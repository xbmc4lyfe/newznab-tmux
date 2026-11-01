//! Resets and table maintenance. Every release a reset changes in the search index goes through the
//! outbox the search role drains, so a crash partway through loses no update.

use std::path::Path;

use anyhow::{Result, bail};
use nntmux_categorize::cat;
use nntmux_core::var_or;
use nntmux_verify::context::Verify;
use nntmux_verify::releases::{delete_single_on, request_search_update};
use sqlx::{AssertSqlSafe, MySqlConnection, MySqlPool};
use tracing::warn;

const CHUNK: usize = 1000;
const TV_RESET: &str =
    "videos_id = 0, tv_episodes_id = 0, tv_lookup_attempts = 0, tv_lookup_retry_at = NULL";
const BOOK_RESET: &str = "bookinfo_id = NULL, book_lookup_attempts = 0, book_lookup_retry_at = NULL, book_name_normalized_at = NULL";
const ARCHIVE_RESET: &str = "passwordstatus = -1, haspreview = -1, archive_retry_at = NULL, jpgstatus = 0, videostatus = 0, nfostatus = -1";
const GROUP_RESET: &str = "UPDATE usenet_groups SET first_record = 0, first_record_postdate = NULL, \
     last_record = 0, last_record_postdate = NULL, last_updated = NULL";

const CATEGORIES: [&str; 8] = [
    "music", "console", "movie", "game", "tv", "adult", "book", "misc",
];

/// Tables `nntmux:resetdb` empties, in the order PHP did.
const RESET_TABLES: [&str; 41] = [
    "binaries",
    "collections",
    "collection_groups",
    "parts",
    "missed_parts",
    "videos",
    "videos_aliases",
    "tv_episodes",
    "tv_info",
    "release_nfos",
    "nfo_cleanup_commits",
    "release_comments",
    "users_releases",
    "user_movies",
    "user_series",
    "movieinfo",
    "musicinfo",
    "bookinfo",
    "consoleinfo",
    "gamesinfo",
    "release_files",
    "audio_data",
    "release_subtitles",
    "video_data",
    "media_infos",
    "par_hashes",
    "par_file_hashes",
    "par_file_hash_inventories",
    "par2_descriptor_evidence",
    "par2_hash_retry",
    "par2_rename_candidate_progress",
    "par2_rename_progress",
    "release_regexes",
    "release_unique",
    "release_reports",
    "release_nzb_creation_failures",
    "release_nzb_passwords",
    "releases",
    "anidb_titles",
    "anidb_info",
    "releases_groups",
];

pub const RESET_INDEXES: [&str; 9] = [
    "releases_rt",
    "movies_rt",
    "tvshows_rt",
    "music_rt",
    "books_rt",
    "games_rt",
    "console_rt",
    "steam_rt",
    "anime_rt",
];

/// Whether a destructive reset may run here. Laravel's `environment()` defaulted to production.
pub fn is_local() -> bool {
    var_or("APP_ENV", "production") == "local"
}

/// Splits comma or space separated values, keeps what follows a `=`, lowercases, and folds plurals
/// of known categories.
pub fn normalize_categories(raw: &[String]) -> Vec<String> {
    let mut normalized = Vec::new();
    for option in raw {
        for token in option.split(|c: char| c == ',' || c.is_whitespace()) {
            let token = token
                .rsplit('=')
                .next()
                .unwrap_or_default()
                .trim()
                .to_lowercase();
            let singular = token.trim_end_matches('s');
            let token = if CATEGORIES.contains(&singular) {
                singular.to_owned()
            } else {
                token
            };
            if !token.is_empty() && !normalized.contains(&token) {
                normalized.push(token);
            }
        }
    }
    normalized
}

