//! The candidate worker: claims candidates, spot checks them over NNTP and publishes the results.
//!
//! Spot checks stay in memory, so this writes no scratch directories. It still cleans up the ones
//! earlier workers registered. Prefetching samples across candidates isn't ported: PHP only did it
//! when `RELEASE_VERIFICATION_SAMPLE_BATCH_SIZE` was raised above its default of one.

use std::collections::HashMap;
use std::path::Path;
use std::sync::Arc;
use std::sync::atomic::{AtomicI64, Ordering};
use std::time::{Duration, Instant};

use anyhow::{Result, anyhow, bail};
use async_trait::async_trait;
use nntmux_categorize::Categorizer;
use nntmux_core::{parsed_or, var_or};
use nntmux_nntp::pipeline::{Batch, BatchError, Mode, verification_article_batch};
use nntmux_nntp::{NntpClient, NntpConfig, NntpError};
use nntmux_predb::PredbIndex;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown, busy};
use tokio::sync::{Mutex, watch};
use tokio::task::{JoinHandle, JoinSet};
use tracing::{error, info, warn};

use crate::candidates::{self, Claim, FinishedAttempt};
use crate::cleaning::ReleaseCleaner;
use crate::context::Verify;
use crate::in_tx;
use crate::locks::WorkerLocks;
use crate::manifest;
use crate::metadata::Resolver;
use crate::prepare::{self, PrepareOptions};
use crate::publisher::{Outcome, PublishError, publish};
use crate::spot_check::{
    self, ArticleBudgetExceeded, ArticleSource, SpotCheckOptions, VerificationResult,
};

const MAX_ARTICLE_BYTES: i64 = 1_048_576;
const MAX_STAT_REQUESTS: usize = 16;
const MAINTENANCE_BUDGET: Duration = Duration::from_secs(5);
const PREPARE_EVERY: Duration = Duration::from_secs(60);

/// Spot checks read from the primary provider only, over one connection per worker.
pub struct NntpSource {
    client: Mutex<NntpClient>,
    maximum_bytes: i64,
}

impl NntpSource {
    pub fn new(config: Arc<NntpConfig>, verify: &Verify) -> Self {
        let limits = &verify.config;
        Self {
            client: Mutex::new(NntpClient::new(config)),
            maximum_bytes: MAX_ARTICLE_BYTES
                .min(limits.spot_check_bytes)
                .min(limits.spot_check_article_bytes)
                .min(limits.article_bytes)
                .max(0),
        }
    }
}

fn fetch_error(failure: NntpError) -> anyhow::Error {
    match failure {
        NntpError::BudgetExceeded(message) => ArticleBudgetExceeded(message).into(),
        other => anyhow!(other),
    }
}

#[async_trait]
impl ArticleSource for NntpSource {
    async fn fetch_bounded(&self, message_id: &str, max_bytes: i64) -> Result<Vec<u8>> {
        if max_bytes < 1 {
            bail!("Spot-check transfer budget is exhausted.");
        }
        let maximum = self.maximum_bytes.min(max_bytes);
        let mut client = self.client.lock().await;
        client.do_connect(false, false).await.map_err(fetch_error)?;
        let (body, _) = client
            .verification_article(message_id, maximum)
            .await
            .map_err(fetch_error)?;
        Ok(body)
    }

    /// Extra availability evidence. It never replaces the sampled BODY checks.
    async fn stat(&self, message_ids: &[String]) -> Result<()> {
        if message_ids.is_empty() {
            return Ok(());
        }
        if message_ids.len() > MAX_STAT_REQUESTS {
            bail!("Too many verification STAT requests.");
        }
        let mut client = self.client.lock().await;
        client.do_connect(false, false).await.map_err(fetch_error)?;
        let batch = Batch {
            client: &mut client,
            ids: message_ids.to_vec(),
        };
        let mut heartbeat = || Ok::<(), std::convert::Infallible>(());
        let mut results = verification_article_batch(
            vec![batch],
            1,
            message_ids.len() as i64,
            Mode::Stat,
            &mut heartbeat,
        )
        .await
        .map_err(|failure| match failure {
            BatchError::Invalid(failure) => anyhow!(failure),
            BatchError::Heartbeat(never) => match never {},
        })?;
        for id in message_ids {
            match results.remove(id) {
                Some(Ok(_)) => {}
                Some(Err(failure)) => return Err(anyhow!(failure)),
                None => bail!("Verification STAT response is unavailable."),
            }
        }
        Ok(())
    }
}

