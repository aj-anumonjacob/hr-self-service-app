class ReasonOption {
  final String code;
  final String label;

  ReasonOption({required this.code, required this.label});

  factory ReasonOption.fromJson(Map<String, dynamic> json) {
    return ReasonOption(
      code: json['code'] as String? ?? '',
      label: json['label'] as String? ?? '',
    );
  }
}

class AttendancePolicySettingModel {
  final int maxLateDelayMinutes;
  final int claimDescriptionMaxChars;
  final List<ReasonOption> lateReasonOptions;
  final List<ReasonOption> claimReasonOptions;

  AttendancePolicySettingModel({
    required this.maxLateDelayMinutes,
    required this.claimDescriptionMaxChars,
    required this.lateReasonOptions,
    required this.claimReasonOptions,
  });

  factory AttendancePolicySettingModel.fromJson(Map<String, dynamic> json) {
    final rawLate = json['late_reason_options'] as List<dynamic>? ?? [];
    final rawClaim = json['claim_reason_options'] as List<dynamic>? ?? [];

    return AttendancePolicySettingModel(
      maxLateDelayMinutes: (json['max_late_delay_minutes'] as num?)?.toInt() ?? 240,
      claimDescriptionMaxChars: (json['claim_description_max_chars'] as num?)?.toInt() ?? 500,
      lateReasonOptions: rawLate.map((e) => ReasonOption.fromJson(e as Map<String, dynamic>)).toList(),
      claimReasonOptions: rawClaim.map((e) => ReasonOption.fromJson(e as Map<String, dynamic>)).toList(),
    );
  }
}
