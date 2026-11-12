import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
import '../../../core/config/theme.dart';
import '../models/missed_attendance_model.dart';
import '../providers/missed_attendance_provider.dart';
import '../../requests/providers/requests_provider.dart';

/// Shown on the dashboard, right below the employee details card, whenever
/// the employee has a recent day where they checked in but never checked
/// out. Each item can be "Verified" — which opens the details and offers a
/// one-tap quick-claim so the employee doesn't have to navigate to the full
/// Requests screen and fill the long form.
class MissedAttendanceCard extends ConsumerWidget {
  const MissedAttendanceCard({super.key});

  String _formatDate(String isoDate) {
    try {
      return DateFormat('EEE, MMM d').format(DateTime.parse(isoDate));
    } catch (_) {
      return isoDate;
    }
  }

  String _formatTime(String? isoDateTime) {
    if (isoDateTime == null || isoDateTime.isEmpty) return '—';
    try {
      return DateFormat('hh:mm a').format(DateTime.parse(isoDateTime.replaceFirst(' ', 'T')));
    } catch (_) {
      return isoDateTime;
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final missedAsync = ref.watch(missedAttendanceProvider);

    return missedAsync.when(
      loading: () => const SizedBox.shrink(),
      error: (_, __) => const SizedBox.shrink(),
      data: (items) {
        if (items.isEmpty) return const SizedBox.shrink();

        return Container(
          margin: const EdgeInsets.only(top: 10),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: Colors.orange.withValues(alpha: 0.35)),
            boxShadow: const [
              BoxShadow(color: Color(0x14073B4C), blurRadius: 10, offset: Offset(0, 4)),
            ],
          ),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Icon(Icons.warning_amber_rounded, color: Colors.orange[800], size: 20),
                    const SizedBox(width: 8),
                    const Expanded(
                      child: Text(
                        'ATTENDANCE ALERTS',
                        style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, letterSpacing: 0.5, color: Color(0xFF7C2D12)),
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                      decoration: BoxDecoration(color: Colors.orange[100], borderRadius: BorderRadius.circular(8)),
                      child: Text('${items.length}', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.orange[900])),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                for (final item in items) ...[
                  Container(
                    margin: const EdgeInsets.only(top: 6),
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFFF7ED),
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: Colors.orange.withValues(alpha: 0.2)),
                    ),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '${item.displayLabel} — ${_formatDate(item.date)}',
                                style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold, color: Color(0xFF7C2D12)),
                              ),
                              const SizedBox(height: 2),
                              Text(
                                'Checked in ${_formatTime(item.recordedCheckInAt)}, no checkout recorded.',
                                style: const TextStyle(fontSize: 11, color: Color(0xFF9A3412)),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 8),
                        ElevatedButton(
                          onPressed: () => _showVerifySheet(context, ref, item),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: AppTheme.primaryTeal,
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                            minimumSize: Size.zero,
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                          ),
                          child: const Text('Verify', style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold)),
                        ),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
        );
      },
    );
  }

  void _showVerifySheet(BuildContext context, WidgetRef ref, MissedAttendanceModel item) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (sheetContext) => _VerifyMissedAttendanceSheet(item: item),
    );
  }
}

class _VerifyMissedAttendanceSheet extends ConsumerStatefulWidget {
  final MissedAttendanceModel item;
  const _VerifyMissedAttendanceSheet({required this.item});

  @override
  ConsumerState<_VerifyMissedAttendanceSheet> createState() => _VerifyMissedAttendanceSheetState();
}

class _VerifyMissedAttendanceSheetState extends ConsumerState<_VerifyMissedAttendanceSheet> {
  final _descriptionController = TextEditingController(text: 'Forgot to check out at the end of my shift.');
  TimeOfDay? _requestedCheckOutTime;
  bool _submitting = false;

  @override
  void dispose() {
    _descriptionController.dispose();
    super.dispose();
  }

