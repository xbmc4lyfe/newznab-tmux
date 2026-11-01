//! `nntmux-ctl`: the admin CLI that takes over from artisan's one-off commands.

use anyhow::Result;
use clap::{Parser, Subcommand};
use nntmux_core::Config;
use nntmux_runtime::Ctx;

#[derive(Parser)]
#[command(version, about)]
struct Cli {
    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand)]
enum Command {
    /// Apply pending schema migrations.
    Migrate,
    #[command(subcommand)]
    Ingest(nntmux_ingest::Command),
    #[command(subcommand)]
    Categorize(nntmux_categorize::Command),
    #[command(subcommand)]
    Namefix(nntmux_namefix::Command),
    #[command(subcommand)]
    Predb(nntmux_predb::Command),
    #[command(subcommand)]
    Releases(nntmux_releases::Command),
    #[command(subcommand)]
    Fingerprint(nntmux_fingerprint::Command),
    #[command(subcommand)]
    Verify(nntmux_verify::Command),
    #[command(subcommand)]
    Postprocess(nntmux_postprocess::Command),
    #[command(subcommand)]
    Metadata(nntmux_metadata::Command),
    #[command(subcommand)]
    Search(nntmux_search::Command),
    #[command(subcommand)]
    Ops(nntmux_ops::Command),
    #[command(subcommand)]
    Mail(nntmux_mail::Command),
}

#[tokio::main]
async fn main() -> Result<()> {
    let cli = Cli::parse();
    let config = Config::from_env()?;
    nntmux_telemetry::init(&config.log_filter, false)?;

    if matches!(cli.command, Command::Migrate) {
        let pool = nntmux_db::connect(&config.database).await?;
        let ran = nntmux_db::migrate::run(&pool).await?;
        println!("applied {} migration(s)", ran.len());
        return Ok(());
    }

    let ctx = Ctx::connect(config).await?;
    match cli.command {
        Command::Migrate => unreachable!("handled above"),
        Command::Ingest(command) => nntmux_ingest::ctl(ctx, command).await,
        Command::Categorize(command) => nntmux_categorize::ctl(ctx, command).await,
        Command::Namefix(command) => nntmux_namefix::ctl(ctx, command).await,
        Command::Predb(command) => nntmux_predb::ctl(ctx, command).await,
        Command::Releases(command) => nntmux_releases::ctl(ctx, command).await,
        Command::Fingerprint(command) => nntmux_fingerprint::ctl(ctx, command).await,
        Command::Verify(command) => nntmux_verify::ctl(ctx, command).await,
        Command::Postprocess(command) => nntmux_postprocess::ctl(ctx, command).await,
        Command::Metadata(command) => nntmux_metadata::ctl(ctx, command).await,
        Command::Search(command) => nntmux_search::ctl(ctx, command).await,
        Command::Ops(command) => nntmux_ops::ctl(ctx, command).await,
        Command::Mail(command) => nntmux_mail::ctl(ctx, command).await,
    }
}
