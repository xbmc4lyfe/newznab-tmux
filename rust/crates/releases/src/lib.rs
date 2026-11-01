//! Collection to release assembly, and NZB creation and storage.
//!
//! Owner: ws-releases. See `rust/WORKSTREAMS.md`.

pub mod maintenance;
pub mod process;
pub mod reconcile;
pub mod remove;
pub mod runner;
pub mod settings;

use std::time::Duration;

use anyhow::{Result, bail};
use clap::Subcommand;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};
use nntmux_verify::cache::Cache;
use nntmux_verify::config::VerificationConfig;
use nntmux_verify::context::Verify;

use tracing::error;

pub use nntmux_verify::nzb;

/// The handles release processing runs with.
#[derive(Clone)]
pub struct Releases {
    pub verify: Verify,
}

impl Releases {
    pub fn new(ctx: &Ctx) -> Result<Self> {
        Ok(Self {
            verify: Verify::new(
                ctx.db.clone(),
                Cache::new(ctx.redis.clone(), "nntmux:"),
                VerificationConfig::from_env()?,
            ),
        })
    }
}

/// The `releases` role: rounds of release processing until shutdown. Operators pause it through
/// the stop flag, which every pass also checks.
pub async fn run(ctx: Ctx, args: RoleArgs, stop: Shutdown) -> Result<()> {
    let releases = Releases::new(&ctx)?;
    let idle = Duration::from_secs(args.idle_seconds);
    while !stop.requested() {
        if ctx.paused().await.unwrap_or(false) {
            stop.sleep(Duration::from_secs(5)).await;
            continue;
        }
        let round = runner::run_round(&releases, &stop).await;
        if let Err(failure) = &round {
            error!(error = format!("{failure:#}"), "release round failed");
        }
        if args.once {
            return round;
        }
        stop.sleep(idle).await;
    }
    Ok(())
}

/// `nntmux-ctl releases ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Process releases for a specific group or all groups.
    Process {
        /// Group ID to process.
        group: Option<i64>,
        /// Run release cleanup and categorization without processing collections.
        #[arg(long)]
        maintenance_only: bool,
        /// Process one bounded ready window for a group, without reconciliation or retention cleanup.
        #[arg(long)]
        ready_only: bool,
    },
    /// One round over every group: ready windows, full passes, then maintenance.
    Round,
    /// Categorize uncategorized Other > Misc releases.
    Categorize,
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    let releases = Releases::new(&ctx)?;
    match command {
        Command::Process {
            group,
            maintenance_only,
            ready_only,
        } => {
            if group.is_some_and(|id| id <= 0) {
                bail!("Group ID must be a positive integer.");
            }
            if maintenance_only && group.is_some() {
                bail!("The --maintenance-only option cannot be combined with a group ID.");
            }
            let mode = match (group, maintenance_only, ready_only) {
                (Some(group), false, true) => process::Mode::ReadyOnly(group),
                (_, _, true) => {
                    bail!(
                        "The --ready-only option requires a group ID and cannot be combined with --maintenance-only."
                    );
                }
                (None, true, false) => process::Mode::MaintenanceOnly,
                (Some(group), false, false) => process::Mode::Group(group),
                _ => process::Mode::All,
            };
            process::run(&releases, mode).await
        }
        Command::Round => runner::run_round(&releases, &Shutdown::on_signals()?).await,
        Command::Categorize => {
            let count = maintenance::categorize_releases(&releases, None).await?;
            println!("categorized {count} releases");
            Ok(())
        }
    }
}
