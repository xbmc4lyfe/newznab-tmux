-- The old schema's shape, for the columns the importer reads.
CREATE TABLE usenet_groups (
    id INT UNSIGNED PRIMARY KEY, name VARCHAR(255) NOT NULL, backfill_target INT NOT NULL DEFAULT 1,
    first_record BIGINT UNSIGNED NOT NULL DEFAULT 0, first_record_postdate DATETIME,
    last_record BIGINT UNSIGNED NOT NULL DEFAULT 0, last_record_postdate DATETIME, last_updated DATETIME,
    minfilestoformrelease INT, minsizetoformrelease BIGINT, active TINYINT(1) NOT NULL DEFAULT 0,
    backfill TINYINT(1) NOT NULL DEFAULT 0, description VARCHAR(255) DEFAULT ''
);
CREATE TABLE videos (
    id INT UNSIGNED PRIMARY KEY, type TINYINT(1) NOT NULL DEFAULT 0, title VARCHAR(180) NOT NULL,
    countries_id CHAR(2) NOT NULL DEFAULT '', started DATETIME NOT NULL, anidb INT UNSIGNED NOT NULL DEFAULT 0,
    source TINYINT(1) NOT NULL DEFAULT 0, imdb VARCHAR(100) NOT NULL DEFAULT '0',
    tmdb INT UNSIGNED NOT NULL DEFAULT 0, trakt INT UNSIGNED NOT NULL DEFAULT 0,
    tvdb INT UNSIGNED NOT NULL DEFAULT 0, tvmaze INT UNSIGNED NOT NULL DEFAULT 0,
    tvrage INT UNSIGNED NOT NULL DEFAULT 0
);
CREATE TABLE tv_episodes (
    id INT UNSIGNED PRIMARY KEY, videos_id INT UNSIGNED NOT NULL, series SMALLINT UNSIGNED NOT NULL,
    episode SMALLINT UNSIGNED NOT NULL, se_complete VARCHAR(10) NOT NULL, title VARCHAR(180) NOT NULL,
    firstaired DATE, summary MEDIUMTEXT NOT NULL
);
CREATE TABLE movieinfo (
    id INT UNSIGNED PRIMARY KEY, imdbid VARCHAR(100) NOT NULL, tmdbid INT UNSIGNED NOT NULL DEFAULT 0,
    traktid INT UNSIGNED NOT NULL DEFAULT 0, title VARCHAR(255) NOT NULL DEFAULT '',
    tagline VARCHAR(1024) NOT NULL DEFAULT '', rating VARCHAR(4) NOT NULL DEFAULT '',
    rtrating VARCHAR(10) NOT NULL DEFAULT '', plot VARCHAR(1024) NOT NULL DEFAULT '',
    year VARCHAR(4) NOT NULL DEFAULT '', genre VARCHAR(64) NOT NULL DEFAULT '', type VARCHAR(32) NOT NULL DEFAULT '',
    director VARCHAR(64) NOT NULL DEFAULT '', actors VARCHAR(2000) NOT NULL DEFAULT '',
    language VARCHAR(64) NOT NULL DEFAULT '', trailer VARCHAR(255) NOT NULL DEFAULT '',
    cover TINYINT(1) NOT NULL DEFAULT 0, backdrop TINYINT(1) NOT NULL DEFAULT 0
);
CREATE TABLE releases (
    id INT UNSIGNED PRIMARY KEY, name VARCHAR(255) NOT NULL, searchname VARCHAR(255) NOT NULL,
    totalpart INT DEFAULT 0, groups_id INT UNSIGNED NOT NULL DEFAULT 0,
    size BIGINT UNSIGNED NOT NULL DEFAULT 0, postdate DATETIME, adddate DATETIME,
    guid CHAR(36) CHARACTER SET ascii NOT NULL, fromname VARCHAR(255), completion DOUBLE NOT NULL DEFAULT 0,
    categories_id INT NOT NULL DEFAULT 10, videos_id INT UNSIGNED NOT NULL DEFAULT 0,
    tv_episodes_id INT NOT NULL DEFAULT 0, imdbid VARCHAR(100), movieinfo_id INT,
    predb_id INT UNSIGNED NOT NULL DEFAULT 0, grabs INT UNSIGNED NOT NULL DEFAULT 0,
    passwordstatus SMALLINT NOT NULL DEFAULT -1, isrenamed TINYINT(1) NOT NULL DEFAULT 0,
    rarinnerfilecount INT NOT NULL DEFAULT 0, articles_found BIGINT UNSIGNED, articles_expected BIGINT UNSIGNED,
    files_found INT UNSIGNED, files_expected INT UNSIGNED, article_fingerprint CHAR(40),
    UNIQUE KEY ux_guid (guid), KEY ix_size (size)
);
CREATE TABLE releases_groups (
    releases_id INT UNSIGNED NOT NULL, groups_id INT UNSIGNED NOT NULL, PRIMARY KEY (releases_id, groups_id)
);
CREATE TABLE release_files (
    releases_id INT UNSIGNED NOT NULL, name VARCHAR(255) NOT NULL, size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    crc32 VARCHAR(255) NOT NULL DEFAULT '', passworded TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (releases_id, name)
);
CREATE TABLE release_nfos (releases_id INT UNSIGNED PRIMARY KEY, nfo BLOB);
CREATE TABLE release_nzb_passwords (releases_id INT UNSIGNED PRIMARY KEY, password VARCHAR(255) NOT NULL);
CREATE TABLE par_hashes (
    releases_id INT UNSIGNED NOT NULL, hash VARCHAR(32) NOT NULL, PRIMARY KEY (releases_id, hash)
);
CREATE TABLE par_file_hashes (
    id BIGINT UNSIGNED PRIMARY KEY, releases_id INT UNSIGNED NOT NULL, release_guid VARCHAR(40) NOT NULL,
    hash16k CHAR(32) NOT NULL, full_md5 CHAR(32) NOT NULL, file_size VARCHAR(20) NOT NULL,
    KEY ix_release (releases_id)
);
CREATE TABLE par_file_hash_inventories (
    releases_id INT UNSIGNED PRIMARY KEY, release_guid VARCHAR(40) NOT NULL,
    evidence_last_id BIGINT UNSIGNED NOT NULL, evidence_count BIGINT UNSIGNED NOT NULL,
    proof_version TINYINT UNSIGNED NOT NULL DEFAULT 0, created_by_inventory_upgrade TINYINT(1) NOT NULL DEFAULT 1
);
CREATE TABLE par2_descriptor_evidence (
    id BIGINT UNSIGNED PRIMARY KEY, releases_id INT UNSIGNED NOT NULL, release_guid VARCHAR(40) NOT NULL,
    packet_checksum CHAR(32) NOT NULL,
    recovery_set_id CHAR(32) NOT NULL, file_id CHAR(32) NOT NULL, full_md5 CHAR(32) NOT NULL,
    hash16k CHAR(32) NOT NULL, file_size VARCHAR(20) NOT NULL, raw_filename_base64 LONGTEXT NOT NULL,
    descriptor_proof_version TINYINT UNSIGNED NOT NULL, classification VARCHAR(32) NOT NULL DEFAULT 'unknown',
    classification_version TINYINT UNSIGNED NOT NULL DEFAULT 0,
    classification_provenance VARCHAR(32) NOT NULL DEFAULT 'unclassified', KEY ix_release (releases_id)
);
CREATE TABLE release_archive_naming_evidence (
    releases_id INT UNSIGNED PRIMARY KEY, release_guid VARCHAR(40) NOT NULL, source_sha256 CHAR(64) NOT NULL, proof_version TINYINT UNSIGNED NOT NULL,
    scope VARCHAR(24) NOT NULL, episode_files LONGTEXT NOT NULL, updated_at TIMESTAMP NULL
);
CREATE TABLE video_data (
    releases_id INT UNSIGNED PRIMARY KEY, containerformat VARCHAR(50), overallbitrate VARCHAR(20),
    videoduration VARCHAR(20), videoformat VARCHAR(50), videocodec VARCHAR(50), videowidth INT, videoheight INT,
    videoaspect VARCHAR(10), videoframerate DOUBLE(7,4), videolibrary VARCHAR(50)
);
CREATE TABLE audio_data (
    id INT UNSIGNED PRIMARY KEY, releases_id INT UNSIGNED NOT NULL, audioid INT UNSIGNED NOT NULL,
    audioformat VARCHAR(50), audiomode VARCHAR(50), audiobitratemode VARCHAR(50), audiobitrate VARCHAR(10),
    audiochannels VARCHAR(25), audiosamplerate VARCHAR(25), audiolibrary VARCHAR(50), audiolanguage VARCHAR(50),
    audiotitle VARCHAR(50), KEY ix_release (releases_id)
);
CREATE TABLE release_subtitles (
    id INT UNSIGNED PRIMARY KEY, releases_id INT UNSIGNED NOT NULL, subsid INT UNSIGNED NOT NULL,
    subslanguage VARCHAR(50) NOT NULL, KEY ix_release (releases_id)
);
CREATE TABLE release_unique (
    releases_id INT UNSIGNED NOT NULL, uniqueid VARCHAR(255) NOT NULL, PRIMARY KEY (releases_id, uniqueid)
);
CREATE TABLE media_infos (
    id BIGINT UNSIGNED PRIMARY KEY, releases_id BIGINT UNSIGNED NOT NULL, movie_name VARCHAR(255),
    file_name VARCHAR(255), unique_id VARCHAR(255), KEY ix_release (releases_id)
);
CREATE TABLE release_fingerprint_metadata (
    releases_id INT UNSIGNED PRIMARY KEY, status VARCHAR(20) NOT NULL DEFAULT 'pending', metadata LONGTEXT,
    last_error TEXT, collected_at DATETIME
);
CREATE TABLE release_nzb_contents (
    guid VARCHAR(40) PRIMARY KEY, releases_id INT UNSIGNED NOT NULL UNIQUE, body LONGBLOB NOT NULL,
    source_digest CHAR(64) NOT NULL, generation CHAR(32) NOT NULL, decoded_bytes BIGINT UNSIGNED NOT NULL
);
CREATE TABLE predb (
    id INT UNSIGNED PRIMARY KEY, title VARCHAR(255) NOT NULL, nfo VARCHAR(255), size VARCHAR(50),
    category VARCHAR(255), predate DATETIME, imported_at DATETIME, source VARCHAR(50) NOT NULL DEFAULT '',
    requestid INT UNSIGNED NOT NULL DEFAULT 0, groups_id INT UNSIGNED NOT NULL DEFAULT 0,
    nuked TINYINT(1) NOT NULL DEFAULT 0, nukereason VARCHAR(255), files VARCHAR(50),
    filename VARCHAR(255) NOT NULL DEFAULT ''
);

