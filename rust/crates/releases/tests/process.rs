//! Release passes against a real database and Redis.

use anyhow::Result;
use nntmux_releases::process::{self, Mode};
use nntmux_releases::settings::{Limits, Settings};
use nntmux_releases::{Releases, maintenance};
use nntmux_testkit::TestDb;
use nntmux_verify::cache::Cache;
use nntmux_verify::config::VerificationConfig;
use nntmux_verify::context::Verify;
use rand::Rng;
use sqlx::MySqlPool;

struct Harness {
    db: TestDb,
    releases: Releases,
}

fn limits() -> Limits {
    Limits {
        sql_chunk_size: 500,
        reconcile_batch_size: 500,
        reconcile_pages_per_pass: 10,
        reconcile_pass_seconds: 60,
    }
}

impl Harness {
    async fn start() -> Result<Option<Self>> {
        let (Some(db), Ok(redis_url)) = (TestDb::create().await?, std::env::var("TEST_REDIS_URL"))
        else {
            return Ok(None);
        };
        let redis = redis::Client::open(redis_url)?
            .get_connection_manager()
            .await?;
        let prefix = format!("t_{:016x}:", rand::rng().random::<u64>());
        let config = VerificationConfig::for_tests(std::env::temp_dir().join(&prefix));
        sqlx::query(
            "INSERT INTO release_verification_state (id, pending_bytes, audit_cursor) VALUES (1, 0, 0) \
             ON DUPLICATE KEY UPDATE pending_bytes = 0",
        )
        .execute(&db.pool)
        .await?;
        sqlx::query("DELETE FROM usenet_groups WHERE id <> 1 AND name = 'alt.binaries.test'")
            .execute(&db.pool)
            .await?;
        sqlx::query(
            "INSERT INTO usenet_groups (id, name, active, backfill) VALUES (1, 'alt.binaries.test', 1, 0) \
             ON DUPLICATE KEY UPDATE name = VALUES(name), active = 1, minfilestoformrelease = NULL, minsizetoformrelease = NULL",
        )
        .execute(&db.pool)
        .await?;
        let releases = Releases {
            verify: Verify::new(db.pool.clone(), Cache::new(redis, prefix), config),
        };
        Ok(Some(Self { db, releases }))
    }

    fn pool(&self) -> &MySqlPool {
        &self.db.pool
    }

    /// One file; `parts` of `total` articles stored; aggregates left at zero for reconciling.
    async fn collection(
        &self,
        hash: u8,
        parts: u32,
        total: u32,
        filecheck: i8,
        hours_old: i64,
    ) -> Result<i64> {
        let id = sqlx::query(
            "INSERT INTO collections (subject, fromname, date, xref, totalfiles, groups_id, collectionhash, \
             dateadded, last_seen_at, filecheck, filesize) VALUES ('Subject', 'poster', '2026-10-31 12:00:00', '', 1, 1, ?, \
             UTC_TIMESTAMP() - INTERVAL ? HOUR, UTC_TIMESTAMP(), ?, 0)",
        )
        .bind(vec![hash; 20])
        .bind(hours_old)
        .bind(filecheck)
        .execute(self.pool())
        .await?
        .last_insert_id();
        let binary = sqlx::query(
            "INSERT INTO binaries (binaryhash, name, collections_id, totalparts) VALUES (UNHEX(MD5(?)), 'file.rar', ?, ?)",
        )
        .bind(hash)
        .bind(id)
        .bind(total)
        .execute(self.pool())
        .await?
        .last_insert_id();
        for part in 1..=parts {
            sqlx::query("INSERT INTO parts (binaries_id, messageid, number, partnumber, size) VALUES (?, ?, 1, ?, 1000)")
                .bind(binary)
                .bind(format!("{hash}-{part}@example.com"))
                .bind(part)
                .execute(self.pool())
                .await?;
        }
        Ok(id as i64)
    }

    async fn scalar(&self, sql: &str) -> Result<i64> {
        Ok(sqlx::query_scalar(sqlx::AssertSqlSafe(sql.to_owned()))
            .fetch_one(self.pool())
            .await?)
    }
}

