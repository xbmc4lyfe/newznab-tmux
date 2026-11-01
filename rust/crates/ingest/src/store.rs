//! Stores parsed headers as collections, binaries and parts, one transaction per chunk.
//!
//! Workers on the same group race on cross-posted collections, so every statement takes its locks
//! in key order. The aggregate refreshes lock the parent rows and read children with plain
//! SELECTs under READ COMMITTED, because an UPDATE joining `parts` deadlocked those writers.

use std::collections::{BTreeMap, BTreeSet, HashMap, HashSet};
use std::time::Duration;

use anyhow::{Context, Result, anyhow};
use async_trait::async_trait;
use nntmux_core::pcre::latin1;
use rand::Rng;
use sqlx::mysql::{MySqlConnection, MySqlDatabaseError};
use sqlx::{AssertSqlSafe, Connection, MySqlPool};
use tracing::{error, warn};

use crate::clean::{CollectionRegex, binary_hash, clean_subject, collection_hash, to_utf8};
use crate::groups::Group;
use crate::header::{
    Overview, SubjectMatch, file_counter, validation_error, xref_groups, xref_tokens,
};

const LOCK_RETRY_MAX: u32 = 10;
const SUBJECT_COLUMN_BYTES: usize = 255;

/// `CollectionFileCheckStatus` values that a collection can still be completed from.
const FILECHECK_IN_PROGRESS: [u8; 5] = [0, 1, 2, 15, 16];
const FILECHECK_SIZED: u8 = 3;

/// A header that passed parsing and filtering.
#[derive(Clone, Debug)]
pub struct ParsedHeader {
    pub overview: Overview,
    /// The base name, with ` yEnc` added if the subject had none.
    pub subject: SubjectMatch,
}

/// What happens inside the header transaction beyond ingest's own tables.
#[async_trait]
pub trait StoreHooks: Send + Sync {
    /// Late articles arrived for collections with a verification candidate in progress.
    async fn invalidate_candidates(
        &self,
        connection: &mut MySqlConnection,
        collections: &[u32],
    ) -> Result<()>;
}

/// Leaves verification candidates alone.
pub struct NoHooks;

#[async_trait]
impl StoreHooks for NoHooks {
    async fn invalidate_candidates(&self, _: &mut MySqlConnection, _: &[u32]) -> Result<()> {
        Ok(())
    }
}

#[derive(Clone, Copy, Debug)]
pub struct StoreLimits {
    pub header_chunk_size: usize,
    pub sql_chunk_size: usize,
    pub verification_required: bool,
}

/// Stores headers and returns the article numbers that didn't make it, for part repair.
pub async fn store(
    pool: &MySqlPool,
    hooks: &dyn StoreHooks,
    limits: StoreLimits,
    group: &Group,
    regexes: &[CollectionRegex],
    headers: &[ParsedHeader],
) -> Result<Vec<u64>> {
    let mut failed = BTreeSet::new();
    for chunk in headers.chunks(limits.header_chunk_size.max(1)) {
        let mut rejected: BTreeMap<&str, usize> = BTreeMap::new();
        let mut valid = Vec::with_capacity(chunk.len());
        for header in chunk {
            match validation_error(header.subject.part, &header.overview.message_id) {
                None => valid.push(prepare(header, group, regexes)),
                Some(reason) => {
                    *rejected.entry(reason).or_default() += 1;
                    failed.insert(header.overview.number);
                }
            }
        }
        if !rejected.is_empty() {
            warn!(
                group = group.id,
                ?rejected,
                "invalid binary headers rejected"
            );
        }
        if !valid.is_empty() {
            store_chunk(pool, hooks, limits, group, &valid, &mut failed).await?;
        }
    }
    Ok(failed.into_iter().collect())
}

