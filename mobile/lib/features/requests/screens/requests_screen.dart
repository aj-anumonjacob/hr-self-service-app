import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/config/theme.dart';
import '../../../core/widgets/portal_header.dart';
import '../../../core/widgets/portal_bottom_nav_bar.dart';
import '../providers/requests_provider.dart';
import '../models/attendance_request_model.dart';
import '../models/attendance_claim_model.dart';
import '../widgets/attendance_claim_form.dart';
import '../widgets/request_detail_dialog.dart';

class RequestsScreen extends ConsumerStatefulWidget {
  const RequestsScreen({super.key});

  @override
  ConsumerState<RequestsScreen> createState() => _RequestsScreenState();
}

class _RequestsScreenState extends ConsumerState<RequestsScreen> with SingleTickerProviderStateMixin {
  late TabController _tabController;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    Future.microtask(() {
      ref.read(requestsProvider.notifier).loadInitialData();
    });
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final requestsState = ref.watch(requestsProvider);

    return Scaffold(
      backgroundColor: AppTheme.lightAqua,
      appBar: PortalHeader(
        title: 'Requests & Claims',
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
              ref.read(requestsProvider.notifier).loadInitialData();
            },
          ),
        ),
        bottom: TabBar(
          controller: _tabController,
          labelColor: AppTheme.champagneGold,
          unselectedLabelColor: Colors.white70,
          indicatorColor: AppTheme.champagneGold,
          indicatorWeight: 3,
          labelStyle: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5),
          unselectedLabelStyle: const TextStyle(fontWeight: FontWeight.normal, fontSize: 13.5),
          tabs: const [
            Tab(text: 'New Claim', icon: Icon(Icons.edit_calendar_rounded, size: 18)),
            Tab(text: 'Submissions', icon: Icon(Icons.history_rounded, size: 18)),
          ],
        ),
      ),
      bottomNavigationBar: const PortalBottomNavBar(currentTab: NavTab.requests),
      body: requestsState.isLoading
          ? const Center(child: CircularProgressIndicator())
          : TabBarView(
              controller: _tabController,
              children: [
                AttendanceClaimForm(onSuccess: () {
                  _tabController.animateTo(1);
                }),
                _buildMySubmissionsList(requestsState),
              ],
            ),
    );
  }

  Widget _buildMySubmissionsList(RequestsState state) {
    final requests = state.requests;
    final claims = state.claims;

    if (requests.isEmpty && claims.isEmpty) {
      return RefreshIndicator(
        onRefresh: () async => ref.read(requestsProvider.notifier).loadInitialData(),
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(40.0),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.inbox, size: 48, color: Colors.grey[400]),
              const SizedBox(height: 12),
              const Text('No requests or claims submitted yet.', style: TextStyle(color: Colors.grey, fontWeight: FontWeight.bold)),
            ],
          ),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: () async => ref.read(requestsProvider.notifier).loadInitialData(),
      child: ListView(
        padding: const EdgeInsets.all(16.0),
        children: [
          if (requests.isNotEmpty) ...[
            const Text('LATE / HALF-DAY REQUESTS', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.grey, letterSpacing: 0.5)),
            const SizedBox(height: 8),
            ...requests.map((req) => _buildRequestCard(req)),
            const SizedBox(height: 16),
          ],
          if (claims.isNotEmpty) ...[
            const Text('ATTENDANCE CLAIMS', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.grey, letterSpacing: 0.5)),
            const SizedBox(height: 8),
            ...claims.map((clm) => _buildClaimCard(clm)),
          ],
        ],
      ),
    );
  }

  Widget _buildRequestCard(AttendanceRequestModel req) {
    return Card(
      color: Colors.white,
      margin: const EdgeInsets.only(bottom: 10),
      child: ListTile(
        onTap: () {
          showDialog(
            context: context,
            builder: (_) => RequestDetailDialog(request: req),
          );
        },
      title: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(req.displayTypeLabel, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
            decoration: BoxDecoration(color: req.statusBgColor, borderRadius: BorderRadius.circular(6)),
            child: Text(req.displayStatusLabel, style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: req.statusColor)),
          ),
        ],
      ),
      subtitle: Padding(
        padding: const EdgeInsets.only(top: 4.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Date: ${req.date} | Delay: ${req.delayMinutes} mins', style: TextStyle(fontSize: 11, color: Colors.grey[700])),
            if (req.status == 'explanation_requested')
              Padding(
                padding: const EdgeInsets.only(top: 4.0),
                child: Text('⚠️ Manager requested explanation — Tap to respond', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.orange[900])),
              ),
          ],
        ),
      ),
      trailing: const Icon(Icons.chevron_right, color: Colors.grey),
      ),
    );
  }

  Widget _buildClaimCard(AttendanceClaimModel clm) {
    return Card(
      color: Colors.white,
      margin: const EdgeInsets.only(bottom: 10),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10), side: BorderSide(color: Colors.grey[200]!)),
      child: ListTile(
        onTap: () {
          showDialog(
            context: context,
            builder: (_) => RequestDetailDialog(claim: clm),
          );
        },
        title: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(clm.displayTypeLabel, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              decoration: BoxDecoration(color: clm.statusBgColor, borderRadius: BorderRadius.circular(6)),
              child: Text(clm.displayStatusLabel, style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: clm.statusColor)),
            ),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4.0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Date: ${clm.date}', style: TextStyle(fontSize: 11, color: Colors.grey[700])),
              if (clm.status == 'explanation_requested')
                Padding(
                  padding: const EdgeInsets.only(top: 4.0),
                  child: Text('⚠️ Manager requested explanation — Tap to respond', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.orange[900])),
                ),
            ],
          ),
        ),
        trailing: const Icon(Icons.chevron_right, color: Colors.grey),
      ),
    );
  }
}
