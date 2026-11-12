import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../utils/connectivity.dart';
import '../services/offline_queue_service.dart';

/// Live online/offline status, backed by the shared [ConnectivityService].
/// Seeds with the current status immediately so the badge never shows a
/// blank/loading state before the first change event arrives.
final connectivityStatusProvider = StreamProvider<bool>((ref) async* {
  yield await ConnectivityService().isConnected;
  yield* ConnectivityService().onConnectivityChanged;
});

/// Number of offline-queued attendance actions still awaiting a successful
/// sync (pending, syncing, or transiently failed) — drives the small count
/// badge on the connectivity indicator and the dashboard summary.
final pendingSyncCountProvider = FutureProvider.autoDispose<int>((ref) async {
  return OfflineQueueService().pendingCount();
});
