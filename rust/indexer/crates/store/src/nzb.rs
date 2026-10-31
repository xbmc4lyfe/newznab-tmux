//! NZB bodies, kept exactly as they arrive so a body can be served without
//! recompressing and checked against its digest at any time.

use std::io::Read;
use std::path::Path;

use anyhow::{Context, Result, bail};
use flate2::bufread::GzDecoder;
use rusqlite::{Connection, OpenFlags, OptionalExtension, params};
use sha2::{Digest, Sha256};

use crate::sqlite;

const MIGRATIONS: &[&str] = &[include_str!("../migrations/0001_nzb.sql")];

/// The largest NZB this store will decode, a guard against decompression bombs.
pub const MAX_DECODED_BYTES: u64 = 268_435_456;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum NzbEncoding {
    Gzip,
}

impl NzbEncoding {
    const fn as_str(self) -> &'static str {
        match self {
            Self::Gzip => "gzip",
        }
    }
}

/// A body to store. `body_sha256` comes from the caller, which computes it off
/// the single writer thread.
#[derive(Clone, Debug)]
pub struct NzbWrite {
    pub release_id: i64,
    pub encoding: NzbEncoding,
    pub decoded_bytes: i64,
    pub body_sha256: [u8; 32],
    pub legacy_digest: Option<String>,
    pub body: Vec<u8>,
}

/// A stored body with what is known about it.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct StoredNzb {
    pub encoding: String,
    pub decoded_bytes: i64,
    pub body_sha256: Vec<u8>,
    pub legacy_digest: Option<String>,
    pub body: Vec<u8>,
}

pub struct NzbStore {
    conn: Connection,
}

impl NzbStore {
    pub fn open(path: &Path) -> Result<Self> {
        let mut conn = Connection::open(path)
            .with_context(|| format!("opening NZB store {}", path.display()))?;
        // page_size only takes effect before the file has any pages. fullfsync and
        // FULL make each commit durable, which the catalog's write order relies on.
        conn.execute_batch(
            "PRAGMA page_size = 65536;
             PRAGMA busy_timeout = 10000;",
        )?;
        sqlite::set_wal(&conn)?;
        conn.execute_batch(
            "PRAGMA synchronous = FULL;
             PRAGMA fullfsync = ON;
             PRAGMA checkpoint_fullfsync = ON;
             PRAGMA journal_size_limit = 1073741824;
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
        .with_context(|| format!("opening NZB store {}", path.display()))?;
        conn.execute_batch("PRAGMA busy_timeout = 10000;")?;
        Ok(Self { conn })
    }

    pub fn conn(&self) -> &Connection {
        &self.conn
    }

    /// Stores every body in one durable transaction, replacing any earlier copy.
    pub fn put_batch(&mut self, writes: &[NzbWrite]) -> Result<()> {
        let tx = self.conn.transaction()?;
        {
            let mut insert = tx.prepare_cached(
                "INSERT OR REPLACE INTO nzbs
                     (release_id, encoding, decoded_bytes, body_sha256, legacy_digest, body)
                 VALUES (?1, ?2, ?3, ?4, ?5, ?6)",
            )?;
            for write in writes {
                insert.execute(params![
                    write.release_id,
                    write.encoding.as_str(),
                    write.decoded_bytes,
                    &write.body_sha256[..],
                    write.legacy_digest,
                    write.body,
                ])?;
            }
        }
        tx.commit()?;
        Ok(())
    }

    pub fn get(&self, release_id: i64) -> Result<Option<StoredNzb>> {
        Ok(self
            .conn
            .prepare_cached(
                "SELECT encoding, decoded_bytes, body_sha256, legacy_digest, body
                 FROM nzbs WHERE release_id = ?1",
            )?
            .query_row([release_id], |row| {
                Ok(StoredNzb {
                    encoding: row.get(0)?,
                    decoded_bytes: row.get(1)?,
                    body_sha256: row.get(2)?,
                    legacy_digest: row.get(3)?,
                    body: row.get(4)?,
                })
            })
            .optional()?)
    }

    pub fn count(&self) -> Result<i64> {
        Ok(self
            .conn
            .query_row("SELECT COUNT(*) FROM nzbs", [], |row| row.get(0))?)
    }

    /// Folds the write-ahead log back into the main file.
    pub fn checkpoint(&self) -> Result<()> {
        self.conn
            .execute_batch("PRAGMA wal_checkpoint(TRUNCATE);")?;
        Ok(())
    }
}

pub fn body_sha256(body: &[u8]) -> [u8; 32] {
    Sha256::digest(body).into()
}

/// Decodes one gzip member, failing on trailing data or more than `max` bytes.
pub fn gunzip_capped(body: &[u8], max: u64) -> Result<Vec<u8>> {
    let mut xml = Vec::with_capacity(body.len().saturating_mul(3));
    let mut decoder = GzDecoder::new(body);
    (&mut decoder)
        .take(max.saturating_add(1))
        .read_to_end(&mut xml)
        .context("decoding gzip")?;
    if xml.len() as u64 > max {
        bail!("decodes to more than {max} bytes");
    }
    let rest = decoder.into_inner();
    if !rest.is_empty() {
        bail!("{} bytes follow the gzip stream", rest.len());
    }
    Ok(xml)
}

#[cfg(test)]
mod tests {
    use std::io::Write;

