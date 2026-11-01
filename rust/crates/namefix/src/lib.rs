//! Release name fixing.
//!
//! Owner: ws-naming. See `rust/WORKSTREAMS.md`.

pub mod checkers;
pub mod compat;
pub mod donor;
pub mod extractors;
pub mod file_prioritizer;
pub mod identity_key;
pub mod predb_constrained;
pub mod result;

use anyhow::{Result, bail};
use clap::Subcommand;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};

pub async fn run(_ctx: Ctx, _args: RoleArgs, _stop: Shutdown) -> Result<()> {
    bail!("the fixnames role isn't ported yet")
}

/// `nntmux-ctl namefix ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Reports that nothing is ported here yet. Replace this as commands arrive.
    Status,
}

pub async fn ctl(_ctx: Ctx, command: Command) -> Result<()> {
    match command {
        Command::Status => bail!("no namefix commands are ported yet"),
    }
}
