//! The processing statistics the tmux monitor computed: backlogs, table sizes and category counts.
//! These queries are expensive, so one scheduled task computes them and leaves a snapshot in Redis,
//! and the once-a-minute export reads that.

use std::collections::BTreeMap;
use std::time::{Duration, Instant};

use anyhow::{Context, Result};
use chrono::Utc;
use nntmux_categorize::cat;
use nntmux_metadata::{LookupMode, book_work_condition, imdb_needs_lookup_sql, tv_work_condition};
use nntmux_postprocess::additional::candidates::{Scope, backlog_counts};
use nntmux_postprocess::config::{Config as PostprocessConfig, Settings};
use nntmux_runtime::Ctx;
use redis::AsyncCommands;
use serde_json::{Value, json};
use sqlx::{AssertSqlSafe, MySqlPool, Row};

const SNAPSHOT_TTL_SECONDS: u64 = 900;
/// Above this the planner's estimate stands in for an exact count.
const EXACT_COUNT_LIMIT: i64 = 1_000_000;
const SETTLED: &str = "late_article_candidate_id IS NULL";
const NEVER: &str = "0 = 1";
/// `NfoService::NFO_UNPROC`.
const NFO_UNPROCESSED: i64 = -1;

pub fn snapshot_key(ctx: &Ctx) -> String {
    format!("monitoring:tmux-snapshot:{}", ctx.config.database.database)
}

/// The settings that decide which backlogs count.
#[derive(Clone, Debug, Default, sqlx::FromRow)]
pub struct BacklogSettings {
    pub sequential_mode: i64,
    pub post_mode: i64,
    pub post_amazon_mode: i64,
    pub post_non_mode: i64,
    pub fix_names_enabled: i64,
    pub anime_lookup: i64,
    pub book_lookup: i64,
    pub game_lookup: i64,
    pub movie_lookup: i64,
    pub music_lookup: i64,
    pub tv_lookup: i64,
    pub lookup_nfo: i64,
    pub min_nfo_size: i64,
    pub max_nfo_size: i64,
    pub max_nfo_retries: i64,
}

async fn backlog_settings(db: &MySqlPool) -> Result<BacklogSettings> {
    let row: Option<BacklogSettings> = sqlx::query_as(
        "SELECT CAST(COALESCE(t.sequential_mode, 0) AS SIGNED) AS sequential_mode, \
         CAST(COALESCE(t.post_mode, 0) AS SIGNED) AS post_mode, \
         CAST(COALESCE(t.post_amazon_mode, 0) AS SIGNED) AS post_amazon_mode, \
         CAST(COALESCE(t.post_non_mode, 0) AS SIGNED) AS post_non_mode, \
         CAST(COALESCE(t.fix_names_enabled, 0) AS SIGNED) AS fix_names_enabled, \
         CAST(COALESCE(m.anime_lookup, 0) AS SIGNED) AS anime_lookup, \
         CAST(COALESCE(m.book_lookup, 0) AS SIGNED) AS book_lookup, \
         CAST(COALESCE(m.game_lookup, 0) AS SIGNED) AS game_lookup, \
         CAST(COALESCE(m.movie_lookup, 0) AS SIGNED) AS movie_lookup, \
         CAST(COALESCE(m.music_lookup, 0) AS SIGNED) AS music_lookup, \
         CAST(COALESCE(m.tv_lookup, 0) AS SIGNED) AS tv_lookup, \
         CAST(COALESCE(p.lookup_nfo, 0) AS SIGNED) AS lookup_nfo, \
         CAST(COALESCE(p.min_size_to_process_nfo, 0) AS SIGNED) AS min_nfo_size, \
         CAST(COALESCE(p.max_size_to_process_nfo, 0) AS SIGNED) AS max_nfo_size, \
         CAST(COALESCE(p.max_nfo_retries, 0) AS SIGNED) AS max_nfo_retries \
         FROM (SELECT 1 AS id) one \
         LEFT JOIN tmux_configurations t ON t.id = one.id \
         LEFT JOIN metadata_configurations m ON m.id = one.id \
         LEFT JOIN post_processing_configurations p ON p.id = one.id",
    )
    .fetch_optional(db)
    .await?;
    Ok(row.unwrap_or_default())
}

