//! Release rows as the SQL returned them, and the single-release query both API versions share.

use anyhow::Result;
use chrono::{DateTime, NaiveDate, NaiveDateTime, Utc};
use nntmux_runtime::Ctx;
use serde::{Deserialize, Serialize};
use sqlx::{AssertSqlSafe, QueryBuilder};

use crate::db::row_json;
use crate::json::J;

const MOVIES_GROUP: [i64; 11] = [
    2010, 2000, 2999, 2030, 2040, 2045, 2050, 2060, 2070, 2080, 2090,
];
const TV_GROUP: [i64; 11] = [
    5020, 5000, 5999, 5030, 5040, 5045, 5070, 5080, 5060, 5010, 5090,
];

pub fn is_movie(category: i64) -> bool {
    MOVIES_GROUP.contains(&category)
}

pub fn is_tv(category: i64) -> bool {
    TV_GROUP.contains(&category)
}

/// A row's columns in select order. Search rows also carry `_totalrows` and friends.
#[derive(Clone, Debug, Default, Deserialize, Serialize)]
pub struct Row(pub Vec<(String, J)>);

impl Row {
    pub fn from_json(value: J) -> Self {
        match value {
            J::Obj(entries) => Self(entries),
            _ => Self::default(),
        }
    }

    /// The value, with SQL NULL and absent columns both `None`, as PHP's `isset` sees them.
    pub fn get(&self, key: &str) -> Option<&J> {
        self.0
            .iter()
            .find(|(name, _)| name == key)
            .map(|(_, value)| value)
            .filter(|value| **value != J::Null)
    }

    pub fn or_null(&self, key: &str) -> J {
        self.get(key).cloned().unwrap_or(J::Null)
    }

    pub fn set(&mut self, key: &str, value: J) {
        match self.0.iter_mut().find(|(name, _)| name == key) {
            Some(entry) => entry.1 = value,
            None => self.0.push((key.to_owned(), value)),
        }
    }

    /// `(string) $value`.
    pub fn text(&self, key: &str) -> String {
        self.get(key).map(scalar_text).unwrap_or_default()
    }

    /// `(int) $value`.
    pub fn int(&self, key: &str) -> i64 {
        self.get(key).map_or(0, scalar_int)
    }

    /// `$value !== null && $value > 0`.
    pub fn positive(&self, key: &str) -> bool {
        self.int(key) > 0
    }
}

pub fn scalar_text(value: &J) -> String {
    match value {
        J::Null => String::new(),
        J::Bool(flag) => if *flag { "1" } else { "" }.to_owned(),
        J::Int(number) => number.to_string(),
        J::Float(number) => number.to_string(),
        J::Str(text) => text.clone(),
        J::Arr(_) | J::Obj(_) => String::new(),
    }
}

pub fn scalar_int(value: &J) -> i64 {
    match value {
        J::Int(number) => *number,
        J::Float(number) => *number as i64,
        J::Bool(flag) => i64::from(*flag),
        J::Str(text) => crate::input::php_int(text),
        _ => 0,
    }
}

/// PHP's `empty`.
pub fn is_empty(value: Option<&J>) -> bool {
    match value {
        None | Some(J::Null) => true,
        Some(J::Str(text)) => text.is_empty() || text == "0",
        Some(J::Int(0)) | Some(J::Bool(false)) => true,
        Some(J::Float(number)) => *number == 0.0,
        Some(_) => false,
    }
}

/// `imdb_id_is_valid`: has digits, and they aren't all zero.
pub fn imdb_id_is_valid(value: &str) -> bool {
    let digits: String = value.trim().chars().filter(char::is_ascii_digit).collect();
    !digits.trim_start_matches('0').is_empty()
}

/// Parses the date forms the database gives. `strtotime` on anything else isn't reachable here.
pub fn parse_time(text: &str) -> Option<DateTime<Utc>> {
    let text = text.trim();
    if let Ok(time) = NaiveDateTime::parse_from_str(text, "%Y-%m-%d %H:%M:%S") {
        return Some(time.and_utc());
    }
    NaiveDate::parse_from_str(text, "%Y-%m-%d")
        .ok()
        .and_then(|date| date.and_hms_opt(0, 0, 0))
        .map(|time| time.and_utc())
}

