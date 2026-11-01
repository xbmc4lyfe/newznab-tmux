//! `releases:fix-names`, `releases:fix-names-group`, `multiprocessing:fixrelnames`,
//! `match:prefiles` and `releases:fix-nzbsplit`, and the `fixnames` role.

use std::sync::Arc;
use std::time::Duration;

use anyhow::{Context, Result, bail};
use clap::Subcommand;
use nntmux_categorize::Categorizer;
use nntmux_categorize::names::unwrap_nzbsplit;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown, run_slots};
use redis::AsyncCommands;

use crate::queries::{self, FixNamesConfig, PredbTitle};
use crate::release::Release;
use crate::service::{CandidateSearch, IncompleteMatch, NameFixer, Par2Outcome, Par2Probe};
use crate::update::{FixerCleaner, ReleaseSearch, ReleaseUpdater};

const LEFT_GUIDS: [&str; 16] = ["0", "1", "2", "3", "4", "5", "6", "7", "8", "9", "a", "b", "c", "d", "e", "f"];

/// `nntmux-ctl namefix ...`
#[derive(Debug, Subcommand)]
pub enum Command {
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
    par2: Box<dyn Par2Probe>,
}

fn backends(_ctx: &Ctx) -> Result<Backends> {
    Ok(Backends {
        search: Arc::new(crate::update::NoSearch),
        candidates: Arc::new(Unavailable),
        cleaner: |name| name.to_owned(),
        par2: Box::new(DeferPar2),
    })
}

struct Unavailable;

#[async_trait::async_trait]
impl CandidateSearch for Unavailable {
    async fn release_candidates_page(&self, _title: &str, _limit: u64, _after: i64) -> Result<crate::service::CandidatePage> {
        bail!("The configured search backend is unavailable for paged PreDB matching.")
    }
}

/// Leaves `proc_par2` pending, for the PAR2 sweep to reach.
struct DeferPar2;

#[async_trait::async_trait]
impl Par2Probe for DeferPar2 {
    async fn probe(&mut self, _release: &Release) -> Result<Par2Outcome> {
        Ok(Par2Outcome::Deferred)
    }
}

async fn fixer(ctx: &Ctx, config: FixNamesConfig, backends: &Backends) -> Result<NameFixer> {
    let categorizer = Categorizer::load(&ctx.db).await?;
    let updater = ReleaseUpdater::new(ctx.db.clone(), categorizer, backends.search.clone(), backends.cleaner);
    Ok(NameFixer::new(updater, config))
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    let backends = backends(&ctx)?;
    match command {
        Command::FixNames { method, update, category, set_status, limit, show } => {
            let mut config = FixNamesConfig::from_env();
            config.batch_limit = Some(limit.max(1));
            let categories = match category.as_str() {
                "all" => 2,
                "predb_id" => 3,
                _ => 1,
            };
            let mut fixer = fixer(&ctx, config, &backends).await?;
            run_method(&mut fixer, method, update, categories, set_status, show).await
        }
        Command::FixNamesGroup { kind, guid_char, limit, thread, workers } => match kind.as_str() {
            "standard" => {
                let Some(guid) = guid_char else {
                    bail!("--guid-char is required for standard type");
                };
                let Backends { search, candidates, cleaner, par2 } = backends;
                let backends = Backends { search, candidates, cleaner, par2: Box::new(DeferPar2) };
                let mut par2 = par2;
                let (checked, fixed) = standard_pass(&ctx, &guid, limit.max(1) as usize, &backends, par2.as_mut()).await?;
                if checked == 0 {
                    println!("No releases to process");
                } else {
                    println!("Processed {checked} releases");
                    println!("Fixed {fixed} release names");
                }
                Ok(())
            }
            "predbft" => {
                let (processed, incomplete) = predbft_pass(&ctx, thread, workers, limit, &backends).await?;
                println!("Processed {processed} PreDB entries");
                if incomplete > 0 {
                    bail!("{incomplete} PreDB entries remain unsearched after incomplete matching.");
                }
                Ok(())
            }
            other => bail!("Invalid type: {other}. Use 'standard' or 'predbft'"),
        },
        Command::Fixrelnames { kind } => fixrelnames(&ctx, &kind, backends).await,
        Command::MatchPrefiles { limit, show } => {
            let limit = match limit.as_str() {
                "full" => None,
                number => Some(number.parse::<i64>().context("Limit must be \"full\" or a numeric value.")?),
            };
            let mut fixer = fixer(&ctx, FixNamesConfig::from_env(), &backends).await?;
            fixer.match_prefiles(limit, show).await
        }
        Command::FixNzbsplit { dry_run, include_renamed } => fix_nzbsplit(&ctx, dry_run, include_renamed, &backends).await,
    }
}

