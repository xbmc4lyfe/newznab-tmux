//! Manual historical nZEDbPre_Dumps catalogue: one retained gzip export, locally paged. The
//! producer's legacy schema has twelve MySQL OUTFILE fields, double-tab separators, single-quote
//! enclosures, backslash escapes and CRLF record endings. Record creation clocks and upstream
//! provider labels do not establish scene PRE times, so only titles and categories are kept.

use std::collections::{BTreeMap, HashSet};
use std::io::Read;

use async_trait::async_trait;

use super::super::http::FeedHttp;
use super::super::{Entry, FeedResult, FeedSource};
use super::literal::{char_length, fail, has_control_character, php_trim};
use super::xml_catalogue::local_page;

pub const DEFAULT_MAXIMUM_BODY_BYTES: usize = 1024 * 1024;
pub const DEFAULT_MAXIMUM_CSV_BYTES: usize = 8 * 1024 * 1024;
pub const DEFAULT_MAXIMUM_RECORDS: usize = 5000;
const KEY: &str = "NZEDB_ARCHIVE";

#[derive(Clone, Copy, Debug)]
pub struct Limits {
    pub maximum_body_bytes: usize,
    pub maximum_csv_bytes: usize,
    pub maximum_records: usize,
}

impl Default for Limits {
    fn default() -> Self {
        Self {
            maximum_body_bytes: DEFAULT_MAXIMUM_BODY_BYTES,
            maximum_csv_bytes: DEFAULT_MAXIMUM_CSV_BYTES,
            maximum_records: DEFAULT_MAXIMUM_RECORDS,
        }
    }
}

#[derive(Debug, Default)]
pub struct ArchiveCatalogue {
    pub entries: Vec<Entry>,
    pub rejections: BTreeMap<&'static str, usize>,
}

/// Decodes the export: exactly one complete gzip member, with no bytes after it.
pub fn decode_archive(gzip: &[u8], limits: Limits) -> FeedResult<Vec<u8>> {
    if gzip.len() > limits.maximum_body_bytes {
        return fail(format!("{KEY} response exceeds the body limit."));
    }
    if !gzip.starts_with(&[0x1f, 0x8b]) {
        return fail(format!("{KEY} returned an invalid gzip export."));
    }
    let mut decoder = flate2::bufread::GzDecoder::new(gzip);
    let mut csv = Vec::new();
    let read = (&mut decoder)
        .take(limits.maximum_csv_bytes as u64 + 1)
        .read_to_end(&mut csv);
    if let Err(error) = read {
        return if error.kind() == std::io::ErrorKind::UnexpectedEof {
            fail(format!("{KEY} gzip export is truncated."))
        } else {
            fail(format!("{KEY} gzip export failed integrity validation."))
        };
    }
    if csv.len() > limits.maximum_csv_bytes {
        return fail(format!("{KEY} decoded CSV exceeds its byte limit."));
    }
    if !decoder.into_inner().is_empty() {
        return fail(format!(
            "{KEY} requires one gzip member without trailing bytes."
        ));
    }
    Ok(csv)
}

pub fn parse_gzip(gzip: &[u8], limits: Limits) -> FeedResult<ArchiveCatalogue> {
    let csv = decode_archive(gzip, limits)?;
    parse_csv(&csv, limits)
}

/// One MySQL OUTFILE field: `\N` is NULL, anything else is a single-quoted, backslash-escaped
/// string. A bare `'` always ends it.
fn read_field(csv: &[u8], offset: &mut usize) -> FeedResult<Option<Vec<u8>>> {
    if csv[*offset..].starts_with(b"\\N") {
        *offset += 2;
        return Ok(None);
    }
    if csv.get(*offset) != Some(&b'\'') {
        return fail(format!("{KEY} returned an invalid MySQL OUTFILE field."));
    }
    *offset += 1;
    let mut value = Vec::new();
    while *offset < csv.len() {
        let byte = csv[*offset];
        *offset += 1;
        match byte {
            b'\'' => return Ok(Some(value)),
            b'\\' => {
                let Some(&escaped) = csv.get(*offset) else {
                    return fail(format!("{KEY} returned a truncated field escape."));
                };
                *offset += 1;
                value.push(match escaped {
                    b'0' => 0,
                    b'b' => 8,
                    b'n' => b'\n',
                    b'r' => b'\r',
                    b't' => b'\t',
                    b'Z' => 0x1a,
                    b'\'' | b'"' | b'\\' => escaped,
                    _ => {
                        return fail(format!(
                            "{KEY} returned an invalid or truncated field escape."
                        ));
                    }
                });
            }
            _ => value.push(byte),
        }
    }
    fail(format!("{KEY} returned an unterminated CSV field."))
}

