//! Serve stage: answer Newznab requests for NZBHydra2.
//!
//! `GET /api` takes `t=caps`, `search`, `tvsearch`, `movie`, `get` and `details`. Text searches
//! go to the Tantivy index and ID searches (`tvdbid`, `tvmazeid`, `rid`, `traktid`, `tmdbid`,
//! `imdbid`) to the catalog's ID indexes; the catalog fills in each result either way. Only TV
//! and movie categories are served, and anime is left out. Answers are XML.

mod categories;
mod engine;
mod params;
mod request;
mod sync;
mod xml;

use std::io;
use std::sync::Arc;
use std::time::{Instant, SystemTime, UNIX_EPOCH};

use anyhow::{Context, Result};
use axum::Router;
use axum::extract::{RawQuery, State};
use axum::http::header::{CACHE_CONTROL, CONTENT_DISPOSITION, CONTENT_TYPE, HOST};
use axum::http::{HeaderMap, HeaderValue, StatusCode};
use axum::response::{IntoResponse, Response};
use axum::routing::get;
use indexer_config::{ApiConfig, Secret};
use indexer_search::TextIndex;
use indexer_store::{Catalog, MAX_DECODED_BYTES, NzbStore, StorePaths, gunzip_capped};
use indexer_telemetry::catalog as metric;
use tokio::net::TcpListener;
use tokio_util::sync::CancellationToken;
use tracing::error;

use crate::engine::Pool;
use crate::params::Params;
use crate::request::{ApiError, Function, Search};

pub use crate::sync::{open_index, rebuild_index};

const XML: &str = "application/xml; charset=utf-8";
const NZB: &str = "application/x-nzb";

/// An answer before it becomes an HTTP response.
#[derive(Debug)]
pub struct Reply {
    pub status: StatusCode,
    pub content_type: &'static str,
    pub body: Vec<u8>,
    /// For an NZB, the name a client should save it under.
    pub filename: Option<String>,
}

impl Reply {
    fn xml(body: String) -> Self {
        Self {
            status: StatusCode::OK,
            content_type: XML,
            body: body.into_bytes(),
            filename: None,
        }
    }

    /// Newznab clients read the error element, so an error still answers 200, except a bad key,
    /// which answers 401 too so that a misconfigured client shows up in access logs.
    fn error(error: &ApiError) -> Self {
        let mut reply = Self::xml(xml::error(error.code(), &error.description()));
        if *error == ApiError::Credentials {
            reply.status = StatusCode::UNAUTHORIZED;
        }
        reply
    }

    pub fn text(&self) -> &str {
        std::str::from_utf8(&self.body).unwrap_or("")
    }
}

impl IntoResponse for Reply {
    fn into_response(self) -> Response {
        let mut response = (self.status, self.body).into_response();
        let headers = response.headers_mut();
        headers.insert(CONTENT_TYPE, HeaderValue::from_static(self.content_type));
        headers.insert(CACHE_CONTROL, HeaderValue::from_static("no-store"));
        if let Some(name) = self.filename {
            let value = format!("attachment; filename=\"{name}.nzb\"");
            if let Ok(value) = HeaderValue::from_str(&value) {
                headers.insert(CONTENT_DISPOSITION, value);
            }
        }
        response
    }
}

struct Inner {
    index: Arc<dyn TextIndex>,
    catalogs: Pool<Catalog>,
    nzbs: Pool<NzbStore>,
    api_keys: Vec<Secret<String>>,
    public_url: Option<String>,
    max_results: usize,
}

/// The Newznab API over a text index and a store. Cheap to clone.
#[derive(Clone)]
pub struct Api(Arc<Inner>);

impl Api {
    /// Opens the store read-only. Fails now, rather than on the first request, when it can't.
    pub fn new(config: &ApiConfig, paths: &StorePaths, index: Arc<dyn TextIndex>) -> Result<Self> {
        let catalog_path = paths.catalog.clone();
        let nzb_path = paths.nzbs.clone();
        let catalogs = Pool::new(move || Catalog::open_read_only(&catalog_path));
        let nzbs = Pool::new(move || NzbStore::open_read_only(&nzb_path));
        catalogs
            .with(|_| Ok(()))
            .context("opening the catalog for the API")?;
        nzbs.with(|_| Ok(())).context("opening NZBs for the API")?;
        Ok(Self(Arc::new(Inner {
            index,
            catalogs,
            nzbs,
            api_keys: config.api_keys.clone(),
            public_url: config
                .public_url
                .as_deref()
                .map(|url| url.trim_end_matches('/').to_owned()),
            max_results: config.max_results,
        })))
    }

    pub fn router(&self) -> Router {
        Router::new()
            .route("/api", get(handle))
            .with_state(self.clone())
    }

    /// Answers one request from its query string and `Host` header. Blocks on the index and the
    /// catalog, and records the request's metrics.
    pub fn respond(&self, query: &str, host: Option<&str>) -> Reply {
        let started = Instant::now();
        let params = Params::parse(query);
        let kind = request::function_label(&params);
        let (reply, status) = match self.answer(&params, host, kind) {
            Ok(reply) => (reply, "ok"),
            Err(error) => {
                let status = match error {
                    ApiError::Credentials => "100",
                    ApiError::Missing(_) => "200",
                    ApiError::Incorrect(_) => "201",
                    ApiError::NoFunction => "202",
                    ApiError::NoItem => "300",
                    ApiError::Internal => "900",
                };
                (Reply::error(&error), status)
            }
        };
        metrics::counter!(metric::API_REQUESTS, "type" => kind, "status" => status).increment(1);
        metrics::histogram!(metric::API_REQUEST_DURATION, "type" => kind)
            .record(started.elapsed().as_secs_f64());
        reply
    }

