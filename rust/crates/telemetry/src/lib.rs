//! Logging, Prometheus metrics and the health endpoint. Crates record through the `metrics`
//! and `tracing` macros; a binary calls [`init`] once and spawns [`serve`].

use std::net::SocketAddr;
use std::time::Duration;

use anyhow::{Context, Result};
use axum::Router;
use axum::http::header::CONTENT_TYPE;
use axum::response::IntoResponse;
use axum::routing::get;
use metrics_exporter_prometheus::{PrometheusBuilder, PrometheusHandle};
use tokio::net::TcpListener;
use tokio_util::sync::CancellationToken;
use tracing_subscriber::EnvFilter;

pub mod http;

const BUCKETS: [f64; 16] = [
    0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0, 300.0, 900.0,
];

/// Installs the global logger and metrics recorder. Call it once per process.
pub fn init(log_filter: &str, json: bool) -> Result<PrometheusHandle> {
    let filter = EnvFilter::try_new(log_filter).context("parsing the log filter")?;
    let subscriber = tracing_subscriber::fmt().with_env_filter(filter);
    if json {
        subscriber.json().init();
    } else {
        subscriber.init();
    }
    PrometheusBuilder::new()
        .set_buckets(&BUCKETS)
        .context("setting histogram buckets")?
        .install_recorder()
        .context("installing the metrics recorder")
        .inspect(|_| {
            // Lets dashboards tell builds apart and spot a service that stopped reporting.
            metrics::gauge!("nntmux_build_info", "version" => env!("CARGO_PKG_VERSION")).set(1.0);
        })
}

/// Serves `/metrics` and `/healthz` until `shutdown` is cancelled.
pub async fn serve(
    addr: SocketAddr,
    handle: PrometheusHandle,
    shutdown: CancellationToken,
) -> Result<()> {
    let listener = TcpListener::bind(addr)
        .await
        .with_context(|| format!("binding the health port {addr}"))?;
    // Without upkeep, histogram samples pile up in memory between scrapes.
    let upkeep_handle = handle.clone();
    let upkeep = tokio::spawn(async move {
        let mut interval = tokio::time::interval(Duration::from_secs(5));
        loop {
            interval.tick().await;
            upkeep_handle.run_upkeep();
        }
    });
    let app = Router::new()
        .route(
            "/metrics",
            get(move || {
                let handle = handle.clone();
                async move {
                    (
                        [(CONTENT_TYPE, "text/plain; version=0.0.4")],
                        handle.render(),
                    )
                        .into_response()
                }
            }),
        )
        .route("/healthz", get(|| async { "ok\n" }));
    let served = axum::serve(listener, app)
        .with_graceful_shutdown(shutdown.cancelled_owned())
        .await;
    upkeep.abort();
    served.context("serving metrics")
}
