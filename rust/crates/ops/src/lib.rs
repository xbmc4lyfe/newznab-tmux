//! Operator commands, monitoring and status.
//!
//! Owner: ws-ops. See `rust/WORKSTREAMS.md`.

pub mod accounts;
pub mod activity;
pub mod disposable;
pub mod populate;
pub mod resets;
pub mod runtime_state;
pub mod stats;
pub mod system_metrics;

use anyhow::{Result, bail};
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
    /// Rebuild Manticore indexes from the database (`nntmux:populate`).
    Populate {
        /// Index to rebuild: releases, predb, movies, tvshows, music, books, games, console, steam
        /// or anime. Repeatable.
        #[arg(long = "index")]
        indexes: Vec<String>,
        /// Rebuild every index.
        #[arg(long)]
        all: bool,
        /// Ids per database window.
        #[arg(long, default_value_t = 5000)]
        count: i64,
        /// Windows fetched and inserted at once.
        #[arg(long, default_value_t = 4)]
        parallel: usize,
        /// Optimize the indexes instead of rebuilding them.
        #[arg(long)]
        optimize: bool,
    },
    /// Reset the database to a blank state, keeping settings (`nntmux:resetdb`). Local only.
    ResetDb {
        /// Confirm: this removes all releases, NZBs, images and article tables.
        #[arg(long)]
        yes: bool,
    },
    /// Empty article tables, reset groups, delete releases with no NZB (`nntmux:reset-truncate`).
    ResetTruncate,
    /// Reset post-processing for categories, or all of it (`nntmux:resetpp`).
    ResetPp {
        /// music, console, movie, game, tv, adult, book, misc or all. Repeat or comma separate.
        #[arg(short, long)]
        category: Vec<String>,
    },
    /// Queue a TV show's releases for TV post-processing again (`nntmux:reset-tv-show`).
    ResetTvShow {
        /// The show's `videos.id`.
        videos_id: i64,
        /// Show counts only.
        #[arg(long)]
        dry_run: bool,
    },
    /// Check tables and optimize any that fail (`nntmux:optimize-tables`).
    OptimizeTables {
        /// Table to check, or all. Repeatable.
        #[arg(short, long)]
        table: Vec<String>,
    },
    /// Delete failed releases and mark passworded ones (`nntmux:remove-bad`).
    RemoveBad,
    /// Refresh the disposable-domain list the site reads (`disposable:update`).
    DisposableUpdate,
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
        Command::Populate {
            indexes,
            all,
            count,
            parallel,
            optimize,
        } => {
            let client = nntmux_search::SearchClient::from_ctx(&ctx)?;
            if optimize {
                client.optimize_indexes().await?;
                println!("Optimization completed successfully!");
                return Ok(());
            }
            let selected: Vec<String> = if all {
                populate::INDEXES
                    .iter()
                    .map(|index| (*index).to_owned())
                    .collect()
            } else {
                indexes
            };
            if selected.is_empty() {
                bail!("Name at least one --index, or pass --all.");
            }
            let options = populate::Options { count, parallel };
            let mut failed = false;
            for index in &selected {
                match populate::populate(&client, &ctx.db, index, options).await {
                    Ok(documents) => println!("{index}: {documents} documents indexed."),
                    Err(failure) => {
                        eprintln!("{index}: {failure:#}");
                        failed = true;
                    }
                }
            }
            if failed {
                bail!("Some indexes didn't populate.");
            }
        }
        Command::ResetDb { yes } => {
            if !resets::is_local() {
                bail!("This command can only be run in local environment");
            }
            if !yes {
                bail!(
                    "This removes all releases, NZBs, images and article tables. Pass --yes to go ahead."
                );
            }
            reset_database(&ctx).await?;
            println!("Deleted all releases, images, previews and samples.");
        }
        Command::ResetTruncate => {
            let verify = verify_context(&ctx)?;
            let deleted = resets::reset_truncate(&verify).await?;
            println!("{deleted} releases had no nzb, deleted.");
        }
        Command::ResetPp { category } => {
            let total = resets::reset_postprocessing(&ctx.db, &category).await?;
            println!("{total} release(s) reset.");
        }
        Command::ResetTvShow { videos_id, dry_run } => {
            match resets::reset_tv_show(&ctx.db, videos_id, dry_run).await? {
                resets::TvShowReset::Reset { title, releases } => {
                    println!("{releases} release(s) of {title} reset.");
                }
                resets::TvShowReset::WouldReset { title, releases } => {
                    println!("[Dry Run] Would reset {releases} release(s) for TV show: {title}");
                }
                resets::TvShowReset::NoReleases => {
                    println!("No releases found for this videos_id in TV categories");
                }
            }
        }
        Command::OptimizeTables { table } => {
            for name in resets::optimize_tables(&ctx.db, &table).await? {
                println!("Table {name} needed repair and was optimized.");
            }
            println!("Tables checked.");
        }
        Command::RemoveBad => {
            let (removed, marked) = resets::remove_bad(&ctx.db).await?;
            println!("Removed {removed} bad releases and marked {marked} as passworded.");
        }
        Command::DisposableUpdate => {
            let path = disposable::list_path();
            let count = disposable::refresh(disposable::SOURCE, &path).await?;
            println!("Saved {count} domains to {}.", path.display());
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

fn verify_context(ctx: &Ctx) -> Result<nntmux_verify::context::Verify> {
    Ok(nntmux_verify::context::Verify::new(
        ctx.db.clone(),
        nntmux_verify::cache::Cache::new(ctx.redis.clone(), "nntmux:"),
        nntmux_verify::config::VerificationConfig::from_env()?,
    ))
}

async fn reset_database(ctx: &Ctx) -> Result<()> {
    let verify = verify_context(ctx)?;
    nntmux_verify::reset::run(&verify, || async {
        resets::clear_database(&ctx.db).await?;
        nntmux_search::SearchClient::from_ctx(ctx)?
            .truncate_indexes(&resets::RESET_INDEXES)
            .await?;
        let nzbs = nntmux_core::var_or("PATH_TO_NZBS", "storage/nzb");
        resets::remove_files(std::path::Path::new(&nzbs), &[])?;
        let covers = nntmux_core::var_or("COVERS_PATH", "storage/covers");
        resets::remove_files(
            std::path::Path::new(&covers),
            &[".gitignore", "no-cover.jpg", "no-backdrop.jpg"],
        )?;
        Ok(())
    })
    .await
}
