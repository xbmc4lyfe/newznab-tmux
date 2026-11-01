//! Release fingerprint collection.
//!
//! Owner: ws-releases. See `rust/WORKSTREAMS.md`.

pub mod backfill;
pub mod breaker;
pub mod collect;
pub mod database;
pub mod manifest;
pub mod role;

use anyhow::Result;
use clap::Subcommand;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};

pub async fn run(ctx: Ctx, args: RoleArgs, stop: Shutdown) -> Result<()> {
    role::run(ctx, args, stop).await
}

/// `nntmux-ctl fingerprint ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Queue resumable collection of public release format and NNTP metadata.
    Backfill {
        /// Maximum number of releases to queue.
        #[arg(long, default_value_t = 100)]
        limit: i64,
        /// Resume after this release ID.
        #[arg(long, default_value_t = 0)]
        after_id: i64,
        /// Delay between queue submissions in milliseconds.
        #[arg(long, default_value_t = 0)]
        delay_ms: u64,
        /// Show matching releases without queueing jobs.
        #[arg(long)]
        dry_run: bool,
        /// Only re-queue records left stale (an upgrade replaced their NZB) or stuck processing.
        #[arg(long)]
        stale: bool,
    },
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    match command {
        Command::Backfill {
            limit,
            after_id,
            delay_ms,
            dry_run,
            stale,
        } => {
            let options = backfill::Options {
                limit,
                after_id,
                delay_ms,
                dry_run,
                stale,
            };
            backfill::run(&ctx.db, &options).await
        }
    }
}