/// Applies `set` to every release matching `condition`, a chunk at a time. Conditions and settings
/// are fixed strings from this file. Each chunk commits with its search updates.
async fn reset_releases(
    db: &MySqlPool,
    condition: &str,
    bindings: &[i64],
    set: &str,
    search: bool,
) -> Result<u64> {
    let mut cursor = 0i64;
    let mut total = 0;
    loop {
        let select = format!(
            "SELECT CAST(id AS SIGNED) FROM releases WHERE id > ? AND ({condition}) ORDER BY id LIMIT {CHUNK}"
        );
        let mut query = sqlx::query_scalar::<_, i64>(AssertSqlSafe(select)).bind(cursor);
        for binding in bindings {
            query = query.bind(*binding);
        }
        let ids = query.fetch_all(db).await?;
        let Some(last) = ids.last().copied() else {
            return Ok(total);
        };
        let list = ids.iter().map(i64::to_string).collect::<Vec<_>>().join(",");
        let mut tx = db.begin().await?;
        sqlx::query(AssertSqlSafe(format!(
            "UPDATE releases SET {set} WHERE id IN ({list})"
        )))
        .execute(&mut *tx)
        .await?;
        if search {
            for id in &ids {
                request_search_update(&mut tx, *id).await?;
            }
        }
        tx.commit().await?;
        total += ids.len() as u64;
        cursor = last;
    }
}

/// `nntmux:resetpp` with no category.
pub async fn reset_all_postprocessing(db: &MySqlPool) -> Result<u64> {
    let set = format!(
        "consoleinfo_id = NULL, gamesinfo_id = NULL, imdbid = NULL, movieinfo_id = NULL, musicinfo_id = NULL, \
         {BOOK_RESET}, {TV_RESET}, {ARCHIVE_RESET}"
    );
    reset_releases(db, "1 = 1", &[], &set, true).await
}

/// One category of `nntmux:resetpp`. `adult` is accepted and does nothing, as it always has.
pub async fn reset_category(db: &MySqlPool, category: &str) -> Result<u64> {
    let range = "categories_id BETWEEN ? AND ?";
    let between = |low: i32, high: i32| [i64::from(low), i64::from(high)];
    match category {
        "console" => {
            reset_releases(
                db,
                &format!("consoleinfo_id IS NOT NULL AND {range}"),
                &between(cat::GAME_ROOT, cat::GAME_OTHER),
                "consoleinfo_id = NULL",
                false,
            )
            .await
        }
        "movie" => {
            reset_releases(
                db,
                &format!("movieinfo_id IS NOT NULL AND {range}"),
                &between(cat::MOVIE_ROOT, cat::MOVIE_OTHER),
                "movieinfo_id = NULL, imdbid = NULL",
                true,
            )
            .await
        }
        "game" => {
            reset_releases(
                db,
                "gamesinfo_id IS NOT NULL AND categories_id = ?",
                &[i64::from(cat::PC_GAMES)],
                "gamesinfo_id = NULL",
                false,
            )
            .await
        }
        "book" => {
            reset_releases(
                db,
                &format!("bookinfo_id IS NOT NULL AND {range}"),
                &between(cat::BOOKS_ROOT, cat::BOOKS_UNKNOWN),
                BOOK_RESET,
                false,
            )
            .await
        }
        "music" => {
            reset_releases(
                db,
                &format!("musicinfo_id IS NOT NULL AND {range}"),
                &between(cat::MUSIC_ROOT, cat::MUSIC_OTHER),
                "musicinfo_id = NULL",
                false,
            )
            .await
        }
        "tv" => {
            reset_releases(
                db,
                &format!(
                    "{range} AND (videos_id != 0 OR tv_episodes_id != 0 OR tv_lookup_attempts > 0)"
                ),
                &between(cat::TV_ROOT, cat::TV_OTHER),
                TV_RESET,
                true,
            )
            .await
        }
        "misc" => {
            reset_releases(
                db,
                range,
                &between(cat::OTHER_ROOT, cat::OTHER_HASHED),
                ARCHIVE_RESET,
                true,
            )
            .await
        }
        "adult" => Ok(0),
        other => bail!("Unknown category option: {other}"),
    }
}

