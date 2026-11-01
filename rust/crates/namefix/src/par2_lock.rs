//! Laravel's renewable Redis lock (`Cache::lock`), and the claim a PAR2 check holds through it.

use std::sync::Arc;
use std::sync::atomic::{AtomicBool, AtomicI64, Ordering};
use std::time::{Duration, Instant};

use anyhow::Result;
use async_trait::async_trait;
use rand::Rng;
use redis::aio::ConnectionManager;

use crate::par2_support::{Claim, Guard};

const REFRESH: &str = "if redis.call('get', KEYS[1]) == ARGV[1] then \
    if tonumber(ARGV[2]) > 0 then return redis.call('expire', KEYS[1], ARGV[2]) end \
    redis.call('persist', KEYS[1]) return 1 else return 0 end";
const RELEASE: &str = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";

/// An atomic lock with an owner token that its holder can renew and release, and nobody else
/// can.
#[derive(Clone)]
pub struct RedisLock {
    redis: ConnectionManager,
    name: String,
    owner: String,
    seconds: u64,
}

impl RedisLock {
    pub fn new(redis: ConnectionManager, name: impl Into<String>, seconds: u64) -> Self {
        let owner: String = rand::rng()
            .sample_iter(rand::distr::Alphanumeric)
            .take(16)
            .map(char::from)
            .collect();
        Self {
            redis,
            name: name.into(),
            owner,
            seconds,
        }
    }

    /// `Lock::get`: takes the lock if nobody holds it.
    pub async fn acquire(&self) -> Result<bool> {
        let mut redis = self.redis.clone();
        let claimed: Option<String> = redis::cmd("SET")
            .arg(&self.name)
            .arg(&self.owner)
            .arg("EX")
            .arg(self.seconds.max(1))
            .arg("NX")
            .query_async(&mut redis)
            .await?;
        Ok(claimed.is_some())
    }

    /// `Lock::refresh`: extends the lease while this holder still owns it.
    pub async fn refresh(&self) -> bool {
        let mut redis = self.redis.clone();
        let renewed: redis::RedisResult<i64> = redis::Script::new(REFRESH)
            .key(&self.name)
            .arg(&self.owner)
            .arg(self.seconds)
            .invoke_async(&mut redis)
            .await;
        renewed.is_ok_and(|renewed| renewed == 1)
    }

    pub async fn release(&self) -> Result<bool> {
        let mut redis = self.redis.clone();
        let released: i64 = redis::Script::new(RELEASE)
            .key(&self.name)
            .arg(&self.owner)
            .invoke_async(&mut redis)
            .await?;
        Ok(released == 1)
    }
}

/// Something a PAR2 check renews alongside its own lock, such as a sweep's.
#[async_trait]
pub trait Refresh: Send + Sync {
    async fn refresh(&self) -> bool;
}

#[async_trait]
impl Refresh for RedisLock {
    async fn refresh(&self) -> bool {
        RedisLock::refresh(self).await
    }
}

/// The claim one `checkPar2Result` holds: its release lock, an optional deadline, and an
/// optional outer claim (the sweep) that must hold too.
pub struct LockClaim {
    lock: RedisLock,
    deadline: Option<Instant>,
    outer: Option<Arc<dyn Refresh>>,
    held: AtomicBool,
    last_heartbeat: AtomicI64,
}

/// How often an article read renews the claim.
const HEARTBEAT_SECONDS: i64 = 30;

fn now_seconds() -> i64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map_or(0, |elapsed| elapsed.as_secs() as i64)
}

impl LockClaim {
    pub fn new(
        lock: RedisLock,
        deadline: Option<Instant>,
        outer: Option<Arc<dyn Refresh>>,
    ) -> Self {
        Self {
            lock,
            deadline,
            outer,
            held: AtomicBool::new(true),
            last_heartbeat: AtomicI64::new(0),
        }
    }

    /// `$refreshOwnership`.
    async fn refresh_ownership(&self) -> bool {
        if !self.held.load(Ordering::SeqCst) {
            return false;
        }
        if let Some(outer) = &self.outer
            && !outer.refresh().await
        {
            return false;
        }
        self.lock.refresh().await
    }

    /// `$onArticleRead`: renews at most every thirty seconds, and remembers a loss.
    async fn heartbeat(&self) -> bool {
        if !self.held.load(Ordering::SeqCst) {
            return false;
        }
        let now = now_seconds();
        if now - self.last_heartbeat.load(Ordering::SeqCst) < HEARTBEAT_SECONDS {
            return true;
        }
        self.last_heartbeat.store(now, Ordering::SeqCst);
        let held = self.refresh_ownership().await;
        self.held.store(held, Ordering::SeqCst);
        held
    }
}

#[async_trait]
impl Guard for LockClaim {
    async fn holds(&self) -> bool {
        self.deadline
            .is_none_or(|deadline| Instant::now() < deadline)
            && self.refresh_ownership().await
    }
}

#[async_trait]
impl Claim for LockClaim {
    fn read_ok(&self) -> bool {
        self.held.load(Ordering::SeqCst)
    }

    async fn keep_alive(&self) {
        loop {
            if !self.heartbeat().await {
                return;
            }
            tokio::time::sleep(Duration::from_secs(HEARTBEAT_SECONDS as u64)).await;
        }
    }

    async fn received(&self) -> bool {
        self.heartbeat().await
    }
}