fn ready_lookup(condition: &str, mode: i64, enabled: bool, renamed_only: bool) -> String {
    if !enabled || mode <= 0 {
        return NEVER.to_owned();
    }
    let renamed = if renamed_only && mode == 2 {
        " AND isrenamed = 1"
    } else {
        ""
    };
    format!("{condition}{renamed} AND {SETTLED}")
}

/// `NfoService::NfoQueryString`.
pub fn nfo_work(settings: &BacklogSettings) -> String {
    let max_retries = -(settings.max_nfo_retries + 1);
    let max = if settings.max_nfo_size > 0 {
        format!(" AND r.size < {}", settings.max_nfo_size)
    } else {
        String::new()
    };
    let min = if settings.min_nfo_size > 0 {
        format!(" AND r.size > {}", settings.min_nfo_size)
    } else {
        String::new()
    };
    format!(
        "r.nfostatus BETWEEN {} AND {NFO_UNPROCESSED}{max}{min} AND r.{SETTLED}",
        max_retries.max(-8)
    )
}

/// `NameFixingQueryService::pendingWorkCondition`, counting each release once.
pub fn rename_work(category_scope: &str, full_backlog: bool) -> String {
    let sources = [
        ("proc_nfo", "EXISTS (SELECT 1 FROM release_nfos source_nfo WHERE source_nfo.releases_id = r.id)".to_owned()),
        ("proc_files", "EXISTS (SELECT 1 FROM release_files source_file WHERE source_file.releases_id = r.id)".to_owned()),
        ("proc_srr", "EXISTS (SELECT 1 FROM release_files source_srr WHERE source_srr.releases_id = r.id AND (source_srr.name LIKE '%.srr' OR source_srr.name LIKE '%.srs'))".to_owned()),
        ("proc_crc32", "EXISTS (SELECT 1 FROM release_files source_crc WHERE source_crc.releases_id = r.id AND source_crc.crc32 IS NOT NULL AND source_crc.crc32 != '')".to_owned()),
        ("proc_uid", "EXISTS (SELECT 1 FROM media_infos source_media WHERE source_media.releases_id = r.id AND source_media.unique_id IS NOT NULL AND source_media.unique_id != '')".to_owned()),
        ("proc_hash16k", "EXISTS (SELECT 1 FROM par_hashes source_hash WHERE source_hash.releases_id = r.id AND source_hash.hash != '') AND NOT EXISTS (SELECT 1 FROM par2_hash_retry hr WHERE hr.releases_id = r.id AND HEX(hr.release_guid) = HEX(r.guid) AND hr.retry_at > CURRENT_TIMESTAMP)".to_owned()),
        ("proc_files", "EXISTS (SELECT 1 FROM release_files source_xxx WHERE source_xxx.releases_id = r.id AND source_xxx.name LIKE '%SDPORN%')".to_owned()),
        ("proc_uid", "EXISTS (SELECT 1 FROM media_infos source_movie WHERE source_movie.releases_id = r.id AND source_movie.movie_name IS NOT NULL AND source_movie.movie_name != '')".to_owned()),
    ];
    let pending = sources
        .iter()
        .map(|(column, source)| format!("(r.{column} = 0 AND ({source}))"))
        .collect::<Vec<_>>()
        .join(" OR ");
    let category = if category_scope == "all" {
        String::new()
    } else {
        let others = cat::OTHERS_GROUP.map(|id| id.to_string()).join(",");
        format!(" AND r.categories_id IN ({others})")
    };
    let recent = if full_backlog {
        ""
    } else {
        " AND r.adddate > CURRENT_TIMESTAMP - INTERVAL 6 HOUR"
    };
    format!(
        "r.predb_id = 0 AND (r.isrenamed = 0 OR r.categories_id IN ({}, {})){category} \
         AND (CASE WHEN r.proc_par2 = 0 AND (r.nzbstatus = 1) AND r.{SETTLED} THEN 1 WHEN {pending} THEN 1 ELSE 0 END = 1) \
         AND r.{SETTLED}{recent}",
        cat::OTHER_MISC,
        cat::OTHER_HASHED
    )
}

