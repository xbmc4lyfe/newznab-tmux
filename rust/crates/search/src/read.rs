//! The read API that `api`, `site` and the workers call, from `ManticoreSearchDriver`.

use std::collections::HashSet;
use std::sync::LazyLock;
use std::time::Instant;

use regex::Regex;
use serde::{Deserialize, Serialize};
use serde_json::{Map, Value, json};
use tracing::{debug, warn};

use crate::cache;
use crate::document::ReleaseDocument;
use crate::query::{
    build_field_search_expression, escape_literal, escape_string, extract_suggestion, fuzzy_clean,
    has_negation, levenshtein, php_empty, php_trim, prepare_user_search_query,
    scope_release_search_query, scope_to_field, title_tokens,
};
use crate::registry::{
    PROFILE_FUZZY_DISTANCE, RANKER, SecondaryIndex, autocomplete_fields, field_weights,
};
use crate::release_query::{Phrases, ReleaseSearchQuery, SearchPage};
use crate::{SearchClient, SearchError};

/// PHP's default when a search names no limit.
const DEFAULT_LIMIT: u64 = 1000;

/// Ids and stored fields from one index, in Manticore's order.
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct IndexHits {
    pub id: Vec<i64>,
    pub data: Vec<Map<String, Value>>,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct Suggestion {
    pub suggest: String,
    pub distance: u64,
    pub docs: u64,
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct FuzzyIds {
    pub ids: Vec<i64>,
    pub fuzzy: bool,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct PredbHit {
    pub id: i64,
    pub title: String,
    pub filename: String,
    pub source: String,
}

#[derive(Clone, Debug, Default, PartialEq)]
pub struct MoviesByFields {
    pub imdbids: Vec<String>,
    pub movieinfo_ids: Vec<i64>,
    pub data: Vec<Map<String, Value>>,
}

/// An id a release carries from a movie or TV provider.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum ExternalId {
    ImdbId(String),
    TmdbId(i64),
    TraktId(i64),
    Tvdb(i64),
    Tvmaze(i64),
    Tvrage(i64),
}

impl ExternalId {
    /// The filter, or None for the empty values PHP skipped: 0, "" and "0".
    fn filter(&self) -> Option<(&'static str, Value)> {
        let number = |field, value: i64| (value != 0).then(|| (field, json!(value)));
        match self {
            Self::ImdbId(value) => (!php_empty(value)).then(|| ("imdbid", json!(value))),
            Self::TmdbId(value) => number("tmdbid", *value),
            Self::TraktId(value) => number("traktid", *value),
            Self::Tvdb(value) => number("tvdb", *value),
            Self::Tvmaze(value) => number("tvmaze", *value),
            Self::Tvrage(value) => number("tvrage", *value),
        }
    }
}

#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize)]
pub struct MovieExternalIds {
    pub imdbid: Option<String>,
    pub tmdbid: Option<i64>,
    pub traktid: Option<i64>,
}

/// A page of release candidates in ascending id order.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ReleaseCandidates {
    pub ids: Vec<i64>,
    pub has_more: bool,
}

/// What `search_indexes` looks for: text in some columns, or per-field terms.
enum IndexQuery<'a> {
    Text {
        text: &'a str,
        columns: &'a [&'a str],
    },
    Fields(&'a [(String, String)]),
}

struct ParsedHits {
    ids: Vec<i64>,
    sources: Vec<Map<String, Value>>,
    total: u64,
    total_exact: bool,
}

/// Manticore sends ids as numbers, but as strings above the range JSON numbers cover safely.
pub(crate) fn parse_id(id: &Value) -> Result<i64, SearchError> {
    id.as_i64()
        .or_else(|| id.as_str().and_then(|text| text.parse().ok()))
        .ok_or_else(|| SearchError::Malformed(format!("bad document id {id}")))
}

