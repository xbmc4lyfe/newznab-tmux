//! Turns finished collections into verification candidates: `releases:process` and its per-group
//! passes. Publishing belongs to the verifier; this keeps collection state current and archives them.

use std::collections::{BTreeMap, HashSet};
use std::time::{Duration, Instant};

use anyhow::{Result, bail};
use nntmux_verify::candidates;
use nntmux_verify::collections::{self, Cleanup, ELIGIBLE, READY};
use serde::{Deserialize, Serialize};
use sqlx::{MySql, QueryBuilder};
use tracing::warn;

use crate::reconcile::{ReconcileWalk, reconcile_collection_ids};
use crate::settings::{Limits, Settings, stop_requested};
use crate::{Releases, maintenance};

const BATCH_SIZE: i64 = 500;
const READY_WINDOW_SIZE: i64 = 50;
const COLLECTION_WINDOWS_PER_PASS: u32 = 20;
const PRIORITY_GROUP_PROBES_PER_PASS: u32 = 20;
const COLLECTION_WINDOW_CURSOR: &str = "cbp:release-window:cursor:";
const READY_WINDOW_CURSOR: &str = "cbp:release-ready-window:cursor:";
const PRIORITY_WINDOW_CURSOR: &str = "cbp:release-priority-window:cursor:";

/// What a pass did.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Totals {
    pub iterations: u32,
}

/// Which work `releases:process` runs.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Mode {
    /// One bounded ready window for a group, with no reconciliation or retention cleanup.
    ReadyOnly(i64),
    /// Release cleanup and categorization, with no collections processed.
    MaintenanceOnly,
    Group(i64),
    /// Every group's collections, then maintenance.
    All,
}

pub async fn run(releases: &Releases, mode: Mode) -> Result<()> {
    let db = &releases.verify.db;
    if stop_requested(db).await? {
        return Ok(());
    }
    let settings = Settings::load(db).await?;
    let limits = Limits::from_env()?;
    match mode {
        Mode::ReadyOnly(group) => {
            process_ready_release_window(releases, &settings, group).await?;
        }
        Mode::MaintenanceOnly => maintenance::run(releases, &settings).await?,
        Mode::Group(group) => process_group(releases, &settings, &limits, Some(group)).await?,
        Mode::All => {
            process_group(releases, &settings, &limits, None).await?;
            maintenance::run(releases, &settings).await?;
        }
    }
    Ok(())
}

/// One full pass over a group, as a per-group worker runs it.
pub async fn run_group_pass(
    releases: &Releases,
    settings: &Settings,
    limits: &Limits,
    group: i64,
) -> Result<()> {
    if stop_requested(&releases.verify.db).await? {
        return Ok(());
    }
    process_group(releases, settings, limits, Some(group)).await
}

async fn process_group(
    releases: &Releases,
    settings: &Settings,
    limits: &Limits,
    group: Option<i64>,
) -> Result<()> {
    process_collections_and_releases(releases, settings, limits, group).await?;
    if !stop_requested(&releases.verify.db).await? {
        let cleanup = Cleanup::from_env(settings.part_retention_hours)?;
        collections::delete_finished_and_orphans(&releases.verify, &cleanup).await?;
    }
    Ok(())
}

async fn has_release_creation_work(releases: &Releases, group: Option<i64>) -> Result<bool> {
    let mut query = QueryBuilder::<MySql>::new(format!(
        "SELECT 1 FROM collections WHERE {ELIGIBLE} AND {READY}"
    ));
    if let Some(group) = group {
        query.push(" AND groups_id = ").push_bind(group);
    }
    query.push(" LIMIT 1");
    let row: Option<(i32,)> = query
        .build_query_as()
        .fetch_optional(&releases.verify.db)
        .await?;
    Ok(row.is_some())
}

/// Drains ready collections before preparation, then consumes each freshly reconciled page. Sized
/// rows never take part in downtime compensation or stuck cleanup, so they needn't wait for either.
pub async fn process_collections_and_releases(
    releases: &Releases,
    settings: &Settings,
    limits: &Limits,
    group: Option<i64>,
) -> Result<Totals> {
    let db = &releases.verify.db;
    let mut totals = if has_release_creation_work(releases, group).await? {
        process_release_creation_batches(releases, settings, group).await?
    } else {
        Totals::default()
    };
    if stop_requested(db).await? {
        return Ok(totals);
    }
    let window_size = settings.release_creation_limit.clamp(1, BATCH_SIZE) as usize;
    let mut walk = ReconcileWalk::start(releases, settings, limits, group).await?;
    'pages: while let Some(ids) = walk.next_page().await? {
        for window in ids.chunks(window_size) {
            if stop_requested(db).await? {
                break 'pages;
            }
            let ready = complete_parts_among(releases, window, group).await?;
            if ready.is_empty() {
                continue;
            }
            mark_sized(releases, &ready).await?;
            archive_window(releases, settings, group, &ready).await?;
            totals.iterations += 1;
        }
    }
    if stop_requested(db).await? {
        return Ok(totals);
    }
    // The final sweep catches collections completed concurrently by ingestion.
    process_collection_sizes(releases, settings, limits, group).await?;
    if has_release_creation_work(releases, group).await? || totals.iterations == 0 {
        let last = process_release_creation_batches(releases, settings, group).await?;
        totals.iterations += last.iterations;
    }
    Ok(totals)
}

