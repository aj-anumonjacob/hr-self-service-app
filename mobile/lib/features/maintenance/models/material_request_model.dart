import 'package:flutter/foundation.dart';

@immutable
class InventoryItem {
  final int id;
  final String itemCode;
  final String name;
  final String categoryName;
  final double unitPrice;

  const InventoryItem({
    required this.id,
    required this.itemCode,
    required this.name,
    required this.categoryName,
    required this.unitPrice,
  });

  factory InventoryItem.fromJson(Map<String, dynamic> json) {
    return InventoryItem(
      id: json['id'] as int? ?? 0,
      itemCode: json['item_code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      categoryName: json['category_name'] as String? ?? 'General Inventory',
      unitPrice: (json['unit_price'] as num?)?.toDouble() ?? 0.0,
    );
  }
}

@immutable
class MaterialRequestLine {
  final int id;
  final int itemId;
  final String itemName;
  final String itemCode;
  final double requestedQty;
  final double approvedQty;
  final String? lineNotes;

  const MaterialRequestLine({
    required this.id,
    required this.itemId,
    required this.itemName,
    required this.itemCode,
    required this.requestedQty,
    required this.approvedQty,
    this.lineNotes,
  });

  factory MaterialRequestLine.fromJson(Map<String, dynamic> json) {
    return MaterialRequestLine(
      id: json['id'] as int? ?? 0,
      itemId: json['item_id'] as int? ?? 0,
      itemName: json['item_name'] as String? ?? '',
      itemCode: json['item_code'] as String? ?? '',
      requestedQty: (json['requested_qty'] as num?)?.toDouble() ?? 0.0,
      approvedQty: (json['approved_qty'] as num?)?.toDouble() ?? 0.0,
      lineNotes: json['line_notes'] as String?,
    );
  }
}

@immutable
class MaterialRequestHeader {
  final int id;
  final String requestNo;
  final String? requestDate;
  final String status;
  final String? notes;
  final String requestedBy;
  final List<MaterialRequestLine> lines;
  final String? createdAt;

  const MaterialRequestHeader({
    required this.id,
    required this.requestNo,
    this.requestDate,
    required this.status,
    this.notes,
    required this.requestedBy,
    required this.lines,
    this.createdAt,
  });

  factory MaterialRequestHeader.fromJson(Map<String, dynamic> json) {
    final lineList = (json['lines'] as List<dynamic>?)
            ?.map((l) => MaterialRequestLine.fromJson(l as Map<String, dynamic>))
            .toList() ??
        [];

    return MaterialRequestHeader(
      id: json['id'] as int? ?? 0,
      requestNo: json['request_no'] as String? ?? '',
      requestDate: json['request_date'] as String?,
      status: json['status'] as String? ?? 'pending',
      notes: json['notes'] as String?,
      requestedBy: json['requested_by'] as String? ?? 'Technician',
      lines: lineList,
      createdAt: json['created_at'] as String?,
    );
  }
}
