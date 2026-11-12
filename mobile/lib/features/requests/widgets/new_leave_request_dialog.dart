import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:file_picker/file_picker.dart';
import 'package:intl/intl.dart';
import '../../../core/config/theme.dart';
import '../models/leave_type_model.dart';
import '../providers/leave_provider.dart';

class NewLeaveRequestDialog extends ConsumerStatefulWidget {
  const NewLeaveRequestDialog({super.key});

  @override
  ConsumerState<NewLeaveRequestDialog> createState() => _NewLeaveRequestDialogState();
}

class _NewLeaveRequestDialogState extends ConsumerState<NewLeaveRequestDialog> {
  final _formKey = GlobalKey<FormState>();

  int? _selectedTypeId;
  DateTime _fromDate = DateTime.now();
  DateTime _toDate = DateTime.now();
  final TextEditingController _reasonController = TextEditingController();

  File? _selectedFile;
  String? _fileName;
  int? _fileSize;
  String? _fileError;

  static const int maxFileSizeBytes = 5 * 1024 * 1024; // 5 MB Limit matching Main System
  static const List<String> allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx'];

  @override
  void initState() {
    super.initState();
    final leaveTypes = ref.read(leaveProvider).leaveTypes;
    if (leaveTypes.isNotEmpty) {
      _selectedTypeId = leaveTypes.first.id;
    }
  }

  @override
  void dispose() {
    _reasonController.dispose();
    super.dispose();
  }

  LeaveTypeModel? get _selectedType {
    final leaveTypes = ref.watch(leaveProvider).leaveTypes;
    if (leaveTypes.isEmpty) return null;
    if (_selectedTypeId == null) return leaveTypes.first;
    try {
      return leaveTypes.firstWhere((t) => t.id == _selectedTypeId);
    } catch (_) {
      return leaveTypes.first;
    }
  }

  int get _calculatedDays {
    final start = DateTime(_fromDate.year, _fromDate.month, _fromDate.day);
    final end = DateTime(_toDate.year, _toDate.month, _toDate.day);
    if (end.isBefore(start)) return 0;
    return end.difference(start).inDays + 1;
  }

  Future<void> _pickAttachment() async {
    setState(() {
      _fileError = null;
    });

    try {
      FilePickerResult? result = await FilePicker.platform.pickFiles(
        type: FileType.custom,
        allowedExtensions: allowedExtensions,
        allowMultiple: false,
      );

      if (result != null && result.files.single.path != null) {
        final path = result.files.single.path!;
        final file = File(path);
        final size = await file.length();
        final ext = path.split('.').last.toLowerCase();

        if (!allowedExtensions.contains(ext)) {
          setState(() {
            _fileError = 'Invalid file type. Allowed: PDF, PNG, JPG, JPEG, DOC, DOCX';
          });
          return;
        }

        if (size > maxFileSizeBytes) {
          final sizeMb = (size / (1024 * 1024)).toStringAsFixed(1);
          setState(() {
            _fileError = 'File size ($sizeMb MB) exceeds max allowed size of 5 MB.';
          });
          return;
        }

        setState(() {
          _selectedFile = file;
          _fileName = result.files.single.name;
          _fileSize = size;
          _fileError = null;
        });
      }
    } catch (e) {
      setState(() {
        _fileError = 'Error picking file: $e';
      });
    }
  }

  Future<void> _selectDate(BuildContext context, bool isFrom) async {
    final today = DateTime.now();
    final todayOnly = DateTime(today.year, today.month, today.day);

    final initialDate = isFrom ? _fromDate : _toDate;
    final firstSelectableDate = isFrom ? todayOnly : DateTime(_fromDate.year, _fromDate.month, _fromDate.day);

    final picked = await showDatePicker(
      context: context,
      initialDate: initialDate.isBefore(firstSelectableDate) ? firstSelectableDate : initialDate,
      firstDate: firstSelectableDate,
      lastDate: DateTime.now().add(const Duration(days: 365)),
      builder: (context, child) {
        return Theme(
          data: Theme.of(context).copyWith(
            colorScheme: const ColorScheme.light(
              primary: AppTheme.primaryTeal,
              onPrimary: Colors.white,
              onSurface: AppTheme.darkText,
            ),
          ),
          child: child!,
        );
      },
    );

    if (picked != null) {
      setState(() {
        if (isFrom) {
          _fromDate = picked;
          if (_toDate.isBefore(_fromDate)) {
            _toDate = _fromDate;
          }
        } else {
          _toDate = picked;
        }
      });
    }
  }

  Future<void> _submit() async {
    final todayOnly = DateTime(DateTime.now().year, DateTime.now().month, DateTime.now().day);
    final fromOnly = DateTime(_fromDate.year, _fromDate.month, _fromDate.day);
    final toOnly = DateTime(_toDate.year, _toDate.month, _toDate.day);

    final currentType = _selectedType;
    if (currentType == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please select a Leave Type.')),
      );
      return;
    }

