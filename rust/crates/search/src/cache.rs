//! Short-lived result caching in Redis. A cache failure never fails a search.

use redis::AsyncCommands;
use redis::aio::ConnectionManager;
use serde::Serialize;
use serde::de::DeserializeOwned;
use sha2::{Digest, Sha256};
use tracing::debug;

#[derive(Clone)]
pub(crate) struct Cache(Option<ConnectionManager>);

impl Cache {
    pub(crate) fn new(redis: Option<ConnectionManager>) -> Self {
        Self(redis)
    }

    pub(crate) async fn get<T: DeserializeOwned>(&self, key: &str) -> Option<T> {
        let mut redis = self.0.clone()?;
        let raw: Option<String> = match redis.get(key).await {
            Ok(raw) => raw,
            Err(error) => {
                debug!(%error, "search cache read failed");
                return None;
            }
        };
        serde_json::from_str(&raw?).ok()
    }

    pub(crate) async fn put<T: Serialize>(&self, key: &str, value: &T, minutes: u64) {
        let Some(mut redis) = self.0.clone() else {
            return;
        };
        let Ok(raw) = serde_json::to_string(value) else {
            return;
        };
        let written: redis::RedisResult<()> = redis.set_ex(key, raw, minutes.max(1) * 60).await;
        if let Err(error) = written {
            debug!(%error, "search cache write failed");
        }
    }

    /// Adds to a failure counter. True for the first caller in a minute, which is the one
    /// that should log, so an outage doesn't write a line per release.
    pub(crate) async fn count_failure(&self, counter: &str, lock: &str) -> bool {
        let Some(mut redis) = self.0.clone() else {
            return true;
        };
        let counted: redis::RedisResult<i64> = redis.incr(counter, 1).await;
        if let Err(error) = counted {
            debug!(%error, "search failure counter write failed");
        }
        let options = redis::SetOptions::default()
            .conditional_set(redis::ExistenceCheck::NX)
            .with_expiration(redis::SetExpiry::EX(60));
        redis
            .set_options::<_, _, Option<String>>(lock, true, options)
            .await
            .map_or(true, |set| set.is_some())
    }
}

/// A cache key for these inputs. The generation in `parts` retires old entries after a rebuild.
pub(crate) fn key(kind: &str, parts: &impl Serialize) -> String {
    let encoded = serde_json::to_vec(parts).unwrap_or_default();
    format!(
        "nntmux:search:{kind}:{}",
        hex::encode(Sha256::digest(&encoded))
    )
}
