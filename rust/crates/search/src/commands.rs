//! `nntmux-ctl search ...`

use anyhow::{Context, Result, anyhow, bail};
use chrono::Utc;
use clap::Subcommand;
use serde_json::Value;
use sqlx::MySqlPool;

use crate::document::{ReleaseDocument, ReleaseRow};
use crate::reconcile::{ReconcileOptions, default_since, parse_since};
use crate::{SearchClient, SearchError};

#[derive(Debug, Subcommand)]
pub enum Command {
    /// Create Manticore tables (manticore:create-indexes).
    CreateIndexes {
        /// Drop selected tables first. Use in a maintenance window, then repopulate.
        #[arg(long)]
        drop: bool,
        /// Logical index to create. Repeat for more. Defaults to all.
        #[arg(long = "index")]
        indexes: Vec<String>,
    },
    /// Create the movies and tvshows tables (nntmux:create-media-indexes).
    CreateMediaIndexes {
        #[arg(long)]
        drop: bool,
    },
    /// Report the Manticore version and whether tables match the schema (manticore:inspect).
    Inspect {
        #[arg(long)]
        json: bool,
    },
    /// Show a table's document count, a sample and its structure (nntmux:check-index).
    CheckIndex {
        /// Logical index, such as releases or predb.
        index: String,
    },
    /// Compare release rows with their documents (nntmux:search-diag).
    Diag {
        /// Release ids or guids.
        ids: Vec<String>,
        /// Match releases whose name or searchname contains this.
        #[arg(long)]
        name: Option<String>,
        #[arg(long, default_value_t = 20)]
        limit: u32,
        /// Print the row and the document.
        #[arg(long)]
        show_fields: bool,
    },
    /// Find rows missing from Manticore and optionally repair them (nntmux:search-reconcile).
    Reconcile {
        /// Reconcile PreDB. Requires --all.
        #[arg(long)]
        predb: bool,
        /// Only releases added in this window, such as 24h or 7d.
        #[arg(long, default_value = "24h")]
        since: String,
        /// Scan every row.
        #[arg(long)]
        all: bool,
        #[arg(long, default_value_t = 1000)]
        chunk: u32,
        #[arg(long, default_value_t = 500)]
        in_batch: u32,
        /// Also find indexed ids with no release row.
        #[arg(long)]
        orphans: bool,
        /// Repair what's found.
        #[arg(long)]
        reindex: bool,
        /// Report only.
        #[arg(long)]
        dry_run: bool,
    },
    /// Retry failed index updates (nntmux:search-repair).
    Repair {
        #[arg(long, default_value_t = 100)]
        limit: u32,
        /// List what's due without writing.
        #[arg(long)]
        dry_run: bool,
    },
}

pub async fn run(client: &SearchClient, db: &MySqlPool, command: Command) -> Result<()> {
    match command {
        Command::CreateIndexes { drop, indexes } => {
            print_lines(&client.create_indexes(&indexes, drop).await?);
            println!("All Manticore tables are ready.");
        }
        Command::CreateMediaIndexes { drop } => {
            let media = ["movies".to_owned(), "tvshows".to_owned()];
            print_lines(&client.create_indexes(&media, drop).await?);
        }
        Command::Inspect { json } => inspect(client, json).await?,
        Command::CheckIndex { index } => check_index(client, &index).await?,
        Command::Diag {
            ids,
            name,
            limit,
            show_fields,
        } => diag(client, db, &ids, name.as_deref(), limit, show_fields).await?,
        Command::Reconcile {
            predb,
            since,
            all,
            chunk,
            in_batch,
            orphans,
            reindex,
            dry_run,
        } => {
            client.require_available().await?;
            if dry_run && reindex {
                println!("--reindex with --dry-run: no writes will be performed.");
            }
            let reindex = reindex && !dry_run;
            if predb {
                if !all || orphans {
                    bail!("--predb requires --all and does not support --orphans");
                }
                reconcile_predb(client, db, chunk, in_batch, reindex).await?;
            } else {
                let since = if all {
                    None
                } else {
                    Some(
                        parse_since(&since, Utc::now().naive_utc()).unwrap_or_else(|| {
                            println!("Could not parse --since={since}; defaulting to 24 hours.");
                            default_since()
                        }),
                    )
                };
                let options = ReconcileOptions {
                    since,
                    chunk,
                    in_batch,
                    orphans,
                    reindex,
                };
                reconcile_releases(client, db, &options, dry_run).await?;
            }
        }
        Command::Repair { limit, dry_run } => {
            if dry_run {
                let due = client.due_failures(db, limit).await?;
                for id in &due {
                    println!("{id}");
                }
                println!("{} release index failure(s) reported.", due.len());
            } else {
                let report = client.repair(db, limit).await?;
                if report.due == 0 {
                    println!("No failed release index updates are due for repair.");
                } else {
                    println!(
                        "{} release index failure(s) processed, {} repaired.",
                        report.due, report.repaired
                    );
                }
            }
        }
    }
    Ok(())
}

