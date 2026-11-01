//! The JSON API feeds.

use anyhow::anyhow;
use async_trait::async_trait;
use chrono::{NaiveDateTime, TimeZone};
use serde_json::Value;

use super::super::http::{FeedHttp, read_json};
use super::super::{
    Entry, FeedError, FeedResult, FeedSource, PRE_MODNUKE, PRE_NONUKE, PRE_NUKED, PRE_OLDNUKE,
    PRE_RENUKED, PRE_UNNUKED, files_from_count, from_unix, size_from_megabytes,
};

/// `is_numeric()` then `(float)`.
fn number(value: &Value) -> Option<f64> {
    match value {
        Value::Number(n) => n.as_f64(),
        Value::String(s) => s.trim().parse::<f64>().ok().filter(|f| f.is_finite()),
        _ => None,
    }
}

fn integer(value: &Value) -> Option<i64> {
    number(value).map(|n| n as i64)
}

fn text(value: &Value) -> Option<&str> {
    value.as_str().filter(|text| !text.is_empty())
}

fn title(value: &Value) -> Option<&str> {
    value
        .as_str()
        .map(str::trim)
        .filter(|title| !title.is_empty())
}

fn opt(value: &Value) -> Option<String> {
    text(value).map(str::to_owned)
}

fn rows<'a>(value: Option<&'a Value>, message: &str) -> FeedResult<&'a Vec<Value>> {
    value
        .and_then(Value::as_array)
        .ok_or_else(|| FeedError::Failed(anyhow!("{message}")))
}

/// A `{"status":"error"}` envelope, which may mean throttling.
fn check_envelope(body: &Value, label: &str) -> FeedResult<()> {
    if body["status"].as_str() != Some("error") {
        return Ok(());
    }
    let message = body["message"].as_str().unwrap_or("unknown");
    if message.to_lowercase().contains("rate limit") {
        return Err(FeedError::rate_limited(format!(
            "{label} rate limited: {message}"
        )));
    }
    Err(FeedError::Failed(anyhow!("{label} API error: {message}")))
}

pub struct PredbClubSource {
    endpoint: String,
    page_size: usize,
    http: FeedHttp,
}

impl PredbClubSource {
    pub fn new(endpoint: String, page_size: usize, http: FeedHttp) -> Self {
        Self {
            endpoint,
            page_size,
            http,
        }
    }

    pub fn parse_rows(rows: &[Value]) -> Vec<Entry> {
        rows.iter()
            .filter_map(|row| {
                let name = title(&row["name"])?;
                let (nuked, nuke_reason) = club_nuke(&row["nuke"]);
                Some(Entry {
                    category: opt(&row["cat"]),
                    size: size_from_megabytes(number(&row["size"])),
                    files: files_from_count(integer(&row["files"])),
                    predate: integer(&row["preAt"]).and_then(from_unix),
                    nuked,
                    nuke_reason,
                    ..Entry::new(name, "predb.club")
                })
            })
            .collect()
    }
}

fn club_nuke(nuke: &Value) -> (i8, Option<String>) {
    if !nuke.is_object() {
        return (PRE_NONUKE, None);
    }
    let status = match nuke["type"]
        .as_str()
        .unwrap_or("nuke")
        .to_lowercase()
        .as_str()
    {
        "unnuke" | "undelpre" => PRE_UNNUKED,
        "modnuke" => PRE_MODNUKE,
        "renuke" => PRE_RENUKED,
        "oldnuke" => PRE_OLDNUKE,
        _ => PRE_NUKED,
    };
    (status, opt(&nuke["reason"]))
}

#[async_trait]
impl FeedSource for PredbClubSource {
    fn key(&self) -> &str {
        "predb_club"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        let query = [
            ("count", self.page_size.min(100).to_string()),
            ("page", page.max(1).to_string()),
        ];
        let body = self.http.get_json(&self.endpoint, &query).await?;
        check_envelope(&body, "predb.club")?;
        let rows = rows(
            body["data"].get("rows"),
            "predb.club returned an unexpected response (no data.rows).",
        )?;
        Ok(Self::parse_rows(rows))
    }
}

pub struct PredbNetSource {
    endpoint: String,
    page_size: usize,
    http: FeedHttp,
}

