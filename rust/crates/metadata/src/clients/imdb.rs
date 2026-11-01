//! IMDb: scrapes imdb.com, falling back to imdbapi.dev, and backs off when a WAF blocks us. Port of
//! `ImdbScraper`.

use std::collections::HashSet;
use std::sync::LazyLock;
use std::time::Duration;

use anyhow::Result;
use nntmux_core::parsed_or;
use nntmux_core::pcre::Pcre;
use reqwest::Client;
use scraper::{ElementRef, Html, Selector};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use tracing::{debug, info, warn};

use crate::cache::Cache;
use crate::php;

const SOFT_FAILURE_TTL: Duration = Duration::from_secs(30 * 60);
const HARD_FAILURE_TTL: Duration = Duration::from_secs(6 * 3600);
const WEEK: Duration = Duration::from_secs(7 * 24 * 3600);
const INTERVAL_KEY: &str = "imdbapi_dev:min_interval";
const COOLDOWN_KEY: &str = "imdbapi_dev:cooldown";
const BACKOFF_KEY: &str = "imdb_scraper:waf_backoff";
const BLOCKED_KEY: &str = "imdb_scraper:waf_blocked";
const PROBE_KEY: &str = "imdb_scraper:waf_probe";
const PROBE_LOCK: Duration = Duration::from_secs(120);
const HTML_ACCEPT: &str =
    "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8";

static JSON_LD: LazyLock<Pcre> = LazyLock::new(|| {
    Pcre::new(r#"/<script[^>]*type=["']application\/ld\+json["'][^>]*>(.*?)<\/script>/is"#)
        .expect("a valid regex")
});
static YEAR: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/(19|20)\d{2}/").expect("a valid regex"));
static IMAGE_SIZE: LazyLock<regex::Regex> =
    LazyLock::new(|| regex::Regex::new(r"\._V1_.*\.").expect("a valid regex"));
static RATING: LazyLock<regex::Regex> =
    LazyLock::new(|| regex::Regex::new(r"\d+(?:\.\d+)?").expect("a valid regex"));
static TITLE_SUFFIX: LazyLock<regex::Regex> =
    LazyLock::new(|| regex::Regex::new(r"\s+\(.*?\)$").expect("a valid regex"));
static OG_SUFFIX: LazyLock<regex::Regex> =
    LazyLock::new(|| regex::Regex::new(r"\s+-\s+IMDb$").expect("a valid regex"));
static SEARCH_LINK: LazyLock<Pcre> = LazyLock::new(|| {
    Pcre::new(r#"#<a[^>]+href=["']/title/tt(?P<id>\d{5,8})/["'][^>]*>(?P<title>.*?)</a>#is"#)
        .expect("a valid regex")
});

/// A title's metadata.
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct ImdbTitle {
    pub imdbid: String,
    pub title: String,
    pub year: String,
    pub plot: String,
    pub rating: String,
    pub cover: String,
    pub genre: Vec<String>,
    pub director: Vec<String>,
    pub actors: Vec<String>,
    pub language: String,
    pub kind: String,
}

/// A search match.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct ImdbMatch {
    pub imdbid: String,
    pub title: String,
    pub year: String,
}

/// What `fetchById` and its `was*` getters said together.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Fetched {
    pub title: Option<ImdbTitle>,
    /// No request was made, because every source was off or paused.
    pub skipped: bool,
    pub blocked: bool,
    pub reason: Option<String>,
}

#[derive(Clone)]
pub struct Imdb {
    http: Client,
    cache: Cache,
    pub scraping: bool,
    pub api_dev: bool,
    pub title_url: String,
    pub suggestion_url: String,
    pub find_url: String,
    pub api_dev_url: String,
    backoff: Duration,
    min_interval: u64,
    cooldown: u64,
}

fn env_bool(key: &str, default: bool) -> bool {
    nntmux_core::optional(key).map_or(default, |value| {
        matches!(
            value.trim().to_ascii_lowercase().as_str(),
            "1" | "true" | "on" | "yes"
        )
    })
}

