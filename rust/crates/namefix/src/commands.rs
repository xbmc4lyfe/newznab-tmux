//! `releases:fix-names`, `releases:fix-names-group`, `multiprocessing:fixrelnames`,
//! `match:prefiles` and `releases:fix-nzbsplit`, and the `fixnames` role.

use std::sync::Arc;

use anyhow::{Context, Result, bail};
use clap::Subcommand;
use nntmux_categorize::Categorizer;
use nntmux_categorize::names::unwrap_nzbsplit;
use nntmux_nntp::{NntpConfig, NntpPool};
use nntmux_runtime::{Ctx, RoleArgs, Shutdown, run_slots};
use nntmux_search::SearchClient;
use redis::AsyncCommands;

use crate::fixnames_chain;
use crate::identity_suggestions;
use crate::par2_check::{Par2Checker, Par2Settings, env_bool};
use crate::par2_nntp::NntpArticles;
use crate::par2_probe::{BatchProbe, fix_names_with_par2};
use crate::queries::{self, FixNamesConfig, PredbTitle};
use crate::release::Release;
use crate::service::{CandidatePage, CandidateSearch, IncompleteMatch, NameFixer};
use crate::update::{FixerCleaner, ReleaseSearch, ReleaseUpdater};

const LEFT_GUIDS: [&str; 16] = [
    "0", "1", "2", "3", "4", "5", "6", "7", "8", "9", "a", "b", "c", "d", "e", "f",
];

/// `nntmux-ctl namefix ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Reports constrained, read-only identity suggestions, and never renames or links a
    /// release (`releases:identity-suggestions`).
    IdentitySuggestions {
        /// Filename or partial release title.
        query: Option<String>,
        /// Reads the current search name of this release.
        #[arg(long)]
        release_id: Option<String>,
        /// Restricts to these known PreDB IDs (maximum 50).
        #[arg(long)]
        candidate_id: Vec<String>,
    },
    /// Fixes names by one numbered method (`releases:fix-names`).
    FixNames {
        /// 3-20: odd methods look at the past 6 hours, even ones at everything.
        method: u32,
        /// Actually update the names. Otherwise just report.
        #[arg(long)]
        update: bool,
        /// `other`, `all` or `predb_id`.
        #[arg(long, default_value = "other")]
        category: String,
        /// Mark releases as checked afterwards.
        #[arg(long)]
        set_status: bool,
        #[arg(long, default_value_t = 1000)]
        limit: usize,
        #[arg(long)]
        show: bool,
    },
    /// One group-based pass (`releases:fix-names-group`).
    FixNamesGroup {
        /// `standard` or `predbft`.
        kind: String,
        #[arg(long)]
        guid_char: Option<String>,
        #[arg(long, default_value_t = 1000)]
        limit: i64,
        #[arg(long, default_value_t = 1)]
        thread: i64,
        #[arg(long, default_value_t = 1)]
        workers: i64,
    },
    /// Every worker of a pass (`multiprocessing:fixrelnames`).
    Fixrelnames {
        /// `standard` or `predbft`.
        kind: String,
    },
    /// Matches release file names to PreDB filenames (`match:prefiles`).
    MatchPrefiles {
        /// A number of releases, or `full`.
        #[arg(default_value = "full")]
        limit: String,
        #[arg(long)]
        show: bool,
    },
    /// Finds releases much bigger or smaller than their files (`nntmux:find-size-mismatches`).
    FindSizeMismatches(crate::size_mismatch::Args),
    /// Fixes NZBSPLIT-wrapped names and recategorizes them (`releases:fix-nzbsplit`).
    FixNzbsplit {
        #[arg(long)]
        dry_run: bool,
        #[arg(long)]
        include_renamed: bool,
    },
}

/// The other workstreams' services, wired in one place.
struct Backends {
    search: Arc<dyn ReleaseSearch>,
    candidates: Arc<dyn CandidateSearch>,
    cleaner: FixerCleaner,
    /// Sessions for PAR2 reads, shared by every pass so they are reused. Nothing connects
    /// until a release needs a PAR2 file.
    nntp: NntpPool,
    use_alternate: bool,
}

