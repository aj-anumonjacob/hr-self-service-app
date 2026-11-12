import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../features/notifications/providers/notification_provider.dart';

/// Shared notification bell widget for authenticated app bars.
///
/// Reads [unreadCountProvider] so it only rebuilds when the badge number
/// changes — it does not trigger full-screen rebuilds.
///
/// Usage:
///   AppBar(
///     actions: [
///       const NotificationBell(),
///       // ... other actions
///     ],
///   )
class NotificationBell extends ConsumerWidget {
  const NotificationBell({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final unreadCount = ref.watch(unreadCountProvider);

    final String semanticLabel = unreadCount == 0
        ? 'Notifications'
        : 'Notifications, $unreadCount unread';

    return Semantics(
      label: semanticLabel,
      button: true,
      child: IconButton(
        tooltip: semanticLabel,
        onPressed: () => context.push('/notifications'),
        icon: Stack(
          clipBehavior: Clip.none,
          children: [
            Icon(
              unreadCount > 0
                  ? Icons.notifications
                  : Icons.notifications_outlined,
              color: Colors.white,
            ),
            if (unreadCount > 0)
              Positioned(
                top: -4,
                right: -4,
                child: _Badge(count: unreadCount),
              ),
          ],
        ),
      ),
    );
  }
}

class _Badge extends StatelessWidget {
  final int count;

  const _Badge({required this.count});

  @override
  Widget build(BuildContext context) {
    final label = count > 99 ? '99+' : '$count';

    return Container(
      constraints: const BoxConstraints(minWidth: 16, minHeight: 16),
      padding: const EdgeInsets.symmetric(horizontal: 3, vertical: 1),
      decoration: BoxDecoration(
        color: Colors.red,
        borderRadius: BorderRadius.circular(8),
        // High-contrast border so badge is visible on any background
        border: Border.all(color: Colors.white, width: 1.5),
      ),
      child: Text(
        label,
        textAlign: TextAlign.center,
        style: const TextStyle(
          color: Colors.white,
          fontSize: 9,
          fontWeight: FontWeight.bold,
          height: 1.2,
        ),
      ),
    );
  }
}
