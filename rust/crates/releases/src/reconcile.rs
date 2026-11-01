//! Keeps collection state in step with stored parts: parts are the authority for binary counts and
//! sizes, and binary aggregates are then the authority for collection readiness and filesize.
//!
//! Header storage writes the same binaries and collections inside its own transactions, and an
//! `UPDATE` that joins parts locks every parts row it reads, which deadlocked with it. So aggregates
//! are read with plain `SELECT`s, which take no locks, and only rows that drifted are written, each
//! by primary key and only if it still holds the values read: if header storage changed it meanwhile,
//! its own aggregate refresh is newer.

use std::collections::HashMap;
use std::time::{Duration, Instant};

use anyhow::Result;
use chrono::{NaiveDateTime, TimeDelta};
use nntmux_verify::candidates::now;
use nntmux_verify::collections::{
    STATUS_COMPLETE_COLLECTION, STATUS_COMPLETE_PARTS, STATUS_DEFAULT, STATUS_LEGACY,
};
use nntmux_verify::in_tx;
use serde::{Deserialize, Serialize};
use sqlx::{MySql, MySqlPool, QueryBuilder};

use crate::Releases;
use crate::settings::{Limits, Settings, stop_requested};
use nntmux_runtime::downtime;

const RECONCILE_CURSOR: &str = "cbp:release-reconcile:cursor:";

/// Statuses the walk revisits: in progress, plus the legacy 10.
const STATUSES: &str = "0, 1, 2, 15, 16, 10";
/// The same without `CompleteParts`, which is always in scope however new.
const WAITING_STATUSES: &str = "0, 1, 15, 16, 10";

#[derive(Debug, Serialize, Deserialize)]
struct Cursor {
    last_id: i64,
    max_id: i64,
}

/// The stored walk position and the snapshot ceiling of its cycle; a missing or finished cursor
/// starts a new cycle at the current highest id.
async fn load_cursor(releases: &Releases, key: &str) -> Result<(i64, i64)> {
    if let Some(saved) = releases.verify.cache.get::<Cursor>(key).await
        && saved.last_id >= 0
        && saved.max_id > saved.last_id
    {
        return Ok((saved.last_id, saved.max_id));
    }
    let max: Option<u32> = sqlx::query_scalar("SELECT MAX(id) FROM collections")
        .fetch_one(&releases.verify.db)
        .await?;
    Ok((0, i64::from(max.unwrap_or(0))))
}

/// Walks a bounded keyset page at a time. Stale incomplete collections stay in scope until retention
/// removes them, and a busy group can hold tens of millions, so one pass walks at most a page and
/// time budget and stores its position; the next resumes there and starts from the lowest id once
/// the walk reaches the end. Each cycle stops at the highest id present when it began, so collections
/// added meanwhile can't keep it from wrapping round to rows that became stale after the cursor
/// passed them.
pub struct ReconcileWalk<'a> {
    releases: &'a Releases,
    settings: &'a Settings,
    limits: &'a Limits,
    group: Option<i64>,
    key: String,
    last_id: i64,
    max_id: i64,
    pages: i64,
    deadline: Instant,
    finished: bool,
}

impl<'a> ReconcileWalk<'a> {
    pub async fn start(
        releases: &'a Releases,
        settings: &'a Settings,
        limits: &'a Limits,
        group: Option<i64>,
    ) -> Result<Self> {
        let scope = group.map_or_else(|| "all".to_owned(), |group| format!("group:{group}"));
        let key = format!("{RECONCILE_CURSOR}{scope}");
        let (last_id, max_id) = load_cursor(releases, &key).await?;
        Ok(Self {
            releases,
            settings,
            limits,
            group,
            key,
            last_id,
            max_id,
            pages: 0,
            deadline: Instant::now() + Duration::from_secs(limits.reconcile_pass_seconds),
            finished: false,
        })
    }

