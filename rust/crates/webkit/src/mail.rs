//! Handing mail to the `mail` role. Web requests don't talk to SMTP: a page renders its message
//! and pushes it here, and the role sends it, the way Laravel queued mail jobs.

use anyhow::{Context, Result};
use nntmux_runtime::Ctx;
use redis::AsyncCommands;
use serde::{Deserialize, Serialize};

use crate::session::key_prefix;

/// A rendered message, ready to send.
#[derive(Clone, Debug, Deserialize, PartialEq, Serialize)]
pub struct OutgoingMail {
    pub to: String,
    pub subject: String,
    pub html: String,
    pub text: String,
    pub reply_to: Option<String>,
}

/// The Redis list the `mail` role pops from: `RPOP` for oldest first.
pub fn outbox_key() -> String {
    format!("{}mail:outbox", key_prefix())
}

pub async fn enqueue(ctx: &Ctx, message: &OutgoingMail) -> Result<()> {
    let body = serde_json::to_string(message)?;
    let mut redis = ctx.redis.clone();
    redis
        .lpush::<_, _, ()>(outbox_key(), body)
        .await
        .context("queueing mail")
}
