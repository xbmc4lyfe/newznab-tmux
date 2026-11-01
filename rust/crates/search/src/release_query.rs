//! The filtered release query, from `ReleaseSearchQuery` and
//! `applyManticoreReleaseIndexFilters`.

use serde_json::{Value, json};

use crate::cursor::SearchCursor;
use crate::document::ReleaseDocument;
use crate::query::{
    fuzzy_clean, has_negation, php_empty, prepare_poster_query, prepare_user_search_query,
    scope_release_search_query,
};
use crate::registry::{PROFILE_FUZZY_DISTANCE, RANKER, field_weights};
use crate::{SearchConfig, config::IndexNames};

/// Fields a release query may name. Anything else is dropped.
const RELEASE_SEARCH_FIELDS: [&str; 14] = [
    "searchname",
    "plainsearchname",
    "name",
    "fromname",
    "filename",
    "media_movie_name",
    "media_file_name",
    "media_container_format",
    "media_video_format",
    "media_video_codec",
    "media_audio_format",
    "media_audio_channels",
    "media_audio_language",
    "media_subtitle_language",
];

const MEDIA_TEXT_FIELDS: [&str; 9] = [
    "media_movie_name",
    "media_file_name",
    "media_container_format",
    "media_video_format",
    "media_video_codec",
    "media_audio_format",
    "media_audio_channels",
    "media_audio_language",
    "media_subtitle_language",
];

/// What a query searches for.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Phrases {
    /// Text against `searchname`.
    Text(String),
    /// Terms joined with spaces against `searchname`.
    Terms(Vec<String>),
    /// Per-field text, ANDed in this order.
    Fields(Vec<(String, String)>),
}

impl Phrases {
    /// The field terms this searches, with `searchname` as the default field.
    pub fn to_fields(&self) -> Vec<(String, String)> {
        match self {
            Self::Text(text) => vec![("searchname".to_owned(), text.clone())],
            Self::Terms(terms) => vec![("searchname".to_owned(), terms.join(" "))],
            Self::Fields(fields) => fields.clone(),
        }
    }

    pub(crate) fn has_text(&self) -> bool {
        !matches!(self, Self::Text(text) if text.is_empty())
    }

    pub fn has_negation(&self) -> bool {
        match self {
            Self::Text(text) => has_negation(text),
            Self::Terms(terms) => terms.iter().any(|term| has_negation(term)),
            Self::Fields(fields) => fields.iter().any(|(_, value)| has_negation(value)),
        }
    }

    fn names_field(&self, field: &str) -> bool {
        matches!(self, Self::Fields(fields)
            if fields.iter().any(|(name, value)| name == field && !value.is_empty()))
    }

    fn has_poster(&self) -> bool {
        self.names_field("fromname")
    }

    fn has_media_field(&self) -> bool {
        MEDIA_TEXT_FIELDS
            .iter()
            .any(|field| self.names_field(field))
    }

    /// Fuzzy search only takes a plain string, so the fields are lost here.
    pub(crate) fn to_fuzzy_string(&self) -> String {
        let terms: Vec<String> = self
            .to_fields()
            .iter()
            .filter(|(_, value)| !php_empty(value))
            .map(|(_, value)| fuzzy_clean(value))
            .filter(|clean| !clean.is_empty())
            .collect();
        terms.join(" ")
    }
}

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum SortField {
    #[default]
    PostDate,
    AddDate,
    Size,
    TotalPart,
    Grabs,
    Comments,
    Category,
    Id,
}

impl SortField {
    fn attribute(self) -> &'static str {
        match self {
            Self::PostDate => "postdate_ts",
            Self::AddDate => "adddate_ts",
            Self::Size => "size",
            Self::TotalPart => "totalpart",
            Self::Grabs => "grabs",
            Self::Comments => "comments",
            Self::Category => "categories_id",
            Self::Id => "id",
        }
    }

    /// An unknown name sorts by post date, as the PHP did.
    pub fn parse(name: &str) -> Self {
        match name {
            "adddate_ts" => Self::AddDate,
            "size" => Self::Size,
            "totalpart" => Self::TotalPart,
            "grabs" => Self::Grabs,
            "comments" => Self::Comments,
            "categories_id" => Self::Category,
            "id" => Self::Id,
            _ => Self::PostDate,
        }
    }
}

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum SortDirection {
    Asc,
    #[default]
    Desc,
}

impl SortDirection {
    fn name(self) -> &'static str {
        match self {
            Self::Asc => "asc",
            Self::Desc => "desc",
        }
    }

    /// Anything but `asc` sorts descending.
    pub fn parse(name: &str) -> Self {
        if name.eq_ignore_ascii_case("asc") {
            Self::Asc
        } else {
            Self::Desc
        }
    }
}

/// Inclusive bounds. A negative bound is ignored.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Bounds {
    pub min: Option<i64>,
    pub max: Option<i64>,
}

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Ranges {
    pub size: Bounds,
    pub totalpart: Bounds,
    pub grabs: Bounds,
    pub comments: Bounds,
    pub adddate_ts: Bounds,
}

