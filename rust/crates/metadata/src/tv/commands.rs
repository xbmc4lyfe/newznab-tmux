//! `tv:repair-links`, `tv:reprocess`, `tv:reprocess-missing-episodes` and `tv:reprocess-unmatched`.

use anyhow::{Result, bail};
use nntmux_runtime::Ctx;
use sqlx::{MySql, MySqlPool, QueryBuilder, Row};
use std::sync::Arc;
use std::time::Duration;
use tracing::error;

use super::parse::{ParsedRelease, parse_release};
use super::{Candidate, Outcome, Pipeline, RunState, fetch_candidates};
use crate::candidates::{
    SETTLED, TV_MAX_EPISODE_ATTEMPTS, tv_category_condition, tv_episode_retry_condition, tv_status,
};
use crate::php::mb_substr;
use crate::services::Services;
use crate::settings::{LookupMode, MetadataSettings};

const TV_RANGE: &str = "categories_id BETWEEN 5000 AND 5999";

/// `tv:repair-links` options.
#[derive(Clone, Debug, Default, clap::Args)]
pub struct TvRepairLinksOptions {
    /// Move legacy provider hand-off statuses (-1..-4, -6) to the current states.
    #[arg(long)]
    pub legacy: bool,
    /// Requeue releases linked to a show but left without an episode or a lookup state.
    #[arg(long)]
    pub stuck: bool,
    /// Unlink releases whose episode belongs to another show or doesn't match the release.
    #[arg(long)]
    pub wrong_episode: bool,
    /// Reset releases linked to a show that no longer exists.
    #[arg(long)]
    pub orphans: bool,
    /// Clear literal '' timezones stored in tv_info.
    #[arg(long)]
    pub localzone: bool,
    /// Merge duplicate tv_episodes rows of the same show, season and episode.
    #[arg(long)]
    pub dedupe_episodes: bool,
    /// Run every repair.
    #[arg(long)]
    pub all: bool,
    /// Only count what would change.
    #[arg(long)]
    pub dry_run: bool,
    /// Rows per batch.
    #[arg(long, default_value_t = 1000)]
    pub chunk: i64,
    /// Stop each repair after this many rows (0 = no limit).
    #[arg(long, default_value_t = 0)]
    pub limit: i64,
}

/// What a repair run changed, one line per repair.
pub type RepairReport = Vec<(&'static str, i64)>;

struct Repair<'a> {
    services: &'a Services,
    db: &'a MySqlPool,
    dry_run: bool,
    chunk: i64,
    limit: i64,
    links_changed: bool,
}

/// Queues an episode lookup now with the retry budget spent but one: these episodes have been
/// missing a long time, so one fresh lookup is worth it, a week of retries per release is not.
fn single_retry_set() -> String {
    format!(
        "tv_episodes_id = {}, tv_lookup_attempts = {}, tv_lookup_retry_at = NOW()",
        tv_status::EPISODE_MISSING,
        TV_MAX_EPISODE_ATTEMPTS - 1
    )
}

const RESET_SET: &str =
    "videos_id = 0, tv_episodes_id = 0, tv_lookup_attempts = 0, tv_lookup_retry_at = NULL";

fn in_list(ids: &[i64]) -> String {
    ids.iter().map(i64::to_string).collect::<Vec<_>>().join(",")
}

