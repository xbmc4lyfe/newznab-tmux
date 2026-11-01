//! The release list every browse and search page shows: its query, its rows and its links.

use anyhow::Result;
use chrono::{NaiveDateTime, Utc};
use nntmux_core::parsed_or;
use nntmux_webkit::config::encode_component;
use nntmux_webkit::{Input, Pager};
use sqlx::{FromRow, MySqlPool};

use crate::profile::relative;

/// Newest posted first, the default when the request names no order.
const DEFAULT_ORDER: &str = "posted_desc";
const MB_PER_GB: f64 = 1_073_741_824.0;

pub const ORDERINGS: [(&str, &str, &str); 16] = [
    ("posted_desc", "Posted (Newest)", "fa-calendar-alt"),
    ("posted_asc", "Posted (Oldest)", "fa-calendar-alt"),
    ("added_desc", "Added (Newest)", "fa-clock"),
    ("added_asc", "Added (Oldest)", "fa-clock"),
    ("name_asc", "Name (A-Z)", "fa-font"),
    ("name_desc", "Name (Z-A)", "fa-font"),
    ("cat_asc", "Category (Ascending)", "fa-tags"),
    ("cat_desc", "Category (Descending)", "fa-tags"),
    ("size_desc", "Size (Largest)", "fa-hdd"),
    ("size_asc", "Size (Smallest)", "fa-hdd"),
    ("files_desc", "Files (Most)", "fa-file"),
    ("files_asc", "Files (Least)", "fa-file"),
    ("stats_desc", "Grabs (Most)", "fa-download"),
    ("stats_asc", "Grabs (Least)", "fa-download"),
    ("comments_desc", "Comments (Most)", "fa-comment"),
    ("comments_asc", "Comments (Least)", "fa-comment"),
];

/// A valid `ob` value, or the default. Only these strings reach the ORDER BY.
pub fn order_or_default(requested: &str) -> &'static str {
    ORDERINGS
        .iter()
        .find(|(key, _, _)| *key == requested)
        .map_or(DEFAULT_ORDER, |(key, _, _)| key)
}

/// The column and direction for a validated order.
pub fn order_sql(order: &str) -> (&'static str, &'static str) {
    let (field, direction) = order.split_once('_').unwrap_or(("posted", "desc"));
    let column = match field {
        "cat" => "categories_id",
        "name" => "searchname",
        "size" => "size",
        "files" => "totalpart",
        "stats" => "grabs",
        "comments" => "comments",
        "added" => "adddate",
        _ => "postdate",
    };
    (column, if direction == "asc" { "ASC" } else { "DESC" })
}

pub fn per_page() -> u64 {
    parsed_or("ITEMS_PER_PAGE", 50).unwrap_or(50)
}

pub fn max_results() -> u64 {
    parsed_or("MAX_PAGER_RESULTS", 125_000).unwrap_or(125_000)
}

/// `Category::getCategorySearch`: a root expands to its children. `None` means every category.
pub async fn expand_categories(db: &MySqlPool, categories: &[i64]) -> Result<Option<Vec<i64>>> {
    let mut ids = Vec::new();
    for &category in categories {
        if category <= 0 {
            continue;
        }
        let is_root: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM root_categories WHERE id = ?")
            .bind(category)
            .fetch_one(db)
            .await?;
        if is_root > 0 {
            let children: Vec<i64> = sqlx::query_scalar(
                "SELECT CAST(id AS SIGNED) FROM categories WHERE root_categories_id = ?",
            )
            .bind(category)
            .fetch_all(db)
            .await?;
            ids.extend(children);
        } else {
            ids.push(category);
        }
    }
    Ok((!ids.is_empty()).then_some(ids))
}

/// `showPasswords`: passworded releases only when the operator chose to show them.
pub async fn password_limit(db: &MySqlPool) -> Result<i32> {
    let show: Option<i64> = sqlx::query_scalar("SELECT CAST(show_passworded_releases AS SIGNED) FROM ingestion_configurations ORDER BY id LIMIT 1")
        .fetch_optional(db)
        .await?;
    Ok(i32::from(show == Some(1)))
}

pub fn id_list(ids: &[i64]) -> String {
    ids.iter().map(i64::to_string).collect::<Vec<_>>().join(",")
}

