import 'package:flutter/material.dart';

class RequestActionModel {
  final String? previousStatus;
  final String newStatus;
  final String? actorName;
  final String actorRole;
  final String? comment;
  final String createdAt;

  RequestActionModel({
    this.previousStatus,
    required this.newStatus,
    this.actorName,
    required this.actorRole,
    this.comment,
    required this.createdAt,
  });

  factory RequestActionModel.fromJson(Map<String, dynamic> json) {
    return RequestActionModel(
      previousStatus: json['previous_status'] as String?,
      newStatus: json['new_status'] as String? ?? '',
      actorName: json['actor']?['name'] as String?,
      actorRole: json['actor_role'] as String? ?? 'system',
      comment: json['comment'] as String?,
      createdAt: json['created_at'] as String? ?? '',
    );
  }
}

class AttendanceRequestModel {
  final int id;
  final String uuid;
  final String date;
  final String requestType; // late_arrival, morning_half_day
  final int delayMinutes;
  final String? expectedArrivalAt;
  final String reasonCode;
  final String? description;
  final String status; // pending, approved, rejected, explanation_requested, employee_responded, cancelled
  final String? submittedAt;
  final String? managerComment;
  final String? explanationRequest;
  final String? employeeResponse;
  final String? shiftName;
  final String? employeeName;
  final String? managerName;
  final List<RequestActionModel> actions;

  AttendanceRequestModel({
    required this.id,
    required this.uuid,
    required this.date,
    required this.requestType,
    required this.delayMinutes,
    this.expectedArrivalAt,
    required this.reasonCode,
    this.description,
    required this.status,
    this.submittedAt,
    this.managerComment,
    this.explanationRequest,
    this.employeeResponse,
    this.shiftName,
    this.employeeName,
    this.managerName,
    this.actions = const [],
  });

  factory AttendanceRequestModel.fromJson(Map<String, dynamic> json) {
    final rawActions = json['actions'] as List<dynamic>? ?? [];

    return AttendanceRequestModel(
      id: (json['id'] as num?)?.toInt() ?? 0,
      uuid: json['uuid'] as String? ?? '',
      date: json['date'] as String? ?? '',
      requestType: json['request_type'] as String? ?? 'late_arrival',
      delayMinutes: (json['delay_minutes'] as num?)?.toInt() ?? 0,
      expectedArrivalAt: json['expected_arrival_at'] as String?,
      reasonCode: json['reason_code'] as String? ?? 'other',
      description: json['description'] as String?,
      status: json['status'] as String? ?? 'pending',
      submittedAt: json['submitted_at'] as String?,
      managerComment: json['manager_comment'] as String?,
      explanationRequest: json['explanation_request'] as String?,
      employeeResponse: json['employee_response'] as String?,
      shiftName: json['shift']?['name'] as String?,
      employeeName: json['employee']?['full_name'] as String?,
      managerName: json['reporting_manager']?['full_name'] as String?,
      actions: rawActions.map((e) => RequestActionModel.fromJson(e as Map<String, dynamic>)).toList(),
    );
  }

  String get displayTypeLabel {
    return requestType == 'morning_half_day' ? 'Morning Half-Day' : 'Late Arrival';
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
