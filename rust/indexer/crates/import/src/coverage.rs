//! What the importer deliberately leaves behind, and why.
//!
//! A row comparison can only see what was selected, and the source may be dropped
//! once an import verifies. So every source table and column must be either copied
//! or listed here, and verification fails on anything unlisted.

use std::collections::{BTreeMap, BTreeSet};

use crate::spec::{self, Table};

const APP_STATE: &str = "old web app, user and admin state";
const PIPELINE_STATE: &str = "old pipeline progress";
const OTHER_MEDIA: &str = "metadata for media this indexer doesn't keep";
const HEADERS: &str = "headers, which can be downloaded again";
const EMPTY: &str = "empty, which verification checks";
const SEEDED: &str = "reference data seeded from the repo";
const REBUILT: &str = "rebuilt from the news server's group list";
const PROVENANCE: &str = "which old regex named each release";
const REFETCHABLE: &str = "can be fetched again from TMDB and TVDB";
const PER_RELEASE_APP: &str = "per-release activity in the old web app";

/// Left out only because they hold nothing. These would hold PreDB records and other
/// evidence that can't be fetched again, so a row appearing here must stop verification.
static MUST_BE_EMPTY: &[&str] = &["poster_renames", "predb_crcs", "predb_imports"];

/// Tables that aren't copied at all, by reason.
static LEFT_TABLES: &[(&str, &[&str])] = &[
    (EMPTY, MUST_BE_EMPTY),
    (REBUILT, &["short_groups"]),
    (PROVENANCE, &["release_regexes"]),
    (
        APP_STATE,
        &[
            "api_token_ip_addresses",
            "cache",
            "cache_locks",
            "content",
            "countries",
            "download_stats",
            "failed_jobs",
            "firewall",
            "forum_categories",
            "forum_posts",
            "forum_threads",
            "forum_threads_read",
            "forumpost",
            "gdpr_audit_logs",
            "gdpr_consents",
            "gdpr_requests",
            "grab_stats",
            "ingestion_configurations",
            "invitations",
            "jobs",
            "log_index_files",
            "logging",
            "metadata_configurations",
            "migrations",
            "model_has_permissions",
            "model_has_roles",
            "passkeys",
            "password_reset_tokens",
            "password_securities",
            "payments",
            "paypal_payments",
            "permissions",
            "personal_access_tokens",
            "post_processing_configurations",
            "processing_runtime_states",
            "pulse_aggregates",
            "pulse_entries",
            "pulse_values",
            "registration_configurations",
            "registration_periods",
            "registration_status_history",
            "role_expiration_emails",
            "role_has_permissions",
            "role_promotion_stats",
            "role_promotions",
            "role_stats",
            "roles",
            "service_incident_service_status",
            "service_incidents",
            "service_statuses",
            "settings",
            "signup_stats",
            "site_configurations",
            "system_metrics",
            "telescope_entries",
            "telescope_entries_tags",
            "telescope_monitoring",
            "tmux_cleanup_rules",
            "tmux_color_exclusions",
            "tmux_configurations",
            "trusted_devices",
            "user_activities",
            "user_activity_stats",
            "user_activity_stats_hourly",
            "user_downloads",
            "user_excluded_categories",
            "user_invitations",
            "user_movies",
            "user_requests",
            "user_role_history",
            "user_series",
            "users",
            "users_releases",
        ],
    ),
    (
        PIPELINE_STATE,
        &[
            "cbp_optimization_checkpoints",
            "nfo_cleanup_commits",
            "nzb_backup_runs",
            "nzb_storage_imports",
            "par2_hash_retry",
            "par2_rename_candidate_progress",
            "par2_rename_progress",
            "predb_identity_keys",
            "predb_identity_revisions",
            "predb_identity_state",
            "release_fingerprint_connections",
            "release_fingerprint_jobs",
            "release_fingerprint_provider_state",
            "release_nzb_creation_failures",
            "release_stats",
            "release_verification_audit_retries",
            "release_verification_candidates",
            "release_verification_locks",
            "release_verification_manifests",
            "release_verification_oversized_collections",
            "release_verification_pauses",
            "release_verification_publication_stripes",
            "release_verification_scratch",
            "release_verification_scratch_budget",
            "release_verification_state",
            "search_index_failures",
        ],
    ),
    (
        PER_RELEASE_APP,
        &[
            "dnzb_failures",
            "release_comments",
            "release_informs",
            "release_reports",
        ],
    ),
    (
        OTHER_MEDIA,
        &[
            "anidb_episodes",
            "anidb_info",
            "anidb_titles",
            "bookinfo",
            "consoleinfo",
            "gamesinfo",
            "musicinfo",
            "steam_apps",
            "xxxinfo",
        ],
    ),
    (
        HEADERS,
        &[
            "binaries",
            "collection_groups",
            "collections",
            "missed_parts",
            "parts",
        ],
    ),
    (
        SEEDED,
        &[
            "binaryblacklist",
            "categories",
            "category_regexes",
            "collection_regexes",
            "genres",
            "release_naming_regexes",
            "root_categories",
        ],
    ),
    (REFETCHABLE, &["tv_info", "videos_aliases"]),
];

