import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'core/config/router.dart';
import 'core/config/theme.dart';
import 'core/config/env.dart';
import 'core/services/push_notification_service.dart';
import 'core/services/sync_engine.dart';
import 'core/database/legacy_queue_migrator.dart';
import 'core/utils/device_info.dart';
import 'core/utils/connectivity.dart';
import 'core/widgets/foreground_alert_overlay.dart';
import 'core/widgets/quick_unlock_gate.dart';

void main() async {
  Env.init(
    baseUrl: Env.defaultDevBaseUrl,
    environmentName: 'development',
  );
  await mainCommon();
}

Future<void> mainCommon() async {
  WidgetsFlutterBinding.ensureInitialized();
  await DeviceInfoUtil().init();
  ConnectivityService().init();

  // Bring forward any check-in/out/break actions queued by the previous
  // (SharedPreferences-based) offline queue before the sqflite-backed one
  // takes over, then start the sync engine that watches for connectivity.
  await migrateLegacySharedPreferencesQueue();
  SyncEngine().init();

  // Register the FCM background handler before runApp(). Wrapped because
  // Firebase.initializeApp() throws until google-services.json is added —
  // push notifications simply stay disabled until then, the app still runs.
  try {
    if (Firebase.apps.isEmpty) {
      await Firebase.initializeApp();
    }
    FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);
  } catch (_) {}

  runApp(const ProviderScope(child: MyApp()));
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'Demo Company Attendance',
      theme: AppTheme.lightTheme,
      routerConfig: appRouter,
      debugShowCheckedModeBanner: false,
      builder: (context, child) {
        return QuickUnlockGate(child: ForegroundAlertOverlay(child: child!));
      },
    );
  }
}
