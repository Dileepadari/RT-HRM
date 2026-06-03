package net.hrapp.hr.ble

import android.util.Log
import java.util.concurrent.ConcurrentLinkedQueue
import kotlin.math.abs
import kotlin.math.ln
import kotlin.math.max
import kotlin.math.sqrt

/**
 * Signal Quality Detector
 *
 * Supporting AFib, bradycardia and tachycardia patients,
 * algorithm for separating noise from real cardiac data.
 *
 * Key features:
 * - 140-170 BPM noise band density detection
 * - Sample Entropy (chaotic vs regular signal separation)
 * - Progressive window system (5s fast -> 15s reliable -> 30s definitive)
 * - AFib protection: High entropy = real irregularity, do not reject
 */
class SignalQualityDetector {

    companion object {
        private const val TAG = "SignalQualityDetector"

        // Window sizes (in seconds, assuming 1 Hz)
        private const val QUICK_WINDOW = 5
        private const val RELIABLE_WINDOW = 15
        private const val DEFINITIVE_WINDOW = 30

        // Noise band (observed when device is not worn)
        private const val NOISE_BAND_LOW = 140
        private const val NOISE_BAND_HIGH = 170

        // Physiological limits
        private const val PHYSIOLOGICAL_MIN = 30
        private const val PHYSIOLOGICAL_MAX = 220

        // Sample Entropy parametreleri
        private const val ENTROPY_M = 2
        private const val ENTROPY_R_FACTOR = 0.2
        private const val ENTROPY_R_MIN = 5.0 // Low std protection

        // Decision thresholds
        private const val NOISE_BAND_THRESHOLD_HIGH = 0.80f  // 80%+ = definite noise
        private const val NOISE_BAND_THRESHOLD_MEDIUM = 0.60f // 60%+ = suspicious
        private const val ENTROPY_NOISE_THRESHOLD = 0.3 // Low entropy = noise
        private const val ENTROPY_AFIB_THRESHOLD = 1.5  // High entropy = possibly AFib
    }

    // Thread-safe HR buffer
    private val hrBuffer = ConcurrentLinkedQueue<TimestampedHR>()

    // Last quality score
    private var lastQualityResult: QualityResult = QualityResult(
        quality = 0f,
        confidence = 0f,
        stage = DecisionStage.UNKNOWN,
        reason = "Not enough data"
    )

    data class TimestampedHR(
        val timestamp: Long,
        val heartRate: Int
    )

    data class QualityResult(
        val quality: Float,      // 0.0 (noise) - 1.0 (clean signal)
        val confidence: Float,   // Decision confidence
        val stage: DecisionStage,
        val reason: String,
        val isNoise: Boolean = quality < 0.5f,
        val entropy: Double = 0.0,
        val noiseBandDensity: Float = 0f
    )

    enum class DecisionStage(val windowSize: Int, val baseConfidence: Float) {
        UNKNOWN(0, 0f),
        QUICK(QUICK_WINDOW, 0.6f),
        RELIABLE(RELIABLE_WINDOW, 0.8f),
        DEFINITIVE(DEFINITIVE_WINDOW, 0.95f)
    }

    /**
     * Add new HR value and perform quality analysis
     */
    @Synchronized
    fun addReading(heartRate: Int): QualityResult {
        val now = System.currentTimeMillis()

        // Buffer'a ekle
        hrBuffer.add(TimestampedHR(now, heartRate))

        // Eski verileri temizle (30 saniyeden eski)
        val cutoff = now - (DEFINITIVE_WINDOW * 1000L)
        while (hrBuffer.peek()?.timestamp?.let { it < cutoff } == true) {
            hrBuffer.poll()
        }

        // Perform analysis
        lastQualityResult = analyze()

        Log.d(TAG, "HR=$heartRate, Quality=${String.format("%.2f", lastQualityResult.quality)}, " +
                "Stage=${lastQualityResult.stage}, Noise=${lastQualityResult.isNoise}, " +
                "Reason=${lastQualityResult.reason}")

        return lastQualityResult
    }

