//! Provider connections that survive from one release to the next.
//!
//! One `release_fingerprint_connections` row is one live socket, shared by every worker host.
//! A connection therefore owns its row for as long as it exists: the row is leased with a
//! per-connection token (not the job's), renewed by the owning task's heartbeat, and released
//! only after the socket is closed. A connection is retained only when it is healthy, profiled
//! and unpoisoned, and is dropped when it goes stale, ages out, loses its lease, or the worker
//! drains. Only the primary account is retained; see [`RETAINED_ACCOUNT`].
//!
//! Holding rows between releases must not starve anyone who is waiting for one. Inside this
//! process, a task that cannot lease a row raises [`Contention`] and every holder gives its row
//! back at the end of its job or idle check. Across hosts a waiter is invisible, so retention is
//! bounded by [`MAX_AGE`]: each connection is retired and offered to waiting clients within it.
use crate::{
    database::{self, LEASE_SECONDS},
    nntp::{Client, Provider},
};
use anyhow::{Result, bail};
use sqlx::{MySqlPool, Row};
use std::{
    collections::HashMap,
    sync::{
        Arc,
        atomic::{AtomicUsize, Ordering},
    },
    time::{Duration, Instant, SystemTime},
};
use tokio::sync::Mutex;

/// The account whose connections are kept between releases.
///
/// The alternate provider serves a small share of releases from a small shared budget, so a
/// retained alternate socket would rarely be reused before it expired while still occupying
/// scarce budget. Alternate sockets therefore stay per release, which also keeps its shared
/// cooldown and single-prober recovery protocol exactly as it was.
pub const RETAINED_ACCOUNT: &str = "primary";
/// A retained connection is replaced after this long so a saturated host cannot starve peers
/// of the shared budget: the freed row is offered to waiting clients before it is re-leased.
/// Waiting clients give up after 90 seconds, so retirement must come well inside that.
pub const MAX_AGE: Duration = Duration::from_secs(60);
/// Idle connections are probed with `DATE` before reuse once idle for this long.
const PING_AFTER: Duration = Duration::from_secs(10);
const SLOT_WAIT: Duration = Duration::from_secs(90);
const DB_TIMEOUT: Duration = Duration::from_secs(10);
const RETIRE_YIELD: Duration = Duration::from_millis(300);

/// Tasks of this process that are waiting for a budget row right now.
#[derive(Default)]
pub struct Contention {
    waiting: AtomicUsize,
}

impl Contention {
    pub fn waiting(&self) -> usize {
        self.waiting.load(Ordering::SeqCst)
    }
}

struct Waiting<'a>(&'a Contention);

impl<'a> Waiting<'a> {
    fn new(contention: &'a Contention) -> Self {
        contention.waiting.fetch_add(1, Ordering::SeqCst);
        Self(contention)
    }
}

impl Drop for Waiting<'_> {
    fn drop(&mut self) {
        self.0.waiting.fetch_sub(1, Ordering::SeqCst);
    }
}

struct Record {
    token: String,
    /// False between registering the token and the row being committed to this token.
    confirmed: bool,
}

/// Budget-row leases held by one worker task, shared with its heartbeat.
///
/// The database mutation and the bookkeeping happen under one lock, so a heartbeat never
/// counts a lease that is half acquired or half released.
#[derive(Default)]
pub struct Leases {
    records: Mutex<Vec<Record>>,
    contention: Arc<Contention>,
}

fn placeholders(count: usize) -> String {
    vec!["?"; count].join(",")
}

impl Leases {
    /// Lease one free row of `provider`, waiting up to 90 seconds. Returns the lease token.
    pub async fn acquire(&self, pool: &MySqlPool, provider: &str, worker: &str) -> Result<String> {
        let token = database::token();
        let started = Instant::now();
        let mut registered = false;
        let mut waiting = None;
        loop {
            {
                let mut records = self.records.lock().await;
                if !registered {
                    // Registered before the transaction so a cancelled acquisition is still
                    // released by token.
                    records.push(Record {
                        token: token.clone(),
                        confirmed: false,
                    });
                    registered = true;
                }
                let mut tx = pool.begin().await?;
                let row = sqlx::query("SELECT slot FROM release_fingerprint_connections WHERE provider=? AND (lease_token IS NULL OR lease_until<UTC_TIMESTAMP(6)) ORDER BY slot LIMIT 1 FOR UPDATE SKIP LOCKED").bind(provider).fetch_optional(&mut *tx).await?;
                if let Some(row) = row {
                    let slot: u32 = row.try_get("slot")?;
                    sqlx::query("UPDATE release_fingerprint_connections SET lease_token=?,lease_until=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)),worker_id=? WHERE provider=? AND slot=?").bind(&token).bind(LEASE_SECONDS).bind(worker).bind(provider).bind(slot).execute(&mut *tx).await?;
                    tx.commit().await?;
                    if let Some(record) = records.iter_mut().find(|r| r.token == token) {
                        record.confirmed = true;
                    }
                    return Ok(token);
                }
                tx.rollback().await?;
            }
            // Visible to this process's holders, which then hand their rows back.
            waiting.get_or_insert_with(|| Waiting::new(&self.contention));
            if started.elapsed() > SLOT_WAIT {
                self.release_tokens(pool, &[token]).await.ok();
                bail!("ProviderConnectionBudgetExhausted");
            }
            tokio::time::sleep(Duration::from_millis(250)).await;
        }
    }

