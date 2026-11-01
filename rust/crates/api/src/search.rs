//! `ReleaseSearchService` and `ReleaseBrowseService`: the API's release listings. The index narrows
//! and sorts where it can, SQL hydrates and fills in the rest.

use std::collections::HashSet;

use anyhow::Result;
use chrono::DateTime;
use nntmux_runtime::Ctx;
use nntmux_search::{
    ExternalId, Phrases, ReleaseDocument, ReleaseSearchQuery, SearchClient, SearchCursor,
    SearchError, SearchPage, SecondaryIndex, SortDirection, SortField,
};
use sqlx::AssertSqlSafe;
use tracing::warn;

use crate::category;
use crate::db::row_json;
use crate::input::{is_numeric, php_int};
use crate::json::J;
use crate::rows::{Row, imdb_id_is_valid};

const MAX_CANDIDATES: u64 = 2000;
const MAX_PAGER_RESULTS: i64 = 125_000;
const MUSIC_ROOT_SEARCH_LIMIT: u64 = 2000;

pub const TV_GROUP: [i64; 11] = [
    5020, 5000, 5999, 5030, 5040, 5045, 5070, 5080, 5060, 5010, 5090,
];
pub const MOVIES_GROUP: [i64; 11] = [
    2010, 2000, 2999, 2030, 2040, 2045, 2050, 2060, 2070, 2080, 2090,
];
const TV_ANIME: i64 = 5070;

const SEARCH_COLUMNS: &str =
    "r.id, r.searchname, r.guid, r.postdate, r.categories_id, r.size, r.totalpart, r.fromname,
    r.passwordstatus, r.grabs, r.comments, r.adddate,
    cp.title AS parent_category, c.title AS sub_category,
    CONCAT(cp.title, ' > ', c.title) AS category_name,
    g.name AS group_name,
    m.imdbid, m.tmdbid, m.traktid,
    v.tvdb, v.trakt, v.tvrage, v.tvmaze, v.imdb, v.tmdb,
    tve.firstaired, tve.title, tve.series, tve.episode";

const SEARCH_JOINS: &str = "FROM releases r
    INNER JOIN categories c ON c.id = r.categories_id
    INNER JOIN root_categories cp ON cp.id = c.root_categories_id
    LEFT JOIN usenet_groups g ON g.id = r.groups_id
    LEFT JOIN videos v ON r.videos_id = v.id AND r.videos_id > 0
    LEFT JOIN tv_episodes tve ON r.tv_episodes_id = tve.id AND r.tv_episodes_id > 0
    LEFT JOIN movieinfo m ON m.id = r.movieinfo_id AND r.movieinfo_id > 0";

/// A listing's rows, and whether a search failed along the way. Degraded rows aren't cached.
#[derive(Debug, Default)]
pub struct Found {
    pub rows: Vec<Row>,
    pub degraded: bool,
}

impl Found {
    fn of(rows: Vec<Row>) -> Self {
        Self {
            rows,
            degraded: false,
        }
    }

    pub fn from_rows(rows: Vec<Row>) -> Self {
        Self::of(rows)
    }

    fn failed() -> Self {
        Self {
            rows: Vec::new(),
            degraded: true,
        }
    }
}

/// `$cat` as requested: `-1` means no filter, as the controllers' `[-1]`.
#[derive(Clone, Debug)]
pub struct Categories(pub Vec<String>);

impl Categories {
    pub fn unfiltered() -> Self {
        Self(vec!["-1".to_owned()])
    }

    pub fn is_unfiltered(&self) -> bool {
        self.0.len() == 1 && self.0[0] == "-1"
    }

    pub fn ids(ids: &[i64]) -> Self {
        Self(ids.iter().map(i64::to_string).collect())
    }
}

/// The filters shared by every listing.
#[derive(Clone, Debug)]
pub struct Filters {
    pub categories: Categories,
    pub excluded: Vec<i64>,
    pub max_age: i64,
    pub min_size: i64,
    pub group: Option<String>,
    pub offset: i64,
    pub limit: i64,
    pub sort: String,
}

#[derive(Clone, Copy)]
pub enum CategoryKind {
    Plain,
    Tv,
    Movies,
    Anime,
}

fn sorted(filters: &Filters) -> (&'static str, &'static str) {
    let (name, direction) = filters
        .sort
        .split_once('_')
        .unwrap_or((filters.sort.as_str(), "desc"));
    let field = match name {
        "cat" => "categories_id",
        "name" => "searchname",
        "size" => "size",
        "files" => "totalpart",
        "stats" => "grabs",
        "comments" => "comments",
        "added" => "adddate",
        _ => "postdate",
    };
    (
        field,
        if direction.eq_ignore_ascii_case("asc") {
            "asc"
        } else {
            "desc"
        },
    )
}

fn index_sort(field: &str) -> SortField {
    match field {
        "adddate" => SortField::AddDate,
        "categories_id" => SortField::Category,
        "size" => SortField::Size,
        "totalpart" => SortField::TotalPart,
        "grabs" => SortField::Grabs,
        "comments" => SortField::Comments,
        _ => SortField::PostDate,
    }
}

