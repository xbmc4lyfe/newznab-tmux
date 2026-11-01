//! Search settings. The environment names and defaults are the ones `config/search.php` read.

use anyhow::{Result, bail};
use nntmux_core::{ManticoreConfig, optional, parsed_or, var_or};

#[derive(Clone, Debug)]
pub struct SearchConfig {
    /// Base URLs, tried in turn when one can't be reached.
    pub endpoints: Vec<String>,
    pub auth: Auth,
    pub indexes: IndexNames,
    pub max_matches: u64,
    pub retry_attempts: u32,
    pub retry_delay_ms: u64,
    pub cache_minutes: u64,
    pub index_generation: String,
    pub autocomplete_enabled: bool,
    pub autocomplete_min_length: usize,
    pub autocomplete_max_results: u64,
    pub autocomplete_cache_minutes: u64,
    pub suggest_enabled: bool,
    pub fuzzy_enabled: bool,
    pub fuzzy_max_distance: u32,
}

#[derive(Clone, Debug, Default)]
pub enum Auth {
    #[default]
    None,
    Bearer(String),
    Basic(String, String),
}

/// The table behind each logical index.
#[derive(Clone, Debug)]
pub struct IndexNames(Vec<(&'static str, String)>);

pub const LOGICAL_INDEXES: [&str; 10] = [
    "releases", "predb", "movies", "tvshows", "music", "books", "games", "console", "steam",
    "anime",
];

impl IndexNames {
    fn with(table: impl Fn(&'static str) -> String) -> Self {
        Self(LOGICAL_INDEXES.iter().map(|&l| (l, table(l))).collect())
    }

    /// Names every table `<prefix><logical>_rt`, so tests can't collide.
    pub fn prefixed(prefix: &str) -> Self {
        Self::with(|logical| format!("{prefix}{logical}_rt"))
    }

    pub fn table(&self, logical: &str) -> &str {
        self.0
            .iter()
            .find(|(name, _)| *name == logical)
            .map_or("", |(_, table)| table.as_str())
    }

    /// The logical index configured to use this table, if any.
    pub fn configured(&self, table: &str) -> Option<&'static str> {
        self.0
            .iter()
            .find(|(_, configured)| configured == table)
            .map(|(logical, _)| *logical)
    }

    /// The configured name wins over the `<logical>_rt` default.
    pub fn logical(&self, table: &str) -> Option<&'static str> {
        self.0
            .iter()
            .find(|(_, configured)| configured == table)
            .map(|(logical, _)| *logical)
            .or_else(|| {
                LOGICAL_INDEXES
                    .iter()
                    .find(|logical| table.strip_suffix("_rt") == Some(logical))
                    .copied()
            })
    }
}

impl SearchConfig {
    /// Defaults for one server, the values `config/search.php` falls back to.
    pub fn new(endpoint: impl Into<String>) -> Self {
        Self {
            endpoints: vec![endpoint.into()],
            auth: Auth::None,
            indexes: IndexNames::prefixed(""),
            max_matches: 10_000,
            retry_attempts: 2,
            retry_delay_ms: 100,
            cache_minutes: 5,
            index_generation: "1".to_owned(),
            autocomplete_enabled: true,
            autocomplete_min_length: 2,
            autocomplete_max_results: 10,
            autocomplete_cache_minutes: 10,
            suggest_enabled: true,
            fuzzy_enabled: true,
            fuzzy_max_distance: 2,
        }
    }

    pub fn from_env(manticore: &ManticoreConfig) -> Result<Self> {
        let scheme = var_or("MANTICORESEARCH_SCHEME", "http");
        let mut endpoints = parse_hosts(
            &var_or("MANTICORESEARCH_HOSTS", ""),
            &scheme,
            manticore.http_port,
        );
        if endpoints.is_empty() {
            endpoints = parse_hosts(&manticore.host, &scheme, manticore.http_port);
        }
        if endpoints.is_empty() {
            bail!("no Manticore host is configured");
        }
        let auth = match (
            optional("MANTICORESEARCH_TOKEN"),
            optional("MANTICORESEARCH_USERNAME"),
            optional("MANTICORESEARCH_PASSWORD"),
        ) {
            (Some(token), _, _) => Auth::Bearer(token),
            (None, Some(user), Some(password)) => Auth::Basic(user, password),
            _ => Auth::None,
        };
        Ok(Self {
            endpoints,
            auth,
            indexes: IndexNames::with(|logical| {
                var_or(
                    &format!("MANTICORESEARCH_INDEX_{}", logical.to_ascii_uppercase()),
                    &format!("{logical}_rt"),
                )
            }),
            max_matches: parsed_or("MANTICORESEARCH_MAX_MATCHES", 10_000)?,
            retry_attempts: parsed_or("MANTICORESEARCH_RETRY_ATTEMPTS", 2)?,
            retry_delay_ms: parsed_or("MANTICORESEARCH_RETRY_DELAY_MS", 100)?,
            cache_minutes: parsed_or("MANTICORESEARCH_CACHE_MINUTES", 5)?,
            index_generation: var_or("SEARCH_INDEX_GENERATION", "1"),
            autocomplete_enabled: enabled("MANTICORESEARCH_AUTOCOMPLETE_ENABLED"),
            autocomplete_min_length: parsed_or("MANTICORESEARCH_AUTOCOMPLETE_MIN_LENGTH", 2)?,
            autocomplete_max_results: parsed_or("MANTICORESEARCH_AUTOCOMPLETE_MAX_RESULTS", 10)?,
            autocomplete_cache_minutes: parsed_or(
                "MANTICORESEARCH_AUTOCOMPLETE_CACHE_MINUTES",
                10,
            )?,
            suggest_enabled: enabled("MANTICORESEARCH_SUGGEST_ENABLED"),
            fuzzy_enabled: enabled("MANTICORESEARCH_FUZZY_ENABLED"),
            fuzzy_max_distance: parsed_or("MANTICORESEARCH_FUZZY_MAX_DISTANCE", 2)?,
        })
    }
}

/// PHP compared these with `=== true`, and Laravel only turns `true` into a boolean, so a value
/// such as `1` switched the feature off.
fn enabled(key: &str) -> bool {
    optional(key)
        .is_none_or(|value| matches!(value.to_ascii_lowercase().as_str(), "true" | "(true)"))
}

/// Each comma-separated entry is `host`, `host:port` or a full URL.
fn parse_hosts(hosts: &str, scheme: &str, default_port: u16) -> Vec<String> {
    hosts
        .split(',')
        .map(str::trim)
        .filter(|host| !host.is_empty())
        .map(|host| {
            let (scheme, rest) = host.split_once("://").unwrap_or((scheme, host));
            let (authority, path) = rest.split_once('/').unwrap_or((rest, ""));
            let port = if authority.contains(':') {
                String::new()
            } else {
                format!(":{default_port}")
            };
            let path = path.trim_end_matches('/');
            if path.is_empty() {
                format!("{scheme}://{authority}{port}")
            } else {
                format!("{scheme}://{authority}{port}/{path}")
            }
        })
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn hosts_take_the_default_scheme_and_port() {
        assert_eq!(
            parse_hosts(" a, b:1 ,https://c:2/x/,,d/y", "http", 9308),
            [
                "http://a:9308",
                "http://b:1",
                "https://c:2/x",
                "http://d:9308/y"
            ]
        );
    }

    #[test]
    fn logical_names_prefer_the_configured_table() {
        let names = IndexNames::prefixed("t_");
        assert_eq!(names.logical("t_music_rt"), Some("music"));
        assert_eq!(names.logical("music_rt"), Some("music"));
        assert_eq!(names.logical("nope"), None);
        assert_eq!(names.table("anime"), "t_anime_rt");
    }
}
