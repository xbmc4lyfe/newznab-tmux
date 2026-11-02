//! `anime:refresh`, `anime:migrate-covers` and `nntmux:populate-anidb`.

use std::fs;
use std::sync::Arc;

use anyhow::{Context, Result, bail};
use nntmux_runtime::Ctx;
use sqlx::Row;
use tracing::warn;

use super::title::extract_title;
use super::{TV_ANIME, anidbid_of, populate_batch, populate_by_id, set_release};
use crate::candidates::SETTLED;
use crate::php;
use crate::services::Services;

/// `anime:refresh` options.
#[derive(Clone, Debug, clap::Args)]
pub struct AnimeRefreshOptions {
    /// Maximum number of releases to process (0 = all).
    #[arg(long, default_value_t = 0)]
    pub limit: i64,
    /// Only refresh releases missing AniList data.
    #[arg(long)]
    pub missing_only: bool,
    /// Only refresh releases with anidbid <= 0 (failed processing).
    #[arg(long)]
    pub retry_failed: bool,
    /// Force refresh even if data exists.
    #[arg(long)]
    pub force: bool,
}

#[derive(Debug, Default, PartialEq, Eq)]
pub struct RefreshReport {
    pub processed: u64,
    pub successful: u64,
    pub failed: u64,
    pub not_found: u64,
    pub skipped: u64,
    pub rate_limited: bool,
}

async fn has_complete_info(services: &Services, anidbid: i64) -> Result<bool> {
    let found: Option<i32> = sqlx::query_scalar(
        "SELECT 1 FROM anidb_info WHERE anidbid = ? AND anilist_id IS NOT NULL \
         AND country IS NOT NULL AND media_type IS NOT NULL LIMIT 1",
    )
    .bind(anidbid)
    .fetch_optional(&services.db)
    .await?;
    Ok(found.is_some())
}

/// Fetches AniList data for existing anime releases by matching their searchname.
pub async fn refresh(
    services: &Arc<Services>,
    options: &AnimeRefreshOptions,
) -> Result<RefreshReport> {
    let mut statement = "SELECT CAST(r.id AS SIGNED) AS id, CAST(r.anidbid AS SIGNED) AS anidbid, \
         r.searchname FROM releases r "
        .to_owned();
    if options.missing_only {
        statement.push_str("LEFT JOIN anidb_info ai ON ai.anidbid = r.anidbid ");
    }
    statement.push_str(&format!(
        "WHERE r.categories_id = {TV_ANIME} AND r.{SETTLED}"
    ));
    if options.retry_failed {
        statement.push_str(" AND r.anidbid <= 0");
    }
    if options.missing_only {
        statement.push_str(" AND ai.anilist_id IS NULL");
    }
    statement.push_str(" ORDER BY r.id");
    if options.limit > 0 {
        statement.push_str(&format!(" LIMIT {}", options.limit));
    }
    let rows = sqlx::query(sqlx::AssertSqlSafe(statement))
        .fetch_all(&services.db)
        .await?;
    let mut report = RefreshReport::default();
    for row in rows {
        report.processed += 1;
        let id: i64 = row.try_get("id")?;
        let searchname: String = row.try_get("searchname")?;
        let current: Option<i64> = row.try_get("anidbid")?;
        let Some(title) = extract_title(&searchname) else {
            report.not_found += 1;
            continue;
        };
        if !options.force
            && !options.missing_only
            && !options.retry_failed
            && let Some(current) = current.filter(|current| *current > 0)
            && has_complete_info(services, current).await?
        {
            report.skipped += 1;
            continue;
        }
        match refresh_one(services, id, &searchname, &title).await {
            Ok(true) => report.successful += 1,
            Ok(false) => report.not_found += 1,
            Err(failure) => {
                let message = format!("{failure:#}");
                if message.contains("429") || message.contains("rate limit exceeded") {
                    report.rate_limited = true;
                    report.processed -= 1;
                    return Ok(report);
                }
                warn!(
                    release_id = id,
                    error = message,
                    "couldn't refresh an anime release"
                );
                report.failed += 1;
            }
        }
    }
    Ok(report)
}

async fn refresh_one(services: &Services, id: i64, searchname: &str, title: &str) -> Result<bool> {
    let results = services.anilist.search_anime(title, 1).await?;
    let anilist_id = results.first().map_or(0, |media| php::int(media.get("id")));
    if anilist_id == 0 {
        return Ok(false);
    }
    populate_by_id(services, anilist_id).await?;
    let anidbid = anidbid_of(services, anilist_id)
        .await?
        .unwrap_or(anilist_id);
    set_release(services, id, searchname, anidbid).await?;
    Ok(true)
}

/// `anime:refresh`.
pub async fn anime_refresh(ctx: &Ctx, options: AnimeRefreshOptions) -> Result<()> {
    let services = Services::shared(ctx)?;
    let report = refresh(&services, &options).await?;
    println!(
        "Processed {}: {} successful, {} failed, {} not found, {} skipped.",
        report.processed, report.successful, report.failed, report.not_found, report.skipped
    );
    if report.rate_limited {
        bail!("AniList API rate limit exceeded (429). Wait 15 minutes before running this again.");
    }
    Ok(())
}

/// `anime:migrate-covers`: renames `{id}.jpg` to `{id}-cover.jpg`. Returns renamed and skipped.
pub fn migrate_covers(covers: &std::path::Path, dry_run: bool) -> Result<(u64, u64)> {
    let directory = covers.join(super::KIND);
    let entries = fs::read_dir(&directory).with_context(|| {
        format!(
            "Anime covers directory does not exist: {}",
            directory.display()
        )
    })?;
    let (mut renamed, mut skipped) = (0, 0);
    for entry in entries {
        let entry = entry?;
        if !entry.file_type()?.is_file() {
            continue;
        }
        let name = entry.file_name();
        let Some(id) = name.to_str().and_then(|name| name.strip_suffix(".jpg")) else {
            continue;
        };
        if id.is_empty() || !id.bytes().all(|byte| byte.is_ascii_digit()) {
            continue;
        }
        let target = directory.join(format!("{id}-cover.jpg"));
        if target.exists() {
            println!("Skipping {id}.jpg - {id}-cover.jpg already exists");
            skipped += 1;
        } else if dry_run {
            println!("Would rename: {id}.jpg -> {id}-cover.jpg");
            renamed += 1;
        } else {
            fs::rename(entry.path(), &target)?;
            println!("Renamed: {id}.jpg -> {id}-cover.jpg");
            renamed += 1;
        }
    }
    Ok((renamed, skipped))
}

/// `nntmux:populate-anidb`. `--anilistid` is a flag in the PHP, so it never took a value there.
pub async fn populate_anidb(ctx: &Ctx, info: bool, anilist_id: Option<i64>) -> Result<()> {
    if !info {
        bail!("Please specify --info option");
    }
    let services = Services::shared(ctx)?;
    match anilist_id {
        Some(id) => populate_by_id(&services, id).await?,
        None => populate_batch(&services).await?,
    }
    println!("AniList tables populated with requested data");
    Ok(())
}
