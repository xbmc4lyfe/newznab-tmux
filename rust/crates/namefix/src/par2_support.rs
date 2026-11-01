//! What every PAR2 stage shares: the ownership guard, the "ownership or identity was lost"
//! signal that rolls a stage back, and the locked release row.

use std::sync::atomic::{AtomicBool, Ordering};

use anyhow::Result;
use async_trait::async_trait;
use sqlx::{MySqlConnection, Row};

/// Asked before and after every write: does the caller still hold its claim on the release?
/// Anything that can lose a lock or a deadline implements it; `true` means keep going.
#[async_trait]
pub trait Guard: Send + Sync {
    async fn holds(&self) -> bool;
}

/// A guard that also covers article reads, which can't await: the NNTP client asks a plain
/// function whether to keep reading, so a background heartbeat keeps the flag it answers from.
#[async_trait]
pub trait Claim: Guard {
    /// Cheap and synchronous: false once the claim was lost while an article was being read.
    fn read_ok(&self) -> bool;
    /// Refreshes the claim periodically while an article is read. Returns only if the claim
    /// is lost; the caller races it against the read.
    async fn keep_alive(&self);
    /// Asked once an article arrived: whether the claim still holds.
    async fn received(&self) -> bool;
}

/// A claim that is never lost, for callers with nothing to lose.
pub struct Unguarded;

#[async_trait]
impl Guard for Unguarded {
    async fn holds(&self) -> bool {
        true
    }
}

#[async_trait]
impl Claim for Unguarded {
    fn read_ok(&self) -> bool {
        true
    }

    async fn keep_alive(&self) {
        std::future::pending::<()>().await;
    }

    async fn received(&self) -> bool {
        true
    }
}

/// A claim a test can drop.
pub struct Switch(pub AtomicBool);

impl Switch {
    pub fn new(held: bool) -> Self {
        Self(AtomicBool::new(held))
    }

    pub fn set(&self, held: bool) {
        self.0.store(held, Ordering::SeqCst);
    }
}

#[async_trait]
impl Guard for Switch {
    async fn holds(&self) -> bool {
        self.0.load(Ordering::SeqCst)
    }
}

#[async_trait]
impl Claim for Switch {
    fn read_ok(&self) -> bool {
        self.0.load(Ordering::SeqCst)
    }

    async fn keep_alive(&self) {
        std::future::pending::<()>().await;
    }

    async fn received(&self) -> bool {
        self.0.load(Ordering::SeqCst)
    }
}

/// The claim or the release identity changed under a stage, so its transaction must not
/// commit. Stages turn this into `false` or a deferred outcome; any other error is a failure.
#[derive(Debug)]
pub struct Aborted(pub &'static str);

impl std::fmt::Display for Aborted {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(self.0)
    }
}

impl std::error::Error for Aborted {}

pub fn aborted(message: &'static str) -> anyhow::Error {
    Aborted(message).into()
}

/// Runs a stage whose `Aborted` result means "not done", and whose other errors are real.
pub fn absorb_abort(result: Result<bool>) -> Result<bool> {
    match result {
        Err(error) if error.downcast_ref::<Aborted>().is_some() => Ok(false),
        other => other,
    }
}

/// A release row read under `FOR UPDATE`.
#[derive(Clone, Debug, PartialEq)]
pub struct LockedRelease {
    pub id: i64,
    pub guid: String,
    pub name: Option<String>,
    pub searchname: Option<String>,
    pub groups_id: i64,
    pub predb_id: i64,
    pub isrenamed: i64,
    pub proc_par2: i64,
    pub proc_hash16k: i64,
    pub size: i64,
    pub categories_id: i64,
    pub postdate: Option<String>,
}

const LOCKED_COLUMNS: &str = "CAST(id AS SIGNED) AS id, guid, name, searchname, \
     CAST(groups_id AS SIGNED) AS groups_id, CAST(predb_id AS SIGNED) AS predb_id, \
     CAST(isrenamed AS SIGNED) AS isrenamed, CAST(proc_par2 AS SIGNED) AS proc_par2, \
     CAST(proc_hash16k AS SIGNED) AS proc_hash16k, CAST(size AS SIGNED) AS size, \
     CAST(categories_id AS SIGNED) AS categories_id, \
     DATE_FORMAT(postdate, '%Y-%m-%d %H:%i:%s') AS postdate";

impl LockedRelease {
    fn from_row(row: &sqlx::mysql::MySqlRow) -> Result<Self> {
        Ok(Self {
            id: row.try_get("id")?,
            guid: row.try_get("guid")?,
            name: row.try_get("name")?,
            searchname: row.try_get("searchname")?,
            groups_id: row.try_get("groups_id")?,
            predb_id: row.try_get("predb_id")?,
            isrenamed: row.try_get("isrenamed")?,
            proc_par2: row.try_get("proc_par2")?,
            proc_hash16k: row.try_get("proc_hash16k")?,
            size: row.try_get("size")?,
            categories_id: row.try_get("categories_id")?,
            postdate: row.try_get("postdate")?,
        })
    }

    /// The row under `FOR UPDATE`.
    pub async fn lock(conn: &mut MySqlConnection, id: i64) -> Result<Option<Self>> {
        let sql = format!("SELECT {LOCKED_COLUMNS} FROM releases WHERE id = ? FOR UPDATE");
        let row = sqlx::query(sqlx::AssertSqlSafe(sql))
            .bind(id)
            .fetch_optional(conn)
            .await?;
        row.as_ref().map(Self::from_row).transpose()
    }

    /// The row without a lock.
    pub async fn read(conn: &mut MySqlConnection, id: i64) -> Result<Option<Self>> {
        let sql = format!("SELECT {LOCKED_COLUMNS} FROM releases WHERE id = ?");
        let row = sqlx::query(sqlx::AssertSqlSafe(sql))
            .bind(id)
            .fetch_optional(conn)
            .await?;
        row.as_ref().map(Self::from_row).transpose()
    }
}
