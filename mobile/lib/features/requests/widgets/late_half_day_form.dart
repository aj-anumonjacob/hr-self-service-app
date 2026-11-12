import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
import '../../../core/config/theme.dart';
import '../providers/requests_provider.dart';

class LateHalfDayForm extends ConsumerStatefulWidget {
  final VoidCallback onSuccess;

  const LateHalfDayForm({super.key, required this.onSuccess});

  @override
  ConsumerState<LateHalfDayForm> createState() => _LateHalfDayFormState();
}

class _LateHalfDayFormState extends ConsumerState<LateHalfDayForm> {
  final _formKey = GlobalKey<FormState>();

  String _requestType = 'late_arrival';
  DateTime _selectedDate = DateTime.now();

  String _inputMode = 'duration';
  int _delayHours = 1;
  int _delayMinutes = 0;
  TimeOfDay? _expectedArrivalTime;

  String? _selectedReason;
  final TextEditingController _descriptionController = TextEditingController();

  final TimeOfDay _shiftStartTime = const TimeOfDay(hour: 9, minute: 0);

  @override
  void dispose() {
    _descriptionController.dispose();
    super.dispose();
  }

  void _calculateEquivalentFromDuration() {
    final totalMinutes = (_delayHours * 60) + _delayMinutes;
    final startMinutes = (_shiftStartTime.hour * 60) + _shiftStartTime.minute;
    final endMinutes = startMinutes + totalMinutes;

    final newHour = (endMinutes ~/ 60) % 24;
    final newMin = endMinutes % 60;

    setState(() {
      _expectedArrivalTime = TimeOfDay(hour: newHour, minute: newMin);
    });
  }

