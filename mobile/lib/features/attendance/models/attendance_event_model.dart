import 'package:intl/intl.dart';
import '../../../core/utils/duration_formatter.dart';

class AttendanceEventModel {
  final int id;
  final String uuid;
  final String type; // ATTENDANCE_CHECKED_IN, ATTENDANCE_CHECKED_OUT, BREAK_STARTED, BREAK_ENDED, etc.
  final String title;
  final String? occurredAt;
  final String? date;
  final String? time;
  final int? attendanceId;
  final int? breakId;
  final String? eventSource;
  final Map<String, dynamic> details;

  AttendanceEventModel({
    required this.id,
    required this.uuid,
    required this.type,
    required this.title,
    this.occurredAt,
    this.date,
    this.time,
    this.attendanceId,
    this.breakId,
    this.eventSource,
    this.details = const {},
  });

  factory AttendanceEventModel.fromJson(Map<String, dynamic> json) {
    Map<String, dynamic> parsedDetails = {};
    if (json['details'] is Map) {
      parsedDetails = Map<String, dynamic>.from(json['details'] as Map);
    }

    return AttendanceEventModel(
      id: (json['id'] as num?)?.toInt() ?? 0,
      uuid: json['uuid'] as String? ?? '',
      type: json['type'] as String? ?? 'UNKNOWN',
      title: json['title'] as String? ?? 'Activity',
      occurredAt: json['occurred_at'] as String?,
      date: json['date'] as String?,
      time: json['time'] as String?,
      attendanceId: (json['attendance_id'] as num?)?.toInt(),
      breakId: (json['break_id'] as num?)?.toInt(),
      eventSource: json['event_source'] as String?,
      details: parsedDetails,
    );
  }

  /// Format date header for grouping (e.g. "Today", "Yesterday", "August 01, 2026")
  String get groupDateHeader {
    if (date == null || date!.isEmpty) return 'Recent Activity';
    try {
      final dt = DateTime.parse(date!);
      final now = DateTime.now();
      final today = DateTime(now.year, now.month, now.day);
      final yesterday = today.subtract(const Duration(days: 1));
      final targetDate = DateTime(dt.year, dt.month, dt.day);

      if (targetDate == today) return 'Today';
      if (targetDate == yesterday) return 'Yesterday';
      return DateFormat('EEEE, MMMM dd, yyyy').format(dt);
    } catch (_) {
      return date!;
    }
  }

  /// Secondary description string for UI timeline
  String get subtitleDetail {
    switch (type) {
      case 'ATTENDANCE_CHECKED_IN':
        final loc = details['location_name'];
        final lateMins = (details['late_minutes'] as num?)?.toInt() ?? 0;
        final lateStr = lateMins > 0 ? ' • Late: ${_formatMinutes(lateMins)}' : '';
        return loc != null ? 'Attendance started at $loc$lateStr' : 'Attendance session started$lateStr';

      case 'ATTENDANCE_CHECKED_OUT':
        final netMins = (details['net_duration_minutes'] as num?)?.toInt() ?? 0;
        return netMins > 0 ? 'Worked duration: ${_formatMinutes(netMins)}' : 'Attendance session closed';

      case 'BREAK_STARTED':
        final allowed = (details['allowed_duration_minutes'] as num?)?.toInt() ?? 60;
        final policy = details['policy_name'] ?? 'Flexible break';
        return '$policy · Allowed: $allowed minutes';

      case 'BREAK_ENDED':
        int? actualSec = (details['actualDurationSeconds'] as num?)?.toInt() ??
            (details['actual_duration_seconds'] as num?)?.toInt() ??
            (details['duration_seconds'] as num?)?.toInt();

        // Calculate fallback if duration_seconds is missing but startedAt/endedAt exist
        if (actualSec == null) {
          final startStr = details['startedAt'] ?? details['started_at'];
          final endStr = details['endedAt'] ?? details['ended_at'] ?? occurredAt;
          if (startStr != null && endStr != null) {
            try {
              final startDt = DateTime.parse(startStr);
              final endDt = DateTime.parse(endStr);
              actualSec = endDt.difference(startDt).inSeconds;
            } catch (_) {}
          }
        }

        // If duration_minutes was stored directly in legacy event
        if (actualSec == null && details.containsKey('duration_minutes')) {
          final mins = (details['duration_minutes'] as num?)?.toInt() ?? 0;
          actualSec = mins * 60;
        }

        if (actualSec == null) {
          return 'Break completed';
        }

        final actualFormatted = formatDuration(actualSec);

        final int allowedSec = (details['allowedDurationSeconds'] as num?)?.toInt() ??
            (details['allowed_duration_seconds'] as num?)?.toInt() ??
            (((details['allowed_duration_minutes'] as num?)?.toInt() ?? 60) * 60);

        int overdueSec = (details['overdueDurationSeconds'] as num?)?.toInt() ??
            (details['overdue_duration_seconds'] as num?)?.toInt() ??
            (details['overdue_seconds'] as num?)?.toInt() ??
            (actualSec > allowedSec ? actualSec - allowedSec : 0);

        final bool wasOverdue = (details['wasOverdue'] as bool?) ??
            (details['was_overdue'] as bool?) ??
            (overdueSec > 0);

        if (wasOverdue && overdueSec > 0) {
          final overdueFormatted = formatDuration(overdueSec);
          return 'Break duration: $actualFormatted\nOverdue by: $overdueFormatted';
        }

        return 'Break duration: $actualFormatted';

      case 'MANAGER_CORRECTION':
        final reason = details['reason'] ?? 'Closed by manager';
        return 'Note: $reason';

      default:
        return 'Attendance activity event';
    }
  }

  static String _formatMinutes(int minutes) {
    if (minutes < 60) return '$minutes mins';
    final hrs = minutes ~/ 60;
    final rem = minutes % 60;
    return rem > 0 ? '$hrs hrs $rem mins' : '$hrs hrs';
  }
}