impl PredbNetSource {
    /// The API rejects later pages with HTTP 400.
    pub const MAX_PAGE: u32 = 100;

    pub fn new(endpoint: String, page_size: usize, http: FeedHttp) -> Self {
        Self {
            endpoint,
            page_size,
            http,
        }
    }

    pub fn parse_rows(rows: &[Value]) -> Vec<Entry> {
        rows.iter()
            .filter_map(|row| {
                let name = title(&row["release"])?;
                let status = integer(&row["status"]).unwrap_or(0);
                Some(Entry {
                    category: opt(&row["section"]),
                    size: size_from_megabytes(number(&row["size"])),
                    files: files_from_count(integer(&row["files"])),
                    predate: integer(&row["pretime"]).and_then(from_unix),
                    nuked: match status {
                        0 => PRE_NONUKE,
                        2 | 4 => PRE_UNNUKED,
                        _ => PRE_NUKED,
                    },
                    nuke_reason: if status == 0 {
                        None
                    } else {
                        opt(&row["reason"])
                    },
                    ..Entry::new(name, "predb.net")
                })
            })
            .collect()
    }
}

#[async_trait]
impl FeedSource for PredbNetSource {
    fn key(&self) -> &str {
        "predb_net"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        if page > Self::MAX_PAGE {
            return Ok(Vec::new());
        }
        let query = [
            ("limit", self.page_size.to_string()),
            ("page", page.max(1).to_string()),
        ];
        let body = self.http.get_json(&self.endpoint, &query).await?;
        check_envelope(&body, "predb.net")?;
        let rows = rows(
            body.get("data"),
            "predb.net returned an unexpected response (no data).",
        )?;
        Ok(Self::parse_rows(rows))
    }
}

pub struct PredbOrgSource {
    endpoint: String,
    page_size: usize,
    http: FeedHttp,
}

impl PredbOrgSource {
    pub fn new(endpoint: String, page_size: usize, http: FeedHttp) -> Self {
        Self {
            endpoint,
            page_size,
            http,
        }
    }

    pub fn parse_rows(rows: &[Value]) -> Vec<Entry> {
        rows.iter()
            .filter_map(|row| {
                let name = title(&row["rlsname"])?;
                let reason = opt(&row["nukereason"]);
                // `status` 1 is a nuke, whose reason can be empty.
                let status = match &row["status"] {
                    Value::String(s) => s == "1",
                    Value::Number(n) => n.as_i64() == Some(1),
                    _ => false,
                };
                Some(Entry {
                    category: opt(&row["section"]),
                    size: size_from_megabytes(number(&row["size"])),
                    files: files_from_count(integer(&row["files"])),
                    predate: integer(&row["ctime"]).and_then(from_unix),
                    nuked: if status || reason.is_some() {
                        PRE_NUKED
                    } else {
                        PRE_NONUKE
                    },
                    nuke_reason: reason,
                    ..Entry::new(name, "predb.org")
                })
            })
            .collect()
    }
}

#[async_trait]
impl FeedSource for PredbOrgSource {
    fn key(&self) -> &str {
        "predb_org"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        let query = [
            // `q` is required; `*` lists every release, newest first.
            ("q", "*".to_owned()),
            ("limit", self.page_size.min(100).to_string()),
            ("page", page.max(1).to_string()),
        ];
        let body = self.http.get_json(&self.endpoint, &query).await?;
        let rows = rows(
            body.get("values"),
            "predb.org returned an unexpected response (no values).",
        )?;
        Ok(Self::parse_rows(rows))
    }
}

pub struct PredbOvhSource {
    endpoint: String,
    page_size: usize,
    http: FeedHttp,
}

impl PredbOvhSource {
    pub fn new(endpoint: String, page_size: usize, http: FeedHttp) -> Self {
        Self {
            endpoint,
            page_size,
            http,
        }
    }

