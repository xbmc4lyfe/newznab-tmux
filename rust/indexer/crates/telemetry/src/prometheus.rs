use std::io;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use anyhow::{Context, Result};
use axum::Router;
use axum::extract::State;
use axum::http::header::CONTENT_TYPE;
use axum::response::IntoResponse;
use axum::routing::get;
use metrics_exporter_prometheus::{PrometheusBuilder, PrometheusHandle, PrometheusRecorder};
use tokio::net::TcpListener;
use tokio::time;
use tokio_util::sync::CancellationToken;

use crate::catalog;

/// From the API's sub-millisecond targets up to the 0-day lane's hour-long waits.
const BUCKETS: [f64; 24] = [
    0.0001, 0.00025, 0.0005, 0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5,
    5.0, 10.0, 30.0, 60.0, 120.0, 300.0, 600.0, 900.0, 1800.0, 3600.0,
];
const UPKEEP_INTERVAL: Duration = Duration::from_secs(5);

fn build_recorder() -> Result<PrometheusRecorder> {
    Ok(PrometheusBuilder::new()
        .set_buckets(&BUCKETS)
        .context("setting histogram buckets")?
        .build_recorder())
}

pub(crate) fn install(version: &'static str) -> Result<PrometheusHandle> {
    let recorder = build_recorder()?;
    let handle = recorder.handle();
    metrics::set_global_recorder(recorder).context("installing the metrics recorder")?;
    catalog::describe_all();
    catalog::record_startup(version, unix_seconds());
    Ok(handle)
}

fn unix_seconds() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_or(0.0, |elapsed| elapsed.as_secs_f64())
}

/// Serves `/metrics` and `/healthz` until `shutdown` is cancelled.
pub async fn serve_metrics(
    listener: TcpListener,
    handle: PrometheusHandle,
    shutdown: CancellationToken,
) -> io::Result<()> {
    // Without upkeep, histogram samples pile up in memory between scrapes.
    let upkeep_handle = handle.clone();
    let upkeep = tokio::spawn(async move {
        let mut interval = time::interval(UPKEEP_INTERVAL);
        loop {
            interval.tick().await;
            upkeep_handle.run_upkeep();
        }
    });
    let app = Router::new()
        .route("/metrics", get(render))
        .route("/healthz", get(|| async { "ok\n" }))
        .with_state(handle);
    let served = axum::serve(listener, app)
        .with_graceful_shutdown(shutdown.cancelled_owned())
        .await;
    upkeep.abort();
    served
}

async fn render(State(handle): State<PrometheusHandle>) -> impl IntoResponse {
    (
        [(CONTENT_TYPE, "text/plain; version=0.0.4; charset=utf-8")],
        handle.render(),
    )
}

#[cfg(test)]
mod tests {
    use indexer_core::Stage;

    use super::*;

    #[test]
    fn records_catalog_metrics_as_prometheus_text() {
        let recorder = build_recorder().unwrap();
        let handle = recorder.handle();
        metrics::with_local_recorder(&recorder, || {
            catalog::describe_all();
            catalog::record_startup("1.2.3", 1_700_000_000.0);
            catalog::funnel_passed(Stage::Assemble, 3);
            catalog::funnel_dropped(Stage::Assemble, "too_small", 2);
            metrics::histogram!(catalog::API_REQUEST_DURATION, "type" => "tvsearch").record(0.002);
        });
        let text = handle.render();

        for expected in [
            r#"indexer_build_info{version="1.2.3"} 1"#,
            "indexer_start_time_seconds 1700000000",
            "indexer_new_high_quality_releases_total 0",
            r#"indexer_funnel_posts_total{stage="assemble",outcome="passed",reason=""} 3"#,
            r#"indexer_funnel_posts_total{stage="assemble",outcome="dropped",reason="too_small"} 2"#,
            r#"indexer_api_request_duration_seconds_bucket{type="tvsearch",le="0.0025"} 1"#,
            r#"indexer_api_request_duration_seconds_bucket{type="tvsearch",le="0.001"} 0"#,
            "# HELP indexer_funnel_posts_total",
            "# TYPE indexer_api_request_duration_seconds histogram",
        ] {
            assert!(text.contains(expected), "missing {expected} in:\n{text}");
        }
    }
}