/// `DATE_RSS` in UTC.
pub fn rss_date(time: DateTime<Utc>) -> String {
    time.format("%a, %d %b %Y %H:%M:%S +0000").to_string()
}

/// `Release::getByGuidForApi`. v1 asks for the compatibility columns, v2 doesn't.
pub async fn by_guid_for_api(ctx: &Ctx, guid: &str, v1_columns: bool) -> Result<Option<Row>> {
    let extra = if v1_columns {
        ", r.videos_id, r.tv_episodes_id, r.haspreview, r.nfostatus, r.movieinfo_id, r.musicinfo_id,
           r.consoleinfo_id, c.root_categories_id AS parentid, cp.title AS parent_category,
           c.title AS sub_category, g.name AS group_name"
    } else {
        ""
    };
    let group_join = if v1_columns {
        "LEFT JOIN usenet_groups g ON g.id = r.groups_id"
    } else {
        ""
    };
    let sql = format!(
        "SELECT r.id, r.searchname, r.guid, r.postdate, r.categories_id, r.size, r.totalpart, r.fromname,
                r.passwordstatus, r.grabs, r.comments, r.adddate,
                CONCAT(cp.title, ' > ', c.title) AS category_name,
                v.tvdb, v.trakt, v.tvrage, v.tvmaze, v.imdb, v.tmdb,
                m.imdbid, m.tmdbid, m.traktid,
                tve.title, tve.series, tve.episode, tve.firstaired,
                r.articles_found, r.articles_expected, r.files_found, r.files_expected{extra}
         FROM releases r
         LEFT JOIN categories c ON c.id = r.categories_id
         LEFT JOIN root_categories cp ON cp.id = c.root_categories_id
         LEFT JOIN videos v ON r.videos_id = v.id AND r.videos_id > 0
         LEFT JOIN tv_episodes tve ON r.tv_episodes_id = tve.id AND r.tv_episodes_id > 0
         LEFT JOIN movieinfo m ON m.id = r.movieinfo_id AND r.movieinfo_id > 0
         {group_join}
         WHERE r.guid = ?
         LIMIT 1"
    );
    let row = sqlx::query(AssertSqlSafe(sql))
        .bind(guid)
        .fetch_optional(&ctx.db)
        .await?;
    Ok(match row {
        Some(row) => Some(Row::from_json(row_json(&row)?)),
        None => None,
    })
}

const PARTIAL_COLUMNS: [&str; 4] = [
    "articles_found",
    "articles_expected",
    "files_found",
    "files_expected",
];

/// `ReleaseRows::withPartialCounts`: search and browse rows omit the partial-publication counts,
/// so they're attached by GUID so clients see incompleteness before downloading. A failure here
/// leaves the rows as they are.
pub async fn attach_partial_counts(ctx: &Ctx, rows: &mut [Row]) {
    let guids: Vec<String> = rows
        .iter()
        .filter_map(|row| match row.get("guid") {
            Some(J::Str(guid)) => Some(guid.clone()),
            _ => None,
        })
        .collect();
    if guids.is_empty() {
        return;
    }
    let mut query = QueryBuilder::new(
        "SELECT guid, articles_found, articles_expected, files_found, files_expected
         FROM releases WHERE articles_expected IS NOT NULL AND guid IN (",
    );
    let mut list = query.separated(", ");
    for guid in &guids {
        list.push_bind(guid.as_str());
    }
    query.push(")");
    let Ok(found) = query.build().fetch_all(&ctx.db).await else {
        return;
    };
    for record in &found {
        let Ok(counts) = row_json(record).map(Row::from_json) else {
            continue;
        };
        let guid = counts.text("guid");
        for row in rows.iter_mut().filter(|row| row.text("guid") == guid) {
            for column in PARTIAL_COLUMNS {
                row.set(column, counts.or_null(column));
            }
        }
    }
}