  Future<void> _pickTime() async {
    final picked = await showTimePicker(
      context: context,
      initialTime: _requestedCheckOutTime ?? TimeOfDay.now(),
    );
    if (picked != null && mounted) {
      setState(() => _requestedCheckOutTime = picked);
    }
  }

  Future<void> _submit() async {
    setState(() => _submitting = true);

    String? requestedCheckOutAt;
    if (_requestedCheckOutTime != null) {
      final hh = _requestedCheckOutTime!.hour.toString().padLeft(2, '0');
      final mm = _requestedCheckOutTime!.minute.toString().padLeft(2, '0');
      requestedCheckOutAt = '${widget.item.date} $hh:$mm:00';
    }

    final success = await ref.read(requestsProvider.notifier).submitClaim(
          claimType: 'missing_check_out',
          date: widget.item.date,
          attendanceRecordId: widget.item.attendanceRecordId,
          requestedCheckOutAt: requestedCheckOutAt,
          reasonCode: 'forgot_check_out',
          description: _descriptionController.text.trim().isEmpty
              ? 'Forgot to check out at the end of my shift.'
              : _descriptionController.text.trim(),
        );

    if (!mounted) return;
    setState(() => _submitting = false);

    if (success) {
      ref.invalidate(missedAttendanceProvider);
      Navigator.of(context).pop();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Quick claim submitted. HR/your manager will review it.'),
          backgroundColor: Color(0xFF0F5A6E),
        ),
      );
    } else {
      final error = ref.read(requestsProvider).error;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(error ?? 'Could not submit the claim. Please try again.'), backgroundColor: Colors.red[600]),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        top: 20,
        bottom: MediaQuery.of(context).viewInsets.bottom + 20,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(color: Colors.grey[300], borderRadius: BorderRadius.circular(2)),
            ),
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: Colors.orange[50], shape: BoxShape.circle),
                child: Icon(Icons.warning_amber_rounded, color: Colors.orange[800]),
              ),
              const SizedBox(width: 12),
              const Expanded(
                child: Text('Verify Missed Check-Out', style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold, color: Color(0xFF0F172A))),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: const Color(0xFFF8FAFC), borderRadius: BorderRadius.circular(10)),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _detailRow('Date', widget.item.date),
                _detailRow('Recorded Check-In', widget.item.recordedCheckInAt ?? '—'),
                _detailRow('Recorded Check-Out', 'Not recorded'),
              ],
            ),
          ),
          const SizedBox(height: 16),
          const Text('Requested Check-Out Time (optional)', style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: Color(0xFF475569))),
          const SizedBox(height: 6),
          InkWell(
            onTap: _pickTime,
            borderRadius: BorderRadius.circular(10),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              decoration: BoxDecoration(border: Border.all(color: const Color(0xFFE2E8F0)), borderRadius: BorderRadius.circular(10)),
              child: Row(
                children: [
                  const Icon(Icons.access_time_rounded, size: 18, color: AppTheme.primaryTeal),
                  const SizedBox(width: 10),
                  Text(
                    _requestedCheckOutTime != null ? _requestedCheckOutTime!.format(context) : 'Tap to select a time',
                    style: TextStyle(fontSize: 13.5, color: _requestedCheckOutTime != null ? const Color(0xFF0F172A) : Colors.grey[500]),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          const Text('Description', style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: Color(0xFF475569))),
          const SizedBox(height: 6),
          TextField(
            controller: _descriptionController,
            maxLines: 3,
            decoration: InputDecoration(
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
              contentPadding: const EdgeInsets.all(12),
            ),
          ),
          const SizedBox(height: 20),
          SizedBox(
            height: 50,
            child: ElevatedButton(
              onPressed: _submitting ? null : _submit,
              style: ElevatedButton.styleFrom(
                backgroundColor: AppTheme.primaryTeal,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              ),
              child: _submitting
                  ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2.5))
                  : const Text('Submit Quick Claim', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 15)),
            ),
          ),
        ],
      ),
    );
  }

  Widget _detailRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(fontSize: 12, color: Color(0xFF64748B))),
          Text(value, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xFF0F172A))),
        ],
      ),
    );
  }
}