impl Imdb {
    pub fn from_env(cache: Cache) -> Result<Self> {
        let http = Client::builder()
            .timeout(Duration::from_secs(10))
            .connect_timeout(Duration::from_secs(10))
            .user_agent("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36")
            .default_headers(
                [
                    (reqwest::header::ACCEPT_LANGUAGE, "en-US,en;q=0.9"),
                    (reqwest::header::CACHE_CONTROL, "no-cache"),
                    (reqwest::header::PRAGMA, "no-cache"),
                ]
                .into_iter()
                .map(|(name, value)| (name, reqwest::header::HeaderValue::from_static(value)))
                .collect(),
            )
            .build()?;
        Ok(Self {
            http,
            cache,
            scraping: env_bool("IMDB_SCRAPER_ENABLED", true),
            api_dev: env_bool("IMDBAPI_DEV_ENABLED", true),
            title_url: "https://www.imdb.com/title".to_owned(),
            suggestion_url: "https://v2.sg.media-imdb.com/suggestion".to_owned(),
            find_url: "https://www.imdb.com/find/".to_owned(),
            api_dev_url: nntmux_core::var_or("IMDBAPI_DEV_BASE_URL", "https://api.imdbapi.dev")
                .trim_end_matches('/')
                .to_owned(),
            backoff: Duration::from_secs(
                parsed_or::<u64>("IMDB_SCRAPER_BLOCK_BACKOFF_MINUTES", 60)?.max(1) * 60,
            ),
            min_interval: parsed_or("IMDBAPI_DEV_MIN_INTERVAL_SECONDS", 15)?,
            cooldown: parsed_or("IMDBAPI_DEV_COOLDOWN_SECONDS", 300)?,
        })
    }

    pub fn is_enabled(&self) -> bool {
        self.scraping || self.api_dev
    }

    async fn backoff_active(&self) -> bool {
        self.cache.has(BLOCKED_KEY).await
    }

    /// `fetchById`. `id` is the numeric part, without `tt`.
    pub async fn fetch_by_id(&self, id: &str) -> Fetched {
        let id: String = id.chars().filter(char::is_ascii_digit).collect();
        if !(5..=8).contains(&id.len()) {
            return Fetched {
                reason: Some("invalid_id".to_owned()),
                ..Fetched::default()
            };
        }
        let key = format!("imdb_scrape_id_{id}");
        if let Some(cached) = self.cache.get_entry::<ImdbTitle>(&key).await {
            return Fetched {
                title: cached,
                ..Fetched::default()
            };
        }
        if !self.scraping || !self.acquire_scrape_slot().await {
            return self.fetch_without_scraping(&id, &key).await;
        }
        let mut fetched = Fetched::default();
        let sent = self
            .http
            .get(format!("{}/tt{id}/", self.title_url))
            .header(reqwest::header::ACCEPT, HTML_ACCEPT)
            .header(reqwest::header::REFERER, "https://www.imdb.com/")
            .header("Upgrade-Insecure-Requests", "1")
            .send()
            .await;
        let answered = match sent {
            Ok(response) => {
                let status = response.status().as_u16();
                response.text().await.map(|body| (status, body))
            }
            Err(failure) => Err(failure),
        };
        match answered {
            Ok((status, html)) => {
                if is_waf_response(status, &html) {
                    fetched.blocked = true;
                    fetched.reason = Some("waf_block".to_owned());
                    self.start_backoff().await;
                } else {
                    self.end_backoff().await;
                }
                if !fetched.blocked && status < 400 && !php::trim(&html).is_empty() {
                    if let Some(title) = parse_title_html(&html, &id) {
                        self.cache.put_entry(&key, Some(&title), WEEK).await;
                        fetched.reason = None;
                        fetched.title = Some(title);
                        return fetched;
                    }
                    fetched.reason = Some("html_parse_failure".to_owned());
                } else if !fetched.blocked {
                    fetched.reason = Some(
                        if php::trim(&html).is_empty() {
                            "html_empty_response"
                        } else {
                            "html_http_failure"
                        }
                        .to_owned(),
                    );
                }
            }
            Err(failure) => {
                debug!(id, error = %failure.without_url(), "IMDb fetch error");
                self.cache.forget(PROBE_KEY).await;
                fetched.reason = Some("html_exception".to_owned());
            }
        }
        if let Some(title) = self.fetch_from_api_dev(&id).await {
            self.cache.put_entry(&key, Some(&title), WEEK).await;
            fetched.reason = None;
            fetched.title = Some(title);
            return fetched;
        }
        let ttl = if fetched.blocked {
            SOFT_FAILURE_TTL
        } else {
            HARD_FAILURE_TTL
        };
        self.cache.put_entry::<ImdbTitle>(&key, None, ttl).await;
        fetched
    }

