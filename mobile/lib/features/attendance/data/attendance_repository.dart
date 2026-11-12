import 'package:dio/dio.dart';
import 'package:geolocator/geolocator.dart';
import 'package:uuid/uuid.dart';
import 'attendance_api.dart';
import '../models/break_models.dart';
import '../models/attendance_event_model.dart';
import '../models/missed_attendance_model.dart';
import '../../../core/utils/device_info.dart';
import '../../../core/services/offline_queue_service.dart';
import '../../../core/services/geofence_cache_service.dart';
import '../../../core/services/today_status_cache_service.dart';

class AttendanceRepository {
  final AttendanceApi _api = AttendanceApi();
  final DeviceInfoUtil _deviceInfo = DeviceInfoUtil();
  final OfflineQueueService _offlineQueue = OfflineQueueService();
  final GeofenceCacheService _geofenceCache = GeofenceCacheService();
  final TodayStatusCacheService _todayCache = TodayStatusCacheService();

  /// True when [error] represents a connectivity failure (no network, DNS
  /// failure, timeout, unreachable host) rather than a real business-logic
  /// rejection (validation error, geofence rejection, etc.) that should
  /// surface to the user as-is instead of being queued offline.
  bool _isConnectivityFailure(Object error) {
    if (error is! DioException) return false;
    return error.type == DioExceptionType.connectionError ||
        error.type == DioExceptionType.connectionTimeout ||
        error.type == DioExceptionType.sendTimeout ||
        error.type == DioExceptionType.receiveTimeout ||
        (error.type == DioExceptionType.unknown && error.response == null);
  }

  Future<Map<String, dynamic>> getTodayContext() async {
    try {
      final response = await _api.getTodayContext();
      final data = response.data['data'] as Map<String, dynamic>;

      // Piggyback the geofence cache refresh on this call — it already runs
      // on app launch, pull-to-refresh, and after every successful
      // check-in/out, so offline check-in/out always has a recent geofence
      // snapshot to validate against, at zero extra network cost.
      final policy = data['policy'] as Map<String, dynamic>?;
      if (policy != null) {
        await _geofenceCache.saveGeofenceConfig(policy);
      }

      await _todayCache.saveContext(data);
      return data;
    } catch (e) {
      if (!_isConnectivityFailure(e)) rethrow;
      final cached = await _todayCache.getCachedContext();
      if (cached != null) return cached;
      rethrow;
    }
  }

  Future<Map<String, dynamic>> getTodayStatus() async {
    try {
      final response = await _api.getTodayStatus();
      final data = response.data['data'] as Map<String, dynamic>;
      await _todayCache.saveStatus(data);
      return data;
    } catch (e) {
      if (!_isConnectivityFailure(e)) rethrow;
      final cached = await _todayCache.getCachedStatus();
      if (cached != null) return cached;
      rethrow;
    }
  }

  /// Persists an optimistic today-status update (built by the caller after a
  /// successful offline-queued check-in/out) so it survives an app restart —
  /// not just the in-memory state of the current session.
  Future<void> cacheTodayStatus(Map<String, dynamic> status) => _todayCache.saveStatus(status);

  /// Mirror of [cacheTodayStatus] for break state — persists an optimistic
  /// break start/end update built by the caller so it survives an app
  /// restart while still offline.
  Future<void> cacheBreakState(Map<String, dynamic> breakState) => _todayCache.saveBreakState(breakState);

