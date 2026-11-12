import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/services/offline_queue_service.dart';
import '../../../core/services/sync_engine.dart';

class SyncHistoryNotifier extends StateNotifier<AsyncValue<List<Map<String, dynamic>>>> {
  final OfflineQueueService _queue = OfflineQueueService();

  SyncHistoryNotifier() : super(const AsyncValue.loading()) {
    refresh();
  }

  Future<void> refresh() async {
    try {
      final items = await _queue.getAllItems();
      state = AsyncValue.data(items);
    } catch (e, st) {
      state = AsyncValue.error(e, st);
    }
  }

  Future<void> retry(int id) async {
    await _queue.retryItem(id);
    await refresh();
  }

  Future<void> syncNow() async {
    await SyncEngine().syncNow(force: true);
    await refresh();
  }
}

final syncHistoryProvider =
    StateNotifierProvider.autoDispose<SyncHistoryNotifier, AsyncValue<List<Map<String, dynamic>>>>(
  (ref) => SyncHistoryNotifier(),
);