    async fn fetch_without_scraping(&self, id: &str, key: &str) -> Fetched {
        let mut fetched = Fetched {
            blocked: self.scraping,
            reason: Some(
                if self.scraping {
                    "waf_backoff"
                } else {
                    "scraper_disabled"
                }
                .to_owned(),
            ),
            ..Fetched::default()
        };
        if !self.can_use_api_dev().await {
            fetched.skipped = true;
            return fetched;
        }
        if let Some(title) = self.fetch_from_api_dev(id).await {
            self.cache.put_entry(key, Some(&title), WEEK).await;
            fetched.reason = None;
            fetched.title = Some(title);
            return fetched;
        }
        self.cache
            .put_entry::<ImdbTitle>(key, None, SOFT_FAILURE_TTL)
            .await;
        fetched
    }

    /// While blocked, lets a single worker probe imdb.com once the back-off has expired.
    async fn acquire_scrape_slot(&self) -> bool {
        if !self.cache.has(BLOCKED_KEY).await {
            return true;
        }
        if self.cache.has(BACKOFF_KEY).await {
            return false;
        }
        self.cache.add(PROBE_KEY, PROBE_LOCK).await
    }

    async fn start_backoff(&self) {
        self.cache.put(BACKOFF_KEY, &true, self.backoff).await;
        self.cache.forget(PROBE_KEY).await;
        if self.cache.add(BLOCKED_KEY, WEEK).await {
            warn!(
                minutes = self.backoff.as_secs() / 60,
                "IMDb scraping blocked by WAF, pausing IMDb scraping"
            );
        } else {
            self.cache.put(BLOCKED_KEY, &true, WEEK).await;
        }
    }

    async fn end_backoff(&self) {
        if !self.cache.has(BLOCKED_KEY).await {
            return;
        }
        self.cache.forget(BLOCKED_KEY).await;
        self.cache.forget(BACKOFF_KEY).await;
        self.cache.forget(PROBE_KEY).await;
        info!("IMDb scraping no longer blocked by WAF, lookups resumed");
    }

    async fn can_use_api_dev(&self) -> bool {
        self.api_dev
            && !self.cache.has(COOLDOWN_KEY).await
            && !(self.min_interval > 0 && self.cache.has(INTERVAL_KEY).await)
    }

    async fn activate_cooldown(&self) {
        if self.cooldown > 0 {
            self.cache
                .put(COOLDOWN_KEY, &true, Duration::from_secs(self.cooldown))
                .await;
        }
    }

    async fn fetch_from_api_dev(&self, id: &str) -> Option<ImdbTitle> {
        if !self.can_use_api_dev().await || self.api_dev_url.is_empty() {
            return None;
        }
        if self.min_interval > 0 {
            self.cache
                .put(INTERVAL_KEY, &true, Duration::from_secs(self.min_interval))
                .await;
        }
        let sent = self
            .http
            .get(format!("{}/titles/tt{id}", self.api_dev_url))
            .header(reqwest::header::ACCEPT, "application/json")
            .send()
            .await;
        let response = match sent {
            Ok(response) => response,
            Err(failure) => {
                debug!(id, error = %failure.without_url(), "imdbapi.dev fetch error");
                self.activate_cooldown().await;
                return None;
            }
        };
        let status = response.status().as_u16();
        if status != 200 {
            if status == 429 || status >= 500 {
                self.activate_cooldown().await;
            }
            return None;
        }
        let payload: Value = response.json().await.ok()?;
        map_api_dev_title(&payload, id)
    }

