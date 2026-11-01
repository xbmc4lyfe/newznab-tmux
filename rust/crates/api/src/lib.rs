//! Newznab API, RSS and NZB download: `/api`, `/rss` and `/getnzb`.
//!
//! Owner: ws-api. See `rust/WORKSTREAMS.md`.

mod abuse;
mod auth;
mod cache;
mod caps;
mod category;
mod db;
mod filelist;
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
mod release_cache;
mod reply;
mod request;
mod rows;
mod rss;
mod search;
mod settings;
mod suggest;
mod upload;
pub mod usage;
mod v1;
mod v2;
mod web;
mod xml;

use std::sync::Arc;

use axum::routing::{MethodFilter, any, on};
use axum::{Extension, Router};
use nntmux_runtime::Ctx;

pub use crate::abuse::AbuseConfig;
use crate::pipeline::{Endpoint, dispatch};
use crate::rss::Feed;
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
        .route("/rss/mymovies", route(Endpoint::Rss(Feed::MyMovies)))
        .route("/rss/myshows", route(Endpoint::Rss(Feed::MyShows)))
        .route("/rss/full-feed", route(Endpoint::Rss(Feed::Full)))
        .route("/rss/cart", route(Endpoint::Rss(Feed::Cart)))
        .route("/rss/category", route(Endpoint::Rss(Feed::Category)))
        .route(
            "/rss/trending-movies",
            route(Endpoint::Rss(Feed::TrendingMovies)),
        )
        .route(
            "/rss/trending-shows",
            route(Endpoint::Rss(Feed::TrendingShows)),
        )
        .merge(web::router(on(
            MethodFilter::GET
                .or(MethodFilter::POST)
                .or(MethodFilter::OPTIONS),
            dispatch(Endpoint::GetNzb),
        )))
        .layer(Extension(Arc::new(settings)))
}