impl Repair<'_> {
    fn capped(&self, count: i64) -> i64 {
        if self.limit > 0 {
            count.min(self.limit)
        } else {
            count
        }
    }

    async fn count(&self, condition: &str) -> Result<i64> {
        let statement = format!("SELECT COUNT(*) FROM releases WHERE {condition}");
        Ok(sqlx::query_scalar(sqlx::AssertSqlSafe(statement))
            .fetch_one(self.db)
            .await?)
    }

    /// The ids of a chunk past `after`, in id order.
    async fn chunk_ids(&self, condition: &str, after: i64) -> Result<Vec<i64>> {
        let statement = format!(
            "SELECT CAST(id AS SIGNED) FROM releases WHERE {condition} AND id > {after} \
             ORDER BY id LIMIT {}",
            self.chunk
        );
        Ok(sqlx::query_scalar(sqlx::AssertSqlSafe(statement))
            .fetch_all(self.db)
            .await?)
    }

    async fn set_ids(&self, ids: &[i64], set: &str) -> Result<()> {
        if ids.is_empty() {
            return Ok(());
        }
        let statement = format!("UPDATE releases SET {set} WHERE id IN ({})", in_list(ids));
        sqlx::query(sqlx::AssertSqlSafe(statement))
            .execute(self.db)
            .await?;
        Ok(())
    }

    /// Walks the matching releases in id order, updating each chunk unless this is a dry run.
    async fn update_in_chunks(&self, condition: &str, set: &str) -> Result<i64> {
        if self.dry_run {
            return Ok(self.capped(self.count(condition).await?));
        }
        let (mut changed, mut last) = (0, 0);
        loop {
            let mut ids = self.chunk_ids(condition, last).await?;
            let Some(&next) = ids.last() else {
                return Ok(changed);
            };
            last = next;
            if self.limit > 0 {
                ids.truncate(usize::try_from(self.limit - changed)?);
            }
            self.set_ids(&ids, set).await?;
            changed += i64::try_from(ids.len())?;
            if self.limit > 0 && changed >= self.limit {
                return Ok(changed);
            }
        }
    }

    async fn legacy(&self) -> Result<i64> {
        let list = |values: &[i64]| {
            values
                .iter()
                .map(i64::to_string)
                .collect::<Vec<_>>()
                .join(",")
        };
        let clear = "tv_lookup_attempts = 0, tv_lookup_retry_at = NULL";
        let pending = self
            .update_in_chunks(
                &format!(
                    "{TV_RANGE} AND videos_id = 0 AND tv_episodes_id IN ({})",
                    list(&tv_status::LEGACY_PENDING)
                ),
                &format!("tv_episodes_id = {}, {clear}", tv_status::PENDING),
            )
            .await?;
        let exhausted = self
            .update_in_chunks(
                &format!(
                    "{TV_RANGE} AND videos_id = 0 AND tv_episodes_id IN ({})",
                    list(&tv_status::LEGACY_EXHAUSTED)
                ),
                &format!("tv_episodes_id = {}, {clear}", tv_status::SHOW_NOT_FOUND),
            )
            .await?;
        Ok(pending + exhausted)
    }

    /// Season packs keep the show as a season pack; everything else gets one more lookup.
    async fn stuck(&self) -> Result<i64> {
        let condition = format!(
            "{TV_RANGE} AND videos_id > 0 AND tv_episodes_id <= 0 \
             AND tv_episodes_id NOT IN ({}, {})",
            tv_status::EPISODE_MISSING,
            tv_status::SEASON_PACK
        );
        if self.dry_run {
            return Ok(self.capped(self.count(&condition).await?));
        }
        let (mut changed, mut last) = (0, 0);
        loop {
            let statement = format!(
                "SELECT CAST(id AS SIGNED) AS id, searchname FROM releases \
                 WHERE {condition} AND id > {last} ORDER BY id LIMIT {}",
                self.chunk
            );
            let rows = sqlx::query(sqlx::AssertSqlSafe(statement))
                .fetch_all(self.db)
                .await?;
            let Some(tail) = rows.last() else {
                return Ok(changed);
            };
            last = tail.try_get("id")?;
            let take = if self.limit > 0 {
                usize::try_from(self.limit - changed)?
            } else {
                rows.len()
            };
            let (mut packs, mut missing) = (Vec::new(), Vec::new());
            for row in rows.iter().take(take) {
                let id: i64 = row.try_get("id")?;
                let name: String = row.try_get("searchname")?;
                if parse_release(&name).is_some_and(|parsed| parsed.season_pack) {
                    packs.push(id);
                } else {
                    missing.push(id);
                }
            }
            self.set_ids(
                &packs,
                &format!(
                    "tv_episodes_id = {}, tv_lookup_attempts = 0, tv_lookup_retry_at = NULL",
                    tv_status::SEASON_PACK
                ),
            )
            .await?;
            self.set_ids(&missing, &single_retry_set()).await?;
            changed += i64::try_from(packs.len() + missing.len())?;
            if self.limit > 0 && changed >= self.limit {
                return Ok(changed);
            }
        }
    }

    async fn orphans(&mut self) -> Result<i64> {
        let condition = format!(
            "{TV_RANGE} AND videos_id > 0 AND NOT EXISTS \
             (SELECT 1 FROM videos WHERE videos.id = releases.videos_id)"
        );
        if self.dry_run {
            return Ok(self.capped(self.count(&condition).await?));
        }
        let (mut changed, mut last) = (0, 0);
        loop {
            let mut ids = self.chunk_ids(&condition, last).await?;
            let Some(&next) = ids.last() else {
                return Ok(changed);
            };
            last = next;
            if self.limit > 0 {
                ids.truncate(usize::try_from(self.limit - changed)?);
            }
            self.set_ids(&ids, RESET_SET).await?;
            self.services.sync_releases(&ids).await;
            self.links_changed = true;
            changed += i64::try_from(ids.len())?;
            if self.limit > 0 && changed >= self.limit {
                return Ok(changed);
            }
        }
    }

    /// Two shapes of bad links: an episode of another show (or a deleted one), and releases on
    /// `tv_episodes.id = 1`, where an old bug sent every newly inserted episode. The latter are
    /// kept only when the episode really matches.
    async fn wrong_episodes(&mut self) -> Result<i64> {
        let foreign = format!(
            "{TV_RANGE} AND tv_episodes_id > 0 AND NOT EXISTS (SELECT 1 FROM tv_episodes \
             WHERE tv_episodes.id = releases.tv_episodes_id \
             AND tv_episodes.videos_id = releases.videos_id)"
        );
        let mut changed = 0;
        if self.dry_run {
            changed = self.capped(self.count(&foreign).await?);
        } else {
            let mut last = 0;
            loop {
                let mut ids = self.chunk_ids(&foreign, last).await?;
                let Some(&next) = ids.last() else {
                    break;
                };
                last = next;
                if self.limit > 0 {
                    ids.truncate(usize::try_from(self.limit - changed)?);
                }
                self.requeue_episode_lookup(&ids).await?;
                changed += i64::try_from(ids.len())?;
                if self.limit > 0 && changed >= self.limit {
                    break;
                }
            }
        }

        let episode_one = sqlx::query(
            "SELECT CAST(videos_id AS SIGNED) AS videos_id, CAST(series AS SIGNED) AS series, \
             CAST(episode AS SIGNED) AS episode, CAST(firstaired AS CHAR) AS firstaired \
             FROM tv_episodes WHERE id = 1",
        )
        .fetch_optional(self.db)
        .await?;
        let Some(episode_one) = episode_one else {
            return Ok(changed);
        };
        let videos_id: i64 = episode_one.try_get("videos_id")?;
        let series: i64 = episode_one.try_get("series")?;
        let number: i64 = episode_one.try_get("episode")?;
        let aired: Option<String> = episode_one.try_get("firstaired")?;
        let condition = format!("{TV_RANGE} AND tv_episodes_id = 1 AND videos_id = {videos_id}");
        // Needs a parse per release to know, so dry runs walk the rows as well.
        let (mut wrong_count, mut last) = (0, 0);
        loop {
            let statement = format!(
                "SELECT CAST(id AS SIGNED) AS id, searchname FROM releases \
                 WHERE {condition} AND id > {last} ORDER BY id LIMIT {}",
                self.chunk
            );
            let rows = sqlx::query(sqlx::AssertSqlSafe(statement))
                .fetch_all(self.db)
                .await?;
            let Some(tail) = rows.last() else {
                break;
            };
            last = tail.try_get("id")?;
            let mut wrong = Vec::new();
            for row in &rows {
                let name: String = row.try_get("searchname")?;
                if !matches_episode(
                    parse_release(&name).as_ref(),
                    series,
                    number,
                    aired.as_deref(),
                ) {
                    wrong.push(row.try_get::<i64, _>("id")?);
                }
            }
            if self.limit > 0 {
                wrong.truncate(usize::try_from((self.limit - wrong_count).max(0))?);
            }
            if !self.dry_run {
                self.requeue_episode_lookup(&wrong).await?;
            }
            wrong_count += i64::try_from(wrong.len())?;
            if self.limit > 0 && wrong_count >= self.limit {
                break;
            }
        }
        Ok(changed + wrong_count)
    }

    async fn requeue_episode_lookup(&mut self, ids: &[i64]) -> Result<()> {
        if ids.is_empty() {
            return Ok(());
        }
        let statement = format!(
            "SELECT CAST(id AS SIGNED) FROM releases WHERE id IN ({}) AND EXISTS \
             (SELECT 1 FROM videos WHERE videos.id = releases.videos_id)",
            in_list(ids)
        );
        let with_show: Vec<i64> = sqlx::query_scalar(sqlx::AssertSqlSafe(statement))
            .fetch_all(self.db)
            .await?;
        let without: Vec<i64> = ids
            .iter()
            .copied()
            .filter(|id| !with_show.contains(id))
            .collect();
        self.set_ids(&with_show, &single_retry_set()).await?;
        self.set_ids(&without, RESET_SET).await?;
        self.services.sync_releases(ids).await;
        self.links_changed = true;
        Ok(())
    }

    async fn localzone(&self) -> Result<i64> {
        let count: i64 =
            sqlx::query_scalar("SELECT COUNT(*) FROM tv_info WHERE localzone = ''''''")
                .fetch_one(self.db)
                .await?;
        let count = self.capped(count);
        if !self.dry_run && count > 0 {
            sqlx::query("UPDATE tv_info SET localzone = '' WHERE localzone = '''''' LIMIT ?")
                .bind(count)
                .execute(self.db)
                .await?;
        }
        Ok(count)
    }

    /// Duplicates arise because the unique key includes the nullable `firstaired`. The row with an
    /// airdate (then the oldest) is kept and releases are repointed to it.
    async fn dedupe_episodes(&mut self) -> Result<i64> {
        let groups = sqlx::query(
            "SELECT CAST(videos_id AS SIGNED) AS videos_id, CAST(series AS SIGNED) AS series, \
             CAST(episode AS SIGNED) AS episode FROM tv_episodes WHERE episode > 0 \
             GROUP BY videos_id, series, episode HAVING COUNT(*) > 1",
        )
        .fetch_all(self.db)
        .await?;
        let mut removed = 0;
        for group in groups {
            if self.limit > 0 && removed >= self.limit {
                break;
            }
            let ids: Vec<i64> = sqlx::query_scalar(
                "SELECT CAST(id AS SIGNED) FROM tv_episodes \
                 WHERE videos_id = ? AND series = ? AND episode = ? \
                 ORDER BY firstaired IS NULL, id",
            )
            .bind(group.try_get::<i64, _>("videos_id")?)
            .bind(group.try_get::<i64, _>("series")?)
            .bind(group.try_get::<i64, _>("episode")?)
            .fetch_all(self.db)
            .await?;
            let Some((keep, duplicates)) = ids.split_first() else {
                continue;
            };
            removed += i64::try_from(duplicates.len())?;
            if self.dry_run || duplicates.is_empty() {
                continue;
            }
            let list = in_list(duplicates);
            let mut transaction = self.db.begin().await?;
            let statement =
                format!("SELECT CAST(id AS SIGNED) FROM releases WHERE tv_episodes_id IN ({list})");
            let releases: Vec<i64> = sqlx::query_scalar(sqlx::AssertSqlSafe(statement))
                .fetch_all(&mut *transaction)
                .await?;
            if !releases.is_empty() {
                let statement = format!(
                    "UPDATE releases SET tv_episodes_id = {keep} WHERE id IN ({})",
                    in_list(&releases)
                );
                sqlx::query(sqlx::AssertSqlSafe(statement))
                    .execute(&mut *transaction)
                    .await?;
            }
            let statement = format!("DELETE FROM tv_episodes WHERE id IN ({list})");
            sqlx::query(sqlx::AssertSqlSafe(statement))
                .execute(&mut *transaction)
                .await?;
            transaction.commit().await?;
            self.services.sync_releases(&releases).await;
            self.links_changed = true;
        }
        Ok(removed)
    }
}

