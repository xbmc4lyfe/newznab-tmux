//! `nntmux:collect-stats`: the rollups behind the admin statistics pages.

use anyhow::Result;
use chrono::{DateTime, Duration, NaiveDateTime, Timelike, Utc};
use sqlx::MySqlPool;

pub async fn collect(db: &MySqlPool) -> Result<()> {
    let now = Utc::now();
    top_grabbers(db).await?;
    top_downloads(db).await?;
    recently_added(db).await?;
    users_by_month(db).await?;
    users_by_role(db).await?;
    hourly_activity(db, now).await?;
    daily_activity(db, now).await?;
    Ok(())
}

pub async fn top_grabbers(db: &MySqlPool) -> Result<()> {
    let users: Vec<(Option<String>, i64)> = sqlx::query_as(
        "SELECT username, CAST(SUM(grabs) AS SIGNED) AS total FROM users WHERE deleted_at IS NULL \
         GROUP BY id, username HAVING total > 0 ORDER BY total DESC LIMIT 10",
    )
    .fetch_all(db)
    .await?;
    for (username, grabs) in users {
        let existing: Option<u64> = sqlx::query_scalar(
            "SELECT id FROM grab_stats WHERE username <=> ? ORDER BY id LIMIT 1",
        )
        .bind(&username)
        .fetch_optional(db)
        .await?;
        match existing {
            Some(id) => {
                sqlx::query(
                    "UPDATE grab_stats SET grabs = ?, updated_at = NOW() WHERE id = ? AND grabs <> ?",
                )
                .bind(grabs)
                .bind(id)
                .bind(grabs)
                .execute(db)
                .await?;
            }
            None => {
                sqlx::query(
                    "INSERT INTO grab_stats (username, grabs, created_at, updated_at) \
                     VALUES (?, ?, NOW(), NOW())",
                )
                .bind(&username)
                .bind(grabs)
                .execute(db)
                .await?;
            }
        }
    }
    Ok(())
}

/// Every attribute is part of the match, so a changed grab count adds a row beside the old one.
/// The page behind this reads the whole table, so the behaviour is kept.
pub async fn top_downloads(db: &MySqlPool) -> Result<()> {
    let releases: Vec<(String, String, Option<NaiveDateTime>, i64)> = sqlx::query_as(
        "SELECT searchname, guid, adddate, CAST(SUM(grabs) AS SIGNED) AS total FROM releases \
         WHERE grabs > 0 GROUP BY id, searchname, adddate HAVING SUM(grabs) > 0 \
         ORDER BY total DESC LIMIT 10",
    )
    .fetch_all(db)
    .await?;
    for (searchname, guid, adddate, grabs) in releases {
        let existing: Option<u64> = sqlx::query_scalar(
            "SELECT id FROM download_stats WHERE searchname = ? AND guid = ? AND adddate <=> ? \
             AND grabs = ? LIMIT 1",
        )
        .bind(&searchname)
        .bind(&guid)
        .bind(adddate)
        .bind(grabs)
        .fetch_optional(db)
        .await?;
        if existing.is_none() {
            sqlx::query(
                "INSERT INTO download_stats (searchname, guid, adddate, grabs, created_at, updated_at) \
                 VALUES (?, ?, ?, ?, NOW(), NOW())",
            )
            .bind(&searchname)
            .bind(&guid)
            .bind(adddate)
            .bind(grabs)
            .execute(db)
            .await?;
        }
    }
    Ok(())
}

/// Release counts per category over the last week, leaving out Other > Misc and Other > Hashed.
/// A category with no recent releases keeps its last count, as it always has.
pub async fn recently_added(db: &MySqlPool) -> Result<()> {
    let categories: Vec<(String, Option<String>, i64)> = sqlx::query_as(
        "SELECT c.title, rc.title, COUNT(r.id) AS total FROM categories c \
         JOIN releases r ON r.categories_id = c.id \
         LEFT JOIN root_categories rc ON rc.id = c.root_categories_id \
         WHERE r.adddate > NOW() - INTERVAL 1 WEEK AND c.id NOT IN (10, 20) \
         GROUP BY c.id, c.root_categories_id, c.title, rc.title ORDER BY total DESC",
    )
    .fetch_all(db)
    .await?;
    for (title, parent, count) in categories {
        let display = match parent {
            Some(parent) => format!("{parent} > {title}"),
            None => title,
        };
        let updated = sqlx::query(
            "UPDATE release_stats SET count = ?, updated_at = NOW() WHERE category = ?",
        )
        .bind(count)
        .bind(&display)
        .execute(db)
        .await?
        .rows_affected();
        if updated == 0 {
            sqlx::query(
                "INSERT INTO release_stats (category, count, created_at, updated_at) \
                 VALUES (?, ?, NOW(), NOW())",
            )
            .bind(&display)
            .bind(count)
            .execute(db)
            .await?;
        }
    }
    Ok(())
}

