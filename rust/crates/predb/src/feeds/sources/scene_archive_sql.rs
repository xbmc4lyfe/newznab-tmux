//! Manual local reader for the retained nine-column, latin1 2007 PreDB metadata dump. The SQL is
//! parsed as bytes and never executed. Only ASCII canonical titles with matching group fields are
//! eligible; music is included. Eligibility counts don't claim all raw rows or verified PREs. A
//! complete validation pass precedes paging. Memory stays bounded independently of the corpus size
//! (a frame index and 128-bit identity hashes, never the rows), row order is kept without assuming
//! sorted IDs, and source dates, categories, sizes, files and nukes stay unknown.

use std::collections::BTreeMap;
use std::fs::{File, Metadata};
use std::io::{BufRead, BufReader, Read, Seek, SeekFrom};
use std::path::{Path, PathBuf};
use std::sync::LazyLock;

use anyhow::bail;
use async_trait::async_trait;
use regex::bytes::Regex;
use sha2::{Digest, Sha256};

use super::super::{Entry, FeedResult, FeedSource};
use super::literal::fail;

const SCHEMA: &str = "create table `predb` ( `id` int(10) not null auto_increment, `section` varchar(20) not null default '', `rlsname` varchar(255) not null default '', `grp` varchar(50) not null default '', `status` decimal(1,0) not null default '0', `nukereason` varchar(255) default null, `ctime` decimal(10,0) not null default '0', `size` int(8) default null, `files` int(6) default null, primary key (`id`), unique key `rlsname` (`rlsname`), key `grp` (`grp`) ) engine=myisam default charset=latin1;";
const INSERT_PREFIX: &[u8] = b"INSERT INTO `predb` VALUES ";
/// One packed frame record in PHP's index: kind, offset, length, eligible count, SHA-256.
const FRAME_BYTES: usize = 45;
const BUCKETS: usize = 64;

#[derive(Clone, Copy, Debug)]
pub struct Limits {
    pub max_file_bytes: u64,
    pub max_statement_bytes: usize,
    pub max_scratch_bytes: usize,
    pub max_bucket_bytes: usize,
    pub max_frame_index_bytes: usize,
}

impl Default for Limits {
    fn default() -> Self {
        Self {
            max_file_bytes: 536_870_912,
            max_statement_bytes: 4_194_304,
            max_scratch_bytes: 268_435_456,
            max_bucket_bytes: 8_388_608,
            max_frame_index_bytes: 4_194_304,
        }
    }
}

impl Limits {
    fn within_bounds(&self) -> bool {
        let defaults = Self::default();
        (1..=defaults.max_file_bytes).contains(&self.max_file_bytes)
            && (1..=defaults.max_statement_bytes).contains(&self.max_statement_bytes)
            && (1..=defaults.max_scratch_bytes).contains(&self.max_scratch_bytes)
            && (32..=defaults.max_bucket_bytes).contains(&self.max_bucket_bytes)
            && (FRAME_BYTES..=defaults.max_frame_index_bytes).contains(&self.max_frame_index_bytes)
    }
}

type Row = Vec<Option<Vec<u8>>>;

/// What stats the file: replaced or edited between validation and a page read means the
/// validated frames can't be trusted.
#[derive(Clone, Debug, PartialEq, Eq)]
struct Fingerprint {
    device: u64,
    inode: u64,
    size: u64,
    modified: (i64, i64),
    changed: (i64, i64),
}

#[cfg(unix)]
fn fingerprint(metadata: &Metadata) -> Fingerprint {
    use std::os::unix::fs::MetadataExt;
    Fingerprint {
        device: metadata.dev(),
        inode: metadata.ino(),
        size: metadata.size(),
        modified: (metadata.mtime(), metadata.mtime_nsec()),
        changed: (metadata.ctime(), metadata.ctime_nsec()),
    }
}

#[cfg(not(unix))]
fn fingerprint(metadata: &Metadata) -> Fingerprint {
    let modified = metadata
        .modified()
        .ok()
        .and_then(|time| time.duration_since(std::time::UNIX_EPOCH).ok())
        .map_or((0, 0), |d| {
            (d.as_secs() as i64, i64::from(d.subsec_nanos()))
        });
    Fingerprint {
        device: 0,
        inode: 0,
        size: metadata.len(),
        modified,
        changed: modified,
    }
}

