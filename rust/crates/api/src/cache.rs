//! Redis caching, locks and counters. Redis being down degrades to doing the work uncached, as
//! `DegradeWhenRedisUnreachable` did.

use std::time::Duration;

use nntmux_runtime::Ctx;
use rand::Rng;
use redis::AsyncCommands;
use serde::Serialize;
use serde::de::DeserializeOwned;
use tracing::warn;

use crate::settings::Settings;

fn key(settings: &Settings, name: &str) -> String {
    format!("{}{name}", settings.redis_prefix)
}

pub async fn get<T: DeserializeOwned>(ctx: &Ctx, settings: &Settings, name: &str) -> Option<T> {
    let mut redis = ctx.redis.clone();
    let raw: Option<String> = match redis.get(key(settings, name)).await {
        Ok(raw) => raw,
        Err(failure) => {
            warn!(error = %failure, "redis read failed");
            return None;
        }
    };
    serde_json::from_str(&raw?).ok()
}

pub async fn put<T: Serialize>(ctx: &Ctx, settings: &Settings, name: &str, ttl: u64, value: &T) {
    let Ok(raw) = serde_json::to_string(value) else {
        return;
    };
    let mut redis = ctx.redis.clone();
    let stored: redis::RedisResult<()> = redis.set_ex(key(settings, name), raw, ttl.max(1)).await;
    if let Err(failure) = stored {
        warn!(error = %failure, "redis write failed");
    }
}

/// `Cache::add`: true when this call created the key. `None` means Redis failed.
pub async fn add(ctx: &Ctx, settings: &Settings, name: &str, ttl: u64) -> Option<bool> {
    let mut redis = ctx.redis.clone();
    let options = redis::SetOptions::default()
        .conditional_set(redis::ExistenceCheck::NX)
        .with_expiration(redis::SetExpiry::EX(ttl.max(1)));
    let stored: redis::RedisResult<Option<String>> =
        redis.set_options(key(settings, name), "1", options).await;
    match stored {
        Ok(reply) => Some(reply.is_some()),
        Err(failure) => {
            warn!(error = %failure, "redis add failed");
            None
        }
    }
}

const RELEASE_LOCK: &str =
    "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";

/// A held Redis lock. Release it with [`Lock::release`]; otherwise it expires on its own.
pub struct Lock {
    name: String,
    owner: String,
}

/// Tries for `wait`, then gives up with `Ok(None)`. Redis errors are `Err`, so a caller that must
/// not run unlocked can refuse.
pub async fn lock(
    ctx: &Ctx,
    settings: &Settings,
    name: &str,
    ttl: u64,
    wait: Duration,
) -> redis::RedisResult<Option<Lock>> {
    let name = key(settings, name);
    let owner: String = format!("{:032x}", rand::rng().random::<u128>());
    let deadline = tokio::time::Instant::now() + wait;
    let mut redis = ctx.redis.clone();
    loop {
        let options = redis::SetOptions::default()
            .conditional_set(redis::ExistenceCheck::NX)
            .with_expiration(redis::SetExpiry::EX(ttl.max(1)));
        let taken: Option<String> = redis.set_options(&name, &owner, options).await?;
        if taken.is_some() {
            return Ok(Some(Lock { name, owner }));
        }
        if tokio::time::Instant::now() >= deadline {
            return Ok(None);
        }
        tokio::time::sleep(Duration::from_millis(250)).await;
    }
}

impl Lock {
    pub async fn release(self, ctx: &Ctx) {
        let mut redis = ctx.redis.clone();
        let released: redis::RedisResult<i64> = redis::Script::new(RELEASE_LOCK)
            .key(&self.name)
            .arg(&self.owner)
            .invoke_async(&mut redis)
            .await;
        if let Err(failure) = released {
            warn!(error = %failure, "redis unlock failed");
        }
    }
}

/// Laravel's `RateLimiter`: `tooManyAttempts` then `hit`, in one step.
pub struct Attempt {
    pub allowed: bool,
    pub attempts: i64,
    pub retry_after: i64,
}

const HIT: &str = "
local attempts = tonumber(redis.call('get', KEYS[1]) or '0')
if attempts >= tonumber(ARGV[1]) then
  local ttl = redis.call('ttl', KEYS[1])
  if ttl > 0 then return {0, attempts, ttl} end
  attempts = 0
  redis.call('del', KEYS[1])
end
attempts = redis.call('incr', KEYS[1])
if attempts == 1 then redis.call('expire', KEYS[1], ARGV[2]) end
return {1, attempts, redis.call('ttl', KEYS[1])}";

/// Counts an attempt against `max` per `decay` seconds. Redis failing lets the request through.
pub async fn hit(ctx: &Ctx, settings: &Settings, name: &str, max: i64, decay: u64) -> Attempt {
    let mut redis = ctx.redis.clone();
    let result: redis::RedisResult<(i64, i64, i64)> = redis::Script::new(HIT)
        .key(key(settings, name))
        .arg(max)
        .arg(decay)
        .invoke_async(&mut redis)
        .await;
    match result {
        Ok((allowed, attempts, ttl)) => Attempt {
            allowed: allowed == 1,
            attempts,
            retry_after: ttl.max(1),
        },
        Err(failure) => {
            warn!(error = %failure, "redis rate limiter failed");
            Attempt {
                allowed: true,
                attempts: 0,
                retry_after: 1,
            }
        }
    }
}