fn print_lines(lines: &[String]) {
    for line in lines {
        println!("{line}");
    }
}

fn joined(ids: &[i64]) -> String {
    ids.iter()
        .take(20)
        .map(i64::to_string)
        .collect::<Vec<_>>()
        .join(", ")
}

async fn inspect(client: &SearchClient, json: bool) -> Result<()> {
    let report = client.inspect().await?;
    if json {
        println!("{}", serde_json::to_string_pretty(&report)?);
    } else {
        println!("Manticore version: {}", report.version);
        for (table, status) in &report.tables {
            let verdict = if status.needs_rebuild {
                "REBUILD REQUIRED"
            } else {
                "compatible"
            };
            println!("{table:<20} {verdict}");
        }
    }
    if !report.compatible {
        bail!(
            "tables don't match the schema; rebuild the affected index in a maintenance window \
             with `search create-indexes --drop --index=<name>`, then repopulate it"
        );
    }
    Ok(())
}

async fn check_index(client: &SearchClient, index: &str) -> Result<()> {
    if !crate::config::LOGICAL_INDEXES.contains(&index) {
        bail!("unknown logical index: {index}");
    }
    let table = client.config().indexes.table(index).to_owned();
    let count = client.count_index_documents(&table).await?;
    println!("ManticoreSearch table '{table}' exists.");
    println!("Document count: {count}");
    if count > 0 {
        let sample = client
            .sql(&format!("SELECT * FROM {table} LIMIT 1"))
            .await?;
        if let Some(row) = sample.first() {
            println!("Sample document:\n{}", serde_json::to_string_pretty(row)?);
        }
    }
    println!("\nTable structure:");
    for row in client.sql(&format!("DESCRIBE {table}")).await? {
        let field = |name| row.get(name).and_then(Value::as_str).unwrap_or_default();
        println!(
            "{:<28} {:<10} {}",
            field("Field"),
            field("Type"),
            field("Properties")
        );
    }
    Ok(())
}

async fn reconcile_releases(
    client: &SearchClient,
    db: &MySqlPool,
    options: &ReconcileOptions,
    dry_run: bool,
) -> Result<()> {
    let report = client.reconcile_releases(db, options).await?;
    let scope = options.since.map_or_else(
        || "all release rows".to_owned(),
        |since| format!("adddate >= {since}"),
    );
    println!(
        "Scanned {} release row(s); missing in Manticore index: {} ({scope})",
        report.scanned,
        report.missing.len()
    );
    if !report.missing.is_empty() {
        println!("Sample ids: {}", joined(&report.missing));
    }
    if dry_run && report.scanned >= 50 && report.missing.len() == report.scanned {
        tracing::warn!("every scanned release is missing; check the index was populated");
        println!("Warning: every scanned release is missing. Check that the index was populated.");
    }
    if !report.orphans.is_empty() {
        println!(
            "Indexed documents with no MySQL release row: {}",
            report.orphans.len()
        );
        println!("Orphan sample ids: {}", joined(&report.orphans));
    }
    if report.missing.is_empty() && report.orphans.is_empty() {
        return Ok(());
    }
    if !options.reindex {
        println!("Run with --reindex to repair missing documents and remove orphaned ids.");
        return Ok(());
    }
    if report.orphans_removed {
        println!("Removed {} orphaned document(s).", report.orphans.len());
    } else if !report.orphans.is_empty() {
        println!("Failed removing orphaned documents; they have retry rows.");
    }
    println!(
        "Reindexed {} of {} release(s). The rest have retry rows.",
        report.reindexed,
        report.missing.len()
    );
    Ok(())
}

