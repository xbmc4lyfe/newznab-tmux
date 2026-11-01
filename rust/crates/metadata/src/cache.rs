//! Redis caching and short locks, where Laravel's cache kept them. Losing them only repeats work,
//! so Redis being down never fails a lookup.

use std::time::Duration;

use anyhow::Result;
use nntmux_core::var_or;
use rand::RngCore;
use redis::AsyncCommands;
use redis::aio::ConnectionManager;
use serde::Serialize;
use serde::de::DeserializeOwned;
use tracing::warn;

const PREFIX: &str = "metadata:";

#[derive(Clone)]
pub struct Cache {
    redis: ConnectionManager,
    prefix: String,
}

#[derive(Serialize, serde::Deserialize)]
struct Entry<T> {
    value: Option<T>,
}

/// A held lock. If its holder dies it expires by itself.
pub struct Lock {
    key: String,
    token: String,
}

impl Cache {
    pub fn new(redis: ConnectionManager) -> Self {
        Self::with_prefix(redis, PREFIX)
    }

    /// For tests, so parallel runs can't share keys.
    pub fn with_prefix(redis: ConnectionManager, prefix: &str) -> Self {
        Self {
            redis,
            prefix: prefix.to_owned(),
        }
    }

    fn key(&self, key: &str) -> String {
        format!("{}{key}", self.prefix)
    }

    pub async fn get<T: DeserializeOwned>(&self, key: &str) -> Option<T> {
        let mut redis = self.redis.clone();
        let raw: Option<String> = redis.get(self.key(key)).await.ok()?;
        serde_json::from_str(&raw?).ok()
    }

    pub async fn has(&self, key: &str) -> bool {
        let mut redis = self.redis.clone();
        redis.exists(self.key(key)).await.unwrap_or(false)
    }

    pub async fn put<T: Serialize>(&self, key: &str, value: &T, ttl: Duration) {
        let Ok(raw) = serde_json::to_string(value) else {
            return;
        };
        let mut redis = self.redis.clone();
        let stored: redis::RedisResult<()> =
            redis.set_ex(self.key(key), raw, ttl.as_secs().max(1)).await;
        if let Err(failure) = stored {
            warn!(error = %failure, "redis write failed");
        }
    }

    /// A remembered answer, which may be a remembered miss. The outer `None` means not cached.
    pub async fn get_entry<T: DeserializeOwned>(&self, key: &str) -> Option<Option<T>> {
        self.get::<Entry<T>>(key).await.map(|entry| entry.value)
    }

    pub async fn put_entry<T: Serialize>(&self, key: &str, value: Option<&T>, ttl: Duration) {
        self.put(key, &Entry { value }, ttl).await;
    }

    /// `Cache::add`: true when this call created the key, or when Redis is down.
    pub async fn add(&self, key: &str, ttl: Duration) -> bool {
        let mut redis = self.redis.clone();
        let set: redis::RedisResult<Option<String>> = redis::cmd("SET")
            .arg(self.key(key))
            .arg("1")
            .arg("NX")
            .arg("EX")
            .arg(ttl.as_secs().max(1))
            .query_async(&mut redis)
            .await;
        set.map_or(true, |set| set.is_some())
    }

    pub async fn forget(&self, key: &str) {
        let mut redis = self.redis.clone();
        let removed: redis::RedisResult<()> = redis.del(self.key(key)).await;
        if let Err(failure) = removed {
            warn!(error = %failure, "redis delete failed");
        }
    }

    /// Takes a lock, waiting up to `wait`. `None` when another holder kept it, or Redis is down;
    /// the callers then carry on unlocked, as `LockTimeoutException` handlers did.
    pub async fn lock(&self, key: &str, ttl: Duration, wait: Duration) -> Option<Lock> {
        let key = self.key(key);
        let mut bytes = [0u8; 16];
        rand::rng().fill_bytes(&mut bytes);
        let token = hex::encode(bytes);
        let deadline = tokio::time::Instant::now() + wait;
        let mut redis = self.redis.clone();
        loop {
            let set: redis::RedisResult<Option<String>> = redis::cmd("SET")
                .arg(&key)
                .arg(&token)
                .arg("NX")
                .arg("EX")
                .arg(ttl.as_secs().max(1))
                .query_async(&mut redis)
                .await;
            match set {
                Ok(Some(_)) => return Some(Lock { key, token }),
                Ok(None) => {}
                Err(failure) => {
                    warn!(error = %failure, "redis lock failed");
                    return None;
                }
            }
            if tokio::time::Instant::now() >= deadline {
                return None;
            }
            tokio::time::sleep(Duration::from_millis(250)).await;
        }
    }

    /// Releases a lock only if this holder still owns it.
    pub async fn release(&self, lock: Lock) {
        let mut redis = self.redis.clone();
        let script = redis::Script::new(
            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
        );
        let released: redis::RedisResult<i64> = script
            .key(&lock.key)
            .arg(&lock.token)
            .invoke_async(&mut redis)
            .await;
        if let Err(failure) = released {
            warn!(error = %failure, "redis unlock failed");
        }
    }

    /// `ReleaseBrowseService::bumpCacheVersion`, on the key `nntmux-api` reads: makes every cached
    /// release list stale. A missing version counts as 1, so the first bump still changes it.
    pub async fn bump_release_version(&self) -> Result<()> {
        let key = format!(
            "{}releases:cache_version",
            var_or("NNTMUX_API_REDIS_PREFIX", "")
        );
        let mut redis = self.redis.clone();
        let script = redis::Script::new(
            "local version = tonumber(redis.call('get', KEYS[1]) or '1') or 1; \
             redis.call('set', KEYS[1], version + 1); return version + 1",
        );
        let _: i64 = script.key(key).invoke_async(&mut redis).await?;
        Ok(())
    }
}