fn matches_episode(
    parsed: Option<&ParsedRelease>,
    series: i64,
    number: i64,
    aired: Option<&str>,
) -> bool {
    let Some(parsed) = parsed else {
        return false;
    };
    if parsed.has_episode_numbers() {
        return parsed.season == Some(series) && parsed.episode == Some(number);
    }
    parsed.has_airdate() && aired.and_then(|aired| aired.get(..10)) == parsed.airdate.as_deref()
}

pub async fn repair_links(
    services: &Services,
    options: &TvRepairLinksOptions,
) -> Result<RepairReport> {
    let all = options.all;
    let steps = [
        (all || options.legacy, "Legacy statuses normalized"),
        (all || options.stuck, "Stuck releases requeued"),
        (all || options.orphans, "Releases of deleted shows reset"),
        (all || options.wrong_episode, "Wrong episode links removed"),
        (all || options.localzone, "Literal '' timezones cleared"),
        (all || options.dedupe_episodes, "Duplicate episodes merged"),
    ];
    if !steps.iter().any(|(selected, _)| *selected) {
        bail!("Choose at least one repair, or --all.");
    }
    let mut repair = Repair {
        services,
        db: &services.db,
        dry_run: options.dry_run,
        chunk: options.chunk.max(1),
        limit: options.limit.max(0),
        links_changed: false,
    };
    let mut report = Vec::new();
    for (index, (selected, label)) in steps.into_iter().enumerate() {
        if !selected {
            continue;
        }
        let count = match index {
            0 => repair.legacy().await?,
            1 => repair.stuck().await?,
            2 => repair.orphans().await?,
            3 => repair.wrong_episodes().await?,
            4 => repair.localzone().await?,
            _ => repair.dedupe_episodes().await?,
        };
        report.push((label, count));
    }
    if repair.links_changed {
        services.bump_release_cache().await;
    }
    Ok(report)
}

