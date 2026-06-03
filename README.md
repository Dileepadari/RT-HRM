> **Language:** **English** | [Hindi (हिंदी)](README_HI.md)

<div align="center">
  
# Real-Time Heart Rate Monitor

**A comprehensive, real-time BLE heart rate monitoring solution featuring an Android application and a PHP-based web dashboard.**

![Android](https://img.shields.io/badge/Android-3DDC84?style=for-the-badge&logo=android&logoColor=white)
![Kotlin](https://img.shields.io/badge/kotlin-%237F52FF.svg?style=for-the-badge&logo=kotlin&logoColor=white)
![Jetpack Compose](https://img.shields.io/badge/Jetpack%20Compose-4285F4?style=for-the-badge&logo=jetpackcompose&logoColor=white)
<br>
![PHP](https://img.shields.io/badge/PHP-%23777BB4.svg?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-%234479A1.svg?style=for-the-badge&logo=mysql&logoColor=white)
![Chart.js](https://img.shields.io/badge/Chart.js-%23FF6384.svg?style=for-the-badge&logo=chartdotjs&logoColor=white)

</div>

---

## 📌 Overview

This project consists of an Android application that collects real-time heart rate data via Bluetooth Low Energy (BLE) sensors (such as Polar, Garmin, Wahoo TICKR) and a PHP server that provides a live monitoring web dashboard, historical data storage, and analytics.

---

## 📸 Application Previews

<div align="center">
  
| Live Monitoring | Analytics & Stats | Hourly Trends |
|:---:|:---:|:---:|
| <img src="screenshots/01_live_heart_rate.jpg" width="250" alt="Live Heart Rate"/> | <img src="screenshots/02_graphs_daily_stats.jpg" width="250" alt="Charts and Statistics"/> | <img src="screenshots/03_hourly_charts.jpg" width="250" alt="Hourly Charts"/> |

| Comprehensive Summary | Record Logs | Device Configurations |
|:---:|:---:|:---:|
| <img src="screenshots/04_hourly_summary.jpg" width="250" alt="Hourly Summary"/> | <img src="screenshots/05_all_records.jpg" width="250" alt="All Records"/> | <img src="screenshots/06_device_mode_settings.jpg" width="250" alt="Device Mode"/> |

</div>

---

## ✨ Core Features

### Data Acquisition & Connectivity
- **BLE Compatibility:** Seamlessly integrates with standard BLE HR Profile sensors.
- **Smart Connection:** Automatic reconnection, battery tracking, and sensor skin-contact verification.
- **Precision Metrics:** Beat-to-beat (RR) interval monitoring.

### Deep Analytics & UI
- **Live Visualizations:** Real-time heart rate and ECG-style RR interval charts.
- **Robust Statistics:** Daily summaries (Min/Avg/Max) and detailed hourly data aggregation.
- **Signal Integrity:** HRV-based signal quality calculation for artifact detection.

### Server & Synchronization
- **RESTful Architecture:** Secure API endpoints for structured data synchronization.
- **Offline Resilience:** Local data caching and background syncing when connectivity is restored.
- **Web Dashboard:** Server-Sent Events (SSE) provide instant, live telemetry updates on the web client.

### Alerts & Modes
- **Threshold Alerts:** Customizable boundary alerts with system sounds and vibrations.
- **Dual Device Modes:** Run as a "Server" (data collector) or "Client" (live WebView monitor).
- **Service Resilience:** Foreground service designed with OEM-specific battery optimization workarounds.

---

## 🛠️ System Requirements

### Mobile Application
- **OS:** Android 8.0 (API 26) or higher.
- **Hardware:** BLE-capable Android device.
- **Permissions:** Bluetooth, Fine Location (for BLE scanning), and Notifications.

### Backend Infrastructure
- **Server:** PHP 8.1+
- **Database:** MySQL 8.0+ or MariaDB 10.6+
- **Web Server:** Apache or Nginx

---

## 🚀 Installation & Setup

### Phase 1: Server Configuration (PHP Backend)

1. **Clone the repository:**
   ```bash
   git clone https://github.com/Dileepadari/RT-HRM.git
   cd realtime-heart-rate-monitor
   ```

2. **Deploy to your web directory:**
   ```bash
   cp -r server/ /var/www/html/hr/
   ```

3. **Initialize Database:**
   ```bash
   mysql -u root -p < server/schema.sql
   ```
   *This initializes the `heart_rate_db` database and necessary relational tables (`heart_rate_logs`, `heart_rate_alerts`, `heart_rate_stats`).*

4. **Environment Variables:**
   Configure your secure variables in the `.env` file:
   ```bash
   cd /var/www/html/hr/
   cp .env.example .env
   nano .env
   ```
   *Required variables include `HR_DB_HOST`, `HR_DB_NAME`, `HR_DB_USER`, `HR_DB_PASS`, `HR_API_KEY`, and `HR_ALLOWED_DEVICES`.*

5. **Verify Installation:**
   Navigate to `https://your-server.com/hr/live.php`. If configured correctly, the dashboard will display (initially showing "No data").

### Phase 2: Android Application Setup

1. **Build and Install:**
   Download the compiled `hr.apk` release, or compile from source:
   ```bash
   ./gradlew assembleDebug
   ```

2. **Application Configuration:**
   - Launch the application and open **Settings**.
   - Navigate to **Server Settings** and define your **API URL** (e.g., `https://your-server.com/hr/api/log.php`) and your secure **API Key**.
   - Select **Server Mode** if this device is wearing the sensor, or **Client Mode** to just monitor.
   - Connect your BLE sensor via the main interface.

---

## 📡 API Reference

### Data Transmission (Android to Server)

```http
POST /api/log.php
Content-Type: application/json
X-API-Key: {YOUR_API_KEY}

{
  "device_mac": "XX:XX:XX:XX:XX:XX",
  "heart_rate": 72,
  "rr_intervals": [850, 862, 845],
  "battery_level": 85,
  "sensor_contact": true,
  "timestamp": 1699876543210
}
```

### Data Retrieval Endpoints (GET)

| Endpoint | Description |
|----------|-------------|
| `/api/latest.php` | Retrieves the most recent heart rate telemetry. |
| `/api/chart-data.php?seconds=60` | Retrieves chart plotting data for the last *N* seconds. |
| `/api/minute-summary.php?hours=1` | Retrieves per-minute averages over the last *N* hours. |
| `/api/sse.php` | Initiates a real-time Server-Sent Events stream. |
| `/api/history.php` | Retrieves aggregated hourly statistics. |
| `/api/alerts.php` | Retrieves historical threshold violation alerts. |

---

## ⚙️ Technical Architecture

- **Language:** Kotlin
- **UI Framework:** Jetpack Compose
- **Network Client:** Ktor
- **Architecture Pattern:** Single Activity + Compose Navigation
- **SDK Targets:** Min 26, Target 35

---

## 📄 License

This project is licensed under the **MIT License**.

---

## ⚠️ Disclaimer

> [!WARNING]
> **Not a Medical Device**
> 
> This application is intended for informational and personal use only. The ECG-style charts are representative visualizations derived from standard beat intervals and are not authentic electrocardiograms. Do not use this data for medical diagnoses, treatments, or health-critical decisions. Always consult a qualified healthcare professional. The developers assume no liability for consequences arising from the use of this software.
