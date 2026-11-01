//! Layered configuration: built-in defaults, then a TOML file, then `INDEXER_` environment variables.
//!
//! Environment keys use `__` between levels, so `INDEXER_TELEMETRY__LOG_FILTER=debug` sets
//! `telemetry.log_filter`. Unknown keys are errors, so a typo can't silently keep a default.
//! Each stage adds its own section to [`Config`] when it needs one.

use std::fmt;
use std::net::SocketAddr;
use std::path::{Path, PathBuf};

use anyhow::{Context, Result, bail, ensure};
use figment::Figment;
use figment::providers::{Env, Format, Toml};
use serde::{Deserialize, Serialize, Serializer};

/// Names the config file; read by the CLI, so it is not a config key.
pub const CONFIG_PATH_ENV: &str = "INDEXER_CONFIG";

#[derive(Clone, Debug, Default, Deserialize, Serialize)]
#[serde(default, deny_unknown_fields)]
pub struct Config {
    pub storage: StorageConfig,
    pub telemetry: TelemetryConfig,
}

impl Config {
    /// Loads and validates the config. A path that was asked for has to exist.
    pub fn load(path: Option<&Path>) -> Result<Self> {
        let mut figment = Figment::new();
        if let Some(path) = path {
            if !path.is_file() {
                bail!("config file {} does not exist", path.display());
            }
            figment = figment.merge(Toml::file_exact(path));
        }
        let config: Config = figment
            .merge(Env::prefixed("INDEXER_").ignore(&["CONFIG"]).split("__"))
            .extract()
            .context("invalid configuration")?;
        config.validate()?;
        Ok(config)
    }

    fn validate(&self) -> Result<()> {
        let otlp = &self.telemetry.otlp;
        ensure!(
            (0.0..=1.0).contains(&otlp.sample_ratio),
            "telemetry.otlp.sample_ratio must be between 0 and 1, got {}",
            otlp.sample_ratio
        );
        if let Some(endpoint) = &otlp.endpoint {
            ensure!(
                endpoint.starts_with("http://"),
                "telemetry.otlp.endpoint must be an http:// URL, got {endpoint}"
            );
        }
        Ok(())
    }

    pub fn to_toml(&self) -> Result<String> {
        toml::to_string_pretty(self).context("serializing configuration")
    }
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(default, deny_unknown_fields)]
pub struct StorageConfig {
    /// Root for the catalog shards and stored NZBs.
    pub data_dir: PathBuf,
}

impl Default for StorageConfig {
    fn default() -> Self {
        Self {
            data_dir: PathBuf::from("data"),
        }
    }
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(default, deny_unknown_fields)]
pub struct TelemetryConfig {
    /// `tracing` filter directives. `RUST_LOG` wins when set.
    pub log_filter: String,
    pub log_format: LogFormat,
    pub metrics: MetricsConfig,
    pub otlp: OtlpConfig,
}

impl Default for TelemetryConfig {
    fn default() -> Self {
        Self {
            log_filter: "info".to_owned(),
            log_format: LogFormat::default(),
            metrics: MetricsConfig::default(),
            otlp: OtlpConfig::default(),
        }
    }
}

#[derive(Clone, Copy, Debug, Default, Deserialize, Serialize, PartialEq, Eq)]
#[serde(rename_all = "lowercase")]
pub enum LogFormat {
    #[default]
    Text,
    Json,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(default, deny_unknown_fields)]
pub struct MetricsConfig {
    /// Serves `/metrics` and `/healthz`, apart from the Newznab API so scrapes can't slow it.
    pub listen: SocketAddr,
}

impl Default for MetricsConfig {
    fn default() -> Self {
        Self {
            listen: SocketAddr::from(([127, 0, 0, 1], 9187)),
        }
    }
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(default, deny_unknown_fields)]
pub struct OtlpConfig {
    /// OTLP/HTTP traces endpoint, such as `http://127.0.0.1:4318`. Traces are off when unset.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub endpoint: Option<String>,
    /// Share of root spans to export, from 0 to 1.
    pub sample_ratio: f64,
}