/// Everything a header needs for the database, computed once however many retries it takes.
struct Prepared {
    number: u64,
    part: i64,
    message_id: String,
    bytes: i64,
    file_number: i64,
    total_files: i64,
    total_parts: i64,
    collection_hash: [u8; 20],
    collection_regex: i32,
    binary_hash: String,
    binary_name: String,
    subject: String,
    from: String,
    unixtime: i64,
    xref: String,
    group_names: Vec<String>,
}

fn prepare(header: &ParsedHeader, group: &Group, regexes: &[CollectionRegex]) -> Prepared {
    let base = &header.subject.base;
    let (file_number, total_files) = file_counter(base);
    let clean = clean_subject(regexes, base, group.name.as_bytes());
    let mut key_groups: Vec<String> = xref_groups(&header.overview.xref)
        .iter()
        .map(|name| to_utf8(name).into_owned())
        .collect();
    if key_groups.is_empty() {
        key_groups.push(group.name.clone());
    }
    let xref: Vec<String> = xref_tokens(&header.overview.xref)
        .iter()
        .map(|token| to_utf8(token).into_owned())
        .collect();
    let now = chrono::Utc::now().timestamp();
    let unixtime = match crate::header::header_time(&header.overview.date) {
        Some(time) if time.min(now) != 0 => time.min(now),
        _ => now,
    };
    let binary_name = to_utf8(base).into_owned();
    let message_id = crate::header::php_trim(&header.overview.message_id);
    Prepared {
        number: header.overview.number,
        part: header.subject.part,
        // Validation has already held this to printable ASCII.
        message_id: latin1(message_id),
        bytes: header.overview.bytes,
        file_number,
        total_files,
        total_parts: header.subject.total,
        collection_hash: collection_hash(&clean.name, total_files),
        collection_regex: clean.id,
        binary_hash: binary_hash(base, &header.overview.from, file_number),
        subject: truncate_to_boundary(&binary_name, SUBJECT_COLUMN_BYTES).to_owned(),
        binary_name,
        from: to_utf8(&header.overview.from).into_owned(),
        unixtime,
        xref: xref.join(" "),
        group_names: key_groups,
    }
}

fn truncate_to_boundary(text: &str, bytes: usize) -> &str {
    if text.len() <= bytes {
        return text;
    }
    let mut end = bytes;
    while !text.is_char_boundary(end) {
        end -= 1;
    }
    &text[..end]
}

async fn store_chunk(
    pool: &MySqlPool,
    hooks: &dyn StoreHooks,
    limits: StoreLimits,
    group: &Group,
    headers: &[Prepared],
    failed: &mut BTreeSet<u64>,
) -> Result<()> {
    let mut attempt = 0;
    loop {
        let Err(failure) = store_attempt(pool, hooks, limits, group.id, headers).await else {
            return Ok(());
        };
        attempt += 1;
        let retryable = is_transient_lock(&failure) || is_missing_parent(&failure);
        if attempt >= LOCK_RETRY_MAX || !retryable {
            // Only this chunk is lost. Its articles go to part repair, and the rest of the range
            // carries on.
            error!(
                group = group.id,
                articles = headers.len(),
                attempts = attempt,
                error = %describe(&failure),
                "binary header storage chunk rolled back"
            );
            failed.extend(headers.iter().map(|header| header.number));
            return Ok(());
        }
        let jitter = rand::rng().random_range(0..=25);
        let wait = 2000.min(100 * u64::from(attempt)) + jitter;
        tokio::time::sleep(Duration::from_millis(wait)).await;
    }
}

/// The driver's error text, without any statement or bindings.
fn describe(failure: &anyhow::Error) -> String {
    match database_error(failure) {
        Some(found) => format!("{} {}", found.number(), found.message()),
        None => format!("{failure:#}"),
    }
}

fn database_error(failure: &anyhow::Error) -> Option<&MySqlDatabaseError> {
    failure.chain().find_map(|cause| {
        cause
            .downcast_ref::<sqlx::Error>()?
            .as_database_error()?
            .try_downcast_ref::<MySqlDatabaseError>()
    })
}

