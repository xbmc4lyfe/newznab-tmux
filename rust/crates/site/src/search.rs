//! The release search page.

use anyhow::Result;
use askama::Template;
use axum::Router;
use axum::extract::State;
use axum::middleware::from_fn;
use axum::response::{Html, IntoResponse};
use axum::routing::get;
use chrono::{NaiveDate, Utc};
use nntmux_runtime::Ctx;
use nntmux_search::{
    Bounds, Phrases, Ranges, ReleaseSearchQuery, SearchClient, SearchConfig, SortDirection,
    SortField,
};
use nntmux_webkit::auth::Auth;
use nntmux_webkit::config::encode_component;
use nntmux_webkit::{CurrentUser, Input, Layout, WebResult, guard, user};

use crate::releases::{
    BrowseFilter, ReleaseList, ReleaseRow, browse_count, browse_rows, expand_categories,
    max_results, order_or_default, password_limit, per_page,
};

pub fn router() -> Router<Ctx> {
    Router::new()
        .route("/search", get(search).post(search))
        .route_layer(from_fn(guard::two_factor))
        .route_layer(from_fn(guard::verified))
        .route_layer(from_fn(guard::authenticated))
}

const MB: f64 = 1_048_576.0;
/// Legacy size buckets, shown as MB in the current form.
const BUCKETS: [i64; 11] = [
    100, 200, 500, 1000, 2000, 3000, 4000, 8000, 16000, 32000, 64000,
];

/// What the form asked for, after aliases and validation.
#[derive(Debug, Default)]
struct Form {
    search: String,
    subject: String,
    filename: String,
    poster: String,
    exact_poster: String,
    poster_release_id: Option<i64>,
    group: String,
    min_age: Option<i64>,
    max_age: Option<i64>,
    ranges: Ranges,
    categories: Vec<i64>,
    advanced: bool,
    errors: Vec<String>,
}

fn aliased(input: &Input, field: &str, alias: &str) -> String {
    let value = input
        .get(field)
        .or_else(|| input.get(alias).filter(|value| value != "-1"))
        .unwrap_or_default();
    value.trim().to_owned()
}

fn whole(input: &Input, field: &str, maximum: i64, errors: &mut Vec<String>) -> Option<i64> {
    whole_value(&input.str(field), field, maximum, errors)
}

fn whole_value(value: &str, field: &str, maximum: i64, errors: &mut Vec<String>) -> Option<i64> {
    let value = value.trim();
    if value.is_empty() {
        return None;
    }
    match value.parse::<i64>() {
        Ok(number) if (0..=maximum).contains(&number) => Some(number),
        _ => {
            errors.push(format!(
                "The {field} field must be a whole number from 0 to {maximum}."
            ));
            None
        }
    }
}

fn bounded(input: &Input, name: &str, errors: &mut Vec<String>) -> Bounds {
    let min = whole(input, &format!("min{name}"), 4_294_967_295, errors);
    let max = whole(input, &format!("max{name}"), 4_294_967_295, errors);
    ordered(name, min, max, errors)
}

fn ordered(name: &str, min: Option<i64>, max: Option<i64>, errors: &mut Vec<String>) -> Bounds {
    if let (Some(low), Some(high)) = (min, max)
        && high < low
    {
        errors.push(format!("The minimum {name} must not exceed the maximum."));
    }
    Bounds { min, max }
}

/// MB as bytes, with `searchadvsize*` buckets standing in when the field is absent.
fn megabytes(input: &Input, field: &str, alias: &str, errors: &mut Vec<String>) -> Option<i64> {
    let raw = input.str(field);
    let raw = raw.trim();
    if raw.is_empty() {
        let bucket = input.str(alias).trim().parse::<usize>().ok()?;
        return bucket
            .checked_sub(1)
            .and_then(|index| BUCKETS.get(index))
            .map(|mb| mb * 1_048_576);
    }
    match raw.parse::<f64>() {
        Ok(value) if value.is_finite() && (0.0..=8_796_093_022_207.0).contains(&value) => {
            Some((value * MB).round() as i64)
        }
        _ => {
            errors.push(format!("The {field} field must be a number of megabytes."));
            None
        }
    }
}