/// `escapeString`: PDO's quoting for the one or two values that aren't bound.
fn quote(value: &str) -> String {
    let mut out = String::with_capacity(value.len() + 2);
    out.push('\'');
    for ch in value.chars() {
        match ch {
            '\0' => out.push_str("\\0"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\u{1a}' => out.push_str("\\Z"),
            '\\' | '\'' | '"' => {
                out.push('\\');
                out.push(ch);
            }
            c => out.push(c),
        }
    }
    out.push('\'');
    out
}

fn id_list(ids: &[i64]) -> String {
    ids.iter().map(i64::to_string).collect::<Vec<_>>().join(",")
}

/// `$value > 0` for a request string: numeric strings compare as numbers; PHP 8 compares anything
/// else with "0" as text.
fn php_positive(value: &str) -> bool {
    if is_numeric(value) {
        value.trim().parse::<f64>().is_ok_and(|number| number > 0.0)
    } else {
        value > "0"
    }
}

fn from_timestamp(timestamp: i64) -> J {
    if timestamp <= 0 {
        return J::Null;
    }
    DateTime::from_timestamp(timestamp, 0).map_or(J::Null, |date| {
        J::Str(date.naive_utc().format("%Y-%m-%d %H:%M:%S").to_string())
    })
}

/// `ReleaseSearchIndexDocument::toReleaseRow`, plus the two columns the API's rows call otherwise.
fn document_row(document: &ReleaseDocument) -> Row {
    let text = |value: &str| J::str(value);
    Row(vec![
        ("id".to_owned(), J::Int(document.id)),
        ("guid".to_owned(), text(&document.guid)),
        ("searchname".to_owned(), text(&document.searchname)),
        ("fromname".to_owned(), text(&document.fromname)),
        ("categories_id".to_owned(), J::Int(document.categories_id)),
        ("category_name".to_owned(), text(&document.category_name)),
        (
            "parent_category".to_owned(),
            text(&document.parent_category),
        ),
        ("sub_category".to_owned(), text(&document.sub_category)),
        ("group_name".to_owned(), text(&document.group_name)),
        ("imdbid".to_owned(), text(&document.imdbid)),
        ("tmdbid".to_owned(), J::Int(document.tmdbid)),
        ("traktid".to_owned(), J::Int(document.traktid)),
        ("tvdb".to_owned(), J::Int(document.tvdb)),
        ("tvmaze".to_owned(), J::Int(document.tvmaze)),
        ("tvrage".to_owned(), J::Int(document.tvrage)),
        ("trakt".to_owned(), J::Int(document.trakt)),
        ("imdb".to_owned(), text(&document.imdb)),
        ("tmdb".to_owned(), J::Int(document.tmdb)),
        ("videos_id".to_owned(), J::Int(document.videos_id)),
        ("tv_episodes_id".to_owned(), J::Int(document.tv_episodes_id)),
        ("movieinfo_id".to_owned(), J::Int(document.movieinfo_id)),
        ("anidbid".to_owned(), J::Int(document.anidbid)),
        ("title".to_owned(), text(&document.episode_title)),
        ("series".to_owned(), J::Int(document.series)),
        ("episode".to_owned(), J::Int(document.episode)),
        (
            "firstaired".to_owned(),
            from_timestamp(document.firstaired_ts),
        ),
        ("size".to_owned(), J::Int(document.size)),
        ("postdate".to_owned(), from_timestamp(document.postdate_ts)),
        ("adddate".to_owned(), from_timestamp(document.adddate_ts)),
        ("totalpart".to_owned(), J::Int(document.totalpart)),
        ("grabs".to_owned(), J::Int(document.grabs)),
        ("comments".to_owned(), J::Int(document.comments)),
        ("passwordstatus".to_owned(), J::Int(document.passwordstatus)),
        ("nfostatus".to_owned(), J::Int(document.nfostatus)),
        ("haspreview".to_owned(), J::Int(document.haspreview)),
        ("nfoid".to_owned(), J::Int(document.nfoid)),
    ])
}

pub struct Searcher<'a> {
    ctx: &'a Ctx,
    client: &'a SearchClient,
    allow_rar: bool,
    passwords: &'static str,
}

impl<'a> Searcher<'a> {
    pub async fn new(ctx: &'a Ctx, client: &'a SearchClient) -> Result<Self> {
        let allow: Option<bool> = sqlx::query_scalar(
            "SELECT show_passworded_releases FROM ingestion_configurations LIMIT 1",
        )
        .fetch_optional(&ctx.db)
        .await?;
        let allow_rar = allow.unwrap_or(false);
        Ok(Self {
            ctx,
            client,
            allow_rar,
            passwords: if allow_rar { "<= 1" } else { "<= 0" },
        })
    }

    async fn rows(&self, sql: String) -> Result<Vec<Row>> {
        let rows = sqlx::query(AssertSqlSafe(sql))
            .fetch_all(&self.ctx.db)
            .await?;
        rows.iter()
            .map(|row| row_json(row).map(Row::from_json))
            .collect()
    }

    async fn count(&self, sql: String) -> Result<i64> {
        Ok(sqlx::query_scalar(AssertSqlSafe(sql))
            .fetch_one(&self.ctx.db)
            .await?)
    }

    async fn group_id(&self, name: &str) -> Result<Option<i64>> {
        let id: Option<u32> =
            sqlx::query_scalar("SELECT id FROM usenet_groups WHERE name = ? LIMIT 1")
                .bind(name)
                .fetch_optional(&self.ctx.db)
                .await?;
        Ok(id.map(i64::from))
    }

    /// `getCategorySearch`'s defaults per listing, then expansion.
    async fn category_ids(&self, categories: &Categories, kind: CategoryKind) -> Result<Vec<i64>> {
        let chosen = if categories.is_unfiltered() || categories.0.is_empty() {
            match kind {
                CategoryKind::Tv => Categories::ids(&TV_GROUP),
                CategoryKind::Movies => Categories::ids(&MOVIES_GROUP),
                CategoryKind::Anime => Categories::ids(&[TV_ANIME]),
                CategoryKind::Plain => categories.clone(),
            }
        } else {
            categories.clone()
        };
        category::expand(self.ctx, &chosen.0).await
    }

    async fn category_sql(&self, categories: &Categories, kind: CategoryKind) -> Result<String> {
        Ok(category::sql_filter(
            &self.category_ids(categories, kind).await?,
        ))
    }

    fn excluded_sql(excluded: &[i64]) -> String {
        if excluded.is_empty() {
            String::new()
        } else {
            format!(" AND r.categories_id NOT IN ({})", id_list(excluded))
        }
    }

    fn age_sql(max_age: i64) -> String {
        if max_age > 0 {
            format!(" AND r.postdate > (NOW() - INTERVAL {max_age} DAY)")
        } else {
            String::new()
        }
    }

    fn size_sql(min_size: i64) -> String {
        if min_size > 0 {
            format!(" AND r.size >= {min_size}")
        } else {
            String::new()
        }
    }

    /// A failed search reads as no ids with the listing marked degraded, which answers 503 rather
    /// than "no matches". That includes text Manticore can't parse, as it did in PHP. A page past
    /// what Manticore serves is simply empty.
    fn soften<T: Default>(result: Result<T, SearchError>, degraded: &mut bool) -> T {
        match result {
            Ok(value) => value,
            Err(SearchError::BeyondWindow { .. }) => T::default(),
            Err(failure) => {
                warn!(error = %failure, "search failed");
                *degraded = true;
                T::default()
            }
        }
    }

    fn prefilter_limit(&self) -> u64 {
        MAX_CANDIDATES.max(self.client.config().max_matches)
    }

