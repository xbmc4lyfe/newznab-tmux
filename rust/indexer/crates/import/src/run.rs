//! The import itself: groups, then PreDB, then releases with their NZBs.

use std::collections::{BTreeMap, HashMap, HashSet};
use std::path::PathBuf;
use std::pin::pin;
use std::time::{Duration, Instant};

use anyhow::{Context, Result, bail};
use futures_util::{StreamExt, stream};
use indexer_store::NzbWrite;
use indexer_store::rusqlite::types::Value as SqlValue;
use indexer_telemetry::catalog::{IMPORT_ANOMALIES, IMPORT_NZB_BYTES, IMPORT_ROWS};
use mysql_async::Value;
use tokio::sync::mpsc;

use crate::convert::{Anomaly, SqlRow, row_to_sql};
use crate::legacy::{SourceNzb, check_nzb};
use crate::plan::{IdRange, Planner};
use crate::source::Source;
use crate::spec::{self, RELEASE_CHILDREN, RELEASES, Table, WHOLE_TABLES};
use crate::writer::{Batch, TableRows, Writer};

pub(crate) const STEP_RELEASES: &str = "releases";

const PLAN_PAGE: u64 = 5_000;
const KEYSET_PAGE: u64 = 20_000;
const PROGRESS_EVERY: Duration = Duration::from_secs(15);

#[derive(Clone, Debug)]
pub struct ImportOptions {
    pub data_dir: PathBuf,
    /// Releases below this size are left behind.
    pub min_size: u64,
    /// The most releases in one batch.
    pub batch_rows: usize,
    /// The most decoded NZB bytes in one batch, which bounds memory use.
    pub batch_decoded_bytes: u64,
    /// Batches read at once.
    pub concurrency: usize,
    /// Stop after this many more releases, for trying out an import.
    pub limit: Option<u64>,
}

#[derive(Debug, Default)]
pub struct ImportReport {
    pub rows: BTreeMap<&'static str, u64>,
    pub nzbs: u64,
    pub nzb_bytes: u64,
    pub anomalies: BTreeMap<&'static str, u64>,
    /// False when `limit` stopped the import early.
    pub complete: bool,
    pub elapsed: Duration,
}

impl ImportReport {
    fn add(&mut self, batch: &Batch) {
        for rows in &batch.tables {
            let count = rows.rows.len() as u64;
            *self.rows.entry(rows.table.dest).or_default() += count;
            metrics::counter!(IMPORT_ROWS, "table" => rows.table.dest).increment(count);
        }
        let nzb_bytes: u64 = batch.nzbs.iter().map(|nzb| nzb.body.len() as u64).sum();
        self.nzbs += batch.nzbs.len() as u64;
        self.nzb_bytes += nzb_bytes;
        metrics::counter!(IMPORT_NZB_BYTES).increment(nzb_bytes);
        for anomaly in &batch.anomalies {
            *self.anomalies.entry(anomaly.kind).or_default() += 1;
            metrics::counter!(IMPORT_ANOMALIES, "kind" => anomaly.kind).increment(1);
        }
    }

    fn total_rows(&self) -> u64 {
        self.rows.values().sum()
    }
}

/// Fails if the source is missing any table or column, without reading a row.
pub async fn probe(source: &Source) -> Result<()> {
    for table in spec::all_tables() {
        let _: Vec<Vec<Value>> = source
            .select_values(&table.select_probe(), ())
            .await
            .with_context(|| format!("source table for {}", table.dest))?;
    }
    source
        .select_values(spec::SELECT_NZB_HASHES, (0, -1, 0))
        .await
        .context("NZB table")?;
    Ok(())
}

/// Runs or resumes an import.
pub async fn run(source: &Source, options: &ImportOptions) -> Result<ImportReport> {
    let started = Instant::now();
    probe(source).await?;
    let data_dir = options.data_dir.clone();
    let min_size = options.min_size;
    let mut writer = commit_blocking(move || Writer::open(&data_dir, min_size)).await?;
    let mut report = ImportReport::default();

    for (table, step) in WHOLE_TABLES.iter().copied() {
        writer = copy_keyset(source, writer, table, step, &mut report).await?;
    }
    let (writer, complete) = copy_releases(source, writer, options, &mut report).await?;
    commit_blocking(move || writer.checkpoint()).await?;

    report.complete = complete;
    report.elapsed = started.elapsed();
    Ok(report)
}