/// `tv:repair-links`.
pub async fn tv_repair_links(ctx: &Ctx, options: TvRepairLinksOptions) -> Result<()> {
    let services = Services::shared(ctx)?;
    let report = repair_links(&services, &options).await?;
    let heading = if options.dry_run {
        "Would change"
    } else {
        "Changed"
    };
    println!("{heading}:");
    for (label, count) in report {
        println!("  {label}: {count}");
    }
    Ok(())
}

/// `tv:reprocess-missing-episodes` options.
#[derive(Clone, Debug, Default, clap::Args)]
pub struct TvMissingEpisodesOptions {
    /// Limit the number of releases to process (0 = no limit).
    #[arg(long, default_value_t = 0)]
    pub limit: i64,
    /// Process only releases for a specific video ID.
    #[arg(long)]
    pub video_id: Option<i64>,
    /// Ignore the retry schedule and attempt cap.
    #[arg(long)]
    pub all: bool,
    /// Respect the configured TV lookup mode.
    #[arg(long)]
    pub scheduled: bool,
    /// Show how many releases would be processed without making changes.
    #[arg(long)]
    pub dry_run: bool,
    /// Sleep time in milliseconds between processing each release.
    #[arg(long, default_value_t = 0)]
    pub sleep: u64,
}

#[derive(Debug, Default, PartialEq, Eq)]
pub struct ReprocessReport {
    pub due: i64,
    pub processed: u64,
    pub matched: u64,
    pub failed: u64,
}

