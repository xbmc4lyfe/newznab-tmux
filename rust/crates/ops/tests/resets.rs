//! Resets against the real schema.

use anyhow::Result;
use nntmux_ops::resets;
use nntmux_testkit::TestDb;
use nntmux_verify::cache::Cache;
use nntmux_verify::config::VerificationConfig;
use nntmux_verify::context::Verify;
use sqlx::MySqlPool;

async fn release(db: &MySqlPool, guid: &str, category: i32) -> Result<i64> {
    Ok(sqlx::query(
        "INSERT INTO releases (name, searchname, fromname, adddate, guid, leftguid, groups_id, categories_id, \
         consoleinfo_id, movieinfo_id, imdbid, musicinfo_id, bookinfo_id, gamesinfo_id, videos_id, tv_episodes_id, \
         tv_lookup_attempts, nzbstatus, passwordstatus, haspreview, jpgstatus, videostatus, nfostatus) \
         VALUES (?, ?, 'f', NOW(), ?, LEFT(?, 1), 1, ?, 5, 5, '1234567', 5, 5, 5, 5, 5, 2, 1, 0, 1, 1, 1, 1)",
    )
    .bind(guid)
    .bind(guid)
    .bind(guid)
    .bind(guid)
    .bind(category)
    .execute(db)
    .await?
    .last_insert_id() as i64)
}

async fn value(db: &MySqlPool, column: &str, id: i64) -> Result<Option<String>> {
    Ok(sqlx::query_scalar(sqlx::AssertSqlSafe(format!(
        "SELECT CAST({column} AS CHAR) FROM releases WHERE id = ?"
    )))
    .bind(id)
    .fetch_one(db)
    .await?)
}

async fn queued(db: &MySqlPool) -> Result<Vec<i64>> {
    Ok(sqlx::query_scalar(
        "SELECT CAST(release_id AS SIGNED) FROM search_index_failures ORDER BY release_id",
    )
    .fetch_all(db)
    .await?)
}

#[tokio::test]
async fn category_resets_touch_their_category_and_queue_search_as_php_did() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let db = &test.pool;
    let movie = release(db, "a0", 2010).await?;
    let tv = release(db, "b0", 5010).await?;
    let game = release(db, "c0", 1010).await?;
    let misc = release(db, "d0", 10).await?;

    assert_eq!(resets::reset_category(db, "movie").await?, 1);
    assert_eq!(value(db, "movieinfo_id", movie).await?, None);
    assert_eq!(value(db, "imdbid", movie).await?, None);
    assert_eq!(value(db, "movieinfo_id", tv).await?.as_deref(), Some("5"));
    assert_eq!(queued(db).await?, [movie]);

    assert_eq!(resets::reset_category(db, "console").await?, 1);
    assert_eq!(value(db, "consoleinfo_id", game).await?, None);
    assert_eq!(
        queued(db).await?,
        [movie],
        "console resets don't touch search"
    );

    assert_eq!(resets::reset_category(db, "tv").await?, 1);
    assert_eq!(value(db, "videos_id", tv).await?.as_deref(), Some("0"));
    assert_eq!(
        value(db, "tv_lookup_attempts", tv).await?.as_deref(),
        Some("0")
    );
    assert_eq!(resets::reset_category(db, "tv").await?, 0);

    assert_eq!(resets::reset_category(db, "misc").await?, 1);
    assert_eq!(
        value(db, "passwordstatus", misc).await?.as_deref(),
        Some("-1")
    );
    assert_eq!(value(db, "nfostatus", misc).await?.as_deref(), Some("-1"));
    assert_eq!(
        value(db, "passwordstatus", movie).await?.as_deref(),
        Some("0")
    );

    assert_eq!(resets::reset_category(db, "adult").await?, 0);
    assert!(resets::reset_category(db, "nope").await.is_err());

    assert_eq!(resets::reset_all_postprocessing(db).await?, 4);
    assert_eq!(value(db, "musicinfo_id", game).await?, None);
    assert_eq!(
        value(db, "passwordstatus", movie).await?.as_deref(),
        Some("-1")
    );
    assert_eq!(queued(db).await?.len(), 4);
    test.drop_database().await
}

#[tokio::test]
async fn a_tv_show_reset_is_limited_to_that_show() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let db = &test.pool;
    let show: i64 = sqlx::query("INSERT INTO videos (type, title, countries_id, started, source) VALUES (0, 'Show', '', NOW(), 0)")
        .execute(db)
        .await?
        .last_insert_id() as i64;
    let film: i64 = sqlx::query("INSERT INTO videos (type, title, countries_id, started, source) VALUES (1, 'Film', '', NOW(), 0)")
        .execute(db)
        .await?
        .last_insert_id() as i64;
    let mine = release(db, "a1", 5010).await?;
    let other = release(db, "b1", 5010).await?;
    for (id, video) in [(mine, show), (other, show + 100)] {
        sqlx::query("UPDATE releases SET videos_id = ? WHERE id = ?")
            .bind(video)
            .bind(id)
            .execute(db)
            .await?;
    }
    assert!(resets::reset_tv_show(db, film, false).await.is_err());
    assert!(matches!(
        resets::reset_tv_show(db, show, true).await?,
        resets::TvShowReset::WouldReset { releases: 1, .. }
    ));
    assert_ne!(value(db, "videos_id", mine).await?.as_deref(), Some("0"));
    assert!(matches!(
        resets::reset_tv_show(db, show, false).await?,
        resets::TvShowReset::Reset { releases: 1, .. }
    ));
    assert_eq!(value(db, "videos_id", mine).await?.as_deref(), Some("0"));
    assert_ne!(value(db, "videos_id", other).await?.as_deref(), Some("0"));
    assert_eq!(queued(db).await?, [mine]);
    test.drop_database().await
}