/// Everything a worker needs to publish, shared across its slots.
pub struct Worker {
    pub verify: Verify,
    pub cleaner: ReleaseCleaner,
    pub predb: PredbIndex,
    pub categorizer: Categorizer,
    pub nntp: Arc<NntpConfig>,
    /// Reads the operators' pause between candidates. Absent in tests.
    ctx: Option<Ctx>,
    /// Where terminal scratch cleanup resumes, for this host's next maintenance turn.
    scratch_after: AtomicI64,
    scratch_ceiling: AtomicI64,
}

/// One run's bounds, as the scheduled command took them.
#[derive(Clone, Copy, Debug)]
pub struct RunOptions {
    pub limit: i64,
    /// Zero selects a free slot.
    pub slot: i64,
    /// Stop claiming after this long. The active candidate still finishes. Zero means no limit.
    pub max_seconds: u64,
}

/// How a run went.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct RunReport {
    pub started: bool,
    pub processed: u64,
}

impl Worker {
    pub async fn new(ctx: &Ctx, verify: Verify) -> Result<Self> {
        Ok(Self {
            categorizer: Categorizer::load(&ctx.db).await?,
            verify,
            cleaner: ReleaseCleaner::new(),
            predb: PredbIndex::new(&ctx.config.manticore),
            nntp: Arc::new(NntpConfig::from_env()),
            ctx: Some(ctx.clone()),
            scratch_after: AtomicI64::new(0),
            scratch_ceiling: AtomicI64::new(0),
        })
    }

    /// For tests: a worker without PreDB, reading from the given provider settings.
    pub async fn for_tests(verify: Verify, nntp: Arc<NntpConfig>) -> Result<Self> {
        Ok(Self {
            categorizer: Categorizer::load(&verify.db).await?,
            verify,
            cleaner: ReleaseCleaner::new(),
            predb: PredbIndex::disabled(),
            nntp,
            ctx: None,
            scratch_after: AtomicI64::new(0),
            scratch_ceiling: AtomicI64::new(0),
        })
    }

    fn spot_check_options(&self) -> SpotCheckOptions {
        let config = &self.verify.config;
        SpotCheckOptions {
            articles: config.spot_check_articles,
            bytes: config.spot_check_bytes,
            article_bytes: config.spot_check_article_bytes,
            stat_all_articles: config.stat_all_articles,
        }
    }

    /// `releases:verify-candidates`. A run that can't get a slot reports `started: false`.
    pub async fn run(
        &self,
        source: &dyn ArticleSource,
        options: RunOptions,
        stop: &Shutdown,
    ) -> Result<RunReport> {
        let verify = &self.verify;
        let mut locks = WorkerLocks::new(&verify.db, &verify.config).await?;
        if !locks.start(options.slot.max(0)).await? {
            locks.close().await;
            return Ok(RunReport::default());
        }
        let outcome = self.run_started(source, options, stop, &mut locks).await;
        locks.close().await;
        outcome
    }

