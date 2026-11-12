import 'dart:io';
import 'package:dio/dio.dart';
import '../models/maintenance_request_model.dart';
import '../models/material_request_model.dart';

class MaintenanceRequestRepository {
  final Dio _dio;

  MaintenanceRequestRepository(this._dio);

  /// Get list of maintenance requests.
  Future<List<MaintenanceRequestModel>> getRequests({String? status, String? search}) async {
    try {
      final response = await _dio.get(
        '/me/maintenance/requests',
        queryParameters: {
          if (status != null && status.isNotEmpty) 'status': status,
          if (search != null && search.isNotEmpty) 'search': search,
        },
      );

      final data = response.data;
      if (data is Map && data['success'] == true && data['data'] is List) {
        return (data['data'] as List)
            .map((e) => MaintenanceRequestModel.fromJson(e as Map<String, dynamic>))
            .toList();
      }
      return [];
    } catch (e) {
      rethrow;
    }
  }

  /// Get single maintenance request detail.
  Future<MaintenanceRequestModel> getRequestDetail(int id) async {
    try {
      final response = await _dio.get('/me/maintenance/requests/$id');
      final data = response.data;
      if (data is Map && data['success'] == true && data['data'] is Map) {
        return MaintenanceRequestModel.fromJson(data['data'] as Map<String, dynamic>);
      }
      throw Exception('Failed to load maintenance request detail.');
    } catch (e) {
      rethrow;
    }
  }

  /// Update request status & notes.
  Future<bool> updateRequestStatus({
    required int id,
    required String status,
    String? notes,
    double? cost,
  }) async {
    try {
      final response = await _dio.post(
        '/me/maintenance/requests/$id/status',
        data: {
          'status': status,
          if (notes != null && notes.isNotEmpty) 'notes': notes,
          if (cost != null) 'cost': cost,
        },
      );

      final data = response.data;
      return data is Map && data['success'] == true;
    } catch (e) {
      rethrow;
    }
  }

  /// Upload before / after photo.
  Future<bool> uploadPhoto({
    required int id,
    required File photoFile,
    required String photoType,
    String? description,
  }) async {
    try {
      final fileName = photoFile.path.split('/').last;
      final formData = FormData.fromMap({
        'photo': await MultipartFile.fromFile(photoFile.path, filename: fileName),
        'photo_type': photoType,
        if (description != null && description.isNotEmpty) 'description': description,
      });

      final response = await _dio.post(
        '/me/maintenance/requests/$id/photos',
        data: formData,
      );

      final data = response.data;
      return data is Map && data['success'] == true;
    } catch (e) {
      rethrow;
    }
  }

  /// Fetch active inventory catalog items.
  Future<List<InventoryItem>> getInventoryCatalog({String? search}) async {
    try {
      final response = await _dio.get(
        '/inventory/items',
        queryParameters: {
          if (search != null && search.isNotEmpty) 'search': search,
        },
      );

      final data = response.data;
      if (data is Map && data['success'] == true && data['data'] is List) {
        return (data['data'] as List)
            .map((e) => InventoryItem.fromJson(e as Map<String, dynamic>))
            .toList();
      }
      return [];
    } catch (e) {
      rethrow;
    }
  }

  /// Submit material request.
  Future<bool> submitMaterialRequest({
    required int workOrderId,
    int? buildingId,
    int? unitId,
    String? notes,
    required List<Map<String, dynamic>> items,
  }) async {
    try {
      final response = await _dio.post(
        '/me/inventory/material-requests',
        data: {
          'work_order_id': workOrderId,
          if (buildingId != null) 'building_id': buildingId,
          if (unitId != null) 'unit_id': unitId,
          if (notes != null && notes.isNotEmpty) 'notes': notes,
          'items': items,
        },
      );

      final data = response.data;
      return data is Map && data['success'] == true;
    } catch (e) {
      rethrow;
    }
  }
}
