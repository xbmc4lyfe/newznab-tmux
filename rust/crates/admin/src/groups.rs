//! Newsgroup administration (`AdminGroupController`, the group actions of `AdminAjaxController`
//! and `UsenetGroup`'s admin methods).

use anyhow::{Context, Result, bail};
use chrono::NaiveDateTime;
use nntmux_ingest::groups::ServerGroup;
use nntmux_verify::context::Verify;
use sqlx::{FromRow, MySqlPool, QueryBuilder};
use tracing::warn;

const MB: f64 = 1_048_576.0;
const GB: f64 = 1_073_741_824.0;

/// `UsenetGroup::isValidGroup`: a dotted name of word characters and dashes, with a leading
/// `a.b.` spelled out as `alt.binaries.`.
pub fn valid_name(name: &str) -> Option<String> {
    let parts: Vec<&str> = name.split('.').collect();
    let word = |part: &&str| {
        !part.is_empty()
            && part
                .bytes()
                .all(|byte| byte.is_ascii_alphanumeric() || byte == b'_' || byte == b'-')
    };
    if parts.len() < 2 || !parts.iter().all(word) {
        return None;
    }
    if name.len() >= 4 && name[..4].eq_ignore_ascii_case("a.b.") {
        return Some(format!("alt.binaries.{}", &name[4..]));
    }
    Some(name.to_owned())
}

/// A size as the admin form took it, a number of megabytes or gigabytes (`SizeUnit::toBytes`).
/// `500`, `500MB`, `1.5GB` and `0` are accepted; non-positive values are 0, which disables it.
pub fn parse_size(text: &str) -> Result<i64> {
    let trimmed = text.trim();
    let upper = trimmed.to_ascii_uppercase();
    let (number, unit) = if let Some(number) = upper.strip_suffix("GB") {
        (number, GB)
    } else if let Some(number) = upper.strip_suffix("MB") {
        (number, MB)
    } else {
        (upper.as_str(), MB)
    };
    let value: f64 = number
        .trim()
        .parse()
        .ok()
        .filter(|value: &f64| value.is_finite())
        .with_context(|| format!("{trimmed} isn't a size such as 500MB or 2GB"))?;
    #[allow(clippy::cast_possible_truncation)]
    Ok((value * unit).round().max(0.0) as i64)
}

/// `SizeUnit::fromBytes`, for display.
pub fn format_size(bytes: Option<i64>) -> String {
    match bytes {
        None => "default".to_owned(),
        Some(bytes) if bytes <= 0 => "0".to_owned(),
        Some(bytes) if bytes % (1 << 30) == 0 => format!("{}GB", bytes >> 30),
        Some(bytes) if bytes % (1 << 20) == 0 => format!("{}MB", bytes >> 20),
        #[allow(clippy::cast_precision_loss)]
        Some(bytes) => format!("{:.2}MB", bytes as f64 / MB),
    }
}

#[derive(Debug, FromRow)]
pub struct Group {
    pub id: u32,
    pub name: String,
    pub description: Option<String>,
    pub backfill_target: i32,
    pub first_record: u64,
    pub first_record_postdate: Option<NaiveDateTime>,
    pub last_record: u64,
    pub last_record_postdate: Option<NaiveDateTime>,
    pub last_updated: Option<NaiveDateTime>,
    pub minfilestoformrelease: Option<i32>,
    pub minsizetoformrelease: Option<i64>,
    pub active: bool,
    pub backfill: bool,
    pub releases: i64,
}

const COLUMNS: &str = "g.id, g.name, g.description, g.backfill_target, g.first_record, \
    g.first_record_postdate, g.last_record, g.last_record_postdate, g.last_updated, \
    g.minfilestoformrelease, g.minsizetoformrelease, g.active, g.backfill";

#[derive(Debug, Default)]
pub struct Filter {
    /// A substring of the name, as the list's search box.
    pub name: Option<String>,
    pub active: Option<bool>,
    pub backfill: Option<bool>,
}

