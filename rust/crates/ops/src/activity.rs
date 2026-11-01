//! Retention for the user activity rollups and the raw logs they're built from.

use anyhow::Result;
use sqlx::MySqlPool;

/// Weekly: keeps the last 90 days of daily stats.
pub async fn cleanup_daily_stats(db: &MySqlPool) -> Result<u64> {
    Ok(sqlx::query(
        "DELETE FROM user_activity_stats WHERE stat_date < DATE(NOW() - INTERVAL 90 DAY)",
    )
    .execute(db)
    .await?
    .rows_affected())
}

/// Daily: keeps the last 30 days of hourly stats, counted from the start of this hour.
pub async fn cleanup_hourly_stats(db: &MySqlPool) -> Result<u64> {
    Ok(sqlx::query(
        "DELETE FROM user_activity_stats_hourly \
         WHERE stat_hour < DATE_FORMAT(NOW() - INTERVAL 30 DAY, '%Y-%m-%d %H:00:00')",
    )
    .execute(db)
    .await?
    .rows_affected())
}

/// Hourly: API requests and download logs older than a day, deferred from the API path.
pub async fn cleanup_request_logs(db: &MySqlPool) -> Result<u64> {
    let requests =
        sqlx::query("DELETE FROM user_requests WHERE `timestamp` < NOW() - INTERVAL 1 DAY")
            .execute(db)
            .await?
            .rows_affected();
    let downloads =
        sqlx::query("DELETE FROM user_downloads WHERE `timestamp` < NOW() - INTERVAL 1 DAY")
            .execute(db)
            .await?
            .rows_affected();
    Ok(requests + downloads)
}
