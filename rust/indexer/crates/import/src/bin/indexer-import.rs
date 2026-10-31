//! Runs the import, or checks one, from the command line.

use std::path::PathBuf;
use std::process::ExitCode;

use anyhow::{Context, Result};
use clap::{Args, Parser, Subcommand};
use indexer_config::{CONFIG_PATH_ENV, Config};
use indexer_import::{Credentials, ImportOptions, Source, SourceConfig, Tls, run, verify};
use indexer_telemetry::{Telemetry, serve_metrics};
use tokio::net::TcpListener;
use tokio::runtime::Runtime;
use tokio_util::sync::CancellationToken;

const MB: u64 = 1_000_000;

#[derive(Parser)]
#[command(about = "Read-only import of the old MariaDB database into the SQLite catalog")]
struct Cli {
    /// TOML config file, for logging and the metrics listener.
    #[arg(long, global = true, env = CONFIG_PATH_ENV)]
    config: Option<PathBuf>,
    #[command(flatten)]
    source: SourceArgs,
    #[command(subcommand)]
    command: Command,
}

#[derive(Args)]
struct SourceArgs {
    #[arg(long, global = true, default_value = "192.168.1.110")]
    host: String,
    #[arg(long, global = true, default_value_t = 3306)]
    port: u16,
    #[arg(long, global = true, default_value = "nntmux")]
    database: String,
    /// A file holding MARIADB_RO_USER and MARIADB_RO_PASSWORD.
    #[arg(long, global = true, env = "INDEXER_IMPORT_CREDENTIALS")]
    credentials: Option<PathBuf>,
    /// The CA that issued the server's certificate.
    #[arg(long, global = true, env = "INDEXER_IMPORT_CA")]
    ca: Option<PathBuf>,
    /// The name the server's certificate must carry, when it isn't --host.
    #[arg(long, global = true)]
    tls_name: Option<String>,
    /// For loopback test servers only.
    #[arg(long, global = true)]
    no_tls: bool,
    #[arg(long, global = true, default_value = "8")]
    concurrency: usize,
    #[arg(long, global = true, default_value = "500")]
    batch_rows: usize,
    #[arg(long, global = true, default_value = "256")]
    batch_decoded_mb: u64,
}

#[derive(Subcommand)]
enum Command {
    /// Copies everything, resuming if a copy was already started.
    Run {
        #[arg(long)]
        data_dir: PathBuf,
        /// Releases smaller than this are left behind.
        #[arg(long, default_value = "500")]
        min_size_mb: u64,
        /// Stops after this many more releases.
        #[arg(long)]
        limit: Option<u64>,
    },
    /// Compares a finished copy with the source, row by row and NZB by NZB.
    Verify {
        #[arg(long)]
        data_dir: PathBuf,
    },
}

fn source(args: &SourceArgs) -> Result<Source> {
    let Some(credentials) = &args.credentials else {
        anyhow::bail!("--credentials is required");
    };
    let tls = match (&args.ca, args.no_tls) {
        (_, true) => Tls::Disabled,
        (Some(ca), false) => Tls::VerifyCa {
            ca: ca.clone(),
            name: args.tls_name.clone(),
        },
        (None, false) => anyhow::bail!("--ca is required"),
    };
    Source::connect(&SourceConfig {
        host: args.host.clone(),
        port: args.port,
        database: args.database.clone(),
        credentials: Credentials::from_file(credentials)?,
        tls,
        // Reads run one statement at a time per batch; the planner takes one more.
        max_connections: args.concurrency.max(1) + 1,
    })
}

