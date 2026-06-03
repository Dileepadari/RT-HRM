package net.hrapp.hr.util

import android.content.Context
import android.content.res.Configuration
import android.content.res.Resources
import android.os.Build
import java.util.Locale

object LocaleHelper {

    const val LANG_SYSTEM = "system"
    const val LANG_EN = "en"
    const val LANG_HI = "hi"

    /**
     * List of supported languages
     */
    val supportedLanguages = listOf(
        LANG_SYSTEM to "System Language",
        LANG_EN to "English",
        LANG_HI to "हिंदी"
    )

    /**
     * Updates context with the specified language
     */
    fun setLocale(context: Context, languageCode: String): Context {
        val locale = when (languageCode) {
            LANG_SYSTEM -> getSystemLocale()
            else -> Locale(languageCode)
        }

        Locale.setDefault(locale)

        val config = Configuration(context.resources.configuration)
        config.setLocale(locale)

        return context.createConfigurationContext(config)
    }

    /**
     * Returns system locale
     */
    private fun getSystemLocale(): Locale {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            Resources.getSystem().configuration.locales[0]
        } else {
            @Suppress("DEPRECATION")
            Resources.getSystem().configuration.locale
        }
    }

    /**
     * Returns display name from language code
     */
    fun getLanguageDisplayName(code: String): String {
        return supportedLanguages.find { it.first == code }?.second ?: code
    }

    /**
     * Returns current language code
     */
    fun getCurrentLanguageCode(context: Context): String {
        val currentLocale = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            context.resources.configuration.locales[0]
        } else {
            @Suppress("DEPRECATION")
            context.resources.configuration.locale
        }
        return currentLocale.language
    }
}
