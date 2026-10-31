-- Catalog schema. Times are unix seconds in UTC. Absent IDs are NULL, never 0.
-- Text columns can hold a BLOB when the source bytes were not UTF-8, which keeps
-- them exact; import_anomalies lists each case.

CREATE TABLE usenet_groups (
    id   INTEGER PRIMARY KEY,
    name TEXT NOT NULL
);

CREATE TABLE releases (
    id              INTEGER PRIMARY KEY,
    guid            TEXT NOT NULL UNIQUE,
    name            TEXT NOT NULL,
    search_name     TEXT NOT NULL,
    size_bytes      INTEGER NOT NULL,
    total_parts     INTEGER,
    completion      REAL NOT NULL,
    group_id        INTEGER NOT NULL,
    poster          TEXT,
    posted_at       INTEGER,
    added_at        INTEGER,
    legacy_category INTEGER NOT NULL,
    predb_id        INTEGER,
    password_status INTEGER NOT NULL,
    is_renamed      INTEGER NOT NULL,
    grabs           INTEGER NOT NULL,
    media_title     TEXT,
    media_year      TEXT,
    imdb_id         TEXT,
    tmdb_id         INTEGER,
    tvdb_id         INTEGER,
    tvmaze_id       INTEGER,
    trakt_id        INTEGER,
    tvrage_id       INTEGER,
    season          INTEGER,
    episode         INTEGER
);
CREATE INDEX releases_posted_at ON releases (posted_at);
CREATE INDEX releases_search_name ON releases (search_name);
CREATE INDEX releases_predb_id ON releases (predb_id) WHERE predb_id IS NOT NULL;
CREATE INDEX releases_imdb_id ON releases (imdb_id) WHERE imdb_id IS NOT NULL;
CREATE INDEX releases_tmdb_id ON releases (tmdb_id) WHERE tmdb_id IS NOT NULL;
CREATE INDEX releases_tvdb_id ON releases (tvdb_id, season, episode) WHERE tvdb_id IS NOT NULL;
CREATE INDEX releases_tvmaze_id ON releases (tvmaze_id, season, episode) WHERE tvmaze_id IS NOT NULL;
CREATE INDEX releases_trakt_id ON releases (trakt_id, season, episode) WHERE trakt_id IS NOT NULL;
CREATE INDEX releases_tvrage_id ON releases (tvrage_id, season, episode) WHERE tvrage_id IS NOT NULL;

CREATE TABLE release_groups (
    release_id INTEGER NOT NULL,
    group_id   INTEGER NOT NULL,
    PRIMARY KEY (release_id, group_id)
) WITHOUT ROWID;

CREATE TABLE release_files (
    release_id INTEGER NOT NULL,
    name       TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    crc32      TEXT NOT NULL,
    passworded INTEGER NOT NULL,
    PRIMARY KEY (release_id, name)
) WITHOUT ROWID;
CREATE INDEX release_files_crc32 ON release_files (crc32) WHERE crc32 <> '';

CREATE TABLE release_nfos (
    release_id INTEGER PRIMARY KEY,
    nfo        BLOB
);

CREATE TABLE release_passwords (
    release_id INTEGER PRIMARY KEY,
    password   TEXT NOT NULL
);

CREATE TABLE par_hashes (
    release_id INTEGER NOT NULL,
    hash16k    TEXT NOT NULL,
    PRIMARY KEY (release_id, hash16k)
) WITHOUT ROWID;
CREATE INDEX par_hashes_hash16k ON par_hashes (hash16k);

CREATE TABLE par_file_hashes (
    id         INTEGER PRIMARY KEY,
    release_id INTEGER NOT NULL,
    hash16k    TEXT NOT NULL,
    full_md5   TEXT NOT NULL,
    file_size  TEXT NOT NULL
);
CREATE INDEX par_file_hashes_release ON par_file_hashes (release_id);
CREATE INDEX par_file_hashes_hash16k ON par_file_hashes (hash16k);
CREATE INDEX par_file_hashes_full_md5 ON par_file_hashes (full_md5, file_size);

