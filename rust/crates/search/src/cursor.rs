//! Signed pagination cursors. Manticore pages by offset, so a cursor carries the next offset
//! and what it's bound to. Laravel encrypted these with `APP_KEY`; nothing in them is secret,
//! so a signature is enough. A cursor issued by PHP is rejected and the client starts over,
//! which they could already expect, because cursors expire after 15 minutes.

use base64::Engine;
use base64::engine::general_purpose::{STANDARD, URL_SAFE_NO_PAD};
use hmac::{Hmac, Mac};
use nntmux_core::{optional, parsed_or};
use serde::{Deserialize, Serialize};
use sha2::Sha256;

use crate::SearchError;
use crate::release_query::SearchPage;

#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct SearchCursor {
    pub offset: u64,
    pub total: u64,
    pub query_hash: String,
    pub index_generation: String,
    pub expires_at: i64,
}

#[derive(Clone)]
pub struct CursorCodec {
    key: Vec<u8>,
    index_generation: String,
    ttl_seconds: i64,
}

impl CursorCodec {
    pub fn new(key: impl Into<Vec<u8>>, index_generation: &str, ttl_minutes: i64) -> Self {
        Self {
            key: key.into(),
            index_generation: index_generation.to_owned(),
            ttl_seconds: ttl_minutes * 60,
        }
    }

    /// Uses `APP_KEY`, with Laravel's `base64:` form decoded, and `SEARCH_CURSOR_TTL_MINUTES`.
    pub fn from_env(index_generation: &str) -> anyhow::Result<Self> {
        let key = optional("APP_KEY").ok_or_else(|| anyhow::anyhow!("APP_KEY is unset"))?;
        let key = match key.strip_prefix("base64:") {
            Some(encoded) => STANDARD.decode(encoded)?,
            None => key.into_bytes(),
        };
        let ttl = parsed_or("SEARCH_CURSOR_TTL_MINUTES", 15)?;
        Ok(Self::new(key, index_generation, ttl))
    }

    /// The cursor for the page after `page`, which was read at `offset`. None on the last page.
    pub fn next(&self, page: &SearchPage, offset: u64, query_hash: &str) -> Option<String> {
        page.has_more.then(|| {
            self.encode(&SearchCursor {
                offset: offset + page.ids.len() as u64,
                total: page.total,
                query_hash: query_hash.to_owned(),
                index_generation: self.index_generation.clone(),
                expires_at: chrono::Utc::now().timestamp() + self.ttl_seconds,
            })
        })
    }

    pub fn encode(&self, cursor: &SearchCursor) -> String {
        let payload = URL_SAFE_NO_PAD.encode(serde_json::to_vec(cursor).unwrap_or_default());
        let signature = URL_SAFE_NO_PAD.encode(self.mac(&payload).finalize().into_bytes());
        format!("{payload}.{signature}")
    }

    /// Checks the signature, expiry, and that the cursor belongs to this query and generation.
    pub fn decode(&self, token: &str, query_hash: &str) -> Result<SearchCursor, SearchError> {
        let (payload, signature) = token.split_once('.').ok_or(SearchError::InvalidCursor)?;
        let signature = URL_SAFE_NO_PAD
            .decode(signature)
            .map_err(|_| SearchError::InvalidCursor)?;
        self.mac(payload)
            .verify_slice(&signature)
            .map_err(|_| SearchError::InvalidCursor)?;
        let raw = URL_SAFE_NO_PAD
            .decode(payload)
            .map_err(|_| SearchError::InvalidCursor)?;
        let cursor: SearchCursor =
            serde_json::from_slice(&raw).map_err(|_| SearchError::InvalidCursor)?;
        if cursor.expires_at < chrono::Utc::now().timestamp() {
            return Err(SearchError::InvalidCursor);
        }
        if cursor.query_hash != query_hash || cursor.index_generation != self.index_generation {
            return Err(SearchError::CursorMismatch);
        }
        Ok(cursor)
    }

    fn mac(&self, payload: &str) -> Hmac<Sha256> {
        // HMAC takes a key of any length, so this can't fail.
        let mut mac = Hmac::<Sha256>::new_from_slice(&self.key).expect("HMAC accepts any key");
        mac.update(b"nntmux-search-cursor:");
        mac.update(payload.as_bytes());
        mac
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn page(has_more: bool) -> SearchPage {
        SearchPage {
            ids: vec![1, 2],
            total: 9,
            has_more,
            ..SearchPage::default()
        }
    }

    #[test]
    fn a_cursor_round_trips_to_the_next_offset() {
        let codec = CursorCodec::new("k", "1", 15);
        let token = codec.next(&page(true), 10, "q").unwrap();
        let cursor = codec.decode(&token, "q").unwrap();
        assert_eq!((cursor.offset, cursor.total), (12, 9));
        assert!(codec.next(&page(false), 10, "q").is_none());
    }

    #[test]
    fn a_tampered_or_foreign_cursor_is_rejected() {
        let codec = CursorCodec::new("k", "1", 15);
        let token = codec.next(&page(true), 0, "q").unwrap();
        let other_key = CursorCodec::new("other", "1", 15);
        assert!(matches!(
            other_key.decode(&token, "q"),
            Err(SearchError::InvalidCursor)
        ));
        assert!(matches!(
            codec.decode(&format!("x{token}"), "q"),
            Err(SearchError::InvalidCursor)
        ));
        assert!(matches!(
            codec.decode("garbage", "q"),
            Err(SearchError::InvalidCursor)
        ));
        assert!(matches!(
            codec.decode(&token, "another query"),
            Err(SearchError::CursorMismatch)
        ));
        let next_generation = CursorCodec::new("k", "2", 15);
        assert!(matches!(
            next_generation.decode(&token, "q"),
            Err(SearchError::CursorMismatch)
        ));
    }

    #[test]
    fn an_expired_cursor_is_rejected() {
        let codec = CursorCodec::new("k", "1", -1);
        let token = codec.next(&page(true), 0, "q").unwrap();
        assert!(matches!(
            codec.decode(&token, "q"),
            Err(SearchError::InvalidCursor)
        ));
    }
}