INSERT INTO usenet_groups (id, name, last_record, last_record_postdate, active)
VALUES (1, 'alt.binaries.teevee', 123456789012, '2026-10-25 01:02:03', 1), (2, 'alt.binaries.hdtv', 0, NULL, 0);
INSERT INTO videos (id, title, started, imdb, tmdb, trakt, tvdb, tvmaze, tvrage)
VALUES (10, 'Game of Thrones', '2011-04-17 00:00:00', '0944947', 1399, 1390, 121361, 82, 24493),
    (11, 'I Love Lucy', '1951-10-15 00:00:00', '0', 0, 0, 0, 0, 0);
INSERT INTO tv_episodes (id, videos_id, series, episode, se_complete, title, firstaired, summary)
VALUES (20, 10, 3, 9, 'S03E09', 'The Rains of Castamere', '2013-06-02', 'a long summary');
INSERT INTO movieinfo (id, imdbid, tmdbid, traktid, title, year)
VALUES (30, '0848228', 24428, 14701, 'The Avengers', '2012');

-- 1: TV with IDs. 2: a one-file 60 GB remux, unnamed, with zeros for every ID.
-- 3: a movie, whose NZB's digest is wrong at the source. 4: no NZB at all.
-- 5: below the floor. 7 and 8: more, for resuming, with dates at the edge of what
-- a unix timestamp can hold.
INSERT INTO releases
    (id, name, searchname, totalpart, groups_id, size, postdate, adddate, guid, fromname, completion,
     categories_id, videos_id, tv_episodes_id, imdbid, movieinfo_id, predb_id, grabs, passwordstatus, isrenamed)
