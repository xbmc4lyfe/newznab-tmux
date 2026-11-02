//! Additional processing, NFO, PAR2 and media.
//!
//! Owner: ws-postprocess. See `rust/WORKSTREAMS.md`.

pub mod additional;
pub mod archive_info;
pub mod commands;
pub mod config;
pub mod media;
pub mod mediainfo;
pub mod nfo;
pub mod tv_pipeline;
pub mod workspace;

use anyhow::{Result, bail};
use clap::Subcommand;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};

use crate::config::Config;

pub async fn run(_ctx: Ctx, _args: RoleArgs, _stop: Shutdown) -> Result<()> {
    bail!("the postprocess role isn't ported yet")
}

/// `nntmux-ctl postprocess ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Inspect the additional post-processing backlog, claims, capacity, indexes and temp path.
    AdditionalDiagnose {
        /// Output machine-readable JSON.
        #[arg(long)]
        json: bool,
    },
    /// `postprocess:tv-pipeline`: post process TV releases of one GUID bucket through the TV
    /// provider pipeline.
    TvPipeline {
        /// First character of the release GUID (a-f, 0-9).
        guid: String,
        /// 1 takes every release, 2 renamed releases only; empty uses the configured mode.
        renamed: Option<String>,
        /// Deprecated and ignored; every run uses the provider pipeline.
        #[arg(long)]
        mode: Option<String>,
    },
    /// `releases:additional`: run additional post-processing on one release, by GUID or ID,
    /// whatever its processing state.
    Additional {
        /// The release GUID; or give --id.
        guid: Option<String>,
        /// The numeric release ID, instead of the GUID.
        #[arg(long)]
        id: Option<String>,
        /// Reset the release's additional-processing flags first.
        #[arg(long)]
        reset: bool,
        /// Accepted for compatibility; the output is already quiet.
        #[arg(long)]
        no_progress: bool,
        /// Show the stage and download profile.
        #[arg(long)]
        profile: bool,
    },
    /// `postprocess:guid`: one kind of post-processing for one GUID bucket.
    Guid {
        /// additional, nfo, movie, tv, anime, books, music, console or games.
        #[arg(value_name = "TYPE")]
        kind: String,
        /// First character of the release GUID (a-f, 0-9).
        guid: String,
        /// For movie and tv: 2 for renamed releases only.
        renamed: Option<String>,
        /// Drain several additional-processing batches.
        #[arg(long)]
        worker: bool,
        /// Most claim batches in worker mode.
        #[arg(long, default_value_t = 4)]
        max_batches: i64,
        /// Print one JSON performance record per additional batch.
        #[arg(long)]
        profile: bool,
    },
    /// `releases:fetch-samples`: reset and reprocess releases of the given categories that have
    /// no sample picture.
    FetchSamples {
        /// Category ID, or IDs separated by commas or spaces.
        #[arg(long)]
        category: Option<String>,
        /// Most releases to process; 0 for all.
        #[arg(long, default_value_t = 0)]
        limit: i64,
        /// Releases read per query.
        #[arg(long, default_value_t = 500)]
        chunk: i64,
        /// List the GUIDs that would be processed, and stop.
        #[arg(long)]
        dry_run: bool,
        /// Show each release's outcome.
        #[arg(long)]
        show_output: bool,
    },
    /// `update:postprocess nfo`: one pass of NFO processing, then the archive pass for releases
    /// out of retries.
    Nfo {
        /// Only releases whose GUID starts with this character.
        #[arg(long, default_value = "")]
        guid: String,
        /// Only releases in this group.
        #[arg(long)]
        group: Option<i64>,
    },
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    match command {
        Command::AdditionalDiagnose { json } => {
            let config = Config::load(&ctx.db).await?;
            let report = additional::diagnose::inspect(&ctx.db, &config).await?;
            if json {
                println!("{}", serde_json::to_string_pretty(&report)?);
            } else {
                print!("{}", additional::diagnose::render(&report));
            }
            Ok(())
        }
        Command::TvPipeline {
            guid,
            renamed,
            mode,
        } => {
            tv_pipeline::check_guid_char(&guid)?;
            if mode.is_some() {
                eprintln!(
                    "The --mode option is deprecated and ignored; every run uses the provider pipeline."
                );
            }
            let Some(renamed) = tv_pipeline::renamed_argument(renamed.as_deref()) else {
                return Ok(());
            };
            nntmux_metadata::process_tv_pipeline(&ctx, 0, &guid, renamed).await
        }
        Command::Additional {
            guid,
            id,
            reset,
            no_progress: _,
            profile,
        } => {
            let guid = guid
                .map(|guid| guid.trim().to_owned())
                .filter(|g| !g.is_empty());
            let id = id.filter(|id| !id.is_empty());
            let target = match (guid, id) {
                (None, None) => {
                    bail!("You must supply either a GUID argument or --id=<release id>.")
                }
                (Some(_), Some(_)) => bail!("Provide only one identifier: GUID or --id, not both."),
                (Some(guid), None) => commands::Target::Guid(guid),
                (None, Some(id)) => commands::Target::Id(id),
            };
            commands::additional(&ctx, target, reset, profile).await
        }
        Command::Guid {
            kind,
            guid,
            renamed,
            worker,
            max_batches,
            profile,
        } => {
            commands::guid(
                &ctx,
                commands::GuidOptions {
                    kind,
                    guid,
                    renamed,
                    worker,
                    max_batches,
                    profile,
                },
            )
            .await
        }
        Command::FetchSamples {
            category,
            limit,
            chunk,
            dry_run,
            show_output,
        } => {
            commands::fetch_samples(
                &ctx,
                commands::FetchSamplesOptions {
                    category,
                    limit,
                    chunk,
                    dry_run,
                    show_output,
                },
            )
            .await
        }
        Command::Nfo { guid, group } => {
            if !guid.is_empty() {
                tv_pipeline::check_guid_char(&guid)?;
            }
            let config = Config::load(&ctx.db).await?;
            if !config.settings.lookup_nfo {
                println!("NFO lookup is turned off.");
                return Ok(());
            }
            let nntp = nntmux_nntp::NntpConfig::from_env();
            let settings = nfo::Settings::from_config(&config, nntp.alternate_article_fallback);
            let client = nntmux_nntp::NntpClient::new(std::sync::Arc::new(nntp));
            let mut service = nfo::NfoService::new(ctx.db.clone(), settings, client);
            service.process_nfo_files(group, &guid).await?;
            Ok(())
        }
    }
}