    async fn run_started(
        &self,
        source: &dyn ArticleSource,
        options: RunOptions,
        stop: &Shutdown,
        locks: &mut WorkerLocks,
    ) -> Result<RunReport> {
        let started = Instant::now();
        let expired =
            || options.max_seconds > 0 && started.elapsed().as_secs() >= options.max_seconds;
        self.maintain(locks).await?;
        let mut report = RunReport {
            started: true,
            processed: 0,
        };
        for _ in 0..options.limit.max(1) {
            if stop.requested() || expired() {
                break;
            }
            // Database locks end with their session: stop rather than run unprotected.
            if !locks.held().await {
                bail!("Verification worker locks were lost.");
            }
            if !locks.admitting().await? {
                break;
            }
            if let Some(ctx) = &self.ctx
                && ctx.paused().await.unwrap_or(true)
            {
                break;
            }
            let prefer_ready = locks.prefer_primary_queue(true);
            let Some(claim) = candidates::claim(&self.verify, locks, prefer_ready).await? else {
                break;
            };
            self.process(source, &claim).await;
            locks.release_candidate(Some(claim.id())).await;
            report.processed += 1;
        }
        Ok(report)
    }

    async fn maintain(&self, locks: &mut WorkerLocks) -> Result<()> {
        if !locks.try_gc().await? {
            return Ok(());
        }
        // A slow maintenance backlog mustn't monopolize a verification slot.
        let deadline = Instant::now() + MAINTENANCE_BUDGET;
        let outcome = async {
            let expiry_first = locks.prefer_expiry();
            // Each host cleans its own scratch. Expiry is shared, so another host may hold it.
            let expire = locks.try_expiry().await?;
            if expiry_first && expire {
                candidates::expire(&self.verify, Some(deadline)).await?;
            }
            self.clean_terminal_scratch(locks, deadline).await?;
            if !expiry_first && expire {
                candidates::expire(&self.verify, Some(deadline)).await?;
            }
            Ok::<(), anyhow::Error>(())
        }
        .await;
        locks.release_gc().await;
        outcome
    }

    /// Verifies and publishes one claim. A failure is recorded on the candidate, not returned, so
    /// one bad candidate doesn't end the run.
    async fn process(&self, source: &dyn ArticleSource, claim: &Claim) {
        let started = Instant::now();
        let id = claim.id();
        let outcome = self.verify_and_publish(source, claim).await;
        match outcome {
            Ok((result, bytes)) => {
                self.write_status(bytes, started);
                info!(candidate = id, verdict = %result.verdict, reason = %result.reason, "verified");
            }
            Err(failure) => {
                if let Some(PublishError::PartialDeferred(reason)) = failure.downcast_ref() {
                    match candidates::defer_partial(&self.verify, claim, reason).await {
                        Ok(()) => info!(candidate = id, reason, "partial publication deferred"),
                        Err(failure) => warn!(
                            candidate = id,
                            error = format!("{failure:#}"),
                            "could not defer"
                        ),
                    }
                    return;
                }
                let message = format!("{failure:#}");
                match self.settled_without_release(claim).await {
                    Ok(true) => info!(candidate = id, "already settled"),
                    Ok(false) => {
                        let attempt = FinishedAttempt {
                            reason: &message,
                            ..FinishedAttempt::default()
                        };
                        warn!(candidate = id, error = %message, "retry pending");
                        if let Err(failure) =
                            candidates::finish(&self.verify, claim, &attempt).await
                        {
                            error!(
                                candidate = id,
                                error = format!("{failure:#}"),
                                "could not record the failure"
                            );
                        }
                    }
                    Err(failure) => {
                        error!(
                            candidate = id,
                            error = format!("{failure:#}"),
                            "could not read the candidate"
                        );
                    }
                }
            }
        }
    }

    /// A failed step may have followed a commit that filtered the candidate. Finishing it then
    /// would put a settled candidate back in the queue.
    async fn settled_without_release(&self, claim: &Claim) -> Result<bool> {
        let found: Option<i32> = sqlx::query_scalar(
            "SELECT 1 FROM release_verification_candidates WHERE id = ? AND version = ? AND manifest_hash = ? \
             AND status IN ('filtered', 'metadata_review', 'expired') AND release_id IS NULL",
        )
        .bind(claim.id())
        .bind(claim.candidate.version)
        .bind(&claim.candidate.manifest_hash)
        .fetch_optional(&self.verify.db)
        .await?;
        Ok(found.is_some())
    }

