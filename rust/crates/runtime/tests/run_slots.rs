use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};

use anyhow::Result;
use nntmux_core::Config;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown, run_slots};
use nntmux_testkit::TestDb;

async fn context(db: &TestDb) -> Result<Option<Ctx>> {
    let Ok(redis_url) = std::env::var("TEST_REDIS_URL") else {
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

fn args(once: bool) -> RoleArgs {
    RoleArgs {
        concurrency: 1,
        idle_seconds: 0,
        once,
    }
}

#[tokio::test]
async fn a_panicking_unit_keeps_the_slot_alive_and_fails_a_one_shot_pass() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&db).await? else {
        return Ok(());
    };

    let calls = Arc::new(AtomicUsize::new(0));
    let stop = Shutdown::default();
    let (counter, stopper) = (calls.clone(), stop.clone());
    run_slots(ctx.clone(), "test", args(false), stop, move |_, _| {
        let (counter, stopper) = (counter.clone(), stopper.clone());
        async move {
            if counter.fetch_add(1, Ordering::SeqCst) == 0 {
                panic!("bad unit");
            }
            stopper.request();
            Ok(true)
        }
    })
    .await?;
    assert_eq!(calls.load(Ordering::SeqCst), 2);

    let failed = run_slots(ctx, "test", args(true), Shutdown::default(), |_, _| async {
        panic!("bad unit");
    })
    .await;
    assert!(failed.is_err());
    db.drop_database().await
}

#[tokio::test]
async fn a_one_shot_pass_leaves_when_processing_is_paused() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&db).await? else {
        return Ok(());
    };
    sqlx::query("UPDATE processing_runtime_states SET stop_requested = 1 WHERE id = 1")
        .execute(&db.pool)
        .await?;

    let calls = Arc::new(AtomicUsize::new(0));
    let counter = calls.clone();
    run_slots(ctx, "test", args(true), Shutdown::default(), move |_, _| {
        let counter = counter.clone();
        async move {
            counter.fetch_add(1, Ordering::SeqCst);
            Ok(false)
        }
    })
    .await?;
    assert_eq!(calls.load(Ordering::SeqCst), 0);
    db.drop_database().await
}
