//! The diagnostics report against a real database.

use anyhow::Result;
use nntmux_postprocess::additional::diagnose;
use nntmux_postprocess::config::{Config, Settings};
use nntmux_testkit::TestDb;

#[tokio::test]
async fn the_report_finds_the_shipped_index_and_flags_oversized_capacity() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let dir = tempfile::tempdir()?;
    let mut config = Config::from_settings(Settings {
        post_threads: 40,
        ..Settings::default()
    })?;
    config.tmp_unrar_path = dir.path().join("unrar");
    config.child_timeout_seconds = 1800;

    let report = diagnose::inspect(&db.pool, &config).await?;
    assert_eq!(
        report.indexes.claim_queue_index.as_deref(),
        Some("ix_releases_add_pp_claim_queue")
    );
    assert!(!report.indexes.missing);
    assert!(!report.temp_path.exists);
    assert!(report.temp_path.writable);
    assert_eq!(report.capacity.max_in_flight, 40 * 25);
    assert_eq!(report.settings.worker_max_batches, 4);
    let codes: Vec<&str> = report.warnings.iter().map(|w| w.code).collect();
    assert_eq!(codes, ["claim-ttl", "oversized-capacity"]);
    assert!(diagnose::render(&report).contains("[oversized-capacity] postthreads=40"));
    db.drop_database().await
}
