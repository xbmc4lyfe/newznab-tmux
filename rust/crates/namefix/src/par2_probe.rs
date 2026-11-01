//! The two callers of a PAR2 check inside name fixing: the standard batch's per-release probe,
//! and `releases:fix-names 7|8`, the historical sweep with its cursor and budget.

use std::sync::Arc;
use std::time::{Duration, Instant};

use anyhow::{Context, Result, anyhow};
use async_trait::async_trait;
use redis::AsyncCommands;

use crate::par2_check::Par2Checker;
use crate::par2_lock::{RedisLock, Refresh};
use crate::queries::{self, Source};
use crate::release::Release;
use crate::service::{Par2Outcome, Par2Probe};

/// `releases:fix-names-group standard`'s `$par2Processor`: connects once on first use, and
/// answers retryable for every release when it cannot.
pub struct BatchProbe {
    checker: Par2Checker,
    connection: Option<Result<(), String>>,
}

impl BatchProbe {
    pub fn new(checker: Par2Checker) -> Self {
        Self {
            checker,
            connection: None,
        }
    }
}

#[async_trait]
impl Par2Probe for BatchProbe {
    async fn probe(&mut self, release: &Release) -> Result<Par2Outcome> {
        if self.connection.is_none() {
            let connected = self.checker.articles.connect().await;
            if let Err(error) = &connected {
                tracing::warn!(error, "Unable to connect to usenet for PAR2 processing");
            }
            self.connection = Some(connected);
        }
        if matches!(self.connection, Some(Err(_))) {
            return Ok(Par2Outcome::Retryable);
        }
        let Some(guid) = release.guid.as_deref() else {
            return Ok(Par2Outcome::Deferred);
        };
        let (outcome, _) = self
            .checker
            .check(guid, release.id, release.groups_id, 1, None, None)
            .await;
        Ok(outcome)
    }
}

/// The sweep's claim: renewed by every release's check along with its own.
struct SweepLock(RedisLock);

#[async_trait]
impl Refresh for SweepLock {
    async fn refresh(&self) -> bool {
        self.0.refresh().await
    }
}

/// `NameFixingService::fixNamesWithPar2`: a bounded pass over releases whose PAR2 work is
/// pending, resuming from a Redis cursor so each run continues where the last stopped. Needs
/// `--update` and `--set-status` because it downloads.
pub async fn fix_names_with_par2(
    checker: &mut Par2Checker,
    time: i32,
    write: bool,
    categories: i32,
    status: bool,
) -> Result<()> {
    println!(
        "Fixing search names {} using par2 files.",
        if time == 1 {
            "in the past 6 hours"
        } else {
            "since the beginning"
        }
    );
    if !write || !status {
        println!("PAR2 downloads require --update and --set-status.");
        return Ok(());
    }
    let config = checker.config.clone();
    let db = checker.fixer.updater.db.clone();
    let mut redis = checker.redis.clone();
    let deadline = Instant::now() + Duration::from_secs(config.par2_budget_seconds.max(1));
    let key = format!(
        "{}rename:par2:sweep:{time}:{categories}",
        checker.settings.key_prefix
    );
    let socket = std::env::var("NNTP_SOCKET_TIMEOUT")
        .ok()
        .and_then(|v| v.trim().parse::<u64>().ok())
        .unwrap_or(120)
        .max(
            std::env::var("NNTP_SOCKET_TIMEOUT_A")
                .ok()
                .and_then(|v| v.trim().parse::<u64>().ok())
                .unwrap_or(120),
        );
    let lock = RedisLock::new(redis.clone(), format!("{key}:lock"), 300.max(2 * socket));
    if !lock.acquire().await? {
        println!("Another PAR2 sweep is running.");
        return Ok(());
    }
    let result = sweep(
        checker, &config, &db, &mut redis, &lock, &key, time, categories, deadline,
    )
    .await;
    if let Err(error) = lock.release().await {
        tracing::warn!(%error, "Could not release the PAR2 sweep claim; it will expire");
    }
    let (outcomes, probes, received, local_skips, regular, inspected) = match result {
        Ok(totals) => totals,
        Err(error) => {
            tracing::warn!(%error, "PAR2 rename sweep stopped with work pending");
            return Err(error);
        }
    };
    tracing::info!(
        time_scope = time,
        category_scope = categories,
        ?outcomes,
        candidates_probed = probes,
        articles_received = received,
        local_skips,
        regular_attempted = regular,
        inspected,
        "PAR2 rename batch finished"
    );
    println!(
        "PAR2 outcomes: renamed={} no_match={} retryable={} deferred={}",
        outcomes[0], outcomes[1], outcomes[2], outcomes[3]
    );
    println!(
        "{} releases have had their names changed out of: {} releases attempted.",
        checker.fixer.updater.fixed, checker.fixer.updater.checked
    );
    Ok(())
}

