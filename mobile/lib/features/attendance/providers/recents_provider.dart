import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../data/attendance_repository.dart';
import '../models/attendance_event_model.dart';
import 'attendance_provider.dart';

class RecentsState {
  final List<AttendanceEventModel> events;
  final bool isLoading;
  final bool isRefreshing;
  final bool isLoadingMore;
  final String? error;
  final String filter; // 'all', 'attendance', 'breaks'
  final int currentPage;
  final int lastPage;

  RecentsState({
    this.events = const [],
    this.isLoading = false,
    this.isRefreshing = false,
    this.isLoadingMore = false,
    this.error,
    this.filter = 'all',
    this.currentPage = 1,
    this.lastPage = 1,
  });

  bool get hasMore => currentPage < lastPage;

  RecentsState copyWith({
    List<AttendanceEventModel>? events,
    bool? isLoading,
    bool? isRefreshing,
    bool? isLoadingMore,
    String? error,
    String? filter,
    int? currentPage,
    int? lastPage,
  }) {
    return RecentsState(
      events: events ?? this.events,
      isLoading: isLoading ?? this.isLoading,
      isRefreshing: isRefreshing ?? this.isRefreshing,
      isLoadingMore: isLoadingMore ?? this.isLoadingMore,
      error: error,
      filter: filter ?? this.filter,
      currentPage: currentPage ?? this.currentPage,
      lastPage: lastPage ?? this.lastPage,
    );
  }
}

class RecentsNotifier extends StateNotifier<RecentsState> {
  final AttendanceRepository _repository;

  RecentsNotifier(this._repository) : super(RecentsState()) {
    fetchEvents();
  }

  Future<void> fetchEvents({bool isRefresh = false}) async {
    if (isRefresh) {
      state = state.copyWith(isRefreshing: true, error: null);
    } else {
      state = state.copyWith(isLoading: true, error: null);
    }

    try {
      final res = await _repository.getAttendanceEvents(
        page: 1,
        perPage: 20,
        filter: state.filter,
      );

      final items = res['items'] as List<AttendanceEventModel>;
      final currentPage = res['current_page'] as int;
      final lastPage = res['last_page'] as int;

      state = state.copyWith(
        events: items,
        isLoading: false,
        isRefreshing: false,
        currentPage: currentPage,
        lastPage: lastPage,
      );
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        isRefreshing: false,
        error: e.toString().replaceAll('Exception:', '').trim(),
      );
    }
  }

  Future<void> setFilter(String newFilter) async {
    if (state.filter == newFilter) return;
    state = state.copyWith(filter: newFilter, events: [], currentPage: 1, lastPage: 1);
    await fetchEvents();
  }

  Future<void> loadNextPage() async {
    if (state.isLoadingMore || !state.hasMore || state.isLoading) return;

    state = state.copyWith(isLoadingMore: true);
    final nextPage = state.currentPage + 1;

    try {
      final res = await _repository.getAttendanceEvents(
        page: nextPage,
        perPage: 20,
        filter: state.filter,
      );

      final newItems = res['items'] as List<AttendanceEventModel>;
      final currentPage = res['current_page'] as int;
      final lastPage = res['last_page'] as int;

      // Deduplicate items by ID
      final existingIds = state.events.map((e) => e.id).toSet();
      final filteredNew = newItems.where((e) => !existingIds.contains(e.id)).toList();

      state = state.copyWith(
        events: [...state.events, ...filteredNew],
        isLoadingMore: false,
        currentPage: currentPage,
        lastPage: lastPage,
      );
    } catch (_) {
      state = state.copyWith(isLoadingMore: false);
    }
  }
}

final recentsProvider = StateNotifierProvider<RecentsNotifier, RecentsState>((ref) {
  final repo = ref.watch(attendanceRepositoryProvider);
  return RecentsNotifier(repo);
});