impl ReprocessReport {
    fn print(&self, matched_label: &str, failed_label: &str) {
        let rate = if self.processed > 0 {
            self.matched as f64 / self.processed as f64 * 100.0
        } else {
            0.0
        };
        println!(
            "Processed {}; {matched_label} {}; {failed_label} {}; match rate {rate:.2}%",
            self.processed, self.matched, self.failed
        );
    }
}

fn sample_name(name: &str, length: usize) -> String {
    if name.chars().count() > length {
        format!("{}...", mb_substr(name, length))
    } else {
        name.to_owned()
    }
}

/// Retries the episode lookup for releases whose show is known but whose episode wasn't available
/// yet. Each release backs off 1h, 6h, 24h, 72h, then 7 days before giving up.
pub async fn reprocess_missing_episodes(
    services: Arc<Services>,
    options: &TvMissingEpisodesOptions,
) -> Result<ReprocessReport> {
    let mode = if options.scheduled {
        MetadataSettings::load(&services.db).await?.tv_lookup
    } else {
        LookupMode::All
    };
    if !mode.enabled() {
        return Ok(ReprocessReport::default());
    }
    let mut condition = tv_episode_retry_condition(options.all);
    condition.push_str(mode.renamed_filter());
    if let Some(video_id) = options.video_id {
        condition.push_str(&format!(" AND videos_id = {video_id}"));
    }
    let due: i64 = sqlx::query_scalar(sqlx::AssertSqlSafe(format!(
        "SELECT COUNT(*) FROM releases WHERE {condition}"
    )))
    .fetch_one(&services.db)
    .await?;
    let mut report = ReprocessReport {
        due,
        ..ReprocessReport::default()
    };
    if due == 0 {
        println!("No TV releases with missing episodes are due for a retry.");
        return Ok(report);
    }
    // Newest first, so episodes that just aired aren't queued behind an old backlog.
    let order = "ORDER BY postdate DESC, id DESC";
    let select = "SELECT CAST(id AS SIGNED) AS id, searchname, CAST(videos_id AS SIGNED) AS videos_id FROM releases";
    let process_count = if options.limit > 0 {
        options.limit.min(due)
    } else {
        due
    };
    println!(
        "Found {due} TV release(s) with missing episodes due for a retry; processing {process_count}."
    );
    if options.dry_run {
        let sample = process_count.min(15);
        let releases = fetch_candidates(
            &services.db,
            format!("{select} WHERE {condition} {order} LIMIT {sample}"),
        )
        .await?;
        for release in releases {
            println!(
                "{}\t{}\t{}",
                release.id,
                release.videos_id,
                sample_name(&release.searchname, 55)
            );
        }
        return Ok(report);
    }
    let limit = if options.limit > 0 {
        format!(" LIMIT {}", options.limit)
    } else {
        String::new()
    };
    let releases = fetch_candidates(
        &services.db,
        format!("{select} WHERE {condition} {order}{limit}"),
    )
    .await?;
    let pipeline = Pipeline::new(services);
    let mut state = RunState::default();
    for release in &releases {
        match pipeline.process_release(release, &mut state).await {
            Ok(Outcome::Matched { .. }) => report.matched += 1,
            Ok(_) => report.failed += 1,
            Err(failure) => {
                report.failed += 1;
                error!(
                    release_id = release.id,
                    error = format!("{failure:#}"),
                    "couldn't process a release"
                );
            }
        }
        report.processed += 1;
        if options.sleep > 0 {
            tokio::time::sleep(Duration::from_millis(options.sleep)).await;
        }
    }
    Ok(report)
}