    pub fn parse_rows(rows: &[Value]) -> Vec<Entry> {
        rows.iter()
            .filter_map(|row| {
                let name = title(&row["name"])?;
                let mut nuke = row["nuke"].is_object().then_some(&row["nuke"]);
                // Explicit, valid PRE identifiers must refer to the same row.
                if let (Some(id), Some(pre)) =
                    (pre_id(&row["id"]), nuke.and_then(|n| pre_id(&n["preId"])))
                    && id != pre
                {
                    nuke = None;
                }
                let type_id = nuke.and_then(|n| integer(&n["typeId"])).unwrap_or(0);
                Some(Entry {
                    category: opt(&row["cat"]),
                    size: size_from_megabytes(number(&row["size"]).map(|kb| kb / 1024.0)),
                    files: files_from_count(integer(&row["files"])),
                    predate: integer(&row["preAt"]).and_then(from_unix),
                    nuked: match type_id {
                        0 => PRE_NONUKE,
                        2 | 5 => PRE_UNNUKED,
                        3 => PRE_MODNUKE,
                        _ => PRE_NUKED,
                    },
                    nuke_reason: if type_id == 0 {
                        None
                    } else {
                        nuke.and_then(|n| opt(&n["reason"]))
                    },
                    ..Entry::new(name, "predb.ovh")
                })
            })
            .collect()
    }
}

/// A canonical positive uint64 id, without narrowing through integers or floats.
fn pre_id(value: &Value) -> Option<String> {
    match value {
        Value::Number(n) => n.as_i64().filter(|id| *id > 0).map(|id| id.to_string()),
        Value::String(s) if !s.is_empty() && s.bytes().all(|b| b.is_ascii_digit()) => {
            let id = s.trim_start_matches('0');
            let fits = !id.is_empty()
                && (id.len() < 20 || (id.len() == 20 && id <= "18446744073709551615"));
            fits.then(|| id.to_owned())
        }
        _ => None,
    }
}

#[async_trait]
impl FeedSource for PredbOvhSource {
    fn key(&self) -> &str {
        "predb_ovh"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        let query = [
            ("count", self.page_size.to_string()),
            ("page", page.max(1).to_string()),
        ];
        let body = self.http.get_json(&self.endpoint, &query).await?;
        check_envelope(&body, "predb.ovh")?;
        let rows = rows(
            body["data"].get("rows"),
            "predb.ovh returned an unexpected response (no data.rows).",
        )?;
        Ok(Self::parse_rows(rows))
    }
}

pub struct SrrdbSource {
    endpoint: String,
    http: FeedHttp,
    timezone: String,
}

impl SrrdbSource {
    pub const PAGE_SIZE: u32 = 45;

    pub fn new(endpoint: String, http: FeedHttp, timezone: String) -> Self {
        Self {
            endpoint,
            http,
            timezone,
        }
    }

    pub fn page_url(endpoint: &str, page: u32) -> String {
        let endpoint = endpoint.trim_end_matches('/');
        if page > 1 {
            format!("{endpoint}/skip:{}", (page - 1) * Self::PAGE_SIZE)
        } else {
            endpoint.to_owned()
        }
    }

    pub fn parse_rows(&self, rows: &[Value]) -> Vec<Entry> {
        rows.iter()
            .filter_map(|row| {
                let name = title(&row["release"])?;
                // `date` is when the SRR was added and `size` can describe only an NFO, so neither
                // is PRE data. The date only drives paging.
                Some(Entry {
                    listed_at: row["date"]
                        .as_str()
                        .and_then(|date| srrdb_date(date, &self.timezone)),
                    ..Entry::new(name, "srrdb")
                })
            })
            .collect()
    }
}

/// srrDB reports its local time. Only `Europe/Brussels`, the default, has zone rules here; any
/// other value is treated as UTC, which only shifts how far a history import pages.
fn srrdb_date(value: &str, timezone: &str) -> Option<NaiveDateTime> {
    let local = NaiveDateTime::parse_from_str(value.trim(), "%Y-%m-%d %H:%M:%S").ok()?;
    if timezone != "Europe/Brussels" {
        return Some(local);
    }
    // EU summer time runs from the last Sunday of March to the last Sunday of October, 01:00 UTC.
    use chrono::{Datelike, Duration, Utc};
    let year = local.year();
    let last_sunday = |month: u32| {
        let last = chrono::NaiveDate::from_ymd_opt(year, month, 31)?;
        let back = last.weekday().num_days_from_sunday();
        Some(last - Duration::days(i64::from(back)))
    };
    let start = last_sunday(3)?.and_hms_opt(1, 0, 0)?;
    let end = last_sunday(10)?.and_hms_opt(1, 0, 0)?;
    let summer = local - Duration::hours(2);
    let offset = if summer >= start && summer < end {
        2
    } else {
        1
    };
    Some(
        Utc.from_utc_datetime(&(local - Duration::hours(offset)))
            .naive_utc(),
    )
}

