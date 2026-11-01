//! Group updates and backfill: headers, collections, binaries and parts.
//!
//! Owner: ws-ingest. See `rust/WORKSTREAMS.md`.

pub mod backfill;
pub mod blacklist;
pub mod clean;
pub mod config;
pub mod get_range;
pub mod groups;
pub mod header;
pub mod maintenance;
pub mod missed;
pub mod nntp;
pub mod run;
pub mod scan;
pub mod store;

use std::sync::Arc;

use anyhow::{Context, Result, bail};
use clap::{Subcommand, ValueEnum};
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};

use crate::backfill::{Order, Target};
use crate::get_range::{RangeMode, RangeRequest, get_range};
use crate::run::{Connector, Job, Runner, runner};
use crate::scan::Ingest;
use crate::store::NoHooks;

pub async fn run_update(ctx: Ctx, args: RoleArgs, stop: Shutdown) -> Result<()> {
    run::run_update_role(ctx, args.once, stop).await
}

pub async fn run_backfill(ctx: Ctx, args: RoleArgs, stop: Shutdown) -> Result<()> {
    run::run_backfill_role(ctx, args.once, stop).await
}

/// Where news server connections come from. `ws-formats` owns the client.
pub(crate) fn connector() -> Result<Arc<dyn Connector>> {
    bail!("the NNTP client isn't merged yet")
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, ValueEnum)]
pub enum SafeType {
    Binaries,
    Backfill,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, ValueEnum)]
pub enum StatsType {
    Daily,
    Hourly,
}

/// `nntmux-ctl ingest ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// articles:get-range: get a range of article headers for a group.
    GetRange {
        #[arg(value_enum)]
        mode: RangeMode,
        group: String,
        first: u64,
        last: u64,
        /// Refuse a forward range beyond the persisted group cursor.
        #[arg(long)]
        contiguous: bool,
        /// Repair missing headers before this group visit.
        #[arg(long)]
        repair: bool,
    },
    /// backfill:group: backfill one group. Type 1 goes to its date target unless given a quantity;
    /// type 2 takes the configured backfill quantity.
    BackfillGroup {
        group: String,
        #[arg(default_value_t = 1)]
        kind: u8,
        quantity: Option<u64>,
    },
    /// binaries:part-repair: repair missing headers for a group.
    PartRepair { group: String },
    /// group:update-headers: update one group's article headers.
    UpdateHeaders {
        group: String,
        #[arg(long, default_value_t = 0)]
        max: u64,
    },
    /// groups:update: update first and last article numbers for all active groups.
    GroupsUpdate,
    /// update:binaries: update one group, or all active ones.
    UpdateBinaries {
        group: Option<String>,
        max: Option<u64>,
    },
    /// update:backfill: all, alph, date, or a group name, with an optional quantity.
    UpdateBackfill {
        #[arg(default_value = "all")]
        mode: String,
        quantity: Option<u64>,
    },
    /// multiprocessing:backfill: backfill every backfill-enabled group in parallel.
    MultiBackfill { limit: Option<u64> },
    /// multiprocessing:safe: one safe binaries or backfill pass.
    Safe {
        #[arg(value_enum)]
        kind: SafeType,
    },
    /// nntmux:backfill-user-activity-stats
    BackfillUserActivityStats {
        #[arg(long, value_enum, default_value = "daily")]
        kind: StatsType,
        #[arg(long, default_value_t = 30)]
        days: i64,
        #[arg(long, default_value_t = 24)]
        hours: i64,
        #[arg(long)]
        force: bool,
    },
    /// releases:reclassify-header-rejections
    ReclassifyHeaderRejections {
        #[arg(long, default_value_t = 5000)]
        chunk: u64,
        #[arg(long, default_value_t = 0)]
        after: u64,
        #[arg(long, default_value_t = 0)]
        max_seconds: u64,
    },
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    match command {
        Command::BackfillUserActivityStats {
            kind,
            days,
            hours,
            force,
        } => {
            let totals = match kind {
                StatsType::Daily => maintenance::backfill_daily_stats(&ctx.db, days, force).await?,
                StatsType::Hourly => {
                    maintenance::backfill_hourly_stats(&ctx.db, hours, force).await?
                }
            };
            println!("Stats collected: {}", totals.collected);
            if totals.skipped > 0 {
                println!("Stats skipped (already existed): {}", totals.skipped);
            }
            Ok(())
        }
        Command::ReclassifyHeaderRejections {
            chunk,
            after,
            max_seconds,
        } => {
            let done =
                maintenance::reclassify_header_rejections(&ctx.db, chunk, after, max_seconds)
                    .await?;
            if let Some(resume) = done.resume_after {
                println!("Stopped after {max_seconds} seconds; continue with --after={resume}.");
            }
            println!(
                "Reclassified {} candidates with header defects.",
                done.changed
            );
            Ok(())
        }
        other => with_server(ctx, other).await,
    }
}

