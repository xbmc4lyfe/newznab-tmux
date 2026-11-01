//! Fetching a range of article headers and storing it: `BinariesService`.

use std::collections::{BTreeSet, HashMap, HashSet};
use std::time::{Duration, Instant};

use anyhow::{Result, anyhow, bail};
use chrono::{DateTime, NaiveDateTime, Utc};
use nntmux_core::pcre::Pcre;
use nntmux_runtime::Shutdown;
use rand::Rng;
use sqlx::MySqlPool;
use tracing::{debug, error, info, warn};

use crate::blacklist::{Blacklist, record_usage};
use crate::clean::CollectionRegex;
use crate::config::{IngestConfig, capacity_available};
use crate::groups::Group;
use crate::header::{
    ArticleRange, Overview, article_range, header_time, mark_per_article_obfuscated, match_subject,
    parse_date,
};
use crate::missed::MissedParts;
use crate::nntp::{NO_SUCH_ARTICLE, Nntp, SelectedGroup};
use crate::store::{ParsedHeader, StoreHooks, StoreLimits, store};

/// How long a role reuses a group's regexes, so edits reach it within about a minute.
const REGEX_REFRESH: Duration = Duration::from_secs(60);
/// The bounded overview windows that look for the nearest existing article.
const NEAREST_SPANS: [u64; 3] = [100, 1000, 10000];
const MAX_DATE_PROBES: u32 = 64;
const DATE_UNAVAILABLE: &str = "NNTP article date is unavailable; defer date-based indexing.";

/// Why a range is being fetched.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum ScanKind {
    Update,
    Backfill,
    PartRepair,
}

/// The result of one range.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ScanOutcome {
    /// The articles received inside the range.
    pub summary: Option<ArticleRange>,
    /// Set when the range was fully fetched and stored, or durably queued for repair.
    pub completed_end: Option<u64>,
}

/// The article range an update wants from a group.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct UpdateRange {
    pub first: i64,
    pub last: i64,
    pub group_last: i64,
    pub total: i64,
}

/// A new group starts `new_group_messages_to_scan` back, or at a given article when scanning by
/// days, and leaves the newest `message_buffer` articles for the next pass.
pub fn new_group_range(
    config: &IngestConfig,
    server: &SelectedGroup,
    day_target: Option<i64>,
    max_headers: i64,
) -> UpdateRange {
    let buffer = to_i64(config.message_buffer);
    let scan = to_i64(config.new_group_messages_to_scan);
    let (server_first, server_last) = (to_i64(server.first), to_i64(server.last));
    let first = day_target.unwrap_or_else(|| {
        if server_first >= server_last - (scan + buffer) {
            server_first
        } else {
            server_last - (scan + buffer)
        }
    });
    let mut last = server_last - buffer;
    if last < first {
        last = first;
    }
    let mut range = UpdateRange {
        first,
        last,
        group_last: last,
        total: last - first,
    };
    if max_headers > 0 && range.total > max_headers {
        range.last = range.first + max_headers;
        range.group_last = range.last;
        range.total = max_headers;
    }
    range
}

/// An existing group resumes at its cursor and leaves some articles for the next pass, since the
/// newest articles often arrive out of order.
pub fn existing_group_range(
    config: &IngestConfig,
    last_record: u64,
    server_last: u64,
    max_headers: i64,
) -> UpdateRange {
    let buffer = to_i64(config.message_buffer).max(1);
    let first = to_i64(last_record);
    let server_last = to_i64(server_last);
    let total_count = server_last - first;
    let leave_over = if total_count > buffer * 2 {
        total_count % buffer + buffer
    } else {
        // Halves round toward zero.
        total_count / 2
    };
    let mut last = server_last - leave_over;
    if last < first {
        last = first;
    }
    let mut group_last = last;
    if max_headers > 0 && max_headers < group_last - first {
        group_last = first + max_headers;
        last = group_last;
    }
    UpdateRange {
        first,
        last,
        group_last,
        total: group_last - first,
    }
}

fn to_i64(value: u64) -> i64 {
    i64::try_from(value).unwrap_or(i64::MAX)
}