/// `nntmux:resetpp`: the categories to reset, or all postprocessing when none are given.
pub async fn reset_postprocessing(db: &MySqlPool, raw: &[String]) -> Result<u64> {
    // Only misc may be reset outside local, judged by the first value as given.
    if !is_local() && raw.first().is_none_or(|first| first != "misc") {
        bail!("This command can only be run in local environment");
    }
    let categories = normalize_categories(raw);
    let invalid: Vec<&str> = categories
        .iter()
        .map(String::as_str)
        .filter(|category| !CATEGORIES.contains(category) && *category != "all")
        .collect();
    if !invalid.is_empty() {
        bail!(
            "Unknown category option(s): {}. Allowed: {} (or omit --category to reset all).",
            invalid.join(", "),
            CATEGORIES.join(", ")
        );
    }
    if categories.is_empty() || categories.iter().any(|category| category == "all") {
        // PHP's `all` re-entered the command with no category, so its guard then refused it.
        if !is_local() {
            bail!("This command can only be run in local environment");
        }
        return reset_all_postprocessing(db).await;
    }
    let mut total = 0;
    for category in &categories {
        total += reset_category(db, category).await?;
    }
    Ok(total)
}

pub enum TvShowReset {
    Reset { title: String, releases: u64 },
    WouldReset { title: String, releases: u64 },
    NoReleases,
}

/// `nntmux:reset-tv-show`.
pub async fn reset_tv_show(db: &MySqlPool, videos_id: i64, dry_run: bool) -> Result<TvShowReset> {
    let title: Option<String> =
        sqlx::query_scalar("SELECT title FROM videos WHERE id = ? AND type = 0")
            .bind(videos_id)
            .fetch_optional(db)
            .await?;
    let Some(title) = title else {
        bail!("videos_id {videos_id} not found or is not a TV show (type=0)");
    };
    let condition = "videos_id = ? AND categories_id BETWEEN ? AND ?";
    let bindings = [videos_id, i64::from(cat::TV_ROOT), i64::from(cat::TV_OTHER)];
    if dry_run {
        let releases: i64 = sqlx::query_scalar(AssertSqlSafe(format!(
            "SELECT COUNT(*) FROM releases WHERE {condition}"
        )))
        .bind(bindings[0])
        .bind(bindings[1])
        .bind(bindings[2])
        .fetch_one(db)
        .await?;
        return Ok(if releases == 0 {
            TvShowReset::NoReleases
        } else {
            TvShowReset::WouldReset {
                title,
                releases: releases as u64,
            }
        });
    }
    let releases = reset_releases(db, condition, &bindings, TV_RESET, true).await?;
    Ok(if releases == 0 {
        TvShowReset::NoReleases
    } else {
        TvShowReset::Reset { title, releases }
    })
}

/// `nntmux:remove-bad`: deletes releases that failed post-processing and marks those with passworded
/// files. Returns `(removed, marked)`. Releases already marked are skipped: PHP rewrote and reindexed
/// every one of them each hour, with the same result.
pub async fn remove_bad(db: &MySqlPool) -> Result<(u64, u64)> {
    let bad: Vec<(i64, String)> =
        sqlx::query_as("SELECT CAST(id AS SIGNED), guid FROM releases WHERE passwordstatus <= -2")
            .fetch_all(db)
            .await?;
    let mut removed = 0;
    for (id, guid) in bad {
        match delete_single_on(db, id, &guid).await {
            Ok(()) => removed += 1,
            Err(failure) => {
                warn!(
                    release = id,
                    error = format!("{failure:#}"),
                    "unable to remove a bad release"
                );
            }
        }
    }
    let marked = reset_releases(
        db,
        "passwordstatus <> 1 AND EXISTS (SELECT 1 FROM release_files f \
         WHERE f.releases_id = releases.id AND f.passworded = 1)",
        &[],
        "passwordstatus = 1",
        true,
    )
    .await?;
    Ok((removed, marked))
}

async fn truncate_with_keys_off(conn: &mut MySqlConnection, tables: &[&str]) -> Result<()> {
    sqlx::query("SET FOREIGN_KEY_CHECKS = 0")
        .execute(&mut *conn)
        .await?;
    let mut result = Ok(());
    for table in tables {
        let truncated = sqlx::query(AssertSqlSafe(format!("TRUNCATE TABLE `{table}`")))
            .execute(&mut *conn)
            .await;
        if let Err(failure) = truncated {
            result = Err(failure.into());
            break;
        }
    }
    sqlx::query("SET FOREIGN_KEY_CHECKS = 1")
        .execute(&mut *conn)
        .await?;
    result
}

