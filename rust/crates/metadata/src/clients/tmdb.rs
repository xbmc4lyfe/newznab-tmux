//! The Movie Database. Port of `TmdbClient`.

use std::sync::LazyLock;
use std::time::Duration;

use anyhow::Result;
use nntmux_core::pcre::Pcre;
use reqwest::Client;
use serde_json::Value;
use tracing::{debug, warn};

use crate::http::{TransientError, is_transient_status, retry_after_of, send_retrying};
use crate::php;

pub static IMDB_SUFFIX: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/tt(?P<imdbid>\d{6,})$/i").expect("a valid regex"));

const IMAGE_BASE_URL: &str = "https://image.tmdb.org/t/p";

/// The digits of an IMDb id ending a value such as `tt0137523`, or nothing.
pub fn imdb_digits(value: &str) -> String {
    IMDB_SUFFIX
        .captures(value.as_bytes())
        .and_then(|found| {
            found
                .group(1)
                .map(|digits| php::bytes_to_string(digits.to_vec()))
        })
        .unwrap_or_default()
}

/// IDs of a show on the other sites.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ShowIds {
    pub tmdb: i64,
    pub imdb: String,
    pub tvdb: i64,
    pub tvrage: i64,
}

#[derive(Clone)]
pub struct Tmdb {
    http: Client,
    pub base_url: String,
    api_key: String,
    timeout: Duration,
    tries: u32,
    delay: Duration,
    throwing: bool,
}

impl Tmdb {
    pub fn new(http: Client, api_key: String, timeout: u64, tries: u32, delay_ms: u64) -> Self {
        Self {
            http,
            base_url: "https://api.themoviedb.org/3".to_owned(),
            api_key,
            timeout: Duration::from_secs(timeout),
            tries,
            delay: Duration::from_millis(delay_ms),
            throwing: false,
        }
    }

    pub fn is_configured(&self) -> bool {
        !self.api_key.is_empty()
    }

    /// A copy that fails with [`TransientError`] on 429, 5xx and connection errors, so callers
    /// can retry later instead of treating them as "not found".
    pub fn throwing(&self) -> Self {
        Self {
            throwing: true,
            ..self.clone()
        }
    }

    async fn get(&self, endpoint: &str, params: &[(&str, String)]) -> Result<Option<Value>> {
        if !self.is_configured() {
            return Ok(None);
        }
        let request = self
            .http
            .get(format!("{}{endpoint}", self.base_url))
            .timeout(self.timeout)
            .query(params)
            .query(&[("api_key", &self.api_key)]);
        let throwing = self.throwing;
        let sent = send_retrying(request, self.tries, self.delay, |status| {
            // A rate limit won't clear within the retry delay; let the caller defer instead.
            status != 404 && !(status == 429 && throwing)
        })
        .await;
        let response = match sent {
            Ok(response) => response,
            Err(failure) => return self.failed(endpoint, &failure.to_string()),
        };
        let status = response.status().as_u16();
        if response.status().is_success() {
            return match response.json::<Value>().await {
                Ok(value) => Ok(Some(value)),
                Err(failure) => self.failed(endpoint, &failure.to_string()),
            };
        }
        if status == 404 {
            debug!(endpoint, "TMDB: resource not found");
            return Ok(None);
        }
        if self.throwing && is_transient_status(status) {
            return Err(TransientError {
                provider: "tmdb",
                message: format!("TMDB returned {status} for {endpoint}"),
                status: Some(status),
                retry_after: retry_after_of(&response),
            }
            .into());
        }
        warn!(endpoint, status, "TMDB API request failed");
        Ok(None)
    }

    fn failed(&self, endpoint: &str, message: &str) -> Result<Option<Value>> {
        if self.throwing {
            return Err(TransientError {
                provider: "tmdb",
                message: format!("TMDB request failed: {message}"),
                status: None,
                retry_after: None,
            }
            .into());
        }
        warn!(endpoint, error = message, "TMDB API request exception");
        Ok(None)
    }

    pub fn image_url(path: &str) -> String {
        if path.is_empty() {
            String::new()
        } else {
            format!("{IMAGE_BASE_URL}/w500{path}")
        }
    }

