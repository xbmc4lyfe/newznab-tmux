//! The MariaDB pool and schema migrations. The schema is the one Laravel built; nothing here
//! changes its shape, and new changes arrive as numbered files in `rust/migrations`.

pub mod migrate;

use std::str::FromStr;
use std::time::Duration;

use anyhow::{Context, Result};
use nntmux_core::DatabaseConfig;
use sqlx::MySqlPool;
use sqlx::mysql::{MySqlConnectOptions, MySqlPoolOptions, MySqlSslMode};

pub fn connect_options(config: &DatabaseConfig) -> MySqlConnectOptions {
    let options = MySqlConnectOptions::new()
        .host(&config.host)
        .port(config.port)
        .username(&config.username)
        .password(&config.password)
        .database(&config.database)
        .charset("utf8mb4")
        .collation("utf8mb4_unicode_ci")
        .pipes_as_concat(false);
    match &config.ssl_ca {
        Some(ca) => options.ssl_mode(MySqlSslMode::VerifyCa).ssl_ca(ca),
        None => options.ssl_mode(MySqlSslMode::Preferred),
    }
}

/// Every connection uses the SQL mode Laravel set (`strict => false`). Real Usenet data
/// overflows columns such as `collections.fromname`, where PHP stored a truncated value and
/// the server's strict default would raise an error. They also use READ COMMITTED, as
/// production's MariaDB config sets, so tests and any other server behave the same.
fn pool_options() -> MySqlPoolOptions {
    MySqlPoolOptions::new().after_connect(|connection, _| {
        Box::pin(async move {
            sqlx::query("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'")
                .execute(&mut *connection)
                .await?;
            sqlx::query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED")
                .execute(&mut *connection)
                .await?;
            Ok(())
        })
    })
}

pub async fn connect(config: &DatabaseConfig) -> Result<MySqlPool> {
    pool_options()
        .max_connections(config.max_connections)
        .acquire_timeout(Duration::from_secs(30))
        .connect_with(connect_options(config))
        .await
        .with_context(|| format!("connecting to MariaDB at {}:{}", config.host, config.port))
}

/// Connects from a `mysql://` URL. Tests and one-off tools use this.
pub async fn connect_url(url: &str) -> Result<MySqlPool> {
    let options = MySqlConnectOptions::from_str(url)
        .context("parsing the database URL")?
        .charset("utf8mb4")
        .collation("utf8mb4_unicode_ci")
        .pipes_as_concat(false);
    pool_options()
        .max_connections(8)
        .connect_with(options)
        .await
        .context("connecting to MariaDB")
}
