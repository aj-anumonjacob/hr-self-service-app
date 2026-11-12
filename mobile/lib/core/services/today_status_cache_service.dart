import 'dart:convert';
import 'package:sqflite/sqflite.dart';
import '../database/app_database.dart';

/// Caches today's attendance context (shift/policy), status (check-in/out
/// record), and break state (policy + active break + counts) locally so the
/// dashboard shows accurate state even when `/me/today-context`,
/// `/me/today-status`, and `/me/break-state` can't be reached — e.g. right
/// after an offline certificate recovery (see AuthRepository.
/// getOfflineRecoverableSession()), where there's no live token yet to call
/// the API with at all.
///
/// Scoped to the current calendar day: a cached row from a previous day is
/// never returned, since "today's" check-in/out/break state legitimately
/// resets daily — showing yesterday's as still active would be as wrong as
/// showing nothing. Each field is cached and read independently so a fresh
/// write to one (e.g. break state right after starting a break) never
/// clobbers the others.
class TodayStatusCacheService {
  static final TodayStatusCacheService _instance = TodayStatusCacheService._internal();
  factory TodayStatusCacheService() => _instance;
  TodayStatusCacheService._internal();

  String _today() => DateTime.now().toIso8601String().split('T').first;

  Future<Map<String, dynamic>?> _readRow() async {
    try {
      final db = await AppDatabase().database;
      final rows = await db.query('cached_today_status', where: 'id = 1', limit: 1);
      if (rows.isEmpty) return null;
      return rows.first;
    } catch (_) {
      return null;
    }
  }

  Future<void> _save(String column, Map<String, dynamic> data) async {
    try {
      final db = await AppDatabase().database;
      final existing = await _readRow();
      final sameDay = existing != null && existing['date'] == _today();
      final row = <String, Object?>{
        'id': 1,
        'date': _today(),
        'context_json': sameDay ? existing['context_json'] as String? : null,
        'status_json': sameDay ? existing['status_json'] as String? : null,
        'break_json': sameDay ? existing['break_json'] as String? : null,
        'cached_at': DateTime.now().toIso8601String(),
      };
      row[column] = jsonEncode(data);
      await db.insert('cached_today_status', row, conflictAlgorithm: ConflictAlgorithm.replace);
    } catch (_) {
      // Best-effort cache — a failure here just means the offline fallback
      // won't have this field available if connectivity drops later.
    }
  }

  Future<void> saveContext(Map<String, dynamic> context) => _save('context_json', context);
  Future<void> saveStatus(Map<String, dynamic> status) => _save('status_json', status);
  Future<void> saveBreakState(Map<String, dynamic> breakState) => _save('break_json', breakState);

  Future<Map<String, dynamic>?> _readColumn(String column) async {
    final row = await _readRow();
    if (row == null || row['date'] != _today()) return null;
    final raw = row[column] as String?;
    if (raw == null) return null;
    return jsonDecode(raw) as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>?> getCachedContext() => _readColumn('context_json');
  Future<Map<String, dynamic>?> getCachedStatus() => _readColumn('status_json');
  Future<Map<String, dynamic>?> getCachedBreakState() => _readColumn('break_json');
}
