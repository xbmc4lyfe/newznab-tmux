//! Applies `rust/migrations` in order and records them in `rust_migrations`.
//!
//! sqlx's own migrator can't run the baseline, which uses `DELIMITER` for its triggers. A
//! database Laravel built at the baseline's level gets the two baseline steps recorded as
//! applied, in one transaction, and never runs them. Any other database that already has
//! tables and no record is refused, because neither running nor skipping the baseline is safe.

use anyhow::{Context, Result, bail};
use sqlx::{AssertSqlSafe, Connection, MySqlConnection, MySqlPool};
use tracing::info;

pub struct Migration {
    pub version: u32,
    pub name: &'static str,
    pub sql: &'static str,
}

const BASELINE_THROUGH: u32 = 2;

/// The newest Laravel migration in the baseline, the last one in `php-final`.
const LARAVEL_BASELINE: &str = "2026_10_20_200000_create_database_fingerprint_storage";

const LOCK_NAME: &str = "nntmux_rust_migrate";
const LOCK_TIMEOUT_SECONDS: u32 = 600;

/// Append new migrations here, with the next free number. Never edit an applied one.
pub const MIGRATIONS: &[Migration] = &[
    Migration {
        version: 1,
        name: "schema_baseline",
        sql: include_str!("../../../migrations/0001_schema_baseline.sql"),
    },
    Migration {
        version: 2,
        name: "seed_baseline",
        sql: include_str!("../../../migrations/0002_seed_baseline.sql"),
    },
];

/// Returns the versions that ran.
pub async fn run(pool: &MySqlPool) -> Result<Vec<u32>> {
    // Detached, so the session lock and session settings die with this connection whatever
    // happens, rather than going back to the pool.
    let mut connection = pool.acquire().await?.detach();
    let locked: Option<i64> = sqlx::query_scalar("SELECT GET_LOCK(?, ?)")
        .bind(LOCK_NAME)
        .bind(LOCK_TIMEOUT_SECONDS)
        .fetch_one(&mut connection)
        .await?;
    if locked != Some(1) {
        bail!("another migration has held the lock for {LOCK_TIMEOUT_SECONDS} seconds");
    }
    let ran = run_locked(&mut connection).await;
    connection.close().await?;
    ran
}

async fn run_locked(connection: &mut MySqlConnection) -> Result<Vec<u32>> {
    sqlx::query(
        "CREATE TABLE IF NOT EXISTS rust_migrations (
            version INT UNSIGNED NOT NULL PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    )
    .execute(&mut *connection)
    .await
    .context("creating rust_migrations")?;

    let mut applied: Vec<u32> = sqlx::query_scalar("SELECT version FROM rust_migrations")
        .fetch_all(&mut *connection)
        .await?;
    if applied.is_empty() {
        match existing_schema(connection).await? {
            ExistingSchema::None => {}
            ExistingSchema::LaravelBaseline => {
                info!("Laravel baseline already present, recording it");
                applied = record_baseline(connection).await?;
            }
            ExistingSchema::Unknown(reason) => bail!(
                "refusing to migrate a database that has tables but no rust_migrations record: {reason}"
            ),
        }
    }

    let mut ran = Vec::new();
    for migration in MIGRATIONS {
        if applied.contains(&migration.version) {
            continue;
        }
        info!(
            version = migration.version,
            name = migration.name,
            "applying migration"
        );
        apply(connection, migration).await?;
        sqlx::query("INSERT INTO rust_migrations (version, name) VALUES (?, ?)")
            .bind(migration.version)
            .bind(migration.name)
            .execute(&mut *connection)
            .await?;
        ran.push(migration.version);
    }
    Ok(ran)
}

enum ExistingSchema {
    None,
    LaravelBaseline,
    Unknown(String),
}

