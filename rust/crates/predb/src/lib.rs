//! PreDB feeds, the IRC scraper and identity matching.
//!
//! Owner: ws-naming. See `rust/WORKSTREAMS.md`.

/// A lazily compiled pattern that needs lookaround: `frx!("...")` or `frx!(i "...")`.
#[macro_export]
macro_rules! frx {
    (i $pattern:expr) => {{
        static RE: std::sync::LazyLock<nntmux_core::pcre::Pcre> = std::sync::LazyLock::new(|| {
            nntmux_core::pcre::Pcre::with_flags($pattern, "i").expect("static pattern")
        });
        &*RE
    }};
    ($pattern:expr) => {{
        static RE: std::sync::LazyLock<nntmux_core::pcre::Pcre> = std::sync::LazyLock::new(|| {
            nntmux_core::pcre::Pcre::with_flags($pattern, "").expect("static pattern")
        });
        &*RE
    }};
}

pub mod feeds;
pub mod filename;
pub mod identity;
pub mod index;
pub mod irc;
pub mod season;
pub mod selector;

use anyhow::{Result, bail};
use clap::Subcommand;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};
use serde_json::json;
use sqlx::MySqlPool;

use crate::filename::exact_value;

pub use crate::index::{PredbIndex, PredbMatch, match_pre};

/// The `predb` role: the IRC and WebSocket scrapers, plus the feed import and title check
/// that the Laravel scheduler ran every five and ten minutes.
pub async fn run(ctx: Ctx, _args: RoleArgs, stop: Shutdown) -> Result<()> {
    let mut networks = irc::networks::enabled();
    let scrapers = if networks.is_empty() {
        tracing::info!("no IRC networks are enabled");
        None
    } else {
        networks.sort();
        Some(tokio::spawn(irc::supervise(
            ctx.clone(),
            networks,
            stop.clone(),
        )))
    };

    let config = feeds::FeedConfig::from_env();
    // Separate loops, so a feed backing off for minutes can't starve the title check, and either
    // can stop partway when a shutdown arrives.
    let feeds = async {
        let mut timer = tokio::time::interval(std::time::Duration::from_secs(300));
        timer.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Skip);
        loop {
            tokio::select! {
                () = stop.token().cancelled_owned() => return,
                _ = timer.tick() => {}
            }
            if !config.enabled || ctx.paused().await.unwrap_or(true) {
                continue;
            }
            let args = feeds::command::ImportArgs {
                source: Vec::new(),
                pages: 1,
                since: None,
                max_pages: None,
                dry_run: false,
                quiet: true,
            };
            tokio::select! {
                () = stop.token().cancelled_owned() => return,
                result = feeds::command::run(&ctx, args) => {
                    if let Err(error) = result {
                        tracing::warn!(%error, "PreDB feed import failed");
                    }
                }
            }
        }
    };
    let checks = async {
        let mut timer = tokio::time::interval(std::time::Duration::from_secs(600));
        timer.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Skip);
        loop {
            tokio::select! {
                () = stop.token().cancelled_owned() => return,
                _ = timer.tick() => {}
            }
            if ctx.paused().await.unwrap_or(true) {
                continue;
            }
            tokio::select! {
                () = stop.token().cancelled_owned() => return,
                result = check_pre(&ctx.db, Some(3)) => {
                    if let Err(error) = result {
                        tracing::warn!(%error, "PreDB title check failed");
                    }
                }
            }
        }
    };
    tokio::join!(feeds, checks);
    if let Some(scrapers) = scrapers {
        scrapers.await.ok();
    }
    Ok(())
}

/// `nntmux-ctl predb ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Links releases to PreDB rows with the same title (`predb:check`).
    Check {
        /// Only releases added in the last this many days.
        limit: Option<u32>,
    },
    /// Imports release-title metadata from public feeds (`predb:import-feed`).
    ImportFeed(feeds::command::ImportArgs),
    /// Scrapes IRC and WebSocket pre announcements (`irc:scrape`).
    Scrape {
        /// One network, without restarts. Defaults to supervising every enabled one.
        #[arg(long)]
        network: Option<String>,
    },
    /// One finite identity rebuild operation (`predb:identity-rebuild`).
    IdentityRebuild {
        #[command(subcommand)]
        action: RebuildAction,
    },
}

#[derive(Debug, Subcommand)]
pub enum RebuildAction {
    Start,
    Chunk {
        #[arg(long)]
        generation: String,
        #[arg(long, default_value_t = 128)]
        chunk: u32,
    },
    Certify {
        #[arg(long)]
        generation: String,
    },
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    match command {
        Command::Check { limit } => {
            let (matched, total) = check_pre(&ctx.db, limit).await?;
            println!("Matched {matched} of {total} PreDB titles to release search names.");
        }
        Command::ImportFeed(args) => {
            let succeeded = feeds::command::run(&ctx, args).await?;
            if !succeeded {
                bail!("no PreDB feed source succeeded");
            }
        }
        Command::Scrape { network } => irc::scrape_command(ctx, network).await?,
        Command::IdentityRebuild { action } => {
            let result = match action {
                RebuildAction::Start => {
                    let (generation, epoch, until) = identity::rebuild_start(&ctx.db).await?;
                    json!({"phase": "start", "generation": generation, "epoch": epoch,
                        "until_id": until, "certified": false})
                }
                RebuildAction::Chunk { generation, chunk } => {
                    let (cursor, processed, exhausted) =
                        identity::rebuild_chunk(&ctx.db, &generation, chunk).await?;
                    json!({"phase": "chunk", "generation": generation, "cursor": cursor,
                        "processed": processed, "exhausted": exhausted, "certified": false})
                }
                RebuildAction::Certify { generation } => {
                    identity::rebuild_certify(&ctx.db, &generation).await?;
                    json!({"phase": "certify", "generation": generation, "certified": true})
                }
            };
            println!("{result}");
        }
    }
    Ok(())
}

/// `Predb::checkPre`: links unmatched releases whose search name equals a PreDB title. SQL
/// collation only nominates; the update is guarded on the exact bytes that were compared.
pub async fn check_pre(db: &MySqlPool, days: Option<u32>) -> Result<(u64, usize)> {
    let mut sql = String::from(
        "SELECT p.id, r.id, p.title, r.searchname FROM predb p \
         JOIN releases r ON p.title = r.searchname WHERE r.predb_id < 1",
    );
    if days.is_some_and(|days| days > 0) {
        sql.push_str(" AND r.adddate > NOW() - INTERVAL ? DAY");
    }
    let mut query = sqlx::query_as::<_, (u64, u64, String, String)>(sqlx::AssertSqlSafe(sql));
    if let Some(days) = days.filter(|days| *days > 0) {
        query = query.bind(days);
    }
    let rows = query.fetch_all(db).await?;
    let total = rows.len();
    let mut updated = 0;
    for (predb_id, release_id, title, searchname) in rows {
        if exact_value(&title) != exact_value(&searchname) {
            continue;
        }
        updated += sqlx::query(
            "UPDATE releases SET predb_id = ? WHERE id = ? AND predb_id < 1 AND HEX(searchname) = HEX(?)",
        )
        .bind(predb_id)
        .bind(release_id)
        .bind(&searchname)
        .execute(db)
        .await?
        .rows_affected();
    }
    Ok((updated, total))
}
