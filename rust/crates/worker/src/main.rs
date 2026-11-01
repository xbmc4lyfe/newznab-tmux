//! `nntmux-worker <role>`: the background work. Each role runs as its own Compose service.

use anyhow::Result;
use clap::{Parser, Subcommand};
use nntmux_core::Config;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};
use tracing::error;

#[derive(Parser)]
#[command(version, about)]
struct Cli {
    #[command(subcommand)]
    role: Role,
    #[command(flatten)]
    args: RoleArgs,
}

#[derive(Clone, Copy, Debug, Subcommand)]
enum Role {
    Ingest,
    Backfill,
    Releases,
    Fixnames,
    Predb,
    Postprocess,
    Fingerprint,
    Verify,
    SearchSync,
    Supervisor,
    Mail,
}

#[tokio::main]
async fn main() -> Result<()> {
    let cli = Cli::parse();
    let config = Config::from_env()?;
    let metrics = nntmux_telemetry::init(&config.log_filter, config.log_json)?;
    let stop = Shutdown::on_signals()?;
    let health = tokio::spawn(nntmux_telemetry::serve(
        config.health_addr,
        metrics,
        stop.token(),
    ));
    let ctx = Ctx::connect(config).await?;
    let args = cli.args;
    let stopping = stop.clone();

    let outcome = match cli.role {
        Role::Ingest => nntmux_ingest::run_update(ctx, args, stop).await,
        Role::Backfill => nntmux_ingest::run_backfill(ctx, args, stop).await,
        Role::Releases => nntmux_releases::run(ctx, args, stop).await,
        Role::Fixnames => nntmux_namefix::run(ctx, args, stop).await,
        Role::Predb => nntmux_predb::run(ctx, args, stop).await,
        Role::Postprocess => nntmux_postprocess::run(ctx, args, stop).await,
        Role::Fingerprint => nntmux_fingerprint::run(ctx, args, stop).await,
        Role::Verify => nntmux_verify::run(ctx, args, stop).await,
        Role::SearchSync => nntmux_search::run_sync(ctx, args, stop).await,
        Role::Supervisor => nntmux_supervisor::run(ctx, args, stop).await,
        Role::Mail => nntmux_mail::run(ctx, args, stop).await,
    };
    if let Err(failure) = &outcome {
        error!(role = ?cli.role, error = format!("{failure:#}"), "role stopped");
    }
    stopping.request();
    health.await??;
    outcome
}
