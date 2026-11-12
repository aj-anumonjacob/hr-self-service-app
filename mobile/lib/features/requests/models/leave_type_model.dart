class LeaveTypeModel {
  final int id;
  final String name;
  final String? code;
  final bool isPaid;
  final double annualQuotaDays;
  final bool requiresAttachment;
  final String? colorHex;

  LeaveTypeModel({
    required this.id,
    required this.name,
    this.code,
    required this.isPaid,
    required this.annualQuotaDays,
    required this.requiresAttachment,
    this.colorHex,
  });

  factory LeaveTypeModel.fromJson(Map<String, dynamic> json) {
    return LeaveTypeModel(
      id: json['id'] is int ? json['id'] : int.parse(json['id'].toString()),
      name: json['name'] ?? '',
      code: json['code'],
      isPaid: json['is_paid'] == 1 || json['is_paid'] == true,
      annualQuotaDays: double.tryParse(json['annual_quota_days'].toString()) ?? 30.0,
      requiresAttachment: json['requires_attachment'] == 1 || json['requires_attachment'] == true,
      colorHex: json['color_hex'],
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is LeaveTypeModel && runtimeType == other.runtimeType && id == other.id;

  @override
  int get hashCode => id.hashCode;
}
