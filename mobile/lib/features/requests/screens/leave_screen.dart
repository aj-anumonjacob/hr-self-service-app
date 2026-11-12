import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/config/theme.dart';
import '../../../core/widgets/portal_header.dart';
import '../../../core/widgets/portal_bottom_nav_bar.dart';
import '../providers/leave_provider.dart';
import '../widgets/leave_tab_view.dart';

class LeaveScreen extends ConsumerStatefulWidget {
  const LeaveScreen({super.key});

  @override
  ConsumerState<LeaveScreen> createState() => _LeaveScreenState();
}

class _LeaveScreenState extends ConsumerState<LeaveScreen> {
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.lightAqua,
      appBar: PortalHeader(
        title: 'Leave Management',
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
              ref.read(leaveProvider.notifier).loadData();
            },
          ),
        ),
      ),
      bottomNavigationBar: const PortalBottomNavBar(currentTab: NavTab.leave),
      body: const LeaveTabView(),
    );
  }
}
