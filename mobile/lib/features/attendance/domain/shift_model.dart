class ShiftModel {
  final int id;
  final String uuid;
  final String name;
  final String code;
  final String startTime;
  final String endTime;
  final int gracePeriodMinutes;
  final int minWorkingMinutes;

  ShiftModel({
    required this.id,
    required this.uuid,
    required this.name,
    required this.code,
    required this.startTime,
    required this.endTime,
    required this.gracePeriodMinutes,
    required this.minWorkingMinutes,
  });

  factory ShiftModel.fromJson(Map<String, dynamic> json) {
    return ShiftModel(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      uuid: json['uuid']?.toString() ?? '',
      name: json['name']?.toString() ?? 'Default Shift',
      code: json['code']?.toString() ?? 'GEN',
      startTime: json['start_time']?.toString() ?? '08:00',
      endTime: json['end_time']?.toString() ?? '17:00',
      gracePeriodMinutes: json['grace_period_minutes'] is int
          ? json['grace_period_minutes']
          : int.tryParse(json['grace_period_minutes']?.toString() ?? '15') ?? 15,
      minWorkingMinutes: json['min_working_minutes'] is int
          ? json['min_working_minutes']
          : int.tryParse(json['min_working_minutes']?.toString() ?? '480') ?? 480,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'uuid': uuid,
        'name': name,
        'code': code,
        'start_time': startTime,
        'end_time': endTime,
        'grace_period_minutes': gracePeriodMinutes,
        'min_working_minutes': minWorkingMinutes,
      };
}