    async fn verify_and_publish(
        &self,
        source: &dyn ArticleSource,
        claim: &Claim,
    ) -> Result<(VerificationResult, i64)> {
        let verify = &self.verify;
        let mut conn = verify.db.acquire().await?;
        candidates::heartbeat(
            &mut conn,
            verify.config.lease_seconds,
            claim.id(),
            &claim.token,
        )
        .await?;
        drop(conn);
        let renewal = LeaseRenewal::start(verify.clone(), claim);
        let outcome = self.verify_renewed(source, claim, &renewal).await;
        renewal.stop();
        outcome
    }

    async fn verify_renewed(
        &self,
        source: &dyn ArticleSource,
        claim: &Claim,
        renewal: &LeaseRenewal,
    ) -> Result<(VerificationResult, i64)> {
        let verify = &self.verify;
        let manifest =
            manifest::read(&verify.db, &verify.config, &claim.candidate.manifest_path).await?;
        let options = self.spot_check_options();
        let allow_partial = candidates::partial_allowed(verify, &claim.candidate);
        let mut lost = renewal.lost.clone();
        let result = tokio::select! {
            result = spot_check::verify(source, &options, &manifest, allow_partial) => result,
            _ = lost.wait_for(|lost| *lost) => bail!("Verification lease was lost."),
        };
        if !result.passed() {
            let attempt = FinishedAttempt {
                reason: &result.reason,
                waiting_for_headers: result.waiting_for_headers,
                header_rejected: result.header_rejected,
                deterministic: result.deterministic,
                ..FinishedAttempt::default()
            };
            candidates::finish(verify, claim, &attempt).await?;
            return Ok((result, 0));
        }
        let bytes = result
            .manifest
            .as_ref()
            .and_then(|manifest| manifest.spot_check.as_ref())
            .map_or(0, |sample| sample.bytes);
        let resolver = Resolver {
            db: &verify.db,
            cleaner: &self.cleaner,
            predb: &self.predb,
            categorizer: &self.categorizer,
        };
        let outcome = publish(verify, &resolver, claim, &result).await?;
        info!(candidate = claim.id(), outcome = ?outcome, "publication");
        if matches!(outcome, Outcome::NotPassed) {
            bail!("Publication did not produce a verified current release.");
        }
        Ok((result, bytes))
    }

    /// What the monitoring collector reads. Failing to write it never fails a candidate.
    fn write_status(&self, bytes: i64, started: Instant) {
        let root = &self.verify.config.storage_path;
        let rate = bytes as f64 / started.elapsed().as_secs_f64().max(0.001);
        let snapshot = serde_json::json!({
            "verified_bytes_per_second": rate,
            "scratch_bytes": null,
            "scratch_bytes_status": "unknown",
        });
        let temporary = root.join(format!("status-{:016x}.tmp", rand::random::<u64>()));
        let written = std::fs::create_dir_all(root)
            .and_then(|()| std::fs::write(&temporary, snapshot.to_string()))
            .and_then(|()| std::fs::rename(&temporary, root.join("status.json")));
        if written.is_err() {
            let _ = std::fs::remove_file(&temporary);
        }
    }

