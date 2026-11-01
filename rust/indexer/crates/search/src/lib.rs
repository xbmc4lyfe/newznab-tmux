//! Text search behind a trait, so engines stay cheap to swap.
//!
//! Search answers the Newznab `q` parameter: every word is required, and results come newest
//! first. Tantivy won the Phase 1 benchmark (`tools/search-bench`) on latency and index size, and
//! it runs in process, so a query makes no network call.

mod normalize;
mod tantivy_index;

use anyhow::Result;

pub use normalize::{ParsedQuery, index_words, parse_query};
pub use tantivy_index::{TantivyIndex, TantivyWriter};

/// What the index needs to know about a release. The catalog holds everything else.
#[derive(Clone, Debug)]
pub struct IndexedRelease {
    /// The catalog's release ID.
    pub id: i64,
    pub name: String,
    pub category: u32,
    pub size_bytes: u64,
    /// Unix seconds. Releases with no time, or one before 1970, sort last.
    pub posted_at: Option<i64>,
}

/// One page of a text search.
#[derive(Clone, Debug, Default)]
pub struct Query {
    /// The words to find. `-word`, `--word` and `!word` exclude a word. With no words to find,
    /// the query matches every release, as a Newznab search without `q` does.
    pub text: String,
    /// Inclusive category ranges, any of which may match. Empty means every category.
    pub categories: Vec<(u32, u32)>,
    pub min_size_bytes: Option<u64>,
    pub offset: usize,
    /// How many IDs to return. Zero still reports the total.
    pub limit: usize,
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Page {
    /// Release IDs, newest first.
    pub ids: Vec<i64>,
    /// All matches, not just this page.
    pub total: u64,
}

/// Reads from a text index. A query takes well under a millisecond, but it does block.
pub trait TextIndex: Send + Sync {
    fn search(&self, query: &Query) -> Result<Page>;

    /// The catalog position passed to the last commit, or `None` for an index never committed.
    /// After a restart, publish everything past this position to catch up.
    fn watermark(&self) -> Result<Option<u64>>;
}

/// Writes to a text index. Nothing is searchable until `commit`.
pub trait TextIndexWriter: Send {
    /// Adds releases, replacing any already indexed under the same ID.
    fn upsert(&mut self, releases: &[IndexedRelease]) -> Result<()>;

    fn remove(&mut self, ids: &[i64]) -> Result<()>;

    /// Makes changes durable and searchable, and records how far through the catalog they reach.
    fn commit(&mut self, watermark: u64) -> Result<()>;
}
