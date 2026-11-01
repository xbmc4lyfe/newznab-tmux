//! The schedule from `routes/console.php`. Entries that have no place in the Rust stack, or that
//! a role now runs continuously, are in `rust/porting/ledger/ws-ops.toml` with the reason.

use std::time::Duration;

use anyhow::Context;
use chrono::Weekday;
use nntmux_runtime::{Ctx, busy};

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

/// Stops a slow export before its next runs pile up behind it.
const EXPORT_LIMIT: Duration = Duration::from_secs(240);
/// A wedged service can't hold the every-minute health checks for long.
const HEALTH_LIMIT: Duration = Duration::from_secs(300);

pub fn tasks() -> Vec<Task<Ctx>> {
    use nntmux_ops::{
        accounts, activity, clean_directories, disposable, expired_roles, log_index,
        metrics_export, processing_stats, resets, site_status, stats, system_metrics,
    };
    vec![
        Task {
            enabled: metrics_export::enabled,
            ..task(
                "monitoring:export-metrics --scheduled",
                EveryMinutes(1),
                |ctx| {
                    Box::pin(async move {
                        tokio::time::timeout(EXPORT_LIMIT, metrics_export::push(&ctx))
                            .await
                            .context("the metrics export ran over its limit")?
                    })
                },
            )
        },
        Task {
            enabled: metrics_export::enabled,
            ..task("processing-stats", EveryMinutes(1), |ctx| {
                Box::pin(async move { processing_stats::publish(&ctx).await })
            })
        },
        Task {
            enabled: log_index::enabled,
            ..task("nntmux:index-logs", EveryMinutes(1), |ctx| {
                Box::pin(async move {
                    let client = nntmux_search::SearchClient::from_ctx(&ctx)?;
                    log_index::LogIndexer::from_env(&client, &ctx.db)?
                        .run(&[])
                        .await?;
                    Ok(())
                })
            })
        },
        task("nntmux:update-expired-roles", Cadence::MIDNIGHT, |ctx| {
            Box::pin(async move {
                expired_roles::update_expired_roles(&ctx).await?;
                Ok(())
            })
        }),
        task("clean:directories", Cadence::Hourly, |_| {
            Box::pin(async move {
                clean_directories::clean_all().await?;
                Ok(())
            })
        }),
        task("nntmux:check-service-health", EveryMinutes(1), |ctx| {
            Box::pin(async move {
                tokio::time::timeout(
                    HEALTH_LIMIT,
                    site_status::check_all(&ctx, site_status::UPTIME_DAYS),
                )
                .await
                .context("the health checks ran over their limit")??;
                Ok(())
            })
        }),
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
        // The role runs this pass unless the schedule owns it, so it has exactly one launcher.
        Task {
            enabled: nntmux_namefix::predbft_scheduled,
            ..task(
                "multiprocessing:fixrelnames predbft",
                EveryMinutes(5),
                |ctx| {
                    Box::pin(async move {
                        // Renaming is processing, so it waits out a pause and a drain sees it.
                        let Some(_busy) = busy::enter(&ctx, "fixnames").await? else {
                            return Ok(());
                        };
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