VALUES
    (1, 'got', 'Game.of.Thrones.S03E09.1080p.WEB-DL-GRP', 60, 1, 4000000000, '2026-10-01 12:00:00',
     '2026-10-01 13:00:00', 'guid-1', 'poster@x', 100, 5040, 10, 20, NULL, NULL, 100, 3, 0, 1),
    (2, 'a1b2c3', 'a1b2c3', 1, 2, 64424509440, '2026-10-02 12:00:00', NULL, 'guid-2', 'héctor@x', 99.5,
     10, 0, 0, '0000000', 0, 0, 0, -1, 0),
    (3, 'avengers', 'The.Avengers.2012.2160p.BluRay.REMUX-GRP', 500, 1, 60000000000, NULL, NULL, 'guid-3',
     NULL, 100, 2050, 0, 0, '', 30, 0, 0, 0, 1),
    (4, 'no nzb', 'no nzb', 40, 1, 800000000, NULL, NULL, 'guid-4', NULL, 100, 10, 0, 0, NULL, NULL, 0, 0, 0, 0),
    (5, 'small', 'small', 2, 1, 499999999, NULL, NULL, 'guid-5', NULL, 100, 10, 0, 0, NULL, NULL, 0, 0, 0, 0),
    (7, 'seven', 'seven', 2, 1, 500000000, '1970-01-01 00:00:00', '1969-12-31 23:59:59', 'guid-7', NULL, 100, 10, 0, 0, NULL, NULL, 0, 0, 0, 0),
    (8, 'eight', 'eight', 2, 1, 900000000, '2200-01-01 00:00:00', NULL, 'guid-8', NULL, 100, 10, 0, 0, NULL, NULL, 0, 0, 0, 0);

