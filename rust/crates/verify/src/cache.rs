//! Cursors and short locks in Redis, where Laravel's cache kept them. Losing them only makes a walk
//! start over, so every read failure counts as absent.

use std::time::Duration;

use anyhow::Result;
use rand::RngCore;
use redis::AsyncCommands;
use redis::aio::ConnectionManager;
use serde::Serialize;
use serde::de::DeserializeOwned;

#[derive(Clone)]
pub struct Cache {
    redis: ConnectionManager,
    prefix: String,
}

/// A held lock; release it with [`Cache::release`]. If its holder dies it expires by itself.
#[derive(Debug)]
pub struct CacheLock {
    key: String,
    token: String,
}

impl Cache {
    pub fn new(redis: ConnectionManager, prefix: impl Into<String>) -> Self {
        Self {
            redis,
            prefix: prefix.into(),
        }
    }

    fn key(&self, key: &str) -> String {
        format!("{}{key}", self.prefix)
    }

    /// A stored value, or `None` when it's absent, unreadable or Redis is down.
    pub async fn get<T: DeserializeOwned>(&self, key: &str) -> Option<T> {
        let mut redis = self.redis.clone();
        let raw: Option<String> = redis.get(self.key(key)).await.ok()?;
        serde_json::from_str(&raw?).ok()
    }

    /// Stores a value with no expiry.
    pub async fn forever<T: Serialize>(&self, key: &str, value: &T) -> Result<()> {
        let mut redis = self.redis.clone();
        let () = redis
            .set(self.key(key), serde_json::to_string(value)?)
            .await?;
        Ok(())
    }

    /// Stores a value that expires.
    pub async fn put<T: Serialize>(&self, key: &str, value: &T, ttl: Duration) -> Result<()> {
        let mut redis = self.redis.clone();
        let () = redis
            .set_ex(self.key(key), serde_json::to_string(value)?, ttl.as_secs())
            .await?;
        Ok(())
    }

    pub async fn forget(&self, key: &str) -> Result<()> {
        let mut redis = self.redis.clone();
        let () = redis.del(self.key(key)).await?;
        Ok(())
    }

    /// Sets a marker only if it's absent. True when this call set it.
    pub async fn add(&self, key: &str, ttl: Duration) -> Result<bool> {
        let mut redis = self.redis.clone();
        let set: Option<String> = redis::cmd("SET")
            .arg(self.key(key))
            .arg("1")
            .arg("NX")
            .arg("EX")
            .arg(ttl.as_secs())
            .query_async(&mut redis)
            .await?;
        Ok(set.is_some())
    }

    /// Takes a lock without waiting. `None` when another holder has it.
    pub async fn lock(&self, key: &str, ttl: Duration) -> Result<Option<CacheLock>> {
        let mut redis = self.redis.clone();
        let mut bytes = [0u8; 16];
        rand::rng().fill_bytes(&mut bytes);
        let token = hex::encode(bytes);
        let key = self.key(key);
        let set: Option<String> = redis::cmd("SET")
            .arg(&key)
            .arg(&token)
            .arg("NX")
            .arg("EX")
            .arg(ttl.as_secs())
            .query_async(&mut redis)
            .await?;
        Ok(set.map(|_| CacheLock { key, token }))
    }

    /// Releases a lock only if this holder still owns it.
    pub async fn release(&self, lock: CacheLock) -> Result<()> {
        let mut redis = self.redis.clone();
        let script = redis::Script::new(
            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
        );
        let _: i64 = script
            .key(&lock.key)
            .arg(&lock.token)
            .invoke_async(&mut redis)
            .await?;
        Ok(())
    }
}
