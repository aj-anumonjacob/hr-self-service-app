import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:sqflite/sqflite.dart';
import '../../features/attendance/data/attendance_api.dart';
import '../utils/device_info.dart';
import '../database/app_database.dart';

/// Queues attendance actions (check-in, check-out, start-break, end-break)
/// taken while the device has no internet connection, and syncs them to the
/// server once connectivity returns.
///
/// Backed by the sqflite `offline_action_queue` table (see [AppDatabase]) so
/// each item carries a durable per-row status — pending, syncing, synced,
/// failed (transient — retried automatically), or rejected (permanent, e.g.
/// a hard geofence violation — never retried).
///
/// Check-in/check-out require a signed offline-authorization token (proof the
/// employee was online and near their assigned location recently) — fetched
/// and cached while online. Breaks don't carry a location claim, so they can
/// always be queued without one.
class OfflineQueueService {
  static const _authSignatureKey = 'offline_auth_signature';
  static const _authExpiryKey = 'offline_auth_expiry';

  static final OfflineQueueService _instance = OfflineQueueService._internal();
  factory OfflineQueueService() => _instance;
  OfflineQueueService._internal();

  final AttendanceApi _api = AttendanceApi();
  final DeviceInfoUtil _deviceInfo = DeviceInfoUtil();
  bool _isSyncing = false;

  Future<bool> isOnline() async {
    final results = await Connectivity().checkConnectivity();
    return !results.contains(ConnectivityResult.none);
  }