async fn complete_parts_among(
    releases: &Releases,
    ids: &[i64],
    group: Option<i64>,
) -> Result<Vec<i64>> {
    let mut query = QueryBuilder::<MySql>::new("SELECT id FROM collections WHERE id IN (");
    let mut separated = query.separated(",");
    for id in ids {
        separated.push_bind(*id);
    }
    query.push(") AND filecheck = 2");
    if let Some(group) = group {
        query.push(" AND groups_id = ").push_bind(group);
    }
    query.push(" ORDER BY id");
    let rows: Vec<(u32,)> = query
        .build_query_as()
        .fetch_all(&releases.verify.db)
        .await?;
    Ok(rows.into_iter().map(|(id,)| i64::from(id)).collect())
}

async fn mark_sized(releases: &Releases, ids: &[i64]) -> Result<u64> {
    let mut query =
        QueryBuilder::<MySql>::new("UPDATE collections SET filecheck = 3 WHERE id IN (");
    let mut separated = query.separated(",");
    for id in ids {
        separated.push_bind(*id);
    }
    query.push(") AND filecheck = 2");
    Ok(query
        .build()
        .execute(&releases.verify.db)
        .await?
        .rows_affected())
}

/// Sized rows leave `CompleteParts`, so a capped sweep simply continues next pass. The capped
/// reconciliation walk may not have reached a page yet, so each is reconciled before it's promoted.
pub async fn process_collection_sizes(
    releases: &Releases,
    settings: &Settings,
    limits: &Limits,
    group: Option<i64>,
) -> Result<u64> {
    let db = &releases.verify.db;
    let deadline = Instant::now() + Duration::from_secs(limits.reconcile_pass_seconds);
    let mut updated = 0;
    let mut last_id = 0;
    let mut pages = 0;
    loop {
        if stop_requested(db).await?
            || pages >= limits.reconcile_pages_per_pass
            || Instant::now() >= deadline
        {
            return Ok(updated);
        }
        pages += 1;
        let mut query = QueryBuilder::<MySql>::new("SELECT id FROM collections WHERE id > ");
        query.push_bind(last_id).push(" AND filecheck = 2");
        if let Some(group) = group {
            query.push(" AND groups_id = ").push_bind(group);
        }
        query
            .push(" ORDER BY id LIMIT ")
            .push_bind(limits.reconcile_batch_size);
        let rows: Vec<(u32,)> = query.build_query_as().fetch_all(db).await?;
        let ids: Vec<i64> = rows.into_iter().map(|(id,)| i64::from(id)).collect();
        let Some(last) = ids.last().copied() else {
            return Ok(updated);
        };
        let barrier = nntmux_runtime::downtime::enter(db, settings.collection_delay_hours).await?;
        let sized = async {
            reconcile_collection_ids(db, settings, limits, &ids).await?;
            mark_sized(releases, &ids).await
        }
        .await;
        barrier.release().await;
        updated += sized?;
        last_id = last;
        if (ids.len() as i64) < limits.reconcile_batch_size {
            return Ok(updated);
        }
    }
}

#[derive(Clone, Copy, Debug, Serialize, Deserialize)]
struct BackgroundWindow {
    last_id: i64,
    max_id: i64,
}

#[derive(Clone, Copy, Debug, Serialize, Deserialize)]
struct PriorityCursor {
    last_size: i64,
    last_id: i64,
}

/// One priority reader's position: a snapshot ceiling and each group's size-ordered cursor.
#[derive(Clone, Debug, Default, Serialize, Deserialize)]
struct PriorityState {
    max_id: i64,
    group_ids: Vec<i64>,
    position: usize,
    cursors: BTreeMap<i64, PriorityCursor>,
    exhausted: HashSet<i64>,
}