/// A page of releases. Zero and negative filter values mean "no filter", as in PHP.
#[derive(Clone, Debug)]
pub struct ReleaseSearchQuery {
    pub phrases: Option<Phrases>,
    pub category_ids: Vec<i64>,
    pub excluded_category_ids: Vec<i64>,
    pub release_ids: Vec<i64>,
    pub min_size: i64,
    pub max_size: i64,
    pub max_age_days: i64,
    pub min_date: i64,
    pub max_date: i64,
    pub group_id: Option<i64>,
    /// Also return passworded-archive releases (status 1). Status 2 and up never show.
    pub password_allow_rar: bool,
    pub password_status_min: Option<i64>,
    pub has_media_info: Option<bool>,
    pub media_unique_id: Option<String>,
    pub min_video_width: i64,
    pub max_video_width: i64,
    pub min_video_height: i64,
    pub max_video_height: i64,
    pub sort_field: SortField,
    pub sort_direction: SortDirection,
    pub try_fuzzy: bool,
    pub fuzzy_only: bool,
    pub limit: u64,
    pub offset: u64,
    /// Where to continue from. Its offset replaces `offset`.
    pub cursor: Option<SearchCursor>,
    pub include_documents: bool,
    pub ranges: Ranges,
    pub min_id: Option<i64>,
    pub max_id: Option<i64>,
}

impl Default for ReleaseSearchQuery {
    fn default() -> Self {
        Self {
            phrases: None,
            category_ids: Vec::new(),
            excluded_category_ids: Vec::new(),
            release_ids: Vec::new(),
            min_size: 0,
            max_size: 0,
            max_age_days: -1,
            min_date: 0,
            max_date: 0,
            group_id: None,
            password_allow_rar: false,
            password_status_min: None,
            has_media_info: None,
            media_unique_id: None,
            min_video_width: 0,
            max_video_width: 0,
            min_video_height: 0,
            max_video_height: 0,
            sort_field: SortField::default(),
            sort_direction: SortDirection::default(),
            try_fuzzy: true,
            fuzzy_only: false,
            limit: 100,
            offset: 0,
            cursor: None,
            include_documents: false,
            ranges: Ranges::default(),
            min_id: None,
            max_id: None,
        }
    }
}

/// One page of results.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct SearchPage {
    pub ids: Vec<i64>,
    pub total: u64,
    /// False when Manticore timed out, warned, or only estimated `total`.
    pub total_exact: bool,
    pub fuzzy: bool,
    pub duration_ms: f64,
    pub has_more: bool,
    /// Filled when the query asked for documents.
    pub documents: Vec<ReleaseDocument>,
}

fn range(field: &str, operator: &str, value: i64) -> Value {
    json!({"range": {field: {operator: value}}})
}

fn equals(field: &str, value: impl Into<Value>) -> Value {
    json!({"equals": {field: value.into()}})
}

fn one_or_many(field: &str, values: &[i64]) -> Option<Value> {
    match values {
        [] => None,
        [one] => Some(equals(field, *one)),
        many => Some(json!({"in": {field: many}})),
    }
}

impl ReleaseSearchQuery {
    pub fn effective_offset(&self) -> u64 {
        self.cursor
            .as_ref()
            .map_or(self.offset, |cursor| cursor.offset)
    }

    pub(crate) fn fuzzy_allowed(&self) -> bool {
        let Some(phrases) = &self.phrases else {
            return self.try_fuzzy;
        };
        self.try_fuzzy && !phrases.has_media_field() && !phrases.has_poster()
    }

    pub(crate) fn has_poster_field(&self) -> bool {
        self.phrases.as_ref().is_some_and(Phrases::has_poster)
    }

