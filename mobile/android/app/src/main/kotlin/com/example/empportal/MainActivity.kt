package com.example.empportal

import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build
import android.os.Bundle
import io.flutter.embedding.android.FlutterFragmentActivity

// FlutterFragmentActivity (not the default FlutterActivity) is required by
// the local_auth plugin's biometric prompt (Android's BiometricPrompt needs
// a FragmentActivity host).
class MainActivity : FlutterFragmentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        createNotificationChannel()
    }

    // Firebase Cloud Messaging notifications target this channel (see
    // AndroidManifest.xml's com.google.firebase.messaging.default_notification_channel_id
    // meta-data) — the channel must actually exist or Android silently drops
    // the notification on API 26+, so it's created here at app startup
    // rather than left to the OS's ad-hoc fallback channel.
    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                "attendance_app_notifications",
                "Attendance & Alerts",
                NotificationManager.IMPORTANCE_HIGH
            ).apply {
                description = "Check-in reminders, break alerts, and attendance updates."
            }
            val manager = getSystemService(NotificationManager::class.java)
            manager.createNotificationChannel(channel)
        }
    }
}