#[async_trait]
impl FeedSource for SrrdbSource {
    fn key(&self) -> &str {
        "srrdb"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        let url = Self::page_url(&self.endpoint, page.max(1));
        let body = self.http.get_json(&url, &[]).await?;
        let rows = rows(
            body.get("results"),
            "srrDB returned an unexpected response (no results list).",
        )?;
        Ok(self.parse_rows(rows))
    }
}

pub struct XrelSource {
    endpoint: String,
    page_size: usize,
    http: FeedHttp,
    p2p: bool,
}

impl XrelSource {
    pub fn new(endpoint: String, page_size: usize, http: FeedHttp, p2p: bool) -> Self {
        Self {
            endpoint,
            page_size,
            http,
            p2p,
        }
    }

    pub fn parse_rows(&self, rows: &[Value]) -> Vec<Entry> {
        rows.iter()
            .filter_map(|row| {
                let name = title(&row["dirname"])?;
                Some(if self.p2p {
                    p2p_entry(name, row)
                } else {
                    scene_entry(name, row)
                })
            })
            .collect()
    }
}

fn truthy(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Bool(b) => *b,
        Value::Number(n) => n.as_f64() != Some(0.0),
        Value::String(s) => !s.is_empty() && s != "0",
        Value::Array(a) => !a.is_empty(),
        Value::Object(o) => !o.is_empty(),
    }
}

fn scene_entry(name: &str, row: &Value) -> Entry {
    let megabytes = number(&row["size"]["number"]).and_then(|size| {
        // Symbolic units such as RAR or NFO aren't byte sizes.
        match row["size"]["unit"]
            .as_str()
            .unwrap_or("")
            .to_uppercase()
            .as_str()
        {
            "KB" => Some(size / 1024.0),
            "MB" => Some(size),
            "GB" => Some(size * 1024.0),
            "TB" => Some(size * 1_048_576.0),
            _ => None,
        }
    });
    Entry {
        category: text(&row["ext_info"]["type"]).map(str::to_uppercase),
        size: size_from_megabytes(megabytes),
        predate: integer(&row["time"]).and_then(from_unix),
        nuked: if truthy(&row["flags"]["nuke_rls"]) {
            PRE_NUKED
        } else {
            PRE_NONUKE
        },
        ..Entry::new(name, "xrel")
    }
}

fn p2p_entry(name: &str, row: &Value) -> Entry {
    let meta = row["category"]["meta_cat"]
        .as_str()
        .unwrap_or("")
        .to_uppercase();
    let sub = row["category"]["sub_cat"].as_str().unwrap_or("");
    Entry {
        category: match (meta.is_empty(), sub.is_empty()) {
            (true, _) => None,
            (false, true) => Some(meta),
            (false, false) => Some(format!("{meta}-{sub}")),
        },
        size: size_from_megabytes(number(&row["size_mb"])),
        predate: integer(&row["pub_time"]).and_then(from_unix),
        ..Entry::new(name, "xrel-p2p")
    }
}

#[async_trait]
impl FeedSource for XrelSource {
    fn key(&self) -> &str {
        if self.p2p { "xrel_p2p" } else { "xrel" }
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        let query = [
            ("per_page", self.page_size.clamp(5, 100).to_string()),
            ("page", page.max(1).to_string()),
        ];
        let body = self.http.get_json(&self.endpoint, &query).await?;
        let total = integer(&body["pagination"]["total_pages"]).unwrap_or(-1);
        if total > 0 && i64::from(page) > total {
            return Ok(Vec::new());
        }
        let rows = rows(
            body.get("list"),
            "xREL returned an unexpected response (no list).",
        )?;
        Ok(self.parse_rows(rows))
    }
}

pub struct ApibaySource {
    endpoint: String,
    page_size: usize,
    http: FeedHttp,
}

impl ApibaySource {
    pub fn new(endpoint: String, page_size: usize, http: FeedHttp) -> Self {
        Self {
            endpoint,
            page_size,
            http,
        }
    }