/// Splits sorted missing numbers into ranges small enough for one overview request.
pub fn repair_ranges(missing: &[u64], message_buffer: u64) -> Vec<Vec<u64>> {
    let mut ranges: Vec<Vec<u64>> = Vec::new();
    let mut current: Vec<u64> = Vec::new();
    for &number in missing {
        if let Some(&first) = current.first() {
            // A float division in PHP: at a buffer of 5, a distance of 1 fits and 2 doesn't.
            if ((number - first) * 4) > message_buffer {
                ranges.push(std::mem::take(&mut current));
            }
        }
        current.push(number);
    }
    if !current.is_empty() {
        ranges.push(current);
    }
    ranges
}

/// The midpoint rounded up, so a search with adjacent bounds reaches a bound and stops.
pub fn article_midpoint(minimum: u64, maximum: u64) -> u64 {
    let distance = maximum - minimum;
    minimum + distance / 2 + distance % 2
}

/// One pass of header work over a connection. Not shared between tasks.
pub struct Ingest<'a> {
    pub pool: &'a MySqlPool,
    pub config: &'a IngestConfig,
    pub hooks: &'a dyn StoreHooks,
    pub nntp: &'a mut dyn Nntp,
    pub stop: Option<Shutdown>,
    missed: MissedParts,
    blacklist: Blacklist,
    regexes: HashMap<String, (Instant, Vec<CollectionRegex>)>,
}

impl<'a> Ingest<'a> {
    pub fn new(
        pool: &'a MySqlPool,
        config: &'a IngestConfig,
        hooks: &'a dyn StoreHooks,
        nntp: &'a mut dyn Nntp,
        stop: Option<Shutdown>,
    ) -> Self {
        let missed = MissedParts::new(
            config.part_repair_limit,
            config.part_repair_max_tries,
            config.sql_chunk_size,
        );
        Self {
            pool,
            config,
            hooks,
            nntp,
            stop,
            missed,
            blacklist: Blacklist::default(),
            regexes: HashMap::new(),
        }
    }

    pub fn missed(&self) -> MissedParts {
        self.missed
    }

    /// True once operators or a deploy asked processing to pause, or this process must stop.
    pub async fn stopping(&self) -> Result<bool> {
        if self.stop.as_ref().is_some_and(Shutdown::requested) {
            return Ok(true);
        }
        let stop: Option<bool> =
            sqlx::query_scalar("SELECT stop_requested FROM processing_runtime_states WHERE id = 1")
                .fetch_optional(self.pool)
                .await?;
        Ok(stop.unwrap_or(false))
    }

    /// Tells the downtime compensator that ingestion is live. A failed note never fails ingestion.
    async fn note_ingestion(&self) {
        if let Err(failure) = nntmux_runtime::downtime::note_ingestion_on(self.pool).await {
            debug!(
                error = format!("{failure:#}"),
                "unable to record ingestion activity"
            );
        }
    }

    pub async fn mark_binary_run(&self) -> Result<()> {
        sqlx::query(
            "UPDATE processing_runtime_states SET last_binary_run_at = ?, updated_at = ? WHERE id = 1",
        )
        .bind(Utc::now().naive_utc())
        .bind(Utc::now().naive_utc())
        .execute(self.pool)
        .await?;
        Ok(())
    }

    /// Selects a group, reconnecting once if the first try fails.
    pub async fn select_group(&mut self, name: &str) -> Option<SelectedGroup> {
        match self.nntp.select_group(name).await {
            Ok(selected) => return Some(selected),
            Err(failure) => warn!(group = name, error = %failure, "couldn't select the group"),
        }
        if let Err(failure) = self.nntp.reconnect(true).await {
            warn!(group = name, error = %failure, "couldn't reconnect to usenet");
            return None;
        }
        match self.nntp.select_group(name).await {
            Ok(selected) => Some(selected),
            Err(failure) => {
                warn!(group = name, error = %failure, "skipping the group");
                None
            }
        }
    }

