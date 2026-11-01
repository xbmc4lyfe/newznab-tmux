//! Only one supervisor schedules at a time. The lock is a session lock on its own connection, so a
//! dead supervisor releases it as soon as its connection closes, with no stale state to clean up.

use std::time::Duration;

use anyhow::Result;
use nntmux_runtime::Shutdown;
use sqlx::{ConnectOptions, MySqlConnection, MySqlPool};
use tracing::info;

const LOCK: &str = "CONCAT('nntmux-supervisor:', MD5(DATABASE()))";
const WAIT_SECONDS: u32 = 5;

pub struct Leader {
    conn: MySqlConnection,
}

impl Leader {
    /// Waits to become the leader. `None` means a shutdown came first.
    pub async fn acquire(pool: &MySqlPool, stop: &Shutdown) -> Result<Option<Self>> {
        let mut conn = pool.connect_options().connect().await?;
        let mut reported = false;
        while !stop.requested() {
            let got: Option<i64> =
                sqlx::query_scalar(sqlx::AssertSqlSafe(format!("SELECT GET_LOCK({LOCK}, ?)")))
                    .bind(WAIT_SECONDS)
                    .fetch_one(&mut conn)
                    .await?;
            if got == Some(1) {
                info!("this supervisor is scheduling");
                return Ok(Some(Self { conn }));
            }
            if !reported {
                info!("another supervisor is scheduling; standing by");
                reported = true;
            }
            stop.sleep(Duration::from_millis(100)).await;
        }
        Ok(None)
    }

    /// False once the connection, and with it the lock, is gone.
    pub async fn still_held(&mut self) -> bool {
        let holder: Result<Option<i64>, _> = sqlx::query_scalar(sqlx::AssertSqlSafe(format!(
            "SELECT IS_USED_LOCK({LOCK}) = CONNECTION_ID()"
        )))
        .fetch_one(&mut self.conn)
        .await;
        matches!(holder, Ok(Some(1)))
    }
}
