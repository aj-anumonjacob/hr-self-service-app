import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../data/attendance_repository.dart';
import '../models/missed_attendance_model.dart';

final _attendanceRepositoryProvider = Provider<AttendanceRepository>((ref) {
  return AttendanceRepository();
});

/// Missed check-in/check-out events for the dashboard's quick-claim card.
/// Call `ref.invalidate(missedAttendanceProvider)` after a claim is submitted
/// so the list refreshes and the claimed item drops off.
final missedAttendanceProvider = FutureProvider.autoDispose<List<MissedAttendanceModel>>((ref) async {
  final repository = ref.watch(_attendanceRepositoryProvider);
  return repository.getMissedAttendance();
});
