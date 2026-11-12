import 'dart:developer' as developer;

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';

import '../utils/device_info.dart';
import '../../features/notifications/data/notification_api.dart';

/// Handles Firebase Cloud Messaging so the employee is alerted even when the
/// app is closed or backgrounded — the in-app `in_app_notifications` table
/// alone only surfaces alerts while the app is open and polling.
///
/// Safe to call even before a Firebase project/config file has been added:
/// every step is wrapped so a missing `google-services.json` degrades to
/// "no push", not a crash.
class PushNotificationService {
  static final PushNotificationService _instance =
      PushNotificationService._internal();
  factory PushNotificationService() => _instance;
  PushNotificationService._internal();

  final NotificationApi _api = NotificationApi();
  bool _initialized = false;

  /// Call once, after the user is authenticated (login, activation, or
  /// restored session) — token registration requires a logged-in user.
  ///
  /// [onForegroundMessage] is invoked whenever a push arrives while the app
  /// is in the foreground, so the caller can refresh the in-app notification
  /// list/badge immediately instead of waiting for the next poll tick.
  Future<void> initialize({void Function()? onForegroundMessage}) async {
    if (_initialized) return;

    try {
      if (Firebase.apps.isEmpty) {
        await Firebase.initializeApp();
      }
    } catch (e) {
      developer.log(
        'Firebase not configured yet (missing google-services.json?) — push notifications disabled: $e',
        name: 'PushNotificationService',
      );
      return;
    }

    _initialized = true;
    final messaging = FirebaseMessaging.instance;

    try {
      await messaging.requestPermission(alert: true, badge: true, sound: true);
    } catch (e) {
      developer.log('Notification permission request failed: $e', name: 'PushNotificationService');
    }

    await _registerCurrentToken(messaging);
    messaging.onTokenRefresh.listen((_) => _registerCurrentToken(messaging));

    FirebaseMessaging.onMessage.listen((message) {
      developer.log('Foreground push received: ${message.notification?.title}', name: 'PushNotificationService');
      onForegroundMessage?.call();
    });
  }

  Future<void> _registerCurrentToken(FirebaseMessaging messaging) async {
    try {
      final token = await messaging.getToken();
      if (token == null) return;

      final deviceInfo = await DeviceInfoUtil().getDeviceInfo();
      final platformDeviceId = deviceInfo['device_id'];
      if (platformDeviceId == null) return;

      await _api.registerPushToken(
        platformDeviceId: platformDeviceId,
        fcmToken: token,
      );
    } catch (e) {
      developer.log('Failed to register push token: $e', name: 'PushNotificationService');
    }
  }
}

/// Top-level background message handler — required by firebase_messaging to
/// run in a separate isolate when the app is terminated. Android already
/// auto-displays the system tray notification from the message's
/// "notification" block, so there is nothing further to do here; this only
/// exists as a hook for future background data-processing needs.
@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {}
