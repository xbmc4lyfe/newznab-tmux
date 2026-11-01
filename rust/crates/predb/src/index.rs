//! The Manticore PreDB table: keeping it in step with writes, and exact-name lookups.

use anyhow::{Context, Result};
use nntmux_core::ManticoreConfig;
use reqwest::Client;
use serde_json::{Value, json};
use sqlx::MySqlPool;

use crate::filename::exact_value;
use crate::selector::PredbHit;

/// A hung Manticore must not stall release creation or an import.
const REQUEST_TIMEOUT: std::time::Duration = std::time::Duration::from_secs(30);

#[derive(Clone)]
pub struct PredbIndex {
    client: Client,
    base: Option<String>,
    table: String,
}

impl PredbIndex {
    pub fn new(config: &ManticoreConfig) -> Self {
        Self::at(
            format!("http://{}:{}", config.host, config.http_port),
            std::env::var("MANTICORESEARCH_INDEX_PREDB").unwrap_or_else(|_| "predb_rt".to_owned()),
        )
    }

    pub fn at(base: String, table: String) -> Self {
        let client = Client::builder()
            .timeout(REQUEST_TIMEOUT)
            .build()
            .unwrap_or_default();
        Self {
            client,
            base: Some(base),
            table,
        }
    }

    /// An index that drops writes and finds nothing, for dry runs and tests.
    pub fn disabled() -> Self {
        Self {
            client: Client::new(),
            base: None,
            table: String::new(),
        }
    }

    /// A failed write is logged and never fails the import: an idempotent re-index from a later
    /// run recovers the document.
    pub async fn replace(&self, id: u64, title: &str, filename: &str, source: &str) {
        let Some(base) = &self.base else {
            return;
        };
        let body = json!({
            "table": self.table,
            "id": id,
            "doc": {"title": title, "filename": filename, "source": source},
        });
        let outcome = self
            .client
            .post(format!("{base}/replace"))
            .json(&body)
            .send()
            .await;
        if let Err(error) = outcome.and_then(reqwest::Response::error_for_status) {
            tracing::error!(predb_id = id, %error, "Manticore predb write failed");
        }
    }

    async fn search(&self, query: &str, limit: u32) -> Result<Vec<PredbHit>> {
        let Some(base) = &self.base else {
            return Ok(Vec::new());
        };
        let body = json!({
            "table": self.table,
            "query": {"query_string": query},
            "limit": limit,
        });
        let response: Value = self
            .client
            .post(format!("{base}/search"))
            .json(&body)
            .send()
            .await?
            .error_for_status()?
            .json()
            .await
            .context("reading the Manticore search response")?;
        let empty = Vec::new();
        let hits = response["hits"]["hits"].as_array().unwrap_or(&empty);
        Ok(hits.iter().filter_map(hit_from_row).collect())
    }

    /// `matchPredbExact`: a row whose whole title, then whose whole filename, is the name.
    /// Lookup failures are logged and answer `None`.
    pub async fn match_exact(&self, name: &str) -> Option<PredbHit> {
        if !name.chars().any(char::is_alphanumeric) {
            return None;
        }
        let literal = escape_query(name);
        let wanted = exact_value(name);
        for field in ["title", "filename"] {
            let query = format!("@{field} \"^{literal}$\"");
            let hits = match self.search(&query, 5).await {
                Ok(hits) => hits,
                Err(error) => {
                    tracing::warn!(%error, "Manticore PreDB exact lookup failed");
                    return None;
                }
            };
            for hit in hits {
                let value = if field == "title" {
                    &hit.title
                } else {
                    &hit.filename
                };
                if hit.id > 0 && !hit.title.is_empty() && exact_value(value) == wanted {
                    return Some(hit);
                }
            }
        }
        None
    }
}

fn escape_query(name: &str) -> String {
    let mut out = String::with_capacity(name.len());
    for c in name.chars() {
        if "\\()|!@~\"&/^$=<>[]{}*-?".contains(c) {
            out.push('\\');
        }
        out.push(c);
    }
    out
}

fn hit_from_row(row: &Value) -> Option<PredbHit> {
    let id = match &row["_id"] {
        Value::Number(id) => id.as_i64(),
        Value::String(id) => id.parse().ok(),
        _ => None,
    }?;
    let source = &row["_source"];
    let text = |field: &str| source[field].as_str().unwrap_or("").to_owned();
    Some(PredbHit {
        id,
        title: text("title"),
        filename: text("filename"),
        source: text("source"),
    })
}

/// The PreDB row a new release's cleaned name matched.
#[derive(Clone, Debug, PartialEq)]
pub struct PredbMatch {
    pub title: String,
    pub predb_id: u64,
}

/// `Predb::matchPre`: matches one release to a PreDB title when it's created. The index only
/// nominates; the current row must have the name as its exact title or filename.
pub async fn match_pre(
    db: &MySqlPool,
    index: &PredbIndex,
    name: &str,
) -> Result<Option<PredbMatch>> {
    if name.trim().is_empty() {
        return Ok(None);
    }
    let Some(hit) = index.match_exact(name).await else {
        return Ok(None);
    };
    let Ok(id) = u64::try_from(hit.id) else {
        return Ok(None);
    };
    let row: Option<(u64, String, String)> =
        sqlx::query_as("SELECT id, title, filename FROM predb WHERE id = ?")
            .bind(id)
            .fetch_optional(db)
            .await?;
    let Some((predb_id, title, filename)) = row else {
        return Ok(None);
    };
    let wanted = exact_value(name);
    if title.is_empty() || (exact_value(&title) != wanted && exact_value(&filename) != wanted) {
        return Ok(None);
    }
    Ok(Some(PredbMatch { title, predb_id }))
}
