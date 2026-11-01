//! `nntmux:index-logs`: tails the log directory into the Manticore table behind the admin log viewer.
//!
//! Each run reconciles the directory with `log_index_files` (new, renamed, truncated, replaced and
//! vanished files), then reads each file forward from where the last run stopped, newest files first,
//! within a byte budget. Progress is saved after every batch, so an interrupted run loses at most one.
//! The file format is documented in `nntmux_telemetry`.

use std::fs::{self, File};
use std::io::{BufRead, BufReader, Read, Seek, SeekFrom};
use std::os::unix::fs::MetadataExt;
use std::path::{Path, PathBuf};
use std::sync::LazyLock;
use std::time::{Duration, SystemTime};

use anyhow::{Result, bail};
use chrono::{DateTime, NaiveDateTime};
use nntmux_core::{parsed_or, var_or};
use nntmux_search::SearchClient;
use regex::bytes::Regex;
use serde_json::{Value, json};
use sha1::{Digest, Sha1};
use sqlx::MySqlPool;

const HEAD_BYTES: u64 = 1024;
const MAX_BATCH_BYTES: usize = 8_388_608;
const MAX_ENTRY_BYTES: usize = 32_768;
const SNIFF_BYTES: u64 = 8192;
const OFFSET_BITS: u32 = 40;
const MIN_INFIX_LENGTH: u32 = 3;
/// Minutes without writes after which a file's last entry counts as finished.
const TAIL_FLUSH: Duration = Duration::from_secs(120);
const LEVELS: [&str; 8] = [
    "debug",
    "info",
    "notice",
    "warning",
    "error",
    "critical",
    "alert",
    "emergency",
];

static HEADER: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(
        r"(?s)^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\]\s+([\w.\-]+)\.([A-Z]+):\s?((?s-u:.*))$",
    )
    .expect("a valid pattern")
});
static PLAIN_TIMESTAMP: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"^\s*\[?(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})").expect("a valid pattern")
});

/// Whether a run should do anything: the viewer's index is on and logs go to files.
pub fn enabled() -> bool {
    let on = !matches!(
        var_or("LOG_VIEWER_INDEX_ENABLED", "true")
            .to_ascii_lowercase()
            .as_str(),
        "0" | "false" | "(false)"
    );
    on && nntmux_core::optional("NNTMUX_LOG_DIR").is_some()
}

/// Seconds since the epoch for a log timestamp. One with no offset is UTC, the app's zone.
pub fn parse_timestamp(text: &str) -> Option<i64> {
    let text = text.replacen(' ', "T", 1);
    if let Ok(at) = DateTime::parse_from_rfc3339(&text) {
        return Some(at.timestamp());
    }
    if let Ok(at) = DateTime::parse_from_str(&text, "%Y-%m-%dT%H:%M:%S%.f%z") {
        return Some(at.timestamp());
    }
    NaiveDateTime::parse_from_str(&text, "%Y-%m-%dT%H:%M:%S%.f")
        .ok()
        .map(|at| at.and_utc().timestamp())
}

#[derive(Clone, Debug, PartialEq)]
pub struct Entry {
    pub offset: u64,
    pub end: u64,
    pub line_number: u64,
    pub timestamp: Option<i64>,
    pub channel: Option<String>,
    pub level: Option<String>,
    pub first_line: String,
    pub body: String,
    pub truncated: bool,
}