    /// Reconciles the next page and returns its ids; `None` when this pass is over. The position is
    /// stored before the caller creates releases, so a failed page isn't walked again first.
    pub async fn next_page(&mut self) -> Result<Option<Vec<i64>>> {
        let db = &self.releases.verify.db;
        if self.finished
            || self.pages >= self.limits.reconcile_pages_per_pass
            || (self.pages > 0 && Instant::now() >= self.deadline)
            || stop_requested(db).await?
        {
            return Ok(None);
        }
        let barrier = downtime::enter(db, self.settings.collection_delay_hours).await?;
        let page = async {
            let ids = page_ids(
                db,
                self.settings,
                self.limits,
                self.group,
                self.last_id,
                self.max_id,
            )
            .await?;
            if !ids.is_empty() {
                reconcile_collection_ids(db, self.settings, self.limits, &ids).await?;
            }
            anyhow::Ok(ids)
        }
        .await;
        barrier.release().await;
        let ids = page?;
        let cache = &self.releases.verify.cache;
        let Some(last) = ids.last().copied() else {
            cache.forget(&self.key).await?;
            return Ok(None);
        };
        self.last_id = last;
        self.finished =
            (ids.len() as i64) < self.limits.reconcile_batch_size || last >= self.max_id;
        if self.finished {
            cache.forget(&self.key).await?;
        } else {
            let cursor = Cursor {
                last_id: last,
                max_id: self.max_id,
            };
            cache.forever(&self.key, &cursor).await?;
        }
        self.pages += 1;
        Ok(Some(ids))
    }
}

async fn page_ids(
    db: &MySqlPool,
    settings: &Settings,
    limits: &Limits,
    group: Option<i64>,
    last_id: i64,
    max_id: i64,
) -> Result<Vec<i64>> {
    let mut query = QueryBuilder::<MySql>::new("SELECT id FROM collections WHERE id > ");
    query
        .push_bind(last_id)
        .push(" AND id <= ")
        .push_bind(max_id);
    query
        .push(" AND (filecheck = ")
        .push_bind(STATUS_COMPLETE_PARTS);
    query.push(" OR (filecheck IN (");
    query.push(WAITING_STATUSES);
    query.push(") AND COALESCE(dateadded, added) < ");
    query.push_bind(now() - TimeDelta::hours(settings.collection_delay_hours));
    query.push("))");
    if let Some(group) = group {
        query.push(" AND groups_id = ").push_bind(group);
    }
    query
        .push(" ORDER BY id LIMIT ")
        .push_bind(limits.reconcile_batch_size);
    let rows: Vec<(u32,)> = query.build_query_as().fetch_all(db).await?;
    Ok(rows.into_iter().map(|(id,)| i64::from(id)).collect())
}

/// Recomputes binary and collection aggregates from stored parts.
pub async fn reconcile_collection_ids(
    db: &MySqlPool,
    settings: &Settings,
    limits: &Limits,
    ids: &[i64],
) -> Result<()> {
    reconcile_binaries(db, limits, ids).await?;
    reconcile_collections(db, settings, limits, ids).await
}

async fn reconcile_binaries(db: &MySqlPool, limits: &Limits, collections: &[i64]) -> Result<()> {
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT id, totalparts, currentparts, partsize, partcheck FROM binaries WHERE collections_id IN (",
    );
    let mut separated = query.separated(",");
    for id in collections {
        separated.push_bind(*id);
    }
    query.push(") ORDER BY id");
    let binaries: Vec<(u64, u32, u32, u64, bool)> = query.build_query_as().fetch_all(db).await?;
    for chunk in binaries.chunks(limits.sql_chunk_size) {
        let mut query = QueryBuilder::<MySql>::new(
            "SELECT binaries_id, COUNT(*), CAST(COALESCE(SUM(size), 0) AS UNSIGNED) FROM parts WHERE binaries_id IN (",
        );
        let mut separated = query.separated(",");
        for (id, ..) in chunk {
            separated.push_bind(*id);
        }
        query.push(") GROUP BY binaries_id");
        let parts: Vec<(u64, i64, u64)> = query.build_query_as().fetch_all(db).await?;
        let parts: HashMap<u64, (i64, u64)> = parts
            .into_iter()
            .map(|(id, count, size)| (id, (count, size)))
            .collect();
        for (id, totalparts, currentparts, partsize, partcheck) in chunk {
            let (count, size) = parts.get(id).copied().unwrap_or((0, 0));
            let complete = count >= i64::from(*totalparts);
            if count == i64::from(*currentparts) && size == *partsize && complete == *partcheck {
                continue;
            }
            sqlx::query(
                "UPDATE binaries SET currentparts = ?, partsize = ?, partcheck = ? \
                 WHERE id = ? AND currentparts = ? AND partsize = ?",
            )
            .bind(count)
            .bind(size)
            .bind(complete)
            .bind(id)
            .bind(currentparts)
            .bind(partsize)
            .execute(db)
            .await?;
        }
    }
    Ok(())
}

#[derive(sqlx::FromRow)]
struct CollectionState {
    #[sqlx(try_from = "u32")]
    id: i64,
    #[sqlx(try_from = "u32")]
    totalfiles: i64,
    filecheck: i8,
    #[sqlx(try_from = "u64")]
    filesize: i64,
    dateadded: Option<NaiveDateTime>,
    added: Option<NaiveDateTime>,
}