async fn execute(cli: Cli) -> Result<bool> {
    let args = &cli.source;
    let source = source(args)?;
    let clean = match cli.command {
        Command::Run {
            data_dir,
            min_size_mb,
            limit,
        } => {
            let report = run(
                &source,
                &ImportOptions {
                    data_dir,
                    min_size: min_size_mb * MB,
                    batch_rows: args.batch_rows,
                    batch_decoded_bytes: args.batch_decoded_mb * MB,
                    concurrency: args.concurrency,
                    limit,
                },
            )
            .await?;
            let seconds = report.elapsed.as_secs_f64();
            for (table, rows) in &report.rows {
                println!("{table}: {rows} rows");
            }
            let total: u64 = report.rows.values().sum();
            println!(
                "nzbs: {} ({:.2} GB)",
                report.nzbs,
                report.nzb_bytes as f64 / 1e9
            );
            println!(
                "speed: {:.0} rows/s over {seconds:.0} s",
                total as f64 / seconds
            );
            for (kind, count) in &report.anomalies {
                println!("anomaly {kind}: {count}");
            }
            println!("complete: {}", report.complete);
            true
        }
        Command::Verify { data_dir } => {
            let report = verify(
                &source,
                &data_dir,
                args.batch_rows,
                args.batch_decoded_mb * MB,
                args.concurrency,
            )
            .await?;
            for (table, expected, actual) in &report.counts {
                println!("{table}: source {expected}, catalog {actual}");
            }
            println!(
                "compared {} rows and {} NZBs ({:.2} GB) in {:.0} s",
                report.rows_compared,
                report.nzbs_compared,
                report.nzb_bytes as f64 / 1e9,
                report.elapsed.as_secs_f64()
            );
            for (kind, count) in &report.flagged_nzbs {
                println!("flagged at import, bytes still exact, {kind}: {count}");
            }
            for (kind, count) in &report.anomalies {
                println!("flagged at import, kept anyway, {kind}: {count}");
            }
            for problem in &report.problems {
                println!("PROBLEM {problem}");
            }
            println!("problems: {}", report.problem_count);
            println!("complete import: {}", report.complete);
            println!(
                "NOT copied: {} releases below {} MB ({:.2} TB)",
                report.below_floor.0,
                report.min_size / MB,
                report.below_floor.1 as f64 / 1e12
            );
            for (table, rows) in &report.orphans {
                println!("NOT copied: {rows} rows of {table} that belong to no release");
            }
            for left in &report.left_behind {
                println!("NOT copied, {}: {}", left.reason, left.what.join(", "));
            }
            if report.clean() {
                println!(
                    "VERIFIED: everything copied matches. Everything marked NOT copied is lost \
                     if database {} is dropped.",
                    report.database
                );
            } else {
                println!("NOT VERIFIED");
            }
            report.clean()
        }
    };
    source.disconnect().await?;
    Ok(clean)
}

async fn execute_with_metrics(cli: Cli, config: &Config, telemetry: &Telemetry) -> Result<bool> {
    let addr = config.telemetry.metrics.listen;
    let listener = TcpListener::bind(addr)
        .await
        .with_context(|| format!("binding metrics listener on {addr}"))?;
    tracing::info!(addr = %listener.local_addr()?, "metrics listening");
    let shutdown = CancellationToken::new();
    let metrics = tokio::spawn(serve_metrics(
        listener,
        telemetry.prometheus(),
        shutdown.clone(),
    ));
    let result = execute(cli).await;
    shutdown.cancel();
    metrics
        .await
        .context("metrics server panicked")?
        .context("metrics server failed")?;
    result
}

fn start(cli: Cli) -> Result<bool> {
    let config = Config::load(cli.config.as_deref())?;
    let telemetry = Telemetry::init(&config.telemetry, env!("CARGO_PKG_VERSION"))?;
    let runtime = Runtime::new().context("starting the async runtime")?;
    let result = runtime.block_on(execute_with_metrics(cli, &config, &telemetry));
    drop(runtime);
    telemetry.shutdown();
    result
}

fn main() -> ExitCode {
    match start(Cli::parse()) {
        Ok(true) => ExitCode::SUCCESS,
        Ok(false) => ExitCode::FAILURE,
        Err(error) => {
            eprintln!("error: {error:#}");
            ExitCode::FAILURE
        }
    }
}