fn make_entry(
    offset: u64,
    end: u64,
    first: &[u8],
    body: &[u8],
    truncated: bool,
    structured: bool,
    line_number: u64,
) -> Entry {
    let lossy = |bytes: &[u8]| String::from_utf8_lossy(bytes).into_owned();
    let text = |group: &[u8]| std::str::from_utf8(group).ok().map(str::to_owned);
    if structured && let Some(header) = HEADER.captures(first) {
        let level = lossy(&header[3]).to_ascii_lowercase();
        return Entry {
            offset,
            end,
            line_number,
            timestamp: text(&header[1]).as_deref().and_then(parse_timestamp),
            channel: Some(lossy(&header[2])),
            level: LEVELS.contains(&level.as_str()).then_some(level),
            first_line: lossy(first),
            body: lossy(body),
            truncated,
        };
    }
    Entry {
        offset,
        end,
        line_number,
        timestamp: PLAIN_TIMESTAMP
            .captures(first)
            .and_then(|found| text(&found[1]))
            .as_deref()
            .and_then(parse_timestamp),
        channel: None,
        level: None,
        first_line: lossy(first),
        body: lossy(body),
        truncated,
    }
}

/// The bytes of a line, whether they're whole, whether a newline ended it, and the bytes consumed.
type Line = (Vec<u8>, bool, bool, u64);

/// One line, capped at `limit` bytes. `None` at the end of the file.
fn read_line<R: BufRead>(reader: &mut R, limit: usize) -> std::io::Result<Option<Line>> {
    let mut line = Vec::new();
    let consumed = reader.read_until(b'\n', &mut line)?;
    if consumed == 0 {
        return Ok(None);
    }
    let terminated = line.last() == Some(&b'\n');
    if terminated {
        line.pop();
    }
    if line.last() == Some(&b'\r') {
        line.pop();
    }
    let complete = line.len() <= limit;
    line.truncate(limit);
    Ok(Some((line, complete, terminated, consumed as u64)))
}

#[derive(Debug, PartialEq)]
pub struct Batch {
    pub entries: Vec<Entry>,
    /// Where the next read starts: an entry boundary, and the lines before it.
    pub offset: u64,
    pub line: u64,
    /// Whether to read again now: the batch filled up rather than running out of file or budget.
    pub more: bool,
}

struct Pending {
    offset: u64,
    line: u64,
    first: Vec<u8>,
    body: Vec<Vec<u8>>,
    bytes: usize,
    truncated: bool,
}

impl Pending {
    fn finish(self, end: u64) -> Entry {
        make_entry(
            self.offset,
            end,
            &self.first,
            &self.body.join(&b'\n'),
            self.truncated,
            true,
            self.line,
        )
    }
}

/// Complete entries from `from` forward, oldest first. A structured entry is complete once the next
/// header has been read. Its last entry, and a final line missing its newline, are held back unless
/// `flush_tail` is set, because a running process may still be appending. Reading stops at the first
/// entry boundary past `budget` bytes or once a batch is full.
pub fn read_batch(
    path: &Path,
    structured: bool,
    from: u64,
    start_line: u64,
    budget: u64,
    flush_tail: bool,
    batch_size: usize,
) -> Result<Batch> {
    let mut file = File::open(path)?;
    let size = file.metadata()?.len();
    let mut position = from.min(size);
    file.seek(SeekFrom::Start(position))?;
    let mut reader = BufReader::new(file);
    let mut line = start_line;
    let mut entries: Vec<Entry> = Vec::new();
    let mut entry_bytes = 0;
    let mut pending: Option<Pending> = None;
    let batch_full =
        |entries: &[Entry], bytes: usize| entries.len() >= batch_size || bytes >= MAX_BATCH_BYTES;

    while position < size {
        let Some((text, complete, terminated, consumed)) = read_line(&mut reader, MAX_ENTRY_BYTES)?
        else {
            break;
        };
        if !terminated && !flush_tail {
            break;
        }
        let next = position + consumed;
        let is_header = structured && HEADER.is_match(&text);
        if !structured || is_header {
            if let Some(done) = pending.take() {
                let entry = done.finish(position);
                entry_bytes += entry.first_line.len() + entry.body.len();
                entries.push(entry);
            }
            let full = batch_full(&entries, entry_bytes);
            if full || position - from >= budget {
                return Ok(Batch {
                    entries,
                    offset: position,
                    line,
                    more: full,
                });
            }
        }
        line += 1;
        if !structured {
            if !text.iter().all(u8::is_ascii_whitespace) {
                let entry = make_entry(position, next, &text, b"", !complete, false, line);
                entry_bytes += entry.first_line.len();
                entries.push(entry);
            }
        } else if let Some(open) = pending.as_mut() {
            if MAX_ENTRY_BYTES < open.bytes + text.len() + 1 {
                open.truncated = true;
            } else {
                open.bytes += text.len() + 1;
                open.truncated = open.truncated || !complete;
                open.body.push(text);
            }
        } else {
            pending = Some(Pending {
                offset: position,
                line,
                first: text,
                body: Vec::new(),
                bytes: 0,
                truncated: !complete,
            });
        }
        position = next;
    }
    let Some(open) = pending else {
        return Ok(Batch {
            entries,
            offset: position,
            line,
            more: false,
        });
    };
    if flush_tail && position >= size {
        entries.push(open.finish(position));
        return Ok(Batch {
            entries,
            offset: position,
            line,
            more: false,
        });
    }
    Ok(Batch {
        entries,
        offset: open.offset,
        line: open.line - 1,
        more: false,
    })
}

