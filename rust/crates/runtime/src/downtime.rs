//! Gives incomplete collections back the time they lost while the indexer was down.
//!
//! Collections wait the collection delay for missing parts before they're processed as stale. When
//! ingestion stops, that clock keeps running, so after a long outage collections would be cut short.
//! Workers record when ingestion was last active, and on the next start only the collections still
//! waiting at that moment get their lost time back.
//!
//! The state is one row and the lock is a session lock, because role containers share no filesystem.
//! An outage is claimed in one transaction with the shift it starts, and each window of that shift
//! commits with its position, so a crash resumes where it stopped and never moves a row twice.
//!
//! `dateadded` holds UTC, the time zone every connection and the app use, so wall-clock arithmetic
//! is real-time arithmetic and needs no daylight saving handling.

use std::future::Future;
use std::time::Duration;

use anyhow::{Result, bail};
use chrono::{DateTime, NaiveDateTime, Utc};
use sqlx::{ConnectOptions, Connection, MySqlConnection, MySqlPool};

use crate::Ctx;

/// Restarts shorter than this (deploys, crash recovery) need no compensation.
pub const MIN_DOWNTIME_SECONDS: i64 = 300;
const WINDOW_SECONDS: i64 = 60;
const NOTE_INTERVAL_SECONDS: i64 = 30;
/// Safety cap on `UPDATE` windows per start; each window holds at least one row.
const MAX_WINDOWS: usize = 100_000;
const WINDOW_ATTEMPTS: u32 = 5;
const LOCK_WAIT_SECONDS: u32 = 30;
/// Statuses whose reconciliation is gated by the collection delay; 10 is legacy.
const WAITING: &str = "0, 1, 15, 16, 10";

/// Records that ingestion is running now. Called from the monitor loop.
pub async fn record_activity(ctx: &Ctx) -> Result<()> {
    record_activity_on(&ctx.db).await
}

/// Records activity from ingestion workers, at most every 30 seconds so many workers don't contend
/// for the lock.
pub async fn note_ingestion(ctx: &Ctx) -> Result<()> {
    note_ingestion_on(&ctx.db).await
}

/// Pushes back the collections still waiting when the indexer stopped. The lock is held from reading
/// the row to the last update, so two overlapping starts can't both claim the same outage: the
/// second waits, then sees a short downtime. Returns the collections moved.
pub async fn compensate(ctx: &Ctx, delay_hours: i64) -> Result<u64> {
    let barrier = Barrier::lock(&ctx.db).await?;
    let moved = compensate_locked(&ctx.db, delay_hours, false, usize::MAX).await;
    barrier.release().await;
    moved
}

/// Runs `work` with any outage compensated first and the lock held, so a reconciliation page and a
/// shift never interleave.
pub async fn with_reconciliation_barrier<T, F, Fut>(
    ctx: &Ctx,
    delay_hours: i64,
    work: F,
) -> Result<T>
where
    F: FnOnce() -> Fut,
    Fut: Future<Output = Result<T>>,
{
    let barrier = enter(&ctx.db, delay_hours).await?;
    let result = work().await;
    barrier.release().await;
    result
}

/// [`with_reconciliation_barrier`] for callers that can't pass their work as a closure.
pub async fn enter(pool: &MySqlPool, delay_hours: i64) -> Result<Barrier> {
    let barrier = Barrier::lock(pool).await?;
    compensate_locked(pool, delay_hours, false, usize::MAX).await?;
    Ok(barrier)
}

pub async fn record_activity_on(pool: &MySqlPool) -> Result<()> {
    let barrier = Barrier::lock(pool).await?;
    let delay: Option<u32> = sqlx::query_scalar(
        "SELECT collection_delay_hours FROM ingestion_configurations WHERE id = 1",
    )
    .fetch_optional(pool)
    .await?;
    let result = compensate_locked(pool, delay.map_or(2, i64::from), true, usize::MAX).await;
    barrier.release().await;
    result.map(|_| ())
}

pub async fn note_ingestion_on(pool: &MySqlPool) -> Result<()> {
    if last_activity(pool)
        .await?
        .is_some_and(|last| Utc::now().timestamp() - last < NOTE_INTERVAL_SECONDS)
    {
        return Ok(());
    }
    record_activity_on(pool).await
}