impl Default for OtlpConfig {
    fn default() -> Self {
        Self {
            endpoint: None,
            sample_ratio: 1.0,
        }
    }
}

/// A value such as a DSN or API key, hidden from `Debug` output and printed config.
#[derive(Clone, Deserialize, PartialEq, Eq)]
#[serde(transparent)]
pub struct Secret<T>(T);

impl<T> Secret<T> {
    pub fn new(value: T) -> Self {
        Self(value)
    }

    pub fn expose(&self) -> &T {
        &self.0
    }
}

impl<T> fmt::Debug for Secret<T> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str("<redacted>")
    }
}

impl<T> Serialize for Secret<T> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        serializer.serialize_str("<redacted>")
    }
}

#[cfg(test)]
// figment's Jail closures return its own large error type.
#[allow(clippy::result_large_err)]
mod tests {
    use figment::Jail;

    use super::*;

    #[test]
    fn example_file_sets_only_known_keys() {
        // The jail also keeps other tests' environment variables out of this load.
        Jail::expect_with(|_| {
            let example = concat!(env!("CARGO_MANIFEST_DIR"), "/../../indexer.example.toml");
            let config = Config::load(Some(Path::new(example))).unwrap();
            assert_eq!(
                config.telemetry.metrics.listen,
                MetricsConfig::default().listen
            );
            Ok(())
        });
    }

    #[test]
    fn env_overrides_file_and_file_overrides_defaults() {
        Jail::expect_with(|jail| {
            jail.create_file(
                "c.toml",
                "[telemetry]\nlog_filter = \"debug\"\nlog_format = \"json\"\n\
                 [telemetry.otlp]\nsample_ratio = 0.25\n",
            )?;
            jail.set_env("INDEXER_TELEMETRY__LOG_FILTER", "warn,indexer=trace");
            jail.set_env("INDEXER_TELEMETRY__METRICS__LISTEN", "127.0.0.1:0");
            // The CLI's own variable must not read as an unknown key.
            jail.set_env(CONFIG_PATH_ENV, "c.toml");

            let config = Config::load(Some(Path::new("c.toml"))).unwrap();
            assert_eq!(config.telemetry.log_filter, "warn,indexer=trace");
            assert_eq!(config.telemetry.log_format, LogFormat::Json);
            assert_eq!(config.telemetry.otlp.sample_ratio, 0.25);
            assert_eq!(config.telemetry.metrics.listen.to_string(), "127.0.0.1:0");
            Ok(())
        });
    }

    #[test]
    fn rejects_bad_input() {
        Jail::expect_with(|jail| {
            let load = |text: &str| -> Result<Config> {
                std::fs::write("c.toml", text).unwrap();
                Config::load(Some(Path::new("c.toml")))
            };

            assert!(Config::load(Some(Path::new("missing.toml"))).is_err());
            assert!(load("[telemetry]\nlog_filtr = \"debug\"\n").is_err());
            assert!(load("[telemetry.otlp]\nsample_ratio = 1.5\n").is_err());
            assert!(load("[telemetry.otlp]\nendpoint = \"grpc://x:4317\"\n").is_err());

            jail.set_env("INDEXER_TELEMETRY__LOG_FILTR", "debug");
            assert!(Config::load(None).is_err());
            Ok(())
        });
    }

    #[test]
    fn secrets_stay_hidden() {
        let secret = Secret::new("mysql://root:hunter2@db/x".to_owned());
        assert_eq!(format!("{secret:?}"), "<redacted>");
        assert_eq!(
            toml::Value::try_from(&secret).unwrap().as_str(),
            Some("<redacted>")
        );
        assert_eq!(secret.expose(), "mysql://root:hunter2@db/x");
    }
}
