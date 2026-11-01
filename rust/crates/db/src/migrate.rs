//! Applies `rust/migrations` in order and records them in `rust_migrations`.
//!
//! sqlx's own migrator can't run the baseline, which uses `DELIMITER` for its triggers. A
//! database Laravel already built has the Laravel `migrations` table, so the two baseline
//! steps are recorded as applied there and never run.

use anyhow::{Context, Result};
use sqlx::{AssertSqlSafe, MySqlPool};
use tracing::info;

pub struct Migration {
    pub version: u32,
    pub name: &'static str,
    pub sql: &'static str,
}

const BASELINE_THROUGH: u32 = 2;

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
    sqlx::query(
        "CREATE TABLE IF NOT EXISTS rust_migrations (
            version INT UNSIGNED NOT NULL PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    )
    .execute(pool)
    .await
    .context("creating rust_migrations")?;

    let applied: Vec<u32> = sqlx::query_scalar("SELECT version FROM rust_migrations")
        .fetch_all(pool)
        .await?;
    let built_by_laravel = applied.is_empty() && laravel_schema_exists(pool).await?;

    let mut ran = Vec::new();
    for migration in MIGRATIONS {
        if applied.contains(&migration.version) {
            continue;
        }
        if built_by_laravel && migration.version <= BASELINE_THROUGH {
            info!(
                version = migration.version,
                "baseline already present, recording it"
            );
        } else {
            info!(
                version = migration.version,
                name = migration.name,
                "applying migration"
            );
            apply(pool, migration).await?;
            ran.push(migration.version);
        }
        sqlx::query("INSERT INTO rust_migrations (version, name) VALUES (?, ?)")
            .bind(migration.version)
            .bind(migration.name)
            .execute(pool)
            .await?;
    }
    Ok(ran)
}

async fn laravel_schema_exists(pool: &MySqlPool) -> Result<bool> {
    let count: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'migrations'",
    )
    .fetch_one(pool)
    .await?;
    Ok(count > 0)
}

async fn apply(pool: &MySqlPool, migration: &Migration) -> Result<()> {
    let mut connection = pool.acquire().await?;
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