    /// Extend every confirmed lease; false when any of them was lost, which fences the job.
    pub async fn renew(&self, pool: &MySqlPool) -> Result<bool> {
        let records = self.records.lock().await;
        let tokens: Vec<&str> = records
            .iter()
            .filter(|r| r.confirmed)
            .map(|r| r.token.as_str())
            .collect();
        if tokens.is_empty() {
            return Ok(true);
        }
        let sql = format!(
            "UPDATE release_fingerprint_connections SET lease_until=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)) WHERE lease_token IN ({}) AND lease_until>UTC_TIMESTAMP(6)",
            placeholders(tokens.len())
        );
        let mut query = sqlx::query(&sql).bind(LEASE_SECONDS);
        for token in &tokens {
            query = query.bind(*token);
        }
        Ok(query.execute(pool).await?.rows_affected() == tokens.len() as u64)
    }

    /// Extend one lease before its connection is reused; false when it is no longer ours.
    async fn renew_one(&self, pool: &MySqlPool, token: &str) -> Result<bool> {
        let _records = self.records.lock().await;
        let renewed = sqlx::query("UPDATE release_fingerprint_connections SET lease_until=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)) WHERE lease_token=? AND lease_until>UTC_TIMESTAMP(6)").bind(LEASE_SECONDS).bind(token).execute(pool).await?.rows_affected();
        Ok(renewed == 1)
    }

    /// Release every lease whose token is not in `keep`.
    async fn release_except(&self, pool: &MySqlPool, keep: &[&str]) -> Result<()> {
        let mut records = self.records.lock().await;
        let doomed: Vec<String> = records
            .iter()
            .filter(|r| !keep.contains(&r.token.as_str()))
            .map(|r| r.token.clone())
            .collect();
        Self::release_locked(pool, &mut records, doomed).await
    }

    /// Release the leases with exactly these tokens.
    async fn release_tokens(&self, pool: &MySqlPool, tokens: &[String]) -> Result<()> {
        let mut records = self.records.lock().await;
        Self::release_locked(pool, &mut records, tokens.to_vec()).await
    }

    async fn release_locked(
        pool: &MySqlPool,
        records: &mut Vec<Record>,
        doomed: Vec<String>,
    ) -> Result<()> {
        if doomed.is_empty() {
            return Ok(());
        }
        let sql = format!(
            "UPDATE release_fingerprint_connections SET lease_token=NULL,lease_until=NULL,worker_id=NULL WHERE lease_token IN ({})",
            placeholders(doomed.len())
        );
        let mut query = sqlx::query(&sql);
        for token in &doomed {
            query = query.bind(token);
        }
        query.execute(pool).await?;
        records.retain(|r| !doomed.contains(&r.token));
        Ok(())
    }

    /// Number of budget rows this task currently holds or is acquiring.
    pub async fn held(&self) -> usize {
        self.records.lock().await.len()
    }
}

struct Retained {
    client: Client,
    token: String,
    opened: Instant,
    since: Instant,
    since_wall: SystemTime,
}

impl Retained {
    fn idle(&self) -> Duration {
        // Instant does not advance while a laptop sleeps; the wall clock does.
        self.since
            .elapsed()
            .max(self.since_wall.elapsed().unwrap_or_default())
    }
}

/// A provider connection checked out for one release. `client` is `Some` when a retained,
/// already-profiled connection was reused and `None` when only a fresh budget row was leased.
pub struct Checkout {
    pub token: String,
    pub client: Option<Client>,
    pub reused: bool,
    opened: Instant,
}

/// The retained connections of one worker task.
pub struct Connections {
    leases: Arc<Leases>,
    worker: String,
    idle_limit: Duration,
    retained: HashMap<String, Retained>,
}