/// When ingestion was last recorded active, in Unix seconds.
pub async fn last_activity(pool: &MySqlPool) -> Result<Option<i64>> {
    Ok(
        sqlx::query_scalar("SELECT last_active_at FROM ingestion_activity WHERE id = 1")
            .fetch_optional(pool)
            .await?,
    )
}

/// Held while compensation or a reconciliation page runs. It's a session lock on its own connection,
/// outside the pool, because waiters must never starve the holder of pooled connections. Dropping it
/// without [`Barrier::release`] closes the connection, which ends the lock too.
pub struct Barrier {
    conn: MySqlConnection,
}

impl Barrier {
    /// Waits for the lock.
    async fn lock(pool: &MySqlPool) -> Result<Self> {
        let mut conn = pool.connect_options().connect().await?;
        loop {
            let got: Option<i64> =
                sqlx::query_scalar("SELECT GET_LOCK(CONCAT('nnd:', MD5(DATABASE())), ?)")
                    .bind(LOCK_WAIT_SECONDS)
                    .fetch_one(&mut conn)
                    .await?;
            match got {
                Some(1) => return Ok(Self { conn }),
                Some(_) => {}
                None => bail!("the ingestion activity lock failed"),
            }
        }
    }

    /// Closing the session releases the lock.
    pub async fn release(self) {
        let _ = self.conn.close().await;
    }
}

#[derive(sqlx::FromRow)]
struct Activity {
    last_active_at: i64,
    shift_last_active: Option<i64>,
    shift_now: Option<i64>,
    shift_delay_seconds: Option<i64>,
    shift_upper_id: Option<u64>,
    shift_window_end: Option<NaiveDateTime>,
    shift_inclusive: bool,
}

/// One outage's shift, as stored between windows.
#[derive(Clone, Copy)]
struct Shift {
    last_active: i64,
    now: i64,
    delay_seconds: i64,
    upper_id: u64,
    window_end: NaiveDateTime,
    inclusive: bool,
}

impl Activity {
    fn shift(&self) -> Option<Shift> {
        Some(Shift {
            last_active: self.shift_last_active?,
            now: self.shift_now?,
            delay_seconds: self.shift_delay_seconds?,
            upper_id: self.shift_upper_id?,
            window_end: self.shift_window_end?,
            inclusive: self.shift_inclusive,
        })
    }
}

fn at(seconds: i64) -> NaiveDateTime {
    DateTime::from_timestamp(seconds, 0)
        .unwrap_or_default()
        .naive_utc()
}

/// The caller holds the lock. Finishes a shift a crash left behind, then claims any new outage,
/// including one direct header workers find before the monitor starts. With `refresh_recent` it also
/// records that ingestion is active now.
async fn compensate_locked(
    pool: &MySqlPool,
    delay_hours: i64,
    refresh_recent: bool,
    max_windows: usize,
) -> Result<u64> {
    let row: Option<Activity> = sqlx::query_as("SELECT * FROM ingestion_activity WHERE id = 1")
        .fetch_optional(pool)
        .await?;
    let mut moved = 0;
    let mut last = row.as_ref().map(|row| row.last_active_at);
    if let Some(shift) = row.as_ref().and_then(Activity::shift) {
        moved += run_shift(pool, shift, max_windows).await?;
        last = Some(shift.now);
    }
    let now = Utc::now().timestamp();
    let stale = last.is_some_and(|last| now - last >= MIN_DOWNTIME_SECONDS);
    if !stale && !refresh_recent && last.is_some() {
        return Ok(moved);
    }
    let shift = match last {
        Some(last) if stale && delay_hours > 0 => {
            // The id ceiling comes before the recovery time: active header workers may insert rows
            // while the windows are walked.
            let upper: Option<u32> = sqlx::query_scalar("SELECT MAX(id) FROM collections")
                .fetch_one(pool)
                .await?;
            let now = Utc::now().timestamp();
            Some(Shift {
                last_active: last,
                now,
                delay_seconds: delay_hours * 3600,
                upper_id: u64::from(upper.unwrap_or(0)),
                window_end: at(now).max(at(last)),
                inclusive: true,
            })
        }
        _ => None,
    };
    let claimed = shift.map_or_else(|| Utc::now().timestamp(), |shift| shift.now);
    sqlx::query(
        "INSERT INTO ingestion_activity (id, last_active_at, shift_last_active, shift_now, shift_delay_seconds, \
         shift_upper_id, shift_window_end, shift_inclusive) VALUES (1, ?, ?, ?, ?, ?, ?, 1) \
         ON DUPLICATE KEY UPDATE last_active_at = VALUES(last_active_at), shift_last_active = VALUES(shift_last_active), \
         shift_now = VALUES(shift_now), shift_delay_seconds = VALUES(shift_delay_seconds), \
         shift_upper_id = VALUES(shift_upper_id), shift_window_end = VALUES(shift_window_end), shift_inclusive = 1",
    )
    .bind(claimed)
    .bind(shift.map(|shift| shift.last_active))
    .bind(shift.map(|shift| shift.now))
    .bind(shift.map(|shift| shift.delay_seconds))
    .bind(shift.map(|shift| shift.upper_id))
    .bind(shift.map(|shift| shift.window_end))
    .execute(pool)
    .await?;
    if let Some(shift) = shift {
        moved += run_shift(pool, shift, max_windows).await?;
    }
    Ok(moved)
}

