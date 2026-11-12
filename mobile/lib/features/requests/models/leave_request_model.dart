import 'package:flutter/material.dart';

class LeaveRequestModel {
  final int id;
  final int employeeId;
  final int leaveTypeId;
  final String leaveTypeName;
  final String? leaveTypeCode;
  final String? colorHex;
  final String dateFrom;
  final String dateTo;
  final double days;
  final String? reason;
  final String status;
  final String? attachmentPath;
  final String? attachmentUrl;
  final String createdAt;

  LeaveRequestModel({
    required this.id,
    required this.employeeId,
    required this.leaveTypeId,
    required this.leaveTypeName,
    this.leaveTypeCode,
    this.colorHex,
    required this.dateFrom,
    required this.dateTo,
    required this.days,
    this.reason,
    required this.status,
    this.attachmentPath,
    this.attachmentUrl,
    required this.createdAt,
  });

  factory LeaveRequestModel.fromJson(Map<String, dynamic> json) {
    return LeaveRequestModel(
      id: json['id'] is int ? json['id'] : int.parse(json['id'].toString()),
      employeeId: json['employee_id'] is int ? json['employee_id'] : int.parse(json['employee_id'].toString()),
      leaveTypeId: json['leave_type_id'] is int ? json['leave_type_id'] : int.parse(json['leave_type_id'].toString()),
      leaveTypeName: json['leave_type_name'] ?? 'Leave Request',
      leaveTypeCode: json['leave_type_code'],
      colorHex: json['color_hex'],
      dateFrom: json['date_from'] ?? '',
      dateTo: json['date_to'] ?? '',
      days: double.tryParse(json['days'].toString()) ?? 1.0,
      reason: json['reason'],
      status: json['status'] ?? 'pending',
      attachmentPath: json['attachment_path'],
      attachmentUrl: json['attachment_url'],
      createdAt: json['created_at'] ?? '',
    );
  }

  String get displayStatusLabel {
    switch (status.toLowerCase()) {
      case 'approved':
        return 'Approved';
      case 'rejected':
        return 'Rejected';
      case 'cancelled':
        return 'Cancelled';
      case 'pending':
      default:
        return 'Pending';
    }
  }

  Color get statusColor {
    switch (status.toLowerCase()) {
      case 'approved':
        return const Color(0xFF15803D);
      case 'rejected':
      case 'cancelled':
        return const Color(0xFFB91C1C);
      case 'pending':
      default:
        return const Color(0xFFB45309);
    }
  }

  Color get statusBgColor {
    switch (status.toLowerCase()) {
      case 'approved':
        return const Color(0xFFDCFCE7);
      case 'rejected':
      case 'cancelled':
        return const Color(0xFFFEE2E2);
      case 'pending':
      default:
        return const Color(0xFFFEF3C7);
    }
  }
}
