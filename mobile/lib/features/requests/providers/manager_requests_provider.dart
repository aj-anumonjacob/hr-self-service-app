import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/attendance_request_model.dart';
import '../models/attendance_claim_model.dart';
import '../repositories/requests_repository.dart';
import 'requests_provider.dart';

class ManagerRequestsState {
  final bool isLoading;
  final bool isSubmitting;
  final String? error;
  final String? successMessage;
  final String selectedTab; // pending, needs_explanation, approved, rejected, all
  final List<AttendanceRequestModel> requests;
  final List<AttendanceClaimModel> claims;

  ManagerRequestsState({
    this.isLoading = false,
    this.isSubmitting = false,
    this.error,
    this.successMessage,
    this.selectedTab = 'pending',
    this.requests = const [],
    this.claims = const [],
  });

  ManagerRequestsState copyWith({
    bool? isLoading,
    bool? isSubmitting,
    String? error,
    String? successMessage,
    String? selectedTab,
    List<AttendanceRequestModel>? requests,
    List<AttendanceClaimModel>? claims,
  }) {
    return ManagerRequestsState(
      isLoading: isLoading ?? this.isLoading,
      isSubmitting: isSubmitting ?? this.isSubmitting,
      error: error,
      successMessage: successMessage,
      selectedTab: selectedTab ?? this.selectedTab,
      requests: requests ?? this.requests,
      claims: claims ?? this.claims,
    );
  }
}

class ManagerRequestsNotifier extends StateNotifier<ManagerRequestsState> {
  final RequestsRepository _repository;

  ManagerRequestsNotifier(this._repository) : super(ManagerRequestsState());

  Future<void> fetchRequests({String? tab}) async {
    final activeTab = tab ?? state.selectedTab;
    state = state.copyWith(isLoading: true, selectedTab: activeTab, error: null);

    try {
      final requests = await _repository.getManagerRequests(status: activeTab);
      final claims = await _repository.getManagerClaims(status: activeTab);
      state = state.copyWith(
        isLoading: false,
        requests: requests,
        claims: claims,
      );
    } catch (e) {
      state = state.copyWith(isLoading: false, error: e.toString());
    }
  }

  Future<bool> approveRequest(String uuid, {String? comment}) async {
    state = state.copyWith(isSubmitting: true, error: null);
    try {
      await _repository.approveRequest(uuid, comment: comment);
      await fetchRequests();
      state = state.copyWith(isSubmitting: false, successMessage: 'Request approved.');
      return true;
    } catch (e) {
      state = state.copyWith(isSubmitting: false, error: e.toString());
      return false;
    }
  }

  Future<bool> rejectRequest(String uuid, String comment) async {
    state = state.copyWith(isSubmitting: true, error: null);
    try {
      await _repository.rejectRequest(uuid, comment);
      await fetchRequests();
      state = state.copyWith(isSubmitting: false, successMessage: 'Request rejected.');
      return true;
    } catch (e) {
      state = state.copyWith(isSubmitting: false, error: e.toString());
      return false;
    }
  }

  Future<bool> requestExplanationForRequest(String uuid, String comment) async {
    state = state.copyWith(isSubmitting: true, error: null);
    try {
      await _repository.requestExplanationForRequest(uuid, comment);
      await fetchRequests();
      state = state.copyWith(isSubmitting: false, successMessage: 'Explanation requested from employee.');
      return true;
    } catch (e) {
      state = state.copyWith(isSubmitting: false, error: e.toString());
      return false;
    }
  }

  Future<bool> approveClaim(String uuid, {String? comment}) async {
    state = state.copyWith(isSubmitting: true, error: null);
    try {
      await _repository.approveClaim(uuid, comment: comment);
      await fetchRequests();
      state = state.copyWith(isSubmitting: false, successMessage: 'Claim approved and record updated.');
      return true;
    } catch (e) {
      state = state.copyWith(isSubmitting: false, error: e.toString());
      return false;
    }
  }

  Future<bool> rejectClaim(String uuid, String comment) async {
    state = state.copyWith(isSubmitting: true, error: null);
    try {
      await _repository.rejectClaim(uuid, comment);
      await fetchRequests();
      state = state.copyWith(isSubmitting: false, successMessage: 'Claim rejected.');
      return true;
    } catch (e) {
      state = state.copyWith(isSubmitting: false, error: e.toString());
      return false;
    }
  }

  Future<bool> requestExplanationForClaim(String uuid, String comment) async {
    state = state.copyWith(isSubmitting: true, error: null);
    try {
      await _repository.requestExplanationForClaim(uuid, comment);
      await fetchRequests();
      state = state.copyWith(isSubmitting: false, successMessage: 'Explanation requested from employee.');
      return true;
    } catch (e) {
      state = state.copyWith(isSubmitting: false, error: e.toString());
      return false;
    }
  }
}

final managerRequestsProvider = StateNotifierProvider<ManagerRequestsNotifier, ManagerRequestsState>((ref) {
  final repository = ref.watch(requestsRepositoryProvider);
  return ManagerRequestsNotifier(repository);
});
