//! OMDb. Port of the vendor `aharen\OMDbAPI` calls `MovieService` makes.

use std::sync::LazyLock;
use std::time::Duration;

use nntmux_core::pcre::Pcre;
use reqwest::Client;
use serde_json::Value;

static VALID_ID: LazyLock<Pcre> = LazyLock::new(|| {
    Pcre::new(r"/ev\d{7}\/(19|20)\d{2}(\/[12])?|tt\d{7,8}\/characters\/nm\d{7,8}|(tt|ni|nm)\d{8}|(ch|co|ev|tt|nm)\d{7}/")
        .expect("a valid regex")
});

#[derive(Clone)]
pub struct Omdb {
    http: Client,
    pub base_url: String,
    api_key: String,
}

impl Omdb {
    pub fn new(http: Client, api_key: String) -> Self {
        Self {
            http,
            base_url: "https://www.omdbapi.com".to_owned(),
            api_key,
        }
    }

    pub fn is_configured(&self) -> bool {
        !self.api_key.is_empty()
    }

    /// A 200's JSON. `None` stands for every other answer the callers treat as a miss.
    async fn get(&self, query: &[(&str, &str)]) -> Option<Value> {
        let response = self
            .http
            .get(&self.base_url)
            .query(query)
            .query(&[("apikey", &self.api_key)])
            .timeout(Duration::from_secs(30))
            .send()
            .await
            .ok()?;
        if response.status() != reqwest::StatusCode::OK {
            return None;
        }
        response.json().await.ok()
    }

    /// By IMDb id, with its `tt` prefix.
    pub async fn fetch(&self, imdb: &str) -> Option<Value> {
        if !VALID_ID.is_match(imdb.as_bytes()) {
            return None;
        }
        self.get(&[("i", imdb)]).await
    }

    pub async fn search(&self, keyword: &str, year: Option<&str>) -> Option<Value> {
        let mut query = vec![("s", keyword), ("type", "movie")];
        if let Some(year) = year {
            query.push(("y", year));
        }
        self.get(&query).await
    }
}