    /**
     * Main analysis function
     */
    private fun analyze(): QualityResult {
        val samples = hrBuffer.map { it.heartRate }
        val size = samples.size

        return when {
            size >= DEFINITIVE_WINDOW -> definitiveAnalysis(samples)
            size >= RELIABLE_WINDOW -> reliableAnalysis(samples)
            size >= QUICK_WINDOW -> quickAnalysis(samples)
            else -> QualityResult(
                quality = 0.5f,
                confidence = 0f,
                stage = DecisionStage.UNKNOWN,
                reason = "Not enough data (${size}/${QUICK_WINDOW})"
            )
        }
    }

    /**
     * 5-second fast analysis - band density only
     */
    private fun quickAnalysis(samples: List<Int>): QualityResult {
        val noiseBandDensity = calculateNoiseBandDensity(samples)

        // Out-of-physiological-range check
        val outsidePhysiological = samples.count { it !in PHYSIOLOGICAL_MIN..PHYSIOLOGICAL_MAX }
        if (outsidePhysiological > samples.size * 0.3) {
            return QualityResult(
                quality = 0.1f,
                confidence = 0.7f,
                stage = DecisionStage.QUICK,
                reason = "Out of physiological range values",
                noiseBandDensity = noiseBandDensity
            )
        }

        // High noise band density = noise
        if (noiseBandDensity > NOISE_BAND_THRESHOLD_HIGH) {
            return QualityResult(
                quality = 0.2f,
                confidence = 0.6f,
                stage = DecisionStage.QUICK,
                reason = "High noise band density (${(noiseBandDensity * 100).toInt()}%)",
                noiseBandDensity = noiseBandDensity
            )
        }

        // Suspicious but not definitive
        if (noiseBandDensity > NOISE_BAND_THRESHOLD_MEDIUM) {
            return QualityResult(
                quality = 0.5f,
                confidence = 0.5f,
                stage = DecisionStage.QUICK,
                reason = "Suspicious signal, analysis continuing",
                noiseBandDensity = noiseBandDensity
            )
        }

        // Muhtemelen temiz
        return QualityResult(
            quality = 0.8f,
            confidence = 0.6f,
            stage = DecisionStage.QUICK,
            reason = "Preliminary: Looks clean",
            noiseBandDensity = noiseBandDensity
        )
    }

    /**
     * 15-second reliable analysis - band density + entropy
     */
    private fun reliableAnalysis(samples: List<Int>): QualityResult {
        val noiseBandDensity = calculateNoiseBandDensity(samples)
        val entropy = calculateSampleEntropy(samples)
        val normalizedEntropy = normalizeEntropy(entropy)

        Log.d(TAG, "Reliable: noiseBand=${(noiseBandDensity * 100).toInt()}%, entropy=${String.format("%.3f", entropy)}")

        // CASE 1: High band density + low entropy = DEFINITE NOISE
        if (noiseBandDensity > NOISE_BAND_THRESHOLD_HIGH && entropy < ENTROPY_NOISE_THRESHOLD) {
            return QualityResult(
                quality = 0.1f,
                confidence = 0.85f,
                stage = DecisionStage.RELIABLE,
                reason = "Noise detected (narrow band, low entropy)",
                entropy = entropy,
                noiseBandDensity = noiseBandDensity
            )
        }

        // CASE 2: High band density + HIGH entropy = POSSIBLY AFib (PROTECT!)
        if (noiseBandDensity > NOISE_BAND_THRESHOLD_MEDIUM && entropy > ENTROPY_AFIB_THRESHOLD) {
            return QualityResult(
                quality = 0.7f,  // Accept with low confidence
                confidence = 0.6f,
                stage = DecisionStage.RELIABLE,
                reason = "Possibly AFib - high entropy (${String.format("%.2f", entropy)})",
                entropy = entropy,
                noiseBandDensity = noiseBandDensity
            )
        }

        // CASE 3: Low band density = probably clean
        if (noiseBandDensity < NOISE_BAND_THRESHOLD_MEDIUM) {
            return QualityResult(
                quality = 0.9f,
                confidence = 0.8f,
                stage = DecisionStage.RELIABLE,
                reason = "Clean signal",
                entropy = entropy,
                noiseBandDensity = noiseBandDensity
            )
        }

        // CASE 4: Ambiguous - medium quality
        val quality = 0.5f + (normalizedEntropy * 0.3f) - (noiseBandDensity * 0.2f)
        return QualityResult(
            quality = quality.coerceIn(0.3f, 0.8f),
            confidence = 0.7f,
            stage = DecisionStage.RELIABLE,
            reason = "Analysis continuing",
            entropy = entropy,
            noiseBandDensity = noiseBandDensity
        )
    }

