//! The indexer binary, which runs every pipeline stage in one process.

use std::path::PathBuf;
use std::time::Duration;

use anyhow::{Context, Result, anyhow};
use clap::{Parser, Subcommand};
use indexer_config::{CONFIG_PATH_ENV, Config};
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
    }
}

fn run(config: &Config) -> Result<()> {
    let telemetry = Telemetry::init(&config.telemetry, env!("CARGO_PKG_VERSION"))?;
    let runtime = Runtime::new().context("starting the async runtime")?;
    let result = runtime.block_on(supervise(config, &telemetry));
    runtime.shutdown_timeout(SHUTDOWN_TIMEOUT);
    if let Err(error) = &result {
        error!("{error:#}");
    }
    telemetry.shutdown();
    result
}

/// Runs the tasks until a signal arrives or any task stops, because a stage that exits early
/// would otherwise leave the rest running with a hole in the pipeline.
async fn supervise(config: &Config, telemetry: &Telemetry) -> Result<()> {
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
