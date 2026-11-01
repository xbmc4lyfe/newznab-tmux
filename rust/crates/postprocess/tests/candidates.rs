//! The candidate queries and claims against a real database.

use anyhow::Result;
use nntmux_postprocess::additional::candidates::{self, BacklogCounts, Scope};
use nntmux_postprocess::config::Settings;
use nntmux_testkit::TestDb;
use sqlx::MySqlPool;

fn scope(check_passworded_rars: bool) -> Scope {
    Scope::from_settings(&Settings::default(), check_passworded_rars)
}

async fn release(pool: &MySqlPool, guid: &str, set: &str) -> Result<i64> {
    let id: u64 = sqlx::query(sqlx::AssertSqlSafe(format!(
        "INSERT INTO releases (name, searchname, totalpart, groups_id, fromname, size, postdate, \
         adddate, guid, leftguid, categories_id, haspreview, passwordstatus, nzbstatus) VALUES \
         ('n', 's', 1, 1, 'f', 5000000, '2026-01-01 00:00:00', '2026-01-01 00:00:00', '{guid}', \
         '{}', 10, -1, -1, 1)",
        &guid[..1]
    )))
    .execute(pool)
    .await?
    .last_insert_id();
    if !set.is_empty() {
        sqlx::query(sqlx::AssertSqlSafe(format!(
            "UPDATE releases SET {set} WHERE id = {id}"
        )))
        .execute(pool)
        .await?;
    }
    Ok(i64::try_from(id)?)
}

#[tokio::test]
async fn only_real_candidates_count() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    sqlx::query("UPDATE categories SET disablepreview = 1 WHERE id = 20")
        .execute(pool)
        .await?;
    let good = release(pool, "a000", "").await?;
    release(pool, "a001", "passwordstatus = 0").await?;
    release(pool, "a002", "haspreview = 0").await?;
    release(pool, "a003", "nzbstatus = 0").await?;
    release(pool, "a004", "late_article_candidate_id = 9").await?;
    release(pool, "a005", "size = 10").await?;
    release(pool, "a006", "size = 999999999999").await?;
    release(pool, "a007", "archive_retry_at = NOW() + INTERVAL 1 DAY").await?;
    release(pool, "a008", "categories_id = 20").await?;
    release(pool, "b000", "additional_pp_claimed_at = UTC_TIMESTAMP()").await?;
    release(
        pool,
        "c000",
        "additional_pp_claimed_at = UTC_TIMESTAMP() - INTERVAL 1 DAY",
    )
    .await?;

    let open = scope(false);
    assert_eq!(
        candidates::backlog_counts(pool, &open).await?,
        BacklogCounts {
            total: 4,
            available: 3
        }
    );
    let buckets = candidates::bucket_backlog(pool, &open).await?;
    let summary: Vec<_> = buckets
        .iter()
        .map(|b| (b.bucket.as_str(), b.total, b.available))
        .collect();
    assert_eq!(summary, [("a", 2, 2), ("b", 1, 0), ("c", 1, 1)]);
    assert_eq!(
        candidates::available_bucket_counts(pool, &open).await?,
        [("a".to_owned(), 2), ("c".to_owned(), 1)]
    );
    assert_eq!(candidates::claim_counts(pool, &open).await?, (1, 1));

    // With password checks on, a release already marked clear isn't pending.
    let strict = scope(true);
    assert_eq!(candidates::backlog_counts(pool, &strict).await?.total, 3);

    let token = candidates::new_token();
    let claimed = candidates::claim_batch(pool, &strict, "a", 10, &token, 0, &[]).await?;
    assert_eq!(claimed.iter().map(|r| r.id).collect::<Vec<_>>(), [good]);
    db.drop_database().await
}

#[tokio::test]
async fn claims_are_exclusive_ordered_and_clearable() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let old = release(pool, "a100", "postdate = '2020-01-01 00:00:00'").await?;
    let new = release(pool, "a101", "postdate = '2026-06-01 00:00:00'").await?;
    let clear = release(
        pool,
        "a102",
        "passwordstatus = 0, postdate = '2024-01-01 00:00:00'",
    )
    .await?;
    let other = release(pool, "b100", "").await?;
    let open = scope(false);

    let first = candidates::new_token();
    let batch = candidates::claim_batch(pool, &open, "a", 2, &first, 0, &[]).await?;
    assert_eq!(batch.iter().map(|r| r.id).collect::<Vec<_>>(), [new, clear]);
    assert_eq!(batch[0].claim_token.as_deref(), Some(first.as_str()));

    let second = candidates::new_token();
    let batch = candidates::claim_batch(pool, &open, "a", 2, &second, 0, &[]).await?;
    assert_eq!(batch.iter().map(|r| r.id).collect::<Vec<_>>(), [old]);
    assert!(
        candidates::claim_batch(pool, &open, "a", 2, &second, 0, &[])
            .await?
            .is_empty()
    );

    // Someone else's token doesn't release the claim; the holder's does.
    candidates::clear_claim(pool, new, Some(&second)).await?;
    assert!(
        candidates::claim_batch(pool, &open, "a", 2, &second, 0, &[])
            .await?
            .is_empty()
    );
    candidates::clear_claim(pool, new, Some(&first)).await?;
    let batch = candidates::claim_batch(pool, &open, "a", 2, &second, 0, &[]).await?;
    assert_eq!(batch.iter().map(|r| r.id).collect::<Vec<_>>(), [new]);

    // A worker's own released ids stay out of its later batches.
    candidates::clear_claim(pool, new, None).await?;
    assert!(
        candidates::claim_batch(pool, &open, "a", 2, &second, 0, &[new])
            .await?
            .is_empty()
    );

    let batch = candidates::claim_batch(pool, &open, "b", 5, &second, 1, &[]).await?;
    assert_eq!(batch.iter().map(|r| r.id).collect::<Vec<_>>(), [other]);
    db.drop_database().await
}

#[tokio::test]
async fn parallel_workers_never_share_a_release() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = db.pool.clone();
    for n in 0..40 {
        release(&pool, &format!("a{n:03}"), "").await?;
    }
    let open = scope(false);
    let mut workers = Vec::new();
    for _ in 0..4 {
        let pool = pool.clone();
        workers.push(tokio::spawn(async move {
            let token = candidates::new_token();
            let mut ids = Vec::new();
            loop {
                let batch = candidates::claim_batch(&pool, &open, "a", 3, &token, 0, &[]).await?;
                if batch.is_empty() {
                    return anyhow::Ok(ids);
                }
                ids.extend(batch.into_iter().map(|release| release.id));
            }
        }));
    }
    let mut all = Vec::new();
    for worker in workers {
        all.extend(worker.await??);
    }
    let claimed = all.len();
    all.sort_unstable();
    all.dedup();
    assert_eq!(all.len(), claimed, "a release was claimed twice");
    assert_eq!(claimed, 40);
    db.drop_database().await
}