fn backends(ctx: &Ctx) -> Result<Backends> {
    let client = Arc::new(SearchBackend {
        client: SearchClient::from_ctx(ctx)?,
        db: ctx.db.clone(),
    });
    let nntp = NntpConfig::from_env();
    let use_alternate = nntp.use_alternate;
    Ok(Backends {
        search: client.clone(),
        candidates: client,
        cleaner: nntmux_predb::filename::fixer_cleaner,
        nntp: NntpPool::new(nntp),
        use_alternate,
    })
}

struct SearchBackend {
    client: SearchClient,
    db: sqlx::MySqlPool,
}

#[async_trait::async_trait]
impl ReleaseSearch for SearchBackend {
    async fn update_release(&self, release_id: i64) {
        // False means a retry row was recorded, which the search workers pick up.
        self.client.update_release(&self.db, release_id).await;
    }
}

#[async_trait::async_trait]
impl CandidateSearch for SearchBackend {
    async fn release_candidates_page(
        &self,
        title: &str,
        limit: u64,
        after: i64,
    ) -> Result<CandidatePage> {
        let page = self
            .client
            .search_release_candidates_page(title, limit, after)
            .await?;
        Ok(CandidatePage {
            ids: page.ids,
            has_more: page.has_more,
        })
    }
}

async fn fixer(ctx: &Ctx, config: FixNamesConfig, backends: &Backends) -> Result<NameFixer> {
    let categorizer = Categorizer::load(&ctx.db).await?;
    let updater = ReleaseUpdater::new(
        ctx.db.clone(),
        categorizer,
        backends.search.clone(),
        backends.cleaner,
    );
    Ok(NameFixer::new(updater, config))
}

/// A PAR2 checker over the shared NNTP pool: its own name fixer, for the renames it applies.
async fn par2_checker(
    ctx: &Ctx,
    config: FixNamesConfig,
    backends: &Backends,
) -> Result<Par2Checker> {
    Ok(Par2Checker {
        fixer: fixer(ctx, config.clone(), backends).await?,
        search: backends.search.clone(),
        redis: ctx.redis.clone(),
        articles: Box::new(NntpArticles::with_pool(
            backends.nntp.clone(),
            backends.use_alternate,
        )),
        config,
        settings: Par2Settings::from_env(),
    })
}

/// One numbered level: `releases:fix-names <method>`.
#[allow(clippy::too_many_arguments)]
async fn run_level(
    ctx: &Ctx,
    backends: &Backends,
    method: u32,
    update: bool,
    categories: i32,
    set_status: bool,
    limit: Option<usize>,
    show: bool,
) -> Result<()> {
    let mut config = FixNamesConfig::from_env();
    if let Some(limit) = limit {
        config.batch_limit = Some(limit.max(1));
    }
    if matches!(method, 7 | 8) {
        // The session is opened up front, so an unreachable provider fails the command.
        let mut checker = par2_checker(ctx, config, backends).await?;
        checker
            .articles
            .connect()
            .await
            .map_err(|error| anyhow::anyhow!("Unable to connect to usenet. Error: {error}"))?;
        let time = if method % 2 == 1 { 1 } else { 2 };
        return fix_names_with_par2(&mut checker, time, update, categories, set_status).await;
    }
    let mut fixer = fixer(ctx, config, backends).await?;
    run_method(&mut fixer, method, update, categories, set_status, show).await
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    if let Command::IdentitySuggestions {
        query,
        release_id,
        candidate_id,
    } = command
    {
        let request = identity_suggestions::Request {
            query,
            release_id,
            candidate_ids: candidate_id,
        };
        println!("{}", identity_suggestions::run(&ctx, request).await?);
        return Ok(());
    }
    let backends = backends(&ctx)?;
    match command {
        Command::IdentitySuggestions { .. } => unreachable!("handled above"),
        Command::FixNames {
            method,
            update,
            category,
            set_status,
            limit,
            show,
        } => {
            let categories = match category.as_str() {
                "all" => 2,
                "predb_id" => 3,
                _ => 1,
            };
            run_level(
                &ctx,
                &backends,
                method,
                update,
                categories,
                set_status,
                Some(limit),
                show,
            )
            .await
        }
        Command::FixNamesGroup {
            kind,
            guid_char,
            limit,
            thread,
            workers,
        } => match kind.as_str() {
            "standard" => {
                let Some(guid) = guid_char else {
                    bail!("--guid-char is required for standard type");
                };
                let (checked, fixed) =
                    standard_pass(&ctx, &guid, limit.max(1) as usize, &backends).await?;
                if checked == 0 {
                    println!("No releases to process");
                } else {
                    println!("Processed {checked} releases");
                    println!("Fixed {fixed} release names");
                }
                Ok(())
            }
            "predbft" => {
                let (processed, incomplete) =
                    predbft_pass(&ctx, thread, workers, limit, &backends).await?;
                println!("Processed {processed} PreDB entries");
                if incomplete > 0 {
                    bail!(
                        "{incomplete} PreDB entries remain unsearched after incomplete matching."
                    );
                }
                Ok(())
            }
            other => bail!("Invalid type: {other}. Use 'standard' or 'predbft'"),
        },
        Command::Fixrelnames { kind } => fixrelnames(&ctx, &kind, backends).await,
        Command::MatchPrefiles { limit, show } => {
            let limit = match limit.as_str() {
                "full" => None,
                number => Some(
                    number
                        .parse::<i64>()
                        .context("Limit must be \"full\" or a numeric value.")?,
                ),
            };
            let mut fixer = fixer(&ctx, FixNamesConfig::from_env(), &backends).await?;
            fixer.match_prefiles(limit, show).await
        }
        Command::FindSizeMismatches(args) => find_size_mismatches(&ctx, args, &backends).await,
        Command::FixNzbsplit {
            dry_run,
            include_renamed,
        } => fix_nzbsplit(&ctx, dry_run, include_renamed, &backends).await,
    }
}