    /**
     * 30-second definitive analysis - full feature set
     */
    private fun definitiveAnalysis(samples: List<Int>): QualityResult {
        val noiseBandDensity = calculateNoiseBandDensity(samples)
        val entropy = calculateSampleEntropy(samples)
        val std = samples.standardDeviation()
        val mean = samples.average()
        val trend = calculateTrend(samples)

        Log.d(TAG, "Definitive: band=${(noiseBandDensity * 100).toInt()}%, " +
                "entropy=${String.format("%.3f", entropy)}, std=${String.format("%.1f", std)}, " +
                "mean=${String.format("%.1f", mean)}, trend=${String.format("%.3f", trend)}")

        // DEFINITE NOISE: High band + low entropy + no trend
        if (noiseBandDensity > NOISE_BAND_THRESHOLD_HIGH &&
            entropy < ENTROPY_NOISE_THRESHOLD &&
            abs(trend) < 0.1) {
            return QualityResult(
                quality = 0.05f,
                confidence = 0.95f,
                stage = DecisionStage.DEFINITIVE,
                reason = "Noise: Narrow band (${(noiseBandDensity * 100).toInt()}%), low entropy, no trend",
                entropy = entropy,
                noiseBandDensity = noiseBandDensity
            )
        }

        // AFib PROTECTION: High entropy = real irregularity
        if (entropy > ENTROPY_AFIB_THRESHOLD) {
            // But must be within physiological limits
            val inPhysiological = samples.all { it in PHYSIOLOGICAL_MIN..PHYSIOLOGICAL_MAX }
            if (inPhysiological) {
                return QualityResult(
                    quality = 0.85f,
                    confidence = 0.9f,
                    stage = DecisionStage.DEFINITIVE,
                    reason = "Valid: High HRV/AFib pattern (entropy=${String.format("%.2f", entropy)})",
                    entropy = entropy,
                    noiseBandDensity = noiseBandDensity
                )
            }
        }

        // BRADYCARDIA/TACHYCARDIA PROTECTION
        if (mean < 50 || mean > 150) {
            // Low/high HR but stable = real
            if (std < 15 && entropy < 1.0) {
                return QualityResult(
                    quality = 0.9f,
                    confidence = 0.9f,
                    stage = DecisionStage.DEFINITIVE,
                    reason = "Geçerli: Stabil ${if (mean < 50) "bradycardia" else "tachycardia"} (${mean.toInt()} BPM)",
                    entropy = entropy,
                    noiseBandDensity = noiseBandDensity
                )
            }
        }

        // NORMAL CLEAN SIGNAL
        if (noiseBandDensity < NOISE_BAND_THRESHOLD_MEDIUM && std < 30) {
            return QualityResult(
                quality = 0.95f,
                confidence = 0.95f,
                stage = DecisionStage.DEFINITIVE,
                reason = "Clean signal",
                entropy = entropy,
                noiseBandDensity = noiseBandDensity
            )
        }

        // AMBIGUOUS STATE - accept with skepticism (avoid false positives)
        val quality = calculateFinalQuality(noiseBandDensity, entropy, std)
        return QualityResult(
            quality = quality,
            confidence = 0.8f,
            stage = DecisionStage.DEFINITIVE,
            reason = "Ambiguous - accepted with low confidence",
            entropy = entropy,
            noiseBandDensity = noiseBandDensity
        )
    }

