//! The processing snapshot and metrics export against the real schema.

use std::sync::Arc;

use anyhow::Result;
use nntmux_core::Config;
use nntmux_ops::{metrics_export, processing_stats};
use nntmux_runtime::Ctx;
use nntmux_testkit::TestDb;
use redis::AsyncCommands;
use wiremock::matchers::{header, method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

async fn context(test: &TestDb) -> Result<Option<Ctx>> {
    let Ok(url) = std::env::var("TEST_REDIS_URL") else {
        return Ok(None);
    };
    let mut config = Config::from_env()?;
    config.database.database.clone_from(&test.name);
    Ok(Some(Ctx {
        config: Arc::new(config),
        db: test.pool.clone(),
        redis: redis::aio::ConnectionManager::new(redis::Client::open(url)?).await?,
    }))
}

async fn seed(db: &sqlx::MySqlPool) -> Result<()> {
    for statement in [
        "UPDATE tmux_configurations SET post_mode = 3, post_non_mode = 1, post_amazon_mode = 1, fix_names_enabled = 1, sequential_mode = 0",
        "UPDATE metadata_configurations SET tv_lookup = 1, movie_lookup = 1, music_lookup = 1, book_lookup = 1, anime_lookup = 1, game_lookup = 1",
        "UPDATE post_processing_configurations SET lookup_nfo = 1, max_nfo_retries = 3",
        // Waiting for a TV lookup.
        "INSERT INTO releases (id, name, searchname, fromname, adddate, postdate, guid, leftguid, groups_id, categories_id, size, nzbstatus, nfostatus, passwordstatus, haspreview) \
         VALUES (1, 'n1', 's1', 'f', NOW(), NOW(), 'a1', 'a', 1, 5010, 5000000, 1, -1, -1, -1)",
        // A late-article partial isn't settled, so no lookup counts it.
        "INSERT INTO releases (id, name, searchname, fromname, adddate, postdate, guid, leftguid, groups_id, categories_id, size, nzbstatus, late_article_candidate_id) \
         VALUES (2, 'n2', 's2', 'f', NOW(), NOW(), 'a2', 'a', 1, 5010, 5000000, 1, 77)",
        "INSERT INTO releases (id, name, searchname, fromname, adddate, postdate, guid, leftguid, groups_id, categories_id, imdbid, isrenamed, nfostatus, predb_id) \
         VALUES (3, 'n3', 's3', 'f', NOW(), NOW(), 'a3', 'a', 1, 2010, '0133093', 1, 1, 9)",
        "INSERT INTO service_statuses (name, slug, status, response_time_ms, is_enabled) VALUES \
         ('Web', 'web', 'degraded', 250, 1), ('Db', 'db', 'major_outage', NULL, 1), ('Off', 'off', 'major_outage', NULL, 0)",
    ] {
        sqlx::query(statement).execute(db).await?;
    }
    Ok(())
}

#[tokio::test]
async fn the_snapshot_counts_backlogs_and_the_export_publishes_them() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&test).await? else {
        return Ok(());
    };
    seed(&test.pool).await?;

    let snapshot = processing_stats::collect(&ctx).await?;
    let counts = &snapshot["counts"];
    assert_eq!(counts["processtv"], 1, "{counts}");
    assert_eq!(counts["processmovies"], 0);
    assert_eq!(counts["processnfo"], 1);
    assert_eq!(counts["renamed"], 1);
    assert_eq!(counts["nfo"], 1);
    assert_eq!(counts["predb_matched"], 1);
    assert_eq!(counts["tv"], 2);
    assert_eq!(counts["movies"], 1);
    assert_eq!(counts["releases"], 3);
    assert!(counts["active_groups"].is_i64());
    assert!(counts["total_work"].as_i64().unwrap_or(0) >= 2);

    let without = metrics_export::render(&ctx).await;
    assert!(
        without.contains("nntmux_tmux_snapshot_available 0"),
        "{without}"
    );
    processing_stats::publish(&ctx).await?;
    let text = metrics_export::render(&ctx).await;
    for line in [
        "nntmux_up 1",
        "nntmux_tmux_snapshot_available 1",
        "nntmux_processing_backlog{type=\"tv\"} 1",
        "nntmux_releases 3",
        "nntmux_releases_by_category{category=\"tv\"} 2",
        "nntmux_table_rows{table=\"collections\"} 0",
        "nntmux_fingerprint_jobs{status=\"pending\"} 0",
        "nntmux_queue_jobs{queue=\"release-fingerprint\"} 0",
        "nntmux_failed_jobs 0",
        "nntmux_release_verification_candidates_measurement_available 1",
        "nntmux_release_verification_backlog_age_seconds 0",
        "nntmux_service_up{service=\"web\"} 1",
        "nntmux_service_up{service=\"db\"} 0",
        "nntmux_service_severity{service=\"db\"} 4",
        "nntmux_service_response_time_seconds{service=\"web\"} 0.25",
    ] {
        assert!(text.contains(line), "missing {line} in:\n{text}");
    }
    assert!(!text.contains("service=\"off\""));
    for collector in [
        "TmuxSnapshotCollector",
        "DatabaseFingerprintCollector",
        "FailedJobsCollector",
        "ReleaseVerificationCollector",
        "ServiceStatusCollector",
    ] {
        let healthy = format!("nntmux_metrics_collector_errors{{collector=\"{collector}\"}} 0");
        assert!(text.contains(&healthy), "missing {healthy} in:\n{text}");
    }

    let gateway = MockServer::start().await;
    Mock::given(method("PUT"))
        .and(path("/metrics/job/nntmux"))
        .and(header(
            "content-type",
            "text/plain; version=0.0.4; charset=utf-8",
        ))
        .respond_with(ResponseTemplate::new(202))
        .expect(1)
        .mount(&gateway)
        .await;
    metrics_export::push_to(&ctx, &gateway.uri()).await?;

    let refusing = MockServer::start().await;
    Mock::given(method("PUT"))
        .respond_with(ResponseTemplate::new(500))
        .mount(&refusing)
        .await;
    assert!(
        metrics_export::push_to(&ctx, &refusing.uri())
            .await
            .is_err()
    );

    let mut redis = ctx.redis.clone();
    let _: () = redis.del(processing_stats::snapshot_key(&ctx)).await?;
    test.drop_database().await
}

#[tokio::test]
async fn a_failing_collector_is_reported_without_stopping_the_rest() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&test).await? else {
        return Ok(());
    };
    sqlx::query("DROP TABLE failed_jobs")
        .execute(&test.pool)
        .await?;
    let text = metrics_export::render(&ctx).await;
    assert!(text.contains("nntmux_metrics_collector_errors{collector=\"FailedJobsCollector\"} 1"));
    assert!(text.contains("nntmux_up 1"));
    test.drop_database().await
}

#[tokio::test]
async fn an_over_budget_statement_is_recognised_as_a_timeout() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let failure = sqlx::query(
        "SET STATEMENT max_statement_time=0.01 FOR SELECT COUNT(*) FROM seq_1_to_1000000000 WHERE seq % 7 = 3",
    )
    .execute(&test.pool)
    .await
    .expect_err("the statement runs over its limit");
    assert!(metrics_export::timed_out(&failure), "{failure}");
    let other = sqlx::query("SELECT * FROM no_such_table")
        .execute(&test.pool)
        .await
        .expect_err("the table doesn't exist");
    assert!(!metrics_export::timed_out(&other));
    test.drop_database().await
}