async fn run_method(
    fixer: &mut NameFixer,
    method: u32,
    write: bool,
    categories: i32,
    status: bool,
    show: bool,
) -> Result<()> {
    let time = if method % 2 == 1 { 1 } else { 2 };
    match method {
        3 | 4 => {
            fixer
                .fix_names_with_nfo(time, write, categories, status, show)
                .await
        }
        5 | 6 => {
            fixer
                .fix_names_with_files(time, write, categories, status, show)
                .await
        }
        7 | 8 => bail!("PAR2 renaming needs a PAR2 checker, which `run_level` builds"),
        9 | 10 => {
            fixer
                .fix_names_with_media(time, write, categories, status, show)
                .await
        }
        11 | 12 => {
            fixer
                .fix_xxx_names_with_files(time, write, categories, status, show)
                .await
        }
        13 | 14 => {
            fixer
                .fix_names_with_srr(time, write, categories, status, show)
                .await
        }
        15 | 16 => {
            fixer
                .fix_names_with_par_hash(time, write, categories, status, show)
                .await
        }
        17 | 18 => {
            fixer
                .fix_names_with_media_movie_name(time, write, categories, status, show)
                .await
        }
        19 | 20 => {
            fixer
                .fix_names_with_crc(time, write, categories, status, show)
                .await
        }
        other => bail!("Unknown method {other}. Use 3 to 20."),
    }
}

/// A short Redis lock around a reservation. A lost cursor only restarts a sweep.
async fn with_lock<T>(
    ctx: &Ctx,
    key: &str,
    seconds: u64,
    work: impl AsyncFnOnce(&mut redis::aio::ConnectionManager) -> Result<T>,
) -> Result<Option<T>> {
    let mut redis = ctx.redis.clone();
    let lock = format!("{key}:lock");
    let claimed: Option<String> = redis::cmd("SET")
        .arg(&lock)
        .arg("1")
        .arg("NX")
        .arg("EX")
        .arg(seconds)
        .query_async(&mut redis)
        .await?;
    if claimed.is_none() {
        return Ok(None);
    }
    let result = work(&mut redis).await;
    let _: Result<i64, _> = redis.del(&lock).await;
    result.map(Some)
}

