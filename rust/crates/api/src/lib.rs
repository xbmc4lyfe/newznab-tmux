//! Newznab API, RSS and NZB download: `/api`, `/rss` and `/getnzb`.
//!
//! Owner: ws-api. See `rust/WORKSTREAMS.md`.

// Helpers the release, RSS and download endpoints use arrive in the next PRs of this workstream.
#![allow(dead_code)]

mod auth;
mod cache;
mod caps;
mod db;
mod filename;
mod getnzb;
mod input;
mod json;
mod misc;
mod nzb;
mod params;
mod pipeline;
mod present_v1;
mod present_v2;
mod reply;
mod request;
mod rows;
mod settings;
pub mod usage;
mod v1;
mod v2;
mod xml;

use std::sync::Arc;

use axum::routing::any;
use axum::{Extension, Router};
use nntmux_runtime::Ctx;

use crate::pipeline::{Endpoint, dispatch};
pub use crate::settings::Settings;

pub fn router() -> Router<Ctx> {
    // A malformed limit fails the same way on every request, so it can't start half-configured.
    let settings =
        Settings::from_env().unwrap_or_else(|failure| panic!("API settings: {failure:#}"));
    router_with(settings)
}

pub fn router_with(settings: Settings) -> Router<Ctx> {
    let route = |endpoint: Endpoint| any(dispatch(endpoint));
    Router::new()
        .route("/api/v1/api", route(Endpoint::V1))
        .route("/api/v2/capabilities", route(Endpoint::V2Capabilities))
        .route("/api/v2/nzbadd", route(Endpoint::V2NzbAdd))
        .route("/api/v2/movies", route(Endpoint::V2Movies))
        .route("/api/v2/audio", route(Endpoint::V2Audio))
        .route("/api/v2/books", route(Endpoint::V2Books))
        .route("/api/v2/anime", route(Endpoint::V2Anime))
        .route("/api/v2/search", route(Endpoint::V2Search))
        .route("/api/v2/tv", route(Endpoint::V2Tv))
        .route("/api/v2/details", route(Endpoint::V2Details))
        .route("/api/v2/getnzb", route(Endpoint::V2GetNzb))
        .route("/api/inform/release", route(Endpoint::Inform))
        .route("/api/release/{id}/mediainfo", route(Endpoint::MediaInfo))
        .route("/api/{*rest}", route(Endpoint::Fallback))
        .route("/rss/health", route(Endpoint::RssHealth))
        .route("/getnzb", route(Endpoint::GetNzb))
        .route("/getnzb/{guid}", route(Endpoint::GetNzb))
        .layer(Extension(Arc::new(settings)))
}
