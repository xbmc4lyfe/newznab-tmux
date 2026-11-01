//! Fanart.tv. Port of `FanartTvService`.

use std::time::Duration;

use reqwest::Client;
use serde_json::Value;
use tracing::{debug, warn};

use crate::cache::Cache;
use crate::http::send_retrying;
use crate::php;

const CACHE_TTL: Duration = Duration::from_secs(24 * 3600);

#[derive(Clone)]
pub struct Fanart {
    http: Client,
    cache: Cache,
    pub base_url: String,
    api_key: String,
    timeout: Duration,
    tries: u32,
    delay: Duration,
}

impl Fanart {
    pub fn new(
        http: Client,
        cache: Cache,
        api_key: String,
        timeout: u64,
        tries: u32,
        delay_ms: u64,
    ) -> Self {
        Self {
            http,
            cache,
            base_url: "https://webservice.fanart.tv/v3".to_owned(),
            api_key,
            timeout: Duration::from_secs(timeout),
            tries,
            delay: Duration::from_millis(delay_ms),
        }
    }

    pub fn is_configured(&self) -> bool {
        !self.api_key.is_empty()
    }

    async fn get(&self, endpoint: &str) -> Option<Value> {
        if !self.is_configured() {
            return None;
        }
        let request = self
            .http
            .get(format!("{}/{endpoint}", self.base_url))
            .timeout(self.timeout)
            .query(&[("api_key", &self.api_key)]);
        let response = match send_retrying(request, self.tries, self.delay, |_| true).await {
            Ok(response) => response,
            Err(failure) => {
                warn!(endpoint, error = %failure.without_url(), "FanartTV API request exception");
                return None;
            }
        };
        let status = response.status();
        if status.is_success() {
            let data: Value = response.json().await.ok()?;
            if data.get("status").and_then(Value::as_str) == Some("error") {
                debug!(endpoint, "FanartTV API returned error status");
                return None;
            }
            return Some(data);
        }
        if status.as_u16() == 404 {
            debug!(endpoint, "FanartTV: resource not found");
        } else {
            warn!(
                endpoint,
                status = status.as_u16(),
                "FanartTV API request failed"
            );
        }
        None
    }

    async fn cached(&self, key: &str, endpoint: &str) -> Option<Value> {
        if let Some(value) = self.cache.get::<Value>(key).await {
            return Some(value);
        }
        let value = self.get(endpoint).await?;
        self.cache.put(key, &value, CACHE_TTL).await;
        Some(value)
    }

    pub async fn tv_fan_art(&self, tvdb_id: i64) -> Option<Value> {
        self.cached(&format!("fanarttv_tv_{tvdb_id}"), &format!("tv/{tvdb_id}"))
            .await
    }

    /// The most liked poster of a show.
    pub async fn best_tv_poster(&self, tvdb_id: i64) -> Option<String> {
        let art = self.tv_fan_art(tvdb_id).await?;
        // `sortByDesc` is stable, so the first of equals wins.
        let mut best: Option<&Value> = None;
        for poster in php::items(art.get("tvposter")) {
            if best.is_none_or(|current| {
                php::int(poster.get("likes")) > php::int(current.get("likes"))
            }) {
                best = Some(poster);
            }
        }
        best.map(|poster| php::text(poster.get("url")))
            .filter(|url| !url.is_empty())
    }
}
