use std::sync::Arc;

use anyhow::Result;
use chrono::{NaiveDateTime, Utc};
use nntmux_admin::accounts::{
    Activity, delete_unverified, inactive, purge_deleted, remove_inactive,
};
use nntmux_admin::gdpr::purge_expired_exports;
use nntmux_core::Config;
use nntmux_runtime::Ctx;
use nntmux_testkit::TestDb;
use redis::AsyncCommands;
use serde_json::Value;

fn at(raw: &str) -> NaiveDateTime {
    NaiveDateTime::parse_from_str(raw, "%Y-%m-%d %H:%M:%S").expect("a timestamp")
}

/// Every combination `fixtures/inactivity.php` ran through the PHP evaluator.
#[test]
fn inactivity_matches_the_php_evaluator() {
    let golden: Value =
        serde_json::from_str(include_str!("fixtures/inactivity.json")).expect("valid fixture");
    let threshold = at(golden["threshold"].as_str().expect("threshold"));
    let time = |label: &Value| {
        golden["times"][label.as_str().expect("a label")]
            .as_str()
            .map(at)
    };
    let cases = golden["cases"].as_array().expect("cases");
    assert_eq!(cases.len(), 2048);
    for case in cases {
        let user = Activity {
            created_at: time(&case[0]),
            updated_at: time(&case[1]),
            lastlogin: time(&case[2]),
            apiaccess: time(&case[3]),
            lastdownload: time(&case[4]),
            grabs: case[5].as_i64().expect("grabs"),
        };
        let expected = case[6].as_bool().expect("purge");
        assert_eq!(inactive(&user, threshold), expected, "{case}");
    }
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

struct NewUser<'a> {
    name: &'a str,
    role: i64,
    verified: bool,
    created: &'a str,
    login: Option<&'a str>,
    deleted: Option<&'a str>,
}

async fn user(db: &TestDb, new: NewUser<'_>) -> Result<i64> {
    let id = sqlx::query(
        "INSERT INTO users (username, email, password, api_token, roles_id, verified, \
         email_verified_at, created_at, updated_at, lastlogin, apiaccess, deleted_at) \
         VALUES (?, ?, 'x', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(new.name)
    .bind(format!("{}@example.com", new.name))
    .bind(format!("token-{}", new.name))
    .bind(new.role)
    .bind(new.verified)
    .bind(new.verified.then(|| at(new.created)))
    .bind(at(new.created))
    .bind(at(new.created))
    .bind(new.login.map(at))
    .bind(new.login.map(at))
    .bind(new.deleted.map(at))
    .execute(&db.pool)
    .await?
    .last_insert_id();
    Ok(i64::try_from(id)?)
}

async fn deleted_at(db: &TestDb, id: i64) -> Result<Option<Option<NaiveDateTime>>> {
    Ok(
        sqlx::query_scalar("SELECT CAST(deleted_at AS DATETIME) FROM users WHERE id = ?")
            .bind(id)
            .fetch_optional(&db.pool)
            .await?,
    )
}

#[tokio::test]
async fn unverified_accounts_go_after_three_days() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let now = at("2026-11-01 12:00:00");
    let old = |name| NewUser {
        name,
        role: 1,
        verified: false,
        created: "2026-10-28 11:00:00",
        login: None,
        deleted: None,
    };
    let stale = user(&db, old("stale")).await?;
    let recent = user(
        &db,
        NewUser {
            created: "2026-10-29 13:00:00",
            ..old("recent")
        },
    )
    .await?;
    let verified = user(
        &db,
        NewUser {
            verified: true,
            ..old("verified")
        },
    )
    .await?;
    // Friend isn't one of the cleanup roles.
    let friend = user(
        &db,
        NewUser {
            role: 5,
            ..old("friend")
        },
    )
    .await?;

    assert_eq!(delete_unverified(&db.pool, now).await?, 1);
    assert_eq!(deleted_at(&db, stale).await?, Some(Some(now)));
    for kept in [recent, verified, friend] {
        assert_eq!(deleted_at(&db, kept).await?, Some(None));
    }
    // Already deleted: nothing changes on the next run.
    assert_eq!(delete_unverified(&db.pool, now).await?, 0);
    db.drop_database().await
}

