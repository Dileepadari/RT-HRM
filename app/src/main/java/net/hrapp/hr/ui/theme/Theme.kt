package net.hrapp.hr.ui.theme

import android.app.Activity
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalView
import androidx.core.view.WindowCompat

// Modern Light Design System
object HeartMonitorColors {
    // Semantic colors retained for specific components if needed, but updated for light contrast
    val Connected     = Color(0xFF10B981)    // Emerald green
    val LowHeartRate  = Color(0xFF3B82F6)    // Blue
    val HighHeartRate = Color(0xFFF59E0B)    // Amber
    val Critical      = Color(0xFFEF4444)    // Red
    val Offline       = Color(0xFF94A3B8)    // Slate
    val NoSignal      = Color(0xFFF97316)    // Orange
    
    // Light Backgrounds
    val Background = Color(0xFFF8FAFC)
    val Surface = Color(0xFFFFFFFF)
    val Primary = Color(0xFFE11D48) // Crimson red
}

private val LightColorScheme = lightColorScheme(
    primary = HeartMonitorColors.Primary,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFFFE4E6),
    onPrimaryContainer = Color(0xFF9F1239),
    
    secondary = Color(0xFF0F172A),
    onSecondary = Color.White,
    
    tertiary = HeartMonitorColors.Connected,
    onTertiary = Color.White,
    
    background = HeartMonitorColors.Background,
    onBackground = Color(0xFF0F172A),
    
    surface = HeartMonitorColors.Surface,
    onSurface = Color(0xFF1E293B),
    
    surfaceVariant = Color(0xFFF1F5F9),
    onSurfaceVariant = Color(0xFF475569),
    
    error = HeartMonitorColors.Critical,
    onError = Color.White,
    
    outline = Color(0xFFCBD5E1)
)

@Composable
fun HeartMonitorTheme(
    content: @Composable () -> Unit
) {
    val colorScheme = LightColorScheme
    val view = LocalView.current
    if (!view.isInEditMode) {
        SideEffect {
            val window = (view.context as Activity).window
            window.statusBarColor = colorScheme.background.toArgb()
            WindowCompat.getInsetsController(window, view).isAppearanceLightStatusBars = true
        }
    }

    MaterialTheme(
        colorScheme = colorScheme,
        content = content
    )
}
