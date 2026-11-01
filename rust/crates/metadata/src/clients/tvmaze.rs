//! TVMaze. Port of `TvMazeClient` and the vendor value objects it fills. It needs no key and
//! allows about 20 calls per 10 seconds per IP.

use std::sync::Arc;
use std::time::Duration;

use anyhow::Result;
use reqwest::{Client, Response};
use serde_json::Value;
use tokio::sync::Mutex;
use tokio::time::Instant;

use crate::http::{TransientError, is_transient_status, retry_after_of, send_retrying};
use crate::php;

/// A show, with what `DariusIII\TVMaze\TVShow` derived from the response.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct MazeShow {
    pub id: Value,
    pub name: String,
    pub premiered: Option<String>,
    pub summary: String,
    pub network: String,
    pub web_channel: String,
    pub country: String,
    pub timezone: String,
    pub externals: Value,
    pub akas: Vec<String>,
    pub image: String,
}

impl MazeShow {
    pub fn parse(show: &Value) -> Self {
        let network = show.get("network").unwrap_or(&Value::Null);
        let web = show.get("webChannel").unwrap_or(&Value::Null);
        let mut country = php::text(
            network
                .get("country")
                .and_then(|country| country.get("code")),
        );
        if let Some(web_country) = web.get("country").filter(|country| !country.is_null()) {
            country = php::text(web_country.get("code"));
        }
        let zone = |channel: &Value| {
            php::text(
                channel
                    .get("country")
                    .and_then(|country| country.get("timezone")),
            )
        };
        let timezone = if network.is_null() || network.get("country").is_none_or(Value::is_null) {
            zone(web)
        } else {
            zone(network)
        };
        let image = show.get("image").unwrap_or(&Value::Null);
        let akas = php::items(
            show.get("_embedded")
                .and_then(|embedded| embedded.get("akas")),
        )
        .iter()
        .map(|aka| php::text(aka.get("name")))
        .filter(|name| !name.is_empty() && name != "0")
        .collect();
        let original = php::text(image.get("original"));
        Self {
            id: show.get("id").cloned().unwrap_or(Value::Null),
            name: php::text(show.get("name")),
            premiered: show
                .get("premiered")
                .and_then(Value::as_str)
                .map(str::to_owned),
            summary: php::strip_tags(&php::text(show.get("summary"))),
            network: php::text(network.get("name")),
            web_channel: php::text(web.get("name")),
            country,
            timezone,
            externals: show.get("externals").cloned().unwrap_or(Value::Null),
            akas,
            image: if original.is_empty() {
                php::text(image.get("medium"))
            } else {
                original
            },
        }
    }
}

#[derive(Clone)]
pub struct TvMaze {
    http: Client,
    pub base_url: String,
    timeout: Duration,
    tries: u32,
    /// Minimum gap between requests from this process.
    pub min_interval: Duration,
    last_request: Arc<Mutex<Option<Instant>>>,
}

impl TvMaze {
    pub fn new(http: Client, timeout: u64, tries: u32) -> Self {
        Self {
            http,
            base_url: "https://api.tvmaze.com".to_owned(),
            timeout: Duration::from_secs(timeout),
            tries,
            min_interval: Duration::from_secs(1),
            last_request: Arc::new(Mutex::new(None)),
        }
    }

    pub async fn search_shows(&self, name: &str) -> Result<Vec<MazeShow>> {
        Ok(self
            .get_list("/search/shows", &[("q", name.to_owned())])
            .await?
            .iter()
            .filter_map(|result| result.get("show").filter(|show| show.is_object()))
            .map(MazeShow::parse)
            .collect())
    }

    pub async fn single_search_akas(&self, name: &str) -> Result<Option<MazeShow>> {
        let show = self
            .get(
                "/singlesearch/shows",
                &[("q", name.to_owned()), ("embed", "akas".to_owned())],
            )
            .await?;
        Ok(show.as_ref().map(MazeShow::parse))
    }

    pub async fn lookup(&self, site: &str, id: &str) -> Result<Option<MazeShow>> {
        let show = self.get("/lookup/shows", &[(site, id.to_owned())]).await?;
        Ok(show.as_ref().map(MazeShow::parse))
    }

