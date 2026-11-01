//! Code the web crates share: sessions, CSRF, the current user, guards, layout templates,
//! pagination, throttling and error pages.
//!
//! Owner: ws-site, with ws-api and ws-admin as callers. See `rust/WORKSTREAMS.md`.
//!
//! A web crate wraps its router with [`stack`] and adds guards per route:
//!
//! ```ignore
//! let private = Router::new()
//!     .route("/profile", get(show))
//!     .route_layer(from_fn(guard::verified))
//!     .route_layer(from_fn(guard::authenticated));
//! nntmux_webkit::stack(private.merge(public))
//! ```
//!
//! `nntmux-web` adds `Extension(ctx)` around every router, because the layers run before the
//! state is attached. Handlers take `State<Ctx>` as usual.

pub mod assets;
pub mod auth;
pub mod client_ip;
pub mod config;
pub mod csrf;
pub mod error;
pub mod guard;
pub mod headers;
pub mod input;
pub mod mail;
pub mod page;
pub mod paginate;
pub mod session;
pub mod throttle;
pub mod user;

use axum::Router;
use axum::middleware::from_fn;

pub use auth::{CurrentUser, MaybeUser};
pub use client_ip::ClientIp;
pub use error::{WebError, WebResult};
pub use input::Input;
pub use page::Layout;
pub use paginate::Pager;
pub use session::Session;
pub use user::User;

/// The session, sign-in, CSRF and header layers every browser route runs behind. API routes
/// stay outside it. The last layer added runs first.
pub fn stack<S>(router: Router<S>) -> Router<S>
where
    S: Clone + Send + Sync + 'static,
{
    router
        .layer(from_fn(csrf::layer))
        .layer(from_fn(auth::layer))
        .layer(from_fn(session::layer))
        .layer(from_fn(headers::layer))
}
