//! `nntmux-ctl verify repairable` against a real database.

#[allow(dead_code)]
mod support;

use std::collections::HashMap;

use anyhow::{Result, anyhow};
use async_trait::async_trait;
use nntmux_verify::audit::{self, AuditOptions, Deferred, DeletedAudit};
use nntmux_verify::in_tx;
use nntmux_verify::nzb::{self, ReleaseRef};
use nntmux_verify::spot_check::ArticleSource;
use sqlx::MySqlPool;
use support::Harness;

#[derive(Default)]
struct FakeSource {
    articles: HashMap<String, Vec<u8>>,
}

#[async_trait]
impl ArticleSource for FakeSource {
    async fn fetch_bounded(&self, message_id: &str, _max_bytes: i64) -> Result<Vec<u8>> {
        self.articles
            .get(message_id)
            .cloned()
            .ok_or_else(|| anyhow!("article missing"))
    }

    async fn stat(&self, _: &[String]) -> Result<()> {
        Ok(())
    }
}

/// Ends every other session of the test database the first time an article is fetched, the way
/// a lost lock connection or an idle timeout does.
struct SessionKiller {
    pool: MySqlPool,
}

#[async_trait]
impl ArticleSource for SessionKiller {
    async fn fetch_bounded(&self, _: &str, _: i64) -> Result<Vec<u8>> {
        let mut conn = self.pool.acquire().await?;
        let others: Vec<i64> = sqlx::query_scalar(
            "SELECT CAST(ID AS SIGNED) FROM information_schema.PROCESSLIST WHERE DB = DATABASE() AND ID <> CONNECTION_ID()",
        )
        .fetch_all(&mut *conn)
        .await?;
        for id in others {
            let _ = sqlx::query(sqlx::AssertSqlSafe(format!("KILL {id}")))
                .execute(&mut *conn)
                .await;
        }
        Err(anyhow!("article missing"))
    }

    async fn stat(&self, _: &[String]) -> Result<()> {
        Ok(())
    }
}

/// A single-part yEnc article holding `payload` for `name`.
fn article(name: &str, payload: &[u8]) -> Vec<u8> {
    let encoded: Vec<u8> = payload
        .iter()
        .flat_map(|byte| match byte.wrapping_add(42) {
            escape @ (0 | 10 | 13 | 61) => vec![b'=', escape.wrapping_add(64)],
            plain => vec![plain],
        })
        .collect();
    let crc = format!("{:08x}", crc32fast::hash(payload));
    let size = payload.len();
    let mut raw = format!(
        "=ybegin part=1 total=1 line=128 size={size} name={name}\r\n=ypart begin=1 end={size}\r\n"
    )
    .into_bytes();
    raw.extend(encoded);
    raw.extend(format!("\r\n=yend size={size} part=1 pcrc32={crc}\r\n").into_bytes());
    raw
}

fn nzb_document(files: &[(&str, &str)]) -> String {
    let total = files.len();
    let mut xml = String::from("<nzb>");
    for (index, (name, message_id)) in files.iter().enumerate() {
        xml.push_str(&format!(
            "<file poster=\"p\" date=\"1\" subject=\"[{}/{total}] - &quot;{name}&quot; yEnc (1/1)\">\
             <groups><group>alt.binaries.test</group></groups><segments>\
             <segment number=\"1\" bytes=\"7\">{message_id}</segment></segments></file>",
            index + 1
        ));
    }
    xml.push_str("</nzb>");
    xml
}

/// A release, with its NZB stored when one is given. `partial` records the articles and files
/// found at publication.
async fn release(
    pool: &MySqlPool,
    guid: &str,
    document: Option<&str>,
    partial: Option<(i64, i64)>,
) -> Result<i64> {
    let (found, files) = partial.unwrap_or((1, 1));
    let id = sqlx::query(
        "INSERT INTO releases (name, searchname, fromname, groups_id, guid, leftguid, size, adddate, postdate, \
         nzbstatus, articles_found, articles_expected, files_found, files_expected) \
         VALUES (?, ?, 'p', 1, ?, ?, 7, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1, ?, ?, ?, ?)",
    )
    .bind(format!("Name.{guid}"))
    .bind(format!("Search.{guid}"))
    .bind(guid)
    .bind(&guid[..1])
    .bind(found)
    .bind(partial.map(|(found, _)| found + 1))
    .bind(files)
    .bind(partial.map(|(_, files)| files + 1))
    .execute(pool)
    .await?
    .last_insert_id() as i64;
    if let Some(document) = document {
        let reference = ReleaseRef {
            id,
            guid: guid.to_owned(),
        };
        let body = nzb::compress(document.as_bytes())?;
        in_tx!(pool, 1, |tx| {
            nzb::put_locked(tx, &reference, &body, None, false).await
        })?;
    }
    Ok(id)
}