  void _calculateEquivalentFromTime(TimeOfDay arrival) {
    final startMinutes = (_shiftStartTime.hour * 60) + _shiftStartTime.minute;
    final arrivalMinutes = (arrival.hour * 60) + arrival.minute;

    int diff = arrivalMinutes - startMinutes;
    if (diff < 0) diff += 1440;

    setState(() {
      _delayHours = diff ~/ 60;
      _delayMinutes = diff % 60;
      _expectedArrivalTime = arrival;
    });
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate,
      firstDate: DateTime.now().subtract(const Duration(days: 7)),
      lastDate: DateTime.now().add(const Duration(days: 30)),
    );
    if (picked != null) {
      setState(() => _selectedDate = picked);
    }
  }

  Future<void> _pickTime() async {
    final picked = await showTimePicker(
      context: context,
      initialTime: _expectedArrivalTime ?? const TimeOfDay(hour: 10, minute: 30),
    );
    if (picked != null) {
      _calculateEquivalentFromTime(picked);
    }
  }

  InputDecoration _customInputDecoration({required String labelText, IconData? prefixIcon, String? helperText}) {
    return InputDecoration(
      labelText: labelText,
      helperText: helperText,
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
    final reasons = settings?.lateReasonOptions ?? [];

    if (_expectedArrivalTime == null) {
      _calculateEquivalentFromDuration();
    }

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
              // Section Header: Request Category
              const Text(
                'SELECT REQUEST TYPE',
                style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.grey, letterSpacing: 0.8),
              ),
              const SizedBox(height: 10),

              // Request Type Segmented Toggle (Full Width)
              SizedBox(
                width: double.infinity,
                child: SegmentedButton<String>(
                  style: SegmentedButton.styleFrom(
                    textStyle: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600),
                  ),
                  segments: const [
                    ButtonSegment(
                      value: 'late_arrival',
                      label: Text('Late Arrival', style: TextStyle(fontSize: 13.5)),
                      icon: Icon(Icons.alarm_add, size: 17),
                    ),
                    ButtonSegment(
                      value: 'morning_half_day',
                      label: Text('Morning Half-Day', style: TextStyle(fontSize: 13.5)),
                      icon: Icon(Icons.wb_twilight, size: 17),
                    ),
                  ],
                  selected: {_requestType},
                  onSelectionChanged: (val) {
                    setState(() {
                      _requestType = val.first;
                    });
                  },
                ),
              ),
              const SizedBox(height: 20),

              // Date Picker Field
              InkWell(
                onTap: _pickDate,
                borderRadius: BorderRadius.circular(12),
                child: InputDecorator(
                  decoration: _customInputDecoration(
                    labelText: 'Target Date',
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

              if (_requestType == 'late_arrival') ...[
                // Delay Mode Header
                const Text(
                  'SPECIFY DELAY BY',
                  style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.grey, letterSpacing: 0.8),
                ),
                const SizedBox(height: 10),

                // Full-width Delay Mode Segmented Toggle
                SizedBox(
                  width: double.infinity,
                  child: SegmentedButton<String>(
                    style: SegmentedButton.styleFrom(
                      textStyle: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600),
                    ),
                    segments: const [
                      ButtonSegment(
                        value: 'duration',
                        label: Text('Delay Duration', style: TextStyle(fontSize: 13.5)),
                      ),
                      ButtonSegment(
                        value: 'arrival_time',
                        label: Text('Arrival Time', style: TextStyle(fontSize: 13.5)),
                      ),
                    ],
                    selected: {_inputMode},
                    onSelectionChanged: (val) {
                      setState(() => _inputMode = val.first);
                    },
                  ),
                ),
                const SizedBox(height: 16),

                if (_inputMode == 'duration') ...[
                  Row(
                    children: [
                      Expanded(
                        child: DropdownButtonFormField<int>(
                          initialValue: _delayHours,
                          isExpanded: true,
                          decoration: _customInputDecoration(labelText: 'Hours'),
                          items: List.generate(5, (i) => DropdownMenuItem(value: i, child: Text('$i hour(s)', overflow: TextOverflow.ellipsis))),
                          onChanged: (val) {
                            if (val != null) {
                              setState(() => _delayHours = val);
                              _calculateEquivalentFromDuration();
                            }
                          },
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: DropdownButtonFormField<int>(
                          initialValue: _delayMinutes,
                          isExpanded: true,
                          decoration: _customInputDecoration(labelText: 'Minutes'),
                          items: [0, 15, 30, 45].map((m) => DropdownMenuItem(value: m, child: Text('$m mins', overflow: TextOverflow.ellipsis))).toList(),
                          onChanged: (val) {
                            if (val != null) {
                              setState(() => _delayMinutes = val);
                              _calculateEquivalentFromDuration();
                            }
                          },
                        ),
                      ),
                    ],
                  ),
                ] else ...[
                  InkWell(
                    onTap: _pickTime,
                    borderRadius: BorderRadius.circular(12),
                    child: InputDecorator(
                      decoration: _customInputDecoration(
                        labelText: 'Expected Arrival Time',
                        prefixIcon: Icons.access_time_rounded,
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            _expectedArrivalTime != null ? _expectedArrivalTime!.format(context) : 'Select Arrival Time',
                            style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14, color: AppTheme.charcoal),
                          ),
                          const Icon(Icons.arrow_drop_down, color: Colors.grey),
                        ],
                      ),
                    ),
                  ),
                ],
                const SizedBox(height: 16),

                // Dynamic Calculation Reference Box
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: const Color(0xFFFFFBEB),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFFFDE68A)),
                  ),
                  child: Column(
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text('Scheduled Shift Start:', style: TextStyle(fontSize: 12, color: Color(0xFF92400E))),
                          Text(_shiftStartTime.format(context), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Color(0xFF92400E))),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text('Requested Delay:', style: TextStyle(fontSize: 12, color: Color(0xFF92400E))),
                          Text('${_delayHours}h ${_delayMinutes}m', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Color(0xFF92400E))),
                        ],
                      ),
                      const Divider(height: 16, color: Color(0xFFFDE68A)),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text('Expected Arrival Time:', style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: AppTheme.charcoal)),
                          Text(
                            _expectedArrivalTime != null ? _expectedArrivalTime!.format(context) : '--:--',
                            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Color(0xFFB45309)),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ] else ...[
                // Morning Half-Day Calculated Notice Box
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: const Color(0xFFEFF6FF),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFFBFDBFE)),
                  ),
                  child: const Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Icon(Icons.info_outline, color: Color(0xFF1E40AF), size: 18),
                          SizedBox(width: 8),
                          Text('Morning Half-Day Request', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Color(0xFF1E40AF))),
                        ],
                      ),
                      SizedBox(height: 6),
                      Text(
                        'Your expected arrival time will be calculated dynamically based on 50% of your scheduled shift working hours.',
                        style: TextStyle(fontSize: 12, color: Color(0xFF1E3A8A), height: 1.4),
                      ),
                    ],
                  ),
                ),
              ],
              const SizedBox(height: 20),

              // Reason Dropdown (with isExpanded: true to prevent overflow!)
              DropdownButtonFormField<String>(
                initialValue: _selectedReason,
                isExpanded: true,
                decoration: _customInputDecoration(
                  labelText: 'Reason for Late/Half-Day',
                  prefixIcon: Icons.help_outline_rounded,
                ),
                items: reasons.map((r) => DropdownMenuItem(
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

              // Description Text Field
              TextFormField(
                controller: _descriptionController,
                maxLines: 3,
                maxLength: 500,
                decoration: _customInputDecoration(
                  labelText: 'Explanation / Note (Optional)',
                  prefixIcon: Icons.notes_rounded,
                ),
              ),
              const SizedBox(height: 24),

              // Submit Button
              ElevatedButton.icon(
                onPressed: requestsState.isSubmitting
                    ? null
                    : () async {
                        if (!_formKey.currentState!.validate()) return;

                        final dateStr = DateFormat('yyyy-MM-dd').format(_selectedDate);
                        final totalDelayMins = (_delayHours * 60) + _delayMinutes;

                        String? arrivalIso;
                        if (_expectedArrivalTime != null) {
                          final dt = DateTime(
                            _selectedDate.year,
                            _selectedDate.month,
                            _selectedDate.day,
                            _expectedArrivalTime!.hour,
                            _expectedArrivalTime!.minute,
                          );
                          arrivalIso = dt.toIso8601String();
                        }

                        final success = await ref.read(requestsProvider.notifier).submitLateArrivalOrHalfDay(
                          date: dateStr,
                          requestType: _requestType,
                          delayMinutes: totalDelayMins,
                          expectedArrivalAt: arrivalIso,
                          reasonCode: _selectedReason!,
                          description: _descriptionController.text.trim().isEmpty ? null : _descriptionController.text.trim(),
                        );

                        if (success) {
                          widget.onSuccess();
                        }
                      },
                icon: requestsState.isSubmitting
                    ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : const Icon(Icons.send_rounded, color: Colors.white, size: 20),
                label: const Text('SUBMIT REQUEST', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 14, letterSpacing: 0.5)),
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
