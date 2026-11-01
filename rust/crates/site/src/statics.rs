//! Compiled assets and uploaded files, served outside the session stack so they never touch
//! Redis, plus the `/up` health route.

use axum::Router;
use axum::routing::get;
use nntmux_runtime::Ctx;
use nntmux_webkit::assets::public_dir;
use tower_http::services::{ServeDir, ServeFile};

/// Where uploads live, Laravel's `storage/app/public`.
fn storage_dir() -> std::path::PathBuf {
    std::path::PathBuf::from(nntmux_core::var_or("NNTMUX_STORAGE_DIR", "storage"))
        .join("app/public")
}

pub fn router() -> Router<Ctx> {
    let public = public_dir();
    Router::new()
        .route("/up", get(|| async { "OK" }))
        .nest_service("/build", ServeDir::new(public.join("build")))
        .nest_service("/assets", ServeDir::new(public.join("assets")))
        .nest_service("/svg", ServeDir::new(public.join("svg")))
        .nest_service("/storage", ServeDir::new(storage_dir()))
        .route_service("/favicon.ico", ServeFile::new(public.join("favicon.ico")))
        .route_service("/robots.txt", ServeFile::new(public.join("robots.txt")))
}