/// `Tmux::proc_query(1)`: one aggregate over `releases`, so the conditions stay in one scan.
pub fn release_counts_sql(
    settings: &BacklogSettings,
    category_scope: &str,
    full_backlog: bool,
) -> String {
    let post_panes = settings.sequential_mode != 2;
    let non_amazon = post_panes && settings.post_non_mode > 0;
    let amazon = settings.post_amazon_mode > 0;
    let processnfo = if !post_panes {
        format!(
            "{} AND EXISTS (SELECT 1 FROM usenet_groups sequential_group WHERE sequential_group.id = r.groups_id \
             AND (sequential_group.active = 1 OR sequential_group.backfill = 1))",
            nfo_work(settings)
        )
    } else if matches!(settings.post_mode, 2 | 3) && settings.lookup_nfo != 0 {
        nfo_work(settings)
    } else {
        NEVER.to_owned()
    };
    let conditions = [
        (
            "processtv",
            if non_amazon {
                tv_work_condition(LookupMode::from_value(settings.tv_lookup))
            } else {
                NEVER.to_owned()
            },
        ),
        (
            "processanime",
            ready_lookup(
                &format!("categories_id = {} AND anidbid IS NULL", cat::TV_ANIME),
                settings.anime_lookup,
                non_amazon,
                false,
            ),
        ),
        (
            "processmovies",
            ready_lookup(
                &format!(
                    "categories_id BETWEEN {} AND {} AND {}",
                    cat::MOVIE_ROOT,
                    cat::MOVIE_OTHER,
                    imdb_needs_lookup_sql("imdbid")
                ),
                settings.movie_lookup,
                non_amazon,
                true,
            ),
        ),
        (
            "processmusic",
            ready_lookup(
                &format!(
                    "categories_id IN ({}, {}, {}) AND musicinfo_id IS NULL",
                    cat::MUSIC_MP3,
                    cat::MUSIC_LOSSLESS,
                    cat::MUSIC_OTHER
                ),
                settings.music_lookup,
                amazon,
                true,
            ),
        ),
        (
            "processconsole",
            ready_lookup(
                &format!(
                    "categories_id BETWEEN {} AND {} AND consoleinfo_id IS NULL",
                    cat::GAME_ROOT,
                    cat::GAME_OTHER
                ),
                settings.game_lookup,
                amazon,
                true,
            ),
        ),
        (
            "processbooks",
            if amazon {
                book_work_condition(LookupMode::from_value(settings.book_lookup))
            } else {
                NEVER.to_owned()
            },
        ),
        (
            "processgames",
            ready_lookup(
                &format!("categories_id = {} AND gamesinfo_id = 0", cat::PC_GAMES),
                settings.game_lookup,
                amazon,
                true,
            ),
        ),
        ("processnfo", processnfo),
        (
            "processrenames",
            if settings.fix_names_enabled != 0 {
                rename_work(category_scope, full_backlog)
            } else {
                NEVER.to_owned()
            },
        ),
        ("renamed", "isrenamed = 1".to_owned()),
        ("nfo", "nfostatus = 1".to_owned()),
        ("predb_matched", "predb_id > 0".to_owned()),
    ];
    let aggregates = conditions
        .iter()
        .map(|(name, condition)| {
            format!("CAST(COALESCE(SUM(CASE WHEN {condition} THEN 1 ELSE 0 END), 0) AS SIGNED) AS {name}")
        })
        .collect::<Vec<_>>()
        .join(", ");
    format!("SELECT {aggregates} FROM releases r")
}

