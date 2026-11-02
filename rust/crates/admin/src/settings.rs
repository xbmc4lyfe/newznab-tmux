//! The admin settings pages (`AdminSettingsController`, `SettingsPageCatalog`,
//! `DomainConfigurationRepository`): one row (`id = 1`) per configuration domain table,
//! plus the tmux cleanup rules and color exclusions.
//!
//! Every value is checked the way `UpdateDomainConfigurationRequest` checked it, against the
//! row as it will be after the change, and each change is one transaction holding the row
//! lock. The site logo was an image upload on the web page, so it's shown but not set here.

use std::fmt;

use anyhow::{Context, Result, bail, ensure};
use chrono::{NaiveDate, NaiveDateTime};
use nntmux_core::pcre::Pcre;
use sqlx::{MySql, MySqlPool, Row, Transaction};

use crate::groups::{format_size, parse_size};

/// `ConfigurationDomain`.
#[derive(Clone, Copy, Debug, PartialEq, Eq, clap::ValueEnum)]
pub enum Domain {
    Site,
    Registration,
    Ingestion,
    PostProcessing,
    Metadata,
    Tmux,
}

impl Domain {
    pub const ALL: [Self; 6] = [
        Self::Site,
        Self::Registration,
        Self::Ingestion,
        Self::PostProcessing,
        Self::Metadata,
        Self::Tmux,
    ];

    pub fn table(self) -> &'static str {
        match self {
            Self::Site => "site_configurations",
            Self::Registration => "registration_configurations",
            Self::Ingestion => "ingestion_configurations",
            Self::PostProcessing => "post_processing_configurations",
            Self::Metadata => "metadata_configurations",
            Self::Tmux => "tmux_configurations",
        }
    }

    /// When a change takes effect, as the page said.
    pub fn guidance(self) -> &'static str {
        match self {
            Self::Site | Self::Registration | Self::Metadata => "Takes effect immediately.",
            Self::Tmux => {
                "The monitor reloads this value on its next cycle; layout changes require a restart."
            }
            Self::Ingestion | Self::PostProcessing => {
                "Restart active workers after changing this value."
            }
        }
    }

    fn columns(self) -> &'static [&'static str] {
        match self {
            Self::Site => &[
                "title",
                "home_link",
                "site_logo",
                "strapline",
                "meta_title",
                "meta_description",
                "meta_keywords",
                "footer",
                "dereferrer_link",
                "terms",
                "trailers_display",
                "trailers_size_x",
                "trailers_size_y",
            ],
            Self::Registration => &["status"],
            Self::Ingestion => &[
                "binary_threads",
                "backfill_threads",
                "release_threads",
                "collection_delay_hours",
                "collection_timeout_hours",
                "cross_post_hours",
                "completion_percent",
                "grab_status",
                "max_headers_per_iteration",
                "max_messages",
                "max_releases_created",
                "nntp_retries",
                "nzb_split_level",
                "part_retention_hours",
                "release_retention_days",
                "misc_other_retention_hours",
                "misc_hashed_retention_hours",
                "min_files_to_form_release",
                "min_size_to_form_release",
                "max_size_to_form_release",
                "new_group_scan_method",
                "new_group_days_to_scan",
                "new_group_messages_to_scan",
                "safe_backfill_date",
                "disable_backfill_group",
                "part_repair",
                "safe_part_repair",
                "max_part_repair",
                "part_repair_max_tries",
                "categorize_foreign",
                "categorize_web_dl",
                "show_passworded_releases",
                "delete_passworded_releases",
                "backfill_days_mode",
                "backfill_order",
                "backfill_quantity",
            ],
            Self::PostProcessing => &[
                "post_threads",
                "post_threads_amazon",
                "post_threads_non",
                "nfo_threads",
                "fix_name_threads",
                "timeout_seconds",
                "release_timeout_seconds",
                "max_timeout_count",
                "max_additional_processed",
                "max_parts_processed",
                "password_check_attempts",
                "fix_names_per_run",
                "max_nested_levels",
                "extract_using_rar_info",
                "segments_to_download",
                "ffmpeg_duration",
                "inner_file_blacklist",
                "process_jpg",
                "process_thumbnails",
                "process_videos",
                "save_audio_preview",
                "min_size_to_post_process",
                "max_size_to_post_process",
                "min_size_to_process_nfo",
                "max_size_to_process_nfo",
                "max_nfo_processed",
                "max_nfo_retries",
                "lookup_nfo",
                "lookup_par2",
            ],
            Self::Metadata => &[
                "anime_lookup",
                "book_lookup",
                "game_lookup",
                "movie_lookup",
                "music_lookup",
                "tv_lookup",
                "movie_language",
                "imdb_alternate_url",
                "max_anime_processed",
                "max_books_processed",
                "max_games_processed",
                "max_movies_processed",
                "max_music_processed",
                "max_tv_processed",
                "amazon_sleep_milliseconds",
            ],
            Self::Tmux => &[
                "session_name",
                "monitor_delay",
                "niceness",
                "sequential_mode",
                "sequential_timer",
                "binaries_enabled",
                "binaries_timer",
                "binaries_kill_timer",
                "backfill_mode",
                "backfill_groups",
                "backfill_timer",
                "progressive_backfill",
                "releases_enabled",
                "release_timer",
                "post_mode",
                "post_timer",
                "post_kill_timer",
                "post_amazon_mode",
                "post_amazon_timer",
                "post_non_mode",
                "post_non_timer",
                "fix_names_enabled",
                "fix_timer",
                "cleanup_mode",
                "cleanup_timer",
                "run_irc_scraper",
                "console_enabled",
                "htop_enabled",
                "mytop_enabled",
                "nmon_enabled",
                "vnstat_enabled",
                "vnstat_args",
                "tcp_track_enabled",
                "tcp_track_args",
                "bwmng_enabled",
                "redis_enabled",
                "redis_args",
                "write_logs",
                "collections_kill_threshold",
                "post_process_kill_threshold",
                "colors_start",
                "colors_end",
            ],
        }
    }

    /// The domain's fields, in page order.
    pub fn fields(self) -> Vec<Field> {
        self.columns().iter().map(|column| field(column)).collect()
    }

    pub fn field(self, column: &str) -> Result<Field> {
        self.columns()
            .iter()
            .find(|candidate| **candidate == column)
            .map(|column| field(column))
            .with_context(|| {
                format!(
                    "{column} isn't a {} setting; the settings are: {}",
                    self,
                    self.columns().join(", ")
                )
            })
    }
}

