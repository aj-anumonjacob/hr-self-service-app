import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
import '../../../core/config/theme.dart';
import '../models/attendance_request_model.dart';
import '../models/attendance_claim_model.dart';
import '../providers/requests_provider.dart';

class RequestDetailDialog extends ConsumerStatefulWidget {
  final AttendanceRequestModel? request;
  final AttendanceClaimModel? claim;

  const RequestDetailDialog({
    super.key,
    this.request,
    this.claim,
  });

  @override
  ConsumerState<RequestDetailDialog> createState() => _RequestDetailDialogState();
}

class _RequestDetailDialogState extends ConsumerState<RequestDetailDialog> {
  final TextEditingController _responseController = TextEditingController();
  bool _isSubmitting = false;

  @override
  void dispose() {
    _responseController.dispose();
    super.dispose();
  }

  String _formatTimeStr(String? isoStr) {
    if (isoStr == null || isoStr.trim().isEmpty) return 'N/A';
    try {
      String str = isoStr.trim();
      if (str.contains('T')) {
        str = str.split('T')[1];
      } else if (str.contains(' ')) {
        str = str.split(' ')[1];
      }
      str = str.split('+')[0].replaceAll('Z', '').trim();
      final parts = str.split(':');
      if (parts.length >= 2) {
        final hr = int.tryParse(parts[0]) ?? 0;
        final min = int.tryParse(parts[1]) ?? 0;
        final tempDt = DateTime(2026, 1, 1, hr, min);
        return DateFormat('hh:mm a').format(tempDt);
      }
    } catch (_) {}
    return isoStr;
  }