/// Each collection that was waiting when ingestion stopped gets back the waiting time it lost: the
/// full downtime for those added before the last recorded activity, which can't move them past the
/// restart, and only the time since they were added for those written in the moments after it.
///
/// One `dateadded` window at a time, newest first, jumping straight to the next occupied window:
/// each `UPDATE` is a range on the `dateadded` index, and a moved row lands in a later window that's
/// already done, or at now, so nothing is shifted twice, and the work follows the waiting rows rather
/// than the id range or the length of the outage.
async fn run_shift(pool: &MySqlPool, mut shift: Shift, max_windows: usize) -> Result<u64> {
    let waiting_from = shift.last_active - shift.delay_seconds;
    let mut moved = 0;
    for _ in 0..MAX_WINDOWS.min(max_windows) {
        let comparison = if shift.inclusive { "<=" } else { "<" };
        let sql = format!(
            "SELECT dateadded FROM collections WHERE filecheck IN ({WAITING}) AND id <= ? \
             AND dateadded >= ? AND dateadded {comparison} ? ORDER BY dateadded DESC LIMIT 1"
        );
        let newest: Option<NaiveDateTime> = sqlx::query_scalar(sqlx::AssertSqlSafe(sql))
            .bind(shift.upper_id)
            .bind(at(waiting_from))
            .bind(shift.window_end)
            .fetch_optional(pool)
            .await?;
        let Some(newest) = newest else {
            finish_shift(pool).await?;
            return Ok(moved);
        };
        let start = at((newest.and_utc().timestamp() - WINDOW_SECONDS).max(waiting_from));
        moved += shift_window(pool, &shift, start, newest).await?;
        shift.window_end = start;
        shift.inclusive = false;
    }
    if max_windows >= MAX_WINDOWS {
        finish_shift(pool).await?;
    }
    Ok(moved)
}

async fn finish_shift(pool: &MySqlPool) -> Result<()> {
    sqlx::query(
        "UPDATE ingestion_activity SET shift_last_active = NULL, shift_now = NULL, shift_delay_seconds = NULL, \
         shift_upper_id = NULL, shift_window_end = NULL, shift_inclusive = 1 WHERE id = 1",
    )
    .execute(pool)
    .await?;
    Ok(())
}

/// Moves one window and records that it's done in one transaction. Header storage locks collections
/// in its own order, so a window can lose a deadlock and is retried.
async fn shift_window(
    pool: &MySqlPool,
    shift: &Shift,
    start: NaiveDateTime,
    newest: NaiveDateTime,
) -> Result<u64> {
    let mut attempt = 0;
    loop {
        attempt += 1;
        match try_shift_window(pool, shift, start, newest).await {
            Err(error) if attempt < WINDOW_ATTEMPTS && lock_contention(&error) => {
                tokio::time::sleep(Duration::from_millis(20 * u64::from(attempt))).await;
            }
            other => return Ok(other?),
        }
    }
}