#[derive(Clone, Debug, Default)]
pub struct BrowseFilter<'a> {
    pub categories: &'a [i64],
    pub excluded: &'a [i64],
    /// A group name, escaped by binding.
    pub group: Option<&'a str>,
    /// More conditions, written only from literals, with `?` for each of `extra_binds`.
    pub extra: &'a str,
    pub extra_binds: &'a [String],
}

/// The inner WHERE. Numbers are written in; the group name is bound by the caller.
async fn where_clause(db: &MySqlPool, filter: &BrowseFilter<'_>) -> Result<String> {
    let mut clause = format!("r.passwordstatus <= {}", password_limit(db).await?);
    if let Some(ids) = expand_categories(db, filter.categories).await? {
        clause.push_str(&format!(" AND r.categories_id IN ({})", id_list(&ids)));
    }
    if !filter.excluded.is_empty() {
        clause.push_str(&format!(
            " AND r.categories_id NOT IN ({})",
            id_list(filter.excluded)
        ));
    }
    if filter.group.is_some() {
        clause.push_str(" AND g.name = ?");
    }
    clause.push_str(filter.extra);
    Ok(clause)
}

/// `getBrowseCount`, capped so the pager can't promise a page the database would crawl for.
pub async fn browse_count(db: &MySqlPool, filter: &BrowseFilter<'_>) -> Result<u64> {
    let clause = where_clause(db, filter).await?;
    let join = if filter.group.is_some() {
        "INNER JOIN usenet_groups g ON g.id = r.groups_id"
    } else {
        ""
    };
    let sql = format!("SELECT COUNT(r.id) FROM releases r {join} WHERE {clause}");
    let mut query = sqlx::query_scalar::<_, i64>(sqlx::AssertSqlSafe(sql));
    if let Some(group) = filter.group {
        query = query.bind(group);
    }
    for bind in filter.extra_binds {
        query = query.bind(bind);
    }
    let count = query.fetch_one(db).await?.max(0) as u64;
    let cap = max_results();
    Ok(if cap > 0 { count.min(cap) } else { count })
}

/// The row as the browse query returns it.
#[derive(Debug, FromRow)]
struct RawRow {
    id: i64,
    searchname: String,
    guid: String,
    postdate: Option<NaiveDateTime>,
    adddate: Option<NaiveDateTime>,
    size: i64,
    totalpart: i64,
    fromname: Option<String>,
    grabs: i64,
    comments: i64,
    videos_id: i64,
    haspreview: i64,
    jpgstatus: i64,
    nfostatus: i64,
    group_name: Option<String>,
    category_name: Option<String>,
    failed_count: Option<i64>,
    total_report_count: i64,
    all_report_reasons: Option<String>,
    latest_report_reason: Option<String>,
    latest_report_description: Option<String>,
    report_response_count: i64,
    nfoid: Option<i64>,
    reid: Option<i64>,
    imdbid: Option<String>,
}

pub struct ReleaseRow {
    pub id: i64,
    pub guid: String,
    pub searchname: String,
    pub size_label: String,
    pub date_label: String,
    pub posted: String,
    pub group_name: String,
    pub fromname: String,
    pub totalpart: i64,
    pub grabs: i64,
    pub comments: i64,
    pub category: String,
    pub category_url: Option<String>,
    pub reports: i64,
    pub report_title: String,
    pub responses: i64,
    pub failed: i64,
    pub haspreview: i64,
    pub jpgstatus: i64,
    pub nfostatus: i64,
    pub videos_id: i64,
    pub has_media: bool,
    pub imdbid: String,
    pub preview_url: String,
    pub sample_url: String,
    pub cover: Option<String>,
}

impl ReleaseRow {
    /// `imdb_id_is_valid`
    pub fn imdb_valid(&self) -> bool {
        let digits: String = self.imdbid.chars().filter(char::is_ascii_digit).collect();
        !digits.is_empty() && digits.parse::<u64>() != Ok(0)
    }
}

/// Which report reasons mean what, for the badge's tooltip.
fn reason_label(key: &str) -> String {
    match key {
        "duplicate" => "Duplicate Release".to_owned(),
        "fake" => "Fake/Malicious Content".to_owned(),
        "password" => "Password Protected".to_owned(),
        "incomplete" => "Incomplete/Corrupted".to_owned(),
        "wrong_category" => "Wrong Category".to_owned(),
        "spam" => "Spam/Advertisement".to_owned(),
        "other" => "Other".to_owned(),
        other => {
            let mut chars = other.chars();
            chars
                .next()
                .map(|first| first.to_uppercase().chain(chars).collect())
                .unwrap_or_default()
        }
    }
}

