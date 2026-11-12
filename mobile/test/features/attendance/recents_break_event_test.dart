import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/attendance/models/attendance_event_model.dart';

void main() {
  group('AttendanceEventModel BREAK_ENDED tests', () {
    test('1:52 PM to 3:43 PM (6,660s) overdue break event subtitle detail', () {
      final json = {
        'id': 101,
        'uuid': 'evt-101',
        'type': 'BREAK_ENDED',
        'title': 'Break Ended',
        'occurred_at': '2026-08-01T15:43:00Z',
        'date': '2026-08-01',
        'time': '03:43 PM',
        'details': {
          'startedAt': '2026-08-01T13:52:00Z',
          'endedAt': '2026-08-01T15:43:00Z',
          'actualDurationSeconds': 6660,
          'allowedDurationSeconds': 3600,
          'overdueDurationSeconds': 3060,
          'wasOverdue': true,
        }
      };

      final model = AttendanceEventModel.fromJson(json);
      expect(
        model.subtitleDetail,
        equals('Break duration: 1 hour 51 minutes\nOverdue by: 51 minutes'),
      );
    });

    test('Normal break within policy (3,600s)', () {
      final json = {
        'id': 102,
        'uuid': 'evt-102',
        'type': 'BREAK_ENDED',
        'title': 'Break Ended',
        'occurred_at': '2026-08-01T14:00:00Z',
        'details': {
          'actualDurationSeconds': 3600,
          'allowedDurationSeconds': 3600,
          'overdueDurationSeconds': 0,
          'wasOverdue': false,
        }
      };

      final model = AttendanceEventModel.fromJson(json);
      expect(
        model.subtitleDetail,
        equals('Break duration: 1 hour'),
      );
    });

    test('Custom 30-minute policy overdue break (6,660s actual, 1,800s allowed, 4,860s overdue)', () {
      final json = {
        'id': 103,
        'uuid': 'evt-103',
        'type': 'BREAK_ENDED',
        'title': 'Break Ended',
        'occurred_at': '2026-08-01T15:43:00Z',
        'details': {
          'actualDurationSeconds': 6660,
          'allowedDurationSeconds': 1800,
          'overdueDurationSeconds': 4860,
          'wasOverdue': true,
        }
      };

      final model = AttendanceEventModel.fromJson(json);
      expect(
        model.subtitleDetail,
        equals('Break duration: 1 hour 51 minutes\nOverdue by: 1 hour 21 minutes'),
      );
    });

    test('Fallback parsing when only startedAt and endedAt exist', () {
      final json = {
        'id': 104,
        'uuid': 'evt-104',
        'type': 'BREAK_ENDED',
        'title': 'Break Ended',
        'occurred_at': '2026-08-01T15:43:00Z',
        'details': {
          'startedAt': '2026-08-01T13:52:00Z',
          'endedAt': '2026-08-01T15:43:00Z',
          'allowed_duration_minutes': 60,
        }
      };

      final model = AttendanceEventModel.fromJson(json);
      expect(
        model.subtitleDetail,
        equals('Break duration: 1 hour 51 minutes\nOverdue by: 51 minutes'),
      );
    });
  });
}