type Totals = ([u64; 4], i64, i64, usize, usize, usize);

#[allow(clippy::too_many_arguments)]
async fn sweep(
    checker: &mut Par2Checker,
    config: &queries::FixNamesConfig,
    db: &sqlx::MySqlPool,
    redis: &mut redis::aio::ConnectionManager,
    lock: &RedisLock,
    key: &str,
    time: i32,
    categories: i32,
    deadline: Instant,
) -> Result<Totals> {
    // renamed, no_match, retryable, deferred.
    let mut outcomes = [0u64; 4];
    let (mut probes, mut received) = (0i64, 0i64);
    let (mut regular, mut local_skips, mut inspected) = (0usize, 0usize, 0usize);
    let cursor: Option<String> = redis.get(key).await.unwrap_or(None);
    let cursor: Option<serde_json::Value> =
        cursor.and_then(|text| serde_json::from_str(&text).ok());
    let number = |name: &str| {
        cursor
            .as_ref()
            .and_then(|c| c.get(name))
            .and_then(serde_json::Value::as_i64)
            .unwrap_or(0)
            .max(0)
    };
    let mut after_id = number("after_id");
    let mut through_id = if cursor.is_some() {
        number("through_id")
    } else {
        queries::high_watermark(db).await?
    };
    let limit = config.par2_max_releases.max(1);
    let max_local_skips = config.par2_max_local_skips;
    let mut first_page = true;
    let sweep_claim: Arc<dyn Refresh> = Arc::new(SweepLock(lock.clone()));
    'pages: while regular < limit {
        if Instant::now() >= deadline || !lock.refresh().await {
            break;
        }
        let page_limit = queries::BATCH_SIZE.min(limit - regular);
        let releases = queries::candidate_batch(
            db,
            config,
            Source::Par2,
            time,
            categories,
            after_id,
            page_limit,
            Some(through_id),
        )
        .await?;
        if releases.is_empty() {
            if !first_page || after_id == 0 || Instant::now() >= deadline || !lock.refresh().await {
                break;
            }
            after_id = 0;
            through_id = queries::high_watermark(db).await?;
            first_page = false;
            continue;
        }
        first_page = false;
        for release in &releases {
            if regular >= limit || Instant::now() >= deadline || !lock.refresh().await {
                break 'pages;
            }
            let guid = release
                .guid
                .as_deref()
                .ok_or_else(|| anyhow!("release {} has no guid", release.id))?;
            // The renames this checker applies are counted by its own updater, as PHP's were by
            // the PAR2 service's; the sweep only adds a rename that wasn't a name write.
            let fixed_before = checker.fixer.updater.fixed;
            let (outcome, stats) = checker
                .check(
                    guid,
                    release.id,
                    release.groups_id,
                    1,
                    Some(deadline),
                    Some(sweep_claim.clone()),
                )
                .await;
            outcomes[match outcome {
                Par2Outcome::Renamed => 0,
                Par2Outcome::NoMatch => 1,
                Par2Outcome::Retryable => 2,
                Par2Outcome::Deferred => 3,
            }] += 1;
            probes += stats.candidates_probed;
            received += stats.articles_received;
            inspected += 1;
            if outcome == Par2Outcome::Retryable
                && stats.candidates_probed == 0
                && stats.articles_received == 0
                && local_skips < max_local_skips
            {
                local_skips += 1;
            } else {
                regular += 1;
            }
            if outcome == Par2Outcome::Renamed && checker.fixer.updater.fixed == fixed_before {
                checker.fixer.updater.fixed += 1;
            }
            checker.fixer.updater.increment_checked();
            if !lock.refresh().await {
                break 'pages;
            }
            after_id = release.id;
            let cursor = serde_json::json!({"after_id": after_id, "through_id": through_id});
            let _: () = redis
                .set(key, cursor.to_string())
                .await
                .context("saving the PAR2 sweep cursor")?;
        }
    }
    Ok((outcomes, probes, received, local_skips, regular, inspected))
}