/// InnoDB 1020 (record changed since last read), 1205 (lock wait timeout) and 1213 (deadlock).
pub fn is_transient_lock(failure: &anyhow::Error) -> bool {
    database_error(failure).is_some_and(|found| {
        matches!(found.number(), 1020 | 1205 | 1213) || found.code() == Some("40001")
    })
}

/// 1452: a child row names a parent that the releases or cleanup pass just deleted. A fresh
/// attempt finds or recreates the parent.
pub fn is_missing_parent(failure: &anyhow::Error) -> bool {
    database_error(failure).is_some_and(|found| found.number() == 1452)
}

/// One transaction over a chunk. Any failure rolls back the whole chunk.
async fn store_attempt(
    pool: &MySqlPool,
    hooks: &dyn StoreHooks,
    limits: StoreLimits,
    group: u32,
    headers: &[Prepared],
) -> Result<()> {
    let mut connection = pool.acquire().await?;
    // The aggregate refreshes read children with plain SELECTs, which under REPEATABLE READ
    // would come from a snapshot missing rows other header workers committed meanwhile.
    sqlx::raw_sql(AssertSqlSafe(
        "SET TRANSACTION ISOLATION LEVEL READ COMMITTED",
    ))
    .execute(&mut *connection)
    .await?;
    let mut transaction = connection.begin().await?;
    let chunk = limits.sql_chunk_size.max(1);

    let collections = resolve_collections(&mut transaction, group, headers, chunk).await?;
    let tracked = if limits.verification_required {
        lock_collections(&mut transaction, &collections).await?
    } else {
        Vec::new()
    };
    let binaries = resolve_binaries(&mut transaction, headers, &collections, chunk).await?;
    insert_parts(&mut transaction, headers, &collections, &binaries, chunk).await?;

    let touched: BTreeSet<u64> = binaries.values().copied().collect();
    refresh_binaries(&mut transaction, &touched, chunk).await?;
    let collection_ids: BTreeSet<u32> = collections.values().copied().collect();
    refresh_collections(&mut transaction, &collection_ids, chunk).await?;
    if !tracked.is_empty() {
        hooks
            .invalidate_candidates(&mut transaction, &tracked)
            .await
            .context("invalidating verification candidates")?;
    }
    transaction.commit().await?;
    Ok(())
}

type CollectionIds = HashMap<[u8; 20], u32>;