#[derive(Clone, Debug)]
struct LogFile {
    path: String,
    absolute: PathBuf,
    size: u64,
    modified: SystemTime,
    structured: bool,
    device: u64,
    inode: u64,
}

fn looks_structured(path: &Path) -> bool {
    let Ok(file) = File::open(path) else {
        return false;
    };
    let mut sample = Vec::new();
    if file.take(SNIFF_BYTES).read_to_end(&mut sample).is_err() {
        return false;
    }
    sample.split(|byte| *byte == b'\n').any(|line| {
        let line = line.strip_suffix(b"\r").unwrap_or(line);
        HEADER.is_match(line)
    })
}

fn walk(directory: &Path, root: &Path, files: &mut Vec<LogFile>) -> Result<()> {
    let entries = match fs::read_dir(directory) {
        Ok(entries) => entries,
        Err(failure) if failure.kind() == std::io::ErrorKind::NotFound => return Ok(()),
        Err(failure) => return Err(failure.into()),
    };
    for entry in entries {
        let entry = entry?;
        if entry.file_name().to_string_lossy().starts_with('.') {
            continue;
        }
        // Links are skipped, so nothing outside the log directory can reach the index.
        let kind = entry.file_type()?;
        if kind.is_dir() {
            walk(&entry.path(), root, files)?;
        } else if kind.is_file() {
            let absolute = entry.path();
            let metadata = entry.metadata()?;
            files.push(LogFile {
                path: absolute
                    .strip_prefix(root)
                    .unwrap_or(&absolute)
                    .to_string_lossy()
                    .into_owned(),
                size: metadata.len(),
                modified: metadata.modified()?,
                structured: looks_structured(&absolute),
                device: metadata.dev(),
                inode: metadata.ino(),
                absolute,
            });
        }
    }
    Ok(())
}

/// Newest first.
fn list_files(root: &Path) -> Result<Vec<LogFile>> {
    let mut files = Vec::new();
    walk(root, root, &mut files)?;
    files.sort_by(|left, right| {
        right
            .modified
            .cmp(&left.modified)
            .then_with(|| left.path.cmp(&right.path))
    });
    Ok(files)
}

fn head(path: &Path, length: u64) -> (String, u64) {
    let mut bytes = Vec::new();
    if let Ok(file) = File::open(path) {
        let _ = file.take(length).read_to_end(&mut bytes);
    }
    (hex::encode(Sha1::digest(&bytes)), bytes.len() as u64)
}

#[derive(Clone, Debug, sqlx::FromRow)]
struct Tracked {
    #[sqlx(try_from = "u64")]
    id: i64,
    #[sqlx(try_from = "u64")]
    device: i64,
    #[sqlx(try_from = "u64")]
    inode: i64,
    head_hash: String,
    head_length: u32,
    structured: bool,
    #[sqlx(try_from = "u64")]
    indexed_offset: i64,
    #[sqlx(try_from = "u64")]
    indexed_line: i64,
    indexed_at: Option<NaiveDateTime>,
}

