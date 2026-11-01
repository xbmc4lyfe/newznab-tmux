//! Answers a search: the text index or the catalog's ID indexes pick and order release IDs, and
//! the catalog fills in each release. Everything here blocks.

use std::sync::{Mutex, PoisonError};

use anyhow::{Context, Result};
use indexer_search::{Query, TextIndex};
use indexer_store::Catalog;
use indexer_store::rusqlite::types::{Value, ValueRef};
use indexer_store::rusqlite::{self, OptionalExtension, Row, params_from_iter};

use crate::request::{Episode, Search};

/// Connections kept for reuse. More can open under load and close afterwards.
const MAX_IDLE: usize = 16;

/// Reusable handles to a file that a connection can't share between threads.
pub(crate) struct Pool<T> {
    open: Box<dyn Fn() -> Result<T> + Send + Sync>,
    idle: Mutex<Vec<T>>,
}

impl<T> Pool<T> {
    pub(crate) fn new(open: impl Fn() -> Result<T> + Send + Sync + 'static) -> Self {
        Self {
            open: Box::new(open),
            idle: Mutex::new(Vec::new()),
        }
    }

    pub(crate) fn with<R>(&self, f: impl FnOnce(&T) -> Result<R>) -> Result<R> {
        let idle = self.lock().pop();
        let handle = match idle {
            Some(handle) => handle,
            None => (self.open)()?,
        };
        let result = f(&handle);
        // A failed handle may be broken, so it closes rather than going back.
        if result.is_ok() {
            let mut idle = self.lock();
            if idle.len() < MAX_IDLE {
                idle.push(handle);
            }
        }
        result
    }

    fn lock(&self) -> std::sync::MutexGuard<'_, Vec<T>> {
        self.idle.lock().unwrap_or_else(PoisonError::into_inner)
    }
}

/// A release as the API shows it.
#[derive(Clone, Debug, PartialEq)]
pub(crate) struct Item {
    pub id: i64,
    pub guid: String,
    pub title: String,
    pub size_bytes: i64,
    pub posted_at: Option<i64>,
    pub added_at: Option<i64>,
    pub category: u32,
    pub grabs: i64,
    pub poster: Option<String>,
    pub group: Option<String>,
    pub files: Option<i64>,
    pub passworded: bool,
    pub imdb_id: Option<String>,
    pub tmdb_id: Option<i64>,
    pub tvdb_id: Option<i64>,
    pub tvmaze_id: Option<i64>,
    pub trakt_id: Option<i64>,
    pub tvrage_id: Option<i64>,
    pub season: Option<i64>,
    pub episode: Option<i64>,
}

const ITEM_SQL: &str = "SELECT r.id, r.guid, r.search_name, r.size_bytes, r.posted_at, r.added_at,
         r.legacy_category, r.grabs, r.poster, g.name, r.total_parts, r.password_status,
         r.imdb_id, r.tmdb_id, r.tvdb_id, r.tvmaze_id, r.trakt_id, r.tvrage_id, r.season, r.episode
     FROM releases r LEFT JOIN usenet_groups g ON g.id = r.group_id";

/// Text columns can hold a BLOB when the source bytes were not UTF-8.
fn text(row: &Row<'_>, index: usize) -> rusqlite::Result<Option<String>> {
    Ok(match row.get_ref(index)? {
        ValueRef::Null => None,
        ValueRef::Text(bytes) | ValueRef::Blob(bytes) => {
            Some(String::from_utf8_lossy(bytes).into_owned())
        }
        ValueRef::Integer(n) => Some(n.to_string()),
        ValueRef::Real(n) => Some(n.to_string()),
    })
}

fn item(row: &Row<'_>) -> rusqlite::Result<Item> {
    let password_status: i64 = row.get(11)?;
    Ok(Item {
        id: row.get(0)?,
        guid: text(row, 1)?.unwrap_or_default(),
        title: text(row, 2)?.unwrap_or_default(),
        size_bytes: row.get(3)?,
        posted_at: row.get(4)?,
        added_at: row.get(5)?,
        category: u32::try_from(row.get::<_, i64>(6)?).unwrap_or(0),
        grabs: row.get(7)?,
        poster: text(row, 8)?,
        group: text(row, 9)?,
        files: row.get(10)?,
        passworded: password_status > 0,
        imdb_id: text(row, 12)?,
        tmdb_id: row.get(13)?,
        tvdb_id: row.get(14)?,
        tvmaze_id: row.get(15)?,
        trakt_id: row.get(16)?,
        tvrage_id: row.get(17)?,
        season: row.get(18)?,
        episode: row.get(19)?,
    })
}

pub(crate) fn item_by_guid(catalog: &Catalog, guid: &str) -> Result<Option<Item>> {
    let sql = format!("{ITEM_SQL} WHERE r.guid = ?1");
    Ok(catalog
        .conn()
        .prepare_cached(&sql)?
        .query_row([guid], item)
        .optional()?)
}

/// Fills in releases in the order given, leaving out any the catalog no longer has.
fn items_by_id(catalog: &Catalog, ids: &[i64]) -> Result<Vec<Item>> {
    if ids.is_empty() {
        return Ok(Vec::new());
    }
    let placeholders = vec!["?"; ids.len()].join(",");
    let sql = format!("{ITEM_SQL} WHERE r.id IN ({placeholders})");
    let mut statement = catalog.conn().prepare_cached(&sql)?;
    let mut found: Vec<Item> = statement
        .query_map(params_from_iter(ids), item)?
        .collect::<rusqlite::Result<_>>()?;
    let position = |id: i64| ids.iter().position(|&wanted| wanted == id);
    found.sort_by_key(|item| position(item.id));
    Ok(found)
}

