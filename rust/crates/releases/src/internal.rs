//! `POST /internal/release-verification/publish` and `publish-batch`: a remote worker's spot-checked
//! candidate, published on the host holding NZB storage. Only the sampled evidence travels. The
//! manifest is rebuilt from the stored copy, and the sender's lease must still own the candidate.

use std::net::IpAddr;
use std::sync::Arc;
use std::time::Instant;

use anyhow::Result;
use axum::body::Bytes;
use axum::extract::{DefaultBodyLimit, Request, State};
use axum::http::StatusCode;
use axum::middleware::{Next, from_fn};
use axum::response::{IntoResponse, Response};
use axum::routing::post;
use axum::{Extension, Json, Router};
use ipnet::IpNet;
use nntmux_categorize::Categorizer;
use nntmux_core::{parsed_or, var_or};
use nntmux_predb::PredbIndex;
use nntmux_runtime::Ctx;
use nntmux_verify::candidates::{self, Candidate, Claim};
use nntmux_verify::cleaning::ReleaseCleaner;
use nntmux_verify::context::Verify;
use nntmux_verify::inventory::{self, HeaderStatus};
use nntmux_verify::locks::{self, Held, LockSession};
use nntmux_verify::manifest::{self, Evidence};
use nntmux_verify::metadata::Resolver;
use nntmux_verify::publisher::{Outcome, PublishError, publish};
use nntmux_verify::spot_check::{VerificationResult, with_evidence};
use nntmux_webkit::client_ip;
use serde_json::{Value, json};
use sqlx::MySqlPool;
use tokio::sync::OnceCell;

use crate::Releases;

const STALE: &str = "Stale verification result cannot publish.";
const PAUSED: &str = "Verification is paused for exclusive maintenance; retry later.";
const BATCH_PROTOCOL: i64 = 1;
const BATCH_HARD_MAX_ITEMS: usize = 64;

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct PublicationOutcome {
    pub status: &'static str,
    pub release_id: Option<i64>,
    pub error: Option<String>,
}

fn outcome(status: &'static str) -> PublicationOutcome {
    PublicationOutcome {
        status,
        release_id: None,
        error: None,
    }
}

fn failed(status: &'static str, error: &str) -> PublicationOutcome {
    PublicationOutcome {
        status,
        release_id: None,
        error: Some(candidates::cut(error, 500).to_owned()),
    }
}

#[derive(Clone, Debug)]
pub struct Item {
    id: i64,
    version: i64,
    manifest_hash: String,
    lease_token: String,
    reason: String,
    evidence: Evidence,
}

fn integer(value: &Value, min: i64, max: i64, name: &str) -> Result<i64, String> {
    let found = value
        .as_i64()
        .or_else(|| value.as_str().and_then(|text| text.parse().ok()))
        .ok_or_else(|| format!("The {name} field must be an integer."))?;
    if found < min || found > max {
        return Err(format!("The {name} field is out of range."));
    }
    Ok(found)
}

fn text(value: &Value, max: usize, name: &str) -> Result<String, String> {
    let found = value
        .as_str()
        .filter(|found| !found.is_empty())
        .ok_or_else(|| format!("The {name} field is required."))?;
    if found.chars().count() > max {
        return Err(format!("The {name} field is too long."));
    }
    Ok(found.to_owned())
}

fn names(value: &Value, name: &str) -> Result<Vec<String>, String> {
    let list = value
        .as_array()
        .filter(|list| list.len() <= 16)
        .ok_or_else(|| format!("The {name} field must be a list of at most 16."))?;
    list.iter()
        .map(|entry| {
            entry
                .as_str()
                .filter(|entry| entry.chars().count() <= 1024)
                .map(str::to_owned)
                .ok_or_else(|| format!("The {name} field holds an invalid name."))
        })
        .collect()
}