    async fn text_query(
        &self,
        filters: &Filters,
        phrases: Option<Phrases>,
    ) -> Result<ReleaseSearchQuery> {
        let group_id = match &filters.group {
            Some(name) => self.group_id(name).await?,
            None => None,
        };
        Ok(ReleaseSearchQuery {
            phrases,
            category_ids: self
                .category_ids(&filters.categories, CategoryKind::Plain)
                .await?,
            excluded_category_ids: filters.excluded.clone(),
            min_size: filters.min_size,
            max_age_days: filters.max_age,
            group_id,
            password_allow_rar: self.allow_rar,
            ..ReleaseSearchQuery::default()
        })
    }

    fn page_rows(page: SearchPage) -> Vec<Row> {
        let mut rows: Vec<Row> = page.documents.iter().map(document_row).collect();
        if let Some(first) = rows.first_mut() {
            first.set(
                "_totalrows",
                J::Int(i64::try_from(page.total).unwrap_or(i64::MAX)),
            );
            first.set("_search_has_more", J::Bool(page.has_more));
        }
        rows
    }

    /// `apiSearch`: text and filters through the index.
    pub async fn api_search(
        &self,
        text: Option<&str>,
        filters: &Filters,
        cursor: Option<SearchCursor>,
    ) -> Result<Found> {
        let text = text.filter(|text| !text.is_empty());
        if !self.client.is_available().await {
            if text.is_some() {
                return Ok(Found::failed());
            }
            return self.legacy_list(filters).await;
        }
        let (field, direction) = sorted(filters);
        let phrases = text.map(|text| Phrases::Text(text.to_owned()));
        let mut query = self.text_query(filters, phrases).await?;
        query.try_fuzzy = true;
        query.sort_direction = SortDirection::parse(direction);
        if field == "searchname" {
            return self.by_name(query, filters, direction).await;
        }
        query.sort_field = index_sort(field);
        query.include_documents = true;
        query.limit = u64::try_from(filters.limit).unwrap_or(1);
        query.offset = u64::try_from(filters.offset).unwrap_or(0);
        query.cursor = cursor;
        let mut degraded = false;
        let page = Self::soften(self.client.search_releases(&query).await, &mut degraded);
        Ok(Found {
            rows: Self::page_rows(page),
            degraded,
        })
    }

    /// The index has no sortable name, so it filters and SQL orders the newest matches by name.
    /// The total is the candidate count, so clients can't page past what the ordering covers.
    async fn by_name(
        &self,
        mut query: ReleaseSearchQuery,
        filters: &Filters,
        direction: &str,
    ) -> Result<Found> {
        query.sort_field = SortField::PostDate;
        query.sort_direction = SortDirection::Desc;
        query.limit = MAX_CANDIDATES;
        query.offset = 0;
        let mut degraded = false;
        let page = Self::soften(self.client.search_releases(&query).await, &mut degraded);
        let total = i64::try_from(page.ids.len()).unwrap_or(i64::MAX);
        if total == 0 || filters.offset >= total {
            return Ok(Found {
                rows: Vec::new(),
                degraded,
            });
        }
        let (limit, offset) = (filters.limit, filters.offset);
        let mut rows = self
            .rows(format!(
                "SELECT {SEARCH_COLUMNS} {SEARCH_JOINS} WHERE r.id IN ({})
                 ORDER BY r.searchname {direction}, r.id {direction} LIMIT {limit} OFFSET {offset}",
                id_list(&page.ids),
            ))
            .await?;
        let shown = i64::try_from(rows.len()).unwrap_or(i64::MAX);
        if let Some(first) = rows.first_mut() {
            first.set("_totalrows", J::Int(total));
            first.set("_search_has_more", J::Bool(offset + shown < total));
        }
        Ok(Found { rows, degraded })
    }

    /// `apiSearchLegacyMysql` without text: SQL filters when the index is down.
    async fn legacy_list(&self, filters: &Filters) -> Result<Found> {
        let mut conditions = format!("WHERE r.passwordstatus {}", self.passwords);
        conditions.push_str(&Self::age_sql(filters.max_age));
        if let Some(name) = &filters.group
            && let Some(id) = self.group_id(name).await?
        {
            conditions.push_str(&format!(" AND r.groups_id = {id}"));
        }
        conditions.push_str(
            &self
                .category_sql(&filters.categories, CategoryKind::Plain)
                .await?,
        );
        conditions.push_str(&Self::excluded_sql(&filters.excluded));
        conditions.push_str(&Self::size_sql(filters.min_size));
        self.listed(&conditions, filters).await
    }

    async fn listed(&self, conditions: &str, filters: &Filters) -> Result<Found> {
        let (field, direction) = sorted(filters);
        let (limit, offset) = (filters.limit, filters.offset);
        let mut rows = self
            .rows(format!(
                "SELECT {SEARCH_COLUMNS} {SEARCH_JOINS} {conditions}
                 ORDER BY r.{field} {direction} LIMIT {limit} OFFSET {offset}"
            ))
            .await?;
        if !rows.is_empty() {
            let total = self
                .count(format!("SELECT COUNT(*) FROM releases r {conditions}"))
                .await?;
            rows[0].set("_totalrows", J::Int(total));
        }
        Ok(Found::of(rows))
    }

    /// `apiMusicSearch` and `apiBookSearch`: releases linked to the metadata index's rows.
    pub async fn by_metadata(
        &self,
        index: SecondaryIndex,
        column: &str,
        text: &str,
        filters: &Filters,
    ) -> Result<Found> {
        let text = text.trim();
        if text.is_empty() {
            return Ok(Found::of(Vec::new()));
        }
        let mut degraded = false;
        let hits = Self::soften(
            self.client
                .search_secondary(index, text, MUSIC_ROOT_SEARCH_LIMIT)
                .await,
            &mut degraded,
        );
        if hits.id.is_empty() {
            return Ok(Found {
                rows: Vec::new(),
                degraded,
            });
        }
        let mut conditions = format!(
            "WHERE r.passwordstatus {} AND r.{column} IN ({})",
            self.passwords,
            id_list(&hits.id)
        );
        conditions.push_str(&Self::age_sql(filters.max_age));
        if let Some(name) = &filters.group
            && let Some(id) = self.group_id(name).await?
        {
            conditions.push_str(&format!(" AND r.groups_id = {id}"));
        }
        conditions.push_str(
            &self
                .category_sql(&filters.categories, CategoryKind::Plain)
                .await?,
        );
        conditions.push_str(&Self::excluded_sql(&filters.excluded));
        conditions.push_str(&Self::size_sql(filters.min_size));
        let mut found = self.listed(&conditions, filters).await?;
        found.degraded = degraded;
        Ok(found)
    }