fn reasons_label(keys: &str) -> String {
    let mut labels: Vec<String> = Vec::new();
    for key in keys.split(',').map(str::trim).filter(|key| !key.is_empty()) {
        let label = reason_label(key);
        if !labels.contains(&label) {
            labels.push(label);
        }
    }
    labels.join(", ")
}

/// A cover under `storage/covers`, when one exists on disk.
pub fn cover_url(kind: &str, base: &str) -> Option<String> {
    let root = std::path::PathBuf::from(nntmux_core::var_or("NNTMUX_STORAGE_DIR", "storage"))
        .join("covers")
        .join(kind);
    ["webp", "jpg", "jpeg", "png"]
        .iter()
        .find(|extension| root.join(format!("{base}.{extension}")).is_file())
        .map(|extension| format!("/covers/{kind}/{base}.{extension}"))
}

fn release_cover(imdbid: &str, videos_id: i64) -> Option<String> {
    let digits: String = imdbid.chars().filter(char::is_ascii_digit).collect();
    if !digits.is_empty() && digits.parse::<u64>() != Ok(0) {
        return cover_url("movies", &format!("{imdbid}-cover"));
    }
    if videos_id > 0 {
        return cover_url("tvshows", &videos_id.to_string());
    }
    None
}

pub(crate) fn local_time(at: NaiveDateTime, timezone: Option<&str>, format: &str) -> String {
    let zone: chrono_tz::Tz = timezone
        .and_then(|zone| zone.parse().ok())
        .unwrap_or(chrono_tz::UTC);
    at.and_utc().with_timezone(&zone).format(format).to_string()
}

fn into_row(raw: RawRow, timezone: Option<&str>, date_is_added: bool) -> ReleaseRow {
    let now = Utc::now();
    let category = raw.category_name.unwrap_or_else(|| "Other".to_owned());
    let category_url = category.split_once(" > ").map(|(parent, sub)| {
        format!(
            "/browse/{}/{}",
            path_segment(parent.trim()),
            path_segment(sub.trim())
        )
    });
    let date = if date_is_added {
        raw.adddate.or(raw.postdate)
    } else {
        raw.postdate.or(raw.adddate)
    };
    let imdbid = raw.imdbid.unwrap_or_default();
    let reason = raw
        .latest_report_reason
        .as_deref()
        .map(reason_label)
        .unwrap_or_else(|| "Unknown".to_owned());
    let description = raw
        .latest_report_description
        .unwrap_or_else(|| "No additional report details were provided.".to_owned());
    let report_title = format!(
        "Reported: {} | Original report: {reason} - {description}",
        reasons_label(raw.all_report_reasons.as_deref().unwrap_or_default())
    );
    ReleaseRow {
        id: raw.id,
        size_label: format!("{:.2} GB", raw.size as f64 / MB_PER_GB),
        date_label: date.map_or_else(|| "Unknown".to_owned(), |at| relative(at.and_utc(), now)),
        posted: raw
            .postdate
            .map(|at| local_time(at, timezone, "%b %d, %Y %H:%M"))
            .unwrap_or_default(),
        group_name: raw.group_name.unwrap_or_default(),
        fromname: raw.fromname.unwrap_or_default(),
        totalpart: raw.totalpart,
        grabs: raw.grabs,
        comments: raw.comments,
        category,
        category_url,
        reports: raw.total_report_count,
        report_title,
        responses: raw.report_response_count,
        failed: raw.failed_count.unwrap_or(0),
        haspreview: raw.haspreview,
        jpgstatus: raw.jpgstatus,
        // `nfostatus` alone says it was looked for. The NFO row says it's there.
        nfostatus: if raw.nfoid.is_some() {
            raw.nfostatus
        } else {
            0
        },
        videos_id: raw.videos_id,
        has_media: raw.reid.is_some(),
        preview_url: cover_url("preview", &format!("{}_thumb", raw.guid)).unwrap_or_default(),
        sample_url: cover_url("sample", &format!("{}_thumb", raw.guid)).unwrap_or_default(),
        cover: release_cover(&imdbid, raw.videos_id),
        imdbid,
        guid: raw.guid,
        searchname: raw.searchname,
    }
}

