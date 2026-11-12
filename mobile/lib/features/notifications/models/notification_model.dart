class InAppNotificationModel {
  final int id;
  final String uuid;
  final String type;
  final String title;
  final String message;
  final Map<String, dynamic>? data;
  final bool isRead;
  final DateTime? readAt;
  final DateTime? sentAt;
  final DateTime createdAt;

  const InAppNotificationModel({
    required this.id,
    required this.uuid,
    required this.type,
    required this.title,
    required this.message,
    this.data,
    required this.isRead,
    this.readAt,
    this.sentAt,
    required this.createdAt,
  });

  factory InAppNotificationModel.fromJson(Map<String, dynamic> json) {
    return InAppNotificationModel(
      id: (json['id'] as num).toInt(),
      uuid: json['uuid'] as String? ?? '',
      type: json['type'] as String? ?? '',
      title: json['title'] as String? ?? '',
      message: json['message'] as String? ?? '',
      data: json['data'] as Map<String, dynamic>?,
      isRead: json['is_read'] as bool? ?? false,
      readAt: json['read_at'] != null ? DateTime.tryParse(json['read_at'] as String) : null,
      sentAt: json['sent_at'] != null ? DateTime.tryParse(json['sent_at'] as String) : null,
      createdAt: json['created_at'] != null
          ? (DateTime.tryParse(json['created_at'] as String) ?? DateTime.now())
          : DateTime.now(),
    );
  }

  InAppNotificationModel copyWith({bool? isRead, DateTime? readAt}) {
    return InAppNotificationModel(
      id: id,
      uuid: uuid,
      type: type,
      title: title,
      message: message,
      data: data,
      isRead: isRead ?? this.isRead,
      readAt: readAt ?? this.readAt,
      sentAt: sentAt,
      createdAt: createdAt,
    );
  }

  // Navigation helper: returns the navigation map from data if present
  Map<String, dynamic>? get navigation => data?['navigation'] as Map<String, dynamic>?;

  // Notification type constants
  static const String typeBreakEndingSoon      = 'BREAK_ENDING_SOON';
  static const String typeBreakExpired         = 'BREAK_EXPIRED';
  static const String typeBreakOverdueEmployee = 'BREAK_OVERDUE_EMPLOYEE';
  static const String typeBreakOverdueManager  = 'BREAK_OVERDUE_MANAGER';
  static const String typeBreakOverdueAdmin    = 'BREAK_OVERDUE_ADMIN';
}
