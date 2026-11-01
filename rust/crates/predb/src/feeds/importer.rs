//! Upserts feed entries into `predb` by title, mirroring the IRC scraper: new titles are inserted
//! and indexed, and existing rows only gain missing details or nuke changes. Rows the database
//! refuses as data are skipped; infrastructure failures propagate.

use std::collections::HashMap;

use anyhow::{Result, anyhow, bail};
use chrono::NaiveDateTime;
use sqlx::{MySqlPool, Row};

use super::index::PredbIndex;
use super::{Entry, PRE_NONUKE};
use crate::filename::exact_value;
use crate::identity::{self, InsertOutcome, NewPredb};

#[derive(Clone, Copy, Debug, Default, PartialEq)]
pub struct Totals {
    pub inserted: u64,
    pub updated: u64,
    pub skipped: u64,
}

#[derive(Clone, Debug)]
struct PredbRow {
    id: Option<u64>,
    title: String,
    category: Option<String>,
    size: Option<String>,
    files: Option<String>,
    predate: Option<NaiveDateTime>,
    nuked: i8,
    nuke_reason: Option<String>,
}

fn cut(value: &str, limit: usize) -> String {
    value.chars().take(limit).collect()
}

fn empty(value: &Option<String>) -> bool {
    value.as_deref().is_none_or(str::is_empty)
}

#[derive(Default, Debug, PartialEq)]
struct Changes {
    category: Option<String>,
    size: Option<String>,
    files: Option<String>,
    predate: Option<NaiveDateTime>,
    /// Status transition: `(from, to, reason)`.
    nuke: Option<(i8, i8, Option<String>)>,
    /// Same status, new reason: `(status, observed, reason)`.
    reason: Option<(i8, Option<String>, String)>,
}

impl Changes {
    fn is_empty(&self) -> bool {
        *self == Self::default()
    }
}

/// Only fills details the row is missing, and records nuke status changes.
fn changes_for(existing: &PredbRow, entry: &Entry) -> Changes {
    let mut changes = Changes::default();
    if empty(&existing.category) {
        changes.category = entry.category.as_deref().map(|v| cut(v, 255));
    }
    if empty(&existing.size) {
        changes.size = entry.size.as_deref().map(|v| cut(v, 50));
    }
    if empty(&existing.files) {
        changes.files = entry.files.as_deref().map(|v| cut(v, 50));
    }
    if existing.predate.is_none() {
        changes.predate = entry.predate;
    }
    let reason = entry.nuke_reason.as_deref().map(|v| cut(v, 255));
    if entry.nuked != PRE_NONUKE && existing.nuked != entry.nuked {
        changes.nuke = Some((
            existing.nuked,
            entry.nuked,
            reason.or_else(|| existing.nuke_reason.clone()),
        ));
    } else if entry.nuked != PRE_NONUKE
        && let Some(reason) = reason
        && reason != existing.nuke_reason.clone().unwrap_or_default()
    {
        changes.reason = Some((entry.nuked, existing.nuke_reason.clone(), reason));
    }
    changes
}

pub struct Importer {
    db: MySqlPool,
    index: PredbIndex,
    /// Dry runs record what they would have written, so later pages and overlapping feeds in
    /// one run count as updates and skips rather than more inserts.
    shadow: HashMap<String, PredbRow>,
}

impl Importer {
    pub fn new(db: MySqlPool, index: PredbIndex) -> Self {
        Self {
            db,
            index,
            shadow: HashMap::new(),
        }
    }

    pub async fn import(&mut self, entries: &[Entry], dry_run: bool) -> Result<Totals> {
        let mut totals = Totals::default();
        for entry in entries {
            match self.import_entry(entry, dry_run).await? {
                Outcome::Inserted => totals.inserted += 1,
                Outcome::Updated => totals.updated += 1,
                Outcome::Skipped => totals.skipped += 1,
            }
        }
        Ok(totals)
    }

    async fn find(&self, title: &str) -> Result<Option<PredbRow>> {
        let row = sqlx::query(
            "SELECT id, title, category, size, files, predate, nuked, nukereason \
             FROM predb WHERE title = ? LIMIT 1",
        )
        .bind(title)
        .fetch_optional(&self.db)
        .await?;
        row.map(|row| {
            Ok(PredbRow {
                id: Some(row.try_get("id")?),
                title: row.try_get("title")?,
                category: row.try_get("category")?,
                size: row.try_get("size")?,
                files: row.try_get("files")?,
                predate: row.try_get("predate")?,
                nuked: row.try_get("nuked")?,
                nuke_reason: row.try_get("nukereason")?,
            })
        })
        .transpose()
    }

