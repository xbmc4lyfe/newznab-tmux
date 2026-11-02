use std::sync::Arc;

use anyhow::Result;
use chrono::{Duration, NaiveDate, NaiveDateTime};
use nntmux_admin::user_role::{self, Outcome, Request};
use nntmux_admin::users::{self, NewUser};
use nntmux_core::Config;
use nntmux_runtime::Ctx;
use nntmux_testkit::TestDb;

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

async fn role(db: &TestDb, name: &str, rate_limit: i32) -> Result<u32> {
    let id = sqlx::query(
        "INSERT INTO roles (name, guard_name, apirequests, rate_limit, downloadrequests, \
         defaultinvites, isdefault, donation, addyears, created_at, updated_at) \
         VALUES (?, 'web', 100, ?, 100, 1, 0, 0, 1, NOW(), NOW())",
    )
    .bind(name)
    .bind(rate_limit)
    .execute(&db.pool)
    .await?
    .last_insert_id();
    Ok(u32::try_from(id)?)
}

#[derive(Debug, sqlx::FromRow)]
struct State {
    roles_id: i32,
    rolechangedate: Option<NaiveDateTime>,
    pending_roles_id: Option<i32>,
    pending_role_start_date: Option<NaiveDateTime>,
    rate_limit: i32,
}

async fn state(db: &TestDb, id: u32) -> Result<State> {
    Ok(sqlx::query_as(
        "SELECT roles_id, rolechangedate, pending_roles_id, pending_role_start_date, rate_limit \
         FROM users WHERE id = ?",
    )
    .bind(id)
    .fetch_one(&db.pool)
    .await?)
}

async fn count(db: &TestDb, sql: &str, id: u32) -> Result<i64> {
    Ok(sqlx::query_scalar(sqlx::AssertSqlSafe(sql.to_owned()))
        .bind(id)
        .fetch_one(&db.pool)
        .await?)
}

#[tokio::test]
async fn roles_change_now_stack_and_keep_a_set_expiry() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&db).await? else {
        return db.drop_database().await;
    };
    let now = at("2026-11-02 12:00:00");
    let bronze = role(&db, "Bronze", 120).await?;
    let silver = role(&db, "Silver", 240).await?;
    sqlx::query(
        "INSERT INTO role_promotions (name, description, applicable_roles, additional_days, \
         start_date, end_date, is_active, created_at, updated_at) \
         VALUES ('Launch', '', ?, 30, NULL, NULL, 1, NOW(), NOW())",
    )
    .bind(format!("[{bronze}]"))
    .execute(&db.pool)
    .await?;
    let id = users::add(
        &db.pool,
        &NewUser {
            username: "henry".to_owned(),
            email: "henry@example.com".to_owned(),
            password: "Correct-Horse-1".to_owned(),
            role: None,
            notes: String::new(),
            invites: None,
            verified: true,
        },
    )
    .await?;
    let change = |role: Option<&str>, stack: bool| Request {
        role: role.map(str::to_owned),
        stack,
        ..Request::default()
    };

    // Now: a year plus the 30-day promotion, the role's rate limit, synced role links,
    // history, activity and the promotion's statistics.
    let account = users::require(&db.pool, "henry").await?;
    let outcome = user_role::change(&ctx, &account, &change(Some("Bronze"), true), now).await?;
    let expires = now + Duration::days(395);
    assert_eq!(
        outcome,
        Outcome::Immediate {
            expires: Some(expires)
        }
    );
    let after = state(&db, id).await?;
    assert_eq!(after.roles_id, i32::try_from(bronze)?);
    assert_eq!(after.rolechangedate, Some(expires));
    assert_eq!(after.rate_limit, 120);
    assert_eq!(
        count(
            &db,
            "SELECT COUNT(*) FROM model_has_roles WHERE model_id = ? AND role_id = (SELECT id FROM roles WHERE name = 'Bronze')",
            id
        )
        .await?,
        1
    );
    assert_eq!(
        count(
            &db,
            "SELECT COUNT(*) FROM model_has_roles WHERE model_id = ?",
            id
        )
        .await?,
        1
    );
    assert_eq!(
        count(
            &db,
            "SELECT COUNT(*) FROM user_role_history WHERE user_id = ? AND change_reason = 'immediate_role_change'",
            id
        )
        .await?,
        1
    );
    assert_eq!(
        count(
            &db,
            "SELECT COUNT(*) FROM user_activities WHERE user_id = ? AND activity_type = 'role_updated'",
            id
        )
        .await?,
        1
    );
    assert_eq!(
        count(
            &db,
            "SELECT COUNT(*) FROM role_promotion_stats WHERE user_id = ? AND days_added = 30",
            id
        )
        .await?,
        1
    );

    // The same role again changes nothing.
    let account = users::require(&db.pool, "henry").await?;
    assert_eq!(
        user_role::change(&ctx, &account, &change(Some("Bronze"), true), now).await?,
        Outcome::Unchanged
    );

    // Stacked: Silver waits for Bronze to end and runs a year from there.
    let outcome = user_role::change(&ctx, &account, &change(Some("Silver"), true), now).await?;
    assert_eq!(
        outcome,
        Outcome::Stacked {
            starts: expires,
            expires: expires + Duration::days(365)
        }
    );
    let stacked = state(&db, id).await?;
    assert_eq!(stacked.roles_id, i32::try_from(bronze)?);
    assert_eq!(stacked.pending_roles_id, Some(i32::try_from(silver)?));
    assert_eq!(stacked.pending_role_start_date, Some(expires));

    // Cancelling the queued role and setting an expiry by hand keeps that expiry and skips
    // promotions on the next change.
    let account = users::require(&db.pool, "henry").await?;
    let set = NaiveDate::from_ymd_opt(2027, 6, 1).expect("a date");
    let outcome = user_role::change(
        &ctx,
        &account,
        &Request {
            role: Some(silver.to_string()),
            stack: false,
            expires: Some(Some(set)),
            cancel_pending: true,
        },
        now,
    )
    .await?;
    let set_at = set.and_hms_opt(0, 0, 0);
    assert_eq!(outcome, Outcome::Immediate { expires: set_at });
    let manual = state(&db, id).await?;
    assert_eq!(manual.roles_id, i32::try_from(silver)?);
    assert_eq!(manual.rolechangedate, set_at);
    assert_eq!(manual.pending_roles_id, None);
    assert_eq!(manual.rate_limit, 240);
    assert_eq!(
        count(
            &db,
            "SELECT COUNT(*) FROM role_promotion_stats WHERE user_id = ?",
            id
        )
        .await?,
        1
    );

    // Clearing the expiry alone leaves the role.
    let account = users::require(&db.pool, "henry").await?;
    assert_eq!(
        user_role::change(
            &ctx,
            &account,
            &Request {
                expires: Some(None),
                ..Request::default()
            },
            now
        )
        .await?,
        Outcome::Unchanged
    );
    assert_eq!(state(&db, id).await?.rolechangedate, None);
    assert!(
        user_role::change(&ctx, &account, &Request::default(), now)
            .await
            .is_err()
    );
    assert!(
        user_role::change(&ctx, &account, &change(Some("Nonexistent"), false), now)
            .await
            .is_err()
    );

    db.drop_database().await
}
