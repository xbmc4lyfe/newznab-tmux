//! Public pages, accounts and authentication.
//!
//! Owner: ws-site. See `rust/WORKSTREAMS.md`.

use axum::Router;
use nntmux_runtime::Ctx;

pub fn router() -> Router<Ctx> {
    Router::new()
}
