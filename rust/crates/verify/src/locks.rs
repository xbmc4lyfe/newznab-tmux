//! MariaDB user-level locks shared by every worker host on the database. Like `flock` they belong
//! to a session and end with it, so a crashed or disconnected worker releases everything it held.
//!
//! Every acquisition fails at once rather than waiting, so holders never wait on each other. A
//! group is a reader/writer lock: shared holders exclude an exclusive holder, and an exclusive
//! holder excludes everyone else. A session is one dedicated connection, so losing it can't leave
//! protected work running on a new session: its statements fail instead.

use std::collections::{HashMap, HashSet};
use std::time::{Duration, Instant};

use anyhow::{Context, Result, anyhow, bail};
use rand::Rng;
use sha1::{Digest, Sha1};
use sqlx::pool::PoolConnection;
use sqlx::{MySql, MySqlConnection, MySqlPool};

use crate::config::VerificationConfig;

/// Ceiling on scheduled candidate worker slots per host, whatever the configured count.
pub const MAX_SLOTS: i64 = 64;
/// Highest slot number across every worker host; each host schedules its own range.
pub const MAX_SLOT_NUMBER: i64 = 256;

const LOST_SESSION: &str = "Verification worker locks were lost.";

/// Shared-holder stripes per group. An exclusive holder proves every stripe free.
fn members(group: &str) -> Result<u32> {
    match group {
        "worker" => Ok(512),
        "reset" => Ok(64),
        _ => bail!("Unknown verification lock group."),
    }
}

/// Names are scoped to the database, so other databases on the server never collide.
fn key(database: &str, name: &str) -> Result<String> {
    let scope = hex::encode(Sha1::digest(format!("{database}/")));
    let key = format!("nnv:{}:{name}", &scope[..12]);
    if key.len() > 64 {
        bail!("Verification lock name is too long.");
    }
    Ok(key)
}

/// A held lock: the identities a release must give up.
#[derive(Debug, Clone)]
pub struct Held {
    keys: Vec<String>,
}

/// What a holder's connection does. Implemented twice, so an admission can reuse the connection its
/// transaction runs on while a long-running worker keeps its own.
async fn take(conn: &mut MySqlConnection, key: &str) -> Result<bool> {
    let got: Option<i64> = sqlx::query_scalar("SELECT GET_LOCK(?, 0)")
        .bind(key)
        .fetch_one(&mut *conn)
        .await?;
    Ok(got == Some(1))
}

async fn drop_lock(conn: &mut MySqlConnection, key: &str) -> Result<()> {
    sqlx::query("SELECT RELEASE_LOCK(?)")
        .bind(key)
        .execute(&mut *conn)
        .await?;
    Ok(())
}

async fn is_free(conn: &mut MySqlConnection, key: &str) -> Result<bool> {
    let free: Option<i64> = sqlx::query_scalar("SELECT IS_FREE_LOCK(?)")
        .bind(key)
        .fetch_one(&mut *conn)
        .await?;
    Ok(free == Some(1))
}

async fn database_name(conn: &mut MySqlConnection) -> Result<String> {
    let name: Option<String> = sqlx::query_scalar("SELECT DATABASE()")
        .fetch_one(&mut *conn)
        .await?;
    name.ok_or_else(|| anyhow!("The verification lock session has no database."))
}

/// Tries each stripe of a group from a random start, then checks the gate: an exclusive holder
/// takes the gate before proving every stripe free, so it either sees this stripe or this check
/// sees its gate.
async fn take_shared(
    conn: &mut MySqlConnection,
    database: &str,
    group: &str,
) -> Result<Option<String>> {
    let count = members(group)?;
    let start = rand::rng().random_range(0..count);
    let gate = key(database, group)?;
    for offset in 0..count {
        let member = key(database, &format!("{group}.{}", (start + offset) % count))?;
        if !take(conn, &member).await? {
            continue;
        }
        return match is_free(conn, &gate).await {
            Ok(true) => Ok(Some(member)),
            Ok(false) => {
                drop_lock(conn, &member).await?;
                Ok(None)
            }
            Err(error) => {
                let _ = drop_lock(conn, &member).await;
                Err(error)
            }
        };
    }
    Ok(None)
}

/// One dedicated database session holding verification locks.
pub struct LockSession {
    conn: MySqlConnection,
    database: String,
    held: HashSet<String>,
}

impl LockSession {
    pub async fn open(pool: &MySqlPool) -> Result<Self> {
        let mut conn = pool
            .acquire()
            .await
            .context("opening a verification lock session")?
            .detach();
        let database = database_name(&mut conn).await?;
        Ok(Self {
            conn,
            database,
            held: HashSet::new(),
        })
    }