fn day_bound(
    input: &Input,
    field: &str,
    end_of_day: bool,
    errors: &mut Vec<String>,
) -> Option<i64> {
    let raw = input.str(field);
    let raw = raw.trim();
    if raw.is_empty() {
        return None;
    }
    let time = NaiveDate::parse_from_str(raw, "%Y-%m-%d")
        .ok()
        .filter(|date| date.format("%Y-%m-%d").to_string() == raw)
        .filter(|date| {
            date.and_hms_opt(0, 0, 0)
                .is_some_and(|at| at.and_utc().timestamp() >= 0)
        });
    let Some(date) = time else {
        errors.push(format!(
            "The {field} field must be a date, YYYY-MM-DD, from 1970 on."
        ));
        return None;
    };
    let at = if end_of_day {
        date.and_hms_opt(23, 59, 59)
    } else {
        date.and_hms_opt(0, 0, 0)
    };
    at.map(|at| at.and_utc().timestamp())
}

fn parse_form(input: &Input) -> Form {
    let mut errors = Vec::new();
    let search = aliased(input, "search", "searchadvr");
    let search = if search.is_empty() && !input.has("searchadvr") {
        input
            .get("subject")
            .or_else(|| input.get("id"))
            .unwrap_or_default()
            .trim()
            .to_owned()
    } else {
        search
    };
    let raw_t = input
        .get("t")
        .or_else(|| input.get("searchadvcat"))
        .unwrap_or_default();
    let raw_t = raw_t.trim();
    let categories = if raw_t.is_empty() || raw_t == "0" || raw_t == "-1" {
        vec![-1]
    } else if raw_t.len() <= 512
        && raw_t
            .split(',')
            .all(|id| !id.is_empty() && id.bytes().all(|byte| byte.is_ascii_digit()))
    {
        let mut ids: Vec<i64> = raw_t.split(',').filter_map(|id| id.parse().ok()).collect();
        ids.dedup();
        ids
    } else {
        errors.push("The category is not valid.".to_owned());
        vec![-1]
    };
    // An exact poster survives only while it agrees with the advanced field.
    let advanced_poster = input.str("searchadvposter");
    let mut exact_poster = input.str("poster");
    if input.has("poster") && input.has("searchadvposter") && advanced_poster != exact_poster {
        exact_poster = advanced_poster.clone();
    }
    let poster_release_id =
        whole(input, "poster_release_id", 4_294_967_295, &mut errors).filter(|id| *id > 0);
    let min_age = whole_value(
        &aliased(input, "minage", "searchadvdaysnew"),
        "minage",
        3_650_000,
        &mut errors,
    );
    let max_age = whole_value(
        &aliased(input, "maxage", "searchadvdaysold"),
        "maxage",
        3_650_000,
        &mut errors,
    );
    let min_size = megabytes(input, "minsize", "searchadvsizefrom", &mut errors);
    let max_size = megabytes(input, "maxsize", "searchadvsizeto", &mut errors);
    let ranges = Ranges {
        size: ordered("size", min_size, max_size, &mut errors),
        totalpart: bounded(input, "files", &mut errors),
        grabs: bounded(input, "grabs", &mut errors),
        comments: bounded(input, "comments", &mut errors),
        adddate_ts: {
            let min = day_bound(input, "added_from", false, &mut errors);
            let max = day_bound(input, "added_to", true, &mut errors);
            ordered("added date", min, max, &mut errors)
        },
    };
    let group = aliased(input, "group", "searchadvgroups");
    let any_filled = !group.is_empty()
        || min_age.is_some()
        || max_age.is_some()
        || min_size.is_some()
        || max_size.is_some()
        || [
            "searchadvposter",
            "searchadvsubject",
            "searchadvfilename",
            "poster",
            "poster_release_id",
            "minage",
            "maxage",
            "minsize",
            "maxsize",
            "added_from",
            "added_to",
            "minfiles",
            "maxfiles",
            "mingrabs",
            "maxgrabs",
            "mincomments",
            "maxcomments",
        ]
        .iter()
        .any(|field| input.filled(field));
    Form {
        subject: input.str("searchadvsubject").trim().to_owned(),
        filename: input.str("searchadvfilename").trim().to_owned(),
        poster: if exact_poster.is_empty() {
            advanced_poster
        } else {
            String::new()
        },
        advanced: input.str("search_type") == "adv" || any_filled,
        search,
        exact_poster,
        poster_release_id,
        group,
        min_age,
        max_age,
        ranges,
        categories,
        errors,
    }
}

