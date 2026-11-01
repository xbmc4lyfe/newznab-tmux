//! `usenet_groups` and `short_groups`.

use anyhow::Result;
use chrono::NaiveDateTime;
use sqlx::{FromRow, MySqlPool};

#[derive(Clone, Debug, FromRow)]
pub struct Group {
    pub id: u32,
    pub name: String,
    pub backfill_target: i32,
    pub first_record: u64,
    pub first_record_postdate: Option<NaiveDateTime>,
    pub last_record: u64,
    pub last_record_postdate: Option<NaiveDateTime>,
    pub active: bool,
    pub backfill: bool,
}

const COLUMNS: &str = "id, name, backfill_target, first_record, first_record_postdate, \
    last_record, last_record_postdate, active, backfill";

pub async fn by_name(pool: &MySqlPool, name: &str) -> Result<Option<Group>> {
    let sql = format!("SELECT {COLUMNS} FROM usenet_groups WHERE name = ?");
    Ok(sqlx::query_as(sqlx::AssertSqlSafe(sql))
        .bind(name)
        .fetch_optional(pool)
        .await?)
}

pub async fn by_id(pool: &MySqlPool, id: u32) -> Result<Option<Group>> {
    let sql = format!("SELECT {COLUMNS} FROM usenet_groups WHERE id = ?");
    Ok(sqlx::query_as(sqlx::AssertSqlSafe(sql))
        .bind(id)
        .fetch_optional(pool)
        .await?)
}

pub async fn active(pool: &MySqlPool) -> Result<Vec<Group>> {
    let sql = format!("SELECT {COLUMNS} FROM usenet_groups WHERE active = 1 ORDER BY name");
    Ok(sqlx::query_as(sqlx::AssertSqlSafe(sql))
        .fetch_all(pool)
        .await?)
}

/// Backfill-enabled groups that have had a first update. `date` orders newest first.
pub async fn backfill_enabled(pool: &MySqlPool, by_date: bool) -> Result<Vec<Group>> {
    let order = if by_date {
        "first_record_postdate DESC"
    } else {
        "name"
    };
    let sql = format!(
        "SELECT {COLUMNS} FROM usenet_groups WHERE backfill = 1 AND last_record <> 0 ORDER BY {order}"
    );
    Ok(sqlx::query_as(sqlx::AssertSqlSafe(sql))
        .fetch_all(pool)
        .await?)
}

/// Turns a group off when the server says it doesn't exist.
pub async fn disable(pool: &MySqlPool, id: u32) -> Result<()> {
    sqlx::query("UPDATE usenet_groups SET active = 0 WHERE id = ?")
        .bind(id)
        .execute(pool)
        .await?;
    Ok(())
}

pub async fn disable_backfill(pool: &MySqlPool, id: u32) -> Result<()> {
    sqlx::query("UPDATE usenet_groups SET backfill = 0 WHERE id = ?")
        .bind(id)
        .execute(pool)
        .await?;
    Ok(())
}

/// One line of the server's group list.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ServerGroup {
    pub name: String,
    pub first: u64,
    pub last: u64,
}

/// `groups:update`: rebuilds `short_groups` from the server's list, keeping only groups that are
/// active or backfilling.
pub async fn replace_short_groups(pool: &MySqlPool, listed: &[ServerGroup]) -> Result<usize> {
    sqlx::query("TRUNCATE TABLE short_groups")
        .execute(pool)
        .await?;
    let wanted: std::collections::HashSet<String> =
        sqlx::query_scalar("SELECT name FROM usenet_groups WHERE active = 1 OR backfill = 1")
            .fetch_all(pool)
            .await?
            .into_iter()
            .collect();
    let mut updated = 0;
    for group in listed.iter().filter(|group| wanted.contains(&group.name)) {
        sqlx::query(
            "INSERT INTO short_groups (name, first_record, last_record, updated) VALUES (?, ?, ?, NOW())",
        )
        .bind(&group.name)
        .bind(group.first)
        .bind(group.last)
        .execute(pool)
        .await?;
        updated += 1;
    }
    Ok(updated)
}