    /// P2P names, not scene PREs. Only movie and TV categories are kept; everything else,
    /// including adult, is dropped. `added` is the upload time, not a verified PRE time.
    pub fn parse_rows(&self, rows: &[Value]) -> Vec<Entry> {
        let mut entries = Vec::new();
        for row in rows {
            let Some(name) = title(&row["name"]) else {
                continue;
            };
            let category = match integer(&row["category"]).unwrap_or(0) {
                201 => "MOVIE",
                207 => "MOVIE-HD",
                205 => "TV",
                208 => "TV-HD",
                _ => continue,
            };
            entries.push(Entry {
                category: Some(category.to_owned()),
                size: size_from_megabytes(number(&row["size"]).map(|b| b / 1_048_576.0)),
                files: files_from_count(integer(&row["num_files"])),
                listed_at: integer(&row["added"]).and_then(from_unix),
                ..Entry::new(name, "apibay")
            });
            if entries.len() >= self.page_size {
                break;
            }
        }
        entries
    }
}

#[async_trait]
impl FeedSource for ApibaySource {
    fn key(&self) -> &str {
        "apibay"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        if page > 1 {
            return Ok(Vec::new());
        }
        let body = self.http.get_json(&self.endpoint, &[]).await?;
        let rows = body
            .as_array()
            .ok_or_else(|| anyhow!("apibay returned an unexpected response (not a JSON list)."))?;
        Ok(self.parse_rows(rows))
    }
}

/// predataba.se returns ten rows a request, ignores `page`, and pages only through a cursor.
/// Anonymous clients may read five requests deep. One page chains `page_size / 10` requests, and
/// later pages continue the same cursor.
pub struct PredatabaseSource {
    endpoint: String,
    page_size: usize,
    http: FeedHttp,
    api_key: String,
    cursor: Option<String>,
    last_page: u32,
    requests: u32,
    pending_page: Option<u32>,
    pending_requests: usize,
    visited: std::collections::HashSet<String>,
    oldest: Option<i64>,
    pending: Vec<Entry>,
    completed: Vec<Entry>,
}

impl PredatabaseSource {
    pub const ANONYMOUS_REQUEST_LIMIT: u32 = 5;
    const ROWS_PER_REQUEST: usize = 10;
    const MAX_EMPTY_CONTINUATION_REQUESTS: usize = 5;

    pub fn new(endpoint: String, page_size: usize, http: FeedHttp, api_key: String) -> Self {
        Self {
            endpoint,
            page_size,
            http,
            api_key,
            cursor: None,
            last_page: 0,
            requests: 0,
            pending_page: None,
            pending_requests: 0,
            visited: std::collections::HashSet::new(),
            oldest: None,
            pending: Vec::new(),
            completed: Vec::new(),
        }
    }

    pub fn parse_rows(rows: &[Value]) -> Vec<Entry> {
        rows.iter()
            .filter_map(|row| {
                let name = title(&row["rlsname"])?;
                Some(Entry {
                    category: opt(&row["section"]),
                    size: size_from_megabytes(number(&row["size"])),
                    files: files_from_count(integer(&row["files"])),
                    predate: integer(&row["ctime"])
                        .filter(|t| *t > 0)
                        .and_then(from_unix),
                    ..Entry::new(name, "predataba.se")
                })
            })
            .collect()
    }

