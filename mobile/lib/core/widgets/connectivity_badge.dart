import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../providers/connectivity_provider.dart';

/// Persistent Online/Offline indicator, meant to sit immediately next to
/// [NotificationBell] in every app bar. Shows a small pending-sync count
/// badge when there are queued attendance actions, and taps through to the
/// Sync History screen.
///
/// Mirrors [NotificationBell]'s shape/interaction (bare `IconButton` with an
/// optional badge) so it can be dropped into the same spot the bell already
/// occupies in each screen, with no layout changes needed.
class ConnectivityBadge extends ConsumerWidget {
  const ConnectivityBadge({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final isOnline = ref.watch(connectivityStatusProvider).valueOrNull ?? true;
    final pendingCount = ref.watch(pendingSyncCountProvider).valueOrNull ?? 0;

    final label = isOnline
        ? (pendingCount > 0 ? 'Online, $pendingCount syncing' : 'Online')
        : (pendingCount > 0 ? 'Offline, $pendingCount pending sync' : 'Offline');

    return Semantics(
      label: label,
      button: true,
      child: IconButton(
        tooltip: label,
        onPressed: () => context.push('/sync-history'),
        icon: Stack(
          clipBehavior: Clip.none,
          children: [
            Icon(
              isOnline ? Icons.cloud_done_rounded : Icons.cloud_off_rounded,
              color: isOnline ? Colors.white : const Color(0xFFFFD166),
            ),
            if (pendingCount > 0)
              Positioned(
                top: -4,
                right: -4,
                child: _CountBadge(count: pendingCount),
              ),
          ],
        ),
      ),
    );
  }
}

class _CountBadge extends StatelessWidget {
  final int count;
  const _CountBadge({required this.count});

  @override
  Widget build(BuildContext context) {
    final text = count > 99 ? '99+' : '$count';
    return Container(
      constraints: const BoxConstraints(minWidth: 16, minHeight: 16),
      padding: const EdgeInsets.symmetric(horizontal: 3, vertical: 1),
      decoration: BoxDecoration(
        color: const Color(0xFFFFD166),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: Colors.white, width: 1.5),
      ),
      child: Text(
        text,
        textAlign: TextAlign.center,
        style: const TextStyle(
          color: Color(0xFF1F2937),
          fontSize: 9,
          fontWeight: FontWeight.bold,
          height: 1.2,
        ),
      ),
    );
  }
}
