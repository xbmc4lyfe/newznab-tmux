//! `nntmux:find-size-mismatches` against a real database.

use std::sync::Arc;

use nntmux_categorize::{Categorizer, cat};
use nntmux_namefix::size_mismatch::{self, Args};
use nntmux_namefix::update::{NoSearch, ReleaseUpdater};
use nntmux_predb::filename::fixer_cleaner;
use nntmux_testkit::TestDb;
use sqlx::MySqlPool;

const GIB: i64 = 1024 * 1024 * 1024;

async fn insert(pool: &MySqlPool, id: i64, name: &str, searchname: &str, size: i64, files: i64) {
    let columns: Vec<(String, String)> = sqlx::query_as(
        "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() \
         AND TABLE_NAME = 'releases' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'",
    )
    .fetch_all(pool)
    .await
    .expect("columns");
    let mut names = vec!["id", "name", "searchname", "size", "categories_id"]
        .into_iter()
        .map(str::to_owned)
        .collect::<Vec<_>>();
    let mut values = vec![
        id.to_string(),
        format!("'{name}'"),
        format!("'{searchname}'"),
        size.to_string(),
        cat::OTHER_MISC.to_string(),
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
    sqlx::query(
        "INSERT INTO release_files (releases_id, name, size, created_at, updated_at, passworded, crc32) \
         VALUES (?, 'file.mkv', ?, NOW(), NOW(), 0, '')",
    )
    .bind(id)
    .bind(files)
    .execute(pool)
    .await
    .expect("file");
}

fn args(direction: &str, season_pack: bool, rename: bool) -> Args {
    Args {
        threshold: 20.0,
        limit: 100,
        season_pack,
        direction: direction.to_owned(),
        rename,
    }
}

fn ids(found: &[size_mismatch::Mismatch]) -> Vec<i64> {
    found.iter().map(|mismatch| mismatch.id).collect()
}

#[tokio::test]
async fn finds_by_direction_and_renames_season_packs() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    let pack = "Show.Name.S01.1080p.WEB-DL.x264-GRP";
    // Bigger than its files, by 50%.
    insert(pool, 1, pack, "Show.Name.S01E01.1080p", 10 * GIB, 5 * GIB).await;
    // Smaller than its files, by 100%, and not a pack.
    insert(
        pool,
        2,
        "Other.S02E03.720p-GRP",
        "Other.S02E03.720p",
        5 * GIB,
        10 * GIB,
    )
    .await;
    // Within the threshold.
    insert(
        pool,
        3,
        "Close.S01E01.720p-GRP",
        "Close.S01E01.720p",
        10 * GIB,
        9 * GIB,
    )
    .await;
    // Not named after an episode.
    insert(
        pool,
        4,
        "Film.2020.1080p-GRP",
        "Film.2020.1080p",
        10 * GIB,
        GIB,
    )
    .await;

    let any = size_mismatch::find(pool, &args("any", false, false))
        .await
        .unwrap();
    assert_eq!(
        ids(&any),
        [1, 2],
        "ordered by the difference, biggest first"
    );
    assert!((any[0].diff_percent - 50.0).abs() < 1e-6);
    assert!((any[1].size_diff + 5.0).abs() < 1e-6);
    let bigger = size_mismatch::find(pool, &args("bigger", false, false))
        .await
        .unwrap();
    assert_eq!(ids(&bigger), [1]);
    let smaller = size_mismatch::find(pool, &args("smaller", false, false))
        .await
        .unwrap();
    assert_eq!(ids(&smaller), [2]);
    let packs = size_mismatch::find(pool, &args("any", true, false))
        .await
        .unwrap();
    assert_eq!(ids(&packs), [1]);
    let mut limited = args("any", false, true);
    limited.limit = 1;
    assert_eq!(
        ids(&size_mismatch::find(pool, &limited).await.unwrap()),
        [1]
    );

    let categorizer = Categorizer::load(pool).await.unwrap();
    let mut updater =
        ReleaseUpdater::new(pool.clone(), categorizer, Arc::new(NoSearch), fixer_cleaner);
    let renamed = size_mismatch::rename(pool, &mut updater, &packs[0])
        .await
        .unwrap();
    assert_eq!(renamed.as_deref(), Some(pack));
    let searchname: String = sqlx::query_scalar("SELECT searchname FROM releases WHERE id = 1")
        .fetch_one(pool)
        .await
        .unwrap();
    assert_eq!(searchname, pack);
    db.drop_database().await.expect("drop");
}