impl fmt::Display for Domain {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter.write_str(match self {
            Self::Site => "site",
            Self::Registration => "registration",
            Self::Ingestion => "ingestion",
            Self::PostProcessing => "post-processing",
            Self::Metadata => "metadata",
            Self::Tmux => "tmux",
        })
    }
}

/// How a field's value is read and checked (`ConfigurationField::$control` and `$rules`).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Kind {
    /// 1 or 0.
    Boolean,
    /// A byte count, entered as `500MB` or `2GB`.
    Bytes,
    /// One of the listed values.
    Choice(&'static [(i64, &'static str)]),
    /// A `Y-m-d` date.
    Date,
    /// A delimited PCRE pattern, at most 2000 characters.
    Regex,
    /// An integer in `min..=max`.
    Integer { min: i64, max: i64 },
    /// Text of at most `max` characters. A nullable field stores NULL for empty text.
    Text {
        max: usize,
        required: bool,
        nullable: bool,
    },
    /// An upload on the web page, so read only here.
    Upload,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Field {
    pub column: &'static str,
    pub kind: Kind,
}

const BOOLEANS: &[&str] = &[
    "trailers_display",
    "grab_status",
    "disable_backfill_group",
    "part_repair",
    "safe_part_repair",
    "categorize_foreign",
    "categorize_web_dl",
    "show_passworded_releases",
    "delete_passworded_releases",
    "extract_using_rar_info",
    "process_jpg",
    "process_thumbnails",
    "process_videos",
    "save_audio_preview",
    "lookup_nfo",
    "lookup_par2",
    "imdb_alternate_url",
    "binaries_enabled",
    "progressive_backfill",
    "releases_enabled",
    "fix_names_enabled",
    "run_irc_scraper",
    "console_enabled",
    "htop_enabled",
    "mytop_enabled",
    "nmon_enabled",
    "vnstat_enabled",
    "tcp_track_enabled",
    "bwmng_enabled",
    "redis_enabled",
    "write_logs",
];