impl PriorityState {
    fn valid(&self, group: Option<i64>) -> bool {
        let mut unique = self.group_ids.clone();
        unique.sort_unstable();
        unique.dedup();
        self.max_id > 0
            && !self.group_ids.is_empty()
            && self.position < self.group_ids.len()
            && self.group_ids.iter().all(|id| *id > 0)
            && unique.len() == self.group_ids.len()
            && group.is_none_or(|group| self.group_ids == [group])
            && self.cursors.iter().all(|(id, cursor)| {
                self.group_ids.contains(id)
                    && cursor.last_size >= 0
                    && cursor.last_id >= 0
                    && cursor.last_id <= self.max_id
            })
            && self.exhausted.iter().all(|id| self.group_ids.contains(id))
            && self.exhausted.len() < self.group_ids.len()
    }

    fn more(&self) -> bool {
        self.exhausted.len() < self.group_ids.len()
    }
}

fn scope_key(prefix: &str, group: Option<i64>) -> String {
    match group {
        None => format!("{prefix}all"),
        Some(group) => format!("{prefix}group:{group}"),
    }
}

async fn persist<T: Serialize>(
    releases: &Releases,
    key: &str,
    state: &T,
    more: bool,
) -> Result<()> {
    if more {
        releases.verify.cache.forever(key, state).await
    } else {
        releases.verify.cache.forget(key).await
    }
}

async fn load_priority_state(
    releases: &Releases,
    group: Option<i64>,
    key: &str,
) -> Result<PriorityState> {
    if let Some(saved) = releases.verify.cache.get::<PriorityState>(key).await
        && saved.valid(group)
    {
        return Ok(saved);
    }
    let db = &releases.verify.db;
    let mut query =
        QueryBuilder::<MySql>::new("SELECT MAX(id) FROM collections WHERE filecheck = 3");
    if let Some(group) = group {
        query.push(" AND groups_id = ").push_bind(group);
    }
    let (max,): (Option<u32>,) = query.build_query_as().fetch_one(db).await?;
    let max_id = i64::from(max.unwrap_or(0));
    let group_ids = match (max_id, group) {
        (0, _) => Vec::new(),
        (_, Some(group)) => vec![group],
        (_, None) => sqlx::query_scalar::<_, u32>(
            "SELECT usenet_groups.id FROM usenet_groups WHERE EXISTS (SELECT 1 FROM collections AS c \
             WHERE c.groups_id = usenet_groups.id AND c.filecheck = 3 AND c.filesize > 0 AND c.id <= ?) \
             ORDER BY usenet_groups.id",
        )
        .bind(max_id)
        .fetch_all(db)
        .await?
        .into_iter()
        .map(i64::from)
        .collect(),
    };
    releases.verify.cache.forget(key).await?;
    Ok(PriorityState {
        max_id,
        group_ids,
        ..PriorityState::default()
    })
}

/// Probes groups round-robin for sized collections that meet their numeric limits, smallest first,
/// using the same indexed query as a single-group worker.
async fn next_priority_window(
    releases: &Releases,
    settings: &Settings,
    group: Option<i64>,
    state: &mut PriorityState,
    window_size: i64,
    probes: &mut u32,
    processed: &HashSet<i64>,
) -> Result<Vec<i64>> {
    let db = &releases.verify.db;
    let mut inspected = 0;
    let count = state.group_ids.len();
    while *probes < PRIORITY_GROUP_PROBES_PER_PASS && inspected < count {
        let probe = state.group_ids[state.position];
        state.position = (state.position + 1) % count;
        inspected += 1;
        if state.exhausted.contains(&probe) {
            continue;
        }
        *probes += 1;
        let row: Option<(bool, Option<i64>, Option<i32>)> = sqlx::query_as(
            "SELECT active, minsizetoformrelease, minfilestoformrelease FROM usenet_groups WHERE id = ?",
        )
        .bind(probe)
        .fetch_optional(db)
        .await?;
        let (active, group_size, group_files) = row.unwrap_or((false, None, None));
        let numeric = group.is_some() || active;
        let min_size = if numeric {
            group_size.unwrap_or(0).max(settings.min_size)
        } else {
            0
        };
        let min_files = if numeric {
            i64::from(group_files.unwrap_or(0)).max(settings.min_files)
        } else {
            0
        };
        let mut query =
            QueryBuilder::<MySql>::new("SELECT id, filesize FROM collections WHERE groups_id = ");
        query.push_bind(probe);
        query
            .push(" AND filecheck = 3 AND filesize >= ")
            .push_bind(min_size.max(1));
        query.push(" AND id <= ").push_bind(state.max_id);
        if numeric && settings.max_size > 0 {
            query.push(" AND filesize <= ").push_bind(settings.max_size);
        }
        if min_files > 0 {
            query.push(" AND totalfiles >= ").push_bind(min_files);
        }
        if let Some(cursor) = state.cursors.get(&probe) {
            query.push(" AND (filesize > ").push_bind(cursor.last_size);
            query.push(" OR (filesize = ").push_bind(cursor.last_size);
            query
                .push(" AND id > ")
                .push_bind(cursor.last_id)
                .push("))");
        }
        query
            .push(" ORDER BY filesize, id LIMIT ")
            .push_bind(window_size + 1);
        let mut page: Vec<(u32, u64)> = query.build_query_as().fetch_all(db).await?;
        if page.len() as i64 <= window_size {
            state.exhausted.insert(probe);
        }
        page.truncate(window_size as usize);
        if let Some((id, size)) = page.last() {
            state.cursors.insert(
                probe,
                PriorityCursor {
                    last_size: *size as i64,
                    last_id: i64::from(*id),
                },
            );
        }
        let ids: Vec<i64> = page
            .iter()
            .map(|(id, _)| i64::from(*id))
            .filter(|id| !processed.contains(id))
            .collect();
        if !ids.is_empty() {
            return Ok(ids);
        }
    }
    Ok(Vec::new())
}

