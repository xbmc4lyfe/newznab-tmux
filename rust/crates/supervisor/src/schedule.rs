//! The schedule from `routes/console.php`. Entries that have no place in the Rust stack, or that
//! a role now runs continuously, are in `rust/porting/ledger/ws-ops.toml` with the reason.

use chrono::Weekday;
use nntmux_runtime::Ctx;

use crate::cadence::Cadence::{self, Daily, EveryMinutes, Weekly};
use crate::engine::Task;

fn always() -> bool {
    true
}

fn task(
    name: &'static str,
    cadence: Cadence,
    run: fn(Ctx) -> futures_util::future::BoxFuture<'static, anyhow::Result<()>>,
) -> Task<Ctx> {
    Task {
        name,
        cadence,
        enabled: always,
        run,
    }
}

pub fn tasks() -> Vec<Task<Ctx>> {
    use nntmux_ops::{accounts, activity, stats, system_metrics};
    vec![
        task("nntmux:collect-stats", EveryMinutes(15), |ctx| {
            Box::pin(async move { stats::collect(&ctx.db).await })
        }),
        task("metrics:collect", EveryMinutes(5), |ctx| {
            Box::pin(async move { system_metrics::collect(&ctx.db).await })
        }),
        task(
            "metrics:collect --cleanup",
            Daily { hour: 3, minute: 0 },
            |ctx| {
                Box::pin(async move {
                    system_metrics::cleanup(&ctx.db).await?;
                    system_metrics::collect(&ctx.db).await
                })
            },
        ),
        task(
            "nntmux:disable-expired-promotions",
            Cadence::MIDNIGHT,
            |ctx| {
                Box::pin(async move {
                    accounts::disable_expired_promotions(&ctx.db).await?;
                    Ok(())
                })
            },
        ),
        task(
            "nntmux:disable-expired-registration-periods",
            EveryMinutes(5),
            |ctx| {
                Box::pin(async move {
                    accounts::disable_expired_registration_periods(&ctx.db).await?;
                    Ok(())
                })
            },
        ),
        task(
            "user-activity-stats-cleanup",
            Weekly {
                day: Weekday::Mon,
                hour: 4,
                minute: 0,
            },
            |ctx| {
                Box::pin(async move {
                    activity::cleanup_daily_stats(&ctx.db).await?;
                    Ok(())
                })
            },
        ),
        task(
            "user-activity-hourly-stats-cleanup",
            Daily {
                hour: 4,
                minute: 30,
            },
            |ctx| {
                Box::pin(async move {
                    activity::cleanup_hourly_stats(&ctx.db).await?;
                    Ok(())
                })
            },
        ),
        task("cleanup-api-request-logs", Cadence::Hourly, |ctx| {
            Box::pin(async move {
                activity::cleanup_request_logs(&ctx.db).await?;
                Ok(())
            })
        }),
        task("predb:check 3 --quiet", EveryMinutes(10), |ctx| {
            Box::pin(async move {
                nntmux_predb::check_pre(&ctx.db, Some(3)).await?;
                Ok(())
            })
        }),
    ]
}
