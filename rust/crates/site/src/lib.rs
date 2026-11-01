//! Public pages, accounts and authentication.
//!
//! Owner: ws-site. See `rust/WORKSTREAMS.md`.

mod auth;
mod guest;
mod mailer;
mod statics;
pub mod validate;

use axum::Router;
use nntmux_runtime::Ctx;

pub fn router() -> Router<Ctx> {
    let pages = Router::new().merge(auth::router());
    nntmux_webkit::stack(pages).merge(statics::router())
}
