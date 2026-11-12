import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../notifications/models/notification_model.dart';
import '../notifications/providers/notification_provider.dart';
import '../../core/config/theme.dart';

/// Notification Centre — persistent in-app notification list.
///
/// • Newest first, grouped by Today / Yesterday / Earlier
/// • Pull-to-refresh
/// • Pagination (loads more on scroll)
/// • Tap to mark as read + navigate
/// • Mark All as Read in AppBar
class NotificationCentreScreen extends ConsumerStatefulWidget {
  const NotificationCentreScreen({super.key});

  @override
  ConsumerState<NotificationCentreScreen> createState() =>
      _NotificationCentreScreenState();
}

class _NotificationCentreScreenState
    extends ConsumerState<NotificationCentreScreen> {
  final _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    Future.microtask(
      () => ref.read(notificationProvider.notifier).fetchNotifications(),
    );
    _scrollController.addListener(_onScroll);
  }

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollController.position.pixels >=
        _scrollController.position.maxScrollExtent - 200) {
      ref.read(notificationProvider.notifier).fetchMoreNotifications();
    }
  }

  // ── Grouping ──────────────────────────────────────────────────────────────

  Map<String, List<InAppNotificationModel>> _groupNotifications(
    List<InAppNotificationModel> notifications,
  ) {
    final now = DateTime.now();
    final todayStart = DateTime(now.year, now.month, now.day);
    final yesterdayStart = todayStart.subtract(const Duration(days: 1));

    final grouped = <String, List<InAppNotificationModel>>{
      'Today': [],
      'Yesterday': [],
      'Earlier': [],
    };

    for (final n in notifications) {
      final localTime = n.createdAt.toLocal();
      if (localTime.isAfter(todayStart)) {
        grouped['Today']!.add(n);
      } else if (localTime.isAfter(yesterdayStart)) {
        grouped['Yesterday']!.add(n);
      } else {
        grouped['Earlier']!.add(n);
      }
    }

    grouped.removeWhere((_, list) => list.isEmpty);
    return grouped;
  }

  // ── Navigation ────────────────────────────────────────────────────────────

  void _handleTap(InAppNotificationModel notification) async {
    // Mark as read first
    if (!notification.isRead) {
      await ref
          .read(notificationProvider.notifier)
          .markRead(notification.uuid);
    }

    if (!mounted) return;

    final nav = notification.navigation;
    if (nav == null) return;

    final type = nav['type'] as String?;

    switch (type) {
      case 'activeBreak':
        context.go('/dashboard');
        break;
      case 'employeeAttendance':
        context.push('/manager-dashboard');
        break;
      default:
        // Safe fallback — notification is already marked read
        break;
    }
  }

  // ── Icon per type ─────────────────────────────────────────────────────────

  IconData _iconForType(String type) {
    switch (type) {
      case InAppNotificationModel.typeBreakEndingSoon:
        return Icons.alarm;
      case InAppNotificationModel.typeBreakExpired:
        return Icons.timer_off;
      case InAppNotificationModel.typeBreakOverdueEmployee:
        return Icons.warning_amber_rounded;
      case InAppNotificationModel.typeBreakOverdueManager:
      case InAppNotificationModel.typeBreakOverdueAdmin:
        return Icons.people_alt_outlined;
      default:
        return Icons.notifications_outlined;
    }
  }

  Color _colorForType(String type) {
    switch (type) {
      case InAppNotificationModel.typeBreakEndingSoon:
        return Colors.amber[700]!;
      case InAppNotificationModel.typeBreakExpired:
        return Colors.orange[800]!;
      case InAppNotificationModel.typeBreakOverdueEmployee:
      case InAppNotificationModel.typeBreakOverdueManager:
      case InAppNotificationModel.typeBreakOverdueAdmin:
        return Colors.red[700]!;
      default:
        return AppTheme.darkGold;
    }
  }

  // ── Build ─────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(notificationProvider);

    return Scaffold(
      backgroundColor: const Color(0xFFF8F9FA),
      appBar: AppBar(
        title: const Text('Notifications'),
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_ios_new_rounded, size: 20),
          tooltip: 'Back',
          onPressed: () {
            if (context.canPop()) {
              context.pop();
            } else {
              context.go('/dashboard');
            }
          },
        ),
        actions: [
          if (state.unreadCount > 0)
            TextButton(
              onPressed: () =>
                  ref.read(notificationProvider.notifier).markAllRead(),
              child: const Text(
                'Mark All Read',
                style: TextStyle(color: Colors.white, fontSize: 12),
              ),
            ),
        ],
      ),
      body: _buildBody(state),
    );
  }

  Widget _buildBody(NotificationState state) {
    if (state.isLoading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (state.error != null && state.notifications.isEmpty) {
      return _buildErrorState(state.error!);
    }

    if (state.notifications.isEmpty) {
      return _buildEmptyState();
    }

    final grouped = _groupNotifications(state.notifications);
    final sections = grouped.entries.toList();

    // Build flat list: [header, item, item, header, item, ...]
    final List<Widget> rows = [];
    for (final entry in sections) {
      rows.add(_buildGroupHeader(entry.key));
      for (final notification in entry.value) {
        rows.add(_buildNotificationTile(notification));
      }
    }

    if (state.isLoadingMore) {
      rows.add(const Padding(
        padding: EdgeInsets.all(16),
        child: Center(child: CircularProgressIndicator()),
      ));
    }

    return RefreshIndicator(
      onRefresh: () =>
          ref.read(notificationProvider.notifier).fetchNotifications(),
      child: ListView.builder(
        controller: _scrollController,
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.only(bottom: 20),
        itemCount: rows.length,
        itemBuilder: (_, i) => rows[i],
      ),
    );
  }

  Widget _buildGroupHeader(String label) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 6),
      child: Text(
        label.toUpperCase(),
        style: const TextStyle(
          fontSize: 10,
          fontWeight: FontWeight.bold,
          color: Colors.grey,
          letterSpacing: 0.8,
        ),
      ),
    );
  }

  Widget _buildNotificationTile(InAppNotificationModel n) {
    final timeStr = DateFormat('h:mm a').format(n.createdAt.toLocal());
    final color = _colorForType(n.type);

    return InkWell(
      onTap: () => _handleTap(n),
      child: Container(
        color: n.isRead ? Colors.transparent : color.withAlpha(12),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Icon
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(
                color: color.withAlpha(25),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Icon(_iconForType(n.type), color: color, size: 18),
            ),
            const SizedBox(width: 12),
            // Content
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          n.title,
                          style: TextStyle(
                            fontSize: 13,
                            fontWeight: n.isRead
                                ? FontWeight.w500
                                : FontWeight.bold,
                            color: AppTheme.charcoal,
                          ),
                        ),
                      ),
                      if (!n.isRead)
                        Container(
                          width: 8,
                          height: 8,
                          margin: const EdgeInsets.only(left: 6),
                          decoration: BoxDecoration(
                            color: color,
                            shape: BoxShape.circle,
                          ),
                        ),
                    ],
                  ),
                  const SizedBox(height: 2),
                  Text(
                    n.message,
                    style: TextStyle(
                      fontSize: 12,
                      color: Colors.grey[700],
                    ),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    timeStr,
                    style: const TextStyle(fontSize: 10, color: Colors.grey),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildEmptyState() {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.notifications_off_outlined,
                size: 56, color: Colors.grey),
            SizedBox(height: 16),
            Text(
              'No notifications yet',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: Colors.grey,
              ),
            ),
            SizedBox(height: 8),
            Text(
              'Your attendance and break notifications\nwill appear here.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 13, color: Colors.grey),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildErrorState(String error) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.error_outline, size: 48, color: Colors.red),
            const SizedBox(height: 12),
            const Text(
              'Could not load notifications',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
            ),
            const SizedBox(height: 8),
            ElevatedButton.icon(
              onPressed: () =>
                  ref.read(notificationProvider.notifier).fetchNotifications(),
              icon: const Icon(Icons.refresh),
              label: const Text('Retry'),
            ),
          ],
        ),
      ),
    );
  }
}
