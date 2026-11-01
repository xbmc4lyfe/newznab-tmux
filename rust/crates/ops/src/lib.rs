//! Operator commands, monitoring and status.
//!
//! Owner: ws-ops. See `rust/WORKSTREAMS.md`.

pub mod accounts;
pub mod activity;
pub mod stats;
pub mod system_metrics;

use anyhow::Result;
use clap::Subcommand;
use nntmux_runtime::Ctx;

/// `nntmux-ctl ops ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Collect the site statistics the admin pages read (`nntmux:collect-stats`).
    CollectStats,
    /// Store the current CPU and RAM use (`metrics:collect`).
    MetricsCollect {
        /// Delete metrics older than 60 days first.
        #[arg(long)]
        cleanup: bool,
    },
    /// Disable role promotions past their end date (`nntmux:disable-expired-promotions`).
    DisableExpiredPromotions,
    /// Complete registration periods past their end (`nntmux:disable-expired-registration-periods`).
    DisableExpiredRegistrationPeriods,
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    match command {
        Command::CollectStats => {
            stats::collect(&ctx.db).await?;
            println!("Site stats collected.");
        }
        Command::MetricsCollect { cleanup } => {
            if cleanup {
                let deleted = system_metrics::cleanup(&ctx.db).await?;
                println!("Deleted {deleted} old metric records.");
            }
            system_metrics::collect(&ctx.db).await?;
            println!("System metrics collected successfully.");
        }
        Command::DisableExpiredPromotions => {
            let names = accounts::disable_expired_promotions(&ctx.db).await?;
            for name in &names {
                println!("Disabled promotion: {name}");
            }
            println!("Disabled {} expired promotion(s).", names.len());
        }
        Command::DisableExpiredRegistrationPeriods => {
            let names = accounts::disable_expired_registration_periods(&ctx.db).await?;
            for name in &names {
                println!("Marked registration period as done: {name}");
            }
            println!("Completed {} expired registration period(s).", names.len());
        }
    }
    Ok(())
}
