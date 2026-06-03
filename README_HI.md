> **Language:** [English](README.md) | **Hindi (हिंदी)**

<div align="center">
  
# रियल-टाइम हार्ट रेट मॉनिटर (Real-Time Heart Rate Monitor)

**एक व्यापक, रीयल-टाइम BLE हार्ट रेट मॉनिटरिंग समाधान जिसमें एक एंड्रॉइड ऐप और PHP-आधारित वेब डैशबोर्ड शामिल है।**

![Android](https://img.shields.io/badge/Android-3DDC84?style=for-the-badge&logo=android&logoColor=white)
![Kotlin](https://img.shields.io/badge/kotlin-%237F52FF.svg?style=for-the-badge&logo=kotlin&logoColor=white)
![Jetpack Compose](https://img.shields.io/badge/Jetpack%20Compose-4285F4?style=for-the-badge&logo=jetpackcompose&logoColor=white)
<br>
![PHP](https://img.shields.io/badge/PHP-%23777BB4.svg?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-%234479A1.svg?style=for-the-badge&logo=mysql&logoColor=white)
![Chart.js](https://img.shields.io/badge/Chart.js-%23FF6384.svg?style=for-the-badge&logo=chartdotjs&logoColor=white)

</div>

---

## 📌 अवलोकन (Overview)

इस प्रोजेक्ट में एक एंड्रॉइड एप्लिकेशन है जो ब्लूटूथ लो एनर्जी (BLE) सेंसर (जैसे पोलर, गार्मिन, वाहू TICKR) के माध्यम से रियल-टाइम हार्ट रेट डेटा एकत्र करता है। इसके साथ ही, यह एक PHP सर्वर के साथ आता है जो लाइव मॉनिटरिंग वेब डैशबोर्ड, ऐतिहासिक डेटा स्टोरेज और एनालिटिक्स प्रदान करता है।

---

## 📸 एप्लिकेशन प्रीव्यू

<div align="center">
  
| लाइव मॉनिटरिंग | एनालिटिक्स और आँकड़े | प्रति घंटा रुझान |
|:---:|:---:|:---:|
| <img src="screenshots/01_live_heart_rate.jpg" width="250" alt="Live Heart Rate"/> | <img src="screenshots/02_graphs_daily_stats.jpg" width="250" alt="Charts and Statistics"/> | <img src="screenshots/03_hourly_charts.jpg" width="250" alt="Hourly Charts"/> |

| विस्तृत सारांश | रिकॉर्ड लॉग्स | डिवाइस कॉन्फ़िगरेशन |
|:---:|:---:|:---:|
| <img src="screenshots/04_hourly_summary.jpg" width="250" alt="Hourly Summary"/> | <img src="screenshots/05_all_records.jpg" width="250" alt="All Records"/> | <img src="screenshots/06_device_mode_settings.jpg" width="250" alt="Device Mode"/> |

</div>

---

## ✨ मुख्य विशेषताएं (Core Features)

### डेटा अधिग्रहण और कनेक्टिविटी
- **BLE संगतता:** मानक BLE HR प्रोफाइल सेंसर के साथ निर्बाध रूप से जुड़ता है।
- **स्मार्ट कनेक्शन:** स्वचालित रीकनेक्शन, बैटरी ट्रैकिंग और सेंसर स्किन-कॉन्टैक्ट सत्यापन।
- **सटीक मेट्रिक्स:** बीट-टू-बीट (RR) अंतराल मॉनिटरिंग।

### गहन एनालिटिक्स और UI
- **लाइव विज़ुअलाइज़ेशन:** रियल-टाइम हार्ट रेट और ईसीजी-शैली (ECG) RR अंतराल चार्ट।
- **मजबूत आँकड़े:** दैनिक सारांश (न्यूनतम/औसत/अधिकतम) और विस्तृत प्रति घंटा डेटा।
- **सिग्नल की शुद्धता:** विसंगतियों का पता लगाने के लिए HRV-आधारित सिग्नल गुणवत्ता गणना।

### सर्वर और सिंक्रोनाइज़ेशन
- **RESTful आर्किटेक्चर:** सुरक्षित API एंडपॉइंट्स के माध्यम से स्ट्रक्चर्ड डेटा सिंक।
- **ऑफ़लाइन रेजिलिएंस:** लोकल डेटा कैशिंग और कनेक्टिविटी वापस आने पर बैकग्राउंड सिंक।
- **वेब डैशबोर्ड:** सर्वर-सेंट इवेंट्स (SSE) के माध्यम से वेब क्लाइंट पर तुरंत लाइव अपडेट।

### अलर्ट और मोड
- **सीमा अलर्ट (Threshold Alerts):** सिस्टम ध्वनि और कंपन के साथ अनुकूलन योग्य (customizable) अलर्ट।
- **दोहरे डिवाइस मोड:** "सर्वर" (डेटा कलेक्टर) या "क्लाइंट" (लाइव वेबव्यू मॉनिटर) के रूप में चलाएं।
- **सर्विस रेजिलिएंस:** फोरग्राउंड सर्विस जिसे बैटरी ऑप्टिमाइज़ेशन से बचने के लिए डिज़ाइन किया गया है।

---

## 🛠️ सिस्टम आवश्यकताएँ (System Requirements)

### मोबाइल एप्लिकेशन
- **OS:** Android 8.0 (API 26) या इसके बाद का वर्ज़न।
- **हार्डवेयर:** BLE सक्षम एंड्रॉइड डिवाइस।
- **अनुमतियां:** ब्लूटूथ, सटीक स्थान (BLE स्कैनिंग के लिए), और सूचनाएं (Notifications)।

### सर्वर इन्फ्रास्ट्रक्चर
- **सर्वर:** PHP 8.1+
- **डेटाबेस:** MySQL 8.0+ या MariaDB 10.6+
- **वेब सर्वर:** Apache या Nginx

---

## 🚀 स्थापना और सेटअप (Installation)

### चरण 1: सर्वर कॉन्फ़िगरेशन (PHP बैकएंड)

1. **रिपॉजिटरी क्लोन करें:**
   ```bash
   git clone https://github.com/Dileepadari/RT-HRM.git
   cd RT-HRM
   ```

2. **अपनी वेब डायरेक्टरी में डिप्लॉय करें:**
   ```bash
   cp -r server/ /var/www/html/hr/
   ```

3. **डेटाबेस को इनिशियलाइज़ करें:**
   ```bash
   mysql -u root -p < server/schema.sql
   ```
   *यह कमांड `heart_rate_db` डेटाबेस और आवश्यक टेबल्स (`heart_rate_logs`, `heart_rate_alerts`, `heart_rate_stats`) बनाता है।*

4. **पर्यावरण चर (Environment Variables):**
   `.env` फ़ाइल में अपने सुरक्षित चर कॉन्फ़िगर करें:
   ```bash
   cd /var/www/html/hr/
   cp .env.example .env
   nano .env
   ```
   *आवश्यक चरों में शामिल हैं `HR_DB_HOST`, `HR_DB_NAME`, `HR_DB_USER`, `HR_DB_PASS`, `HR_API_KEY`, और `HR_ALLOWED_DEVICES`।*

5. **स्थापना सत्यापित करें:**
   अपने ब्राउज़र में `https://your-server.com/hr/live.php` पर जाएँ। यदि कॉन्फ़िगरेशन सही है, तो डैशबोर्ड प्रदर्शित होगा (शुरू में "No data" दिखाएगा)।

### चरण 2: एंड्रॉइड ऐप सेटअप

1. **बिल्ड और इंस्टाल करें:**
   कंपाइल की गई `hr.apk` रिलीज़ डाउनलोड करें, या स्रोत कोड से बिल्ड करें:
   ```bash
   ./gradlew assembleDebug
   ```

2. **ऐप कॉन्फ़िगरेशन:**
   - एप्लिकेशन लॉन्च करें और **सेटिंग्स** खोलें।
   - **सर्वर सेटिंग्स** पर नेविगेट करें और अपना **API URL** (जैसे, `https://your-server.com/hr/api/log.php`) और सुरक्षित **API Key** दर्ज करें।
   - यदि यह डिवाइस सेंसर पहने हुए है तो **सर्वर मोड** चुनें, या केवल मॉनिटर करने के लिए **क्लाइंट मोड** चुनें।
   - मुख्य इंटरफ़ेस के माध्यम से अपने BLE सेंसर को कनेक्ट करें।

---

## 📡 API संदर्भ (API Reference)

### डेटा ट्रांसमिशन (एंड्रॉइड से सर्वर)

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

### डेटा पुनर्प्राप्ति एंडपॉइंट्स (GET)

| एंडपॉइंट | विवरण |
|----------|-------------|
| `/api/latest.php` | नवीनतम हार्ट रेट डेटा प्राप्त करता है। |
| `/api/chart-data.php?seconds=60` | पिछले *N* सेकंड के लिए चार्ट प्लॉटिंग डेटा प्राप्त करता है। |
| `/api/minute-summary.php?hours=1` | पिछले *N* घंटों का प्रति-मिनट औसत प्राप्त करता है। |
| `/api/sse.php` | रीयल-टाइम सर्वर-सेंट इवेंट्स स्ट्रीम शुरू करता है। |
| `/api/history.php` | एकत्रित प्रति घंटा आँकड़े प्राप्त करता है। |
| `/api/alerts.php` | ऐतिहासिक अलर्ट उल्लंघन प्राप्त करता है। |

---

## ⚙️ तकनीकी आर्किटेक्चर

- **भाषा (Language):** Kotlin
- **UI फ्रेमवर्क:** Jetpack Compose
- **नेटवर्क क्लाइंट:** Ktor
- **आर्किटेक्चर पैटर्न:** सिंगल एक्टिविटी (Single Activity) + Compose नेविगेशन
- **SDK लक्ष्य:** न्यूनतम 26, लक्ष्य 35

---

## 📄 लाइसेंस (License)

यह प्रोजेक्ट **MIT License** के अंतर्गत लाइसेंस प्राप्त है।

---

## ⚠️ अस्वीकरण (Disclaimer)

> [!WARNING]
> **यह कोई चिकित्सा उपकरण नहीं है**
> 
> यह एप्लिकेशन केवल सूचनात्मक और व्यक्तिगत उपयोग के लिए है। ECG शैली के चार्ट मानक बीट अंतरालों से प्राप्त प्रतिनिधि विज़ुअलाइज़ेशन हैं और प्रामाणिक इलेक्ट्रोकार्डियोग्राम नहीं हैं। चिकित्सा निदान, उपचार, या स्वास्थ्य संबंधी महत्वपूर्ण निर्णयों के लिए इस डेटा का उपयोग न करें। हमेशा किसी योग्य स्वास्थ्य देखभाल पेशेवर से सलाह लें। डेवलपर्स इस सॉफ़्टवेयर के उपयोग से उत्पन्न होने वाले परिणामों के लिए कोई दायित्व नहीं लेते हैं।