pub async fn users_by_month(db: &MySqlPool) -> Result<()> {
    let months: Vec<(String, String, i64)> = sqlx::query_as(
        "SELECT DATE_FORMAT(created_at, '%Y-%m-01') AS sort_date, DATE_FORMAT(created_at, '%M %Y') AS mth, \
         COUNT(id) FROM users WHERE deleted_at IS NULL AND created_at IS NOT NULL \
         GROUP BY sort_date, mth ORDER BY sort_date DESC",
    )
    .fetch_all(db)
    .await?;
    for (sort_date, month, signups) in months {
        let existing: Option<u64> =
            sqlx::query_scalar("SELECT id FROM signup_stats WHERE month = ? LIMIT 1")
                .bind(&month)
                .fetch_optional(db)
                .await?;
        match existing {
            Some(id) => {
                sqlx::query(
                    "UPDATE signup_stats SET signups = ?, sort_date = ?, updated_at = NOW() \
                     WHERE id = ? AND (signups <> ? OR NOT sort_date <=> ?)",
                )
                .bind(signups)
                .bind(&sort_date)
                .bind(id)
                .bind(signups)
                .bind(&sort_date)
                .execute(db)
                .await?;
            }
            None => {
                sqlx::query(
                    "INSERT INTO signup_stats (month, signups, sort_date, created_at, updated_at) \
                     VALUES (?, ?, ?, NOW(), NOW())",
                )
                .bind(&month)
                .bind(signups)
                .bind(&sort_date)
                .execute(db)
                .await?;
            }
        }
    }
    Ok(())
}

pub async fn users_by_role(db: &MySqlPool) -> Result<()> {
    let roles: Vec<(String, i64)> = sqlx::query_as(
        "SELECT r.name, COUNT(u.id) AS total FROM roles r \
         JOIN model_has_roles m ON m.role_id = r.id AND m.model_type = 'App\\\\Models\\\\User' \
         JOIN users u ON u.id = m.model_id AND u.deleted_at IS NULL \
         GROUP BY r.name ORDER BY total DESC",
    )
    .fetch_all(db)
    .await?;
    for (role, users) in roles {
        let updated =
            sqlx::query("UPDATE role_stats SET users = ?, updated_at = NOW() WHERE role = ?")
                .bind(users)
                .bind(&role)
                .execute(db)
                .await?
                .rows_affected();
        if updated == 0 {
            sqlx::query(
                "INSERT INTO role_stats (role, users, created_at, updated_at) \
                 VALUES (?, ?, NOW(), NOW())",
            )
            .bind(&role)
            .bind(users)
            .execute(db)
            .await?;
        }
    }
    Ok(())
}

fn hour_start(at: DateTime<Utc>) -> NaiveDateTime {
    at.naive_utc()
        .date()
        .and_hms_opt(at.hour(), 0, 0)
        .unwrap_or_default()
}

/// Rolls the last closed hour up from the raw tables, which are pruned after a day.
pub async fn hourly_activity(db: &MySqlPool, now: DateTime<Utc>) -> Result<()> {
    let start = hour_start(now - Duration::hours(1));
    let end = start + Duration::hours(1);
    let downloads: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM user_downloads WHERE `timestamp` >= ? AND `timestamp` < ?",
    )
    .bind(start)
    .bind(end)
    .fetch_one(db)
    .await?;
    let api_hits: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM user_requests WHERE `timestamp` >= ? AND `timestamp` < ?",
    )
    .bind(start)
    .bind(end)
    .fetch_one(db)
    .await?;
    sqlx::query(
        "INSERT INTO user_activity_stats_hourly \
         (stat_hour, downloads_count, api_hits_count, created_at, updated_at) \
         VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE \
         downloads_count = VALUES(downloads_count), api_hits_count = VALUES(api_hits_count), \
         updated_at = VALUES(updated_at)",
    )
    .bind(start)
    .bind(downloads)
    .bind(api_hits)
    .execute(db)
    .await?;
    Ok(())
}

/// Rolls yesterday up from the hourly table, so the result can't shrink as raw rows are pruned.
pub async fn daily_activity(db: &MySqlPool, now: DateTime<Utc>) -> Result<()> {
    let date = (now - Duration::days(1)).date_naive();
    let start = date.and_hms_opt(0, 0, 0).unwrap_or_default();
    let (downloads, api_hits): (i64, i64) = sqlx::query_as(
        "SELECT CAST(COALESCE(SUM(downloads_count), 0) AS SIGNED), \
         CAST(COALESCE(SUM(api_hits_count), 0) AS SIGNED) FROM user_activity_stats_hourly \
         WHERE stat_hour >= ? AND stat_hour < ?",
    )
    .bind(start)
    .bind(start + Duration::days(1))
    .fetch_one(db)
    .await?;
    sqlx::query(
        "INSERT INTO user_activity_stats \
         (stat_date, downloads_count, api_hits_count, created_at, updated_at) \
         VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE \
         downloads_count = VALUES(downloads_count), api_hits_count = VALUES(api_hits_count), \
         updated_at = VALUES(updated_at)",
    )
    .bind(date)
    .bind(downloads)
    .bind(api_hits)
    .execute(db)
    .await?;
    Ok(())
}