const BYTES: &[&str] = &[
    "min_size_to_form_release",
    "max_size_to_form_release",
    "min_size_to_post_process",
    "max_size_to_post_process",
    "min_size_to_process_nfo",
    "max_size_to_process_nfo",
];

/// Size pairs whose maximum must be zero (unlimited) or at least the minimum.
const SIZE_PAIRS: &[(&str, &str)] = &[
    ("min_size_to_form_release", "max_size_to_form_release"),
    ("min_size_to_post_process", "max_size_to_post_process"),
    ("min_size_to_process_nfo", "max_size_to_process_nfo"),
];

const POSITIVE_INTEGERS: &[&str] = &[
    "trailers_size_x",
    "trailers_size_y",
    "binary_threads",
    "backfill_threads",
    "release_threads",
    "collection_timeout_hours",
    "max_headers_per_iteration",
    "max_messages",
    "max_releases_created",
    "backfill_quantity",
    "post_threads",
    "post_threads_amazon",
    "post_threads_non",
    "nfo_threads",
    "fix_name_threads",
    "release_timeout_seconds",
    "max_timeout_count",
    "max_additional_processed",
    "max_parts_processed",
    "password_check_attempts",
    "fix_names_per_run",
    "max_nested_levels",
    "segments_to_download",
    "ffmpeg_duration",
    "max_nfo_processed",
    "max_anime_processed",
    "max_books_processed",
    "max_games_processed",
    "max_movies_processed",
    "max_music_processed",
    "max_tv_processed",
    "monitor_delay",
    "sequential_timer",
    "binaries_timer",
    "backfill_groups",
    "backfill_timer",
    "release_timer",
    "post_timer",
    "post_amazon_timer",
    "post_non_timer",
    "fix_timer",
    "cleanup_timer",
];

/// `smallint unsigned` columns. The other unsigned integers are `int` or `bigint`.
const SMALLINTS: &[&str] = &[
    "trailers_size_x",
    "trailers_size_y",
    "binary_threads",
    "backfill_threads",
    "release_threads",
    "post_threads",
    "post_threads_amazon",
    "post_threads_non",
    "nfo_threads",
    "fix_name_threads",
    "max_timeout_count",
    "max_parts_processed",
    "password_check_attempts",
    "max_nested_levels",
    "segments_to_download",
];

/// `bigint unsigned` columns.
const BIGINTS: &[&str] = &[
    "max_headers_per_iteration",
    "max_messages",
    "max_releases_created",
    "new_group_messages_to_scan",
    "max_part_repair",
    "backfill_quantity",
];

const LOOKUP_MODES: &[(i64, &str)] = &[
    (0, "Disabled"),
    (1, "All releases"),
    (2, "Renamed releases only"),
];
const ON_OFF: &[(i64, &str)] = &[(0, "Disabled"), (1, "Enabled")];