    /// `search`: the suggestion API, then the HTML search page.
    pub async fn search(&self, query: &str) -> Vec<ImdbMatch> {
        let query = php::trim(query);
        let normalized: String = query
            .chars()
            .filter(|c| c.is_ascii_alphanumeric() || *c == ' ')
            .collect::<String>()
            .to_ascii_lowercase();
        let slug = normalized.split_whitespace().collect::<Vec<_>>().join("_");
        let Some(prefix) = slug.get(..1) else {
            return Vec::new();
        };
        let digest = hex::encode(<md5::Md5 as md5::Digest>::digest(slug.as_bytes()));
        let key = format!("imdb_search_{digest}");
        if let Some(cached) = self.cache.get::<Vec<ImdbMatch>>(&key).await {
            return cached;
        }
        if !self.scraping {
            return Vec::new();
        }
        let sent = self
            .http
            .get(format!("{}/{prefix}/{slug}.json", self.suggestion_url))
            .header(reqwest::header::ACCEPT, "application/json,text/plain,*/*")
            .header(reqwest::header::ORIGIN, "https://www.imdb.com")
            .send()
            .await;
        let answered = match sent {
            Ok(response) => {
                let status = response.status().as_u16();
                response.text().await.map(|body| (status, body))
            }
            Err(failure) => Err(failure),
        };
        let (status, body) = match answered {
            Ok(answer) => answer,
            Err(failure) => {
                debug!(query, error = %failure.without_url(), "IMDb search error");
                self.cache
                    .put(&key, &Vec::<ImdbMatch>::new(), HARD_FAILURE_TTL)
                    .await;
                return Vec::new();
            }
        };
        let mut results = Vec::new();
        if status == 200 && !is_waf_response(status, &body) {
            results = parse_suggestions(&body);
        }
        if results.is_empty() && !self.backoff_active().await {
            results = self.search_html(query).await;
        }
        if results.is_empty() {
            let ttl = if self.backoff_active().await {
                SOFT_FAILURE_TTL
            } else {
                HARD_FAILURE_TTL
            };
            self.cache.put(&key, &results, ttl).await;
            return results;
        }
        self.cache
            .put(&key, &results, Duration::from_secs(12 * 3600))
            .await;
        results
    }

    async fn search_html(&self, query: &str) -> Vec<ImdbMatch> {
        let sent = self
            .http
            .get(&self.find_url)
            .query(&[("q", query), ("s", "tt")])
            .header(reqwest::header::ACCEPT, HTML_ACCEPT)
            .header(reqwest::header::REFERER, "https://www.imdb.com/")
            .send()
            .await;
        let Ok(response) = sent else {
            return Vec::new();
        };
        let status = response.status().as_u16();
        let Ok(html) = response.text().await else {
            return Vec::new();
        };
        if is_waf_response(status, &html) {
            self.start_backoff().await;
            return Vec::new();
        }
        if status >= 400 || php::trim(&html).is_empty() {
            return Vec::new();
        }
        parse_search_html(&html)
    }
}

fn is_waf_response(status: u16, body: &str) -> bool {
    if status == 202 {
        return true;
    }
    let body = body.to_lowercase();
    [
        "window.awswafcookiedomainlist",
        "window.gokuprops",
        "challenge.js",
        "captcha",
        "awswaf",
    ]
    .iter()
    .any(|marker| body.contains(marker))
}

fn year_in(text: &str) -> String {
    YEAR.captures(text.as_bytes())
        .and_then(|found| {
            found
                .group(0)
                .map(|year| php::bytes_to_string(year.to_vec()))
        })
        .unwrap_or_default()
}

fn looks_like_title(value: &Value) -> bool {
    let kind = php::text(value.get("@type")).to_ascii_lowercase();
    matches!(
        kind.as_str(),
        "movie" | "tvseries" | "tvminiseries" | "tvepisode" | "tvmovie" | "video"
    ) || (value.get("name").is_some_and(|name| !name.is_null())
        && ["actor", "director", "aggregateRating"]
            .iter()
            .any(|key| value.get(*key).is_some_and(|field| !field.is_null())))
}

fn find_title_entity(value: &Value) -> Option<&Value> {
    if value.is_object() && looks_like_title(value) {
        return Some(value);
    }
    match value {
        Value::Object(fields) => fields.values().find_map(find_title_entity),
        Value::Array(items) => items.iter().find_map(find_title_entity),
        _ => None,
    }
}