    /**
     * Density in 140-170 BPM noise band
     */
    private fun calculateNoiseBandDensity(samples: List<Int>): Float {
        if (samples.isEmpty()) return 0f
        val inBand = samples.count { it in NOISE_BAND_LOW..NOISE_BAND_HIGH }
        return inBand.toFloat() / samples.size
    }

    /**
     * Sample Entropy hesaplama
     * Noise = low entropy (templates match in narrow band)
     * AFib = high entropy (wide distribution)
     */
    private fun calculateSampleEntropy(data: List<Int>): Double {
        if (data.size < ENTROPY_M + 2) return 0.0

        val std = data.standardDeviation()
        val r = max(ENTROPY_R_FACTOR * std, ENTROPY_R_MIN)

        val n = data.size
        var matchesM = 0
        var matchesM1 = 0

        // Template matching (length m and m+1)
        for (i in 0 until n - ENTROPY_M) {
            for (j in i + 1 until n - ENTROPY_M) {
                // Match check of length m
                var matchM = true
                for (k in 0 until ENTROPY_M) {
                    if (abs(data[i + k] - data[j + k]) > r) {
                        matchM = false
                        break
                    }
                }
                if (matchM) {
                    matchesM++
                    // Match check of length m+1
                    if (i + ENTROPY_M < n && j + ENTROPY_M < n) {
                        if (abs(data[i + ENTROPY_M] - data[j + ENTROPY_M]) <= r) {
                            matchesM1++
                        }
                    }
                }
            }
        }

        return if (matchesM > 0 && matchesM1 > 0) {
            -ln(matchesM1.toDouble() / matchesM)
        } else {
            2.0 // High entropy (no matches)
        }
    }

    /**
     * Entropy normalization (0-1 range)
     */
    private fun normalizeEntropy(entropy: Double): Float {
        // Typical range: 0 (very regular) - 2.5 (very chaotic)
        return (entropy / 2.5).coerceIn(0.0, 1.0).toFloat()
    }

    /**
     * Trend calculation (linear regression slope)
     */
    private fun calculateTrend(samples: List<Int>): Double {
        if (samples.size < 2) return 0.0

        val n = samples.size
        val xMean = (n - 1) / 2.0
        val yMean = samples.average()

        var numerator = 0.0
        var denominator = 0.0

        for (i in samples.indices) {
            numerator += (i - xMean) * (samples[i] - yMean)
            denominator += (i - xMean) * (i - xMean)
        }

        return if (denominator > 0) numerator / denominator else 0.0
    }

    /**
     * Final kalite skoru hesaplama
     */
    private fun calculateFinalQuality(noiseBandDensity: Float, entropy: Double, std: Double): Float {
        val normalizedEntropy = normalizeEntropy(entropy)

        // Weighted score (AFib-friendly: high entropy = high quality)
        val entropyScore = normalizedEntropy * 0.4f
        val bandScore = (1f - noiseBandDensity) * 0.4f
        val stabilityScore = (1f - (std / 50.0).coerceIn(0.0, 1.0).toFloat()) * 0.2f

        return (entropyScore + bandScore + stabilityScore).coerceIn(0.3f, 0.9f)
    }

    /**
     * Clear the buffer
     */
    fun reset() {
        hrBuffer.clear()
        lastQualityResult = QualityResult(
            quality = 0f,
            confidence = 0f,
            stage = DecisionStage.UNKNOWN,
            reason = "Reset"
        )
    }

    /**
     * Get the last quality result
     */
    fun getLastResult(): QualityResult = lastQualityResult

    /**
     * Number of samples in buffer
     */
    fun getSampleCount(): Int = hrBuffer.size
}

// Extension functions
private fun List<Int>.standardDeviation(): Double {
    if (size < 2) return 0.0
    val mean = average()
    val variance = sumOf { (it - mean) * (it - mean) } / (size - 1)
    return sqrt(variance)
}
