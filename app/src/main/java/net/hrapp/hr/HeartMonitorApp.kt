package net.hrapp.hr

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import com.google.firebase.FirebaseApp
import com.google.firebase.crashlytics.FirebaseCrashlytics
import net.hrapp.hr.service.ServiceHealthWorker

class HeartMonitorApp : Application() {

    companion object {
        const val NOTIFICATION_CHANNEL_ID = "heart_monitor_channel"
    }

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
        ServiceHealthWorker.schedule(this)

        enableCrashReporting()
    }

    /**
     * Firebase initialises itself from resources the Google Services plugin
     * generates out of google-services.json. That file is gitignored, and the
     * plugin is now applied only when it is present, so on a build without it
     * there is no FirebaseApp and getInstance() throws IllegalStateException -
     * from onCreate, which means the app dies on launch. Checking first makes
     * crash reporting the optional extra it always was.
     */
    private fun enableCrashReporting() {
        if (FirebaseApp.getApps(this).isEmpty()) return
        FirebaseCrashlytics.getInstance().setCrashlyticsCollectionEnabled(true)
    }

    private fun createNotificationChannel() {
        val channel = NotificationChannel(
            NOTIFICATION_CHANNEL_ID,
            getString(R.string.notification_channel_name),
            NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = getString(R.string.notification_channel_description)
            setShowBadge(false)
        }

        val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        notificationManager.createNotificationChannel(channel)
    }
}