/// Reserves a batch, newest first, and resumes below it next time.
async fn reserve_standard_batch(
    ctx: &Ctx,
    config: &FixNamesConfig,
    left_guid: &str,
    limit: usize,
) -> Result<Vec<Release>> {
    let key = format!("rename:standard:cursor:{left_guid}");
    let reserved = with_lock(ctx, &key, 120, async |redis| {
        let cursor: Option<i64> = redis.get(&key).await.unwrap_or(None);
        let cursor = cursor.unwrap_or(0).max(0);
        let before = (cursor > 0).then_some(cursor);
        let mut releases =
            queries::standard_candidate_batch(&ctx.db, config, left_guid, limit, before).await?;
        if releases.is_empty() && cursor > 0 {
            releases =
                queries::standard_candidate_batch(&ctx.db, config, left_guid, limit, None).await?;
        }
        match releases.iter().map(|release| release.id).min() {
            Some(lowest) => {
                let _: () = redis.set(&key, lowest).await?;
            }
            None => {
                let _: i64 = redis.del(&key).await?;
            }
        }
        Ok(releases)
    })
    .await?;
    Ok(reserved.unwrap_or_default())
}

async fn standard_pass(
    ctx: &Ctx,
    left_guid: &str,
    limit: usize,
    backends: &Backends,
) -> Result<(usize, u64)> {
    let config = FixNamesConfig::from_env();
    let releases = reserve_standard_batch(ctx, &config, left_guid, limit).await?;
    let mut fixer = fixer(ctx, config.clone(), backends).await?;
    let mut par2 = BatchProbe::new(par2_checker(ctx, config, backends).await?);
    fixer
        .process_standard_batch(releases, false, &mut par2)
        .await
}

/// A bounded id window. Unfinished titles stay eligible and come back when the window wraps,
/// including after a worker crashes or its backend fails.
async fn reserve_predb_batch(
    ctx: &Ctx,
    worker: i64,
    workers: i64,
    limit: i64,
) -> Result<Vec<PredbTitle>> {
    let count = workers.clamp(1, 16);
    let slot = worker.clamp(1, count) - 1;
    let key = format!("predb:fulltext:v1:{count}:{slot}");
    let reserved = with_lock(ctx, &key, 60, async |redis| {
        let saved: Option<String> = redis.get(&key).await.unwrap_or(None);
        let parsed = saved.as_deref().and_then(|value| {
            let (last, max) = value.split_once(':')?;
            let (last, max) = (last.parse::<i64>().ok()?, max.parse::<i64>().ok()?);
            (last >= 0 && last <= max).then_some((last, max))
        });
        let highest = async || -> Result<i64> {
            let max: Option<u64> = sqlx::query_scalar("SELECT MAX(id) FROM predb")
                .fetch_one(&ctx.db)
                .await?;
            Ok(max.unwrap_or(0) as i64)
        };
        let (last, mut max) = match parsed {
            Some(values) => values,
            None => (0, highest().await?),
        };
        let mut rows = queries::predb_batch(&ctx.db, worker, workers, limit, last, max).await?;
        if rows.is_empty() && last > 0 {
            max = highest().await?;
            rows = queries::predb_batch(&ctx.db, worker, workers, limit, 0, max).await?;
        }
        match rows.last() {
            None => {
                let _: i64 = redis.del(&key).await?;
            }
            Some(row) => {
                let _: () = redis.set(&key, format!("{}:{max}", row.predb_id)).await?;
            }
        }
        Ok(rows)
    })
    .await?;
    reserved.context("Another worker is reserving this PreDB fulltext shard; retry later.")
}

/// `(processed, incomplete)`. An incomplete title stays unsearched for a later pass.
async fn predbft_pass(
    ctx: &Ctx,
    thread: i64,
    workers: i64,
    limit: i64,
    backends: &Backends,
) -> Result<(u64, u64)> {
    let titles = reserve_predb_batch(ctx, thread, workers, limit).await?;
    if titles.is_empty() {
        return Ok((0, 0));
    }
    let mut fixer = fixer(ctx, FixNamesConfig::from_env(), backends).await?;
    let (mut processed, mut incomplete) = (0, 0);
    for pre in &titles {
        processed += 1;
        let outcome = match fixer
            .match_predb_fulltext(pre, backends.candidates.as_ref())
            .await
        {
            Ok(outcome) if outcome.retry => {
                tracing::warn!(
                    predb = pre.predb_id,
                    "current release evidence could not be completely confirmed"
                );
                incomplete += 1;
                continue;
            }
            Ok(outcome) => outcome,
            Err(error) if error.downcast_ref::<IncompleteMatch>().is_some() => {
                tracing::warn!(predb = pre.predb_id, %error, "PreDB match incomplete");
                incomplete += 1;
                continue;
            }
            Err(error) => return Err(error),
        };
        let searched = if outcome.matched {
            1
        } else if outcome.updated < 0 {
            -6
        } else {
            pre.searched - 1
        };
        let sql = if searched == 1 {
            "UPDATE predb SET searched = ? WHERE id = ?"
        } else {
            "UPDATE predb SET searched = ? WHERE id = ? AND searched = 0"
        };
        sqlx::query(sql)
            .bind(searched)
            .bind(pre.predb_id)
            .execute(&ctx.db)
            .await?;
    }
    Ok((processed, incomplete))
}