impl Connections {
    /// `idle_limit` is how long a connection may wait for its next release; zero disables reuse.
    pub fn new(worker: String, idle_limit: Duration, contention: Arc<Contention>) -> Self {
        Self {
            leases: Arc::new(Leases {
                records: Mutex::default(),
                contention,
            }),
            worker,
            idle_limit,
            retained: HashMap::new(),
        }
    }

    /// Handle for the heartbeat, which renews every lease this task holds.
    pub fn leases(&self) -> Arc<Leases> {
        self.leases.clone()
    }

    /// Connections currently waiting for a release.
    pub fn idle_connections(&self) -> usize {
        self.retained.len()
    }

    /// Reuse the retained connection for `provider` if it is still sound, otherwise lease a
    /// fresh budget row (waiting for one if the shared budget is exhausted).
    pub async fn checkout(&mut self, pool: &MySqlPool, provider: &Provider) -> Result<Checkout> {
        if let Some(kept) = self.retained.remove(&provider.account) {
            let aged = kept.opened.elapsed() >= MAX_AGE;
            let idle = kept.idle();
            let Retained {
                mut client,
                token,
                opened,
                ..
            } = kept;
            let usable = !aged
                && idle < self.idle_limit
                && client.ready_for_reuse(idle >= PING_AFTER).await
                && matches!(
                    tokio::time::timeout(DB_TIMEOUT, self.leases.renew_one(pool, &token)).await,
                    Ok(Ok(true))
                );
            if usable {
                return Ok(Checkout {
                    token,
                    client: Some(client),
                    reused: true,
                    opened,
                });
            }
            // Close the socket first, then give its row back.
            drop(client);
            let _ =
                tokio::time::timeout(DB_TIMEOUT, self.leases.release_tokens(pool, &[token])).await;
        }
        let token = self
            .leases
            .acquire(pool, &provider.account, &self.worker)
            .await?;
        Ok(Checkout {
            token,
            client: None,
            reused: false,
            opened: Instant::now(),
        })
    }

    /// End a checkout: keep a healthy primary connection for the next release, otherwise close
    /// the socket and release its budget row.
    pub async fn finish(
        &mut self,
        pool: &MySqlPool,
        provider: &Provider,
        checkout: Checkout,
        client: Option<Client>,
    ) -> Result<()> {
        if let Some(client) = client
            && self.idle_limit > Duration::ZERO
            && provider.account == RETAINED_ACCOUNT
            && !client.poisoned()
            && client.profiled()
            && checkout.opened.elapsed() < MAX_AGE
            && self.leases.contention.waiting() == 0
        {
            self.retained.insert(
                provider.account.clone(),
                Retained {
                    client,
                    token: checkout.token,
                    opened: checkout.opened,
                    since: Instant::now(),
                    since_wall: SystemTime::now(),
                },
            );
            return Ok(());
        }
        // The client (if any) was dropped above or moved out before this point: socket first.
        let aged = checkout.opened.elapsed() >= MAX_AGE;
        let released = self.leases.release_tokens(pool, &[checkout.token]).await;
        if aged {
            // Let a waiting client take the row before this task asks for one again.
            tokio::time::sleep(RETIRE_YIELD).await;
        }
        released
    }

    /// After a job, release every row not backing a retained connection: a checkout that was
    /// cancelled, timed out or failed never reaches `finish`.
    pub async fn settle(&mut self, pool: &MySqlPool) -> Result<()> {
        let keep: Vec<&str> = self.retained.values().map(|r| r.token.as_str()).collect();
        self.leases.release_except(pool, &keep).await
    }

    /// Close retained connections that waited too long or aged out.
    pub async fn expire_idle(&mut self, pool: &MySqlPool) -> Result<()> {
        let expired: Vec<String> = self
            .retained
            .iter()
            .filter(|(_, r)| {
                r.idle() >= self.idle_limit
                    || r.opened.elapsed() >= MAX_AGE
                    || self.leases.contention.waiting() > 0
            })
            .map(|(account, _)| account.clone())
            .collect();
        let mut tokens = vec![];
        for account in expired {
            if let Some(kept) = self.retained.remove(&account) {
                tokens.push(kept.token);
                drop(kept.client);
            }
        }
        if tokens.is_empty() {
            return Ok(());
        }
        self.leases.release_tokens(pool, &tokens).await
    }

    /// Drain: close every socket, then release every row this task holds.
    pub async fn close(&mut self, pool: &MySqlPool) -> Result<()> {
        self.retained.clear();
        self.leases.release_except(pool, &[]).await
    }
}
