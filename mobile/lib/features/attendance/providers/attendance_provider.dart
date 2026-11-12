import 'dart:async';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:dio/dio.dart';
import 'package:geolocator/geolocator.dart';
import '../data/attendance_repository.dart';
import '../models/break_models.dart';
import '../../../core/services/location_service.dart';
import '../../../core/services/offline_queue_service.dart';
import '../../../core/services/sync_engine.dart';

class AttendanceState {
  final bool isLoading;
  final bool isBreakLoading;
  final String? error;
  final String? successMessage;
  final Map<String, dynamic>? todayContext;
  final Map<String, dynamic>? todayStatus;
  final List<dynamic>? history;
  final List<dynamic>? monthlyHistory;
  final List<dynamic>? holidays;
  final BreakStateModel? breakState;
  final int pendingSyncCount;

  AttendanceState({
    this.isLoading = false,
    this.isBreakLoading = false,
    this.error,
    this.successMessage,
    this.todayContext,
    this.todayStatus,
    this.history,
    this.monthlyHistory,
    this.holidays,
    this.breakState,
    this.pendingSyncCount = 0,
  });

  AttendanceState copyWith({
    bool? isLoading,
    bool? isBreakLoading,
    String? error,
    String? successMessage,
    Map<String, dynamic>? todayContext,
    Map<String, dynamic>? todayStatus,
    List<dynamic>? history,
    List<dynamic>? monthlyHistory,
    List<dynamic>? holidays,
    BreakStateModel? breakState,
    int? pendingSyncCount,
  }) {
    return AttendanceState(
      isLoading: isLoading ?? this.isLoading,
      isBreakLoading: isBreakLoading ?? this.isBreakLoading,
      error: error,
      successMessage: successMessage,
      todayContext: todayContext ?? this.todayContext,
      todayStatus: todayStatus ?? this.todayStatus,
      history: history ?? this.history,
      monthlyHistory: monthlyHistory ?? this.monthlyHistory,
      holidays: holidays ?? this.holidays,
      breakState: breakState ?? this.breakState,
      pendingSyncCount: pendingSyncCount ?? this.pendingSyncCount,
    );
  }
}

class AttendanceNotifier extends StateNotifier<AttendanceState> with WidgetsBindingObserver {
  final AttendanceRepository _repository;
  final OfflineQueueService _offlineQueue = OfflineQueueService();
  StreamSubscription<int>? _syncRoundSub;