pub(crate) fn path_segment(text: &str) -> String {
    encode_component(text).replace('+', "%20")
}

const ROW_COLUMNS: &str = "CAST(r.id AS SIGNED) AS id, r.searchname, r.guid, r.postdate, r.adddate,
    CAST(r.size AS SIGNED) AS size, CAST(COALESCE(r.totalpart, 0) AS SIGNED) AS totalpart, r.fromname, CAST(r.grabs AS SIGNED) AS grabs,
    CAST(r.comments AS SIGNED) AS comments, CAST(r.videos_id AS SIGNED) AS videos_id, CAST(r.haspreview AS SIGNED) AS haspreview,
    CAST(r.jpgstatus AS SIGNED) AS jpgstatus, CAST(r.nfostatus AS SIGNED) AS nfostatus, r.group_name,
    CONCAT(cp.title, ' > ', c.title) AS category_name,
    CAST(MAX(df.failed) AS SIGNED) AS failed_count,
    CAST(COUNT(DISTINCT rr.id) AS SIGNED) AS total_report_count,
    GROUP_CONCAT(DISTINCT rr.reason SEPARATOR ', ') AS all_report_reasons,
    SUBSTRING_INDEX(GROUP_CONCAT(rr.reason ORDER BY rr.created_at DESC SEPARATOR ','), ',', 1) AS latest_report_reason,
    SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(NULLIF(rr.description, ''), 'No additional report details were provided.') ORDER BY rr.created_at DESC SEPARATOR ' || '), ' || ', 1) AS latest_report_description,
    CAST(COUNT(DISTINCT CASE WHEN rr.response IS NOT NULL AND rr.response != '' AND rr.response_is_public = 1 THEN rr.id ELSE NULL END) AS SIGNED) AS report_response_count,
    CAST(MAX(rn.releases_id) AS SIGNED) AS nfoid,
    CAST(MAX(re.releases_id) AS SIGNED) AS reid,
    CAST(MAX(m.imdbid) AS CHAR) AS imdbid";

const ROW_JOINS: &str = "LEFT JOIN categories c ON c.id = r.categories_id
    LEFT JOIN root_categories cp ON cp.id = c.root_categories_id
    LEFT OUTER JOIN movieinfo m ON m.id = r.movieinfo_id
    LEFT OUTER JOIN video_data re ON re.releases_id = r.id
    LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
    LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
    LEFT OUTER JOIN release_reports rr ON rr.releases_id = r.id";

/// `getBrowseRange` without the cache: a page of releases with their badges. The inner query
/// pages first, so the joins only touch the rows shown.
pub async fn browse_rows(
    db: &MySqlPool,
    filter: &BrowseFilter<'_>,
    order: &str,
    limit: u64,
    offset: u64,
    timezone: Option<&str>,
) -> Result<Vec<ReleaseRow>> {
    let clause = where_clause(db, filter).await?;
    let (column, direction) = order_sql(order);
    let sql = format!(
        "SELECT {ROW_COLUMNS}
         FROM (
            SELECT r.id, r.searchname, r.guid, r.postdate, r.groups_id, r.categories_id, r.size, r.totalpart, r.fromname,
                   r.passwordstatus, r.grabs, r.comments, r.adddate, r.videos_id, r.haspreview, r.jpgstatus, r.nfostatus,
                   g.name AS group_name, r.movieinfo_id
            FROM releases r LEFT JOIN usenet_groups g ON g.id = r.groups_id
            WHERE {clause}
            ORDER BY {column} {direction} LIMIT {limit} OFFSET {offset}
         ) r
         {ROW_JOINS}
         GROUP BY r.id
         ORDER BY {column} {direction}"
    );
    let mut query = sqlx::query_as::<_, RawRow>(sqlx::AssertSqlSafe(sql));
    if let Some(group) = filter.group {
        query = query.bind(group);
    }
    for bind in filter.extra_binds {
        query = query.bind(bind);
    }
    let raw = query.fetch_all(db).await?;
    let date_is_added = true;
    Ok(raw
        .into_iter()
        .map(|row| into_row(row, timezone, date_is_added))
        .collect())
}

pub struct SortHeader {
    pub key: &'static str,
    pub label: &'static str,
    pub url: String,
    pub aria: &'static str,
    pub icon: &'static str,
}

