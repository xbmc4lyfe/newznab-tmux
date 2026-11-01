//! Deletes releases the site's settings no longer want: too small or large for their group, past
//! retention, passworded, incomplete, or in a disabled category or genre. Each is removed with its
//! NZB, images and search document.

use anyhow::Result;
use chrono::TimeDelta;
use nntmux_categorize::Categorizer;
use nntmux_verify::candidates::now;
use nntmux_verify::releases::request_search_update;
use sqlx::{MySql, QueryBuilder};
use tracing::info;

use crate::settings::{Settings, stop_requested};
use crate::{Releases, remove};

const BATCH_SIZE: i64 = 500;
const CATEGORIZE_CHUNK: i64 = 1000;
const OTHER_MISC: i64 = 10;
const OTHER_HASHED: i64 = 20;
const PASSWD_RAR: i64 = 1;

/// `releases:process --maintenance-only`: stops between steps once a stop is requested.
pub async fn run(releases: &Releases, settings: &Settings) -> Result<()> {
    let db = &releases.verify.db;
    if stop_requested(db).await? {
        return Ok(());
    }
    delete_releases_by_group(releases, settings).await?;
    if stop_requested(db).await? {
        return Ok(());
    }
    delete_releases(releases, settings).await?;
    if stop_requested(db).await? {
        return Ok(());
    }
    categorize_releases(releases, None).await?;
    Ok(())
}

/// Categorizes uncategorized `Other > Misc` releases by search name. Returns releases changed.
pub async fn categorize_releases(releases: &Releases, group: Option<i64>) -> Result<u64> {
    let db = &releases.verify.db;
    let categorizer = Categorizer::load(db).await?;
    let mut after = 0u32;
    let mut categorized = 0;
    loop {
        let mut query = QueryBuilder::<MySql>::new(
            "SELECT id, fromname, groups_id, searchname FROM releases WHERE categories_id = ",
        );
        query
            .push_bind(OTHER_MISC)
            .push(" AND iscategorized = 0 AND id > ")
            .push_bind(after);
        if let Some(group) = group {
            query.push(" AND groups_id = ").push_bind(group);
        }
        query
            .push(" ORDER BY id LIMIT ")
            .push_bind(CATEGORIZE_CHUNK);
        let rows: Vec<(u32, Option<String>, u32, String)> =
            query.build_query_as().fetch_all(db).await?;
        let count = rows.len() as i64;
        for (id, poster, group, name) in rows {
            after = id;
            let decision = categorizer
                .determine(db, i64::from(group), &name, poster.as_deref().unwrap_or(""))
                .await?;
            let mut conn = db.acquire().await?;
            sqlx::query("UPDATE releases SET categories_id = ?, iscategorized = 1 WHERE id = ?")
                .bind(decision.category)
                .bind(id)
                .execute(&mut *conn)
                .await?;
            request_search_update(&mut conn, i64::from(id)).await?;
            categorized += 1;
        }
        if count < CATEGORIZE_CHUNK {
            return Ok(categorized);
        }
    }
}

/// Deletes releases smaller or larger than the group's and the site's limits.
pub async fn delete_releases_by_group(releases: &Releases, settings: &Settings) -> Result<()> {
    let db = &releases.verify.db;
    let groups: Vec<u32> =
        sqlx::query_scalar("SELECT id FROM usenet_groups WHERE active = 1 ORDER BY name")
            .fetch_all(db)
            .await?;
    let (mut min_size, mut max_size, mut min_files) = (0, 0, 0);
    for group in groups {
        let rows: Vec<(u32, String)> = sqlx::query_as(
            "SELECT r.id, r.guid FROM releases AS r INNER JOIN usenet_groups AS g ON g.id = r.groups_id \
             WHERE r.groups_id = ? AND GREATEST(IFNULL(g.minsizetoformrelease, 0), ?) > 0 \
             AND r.size < GREATEST(IFNULL(g.minsizetoformrelease, 0), ?)",
        )
        .bind(group)
        .bind(settings.min_size)
        .bind(settings.min_size)
        .fetch_all(db)
        .await?;
        min_size += remove_all(releases, rows).await?;
        if settings.max_size > 0 {
            let rows: Vec<(u32, String)> =
                sqlx::query_as("SELECT id, guid FROM releases WHERE groups_id = ? AND size > ?")
                    .bind(group)
                    .bind(settings.max_size)
                    .fetch_all(db)
                    .await?;
            max_size += remove_all(releases, rows).await?;
        }
        if settings.min_files > 0 {
            let rows: Vec<(u32, String)> = sqlx::query_as(
                "SELECT r.id, r.guid FROM releases AS r INNER JOIN usenet_groups AS g ON g.id = r.groups_id \
                 WHERE r.groups_id = ? AND GREATEST(IFNULL(g.minfilestoformrelease, 0), ?) > 0 \
                 AND r.totalpart < GREATEST(IFNULL(g.minfilestoformrelease, 0), ?)",
            )
            .bind(group)
            .bind(settings.min_files)
            .bind(settings.min_files)
            .fetch_all(db)
            .await?;
            min_files += remove_all(releases, rows).await?;
        }
    }
    if min_size + max_size + min_files > 0 {
        info!(
            min_size,
            max_size, min_files, "removed releases outside group limits"
        );
    }
    Ok(())
}

