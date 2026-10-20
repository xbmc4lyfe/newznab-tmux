use crate::database;
use anyhow::Result;
use sqlx::{MySqlPool, Row};

#[derive(Debug)]
pub struct Decision {
    pub skipped_reason: Option<String>,
    generation: Option<String>,
    token: String,
}
pub async fn begin(pool: &MySqlPool, provider: &str) -> Result<Decision> {
    let token = database::token();
    let mut tx = pool.begin().await?;
    sqlx::query("INSERT IGNORE INTO release_fingerprint_provider_state(provider) VALUES(?)")
        .bind(provider)
        .execute(&mut *tx)
        .await?;
    let row=sqlx::query("SELECT generation,blocked_until>UTC_TIMESTAMP(6) AS blocked,probe_token,probe_until>UTC_TIMESTAMP(6) AS probing FROM release_fingerprint_provider_state WHERE provider=? FOR UPDATE").bind(provider).fetch_one(&mut *tx).await?;
    let generation: Option<String> = row.try_get("generation")?;
    let blocked: Option<i32> = row.try_get("blocked")?;
    let probing: Option<i32> = row.try_get("probing")?;
    let skipped_reason = if generation.is_none() {
        None
    } else if blocked == Some(1) {
        Some("recent_provider_failure".into())
    } else if probing == Some(1) {
        Some("provider_probe_in_progress".into())
    } else {
        sqlx::query("UPDATE release_fingerprint_provider_state SET probe_token=?,probe_until=TIMESTAMPADD(SECOND,120,UTC_TIMESTAMP(6)) WHERE provider=?").bind(&token).bind(provider).execute(&mut *tx).await?;
        None
    };
    tx.commit().await?;
    Ok(Decision {
        skipped_reason,
        generation,
        token,
    })
}
pub async fn unavailable(pool: &MySqlPool, provider: &str) -> Result<()> {
    sqlx::query("INSERT INTO release_fingerprint_provider_state(provider,generation,blocked_until,probe_token,probe_until) VALUES(?,?,TIMESTAMPADD(SECOND,900,UTC_TIMESTAMP(6)),NULL,NULL) ON DUPLICATE KEY UPDATE generation=VALUES(generation),blocked_until=VALUES(blocked_until),probe_token=NULL,probe_until=NULL").bind(provider).bind(database::token()).execute(pool).await?;
    Ok(())
}
pub async fn reachable(pool: &MySqlPool, provider: &str, decision: &Decision) -> Result<()> {
    if let Some(generation) = &decision.generation {
        sqlx::query("UPDATE release_fingerprint_provider_state SET generation=NULL,blocked_until=NULL,probe_token=NULL,probe_until=NULL WHERE provider=? AND generation=? AND probe_token=? AND probe_until>UTC_TIMESTAMP(6)").bind(provider).bind(generation).bind(&decision.token).execute(pool).await?;
    }
    Ok(())
}
pub async fn release_probe(pool: &MySqlPool, provider: &str, decision: &Decision) -> Result<()> {
    if let Some(generation) = &decision.generation {
        sqlx::query("UPDATE release_fingerprint_provider_state SET probe_token=NULL,probe_until=NULL WHERE provider=? AND generation=? AND probe_token=?").bind(provider).bind(generation).bind(&decision.token).execute(pool).await?;
    }
    Ok(())
}