pub struct SortOption {
    pub label: &'static str,
    pub icon: &'static str,
    pub url: String,
    pub current: bool,
}

/// Everything `releases/panel.html` shows around the rows.
pub struct ReleaseList {
    pub rows: Vec<ReleaseRow>,
    pub pager: Pager,
    pub headers: Vec<SortHeader>,
    pub sort_options: Vec<SortOption>,
    pub sort_label: &'static str,
    pub sort_icon: &'static str,
    pub show_thumbs: bool,
    pub inline_search_url: String,
    pub search_placeholder: String,
    pub tv_rss_url: Option<String>,
    pub cover_view_url: Option<String>,
}

fn with_query(path: &str, query: &[(String, String)], order: &str) -> String {
    let mut url = format!("{path}?");
    for (key, value) in query.iter().filter(|(key, _)| key != "ob") {
        url.push_str(&format!(
            "{}={}&",
            encode_component(key),
            encode_component(value)
        ));
    }
    url.push_str(&format!("ob={order}"));
    url
}

impl ReleaseList {
    pub fn new(path: &str, input: &Input, rows: Vec<ReleaseRow>, total: u64) -> Self {
        let query = input.query_pairs();
        let requested = input.str("ob");
        let current = if requested.is_empty() {
            DEFAULT_ORDER
        } else {
            order_or_default(&requested)
        };
        let current_entry = ORDERINGS
            .iter()
            .find(|(key, _, _)| *key == current)
            .copied();
        let (sort_label, sort_icon) = current_entry.map_or(
            ("Posted (Newest)", "fa-calendar-alt"),
            |(_, label, icon)| (label, icon),
        );
        let headers = [
            ("name", "Name"),
            ("cat", "Category"),
            ("added", "Added"),
            ("size", "Size"),
            ("files", "Files"),
            ("stats", "Stats"),
        ]
        .into_iter()
        .map(|(key, label)| {
            let ascending = current == format!("{key}_asc");
            let descending = current == format!("{key}_desc");
            let next = if ascending { "desc" } else { "asc" };
            SortHeader {
                key,
                label,
                url: with_query(path, &query, &format!("{key}_{next}")),
                aria: if ascending {
                    "ascending"
                } else if descending {
                    "descending"
                } else {
                    "none"
                },
                icon: if ascending {
                    "fa-arrow-up"
                } else if descending {
                    "fa-arrow-down"
                } else {
                    "fa-sort"
                },
            }
        })
        .collect();
        let sort_options = ORDERINGS
            .iter()
            .map(|(key, label, icon)| SortOption {
                label,
                icon,
                url: with_query(path, &query, key),
                current: *key == current,
            })
            .collect();
        Self {
            rows,
            pager: Pager::new(path, &query, total, per_page(), input.page()),
            headers,
            sort_options,
            sort_label,
            sort_icon,
            show_thumbs: input.str("thumbs") == "1",
            inline_search_url: "/search".to_owned(),
            search_placeholder: "Search...".to_owned(),
            tv_rss_url: None,
            cover_view_url: None,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn orders_only_come_from_the_list() {
        assert_eq!(order_or_default("size_asc"), "size_asc");
        assert_eq!(order_or_default("1; DROP TABLE releases"), DEFAULT_ORDER);
        assert_eq!(order_sql("files_desc"), ("totalpart", "DESC"));
        assert_eq!(order_sql("name_asc"), ("searchname", "ASC"));
    }

    #[test]
    fn report_reasons_read_as_labels() {
        assert_eq!(
            reasons_label("spam, fake, spam"),
            "Spam/Advertisement, Fake/Malicious Content"
        );
        assert_eq!(reasons_label("custom"), "Custom");
    }

    #[test]
    fn sort_links_keep_the_filters_and_drop_the_page() {
        let input = Input::from_query("t=5000&page=3&ob=size_asc");
        let list = ReleaseList::new("/browse/All", &input, Vec::new(), 100);
        let size = list
            .headers
            .iter()
            .find(|header| header.key == "size")
            .unwrap();
        assert_eq!(size.url, "/browse/All?t=5000&ob=size_desc");
        assert_eq!(size.aria, "ascending");
        assert_eq!(list.sort_label, "Size (Smallest)");
        assert_eq!(list.pager.current, 3);
    }
}