async fn remove_all(releases: &Releases, rows: Vec<(u32, String)>) -> Result<u64> {
    let mut removed = 0;
    for (id, guid) in rows {
        remove::delete_single(&releases.verify, i64::from(id), &guid).await?;
        removed += 1;
    }
    Ok(removed)
}

/// Deletes every release a keyset walk over `predicate` finds. `predicate` must be a condition on the
/// bare `releases` table, with its bindings pushed by `bind`.
async fn sweep<F>(releases: &Releases, label: &str, predicate: F) -> Result<u64>
where
    F: Fn(&mut QueryBuilder<MySql>),
{
    let db = &releases.verify.db;
    let mut removed = 0;
    let mut after = 0i64;
    loop {
        let mut query = QueryBuilder::<MySql>::new("SELECT id, guid FROM releases WHERE id > ");
        query.push_bind(after).push(" AND (");
        predicate(&mut query);
        query.push(") ORDER BY id LIMIT ").push_bind(BATCH_SIZE);
        let rows: Vec<(u32, String)> = query.build_query_as().fetch_all(db).await?;
        let count = rows.len() as i64;
        if let Some((id, _)) = rows.last() {
            after = i64::from(*id);
        }
        removed += remove_all(releases, rows).await?;
        if count < BATCH_SIZE {
            break;
        }
    }
    if removed > 0 {
        info!(label, removed, "removed unwanted releases");
    }
    Ok(removed)
}

/// Deletes releases based on site-wide settings.
pub async fn delete_releases(releases: &Releases, settings: &Settings) -> Result<()> {
    let db = &releases.verify.db;
    if settings.retention_days > 0 {
        let cutoff = now() - TimeDelta::days(settings.retention_days);
        sweep(releases, "past retention", |query| {
            query.push("postdate < ").push_bind(cutoff);
        })
        .await?;
    }
    if settings.delete_passworded {
        sweep(releases, "passworded", |query| {
            query.push("passwordstatus = ").push_bind(PASSWD_RAR);
            query.push(" OR id IN (SELECT releases_id FROM release_files WHERE passworded = ");
            query.push_bind(PASSWD_RAR).push(")");
        })
        .await?;
    }
    // A name/poster group isn't proof that its uploads share articles, so cross-posts aren't removed.
    if settings.completion > 0 {
        // Partial publications are knowingly incomplete, carry their article counts, and are kept.
        sweep(releases, "incomplete", |query| {
            query.push("completion < ").push_bind(settings.completion);
            query.push(" AND completion > 0 AND articles_expected IS NULL");
        })
        .await?;
    }
    let disabled: Vec<i64> =
        sqlx::query_scalar::<_, u32>("SELECT id FROM categories WHERE status = 2")
            .fetch_all(db)
            .await?
            .into_iter()
            .map(i64::from)
            .collect();
    if !disabled.is_empty() {
        sweep(releases, "disabled category", |query| {
            query.push("categories_id IN (");
            let mut separated = query.separated(",");
            for id in &disabled {
                separated.push_bind(*id);
            }
            query.push(")");
        })
        .await?;
    }
    delete_category_min_size(releases).await?;
    let genres: Vec<u32> = sqlx::query_scalar("SELECT id FROM genres WHERE disabled = 1")
        .fetch_all(db)
        .await?;
    for genre in genres {
        sweep(releases, "disabled genre", |query| {
            query.push("musicinfo_id IN (SELECT id FROM musicinfo WHERE genres_id = ");
            query.push_bind(genre).push(")");
        })
        .await?;
    }
    if settings.misc_other_retention_hours > 0 {
        let cutoff = now() - TimeDelta::hours(settings.misc_other_retention_hours);
        sweep(releases, "misc other", |query| {
            query.push("categories_id = ").push_bind(OTHER_MISC);
            query.push(" AND adddate <= ").push_bind(cutoff);
        })
        .await?;
    }
    if settings.misc_hashed_retention_hours > 0 {
        let cutoff = now() - TimeDelta::hours(settings.misc_hashed_retention_hours);
        sweep(releases, "misc hashed", |query| {
            query.push("categories_id = ").push_bind(OTHER_HASHED);
            query.push(" AND adddate <= ").push_bind(cutoff);
        })
        .await?;
    }
    Ok(())
}

/// A partial publication is knowingly short, and late articles may still complete it. At most 1000
/// releases per category per pass.
async fn delete_category_min_size(releases: &Releases) -> Result<()> {
    let db = &releases.verify.db;
    let categories: Vec<(u32, i64)> = sqlx::query_as(
        "SELECT id, CAST(minsizetoformrelease AS SIGNED) FROM categories WHERE minsizetoformrelease > 0",
    )
    .fetch_all(db)
    .await?;
    for (category, min_size) in categories {
        let rows: Vec<(u32, String)> = sqlx::query_as(
            "SELECT id, guid FROM releases WHERE categories_id = ? AND size < ? AND articles_expected IS NULL ORDER BY id LIMIT 1000",
        )
        .bind(category)
        .bind(min_size)
        .fetch_all(db)
        .await?;
        remove_all(releases, rows).await?;
    }
    Ok(())
}
