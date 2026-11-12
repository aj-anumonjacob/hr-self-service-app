import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/utils/duration_formatter.dart';

void main() {
  group('duration_formatter tests', () {
    test('Less than 60 seconds formats as "Less than 1 minute"', () {
      expect(formatDuration(0), equals('Less than 1 minute'));
      expect(formatDuration(30), equals('Less than 1 minute'));
      expect(formatDuration(59), equals('Less than 1 minute'));
    });

    test('Exactly 60 seconds formats as "1 minute"', () {
      expect(formatDuration(60), equals('1 minute'));
    });

    test('Multiple minutes format correctly', () {
      expect(formatDuration(120), equals('2 minutes'));
      expect(formatDuration(1500), equals('25 minutes'));
      expect(formatDuration(3540), equals('59 minutes'));
    });

    test('3,600 seconds formats as "1 hour"', () {
      expect(formatDuration(3600), equals('1 hour'));
    });

    test('3,660 seconds formats as "1 hour 1 minute"', () {
      expect(formatDuration(3660), equals('1 hour 1 minute'));
    });

    test('6,660 seconds formats as "1 hour 51 minutes"', () {
      expect(formatDuration(6660), equals('1 hour 51 minutes'));
    });

    test('7,200 seconds formats as "2 hours"', () {
      expect(formatDuration(7200), equals('2 hours'));
    });

    test('7,260 seconds formats as "2 hours 1 minute"', () {
      expect(formatDuration(7260), equals('2 hours 1 minute'));
    });

    test('7,320 seconds formats as "2 hours 2 minutes"', () {
      expect(formatDuration(7320), equals('2 hours 2 minutes'));
    });
  });
}
