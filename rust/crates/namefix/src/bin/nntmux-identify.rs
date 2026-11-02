//! Runs identification over a SQLite import of the catalog. Without `--apply` nothing is written
//! to any database. It takes no MariaDB settings, because it never connects to one.

use std::path::{Path, PathBuf};
use std::process::ExitCode;

use anyhow::{Context, Result};
use clap::{Parser, Subcommand};
use nntmux_namefix::identify::Hooks;
use nntmux_namefix::identify::catalog::{
    Options, holdout, render_holdout, render_report, render_tsv, run,
};

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
    };
    match cli.command {
        Command::Run { apply } => {
            options.apply = apply;
            let report = run(&options, Hooks::default())?;
            let text = render_report(&report);
            write_out(&cli.out, "report.md", &text)?;
            write_out(&cli.out, "identifications.tsv", &render_tsv(&report))?;
            print!("{text}");
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
