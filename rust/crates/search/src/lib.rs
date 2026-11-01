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

use std::time::Duration;

use anyhow::Result;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};
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
#[doc(hidden)]
pub use registry::create_table_sql;
pub use registry::SecondaryIndex;
pub use release_query::{
    Bounds, Phrases, Ranges, ReleaseSearchQuery, SearchPage, SortDirection, SortField,
};
pub use repair::RepairReport;
pub use writes::{MovieDocument, TvShowDocument};

/// Retries due index failures until told to stop. Holds off while processing is paused.
pub async fn run_sync(ctx: Ctx, args: RoleArgs, stop: Shutdown) -> Result<()> {
    let client = SearchClient::from_ctx(&ctx)?;
    let idle = Duration::from_secs(args.idle_seconds);
    while !stop.requested() {
        if !ctx.paused().await? {
            match client.repair(&ctx.db, SYNC_BATCH).await {
                Ok(report) if report.due > 0 => {
                    info!(
                        due = report.due,
                        repaired = report.repaired,
                        "repaired the search index"
                    );
                }
                Ok(_) => {}
                Err(error) => warn!(%error, "search repair failed"),
            }
        }
        if args.once {
            break;
        }
        stop.sleep(idle).await;
    }
    Ok(())
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    let client = SearchClient::from_ctx(&ctx)?;
    commands::run(&client, &ctx.db, command).await
}