    fn filters(&self, now: i64) -> (Vec<Value>, Vec<Value>) {
        let mut must = Vec::new();
        let at_least = |must: &mut Vec<Value>, field: &str, value: i64| {
            if value > 0 {
                must.push(range(field, "gte", value));
            }
        };
        let at_most = |must: &mut Vec<Value>, field: &str, value: i64| {
            if value > 0 {
                must.push(range(field, "lte", value));
            }
        };

        for (field, bounds) in [
            ("size", self.ranges.size),
            ("totalpart", self.ranges.totalpart),
            ("grabs", self.ranges.grabs),
            ("comments", self.ranges.comments),
            ("adddate_ts", self.ranges.adddate_ts),
        ] {
            if let Some(min) = bounds.min.filter(|value| *value >= 0) {
                must.push(range(field, "gte", min));
            }
            if let Some(max) = bounds.max.filter(|value| *value >= 0) {
                must.push(range(field, "lte", max));
            }
        }
        if let Some(min) = self.min_id.filter(|value| *value >= 0) {
            must.push(range("id", "gte", min));
        }
        if let Some(max) = self.max_id.filter(|value| *value >= 0) {
            must.push(range("id", "lte", max));
        }

        let release_ids: Vec<i64> = self
            .release_ids
            .iter()
            .copied()
            .filter(|id| *id > 0)
            .collect();
        must.extend(one_or_many("id", &release_ids));
        let categories: Vec<i64> = self
            .category_ids
            .iter()
            .copied()
            .filter(|id| *id > 0)
            .collect();
        must.extend(one_or_many("categories_id", &categories));

        let mut must_not = Vec::new();
        if !self.excluded_category_ids.is_empty() {
            must_not.push(json!({"in": {"categories_id": self.excluded_category_ids}}));
        }

        at_least(&mut must, "size", self.min_size);
        at_most(&mut must, "size", self.max_size);
        if self.max_age_days > 0 {
            must.push(range(
                "postdate_ts",
                "gte",
                now - self.max_age_days * 86_400,
            ));
        }
        at_least(&mut must, "postdate_ts", self.min_date);
        at_most(&mut must, "postdate_ts", self.max_date);
        if let Some(group) = self.group_id.filter(|group| *group > 0) {
            must.push(equals("groups_id", group));
        }
        must.push(range(
            "passwordstatus",
            "lte",
            i64::from(self.password_allow_rar),
        ));
        if let Some(status) = self.password_status_min {
            must.push(range("passwordstatus", "gte", status));
        }
        if let Some(has_media_info) = self.has_media_info {
            must.push(equals("has_media_info", i64::from(has_media_info)));
        }
        let unique_id = self.media_unique_id.as_deref().map(crate::query::php_trim);
        if let Some(unique_id) = unique_id.filter(|id| !id.is_empty() && *id != "-1") {
            must.push(equals("media_unique_id", unique_id));
        }
        at_least(&mut must, "media_video_width", self.min_video_width);
        at_most(&mut must, "media_video_width", self.max_video_width);
        at_least(&mut must, "media_video_height", self.min_video_height);
        at_most(&mut must, "media_video_height", self.max_video_height);
        (must, must_not)
    }

    /// The request for one attempt, or None when the text leaves nothing to search for.
    pub(crate) fn request_body(
        &self,
        config: &SearchConfig,
        indexes: &IndexNames,
        use_fuzzy: bool,
        now: i64,
    ) -> Option<Value> {
        let mut must = Vec::new();
        let mut options = serde_json::Map::new();
        if let Some(phrases) = self.phrases.as_ref().filter(|phrases| phrases.has_text()) {
            if use_fuzzy && config.fuzzy_enabled {
                if phrases.has_negation() {
                    return None;
                }
                let text = phrases.to_fuzzy_string();
                if text.is_empty() {
                    return None;
                }
                must.push(json!({"query_string": text}));
                options.insert("fuzzy".to_owned(), json!(true));
                options.insert(
                    "distance".to_owned(),
                    json!(config.fuzzy_max_distance.min(PROFILE_FUZZY_DISTANCE)),
                );
                options.insert("field_weights".to_owned(), field_weights("releases"));
            } else {
                let terms: Vec<String> = phrases
                    .to_fields()
                    .iter()
                    .filter(|(field, value)| {
                        RELEASE_SEARCH_FIELDS.contains(&field.as_str()) && !value.is_empty()
                    })
                    .filter_map(|(field, value)| {
                        let prepared = if field == "fromname" {
                            prepare_poster_query(value)
                        } else {
                            prepare_user_search_query(value)
                        };
                        (!prepared.is_empty()).then(|| {
                            scope_release_search_query(value, &prepared, &format!("@{field}"))
                        })
                    })
                    .collect();
                if terms.is_empty() {
                    return None;
                }
                must.push(json!({"query_string": format!("@@relaxed {}", terms.join(" "))}));
                options.insert("ranker".to_owned(), json!(RANKER));
                options.insert("field_weights".to_owned(), field_weights("releases"));
            }
        }

        let (filters, must_not) = self.filters(now);
        must.extend(filters);
        let mut bool_query = serde_json::Map::new();
        bool_query.insert("must".to_owned(), Value::Array(must));
        if !must_not.is_empty() {
            bool_query.insert("must_not".to_owned(), Value::Array(must_not));
        }

        let direction = self.sort_direction.name();
        let attribute = self.sort_field.attribute();
        let mut sort = vec![json!({attribute: direction})];
        if self.sort_field != SortField::Id {
            sort.push(json!({"id": direction}));
        }

        let limit = self.limit.max(1);
        let offset = self.effective_offset();
        let wanted = (offset + limit + 100).max(1000);
        let mut body = json!({
            "table": indexes.table("releases"),
            "strip_bad_utf8": true,
            "query": {"bool": bool_query},
            "sort": sort,
            "max_matches": wanted.min(config.max_matches),
            "limit": limit,
        });
        if offset > 0 {
            body["offset"] = json!(offset);
        }
        if !options.is_empty() {
            body["options"] = Value::Object(options);
        }
        Some(body)
    }
}
