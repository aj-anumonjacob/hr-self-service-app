class MissedAttendanceModel {
  final int attendanceRecordId;
  final String date;
  final String? recordedCheckInAt;
  final String issueType;

  MissedAttendanceModel({
    required this.attendanceRecordId,
    required this.date,
    this.recordedCheckInAt,
    required this.issueType,
  });

  factory MissedAttendanceModel.fromJson(Map<String, dynamic> json) {
    return MissedAttendanceModel(
      attendanceRecordId: (json['attendance_record_id'] as num?)?.toInt() ?? 0,
      date: json['date'] as String? ?? '',
      recordedCheckInAt: json['recorded_check_in_at'] as String?,
      issueType: json['issue_type'] as String? ?? 'missing_check_out',
    );
  }

  String get displayLabel => issueType == 'missing_check_in' ? 'Missing Check-In' : 'Missing Check-Out';
}
