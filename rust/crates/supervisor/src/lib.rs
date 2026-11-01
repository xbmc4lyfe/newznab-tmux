//! Scheduled tasks and worker supervision: the scheduler and tmux replacement.
//!
//! Owner: ws-ops. See `rust/WORKSTREAMS.md`.

use anyhow::{Result, bail};
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};

pub async fn run(_ctx: Ctx, _args: RoleArgs, _stop: Shutdown) -> Result<()> {
    bail!("the supervisor role isn't ported yet")
}
