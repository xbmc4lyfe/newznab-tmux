//! The Redis outbox web requests push rendered mail onto (`nntmux_webkit::mail::enqueue`).
//!
//! A message moves to a processing list while it's sent, so a crash or a deploy can't lose it:
//! the next start puts anything left there back on the outbox. One the server turned away for
//! now waits in a sorted set until its retry time, wrapped with its attempt count, while later
//! mail goes out. One that can't be sent moves to a failed list, which
//! `nntmux-ctl mail retry-failed` returns to the outbox.

use std::time::{Duration, SystemTime, UNIX_EPOCH};

use anyhow::{Context, Result};
use nntmux_webkit::mail::OutgoingMail;
use redis::AsyncCommands;
use redis::aio::ConnectionManager;
use serde::{Deserialize, Serialize};

/// How long to wait before each retry of a message the server turned away for now. Laravel's
/// queue tried a mail job three times; a short SMTP outage shouldn't lose a password reset,
/// so this keeps trying for longer before parking it.
pub const BACKOFF: [Duration; 5] = [
    Duration::from_secs(10),
    Duration::from_secs(30),
    Duration::from_secs(60),
    Duration::from_secs(120),
    Duration::from_secs(300),
];

/// Takes the first retry that's due, else the oldest queued message, into processing.
const CLAIM: &str = r"
local due = redis.call('ZRANGEBYSCORE', KEYS[2], '-inf', ARGV[1], 'LIMIT', 0, 1)[1]
if due then
  redis.call('ZREM', KEYS[2], due)
  redis.call('LPUSH', KEYS[3], due)
  return due
end
return redis.call('LMOVE', KEYS[1], KEYS[3], 'RIGHT', 'LEFT')
";

/// A message with the retries it has had. Fresh mail from the site has no wrapper.
#[derive(Debug, Deserialize, Serialize)]
pub struct Envelope {
    pub attempt: usize,
    pub mail: OutgoingMail,
}

impl Envelope {
    pub fn parse(raw: &str) -> Option<Self> {
        if let Ok(envelope) = serde_json::from_str(raw) {
            return Some(envelope);
        }
        let mail = serde_json::from_str(raw).ok()?;
        Some(Self { attempt: 0, mail })
    }

    pub fn to_json(&self) -> Result<String> {
        Ok(serde_json::to_string(self)?)
    }
}

#[derive(Debug, PartialEq, Eq)]
pub struct Counts {
    pub queued: usize,
    pub processing: usize,
    pub delayed: usize,
    pub failed: usize,
}

#[derive(Clone)]
pub struct Outbox {
    redis: ConnectionManager,
    pub queue: String,
    pub processing: String,
    pub delayed: String,
    pub failed: String,
}

fn now_seconds() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_or(0.0, |since| since.as_secs_f64())
}

impl Outbox {
    /// The keys beside `queue`, which `nntmux_webkit::mail::outbox_key` names in production.
    pub fn new(redis: ConnectionManager, queue: String) -> Self {
        Self {
            redis,
            processing: format!("{queue}:processing"),
            delayed: format!("{queue}:delayed"),
            failed: format!("{queue}:failed"),
            queue,
        }
    }

    /// Returns messages a stopped sender was holding to the front of the queue, oldest first.
    /// Only one mail role runs, so anything in processing at start belongs to a sender that's
    /// gone.
    pub async fn recover(&self) -> Result<usize> {
        self.move_all(&self.processing)
            .await
            .context("recovering mail")
    }

    /// Moves a newest-first list onto the claiming end of the queue, so its oldest goes first.
    async fn move_all(&self, from: &str) -> Result<usize> {
        let mut redis = self.redis.clone();
        let mut moved = 0;
        while redis
            .lmove::<_, _, Option<String>>(
                from,
                &self.queue,
                redis::Direction::Left,
                redis::Direction::Right,
            )
            .await?
            .is_some()
        {
            moved += 1;
        }
        Ok(moved)
    }

    /// The next message to send, now held in processing until [`Self::done`], [`Self::defer`]
    /// or [`Self::fail`]. A retry that's due goes before new mail.
    pub async fn claim(&self) -> Result<Option<String>> {
        let mut redis = self.redis.clone();
        redis::Script::new(CLAIM)
            .key(&self.queue)
            .key(&self.delayed)
            .key(&self.processing)
            .arg(now_seconds())
            .invoke_async(&mut redis)
            .await
            .context("claiming mail")
    }

    pub async fn done(&self, raw: &str) -> Result<()> {
        let mut redis = self.redis.clone();
        redis
            .lrem::<_, _, ()>(&self.processing, 1, raw)
            .await
            .context("removing sent mail")
    }

    /// Sets a message aside as `retry` until `wait` has passed.
    pub async fn defer(&self, raw: &str, retry: &str, wait: Duration) -> Result<()> {
        let mut redis = self.redis.clone();
        redis::pipe()
            .atomic()
            .zadd(&self.delayed, retry, now_seconds() + wait.as_secs_f64())
            .ignore()
            .lrem(&self.processing, 1, raw)
            .ignore()
            .query_async::<()>(&mut redis)
            .await
            .context("deferring mail")
    }

    /// Parks a message that can't be sent as `parked`, newest first.
    pub async fn fail(&self, raw: &str, parked: &str) -> Result<()> {
        let mut redis = self.redis.clone();
        redis::pipe()
            .atomic()
            .lpush(&self.failed, parked)
            .ignore()
            .lrem(&self.processing, 1, raw)
            .ignore()
            .query_async::<()>(&mut redis)
            .await
            .context("parking failed mail")
    }

    pub async fn counts(&self) -> Result<Counts> {
        let mut redis = self.redis.clone();
        let (queued, processing, delayed, failed) = redis::pipe()
            .llen(&self.queue)
            .llen(&self.processing)
            .zcard(&self.delayed)
            .llen(&self.failed)
            .query_async(&mut redis)
            .await
            .context("counting mail")?;
        Ok(Counts {
            queued,
            processing,
            delayed,
            failed,
        })
    }

    /// Puts every failed message back at the front of the queue, oldest first, with its
    /// retries reset.
    pub async fn retry_failed(&self) -> Result<usize> {
        self.move_all(&self.failed)
            .await
            .context("requeueing failed mail")
    }
}