  @override
  Widget build(BuildContext context) {
    final req = widget.request;
    final clm = widget.claim;

    final isRequest = req != null;
    final title = isRequest ? req.displayTypeLabel : clm!.displayTypeLabel;
    final date = isRequest ? req.date : clm!.date;
    final status = isRequest ? req.status : clm!.status;
    final statusLabel = isRequest ? req.displayStatusLabel : clm!.displayStatusLabel;
    final statusColor = isRequest ? req.statusColor : clm!.statusColor;
    final statusBgColor = isRequest ? req.statusBgColor : clm!.statusBgColor;
    final reasonCode = isRequest ? req.reasonCode : clm!.reasonCode;
    final description = isRequest ? req.description : clm!.description;
    final managerComment = isRequest ? req.managerComment : clm!.managerComment;
    final explanationRequest = isRequest ? req.explanationRequest : clm!.explanationRequest;
    final employeeResponse = isRequest ? req.employeeResponse : clm!.employeeResponse;
    final actions = isRequest ? req.actions : clm!.actions;
    final uuid = isRequest ? req.uuid : clm!.uuid;

    final needsExplanation = status == 'explanation_requested';

    return Dialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            // Title & Status Badge Header
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Text(
                    title,
                    style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: AppTheme.charcoal),
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(color: statusBgColor, borderRadius: BorderRadius.circular(8)),
                  child: Text(
                    statusLabel,
                    style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: statusColor),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Text('Date: $date', style: TextStyle(fontSize: 12, color: Colors.grey[700])),
            const Divider(height: 20),

            // Detail Fields
            if (isRequest) ...[
              if (req.requestType == 'late_arrival') ...[
                _infoRow('Delay Duration:', '${req.delayMinutes} mins'),
                if (req.expectedArrivalAt != null)
                  _infoRow('Expected Arrival:', _formatTimeStr(req.expectedArrivalAt)),
              ] else ...[
                _infoRow('Request Segment:', 'Morning Half-Day'),
              ],
            ] else ...[
              if (clm!.requestedCheckInAt != null)
                _infoRow('Requested Check-In:', _formatTimeStr(clm.requestedCheckInAt)),
              if (clm.requestedCheckOutAt != null)
                _infoRow('Requested Check-Out:', _formatTimeStr(clm.requestedCheckOutAt)),
            ],

            _infoRow('Reason Code:', ucwords(reasonCode)),
            const SizedBox(height: 8),

            if (description != null && description.isNotEmpty) ...[
              const Text('Employee Note:', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.grey)),
              const SizedBox(height: 4),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: Colors.grey[100], borderRadius: BorderRadius.circular(6)),
                child: Text(description, style: const TextStyle(fontSize: 12)),
              ),
              const SizedBox(height: 12),
            ],

            if (explanationRequest != null && explanationRequest.isNotEmpty) ...[
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: Colors.orange[50], borderRadius: BorderRadius.circular(8), border: Border.all(color: Colors.orange[300]!)),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Icon(Icons.warning_amber_rounded, color: Colors.orange[900], size: 16),
                        const SizedBox(width: 6),
                        Text('Manager Explanation Request:', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.orange[900])),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Text(explanationRequest, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w500)),
                  ],
                ),
              ),
              const SizedBox(height: 12),
            ],

            if (employeeResponse != null && employeeResponse.isNotEmpty) ...[
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: Colors.purple[50], borderRadius: BorderRadius.circular(8), border: Border.all(color: Colors.purple[200]!)),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Your Acknowledgment Response:', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.purple)),
                    const SizedBox(height: 4),
                    Text(employeeResponse, style: const TextStyle(fontSize: 12)),
                  ],
                ),
              ),
              const SizedBox(height: 12),
            ],

            if (managerComment != null && managerComment.isNotEmpty && !needsExplanation) ...[
              const Text('Manager Note:', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.grey)),
              const SizedBox(height: 4),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: Colors.blue[50], borderRadius: BorderRadius.circular(6)),
                child: Text(managerComment, style: const TextStyle(fontSize: 12)),
              ),
              const SizedBox(height: 12),
            ],

            // ── ACKNOWLEDGE & RESPOND WORKFLOW ──────────────────────────────
            if (needsExplanation) ...[
              const Text('Acknowledge & Respond to Manager:', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppTheme.charcoal)),
              const SizedBox(height: 6),
              TextFormField(
                controller: _responseController,
                maxLines: 3,
                maxLength: 500,
                decoration: const InputDecoration(
                  hintText: 'Enter your response or explanation for the manager...',
                  alignLabelWithHint: true,
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 10),
              ElevatedButton.icon(
                onPressed: _isSubmitting
                    ? null
                    : () async {
                        final text = _responseController.text.trim();
                        if (text.isEmpty) {
                          ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Please enter a response note.')));
                          return;
                        }
                        setState(() => _isSubmitting = true);

                        final bool ok = isRequest
                            ? await ref.read(requestsProvider.notifier).acknowledgeAndRespondRequest(uuid, text)
                            : await ref.read(requestsProvider.notifier).acknowledgeAndRespondClaim(uuid, text);

                        if (mounted) {
                          setState(() => _isSubmitting = false);
                          if (ok) {
                            Navigator.of(context).pop();
                            ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Response submitted to manager.')));
                          }
                        }
                      },
                icon: _isSubmitting
                    ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : const Icon(Icons.check_circle_outline, color: Colors.white),
                label: const Text('ACKNOWLEDGE & RESPOND', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.orange[800],
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
              ),
              const SizedBox(height: 12),
            ],

            // Action History Audit Log
            if (actions.isNotEmpty) ...[
              const Text('ACTION HISTORY', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.grey, letterSpacing: 0.5)),
              const SizedBox(height: 6),
              ...actions.map((act) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 3),
                child: Row(
                  children: [
                    const Icon(Icons.history, size: 14, color: Colors.grey),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text(
                        '${act.actorRole.toUpperCase()}: ${act.newStatus} ${act.comment != null ? "(${act.comment})" : ""}',
                        style: const TextStyle(fontSize: 11, color: Colors.black87),
                      ),
                    ),
                  ],
                ),
              )),
              const SizedBox(height: 12),
            ],

            Align(
              alignment: Alignment.centerRight,
              child: TextButton(
                onPressed: () => Navigator.of(context).pop(),
                child: const Text('CLOSE'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _infoRow(String label, String val) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3.0),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(fontSize: 12, color: Colors.grey)),
          Text(val, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppTheme.charcoal)),
        ],
      ),
    );
  }

  String ucwords(String str) {
    return str.split('_').map((word) => word.isNotEmpty ? '${word[0].toUpperCase()}${word.substring(1)}' : '').join(' ');
  }
}
