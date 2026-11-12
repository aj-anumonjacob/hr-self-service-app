import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/config/theme.dart';
import '../providers/leave_provider.dart';
import '../models/leave_balance_model.dart';
import '../models/leave_request_model.dart';
import 'new_leave_request_dialog.dart';

class LeaveTabView extends ConsumerStatefulWidget {
  const LeaveTabView({super.key});

  @override
  ConsumerState<LeaveTabView> createState() => _LeaveTabViewState();
}

class _LeaveTabViewState extends ConsumerState<LeaveTabView> {
  int _selectedYear = DateTime.now().year;

  @override
  void initState() {
    super.initState();
    Future.microtask(() {
      ref.read(leaveProvider.notifier).loadData(year: _selectedYear);
    });
  }

  void _openNewLeaveRequestDialog() {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => const NewLeaveRequestDialog(),
    );
  }

  @override
  Widget build(BuildContext context) {
    final leaveState = ref.watch(leaveProvider);

    return RefreshIndicator(
      onRefresh: () async {
        await ref.read(leaveProvider.notifier).loadData(year: _selectedYear);
      },
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Top Year Filter Card
            Card(
              elevation: 0,
              color: Colors.white,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
                side: const BorderSide(color: Color(0xFFE2E8F0)),
              ),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16.0, vertical: 12.0),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text(
                      'Leave',
                      style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Color(0xFF1E293B)),
                    ),
                    Row(
                      children: [
                        const Text('Year ', style: TextStyle(fontSize: 13, color: Color(0xFF475569))),
                        const SizedBox(width: 4),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 0),
                          decoration: BoxDecoration(
                            border: Border.all(color: const Color(0xFFCBD5E1)),
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: DropdownButtonHideUnderline(
                            child: DropdownButton<int>(
                              value: _selectedYear,
                              isDense: true,
                              items: [2026, 2025, 2024].map((y) {
                                return DropdownMenuItem<int>(
                                  value: y,
                                  child: Text('$y', style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
                                );
                              }).toList(),
                              onChanged: (val) {
                                if (val != null) {
                                  setState(() {
                                    _selectedYear = val;
                                  });
                                }
                              },
                            ),
                          ),
                        ),
                        const SizedBox(width: 8),
                        ElevatedButton(
                          onPressed: () {
                            ref.read(leaveProvider.notifier).changeYear(_selectedYear);
                          },
                          style: ElevatedButton.styleFrom(
                            backgroundColor: Colors.white,
                            foregroundColor: const Color(0xFF2563EB),
                            side: const BorderSide(color: Color(0xFF2563EB)),
                            elevation: 0,
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                          ),
                          child: const Text('Apply', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),

            // Balances Section Card
            Card(
              elevation: 0,
              color: Colors.white,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
                side: const BorderSide(color: Color(0xFFE2E8F0)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.all(16.0),
                    child: Text(
                      'Balances ($_selectedYear)',
                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: Color(0xFF0F172A)),
                    ),
                  ),
                  const Divider(height: 1, color: Color(0xFFF1F5F9)),

                  if (leaveState.isLoading)
                    const Padding(
                      padding: EdgeInsets.all(24.0),
                      child: Center(child: CircularProgressIndicator()),
                    )
                  else if (leaveState.balances.isEmpty)
                    const Padding(
                      padding: EdgeInsets.all(24.0),
                      child: Center(child: Text('No balance records available.', style: TextStyle(color: Colors.grey))),
                    )
                  else
                    SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: DataTable(
                        columnSpacing: 22,
                        headingRowColor: WidgetStateProperty.all(const Color(0xFFF8FAFC)),
                        columns: const [
                          DataColumn(label: Text('TYPE', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF475569)))),
                          DataColumn(label: Text('OPENING', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF475569)))),
                          DataColumn(label: Text('ACCRUED', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF475569)))),
                          DataColumn(label: Text('TAKEN', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF475569)))),
                          DataColumn(label: Text('CARRIED', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF475569)))),
                          DataColumn(label: Text('CLOSING', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF475569)))),
                        ],
                        rows: leaveState.balances.map((b) => _buildBalanceRow(b)).toList(),
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // Recent Requests Section Card
            Card(
              elevation: 0,
              color: Colors.white,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
                side: const BorderSide(color: Color(0xFFE2E8F0)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16.0, vertical: 12.0),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Recent Requests',
                          style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: Color(0xFF0F172A)),
                        ),
                        Container(
                          decoration: BoxDecoration(
                            gradient: const LinearGradient(
                              colors: [Color(0xFF0F8790), Color(0xFF08606D)],
                              begin: Alignment.topCenter,
                              end: Alignment.bottomCenter,
                            ),
                            borderRadius: BorderRadius.circular(10),
                            boxShadow: const [
                              BoxShadow(
                                color: Color(0x2008737A),
                                blurRadius: 8,
                                offset: Offset(0, 3),
                              ),
                            ],
                          ),
                          child: Material(
                            color: Colors.transparent,
                            child: InkWell(
                              onTap: _openNewLeaveRequestDialog,
                              borderRadius: BorderRadius.circular(10),
                              child: const Padding(
                                padding: EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Icon(Icons.add_rounded, color: Colors.white, size: 16),
                                    SizedBox(width: 6),
                                    Text(
                                      'New Leave Request',
                                      style: TextStyle(
                                        color: Colors.white,
                                        fontSize: 12,
                                        fontWeight: FontWeight.bold,
                                        letterSpacing: 0.3,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const Divider(height: 1, color: Color(0xFFF1F5F9)),

                  if (leaveState.isLoading)
                    const Padding(
                      padding: EdgeInsets.all(24.0),
                      child: Center(child: CircularProgressIndicator()),
                    )
                  else if (leaveState.requests.isEmpty)
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.symmetric(vertical: 36.0),
                      color: const Color(0xFFF8FAFC),
                      child: const Column(
                        children: [
                          Icon(Icons.event_busy, size: 36, color: Colors.grey),
                          SizedBox(height: 8),
                          Text('No leave requests.', style: TextStyle(color: Color(0xFF64748B), fontWeight: FontWeight.w500)),
                        ],
                      ),
                    )
                  else
                    ListView.separated(
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      itemCount: leaveState.requests.length,
                      separatorBuilder: (_, _) => const Divider(height: 1, color: Color(0xFFF1F5F9)),
                      itemBuilder: (context, index) {
                        final req = leaveState.requests[index];
                        return _buildRequestTile(req, index + 1);
                      },
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  DataRow _buildBalanceRow(LeaveBalanceModel b) {
    return DataRow(
      cells: [
        DataCell(
          Text(
            b.typeName,
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Color(0xFF0F172A)),
          ),
        ),
        DataCell(Text(b.opening.toStringAsFixed(2), style: const TextStyle(fontSize: 13))),
        DataCell(Text(b.accrued.toStringAsFixed(2), style: const TextStyle(fontSize: 13))),
        DataCell(Text(b.taken.toStringAsFixed(2), style: const TextStyle(fontSize: 13))),
        DataCell(Text(b.carried.toStringAsFixed(2), style: const TextStyle(fontSize: 13))),
        DataCell(
          Text(
            b.closing.toStringAsFixed(2),
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Color(0xFF0F172A)),
          ),
        ),
      ],
    );
  }

  Widget _buildRequestTile(LeaveRequestModel req, int index) {
    return ListTile(
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      title: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Expanded(
            child: Text(
              '#$index ${req.leaveTypeName}',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Color(0xFF0F172A)),
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
            decoration: BoxDecoration(
              color: req.statusBgColor,
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text(
              req.displayStatusLabel,
              style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: req.statusColor),
            ),
          ),
        ],
      ),
      subtitle: Padding(
        padding: const EdgeInsets.only(top: 4.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Dates: ${req.dateFrom} → ${req.dateTo} (${req.days.toStringAsFixed(0)} ${req.days == 1 ? 'day' : 'days'})',
              style: const TextStyle(fontSize: 12, color: Color(0xFF475569)),
            ),
            if (req.reason != null && req.reason!.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 2.0),
                child: Text(
                  'Reason: ${req.reason}',
                  style: const TextStyle(fontSize: 12, color: Color(0xFF64748B), fontStyle: FontStyle.italic),
                ),
              ),
            if (req.attachmentUrl != null)
              Padding(
                padding: const EdgeInsets.only(top: 4.0),
                child: Row(
                  children: [
                    const Icon(Icons.attach_file, size: 14, color: AppTheme.darkGold),
                    const SizedBox(width: 4),
                    Text(
                      'Attachment uploaded',
                      style: TextStyle(fontSize: 11, color: Colors.blue[800], fontWeight: FontWeight.w600),
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