#[tokio::test]
async fn a_pass_reconciles_sizes_and_archives_complete_collections() -> Result<()> {
    let Some(h) = Harness::start().await? else {
        return Ok(());
    };
    let settings = Settings::default();
    let complete = h.collection(1, 2, 2, 0, 0).await?;
    // Still within the collection delay and missing an article: left alone.
    let waiting = h.collection(2, 1, 2, 0, 0).await?;
    // Older than the delay and incomplete: promoted anyway, and archived as waiting for articles.
    let stale = h.collection(3, 1, 2, 0, 3).await?;

    // A new collection isn't due until its delay ends unless it's already complete; mark it as
    // ingestion does when the last article arrives.
    sqlx::query("UPDATE collections SET filecheck = 2 WHERE id = ?")
        .bind(complete)
        .execute(h.pool())
        .await?;
    process::process_collections_and_releases(&h.releases, &settings, &limits(), None).await?;

    let remaining: Vec<u32> = sqlx::query_scalar("SELECT id FROM collections")
        .fetch_all(h.pool())
        .await?;
    assert_eq!(
        remaining,
        [waiting as u32],
        "only the fresh incomplete one stays; stale was {stale}"
    );
    let statuses: Vec<(String, u64)> = sqlx::query_as(
        "SELECT status, manifest_bytes FROM release_verification_candidates ORDER BY status",
    )
    .fetch_all(h.pool())
    .await?;
    assert_eq!(statuses.len(), 2);
    assert_eq!(statuses[0].0, "ready");
    assert_eq!(statuses[1].0, "waiting_upload");
    // The waiting collection's aggregates were not touched outside its page.
    h.db.drop_database().await
}

#[tokio::test]
async fn sizing_recomputes_drifted_aggregates_from_stored_parts() -> Result<()> {
    let Some(h) = Harness::start().await? else {
        return Ok(());
    };
    let settings = Settings::default();
    let id = h.collection(1, 2, 2, 2, 0).await?;
    process::process_collection_sizes(&h.releases, &settings, &limits(), None).await?;
    let (filecheck, filesize): (i8, u64) =
        sqlx::query_as("SELECT filecheck, filesize FROM collections WHERE id = ?")
            .bind(id)
            .fetch_one(h.pool())
            .await?;
    assert_eq!((filecheck, filesize), (3, 2000));
    let (current, size, check): (u32, u64, bool) =
        sqlx::query_as("SELECT currentparts, partsize, partcheck FROM binaries")
            .fetch_one(h.pool())
            .await?;
    assert_eq!((current, size, check), (2, 2000, true));
    h.db.drop_database().await
}

#[tokio::test]
async fn ready_only_handles_one_bounded_window_smallest_first() -> Result<()> {
    let Some(h) = Harness::start().await? else {
        return Ok(());
    };
    let settings = Settings {
        release_creation_limit: 1,
        ..Settings::default()
    };
    for hash in [1u8, 2] {
        let id = h.collection(hash, 1, 1, 3, 0).await?;
        sqlx::query("UPDATE collections SET filesize = ? WHERE id = ?")
            .bind(u32::from(hash) * 1000)
            .bind(id)
            .execute(h.pool())
            .await?;
    }
    let totals = process::process_ready_release_window(&h.releases, &settings, 1).await?;
    assert_eq!(totals.iterations, 1);
    let size: u64 = sqlx::query_scalar("SELECT filesize FROM collections")
        .fetch_one(h.pool())
        .await?;
    assert_eq!(
        size, 2000,
        "the smaller collection goes first and the window stops at one"
    );
    let totals = process::process_ready_release_window(&h.releases, &settings, 1).await?;
    assert_eq!(totals.iterations, 1);
    assert_eq!(h.scalar("SELECT COUNT(*) FROM collections").await?, 0);
    assert!(
        process::process_ready_release_window(&h.releases, &settings, 0)
            .await
            .is_err()
    );
    h.db.drop_database().await
}

#[tokio::test]
async fn a_stop_request_ends_a_pass_before_it_starts() -> Result<()> {
    let Some(h) = Harness::start().await? else {
        return Ok(());
    };
    h.collection(1, 1, 1, 3, 0).await?;
    sqlx::query(
        "INSERT INTO processing_runtime_states (id, stop_requested) VALUES (1, 1) \
         ON DUPLICATE KEY UPDATE stop_requested = 1",
    )
    .execute(h.pool())
    .await?;
    process::run(&h.releases, Mode::Group(1)).await?;
    assert_eq!(h.scalar("SELECT COUNT(*) FROM collections").await?, 1);
    h.db.drop_database().await
}