#[tokio::test]
async fn inactive_users_are_soft_deleted_and_logged() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&db).await? else {
        return Ok(());
    };
    let now = Utc::now().naive_utc();
    let base = NewUser {
        name: "",
        role: 1,
        verified: true,
        created: "2024-01-01 00:00:00",
        login: None,
        deleted: None,
    };
    let idle = user(
        &db,
        NewUser {
            name: "idle",
            ..base
        },
    )
    .await?;
    let active = user(
        &db,
        NewUser {
            name: "active",
            login: Some(&now.format("%Y-%m-%d %H:%M:%S").to_string()),
            ..base
        },
    )
    .await?;
    let admin = user(
        &db,
        NewUser {
            name: "admin",
            role: 2,
            ..base
        },
    )
    .await?;
    let mut redis = ctx.redis.clone();
    let stats_key = format!("api_user_stats:{idle}");
    redis.set::<_, _, ()>(&stats_key, "cached").await?;

    assert_eq!(remove_inactive(&ctx, now, 180).await?, 1);
    assert!(matches!(deleted_at(&db, idle).await?, Some(Some(_))));
    assert_eq!(deleted_at(&db, active).await?, Some(None));
    assert_eq!(deleted_at(&db, admin).await?, Some(None));
    let by: Option<String> = sqlx::query_scalar("SELECT deleted_by FROM users WHERE id = ?")
        .bind(idle)
        .fetch_one(&db.pool)
        .await?;
    assert_eq!(by.as_deref(), Some("System"));
    let (description, metadata, permanent): (String, String, bool) = sqlx::query_as(
        "SELECT description, metadata, is_permanent FROM user_activities WHERE user_id = ?",
    )
    .bind(idle)
    .fetch_one(&db.pool)
    .await?;
    assert_eq!(description, "User deleted: idle");
    let metadata: Value = serde_json::from_str(&metadata)?;
    assert_eq!(metadata["deleted_by"], "System");
    assert_eq!(metadata["email"], "idle@example.com");
    assert!(!permanent);
    assert!(!redis.exists::<_, bool>(&stats_key).await?);
    db.drop_database().await
}

#[tokio::test]
async fn purging_removes_old_soft_deletes_and_their_role_links() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&db).await? else {
        return Ok(());
    };
    let now = at("2026-11-01 12:00:00");
    let base = NewUser {
        name: "",
        role: 1,
        verified: true,
        created: "2024-01-01 00:00:00",
        login: None,
        deleted: None,
    };
    let gone = user(
        &db,
        NewUser {
            name: "gone",
            deleted: Some("2026-01-01 00:00:00"),
            ..base
        },
    )
    .await?;
    let recent = user(
        &db,
        NewUser {
            name: "recent",
            deleted: Some("2026-10-01 00:00:00"),
            ..base
        },
    )
    .await?;
    let live = user(
        &db,
        NewUser {
            name: "live",
            ..base
        },
    )
    .await?;
    for id in [gone, live] {
        sqlx::query("INSERT INTO model_has_roles (role_id, model_type, model_id) VALUES (1, ?, ?)")
            .bind(r"App\Models\User")
            .bind(id)
            .execute(&db.pool)
            .await?;
    }

    assert_eq!(purge_deleted(&ctx, now, 180).await?, 1);
    assert_eq!(deleted_at(&db, gone).await?, None);
    assert!(matches!(deleted_at(&db, recent).await?, Some(Some(_))));
    assert_eq!(deleted_at(&db, live).await?, Some(None));
    let links: Vec<u64> = sqlx::query_scalar("SELECT model_id FROM model_has_roles")
        .fetch_all(&db.pool)
        .await?;
    assert_eq!(links, vec![u64::try_from(live)?]);
    let rows: Vec<(Option<u64>, String, bool)> =
        sqlx::query_as("SELECT user_id, description, is_permanent FROM user_activities")
            .fetch_all(&db.pool)
            .await?;
    assert_eq!(
        rows,
        vec![(None, "User permanently deleted: gone".to_owned(), true)]
    );
    db.drop_database().await
}

#[tokio::test]
async fn expired_exports_lose_their_file_but_keep_the_request() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let storage = std::env::temp_dir().join(format!("gdpr-{}", db.name));
    let exports = storage.join("app/private/gdpr/exports");
    tokio::fs::create_dir_all(&exports).await?;
    let now = at("2026-11-01 12:00:00");
    let mut ids = Vec::new();
    for (name, expires) in [
        ("expired.json", "2026-10-31 00:00:00"),
        ("missing.json", "2026-10-31 00:00:00"),
        ("current.json", "2026-11-05 00:00:00"),
    ] {
        if name != "missing.json" {
            tokio::fs::write(exports.join(name), "{}").await?;
        }
        let id = sqlx::query(
            "INSERT INTO gdpr_requests (type, status, export_disk, export_path, export_expires_at) \
             VALUES ('export', 'completed', 'local', ?, ?)",
        )
        .bind(format!("gdpr/exports/{name}"))
        .bind(at(expires))
        .execute(&db.pool)
        .await?
        .last_insert_id();
        ids.push(id);
    }

    assert_eq!(purge_expired_exports(&db.pool, &storage, now).await?, 2);
    assert!(!exports.join("expired.json").exists());
    assert!(exports.join("current.json").exists());
    let paths: Vec<(u64, Option<String>)> =
        sqlx::query_as("SELECT id, export_path FROM gdpr_requests ORDER BY id")
            .fetch_all(&db.pool)
            .await?;
    assert_eq!(
        paths,
        vec![
            (ids[0], None),
            (ids[1], None),
            (ids[2], Some("gdpr/exports/current.json".to_owned())),
        ]
    );
    tokio::fs::remove_dir_all(&storage).await?;
    db.drop_database().await
}