/// Columns of copied tables that aren't copied, by table.
static LEFT_COLUMNS: &[(&str, &[(&str, &str)])] = &[
    (
        "releases",
        &[
            ("leftguid", "the first letter of guid"),
            ("musicinfo_id", OTHER_MEDIA),
            ("consoleinfo_id", OTHER_MEDIA),
            ("gamesinfo_id", OTHER_MEDIA),
            ("bookinfo_id", OTHER_MEDIA),
            ("anidbid", OTHER_MEDIA),
            ("book_lookup_attempts", OTHER_MEDIA),
            ("book_lookup_retry_at", OTHER_MEDIA),
            ("book_name_normalized_at", OTHER_MEDIA),
            ("comments", PER_RELEASE_APP),
            ("haspreview", PIPELINE_STATE),
            ("nfostatus", PIPELINE_STATE),
            ("jpgstatus", PIPELINE_STATE),
            ("videostatus", PIPELINE_STATE),
            ("nzbstatus", PIPELINE_STATE),
            ("nzb_creation_claimed_at", PIPELINE_STATE),
            ("nzb_creation_claim_token", PIPELINE_STATE),
            ("iscategorized", PIPELINE_STATE),
            ("proc_pp", PIPELINE_STATE),
            ("proc_par2", PIPELINE_STATE),
            ("proc_nfo", PIPELINE_STATE),
            ("proc_files", PIPELINE_STATE),
            ("proc_uid", PIPELINE_STATE),
            ("proc_srr", PIPELINE_STATE),
            ("proc_hash16k", PIPELINE_STATE),
            ("proc_crc32", PIPELINE_STATE),
            ("pp_timeout_count", PIPELINE_STATE),
            ("additional_pp_claimed_at", PIPELINE_STATE),
            ("additional_pp_claim_token", PIPELINE_STATE),
            ("archive_retry_at", PIPELINE_STATE),
            ("tv_lookup_attempts", PIPELINE_STATE),
            ("tv_lookup_retry_at", PIPELINE_STATE),
            ("late_article_candidate_id", PIPELINE_STATE),
        ],
    ),
    ("predb", &[("searched", PIPELINE_STATE)]),
    (
        "release_files",
        &[
            ("created_at", "row bookkeeping"),
            ("updated_at", "row bookkeeping"),
        ],
    ),
    (
        "release_nzb_contents",
        &[
            ("generation", "row bookkeeping"),
            ("created_at", "row bookkeeping"),
            ("updated_at", "row bookkeeping"),
        ],
    ),
    (
        "release_fingerprint_metadata",
        &[
            ("created_at", "row bookkeeping"),
            ("updated_at", "row bookkeeping"),
            ("run_token", PIPELINE_STATE),
        ],
    ),
    (
        "media_infos",
        &[
            ("created_at", "row bookkeeping"),
            ("updated_at", "row bookkeeping"),
        ],
    ),
    (
        "par_file_hash_inventories",
        &[("created_by_inventory_upgrade", PIPELINE_STATE)],
    ),
    (
        "movieinfo",
        &[
            ("cover", "local image flag"),
            ("backdrop", "local image flag"),
            ("created_at", "row bookkeeping"),
            ("updated_at", "row bookkeeping"),
        ],
    ),
    ("tv_episodes", &[("summary", REFETCHABLE)]),
];