fn choices(column: &str) -> Option<&'static [(i64, &'static str)]> {
    if column.ends_with("_lookup") {
        return Some(LOOKUP_MODES);
    }
    Some(match column {
        "status" => &[(0, "Open"), (1, "Invite"), (2, "Closed")],
        "new_group_scan_method" => &[(0, "Posts"), (1, "Days")],
        "backfill_days_mode" => &[(1, "Days per group"), (2, "Safe backfill date")],
        "backfill_order" => &[
            (1, "Newest"),
            (2, "Oldest"),
            (3, "Alphabetical"),
            (4, "Alphabetical reverse"),
            (5, "Most posts"),
            (6, "Fewest posts"),
        ],
        "sequential_mode" => &[(0, "Full"), (1, "Basic"), (2, "Stripped")],
        "backfill_mode" => &[(0, "Disabled"), (1, "All"), (4, "Safe")],
        "post_non_mode" | "post_amazon_mode" => ON_OFF,
        "post_mode" => &[(0, "Disabled"), (1, "Additional"), (2, "NFO"), (3, "All")],
        _ => return None,
    })
}

/// `SettingsPageCatalog::field`, with each column's storage limit added.
fn field(column: &'static str) -> Field {
    let kind = if column == "site_logo" {
        Kind::Upload
    } else if let Some(options) = choices(column) {
        Kind::Choice(options)
    } else if BOOLEANS.contains(&column) {
        Kind::Boolean
    } else if BYTES.contains(&column) {
        Kind::Bytes
    } else if column == "safe_backfill_date" {
        Kind::Date
    } else if column == "inner_file_blacklist" {
        Kind::Regex
    } else if let Some(text) = text(column) {
        text
    } else {
        let min = if column == "niceness" {
            -20
        } else {
            i64::from(POSITIVE_INTEGERS.contains(&column))
        };
        let max = match column {
            "completion_percent" => 100,
            "niceness" => 19,
            "colors_start" | "colors_end" => 255,
            _ if SMALLINTS.contains(&column) => i64::from(u16::MAX),
            _ if BIGINTS.contains(&column) => i64::MAX,
            _ => i64::from(u32::MAX),
        };
        Kind::Integer { min, max }
    };
    Field { column, kind }
}

fn text(column: &str) -> Option<Kind> {
    let (max, required, nullable) = match column {
        "meta_description" | "meta_keywords" | "footer" => (65_000, true, false),
        // Empty terms and an empty dereferrer link were stored as '', not NULL.
        "terms" => (65_000, false, false),
        "dereferrer_link" => (1000, false, false),
        "vnstat_args" | "tcp_track_args" | "redis_args" => (1000, false, true),
        "movie_language" => (8, true, false),
        "title" | "home_link" | "strapline" | "meta_title" | "session_name" | "cleanup_mode" => {
            (255, true, false)
        }
        _ => return None,
    };
    Some(Kind::Text {
        max,
        required,
        nullable,
    })
}

/// A checked value, ready to bind.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Value {
    Null,
    Integer(i64),
    Text(String),
}

