//! Database protocol for independent worker hosts.
//!
//! Every source-sensitive transaction locks release → NZB content → job. Network work runs
//! outside transactions. The full random job token, unexpired lease and source digest fence
//! completion; a source replacement or takeover makes an older result uncommittable.
//! Provider slots are a shared account budget, not a per-process semaphore.

use anyhow::{Result, bail};
use flate2::bufread::GzDecoder;
use rand::RngCore;
use serde_json::Value;
use sha2::{Digest, Sha256};
use sqlx::{MySqlPool, Row};
use std::{
    io::Read,
    sync::{
        Arc,
        atomic::{AtomicU32, Ordering},
    },
    time::Duration,
};

pub const LEASE_SECONDS: u64 = 120;
#[derive(Debug, Clone)]
pub struct Claim {
    pub release_id: u64,
    pub guid: String,
    pub source_digest: String,
    pub token: String,
    pub body: Vec<u8>,
    pub attempts: u32,
    pub held_slots: Arc<AtomicU32>,
}
pub fn token() -> String {
    let mut bytes = [0u8; 16];
    rand::rng().fill_bytes(&mut bytes);
    hex::encode(bytes)
}
pub fn decode_nzb(claim: &Claim) -> Result<String> {
    if claim.body.len() > 33_554_432 {
        bail!("NzbCompressedBudgetExceeded");
    }
    let mut decoder = GzDecoder::new(claim.body.as_slice());
    let mut body = vec![];
    decoder.by_ref().take(134_217_729).read_to_end(&mut body)?;
    if body.len() > 134_217_728 {
        bail!("NzbDecodedBudgetExceeded");
    }
    if !decoder.into_inner().is_empty() {
        bail!("NzbGzipTrailingBytes");
    }
    let mut sha = Sha256::new();
    sha.update(b"nntmux:nzb-archive-naming:1\0");
    sha.update(&body);
    if hex::encode(sha.finalize()) != claim.source_digest {
        bail!("NzbSourceDigestMismatch");
    }
    Ok(String::from_utf8(body)?)
}
pub async fn claim(pool: &MySqlPool, worker: &str) -> Result<Option<Claim>> {
    let candidates=sqlx::query("SELECT release_id FROM release_fingerprint_jobs WHERE (status='pending' AND available_at <= UTC_TIMESTAMP(6)) OR (status='processing' AND lease_until < UTC_TIMESTAMP(6)) ORDER BY available_at, release_id LIMIT 32").fetch_all(pool).await?;
    for candidate in candidates {
        let id: u64 = candidate.try_get("release_id")?;
        let mut tx = pool.begin().await?;
        let release = sqlx::query("SELECT guid FROM releases WHERE id=? FOR UPDATE")
            .bind(id)
            .fetch_optional(&mut *tx)
            .await?;
        let Some(release) = release else {
            tx.rollback().await?;
            continue;
        };
        let guid: String = release.try_get("guid")?;
        let source = sqlx::query(
            "SELECT source_digest, body FROM release_nzb_contents WHERE guid=? FOR UPDATE",
        )
        .bind(&guid)
        .fetch_optional(&mut *tx)
        .await?;
        let Some(source) = source else {
            tx.rollback().await?;
            continue;
        };
        let digest: String = source.try_get("source_digest")?;
        let row=sqlx::query("SELECT source_digest, attempts FROM release_fingerprint_jobs WHERE release_id=? AND ((status='pending' AND available_at<=UTC_TIMESTAMP(6)) OR (status='processing' AND lease_until<UTC_TIMESTAMP(6))) FOR UPDATE").bind(id).fetch_optional(&mut *tx).await?;
        let Some(row) = row else {
            tx.rollback().await?;
            continue;
        };
        let expected: String = row.try_get("source_digest")?;
        if digest != expected {
            sqlx::query("UPDATE release_fingerprint_jobs SET source_digest=?, status='pending', attempts=0, available_at=UTC_TIMESTAMP(6),lease_token=NULL,lease_until=NULL,worker_id=NULL,last_error=NULL,updated_at=UTC_TIMESTAMP(6) WHERE release_id=?").bind(&digest).bind(id).execute(&mut *tx).await?;
            tx.commit().await?;
            continue;
        }
        let attempts: u32 = row.try_get("attempts")?;
        if attempts >= 3 {
            sqlx::query("UPDATE release_fingerprint_jobs SET status='failed',lease_token=NULL,lease_until=NULL,worker_id=NULL,last_error='AttemptsExhausted',updated_at=UTC_TIMESTAMP(6) WHERE release_id=?").bind(id).execute(&mut *tx).await?;
            tx.commit().await?;
            continue;
        }
        let token = token();
        sqlx::query("UPDATE release_fingerprint_jobs SET status='processing',attempts=attempts+1,lease_token=?,lease_until=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)),worker_id=?,last_error=NULL,updated_at=UTC_TIMESTAMP(6) WHERE release_id=?").bind(&token).bind(LEASE_SECONDS).bind(worker).bind(id).execute(&mut *tx).await?;
        let body: Vec<u8> = source.try_get("body")?;
        tx.commit().await?;
        return Ok(Some(Claim {
            release_id: id,
            guid,
            source_digest: digest,
            token,
            body,
            attempts: attempts + 1,
            held_slots: Arc::new(AtomicU32::new(0)),
        }));
    }
    Ok(None)
}
pub async fn renew(pool: &MySqlPool, c: &Claim) -> Result<bool> {
    let updated=sqlx::query("UPDATE release_fingerprint_jobs SET lease_until=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)),updated_at=UTC_TIMESTAMP(6) WHERE release_id=? AND status='processing' AND lease_token=? AND source_digest=? AND lease_until>UTC_TIMESTAMP(6)").bind(LEASE_SECONDS).bind(c.release_id).bind(&c.token).bind(&c.source_digest).execute(pool).await?.rows_affected();
    if updated != 1 {
        return Ok(false);
    }
    let expected = c.held_slots.load(Ordering::SeqCst);
    let renewed=sqlx::query("UPDATE release_fingerprint_connections SET lease_until=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)) WHERE lease_token=? AND lease_until>UTC_TIMESTAMP(6)").bind(LEASE_SECONDS).bind(&c.token).execute(pool).await?.rows_affected();
    Ok(renewed == u64::from(expected))
}
pub async fn slot(pool: &MySqlPool, c: &Claim, provider: &str, worker: &str) -> Result<u32> {
    let started = std::time::Instant::now();
    loop {
        let mut tx = pool.begin().await?;
        let row=sqlx::query("SELECT slot FROM release_fingerprint_connections WHERE provider=? AND (lease_token IS NULL OR lease_until<UTC_TIMESTAMP(6)) ORDER BY slot LIMIT 1 FOR UPDATE SKIP LOCKED").bind(provider).fetch_optional(&mut *tx).await?;
        if let Some(row) = row {
            let slot: u32 = row.try_get("slot")?;
            sqlx::query("UPDATE release_fingerprint_connections SET lease_token=?,lease_until=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)),worker_id=? WHERE provider=? AND slot=?").bind(&c.token).bind(LEASE_SECONDS).bind(worker).bind(provider).bind(slot).execute(&mut *tx).await?;
            tx.commit().await?;
            c.held_slots.fetch_add(1, Ordering::SeqCst);
            return Ok(slot);
        }
        tx.rollback().await?;
        if started.elapsed() > Duration::from_secs(90) {
            bail!("ProviderConnectionBudgetExhausted");
        }
        tokio::time::sleep(Duration::from_millis(250)).await;
    }
}
pub async fn release_slots(pool: &MySqlPool, c: &Claim) -> Result<()> {
    sqlx::query("UPDATE release_fingerprint_connections SET lease_token=NULL,lease_until=NULL,worker_id=NULL WHERE lease_token=?").bind(&c.token).execute(pool).await?;
    Ok(())
}
pub async fn finish(pool: &MySqlPool, c: &Claim, status: &str, metadata: &Value) -> Result<bool> {
    if !["complete", "partial", "unavailable"].contains(&status) {
        bail!("Invalid fingerprint status");
    }
    let mut tx = pool.begin().await?;
    let release = sqlx::query("SELECT guid FROM releases WHERE id=? FOR UPDATE")
        .bind(c.release_id)
        .fetch_optional(&mut *tx)
        .await?;
    let Some(release) = release else {
        tx.rollback().await?;
        return Ok(false);
    };
    let guid: String = release.try_get("guid")?;
    let source =
        sqlx::query("SELECT source_digest FROM release_nzb_contents WHERE guid=? FOR UPDATE")
            .bind(&guid)
            .fetch_optional(&mut *tx)
            .await?;
    let Some(source) = source else {
        tx.rollback().await?;
        return Ok(false);
    };
    let digest: String = source.try_get("source_digest")?;
    let job=sqlx::query("SELECT source_digest FROM release_fingerprint_jobs WHERE release_id=? AND status='processing' AND lease_token=? AND lease_until>UTC_TIMESTAMP(6) FOR UPDATE").bind(c.release_id).bind(&c.token).fetch_optional(&mut *tx).await?;
    if job.is_none()
        || digest != c.source_digest
        || job
            .as_ref()
            .unwrap()
            .try_get::<String, _>("source_digest")?
            != c.source_digest
    {
        tx.rollback().await?;
        return Ok(false);
    }
    sqlx::query("INSERT INTO release_fingerprint_metadata (releases_id,status,metadata,last_error,collected_at,run_token,created_at,updated_at) VALUES (?,?,?,NULL,UTC_TIMESTAMP(),?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status=VALUES(status),metadata=VALUES(metadata),last_error=NULL,collected_at=VALUES(collected_at),run_token=VALUES(run_token),updated_at=VALUES(updated_at)").bind(c.release_id).bind(status).bind(sqlx::types::Json(metadata)).bind(&c.token[..16]).execute(&mut *tx).await?;
    sqlx::query("UPDATE release_fingerprint_jobs SET status='complete',lease_token=NULL,lease_until=NULL,worker_id=NULL,last_error=NULL,updated_at=UTC_TIMESTAMP(6) WHERE release_id=?").bind(c.release_id).execute(&mut *tx).await?;
    tx.commit().await?;
    Ok(true)
}
pub async fn fail(pool: &MySqlPool, c: &Claim, kind: &str) -> Result<()> {
    let final_attempt = c.attempts >= 3;
    sqlx::query("UPDATE release_fingerprint_jobs SET status=?,available_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)),lease_token=NULL,lease_until=NULL,worker_id=NULL,last_error=?,updated_at=UTC_TIMESTAMP(6) WHERE release_id=? AND status='processing' AND lease_token=? AND source_digest=? AND lease_until>UTC_TIMESTAMP(6)").bind(if final_attempt{"failed"}else{"pending"}).bind(30*c.attempts).bind(kind).bind(c.release_id).bind(&c.token).bind(&c.source_digest).execute(pool).await?;
    Ok(())
}
