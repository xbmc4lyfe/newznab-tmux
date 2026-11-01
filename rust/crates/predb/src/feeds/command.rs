//! `predb:import-feed`.

use std::time::Duration;

use anyhow::{Result, anyhow, bail};
use chrono::{DateTime, NaiveDate, NaiveDateTime, TimeDelta, Utc};
use nntmux_runtime::Ctx;

use super::http::FeedHttp;
use super::importer::{Importer, Totals};
use super::index::PredbIndex;
use super::{
    Entry, FeedConfig, FeedError, FeedResult, FeedSource, MANUAL_ONLY_SOURCES, SINGLE_PAGE_SOURCES,
    sources,
};

#[derive(Debug, Clone, clap::Args)]
pub struct ImportArgs {
    /// Source keys to poll. Defaults to PREDB_FEED_SOURCES.
    #[arg(long)]
    pub source: Vec<String>,
    /// Pages per source, newest first.
    #[arg(long, default_value_t = 1)]
    pub pages: u32,
    /// History mode: page back until entries are older than this (14d, 36h or a date).
    #[arg(long)]
    pub since: Option<String>,
    /// Cap on pages per source in history mode. Defaults to PREDB_FEED_MAX_PAGES.
    #[arg(long)]
    pub max_pages: Option<u32>,
    /// Fetch and report without writing to the database.
    #[arg(long)]
    pub dry_run: bool,
    #[arg(long)]
    pub quiet: bool,
}

/// One source's line in the summary.
#[derive(Debug, PartialEq)]
pub struct SourceReport {
    pub key: String,
    pub totals: Totals,
    pub status: String,
}

pub async fn run(ctx: &Ctx, args: ImportArgs) -> Result<bool> {
    let config = FeedConfig::from_env();
    let index = if args.dry_run {
        PredbIndex::disabled()
    } else {
        PredbIndex::new(&ctx.config.manticore)
    };
    let importer = Importer::new(ctx.db.clone(), index);
    let reports = import(&config, importer, &args).await?;
    if !args.quiet {
        println!(
            "{:<20} {:>8} {:>8} {:>8}  Status",
            "Source", "Inserted", "Updated", "Skipped"
        );
        for report in &reports {
            println!(
                "{:<20} {:>8} {:>8} {:>8}  {}",
                report.key,
                report.totals.inserted,
                report.totals.updated,
                report.totals.skipped,
                report.status
            );
        }
        if args.dry_run {
            println!("Dry run: nothing was written.");
        }
    }
    Ok(reports
        .iter()
        .any(|report| !report.status.starts_with("failed")))
}

/// Runs the import over the selected sources. Errors only for bad arguments; a source that
/// fails is reported in its status so the others still run.
pub async fn import(
    config: &FeedConfig,
    mut importer: Importer,
    args: &ImportArgs,
) -> Result<Vec<SourceReport>> {
    let since = match args.since.as_deref().filter(|value| !value.is_empty()) {
        Some(value) => Some(parse_since(value, Utc::now()).ok_or_else(|| {
            anyhow!("Invalid --since value; use e.g. 14d, 36h or a date such as 2026-09-25.")
        })?),
        None => None,
    };
    let pages = if since.is_some() {
        args.max_pages.unwrap_or(config.max_pages).max(1)
    } else {
        args.pages.max(1)
    };
    let keys = if args.source.is_empty() {
        config
            .sources
            .iter()
            .filter(|key| {
                let manual = MANUAL_ONLY_SOURCES.contains(&key.as_str());
                if manual {
                    tracing::warn!(
                        source = key.as_str(),
                        "Manual-only catalogue excluded from automatic polling"
                    );
                }
                !manual
            })
            .cloned()
            .collect::<Vec<_>>()
    } else {
        args.source
            .iter()
            .filter(|key| !key.is_empty())
            .cloned()
            .collect()
    };
    if keys.is_empty() {
        bail!("No PreDB feed sources configured (PREDB_FEED_SOURCES).");
    }
    let http = FeedHttp::new(config)?;
    let mut seen = Vec::new();
    let mut built: Vec<Box<dyn FeedSource>> = Vec::new();
    for key in keys {
        if !seen.contains(&key) {
            built.push(sources::make(&key, config, &http)?);
            seen.push(key);
        }
    }
    let mut reports = Vec::new();
    for mut source in built {
        reports.push(
            run_source(
                config,
                source.as_mut(),
                &mut importer,
                args.dry_run,
                pages,
                since,
            )
            .await,
        );
    }
    Ok(reports)
}

async fn run_source(
    config: &FeedConfig,
    source: &mut dyn FeedSource,
    importer: &mut Importer,
    dry_run: bool,
    pages: u32,
    since: Option<NaiveDateTime>,
) -> SourceReport {
    let key = source.key().to_owned();
    let single_page = SINGLE_PAGE_SOURCES.contains(&key.as_str());
    let mut totals = Totals::default();
    let outcome = page_through(
        config,
        source,
        importer,
        dry_run,
        if single_page { 1 } else { pages },
        since,
        &mut totals,
    )
    .await;
    let status = match outcome {
        Err(error) => {
            tracing::warn!(source = key, %error, "PreDB feed source failed");
            format!("failed: {error:#}")
        }
        Ok((visited, reached_cutoff)) => {
            let mut status = "ok".to_owned();
            if let Some(accepted) = source.accepted_count()
                && visited < accepted
            {
                status = format!(
                    "incomplete: processed {visited} of {accepted} catalogue entries; increase --pages"
                );
            }
            if let Some(since) = since
                && (single_page || !reached_cutoff)
            {
                // Latest-only listings can't verify historical coverage, and others may have
                // run out of history or hit the page cap.
                status = if source.accepted_count().is_some() {
                    "incomplete: catalogue dates are unknown; --since cannot verify coverage"
                        .to_owned()
                } else if single_page {
                    "incomplete: history paging is disabled for this source".to_owned()
                } else {
                    format!("incomplete: history ended before {}", since.date())
                };
                tracing::warn!(source = key, %since, "PreDB history import incomplete");
            }
            status
        }
    };
    SourceReport {
        key,
        totals,
        status,
    }
}

