import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../attendance/providers/attendance_provider.dart';
import '../../core/config/theme.dart';

class BreakPolicyManagementScreen extends ConsumerStatefulWidget {
  const BreakPolicyManagementScreen({super.key});

  @override
  ConsumerState<BreakPolicyManagementScreen> createState() => _BreakPolicyManagementScreenState();
}

class _BreakPolicyManagementScreenState extends ConsumerState<BreakPolicyManagementScreen> with SingleTickerProviderStateMixin {
  late TabController _tabController;
  bool _isLoading = false;
  List<dynamic> _overdueBreaks = [];
  List<dynamic> _policies = [];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _fetchData();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _fetchData() async {
    setState(() {
      _isLoading = true;
    });

    try {
      final repo = ref.read(attendanceRepositoryProvider);
      final overdue = await repo.getActiveOverdueBreaks();
      final policies = await repo.getBreakPolicies();

      setState(() {
        _overdueBreaks = overdue;
        _policies = policies;
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _isLoading = false;
      });
    }
  }

  void _showCreatePolicyDialog() {
    final nameCtrl = TextEditingController(text: 'Custom Policy');
    final durationCtrl = TextEditingController(text: '60');
    final maxBreaksCtrl = TextEditingController(text: '1');
    String type = 'flexible';
    bool isPaid = false;
    bool requiresApproval = false;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text('Create Break Policy', style: TextStyle(fontWeight: FontWeight.bold)),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                TextField(
                  controller: nameCtrl,
                  decoration: const InputDecoration(labelText: 'Policy Name'),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: type,
                  decoration: const InputDecoration(labelText: 'Policy Type'),
                  items: const [
                    DropdownMenuItem(value: 'flexible', child: Text('Flexible (Anytime)')),
                    DropdownMenuItem(value: 'fixed', child: Text('Fixed Time')),
                    DropdownMenuItem(value: 'window', child: Text('Window Period')),
                  ],
                  onChanged: (val) => setDialogState(() => type = val!),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: durationCtrl,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(labelText: 'Allowed Duration (Minutes)'),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: maxBreaksCtrl,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(labelText: 'Max Breaks per Shift'),
                ),
                const SizedBox(height: 12),
                SwitchListTile(
                  title: const Text('Paid Break'),
                  value: isPaid,
                  onChanged: (val) => setDialogState(() => isPaid = val),
                ),
                SwitchListTile(
                  title: const Text('Requires Manager Approval'),
                  value: requiresApproval,
                  onChanged: (val) => setDialogState(() => requiresApproval = val),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Cancel'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: AppTheme.darkGold),
              onPressed: () async {
                try {
                  final repo = ref.read(attendanceRepositoryProvider);
                  await repo.createBreakPolicy({
                    'name': nameCtrl.text.trim(),
                    'policy_type': type,
                    'allowed_duration_minutes': int.tryParse(durationCtrl.text) ?? 60,
                    'max_breaks_per_shift': int.tryParse(maxBreaksCtrl.text) ?? 1,
                    'is_paid': isPaid,
                    'requires_approval': requiresApproval,
                  });
                  if (ctx.mounted) Navigator.pop(ctx);
                  _fetchData();
                } catch (e) {
                  if (ctx.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text('Error: $e'), backgroundColor: Colors.red),
                    );
                  }
                }
              },
              child: const Text('Create Policy', style: TextStyle(color: Colors.white)),
            ),
          ],
        ),
      ),
    );
  }

  void _showCorrectBreakDialog(Map<String, dynamic> breakItem) {
    final reasonCtrl = TextEditingController();
    String action = 'close';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: Text('Correct Break: ${breakItem['employee_name']}'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Employee is ${breakItem['overdue_minutes']} mins overdue. Overdue notification level: ${breakItem['escalation_level']}.',
                  style: const TextStyle(fontSize: 12, color: Colors.grey),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: action,
                  decoration: const InputDecoration(labelText: 'Manager Action'),
                  items: const [
                    DropdownMenuItem(value: 'close', child: Text('Close Break (Mark Completed)')),
                    DropdownMenuItem(value: 'cancel', child: Text('Cancel Break')),
                  ],
                  onChanged: (val) => setDialogState(() => action = val!),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: reasonCtrl,
                  maxLines: 3,
                  decoration: const InputDecoration(
                    labelText: 'Mandatory Reason / Notes',
                    hintText: 'Enter reason for manual manager closure...',
                    border: OutlineInputBorder(),
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Cancel'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: Colors.red[700]),
              onPressed: () async {
                if (reasonCtrl.text.trim().length < 5) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Please enter a reason of at least 5 characters.'), backgroundColor: Colors.orange),
                  );
                  return;
                }
                try {
                  final repo = ref.read(attendanceRepositoryProvider);
                  await repo.managerCorrectBreak(
                    breakItem['uuid'].toString(),
                    action,
                    reasonCtrl.text.trim(),
                  );
                  if (ctx.mounted) Navigator.pop(ctx);
                  _fetchData();
                  if (ctx.mounted) {
                    ScaffoldMessenger.of(ctx).showSnackBar(
                      const SnackBar(content: Text('Break corrected successfully.'), backgroundColor: Colors.green),
                    );
                  }
                } catch (e) {
                  if (ctx.mounted) {
                    ScaffoldMessenger.of(ctx).showSnackBar(
                      SnackBar(content: Text('Error: $e'), backgroundColor: Colors.red),
                    );
                  }
                }
              },
              child: const Text('Apply Correction', style: TextStyle(color: Colors.white)),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Break Policy & Monitor'),
        backgroundColor: AppTheme.charcoal,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_ios_new_rounded, color: Colors.white, size: 20),
          tooltip: 'Back',
          onPressed: () {
            if (context.canPop()) {
              context.pop();
            } else {
              context.go('/manager-dashboard');
            }
          },
        ),
        bottom: TabBar(
          controller: _tabController,
          indicatorColor: AppTheme.darkGold,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.grey[400],
          tabs: const [
            Tab(icon: Icon(Icons.warning_amber_rounded), text: 'Active / Overdue Breaks'),
            Tab(icon: Icon(Icons.policy), text: 'Break Policies'),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh, color: Colors.white),
            onPressed: _fetchData,
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: AppTheme.darkGold))
          : TabBarView(
              controller: _tabController,
              children: [
                // Tab 1: Active / Overdue Breaks Monitor
                RefreshIndicator(
                  onRefresh: _fetchData,
                  child: _overdueBreaks.isEmpty
                      ? const Center(
                          child: Text(
                            'No active employee breaks found.',
                            style: TextStyle(color: Colors.grey, fontSize: 14),
                          ),
                        )
                      : ListView.builder(
                          padding: const EdgeInsets.all(16),
                          itemCount: _overdueBreaks.length,
                          itemBuilder: (context, index) {
                            final b = _overdueBreaks[index] as Map<String, dynamic>;
                            final isOverdue = b['is_overdue'] as bool? ?? false;
                            final overdueMins = b['overdue_minutes'] as int? ?? 0;
                            final elapsedMins = b['elapsed_minutes'] as int? ?? 0;

                            return Card(
                              elevation: isOverdue ? 2 : 0,
                              margin: const EdgeInsets.only(bottom: 12),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(10),
                                side: BorderSide(
                                  color: isOverdue ? Colors.red[300]! : Colors.grey[200]!,
                                  width: isOverdue ? 1.5 : 1.0,
                                ),
                              ),
                              child: Padding(
                                padding: const EdgeInsets.all(14),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        Text(
                                          b['employee_name'] ?? 'Employee',
                                          style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppTheme.charcoal),
                                        ),
                                        Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                                          decoration: BoxDecoration(
                                            color: isOverdue ? Colors.red.withAlpha(30) : Colors.orange.withAlpha(30),
                                            borderRadius: BorderRadius.circular(6),
                                          ),
                                          child: Text(
                                            isOverdue ? 'OVERDUE ($overdueMins m)' : 'Active Break',
                                            style: TextStyle(
                                              fontSize: 10,
                                              fontWeight: FontWeight.bold,
                                              color: isOverdue ? Colors.red[900] : Colors.orange[900],
                                            ),
                                          ),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 4),
                                    Text(
                                      'Dept: ${b['department_name']} | Policy: ${b['policy_name']}',
                                      style: const TextStyle(fontSize: 11, color: Colors.grey),
                                    ),
                                    const SizedBox(height: 8),
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        Text('Elapsed: $elapsedMins mins', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
                                        Text('Escalation Count: ${b['notification_count']}', style: const TextStyle(fontSize: 12, color: Colors.grey)),
                                      ],
                                    ),
                                    const SizedBox(height: 10),
                                    Align(
                                      alignment: Alignment.centerRight,
                                      child: OutlinedButton.icon(
                                        onPressed: () => _showCorrectBreakDialog(b),
                                        icon: const Icon(Icons.edit_note, size: 18),
                                        label: const Text('Manager Correction'),
                                        style: OutlinedButton.styleFrom(
                                          foregroundColor: Colors.red[700],
                                          side: BorderSide(color: Colors.red[300]!),
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            );
                          },
                        ),
                ),

                // Tab 2: Break Policies List & Creation
                RefreshIndicator(
                  onRefresh: _fetchData,
                  child: SingleChildScrollView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        ElevatedButton.icon(
                          onPressed: _showCreatePolicyDialog,
                          icon: const Icon(Icons.add, color: Colors.white),
                          label: const Text('CREATE NEW BREAK POLICY', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: AppTheme.darkGold,
                            padding: const EdgeInsets.symmetric(vertical: 14),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                          ),
                        ),
                        const SizedBox(height: 16),
                        ..._policies.map((p) {
                          final pol = p as Map<String, dynamic>;
                          return Card(
                            margin: const EdgeInsets.only(bottom: 12),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(10),
                              side: BorderSide(color: Colors.grey[200]!),
                            ),
                            child: ListTile(
                              title: Text(pol['name'] ?? 'Policy', style: const TextStyle(fontWeight: FontWeight.bold)),
                              subtitle: Text(
                                'Type: ${pol['policy_type'].toString().toUpperCase()} | Allowed: ${pol['allowed_duration_minutes']}m | Max: ${pol['max_breaks_per_shift']} break(s)',
                                style: const TextStyle(fontSize: 11),
                              ),
                              trailing: Chip(
                                label: Text(
                                  pol['is_active'] == true ? 'Active' : 'Inactive',
                                  style: const TextStyle(fontSize: 10, color: Colors.white),
                                ),
                                backgroundColor: pol['is_active'] == true ? Colors.green : Colors.grey,
                              ),
                            ),
                          );
                        }),
                      ],
                    ),
                  ),
                ),
              ],
            ),
    );
  }
}