fn lock_contention(error: &sqlx::Error) -> bool {
    let sqlx::Error::Database(database) = error else {
        return false;
    };
    database
        .try_downcast_ref::<sqlx::mysql::MySqlDatabaseError>()
        .is_some_and(|mysql| matches!(mysql.number(), 1213 | 1205))
}

async fn try_shift_window(
    pool: &MySqlPool,
    shift: &Shift,
    start: NaiveDateTime,
    newest: NaiveDateTime,
) -> Result<u64, sqlx::Error> {
    let downtime = (shift.now - shift.last_active).max(0);
    let mut tx = pool.begin().await?;
    let sql = format!(
        "UPDATE collections SET dateadded = dateadded + INTERVAL (CASE WHEN dateadded <= ? THEN ? \
         ELSE GREATEST(0, LEAST(TIMESTAMPDIFF(SECOND, dateadded, ?), ?)) END) SECOND \
         WHERE filecheck IN ({WAITING}) AND id <= ? AND dateadded >= ? AND dateadded <= ?"
    );
    let moved = sqlx::query(sqlx::AssertSqlSafe(sql))
        .bind(at(shift.last_active))
        .bind(downtime)
        .bind(at(shift.now))
        .bind(downtime)
        .bind(shift.upper_id)
        .bind(start)
        .bind(newest)
        .execute(&mut *tx)
        .await?
        .rows_affected();
    sqlx::query(
        "UPDATE ingestion_activity SET shift_window_end = ?, shift_inclusive = 0 WHERE id = 1",
    )
    .bind(start)
    .execute(&mut *tx)
    .await?;
    tx.commit().await?;
    Ok(moved)
}

#[cfg(test)]
mod tests {
    use chrono::TimeDelta;
    use nntmux_testkit::TestDb;

    use super::*;

    async fn add_collection(
        pool: &MySqlPool,
        hash: u8,
        filecheck: i8,
        added: NaiveDateTime,
    ) -> Result<u64> {
        Ok(sqlx::query(
            "INSERT INTO collections (subject, fromname, xref, groups_id, collectionhash, filecheck, dateadded) \
             VALUES ('s', 'f', '', 1, ?, ?, ?)",
        )
        .bind(vec![hash; 20])
        .bind(filecheck)
        .bind(added)
        .execute(pool)
        .await?
        .last_insert_id())
    }

    async fn added(pool: &MySqlPool, id: u64) -> Result<NaiveDateTime> {
        Ok(
            sqlx::query_scalar("SELECT dateadded FROM collections WHERE id = ?")
                .bind(id)
                .fetch_one(pool)
                .await?,
        )
    }

    async fn set_last_active(pool: &MySqlPool, seconds: i64) -> Result<()> {
        sqlx::query("INSERT INTO ingestion_activity (id, last_active_at) VALUES (1, ?)")
            .bind(seconds)
            .execute(pool)
            .await?;
        Ok(())
    }

    #[tokio::test]
    async fn an_outage_moves_only_the_collections_still_waiting() -> Result<()> {
        let Some(db) = TestDb::create().await? else {
            return Ok(());
        };
        let now = Utc::now().timestamp();
        let last = now - 3600;
        set_last_active(&db.pool, last).await?;
        let waiting = add_collection(&db.pool, 1, 0, at(last - 600)).await?;
        let complete = add_collection(&db.pool, 2, 2, at(last - 600)).await?;
        let expired = add_collection(&db.pool, 3, 0, at(last - 3 * 3600)).await?;
        let after = add_collection(&db.pool, 4, 0, at(last + 1800)).await?;

        let barrier = Barrier::lock(&db.pool).await?;
        let moved = compensate_locked(&db.pool, 2, false, usize::MAX).await?;
        barrier.release().await;
        assert_eq!(moved, 2);
        let shifted = added(&db.pool, waiting).await? - at(last - 600);
        assert!((3600..3610).contains(&shifted.num_seconds()), "{shifted}");
        assert_eq!(added(&db.pool, complete).await?, at(last - 600));
        assert_eq!(added(&db.pool, expired).await?, at(last - 3 * 3600));
        // Added after the last activity: only as far as now, not the whole downtime.
        let moved_to = added(&db.pool, after).await?;
        assert!(moved_to <= at(Utc::now().timestamp()), "{moved_to}");
        assert!(moved_to >= at(now - 5));
        // The outage is claimed: nothing moves again.
        let barrier = Barrier::lock(&db.pool).await?;
        assert_eq!(compensate_locked(&db.pool, 2, false, usize::MAX).await?, 0);
        barrier.release().await;
        db.drop_database().await
    }

