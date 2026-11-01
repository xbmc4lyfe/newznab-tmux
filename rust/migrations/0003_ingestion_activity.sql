-- When ingestion was last active, and an outage's shift of waiting collections while it runs. Role
-- containers don't share a filesystem, so this replaces the file the PHP monitor wrote.
CREATE TABLE IF NOT EXISTS `ingestion_activity` (
  `id` tinyint(3) unsigned NOT NULL,
  `last_active_at` bigint(20) NOT NULL,
  `shift_last_active` bigint(20) DEFAULT NULL,
  `shift_now` bigint(20) DEFAULT NULL,
  `shift_delay_seconds` bigint(20) DEFAULT NULL,
  `shift_upper_id` bigint(20) unsigned DEFAULT NULL,
  `shift_window_end` datetime DEFAULT NULL,
  `shift_inclusive` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
