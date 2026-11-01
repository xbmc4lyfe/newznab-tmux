//! Shared HTTP plumbing for remote feeds.

use std::io::Read;
use std::time::{Duration, Instant};

use anyhow::{Context, anyhow, bail};
use futures_util::StreamExt;
use reqwest::header::{ACCEPT_ENCODING, CONTENT_ENCODING, HeaderMap, RETRY_AFTER};
use reqwest::{Client, Response, StatusCode};
use serde_json::Value;

use super::{FeedConfig, FeedError, FeedResult};

/// The connection every source of one run shares.
#[derive(Clone)]
pub struct FeedHttp {
    client: Client,
    timeout: Duration,
}

impl FeedHttp {
    pub fn new(config: &FeedConfig) -> anyhow::Result<Self> {
        let timeout = Duration::from_secs(config.timeout_seconds);
        let client = Client::builder()
            .timeout(timeout)
            .connect_timeout(timeout.min(Duration::from_secs(10)))
            .user_agent(&config.user_agent)
            .no_gzip()
            .build()
            .context("building the feed HTTP client")?;
        Ok(Self { client, timeout })
    }

    pub fn client(&self) -> &Client {
        &self.client
    }

    /// Sends with one retry on connection errors and 5xx. A 429 goes straight back to the caller
    /// as `RateLimited`, for the importer's `Retry-After` backoff.
    pub async fn send(
        &self,
        request: impl Fn(&Client) -> reqwest::RequestBuilder,
    ) -> FeedResult<Response> {
        let mut last = None;
        for attempt in 0..2 {
            if attempt > 0 {
                tokio::time::sleep(Duration::from_millis(500)).await;
            }
            match request(&self.client).send().await {
                Ok(response) if response.status().is_server_error() => {
                    last = Some(anyhow!("HTTP {}", response.status()));
                }
                Ok(response) if response.status() == StatusCode::TOO_MANY_REQUESTS => {
                    return Err(throttled(response.headers()));
                }
                Ok(response) => return Ok(response),
                Err(error) if error.is_connect() || error.is_timeout() => {
                    last = Some(error.into());
                }
                Err(error) => return Err(FeedError::Failed(error.into())),
            }
        }
        Err(FeedError::Failed(
            last.unwrap_or_else(|| anyhow!("no attempt ran")),
        ))
    }

    /// GETs a JSON document, failing on any non-2xx status.
    pub async fn get_json(&self, url: &str, query: &[(&str, String)]) -> FeedResult<Value> {
        let mut url = reqwest::Url::parse(url).context("parsing the feed URL")?;
        if !query.is_empty() {
            url.query_pairs_mut()
                .extend_pairs(query.iter().map(|(k, v)| (*k, v.as_str())));
        }
        let response = self.send(|client| client.get(url.clone())).await?;
        read_json(response).await
    }

    /// Streams a catalogue with wire and decoded byte caps and one deadline for both. Only
    /// identity and complete gzip members are accepted.
    pub async fn download_body(
        &self,
        url: &str,
        key: &str,
        maximum_bytes: usize,
        attempts: u32,
    ) -> FeedResult<Vec<u8>> {
        let label = key.to_uppercase();
        let mut last = None;
        let mut sent = None;
        for attempt in 0..attempts.max(1) {
            if attempt > 0 {
                tokio::time::sleep(Duration::from_millis(500)).await;
            }
            match self
                .client
                .get(url)
                .header(ACCEPT_ENCODING, "gzip")
                .send()
                .await
            {
                Ok(response) if response.status().is_server_error() => {
                    last = Some(anyhow!("HTTP {}", response.status()));
                }
                Ok(response) => {
                    sent = Some(response);
                    break;
                }
                Err(error) if error.is_connect() || error.is_timeout() => {
                    last = Some(error.into());
                }
                Err(error) => return Err(FeedError::Failed(error.into())),
            }
        }
        let Some(response) = sent else {
            return Err(FeedError::Failed(
                last.unwrap_or_else(|| anyhow!("no attempt ran")),
            ));
        };
        if response.status() == StatusCode::TOO_MANY_REQUESTS {
            return Err(throttled(response.headers()));
        }
        if !response.status().is_success() {
            return Err(FeedError::Failed(anyhow!(
                "{label} returned an unsuccessful listing response: HTTP {}",
                response.status()
            )));
        }
        let encoding = response
            .headers()
            .get(CONTENT_ENCODING)
            .and_then(|value| value.to_str().ok())
            .unwrap_or("")
            .trim()
            .to_ascii_lowercase();
        if !matches!(encoding.as_str(), "" | "identity" | "gzip") {
            return Err(FeedError::Failed(anyhow!(
                "{label} returned an unsupported content encoding."
            )));
        }
        let deadline = Instant::now() + self.timeout;
        let mut body = Vec::new();
        let mut stream = response.bytes_stream();
        loop {
            let remaining = deadline.saturating_duration_since(Instant::now());
            let chunk = tokio::time::timeout(remaining, stream.next())
                .await
                .map_err(|_| anyhow!("{label} response exceeded the total body-read deadline."))?;
            let Some(chunk) = chunk else {
                break;
            };
            body.extend_from_slice(&chunk?);
            if body.len() > maximum_bytes {
                return Err(FeedError::Failed(anyhow!(
                    "{label} response exceeds the body limit."
                )));
            }
        }
        if encoding == "gzip" {
            return Ok(decode_gzip(&body, maximum_bytes, &label)?);
        }
        Ok(body)
    }
}

pub(crate) fn throttled(headers: &HeaderMap) -> FeedError {
    let text = |value: Option<&reqwest::header::HeaderValue>| {
        value.and_then(|v| v.to_str().ok()).map(str::to_owned)
    };
    FeedError::RateLimited {
        message: "HTTP 429".to_owned(),
        retry_after: text(headers.get(RETRY_AFTER)),
        reset_at: text(headers.get("X-RateLimit-Reset")),
    }
}

pub(crate) async fn read_json(response: Response) -> FeedResult<Value> {
    let status = response.status();
    if !status.is_success() {
        return Err(FeedError::Failed(anyhow!("HTTP {status}")));
    }
    let bytes = response.bytes().await?;
    Ok(serde_json::from_slice(&decode_if_gzip(&bytes)?)
        .context("the feed returned invalid JSON")?)
}

/// JSON requests don't ask for gzip, but a server may send it anyway.
fn decode_if_gzip(bytes: &[u8]) -> anyhow::Result<std::borrow::Cow<'_, [u8]>> {
    if bytes.starts_with(&[0x1f, 0x8b]) {
        let mut out = Vec::new();
        flate2::read::MultiGzDecoder::new(bytes).read_to_end(&mut out)?;
        Ok(out.into())
    } else {
        Ok(bytes.into())
    }
}

/// Decodes every gzip member within the budget. A truncated member or stray bytes after one
/// are errors, because a partial catalogue must never look complete.
pub fn decode_gzip(body: &[u8], maximum_bytes: usize, label: &str) -> anyhow::Result<Vec<u8>> {
    let mut decoded = Vec::new();
    let mut rest = body;
    loop {
        let mut decoder = flate2::bufread::GzDecoder::new(rest);
        let budget = (maximum_bytes - decoded.len()) as u64 + 1;
        let read = (&mut decoder).take(budget).read_to_end(&mut decoded);
        if read.is_err() {
            bail!("{label} gzip response is malformed or incomplete.");
        }
        if decoded.len() > maximum_bytes {
            bail!("{label} response exceeds the body limit.");
        }
        rest = decoder.into_inner();
        if rest.is_empty() {
            return Ok(decoded);
        }
    }
}