/// `itemRules`: the same limits for a single request and each batch item.
pub fn validate(data: &Value) -> Result<Item, String> {
    let claim = &data["claim"];
    let evidence = &data["evidence"];
    let manifest_hash = text(&claim["manifest_hash"], 64, "claim.manifest_hash")?;
    if manifest_hash.chars().count() != 64 {
        return Err("The claim.manifest_hash field must be 64 characters.".to_owned());
    }
    let parity = &evidence["parity_file_keys"];
    Ok(Item {
        id: integer(&claim["id"], 1, i64::MAX, "claim.id")?,
        version: integer(&claim["version"], 0, i64::MAX, "claim.version")?,
        manifest_hash,
        lease_token: text(&claim["lease_token"], 64, "claim.lease_token")?,
        reason: text(&data["reason"], 2000, "reason")?,
        evidence: Evidence {
            articles: integer(&evidence["articles"], 1, 16, "evidence.articles")?,
            bytes: integer(&evidence["bytes"], 1, 1_048_576, "evidence.bytes")?,
            sampled_files: integer(&evidence["sampled_files"], 1, 16, "evidence.sampled_files")?,
            verified_file_names: names(
                &evidence["verified_file_names"],
                "evidence.verified_file_names",
            )?,
            identity_hints: names(&evidence["identity_hints"], "evidence.identity_hints")?,
            // Decided from the stored inventory below, never by the sender.
            partial: false,
            parity_file_keys: if parity.is_null() {
                Vec::new()
            } else {
                names(parity, "evidence.parity_file_keys")?
            },
        },
    })
}

/// A publication writes as a worker does, so a reset, audit or barrier holding the worker group
/// excludes it. A batch keeps one acquisition, and once refused stays refused.
pub struct Gate {
    retain: bool,
    refused: bool,
    session: Option<LockSession>,
    held: Option<Held>,
}

impl Gate {
    pub fn new(retain: bool) -> Self {
        Self {
            retain,
            refused: false,
            session: None,
            held: None,
        }
    }

    async fn enter(&mut self, pool: &MySqlPool) -> Result<bool> {
        if self.refused {
            return Ok(false);
        }
        if self.session.is_none() {
            self.session = Some(LockSession::open(pool).await?);
        }
        let session = self.session.as_mut().expect("opened above");
        if let Some(held) = &self.held
            && !session.holds(held).await
        {
            // A database lock ends with its session, so protection is proved, not assumed.
            self.held = None;
            self.session = Some(LockSession::open(pool).await?);
        }
        let session = self.session.as_mut().expect("opened above");
        if self.held.is_none() {
            self.held = session.shared("worker").await?;
        }
        if self.held.is_some()
            && locks::paused(pool, None).await?
            && let Some(held) = self.held.take()
        {
            session.release(held).await;
        }
        if self.held.is_none() {
            self.refused = self.retain;
            return Ok(false);
        }
        Ok(true)
    }

    async fn leave(&mut self) {
        if !self.retain {
            self.close().await;
        }
    }

    pub async fn close(&mut self) {
        if let (Some(session), Some(held)) = (self.session.as_mut(), self.held.take()) {
            session.release(held).await;
        }
    }
}

/// The release this exact generation already published, or when replaying its terminal status. A
/// repeated request after a lost response finds it here.
async fn settled(
    pool: &MySqlPool,
    item: &Item,
    replay: bool,
) -> Result<Option<PublicationOutcome>> {
    let published: Option<i64> = sqlx::query_scalar(
        "SELECT CAST(release_id AS SIGNED) FROM release_verification_candidates \
         WHERE id = ? AND version = ? AND manifest_hash = ? AND release_id IS NOT NULL",
    )
    .bind(item.id)
    .bind(item.version)
    .bind(&item.manifest_hash)
    .fetch_optional(pool)
    .await?;
    if let Some(release) = published {
        return Ok(Some(PublicationOutcome {
            status: "published",
            release_id: Some(release),
            error: None,
        }));
    }
    if !replay {
        return Ok(None);
    }
    Ok(resolved_status(pool, item).await?.map(outcome))
}

async fn resolved_status(pool: &MySqlPool, item: &Item) -> Result<Option<&'static str>> {
    let status: Option<String> = sqlx::query_scalar(
        "SELECT status FROM release_verification_candidates WHERE id = ? AND version = ? AND manifest_hash = ? \
         AND release_id IS NULL AND status IN ('filtered', 'metadata_review')",
    )
    .bind(item.id)
    .bind(item.version)
    .bind(&item.manifest_hash)
    .fetch_optional(pool)
    .await?;
    Ok(status.map(|status| {
        if status == "filtered" {
            "filtered"
        } else {
            "metadata_review"
        }
    }))
}

