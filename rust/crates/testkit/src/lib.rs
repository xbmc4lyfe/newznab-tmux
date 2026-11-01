//! Test support. Each test gets its own database on the server named by `TEST_DATABASE_URL`
//! (`rust/compose.test.yml` starts one), so tests and parallel worktrees can't collide. When
//! the variable is unset, database tests skip themselves.

use anyhow::{Context, Result};
use rand::Rng;
use sqlx::{AssertSqlSafe, MySqlPool};

pub struct TestDb {
    pub pool: MySqlPool,
    pub name: String,
    server_url: String,
}

impl TestDb {
    /// A database with the full schema and seed data applied.
    pub async fn create() -> Result<Option<Self>> {
        let Some(db) = Self::create_empty().await? else {
            return Ok(None);
        };
        nntmux_db::migrate::run(&db.pool).await?;
        Ok(Some(db))
    }

    pub async fn create_empty() -> Result<Option<Self>> {
        let Ok(server_url) = std::env::var("TEST_DATABASE_URL") else {
            eprintln!("TEST_DATABASE_URL is unset, skipping");
            return Ok(None);
        };
        let suffix: u64 = rand::rng().random();
        let name = format!("t_{suffix:016x}");
        let admin = nntmux_db::connect_url(&server_url).await?;
        sqlx::query(AssertSqlSafe(format!(
            "CREATE DATABASE `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        )))
        .execute(&admin)
        .await
        .context("creating the test database")?;
        admin.close().await;
        let base = server_url.trim_end_matches('/');
        let pool = nntmux_db::connect_url(&format!("{base}/{name}")).await?;
        Ok(Some(Self {
            pool,
            name,
            server_url,
        }))
    }

    /// Call this at the end of a passing test. A failed test leaves its database to inspect.
    pub async fn drop_database(self) -> Result<()> {
        self.pool.close().await;
        let admin = nntmux_db::connect_url(&self.server_url).await?;
        sqlx::query(AssertSqlSafe(format!("DROP DATABASE `{}`", self.name)))
            .execute(&admin)
            .await?;
        admin.close().await;
        Ok(())
    }
}