    use flate2::{Compression, write::GzEncoder};

    use super::*;

    fn gzip(data: &[u8]) -> Vec<u8> {
        let mut encoder = GzEncoder::new(Vec::new(), Compression::fast());
        encoder.write_all(data).unwrap();
        encoder.finish().unwrap()
    }

    fn write(release_id: i64, body: Vec<u8>) -> NzbWrite {
        NzbWrite {
            release_id,
            encoding: NzbEncoding::Gzip,
            decoded_bytes: 3,
            body_sha256: body_sha256(&body),
            legacy_digest: Some("abc".into()),
            body,
        }
    }

    #[test]
    fn round_trips_bytes_exactly_and_replaces() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("nzbs.db");
        let mut store = NzbStore::open(&path).unwrap();
        let body = gzip(b"<nzb/>");
        store
            .put_batch(&[write(1, body.clone()), write(2, vec![0, 255, 7])])
            .unwrap();
        store.put_batch(&[write(2, vec![9])]).unwrap();
        drop(store);

        let reader = NzbStore::open_read_only(&path).unwrap();
        assert_eq!(reader.count().unwrap(), 2);
        let stored = reader.get(1).unwrap().unwrap();
        assert_eq!(stored.body, body);
        assert_eq!(stored.body_sha256, body_sha256(&body).to_vec());
        assert_eq!(stored.encoding, "gzip");
        assert_eq!(gunzip_capped(&stored.body, 100).unwrap(), b"<nzb/>");
        assert_eq!(reader.get(2).unwrap().unwrap().body, vec![9]);
        assert_eq!(reader.get(3).unwrap(), None);
    }

    #[test]
    fn bigger_than_a_page_body_round_trips() {
        let dir = tempfile::tempdir().unwrap();
        let mut store = NzbStore::open(&dir.path().join("nzbs.db")).unwrap();
        let body: Vec<u8> = (0..4_000_000u32).map(|n| (n % 251) as u8).collect();
        store.put_batch(&[write(1, body.clone())]).unwrap();
        assert_eq!(store.get(1).unwrap().unwrap().body, body);
    }

    #[test]
    fn gunzip_rejects_bad_input() {
        let body = gzip(b"hello");
        assert_eq!(gunzip_capped(&body, 5).unwrap(), b"hello");
        assert!(
            gunzip_capped(&body, 4)
                .unwrap_err()
                .to_string()
                .contains("more than")
        );
        let mut trailing = body.clone();
        trailing.push(0);
        assert!(
            gunzip_capped(&trailing, 100)
                .unwrap_err()
                .to_string()
                .contains("follow")
        );
        assert!(gunzip_capped(&body[..body.len() - 3], 100).is_err());
        assert!(gunzip_capped(b"not gzip", 100).is_err());
    }
}