/// `tv:reprocess-missing-episodes`.
pub async fn tv_reprocess_missing_episodes(
    ctx: &Ctx,
    options: TvMissingEpisodesOptions,
) -> Result<()> {
    let services = Services::shared(ctx)?;
    let dry_run = options.dry_run;
    let report = reprocess_missing_episodes(services, &options).await?;
    if !dry_run && report.due > 0 {
        report.print("episodes matched", "still missing");
    }
    Ok(())
}

/// `tv:reprocess-unmatched` options.
#[derive(Clone, Debug, Default, clap::Args)]
pub struct TvUnmatchedOptions {
    /// Limit the number of releases to process (0 = no limit).
    #[arg(long, default_value_t = 0)]
    pub limit: i64,
    /// Show how many releases would be processed without making changes.
    #[arg(long)]
    pub dry_run: bool,
    /// Also retry releases whose names could not be parsed.
    #[arg(long)]
    pub parse_failed: bool,
    /// Sleep time in milliseconds between processing each release.
    #[arg(long, default_value_t = 0)]
    pub sleep: u64,
}

/// Reprocesses TV releases that aren't matched to any show, newest first.
pub async fn reprocess_unmatched(
    services: Arc<Services>,
    options: &TvUnmatchedOptions,
) -> Result<ReprocessReport> {
    let mut statuses = vec![tv_status::PENDING, tv_status::SHOW_NOT_FOUND];
    if options.parse_failed {
        statuses.push(tv_status::PARSE_FAILED);
    }
    // A partial awaiting late articles is renamed when they arrive; look it up once it settles.
    let condition = format!(
        "{} AND {SETTLED} AND videos_id = 0 AND tv_episodes_id IN ({})",
        tv_category_condition(),
        in_list(&statuses),
    );
    let due: i64 = sqlx::query_scalar(sqlx::AssertSqlSafe(format!(
        "SELECT COUNT(*) FROM releases WHERE {condition}"
    )))
    .fetch_one(&services.db)
    .await?;
    let mut report = ReprocessReport {
        due,
        ..ReprocessReport::default()
    };
    if due == 0 {
        println!("No unmatched TV releases found.");
        return Ok(report);
    }
    println!("Found {due} unmatched TV release(s).");
    let select = "SELECT CAST(id AS SIGNED) AS id, searchname, CAST(videos_id AS SIGNED) AS videos_id FROM releases";
    let order = "ORDER BY adddate DESC, postdate DESC";
    let process_count = if options.limit > 0 {
        options.limit.min(due)
    } else {
        due
    };
    if options.dry_run {
        println!("[Dry Run] Would process {process_count} unmatched TV release(s).");
        let releases = fetch_candidates(
            &services.db,
            format!(
                "{select} WHERE {condition} {order} LIMIT {}",
                process_count.min(10)
            ),
        )
        .await?;
        for release in releases {
            println!("{}\t{}", release.id, sample_name(&release.searchname, 60));
        }
        return Ok(report);
    }
    let limit = if options.limit > 0 {
        format!(" LIMIT {}", options.limit)
    } else {
        String::new()
    };
    let releases: Vec<Candidate> = fetch_candidates(
        &services.db,
        format!("{select} WHERE {condition} {order}{limit}"),
    )
    .await?;
    let pipeline = Pipeline::new(services);
    let mut state = RunState::default();
    for release in &releases {
        match pipeline.process_release(release, &mut state).await {
            Ok(
                Outcome::Matched { .. }
                | Outcome::SeasonPack { .. }
                | Outcome::EpisodeMissing { .. },
            ) => {
                report.matched += 1;
            }
            Ok(_) => report.failed += 1,
            Err(failure) => {
                report.failed += 1;
                error!(
                    release_id = release.id,
                    error = format!("{failure:#}"),
                    "couldn't process a release"
                );
            }
        }
        report.processed += 1;
        if options.sleep > 0 {
            tokio::time::sleep(Duration::from_millis(options.sleep)).await;
        }
    }
    Ok(report)
}

