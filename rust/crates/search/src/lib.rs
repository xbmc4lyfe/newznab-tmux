//! Manticore indexing and search reads.
//!
//! Owner: ws-search. See `rust/WORKSTREAMS.md`.
//!
//! [`SearchClient`] is what other crates call. Its reads return an error when Manticore fails
//! or a call isn't ported yet. An empty result always means the query really matched nothing.
//!
//! Callers stay engine-neutral: the public API takes and returns search types, so changing the
//! engine stays a backend change. Items marked `doc(hidden)` exist for this crate's tests.

mod cache;
mod commands;
pub mod config;
mod cursor;
mod document;
mod error;
mod index;
mod inspect;
mod manticore;
pub mod query;
mod read;
mod reconcile;
mod registry;
mod release_query;
mod repair;
mod writes;

use std::future::Future;
use std::time::Duration;

use anyhow::Result;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};
use sqlx::MySqlPool;
use tracing::{info, warn};

/// What the PHP scheduler passed to `nntmux:search-repair` each minute.
const SYNC_BATCH: u32 = 100;

pub use commands::{Command, run as run_command};

pub use config::SearchConfig;
pub use cursor::{CursorCodec, SearchCursor};
pub use document::{ReleaseDocument, ReleaseRow};
pub use error::SearchError;
pub use index::{BulkResult, DEFERRED_OPERATION, DeferredUpdates};
pub use inspect::{InspectReport, TableReport};
pub use manticore::SearchClient;
pub use read::{
    ExternalId, FuzzyIds, IndexHits, MovieExternalIds, MoviesByFields, PredbHit, ReleaseCandidates,
    Suggestion,
};
pub use reconcile::{PredbReconcileReport, ReconcileOptions, ReconcileReport, parse_since};
pub use registry::SecondaryIndex;
#[doc(hidden)]
pub use registry::create_table_sql;
pub use release_query::{
    Bounds, Phrases, Ranges, ReleaseSearchQuery, SearchPage, SortDirection, SortField,
};
pub use repair::RepairReport;
pub use writes::{MovieDocument, TvShowDocument};

/// Retries due index failures until told to stop. Holds off while processing is paused.
pub async fn run_sync(ctx: Ctx, args: RoleArgs, stop: Shutdown) -> Result<()> {
    let client = SearchClient::from_ctx(&ctx)?;
    sync_loop(&client, &ctx.db, || ctx.paused(), &args, &stop).await
}

/// A database or Manticore failure waits for the next pass, since the failure rows are durable
/// and the old schedule simply ran again a minute later. With `--once` it's the exit code.
#[doc(hidden)]
pub async fn sync_loop<P, F>(
    client: &SearchClient,
    db: &MySqlPool,
    paused: P,
    args: &RoleArgs,
    stop: &Shutdown,
) -> Result<()>
where
    P: Fn() -> F,
    F: Future<Output = Result<bool>>,
{
    let idle = Duration::from_secs(args.idle_seconds);
    while !stop.requested() {
        let pass = sync_pass(client, db, paused().await).await;
        if args.once {
            return pass;
        }
        if let Err(error) = pass {
            warn!(error = %format!("{error:#}"), "search repair failed");
        }
        stop.sleep(idle).await;
    }
    Ok(())
}

async fn sync_pass(client: &SearchClient, db: &MySqlPool, paused: Result<bool>) -> Result<()> {
    // Unknown state: claim nothing until the database answers.
    if paused? {
        return Ok(());
    }
    let report = client.repair(db, SYNC_BATCH).await?;
    if report.due > 0 {
        info!(
            due = report.due,
            repaired = report.repaired,
            "repaired the search index"
        );
    }
    Ok(())
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    let client = SearchClient::from_ctx(&ctx)?;
    commands::run(&client, &ctx.db, command).await
}
