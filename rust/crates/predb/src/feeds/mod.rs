//! Public PreDB feeds and catalogues, normalized to `predb` table semantics.

pub mod command;
pub mod http;
pub mod importer;
pub mod index;
pub mod sources;

use async_trait::async_trait;
use chrono::{DateTime, NaiveDateTime, Utc};

pub const PRE_NONUKE: i8 = 0;
pub const PRE_UNNUKED: i8 = 1;
pub const PRE_NUKED: i8 = 2;
pub const PRE_MODNUKE: i8 = 3;
pub const PRE_RENUKED: i8 = 4;
pub const PRE_OLDNUKE: i8 = 5;

/// Release-name metadata from a remote PRE feed or catalogue.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Entry {
    pub title: String,
    pub source: String,
    pub category: Option<String>,
    pub size: Option<String>,
    pub files: Option<String>,
    pub predate: Option<NaiveDateTime>,
    pub nuked: i8,
    pub nuke_reason: Option<String>,
    /// When the feed listed the entry, if that isn't the PRE time, such as srrDB's upload time.
    /// Only pages history imports; it's never stored as a predate.
    pub listed_at: Option<NaiveDateTime>,
    /// Details only: it may fill in an existing row but never creates one.
    pub enrich_only: bool,
}

impl Entry {
    pub fn new(title: &str, source: &str) -> Self {
        Self {
            title: title.trim().to_owned(),
            source: source.to_owned(),
            ..Self::default()
        }
    }

    /// How far back a history import has paged.
    pub fn paging_time(&self) -> Option<NaiveDateTime> {
        self.predate.or(self.listed_at)
    }
}

/// A size given in megabytes, the way IRC pre bots announce it (KB below 1 MB).
pub fn size_from_megabytes(megabytes: Option<f64>) -> Option<String> {
    let megabytes = megabytes.filter(|mb| *mb > 0.0)?;
    let format = |value: f64| {
        let text = format!("{value:.2}");
        text.trim_end_matches('0').trim_end_matches('.').to_owned()
    };
    Some(if megabytes < 1.0 {
        format!("{}KB", format(megabytes * 1024.0))
    } else {
        format!("{}MB", format(megabytes))
    })
}

/// A file count, with zero meaning unknown.
pub fn files_from_count(count: Option<i64>) -> Option<String> {
    count
        .filter(|count| *count > 0)
        .map(|count| count.to_string())
}

pub fn from_unix(seconds: i64) -> Option<NaiveDateTime> {
    DateTime::<Utc>::from_timestamp(seconds, 0).map(|moment| moment.naive_utc())
}

/// Why a page couldn't be read.
#[derive(Debug)]
pub enum FeedError {
    /// Throttled, by HTTP 429 or a rate-limit envelope. The fields are the response's
    /// `Retry-After` and `X-RateLimit-Reset`.
    RateLimited {
        message: String,
        retry_after: Option<String>,
        reset_at: Option<String>,
    },
    Failed(anyhow::Error),
}

impl FeedError {
    pub fn rate_limited(message: impl Into<String>) -> Self {
        Self::RateLimited {
            message: message.into(),
            retry_after: None,
            reset_at: None,
        }
    }
}

impl std::fmt::Display for FeedError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::RateLimited { message, .. } => write!(f, "{message}"),
            Self::Failed(error) => write!(f, "{error:#}"),
        }
    }
}

impl<E: Into<anyhow::Error>> From<E> for FeedError {
    fn from(error: E) -> Self {
        Self::Failed(error.into())
    }
}

pub type FeedResult<T> = std::result::Result<T, FeedError>;

/// A source of one page at a time, newest first. Pages start at 1.
#[async_trait]
pub trait FeedSource: Send {
    /// Stable key used in `PREDB_FEED_SOURCES`.
    fn key(&self) -> &str;

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>>;

    /// For complete catalogues: eligible entries in the whole catalogue, before local paging.
    fn accepted_count(&self) -> Option<usize> {
        None
    }
}

