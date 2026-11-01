//! Operator commands, monitoring and status.
//!
//! Owner: ws-ops. See `rust/WORKSTREAMS.md`.

pub mod accounts;
pub mod activity;
pub mod runtime_state;
pub mod stats;
pub mod system_metrics;

use anyhow::Result;
use clap::Subcommand;
use nntmux_runtime::{Ctx, Shutdown};

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
    /// Let workers claim work again and clear any drain (`tmux:start`).
    Start,
    /// Pause processing until the next start (`tmux:stop`). Workers finish what they hold.
    Stop {
        /// Wait as long as it takes for active work to finish.
        #[arg(long)]
        wait: bool,
        /// Seconds to wait for active work to finish.
        #[arg(long, default_value_t = 5)]
        timeout: u64,
    },
    /// Pause new work and wait for active workers to finish (`tmux:drain`).
    Drain {
        /// Seconds to wait for active workers.
        #[arg(long, default_value_t = 90)]
        timeout: u64,
        /// Restore the state from before the drain.
        #[arg(long)]
        resume: bool,
        /// Restore only the drain this operation owns.
        #[arg(long)]
        token: Option<String>,
    },
    /// Check that the supervisor is alive when processing should be running (`tmux:health-check`).
    HealthCheck,
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
        Command::Start => {
            runtime_state::start(&ctx).await?;
            println!("Processing started.");
        }
        Command::Stop { wait, timeout } => {
            let remaining =
                runtime_state::stop(&ctx, wait, timeout, &Shutdown::on_signals()?).await?;
            if remaining.is_empty() {
                println!("Processing stopped.");
            } else {
                println!(
                    "Processing stopped; these roles are still finishing: {}.",
                    remaining.join(", ")
                );
            }
        }
        Command::Drain {
            timeout,
            resume,
            token,
        } => {
            if resume {
                println!("{}", runtime_state::resume(&ctx, token.as_deref()).await?);
            } else {
                runtime_state::drain(&ctx, timeout, token.as_deref(), &Shutdown::on_signals()?)
                    .await?;
                println!("Active processing workers finished; scheduling remains paused.");
            }
        }
        Command::HealthCheck => println!("{}", runtime_state::health_check(&ctx).await?),
    }
    Ok(())
}