/// `tv:reprocess`: one release by GUID.
pub async fn reprocess_one(ctx: &Ctx, guid: &str, reset: bool) -> Result<()> {
    let services = Services::shared(ctx)?;
    let release = find_by_guid(&services.db, guid).await?;
    let Some(mut release) = release else {
        bail!("Release with GUID '{guid}' not found.");
    };
    println!(
        "Found release: {} {} videos_id={}",
        release.id,
        sample_name(&release.searchname, 60),
        release.videos_id
    );
    if reset {
        reset_release(&services.db, release.id).await?;
        release.videos_id = 0;
    }
    let pipeline = Pipeline::new(services.clone());
    let outcome = pipeline
        .process_release(&release, &mut RunState::default())
        .await?;
    println!("Status: {}", outcome.status());
    if let Some(video_id) = outcome.video_id() {
        println!("Video ID: {video_id}");
    }
    if let Outcome::Matched {
        episode_id,
        provider,
        ..
    } = &outcome
    {
        println!("Provider: {provider}\nEpisode ID: {episode_id}");
    }
    let state = sqlx::query(
        "SELECT CAST(videos_id AS SIGNED) AS videos_id, CAST(tv_episodes_id AS SIGNED) AS episode, \
         CAST(tv_lookup_attempts AS SIGNED) AS attempts, CAST(tv_lookup_retry_at AS CHAR) AS retry \
         FROM releases WHERE id = ?",
    )
    .bind(release.id)
    .fetch_one(&services.db)
    .await?;
    println!(
        "Final state: videos_id={} tv_episodes_id={} attempts={} retry_at={}",
        state.try_get::<i64, _>("videos_id")?,
        state.try_get::<i64, _>("episode")?,
        state.try_get::<i64, _>("attempts")?,
        state
            .try_get::<Option<String>, _>("retry")?
            .unwrap_or_else(|| "-".to_owned()),
    );
    Ok(())
}