/// `(fix_names_per_run, fix_name_threads)`.
async fn post_processing_settings(ctx: &Ctx) -> Result<(i64, i64)> {
    Ok(sqlx::query_as(
        "SELECT CAST(fix_names_per_run AS SIGNED), CAST(fix_name_threads AS SIGNED) FROM post_processing_configurations LIMIT 1",
    )
    .fetch_one(&ctx.db)
    .await?)
}

async fn fixrelnames(ctx: &Ctx, kind: &str, backends: Backends) -> Result<()> {
    if !matches!(kind, "standard" | "predbft") {
        bail!("Type must be either: standard or predbft");
    }
    let (per_run, threads) = post_processing_settings(ctx).await?;
    let threads = threads.clamp(1, 16) as usize;
    let mut guids: Vec<&str> = LEFT_GUIDS.to_vec();
    if kind == "predbft" {
        let count = queries::count_predb_launcher_candidates(&ctx.db, per_run, 16).await?;
        if count == 0 || per_run <= 0 {
            guids.clear();
        } else {
            guids.truncate((count as usize).div_ceil(per_run as usize).min(16));
        }
    }
    if per_run <= 0 || guids.is_empty() {
        println!("No work to do.");
        return Ok(());
    }
    let workers = guids.len() as i64;
    let backends = Arc::new(backends);
    let mut failures = 0;
    for (chunk_index, chunk) in guids.chunks(threads).enumerate() {
        let mut tasks = tokio::task::JoinSet::new();
        for (index, guid) in chunk.iter().enumerate() {
            let (ctx, backends, guid) = (ctx.clone(), backends.clone(), (*guid).to_owned());
            let kind = kind.to_owned();
            let worker = (chunk_index * threads + index + 1) as i64;
            tasks.spawn(async move {
                if kind == "standard" {
                    standard_pass(&ctx, &guid, per_run as usize, &backends).await.map(|_| ())
                } else {
                    let (_, incomplete) = predbft_pass(&ctx, worker, workers, per_run, &backends).await?;
                    if incomplete > 0 {
                        bail!("{incomplete} PreDB entries remain unsearched after incomplete matching.");
                    }
                    Ok(())
                }
            });
        }
        while let Some(joined) = tasks.join_next().await {
            match joined {
                Ok(Ok(())) => {}
                Ok(Err(error)) => {
                    failures += 1;
                    tracing::warn!(%error, "fix-names worker failed");
                }
                Err(error) => {
                    failures += 1;
                    tracing::error!(%error, "fix-names worker panicked");
                }
            }
        }
    }
    if failures > 0 {
        bail!("{failures} fix-names workers failed");
    }
    Ok(())
}

async fn find_size_mismatches(
    ctx: &Ctx,
    args: crate::size_mismatch::Args,
    backends: &Backends,
) -> Result<()> {
    use crate::size_mismatch::{find, number_format, rename};
    let found = find(&ctx.db, &args).await?;
    if found.is_empty() {
        println!(
            "No releases found with size mismatches above {}%{}",
            args.threshold,
            if args.season_pack {
                " that are season packs"
            } else {
                ""
            }
        );
        return Ok(());
    }
    if args.rename {
        println!("\nAttempting to rename {} releases...\n", found.len());
        let categorizer = Categorizer::load(&ctx.db).await?;
        let mut updater = ReleaseUpdater::new(
            ctx.db.clone(),
            categorizer,
            backends.search.clone(),
            backends.cleaner,
        );
        for mismatch in &found {
            rename(&ctx.db, &mut updater, mismatch).await?;
        }
    } else {
        println!("Release ID\tSearchname\tRelease Size\tFiles Total\tDifference\tDiff %");
        for mismatch in &found {
            println!(
                "{}\t{}\t{} GiB\t{} GiB\t{} GiB\t{}%",
                mismatch.id,
                mismatch.searchname.as_deref().unwrap_or(""),
                number_format(mismatch.release_size),
                number_format(mismatch.files_total_size),
                number_format(mismatch.size_diff),
                number_format(mismatch.diff_percent),
            );
        }
        println!(
            "\nFound {} releases with size mismatches above {}%",
            found.len(),
            args.threshold
        );
    }
    let ids: Vec<String> = found.iter().map(|m| m.id.to_string()).collect();
    println!("\nRelease IDs in CSV format:\n{}", ids.join(","));
    Ok(())
}