fn primary_json_ld(html: &str) -> Value {
    for found in JSON_LD.captures_all(html.as_bytes()) {
        let Some(json) = found.group(1) else {
            continue;
        };
        let text = php::entity_decode(php::trim(&String::from_utf8_lossy(json)));
        let Ok(decoded) = serde_json::from_str::<Value>(&text) else {
            continue;
        };
        if let Some(entity) = find_title_entity(&decoded) {
            return entity.clone();
        }
    }
    Value::Null
}

/// `normalizeValueList`: a list stays, anything else is one item.
fn value_list(value: Option<&Value>) -> Vec<&Value> {
    match value {
        None | Some(Value::Null) => Vec::new(),
        Some(Value::String(text)) if text.is_empty() => Vec::new(),
        Some(Value::Array(items)) => items.iter().collect(),
        Some(other) => vec![other],
    }
}

fn unique(items: Vec<String>) -> Vec<String> {
    let mut seen = HashSet::new();
    items
        .into_iter()
        .filter(|item| seen.insert(item.clone()))
        .collect()
}

fn string_list(value: Option<&Value>) -> Vec<String> {
    unique(
        value_list(value)
            .into_iter()
            .filter_map(Value::as_str)
            .map(|item| php::trim(item).to_owned())
            .filter(|item| !item.is_empty())
            .collect(),
    )
}

fn people(value: Option<&Value>, keys: &[&str]) -> Vec<String> {
    unique(
        value_list(value)
            .into_iter()
            .map(|item| match item {
                Value::Object(_) => keys
                    .iter()
                    .find_map(|key| item.get(*key).filter(|name| !name.is_null()))
                    .map(|name| php::text(Some(name)))
                    .unwrap_or_default(),
                other => php::text(Some(other)),
            })
            .map(|name| php::trim(&name).to_owned())
            .filter(|name| !name.is_empty())
            .collect(),
    )
}

fn normalize_image(image: Option<&Value>) -> String {
    let url = match image {
        Some(Value::String(url)) => php::trim(url).to_owned(),
        Some(Value::Object(_)) => php::text(image.and_then(|image| image.get("url"))),
        Some(Value::Array(items)) => match items.first() {
            Some(first @ Value::Object(_)) => php::text(first.get("url")),
            first => php::text(first),
        },
        _ => String::new(),
    };
    let url = php::trim(&url);
    if url.contains("._V1_") {
        return IMAGE_SIZE.replace(url, ".").into_owned();
    }
    url.to_owned()
}

fn selector(css: &str) -> Selector {
    Selector::parse(css).expect("a valid selector")
}

fn node_text(node: ElementRef<'_>) -> String {
    php::trim(&node.text().collect::<String>()).to_owned()
}

fn first_text(dom: &Html, css: &str) -> String {
    dom.select(&selector(css))
        .next()
        .map(node_text)
        .unwrap_or_default()
}

fn first_attribute(dom: &Html, css: &str, attribute: &str) -> String {
    dom.select(&selector(css))
        .next()
        .and_then(|node| node.attr(attribute))
        .map(|value| php::trim(value).to_owned())
        .unwrap_or_default()
}

fn all_text(dom: &Html, css: &str) -> Vec<String> {
    unique(
        dom.select(&selector(css))
            .map(node_text)
            .filter(|text| !text.is_empty())
            .collect(),
    )
}

/// `li[data-testid='title-pc-principal-credit']:contains(Word) a`.
fn credit_people(dom: &Html) -> Vec<String> {
    let credits = selector("li[data-testid='title-pc-principal-credit']");
    let link = selector("a");
    let mut names = Vec::new();
    for word in ["Director", "Creator"] {
        for credit in dom.select(&credits) {
            if credit.text().collect::<String>().contains(word) {
                names.extend(
                    credit
                        .select(&link)
                        .map(node_text)
                        .filter(|name| !name.is_empty()),
                );
            }
        }
    }
    unique(names)
}