  AttendanceNotifier(this._repository) : super(AttendanceState()) {
    WidgetsBinding.instance.addObserver(this);
    _refreshPendingSyncCount();
    // Proactively cache an offline-authorization token so it's ready if
    // connectivity drops later. Actual sync triggering (connectivity
    // restored, app resume, periodic safety net) is owned by SyncEngine —
    // this just reconciles local state whenever a sync round completes.
    _offlineQueue.refreshAuthorizationToken();
    _syncRoundSub = SyncEngine().onSyncRoundCompleted.listen((_) async {
      await _refreshPendingSyncCount();
      await fetchTodayContext();
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _syncRoundSub?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      fetchBreakState();
      SyncEngine().syncNow();
    }
  }

  Future<void> _refreshPendingSyncCount() async {
    final count = await _offlineQueue.pendingCount();
    state = state.copyWith(pendingSyncCount: count);
  }

  /// Pushes any queued offline actions to the server. Safe to call anytime —
  /// no-ops instantly if the queue is empty or the device is still offline.
  /// Kept as a thin delegate to [SyncEngine] for existing call sites
  /// (e.g. manual pull-to-refresh) that already call this method by name.
  Future<void> syncPendingActions() async {
    await SyncEngine().syncNow();
  }

  /// Fetches each piece of "today" independently rather than in one
  /// all-or-nothing chain — `history`/`breakState` have no offline fallback
  /// and will throw with no connectivity, and a failure there used to
  /// discard `context`/`status` even when those two DID succeed (from cache,
  /// via AttendanceRepository's own fallback — see getTodayContext()/
  /// getTodayStatus()). That's what made an offline-recovered dashboard show
  /// "Not Checked in" for an employee who really was checked in.
  Future<void> fetchTodayContext() async {
    state = state.copyWith(isLoading: true);

    Map<String, dynamic>? context;
    Map<String, dynamic>? status;
    List<dynamic>? history;
    BreakStateModel? breakState;
    String? error;

    try {
      context = await _repository.getTodayContext();
    } catch (e) {
      error = _parseError(e);
    }
    try {
      status = await _repository.getTodayStatus();
    } catch (e) {
      error ??= _parseError(e);
    }
    try {
      history = await _repository.getHistory();
    } catch (_) {
      // No offline fallback for history — not critical for the today-status
      // display this method exists to keep accurate, so it's swallowed
      // rather than clobbering a successfully recovered context/status.
    }
    try {
      breakState = await _repository.getBreakState();
    } catch (_) {
      // Same reasoning as history above.
    }

    state = AttendanceState(
      todayContext: context ?? state.todayContext,
      todayStatus: status ?? state.todayStatus,
      history: history ?? state.history,
      monthlyHistory: state.monthlyHistory,
      breakState: breakState ?? state.breakState,
      pendingSyncCount: state.pendingSyncCount,
      error: (context == null && status == null) ? error : null,
    );
  }

  Future<void> fetchBreakState() async {
    try {
      state = state.copyWith(isBreakLoading: true);
      final breakState = await _repository.getBreakState();
      state = state.copyWith(breakState: breakState, isBreakLoading: false);
    } catch (e) {
      state = state.copyWith(error: _parseError(e), isBreakLoading: false);
    }
  }

  Future<void> fetchMonthlyHistory(String month) async {
    try {
      state = state.copyWith(isLoading: true);
      final monthly = await _repository.getHistory(month);
      final holidays = await _repository.getHolidays(month);
      state = state.copyWith(
        monthlyHistory: monthly,
        holidays: holidays,
        isLoading: false,
      );
    } catch (e) {
      state = state.copyWith(error: _parseError(e), isLoading: false);
    }
  }

  Future<bool> checkIn([Position? position]) async {
    try {
      state = state.copyWith(isLoading: true);
      final pos = await LocationService.determinePosition(position);
      final result = await _repository.checkIn(pos);

      if (result['offline_queued'] == true) {
        await _refreshPendingSyncCount();
        final optimistic = _optimisticTodayStatus(checkedIn: true, deviceTime: result['device_time'] as String);
        await _repository.cacheTodayStatus(optimistic);
        state = state.copyWith(
          todayStatus: optimistic,
          isLoading: false,
          successMessage: 'You\'re offline — check-in saved on this device and will sync automatically once you\'re back online.',
        );
        return true;
      }

      final context = await _repository.getTodayContext();
      final status = await _repository.getTodayStatus();
      final history = await _repository.getHistory();
      final breakState = await _repository.getBreakState();

      state = AttendanceState(
        todayContext: context,
        todayStatus: status,
        history: history,
        breakState: breakState,
        pendingSyncCount: state.pendingSyncCount,
        successMessage: 'Checked in successfully.',
      );
      return true;
    } catch (e) {
      state = state.copyWith(error: _parseError(e), isLoading: false);
      return false;
    }
  }

  Future<bool> checkOut([Position? position]) async {
    try {
      state = state.copyWith(isLoading: true);
      final pos = await LocationService.determinePosition(position);
      final result = await _repository.checkOut(pos);

      if (result['offline_queued'] == true) {
        await _refreshPendingSyncCount();
        final optimistic = _optimisticTodayStatus(checkedIn: false, deviceTime: result['device_time'] as String);
        await _repository.cacheTodayStatus(optimistic);
        state = state.copyWith(
          todayStatus: optimistic,
          isLoading: false,
          successMessage: 'You\'re offline — check-out saved on this device and will sync automatically once you\'re back online.',
        );
        return true;
      }

      final context = await _repository.getTodayContext();
      final status = await _repository.getTodayStatus();
      final history = await _repository.getHistory();
      final breakState = await _repository.getBreakState();

      state = AttendanceState(
        todayContext: context,
        todayStatus: status,
        history: history,
        breakState: breakState,
        pendingSyncCount: state.pendingSyncCount,
        successMessage: 'Checked out successfully.',
      );
      return true;
    } catch (e) {
      state = state.copyWith(error: _parseError(e), isLoading: false);
      return false;
    }
  }

  Future<bool> startBreak({String? reason}) async {
    try {
      state = state.copyWith(isBreakLoading: true);
      final result = await _repository.startBreak(reason: reason);

      if (result['offline_queued'] == true) {
        await _refreshPendingSyncCount();
        final optimisticJson = _optimisticBreakStateJson(onBreak: true, deviceTime: result['device_time'] as String);
        await _repository.cacheBreakState(optimisticJson);
        state = state.copyWith(
          breakState: BreakStateModel.fromJson(optimisticJson),
          isBreakLoading: false,
          successMessage: 'You\'re offline — break start saved on this device and will sync automatically once you\'re back online.',
        );
        return true;
      }

      final breakState = await _repository.getBreakState();
      final status = await _repository.getTodayStatus();
      state = state.copyWith(
        breakState: breakState,
        todayStatus: status,
        isBreakLoading: false,
        successMessage: 'Break started successfully.',
      );
      return true;
    } catch (e) {
      state = state.copyWith(error: _parseError(e), isBreakLoading: false);
      return false;
    }
  }

  Future<bool> endBreak([Position? position]) async {
    try {
      state = state.copyWith(isBreakLoading: true);
      final pos = await LocationService.determinePosition(position);
      final result = await _repository.endBreak(pos);

      if (result['offline_queued'] == true) {
        await _refreshPendingSyncCount();
        final optimisticJson = _optimisticBreakStateJson(onBreak: false, deviceTime: result['device_time'] as String);
        await _repository.cacheBreakState(optimisticJson);
        state = state.copyWith(
          breakState: BreakStateModel.fromJson(optimisticJson),
          isBreakLoading: false,
          successMessage: 'You\'re offline — break end saved on this device and will sync automatically once you\'re back online.',
        );
        return true;
      }

      final breakState = await _repository.getBreakState();
      final status = await _repository.getTodayStatus();
      state = state.copyWith(
        breakState: breakState,
        todayStatus: status,
        isBreakLoading: false,
        successMessage: 'Break ended successfully.',
      );
      return true;
    } catch (e) {
      state = state.copyWith(error: _parseError(e), isBreakLoading: false);
      return false;
    }
  }

  /// Best-effort local reconstruction of `today.record` while offline — the
  /// UI reads `todayStatus['record']['check_in_time' | 'check_out_time']` to
  /// decide whether the employee is checked in. Reconciled with the server's
  /// authoritative record as soon as the queue syncs.
  Map<String, dynamic> _optimisticTodayStatus({required bool checkedIn, required String deviceTime}) {
    final previousRecord = (state.todayStatus?['record'] as Map<String, dynamic>?) ?? {};
    return {
      'date': DateTime.now().toIso8601String().split('T').first,
      'record': {
        ...previousRecord,
        'status': 'present',
        'check_in_time': checkedIn ? deviceTime : previousRecord['check_in_time'],
        'check_out_time': checkedIn ? null : deviceTime,
        'has_active_session': checkedIn,
      },
    };
  }

  /// Best-effort local reconstruction of break state while offline, built as
  /// the same raw shape `BreakStateModel.fromJson` expects (rather than a
  /// [BreakStateModel] directly) so it can be cached via
  /// `AttendanceRepository.cacheBreakState()` — see
  /// TodayStatusCacheService.getCachedBreakState() — and survive an app
  /// restart while still offline, not just the current in-memory session.
  ///
  /// Derives the remaining-break count from the real policy's
  /// `maxBreaksPerShift` (already known from the last successful fetch)
  /// rather than carrying over the previous remaining count unchanged —
  /// otherwise starting a break wouldn't visibly use one up until the next
  /// online sync, understating how many breaks are left compared to what the
  /// main system would show.
  Map<String, dynamic> _optimisticBreakStateJson({required bool onBreak, required String deviceTime}) {
    final previous = state.breakState;
    final policy = previous?.effectivePolicy;
    final newUsedCount = onBreak ? (previous?.usedBreakCount ?? 0) + 1 : (previous?.usedBreakCount ?? 0);
    final maxBreaks = policy?.maxBreaksPerShift;
    final remaining = maxBreaks != null
        ? (maxBreaks - newUsedCount).clamp(0, maxBreaks)
        : (previous?.remainingBreaksCount ?? 1);

    return {
      'policy': policy != null
          ? {
              'uuid': policy.uuid,
              'name': policy.name,
              'type': policy.policyType,
              'allowedDurationMinutes': policy.allowedDurationMinutes,
              'maximumBreaksPerShift': policy.maxBreaksPerShift,
              'active': policy.active,
            }
          : null,
      'active_break': onBreak
          ? {
              'uuid': 'offline-pending',
              'start_time_server': deviceTime,
              'status': 'active',
              'duration_seconds': 0,
              'overdue_duration_seconds': 0,
            }
          : null,
      'can_start_break': !onBreak,
      'can_end_break': onBreak,
      'used_break_count': newUsedCount,
      'remaining_breaks_count': remaining,
      'completed_breaks_count': previous?.completedBreaksCount ?? 0,
      'availability_status': onBreak ? 'on_break' : 'available',
    };
  }

  String _parseError(dynamic error) {
    if (error is DioException) {
      final responseData = error.response?.data;
      if (responseData is Map) {
        if (responseData.containsKey('message') && responseData['message'] != null) {
          return responseData['message'] as String;
        }
        if (responseData.containsKey('error') && responseData['error'] is Map) {
          final errorCode = responseData['error']['code'];
          if (errorCode == 'OUTSIDE_GEOFENCE') {
            return 'You are outside the permitted work location.';
          }
          if (errorCode == 'ALREADY_CHECKED_IN') {
            return 'You have already checked in today.';
          }
          if (errorCode == 'NO_ACTIVE_SESSION') {
            return 'No active checked-in session found for checkout.';
          }
          if (errorCode == 'BREAK_ACTIVE') {
            return 'Cannot check out while a break is active. Please end your break first.';
          }
          if (errorCode == 'NOT_CHECKED_IN') {
            return 'You must check in first before performing this action.';
          }
          if (errorCode == 'BREAK_ALREADY_ACTIVE') {
            return 'You already have an active break session.';
          }
          if (errorCode == 'NO_ACTIVE_BREAK') {
            return 'No active break session found to end.';
          }
          if (errorCode == 'MAX_BREAKS_REACHED') {
            return 'You have reached the maximum allowed break count for today.';
          }
          if (errorCode == 'BREAK_NOT_ALLOWED_AT_THIS_TIME') {
            return 'Breaks are not allowed at this current time under your policy schedule.';
          }
        }
      }
      return error.message ?? 'Network error occurred. Please verify your connection.';
    }

    final errStr = error.toString();
    if (errStr.contains('STALE_LOCATION')) {
      return 'Location data is stale. Please refresh your GPS signal.';
    }
    if (errStr.contains('POOR_ACCURACY')) {
      return 'GPS accuracy is too low. Please move to an open area.';
    }
    return errStr.replaceAll('Exception:', '').replaceAll('Future error:', '').trim();
  }
}

final attendanceRepositoryProvider = Provider<AttendanceRepository>((ref) {
  return AttendanceRepository();
});

final attendanceProvider = StateNotifierProvider<AttendanceNotifier, AttendanceState>((ref) {
  final repo = ref.watch(attendanceRepositoryProvider);
  return AttendanceNotifier(repo);
});
