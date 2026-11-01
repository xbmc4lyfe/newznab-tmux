//! Transactions that retry on InnoDB deadlocks, the way Laravel's `DB::transaction($work, $attempts)` did.

use std::time::Duration;

use rand::Rng;

/// MariaDB error codes for a deadlock and a lock wait timeout.
const LOCK_ERRORS: [&str; 2] = ["1213", "1205"];

/// True when the error is transient lock contention, so the whole transaction may run again.
pub fn is_lock_error(error: &anyhow::Error) -> bool {
    error.chain().any(|cause| {
        cause
            .downcast_ref::<sqlx::Error>()
            .is_some_and(sqlx_lock_error)
    })
}

fn sqlx_lock_error(error: &sqlx::Error) -> bool {
    let sqlx::Error::Database(database) = error else {
        return false;
    };
    database
        .code()
        .is_some_and(|code| code == "40001" || LOCK_ERRORS.contains(&code.as_ref()))
        || database
            .try_downcast_ref::<sqlx::mysql::MySqlDatabaseError>()
            .is_some_and(|mysql| matches!(mysql.number(), 1213 | 1205))
}

/// True when the error is a duplicate-key violation.
pub fn is_unique_violation(error: &sqlx::Error) -> bool {
    matches!(error, sqlx::Error::Database(database) if database.is_unique_violation())
}

/// Backoff before retry `attempt`: `min(500ms, 20ms * attempt)` plus up to 25ms of jitter, so
/// concurrent workers stop colliding on the same cadence.
pub fn lock_backoff(attempt: u32) -> Duration {
    let base = (20 * u64::from(attempt)).min(500);
    Duration::from_millis(base + rand::rng().random_range(0..=25))
}

/// Runs `$body` in a transaction on `$pool`, committing on `Ok`, and runs the whole thing again up
/// to `$attempts` times when a statement or the commit loses a deadlock. `$tx` is a
/// `&mut MySqlConnection`. `return` inside the body ends the attempt, not the caller.
#[macro_export]
macro_rules! in_tx {
    ($pool:expr, $attempts:expr, |$tx:ident| $body:block) => {{
        let mut attempt: u32 = 0;
        loop {
            attempt += 1;
            let result: ::anyhow::Result<_> = async {
                let mut transaction = $pool.begin().await?;
                let value = {
                    let $tx: &mut ::sqlx::MySqlConnection = &mut *transaction;
                    #[allow(clippy::redundant_closure_call)]
                    async { $body }.await?
                };
                transaction.commit().await?;
                Ok(value)
            }
            .await;
            match result {
                Err(error) if attempt < $attempts && $crate::txn::is_lock_error(&error) => {
                    ::tokio::time::sleep($crate::txn::lock_backoff(attempt)).await;
                }
                other => break other,
            }
        }
    }};
}
