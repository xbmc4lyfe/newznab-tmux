//! The one definition of "needs additional processing". The bucket fan-out, the diagnostics and
//! each worker's claim all build on the predicates here, because a mismatch between them once
//! left releases advertised and never claimed. Port of `AdditionalCandidateQuery`.
//!
//! The PHP checked that the claim columns and `late_article_candidate_id` existed. The baseline
//! schema has them, so the checks are gone.

use anyhow::Result;
use chrono::{NaiveDateTime, TimeDelta, Utc};
use nntmux_verify::in_tx;
use rand::Rng;
use sqlx::{MySql, MySqlConnection, MySqlPool, QueryBuilder};

use crate::config::{Config, Settings};

/// Candidates read per claimed release, so rows held by other workers can be skipped.
const CLAIM_CANDIDATE_FACTOR: i64 = 4;

/// The size and password settings every predicate depends on.
#[derive(Clone, Copy, Debug)]
pub struct Scope {
    pub min_size: i64,
    pub max_size: i64,
    pub check_passworded_rars: bool,
    pub claim_ttl_seconds: i64,
}

impl Scope {
    pub fn new(config: &Config) -> Self {
        Self::from_settings(&config.settings, config.check_passworded_rars)
    }

    pub fn from_settings(settings: &Settings, check_passworded_rars: bool) -> Self {
        Self {
            min_size: settings.min_size_to_post_process.max(0),
            max_size: settings.max_size_to_post_process.max(0),
            check_passworded_rars,
            claim_ttl_seconds: settings.claim_ttl_seconds(),
        }
    }
}

/// One GUID bucket's backlog.
#[derive(Clone, Debug, PartialEq, Eq, serde::Serialize)]
pub struct BucketBacklog {
    pub bucket: String,
    pub total: i64,
    pub available: i64,
}

/// The whole backlog.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct BacklogCounts {
    pub total: i64,
    pub available: i64,
}

/// A claimed release, with the columns processing reads.
#[derive(Clone, Debug, sqlx::FromRow)]
pub struct ClaimedRelease {
    #[sqlx(try_from = "u32")]
    pub id: i64,
    pub guid: String,
    pub name: String,
    pub searchname: String,
    #[sqlx(try_from = "u64")]
    pub size: i64,
    #[sqlx(try_from = "u32")]
    pub groups_id: i64,
    #[sqlx(try_from = "i8")]
    pub nfostatus: i64,
    pub fromname: Option<String>,
    pub completion: f64,
    #[sqlx(try_from = "i32")]
    pub categories_id: i64,
    #[sqlx(try_from = "u32")]
    pub predb_id: i64,
    #[sqlx(try_from = "i8")]
    pub pp_timeout_count: i64,
    #[sqlx(rename = "additional_pp_claim_token")]
    pub claim_token: Option<String>,
}

/// Which optional filters a query applies.
#[derive(Clone, Copy, Debug, Default)]
pub struct Filter<'a> {
    pub group_id: i64,
    pub guid_char: &'a str,
    pub include_claimed: bool,
}

/// A fresh 32-character claim token.
pub fn new_token() -> String {
    hex::encode(rand::rng().random::<[u8; 16]>())
}

fn now() -> NaiveDateTime {
    Utc::now().naive_utc()
}

/// Claims older than this are abandoned, and another worker may take them.
pub fn claim_stale_before(scope: &Scope) -> NaiveDateTime {
    now() - TimeDelta::seconds(scope.claim_ttl_seconds)
}

fn push_password_status(query: &mut QueryBuilder<MySql>, scope: &Scope) {
    // Insertion stores zero when password checks are off, and pending media still needs work.
    query.push(if scope.check_passworded_rars {
        " AND r.passwordstatus = -1"
    } else {
        " AND r.passwordstatus IN (-1, 0)"
    });
}

