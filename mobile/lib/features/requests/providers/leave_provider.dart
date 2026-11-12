import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/leave_type_model.dart';
import '../models/leave_balance_model.dart';
import '../models/leave_request_model.dart';
import '../repositories/leave_repository.dart';

class LeaveState {
  final bool isLoading;
  final bool isSubmitting;
  final int selectedYear;
  final List<LeaveTypeModel> leaveTypes;
  final List<LeaveBalanceModel> balances;
  final List<LeaveRequestModel> requests;
  final String? errorMessage;

  LeaveState({
    this.isLoading = false,
    this.isSubmitting = false,
    int? selectedYear,
    this.leaveTypes = const [],
    this.balances = const [],
    this.requests = const [],
    this.errorMessage,
  }) : selectedYear = selectedYear ?? DateTime.now().year;

  LeaveState copyWith({
    bool? isLoading,
    bool? isSubmitting,
    int? selectedYear,
    List<LeaveTypeModel>? leaveTypes,
    List<LeaveBalanceModel>? balances,
    List<LeaveRequestModel>? requests,
    String? errorMessage,
  }) {
    return LeaveState(
      isLoading: isLoading ?? this.isLoading,
      isSubmitting: isSubmitting ?? this.isSubmitting,
      selectedYear: selectedYear ?? this.selectedYear,
      leaveTypes: leaveTypes ?? this.leaveTypes,
      balances: balances ?? this.balances,
      requests: requests ?? this.requests,
      errorMessage: errorMessage,
    );
  }
}

class LeaveNotifier extends StateNotifier<LeaveState> {
  final LeaveRepository _repository;

  LeaveNotifier(this._repository) : super(LeaveState());

  Future<void> loadData({int? year}) async {
    final targetYear = year ?? state.selectedYear;
    state = state.copyWith(isLoading: true, selectedYear: targetYear, errorMessage: null);

    try {
      final types = await _repository.fetchLeaveTypes();
      final balances = await _repository.fetchBalances(targetYear);
      final requests = await _repository.fetchRequests(year: targetYear);

      state = state.copyWith(
        isLoading: false,
        leaveTypes: types,
        balances: balances,
        requests: requests,
      );
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: e.toString().replaceAll('Exception: ', ''),
      );
    }
  }

  Future<void> changeYear(int year) async {
    await loadData(year: year);
  }

  Future<bool> submitRequest({
    required int leaveTypeId,
    required String dateFrom,
    required String dateTo,
    String? reason,
    String? filePath,
  }) async {
    state = state.copyWith(isSubmitting: true, errorMessage: null);
    try {
      await _repository.submitRequest(
        leaveTypeId: leaveTypeId,
        dateFrom: dateFrom,
        dateTo: dateTo,
        reason: reason,
        filePath: filePath,
      );
      state = state.copyWith(isSubmitting: false);
      await loadData();
      return true;
    } catch (e) {
      final msg = e.toString().replaceAll('Exception: ', '');
      state = state.copyWith(isSubmitting: false, errorMessage: msg);
      return false;
    }
  }
}

final leaveRepositoryProvider = Provider<LeaveRepository>((ref) {
  return LeaveRepository();
});

final leaveProvider = StateNotifierProvider<LeaveNotifier, LeaveState>((ref) {
  final repo = ref.watch(leaveRepositoryProvider);
  return LeaveNotifier(repo);
});