    pub async fn search_tv(&self, query: &str, year: Option<i32>) -> Result<Option<Value>> {
        let mut params = vec![
            ("query", query.to_owned()),
            ("page", "1".to_owned()),
            ("include_adult", "false".to_owned()),
        ];
        if let Some(year) = year {
            params.push(("first_air_date_year", year.to_string()));
        }
        self.get("/search/tv", &params).await
    }

    pub async fn tv_show(&self, id: i64, append: &[&str]) -> Result<Option<Value>> {
        let params = if append.is_empty() {
            Vec::new()
        } else {
            vec![("append_to_response", append.join(","))]
        };
        self.get(&format!("/tv/{id}"), &params).await
    }

    pub async fn tv_external_ids(&self, id: i64) -> Result<Option<Value>> {
        self.get(&format!("/tv/{id}/external_ids"), &[]).await
    }

    pub async fn tv_season(&self, id: i64, season: i64) -> Result<Option<Value>> {
        if id <= 0 || season < 0 {
            return Ok(None);
        }
        self.get(&format!("/tv/{id}/season/{season}"), &[]).await
    }

    pub async fn tv_episode(&self, id: i64, season: i64, episode: i64) -> Result<Option<Value>> {
        if id <= 0 || season < 0 || episode < 0 {
            return Ok(None);
        }
        self.get(&format!("/tv/{id}/season/{season}/episode/{episode}"), &[])
            .await
    }

    pub async fn find_tv(&self, external_id: &str, source: &str) -> Result<Option<Value>> {
        if external_id.is_empty() {
            return Ok(None);
        }
        let found = self
            .get(
                &format!("/find/{external_id}"),
                &[("external_source", source.to_owned())],
            )
            .await?;
        Ok(found.and_then(|found| {
            found
                .get("tv_results")
                .and_then(|results| results.get(0))
                .cloned()
        }))
    }

    /// `getTvEpisodeWithFallback`: the TMDB id, then the show found by its TVDB or IMDb id.
    pub async fn tv_episode_with_fallback(
        &self,
        tmdb: i64,
        tvdb: i64,
        imdb: &str,
        season: i64,
        episode: i64,
    ) -> Result<Option<Value>> {
        if tmdb > 0
            && let Some(found) = self.tv_episode(tmdb, season, episode).await?
        {
            return Ok(Some(found));
        }
        if tvdb > 0
            && let Some(show) = self.find_tv(&tvdb.to_string(), "tvdb_id").await?
            && let id @ 1.. = php::int(show.get("id"))
            && let Some(found) = self.tv_episode(id, season, episode).await?
        {
            return Ok(Some(found));
        }
        if !imdb.is_empty() && imdb != "0" {
            let formatted = if imdb.starts_with("tt") {
                imdb.to_owned()
            } else {
                format!("tt{imdb}")
            };
            if let Some(show) = self.find_tv(&formatted, "imdb_id").await?
                && let id @ 1.. = php::int(show.get("id"))
                && let Some(found) = self.tv_episode(id, season, episode).await?
            {
                return Ok(Some(found));
            }
        }
        Ok(None)
    }

    /// `lookupTvShowIds` for a TVDB or IMDb id.
    pub async fn lookup_tv_ids(&self, id: &str, source: &str) -> Result<Option<ShowIds>> {
        let show = match source {
            "tvdb" if id.parse::<i64>().is_ok_and(|id| id > 0) => {
                self.find_tv(id, "tvdb_id").await?
            }
            "imdb" => {
                let formatted = if id.starts_with("tt") {
                    id.to_owned()
                } else {
                    format!("tt{id}")
                };
                self.find_tv(&formatted, "imdb_id").await?
            }
            _ => None,
        };
        let Some(show) = show else {
            return Ok(None);
        };
        let tmdb = php::int(show.get("id"));
        let external = if tmdb > 0 {
            self.tv_external_ids(tmdb).await?.unwrap_or(Value::Null)
        } else {
            Value::Null
        };
        Ok(Some(ShowIds {
            tmdb,
            imdb: imdb_digits(&php::text(external.get("imdb_id"))),
            tvdb: php::int(external.get("tvdb_id")),
            tvrage: php::int(external.get("tvrage_id")),
        }))
    }
}
