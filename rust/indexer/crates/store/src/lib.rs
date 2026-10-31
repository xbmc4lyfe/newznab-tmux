//! Store stage: SQLite catalog rows and compressed NZBs.
//!
//! The catalog and the NZB bodies live in separate database files. Write an NZB
//! before the catalog row that points at it: the NZB file syncs on every commit,
//! so a crash can leave an unreferenced body but never a row without one.

mod catalog;
mod nzb;
mod sqlite;

use std::path::{Path, PathBuf};

pub use catalog::{Catalog, Release};
pub use nzb::{
    MAX_DECODED_BYTES, NzbEncoding, NzbStore, NzbWrite, StoredNzb, body_sha256, gunzip_capped,
};
pub use rusqlite;

/// Where a store's files live under its data directory.
#[derive(Clone, Debug)]
pub struct StorePaths {
    pub catalog: PathBuf,
    pub nzbs: PathBuf,
}

impl StorePaths {
    pub fn under(data_dir: &Path) -> Self {
        Self {
            catalog: data_dir.join("catalog.db"),
            nzbs: data_dir.join("nzbs.db"),
        }
    }
}