fn push_retry_window(query: &mut QueryBuilder<MySql>) {
    query.push(" AND (r.archive_retry_at IS NULL OR r.archive_retry_at <= ");
    query.push_bind(now());
    query.push(")");
}

fn push_claim_window(query: &mut QueryBuilder<MySql>, scope: &Scope) {
    query.push(" AND (r.additional_pp_claimed_at IS NULL OR r.additional_pp_claimed_at < ");
    query.push_bind(claim_stale_before(scope));
    query.push(")");
}

/// Everything except the password status.
fn push_processing(
    query: &mut QueryBuilder<MySql>,
    scope: &Scope,
    filter: Filter<'_>,
    joined_category: bool,
) {
    query
        .push(" AND r.haspreview = -1 AND r.nzbstatus = 1 AND r.late_article_candidate_id IS NULL");
    // A scalar primary-key lookup can't reorder the queue through a join before the LIMIT.
    query.push(if joined_category {
        " AND c.disablepreview = 0"
    } else {
        " AND (SELECT c.disablepreview FROM categories c WHERE c.id = r.categories_id) = 0"
    });
    push_retry_window(query);
    if scope.min_size > 0 {
        query.push(" AND r.size > ");
        query.push_bind(scope.min_size);
    }
    if scope.max_size > 0 {
        query.push(" AND r.size < ");
        query.push_bind(scope.max_size);
    }
    if filter.group_id != 0 {
        query.push(" AND r.groups_id = ");
        query.push_bind(filter.group_id);
    }
    if !filter.guid_char.is_empty() {
        query.push(" AND r.leftguid = ");
        query.push_bind(filter.guid_char.to_owned());
    }
    if !filter.include_claimed {
        push_claim_window(query, scope);
    }
}

fn counts_query(scope: &Scope, select_bucket: bool) -> QueryBuilder<MySql> {
    let mut query = QueryBuilder::new("SELECT ");
    if select_bucket {
        query.push("r.leftguid AS bucket, ");
    }
    query.push(
        "COUNT(*), CAST(COALESCE(SUM(CASE WHEN r.additional_pp_claimed_at IS NULL OR r.additional_pp_claimed_at < ",
    );
    query.push_bind(claim_stale_before(scope));
    query.push(
        " THEN 1 ELSE 0 END), 0) AS SIGNED) FROM releases r \
         LEFT JOIN categories c ON c.id = r.categories_id WHERE 1 = 1",
    );
    push_password_status(&mut query, scope);
    push_processing(
        &mut query,
        scope,
        Filter {
            include_claimed: true,
            ..Filter::default()
        },
        true,
    );
    query
}

/// Total and claimable candidates in every GUID bucket.
pub async fn bucket_backlog(pool: &MySqlPool, scope: &Scope) -> Result<Vec<BucketBacklog>> {
    let mut query = counts_query(scope, true);
    query.push(" GROUP BY r.leftguid ORDER BY r.leftguid");
    let rows: Vec<(String, i64, i64)> = query.build_query_as().fetch_all(pool).await?;
    Ok(rows
        .into_iter()
        .filter_map(|(bucket, total, available)| {
            let bucket = bucket.get(..1)?.to_ascii_lowercase();
            Some(BucketBacklog {
                bucket,
                total,
                available,
            })
        })
        .collect())
}

/// Claimable candidates per bucket, leaving out buckets with none.
pub async fn available_bucket_counts(
    pool: &MySqlPool,
    scope: &Scope,
) -> Result<Vec<(String, i64)>> {
    Ok(bucket_backlog(pool, scope)
        .await?
        .into_iter()
        .filter(|backlog| backlog.available > 0)
        .map(|backlog| (backlog.bucket, backlog.available))
        .collect())
}

/// Total and claimable candidates anywhere.
pub async fn backlog_counts(pool: &MySqlPool, scope: &Scope) -> Result<BacklogCounts> {
    let (total, available): (i64, i64) = counts_query(scope, false)
        .build_query_as()
        .fetch_one(pool)
        .await?;
    Ok(BacklogCounts { total, available })
}

