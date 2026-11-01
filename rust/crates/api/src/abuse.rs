//! `BlockAbusiveServices` and `IndexerProxyDetector`: refuses streaming services, some cloud networks
//! and, when configured, indexer apps that proxy NZB downloads.

use std::net::IpAddr;
use std::sync::LazyLock;
use std::time::Duration;

use axum::extract::Request;
use axum::http::header::USER_AGENT;
use axum::middleware::Next;
use axum::response::Response;

use md5::{Digest, Md5};
use nntmux_core::{optional, parsed_or, var_or};
use nntmux_runtime::Ctx;
use serde::{Deserialize, Serialize};
use tracing::{debug, warn};

use crate::cache;
use crate::input::trim;
use crate::json::{J, Style};
use crate::reply::Reply;
use crate::request::ApiRequest;
use crate::settings::Settings;

const BLOCKED_USER_AGENTS: [&str; 3] = ["aiostreams", "usenetstreamer", "stremio"];
const BLOCKED_ASNS: [(u32, &str); 2] = [
    (31898, "Oracle Cloud Infrastructure"),
    (13335, "Cloudflare WARP"),
];
const ASN_CACHE_SECONDS: u64 = 86_400;
/// Failed lookups retry sooner, and not on every request: ip-api.com allows 45 a minute.
const ASN_MISS_CACHE_SECONDS: u64 = 300;
const MAX_TRACKED_USER_AGENTS: usize = 5;
const V2_SEARCH_PATHS: [&str; 7] = [
    "/api/v2/search",
    "/api/v2/movies",
    "/api/v2/audio",
    "/api/v2/books",
    "/api/v2/anime",
    "/api/v2/tv",
    "/api/v2/capabilities",
];

#[derive(Clone, Debug)]
pub struct AbuseConfig {
    pub block_proxy_apps: bool,
    pub proxy_app_user_agents: Vec<String>,
    pub detection_enabled: bool,
    pub threshold: i64,
    pub window_seconds: u64,
    pub ratio_min: f64,
    pub min_searches: i64,
    pub referer_patterns: Vec<String>,
    pub asn_lookup: bool,
}

fn patterns(raw: &str) -> Vec<String> {
    raw.split(['\r', '\n', ','])
        .map(str::trim)
        .filter(|pattern| !pattern.is_empty())
        .map(str::to_lowercase)
        .collect()
}

impl Default for AbuseConfig {
    fn default() -> Self {
        Self {
            block_proxy_apps: false,
            proxy_app_user_agents: patterns("Prowlarr/,NZBHydra2"),
            detection_enabled: false,
            threshold: 50,
            window_seconds: 3600,
            ratio_min: 0.8,
            min_searches: 20,
            referer_patterns: patterns("hydra,prowlarr"),
            asn_lookup: true,
        }
    }
}

impl AbuseConfig {
    pub fn from_env() -> anyhow::Result<Self> {
        let flag = |key: &str| {
            optional(key).is_some_and(|value| matches!(value.as_str(), "1" | "true" | "on" | "yes"))
        };
        let defaults = Self::default();
        Ok(Self {
            block_proxy_apps: flag("BLOCK_PROXY_INDEXER_APPS"),
            proxy_app_user_agents: patterns(&var_or(
                "BLOCK_PROXY_INDEXER_APP_USER_AGENTS",
                "Prowlarr/,NZBHydra2",
            )),
            detection_enabled: flag("PROXY_DETECTION_ENABLED"),
            threshold: parsed_or("PROXY_DETECTION_THRESHOLD", defaults.threshold)?,
            window_seconds: parsed_or("PROXY_DETECTION_WINDOW_SECONDS", defaults.window_seconds)?,
            ratio_min: parsed_or("PROXY_DETECTION_RATIO_MIN", defaults.ratio_min)?,
            min_searches: parsed_or("PROXY_DETECTION_MIN_SEARCHES", defaults.min_searches)?,
            referer_patterns: patterns(&var_or(
                "PROXY_DETECTION_INDEXER_REFERER_PATTERNS",
                "hydra,prowlarr",
            )),
            asn_lookup: true,
        })
    }
}

fn blocked(message: &str) -> Reply {
    Reply::json(
        403,
        &J::obj([("error", J::Bool(true)), ("message", J::str(message))]),
        Style::Plain,
    )
}

fn operation(request: &ApiRequest) -> String {
    trim(&request.input.string("t")).to_lowercase()
}

fn is_download(request: &ApiRequest) -> bool {
    request.path == "/api/v2/getnzb"
        || (request.path == "/api/v1/api" && matches!(operation(request).as_str(), "get" | "g"))
}

fn is_search(request: &ApiRequest) -> bool {
    request.path.starts_with("/rss/")
        || V2_SEARCH_PATHS.contains(&request.path.as_str())
        || (request.path == "/api/v1/api"
            && matches!(
                operation(request).as_str(),
                "search" | "tvsearch" | "movie" | "music" | "book" | "caps"
            ))
}