fn parse_hits(response: &Value) -> Result<ParsedHits, SearchError> {
    let hits = response
        .pointer("/hits/hits")
        .and_then(Value::as_array)
        .ok_or_else(|| SearchError::Malformed("the response has no hits".to_owned()))?;
    let mut ids = Vec::with_capacity(hits.len());
    let mut sources = Vec::with_capacity(hits.len());
    for hit in hits {
        ids.push(parse_id(hit.get("_id").unwrap_or(&Value::Null))?);
        sources.push(
            hit.get("_source")
                .and_then(Value::as_object)
                .cloned()
                .unwrap_or_default(),
        );
    }
    let total = response
        .pointer("/hits/total")
        .and_then(Value::as_u64)
        .unwrap_or(0);
    let warned = response
        .get("warning")
        .is_some_and(|warning| !warning.is_null() && warning.as_str() != Some(""));
    let total_exact = response.get("timed_out") == Some(&Value::Bool(false))
        && !warned
        && response
            .pointer("/hits/total_relation")
            .and_then(Value::as_str)
            == Some("eq");
    Ok(ParsedHits {
        ids,
        sources,
        total,
        total_exact,
    })
}

impl SearchClient {
    fn normalize_limit(&self, limit: u64) -> u64 {
        limit.min(self.config.max_matches.max(1)).max(1)
    }

    /// A filtered, sorted page of releases, falling back to a fuzzy search when an exact one
    /// finds nothing. An empty page means nothing matched.
    pub async fn search_releases(
        &self,
        query: &ReleaseSearchQuery,
    ) -> Result<SearchPage, SearchError> {
        self.require_available().await?;
        let started = Instant::now();
        let has_text = query.phrases.as_ref().is_some_and(Phrases::has_text);
        let mut page = if has_text && query.fuzzy_only {
            self.release_attempt(query, !query.has_poster_field())
                .await?
        } else if has_text {
            let first = self.release_attempt(query, false).await?;
            let negated = query.phrases.as_ref().is_some_and(Phrases::has_negation);
            // Matches past the end of the page aren't a reason to go looking for typos.
            if !first.ids.is_empty()
                || first.total > 0
                || !query.fuzzy_allowed()
                || !self.config.fuzzy_enabled
                || negated
            {
                first
            } else {
                self.release_attempt(query, true).await?
            }
        } else {
            self.release_attempt(query, false).await?
        };
        page.duration_ms = started.elapsed().as_secs_f64() * 1000.0;
        Ok(page)
    }

    async fn release_attempt(
        &self,
        query: &ReleaseSearchQuery,
        use_fuzzy: bool,
    ) -> Result<SearchPage, SearchError> {
        let now = chrono::Utc::now().timestamp();
        let Some(body) = query.request_body(&self.config, &self.config.indexes, use_fuzzy, now)
        else {
            return Ok(SearchPage {
                total_exact: true,
                ..SearchPage::default()
            });
        };
        let hits = parse_hits(&self.search(&body).await?)?;
        let documents = if query.include_documents {
            hits.ids
                .iter()
                .zip(&hits.sources)
                .map(|(id, source)| {
                    let mut document: ReleaseDocument =
                        serde_json::from_value(Value::Object(source.clone()))
                            .map_err(|error| SearchError::Malformed(error.to_string()))?;
                    document.id = *id;
                    Ok(document)
                })
                .collect::<Result<Vec<_>, SearchError>>()?
        } else {
            Vec::new()
        };
        let offset = query.effective_offset();
        let max_matches = self.config.max_matches;
        if hits.ids.is_empty() && offset < hits.total && offset >= max_matches {
            return Err(SearchError::BeyondWindow {
                offset,
                max_matches,
            });
        }
        let next = offset + hits.ids.len() as u64;
        let has_more = !hits.ids.is_empty() && next < hits.total.min(max_matches);
        Ok(SearchPage {
            ids: hits.ids,
            total: hits.total,
            total_exact: hits.total_exact,
            fuzzy: use_fuzzy,
            duration_ms: 0.0,
            has_more,
            documents,
        })
    }

    /// Release ids for text, newest first, with no filters. `Fields` naming `name` and
    /// `searchname` with the same text matches either one, for the PreDB title lookup.
    pub async fn search_release_ids(
        &self,
        phrases: &Phrases,
        limit: u64,
    ) -> Result<Vec<i64>, SearchError> {
        let fields = phrases.to_fields();
        let table = self.config.indexes.table("releases").to_owned();
        let query = match shared_name_text(&fields) {
            Some(text) => IndexQuery::Text {
                text,
                columns: &["name", "searchname"],
            },
            None => IndexQuery::Fields(&fields),
        };
        Ok(self.search_indexes(&table, query, limit).await?.id)
    }

