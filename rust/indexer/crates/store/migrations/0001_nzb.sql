-- NZB bodies, in their own file so the catalog stays small enough to serve from.
CREATE TABLE nzbs (
    release_id    INTEGER PRIMARY KEY,
    encoding      TEXT NOT NULL,
    decoded_bytes INTEGER NOT NULL,
    body_sha256   BLOB NOT NULL,
    legacy_digest TEXT,
    body          BLOB NOT NULL
);