fn matches_any(haystack: &str, patterns: &[String]) -> bool {
    let lower = haystack.to_lowercase();
    patterns
        .iter()
        .any(|pattern| lower.contains(pattern.as_str()))
}

fn key(parts: &[&str]) -> String {
    let parts: Vec<String> = parts
        .iter()
        .map(|part| {
            if part.contains(':') || part.len() > 40 {
                hex::encode(Md5::digest(part.as_bytes()))
            } else {
                (*part).to_owned()
            }
        })
        .collect();
    format!("proxy_detect:{}", parts.join(":"))
}

/// The token correlating searches with downloads: all of these name the same user.
fn token(request: &ApiRequest) -> Option<&str> {
    ["api_token", "apikey", "apiToken", "r"]
        .into_iter()
        .find_map(|name| request.input.str(name).filter(|value| !value.is_empty()))
}

/// Scores a download from four weak signals, so no single one can block a real user. Redirected
/// grabs score zero: the download client's own User-Agent matches none of them.
async fn analyze(
    ctx: &Ctx,
    settings: &Settings,
    request: &ApiRequest,
    user_agent: &str,
    ip: &str,
) -> (bool, i64) {
    let config = &settings.abuse;
    let mut score = 0;
    if request
        .header("referer")
        .is_some_and(|referer| matches_any(referer, &config.referer_patterns))
    {
        score += 30;
    }
    if let Some(token) = token(request) {
        let recent: Vec<String> = cache::get(ctx, settings, &key(&["ua", token]))
            .await
            .unwrap_or_default();
        if recent.iter().any(|agent| agent == user_agent) {
            score += 25;
        }
        let searches: i64 = cache::get(ctx, settings, &key(&["count", token, "searches"]))
            .await
            .unwrap_or(0);
        let downloads: i64 = cache::get(ctx, settings, &key(&["count", token, "downloads"]))
            .await
            .unwrap_or(0);
        if searches >= config.min_searches
            && (downloads as f64 / searches.max(1) as f64) >= config.ratio_min
        {
            score += 25;
        }
    }
    if !ip.is_empty() {
        let last: Option<String> = cache::get(ctx, settings, &key(&["ip", ip, "indexer_ua"])).await;
        if last.as_deref() == Some(user_agent) {
            score += 20;
        }
    }
    if let Some(token) = token(request) {
        cache::increment(
            ctx,
            settings,
            &key(&["count", token, "downloads"]),
            config.window_seconds,
        )
        .await;
    }
    (score >= config.threshold, score)
}

/// Feeds the sliding windows, so a later download from this token or address can be correlated.
async fn record_search(
    ctx: &Ctx,
    settings: &Settings,
    request: &ApiRequest,
    user_agent: &str,
    ip: &str,
) {
    let ttl = settings.abuse.window_seconds;
    if let Some(token) = token(request) {
        cache::increment(ctx, settings, &key(&["count", token, "searches"]), ttl).await;
        if !user_agent.is_empty() {
            let name = key(&["ua", token]);
            let mut agents: Vec<String> =
                cache::get(ctx, settings, &name).await.unwrap_or_default();
            agents.retain(|agent| agent != user_agent);
            agents.insert(0, user_agent.to_owned());
            agents.truncate(MAX_TRACKED_USER_AGENTS);
            cache::put(ctx, settings, &name, ttl, &agents).await;
        }
    }
    if !user_agent.is_empty() {
        cache::put(
            ctx,
            settings,
            &key(&["ip", ip, "indexer_ua"]),
            ttl,
            &user_agent,
        )
        .await;
    }
}

#[derive(Deserialize, Serialize)]
struct AsnEntry {
    asn: Option<u32>,
}

async fn asn_of(ctx: &Ctx, settings: &Settings, ip: &str) -> Option<u32> {
    let name = format!("asn_lookup_{}", hex::encode(Md5::digest(ip.as_bytes())));
    if let Some(entry) = cache::get::<AsnEntry>(ctx, settings, &name).await {
        return entry.asn;
    }
    let asn = fetch_asn(ip).await;
    let ttl = if asn.is_some() {
        ASN_CACHE_SECONDS
    } else {
        ASN_MISS_CACHE_SECONDS
    };
    cache::put(ctx, settings, &name, ttl, &AsnEntry { asn }).await;
    asn
}

async fn fetch_asn(ip: &str) -> Option<u32> {
    let client = reqwest::Client::builder()
        .timeout(Duration::from_secs(3))
        .build()
        .ok()?;
    let url = format!("http://ip-api.com/json/{ip}?fields=status,as,org");
    let response = match client.get(url).send().await {
        Ok(response) if response.status().is_success() => response,
        Ok(response) => {
            debug!(ip, status = %response.status(), "ASN lookup failed");
            return None;
        }
        Err(failure) => {
            debug!(ip, error = %failure, "ASN lookup failed");
            return None;
        }
    };
    let body: serde_json::Value = response.json().await.ok()?;
    if body["status"] != "success" {
        return None;
    }
    let digits: String = body["as"]
        .as_str()?
        .strip_prefix("AS")?
        .chars()
        .take_while(char::is_ascii_digit)
        .collect();
    digits.parse().ok()
}

