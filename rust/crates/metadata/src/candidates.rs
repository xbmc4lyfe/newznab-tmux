//! SQL fragments over `releases` that pick the work. The bucket dispatcher, the workers and the
//! ready-work counts all use these, so they can't drift apart.

use crate::settings::LookupMode;

// `App\Models\Category`.
const TV_ROOT: i64 = 5000;
const TV_OTHER: i64 = 5999;
const TV_ANIME: i64 = 5070;
const BOOKS_ROOT: i64 = 7000;
const BOOKS_UNKNOWN: i64 = 7999;
const MUSIC_AUDIOBOOK: i64 = 3030;

/// `releases.tv_episodes_id` while no episode is linked. Positive values are `tv_episodes` ids.
pub mod tv_status {
    /// Waiting for the bucket workers. Transient failures also wait here until the retry time.
    pub const PENDING: i64 = 0;
    /// Every provider confirmed it has no matching show.
    pub const SHOW_NOT_FOUND: i64 = -10;
    /// The show is linked but the episode isn't available yet. Retried on a schedule.
    pub const EPISODE_MISSING: i64 = -11;
    /// A full-season release, linked to its show by design.
    pub const SEASON_PACK: i64 = -12;
    /// The name couldn't be parsed into show and episode information.
    pub const PARSE_FAILED: i64 = -100;
    /// Legacy statuses that meant "waiting for the next provider".
    pub const LEGACY_PENDING: [i64; 3] = [-1, -2, -3];
    /// Legacy statuses that meant "every provider gave up".
    pub const LEGACY_EXHAUSTED: [i64; 2] = [-4, -6];
}

/// Transient failures before a release with no show is given up as show-not-found.
pub const TV_MAX_TRANSIENT_ATTEMPTS: i64 = 8;
/// Episode lookups for a known show before the release stops being retried.
pub const TV_MAX_EPISODE_ATTEMPTS: i64 = 6;
/// Delay after a transient failure on a release whose show is already known.
pub const TV_KNOWN_SHOW_TRANSIENT_DELAY: i64 = 900;
/// Book lookup failures before the release is marked exhausted.
pub const BOOK_MAX_FAILURES: i64 = 5;

/// A partial awaiting a late-article upgrade may be renamed and reset, so a lookup on its title
/// would go stale.
pub const SETTLED: &str = "late_article_candidate_id IS NULL";

pub fn tv_transient_delay(attempts: i64, provider_retry_after: i64) -> i64 {
    provider_retry_after.max(match attempts {
        1 => 300,
        2 => 900,
        3 => 3600,
        _ => 21600,
    })
}

pub fn tv_episode_delay(attempts: i64) -> i64 {
    match attempts {
        1 => 3600,
        2 => 21600,
        3 => 86400,
        4 => 259200,
        _ => 604800,
    }
}

const TV_DUE: &str = "(tv_lookup_retry_at IS NULL OR tv_lookup_retry_at <= CURRENT_TIMESTAMP)";

pub fn tv_category_condition() -> String {
    format!(
        "(categories_id BETWEEN {TV_ROOT} AND {TV_OTHER} AND categories_id <> {TV_ANIME} \
         AND size > 1048576)"
    )
}

/// Releases with no show yet that are due for the bucket workers.
pub fn tv_work_condition(lookup_mode: LookupMode) -> String {
    if !lookup_mode.enabled() {
        return "0 = 1".to_owned();
    }
    format!(
        "{} AND videos_id = 0 AND tv_episodes_id = {} AND {SETTLED} AND {TV_DUE}{}",
        tv_category_condition(),
        tv_status::PENDING,
        lookup_mode.renamed_filter(),
    )
}

/// Releases linked to a show whose episode is due for another lookup.
pub fn tv_episode_retry_condition(ignore_schedule: bool) -> String {
    let condition = format!(
        "{} AND videos_id > 0 AND tv_episodes_id = {} AND {SETTLED}",
        tv_category_condition(),
        tv_status::EPISODE_MISSING,
    );
    if ignore_schedule {
        return condition;
    }
    format!("{condition} AND tv_lookup_attempts < {TV_MAX_EPISODE_ATTEMPTS} AND {TV_DUE}")
}

pub fn book_category_condition() -> String {
    format!(
        "(categories_id BETWEEN {BOOKS_ROOT} AND {BOOKS_UNKNOWN} \
         OR categories_id = {MUSIC_AUDIOBOOK})"
    )
}

pub fn book_metadata_condition(lookup_mode: LookupMode) -> String {
    if !lookup_mode.enabled() {
        return "0 = 1".to_owned();
    }
    format!(
        "(bookinfo_id IS NULL AND book_lookup_attempts < {BOOK_MAX_FAILURES} \
         AND (book_lookup_retry_at IS NULL OR book_lookup_retry_at <= CURRENT_TIMESTAMP){})",
        lookup_mode.renamed_filter(),
    )
}

