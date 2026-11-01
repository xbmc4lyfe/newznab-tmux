//! The scheduled rollups and housekeeping against the real schema.

use anyhow::Result;
use chrono::{TimeZone, Utc};
use nntmux_ops::{accounts, activity, stats, system_metrics};
use nntmux_testkit::TestDb;
use sqlx::MySqlPool;

async fn user(db: &MySqlPool, name: &str, grabs: i64, deleted: bool, role: i64) -> Result<u64> {
    let id = sqlx::query(
        "INSERT INTO users (username, email, password, api_token, roles_id, grabs, created_at, deleted_at) \
         VALUES (?, ?, 'x', ?, ?, ?, '2026-03-05 10:00:00', IF(?, NOW(), NULL))",
    )
    .bind(name)
    .bind(format!("{name}@example.com"))
    .bind(name)
    .bind(role)
    .bind(grabs)
    .bind(deleted)
    .execute(db)
    .await?
    .last_insert_id();
    sqlx::query(
        "INSERT INTO model_has_roles (role_id, model_type, model_id) VALUES (?, 'App\\\\Models\\\\User', ?)",
    )
    .bind(role)
    .bind(id)
    .execute(db)
    .await?;
    Ok(id)
}

#[tokio::test]
async fn grabbers_signups_and_roles_skip_deleted_users_and_update_in_place() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let db = &test.pool;
    let role: i64 = sqlx::query_scalar("SELECT CAST(id AS SIGNED) FROM roles ORDER BY id LIMIT 1")
        .fetch_one(db)
        .await?;
    let name: String = sqlx::query_scalar("SELECT name FROM roles WHERE id = ?")
        .bind(role)
        .fetch_one(db)
        .await?;
    let alice = user(db, "alice", 5, false, role).await?;
    user(db, "gone", 50, true, role).await?;
    user(db, "idle", 0, false, role).await?;

    stats::top_grabbers(db).await?;
    stats::users_by_month(db).await?;
    stats::users_by_role(db).await?;
    sqlx::query("UPDATE users SET grabs = 7 WHERE id = ?")
        .bind(alice)
        .execute(db)
        .await?;
    stats::top_grabbers(db).await?;
    stats::users_by_role(db).await?;

    let grabbers: Vec<(String, i32)> =
        sqlx::query_as("SELECT username, grabs FROM grab_stats ORDER BY id")
            .fetch_all(db)
            .await?;
    assert_eq!(grabbers, [("alice".to_owned(), 7)]);
    let signups: Vec<(String, i32, String)> = sqlx::query_as(
        "SELECT month, signups, DATE_FORMAT(sort_date, '%Y-%m-%d') FROM signup_stats",
    )
    .fetch_all(db)
    .await?;
    assert_eq!(
        signups,
        [("March 2026".to_owned(), 2, "2026-03-01".to_owned())]
    );
    let roles: Vec<(String, i32)> = sqlx::query_as("SELECT role, users FROM role_stats")
        .fetch_all(db)
        .await?;
    assert_eq!(roles, [(name, 2)]);
    test.drop_database().await
}