pub fn parse_title_html(html: &str, id: &str) -> Option<ImdbTitle> {
    if is_waf_response(200, html) {
        return None;
    }
    let json = primary_json_ld(html);
    let dom = Html::parse_document(html);

    let mut title = php::trim(&php::text(json.get("name"))).to_owned();
    if title.is_empty() {
        title = first_text(&dom, "h1");
        title = TITLE_SUFFIX.replace(&title, "").into_owned();
    }
    if title.is_empty() {
        title = first_attribute(&dom, "meta[property='og:title']", "content");
        title = OG_SUFFIX.replace(&title, "").into_owned();
    }
    if title.is_empty() {
        return None;
    }

    let mut year = year_in(&php::text(json.get("datePublished")));
    if year.is_empty() {
        year = year_in(&php::text(json.get("dateCreated")));
    }
    if year.is_empty() {
        for css in [
            "span[data-testid='title-details-releasedate'] a",
            "span[data-testid='title-details-releasedate']",
            "ul[data-testid='hero-title-block__metadata'] li",
        ] {
            year = dom
                .select(&selector(css))
                .map(|node| year_in(&node_text(node)))
                .find(|year| !year.is_empty())
                .unwrap_or_default();
            if !year.is_empty() {
                break;
            }
        }
    }

    let mut plot = php::trim(&php::text(json.get("description"))).to_owned();
    if plot.is_empty() {
        plot = first_text(&dom, "span[data-testid='plot-l']");
    }
    if plot.is_empty() {
        plot = first_text(&dom, "span[data-testid='plot-xl']");
    }
    if plot.is_empty() {
        plot = first_attribute(&dom, "meta[name='description']", "content");
    }

    let mut rating = php::trim(&php::text(
        json.get("aggregateRating")
            .and_then(|rating| rating.get("ratingValue")),
    ))
    .to_owned();
    if rating.is_empty() {
        let text = first_text(
            &dom,
            "div[data-testid='hero-rating-bar__aggregate-rating__score'] span",
        );
        rating = RATING
            .find(&text)
            .map(|found| found.as_str().to_owned())
            .unwrap_or_default();
    }

    let mut cover = normalize_image(json.get("image"));
    if cover.is_empty() {
        let mut found = first_attribute(&dom, "div[data-testid='hero-media__poster'] img", "src");
        if found.is_empty() {
            found = first_attribute(&dom, "meta[property='og:image']", "content");
        }
        cover = normalize_image(Some(&Value::String(found)));
    }

    let mut genre = string_list(json.get("genre"));
    if genre.is_empty() {
        genre = all_text(&dom, "div[data-testid='genres'] a");
    }
    let mut director = people(json.get("director"), &["name"]);
    if director.is_empty() {
        director = credit_people(&dom);
    }
    let mut actors = people(json.get("actor"), &["name"]);
    if actors.is_empty() {
        actors = all_text(
            &dom,
            "div[data-testid='title-cast-item'] a[data-testid='title-cast-item__actor']",
        );
    }
    actors.truncate(10);
    let mut languages = string_list(json.get("inLanguage"));
    if languages.is_empty() {
        languages = all_text(&dom, "li[data-testid='title-details-languages'] a");
    }
    let kind = match php::text(json.get("@type")).to_ascii_lowercase().as_str() {
        "tvseries" | "tvminiseries" | "tvepisode" | "tvmovie" => "tv",
        _ => "movie",
    };
    Some(ImdbTitle {
        imdbid: id.to_owned(),
        title,
        year,
        plot,
        rating,
        cover,
        genre,
        director,
        actors,
        language: languages.join(", "),
        kind: kind.to_owned(),
    })
}

fn first_of<'a>(payload: &'a Value, keys: &[&str]) -> Option<&'a Value> {
    keys.iter()
        .find_map(|key| payload.get(*key).filter(|value| !value.is_null()))
}