/// `UsenetGroup::getGroupsRange` without the paging: matching groups by name, with their
/// release counts.
pub async fn list(db: &MySqlPool, filter: &Filter) -> Result<Vec<Group>> {
    let mut query = QueryBuilder::new(format!(
        "SELECT {COLUMNS}, (SELECT COUNT(*) FROM releases r WHERE r.groups_id = g.id) AS releases \
         FROM usenet_groups g WHERE 1 = 1"
    ));
    if let Some(name) = filter.name.as_deref().filter(|name| !name.is_empty()) {
        query
            .push(" AND g.name LIKE ")
            .push_bind(format!("%{}%", escape_like(name)));
    }
    if let Some(active) = filter.active {
        query.push(" AND g.active = ").push_bind(active);
    }
    if let Some(backfill) = filter.backfill {
        query.push(" AND g.backfill = ").push_bind(backfill);
    }
    query.push(" ORDER BY g.name, g.id");
    Ok(query.build_query_as().fetch_all(db).await?)
}

fn escape_like(text: &str) -> String {
    text.replace('\\', "\\\\")
        .replace('%', "\\%")
        .replace('_', "\\_")
}

/// One group by id or name.
pub async fn find(db: &MySqlPool, key: &str) -> Result<Option<Group>> {
    let sql = format!(
        "SELECT {COLUMNS}, (SELECT COUNT(*) FROM releases r WHERE r.groups_id = g.id) AS releases \
         FROM usenet_groups g WHERE g.name = ? OR (? AND g.id = ?) LIMIT 1"
    );
    let id = key.parse::<u32>().ok();
    Ok(sqlx::query_as(sqlx::AssertSqlSafe(sql))
        .bind(key)
        .bind(id.is_some())
        .bind(id.unwrap_or(0))
        .fetch_optional(db)
        .await?)
}

pub async fn require(db: &MySqlPool, key: &str) -> Result<Group> {
    find(db, key)
        .await?
        .with_context(|| format!("No group found: {key}"))
}

#[derive(Debug, Default)]
pub struct NewGroup {
    pub name: String,
    pub description: String,
    pub active: bool,
    pub backfill: bool,
    pub backfill_target: Option<i32>,
    pub min_files: Option<i32>,
    pub min_size: Option<i64>,
}

/// `UsenetGroup::addGroup`: the new group's id and true, or an existing group's id and false.
pub async fn add(db: &MySqlPool, group: &NewGroup) -> Result<(u32, bool)> {
    let name = group.name.trim();
    if let Some(id) = sqlx::query_scalar("SELECT id FROM usenet_groups WHERE name = ?")
        .bind(name)
        .fetch_optional(db)
        .await?
    {
        return Ok((id, false));
    }
    let id = sqlx::query(
        "INSERT INTO usenet_groups (name, description, backfill_target, first_record, \
         last_record, active, backfill, minsizetoformrelease, minfilestoformrelease) \
         VALUES (?, ?, ?, 0, 0, ?, ?, ?, ?)",
    )
    .bind(name)
    .bind(group.description.trim())
    .bind(group.backfill_target.unwrap_or(1))
    .bind(group.active)
    .bind(group.backfill)
    .bind(group.min_size)
    .bind(group.min_files)
    .execute(db)
    .await?
    .last_insert_id();
    Ok((u32::try_from(id)?, true))
}

/// A field to change on an existing group. `None` inside a min setting falls back to the site
/// setting, as a blank field on the form did.
#[derive(Debug, Default)]
pub struct Changes {
    pub name: Option<String>,
    pub description: Option<String>,
    pub backfill_target: Option<i32>,
    pub first_record: Option<u64>,
    pub last_record: Option<u64>,
    pub active: Option<bool>,
    pub backfill: Option<bool>,
    pub min_files: Option<Option<i32>>,
    pub min_size: Option<Option<i64>>,
}

impl Changes {
    pub fn is_empty(&self) -> bool {
        self.name.is_none()
            && self.description.is_none()
            && self.backfill_target.is_none()
            && self.first_record.is_none()
            && self.last_record.is_none()
            && self.active.is_none()
            && self.backfill.is_none()
            && self.min_files.is_none()
            && self.min_size.is_none()
    }
}

