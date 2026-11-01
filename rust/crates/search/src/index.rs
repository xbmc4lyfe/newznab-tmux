//! Keeping a release's document in step with its row, from `ManticoreSearchDriver` and
//! `ReleaseIndexProjection`.
//!
//! A failed write leaves a row in `search_index_failures` with its next attempt time. PHP also
//! queued `ReindexReleaseJob` for two seconds later; here that row is the retry.

use std::collections::{HashMap, HashSet};
use std::time::{Duration, Instant};

use chrono::{NaiveDateTime, Utc};
use serde_json::Value;
use sqlx::MySqlPool;
use tracing::{debug, error, warn};

use crate::document::{ReleaseDocument, ReleaseRow};
use crate::{SearchClient, SearchError};

/// The `operation` of a row that is a release pass's lease. Only that pass or repair removes it.
pub const DEFERRED_OPERATION: &str = "deferred";
/// A deferred scope refreshes its releases in chunks of this size.
const DEFERRED_CHUNK: usize = 200;
/// If a lease isn't renewed or cleared in this time, the worker died and repair takes over.
const LEASE_SECONDS: i64 = 600;
/// A release updated again after this long renews its lease.
const LEASE_RENEW_AFTER: Duration = Duration::from_secs(300);

/// The whole-document projection. Numbers are cast so each column decodes as `i64`, and dates
/// are read as text so one zero date can't fail a whole batch.
pub(crate) const PROJECTION: &str = r"
SELECT
  CAST(r.id AS SIGNED) AS id, r.guid, r.name, r.searchname, r.fromname,
  CAST(r.categories_id AS SIGNED) AS categories_id,
  CAST(r.groups_id AS SIGNED) AS groups_id,
  CAST(r.size AS SIGNED) AS size,
  CAST(r.postdate AS CHAR) AS postdate,
  CAST(r.adddate AS CHAR) AS adddate,
  CAST(r.totalpart AS SIGNED) AS totalpart,
  CAST(r.grabs AS SIGNED) AS grabs,
  CAST(r.comments AS SIGNED) AS comments,
  CAST(r.passwordstatus AS SIGNED) AS passwordstatus,
  CAST(r.nzbstatus AS SIGNED) AS nzbstatus,
  CAST(r.nfostatus AS SIGNED) AS nfostatus,
  CAST(r.haspreview AS SIGNED) AS haspreview,
  CAST(r.jpgstatus AS SIGNED) AS jpgstatus,
  CAST(r.videos_id AS SIGNED) AS videos_id,
  CAST(r.tv_episodes_id AS SIGNED) AS tv_episodes_id,
  CAST(r.movieinfo_id AS SIGNED) AS movieinfo_id,
  r.imdbid,
  CAST(r.anidbid AS SIGNED) AS anidbid,
  g.name AS group_name,
  CAST(c.root_categories_id AS SIGNED) AS parentid,
  c.title AS sub_category,
  tve.title AS episode_title,
  CAST(tve.series AS SIGNED) AS series,
  CAST(tve.episode AS SIGNED) AS episode,
  CAST(tve.firstaired AS CHAR) AS firstaired,
  cp.title AS parent_category,
  CONCAT(cp.title, ' > ', c.title) AS category_name,
  COALESCE((SELECT GROUP_CONCAT(rf.name SEPARATOR ' ') FROM release_files rf WHERE rf.releases_id = r.id), '') AS filename,
  CAST(COALESCE(mi.tmdbid, 0) AS SIGNED) AS tmdbid,
  CAST(COALESCE(mi.traktid, 0) AS SIGNED) AS traktid,
  CAST(COALESCE(v.tvdb, 0) AS SIGNED) AS tvdb,
  CAST(COALESCE(v.tvmaze, 0) AS SIGNED) AS tvmaze,
  CAST(COALESCE(v.tvrage, 0) AS SIGNED) AS tvrage,
  CAST(COALESCE(v.trakt, 0) AS SIGNED) AS trakt,
  COALESCE(v.imdb, '') AS imdb,
  CAST(COALESCE(v.tmdb, 0) AS SIGNED) AS tmdb,
  CAST(COALESCE(rn.releases_id, 0) AS SIGNED) AS nfoid,
  CAST(COALESCE(vd.releases_id, 0) AS SIGNED) AS reid,
  COALESCE(mdi.movie_name, '') AS media_movie_name,
  COALESCE(mdi.file_name, '') AS media_file_name,
  COALESCE(mdi.unique_id, '') AS media_unique_id,
  COALESCE(vd.containerformat, '') AS media_container_format,
  COALESCE(vd.videoformat, '') AS media_video_format,
  COALESCE(vd.videocodec, '') AS media_video_codec,
  CAST(COALESCE(vd.videowidth, 0) AS SIGNED) AS media_video_width,
  CAST(COALESCE(vd.videoheight, 0) AS SIGNED) AS media_video_height,
  COALESCE((SELECT GROUP_CONCAT(ad.audioformat SEPARATOR ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '') AS media_audio_format,
  COALESCE((SELECT GROUP_CONCAT(ad.audiochannels SEPARATOR ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '') AS media_audio_channels,
  COALESCE((SELECT GROUP_CONCAT(ad.audiolanguage SEPARATOR ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '') AS media_audio_language,
  COALESCE((SELECT GROUP_CONCAT(rs.subslanguage SEPARATOR ' ') FROM release_subtitles rs WHERE rs.releases_id = r.id), '') AS media_subtitle_language,
  CAST(CASE WHEN mdi.releases_id IS NOT NULL OR vd.releases_id IS NOT NULL
    OR EXISTS (SELECT 1 FROM audio_data ad WHERE ad.releases_id = r.id)
    OR EXISTS (SELECT 1 FROM release_subtitles rs WHERE rs.releases_id = r.id)
    THEN 1 ELSE 0 END AS SIGNED) AS has_media_info
FROM releases r
LEFT JOIN usenet_groups g ON g.id = r.groups_id
LEFT JOIN categories c ON c.id = r.categories_id
LEFT JOIN root_categories cp ON cp.id = c.root_categories_id
LEFT JOIN movieinfo mi ON mi.id = r.movieinfo_id AND r.movieinfo_id > 0
LEFT JOIN videos v ON v.id = r.videos_id AND r.videos_id > 0
LEFT JOIN tv_episodes tve ON tve.id = r.tv_episodes_id AND r.tv_episodes_id > 0
LEFT JOIN release_nfos rn ON rn.releases_id = r.id
LEFT JOIN video_data vd ON vd.releases_id = r.id
LEFT JOIN (
  SELECT selected.releases_id, selected.movie_name, selected.file_name, selected.unique_id
  FROM media_infos selected
  WHERE selected.id = (
    SELECT MIN(candidate.id) FROM media_infos candidate WHERE candidate.releases_id = selected.releases_id
  )
) mdi ON mdi.releases_id = r.id
";

fn now() -> NaiveDateTime {
    Utc::now().naive_utc()
}

impl SearchClient {
    /// Writes a document built by the caller. On false a failure row records the retry.
    pub async fn insert_release(&self, db: &MySqlPool, document: &ReleaseDocument) -> bool {
        if document.id <= 0 {
            warn!("can't insert a release without an id");
            return false;
        }
        self.write_release(db, document).await
    }

    /// Removes documents. On false each id has a failure row recording the retry.
    pub async fn delete_releases(&self, db: &MySqlPool, ids: &[i64]) -> bool {
        let ids = positive_unique(ids);
        if ids.is_empty() {
            warn!("can't delete a release without an id");
            return false;
        }
        self.remove_releases(db, &ids).await
    }

    /// Replaces many documents in one request, with no retry. Backfills use this and count what
    /// failed; nothing records a retry.
    pub async fn bulk_insert_releases(&self, documents: &[ReleaseDocument]) -> BulkResult {
        let rows: Vec<(i64, Value)> = documents
            .iter()
            .filter(|document| document.id > 0)
            .map(release_row)
            .collect();
        if rows.is_empty() {
            return BulkResult::default();
        }
        let table = self.config.indexes.table("releases");
        match self.replace_documents(table, &rows).await {
            Ok(()) => BulkResult {
                success: rows.len(),
                errors: 0,
            },
            Err(bulk_error) => {
                error!(error = %bulk_error, "bulk indexing releases failed");
                BulkResult {
                    success: 0,
                    errors: rows.len(),
                }
            }
        }
    }

    /// Refreshes many releases with one projection query and one bulk replace. Each ends up as
    /// `update_release` would leave it. If the query or the bulk write fails, each release falls
    /// back to the single path, which retries and records its own failure. Returns the ids that
    /// couldn't be refreshed.
    pub async fn update_releases(&self, db: &MySqlPool, ids: &[i64]) -> Vec<i64> {
        let ids = positive_unique(ids);
        if ids.is_empty() {
            return Vec::new();
        }
        let placeholders = vec!["?"; ids.len()].join(",");
        let statement = format!("{PROJECTION} WHERE r.id IN ({placeholders})");
        let mut query = sqlx::query_as::<_, ReleaseRow>(sqlx::AssertSqlSafe(statement));
        for id in &ids {
            query = query.bind(id);
        }
        let rows = match query.fetch_all(db).await {
            Ok(rows) => rows,
            Err(query_error) => {
                error!(releases = ids.len(), error = %query_error, "reading releases for the index failed");
                let mut failed = Vec::new();
                for id in ids {
                    if !self.update_release(db, id).await {
                        failed.push(id);
                    }
                }
                return failed;
            }
        };
        let documents: Vec<ReleaseDocument> = rows.into_iter().map(ReleaseDocument::from).collect();
        let found: HashSet<i64> = documents.iter().map(|document| document.id).collect();
        let missing: Vec<i64> = ids
            .iter()
            .copied()
            .filter(|id| !found.contains(id))
            .collect();

        let mut failed = Vec::new();
        for id in &missing {
            self.note_missing_release(*id).await;
        }
        if !missing.is_empty() && !self.remove_releases(db, &missing).await {
            failed.extend(&missing);
        }
        if documents.is_empty() {
            return failed;
        }

        if self.replace_releases_with_retry(&documents).await {
            self.resolve_failures(db, &documents).await;
            return failed;
        }
        for document in &documents {
            if !self.write_release(db, document).await {
                failed.push(document.id);
            }
        }
        failed
    }

    async fn replace_releases_with_retry(&self, documents: &[ReleaseDocument]) -> bool {
        let rows: Vec<(i64, Value)> = documents.iter().map(release_row).collect();
        let table = self.config.indexes.table("releases");
        let attempts = self.config.retry_attempts.max(1);
        for attempt in 0..attempts {
            match self.replace_documents(table, &rows).await {
                Ok(()) => return true,
                Err(bulk_error) if attempt + 1 == attempts => {
                    error!(releases = rows.len(), table, error = %bulk_error, "bulk replace failed, retrying one by one");
                }
                Err(_) => self.retry_pause(attempt).await,
            }
        }
        false
    }

    async fn resolve_failures(&self, db: &MySqlPool, documents: &[ReleaseDocument]) {
        let placeholders = vec!["?"; documents.len()].join(",");
        let statement = format!(
            "UPDATE search_index_failures SET resolved_at = ?, next_attempt_at = NULL, updated_at = ? \
             WHERE release_id IN ({placeholders}) AND resolved_at IS NULL AND operation != ?"
        );
        let mut query = sqlx::query(sqlx::AssertSqlSafe(statement))
            .bind(now())
            .bind(now());
        for document in documents {
            query = query.bind(document.id);
        }
        let resolved = query.bind(DEFERRED_OPERATION).execute(db).await;
        if let Err(database_error) = resolved {
            debug!(error = %database_error, "marking index failures resolved failed");
        }
    }

    async fn note_missing_release(&self, release_id: i64) {
        if self
            .cache
            .count_failure(
                "search:index:failures:release_not_found",
                "search:index:release_not_found_warn_lock",
            )
            .await
        {
            warn!(
                release_id,
                "release not found when updating the index, removing it"
            );
        }
    }

    /// Starts a scope that holds release updates back and refreshes each release once, from its
    /// final row, when `finish` runs. A release created and then given its NZB in one pass is
    /// indexed once. See [`DeferredUpdates`].
    pub fn defer_release_updates<'a>(&'a self, db: &'a MySqlPool) -> DeferredUpdates<'a> {
        DeferredUpdates {
            client: self,
            db,
            token: format!(
                "{DEFERRED_OPERATION}:{}",
                hex::encode(rand::random::<[u8; 8]>())
            ),
            marked: HashMap::new(),
        }
    }

    /// Rewrites a release's document from its row, or removes it if the row is gone. True when
    /// the document now matches. On false a failure row records the retry, so most callers can
    /// carry on without looking at the result.
    pub async fn update_release(&self, db: &MySqlPool, release_id: i64) -> bool {
        if release_id <= 0 {
            warn!("can't update a release without an id");
            return false;
        }
        let statement = format!("{PROJECTION} WHERE r.id = ?");
        let row = sqlx::query_as::<_, ReleaseRow>(sqlx::AssertSqlSafe(statement))
            .bind(release_id)
            .fetch_optional(db)
            .await;
        match row {
            Ok(Some(row)) => self.write_release(db, &row.into()).await,
            Ok(None) => {
                self.note_missing_release(release_id).await;
                self.remove_releases(db, &[release_id]).await
            }
            Err(query_error) => {
                error!(release_id, error = %query_error, "reading a release for the index failed");
                self.record_failure(db, release_id, "updateRelease_query", "upsert")
                    .await;
                false
            }
        }
    }

    async fn write_release(&self, db: &MySqlPool, document: &ReleaseDocument) -> bool {
        let table = self.config.indexes.table("releases");
        let attempts = self.config.retry_attempts.max(1);
        for attempt in 0..attempts {
            match self.replace_document(table, document.id, document).await {
                Ok(()) => {
                    self.resolve_failure(db, document.id).await;
                    return true;
                }
                Err(replace_error) if attempt + 1 == attempts => {
                    error!(release_id = document.id, table, error = %replace_error, "indexing a release failed");
                }
                Err(_) => self.retry_pause(attempt).await,
            }
        }
        self.record_failure(db, document.id, "insertRelease", "upsert")
            .await;
        false
    }

    async fn remove_releases(&self, db: &MySqlPool, ids: &[i64]) -> bool {
        let table = self.config.indexes.table("releases");
        let attempts = self.config.retry_attempts.max(1);
        let mut last_error = None::<SearchError>;
        for attempt in 0..attempts {
            match self.delete_documents(table, ids).await {
                Ok(()) => {
                    for id in ids {
                        self.resolve_failure(db, *id).await;
                    }
                    return true;
                }
                Err(delete_error) => {
                    last_error = Some(delete_error);
                    if attempt + 1 < attempts {
                        self.retry_pause(attempt).await;
                    }
                }
            }
        }
        let message = last_error.map(|e| e.to_string()).unwrap_or_default();
        error!(?ids, error = %message, "removing releases from the index failed");
        for id in ids {
            self.record_failure(db, *id, &format!("deleteReleases: {message}"), "delete")
                .await;
        }
        false
    }

    async fn retry_pause(&self, attempt: u32) {
        let delay = self.config.retry_delay_ms * u64::from(attempt + 1);
        if delay > 0 {
            tokio::time::sleep(Duration::from_millis(delay)).await;
        }
    }

    async fn record_failure(&self, db: &MySqlPool, release_id: i64, phase: &str, operation: &str) {
        let first_this_minute = self
            .cache
            .count_failure(
                "search:index:failures:releases",
                "search:index:release_index_warn_lock",
            )
            .await;
        if let Err(database_error) = persist_failure(db, release_id, phase, operation).await {
            error!(release_id, phase, error = %database_error, "recording an index failure failed");
        }
        if first_this_minute {
            warn!(release_id, phase, "release indexing failure");
        }
    }

    async fn resolve_failure(&self, db: &MySqlPool, release_id: i64) {
        let resolved = sqlx::query(
            "UPDATE search_index_failures SET resolved_at = ?, next_attempt_at = NULL, updated_at = ? \
             WHERE release_id = ? AND operation != ?",
        )
        .bind(now())
        .bind(now())
        .bind(release_id)
        .bind(DEFERRED_OPERATION)
        .execute(db)
        .await;
        if let Err(database_error) = resolved {
            debug!(release_id, error = %database_error, "marking an index failure resolved failed");
        }
    }
}

/// Uses conditional writes, so a lease a release pass writes at the same moment is never
/// replaced. On a lease the failure only counts an attempt, and the pass or repair takes over.
async fn persist_failure(
    db: &MySqlPool,
    release_id: i64,
    phase: &str,
    operation: &str,
) -> Result<(), sqlx::Error> {
    for _ in 0..3 {
        let attempts: Option<u32> =
            sqlx::query_scalar("SELECT attempts FROM search_index_failures WHERE release_id = ?")
                .bind(release_id)
                .fetch_optional(db)
                .await?;
        let next_attempts = attempts.unwrap_or(0) + 1;
        let backoff = 2_i64.pow(next_attempts.min(10)).min(3600);
        let current = now();
        let next_attempt_at = current + chrono::Duration::seconds(backoff);

        let counted = sqlx::query(
            "UPDATE search_index_failures SET attempts = attempts + 1, updated_at = ? \
             WHERE release_id = ? AND operation = ?",
        )
        .bind(current)
        .bind(release_id)
        .bind(DEFERRED_OPERATION)
        .execute(db)
        .await?;
        if counted.rows_affected() > 0 {
            return Ok(());
        }

        let updated = sqlx::query(
            "UPDATE search_index_failures SET operation = ?, last_error = ?, next_attempt_at = ?, \
             resolved_at = NULL, updated_at = ?, attempts = attempts + 1 \
             WHERE release_id = ? AND operation != ?",
        )
        .bind(operation)
        .bind(phase)
        .bind(next_attempt_at)
        .bind(current)
        .bind(release_id)
        .bind(DEFERRED_OPERATION)
        .execute(db)
        .await?;
        if updated.rows_affected() > 0 {
            return Ok(());
        }

        let inserted = sqlx::query(
            "INSERT IGNORE INTO search_index_failures \
             (release_id, operation, attempts, last_error, next_attempt_at, resolved_at, created_at, updated_at) \
             VALUES (?, ?, 1, ?, ?, NULL, ?, ?)",
        )
        .bind(release_id)
        .bind(operation)
        .bind(phase)
        .bind(next_attempt_at)
        .bind(current)
        .bind(current)
        .execute(db)
        .await?;
        if inserted.rows_affected() > 0 {
            return Ok(());
        }
        // The row changed between the writes, so look again.
    }
    Ok(())
}

/// How many documents a bulk write took.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct BulkResult {
    pub success: usize,
    pub errors: usize,
}

fn release_row(document: &ReleaseDocument) -> (i64, Value) {
    (
        document.id,
        serde_json::to_value(document).unwrap_or(Value::Null),
    )
}

fn positive_unique(ids: &[i64]) -> Vec<i64> {
    let mut seen = HashSet::new();
    ids.iter()
        .copied()
        .filter(|id| *id > 0 && seen.insert(*id))
        .collect()
}

/// Release updates held back until [`finish`](Self::finish). Each held id has a `deferred` row in
/// `search_index_failures` with this scope's token and a lease. If the worker dies before
/// finishing, repair refreshes the release once the lease runs out, so dropping a scope loses
/// nothing. Use it outside database transactions, so `finish` reads committed rows.
pub struct DeferredUpdates<'a> {
    client: &'a SearchClient,
    db: &'a MySqlPool,
    token: String,
    marked: HashMap<i64, Instant>,
}

impl DeferredUpdates<'_> {
    /// Holds this release's update for `finish`. Without a lease of its own, which happens when
    /// another live scope holds it or the write fails, it indexes the release now, because a crash
    /// before `finish` would leave nothing for repair.
    pub async fn update_release(&mut self, release_id: i64) {
        if release_id <= 0 {
            return;
        }
        if self
            .marked
            .get(&release_id)
            .is_some_and(|marked| marked.elapsed() < LEASE_RENEW_AFTER)
        {
            return;
        }
        match self.mark(release_id).await {
            Ok(true) => {
                self.marked.insert(release_id, Instant::now());
            }
            Ok(false) => {
                self.marked.remove(&release_id);
                self.client.update_release(self.db, release_id).await;
            }
            Err(database_error) => {
                debug!(release_id, error = %database_error, "recording a deferred index update failed");
                self.marked.remove(&release_id);
                self.client.update_release(self.db, release_id).await;
            }
        }
    }

    /// Writes or renews this scope's lease. Takes over an ordinary failure row, its own lease, or
    /// a lease whose scope died, but never another live scope's lease.
    async fn mark(&self, release_id: i64) -> Result<bool, sqlx::Error> {
        let current = now();
        let lease_end = current + chrono::Duration::seconds(LEASE_SECONDS);
        let inserted = sqlx::query(
            "INSERT IGNORE INTO search_index_failures \
             (release_id, operation, attempts, last_error, next_attempt_at, resolved_at, created_at, updated_at) \
             VALUES (?, ?, 0, ?, ?, NULL, ?, ?)",
        )
        .bind(release_id)
        .bind(DEFERRED_OPERATION)
        .bind(&self.token)
        .bind(lease_end)
        .bind(current)
        .bind(current)
        .execute(self.db)
        .await?;
        if inserted.rows_affected() > 0 {
            return Ok(true);
        }
        let updated = sqlx::query(
            "UPDATE search_index_failures SET operation = ?, attempts = 0, last_error = ?, \
             next_attempt_at = ?, resolved_at = NULL, updated_at = ? \
             WHERE release_id = ? AND (operation != ? OR last_error = ? OR next_attempt_at <= ?)",
        )
        .bind(DEFERRED_OPERATION)
        .bind(&self.token)
        .bind(lease_end)
        .bind(current)
        .bind(release_id)
        .bind(DEFERRED_OPERATION)
        .bind(&self.token)
        .bind(current)
        .execute(self.db)
        .await?;
        Ok(updated.rows_affected() > 0)
    }

    /// Refreshes every held release, then clears the leases of those that worked. Whatever is
    /// left goes to repair at once: reported failures, and markers on which another worker counted
    /// a failure after the refresh read its rows.
    pub async fn finish(mut self) {
        let ids: Vec<i64> = self.marked.drain().map(|(id, _)| id).collect();
        for chunk in ids.chunks(DEFERRED_CHUNK) {
            let failed: HashSet<i64> = self
                .client
                .update_releases(self.db, chunk)
                .await
                .into_iter()
                .collect();
            let refreshed: Vec<i64> = chunk
                .iter()
                .copied()
                .filter(|id| !failed.contains(id))
                .collect();
            self.clear_markers(&refreshed).await;
            self.hand_to_repair(chunk).await;
        }
    }

    async fn clear_markers(&self, ids: &[i64]) {
        if ids.is_empty() {
            return;
        }
        let placeholders = vec!["?"; ids.len()].join(",");
        let statement = format!(
            "DELETE FROM search_index_failures WHERE release_id IN ({placeholders}) \
             AND operation = ? AND last_error = ? AND attempts = 0"
        );
        let mut query = sqlx::query(sqlx::AssertSqlSafe(statement));
        for id in ids {
            query = query.bind(id);
        }
        let cleared = query
            .bind(DEFERRED_OPERATION)
            .bind(&self.token)
            .execute(self.db)
            .await;
        if let Err(database_error) = cleared {
            debug!(error = %database_error, "clearing deferred index markers failed");
        }
    }

    async fn hand_to_repair(&self, ids: &[i64]) {
        let placeholders = vec!["?"; ids.len()].join(",");
        let statement = format!(
            "UPDATE search_index_failures SET next_attempt_at = ?, updated_at = ? \
             WHERE release_id IN ({placeholders}) AND operation = ? AND last_error = ?"
        );
        let mut query = sqlx::query(sqlx::AssertSqlSafe(statement))
            .bind(now())
            .bind(now());
        for id in ids {
            query = query.bind(id);
        }
        let handed = query
            .bind(DEFERRED_OPERATION)
            .bind(&self.token)
            .execute(self.db)
            .await;
        if let Err(database_error) = handed {
            debug!(error = %database_error, "handing deferred releases to repair failed");
        }
    }
}