async fn resolve_collections(
    connection: &mut MySqlConnection,
    group: u32,
    headers: &[Prepared],
    chunk: usize,
) -> Result<CollectionIds> {
    // The first header of each collection supplies its row. Sorted by hash, so concurrent workers
    // upserting the same cross-posted collections take those locks in one order.
    let mut rows: BTreeMap<[u8; 20], &Prepared> = BTreeMap::new();
    for header in headers {
        rows.entry(header.collection_hash).or_insert(header);
    }
    let hashes: Vec<[u8; 20]> = rows.keys().copied().collect();
    let mut ids = select_collections(connection, &hashes, chunk).await?;

    let noise = hex::encode(rand::rng().random::<[u8; 8]>());
    let ordered: Vec<(&[u8; 20], &&Prepared)> = rows.iter().collect();
    for batch in ordered.chunks(chunk) {
        let values =
            vec!["(?, ?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, NOW(), ?)"; batch.len()].join(",");
        // `id = LAST_INSERT_ID(id)` doesn't rewrite the wide row of an existing collection.
        let sql = format!(
            "INSERT INTO collections (subject, fromname, date, xref, groups_id, totalfiles, \
             collectionhash, collection_regexes_id, dateadded, noise) VALUES {values} \
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        let mut query = sqlx::query(AssertSqlSafe(sql));
        for (hash, row) in batch {
            query = query
                .bind(&row.subject)
                .bind(&row.from)
                .bind(row.unixtime)
                .bind(&row.xref)
                .bind(group)
                .bind(row.total_files)
                .bind(&hash[..])
                .bind(row.collection_regex)
                .bind(&noise);
        }
        query.execute(&mut *connection).await?;
    }

    let new: Vec<[u8; 20]> = hashes
        .iter()
        .filter(|hash| !ids.contains_key(*hash))
        .copied()
        .collect();
    ids.extend(select_collections(connection, &new, chunk).await?);
    if let Some(hash) = hashes.iter().find(|hash| !ids.contains_key(*hash)) {
        return Err(anyhow!("collection {} didn't resolve", hex::encode(hash)));
    }

    // Cross-post groups are normalized, so the wide row isn't rewritten for each new article.
    let mut pairs: BTreeSet<(u32, &str)> = BTreeSet::new();
    for header in headers {
        let id = ids[&header.collection_hash];
        for name in &header.group_names {
            pairs.insert((id, name));
        }
    }
    let pairs: Vec<(u32, &str)> = pairs.into_iter().collect();
    for batch in pairs.chunks(chunk) {
        let values = vec!["(?, ?)"; batch.len()].join(",");
        let sql = format!(
            "INSERT IGNORE INTO collection_groups (collections_id, group_name) VALUES {values}"
        );
        let mut query = sqlx::query(AssertSqlSafe(sql));
        for (id, name) in batch {
            query = query.bind(id).bind(*name);
        }
        query.execute(&mut *connection).await?;
    }
    Ok(ids)
}

async fn select_collections(
    connection: &mut MySqlConnection,
    hashes: &[[u8; 20]],
    chunk: usize,
) -> Result<CollectionIds> {
    let mut ids = HashMap::with_capacity(hashes.len());
    for batch in hashes.chunks(chunk) {
        let marks = vec!["?"; batch.len()].join(",");
        let sql =
            format!("SELECT id, collectionhash FROM collections WHERE collectionhash IN ({marks})");
        let mut query = sqlx::query_as::<_, (u32, Vec<u8>)>(AssertSqlSafe(sql));
        for hash in batch {
            query = query.bind(&hash[..]);
        }
        for (id, hash) in query.fetch_all(&mut *connection).await? {
            if let Ok(hash) = <[u8; 20]>::try_from(hash) {
                ids.insert(hash, id);
            }
        }
    }
    Ok(ids)
}

/// Locks the chunk's collections against archiving, and returns those with a verification
/// candidate still in progress. A published partial keeps its inventory, so it counts too.
async fn lock_collections(
    connection: &mut MySqlConnection,
    collections: &CollectionIds,
) -> Result<Vec<u32>> {
    let ids: BTreeSet<u32> = collections.values().copied().collect();
    if ids.is_empty() {
        return Ok(Vec::new());
    }
    let marks = vec!["?"; ids.len()].join(",");
    // A covering secondary scan could lock tentative duplicate inserts outside this set.
    let sql = format!(
        "SELECT id FROM collections FORCE INDEX (PRIMARY) WHERE id IN ({marks}) ORDER BY id FOR UPDATE"
    );
    let mut query = sqlx::query_scalar::<_, u32>(AssertSqlSafe(sql));
    for id in &ids {
        query = query.bind(id);
    }
    let locked = query.fetch_all(&mut *connection).await?;

    let identities: HashMap<String, u32> = collections
        .iter()
        .map(|(hash, id)| (hex::encode(hash), *id))
        .collect();
    let marks = vec!["?"; identities.len()].join(",");
    let sql = format!(
        "SELECT source_identity FROM release_verification_candidates \
         WHERE source_identity IN ({marks}) AND (release_id IS NULL OR manifest_path <> '') \
         AND status NOT IN ('expired')"
    );
    let mut query = sqlx::query_scalar::<_, String>(AssertSqlSafe(sql));
    for identity in identities.keys() {
        query = query.bind(identity);
    }
    let active: HashSet<String> = query
        .fetch_all(&mut *connection)
        .await?
        .into_iter()
        .collect();
    if locked.len() != ids.len() {
        return Err(anyhow!(
            "a collection was archived during header resolution"
        ));
    }
    let mut tracked: Vec<u32> = active
        .iter()
        .filter_map(|identity| identities.get(identity).copied())
        .collect();
    tracked.sort_unstable();
    tracked.dedup();
    Ok(tracked)
}

type BinaryIds = HashMap<(u32, String), u64>;

async fn resolve_binaries(
    connection: &mut MySqlConnection,
    headers: &[Prepared],
    collections: &CollectionIds,
    chunk: usize,
) -> Result<BinaryIds> {
    // Sorted by the unique key, for the same reason as collections.
    let mut rows: BTreeMap<(u32, String), &Prepared> = BTreeMap::new();
    for header in headers {
        let collection = collections[&header.collection_hash];
        rows.entry((collection, header.binary_hash.clone()))
            .or_insert(header);
    }
    let keys: Vec<(u32, String)> = rows.keys().cloned().collect();
    let mut ids = select_binaries(connection, &keys, chunk).await?;

    let ordered: Vec<(&(u32, String), &&Prepared)> = rows.iter().collect();
    for batch in ordered.chunks(chunk) {
        let values = vec!["(UNHEX(?), ?, ?, ?, 0, ?, 0)"; batch.len()].join(",");
        let sql = format!(
            "INSERT INTO binaries (binaryhash, name, collections_id, totalparts, currentparts, \
             filenumber, partsize) VALUES {values} ON DUPLICATE KEY UPDATE \
             totalparts = GREATEST(totalparts, VALUES(totalparts)), id = LAST_INSERT_ID(id)"
        );
        let mut query = sqlx::query(AssertSqlSafe(sql));
        for ((collection, hash), row) in batch {
            query = query
                .bind(hash)
                .bind(&row.binary_name)
                .bind(collection)
                .bind(row.total_parts)
                .bind(row.file_number);
        }
        query.execute(&mut *connection).await?;
    }

    let new: Vec<(u32, String)> = keys
        .iter()
        .filter(|key| !ids.contains_key(*key))
        .cloned()
        .collect();
    ids.extend(select_binaries(connection, &new, chunk).await?);
    if let Some((collection, hash)) = keys.iter().find(|key| !ids.contains_key(*key)) {
        return Err(anyhow!(
            "binary {hash} of collection {collection} didn't resolve"
        ));
    }
    Ok(ids)
}

async fn select_binaries(
    connection: &mut MySqlConnection,
    keys: &[(u32, String)],
    chunk: usize,
) -> Result<BinaryIds> {
    let mut ids = HashMap::with_capacity(keys.len());
    for batch in keys.chunks(chunk) {
        let tuples = vec!["(?, UNHEX(?))"; batch.len()].join(",");
        // One statement per batch: lookups per collection would add round trips while this
        // transaction holds parent locks across cross-posted collections.
        let sql = format!(
            "SELECT id, collections_id, LOWER(HEX(binaryhash)) FROM binaries \
             WHERE (collections_id, binaryhash) IN ({tuples})"
        );
        let mut query = sqlx::query_as::<_, (u64, u32, String)>(AssertSqlSafe(sql));
        for (collection, hash) in batch {
            query = query.bind(collection).bind(hash);
        }
        for (id, collection, hash) in query.fetch_all(&mut *connection).await? {
            ids.insert((collection, hash), id);
        }
    }
    Ok(ids)
}

struct PartRow<'a> {
    binary: u64,
    part: i64,
    number: u64,
    message_id: &'a str,
    bytes: i64,
}