    /// Recovers interrupted terminal cleanup without delaying claims indefinitely. Entries an audit
    /// must still delete a release for are left to it.
    async fn clean_terminal_scratch(
        &self,
        locks: &mut WorkerLocks,
        deadline: Instant,
    ) -> Result<()> {
        let verify = &self.verify;
        let scratch = verify.config.storage_path.join("scratch");
        if std::fs::symlink_metadata(&scratch).is_ok_and(|meta| meta.file_type().is_symlink()) {
            bail!("Verification scratch root is inaccessible or linked; inventory retained.");
        }
        let host = verify.config.host.as_str();
        let mut after = self.scratch_after.load(Ordering::Relaxed);
        let mut ceiling = self.scratch_ceiling.load(Ordering::Relaxed);
        if ceiling == 0 {
            ceiling = sqlx::query_scalar::<_, Option<i64>>(
                "SELECT CAST(MAX(id) AS SIGNED) FROM release_verification_scratch WHERE host = ?",
            )
            .bind(host)
            .fetch_one(&verify.db)
            .await?
            .unwrap_or(0);
        }
        let entries: Vec<ScratchEntry> = sqlx::query_as(
            "SELECT CAST(id AS SIGNED) AS id, CAST(candidate_id AS SIGNED) AS candidate_id, \
             CAST(audit_release_id AS SIGNED) AS audit_release_id, terminal, CAST(version AS SIGNED) AS version, delete_guid \
             FROM release_verification_scratch WHERE host = ? AND id > ? AND id <= ? ORDER BY id LIMIT 100",
        )
        .bind(host)
        .bind(after)
        .bind(ceiling)
        .fetch_all(&verify.db)
        .await?;
        if entries.is_empty() && ceiling == 0 {
            return Ok(());
        }
        let count = entries.len();
        let mut exhausted = false;
        for entry in entries {
            if Instant::now() >= deadline {
                exhausted = true;
                break;
            }
            after = entry.id;
            if let Some(candidate) = entry.candidate_id
                && !locks.try_candidate(candidate).await?
            {
                continue;
            }
            let cleaned = self.clean_entry(&scratch, &entry, deadline).await;
            if entry.candidate_id.is_some() {
                locks.release_candidate(entry.candidate_id).await;
            }
            if let Err(failure) = cleaned {
                warn!(
                    entry = entry.id,
                    error = format!("{failure:#}"),
                    "terminal scratch cleanup will retry"
                );
            }
        }
        if (!exhausted && count < 100) || after >= ceiling {
            after = 0;
            ceiling = 0;
        }
        self.scratch_after.store(after, Ordering::Relaxed);
        self.scratch_ceiling.store(ceiling, Ordering::Relaxed);
        Ok(())
    }

    async fn clean_entry(
        &self,
        scratch: &Path,
        entry: &ScratchEntry,
        deadline: Instant,
    ) -> Result<()> {
        if entry.delete_guid.is_some() {
            return Ok(());
        }
        let directory = scratch.join(entry.directory_name());
        match std::fs::symlink_metadata(&directory) {
            Err(failure) if failure.kind() == std::io::ErrorKind::NotFound => {
                return self.forget_scratch(entry.id).await;
            }
            Err(failure) => return Err(failure.into()),
            // A link or file isn't ours to remove here.
            Ok(meta) if !meta.is_dir() => return Ok(()),
            Ok(_) => {}
        }
        if !self.scratch_terminal(entry).await? {
            return Ok(());
        }
        if remove_tree(&directory, deadline)? {
            return self.forget_scratch(entry.id).await;
        }
        Ok(())
    }

    /// A reset marks the records it keeps for other hosts terminal.
    async fn scratch_terminal(&self, entry: &ScratchEntry) -> Result<bool> {
        if entry.terminal || entry.audit_release_id.is_some() {
            return Ok(entry.terminal);
        }
        let found: Option<i32> = sqlx::query_scalar(
            "SELECT 1 FROM release_verification_candidates WHERE id = ? AND version >= ? \
             AND (status IN ('expired', 'filtered', 'metadata_review', 'metadata_deferred', 'metadata_expired') \
             OR (release_id IS NOT NULL AND status IN ('complete', 'repaired', 'sampled'))) \
             AND (lease_until IS NULL OR lease_until < ?)",
        )
        .bind(entry.candidate_id)
        .bind(entry.version)
        .bind(candidates::now())
        .fetch_optional(&self.verify.db)
        .await?;
        Ok(found.is_some())
    }