/// `nntmux:reset-truncate`: empties the article tables and resets groups, keeping releases that have
/// an NZB. Returns the releases deleted for having none.
pub async fn reset_truncate(verify: &Verify) -> Result<u64> {
    nntmux_verify::reset::run(verify, || async {
        let db = &verify.db;
        sqlx::query(GROUP_RESET).execute(db).await?;
        let mut conn = db.acquire().await?;
        truncate_with_keys_off(
            &mut conn,
            &["parts", "missed_parts", "binaries", "collections"],
        )
        .await?;
        drop(conn);
        Ok(sqlx::query("DELETE FROM releases WHERE nzbstatus = 0")
            .execute(db)
            .await?
            .rows_affected())
    })
    .await
}

/// The database half of `nntmux:resetdb`. The caller holds the verification reset and the local-only
/// check.
pub async fn clear_database(db: &MySqlPool) -> Result<()> {
    sqlx::query(GROUP_RESET).execute(db).await?;
    let mut conn = db.acquire().await?;
    truncate_with_keys_off(&mut conn, &RESET_TABLES).await
}

/// Removes every file under `root`, leaving directories, dot files and the `keep` names, the way
/// Laravel's `File::allFiles` walked. A missing directory has nothing to remove.
pub fn remove_files(root: &Path, keep: &[&str]) -> Result<u64> {
    let entries = match std::fs::read_dir(root) {
        Ok(entries) => entries,
        Err(failure) if failure.kind() == std::io::ErrorKind::NotFound => return Ok(0),
        Err(failure) => return Err(failure.into()),
    };
    let mut removed = 0;
    for entry in entries {
        let entry = entry?;
        let name = entry.file_name();
        let name = name.to_string_lossy();
        if name.starts_with('.') {
            continue;
        }
        if entry.file_type()?.is_dir() {
            removed += remove_files(&entry.path(), keep)?;
        } else if !keep.contains(&name.as_ref()) {
            std::fs::remove_file(entry.path())?;
            removed += 1;
        }
    }
    Ok(removed)
}

/// `nntmux:optimize-tables`: checks tables and optimizes those that don't report OK. Returns the
/// tables optimized. Names must exist, since they end up in the statement.
pub async fn optimize_tables(db: &MySqlPool, tables: &[String]) -> Result<Vec<String>> {
    let existing: Vec<String> = sqlx::query_scalar("SHOW TABLES").fetch_all(db).await?;
    let selected: Vec<&String> = if tables.is_empty() || tables[0] == "all" {
        existing.iter().collect()
    } else {
        for table in tables {
            if !existing.contains(table) {
                bail!("Table {table} doesn't exist.");
            }
        }
        tables.iter().collect()
    };
    let mut conn = db.acquire().await?;
    let conn: &mut MySqlConnection = conn.as_mut();
    let mut optimized = Vec::new();
    for table in selected {
        let quoted = format!("`{}`", table.replace('`', "``"));
        let checks: Vec<(String, String, String, String)> =
            sqlx::query_as(AssertSqlSafe(format!("CHECK TABLE {quoted}")))
                .fetch_all(&mut *conn)
                .await?;
        if checks.first().is_none_or(|check| check.3 != "OK") {
            sqlx::query(AssertSqlSafe(format!("OPTIMIZE TABLE {quoted}")))
                .execute(&mut *conn)
                .await?;
            optimized.push(table.clone());
        }
    }
    Ok(optimized)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn strings(values: &[&str]) -> Vec<String> {
        values.iter().map(|value| (*value).to_owned()).collect()
    }

    #[test]
    fn categories_normalize_like_the_command() {
        assert_eq!(
            normalize_categories(&strings(&["Movies,tv", "category=books music", "tv"])),
            ["movie", "tv", "book", "music"]
        );
        assert_eq!(normalize_categories(&strings(&["misc"])), ["misc"]);
        assert_eq!(normalize_categories(&strings(&["-category=TVs"])), ["tv"]);
        assert_eq!(normalize_categories(&strings(&["nope"])), ["nope"]);
        assert!(normalize_categories(&strings(&[" , "])).is_empty());
    }
}