/// A statement's position and SHA-256, so a page read can prove the bytes are what was validated.
#[derive(Clone, Copy, Debug)]
struct Frame {
    offset: u64,
    length: u64,
    hash: [u8; 32],
}

/// Reads `;`-terminated statements, honouring `'` quoting with backslash escapes, and returns each
/// with its file offset. Whole-line `-- ` and `#` comments between statements are skipped.
struct Statements {
    reader: BufReader<File>,
    /// File offset the next physical read starts at.
    position: u64,
    /// The unread rest of a line that held a statement end.
    carry: Option<(u64, Vec<u8>)>,
    size: u64,
    limit: usize,
}

impl Statements {
    /// `fgets($handle, $limit + 2)`: up to `limit + 1` bytes, through the first newline.
    fn line(&mut self) -> std::io::Result<Option<(u64, Vec<u8>)>> {
        if let Some(carried) = self.carry.take() {
            return Ok(Some(carried));
        }
        let start = self.position;
        let mut line = Vec::new();
        let wanted = self.limit + 1;
        while line.len() < wanted {
            let available = self.reader.fill_buf()?;
            if available.is_empty() {
                break;
            }
            let room = wanted - line.len();
            let window = &available[..available.len().min(room)];
            let (take, done) = match window.iter().position(|b| *b == b'\n') {
                Some(newline) => (newline + 1, true),
                None => (window.len(), false),
            };
            line.extend_from_slice(&window[..take]);
            self.reader.consume(take);
            if done {
                break;
            }
        }
        self.position += line.len() as u64;
        Ok((!line.is_empty()).then_some((start, line)))
    }

    fn next_statement(&mut self) -> FeedResult<Option<(u64, Vec<u8>)>> {
        static COMMENT: LazyLock<Regex> =
            LazyLock::new(|| Regex::new(r"(?-u)^\s*(?:-- |#)").expect("pattern"));
        let mut buffer: Vec<u8> = Vec::new();
        let mut quoted = false;
        let mut escaped = false;
        let mut offset = 0;
        while let Some((start, line)) = self.line()? {
            if start + line.len() as u64 > self.size {
                return fail("SCENE_ARCHIVE_SQL input grew beyond its validated file bound.");
            }
            if buffer.is_empty() && (php_trim_bytes(&line).is_empty() || COMMENT.is_match(&line)) {
                continue;
            }
            if buffer.is_empty() {
                offset = start;
            }
            if buffer.len() + line.len() > self.limit || line.contains(&0) {
                return fail(
                    "SCENE_ARCHIVE_SQL statement exceeds its bounds or contains binary bytes.",
                );
            }
            for (index, byte) in line.iter().enumerate() {
                if escaped {
                    escaped = false;
                    continue;
                }
                if quoted && *byte == b'\\' {
                    escaped = true;
                } else if *byte == b'\'' {
                    quoted = !quoted;
                } else if !quoted && *byte == b';' {
                    buffer.extend_from_slice(&line[..=index]);
                    let rest = line[index + 1..].to_vec();
                    if !rest.is_empty() {
                        self.carry = Some((start + index as u64 + 1, rest));
                    }
                    return Ok(Some((offset, buffer)));
                }
            }
            buffer.extend_from_slice(&line);
        }
        if !php_trim_bytes(&buffer).is_empty() || quoted || escaped {
            return fail("SCENE_ARCHIVE_SQL contains a truncated statement.");
        }
        Ok(None)
    }
}

fn php_trim_bytes(bytes: &[u8]) -> &[u8] {
    let is_trim = |b: &u8| matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b);
    let start = bytes
        .iter()
        .position(|b| !is_trim(b))
        .unwrap_or(bytes.len());
    let end = bytes
        .iter()
        .rposition(|b| !is_trim(b))
        .map_or(start, |i| i + 1);
    &bytes[start..end]
}

fn escape_byte(escape: u8) -> Option<u8> {
    Some(match escape {
        b'0' => 0,
        b'b' => 8,
        b'n' => b'\n',
        b'r' => b'\r',
        b't' => b'\t',
        b'Z' => 0x1a,
        b'\'' | b'"' | b'\\' => escape,
        _ => return None,
    })
}

fn is_signed_integer(value: &[u8]) -> bool {
    let digits = value.strip_prefix(b"-").unwrap_or(value);
    !digits.is_empty() && digits.iter().all(u8::is_ascii_digit)
}