/// Some of what stays behind, for one reason.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct LeftBehind {
    pub reason: String,
    pub what: Vec<String>,
}

#[derive(Debug, Default)]
pub struct Coverage {
    /// `table` or `table.column`, in the source but neither copied nor listed.
    pub unlisted: Vec<String>,
    pub left_behind: Vec<LeftBehind>,
}

/// The source table behind a spec: the first word of its `FROM`.
fn source_table(table: &Table) -> &'static str {
    table.from.split_whitespace().next().unwrap_or(table.from)
}

/// Whether `expression` reads column `name` of the row alias `c`.
fn reads_column(expression: &str, name: &str) -> bool {
    let needle = format!("c.{name}");
    expression.match_indices(&needle).any(|(at, _)| {
        let before = expression[..at].chars().next_back();
        let after = expression[at + needle.len()..].chars().next();
        let part_of_name = |c: char| c.is_ascii_alphanumeric() || c == '_';
        !before.is_some_and(part_of_name) && !after.is_some_and(part_of_name)
    })
}

/// Sorts every source column into copied, left out on purpose, or unlisted.
pub fn check(source_columns: &[(String, String)]) -> Coverage {
    let copied: BTreeMap<&str, &Table> = spec::all_tables()
        .into_iter()
        .map(|table| (source_table(table), table))
        .collect();
    let left_tables: BTreeMap<&str, &str> = LEFT_TABLES
        .iter()
        .flat_map(|(reason, tables)| tables.iter().map(move |table| (*table, *reason)))
        .collect();

    let mut by_reason: BTreeMap<String, BTreeSet<String>> = BTreeMap::new();
    let mut unlisted = BTreeSet::new();
    for (table, column) in source_columns {
        if let Some(reason) = left_tables.get(table.as_str()) {
            by_reason
                .entry((*reason).to_owned())
                .or_default()
                .insert(table.clone());
            continue;
        }
        if table == "release_nzb_contents" && is_nzb_column(column) {
            continue;
        }
        let spec = copied.get(table.as_str());
        let read =
            spec.is_some_and(|spec| spec.columns.iter().any(|c| reads_column(c.source, column)));
        if read {
            continue;
        }
        let reason = LEFT_COLUMNS
            .iter()
            .find(|(name, _)| *name == table)
            .and_then(|(_, columns)| columns.iter().find(|(name, _)| *name == column))
            .map(|(_, reason)| *reason);
        match reason {
            Some(reason) => {
                by_reason
                    .entry(reason.to_owned())
                    .or_default()
                    .insert(format!("{table}.{column}"));
            }
            None if spec.is_none() && table != "release_nzb_contents" => {
                unlisted.insert(table.clone());
            }
            None => {
                unlisted.insert(format!("{table}.{column}"));
            }
        }
    }
    Coverage {
        unlisted: unlisted.into_iter().collect(),
        left_behind: by_reason
            .into_iter()
            .map(|(reason, what)| LeftBehind {
                reason,
                what: what.into_iter().collect(),
            })
            .collect(),
    }
}

/// Tables that have to be empty, of those the source has.
pub fn must_be_empty(source_columns: &[(String, String)]) -> Vec<&'static str> {
    MUST_BE_EMPTY
        .iter()
        .copied()
        .filter(|table| source_columns.iter().any(|(name, _)| name == table))
        .collect()
}