async fn table_rows(db: &MySqlPool, table: &str) -> Result<i64> {
    let estimate: Option<i64> = sqlx::query_scalar(
        "SELECT CAST(TABLE_ROWS AS SIGNED) FROM information_schema.TABLES \
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    )
    .bind(table)
    .fetch_optional(db)
    .await?
    .flatten();
    if estimate.unwrap_or(0) > EXACT_COUNT_LIMIT {
        return Ok(estimate.unwrap_or(0));
    }
    Ok(
        sqlx::query_scalar(AssertSqlSafe(format!("SELECT COUNT(*) FROM `{table}`")))
            .fetch_one(db)
            .await?,
    )
}

const COUNT_COLUMNS: [&str; 12] = [
    "processtv",
    "processanime",
    "processmovies",
    "processmusic",
    "processconsole",
    "processbooks",
    "processgames",
    "processnfo",
    "processrenames",
    "renamed",
    "nfo",
    "predb_matched",
];

/// Computes the snapshot. Any failure fails the whole thing: publishing partial counts would read
/// as real drops, while a skipped publish shows as the snapshot's age growing.
pub async fn collect(ctx: &Ctx) -> Result<Value> {
    let db = &ctx.db;
    let mut counts: BTreeMap<String, i64> = BTreeMap::new();
    let mut timers: BTreeMap<&str, f64> = BTreeMap::new();

    let started = Instant::now();
    let settings = backlog_settings(db).await?;
    timers.insert("tmux_time", started.elapsed().as_secs_f64());

    let started = Instant::now();
    let category_scope = nntmux_core::var_or("TMUX_FIX_NAMES_CATEGORY", "other");
    let full_backlog = !matches!(
        nntmux_core::var_or("TMUX_FIX_NAMES_FULL_BACKLOG", "true")
            .to_ascii_lowercase()
            .as_str(),
        "0" | "false"
    );
    let sql = release_counts_sql(&settings, &category_scope, full_backlog);
    let row = sqlx::query(AssertSqlSafe(sql)).fetch_one(db).await?;
    for column in COUNT_COLUMNS {
        counts.insert(column.to_owned(), row.try_get::<i64, _>(column)?);
    }
    timers.insert("proc1_time", started.elapsed().as_secs_f64());

    let started = Instant::now();
    let (active, all): (i64, i64) = sqlx::query_as(
        "SELECT (SELECT COUNT(id) FROM usenet_groups WHERE active = 1), \
         (SELECT COUNT(id) FROM usenet_groups WHERE name IS NOT NULL)",
    )
    .fetch_one(db)
    .await?;
    counts.insert("active_groups".to_owned(), active);
    counts.insert("all_groups".to_owned(), all);
    let sequential = settings.sequential_mode == 2;
    let (mut work, mut available) = (0, 0);
    if sequential || matches!(settings.post_mode, 1 | 3) {
        (work, available) = additional_backlog(db).await?;
    }
    counts.insert("work".to_owned(), work);
    counts.insert("work_available".to_owned(), available);
    timers.insert("proc2_time", started.elapsed().as_secs_f64());

    let started = Instant::now();
    counts.extend(category_counts(db).await?);
    timers.insert("init_time", started.elapsed().as_secs_f64());

    let started = Instant::now();
    counts.extend(table_counts(db).await?);
    timers.insert("tpg_time", started.elapsed().as_secs_f64());

    let backlog: i64 = counts
        .iter()
        .filter(|(key, _)| key.starts_with("process"))
        .map(|(_, value)| *value)
        .sum();
    counts.insert("total_work".to_owned(), work + backlog);

    let running: Option<bool> =
        sqlx::query_scalar("SELECT tmux_running FROM processing_runtime_states WHERE id = 1")
            .fetch_optional(db)
            .await?;
    Ok(json!({
        "collected_at": Utc::now().timestamp(),
        "is_running": i64::from(running.unwrap_or(false)),
        "counts": counts,
        "query_timers": timers,
    }))
}

async fn additional_backlog(db: &MySqlPool) -> Result<(i64, i64)> {
    let config = PostprocessConfig::from_settings(Settings::load(db).await?)?;
    let counts = backlog_counts(db, &Scope::new(&config)).await?;
    Ok((counts.total, counts.available))
}

async fn category_counts(db: &MySqlPool) -> Result<BTreeMap<String, i64>, sqlx::Error> {
    let ranges = [
        ("tv", cat::TV_ROOT, cat::TV_OTHER),
        ("movies", cat::MOVIE_ROOT, cat::MOVIE_OTHER),
        ("audio", cat::MUSIC_ROOT, cat::MUSIC_OTHER),
        ("books", cat::BOOKS_ROOT, cat::BOOKS_UNKNOWN),
        ("console", cat::GAME_ROOT, cat::GAME_OTHER),
        ("pc", cat::PC_ROOT, cat::PC_PHONE_ANDROID),
        ("xxx", cat::XXX_ROOT, cat::XXX_OTHER),
        ("misc", cat::OTHER_ROOT, cat::OTHER_HASHED),
    ];
    let aggregates = ranges
        .iter()
        .map(|(name, low, high)| {
            format!(
                "CAST(COALESCE(SUM(categories_id BETWEEN {low} AND {high}), 0) AS SIGNED) AS {name}"
            )
        })
        .collect::<Vec<_>>()
        .join(", ");
    let row = sqlx::query(AssertSqlSafe(format!("SELECT {aggregates} FROM releases")))
        .fetch_one(db)
        .await?;
    ranges
        .iter()
        .map(|(name, _, _)| Ok(((*name).to_owned(), row.try_get::<i64, _>(*name)?)))
        .collect()
}

async fn table_counts(db: &MySqlPool) -> Result<BTreeMap<String, i64>> {
    let mut counts = BTreeMap::new();
    for (key, table) in [
        ("collections_table", "collections"),
        ("binaries_table", "binaries"),
        ("parts_table", "parts"),
        ("missed_parts_table", "missed_parts"),
    ] {
        counts.insert(key.to_owned(), table_rows(db, table).await?);
    }
    let releases: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM releases")
        .fetch_one(db)
        .await?;
    counts.insert("releases".to_owned(), releases);
    let predb: Option<i64> = sqlx::query_scalar(
        "SELECT CAST(TABLE_ROWS AS SIGNED) FROM information_schema.TABLES \
         WHERE TABLE_NAME = 'predb' AND TABLE_SCHEMA = DATABASE()",
    )
    .fetch_optional(db)
    .await?
    .flatten();
    counts.insert("predb".to_owned(), predb.unwrap_or(0));
    Ok(counts)
}

/// `total_work` from the latest published snapshot, without running the counts. `None` means there's
/// no snapshot younger than `max_age`, which the caller has to decide about.
pub async fn published_total_work(ctx: &Ctx, max_age: Duration) -> Result<Option<i64>> {
    let mut redis = ctx.redis.clone();
    let body: Option<String> = redis.get(snapshot_key(ctx)).await?;
    Ok(body.and_then(|body| fresh_total_work(&body, Utc::now().timestamp(), max_age)))
}

fn fresh_total_work(body: &str, now: i64, max_age: Duration) -> Option<i64> {
    let snapshot: Value = serde_json::from_str(body).ok()?;
    let age = now - snapshot["collected_at"].as_i64()?;
    if u64::try_from(age.max(0)).ok()? > max_age.as_secs() {
        return None;
    }
    snapshot["counts"]["total_work"].as_i64()
}

/// Computes and publishes the snapshot for the exporter and the post-processing kill switch.
pub async fn publish(ctx: &Ctx) -> Result<()> {
    let snapshot = collect(ctx).await?;
    let mut redis = ctx.redis.clone();
    let _: () = redis
        .set_ex(
            snapshot_key(ctx),
            snapshot.to_string(),
            SNAPSHOT_TTL_SECONDS,
        )
        .await
        .context("publishing the processing snapshot")?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn total_work_is_only_read_from_a_fresh_snapshot() {
        let body = json!({"collected_at": 1000, "counts": {"total_work": 42}}).to_string();
        let limit = Duration::from_secs(300);
        assert_eq!(fresh_total_work(&body, 1200, limit), Some(42));
        assert_eq!(fresh_total_work(&body, 1300, limit), Some(42));
        assert_eq!(fresh_total_work(&body, 1301, limit), None);
        assert_eq!(fresh_total_work("not json", 1000, limit), None);
        assert_eq!(
            fresh_total_work(r#"{"collected_at":1000,"counts":{}}"#, 1000, limit),
            None
        );
    }
}
