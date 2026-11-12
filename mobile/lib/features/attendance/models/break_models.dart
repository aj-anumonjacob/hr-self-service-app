class BreakPolicyModel {
  final int? id;
  final String uuid;
  final String name;
  final String policyType; // flexible, fixed, window
  final String? startTime;
  final String? endTime;
  final int allowedDurationMinutes;
  final int maxBreaksPerShift;
  final bool isPaid;
  final bool requiresApproval;
  final bool active;

  BreakPolicyModel({
    this.id,
    required this.uuid,
    required this.name,
    required this.policyType,
    this.startTime,
    this.endTime,
    required this.allowedDurationMinutes,
    required this.maxBreaksPerShift,
    required this.isPaid,
    required this.requiresApproval,
    this.active = true,
  });

  factory BreakPolicyModel.fromJson(Map<String, dynamic> json) {
    return BreakPolicyModel(
      id: (json['id'] as num?)?.toInt(),
      uuid: json['uuid'] as String? ?? '',
      name: json['name'] as String? ?? 'Flexible Break',
      policyType: (json['policy_type'] ?? json['type']) as String? ?? 'flexible',
      startTime: json['start_time'] as String?,
      endTime: json['end_time'] as String?,
      allowedDurationMinutes: (json['allowed_duration_minutes'] ?? json['allowedDurationMinutes'] as num?)?.toInt() ?? 60,
      maxBreaksPerShift: (json['max_breaks_per_shift'] ?? json['maximumBreaksPerShift'] as num?)?.toInt() ?? 1,
      isPaid: json['is_paid'] as bool? ?? false,
      requiresApproval: json['requires_approval'] as bool? ?? false,
      active: json['active'] as bool? ?? json['is_active'] as bool? ?? true,
    );
  }
}

class AttendanceBreakModel {
  final String uuid;
  final String? startedAt;
  final String? endedAt;
  final String? expectedEndAt;
  final int durationSeconds;
  final int overdueDurationSeconds;
  final String status; // active, completed, cancelled
  final String? violationCode;
  final String? reason;

  AttendanceBreakModel({
    required this.uuid,
    this.startedAt,
    this.endedAt,
    this.expectedEndAt,
    required this.durationSeconds,
    required this.overdueDurationSeconds,
    required this.status,
    this.violationCode,
    this.reason,
  });

  factory AttendanceBreakModel.fromJson(Map<String, dynamic> json) {
    return AttendanceBreakModel(
      uuid: json['uuid'] as String? ?? '',
      startedAt: json['start_time_server'] as String?,
      endedAt: json['end_time_server'] as String?,
      expectedEndAt: json['expected_end_at'] as String?,
      durationSeconds: (json['duration_seconds'] as num?)?.toInt() ?? 0,
      overdueDurationSeconds: (json['overdue_duration_seconds'] as num?)?.toInt() ?? 0,
      status: json['status'] as String? ?? 'active',
      violationCode: json['violation_code'] as String?,
      reason: json['reason'] as String?,
    );
  }
}

class BreakStateModel {
  final BreakPolicyModel? effectivePolicy;
  final AttendanceBreakModel? activeBreak;
  final List<AttendanceBreakModel> breaksToday;
  final bool canStartBreak;
  final bool canEndBreak;
  final int usedBreakCount;
  final int remainingBreaksCount;
  final int completedBreaksCount;
  final String availabilityStatus;
  final String? restrictionCode;
  final String? restrictionMessage;
  final String? serverTime;
  final String? expectedEndAt;
  final bool isOverdue;
  final int overdueMinutes;
  final String? unavailableReason;

  BreakStateModel({
    this.effectivePolicy,
    this.activeBreak,
    this.breaksToday = const [],
    this.canStartBreak = false,
    this.canEndBreak = false,
    this.usedBreakCount = 0,
    this.remainingBreaksCount = 1,
    this.completedBreaksCount = 0,
    this.availabilityStatus = 'unavailable',
    this.restrictionCode,
    this.restrictionMessage,
    this.serverTime,
    this.expectedEndAt,
    this.isOverdue = false,
    this.overdueMinutes = 0,
    this.unavailableReason,
  });

  factory BreakStateModel.fromJson(Map<String, dynamic> json) {
    final policyJson = (json['policy'] ?? json['effective_policy']) as Map<String, dynamic>?;
    final activeBreakJson = json['active_break'] as Map<String, dynamic>?;
    final rawBreaksToday = json['breaks_today'] as List<dynamic>? ?? [];

    final canStart = (json['can_start_break'] ?? json['canStartBreak']) as bool? ?? false;
    final remaining = (json['remaining_breaks_count'] ?? json['remainingBreakCount'] as num?)?.toInt() ?? 1;
    final used = (json['used_break_count'] ?? json['usedBreakCount'] ?? json['completed_breaks_count'] as num?)?.toInt() ?? 0;

    String status = (json['availability_status'] ?? json['availabilityStatus']) as String? ?? '';
    if (status.isEmpty) {
      if (canStart) {
        status = 'available';
      } else if (activeBreakJson != null) {
        status = 'on_break';
      } else if (remaining <= 0) {
        status = 'limit_reached';
      } else {
        status = 'unavailable';
      }
    }

    final String? rCode = json['restrictionCode'] as String? ?? json['unavailable_reason'] as String?;
    final String? rMsg = json['restrictionMessage'] as String?;

    return BreakStateModel(
      effectivePolicy: policyJson != null ? BreakPolicyModel.fromJson(policyJson) : null,
      activeBreak: activeBreakJson != null ? AttendanceBreakModel.fromJson(activeBreakJson) : null,
      breaksToday: rawBreaksToday.map((e) => AttendanceBreakModel.fromJson(e as Map<String, dynamic>)).toList(),
      canStartBreak: canStart,
      canEndBreak: json['can_end_break'] as bool? ?? (activeBreakJson != null),
      usedBreakCount: used,
      remainingBreaksCount: remaining,
      completedBreaksCount: (json['completed_breaks_count'] as num?)?.toInt() ?? 0,
      availabilityStatus: status,
      restrictionCode: rCode,
      restrictionMessage: rMsg,
      serverTime: json['server_time'] as String?,
      expectedEndAt: json['expected_end_at'] as String?,
      isOverdue: json['is_overdue'] as bool? ?? false,
      overdueMinutes: (json['overdue_minutes'] as num?)?.toInt() ?? 0,
      unavailableReason: json['unavailable_reason'] as String?,
    );
  }

  /// Employee-facing localized label derived strictly from machine availabilityStatus
  String get displayStatusLabel {
    switch (availabilityStatus) {
      case 'available':
        return 'Available';
      case 'on_break':
        return 'On Break';
      case 'limit_reached':
        return 'Limit Reached';
      case 'not_checked_in':
        return 'Check In Required';
      case 'attendance_closed':
        return 'Attendance Closed';
      case 'outside_allowed_time':
        return 'Not Available Now';
      case 'approval_required':
        return 'Approval Required';
      case 'policy_inactive':
        return 'Policy Inactive';
      case 'unavailable':
      default:
        return 'Unavailable';
    }
  }
}
