//! Runs identification over a SQLite import of the catalog. Without `--apply` nothing is written
//! to any database. It takes no MariaDB settings, because it never connects to one.

use std::path::{Path, PathBuf};
use std::process::ExitCode;
use std::time::Duration;

use anyhow::{Context, Result};
use clap::{Parser, Subcommand};
use nntmux_namefix::identify::archive_probe::{ArchiveProbe, NntpFetch};
use nntmux_namefix::identify::catalog::{
    Options, holdout, render_holdout, render_report, render_tsv, run_with_probe,
};
use nntmux_namefix::identify::{Hooks, SrrdbClient, SrrdbConfig};

#[derive(Parser)]
#[command(about = "Identify releases from the evidence in a SQLite import")]
struct Cli {
    /// The directory holding catalog.db and nzbs.db.
    #[arg(long, global = true)]
    data_dir: Option<PathBuf>,
    /// Where the report, the matches and the metrics snapshot go.
    #[arg(long, global = true, default_value = "identify-output")]
    out: PathBuf,
    #[arg(long, global = true, default_value_t = 8)]
    threads: usize,
    /// Stops after this many releases, for trying it out.
    #[arg(long, global = true)]
    limit: Option<usize>,
    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand)]
enum Command {
    /// Climbs the ladder on every unidentified release.
    Run {
        /// Writes results to release_identifications in catalog.db. Without it, a dry run.
        #[arg(long)]
        apply: bool,
        /// Also asks srrdb.com, caching answers here. This sends CRC32s and names to srrDB, and
        /// nothing leaves this machine without it.
        #[arg(long)]
        srrdb_cache: Option<PathBuf>,
        /// Stops asking srrDB after this many releases, chosen at random.
        #[arg(long)]
        srrdb_sample: Option<usize>,
        /// Reads archive headers over NNTP for this many releases with no other evidence, chosen
        /// at random. It takes the provider from the NNTP_* variables, and needs nothing else.
        #[arg(long)]
        archive_probe_sample: Option<usize>,
        /// The least time between srrDB requests.
        #[arg(long, default_value_t = 1000)]
        srrdb_interval_ms: u64,
    },
    /// Measures the weak rungs on releases whose PreDB row is already known.
    Holdout,
}

fn write_out(dir: &Path, file: &str, content: &str) -> Result<()> {
    std::fs::create_dir_all(dir).with_context(|| format!("creating {}", dir.display()))?;
    let path = dir.join(file);
    std::fs::write(&path, content).with_context(|| format!("writing {}", path.display()))
}

fn start(cli: &Cli) -> Result<()> {
    let metrics = nntmux_telemetry::init("info", false)?;
    let mut options = Options {
        data_dir: cli.data_dir.clone().context("--data-dir is required")?,
        threads: cli.threads,
        limit: cli.limit,
        apply: false,
        shuffle: false,
    };
    match cli.command {
        Command::Run {
            apply,
            ref srrdb_cache,
            srrdb_sample,
            archive_probe_sample,
            srrdb_interval_ms,
        } => {
            options.apply = apply;
            let srrdb = srrdb_cache
                .as_ref()
                .map(|cache| {
                    let mut config = SrrdbConfig::new(cache);
                    config.budget = srrdb_sample;
                    config.min_interval = Duration::from_millis(srrdb_interval_ms);
                    SrrdbClient::new(config)
                })
                .transpose()?;
            let fetcher = archive_probe_sample
                .map(|_| NntpFetch::from_env())
                .transpose()?;
            let probe = fetcher.as_ref().map(|fetcher| {
                ArchiveProbe::new(fetcher, archive_probe_sample.unwrap_or_default())
            });
            options.shuffle = srrdb.is_some() || probe.is_some();
            let hooks = srrdb.as_ref().map_or_else(Hooks::default, |client| Hooks {
                srrdb: client,
                ..Hooks::default()
            });
            let report = run_with_probe(&options, hooks, probe.as_ref())?;
            let text = render_report(&report);
            write_out(&cli.out, "report.md", &text)?;
            write_out(&cli.out, "identifications.tsv", &render_tsv(&report))?;
            print!("{text}");
            if let Some(probe) = &probe {
                let stats = probe.render_stats();
                write_out(&cli.out, "archive_probe.md", &stats)?;
                print!("\n{stats}");
            }
            if let Some(client) = &srrdb {
                let stats = client.render_stats();
                write_out(&cli.out, "srrdb.md", &stats)?;
                print!("\n{stats}");
            }
        }
        Command::Holdout => {
            let text = render_holdout(&holdout(&options)?);
            write_out(&cli.out, "holdout.md", &text)?;
            print!("{text}");
        }
    }
    write_out(&cli.out, "metrics.prom", &metrics.render())
}

fn main() -> ExitCode {
    match start(&Cli::parse()) {
        Ok(()) => ExitCode::SUCCESS,
        Err(error) => {
            eprintln!("error: {error:#}");
            ExitCode::FAILURE
        }
    }
}