    /// `search_release_ids`, then a fuzzy search if that found nothing.
    pub async fn search_release_ids_with_fuzzy(
        &self,
        phrases: &Phrases,
        limit: u64,
        force_fuzzy: bool,
    ) -> Result<FuzzyIds, SearchError> {
        if !force_fuzzy {
            let ids = self.search_release_ids(phrases, limit).await?;
            if !ids.is_empty() {
                return Ok(FuzzyIds { ids, fuzzy: false });
            }
        }
        if phrases.has_negation() || !self.config.fuzzy_enabled {
            return Ok(FuzzyIds::default());
        }
        let ids = self.fuzzy_search_release_ids(phrases, limit).await?;
        let fuzzy = !ids.is_empty();
        Ok(FuzzyIds { ids, fuzzy })
    }

    pub async fn fuzzy_search_release_ids(
        &self,
        phrases: &Phrases,
        limit: u64,
    ) -> Result<Vec<i64>, SearchError> {
        if !self.config.fuzzy_enabled {
            return Ok(Vec::new());
        }
        let table = self.config.indexes.table("releases").to_owned();
        Ok(self
            .fuzzy_search_indexes(&table, &phrases.to_fields(), limit)
            .await?
            .id)
    }

    async fn fuzzy_search_indexes(
        &self,
        table: &str,
        fields: &[(String, String)],
        limit: u64,
    ) -> Result<IndexHits, SearchError> {
        if table.is_empty() || fields.is_empty() {
            return Ok(IndexHits::default());
        }
        let limit = self.normalize_limit(limit);
        let logical = self.config.indexes.logical(table).unwrap_or_default();
        let distance = self.config.fuzzy_max_distance.min(PROFILE_FUZZY_DISTANCE);
        let key = cache::key(
            "fuzzy",
            &(
                &self.config.index_generation,
                table,
                fields,
                limit,
                distance,
            ),
        );
        if let Some(cached) = self.cache.get(&key).await {
            return Ok(cached);
        }

        let terms: Vec<String> = fields
            .iter()
            .filter(|(_, value)| !php_empty(value))
            .map(|(_, value)| fuzzy_clean(value))
            .filter(|clean| !php_empty(clean))
            .collect();
        if terms.is_empty() {
            return Ok(IndexHits::default());
        }

        // Fuzzy only works on a plain query string, with few options around it.
        let body = json!({
            "table": table,
            "query": {"bool": {"must": [{"query_string": terms.join(" ")}]}},
            "options": {
                "fuzzy": true,
                "distance": distance,
                "field_weights": field_weights(logical),
            },
            "limit": limit,
        });
        let response = match self.search(&body).await {
            Err(SearchError::Query(message)) if message.contains("min_infix_len") => {
                warn!(
                    table,
                    "fuzzy search needs min_infix_len; recreate the index"
                );
                return self
                    .search_indexes(table, IndexQuery::Fields(fields), limit)
                    .await;
            }
            other => other?,
        };
        let hits = parse_hits(&response)?;
        let result = IndexHits {
            id: hits.ids,
            data: hits.sources,
        };
        if !result.id.is_empty() {
            self.cache
                .put(&key, &result, self.config.cache_minutes)
                .await;
        }
        Ok(result)
    }

