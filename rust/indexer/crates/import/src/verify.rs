//! Proves the copy. Every row and NZB is read back from disk and compared with the
//! source: rows by exact value, NZBs by the source's own SHA-256 of the stored bytes.

use std::collections::{BTreeMap, HashMap, HashSet};
use std::path::{Path, PathBuf};
use std::pin::pin;
use std::time::{Duration, Instant};

use anyhow::{Context, Result, bail};
use futures_util::{StreamExt, stream};
use indexer_store::rusqlite::types::Value as SqlValue;
use indexer_store::rusqlite::{OptionalExtension, params};
use indexer_store::{Catalog, MAX_DECODED_BYTES, NzbStore, StorePaths, body_sha256, gunzip_capped};
use tokio::sync::mpsc;

use crate::convert::{SqlRow, canonical, row_to_sql};
use crate::legacy::legacy_digest;
use crate::plan::IdRange;
use crate::run::{STEP_GROUPS, STEP_PREDB, STEP_RELEASES, plan_ranges};
use crate::source::Source;
use crate::spec::{self, GROUPS, PREDB, RELEASE_CHILDREN, RELEASES, Table};

const KEYSET_PAGE: u64 = 20_000;
/// Problems kept for the report. The count covers them all.
const PROBLEMS_KEPT: usize = 200;

#[derive(Debug, Default)]
pub struct VerifyReport {
    /// Table, rows expected from the source, rows in the catalog.
    pub counts: Vec<(String, u64, u64)>,
    pub rows_compared: u64,
    pub nzbs_compared: u64,
    pub nzb_bytes: u64,
    /// NZBs that match the source byte for byte, but that the source's own record
    /// disagrees with. The import flagged these, by kind.
    pub flagged_nzbs: BTreeMap<String, u64>,
    pub problem_count: u64,
    pub problems: Vec<String>,
    /// False when the import behind this report stopped early.
    pub complete: bool,
    pub elapsed: Duration,
}

impl VerifyReport {
    /// Whether the catalog is a complete, exact copy.
    pub fn clean(&self) -> bool {
        self.complete && self.problem_count == 0
    }

    fn problem(&mut self, problem: String) {
        self.problem_count += 1;
        if self.problems.len() < PROBLEMS_KEPT {
            self.problems.push(problem);
        }
    }

    fn absorb(&mut self, part: Self) {
        self.rows_compared += part.rows_compared;
        self.nzbs_compared += part.nzbs_compared;
        self.nzb_bytes += part.nzb_bytes;
        for (kind, count) in part.flagged_nzbs {
            *self.flagged_nzbs.entry(kind).or_default() += count;
        }
        self.problem_count += part.problem_count;
        for problem in part.problems {
            if self.problems.len() < PROBLEMS_KEPT {
                self.problems.push(problem);
            }
        }
    }
}

struct ImportState {
    min_size: u64,
    last_id: i64,
    finished: bool,
}

fn import_state(catalog: &Catalog, step: &str) -> Result<Option<ImportState>> {
    Ok(catalog
        .conn()
        .query_row(
            "SELECT min_size, last_id, finished FROM import_state WHERE step = ?1",
            [step],
            |row| {
                Ok(ImportState {
                    min_size: row.get::<_, i64>(0)? as u64,
                    last_id: row.get(1)?,
                    finished: row.get(2)?,
                })
            },
        )
        .optional()?)
}

