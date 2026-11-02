use std::sync::Arc;

use anyhow::Result;
use chrono::{NaiveDate, NaiveDateTime};
use nntmux_admin::users::{self, Changes, Filter, NewUser};
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

fn new_user(name: &str) -> NewUser {
    NewUser {
        username: name.to_owned(),
        email: format!("{name}@example.com"),
        password: "Correct-Horse-1".to_owned(),
        role: None,
        notes: "added by test".to_owned(),
        invites: None,
        verified: false,
    }
}

fn filter() -> Filter {
    Filter {
        limit: 100,
        ..Filter::default()
    }
}

async fn activities(db: &TestDb, user: u32) -> Result<Vec<(String, String)>> {
    Ok(sqlx::query_as(
        "SELECT activity_type, description FROM user_activities WHERE user_id = ? ORDER BY id",
    )
    .bind(user)
    .fetch_all(&db.pool)
    .await?)
}

#[tokio::test]
async fn accounts_are_added_listed_and_edited() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let now = at("2026-11-02 12:00:00");
    let alice = users::add(&db.pool, &new_user("alice")).await?;
    let bob = users::add(
        &db.pool,
        &NewUser {
            verified: true,
            invites: Some(7),
            ..new_user("bob")
        },
    )
    .await?;

    // Names and addresses another live account uses are refused.
    assert!(users::add(&db.pool, &new_user("alice")).await.is_err());
    assert!(
        users::add(
            &db.pool,
            &NewUser {
                email: "bob@example.com".to_owned(),
                ..new_user("carol")
            }
        )
        .await
        .is_err()
    );
    assert!(
        users::add(
            &db.pool,
            &NewUser {
                password: "short".to_owned(),
                ..new_user("dave")
            }
        )
        .await
        .is_err()
    );

    let account = users::require(&db.pool, "alice").await?;
    assert_eq!(account.id, alice);
    assert!(!account.is_verified());
    assert_eq!(account.notes.as_deref(), Some("added by test"));
    assert_eq!(
        activities(&db, alice).await?,
        [(
            "registered".to_owned(),
            "New user registered: alice".to_owned()
        )]
    );
    let bob_account = users::require(&db.pool, &bob.to_string()).await?;
    assert!(bob_account.is_verified());
    assert_eq!(bob_account.invites, 7);

    let verified = users::list(
        &db.pool,
        &Filter {
            verified: Some(true),
            username: Some("o".to_owned()),
            ..filter()
        },
        now,
    )
    .await?;
    assert_eq!(
        verified.iter().map(|user| user.id).collect::<Vec<_>>(),
        [bob]
    );
    let ordered = users::list(
        &db.pool,
        &Filter {
            ordering: "username_asc".to_owned(),
            created_from: Some(NaiveDate::from_ymd_opt(2000, 1, 1).expect("a date")),
            ..filter()
        },
        now,
    )
    .await?;
    let names: Vec<_> = ordered.iter().map(|user| user.username.as_str()).collect();
    assert!(names.windows(2).all(|pair| pair[0] <= pair[1]), "{names:?}");
    // A literal underscore doesn't match any character.
    let literal = users::list(
        &db.pool,
        &Filter {
            username: Some("a_ice".to_owned()),
            ..filter()
        },
        now,
    )
    .await?;
    assert!(literal.is_empty());

    users::update(
        &db.pool,
        &account,
        &Changes {
            username: Some("alicia".to_owned()),
            notes: Some("renamed".to_owned()),
            movieview: Some(false),
            password: Some("Another-Pass-2".to_owned()),
            ..Changes::default()
        },
    )
    .await?;
    let renamed = users::require(&db.pool, "alicia").await?;
    assert_eq!(renamed.notes.as_deref(), Some("renamed"));
    assert_eq!(renamed.movieview, 0);
    assert_eq!(renamed.musicview, account.musicview);
    let hash: String = sqlx::query_scalar("SELECT password FROM users WHERE id = ?")
        .bind(alice)
        .fetch_one(&db.pool)
        .await?;
    assert!(bcrypt::verify("Another-Pass-2", &hash)?);
    assert!(
        users::update(
            &db.pool,
            &renamed,
            &Changes {
                email: Some("bob@example.com".to_owned()),
                ..Changes::default()
            }
        )
        .await
        .is_err()
    );
    assert!(
        users::update(&db.pool, &renamed, &Changes::default())
            .await
            .is_err()
    );

    db.drop_database().await
}

