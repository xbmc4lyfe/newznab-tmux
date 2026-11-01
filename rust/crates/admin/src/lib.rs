//! The admin area.
//!
//! Owner: ws-admin. See `rust/WORKSTREAMS.md`.

use axum::Router;
use nntmux_runtime::Ctx;

pub fn router() -> Router<Ctx> {
    Router::new()
}