    async fn request(&mut self) -> FeedResult<(Vec<Value>, Option<String>)> {
        let anonymous = self.api_key.is_empty();
        let attempts = if anonymous {
            2.min(Self::ANONYMOUS_REQUEST_LIMIT.saturating_sub(self.requests))
        } else {
            2
        };
        let mut response = None;
        let mut last = None;
        for attempt in 0..attempts {
            if attempt > 0 {
                tokio::time::sleep(std::time::Duration::from_millis(500)).await;
            }
            self.requests += 1;
            let mut url = reqwest::Url::parse(&self.endpoint)?;
            if let Some(cursor) = &self.cursor {
                url.query_pairs_mut().append_pair("page_id", cursor);
            }
            let mut request = self.http.client().get(url);
            if !anonymous {
                request = request.bearer_auth(&self.api_key);
            }
            match request.send().await {
                Ok(sent) if sent.status().is_server_error() => {
                    last = Some(anyhow!("HTTP {}", sent.status()));
                }
                Ok(sent) => {
                    response = Some(sent);
                    break;
                }
                Err(error) if error.is_connect() || error.is_timeout() => last = Some(error.into()),
                Err(error) => return Err(FeedError::Failed(error.into())),
            }
        }
        let Some(response) = response else {
            return Err(FeedError::Failed(last.unwrap_or_else(|| {
                anyhow!("predataba.se anonymous wire request allowance is exhausted.")
            })));
        };
        // The API answers 403 when the anonymous rate or depth limit is exceeded.
        if response.status() == reqwest::StatusCode::FORBIDDEN {
            let body: Value = response.json().await.unwrap_or(Value::Null);
            let message = body["message"].as_str().unwrap_or("forbidden");
            return Err(FeedError::rate_limited(format!(
                "predataba.se rate limited: {message}"
            )));
        }
        if response.status() == reqwest::StatusCode::TOO_MANY_REQUESTS {
            return Err(super::super::http::throttled(response.headers()));
        }
        let body = read_json(response).await?;
        let rows = rows(
            body.get("results"),
            "predataba.se returned an unexpected response (no results).",
        )?
        .clone();
        let next = match &body["next_page_id"] {
            Value::Null => None,
            Value::String(next) if next.is_empty() => None,
            Value::String(next) => Some(next.clone()),
            _ => {
                return Err(FeedError::Failed(anyhow!(
                    "predataba.se returned a malformed history cursor."
                )));
            }
        };
        Ok((rows, next))
    }

    /// Unknown dates stay unknown; only an explicit reversal of known dates is rejected.
    fn validate_chronology(&self, rows: &[Value]) -> FeedResult<Option<i64>> {
        let mut oldest = self.oldest;
        for row in rows {
            let timestamp = integer(&row["ctime"]).unwrap_or(0);
            if timestamp <= 0 {
                continue;
            }
            if oldest.is_some_and(|known| timestamp > known) {
                return Err(FeedError::Failed(anyhow!(
                    "predataba.se returned reversed chronological history."
                )));
            }
            oldest = Some(timestamp);
        }
        Ok(oldest)
    }
}

#[async_trait]
impl FeedSource for PredatabaseSource {
    fn key(&self) -> &str {
        "predatabase"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        let page = page.max(1);
        if let Some(pending) = self.pending_page {
            if page != pending {
                return Err(FeedError::Failed(anyhow!(
                    "predataba.se has an unfinished page; retry it before fetching the next page."
                )));
            }
        } else {
            if page == self.last_page {
                return Ok(self.completed.clone());
            }
            if page != self.last_page + 1 {
                return Err(FeedError::Failed(anyhow!(
                    "predataba.se pages through a cursor; fetch pages in order starting at 1."
                )));
            }
            if self.last_page > 0 && self.cursor.is_none() {
                return Ok(Vec::new());
            }
            self.pending_page = Some(page);
            self.pending_requests = 0;
            self.pending.clear();
        }
        let per_page = self.page_size.div_ceil(Self::ROWS_PER_REQUEST).max(1);
        while self.pending_requests < per_page || (self.pending.is_empty() && self.cursor.is_some())
        {
            if self.pending_requests >= per_page + Self::MAX_EMPTY_CONTINUATION_REQUESTS {
                return Err(FeedError::Failed(anyhow!(
                    "predataba.se returned too many nonterminal pages without eligible titles; history coverage is incomplete."
                )));
            }
            if self.api_key.is_empty() && self.requests >= Self::ANONYMOUS_REQUEST_LIMIT {
                self.cursor = None;
                break;
            }
            let (rows, next) = self.request().await?;
            let entries = Self::parse_rows(&rows);
            if let Some(next) = &next
                && (Some(next) == self.cursor.as_ref() || self.visited.contains(next))
            {
                return Err(FeedError::Failed(anyhow!(
                    "predataba.se returned a repeated history cursor."
                )));
            }
            let oldest = self.validate_chronology(&rows)?;
            if let Some(cursor) = self.cursor.take() {
                self.visited.insert(cursor);
            }
            self.pending.extend(entries);
            self.oldest = oldest;
            self.cursor = next;
            self.pending_requests += 1;
            if self.cursor.is_none() {
                break;
            }
        }
        self.last_page = page;
        self.completed = std::mem::take(&mut self.pending);
        self.pending_page = None;
        Ok(self.completed.clone())
    }
}