/// Compares a catalog with its source. A difference is a finding, not an error.
pub async fn verify(
    source: &Source,
    data_dir: &Path,
    batch_rows: usize,
    batch_decoded_bytes: u64,
    concurrency: usize,
) -> Result<VerifyReport> {
    let started = Instant::now();
    let paths = StorePaths::under(data_dir);
    if !paths.catalog.exists() {
        bail!("no catalog at {}", paths.catalog.display());
    }
    let catalog = Catalog::open_read_only(&paths.catalog)?;
    let Some(releases) = import_state(&catalog, STEP_RELEASES)? else {
        bail!("no release was imported into {}", paths.catalog.display());
    };
    let finished =
        |step| Ok::<_, anyhow::Error>(import_state(&catalog, step)?.is_some_and(|s| s.finished));
    let mut report = VerifyReport {
        complete: releases.finished && finished(STEP_GROUPS)? && finished(STEP_PREDB)?,
        ..VerifyReport::default()
    };

    compare_keyset(source, &catalog, &GROUPS, &mut report).await?;
    compare_keyset(source, &catalog, &PREDB, &mut report).await?;

    let concurrency = concurrency.max(1);
    let (range_tx, range_rx) = mpsc::channel(concurrency);
    let through = if releases.finished {
        i64::MAX
    } else {
        releases.last_id
    };
    let planner = tokio::spawn(plan_ranges(
        source.clone(),
        0,
        through,
        releases.min_size,
        (batch_rows.max(1), batch_decoded_bytes),
        None,
        range_tx,
    ));
    let min_size = releases.min_size;
    let reader = source.clone();
    let nzb_path = paths.nzbs.clone();
    let catalog_path = paths.catalog.clone();
    let mut parts = pin!(
        stream::unfold(range_rx, |mut ranges| async move {
            ranges.recv().await.map(|range| (range, ranges))
        })
        .map(move |range| {
            tokio::spawn(compare_range(
                reader.clone(),
                catalog_path.clone(),
                nzb_path.clone(),
                range,
                min_size,
            ))
        })
        .buffer_unordered(concurrency)
    );
    let mut last_log = Instant::now();
    while let Some(part) = parts.next().await {
        report.absorb(part.context("comparison stopped")??);
        if last_log.elapsed() >= Duration::from_secs(15) {
            last_log = Instant::now();
            tracing::info!(
                rows = report.rows_compared,
                nzbs = report.nzbs_compared,
                nzb_gb = report.nzb_bytes as f64 / 1e9,
                problems = report.problem_count,
                "verifying"
            );
        }
    }
    planner.await.context("planner stopped")??;

    if report.complete {
        compare_counts(
            source,
            &catalog,
            &paths.nzbs,
            releases.min_size,
            &mut report,
        )
        .await?;
    }
    report.elapsed = started.elapsed();
    Ok(report)
}

async fn compare_counts(
    source: &Source,
    catalog: &Catalog,
    nzbs: &Path,
    min_size: u64,
    report: &mut VerifyReport,
) -> Result<()> {
    for table in spec::all_tables() {
        let counts: Vec<u64> = if table.has_floor() {
            source.select(&table.select_count(), (min_size,)).await?
        } else {
            source.select(&table.select_count(), ()).await?
        };
        let expected = counts.first().copied().unwrap_or(0);
        let actual: i64 = catalog
            .conn()
            .query_row(&table.dest_count(), [], |row| row.get(0))?;
        record_count(report, table.dest, expected, actual as u64);
    }
    let counts: Vec<u64> = source.select(spec::SELECT_NZB_COUNT, (min_size,)).await?;
    let actual = NzbStore::open_read_only(nzbs)?.count()? as u64;
    record_count(report, "nzbs", counts.first().copied().unwrap_or(0), actual);

    let totals: Vec<(u64, u64)> = source
        .select(spec::SELECT_RELEASE_TOTALS, (min_size,))
        .await?;
    let (rows, bytes): (i64, i64) = catalog.conn().query_row(
        "SELECT COUNT(*), COALESCE(SUM(size_bytes), 0) FROM releases",
        [],
        |row| Ok((row.get(0)?, row.get(1)?)),
    )?;
    let (source_rows, source_bytes) = totals.first().copied().unwrap_or((0, 0));
    if (source_rows, source_bytes) != (rows as u64, bytes as u64) {
        report.problem(format!(
            "release totals: source {source_rows} rows of {source_bytes} bytes, \
             catalog {rows} rows of {bytes} bytes"
        ));
    }
    Ok(())
}

fn record_count(report: &mut VerifyReport, table: &str, expected: u64, actual: u64) {
    if expected != actual {
        report.problem(format!(
            "{table}: source has {expected} rows, catalog has {actual}"
        ));
    }
    report.counts.push((table.to_owned(), expected, actual));
}

