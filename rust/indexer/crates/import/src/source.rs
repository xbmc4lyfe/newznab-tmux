//! The read-only connection to MariaDB.

use std::path::{Path, PathBuf};

use anyhow::{Context, Result, bail};
use indexer_config::Secret;
use mysql_async::prelude::{FromRow, Queryable};
use mysql_async::{
    Opts, OptsBuilder, Params, Pool, PoolConstraints, PoolOpts, Row, SslOpts, Value,
};

/// A login, loaded from a `KEY=value` file so it stays out of arguments and logs.
#[derive(Clone, Debug)]
pub struct Credentials {
    pub user: String,
    pub password: Secret<String>,
}

impl Credentials {
    pub fn from_file(path: &Path) -> Result<Self> {
        let content = std::fs::read_to_string(path)
            .with_context(|| format!("reading credentials {}", path.display()))?;
        Self::parse(&content).with_context(|| format!("in {}", path.display()))
    }

    fn parse(content: &str) -> Result<Self> {
        let value = |key: &str| {
            content
                .lines()
                .find_map(|line| line.strip_prefix(key)?.strip_prefix('='))
                .map(str::to_owned)
                .with_context(|| format!("{key} is not set"))
        };
        Ok(Self {
            user: value("MARIADB_RO_USER")?,
            password: Secret::new(value("MARIADB_RO_PASSWORD")?),
        })
    }
}

#[derive(Clone, Debug)]
pub enum Tls {
    /// Check the server's certificate against this CA. The server's certificate
    /// is issued locally, so its name is not checked.
    VerifyCa(PathBuf),
    /// Loopback test servers only.
    Disabled,
}

#[derive(Clone, Debug)]
pub struct SourceConfig {
    pub host: String,
    pub port: u16,
    pub database: String,
    pub credentials: Credentials,
    pub tls: Tls,
    pub max_connections: usize,
}

/// Reports why a statement isn't one this importer may run.
pub fn assert_read_only(sql: &str) -> Result<()> {
    if !sql.starts_with("SELECT ") {
        bail!("refusing to run a statement that is not a SELECT: {sql}");
    }
    if sql.contains(';') {
        bail!("refusing to run a statement with a semicolon: {sql}");
    }
    let upper = sql.to_ascii_uppercase();
    if upper.contains(" INTO ") || upper.contains(" FOR UPDATE") || upper.contains(" LOCK IN ") {
        bail!("refusing to run a statement that writes or locks: {sql}");
    }
    Ok(())
}

/// MariaDB, reachable only through `SELECT`.
#[derive(Clone)]
pub struct Source {
    pool: Pool,
}

impl Source {
    pub fn connect(config: &SourceConfig) -> Result<Self> {
        let constraints = PoolConstraints::new(0, config.max_connections.max(1))
            .context("bad connection limit")?;
        let mut options = OptsBuilder::default()
            .ip_or_hostname(config.host.clone())
            .tcp_port(config.port)
            .db_name(Some(config.database.clone()))
            .user(Some(config.credentials.user.clone()))
            .pass(Some(config.credentials.password.expose().clone()))
            .prefer_socket(false)
            .pool_opts(PoolOpts::default().with_constraints(constraints))
            .setup(vec![
                "SET SESSION TRANSACTION READ ONLY",
                "SET SESSION time_zone = '+00:00'",
                "SET NAMES utf8mb4",
            ]);
        match &config.tls {
            Tls::VerifyCa(ca) => {
                options = options.ssl_opts(
                    SslOpts::default()
                        .with_root_certs(vec![ca.clone().into()])
                        .with_danger_skip_domain_validation(true),
                );
            }
            Tls::Disabled => {
                if !matches!(config.host.as_str(), "127.0.0.1" | "localhost" | "::1") {
                    bail!("TLS can only be disabled for a loopback server");
                }
            }
        }
        Ok(Self {
            pool: Pool::new(Opts::from(options)),
        })
    }

    /// Runs one statement with the guard rails on, and returns every row.
    pub async fn select<T>(&self, sql: &str, params: impl Into<Params> + Send) -> Result<Vec<T>>
    where
        T: FromRow + Send + 'static,
    {
        assert_read_only(sql)?;
        let mut conn = self
            .pool
            .get_conn()
            .await
            .context("connecting to MariaDB")?;
        let read_only: Option<i64> = conn
            .query_first("SELECT @@session.tx_read_only")
            .await
            .context("checking session")?;
        if read_only != Some(1) {
            bail!("refusing to read: this MariaDB session is not READ ONLY");
        }
        conn.exec(sql, params)
            .await
            .with_context(|| format!("running {sql}"))
    }

    /// Like [`Source::select`], but with raw column values.
    pub async fn select_values(
        &self,
        sql: &str,
        params: impl Into<Params> + Send,
    ) -> Result<Vec<Vec<Value>>> {
        let rows: Vec<Row> = self.select(sql, params).await?;
        Ok(rows.into_iter().map(Row::unwrap).collect())
    }

    pub async fn disconnect(self) -> Result<()> {
        self.pool
            .disconnect()
            .await
            .context("closing MariaDB connections")
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_plain_selects_pass() {
        assert!(assert_read_only("SELECT a FROM b WHERE c = ?").is_ok());
        for sql in [
            "DELETE FROM releases",
            "select 1",
            " SELECT 1",
            "SELECT 1; DROP TABLE releases",
            "SELECT a FROM b INTO OUTFILE '/tmp/x'",
            "SELECT a FROM b FOR UPDATE",
            "SELECT a FROM b LOCK IN SHARE MODE",
            "TRUNCATE releases",
        ] {
            assert!(assert_read_only(sql).is_err(), "{sql}");
        }
    }

    #[test]
    fn credentials_parse_and_never_print_the_password() {
        let credentials =
            Credentials::parse("# note\nMARIADB_RO_USER=ro\nMARIADB_RO_PASSWORD=sekret=1\n")
                .unwrap();
        assert_eq!(credentials.user, "ro");
        assert_eq!(credentials.password.expose(), "sekret=1");
        assert!(!format!("{credentials:?}").contains("sekret"));
        assert!(Credentials::parse("MARIADB_RO_USER=ro\n").is_err());
    }

    #[test]
    fn tls_stays_on_for_anything_but_loopback() {
        let config = SourceConfig {
            host: "192.168.1.110".into(),
            port: 3306,
            database: "nntmux".into(),
            credentials: Credentials {
                user: "ro".into(),
                password: Secret::new("p".into()),
            },
            tls: Tls::Disabled,
            max_connections: 1,
        };
        let error = Source::connect(&config).err().unwrap();
        assert!(error.to_string().contains("loopback"));
    }
}
