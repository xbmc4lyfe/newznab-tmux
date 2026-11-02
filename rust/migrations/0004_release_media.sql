-- What the media probe read from a release's first articles: one row per release, with the fields
-- search and the API filter on as columns and the full summary as JSON.
CREATE TABLE IF NOT EXISTS `release_media` (
  `releases_id` int(10) unsigned NOT NULL,
  `status` varchar(24) NOT NULL COMMENT 'read, not_video, encrypted, compressed, unreadable, failed',
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `bytes_fetched` bigint(20) unsigned NOT NULL DEFAULT 0,
  `articles_fetched` smallint(5) unsigned NOT NULL DEFAULT 0,
  `inner_name` varchar(255) DEFAULT NULL,
  `inner_size` bigint(20) unsigned DEFAULT NULL,
  `container` varchar(16) DEFAULT NULL,
  `duration_ms` bigint(20) unsigned DEFAULT NULL,
  `segment_uid` char(32) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `chapter_signature` char(16) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `oshash` char(16) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `poster_signature` char(16) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `video_codec` varchar(16) DEFAULT NULL,
  `resolution` varchar(8) DEFAULT NULL,
  `hdr` varchar(32) DEFAULT NULL COMMENT 'Space-separated: DV HDR10+ HDR10 HLG SDR',
  `dv_profile` varchar(8) DEFAULT NULL,
  `dv_el` varchar(3) DEFAULT NULL COMMENT 'MEL or FEL',
  `dv_cm` varchar(3) DEFAULT NULL,
  `atmos` tinyint(1) NOT NULL DEFAULT 0,
  `dtsx` tinyint(1) NOT NULL DEFAULT 0,
  `audio_languages` varchar(255) DEFAULT NULL COMMENT 'Comma-separated ISO 639-2/B',
  `subtitle_languages` varchar(255) DEFAULT NULL COMMENT 'Comma-separated ISO 639-2/B',
  `chapter_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `commentary_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `tags` varchar(512) DEFAULT NULL COMMENT 'Space-separated search tokens',
  `summary` json DEFAULT NULL,
  `posting` json DEFAULT NULL COMMENT 'Poster signature fields and yEnc CRC32s seen',
  `error` varchar(255) DEFAULT NULL,
  `probed_at` datetime NOT NULL,
  PRIMARY KEY (`releases_id`),
  KEY `release_media_segment_uid` (`segment_uid`),
  KEY `release_media_chapter_signature` (`chapter_signature`),
  KEY `release_media_oshash` (`oshash`),
  KEY `release_media_status` (`status`, `probed_at`),
  CONSTRAINT `release_media_release` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The default edition's chapters.
CREATE TABLE IF NOT EXISTS `release_chapters` (
  `releases_id` int(10) unsigned NOT NULL,
  `idx` smallint(5) unsigned NOT NULL,
  `start_ms` bigint(20) unsigned NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`releases_id`, `idx`),
  CONSTRAINT `release_chapters_release` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every identification attempt the probe made, cheapest method first. A hit carries the IDs found;
-- whether it was applied to the release is recorded beside it.
CREATE TABLE IF NOT EXISTS `release_media_identifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL,
  `method` varchar(24) NOT NULL,
  `outcome` varchar(16) NOT NULL COMMENT 'hit, miss, skipped, error',
  `bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `imdbid` varchar(16) DEFAULT NULL,
  `tmdbid` int(10) unsigned DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `year` smallint(5) unsigned DEFAULT NULL,
  `season` smallint(5) unsigned DEFAULT NULL,
  `episode` smallint(5) unsigned DEFAULT NULL,
  `detail` varchar(512) DEFAULT NULL,
  `applied` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `release_media_identifications_release` (`releases_id`),
  KEY `release_media_identifications_method` (`method`, `outcome`),
  CONSTRAINT `release_media_identifications_release` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