    pub async fn akas(&self, show_id: i64) -> Result<Vec<String>> {
        Ok(self
            .get_list(&format!("/shows/{show_id}/akas"), &[])
            .await?
            .iter()
            .filter_map(|aka| aka.get("name").and_then(Value::as_str))
            .filter(|name| !name.is_empty())
            .map(str::to_owned)
            .collect())
    }

    pub async fn episodes_by_show(&self, show_id: i64) -> Result<Vec<Value>> {
        self.get_list(
            &format!("/shows/{show_id}/episodes"),
            &[("specials", "1".to_owned())],
        )
        .await
    }

    pub async fn episode_by_number(
        &self,
        show_id: i64,
        season: i64,
        episode: i64,
    ) -> Result<Option<Value>> {
        self.get(
            &format!("/shows/{show_id}/episodebynumber"),
            &[
                ("season", season.to_string()),
                ("number", episode.to_string()),
            ],
        )
        .await
    }

    pub async fn episodes_by_date(&self, show_id: i64, airdate: &str) -> Result<Vec<Value>> {
        self.get_list(
            &format!("/shows/{show_id}/episodesbydate"),
            &[("date", airdate.to_owned())],
        )
        .await
    }

    async fn get_list(&self, path: &str, query: &[(&str, String)]) -> Result<Vec<Value>> {
        let Some(Value::Array(items)) = self.get(path, query).await? else {
            return Ok(Vec::new());
        };
        Ok(items
            .into_iter()
            .filter(|item| item.is_object() || item.is_array())
            .collect())
    }

    /// A JSON document, `None` when TVMaze has nothing for it. Rate limits, server errors and
    /// connection failures are [`TransientError`]s.
    async fn get(&self, path: &str, query: &[(&str, String)]) -> Result<Option<Value>> {
        self.throttle().await;
        let request = self
            .http
            .get(format!("{}{path}", self.base_url))
            .header(reqwest::header::ACCEPT, "application/json")
            .timeout(self.timeout)
            .query(query);
        let response = send_retrying(request, self.tries, Duration::from_millis(500), |_| false)
            .await
            .map_err(|failure| TransientError {
                provider: "tvmaze",
                message: format!("TVMaze connection failed: {}", failure.without_url()),
                status: None,
                retry_after: None,
            })?;
        Self::decode(response, path).await
    }

    async fn decode(response: Response, path: &str) -> Result<Option<Value>> {
        let status = response.status().as_u16();
        if is_transient_status(status) {
            return Err(TransientError {
                provider: "tvmaze",
                message: format!("TVMaze returned {status} for {path}"),
                status: Some(status),
                retry_after: retry_after_of(&response),
            }
            .into());
        }
        if !response.status().is_success() {
            return Ok(None);
        }
        let data: Option<Value> = response.json().await.ok();
        Ok(data.filter(|data| match data {
            Value::Array(items) => !items.is_empty(),
            Value::Object(fields) => !fields.is_empty(),
            _ => false,
        }))
    }

    async fn throttle(&self) {
        let mut last = self.last_request.lock().await;
        if let Some(previous) = *last {
            let elapsed = previous.elapsed();
            if elapsed < self.min_interval {
                tokio::time::sleep(self.min_interval - elapsed).await;
            }
        }
        *last = Some(Instant::now());
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn web_channel_country_wins() {
        let show = MazeShow::parse(&json!({
            "id": 5, "name": "A", "summary": "<p>Text</p>",
            "network": {"name": "HBO", "country": {"code": "US", "timezone": "America/New_York"}},
            "webChannel": {"name": "Max", "country": {"code": "GB", "timezone": "Europe/London"}},
            "image": {"medium": "m.jpg", "original": "o.jpg"},
            "_embedded": {"akas": [{"name": "B"}, {"name": ""}]},
        }));
        assert_eq!(show.country, "GB");
        assert_eq!(show.timezone, "America/New_York");
        assert_eq!(show.network, "HBO");
        assert_eq!(show.summary, "Text");
        assert_eq!(show.image, "o.jpg");
        assert_eq!(show.akas, ["B"]);

        let web_only = MazeShow::parse(&json!({
            "id": 6, "name": "C", "network": null,
            "webChannel": {"name": "Netflix", "country": null},
            "image": {"medium": "m.jpg"},
        }));
        assert_eq!(web_only.country, "");
        assert_eq!(web_only.web_channel, "Netflix");
        assert_eq!(web_only.image, "m.jpg");
    }
}
