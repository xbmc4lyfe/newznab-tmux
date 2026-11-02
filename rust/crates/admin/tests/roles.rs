use std::sync::Arc;

use anyhow::Result;
use chrono::NaiveDateTime;
use nntmux_admin::roles::{
    Activation, activate_pending, cancel_all_pending, cancel_pending, find, history, list_pending,
};
use nntmux_core::Config;
use nntmux_runtime::Ctx;
use nntmux_testkit::TestDb;
use serde_json::Value;

const USER_MODEL: &str = r"App\Models\User";

fn at(raw: &str) -> NaiveDateTime {
    NaiveDateTime::parse_from_str(raw, "%Y-%m-%d %H:%M:%S").expect("a timestamp")
}

async fn context(db: &TestDb) -> Result<Option<Ctx>> {
    let Ok(redis_url) = std::env::var("TEST_REDIS_URL") else {
        eprintln!("TEST_REDIS_URL is unset, skipping");
        return Ok(None);
    };
    let mut config = Config::from_env()?;
    config.redis_url = redis_url.clone();
    Ok(Some(Ctx {
        config: Arc::new(config),
        db: db.pool.clone(),
        redis: redis::aio::ConnectionManager::new(redis::Client::open(redis_url)?).await?,
    }))
}

/// A user in `role`, linked to it the way spatie did, with an optional pending role.
async fn user(db: &TestDb, name: &str, role: i64, pending: Option<(i64, &str)>) -> Result<u64> {
    let id = sqlx::query(
        "INSERT INTO users (username, email, password, api_token, roles_id, pending_roles_id, \
         pending_role_start_date, verified, created_at) VALUES (?, ?, 'x', ?, ?, ?, ?, 1, NOW())",
    )
    .bind(name)
    .bind(format!("{name}@example.com"))
    .bind(format!("token-{name}"))
    .bind(role)
    .bind(pending.map(|(role, _)| role))
    .bind(pending.map(|(_, starts)| at(starts)))
    .execute(&db.pool)
    .await?
    .last_insert_id();
    sqlx::query("INSERT INTO model_has_roles (role_id, model_type, model_id) VALUES (?, ?, ?)")
        .bind(role)
        .bind(USER_MODEL)
        .bind(id)
        .execute(&db.pool)
        .await?;
    Ok(id)
}

#[tokio::test]
async fn due_pending_roles_activate_with_history_and_activity() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&db).await? else {
        return Ok(());
    };
    let now = at("2026-11-01 12:00:00");
    // 1 User, 5 Friend from the seed.
    let due = user(&db, "due", 1, Some((5, "2026-11-01 00:00:00"))).await?;
    let later = user(&db, "later", 1, Some((5, "2026-12-01 00:00:00"))).await?;

    let pending = list_pending(&db.pool).await?;
    assert_eq!(pending.len(), 2);
    assert_eq!(
        (pending[0].current.as_str(), pending[0].pending.as_str()),
        ("User", "Friend")
    );

    let (activated, failed) = activate_pending(&ctx, now).await?;
    assert!(failed.is_empty());
    assert_eq!(
        activated,
        vec![Activation {
            username: "due".to_owned(),
            from: "User".to_owned(),
            to: "Friend".to_owned(),
        }]
    );
    let (role, pending_role): (i32, Option<i32>) =
        sqlx::query_as("SELECT roles_id, pending_roles_id FROM users WHERE id = ?")
            .bind(due)
            .fetch_one(&db.pool)
            .await?;
    assert_eq!((role, pending_role), (5, None));
    let links: Vec<u32> = sqlx::query_scalar(
        "SELECT role_id FROM model_has_roles WHERE model_id = ? AND model_type = ?",
    )
    .bind(due)
    .bind(USER_MODEL)
    .fetch_all(&db.pool)
    .await?;
    assert_eq!(links, vec![5]);

    let changes = history(&db.pool, due).await?;
    assert_eq!(changes.len(), 1);
    assert_eq!(
        (
            changes[0].from.as_str(),
            changes[0].to.as_str(),
            changes[0].stacked
        ),
        ("User", "Friend", true)
    );
    assert_eq!(changes[0].reason.as_deref(), Some("manual_activation"));
    assert_eq!(changes[0].changed_by, "System");

    let (description, metadata): (String, String) = sqlx::query_as(
        "SELECT description, metadata FROM user_activities \
         WHERE user_id = ? AND activity_type = 'role_updated'",
    )
    .bind(due)
    .fetch_one(&db.pool)
    .await?;
    assert_eq!(description, "User role updated: due → Friend");
    let metadata: Value = serde_json::from_str(&metadata)?;
    assert_eq!(metadata["old_role_name"], "User");
    assert_eq!(metadata["new_role_id"], 5);
    assert_eq!(metadata["updated_by"], "System");

    // The later one is untouched, and a second run finds nothing.
    assert_eq!(list_pending(&db.pool).await?.len(), 1);
    assert!(history(&db.pool, later).await?.is_empty());
    assert!(activate_pending(&ctx, now).await?.0.is_empty());
    db.drop_database().await
}

#[tokio::test]
async fn cancelling_clears_a_pending_role() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let one = user(&db, "one", 1, Some((5, "2026-12-01 00:00:00"))).await?;
    user(&db, "two", 1, Some((5, "2026-12-01 00:00:00"))).await?;
    let none = user(&db, "none", 1, None).await?;

    let found = find(&db.pool, "one").await?.expect("by username");
    assert_eq!(found.id, one);
    assert_eq!(
        find(&db.pool, &one.to_string()).await?.map(|a| a.id),
        Some(one)
    );
    assert!(find(&db.pool, "missing").await?.is_none());

    assert!(cancel_pending(&db.pool, one).await?);
    assert!(!cancel_pending(&db.pool, one).await?);
    assert!(!cancel_pending(&db.pool, none).await?);
    assert_eq!(cancel_all_pending(&db.pool).await?, vec!["two".to_owned()]);
    assert!(list_pending(&db.pool).await?.is_empty());
    db.drop_database().await
}
