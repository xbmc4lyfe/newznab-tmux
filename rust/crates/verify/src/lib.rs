//! Release verification and remote publication.
//!
//! Owner: ws-releases. See `rust/WORKSTREAMS.md`.

pub mod article;
pub mod barrier;
pub mod cache;
pub mod candidates;
pub mod collections;
pub mod config;
pub mod context;
pub mod groups;
pub mod identity;
pub mod inventory;
pub mod ledger;
pub mod locks;
pub mod manifest;
pub mod nzb;
pub mod prepare;
pub mod reader;
pub mod releases;
pub mod reset;
pub mod spot_check;
pub mod txn;
pub mod worker;

use anyhow::{Result, bail};
use clap::Subcommand;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};

use crate::cache::Cache;
use crate::config::VerificationConfig;
use crate::context::Verify;
use crate::locks::WorkerLocks;
use crate::prepare::PrepareOptions;

fn context(ctx: &Ctx) -> Result<Verify> {
    Ok(Verify::new(
        ctx.db.clone(),
        Cache::new(ctx.redis.clone(), "nntmux:"),
        VerificationConfig::from_env()?,
    ))
}

pub async fn run(ctx: Ctx, args: RoleArgs, stop: Shutdown) -> Result<()> {
    let verify = context(&ctx)?;
    worker::run_role(ctx, args, stop, verify).await
}

/// `nntmux-ctl verify ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Spot check bounded articles before publishing complete candidate NZBs.
    Candidates {
        /// Maximum candidates in this run.
        #[arg(long, default_value_t = 1)]
        limit: i64,
        /// Worker slot. Zero selects a free slot.
        #[arg(long, default_value_t = 0)]
        slot: i64,
        /// Stop claiming after this duration, and finish the active candidate.
        #[arg(long, default_value_t = 0)]
        max_seconds: u64,
    },
    /// Separate complete header inventories from uploads waiting for articles.
    PrepareCandidates {
        /// Maximum metadata records inspected.
        #[arg(long, default_value_t = 5000)]
        limit: i64,
        /// Stop starting metadata reads after this duration.
        #[arg(long, default_value_t = 30)]
        max_seconds: u64,
        /// Maximum dirty candidates whose late headers are archived.
        #[arg(long, default_value_t = 200)]
        dirty_limit: i64,
        /// Stop starting dirty-candidate archives after this duration.
        #[arg(long, default_value_t = 20)]
        dirty_seconds: u64,
    },
    /// Copy legacy verification manifest files referenced by candidates into the database.
    ImportManifests {
        /// Candidates read per page.
        #[arg(long, default_value_t = 1000)]
        batch: i64,
        /// Stop after this many manifests (0 = all).
        #[arg(long, default_value_t = 0)]
        limit: i64,
        /// Start after this candidate ID.
        #[arg(long, default_value_t = 0)]
        since_id: i64,
        /// Remove each legacy file once the database holds a verified copy.
        #[arg(long)]
        delete_files: bool,
    },
    /// Hold verification workers on every host out until this command is stopped.
    Barrier {
        /// Seconds to wait for every host's workers to finish.
        #[arg(long, default_value_t = 900)]
        timeout: u64,
        /// Only clear deployment pauses left by a barrier that did not release.
        #[arg(long)]
        release: bool,
        /// With --release, also clear pauses left by an interrupted reset.
        #[arg(long)]
        all: bool,
    },
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    let verify = context(&ctx)?;
    match command {
        Command::Candidates {
            limit,
            slot,
            max_seconds,
        } => {
            let worker = worker::Worker::new(&ctx, verify.clone()).await?;
            let source = worker::NntpSource::new(worker.nntp.clone(), &verify);
            let options = worker::RunOptions {
                limit,
                slot,
                max_seconds,
            };
            let report = worker
                .run(&source, options, &Shutdown::on_signals()?)
                .await?;
            if !report.started {
                println!(
                    "Verification worker slots are occupied or exclusive maintenance is running."
                );
            }
            let mut counts: Vec<_> = worker::status_counts(&verify).await?.into_iter().collect();
            counts.sort();
            for (status, count) in counts {
                println!("{status}: {count}");
            }
            Ok(())
        }
        Command::PrepareCandidates {
            limit,
            max_seconds,
            dirty_limit,
            dirty_seconds,
        } => {
            let mut locks = WorkerLocks::new(&verify.db, &verify.config).await?;
            let options = PrepareOptions {
                limit: limit.max(1),
                max_seconds: max_seconds.max(1),
                dirty_limit: dirty_limit.max(1),
                dirty_seconds: dirty_seconds.max(1),
            };
            if !prepare::run_pass(&verify, &mut locks, options).await? {
                println!("Candidate preparation or exclusive maintenance is already running.");
            }
            Ok(())
        }
        Command::ImportManifests {
            batch,
            limit,
            since_id,
            delete_files,
        } => {
            let report = manifest::import_referenced(
                &verify.db,
                &verify.config,
                batch.max(1),
                limit.max(0),
                since_id.max(0),
                delete_files,
            )
            .await?;
            let counts = report.counts;
            println!(
                "imported={} already={} missing={} invalid={} conflict={} unreferenced={} last_candidate_id={}",
                counts.imported,
                counts.already,
                counts.missing,
                counts.invalid,
                counts.conflict,
                counts.unreferenced,
                report.last_candidate_id,
            );
            Ok(())
        }
        Command::Barrier {
            timeout,
            release,
            all,
        } => {
            if release {
                return barrier::release(&verify, all).await;
            }
            barrier::hold(&verify, timeout.max(1), Shutdown::on_signals()?).await
        }
    }
}
