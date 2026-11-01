//! The indexer binary, which runs every pipeline stage in one process.

use std::path::PathBuf;
use std::sync::Arc;
use std::time::Duration;

use anyhow::{Context, Result, anyhow};
use clap::{Parser, Subcommand};
use indexer_config::{CONFIG_PATH_ENV, Config};
use indexer_serve::{Api, open_index, rebuild_index, serve_api};
use indexer_store::{Catalog, StorePaths};
use indexer_telemetry::{Telemetry, serve_metrics};
use tokio::net::TcpListener;
use tokio::runtime::Runtime;
use tokio::signal::unix::{SignalKind, signal};
use tokio::task::JoinSet;
use tokio::time;
use tokio_util::sync::CancellationToken;
use tracing::{Instrument, error, info, info_span, warn};

const SHUTDOWN_TIMEOUT: Duration = Duration::from_secs(10);

#[derive(Parser)]
#[command(version, about = "High-quality TV and movie Newznab indexer")]
struct Cli {
    /// TOML config file. Defaults and INDEXER_* variables apply without one.
    #[arg(long, global = true, env = CONFIG_PATH_ENV)]
    config: Option<PathBuf>,

    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand)]
enum Command {
    /// Run the indexer until SIGINT or SIGTERM.
    Run,
    /// Validate the configuration and print the effective values, with secrets hidden.
    Config,
    /// Build the search index again from the catalog, then swap it in.
    Reindex,
}

fn main() -> Result<()> {
    let cli = Cli::parse();
    let config = Config::load(cli.config.as_deref())?;
    match cli.command {
        Command::Run => run(&config),
        Command::Config => {
            print!("{}", config.to_toml()?);
            Ok(())
        }
        Command::Reindex => reindex(&config),
    }
}

fn reindex(config: &Config) -> Result<()> {
    let telemetry = Telemetry::init(&config.telemetry, env!("CARGO_PKG_VERSION"))?;
    let paths = StorePaths::under(&config.storage.data_dir);
    let result = Catalog::open_read_only(&paths.catalog)
        .and_then(|catalog| rebuild_index(&catalog, &paths.search))
        .map(drop);
    if let Err(error) = &result {
        error!("{error:#}");
    }
    telemetry.shutdown();
    result
}

/// Opens the API's store and brings the search index up to date with the catalog, when the API
/// is on. This blocks, so it runs before the async runtime starts.
fn open_api(config: &Config) -> Result<Option<Api>> {
    if config.api.listen.is_none() {
        return Ok(None);
    }
    let paths = StorePaths::under(&config.storage.data_dir);
    let catalog = Catalog::open_read_only(&paths.catalog)?;
    let index = open_index(&catalog, &paths.search)?;
    Ok(Some(Api::new(&config.api, &paths, Arc::new(index))?))
}

fn run(config: &Config) -> Result<()> {
    let telemetry = Telemetry::init(&config.telemetry, env!("CARGO_PKG_VERSION"))?;
    let result = open_api(config).and_then(|api| {
        let runtime = Runtime::new().context("starting the async runtime")?;
        let result = runtime.block_on(supervise(config, &telemetry, api));
        runtime.shutdown_timeout(SHUTDOWN_TIMEOUT);
        result
    });
    if let Err(error) = &result {
        error!("{error:#}");
    }
    telemetry.shutdown();
    result
}

/// Runs the tasks until a signal arrives or any task stops, because a stage that exits early
/// would otherwise leave the rest running with a hole in the pipeline.
async fn supervise(config: &Config, telemetry: &Telemetry, api: Option<Api>) -> Result<()> {
    let shutdown = CancellationToken::new();
    let mut tasks = JoinSet::new();

    let addr = config.telemetry.metrics.listen;
    let listener = TcpListener::bind(addr)
        .instrument(info_span!("metrics.bind", %addr))
        .await
        .with_context(|| format!("binding metrics listener on {addr}"))?;
    info!(addr = %listener.local_addr()?, "metrics listening");
    let handle = telemetry.prometheus();
    let token = shutdown.clone();
    tasks.spawn(async move {
        serve_metrics(listener, handle, token)
            .await
            .context("metrics server failed")
    });

    if let (Some(api), Some(addr)) = (api, config.api.listen) {
        let listener = TcpListener::bind(addr)
            .instrument(info_span!("api.bind", %addr))
            .await
            .with_context(|| format!("binding the API listener on {addr}"))?;
        info!(api_addr = %listener.local_addr()?, "api listening");
        let token = shutdown.clone();
        tasks.spawn(async move {
            serve_api(listener, api, token)
                .await
                .context("API server failed")
        });
    }

    let mut sigterm = signal(SignalKind::terminate()).context("installing the SIGTERM handler")?;
    let mut failure = tokio::select! {
        _ = tokio::signal::ctrl_c() => None,
        _ = sigterm.recv() => None,
        Some(stopped) = tasks.join_next() => Some(task_failure(stopped)),
    };
    info!("shutting down");
    shutdown.cancel();

    let drain = async {
        while let Some(stopped) = tasks.join_next().await {
            if let Err(error) = flatten(stopped) {
                failure.get_or_insert(error);
            }
        }
    };
    if time::timeout(SHUTDOWN_TIMEOUT, drain).await.is_err() {
        warn!("tasks did not stop in time");
    }
    failure.map_or(Ok(()), Err)
}

type TaskResult = std::result::Result<Result<()>, tokio::task::JoinError>;

fn flatten(stopped: TaskResult) -> Result<()> {
    stopped.context("task panicked")?
}

fn task_failure(stopped: TaskResult) -> anyhow::Error {
    flatten(stopped)
        .err()
        .unwrap_or_else(|| anyhow!("a task stopped before shutdown"))
}
