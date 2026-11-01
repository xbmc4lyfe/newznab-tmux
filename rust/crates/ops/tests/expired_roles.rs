//! Role expiry against the real schema, with the mail outbox on a key private to the test.

use std::sync::Arc;

use anyhow::Result;
use chrono::{TimeDelta, Utc};
use nntmux_core::Config;
use nntmux_ops::expired_roles::{self, Report};
use nntmux_runtime::Ctx;
use nntmux_testkit::TestDb;
use sqlx::MySqlPool;

async fn context(test: &TestDb) -> Result<Option<Ctx>> {
    let Ok(url) = std::env::var("TEST_REDIS_URL") else {
        return Ok(None);
    };
    let mut config = Config::from_env()?;
    config.database.database.clone_from(&test.name);
    Ok(Some(Ctx {
        config: Arc::new(config),
        db: test.pool.clone(),
        redis: redis::aio::ConnectionManager::new(redis::Client::open(url)?).await?,
    }))
}

async fn user(
    db: &MySqlPool,
    name: &str,
    role: i64,
    expiry_days: i64,
    deleted: bool,
) -> Result<u64> {
    let id = sqlx::query(
        "INSERT INTO users (username, email, password, api_token, roles_id, rolechangedate, deleted_at) \
         VALUES (?, ?, 'x', ?, ?, NOW() + INTERVAL ? DAY, IF(?, NOW(), NULL))",
    )
    .bind(name)
    .bind(format!("{name}@example.com"))
    .bind(name)
    .bind(role)
    .bind(expiry_days)
    .bind(deleted)
    .execute(db)
    .await?
    .last_insert_id();
    sqlx::query("INSERT INTO model_has_roles (role_id, model_type, model_id) VALUES (?, 'App\\\\Models\\\\User', ?)")
        .bind(role)
        .bind(id)
        .execute(db)
        .await?;
    Ok(id)
}

async fn state(db: &MySqlPool, id: u64) -> Result<(i64, bool, Vec<i64>, Vec<String>)> {
    let (role, expiry): (i64, Option<chrono::NaiveDateTime>) =
        sqlx::query_as("SELECT CAST(roles_id AS SIGNED), rolechangedate FROM users WHERE id = ?")
            .bind(id)
            .fetch_one(db)
            .await?;
    let permissions: Vec<i64> = sqlx::query_scalar(
        "SELECT CAST(role_id AS SIGNED) FROM model_has_roles WHERE model_id = ?",
    )
    .bind(id)
    .fetch_all(db)
    .await?;
    let history: Vec<String> = sqlx::query_scalar(
        "SELECT change_reason FROM user_role_history WHERE user_id = ? ORDER BY id",
    )
    .bind(id)
    .fetch_all(db)
    .await?;
    Ok((role, expiry.is_some(), permissions, history))
}

#[tokio::test]
async fn expired_users_move_to_their_pending_role_or_back_to_the_default() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&test).await? else {
        return Ok(());
    };
    let db = &test.pool;
    let vip = 2;
    sqlx::query("UPDATE roles SET addyears = 1 WHERE id = ?")
        .bind(vip)
        .execute(db)
        .await?;
    sqlx::query(
        "INSERT INTO role_promotions (name, applicable_roles, additional_days, is_active) VALUES \
         ('extra', ?, 10, 1), ('other', '[99]', 500, 1), ('off', ?, 500, 0)",
    )
    .bind(format!("[{vip}]"))
    .bind(format!("[{vip}]"))
    .execute(db)
    .await?;

    let gone = user(db, "gone", vip, -1, false).await?;
    let stacked = user(db, "stacked", vip, -1, false).await?;
    sqlx::query("UPDATE users SET pending_roles_id = ?, pending_role_start_date = NOW() - INTERVAL 1 HOUR WHERE id = ?")
        .bind(vip)
        .bind(stacked)
        .execute(db)
        .await?;
    let waiting = user(db, "waiting", vip, -1, false).await?;
    sqlx::query("UPDATE users SET pending_roles_id = ?, pending_role_start_date = NOW() + INTERVAL 1 DAY WHERE id = ?")
        .bind(vip)
        .bind(waiting)
        .execute(db)
        .await?;
    let current = user(db, "current", vip, 100, false).await?;
    let deleted = user(db, "deleted", vip, -1, true).await?;
    let soon = user(db, "soon", vip, 7, false).await?;
    sqlx::query("UPDATE users SET rolechangedate = NOW() + INTERVAL 7 DAY WHERE id = ?")
        .bind(soon)
        .execute(db)
        .await?;

    let report = expired_roles::update_expired_roles(&ctx).await?;
    assert_eq!(
        report,
        Report {
            warned: 1,
            activated: 1,
            downgraded: 2,
        }
    );

    assert_eq!(
        state(db, gone).await?,
        (1, false, vec![1], vec!["role_expired".to_owned()])
    );
    // A pending role still in the future doesn't save an expired user.
    assert_eq!(
        state(db, waiting).await?,
        (1, false, vec![1], vec!["role_expired".to_owned()])
    );
    assert_eq!(
        state(db, stacked).await?,
        (
            vip,
            true,
            vec![vip],
            vec!["stacked_role_activated".to_owned()]
        )
    );
    let expiry: chrono::NaiveDateTime =
        sqlx::query_scalar("SELECT rolechangedate FROM users WHERE id = ?")
            .bind(stacked)
            .fetch_one(db)
            .await?;
    let expected = Utc::now().naive_utc() + TimeDelta::days(375);
    assert!((expiry - expected).num_minutes().abs() < 5, "{expiry}");
    let (is_stacked, pending): (bool, Option<i64>) = sqlx::query_as(
        "SELECT h.is_stacked, CAST(u.pending_roles_id AS SIGNED) FROM user_role_history h JOIN users u ON u.id = h.user_id WHERE u.id = ?",
    )
    .bind(stacked)
    .fetch_one(db)
    .await?;
    assert!(is_stacked);
    assert_eq!(pending, None);
    assert_eq!(state(db, current).await?, (vip, true, vec![vip], vec![]));
    assert_eq!(state(db, deleted).await?, (vip, true, vec![vip], vec![]));
    test.drop_database().await
}