/// Prioritizes numerically eligible collections, alternating with background cleanup under a shared
/// budget of 20 validation windows. Independent cursors keep their snapshot ceilings until exhausted.
/// All selected ids still pass the complete rules before creation. Totals are partial while a backlog
/// remains.
pub async fn process_release_creation_batches(
    releases: &Releases,
    settings: &Settings,
    group: Option<i64>,
) -> Result<Totals> {
    let db = &releases.verify.db;
    let mut totals = Totals::default();
    let limit = settings.release_creation_limit.max(1);
    let window_size = limit.min(BATCH_SIZE);
    let cursor_key = scope_key(COLLECTION_WINDOW_CURSOR, group);
    let saved: Option<BackgroundWindow> = releases.verify.cache.get(&cursor_key).await;
    let (mut last_id, max_id) = match saved {
        Some(window) if window.last_id >= 0 && window.max_id > window.last_id => {
            (window.last_id, window.max_id)
        }
        _ => {
            let mut query = QueryBuilder::<MySql>::new(format!(
                "SELECT MAX(collections.id) FROM collections WHERE {ELIGIBLE} AND {READY}"
            ));
            if let Some(group) = group {
                query.push(" AND groups_id = ").push_bind(group);
            }
            let (max,): (Option<u32>,) = query.build_query_as().fetch_one(db).await?;
            (0, i64::from(max.unwrap_or(0)))
        }
    };
    if max_id == 0 {
        releases.verify.cache.forget(&cursor_key).await?;
    }
    let mut more_collections = max_id > 0;
    let priority_key = scope_key(PRIORITY_WINDOW_CURSOR, group);
    let mut priority = load_priority_state(releases, group, &priority_key).await?;
    let mut more_priority = !priority.group_ids.is_empty();
    let mut probes = 0;
    let mut prefer_priority = true;
    let mut windows = 0;
    let mut processed: HashSet<i64> = HashSet::new();
    let mut first = true;
    loop {
        if stop_requested(db).await? {
            break;
        }
        let mut window: Vec<i64> = Vec::new();
        let mut reader = None;
        while windows < COLLECTION_WINDOWS_PER_PASS
            && (more_collections || (more_priority && probes < PRIORITY_GROUP_PROBES_PER_PASS))
        {
            if (prefer_priority || !more_collections)
                && more_priority
                && probes < PRIORITY_GROUP_PROBES_PER_PASS
            {
                window = next_priority_window(
                    releases,
                    settings,
                    group,
                    &mut priority,
                    window_size,
                    &mut probes,
                    &processed,
                )
                .await?;
                more_priority = priority.more();
                prefer_priority = false;
                if window.is_empty() {
                    persist(releases, &priority_key, &priority, more_priority).await?;
                    continue;
                }
                reader = Some(Reader::Priority);
            } else {
                let mut query = QueryBuilder::<MySql>::new(format!(
                    "SELECT collections.id FROM collections WHERE {ELIGIBLE} AND {READY}"
                ));
                if let Some(group) = group {
                    query.push(" AND groups_id = ").push_bind(group);
                }
                query.push(" AND collections.id > ").push_bind(last_id);
                query.push(" AND collections.id <= ").push_bind(max_id);
                query
                    .push(" ORDER BY collections.id LIMIT ")
                    .push_bind(window_size + 1);
                let rows: Vec<(u32,)> = query.build_query_as().fetch_all(db).await?;
                let mut page: Vec<i64> = rows.into_iter().map(|(id,)| i64::from(id)).collect();
                more_collections = page.len() as i64 > window_size;
                page.truncate(window_size as usize);
                if let Some(last) = page.last() {
                    last_id = *last;
                }
                window = page
                    .into_iter()
                    .filter(|id| !processed.contains(id))
                    .collect();
                prefer_priority = true;
                if window.is_empty() {
                    let state = BackgroundWindow { last_id, max_id };
                    persist(releases, &cursor_key, &state, more_collections).await?;
                    continue;
                }
                reader = Some(Reader::Background);
            }
            windows += 1;
            break;
        }
        if window.is_empty() && !first {
            break;
        }
        first = false;
        totals.iterations += 1;
        archive_window(releases, settings, group, &window).await?;
        processed.extend(window.iter().copied());
        match reader {
            Some(Reader::Priority) => {
                persist(releases, &priority_key, &priority, more_priority).await?
            }
            Some(Reader::Background) => {
                let state = BackgroundWindow { last_id, max_id };
                persist(releases, &cursor_key, &state, more_collections).await?;
            }
            None => {}
        }
        let continues = (more_collections
            || (more_priority && probes < PRIORITY_GROUP_PROBES_PER_PASS))
            && windows < COLLECTION_WINDOWS_PER_PASS;
        if !continues {
            break;
        }
    }
    Ok(totals)
}