/// What publishing needs beyond the database. Built once per process.
pub struct Publication {
    pub verify: Verify,
    pub cleaner: ReleaseCleaner,
    pub predb: PredbIndex,
    pub categorizer: Categorizer,
}

impl Publication {
    fn resolver(&self) -> Resolver<'_> {
        Resolver {
            db: &self.verify.db,
            cleaner: &self.cleaner,
            predb: &self.predb,
            categorizer: &self.categorizer,
        }
    }

    async fn attempt(
        &self,
        item: &Item,
        gate: &mut Gate,
        replay: bool,
    ) -> Result<PublicationOutcome> {
        let verify = &self.verify;
        let pool = &verify.db;
        if let Some(done) = settled(pool, item, replay).await? {
            return Ok(done);
        }
        let row: Option<Candidate> =
            sqlx::query_as("SELECT * FROM release_verification_candidates WHERE id = ?")
                .bind(item.id)
                .fetch_optional(pool)
                .await?;
        let current = row.filter(|row| {
            row.version == item.version
                && row.manifest_hash == item.manifest_hash
                && row.lease_token.as_deref() == Some(item.lease_token.as_str())
                && !row.manifest_path.is_empty()
        });
        let Some(candidate) = current else {
            // A concurrent first attempt may have published and cleared the lease since the check.
            return Ok(settled(pool, item, replay)
                .await?
                .unwrap_or_else(|| failed("stale", STALE)));
        };
        let archived = manifest::read(pool, &verify.config, &candidate.manifest_path).await?;
        let mut evidence = item.evidence.clone();
        evidence.partial = inventory::assess(&archived).status == HeaderStatus::WaitingUpload;
        let sampled = with_evidence(&archived, &evidence);
        if !gate.enter(pool).await? {
            return Ok(failed("paused", PAUSED));
        }
        let claim = Claim {
            candidate,
            token: item.lease_token.clone(),
        };
        let result = VerificationResult {
            verdict: "sampled".to_owned(),
            reason: item.reason.clone(),
            manifest: Some(sampled),
            ..VerificationResult::default()
        };
        let published = publish(verify, &self.resolver(), &claim, &result).await;
        gate.leave().await;
        match published {
            Ok(Outcome::Published(id) | Outcome::Upgraded(id) | Outcome::Duplicate(id)) => {
                Ok(PublicationOutcome {
                    status: "published",
                    release_id: Some(id),
                    error: None,
                })
            }
            // Policy filtered it, or a covered release lacks its metadata.
            Ok(_) => {
                let status = if replay {
                    resolved_status(pool, item).await?
                } else {
                    None
                };
                Ok(match status {
                    Some(status) => outcome(status),
                    None if replay => failed("unresolved", "Publication produced no release."),
                    None => outcome("unresolved"),
                })
            }
            Err(failure) => {
                if let Some(PublishError::PartialDeferred(reason)) = failure.downcast_ref() {
                    // The sending worker returns the partial to waiting for one more hold window.
                    return Ok(failed("deferred", reason));
                }
                if let Some(done) = settled(pool, item, replay).await? {
                    return Ok(done);
                }
                if matches!(failure.downcast_ref(), Some(PublishError::Stale)) {
                    return Ok(failed("stale", STALE));
                }
                Ok(failed("error", &format!("{failure:#}")))
            }
        }
    }

    /// Publishes one validated item. A claim whose generation already ended answers with that
    /// status when `replay` is set, so a resent batch is idempotent.
    pub async fn publish_item(
        &self,
        item: &Item,
        gate: &mut Gate,
        replay: bool,
    ) -> PublicationOutcome {
        match self.attempt(item, gate, replay).await {
            Ok(done) => done,
            Err(failure) => {
                let pool = &self.verify.db;
                if let Ok(Some(done)) = settled(pool, item, replay).await {
                    return done;
                }
                failed("error", &format!("{failure:#}"))
            }
        }
    }

    /// One item at a time, each in its own transaction. Items not started when the budget ends are
    /// answered `retry`, and the first always starts.
    pub async fn publish_batch(&self, items: &[Value], budget_seconds: f64) -> Vec<Value> {
        let started = Instant::now();
        let mut gate = Gate::new(true);
        let mut results = Vec::with_capacity(items.len());
        let mut exhausted = false;
        for (index, raw) in items.iter().enumerate() {
            exhausted =
                exhausted || (index > 0 && started.elapsed().as_secs_f64() >= budget_seconds);
            let done = if exhausted {
                failed("retry", "Batch time budget exhausted; resend this item.")
            } else {
                match validate(raw) {
                    Err(reason) => failed("invalid", &reason),
                    Ok(item) => {
                        let done = self.publish_item(&item, &mut gate, true).await;
                        if done.status == "unresolved" {
                            failed(
                                "error",
                                done.error
                                    .as_deref()
                                    .unwrap_or("Publication produced no release."),
                            )
                        } else {
                            done
                        }
                    }
                }
            };
            let claim_id = integer(&raw["claim"]["id"], i64::MIN, i64::MAX, "").ok();
            let claim_version = integer(&raw["claim"]["version"], i64::MIN, i64::MAX, "").ok();
            results.push(json!({
                "index": index,
                "claim_id": claim_id,
                "claim_version": claim_version,
                "status": done.status,
                "release_id": done.release_id,
                "error": done.error,
            }));
        }
        gate.close().await;
        results
    }
}

