import '../../../core/network/api_client.dart';
import '../models/attendance_request_model.dart';
import '../models/attendance_claim_model.dart';
import '../models/attendance_policy_setting_model.dart';

class RequestsRepository {
  final ApiClient _apiClient;

  RequestsRepository(this._apiClient);

  Future<AttendancePolicySettingModel> getPolicySettings() async {
    final response = await _apiClient.get('/attendance-policy-settings');
    return AttendancePolicySettingModel.fromJson(response.data['data'] as Map<String, dynamic>);
  }

  // ── Employee Endpoints ───────────────────────────────────────────────────

  Future<List<AttendanceRequestModel>> getMyRequests() async {
    final response = await _apiClient.get('/me/attendance-requests');
    final rawList = response.data['data'] as List<dynamic>? ?? [];
    return rawList.map((e) => AttendanceRequestModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<AttendanceRequestModel> createRequest({
    required String date,
    required String requestType,
    int? delayMinutes,
    String? expectedArrivalAt,
    required String reasonCode,
    String? description,
  }) async {
    final response = await _apiClient.post('/me/attendance-requests', data: {
      'date': date,
      'request_type': requestType,
      if (delayMinutes != null) 'delay_minutes': delayMinutes,
      if (expectedArrivalAt != null) 'expected_arrival_at': expectedArrivalAt,
      'reason_code': reasonCode,
      if (description != null) 'description': description,
    });
    return AttendanceRequestModel.fromJson(response.data['data'] as Map<String, dynamic>);
  }

  Future<AttendanceRequestModel> respondToRequest(String uuid, String responseText) async {
    final response = await _apiClient.post('/me/attendance-requests/$uuid/respond', data: {
      'response': responseText,
    });
    return AttendanceRequestModel.fromJson(response.data['data'] as Map<String, dynamic>);
  }

  Future<void> cancelRequest(String uuid) async {
    await _apiClient.post('/me/attendance-requests/$uuid/cancel');
  }

  Future<List<AttendanceClaimModel>> getMyClaims() async {
    final response = await _apiClient.get('/me/attendance-claims');
    final rawList = response.data['data'] as List<dynamic>? ?? [];
    return rawList.map((e) => AttendanceClaimModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<AttendanceClaimModel> createClaim({
    required String claimType,
    required String date,
    int? attendanceRecordId,
    String? requestedCheckInAt,
    String? requestedCheckOutAt,
    required String reasonCode,
    required String description,
  }) async {
    final response = await _apiClient.post('/me/attendance-claims', data: {
      'claim_type': claimType,
      'date': date,
      if (attendanceRecordId != null) 'attendance_record_id': attendanceRecordId,
      if (requestedCheckInAt != null) 'requested_check_in_at': requestedCheckInAt,
      if (requestedCheckOutAt != null) 'requested_check_out_at': requestedCheckOutAt,
      'reason_code': reasonCode,
      'description': description,
    });
    return AttendanceClaimModel.fromJson(response.data['data'] as Map<String, dynamic>);
  }

  Future<AttendanceClaimModel> respondToClaim(String uuid, String responseText) async {
    final response = await _apiClient.post('/me/attendance-claims/$uuid/respond', data: {
      'response': responseText,
    });
    return AttendanceClaimModel.fromJson(response.data['data'] as Map<String, dynamic>);
  }

  Future<void> cancelClaim(String uuid) async {
    await _apiClient.post('/me/attendance-claims/$uuid/cancel');
  }

  // ── Manager Endpoints ────────────────────────────────────────────────────

  Future<List<AttendanceRequestModel>> getManagerRequests({String? status}) async {
    final response = await _apiClient.get('/manager/attendance-requests', queryParameters: {
      if (status != null && status != 'all') 'status': status,
    });
    final rawList = response.data['data'] as List<dynamic>? ?? [];
    return rawList.map((e) => AttendanceRequestModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<void> approveRequest(String uuid, {String? comment}) async {
    await _apiClient.post('/manager/attendance-requests/$uuid/approve', data: {
      if (comment != null) 'comment': comment,
    });
  }

  Future<void> rejectRequest(String uuid, String comment) async {
    await _apiClient.post('/manager/attendance-requests/$uuid/reject', data: {
      'comment': comment,
    });
  }

  Future<void> requestExplanationForRequest(String uuid, String comment) async {
    await _apiClient.post('/manager/attendance-requests/$uuid/request-explanation', data: {
      'comment': comment,
    });
  }

  Future<List<AttendanceClaimModel>> getManagerClaims({String? status}) async {
    final response = await _apiClient.get('/manager/attendance-claims', queryParameters: {
      if (status != null && status != 'all') 'status': status,
    });
    final rawList = response.data['data'] as List<dynamic>? ?? [];
    return rawList.map((e) => AttendanceClaimModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<void> approveClaim(String uuid, {String? comment}) async {
    await _apiClient.post('/manager/attendance-claims/$uuid/approve', data: {
      if (comment != null) 'comment': comment,
    });
  }

  Future<void> rejectClaim(String uuid, String comment) async {
    await _apiClient.post('/manager/attendance-claims/$uuid/reject', data: {
      'comment': comment,
    });
  }

  Future<void> requestExplanationForClaim(String uuid, String comment) async {
    await _apiClient.post('/manager/attendance-claims/$uuid/request-explanation', data: {
      'comment': comment,
    });
  }
}
