package net.hrapp.hr.service

import android.app.*
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import android.os.SystemClock
import android.util.Log
import androidx.core.app.NotificationCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import net.hrapp.hr.HeartMonitorApp
import net.hrapp.hr.MainActivity
import net.hrapp.hr.R
import net.hrapp.hr.alert.AlertManager
import net.hrapp.hr.api.ApiClient
import net.hrapp.hr.data.PreferencesManager

/**
 * Lightweight service running in Client mode.
 * Periodically polls the API for the latest heart rate value and performs threshold checks.
 */
class ClientMonitorService : Service() {

    companion object {
        private const val TAG = "ClientMonitorService"
        private const val NOTIFICATION_ID = 2002
        private const val WAKELOCK_TAG = "HeartMonitor::ClientWakeLock"

        // Intent action constants for service control
        const val ACTION_START = "net.hrapp.hr.action.CLIENT_START"
        const val ACTION_STOP = "net.hrapp.hr.action.CLIENT_STOP"

        // UI broadcast action constants
        const val ACTION_CONNECTIVITY_STATE = "net.hrapp.hr.action.CLIENT_CONNECTIVITY"
        const val EXTRA_CONNECTIVITY_TYPE = "connectivity_type"  // "stale", "api_error", "ok"
        const val EXTRA_STALE_SECONDS = "stale_seconds"

        // Polling interval (5 seconds)
        private const val POLL_INTERVAL_MS = 5_000L

        // Connectivity loss thresholds
        private const val API_FAILURE_ALERT_THRESHOLD = 3    // 3 consecutive API failures (5s*3 = 15s)
        private const val STALE_DETECT_THRESHOLD_S = 10      // Data considered "stale" after 10 seconds
    }

    private lateinit var alertManager: AlertManager
    private lateinit var prefs: PreferencesManager
    private var wakeLock: PowerManager.WakeLock? = null

    private val serviceScope = CoroutineScope(Dispatchers.Default + SupervisorJob())
    private var pollingJob: Job? = null
    private var isMonitoringStarted: Boolean = false
    private var lastHeartRate: Int? = null

    // Connectivity loss tracking
    private var consecutiveApiFailures: Int = 0
    private var apiConnected: Boolean = true
    private var lastFreshDataTime: Long = 0L
    private var consecutiveStaleDetections: Int = 0

