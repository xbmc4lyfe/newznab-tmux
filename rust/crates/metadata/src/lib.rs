//! Metadata providers: TV, movies, music, games, books and anime.
//!
//! Owner: ws-metadata. See `rust/WORKSTREAMS.md`.

pub mod cache;
pub mod candidates;
pub mod clients;
pub mod http;
pub mod images;
pub mod php;
pub mod services;
pub mod settings;
pub mod tv;

use anyhow::{Result, bail};
use clap::Subcommand;
use nntmux_runtime::Ctx;

pub use candidates::{book_work_condition, imdb_needs_lookup_sql, tv_work_condition};
pub use settings::{LookupMode, MetadataSettings, Renamed};
pub use tv::commands::{
    TvMissingEpisodesOptions, TvRepairLinksOptions, TvUnmatchedOptions, tv_repair_links,
    tv_reprocess_missing_episodes,
};

/// `nntmux-ctl metadata ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// `tv:repair-links`: repair TV release links and lookup states left by the legacy processor.
    TvRepairLinks(TvRepairLinksOptions),
    /// `tv:reprocess`: reprocess one TV release by GUID.
    TvReprocess {
        /// The release GUID to reprocess.
        guid: String,
        /// Reset the release's video and episode IDs before reprocessing.
        #[arg(long)]
        reset: bool,
    },
    /// `tv:reprocess-missing-episodes`: retry releases whose show matched but episode was missing.
    TvReprocessMissingEpisodes(TvMissingEpisodesOptions),
    /// `tv:reprocess-unmatched`: reprocess TV releases that aren't matched to any show.
    TvReprocessUnmatched(TvUnmatchedOptions),
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    match command {
        Command::TvRepairLinks(options) => tv_repair_links(&ctx, options).await,
        Command::TvReprocess { guid, reset } => {
            tv::commands::reprocess_one(&ctx, &guid, reset).await
        }
        Command::TvReprocessMissingEpisodes(options) => {
            tv_reprocess_missing_episodes(&ctx, options).await
        }
        Command::TvReprocessUnmatched(options) => {
            tv::commands::tv_reprocess_unmatched(&ctx, options).await
        }
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
pub async fn process_tv(ctx: &Ctx, group_id: i64, guid_char: &str, renamed: Renamed) -> Result<()> {
    if !guid_char.is_empty() {
        return process_tv_pipeline(ctx, group_id, guid_char, renamed).await;
    }
    let services = services::Services::shared(ctx)?;
    let settings = MetadataSettings::load(&services.db).await?;
    tv::dispatch_buckets(services, &settings, renamed).await
}

pub async fn process_tv_pipeline(
    ctx: &Ctx,
    group_id: i64,
    guid_char: &str,
    renamed: Renamed,
) -> Result<()> {
    tv::run_bucket(ctx, group_id, guid_char, renamed).await
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