impl Field {
    /// Reads `text` as this field's value, or says why it isn't one.
    pub fn parse(&self, text: &str) -> Result<Value> {
        let column = self.column;
        let trimmed = text.trim();
        Ok(match self.kind {
            Kind::Upload => {
                bail!("{column} was an image upload on the web page and isn't set here")
            }
            Kind::Boolean => Value::Integer(match trimmed.to_ascii_lowercase().as_str() {
                "1" | "true" | "yes" | "on" | "enabled" => 1,
                "0" | "false" | "no" | "off" | "disabled" => 0,
                _ => bail!("{column} is a yes/no setting: use 1 or 0"),
            }),
            Kind::Bytes => {
                ensure!(
                    !trimmed.starts_with('-'),
                    "{column} can't be negative; use 0 for no limit"
                );
                let bytes = parse_size(trimmed)?;
                ensure!(
                    bytes > 0
                        || trimmed
                            .trim_end_matches(|c: char| c.is_ascii_alphabetic())
                            .trim()
                            .parse::<f64>()
                            .is_ok_and(|n| n == 0.0),
                    "{column} is less than a byte; use 0 for no limit"
                );
                Value::Integer(bytes)
            }
            Kind::Choice(options) => {
                let value = trimmed.parse::<i64>().ok().or_else(|| {
                    options
                        .iter()
                        .find(|(_, label)| label.eq_ignore_ascii_case(trimmed))
                        .map(|(value, _)| *value)
                });
                match value {
                    Some(value) if options.iter().any(|(option, _)| *option == value) => {
                        Value::Integer(value)
                    }
                    _ => bail!("{column} must be one of: {}", describe_choices(options)),
                }
            }
            Kind::Date => {
                let date = NaiveDate::parse_from_str(trimmed, "%Y-%m-%d")
                    .with_context(|| format!("{column} must be a date such as 2012-06-24"))?;
                Value::Text(date.format("%Y-%m-%d").to_string())
            }
            Kind::Regex => {
                ensure!(!trimmed.is_empty(), "{column} can't be empty");
                ensure!(
                    trimmed.chars().count() <= 2000,
                    "{column} can be at most 2000 characters"
                );
                Pcre::new(trimmed).with_context(|| {
                    format!("{column} must be a valid delimited regular expression such as /setup\\.exe/i")
                })?;
                Value::Text(trimmed.to_owned())
            }
            Kind::Integer { min, max } => {
                let value: i64 = trimmed
                    .parse()
                    .ok()
                    .with_context(|| format!("{column} must be a whole number"))?;
                ensure!(
                    (min..=max).contains(&value),
                    "{column} must be from {min} through {max}"
                );
                Value::Integer(value)
            }
            Kind::Text {
                max,
                required,
                nullable,
            } => {
                ensure!(!(required && trimmed.is_empty()), "{column} can't be empty");
                ensure!(
                    text.chars().count() <= max,
                    "{column} can be at most {max} characters"
                );
                if trimmed.is_empty() && nullable {
                    Value::Null
                } else if trimmed.is_empty() {
                    Value::Text(String::new())
                } else {
                    Value::Text(text.to_owned())
                }
            }
        })
    }

    /// The stored value as the page showed it.
    pub fn display(&self, stored: Option<&str>) -> String {
        let Some(stored) = stored else {
            return "(none)".to_owned();
        };
        match self.kind {
            Kind::Boolean => if stored == "0" {
                "0 (disabled)"
            } else {
                "1 (enabled)"
            }
            .to_owned(),
            Kind::Bytes => format!("{stored} ({})", format_size(stored.parse().ok())),
            Kind::Choice(options) => {
                let mut value: i64 = stored.parse().unwrap_or_default();
                // The page showed any positive pane mode as enabled.
                if options == ON_OFF && value > 0 {
                    value = 1;
                }
                match options.iter().find(|(option, _)| *option == value) {
                    Some((value, label)) => format!("{value} ({label})"),
                    None => stored.to_owned(),
                }
            }
            _ => stored.to_owned(),
        }
    }
}

fn describe_choices(options: &[(i64, &str)]) -> String {
    options
        .iter()
        .map(|(value, label)| format!("{value} ({label})"))
        .collect::<Vec<_>>()
        .join(", ")
}

/// `SettingsPageCatalog::cleanupRules`.
pub const CLEANUP_RULES: &[&str] = &[
    "blacklist",
    "blfiles",
    "executable",
    "gibberish",
    "hashed",
    "installbin",
    "passworded",
    "passwordurl",
    "sample",
    "scr",
    "short",
    "size",
    "nzb",
    "codec",
];

/// A domain's row as text, in page order. `None` is a NULL.
pub async fn show(db: &MySqlPool, domain: Domain) -> Result<Vec<(Field, Option<String>)>> {
    let columns = domain.columns();
    let row = sqlx::query(sqlx::AssertSqlSafe(select(domain, "")))
        .fetch_optional(db)
        .await?
        .with_context(|| format!("{} has no configuration row", domain.table()))?;
    columns
        .iter()
        .map(|column| Ok((field(column), row.try_get::<Option<String>, _>(*column)?)))
        .collect()
}

pub async fn cleanup_rules(db: &MySqlPool) -> Result<Vec<String>> {
    Ok(sqlx::query_scalar(
        "SELECT `rule` FROM tmux_cleanup_rules WHERE tmux_configuration_id = 1 ORDER BY `rule`",
    )
    .fetch_all(db)
    .await?)
}

