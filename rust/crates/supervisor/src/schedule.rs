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

/// Laravel's `(bool) env()`: unset takes the default, and empty, `0` and `false` are off.
fn flag(key: &str, default: bool) -> bool {
    nntmux_core::optional(key).map_or(default, |value| {
        let value = value.to_ascii_lowercase();
        !matches!(value.as_str(), "" | "0" | "false" | "(false)")
    })
}

/// The role runs this pass itself unless the schedule owns it, so it has exactly one launcher.
fn predbft_scheduled() -> bool {
    flag("TMUX_FIX_NAMES_PREDBFT", true) && flag("TMUX_FIX_NAMES_PREDBFT_SCHEDULED", true)
}

pub fn tasks() -> Vec<Task<Ctx>> {
    use nntmux_ops::{accounts, activity, disposable, resets, stats, system_metrics};
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
        Task {
            enabled: predbft_scheduled,
            ..task(
                "multiprocessing:fixrelnames predbft",
                EveryMinutes(5),
                |ctx| {
                    Box::pin(async move {
                        let kind = "predbft".to_owned();
                        nntmux_namefix::ctl(ctx, nntmux_namefix::Command::Fixrelnames { kind })
                            .await
                    })
                },
            )
        },
        task("nntmux:remove-bad", Cadence::Hourly, |ctx| {
            Box::pin(async move {
                resets::remove_bad(&ctx.db).await?;
                Ok(())
            })
        }),
        task(
            "disposable:update",
            Weekly {
                day: Weekday::Sun,
                hour: 0,
                minute: 0,
            },
            |_| {
                Box::pin(async move {
                    let path = disposable::list_path();
                    disposable::refresh(disposable::SOURCE, &path).await?;
                    Ok(())
                })
            },
        ),
    ]
}
