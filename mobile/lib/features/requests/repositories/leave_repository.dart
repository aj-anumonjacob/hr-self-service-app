import 'package:dio/dio.dart';
import '../../../core/network/api_client.dart';
import '../models/leave_type_model.dart';
import '../models/leave_balance_model.dart';
import '../models/leave_request_model.dart';

class LeaveRepository {
  final ApiClient _apiClient;

  LeaveRepository({ApiClient? apiClient}) : _apiClient = apiClient ?? ApiClient();

  Future<List<LeaveTypeModel>> fetchLeaveTypes() async {
    try {
      final response = await _apiClient.get('/leave-types');
      if (response.statusCode == 200 && response.data['success'] == true) {
        final List list = response.data['data'] ?? [];
        return list.map((item) => LeaveTypeModel.fromJson(item)).toList();
      }
      return [];
    } catch (e) {
      throw Exception('Failed to fetch leave types: $e');
    }
  }

  Future<List<LeaveBalanceModel>> fetchBalances(int year) async {
    try {
      final response = await _apiClient.get(
        '/me/leave-balances',
        queryParameters: {'year': year},
      );
      if (response.statusCode == 200 && response.data['success'] == true) {
        final List list = response.data['data'] ?? [];
        return list.map((item) => LeaveBalanceModel.fromJson(item)).toList();
      }
      return [];
    } catch (e) {
      throw Exception('Failed to fetch leave balances: $e');
    }
  }

  Future<List<LeaveRequestModel>> fetchRequests({int? year}) async {
    try {
      final queryParams = <String, dynamic>{};
      if (year != null) {
        queryParams['year'] = year;
      }
      final response = await _apiClient.get(
        '/me/leave-requests',
        queryParameters: queryParams,
      );
      if (response.statusCode == 200 && response.data['success'] == true) {
        final List list = response.data['data'] ?? [];
        return list.map((item) => LeaveRequestModel.fromJson(item)).toList();
      }
      return [];
    } catch (e) {
      throw Exception('Failed to fetch leave requests: $e');
    }
  }

  Future<LeaveRequestModel> submitRequest({
    required int leaveTypeId,
    required String dateFrom,
    required String dateTo,
    String? reason,
    String? filePath,
  }) async {
    try {
      final Map<String, dynamic> map = {
        'leave_type_id': leaveTypeId,
        'date_from': dateFrom,
        'date_to': dateTo,
        if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim(),
      };

      if (filePath != null && filePath.isNotEmpty) {
        final fileName = filePath.split('/').last;
        map['attachment'] = await MultipartFile.fromFile(
          filePath,
          filename: fileName,
        );
      }

      final formData = FormData.fromMap(map);

      final response = await _apiClient.post(
        '/me/leave-requests',
        data: formData,
      );

      if (response.statusCode == 200 || response.statusCode == 201) {
        if (response.data['success'] == true && response.data['data'] != null) {
          return LeaveRequestModel.fromJson(response.data['data']);
        }
      }
      throw response.data['message'] ?? 'Failed to submit leave request.';
    } on DioException catch (e) {
      String msg = 'Server error during submission.';
      if (e.response?.data != null) {
        if (e.response?.data['message'] != null) {
          msg = e.response!.data['message'].toString();
        } else if (e.response?.data['errors'] != null) {
          final errors = e.response!.data['errors'];
          if (errors is Map && errors.isNotEmpty) {
            msg = errors.values.first.first.toString();
          }
        }
      }
      throw Exception(msg);
    } catch (e) {
      throw Exception(e.toString().replaceAll('Exception: ', ''));
    }
  }
}
