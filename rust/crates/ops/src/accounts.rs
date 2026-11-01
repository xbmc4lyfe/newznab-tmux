//! Scheduled account housekeeping that needs no mail.

use anyhow::Result;
use chrono::{NaiveDateTime, Utc};
use serde_json::{Value, json};
use sqlx::MySqlPool;

const DATE_TIME: &str = "%Y-%m-%d %H:%M:%S";

/// A promotion ending today is already past: `end_date` compares as midnight.
pub async fn disable_expired_promotions(db: &MySqlPool) -> Result<Vec<String>> {
    let expired: Vec<(u64, String)> = sqlx::query_as(
        "SELECT id, name FROM role_promotions WHERE is_active = 1 AND end_date IS NOT NULL \
         AND end_date < NOW()",
    )
    .fetch_all(db)
    .await?;
    for (id, _) in &expired {
        sqlx::query("UPDATE role_promotions SET is_active = 0, updated_at = NOW() WHERE id = ?")
            .bind(id)
            .execute(db)
            .await?;
    }
    Ok(expired.into_iter().map(|(_, name)| name).collect())
}

#[derive(sqlx::FromRow)]
struct Period {
    id: u64,
    name: String,
    starts_at: NaiveDateTime,
    ends_at: NaiveDateTime,
    notes: Option<String>,
    created_by: Option<u64>,
}

/// Each period's completion and its history row commit together, so a crash can't complete a
/// period without recording it.
pub async fn disable_expired_registration_periods(db: &MySqlPool) -> Result<Vec<String>> {
    let now = Utc::now().naive_utc();
    let expired: Vec<Period> = sqlx::query_as(
        "SELECT id, name, starts_at, ends_at, notes, created_by FROM registration_periods \
         WHERE is_enabled = 1 AND ends_at < ?",
    )
    .bind(now)
    .fetch_all(db)
    .await?;
    let mut names = Vec::with_capacity(expired.len());
    for period in expired {
        let mut tx = db.begin().await?;
        let changed = sqlx::query(
            "UPDATE registration_periods SET is_enabled = 0, updated_by = NULL, updated_at = NOW() \
             WHERE id = ? AND is_enabled = 1",
        )
        .bind(period.id)
        .execute(&mut *tx)
        .await?
        .rows_affected();
        if changed == 0 {
            continue;
        }
        sqlx::query(
            "INSERT INTO registration_status_history \
             (action, description, registration_period_id, metadata, created_at, updated_at) \
             VALUES ('period_completed', ?, ?, ?, NOW(), NOW())",
        )
        .bind(format!(
            "Scheduled open period \"{}\" completed after reaching its end time.",
            period.name
        ))
        .bind(period.id)
        .bind(completion_metadata(&period, now).to_string())
        .execute(&mut *tx)
        .await?;
        tx.commit().await?;
        names.push(period.name);
    }
    Ok(names)
}

fn completion_metadata(period: &Period, now: NaiveDateTime) -> Value {
    json!({
        "completed_at": now.format(DATE_TIME).to_string(),
        "period": {
            "id": period.id,
            "name": period.name,
            "starts_at": period.starts_at.format(DATE_TIME).to_string(),
            "ends_at": period.ends_at.format(DATE_TIME).to_string(),
            "is_enabled": false,
            "notes": period.notes,
            "created_by": period.created_by,
            "updated_by": null,
        },
    })
}
