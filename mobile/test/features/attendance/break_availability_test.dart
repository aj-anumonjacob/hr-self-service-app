import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/attendance/models/break_models.dart';

void main() {
  group('BreakStateModel & displayStatusLabel Tests', () {
    test('Parses explicit API fields and maps displayStatusLabel for limit_reached', () {
      final json = {
        'policy': {
          'id': 1,
          'name': 'Flexible Break',
          'type': 'flexible',
          'allowedDurationMinutes': 60,
          'maximumBreaksPerShift': 1,
          'active': true
        },
        'usedBreakCount': 1,
        'remainingBreakCount': 0,
        'canStartBreak': false,
        'availabilityStatus': 'limit_reached',
        'restrictionCode': 'MAX_BREAKS_REACHED',
        'restrictionMessage': 'Maximum break count reached for this shift.'
      };

      final model = BreakStateModel.fromJson(json);

      expect(model.usedBreakCount, equals(1));
      expect(model.remainingBreaksCount, equals(0));
      expect(model.canStartBreak, isFalse);
      expect(model.availabilityStatus, equals('limit_reached'));
      expect(model.displayStatusLabel, equals('Limit Reached'));
      expect(model.restrictionMessage, equals('Maximum break count reached for this shift.'));
    });

    test('Maps all availabilityStatus labels correctly', () {
      expect(BreakStateModel(availabilityStatus: 'available').displayStatusLabel, equals('Available'));
      expect(BreakStateModel(availabilityStatus: 'on_break').displayStatusLabel, equals('On Break'));
      expect(BreakStateModel(availabilityStatus: 'limit_reached').displayStatusLabel, equals('Limit Reached'));
      expect(BreakStateModel(availabilityStatus: 'not_checked_in').displayStatusLabel, equals('Check In Required'));
      expect(BreakStateModel(availabilityStatus: 'attendance_closed').displayStatusLabel, equals('Attendance Closed'));
      expect(BreakStateModel(availabilityStatus: 'outside_allowed_time').displayStatusLabel, equals('Not Available Now'));
      expect(BreakStateModel(availabilityStatus: 'approval_required').displayStatusLabel, equals('Approval Required'));
      expect(BreakStateModel(availabilityStatus: 'policy_inactive').displayStatusLabel, equals('Policy Inactive'));
      expect(BreakStateModel(availabilityStatus: 'unknown_status').displayStatusLabel, equals('Unavailable'));
    });

    test('Zero remaining breaks does NOT return Available even if policy.active is true', () {
      final model = BreakStateModel(
        effectivePolicy: BreakPolicyModel(
          uuid: 'p-1',
          name: 'Flexible Break',
          policyType: 'flexible',
          allowedDurationMinutes: 60,
          maxBreaksPerShift: 1,
          isPaid: false,
          requiresApproval: false,
          active: true,
        ),
        usedBreakCount: 1,
        remainingBreaksCount: 0,
        canStartBreak: false,
        availabilityStatus: 'limit_reached',
        restrictionCode: 'MAX_BREAKS_REACHED',
        restrictionMessage: 'Maximum break count reached for this shift.',
      );

      expect(model.canStartBreak, isFalse);
      expect(model.displayStatusLabel, equals('Limit Reached'));
      expect(model.displayStatusLabel, isNot(equals('Available')));
    });
  });
}