    if (fromOnly.isBefore(todayOnly)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('From Date cannot be a past date before today.')),
      );
      return;
    }

    if (toOnly.isBefore(fromOnly)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('To Date cannot be before From Date.')),
      );
      return;
    }

    if (currentType.requiresAttachment && _selectedFile == null) {
      setState(() {
        _fileError = 'An attachment document is required for ${currentType.name}.';
      });
      return;
    }

    final dateFromStr = DateFormat('yyyy-MM-dd').format(_fromDate);
    final dateToStr = DateFormat('yyyy-MM-dd').format(_toDate);

    final success = await ref.read(leaveProvider.notifier).submitRequest(
      leaveTypeId: currentType.id,
      dateFrom: dateFromStr,
      dateTo: dateToStr,
      reason: _reasonController.text.trim(),
      filePath: _selectedFile?.path,
    );

    if (mounted) {
      if (success) {
        Navigator.of(context).pop();
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Leave Request submitted successfully to HR Module!'),
            backgroundColor: Color(0xFF15803D),
          ),
        );
      } else {
        final err = ref.read(leaveProvider).errorMessage ?? 'Submission failed.';
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(err), backgroundColor: Colors.red[800]),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final leaveState = ref.watch(leaveProvider);
    final leaveTypes = leaveState.leaveTypes;

    final currentSelectedId = (_selectedTypeId != null && leaveTypes.any((t) => t.id == _selectedTypeId))
        ? _selectedTypeId
        : (leaveTypes.isNotEmpty ? leaveTypes.first.id : null);

    return Dialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      clipBehavior: Clip.antiAlias,
      child: Container(
        constraints: const BoxConstraints(maxWidth: 460),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Styled Teal Header matching Employee Portal Theme
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  colors: [Color(0xFF0F8790), Color(0xFF08606D)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Row(
                    children: [
                      Icon(Icons.flight_takeoff_rounded, color: Colors.white, size: 20),
                      SizedBox(width: 10),
                      Text(
                        'New Leave Request',
                        style: TextStyle(
                          fontSize: 17,
                          fontWeight: FontWeight.bold,
                          color: Colors.white,
                          letterSpacing: 0.3,
                        ),
                      ),
                    ],
                  ),
                  IconButton(
                    icon: const Icon(Icons.close, color: Colors.white70, size: 20),
                    onPressed: () => Navigator.of(context).pop(),
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(),
                  ),
                ],
              ),
            ),

            Flexible(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(20),
                child: Form(
                  key: _formKey,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Leave Type Dropdown
                      const Text(
                        'Type *',
                        style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: Color(0xFF1E293B)),
                      ),
                      const SizedBox(height: 6),
                      DropdownButtonFormField<int>(
                        initialValue: currentSelectedId,
                        decoration: InputDecoration(
                          contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                          enabledBorder: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(10),
                            borderSide: const BorderSide(color: Color(0xFFCBD5E1)),
                          ),
                          focusedBorder: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(10),
                            borderSide: const BorderSide(color: AppTheme.primaryTeal, width: 2),
                          ),
                          hintText: '-- Select --',
                        ),
                        items: leaveTypes.map((t) {
                          return DropdownMenuItem<int>(
                            value: t.id,
                            child: Text(t.name, style: const TextStyle(fontSize: 14, color: AppTheme.darkText)),
                          );
                        }).toList(),
                        onChanged: (val) {
                          setState(() {
                            _selectedTypeId = val;
                            _fileError = null;
                          });
                        },
                      ),
                      const SizedBox(height: 16),

                      // From & To Dates
                      Row(
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text(
                                  'From *',
                                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: Color(0xFF1E293B)),
                                ),
                                const SizedBox(height: 6),
                                InkWell(
                                  onTap: () => _selectDate(context, true),
                                  child: Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                                    decoration: BoxDecoration(
                                      border: Border.all(color: const Color(0xFFCBD5E1)),
                                      borderRadius: BorderRadius.circular(10),
                                    ),
                                    child: Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        Text(
                                          DateFormat('MM/dd/yyyy').format(_fromDate),
                                          style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600, color: Color(0xFF1E293B)),
                                        ),
                                        const Icon(Icons.calendar_today_rounded, size: 16, color: AppTheme.primaryTeal),
                                      ],
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text(
                                  'To *',
                                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: Color(0xFF1E293B)),
                                ),
                                const SizedBox(height: 6),
                                InkWell(
                                  onTap: () => _selectDate(context, false),
                                  child: Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                                    decoration: BoxDecoration(
                                      border: Border.all(color: const Color(0xFFCBD5E1)),
                                      borderRadius: BorderRadius.circular(10),
                                    ),
                                    child: Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        Text(
                                          DateFormat('MM/dd/yyyy').format(_toDate),
                                          style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600, color: Color(0xFF1E293B)),
                                        ),
                                        const Icon(Icons.calendar_today_rounded, size: 16, color: AppTheme.primaryTeal),
                                      ],
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),

                      Padding(
                        padding: const EdgeInsets.only(top: 8.0),
                        child: Text(
                          'Total Duration: $_calculatedDays ${_calculatedDays == 1 ? "day" : "days"}',
                          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppTheme.primaryTeal),
                        ),
                      ),
                      const SizedBox(height: 16),

                      // Reason
                      const Text(
                        'Reason',
                        style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: Color(0xFF1E293B)),
                      ),
                      const SizedBox(height: 6),
                      TextField(
                        controller: _reasonController,
                        maxLines: 2,
                        decoration: InputDecoration(
                          contentPadding: const EdgeInsets.all(12),
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                          enabledBorder: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(10),
                            borderSide: const BorderSide(color: Color(0xFFCBD5E1)),
                          ),
                          focusedBorder: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(10),
                            borderSide: const BorderSide(color: AppTheme.primaryTeal, width: 2),
                          ),
                          hintText: 'Enter reason for leave request...',
                          hintStyle: TextStyle(color: Colors.grey[400], fontSize: 13),
                        ),
                      ),
                      const SizedBox(height: 16),

                      // Attachment Field Header & Helper Info
                      Row(
                        children: [
                          const Text(
                            'Attachment',
                            style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: Color(0xFF1E293B)),
                          ),
                          if (_selectedType?.requiresAttachment == true)
                            const Text(' *', style: TextStyle(color: Colors.red, fontWeight: FontWeight.bold)),
                        ],
                      ),
                      const SizedBox(height: 4),

                      // Attachment Specifications Hint
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                        decoration: BoxDecoration(
                          color: const Color(0xFFF1F5F9),
                          borderRadius: BorderRadius.circular(6),
                          border: Border.all(color: const Color(0xFFE2E8F0)),
                        ),
                        child: const Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              '📄 Allowed file types: PDF, PNG, JPG, JPEG, DOC, DOCX',
                              style: TextStyle(fontSize: 11, color: Color(0xFF475569), fontWeight: FontWeight.w500),
                            ),
                            Text(
                              '⚡ Maximum file size: 5 MB',
                              style: TextStyle(fontSize: 11, color: Color(0xFF475569), fontWeight: FontWeight.w500),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 8),

                      // Attachment File Picker Box
                      Container(
                        decoration: BoxDecoration(
                          border: Border.all(color: _fileError != null ? Colors.red : const Color(0xFFCBD5E1)),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: Row(
                          children: [
                            ElevatedButton(
                              onPressed: _pickAttachment,
                              style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xFFE2E8F0),
                                foregroundColor: const Color(0xFF1E293B),
                                elevation: 0,
                                shape: const RoundedRectangleBorder(
                                  borderRadius: BorderRadius.only(
                                    topLeft: Radius.circular(9),
                                    bottomLeft: Radius.circular(9),
                                  ),
                                ),
                              ),
                              child: const Text('Choose File', style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                _fileName ?? 'no file selected',
                                style: TextStyle(
                                  fontSize: 13,
                                  color: _fileName != null ? AppTheme.darkText : Colors.grey,
                                  fontStyle: _fileName != null ? FontStyle.normal : FontStyle.italic,
                                ),
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                            if (_fileName != null)
                              IconButton(
                                icon: const Icon(Icons.cancel, size: 18, color: Colors.grey),
                                onPressed: () {
                                  setState(() {
                                    _selectedFile = null;
                                    _fileName = null;
                                    _fileSize = null;
                                    _fileError = null;
                                  });
                                },
                              ),
                          ],
                        ),
                      ),
                      if (_fileSize != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 4.0),
                          child: Text(
                            'Selected size: ${(_fileSize! / 1024).toStringAsFixed(1)} KB (Max 5 MB)',
                            style: const TextStyle(fontSize: 11, color: Colors.green, fontWeight: FontWeight.w600),
                          ),
                        ),
                      if (_fileError != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 4.0),
                          child: Text(
                            _fileError!,
                            style: const TextStyle(fontSize: 11, color: Colors.red, fontWeight: FontWeight.bold),
                          ),
                        ),

                      const SizedBox(height: 24),

                      // Action Buttons
                      Row(
                        mainAxisAlignment: MainAxisAlignment.end,
                        children: [
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
                                  blurRadius: 6,
                                  offset: Offset(0, 2),
                                ),
                              ],
                            ),
                            child: Material(
                              color: Colors.transparent,
                              child: InkWell(
                                onTap: leaveState.isSubmitting ? null : _submit,
                                borderRadius: BorderRadius.circular(10),
                                child: Padding(
                                  padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 10),
                                  child: leaveState.isSubmitting
                                      ? const SizedBox(
                                          width: 18,
                                          height: 18,
                                          child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                                        )
                                      : const Text(
                                          'Submit',
                                          style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13.5, letterSpacing: 0.3),
                                        ),
                                ),
                              ),
                            ),
                          ),
                          const SizedBox(width: 10),
                          OutlinedButton(
                            onPressed: () => Navigator.of(context).pop(),
                            style: OutlinedButton.styleFrom(
                              foregroundColor: const Color(0xFF475569),
                              side: const BorderSide(color: Color(0xFFCBD5E1)),
                              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 10),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                            ),
                            child: const Text('Cancel', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5)),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
