> **Language:** [English](README.md) | **Hindi (हिंदी)**

<div align="center">

# रियल-टाइम हार्ट रेट मॉनिटर (Real-Time Heart Rate Monitor)

**एक BLE हार्ट रेट मॉनिटर: एक एंड्रॉइड ऐप जो सेंसर से डेटा पढ़ता है, और एक PHP डैशबोर्ड जो उसे लाइव दिखाता है।**

![Android](https://img.shields.io/badge/Android-3DDC84?style=for-the-badge&logo=android&logoColor=white)
![Kotlin](https://img.shields.io/badge/kotlin-%237F52FF.svg?style=for-the-badge&logo=kotlin&logoColor=white)
![Jetpack Compose](https://img.shields.io/badge/Jetpack%20Compose-4285F4?style=for-the-badge&logo=jetpackcompose&logoColor=white)
<br>
![PHP](https://img.shields.io/badge/PHP-%23777BB4.svg?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-%234479A1.svg?style=for-the-badge&logo=mysql&logoColor=white)
![Chart.js](https://img.shields.io/badge/Chart.js-%23FF6384.svg?style=for-the-badge&logo=chartdotjs&logoColor=white)
<br>
![License](https://img.shields.io/badge/License-MIT-green.svg?style=for-the-badge)

</div>

---

## अवलोकन (Overview)

एक एंड्रॉइड ऐप ब्लूटूथ लो एनर्जी (BLE) चेस्ट स्ट्रैप या आर्मबैंड (पोलर, गार्मिन, वाहू TICKR, और मानक BLE हार्ट रेट प्रोफ़ाइल वाला कोई भी सेंसर) से हार्ट रेट पढ़ता है और हर रीडिंग PHP सर्वर को भेजता है। सर्वर इतिहास संग्रहीत करता है, अतालता (arrhythmia) और ब्रैडीकार्डिया एपिसोड का पता लगाता है, और एक लाइव वेब डैशबोर्ड प्रस्तुत करता है।

> [!IMPORTANT]
> अब रीड एंडपॉइंट और डैशबोर्ड के लिए क्रेडेंशियल आवश्यक है। पहले के संस्करणों में URL जानने वाले किसी भी व्यक्ति को लाइव और ऐतिहासिक हार्ट रेट दिखाई देता था। देखें: [पहुँच नियंत्रण](#पहुँच-नियंत्रण-access-control)।

---

## डैशबोर्ड (Dashboard)

| लाइव | इतिहास | असामान्यताएँ |
|:---:|:---:|:---:|
| <img src="screenshots/dashboard_live.jpg" alt="Live dashboard"/> | <img src="screenshots/dashboard_history.jpg" alt="History tab"/> | <img src="screenshots/dashboard_anomalies.jpg" alt="Anomalies tab"/> |

RR अंतराल के साथ लाइव हार्ट रेट, एक घंटे से लेकर एक महीने तक की किसी भी अवधि के प्रति-मिनट और प्रति-घंटा आँकड़े, और हर पहचाने गए एपिसोड का क्रमबद्ध और फ़िल्टर करने योग्य लॉग। अंग्रेज़ी और हिंदी दोनों में।

## ऐप (App)

| लाइव मॉनिटरिंग | एनालिटिक्स और आँकड़े | प्रति घंटा रुझान |
|:---:|:---:|:---:|
| <img src="screenshots/01_live_heart_rate.jpg" width="250" alt="Live Heart Rate"/> | <img src="screenshots/02_graphs_daily_stats.jpg" width="250" alt="Charts and Statistics"/> | <img src="screenshots/03_hourly_charts.jpg" width="250" alt="Hourly Charts"/> |

| सारांश | रिकॉर्ड लॉग्स | डिवाइस कॉन्फ़िगरेशन |
|:---:|:---:|:---:|
| <img src="screenshots/04_hourly_summary.jpg" width="250" alt="Hourly Summary"/> | <img src="screenshots/05_all_records.jpg" width="250" alt="All Records"/> | <img src="screenshots/06_device_mode_settings.jpg" width="250" alt="Device Mode"/> |

---

## विशेषताएं (Features)

### डेटा अधिग्रहण
- मानक BLE हार्ट रेट प्रोफ़ाइल, इसलिए अधिकांश स्ट्रैप बिना कॉन्फ़िगरेशन के काम करते हैं।
- स्वचालित पुनः कनेक्शन, बैटरी स्तर, और त्वचा-संपर्क (skin contact) की जानकारी।
- केवल औसत BPM नहीं, बल्कि बीट-दर-बीट RR अंतराल।

### विश्लेषण
- लाइव हार्ट रेट और RR-आधारित अंतराल चार्ट।
- दैनिक न्यूनतम, औसत और अधिकतम, साथ ही प्रति-मिनट और प्रति-घंटा संकलन।
- अतालता पहचान (एट्रियल फ़िब्रिलेशन, टैकीकार्डिया, ब्रैडीकार्डिया, PVC, PAC, SVT) एक विश्वास स्कोर के साथ, और ब्रैडीकार्डिया एपिसोड शुरुआत से रिकवरी तक ट्रैक किए जाते हैं।
- HRV-आधारित सिग्नल गुणवत्ता, जिससे सेंसर आर्टिफ़ैक्ट हटाए जाते हैं।

### सर्वर
- ऐप के लिखने और डैशबोर्ड के पढ़ने हेतु JSON एंडपॉइंट।
- पोलिंग के बिना लाइव अपडेट के लिए Server-Sent Events।
- ऐप स्थानीय रूप से डेटा सहेजता है और कनेक्टिविटी लौटने पर सिंक करता है।

### अलर्ट और मोड
- ध्वनि और कंपन के साथ सीमा-आधारित अलर्ट।
- सर्वर मोड (यह डिवाइस सेंसर पहने है) या क्लाइंट मोड (यह डिवाइस केवल देखता है)।
- फ़ोरग्राउंड सेवा, हर निर्माता के लिए बैटरी-ऑप्टिमाइज़ेशन मार्गदर्शन के साथ, क्योंकि ऐसे ऐप आमतौर पर बैकग्राउंड में ही बंद कर दिए जाते हैं।

---

## आवश्यकताएँ (Requirements)

**ऐप:** एंड्रॉइड 8.0 (API 26) या नया, BLE हार्डवेयर, और ब्लूटूथ, लोकेशन (BLE स्कैनिंग के लिए एंड्रॉइड इसे अनिवार्य करता है) व नोटिफ़िकेशन अनुमतियाँ।

**सर्वर:** PHP 8.1 या नया, MySQL 8.0 / MariaDB 10.6 या नया, Apache या nginx।

---

## स्थापना (Setup)

### 1. सर्वर

```bash
git clone https://github.com/Dileepadari/RT-HRM.git
cd RT-HRM
cp -r server/ /var/www/html/hr/
mysql -u root -p < server/schema.sql
```

फिर इसे कॉन्फ़िगर करें:

```bash
cd /var/www/html/hr/
cp .env.example .env
$EDITOR .env
```

`HR_API_KEY` ऐप और सर्वर के बीच साझा गुप्त कुंजी है, और यही डैशबोर्ड का पासवर्ड भी है। इसे ऐसा कुछ रखें जो आपकी धड़कन के रिकॉर्ड और किसी अजनबी के बीच अकेली दीवार बनने लायक हो। `HR_ALLOWED_DEVICES` में स्वीकार किए जाने वाले सेंसर के MAC पते अल्पविराम से अलग करके लिखें।

डैशबोर्ड फ़ोल्डर के भीतर `live.php` पर मिलता है और अपना API पथ स्वयं वहीं से निकाल लेता है, इसलिए `/hr/`, `/monitoring/hr2/` या डोमेन रूट, सब बिना किसी बदलाव के काम करते हैं।

### 2. ऐप

बनाएँ:

```bash
./gradlew assembleDebug
```

Gradle चलाने के लिए JDK 17 या नया चाहिए। प्रोजेक्ट का Java संस्करण एक toolchain के रूप में घोषित है, इसलिए Gradle आवश्यक कंपाइलर स्वयं ढूँढ या डाउनलोड कर लेगा।

क्रैश रिपोर्टिंग वैकल्पिक है। अपनी `google-services.json` फ़ाइल `app/` में रखें तो Firebase Crashlytics स्वतः लागू हो जाता है; उसके बिना ऐप बनता और चलता है, बस क्रैश रिपोर्टिंग बंद रहती है।

फिर ऐप में **Settings** खोलें, **API URL** (`https://your-server/hr/api/log.php`) और वही **API Key** डालें, सर्वर या क्लाइंट मोड चुनें, और अपना सेंसर कनेक्ट करें।

---

## पहुँच नियंत्रण (Access control)

हर रीड एंडपॉइंट और डैशबोर्ड के लिए इनमें से एक आवश्यक है:

- `X-API-Key` हेडर में API कुंजी, जो ऐप भेजता है, या
- डैशबोर्ड सत्र, जो `live.php` पर API कुंजी डालने से मिलता है।

कुंजी केवल हेडर में स्वीकार की जाती है। पहले इसे क्वेरी स्ट्रिंग से भी पढ़ा जाता था, जिससे वह वेब सर्वर के एक्सेस लॉग में, किसी तीसरे पक्ष को भेजे गए `Referer` में, और URL खोलने वाले किसी भी व्यक्ति के ब्राउज़र इतिहास में पहुँच जाती थी।

खुली पहुँच पर लौटने के लिए `HR_PUBLIC_READ=1` सेट करें। ध्यान रखें कि इससे क्या सार्वजनिक होता है: एक पहचाने जा सकने वाले व्यक्ति की धड़कन का निरंतर रिकॉर्ड, उनके अतालता एपिसोड, और परोक्ष रूप से यह कि वे कब सो रहे हैं, कब व्यायाम कर रहे हैं, और कब घर पर नहीं हैं।

`server/tests/http-gate-test.sh` यह सब वास्तविक HTTP पर जाँचता है, और CI हर पुश पर इसे चलाता है।

---

## API

### लिखना (ऐप से सर्वर तक)

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

### पढ़ना

| एंडपॉइंट | क्या लौटाता है |
|----------|---------------|
| `/api/latest.php` | सबसे हालिया रीडिंग। |
| `/api/latest-all.php` | हर डिवाइस की सबसे हालिया रीडिंग। |
| `/api/chart-data.php?seconds=60` | पिछले N सेकंड के प्लॉट बिंदु। |
| `/api/minute-summary.php?hours=1` | N घंटों में प्रति-मिनट न्यूनतम, औसत और अधिकतम। |
| `/api/today-count.php` | आज की रीडिंग संख्या, न्यूनतम, औसत और अधिकतम सहित। |
| `/api/arrhythmia.php?hours=24` | पहचानी गई अतालता घटनाएँ। |
| `/api/episodes.php` | ब्रैडीकार्डिया एपिसोड, पेजिंग और सॉर्टिंग के साथ। |
| `/api/sse.php`, `/api/sse-all.php` | लाइव Server-Sent Events स्ट्रीम। |
| `/api/history.php` | प्रति-घंटा आँकड़े। API कुंजी हेडर आवश्यक। |
| `/api/alerts.php` | पिछले सीमा-अलर्ट। API कुंजी हेडर आवश्यक। |

---

## आर्किटेक्चर (Architecture)

Kotlin, Jetpack Compose, Ktor, Compose नेविगेशन के साथ एकल गतिविधि, न्यूनतम SDK 26 और लक्ष्य SDK 35, PHP 8 और MySQL के साथ। [DEVDOC.md](DEVDOC.md) में मॉड्यूल संरचना, सेंसर से चार्ट तक का डेटा प्रवाह, और कुछ भी बदलने से पहले जानने योग्य बातें हैं।

---

## लाइसेंस (License)

MIT। देखें [LICENSE](LICENSE)।

---

## अस्वीकरण (Disclaimer)

> [!WARNING]
> **यह चिकित्सा उपकरण नहीं है।**
>
> यह व्यक्तिगत और सूचनात्मक उपयोग के लिए है। अंतराल चार्ट बीट-दर-बीट समय से बनाए गए हैं, ECG नहीं हैं, और अतालता पहचान RR अंतरालों पर आधारित एक अनुमान है, निदान नहीं। इससे चिकित्सा संबंधी निर्णय न लें। किसी योग्य स्वास्थ्य पेशेवर से परामर्श करें। इस सॉफ़्टवेयर के उपयोग से होने वाले किसी भी परिणाम के लिए लेखक उत्तरदायी नहीं हैं।
