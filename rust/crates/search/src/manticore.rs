//! The HTTP connection to Manticore. Every call either returns what Manticore said or an error.

use std::sync::Arc;
use std::sync::Mutex;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::{Duration, Instant};

use anyhow::{Context, Result};
use nntmux_runtime::Ctx;
use redis::aio::ConnectionManager;
use reqwest::Url;
use serde_json::{Map, Value, json};

use crate::SearchConfig;
use crate::SearchError;
use crate::cache::Cache;
use crate::config::Auth;

const AVAILABILITY_TTL: Duration = Duration::from_secs(30);
const REQUEST_TIMEOUT: Duration = Duration::from_secs(300);
const CONNECT_TIMEOUT: Duration = Duration::from_secs(5);

/// Search reads and index writes. Cheap to clone.
#[derive(Clone)]
pub struct SearchClient {
    pub(crate) config: Arc<SearchConfig>,
    pub(crate) cache: Cache,
    http: reqwest::Client,
    next_endpoint: Arc<AtomicUsize>,
    availability: Arc<Mutex<Option<(Instant, bool)>>>,
}

impl SearchClient {
    pub fn new(config: SearchConfig, redis: Option<ConnectionManager>) -> Result<Self> {
        let http = reqwest::Client::builder()
            .timeout(REQUEST_TIMEOUT)
            .connect_timeout(CONNECT_TIMEOUT)
            .build()
            .context("building the Manticore HTTP client")?;
        Ok(Self {
            config: Arc::new(config),
            cache: Cache::new(redis),
            http,
            next_endpoint: Arc::new(AtomicUsize::new(0)),
            availability: Arc::new(Mutex::new(None)),
        })
    }

    pub fn from_ctx(ctx: &Ctx) -> Result<Self> {
        let config = SearchConfig::from_env(&ctx.config.manticore)?;
        Self::new(config, Some(ctx.redis.clone()))
    }

    pub fn config(&self) -> &SearchConfig {
        &self.config
    }

    /// Whether Manticore answers, remembered for 30 seconds so an outage fails searches fast.
    pub async fn is_available(&self) -> bool {
        if let Ok(guard) = self.availability.lock()
            && let Some((checked, available)) = *guard
            && checked.elapsed() < AVAILABILITY_TTL
        {
            return available;
        }
        let available = self
            .sql("SHOW STATUS")
            .await
            .is_ok_and(|rows| !rows.is_empty());
        if let Ok(mut guard) = self.availability.lock() {
            *guard = Some((Instant::now(), available));
        }
        available
    }

    pub(crate) async fn require_available(&self) -> Result<(), SearchError> {
        if self.is_available().await {
            Ok(())
        } else {
            Err(SearchError::Unavailable(
                "the status check failed".to_owned(),
            ))
        }
    }

    pub(crate) async fn search(&self, body: &Value) -> Result<Value, SearchError> {
        self.send("search", "application/json", body.to_string())
            .await
    }

    /// Runs one statement through `/sql` and returns its rows.
    pub async fn sql(&self, statement: &str) -> Result<Vec<Map<String, Value>>, SearchError> {
        let mut encoder = Url::parse("http://localhost/").expect("a valid constant URL");
        encoder
            .query_pairs_mut()
            .append_pair("mode", "raw")
            .append_pair("query", statement);
        let body = encoder.query().unwrap_or_default().to_owned();
        let response = self
            .send("sql", "application/x-www-form-urlencoded", body)
            .await?;
        let mut rows = Vec::new();
        for result in response.as_array().into_iter().flatten() {
            let data = result.get("data").and_then(Value::as_array);
            for row in data.into_iter().flatten() {
                if let Some(row) = row.as_object() {
                    rows.push(row.clone());
                }
            }
        }
        Ok(rows)
    }

    /// Replaces one document, creating it if it's new.
    pub async fn replace_document(
        &self,
        table: &str,
        id: i64,
        document: &impl serde::Serialize,
    ) -> Result<(), SearchError> {
        let body = json!({"table": table, "id": id, "doc": document});
        self.send("replace", "application/json", body.to_string())
            .await
            .map(drop)
    }

    /// Replaces many documents in one request. `documents` holds each one's id and fields.
    pub async fn replace_documents(
        &self,
        table: &str,
        documents: &[(i64, Value)],
    ) -> Result<(), SearchError> {
        if documents.is_empty() {
            return Ok(());
        }
        let mut body = String::new();
        for (id, document) in documents {
            body.push_str(
                &json!({"replace": {"table": table, "id": id, "doc": document}}).to_string(),
            );
            body.push('\n');
        }
        self.send("bulk", "application/x-ndjson", body)
            .await
            .map(drop)
    }