async fn with_server(ctx: Ctx, command: Command) -> Result<()> {
    let runner = runner(&ctx, None).await?;
    match command {
        Command::GroupsUpdate => {
            println!("Updated {} groups", runner.update_short_groups().await?);
            Ok(())
        }
        Command::MultiBackfill { limit } => report(runner.backfill(limit).await?),
        Command::Safe { kind } => {
            let result = match kind {
                SafeType::Binaries => runner.safe_binaries(Some(ctx.redis.clone())).await?,
                SafeType::Backfill => runner.safe_backfill().await?,
            };
            report(result)
        }
        Command::UpdateBinaries { group: None, max } => {
            let limit = max.unwrap_or(default_max(&runner));
            let plan = groups::active(&ctx.db)
                .await?
                .into_iter()
                .map(|group| run::GroupJobs {
                    group: group.id,
                    jobs: vec![Job::UpdateHeaders {
                        group: group.name,
                        max: limit,
                    }],
                })
                .collect();
            // updateAllGroups went through them one at a time.
            report(runner.run(plan, 1).await)
        }
        one => one_group(&runner, one).await,
    }
}

fn default_max(runner: &Runner) -> u64 {
    match runner.config.max_headers_per_iteration {
        0 => 1_000_000,
        max => max,
    }
}

fn report(result: run::PassResult) -> Result<()> {
    println!(
        "{} jobs finished, {} failed",
        result.finished, result.failed
    );
    Ok(())
}

async fn one_group(runner: &Runner, command: Command) -> Result<()> {
    let mut connection = runner.connector.connect().await?;
    let mut ingest = Ingest::new(
        &runner.pool,
        &runner.config,
        &NoHooks,
        connection.as_mut(),
        None,
    );
    match command {
        Command::GetRange {
            mode,
            group,
            first,
            last,
            contiguous,
            repair,
        } => {
            let request = RangeRequest {
                mode,
                group,
                first,
                last,
                contiguous,
                repair,
            };
            get_range(&mut ingest, &request).await
        }
        Command::BackfillGroup {
            group,
            kind,
            quantity,
        } => {
            let target = match (kind, quantity) {
                (1 | 2, Some(count)) => Target::Articles(count),
                (1, None) => Target::Date,
                (2, None) => Target::Articles(runner.config.backfill_quantity),
                _ => bail!("Invalid backfill type. Must be 1 (interval) or 2 (all)."),
            };
            ingest.backfill_all(Some(&group), target, Order::Name).await
        }
        Command::PartRepair { group } => {
            let found = required_group(&runner.pool, &group).await?;
            if ingest.select_group(&found.name).await.is_none() {
                bail!("Couldn't select {group}");
            }
            ingest.part_repair(&found).await
        }
        Command::UpdateHeaders { group, max } => {
            let found = required_group(&runner.pool, &group).await?;
            ingest.update_group(&found, max).await
        }
        Command::UpdateBinaries {
            group: Some(group),
            max,
        } => {
            let found = required_group(&runner.pool, &group).await?;
            let limit = max.unwrap_or(default_max(runner));
            ingest.update_group(&found, limit).await
        }
        Command::UpdateBackfill { mode, quantity } => match (mode.as_str(), quantity) {
            ("all", None) => ingest.backfill_all(None, Target::Date, Order::Name).await,
            ("alph", Some(count)) => {
                ingest
                    .backfill_all(None, Target::Articles(count), Order::Name)
                    .await
            }
            ("date", Some(count)) => {
                ingest
                    .backfill_all(None, Target::Articles(count), Order::Date)
                    .await
            }
            (name, None) if is_group_name(name) => {
                ingest
                    .backfill_all(Some(name), Target::Date, Order::Name)
                    .await
            }
            (name, Some(count)) if is_group_name(name) => {
                ingest
                    .backfill_all(Some(name), Target::Articles(count), Order::Name)
                    .await
            }
            _ => bail!("Wrong set of arguments: all, alph N, date N, GROUP, or GROUP N."),
        },
        other => bail!("{other:?} doesn't take one group"),
    }
}

async fn required_group(pool: &sqlx::MySqlPool, name: &str) -> Result<groups::Group> {
    groups::by_name(pool, name)
        .await?
        .with_context(|| format!("Group not found: {name}"))
}

/// A dotted name, as opposed to a mode word.
fn is_group_name(name: &str) -> bool {
    !matches!(name, "all" | "alph" | "date" | "safe")
        && name.contains('.')
        && name
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || b"+_.-".contains(&byte))
}