async fn run_method(fixer: &mut NameFixer, method: u32, write: bool, categories: i32, status: bool, show: bool) -> Result<()> {
    let time = if method % 2 == 1 { 1 } else { 2 };
    match method {
        3 | 4 => fixer.fix_names_with_nfo(time, write, categories, status, show).await,
        5 | 6 => fixer.fix_names_with_files(time, write, categories, status, show).await,
        7 | 8 => bail!("PAR2 renaming isn't ported yet"),
        9 | 10 => fixer.fix_names_with_media(time, write, categories, status, show).await,
        11 | 12 => fixer.fix_xxx_names_with_files(time, write, categories, status, show).await,
        13 | 14 => fixer.fix_names_with_srr(time, write, categories, status, show).await,
        15 | 16 => fixer.fix_names_with_par_hash(time, write, categories, status, show).await,
        17 | 18 => fixer.fix_names_with_media_movie_name(time, write, categories, status, show).await,
        19 | 20 => fixer.fix_names_with_crc(time, write, categories, status, show).await,
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
async fn reserve_standard_batch(ctx: &Ctx, config: &FixNamesConfig, left_guid: &str, limit: usize) -> Result<Vec<Release>> {
    let key = format!("rename:standard:cursor:{left_guid}");
    let reserved = with_lock(ctx, &key, 120, async |redis| {
        let cursor: Option<i64> = redis.get(&key).await.unwrap_or(None);
        let cursor = cursor.unwrap_or(0).max(0);
        let before = (cursor > 0).then_some(cursor);
        let mut releases = queries::standard_candidate_batch(&ctx.db, config, left_guid, limit, before).await?;
        if releases.is_empty() && cursor > 0 {
            releases = queries::standard_candidate_batch(&ctx.db, config, left_guid, limit, None).await?;
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
    par2: &mut dyn Par2Probe,
) -> Result<(usize, u64)> {
    let config = FixNamesConfig::from_env();
    let releases = reserve_standard_batch(ctx, &config, left_guid, limit).await?;
    let mut fixer = fixer(ctx, config, backends).await?;
    fixer.process_standard_batch(releases, false, par2).await
}

/// A bounded id window. Unfinished titles stay eligible and come back when the window wraps,
/// including after a worker crashes or its backend fails.
async fn reserve_predb_batch(ctx: &Ctx, worker: i64, workers: i64, limit: i64) -> Result<Vec<PredbTitle>> {
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
            let max: Option<u64> = sqlx::query_scalar("SELECT MAX(id) FROM predb").fetch_one(&ctx.db).await?;
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
async fn predbft_pass(ctx: &Ctx, thread: i64, workers: i64, limit: i64, backends: &Backends) -> Result<(u64, u64)> {
    let titles = reserve_predb_batch(ctx, thread, workers, limit).await?;
    if titles.is_empty() {
        return Ok((0, 0));
    }
    let mut fixer = fixer(ctx, FixNamesConfig::from_env(), backends).await?;
    let (mut processed, mut incomplete) = (0, 0);
    for pre in &titles {
        processed += 1;
        let outcome = match fixer.match_predb_fulltext(pre, backends.candidates.as_ref()).await {
            Ok(outcome) if outcome.retry => {
                tracing::warn!(predb = pre.predb_id, "current release evidence could not be completely confirmed");
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
        sqlx::query(sql).bind(searched).bind(pre.predb_id).execute(&ctx.db).await?;
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
    let backends = Arc::new(backends_without_par2(backends));
    let mut failures = 0;
    for (chunk_index, chunk) in guids.chunks(threads).enumerate() {
        let mut tasks = tokio::task::JoinSet::new();
        for (index, guid) in chunk.iter().enumerate() {
            let (ctx, backends, guid) = (ctx.clone(), backends.clone(), (*guid).to_owned());
            let kind = kind.to_owned();
            let worker = (chunk_index * threads + index + 1) as i64;
            tasks.spawn(async move {
                if kind == "standard" {
                    standard_pass(&ctx, &guid, per_run as usize, &backends, &mut DeferPar2).await.map(|_| ())
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

fn backends_without_par2(backends: Backends) -> Backends {
    Backends { par2: Box::new(DeferPar2), ..backends }
}

async fn fix_nzbsplit(ctx: &Ctx, dry_run: bool, include_renamed: bool, backends: &Backends) -> Result<()> {
    let categorizer = Categorizer::load(&ctx.db).await?;
    let renamed = if include_renamed { "" } else { " AND isrenamed = 0" };
    let filter = format!("(name LIKE '%\\_\\_NZBSPLIT\\_\\_%' OR searchname LIKE '%\\_\\_NZBSPLIT\\_\\_%'){renamed}");
    let count: i64 = sqlx::query_scalar(sqlx::AssertSqlSafe(format!("SELECT COUNT(*) FROM releases WHERE {filter}")))
        .fetch_one(&ctx.db)
        .await?;
    if count == 0 {
        println!(
            "{}",
            if include_renamed { "No NZBSPLIT-wrapped releases found." } else { "No unrenamed NZBSPLIT-wrapped releases found." }
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
        let rows: Vec<(i64, Option<String>, Option<String>, Option<String>, i64, i32)> =
            sqlx::query_as(sqlx::AssertSqlSafe(select.clone())).bind(after).fetch_all(&ctx.db).await?;
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
            let decision = categorizer.determine(&ctx.db, group, &new_title, fromname.as_deref().unwrap_or("")).await?;
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

/// The `fixnames` role: standard and PreDB fulltext passes across the GUID partitions, plus the
/// numbered source passes the tmux monitor ran.
pub async fn run(ctx: Ctx, args: RoleArgs, stop: Shutdown) -> Result<()> {
    let backends = Arc::new(backends(&ctx)?);
    run_slots(ctx, args, stop, move |ctx, slot| {
        let backends = backends.clone();
        async move {
            if ctx.paused().await? {
                return Ok(false);
            }
            let (per_run, threads) = post_processing_settings(&ctx).await?;
            if per_run <= 0 {
                return Ok(false);
            }
            let guid = LEFT_GUIDS[slot % LEFT_GUIDS.len()];
            let (checked, _) = standard_pass(&ctx, guid, per_run as usize, &backends, &mut DeferPar2).await?;
            let workers = threads.clamp(1, 16);
            let worker = (slot as i64 % workers) + 1;
            let (processed, _) = predbft_pass(&ctx, worker, workers, per_run, &backends).await?;
            tokio::time::sleep(Duration::from_millis(0)).await;
            Ok(checked > 0 || processed > 0)
        }
    })
    .await
}