    /// `intersectReleaseIdsWithSearchFilters`.
    async fn intersect(
        &self,
        ids: &[i64],
        categories: &Categories,
        kind: CategoryKind,
        filters: &Filters,
        degraded: &mut bool,
    ) -> Result<Vec<i64>> {
        let mut seen = HashSet::new();
        let unique: Vec<i64> = ids
            .iter()
            .copied()
            .filter(|id| *id > 0 && seen.insert(*id))
            .collect();
        if unique.is_empty() {
            return Ok(Vec::new());
        }
        let limit = u64::try_from(unique.len())
            .unwrap_or(1)
            .max(1)
            .min(1000.max(self.client.config().max_matches));
        let query = ReleaseSearchQuery {
            release_ids: unique,
            category_ids: self.category_ids(categories, kind).await?,
            excluded_category_ids: filters.excluded.clone(),
            min_size: filters.min_size,
            max_age_days: filters.max_age,
            password_allow_rar: self.allow_rar,
            try_fuzzy: false,
            limit,
            ..ReleaseSearchQuery::default()
        };
        let page = Self::soften(self.client.search_releases(&query).await, degraded);
        Ok(page.ids)
    }

    /// `getBrowseRangeForApi`: filter and sort through the index; name order and an unavailable
    /// index use SQL.
    pub async fn browse(&self, filters: &Filters) -> Result<Found> {
        let (field, direction) = sorted(filters);
        if field != "searchname" && self.client.is_available().await {
            let mut query = self.text_query(filters, None).await?;
            query.try_fuzzy = false;
            query.include_documents = true;
            query.sort_field = index_sort(field);
            query.sort_direction = SortDirection::parse(direction);
            query.limit = u64::try_from(filters.limit.max(1)).unwrap_or(1);
            query.offset = u64::try_from(filters.offset).unwrap_or(0);
            let mut degraded = false;
            let page = Self::soften(self.client.search_releases(&query).await, &mut degraded);
            return Ok(Found {
                rows: Self::page_rows(page),
                degraded,
            });
        }
        self.browse_sql(filters).await
    }

    async fn browse_sql(&self, filters: &Filters) -> Result<Found> {
        let (field, direction) = sorted(filters);
        let category_sql = self
            .category_sql(&filters.categories, CategoryKind::Plain)
            .await?;
        let age = if filters.max_age > 0 {
            format!(" AND postdate > NOW() - INTERVAL {} DAY ", filters.max_age)
        } else {
            String::new()
        };
        let group = filters.group.as_ref().map_or(String::new(), |name| {
            format!(" AND g.name = {} ", quote(name))
        });
        let conditions = format!(
            "r.passwordstatus {} {category_sql} {age} {} {group} {}",
            self.passwords,
            Self::excluded_sql(&filters.excluded),
            if filters.min_size > 0 {
                format!("AND r.size >= {}", filters.min_size)
            } else {
                String::new()
            },
        );
        let window = if filters.offset == 0 {
            format!("LIMIT {}", filters.limit)
        } else {
            format!("LIMIT {} OFFSET {}", filters.limit, filters.offset)
        };
        let mut rows = self
            .rows(format!(
                "SELECT r.id, r.searchname, r.guid, r.postdate, r.categories_id, r.size, r.totalpart, r.fromname,
                    r.passwordstatus, r.grabs, r.comments, r.adddate, r.videos_id, r.haspreview, r.nfostatus, r.group_name,
                    CONCAT(cp.title, ' > ', c.title) AS category_name,
                    rn.releases_id AS nfoid,
                    v.tvdb, v.trakt, v.tvrage, v.tvmaze, v.imdb, v.tmdb,
                    m.imdbid, m.tmdbid, m.traktid,
                    tve.title, tve.series, tve.episode, tve.firstaired
                 FROM (
                    SELECT r.id, r.searchname, r.guid, r.postdate, r.groups_id, r.categories_id, r.size, r.totalpart,
                        r.fromname, r.passwordstatus, r.grabs, r.comments, r.adddate, r.videos_id, r.tv_episodes_id,
                        r.haspreview, r.nfostatus, g.name AS group_name, r.movieinfo_id
                    FROM releases r
                    LEFT JOIN usenet_groups g ON g.id = r.groups_id
                    WHERE {conditions}
                    ORDER BY {field} {direction} {window}
                 ) r
                 LEFT JOIN categories c ON c.id = r.categories_id
                 LEFT JOIN root_categories cp ON cp.id = c.root_categories_id
                 LEFT OUTER JOIN videos v ON r.videos_id = v.id
                 LEFT OUTER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
                 LEFT OUTER JOIN movieinfo m ON m.id = r.movieinfo_id
                 LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
                 GROUP BY r.id
                 ORDER BY {field} {direction}"
            ))
            .await?;
        if !rows.is_empty() {
            let group_join = if filters.group.is_some() {
                "INNER JOIN usenet_groups g ON g.id = r.groups_id"
            } else {
                ""
            };
            let total = self
                .count(format!(
                    "SELECT COUNT(r.id) FROM releases r {group_join} WHERE r.passwordstatus {} {category_sql} {} {} {group}",
                    self.passwords,
                    Self::age_sql(filters.max_age),
                    Self::excluded_sql(&filters.excluded),
                ))
                .await?;
            rows[0].set("_totalrows", J::Int(total.min(MAX_PAGER_RESULTS)));
        }
        Ok(Found::of(rows))
    }

