//! `nntmux-web`: every HTTP route. Each crate adds its routes through `router()`.

use std::net::SocketAddr;

use anyhow::{Context, Result};
use axum::Router;
use nntmux_core::{Config, var_or};
use nntmux_runtime::{Ctx, Shutdown};
use tokio::net::TcpListener;
use tower_http::trace::TraceLayer;
use tracing::info;

#[tokio::main]
async fn main() -> Result<()> {
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
        .merge(nntmux_site::router())
        .merge(nntmux_admin::router())
        .layer(TraceLayer::new_for_http())
        .with_state(ctx);

    let addr: SocketAddr = var_or("NNTMUX_WEB_ADDR", "0.0.0.0:8080")
        .parse()
        .context("NNTMUX_WEB_ADDR")?;
    let listener = TcpListener::bind(addr)
        .await
        .with_context(|| format!("binding {addr}"))?;
    info!(%addr, "serving");
    axum::serve(listener, app)
        .with_graceful_shutdown(stop.token().cancelled_owned())
        .await
        .context("serving")?;
    health.await?
}