pub async fn find_by_guid(db: &MySqlPool, guid: &str) -> Result<Option<Candidate>> {
    let row = sqlx::query(
        "SELECT CAST(id AS SIGNED) AS id, searchname, CAST(videos_id AS SIGNED) AS videos_id \
         FROM releases WHERE guid = ?",
    )
    .bind(guid)
    .fetch_optional(db)
    .await?;
    row.map(|row| {
        Ok(Candidate {
            id: row.try_get("id")?,
            searchname: row.try_get("searchname")?,
            videos_id: row.try_get("videos_id")?,
        })
    })
    .transpose()
}

/// The columns that hand a release back to TV processing from scratch.
pub async fn reset_release(db: &MySqlPool, release_id: i64) -> Result<()> {
    let mut update: QueryBuilder<MySql> = QueryBuilder::new("UPDATE releases SET ");
    update
        .push(RESET_SET)
        .push(" WHERE id = ")
        .push_bind(release_id);
    update.build().execute(db).await?;
    Ok(())
}

/// `tv:reprocess-unmatched`.
pub async fn tv_reprocess_unmatched(ctx: &Ctx, options: TvUnmatchedOptions) -> Result<()> {
    let services = Services::shared(ctx)?;
    let dry_run = options.dry_run;
    let report = reprocess_unmatched(services, &options).await?;
    if !dry_run && report.due > 0 {
        report.print("matched", "not matched");
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn episode_one_matching() {
        let parsed = parse_release("Show.S02E03.WEB");
        assert!(matches_episode(parsed.as_ref(), 2, 3, None));
        assert!(!matches_episode(parsed.as_ref(), 2, 4, None));
        let dated = parse_release("Show.2020.01.02.WEB");
        assert!(matches_episode(dated.as_ref(), 0, 0, Some("2020-01-02")));
        assert!(!matches_episode(dated.as_ref(), 0, 0, Some("2020-01-03")));
        assert!(!matches_episode(None, 0, 0, None));
    }

    #[test]
    fn sample_names_shorten() {
        assert_eq!(sample_name("abc", 3), "abc");
        assert_eq!(sample_name("abcd", 3), "abc...");
    }
}