  /// Call while online (app start, dashboard load, after any successful
  /// online action) so a signed token is ready if connectivity drops later.
  Future<void> refreshAuthorizationToken() async {
    try {
      final devInfo = await _deviceInfo.getDeviceInfo();
      final deviceId = devInfo['device_id'] ?? 'unknown';
      final response = await _api.getOfflineAuthorization(deviceId);
      final data = response.data['data'] as Map<String, dynamic>?;
      final signature = data?['signature'] as String?;
      final validTo = data?['valid_to'] as String?;
      if (signature == null) return;

      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_authSignatureKey, signature);
      if (validTo != null) {
        await prefs.setString(_authExpiryKey, validTo);
      }
    } catch (_) {
      // Best-effort — if this fails, offline check-in/out simply won't be
      // available until the next successful refresh; breaks are unaffected.
    }
  }

  Future<String?> _getValidSignature() async {
    final prefs = await SharedPreferences.getInstance();
    final signature = prefs.getString(_authSignatureKey);
    final expiryStr = prefs.getString(_authExpiryKey);
    if (signature == null || expiryStr == null) return null;

    final expiry = DateTime.tryParse(expiryStr);
    if (expiry == null || DateTime.now().isAfter(expiry)) return null;

    return signature;
  }

  /// Returns the offline-authorization signature if one is cached and valid —
  /// used by callers to decide whether an offline check-in/out is even
  /// possible before attempting to queue one.
  Future<bool> canQueueLocationEvent() async => (await _getValidSignature()) != null;

  /// Number of queue items still awaiting a successful sync (pending,
  /// currently syncing, or transiently failed — everything except terminal
  /// `synced`/`rejected` states). Drives the connectivity badge's count and
  /// the dashboard summary.
  Future<int> pendingCount() async {
    final db = await AppDatabase().database;
    final result = await db.rawQuery(
      "SELECT COUNT(*) as c FROM offline_action_queue WHERE status IN ('pending','syncing','failed')",
    );
    return Sqflite.firstIntValue(result) ?? 0;
  }

  /// All queue items, newest first — for the Sync/Audit History screen.
  Future<List<Map<String, dynamic>>> getAllItems() async {
    final db = await AppDatabase().database;
    return db.query('offline_action_queue', orderBy: 'id DESC');
  }

  Future<void> enqueueCheckInOrOut({
    required String eventType, // check_in | check_out
    required double latitude,
    required double longitude,
    required double accuracy,
    required String deviceId,
    required String idempotencyKey,
  }) async {
    final signature = await _getValidSignature();
    if (signature == null) {
      throw Exception('No offline authorization available. Please connect to the internet at least once today before going offline.');
    }

    final deviceTime = DateTime.now().toIso8601String();
    final payload = {
      'event_type': eventType,
      'device_time': deviceTime,
      'idempotency_key': idempotencyKey,
      'signature': signature,
      'latitude': latitude,
      'longitude': longitude,
      'accuracy': accuracy,
      'device_id': deviceId,
    };

    final db = await AppDatabase().database;
    await db.insert('offline_action_queue', {
      'uuid': idempotencyKey,
      'action_type': eventType,
      'payload': jsonEncode(payload),
      'status': 'pending',
      'attempt_count': 0,
      'created_at': deviceTime,
    });
  }

  Future<void> enqueueBreak({
    required String eventType, // start_break | end_break
    required String idempotencyKey,
    String? reason,
    double? latitude,
    double? longitude,
    double? accuracy,
  }) async {
    final deviceTime = DateTime.now().toIso8601String();
    final payload = {
      'event_type': eventType,
      'device_time': deviceTime,
      'idempotency_key': idempotencyKey,
      if (reason != null) 'reason': reason,
      if (latitude != null) 'latitude': latitude,
      if (longitude != null) 'longitude': longitude,
      if (accuracy != null) 'accuracy': accuracy,
    };

    final db = await AppDatabase().database;
    await db.insert('offline_action_queue', {
      'uuid': idempotencyKey,
      'action_type': eventType,
      'payload': jsonEncode(payload),
      'status': 'pending',
      'attempt_count': 0,
      'created_at': deviceTime,
    });
  }

  /// Re-queues a `failed` item for another sync attempt (used by the manual
  /// "Retry" button in the audit history screen). No-ops for any other status
  /// — `rejected` items are permanent and `synced`/`pending`/`syncing` items
  /// don't need re-queuing.
  Future<void> retryItem(int id) async {
    final db = await AppDatabase().database;
    await db.update(
      'offline_action_queue',
      {'status': 'pending', 'last_error': null},
      where: 'id = ? AND status = ?',
      whereArgs: [id, 'failed'],
    );
    await trySync();
  }

  /// Pushes all queued actions to the server in order, updating each row's
  /// status based on the response. Returns the number of records that were
  /// successfully synced (server-confirmed, including ones that turned out
  /// to already exist from a previous attempt whose response we missed).
  Future<int> trySync() async {
    if (_isSyncing) return 0;
    _isSyncing = true;

    try {
      final db = await AppDatabase().database;
      final rows = await db.query(
        'offline_action_queue',
        where: "status IN ('pending','failed')",
        orderBy: 'id ASC',
      );
      if (rows.isEmpty) return 0;
      if (!await isOnline()) return 0;

      final now = DateTime.now().toIso8601String();
      for (final row in rows) {
        await db.update(
          'offline_action_queue',
          {
            'status': 'syncing',
            'attempt_count': (row['attempt_count'] as int) + 1,
            'last_attempted_at': now,
          },
          where: 'id = ?',
          whereArgs: [row['id']],
        );
      }

      final records = rows.map((r) => jsonDecode(r['payload'] as String) as Map<String, dynamic>).toList();

      try {
        final response = await _api.syncOfflineRecords(records);
        final data = response.data['data'] as Map<String, dynamic>?;

        final alreadySynced = _extractKeys(data?['already_synced']);
        final rejected = _extractRejectionMap(data?['rejected_records']);
        final failed = _extractReasonMap(data?['failed_records']);

        int syncedCount = 0;
        final syncedAt = DateTime.now().toIso8601String();

        for (final row in rows) {
          final key = row['uuid'] as String;
          final id = row['id'];

          if (rejected.containsKey(key)) {
            await db.update(
              'offline_action_queue',
              {'status': 'rejected', 'last_error': rejected[key]},
              where: 'id = ?',
              whereArgs: [id],
            );
          } else if (failed.containsKey(key)) {
            await db.update(
              'offline_action_queue',
              {'status': 'failed', 'last_error': failed[key]},
              where: 'id = ?',
              whereArgs: [id],
            );
          } else {
            // Synced this round, or already synced from a previous attempt
            // whose response we never received — both are a success.
            await db.update(
              'offline_action_queue',
              {'status': 'synced', 'synced_at': syncedAt},
              where: 'id = ?',
              whereArgs: [id],
            );
            syncedCount++;
          }
          if (alreadySynced.contains(key)) syncedCount++;
        }

        if (syncedCount > 0) {
          // A successful sync round-trip means we're online — refresh the
          // authorization token so it's fresh for the next offline stretch.
          await refreshAuthorizationToken();
        }

        return syncedCount;
      } catch (_) {
        // Network-level failure mid-request (timeout, connection drop) — we
        // genuinely don't know if the server received/committed this batch.
        // Revert to 'pending' (not 'failed') rather than guessing: the next
        // retry resubmits the same idempotency keys, and the server's own
        // duplicate check safely no-ops anything that actually went through,
        // surfacing it back to us as 'already_synced'.
        for (final row in rows) {
          await db.update(
            'offline_action_queue',
            {'status': 'pending'},
            where: 'id = ? AND status = ?',
            whereArgs: [row['id'], 'syncing'],
          );
        }
        return 0;
      }
    } finally {
      _isSyncing = false;
    }
  }

  Set<String> _extractKeys(dynamic list) {
    if (list is! List) return {};
    return list
        .map((e) => (e as Map<String, dynamic>)['idempotency_key'] as String?)
        .whereType<String>()
        .toSet();
  }

  Map<String, String> _extractReasonMap(dynamic list) {
    if (list is! List) return {};
    final map = <String, String>{};
    for (final e in list) {
      final entry = e as Map<String, dynamic>;
      final key = entry['idempotency_key'] as String?;
      if (key == null) continue;
      map[key] = (entry['reason'] as String?) ?? 'Sync failed.';
    }
    return map;
  }

  Map<String, String> _extractRejectionMap(dynamic list) {
    if (list is! List) return {};
    final map = <String, String>{};
    for (final e in list) {
      final entry = e as Map<String, dynamic>;
      final key = entry['idempotency_key'] as String?;
      if (key == null) continue;
      final distance = entry['distance_meters'];
      final radius = entry['allowed_radius_meters'];
      map[key] = (distance != null && radius != null)
          ? 'Outside your work location (${(distance as num).round()}m away, ${radius}m allowed).'
          : 'Outside your work location geofence.';
    }
    return map;
  }
}