impl Form {
    fn fields(&self) -> Vec<(String, String)> {
        [
            ("searchname", &self.search),
            ("name", &self.subject),
            ("fromname", &self.poster),
            ("filename", &self.filename),
        ]
        .into_iter()
        .filter(|(_, value)| !value.is_empty())
        .map(|(field, value)| (field.to_owned(), value.clone()))
        .collect()
    }

    fn has_exact_poster(&self) -> bool {
        self.poster_release_id.is_some() || !self.exact_poster.is_empty()
    }

    fn has_text(&self) -> bool {
        !self.has_exact_poster() && !self.fields().is_empty()
    }

    fn has_filters(&self) -> bool {
        let range = |bounds: Bounds| bounds.min.is_some() || bounds.max.is_some();
        self.has_exact_poster()
            || !self.group.is_empty()
            || self.categories != [-1]
            || self.min_age.is_some()
            || self.max_age.is_some()
            || range(self.ranges.size)
            || range(self.ranges.totalpart)
            || range(self.ranges.grabs)
            || range(self.ranges.comments)
            || range(self.ranges.adddate_ts)
    }
}

fn sort_field(order: &str) -> SortField {
    match order.split('_').next().unwrap_or_default() {
        "added" => SortField::AddDate,
        "cat" => SortField::Category,
        "size" => SortField::Size,
        "files" => SortField::TotalPart,
        "stats" => SortField::Grabs,
        "comments" => SortField::Comments,
        _ => SortField::PostDate,
    }
}

/// `buildSearchWhereClause` for the path that reads MySQL: numbers are written in, and text binds.
fn mysql_clause(form: &Form) -> Option<(String, Vec<String>)> {
    let mut sql = String::new();
    let mut binds = Vec::new();
    if let Some(days) = form.max_age {
        sql.push_str(&format!(" AND r.postdate >= NOW() - INTERVAL {days} DAY"));
    }
    if let Some(days) = form.min_age {
        sql.push_str(&format!(" AND r.postdate <= NOW() - INTERVAL {days} DAY"));
    }
    for (column, bounds) in [
        ("r.size", form.ranges.size),
        ("r.totalpart", form.ranges.totalpart),
        ("r.grabs", form.ranges.grabs),
        ("r.comments", form.ranges.comments),
        ("UNIX_TIMESTAMP(r.adddate)", form.ranges.adddate_ts),
    ] {
        if let Some(min) = bounds.min {
            sql.push_str(&format!(" AND {column} >= {min}"));
        }
        if let Some(max) = bounds.max {
            sql.push_str(&format!(" AND {column} <= {max}"));
        }
    }
    if !form.exact_poster.is_empty() {
        sql.push_str(" AND r.fromname = ? AND HEX(r.fromname) = HEX(?)");
        binds.push(form.exact_poster.clone());
        binds.push(form.exact_poster.clone());
    } else if let Some(id) = form.poster_release_id {
        sql.push_str(&format!(" AND EXISTS (SELECT 1 FROM releases poster_source WHERE poster_source.id = {id} AND r.fromname = poster_source.fromname AND HEX(r.fromname) = HEX(poster_source.fromname))"));
    }
    let fields = form.fields();
    let mut conditions = 0;
    for (field, value) in &fields {
        let terms: Vec<String> = value
            .split_whitespace()
            .filter(|term| term.len() >= 2)
            .map(|term| {
                format!(
                    "%{}%",
                    term.replace('!', "!!")
                        .replace('%', "!%")
                        .replace('_', "!_")
                )
            })
            .collect();
        conditions += terms.len();
        if terms.is_empty() {
            continue;
        }
        if field == "filename" {
            let any = vec!["rf.name LIKE ? ESCAPE '!'"; terms.len()].join(" OR ");
            sql.push_str(&format!(" AND EXISTS (SELECT 1 FROM release_files rf WHERE rf.releases_id = r.id AND ({any}))"));
        } else {
            let column = match field.as_str() {
                "name" => "r.name",
                "fromname" => "r.fromname",
                _ => "r.searchname",
            };
            for _ in &terms {
                sql.push_str(&format!(" AND {column} LIKE ? ESCAPE '!'"));
            }
        }
        binds.extend(terms);
    }
    (fields.is_empty() || conditions > 0).then_some((sql, binds))
}

