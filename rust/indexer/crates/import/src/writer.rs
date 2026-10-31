//! The single writer. An NZB commits before the catalog row that needs it, and a
//! batch's rows commit together with the resume point that covers them.

use std::path::Path;

use anyhow::{Context, Result, bail};
use indexer_store::rusqlite::{OptionalExtension, Transaction, params, params_from_iter};
use indexer_store::{Catalog, NzbStore, NzbWrite, StorePaths};

use crate::convert::{Anomaly, SqlRow};
use crate::spec::Table;

/// Rows from one table.
pub struct TableRows {
    pub table: &'static Table,
    pub rows: Vec<SqlRow>,
}

/// Everything that commits together.
pub struct Batch {
    pub step: &'static str,
    pub last_id: i64,
    pub tables: Vec<TableRows>,
    pub nzbs: Vec<NzbWrite>,
    pub anomalies: Vec<Anomaly>,
}

pub struct Writer {
    catalog: Catalog,
    nzbs: NzbStore,
    min_size: u64,
}

impl Writer {
    pub fn open(data_dir: &Path, min_size: u64) -> Result<Self> {
        std::fs::create_dir_all(data_dir)
            .with_context(|| format!("creating {}", data_dir.display()))?;
        let paths = StorePaths::under(data_dir);
        Ok(Self {
            catalog: Catalog::open(&paths.catalog)?,
            nzbs: NzbStore::open(&paths.nzbs)?,
            min_size,
        })
    }

    /// Where a step left off. A size floor can't change partway through an import,
    /// because rows below the new floor would be missed or kept by accident.
    pub fn resume_point(&self, step: &str) -> Result<i64> {
        let state: Option<(i64, i64)> = self
            .catalog
            .conn()
            .query_row(
                "SELECT last_id, min_size FROM import_state WHERE step = ?1",
                [step],
                |row| Ok((row.get(0)?, row.get(1)?)),
            )
            .optional()?;
        match state {
            None => Ok(0),
            Some((_, previous)) if previous != self.min_size as i64 => {
                bail!(
                    "step {step} ran with a size floor of {previous} bytes, not {}; \
                     use a new data directory to change it",
                    self.min_size
                )
            }
            Some((last_id, _)) => Ok(last_id),
        }
    }

    pub fn write(&mut self, batch: &Batch) -> Result<()> {
        if !batch.nzbs.is_empty() {
            self.nzbs.put_batch(&batch.nzbs)?;
        }
        let tx = self.catalog.conn_mut().transaction()?;
        for rows in &batch.tables {
            let mut insert = tx.prepare_cached(&rows.table.insert())?;
            for row in &rows.rows {
                insert
                    .execute(params_from_iter(row.iter()))
                    .with_context(|| format!("inserting into {}", rows.table.dest))?;
            }
        }
        {
            let mut insert = tx.prepare_cached(
                "INSERT INTO import_anomalies (release_id, kind, detail) VALUES (?1, ?2, ?3)",
            )?;
            for anomaly in &batch.anomalies {
                insert.execute(params![anomaly.release_id, anomaly.kind, anomaly.detail])?;
            }
        }
        save_state(&tx, batch.step, batch.last_id, self.min_size, false)?;
        tx.commit()?;
        Ok(())
    }

    pub fn finish_step(&mut self, step: &str, last_id: i64) -> Result<()> {
        let tx = self.catalog.conn_mut().transaction()?;
        save_state(&tx, step, last_id, self.min_size, true)?;
        tx.commit()?;
        Ok(())
    }

    /// Folds the write-ahead logs back in, so the files on disk are complete.
    pub fn checkpoint(&self) -> Result<()> {
        self.catalog
            .conn()
            .execute_batch("PRAGMA wal_checkpoint(TRUNCATE); PRAGMA optimize;")?;
        self.nzbs.checkpoint()
    }
}

fn save_state(
    tx: &Transaction<'_>,
    step: &str,
    last_id: i64,
    min_size: u64,
    done: bool,
) -> Result<()> {
    tx.execute(
        "INSERT INTO import_state (step, last_id, min_size, finished) VALUES (?1, ?2, ?3, ?4)
         ON CONFLICT (step) DO UPDATE SET last_id = excluded.last_id, finished = excluded.finished",
        params![step, last_id, min_size as i64, done],
    )?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use indexer_store::rusqlite::types::Value as SqlValue;

    use super::*;
    use crate::spec::RELEASE_CHILDREN;

    fn batch(last_id: i64) -> Batch {
        Batch {
            step: "groups",
            last_id,
            tables: vec![TableRows {
                table: RELEASE_CHILDREN[0],
                rows: vec![vec![SqlValue::Integer(last_id), SqlValue::Integer(1)]],
            }],
            nzbs: Vec::new(),
            anomalies: vec![Anomaly {
                release_id: None,
                kind: "k",
                detail: "d".into(),
            }],
        }
    }

    #[test]
    fn resume_point_follows_commits() {
        let dir = tempfile::tempdir().unwrap();
        let mut writer = Writer::open(dir.path(), 100).unwrap();
        assert_eq!(writer.resume_point("groups").unwrap(), 0);
        writer.write(&batch(5)).unwrap();
        drop(writer);
        let writer = Writer::open(dir.path(), 100).unwrap();
        assert_eq!(writer.resume_point("groups").unwrap(), 5);
    }

    #[test]
    fn changing_the_size_floor_mid_import_is_refused() {
        let dir = tempfile::tempdir().unwrap();
        Writer::open(dir.path(), 100)
            .unwrap()
            .write(&batch(5))
            .unwrap();
        let error = Writer::open(dir.path(), 200)
            .unwrap()
            .resume_point("groups")
            .unwrap_err();
        assert!(error.to_string().contains("size floor"), "{error}");
    }
}