    async fn group_regexes(&mut self, group: &str) -> Result<Vec<CollectionRegex>> {
        if let Some((read, regexes)) = self.regexes.get(group)
            && read.elapsed() < REGEX_REFRESH
        {
            return Ok(regexes.clone());
        }
        let regexes = load_regexes(self.pool, group).await?;
        self.regexes
            .insert(group.to_owned(), (Instant::now(), regexes.clone()));
        Ok(regexes)
    }

    /// Downloads new headers for one group, repairing missing ones first.
    pub async fn update_group(&mut self, group: &Group, max_headers: u64) -> Result<()> {
        if !capacity_available(self.pool, self.config).await? {
            return Ok(());
        }
        self.note_ingestion().await;
        self.mark_binary_run().await?;
        let Some(server) = self.select_group(&group.name).await else {
            return Ok(());
        };
        let mut group = group.clone();
        if group.last_record != 0 && self.config.part_repair {
            self.part_repair(&group).await?;
        }
        // Generate a postdate for the first record, for groups from before the column existed.
        if group.first_record_postdate.is_none() && group.first_record != 0 {
            let date = self.postdate(group.first_record, &server).await;
            let date = naive(date);
            group.first_record_postdate = Some(date);
            sqlx::query("UPDATE usenet_groups SET first_record_postdate = ? WHERE id = ?")
                .bind(date)
                .bind(group.id)
                .execute(self.pool)
                .await?;
        }

        let max = to_i64(max_headers);
        let range = if group.last_record == 0 {
            let day_target = if self.config.new_group_scan_by_days {
                Some(to_i64(
                    self.day_to_post(self.config.new_group_days_to_scan, &server)
                        .await?,
                ))
            } else {
                None
            };
            new_group_range(self.config, &server, day_target, max)
        } else {
            existing_group_range(self.config, group.last_record, server.last, max)
        };
        if range.total <= 0 {
            return Ok(());
        }
        self.process_range(&mut group, range).await
    }

    async fn process_range(&mut self, group: &mut Group, range: UpdateRange) -> Result<()> {
        let buffer = to_i64(self.config.message_buffer);
        let mut first = range.first;
        let mut last = range.last;
        loop {
            if self.stopping().await? {
                return Ok(());
            }
            if range.total > buffer {
                last = (first + buffer).min(range.group_last);
            }
            first += 1;
            let outcome = self
                .scan(
                    group,
                    u64::try_from(first)?,
                    u64::try_from(last)?,
                    ScanKind::Update,
                    None,
                )
                .await?;
            if outcome.summary.is_none() && outcome.completed_end.is_none() {
                return Ok(());
            }
            self.update_group_after_scan(group, &outcome, u64::try_from(last)?)
                .await?;
            if last == range.group_last {
                return Ok(());
            }
            first = last;
        }
    }

    async fn update_group_after_scan(
        &mut self,
        group: &mut Group,
        outcome: &ScanOutcome,
        last: u64,
    ) -> Result<()> {
        let now = Utc::now().naive_utc();
        let Some(summary) = &outcome.summary else {
            sqlx::query("UPDATE usenet_groups SET last_record = ?, last_updated = ? WHERE id = ?")
                .bind(last)
                .bind(now)
                .bind(group.id)
                .execute(self.pool)
                .await?;
            return Ok(());
        };
        // A new group's first record is the first article seen.
        if group.first_record_postdate.is_none() && group.first_record == 0 {
            let date = naive(date_or_now(&summary.first_date));
            group.first_record = summary.first_number;
            group.first_record_postdate = Some(date);
            sqlx::query(
                "UPDATE usenet_groups SET first_record = ?, first_record_postdate = ? WHERE id = ?",
            )
            .bind(summary.first_number)
            .bind(date)
            .bind(group.id)
            .execute(self.pool)
            .await?;
        }
        let end = outcome.completed_end.unwrap_or(summary.last_number);
        sqlx::query(
            "UPDATE usenet_groups SET last_record = ?, last_record_postdate = ?, last_updated = ? WHERE id = ?",
        )
        .bind(end)
        .bind(naive(date_or_now(&summary.last_date)))
        .bind(now)
        .bind(group.id)
        .execute(self.pool)
        .await?;
        group.last_record = end;
        Ok(())
    }

