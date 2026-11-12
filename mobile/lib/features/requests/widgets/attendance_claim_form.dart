import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
import '../../../core/config/theme.dart';
import '../providers/requests_provider.dart';
import '../models/attendance_policy_setting_model.dart';

class AttendanceClaimForm extends ConsumerStatefulWidget {
  final VoidCallback onSuccess;

  const AttendanceClaimForm({super.key, required this.onSuccess});

  @override
  ConsumerState<AttendanceClaimForm> createState() => _AttendanceClaimFormState();
}

class _AttendanceClaimFormState extends ConsumerState<AttendanceClaimForm> {
  final _formKey = GlobalKey<FormState>();

  String _claimType = 'missing_check_out';
  DateTime _selectedDate = DateTime.now();
  TimeOfDay? _requestedTime;
  String? _selectedReason;
  final TextEditingController _descriptionController = TextEditingController();

  int _charCount = 0;
  static const int _maxChars = 500;

  @override
  void initState() {
    super.initState();
    _descriptionController.addListener(() {
      setState(() {
        _charCount = _descriptionController.text.length;
      });
    });
  }

  @override
  void dispose() {
    _descriptionController.dispose();
    super.dispose();
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate,
      firstDate: DateTime.now().subtract(const Duration(days: 30)),
      lastDate: DateTime.now(),
    );
    if (picked != null) {
      setState(() => _selectedDate = picked);
    }
  }

  Future<void> _pickTime() async {
    final picked = await showTimePicker(
      context: context,
      initialTime: _requestedTime ?? const TimeOfDay(hour: 18, minute: 0),
    );
    if (picked != null) {
      setState(() => _requestedTime = picked);
    }
  }

  InputDecoration _customInputDecoration({required String labelText, IconData? prefixIcon, String? helperText, String? counterText}) {
    return InputDecoration(
      labelText: labelText,
      helperText: helperText,
      counterText: counterText,
      counterStyle: TextStyle(
        color: _charCount > _maxChars ? Colors.red : Colors.grey[600],
        fontWeight: FontWeight.bold,
        fontSize: 11,
      ),
      prefixIcon: prefixIcon != null ? Icon(prefixIcon, color: AppTheme.darkGold, size: 20) : null,
      filled: true,
      fillColor: const Color(0xFFF9FAFB),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: Color(0xFFE5E7EB)),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: Color(0xFFE5E7EB)),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: AppTheme.darkGold, width: 1.5),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final requestsState = ref.watch(requestsProvider);
    final settings = requestsState.settings;
    final claimReasons = (settings?.claimReasonOptions != null && settings!.claimReasonOptions.isNotEmpty)
        ? settings.claimReasonOptions
        : [
            ReasonOption(code: 'forgot_check_out', label: 'Forgot to Check-Out'),
            ReasonOption(code: 'forgot_check_in', label: 'Forgot to Check-In'),
            ReasonOption(code: 'app_issue', label: 'Mobile Application Issue'),
            ReasonOption(code: 'location_verification_issue', label: 'Location Verification Issue'),
            ReasonOption(code: 'network_connectivity_issue', label: 'Network Connectivity Issue'),
            ReasonOption(code: 'device_battery_failure', label: 'Device Battery Failure'),
            ReasonOption(code: 'wrong_attendance_action', label: 'Wrong Attendance Action Selected'),
            ReasonOption(code: 'emergency_situation', label: 'Emergency Situation'),
            ReasonOption(code: 'manager_instructed_schedule_change', label: 'Manager Instructed Schedule Change'),
            ReasonOption(code: 'auto_checkout_time_incorrect', label: 'Automatic Checkout Time Incorrect'),
            ReasonOption(code: 'other', label: 'Other Reason'),
          ];

    final claimTypes = [
      {'code': 'missing_check_out', 'label': 'Missing Check-Out'},
      {'code': 'missing_check_in', 'label': 'Missing Check-In'},
      {'code': 'incorrect_check_in', 'label': 'Incorrect Check-In Time'},
      {'code': 'incorrect_check_out', 'label': 'Incorrect Check-Out Time'},
      {'code': 'auto_checkout_correction', 'label': 'Auto Checkout Correction'},
      {'code': 'accidental_check_in', 'label': 'Accidental Check-In'},
      {'code': 'accidental_check_out', 'label': 'Accidental Check-Out'},
      {'code': 'other_attendance_issue', 'label': 'Other Attendance Issue'},
    ];

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16.0),
      child: Form(
        key: _formKey,
        child: Container(
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(16),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withOpacity(0.04),
                blurRadius: 10,
                offset: const Offset(0, 4),
              ),
            ],
          ),
          padding: const EdgeInsets.all(20.0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // Claim Type Dropdown (with isExpanded: true to prevent 90px overflow!)
              DropdownButtonFormField<String>(
                initialValue: _claimType,
                isExpanded: true,
                decoration: _customInputDecoration(
                  labelText: 'Claim Type',
                  prefixIcon: Icons.assignment_turned_in_rounded,
                ),
                items: claimTypes.map((t) => DropdownMenuItem(
                  value: t['code'],
                  child: Text(
                    t['label']!,
                    overflow: TextOverflow.ellipsis,
                  ),
                )).toList(),
                onChanged: (val) {
                  if (val != null) setState(() => _claimType = val);
                },
              ),
              const SizedBox(height: 20),

              // Date Picker
              InkWell(
                onTap: _pickDate,
                borderRadius: BorderRadius.circular(12),
                child: InputDecorator(
                  decoration: _customInputDecoration(
                    labelText: 'Attendance Date',
                    prefixIcon: Icons.calendar_today_rounded,
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        DateFormat('EEEE, MMM d, yyyy').format(_selectedDate),
                        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14, color: AppTheme.charcoal),
                      ),
                      const Icon(Icons.arrow_drop_down, color: Colors.grey),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 20),

              // Requested Correction Time Picker
              InkWell(
                onTap: _pickTime,
                borderRadius: BorderRadius.circular(12),
                child: InputDecorator(
                  decoration: _customInputDecoration(
                    labelText: 'Requested Correction Time',
                    prefixIcon: Icons.access_time_rounded,
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        _requestedTime != null ? _requestedTime!.format(context) : 'Select Time',
                        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14, color: AppTheme.charcoal),
                      ),
                      const Icon(Icons.arrow_drop_down, color: Colors.grey),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 20),

              // Reason Selector (with isExpanded: true!)
              DropdownButtonFormField<String>(
                initialValue: _selectedReason,
                isExpanded: true,
                decoration: _customInputDecoration(
                  labelText: 'Reason for Claim',
                  prefixIcon: Icons.help_outline_rounded,
                ),
                items: claimReasons.map((r) => DropdownMenuItem(
                  value: r.code,
                  child: Text(
                    r.label,
                    overflow: TextOverflow.ellipsis,
                  ),
                )).toList(),
                onChanged: (val) => setState(() => _selectedReason = val),
                validator: (val) => val == null ? 'Please select a reason' : null,
              ),
              const SizedBox(height: 20),

              // Description Field with Live 500-Character Counter
              TextFormField(
                controller: _descriptionController,
                maxLines: 4,
                maxLength: _maxChars,
                decoration: _customInputDecoration(
                  labelText: 'Description / Explanation',
                  prefixIcon: Icons.notes_rounded,
                  helperText: 'Provide professional details of the attendance issue.',
                  counterText: '$_charCount / $_maxChars characters remaining',
                ),
                validator: (val) {
                  if (val == null || val.trim().isEmpty) return 'Description is required';
                  if (val.length > _maxChars) return 'Exceeds $_maxChars character limit';
                  return null;
                },
              ),
              const SizedBox(height: 24),

              // Submit Button
              ElevatedButton.icon(
                onPressed: requestsState.isSubmitting
                    ? null
                    : () async {
                        if (!_formKey.currentState!.validate()) return;

                        final dateStr = DateFormat('yyyy-MM-dd').format(_selectedDate);
                        String? reqTimeIso;
                        if (_requestedTime != null) {
                          final dt = DateTime(
                            _selectedDate.year,
                            _selectedDate.month,
                            _selectedDate.day,
                            _requestedTime!.hour,
                            _requestedTime!.minute,
                          );
                          reqTimeIso = dt.toIso8601String();
                        }

                        final isCheckInClaim = _claimType.contains('check_in');

                        final success = await ref.read(requestsProvider.notifier).submitClaim(
                          claimType: _claimType,
                          date: dateStr,
                          requestedCheckInAt: isCheckInClaim ? reqTimeIso : null,
                          requestedCheckOutAt: !isCheckInClaim ? reqTimeIso : null,
                          reasonCode: _selectedReason!,
                          description: _descriptionController.text.trim(),
                        );

                        if (!mounted) return;

                        if (success) {
                          _descriptionController.clear();
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(
                              content: Text('Attendance claim submitted successfully.'),
                              backgroundColor: Colors.green,
                              behavior: SnackBarBehavior.floating,
                            ),
                          );
                          widget.onSuccess();
                        } else {
                          final err = ref.read(requestsProvider).error;
                          ScaffoldMessenger.of(context).showSnackBar(
                            SnackBar(
                              content: Text(err ?? 'Failed to submit claim. Please try again.'),
                              backgroundColor: Colors.red,
                              behavior: SnackBarBehavior.floating,
                            ),
                          );
                        }
                      },
                icon: requestsState.isSubmitting
                    ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : const Icon(Icons.send_rounded, color: Colors.white, size: 20),
                label: const Text('SUBMIT ATTENDANCE CLAIM', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 14, letterSpacing: 0.5)),
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppTheme.darkGold,
                  elevation: 2,
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