async fn fix_nzbsplit(
    ctx: &Ctx,
    dry_run: bool,
    include_renamed: bool,
    backends: &Backends,
) -> Result<()> {
    let categorizer = Categorizer::load(&ctx.db).await?;
    let renamed = if include_renamed {
        ""
    } else {
        " AND isrenamed = 0"
    };
    let filter = format!(
        "(name LIKE '%\\_\\_NZBSPLIT\\_\\_%' OR searchname LIKE '%\\_\\_NZBSPLIT\\_\\_%'){renamed}"
    );
    let count: i64 = sqlx::query_scalar(sqlx::AssertSqlSafe(format!(
        "SELECT COUNT(*) FROM releases WHERE {filter}"
    )))
    .fetch_one(&ctx.db)
    .await?;
    if count == 0 {
        println!(
            "{}",
            if include_renamed {
                "No NZBSPLIT-wrapped releases found."
            } else {
                "No unrenamed NZBSPLIT-wrapped releases found."
            }
        );
        return Ok(());
    }
    let select = format!(
        "SELECT CAST(id AS SIGNED), name, searchname, fromname, CAST(groups_id AS SIGNED), categories_id FROM releases \
         WHERE {filter} AND id > ? ORDER BY id LIMIT 250"
    );
    let mut after = 0i64;
    let mut updated = 0;
    loop {
        let rows: Vec<NzbsplitRow> = sqlx::query_as(sqlx::AssertSqlSafe(select.clone()))
            .bind(after)
            .fetch_all(&ctx.db)
            .await?;
        let Some(last) = rows.last().map(|row| row.0) else {
            break;
        };
        for (id, name, searchname, fromname, group, category) in rows {
            let new_title = searchname
                .as_deref()
                .and_then(unwrap_nzbsplit)
                .or_else(|| name.as_deref().and_then(unwrap_nzbsplit));
            let Some(new_title) = new_title else {
                continue;
            };
            let decision = categorizer
                .determine(
                    &ctx.db,
                    group,
                    &new_title,
                    fromname.as_deref().unwrap_or(""),
                )
                .await?;
            if dry_run {
                println!(
                    "[dry-run] #{id} {} -> {new_title} ({category} -> {})",
                    searchname.as_deref().unwrap_or(""),
                    decision.category
                );
                continue;
            }
            sqlx::query(
                "UPDATE releases SET name = ?, searchname = ?, categories_id = ?, isrenamed = 1, iscategorized = 1, \
                 videos_id = 0, tv_episodes_id = 0, tv_lookup_attempts = 0, tv_lookup_retry_at = NULL, imdbid = NULL, \
                 musicinfo_id = NULL, consoleinfo_id = NULL, gamesinfo_id = 0, bookinfo_id = NULL, movieinfo_id = NULL, \
                 anidbid = NULL WHERE id = ?",
            )
            .bind(&new_title)
            .bind(&new_title)
            .bind(decision.category)
            .bind(id)
            .execute(&ctx.db)
            .await?;
            backends.search.update_release(id).await;
            updated += 1;
        }
        after = last;
    }
    if dry_run {
        println!("Dry run complete. {count} NZBSPLIT-wrapped releases inspected.");
    } else {
        println!("Updated {updated} NZBSPLIT-wrapped releases.");
    }
    Ok(())
}

type NzbsplitRow = (
    i64,
    Option<String>,
    Option<String>,
    Option<String>,
    i64,
    i32,
);

/// The partitions in `0..total` that a slot serves, so that the slots together cover them all
/// whatever the concurrency.
fn slot_partitions(total: usize, slot: usize, slots: usize) -> impl Iterator<Item = usize> {
    (0..total).filter(move |partition| partition % slots.max(1) == slot)
}