/// `PREDB_FEED_*` settings, with the defaults `config/predb_feeds.php` had.
#[derive(Clone, Debug)]
pub struct FeedConfig {
    pub enabled: bool,
    pub sources: Vec<String>,
    pub page_size: usize,
    pub max_body_bytes: usize,
    pub timeout_seconds: u64,
    pub user_agent: String,
    pub rss_url: String,
    pub scene_archive_sql_file: String,
    pub predatabase_api_key: String,
    pub srrdb_timezone: String,
    pub request_delay_ms: u64,
    pub rate_limit_wait_seconds: u64,
    pub rate_limit_retries: u32,
    pub rate_limit_max_wait_seconds: u64,
    pub max_pages: u32,
    /// Overrides every endpoint, for tests.
    pub endpoint_override: Option<String>,
}

fn env_or(name: &str, default: &str) -> String {
    std::env::var(name).unwrap_or_else(|_| default.to_owned())
}

fn parsed_or<T: std::str::FromStr>(name: &str, default: T) -> T {
    std::env::var(name)
        .ok()
        .and_then(|v| v.parse().ok())
        .unwrap_or(default)
}

pub(crate) fn env_truthy(name: &str) -> bool {
    matches!(
        std::env::var(name)
            .unwrap_or_default()
            .to_ascii_lowercase()
            .as_str(),
        "1" | "true" | "on" | "yes"
    )
}

impl FeedConfig {
    pub fn from_env() -> Self {
        Self {
            enabled: env_truthy("PREDB_FEEDS_ENABLED"),
            sources: env_or(
                "PREDB_FEED_SOURCES",
                "predb_club,predb_net,predb_me,predatabase,xrel,xrel_p2p",
            )
            .split(',')
            .map(|key| key.trim().to_owned())
            .filter(|key| !key.is_empty())
            .collect(),
            page_size: parsed_or("PREDB_FEED_PAGE_SIZE", 100).max(1),
            max_body_bytes: parsed_or("PREDB_FEED_MAX_BODY_BYTES", 1_048_576).max(1),
            timeout_seconds: parsed_or("PREDB_FEED_TIMEOUT", 15).max(1),
            user_agent: env_or("PREDB_FEED_USER_AGENT", "NNTmux-PreDB-Importer/1.0"),
            rss_url: env_or("PREDB_FEED_RSS_URL", "https://predb.me/?rss=1"),
            scene_archive_sql_file: env_or("PREDB_FEED_SCENE_ARCHIVE_SQL_FILE", ""),
            predatabase_api_key: env_or("PREDB_FEED_PREDATABASE_API_KEY", ""),
            srrdb_timezone: env_or("PREDB_FEED_SRRDB_TIMEZONE", "Europe/Brussels"),
            request_delay_ms: parsed_or("PREDB_FEED_REQUEST_DELAY_MS", 1000),
            rate_limit_wait_seconds: parsed_or("PREDB_FEED_RATE_LIMIT_WAIT", 60),
            rate_limit_retries: parsed_or("PREDB_FEED_RATE_LIMIT_RETRIES", 5),
            rate_limit_max_wait_seconds: parsed_or("PREDB_FEED_RATE_LIMIT_MAX_WAIT", 900),
            max_pages: parsed_or("PREDB_FEED_MAX_PAGES", 2000).max(1),
            endpoint_override: None,
        }
    }
}

/// Never paged beyond the latest listing. srrDB's terms say "Use but don't scrape", and the
/// rest expose only their latest listing.
pub const SINGLE_PAGE_SOURCES: [&str; 18] = [
    "srrdb",
    "nfodb",
    "one_techno",
    "one_gabba",
    "ddlvalley",
    "hdencode",
    "hdencode_tv",
    "mp3scene",
    "nima4k",
    "gload",
    "hdworld",
    "g4u",
    "gabber",
    "movieblog",
    "scnlog",
    "knaben",
    "crowdnfo",
    "seriousaboutsound",
];

/// Complete exports need explicit selection; automatic polling skips them even if configured.
pub const MANUAL_ONLY_SOURCES: [&str; 5] = [
    "nswdb",
    "3dsdb",
    "nzedb_archive",
    "dats_pciso_1998",
    "scene_archive_sql",
];
