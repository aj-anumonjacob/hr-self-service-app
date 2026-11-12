import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'models/attendance_event_model.dart';
import 'providers/recents_provider.dart';
import '../../core/config/theme.dart';
import '../../core/widgets/portal_header.dart';
import '../../core/widgets/portal_bottom_nav_bar.dart';

class RecentsScreen extends ConsumerStatefulWidget {
  const RecentsScreen({super.key});

  @override
  ConsumerState<RecentsScreen> createState() => _RecentsScreenState();
}

class _RecentsScreenState extends ConsumerState<RecentsScreen> {
  final ScrollController _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_onScroll);
  }

  @override
  void dispose() {
    _scrollController.removeListener(_onScroll);
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollController.position.pixels >= _scrollController.position.maxScrollExtent - 200) {
      ref.read(recentsProvider.notifier).loadNextPage();
    }
  }

  Color _getEventColor(String type) {
    switch (type) {
      case 'ATTENDANCE_CHECKED_IN':
        return Colors.green[700]!;
      case 'ATTENDANCE_CHECKED_OUT':
        return Colors.red[700]!;
      case 'BREAK_STARTED':
        return Colors.orange[800]!;
      case 'BREAK_ENDED':
        return Colors.blue[700]!;
      case 'MANAGER_CORRECTION':
        return Colors.purple[700]!;
      default:
        return AppTheme.charcoal;
    }
  }

  IconData _getEventIcon(String type) {
    switch (type) {
      case 'ATTENDANCE_CHECKED_IN':
        return Icons.login;
      case 'ATTENDANCE_CHECKED_OUT':
        return Icons.logout;
      case 'BREAK_STARTED':
        return Icons.free_breakfast;
      case 'BREAK_ENDED':
        return Icons.work_history;
      case 'MANAGER_CORRECTION':
        return Icons.edit_note;
      default:
        return Icons.event_note;
    }
  }

  Map<String, List<AttendanceEventModel>> _groupEventsByDate(List<AttendanceEventModel> events) {
    final Map<String, List<AttendanceEventModel>> grouped = {};
    for (final event in events) {
      final header = event.groupDateHeader;
      grouped.putIfAbsent(header, () => []).add(event);
    }
    return grouped;
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(recentsProvider);
    final groupedEvents = _groupEventsByDate(state.events);

    return Scaffold(
      backgroundColor: AppTheme.lightAqua,
      appBar: PortalHeader(
        title: 'Recents',
        actionButton: Container(
          width: 36,
          height: 36,
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.12),
            borderRadius: BorderRadius.circular(10),
          ),
          child: IconButton(
            padding: EdgeInsets.zero,
            icon: const Icon(Icons.refresh_rounded, color: Colors.white, size: 18),
            tooltip: 'Refresh',
            onPressed: () {
              ref.read(recentsProvider.notifier).fetchEvents(isRefresh: true);
            },
          ),
        ),
      ),
      body: Column(
        children: [
          // Filter Choice Chips Bar
          Container(
            color: Colors.white,
            padding: const EdgeInsets.symmetric(horizontal: 16.0, vertical: 8.0),
            child: Row(
              children: [
                _buildFilterChip('All', 'all', state.filter),
                const SizedBox(width: 8),
                _buildFilterChip('Attendance', 'attendance', state.filter),
                const SizedBox(width: 8),
                _buildFilterChip('Breaks', 'breaks', state.filter),
              ],
            ),
          ),
          const Divider(height: 1),

          // Main Activity List / Empty State / Error State
          Expanded(
            child: state.isLoading
                ? const Center(child: CircularProgressIndicator(color: AppTheme.darkGold))
                : state.error != null && state.events.isEmpty
                    ? Center(
                        child: Padding(
                          padding: const EdgeInsets.all(24.0),
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              const Icon(Icons.error_outline, size: 48, color: Colors.red),
                              const SizedBox(height: 12),
                              Text(
                                state.error!,
                                textAlign: TextAlign.center,
                                style: const TextStyle(color: Colors.grey),
                              ),
                              const SizedBox(height: 16),
                              ElevatedButton.icon(
                                style: ElevatedButton.styleFrom(backgroundColor: AppTheme.darkGold),
                                onPressed: () {
                                  ref.read(recentsProvider.notifier).fetchEvents();
                                },
                                icon: const Icon(Icons.refresh, color: Colors.white),
                                label: const Text('Retry', style: TextStyle(color: Colors.white)),
                              ),
                            ],
                          ),
                        ),
                      )
                    : RefreshIndicator(
                        onRefresh: () async {
                          await ref.read(recentsProvider.notifier).fetchEvents(isRefresh: true);
                        },
                        child: state.events.isEmpty
                            ? ListView(
                                children: const [
                                  SizedBox(height: 120),
                                  Center(
                                    child: Column(
                                      children: [
                                        Icon(Icons.history, size: 56, color: Colors.grey),
                                        SizedBox(height: 12),
                                        Text(
                                          'No attendance activity yet.',
                                          style: TextStyle(color: Colors.grey, fontSize: 14, fontWeight: FontWeight.w500),
                                        ),
                                      ],
                                    ),
                                  ),
                                ],
                              )
                            : ListView.builder(
                                controller: _scrollController,
                                padding: const EdgeInsets.all(16.0),
                                itemCount: groupedEvents.length + (state.isLoadingMore ? 1 : 0),
                                itemBuilder: (context, index) {
                                  if (index == groupedEvents.length) {
                                    return const Padding(
                                      padding: EdgeInsets.symmetric(vertical: 16.0),
                                      child: Center(
                                        child: CircularProgressIndicator(strokeWidth: 2, color: AppTheme.darkGold),
                                      ),
                                    );
                                  }

                                  final dateHeader = groupedEvents.keys.elementAt(index);
                                  final eventsForDate = groupedEvents[dateHeader]!;

                                  return Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      // Group Date Header
                                      Padding(
                                        padding: const EdgeInsets.only(top: 8.0, bottom: 8.0),
                                        child: Text(
                                          dateHeader,
                                          style: TextStyle(
                                            fontSize: 12,
                                            fontWeight: FontWeight.bold,
                                            color: Colors.grey[700],
                                            letterSpacing: 0.5,
                                          ),
                                        ),
                                      ),
                                      ...eventsForDate.map((evt) => _buildEventCard(evt)),
                                    ],
                                  );
                                },
                              ),
                      ),
          ),
        ],
      ),
      bottomNavigationBar: const PortalBottomNavBar(currentTab: NavTab.recents),
    );
  }

  Widget _buildFilterChip(String label, String value, String activeFilter) {
    final isSelected = activeFilter == value;
    return ChoiceChip(
      label: Text(
        label,
        style: TextStyle(
          fontSize: 13.5,
          fontWeight: isSelected ? FontWeight.bold : FontWeight.w600,
          color: isSelected ? Colors.white : AppTheme.charcoal,
        ),
      ),
      selected: isSelected,
      selectedColor: AppTheme.darkGold,
      backgroundColor: Colors.grey[100],
      showCheckmark: false,
      onSelected: (_) {
        ref.read(recentsProvider.notifier).setFilter(value);
      },
    );
  }

  Widget _buildEventCard(AttendanceEventModel evt) {
    final color = _getEventColor(evt.type);
    final icon = _getEventIcon(evt.type);

    return Card(
      color: Colors.white,
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 10.0),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(color: Colors.grey[200]!),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Row(
          children: [
            // Event Type Icon Avatar
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: color.withAlpha(25),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, color: color, size: 20),
            ),
            const SizedBox(width: 12),

            // Event Details Column
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        evt.title,
                        style: const TextStyle(
                          fontSize: 13.5,
                          fontWeight: FontWeight.bold,
                          color: AppTheme.charcoal,
                        ),
                      ),
                      Text(
                        evt.time ?? '',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                          color: Colors.grey[600],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(
                    evt.subtitleDetail,
                    style: TextStyle(
                      fontSize: 11,
                      color: Colors.grey[700],
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