    /// `animeSearch`.
    pub async fn anime(
        &self,
        mut anidb: i64,
        anilist: i64,
        name: &str,
        filters: &Filters,
    ) -> Result<Found> {
        if anilist > 0 {
            let resolved: Option<u32> =
                sqlx::query_scalar("SELECT anidbid FROM anidb_info WHERE anilist_id = ? LIMIT 1")
                    .bind(anilist)
                    .fetch_optional(&self.ctx.db)
                    .await?;
            match resolved {
                Some(id) => anidb = i64::from(id),
                None if name.is_empty() && anidb <= -1 => return Ok(Found::of(Vec::new())),
                None => {}
            }
        }
        let mut degraded = false;
        let mut title_filter = String::new();
        if !name.is_empty() {
            let limit = candidate_limit(filters.offset, filters.limit);
            let ids = Self::soften(
                self.client.search_anime_title(name, limit).await,
                &mut degraded,
            );
            if ids.is_empty() {
                return Ok(Found {
                    rows: Vec::new(),
                    degraded,
                });
            }
            title_filter = format!(" AND r.anidbid IN ({})", id_list(&ids));
        }
        let by_id = if anidb > -1 {
            format!(" AND r.anidbid = {anidb} ")
        } else {
            String::new()
        };
        let categories = self
            .category_sql(&filters.categories, CategoryKind::Anime)
            .await?;
        let conditions = format!(
            "WHERE r.passwordstatus {} {by_id} {title_filter} {} {categories} {} {}",
            self.passwords,
            Self::excluded_sql(&filters.excluded),
            Self::age_sql(filters.max_age),
            Self::size_sql(filters.min_size),
        );
        let (field, direction) = sorted(filters);
        let (limit, offset) = (filters.limit, filters.offset);
        let from = format!(
            "FROM releases r
             LEFT JOIN categories c ON c.id = r.categories_id
             LEFT JOIN root_categories cp ON cp.id = c.root_categories_id
             LEFT JOIN usenet_groups g ON g.id = r.groups_id
             LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
             {conditions}"
        );
        let mut rows = self
            .rows(format!(
                "SELECT r.id, r.searchname, r.guid, r.postdate, r.groups_id, r.categories_id, r.size, r.totalpart,
                    r.fromname, r.passwordstatus, r.grabs, r.comments, r.adddate, r.haspreview, r.jpgstatus,
                    cp.title AS parent_category, c.title AS sub_category,
                    CONCAT(cp.title, ' > ', c.title) AS category_name,
                    g.name AS group_name, rn.releases_id AS nfoid
                 {from}
                 ORDER BY {field} {direction} LIMIT {limit} OFFSET {offset}"
            ))
            .await?;
        if !rows.is_empty() {
            let total = self.count(format!("SELECT COUNT(*) {from}")).await?;
            rows[0].set("_totalrows", J::Int(total.min(MAX_PAGER_RESULTS)));
        }
        Ok(Found { rows, degraded })
    }

    /// `moviesSearch`.
    pub async fn movies(
        &self,
        imdb: &str,
        tmdb: i64,
        trakt: i64,
        name: &str,
        filters: &Filters,
    ) -> Result<Found> {
        let mut external = Vec::new();
        if !imdb.is_empty() && imdb_id_is_valid(imdb) {
            external.push(ExternalId::ImdbId(imdb.to_owned()));
        }
        if tmdb > 0 {
            external.push(ExternalId::TmdbId(tmdb));
        }
        if trakt > 0 {
            external.push(ExternalId::TraktId(trakt));
        }
        let has_external = !external.is_empty();
        let mut degraded = false;
        let mut ids = Vec::new();
        if has_external {
            ids = Self::soften(
                self.client
                    .search_by_external_id(&external, self.prefilter_limit())
                    .await,
                &mut degraded,
            );
        }
        if !has_external && !php_empty(name) {
            let phrases = Phrases::Text(name.to_owned());
            ids = Self::soften(
                self.client
                    .search_release_ids(&phrases, self.prefilter_limit())
                    .await,
                &mut degraded,
            );
            if ids.is_empty() {
                return Ok(Found {
                    rows: Vec::new(),
                    degraded,
                });
            }
        }
        if !ids.is_empty() {
            ids = self
                .intersect(
                    &ids,
                    &filters.categories,
                    CategoryKind::Movies,
                    filters,
                    &mut degraded,
                )
                .await?;
            if ids.is_empty() {
                return Ok(Found {
                    rows: Vec::new(),
                    degraded,
                });
            }
        }

        let mut conditions = format!("WHERE r.passwordstatus {}", self.passwords);
        if !ids.is_empty() {
            conditions.push_str(&format!(" AND r.id IN ({})", id_list(&ids)));
        }
        // External ids the index didn't know fall back to the database, in case it's out of sync.
        let needs_join = ids.is_empty() && has_external;
        if needs_join {
            if !imdb.is_empty() && imdb_id_is_valid(imdb) {
                conditions.push_str(&format!(" AND r.imdbid = {}", quote(imdb)));
            }
            if tmdb > 0 {
                conditions.push_str(&format!(" AND m.tmdbid = {tmdb}"));
            }
            if trakt > 0 {
                conditions.push_str(&format!(" AND m.traktid = {trakt}"));
            }
        }
        if ids.is_empty() {
            conditions.push_str(&Self::excluded_sql(&filters.excluded));
            conditions.push_str(
                &self
                    .category_sql(&filters.categories, CategoryKind::Movies)
                    .await?,
            );
            conditions.push_str(&Self::age_sql(filters.max_age));
            conditions.push_str(&Self::size_sql(filters.min_size));
        }
        let join = if needs_join {
            "INNER JOIN movieinfo m ON m.imdbid = r.imdbid"
        } else {
            "LEFT JOIN movieinfo m ON m.id = r.movieinfo_id"
        };
        let (field, direction) = sorted(filters);
        let (limit, offset) = (filters.limit, filters.offset);
        let mut rows = self
            .rows(format!(
                "SELECT r.id, r.searchname, r.guid, r.postdate, r.categories_id, r.size, r.totalpart, r.fromname,
                    r.passwordstatus, r.grabs, r.comments, r.adddate, m.imdbid, m.tmdbid, m.traktid,
                    cp.title AS parent_category, c.title AS sub_category,
                    CONCAT(cp.title, ' > ', c.title) AS category_name, g.name AS group_name
                 FROM releases r
                 INNER JOIN categories c ON c.id = r.categories_id
                 INNER JOIN root_categories cp ON cp.id = c.root_categories_id
                 {join}
                 LEFT JOIN usenet_groups g ON g.id = r.groups_id
                 {conditions}
                 ORDER BY r.{field} {direction} LIMIT {limit} OFFSET {offset}"
            ))
            .await?;
        if !rows.is_empty() {
            let count_join = if needs_join { join } else { "" };
            let total = self
                .count(format!(
                    "SELECT COUNT(*) FROM releases r {count_join} {conditions}"
                ))
                .await?;
            rows[0].set("_totalrows", J::Int(total));
        }
        Ok(Found { rows, degraded })
    }
}

fn php_empty(value: &str) -> bool {
    value.is_empty() || value == "0"
}

fn candidate_limit(offset: i64, limit: i64) -> u64 {
    let page = limit.max(1);
    let requested = page.max(offset + page);
    let buffered = requested.max(offset + page * 10).max(250);
    u64::try_from(buffered)
        .unwrap_or(MAX_CANDIDATES)
        .min(MAX_CANDIDATES)
}

/// The site ids of a TV search, as sent: `vid`, `tvdbid`, `traktid`, `rid`, `tvmazeid`, `imdbid`,
/// `tmdbid`.
#[derive(Clone, Debug, Default)]
pub struct TvIds {
    pub id: String,
    pub tvdb: String,
    pub trakt: String,
    pub tvrage: String,
    pub tvmaze: String,
    pub imdb: String,
    pub tmdb: String,
}