const TRACKED_COLUMNS: &str = "id, device, inode, head_hash, head_length, structured, indexed_offset, indexed_line, indexed_at";

#[derive(Debug, Default, PartialEq, Eq)]
pub struct Stats {
    pub files: u64,
    pub entries: u64,
    pub bytes: u64,
    pub purged: u64,
}

pub struct LogIndexer<'a> {
    pub client: &'a SearchClient,
    pub db: &'a MySqlPool,
    pub table: String,
    pub directory: PathBuf,
    pub batch_size: usize,
    pub max_bytes_per_run: u64,
    pub max_bytes_per_file: u64,
}

impl<'a> LogIndexer<'a> {
    pub fn from_env(client: &'a SearchClient, db: &'a MySqlPool) -> Result<Self> {
        let table = var_or("LOG_VIEWER_INDEX_TABLE", "app_logs_rt");
        if table.is_empty()
            || !table
                .bytes()
                .all(|byte| byte.is_ascii_alphanumeric() || byte == b'_')
        {
            bail!("Unsafe Manticore log index table name.");
        }
        Ok(Self {
            client,
            db,
            table,
            directory: PathBuf::from(var_or("NNTMUX_LOG_DIR", "storage/logs")),
            batch_size: parsed_or("LOG_VIEWER_INDEX_BATCH_SIZE", 1000usize)?.max(1),
            max_bytes_per_run: parsed_or("LOG_VIEWER_INDEX_MAX_BYTES_PER_RUN", 268_435_456u64)?
                .max(1),
            max_bytes_per_file: parsed_or("LOG_VIEWER_INDEX_MAX_BYTES_PER_FILE", 67_108_864u64)?
                .max(1),
        })
    }

    async fn ensure_table(&self) -> Result<()> {
        self.client
            .sql(&format!(
                "CREATE TABLE IF NOT EXISTS {} (file_id bigint, byte_offset bigint, byte_end bigint, line_no bigint, \
                 logged_at bigint, level string, channel string, structured integer, truncated integer, \
                 first_line text, body text) min_infix_len='{MIN_INFIX_LENGTH}' index_field_lengths='0'",
                self.table
            ))
            .await?;
        Ok(())
    }

    async fn purge(&self, file_id: i64) -> Result<()> {
        self.client
            .sql(&format!(
                "DELETE FROM {} WHERE file_id = {file_id}",
                self.table
            ))
            .await?;
        Ok(())
    }

    /// Drops the index and all progress. The next run indexes every file from the start.
    pub async fn reset(&self) -> Result<()> {
        self.client
            .sql(&format!("DROP TABLE IF EXISTS {}", self.table))
            .await?;
        sqlx::query("DELETE FROM log_index_files")
            .execute(self.db)
            .await?;
        Ok(())
    }

    async fn reset_progress(&self, id: i64) -> Result<()> {
        sqlx::query(
            "UPDATE log_index_files SET indexed_offset = 0, indexed_line = 0, indexed_size = 0, indexed_at = NULL, \
             updated_at = NOW() WHERE id = ?",
        )
        .bind(id)
        .execute(self.db)
        .await?;
        Ok(())
    }

    async fn tracked_rows(&self) -> Result<Vec<Tracked>> {
        Ok(sqlx::query_as(sqlx::AssertSqlSafe(format!(
            "SELECT {TRACKED_COLUMNS} FROM log_index_files"
        )))
        .fetch_all(self.db)
        .await?)
    }

    async fn reload(&self, id: i64) -> Result<Tracked> {
        Ok(sqlx::query_as(sqlx::AssertSqlSafe(format!(
            "SELECT {TRACKED_COLUMNS} FROM log_index_files WHERE id = ?"
        )))
        .bind(id)
        .fetch_one(self.db)
        .await?)
    }