fn title_rejection(title: Option<&str>) -> Option<&'static str> {
    let Some(title) = title.filter(|title| !php_trim(title).is_empty()) else {
        return Some("empty_title");
    };
    if title != php_trim(title) {
        return Some("outer_title_whitespace");
    }
    if char_length(title) > 255 {
        return Some("overlength_title");
    }
    if has_control_character(title) {
        return Some("control_title");
    }
    None
}

pub fn parse_csv(csv: &[u8], limits: Limits) -> FeedResult<ArchiveCatalogue> {
    if csv.is_empty()
        || csv.len() > limits.maximum_csv_bytes
        || std::str::from_utf8(csv).is_err()
        || csv.contains(&0)
    {
        return fail(format!(
            "{KEY} returned an invalid or oversized CSV export."
        ));
    }
    let mut offset = 0;
    let mut records = 0;
    let mut entries = Vec::new();
    let mut identities = HashSet::new();
    let mut rejections: BTreeMap<&'static str, usize> = BTreeMap::new();
    while offset < csv.len() {
        records += 1;
        if records > limits.maximum_records {
            return fail(format!("{KEY} export exceeds its record limit."));
        }
        let mut fields = Vec::with_capacity(12);
        for column in 0..12 {
            fields.push(read_field(csv, &mut offset)?);
            let separator: &[u8] = if column == 11 { b"\r\n" } else { b"\t\t" };
            if !csv[offset..].starts_with(separator) {
                return fail(format!(
                    "{KEY} returned a truncated or non-twelve-field CSV record."
                ));
            }
            offset += 2;
        }
        // The escapes only ever produce ASCII, so a field of valid UTF-8 stays valid.
        let field = |index: usize| {
            fields[index]
                .as_deref()
                .map(|bytes| String::from_utf8_lossy(bytes).into_owned())
        };
        let title = field(0);
        if let Some(reason) = title_rejection(title.as_deref()) {
            *rejections.entry(reason).or_default() += 1;
            continue;
        }
        let title = title.unwrap_or_default();
        if !identities.insert(title.to_lowercase()) {
            *rejections.entry("duplicate_title").or_default() += 1;
            continue;
        }
        let category = field(7).filter(|category| {
            !category.is_empty() && char_length(category) <= 255 && !has_control_character(category)
        });
        entries.push(Entry {
            title,
            source: "nzedb-pre-dumps".to_owned(),
            category,
            ..Entry::default()
        });
    }
    if !rejections.is_empty() {
        tracing::warn!(
            total_records = records,
            accepted_records = entries.len(),
            rejected_records = rejections.values().sum::<usize>(),
            reasons = ?rejections,
            "NZEDB_ARCHIVE omitted invalid or duplicate literal titles."
        );
    }
    Ok(ArchiveCatalogue {
        entries,
        rejections,
    })
}

pub struct NzedbArchiveSource {
    endpoint: String,
    page_size: usize,
    limits: Limits,
    http: FeedHttp,
    entries: Option<Vec<Entry>>,
    accepted: usize,
    rejections: BTreeMap<&'static str, usize>,
}

impl NzedbArchiveSource {
    pub fn new(endpoint: String, page_size: usize, http: FeedHttp) -> Self {
        Self {
            endpoint,
            page_size: page_size.max(1),
            limits: Limits::default(),
            http,
            entries: None,
            accepted: 0,
            rejections: BTreeMap::new(),
        }
    }

    pub fn with_limits(mut self, limits: Limits) -> Self {
        self.limits = limits;
        self
    }

    pub fn rejection_counts(&self) -> &BTreeMap<&'static str, usize> {
        &self.rejections
    }

    pub fn parse_gzip(&mut self, gzip: &[u8]) -> FeedResult<Vec<Entry>> {
        self.entries = None;
        self.accepted = 0;
        self.rejections.clear();
        let catalogue = parse_gzip(gzip, self.limits)?;
        self.accepted = catalogue.entries.len();
        self.rejections = catalogue.rejections;
        Ok(catalogue.entries)
    }
}

#[async_trait]
impl FeedSource for NzedbArchiveSource {
    fn key(&self) -> &str {
        "nzedb_archive"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        if page < 1 {
            return fail("NZEDB_ARCHIVE local pages start at one.");
        }
        if self.entries.is_none() {
            let body = self
                .http
                .download_body(
                    &self.endpoint,
                    "nzedb_archive",
                    self.limits.maximum_body_bytes,
                    2,
                )
                .await?;
            let entries = self.parse_gzip(&body)?;
            self.entries = Some(entries);
        }
        let entries = self.entries.as_deref().unwrap_or_default();
        Ok(local_page(entries, page, self.page_size))
    }

    fn accepted_count(&self) -> Option<usize> {
        Some(self.accepted)
    }
}