    /// Called only once the exact directory is proved absent.
    async fn forget_scratch(&self, id: i64) -> Result<()> {
        in_tx!(self.verify.db, 5, |tx| {
            let entry: Option<(String, i64)> = sqlx::query_as(
                "SELECT host, CAST(reserved_bytes AS SIGNED) FROM release_verification_scratch WHERE id = ? FOR UPDATE",
            )
            .bind(id)
            .fetch_optional(&mut *tx)
            .await?;
            let Some((host, reserved)) = entry else {
                return Ok::<(), anyhow::Error>(());
            };
            if reserved != 0 {
                sqlx::query(
                    "UPDATE release_verification_scratch_budget SET reserved_bytes = \
                     CASE WHEN CAST(reserved_bytes AS SIGNED) - ? > 0 THEN reserved_bytes - ? ELSE 0 END WHERE host = ?",
                )
                .bind(reserved)
                .bind(reserved)
                .bind(host)
                .execute(&mut *tx)
                .await?;
            }
            sqlx::query("DELETE FROM release_verification_scratch WHERE id = ?")
                .bind(id)
                .execute(&mut *tx)
                .await?;
            Ok(())
        })
    }
}

#[derive(sqlx::FromRow)]
struct ScratchEntry {
    id: i64,
    candidate_id: Option<i64>,
    audit_release_id: Option<i64>,
    terminal: bool,
    version: i64,
    delete_guid: Option<String>,
}

impl ScratchEntry {
    fn directory_name(&self) -> String {
        match self.audit_release_id {
            None => format!("{}-{}", self.candidate_id.unwrap_or(0), self.version),
            Some(release) => format!("audit-{release}-{}", self.id),
        }
    }
}

/// Removes a directory without following links. `false` when the deadline stopped it, to resume on
/// a later turn.
fn remove_tree(directory: &Path, deadline: Instant) -> Result<bool> {
    let mut pending = vec![directory.to_owned()];
    let mut dirs = Vec::new();
    while let Some(path) = pending.pop() {
        for entry in std::fs::read_dir(&path)? {
            if Instant::now() >= deadline {
                return Ok(false);
            }
            let entry = entry?;
            if entry.file_type()?.is_dir() {
                pending.push(entry.path());
            } else {
                std::fs::remove_file(entry.path())?;
            }
        }
        dirs.push(path);
    }
    for path in dirs.iter().rev() {
        std::fs::remove_dir(path)?;
    }
    Ok(true)
}

/// Renews a claim's lease beside its verification. Publication keeps it renewing too: an update
/// waits behind the publishing transaction's row lock, and a real loss comes back as a stale publish.
struct LeaseRenewal {
    task: JoinHandle<()>,
    lost: watch::Receiver<bool>,
}

impl LeaseRenewal {
    fn start(verify: Verify, claim: &Claim) -> Self {
        let (signal, lost) = watch::channel(false);
        let (id, token) = (claim.id(), claim.token.clone());
        let every =
            Duration::from_secs_f64((verify.config.lease_seconds as f64 / 3.0).clamp(0.1, 30.0));
        let task = tokio::spawn(async move {
            loop {
                tokio::time::sleep(every).await;
                let renewed = async {
                    let mut conn = verify.db.acquire().await?;
                    candidates::heartbeat(&mut conn, verify.config.lease_seconds, id, &token).await
                }
                .await;
                if renewed.is_err() {
                    let _ = signal.send(true);
                    return;
                }
            }
        });
        Self { task, lost }
    }

    fn stop(self) {
        self.task.abort();
    }
}

fn flag(key: &str, default: &str) -> bool {
    matches!(
        var_or(key, default).to_ascii_lowercase().as_str(),
        "true" | "1" | "(true)"
    )
}

/// Settings for the role, read once at start.
#[derive(Clone, Copy, Debug)]
pub struct RoleSettings {
    pub scheduled: bool,
    pub worker_only: bool,
    pub worker_limit: i64,
    pub worker_seconds: u64,
}

impl RoleSettings {
    pub fn from_env() -> Result<Self> {
        Ok(Self {
            scheduled: flag("RELEASE_VERIFICATION_SCHEDULED", "true"),
            worker_only: flag("RELEASE_VERIFICATION_WORKER_ONLY", "false"),
            worker_limit: parsed_or("RELEASE_VERIFICATION_WORKER_LIMIT", 1000)?,
            worker_seconds: parsed_or("RELEASE_VERIFICATION_WORKER_SECONDS", 300)?,
        })
    }
}