    async fn search_indexes(
        &self,
        table: &str,
        query: IndexQuery<'_>,
        limit: u64,
    ) -> Result<IndexHits, SearchError> {
        if table.is_empty() {
            return Ok(IndexHits::default());
        }
        let limit = self.normalize_limit(limit);
        let logical = self.config.indexes.logical(table).unwrap_or_default();
        let expression = match query {
            IndexQuery::Fields(fields) if !fields.is_empty() => {
                build_field_search_expression(fields, None)
            }
            IndexQuery::Fields(_) => None,
            IndexQuery::Text { text, columns } => {
                let prepared = prepare_user_search_query(text);
                (!php_empty(text) && !php_empty(&prepared)).then(|| {
                    let selector = match columns {
                        [] => String::new(),
                        [one] => format!("@{one}"),
                        many => format!("@({})", many.join(",")),
                    };
                    format!(
                        "@@relaxed {}",
                        scope_release_search_query(text, &prepared, &selector)
                    )
                })
            }
        };
        let Some(expression) = expression else {
            return Ok(IndexHits::default());
        };
        let key = cache::key(
            "index",
            &(&self.config.index_generation, table, &expression, limit),
        );
        if let Some(cached) = self.cache.get(&key).await {
            return Ok(cached);
        }

        let mut body = json!({
            "table": table,
            "options": {"ranker": RANKER, "field_weights": field_weights(logical)},
            "max_matches": limit,
            "limit": limit,
            "strip_bad_utf8": true,
            "query": {"bool": {"must": [{"query_string": expression}]}},
        });
        let unsorted = body.clone();
        // Sorting predb_rt hit Manticore's "too many sort-by attributes" error.
        if table != "predb_rt" {
            body["sort"] = json!([{"id": "desc"}]);
        }
        let response = match self.search(&body).await {
            Err(SearchError::Query(message))
                if message
                    .to_lowercase()
                    .contains("too many sort-by attributes") =>
            {
                warn!(table, "retrying a search without sorting");
                self.search(&unsorted).await?
            }
            other => other?,
        };
        let hits = parse_hits(&response)?;
        let result = IndexHits {
            id: hits.ids,
            data: hits.sources,
        };
        if !result.id.is_empty() {
            self.cache
                .put(&key, &result, self.config.cache_minutes)
                .await;
        }
        Ok(result)
    }

    /// Releases carrying any of these provider ids, deduplicated, in the order they were asked.
    pub async fn search_by_external_id(
        &self,
        external_ids: &[ExternalId],
        limit: u64,
    ) -> Result<Vec<i64>, SearchError> {
        let limit = limit.min(10_000);
        let mut seen_filters = HashSet::new();
        let filters: Vec<(&'static str, Value)> = external_ids
            .iter()
            .filter_map(ExternalId::filter)
            .filter(|(field, value)| seen_filters.insert(format!("{field}:{value}")))
            .collect();
        if filters.is_empty() {
            return Ok(Vec::new());
        }
        let key = cache::key(
            "external",
            &(&self.config.index_generation, &filters, limit),
        );
        if let Some(cached) = self.cache.get(&key).await {
            return Ok(cached);
        }
        let table = self.config.indexes.table("releases");
        let mut seen = HashSet::new();
        let mut ids = Vec::new();
        'filters: for (field, value) in &filters {
            let body = json!({
                "table": table,
                "limit": limit,
                "query": {"bool": {"must": [{"equals": {*field: value}}]}},
            });
            for id in parse_hits(&self.search(&body).await?)?.ids {
                if seen.insert(id) {
                    ids.push(id);
                    if ids.len() as u64 >= limit {
                        break 'filters;
                    }
                }
            }
        }
        if !ids.is_empty() {
            self.cache.put(&key, &ids, self.config.cache_minutes).await;
        }
        Ok(ids)
    }

    /// Full-text search on a metadata index.
    pub async fn search_secondary(
        &self,
        index: SecondaryIndex,
        query: &str,
        limit: u64,
    ) -> Result<IndexHits, SearchError> {
        let query = php_trim(query);
        if query.is_empty() {
            return Ok(IndexHits::default());
        }
        let table = self.config.indexes.table(index.logical()).to_owned();
        self.search_indexes(
            &table,
            IndexQuery::Text {
                text: query,
                columns: index.fulltext_fields(),
            },
            limit,
        )
        .await
    }

    /// The distinct anidb ids of matching anime titles.
    pub async fn search_anime_title(
        &self,
        query: &str,
        limit: u64,
    ) -> Result<Vec<i64>, SearchError> {
        let hits = self
            .search_secondary(SecondaryIndex::Anime, query, limit)
            .await?;
        let mut seen = HashSet::new();
        Ok(hits
            .data
            .iter()
            .filter_map(|row| row.get("anidbid").and_then(Value::as_i64))
            .filter(|anidbid| *anidbid > 0 && seen.insert(*anidbid))
            .collect())
    }