pub fn book_normalization_condition(lookup_mode: LookupMode) -> String {
    if !lookup_mode.enabled() {
        return "0 = 1".to_owned();
    }
    let not_renamed = if lookup_mode == LookupMode::RenamedOnly {
        ""
    } else {
        "isrenamed = 0 OR "
    };
    format!(
        "(book_name_normalized_at IS NULL AND ({not_renamed}\
         searchname LIKE 'N:/NZB%' OR searchname LIKE 'N_NZB_%' \
         OR name LIKE 'N:/NZB%' OR name LIKE 'N_NZB_%'))"
    )
}

/// Books due for a metadata lookup or a name normalization.
pub fn book_work_condition(lookup_mode: LookupMode) -> String {
    format!(
        "{} AND {SETTLED} AND ({} OR {})",
        book_category_condition(),
        book_metadata_condition(lookup_mode),
        book_normalization_condition(lookup_mode),
    )
}

/// IMDb values in legacy rows that mean a lookup is still pending. NULL means never tried. An
/// empty string means tried and failed, so it stays out.
pub const IMDB_PENDING_VALUES: [&str; 3] = ["0", "0000000", "00000000"];

/// Whether a release with this `imdbid` still needs a lookup.
pub fn imdb_needs_lookup(id: Option<&str>) -> bool {
    id.is_none_or(|id| IMDB_PENDING_VALUES.contains(&id.trim()))
}

/// The same check as SQL. `column` must come from code, not from input.
pub fn imdb_needs_lookup_sql(column: &str) -> String {
    format!("({column} IS NULL OR {column} IN ('0', '0000000', '00000000'))")
}

/// `AND`-prefixed filters for a group and a GUID bucket, as every worker's fetch applies them. A
/// group of 0 and an empty bucket mean no filter. `guid_char` must already be one hex character.
pub fn release_scope(group_id: i64, guid_char: &str) -> String {
    let mut scope = String::new();
    if group_id > 0 {
        scope.push_str(&format!(" AND groups_id = {group_id}"));
    }
    if guid_char.len() == 1 && guid_char.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        scope.push_str(&format!(" AND leftguid = '{guid_char}'"));
    }
    scope
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn tv_condition_matches_the_php_query() {
        assert_eq!(tv_work_condition(LookupMode::Disabled), "0 = 1");
        assert_eq!(
            tv_work_condition(LookupMode::RenamedOnly),
            "(categories_id BETWEEN 5000 AND 5999 AND categories_id <> 5070 AND size > 1048576) \
             AND videos_id = 0 AND tv_episodes_id = 0 AND late_article_candidate_id IS NULL \
             AND (tv_lookup_retry_at IS NULL OR tv_lookup_retry_at <= CURRENT_TIMESTAMP) \
             AND isrenamed = 1"
        );
    }

    #[test]
    fn book_normalization_only_widens_when_not_renamed_only() {
        assert!(book_normalization_condition(LookupMode::All).contains("isrenamed = 0 OR"));
        assert!(!book_normalization_condition(LookupMode::RenamedOnly).contains("isrenamed = 0"));
        assert_eq!(book_normalization_condition(LookupMode::Disabled), "0 = 1");
    }

    #[test]
    fn imdb_sentinels() {
        assert!(imdb_needs_lookup(None));
        assert!(imdb_needs_lookup(Some(" 0000000 ")));
        assert!(!imdb_needs_lookup(Some("")));
        assert!(!imdb_needs_lookup(Some("0137523")));
        assert_eq!(
            imdb_needs_lookup_sql("r.imdbid"),
            "(r.imdbid IS NULL OR r.imdbid IN ('0', '0000000', '00000000'))"
        );
    }

    #[test]
    fn explicit_renamed_argument_overrides_the_setting() {
        use crate::settings::Renamed;
        assert_eq!(
            Renamed::Configured.resolve(LookupMode::Disabled),
            LookupMode::Disabled
        );
        assert_eq!(
            Renamed::All.resolve(LookupMode::RenamedOnly),
            LookupMode::All
        );
        assert_eq!(
            Renamed::Only.resolve(LookupMode::Disabled),
            LookupMode::RenamedOnly
        );
    }

    #[test]
    fn scope_ignores_anything_but_one_hex_char() {
        assert_eq!(release_scope(0, ""), "");
        assert_eq!(
            release_scope(7, "a"),
            " AND groups_id = 7 AND leftguid = 'a'"
        );
        assert_eq!(release_scope(0, "x' OR 1"), "");
    }
}
