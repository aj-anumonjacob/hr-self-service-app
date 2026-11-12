import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';
import 'app_database.dart';

/// One-time migration of the old SharedPreferences-based offline queue
/// (`offline_attendance_queue`, a single JSON blob) into the new sqflite
/// `offline_action_queue` table, so employees who had check-in/out/break
/// actions queued before this update don't lose them.
///
/// Safe to call on every app start — it no-ops after the first successful run.
Future<void> migrateLegacySharedPreferencesQueue() async {
  const migratedFlagKey = 'offline_queue_migrated_v2';
  const legacyQueueKey = 'offline_attendance_queue';
  const legacyQueueMigratedKey = 'offline_attendance_queue_migrated_v1';

  try {
    final prefs = await SharedPreferences.getInstance();
    if (prefs.getBool(migratedFlagKey) == true) return;

    final raw = prefs.getString(legacyQueueKey);
    if (raw != null && raw.isNotEmpty) {
      final items = (jsonDecode(raw) as List<dynamic>).cast<Map<String, dynamic>>();
      final db = await AppDatabase().database;
      const uuidGen = Uuid();

      for (final item in items) {
        final eventType = item['event_type'] as String? ?? 'check_in';
        final idempotencyKey = item['idempotency_key'] as String? ?? uuidGen.v4();
        final createdAt = (item['device_time'] as String?) ?? DateTime.now().toIso8601String();

        await db.insert(
          'offline_action_queue',
          {
            'uuid': idempotencyKey,
            'action_type': eventType,
            'payload': jsonEncode(item),
            'status': 'pending',
            'attempt_count': 0,
            'created_at': createdAt,
          },
          conflictAlgorithm: ConflictAlgorithm.ignore,
        );
      }

      // Preserve the old data under a renamed key (rather than deleting)
      // as a safety net, instead of clearing it outright.
      await prefs.setString(legacyQueueMigratedKey, raw);
      await prefs.remove(legacyQueueKey);
    }

    await prefs.setBool(migratedFlagKey, true);
  } catch (_) {
    // Leave the old queue untouched — OfflineQueueService's new sqflite path
    // simply starts empty, and the old data stays recoverable for a manual
    // look if this ever needs investigating.
  }
}
