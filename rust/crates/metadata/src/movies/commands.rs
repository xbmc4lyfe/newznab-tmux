//! `movie:reverify-matches`.

use std::sync::Arc;

use anyhow::{Result, bail};
use nntmux_runtime::Ctx;
use sqlx::Row;

use super::{MovieLookup, md5_hex};
use crate::php::mb_substr;
use crate::services::Services;
use crate::settings::MetadataSettings;

/// `movie:reverify-matches` options.
#[derive(Clone, Debug, Default, clap::Args)]
pub struct MovieReverifyOptions {
    /// Only report mismatches without changing anything.
    #[arg(long)]
    pub dry_run: bool,
    /// Maximum number of releases to check.
    #[arg(long)]
    pub limit: Option<i64>,
}

#[derive(Debug, Default, PartialEq, Eq)]
pub struct ReverifyReport {
    pub checked: i64,
    pub skipped: i64,
    pub mismatched: Vec<i64>,
}

/// Re-verifies movie releases' IMDb matches against the title and year in their searchname.
pub async fn reverify_matches(
    services: Arc<Services>,
    options: &MovieReverifyOptions,
) -> Result<ReverifyReport> {
    let db = services.db.clone();
    let settings = MetadataSettings::load(&db).await?;
    let mut lookup = MovieLookup::new(services.clone(), settings);
    let limit = options.limit.filter(|limit| *limit > 0);
    let mut report = ReverifyReport::default();
    let mut last = 0_i64;
    'pages: loop {
        let rows = sqlx::query(
            "SELECT CAST(id AS SIGNED) AS id, searchname, imdbid FROM releases \
             WHERE categories_id BETWEEN 2000 AND 2999 AND imdbid IS NOT NULL AND imdbid <> '' \
             AND imdbid NOT IN ('0', '0000000', '00000000') AND id > ? ORDER BY id LIMIT 500",
        )
        .bind(last)
        .fetch_all(&db)
        .await?;
        if rows.is_empty() {
            break;
        }
        for row in rows {
            if limit.is_some_and(|limit| report.checked >= limit) {
                break 'pages;
            }
            let id: i64 = row.try_get("id")?;
            let searchname: String = row.try_get("searchname")?;
            let imdb: String = row.try_get("imdbid")?;
            last = id;
            report.checked += 1;
            match lookup.verify_match(&searchname, &imdb).await? {
                None => report.skipped += 1,
                Some(false) => {
                    println!(
                        "Mismatch: release {id} ({}) matched to tt{imdb}",
                        mb_substr(&searchname, 100)
                    );
                    report.mismatched.push(id);
                }
                Some(true) => {}
            }
        }
    }
    println!(
        "Checked {} movie releases, skipped {} unverifiable, found {} mismatches.",
        report.checked,
        report.skipped,
        report.mismatched.len()
    );
    if options.dry_run {
        if !report.mismatched.is_empty() {
            println!("Dry run: no changes made.");
        }
        return Ok(report);
    }
    for chunk in report.mismatched.chunks(100) {
        let list = chunk
            .iter()
            .map(i64::to_string)
            .collect::<Vec<_>>()
            .join(",");
        let statement =
            format!("UPDATE releases SET imdbid = NULL, movieinfo_id = NULL WHERE id IN ({list})");
        sqlx::query(sqlx::AssertSqlSafe(statement))
            .execute(&db)
            .await?;
        services.sync_releases(chunk).await;
    }
    if !report.mismatched.is_empty() {
        println!(
            "Reset imdbid/movieinfo_id for {} mismatched releases.",
            report.mismatched.len()
        );
    }
    Ok(report)
}

/// `nntmux:fetch-movie`: force fetches a movie's data, clearing what's cached for it.
pub async fn fetch_movie(services: Arc<Services>, raw: &str) -> Result<Option<(String, String)>> {
    let lower = raw.trim().to_lowercase();
    let imdb: String = lower
        .strip_prefix("tt")
        .unwrap_or(&lower)
        .chars()
        .filter(char::is_ascii_digit)
        .collect();
    if imdb.len() < 5 {
        bail!("Invalid IMDb id provided: {raw} (parsed: {imdb})");
    }
    let cache = &services.cache;
    cache
        .forget(&format!("tmdb_movie_{}", md5_hex(&format!("tt{imdb}"))))
        .await;
    cache
        .forget(&format!("trakt_movie_{}", md5_hex(&imdb)))
        .await;
    cache
        .forget(&format!("imdb_movie_{}", md5_hex(&imdb)))
        .await;
    cache.forget(&format!("imdb_scrape_id_{imdb}")).await;
    cache
        .forget(&format!("omdb_movie_{}", md5_hex(&imdb)))
        .await;
    let settings = MetadataSettings::load(&services.db).await?;
    let mut lookup = MovieLookup::new(services.clone(), settings);
    if !lookup.update_movie_info(&imdb).await? {
        bail!("Failed to fetch/update movie data for tt{imdb}.");
    }
    Ok(
        sqlx::query_as("SELECT title, year FROM movieinfo WHERE imdbid = ?")
            .bind(&imdb)
            .fetch_optional(&services.db)
            .await?,
    )
}

/// `nntmux:fetch-movie`.
pub async fn fetch_movie_command(ctx: &Ctx, imdb: &str) -> Result<()> {
    let Some((title, year)) = fetch_movie(Services::shared(ctx)?, imdb).await? else {
        bail!("Movie info not found after update for {imdb}.");
    };
    println!("Updated movie: {title} ({year})");
    Ok(())
}

/// `movie:reverify-matches`.
pub async fn movie_reverify_matches(ctx: &Ctx, options: MovieReverifyOptions) -> Result<()> {
    reverify_matches(Services::shared(ctx)?, &options)
        .await
        .map(|_| ())
}