  Future<List<MissedAttendanceModel>> getMissedAttendance() async {
    final response = await _api.getMissedAttendance();
    final rawList = response.data['data'] as List<dynamic>? ?? [];
    return rawList.map((e) => MissedAttendanceModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<Map<String, dynamic>> checkIn(Position position) async {
    final devInfo = await _deviceInfo.getDeviceInfo();
    final deviceId = devInfo['device_id'] ?? 'unknown';
    final appVer = devInfo['app_version'] ?? '1.0.0';
    final osVer = devInfo['os_version'] ?? 'unknown';
    final key = const Uuid().v4();
    final deviceTime = DateTime.now();

    try {
      final response = await _api.checkIn(
        latitude: position.latitude,
        longitude: position.longitude,
        accuracy: position.accuracy,
        altitude: position.altitude,
        deviceTime: deviceTime.toIso8601String(),
        locationTimestamp: deviceTime.toUtc().toIso8601String(),
        deviceId: deviceId,
        appVersion: appVer,
        osVersion: osVer,
        isMocked: position.isMocked,
        idempotencyKey: key,
      );

      return response.data['data'] as Map<String, dynamic>;
    } catch (e) {
      if (!_isConnectivityFailure(e)) rethrow;

      await _ensureNotAlreadyCheckedIn();
      await _validateGeofenceOrThrow(position, isCheckOut: false);

      await _offlineQueue.enqueueCheckInOrOut(
        eventType: 'check_in',
        latitude: position.latitude,
        longitude: position.longitude,
        accuracy: position.accuracy,
        deviceId: deviceId,
        idempotencyKey: key,
      );

      return {
        'offline_queued': true,
        'device_time': deviceTime.toIso8601String(),
      };
    }
  }

  /// Blocks queuing a duplicate offline check-in when the local cache
  /// already shows an active (checked-in, not checked-out) session for
  /// today — there's no live server round-trip available offline to catch
  /// this the way the online ALREADY_CHECKED_IN rejection does.
  Future<void> _ensureNotAlreadyCheckedIn() async {
    final cached = await _todayCache.getCachedStatus();
    final record = cached?['record'] as Map<String, dynamic>?;
    final hasActiveSession = record != null &&
        record['check_in_time'] != null &&
        record['check_out_time'] == null;
    if (hasActiveSession) {
      throw Exception('You have already checked in today.');
    }
  }

  /// Mirror of [_ensureNotAlreadyCheckedIn] for check-out: blocks queuing an
  /// offline check-out when the local cache shows no active session to close.
  Future<void> _ensureCheckedIn() async {
    final cached = await _todayCache.getCachedStatus();
    final record = cached?['record'] as Map<String, dynamic>?;
    final hasActiveSession = record != null &&
        record['check_in_time'] != null &&
        record['check_out_time'] == null;
    if (!hasActiveSession) {
      throw Exception('You must check in first before performing this action.');
    }
  }

  /// Blocks queuing an offline check-out while the cached break state shows
  /// an active break — mirrors the live checkOut() endpoint's BREAK_ACTIVE
  /// rejection (and OfflineController::syncOfflineRecords()'s server-side
  /// enforcement of the same rule for synced offline checkouts), giving the
  /// employee immediate feedback offline instead of a silent rejection days
  /// later once the queue syncs.
  Future<void> _ensureNoActiveBreak() async {
    final cachedBreak = await _todayCache.getCachedBreakState();
    final hasActiveBreak = cachedBreak != null && cachedBreak['active_break'] != null;
    if (hasActiveBreak) {
      throw Exception('Cannot check out while a break is active. Please end your break first.');
    }
  }

  /// Validates a captured GPS fix against the cached geofence config before
  /// an offline check-in/out is allowed to be queued. Throws (never queues)
  /// if there's no cached config yet, or if the position is outside every
  /// assigned location's radius — matching the server's own hard-rejection
  /// of out-of-geofence attendance, applied locally so the employee gets
  /// immediate feedback instead of a false "saved offline" success message.
  Future<void> _validateGeofenceOrThrow(Position position, {required bool isCheckOut}) async {
    final cached = await _geofenceCache.getCachedGeofenceConfig();
    if (cached == null) {
      throw Exception(
        'You need to open the app once while online today before you can check in/out offline.',
      );
    }

    final result = _geofenceCache.checkGeofence(
      lat: position.latitude,
      lng: position.longitude,
      policy: cached.policy,
      isCheckOut: isCheckOut,
    );

    if (!result.inGeofence) {
      throw Exception(
        'You are outside your permitted work location (${result.distanceMeters.round()}m away, '
        '${result.allowedRadiusMeters}m allowed). Attendance was not recorded.',
      );
    }
  }

  Future<Map<String, dynamic>> checkOut(Position position) async {
    final devInfo = await _deviceInfo.getDeviceInfo();
    final deviceId = devInfo['device_id'] ?? 'unknown';
    final appVer = devInfo['app_version'] ?? '1.0.0';
    final osVer = devInfo['os_version'] ?? 'unknown';
    final key = const Uuid().v4();
    final deviceTime = DateTime.now();

    try {
      final response = await _api.checkOut(
        latitude: position.latitude,
        longitude: position.longitude,
        accuracy: position.accuracy,
        altitude: position.altitude,
        deviceTime: deviceTime.toIso8601String(),
        locationTimestamp: deviceTime.toUtc().toIso8601String(),
        deviceId: deviceId,
        appVersion: appVer,
        osVersion: osVer,
        isMocked: position.isMocked,
        idempotencyKey: key,
      );

      return response.data['data'] as Map<String, dynamic>;
    } catch (e) {
      if (!_isConnectivityFailure(e)) rethrow;

      await _ensureCheckedIn();
      await _ensureNoActiveBreak();
      await _validateGeofenceOrThrow(position, isCheckOut: true);

      await _offlineQueue.enqueueCheckInOrOut(
        eventType: 'check_out',
        latitude: position.latitude,
        longitude: position.longitude,
        accuracy: position.accuracy,
        deviceId: deviceId,
        idempotencyKey: key,
      );

      return {
        'offline_queued': true,
        'device_time': deviceTime.toIso8601String(),
      };
    }
  }

  Future<List<dynamic>> getHistory([String? month]) async {
    final response = await _api.getHistory(month);
    return response.data['data'] as List<dynamic>;
  }

  Future<List<dynamic>> getHolidays([String? month]) async {
    final response = await _api.getHolidays(month);
    return response.data['data'] as List<dynamic>;
  }

  // Break Repository Methods
  Future<BreakStateModel> getBreakState() async {
    try {
      final response = await _api.getBreakState();
      final data = response.data['data'] as Map<String, dynamic>;
      await _todayCache.saveBreakState(data);
      return BreakStateModel.fromJson(data);
    } catch (e) {
      if (!_isConnectivityFailure(e)) rethrow;
      final cached = await _todayCache.getCachedBreakState();
      if (cached != null) return BreakStateModel.fromJson(cached);
      rethrow;
    }
  }

  Future<Map<String, dynamic>> startBreak({String? reason}) async {
    final key = const Uuid().v4();
    final deviceTime = DateTime.now();
    try {
      final response = await _api.startBreak(reason: reason, idempotencyKey: key);
      return response.data['data'] as Map<String, dynamic>;
    } catch (e) {
      if (!_isConnectivityFailure(e)) rethrow;
      await _offlineQueue.enqueueBreak(eventType: 'start_break', idempotencyKey: key, reason: reason);
      return {'offline_queued': true, 'device_time': deviceTime.toIso8601String()};
    }
  }

  Future<Map<String, dynamic>> endBreak(Position position) async {
    final key = const Uuid().v4();
    final deviceTime = DateTime.now();
    try {
      final response = await _api.endBreak(
        latitude: position.latitude,
        longitude: position.longitude,
        accuracy: position.accuracy,
        locationTimestamp: deviceTime.toUtc().toIso8601String(),
        idempotencyKey: key,
      );
      return response.data['data'] as Map<String, dynamic>;
    } catch (e) {
      if (!_isConnectivityFailure(e)) rethrow;

      // Same geofence rule as check-out: ending a break requires being
      // inside an assigned location, validated locally so an offline
      // attempt gets immediate feedback instead of a silent queue-then-fail.
      await _validateGeofenceOrThrow(position, isCheckOut: true);

      await _offlineQueue.enqueueBreak(
        eventType: 'end_break',
        idempotencyKey: key,
        latitude: position.latitude,
        longitude: position.longitude,
        accuracy: position.accuracy,
      );
      return {'offline_queued': true, 'device_time': deviceTime.toIso8601String()};
    }
  }

  Future<List<dynamic>> getBreakPolicies() async {
    final response = await _api.getBreakPolicies();
    return response.data['data'] as List<dynamic>;
  }

  Future<Map<String, dynamic>> createBreakPolicy(Map<String, dynamic> data) async {
    final response = await _api.createBreakPolicy(data);
    return response.data['data'] as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> assignBreakPolicy(String policyUuid, Map<String, dynamic> data) async {
    final response = await _api.assignBreakPolicy(policyUuid, data);
    return response.data['data'] as Map<String, dynamic>;
  }

  Future<List<dynamic>> getActiveOverdueBreaks() async {
    final response = await _api.getActiveOverdueBreaks();
    return response.data['data'] as List<dynamic>;
  }

  Future<Map<String, dynamic>> managerCorrectBreak(String breakUuid, String action, String reason) async {
    final response = await _api.managerCorrectBreak(breakUuid, action, reason);
    return response.data['data'] as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> getAttendanceEvents({int page = 1, int perPage = 20, String? filter}) async {
    final response = await _api.getAttendanceEvents(page: page, perPage: perPage, filter: filter);
    final data = response.data['data'] as Map<String, dynamic>;
    final rawItems = data['items'] as List<dynamic>? ?? [];
    final items = rawItems.map((e) => AttendanceEventModel.fromJson(e as Map<String, dynamic>)).toList();

    return {
      'items': items,
      'current_page': data['current_page'] ?? page,
      'last_page': data['last_page'] ?? 1,
      'total': data['total'] ?? items.length,
    };
  }
}
