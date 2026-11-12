import 'package:flutter/material.dart';
import 'attendance_request_model.dart';

class AttendanceClaimModel {
  final int id;
  final String uuid;
  final String claimType;
  final String date;
  final String? recordedCheckInAt;
  final String? recordedCheckOutAt;
  final String? requestedCheckInAt;
  final String? requestedCheckOutAt;
  final String reasonCode;
  final String description;
  final String status;
  final String? submittedAt;
  final String? managerComment;
  final String? explanationRequest;
  final String? employeeResponse;
  final String? employeeName;
  final String? managerName;
  final List<RequestActionModel> actions;

  AttendanceClaimModel({
    required this.id,
    required this.uuid,
    required this.claimType,
    required this.date,
    this.recordedCheckInAt,
    this.recordedCheckOutAt,
    this.requestedCheckInAt,
    this.requestedCheckOutAt,
    required this.reasonCode,
    required this.description,
    required this.status,
    this.submittedAt,
    this.managerComment,
    this.explanationRequest,
    this.employeeResponse,
    this.employeeName,
    this.managerName,
    this.actions = const [],
  });

  factory AttendanceClaimModel.fromJson(Map<String, dynamic> json) {
    final rawActions = json['actions'] as List<dynamic>? ?? [];

    return AttendanceClaimModel(
      id: (json['id'] as num?)?.toInt() ?? 0,
      uuid: json['uuid'] as String? ?? '',
      claimType: json['claim_type'] as String? ?? 'missing_check_in',
      date: json['date'] as String? ?? '',
      recordedCheckInAt: json['recorded_check_in_at'] as String?,
      recordedCheckOutAt: json['recorded_check_out_at'] as String?,
      requestedCheckInAt: json['requested_check_in_at'] as String?,
      requestedCheckOutAt: json['requested_check_out_at'] as String?,
      reasonCode: json['reason_code'] as String? ?? 'other',
      description: json['description'] as String? ?? '',
      status: json['status'] as String? ?? 'pending',
      submittedAt: json['submitted_at'] as String?,
      managerComment: json['manager_comment'] as String?,
      explanationRequest: json['explanation_request'] as String?,
      employeeResponse: json['employee_response'] as String?,
      employeeName: json['employee']?['full_name'] as String?,
      managerName: json['reporting_manager']?['full_name'] as String?,
      actions: rawActions.map((e) => RequestActionModel.fromJson(e as Map<String, dynamic>)).toList(),
    );
  }

  String get displayTypeLabel {
    switch (claimType) {
      case 'missing_check_in':
        return 'Missing Check-In';
      case 'incorrect_check_in':
        return 'Incorrect Check-In';
      case 'missing_check_out':
        return 'Missing Check-Out';
      case 'incorrect_check_out':
        return 'Incorrect Check-Out';
      case 'accidental_check_in':
        return 'Accidental Check-In';
      case 'accidental_check_out':
        return 'Accidental Check-Out';
      case 'auto_checkout_correction':
        return 'Auto Checkout Correction';
      case 'other_attendance_issue':
      default:
        return 'Attendance Claim';
    }
  }

  String get displayStatusLabel {
    switch (status) {
      case 'approved':
        return 'Approved';
      case 'rejected':
        return 'Rejected';
      case 'explanation_requested':
        return 'Needs Explanation';
      case 'employee_responded':
        return 'Responded';
      case 'cancelled':
        return 'Cancelled';
      case 'pending':
      case 'submitted':
      default:
        return 'Pending';
    }
  }

  Color get statusColor {
    switch (status) {
      case 'approved':
        return Colors.green[800]!;
      case 'rejected':
        return Colors.red[800]!;
      case 'explanation_requested':
        return Colors.orange[900]!;
      case 'employee_responded':
        return Colors.purple[800]!;
      case 'cancelled':
        return Colors.grey[700]!;
      case 'pending':
      default:
        return Colors.blue[800]!;
    }
  }

  Color get statusBgColor {
    switch (status) {
      case 'approved':
        return Colors.green[50]!;
      case 'rejected':
        return Colors.red[50]!;
      case 'explanation_requested':
        return Colors.orange[50]!;
      case 'employee_responded':
        return Colors.purple[50]!;
      case 'cancelled':
        return Colors.grey[200]!;
      case 'pending':
      default:
        return Colors.blue[50]!;
    }
  }
}
