import 'dart:async';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../data/notification_repository.dart';
import '../models/notification_model.dart';

class NotificationState {
  final int unreadCount;
  final List<InAppNotificationModel> notifications;
  final InAppNotificationModel? activeAlert;
  final bool isLoading;
  final bool isLoadingMore;
  final bool hasMore;
  final int currentPage;
  final String? error;

  const NotificationState({
    this.unreadCount = 0,
    this.notifications = const [],
    this.activeAlert,
    this.isLoading = false,
    this.isLoadingMore = false,
    this.hasMore = true,
    this.currentPage = 1,
    this.error,
  });

  NotificationState copyWith({
    int? unreadCount,
    List<InAppNotificationModel>? notifications,
    InAppNotificationModel? activeAlert,
    bool clearActiveAlert = false,
    bool? isLoading,
    bool? isLoadingMore,
    bool? hasMore,
    int? currentPage,
    String? error,
  }) {
    return NotificationState(
      unreadCount: unreadCount ?? this.unreadCount,
      notifications: notifications ?? this.notifications,
      activeAlert: clearActiveAlert ? null : (activeAlert ?? this.activeAlert),
      isLoading: isLoading ?? this.isLoading,
      isLoadingMore: isLoadingMore ?? this.isLoadingMore,
      hasMore: hasMore ?? this.hasMore,
      currentPage: currentPage ?? this.currentPage,
      error: error,
    );
  }
}

