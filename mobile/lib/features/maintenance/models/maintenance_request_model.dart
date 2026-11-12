import 'package:flutter/foundation.dart';
import 'material_request_model.dart';

@immutable
class MaintenanceRequestPhoto {
  final int id;
  final String photoType;
  final String fileName;
  final String filePath;
  final String? description;
  final dynamic uploadedBy;
  final String? createdAt;

  const MaintenanceRequestPhoto({
    required this.id,
    required this.photoType,
    required this.fileName,
    required this.filePath,
    this.description,
    this.uploadedBy,
    this.createdAt,
  });

  factory MaintenanceRequestPhoto.fromJson(Map<String, dynamic> json) {
    return MaintenanceRequestPhoto(
      id: (json['id'] as num? ?? 0).toInt(),
      photoType: json['photo_type']?.toString() ?? 'before',
      fileName: json['file_name']?.toString() ?? '',
      filePath: json['file_path']?.toString() ?? '',
      description: json['description']?.toString(),
      uploadedBy: json['uploaded_by'],
      createdAt: json['created_at']?.toString(),
    );
  }
}

@immutable
class MaintenanceRequestModel {
  final int id;
  final int companyId;
  final String? requestDate;
  final String category;
  final String priority;
  final String status;
  final double cost;
  final String? description;
  final String? notes;
  final String source;
  final int? buildingId;
  final int? unitId;
  final String buildingName;
  final String unitNumber;
  final String displayLocation;
  final String tenantName;
  final String tenantPhone;
  final String tenantEmail;
  final List<MaintenanceRequestPhoto> photos;
  final List<MaterialRequestHeader> materialRequests;
  final String? createdAt;
  final String? updatedAt;

  const MaintenanceRequestModel({
    required this.id,
    required this.companyId,
    this.requestDate,
    required this.category,
    required this.priority,
    required this.status,
    required this.cost,
    this.description,
    this.notes,
    required this.source,
    this.buildingId,
    this.unitId,
    required this.buildingName,
    required this.unitNumber,
    required this.displayLocation,
    required this.tenantName,
    required this.tenantPhone,
    required this.tenantEmail,
    required this.photos,
    required this.materialRequests,
    this.createdAt,
    this.updatedAt,
  });

  factory MaintenanceRequestModel.fromJson(Map<String, dynamic> json) {
    final loc = json['location'] as Map<String, dynamic>? ?? {};
    final tenant = json['tenant'] as Map<String, dynamic>? ?? {};
    
    final photoList = (json['photos'] as List<dynamic>?)
            ?.map((p) => MaintenanceRequestPhoto.fromJson(p as Map<String, dynamic>))
            .toList() ??
        [];

    final matReqList = (json['material_requests'] as List<dynamic>?)
            ?.map((m) => MaterialRequestHeader.fromJson(m as Map<String, dynamic>))
            .toList() ??
        [];

    return MaintenanceRequestModel(
      id: (json['id'] as num? ?? 0).toInt(),
      companyId: (json['company_id'] as num? ?? 1).toInt(),
      requestDate: json['request_date']?.toString(),
      category: json['category']?.toString() ?? 'General Maintenance',
      priority: json['priority']?.toString() ?? 'normal',
      status: json['status']?.toString() ?? 'pending',
      cost: double.tryParse(json['cost']?.toString() ?? '') ?? 0.0,
      description: json['description']?.toString(),
      notes: json['notes']?.toString(),
      source: json['source']?.toString() ?? 'internal',
      buildingId: (json['building_id'] as num?)?.toInt(),
      unitId: (json['unit_id'] as num?)?.toInt(),
      buildingName: loc['building_name']?.toString() ?? '',
      unitNumber: loc['unit_number']?.toString() ?? '',
      displayLocation: loc['display_location']?.toString() ?? 'General Location',
      tenantName: tenant['name']?.toString() ?? 'Tenant',
      tenantPhone: tenant['phone']?.toString() ?? '',
      tenantEmail: tenant['email']?.toString() ?? '',
      photos: photoList,
      materialRequests: matReqList,
      createdAt: json['created_at']?.toString(),
      updatedAt: json['updated_at']?.toString(),
    );
  }
}