    #[tokio::test]
    async fn a_crash_mid_shift_resumes_without_moving_a_row_twice() -> Result<()> {
        let Some(db) = TestDb::create().await? else {
            return Ok(());
        };
        let now = Utc::now().timestamp();
        let last = now - 3600;
        set_last_active(&db.pool, last).await?;
        // Far enough apart to fall in separate windows.
        let ids = [
            add_collection(&db.pool, 1, 0, at(last - 100)).await?,
            add_collection(&db.pool, 2, 1, at(last - 300)).await?,
            add_collection(&db.pool, 3, 16, at(last - 500)).await?,
        ];
        let originals = [at(last - 100), at(last - 300), at(last - 500)];

        // One window, then the process dies with the shift still recorded.
        let barrier = Barrier::lock(&db.pool).await?;
        assert_eq!(compensate_locked(&db.pool, 2, false, 1).await?, 1);
        drop(barrier);
        let pending: Option<i64> =
            sqlx::query_scalar("SELECT shift_now FROM ingestion_activity WHERE id = 1")
                .fetch_one(&db.pool)
                .await?;
        assert!(pending.is_some());
        assert_ne!(added(&db.pool, ids[0]).await?, originals[0]);
        assert_eq!(added(&db.pool, ids[1]).await?, originals[1]);

        let barrier = Barrier::lock(&db.pool).await?;
        assert_eq!(compensate_locked(&db.pool, 2, false, usize::MAX).await?, 2);
        barrier.release().await;
        let downtime = TimeDelta::seconds(pending.expect("checked") - last);
        for (id, original) in ids.iter().zip(originals) {
            assert_eq!(added(&db.pool, *id).await?, original + downtime);
        }
        let pending: Option<i64> =
            sqlx::query_scalar("SELECT shift_now FROM ingestion_activity WHERE id = 1")
                .fetch_one(&db.pool)
                .await?;
        assert_eq!(pending, None);
        db.drop_database().await
    }

    #[tokio::test]
    async fn short_restarts_and_notes_change_nothing() -> Result<()> {
        let Some(db) = TestDb::create().await? else {
            return Ok(());
        };
        let now = Utc::now().timestamp();
        // First run: nothing to compare with, so it only records.
        let id = add_collection(&db.pool, 1, 0, at(now - 200)).await?;
        record_activity_on(&db.pool).await?;
        assert!(last_activity(&db.pool).await?.is_some());

        sqlx::query("UPDATE ingestion_activity SET last_active_at = ?")
            .bind(now - 100)
            .execute(&db.pool)
            .await?;
        // Under the minimum downtime: no shift. Under the note interval: no write at all.
        record_activity_on(&db.pool).await?;
        assert_eq!(added(&db.pool, id).await?, at(now - 200));
        sqlx::query("UPDATE ingestion_activity SET last_active_at = ?")
            .bind(now - 10)
            .execute(&db.pool)
            .await?;
        note_ingestion_on(&db.pool).await?;
        assert_eq!(last_activity(&db.pool).await?, Some(now - 10));
        db.drop_database().await
    }

    #[tokio::test]
    async fn the_barrier_excludes_a_second_holder() -> Result<()> {
        let Some(db) = TestDb::create().await? else {
            return Ok(());
        };
        let barrier = enter(&db.pool, 2).await?;
        let pool = db.pool.clone();
        let waiter = tokio::spawn(async move { enter(&pool, 2).await.map(|_| ()) });
        tokio::time::sleep(Duration::from_millis(200)).await;
        assert!(!waiter.is_finished());
        barrier.release().await;
        waiter.await??;
        db.drop_database().await
    }
}
