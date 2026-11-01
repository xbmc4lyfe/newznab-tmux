//! Newznab API, RSS and NZB download: `/api`, `/rss` and `/getnzb`.
//!
//! Owner: ws-api. See `rust/WORKSTREAMS.md`.

use axum::Router;
use nntmux_runtime::Ctx;

pub fn router() -> Router<Ctx> {
    Router::new()
}