/// Candidates with a claim, split into live and abandoned.
pub async fn claim_counts(pool: &MySqlPool, scope: &Scope) -> Result<(i64, i64)> {
    let mut query: QueryBuilder<MySql> =
        QueryBuilder::new("SELECT CAST(COALESCE(SUM(r.additional_pp_claimed_at >= ");
    query.push_bind(claim_stale_before(scope));
    query.push("), 0) AS SIGNED), CAST(COALESCE(SUM(r.additional_pp_claimed_at < ");
    query.push_bind(claim_stale_before(scope));
    query.push(
        "), 0) AS SIGNED) FROM releases r LEFT JOIN categories c ON c.id = r.categories_id \
         WHERE r.additional_pp_claimed_at IS NOT NULL",
    );
    push_password_status(&mut query, scope);
    push_processing(
        &mut query,
        scope,
        Filter {
            include_claimed: true,
            ..Filter::default()
        },
        true,
    );
    Ok(query.build_query_as().fetch_one(pool).await?)
}

/// Claims up to `limit` releases for one worker, newest first, skipping rows another worker has
/// locked. Candidates are picked without a lock, because `FOR UPDATE` would also lock the joined
/// category rows every release shares and serialize all workers.
pub async fn claim_batch(
    pool: &MySqlPool,
    scope: &Scope,
    guid_char: &str,
    limit: i64,
    token: &str,
    group_id: i64,
    excluded: &[i64],
) -> Result<Vec<ClaimedRelease>> {
    let limit = limit.max(1);
    let filter = Filter {
        group_id,
        guid_char,
        include_claimed: false,
    };
    in_tx!(pool, 3, |tx| {
        claim_in(tx, scope, filter, limit, token, excluded).await
    })
}

async fn claim_in(
    tx: &mut MySqlConnection,
    scope: &Scope,
    filter: Filter<'_>,
    limit: i64,
    token: &str,
    excluded: &[i64],
) -> Result<Vec<ClaimedRelease>> {
    let candidates = pick_candidates(tx, scope, filter, limit, excluded).await?;
    if candidates.is_empty() {
        return Ok(Vec::new());
    }
    let ids = lock_candidates(tx, scope, &candidates, limit).await?;
    if ids.is_empty() {
        return Ok(Vec::new());
    }
    let mut update = QueryBuilder::<MySql>::new("UPDATE releases SET additional_pp_claimed_at = ");
    update.push_bind(now());
    update.push(", additional_pp_claim_token = ");
    update.push_bind(token.to_owned());
    update.push(" WHERE id IN (");
    push_ids(&mut update, &ids);
    update.push(")");
    update.build().execute(&mut *tx).await?;

    let mut select = QueryBuilder::<MySql>::new(
        "SELECT id, guid, name, searchname, size, groups_id, nfostatus, fromname, completion, \
         categories_id, predb_id, pp_timeout_count, additional_pp_claim_token \
         FROM releases WHERE id IN (",
    );
    push_ids(&mut select, &ids);
    select.push(") ORDER BY FIELD(id, ");
    push_ids(&mut select, &ids);
    select.push(")");
    Ok(select.build_query_as().fetch_all(&mut *tx).await?)
}

fn push_ids(query: &mut QueryBuilder<MySql>, ids: &[i64]) {
    let mut separated = query.separated(", ");
    for id in ids {
        separated.push_bind(*id);
    }
}

