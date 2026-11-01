//! Request accounting: quotas, the redacted audit entry and the access metadata update.

use std::sync::Arc;
use std::time::Duration;

use anyhow::{Context, Result};
use chrono::{NaiveDateTime, Utc};
use nntmux_runtime::Ctx;
use serde::{Deserialize, Serialize};
use tracing::warn;

use crate::auth::User;
use crate::cache;
use crate::input::{Input, Value, canonicalize};
use crate::settings::Settings;

/// Only these filters reach `user_requests.request`. Credentials and unknown fields never do,
/// because the column ends up in GDPR exports.
const AUDITED_PARAMETERS: [&str; 26] = [
    "t",
    "q",
    "id",
    "cat",
    "imdbid",
    "tmdbid",
    "traktid",
    "vid",
    "tvdbid",
    "rid",
    "tvmazeid",
    "anidbid",
    "anilistid",
    "season",
    "ep",
    "group",
    "limit",
    "offset",
    "maxage",
    "minsize",
    "sort",
    "o",
    "extended",
    "num",
    "dl",
    "del",
];
const AUDIT_VALUE_MAX_LENGTH: usize = 64;
const AUDIT_MAX_LENGTH: usize = 255;
const QUOTA_LOCK_SECONDS: u64 = 10;
const QUOTA_LOCK_WAIT: Duration = Duration::from_secs(5);

#[derive(Clone, Debug, Default, Deserialize, Serialize)]
pub struct Statistics {
    pub api_count: i64,
    pub grab_count: i64,
    pub api_time: Option<NaiveDateTime>,
    pub grab_time: Option<NaiveDateTime>,
}

/// `Carbon::toRfc2822String` in UTC, the application timezone.
pub fn rfc2822(time: NaiveDateTime) -> String {
    time.format("%a, %d %b %Y %H:%M:%S +0000").to_string()
}

pub fn now_string() -> String {
    Utc::now().format("%Y-%m-%d %H:%M:%S").to_string()
}

fn day_ago_string() -> String {
    (Utc::now() - chrono::Duration::days(1))
        .format("%Y-%m-%d %H:%M:%S")
        .to_string()
}

/// Usage in the rolling day, cached for a minute. Display only: quota enforcement in v2 counts
/// the table directly.
pub async fn statistics(ctx: &Ctx, settings: &Settings, user_id: i64) -> Result<Statistics> {
    let key = format!("api_user_stats:{user_id}");
    if let Some(stats) = cache::get::<Statistics>(ctx, settings, &key).await {
        return Ok(stats);
    }
    let since = day_ago_string();
    let row: (i64, i64, Option<NaiveDateTime>, Option<NaiveDateTime>) = sqlx::query_as(
        "SELECT
            (SELECT COUNT(*) FROM user_requests WHERE users_id = ? AND timestamp > ?),
            (SELECT COUNT(*) FROM user_downloads WHERE users_id = ? AND timestamp > ?),
            (SELECT MIN(timestamp) FROM user_requests WHERE users_id = ? AND timestamp > ?),
            (SELECT MIN(timestamp) FROM user_downloads WHERE users_id = ? AND timestamp > ?)",
    )
    .bind(user_id)
    .bind(&since)
    .bind(user_id)
    .bind(&since)
    .bind(user_id)
    .bind(&since)
    .bind(user_id)
    .bind(&since)
    .fetch_one(&ctx.db)
    .await
    .context("reading API usage")?;
    let stats = Statistics {
        api_count: row.0,
        grab_count: row.1,
        api_time: row.2,
        grab_time: row.3,
    };
    cache::put(ctx, settings, &key, 60, &stats).await;
    Ok(stats)
}

pub enum Reservation {
    /// Requests used in the window, including this one.
    Used(i64),
    Exhausted,
    /// Another request for this user held the lock too long.
    Busy,
}

/// Atomically checks the rolling 24-hour quota and records the request. Counts the table instead
/// of reading cached statistics, and holds a per-user lock so concurrent requests can't all take
/// the last slot. With Redis down it fails closed as busy, since nothing could hold the line.
pub async fn reserve(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    user: &User,
    audit: &str,
    ip: Option<&str>,
) -> Result<Reservation> {
    let name = format!("api_quota:{}", user.id);
    let Ok(Some(lock)) =
        cache::lock(ctx, settings, &name, QUOTA_LOCK_SECONDS, QUOTA_LOCK_WAIT).await
    else {
        return Ok(Reservation::Busy);
    };
    let outcome = reserve_locked(ctx, settings, user, audit, ip).await;
    lock.release(ctx).await;
    outcome
}