    /// Fetches and stores one range. A capacity failure defers the range and returns an error.
    pub async fn scan(
        &mut self,
        group: &Group,
        first: u64,
        last: u64,
        kind: ScanKind,
        missing: Option<&[u64]>,
    ) -> Result<ScanOutcome> {
        let part_repair = kind == ScanKind::PartRepair;
        let admission = match capacity_available(self.pool, self.config).await {
            Ok(true) => None,
            Ok(false) => Some(anyhow!(
                "Verification backlog is full; scan paused before downloading headers."
            )),
            Err(failure) => Some(failure),
        };
        if let Some(failure) = admission {
            if !part_repair
                && let Err(deferral) = self
                    .missed
                    .defer_range(self.pool, first, last, group.id)
                    .await
            {
                bail!(
                    "Unable to defer headers after capacity admission failed: {failure:#}; {deferral:#}"
                );
            }
            return Err(failure);
        }

        let Some(headers) = self.download(&group.name, first, last, part_repair).await? else {
            if part_repair {
                self.missed
                    .increment_range(self.pool, group.id, first, last)
                    .await?;
            }
            return Ok(ScanOutcome::default());
        };
        if headers.is_empty() {
            return Ok(ScanOutcome {
                summary: None,
                completed_end: Some(last),
            });
        }
        let summary = article_range(&headers, first, last);

        // Detect across the whole range: one chunk can hold too few of an upload's articles. A
        // sparse repair lists scattered articles of real uploads, so it's exempt. A repair listing
        // every number of its range is a range deferred before download, or lost whole, and is
        // filtered like the scan it replaces.
        let sparse =
            part_repair && missing.is_none_or(|list| list.len() as u64 != last - first + 1);
        let threshold = if sparse {
            0
        } else {
            self.config.obfuscation_min_articles
        };
        let obfuscated = mark_per_article_obfuscated(&headers, threshold);
        if !obfuscated.is_empty() {
            info!(
                group = group.id,
                first,
                last,
                skipped = obfuscated.len(),
                received = headers.len(),
                "skipping articles obfuscated per article"
            );
        }

        let wanted: Option<HashSet<u64>> = missing
            .filter(|_| part_repair)
            .map(|list| list.iter().copied().collect());
        let regexes = self.group_regexes(&group.name).await?;
        let limits = StoreLimits {
            header_chunk_size: self.config.header_chunk_size,
            sql_chunk_size: self.config.sql_chunk_size,
            verification_required: self.config.verification_required,
        };
        let mut received = HashSet::with_capacity(headers.len());
        let mut repaired = BTreeSet::new();
        let mut not_inserted = BTreeSet::new();
        for chunk in headers.chunks(self.config.header_chunk_size.max(1)) {
            let mut matched_rules = BTreeSet::new();
            let mut parsed = Vec::with_capacity(chunk.len());
            let lists = self.blacklist.lists(self.pool, &group.name).await?.clone();
            for header in chunk {
                received.insert(header.number);
                if let Some(wanted) = &wanted {
                    if !wanted.contains(&header.number) {
                        continue;
                    }
                    repaired.insert(header.number);
                }
                if obfuscated.contains(&header.number) {
                    continue;
                }
                let Some(mut subject) = match_subject(&header.subject) else {
                    continue;
                };
                if !contains_yenc(&header.subject) {
                    subject.base.extend_from_slice(b" yEnc");
                }
                if lists.is_blacklisted(header, &mut matched_rules) {
                    continue;
                }
                parsed.push(ParsedHeader {
                    overview: header.clone(),
                    subject,
                });
            }
            record_usage(self.pool, &matched_rules).await?;
            if !parsed.is_empty() {
                let failed = store(self.pool, self.hooks, limits, group, &regexes, &parsed).await;
                self.note_ingestion().await;
                match failed {
                    Ok(failed) => not_inserted.extend(failed),
                    Err(failure) => {
                        error!(
                            group = group.id,
                            articles = parsed.len(),
                            error = format!("{failure:#}"),
                            "binary header storage failed"
                        );
                        not_inserted.extend(parsed.iter().map(|header| header.overview.number));
                    }
                }
            }
        }
        drop(headers);

        if !not_inserted.is_empty() && !self.config.part_repair && !part_repair {
            bail!("Header storage did not complete and part repair is disabled; retry this range.");
        }
        if part_repair && !repaired.is_empty() {
            let done: Vec<u64> = repaired.difference(&not_inserted).copied().collect();
            self.missed.remove(self.pool, &done, group.id).await;
        }
        let failed: Vec<u64> = not_inserted.into_iter().collect();
        if kind == ScanKind::Update && self.config.part_repair {
            if !failed.is_empty() {
                warn!(
                    group = group.id,
                    articles = failed.len(),
                    "articles failed to insert"
                );
                self.missed.add(self.pool, &failed, group.id).await?;
            }
            self.queue_not_received(group.id, first, last, &received)
                .await?;
        } else if kind == ScanKind::Backfill && self.config.part_repair && !failed.is_empty() {
            // Headers lost to a storage rollback during backfill get repaired too.
            self.missed.add(self.pool, &failed, group.id).await?;
        }
        Ok(ScanOutcome {
            summary,
            completed_end: Some(last),
        })
    }