#[tokio::test]
async fn accounts_are_deleted_restored_and_verified() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&db).await? else {
        return db.drop_database().await;
    };
    let now = at("2026-11-02 12:00:00");
    let erin = users::add(&db.pool, &new_user("erin")).await?;
    let account = users::require(&db.pool, "erin").await?;

    assert!(users::verify(&ctx, &account).await?);
    let account = users::require(&db.pool, "erin").await?;
    assert!(account.is_verified());
    assert!(!users::verify(&ctx, &account).await?);

    assert!(users::delete(&ctx, &account, now).await?);
    assert!(!users::delete(&ctx, &account, now).await?);
    let deleted = users::require(&db.pool, "erin").await?;
    assert_eq!(deleted.deleted_at, Some(now));
    assert_eq!(deleted.deleted_by.as_deref(), Some(users::ACTOR));
    // A deleted account can't change until it's restored.
    assert!(
        users::update(
            &db.pool,
            &deleted,
            &Changes {
                notes: Some("x".to_owned()),
                ..Changes::default()
            }
        )
        .await
        .is_err()
    );
    let only_deleted = users::list(
        &db.pool,
        &Filter {
            deleted_only: true,
            ..filter()
        },
        now,
    )
    .await?;
    assert_eq!(
        only_deleted.iter().map(|user| user.id).collect::<Vec<_>>(),
        [erin]
    );

    assert!(users::restore(&ctx, &deleted, now).await?);
    let restored = users::require(&db.pool, "erin").await?;
    assert_eq!(restored.deleted_at, None);
    assert_eq!(restored.deleted_by, None);
    assert_eq!(
        activities(&db, erin)
            .await?
            .into_iter()
            .map(|(_, description)| description)
            .collect::<Vec<_>>(),
        [
            "New user registered: erin",
            "User deleted: erin",
            "User restored: erin"
        ]
    );

    // Restoring is refused while another live account holds the name.
    assert!(users::delete(&ctx, &restored, now).await?);
    let taken = users::add(&db.pool, &new_user("erin")).await?;
    let erin_deleted = users::require(&db.pool, &format!("id:{erin}")).await?;
    assert!(users::restore(&ctx, &erin_deleted, now).await.is_err());
    assert_eq!(users::require(&db.pool, "erin").await?.id, taken);
    let restored = users::require(&db.pool, &taken.to_string()).await?;

    // A number that is one account's id and another's name is ambiguous.
    let numeric = users::add(&db.pool, &new_user(&taken.to_string())).await?;
    assert!(users::find(&db.pool, &taken.to_string()).await.is_err());
    assert_eq!(
        users::require(&db.pool, &format!("name:{taken}")).await?.id,
        numeric
    );

    // Admins are left alone unless asked.
    let admin_role: u32 = sqlx::query_scalar("SELECT id FROM roles WHERE name = 'Admin'")
        .fetch_one(&db.pool)
        .await?;
    let admin = users::add(
        &db.pool,
        &NewUser {
            role: Some(i32::try_from(admin_role)?),
            ..new_user("frank")
        },
    )
    .await?;
    let admin = users::require(&db.pool, &admin.to_string()).await?;
    assert!(users::guard_admin(&db.pool, &admin, false).await.is_err());
    users::guard_admin(&db.pool, &admin, true).await?;
    users::guard_admin(&db.pool, &restored, false).await?;

    db.drop_database().await
}

#[tokio::test]
async fn passkeys_are_listed_deleted_and_wiped() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let gina = users::add(&db.pool, &new_user("gina")).await?;
    for name in ["laptop", "phone", "key"] {
        sqlx::query(
            "INSERT INTO passkeys (authenticatable_id, name, credential_id, data, created_at) \
             VALUES (?, ?, ?, '{}', NOW())",
        )
        .bind(gina)
        .bind(name)
        .bind(format!("cred-{name}"))
        .execute(&db.pool)
        .await?;
    }
    let keys = users::passkeys(&db.pool, gina).await?;
    assert_eq!(keys.len(), 3);
    let first = users::passkey(&db.pool, keys[0].id)
        .await?
        .expect("the passkey");
    assert!(users::delete_passkey(&db.pool, &first).await?);
    assert_eq!(users::passkeys(&db.pool, gina).await?.len(), 2);
    let account = users::require(&db.pool, "gina").await?;
    assert_eq!(users::wipe_passkeys(&db.pool, &account).await?, 2);
    assert!(users::passkeys(&db.pool, gina).await?.is_empty());

    db.drop_database().await
}
