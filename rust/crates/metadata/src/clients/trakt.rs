//! Trakt's public metadata endpoints. Port of `TraktService`.

use std::time::{Duration, SystemTime, UNIX_EPOCH};

use anyhow::Result;
use md5::{Digest, Md5};
use reqwest::{Client, Response};
use serde_json::Value;
use tracing::{debug, warn};

use crate::cache::Cache;
use crate::http::{TransientError, is_transient_status, send_retrying};
use crate::php;

const CACHE_TTL: Duration = Duration::from_secs(24 * 3600);
/// Minimum gap between requests, across workers, to stay clear of Trakt's and Cloudflare's limits.
const THROTTLE_SECONDS: f64 = 1.0;
/// Backoff when a 429 names no delay.
const RATE_LIMIT_BACKOFF: u64 = 60;
const THROTTLE_KEY: &str = "trakt_last_request_at";

/// IDs of a show on the other sites.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct TraktIds {
    pub trakt: i64,
    pub tmdb: i64,
}

#[derive(Clone)]
pub struct Trakt {
    http: Client,
    cache: Cache,
    pub base_url: String,
    client_id: String,
    timeout: Duration,
    tries: u32,
    delay: Duration,
    throwing: bool,
    /// Unset in tests.
    pub throttled: bool,
}

fn md5_hex(text: &str) -> String {
    hex::encode(Md5::digest(text.as_bytes()))
}

/// `Str::slug`.
fn slug(text: &str) -> String {
    let ascii = deunicode::deunicode(text).to_ascii_lowercase();
    let mut output = String::with_capacity(ascii.len());
    for character in ascii.chars() {
        if character.is_ascii_alphanumeric() {
            output.push(character);
        } else if !output.is_empty() && !output.ends_with('-') {
            output.push('-');
        }
    }
    output.trim_end_matches('-').to_owned()
}

/// Keeps meaningful leading zeroes: `tt0137523` must stay as it is.
pub fn format_imdb_id(id: &str) -> Option<String> {
    let digits_after_tt = id
        .get(..2)
        .filter(|prefix| prefix.eq_ignore_ascii_case("tt"))
        .and_then(|_| id.get(2..))
        .filter(|rest| rest.len() >= 6 && rest.bytes().all(|byte| byte.is_ascii_digit()));
    if digits_after_tt.is_some() {
        return Some(id.to_owned());
    }
    let digits: String = id.chars().filter(char::is_ascii_digit).collect();
    (digits.len() >= 6).then(|| format!("tt{digits}"))
}

fn format_media_id(id: &str) -> Option<String> {
    let id = php::trim(id);
    if id.is_empty() {
        return None;
    }
    if id.to_ascii_lowercase().starts_with("tt") {
        format_imdb_id(id)
    } else {
        Some(slug(id))
    }
}

/// The ids `episode_with_fallback` tries.
#[derive(Clone, Copy, Debug)]
pub struct EpisodeIds<'a> {
    pub trakt: i64,
    pub tmdb: i64,
    pub tvdb: i64,
    pub imdb: &'a str,
}

impl Trakt {
    pub fn new(
        http: Client,
        cache: Cache,
        client_id: String,
        timeout: u64,
        tries: u32,
        delay_ms: u64,
    ) -> Self {
        Self {
            http,
            cache,
            base_url: "https://api.trakt.tv".to_owned(),
            client_id,
            timeout: Duration::from_secs(timeout),
            tries,
            delay: Duration::from_millis(delay_ms),
            throwing: false,
            throttled: true,
        }
    }

    pub fn is_configured(&self) -> bool {
        !self.client_id.is_empty()
    }

    /// A copy that fails with [`TransientError`] on transient failures. A 429 fails at once with
    /// its Retry-After instead of sleeping the worker before one more try.
    pub fn throwing(&self) -> Self {
        Self {
            throwing: true,
            ..self.clone()
        }
    }

    async fn throttle(&self) {
        if !self.throttled {
            return;
        }
        let now = || {
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .map_or(0.0, |elapsed| elapsed.as_secs_f64())
        };
        if let Some(last) = self.cache.get::<f64>(THROTTLE_KEY).await {
            let elapsed = now() - last;
            if (0.0..THROTTLE_SECONDS).contains(&elapsed) {
                tokio::time::sleep(Duration::from_secs_f64(THROTTLE_SECONDS - elapsed)).await;
            }
        }
        self.cache
            .put(THROTTLE_KEY, &now(), Duration::from_secs(10))
            .await;
    }