    /// Queues requested articles the server didn't return, in bounded batches.
    async fn queue_not_received(
        &self,
        group: u32,
        first: u64,
        last: u64,
        received: &HashSet<u64>,
    ) -> Result<()> {
        let mut missing = Vec::with_capacity(self.config.sql_chunk_size);
        for number in first..=last {
            if received.contains(&number) {
                continue;
            }
            missing.push(number);
            if missing.len() >= self.config.sql_chunk_size {
                self.missed.add(self.pool, &missing, group).await?;
                missing.clear();
            }
        }
        self.missed.add(self.pool, &missing, group).await
    }

    /// None when the server couldn't provide the range. A 423 confirms the window is empty.
    async fn download(
        &mut self,
        group: &str,
        first: u64,
        last: u64,
        part_repair: bool,
    ) -> Result<Option<Vec<Overview>>> {
        match self.nntp.xover(first, last).await {
            Ok(headers) => return Ok(Some(headers)),
            Err(failure) if failure.code == Some(NO_SUCH_ARTICLE) => return Ok(Some(Vec::new())),
            Err(_) if part_repair => return Ok(None),
            Err(_) => {}
        }
        // Retry without compression.
        if self.nntp.reconnect(false).await.is_err() || self.nntp.select_group(group).await.is_err()
        {
            return Ok(None);
        }
        let retried = self.nntp.xover(first, last).await;
        if let Err(failure) = self.nntp.enable_compression().await {
            warn!(group, error = %failure, "couldn't turn header compression back on");
        }
        match retried {
            Ok(headers) => Ok(Some(headers)),
            Err(failure) if failure.code == Some(NO_SUCH_ARTICLE) => Ok(Some(Vec::new())),
            Err(failure) => {
                warn!(group, error = %failure, "skipping the group");
                Ok(None)
            }
        }
    }

    /// Tries again to fetch this group's missing articles.
    pub async fn part_repair(&mut self, group: &Group) -> Result<()> {
        if self.stopping().await? {
            return Ok(());
        }
        let missing = self.missed.pending(self.pool, group.id).await?;
        if missing.is_empty() {
            return self.missed.remove_exhausted(self.pool, group.id).await;
        }
        let mut last_attempted = None;
        for range in repair_ranges(&missing, self.config.message_buffer) {
            if self.stopping().await? {
                break;
            }
            let (first, last) = (range[0], range[range.len() - 1]);
            self.scan(group, first, last, ScanKind::PartRepair, Some(&range))
                .await?;
            last_attempted = Some(last);
        }
        let Some(last) = last_attempted else {
            return Ok(());
        };
        self.missed
            .increment_up_to(self.pool, group.id, last)
            .await?;
        self.missed.remove_exhausted(self.pool, group.id).await
    }

