//! Additional processing, NFO, PAR2 and media.
//!
//! Owner: ws-postprocess. See `rust/WORKSTREAMS.md`.

pub mod additional;
pub mod archive_info;
pub mod config;
pub mod workspace;

use anyhow::{Result, bail};
use clap::Subcommand;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};

use crate::config::Config;

pub async fn run(_ctx: Ctx, _args: RoleArgs, _stop: Shutdown) -> Result<()> {
    bail!("the postprocess role isn't ported yet")
}

/// `nntmux-ctl postprocess ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Inspect the additional post-processing backlog, claims, capacity, indexes and temp path.
    AdditionalDiagnose {
        /// Output machine-readable JSON.
        #[arg(long)]
        json: bool,
    },
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    match command {
        Command::AdditionalDiagnose { json } => {
            let config = Config::load(&ctx.db).await?;
            let report = additional::diagnose::inspect(&ctx.db, &config).await?;
            if json {
                println!("{}", serde_json::to_string_pretty(&report)?);
            } else {
                print!("{}", additional::diagnose::render(&report));
            }
            Ok(())
        }
    }
}
