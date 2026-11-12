class EmployeeModel {
  final String uuid;
  final String employeeNumber;
  final String fullName;
  final String email;
  final String? designation;
  final String? department;
  final String? company;
  final String? mobileNumber;
  final String? photoUrl;

  EmployeeModel({
    required this.uuid,
    required this.employeeNumber,
    required this.fullName,
    required this.email,
    this.designation,
    this.department,
    this.company,
    this.mobileNumber,
    this.photoUrl,
  });

  bool get isManager => email.toLowerCase().contains('manager');

  factory EmployeeModel.fromJson(Map<String, dynamic> json) {
    return EmployeeModel(
      uuid: json['uuid'] as String? ?? json['id']?.toString() ?? '',
      employeeNumber: json['employee_number'] as String? ?? json['employee_code'] as String? ?? '',
      fullName: json['full_name'] as String? ?? json['name'] as String? ?? 'Employee',
      email: json['email'] as String? ?? '',
      designation: json['designation'] as String? ?? json['position_title'] as String?,
      department: json['department'] as String?,
      company: json['company'] as String?,
      mobileNumber: json['mobile_number'] as String? ?? json['phone'] as String?,
      photoUrl: json['photo_url'] as String?,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'uuid': uuid,
      'employee_number': employeeNumber,
      'full_name': fullName,
      'email': email,
      'designation': designation,
      'department': department,
      'company': company,
      'mobile_number': mobileNumber,
      'photo_url': photoUrl,
    };
  }
}