    /// The session's connection, for work that must share it.
    pub fn conn(&mut self) -> &mut MySqlConnection {
        &mut self.conn
    }

    fn key(&self, name: &str) -> Result<String> {
        key(&self.database, name)
    }

    // GET_LOCK is re-entrant within a session; refuse a second holder in this process, as a second
    // file descriptor's flock would be refused.
    async fn take(&mut self, key: &str) -> Result<bool> {
        if self.held.contains(key) {
            return Ok(false);
        }
        if !take(&mut self.conn, key).await? {
            return Ok(false);
        }
        self.held.insert(key.to_owned());
        Ok(true)
    }

    async fn drop_key(&mut self, key: &str) {
        if self.held.remove(key) {
            // A lost session has already released every lock it held.
            let _ = drop_lock(&mut self.conn, key).await;
        }
    }

    /// One holder of a named mutex across every process.
    pub async fn exclusive(&mut self, name: &str) -> Result<Option<Held>> {
        let key = self.key(name)?;
        Ok(self.take(&key).await?.then_some(Held { keys: vec![key] }))
    }

    /// A shared holder of a group, refused while the group is held exclusively.
    pub async fn shared(&mut self, group: &str) -> Result<Option<Held>> {
        let database = self.database.clone();
        let Some(member) = take_shared(&mut self.conn, &database, group).await? else {
            return Ok(None);
        };
        self.held.insert(member.clone());
        Ok(Some(Held { keys: vec![member] }))
    }

    /// The sole holder of a group, refused while any shared holder remains.
    pub async fn exclusive_group(&mut self, group: &str) -> Result<Option<Held>> {
        let gate = self.key(group)?;
        if !self.take(&gate).await? {
            return Ok(None);
        }
        match self.members_free(group).await {
            Ok(true) => Ok(Some(Held { keys: vec![gate] })),
            Ok(false) => {
                self.drop_key(&gate).await;
                Ok(None)
            }
            Err(error) => {
                self.drop_key(&gate).await;
                Err(error)
            }
        }
    }

    /// Holds the group's gate so no new shared holder joins, then waits for the current ones to
    /// leave. Workers on every host stop claiming once they see the gate held.
    pub async fn drain_group(&mut self, group: &str, seconds: f64) -> Result<Option<Held>> {
        let deadline = Instant::now() + Duration::from_secs_f64(seconds.max(0.0));
        let gate = self.key(group)?;
        while !self.take(&gate).await? {
            if Instant::now() >= deadline {
                return Ok(None);
            }
            tokio::time::sleep(Duration::from_millis(200)).await;
        }
        loop {
            match self.members_free(group).await {
                Ok(true) => return Ok(Some(Held { keys: vec![gate] })),
                Ok(false) if Instant::now() >= deadline => {
                    self.drop_key(&gate).await;
                    return Ok(None);
                }
                Ok(false) => tokio::time::sleep(Duration::from_millis(200)).await,
                Err(error) => {
                    self.drop_key(&gate).await;
                    return Err(error);
                }
            }
        }
    }

    /// Whether new shared holders may join the group.
    pub async fn admitting(&mut self, group: &str) -> Result<bool> {
        let gate = self.key(group)?;
        is_free(&mut self.conn, &gate).await
    }

    /// Whether new shared holders may join and no durable pause exists, in one round trip.
    pub async fn admitting_unpaused(&mut self, group: &str) -> Result<bool> {
        let gate = self.key(group)?;
        let admitting: Option<i64> = sqlx::query_scalar(
            "SELECT IS_FREE_LOCK(?) AND NOT EXISTS (SELECT 1 FROM release_verification_pauses)",
        )
        .bind(gate)
        .fetch_one(&mut self.conn)
        .await?;
        Ok(admitting == Some(1))
    }

    async fn members_free(&mut self, group: &str) -> Result<bool> {
        let count = members(group)?;
        let placeholders = vec!["IS_USED_LOCK(?)"; count as usize].join(", ");
        let sql = format!("SELECT COALESCE({placeholders})");
        let mut query = sqlx::query_scalar::<_, Option<i64>>(sqlx::AssertSqlSafe(sql));
        for number in 0..count {
            query = query.bind(self.key(&format!("{group}.{number}"))?);
        }
        Ok(query.fetch_one(&mut self.conn).await?.is_none())
    }

    /// False once the lock is no longer held, such as after this session ended.
    pub async fn holds(&mut self, lock: &Held) -> bool {
        self.holds_all(&[lock]).await
    }