CREATE TABLE par2_descriptors (
    id                        INTEGER PRIMARY KEY,
    release_id                INTEGER NOT NULL,
    packet_checksum           TEXT NOT NULL,
    recovery_set_id           TEXT NOT NULL,
    file_id                   TEXT NOT NULL,
    full_md5                  TEXT NOT NULL,
    hash16k                   TEXT NOT NULL,
    file_size                 TEXT NOT NULL,
    raw_filename_base64       TEXT NOT NULL,
    proof_version             INTEGER NOT NULL,
    classification            TEXT NOT NULL,
    classification_version    INTEGER NOT NULL,
    classification_provenance TEXT NOT NULL
);
CREATE INDEX par2_descriptors_release ON par2_descriptors (release_id);

CREATE TABLE archive_naming_evidence (
    release_id    INTEGER PRIMARY KEY,
    source_sha256 TEXT NOT NULL,
    proof_version INTEGER NOT NULL,
    scope         TEXT NOT NULL,
    episode_files TEXT NOT NULL,
    updated_at    INTEGER
);

CREATE TABLE media_video (
    release_id       INTEGER PRIMARY KEY,
    container_format TEXT,
    overall_bitrate  TEXT,
    duration         TEXT,
    video_format     TEXT,
    codec            TEXT,
    width            INTEGER,
    height           INTEGER,
    aspect           TEXT,
    frame_rate       REAL,
    library          TEXT
);

CREATE TABLE media_audio (
    id           INTEGER PRIMARY KEY,
    release_id   INTEGER NOT NULL,
    track_id     INTEGER NOT NULL,
    format       TEXT,
    mode         TEXT,
    bitrate_mode TEXT,
    bitrate      TEXT,
    channels     TEXT,
    sample_rate  TEXT,
    library      TEXT,
    language     TEXT,
    title        TEXT
);
CREATE INDEX media_audio_release ON media_audio (release_id);

CREATE TABLE media_subtitles (
    id         INTEGER PRIMARY KEY,
    release_id INTEGER NOT NULL,
    track_id   INTEGER NOT NULL,
    language   TEXT NOT NULL
);
CREATE INDEX media_subtitles_release ON media_subtitles (release_id);

CREATE TABLE media_unique_ids (
    release_id INTEGER NOT NULL,
    unique_id  TEXT NOT NULL,
    PRIMARY KEY (release_id, unique_id)
) WITHOUT ROWID;
CREATE INDEX media_unique_ids_unique_id ON media_unique_ids (unique_id);

CREATE TABLE media_infos (
    id         INTEGER PRIMARY KEY,
    release_id INTEGER NOT NULL,
    movie_name TEXT,
    file_name  TEXT,
    unique_id  TEXT
);
CREATE INDEX media_infos_release ON media_infos (release_id);

CREATE TABLE fingerprint_metadata (
    release_id   INTEGER PRIMARY KEY,
    status       TEXT NOT NULL,
    metadata     TEXT,
    last_error   TEXT,
    collected_at INTEGER
);

CREATE TABLE predb (
    id          INTEGER PRIMARY KEY,
    title       TEXT NOT NULL,
    nfo         TEXT,
    size        TEXT,
    category    TEXT,
    pre_at      INTEGER,
    imported_at INTEGER,
    source      TEXT NOT NULL,
    request_id  INTEGER NOT NULL,
    group_id    INTEGER NOT NULL,
    nuked       INTEGER NOT NULL,
    nuke_reason TEXT,
    files       TEXT,
    filename    TEXT NOT NULL
);
CREATE INDEX predb_title ON predb (title);
CREATE INDEX predb_pre_at ON predb (pre_at);

-- Resume point of each import step, committed with the rows it covers.
CREATE TABLE import_state (
    step      TEXT PRIMARY KEY,
    last_id   INTEGER NOT NULL,
    min_size  INTEGER NOT NULL,
    finished  INTEGER NOT NULL DEFAULT 0
);

-- Anything the importer kept but could not vouch for.
CREATE TABLE import_anomalies (
    id         INTEGER PRIMARY KEY,
    release_id INTEGER,
    kind       TEXT NOT NULL,
    detail     TEXT NOT NULL
);
CREATE INDEX import_anomalies_kind ON import_anomalies (kind);
