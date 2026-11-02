//! `multiprocessing:postprocess`, `update:postprocess` and the postprocess role. Port of
//! `ForkingService`'s post-processing methods, `PostProcessRunner` and `PostProcessService`.
//!
//! PHP forked one `postprocess:guid` child per GUID bucket. Here each bucket is a task in this
//! process, bounded by the same thread settings, running the same per-bucket code the
//! `postprocess guid` subcommand runs.
//!
//! Scope: the indexer covers TV and movies only. Anime is out of scope, and the book, music,
//! console and game providers aren't ported; those types run as one unbucketed pass, which fails
//! with the provider's not-ported error. `update all` and the role skip them.

use std::future::Future;
use std::sync::Arc;
use std::time::{Duration, Instant};

use anyhow::{Result, bail};
use nntmux_metadata::Renamed;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};
use tokio::sync::Semaphore;
use tokio::task::JoinSet;

use crate::additional::candidates::{self, Scope};
use crate::additional::orchestrator;
use crate::commands::{self, GuidOptions};
use crate::config::Config;
use crate::workspace;

/// Batches one additional-processing worker claims before it hands its bucket back.
pub const ADDITIONAL_WORKER_MAX_BATCHES: i64 = 4;

/// A kind of post-processing.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Kind {
    Additional,
    Nfo,
    Movies,
    Tv,
    Anime,
    Books,
    Music,
    Console,
    Games,
    /// Books, music, console and games.
    Amazon,
}

impl Kind {
    /// The `multiprocessing:postprocess` type names.
    pub fn from_short(name: &str) -> Option<Self> {
        Some(match name {
            "add" => Self::Additional,
            "nfo" => Self::Nfo,
            "mov" => Self::Movies,
            "tv" => Self::Tv,
            "ani" => Self::Anime,
            "boo" => Self::Books,
            "mus" => Self::Music,
            "con" => Self::Console,
            "gam" => Self::Games,
            "ama" => Self::Amazon,
            _ => return None,
        })
    }

    /// The `update:postprocess` type names, except `all`.
    pub fn from_long(name: &str) -> Option<Self> {
        Some(match name {
            "additional" => Self::Additional,
            "nfo" => Self::Nfo,
            "movies" => Self::Movies,
            "tv" => Self::Tv,
            "anime" => Self::Anime,
            "book" => Self::Books,
            "music" => Self::Music,
            "console" => Self::Console,
            "games" => Self::Games,
            "amazon" => Self::Amazon,
            _ => return None,
        })
    }

    fn task_name(self) -> &'static str {
        match self {
            Self::Additional => "postProcess_add",
            Self::Nfo => "postProcess_nfo",
            Self::Movies => "postProcess_mov",
            Self::Tv => "postProcess_tv",
            Self::Anime => "postProcess_ani",
            Self::Books => "postProcess_boo",
            Self::Music => "postProcess_mus",
            Self::Console => "postProcess_con",
            Self::Games => "postProcess_gam",
            Self::Amazon => "postProcess_ama",
        }
    }
}

pub const MULTIPROCESS_HELP: &str =
    "Type must be one of: ama, add, ani, mov, nfo, tv, boo, mus, con, gam

ama => Do amazon (books+music+console+games) processing in parallel
boo => Do books processing
mus => Do music processing
con => Do console processing
gam => Do games processing
add => Do additional (rar|zip) processing
ani => Do anime processing
mov => Do movie processing
nfo => Do NFO processing
tv  => Do TV processing";

pub const UPDATE_HELP: &str = "Available types:
  all         - Does all the types of post processing
  nfo         - Processes NFO files
  movies      - Processes movies
  music       - Processes music
  console     - Processes console games
  games       - Processes games
  book        - Processes books
  anime       - Processes anime
  tv          - Processes tv
  additional  - Processes previews/mediainfo/etc...
  amazon      - Processes books, music, console, and games";

