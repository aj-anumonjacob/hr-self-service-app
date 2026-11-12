import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/config/theme.dart';
import '../../../core/widgets/notification_bell.dart';
import '../../../core/widgets/connectivity_badge.dart';
import '../providers/manager_requests_provider.dart';
import '../models/attendance_request_model.dart';
import '../models/attendance_claim_model.dart';

class ManagerRequestsScreen extends ConsumerStatefulWidget {
  const ManagerRequestsScreen({super.key});

  @override
  ConsumerState<ManagerRequestsScreen> createState() => _ManagerRequestsScreenState();
}

class _ManagerRequestsScreenState extends ConsumerState<ManagerRequestsScreen> with SingleTickerProviderStateMixin {
  late TabController _tabController;

  final List<Map<String, String>> _tabs = [
    {'code': 'pending', 'label': 'Pending'},
    {'code': 'needs_explanation', 'label': 'Needs Explanation'},
    {'code': 'approved', 'label': 'Approved'},
    {'code': 'rejected', 'label': 'Rejected'},
    {'code': 'all', 'label': 'All Requests'},
  ];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: _tabs.length, vsync: this);
    _tabController.addListener(() {
      if (!_tabController.indexIsChanging) {
        final code = _tabs[_tabController.index]['code']!;
        ref.read(managerRequestsProvider.notifier).fetchRequests(tab: code);
      }
    });

    Future.microtask(() {
      ref.read(managerRequestsProvider.notifier).fetchRequests(tab: 'pending');
    });
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(managerRequestsProvider);

    return Scaffold(
      backgroundColor: const Color(0xFFF8F9FA),
      appBar: AppBar(
        title: const Text('Manager Request Console'),
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_ios_new_rounded, size: 20),
          tooltip: 'Back',
          onPressed: () {
            if (context.canPop()) {
              context.pop();
            } else {
              context.go('/manager-dashboard');
            }
          },
        ),
        actions: [
          const ConnectivityBadge(),
          const NotificationBell(),
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: () {
              ref.read(managerRequestsProvider.notifier).fetchRequests();
            },
          ),
        ],
        bottom: TabBar(
          controller: _tabController,
          isScrollable: true,
          labelColor: AppTheme.darkGold,
          unselectedLabelColor: Colors.grey,
          indicatorColor: AppTheme.darkGold,
          tabs: _tabs.map((t) => Tab(text: t['label'])).toList(),
        ),
      ),
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: 2, // Requests is index 2 for Manager
        selectedItemColor: AppTheme.darkGold,
        unselectedItemColor: Colors.grey,
        onTap: (index) {
          if (index == 0) {
            context.go('/manager-dashboard');
          } else if (index == 3) {
            context.go('/calendar');
          }
        },
        items: const [
          BottomNavigationBarItem(icon: Icon(Icons.dashboard), label: 'Dashboard'),
          BottomNavigationBarItem(icon: Icon(Icons.group), label: 'Team'),
          BottomNavigationBarItem(icon: Icon(Icons.assignment_turned_in), label: 'Requests'),
          BottomNavigationBarItem(icon: Icon(Icons.calendar_month), label: 'Calendar'),
        ],
      ),
      body: state.isLoading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: () async => ref.read(managerRequestsProvider.notifier).fetchRequests(),
              child: _buildRequestsAndClaimsList(state),
            ),
    );
  }

  Widget _buildRequestsAndClaimsList(ManagerRequestsState state) {
    final requests = state.requests;
    final claims = state.claims;

    if (requests.isEmpty && claims.isEmpty) {
      return ListView(
        padding: const EdgeInsets.all(40.0),
        children: [
          Center(
            child: Column(
              children: [
                Icon(Icons.check_circle_outline, size: 48, color: Colors.grey[400]),
                const SizedBox(height: 12),
                const Text('No pending requests found in this view.', style: TextStyle(color: Colors.grey, fontWeight: FontWeight.bold)),
              ],
            ),
          ),
        ],
      );
    }

    return ListView(
      padding: const EdgeInsets.all(16.0),
      children: [
        if (requests.isNotEmpty) ...[
          const Text('ATTENDANCE REQUESTS', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.grey, letterSpacing: 0.5)),
          const SizedBox(height: 8),
          ...requests.map((req) => _buildManagerRequestCard(req)),
          const SizedBox(height: 16),
        ],
        if (claims.isNotEmpty) ...[
          const Text('ATTENDANCE CLAIMS', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.grey, letterSpacing: 0.5)),
          const SizedBox(height: 8),
          ...claims.map((clm) => _buildManagerClaimCard(clm)),
        ],
      ],
    );
  }

  Widget _buildManagerRequestCard(AttendanceRequestModel req) {
    final isPending = req.status == 'pending' || req.status == 'submitted' || req.status == 'employee_responded';

    return Card(
      color: Colors.white,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10), side: BorderSide(color: Colors.grey[200]!)),
      child: Padding(
        padding: const EdgeInsets.all(14.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(req.employeeName ?? 'Employee', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: AppTheme.charcoal)),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(color: req.statusBgColor, borderRadius: BorderRadius.circular(6)),
                  child: Text(req.displayStatusLabel, style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: req.statusColor)),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text('${req.displayTypeLabel} | Date: ${req.date} | Delay: ${req.delayMinutes}m', style: TextStyle(fontSize: 12, color: Colors.grey[800])),
            if (req.description != null && req.description!.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text('Note: ${req.description}', style: const TextStyle(fontSize: 11, fontStyle: FontStyle.italic, color: Colors.black87)),
            ],
            if (req.employeeResponse != null && req.employeeResponse!.isNotEmpty) ...[
              const SizedBox(height: 6),
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(color: Colors.purple[50], borderRadius: BorderRadius.circular(6)),
                child: Text('Employee Acknowledgment Response: ${req.employeeResponse}', style: const TextStyle(fontSize: 11, color: Colors.purple, fontWeight: FontWeight.bold)),
              ),
            ],
            if (isPending) ...[
              const Divider(height: 16),
              Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  OutlinedButton(
                    onPressed: () => _showActionDialog(req.uuid, 'explanation', isClaim: false),
                    child: const Text('Request Exp.', style: TextStyle(fontSize: 11, color: Colors.orange)),
                  ),
                  const SizedBox(width: 8),
                  OutlinedButton(
                    onPressed: () => _showActionDialog(req.uuid, 'reject', isClaim: false),
                    style: OutlinedButton.styleFrom(side: const BorderSide(color: Colors.red)),
                    child: const Text('Reject', style: TextStyle(fontSize: 11, color: Colors.red)),
                  ),
                  const SizedBox(width: 8),
                  ElevatedButton(
                    onPressed: () => _showActionDialog(req.uuid, 'approve', isClaim: false),
                    style: ElevatedButton.styleFrom(backgroundColor: Colors.green[700]),
                    child: const Text('Approve', style: TextStyle(fontSize: 11, color: Colors.white, fontWeight: FontWeight.bold)),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildManagerClaimCard(AttendanceClaimModel clm) {
    final isPending = clm.status == 'pending' || clm.status == 'submitted' || clm.status == 'employee_responded';

    return Card(
      color: Colors.white,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10), side: BorderSide(color: Colors.grey[200]!)),
      child: Padding(
        padding: const EdgeInsets.all(14.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(clm.employeeName ?? 'Employee', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: AppTheme.charcoal)),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(color: clm.statusBgColor, borderRadius: BorderRadius.circular(6)),
                  child: Text(clm.displayStatusLabel, style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: clm.statusColor)),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text('${clm.displayTypeLabel} | Date: ${clm.date}', style: TextStyle(fontSize: 12, color: Colors.grey[800])),
            if (clm.description.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text('Note: ${clm.description}', style: const TextStyle(fontSize: 11, fontStyle: FontStyle.italic, color: Colors.black87)),
            ],
            if (clm.employeeResponse != null && clm.employeeResponse!.isNotEmpty) ...[
              const SizedBox(height: 6),
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(color: Colors.purple[50], borderRadius: BorderRadius.circular(6)),
                child: Text('Employee Acknowledgment Response: ${clm.employeeResponse}', style: const TextStyle(fontSize: 11, color: Colors.purple, fontWeight: FontWeight.bold)),
              ),
            ],
            if (isPending) ...[
              const Divider(height: 16),
              Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  OutlinedButton(
                    onPressed: () => _showActionDialog(clm.uuid, 'explanation', isClaim: true),
                    child: const Text('Request Exp.', style: TextStyle(fontSize: 11, color: Colors.orange)),
                  ),
                  const SizedBox(width: 8),
                  OutlinedButton(
                    onPressed: () => _showActionDialog(clm.uuid, 'reject', isClaim: true),
                    style: OutlinedButton.styleFrom(side: const BorderSide(color: Colors.red)),
                    child: const Text('Reject', style: TextStyle(fontSize: 11, color: Colors.red)),
                  ),
                  const SizedBox(width: 8),
                  ElevatedButton(
                    onPressed: () => _showActionDialog(clm.uuid, 'approve', isClaim: true),
                    style: ElevatedButton.styleFrom(backgroundColor: Colors.green[700]),
                    child: const Text('Approve', style: TextStyle(fontSize: 11, color: Colors.white, fontWeight: FontWeight.bold)),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }

  void _showActionDialog(String uuid, String action, {required bool isClaim}) {
    final commentController = TextEditingController();
    final isReject = action == 'reject';
    final isExp = action == 'explanation';

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isReject ? 'Reject Submission' : isExp ? 'Request Explanation' : 'Approve Submission'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(isReject
                ? 'Please enter the reason for rejection (Required):'
                : isExp
                    ? 'Please enter the explanation request for the employee (Required):'
                    : 'Add an optional approval comment:'),
            const SizedBox(height: 10),
            TextField(
              controller: commentController,
              maxLines: 3,
              maxLength: 500,
              decoration: const InputDecoration(border: OutlineInputBorder(), hintText: 'Enter comment...'),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('CANCEL')),
          ElevatedButton(
            onPressed: () async {
              final comment = commentController.text.trim();
              if ((isReject || isExp) && comment.isEmpty) {
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Comment is required.')));
                return;
              }
              Navigator.of(ctx).pop();

              final notifier = ref.read(managerRequestsProvider.notifier);
              if (isClaim) {
                if (action == 'approve') await notifier.approveClaim(uuid, comment: comment.isEmpty ? null : comment);
                if (action == 'reject') await notifier.rejectClaim(uuid, comment);
                if (action == 'explanation') await notifier.requestExplanationForClaim(uuid, comment);
              } else {
                if (action == 'approve') await notifier.approveRequest(uuid, comment: comment.isEmpty ? null : comment);
                if (action == 'reject') await notifier.rejectRequest(uuid, comment);
                if (action == 'explanation') await notifier.requestExplanationForRequest(uuid, comment);
              }
            },
            child: const Text('SUBMIT'),
          ),
        ],
      ),
    );
  }
}
