//! Configuration every binary shares. Values come from the environment, which Compose fills
//! from `docker/config/app.env`, so the names match what the Laravel app read.

pub mod pcre;

use std::env;
use std::net::SocketAddr;
use std::path::PathBuf;

use anyhow::{Context, Result};

#[derive(Clone)]
pub struct Config {
    pub database: DatabaseConfig,
    pub redis_url: String,
    pub manticore: ManticoreConfig,
    pub health_addr: SocketAddr,
    pub log_filter: String,
    pub log_json: bool,
}

#[derive(Clone)]
pub struct DatabaseConfig {
    pub host: String,
    pub port: u16,
    pub database: String,
    pub username: String,
    pub password: String,
    /// CA for TLS to an external database. With none set, TLS is preferred but unverified.
    pub ssl_ca: Option<PathBuf>,
    pub max_connections: u32,
}

#[derive(Clone)]
pub struct ManticoreConfig {
    pub host: String,
    /// The MySQL protocol port.
    pub sql_port: u16,
    /// The HTTP JSON API port.
    pub http_port: u16,
}

impl Config {
    pub fn from_env() -> Result<Self> {
        Ok(Self {
            database: DatabaseConfig {
                host: var_or("DB_HOST", "127.0.0.1"),
                port: parsed_or("DB_PORT", 3306)?,
                database: var_or("DB_DATABASE", "nntmux"),
                username: var_or("DB_USERNAME", "root"),
                password: var_or("DB_PASSWORD", ""),
                ssl_ca: optional("MYSQL_ATTR_SSL_CA").map(PathBuf::from),
                max_connections: parsed_or("DB_MAX_CONNECTIONS", 16)?,
            },
            redis_url: redis_url(),
            manticore: ManticoreConfig {
                host: var_or("MANTICORE_HOST", "127.0.0.1"),
                sql_port: parsed_or("MANTICORE_PORT", 9306)?,
                http_port: parsed_or("MANTICORE_HTTP_PORT", 9308)?,
            },
            health_addr: var_or("NNTMUX_HEALTH_ADDR", "0.0.0.0:9187")
                .parse()
                .context("NNTMUX_HEALTH_ADDR")?,
            log_filter: var_or("RUST_LOG", "info"),
            log_json: var_or("NNTMUX_LOG_FORMAT", "json") == "json",
        })
    }
}

fn redis_url() -> String {
    if let Some(url) = optional("REDIS_URL") {
        return url;
    }
    let host = var_or("REDIS_HOST", "127.0.0.1");
    let port = var_or("REDIS_PORT", "6379");
    let auth = match (optional("REDIS_USERNAME"), optional("REDIS_PASSWORD")) {
        (user, Some(password)) => format!("{}:{password}@", user.unwrap_or_default()),
        _ => String::new(),
    };
    format!("redis://{auth}{host}:{port}/")
}

/// An unset or empty variable counts as absent, the way `env()` defaults worked in Laravel.
pub fn optional(key: &str) -> Option<String> {
    env::var(key).ok().filter(|value| !value.is_empty())
}

pub fn var_or(key: &str, default: &str) -> String {
    optional(key).unwrap_or_else(|| default.to_owned())
}

pub fn parsed_or<T>(key: &str, default: T) -> Result<T>
where
    T: std::str::FromStr,
    T::Err: std::error::Error + Send + Sync + 'static,
{
    match optional(key) {
        Some(value) => value.parse().with_context(|| format!("parsing {key}")),
        None => Ok(default),
    }
}
