//! The catalog: releases, the evidence about them, and PreDB.

use std::path::Path;

use anyhow::{Context, Result};
use rusqlite::{Connection, OpenFlags, OptionalExtension, Row};

use crate::sqlite;

const MIGRATIONS: &[&str] = &[include_str!("../migrations/0001_catalog.sql")];

pub struct Catalog {
    conn: Connection,
}

impl Catalog {
    /// Opens for writing, creating or upgrading the file as needed.
    pub fn open(path: &Path) -> Result<Self> {
        let mut conn = Connection::open(path)
            .with_context(|| format!("opening catalog {}", path.display()))?;
        sqlite::set_wal(&conn)?;
        conn.execute_batch(
            "PRAGMA synchronous = NORMAL;
             PRAGMA busy_timeout = 10000;
             PRAGMA cache_size = -262144;
             PRAGMA temp_store = MEMORY;",
        )?;
        sqlite::migrate(&mut conn, MIGRATIONS)?;
        Ok(Self { conn })
    }

    pub fn open_read_only(path: &Path) -> Result<Self> {
        let conn = Connection::open_with_flags(
            path,
            OpenFlags::SQLITE_OPEN_READ_ONLY | OpenFlags::SQLITE_OPEN_NO_MUTEX,
        )
        .with_context(|| format!("opening catalog {}", path.display()))?;
        conn.execute_batch("PRAGMA busy_timeout = 10000; PRAGMA mmap_size = 4294967296;")?;
        Ok(Self { conn })
    }

    pub fn conn(&self) -> &Connection {
        &self.conn
    }

    pub fn conn_mut(&mut self) -> &mut Connection {
        &mut self.conn
    }

    pub fn release_by_guid(&self, guid: &str) -> Result<Option<Release>> {
        let sql = format!("SELECT {RELEASE_COLUMNS} FROM releases WHERE guid = ?1");
        Ok(self
            .conn
            .prepare_cached(&sql)?
            .query_row([guid], Release::from_row)
            .optional()?)
    }

    pub fn release_by_id(&self, id: i64) -> Result<Option<Release>> {
        let sql = format!("SELECT {RELEASE_COLUMNS} FROM releases WHERE id = ?1");
        Ok(self
            .conn
            .prepare_cached(&sql)?
            .query_row([id], Release::from_row)
            .optional()?)
    }
}

const RELEASE_COLUMNS: &str = "id, guid, name, search_name, size_bytes, posted_at, predb_id, \
     imdb_id, tmdb_id, tvdb_id, tvmaze_id, trakt_id, tvrage_id, season, episode";

/// A catalog release row, without its evidence tables.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Release {
    pub id: i64,
    pub guid: String,
    pub name: String,
    pub search_name: String,
    pub size_bytes: i64,
    pub posted_at: Option<i64>,
    pub predb_id: Option<i64>,
    pub imdb_id: Option<String>,
    pub tmdb_id: Option<i64>,
    pub tvdb_id: Option<i64>,
    pub tvmaze_id: Option<i64>,
    pub trakt_id: Option<i64>,
    pub tvrage_id: Option<i64>,
    pub season: Option<i64>,
    pub episode: Option<i64>,
}

impl Release {
    fn from_row(row: &Row<'_>) -> rusqlite::Result<Self> {
        Ok(Self {
            id: row.get(0)?,
            guid: row.get(1)?,
            name: row.get(2)?,
            search_name: row.get(3)?,
            size_bytes: row.get(4)?,
            posted_at: row.get(5)?,
            predb_id: row.get(6)?,
            imdb_id: row.get(7)?,
            tmdb_id: row.get(8)?,
            tvdb_id: row.get(9)?,
            tvmaze_id: row.get(10)?,
            trakt_id: row.get(11)?,
            tvrage_id: row.get(12)?,
            season: row.get(13)?,
            episode: row.get(14)?,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn open_is_idempotent_and_keeps_rows() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("catalog.db");
        {
            let catalog = Catalog::open(&path).unwrap();
            catalog
                .conn()
                .execute(
                    "INSERT INTO releases (id, guid, name, search_name, size_bytes, completion,
                         group_id, legacy_category, password_status, is_renamed, grabs, tvdb_id,
                         season, episode)
                     VALUES (7, 'g', 'n', 's', 64424509440, 100, 1, 2000, 0, 0, 0, 121361, 1, 2)",
                    [],
                )
                .unwrap();
        }
        let catalog = Catalog::open(&path).unwrap();
        let release = catalog.release_by_guid("g").unwrap().unwrap();
        assert_eq!(release.size_bytes, 64_424_509_440);
        assert_eq!(release.tvdb_id, Some(121_361));
        assert_eq!(release.imdb_id, None);
        assert_eq!(catalog.release_by_id(8).unwrap(), None);
    }

    #[test]
    fn id_search_uses_an_index() {
        let dir = tempfile::tempdir().unwrap();
        let catalog = Catalog::open(&dir.path().join("catalog.db")).unwrap();
        let plan: String = catalog
            .conn()
            .query_row(
                "EXPLAIN QUERY PLAN SELECT id FROM releases
                 WHERE tvdb_id = 121361 AND season = 1 AND episode = 2",
                [],
                |row| row.get(3),
            )
            .unwrap();
        assert!(plan.contains("releases_tvdb_id"), "{plan}");
    }

    #[test]
    fn read_only_rejects_writes() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("catalog.db");
        drop(Catalog::open(&path).unwrap());
        let reader = Catalog::open_read_only(&path).unwrap();
        assert!(reader.conn().execute("DELETE FROM releases", []).is_err());
    }
}