    override fun onCreate() {
        super.onCreate()
        Log.d(TAG, "Service onCreate")

        alertManager = AlertManager(this)
        prefs = PreferencesManager(this)

        acquireWakeLock()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        Log.d(TAG, "Service onStartCommand, action: ${intent?.action}")

        when (intent?.action) {
            ACTION_STOP -> {
                stopSelf()
                return START_NOT_STICKY
            }
            else -> {
                // Mode check: if in Server mode, redirect to correct service
                if (prefs.isServerMode) {
                    Log.w(TAG, "Not in client mode, redirecting to HeartMonitorService")
                    startForegroundCompat(createNotification(getString(R.string.client_service_starting)))
                    if (prefs.isServiceEnabled) {
                        startForegroundService(Intent(this, HeartMonitorService::class.java).apply {
                            action = HeartMonitorService.ACTION_START
                        })
                    }
                    stopSelf()
                    return START_NOT_STICKY
                }
                startForegroundCompat(createNotification(getString(R.string.client_service_starting)))
                startMonitoring()
            }
        }

        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onTaskRemoved(rootIntent: Intent?) {
        Log.w(TAG, "Task removed, scheduling restart...")

        // Restart the correct service based on mode
        val restartIntent = if (prefs.isServerMode) {
            Intent(this, HeartMonitorService::class.java).apply {
                action = HeartMonitorService.ACTION_START
            }
        } else {
            Intent(this, ClientMonitorService::class.java).apply {
                action = ACTION_START
            }
        }

        val pendingIntent = PendingIntent.getService(
            this,
            2,
            restartIntent,
            PendingIntent.FLAG_ONE_SHOT or PendingIntent.FLAG_IMMUTABLE
        )

        val alarmManager = getSystemService(Context.ALARM_SERVICE) as AlarmManager
        alarmManager.setExactAndAllowWhileIdle(
            AlarmManager.ELAPSED_REALTIME_WAKEUP,
            SystemClock.elapsedRealtime() + 1000,
            pendingIntent
        )

        super.onTaskRemoved(rootIntent)
    }

    override fun onDestroy() {
        Log.d(TAG, "Service onDestroy")

        isMonitoringStarted = false
        pollingJob?.cancel()
        pollingJob = null
        serviceScope.cancel()

        releaseWakeLock()

        super.onDestroy()
    }

    private fun startMonitoring() {
        if (isMonitoringStarted) {
            Log.d(TAG, "Monitoring already started, skipping...")
            return
        }

        // Stop the other service (to prevent conflicts)
        try {
            stopService(Intent(this, HeartMonitorService::class.java))
        } catch (e: Exception) {
            Log.d(TAG, "HeartMonitorService already stopped")
        }

        // Initialize ApiClient with saved URL
        ApiClient.setBaseUrl(prefs.apiUrl)

        Log.d(TAG, "Starting client monitoring with URL: ${ApiClient.getBaseUrl()}")
        isMonitoringStarted = true

        // Start polling job
        pollingJob = serviceScope.launch {
            while (isActive) {
                try {
                    val result = ApiClient.getLatestHeartRate()
                    result.onSuccess { response ->
                        // API access successful - reset failure counter
                        if (!apiConnected) {
                            apiConnected = true
                            alertManager.clearConnectivityState(AlertManager.ConnectivityAlertType.API_UNREACHABLE)
                            broadcastConnectivityState("ok")
                            Log.i(TAG, "API connection restored")
                        }
                        consecutiveApiFailures = 0

                        val heartRate = response.heartRate
                        val secondsAgo = response.secondsAgo

                        if (heartRate != null && heartRate > 0) {
                            lastHeartRate = heartRate
                            Log.d(TAG, "Got heart rate: $heartRate BPM, secondsAgo=$secondsAgo")

                            // Freshness check: if seconds_ago > 10s, consider data stale
                            val isStale = secondsAgo != null && secondsAgo > STALE_DETECT_THRESHOLD_S

                            if (isStale) {
                                consecutiveStaleDetections++
                                Log.w(TAG, "Data is stale: ${secondsAgo}s old (detections: $consecutiveStaleDetections/${prefs.alertMinExceedCount})")
                                updateNotification(getString(R.string.client_service_stale))
                                // Show popup immediately
                                broadcastConnectivityState("stale", secondsAgo ?: 0)
                                // Alert only when exceed count threshold is met
                                if (consecutiveStaleDetections >= prefs.alertMinExceedCount) {
                                    alertManager.checkConnectivityAlert(AlertManager.ConnectivityAlertType.DATA_STALE)
                                }
                            } else {
                                if (consecutiveStaleDetections > 0) {
                                    consecutiveStaleDetections = 0
                                    alertManager.clearConnectivityState(AlertManager.ConnectivityAlertType.DATA_STALE)
                                    broadcastConnectivityState("ok")
                                }
                                lastFreshDataTime = System.currentTimeMillis()
                                updateNotification(getString(R.string.client_service_hr, heartRate))

                                // Check thresholds and alert
                                alertManager.checkAndAlert(
                                    heartRate = heartRate,
                                    minThreshold = prefs.minHeartRateThreshold,
                                    maxThreshold = prefs.maxHeartRateThreshold
                                )
                            }
                        } else {
                            Log.d(TAG, "No heart rate data available")
                            updateNotification(getString(R.string.client_service_waiting))

                            // No HR but API reachable - increment stale counter
                            if (lastFreshDataTime > 0) {
                                val staleSec = ((System.currentTimeMillis() - lastFreshDataTime) / 1000).toInt()
                                if (staleSec > STALE_DETECT_THRESHOLD_S) {
                                    consecutiveStaleDetections++
                                    Log.w(TAG, "No HR data, stale: ${staleSec}s (detections: $consecutiveStaleDetections/${prefs.alertMinExceedCount})")
                                    updateNotification(getString(R.string.client_service_stale))
                                    broadcastConnectivityState("stale", staleSec)
                                    if (consecutiveStaleDetections >= prefs.alertMinExceedCount) {
                                        alertManager.checkConnectivityAlert(AlertManager.ConnectivityAlertType.DATA_STALE)
                                    }
                                }
                            }
                        }
                    }.onFailure { e ->
                        Log.e(TAG, "Failed to get heart rate: ${e.message}")
                        consecutiveApiFailures++
                        Log.w(TAG, "API failure (consecutive: $consecutiveApiFailures)")
                        updateNotification(getString(R.string.client_service_error))

                        // Alert and popup if consecutive failure threshold exceeded
                        if (consecutiveApiFailures >= API_FAILURE_ALERT_THRESHOLD) {
                            if (apiConnected) {
                                apiConnected = false
                                broadcastConnectivityState("api_error")
                            }
                            alertManager.checkConnectivityAlert(AlertManager.ConnectivityAlertType.API_UNREACHABLE)
                        }
                    }
                } catch (e: Exception) {
                    Log.e(TAG, "Polling error: ${e.message}", e)
                }

                delay(POLL_INTERVAL_MS)
            }
        }
    }

    /**
     * Android 14+ (API 34) puts time limits on dataSync type foreground services.
     * We use specialUse type to prevent crashes.
     */
    private fun startForegroundCompat(notification: Notification) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    private fun createNotification(text: String): Notification {
        val pendingIntent = PendingIntent.getActivity(
            this,
            0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val stopIntent = PendingIntent.getService(
            this,
            1,
            Intent(this, ClientMonitorService::class.java).apply { action = ACTION_STOP },
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        return NotificationCompat.Builder(this, HeartMonitorApp.NOTIFICATION_CHANNEL_ID)
            .setContentTitle(getString(R.string.client_service_title))
            .setContentText(text)
            .setSmallIcon(android.R.drawable.ic_menu_view)
            .setContentIntent(pendingIntent)
            .addAction(android.R.drawable.ic_media_pause, getString(R.string.dashboard_btn_stop), stopIntent)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .build()
    }

    private fun updateNotification(text: String) {
        val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        notificationManager.notify(NOTIFICATION_ID, createNotification(text))
    }

    private fun broadcastConnectivityState(type: String, staleSeconds: Int = 0) {
        sendBroadcast(Intent(ACTION_CONNECTIVITY_STATE).apply {
            setPackage(packageName)
            putExtra(EXTRA_CONNECTIVITY_TYPE, type)
            if (staleSeconds > 0) putExtra(EXTRA_STALE_SECONDS, staleSeconds)
        })
    }

    private fun acquireWakeLock() {
        val powerManager = getSystemService(Context.POWER_SERVICE) as PowerManager
        wakeLock = powerManager.newWakeLock(
            PowerManager.PARTIAL_WAKE_LOCK,
            WAKELOCK_TAG
        ).apply {
            acquire(10 * 60 * 60 * 1000L) // 10 hours
        }
        Log.d(TAG, "WakeLock acquired")
    }

    private fun releaseWakeLock() {
        wakeLock?.let {
            if (it.isHeld) {
                it.release()
                Log.d(TAG, "WakeLock released")
            }
        }
        wakeLock = null
    }
}