    async fn get(&self, endpoint: &str, params: &[(&str, String)]) -> Result<Option<Value>> {
        if !self.is_configured() {
            return Ok(None);
        }
        let mut rate_limited_once = false;
        loop {
            self.throttle().await;
            let request = self
                .http
                .get(format!(
                    "{}/{}",
                    self.base_url,
                    endpoint.trim_start_matches('/')
                ))
                .timeout(self.timeout)
                .header("Accept", "application/json")
                .header("Content-Type", "application/json")
                .header("trakt-api-version", "2")
                .header("trakt-api-key", &self.client_id)
                .query(params);
            let sent = send_retrying(request, self.tries, self.delay, |status| {
                status != 404 && status != 429
            })
            .await;
            let response = match sent {
                Ok(response) => response,
                Err(failure) => {
                    let failure = failure.without_url();
                    if self.throwing {
                        return Err(TransientError {
                            provider: "trakt",
                            message: format!("Trakt request failed: {failure}"),
                            status: None,
                            retry_after: None,
                        }
                        .into());
                    }
                    warn!(endpoint, error = %failure, "Trakt API request exception");
                    return Ok(None);
                }
            };
            let status = response.status().as_u16();
            if response.status().is_success() {
                let data: Value = match response.json().await {
                    Ok(data) => data,
                    Err(failure) => {
                        warn!(endpoint, error = %failure.without_url(), "Trakt API returned bad JSON");
                        return Ok(None);
                    }
                };
                if data.get("status").and_then(Value::as_str) == Some("failure") {
                    debug!(endpoint, "Trakt API returned failure status");
                    return Ok(None);
                }
                return Ok(Some(data));
            }
            if self.throwing && is_transient_status(status) {
                return Err(TransientError {
                    provider: "trakt",
                    message: format!("Trakt returned {status} for {endpoint}"),
                    status: Some(status),
                    retry_after: (status == 429).then(|| rate_limit_backoff(&response) as i64),
                }
                .into());
            }
            if status == 429 && !rate_limited_once {
                let backoff = rate_limit_backoff(&response);
                warn!(
                    endpoint,
                    backoff, "Trakt API rate limited, backing off then retrying once"
                );
                tokio::time::sleep(Duration::from_secs(backoff)).await;
                rate_limited_once = true;
                continue;
            }
            if status == 404 {
                debug!(endpoint, "Trakt: resource not found");
            } else {
                warn!(endpoint, status, "Trakt API request failed");
            }
            return Ok(None);
        }
    }

    /// `Cache::remember`, which doesn't keep an empty result.
    async fn cached(
        &self,
        key: &str,
        endpoint: &str,
        params: &[(&str, String)],
    ) -> Result<Option<Value>> {
        if let Some(value) = self.cache.get::<Value>(key).await {
            return Ok(Some(value));
        }
        let value = self.get(endpoint, params).await?;
        if let Some(value) = &value {
            self.cache.put(key, value, CACHE_TTL).await;
        }
        Ok(value)
    }

    pub async fn search_shows(&self, query: &str) -> Result<Option<Value>> {
        let query = php::trim(query);
        if query.is_empty() {
            return Ok(None);
        }
        self.cached(
            &format!("trakt_search_show_{}", slug(query)),
            "search/show",
            &[("query", query.to_owned())],
        )
        .await
    }

    pub async fn show_summary(&self, show: &str) -> Result<Option<Value>> {
        let Some(id) = format_media_id(show) else {
            return Ok(None);
        };
        self.cached(
            &format!("trakt_show_{}_full", md5_hex(&id)),
            &format!("shows/{id}"),
            &[("extended", "full".to_owned())],
        )
        .await
    }

    pub async fn movie_summary(&self, movie: &str) -> Result<Option<Value>> {
        let Some(id) = format_media_id(movie) else {
            return Ok(None);
        };
        self.cached(
            &format!("trakt_movie_{}_full", md5_hex(&id)),
            &format!("movies/{id}"),
            &[("extended", "full".to_owned())],
        )
        .await
    }

    pub async fn show_seasons(&self, show: &str, extended: &str) -> Result<Option<Value>> {
        let Some(id) = format_media_id(show) else {
            return Ok(None);
        };
        self.cached(
            &format!("trakt_seasons_{}_{extended}", md5_hex(&id)),
            &format!("shows/{id}/seasons"),
            &[("extended", extended.to_owned())],
        )
        .await
    }

