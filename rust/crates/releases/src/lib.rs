//! Collection to release assembly, and NZB creation and storage.
//!
//! Owner: ws-releases. See `rust/WORKSTREAMS.md`.

use anyhow::{Result, bail};
use clap::Subcommand;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};

pub async fn run(_ctx: Ctx, _args: RoleArgs, _stop: Shutdown) -> Result<()> {
    bail!("the releases role isn't ported yet")
}

/// `nntmux-ctl releases ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Reports that nothing is ported here yet. Replace this as commands arrive.
    Status,
}

pub async fn ctl(_ctx: Ctx, command: Command) -> Result<()> {
    match command {
        Command::Status => bail!("no releases commands are ported yet"),
    }
}