/// `ForkingService::process*`: one kind over every bucket with work, in parallel. Once `stop` is
/// requested no further bucket or additional-processing batch starts; work already claimed
/// finishes.
pub async fn multiprocess(
    ctx: &Ctx,
    kind: Kind,
    renamed_only: bool,
    stop: &Shutdown,
) -> Result<()> {
    let started = Instant::now();
    let outcome = match kind {
        Kind::Additional => additional(ctx, stop).await,
        Kind::Nfo => nfo(ctx, stop).await,
        Kind::Movies => nntmux_metadata::process_movie_buckets(ctx, renamed_only).await,
        Kind::Tv => {
            let renamed = if renamed_only {
                Renamed::Only
            } else {
                Renamed::Configured
            };
            nntmux_metadata::process_tv(ctx, 0, "", renamed).await
        }
        Kind::Anime | Kind::Books | Kind::Music | Kind::Console | Kind::Games | Kind::Amazon => {
            unbucketed(ctx, kind).await
        }
    };
    if outcome.is_ok() && echo_cli() {
        println!(
            "Multi-processing for {} finished in {} seconds at {}.",
            kind.task_name(),
            started.elapsed().as_secs(),
            chrono::Local::now().to_rfc2822(),
        );
    }
    outcome
}

/// `PostProcessService`: one kind in a single pass, without bucket workers. TV dispatches over
/// its buckets, as PHP did when no bucket was given.
pub async fn update(ctx: &Ctx, kind: Kind) -> Result<()> {
    match kind {
        Kind::Additional => {
            let mut services = commands::services(ctx).await?;
            // A worker token of its own keeps this pass's workspace apart from bucket workers'.
            let token = candidates::new_token();
            let result = orchestrator::start(&mut services, "", 0, &token, &[]).await?;
            if let Some(error) = result.setup_error {
                bail!("Additional post-processing skipped: {error}");
            }
            Ok(())
        }
        Kind::Nfo => {
            commands::nfo_service(ctx)
                .await?
                .process_nfo_files(None, "")
                .await?;
            Ok(())
        }
        Kind::Movies => nntmux_metadata::process_movies(ctx, 0, "", Renamed::Configured).await,
        Kind::Tv => nntmux_metadata::process_tv(ctx, 0, "", Renamed::Configured).await,
        Kind::Anime | Kind::Books | Kind::Music | Kind::Console | Kind::Games | Kind::Amazon => {
            unbucketed(ctx, kind).await
        }
    }
}

/// The kinds `update all` and the role run, in `processAll`'s order.
pub const IN_SCOPE: [Kind; 4] = [Kind::Additional, Kind::Nfo, Kind::Movies, Kind::Tv];

/// `processAll`, for the kinds in scope.
pub async fn update_all(ctx: &Ctx) -> Result<()> {
    for kind in IN_SCOPE {
        update(ctx, kind).await?;
    }
    Ok(())
}

/// The postprocess role: additional, NFO, movie and TV passes over every bucket, round after
/// round, until stopped. A clean stop lets the running pass finish.
pub async fn run(ctx: Ctx, args: RoleArgs, stop: Shutdown) -> Result<()> {
    let idle = Duration::from_secs(args.idle_seconds);
    while !stop.requested() {
        let mut failed = None;
        for kind in IN_SCOPE {
            if stop.requested() {
                break;
            }
            let busy = match nntmux_runtime::busy::enter(&ctx, "postprocess").await {
                Ok(Some(busy)) => busy,
                Ok(None) => break,
                Err(failure) => {
                    eprintln!("can't read the pause flag: {failure:#}");
                    break;
                }
            };
            if let Err(failure) = multiprocess(&ctx, kind, false, &stop).await {
                eprintln!("{} failed: {failure:#}", kind.task_name());
                failed = Some(failure);
            }
            drop(busy);
        }
        if args.once {
            return failed.map_or(Ok(()), Err);
        }
        stop.sleep(idle).await;
    }
    Ok(())
}

/// `PostProcessRunner::processAdditional`.
async fn additional(ctx: &Ctx, stop: &Shutdown) -> Result<()> {
    let config = Config::load(&ctx.db).await?;
    let counts = candidates::available_bucket_counts(&ctx.db, &Scope::new(&config)).await?;
    let threads = config.settings.post_threads;
    let queue = additional_queue(&counts, threads, config.settings.query_limit());
    let chars: Vec<String> = counts.into_iter().map(|(bucket, _)| bucket).collect();
    if let Err(error) = prepare_temp_buckets(&config, &chars) {
        eprintln!("Additional post-processing skipped: {error:#}");
        return Ok(());
    }
    let ctx = ctx.clone();
    let worker_stop = stop.clone();
    run_buckets(queue, threads, stop, move |bucket| {
        let (ctx, stop) = (ctx.clone(), worker_stop.clone());
        async move {
            commands::additional_bucket(
                &ctx,
                &GuidOptions {
                    kind: "additional".to_owned(),
                    guid: bucket,
                    renamed: None,
                    worker: true,
                    max_batches: ADDITIONAL_WORKER_MAX_BATCHES,
                    profile: false,
                },
                &stop,
            )
            .await
        }
    })
    .await
}

