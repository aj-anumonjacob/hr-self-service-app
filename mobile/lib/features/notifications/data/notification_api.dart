import 'package:dio/dio.dart';
import '../../../core/network/api_client.dart';

class NotificationApi {
  /// GET /me/notifications
  Future<Response> getNotifications({
    int page = 1,
    int perPage = 20,
    bool? unreadOnly,
    String? type,
  }) async {
    return await apiClient.dio.get('/me/notifications', queryParameters: {
      'page': page,
      'per_page': perPage,
      if (unreadOnly == true) 'unread': true,
      if (type != null && type.isNotEmpty) 'type': type,
    });
  }

  /// GET /me/notifications/unread-count
  Future<Response> getUnreadCount() async {
    return await apiClient.dio.get('/me/notifications/unread-count');
  }

  /// POST /me/notifications/{uuid}/read
  Future<Response> markRead(String uuid) async {
    return await apiClient.dio.post('/me/notifications/$uuid/read');
  }

  /// POST /me/notifications/read-all
  Future<Response> markAllRead() async {
    return await apiClient.dio.post('/me/notifications/read-all');
  }

  /// POST /me/notifications/push-token
  Future<Response> registerPushToken({
    required String platformDeviceId,
    required String fcmToken,
  }) async {
    return await apiClient.dio.post('/me/notifications/push-token', data: {
      'platform_device_id': platformDeviceId,
      'fcm_token': fcmToken,
    });
  }
}
