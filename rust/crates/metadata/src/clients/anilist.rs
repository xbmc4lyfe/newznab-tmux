//! AniList's GraphQL API. Port of the request half of `PopulateAniListService`.

use std::collections::VecDeque;
use std::sync::Arc;
use std::time::Duration;

use anyhow::{Result, bail};
use reqwest::Client;
use serde_json::{Value, json};
use tokio::sync::Mutex;
use tokio::time::Instant;
use tracing::{error, warn};

/// Requests per minute before slowing down, conservative against AniList's 90.
const RATE_LIMIT_PER_MINUTE: usize = 20;
const WINDOW: Duration = Duration::from_secs(60);
const PAUSE_AFTER_429: Duration = Duration::from_secs(15 * 60);

const FIELDS: &str = "id idMal title { romaji english native } type format status countryOfOrigin \
    episodes duration source popularity favourites startDate { year month day } \
    endDate { year month day } description averageScore hashtag coverImage { large } genres \
    studios { nodes { name } } characters { nodes { name { full } } } \
    relations { edges { relationType node { id title { romaji english } } } }";

#[derive(Default)]
struct Limits {
    recent: VecDeque<Instant>,
    paused_until: Option<Instant>,
}

#[derive(Clone)]
pub struct AniList {
    http: Client,
    pub url: String,
    limits: Arc<Mutex<Limits>>,
}

impl AniList {
    pub fn new(http: Client) -> Self {
        Self {
            http,
            url: "https://graphql.anilist.co".to_owned(),
            limits: Arc::new(Mutex::new(Limits::default())),
        }
    }

    /// A 429 pauses every call for 15 minutes, and the error stops the caller's batch.
    async fn request(&self, query: String, variables: Value) -> Result<Option<Value>> {
        {
            let mut limits = self.limits.lock().await;
            if let Some(until) = limits.paused_until {
                if Instant::now() < until {
                    bail!("AniList API rate limit exceeded (429), still paused");
                }
                limits.paused_until = None;
            }
            limits.recent.retain(|at| at.elapsed() < WINDOW);
            if limits.recent.len() >= RATE_LIMIT_PER_MINUTE - 5
                && let Some(oldest) = limits.recent.front()
            {
                tokio::time::sleep(WINDOW.saturating_sub(oldest.elapsed())).await;
            }
        }
        let sent = self
            .http
            .post(&self.url)
            .timeout(Duration::from_secs(30))
            .header(reqwest::header::ACCEPT, "application/json")
            .json(&json!({"query": query, "variables": variables}))
            .send()
            .await;
        let response = match sent {
            Ok(response) => response,
            Err(failure) => {
                error!(error = %failure.without_url(), "AniList API request failed");
                return Ok(None);
            }
        };
        let status = response.status().as_u16();
        if status == 429 {
            self.limits.lock().await.paused_until = Some(Instant::now() + PAUSE_AFTER_429);
            bail!("AniList API rate limit exceeded (429)");
        }
        self.limits.lock().await.recent.push_back(Instant::now());
        if status != 200 {
            warn!(status, "AniList API request failed");
            return Ok(None);
        }
        let body: Value = match response.json().await {
            Ok(body) => body,
            Err(failure) => {
                error!(error = %failure.without_url(), "AniList API returned bad JSON");
                return Ok(None);
            }
        };
        if let Some(errors) = body.get("errors") {
            error!(%errors, "AniList API error");
        }
        Ok(body.get("data").filter(|data| !data.is_null()).cloned())
    }

    /// Matches for a title, best first.
    pub async fn search_anime(&self, title: &str, limit: i64) -> Result<Vec<Value>> {
        let query = format!(
            "query ($search: String, $perPage: Int) {{ Page (perPage: $perPage) {{ \
             media (search: $search, type: ANIME) {{ {FIELDS} }} }} }}"
        );
        let data = self
            .request(query, json!({"search": title, "perPage": limit}))
            .await?;
        Ok(data
            .as_ref()
            .and_then(|data| data.get("Page"))
            .and_then(|page| page.get("media"))
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default())
    }

    pub async fn anime_by_id(&self, id: i64) -> Result<Option<Value>> {
        let query = format!("query ($id: Int) {{ Media (id: $id, type: ANIME) {{ {FIELDS} }} }}");
        let data = self.request(query, json!({"id": id})).await?;
        Ok(data
            .and_then(|mut data| data.get_mut("Media").map(Value::take))
            .filter(|media| !media.is_null()))
    }
}
