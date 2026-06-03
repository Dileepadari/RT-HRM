package net.hrapp.hr.util

import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.os.Build

object OemCompatibilityHelper {

    data class OemInstruction(
        val title: String,
        val steps: List<String>,
        val settingsIntent: Intent? = null
    )

    fun getManufacturer(): String = Build.MANUFACTURER.lowercase()

    fun getDeviceModel(): String = Build.MODEL

    fun needsSpecialSetup(): Boolean {
        val manufacturer = getManufacturer()
        return manufacturer.contains("xiaomi") ||
                manufacturer.contains("redmi") ||
                manufacturer.contains("poco") ||
                manufacturer.contains("samsung") ||
                manufacturer.contains("huawei") ||
                manufacturer.contains("honor") ||
                manufacturer.contains("oneplus") ||
                manufacturer.contains("oppo") ||
                manufacturer.contains("vivo") ||
                manufacturer.contains("realme")
    }

    fun getInstructions(): OemInstruction {
        val manufacturer = getManufacturer()

        return when {
            manufacturer.contains("xiaomi") ||
            manufacturer.contains("redmi") ||
            manufacturer.contains("poco") -> OemInstruction(
                title = "Xiaomi / Redmi / POCO Settings",
                steps = listOf(
                    "1. Settings → Apps → Manage apps → Heart Monitor",
                    "2. Enable 'Auto-start' option",
                    "3. 'Battery saver' → Select 'No restrictions'",
                    "4. In the recent apps screen, swipe down on the app to LOCK it",
                    "5. Security app → Battery → Background settings → Heart Monitor → Allow background activity"
                ),
                settingsIntent = createXiaomiAutoStartIntent()
            )

            manufacturer.contains("samsung") -> OemInstruction(
                title = "Samsung Settings",
                steps = listOf(
                    "1. Settings → Battery and device care → Battery",
                    "2. 'Background usage limits' → Add Heart Monitor to 'Apps that won't sleep'",
                    "3. Turn OFF 'Adaptive battery'",
                    "4. Settings → Apps → Heart Monitor → Battery → Select 'Unrestricted'"
                ),
                settingsIntent = createSamsungBatteryIntent()
            )

            manufacturer.contains("huawei") ||
            manufacturer.contains("honor") -> OemInstruction(
                title = "Huawei / Honor Settings",
                steps = listOf(
                    "1. Settings → Battery → App launch → Heart Monitor",
                    "2. Enable 'Manage manually'",
                    "3. Enable all three options: Auto-launch, Secondary launch, Run in background",
                    "4. Phone Manager → Battery → Settings → Protected apps → Add Heart Monitor"
                ),
                settingsIntent = createHuaweiAutoStartIntent()
            )

            manufacturer.contains("oneplus") -> OemInstruction(
                title = "OnePlus Settings",
                steps = listOf(
                    "1. Settings → Battery → Battery optimization → Heart Monitor → Select 'Don't optimize'",
                    "2. Settings → Battery → Advanced optimization → Turn OFF",
                    "3. Recent apps → LOCK Heart Monitor (lock icon)"
                ),
                settingsIntent = createOnePlusBatteryIntent()
            )

            manufacturer.contains("oppo") ||
            manufacturer.contains("realme") -> OemInstruction(
                title = "OPPO / Realme Settings",
                steps = listOf(
                    "1. Settings → Battery → Power saver → Heart Monitor → 'Allow background activity'",
                    "2. Settings → App management → Heart Monitor → 'Auto start' → ON",
                    "3. Security center → Privacy permissions → Startup manager → Heart Monitor → ON"
                ),
                settingsIntent = createOppoAutoStartIntent()
            )

            manufacturer.contains("vivo") -> OemInstruction(
                title = "Vivo Settings",
                steps = listOf(
                    "1. Settings → Battery → High background power consumption → Heart Monitor → ON",
                    "2. Settings → More settings → Apps → Auto start → Heart Monitor → ON",
                    "3. i Manager → App manager → Autostart manager → Heart Monitor → ON"
                ),
                settingsIntent = createVivoAutoStartIntent()
            )

            else -> OemInstruction(
                title = "Battery Settings",
                steps = listOf(
                    "1. Settings → Battery → Heart Monitor → Select 'No background restriction'",
                    "2. Settings → Apps → Heart Monitor → Battery → Select 'Unrestricted'"
                )
            )
        }
    }

    fun tryOpenAutoStartSettings(context: Context): Boolean {
        val instructions = getInstructions()
        val intent = instructions.settingsIntent ?: return false

        return try {
            context.startActivity(intent)
            true
        } catch (e: Exception) {
            false
        }
    }

    private fun createXiaomiAutoStartIntent(): Intent {
        return Intent().apply {
            component = ComponentName(
                "com.miui.securitycenter",
                "com.miui.permcenter.autostart.AutoStartManagementActivity"
            )
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
    }

    private fun createSamsungBatteryIntent(): Intent {
        return Intent().apply {
            component = ComponentName(
                "com.samsung.android.lool",
                "com.samsung.android.sm.battery.ui.BatteryActivity"
            )
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
    }

    private fun createHuaweiAutoStartIntent(): Intent {
        return Intent().apply {
            component = ComponentName(
                "com.huawei.systemmanager",
                "com.huawei.systemmanager.startupmgr.ui.StartupNormalAppListActivity"
            )
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
    }

    private fun createOnePlusBatteryIntent(): Intent {
        return Intent().apply {
            component = ComponentName(
                "com.oneplus.security",
                "com.oneplus.security.chainlaunch.view.ChainLaunchAppListActivity"
            )
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
    }

    private fun createOppoAutoStartIntent(): Intent {
        return Intent().apply {
            component = ComponentName(
                "com.coloros.safecenter",
                "com.coloros.safecenter.startupapp.StartupAppListActivity"
            )
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
    }

    private fun createVivoAutoStartIntent(): Intent {
        return Intent().apply {
            component = ComponentName(
                "com.iqoo.secure",
                "com.iqoo.secure.ui.phoneoptimize.AddWhiteListActivity"
            )
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
    }
}
