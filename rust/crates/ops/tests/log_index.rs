//! The log indexer against real MariaDB and Manticore, on a table private to the test.

use std::fs;
use std::io::Write;
use std::path::Path;
use std::time::{Duration, SystemTime};

use anyhow::Result;
use nntmux_ops::log_index::{LogIndexer, Stats};
use nntmux_search::{SearchClient, SearchConfig};
use nntmux_testkit::TestDb;
use serde_json::Value;

const TWO: &str = "[2026-11-01T17:00:00+00:00] ingest.ERROR: first failed\nstack\n[2026-11-01T17:00:01+00:00] ingest.INFO: second\n";

/// Ages a file past the tail wait, so its last entry counts as finished.
fn settle(path: &Path) -> Result<()> {
    let file = fs::File::options().write(true).open(path)?;
    file.set_modified(SystemTime::now() - Duration::from_secs(3600))?;
    Ok(())
}

async fn rows(
    client: &SearchClient,
    table: &str,
    order: &str,
) -> Result<Vec<serde_json::Map<String, Value>>> {
    Ok(client
        .sql(&format!("SELECT * FROM {table} ORDER BY {order} ASC"))
        .await?)
}

#[tokio::test]
async fn tails_files_and_follows_truncation_and_removal() -> Result<()> {
    let Ok(address) = std::env::var("TEST_MANTICORE_HTTP") else {
        eprintln!("TEST_MANTICORE_HTTP is unset, skipping");
        return Ok(());
    };
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let client = SearchClient::new(SearchConfig::new(format!("http://{address}")), None)?;
    let dir = tempfile::tempdir()?;
    let table = format!("{}_logs", test.name);
    let indexer = LogIndexer {
        client: &client,
        db: &test.pool,
        table: table.clone(),
        directory: dir.path().to_path_buf(),
        batch_size: 1,
        max_bytes_per_run: 1 << 30,
        max_bytes_per_file: 1 << 30,
    };
    let path = dir.path().join("ingest.log");
    fs::write(&path, TWO)?;

    // The file was just written, so its last entry may still be growing.
    let first = indexer.run(&[]).await?;
    assert_eq!((first.entries, first.purged), (1, 0));
    let found = rows(&client, &table, "byte_offset").await?;
    assert_eq!(found.len(), 1);
    assert_eq!(
        found[0]["first_line"],
        "[2026-11-01T17:00:00+00:00] ingest.ERROR: first failed"
    );
    assert_eq!(found[0]["body"], "stack");
    assert_eq!(found[0]["level"], "error");
    assert_eq!(found[0]["channel"], "ingest");

    settle(&path)?;
    assert_eq!(indexer.run(&[]).await?.entries, 1);
    assert_eq!(rows(&client, &table, "byte_offset").await?.len(), 2);

    // Nothing new: no writes, no duplicates.
    assert_eq!(indexer.run(&[]).await?, Stats::default());

    // Appended lines continue from the saved offset, with their line numbers.
    let mut file = fs::OpenOptions::new().append(true).open(&path)?;
    writeln!(file, "[2026-11-01T17:00:02+00:00] ingest.INFO: third")?;
    drop(file);
    settle(&path)?;
    assert_eq!(indexer.run(&[]).await?.entries, 1);
    let found = rows(&client, &table, "byte_offset").await?;
    assert_eq!(found.len(), 3);
    assert_eq!(found[2]["line_no"].to_string().trim_matches('"'), "4");

    // A rename keeps its progress.
    let renamed = dir.path().join("ingest.old.log");
    fs::rename(&path, &renamed)?;
    assert_eq!(indexer.run(&[]).await?, Stats::default());

    // Truncation starts over and clears the stale documents.
    fs::write(&renamed, "[2026-11-01T18:00:00+00:00] ingest.INFO: fresh\n")?;
    settle(&renamed)?;
    let after = indexer.run(&[]).await?;
    assert_eq!((after.entries, after.purged), (1, 1));
    assert_eq!(rows(&client, &table, "byte_offset").await?.len(), 1);

    // A wiped table is noticed, and everything is indexed again.
    client.sql(&format!("TRUNCATE TABLE {table}")).await?;
    assert_eq!(indexer.run(&[]).await?.entries, 1);

    // A vanished file takes its documents and row with it.
    fs::remove_file(&renamed)?;
    assert_eq!(indexer.run(&[]).await?.purged, 1);
    assert!(rows(&client, &table, "byte_offset").await?.is_empty());
    let tracked: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM log_index_files")
        .fetch_one(&test.pool)
        .await?;
    assert_eq!(tracked, 0);

    indexer.reset().await?;
    test.drop_database().await
}
