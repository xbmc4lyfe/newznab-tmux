//! Manticore indexing and search reads.
//!
//! Owner: ws-search. See `rust/WORKSTREAMS.md`.
//!
//! [`SearchClient`] is what other crates call. Its reads return an error when Manticore fails
//! or a call isn't ported yet. An empty result always means the query really matched nothing.

mod cache;
pub mod config;
mod cursor;
mod document;
mod error;
mod index;
mod manticore;
pub mod query;
mod read;
mod registry;
mod release_query;
mod writes;

use anyhow::{Result, bail};
use clap::Subcommand;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};

pub use config::SearchConfig;
pub use cursor::{CursorCodec, SearchCursor};
pub use document::{ReleaseDocument, ReleaseRow};
pub use error::SearchError;
pub use index::{BulkResult, DEFERRED_OPERATION, DeferredUpdates};
pub use manticore::SearchClient;
pub use read::{
    ExternalId, FuzzyIds, IndexHits, MovieExternalIds, MoviesByFields, PredbHit, ReleaseCandidates,
    Suggestion,
};
pub use registry::{SecondaryIndex, create_table_sql};
pub use release_query::{
    Bounds, Phrases, Ranges, ReleaseSearchQuery, SearchPage, SortDirection, SortField,
};
pub use writes::{MovieDocument, TvShowDocument};

pub async fn run_sync(_ctx: Ctx, _args: RoleArgs, _stop: Shutdown) -> Result<()> {
    bail!("the search-sync role isn't ported yet")
}

/// `nntmux-ctl search ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Reports that nothing is ported here yet. Replace this as commands arrive.
    Status,
}

pub async fn ctl(_ctx: Ctx, command: Command) -> Result<()> {
    match command {
        Command::Status => bail!("no search commands are ported yet"),
    }
}