/// One page of results, and how many there are in all.
#[derive(Debug, Default)]
pub(crate) struct Results {
    pub total: u64,
    pub items: Vec<Item>,
}

/// Searches the catalog by outside ID when the request has one, and the text index otherwise.
pub(crate) fn search(
    index: &dyn TextIndex,
    catalog: &Catalog,
    search: &Search,
    now: i64,
) -> Result<Results> {
    if search.categories.is_empty() {
        return Ok(Results::default());
    }
    let min_posted_at = search
        .max_age_days
        .map(|days| now.saturating_sub(i64::from(days) * 86_400));
    if !search.ids.is_empty() {
        return by_ids(catalog, search, min_posted_at);
    }
    let page = index.search(&Query {
        text: query_text(search),
        categories: search.categories.iter().map(|&c| (c, c)).collect(),
        min_size_bytes: search.min_size,
        max_size_bytes: search.max_size,
        min_posted_at,
        offset: search.offset,
        limit: search.limit,
    })?;
    Ok(Results {
        total: page.total,
        items: items_by_id(catalog, &page.ids)?,
    })
}

/// `q`, plus the words the index holds for the season, episode or air date.
fn query_text(search: &Search) -> String {
    let mut text = search.text.clone();
    let extra = match (search.season, search.episode) {
        (Some(season), Some(Episode::Number(episode))) => format!("s{season:02}e{episode:02}"),
        (Some(season), None) => format!("s{season:02}"),
        (None, Some(Episode::Date { year, month, day })) => format!("{year} {month:02} {day:02}"),
        // An episode without its season can't be matched exactly, so it narrows nothing.
        _ => String::new(),
    };
    if !extra.is_empty() {
        text.push(' ');
        text.push_str(&extra);
    }
    text
}

fn by_ids(catalog: &Catalog, search: &Search, min_posted_at: Option<i64>) -> Result<Results> {
    let ids = &search.ids;
    let mut values: Vec<Value> = Vec::new();
    let mut any = Vec::new();
    let mut id_clause = |column: &str, value: Option<Value>| {
        if let Some(value) = value {
            values.push(value);
            any.push(format!("r.{column} = ?"));
        }
    };
    id_clause("tvdb_id", ids.tvdb.map(Value::Integer));
    id_clause("tvmaze_id", ids.tvmaze.map(Value::Integer));
    id_clause("tvrage_id", ids.tvrage.map(Value::Integer));
    id_clause("trakt_id", ids.trakt.map(Value::Integer));
    id_clause("tmdb_id", ids.tmdb.map(Value::Integer));
    if let Some(imdb) = &ids.imdb {
        values.push(Value::Text(imdb.clone()));
        values.push(Value::Text(format!("tt{imdb}")));
        any.push("r.imdb_id IN (?, ?)".to_owned());
    }
    let categories = vec!["?"; search.categories.len()].join(",");
    let mut clauses = vec![
        format!("({})", any.join(" OR ")),
        format!("r.legacy_category IN ({categories})"),
    ];
    values.extend(search.categories.iter().map(|&c| Value::Integer(c.into())));
    let mut filter = |clause: &str, value: Value| {
        clauses.push(clause.to_owned());
        values.push(value);
    };
    if let Some(season) = search.season {
        filter("r.season = ?", Value::Integer(season.into()));
    }
    match search.episode {
        Some(Episode::Number(episode)) => filter("r.episode = ?", Value::Integer(episode.into())),
        Some(Episode::Date { year, month, day }) => filter(
            "r.search_name LIKE ?",
            Value::Text(format!("%{year}_{month:02}_{day:02}%")),
        ),
        None => {}
    }
    if let Some(min) = search.min_size {
        filter("r.size_bytes >= ?", Value::Integer(saturating_i64(min)));
    }
    if let Some(max) = search.max_size {
        filter("r.size_bytes <= ?", Value::Integer(saturating_i64(max)));
    }
    if let Some(posted) = min_posted_at {
        filter("r.posted_at >= ?", Value::Integer(posted));
    }
    let where_clause = clauses.join(" AND ");
    let conn = catalog.conn();
    let total: i64 = conn
        .prepare_cached(&format!(
            "SELECT COUNT(*) FROM releases r WHERE {where_clause}"
        ))?
        .query_row(params_from_iter(&values), |row| row.get(0))
        .context("counting releases by ID")?;
    let mut page_values = values;
    page_values.push(Value::Integer(saturating_i64(search.limit as u64)));
    page_values.push(Value::Integer(saturating_i64(search.offset as u64)));
    // NULL times sort last when descending.
    let sql = format!(
        "SELECT r.id FROM releases r WHERE {where_clause}
         ORDER BY r.posted_at DESC, r.id DESC LIMIT ? OFFSET ?"
    );
    let page: Vec<i64> = conn
        .prepare_cached(&sql)?
        .query_map(params_from_iter(&page_values), |row| row.get(0))?
        .collect::<rusqlite::Result<_>>()
        .context("finding releases by ID")?;
    Ok(Results {
        total: u64::try_from(total).unwrap_or(0),
        items: items_by_id(catalog, &page)?,
    })
}

fn saturating_i64(n: u64) -> i64 {
    i64::try_from(n).unwrap_or(i64::MAX)
}