    /// Whether every lock is still held, proved in one round trip.
    pub async fn holds_all(&mut self, locks: &[&Held]) -> bool {
        let keys: Vec<&String> = locks.iter().flat_map(|lock| &lock.keys).collect();
        if keys.iter().any(|key| !self.held.contains(*key)) {
            return false;
        }
        if keys.is_empty() {
            return true;
        }
        let checks = vec!["IS_USED_LOCK(?) <=> CONNECTION_ID()"; keys.len()].join(" AND ");
        let sql = format!("SELECT ({checks})");
        let mut query = sqlx::query_scalar::<_, Option<i64>>(sqlx::AssertSqlSafe(sql));
        for key in keys {
            query = query.bind(key);
        }
        matches!(query.fetch_one(&mut self.conn).await, Ok(Some(1)))
    }

    pub async fn release(&mut self, lock: Held) {
        for key in &lock.keys {
            self.drop_key(key).await;
        }
    }
}

/// Protects an admission from a concurrent reset, on the pooled connection its transaction uses.
/// Dropped with the lock still held (a cancelled or failed task), it closes the connection rather
/// than return a session holding a lock to the pool.
pub struct Admission {
    conn: Option<PoolConnection<MySql>>,
    member: String,
}

impl Admission {
    /// Fails when a reset is running, or left its durable pause behind while its cleanup may still run.
    pub async fn enter(pool: &MySqlPool) -> Result<Self> {
        const BUSY: &str = "Verification worker, admission or reset is active; retry reset later.";
        let mut conn = pool.acquire().await?;
        let database = database_name(&mut conn).await?;
        let Some(member) = take_shared(&mut conn, &database, "reset").await? else {
            bail!(BUSY);
        };
        let mut admission = Self {
            conn: Some(conn),
            member,
        };
        let paused: Option<i32> = sqlx::query_scalar(
            "SELECT 1 FROM release_verification_pauses WHERE reason = 'reset' LIMIT 1 LOCK IN SHARE MODE",
        )
        .fetch_optional(admission.conn())
        .await?;
        if paused.is_some() {
            admission.leave().await?;
            bail!(BUSY);
        }
        Ok(admission)
    }

    pub fn conn(&mut self) -> &mut MySqlConnection {
        self.conn
            .as_mut()
            .expect("an admission keeps its connection until it leaves")
    }

    /// Gives the lock back and returns the connection to the pool.
    pub async fn leave(mut self) -> Result<()> {
        let mut conn = self.conn.take().expect("an admission leaves once");
        if let Err(error) = drop_lock(&mut conn, &self.member).await {
            conn.close_on_drop();
            return Err(error);
        }
        Ok(())
    }
}

impl Drop for Admission {
    fn drop(&mut self) {
        if let Some(conn) = self.conn.as_mut() {
            conn.close_on_drop();
        }
    }
}

/// A durable pause keeps every host's workers out even after the session that took it ends.
pub async fn pause(pool: &MySqlPool, token: &str, reason: &str) -> Result<()> {
    sqlx::query("INSERT IGNORE INTO release_verification_pauses (token, reason) VALUES (?, ?)")
        .bind(token)
        .bind(reason)
        .execute(pool)
        .await?;
    Ok(())
}

/// Releases pauses: one token, every pause with the given reason (an earlier failed attempt's too),
/// or with neither, every pause (operator recovery).
pub async fn unpause(pool: &MySqlPool, token: Option<&str>, reason: Option<&str>) -> Result<()> {
    match (token, reason) {
        (Some(token), _) => {
            sqlx::query("DELETE FROM release_verification_pauses WHERE token = ?")
                .bind(token)
                .execute(pool)
                .await?;
        }
        (None, Some(reason)) => {
            sqlx::query("DELETE FROM release_verification_pauses WHERE reason = ?")
                .bind(reason)
                .execute(pool)
                .await?;
        }
        (None, None) => {
            sqlx::query("DELETE FROM release_verification_pauses")
                .execute(pool)
                .await?;
        }
    }
    Ok(())
}

pub async fn paused(pool: &MySqlPool, reason: Option<&str>) -> Result<bool> {
    let found: Option<i32> = match reason {
        Some(reason) => {
            sqlx::query_scalar("SELECT 1 FROM release_verification_pauses WHERE reason = ? LIMIT 1")
                .bind(reason)
                .fetch_optional(pool)
                .await?
        }
        None => {
            sqlx::query_scalar("SELECT 1 FROM release_verification_pauses LIMIT 1")
                .fetch_optional(pool)
                .await?
        }
    };
    Ok(found.is_some())
}

