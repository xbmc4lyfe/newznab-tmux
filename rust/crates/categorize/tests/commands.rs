//! The OnlyFans repair against a real database.

use nntmux_categorize::cat;
use nntmux_categorize::commands::repair_onlyfans;
use nntmux_testkit::TestDb;
use sqlx::MySqlPool;

async fn insert(pool: &MySqlPool, id: i64, searchname: &str, category: i32) {
    let columns: Vec<(String, String)> = sqlx::query_as(
        "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() \
         AND TABLE_NAME = 'releases' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'",
    )
    .fetch_all(pool)
    .await
    .expect("columns");
    let mut names = vec![
        "id".to_owned(),
        "searchname".to_owned(),
        "categories_id".to_owned(),
    ];
    let mut values = vec![
        id.to_string(),
        format!("'{searchname}'"),
        category.to_string(),
    ];
    for (column, kind) in columns {
        if names.contains(&column) {
            continue;
        }
        names.push(format!("`{column}`"));
        values.push(if kind.contains("int") {
            "0".to_owned()
        } else if kind.contains("date") || kind.contains("time") {
            "NOW()".to_owned()
        } else {
            format!("'{id}'")
        });
    }
    sqlx::query(sqlx::AssertSqlSafe(format!(
        "INSERT INTO releases ({}) VALUES ({})",
        names.join(", "),
        values.join(", ")
    )))
    .execute(pool)
    .await
    .expect("release");
}

async fn category(pool: &MySqlPool, id: i64) -> i32 {
    sqlx::query_scalar("SELECT categories_id FROM releases WHERE id = ?")
        .bind(id)
        .fetch_one(pool)
        .await
        .expect("row")
}

#[tokio::test]
async fn repairs_only_of_releases_in_xxx_categories() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    insert(pool, 1, "OF.Creator.Video.1080p.mp4", cat::XXX_X264).await;
    insert(pool, 2, "OF.Creator_Photosets.1080p", cat::XXX_X264).await;
    insert(pool, 3, "OF.Creator.Video.2.mp4", cat::OTHER_MISC).await;
    insert(pool, 4, "Other.Title.XXX.1080p", cat::XXX_X264).await;

    let preview = repair_onlyfans(pool, false, |_| async { true })
        .await
        .expect("preview");
    assert_eq!(
        (
            preview.inspected,
            preview.eligible,
            preview.updated,
            preview.skipped
        ),
        (2, 1, 0, 1)
    );
    assert_eq!(category(pool, 1).await, cat::XXX_X264);

    let applied = repair_onlyfans(pool, true, |_| async { true })
        .await
        .expect("apply");
    assert_eq!(
        (applied.updated, applied.refreshed, applied.failed),
        (1, 1, 0)
    );
    assert_eq!(category(pool, 1).await, cat::XXX_ONLYFANS);
    assert_eq!(
        category(pool, 2).await,
        cat::XXX_X264,
        "photo packs without a video hint stay"
    );
    assert_eq!(category(pool, 3).await, cat::OTHER_MISC);
    assert_eq!(category(pool, 4).await, cat::XXX_X264);

    // A failed refresh counts against the run, with the category saved.
    insert(pool, 5, "OF.Creator.Video.3.mp4", cat::XXX_X264).await;
    let failed = repair_onlyfans(pool, true, |_| async { false })
        .await
        .expect("apply");
    assert_eq!((failed.updated, failed.failed), (1, 1));
    assert_eq!(category(pool, 5).await, cat::XXX_ONLYFANS);
    db.drop_database().await.expect("drop");
}
