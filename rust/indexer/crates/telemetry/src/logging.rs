use std::env;

use anyhow::{Context, Result};
use indexer_config::{LogFormat, OtlpConfig, TelemetryConfig};
use opentelemetry::KeyValue;
use opentelemetry::trace::TracerProvider;
use opentelemetry_otlp::{SpanExporter, WithExportConfig};
use opentelemetry_sdk::Resource;
use opentelemetry_sdk::trace::{Sampler, SdkTracerProvider};
use tracing_subscriber::filter::{EnvFilter, LevelFilter, Targets};
use tracing_subscriber::layer::{Layer, SubscriberExt};
use tracing_subscriber::util::SubscriberInitExt;

const SERVICE_NAME: &str = "indexer";
const TRACES_PATH: &str = "/v1/traces";

/// The exporter's own HTTP stack and logs, which would otherwise feed back into every export.
const EXPORTER_TARGETS: [&str; 5] = ["h2", "hyper", "opentelemetry", "reqwest", "tower"];

pub(crate) fn init(
    config: &TelemetryConfig,
    version: &'static str,
) -> Result<Option<SdkTracerProvider>> {
    let provider = config
        .otlp
        .endpoint
        .as_deref()
        .map(|endpoint| tracer_provider(endpoint, &config.otlp, version))
        .transpose()?;

    let log_filter = match env::var("RUST_LOG") {
        Ok(directives) => EnvFilter::try_new(&directives).context("invalid RUST_LOG")?,
        Err(_) => EnvFilter::try_new(&config.log_filter).context("invalid telemetry.log_filter")?,
    };
    let logs = match config.log_format {
        LogFormat::Text => tracing_subscriber::fmt::layer()
            .with_filter(log_filter)
            .boxed(),
        LogFormat::Json => tracing_subscriber::fmt::layer()
            .json()
            .with_filter(log_filter)
            .boxed(),
    };
    let traces = provider.as_ref().map(|provider| {
        let targets = EXPORTER_TARGETS.map(|target| (target, LevelFilter::OFF));
        tracing_opentelemetry::layer()
            .with_tracer(provider.tracer(SERVICE_NAME))
            .with_filter(
                Targets::new()
                    .with_default(LevelFilter::INFO)
                    .with_targets(targets),
            )
    });

    tracing_subscriber::registry()
        .with(logs)
        .with(traces)
        .try_init()
        .context("installing the tracing subscriber")?;
    Ok(provider)
}

fn tracer_provider(
    endpoint: &str,
    config: &OtlpConfig,
    version: &'static str,
) -> Result<SdkTracerProvider> {
    let exporter = SpanExporter::builder()
        .with_http()
        .with_endpoint(traces_url(endpoint))
        .build()
        .context("building the OTLP exporter")?;
    let resource = Resource::builder()
        .with_service_name(SERVICE_NAME)
        .with_attribute(KeyValue::new("service.version", version))
        .build();
    let sampler = Sampler::ParentBased(Box::new(Sampler::TraceIdRatioBased(config.sample_ratio)));
    Ok(SdkTracerProvider::builder()
        .with_batch_exporter(exporter)
        .with_resource(resource)
        .with_sampler(sampler)
        .build())
}

/// The exporter uses a configured URL verbatim, so a bare collector address would 404.
fn traces_url(endpoint: &str) -> String {
    let endpoint = endpoint.trim_end_matches('/');
    if endpoint.ends_with(TRACES_PATH) {
        endpoint.to_owned()
    } else {
        format!("{endpoint}{TRACES_PATH}")
    }
}

#[cfg(test)]
mod tests {
    use super::traces_url;

    #[test]
    fn traces_url_accepts_base_or_full_url() {
        let full = "http://tempo:4318/v1/traces";
        assert_eq!(traces_url("http://tempo:4318"), full);
        assert_eq!(traces_url("http://tempo:4318/"), full);
        assert_eq!(traces_url(full), full);
    }
}