async fn reserve_locked(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    user: &User,
    audit: &str,
    ip: Option<&str>,
) -> Result<Reservation> {
    let used: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM user_requests WHERE users_id = ? AND timestamp > ?",
    )
    .bind(user.id)
    .bind(day_ago_string())
    .fetch_one(&ctx.db)
    .await?;
    if used >= user.api_requests {
        return Ok(Reservation::Exhausted);
    }
    record(ctx, settings, user.id, audit, ip).await?;
    Ok(Reservation::Used(used + 1))
}

/// Writes the durable request row, then updates access metadata in the background.
pub async fn record(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    user_id: i64,
    audit: &str,
    ip: Option<&str>,
) -> Result<()> {
    let occurred_at = now_string();
    sqlx::query("INSERT INTO user_requests (users_id, request, timestamp) VALUES (?, ?, ?)")
        .bind(user_id)
        .bind(audit)
        .bind(&occurred_at)
        .execute(&ctx.db)
        .await
        .context("recording an API request")?;
    let (ctx, settings, ip) = (ctx.clone(), settings.clone(), ip.map(str::to_owned));
    tokio::spawn(async move {
        if let Err(failure) =
            update_user_api_access(&ctx, &settings, user_id, ip.as_deref(), &occurred_at).await
        {
            warn!(
                user_id,
                error = format!("{failure:#}"),
                "updating API access failed"
            );
        }
    });
    Ok(())
}

/// The `UpdateUserApiAccess` job: records `apiaccess` and `host`, at most once per interval. A cache
/// failure must not prevent the update.
pub async fn update_user_api_access(
    ctx: &Ctx,
    settings: &Settings,
    user_id: i64,
    ip: Option<&str>,
    occurred_at: &str,
) -> Result<()> {
    let name = format!("api_access_update:{user_id}");
    if cache::add(ctx, settings, &name, settings.access_update_interval.max(1)).await == Some(false)
    {
        return Ok(());
    }
    match ip {
        Some(ip) => {
            sqlx::query("UPDATE users SET apiaccess = ?, host = ? WHERE id = ?")
                .bind(occurred_at)
                .bind(ip)
                .bind(user_id)
                .execute(&ctx.db)
                .await?;
        }
        None => {
            sqlx::query("UPDATE users SET apiaccess = ? WHERE id = ?")
                .bind(occurred_at)
                .bind(user_id)
                .execute(&ctx.db)
                .await?;
        }
    }
    Ok(())
}

/// The redacted entry for `user_requests.request`, for example `/api/v2/search?cat=5030&id=test`.
pub fn audit_entry(path: &str, input: &Input) -> String {
    let allowed: Vec<(String, Value)> = input
        .all()
        .into_iter()
        .filter(|(key, _)| AUDITED_PARAMETERS.contains(&key.as_str()))
        .collect();
    let mut parts = Vec::new();
    for (key, value) in canonicalize(&allowed) {
        match value {
            Value::Str(text) => {
                let cut: String = text.chars().take(AUDIT_VALUE_MAX_LENGTH).collect();
                parts.push(format!("{}={}", raw_url_encode(&key), raw_url_encode(&cut)));
            }
            // Empty parameters (`?id=`) stay visible.
            Value::Null => parts.push(format!("{}=", raw_url_encode(&key))),
            // Legacy array values (`o[]=json`) are skipped.
            Value::List(_) | Value::Map(_) => {}
        }
    }
    let mut entry = format!("/{}", path.trim_start_matches('/'));
    if !parts.is_empty() {
        entry.push('?');
        entry.push_str(&parts.join("&"));
    }
    entry.chars().take(AUDIT_MAX_LENGTH).collect()
}

/// `http_build_query` with `PHP_QUERY_RFC3986` for one component.
pub fn raw_url_encode(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    for byte in value.bytes() {
        match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(byte as char)
            }
            _ => out.push_str(&format!("%{byte:02X}")),
        }
    }
    out
}
