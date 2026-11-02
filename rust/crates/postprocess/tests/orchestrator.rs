//! Claim batches and single releases through the orchestrator, against a real database and a
//! scripted NNTP server.

#[allow(dead_code)]
mod support;

use std::path::Path;
use std::sync::{Arc, Mutex};

use anyhow::Result;
use async_trait::async_trait;
use nntmux_namefix::update::ReleaseSearch;
use nntmux_postprocess::additional::candidates;
use nntmux_postprocess::additional::orchestrator::{self, Services};
use nntmux_postprocess::additional::processor::Outcome;
use nntmux_postprocess::config::{Config, Settings};
use nntmux_testkit::TestDb;
use nntmux_verify::in_tx;
use nntmux_verify::nzb::{self, ReleaseRef};
use sqlx::MySqlPool;
use support::{post, serve, service};

#[derive(Default)]
struct Synced(Mutex<Vec<i64>>);

#[async_trait]
impl ReleaseSearch for Synced {
    async fn update_release(&self, release_id: i64) {
        self.0.lock().unwrap().push(release_id);
    }
}

fn volume() -> Vec<u8> {
    std::fs::read(
        Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/media/stored/movie.part1.rar"),
    )
    .unwrap()
}

/// A release waiting for additional processing, with an NZB of one stored archive volume.
async fn queued(
    pool: &MySqlPool,
    guid: &str,
    articles_prefix: &'static str,
) -> Result<(i64, Vec<(&'static str, Vec<u8>)>)> {
    let category: i64 = sqlx::query_scalar(
        "SELECT CAST(id AS SIGNED) FROM categories WHERE disablepreview = 0 ORDER BY id LIMIT 1",
    )
    .fetch_one(pool)
    .await?;
    let id = sqlx::query(
        "INSERT INTO releases (name, searchname, totalpart, groups_id, fromname, size, postdate, \
         adddate, guid, leftguid, categories_id, nzbstatus, passwordstatus, haspreview) \
         VALUES ('n', 's', 1, 1, 'f', 5000000, NOW(), NOW(), ?, ?, ?, 1, -1, -1)",
    )
    .bind(guid)
    .bind(&guid[..1])
    .bind(category)
    .execute(pool)
    .await?
    .last_insert_id() as i64;
    let (file, articles) = post(articles_prefix, "movie.part1.rar", &volume());
    let mut document = format!(
        "<nzb><file subject=\"{}\"><segments>",
        file.title.replace('"', "&quot;")
    );
    for (number, segment) in file.segments.iter().enumerate() {
        document.push_str(&format!(
            "<segment number=\"{}\" bytes=\"20000\">{segment}</segment>",
            number + 1
        ));
    }
    document.push_str("</segments></file></nzb>");
    let reference = ReleaseRef {
        id,
        guid: guid.to_owned(),
    };
    let body = nzb::compress(document.as_bytes())?;
    in_tx!(pool, 1, |tx| {
        nzb::put_locked(tx, &reference, &body, None, false).await
    })?;
    Ok((id, articles))
}

async fn services(pool: &MySqlPool, tmp: &Path, port: u16) -> Result<Services> {
    let mut config = Config::from_settings(Settings::default())?;
    config.tmp_unrar_path = tmp.to_owned();
    config.covers_path = tmp.join("covers");
    let mut services = Services::build(pool.clone(), config, Arc::new(Synced::default())).await?;
    services.downloads = service(port);
    // Downloads only run with something to look for; ffmpeg and unrar may be missing here.
    services.processor.settings.process_jpg_sample = true;
    // The whole first volume, so its listing is complete.
    services.processor.planner.maximum_rar_segments = 5;
    services.processor.settings.maximum_rar_segments = 5;
    // The configured default paths need not exist here.
    services.processor.extractor.unrar = None;
    services.config.mediainfo_path = None;
    Ok(services)
}

#[tokio::test]
async fn a_batch_is_claimed_processed_and_released() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let tmp = tempfile::tempdir()?;
    let (id, articles) = queued(pool, "a1", "v").await?;
    let server = serve(articles).await;
    let mut services = services(pool, tmp.path(), server.port).await?;

    let batch = orchestrator::start(&mut services, "a", 0, "worker", &[]).await?;

    assert_eq!(batch.claimed_ids, vec![id]);
    assert_eq!(batch.results.len(), 1);
    assert!(batch.results[0].is_successful(), "{:?}", batch.results[0]);
    assert!(batch.release_files_added() > 0, "{:?}", batch.results[0]);
    let claim: Option<String> =
        sqlx::query_scalar("SELECT additional_pp_claim_token FROM releases WHERE id = ?")
            .bind(id)
            .fetch_one(pool)
            .await?;
    assert_eq!(claim, None);
    let files: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM release_files WHERE releases_id = ?")
        .bind(id)
        .fetch_one(pool)
        .await?;
    assert!(files > 0);
    let profile = batch.profile("a", 1);
    assert_eq!(profile["claimed"], 1);

    // Processed releases leave the queue; other buckets were never in it.
    let again = orchestrator::start(&mut services, "a", 0, "worker", &[]).await?;
    assert_eq!(again.claimed_count(), 0);
    let other = orchestrator::start(&mut services, "b", 0, "worker", &[]).await?;
    assert_eq!(other.claimed_count(), 0);
    db.drop_database().await
}

