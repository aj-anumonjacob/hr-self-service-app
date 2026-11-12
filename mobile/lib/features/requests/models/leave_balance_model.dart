class LeaveBalanceModel {
  final int id;
  final int employeeId;
  final int leaveTypeId;
  final String typeName;
  final String? typeCode;
  final String? colorHex;
  final int year;
  final double opening;
  final double accrued;
  final double taken;
  final double carried;
  final double closing;

  LeaveBalanceModel({
    required this.id,
    required this.employeeId,
    required this.leaveTypeId,
    required this.typeName,
    this.typeCode,
    this.colorHex,
    required this.year,
    required this.opening,
    required this.accrued,
    required this.taken,
    required this.carried,
    required this.closing,
  });

  factory LeaveBalanceModel.fromJson(Map<String, dynamic> json) {
    return LeaveBalanceModel(
      id: json['id'] is int ? json['id'] : int.parse(json['id'].toString()),
      employeeId: json['employee_id'] is int ? json['employee_id'] : int.parse(json['employee_id'].toString()),
      leaveTypeId: json['leave_type_id'] is int ? json['leave_type_id'] : int.parse(json['leave_type_id'].toString()),
      typeName: json['type_name'] ?? 'Leave',
      typeCode: json['type_code'],
      colorHex: json['color_hex'],
      year: json['year'] is int ? json['year'] : int.parse(json['year'].toString()),
      opening: double.tryParse(json['opening'].toString()) ?? 0.0,
      accrued: double.tryParse(json['accrued'].toString()) ?? 0.0,
      taken: double.tryParse(json['taken'].toString()) ?? 0.0,
      carried: double.tryParse(json['carried'].toString()) ?? 0.0,
      closing: double.tryParse(json['closing'].toString()) ?? 0.0,
    );
  }
}