async fn reconcile_predb(
    client: &SearchClient,
    db: &MySqlPool,
    chunk: u32,
    in_batch: u32,
    reindex: bool,
) -> Result<()> {
    let report = client.reconcile_predb(db, chunk, in_batch, reindex).await;
    let position = format!(
        "Last completed ID: {}; frozen max ID: {}",
        report.last_completed_id, report.max_id
    );
    if let Some(failure) = &report.failure {
        println!("{position}");
        bail!("PreDB reconciliation failed: {failure}");
    }
    println!(
        "Scanned {} PreDB row(s); missing in Manticore index: {}",
        report.scanned, report.missing
    );
    println!("{position}");
    if !report.sample.is_empty() {
        println!("Sample ids: {}", joined(&report.sample));
    }
    if reindex {
        println!("Reindexed {} PreDB row(s).", report.repaired);
    } else if report.missing > 0 {
        println!("Run with --predb --all --reindex to repair missing documents.");
    }
    Ok(())
}

async fn show_passworded_releases(db: &MySqlPool) -> Result<bool> {
    let show: Option<bool> =
        sqlx::query_scalar("SELECT show_passworded_releases FROM ingestion_configurations LIMIT 1")
            .fetch_optional(db)
            .await
            .context("reading show_passworded_releases")?;
    Ok(show.unwrap_or(false))
}

async fn diag(
    client: &SearchClient,
    db: &MySqlPool,
    tokens: &[String],
    name: Option<&str>,
    limit: u32,
    show_fields: bool,
) -> Result<()> {
    client.require_available().await?;
    let mut ids = Vec::new();
    if let Some(name) = name.filter(|name| !name.is_empty()) {
        let escaped = name
            .replace('\\', "\\\\")
            .replace('%', "\\%")
            .replace('_', "\\_");
        let like = format!("%{escaped}%");
        ids = sqlx::query_scalar(
            "SELECT CAST(id AS SIGNED) FROM releases WHERE name LIKE ? OR searchname LIKE ? \
             ORDER BY id DESC LIMIT ?",
        )
        .bind(&like)
        .bind(&like)
        .bind(limit.clamp(1, 500))
        .fetch_all(db)
        .await?;
        if ids.is_empty() {
            println!("No releases matched --name filter.");
            return Ok(());
        }
    } else {
        if tokens.is_empty() {
            bail!("provide at least one id or guid, or use --name");
        }
        for token in tokens {
            let found: Option<i64> = if token.bytes().all(|byte| byte.is_ascii_digit()) {
                sqlx::query_scalar("SELECT CAST(id AS SIGNED) FROM releases WHERE id = ?")
                    .bind(token)
                    .fetch_optional(db)
                    .await?
            } else {
                sqlx::query_scalar("SELECT CAST(id AS SIGNED) FROM releases WHERE guid = ?")
                    .bind(token)
                    .fetch_optional(db)
                    .await?
            };
            match found {
                Some(id) => ids.push(id),
                None => println!("No release found for token: {token}"),
            }
        }
    }
    if ids.is_empty() {
        return Err(anyhow!("no releases found"));
    }
    let show_passwords = show_passworded_releases(db).await?;
    for id in ids {
        println!(
            "{}",
            diagnose(client, db, id, show_passwords, show_fields).await?
        );
    }
    Ok(())
}