/// `PostProcessRunner::processNfo`.
async fn nfo(ctx: &Ctx, stop: &Shutdown) -> Result<()> {
    let config = Config::load(&ctx.db).await?;
    if !config.settings.lookup_nfo {
        return Ok(());
    }
    let buckets = commands::nfo_service(ctx).await?.buckets().await?;
    let ctx = ctx.clone();
    run_buckets(buckets, config.settings.nfo_threads, stop, move |bucket| {
        let ctx = ctx.clone();
        async move {
            commands::nfo_service(&ctx)
                .await?
                .process_nfo_files(None, &bucket)
                .await?;
            Ok(())
        }
    })
    .await
}

/// The types whose providers have no bucketed runner here: one pass over every release.
async fn unbucketed(ctx: &Ctx, kind: Kind) -> Result<()> {
    let renamed = Renamed::Configured;
    match kind {
        Kind::Anime => nntmux_metadata::process_anime(ctx, 0, "", renamed).await,
        Kind::Books => nntmux_metadata::process_books(ctx, 0, "", renamed).await,
        Kind::Music => nntmux_metadata::process_music(ctx, 0, "", renamed).await,
        Kind::Console => nntmux_metadata::process_consoles(ctx, 0, "", renamed).await,
        Kind::Games => nntmux_metadata::process_games(ctx, 0, "", renamed).await,
        Kind::Amazon => {
            nntmux_metadata::process_books(ctx, 0, "", renamed).await?;
            nntmux_metadata::process_music(ctx, 0, "", renamed).await?;
            nntmux_metadata::process_consoles(ctx, 0, "", renamed).await?;
            nntmux_metadata::process_games(ctx, 0, "", renamed).await
        }
        Kind::Additional | Kind::Nfo | Kind::Movies | Kind::Tv => {
            bail!("{kind:?} runs over its buckets")
        }
    }
}

/// `additionalQueue`: one entry per bucket with work, then more for the buckets with the most
/// claimable batches left, until the threads are used or the demand is met. A hot bucket is
/// split across several workers that way.
pub fn additional_queue(counts: &[(String, i64)], threads: i64, query_limit: i64) -> Vec<String> {
    let mut queue: Vec<String> = counts.iter().map(|(bucket, _)| bucket.clone()).collect();
    if queue.is_empty() {
        return queue;
    }
    let mut allocations = vec![1i64; counts.len()];
    let demand: Vec<i64> = counts
        .iter()
        .map(|(_, count)| (count.max(&0) + query_limit.max(1) - 1) / query_limit.max(1))
        .map(|batches| batches.max(1))
        .collect();
    let total_demand: i64 = demand.iter().sum();
    let target = usize::try_from(threads.max(1).min(total_demand))
        .unwrap_or(0)
        .max(queue.len());
    while queue.len() < target {
        let mut next = None;
        let mut largest = 0;
        for (index, wanted) in demand.iter().enumerate() {
            let remaining = wanted - allocations[index];
            if remaining > largest {
                largest = remaining;
                next = Some(index);
            }
        }
        let Some(index) = next else {
            break;
        };
        queue.push(counts[index].0.clone());
        allocations[index] += 1;
    }
    queue
}

/// `prepareAdditionalTempBuckets`: each bucket's directory exists and is writable, and worker
/// directories older than twice the child time limit (an hour at least) are gone.
fn prepare_temp_buckets(config: &Config, chars: &[String]) -> Result<()> {
    let stale_after = (config.worker_child_time_seconds * 2).max(3600);
    for bucket in chars {
        let path = workspace::ensure_main_temp_path(&config.tmp_unrar_path, bucket, "", "")?;
        workspace::prune_stale_worker_directories(&path, stale_after);
    }
    Ok(())
}