fn dest_rows(catalog: &Catalog, table: &Table, low: i64, high: i64) -> Result<Vec<Vec<u8>>> {
    let mut statement = catalog.conn().prepare_cached(&table.dest_range())?;
    let rows = statement.query_map(params![low, high], |row| {
        (0..table.columns.len())
            .map(|i| row.get::<_, SqlValue>(i))
            .collect::<indexer_store::rusqlite::Result<Vec<_>>>()
    })?;
    let mut encoded = Vec::new();
    for row in rows {
        encoded.push(canonical(&row?));
    }
    encoded.sort_unstable();
    Ok(encoded)
}

/// Compares two sets of rows, reporting a count of the rows that differ.
fn compare_rows(
    table: &Table,
    range: IdRange,
    mut source_rows: Vec<Vec<u8>>,
    dest: Vec<Vec<u8>>,
    report: &mut VerifyReport,
) {
    source_rows.sort_unstable();
    report.rows_compared += source_rows.len() as u64;
    if source_rows == dest {
        return;
    }
    let source_set: HashSet<&Vec<u8>> = source_rows.iter().collect();
    let dest_set: HashSet<&Vec<u8>> = dest.iter().collect();
    let missing = source_set.difference(&dest_set).count();
    let extra = dest_set.difference(&source_set).count();
    report.problem(format!(
        "{}: IDs {}..={}: {missing} source rows are not in the catalog, \
         {extra} catalog rows are not in the source",
        table.dest, range.low, range.high
    ));
}

async fn source_rows(
    source: &Source,
    table: &'static Table,
    range: IdRange,
    min_size: u64,
) -> Result<Vec<SqlRow>> {
    let values = if table.has_floor() {
        source
            .select_values(&table.select_range(), (range.low, range.high, min_size))
            .await?
    } else {
        source
            .select_values(&table.select_range(), (range.low, range.high))
            .await?
    };
    let mut ignored = Vec::new();
    values
        .into_iter()
        .map(|row| row_to_sql(table.dest, table.columns, row, &mut ignored))
        .collect()
}

async fn compare_keyset(
    source: &Source,
    catalog: &Catalog,
    table: &'static Table,
    report: &mut VerifyReport,
) -> Result<()> {
    let sql = spec::select_keyset(table);
    let mut after = 0_i64;
    let mut seen = false;
    loop {
        let values = source.select_values(&sql, (after, KEYSET_PAGE)).await?;
        let page = values.len() as u64;
        let mut ignored = Vec::new();
        let rows: Vec<SqlRow> = values
            .into_iter()
            .map(|row| row_to_sql(table.dest, table.columns, row, &mut ignored))
            .collect::<Result<_>>()?;
        let (Some(first), Some(last)) = (rows.first(), rows.last()) else {
            break;
        };
        let range = IdRange {
            low: key(first)?,
            high: key(last)?,
        };
        let dest = dest_rows(catalog, table, range.low, range.high)?;
        let encoded = rows.iter().map(|row| canonical(row)).collect();
        compare_rows(table, range, encoded, dest, report);
        after = range.high;
        seen = true;
        if page < KEYSET_PAGE {
            break;
        }
    }
    if !seen {
        tracing::warn!(table = table.dest, "source table is empty");
    }
    Ok(())
}

fn key(row: &SqlRow) -> Result<i64> {
    match row.first() {
        Some(SqlValue::Integer(id)) => Ok(*id),
        other => bail!("row key is {other:?}, not an integer"),
    }
}