async fn insert_parts(
    connection: &mut MySqlConnection,
    headers: &[Prepared],
    collections: &CollectionIds,
    binaries: &BinaryIds,
    chunk: usize,
) -> Result<()> {
    let mut rows: Vec<PartRow> = headers
        .iter()
        .map(|header| PartRow {
            binary: binaries[&(
                collections[&header.collection_hash],
                header.binary_hash.clone(),
            )],
            part: header.part,
            number: header.number,
            message_id: &header.message_id,
            bytes: header.bytes,
        })
        .collect();
    // Primary-key order across every flush, so workers take shared part locks in one order.
    rows.sort_by_key(|row| (row.binary, row.part));
    let mut chosen: Vec<PartRow> = Vec::with_capacity(rows.len());
    for row in rows {
        match chosen.last_mut() {
            Some(last) if (last.binary, last.part) == (row.binary, row.part) => {
                if prefer(&row, last) {
                    *last = row;
                }
            }
            _ => chosen.push(row),
        }
    }
    for batch in chosen.chunks(chunk) {
        let values = vec!["(?, ?, ?, ?, ?)"; batch.len()].join(",");
        let sql = format!(
            "INSERT IGNORE INTO parts (binaries_id, number, messageid, partnumber, size) VALUES {values}"
        );
        let mut query = sqlx::query(AssertSqlSafe(sql));
        for row in batch {
            query = query
                .bind(row.binary)
                .bind(row.number)
                .bind(row.message_id)
                .bind(row.part)
                .bind(row.bytes);
        }
        let inserted = query.execute(&mut *connection).await?.rows_affected();
        if inserted != batch.len() as u64 {
            check_parts_exist(connection, batch).await?;
        }
    }
    Ok(())
}