/// Runs `job` for each bucket, at most `threads` at a time, and waits for all of them. A failed
/// bucket doesn't stop the others; the pass fails afterwards, naming each failure. Once `stop` is
/// requested, buckets not yet started are left for the next run.
async fn run_buckets<F, Fut>(
    buckets: Vec<String>,
    threads: i64,
    stop: &Shutdown,
    job: F,
) -> Result<()>
where
    F: Fn(String) -> Fut,
    Fut: Future<Output = Result<()>> + Send + 'static,
{
    if buckets.is_empty() {
        return Ok(());
    }
    let permits = Arc::new(Semaphore::new(usize::try_from(threads.max(1)).unwrap_or(1)));
    let mut running = JoinSet::new();
    for bucket in buckets {
        let permit = Arc::clone(&permits).acquire_owned().await?;
        if stop.requested() {
            break;
        }
        let work = job(bucket.clone());
        running.spawn(async move {
            let outcome = work.await;
            drop(permit);
            (bucket, outcome)
        });
    }
    let mut failures = Vec::new();
    while let Some(joined) = running.join_next().await {
        match joined {
            Ok((_, Ok(()))) => {}
            Ok((bucket, Err(failure))) => failures.push(format!("bucket {bucket}: {failure:#}")),
            Err(failure) => failures.push(format!("a bucket worker stopped: {failure}")),
        }
    }
    if failures.is_empty() {
        Ok(())
    } else {
        bail!("Worker batch failed: {}", failures.join("; "))
    }
}

/// `nntmux.echocli`, read as the release-file settings read it.
fn echo_cli() -> bool {
    std::env::var("ECHOCLI").map_or(true, |value| !matches!(value.as_str(), "false" | "0" | ""))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn counts(entries: &[(&str, i64)]) -> Vec<(String, i64)> {
        entries
            .iter()
            .map(|(bucket, count)| ((*bucket).to_owned(), *count))
            .collect()
    }

    #[test]
    fn a_hot_bucket_gets_the_spare_threads() {
        let queue = additional_queue(&counts(&[("a", 100), ("b", 10)]), 4, 25);
        assert_eq!(queue, ["a", "b", "a", "a"]);
    }

    #[test]
    fn the_queue_never_exceeds_the_demand_or_drops_a_bucket() {
        assert_eq!(
            additional_queue(&counts(&[("a", 5), ("b", 5)]), 8, 25),
            ["a", "b"]
        );
        assert_eq!(
            additional_queue(&counts(&[("a", 100), ("b", 10), ("c", 1)]), 1, 25),
            ["a", "b", "c"]
        );
        assert!(additional_queue(&[], 4, 25).is_empty());
    }

    #[tokio::test]
    async fn buckets_run_within_the_thread_limit_and_failures_are_collected() {
        use std::sync::atomic::{AtomicUsize, Ordering};
        let running = Arc::new(AtomicUsize::new(0));
        let peak = Arc::new(AtomicUsize::new(0));
        let buckets = ["a", "b", "c", "d", "e"].map(str::to_owned).to_vec();
        let outcome = run_buckets(buckets, 2, &Shutdown::default(), |bucket| {
            let (running, peak) = (Arc::clone(&running), Arc::clone(&peak));
            async move {
                let now = running.fetch_add(1, Ordering::SeqCst) + 1;
                peak.fetch_max(now, Ordering::SeqCst);
                tokio::time::sleep(Duration::from_millis(20)).await;
                running.fetch_sub(1, Ordering::SeqCst);
                if bucket == "c" {
                    bail!("no news server")
                }
                Ok(())
            }
        })
        .await;
        assert_eq!(peak.load(Ordering::SeqCst), 2);
        let message = format!("{:#}", outcome.unwrap_err());
        assert!(message.contains("bucket c: no news server"), "{message}");
    }

    #[tokio::test]
    async fn no_bucket_starts_after_a_stop() {
        use std::sync::atomic::{AtomicUsize, Ordering};
        let started = Arc::new(AtomicUsize::new(0));
        let stop = Shutdown::default();
        let buckets = ["a", "b", "c", "d"].map(str::to_owned).to_vec();
        let outcome = run_buckets(buckets, 1, &stop, |_| {
            let (started, stop) = (Arc::clone(&started), stop.clone());
            async move {
                started.fetch_add(1, Ordering::SeqCst);
                // A stop arrives while the first bucket runs; it still finishes.
                stop.request();
                Ok(())
            }
        })
        .await;
        assert!(outcome.is_ok());
        assert_eq!(started.load(Ordering::SeqCst), 1);
    }

    #[test]
    fn type_names_follow_both_commands() {
        assert_eq!(Kind::from_short("add"), Some(Kind::Additional));
        assert_eq!(Kind::from_short("additional"), None);
        assert_eq!(Kind::from_long("book"), Some(Kind::Books));
        assert_eq!(Kind::from_long("boo"), None);
        assert_eq!(Kind::from_long("all"), None);
    }
}
