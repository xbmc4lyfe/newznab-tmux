//! Configuration every binary shares. Values come from the environment, which Compose fills
//! from `docker/config/app.env`, so the names and value keywords match what Laravel read.

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
                username: var_or("DB_USERNAME", "nntmux"),
                password: var_or("DB_PASSWORD", ""),
                ssl_ca: optional("MYSQL_ATTR_SSL_CA").map(PathBuf::from),
                max_connections: parsed_or("DB_MAX_CONNECTIONS", 16)?,
            },
            redis_url: redis_url(),
            manticore: ManticoreConfig {
                host: var_or("MANTICORESEARCH_HOST", "127.0.0.1"),
                sql_port: parsed_or("MANTICORESEARCH_SQL_PORT", 9306)?,
                // Laravel talked to Manticore over HTTP, so this key holds the HTTP port.
                http_port: parsed_or("MANTICORESEARCH_PORT", 9308)?,
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
    optional("REDIS_URL").unwrap_or_else(|| {
        build_redis_url(
            &var_or("REDIS_HOST", "127.0.0.1"),
            &var_or("REDIS_PORT", "6379"),
            optional("REDIS_USERNAME").as_deref(),
            optional("REDIS_PASSWORD").as_deref(),
            &var_or("REDIS_DB", "0"),
        )
    })
}

fn build_redis_url(
    host: &str,
    port: &str,
    username: Option<&str>,
    password: Option<&str>,
    database: &str,
) -> String {
    let auth = match password {
        Some(password) => format!(
            "{}:{}@",
            percent_encode(username.unwrap_or_default()),
            percent_encode(password)
        ),
        None => String::new(),
    };
    format!("redis://{auth}{host}:{port}/{database}")
}

fn percent_encode(value: &str) -> String {
    let mut encoded = String::with_capacity(value.len());
    for byte in value.bytes() {
        if byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_' | b'.' | b'~') {
            encoded.push(char::from(byte));
        } else {
            encoded.push_str(&format!("%{byte:02X}"));
        }
    }
    encoded
}

/// Reads a variable. Unset, empty, and Laravel's `null` and `empty` keywords all count as
/// absent, because app.env ships values like `REDIS_PASSWORD=null`.
pub fn optional(key: &str) -> Option<String> {
    env::var(key).ok().and_then(present)
}

fn present(value: String) -> Option<String> {
    let keyword = value.to_ascii_lowercase();
    let absent = matches!(
        keyword.as_str(),
        "" | "null" | "(null)" | "empty" | "(empty)"
    );
    (!absent).then_some(value)
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

#[cfg(test)]
mod tests {
    use super::{build_redis_url, present};

    #[test]
    fn laravel_keywords_count_as_absent() {
        for value in ["", "null", "NULL", "(null)", "empty", "(empty)"] {
            assert_eq!(present(value.to_owned()), None, "{value}");
        }
        assert_eq!(present("nullable".to_owned()).as_deref(), Some("nullable"));
    }

    #[test]
    fn redis_urls_encode_credentials() {
        assert_eq!(
            build_redis_url("redis", "6379", None, None, "0"),
            "redis://redis:6379/0"
        );
        assert_eq!(
            build_redis_url("redis", "6379", None, Some("a/b#c"), "1"),
            "redis://:a%2Fb%23c@redis:6379/1"
        );
    }
}