fn scalar(statement: &[u8], position: &mut usize) -> FeedResult<Option<Vec<u8>>> {
    if statement.get(*position) == Some(&b'\'') {
        *position += 1;
        let mut value = Vec::new();
        while *position < statement.len() {
            let byte = statement[*position];
            *position += 1;
            if byte == b'\'' {
                if statement.get(*position) == Some(&b'\'') {
                    value.push(b'\'');
                    *position += 1;
                    continue;
                }
                return Ok(Some(value));
            }
            if byte == b'\\' {
                let Some(&escape) = statement.get(*position) else {
                    return fail("SCENE_ARCHIVE_SQL contains a truncated string escape.");
                };
                *position += 1;
                let Some(replacement) = escape_byte(escape) else {
                    return fail("SCENE_ARCHIVE_SQL contains an unsupported string escape.");
                };
                value.push(replacement);
                continue;
            }
            value.push(byte);
        }
        return fail("SCENE_ARCHIVE_SQL contains an unterminated string.");
    }
    let rest = &statement[(*position).min(statement.len())..];
    let end = rest
        .iter()
        .position(|b| matches!(b, b',' | b')'))
        .unwrap_or(rest.len());
    let value = &rest[..end];
    *position += end;
    if value == b"NULL" {
        return Ok(None);
    }
    if !is_signed_integer(value) {
        return fail("SCENE_ARCHIVE_SQL contains an unsupported scalar expression.");
    }
    Ok(Some(value.to_vec()))
}

fn validate_row(row: &Row) -> FeedResult<()> {
    for (column, maximum) in [(1, 20), (2, 255), (3, 50), (5, 255)] {
        let value = &row[column];
        if (value.is_none() && column != 5) || value.as_ref().is_some_and(|v| v.len() > maximum) {
            return fail("SCENE_ARCHIVE_SQL string columns conflict with the declared schema.");
        }
    }
    for column in [0, 4, 6, 7, 8] {
        let value = &row[column];
        if value.is_none() && matches!(column, 7 | 8) {
            continue;
        }
        if !value
            .as_ref()
            .is_some_and(|v| is_signed_integer(v) && v.len() <= 11)
        {
            return fail("SCENE_ARCHIVE_SQL numeric columns conflict with the declared schema.");
        }
    }
    let id = row[0].as_deref().unwrap_or(b"");
    let canonical = id.first().is_some_and(|b| (b'1'..=b'9').contains(b))
        && id.iter().all(u8::is_ascii_digit)
        && std::str::from_utf8(id)
            .ok()
            .and_then(|text| text.parse::<u64>().ok())
            .is_some_and(|id| id <= 2_147_483_647);
    if !canonical {
        return fail("SCENE_ARCHIVE_SQL contains an invalid catalogue ID.");
    }
    Ok(())
}

/// The nine-column tuples of one `INSERT` statement, in order.
fn rows(statement: &[u8]) -> FeedResult<Vec<Row>> {
    if !statement.starts_with(INSERT_PREFIX) {
        return fail("SCENE_ARCHIVE_SQL returned an invalid INSERT frame.");
    }
    let mut position = INSERT_PREFIX.len();
    let mut found = Vec::new();
    loop {
        if statement.get(position) != Some(&b'(') {
            return fail("SCENE_ARCHIVE_SQL contains an invalid tuple boundary.");
        }
        position += 1;
        let mut row: Row = Vec::new();
        let delimiter = loop {
            row.push(scalar(statement, &mut position)?);
            let delimiter = statement.get(position).copied();
            position += 1;
            if delimiter != Some(b',') {
                break delimiter;
            }
        };
        if delimiter != Some(b')') || row.len() != 9 {
            return fail("SCENE_ARCHIVE_SQL contains an incomplete nine-column tuple.");
        }
        validate_row(&row)?;
        found.push(row);
        if statement.get(position) == Some(&b',') {
            position += 1;
            continue;
        }
        if statement.get(position) != Some(&b';') || position + 1 != statement.len() {
            return fail("SCENE_ARCHIVE_SQL contains unsupported trailing tuple data.");
        }
        return Ok(found);
    }
}

fn eligibility_reason(row: &Row) -> Option<&'static str> {
    static CANONICAL: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(r"(?-u)^[A-Za-z0-9_.()&'+,-]+-[A-Za-z0-9_]+$").expect("pattern")
    });
    let title = row[2].as_deref().unwrap_or(b"");
    if !CANONICAL.is_match(title) {
        return Some("noncanonical_title");
    }
    let group = row[3].as_deref().unwrap_or(b"");
    let mut suffix = vec![b'-'];
    suffix.extend_from_slice(&group.to_ascii_lowercase());
    if group.is_empty() || !title.to_ascii_lowercase().ends_with(&suffix) {
        return Some("group_conflict");
    }
    None
}

