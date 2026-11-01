//! Validates a candidate's article references without fetching any payload.

use std::collections::HashSet;

use crate::manifest::Manifest;

pub const CONFLICT: &str = "Conflicting header identities require resolution.";
pub const NO_INVENTORY: &str = "Candidate has no file inventory to spot check.";
pub const BAD_FILE: &str = "Invalid or duplicate file identity in candidate headers.";
pub const BAD_COUNT: &str = "Invalid declared article count in candidate headers.";
pub const BAD_ARTICLE: &str = "Invalid or duplicate article reference in candidate headers.";
pub const FILES_UPLOADING: &str =
    "Declared file inventory is still uploading; publication deferred.";
pub const ARTICLES_UPLOADING: &str =
    "Declared article inventory is still uploading; publication deferred.";

/// Header defects depend only on the manifest, so retrying the same version can't pass. Such a
/// candidate waits outside the claim queue until new headers create a new version, or it expires.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum HeaderStatus {
    Ready,
    WaitingUpload,
    Rejected,
}

impl HeaderStatus {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Ready => "ready",
            Self::WaitingUpload => "waiting_upload",
            Self::Rejected => "header_rejected",
        }
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Assessment {
    pub status: HeaderStatus,
    pub reason: Option<&'static str>,
}

fn rejected(reason: &'static str) -> Assessment {
    Assessment {
        status: HeaderStatus::Rejected,
        reason: Some(reason),
    }
}

pub fn assess(manifest: &Manifest) -> Assessment {
    if manifest.conflict {
        return rejected(CONFLICT);
    }
    if manifest.files.is_empty() {
        return rejected(NO_INVENTORY);
    }
    let expected = manifest.expected_files.max(0);
    let mut missing_parts = false;
    let mut keys = HashSet::new();
    let mut ids = HashSet::new();
    for file in &manifest.files {
        if file.key.is_empty() || !keys.insert(file.key.as_str()) {
            return rejected(BAD_FILE);
        }
        let total = file.totalparts;
        let count = file.segments.len() as i64;
        if total < 1 || count > total {
            return rejected(BAD_COUNT);
        }
        missing_parts = missing_parts || count < total;
        let mut numbers = HashSet::new();
        for segment in &file.segments {
            let id = segment.messageid.trim_matches(['<', '>']);
            if segment.number < 1
                || segment.number > total
                || !numbers.insert(segment.number)
                || segment.bytes < 1
                || !valid_message_id(id)
                || !ids.insert(id)
            {
                return rejected(BAD_ARTICLE);
            }
        }
    }
    if expected > manifest.files.len() as i64 {
        return Assessment {
            status: HeaderStatus::WaitingUpload,
            reason: Some(FILES_UPLOADING),
        };
    }
    if missing_parts {
        return Assessment {
            status: HeaderStatus::WaitingUpload,
            reason: Some(ARTICLES_UPLOADING),
        };
    }
    Assessment {
        status: HeaderStatus::Ready,
        reason: None,
    }
}

/// `/\A[^\s<>\x00-\x1f\x7f]+\z/D`: PCRE `\s` without the `u` flag, on bytes.
fn valid_message_id(id: &str) -> bool {
    !id.is_empty()
        && id
            .bytes()
            .all(|byte| !matches!(byte, 0x00..=0x20 | 0x7f | b'<' | b'>'))
}

/// The rejection a partial publication still enforces: an upload only missing files or articles is
/// accepted, while invalid or conflicting headers aren't.
pub fn partial_rejection(manifest: &Manifest) -> Option<&'static str> {
    let assessment = assess(manifest);
    match assessment.status {
        HeaderStatus::WaitingUpload => None,
        _ => assessment.reason,
    }
}

/// Articles and files found in the headers against those declared. A file counts as found once it
/// has an article, since only those reach the NZB. Files never seen declare no article count.
#[derive(Clone, Copy, Debug, PartialEq, Eq, serde::Serialize, serde::Deserialize)]
pub struct Completeness {
    pub articles_found: i64,
    pub articles_expected: i64,
    pub files_found: i64,
    pub files_expected: i64,
}

pub fn completeness(manifest: &Manifest) -> Completeness {
    let mut found = 0;
    let mut expected = 0;
    let mut files_found = 0;
    for file in &manifest.files {
        let segments = file.segments.len() as i64;
        found += segments;
        expected += segments.max(file.totalparts);
        files_found += i64::from(segments > 0);
    }
    Completeness {
        articles_found: found,
        articles_expected: expected,
        files_found,
        files_expected: (manifest.files.len() as i64).max(manifest.expected_files),
    }
}

/// The article sizes the headers declare, summed.
pub fn bytes(manifest: &Manifest) -> i64 {
    manifest
        .files
        .iter()
        .flat_map(|file| &file.segments)
        .map(|segment| segment.bytes.max(0))
        .sum()
}