#[tokio::test]
async fn activity_rolls_up_hours_then_days_and_prunes() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let db = &test.pool;
    let now = Utc
        .with_ymd_and_hms(2026, 11, 2, 0, 20, 0)
        .single()
        .expect("valid date");
    let owner = user(db, "alice", 0, false, 1).await?;
    for at in [
        "2026-11-01 23:00:00",
        "2026-11-01 23:59:59",
        "2026-11-02 00:00:00",
    ] {
        sqlx::query("INSERT INTO user_downloads (users_id, hosthash, timestamp) VALUES (?, '', ?)")
            .bind(owner)
            .bind(at)
            .execute(db)
            .await?;
    }
    sqlx::query("INSERT INTO user_requests (users_id, request, timestamp) VALUES (?, 'q', ?)")
        .bind(owner)
        .bind("2026-11-01 23:30:00")
        .execute(db)
        .await?;

    stats::hourly_activity(db, now).await?;
    stats::hourly_activity(db, now).await?;
    let hourly: Vec<(i32, i32)> =
        sqlx::query_as("SELECT downloads_count, api_hits_count FROM user_activity_stats_hourly")
            .fetch_all(db)
            .await?;
    assert_eq!(hourly, [(2, 1)]);

    sqlx::query(
        "INSERT INTO user_activity_stats_hourly (stat_hour, downloads_count, api_hits_count) \
         VALUES ('2026-11-01 02:00:00', 10, 20), ('2026-10-31 23:00:00', 100, 200)",
    )
    .execute(db)
    .await?;
    stats::daily_activity(db, now).await?;
    let daily: Vec<(String, i32, i32)> = sqlx::query_as(
        "SELECT DATE_FORMAT(stat_date, '%Y-%m-%d'), downloads_count, api_hits_count \
         FROM user_activity_stats",
    )
    .fetch_all(db)
    .await?;
    assert_eq!(daily, [("2026-11-01".to_owned(), 12, 21)]);

    sqlx::query(
        "INSERT INTO user_activity_stats (stat_date, downloads_count, api_hits_count) \
         VALUES (DATE(NOW() - INTERVAL 91 DAY), 1, 1), (DATE(NOW() - INTERVAL 89 DAY), 1, 1)",
    )
    .execute(db)
    .await?;
    sqlx::query(
        "INSERT INTO user_activity_stats_hourly (stat_hour, downloads_count, api_hits_count) \
         VALUES (DATE_FORMAT(NOW() - INTERVAL 31 DAY, '%Y-%m-%d %H:00:00'), 1, 1), \
         (DATE_FORMAT(NOW() - INTERVAL 29 DAY, '%Y-%m-%d %H:00:00'), 1, 1)",
    )
    .execute(db)
    .await?;
    assert_eq!(activity::cleanup_daily_stats(db).await?, 1);
    assert!(activity::cleanup_hourly_stats(db).await? >= 1);
    let (stale, kept): (i64, i64) = sqlx::query_as(
        "SELECT CAST(COALESCE(SUM(stat_hour < NOW() - INTERVAL 30 DAY - INTERVAL 1 HOUR), 0) AS SIGNED), \
         CAST(COALESCE(SUM(stat_hour > NOW() - INTERVAL 30 DAY), 0) AS SIGNED) FROM user_activity_stats_hourly",
    )
    .fetch_one(db)
    .await?;
    assert_eq!(stale, 0);
    assert!(kept >= 1);

    sqlx::query("UPDATE user_downloads SET `timestamp` = NOW() - INTERVAL 2 DAY")
        .execute(db)
        .await?;
    sqlx::query("UPDATE user_requests SET `timestamp` = NOW()")
        .execute(db)
        .await?;
    assert_eq!(activity::cleanup_request_logs(db).await?, 3);
    test.drop_database().await
}

#[tokio::test]
async fn expired_promotions_and_periods_are_closed_once_with_history() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let db = &test.pool;
    sqlx::query(
        "INSERT INTO role_promotions (name, applicable_roles, end_date, is_active) VALUES \
         ('past', '[]', CURDATE() - INTERVAL 1 DAY, 1), ('today', '[]', CURDATE(), 1), \
         ('future', '[]', CURDATE() + INTERVAL 1 DAY, 1), ('open', '[]', NULL, 1), \
         ('off', '[]', '2020-01-01', 0)",
    )
    .execute(db)
    .await?;
    let mut disabled = accounts::disable_expired_promotions(db).await?;
    disabled.sort();
    assert_eq!(disabled, ["past", "today"]);
    assert!(accounts::disable_expired_promotions(db).await?.is_empty());

    sqlx::query(
        "INSERT INTO registration_periods (name, starts_at, ends_at, is_enabled, updated_by) VALUES \
         ('over', '2026-01-01 00:00:00', '2026-01-02 00:00:00', 1, 9), \
         ('later', '2026-01-01 00:00:00', NOW() + INTERVAL 1 DAY, 1, 9)",
    )
    .execute(db)
    .await?;
    let done = accounts::disable_expired_registration_periods(db).await?;
    assert_eq!(done, ["over"]);
    assert!(
        accounts::disable_expired_registration_periods(db)
            .await?
            .is_empty()
    );
    let (enabled, updated_by): (bool, Option<u64>) = sqlx::query_as(
        "SELECT is_enabled, updated_by FROM registration_periods WHERE name = 'over'",
    )
    .fetch_one(db)
    .await?;
    assert!(!enabled);
    assert_eq!(updated_by, None);
    let history: Vec<(String, String, String)> =
        sqlx::query_as("SELECT action, description, metadata FROM registration_status_history")
            .fetch_all(db)
            .await?;
    assert_eq!(history.len(), 1);
    assert_eq!(history[0].0, "period_completed");
    assert_eq!(
        history[0].1,
        "Scheduled open period \"over\" completed after reaching its end time."
    );
    let metadata: serde_json::Value = serde_json::from_str(&history[0].2)?;
    assert_eq!(metadata["period"]["ends_at"], "2026-01-02 00:00:00");
    test.drop_database().await
}

#[tokio::test]
async fn system_metrics_store_both_types_and_prune() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let db = &test.pool;
    system_metrics::collect(db).await?;
    let types: Vec<String> =
        sqlx::query_scalar("SELECT metric_type FROM system_metrics ORDER BY metric_type")
            .fetch_all(db)
            .await?;
    assert_eq!(types, ["cpu", "ram"]);
    sqlx::query(
        "UPDATE system_metrics SET recorded_at = NOW() - INTERVAL 61 DAY WHERE metric_type = 'cpu'",
    )
    .execute(db)
    .await?;
    assert_eq!(system_metrics::cleanup(db).await?, 1);
    test.drop_database().await
}