/// Read once when the router is built.
#[derive(Clone, Debug)]
pub struct Settings {
    pub enabled: bool,
    pub networks: Vec<IpNet>,
    pub batch_items: usize,
    pub batch_bytes: usize,
    pub batch_seconds: f64,
}

fn network(entry: &str) -> Option<IpNet> {
    let entry = entry.trim();
    entry
        .parse()
        .ok()
        .or_else(|| entry.parse::<IpAddr>().ok().map(IpNet::from))
}

impl Settings {
    pub fn from_env() -> Self {
        let enabled = var_or("RELEASE_VERIFICATION_PUBLISH_ENDPOINT", "false");
        let items: usize =
            parsed_or("RELEASE_VERIFICATION_PUBLISH_BATCH_MAX_ITEMS", 16).unwrap_or(16);
        let bytes: usize = parsed_or("RELEASE_VERIFICATION_PUBLISH_BATCH_MAX_BYTES", 1_048_576)
            .unwrap_or(1_048_576);
        let seconds: f64 =
            parsed_or("RELEASE_VERIFICATION_PUBLISH_BATCH_SECONDS", 15.0).unwrap_or(15.0);
        Self {
            enabled: matches!(
                enabled.to_ascii_lowercase().as_str(),
                "true" | "1" | "(true)"
            ),
            networks: var_or(
                "RELEASE_VERIFICATION_PUBLISH_ALLOWED_NETWORKS",
                "10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.0/8",
            )
            .split(',')
            .filter_map(network)
            .collect(),
            batch_items: items.clamp(1, BATCH_HARD_MAX_ITEMS),
            batch_bytes: bytes.max(1),
            batch_seconds: seconds.max(0.0),
        }
    }
}

struct Shared {
    settings: Settings,
    publication: OnceCell<Publication>,
}

impl Shared {
    async fn publication(&self, ctx: &Ctx) -> Result<&Publication> {
        self.publication
            .get_or_try_init(|| async {
                Ok(Publication {
                    verify: Releases::new(ctx)?.verify,
                    cleaner: ReleaseCleaner::new(),
                    predb: PredbIndex::new(&ctx.config.manticore),
                    categorizer: Categorizer::load(&ctx.db).await?,
                })
            })
            .await
    }
}

/// Off unless configured, and then for private-network clients only. It has no authentication.
async fn endpoint_gate(
    Extension(shared): Extension<Arc<Shared>>,
    request: Request,
    next: Next,
) -> Response {
    if !shared.settings.enabled {
        return StatusCode::NOT_FOUND.into_response();
    }
    let (parts, body) = request.into_parts();
    let ip = client_ip::from_parts(&parts).0;
    if !shared.settings.networks.iter().any(|net| net.contains(&ip)) {
        return StatusCode::FORBIDDEN.into_response();
    }
    next.run(Request::from_parts(parts, body)).await
}

fn internal_error(failure: &anyhow::Error) -> Response {
    tracing::error!(
        error = format!("{failure:#}"),
        "internal publication failed"
    );
    (
        StatusCode::INTERNAL_SERVER_ERROR,
        Json(json!({"error": "Server error."})),
    )
        .into_response()
}

