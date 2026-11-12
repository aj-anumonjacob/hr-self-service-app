import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../features/notifications/models/notification_model.dart';
import '../../features/notifications/providers/notification_provider.dart';
import '../config/theme.dart';

import '../config/router.dart';

/// ForegroundAlertOverlay — floating top banner overlay that displays newly arrived
/// in-app notifications over any active screen in the Flutter application.
class ForegroundAlertOverlay extends ConsumerWidget {
  final Widget child;

  const ForegroundAlertOverlay({super.key, required this.child});

  void _handleViewTap(BuildContext context, WidgetRef ref, InAppNotificationModel alert) {
    // 1. Mark as read
    ref.read(notificationProvider.notifier).markRead(alert.uuid);

    // 2. Dismiss banner
    ref.read(notificationProvider.notifier).dismissAlert();

    // 3. Navigate to target destination based on navigation payload
    final nav = alert.navigation;
    final navType = nav?['type'] as String?;

    String targetRoute = '/notifications';
    if (navType == 'activeBreak' || navType == 'checkIn') {
      targetRoute = '/check-in';
    } else if (navType == 'employeeAttendance') {
      targetRoute = '/manager/requests';
    } else if (navType == 'request') {
      targetRoute = '/requests';
    }

    try {
      appRouter.push(targetRoute);
    } catch (_) {
      try {
        context.push(targetRoute);
      } catch (_) {}
    }
  }

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
        return Icons.notifications_active_rounded;
    }
  }

  Color _colorForType(String type) {
    switch (type) {
      case InAppNotificationModel.typeBreakEndingSoon:
        return Colors.orangeAccent;
      case InAppNotificationModel.typeBreakExpired:
      case InAppNotificationModel.typeBreakOverdueEmployee:
        return Colors.redAccent;
      case InAppNotificationModel.typeBreakOverdueManager:
      case InAppNotificationModel.typeBreakOverdueAdmin:
        return Colors.amberAccent;
      default:
        return AppTheme.darkGold;
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final alert = ref.watch(notificationProvider.select((s) => s.activeAlert));

    return Stack(
      children: [
        child,
        if (alert != null)
          Positioned(
            top: MediaQuery.of(context).padding.top + 8,
            left: 12,
            right: 12,
            child: Material(
              color: Colors.transparent,
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 300),
                curve: Curves.easeOut,
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                decoration: BoxDecoration(
                  color: AppTheme.charcoal,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: _colorForType(alert.type), width: 1.5),
                  boxShadow: const [
                    BoxShadow(
                      color: Colors.black38,
                      blurRadius: 10,
                      offset: Offset(0, 4),
                    ),
                  ],
                ),
                child: Row(
                  children: [
                    // Notification Icon Badge
                    Container(
                      padding: const EdgeInsets.all(8),
                      decoration: BoxDecoration(
                        color: _colorForType(alert.type).withAlpha(30),
                        shape: BoxShape.circle,
                      ),
                      child: Icon(
                        _iconForType(alert.type),
                        color: _colorForType(alert.type),
                        size: 22,
                      ),
                    ),
                    const SizedBox(width: 12),

                    // Notification Text Content
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            alert.title,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 14,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            alert.message,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              color: Colors.grey[300],
                              fontSize: 12,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),

                    // Action Buttons: View & Dismiss
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        TextButton(
                          style: TextButton.styleFrom(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                            minimumSize: Size.zero,
                            tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                          ),
                          onPressed: () => _handleViewTap(context, ref, alert),
                          child: const Text(
                            'VIEW',
                            style: TextStyle(
                              color: AppTheme.darkGold,
                              fontWeight: FontWeight.bold,
                              fontSize: 12,
                            ),
                          ),
                        ),
                        IconButton(
                          padding: EdgeInsets.zero,
                          constraints: const BoxConstraints(),
                          icon: const Icon(Icons.close, color: Colors.grey, size: 18),
                          onPressed: () {
                            ref.read(notificationProvider.notifier).dismissAlert();
                          },
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ),
      ],
    );
  }
}