/// `INSERT IGNORE` also swallows a missing parent, as a warning. A row that was ignored as a
/// duplicate exists now, and one that doesn't exist was lost.
async fn check_parts_exist(connection: &mut MySqlConnection, batch: &[PartRow<'_>]) -> Result<()> {
    let tuples = vec!["(?, ?)"; batch.len()].join(",");
    let sql = format!(
        "SELECT binaries_id, partnumber FROM parts WHERE (binaries_id, partnumber) IN ({tuples})"
    );
    let mut query = sqlx::query_as::<_, (u64, u32)>(AssertSqlSafe(sql));
    for row in batch {
        query = query.bind(row.binary).bind(row.part);
    }
    let found: HashSet<(u64, u32)> = query
        .fetch_all(&mut *connection)
        .await?
        .into_iter()
        .collect();
    let missing = batch
        .iter()
        .filter(|row| {
            u32::try_from(row.part).map_or(true, |part| !found.contains(&(row.binary, part)))
        })
        .count();
    if missing > 0 {
        return Err(anyhow!("{missing} parts were not stored"));
    }
    Ok(())
}

/// The storage migration's policy for duplicates: the larger payload, then the lower article
/// number. Validation has already refused empty message IDs.
fn prefer(candidate: &PartRow, current: &PartRow) -> bool {
    if candidate.bytes != current.bytes {
        return candidate.bytes > current.bytes;
    }
    candidate.number < current.number
}

/// Rebuilds aggregates from stored parts, so ignored duplicates can't inflate them.
async fn refresh_binaries(
    connection: &mut MySqlConnection,
    ids: &BTreeSet<u64>,
    chunk: usize,
) -> Result<()> {
    let ids: Vec<u64> = ids.iter().copied().collect();
    for batch in ids.chunks(chunk) {
        let marks = vec!["?"; batch.len()].join(",");
        let sql = format!("SELECT id FROM binaries WHERE id IN ({marks}) ORDER BY id FOR UPDATE");
        let mut lock = sqlx::query_scalar::<_, u64>(AssertSqlSafe(sql));
        for id in batch {
            lock = lock.bind(id);
        }
        lock.fetch_all(&mut *connection).await?;

        let sql = format!(
            "SELECT binaries_id, COUNT(*), CAST(COALESCE(SUM(size), 0) AS UNSIGNED) FROM parts \
             WHERE binaries_id IN ({marks}) GROUP BY binaries_id ORDER BY binaries_id"
        );
        let mut read = sqlx::query_as::<_, (u64, i64, u64)>(AssertSqlSafe(sql));
        for id in batch {
            read = read.bind(id);
        }
        let totals = read.fetch_all(&mut *connection).await?;
        if totals.is_empty() {
            continue;
        }
        let whens = vec!["WHEN ? THEN ?"; totals.len()].join(" ");
        let id_marks = vec!["?"; totals.len()].join(",");
        let sql = format!(
            "UPDATE binaries SET currentparts = CASE id {whens} END, \
             partsize = CASE id {whens} END, \
             partcheck = CASE WHEN (CASE id {whens} END) >= totalparts THEN 1 ELSE 0 END \
             WHERE id IN ({id_marks})"
        );
        let mut update = sqlx::query(AssertSqlSafe(sql));
        for (id, count, _) in &totals {
            update = update.bind(id).bind(count);
        }
        for (id, _, size) in &totals {
            update = update.bind(id).bind(size);
        }
        for (id, count, _) in &totals {
            update = update.bind(id).bind(count);
        }
        for (id, _, _) in &totals {
            update = update.bind(id);
        }
        update.execute(&mut *connection).await?;
    }
    Ok(())
}

/// Refreshes size and readiness for just the collections a chunk touched, which replaces
/// table-wide completion scans on the normal ingest path.
async fn refresh_collections(
    connection: &mut MySqlConnection,
    ids: &BTreeSet<u32>,
    chunk: usize,
) -> Result<()> {
    let ids: Vec<u32> = ids.iter().copied().collect();
    for batch in ids.chunks(chunk) {
        let marks = vec!["?"; batch.len()].join(",");
        let sql = format!(
            "SELECT id FROM collections FORCE INDEX (PRIMARY) WHERE id IN ({marks}) ORDER BY id FOR UPDATE"
        );
        let mut lock = sqlx::query_scalar::<_, u32>(AssertSqlSafe(sql));
        for id in batch {
            lock = lock.bind(id);
        }
        lock.fetch_all(&mut *connection).await?;

        let sql = format!(
            "SELECT collections_id, COUNT(*), \
             CAST(SUM(CASE WHEN partcheck = 1 THEN 1 ELSE 0 END) AS UNSIGNED), \
             CAST(COALESCE(SUM(partsize), 0) AS UNSIGNED) FROM binaries \
             WHERE collections_id IN ({marks}) GROUP BY collections_id ORDER BY collections_id"
        );
        let mut read = sqlx::query_as::<_, (u32, i64, u64, u64)>(AssertSqlSafe(sql));
        for id in batch {
            read = read.bind(id);
        }
        let totals = read.fetch_all(&mut *connection).await?;
        if totals.is_empty() {
            continue;
        }
        let whens = vec!["WHEN ? THEN ?"; totals.len()].join(" ");
        let id_marks = vec!["?"; totals.len()].join(",");
        let statuses = vec!["?"; FILECHECK_IN_PROGRESS.len()].join(",");
        let sql = format!(
            "UPDATE collections SET filesize = CASE id {whens} END, last_seen_at = NOW(), \
             filecheck = CASE WHEN filecheck IN ({statuses}) AND totalfiles > 0 \
             AND (CASE id {whens} END) IN (totalfiles, totalfiles + 1) \
             AND (CASE id {whens} END) >= totalfiles THEN ? ELSE filecheck END \
             WHERE id IN ({id_marks})"
        );
        let mut update = sqlx::query(AssertSqlSafe(sql));
        for (id, _, _, size) in &totals {
            update = update.bind(id).bind(size);
        }
        for status in FILECHECK_IN_PROGRESS {
            update = update.bind(status);
        }
        for (id, files, _, _) in &totals {
            update = update.bind(id).bind(files);
        }
        for (id, _, complete, _) in &totals {
            update = update.bind(id).bind(complete);
        }
        update = update.bind(FILECHECK_SIZED);
        for (id, _, _, _) in &totals {
            update = update.bind(id);
        }
        update.execute(&mut *connection).await?;
    }
    Ok(())
}