#[tokio::test]
async fn single_releases_are_processed_by_guid() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let tmp = tempfile::tempdir()?;
    let (id, articles) = queued(pool, "c1", "w").await?;
    let server = serve(articles).await;
    let mut services = services(pool, tmp.path(), server.port).await?;

    let missing = orchestrator::process_single_guid(&mut services, "nope").await;
    assert_eq!(missing.outcome, Outcome::NotFound);

    sqlx::query("UPDATE releases SET late_article_candidate_id = 9 WHERE id = ?")
        .bind(id)
        .execute(pool)
        .await?;
    let late = orchestrator::process_single_guid(&mut services, "c1").await;
    assert_eq!(late.outcome, Outcome::AwaitingLateArticles);

    sqlx::query("UPDATE releases SET late_article_candidate_id = NULL WHERE id = ?")
        .bind(id)
        .execute(pool)
        .await?;
    let result = orchestrator::process_single_guid(&mut services, "c1").await;
    assert!(result.is_successful(), "{result:?}");
    assert_eq!(result.release_id, id);
    db.drop_database().await
}

#[tokio::test]
async fn claims_are_renewed_and_lapsed_ones_left_to_their_new_holder() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let (mine, _) = queued(pool, "e1", "x").await?;
    let (taken, _) = queued(pool, "e2", "y").await?;
    sqlx::query(
        "UPDATE releases SET additional_pp_claim_token = 'mine', \
         additional_pp_claimed_at = '2020-01-01 00:00:00' WHERE id IN (?, ?)",
    )
    .bind(mine)
    .bind(taken)
    .execute(pool)
    .await?;
    // The second claim lapsed and another worker took it.
    sqlx::query("UPDATE releases SET additional_pp_claim_token = 'theirs' WHERE id = ?")
        .bind(taken)
        .execute(pool)
        .await?;

    let held = candidates::renew_claims(pool, &[mine, taken], "mine").await?;

    assert_eq!(held, vec![mine]);
    let stamps: Vec<(String, i64)> = sqlx::query_as(
        "SELECT additional_pp_claim_token, \
         CAST(additional_pp_claimed_at > '2025-01-01' AS SIGNED) FROM releases \
         WHERE id IN (?, ?) ORDER BY id",
    )
    .bind(mine)
    .bind(taken)
    .fetch_all(pool)
    .await?;
    assert_eq!(
        stamps,
        vec![("mine".to_owned(), 1), ("theirs".to_owned(), 0)]
    );
    db.drop_database().await
}