    /// The title column's own idea of equality, without trailing-space weights.
    async fn shadow_key(&self, title: &str) -> Result<String> {
        let (weight, space): (String, String) = sqlx::query_as(
            "SELECT HEX(WEIGHT_STRING(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci)), \
             HEX(WEIGHT_STRING(CONVERT(' ' USING utf8mb4) COLLATE utf8mb4_unicode_ci))",
        )
        .bind(title)
        .fetch_one(&self.db)
        .await?;
        if space.is_empty() {
            bail!("Unable to verify the PreDB dry-run title collation.");
        }
        let mut weight = weight.as_str();
        while let Some(trimmed) = weight.strip_suffix(space.as_str()) {
            weight = trimmed;
        }
        Ok(weight.to_owned())
    }

    async fn import_entry(&mut self, entry: &Entry, dry_run: bool) -> Result<Outcome> {
        let title = entry.title.trim();
        if title.is_empty() || title.chars().count() > 255 {
            return Ok(Outcome::Skipped);
        }
        let key = if dry_run {
            self.shadow_key(title).await?
        } else {
            String::new()
        };
        let shadowed = if dry_run {
            self.shadow.get(&key).cloned()
        } else {
            None
        };
        let existing = match shadowed {
            Some(row) => Some(row),
            None => self.find(title).await?,
        };

        let existing = match existing {
            Some(row) => row,
            None => {
                if entry.enrich_only {
                    return Ok(Outcome::Skipped);
                }
                if dry_run {
                    self.shadow.insert(
                        key,
                        PredbRow {
                            id: None,
                            title: title.to_owned(),
                            category: entry.category.clone(),
                            size: entry.size.clone(),
                            files: entry.files.clone(),
                            predate: entry.predate,
                            nuked: entry.nuked,
                            nuke_reason: entry.nuke_reason.clone(),
                        },
                    );
                    return Ok(Outcome::Inserted);
                }
                let row = NewPredb {
                    title: title.to_owned(),
                    source: entry.source.clone(),
                    category: entry.category.clone(),
                    size: entry.size.clone(),
                    files: entry.files.clone(),
                    predate: entry.predate,
                    nuked: entry.nuked,
                    nuke_reason: entry.nuke_reason.clone(),
                };
                match identity::insert_feed(&self.db, &row).await? {
                    InsertOutcome::Inserted(id) => {
                        self.index.replace(id, title, "", &entry.source).await;
                        return Ok(Outcome::Inserted);
                    }
                    InsertOutcome::Rejected(error) => {
                        let unique = error
                            .as_database_error()
                            .is_some_and(|db| db.is_unique_violation());
                        if !unique {
                            tracing::warn!(title, source = entry.source, %error, "PreDB feed entry import failed");
                            return Ok(Outcome::Skipped);
                        }
                        // Another ingester won the insert: enrich its row instead.
                        match self.find(title).await? {
                            Some(row) => row,
                            None => return Ok(Outcome::Skipped),
                        }
                    }
                }
            }
        };

        if exact_value(&existing.title) != exact_value(title) {
            return Ok(Outcome::Skipped);
        }
        let changes = changes_for(&existing, entry);
        if changes.is_empty() {
            if !dry_run && let Some(id) = existing.id {
                // Idempotent re-index, so a document dropped by a failed index write recovers.
                self.reindex(id, title).await?;
            }
            return Ok(Outcome::Skipped);
        }
        if dry_run {
            let mut shadow = existing;
            apply_to_shadow(&mut shadow, changes);
            self.shadow.insert(key, shadow);
            return Ok(Outcome::Updated);
        }
        let id = existing.id.ok_or_else(|| anyhow!("PreDB row has no id"))?;
        match self.apply_changes(id, &existing.title, changes).await {
            Ok(true) => {}
            Ok(false) => return Ok(Outcome::Skipped),
            Err(error)
                if error
                    .as_database_error()
                    .is_some_and(|db| db.code().is_some_and(|code| code.starts_with("22"))) =>
            {
                tracing::warn!(title, source = entry.source, %error, "PreDB feed entry import failed");
                return Ok(Outcome::Skipped);
            }
            Err(error) => return Err(error.into()),
        }
        Ok(if self.reindex(id, title).await? {
            Outcome::Updated
        } else {
            Outcome::Skipped
        })
    }

