-- Ramza mobile API v1 foundation
-- Additive only. Apply to an existing installation after a verified backup.
-- The same definitions are included in ramza.sql for new installations.

CREATE TABLE IF NOT EXISTS `Ramza_MobileApiClients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_client_id` varchar(80) NOT NULL,
  `display_name` varchar(120) NOT NULL DEFAULT 'Ramza Mobile',
  `android_package` varchar(190) NOT NULL DEFAULT '',
  `ios_bundle_id` varchar(190) NOT NULL DEFAULT '',
  `enabled` tinyint unsigned NOT NULL DEFAULT 0,
  `created_at` int unsigned NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ramza_mobile_public_client` (`public_client_id`),
  KEY `idx_ramza_mobile_client_enabled` (`enabled`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Ramza_MobileSessions` (
  `session_id` char(36) NOT NULL,
  `family_id` char(36) NOT NULL,
  `user_id` int unsigned NOT NULL,
  `client_id` bigint unsigned NOT NULL,
  `installation_id` varchar(128) NOT NULL,
  `platform` enum('android','ios') NOT NULL,
  `access_token_hash` char(64) NOT NULL,
  `refresh_token_hash` char(64) NOT NULL,
  `previous_refresh_hash` char(64) DEFAULT NULL,
  `access_expires_at` int unsigned NOT NULL,
  `refresh_expires_at` int unsigned NOT NULL,
  `last_seen_at` int unsigned NOT NULL,
  `revoked_at` int unsigned DEFAULT NULL,
  `revocation_reason` varchar(64) DEFAULT NULL,
  `ip_hash` char(64) NOT NULL,
  `user_agent_hash` char(64) NOT NULL,
  `created_at` int unsigned NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `uq_ramza_mobile_access_hash` (`access_token_hash`),
  UNIQUE KEY `uq_ramza_mobile_refresh_hash` (`refresh_token_hash`),
  KEY `idx_ramza_mobile_previous_refresh` (`previous_refresh_hash`),
  KEY `idx_ramza_mobile_session_user` (`user_id`,`revoked_at`,`last_seen_at`),
  KEY `idx_ramza_mobile_session_family` (`family_id`,`revoked_at`),
  KEY `idx_ramza_mobile_session_expiry` (`refresh_expires_at`,`revoked_at`),
  CONSTRAINT `fk_ramza_mobile_session_client` FOREIGN KEY (`client_id`) REFERENCES `Ramza_MobileApiClients` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Ramza_MobileSettings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` mediumtext NOT NULL,
  `is_encrypted` tinyint unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `Ramza_MobileSettings` (`setting_key`,`setting_value`,`is_encrypted`,`updated_at`) VALUES
('mobile_enabled','0',0,UNIX_TIMESTAMP()),
('app_name','Ramza',0,UNIX_TIMESTAMP()),
('primary_color','#c64d53',0,UNIX_TIMESTAMP()),
('secondary_color','#1e2321',0,UNIX_TIMESTAMP()),
('minimum_android_version','1.0.0',0,UNIX_TIMESTAMP()),
('minimum_ios_version','1.0.0',0,UNIX_TIMESTAMP()),
('latest_android_version','1.0.0',0,UNIX_TIMESTAMP()),
('latest_ios_version','1.0.0',0,UNIX_TIMESTAMP()),
('force_update','0',0,UNIX_TIMESTAMP()),
('maintenance_mode','0',0,UNIX_TIMESTAMP()),
('maintenance_message','',0,UNIX_TIMESTAMP()),
('android_store_url','',0,UNIX_TIMESTAMP()),
('ios_store_url','',0,UNIX_TIMESTAMP()),
('terms_url','',0,UNIX_TIMESTAMP()),
('privacy_url','',0,UNIX_TIMESTAMP());

CREATE TABLE IF NOT EXISTS `Ramza_MobileLicenseCache` (
  `id` tinyint unsigned NOT NULL DEFAULT 1,
  `license_id` varchar(100) NOT NULL DEFAULT '',
  `installation_id` varchar(100) NOT NULL DEFAULT '',
  `status` enum('unconfigured','active','grace','expired','suspended','revoked','invalid','unavailable') NOT NULL DEFAULT 'unconfigured',
  `features_json` text NOT NULL,
  `signed_payload` mediumtext NOT NULL,
  `signature` text NOT NULL,
  `verified_at` int unsigned NOT NULL DEFAULT 0,
  `expires_at` int unsigned NOT NULL DEFAULT 0,
  `grace_until` int unsigned NOT NULL DEFAULT 0,
  `next_check_at` int unsigned NOT NULL DEFAULT 0,
  `last_error_code` varchar(64) NOT NULL DEFAULT '',
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `Ramza_MobileLicenseCache`
(`id`,`status`,`features_json`,`signed_payload`,`signature`,`updated_at`)
VALUES (1,'unconfigured','[]','','',UNIX_TIMESTAMP());

CREATE TABLE IF NOT EXISTS `Ramza_MobileRateLimits` (
  `scope` varchar(64) NOT NULL,
  `bucket_hash` char(64) NOT NULL,
  `window_started_at` int unsigned NOT NULL,
  `hits` int unsigned NOT NULL,
  `expires_at` int unsigned NOT NULL,
  PRIMARY KEY (`scope`,`bucket_hash`),
  KEY `idx_ramza_mobile_rate_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Ramza_MobileAuditLog` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_id` varchar(128) NOT NULL,
  `event_type` varchar(80) NOT NULL,
  `actor_user_id` int unsigned NOT NULL DEFAULT 0,
  `client_id` bigint unsigned NOT NULL DEFAULT 0,
  `installation_hash` char(64) NOT NULL DEFAULT '',
  `ip_hash` char(64) NOT NULL DEFAULT '',
  `outcome` varchar(32) NOT NULL,
  `metadata_json` text NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ramza_mobile_audit_event` (`event_type`,`created_at`),
  KEY `idx_ramza_mobile_audit_user` (`actor_user_id`,`created_at`),
  KEY `idx_ramza_mobile_audit_client` (`client_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Ramza_MobileAuthChallenges` (
  `challenge_id` char(36) NOT NULL,
  `purpose` enum('activation','password_reset','two_factor','unusual_login') NOT NULL,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `client_id` bigint unsigned NOT NULL,
  `installation_id` varchar(128) NOT NULL,
  `secret_hash` char(64) NOT NULL DEFAULT '',
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `max_attempts` tinyint unsigned NOT NULL DEFAULT 6,
  `expires_at` int unsigned NOT NULL,
  `consumed_at` int unsigned DEFAULT NULL,
  `created_at` int unsigned NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`challenge_id`),
  KEY `idx_ramza_mobile_challenge_user` (`user_id`,`purpose`,`consumed_at`),
  KEY `idx_ramza_mobile_challenge_expiry` (`expires_at`,`consumed_at`),
  KEY `idx_ramza_mobile_challenge_client` (`client_id`,`installation_id`,`purpose`),
  CONSTRAINT `fk_ramza_mobile_challenge_client` FOREIGN KEY (`client_id`) REFERENCES `Ramza_MobileApiClients` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Ramza_MobileSocialIdentities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(32) NOT NULL,
  `provider_subject` varchar(190) NOT NULL,
  `user_id` int unsigned NOT NULL,
  `verified_email` varchar(190) NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ramza_mobile_social_subject` (`provider`,`provider_subject`),
  KEY `idx_ramza_mobile_social_user` (`user_id`,`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
