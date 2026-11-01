//! Metadata providers: TV, movies, music, games, books and anime.
//!
//! Owner: ws-metadata. See `rust/WORKSTREAMS.md`.

pub mod candidates;
pub mod settings;

use anyhow::{Result, bail};
use clap::Subcommand;
use nntmux_runtime::Ctx;

pub use candidates::{book_work_condition, imdb_needs_lookup_sql, tv_work_condition};
pub use settings::{LookupMode, MetadataSettings, Renamed};

/// `nntmux-ctl metadata ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Reports that nothing is ported here yet. Replace this as commands arrive.
    Status,
}

pub async fn ctl(_ctx: Ctx, command: Command) -> Result<()> {
    match command {
        Command::Status => bail!("no metadata commands are ported yet"),
    }
}

// The entry points ws-postprocess calls, one per type, for one GUID bucket. A `group_id` of 0 and
// an empty `guid_char` mean no filter. They fail until each provider lands, so nothing silently
// skips a lookup.

pub async fn process_movies(
    _ctx: &Ctx,
    _group_id: i64,
    _guid_char: &str,
    _renamed: Renamed,
) -> Result<()> {
    bail!("movie lookups aren't ported yet")
}

/// PHP's `processTv`: with no bucket it dispatches over the buckets, otherwise it runs
/// [`process_tv_pipeline`] on that bucket.
pub async fn process_tv(
    _ctx: &Ctx,
    _group_id: i64,
    _guid_char: &str,
    _renamed: Renamed,
) -> Result<()> {
    bail!("TV lookups aren't ported yet")
}

pub async fn process_tv_pipeline(
    _ctx: &Ctx,
    _group_id: i64,
    _guid_char: &str,
    _renamed: Renamed,
) -> Result<()> {
    bail!("TV lookups aren't ported yet")
}

pub async fn process_anime(
    _ctx: &Ctx,
    _group_id: i64,
    _guid_char: &str,
    _renamed: Renamed,
) -> Result<()> {
    bail!("anime lookups aren't ported yet")
}

pub async fn process_books(
    _ctx: &Ctx,
    _group_id: i64,
    _guid_char: &str,
    _renamed: Renamed,
) -> Result<()> {
    bail!("book lookups aren't ported yet")
}

pub async fn process_music(
    _ctx: &Ctx,
    _group_id: i64,
    _guid_char: &str,
    _renamed: Renamed,
) -> Result<()> {
    bail!("music lookups aren't ported yet")
}

pub async fn process_consoles(
    _ctx: &Ctx,
    _group_id: i64,
    _guid_char: &str,
    _renamed: Renamed,
) -> Result<()> {
    bail!("console lookups aren't ported yet")
}

pub async fn process_games(
    _ctx: &Ctx,
    _group_id: i64,
    _guid_char: &str,
    _renamed: Renamed,
) -> Result<()> {
    bail!("game lookups aren't ported yet")
}

/// `tv:repair-links`.
pub async fn tv_repair_links(_ctx: &Ctx, _options: TvRepairLinksOptions) -> Result<()> {
    bail!("tv:repair-links isn't ported yet")
}

/// `tv:reprocess-missing-episodes`.
pub async fn tv_reprocess_missing_episodes(
    _ctx: &Ctx,
    _options: TvMissingEpisodesOptions,
) -> Result<()> {
    bail!("tv:reprocess-missing-episodes isn't ported yet")
}

/// Options for [`tv_repair_links`]. The fields arrive with the port.
#[derive(Clone, Debug, Default)]
#[non_exhaustive]
pub struct TvRepairLinksOptions {}

/// Options for [`tv_reprocess_missing_episodes`]. The fields arrive with the port.
#[derive(Clone, Debug, Default)]
#[non_exhaustive]
pub struct TvMissingEpisodesOptions {}