INSERT INTO releases_groups VALUES (1, 1), (1, 2), (5, 1);
INSERT INTO release_files VALUES (1, 'got.mkv', 4000000000, 'ABCD1234', 0), (2, 'a1.mkv', 64424509440, '', 0),
    (5, 'small.mkv', 499999999, '', 0);
INSERT INTO release_nfos VALUES (1, x'FF00FE'), (5, x'00');
INSERT INTO release_nzb_passwords VALUES (3, 'hunter2');
INSERT INTO par_hashes VALUES (1, '00112233445566778899aabbccddeeff');
INSERT INTO par_file_hashes VALUES (1, 1, 'guid-1', 'h16', 'md5', '4000000000');
INSERT INTO par_file_hash_inventories (releases_id, release_guid, evidence_last_id, evidence_count) VALUES (1, 'guid-1', 1, 1);
INSERT INTO par2_descriptor_evidence
    (id, releases_id, release_guid, packet_checksum, recovery_set_id, file_id, full_md5, hash16k, file_size,
     raw_filename_base64, descriptor_proof_version)
VALUES (1, 1, 'guid-1', 'p', 'r', 'f', 'm', 'h', '4000000000', 'Z290Lm1rdg==', 1);
INSERT INTO release_archive_naming_evidence VALUES (1, 'guid-1', 'sha', 1, 'season', '[]', '2026-10-01 00:00:00');
INSERT INTO video_data VALUES (2, 'Matroska', '40000', '7200000', 'HEVC', 'V_MPEGH', 3840, 2160, '16:9', 23.976, 'x265');
INSERT INTO audio_data VALUES (1, 2, 1, 'TrueHD', NULL, NULL, NULL, '8', '48000', NULL, 'en', 'Atmos');
INSERT INTO release_subtitles VALUES (1, 2, 1, 'en');
INSERT INTO release_unique VALUES (2, 'uid-2');
INSERT INTO media_infos (id, releases_id, movie_name, file_name, unique_id)
VALUES (1, 2, 'Avatar', 'a1.mkv', 'uid-2'), (2, 999, 'Deleted', 'gone.mkv', 'uid-999');
INSERT INTO release_fingerprint_metadata VALUES (2, 'done', '{"a":1}', NULL, '2026-10-02 00:00:00');

INSERT INTO predb (id, title, nfo, size, category, predate, imported_at, source, requestid, groups_id, nuked, nukereason, files, filename)
VALUES
    (100, 'Game.of.Thrones.S03E09.1080p.WEB-DL-GRP', NULL, '4.0GB', 'TV', '2026-10-01 11:00:00', '2026-10-01 11:00:05', 'irc', 0, 0, 0, NULL, '60F', ''),
    (101, 'Silo.S03E03.1080p.WEB.H264-GRP', NULL, NULL, NULL, '1965-06-01 00:00:00', NULL, 'feed', 7, 1, 2, 'bad.audio', NULL, 'silo.mkv');