/// The stable locks a worker holds. They outlive database leases and manifest generations.
pub struct WorkerLocks {
    session: LockSession,
    pool: MySqlPool,
    host: String,
    slots: i64,
    slot_offset: i64,
    global: Option<Held>,
    slot: Option<Held>,
    candidates: HashMap<i64, Held>,
    gc: Option<Held>,
    expiry: Option<Held>,
    exclusive: bool,
    /// Queue and maintenance fairness turns. A worker lives for many runs, so memory is enough.
    queue_phase: u8,
    expiry_first: bool,
}

impl WorkerLocks {
    pub async fn new(pool: &MySqlPool, config: &VerificationConfig) -> Result<Self> {
        Ok(Self {
            session: LockSession::open(pool).await?,
            pool: pool.clone(),
            host: config.host.clone(),
            slots: config.candidate_workers.clamp(1, MAX_SLOTS),
            slot_offset: config.slot_offset,
            global: None,
            slot: None,
            candidates: HashMap::new(),
            gc: None,
            expiry: None,
            exclusive: false,
            queue_phase: 0,
            expiry_first: false,
        })
    }

    pub fn first_slot(&self) -> i64 {
        self.slot_offset.clamp(0, MAX_SLOT_NUMBER - self.slots) + 1
    }

    pub fn last_slot(&self) -> i64 {
        self.first_slot() + self.slots - 1
    }

    /// Joins the worker group and takes a slot: the one asked for, or the first free one.
    pub async fn start(&mut self, slot: i64) -> Result<bool> {
        self.close().await;
        self.global = self.session.shared("worker").await?;
        if self.global.is_none() || paused(&self.pool, None).await? {
            self.close().await;
            return Ok(false);
        }
        let (first, last) = (self.first_slot(), self.last_slot());
        let numbers: Vec<i64> = if slot > 0 {
            vec![slot]
        } else {
            (first..=last).collect()
        };
        for number in numbers {
            if number < first || number > last {
                break;
            }
            self.slot = self.session.exclusive(&format!("worker-{number}")).await?;
            if self.slot.is_some() {
                return Ok(true);
            }
        }
        self.close().await;
        Ok(false)
    }

    fn forwarder_lock_name(&self, number: i64) -> String {
        let host = hex::encode(Sha1::digest(&self.host));
        format!("publication-forwarder-{}-{number}", &host[..12])
    }

    /// A publication forwarder holds one exclusive lock per forwarder number and host for its whole
    /// run. It fences nothing but a second copy of itself. It joins the shared worker group only
    /// while it writes, so an idle forwarder never delays a barrier.
    pub async fn start_forwarder(&mut self, number: i64) -> Result<bool> {
        self.close().await;
        let name = self.forwarder_lock_name(number);
        self.slot = self.session.exclusive(&name).await?;
        Ok(self.slot.is_some())
    }

    /// A running forwarder becomes a writer like a worker: it joins the shared worker group, which
    /// a drain, an exclusive holder or a durable pause refuses. Join before writing, leave when done.
    pub async fn join_workers(&mut self) -> Result<bool> {
        if self.slot.is_none() {
            bail!("Joining the worker group requires a forwarder lock.");
        }
        if self.global.is_some() {
            return Ok(true);
        }
        self.global = self.session.shared("worker").await?;
        if self.global.is_some() && paused(&self.pool, None).await? {
            self.leave_workers().await;
        }
        Ok(self.global.is_some())
    }

    pub async fn leave_workers(&mut self) {
        if let Some(lock) = self.global.take() {
            self.session.release(lock).await;
        }
    }

    /// Whether the forwarder still holds its own lock; a database lock ends with its session.
    pub async fn forwarder_held(&mut self) -> bool {
        match self.slot.clone() {
            Some(slot) => self.session.holds(&slot).await,
            None => false,
        }
    }

    /// Whether forwarder `number` is free right now. Used to recover a dead forwarder's claims: no
    /// process of that number runs while this returns true and the caller holds the returned lock.
    pub async fn try_forwarder_number(&mut self, number: i64) -> Result<Option<Held>> {
        let name = self.forwarder_lock_name(number);
        self.session.exclusive(&name).await
    }

    pub async fn release_held(&mut self, lock: Held) {
        self.session.release(lock).await;
    }

    pub async fn start_preparation(&mut self) -> Result<bool> {
        self.close().await;
        self.global = self.session.shared("worker").await?;
        if self.global.is_some() && !paused(&self.pool, None).await? {
            self.slot = self.session.exclusive("preparation").await?;
            if self.slot.is_some() {
                return Ok(true);
            }
        }
        self.close().await;
        Ok(false)
    }

