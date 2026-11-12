import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/network/api_client.dart';
import '../models/attendance_request_model.dart';
import '../models/attendance_claim_model.dart';
import '../models/attendance_policy_setting_model.dart';
import '../repositories/requests_repository.dart';

final requestsRepositoryProvider = Provider<RequestsRepository>((ref) {
  return RequestsRepository(ApiClient());
});

class RequestsState {
  final bool isLoading;
  final bool isSubmitting;
  final String? error;
  final String? successMessage;
  final AttendancePolicySettingModel? settings;
  final List<AttendanceRequestModel> requests;
  final List<AttendanceClaimModel> claims;

  RequestsState({
    this.isLoading = false,
    this.isSubmitting = false,
    this.error,
    this.successMessage,
    this.settings,
    this.requests = const [],
    this.claims = const [],
  });

  RequestsState copyWith({
    bool? isLoading,
    bool? isSubmitting,
    String? error,
    String? successMessage,
    AttendancePolicySettingModel? settings,
    List<AttendanceRequestModel>? requests,
    List<AttendanceClaimModel>? claims,
  }) {
    return RequestsState(
      isLoading: isLoading ?? this.isLoading,
      isSubmitting: isSubmitting ?? this.isSubmitting,
      error: error,
      successMessage: successMessage,
      settings: settings ?? this.settings,
      requests: requests ?? this.requests,
      claims: claims ?? this.claims,
    );
  }
}

class RequestsNotifier extends StateNotifier<RequestsState> {
  final RequestsRepository _repository;

  RequestsNotifier(this._repository) : super(RequestsState());

  Future<void> loadInitialData() async {
    state = state.copyWith(isLoading: true, error: null);
    try {
      final settings = await _repository.getPolicySettings();
      final requests = await _repository.getMyRequests();
      final claims = await _repository.getMyClaims();
      state = state.copyWith(
        isLoading: false,
        settings: settings,
        requests: requests,
        claims: claims,
      );
    } catch (e) {
      state = state.copyWith(isLoading: false, error: e.toString());
    }
  }

  Future<bool> submitLateArrivalOrHalfDay({
    required String date,
    required String requestType,
    int? delayMinutes,
    String? expectedArrivalAt,
    required String reasonCode,
    String? description,
  }) async {
    state = state.copyWith(isSubmitting: true, error: null);
    try {
      await _repository.createRequest(
        date: date,
        requestType: requestType,
        delayMinutes: delayMinutes,
        expectedArrivalAt: expectedArrivalAt,
        reasonCode: reasonCode,
        description: description,
      );
      final requests = await _repository.getMyRequests();
      state = state.copyWith(
        isSubmitting: false,
        requests: requests,
        successMessage: 'Request submitted successfully.',
      );
      return true;
    } catch (e) {
      state = state.copyWith(isSubmitting: false, error: _parseError(e));
      return false;
    }
  }

  Future<bool> submitClaim({
    required String claimType,
    required String date,
    int? attendanceRecordId,
    String? requestedCheckInAt,
    String? requestedCheckOutAt,
    required String reasonCode,
    required String description,
  }) async {
    state = state.copyWith(isSubmitting: true, error: null);
    try {
      await _repository.createClaim(
        claimType: claimType,
        date: date,
        attendanceRecordId: attendanceRecordId,
        requestedCheckInAt: requestedCheckInAt,
        requestedCheckOutAt: requestedCheckOutAt,
        reasonCode: reasonCode,
        description: description,
      );
      final claims = await _repository.getMyClaims();
      state = state.copyWith(
        isSubmitting: false,
        claims: claims,
        successMessage: 'Attendance claim submitted successfully.',
      );
      return true;
    } catch (e) {
      state = state.copyWith(isSubmitting: false, error: _parseError(e));
      return false;
    }
  }

  Future<bool> acknowledgeAndRespondRequest(String uuid, String responseText) async {
    state = state.copyWith(isSubmitting: true, error: null);
    try {
      await _repository.respondToRequest(uuid, responseText);
      final requests = await _repository.getMyRequests();
      state = state.copyWith(
        isSubmitting: false,
        requests: requests,
        successMessage: 'Response submitted to manager.',
      );
      return true;
    } catch (e) {
      state = state.copyWith(isSubmitting: false, error: _parseError(e));
      return false;
    }
  }

  Future<bool> acknowledgeAndRespondClaim(String uuid, String responseText) async {
    state = state.copyWith(isSubmitting: true, error: null);
    try {
      await _repository.respondToClaim(uuid, responseText);
      final claims = await _repository.getMyClaims();
      state = state.copyWith(
        isSubmitting: false,
        claims: claims,
        successMessage: 'Response submitted to manager.',
      );
      return true;
    } catch (e) {
      state = state.copyWith(isSubmitting: false, error: _parseError(e));
      return false;
    }
  }

  String _parseError(dynamic error) {
    if (error is Exception) {
      return error.toString().replaceAll('Exception: ', '');
    }
    return 'An unexpected error occurred.';
  }
}

final requestsProvider = StateNotifierProvider<RequestsNotifier, RequestsState>((ref) {
  final repository = ref.watch(requestsRepositoryProvider);
  return RequestsNotifier(repository);
});
