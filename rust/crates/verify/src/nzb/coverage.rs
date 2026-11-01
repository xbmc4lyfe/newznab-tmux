//! Proves that an existing release's NZB holds every article of an incoming one, by exact ID bytes.
//! Stored fingerprints and metadata only nominate uploads; bounds limit comparison work, never
//! whether an incoming NZB may be imported.

use std::collections::HashSet;

use anyhow::Result;
use sqlx::MySqlConnection;

use super::fingerprint::{ArticleFingerprint, validated_message_id};
use super::parse::{ParsedNzb, parse};
use super::store;

pub const CANDIDATES: usize = 128;
const COMPRESSED_BYTES: usize = 4_194_304;
const DECODED_BYTES: usize = 16_777_216;
const TOTAL_BYTES: i64 = 33_554_432;
const IDS: usize = 100_000;

/// A document parsed for comparison, or `None` when it's oversized or isn't XML this reads.
pub fn parse_document(contents: &[u8]) -> Option<ParsedNzb> {
    if contents.len() > DECODED_BYTES {
        return None;
    }
    parse(contents).ok()
}

/// The exact article IDs a document requires, or `None` when it isn't a well-formed NZB inventory.
pub fn coverage_ids(document: &ParsedNzb) -> Option<HashSet<String>> {
    if document.files.is_empty() || document.file_elements != document.files.len() {
        return None;
    }
    let mut ids = HashSet::new();
    let mut segments = 0;
    for file in &document.files {
        if file.segments_containers != 1
            || file.segments.is_empty()
            || file.segments_children != file.segments.len()
        {
            return None;
        }
        for segment in &file.segments {
            let number = segment.number.as_bytes();
            if number
                .first()
                .is_none_or(|first| !(b'1'..=b'9').contains(first))
                || !number.iter().all(u8::is_ascii_digit)
                || segment.bytes.is_empty()
                || !segment.bytes.bytes().all(|byte| byte.is_ascii_digit())
                || segment.child_elements != 0
            {
                return None;
            }
            let id = validated_message_id(&segment.text)?;
            segments += 1;
            if segments > IDS {
                return None;
            }
            ids.insert(id.to_owned());
        }
    }
    Some(ids)
}

/// A document's article fingerprint; `None` when an ID is unsupported.
pub fn document_fingerprint(document: &ParsedNzb) -> Option<String> {
    let mut fingerprint = ArticleFingerprint::new();
    for file in &document.files {
        for segment in &file.segments {
            fingerprint.add(&segment.text);
        }
    }
    fingerprint.value()
}

/// The shared allowance for one duplicate search. A failed or over-limit read uses up its whole
/// decoded allowance, so repeated corrupt candidates can't get past the budget.
#[derive(Debug)]
pub struct CoverageBudget {
    remaining: i64,
}

impl Default for CoverageBudget {
    fn default() -> Self {
        Self {
            remaining: TOTAL_BYTES,
        }
    }
}

impl CoverageBudget {
    pub fn exhausted(&self) -> bool {
        self.remaining <= 0
    }
}

/// The stored document of `guid` when it holds every required article, read within the budget.
pub async fn covered_snapshot(
    conn: &mut MySqlConnection,
    required: &HashSet<String>,
    guid: &str,
    budget: &mut CoverageBudget,
) -> Result<Option<ParsedNzb>> {
    if budget.exhausted() {
        return Ok(None);
    }
    let allowance = (budget.remaining as usize).min(DECODED_BYTES);
    let contents = store::read_bounded(&mut *conn, guid, COMPRESSED_BYTES, allowance).await;
    let contents = match contents {
        Ok(Some(contents)) => {
            budget.remaining -= contents.len() as i64;
            contents
        }
        Ok(None) => return Ok(None),
        Err(_) => {
            budget.remaining -= allowance as i64;
            return Ok(None);
        }
    };
    let Some(document) = parse_document(&contents) else {
        return Ok(None);
    };
    let Some(available) = coverage_ids(&document) else {
        return Ok(None);
    };
    Ok(required.is_subset(&available).then_some(document))
}