pub fn map_api_dev_title(payload: &Value, id: &str) -> Option<ImdbTitle> {
    if !payload.is_object() {
        return None;
    }
    let title = php::trim(&php::text(first_of(payload, &["primaryTitle", "title"]))).to_owned();
    if title.is_empty() {
        return None;
    }
    let rating_value = payload
        .get("rating")
        .and_then(|rating| rating.get("aggregateRating"))
        .filter(|rating| !rating.is_null())
        .or_else(|| payload.get("rating"));
    let mut actors = people(
        first_of(payload, &["stars", "cast"]),
        &["displayName", "name"],
    );
    actors.truncate(10);
    let languages = people(payload.get("spokenLanguages"), &["name", "displayName"]);
    let kind = php::text(payload.get("type")).to_lowercase();
    let kind = match php::trim(&kind) {
        "tvseries" | "tv_series" | "tv mini series" | "tv_mini_series" | "tvminiseries"
        | "tvspecial" | "tv_special" | "tvmovie" | "tv_movie" | "tvepisode" | "tv_episode" => "tv",
        _ => "movie",
    };
    Some(ImdbTitle {
        imdbid: id.to_owned(),
        title,
        year: php::trim(&php::text(first_of(payload, &["startYear", "year"]))).to_owned(),
        plot: php::trim(&php::text(first_of(payload, &["plot", "description"]))).to_owned(),
        rating: php::trim(&php::text(rating_value)).to_owned(),
        cover: normalize_image(first_of(payload, &["primaryImage", "image"])),
        genre: string_list(payload.get("genres")),
        director: people(payload.get("directors"), &["displayName", "name"]),
        actors,
        language: languages.join(", "),
        kind: kind.to_owned(),
    })
}

pub fn parse_suggestions(body: &str) -> Vec<ImdbMatch> {
    let Ok(json) = serde_json::from_str::<Value>(body) else {
        return Vec::new();
    };
    let mut results = Vec::new();
    for row in php::items(json.get("d")) {
        let Some(id) = row.get("id").and_then(Value::as_str) else {
            continue;
        };
        let Some(imdbid) = id.strip_prefix("tt") else {
            continue;
        };
        let title = php::trim(&php::text(row.get("l"))).to_owned();
        if title.is_empty() {
            continue;
        }
        results.push(ImdbMatch {
            imdbid: imdbid.to_owned(),
            title,
            year: php::trim(&php::text(row.get("y"))).to_owned(),
        });
        if results.len() >= 25 {
            break;
        }
    }
    results
}

pub fn parse_search_html(html: &str) -> Vec<ImdbMatch> {
    let mut results: Vec<ImdbMatch> = Vec::new();
    let bytes = html.as_bytes();
    for found in SEARCH_LINK.captures_all(bytes) {
        let group = |name: &str| {
            found
                .named()
                .into_iter()
                .find(|(key, _)| *key == name)
                .map(|(_, value)| value.to_vec())
                .unwrap_or_default()
        };
        let whole = found.group(0).unwrap_or_default();
        let raw_title = String::from_utf8_lossy(&group("title")).into_owned();
        let title = php::trim(&php::entity_decode(&php::strip_tags(&raw_title))).to_owned();
        if title.is_empty() {
            continue;
        }
        // The link's own text position, so the year comes from what follows it.
        let offset = find_subslice(bytes, whole).map_or(0, |start| start + whole.len());
        let context = &bytes[offset..bytes.len().min(offset + 200)];
        let context = php::strip_tags(&String::from_utf8_lossy(context));
        let imdbid = php::bytes_to_string(group("id"));
        let year = year_in(&context);
        // A later row with the same id wins, in its first position.
        if let Some(existing) = results.iter_mut().find(|row| row.imdbid == imdbid) {
            existing.title = title;
            existing.year = year;
        } else {
            results.push(ImdbMatch {
                imdbid,
                title,
                year,
            });
        }
        if results.len() >= 25 {
            break;
        }
    }
    results
}

