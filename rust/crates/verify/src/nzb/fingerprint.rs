//! Order-independent fingerprint of unique normalized segment Message-IDs.
//!
//! Stored fingerprints only nominate duplicates; a suppression always proves coverage from the
//! NZB itself. Unsupported IDs leave identity unknown rather than being truncated or skipped.

use std::collections::HashSet;

use sha1::Sha1;
use sha2::{Digest, Sha256};

pub const MAX_MESSAGE_ID_BYTES: usize = 255;

/// PHP's `trim()`: strips space, tab, newline, carriage return, NUL and vertical tab.
pub fn php_trim(value: &str) -> &str {
    value.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B'])
}

/// Strips surrounding whitespace, one pair of outer quotes and one pair of angle brackets.
pub fn normalize_message_id(message_id: &str) -> &str {
    let mut id = php_trim(message_id);
    let quoted = id.len() >= 2
        && ((id.starts_with('"') && id.ends_with('"'))
            || (id.starts_with('\'') && id.ends_with('\'')));
    if quoted {
        id = php_trim(&id[1..id.len() - 1]);
    }
    if let Some(inner) = id.strip_prefix('<').and_then(|rest| rest.strip_suffix('>')) {
        id = inner;
    }
    php_trim(id)
}

/// The persisted parts schema supports 255-byte IDs. Unsupported input makes article identity
/// unknown; it's never truncated or silently omitted.
pub fn validated_message_id(message_id: &str) -> Option<&str> {
    let id = normalize_message_id(message_id);
    let valid = !id.is_empty()
        && id.len() <= MAX_MESSAGE_ID_BYTES
        && !id.bytes().any(|byte| matches!(byte, 0x00..=0x20 | 0x7f));
    valid.then_some(id)
}

/// Accumulates a fingerprint. IDs are reduced to their hash on the way in, which bounds memory for
/// the largest upload the metadata budget admits.
#[derive(Debug, Default)]
pub struct ArticleFingerprint {
    seen: HashSet<[u8; 32]>,
    lanes: [u32; 8],
    failed: bool,
}

impl ArticleFingerprint {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn add(&mut self, message_id: &str) {
        if self.failed {
            return;
        }
        let Some(id) = validated_message_id(message_id) else {
            self.failed = true;
            return;
        };
        let hash: [u8; 32] = Sha256::digest(id).into();
        if self.seen.insert(hash) {
            for (lane, word) in self.lanes.iter_mut().zip(hash.as_chunks::<4>().0) {
                *lane = lane.wrapping_add(u32::from_be_bytes(*word));
            }
        }
    }

    /// Null when no IDs were added or one was unsupported.
    pub fn value(&self) -> Option<String> {
        if self.failed || self.seen.is_empty() {
            return None;
        }
        let mut hasher = Sha1::new();
        hasher.update((self.seen.len() as u64).to_be_bytes());
        for lane in self.lanes {
            hasher.update(lane.to_be_bytes());
        }
        Some(hex::encode(hasher.finalize()))
    }
}