fn options() -> AuditOptions {
    AuditOptions {
        dry_run: false,
        delete: false,
        limit: 100,
        since_id: 0,
        resume: false,
    }
}

async fn count(pool: &MySqlPool, sql: &'static str, id: i64) -> Result<i64> {
    Ok(sqlx::query_scalar::<_, i64>(sql)
        .bind(id)
        .fetch_one(pool)
        .await?)
}

#[tokio::test]
async fn verdicts_are_recorded_and_inconclusive_ones_retried() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let pool = harness.db();
    let good = release(
        &pool,
        "a0000000-0000-0000-0000-000000000001",
        Some(&nzb_document(&[("Good.Release.mkv", "good@x")])),
        None,
    )
    .await?;
    let missing = release(&pool, "b0000000-0000-0000-0000-000000000002", None, None).await?;
    let mut source = FakeSource::default();
    source
        .articles
        .insert("good@x".to_owned(), article("Good.Release.mkv", b"payload"));
    let mut lines = Vec::new();
    let report = audit::run(&harness.verify, &source, options(), |line| {
        lines.push(line.to_owned());
    })
    .await?;
    assert_eq!(report.deferred, None);
    let verdicts: Vec<(i64, &str)> = report
        .records
        .iter()
        .map(|record| (record.id, record.verdict.as_str()))
        .collect();
    assert_eq!(verdicts, vec![(good, "sampled"), (missing, "unknown")]);
    assert_eq!(report.records[1].reason, "Release NZB is unavailable.");
    assert_eq!(lines.len(), 2);
    let written = std::fs::read_to_string(harness.verify.config.storage_path.join("audit.jsonl"))?;
    assert_eq!(written, format!("{}\n{}\n", lines[0], lines[1]));

    // The proven audit is finished; the inconclusive one waits for its retry.
    let retries = count(
        &pool,
        "SELECT COUNT(*) FROM release_verification_audit_retries WHERE release_id = ?",
        missing,
    )
    .await?;
    assert_eq!(retries, 1);
    let open = count(
        &pool,
        "SELECT COUNT(*) FROM release_verification_scratch WHERE audit_release_id = ? AND terminal = 0",
        missing,
    )
    .await?;
    assert_eq!(open, 1);
    let finished = count(
        &pool,
        "SELECT COUNT(*) FROM release_verification_scratch WHERE audit_release_id = ? AND terminal = 1 \
         AND audit_verdict = 'sampled'",
        good,
    )
    .await?;
    assert_eq!(finished, 1);
    let (cursor, retry_turn): (i64, bool) = sqlx::query_as(
        "SELECT CAST(audit_cursor AS SIGNED), audit_retry_turn FROM release_verification_state WHERE id = 1",
    )
    .fetch_one(&pool)
    .await?;
    assert_eq!((cursor, retry_turn), (missing, true));

    // A resumed run with nothing due and nothing past the cursor attempts nothing.
    let resumed = audit::run(
        &harness.verify,
        &source,
        AuditOptions {
            resume: true,
            ..options()
        },
        |_| {},
    )
    .await?;
    assert!(resumed.records.is_empty());

    // Once due, the retry reuses the open audit rather than starting another.
    sqlx::query("UPDATE release_verification_audit_retries SET next_attempt_at = '2000-01-01'")
        .execute(&pool)
        .await?;
    let retried = audit::run(
        &harness.verify,
        &source,
        AuditOptions {
            resume: true,
            ..options()
        },
        |_| {},
    )
    .await?;
    assert_eq!(retried.records.len(), 1);
    assert_eq!(retried.records[0].id, missing);
    let audits = count(
        &pool,
        "SELECT COUNT(*) FROM release_verification_scratch WHERE audit_release_id = ?",
        missing,
    )
    .await?;
    assert_eq!(audits, 1);
    harness.finish().await
}