    /// Whole release names that start the way the user is typing. `index` is a table name.
    pub async fn autocomplete(
        &self,
        query: &str,
        index: Option<&str>,
    ) -> Result<Vec<Suggestion>, SearchError> {
        let query = php_trim(query);
        if !self.config.autocomplete_enabled || query.len() < self.config.autocomplete_min_length {
            return Ok(Vec::new());
        }
        let table = index.unwrap_or_else(|| self.config.indexes.table("releases"));
        let logical = self.config.indexes.logical(table).unwrap_or_default();
        let fields = autocomplete_fields(logical);
        if fields.is_empty() {
            return Ok(Vec::new());
        }
        let key = cache::key(
            "autocomplete",
            &(&self.config.index_generation, table, query),
        );
        if let Some(cached) = self.cache.get(&key).await {
            return Ok(cached);
        }
        let escaped = escape_string(query);
        if php_empty(&escaped) {
            return Ok(Vec::new());
        }
        let body = json!({
            "table": table,
            "query": {"bool": {"must": [{
                "query_string": format!("@@relaxed @({}) {escaped}*", fields.join(","))
            }]}},
            "sort": [{"id": "desc"}],
            "limit": self.config.autocomplete_max_results * 3,
            "strip_bad_utf8": true,
        });
        let hits = parse_hits(&self.search(&body).await?)?;

        struct Ranked {
            normalized: String,
            suggest: String,
            docs: u64,
            prefix: bool,
        }
        let lowered_query = query.to_lowercase();
        let mut ranked: Vec<Ranked> = Vec::new();
        for source in &hits.sources {
            let Some(name) = first_field(source, fields) else {
                continue;
            };
            let Some(suggestion) = extract_suggestion(name, query).filter(|s| !s.is_empty()) else {
                continue;
            };
            let spaced = suggestion
                .split_ascii_whitespace()
                .collect::<Vec<_>>()
                .join(" ");
            let normalized = php_trim(&spaced).to_lowercase();
            if normalized.is_empty() {
                continue;
            }
            match ranked.iter_mut().find(|item| item.normalized == normalized) {
                Some(item) => item.docs += 1,
                None => ranked.push(Ranked {
                    prefix: normalized.starts_with(&lowered_query),
                    normalized,
                    suggest: suggestion,
                    docs: 1,
                }),
            }
        }
        ranked.sort_by_key(|item| std::cmp::Reverse((item.prefix, item.docs)));
        let suggestions: Vec<Suggestion> = ranked
            .into_iter()
            .take(usize::try_from(self.config.autocomplete_max_results).unwrap_or(usize::MAX))
            .map(|item| Suggestion {
                suggest: item.suggest,
                distance: 0,
                docs: item.docs,
            })
            .collect();
        if !suggestions.is_empty() {
            self.cache
                .put(&key, &suggestions, self.config.autocomplete_cache_minutes)
                .await;
        }
        Ok(suggestions)
    }

    /// "Did you mean": words close to the query from documents that contain it.
    ///
    /// PHP also called `CALL SUGGEST`, but its client keyed those rows by the suggestion and
    /// the driver then looked for a `suggest` field that was gone, so it always dropped them.
    /// This does what production did. `tests/fixtures/golden.json` records that behaviour.
    pub async fn suggest(
        &self,
        query: &str,
        index: Option<&str>,
    ) -> Result<Vec<Suggestion>, SearchError> {
        let query = php_trim(query);
        if !self.config.suggest_enabled || php_empty(query) || has_negation(query) {
            return Ok(Vec::new());
        }
        let table = index.unwrap_or_else(|| self.config.indexes.table("releases"));
        let key = cache::key("suggest", &(&self.config.index_generation, table, query));
        if let Some(cached) = self.cache.get(&key).await {
            return Ok(cached);
        }
        let escaped = escape_string(query);
        let logical = self.config.indexes.logical(table).unwrap_or_default();
        let fields = autocomplete_fields(logical);
        if php_empty(&escaped) || fields.is_empty() {
            return Ok(Vec::new());
        }
        let body = json!({
            "table": table,
            "query": {"bool": {"must": [{
                "query_string": format!("@@relaxed @({}) {escaped}", fields.join(","))
            }]}},
            "limit": 20,
            "strip_bad_utf8": true,
        });
        let hits = parse_hits(&self.search(&body).await?)?;
        let lowered_query = query.to_ascii_lowercase();
        let mut terms: Vec<Suggestion> = Vec::new();
        for source in &hits.sources {
            let name = first_field(source, fields)
                .unwrap_or_default()
                .to_ascii_lowercase();
            let separators = |c: char| {
                matches!(
                    c,
                    ' ' | '\t' | '\n' | '\x0B' | '\x0C' | '\r' | '.' | '-' | '_'
                )
            };
            for word in name.split(separators) {
                if word.len() < 3 || word == lowered_query {
                    continue;
                }
                let distance = levenshtein(&lowered_query, word);
                if !(1..=3).contains(&distance) {
                    continue;
                }
                match terms.iter_mut().find(|term| term.suggest == word) {
                    Some(term) => term.docs += 1,
                    None => terms.push(Suggestion {
                        suggest: word.to_owned(),
                        distance: distance as u64,
                        docs: 1,
                    }),
                }
            }
        }
        terms.sort_by_key(|term| std::cmp::Reverse(term.docs));
        terms.truncate(5);
        if !terms.is_empty() {
            self.cache
                .put(&key, &terms, self.config.cache_minutes)
                .await;
        }
        Ok(terms)
    }

