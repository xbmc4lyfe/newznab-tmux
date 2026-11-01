//! Public pages, accounts and authentication.
//!
//! Owner: ws-site. See `rust/WORKSTREAMS.md`.

mod auth;
mod browse;
mod details;
mod guest;
mod invitations;
mod mailer;
mod pages;
mod profile;
mod releases;
mod statics;
pub mod validate;

use axum::Router;
use nntmux_runtime::Ctx;

pub fn router() -> Router<Ctx> {
    let pages = Router::new()
        .merge(auth::router())
        .merge(pages::router())
        .merge(invitations::router())
        .merge(profile::router())
        .merge(browse::router())
        .merge(details::router())
        .merge(details::failed_router());
    nntmux_webkit::stack(pages)
        .merge(statics::router())
        .merge(pages::cover_router())
}