async fn page_through(
    config: &FeedConfig,
    source: &mut dyn FeedSource,
    importer: &mut Importer,
    dry_run: bool,
    pages: u32,
    since: Option<NaiveDateTime>,
    totals: &mut Totals,
) -> Result<(usize, bool)> {
    let mut visited = 0;
    for page in 1..=pages {
        if page > 1 {
            tokio::time::sleep(Duration::from_millis(config.request_delay_ms)).await;
        }
        let entries = fetch_with_backoff(config, source, page).await?;
        if entries.is_empty() {
            break;
        }
        visited += entries.len();
        let imported = importer.import(&entries, dry_run).await?;
        totals.inserted += imported.inserted;
        totals.updated += imported.updated;
        totals.skipped += imported.skipped;
        if let Some(since) = since {
            let Some(oldest) = oldest_predate(&entries) else {
                // Undated entries can't page back: stop, unverified.
                break;
            };
            if oldest < since {
                return Ok((visited, true));
            }
        }
    }
    Ok((visited, false))
}

/// Fetches one page, waiting and retrying when the source is throttled. The server's delay is
/// never shortened, and a delay over the maximum fails this run instead of blocking it.
async fn fetch_with_backoff(
    config: &FeedConfig,
    source: &mut dyn FeedSource,
    page: u32,
) -> Result<Vec<Entry>> {
    let mut attempt = 0;
    loop {
        let fetched: FeedResult<Vec<Entry>> = source.fetch(page).await;
        match fetched {
            Ok(entries) => return Ok(entries),
            Err(FeedError::Failed(error)) => return Err(error),
            Err(FeedError::RateLimited {
                message,
                retry_after,
                reset_at,
            }) => {
                if attempt >= config.rate_limit_retries {
                    bail!("{message}");
                }
                let wait = rate_limit_wait_seconds(
                    retry_after.as_deref(),
                    reset_at.as_deref(),
                    config.rate_limit_wait_seconds,
                    Utc::now(),
                );
                if wait > config.rate_limit_max_wait_seconds {
                    bail!(
                        "rate limited for {wait}s (more than the {}s maximum wait)",
                        config.rate_limit_max_wait_seconds
                    );
                }
                tracing::info!(
                    source = source.key(),
                    page,
                    wait_seconds = wait,
                    "PreDB feed rate limited; backing off"
                );
                tokio::time::sleep(Duration::from_secs(wait)).await;
                attempt += 1;
            }
        }
    }
}

/// `Retry-After` (delta-seconds or HTTP-date), else an epoch `X-RateLimit-Reset`, else the default.
pub fn rate_limit_wait_seconds(
    retry_after: Option<&str>,
    reset_at: Option<&str>,
    default: u64,
    now: DateTime<Utc>,
) -> u64 {
    let digits = |value: &str| !value.is_empty() && value.bytes().all(|b| b.is_ascii_digit());
    let retry_after = retry_after.unwrap_or("").trim();
    if digits(retry_after) {
        return retry_after.parse().unwrap_or(u64::MAX);
    }
    if !retry_after.is_empty()
        && let Ok(date) = DateTime::parse_from_rfc2822(retry_after)
    {
        return (date.timestamp() - now.timestamp()).max(0) as u64;
    }
    let reset_at = reset_at.unwrap_or("").trim();
    if digits(reset_at) {
        let reset: i64 = reset_at.parse().unwrap_or(i64::MAX);
        return reset.saturating_sub(now.timestamp()).max(0) as u64;
    }
    default
}

fn oldest_predate(entries: &[Entry]) -> Option<NaiveDateTime> {
    entries.iter().filter_map(Entry::paging_time).min()
}

/// `14d`, `36h` or a strict `Y-m-d` date.
pub fn parse_since(value: &str, now: DateTime<Utc>) -> Option<NaiveDateTime> {
    let value = value.trim();
    let lower = value.to_ascii_lowercase();
    if let Some(unit) = lower.chars().last()
        && matches!(unit, 'd' | 'h')
    {
        let number = lower[..lower.len() - 1].trim_end();
        if !number.is_empty() && number.bytes().all(|b| b.is_ascii_digit()) {
            let amount: i64 = number.parse().ok()?;
            let delta = if unit == 'd' {
                TimeDelta::try_days(amount)?
            } else {
                TimeDelta::try_hours(amount)?
            };
            return now.naive_utc().checked_sub_signed(delta);
        }
    }
    let shaped = value.len() == 10
        && value.bytes().enumerate().all(|(i, b)| {
            if i == 4 || i == 7 {
                b == b'-'
            } else {
                b.is_ascii_digit()
            }
        });
    if !shaped {
        return None;
    }
    NaiveDate::parse_from_str(value, "%Y-%m-%d")
        .ok()?
        .and_hms_opt(0, 0, 0)
}