/// `TMUX_FIX_NAMES_PREDBFT` and `TMUX_FIX_NAMES_PREDBFT_SCHEDULED`, with PHP's defaults.
fn predbft_flags() -> (bool, bool) {
    (
        env_bool("TMUX_FIX_NAMES_PREDBFT").unwrap_or(true),
        env_bool("TMUX_FIX_NAMES_PREDBFT_SCHEDULED").unwrap_or(true),
    )
}

/// Whether the scheduler owns the PreDB fulltext pass. The pass has one launcher: the scheduler
/// when this is true, otherwise the `fixnames` role while `TMUX_FIX_NAMES_PREDBFT` is on.
pub fn predbft_scheduled() -> bool {
    predbft_flags() == (true, true)
}

/// One cycle of what the tmux monitor's fix-names pane ran: the numbered levels, when the
/// pane would run them. Returns whether it ran.
async fn run_chain(ctx: &Ctx, backends: &Backends, stop: &Shutdown) -> Result<bool> {
    let config = FixNamesConfig::from_env();
    let Some(claimed) =
        fixnames_chain::claim(ctx, &config, &Par2Settings::from_env().key_prefix).await?
    else {
        return Ok(false);
    };
    let categories = match config.category.as_str() {
        "all" => 2,
        "predb_id" => 3,
        _ => 1,
    };
    let heartbeat = claimed.heartbeat();
    for level in claimed.levels.clone() {
        // The pane checked for a drain between commands. Without an answer, stop and let the
        // claim go rather than hold it.
        if ctx.paused().await.unwrap_or(true) {
            break;
        }
        let outcome = tokio::select! {
            () = stop.token().cancelled_owned() => break,
            outcome = run_level(
                ctx,
                backends,
                level,
                true,
                categories,
                true,
                config.batch_limit,
                true,
            ) => outcome,
        };
        if let Err(error) = outcome {
            tracing::warn!(
                level,
                error = format!("{error:#}"),
                "fix-names level failed"
            );
        }
    }
    drop(heartbeat);
    claimed.finish().await;
    Ok(true)
}

/// The `fixnames` role: what the tmux monitor ran, plus the PreDB fulltext passes. The first
/// slot runs the numbered fix-names levels (`fixNamesCommands`) whenever the pane would, which
/// the `fix_names_enabled` setting and a `fix_timer` rest between cycles pace; the others
/// share the PreDB shards. A cycle only counts as work if it moved something, so an idle role
/// polls at its idle interval rather than spinning. The standard GUID-partition pass has no
/// PHP schedule, so it stays a command (`releases:fix-names-group standard`) and is not run
/// here.
pub async fn run(ctx: Ctx, args: RoleArgs, stop: Shutdown) -> Result<()> {
    let backends = Arc::new(backends(&ctx)?);
    let slots = args.concurrency;
    let shutdown = stop.clone();
    run_slots(ctx, "fixnames", args, stop, move |ctx, slot| {
        let (backends, shutdown) = (backends.clone(), shutdown.clone());
        async move {
            if ctx.paused().await? {
                return Ok(false);
            }
            let mut moved = false;
            if slot == 0 {
                moved |= run_chain(&ctx, &backends, &shutdown).await?;
            }
            let predbft = predbft_flags() == (true, false);
            let (per_run, threads) = post_processing_settings(&ctx).await?;
            if predbft && per_run > 0 {
                let workers = threads.clamp(1, 16);
                for shard in slot_partitions(workers as usize, slot, slots) {
                    let (processed, incomplete) =
                        predbft_pass(&ctx, shard as i64 + 1, workers, per_run, &backends).await?;
                    moved |= processed > incomplete;
                }
            }
            Ok(moved)
        }
    })
    .await
}

#[cfg(test)]
mod tests {
    use super::slot_partitions;

    #[test]
    fn slots_cover_every_partition_once() {
        for (total, slots) in [(16, 4), (16, 1), (16, 3), (6, 4), (1, 4), (4, 16)] {
            let mut seen: Vec<usize> = (0..slots)
                .flat_map(|slot| slot_partitions(total, slot, slots))
                .collect();
            seen.sort_unstable();
            assert_eq!(seen, (0..total).collect::<Vec<_>>(), "{total} over {slots}");
        }
    }
}
