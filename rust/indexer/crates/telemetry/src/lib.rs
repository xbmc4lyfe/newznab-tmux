//! Logging, Prometheus metrics and OpenTelemetry traces.
//!
//! Any crate records through the `metrics` and `tracing` macros, with names from [`catalog`].
//! The binary calls [`Telemetry::init`] once and spawns [`serve_metrics`].

pub mod catalog;
mod logging;
mod prometheus;

use anyhow::Result;
use indexer_config::TelemetryConfig;
use metrics_exporter_prometheus::PrometheusHandle;
use opentelemetry_sdk::trace::SdkTracerProvider;

pub use prometheus::serve_metrics;

pub struct Telemetry {
    prometheus: PrometheusHandle,
    tracer_provider: Option<SdkTracerProvider>,
}

impl Telemetry {
    /// Installs the global subscriber and recorder. Call it outside any async runtime, because
    /// the OTLP exporter owns a blocking HTTP client, and only once per process.
    pub fn init(config: &TelemetryConfig, version: &'static str) -> Result<Self> {
        let tracer_provider = logging::init(config, version)?;
        let prometheus = prometheus::install(version)?;
        Ok(Self {
            prometheus,
            tracer_provider,
        })
    }

    pub fn prometheus(&self) -> PrometheusHandle {
        self.prometheus.clone()
    }

    /// Flushes buffered spans. Call it outside any async runtime.
    pub fn shutdown(self) {
        if let Some(provider) = self.tracer_provider
            && let Err(error) = provider.shutdown()
        {
            tracing::warn!(%error, "flushing traces failed");
        }
    }
}
