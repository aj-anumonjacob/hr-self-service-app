import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/config/theme.dart';
import '../../../core/widgets/portal_header.dart';
import '../providers/sync_history_provider.dart';

class SyncHistoryScreen extends ConsumerWidget {
  const SyncHistoryScreen({super.key});

  String _actionLabel(String actionType) {
    switch (actionType) {
      case 'check_in':
        return 'Check-in';
      case 'check_out':
        return 'Check-out';
      case 'start_break':
        return 'Break started';
      case 'end_break':
        return 'Break ended';
      default:
        return actionType;
    }
  }

  ({IconData icon, Color color, String label}) _statusVisual(String status) {
    switch (status) {
      case 'pending':
        return (icon: Icons.schedule_rounded, color: const Color(0xFFB45309), label: 'Pending');
      case 'syncing':
        return (icon: Icons.sync_rounded, color: const Color(0xFF1D4ED8), label: 'Syncing…');
      case 'synced':
        return (icon: Icons.check_circle_rounded, color: const Color(0xFF15803D), label: 'Synced');
      case 'failed':
        return (icon: Icons.error_rounded, color: const Color(0xFFB91C1C), label: 'Failed');
      case 'rejected':
        return (icon: Icons.block_rounded, color: const Color(0xFF6B7280), label: 'Rejected');
      default:
        return (icon: Icons.help_outline_rounded, color: Colors.grey, label: status);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(syncHistoryProvider);

    return Scaffold(
      backgroundColor: AppTheme.lightAqua,
      appBar: const PortalHeader(title: 'Sync History'),
      body: state.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(child: Text('Failed to load sync history: $e')),
        data: (items) {
          if (items.isEmpty) {
            return const Center(
              child: Padding(
                padding: EdgeInsets.all(24),
                child: Text(
                  'No offline actions yet. Anything you do while offline (check-in, check-out, breaks) will show up here.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.grey),
                ),
              ),
            );
          }

          return RefreshIndicator(
            onRefresh: () => ref.read(syncHistoryProvider.notifier).syncNow(),
            child: ListView.builder(
              padding: const EdgeInsets.all(12),
              itemCount: items.length,
              itemBuilder: (context, index) {
                final item = items[index];
                final status = item['status'] as String;
                final visual = _statusVisual(status);
                final actionType = item['action_type'] as String;
                final createdAt = DateTime.tryParse(item['created_at'] as String? ?? '');
                final lastError = item['last_error'] as String?;
                Map<String, dynamic>? payload;
                try {
                  payload = jsonDecode(item['payload'] as String) as Map<String, dynamic>;
                } catch (_) {}

                return Card(
                  margin: const EdgeInsets.only(bottom: 10),
                  elevation: 0,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                    side: BorderSide(color: visual.color.withValues(alpha: 0.25)),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Icon(visual.icon, color: visual.color, size: 20),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                _actionLabel(actionType),
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                              ),
                            ),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                              decoration: BoxDecoration(
                                color: visual.color.withValues(alpha: 0.12),
                                borderRadius: BorderRadius.circular(20),
                              ),
                              child: Text(
                                visual.label,
                                style: TextStyle(color: visual.color, fontSize: 11, fontWeight: FontWeight.w700),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 6),
                        if (createdAt != null)
                          Text(
                            DateFormat('MMM d, y • h:mm a').format(createdAt),
                            style: const TextStyle(fontSize: 12, color: Colors.grey),
                          ),
                        if (payload != null && payload['latitude'] != null)
                          Padding(
                            padding: const EdgeInsets.only(top: 2),
                            child: Text(
                              'GPS: ${(payload['latitude'] as num).toStringAsFixed(5)}, ${(payload['longitude'] as num).toStringAsFixed(5)}',
                              style: const TextStyle(fontSize: 11, color: Colors.grey),
                            ),
                          ),
                        if (lastError != null) ...[
                          const SizedBox(height: 6),
                          Text(
                            lastError,
                            style: TextStyle(fontSize: 12, color: visual.color, fontWeight: FontWeight.w600),
                          ),
                        ],
                        if (status == 'failed' || status == 'rejected') ...[
                          const SizedBox(height: 8),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.end,
                            children: [
                              if (status == 'failed')
                                TextButton.icon(
                                  onPressed: () => ref.read(syncHistoryProvider.notifier).retry(item['id'] as int),
                                  icon: const Icon(Icons.refresh_rounded, size: 16),
                                  label: const Text('Retry'),
                                ),
                              if (status == 'rejected')
                                TextButton.icon(
                                  onPressed: () => context.push('/requests'),
                                  icon: const Icon(Icons.description_outlined, size: 16),
                                  label: const Text('File a claim instead'),
                                ),
                            ],
                          ),
                        ],
                      ],
                    ),
                  ),
                );
              },
            ),
          );
        },
      ),
    );
  }
}