    pub async fn search_predb(&self, term: &str) -> Result<Vec<PredbHit>, SearchError> {
        let table = self.config.indexes.table("predb").to_owned();
        let hits = self
            .search_indexes(
                &table,
                IndexQuery::Text {
                    text: term,
                    columns: &["title", "filename"],
                },
                DEFAULT_LIMIT,
            )
            .await?;
        Ok(hits
            .id
            .iter()
            .zip(&hits.data)
            .filter(|(id, _)| **id > 0)
            .map(|(id, row)| predb_hit(*id, row))
            .collect())
    }

    pub async fn search_tv_shows(&self, term: &str) -> Result<IndexHits, SearchError> {
        let table = self.config.indexes.table("tvshows").to_owned();
        self.search_indexes(
            &table,
            IndexQuery::Text {
                text: term,
                columns: &["title"],
            },
            DEFAULT_LIMIT,
        )
        .await
    }

    pub async fn search_movies(&self, term: &str) -> Result<IndexHits, SearchError> {
        let table = self.config.indexes.table("movies").to_owned();
        self.search_indexes(
            &table,
            IndexQuery::Text {
                text: term,
                columns: &["title", "actors", "director"],
            },
            DEFAULT_LIMIT,
        )
        .await
    }

    /// Movies by `title`, `director`, `actors` and `genre`. Other fields are ignored.
    pub async fn search_movies_by_fields(
        &self,
        terms: &[(String, String)],
        limit: u64,
    ) -> Result<MoviesByFields, SearchError> {
        let fields: Vec<(String, String)> = terms
            .iter()
            .filter(|(field, _)| {
                matches!(field.as_str(), "title" | "director" | "actors" | "genre")
            })
            .map(|(field, value)| (field.clone(), php_trim(value).to_owned()))
            .filter(|(_, value)| !value.is_empty())
            .collect();
        if fields.is_empty() {
            return Ok(MoviesByFields::default());
        }
        self.require_available().await?;
        let table = self.config.indexes.table("movies").to_owned();
        let hits = self
            .search_indexes(&table, IndexQuery::Fields(&fields), limit)
            .await?;
        let mut seen = HashSet::new();
        let imdbids = hits
            .data
            .iter()
            .filter_map(|row| row.get("imdbid").and_then(Value::as_str))
            .filter(|imdbid| !imdbid.is_empty() && seen.insert(imdbid.to_string()))
            .map(str::to_owned)
            .collect();
        Ok(MoviesByFields {
            imdbids,
            movieinfo_ids: hits.id,
            data: hits.data,
        })
    }

