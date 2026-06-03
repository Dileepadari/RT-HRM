-- Heart Rate Monitor - Database Schema
-- Run: mysql -u root < schema.sql

CREATE DATABASE IF NOT EXISTS heart_rate_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

USE heart_rate_db;

-- Heart Rate verileri
CREATE TABLE IF NOT EXISTS `heart_rate_logs` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `device_id` varchar(50) NOT NULL COMMENT 'Cihaz MAC adresi',
    `user_id` int(10) unsigned DEFAULT NULL COMMENT 'İlişkili kullanıcı (optional)',
    `heart_rate` smallint(5) unsigned NOT NULL COMMENT 'Heart Rate (BPM)',
    `rr_intervals` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'RR interval değerleri (ms)' CHECK (json_valid(`rr_intervals`)),
    `sensor_contact` tinyint(1) DEFAULT 1 COMMENT 'Sensör Contactı var mı',
    `battery_level` tinyint(3) unsigned DEFAULT NULL COMMENT 'Pil seviyesi (%)',
    `recorded_at` datetime(3) NOT NULL COMMENT 'Ölçüm zamanı (ms hassasiyet)',
    `created_at` timestamp NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_device_time` (`device_id`, `recorded_at`),
    KEY `idx_user_time` (`user_id`, `recorded_at`),
    KEY `idx_recorded` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Heart Rate verileri';

-- Heart Rate alarmları
CREATE TABLE IF NOT EXISTS `heart_rate_alerts` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `device_id` varchar(50) NOT NULL,
    `user_id` int(10) unsigned DEFAULT NULL,
    `alert_type` enum('low','high','no_signal') NOT NULL,
    `heart_rate` smallint(5) unsigned DEFAULT NULL,
    `message` text DEFAULT NULL,
    `acknowledged` tinyint(1) DEFAULT 0,
    `created_at` timestamp NULL DEFAULT current_timestamp(),
    `acknowledged_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_device_time` (`device_id`, `created_at`),
    KEY `idx_unacknowledged` (`acknowledged`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Heart Rate alarmları';

-- Hourly heart rate statistics
CREATE TABLE IF NOT EXISTS `heart_rate_stats` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `device_id` varchar(50) NOT NULL,
    `user_id` int(10) unsigned DEFAULT NULL,
    `stat_date` date NOT NULL,
    `stat_hour` tinyint(3) unsigned NOT NULL COMMENT '0-23 saat',
    `min_hr` smallint(5) unsigned DEFAULT NULL,
    `max_hr` smallint(5) unsigned DEFAULT NULL,
    `avg_hr` decimal(5,2) DEFAULT NULL,
    `sample_count` int(10) unsigned DEFAULT 0,
    `created_at` timestamp NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_device_date_hour` (`device_id`, `stat_date`, `stat_hour`),
    KEY `idx_user_date` (`user_id`, `stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Hourly heart rate statistics';

-- Aritmi olayları
CREATE TABLE IF NOT EXISTS `arrhythmia_events` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `device_id` varchar(50) NOT NULL COMMENT 'Cihaz MAC adresi',
    `event_type` varchar(30) NOT NULL COMMENT 'af, sinus_bradycardia, sinus_tachycardia, pvc, pac, svt, flutter, wenckebach, mobitz_ii',
    `severity` enum('info','warning','critical') NOT NULL DEFAULT 'warning',
    `confidence` decimal(5,2) NOT NULL COMMENT 'Güven skoru (0-100)',
    `heart_rate` smallint(5) unsigned DEFAULT NULL COMMENT 'Heart rate at time of detection',
    `metrics` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Hesaplanan metrikler (JSON)' CHECK (json_valid(`metrics`)),
    `rr_window` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Analiz edilen RR penceresi (JSON)' CHECK (json_valid(`rr_window`)),
    `window_size` int(10) unsigned DEFAULT NULL COMMENT 'Penceredeki RR sayısı',
    `message` varchar(255) DEFAULT NULL COMMENT 'Okunabilir mesaj',
    `created_at` timestamp NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_device_time` (`device_id`, `created_at`),
    KEY `idx_type_time` (`event_type`, `created_at`),
    KEY `idx_severity` (`severity`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Aritmi olayları';

-- Bradikardi Episodesı (HR < 50 BPM, 5+ saniye süren dönemler)
CREATE TABLE IF NOT EXISTS `bradycardia_episodes` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `device_id` varchar(50) NOT NULL COMMENT 'Cihaz MAC adresi',
    `status` enum('active','completed') NOT NULL DEFAULT 'active' COMMENT 'Epizod durumu',
    `started_at` datetime(3) NOT NULL COMMENT 'Epizod başlangıç zamanı',
    `ended_at` datetime(3) DEFAULT NULL COMMENT 'Epizod bitiş zamanı',
    `duration_seconds` int(10) unsigned DEFAULT NULL COMMENT 'Toplam süre (saniye)',
    `reading_count` int(10) unsigned NOT NULL DEFAULT 1 COMMENT 'Ölçüm sayısı',
    `min_hr` smallint(5) unsigned NOT NULL COMMENT 'Minimum heart rate',
    `max_hr` smallint(5) unsigned NOT NULL COMMENT 'Maximum heart rate',
    `avg_hr` decimal(5,2) NOT NULL COMMENT 'Average heart rate',
    `hr_sum` int(10) unsigned NOT NULL COMMENT 'HR toplamı (avg hesaplama)',
    `recovery_hr` smallint(5) unsigned DEFAULT NULL COMMENT 'Toparlanma nabzı (ilk HR >= 50)',
    `recovery_at` datetime(3) DEFAULT NULL COMMENT 'Toparlanma zamanı',
    `created_at` timestamp NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_device_status` (`device_id`, `status`),
    KEY `idx_device_time` (`device_id`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Bradikardi Episodesı';

-- Push notification token'ları
CREATE TABLE IF NOT EXISTS `push_tokens` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `device_id` varchar(50) DEFAULT NULL COMMENT 'Cihaz MAC adresi (optional)',
    `fcm_token` varchar(512) NOT NULL COMMENT 'Firebase Cloud Messaging token',
    `platform` enum('android','ios','web') NOT NULL DEFAULT 'android',
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` timestamp NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fcm_token` (`fcm_token`),
    KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Push notification token\'ları';