async fn compare_range(
    source: Source,
    catalog_path: PathBuf,
    nzb_path: PathBuf,
    range: IdRange,
    min_size: u64,
) -> Result<VerifyReport> {
    let mut tables = Vec::with_capacity(RELEASE_CHILDREN.len() + 1);
    tables.push((
        &RELEASES,
        source_rows(&source, &RELEASES, range, min_size).await?,
    ));
    for table in RELEASE_CHILDREN.iter().copied() {
        tables.push((table, source_rows(&source, table, range, min_size).await?));
    }
    let hashes: Vec<(i64, String, i64, String)> = source
        .select(spec::SELECT_NZB_HASHES, (range.low, range.high, min_size))
        .await?;

    tokio::task::spawn_blocking(move || {
        let catalog = Catalog::open_read_only(&catalog_path)?;
        let nzbs = NzbStore::open_read_only(&nzb_path)?;
        let mut report = VerifyReport::default();
        for (table, rows) in tables {
            let dest = dest_rows(&catalog, table, range.low, range.high)?;
            let encoded = rows.iter().map(|row| canonical(row)).collect();
            compare_rows(table, range, encoded, dest, &mut report);
        }
        compare_nzbs(&catalog, &nzbs, range, hashes, &mut report)?;
        Ok(report)
    })
    .await
    .context("comparison thread stopped")?
}

fn flagged_nzbs(catalog: &Catalog, range: IdRange) -> Result<HashMap<i64, String>> {
    let mut statement = catalog.conn().prepare_cached(
        "SELECT release_id, kind FROM import_anomalies
         WHERE release_id BETWEEN ?1 AND ?2
           AND kind IN ('nzb_digest_mismatch', 'nzb_size_mismatch', 'nzb_undecodable')",
    )?;
    let rows = statement.query_map(params![range.low, range.high], |row| {
        Ok((row.get(0)?, row.get(1)?))
    })?;
    Ok(rows.collect::<indexer_store::rusqlite::Result<_>>()?)
}

fn compare_nzbs(
    catalog: &Catalog,
    nzbs: &NzbStore,
    range: IdRange,
    hashes: Vec<(i64, String, i64, String)>,
    report: &mut VerifyReport,
) -> Result<()> {
    let flagged = flagged_nzbs(catalog, range)?;
    let mut expected = HashSet::with_capacity(hashes.len());
    for (release_id, digest, decoded_bytes, source_sha256) in hashes {
        expected.insert(release_id);
        report.nzbs_compared += 1;
        let Some(stored) = nzbs.get(release_id)? else {
            report.problem(format!(
                "release {release_id}: NZB is missing from the store"
            ));
            continue;
        };
        report.nzb_bytes += stored.body.len() as u64;
        let actual_sha256 = body_sha256(&stored.body);
        if !hex::encode(actual_sha256).eq_ignore_ascii_case(&source_sha256) {
            report.problem(format!(
                "release {release_id}: NZB bytes differ from the source"
            ));
            continue;
        }
        if stored.body_sha256 != actual_sha256 {
            report.problem(format!("release {release_id}: recorded NZB hash is wrong"));
        }
        if stored.legacy_digest.as_deref() != Some(digest.as_str()) {
            report.problem(format!(
                "release {release_id}: recorded source digest is wrong"
            ));
        }
        if let Some(kind) = flagged.get(&release_id) {
            *report.flagged_nzbs.entry(kind.clone()).or_default() += 1;
            continue;
        }
        match gunzip_capped(&stored.body, MAX_DECODED_BYTES) {
            Ok(xml) => {
                if legacy_digest(&xml) != digest {
                    report.problem(format!("release {release_id}: NZB digest does not match"));
                }
                if xml.len() as i64 != decoded_bytes || stored.decoded_bytes != decoded_bytes {
                    report.problem(format!("release {release_id}: NZB decoded size differs"));
                }
            }
            Err(error) => {
                report.problem(format!(
                    "release {release_id}: NZB does not decode: {error:#}"
                ));
            }
        }
    }

    let mut statement = nzbs
        .conn()
        .prepare_cached("SELECT release_id FROM nzbs WHERE release_id BETWEEN ?1 AND ?2")?;
    let ids = statement.query_map(params![range.low, range.high], |row| row.get::<_, i64>(0))?;
    for id in ids {
        let id = id?;
        if !expected.contains(&id) {
            report.problem(format!(
                "release {id}: the store has an NZB the source lacks"
            ));
        }
    }
    Ok(())
}