class NotificationNotifier extends StateNotifier<NotificationState>
    with WidgetsBindingObserver {
  final NotificationRepository _repository;
  Timer? _pollTimer;
  Timer? _autoDismissTimer;
  bool _isFetching = false;
  final Set<String> _alertedUuids = {};

  /// How long the foreground alert banner (see ForegroundAlertOverlay) stays
  /// visible before auto-hiding — it used to require an explicit tap to
  /// dismiss, which meant banners piled up and stayed on screen indefinitely.
  static const _autoDismissDelay = Duration(seconds: 4);

  NotificationNotifier(this._repository) : super(const NotificationState()) {
    WidgetsBinding.instance.addObserver(this);
    startPolling();
  }

  @override
  void dispose() {
    stopPolling();
    _autoDismissTimer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Schedules the active alert banner to clear itself after
  /// [_autoDismissDelay] — guarded by uuid so a stale timer from a
  /// previously-shown alert can never dismiss a newer one that replaced it.
  void _scheduleAutoDismiss(String uuid) {
    _autoDismissTimer?.cancel();
    _autoDismissTimer = Timer(_autoDismissDelay, () {
      if (state.activeAlert?.uuid == uuid) {
        state = state.copyWith(clearActiveAlert: true);
      }
    });
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      startPolling();
      pollLatestNotifications();
    } else if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.inactive ||
        state == AppLifecycleState.detached) {
      stopPolling();
    }
  }

  /// Start periodic 15-second polling while app is in foreground
  void startPolling() {
    _pollTimer?.cancel();
    _pollTimer = Timer.periodic(const Duration(seconds: 15), (_) {
      pollLatestNotifications();
    });
  }

  /// Stop polling (when app goes to background or on logout)
  void stopPolling() {
    _pollTimer?.cancel();
    _pollTimer = null;
  }

  /// Foreground polling loop: fetches unread count and latest notifications
  Future<void> pollLatestNotifications() async {
    if (_isFetching) return;
    _isFetching = true;
    try {
      final count = await _repository.getUnreadCount();
      final result = await _repository.getNotifications(page: 1, perPage: 10);
      final items = result['items'] as List<InAppNotificationModel>;

      // Identify unread notifications that haven't been alerted yet
      InAppNotificationModel? candidateAlert;
      for (final n in items) {
        if (!n.isRead && !_alertedUuids.contains(n.uuid)) {
          candidateAlert = n;
          _alertedUuids.add(n.uuid);
          break; // Queue newest unread
        }
      }

      state = state.copyWith(
        unreadCount: count,
        notifications: items,
        activeAlert: candidateAlert ?? state.activeAlert,
      );
      if (candidateAlert != null) {
        _scheduleAutoDismiss(candidateAlert.uuid);
      }
    } catch (_) {
      // Silent failure on background poll
    } finally {
      _isFetching = false;
    }
  }

  /// Dismiss active foreground alert banner
  void dismissAlert() {
    _autoDismissTimer?.cancel();
    state = state.copyWith(clearActiveAlert: true);
  }

  /// Fetch only the badge count
  Future<void> fetchUnreadCount() async {
    try {
      final count = await _repository.getUnreadCount();
      state = state.copyWith(unreadCount: count);
    } catch (_) {
      // Silent failure
    }
  }

  /// Fetch first page of notifications (pull-to-refresh)
  Future<void> fetchNotifications() async {
    try {
      state = state.copyWith(isLoading: true, error: null);
      final result = await _repository.getNotifications(page: 1);
      final items = result['items'] as List<InAppNotificationModel>;
      final lastPage = result['last_page'] as int;

      // Track items already displayed
      for (final n in items) {
        if (n.isRead) {
          _alertedUuids.add(n.uuid);
        }
      }

      state = state.copyWith(
        notifications: items,
        isLoading: false,
        currentPage: 1,
        hasMore: 1 < lastPage,
      );

      await fetchUnreadCount();
    } catch (e) {
      state = state.copyWith(isLoading: false, error: e.toString());
    }
  }

  /// Fetch next page (pagination)
  Future<void> fetchMoreNotifications() async {
    if (state.isLoadingMore || !state.hasMore) return;
    try {
      state = state.copyWith(isLoadingMore: true);
      final nextPage = state.currentPage + 1;
      final result = await _repository.getNotifications(page: nextPage);
      final newItems = result['items'] as List<InAppNotificationModel>;
      final lastPage = result['last_page'] as int;

      state = state.copyWith(
        notifications: [...state.notifications, ...newItems],
        isLoadingMore: false,
        currentPage: nextPage,
        hasMore: nextPage < lastPage,
      );
    } catch (e) {
      state = state.copyWith(isLoadingMore: false, error: e.toString());
    }
  }

  /// Mark a single notification as read (optimistic)
  Future<void> markRead(String uuid) async {
    final updatedList = state.notifications.map((n) {
      if (n.uuid == uuid && !n.isRead) {
        return n.copyWith(isRead: true, readAt: DateTime.now());
      }
      return n;
    }).toList();

    final wasUnread =
        state.notifications.any((n) => n.uuid == uuid && !n.isRead);
    final newCount =
        wasUnread ? (state.unreadCount - 1).clamp(0, 9999) : state.unreadCount;

    // Clear active alert if it matches this notification
    final clearActive = state.activeAlert?.uuid == uuid;

    state = state.copyWith(
      notifications: updatedList,
      unreadCount: newCount,
      clearActiveAlert: clearActive,
    );

    try {
      await _repository.markRead(uuid);
    } catch (_) {
      state = state.copyWith(
        notifications: state.notifications.map((n) {
          if (n.uuid == uuid) return n.copyWith(isRead: false, readAt: null);
          return n;
        }).toList(),
        unreadCount: state.unreadCount + (wasUnread ? 1 : 0),
      );
    }
  }

  /// Mark all notifications as read
  Future<void> markAllRead() async {
    final updatedList =
        state.notifications.map((n) => n.copyWith(isRead: true)).toList();
    state = state.copyWith(
      notifications: updatedList,
      unreadCount: 0,
      clearActiveAlert: true,
    );

    try {
      await _repository.markAllRead();
    } catch (_) {
      await fetchNotifications();
    }
  }

  /// Clear state on logout
  void clearState() {
    stopPolling();
    _autoDismissTimer?.cancel();
    _alertedUuids.clear();
    state = const NotificationState();
  }
}

// ── Providers ──────────────────────────────────────────────────────────────

final notificationRepositoryProvider =
    Provider<NotificationRepository>((ref) => NotificationRepository());

/// Primary notification provider — shared across the whole app
final notificationProvider =
    StateNotifierProvider<NotificationNotifier, NotificationState>((ref) {
  final repo = ref.watch(notificationRepositoryProvider);
  return NotificationNotifier(repo);
});

/// Derived provider for the unread badge count
final unreadCountProvider = Provider<int>((ref) {
  return ref.watch(notificationProvider.select((s) => s.unreadCount));
});
