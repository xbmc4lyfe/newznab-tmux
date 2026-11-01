//! `ApiReleaseRowCache`: release rows cached for a few minutes, shared across users. User-specific
//! fields are added afterwards, so nothing here belongs to one requester.

use std::future::Future;

use anyhow::Result;
use md5::{Digest, Md5};
use nntmux_runtime::Ctx;
use rand::Rng;

use crate::cache;
use crate::rows::Row;
use crate::search::Found;
use crate::settings::Settings;

const TTL: u64 = 600;
const JITTER: u64 = 60;
const STALE_TTL: u64 = 900;
const LOCK_TTL: u64 = 15;

/// A search failed and no earlier rows could stand in. The caller answers 503.
#[derive(Debug, thiserror::Error)]
#[error("search is temporarily unavailable")]
pub struct Unavailable;

fn ttl() -> u64 {
    TTL + rand::rng().random_range(0..=JITTER)
}

async fn load<F, Fut>(ctx: &Ctx, settings: &Settings, key: &str, query: F) -> Result<Vec<Row>>
where
    F: FnOnce() -> Fut,
    Fut: Future<Output = Result<Found>>,
{
    let found = query().await?;
    if !found.degraded {
        cache::put(ctx, settings, key, ttl(), &found.rows).await;
        cache::put(
            ctx,
            settings,
            &format!("{key}:stale"),
            ttl().max(STALE_TTL),
            &found.rows,
        )
        .await;
        return Ok(found.rows);
    }
    // A failed search never caches: partial rows pass through, and empty ones can't be told from
    // "no matches", so the last known answer or a retryable error takes their place.
    if !found.rows.is_empty() {
        return Ok(found.rows);
    }
    match cache::get::<Vec<Row>>(ctx, settings, &format!("{key}:stale")).await {
        Some(stale) => Ok(stale),
        None => Err(Unavailable.into()),
    }
}

/// `parameters` is canonical JSON text naming everything the rows depend on.
pub async fn remember<F, Fut>(
    ctx: &Ctx,
    settings: &Settings,
    version: &str,
    scope: &str,
    parameters: &str,
    query: F,
) -> Result<Vec<Row>>
where
    F: FnOnce() -> Fut,
    Fut: Future<Output = Result<Found>>,
{
    let generation: i64 = cache::get(ctx, settings, "releases:cache_version")
        .await
        .unwrap_or(1);
    let digest = hex::encode(Md5::digest(format!("{generation}|{parameters}").as_bytes()));
    let key = format!("api_release_rows:{version}:{scope}:{digest}");
    if let Some(rows) = cache::get::<Vec<Row>>(ctx, settings, &key).await {
        return Ok(rows);
    }
    let lock = cache::lock(
        ctx,
        settings,
        &format!("{key}:lock"),
        LOCK_TTL,
        std::time::Duration::ZERO,
    )
    .await;
    if let Ok(Some(lock)) = lock {
        let rows = match cache::get::<Vec<Row>>(ctx, settings, &key).await {
            Some(rows) => Ok(rows),
            None => load(ctx, settings, &key, query).await,
        };
        lock.release(ctx).await;
        return rows;
    }
    if let Some(stale) = cache::get::<Vec<Row>>(ctx, settings, &format!("{key}:stale")).await {
        return Ok(stale);
    }
    load(ctx, settings, &key, query).await
}
