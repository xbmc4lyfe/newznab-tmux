//! Keeps the Manticore `predb_rt` table in step with feed writes. A failed write is logged and
//! never fails the import: an idempotent re-index from a later run recovers the document.

use nntmux_core::ManticoreConfig;
use reqwest::Client;
use serde_json::json;

#[derive(Clone)]
pub struct PredbIndex {
    client: Client,
    url: Option<String>,
}

impl PredbIndex {
    pub fn new(config: &ManticoreConfig) -> Self {
        Self {
            client: Client::new(),
            url: Some(format!(
                "http://{}:{}/replace",
                config.host, config.http_port
            )),
        }
    }

    /// An index that drops everything, for dry runs and tests.
    pub fn disabled() -> Self {
        Self {
            client: Client::new(),
            url: None,
        }
    }

    pub async fn replace(&self, id: u64, title: &str, filename: &str, source: &str) {
        let Some(url) = &self.url else {
            return;
        };
        let body = json!({
            "table": "predb_rt",
            "id": id,
            "doc": {"title": title, "filename": filename, "source": source},
        });
        let outcome = self.client.post(url).json(&body).send().await;
        match outcome.and_then(reqwest::Response::error_for_status) {
            Ok(_) => {}
            Err(error) => tracing::error!(predb_id = id, %error, "Manticore predb write failed"),
        }
    }
}