fn id_clause(ids: &[i64]) -> String {
    format!(
        " AND r.id IN ({})",
        ids.iter().map(i64::to_string).collect::<Vec<_>>().join(",")
    )
}

struct Found {
    rows: Vec<ReleaseRow>,
    total: u64,
}

const NOTHING: Found = Found {
    rows: Vec::new(),
    total: 0,
};

async fn from_mysql(
    ctx: &Ctx,
    form: &Form,
    excluded: &[i64],
    order: &str,
    offset: u64,
    signed_in: &Auth,
) -> Result<Found> {
    let Some((extra, binds)) = mysql_clause(form) else {
        return Ok(NOTHING);
    };
    let filter = BrowseFilter {
        categories: &form.categories,
        excluded,
        group: (!form.group.is_empty()).then_some(form.group.as_str()),
        extra: &extra,
        extra_binds: &binds,
    };
    let total = browse_count(&ctx.db, &filter).await?;
    if offset >= total {
        return Ok(Found {
            rows: Vec::new(),
            total,
        });
    }
    let rows = browse_rows(
        &ctx.db,
        &filter,
        order,
        per_page(),
        offset,
        signed_in.user.timezone.as_deref(),
    )
    .await?;
    Ok(Found { rows, total })
}

/// Manticore finds the ids; MySQL reads the rows. An outage shows as no results, as it did in PHP.
async fn from_index(
    ctx: &Ctx,
    client: &SearchClient,
    form: &Form,
    excluded: &[i64],
    order: &str,
    offset: u64,
    signed_in: &Auth,
) -> Result<Found> {
    let db = &ctx.db;
    let categories = expand_categories(db, &form.categories)
        .await?
        .unwrap_or_default();
    let wanted: Vec<i64> = categories
        .iter()
        .copied()
        .filter(|id| !excluded.contains(id))
        .collect();
    if !categories.is_empty() && wanted.is_empty() {
        return Ok(NOTHING);
    }
    let group_id = if form.group.is_empty() {
        None
    } else {
        let id: Option<i64> =
            sqlx::query_scalar("SELECT CAST(id AS SIGNED) FROM usenet_groups WHERE name = ?")
                .bind(&form.group)
                .fetch_optional(db)
                .await?;
        match id {
            Some(id) => Some(id),
            None => return Ok(NOTHING),
        }
    };
    let now = Utc::now().timestamp();
    let by_name = order.starts_with("name");
    let fields = form.fields();
    let query = ReleaseSearchQuery {
        phrases: (!fields.is_empty()).then_some(Phrases::Fields(fields)),
        category_ids: wanted,
        excluded_category_ids: excluded.to_vec(),
        min_date: form.max_age.map_or(0, |days| now - days * 86400),
        max_date: form.min_age.map_or(0, |days| now - days * 86400),
        group_id,
        password_allow_rar: password_limit(db).await? >= 1,
        sort_field: if by_name {
            SortField::Id
        } else {
            sort_field(order)
        },
        sort_direction: if by_name {
            SortDirection::Desc
        } else {
            SortDirection::parse(order.rsplit('_').next().unwrap_or_default())
        },
        limit: if by_name { max_results() } else { per_page() },
        offset: if by_name { 0 } else { offset },
        ranges: form.ranges,
        ..ReleaseSearchQuery::default()
    };
    let page = match client.search_releases(&query).await {
        Ok(page) => page,
        Err(error) => {
            tracing::warn!(%error, "release search is unavailable");
            return Ok(NOTHING);
        }
    };
    if page.ids.is_empty() {
        return Ok(NOTHING);
    }
    let extra = id_clause(&page.ids);
    let filter = BrowseFilter {
        categories: &[-1],
        excluded,
        group: None,
        extra: &extra,
        extra_binds: &[],
    };
    let timezone = signed_in.user.timezone.as_deref();
    // The index can't sort by name, so the newest matches are named and paged here.
    if by_name {
        let total = page.ids.len() as u64;
        let rows = if offset >= total {
            Vec::new()
        } else {
            browse_rows(db, &filter, order, per_page(), offset, timezone).await?
        };
        return Ok(Found { rows, total });
    }
    let mut rows = browse_rows(db, &filter, order, page.ids.len() as u64, 0, timezone).await?;
    rows.sort_by_key(|row| page.ids.iter().position(|id| *id == row.id));
    Ok(Found {
        rows,
        total: page.total,
    })
}