pub async fn color_exclusions(db: &MySqlPool) -> Result<Vec<u8>> {
    Ok(sqlx::query_scalar(
        "SELECT color FROM tmux_color_exclusions WHERE tmux_configuration_id = 1 ORDER BY color",
    )
    .fetch_all(db)
    .await?)
}

/// Parses `column=value` assignments for `domain`.
pub fn assignments(domain: Domain, pairs: &[String]) -> Result<Vec<(Field, Value)>> {
    ensure!(
        !pairs.is_empty(),
        "name at least one setting as column=value"
    );
    let mut parsed: Vec<(Field, Value)> = Vec::with_capacity(pairs.len());
    for pair in pairs {
        let (column, text) = pair
            .split_once('=')
            .with_context(|| format!("{pair} isn't column=value"))?;
        let field = domain.field(column.trim())?;
        ensure!(
            parsed.iter().all(|(seen, _)| seen.column != field.column),
            "{} is set twice",
            field.column
        );
        let value = field.parse(text)?;
        parsed.push((field, value));
    }
    Ok(parsed)
}

/// Writes checked values in one transaction, after checking the row they leave behind:
/// size maximums stay zero or at least the minimum, and the tmux color range stays ordered
/// with every excluded color inside it.
pub async fn update(
    db: &MySqlPool,
    domain: Domain,
    values: &[(Field, Value)],
    now: NaiveDateTime,
) -> Result<()> {
    ensure!(!values.is_empty(), "nothing to change");
    let table = domain.table();
    let mut tx = db.begin().await?;
    let current = locked_row(&mut tx, domain).await?;
    let after = |column: &str| -> Option<i64> {
        values
            .iter()
            .find(|(field, _)| field.column == column)
            .map(|(_, value)| match value {
                Value::Integer(value) => Some(*value),
                _ => None,
            })
            .unwrap_or_else(|| {
                current
                    .iter()
                    .find(|(name, _)| name == column)
                    .and_then(|(_, value)| value.as_deref()?.parse().ok())
            })
    };
    for (minimum, maximum) in SIZE_PAIRS {
        if let (Some(minimum_bytes), Some(maximum_bytes)) = (after(minimum), after(maximum)) {
            ensure!(
                maximum_bytes == 0 || minimum_bytes <= maximum_bytes,
                "{maximum} must be zero (unlimited) or at least {minimum} ({} < {})",
                format_size(Some(maximum_bytes)),
                format_size(Some(minimum_bytes))
            );
        }
    }
    if domain == Domain::Tmux {
        let (start, end) = (
            after("colors_start").unwrap_or_default(),
            after("colors_end").unwrap_or_default(),
        );
        let exclusions: Vec<u8> = sqlx::query_scalar(
            "SELECT color FROM tmux_color_exclusions WHERE tmux_configuration_id = 1",
        )
        .fetch_all(&mut *tx)
        .await?;
        check_colors(start, end, &exclusions)?;
    }

    let assignments = values
        .iter()
        .map(|(field, _)| format!("`{}` = ?", field.column))
        .collect::<Vec<_>>()
        .join(", ");
    // Strict mode, so a value the column can't hold fails instead of being clipped.
    let sql = format!(
        "SET STATEMENT sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,\
         ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' FOR \
         UPDATE `{table}` SET {assignments}, updated_at = ? WHERE id = 1"
    );
    // Columns come from the fixed catalog, values are bound.
    let mut query = sqlx::query(sqlx::AssertSqlSafe(sql));
    for (_, value) in values {
        query = match value {
            Value::Null => query.bind(None::<String>),
            Value::Integer(value) => query.bind(*value),
            Value::Text(value) => query.bind(value),
        };
    }
    query.bind(now).execute(&mut *tx).await?;
    tx.commit().await?;
    Ok(())
}