    /// The Unix time of an article: from a stored part if there is one, or else the server,
    /// trying neighbouring numbers. Falls back to now.
    pub async fn postdate(&mut self, post: u64, server: &SelectedGroup) -> i64 {
        let mut current = post;
        for _ in 0..=21 {
            // `ix_parts_number` makes this an index lookup. Any matching collection will do.
            let local: Result<Option<Option<i64>>, sqlx::Error> = sqlx::query_scalar(
                "SELECT CAST(UNIX_TIMESTAMP(c.date) AS SIGNED) FROM collections c
                 INNER JOIN binaries b ON c.id = b.collections_id
                 INNER JOIN parts p ON b.id = p.binaries_id
                 WHERE p.number = ? LIMIT 1",
            )
            .bind(current)
            .fetch_optional(self.pool)
            .await;
            if let Ok(Some(date)) = local {
                return date.unwrap_or_else(|| Utc::now().timestamp());
            }
            if let Ok(headers) = self.nntp.xover(current, current).await
                && let Some(header) = headers.first()
                && !header.date.is_empty()
            {
                return date_or_now(&header.date);
            }
            match next_article_to_try(current, server) {
                Some(next) => current = next,
                None => break,
            }
        }
        Utc::now().timestamp()
    }

    /// The article number closest to `days` ago, by binary search on article dates.
    pub async fn day_to_post(&mut self, days: i64, server: &SelectedGroup) -> Result<u64> {
        if server.first < 1 || server.last < 1 || server.first > server.last {
            bail!("Invalid NNTP article bounds for date targeting.");
        }
        if server.name.is_empty() {
            bail!("Cannot resolve a date target without an NNTP group.");
        }
        self.nntp
            .select_group(&server.name)
            .await
            .map_err(|_| anyhow!("Cannot select the NNTP group for date targeting."))?;
        let goal = Utc::now().timestamp() - days * 86_400;

        // The server's watermarks often name no article, so the bounds use the nearest existing
        // article inside them.
        let Some((first, first_date)) = self.nearest_postdate(server.first, server.last).await?
        else {
            bail!(DATE_UNAVAILABLE);
        };
        if goal < first_date {
            return Ok(first);
        }
        let Some((last, last_date)) = self.nearest_postdate(server.last, first).await? else {
            bail!(DATE_UNAVAILABLE);
        };
        if goal > last_date {
            return Ok(last);
        }
        self.search_article_by_date(goal, first, last).await
    }

    async fn search_article_by_date(&mut self, goal: i64, first: u64, last: u64) -> Result<u64> {
        let mut wanted = article_midpoint(first, last);
        let (mut low, mut high) = (first, last);
        let mut old: Option<u64> = None;
        let mut probes = MAX_DATE_PROBES;
        loop {
            // Adjacent bounds are fully resolved.
            if wanted <= first || wanted >= last || wanted <= low || wanted >= high {
                return Ok(wanted);
            }
            let really_old = old;
            old = Some(wanted);
            if probes == 0 {
                bail!("NNTP date target did not converge; defer date-based indexing.");
            }
            probes -= 1;
            // low < wanted < high, so the nearest-article search only moves upward.
            let Some((article, time)) = self.nearest_postdate(wanted, high - 1).await? else {
                // No article from the probe to the upper bound: the boundary is below the probe.
                high = wanted;
                wanted = article_midpoint(low, wanted);
                continue;
            };
            old = Some(article);
            wanted = article;
            if time < goal {
                low = article;
                wanted = article_midpoint(article, high);
            } else if time > goal {
                high = article;
                wanted = article_midpoint(low, article);
            } else {
                return Ok(wanted);
            }
            if really_old == Some(wanted) && goal - time <= 0 {
                return Ok(wanted);
            }
        }
    }

    /// The number and date of the existing article nearest `post`, toward `limit` inclusive.
    ///
    /// Article numbers have gaps. Only a number the server confirms missing is replaced, with
    /// overview ranges of growing size, so no number receives another article's date. None when
    /// no article exists up to `limit`. Any other failure, or an unknown date, defers.
    async fn nearest_postdate(&mut self, post: u64, limit: u64) -> Result<Option<(u64, i64)>> {
        if let Some(date) = self.target_postdate(post).await? {
            return Ok(Some((post, date)));
        }
        let upward = limit >= post;
        let mut from = post;
        for span in NEAREST_SPANS {
            if from == limit {
                return Ok(None);
            }
            from = if upward { from + 1 } else { from - 1 };
            let to = if upward {
                if limit - from < span {
                    limit
                } else {
                    from + span - 1
                }
            } else if from - limit < span {
                limit
            } else {
                from - span + 1
            };
            let (low, high) = if upward { (from, to) } else { (to, from) };
            if let Some(found) = self.nearest_overview_row(low, high, upward).await? {
                return Ok(Some(found));
            }
            from = to;
        }
        if from == limit {
            return Ok(None);
        }
        bail!(DATE_UNAVAILABLE)
    }

