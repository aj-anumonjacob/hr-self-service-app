import '../models/notification_model.dart';
import 'notification_api.dart';

class NotificationRepository {
  final NotificationApi _api = NotificationApi();

  Future<Map<String, dynamic>> getNotifications({
    int page = 1,
    int perPage = 20,
    bool? unreadOnly,
  }) async {
    final response = await _api.getNotifications(
      page: page,
      perPage: perPage,
      unreadOnly: unreadOnly,
    );
    final data = response.data['data'] as Map<String, dynamic>;
    final rawItems = data['items'] as List<dynamic>? ?? [];
    final items = rawItems
        .map((e) => InAppNotificationModel.fromJson(e as Map<String, dynamic>))
        .toList();
    return {
      'items': items,
      'current_page': (data['current_page'] as num?)?.toInt() ?? page,
      'last_page': (data['last_page'] as num?)?.toInt() ?? 1,
      'total': (data['total'] as num?)?.toInt() ?? items.length,
    };
  }

  Future<int> getUnreadCount() async {
    final response = await _api.getUnreadCount();
    final data = response.data['data'] as Map<String, dynamic>;
    return (data['unread_count'] as num?)?.toInt() ?? 0;
  }

  Future<InAppNotificationModel> markRead(String uuid) async {
    final response = await _api.markRead(uuid);
    return InAppNotificationModel.fromJson(
      response.data['data'] as Map<String, dynamic>,
    );
  }

  Future<void> markAllRead() async {
    await _api.markAllRead();
  }
}