fn sha256(bytes: &[u8]) -> [u8; 32] {
    Sha256::digest(bytes).into()
}

#[derive(Debug, PartialEq, Eq)]
enum Stage {
    Schema,
    DisableKeys,
    Lock,
    Rows,
    Unlock,
    EnableKeys,
}

/// The validated dump: where each statement is and how many rows were eligible.
struct Prepared {
    file: File,
    path: PathBuf,
    fingerprint: Fingerprint,
    /// Every statement that isn't an `INSERT`, re-hashed on every page read.
    protected: Vec<Frame>,
    inserts: Vec<Frame>,
    accepted: usize,
    rejections: BTreeMap<&'static str, usize>,
}

impl Prepared {
    fn open(path: &Path, limits: Limits) -> FeedResult<Self> {
        let text = path.to_string_lossy();
        let remote = text.split_once("://").is_some_and(|(scheme, _)| {
            scheme
                .chars()
                .next()
                .is_some_and(|c| c.is_ascii_alphabetic())
                && scheme
                    .chars()
                    .all(|c| c.is_ascii_alphanumeric() || matches!(c, '+' | '.' | '-'))
        });
        if text.is_empty() || remote || !path.is_file() {
            return fail("SCENE_ARCHIVE_SQL requires a local regular SQL metadata file.");
        }
        let Ok(file) = File::open(path) else {
            return fail("SCENE_ARCHIVE_SQL cannot open the local metadata file.");
        };
        let metadata = file.metadata()?;
        if !metadata.is_file() || metadata.len() < 1 || metadata.len() > limits.max_file_bytes {
            return fail("SCENE_ARCHIVE_SQL input is not a bounded regular file.");
        }
        let fingerprint = fingerprint(&metadata);
        let mut prepared = Self {
            file: file.try_clone()?,
            path: path.to_owned(),
            fingerprint,
            protected: Vec::new(),
            inserts: Vec::new(),
            accepted: 0,
            rejections: BTreeMap::new(),
        };
        prepared.validate(file, limits)?;
        prepared.assert_unchanged()?;
        Ok(prepared)
    }

