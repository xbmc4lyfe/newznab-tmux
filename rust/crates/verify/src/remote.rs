//! A worker host without the primary's NZB storage asks the primary to publish its spot-checked
//! candidate. Only the sampled evidence travels; the primary rebuilds the manifest from its copy.

use std::time::Duration;

use anyhow::{Context, Result, bail};
use serde_json::{Value, json};

use crate::candidates::{self, Claim};
use crate::publisher::PublishError;
use crate::spot_check::{self, VerificationResult};

const ATTEMPTS: u32 = 3;
const RETRY_DELAY: Duration = Duration::from_secs(1);
const CONNECT_TIMEOUT: Duration = Duration::from_secs(5);

#[derive(Clone, Debug)]
pub struct RemotePublisher {
    client: reqwest::Client,
    url: String,
}

impl RemotePublisher {
    pub fn new(url: &str, timeout_seconds: u64) -> Result<Self> {
        let client = reqwest::Client::builder()
            .connect_timeout(CONNECT_TIMEOUT)
            .timeout(Duration::from_secs(timeout_seconds.max(10)))
            .build()
            .context("building the publication client")?;
        Ok(Self {
            client,
            url: url.trim().to_owned(),
        })
    }

    /// The release the primary published, or `None` when it settled the candidate without one. A
    /// repeated request after a lost response finds the release it already published.
    pub async fn publish(&self, claim: &Claim, result: &VerificationResult) -> Result<Option<i64>> {
        if result.verdict != "sampled" {
            bail!("Only spot-checked results can be published by a remote worker.");
        }
        let manifest = result
            .manifest
            .as_ref()
            .context("A passed result has no manifest.")?;
        let payload = json!({
            "claim": {
                "id": claim.id(),
                "version": claim.candidate.version,
                "manifest_hash": claim.candidate.manifest_hash,
                "lease_token": claim.token,
            },
            "reason": result.reason,
            "evidence": spot_check::evidence(manifest)?,
        });
        let response = self.send(&payload).await?;
        let status = response.status();
        let body: Value = response.json().await.unwrap_or(Value::Null);
        if !status.is_success() {
            if let Some(reason) = body["deferred"].as_str() {
                // Handled as a local publication's deferral: back to waiting for late articles.
                return Err(PublishError::PartialDeferred(reason.to_owned()).into());
            }
            let error = body["error"]
                .as_str()
                .map_or_else(|| format!("HTTP {}", status.as_u16()), str::to_owned);
            bail!(
                "Remote publication failed: {}",
                candidates::cut(&error, 500)
            );
        }
        Ok(body["release_id"].as_i64())
    }

    async fn send(&self, payload: &Value) -> Result<reqwest::Response> {
        let mut attempt = 1;
        loop {
            let sent = self
                .client
                .post(&self.url)
                .header("accept", "application/json")
                .json(payload)
                .send()
                .await;
            match sent {
                Ok(response) => return Ok(response),
                Err(_) if attempt < ATTEMPTS => {
                    attempt += 1;
                    tokio::time::sleep(RETRY_DELAY).await;
                }
                Err(failure) => {
                    return Err(failure).context("Remote publication could not reach the primary");
                }
            }
        }
    }
}