/// `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`: nothing to look up for these.
fn is_public(address: &str) -> bool {
    match address.parse::<IpAddr>() {
        Ok(IpAddr::V4(ip)) => {
            !(ip.is_private()
                || ip.is_loopback()
                || ip.is_link_local()
                || ip.is_unspecified()
                || ip.is_multicast()
                || ip.octets()[0] >= 240)
        }
        Ok(IpAddr::V6(ip)) => {
            !(ip.is_loopback()
                || ip.is_unspecified()
                || ip.is_multicast()
                || ip.is_unique_local()
                || ip.is_unicast_link_local())
        }
        Err(_) => false,
    }
}

fn agent_refusal(user_agent: &str, ip: Option<&str>, path: &str) -> Option<Reply> {
    let lower = user_agent.to_lowercase();
    if !BLOCKED_USER_AGENTS
        .iter()
        .any(|pattern| lower.contains(pattern))
    {
        return None;
    }
    warn!(ip, user_agent, path, "blocked abusive User-Agent");
    Some(blocked(
        "Access denied: Streaming services are not allowed.",
    ))
}

async fn asn_refusal(
    ctx: &Ctx,
    settings: &Settings,
    user_agent: &str,
    ip: Option<&str>,
    path: &str,
) -> Option<Reply> {
    if !settings.abuse.asn_lookup {
        return None;
    }
    let address = ip.filter(|address| is_public(address))?;
    let asn = asn_of(ctx, settings, address).await?;
    let (_, name) = BLOCKED_ASNS
        .iter()
        .find(|(blocked_asn, _)| *blocked_asn == asn)?;
    warn!(ip, asn, user_agent, path, "blocked ASN");
    Some(blocked(&format!("Access denied: {name} is not allowed.")))
}

/// What `site::statics` serves from disk, which the web server answered before PHP.
const STATIC_PREFIXES: [&str; 4] = ["/build/", "/assets/", "/svg/", "/storage/"];
const STATIC_FILES: [&str; 2] = ["/favicon.ico", "/robots.txt"];

static PAGE_SETTINGS: LazyLock<Option<Settings>> = LazyLock::new(|| match Settings::from_env() {
    Ok(settings) => Some(settings),
    Err(failure) => {
        warn!(%failure, "abuse checks for pages are off");
        None
    }
});

/// The same middleware for pages, which PHP ran it on as well. Downloads and searches have their
/// own routes, so only the user agent and network checks apply. Files the web server would have
/// answered in PHP's setup are left alone.
pub async fn block_pages(request: Request, next: Next) -> Response {
    let path = request.uri().path().to_owned();
    if STATIC_PREFIXES
        .iter()
        .any(|prefix| path.starts_with(prefix))
        || STATIC_FILES.contains(&path.as_str())
    {
        return next.run(request).await;
    }
    let (parts, body) = request.into_parts();
    let ip = nntmux_webkit::client_ip::from_parts(&parts).to_string();
    let user_agent = parts
        .headers
        .get(USER_AGENT)
        .and_then(|value| value.to_str().ok())
        .unwrap_or_default()
        .to_owned();
    let mut refusal = agent_refusal(&user_agent, Some(&ip), &path);
    if refusal.is_none()
        && let (Some(ctx), Some(settings)) = (parts.extensions.get::<Ctx>(), PAGE_SETTINGS.as_ref())
    {
        refusal = asn_refusal(ctx, settings, &user_agent, Some(&ip), &path).await;
    }
    match refusal {
        Some(reply) => reply.into_response(),
        None => next.run(Request::from_parts(parts, body)).await,
    }
}

/// The middleware: `Some` is the refusal.
pub async fn check(ctx: &Ctx, settings: &Settings, request: &ApiRequest) -> Option<Reply> {
    let user_agent = request.header("user-agent").unwrap_or_default();
    let ip = request.client_ip();
    if let Some(reply) = agent_refusal(user_agent, ip.as_deref(), &request.path) {
        return Some(reply);
    }

    let download = is_download(request);
    let config = &settings.abuse;
    if config.detection_enabled {
        let address = ip.as_deref().unwrap_or_default();
        if download {
            let (block, score) = analyze(ctx, settings, request, user_agent, address).await;
            if block {
                warn!(
                    ip,
                    user_agent,
                    score,
                    path = request.path,
                    "blocked proxied NZB download"
                );
                return Some(blocked(
                    "Access denied: Proxying NZB downloads through indexer apps is not allowed.",
                ));
            }
        } else if is_search(request) {
            record_search(ctx, settings, request, user_agent, address).await;
        }
    }
    if config.block_proxy_apps && download && matches_any(user_agent, &config.proxy_app_user_agents)
    {
        warn!(
            ip,
            user_agent,
            path = request.path,
            "blocked proxied NZB download from indexer app"
        );
        return Some(blocked(
            "Access denied: Proxying NZB downloads through indexer apps is not allowed.",
        ));
    }

    asn_refusal(ctx, settings, user_agent, ip.as_deref(), &request.path).await
}