fn find_subslice(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    if needle.is_empty() {
        return None;
    }
    haystack
        .windows(needle.len())
        .position(|window| window == needle)
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    const PAGE: &str = r#"<html><head>
        <script type="application/ld+json">{"@type":"Movie","name":"Fight Club &amp; Co","datePublished":"1999-10-15",
        "description":"An insomniac.","aggregateRating":{"ratingValue":8.8},
        "image":"https://m.media-amazon.com/images/M/abc._V1_SX300.jpg","genre":["Drama","Drama","Thriller"],
        "director":[{"@type":"Person","name":"David Fincher"}],"actor":[{"name":"Brad Pitt"},{"name":"Edward Norton"}],"inLanguage":"English"}</script>
        </head><body><h1>Ignored</h1></body></html>"#;

    #[test]
    fn title_pages_read_json_ld_first() {
        let title = parse_title_html(PAGE, "0137523").unwrap();
        assert_eq!(title.title, "Fight Club & Co");
        assert_eq!(title.year, "1999");
        assert_eq!(title.rating, "8.8");
        assert_eq!(title.cover, "https://m.media-amazon.com/images/M/abc.jpg");
        assert_eq!(title.genre, ["Drama", "Thriller"]);
        assert_eq!(title.director, ["David Fincher"]);
        assert_eq!(title.actors, ["Brad Pitt", "Edward Norton"]);
        assert_eq!(title.language, "English");
        assert_eq!(title.kind, "movie");
    }

    #[test]
    fn title_pages_fall_back_to_the_dom() {
        let page = r#"<html><head><meta property="og:image" content="http://x/p._V1_QL.png"></head><body>
            <h1>Heat (1995)</h1>
            <ul data-testid="hero-title-block__metadata"><li>Movie</li><li>1995</li></ul>
            <span data-testid="plot-xl"> A plot. </span>
            <div data-testid="hero-rating-bar__aggregate-rating__score"><span>8.3/10</span></div>
            <div data-testid="genres"><a>Crime</a><a>Crime</a></div>
            <li data-testid="title-pc-principal-credit">Director <a>Michael Mann</a></li>
            <li data-testid="title-pc-principal-credit">Stars <a>Al Pacino</a></li>
            <div data-testid="title-cast-item"><a data-testid="title-cast-item__actor">Al Pacino</a></div>
            <li data-testid="title-details-languages"><a>English</a><a>Spanish</a></li>
            </body></html>"#;
        let title = parse_title_html(page, "0113277").unwrap();
        assert_eq!(title.title, "Heat");
        assert_eq!(title.year, "1995");
        assert_eq!(title.plot, "A plot.");
        assert_eq!(title.rating, "8.3");
        assert_eq!(title.cover, "http://x/p.png");
        assert_eq!(title.genre, ["Crime"]);
        assert_eq!(title.director, ["Michael Mann"]);
        assert_eq!(title.actors, ["Al Pacino"]);
        assert_eq!(title.language, "English, Spanish");
    }

    #[test]
    fn waf_pages_are_recognised() {
        assert!(is_waf_response(202, ""));
        assert!(is_waf_response(200, "x window.awsWafCookieDomainList y"));
        assert!(is_waf_response(200, "a CAPTCHA page"));
        assert!(!is_waf_response(200, "<html>fine</html>"));
        assert_eq!(parse_title_html("<h1>captcha</h1>", "1"), None);
    }

    #[test]
    fn api_dev_payloads_map() {
        let title = map_api_dev_title(
            &json!({
                "primaryTitle": "Se7en", "startYear": 1995, "plot": "P",
                "rating": {"aggregateRating": 8.6}, "primaryImage": {"url": "u"},
                "genres": ["Crime"], "directors": [{"displayName": "D"}],
                "stars": [{"displayName": "S"}], "spokenLanguages": [{"name": "English"}],
                "type": "tvMiniSeries",
            }),
            "0114369",
        )
        .unwrap();
        assert_eq!(title.year, "1995");
        assert_eq!(title.rating, "8.6");
        assert_eq!(title.cover, "u");
        assert_eq!(title.kind, "tv");
        assert_eq!(map_api_dev_title(&json!({"title": " "}), "1"), None);
    }

    #[test]
    fn search_results_parse() {
        let rows = parse_suggestions(
            r#"{"d":[{"id":"tt0137523","l":"Fight Club","y":1999},{"id":"nm1","l":"Person"},{"id":"tt1","l":""}]}"#,
        );
        assert_eq!(rows.len(), 1);
        assert_eq!(rows[0].imdbid, "0137523");
        assert_eq!(rows[0].year, "1999");

        let rows = parse_search_html(
            r#"<a class="x" href="/title/tt0137523/">Fight <b>Club</b></a><span>1999</span>
               <a href="/title/tt0113277/">Heat</a> 1995"#,
        );
        assert_eq!(rows.len(), 2);
        assert_eq!(rows[0].title, "Fight Club");
        assert_eq!(rows[0].year, "1999");
        assert_eq!(rows[1].year, "1995");
    }
}
