use std::path::PathBuf;
use std::time::Duration;

#[derive(Clone, Debug, Default)]
pub struct Provider {
    pub host: String,
    pub port: u16,
    pub tls: bool,
    pub username: String,
    pub password: String,
    pub socket_timeout: Duration,
}

#[derive(Clone, Debug)]
pub struct TlsOptions {
    pub verify_peer: bool,
    pub verify_host: bool,
    pub allow_self_signed: bool,
    pub ca_file: Option<PathBuf>,
    pub ca_path: Option<PathBuf>,
}

impl Default for TlsOptions {
    fn default() -> Self {
        Self {
            verify_peer: true,
            verify_host: true,
            allow_self_signed: false,
            ca_file: None,
            ca_path: None,
        }
    }
}

#[derive(Clone, Debug)]
pub struct NntpConfig {
    pub primary: Provider,
    pub alternate: Option<Provider>,
    pub tls: TlsOptions,
    pub primary_connections: usize,
    pub alternate_connections: usize,
    pub compressed_headers: bool,
    pub use_alternate: bool,
    /// Whether article reads may fall back to the alternate provider.
    pub alternate_article_fallback: bool,
    pub fingerprint_breaker_seconds: u64,
    /// Connect and login attempts. The `nntpretries` setting supplies this; at least one is made.
    pub retries: u32,
}

const DEFAULT_SOCKET_TIMEOUT: u64 = 120;

impl NntpConfig {
    /// Reads the same `NNTP_*` and `SSL_*` variables the PHP config files did.
    pub fn from_env() -> Self {
        Self::from_lookup(|key| std::env::var(key).ok())
    }

    pub(crate) fn from_lookup(get: impl Fn(&str) -> Option<String>) -> Self {
        let text = |key: &str| get(key).unwrap_or_default();
        let number = |key: &str, default: u64| {
            get(key)
                .and_then(|value| value.trim().parse::<u64>().ok())
                .unwrap_or(default)
        };
        let flag = |key: &str, default: bool| get(key).map_or(default, |value| env_flag(&value));
        let timeout = |key: &str| match number(key, DEFAULT_SOCKET_TIMEOUT) {
            0 => Duration::from_secs(DEFAULT_SOCKET_TIMEOUT),
            seconds => Duration::from_secs(seconds),
        };
        let alternate_host = text("NNTP_SERVER_A");
        let use_alternate = flag("USE_ALTERNATE_NNTP_SERVER", false);
        let fallback = get("NNTP_ALTERNATE_FALLBACK")
            .filter(|value| !value.trim().is_empty())
            .map_or(use_alternate, |value| env_flag(&value));
        Self {
            primary: Provider {
                host: text("NNTP_SERVER"),
                port: u16::try_from(number("NNTP_PORT", 0)).unwrap_or(0),
                tls: flag("NNTP_SSLENABLED", false),
                username: text("NNTP_USERNAME"),
                password: text("NNTP_PASSWORD"),
                socket_timeout: timeout("NNTP_SOCKET_TIMEOUT"),
            },
            alternate: (!alternate_host.is_empty()).then(|| Provider {
                host: alternate_host,
                port: u16::try_from(number("NNTP_PORT_A", 0)).unwrap_or(0),
                tls: flag("NNTP_SSLENABLED_A", false),
                username: text("NNTP_USERNAME_A"),
                password: text("NNTP_PASSWORD_A"),
                socket_timeout: timeout("NNTP_SOCKET_TIMEOUT_A"),
            }),
            tls: TlsOptions {
                verify_peer: flag("SSL_VERIFY_PEER", true),
                verify_host: flag("SSL_VERIFY_HOST", true),
                allow_self_signed: flag("SSL_ALLOW_SELF_SIGNED", false),
                ca_file: get("SSL_CAFILE")
                    .filter(|v| !v.is_empty())
                    .map(PathBuf::from),
                ca_path: get("SSL_CAPATH")
                    .filter(|v| !v.is_empty())
                    .map(PathBuf::from),
            },
            primary_connections: usize::try_from(number("NNTP_CONNECTIONS", 1)).unwrap_or(1),
            alternate_connections: usize::try_from(number("NNTP_CONNECTIONS_A", 1)).unwrap_or(1),
            compressed_headers: flag("NNTP_COMPRESSED_HEADERS", false),
            use_alternate,
            alternate_article_fallback: fallback,
            fingerprint_breaker_seconds: number("NNTP_FINGERPRINT_BREAKER_SECONDS", 900),
            retries: 1,
        }
    }
}

/// Laravel's `env()` booleans, then PHP's `(bool)` for anything else.
fn env_flag(value: &str) -> bool {
    match value.to_ascii_lowercase().as_str() {
        "true" | "(true)" => true,
        "false" | "(false)" | "null" | "(null)" | "empty" | "(empty)" | "" | "0" => false,
        _ => true,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::HashMap;

    fn config(pairs: &[(&str, &str)]) -> NntpConfig {
        let map: HashMap<String, String> = pairs
            .iter()
            .map(|(key, value)| ((*key).to_string(), (*value).to_string()))
            .collect();
        NntpConfig::from_lookup(|key| map.get(key).cloned())
    }

    #[test]
    fn reads_the_php_environment_names() {
        let config = config(&[
            ("NNTP_SERVER", "news.example"),
            ("NNTP_PORT", "563"),
            ("NNTP_SSLENABLED", "true"),
            ("NNTP_USERNAME", "user"),
            ("NNTP_PASSWORD", "secret"),
            ("NNTP_SOCKET_TIMEOUT", "30"),
            ("NNTP_CONNECTIONS", "8"),
            ("NNTP_COMPRESSED_HEADERS", "(true)"),
            ("SSL_VERIFY_PEER", "false"),
        ]);
        assert_eq!(config.primary.host, "news.example");
        assert_eq!(config.primary.port, 563);
        assert!(config.primary.tls);
        assert_eq!(config.primary.socket_timeout, Duration::from_secs(30));
        assert_eq!(config.primary_connections, 8);
        assert!(config.compressed_headers);
        assert!(!config.tls.verify_peer);
        assert!(config.alternate.is_none());
    }

    #[test]
    fn alternate_fallback_follows_use_alternate_unless_it_is_set() {
        assert!(!config(&[]).alternate_article_fallback);
        assert!(config(&[("USE_ALTERNATE_NNTP_SERVER", "true")]).alternate_article_fallback);
        assert!(
            !config(&[
                ("USE_ALTERNATE_NNTP_SERVER", "true"),
                ("NNTP_ALTERNATE_FALLBACK", "false"),
            ])
            .alternate_article_fallback
        );
        assert!(
            config(&[
                ("USE_ALTERNATE_NNTP_SERVER", "true"),
                ("NNTP_ALTERNATE_FALLBACK", "  "),
            ])
            .alternate_article_fallback
        );
    }

    #[test]
    fn a_zero_socket_timeout_means_the_default() {
        assert_eq!(
            config(&[("NNTP_SOCKET_TIMEOUT", "0")])
                .primary
                .socket_timeout,
            Duration::from_secs(120)
        );
    }
}