#[tokio::test]
async fn remove_bad_deletes_failures_and_marks_passworded_releases_once() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let db = &test.pool;
    let bad = release(db, "a2", 10).await?;
    let worse = release(db, "b2", 10).await?;
    let locked = release(db, "c2", 10).await?;
    let fine = release(db, "d2", 10).await?;
    sqlx::query("UPDATE releases SET passwordstatus = -2 WHERE id = ?")
        .bind(bad)
        .execute(db)
        .await?;
    sqlx::query("UPDATE releases SET passwordstatus = -5 WHERE id = ?")
        .bind(worse)
        .execute(db)
        .await?;
    for (id, name, passworded) in [(locked, "x", 1), (fine, "y", 0)] {
        sqlx::query(
            "INSERT INTO release_files (releases_id, name, size, passworded) VALUES (?, ?, 1, ?)",
        )
        .bind(id)
        .bind(name)
        .bind(passworded)
        .execute(db)
        .await?;
    }
    assert_eq!(resets::remove_bad(db).await?, (2, 1));
    let left: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM releases")
        .fetch_one(db)
        .await?;
    assert_eq!(left, 2);
    assert_eq!(
        value(db, "passwordstatus", locked).await?.as_deref(),
        Some("1")
    );
    assert_eq!(
        value(db, "passwordstatus", fine).await?.as_deref(),
        Some("0")
    );
    assert_eq!(resets::remove_bad(db).await?, (0, 0));
    test.drop_database().await
}

#[tokio::test]
async fn truncate_keeps_releases_with_nzbs_and_clear_empties_everything() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let Ok(url) = std::env::var("TEST_REDIS_URL") else {
        return Ok(());
    };
    let db = &test.pool;
    let storage = tempfile::tempdir()?;
    let verify = Verify::new(
        db.clone(),
        Cache::new(
            redis::aio::ConnectionManager::new(redis::Client::open(url)?).await?,
            format!("{}:", test.name),
        ),
        VerificationConfig::for_tests(storage.path().to_path_buf()),
    );
    let keep = release(db, "a3", 10).await?;
    let gone = release(db, "b3", 10).await?;
    sqlx::query("UPDATE releases SET nzbstatus = 0 WHERE id = ?")
        .bind(gone)
        .execute(db)
        .await?;
    sqlx::query("UPDATE usenet_groups SET first_record = 9, last_record = 9")
        .execute(db)
        .await?;
    sqlx::query(
        "INSERT INTO collections (subject, fromname, xref, groups_id, collectionhash, filecheck, dateadded) \
         VALUES ('s', 'f', '', 1, UNHEX(MD5('x')), 0, NOW())",
    )
    .execute(db)
    .await?;

    assert_eq!(resets::reset_truncate(&verify).await?, 1);
    assert_eq!(value(db, "guid", keep).await?.as_deref(), Some("a3"));
    let collections: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM collections")
        .fetch_one(db)
        .await?;
    assert_eq!(collections, 0);
    let dirty: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM usenet_groups WHERE first_record <> 0 OR last_record <> 0",
    )
    .fetch_one(db)
    .await?;
    assert_eq!(dirty, 0);

    // Every listed table exists, or the truncate fails here.
    resets::clear_database(db).await?;
    let releases: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM releases")
        .fetch_one(db)
        .await?;
    assert_eq!(releases, 0);
    let checks: i64 = sqlx::query_scalar("SELECT @@FOREIGN_KEY_CHECKS")
        .fetch_one(db)
        .await?;
    assert_eq!(checks, 1);
    test.drop_database().await
}

#[tokio::test]
async fn optimize_checks_named_tables_only() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let db = &test.pool;
    assert!(
        resets::optimize_tables(db, &["releases".to_owned()])
            .await
            .is_ok()
    );
    assert!(
        resets::optimize_tables(db, &["no_such`table".to_owned()])
            .await
            .is_err()
    );
    resets::optimize_tables(db, &[]).await?;
    test.drop_database().await
}

#[test]
fn remove_files_keeps_directories_dot_files_and_named_files() -> Result<()> {
    let dir = tempfile::tempdir()?;
    std::fs::create_dir_all(dir.path().join("a/b"))?;
    for name in [
        "a/b/one.jpg",
        "two.jpg",
        "no-cover.jpg",
        ".gitignore",
        "a/.keep",
    ] {
        std::fs::write(dir.path().join(name), "x")?;
    }
    assert_eq!(resets::remove_files(dir.path(), &["no-cover.jpg"])?, 2);
    assert!(dir.path().join("a/b").is_dir());
    assert!(dir.path().join("no-cover.jpg").exists());
    assert!(dir.path().join(".gitignore").exists());
    assert!(dir.path().join("a/.keep").exists());
    assert!(!dir.path().join("two.jpg").exists());
    assert_eq!(resets::remove_files(&dir.path().join("missing"), &[])?, 0);
    Ok(())
}
