//! Manticore indexing and search reads.
//!
//! Owner: ws-search. See `rust/WORKSTREAMS.md`.

use anyhow::{Result, bail};
use clap::Subcommand;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};

pub async fn run_sync(_ctx: Ctx, _args: RoleArgs, _stop: Shutdown) -> Result<()> {
    bail!("the search-sync role isn't ported yet")
}

/// `nntmux-ctl search ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Reports that nothing is ported here yet. Replace this as commands arrive.
    Status,
}

pub async fn ctl(_ctx: Ctx, command: Command) -> Result<()> {
    match command {
        Command::Status => bail!("no search commands are ported yet"),
    }
}