#[tokio::test]
async fn a_truncated_partial_is_deleted_only_when_asked() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let pool = harness.db();
    let guid = "c0000000-0000-0000-0000-000000000003";
    // Published with two files and two articles; its NZB now holds one of each.
    let id = release(
        &pool,
        guid,
        Some(&nzb_document(&[("Part.One.mkv", "one@x")])),
        Some((2, 2)),
    )
    .await?;
    let source = FakeSource::default();

    let dry = audit::run(
        &harness.verify,
        &source,
        AuditOptions {
            delete: true,
            dry_run: true,
            ..options()
        },
        |_| {},
    )
    .await?;
    assert_eq!(dry.records.len(), 1);
    assert_eq!(dry.records[0].verdict, "unrepairable");
    assert_eq!(
        dry.records[0].reason,
        "Partial release NZB holds 1 of 2 recorded articles and 1 of 2 recorded files."
    );
    assert!(!dry.records[0].deleted);
    assert_eq!(
        count(&pool, "SELECT COUNT(*) FROM releases WHERE id = ?", id).await?,
        1
    );

    let deleted = audit::run(
        &harness.verify,
        &source,
        AuditOptions {
            delete: true,
            ..options()
        },
        |_| {},
    )
    .await?;
    assert!(deleted.records[0].deleted);
    assert_eq!(
        count(&pool, "SELECT COUNT(*) FROM releases WHERE id = ?", id).await?,
        0
    );
    assert!(
        !nzb::exists(&pool, guid).await?,
        "the NZB goes with its release"
    );
    assert_eq!(
        count(
            &pool,
            "SELECT COUNT(*) FROM search_index_failures WHERE release_id = ? AND operation = 'delete'",
            id
        )
        .await?,
        1
    );

    // The worker's cleanup finishes what the deletion left.
    let (terminal, verdict, delete_guid, delete_paths): (bool, Option<String>, String, Option<String>) =
        sqlx::query_as(
            "SELECT terminal, audit_verdict, delete_guid, delete_paths FROM release_verification_scratch \
             WHERE audit_release_id = ? AND delete_guid IS NOT NULL",
        )
        .bind(id)
        .fetch_one(&pool)
        .await?;
    let entry = DeletedAudit {
        terminal,
        audit_release_id: Some(id),
        audit_verdict: verdict,
        delete_guid,
        delete_paths,
    };
    audit::cleanup(&pool, &entry).await?;

    // A cleanup never removes anything for a release that exists again under that GUID.
    release(&pool, guid, None, None).await?;
    let refused = audit::cleanup(&pool, &entry).await.expect_err("refused");
    assert_eq!(
        refused.to_string(),
        "Audit cleanup cannot remove a current release."
    );
    harness.finish().await
}

#[tokio::test]
async fn audits_defer_to_due_candidates_and_running_workers() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let pool = harness.db();
    release(&pool, "d0000000-0000-0000-0000-000000000004", None, None).await?;
    let source = FakeSource::default();

    let mut worker = nntmux_verify::locks::WorkerLocks::new(&pool, &harness.verify.config).await?;
    assert!(worker.start(0).await?);
    let busy = audit::run(&harness.verify, &source, options(), |_| {}).await?;
    assert_eq!(busy.deferred, Some(Deferred::WorkerPriority));
    worker.close().await;

    sqlx::query(
        "INSERT INTO release_verification_candidates (identity, groups_id, manifest_path, manifest_bytes, \
         manifest_hash, status, last_unique_at, next_attempt_at) \
         VALUES (REPEAT('a', 40), 1, 'm', 1, REPEAT('0', 64), 'ready', '2000-01-01', '2000-01-01')",
    )
    .execute(&pool)
    .await?;
    let due = audit::run(&harness.verify, &source, options(), |_| {}).await?;
    assert_eq!(due.deferred, Some(Deferred::CandidatesDue));
    assert!(due.records.is_empty());
    harness.finish().await
}

#[tokio::test]
async fn an_audit_stops_writing_once_its_lock_session_is_lost() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let pool = harness.db();
    let id = release(
        &pool,
        "e0000000-0000-0000-0000-000000000005",
        Some(&nzb_document(&[("Lost.mkv", "lost@x")])),
        None,
    )
    .await?;
    let source = SessionKiller { pool: pool.clone() };
    let failure = audit::run(&harness.verify, &source, options(), |_| {})
        .await
        .expect_err("the gate was lost");
    assert_eq!(failure.to_string(), "Verification worker locks were lost.");
    // The audit was registered before the check, but no verdict, retry or cursor was written.
    let verdicts = count(
        &pool,
        "SELECT COUNT(*) FROM release_verification_scratch WHERE audit_release_id = ? \
         AND audit_verdict IS NOT NULL",
        id,
    )
    .await?;
    assert_eq!(verdicts, 0);
    let retries = count(
        &pool,
        "SELECT COUNT(*) FROM release_verification_audit_retries WHERE release_id = ?",
        id,
    )
    .await?;
    assert_eq!(retries, 0);
    harness.finish().await
}