    /// Whether this turn prefers the primary queue: four turns of five. `advance` spends the turn.
    pub fn prefer_primary_queue(&mut self, advance: bool) -> bool {
        let next = (self.queue_phase + 1) % 5;
        if advance {
            self.queue_phase = next;
        }
        next != 0
    }

    /// Audit and bootstrap exclusion. A durable deployment pause refuses it as it refuses workers.
    pub async fn try_exclusive(&mut self) -> Result<bool> {
        self.close().await;
        self.global = self.session.exclusive_group("worker").await?;
        // Checked while holding the gate: a pause written before it is seen, and a barrier can't
        // start draining until this holder releases it.
        if self.global.is_some() && paused(&self.pool, None).await? {
            self.close().await;
        }
        self.exclusive = self.global.is_some();
        Ok(self.exclusive)
    }

    /// Stops every host's workers from claiming and waits up to `seconds` for them to finish.
    pub async fn drain(&mut self, seconds: f64) -> Result<bool> {
        self.close().await;
        self.global = self.session.drain_group("worker", seconds).await?;
        self.exclusive = self.global.is_some();
        Ok(self.exclusive)
    }

    /// False while a drain holds the worker gate or a durable pause exists; a running worker stops claiming.
    pub async fn admitting(&mut self) -> Result<bool> {
        self.session.admitting_unpaused("worker").await
    }

    pub async fn try_candidate(&mut self, id: i64) -> Result<bool> {
        if let Some(lock) = self.candidates.get(&id).cloned() {
            return Ok(self.session.holds(&lock).await);
        }
        if self.global.is_none() || self.candidates.len() >= 16 {
            bail!("Candidate lock requires an active worker and a bounded batch.");
        }
        let Some(lock) = self.session.exclusive(&format!("candidate-{id}")).await? else {
            return Ok(false);
        };
        self.candidates.insert(id, lock);
        Ok(true)
    }

    /// A database lock is lost with its session, so protection is proved at each use.
    pub async fn protects(&mut self, id: i64) -> bool {
        if self.exclusive {
            return match self.global.clone() {
                Some(global) => self.session.holds(&global).await,
                None => false,
            };
        }
        match self.candidates.get(&id).cloned() {
            Some(lock) => self.session.holds(&lock).await,
            None => false,
        }
    }

    /// Whether this worker still holds its group and slot locks.
    pub async fn held(&mut self) -> bool {
        match (self.global.clone(), self.slot.clone()) {
            (Some(global), Some(slot)) => self.session.holds_all(&[&global, &slot]).await,
            _ => false,
        }
    }

    /// Maintenance of this host's scratch; each worker host has its own turn.
    pub async fn try_gc(&mut self) -> Result<bool> {
        let name = if self.host.is_empty() {
            "gc".to_owned()
        } else {
            format!("gc-{}", &hex::encode(Sha1::digest(&self.host))[..12])
        };
        self.gc = self.session.exclusive(&name).await?;
        Ok(self.gc.is_some())
    }

    /// Candidate expiry is shared by every host; a maintenance holder that misses it skips it.
    pub async fn try_expiry(&mut self) -> Result<bool> {
        if self.gc.is_none() {
            bail!("Expiry requires this host's maintenance lock.");
        }
        if self.expiry.is_none() {
            self.expiry = self.session.exclusive("expiry").await?;
        }
        Ok(self.expiry.is_some())
    }

    /// Alternates the first maintenance task across GC turns.
    pub fn prefer_expiry(&mut self) -> bool {
        let first = self.expiry_first;
        self.expiry_first = !first;
        first
    }

    pub async fn release_candidate(&mut self, id: Option<i64>) {
        let ids: Vec<i64> = match id {
            Some(id) => vec![id],
            None => self.candidates.keys().copied().collect(),
        };
        for id in ids {
            if let Some(lock) = self.candidates.remove(&id) {
                self.session.release(lock).await;
            }
        }
    }

    pub async fn release_gc(&mut self) {
        if let Some(lock) = self.expiry.take() {
            self.session.release(lock).await;
        }
        if let Some(lock) = self.gc.take() {
            self.session.release(lock).await;
        }
    }

    pub async fn close(&mut self) {
        self.release_candidate(None).await;
        self.release_gc().await;
        if let Some(lock) = self.slot.take() {
            self.session.release(lock).await;
        }
        if let Some(lock) = self.global.take() {
            self.session.release(lock).await;
        }
        self.exclusive = false;
    }

    /// Fails when the session is gone, so a loop stops rather than run unprotected.
    pub async fn ensure_held(&mut self) -> Result<()> {
        if !self.held().await {
            bail!(LOST_SESSION);
        }
        Ok(())
    }
}