    /// `searchById` for shows.
    async fn search_show_by_id(&self, id: &str, id_type: &str) -> Result<Option<Value>> {
        let mut formatted = php::trim(id).to_owned();
        if formatted.is_empty() {
            return Ok(None);
        }
        if id_type == "imdb" {
            let Some(imdb) = format_imdb_id(&formatted) else {
                return Ok(None);
            };
            formatted = imdb;
        }
        let safe: String = formatted
            .chars()
            .map(|c| {
                if c.is_ascii_alphanumeric() || c == '_' || c == '-' {
                    c
                } else {
                    '_'
                }
            })
            .collect();
        self.cached(
            &format!("trakt_search_{id_type}_{safe}_show"),
            &format!("search/{id_type}/{formatted}"),
            &[("type", "show".to_owned())],
        )
        .await
    }

    pub async fn lookup_show_ids(&self, id: &str, id_type: &str) -> Result<Option<TraktIds>> {
        let Some(results) = self.search_show_by_id(id, id_type).await? else {
            return Ok(None);
        };
        let Some(ids) = results
            .get(0)
            .and_then(|result| result.get("show"))
            .and_then(|show| show.get("ids"))
        else {
            return Ok(None);
        };
        Ok(Some(TraktIds {
            trakt: php::int(ids.get("trakt")),
            tmdb: php::int(ids.get("tmdb")),
        }))
    }

    pub async fn episode_by_trakt_id(
        &self,
        trakt: i64,
        season: i64,
        episode: i64,
    ) -> Result<Option<Value>> {
        if trakt <= 0 || season < 0 || episode < 0 {
            return Ok(None);
        }
        self.cached(
            &format!("trakt_episode_trakt_{trakt}_{season}_{episode}_full"),
            &format!("shows/{trakt}/seasons/{season}/episodes/{episode}"),
            &[("extended", "full".to_owned())],
        )
        .await
    }

    /// Tries the Trakt id, then the show found by its TMDB, TVDB or IMDb id. The episode endpoint
    /// only takes Trakt's own ids, so the others go through the ID lookup first.
    pub async fn episode_with_fallback(
        &self,
        ids: EpisodeIds<'_>,
        season: i64,
        episode: i64,
    ) -> Result<Option<Value>> {
        if let Some(found) = self.episode_by_trakt_id(ids.trakt, season, episode).await? {
            return Ok(Some(found));
        }
        let tmdb = ids.tmdb.to_string();
        let tvdb = ids.tvdb.to_string();
        for (id_type, id, usable) in [
            ("tmdb", tmdb.as_str(), ids.tmdb > 0),
            ("tvdb", tvdb.as_str(), ids.tvdb > 0),
            ("imdb", ids.imdb, format_imdb_id(ids.imdb).is_some()),
        ] {
            if !usable {
                continue;
            }
            let Some(found) = self.lookup_show_ids(id, id_type).await? else {
                continue;
            };
            if found.trakt != ids.trakt
                && let Some(episode) = self
                    .episode_by_trakt_id(found.trakt, season, episode)
                    .await?
            {
                return Ok(Some(episode));
            }
        }
        Ok(None)
    }
}

fn rate_limit_backoff(response: &Response) -> u64 {
    response
        .headers()
        .get(reqwest::header::RETRY_AFTER)
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.trim().parse::<f64>().ok())
        .map_or(RATE_LIMIT_BACKOFF, |seconds| {
            (seconds as i64).clamp(1, 300) as u64
        })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn imdb_ids_keep_leading_zeroes() {
        assert_eq!(format_imdb_id("tt0137523").as_deref(), Some("tt0137523"));
        assert_eq!(format_imdb_id("0137523").as_deref(), Some("tt0137523"));
        assert_eq!(format_imdb_id("123"), None);
        assert_eq!(format_imdb_id(""), None);
    }

    #[test]
    fn media_ids() {
        assert_eq!(format_media_id("1390").as_deref(), Some("1390"));
        assert_eq!(
            format_media_id("Game of Thrones!").as_deref(),
            Some("game-of-thrones")
        );
        assert_eq!(format_media_id("tt0944947").as_deref(), Some("tt0944947"));
        assert_eq!(format_media_id(" "), None);
    }
}