    /// The nearest article is chosen by number before its date is read, so an existing article
    /// with an unknown date defers instead of being skipped.
    async fn nearest_overview_row(
        &mut self,
        low: u64,
        high: u64,
        upward: bool,
    ) -> Result<Option<(u64, i64)>> {
        let rows = match self.nntp.xover(low, high).await {
            Ok(rows) => rows,
            Err(failure) if failure.no_article() => return Ok(None),
            Err(_) => bail!(DATE_UNAVAILABLE),
        };
        let mut nearest: Option<&Overview> = None;
        for row in &rows {
            if row.number < low || row.number > high {
                bail!(DATE_UNAVAILABLE);
            }
            let closer = nearest.is_none_or(|current| {
                if upward {
                    row.number < current.number
                } else {
                    row.number > current.number
                }
            });
            if closer {
                nearest = Some(row);
            }
        }
        let Some(row) = nearest else {
            return Ok(None);
        };
        let date = std::str::from_utf8(&row.date).ok().and_then(parse_date);
        match date {
            Some(date) => Ok(Some((row.number, date))),
            None => bail!(DATE_UNAVAILABLE),
        }
    }

    /// Admission needs the requested article's own date. Stored part numbers and neighbouring
    /// probes can't establish that. None only when the server confirms no such article.
    async fn target_postdate(&mut self, post: u64) -> Result<Option<i64>> {
        let rows = match self.nntp.xover(post, post).await {
            Ok(rows) => rows,
            Err(failure) if failure.no_article() => return Ok(None),
            Err(_) => bail!(DATE_UNAVAILABLE),
        };
        let Some(row) = rows.first() else {
            return Ok(None);
        };
        let date = std::str::from_utf8(&row.date).ok().and_then(parse_date);
        match date {
            Some(date) if row.number == post => Ok(Some(date)),
            _ => bail!(DATE_UNAVAILABLE),
        }
    }
}

/// A nearby number to try for a date, moving toward the far bound. None if it wouldn't move.
fn next_article_to_try(current: u64, server: &SelectedGroup) -> Option<u64> {
    let factor = f64::from(rand::rng().random_range(1005_u32..=1012)) / 1000.0;
    let to_first = current.abs_diff(server.first);
    let to_last = current.abs_diff(server.last);
    let next = if to_first > to_last {
        let moved = (current as f64 / factor).round() as u64;
        moved.max(server.first)
    } else {
        let moved = (current as f64 * factor).round() as u64;
        moved.min(server.last)
    };
    (next != current).then_some(next)
}

fn contains_yenc(subject: &[u8]) -> bool {
    subject
        .windows(4)
        .any(|window| window.eq_ignore_ascii_case(b"yEnc"))
}

/// A summary date as a timestamp. PHP fell back to the current time when it couldn't read one.
fn date_or_now(date: &[u8]) -> i64 {
    std::str::from_utf8(date)
        .ok()
        .and_then(parse_date)
        .unwrap_or_else(|| Utc::now().timestamp())
}

fn naive(timestamp: i64) -> NaiveDateTime {
    DateTime::from_timestamp(timestamp, 0)
        .unwrap_or_else(Utc::now)
        .naive_utc()
}

/// A range's date for `articles:get-range`, which also accepted an all-digit time.
pub fn summary_time(date: &[u8]) -> Option<i64> {
    header_time(date)
}