    /// One pass over the whole file: the statement sequence must be exactly the dump's, every row
    /// must parse, and no ID or case-folded title may repeat.
    fn validate(&mut self, file: File, limits: Limits) -> FeedResult<()> {
        static WHITESPACE: LazyLock<Regex> =
            LazyLock::new(|| Regex::new(r"(?-u)\s+").expect("pattern"));
        let mut statements = Statements {
            reader: BufReader::new(file),
            position: 0,
            carry: None,
            size: self.fingerprint.size,
            limit: limits.max_statement_bytes,
        };
        let mut stage = Stage::Schema;
        let mut has_rows = false;
        let mut accepted = 0;
        let mut rejected: BTreeMap<&'static str, usize> = BTreeMap::new();
        let mut identities: Vec<[u8; 16]> = Vec::new();
        let mut bucket_bytes = [0usize; BUCKETS];
        let mut scratch_bytes = 0usize;
        let mut frame_bytes = 0usize;
        while let Some((offset, statement)) = statements.next_statement()? {
            let normal = WHITESPACE
                .replace_all(php_trim_bytes(&statement), &b" "[..])
                .to_ascii_lowercase();
            let mut insert = false;
            let next = match &stage {
                Stage::Schema if normal == SCHEMA.as_bytes() => Some(Stage::DisableKeys),
                Stage::DisableKeys
                    if normal == b"/*!40000 alter table `predb` disable keys */;" =>
                {
                    Some(Stage::Lock)
                }
                Stage::Lock if normal == b"lock tables `predb` write;" => Some(Stage::Rows),
                Stage::Rows if statement.starts_with(INSERT_PREFIX) => {
                    insert = true;
                    for row in rows(&statement)? {
                        has_rows = true;
                        let id = row[0].as_deref().unwrap_or(b"");
                        let title = row[2].as_deref().unwrap_or(b"").to_ascii_lowercase();
                        for identity in [[b"id\0", id].concat(), [b"title\0", &title[..]].concat()]
                        {
                            let hash = sha256(&identity);
                            let bucket = usize::from(hash[0]) & (BUCKETS - 1);
                            bucket_bytes[bucket] += 32;
                            scratch_bytes += 32;
                            if bucket_bytes[bucket] > limits.max_bucket_bytes
                                || scratch_bytes > limits.max_scratch_bytes
                            {
                                return fail(
                                    "SCENE_ARCHIVE_SQL duplicate scratch exceeds its bounds or could not be written.",
                                );
                            }
                            // The first 128 bits identify the row; a clash is as unlikely as a
                            // clash in the full hash.
                            let mut short = [0u8; 16];
                            short.copy_from_slice(&hash[..16]);
                            identities.push(short);
                        }
                        match eligibility_reason(&row) {
                            Some(reason) => *rejected.entry(reason).or_default() += 1,
                            None => accepted += 1,
                        }
                    }
                    None
                }
                Stage::Rows if normal == b"unlock tables;" => Some(Stage::Unlock),
                Stage::Unlock if normal == b"/*!40000 alter table `predb` enable keys */;" => {
                    Some(Stage::EnableKeys)
                }
                _ => {
                    return fail(
                        "SCENE_ARCHIVE_SQL contains unsupported schema, encoding, commands or framing.",
                    );
                }
            };
            if let Some(next) = next {
                stage = next;
            }
            let frame = Frame {
                offset,
                length: statement.len() as u64,
                hash: sha256(&statement),
            };
            if insert {
                scratch_bytes += FRAME_BYTES;
                frame_bytes += FRAME_BYTES;
                if scratch_bytes > limits.max_scratch_bytes
                    || frame_bytes > limits.max_frame_index_bytes
                {
                    return fail(
                        "SCENE_ARCHIVE_SQL frame index exceeds its bounds or could not be written.",
                    );
                }
                self.inserts.push(frame);
            } else {
                self.protected.push(frame);
            }
        }
        if !matches!(stage, Stage::Unlock | Stage::EnableKeys) || !has_rows {
            return fail("SCENE_ARCHIVE_SQL ended before a complete supported dump.");
        }
        identities.sort_unstable();
        if identities.windows(2).any(|pair| pair[0] == pair[1]) {
            return fail(
                "SCENE_ARCHIVE_SQL contains duplicate IDs or case-folded title identities.",
            );
        }
        self.accepted = accepted;
        self.rejections = rejected;
        Ok(())
    }

    fn assert_unchanged(&self) -> FeedResult<()> {
        let on_disk = std::fs::metadata(&self.path).map(|m| fingerprint(&m));
        let held = self.file.metadata().map(|m| fingerprint(&m));
        if on_disk.ok().as_ref() != Some(&self.fingerprint)
            || held.ok().as_ref() != Some(&self.fingerprint)
        {
            return fail("SCENE_ARCHIVE_SQL input was replaced or modified.");
        }
        Ok(())
    }

    /// A statement's bytes, proven to be the ones that were validated.
    fn read_frame(&mut self, frame: &Frame) -> FeedResult<Vec<u8>> {
        if self.file.seek(SeekFrom::Start(frame.offset)).is_err() {
            return fail("SCENE_ARCHIVE_SQL cannot seek its validated input.");
        }
        let mut bytes = Vec::new();
        (&mut self.file)
            .take(frame.length)
            .read_to_end(&mut bytes)?;
        if bytes.len() as u64 != frame.length || sha256(&bytes) != frame.hash {
            return fail("SCENE_ARCHIVE_SQL input changed after complete validation.");
        }
        Ok(bytes)
    }
}

/// Where a run of page reads has got to.
#[derive(Default)]
struct Cursor {
    page: usize,
    last_page: Vec<Vec<u8>>,
    pending: Vec<Vec<u8>>,
    pending_offset: usize,
    next_frame: usize,
    active: Option<Frame>,
}

pub struct SceneArchiveSqlSource {
    path: PathBuf,
    page_size: usize,
    limits: Limits,
    prepared: Option<Prepared>,
    cursor: Cursor,
    accepted: usize,
    rejections: BTreeMap<&'static str, usize>,
}

impl SceneArchiveSqlSource {
    pub fn new(path: impl Into<PathBuf>, page_size: usize) -> anyhow::Result<Self> {
        Self::with_limits(path, page_size, Limits::default())
    }