/// `UsenetGroup::updateGroup`, changing only the given fields. Like the edit form, it stamps
/// `last_updated`.
pub async fn update(db: &MySqlPool, id: u32, changes: &Changes, now: NaiveDateTime) -> Result<()> {
    let mut query = QueryBuilder::new("UPDATE usenet_groups SET last_updated = ");
    query.push_bind(now);
    if let Some(name) = &changes.name {
        let name =
            valid_name(name.trim()).with_context(|| format!("{name} isn't a valid group name"))?;
        query.push(", name = ").push_bind(name);
    }
    if let Some(description) = &changes.description {
        query
            .push(", description = ")
            .push_bind(description.trim().to_owned());
    }
    if let Some(target) = changes.backfill_target {
        query.push(", backfill_target = ").push_bind(target);
    }
    if let Some(first) = changes.first_record {
        query.push(", first_record = ").push_bind(first);
    }
    if let Some(last) = changes.last_record {
        query.push(", last_record = ").push_bind(last);
    }
    if let Some(active) = changes.active {
        query.push(", active = ").push_bind(active);
    }
    if let Some(backfill) = changes.backfill {
        query.push(", backfill = ").push_bind(backfill);
    }
    if let Some(files) = changes.min_files {
        query.push(", minfilestoformrelease = ").push_bind(files);
    }
    if let Some(size) = changes.min_size {
        query.push(", minsizetoformrelease = ").push_bind(size);
    }
    query.push(" WHERE id = ").push_bind(id);
    query.build().execute(db).await.map_err(|failure| {
        if failure
            .as_database_error()
            .is_some_and(|error| error.is_unique_violation())
        {
            anyhow::anyhow!("Another group already has that name.")
        } else {
            failure.into()
        }
    })?;
    Ok(())
}

#[derive(Clone, Copy, Debug)]
pub enum Flag {
    Active,
    Backfill,
}

/// `UsenetGroup::updateGroupStatus`.
pub async fn set_flag(db: &MySqlPool, id: u32, flag: Flag, on: bool) -> Result<bool> {
    let sql = match flag {
        Flag::Active => "UPDATE usenet_groups SET active = ? WHERE id = ?",
        Flag::Backfill => "UPDATE usenet_groups SET backfill = ? WHERE id = ?",
    };
    Ok(sqlx::query(sql)
        .bind(on)
        .bind(id)
        .execute(db)
        .await?
        .rows_affected()
        > 0)
}

const RESET_STATS: &str = "backfill_target = 1, first_record = 0, first_record_postdate = NULL, \
    last_record = 0, last_record_postdate = NULL, last_updated = NULL, active = 0";

/// `UsenetGroup::reset`: drops the group's part repair rows and unfinished collections (their
/// binaries, parts and group links go with them by foreign key), then starts its counters over
/// and turns it off. Verification is held out meanwhile, and the group's unpublished candidates
/// are expired, so work already queued can't publish into the group afterwards.
pub async fn reset(verify: &Verify, id: u32) -> Result<()> {
    nntmux_verify::reset::fenced(verify, || async {
        nntmux_verify::candidates::expire_group(verify, id).await?;
        reset_rows(&verify.db, id).await
    })
    .await
}

async fn reset_rows(db: &MySqlPool, id: u32) -> Result<()> {
    let mut tx = db.begin().await?;
    sqlx::query("DELETE FROM missed_parts WHERE groups_id = ?")
        .bind(id)
        .execute(&mut *tx)
        .await?;
    sqlx::query("DELETE FROM collections WHERE groups_id = ?")
        .bind(id)
        .execute(&mut *tx)
        .await?;
    sqlx::query(sqlx::AssertSqlSafe(format!(
        "UPDATE usenet_groups SET {RESET_STATS} WHERE id = ?"
    )))
    .bind(id)
    .execute(&mut *tx)
    .await?;
    tx.commit().await?;
    Ok(())
}

/// `UsenetGroup::resetall`: empties every unfinished collection and part repair table, then
/// resets every group. `collection_groups` is emptied with them, which Laravel left orphaned. It
/// runs as a verification reset, like `nntmux:reset-truncate`, which clears all queued
/// verification work.
pub async fn reset_all(verify: &Verify) -> Result<u64> {
    nntmux_verify::reset::run(verify, || reset_all_rows(&verify.db)).await
}

async fn reset_all_rows(db: &MySqlPool) -> Result<u64> {
    let mut conn = db.acquire().await?;
    sqlx::query("SET FOREIGN_KEY_CHECKS = 0")
        .execute(&mut *conn)
        .await?;
    let mut truncated = Ok(());
    for table in [
        "missed_parts",
        "parts",
        "binaries",
        "collection_groups",
        "collections",
    ] {
        if let Err(failure) = sqlx::query(sqlx::AssertSqlSafe(format!("TRUNCATE TABLE {table}")))
            .execute(&mut *conn)
            .await
        {
            truncated = Err(failure).with_context(|| format!("emptying {table}"));
            break;
        }
    }
    // Always turn the checks back on, since the connection goes back to the pool.
    sqlx::query("SET FOREIGN_KEY_CHECKS = 1")
        .execute(&mut *conn)
        .await?;
    truncated?;
    Ok(sqlx::query(sqlx::AssertSqlSafe(format!(
        "UPDATE usenet_groups SET {RESET_STATS}"
    )))
    .execute(&mut *conn)
    .await?
    .rows_affected())
}