/// The `verify` role: one loop per configured slot, and candidate preparation on the primary host.
pub async fn run_role(ctx: Ctx, args: RoleArgs, stop: Shutdown, verify: Verify) -> Result<()> {
    let settings = RoleSettings::from_env()?;
    if !settings.scheduled && !args.once {
        info!("verification is off: RELEASE_VERIFICATION_SCHEDULED is false");
        stop.token().cancelled().await;
        return Ok(());
    }
    if std::env::var("RELEASE_VERIFICATION_PUBLISH_URL").is_ok_and(|url| !url.is_empty()) {
        bail!(
            "Publishing through a primary host isn't ported yet; unset RELEASE_VERIFICATION_PUBLISH_URL."
        );
    }
    let worker = Arc::new(Worker::new(&ctx, verify.clone()).await?);
    let first = WorkerLocks::new(&verify.db, &verify.config)
        .await?
        .first_slot();
    let last = WorkerLocks::new(&verify.db, &verify.config)
        .await?
        .last_slot();
    let mut tasks = JoinSet::new();
    for slot in first..=last {
        let (worker, ctx, stop) = (worker.clone(), ctx.clone(), stop.clone());
        let idle = Duration::from_secs(args.idle_seconds);
        let once = args.once;
        tasks.spawn(async move {
            let source = NntpSource::new(worker.nntp.clone(), &worker.verify);
            let options = RunOptions {
                limit: settings.worker_limit.max(1),
                slot,
                max_seconds: settings.worker_seconds.max(1),
            };
            while !stop.requested() {
                let busy = match busy::enter(&ctx, "verify").await {
                    Ok(Some(busy)) => busy,
                    Ok(None) => {
                        if once {
                            return;
                        }
                        stop.sleep(Duration::from_secs(5)).await;
                        continue;
                    }
                    Err(failure) => {
                        error!(slot, error = format!("{failure:#}"), "can't mark this worker busy");
                        if once {
                            return;
                        }
                        stop.sleep(idle).await;
                        continue;
                    }
                };
                let run = worker.run(&source, options, &stop).await;
                drop(busy);
                if let Err(failure) = &run {
                    error!(
                        slot,
                        error = format!("{failure:#}"),
                        "verification run failed"
                    );
                }
                if once {
                    return;
                }
                if !matches!(run, Ok(RunReport { processed: 1.., .. })) {
                    stop.sleep(idle).await;
                }
            }
        });
    }
    if !settings.worker_only {
        let (verify, ctx, stop) = (verify.clone(), ctx.clone(), stop.clone());
        let once = args.once;
        tasks.spawn(async move {
            while !stop.requested() {
                if !ctx.paused().await.unwrap_or(true)
                    && let Err(failure) = prepare_pass(&verify).await
                {
                    error!(
                        error = format!("{failure:#}"),
                        "candidate preparation failed"
                    );
                }
                if once {
                    return;
                }
                stop.sleep(PREPARE_EVERY).await;
            }
        });
    }
    while tasks.join_next().await.is_some() {}
    Ok(())
}

async fn prepare_pass(verify: &Verify) -> Result<()> {
    let mut locks = WorkerLocks::new(&verify.db, &verify.config).await?;
    let options = PrepareOptions {
        limit: 5000,
        max_seconds: 30,
        dirty_limit: 200,
        dirty_seconds: 20,
    };
    let outcome = prepare::run_pass(verify, &mut locks, options).await;
    locks.close().await;
    outcome.map(|_| ())
}

/// Counts per status, for the command's closing summary.
pub async fn status_counts(verify: &Verify) -> Result<HashMap<String, i64>> {
    let rows: Vec<(String, i64)> = sqlx::query_as(
        "SELECT status, COUNT(*) FROM release_verification_candidates GROUP BY status",
    )
    .fetch_all(&verify.db)
    .await?;
    Ok(rows.into_iter().collect())
}
