import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:sqflite/sqflite.dart';

/// Single local SQLite database for offline-first attendance: the action
/// queue (check-in/out/breaks captured while offline) and a cached snapshot
/// of the employee's geofence configuration so it's available for validation
/// even without connectivity.
class AppDatabase {
  static const _dbName = 'attendance_offline.db';
  static const _dbVersion = 3;

  static final AppDatabase _instance = AppDatabase._internal();
  factory AppDatabase() => _instance;
  AppDatabase._internal();

  Database? _database;

  Future<Database> get database async {
    _database ??= await _open();
    return _database!;
  }

  Future<Database> _open() async {
    final docsDir = await getApplicationDocumentsDirectory();
    final path = p.join(docsDir.path, _dbName);

    return openDatabase(
      path,
      version: _dbVersion,
      onCreate: (db, version) async {
        await db.execute('''
          CREATE TABLE offline_action_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT UNIQUE NOT NULL,
            action_type TEXT NOT NULL,
            payload TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            attempt_count INTEGER NOT NULL DEFAULT 0,
            last_attempted_at TEXT,
            last_error TEXT,
            created_at TEXT NOT NULL,
            synced_at TEXT,
            server_ref TEXT
          )
        ''');
        await db.execute('CREATE INDEX idx_offline_queue_status ON offline_action_queue(status)');

        await db.execute('''
          CREATE TABLE cached_geofence_config (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            policy_json TEXT NOT NULL,
            cached_at TEXT NOT NULL
          )
        ''');

        await db.execute('''
          CREATE TABLE cached_today_status (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            date TEXT NOT NULL,
            context_json TEXT,
            status_json TEXT,
            break_json TEXT,
            cached_at TEXT NOT NULL
          )
        ''');
      },
      onUpgrade: (db, oldVersion, newVersion) async {
        if (oldVersion < 2) {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_today_status (
              id INTEGER PRIMARY KEY CHECK (id = 1),
              date TEXT NOT NULL,
              context_json TEXT,
              status_json TEXT,
              cached_at TEXT NOT NULL
            )
          ''');
        }
        if (oldVersion < 3) {
          await db.execute('ALTER TABLE cached_today_status ADD COLUMN break_json TEXT');
        }
      },
    );
  }
}