async fn commit_blocking<T: Send + 'static>(
    work: impl FnOnce() -> Result<T> + Send + 'static,
) -> Result<T> {
    tokio::task::spawn_blocking(work)
        .await
        .context("writer thread stopped")?
}

async fn commit(mut writer: Writer, batch: Batch, report: &mut ImportReport) -> Result<Writer> {
    let (writer, batch) = commit_blocking(move || {
        writer.write(&batch)?;
        Ok((writer, batch))
    })
    .await?;
    report.add(&batch);
    Ok(writer)
}

fn convert_rows(
    table: &'static Table,
    rows: Vec<Vec<Value>>,
    anomalies: &mut Vec<Anomaly>,
) -> Result<Vec<SqlRow>> {
    rows.into_iter()
        .map(|row| row_to_sql(table.dest, table.columns, row, anomalies))
        .collect()
}

fn first_id(row: &SqlRow) -> Result<i64> {
    match row.first() {
        Some(SqlValue::Integer(id)) => Ok(*id),
        other => bail!("row key is {other:?}, not an integer"),
    }
}

/// Copies a table that has no size floor, in key order.
async fn copy_keyset(
    source: &Source,
    mut writer: Writer,
    table: &'static Table,
    step: &'static str,
    report: &mut ImportReport,
) -> Result<Writer> {
    let started = Instant::now();
    let sql = spec::select_keyset(table);
    let mut last_id = writer.resume_point(step)?;
    loop {
        let rows = source.select_values(&sql, (last_id, KEYSET_PAGE)).await?;
        let page = rows.len() as u64;
        let mut anomalies = Vec::new();
        let rows = convert_rows(table, rows, &mut anomalies)?;
        if let Some(last) = rows.last() {
            last_id = first_id(last)?;
            let batch = Batch {
                step,
                last_id,
                tables: vec![TableRows { table, rows }],
                nzbs: Vec::new(),
                anomalies,
            };
            writer = commit(writer, batch, report).await?;
        }
        if page < KEYSET_PAGE {
            break;
        }
    }
    let (writer, ()) = commit_blocking(move || {
        writer.finish_step(step, last_id)?;
        Ok((writer, ()))
    })
    .await?;
    tracing::info!(
        table = table.dest,
        rows = report.rows.get(table.dest).copied().unwrap_or(0),
        seconds = started.elapsed().as_secs_f64(),
        "copied table"
    );
    Ok(writer)
}

/// Sends ID ranges of releases above `after`. Returns whether it reached the end.
pub(crate) async fn plan_ranges(
    source: Source,
    after: i64,
    through: i64,
    min_size: u64,
    (batch_rows, batch_decoded_bytes): (usize, u64),
    limit: Option<u64>,
    ranges: mpsc::Sender<IdRange>,
) -> Result<bool> {
    let mut planner = Planner::new(batch_rows, batch_decoded_bytes);
    let mut last_id = after;
    let mut planned = 0_u64;
    let reached_end = 'pages: loop {
        let rows: Vec<(i64, u64)> = source
            .select(spec::SELECT_RELEASE_PLAN, (last_id, min_size, PLAN_PAGE))
            .await?;
        let page = rows.len() as u64;
        for (id, decoded_bytes) in rows {
            if id > through {
                break 'pages true;
            }
            if limit.is_some_and(|limit| planned >= limit) {
                break 'pages false;
            }
            last_id = id;
            planned += 1;
            if let Some(range) = planner.push(id, decoded_bytes)
                && ranges.send(range).await.is_err()
            {
                bail!("the import stopped reading batches");
            }
        }
        if page < PLAN_PAGE {
            break true;
        }
    };
    if let Some(range) = planner.finish()
        && ranges.send(range).await.is_err()
    {
        bail!("the import stopped reading batches");
    }
    Ok(reached_end)
}