async fn add_release(h: &Harness, n: u8, extra: &str) -> Result<()> {
    let guid = format!("{n:08x}-0000-0000-0000-000000000000");
    let sql = format!(
        "INSERT INTO releases (name, searchname, groups_id, guid, leftguid, size, adddate, postdate, totalpart, \
         categories_id, nzbstatus, {}) VALUES ('n', 's', 1, ?, '0', {}, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 5, 2030, 1, {})",
        extra.split('=').next().unwrap_or("grabs"),
        1000 + i64::from(n),
        extra.split('=').nth(1).unwrap_or("0"),
    );
    sqlx::query(sqlx::AssertSqlSafe(sql))
        .bind(guid)
        .execute(h.pool())
        .await?;
    Ok(())
}

#[tokio::test]
async fn maintenance_removes_what_settings_no_longer_want() -> Result<()> {
    let Some(h) = Harness::start().await? else {
        return Ok(());
    };
    let settings = Settings {
        delete_passworded: true,
        completion: 95,
        min_files: 0,
        ..Settings::default()
    };
    add_release(&h, 1, "grabs=0").await?;
    add_release(&h, 2, "passwordstatus=1").await?;
    add_release(&h, 3, "completion=50").await?;
    // A partial publication is knowingly incomplete and stays.
    add_release(&h, 4, "completion=50").await?;
    sqlx::query("UPDATE releases SET articles_expected = 10 WHERE guid LIKE '00000004%'")
        .execute(h.pool())
        .await?;
    maintenance::run(&h.releases, &settings).await?;
    let left: Vec<String> = sqlx::query_scalar("SELECT guid FROM releases ORDER BY guid")
        .fetch_all(h.pool())
        .await?;
    assert_eq!(left.len(), 2);
    assert!(left[0].starts_with("00000001"));
    assert!(left[1].starts_with("00000004"));
    // Their search documents are queued for removal.
    assert_eq!(
        h.scalar("SELECT COUNT(*) FROM search_index_failures WHERE operation = 'delete'")
            .await?,
        2
    );
    h.db.drop_database().await
}

#[tokio::test]
async fn categorizing_finishes_misc_releases_and_queues_their_search_update() -> Result<()> {
    let Some(h) = Harness::start().await? else {
        return Ok(());
    };
    sqlx::query(
        "INSERT INTO releases (name, searchname, groups_id, guid, leftguid, size, categories_id, iscategorized, fromname) \
         VALUES ('n', 'The.Show.S01E02.1080p.WEB-DL.DDP5.1.H.264-GROUP', 1, 'abcdef00-0000-0000-0000-000000000001', 'a', 1, 10, 0, 'poster')",
    )
    .execute(h.pool())
    .await?;
    let changed = maintenance::categorize_releases(&h.releases, None).await?;
    assert_eq!(changed, 1);
    let (category, done): (i32, bool) =
        sqlx::query_as("SELECT categories_id, iscategorized FROM releases")
            .fetch_one(h.pool())
            .await?;
    assert_ne!(category, 10);
    assert!(done);
    assert_eq!(
        h.scalar("SELECT COUNT(*) FROM search_index_failures WHERE operation = 'upsert'")
            .await?,
        1
    );
    assert_eq!(
        maintenance::categorize_releases(&h.releases, None).await?,
        0
    );
    h.db.drop_database().await
}

#[tokio::test]
async fn a_round_runs_ready_windows_full_passes_and_maintenance() -> Result<()> {
    let Some(h) = Harness::start().await? else {
        return Ok(());
    };
    sqlx::query(
        "INSERT INTO ingestion_configurations (id, release_threads, max_releases_created, collection_delay_hours, \
         part_retention_hours, safe_backfill_date) VALUES (1, 2, 100, 2, 72, '2012-06-24') \
         ON DUPLICATE KEY UPDATE release_threads = 2",
    )
    .execute(h.pool())
    .await?;
    h.collection(1, 1, 1, 2, 0).await?;
    nntmux_releases::runner::run_round(&h.releases, &nntmux_runtime::Shutdown::default()).await?;
    assert_eq!(h.scalar("SELECT COUNT(*) FROM collections").await?, 0);
    assert_eq!(
        h.scalar("SELECT COUNT(*) FROM release_verification_candidates")
            .await?,
        1
    );
    h.db.drop_database().await
}
