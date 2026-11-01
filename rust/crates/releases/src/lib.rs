//! Collection to release assembly, and NZB creation and storage.
//!
//! Owner: ws-releases. See `rust/WORKSTREAMS.md`.

pub mod archive_retry;
pub mod crap;
pub mod delete;
pub mod import_database;
pub mod maintenance;
pub mod nzb_clean;
pub mod process;
pub mod reconcile;
pub mod remove;
pub mod runner;
pub mod settings;

use std::path::PathBuf;
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
        let busy = match nntmux_runtime::busy::enter(&ctx, "releases").await {
            Ok(Some(busy)) => busy,
            Ok(None) => continue,
            Err(failure) => {
                error!(error = format!("{failure:#}"), "can't read the pause flag");
                stop.sleep(idle).await;
                continue;
            }
        };
        let round = runner::run_round(&releases, &stop).await;
        drop(busy);
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
    /// Delete releases matching the given criteria.
    Delete(Box<delete::DeleteArgs>),
    /// Remove crap releases based on various criteria.
    RemoveCrap {
        /// Type of crap to remove. All but passwordurl and wmv_all when absent.
        #[arg(long = "type", default_value = "")]
        kind: String,
        /// Time limit in hours, or full.
        #[arg(long, default_value = "full")]
        time: String,
        /// Specific blacklist ID.
        #[arg(long)]
        blacklist_id: Option<String>,
        /// Actually delete releases.
        #[arg(long)]
        delete: bool,
    },
    /// Queue releases whose archives could not be read for one more post-processing pass.
    RetryArchiveInspection {
        /// Max releases to queue (0 = no limit).
        #[arg(long, default_value_t = 0)]
        limit: u64,
        /// Count the affected releases by archive type without queueing them.
        #[arg(long)]
        dry_run: bool,
    },
    /// Copy retained NZBs into the database, preserving originals.
    ImportDatabase {
        /// Legacy NZB roots, searched in this order.
        #[arg(long = "root")]
        roots: Vec<PathBuf>,
        /// Maximum releases per invocation.
        #[arg(long, default_value_t = 1000)]
        limit: u32,
        /// Override the stored resume cursor.
        #[arg(long)]
        after_id: Option<i64>,
        /// Database checkpoint name.
        #[arg(long, default_value = "published-nzbs")]
        name: String,
        /// Inspect without writing.
        #[arg(long)]
        dry_run: bool,
    },
    /// Report legacy NZB files with no release, and clean stale temporary ones.
    NzbClean {
        /// Report NZBs that don't exist in the database.
        #[arg(long = "notindb")]
        not_in_db: bool,
        /// Refused: a missing file can't qualify a release for deletion.
        #[arg(long = "notondisk")]
        not_on_disk: bool,
        /// Delete stale temporary NZB files left by interrupted creation.
        #[arg(long)]
        temps: bool,
        /// Minimum temporary NZB age in hours before deletion.
        #[arg(long, default_value_t = 24)]
        temp_age_hours: u64,
        /// Actually delete the files. Otherwise it's a dry run.
        #[arg(long)]
        delete: bool,
    },
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
        Command::Delete(args) => delete::run(&ctx.db, &args).await,
        Command::RemoveCrap {
            kind,
            time,
            blacklist_id,
            delete,
        } => {
            let options = crap::Options {
                delete,
                time,
                kind,
                blacklist_id,
            };
            crap::run(&ctx.db, &options).await
        }
        Command::RetryArchiveInspection { limit, dry_run } => {
            archive_retry::run(&ctx.db, limit, dry_run).await
        }
        Command::ImportDatabase {
            roots,
            limit,
            after_id,
            name,
            dry_run,
        } => {
            let options = import_database::Options {
                roots,
                limit,
                after_id,
                name,
                dry_run,
            };
            import_database::run(&ctx.db, &options).await
        }
        Command::NzbClean {
            not_in_db,
            not_on_disk,
            temps,
            temp_age_hours,
            delete,
        } => {
            let options = nzb_clean::Options {
                root: PathBuf::from(nntmux_core::var_or("PATH_TO_NZBS", "storage/nzb")),
                not_in_db,
                not_on_disk,
                temps,
                temp_age_hours,
                delete,
            };
            nzb_clean::run(&ctx.db, &options).await
        }
    }
}