struct AdvancedField {
    name: &'static str,
    label: &'static str,
    kind: &'static str,
    step: &'static str,
    value: String,
}

struct CategoryOption {
    id: i64,
    title: String,
    selected: bool,
}

#[derive(Template)]
#[template(path = "search/index.html")]
struct SearchPage {
    layout: Layout,
    search: String,
    exact_poster: String,
    suggestion: Option<String>,
    suggestion_url: String,
    advanced_url: String,
    hidden_fields: Vec<(&'static str, String)>,
    categories: Vec<CategoryOption>,
    errors: Vec<String>,
    advanced: bool,
    fields: Vec<AdvancedField>,
    submitted: bool,
    list: ReleaseList,
}

const ADVANCED_FIELDS: [(&str, &str, &str, &str); 14] = [
    ("group", "Usenet Group", "text", "searchadvgroups"),
    ("searchadvposter", "Poster", "text", ""),
    ("added_from", "Added From (inclusive)", "date", ""),
    ("added_to", "Added To (inclusive)", "date", ""),
    (
        "minage",
        "Min Posted Age (days)",
        "number",
        "searchadvdaysnew",
    ),
    (
        "maxage",
        "Max Posted Age (days)",
        "number",
        "searchadvdaysold",
    ),
    ("minsize", "Min Size (MB)", "number", "searchadvsizefrom"),
    ("maxsize", "Max Size (MB)", "number", "searchadvsizeto"),
    ("minfiles", "Min Files", "number", ""),
    ("maxfiles", "Max Files", "number", ""),
    ("mingrabs", "Min Grabs", "number", ""),
    ("maxgrabs", "Max Grabs", "number", ""),
    ("mincomments", "Min Comments", "number", ""),
    ("maxcomments", "Max Comments", "number", ""),
];

fn search_url(query: &[(String, String)], key: &str, value: &str) -> String {
    let mut url = String::from("/search?");
    for (name, current) in query
        .iter()
        .filter(|(name, _)| name != key && name != "page")
    {
        url.push_str(&format!(
            "{}={}&",
            encode_component(name),
            encode_component(current)
        ));
    }
    url.push_str(&format!(
        "{}={}",
        encode_component(key),
        encode_component(value)
    ));
    url
}

async fn search(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let form = parse_form(&input);
    let order = order_or_default(&input.str("ob"));
    let page = input.page();
    let offset = page.saturating_sub(1).saturating_mul(per_page());
    let submitted = form.errors.is_empty() && (form.has_text() || form.has_filters());
    let client = SearchClient::new(
        SearchConfig::from_env(&ctx.config.manticore)?,
        Some(ctx.redis.clone()),
    )?;
    let excluded = user::category_exclusions(&ctx.db, signed_in.user.id, &signed_in.access).await?;
    let found = if !submitted {
        NOTHING
    } else if form.has_exact_poster() {
        from_mysql(&ctx, &form, &excluded, order, offset, &signed_in).await?
    } else {
        from_index(&ctx, &client, &form, &excluded, order, offset, &signed_in).await?
    };
    let suggestion = if form.search.is_empty() || found.total > 3 {
        None
    } else {
        let mut suggestions = client.suggest(&form.search, None).await.unwrap_or_default();
        suggestions.sort_by_key(|suggestion| std::cmp::Reverse(suggestion.docs));
        suggestions
            .into_iter()
            .next()
            .map(|best| best.suggest)
            .filter(|best| *best != form.search)
    };
    let query = input.query_pairs();
    let rows: Vec<(i64, Option<i64>, String, Option<String>)> = sqlx::query_as(
        "SELECT CAST(c.id AS SIGNED), CAST(c.root_categories_id AS SIGNED), c.title, p.title FROM categories c
         LEFT JOIN root_categories p ON p.id = c.root_categories_id ORDER BY COALESCE(c.root_categories_id, c.id), c.id",
    )
    .fetch_all(&ctx.db)
    .await?;
    let selected = input
        .get("t")
        .or_else(|| input.get("searchadvcat"))
        .unwrap_or_default();
    let categories = rows
        .into_iter()
        .map(|(id, _, title, parent)| CategoryOption {
            id,
            title: parent.map_or_else(|| title.clone(), |parent| format!("{parent} > {title}")),
            selected: selected == id.to_string(),
        })
        .collect();
    let field_value = |name: &str, alias: &str| {
        input
            .get(name)
            .or_else(|| (!alias.is_empty()).then(|| input.get(alias)).flatten())
            .filter(|value| value != "-1")
            .unwrap_or_default()
    };
    let mut hidden_fields: Vec<(&'static str, String)> = vec![("ob", order.to_owned())];
    if !form.exact_poster.is_empty() {
        hidden_fields.push(("poster", form.exact_poster.clone()));
    } else if let Some(id) = form.poster_release_id {
        hidden_fields.push(("poster_release_id", id.to_string()));
    }
    for name in ["searchadvsubject", "searchadvfilename"] {
        if input.filled(name) {
            hidden_fields.push((name, input.str(name)));
        }
    }
    if form.advanced {
        hidden_fields.push(("search_type", "adv".to_owned()));
    }
    let fields = ADVANCED_FIELDS
        .iter()
        .map(|(name, label, kind, alias)| AdvancedField {
            name,
            label,
            kind,
            step: if name.ends_with("size") { "any" } else { "1" },
            value: field_value(name, alias),
        })
        .collect();
    let suggestion_url = suggestion
        .as_deref()
        .map(|best| search_url(&query, "search", best))
        .unwrap_or_default();
    let page = SearchPage {
        layout: layout.titled(
            "Search Nzbs",
            "search,nzb,description,details",
            "Search for Nzbs",
        ),
        search: form.search.clone(),
        exact_poster: form.exact_poster.clone(),
        suggestion,
        suggestion_url,
        advanced_url: search_url(&query, "search_type", "adv"),
        hidden_fields,
        categories,
        errors: form.errors,
        advanced: form.advanced,
        fields,
        submitted,
        list: ReleaseList::new("/search", &input, found.rows, found.total),
    };
    Ok(Html(page.render()?).into_response())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn form(query: &str) -> Form {
        parse_form(&Input::from_query(query))
    }

    #[test]
    fn aliases_fill_the_modern_fields() {
        let parsed = form(
            "searchadvr=ubuntu&searchadvcat=5000,5040&searchadvgroups=-1&searchadvdaysold=30&searchadvsizefrom=2",
        );
        assert_eq!(parsed.search, "ubuntu");
        assert_eq!(parsed.categories, [5000, 5040]);
        assert_eq!(parsed.group, "");
        assert_eq!(parsed.max_age, Some(30));
        assert_eq!(parsed.ranges.size.min, Some(200 * 1_048_576));
        assert!(parsed.advanced);
    }

    #[test]
    fn bad_input_is_reported_and_runs_no_search() {
        assert!(!form("minfiles=x").errors.is_empty());
        assert!(!form("minfiles=9&maxfiles=2").errors.is_empty());
        assert!(!form("added_from=2026-13-40").errors.is_empty());
        assert!(!form("t=1;DROP").errors.is_empty());
        assert!(form("minsize=1.5").errors.is_empty());
        assert_eq!(form("minsize=1.5").ranges.size.min, Some(1_572_864));
    }

    #[test]
    fn an_exact_poster_follows_the_advanced_field() {
        let parsed = form("poster=a%40b&searchadvposter=c%40d");
        assert_eq!(parsed.exact_poster, "c@d");
        let parsed = form("poster=a%40b");
        assert!(parsed.has_exact_poster() && !parsed.has_text());
        assert_eq!(form("searchadvposter=c%40d").poster, "c@d");
    }

    #[test]
    fn mysql_terms_bind_and_escape() {
        let (sql, binds) = mysql_clause(&form("poster=a%40b&search=100%25+x")).unwrap();
        assert_eq!(binds, ["a@b", "a@b", "%100!%%"]);
        assert_eq!(sql.matches('?').count(), 3);
        assert!(mysql_clause(&form("poster=a%40b&search=x")).is_none());
    }
}