    /// The first movie matching any id, tried as imdb, then tmdb, then trakt.
    pub async fn search_movie_by_external_ids(
        &self,
        ids: &MovieExternalIds,
    ) -> Result<Option<Map<String, Value>>, SearchError> {
        let mut filters: Vec<(&str, Value)> = Vec::new();
        if let Some(imdbid) = ids.imdbid.as_deref().filter(|id| !php_empty(id)) {
            filters.push(("imdbid", json!(imdbid)));
        }
        if let Some(tmdbid) = ids.tmdbid.filter(|id| *id != 0) {
            filters.push(("tmdbid", json!(tmdbid)));
        }
        if let Some(traktid) = ids.traktid.filter(|id| *id != 0) {
            filters.push(("traktid", json!(traktid)));
        }
        if filters.is_empty() {
            return Ok(None);
        }
        let key = cache::key("movie", &(&self.config.index_generation, &filters));
        if let Some(cached) = self.cache.get(&key).await {
            return Ok(Some(cached));
        }
        let table = self.config.indexes.table("movies");
        for (field, value) in &filters {
            let body = json!({
                "table": table,
                "limit": 1,
                "query": {"bool": {"must": [{"equals": {*field: value}}]}},
            });
            let hits = parse_hits(&self.search(&body).await?)?;
            if let (Some(id), Some(source)) = (hits.ids.first(), hits.sources.into_iter().next()) {
                let mut movie = source;
                movie.insert("id".to_owned(), json!(id));
                self.cache
                    .put(&key, &movie, self.config.cache_minutes)
                    .await;
                return Ok(Some(movie));
            }
        }
        debug!(?ids, "no movie for these ids");
        Ok(None)
    }

    /// The PreDB row whose title or filename is exactly `name`, ignoring case.
    pub async fn match_predb_exact(&self, name: &str) -> Result<Option<PredbHit>, SearchError> {
        if !name.chars().any(char::is_alphanumeric) {
            return Ok(None);
        }
        let literal = escape_literal(name);
        let wanted = name.to_lowercase();
        let table = self.config.indexes.table("predb");
        for field in ["title", "filename"] {
            // These are full-text fields, so the phrase anchors to the whole field, and the
            // comparison below decides the match.
            let body = json!({
                "table": table,
                "limit": 5,
                "query": {"bool": {"must": [{
                    "query_string": format!("@{field} \"^{literal}$\"")
                }]}},
            });
            let hits = parse_hits(&self.search(&body).await?)?;
            for (id, row) in hits.ids.iter().zip(&hits.sources) {
                let hit = predb_hit(*id, row);
                let value = if field == "title" {
                    &hit.title
                } else {
                    &hit.filename
                };
                if hit.id > 0 && !hit.title.is_empty() && value.to_lowercase() == wanted {
                    return Ok(Some(hit));
                }
            }
        }
        Ok(None)
    }

    /// PreDB rows that could own this filename, for attaching an identity. Ok(None) means the
    /// result can't be trusted to be complete: a full window, a warning, an estimated total or
    /// a row outside what the table can hold. This skips the cache, which can't attest either.
    pub async fn search_predb_filename_candidates(
        &self,
        term: &str,
    ) -> Result<Option<Vec<PredbHit>>, SearchError> {
        let prepared = prepare_user_search_query(term);
        if php_empty(&prepared) {
            return Ok(None);
        }
        let expression = format!(
            "@@relaxed {}",
            scope_release_search_query(term, &prepared, "@(title,filename)")
        );
        let body = json!({
            "table": self.config.indexes.table("predb"),
            "max_matches": 1000,
            "limit": 1000,
            "strip_bad_utf8": false,
            "query": {"bool": {"must": [{"query_string": expression}]}},
        });
        let response = self.search(&body).await?;
        let rows = response.pointer("/hits/hits").and_then(Value::as_array);
        let total = response.pointer("/hits/total").and_then(Value::as_u64);
        let warned = response
            .get("warning")
            .is_some_and(|warning| !warning.is_null() && warning.as_str() != Some(""));
        let (Some(rows), Some(total)) = (rows, total) else {
            return Ok(None);
        };
        // A full window is denied even when the total agrees: max_matches bounds what Manticore
        // nominates, so it doesn't prove there's nothing more.
        if response.get("timed_out") != Some(&Value::Bool(false))
            || warned
            || response
                .pointer("/hits/total_relation")
                .and_then(Value::as_str)
                != Some("eq")
            || total != rows.len() as u64
            || rows.len() >= 1000
        {
            return Ok(None);
        }
        Ok(rows.iter().map(filename_candidate).collect())
    }

