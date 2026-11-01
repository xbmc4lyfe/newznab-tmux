//! Logging, Prometheus metrics and the health endpoint. Crates record through the `metrics`
//! and `tracing` macros; a binary calls [`init`] once and spawns [`serve`].
//!
//! # Log files
//!
//! With `NNTMUX_LOG_DIR` set, [`init_for_role`] also writes each role's logs to
//! `<dir>/<role>.YYYY-MM-DD.log`, rotated daily and keeping [`LOG_FILES_KEPT`] files. This is what
//! `nntmux-ctl ops index-logs` tails and the admin log viewer reads. One entry starts with a header:
//!
//! ```text
//! [2026-11-01T17:00:00.123+00:00] ingest.ERROR: message key=value
//! ```
//!
//! That's a timestamp in brackets, the role as the channel, the level in capitals, then the message
//! and its fields. Any line without a header continues the entry above it.

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
use tracing_subscriber::fmt::format::Writer;
use tracing_subscriber::fmt::{FmtContext, FormatEvent, FormatFields};
use tracing_subscriber::layer::{Layer, SubscriberExt};
use tracing_subscriber::registry::LookupSpan;
use tracing_subscriber::util::SubscriberInitExt;

const BUCKETS: [f64; 16] = [
    0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0, 300.0, 900.0,
];

/// Daily log files kept per role.
pub const LOG_FILES_KEPT: usize = 14;

/// The header format documented above.
struct LogFileFormat {
    role: String,
}

impl<S, N> FormatEvent<S, N> for LogFileFormat
where
    S: tracing::Subscriber + for<'a> LookupSpan<'a>,
    N: for<'a> FormatFields<'a> + 'static,
{
    fn format_event(
        &self,
        context: &FmtContext<'_, S, N>,
        mut writer: Writer<'_>,
        event: &tracing::Event<'_>,
    ) -> std::fmt::Result {
        let now = chrono::Utc::now().format("%Y-%m-%dT%H:%M:%S%.3f+00:00");
        write!(
            writer,
            "[{now}] {}.{}: ",
            self.role,
            event.metadata().level()
        )?;
        context.format_fields(writer.by_ref(), event)?;
        writeln!(writer)
    }
}

/// Installs the global logger and metrics recorder. Call it once per process.
pub fn init(log_filter: &str, json: bool) -> Result<PrometheusHandle> {
    init_for_role(log_filter, json, None)
}

/// [`init`], also writing log files for `role` when `NNTMUX_LOG_DIR` is set.
pub fn init_for_role(log_filter: &str, json: bool, role: Option<&str>) -> Result<PrometheusHandle> {
    let filter = EnvFilter::try_new(log_filter).context("parsing the log filter")?;
    let stdout = if json {
        tracing_subscriber::fmt::layer().json().boxed()
    } else {
        tracing_subscriber::fmt::layer().boxed()
    };
    let files = match (role, nntmux_core::optional("NNTMUX_LOG_DIR")) {
        (Some(role), Some(directory)) => {
            let appender = tracing_appender::rolling::RollingFileAppender::builder()
                .rotation(tracing_appender::rolling::Rotation::DAILY)
                .filename_prefix(role)
                .filename_suffix("log")
                .max_log_files(LOG_FILES_KEPT)
                .build(&directory)
                .with_context(|| format!("opening the log directory {directory}"))?;
            Some(
                tracing_subscriber::fmt::layer()
                    .with_ansi(false)
                    .event_format(LogFileFormat {
                        role: role.to_owned(),
                    })
                    .with_writer(std::sync::Mutex::new(appender)),
            )
        }
        _ => None,
    };
    tracing_subscriber::registry()
        .with(filter)
        .with(stdout)
        .with(files)
        .try_init()
        .context("installing the logger")?;
    PrometheusBuilder::new()
        .set_buckets(&BUCKETS)
        .context("setting histogram buckets")?
        .install_recorder()
        .context("installing the metrics recorder")
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