    pub(crate) async fn delete_documents(
        &self,
        table: &str,
        ids: &[i64],
    ) -> Result<(), SearchError> {
        let body = json!({"table": table, "limit": ids.len(), "id": ids});
        self.send("delete", "application/json", body.to_string())
            .await
            .map(drop)
    }

    async fn send(
        &self,
        path: &str,
        content_type: &str,
        body: String,
    ) -> Result<Value, SearchError> {
        let endpoints = &self.config.endpoints;
        let first = self.next_endpoint.fetch_add(1, Ordering::Relaxed);
        let mut last_error = String::from("no Manticore endpoint is configured");
        for attempt in 0..endpoints.len() {
            let endpoint = &endpoints[(first + attempt) % endpoints.len()];
            let request = self
                .http
                .post(format!("{endpoint}/{path}"))
                .header(reqwest::header::CONTENT_TYPE, content_type)
                .body(body.clone());
            let request = match &self.config.auth {
                Auth::None => request,
                Auth::Bearer(token) => request.bearer_auth(token),
                Auth::Basic(user, password) => request.basic_auth(user, Some(password)),
            };
            match request.send().await {
                Ok(response) => return read_response(response).await,
                Err(error) => last_error = error.without_url().to_string(),
            }
        }
        Err(SearchError::Unavailable(last_error))
    }
}

async fn read_response(response: reqwest::Response) -> Result<Value, SearchError> {
    let status = response.status();
    let text = response
        .text()
        .await
        .map_err(|error| SearchError::Unavailable(error.without_url().to_string()))?;
    let value: Value = match serde_json::from_str(&text) {
        Ok(value) => value,
        // Manticore always answers in JSON, so this came from a proxy or a sign-in check.
        Err(_) if !status.is_success() => {
            return Err(SearchError::Unavailable(format!("HTTP {status}")));
        }
        Err(error) => return Err(SearchError::Malformed(error.to_string())),
    };
    if let Some(error) = response_error(&value) {
        return Err(if is_query_text_error(&error) {
            SearchError::RejectedQuery(error)
        } else {
            SearchError::Query(error)
        });
    }
    if !status.is_success() {
        return Err(SearchError::Query(text));
    }
    Ok(value)
}

/// Whether the query text itself is at fault. HTTP status can't tell: every error here is a 500.
fn is_query_text_error(error: &str) -> bool {
    error.contains("query is non-computable") || error.contains("syntax error")
}

/// An `error` that isn't empty at the top or in any `/sql` result, or `errors` that isn't false.
fn response_error(value: &Value) -> Option<String> {
    let named = |value: &Value| match value.get("error") {
        None | Some(Value::Null) => None,
        Some(Value::String(error)) if error.is_empty() => None,
        Some(Value::String(error)) => Some(error.clone()),
        Some(error) => Some(error.to_string()),
    };
    if let Some(results) = value.as_array() {
        return results.iter().find_map(named);
    }
    if let Some(error) = named(value) {
        return Some(error);
    }
    match value.get("errors") {
        None | Some(Value::Bool(false)) => None,
        Some(_) => Some(format!("the bulk request failed: {value}")),
    }
}

#[cfg(test)]
mod tests {
    use serde_json::json;

    use super::{is_query_text_error, response_error};

    #[test]
    fn only_query_text_errors_count_as_rejected() {
        assert!(is_query_text_error(
            "table t: query error: query is non-computable (single NOT operator)"
        ));
        assert!(is_query_text_error(
            "query error: P08: syntax error, unexpected $end"
        ));
        assert!(!is_query_text_error(
            "unknown local table(s) 'x' in search request"
        ));
        assert!(!is_query_text_error("unknown column: nosuch"));
        assert!(!is_query_text_error(
            "query error: no field 'nosuch' found in schema"
        ));
    }

    #[test]
    fn errors_are_found_in_every_response_shape() {
        assert_eq!(response_error(&json!({"hits": {}})), None);
        assert_eq!(response_error(&json!({"error": ""})), None);
        assert_eq!(response_error(&json!({"error": "bad"})).unwrap(), "bad");
        assert_eq!(response_error(&json!({"errors": false})), None);
        assert!(response_error(&json!({"errors": true})).is_some());
        assert_eq!(response_error(&json!([{"error": ""}])), None);
        assert_eq!(response_error(&json!([{"error": "bad"}])).unwrap(), "bad");
        assert!(response_error(&json!({"error": {"type": "x"}})).is_some());
    }
}