#[derive(Clone, Copy)]
enum Reader {
    Priority,
    Background,
}

/// Processes one bounded ready window for a group.
pub async fn process_ready_release_window(
    releases: &Releases,
    settings: &Settings,
    group: i64,
) -> Result<Totals> {
    if group <= 0 {
        bail!("Ready release processing requires a positive group ID.");
    }
    let db = &releases.verify.db;
    if stop_requested(db).await? {
        return Ok(Totals::default());
    }
    let limit = settings.release_creation_limit.clamp(1, READY_WINDOW_SIZE);
    let key = format!("{READY_WINDOW_CURSOR}{group}");
    let mut state = match releases.verify.cache.get::<PriorityState>(&key).await {
        Some(saved) if saved.valid(Some(group)) => saved,
        _ => {
            let (max,): (Option<u32>,) = sqlx::query_as("SELECT MAX(id) FROM collections")
                .fetch_one(db)
                .await?;
            PriorityState {
                max_id: i64::from(max.unwrap_or(0)),
                group_ids: vec![group],
                ..PriorityState::default()
            }
        }
    };
    let mut probes = 0;
    let window = next_priority_window(
        releases,
        settings,
        Some(group),
        &mut state,
        limit,
        &mut probes,
        &HashSet::new(),
    )
    .await?;
    let more = state.more();
    if window.is_empty() {
        persist(releases, &key, &state, more).await?;
        return Ok(Totals::default());
    }
    let result = archive_window(releases, settings, Some(group), &window).await;
    persist(releases, &key, &state, more).await?;
    result?;
    Ok(Totals { iterations: 1 })
}

/// Archives a window. Uploads over the metadata budget only read every part again and fail, so
/// they're deferred; a failed archive keeps its headers without starving later candidates or stopping
/// the window cursor from saving.
async fn archive_window(
    releases: &Releases,
    settings: &Settings,
    group: Option<i64>,
    allowed: &[i64],
) -> Result<()> {
    if allowed.is_empty() {
        return Ok(());
    }
    let mut query = QueryBuilder::<MySql>::new(format!(
        "SELECT collections.id FROM collections WHERE {ELIGIBLE} AND {READY}"
    ));
    if let Some(group) = group {
        query.push(" AND groups_id = ").push_bind(group);
    }
    query.push(" AND collections.id IN (");
    let mut separated = query.separated(",");
    for id in allowed {
        separated.push_bind(*id);
    }
    query.push(") ORDER BY collections.id LIMIT ");
    query.push_bind(settings.release_creation_limit.max(1));
    let rows: Vec<(u32,)> = query
        .build_query_as()
        .fetch_all(&releases.verify.db)
        .await?;
    let ids: Vec<i64> = rows.into_iter().map(|(id,)| i64::from(id)).collect();
    let kept = candidates::archive_each(&releases.verify, &ids).await;
    for (id, outcome) in &kept {
        if let Some(error) = &outcome.error {
            warn!(
                collection_id = id,
                error = format!("{error:#}"),
                retry_after = outcome.retry_after.as_deref(),
                "verification archival failed; original headers retained"
            );
        }
    }
    Ok(())
}
