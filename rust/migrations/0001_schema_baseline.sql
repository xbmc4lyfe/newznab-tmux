SET FOREIGN_KEY_CHECKS=0;
CREATE TABLE `anidb_info` (
  `anidbid` int(10) unsigned NOT NULL COMMENT 'ID of title from AniDB',
  `anilist_id` int(10) unsigned DEFAULT NULL COMMENT 'ID from AniList',
  `mal_id` int(10) unsigned DEFAULT NULL COMMENT 'ID from MyAnimeList',
  `country` char(2) DEFAULT NULL COMMENT 'ISO 3166-1 alpha-2 country code',
  `media_type` varchar(10) DEFAULT NULL COMMENT 'ANIME or MANGA',
  `type` varchar(32) DEFAULT NULL,
  `episodes` int(10) unsigned DEFAULT NULL,
  `duration` int(10) unsigned DEFAULT NULL COMMENT 'Duration in minutes',
  `status` varchar(20) DEFAULT NULL COMMENT 'Media status (FINISHED, RELEASING, etc.)',
  `source` varchar(20) DEFAULT NULL COMMENT 'Original source (MANGA, ORIGINAL, etc.)',
  `hashtag` varchar(255) DEFAULT NULL COMMENT 'AniList hashtag',
  `startdate` date DEFAULT NULL,
  `enddate` date DEFAULT NULL,
  `updated` timestamp NOT NULL DEFAULT current_timestamp(),
  `related` varchar(1024) DEFAULT NULL,
  `similar` varchar(1024) DEFAULT NULL,
  `creators` varchar(1024) DEFAULT NULL,
  `description` mediumtext DEFAULT NULL,
  `rating` varchar(5) DEFAULT NULL,
  `picture` varchar(255) DEFAULT NULL,
  `categories` varchar(1024) DEFAULT NULL,
  `characters` varchar(1024) DEFAULT NULL,
  PRIMARY KEY (`anidbid`),
  KEY `ix_anidb_info_datetime` (`startdate`,`enddate`,`updated`),
  KEY `ix_anidb_info_anilist_id` (`anilist_id`),
  KEY `ix_anidb_info_mal_id` (`mal_id`),
  KEY `ix_anidb_info_country` (`country`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `anidb_titles` (
  `anidbid` int(10) unsigned NOT NULL COMMENT 'ID of title from AniDB',
  `type` varchar(25) NOT NULL COMMENT 'type of title.',
  `lang` varchar(25) NOT NULL,
  `title` varchar(255) NOT NULL,
  PRIMARY KEY (`anidbid`,`type`,`lang`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `api_token_ip_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(10) unsigned NOT NULL,
  `api_token` varchar(64) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `first_seen_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `request_count` int(10) unsigned NOT NULL DEFAULT 1,
  `is_rate_limited` tinyint(1) NOT NULL DEFAULT 0,
  `rate_limited_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_token_ip_addresses_api_token_ip_address_unique` (`api_token`,`ip_address`),
  KEY `api_token_ip_addresses_users_id_index` (`users_id`),
  KEY `api_token_ip_addresses_is_rate_limited_index` (`is_rate_limited`),
  KEY `api_token_ip_addresses_last_seen_at_index` (`last_seen_at`),
  KEY `api_token_ip_addresses_api_token_index` (`api_token`),
  CONSTRAINT `api_token_ip_addresses_users_id_foreign` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `audio_data` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `audioid` int(10) unsigned NOT NULL,
  `audioformat` varchar(50) DEFAULT NULL,
  `audiomode` varchar(50) DEFAULT NULL,
  `audiobitratemode` varchar(50) DEFAULT NULL,
  `audiobitrate` varchar(10) DEFAULT NULL,
  `audiochannels` varchar(25) DEFAULT NULL,
  `audiosamplerate` varchar(25) DEFAULT NULL,
  `audiolibrary` varchar(50) DEFAULT NULL,
  `audiolanguage` varchar(50) DEFAULT NULL,
  `audiotitle` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_releaseaudio_releaseid_audioid` (`releases_id`,`audioid`),
  CONSTRAINT `FK_ad_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `binaries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `binaryhash` binary(16) NOT NULL,
  `name` varchar(1000) NOT NULL DEFAULT '',
  `collections_id` int(10) unsigned NOT NULL DEFAULT 0,
  `filenumber` int(10) unsigned NOT NULL DEFAULT 0,
  `totalparts` int(10) unsigned NOT NULL DEFAULT 0,
  `currentparts` int(10) unsigned NOT NULL DEFAULT 0,
  `partcheck` tinyint(1) NOT NULL DEFAULT 0,
  `partsize` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_binaries_collection_hash` (`collections_id`,`binaryhash`),
  KEY `ix_binaries_collection` (`collections_id`),
  KEY `ix_binaries_partcheck` (`partcheck`),
  KEY `ix_binaries_collection_filenumber` (`collections_id`,`filenumber`),
  CONSTRAINT `FK_Collections` FOREIGN KEY (`collections_id`) REFERENCES `collections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `binaryblacklist` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `groupname` varchar(255) DEFAULT NULL,
  `regex` varchar(2000) NOT NULL,
  `msgcol` int(10) unsigned NOT NULL DEFAULT 1,
  `optype` int(10) unsigned NOT NULL DEFAULT 1,
  `status` int(10) unsigned NOT NULL DEFAULT 1,
  `description` varchar(1000) DEFAULT NULL,
  `last_activity` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_binaryblacklist_groupname` (`groupname`),
  KEY `ix_binaryblacklist_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `bookinfo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `author` varchar(255) NOT NULL,
  `asin` varchar(128) DEFAULT NULL,
  `isbn` varchar(128) DEFAULT NULL,
  `ean` varchar(128) DEFAULT NULL,
  `url` varchar(1000) DEFAULT NULL,
  `salesrank` int(10) unsigned DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `publishdate` datetime DEFAULT NULL,
  `pages` varchar(128) DEFAULT NULL,
  `overview` varchar(3000) DEFAULT NULL,
  `genre` varchar(255) NOT NULL,
  `cover` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_bookinfo_asin` (`asin`),
  KEY `ix_bookinfo_isbn` (`isbn`),
  KEY `ix_bookinfo_ean` (`ean`),
  FULLTEXT KEY `ix_bookinfo_author_title_ft` (`author`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` longtext NOT NULL,
  `expiration` int(11) NOT NULL,
  UNIQUE KEY `cache_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `root_categories_id` bigint(20) unsigned DEFAULT NULL,
  `status` int(11) NOT NULL DEFAULT 1,
  `description` varchar(255) DEFAULT NULL,
  `disablepreview` tinyint(1) NOT NULL DEFAULT 0,
  `minsizetoformrelease` bigint(20) unsigned NOT NULL DEFAULT 0,
  `maxsizetoformrelease` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_categories_parentid` (`root_categories_id`),
  KEY `ix_categories_status` (`status`),
  CONSTRAINT `fk_root_categories_id` FOREIGN KEY (`root_categories_id`) REFERENCES `root_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `category_regexes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_regex` varchar(255) NOT NULL DEFAULT '' COMMENT 'This is a regex to match against usenet groups',
  `regex` varchar(5000) NOT NULL DEFAULT '' COMMENT 'Regex used to match a release name to categorize it',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=ON 0=OFF',
  `description` varchar(1000) NOT NULL DEFAULT '' COMMENT 'Optional extra details on this regex',
  `ordinal` int(11) NOT NULL DEFAULT 0 COMMENT 'Order to run the regex in',
  `categories_id` smallint(5) unsigned NOT NULL DEFAULT 10 COMMENT 'Which categories id to put the release in',
  PRIMARY KEY (`id`),
  KEY `ix_category_regexes_group_regex` (`group_regex`),
  KEY `ix_category_regexes_status` (`status`),
  KEY `ix_category_regexes_ordinal` (`ordinal`),
  KEY `ix_category_regexes_categories_id` (`categories_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `cbp_optimization_checkpoints` (
  `step_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `completed_at` datetime NOT NULL,
  PRIMARY KEY (`step_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `collection_groups` (
  `collections_id` int(10) unsigned NOT NULL,
  `group_name` varchar(255) NOT NULL,
  PRIMARY KEY (`collections_id`,`group_name`),
  CONSTRAINT `fk_collection_groups_collection` FOREIGN KEY (`collections_id`) REFERENCES `collections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `collection_regexes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_regex` varchar(255) NOT NULL DEFAULT '' COMMENT 'This is a regex to match against usenet groups',
  `regex` varchar(5000) NOT NULL DEFAULT '' COMMENT 'Regex used for collection grouping',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=ON 0=OFF',
  `description` varchar(1000) NOT NULL COMMENT 'Optional extra details on this regex',
  `ordinal` int(11) NOT NULL DEFAULT 0 COMMENT 'Order to run the regex in',
  PRIMARY KEY (`id`),
  KEY `ix_collection_regexes_group_regex` (`group_regex`),
  KEY `ix_collection_regexes_status` (`status`),
  KEY `ix_collection_regexes_ordinal` (`ordinal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `collections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `subject` varchar(255) NOT NULL DEFAULT '',
  `fromname` varchar(255) NOT NULL DEFAULT '',
  `date` datetime DEFAULT NULL,
  `xref` varchar(2000) NOT NULL DEFAULT '',
  `totalfiles` int(10) unsigned NOT NULL DEFAULT 0,
  `groups_id` int(10) unsigned NOT NULL DEFAULT 0,
  `collectionhash` binary(20) NOT NULL,
  `collection_regexes_id` int(11) NOT NULL DEFAULT 0 COMMENT 'FK to collection_regexes.id',
  `dateadded` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `added` timestamp NOT NULL DEFAULT current_timestamp(),
  `filecheck` tinyint(1) NOT NULL DEFAULT 0,
  `filesize` bigint(20) unsigned NOT NULL DEFAULT 0,
  `releases_id` int(11) DEFAULT NULL,
  `release_creation_claimed_at` timestamp NULL DEFAULT NULL,
  `release_creation_claim_token` varchar(64) DEFAULT NULL,
  `release_creation_failures` smallint(5) unsigned NOT NULL DEFAULT 0,
  `release_creation_failed_at` timestamp NULL DEFAULT NULL,
  `release_creation_error` varchar(512) DEFAULT NULL,
  `noise` char(32) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_collection_collectionhash` (`collectionhash`),
  KEY `fromname` (`fromname`),
  KEY `date` (`date`),
  KEY `groups_id` (`groups_id`),
  KEY `ix_collection_dateadded` (`dateadded`),
  KEY `ix_collection_filecheck` (`filecheck`),
  KEY `ix_collection_releaseid` (`releases_id`),
  KEY `collections_groups_added_idx` (`groups_id`,`added`),
  KEY `ix_collections_filecheck_filesize_groups` (`filecheck`,`filesize`,`groups_id`),
  KEY `ix_collections_release_creation_claim_queue` (`filecheck`,`groups_id`,`release_creation_claimed_at`,`id`),
  KEY `ix_collections_group_filecheck_seen_id` (`groups_id`,`filecheck`,`last_seen_at`,`id`),
  KEY `ix_collections_group_filecheck_id` (`groups_id`,`filecheck`,`id`),
  KEY `ix_collections_group_filecheck_size_id` (`groups_id`,`filecheck`,`filesize`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `consoleinfo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `asin` varchar(128) DEFAULT NULL,
  `url` varchar(1000) DEFAULT NULL,
  `salesrank` int(10) unsigned DEFAULT NULL,
  `platform` varchar(255) DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `genres_id` int(11) DEFAULT NULL,
  `esrb` varchar(255) DEFAULT NULL,
  `releasedate` datetime DEFAULT NULL,
  `review` varchar(3000) DEFAULT NULL,
  `cover` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_consoleinfo_asin` (`asin`),
  KEY `ix_consoleinfo_genres_id` (`genres_id`),
  FULLTEXT KEY `ix_consoleinfo_title_platform_ft` (`title`,`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `content` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `url` varchar(2000) DEFAULT NULL,
  `body` mediumtext DEFAULT NULL,
  `metadescription` varchar(1000) NOT NULL,
  `metakeywords` varchar(1000) NOT NULL,
  `contenttype` int(11) NOT NULL,
  `status` int(11) NOT NULL,
  `ordinal` int(11) DEFAULT NULL,
  `role` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_status_contenttype_role` (`status`,`contenttype`,`role`),
  KEY `ix_content_type_ordinal` (`contenttype`,`ordinal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `countries` (
  `iso_3166_2` char(2) NOT NULL,
  `name` varchar(255) NOT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`iso_3166_2`),
  KEY `countries_name_index` (`name`),
  KEY `countries_full_name_index` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `dnzb_failures` (
  `release_id` int(10) unsigned NOT NULL,
  `users_id` int(10) unsigned NOT NULL,
  `failed` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`release_id`,`users_id`),
  KEY `FK_users_df` (`users_id`),
  CONSTRAINT `FK_df_releases` FOREIGN KEY (`release_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_users_df` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `download_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `searchname` varchar(255) DEFAULT NULL,
  `grabs` int(11) NOT NULL DEFAULT 0,
  `guid` varchar(255) DEFAULT NULL,
  `adddate` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_download_stats_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `connection` mediumtext NOT NULL,
  `queue` mediumtext NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uuid` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `firewall` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(39) NOT NULL,
  `whitelisted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `firewall_ip_address_unique` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `forum_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `accepts_threads` tinyint(1) NOT NULL DEFAULT 0,
  `newest_thread_id` int(10) unsigned DEFAULT NULL,
  `latest_active_thread_id` int(10) unsigned DEFAULT NULL,
  `thread_count` int(11) NOT NULL DEFAULT 0,
  `post_count` int(11) NOT NULL DEFAULT 0,
  `is_private` tinyint(1) NOT NULL DEFAULT 0,
  `thread_approval_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `post_approval_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `_lft` int(10) unsigned NOT NULL DEFAULT 0,
  `_rgt` int(10) unsigned NOT NULL DEFAULT 0,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `color_light_mode` varchar(255) DEFAULT NULL,
  `color_dark_mode` varchar(255) DEFAULT NULL,
  `depth` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `forum_categories__lft__rgt_parent_id_index` (`_lft`,`_rgt`,`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `forum_posts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `thread_id` int(10) unsigned NOT NULL,
  `author_id` bigint(20) unsigned NOT NULL,
  `content` mediumtext NOT NULL,
  `post_id` int(10) unsigned DEFAULT NULL,
  `sequence` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `forum_posts_thread_id_index` (`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `forum_threads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int(10) unsigned NOT NULL,
  `author_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `pinned` tinyint(1) DEFAULT 0,
  `locked` tinyint(1) DEFAULT 0,
  `first_post_id` int(10) unsigned DEFAULT NULL,
  `last_post_id` int(10) unsigned DEFAULT NULL,
  `reply_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `forum_threads_category_id_index` (`category_id`),
  KEY `ix_forum_threads_author_id` (`author_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `forum_threads_read` (
  `thread_id` int(10) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  KEY `ix_forum_threads_read_user_thread` (`user_id`,`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `forumpost` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `forumid` int(11) NOT NULL DEFAULT 1,
  `parentid` int(11) NOT NULL DEFAULT 0,
  `users_id` int(10) unsigned NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` mediumtext NOT NULL,
  `locked` tinyint(1) NOT NULL DEFAULT 0,
  `sticky` tinyint(1) NOT NULL DEFAULT 0,
  `replies` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `parentid` (`parentid`),
  KEY `userid` (`users_id`),
  CONSTRAINT `FK_users_fp` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `gamesinfo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `asin` varchar(128) DEFAULT NULL,
  `url` varchar(1000) DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `genres_id` int(11) DEFAULT NULL,
  `esrb` varchar(255) DEFAULT NULL,
  `releasedate` datetime DEFAULT NULL,
  `review` varchar(3000) DEFAULT NULL,
  `cover` tinyint(1) NOT NULL DEFAULT 0,
  `backdrop` tinyint(1) NOT NULL DEFAULT 0,
  `trailer` varchar(1000) NOT NULL DEFAULT '',
  `classused` varchar(10) NOT NULL DEFAULT 'steam',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_gamesinfo_asin` (`asin`),
  KEY `ix_gamesinfo_genres_id` (`genres_id`),
  FULLTEXT KEY `ix_title_ft` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `gdpr_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gdpr_request_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `event` varchar(64) NOT NULL,
  `description` text NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_gdpr_audit_request_created` (`gdpr_request_id`,`created_at`),
  KEY `ix_gdpr_audit_user_created` (`user_id`,`created_at`),
  KEY `ix_gdpr_audit_event_created` (`event`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `gdpr_consents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `consent_type` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'granted',
  `policy_version` varchar(64) DEFAULT NULL,
  `consented_at` timestamp NULL DEFAULT NULL,
  `withdrawn_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent_hash` varchar(64) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_gdpr_consents_user_type` (`user_id`,`consent_type`),
  KEY `ix_gdpr_consents_type_status` (`consent_type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `gdpr_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `requester_username` varchar(255) DEFAULT NULL,
  `requester_email` varchar(255) DEFAULT NULL,
  `type` varchar(32) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `request_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_payload`)),
  `response_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_payload`)),
  `notes` text DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `export_disk` varchar(255) DEFAULT NULL,
  `export_path` varchar(255) DEFAULT NULL,
  `export_expires_at` timestamp NULL DEFAULT NULL,
  `processed_by` bigint(20) unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_gdpr_requests_user_type_status` (`user_id`,`type`,`status`),
  KEY `ix_gdpr_requests_status_created` (`status`,`created_at`),
  KEY `ix_gdpr_requests_type_created` (`type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `genres` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `type` int(11) DEFAULT NULL,
  `disabled` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_genres_type_disabled` (`type`,`disabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `grab_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `grabs` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_grab_stats_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `ingestion_configurations` (
  `id` tinyint(3) unsigned NOT NULL,
  `binary_threads` smallint(5) unsigned NOT NULL DEFAULT 1,
  `backfill_threads` smallint(5) unsigned NOT NULL DEFAULT 1,
  `release_threads` smallint(5) unsigned NOT NULL DEFAULT 1,
  `collection_delay_hours` int(10) unsigned NOT NULL DEFAULT 0,
  `collection_timeout_hours` int(10) unsigned NOT NULL DEFAULT 0,
  `cross_post_hours` int(10) unsigned NOT NULL DEFAULT 0,
  `completion_percent` int(10) unsigned NOT NULL DEFAULT 0,
  `grab_status` tinyint(1) NOT NULL DEFAULT 0,
  `max_headers_per_iteration` bigint(20) unsigned NOT NULL DEFAULT 0,
  `max_messages` bigint(20) unsigned NOT NULL DEFAULT 0,
  `max_releases_created` bigint(20) unsigned NOT NULL DEFAULT 0,
  `nntp_retries` int(10) unsigned NOT NULL DEFAULT 0,
  `nzb_split_level` int(10) unsigned NOT NULL DEFAULT 0,
  `part_retention_hours` int(10) unsigned NOT NULL DEFAULT 0,
  `release_retention_days` int(10) unsigned NOT NULL DEFAULT 0,
  `misc_other_retention_hours` int(10) unsigned NOT NULL DEFAULT 0,
  `misc_hashed_retention_hours` int(10) unsigned NOT NULL DEFAULT 0,
  `min_files_to_form_release` int(10) unsigned NOT NULL DEFAULT 0,
  `min_size_to_form_release` bigint(20) unsigned NOT NULL DEFAULT 0,
  `max_size_to_form_release` bigint(20) unsigned NOT NULL DEFAULT 0,
  `new_group_scan_method` int(10) unsigned NOT NULL DEFAULT 0,
  `new_group_days_to_scan` int(10) unsigned NOT NULL DEFAULT 0,
  `new_group_messages_to_scan` bigint(20) unsigned NOT NULL DEFAULT 0,
  `safe_backfill_date` date NOT NULL,
  `disable_backfill_group` tinyint(1) NOT NULL DEFAULT 0,
  `part_repair` tinyint(1) NOT NULL DEFAULT 0,
  `safe_part_repair` tinyint(1) NOT NULL DEFAULT 0,
  `max_part_repair` bigint(20) unsigned NOT NULL DEFAULT 0,
  `part_repair_max_tries` int(10) unsigned NOT NULL DEFAULT 0,
  `categorize_foreign` tinyint(1) NOT NULL DEFAULT 0,
  `categorize_web_dl` tinyint(1) NOT NULL DEFAULT 0,
  `show_passworded_releases` tinyint(1) NOT NULL DEFAULT 0,
  `delete_passworded_releases` tinyint(1) NOT NULL DEFAULT 0,
  `backfill_days_mode` int(10) unsigned NOT NULL DEFAULT 0,
  `backfill_order` int(10) unsigned NOT NULL DEFAULT 0,
  `backfill_quantity` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `invitations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `email` varchar(255) NOT NULL,
  `invited_by` bigint(20) unsigned NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `used_by` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `metadata` longtext DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invitations_token_unique` (`token`),
  KEY `invitations_invited_by_index` (`invited_by`),
  KEY `invitations_used_by_index` (`used_by`),
  KEY `invitations_token_is_active_index` (`token`,`is_active`),
  KEY `invitations_email_is_active_index` (`email`,`is_active`),
  KEY `invitations_expires_at_index` (`expires_at`),
  KEY `ix_invitations_active_expires` (`is_active`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `log_index_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `path` varchar(255) NOT NULL,
  `device` bigint(20) unsigned NOT NULL,
  `inode` bigint(20) unsigned NOT NULL,
  `head_hash` char(40) NOT NULL,
  `head_length` int(10) unsigned NOT NULL,
  `structured` tinyint(1) NOT NULL DEFAULT 1,
  `indexed_offset` bigint(20) unsigned NOT NULL DEFAULT 0,
  `indexed_line` bigint(20) unsigned NOT NULL DEFAULT 0,
  `indexed_size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `indexed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `log_index_files_device_inode_unique` (`device`,`inode`),
  KEY `log_index_files_path_index` (`path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `logging` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `time` datetime DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `host` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `media_infos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` bigint(20) unsigned NOT NULL,
  `movie_name` varchar(255) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `unique_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_media_infos_unique_id` (`unique_id`),
  KEY `ix_media_infos_releases_id` (`releases_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `metadata_configurations` (
  `id` tinyint(3) unsigned NOT NULL,
  `anime_lookup` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `book_lookup` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `game_lookup` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `movie_lookup` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `music_lookup` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `tv_lookup` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `movie_language` varchar(8) NOT NULL DEFAULT 'en',
  `imdb_alternate_url` tinyint(1) NOT NULL DEFAULT 0,
  `max_anime_processed` int(10) unsigned NOT NULL DEFAULT 0,
  `max_books_processed` int(10) unsigned NOT NULL DEFAULT 0,
  `max_games_processed` int(10) unsigned NOT NULL DEFAULT 0,
  `max_movies_processed` int(10) unsigned NOT NULL DEFAULT 0,
  `max_music_processed` int(10) unsigned NOT NULL DEFAULT 0,
  `max_tv_processed` int(10) unsigned NOT NULL DEFAULT 0,
  `amazon_sleep_milliseconds` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `missed_parts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numberid` bigint(20) unsigned NOT NULL,
  `groups_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to groups.id',
  `attempts` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_missed_parts_numberid_groupsid` (`numberid`,`groups_id`),
  KEY `ix_missed_parts_groupid_attempts` (`groups_id`,`attempts`),
  KEY `ix_missed_parts_numberid_groupsid_attempts` (`numberid`,`groups_id`,`attempts`),
  KEY `ix_missed_parts_attempts` (`attempts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `model_has_permissions` (
  `permission_id` int(10) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_type_model_id_index` (`model_type`,`model_id`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `model_has_roles` (
  `role_id` int(10) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_type_model_id_index` (`model_type`,`model_id`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `movieinfo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `imdbid` varchar(100) NOT NULL,
  `tmdbid` int(10) unsigned NOT NULL DEFAULT 0,
  `traktid` int(10) unsigned NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL DEFAULT '',
  `tagline` varchar(1024) NOT NULL DEFAULT '',
  `rating` varchar(4) NOT NULL DEFAULT '',
  `rtrating` varchar(10) NOT NULL DEFAULT '' COMMENT 'RottenTomatoes rating score',
  `plot` varchar(1024) NOT NULL DEFAULT '',
  `year` varchar(4) NOT NULL DEFAULT '',
  `genre` varchar(64) NOT NULL DEFAULT '',
  `type` varchar(32) NOT NULL DEFAULT '',
  `director` varchar(64) NOT NULL DEFAULT '',
  `actors` varchar(2000) NOT NULL DEFAULT '',
  `language` varchar(64) NOT NULL DEFAULT '',
  `cover` tinyint(1) NOT NULL DEFAULT 0,
  `backdrop` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `trailer` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_movieinfo_imdbid` (`imdbid`),
  KEY `ix_movieinfo_title` (`title`),
  KEY `ix_movieinfo_tmdbid` (`tmdbid`),
  KEY `ix_movieinfo_traktid` (`traktid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `musicinfo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `asin` varchar(128) DEFAULT NULL,
  `url` varchar(1000) DEFAULT NULL,
  `salesrank` int(10) unsigned DEFAULT NULL,
  `artist` varchar(255) DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `releasedate` datetime DEFAULT NULL,
  `review` varchar(3000) DEFAULT NULL,
  `year` varchar(4) NOT NULL,
  `genres_id` int(11) DEFAULT NULL,
  `tracks` varchar(3000) DEFAULT NULL,
  `cover` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_musicinfo_asin` (`asin`),
  KEY `ix_musicinfo_genres_id` (`genres_id`),
  FULLTEXT KEY `ix_musicinfo_artist_title_ft` (`artist`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `nfo_cleanup_commits` (
  `binding` varchar(64) NOT NULL,
  `release_id` int(10) unsigned NOT NULL,
  `release_guid` varchar(255) NOT NULL,
  `candidate_identity` varchar(40) DEFAULT NULL,
  `stored_digest` varchar(64) NOT NULL,
  PRIMARY KEY (`binding`),
  KEY `nfo_cleanup_commits_release_id_foreign` (`release_id`),
  CONSTRAINT `nfo_cleanup_commits_release_id_foreign` FOREIGN KEY (`release_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `nzb_backup_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `started_at` timestamp NOT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `status` enum('running','completed','failed','cancelled') NOT NULL DEFAULT 'running',
  `files_found` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Files checked by rclone',
  `files_uploaded` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Files transferred',
  `files_skipped` int(10) unsigned NOT NULL DEFAULT 0,
  `files_failed` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Errors during transfer',
  `bytes_uploaded` bigint(20) unsigned NOT NULL DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `stats` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Rclone statistics' CHECK (json_valid(`stats`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `nzb_backup_runs_started_at_index` (`started_at`),
  KEY `nzb_backup_runs_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `nzb_storage_imports` (
  `name` varchar(120) NOT NULL,
  `last_release_id` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `par2_descriptor_evidence` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL,
  `release_guid` varchar(40) NOT NULL,
  `packet_checksum` char(32) NOT NULL,
  `recovery_set_id` char(32) NOT NULL,
  `file_id` char(32) NOT NULL,
  `full_md5` char(32) NOT NULL,
  `hash16k` char(32) NOT NULL,
  `file_size` varchar(20) NOT NULL,
  `raw_filename_base64` longtext NOT NULL,
  `descriptor_proof_version` tinyint(3) unsigned NOT NULL,
  `classification` varchar(32) NOT NULL DEFAULT 'unknown',
  `classification_version` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `classification_provenance` varchar(32) NOT NULL DEFAULT 'unclassified',
  PRIMARY KEY (`id`),
  UNIQUE KEY `par2_descriptor_identity` (`releases_id`,`release_guid`,`packet_checksum`),
  CONSTRAINT `par2_descriptor_evidence_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `par2_hash_retry` (
  `releases_id` int(10) unsigned NOT NULL,
  `release_guid` varchar(40) NOT NULL,
  `retry_at` timestamp NOT NULL,
  PRIMARY KEY (`releases_id`),
  CONSTRAINT `par2_hash_retry_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `par2_rename_candidate_progress` (
  `releases_id` int(10) unsigned NOT NULL,
  `candidate_signature` char(64) NOT NULL,
  `next_segment` int(10) unsigned NOT NULL,
  `retry_segments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`retry_segments`)),
  `accumulator_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`accumulator_state`)),
  `decoded_offset` bigint(20) unsigned NOT NULL DEFAULT 0,
  `article_identity` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`article_identity`)),
  `stored_bytes` int(10) unsigned NOT NULL,
  `blocked_reason` varchar(64) DEFAULT NULL,
  `updated_at` timestamp NOT NULL,
  PRIMARY KEY (`releases_id`,`candidate_signature`),
  CONSTRAINT `par2_rename_candidate_progress_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `par2_rename_progress` (`releases_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `par2_rename_progress` (
  `releases_id` int(10) unsigned NOT NULL,
  `signature` varchar(64) NOT NULL,
  `offset` int(10) unsigned NOT NULL,
  `retry_indexes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`retry_indexes`)),
  `updated_at` timestamp NOT NULL,
  PRIMARY KEY (`releases_id`),
  CONSTRAINT `par2_rename_progress_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `par_file_hash_inventories` (
  `releases_id` int(10) unsigned NOT NULL,
  `release_guid` varchar(40) NOT NULL,
  `evidence_last_id` bigint(20) unsigned NOT NULL,
  `evidence_count` bigint(20) unsigned NOT NULL,
  `proof_version` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`releases_id`),
  CONSTRAINT `par_file_hash_inventories_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `par_file_hashes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL,
  `release_guid` varchar(40) NOT NULL,
  `hash16k` char(32) NOT NULL,
  `full_md5` char(32) NOT NULL,
  `file_size` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `par_file_hashes_identity` (`releases_id`,`release_guid`,`hash16k`,`full_md5`,`file_size`),
  KEY `par_file_hashes_content` (`full_md5`,`file_size`,`releases_id`),
  KEY `par_file_hashes_cursor` (`releases_id`,`id`),
  CONSTRAINT `par_file_hashes_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `par_hashes` (
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `hash` varchar(32) NOT NULL COMMENT 'hash_16k block of par2',
  PRIMARY KEY (`releases_id`,`hash`),
  KEY `ix_par_hashes_hash_releases_id` (`hash`,`releases_id`),
  CONSTRAINT `FK_ph_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `parts` (
  `binaries_id` bigint(20) unsigned NOT NULL,
  `messageid` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `number` bigint(20) unsigned NOT NULL DEFAULT 0,
  `partnumber` int(10) unsigned NOT NULL DEFAULT 0,
  `size` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`binaries_id`,`partnumber`),
  KEY `ix_parts_number` (`number`),
  CONSTRAINT `FK_binaries` FOREIGN KEY (`binaries_id`) REFERENCES `binaries` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `passkeys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `authenticatable_id` int(10) unsigned NOT NULL,
  `name` text NOT NULL,
  `credential_id` text NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `passkeys_authenticatable_fk` (`authenticatable_id`),
  CONSTRAINT `passkeys_authenticatable_fk` FOREIGN KEY (`authenticatable_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `password_securities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `google2fa_enable` tinyint(1) NOT NULL DEFAULT 0,
  `google2fa_secret` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_password_securities_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `username` varchar(255) NOT NULL,
  `item_description` varchar(255) NOT NULL,
  `order_id` varchar(255) NOT NULL,
  `payment_id` varchar(255) NOT NULL,
  `payment_status` varchar(255) NOT NULL,
  `invoice_status` varchar(255) DEFAULT 'Pending',
  `invoice_amount` varchar(255) NOT NULL,
  `payment_method` varchar(255) NOT NULL,
  `payment_value` varchar(255) NOT NULL,
  `webhook_id` varchar(255) NOT NULL,
  `invoice_id` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `paypal_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(11) NOT NULL,
  `transaction_id` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_paypal_payments_users_id` (`users_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` mediumtext DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `post_processing_configurations` (
  `id` tinyint(3) unsigned NOT NULL,
  `post_threads` smallint(5) unsigned NOT NULL DEFAULT 1,
  `post_threads_amazon` smallint(5) unsigned NOT NULL DEFAULT 1,
  `post_threads_non` smallint(5) unsigned NOT NULL DEFAULT 1,
  `nfo_threads` smallint(5) unsigned NOT NULL DEFAULT 1,
  `fix_name_threads` smallint(5) unsigned NOT NULL DEFAULT 1,
  `timeout_seconds` int(10) unsigned NOT NULL DEFAULT 0,
  `release_timeout_seconds` int(10) unsigned NOT NULL DEFAULT 0,
  `max_timeout_count` smallint(5) unsigned NOT NULL DEFAULT 1,
  `max_additional_processed` int(10) unsigned NOT NULL DEFAULT 0,
  `max_parts_processed` smallint(5) unsigned NOT NULL DEFAULT 1,
  `password_check_attempts` smallint(5) unsigned NOT NULL DEFAULT 1,
  `fix_names_per_run` int(10) unsigned NOT NULL DEFAULT 0,
  `max_nested_levels` smallint(5) unsigned NOT NULL DEFAULT 1,
  `extract_using_rar_info` tinyint(1) NOT NULL DEFAULT 0,
  `segments_to_download` smallint(5) unsigned NOT NULL DEFAULT 1,
  `ffmpeg_duration` int(10) unsigned NOT NULL DEFAULT 0,
  `inner_file_blacklist` text NOT NULL,
  `process_jpg` tinyint(1) NOT NULL DEFAULT 0,
  `process_thumbnails` tinyint(1) NOT NULL DEFAULT 0,
  `process_videos` tinyint(1) NOT NULL DEFAULT 0,
  `save_audio_preview` tinyint(1) NOT NULL DEFAULT 0,
  `min_size_to_post_process` bigint(20) unsigned NOT NULL DEFAULT 0,
  `max_size_to_post_process` bigint(20) unsigned NOT NULL DEFAULT 0,
  `min_size_to_process_nfo` bigint(20) unsigned NOT NULL DEFAULT 0,
  `max_size_to_process_nfo` bigint(20) unsigned NOT NULL DEFAULT 0,
  `max_nfo_processed` int(10) unsigned NOT NULL DEFAULT 0,
  `max_nfo_retries` int(10) unsigned NOT NULL DEFAULT 0,
  `lookup_nfo` tinyint(1) NOT NULL DEFAULT 0,
  `lookup_par2` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `poster_renames` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `title` varchar(500) NOT NULL,
  `poster` varchar(255) NOT NULL,
  `source` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `poster_title` (`poster`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `predb` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Primary key',
  `title` varchar(255) NOT NULL DEFAULT '',
  `nfo` varchar(255) DEFAULT NULL,
  `size` varchar(50) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `predate` datetime DEFAULT NULL,
  `source` varchar(50) NOT NULL DEFAULT '',
  `requestid` int(10) unsigned NOT NULL DEFAULT 0,
  `groups_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to groups',
  `nuked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Is this pre nuked? 0 no 2 yes 1 un nuked 3 mod nuked',
  `nukereason` varchar(255) DEFAULT NULL COMMENT 'If this pre is nuked, what is the reason?',
  `files` varchar(50) DEFAULT NULL COMMENT 'How many files does this pre have ?',
  `filename` varchar(255) NOT NULL DEFAULT '',
  `searched` tinyint(1) NOT NULL DEFAULT 0,
  `imported_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_predb_title` (`title`),
  KEY `ix_predb_requestid` (`requestid`,`groups_id`),
  KEY `ix_predb_nfo` (`nfo`),
  KEY `ix_predb_predate` (`predate`),
  KEY `ix_predb_source` (`source`),
  KEY `ix_predb_searched` (`searched`),
  KEY `ix_predb_searched_predate_id` (`searched`,`predate`,`id`),
  KEY `ix_predb_searched_imported_at` (`searched`,`imported_at`,`id`),
  FULLTEXT KEY `ft_predb_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER predb_identity_insert AFTER INSERT ON predb FOR EACH ROW BEGIN IF NOT EXISTS (SELECT 1 FROM predb_identity_state WHERE id=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PreDB identity state unavailable'; END IF;
UPDATE predb_identity_state SET epoch=epoch+1, ready=0 WHERE id=1 AND epoch<9223372036854775806;
IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PreDB identity epoch exhausted'; END IF;
INSERT INTO predb_identity_revisions (predb_id, revision, deleted, projected_revision, projected_generation) SELECT NEW.id, epoch, 0, NULL, NULL FROM predb_identity_state WHERE id=1 ON DUPLICATE KEY UPDATE revision=VALUES(revision), deleted=VALUES(deleted), projected_revision=NULL, projected_generation=NULL;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER predb_identity_update AFTER UPDATE ON predb FOR EACH ROW BEGIN IF NOT (OLD.id <=> NEW.id) OR NOT (CAST(OLD.title AS BINARY) <=> CAST(NEW.title AS BINARY)) OR NOT (CAST(OLD.filename AS BINARY) <=> CAST(NEW.filename AS BINARY)) THEN IF NOT EXISTS (SELECT 1 FROM predb_identity_state WHERE id=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PreDB identity state unavailable'; END IF;
UPDATE predb_identity_state SET epoch=epoch+1, ready=0 WHERE id=1 AND epoch<9223372036854775806;
IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PreDB identity epoch exhausted'; END IF;
IF NOT (OLD.id <=> NEW.id) THEN INSERT INTO predb_identity_revisions (predb_id, revision, deleted, projected_revision, projected_generation) SELECT OLD.id, epoch, 1, NULL, NULL FROM predb_identity_state WHERE id=1 ON DUPLICATE KEY UPDATE revision=VALUES(revision), deleted=VALUES(deleted), projected_revision=NULL, projected_generation=NULL;
END IF; INSERT INTO predb_identity_revisions (predb_id, revision, deleted, projected_revision, projected_generation) SELECT NEW.id, epoch, 0, NULL, NULL FROM predb_identity_state WHERE id=1 ON DUPLICATE KEY UPDATE revision=VALUES(revision), deleted=VALUES(deleted), projected_revision=NULL, projected_generation=NULL;
END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER predb_identity_delete AFTER DELETE ON predb FOR EACH ROW BEGIN IF NOT EXISTS (SELECT 1 FROM predb_identity_state WHERE id=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PreDB identity state unavailable'; END IF;
UPDATE predb_identity_state SET epoch=epoch+1, ready=0 WHERE id=1 AND epoch<9223372036854775806;
IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PreDB identity epoch exhausted'; END IF;
INSERT INTO predb_identity_revisions (predb_id, revision, deleted, projected_revision, projected_generation) SELECT OLD.id, epoch, 1, NULL, NULL FROM predb_identity_state WHERE id=1 ON DUPLICATE KEY UPDATE revision=VALUES(revision), deleted=VALUES(deleted), projected_revision=NULL, projected_generation=NULL;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
CREATE TABLE `predb_crcs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `predb_id` int(10) unsigned NOT NULL COMMENT 'FK to predb.id',
  `crchash` varchar(255) NOT NULL DEFAULT '' COMMENT 'CRC hash',
  `filesize` bigint(20) NOT NULL DEFAULT 0 COMMENT 'Release file size in bytes',
  `filedate` datetime DEFAULT NULL COMMENT 'The file modified date',
  `osohash` varchar(255) NOT NULL DEFAULT '' COMMENT 'OpenSubtitles hash',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `predb_crcs_crchash_filesize_filedate_index` (`crchash`,`filesize`,`filedate`),
  KEY `predb_crcs_osohash_index` (`osohash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `predb_identity_keys` (
  `generation` char(32) NOT NULL,
  `kind` tinyint(3) unsigned NOT NULL,
  `key_digest` char(64) NOT NULL,
  `predb_id` int(10) unsigned NOT NULL,
  `source_revision` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`generation`,`kind`,`key_digest`,`predb_id`),
  KEY `predb_identity_key_owner` (`generation`,`predb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `predb_identity_revisions` (
  `predb_id` int(10) unsigned NOT NULL,
  `revision` bigint(20) unsigned NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `projected_revision` bigint(20) unsigned DEFAULT NULL,
  `projected_generation` char(32) DEFAULT NULL,
  PRIMARY KEY (`predb_id`),
  KEY `predb_identity_revision_range` (`revision`,`predb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `predb_identity_state` (
  `id` tinyint(3) unsigned NOT NULL,
  `generation` char(32) NOT NULL,
  `epoch` bigint(20) unsigned NOT NULL DEFAULT 0,
  `built_epoch` bigint(20) unsigned DEFAULT NULL,
  `policy_fingerprint` char(64) DEFAULT NULL,
  `ready` tinyint(1) NOT NULL DEFAULT 0,
  `rebuild_epoch` bigint(20) unsigned DEFAULT NULL,
  `rebuild_cursor` int(10) unsigned NOT NULL DEFAULT 0,
  `rebuild_until_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `predb_imports` (
  `title` varchar(255) NOT NULL DEFAULT '',
  `nfo` varchar(255) DEFAULT NULL,
  `size` varchar(50) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `predate` datetime DEFAULT NULL,
  `source` varchar(50) NOT NULL DEFAULT '',
  `requestid` int(10) unsigned NOT NULL DEFAULT 0,
  `groups_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to groups',
  `nuked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Is this pre nuked? 0 no 2 yes 1 un nuked 3 mod nuked',
  `nukereason` varchar(255) DEFAULT NULL COMMENT 'If this pre is nuked, what is the reason?',
  `files` varchar(50) DEFAULT NULL COMMENT 'How many files does this pre have ?',
  `filename` varchar(255) NOT NULL DEFAULT '',
  `searched` tinyint(1) NOT NULL DEFAULT 0,
  `groupname` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `processing_runtime_states` (
  `id` tinyint(3) unsigned NOT NULL,
  `tmux_running` tinyint(1) NOT NULL DEFAULT 0,
  `stop_requested` tinyint(1) NOT NULL DEFAULT 0,
  `last_binary_run_at` datetime DEFAULT NULL,
  `monitor_path` varchar(1000) DEFAULT NULL,
  `monitor_path_a` varchar(1000) DEFAULT NULL,
  `monitor_path_b` varchar(1000) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `pulse_aggregates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bucket` int(10) unsigned NOT NULL,
  `period` mediumint(8) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `key` mediumtext NOT NULL,
  `key_hash` binary(16) GENERATED ALWAYS AS (unhex(md5(`key`))) VIRTUAL,
  `aggregate` varchar(255) NOT NULL,
  `value` decimal(20,2) NOT NULL,
  `count` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pulse_aggregates_bucket_period_type_aggregate_key_hash_unique` (`bucket`,`period`,`type`,`aggregate`,`key_hash`),
  KEY `pulse_aggregates_period_bucket_index` (`period`,`bucket`),
  KEY `pulse_aggregates_type_index` (`type`),
  KEY `pulse_aggregates_period_type_aggregate_bucket_index` (`period`,`type`,`aggregate`,`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `pulse_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` int(10) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `key` mediumtext NOT NULL,
  `key_hash` binary(16) GENERATED ALWAYS AS (unhex(md5(`key`))) VIRTUAL,
  `value` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pulse_entries_timestamp_index` (`timestamp`),
  KEY `pulse_entries_type_index` (`type`),
  KEY `pulse_entries_key_hash_index` (`key_hash`),
  KEY `pulse_entries_timestamp_type_key_hash_value_index` (`timestamp`,`type`,`key_hash`,`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `pulse_values` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` int(10) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `key` mediumtext NOT NULL,
  `key_hash` binary(16) GENERATED ALWAYS AS (unhex(md5(`key`))) VIRTUAL,
  `value` mediumtext NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pulse_values_type_key_hash_unique` (`type`,`key_hash`),
  KEY `pulse_values_timestamp_index` (`timestamp`),
  KEY `pulse_values_type_index` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `registration_configurations` (
  `id` tinyint(3) unsigned NOT NULL,
  `status` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `registration_periods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `registration_periods_starts_at_index` (`starts_at`),
  KEY `registration_periods_ends_at_index` (`ends_at`),
  KEY `registration_periods_is_enabled_index` (`is_enabled`),
  KEY `registration_periods_created_by_index` (`created_by`),
  KEY `registration_periods_updated_by_index` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `registration_status_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(100) NOT NULL,
  `old_status` tinyint(3) unsigned DEFAULT NULL,
  `new_status` tinyint(3) unsigned DEFAULT NULL,
  `registration_period_id` bigint(20) unsigned DEFAULT NULL,
  `changed_by` bigint(20) unsigned DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `registration_status_history_action_index` (`action`),
  KEY `registration_status_history_registration_period_id_index` (`registration_period_id`),
  KEY `registration_status_history_changed_by_index` (`changed_by`),
  KEY `registration_status_history_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_archive_naming_evidence` (
  `releases_id` int(10) unsigned NOT NULL,
  `release_guid` varchar(40) NOT NULL,
  `source_sha256` char(64) NOT NULL,
  `proof_version` tinyint(3) unsigned NOT NULL,
  `scope` varchar(24) NOT NULL,
  `episode_files` longtext NOT NULL,
  `updated_at` timestamp NOT NULL,
  PRIMARY KEY (`releases_id`),
  CONSTRAINT `release_archive_naming_evidence_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_comments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `text` varchar(2000) NOT NULL DEFAULT '',
  `isvisible` tinyint(1) NOT NULL DEFAULT 1,
  `username` varchar(255) NOT NULL DEFAULT '',
  `users_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `host` varchar(15) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_releasecomment_releases_id` (`releases_id`),
  KEY `ix_releasecomment_userid` (`users_id`),
  CONSTRAINT `FK_rc_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `release_files` (
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `name` varchar(255) NOT NULL,
  `size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `crc32` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `passworded` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`releases_id`,`name`),
  KEY `ix_release_files_crc32_releases_id` (`crc32`,`releases_id`),
  CONSTRAINT `FK_rf_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `release_fingerprint_connections` (
  `provider` varchar(64) NOT NULL,
  `slot` int(10) unsigned NOT NULL,
  `lease_token` char(32) DEFAULT NULL,
  `lease_until` datetime(6) DEFAULT NULL,
  `worker_id` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`provider`,`slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_fingerprint_jobs` (
  `release_id` int(10) unsigned NOT NULL,
  `source_digest` char(64) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `available_at` datetime(6) NOT NULL,
  `lease_token` char(32) DEFAULT NULL,
  `lease_until` datetime(6) DEFAULT NULL,
  `worker_id` varchar(120) DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`release_id`),
  KEY `fingerprint_jobs_ready` (`status`,`available_at`,`release_id`),
  KEY `fingerprint_jobs_expired` (`status`,`lease_until`,`release_id`),
  KEY `fingerprint_jobs_completed` (`status`,`updated_at`),
  CONSTRAINT `release_fingerprint_jobs_release_id_foreign` FOREIGN KEY (`release_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_fingerprint_metadata` (
  `releases_id` int(10) unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `last_error` text DEFAULT NULL,
  `collected_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `run_token` char(16) DEFAULT NULL,
  PRIMARY KEY (`releases_id`),
  KEY `release_fingerprint_status` (`status`,`releases_id`),
  CONSTRAINT `FK_rfm_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_fingerprint_provider_state` (
  `provider` varchar(64) NOT NULL,
  `generation` char(32) DEFAULT NULL,
  `blocked_until` datetime(6) DEFAULT NULL,
  `probe_token` char(32) DEFAULT NULL,
  `probe_until` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_informs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `relOName` varchar(255) NOT NULL,
  `relPName` varchar(255) NOT NULL,
  `api_token` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_naming_regexes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_regex` varchar(255) NOT NULL DEFAULT '' COMMENT 'This is a regex to match against usenet groups',
  `regex` varchar(5000) NOT NULL DEFAULT '' COMMENT 'Regex used for extracting name from subject',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=ON 0=OFF',
  `description` varchar(1000) NOT NULL DEFAULT '' COMMENT 'Optional extra details on this regex',
  `ordinal` int(11) NOT NULL DEFAULT 0 COMMENT 'Order to run the regex in',
  PRIMARY KEY (`id`),
  KEY `ix_release_naming_regexes_group_regex` (`group_regex`),
  KEY `ix_release_naming_regexes_status` (`status`),
  KEY `ix_release_naming_regexes_ordinal` (`ordinal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `release_nfos` (
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `nfo` blob DEFAULT NULL,
  PRIMARY KEY (`releases_id`),
  CONSTRAINT `FK_rn_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `release_nzb_contents` (
  `guid` varchar(40) NOT NULL,
  `releases_id` int(10) unsigned NOT NULL,
  `body` longblob NOT NULL,
  `source_digest` char(64) NOT NULL,
  `generation` char(32) NOT NULL,
  `decoded_bytes` bigint(20) unsigned NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`guid`),
  UNIQUE KEY `release_nzb_contents_releases_id_unique` (`releases_id`),
  CONSTRAINT `release_nzb_contents_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_nzb_creation_failures` (
  `releases_id` int(10) unsigned NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`releases_id`),
  CONSTRAINT `FK_rncf_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_nzb_passwords` (
  `releases_id` int(10) unsigned NOT NULL,
  `password` varchar(255) NOT NULL,
  PRIMARY KEY (`releases_id`),
  CONSTRAINT `FK_rnp_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_regexes` (
  `releases_id` int(10) unsigned NOT NULL DEFAULT 0,
  `collection_regex_id` int(11) NOT NULL DEFAULT 0,
  `naming_regex_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`releases_id`,`collection_regex_id`,`naming_regex_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `release_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL,
  `users_id` int(10) unsigned NOT NULL,
  `reason` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `response` text DEFAULT NULL,
  `status` enum('pending','reviewed','resolved','dismissed') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `responded_by` int(10) unsigned DEFAULT NULL,
  `responded_at` timestamp NULL DEFAULT NULL,
  `response_is_public` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `release_reports_users_id_foreign` (`users_id`),
  KEY `release_reports_reviewed_by_foreign` (`reviewed_by`),
  KEY `release_reports_releases_id_status_index` (`releases_id`,`status`),
  KEY `release_reports_status_created_at_index` (`status`,`created_at`),
  KEY `release_reports_responded_by_foreign` (`responded_by`),
  KEY `release_reports_response_lookup_idx` (`releases_id`,`response_is_public`,`responded_at`),
  KEY `ix_release_reports_status_created_admin` (`status`,`created_at`),
  CONSTRAINT `release_reports_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `release_reports_responded_by_foreign` FOREIGN KEY (`responded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `release_reports_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `release_reports_users_id_foreign` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `category` varchar(255) NOT NULL,
  `count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `release_stats_category_index` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_subtitles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `subsid` int(10) unsigned NOT NULL,
  `subslanguage` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_releasesubs_releases_id_subsid` (`releases_id`,`subsid`),
  CONSTRAINT `FK_rs_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `release_unique` (
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id.',
  `uniqueid` varchar(255) NOT NULL COMMENT 'Unique_ID from mediainfo.',
  PRIMARY KEY (`releases_id`,`uniqueid`),
  CONSTRAINT `FK_ru_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `release_verification_audit_retries` (
  `release_id` bigint(20) unsigned NOT NULL,
  `next_attempt_at` datetime NOT NULL,
  `reason` text NOT NULL,
  PRIMARY KEY (`release_id`),
  KEY `verification_audit_due` (`next_attempt_at`,`release_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_verification_candidates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `identity` char(40) NOT NULL,
  `source_identity` char(40) DEFAULT NULL,
  `groups_id` int(10) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  `manifest_path` varchar(255) NOT NULL,
  `manifest_bytes` bigint(20) unsigned NOT NULL,
  `manifest_hash` char(64) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `reason` text DEFAULT NULL,
  `last_unique_at` datetime NOT NULL,
  `next_attempt_at` datetime NOT NULL,
  `lease_token` varchar(64) DEFAULT NULL,
  `lease_until` datetime DEFAULT NULL,
  `release_id` bigint(20) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `header_check_pending` tinyint(1) NOT NULL DEFAULT 0,
  `upgrades_release_id` bigint(20) unsigned DEFAULT NULL,
  `retained_for_release_id` bigint(20) unsigned DEFAULT NULL,
  `retained_last_unique_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `release_verification_candidates_identity_unique` (`identity`),
  KEY `verification_due` (`status`,`next_attempt_at`,`id`),
  KEY `release_verification_candidates_source_identity_index` (`source_identity`),
  KEY `verification_manifest_path` (`manifest_path`),
  KEY `verification_retained_expiry` (`retained_last_unique_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_verification_locks` (
  `name` varchar(32) NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_verification_manifests` (
  `name` varchar(80) NOT NULL,
  `body` longblob NOT NULL,
  `bytes` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_verification_oversized_collections` (
  `collection_id` bigint(20) unsigned NOT NULL,
  `collectionhash` binary(20) NOT NULL,
  `budget_bytes` bigint(20) unsigned NOT NULL,
  `attempts` int(10) unsigned NOT NULL DEFAULT 1,
  `failed_at` datetime NOT NULL,
  `retry_after` datetime NOT NULL,
  PRIMARY KEY (`collection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_verification_pauses` (
  `token` varchar(64) NOT NULL,
  `reason` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_verification_scratch` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `candidate_id` bigint(20) unsigned DEFAULT NULL,
  `audit_release_id` bigint(20) unsigned DEFAULT NULL,
  `terminal` tinyint(1) NOT NULL DEFAULT 0,
  `audit_verdict` varchar(32) DEFAULT NULL,
  `audit_reason` text DEFAULT NULL,
  `delete_guid` varchar(255) DEFAULT NULL,
  `delete_paths` text DEFAULT NULL,
  `version` int(10) unsigned NOT NULL DEFAULT 0,
  `reserved_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `host` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `verification_scratch_host_candidate_version` (`host`,`candidate_id`,`version`),
  KEY `verification_scratch_audit_terminal` (`audit_release_id`,`terminal`),
  KEY `verification_scratch_host_id` (`host`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_verification_scratch_budget` (
  `id` int(10) unsigned NOT NULL,
  `reserved_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `initialized` tinyint(1) NOT NULL DEFAULT 0,
  `host` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `verification_scratch_budget_host` (`host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `release_verification_state` (
  `id` int(10) unsigned NOT NULL,
  `pending_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `audit_cursor` bigint(20) unsigned NOT NULL DEFAULT 0,
  `audit_retry_turn` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `releases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `searchname` varchar(255) NOT NULL DEFAULT '',
  `totalpart` int(11) DEFAULT 0,
  `groups_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to groups.id',
  `size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `postdate` datetime DEFAULT NULL,
  `adddate` datetime DEFAULT NULL,
  `guid` char(40) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `leftguid` char(1) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL COMMENT 'The first letter of the release guid',
  `fromname` varchar(255) DEFAULT NULL,
  `completion` double NOT NULL DEFAULT 0,
  `categories_id` int(11) NOT NULL DEFAULT 10,
  `videos_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to videos.id of the parent series.',
  `tv_episodes_id` int(11) NOT NULL DEFAULT 0 COMMENT 'FK to tv_episodes.id for the episode.',
  `imdbid` varchar(100) DEFAULT NULL,
  `musicinfo_id` int(11) DEFAULT NULL COMMENT 'FK to musicinfo.id',
  `consoleinfo_id` int(11) DEFAULT NULL COMMENT 'FK to consoleinfo.id',
  `gamesinfo_id` int(11) NOT NULL DEFAULT 0,
  `bookinfo_id` int(11) DEFAULT NULL COMMENT 'FK to bookinfo.id',
  `anidbid` int(11) DEFAULT NULL COMMENT 'FK to anidb_titles.anidbid',
  `movieinfo_id` int(11) DEFAULT NULL COMMENT 'FK to movieinfo.id',
  `predb_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to predb.id',
  `grabs` int(10) unsigned NOT NULL DEFAULT 0,
  `comments` int(11) NOT NULL DEFAULT 0,
  `passwordstatus` smallint(6) NOT NULL DEFAULT -1,
  `rarinnerfilecount` int(11) NOT NULL DEFAULT 0,
  `haspreview` tinyint(1) NOT NULL DEFAULT 0,
  `nfostatus` tinyint(1) NOT NULL DEFAULT 0,
  `jpgstatus` tinyint(1) NOT NULL DEFAULT 0,
  `videostatus` tinyint(1) NOT NULL DEFAULT 0,
  `nzbstatus` tinyint(1) NOT NULL DEFAULT 0,
  `nzb_creation_claimed_at` timestamp NULL DEFAULT NULL,
  `nzb_creation_claim_token` char(32) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `iscategorized` tinyint(1) NOT NULL DEFAULT 0,
  `isrenamed` tinyint(1) NOT NULL DEFAULT 0,
  `proc_pp` tinyint(1) NOT NULL DEFAULT 0,
  `proc_par2` tinyint(1) NOT NULL DEFAULT 0,
  `proc_nfo` tinyint(1) NOT NULL DEFAULT 0,
  `proc_files` tinyint(1) NOT NULL DEFAULT 0,
  `proc_uid` tinyint(1) NOT NULL DEFAULT 0,
  `proc_srr` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Has the release been srr\nprocessed',
  `proc_hash16k` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Has the release been hash16k\nprocessed',
  `proc_crc32` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Has the release been crc32 processed',
  `pp_timeout_count` tinyint(4) NOT NULL DEFAULT 0 COMMENT 'Number of times this release timed out during additional post-processing',
  `additional_pp_claimed_at` timestamp NULL DEFAULT NULL,
  `additional_pp_claim_token` char(32) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `book_lookup_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `book_lookup_retry_at` datetime DEFAULT NULL,
  `book_name_normalized_at` datetime DEFAULT NULL,
  `article_fingerprint` char(40) DEFAULT NULL,
  `archive_retry_at` timestamp NULL DEFAULT NULL,
  `tv_lookup_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `tv_lookup_retry_at` datetime DEFAULT NULL,
  `articles_found` bigint(20) unsigned DEFAULT NULL,
  `articles_expected` bigint(20) unsigned DEFAULT NULL,
  `files_found` int(10) unsigned DEFAULT NULL,
  `files_expected` int(10) unsigned DEFAULT NULL,
  `late_article_candidate_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_releases_guid` (`guid`),
  KEY `ix_releases_groupsid` (`groups_id`,`passwordstatus`),
  KEY `ix_releases_leftguid` (`leftguid`,`predb_id`),
  KEY `ix_releases_musicinfo_id` (`musicinfo_id`,`passwordstatus`),
  KEY `ix_releases_nfostatus` (`nfostatus`,`size`),
  KEY `ix_releases_name` (`name`),
  KEY `ix_releases_tv_episodes_id` (`tv_episodes_id`),
  KEY `ix_releases_consoleinfo_id` (`consoleinfo_id`),
  KEY `ix_releases_gamesinfo_id` (`gamesinfo_id`),
  KEY `ix_releases_bookinfo_id` (`bookinfo_id`),
  KEY `ix_releases_anidbid` (`anidbid`),
  KEY `ix_releases_password_categories_postdate` (`passwordstatus`,`categories_id`,`postdate` DESC),
  KEY `ix_releases_adddate` (`adddate`,`categories_id`),
  KEY `ix_releases_grabs` (`grabs`,`categories_id`,`postdate`),
  KEY `ix_releases_movieinfo_cat` (`movieinfo_id`,`categories_id`,`passwordstatus`,`postdate`),
  KEY `ix_releases_videos_categories` (`videos_id`,`categories_id`),
  KEY `ix_releases_imdbid_password_cat_postdate` (`imdbid`,`passwordstatus`,`categories_id`,`postdate` DESC),
  KEY `ix_releases_searchname` (`searchname`),
  KEY `ix_releases_fromname` (`fromname`),
  KEY `ix_releases_categories_postdate_admin` (`categories_id`,`postdate`),
  KEY `ix_releases_postdate_admin` (`postdate`),
  KEY `ix_releases_adddate_id` (`adddate`,`id`),
  KEY `ix_releases_predb_id` (`predb_id`),
  KEY `ix_releases_size` (`size`),
  KEY `ix_releases_nzb_creation_group_queue` (`nzbstatus`,`groups_id`,`postdate` DESC,`id`,`nzb_creation_claimed_at`),
  KEY `ix_releases_nzb_creation_global_queue` (`nzbstatus`,`postdate` DESC,`id`,`nzb_creation_claimed_at`),
  KEY `ix_releases_add_pp_claim_queue` (`passwordstatus`,`haspreview`,`nzbstatus`,`leftguid`,`postdate` DESC,`id`,`additional_pp_claimed_at`,`size`),
  KEY `ix_releases_tv_processing` (`videos_id`,`tv_episodes_id`,`leftguid`,`postdate` DESC),
  KEY `ix_releases_book_retry` (`bookinfo_id`,`book_lookup_retry_at`,`categories_id`),
  KEY `ix_releases_article_fingerprint` (`article_fingerprint`),
  KEY `releases_late_article_candidate_id_index` (`late_article_candidate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `releases_groups` (
  `releases_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to releases.id',
  `groups_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to groups.id',
  PRIMARY KEY (`releases_id`,`groups_id`),
  CONSTRAINT `FK_rg_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `role_expiration_emails` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `day` tinyint(1) NOT NULL DEFAULT 0,
  `week` tinyint(1) NOT NULL DEFAULT 0,
  `month` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_expiration_emails_users_id_unique` (`users_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `role_has_permissions` (
  `permission_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `role_promotion_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `role_promotion_id` bigint(20) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  `days_added` int(11) NOT NULL COMMENT 'Number of days added to role expiry',
  `previous_expiry_date` datetime DEFAULT NULL COMMENT 'Previous role expiry date before promotion',
  `new_expiry_date` datetime DEFAULT NULL COMMENT 'New role expiry date after promotion',
  `applied_at` timestamp NOT NULL COMMENT 'When the promotion was applied',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `role_promotion_stats_user_id_index` (`user_id`),
  KEY `role_promotion_stats_role_promotion_id_index` (`role_promotion_id`),
  KEY `role_promotion_stats_role_id_index` (`role_id`),
  KEY `role_promotion_stats_applied_at_index` (`applied_at`),
  CONSTRAINT `role_promotion_stats_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_promotion_stats_role_promotion_id_foreign` FOREIGN KEY (`role_promotion_id`) REFERENCES `role_promotions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_promotion_stats_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `role_promotions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `applicable_roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'JSON array of role IDs this promotion applies to' CHECK (json_valid(`applicable_roles`)),
  `additional_days` int(11) NOT NULL DEFAULT 0 COMMENT 'Additional days added to role expiry',
  `start_date` date DEFAULT NULL COMMENT 'Promotion start date',
  `end_date` date DEFAULT NULL COMMENT 'Promotion end date',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_role_promotions_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `role_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `role` varchar(255) DEFAULT NULL,
  `users` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_role_stats_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `apirequests` int(10) unsigned NOT NULL,
  `rate_limit` int(11) NOT NULL DEFAULT 60,
  `downloadrequests` int(10) unsigned NOT NULL,
  `defaultinvites` int(10) unsigned NOT NULL,
  `isdefault` tinyint(1) NOT NULL DEFAULT 0,
  `donation` int(11) NOT NULL DEFAULT 0,
  `addyears` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `root_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `status` int(11) NOT NULL DEFAULT 1,
  `disablepreview` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_root_categories_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `search_index_failures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `release_id` bigint(20) unsigned NOT NULL,
  `operation` varchar(32) NOT NULL DEFAULT 'upsert',
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `search_index_failures_release_id_unique` (`release_id`),
  KEY `search_index_failures_next_attempt_at_index` (`next_attempt_at`),
  KEY `search_index_failures_resolved_at_index` (`resolved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `service_incident_service_status` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_incident_id` bigint(20) unsigned NOT NULL,
  `service_status_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `svc_incident_svc_pair_unique` (`service_incident_id`,`service_status_id`),
  KEY `service_incident_service_status_service_status_id_index` (`service_status_id`),
  CONSTRAINT `service_incident_service_status_service_incident_id_foreign` FOREIGN KEY (`service_incident_id`) REFERENCES `service_incidents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_incident_service_status_service_status_id_foreign` FOREIGN KEY (`service_status_id`) REFERENCES `service_statuses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `service_incidents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `status` varchar(255) NOT NULL,
  `impact` varchar(255) NOT NULL,
  `started_at` timestamp NOT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `is_auto` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_incidents_status_index` (`status`),
  KEY `service_incidents_impact_index` (`impact`),
  KEY `service_incidents_started_at_index` (`started_at`),
  KEY `service_incidents_resolved_at_index` (`resolved_at`),
  KEY `service_incidents_status_started_at_index` (`status`,`started_at`),
  KEY `service_incidents_created_by_index` (`created_by`),
  KEY `service_incidents_is_auto_index` (`is_auto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `service_statuses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `endpoint_url` varchar(255) DEFAULT NULL,
  `check_type` varchar(255) NOT NULL DEFAULT 'http',
  `probe_identifier` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL,
  `last_checked_at` timestamp NULL DEFAULT NULL,
  `uptime_percentage` decimal(5,2) NOT NULL DEFAULT 100.00,
  `response_time_ms` int(10) unsigned DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_statuses_slug_unique` (`slug`),
  KEY `service_statuses_status_index` (`status`),
  KEY `service_statuses_is_enabled_sort_order_index` (`is_enabled`,`sort_order`),
  KEY `service_statuses_check_type_index` (`check_type`),
  KEY `service_statuses_probe_identifier_index` (`probe_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `short_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `first_record` bigint(20) unsigned NOT NULL DEFAULT 0,
  `last_record` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_shortgroups_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `signup_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `month` varchar(255) DEFAULT NULL,
  `sort_date` date DEFAULT NULL,
  `signups` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `signup_stats_sort_date_index` (`sort_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `site_configurations` (
  `id` tinyint(3) unsigned NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT 'NNTmux',
  `home_link` varchar(255) NOT NULL DEFAULT '/',
  `site_logo` varchar(255) DEFAULT NULL,
  `strapline` varchar(255) NOT NULL DEFAULT '',
  `meta_title` varchar(255) NOT NULL DEFAULT '',
  `meta_description` text NOT NULL,
  `meta_keywords` text NOT NULL,
  `footer` text NOT NULL,
  `dereferrer_link` varchar(1000) NOT NULL DEFAULT '',
  `terms` longtext NOT NULL,
  `trailers_display` tinyint(1) NOT NULL DEFAULT 1,
  `trailers_size_x` smallint(5) unsigned NOT NULL DEFAULT 480,
  `trailers_size_y` smallint(5) unsigned NOT NULL DEFAULT 345,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `steam_apps` (
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Steam application name',
  `appid` int(10) unsigned NOT NULL COMMENT 'Steam application id',
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`),
  KEY `ix_name_appid` (`name`,`appid`),
  FULLTEXT KEY `ix_name_ft` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `system_metrics` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `metric_type` varchar(50) NOT NULL,
  `value` decimal(8,2) NOT NULL,
  `recorded_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `system_metrics_metric_type_recorded_at_index` (`metric_type`,`recorded_at`),
  KEY `system_metrics_metric_type_index` (`metric_type`),
  KEY `system_metrics_recorded_at_index` (`recorded_at`),
  KEY `ix_system_metrics_type_recorded` (`metric_type`,`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `telescope_entries` (
  `sequence` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `batch_id` char(36) NOT NULL,
  `family_hash` varchar(255) DEFAULT NULL,
  `should_display_on_index` tinyint(1) NOT NULL DEFAULT 1,
  `type` varchar(20) NOT NULL,
  `content` longtext NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`sequence`),
  UNIQUE KEY `telescope_entries_uuid_unique` (`uuid`),
  KEY `telescope_entries_batch_id_index` (`batch_id`),
  KEY `telescope_entries_type_should_display_on_index_index` (`type`,`should_display_on_index`),
  KEY `telescope_entries_family_hash_index` (`family_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `telescope_entries_tags` (
  `entry_uuid` char(36) NOT NULL,
  `tag` varchar(255) NOT NULL,
  KEY `telescope_entries_tags_entry_uuid_tag_index` (`entry_uuid`,`tag`),
  KEY `telescope_entries_tags_tag_index` (`tag`),
  CONSTRAINT `telescope_entries_tags_entry_uuid_foreign` FOREIGN KEY (`entry_uuid`) REFERENCES `telescope_entries` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `telescope_monitoring` (
  `tag` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `tmux_cleanup_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tmux_configuration_id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `rule` varchar(32) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tmux_cleanup_rules_tmux_configuration_id_rule_unique` (`tmux_configuration_id`,`rule`),
  CONSTRAINT `tmux_cleanup_rules_tmux_configuration_id_foreign` FOREIGN KEY (`tmux_configuration_id`) REFERENCES `tmux_configurations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tmux_color_exclusions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tmux_configuration_id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `color` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tmux_color_exclusions_tmux_configuration_id_color_unique` (`tmux_configuration_id`,`color`),
  CONSTRAINT `tmux_color_exclusions_tmux_configuration_id_foreign` FOREIGN KEY (`tmux_configuration_id`) REFERENCES `tmux_configurations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tmux_configurations` (
  `id` tinyint(3) unsigned NOT NULL,
  `session_name` varchar(255) NOT NULL DEFAULT 'nntmux',
  `monitor_delay` int(10) unsigned NOT NULL DEFAULT 30,
  `niceness` smallint(6) NOT NULL DEFAULT 19,
  `sequential_mode` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `sequential_timer` int(10) unsigned NOT NULL DEFAULT 0,
  `binaries_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `binaries_timer` int(10) unsigned NOT NULL DEFAULT 0,
  `binaries_kill_timer` int(10) unsigned NOT NULL DEFAULT 0,
  `backfill_mode` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `backfill_groups` int(10) unsigned NOT NULL DEFAULT 0,
  `backfill_timer` int(10) unsigned NOT NULL DEFAULT 0,
  `progressive_backfill` tinyint(1) NOT NULL DEFAULT 0,
  `releases_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `release_timer` int(10) unsigned NOT NULL DEFAULT 0,
  `post_mode` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `post_timer` int(10) unsigned NOT NULL DEFAULT 0,
  `post_kill_timer` int(10) unsigned NOT NULL DEFAULT 0,
  `post_amazon_mode` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `post_amazon_timer` int(10) unsigned NOT NULL DEFAULT 0,
  `post_non_mode` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `post_non_timer` int(10) unsigned NOT NULL DEFAULT 0,
  `fix_names_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `fix_timer` int(10) unsigned NOT NULL DEFAULT 0,
  `cleanup_mode` varchar(255) NOT NULL DEFAULT 'Disabled',
  `cleanup_timer` int(10) unsigned NOT NULL DEFAULT 0,
  `run_irc_scraper` tinyint(1) NOT NULL DEFAULT 0,
  `console_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `htop_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `mytop_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `nmon_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `vnstat_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `vnstat_args` varchar(1000) DEFAULT NULL,
  `tcp_track_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `tcp_track_args` varchar(1000) DEFAULT NULL,
  `bwmng_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `redis_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `redis_args` varchar(1000) DEFAULT NULL,
  `write_logs` tinyint(1) NOT NULL DEFAULT 0,
  `collections_kill_threshold` int(10) unsigned NOT NULL DEFAULT 0,
  `post_process_kill_threshold` int(10) unsigned NOT NULL DEFAULT 0,
  `colors_start` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `colors_end` tinyint(3) unsigned NOT NULL DEFAULT 250,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `trusted_devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `trusted_devices_token_hash_unique` (`token_hash`),
  KEY `trusted_devices_user_id_expires_at_index` (`user_id`,`expires_at`),
  KEY `trusted_devices_expires_at_index` (`expires_at`),
  CONSTRAINT `trusted_devices_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `tv_episodes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `videos_id` int(10) unsigned NOT NULL COMMENT 'FK to videos.id of the parent series.',
  `series` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Number of series/season.',
  `episode` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Number of episode within series',
  `se_complete` varchar(10) NOT NULL COMMENT 'String version of Series/Episode as taken from release subject (i.e. S02E21+22).',
  `title` varchar(180) NOT NULL COMMENT 'Title of the episode.',
  `firstaired` date DEFAULT NULL COMMENT 'Date of original airing/release.',
  `summary` mediumtext NOT NULL COMMENT 'Description/summary of the episode.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `videos_id` (`videos_id`,`series`,`episode`,`firstaired`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tv_info` (
  `videos_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to video.id',
  `summary` mediumtext NOT NULL COMMENT 'Description/summary of the show.',
  `publisher` varchar(50) NOT NULL COMMENT 'The channel/network of production/release (ABC, BBC, Showtime, etc.).',
  `localzone` varchar(50) NOT NULL DEFAULT '' COMMENT 'The linux tz style identifier',
  `image` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Does the video have a cover image?',
  PRIMARY KEY (`videos_id`),
  KEY `ix_tv_info_image` (`image`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `usenet_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `backfill_target` int(11) NOT NULL DEFAULT 1,
  `first_record` bigint(20) unsigned NOT NULL DEFAULT 0,
  `first_record_postdate` datetime DEFAULT NULL,
  `last_record` bigint(20) unsigned NOT NULL DEFAULT 0,
  `last_record_postdate` datetime DEFAULT NULL,
  `last_updated` datetime DEFAULT NULL,
  `minfilestoformrelease` int(11) DEFAULT NULL,
  `minsizetoformrelease` bigint(20) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `backfill` tinyint(1) NOT NULL DEFAULT 0,
  `description` varchar(255) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_groups_name` (`name`),
  KEY `active` (`active`),
  KEY `ix_usenet_groups_active_name_admin` (`active`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `user_activities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `username` varchar(255) NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `is_permanent` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_activities_activity_type_created_at_index` (`activity_type`,`created_at`),
  KEY `user_activities_created_at_index` (`created_at`),
  KEY `ix_user_activities_type_permanent` (`activity_type`,`is_permanent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `user_activity_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stat_date` date NOT NULL,
  `downloads_count` int(11) NOT NULL DEFAULT 0,
  `api_hits_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_activity_stats_stat_date_unique` (`stat_date`),
  KEY `user_activity_stats_stat_date_index` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `user_activity_stats_hourly` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stat_hour` datetime NOT NULL,
  `downloads_count` int(11) NOT NULL DEFAULT 0,
  `api_hits_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_activity_stats_hourly_stat_hour_unique` (`stat_hour`),
  KEY `user_activity_stats_hourly_stat_hour_index` (`stat_hour`),
  KEY `ix_hourly_stats_hour` (`stat_hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `user_downloads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(10) unsigned NOT NULL,
  `hosthash` varchar(50) NOT NULL DEFAULT '',
  `timestamp` datetime NOT NULL,
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  PRIMARY KEY (`id`),
  KEY `userid` (`users_id`),
  KEY `timestamp` (`timestamp`),
  KEY `ix_user_downloads_users_timestamp` (`users_id`,`timestamp`),
  KEY `ix_user_downloads_releases_id` (`releases_id`),
  CONSTRAINT `FK_users_ud` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `user_excluded_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(10) unsigned NOT NULL,
  `categories_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_excluded_categories_users_id_categories_id_unique` (`users_id`,`categories_id`),
  KEY `user_excluded_categories_categories_id_foreign` (`categories_id`),
  KEY `user_excluded_categories_users_id_index` (`users_id`),
  CONSTRAINT `user_excluded_categories_categories_id_foreign` FOREIGN KEY (`categories_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_excluded_categories_users_id_foreign` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `user_invitations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `status` enum('pending','successful','canceled','expired') NOT NULL DEFAULT 'pending',
  `valid_till` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_invitations_code_index` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `user_movies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(10) unsigned NOT NULL,
  `imdbid` varchar(100) DEFAULT NULL,
  `categories` varchar(64) DEFAULT NULL COMMENT 'List of categories for user movies',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_usermovies_userid` (`users_id`,`imdbid`),
  CONSTRAINT `FK_users_um` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `user_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(10) unsigned NOT NULL,
  `hosthash` varchar(50) NOT NULL DEFAULT '',
  `request` varchar(255) NOT NULL,
  `timestamp` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `userid` (`users_id`),
  KEY `timestamp` (`timestamp`),
  KEY `ix_user_requests_users_timestamp` (`users_id`,`timestamp`),
  CONSTRAINT `FK_users_urq` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `user_role_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `old_role_id` int(11) DEFAULT NULL,
  `new_role_id` int(11) NOT NULL,
  `old_expiry_date` datetime DEFAULT NULL COMMENT 'Previous role expiry date',
  `new_expiry_date` datetime DEFAULT NULL COMMENT 'New role expiry date',
  `effective_date` datetime NOT NULL COMMENT 'When this role change became active',
  `is_stacked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Was this role stacked after previous expiry',
  `change_reason` varchar(255) DEFAULT NULL COMMENT 'Reason for role change (upgrade, downgrade, expiry, admin, etc)',
  `changed_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Admin user ID who made the change',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_role_history_user_id_index` (`user_id`),
  KEY `ix_user_role_history_changed_by` (`changed_by`),
  KEY `ix_user_role_history_roles` (`old_role_id`,`new_role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE `user_series` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(10) unsigned NOT NULL,
  `videos_id` int(11) NOT NULL COMMENT 'FK to videos.id',
  `categories` varchar(64) DEFAULT NULL COMMENT 'List of categories for user tv shows',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_userseries_videos_id` (`users_id`,`videos_id`),
  CONSTRAINT `FK_users_us` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `firstname` varchar(255) DEFAULT NULL,
  `lastname` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `roles_id` int(11) NOT NULL DEFAULT 1 COMMENT 'FK to roles.id',
  `next_roles_id` int(11) DEFAULT NULL,
  `host` varchar(40) DEFAULT NULL,
  `grabs` int(11) NOT NULL DEFAULT 0,
  `api_token` varchar(64) NOT NULL,
  `resetguid` varchar(50) DEFAULT NULL,
  `lastlogin` datetime DEFAULT NULL,
  `apiaccess` datetime DEFAULT NULL,
  `lastdownload` datetime DEFAULT NULL,
  `invites` int(11) NOT NULL DEFAULT 0,
  `invitedby` int(11) DEFAULT NULL,
  `movieview` int(11) NOT NULL DEFAULT 1,
  `xxxview` int(11) NOT NULL DEFAULT 1,
  `musicview` int(11) NOT NULL DEFAULT 1,
  `consoleview` int(11) NOT NULL DEFAULT 1,
  `bookview` int(11) NOT NULL DEFAULT 1,
  `gameview` int(11) NOT NULL DEFAULT 1,
  `rate_limit` int(11) NOT NULL DEFAULT 60,
  `notes` varchar(255) DEFAULT NULL,
  `theme_preference` varchar(10) NOT NULL DEFAULT 'light',
  `color_scheme` varchar(20) NOT NULL DEFAULT 'blue',
  `style` varchar(255) DEFAULT NULL,
  `rolechangedate` datetime DEFAULT NULL COMMENT 'When does the role expire',
  `pending_role_start_date` datetime DEFAULT NULL COMMENT 'When the pending role change takes effect',
  `pending_roles_id` int(11) DEFAULT NULL COMMENT 'The role that will be applied after current role expires',
  `next_rolechangedate` datetime DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `session_token` varchar(60) DEFAULT NULL,
  `timezone` varchar(255) DEFAULT NULL,
  `movie_layout` tinyint(4) NOT NULL DEFAULT 2 COMMENT '1=1-column, 2=2-columns',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `verification_token` varchar(255) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  `bad_user` tinyint(1) NOT NULL DEFAULT 0,
  `can_post` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_users_api_token` (`api_token`),
  KEY `ix_user_roles` (`roles_id`),
  KEY `ix_users_created_at` (`created_at`),
  KEY `ix_users_roles_created` (`roles_id`,`created_at`),
  KEY `ix_users_deleted_at` (`deleted_at`),
  KEY `ix_users_username` (`username`),
  KEY `ix_users_email` (`email`),
  KEY `ix_users_host` (`host`),
  KEY `ix_users_lastlogin` (`lastlogin`),
  KEY `ix_users_apiaccess` (`apiaccess`),
  KEY `ix_users_grabs` (`grabs`),
  KEY `ix_users_rolechangedate` (`rolechangedate`),
  KEY `ix_users_verified` (`verified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `users_releases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(10) unsigned NOT NULL,
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_usercart_userrelease` (`users_id`,`releases_id`),
  KEY `FK_ur_releases` (`releases_id`),
  CONSTRAINT `FK_ur_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_users_ur` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `video_data` (
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `containerformat` varchar(50) DEFAULT NULL,
  `overallbitrate` varchar(20) DEFAULT NULL,
  `videoduration` varchar(20) DEFAULT NULL,
  `videoformat` varchar(50) DEFAULT NULL,
  `videocodec` varchar(50) DEFAULT NULL,
  `videowidth` int(11) DEFAULT NULL,
  `videoheight` int(11) DEFAULT NULL,
  `videoaspect` varchar(10) DEFAULT NULL,
  `videoframerate` double(7,4) DEFAULT NULL,
  `videolibrary` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`releases_id`),
  CONSTRAINT `FK_vd_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `videos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Show ID to be used in other tables as reference ',
  `type` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = TV, 1 = Film, 2 = Anime',
  `title` varchar(180) NOT NULL COMMENT 'Name of the video.',
  `countries_id` char(2) NOT NULL DEFAULT '' COMMENT 'Two character country code (FK to countries table).',
  `started` datetime NOT NULL COMMENT 'Date (UTC) of production''s first airing.',
  `anidb` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'ID number for anidb site',
  `imdb` varchar(100) NOT NULL DEFAULT '0' COMMENT 'ID number for IMDB site (without the ''tt'' prefix).',
  `tmdb` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'ID number for TMDB site.',
  `trakt` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'ID number for TraktTV site.',
  `tvdb` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'ID number for TVDB site',
  `tvmaze` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'ID number for TVMaze site.',
  `tvrage` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'ID number for TVRage site.',
  `source` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Which site did we use for info?',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_videos_title` (`title`,`type`,`started`,`countries_id`),
  KEY `ix_videos_type_source` (`type`,`source`),
  KEY `ix_videos_imdb` (`imdb`),
  KEY `ix_videos_tmdb` (`tmdb`),
  KEY `ix_videos_trakt` (`trakt`),
  KEY `ix_videos_tvdb` (`tvdb`),
  KEY `ix_videos_tvmaze` (`tvmaze`),
  KEY `ix_videos_tvrage` (`tvrage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `videos_aliases` (
  `videos_id` int(10) unsigned NOT NULL COMMENT 'FK to videos.id of the parent title.',
  `title` varchar(180) NOT NULL COMMENT 'AKA of the video.',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`videos_id`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=1;
