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
        .charset("utf8mb4");
    match &config.ssl_ca {
        Some(ca) => options.ssl_mode(MySqlSslMode::VerifyCa).ssl_ca(ca),
        None => options.ssl_mode(MySqlSslMode::Preferred),
    }
}

pub async fn connect(config: &DatabaseConfig) -> Result<MySqlPool> {
    MySqlPoolOptions::new()
        .max_connections(config.max_connections)
        .acquire_timeout(Duration::from_secs(30))
        .connect_with(connect_options(config))
        .await
        .with_context(|| format!("connecting to MariaDB at {}:{}", config.host, config.port))
}

/// Connects from a `mysql://` URL. Tests and one-off tools use this.
pub async fn connect_url(url: &str) -> Result<MySqlPool> {
    let options = MySqlConnectOptions::from_str(url).context("parsing the database URL")?;
    MySqlPoolOptions::new()
        .max_connections(8)
        .connect_with(options)
        .await
        .context("connecting to MariaDB")
}