    fn answer(
        &self,
        params: &Params,
        host: Option<&str>,
        kind: &'static str,
    ) -> Result<Reply, ApiError> {
        let function = request::parse(params, self.0.max_results)?;
        if function == Function::Caps {
            return Ok(Reply::xml(xml::caps(self.0.max_results)));
        }
        let apikey = params.get("apikey").ok_or(ApiError::Credentials)?;
        if !self.key_is_valid(apikey) {
            return Err(ApiError::Credentials);
        }
        let base = self.base_url(host);
        let links = xml::Links {
            base: &base,
            apikey,
        };
        match function {
            Function::Caps => unreachable!("answered above"),
            Function::Search(search) => self.search(&links, &search, kind),
            Function::Details(guid) => {
                let item = self
                    .0
                    .catalogs
                    .with(|catalog| engine::item_by_guid(catalog, &guid))
                    .map_err(internal)?
                    .ok_or(ApiError::NoItem)?;
                Ok(Reply::xml(xml::results(&links, 0, 1, &[item])))
            }
            Function::Get(guid) => self.nzb(&guid),
        }
    }

    fn search(
        &self,
        links: &xml::Links<'_>,
        search: &Search,
        kind: &'static str,
    ) -> Result<Reply, ApiError> {
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map_or(0, |elapsed| elapsed.as_secs() as i64);
        let results = self
            .0
            .catalogs
            .with(|catalog| engine::search(self.0.index.as_ref(), catalog, search, now))
            .map_err(internal)?;
        if results.total == 0 {
            metrics::counter!(metric::API_EMPTY_RESULTS, "type" => kind).increment(1);
        }
        Ok(Reply::xml(xml::results(
            links,
            search.offset,
            results.total,
            &results.items,
        )))
    }

    fn nzb(&self, guid: &str) -> Result<Reply, ApiError> {
        let item = self
            .0
            .catalogs
            .with(|catalog| engine::item_by_guid(catalog, guid))
            .map_err(internal)?
            .ok_or(ApiError::NoItem)?;
        let stored = self
            .0
            .nzbs
            .with(|nzbs| nzbs.get(item.id))
            .map_err(internal)?
            .ok_or(ApiError::NoItem)?;
        let body = match stored.encoding.as_str() {
            "gzip" => gunzip_capped(&stored.body, MAX_DECODED_BYTES)
                .with_context(|| format!("decoding the NZB of release {}", item.id))
                .map_err(internal)?,
            other => {
                return Err(internal(anyhow::anyhow!(
                    "release {} has an NZB in unknown encoding {other:?}",
                    item.id
                )));
            }
        };
        metrics::counter!(metric::API_GRABS).increment(1);
        Ok(Reply {
            status: StatusCode::OK,
            content_type: NZB,
            body,
            filename: Some(safe_filename(&item.title)),
        })
    }

    fn key_is_valid(&self, given: &str) -> bool {
        // Checks every key in full, so timing shows neither which key nor how much matched.
        self.0.api_keys.iter().fold(false, |found, key| {
            let key = key.expose();
            !key.is_empty() & constant_time_eq(key.as_bytes(), given.as_bytes()) | found
        })
    }

    fn base_url(&self, host: Option<&str>) -> String {
        if let Some(url) = &self.0.public_url {
            return url.clone();
        }
        let host = host
            .filter(|h| {
                !h.is_empty()
                    && h.bytes().all(|b| {
                        b.is_ascii_alphanumeric() || matches!(b, b'.' | b'-' | b':' | b'[' | b']')
                    })
            })
            .unwrap_or("localhost");
        format!("http://{host}")
    }
}

fn constant_time_eq(a: &[u8], b: &[u8]) -> bool {
    if a.len() != b.len() {
        return false;
    }
    a.iter().zip(b).fold(0u8, |diff, (x, y)| diff | (x ^ y)) == 0
}

/// A name safe inside a quoted `Content-Disposition` filename.
fn safe_filename(title: &str) -> String {
    let name: String = title
        .chars()
        .map(|c| {
            if c.is_ascii_alphanumeric()
                || matches!(c, '.' | '-' | '_' | ' ' | '(' | ')' | '[' | ']')
            {
                c
            } else {
                '_'
            }
        })
        .take(200)
        .collect();
    if name.trim().is_empty() {
        "release".to_owned()
    } else {
        name
    }
}

fn internal(error: anyhow::Error) -> ApiError {
    error!("{error:#}");
    ApiError::Internal
}

async fn handle(State(api): State<Api>, RawQuery(query): RawQuery, headers: HeaderMap) -> Reply {
    let host = headers
        .get(HOST)
        .and_then(|value| value.to_str().ok())
        .map(str::to_owned);
    let query = query.unwrap_or_default();
    // The index and SQLite block, so they run off the async workers.
    match tokio::task::spawn_blocking(move || api.respond(&query, host.as_deref())).await {
        Ok(reply) => reply,
        Err(join) => {
            error!(%join, "an API request panicked");
            Reply::error(&ApiError::Internal)
        }
    }
}

/// Serves the API until `shutdown` is cancelled.
pub async fn serve_api(
    listener: TcpListener,
    api: Api,
    shutdown: CancellationToken,
) -> io::Result<()> {
    axum::serve(listener, api.router())
        .with_graceful_shutdown(shutdown.cancelled_owned())
        .await
}

#[cfg(test)]
mod tests;