/// Copied tables the login can't see, which would mean the list is cut short.
pub fn missing_copied_tables(source_columns: &[(String, String)]) -> Vec<&'static str> {
    spec::all_tables()
        .into_iter()
        .map(source_table)
        .chain(["release_nzb_contents"])
        .filter(|table| !source_columns.iter().any(|(name, _)| name == table))
        .collect()
}

/// `release_nzb_contents` is copied by its own statements, not a table spec.
fn is_nzb_column(column: &str) -> bool {
    matches!(
        column,
        "guid" | "releases_id" | "body" | "source_digest" | "decoded_bytes"
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    fn columns(rows: &[(&str, &str)]) -> Vec<(String, String)> {
        rows.iter()
            .map(|(table, column)| ((*table).to_owned(), (*column).to_owned()))
            .collect()
    }

    #[test]
    fn an_unknown_table_or_column_is_reported() {
        let coverage = check(&columns(&[
            ("brand_new_table", "id"),
            ("releases", "brand_new_column"),
            ("releases", "id"),
            ("releases", "name"),
        ]));
        assert_eq!(
            coverage.unlisted,
            ["brand_new_table", "releases.brand_new_column"]
        );
    }

    #[test]
    fn left_out_items_are_listed_with_their_reason() {
        let coverage = check(&columns(&[
            ("users", "id"),
            ("users", "email"),
            ("releases", "nzbstatus"),
            ("release_nzb_contents", "body"),
            ("release_nzb_contents", "generation"),
        ]));
        assert!(coverage.unlisted.is_empty(), "{:?}", coverage.unlisted);
        let what: Vec<&str> = coverage
            .left_behind
            .iter()
            .flat_map(|l| l.what.iter().map(String::as_str))
            .collect();
        assert!(what.contains(&"users"));
        assert!(what.contains(&"releases.nzbstatus"));
        assert!(what.contains(&"release_nzb_contents.generation"));
        assert!(!what.contains(&"release_nzb_contents.body"));
    }

    #[test]
    fn an_empty_or_short_list_of_source_tables_is_noticed() {
        assert!(missing_copied_tables(&[]).contains(&"releases"));
        assert!(missing_copied_tables(&[]).contains(&"release_nzb_contents"));
        let all: Vec<(String, String)> = spec::all_tables()
            .into_iter()
            .map(source_table)
            .chain(["release_nzb_contents"])
            .map(|table| (table.to_owned(), "id".to_owned()))
            .collect();
        assert_eq!(missing_copied_tables(&all), Vec::<&str>::new());
        assert_eq!(must_be_empty(&all), Vec::<&str>::new());
        assert_eq!(
            must_be_empty(&columns(&[("predb_crcs", "id")])),
            ["predb_crcs"]
        );
    }

    #[test]
    fn a_column_counts_as_read_only_when_named_exactly() {
        assert!(reads_column("c.size", "size"));
        assert!(reads_column("NULLIF(c.videos_id, 0)", "videos_id"));
        assert!(reads_column(
            "COALESCE(NULLIF(c.imdbid, ''), m.imdbid)",
            "imdbid"
        ));
        assert!(!reads_column("c.size_bytes", "size"));
        assert!(!reads_column("abc.size", "size"));
        assert!(!reads_column("m.imdbid", "imdbid"));
    }

    #[test]
    fn nothing_is_both_copied_and_left_out() {
        let copied: BTreeSet<&str> = spec::all_tables().into_iter().map(source_table).collect();
        for (_, tables) in LEFT_TABLES {
            for table in *tables {
                assert!(!copied.contains(table), "{table}");
            }
        }
        for (table, columns) in LEFT_COLUMNS {
            for (column, _) in *columns {
                let spec = spec::all_tables()
                    .into_iter()
                    .find(|t| source_table(t) == *table);
                if let Some(spec) = spec {
                    assert!(
                        !spec.columns.iter().any(|c| reads_column(c.source, column)),
                        "{table}.{column}"
                    );
                }
            }
        }
    }
}