    pub fn with_limits(
        path: impl Into<PathBuf>,
        page_size: usize,
        limits: Limits,
    ) -> anyhow::Result<Self> {
        if !(1..=10_000).contains(&page_size) || !limits.within_bounds() {
            bail!(
                "SCENE_ARCHIVE_SQL limits must be positive and within the offline reader bounds."
            );
        }
        Ok(Self {
            path: path.into(),
            page_size,
            limits,
            prepared: None,
            cursor: Cursor::default(),
            accepted: 0,
            rejections: BTreeMap::new(),
        })
    }

    /// Records left out of the last validated dump, by reason.
    pub fn rejection_counts(&self) -> &BTreeMap<&'static str, usize> {
        &self.rejections
    }

    async fn prepare(&mut self) -> FeedResult<()> {
        if self.prepared.is_some() {
            return Ok(());
        }
        let path = self.path.clone();
        let limits = self.limits;
        let prepared = tokio::task::spawn_blocking(move || Prepared::open(&path, limits))
            .await
            .map_err(|error| super::super::FeedError::Failed(error.into()))??;
        self.accepted = prepared.accepted;
        self.rejections = prepared.rejections.clone();
        self.prepared = Some(prepared);
        self.cursor = Cursor::default();
        Ok(())
    }

    fn reset(&mut self) {
        self.prepared = None;
        self.cursor = Cursor::default();
        self.accepted = 0;
        self.rejections.clear();
    }

    fn page(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        let page = page as usize;
        let page_size = self.page_size;
        let Some(prepared) = self.prepared.as_mut() else {
            return Ok(Vec::new());
        };
        prepared.assert_unchanged()?;
        for frame in prepared.protected.clone() {
            prepared.read_frame(&frame)?;
        }
        if let Some(active) = self.cursor.active {
            prepared.read_frame(&active)?;
        }
        if page > prepared.accepted.div_ceil(page_size) {
            return Ok(Vec::new());
        }
        if page == self.cursor.page {
            return Ok(entries(&self.cursor.last_page));
        }
        if page < self.cursor.page {
            self.cursor = Cursor::default();
        }
        while self.cursor.page < page {
            let mut titles = Vec::new();
            while titles.len() < page_size {
                if self.cursor.pending_offset < self.cursor.pending.len() {
                    titles.push(self.cursor.pending[self.cursor.pending_offset].clone());
                    self.cursor.pending_offset += 1;
                    continue;
                }
                let Some(frame) = prepared.inserts.get(self.cursor.next_frame).copied() else {
                    break;
                };
                self.cursor.next_frame += 1;
                let statement = prepared.read_frame(&frame)?;
                self.cursor.active = Some(frame);
                self.cursor.pending_offset = 0;
                self.cursor.pending = rows(&statement)?
                    .into_iter()
                    .filter(|row| eligibility_reason(row).is_none())
                    .map(|row| row[2].clone().unwrap_or_default())
                    .collect();
            }
            self.cursor.last_page = titles;
            self.cursor.page += 1;
        }
        Ok(entries(&self.cursor.last_page))
    }
}

fn entries(titles: &[Vec<u8>]) -> Vec<Entry> {
    titles
        .iter()
        .map(|title| Entry {
            title: String::from_utf8_lossy(title).into_owned(),
            source: "scene-archive-sql".to_owned(),
            ..Entry::default()
        })
        .collect()
}

/// Validates a whole dump and returns every eligible title in file order, with the count of
/// records left out by reason.
pub fn parse_file(
    path: &Path,
    limits: Limits,
) -> FeedResult<(Vec<String>, BTreeMap<&'static str, usize>)> {
    let mut prepared = Prepared::open(path, limits)?;
    let mut titles = Vec::new();
    for frame in prepared.inserts.clone() {
        for row in rows(&prepared.read_frame(&frame)?)? {
            if eligibility_reason(&row).is_none() {
                titles.push(String::from_utf8_lossy(row[2].as_deref().unwrap_or(b"")).into_owned());
            }
        }
    }
    Ok((titles, prepared.rejections))
}

#[async_trait]
impl FeedSource for SceneArchiveSqlSource {
    fn key(&self) -> &str {
        "scene_archive_sql"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        if page < 1 {
            return fail("SCENE_ARCHIVE_SQL local pages start at one.");
        }
        let outcome = match self.prepare().await {
            Ok(()) => self.page(page),
            Err(error) => Err(error),
        };
        if outcome.is_err() {
            self.reset();
        }
        outcome
    }

    fn accepted_count(&self) -> Option<usize> {
        Some(self.accepted)
    }
}
