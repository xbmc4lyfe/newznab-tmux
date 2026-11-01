//! TheTVDB API v4. Port of what `TvdbProvider` used of the vendor client.

use std::time::Duration;

use anyhow::{Result, anyhow};
use reqwest::{Client, RequestBuilder};
use serde_json::{Value, json};
use tokio::sync::Mutex;
use tracing::warn;

use crate::http::{TransientError, is_transient_status, retry_after_of};
use crate::php;

/// Episodes per page when a language is named.
const FULL_PAGE: usize = 500;

#[derive(Default)]
struct Session {
    token: Option<String>,
    /// TVDB rejected our credentials, so it's off for this process.
    rejected: bool,
}

pub struct Tvdb {
    http: Client,
    pub base_url: String,
    api_key: String,
    pin: Option<String>,
    timeout: Duration,
    session: Mutex<Session>,
}

impl Tvdb {
    pub fn new(http: Client, api_key: String, pin: Option<String>, timeout: u64) -> Self {
        Self {
            http,
            base_url: "https://api4.thetvdb.com/v4".to_owned(),
            api_key,
            pin,
            timeout: Duration::from_secs(timeout),
            session: Mutex::new(Session::default()),
        }
    }

    pub fn is_configured(&self) -> bool {
        !self.api_key.is_empty()
    }

    fn request(&self, method: reqwest::Method, path: &str) -> RequestBuilder {
        self.http
            .request(method, format!("{}/{path}", self.base_url))
            .timeout(self.timeout)
            .header(reqwest::header::ACCEPT, "application/json")
    }

    /// A token, logging in if needed. `None` when TVDB is unconfigured or rejected us.
    async fn authorize(&self, force: bool) -> Result<Option<String>> {
        let mut session = self.session.lock().await;
        if session.rejected || !self.is_configured() {
            return Ok(None);
        }
        if !force && let Some(token) = &session.token {
            return Ok(Some(token.clone()));
        }
        session.token = None;
        let mut body = json!({"apikey": self.api_key});
        if let Some(pin) = self.pin.as_deref().filter(|pin| !pin.is_empty()) {
            body["pin"] = json!(pin);
        }
        let response = self
            .request(reqwest::Method::POST, "login")
            .json(&body)
            .send()
            .await
            .map_err(|failure| TransientError {
                provider: "tvdb",
                message: format!("TVDB login failed: {}", failure.without_url()),
                status: None,
                retry_after: None,
            })?;
        let status = response.status().as_u16();
        if status == 401 || status == 404 {
            warn!(
                "TVDB rejected the configured credentials; TVDB lookups are disabled for this run."
            );
            session.rejected = true;
            return Ok(None);
        }
        if is_transient_status(status) {
            return Err(TransientError {
                provider: "tvdb",
                message: format!("TVDB login returned {status}"),
                status: Some(status),
                retry_after: retry_after_of(&response),
            }
            .into());
        }
        if status != 200 {
            return Err(anyhow!("TVDB login: got status code {status}"));
        }
        let data: Value = response
            .json()
            .await
            .map_err(|failure| anyhow!("TVDB login: {}", failure.without_url()))?;
        let token = data
            .get("data")
            .unwrap_or(&data)
            .get("token")
            .and_then(Value::as_str)
            .map(str::to_owned);
        let Some(token) = token else {
            warn!("TVDB login returned no token; TVDB lookups are disabled for this run.");
            session.rejected = true;
            return Ok(None);
        };
        session.token = Some(token.clone());
        Ok(Some(token))
    }

    /// A GET's `data`. `None` when TVDB has nothing (404) or is unusable here. Re-authorises once
    /// on 401. Rate limits, server errors and connection failures are [`TransientError`]s.
    async fn get(&self, path: &str, query: &[(&str, String)]) -> Result<Option<Value>> {
        for attempt in 0..2 {
            let Some(token) = self.authorize(attempt > 0).await? else {
                return Ok(None);
            };
            let response = self
                .request(reqwest::Method::GET, path)
                .bearer_auth(token)
                .query(query)
                .send()
                .await
                .map_err(|failure| TransientError {
                    provider: "tvdb",
                    message: format!("TVDB request failed: {}", failure.without_url()),
                    status: None,
                    retry_after: None,
                })?;
            let status = response.status().as_u16();
            match status {
                200 => {
                    let Ok(mut json) = response.json::<Value>().await else {
                        warn!(path, "TVDB returned an undecodable response");
                        return Ok(None);
                    };
                    return Ok(Some(match json.get_mut("data") {
                        Some(data) => data.take(),
                        None => json,
                    }));
                }
                401 => {}
                404 => return Ok(None),
                _ if is_transient_status(status) => {
                    return Err(TransientError {
                        provider: "tvdb",
                        message: format!("Got status code {status} from service at path {path}"),
                        status: Some(status),
                        retry_after: retry_after_of(&response),
                    }
                    .into());
                }
                _ => {
                    warn!(path, status, "TVDB request failed");
                    return Ok(None);
                }
            }
        }
        Ok(None)
    }

    pub async fn search_series(&self, name: &str, year: Option<i32>) -> Result<Option<Vec<Value>>> {
        let mut query = vec![("type", "series".to_owned())];
        if let Some(year) = year {
            query.push(("year", year.to_string()));
        }
        query.push(("query", name.to_owned()));
        Ok(self
            .get("search", &query)
            .await?
            .and_then(|data| match data {
                Value::Array(results) => Some(results),
                _ => None,
            }))
    }

    pub async fn series_artworks(&self, id: i64) -> Result<Vec<Value>> {
        let data = self
            .get(&format!("series/{id}/artworks"), &[])
            .await?
            .unwrap_or(Value::Null);
        Ok(php::items(data.get("artworks")).to_vec())
    }

    pub async fn series_extended(&self, id: i64) -> Result<Option<Value>> {
        self.get(&format!("series/{id}/extended"), &[]).await
    }

    /// Every episode of a series in English, page by page.
    pub async fn all_episodes(&self, id: i64) -> Result<Option<Vec<Value>>> {
        let mut episodes = Vec::new();
        for page in 0_u32.. {
            let Some(data) = self
                .get(
                    &format!("series/{id}/episodes/default/eng"),
                    &[("page", page.to_string())],
                )
                .await?
            else {
                return Ok((page > 0).then_some(episodes));
            };
            let found = php::items(data.get("episodes"));
            episodes.extend_from_slice(found);
            if found.len() != FULL_PAGE {
                break;
            }
        }
        Ok(Some(episodes))
    }

    pub async fn episodes(
        &self,
        id: i64,
        season: i64,
        episode: Option<i64>,
        air_date: Option<&str>,
    ) -> Result<Option<Vec<Value>>> {
        let mut query = vec![("page", "0".to_owned()), ("season", season.to_string())];
        if let Some(episode) = episode {
            query.push(("episodeNumber", episode.to_string()));
        }
        if let Some(air_date) = air_date {
            query.push(("airDate", air_date.to_owned()));
        }
        Ok(self
            .get(&format!("series/{id}/episodes/default"), &query)
            .await?
            .map(|data| php::items(data.get("episodes")).to_vec()))
    }
}