    /// Re-reads the indexed fields, since another ingester may have changed them.
    async fn reindex(&self, id: u64, expected_title: &str) -> Result<bool> {
        let row: Option<(String, String, String)> =
            sqlx::query_as("SELECT title, filename, source FROM predb WHERE id = ?")
                .bind(id)
                .fetch_optional(&self.db)
                .await?;
        let Some((title, filename, source)) = row else {
            return Ok(false);
        };
        if exact_value(&title) != exact_value(expected_title) {
            return Ok(false);
        }
        self.index.replace(id, &title, &filename, &source).await;
        Ok(true)
    }

    /// Fill-only changes, each written only while the column is still empty and the row still
    /// has the title that was read. A nuke change applies only while the status still differs.
    async fn apply_changes(
        &self,
        id: u64,
        title: &str,
        changes: Changes,
    ) -> std::result::Result<bool, sqlx::Error> {
        let mut changed = false;
        for (column, value) in [
            ("category", &changes.category),
            ("size", &changes.size),
            ("files", &changes.files),
        ] {
            if let Some(value) = value {
                let sql = format!(
                    "UPDATE predb SET {column} = ? WHERE id = ? AND HEX(title) = HEX(?) \
                     AND ({column} IS NULL OR {column} = '')"
                );
                changed |= sqlx::query(sqlx::AssertSqlSafe(sql))
                    .bind(value)
                    .bind(id)
                    .bind(title)
                    .execute(&self.db)
                    .await?
                    .rows_affected()
                    > 0;
            }
        }
        if let Some(predate) = changes.predate {
            changed |= sqlx::query(
                "UPDATE predb SET predate = ? WHERE id = ? AND HEX(title) = HEX(?) AND predate IS NULL",
            )
            .bind(predate)
            .bind(id)
            .bind(title)
            .execute(&self.db)
            .await?
            .rows_affected()
                > 0;
        }
        if let Some((from, to, reason)) = changes.nuke {
            // Transition from the status that was read; if another ingester changed it, keep theirs.
            changed |= sqlx::query(
                "UPDATE predb SET nuked = ?, nukereason = ? WHERE id = ? AND HEX(title) = HEX(?) AND nuked = ?",
            )
            .bind(to)
            .bind(reason)
            .bind(id)
            .bind(title)
            .bind(from)
            .execute(&self.db)
            .await?
            .rows_affected()
                > 0;
        } else if let Some((status, observed, reason)) = changes.reason {
            let observed = observed.filter(|value| !value.is_empty());
            let query = match &observed {
                None => sqlx::query(
                    "UPDATE predb SET nukereason = ? WHERE id = ? AND HEX(title) = HEX(?) AND nuked = ? \
                     AND (nukereason IS NULL OR nukereason = '')",
                ),
                Some(_) => sqlx::query(
                    "UPDATE predb SET nukereason = ? WHERE id = ? AND HEX(title) = HEX(?) AND nuked = ? \
                     AND nukereason = ?",
                ),
            };
            let mut query = query.bind(&reason).bind(id).bind(title).bind(status);
            if let Some(observed) = &observed {
                query = query.bind(observed);
            }
            changed |= query.execute(&self.db).await?.rows_affected() > 0;
        }
        Ok(changed)
    }
}

fn apply_to_shadow(shadow: &mut PredbRow, changes: Changes) {
    if changes.category.is_some() {
        shadow.category = changes.category;
    }
    if changes.size.is_some() {
        shadow.size = changes.size;
    }
    if changes.files.is_some() {
        shadow.files = changes.files;
    }
    if changes.predate.is_some() {
        shadow.predate = changes.predate;
    }
    if let Some((_, to, reason)) = changes.nuke {
        shadow.nuked = to;
        shadow.nuke_reason = reason;
    }
    if let Some((_, _, reason)) = changes.reason {
        shadow.nuke_reason = Some(reason);
    }
}

#[derive(Clone, Copy, Debug, PartialEq)]
enum Outcome {
    Inserted,
    Updated,
    Skipped,
}