async fn copy_releases(
    source: &Source,
    mut writer: Writer,
    options: &ImportOptions,
    report: &mut ImportReport,
) -> Result<(Writer, bool)> {
    let after = writer.resume_point(STEP_RELEASES)?;
    let concurrency = options.concurrency.max(1);
    let (range_tx, range_rx) = mpsc::channel(concurrency);
    let planner = tokio::spawn(plan_ranges(
        source.clone(),
        after,
        i64::MAX,
        options.min_size,
        (options.batch_rows.max(1), options.batch_decoded_bytes),
        options.limit,
        range_tx,
    ));

    // Spawned so reads keep going while a batch commits.
    let min_size = options.min_size;
    let reader = source.clone();
    let mut batches = pin!(
        stream::unfold(range_rx, |mut ranges| async move {
            ranges.recv().await.map(|range| (range, ranges))
        })
        .map(move |range| tokio::spawn(fetch_range(reader.clone(), range, min_size)))
        .buffered(concurrency)
    );

    let started = Instant::now();
    let mut last_log = Instant::now();
    let mut last_id = after;
    while let Some(batch) = batches.next().await {
        let batch = batch.context("batch reader stopped")??;
        last_id = batch.last_id;
        writer = commit(writer, batch, report).await?;
        if last_log.elapsed() >= PROGRESS_EVERY {
            last_log = Instant::now();
            let seconds = started.elapsed().as_secs_f64();
            tracing::info!(
                last_id,
                rows = report.total_rows(),
                nzbs = report.nzbs,
                nzb_gb = report.nzb_bytes as f64 / 1e9,
                rows_per_second = report.total_rows() as f64 / seconds,
                nzb_mb_per_second = report.nzb_bytes as f64 / 1e6 / seconds,
                "importing"
            );
        }
    }
    let complete = planner.await.context("planner stopped")??;
    if complete {
        let (done, ()) = commit_blocking(move || {
            writer.finish_step(STEP_RELEASES, last_id)?;
            Ok((writer, ()))
        })
        .await?;
        writer = done;
    }
    Ok((writer, complete))
}

/// Reads and checks everything belonging to one range of releases.
async fn fetch_range(source: Source, range: IdRange, min_size: u64) -> Result<Batch> {
    let bounds = (range.low, range.high, min_size);
    let mut anomalies = Vec::new();
    let mut tables = Vec::with_capacity(RELEASE_CHILDREN.len() + 1);

    let rows = source
        .select_values(&RELEASES.select_range(), bounds)
        .await?;
    let rows = convert_rows(&RELEASES, rows, &mut anomalies)?;
    let mut guids = HashMap::with_capacity(rows.len());
    for row in &rows {
        if let SqlValue::Text(guid) = &row[1] {
            guids.insert(first_id(row)?, guid.clone());
        }
    }
    tables.push(TableRows {
        table: &RELEASES,
        rows,
    });

    for table in RELEASE_CHILDREN.iter().copied() {
        let rows = source.select_values(&table.select_range(), bounds).await?;
        let rows = convert_rows(table, rows, &mut anomalies)?;
        tables.push(TableRows { table, rows });
    }

    let nzbs: Vec<(i64, String, String, i64, Vec<u8>)> =
        source.select(spec::SELECT_NZBS, bounds).await?;
    let (nzbs, nzb_anomalies) =
        tokio::task::spawn_blocking(move || check_nzbs(nzbs, &guids)).await?;
    anomalies.extend(nzb_anomalies);

    Ok(Batch {
        step: STEP_RELEASES,
        last_id: range.high,
        tables,
        nzbs,
        anomalies,
    })
}

fn check_nzbs(
    nzbs: Vec<(i64, String, String, i64, Vec<u8>)>,
    guids: &HashMap<i64, String>,
) -> (Vec<NzbWrite>, Vec<Anomaly>) {
    let mut anomalies = Vec::new();
    let mut writes = Vec::with_capacity(nzbs.len());
    let mut seen = HashSet::with_capacity(nzbs.len());
    for (release_id, guid, digest, decoded_bytes, body) in nzbs {
        seen.insert(release_id);
        if guids.get(&release_id) != Some(&guid) {
            anomalies.push(Anomaly {
                release_id: Some(release_id),
                kind: "nzb_guid_mismatch",
                detail: format!("NZB row has guid {guid}"),
            });
        }
        let (write, anomaly) = check_nzb(SourceNzb {
            release_id,
            digest,
            decoded_bytes,
            body,
        });
        writes.push(write);
        anomalies.extend(anomaly);
    }
    for release_id in guids.keys().filter(|id| !seen.contains(id)) {
        anomalies.push(Anomaly {
            release_id: Some(*release_id),
            kind: "nzb_missing",
            detail: String::new(),
        });
    }
    (writes, anomalies)
}