struct Change {
    id: i64,
    old: (i8, i64, i64),
    new: (i64, i8),
}

async fn reconcile_collections(
    db: &MySqlPool,
    settings: &Settings,
    limits: &Limits,
    ids: &[i64],
) -> Result<()> {
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT id, totalfiles, filecheck, filesize, dateadded, CAST(added AS DATETIME) AS added FROM collections WHERE id IN (",
    );
    let mut separated = query.separated(",");
    for id in ids {
        separated.push_bind(*id);
    }
    query.push(") AND filecheck IN (");
    query.push(STATUSES);
    query.push(") ORDER BY id");
    let collections: Vec<CollectionState> = query.build_query_as().fetch_all(db).await?;
    if collections.is_empty() {
        return Ok(());
    }
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT collections_id, COUNT(*), CAST(COALESCE(SUM(CASE WHEN partcheck = 1 THEN 1 ELSE 0 END), 0) AS SIGNED), \
         CAST(COALESCE(SUM(partsize), 0) AS UNSIGNED) FROM binaries WHERE collections_id IN (",
    );
    let mut separated = query.separated(",");
    for row in &collections {
        separated.push_bind(row.id);
    }
    query.push(") GROUP BY collections_id");
    let rows: Vec<(u32, i64, i64, u64)> = query.build_query_as().fetch_all(db).await?;
    let aggregates: HashMap<i64, (i64, i64, u64)> = rows
        .into_iter()
        .map(|(id, current, complete, size)| (i64::from(id), (current, complete, size)))
        .collect();
    let stale_before = now() - TimeDelta::hours(settings.collection_delay_hours);
    let mut changes = Vec::new();
    for collection in &collections {
        let (current, complete, size) =
            aggregates.get(&collection.id).copied().unwrap_or((0, 0, 0));
        let size = size as i64;
        let created = collection.dateadded.or(collection.added);
        let stale = created.is_some_and(|at| at < stale_before)
            && matches!(
                collection.filecheck,
                STATUS_DEFAULT | STATUS_COMPLETE_COLLECTION | STATUS_LEGACY
            );
        let total = collection.totalfiles;
        let ready = total > 0 && (current == total || current == total + 1) && complete >= total;
        let filecheck = if ready || stale {
            STATUS_COMPLETE_PARTS
        } else {
            collection.filecheck
        };
        if size == collection.filesize && filecheck == collection.filecheck {
            continue;
        }
        changes.push(Change {
            id: collection.id,
            old: (
                collection.filecheck,
                collection.totalfiles,
                collection.filesize,
            ),
            new: (size, filecheck),
        });
    }
    write_collection_aggregates(db, limits, &changes).await
}

/// Writes changed aggregates in one `UPDATE` per batch, since a group can have millions of stale
/// collections. Each row is written only if it holds the values read. Header storage locks collections
/// in a different order inside its own transactions, so a batch can lose a deadlock and is retried;
/// when retries run out the error propagates, so later stages never size rows whose aggregates
/// weren't written.
async fn write_collection_aggregates(
    db: &MySqlPool,
    limits: &Limits,
    changes: &[Change],
) -> Result<()> {
    for chunk in changes.chunks(limits.sql_chunk_size.min(500)) {
        in_tx!(db, 5, |tx| {
            let mut query = QueryBuilder::<MySql>::new("UPDATE collections SET filesize = CASE id");
            for change in chunk {
                query
                    .push(" WHEN ")
                    .push_bind(change.id)
                    .push(" THEN ")
                    .push_bind(change.new.0);
            }
            query.push(" END, filecheck = CASE id");
            for change in chunk {
                query
                    .push(" WHEN ")
                    .push_bind(change.id)
                    .push(" THEN ")
                    .push_bind(change.new.1);
            }
            query.push(" END WHERE id IN (");
            let mut separated = query.separated(",");
            for change in chunk {
                separated.push_bind(change.id);
            }
            query.push(") AND CASE id");
            for change in chunk {
                let (check, total, size) = change.old;
                query.push(" WHEN ").push_bind(change.id);
                query.push(" THEN (filecheck = ").push_bind(check);
                query.push(" AND totalfiles = ").push_bind(total);
                query.push(" AND filesize = ").push_bind(size).push(")");
            }
            query.push(" ELSE 0 END");
            query.build().execute(&mut *tx).await?;
            Ok::<(), anyhow::Error>(())
        })?;
    }
    Ok(())
}