/// `UsenetGroup::purge`: resets one group, or all of them, then deletes its releases with their
/// NZBs, files and search documents. A release that fails is reported and skipped. Verification
/// stays held out until the releases are gone.
pub async fn purge(verify: &Verify, id: Option<u32>) -> Result<(u64, u64)> {
    match id {
        Some(id) => {
            nntmux_verify::reset::fenced(verify, || async {
                nntmux_verify::candidates::expire_group(verify, id).await?;
                reset_rows(&verify.db, id).await?;
                delete_releases(&verify.db, Some(id)).await
            })
            .await
        }
        None => {
            nntmux_verify::reset::run(verify, || async {
                reset_all_rows(&verify.db).await?;
                delete_releases(&verify.db, None).await
            })
            .await
        }
    }
}

async fn delete_releases(db: &MySqlPool, id: Option<u32>) -> Result<(u64, u64)> {
    let (mut deleted, mut failed) = (0, 0);
    let mut after = 0_u32;
    loop {
        let rows: Vec<(u32, String)> = sqlx::query_as(
            "SELECT id, guid FROM releases WHERE id > ? AND (? OR groups_id = ?) \
             ORDER BY id LIMIT 1000",
        )
        .bind(after)
        .bind(id.is_none())
        .bind(id.unwrap_or(0))
        .fetch_all(db)
        .await?;
        let Some(last) = rows.last() else {
            break;
        };
        after = last.0;
        for (release, guid) in &rows {
            match nntmux_verify::releases::delete_single_on(db, i64::from(*release), guid).await {
                Ok(()) => deleted += 1,
                Err(failure) => {
                    failed += 1;
                    warn!(
                        release,
                        error = format!("{failure:#}"),
                        "failed to delete a release"
                    );
                }
            }
        }
    }
    Ok((deleted, failed))
}

/// `UsenetGroup::deleteGroup`: purges the group, then removes it, all behind the verification
/// fence. A group whose releases couldn't all be deleted is kept, so none is left pointing at a
/// missing group.
pub async fn delete(verify: &Verify, id: u32) -> Result<(u64, bool)> {
    let db = &verify.db;
    nntmux_verify::reset::fenced(verify, || async {
        nntmux_verify::candidates::expire_group(verify, id).await?;
        reset_rows(db, id).await?;
        let (deleted, failed) = delete_releases(db, Some(id)).await?;
        if failed > 0 {
            bail!("{failed} release(s) couldn't be deleted, so the group is kept; see the log");
        }
        let removed = sqlx::query("DELETE FROM usenet_groups WHERE id = ?")
            .bind(id)
            .execute(db)
            .await?
            .rows_affected()
            > 0;
        Ok((deleted, removed))
    })
    .await
}

/// What `addBulk` reported for each group.
#[derive(Debug, PartialEq, Eq)]
pub struct Created {
    pub name: String,
    pub id: u32,
}

/// `UsenetGroup::addBulk`: adds every group on the server whose name matches `pattern`
/// (case-insensitive, as `/pattern/i`) and isn't stored yet.
pub async fn add_bulk(
    db: &MySqlPool,
    listed: &[ServerGroup],
    pattern: &str,
    active: bool,
    backfill: bool,
) -> Result<Vec<Created>> {
    if pattern.trim().is_empty() {
        bail!("No group list provided.");
    }
    let regex = fancy_regex::Regex::new(&format!("(?i){pattern}"))
        .with_context(|| format!("{pattern} isn't a valid regular expression"))?;
    let mut created = Vec::new();
    for group in listed {
        if !regex.is_match(&group.name).unwrap_or(false) {
            continue;
        }
        let (id, new) = add(
            db,
            &NewGroup {
                name: group.name.clone(),
                description: "Added by bulkAdd".to_owned(),
                active,
                backfill,
                ..NewGroup::default()
            },
        )
        .await?;
        if new {
            created.push(Created {
                name: group.name.trim().to_owned(),
                id,
            });
        }
    }
    Ok(created)
}