impl TvIds {
    fn columns(&self) -> [(&'static str, &str); 7] {
        [
            ("id", &self.id),
            ("tvdb", &self.tvdb),
            ("trakt", &self.trakt),
            ("tvrage", &self.tvrage),
            ("tvmaze", &self.tvmaze),
            ("imdb", &self.imdb),
            ("tmdb", &self.tmdb),
        ]
    }

    fn external(&self) -> Vec<ExternalId> {
        let mut external = Vec::new();
        let number = |value: &str| php_positive(value).then(|| php_int(value));
        if let Some(id) = number(&self.tvdb) {
            external.push(ExternalId::Tvdb(id));
        }
        if let Some(id) = number(&self.trakt) {
            external.push(ExternalId::TraktId(id));
        }
        if let Some(id) = number(&self.tvrage) {
            external.push(ExternalId::Tvrage(id));
        }
        if let Some(id) = number(&self.tvmaze) {
            external.push(ExternalId::Tvmaze(id));
        }
        if !self.imdb.is_empty() && imdb_id_is_valid(&self.imdb) {
            external.push(ExternalId::ImdbId(self.imdb.clone()));
        }
        if let Some(id) = number(&self.tmdb) {
            external.push(ExternalId::TmdbId(id));
        }
        external
    }

    /// `hasTvLookupIdentifiers`.
    fn strict(&self) -> bool {
        (!self.imdb.is_empty() && imdb_id_is_valid(&self.imdb))
            || self.columns().iter().any(|(_, value)| php_int(value) > 0)
    }

    /// `v.column = n` for each id, with the integer cast the PHP's `%d` applied.
    fn site_sql(&self) -> Vec<String> {
        self.columns()
            .iter()
            .filter(|(_, value)| php_positive(value))
            .map(|(column, value)| format!("v.{column} = {}", php_int(value)))
            .collect()
    }
}

/// `(int) preg_replace('/^s0*/i', '', ...)`: "S02" and "2" both mean season 2.
fn season_number(value: &str, letter: char) -> i64 {
    let stripped = value
        .strip_prefix(|c: char| c.eq_ignore_ascii_case(&letter))
        .map_or(value, |rest| rest.trim_start_matches('0'));
    php_int(stripped)
}

fn numeric_season(value: &str) -> bool {
    !php_empty(value) && php_int(value) < 1900
}

/// `buildEpisodeJoinPredicates`.
fn episode_predicates(series: &str, episode: &str, air_date: &str) -> Vec<String> {
    let mut predicates = Vec::new();
    if numeric_season(series) {
        predicates.push(format!("tve.series = {}", season_number(series, 's')));
        if !php_empty(episode) && !episode.contains('/') {
            predicates.push(format!("tve.episode = {}", season_number(episode, 'e')));
        }
    } else if !php_empty(air_date) {
        predicates.push(format!("DATE(tve.firstaired) = {}", quote(air_date)));
    }
    predicates
}

fn pad2(value: &str) -> String {
    format!("{value:0>2}")
}

fn tv_sql(
    where_sql: &str,
    needs_episode: bool,
    video_join: &str,
    episode_join: &str,
    filters: &Filters,
    limit: i64,
    offset: i64,
) -> String {
    let (field, direction) = sorted(filters);
    let join_type = if needs_episode { "INNER" } else { "LEFT" };
    format!(
        "SELECT r.id, r.searchname, r.guid, r.postdate, r.groups_id, r.categories_id,
            r.size, r.totalpart, r.fromname, r.passwordstatus, r.grabs, r.comments,
            r.adddate, r.videos_id, r.tv_episodes_id,
            v.title, v.tvdb, v.trakt, v.imdb, v.tmdb, v.tvmaze, v.tvrage,
            tve.series, tve.episode, tve.firstaired,
            cp.title AS parent_category, c.title AS sub_category,
            CONCAT(cp.title, ' > ', c.title) AS category_name,
            g.name AS group_name
         FROM releases r
         INNER JOIN categories c ON c.id = r.categories_id
         INNER JOIN root_categories cp ON cp.id = c.root_categories_id
         LEFT JOIN videos v ON r.videos_id = v.id AND v.type = 0 {video_join}
         {join_type} JOIN tv_episodes tve ON r.tv_episodes_id = tve.id {episode_join}
         LEFT JOIN usenet_groups g ON g.id = r.groups_id
         {where_sql}
         ORDER BY r.{field} {direction} LIMIT {limit} OFFSET {offset}"
    )
}

impl Searcher<'_> {
    /// `tvSearch`, the newznab function.
    pub async fn tv(
        &self,
        ids: &TvIds,
        series: &str,
        episode: &str,
        air_date: &str,
        name: &str,
        filters: &Filters,
    ) -> Result<Found> {
        let strict = ids.strict();
        let mut conditions = vec![format!("r.passwordstatus {}", self.passwords)];
        let mut video_join = String::new();
        let mut episode_join = String::new();
        let mut needs_episode = false;
        let mut needs_lookup = false;
        let mut name_predicates = Vec::new();
        let mut degraded = false;

        let external = ids.external();
        let mut found = Vec::new();
        if !external.is_empty() {
            found = Self::soften(
                self.client
                    .search_by_external_id(&external, self.prefilter_limit())
                    .await,
                &mut degraded,
            );
        }

        if found.is_empty() && strict {
            let site = ids.site_sql();
            if !site.is_empty() {
                needs_lookup = true;
                let video_only = site.len() == 1 && php_int(&ids.id) > 0;
                let series_filter = if series.is_empty() {
                    String::new()
                } else {
                    format!("AND tve.series = {}", season_number(series, 's'))
                };
                let episode_filter = if episode.is_empty() {
                    String::new()
                } else {
                    format!("AND tve.episode = {}", season_number(episode, 'e'))
                };
                let air_filter = if air_date.is_empty() {
                    String::new()
                } else {
                    format!("AND DATE(tve.firstaired) = {}", quote(air_date))
                };
                let lookups: Vec<(u32, Option<u32>)> = sqlx::query_as(AssertSqlSafe(format!(
                    "SELECT v.id, tve.id FROM videos v LEFT JOIN tv_episodes tve ON v.id = tve.videos_id
                     WHERE ({}) {series_filter} {episode_filter} {air_filter}",
                    site.join(" OR "),
                )))
                .fetch_all(&self.ctx.db)
                .await?;
                if lookups.is_empty() {
                    return Ok(Found {
                        rows: Vec::new(),
                        degraded,
                    });
                }
                let mut videos: Vec<i64> =
                    lookups.iter().map(|(video, _)| i64::from(*video)).collect();
                videos.dedup();
                let mut episodes: Vec<i64> = lookups
                    .iter()
                    .filter_map(|(_, id)| id.map(i64::from))
                    .collect();
                episodes.dedup();
                conditions.push(format!("r.videos_id IN ({})", id_list(&videos)));
                video_join = format!("AND v.id IN ({})", id_list(&videos));
                if !episodes.is_empty() && !video_only {
                    conditions.push(format!("r.tv_episodes_id IN ({})", id_list(&episodes)));
                    episode_join = format!("AND tve.id IN ({})", id_list(&episodes));
                    needs_episode = true;
                }
            }
        }

        let from_external = !found.is_empty();
        if from_external {
            found = self
                .intersect(
                    &found,
                    &filters.categories,
                    CategoryKind::Tv,
                    filters,
                    &mut degraded,
                )
                .await?;
            if found.is_empty() {
                return Ok(Found {
                    rows: Vec::new(),
                    degraded,
                });
            }
            conditions.push(format!("r.id IN ({})", id_list(&found)));
            let predicates = episode_predicates(series, episode, air_date);
            if !predicates.is_empty() {
                conditions.extend(predicates);
                needs_episode = true;
            }
        }

        if !from_external && !strict && !php_empty(name) {
            let has_site = ids.columns().iter().any(|(_, value)| php_positive(value));
            let mut text = name.to_owned();
            if !has_site {
                if numeric_season(series) {
                    text.push_str(&format!(" S{}", pad2(series)));
                    if !php_empty(episode) && !episode.contains('/') {
                        text.push_str(&format!("E{}", pad2(episode)));
                    }
                } else if !php_empty(air_date) {
                    text.push(' ');
                    text.push_str(&air_date.replace(['/', '-', '.', '_'], " "));
                }
            }
            let phrases = Phrases::Text(text);
            let hits = Self::soften(
                self.client
                    .search_release_ids(&phrases, self.prefilter_limit())
                    .await,
                &mut degraded,
            );
            if hits.is_empty() {
                return Ok(Found {
                    rows: Vec::new(),
                    degraded,
                });
            }
            found = self
                .intersect(
                    &hits,
                    &filters.categories,
                    CategoryKind::Tv,
                    filters,
                    &mut degraded,
                )
                .await?;
            if found.is_empty() {
                return Ok(Found {
                    rows: Vec::new(),
                    degraded,
                });
            }
            conditions.push(format!("r.id IN ({})", id_list(&found)));
            if !has_site && (!php_empty(series) || !php_empty(air_date)) {
                name_predicates = episode_predicates(series, episode, air_date);
                if !name_predicates.is_empty() {
                    conditions.extend(name_predicates.iter().cloned());
                    needs_episode = true;
                }
            }
        }

        if needs_lookup || found.is_empty() {
            let categories = self
                .category_sql(&filters.categories, CategoryKind::Tv)
                .await?;
            let categories = categories
                .trim()
                .trim_start_matches("AND ")
                .trim()
                .to_owned();
            if !categories.is_empty() && categories != "1=1" {
                conditions.push(categories);
            }
            if filters.max_age > 0 {
                conditions.push(format!(
                    "r.postdate > (NOW() - INTERVAL {} DAY)",
                    filters.max_age
                ));
            }
            if filters.min_size > 0 {
                conditions.push(format!("r.size >= {}", filters.min_size));
            }
            if !filters.excluded.is_empty() {
                conditions.push(format!(
                    "r.categories_id NOT IN ({})",
                    id_list(&filters.excluded)
                ));
            }
        }

        let mut where_sql = format!("WHERE {}", conditions.join(" AND "));
        let mut rows = self
            .rows(tv_sql(
                &where_sql,
                needs_episode,
                &video_join,
                &episode_join,
                filters,
                filters.limit,
                filters.offset,
            ))
            .await?;
        if rows.is_empty() && !name_predicates.is_empty() {
            let retry = filters.offset == 0
                || self
                    .rows(tv_sql(
                        &where_sql,
                        true,
                        &video_join,
                        &episode_join,
                        filters,
                        1,
                        0,
                    ))
                    .await?
                    .is_empty();
            if retry {
                conditions.retain(|condition| !name_predicates.contains(condition));
                needs_episode = false;
                where_sql = format!("WHERE {}", conditions.join(" AND "));
                rows = self
                    .rows(tv_sql(
                        &where_sql,
                        false,
                        &video_join,
                        &episode_join,
                        filters,
                        filters.limit,
                        filters.offset,
                    ))
                    .await?;
            }
        }
        if !rows.is_empty() {
            let video = if video_join.is_empty() {
                ""
            } else {
                "LEFT JOIN videos v ON r.videos_id = v.id AND v.type = 0"
            };
            let episodes = if needs_episode {
                format!("INNER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id {episode_join}")
            } else {
                String::new()
            };
            let total = self
                .count(format!(
                    "SELECT COUNT(*) FROM releases r {video} {episodes} {where_sql}"
                ))
                .await?;
            rows[0].set("_totalrows", J::Int(total));
        }
        Ok(Found { rows, degraded })
    }