/// Replaces the tmux cleanup rules.
pub async fn set_cleanup_rules(db: &MySqlPool, rules: &[String], now: NaiveDateTime) -> Result<()> {
    let mut unique: Vec<&str> = Vec::new();
    for rule in rules {
        let rule = rule.trim();
        ensure!(
            CLEANUP_RULES.contains(&rule),
            "{rule} isn't a cleanup rule; the rules are: {}",
            CLEANUP_RULES.join(", ")
        );
        if !unique.contains(&rule) {
            unique.push(rule);
        }
    }
    let mut tx = db.begin().await?;
    locked_row(&mut tx, Domain::Tmux).await?;
    sqlx::query("DELETE FROM tmux_cleanup_rules WHERE tmux_configuration_id = 1")
        .execute(&mut *tx)
        .await?;
    for rule in unique {
        sqlx::query("INSERT INTO tmux_cleanup_rules (tmux_configuration_id, `rule`) VALUES (1, ?)")
            .bind(rule)
            .execute(&mut *tx)
            .await?;
    }
    touch(&mut tx, now).await?;
    tx.commit().await?;
    Ok(())
}

/// Replaces the tmux color exclusions. Each must sit inside the configured color range.
pub async fn set_color_exclusions(db: &MySqlPool, colors: &[u8], now: NaiveDateTime) -> Result<()> {
    let mut unique: Vec<u8> = Vec::new();
    for color in colors {
        if !unique.contains(color) {
            unique.push(*color);
        }
    }
    let mut tx = db.begin().await?;
    let current = locked_row(&mut tx, Domain::Tmux).await?;
    let read = |column: &str| -> i64 {
        current
            .iter()
            .find(|(name, _)| name == column)
            .and_then(|(_, value)| value.as_deref()?.parse().ok())
            .unwrap_or_default()
    };
    check_colors(read("colors_start"), read("colors_end"), &unique)?;
    sqlx::query("DELETE FROM tmux_color_exclusions WHERE tmux_configuration_id = 1")
        .execute(&mut *tx)
        .await?;
    for color in unique {
        sqlx::query(
            "INSERT INTO tmux_color_exclusions (tmux_configuration_id, color) VALUES (1, ?)",
        )
        .bind(color)
        .execute(&mut *tx)
        .await?;
    }
    touch(&mut tx, now).await?;
    tx.commit().await?;
    Ok(())
}

fn check_colors(start: i64, end: i64, exclusions: &[u8]) -> Result<()> {
    ensure!(
        start < end,
        "colors_end ({end}) must be greater than colors_start ({start})"
    );
    for color in exclusions {
        ensure!(
            (start..=end).contains(&i64::from(*color)),
            "excluded color {color} must be inside the color range {start}..={end}"
        );
    }
    Ok(())
}

/// Row 1 of the domain's table, every column as text.
fn select(domain: Domain, suffix: &str) -> String {
    let columns = domain
        .columns()
        .iter()
        .map(|column| format!("CAST(`{column}` AS CHAR) AS `{column}`"))
        .collect::<Vec<_>>()
        .join(", ");
    format!(
        "SELECT {columns} FROM `{}` WHERE id = 1{suffix}",
        domain.table()
    )
}

/// Locks row 1 of the domain's table and returns its columns as text.
async fn locked_row(
    tx: &mut Transaction<'_, MySql>,
    domain: Domain,
) -> Result<Vec<(String, Option<String>)>> {
    let columns = domain.columns();
    let row = sqlx::query(sqlx::AssertSqlSafe(select(domain, " FOR UPDATE")))
        .fetch_optional(&mut **tx)
        .await?
        .with_context(|| format!("{} has no configuration row", domain.table()))?;
    columns
        .iter()
        .map(|column| Ok(((*column).to_owned(), row.try_get(*column)?)))
        .collect()
}

async fn touch(tx: &mut Transaction<'_, MySql>, now: NaiveDateTime) -> Result<()> {
    sqlx::query("UPDATE tmux_configurations SET updated_at = ? WHERE id = 1")
        .bind(now)
        .execute(&mut **tx)
        .await?;
    Ok(())
}
