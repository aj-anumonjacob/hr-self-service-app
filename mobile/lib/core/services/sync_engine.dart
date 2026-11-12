import 'dart:async';
import 'package:flutter/widgets.dart';
import '../utils/connectivity.dart';
import 'offline_queue_service.dart';

/// Single source of truth for *when* the offline queue gets synced.
///
/// Replaces the ad-hoc connectivity/lifecycle listeners that used to live
/// directly inside `AttendanceNotifier` — everything that needs to know
/// "a sync round just ran and N items went through" listens to
/// [onSyncRoundCompleted] instead of wiring its own trigger.
///
/// Triggers, in order of how often they fire in practice:
///   1. Connectivity restored (shared [ConnectivityService] stream).
///   2. App foreground/resume.
///   3. A 5-minute periodic safety net, for the rare case neither of the
///      above fires (e.g. a connectivity blip the OS doesn't report as an
///      event, or the app is resumed but immediately backgrounded again).
class SyncEngine with WidgetsBindingObserver {
  static final SyncEngine _instance = SyncEngine._internal();
  factory SyncEngine() => _instance;
  SyncEngine._internal();

  final OfflineQueueService _queue = OfflineQueueService();
  final _roundCompletedController = StreamController<int>.broadcast();
  final _connectivityRestoredController = StreamController<void>.broadcast();
  StreamSubscription<bool>? _connectivitySub;
  Timer? _periodicTimer;
  bool _initialized = false;

  /// Emits the number of items synced whenever a sync round completes with
  /// at least one item successfully synced.
  Stream<int> get onSyncRoundCompleted => _roundCompletedController.stream;

  /// Emits every time connectivity is restored, regardless of whether there
  /// was anything queued to sync — used by AuthNotifier to opportunistically
  /// redeem an offline-recovery certificate for real tokens.
  Stream<void> get onConnectivityRestored => _connectivityRestoredController.stream;

  void init() {
    if (_initialized) return;
    _initialized = true;

    WidgetsBinding.instance.addObserver(this);

    _connectivitySub = ConnectivityService().onConnectivityChanged.listen((isOnline) {
      if (isOnline) {
        _connectivityRestoredController.add(null);
        syncNow();
      }
    });

    _periodicTimer = Timer.periodic(const Duration(minutes: 5), (_) => syncNow());
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      syncNow();
    }
  }

  Future<void> syncNow({bool force = false}) async {
    final syncedCount = await _queue.trySync();
    if (syncedCount > 0) {
      _roundCompletedController.add(syncedCount);
    }
  }

  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _connectivitySub?.cancel();
    _periodicTimer?.cancel();
  }
}