/// The stored regexes for a group, in the order they're tried. PHP threw on one that wouldn't
/// compile, which stopped the worker on the first header, so here it's logged and skipped.
pub async fn load_regexes(pool: &MySqlPool, group: &str) -> Result<Vec<CollectionRegex>> {
    let rows: Vec<(u32, String)> = sqlx::query_as(
        "SELECT r.id, r.regex FROM collection_regexes r
         WHERE ? REGEXP r.group_regex AND r.status = 1
         ORDER BY r.ordinal ASC, r.group_regex ASC",
    )
    .bind(group)
    .fetch_all(pool)
    .await?;
    Ok(rows
        .into_iter()
        .filter_map(|(id, regex)| match Pcre::new(&regex) {
            Ok(regex) => Some(CollectionRegex {
                id: i32::try_from(id).unwrap_or(i32::MAX),
                regex,
            }),
            Err(failure) => {
                warn!(
                    id,
                    error = format!("{failure:#}"),
                    "skipping a collection regex"
                );
                None
            }
        })
        .collect())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn config(buffer: u64, scan: u64) -> IngestConfig {
        IngestConfig {
            message_buffer: buffer,
            part_repair: true,
            safe_part_repair: false,
            new_group_scan_by_days: false,
            new_group_messages_to_scan: scan,
            new_group_days_to_scan: 3,
            part_repair_limit: 15000,
            part_repair_max_tries: 3,
            binary_threads: 1,
            backfill_threads: 1,
            release_threads: 1,
            max_headers_per_iteration: 0,
            safe_backfill_date: chrono::NaiveDate::from_ymd_opt(2012, 6, 24).unwrap(),
            disable_backfill_group: false,
            backfill_days_mode: 1,
            backfill_order: 2,
            backfill_quantity: 100000,
            header_chunk_size: 500,
            sql_chunk_size: 500,
            obfuscation_min_articles: 16,
            verification_required: false,
            verification: nntmux_verify::config::VerificationConfig::for_tests("/tmp".into()),
        }
    }

    fn server(first: u64, last: u64) -> SelectedGroup {
        SelectedGroup {
            name: "g".to_owned(),
            first,
            last,
        }
    }

    #[test]
    fn existing_groups_leave_a_remainder_for_the_next_pass() {
        let config = config(20000, 50000);
        // Under twice the buffer, half stays behind.
        let range = existing_group_range(&config, 1000, 2001, 0);
        assert_eq!((range.first, range.last, range.total), (1000, 1501, 501));
        // Over it, the remainder plus a whole buffer stays behind.
        let range = existing_group_range(&config, 0, 100_500, 0);
        assert_eq!(range.last, 100_500 - (500 + 20000));
        // Nothing new.
        let range = existing_group_range(&config, 2000, 1000, 0);
        assert_eq!((range.first, range.last, range.total), (2000, 2000, 0));
        // A cap applies from the cursor.
        let range = existing_group_range(&config, 0, 100_500, 10);
        assert_eq!((range.last, range.group_last, range.total), (10, 10, 10));
    }

    #[test]
    fn new_groups_start_back_from_the_newest_article() {
        let config = config(100, 50);
        let range = new_group_range(&config, &server(1, 1000), None, 0);
        assert_eq!((range.first, range.last, range.total), (850, 900, 50));
        // Fewer articles than that start at the oldest.
        let range = new_group_range(&config, &server(950, 1000), None, 0);
        assert_eq!((range.first, range.last, range.total), (950, 950, 0));
        let range = new_group_range(&config, &server(1, 1000), None, 10);
        assert_eq!((range.first, range.last, range.total), (850, 860, 10));
        let range = new_group_range(&config, &server(1, 1000), Some(500), 0);
        assert_eq!((range.first, range.last, range.total), (500, 900, 400));
    }

    #[test]
    fn repair_ranges_split_at_a_quarter_of_the_buffer() {
        assert_eq!(
            repair_ranges(&[1, 2, 25, 26, 27, 100], 100),
            vec![vec![1, 2, 25, 26], vec![27], vec![100]]
        );
        assert!(repair_ranges(&[], 100).is_empty());
    }

    #[test]
    fn midpoint_rounds_up() {
        assert_eq!(article_midpoint(1, 2), 2);
        assert_eq!(article_midpoint(1, 3), 2);
        assert_eq!(article_midpoint(10, 10), 10);
    }
}
