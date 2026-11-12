import 'package:dio/dio.dart';
import '../../../core/network/api_client.dart';

class AttendanceApi {
  Future<Response> getTodayContext() async {
    return await apiClient.dio.get('/me/today-context');
  }

  Future<Response> getTodayStatus() async {
    return await apiClient.dio.get('/me/attendance/today');
  }

  Future<Response> getMissedAttendance() async {
    return await apiClient.dio.get('/me/attendance/missed');
  }

  Future<Response> getOfflineAuthorization(String platformDeviceId) async {
    return await apiClient.dio.post('/me/offline-authorization', data: {
      'platform_device_id': platformDeviceId,
    });
  }

  Future<Response> syncOfflineRecords(List<Map<String, dynamic>> records) async {
    return await apiClient.dio.post('/me/offline-attendance/sync', data: {
      'records': records,
    });
  }

  Future<Response> checkIn({
    required double latitude,
    required double longitude,
    required double accuracy,
    required double? altitude,
    required String deviceTime,
    required String locationTimestamp,
    required String deviceId,
    required String appVersion,
    required String osVersion,
    required bool isMocked,
    required String idempotencyKey,
  }) async {
    return await apiClient.dio.post('/me/attendance/check-in', data: {
      'latitude': latitude,
      'longitude': longitude,
      'accuracy': accuracy,
      'altitude': altitude,
      'device_time': deviceTime,
      'location_timestamp': locationTimestamp,
      'device_id': deviceId,
      'app_version': appVersion,
      'os_version': osVersion,
      'mock_location': isMocked,
      'idempotency_key': idempotencyKey,
    });
  }

  Future<Response> checkOut({
    required double latitude,
    required double longitude,
    required double accuracy,
    required double? altitude,
    required String deviceTime,
    required String locationTimestamp,
    required String deviceId,
    required String appVersion,
    required String osVersion,
    required bool isMocked,
    required String idempotencyKey,
  }) async {
    return await apiClient.dio.post('/me/attendance/check-out', data: {
      'latitude': latitude,
      'longitude': longitude,
      'accuracy': accuracy,
      'altitude': altitude,
      'device_time': deviceTime,
      'location_timestamp': locationTimestamp,
      'device_id': deviceId,
      'app_version': appVersion,
      'os_version': osVersion,
      'mock_location': isMocked,
      'idempotency_key': idempotencyKey,
    });
  }

  Future<Response> getHistory([String? month]) async {
    return await apiClient.dio.get('/me/attendance', queryParameters: {
      if (month != null && month.isNotEmpty) 'month': month,
    });
  }

  Future<Response> getHolidays([String? month]) async {
    return await apiClient.dio.get('/holidays', queryParameters: {
      if (month != null && month.isNotEmpty) 'month': month,
    });
  }

  // Break APIs
  Future<Response> getBreakState() async {
    return await apiClient.dio.get('/me/break/state');
  }

  Future<Response> startBreak({String? reason, String? idempotencyKey}) async {
    final Map<String, dynamic> body = {
      'device_time': DateTime.now().toIso8601String(),
    };
    if (reason != null) body['reason'] = reason;
    if (idempotencyKey != null) body['idempotency_key'] = idempotencyKey;

    return await apiClient.dio.post('/me/attendance/start-break', data: body);
  }

  Future<Response> endBreak({
    required double latitude,
    required double longitude,
    required double accuracy,
    required String locationTimestamp,
    String? idempotencyKey,
  }) async {
    final Map<String, dynamic> body = {
      'device_time': DateTime.now().toIso8601String(),
      'latitude': latitude,
      'longitude': longitude,
      'accuracy': accuracy,
      'location_timestamp': locationTimestamp,
    };
    if (idempotencyKey != null) body['idempotency_key'] = idempotencyKey;

    return await apiClient.dio.post('/me/attendance/end-break', data: body);
  }

  // Manager & Admin Break Management APIs
  Future<Response> getBreakPolicies() async {
    return await apiClient.dio.get('/manager/break-policies');
  }

  Future<Response> createBreakPolicy(Map<String, dynamic> data) async {
    return await apiClient.dio.post('/manager/break-policies', data: data);
  }

  Future<Response> assignBreakPolicy(String policyUuid, Map<String, dynamic> data) async {
    return await apiClient.dio.post('/manager/break-policies/$policyUuid/assign', data: data);
  }

  Future<Response> getActiveOverdueBreaks() async {
    return await apiClient.dio.get('/manager/breaks/overdue');
  }

  Future<Response> managerCorrectBreak(String breakUuid, String action, String reason) async {
    return await apiClient.dio.post('/manager/breaks/$breakUuid/correct', data: {
      'action': action,
      'reason': reason,
    });
  }

  // Recents Activity Events API
  Future<Response> getAttendanceEvents({int page = 1, int perPage = 20, String? filter}) async {
    return await apiClient.dio.get('/me/attendance-events', queryParameters: {
      'page': page,
      'per_page': perPage,
      if (filter != null && filter.isNotEmpty && filter != 'all') 'filter': filter,
    });
  }
}
