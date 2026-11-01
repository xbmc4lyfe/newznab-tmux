//! The Redis outbox web requests push rendered mail onto (`nntmux_webkit::mail::enqueue`).
//!
//! A message moves to a processing list while it's sent, so a crash or a deploy can't lose it:
//! the next start puts anything left there back on the outbox. One that can't be sent moves to
//! a failed list, which `nntmux-ctl mail retry-failed` returns to the outbox.

use std::time::Duration;

use anyhow::{Context, Result};
use redis::AsyncCommands;
use redis::aio::ConnectionManager;

#[derive(Clone)]
pub struct Outbox {
    redis: ConnectionManager,
    pub queue: String,
    pub processing: String,
    pub failed: String,
}

impl Outbox {
    /// The lists beside `queue`, which `nntmux_webkit::mail::outbox_key` names in production.
    pub fn new(redis: ConnectionManager, queue: String) -> Self {
        Self {
            redis,
            processing: format!("{queue}:processing"),
            failed: format!("{queue}:failed"),
            queue,
        }
    }

    /// Returns messages a stopped sender was holding to the front of the queue. Only one mail
    /// role runs, so anything in processing at start belongs to a sender that's gone.
    pub async fn recover(&self) -> Result<usize> {
        let mut redis = self.redis.clone();
        let mut moved = 0;
        while redis
            .lmove::<_, _, Option<String>>(
                &self.processing,
                &self.queue,
                redis::Direction::Right,
                redis::Direction::Right,
            )
            .await
            .context("recovering mail")?
            .is_some()
        {
            moved += 1;
        }
        Ok(moved)
    }

    /// The oldest waiting message, now held in processing until [`Self::done`] or
    /// [`Self::fail`].
    pub async fn claim(&self) -> Result<Option<String>> {
        let mut redis = self.redis.clone();
        redis
            .lmove(
                &self.queue,
                &self.processing,
                redis::Direction::Right,
                redis::Direction::Left,
            )
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

    /// Parks a message that can't be sent, newest first.
    pub async fn fail(&self, raw: &str) -> Result<()> {
        let mut redis = self.redis.clone();
        redis::pipe()
            .atomic()
            .lpush(&self.failed, raw)
            .ignore()
            .lrem(&self.processing, 1, raw)
            .ignore()
            .query_async::<()>(&mut redis)
            .await
            .context("parking failed mail")
    }

    pub async fn counts(&self) -> Result<(usize, usize, usize)> {
        let mut redis = self.redis.clone();
        let (queued, processing, failed) = redis::pipe()
            .llen(&self.queue)
            .llen(&self.processing)
            .llen(&self.failed)
            .query_async(&mut redis)
            .await
            .context("counting mail")?;
        Ok((queued, processing, failed))
    }

    /// Puts every failed message back at the front of the queue, oldest first.
    pub async fn retry_failed(&self) -> Result<usize> {
        let mut redis = self.redis.clone();
        let mut moved = 0;
        while redis
            .lmove::<_, _, Option<String>>(
                &self.failed,
                &self.queue,
                redis::Direction::Right,
                redis::Direction::Right,
            )
            .await
            .context("requeueing failed mail")?
            .is_some()
        {
            moved += 1;
        }
        Ok(moved)
    }
}

/// How long to wait before each retry of a message the server turned away for now. Laravel's
/// queue tried a mail job three times; a short SMTP outage shouldn't lose a password reset,
/// so this waits longer before parking it.
pub const BACKOFF: [Duration; 5] = [
    Duration::from_secs(10),
    Duration::from_secs(30),
    Duration::from_secs(60),
    Duration::from_secs(120),
    Duration::from_secs(300),
];