async fn pick_candidates(
    tx: &mut MySqlConnection,
    scope: &Scope,
    filter: Filter<'_>,
    limit: i64,
    excluded: &[i64],
) -> Result<Vec<i64>> {
    let window = limit * CLAIM_CANDIDATE_FACTOR;
    let branch = |status: i64, joined: bool| {
        let mut query: QueryBuilder<MySql> =
            QueryBuilder::new("SELECT r.id, r.postdate FROM releases r");
        if joined {
            query.push(" LEFT JOIN categories c ON c.id = r.categories_id");
        }
        query.push(" WHERE r.passwordstatus = ");
        query.push_bind(status);
        push_processing(&mut query, scope, filter, joined);
        if !excluded.is_empty() {
            query.push(" AND r.id NOT IN (");
            push_ids(&mut query, excluded);
            query.push(")");
        }
        query.push(" ORDER BY r.postdate DESC, r.id ASC LIMIT ");
        query.push_bind(window);
        query
    };
    if scope.check_passworded_rars {
        let rows: Vec<(u32, Option<NaiveDateTime>)> = branch(-1, true)
            .build_query_as()
            .fetch_all(&mut *tx)
            .await?;
        return Ok(rows.into_iter().map(|(id, _)| i64::from(id)).collect());
    }
    // Each equality range keeps the queue index's order, so only two bounded prefixes get sorted
    // rather than the whole combined backlog.
    let mut unchecked: Vec<(u32, Option<NaiveDateTime>)> = branch(-1, false)
        .build_query_as()
        .fetch_all(&mut *tx)
        .await?;
    let disabled: Vec<(u32, Option<NaiveDateTime>)> = branch(0, false)
        .build_query_as()
        .fetch_all(&mut *tx)
        .await?;
    unchecked.extend(disabled);
    unchecked.sort_by(|a, b| b.1.cmp(&a.1).then(a.0.cmp(&b.0)));
    let window = usize::try_from(window)?;
    Ok(unchecked
        .into_iter()
        .take(window)
        .map(|(id, _)| i64::from(id))
        .collect())
}

/// Locks only release rows, in chunks in candidate order, skipping rows another worker holds.
/// Every predicate that can change between the pick and the lock is checked again.
async fn lock_candidates(
    tx: &mut MySqlConnection,
    scope: &Scope,
    candidates: &[i64],
    limit: i64,
) -> Result<Vec<i64>> {
    let wanted = usize::try_from(limit)?;
    let mut ids = Vec::with_capacity(wanted);
    for chunk in candidates.chunks(wanted) {
        // A locking read locks every row it scans; the primary key keeps that to this chunk.
        let mut query: QueryBuilder<MySql> =
            QueryBuilder::new("SELECT r.id FROM releases r FORCE INDEX (PRIMARY) WHERE r.id IN (");
        push_ids(&mut query, chunk);
        query.push(")");
        push_password_status(&mut query, scope);
        query.push(
            " AND r.haspreview = -1 AND r.nzbstatus = 1 AND r.late_article_candidate_id IS NULL",
        );
        push_retry_window(&mut query);
        push_claim_window(&mut query, scope);
        query.push(" FOR UPDATE SKIP LOCKED");
        let locked: Vec<(u32,)> = query.build_query_as().fetch_all(&mut *tx).await?;
        let locked: Vec<i64> = locked.into_iter().map(|(id,)| i64::from(id)).collect();
        ids.extend(chunk.iter().copied().filter(|id| locked.contains(id)));
        if ids.len() >= wanted {
            break;
        }
    }
    ids.truncate(wanted);
    Ok(ids)
}

/// Gives a claim back. With a token, only the holder's own claim is cleared.
pub async fn clear_claim(pool: &MySqlPool, release_id: i64, token: Option<&str>) -> Result<()> {
    let mut query: QueryBuilder<MySql> = QueryBuilder::new(
        "UPDATE releases SET additional_pp_claimed_at = NULL, additional_pp_claim_token = NULL WHERE id = ",
    );
    query.push_bind(release_id);
    if let Some(token) = token.filter(|token| !token.is_empty()) {
        query.push(" AND additional_pp_claim_token = ");
        query.push_bind(token.to_owned());
    }
    query.build().execute(pool).await?;
    Ok(())
}