async fn diagnose(
    client: &SearchClient,
    db: &MySqlPool,
    id: i64,
    show_passwords: bool,
    show_fields: bool,
) -> Result<String, SearchError> {
    let row = client.release_row(db, id).await?;
    let Some(row) = row else {
        return Ok(format!("{id} | NOT_IN_DB"));
    };
    let document = ReleaseDocument::from(ReleaseRow::clone(&row));
    let table = client.config().indexes.table("releases").replace('`', "``");
    let found = client
        .sql(&format!("SELECT * FROM `{table}` WHERE id = {id}"))
        .await?;
    let status = classify(&document, found.first(), show_passwords, show_fields);
    let mut line = format!(
        "{id} | {status} | searchname={} | size={} | passwordstatus={}",
        document.searchname, document.size, document.passwordstatus
    );
    if show_fields {
        let expected = serde_json::to_string(&document).unwrap_or_default();
        let actual = found.first().map_or_else(
            || "null".to_owned(),
            |row| serde_json::to_string(row).unwrap_or_default(),
        );
        line.push_str(&format!("\nDB: {expected}\nIX: {actual}"));
    }
    Ok(line)
}

fn indexed_text(row: &serde_json::Map<String, Value>, field: &str) -> String {
    match row.get(field) {
        Some(Value::String(text)) => text.clone(),
        Some(Value::Null) | None => String::new(),
        Some(other) => other.to_string(),
    }
}

fn indexed_number(row: &serde_json::Map<String, Value>, field: &str) -> i64 {
    indexed_text(row, field).parse().unwrap_or(0)
}

/// `MISSING_IN_INDEX`, `STALE_INDEX`, `STATUS_HIDDEN` or `OK`. Dates may drift by two seconds.
pub fn classify(
    document: &ReleaseDocument,
    indexed: Option<&serde_json::Map<String, Value>>,
    show_passwords: bool,
    show_fields: bool,
) -> &'static str {
    let Some(indexed) = indexed else {
        return "MISSING_IN_INDEX";
    };
    let expected = [
        ("searchname", document.searchname.clone()),
        ("name", document.name.clone()),
        ("categories_id", document.categories_id.to_string()),
        ("passwordstatus", document.passwordstatus.to_string()),
        ("size", document.size.to_string()),
        ("groups_id", document.groups_id.to_string()),
        ("nzbstatus", document.nzbstatus.to_string()),
    ];
    let mut drift = false;
    for (field, value) in expected {
        let actual = indexed_text(indexed, field);
        if actual != value {
            drift = true;
            if show_fields {
                println!("  drift: {field} db={value:?} ix={actual:?}");
            }
        }
    }
    drift |= (document.postdate_ts - indexed_number(indexed, "postdate_ts")).abs() > 2;
    drift |= (document.adddate_ts - indexed_number(indexed, "adddate_ts")).abs() > 2;
    if drift {
        return "STALE_INDEX";
    }
    if document.passwordstatus > i64::from(show_passwords) {
        return "STATUS_HIDDEN";
    }
    "OK"
}

#[cfg(test)]
mod tests {
    use serde_json::{Map, Value, json};

    use super::*;

    fn indexed(value: Value) -> Map<String, Value> {
        value.as_object().unwrap().clone()
    }

    #[test]
    fn classifies_like_php() {
        let document = ReleaseDocument {
            searchname: "a".to_owned(),
            name: "b".to_owned(),
            size: 5,
            postdate_ts: 100,
            adddate_ts: 200,
            passwordstatus: 1,
            ..ReleaseDocument::default()
        };
        let row = indexed(json!({"searchname": "a", "name": "b", "categories_id": 0,
            "passwordstatus": 1, "size": "5", "groups_id": 0, "nzbstatus": 0,
            "postdate_ts": 102, "adddate_ts": 198}));
        assert_eq!(classify(&document, None, false, false), "MISSING_IN_INDEX");
        assert_eq!(classify(&document, Some(&row), true, false), "OK");
        assert_eq!(
            classify(&document, Some(&row), false, false),
            "STATUS_HIDDEN"
        );
        let mut stale = row.clone();
        stale.insert("size".to_owned(), json!(6));
        assert_eq!(
            classify(&document, Some(&stale), true, false),
            "STALE_INDEX"
        );
        let mut late = row;
        late.insert("postdate_ts".to_owned(), json!(103));
        assert_eq!(classify(&document, Some(&late), true, false), "STALE_INDEX");
    }
}