    /// Matches files to rows by device and inode, so a rename keeps its progress. A file that
    /// shrank, changed its head or changed format starts over, and a row with no file is purged.
    async fn reconcile(
        &self,
        files: Vec<LogFile>,
        stats: &mut Stats,
    ) -> Result<Vec<(Tracked, LogFile)>> {
        let rows = self.tracked_rows().await?;
        let mut tracked: Vec<(Tracked, LogFile)> = Vec::new();
        for file in files {
            let row = rows
                .iter()
                .find(|row| row.device as u64 == file.device && row.inode as u64 == file.inode);
            if row.is_some_and(|row| tracked.iter().any(|(seen, _)| seen.id == row.id)) {
                continue;
            }
            let (hash, length) = head(&file.absolute, HEAD_BYTES.min(file.size));
            let id = match row {
                None => {
                    sqlx::query(
                        "INSERT INTO log_index_files (path, device, inode, structured, head_hash, head_length, created_at, updated_at) \
                         VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    )
                    .bind(&file.path)
                    .bind(file.device)
                    .bind(file.inode)
                    .bind(file.structured)
                    .bind(&hash)
                    .bind(length)
                    .execute(self.db)
                    .await?
                    .last_insert_id() as i64
                }
                Some(row) => {
                    let matches = file.size >= u64::from(row.head_length)
                        && head(&file.absolute, u64::from(row.head_length)).0 == row.head_hash;
                    let changed = file.size < row.indexed_offset as u64
                        || row.structured != file.structured
                        || !matches;
                    if changed {
                        self.purge(row.id).await?;
                        self.reset_progress(row.id).await?;
                        stats.purged += 1;
                    }
                    let grown = u64::from(row.head_length) < HEAD_BYTES && file.size > u64::from(row.head_length);
                    let (hash, length) = if changed || grown {
                        (hash, length)
                    } else {
                        (row.head_hash.clone(), u64::from(row.head_length))
                    };
                    sqlx::query(
                        "UPDATE log_index_files SET path = ?, structured = ?, head_hash = ?, head_length = ?, updated_at = NOW() WHERE id = ?",
                    )
                    .bind(&file.path)
                    .bind(file.structured)
                    .bind(&hash)
                    .bind(length)
                    .bind(row.id)
                    .execute(self.db)
                    .await?;
                    row.id
                }
            };
            tracked.push((self.reload(id).await?, file));
        }
        for row in &rows {
            if !tracked.iter().any(|(seen, _)| seen.id == row.id) {
                self.purge(row.id).await?;
                sqlx::query("DELETE FROM log_index_files WHERE id = ?")
                    .bind(row.id)
                    .execute(self.db)
                    .await?;
                stats.purged += 1;
            }
        }
        Ok(tracked)
    }

    fn document(file_id: i64, entry: &Entry) -> (i64, Value) {
        let id = (file_id << OFFSET_BITS) | entry.offset as i64;
        (
            id,
            json!({
                "file_id": file_id,
                "byte_offset": entry.offset,
                "byte_end": entry.end,
                "line_no": entry.line_number,
                "logged_at": entry.timestamp.unwrap_or(0),
                "level": entry.level.as_deref().unwrap_or(""),
                "channel": entry.channel.as_deref().unwrap_or(""),
                "structured": i64::from(entry.channel.is_some()),
                "truncated": i64::from(entry.truncated),
                "first_line": entry.first_line,
                "body": entry.body,
            }),
        )
    }

    async fn save_progress(&self, id: i64, offset: u64, line: u64, size: u64) -> Result<()> {
        sqlx::query(
            "UPDATE log_index_files SET indexed_offset = ?, indexed_line = ?, indexed_size = ?, indexed_at = NOW(), \
             updated_at = NOW() WHERE id = ?",
        )
        .bind(offset)
        .bind(line)
        .bind(size)
        .bind(id)
        .execute(self.db)
        .await?;
        Ok(())
    }

    /// Returns entries written and bytes consumed.
    async fn index_file(&self, row: &Tracked, file: &LogFile, budget: u64) -> Result<(u64, u64)> {
        let start = row.indexed_offset as u64;
        if start >= file.size {
            if row.indexed_at.is_none() {
                self.save_progress(row.id, start, row.indexed_line as u64, file.size)
                    .await?;
            }
            return Ok((0, 0));
        }
        let flush_tail = SystemTime::now()
            .duration_since(file.modified)
            .is_ok_and(|age| age > TAIL_FLUSH);
        let (mut offset, mut line, mut written) = (start, row.indexed_line as u64, 0);
        loop {
            let (path, structured, size) =
                (file.absolute.clone(), file.structured, self.batch_size);
            let remaining = budget.saturating_sub(offset - start);
            let batch = tokio::task::spawn_blocking(move || {
                read_batch(&path, structured, offset, line, remaining, flush_tail, size)
            })
            .await??;
            let documents: Vec<(i64, Value)> = batch
                .entries
                .iter()
                .map(|entry| Self::document(row.id, entry))
                .collect();
            self.client
                .replace_documents(&self.table, &documents)
                .await?;
            written += documents.len() as u64;
            (offset, line) = (batch.offset, batch.line);
            self.save_progress(row.id, offset, line, file.size).await?;
            if !batch.more {
                return Ok((written, offset.saturating_sub(start)));
            }
        }
    }

    /// Indexes every file, or only the relative paths in `only`.
    pub async fn run(&self, only: &[String]) -> Result<Stats> {
        self.ensure_table().await?;
        let mut stats = Stats::default();
        let directory = self.directory.clone();
        let files = tokio::task::spawn_blocking(move || list_files(&directory)).await??;
        let mut tracked = self.reconcile(files, &mut stats).await?;

        // The table was dropped or truncated behind our back, such as by a Manticore rebuild.
        let has_progress = tracked.iter().any(|(row, _)| row.indexed_offset > 0);
        if has_progress && self.client.count_index_documents(&self.table).await? == 0 {
            for (row, _) in &mut tracked {
                self.reset_progress(row.id).await?;
                row.indexed_offset = 0;
                row.indexed_line = 0;
                row.indexed_at = None;
            }
        }
        let mut remaining = self.max_bytes_per_run;
        for (row, file) in &tracked {
            if remaining == 0 {
                break;
            }
            if !only.is_empty() && !only.contains(&file.path) {
                continue;
            }
            let budget = self.max_bytes_per_file.min(remaining);
            let (entries, bytes) = self.index_file(row, file, budget).await?;
            remaining = remaining.saturating_sub(bytes);
            stats.entries += entries;
            stats.bytes += bytes;
            stats.files += u64::from(entries > 0);
        }
        Ok(stats)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Write;

    fn log(content: &str) -> (tempfile::TempDir, PathBuf) {
        let dir = tempfile::tempdir().expect("a temp directory");
        let path = dir.path().join("role.log");
        File::create(&path)
            .and_then(|mut file| file.write_all(content.as_bytes()))
            .expect("the log is written");
        (dir, path)
    }

    const TWO: &str = "[2026-11-01T17:00:00+00:00] ingest.ERROR: first failed\nstack line one\nstack line two\n[2026-11-01 17:00:01] ingest.INFO: second\n";

    #[test]
    fn headers_group_continuation_lines_and_the_tail_waits() {
        let (_dir, path) = log(TWO);
        let held = read_batch(&path, true, 0, 0, 1 << 30, false, 1000).unwrap();
        assert_eq!(held.entries.len(), 1);
        let first = &held.entries[0];
        assert_eq!(first.level.as_deref(), Some("error"));
        assert_eq!(first.channel.as_deref(), Some("ingest"));
        assert_eq!(first.body, "stack line one\nstack line two");
        assert_eq!(first.line_number, 1);
        assert_eq!(first.timestamp, Some(1793552400));
        // The held-back entry starts where the next read resumes.
        assert_eq!(held.offset, first.end);
        assert_eq!(held.line, 3);

        let rest = read_batch(&path, true, held.offset, held.line, 1 << 30, true, 1000).unwrap();
        assert_eq!(rest.entries.len(), 1);
        assert_eq!(
            rest.entries[0].first_line,
            "[2026-11-01 17:00:01] ingest.INFO: second"
        );
        assert_eq!(rest.entries[0].line_number, 4);
        assert_eq!(rest.entries[0].timestamp, Some(1793552401));
        assert_eq!(rest.offset, TWO.len() as u64);
    }

    #[test]
    fn a_partial_last_line_waits_until_the_file_goes_quiet() {
        let (_dir, path) =
            log("[2026-11-01T17:00:00Z] a.INFO: done\n[2026-11-01T17:00:01Z] a.INFO: half");
        // The partial line can't be told from a header yet, so the entry before it waits too.
        let held = read_batch(&path, true, 0, 0, 1 << 30, false, 1000).unwrap();
        assert!(held.entries.is_empty(), "{held:?}");
        assert_eq!((held.offset, held.line), (0, 0));
        let flushed = read_batch(&path, true, 0, 0, 1 << 30, true, 1000).unwrap();
        assert_eq!(flushed.entries.len(), 2);
        assert_eq!(
            flushed.entries[1].first_line,
            "[2026-11-01T17:00:01Z] a.INFO: half"
        );
    }

    #[test]
    fn plain_files_are_one_entry_per_line() {
        let (_dir, path) = log("2026-11-01 17:00:00 started\n\nno stamp here\n");
        let batch = read_batch(&path, false, 0, 0, 1 << 30, false, 1000).unwrap();
        assert_eq!(batch.entries.len(), 2);
        assert_eq!(batch.entries[0].timestamp, Some(1793552400));
        assert_eq!(batch.entries[0].channel, None);
        assert_eq!(batch.entries[1].timestamp, None);
        assert_eq!(batch.entries[1].line_number, 3);
    }

    #[test]
    fn batches_stop_on_entry_boundaries_and_resume_exactly() {
        let lines: String = (0..5)
            .map(|index| format!("[2026-11-01T17:00:0{index}Z] a.INFO: {index}\n"))
            .collect();
        let (_dir, path) = log(&lines);
        let mut seen = Vec::new();
        let (mut offset, mut line) = (0, 0);
        loop {
            let batch = read_batch(&path, true, offset, line, 1 << 30, true, 2).unwrap();
            seen.extend(batch.entries.iter().map(|entry| entry.line_number));
            (offset, line) = (batch.offset, batch.line);
            if !batch.more {
                break;
            }
        }
        assert_eq!(seen, [1, 2, 3, 4, 5]);
        assert_eq!(offset, lines.len() as u64);
    }

    #[test]
    fn oversized_entries_are_cut_and_marked() {
        let long = "x".repeat(MAX_ENTRY_BYTES + 10);
        let (_dir, path) = log(&format!("[2026-11-01T17:00:00Z] a.INFO: start\n{long}\n"));
        let batch = read_batch(&path, true, 0, 0, 1 << 30, true, 10).unwrap();
        assert!(batch.entries[0].truncated);
    }

    #[test]
    fn invalid_utf8_is_replaced_rather_than_failing() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("bad.log");
        fs::write(&path, b"[2026-11-01T17:00:00Z] a.INFO: bad \xff byte\n").unwrap();
        let batch = read_batch(&path, true, 0, 0, 1 << 30, true, 10).unwrap();
        assert!(batch.entries[0].first_line.contains("bad"));
        assert_eq!(batch.entries[0].level.as_deref(), Some("info"));
    }
}
