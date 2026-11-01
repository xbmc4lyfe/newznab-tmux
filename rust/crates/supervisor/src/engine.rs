//! Runs tasks on their cadence. A task never overlaps itself, and one that fails or panics is
//! logged and counted and tries again at its next time.

use std::panic::AssertUnwindSafe;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::Instant;

use anyhow::{Result, anyhow};
use chrono::{DateTime, Utc};
use futures_util::FutureExt;
use futures_util::future::BoxFuture;
use tokio::task::JoinSet;
use tracing::{debug, error, info};

use crate::cadence::Cadence;

/// One schedule entry, generic over its context so the engine can be tested without services.
pub struct Task<C> {
    pub name: &'static str,
    pub cadence: Cadence,
    /// Read every time the task is due, like Laravel's `when()`.
    pub enabled: fn() -> bool,
    pub run: fn(C) -> BoxFuture<'static, Result<()>>,
}

struct Slot<C> {
    task: Task<C>,
    running: Arc<AtomicBool>,
}

/// Clears the running flag however the task ends.
struct Running(Arc<AtomicBool>);

impl Drop for Running {
    fn drop(&mut self) {
        self.0.store(false, Ordering::Release);
    }
}

pub struct Engine<C> {
    context: C,
    slots: Vec<Slot<C>>,
    tasks: JoinSet<()>,
}

impl<C: Clone + Send + 'static> Engine<C> {
    pub fn new(context: C, tasks: Vec<Task<C>>) -> Self {
        let slots = tasks
            .into_iter()
            .map(|task| Slot {
                task,
                running: Arc::new(AtomicBool::new(false)),
            })
            .collect();
        Self {
            context,
            slots,
            tasks: JoinSet::new(),
        }
    }

    /// Starts everything due at this minute that isn't still running.
    pub fn tick(&mut self, at: DateTime<Utc>) {
        while self.tasks.try_join_next().is_some() {}
        for slot in &self.slots {
            if !slot.task.cadence.due(at) || !(slot.task.enabled)() {
                continue;
            }
            if slot.running.swap(true, Ordering::AcqRel) {
                debug!(task = slot.task.name, "still running, skipping this time");
                metrics::counter!("nntmux_schedule_skipped_total", "task" => slot.task.name)
                    .increment(1);
                continue;
            }
            let running = Running(slot.running.clone());
            let name = slot.task.name;
            let work = (slot.task.run)(self.context.clone());
            self.tasks.spawn(async move {
                let _running = running;
                run_one(name, work).await;
            });
        }
    }

    /// Waits for what's in flight. Each task owns its stop handling.
    pub async fn drain(&mut self) {
        while self.tasks.join_next().await.is_some() {}
    }
}

async fn run_one(name: &'static str, work: BoxFuture<'static, Result<()>>) {
    let started = Instant::now();
    info!(task = name, "scheduled task starting");
    let outcome = AssertUnwindSafe(work)
        .catch_unwind()
        .await
        .unwrap_or_else(|_| Err(anyhow!("the task panicked")));
    let seconds = started.elapsed().as_secs_f64();
    metrics::histogram!("nntmux_schedule_task_seconds", "task" => name).record(seconds);
    match outcome {
        Ok(()) => {
            metrics::counter!("nntmux_schedule_runs_total", "task" => name, "outcome" => "ok")
                .increment(1);
            info!(task = name, seconds, "scheduled task finished");
        }
        Err(failure) => {
            metrics::counter!("nntmux_schedule_runs_total", "task" => name, "outcome" => "error")
                .increment(1);
            error!(
                task = name,
                seconds,
                error = format!("{failure:#}"),
                "scheduled task failed"
            );
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use chrono::TimeZone;
    use std::sync::atomic::AtomicUsize;
    use tokio::sync::Notify;

    #[derive(Clone, Default)]
    struct Probe {
        starts: Arc<AtomicUsize>,
        release: Arc<Notify>,
    }

    fn minute(minute: u32) -> DateTime<Utc> {
        Utc.with_ymd_and_hms(2026, 11, 2, 3, minute, 0)
            .single()
            .expect("valid date")
    }

    fn task(run: fn(Probe) -> BoxFuture<'static, Result<()>>) -> Task<Probe> {
        Task {
            name: "probe",
            cadence: Cadence::EveryMinutes(1),
            enabled: || true,
            run,
        }
    }

    async fn settle(probe: &Probe, starts: usize) {
        for _ in 0..1000 {
            if probe.starts.load(Ordering::SeqCst) == starts {
                return;
            }
            tokio::task::yield_now().await;
        }
        assert_eq!(probe.starts.load(Ordering::SeqCst), starts);
    }

    #[tokio::test]
    async fn a_running_task_is_not_started_again() {
        let probe = Probe::default();
        let mut engine = Engine::new(
            probe.clone(),
            vec![task(|probe| {
                Box::pin(async move {
                    probe.starts.fetch_add(1, Ordering::SeqCst);
                    probe.release.notified().await;
                    Ok(())
                })
            })],
        );
        engine.tick(minute(0));
        settle(&probe, 1).await;
        engine.tick(minute(1));
        tokio::task::yield_now().await;
        assert_eq!(probe.starts.load(Ordering::SeqCst), 1);
        probe.release.notify_one();
        engine.drain().await;
        engine.tick(minute(2));
        settle(&probe, 2).await;
        probe.release.notify_one();
        engine.drain().await;
    }

    #[tokio::test]
    async fn failures_and_panics_free_the_task() {
        for run in [
            (|probe: Probe| {
                Box::pin(async move {
                    probe.starts.fetch_add(1, Ordering::SeqCst);
                    Err(anyhow!("no"))
                }) as BoxFuture<'static, Result<()>>
            }) as fn(Probe) -> BoxFuture<'static, Result<()>>,
            |probe: Probe| {
                Box::pin(async move {
                    probe.starts.fetch_add(1, Ordering::SeqCst);
                    panic!("boom")
                })
            },
        ] {
            let probe = Probe::default();
            let mut engine = Engine::new(probe.clone(), vec![task(run)]);
            engine.tick(minute(0));
            engine.drain().await;
            engine.tick(minute(1));
            engine.drain().await;
            assert_eq!(probe.starts.load(Ordering::SeqCst), 2);
        }
    }

    #[tokio::test]
    async fn disabled_and_not_due_tasks_stay_idle() {
        let probe = Probe::default();
        let count: fn(Probe) -> BoxFuture<'static, Result<()>> = |probe| {
            Box::pin(async move {
                probe.starts.fetch_add(1, Ordering::SeqCst);
                Ok(())
            })
        };
        let mut engine = Engine::new(
            probe.clone(),
            vec![
                Task {
                    enabled: || false,
                    ..task(count)
                },
                Task {
                    cadence: Cadence::Hourly,
                    ..task(count)
                },
            ],
        );
        engine.tick(minute(5));
        engine.drain().await;
        assert_eq!(probe.starts.load(Ordering::SeqCst), 0);
        engine.tick(minute(0));
        engine.drain().await;
        assert_eq!(probe.starts.load(Ordering::SeqCst), 1);
    }
}
