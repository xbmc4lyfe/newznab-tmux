//! Group updates and backfill: headers, collections, binaries and parts.
//!
//! Owner: ws-ingest. See `rust/WORKSTREAMS.md`.

pub mod blacklist;
pub mod clean;
pub mod config;
pub mod groups;
pub mod header;
pub mod missed;
pub mod store;

use anyhow::{Result, bail};
use clap::Subcommand;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};

pub async fn run_update(_ctx: Ctx, _args: RoleArgs, _stop: Shutdown) -> Result<()> {
    bail!("the ingest role isn't ported yet")
}

pub async fn run_backfill(_ctx: Ctx, _args: RoleArgs, _stop: Shutdown) -> Result<()> {
    bail!("the backfill role isn't ported yet")
}

/// `nntmux-ctl ingest ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Reports that nothing is ported here yet. Replace this as commands arrive.
    Status,
}

pub async fn ctl(_ctx: Ctx, command: Command) -> Result<()> {
    match command {
        Command::Status => bail!("no ingest commands are ported yet"),
    }
}
