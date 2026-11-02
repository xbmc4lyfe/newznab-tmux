use std::sync::Arc;

use anyhow::Result;
use chrono::NaiveDateTime;
use nntmux_admin::erasure;
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

async fn user(db: &TestDb, name: &str) -> Result<u32> {
    users::add(
        &db.pool,
        &NewUser {
            username: name.to_owned(),
            email: format!("{name}@example.com"),
            password: "Correct-Horse-1".to_owned(),
            role: None,
            notes: "private note".to_owned(),
            invites: None,
            verified: true,
        },
    )
    .await
}

async fn scalar(db: &TestDb, sql: &str, id: u32) -> Result<i64> {
    Ok(sqlx::query_scalar(sqlx::AssertSqlSafe(sql.to_owned()))
        .bind(id)
        .fetch_one(&db.pool)
        .await?)
}

/// Rows that tie an account to its data, for both erasure kinds.
async fn seed(db: &TestDb, id: u32, name: &str) -> Result<()> {
    let email = format!("{name}@example.com");
    let release = sqlx::query(
        "INSERT INTO releases (name, searchname, groups_id, guid, leftguid, size, adddate, \
         postdate) VALUES ('n', 's', 1, ?, 'r', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
    )
    .bind(format!("guid-{id}"))
    .execute(&db.pool)
    .await?
    .last_insert_id();
    sqlx::query(
        "INSERT INTO user_requests (users_id, hosthash, request, timestamp) \
         VALUES (?, '', 'q', NOW())",
    )
    .bind(id)
    .execute(&db.pool)
    .await?;
    sqlx::query(
        "INSERT INTO release_comments (releases_id, text, username, users_id, host, created_at) \
         VALUES (?, 'nice', ?, ?, '10.0.0.1', NOW())",
    )
    .bind(release)
    .bind(name)
    .bind(id)
    .execute(&db.pool)
    .await?;
    sqlx::query(
        "INSERT INTO payments (email, username, item_description, order_id, payment_id, \
         payment_status, invoice_amount, payment_method, payment_value, webhook_id, invoice_id) \
         VALUES (?, ?, 'Bronze', 'o', 'p', 'paid', '5', 'btc', '5', 'w', 'i')",
    )
    .bind(&email)
    .bind(name)
    .execute(&db.pool)
    .await?;
    sqlx::query(
        "INSERT INTO invitations (token, email, invited_by, expires_at, created_at) \
         VALUES (?, 'friend@example.com', ?, NOW() + INTERVAL 1 DAY, NOW())",
    )
    .bind(format!("unused-{id}"))
    .bind(id)
    .execute(&db.pool)
    .await?;
    sqlx::query(
        "INSERT INTO invitations (token, email, invited_by, expires_at, used_at, used_by, \
         created_at) VALUES (?, ?, 1, NOW() + INTERVAL 1 DAY, NOW(), ?, NOW())",
    )
    .bind(format!("used-{id}"))
    .bind(&email)
    .bind(id)
    .execute(&db.pool)
    .await?;
    sqlx::query(
        "INSERT INTO user_activities (user_id, username, activity_type, description, metadata) \
         VALUES (?, ?, 'login', 'Logged in', ?)",
    )
    .bind(id)
    .bind(name)
    .bind(r#"{"ip_address":"10.0.0.1","email":"x@example.com","browser":"curl"}"#)
    .execute(&db.pool)
    .await?;
    Ok(())
}

#[tokio::test]
async fn erasure_anonymizes_retained_records_and_deletes_the_rest() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&db).await? else {
        return db.drop_database().await;
    };
    let now = at("2026-11-02 12:00:00");

    // A soft erasure keeps the anonymized row and soft-deletes it.
    let soft = user(&db, "ivy").await?;
    seed(&db, soft, "ivy").await?;
    let erased = erasure::erase(&ctx, soft, false, now).await?;
    assert_eq!(erased.username, "ivy");
    let (username, email, notes, verified, deleted_by): (
        String,
        String,
        Option<String>,
        i32,
        Option<String>,
    ) = sqlx::query_as(
        "SELECT username, email, notes, CAST(verified AS SIGNED), deleted_by FROM users \
         WHERE id = ? AND deleted_at IS NOT NULL",
    )
    .bind(soft)
    .fetch_one(&db.pool)
    .await?;
    assert_eq!(username, format!("deleted_user_{soft}"));
    assert_eq!(email, format!("deleted-user-{soft}@deleted.invalid"));
    assert_eq!(notes, None);
    assert_eq!(verified, 0);
    assert_eq!(deleted_by.as_deref(), Some("System"));
    assert_eq!(
        scalar(
            &db,
            "SELECT COUNT(*) FROM user_requests WHERE users_id = ?",
            soft
        )
        .await?,
        0
    );
    let (comment_user, comment_host): (String, Option<String>) =
        sqlx::query_as("SELECT username, host FROM release_comments WHERE users_id = ?")
            .bind(soft)
            .fetch_one(&db.pool)
            .await?;
    assert_eq!(comment_user, format!("deleted_user_{soft}"));
    assert_eq!(comment_host, None);
    assert_eq!(
        scalar(
            &db,
            "SELECT COUNT(*) FROM invitations WHERE invited_by = ? AND used_at IS NULL",
            soft
        )
        .await?,
        0
    );
    assert_eq!(
        scalar(
            &db,
            "SELECT COUNT(*) FROM invitations WHERE used_by = ?",
            soft
        )
        .await?,
        0
    );
    assert_eq!(
        scalar(
            &db,
            "SELECT COUNT(*) FROM invitations WHERE email = CONCAT('deleted-user-', ?, '@deleted.invalid')",
            soft
        )
        .await?,
        1
    );
    let payment: (String, String) =
        sqlx::query_as("SELECT email, username FROM payments WHERE item_description = 'Bronze'")
            .fetch_one(&db.pool)
            .await?;
    assert_eq!(
        payment,
        (
            format!("deleted-user-{soft}@deleted.invalid"),
            format!("deleted_user_{soft}")
        )
    );
    let login: String = sqlx::query_scalar(
        "SELECT CAST(metadata AS CHAR) FROM user_activities \
         WHERE user_id = ? AND activity_type = 'login'",
    )
    .bind(soft)
    .fetch_one(&db.pool)
    .await?;
    let login: serde_json::Value = serde_json::from_str(&login)?;
    assert_eq!(
        login,
        serde_json::json!({"browser": "curl", "gdpr_minimized": true})
    );
    assert_eq!(
        scalar(
            &db,
            "SELECT COUNT(*) FROM gdpr_audit_logs WHERE user_id = ? AND event = 'erasure_soft_delete'",
            soft
        )
        .await?,
        1
    );

    // A permanent erasure removes the row and leaves only anonymous records behind.
    let gone = user(&db, "jack").await?;
    seed(&db, gone, "jack").await?;
    let account = users::require(&db.pool, "jack").await?;
    assert!(users::delete(&ctx, &account, now).await?);
    let erased = erasure::erase(&ctx, gone, true, now).await?;
    assert!(erased.permanent);
    assert_eq!(
        scalar(&db, "SELECT COUNT(*) FROM users WHERE id = ?", gone).await?,
        0
    );
    assert_eq!(
        scalar(
            &db,
            "SELECT COUNT(*) FROM user_activities WHERE username = 'jack' OR \
             (user_id = ? AND username <> CONCAT('deleted_user_', user_id))",
            gone
        )
        .await?,
        0
    );
    assert_eq!(
        scalar(
            &db,
            "SELECT COUNT(*) FROM user_activities WHERE user_id IS NULL AND is_permanent = 1 \
             AND username = CONCAT('deleted_user_', ?)",
            gone
        )
        .await?,
        1
    );
    assert_eq!(
        scalar(
            &db,
            "SELECT COUNT(*) FROM gdpr_audit_logs WHERE user_id = ? AND event = 'erasure_force_delete'",
            gone
        )
        .await?,
        1
    );
    assert_eq!(
        scalar(
            &db,
            "SELECT COUNT(*) FROM payments WHERE username = 'jack' OR ? = 0",
            gone
        )
        .await?,
        0
    );
    assert!(erasure::erase(&ctx, gone, true, now).await.is_err());

    db.drop_database().await
}
