//! `identify::unidentifiable` against a real database.

use nntmux_namefix::identify::unidentifiable::{Plan, apply, plan};
use nntmux_testkit::TestDb;
use sqlx::{AssertSqlSafe, MySqlPool};

/// A release row with only the columns this reads set, and the rest at whatever fits.
async fn release(pool: &MySqlPool, id: i64, status: i64, renamed: i64, predb: i64) {
    let columns: Vec<(String, String)> = sqlx::query_as(
        "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'releases'
           AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'",
    )
    .fetch_all(pool)
    .await
    .expect("columns");
    let mut names = vec![
        "id".to_owned(),
        "passwordstatus".to_owned(),
        "isrenamed".to_owned(),
        "predb_id".to_owned(),
    ];
    let mut values = vec![
        id.to_string(),
        status.to_string(),
        renamed.to_string(),
        predb.to_string(),
    ];
    for (name, kind) in columns {
        if names.contains(&name) {
            continue;
        }
        let value = if name == "guid" || name == "leftguid" {
            format!("'{id:040x}'")
        } else if kind.contains("int") || kind.contains("decimal") {
            "0".to_owned()
        } else if kind.contains("time") || kind.contains("date") {
            "NOW()".to_owned()
        } else {
            "''".to_owned()
        };
        names.push(name);
        values.push(value);
    }
    let sql = format!(
        "INSERT INTO releases ({}) VALUES ({})",
        names.join(", "),
        values.join(", ")
    );
    sqlx::query(AssertSqlSafe(sql))
        .execute(pool)
        .await
        .expect("release");
}

async fn status(pool: &MySqlPool, id: i64) -> i64 {
    sqlx::query_scalar("SELECT CAST(passwordstatus AS SIGNED) FROM releases WHERE id = ?")
        .bind(id)
        .fetch_one(pool)
        .await
        .expect("status")
}

async fn queued(pool: &MySqlPool) -> Vec<(i64, String)> {
    sqlx::query_as(
        "SELECT CAST(release_id AS SIGNED), operation FROM search_index_failures ORDER BY release_id",
    )
    .fetch_all(pool)
    .await
    .expect("queue")
}

#[tokio::test]
async fn a_plan_writes_nothing_and_apply_only_touches_what_nothing_has_named() {
    let Some(db) = TestDb::create().await.expect("database") else {
        return;
    };
    release(&db.pool, 1, -1, 0, 0).await;
    release(&db.pool, 2, 0, 0, 0).await;
    release(&db.pool, 3, 1, 0, 0).await;
    release(&db.pool, 4, 0, 1, 0).await; // Renamed since.
    release(&db.pool, 5, 0, 0, 77).await; // Linked to PreDB since.
    release(&db.pool, 6, 3, 0, 0).await; // Already hidden.
    let ids = [1, 2, 3, 4, 5, 6, 99];

    let found = plan(&db.pool, &ids).await.expect("plan");
    let mut eligible = found.eligible.clone();
    eligible.sort_unstable();
    assert_eq!(
        Plan {
            eligible,
            ..found.clone()
        },
        Plan {
            eligible: vec![1, 2, 3],
            already_hidden: 1,
            named: 2,
            missing: 1,
        }
    );
    assert_eq!(status(&db.pool, 1).await, -1, "a plan changes nothing");
    assert!(queued(&db.pool).await.is_empty(), "a plan queues nothing");

    assert_eq!(apply(&db.pool, &ids).await.expect("apply"), 3);
    for id in [1, 2, 3, 6] {
        assert_eq!(status(&db.pool, id).await, 3, "release {id}");
    }
    assert_eq!(
        status(&db.pool, 4).await,
        0,
        "a renamed release is left alone"
    );
    assert_eq!(
        status(&db.pool, 5).await,
        0,
        "a linked release is left alone"
    );

    // Search hears about exactly the releases that changed.
    let upsert = |id: i64| (id, "upsert".to_owned());
    assert_eq!(queued(&db.pool).await, [upsert(1), upsert(2), upsert(3)]);

    // Running it again changes nothing.
    assert_eq!(apply(&db.pool, &ids).await.expect("apply"), 0);
}

#[tokio::test]
async fn a_release_named_after_the_plan_is_left_alone() {
    let Some(db) = TestDb::create().await.expect("database") else {
        return;
    };
    release(&db.pool, 1, 0, 0, 0).await;
    let found = plan(&db.pool, &[1]).await.expect("plan");
    sqlx::query("UPDATE releases SET isrenamed = 1 WHERE id = 1")
        .execute(&db.pool)
        .await
        .expect("rename");
    assert_eq!(apply(&db.pool, &found.eligible).await.expect("apply"), 0);
    assert_eq!(status(&db.pool, 1).await, 0);
    assert!(queued(&db.pool).await.is_empty());
}