    /// `apiTvSearch`, the v2 function.
    pub async fn api_tv(
        &self,
        ids: &TvIds,
        series: &str,
        episode: &str,
        air_date: &str,
        name: &str,
        filters: &Filters,
    ) -> Result<Found> {
        let strict = ids.strict();
        let mut degraded = false;
        let external = ids.external();
        let mut found = Vec::new();
        if !external.is_empty() {
            found = Self::soften(
                self.client
                    .search_by_external_id(&external, self.prefilter_limit())
                    .await,
                &mut degraded,
            );
        }

        let targets_episode = !air_date.is_empty()
            || (!series.is_empty() && php_int(series) < 1900)
            || (!episode.is_empty() && !episode.contains('/'));
        let site = ids.site_sql();
        let mut show_sql = String::new();
        if !site.is_empty() && (found.is_empty() || targets_episode) {
            let strict_episode = !found.is_empty() && targets_episode;
            match self
                .show_fragment(&site, series, episode, air_date, strict_episode)
                .await?
            {
                Some(fragment) => show_sql = fragment,
                None => {
                    return Ok(Found {
                        rows: Vec::new(),
                        degraded,
                    });
                }
            }
        }

        let mut text = name.to_owned();
        if !strict && !php_empty(name) && show_sql.is_empty() && found.is_empty() {
            if numeric_season(series) {
                text.push_str(&format!(" S{}", pad2(series)));
                if !php_empty(episode) && !episode.contains('/') {
                    text.push_str(&format!("E{}", pad2(episode)));
                }
                if php_empty(episode) {
                    text.push('*');
                }
            } else if !php_empty(air_date) {
                text.push(' ');
                text.push_str(&air_date.replace(['/', '-', '.', '_'], " "));
            }
        }
        if !strict && found.is_empty() && !php_empty(name) {
            let phrases = Phrases::Text(text);
            found = Self::soften(
                self.client
                    .search_release_ids(&phrases, self.prefilter_limit())
                    .await,
                &mut degraded,
            );
            if found.is_empty() {
                return Ok(Found {
                    rows: Vec::new(),
                    degraded,
                });
            }
        }

        let mut skip_filters = false;
        if !found.is_empty() {
            found = self
                .intersect(
                    &found,
                    &filters.categories,
                    CategoryKind::Tv,
                    filters,
                    &mut degraded,
                )
                .await?;
            skip_filters = true;
            if found.is_empty() {
                return Ok(Found {
                    rows: Vec::new(),
                    degraded,
                });
            }
        }

        let mut where_sql = format!("WHERE r.passwordstatus {} {show_sql}", self.passwords);
        if !found.is_empty() {
            where_sql.push_str(&format!(" AND r.id IN ({})", id_list(&found)));
        }
        if !skip_filters {
            where_sql.push_str(
                &self
                    .category_sql(&filters.categories, CategoryKind::Tv)
                    .await?,
            );
            where_sql.push_str(&Self::age_sql(filters.max_age));
            where_sql.push_str(&Self::size_sql(filters.min_size));
            where_sql.push_str(&Self::excluded_sql(&filters.excluded));
        }
        let from = format!(
            "FROM releases r
             LEFT OUTER JOIN videos v ON r.videos_id = v.id AND v.type = 0
             LEFT OUTER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
             LEFT JOIN categories c ON c.id = r.categories_id
             LEFT JOIN root_categories cp ON cp.id = c.root_categories_id
             LEFT JOIN usenet_groups g ON g.id = r.groups_id
             {where_sql}"
        );
        let (field, direction) = sorted(filters);
        let (limit, offset) = (filters.limit, filters.offset);
        let mut rows = self
            .rows(format!(
                "SELECT r.searchname, r.guid, r.postdate, r.categories_id, r.size, r.totalpart, r.fromname,
                    r.passwordstatus, r.grabs, r.comments, r.adddate, r.tv_episodes_id,
                    v.title, v.tvdb, v.trakt, v.imdb, v.tmdb, v.tvmaze, v.tvrage,
                    tve.series, tve.episode, tve.firstaired, cp.title AS parent_category, c.title AS sub_category,
                    CONCAT(cp.title, ' > ', c.title) AS category_name, g.name AS group_name
                 {from}
                 ORDER BY r.{field} {direction} LIMIT {limit} OFFSET {offset}"
            ))
            .await?;
        if !rows.is_empty() {
            let total = self.count(format!("SELECT COUNT(*) {from}")).await?;
            rows[0].set("_totalrows", J::Int(total.min(MAX_PAGER_RESULTS)));
        }
        Ok(Found { rows, degraded })
    }