async fn existing_schema(connection: &mut MySqlConnection) -> Result<ExistingSchema> {
    let tables: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name <> 'rust_migrations'",
    )
    .fetch_one(&mut *connection)
    .await?;
    if tables == 0 {
        return Ok(ExistingSchema::None);
    }
    let has_migrations: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'migrations'",
    )
    .fetch_one(&mut *connection)
    .await?;
    if has_migrations == 0 {
        return Ok(ExistingSchema::Unknown(
            "it has no Laravel migrations table".into(),
        ));
    }
    // Laravel's names sort by date. The baseline's own `migrations` table is empty, so a
    // partly applied Rust install lands here too.
    let newest: Option<String> = sqlx::query_scalar("SELECT MAX(migration) FROM migrations")
        .fetch_one(&mut *connection)
        .await?;
    Ok(match newest.as_deref() {
        Some(LARAVEL_BASELINE) => ExistingSchema::LaravelBaseline,
        Some(other) => ExistingSchema::Unknown(format!(
            "its newest Laravel migration is {other}, and the baseline is {LARAVEL_BASELINE}"
        )),
        None => ExistingSchema::Unknown(
            "its Laravel migrations table is empty, so this may be an interrupted install".into(),
        ),
    })
}

async fn record_baseline(connection: &mut MySqlConnection) -> Result<Vec<u32>> {
    let mut transaction = connection.begin().await?;
    let mut recorded = Vec::new();
    for migration in MIGRATIONS.iter().filter(|m| m.version <= BASELINE_THROUGH) {
        sqlx::query("INSERT INTO rust_migrations (version, name) VALUES (?, ?)")
            .bind(migration.version)
            .bind(migration.name)
            .execute(&mut *transaction)
            .await?;
        recorded.push(migration.version);
    }
    transaction.commit().await?;
    Ok(recorded)
}

async fn apply(connection: &mut MySqlConnection, migration: &Migration) -> Result<()> {
    for statement in split_statements(migration.sql) {
        sqlx::raw_sql(AssertSqlSafe(statement.to_owned()))
            .execute(&mut *connection)
            .await
            .with_context(|| {
                let head: String = statement.chars().take(120).collect();
                format!("migration {} failed at: {head}", migration.version)
            })?;
    }
    Ok(())
}

/// Splits on the `DELIMITER` client directive, which the server doesn't understand. Each
/// returned chunk can hold many statements, because `raw_sql` sends them as one batch.
fn split_statements(sql: &str) -> Vec<&str> {
    let mut chunks = Vec::new();
    let mut rest = sql;
    while let Some(start) = find_directive(rest, "DELIMITER ;;") {
        push_chunk(&mut chunks, &rest[..start]);
        let body = &rest[start + "DELIMITER ;;".len()..];
        let end = find_directive(body, "DELIMITER ;").unwrap_or(body.len());
        let trigger = body[..end].trim();
        push_chunk(&mut chunks, trigger.strip_suffix(";;").unwrap_or(trigger));
        rest = body.get(end + "DELIMITER ;".len()..).unwrap_or("");
    }
    push_chunk(&mut chunks, rest);
    chunks
}

fn find_directive(sql: &str, directive: &str) -> Option<usize> {
    sql.match_indices(directive)
        .map(|(index, _)| index)
        .find(|&index| index == 0 || sql.as_bytes()[index - 1] == b'\n')
}

fn push_chunk<'a>(chunks: &mut Vec<&'a str>, chunk: &'a str) {
    let chunk = chunk.trim();
    if !chunk.is_empty() {
        chunks.push(chunk);
    }
}

#[cfg(test)]
mod tests {
    use super::split_statements;

    #[test]
    fn delimiter_blocks_become_their_own_chunks() {
        let sql = "CREATE TABLE a (id INT);\nDELIMITER ;;\nCREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN SET @x = 1; END ;;\nDELIMITER ;\nCREATE TABLE b (id INT);\n";
        assert_eq!(
            split_statements(sql),
            [
                "CREATE TABLE a (id INT);",
                "CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN SET @x = 1; END",
                "CREATE TABLE b (id INT);",
            ]
        );
    }

    #[test]
    fn the_baseline_has_three_trigger_chunks_around_its_tables() {
        let chunks = split_statements(super::MIGRATIONS[0].sql);
        let triggers = chunks
            .iter()
            .filter(|c| c.contains("TRIGGER predb_identity"))
            .count();
        assert_eq!(triggers, 3);
    }
}
