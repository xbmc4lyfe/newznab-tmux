//! Additional processing, NFO, PAR2 and media.
//!
//! Owner: ws-postprocess. See `rust/WORKSTREAMS.md`.

pub mod additional;
pub mod archive_info;
pub mod config;
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