    /// `lookupApiTvShowSqlFragment`. PHP spliced the raw season into the SQL; this uses its number.
    async fn show_fragment(
        &self,
        site: &[String],
        series: &str,
        episode: &str,
        air_date: &str,
        strict_episode: bool,
    ) -> Result<Option<String>> {
        let series_filter = if series.is_empty() {
            String::new()
        } else {
            format!("AND tve.series = {}", season_number(series, 's'))
        };
        let episode_filter = if episode.is_empty() {
            String::new()
        } else {
            format!("AND tve.episode = {}", season_number(episode, 'e'))
        };
        let air_filter = if air_date.is_empty() {
            String::new()
        } else {
            format!("AND DATE(tve.firstaired) = {}", quote(air_date))
        };
        let show: Option<(u32, Option<String>)> = sqlx::query_as(AssertSqlSafe(format!(
            "SELECT v.id, GROUP_CONCAT(tve.id SEPARATOR ',') FROM videos v
             LEFT JOIN tv_episodes tve ON v.id = tve.videos_id
             WHERE ({}) {series_filter} {episode_filter} {air_filter} GROUP BY v.id LIMIT 1",
            site.join(" OR "),
        )))
        .fetch_optional(&self.ctx.db)
        .await?;
        let Some((video, episodes)) = show else {
            return Ok(None);
        };
        let targets_episode = !air_date.is_empty()
            || (!series.is_empty() && php_int(series) < 1900)
            || (!episode.is_empty() && !episode.contains('/'));
        let episodes = episodes.unwrap_or_default();
        if strict_episode && targets_episode && episodes.is_empty() {
            return Ok(None);
        }
        let season = season_number(series, 's');
        let mut sql = String::new();
        // `r.tv_episodes_id IN ()` isn't valid, and the PHP's error there answered 500.
        if !episodes.is_empty() {
            if !php_empty(episode) && !php_empty(series) {
                sql.push_str(&format!(
                    " AND r.tv_episodes_id IN ({episodes}) AND tve.series = {season}"
                ));
            } else if !php_empty(episode) {
                sql.push_str(&format!("AND r.tv_episodes_id IN ({episodes})"));
            } else if !php_empty(series) {
                sql.push_str(&format!(
                    " AND r.tv_episodes_id IN ({episodes}) AND tve.series = {season}"
                ));
            }
        }
        if video > 0 {
            sql.push_str(&format!(" AND r.videos_id = {video}"));
        }
        Ok(Some(sql))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn seasons_read_both_forms() {
        assert_eq!(season_number("S02", 's'), 2);
        assert_eq!(season_number("s00", 's'), 0);
        assert_eq!(season_number("2", 's'), 2);
        assert_eq!(season_number("E12", 'e'), 12);
    }

    #[test]
    fn quoting_escapes_like_pdo() {
        assert_eq!(quote("a'b\\c"), "'a\\'b\\\\c'");
    }

    #[test]
    fn positive_compares_like_php() {
        assert!(php_positive("5"));
        assert!(!php_positive("0"));
        assert!(!php_positive("-1"));
        assert!(php_positive("abc"));
        assert!(!php_positive(""));
    }
}
