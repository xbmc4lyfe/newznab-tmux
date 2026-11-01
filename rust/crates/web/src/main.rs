//! `nntmux-web`: every HTTP route. Each crate adds its routes through `router()`.

use std::net::SocketAddr;

use anyhow::{Context, Result};
use axum::extract::Request;
use axum::middleware::{from_fn, map_request};
use axum::{Extension, Router, ServiceExt};
use nntmux_core::{Config, var_or};
use nntmux_runtime::{Ctx, Shutdown};
use tokio::net::TcpListener;
use tower::Layer;
use tower_http::trace::TraceLayer;
use tracing::info;

#[tokio::main]
async fn main() -> Result<()> {
    nntmux_webkit::config::require_app_key()?;
    let config = Config::from_env()?;
    let metrics = nntmux_telemetry::init(&config.log_filter, config.log_json)?;
    let stop = Shutdown::on_signals()?;
    let health = tokio::spawn(nntmux_telemetry::serve(
        config.health_addr,
        metrics,
        stop.token(),
    ));
    let ctx = Ctx::connect(config).await?;

    let app = Router::new()
        .merge(nntmux_api::router())
        .merge(nntmux_releases::router())
        .merge(
            Router::new()
                .merge(nntmux_site::router())
                .merge(nntmux_admin::router())
                .layer(from_fn(nntmux_api::block_pages)),
        )
        // After every route, so each request is labelled with the pattern it matched.
        .route_layer(from_fn(nntmux_telemetry::http::track))
        .layer(TraceLayer::new_for_http())
        // The web stack's layers run before the state exists, so they read it from here.
        .layer(Extension(ctx.clone()))
        .with_state(ctx);

    let addr: SocketAddr = var_or("NNTMUX_WEB_ADDR", "0.0.0.0:8080")
        .parse()
        .context("NNTMUX_WEB_ADDR")?;
    let listener = TcpListener::bind(addr)
        .await
        .with_context(|| format!("binding {addr}"))?;
    info!(%addr, "serving");
    // Outside the router, because routing already depends on the method.
    let app = map_request(nntmux_webkit::csrf::override_method).layer(app);
    axum::serve(
        listener,
        ServiceExt::<Request>::into_make_service_with_connect_info::<SocketAddr>(app),
    )
    .with_graceful_shutdown(stop.token().cancelled_owned())
    .await
    .context("serving")?;
    health.await?
}
