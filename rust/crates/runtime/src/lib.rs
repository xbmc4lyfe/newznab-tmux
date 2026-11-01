//! What every worker role shares: the connections, a clean stop on SIGTERM, and the
//! claim-work-commit loop.

use std::future::Future;
use std::sync::Arc;
use std::time::Duration;

use anyhow::{Context, Result};
use nntmux_core::Config;
use redis::aio::ConnectionManager;
use sqlx::MySqlPool;
use tokio::signal::unix::{SignalKind, signal};
use tokio::task::JoinSet;
use tokio_util::sync::CancellationToken;
use tracing::{error, info};

/// Shared handles. Cheap to clone. Manticore and NNTP clients belong to the crates that use
/// them, built from `config`.
#[derive(Clone)]
pub struct Ctx {
    pub config: Arc<Config>,
    pub db: MySqlPool,
    pub redis: ConnectionManager,
}

impl Ctx {
    pub async fn connect(config: Config) -> Result<Self> {
        let db = nntmux_db::connect(&config.database).await?;
        let client =
            redis::Client::open(config.redis_url.as_str()).context("parsing the Redis URL")?;
        let redis = ConnectionManager::new(client)
            .await
            .context("connecting to Redis")?;
        Ok(Self {
            config: Arc::new(config),
            db,
            redis,
        })
    }

    /// True while operators or a deploy have asked processing to pause. Workers finish what
    /// they hold and claim nothing new. Reads the row `tmux:drain` used.
    pub async fn paused(&self) -> Result<bool> {
        let stop: Option<bool> =
            sqlx::query_scalar("SELECT stop_requested FROM processing_runtime_states WHERE id = 1")
                .fetch_optional(&self.db)
                .await?;
        Ok(stop.unwrap_or(false))
    }
}

/// Flags every role accepts.
#[derive(Clone, Debug, clap::Args)]
pub struct RoleArgs {
    /// Concurrent work slots.
    #[arg(long, env = "NNTMUX_CONCURRENCY", default_value_t = 4)]
    pub concurrency: usize,
    /// Seconds to wait when there's no work.
    #[arg(long, env = "NNTMUX_IDLE_SECONDS", default_value_t = 10)]
    pub idle_seconds: u64,
    /// Do one pass over the available work, then exit.
    #[arg(long)]
    pub once: bool,
}

/// A clean stop: stop claiming, finish in-flight work, exit.
#[derive(Clone, Default)]
pub struct Shutdown(CancellationToken);

impl Shutdown {
    /// Cancels on the first SIGTERM or SIGINT.
    pub fn on_signals() -> Result<Self> {
        let token = CancellationToken::new();
        let mut term = signal(SignalKind::terminate()).context("installing SIGTERM")?;
        let mut int = signal(SignalKind::interrupt()).context("installing SIGINT")?;
        let trigger = token.clone();
        tokio::spawn(async move {
            tokio::select! {
                _ = term.recv() => info!("SIGTERM, draining"),
                _ = int.recv() => info!("SIGINT, draining"),
            }
            trigger.cancel();
        });
        Ok(Self(token))
    }

    pub fn requested(&self) -> bool {
        self.0.is_cancelled()
    }

    pub fn token(&self) -> CancellationToken {
        self.0.clone()
    }

    pub fn request(&self) {
        self.0.cancel();
    }

    /// Sleeps, waking early on shutdown.
    pub async fn sleep(&self, duration: Duration) {
        tokio::select! {
            () = tokio::time::sleep(duration) => {}
            () = self.0.cancelled() => {}
        }
    }
}

/// Runs `args.concurrency` copies of `work` until shutdown. `work` claims one unit, processes
/// it and commits. It returns false when there was nothing to claim. An error is logged and
/// counted, and that slot backs off, so one bad unit can't stop the role.
pub async fn run_slots<F, Fut>(
    ctx: Ctx,
    role: &'static str,
    args: RoleArgs,
    stop: Shutdown,
    work: F,
) -> Result<()>
where
    F: Fn(Ctx, usize) -> Fut + Clone + Send + 'static,
    Fut: Future<Output = Result<bool>> + Send + 'static,
{
    let idle = Duration::from_secs(args.idle_seconds);
    let mut tasks = JoinSet::new();
    for slot in 0..args.concurrency {
        let (ctx, stop, work) = (ctx.clone(), stop.clone(), work.clone());
        let once = args.once;
        tasks.spawn(async move {
            while !stop.requested() {
                if ctx.paused().await.unwrap_or(false) {
                    stop.sleep(Duration::from_secs(5)).await;
                    continue;
                }
                match work(ctx.clone(), slot).await {
                    Ok(true) => {
                        metrics::counter!("nntmux_work_units_total", "role" => role).increment(1);
                    }
                    Ok(false) => {
                        if once {
                            break;
                        }
                        stop.sleep(idle).await;
                    }
                    Err(failure) => {
                        metrics::counter!("nntmux_work_errors_total", "role" => role).increment(1);
                        error!(
                            role,
                            slot,
                            error = format!("{failure:#}"),
                            "work unit failed"
                        );
                        if once {
                            break;
                        }
                        stop.sleep(idle).await;
                    }
                }
            }
        });
    }
    while let Some(joined) = tasks.join_next().await {
        joined.context("a work slot panicked")?;
    }
    Ok(())
}