/// A client that gives up mustn't roll back a publication under way, nor stop the work after its
/// commit: dropping a handler would do both. Its retry finds the release.
async fn detached<T: Send + 'static>(
    work: impl Future<Output = Result<T>> + Send + 'static,
) -> Result<T> {
    tokio::spawn(work).await?
}

async fn publish_single(
    State(ctx): State<Ctx>,
    Extension(shared): Extension<Arc<Shared>>,
    body: Bytes,
) -> Response {
    let data: Value = serde_json::from_slice(&body).unwrap_or(Value::Null);
    let item = match validate(&data) {
        Ok(item) => item,
        Err(reason) => {
            return (
                StatusCode::UNPROCESSABLE_ENTITY,
                Json(json!({"message": reason})),
            )
                .into_response();
        }
    };
    let done = detached(async move {
        let service = shared.publication(&ctx).await?;
        let mut gate = Gate::new(false);
        let done = service.publish_item(&item, &mut gate, false).await;
        gate.close().await;
        Ok(done)
    })
    .await;
    let done = match done {
        Ok(done) => done,
        Err(failure) => return internal_error(&failure),
    };
    match done.status {
        "published" => Json(json!({"release_id": done.release_id})).into_response(),
        "deferred" => (StatusCode::CONFLICT, Json(json!({"deferred": done.error}))).into_response(),
        "filtered" | "metadata_review" | "unresolved" => {
            Json(json!({"release_id": null})).into_response()
        }
        _ => (StatusCode::CONFLICT, Json(json!({"error": done.error}))).into_response(),
    }
}

async fn publish_batch(
    State(ctx): State<Ctx>,
    Extension(shared): Extension<Arc<Shared>>,
    body: Bytes,
) -> Response {
    let limits = shared.settings.clone();
    if body.len() > limits.batch_bytes {
        let error = format!("Batch body exceeds {} bytes.", limits.batch_bytes);
        return (
            StatusCode::PAYLOAD_TOO_LARGE,
            Json(json!({"error": error, "max_bytes": limits.batch_bytes})),
        )
            .into_response();
    }
    let data: Value = serde_json::from_slice(&body).unwrap_or(Value::Null);
    if data["protocol"].as_i64() != Some(BATCH_PROTOCOL) {
        return (
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(json!({"error": "Unsupported batch protocol.", "supported_protocol": BATCH_PROTOCOL})),
        )
            .into_response();
    }
    let Some(items) = data["items"].as_array().filter(|items| !items.is_empty()) else {
        return (
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(json!({"error": "items must be a non-empty list.", "max_items": limits.batch_items})),
        )
            .into_response();
    };
    if items.len() > limits.batch_items {
        let error = format!("A batch holds at most {} items.", limits.batch_items);
        return (
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(json!({"error": error, "max_items": limits.batch_items})),
        )
            .into_response();
    }
    let items = items.clone();
    let results = detached(async move {
        let service = shared.publication(&ctx).await?;
        Ok(service
            .publish_batch(&items, shared.settings.batch_seconds)
            .await)
    })
    .await;
    let results = match results {
        Ok(results) => results,
        Err(failure) => return internal_error(&failure),
    };
    Json(json!({"protocol": BATCH_PROTOCOL, "results": results})).into_response()
}

pub fn router() -> Router<Ctx> {
    routes(Settings::from_env(), None)
}

/// `publication` replaces the one built from the context on the first request.
pub fn routes(settings: Settings, publication: Option<Publication>) -> Router<Ctx> {
    // The batch handler answers an oversized body itself, with its limit.
    let body_limit = settings.batch_bytes.max(16 * 1024 * 1024);
    let shared = Arc::new(Shared {
        settings,
        publication: publication
            .map_or_else(OnceCell::new, |found| OnceCell::new_with(Some(found))),
    });
    Router::new()
        .route(
            "/internal/release-verification/publish",
            post(publish_single),
        )
        .route(
            "/internal/release-verification/publish-batch",
            post(publish_batch),
        )
        .layer(DefaultBodyLimit::max(body_limit))
        .layer(from_fn(endpoint_gate))
        .layer(Extension(shared))
}