    /// Releases that may match a title, a page at a time by ascending id. The title is data, not
    /// search syntax, and the caller confirms the literal match. A title with no letters or
    /// numbers can't be searched, which is a `RejectedQuery`, not an empty page.
    pub async fn search_release_candidates_page(
        &self,
        title: &str,
        limit: u64,
        after_id: i64,
    ) -> Result<ReleaseCandidates, SearchError> {
        if !(1..=1000).contains(&limit) || after_id < 0 {
            return Err(SearchError::InvalidArgument(
                "candidate pages take a limit from 1 to 1000 and a nonnegative cursor",
            ));
        }
        self.require_available().await?;
        let tokens = title_tokens(title);
        if tokens.is_empty() {
            return Err(SearchError::RejectedQuery(
                "the title has no letters or numbers".to_owned(),
            ));
        }
        let prepared = prepare_user_search_query(&tokens);
        let expression = format!(
            "@@relaxed ({} | {})",
            scope_to_field(&prepared, "@name"),
            scope_to_field(&prepared, "@searchname")
        );
        let body = json!({
            "table": self.config.indexes.table("releases"),
            "strip_bad_utf8": true,
            "query": {"bool": {"must": [
                {"query_string": expression},
                {"range": {"id": {"gt": after_id}}},
            ]}},
            "sort": [{"id": "asc"}],
            "options": {"ranker": RANKER, "field_weights": field_weights("releases")},
            "max_matches": limit + 1,
            "limit": limit + 1,
        });
        let response = self.search(&body).await?;
        if response.get("timed_out") == Some(&Value::Bool(true)) {
            return Err(SearchError::Malformed(
                "the release candidate response was partial".to_owned(),
            ));
        }
        let mut ids = parse_hits(&response)?.ids;
        let mut previous = after_id;
        for id in &ids {
            if *id <= previous {
                return Err(SearchError::Malformed(
                    "the release candidate response lost its cursor order".to_owned(),
                ));
            }
            previous = *id;
        }
        let window = usize::try_from(limit).unwrap_or(usize::MAX);
        if ids.len() > window + 1 {
            return Err(SearchError::Malformed(
                "the release candidate response overran its window".to_owned(),
            ));
        }
        let has_more = ids.len() > window;
        ids.truncate(window);
        Ok(ReleaseCandidates { ids, has_more })
    }
}

fn predb_hit(id: i64, row: &Map<String, Value>) -> PredbHit {
    let text = |field: &str| {
        row.get(field)
            .and_then(Value::as_str)
            .unwrap_or_default()
            .to_owned()
    };
    PredbHit {
        id,
        title: text("title"),
        filename: text("filename"),
        source: text("source"),
    }
}

/// A hit that's safe to attach as an identity, or None. The limits are the source columns'
/// sizes, not truncation targets. `U+FFFD` is refused because the HTTP layer writes it over
/// bytes that weren't UTF-8, which PHP refused directly.
fn filename_candidate(row: &Value) -> Option<PredbHit> {
    static CONTROL: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"\p{C}").expect("valid pattern"));

    let source = row.get("_source")?.as_object()?;
    let id = parse_id(row.get("_id")?).ok().filter(|id| *id > 0)?;
    let optional = |field: &str| match source.get(field) {
        None | Some(Value::Null) => Some(""),
        Some(value) => value.as_str(),
    };
    let title = source.get("title")?.as_str().filter(|t| !t.is_empty())?;
    let filename = optional("filename")?;
    let origin = optional("source")?;
    for (value, maximum) in [(title, 255), (filename, 255), (origin, 50)] {
        if value.chars().count() > maximum || value.contains('\u{FFFD}') {
            return None;
        }
    }
    if CONTROL.is_match(title) || CONTROL.is_match(filename) || title.contains(['/', '\\', '|']) {
        return None;
    }
    Some(predb_hit(id, source))
}

fn first_field<'a>(source: &'a Map<String, Value>, fields: &[&str]) -> Option<&'a str> {
    fields.iter().find_map(|field| {
        source
            .get(*field)
            .and_then(Value::as_str)
            .filter(|value| !php_empty(value))
    })
}

/// The text when a search covers exactly `name` and `searchname` with the same value.
fn shared_name_text(fields: &[(String, String)]) -> Option<&str> {
    let [(first, first_value), (second, second_value)] = fields else {
        return None;
    };
    let names =
        (first == "name" && second == "searchname") || (first == "searchname" && second == "name");
    (names && !first_value.is_empty() && first_value == second_value).then_some(first_value)
}
