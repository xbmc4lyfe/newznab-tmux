//! Matches TV releases to shows and episodes. Each release passes through the local database and
//! then the provider APIs (TVDB, TVMaze, TMDB, Trakt) until one resolves it, and the outcome is
//! written once at the end. Port of `TvProcessingPipeline` and its pipes.

pub mod adder;
pub mod commands;
pub mod parse;
pub mod providers;
pub mod store;

use std::collections::{HashMap, HashSet};
use std::sync::Arc;
use std::time::{Duration, Instant};

use anyhow::{Context, Result, bail};
use futures_util::{StreamExt, stream};
use nntmux_runtime::Ctx;
use sqlx::{MySql, MySqlPool, Row, Transaction};
use tracing::{error, info};

use crate::candidates::{
    TV_KNOWN_SHOW_TRANSIENT_DELAY, TV_MAX_EPISODE_ATTEMPTS, TV_MAX_TRANSIENT_ATTEMPTS,
    release_scope, tv_episode_delay, tv_status, tv_transient_delay, tv_work_condition,
};
use crate::http::TransientError;
use crate::services::Services;
use crate::settings::{LookupMode, MetadataSettings, Renamed};
use parse::{ParsedRelease, parse_release};
use providers::{TvProvider, default_providers, resolve_poster};

/// How long a show's episode list counts as fresh across workers.
const EPISODE_REFRESH_TTL: Duration = Duration::from_secs(3600);
/// Consecutive transient failures before a provider is skipped for the rest of the run.
const CIRCUIT_BREAKER_THRESHOLD: u32 = 3;
const LOCAL_DB: &str = "Local DB";

/// Memo shared by every release in one run: providers don't search the same unknown title or
/// download the same episode list twice, and a provider that keeps failing is skipped.
#[derive(Default)]
pub struct RunState {
    not_found: HashSet<(&'static str, String)>,
    refreshed: HashSet<String>,
    episode_lookups: HashSet<String>,
    consecutive_transient: HashMap<&'static str, u32>,
}

impl RunState {
    fn title_not_found(&self, provider: &'static str, title: &str) -> bool {
        self.not_found.contains(&(provider, title.to_lowercase()))
    }

    fn remember_title_not_found(&mut self, provider: &'static str, title: &str) {
        self.not_found.insert((provider, title.to_lowercase()));
    }

    fn first_episode_lookup(&mut self, provider: &str, video_id: i64, episode: &str) -> bool {
        self.episode_lookups
            .insert(format!("{provider}:{video_id}:{episode}"))
    }

    fn circuit_open(&self, provider: &str) -> bool {
        self.consecutive_transient
            .get(provider)
            .copied()
            .unwrap_or(0)
            >= CIRCUIT_BREAKER_THRESHOLD
    }
}

fn refresh_key(provider: &str, video_id: i64) -> String {
    format!("tv:eps-refreshed:{}:{video_id}", provider.to_lowercase())
}

/// One release as the pipeline needs it.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Candidate {
    pub id: i64,
    pub searchname: String,
    pub videos_id: i64,
}

/// The outcome of a release passing through the pipeline.
#[derive(Clone, Debug, PartialEq)]
pub enum Outcome {
    Matched {
        video_id: i64,
        episode_id: i64,
        provider: String,
    },
    SeasonPack {
        video_id: i64,
        provider: String,
    },
    EpisodeMissing {
        video_id: i64,
        provider: Option<String>,
        transient: bool,
        retry_after: Option<i64>,
    },
    Transient {
        providers: String,
        retry_after: Option<i64>,
    },
    NotFound,
    ParseFailed,
}

impl Outcome {
    pub fn status(&self) -> &'static str {
        match self {
            Self::Matched { .. } => "matched",
            Self::SeasonPack { .. } => "season_pack",
            Self::EpisodeMissing { .. } => "episode_missing",
            Self::Transient { .. } => "transient",
            Self::NotFound => "not_found",
            Self::ParseFailed => "parse_failed",
        }
    }

    pub fn video_id(&self) -> Option<i64> {
        match self {
            Self::Matched { video_id, .. }
            | Self::SeasonPack { video_id, .. }
            | Self::EpisodeMissing { video_id, .. } => Some(*video_id),
            _ => None,
        }
    }
}

/// What the pipes learned about one release.
#[derive(Default)]
struct Passable {
    parsed: Option<ParsedRelease>,
    video_id: i64,
    show_provider: Option<String>,
    episode_id: i64,
    season_pack: bool,
    matched_by: Option<String>,
    transient: Vec<String>,
    max_retry_after: Option<i64>,
}

impl Passable {
    fn new(candidate: &Candidate) -> Self {
        // A release already linked to a show keeps that show.
        let video_id = candidate.videos_id.max(0);
        Self {
            video_id,
            show_provider: (video_id > 0).then(|| "existing".to_owned()),
            ..Self::default()
        }
    }

    fn resolved(&self) -> bool {
        self.episode_id > 0 || self.season_pack
    }

    fn mark_transient(&mut self, provider: &str, retry_after: Option<i64>) {
        self.transient.push(provider.to_owned());
        if let Some(retry_after) = retry_after {
            self.max_retry_after = Some(self.max_retry_after.unwrap_or(0).max(retry_after));
        }
    }

    fn outcome(&self) -> Outcome {
        if self.parsed.is_none() {
            return Outcome::ParseFailed;
        }
        let matched_by = self.matched_by.clone().unwrap_or_default();
        if self.episode_id > 0 {
            return Outcome::Matched {
                video_id: self.video_id,
                episode_id: self.episode_id,
                provider: matched_by,
            };
        }
        if self.season_pack && self.video_id > 0 {
            return Outcome::SeasonPack {
                video_id: self.video_id,
                provider: matched_by,
            };
        }
        let transient = !self.transient.is_empty();
        if self.video_id > 0 {
            return Outcome::EpisodeMissing {
                video_id: self.video_id,
                provider: self.show_provider.clone(),
                transient,
                retry_after: self.max_retry_after,
            };
        }
        if transient {
            let mut seen = HashSet::new();
            let providers: Vec<&str> = self
                .transient
                .iter()
                .map(String::as_str)
                .filter(|provider| seen.insert(*provider))
                .collect();
            return Outcome::Transient {
                providers: providers.join(","),
                retry_after: self.max_retry_after,
            };
        }
        Outcome::NotFound
    }
}

#[derive(Debug, Default, PartialEq, Eq)]
pub struct Stats {
    pub processed: u64,
    pub matched: u64,
    pub episode_missing: u64,
    pub not_found: u64,
    pub deferred: u64,
    pub providers: HashMap<String, u64>,
}

impl Stats {
    fn record(&mut self, outcome: &Outcome) {
        self.processed += 1;
        match outcome {
            Outcome::Matched { provider, .. } | Outcome::SeasonPack { provider, .. } => {
                self.matched += 1;
                *self.providers.entry(provider.clone()).or_default() += 1;
            }
            Outcome::EpisodeMissing { .. } => self.episode_missing += 1,
            Outcome::Transient { .. } => self.deferred += 1,
            Outcome::NotFound | Outcome::ParseFailed => self.not_found += 1,
        }
    }
}

pub struct Pipeline {
    pub services: Arc<Services>,
    providers: Vec<Box<dyn TvProvider>>,
}

impl Pipeline {
    pub fn new(services: Arc<Services>) -> Self {
        let providers = default_providers(&services);
        Self {
            services,
            providers,
        }
    }

    pub fn with_providers(services: Arc<Services>, providers: Vec<Box<dyn TvProvider>>) -> Self {
        Self {
            services,
            providers,
        }
    }

    /// Processes one release and persists the outcome. A broken release doesn't abort a batch;
    /// the attempt cap stops it looping forever.
    pub async fn process_release(
        &self,
        candidate: &Candidate,
        state: &mut RunState,
    ) -> Result<Outcome> {
        let mut passable = Passable::new(candidate);
        if let Err(failure) = self.run_pipes(candidate, &mut passable, state).await {
            error!(
                release_id = candidate.id,
                searchname = %candidate.searchname,
                error = format!("{failure:#}"),
                "TV processing failed for release"
            );
            passable.mark_transient("pipeline", None);
        }
        let outcome = passable.outcome();
        record_outcome(&self.services, candidate, &outcome).await?;
        Ok(outcome)
    }

    async fn run_pipes(
        &self,
        candidate: &Candidate,
        passable: &mut Passable,
        state: &mut RunState,
    ) -> Result<()> {
        let Some(parsed) = parse_release(&candidate.searchname) else {
            return Ok(());
        };
        passable.parsed = Some(parsed.clone());
        self.local_db(&parsed, passable).await?;
        for provider in &self.providers {
            if passable.resolved() {
                break;
            }
            self.run_provider(provider.as_ref(), &parsed, candidate, passable, state)
                .await?;
        }
        Ok(())
    }

    /// Matches an already scraped show and episode before any API is called. A show found here
    /// without the episode is handed on, so the API pipes can fetch the episode.
    async fn local_db(&self, parsed: &ParsedRelease, passable: &mut Passable) -> Result<()> {
        let db = &self.services.db;
        if passable.video_id <= 0 {
            let video_id = store::by_title(db, &parsed.clean_name, store::TYPE_TV).await?;
            if video_id <= 0 {
                return Ok(());
            }
            passable.video_id = video_id;
            passable.show_provider = Some(LOCAL_DB.to_owned());
        }
        if parsed.season_pack {
            passable.season_pack = true;
            passable.matched_by = Some(LOCAL_DB.to_owned());
            return Ok(());
        }
        if parsed.has_episode_reference()
            && let Some(episode_id) = find_local_episode(db, passable.video_id, parsed).await?
        {
            passable.episode_id = episode_id;
            passable.matched_by = Some(LOCAL_DB.to_owned());
        }
        Ok(())
    }

    async fn run_provider(
        &self,
        provider: &dyn TvProvider,
        parsed: &ParsedRelease,
        candidate: &Candidate,
        passable: &mut Passable,
        state: &mut RunState,
    ) -> Result<()> {
        if !provider.available() {
            return Ok(());
        }
        let name = provider.name();
        if state.circuit_open(name) {
            passable.mark_transient(name, None);
            return Ok(());
        }
        match self.attempt(provider, parsed, passable, state).await {
            Ok(()) => {
                state.consecutive_transient.insert(name, 0);
                Ok(())
            }
            Err(failure) => {
                let Some(transient) = failure.downcast_ref::<TransientError>() else {
                    return Err(failure);
                };
                *state.consecutive_transient.entry(name).or_default() += 1;
                passable.mark_transient(name, transient.retry_after);
                info!(
                    provider = name,
                    release_id = candidate.id,
                    status = transient.status,
                    retry_after = transient.retry_after,
                    error = %transient,
                    "TV provider failed transiently"
                );
                Ok(())
            }
        }
    }

    async fn attempt(
        &self,
        provider: &dyn TvProvider,
        parsed: &ParsedRelease,
        passable: &mut Passable,
        state: &mut RunState,
    ) -> Result<()> {
        let services = &self.services;
        let name = provider.name();
        let title = &parsed.clean_name;
        if passable.video_id <= 0 {
            if state.title_not_found(name, title) {
                return Ok(());
            }
            let found = provider
                .show_info(
                    &parsed.title_without_year(),
                    parsed.year,
                    parsed.country_code(),
                )
                .await?;
            let Some(mut show) = found.filter(|show| !show.title.is_empty() && show.title != "0")
            else {
                state.remember_title_not_found(name, title);
                return Ok(());
            };
            if show.country.is_empty() {
                parsed.country_code().clone_into(&mut show.country);
            }
            let video_id = store::add_show(services, &show).await?;
            if video_id <= 0 {
                return Ok(());
            }
            passable.video_id = video_id;
            passable.show_provider = Some(name.to_owned());
            self.store_poster(video_id, &show).await;
        }
        let video_id = passable.video_id;
        if parsed.season_pack {
            passable.season_pack = true;
            passable.matched_by = Some(name.to_owned());
            return Ok(());
        }
        let site_id = store::site_id_of(&services.db, provider.site(), video_id).await?;
        if site_id <= 0 {
            return Ok(());
        }
        let episode_id = self
            .resolve_episode(provider, parsed, site_id, video_id, state)
            .await?;
        if episode_id > 0 {
            passable.episode_id = episode_id;
            passable.matched_by = Some(name.to_owned());
        }
        Ok(())
    }

    /// The local episode table first, then the API, downloading each show's list at most once per
    /// refresh window.
    async fn resolve_episode(
        &self,
        provider: &dyn TvProvider,
        parsed: &ParsedRelease,
        site_id: i64,
        video_id: i64,
        state: &mut RunState,
    ) -> Result<i64> {
        if !parsed.has_episode_reference() {
            return Ok(0);
        }
        let db = &self.services.db;
        let name = provider.name();
        if let Some(found) = find_local_episode(db, video_id, parsed).await? {
            return Ok(found);
        }
        // A show we hold no episodes for gets its whole list in one go.
        if !store::has_episodes(db, video_id).await?
            && self
                .refresh_episodes(provider, site_id, video_id, state)
                .await?
            && let Some(found) = find_local_episode(db, video_id, parsed).await?
        {
            return Ok(found);
        }
        if parsed.has_episode_numbers() {
            let season = parsed.season.unwrap_or(0);
            let number = parsed.episode.unwrap_or(0);
            if state.first_episode_lookup(name, video_id, &format!("{season}x{number}"))
                && let Some(episode) = provider.episode(site_id, season, number, video_id).await?
                && let Some(found) = store::add_episode(db, video_id, &episode).await?
                && found > 0
            {
                return Ok(found);
            }
        }
        if let Some(airdate) = parsed.airdate.as_deref()
            && provider.supports_airdate()
            && state.first_episode_lookup(name, video_id, airdate)
        {
            let episodes = provider.episodes_by_airdate(site_id, airdate).await?;
            store::add_episodes(&self.services, video_id, &episodes).await?;
            if let Some(found) = find_local_episode(db, video_id, parsed).await? {
                return Ok(found);
            }
        }
        // Last resort: the stored list may be stale or renumbered.
        if self
            .refresh_episodes(provider, site_id, video_id, state)
            .await?
        {
            return Ok(find_local_episode(db, video_id, parsed).await?.unwrap_or(0));
        }
        Ok(0)
    }

    /// Downloads the show's episode list unless it was refreshed within the window.
    async fn refresh_episodes(
        &self,
        provider: &dyn TvProvider,
        site_id: i64,
        video_id: i64,
        state: &mut RunState,
    ) -> Result<bool> {
        let key = refresh_key(provider.name(), video_id);
        if state.refreshed.contains(&key) || self.services.cache.has(&key).await {
            return Ok(false);
        }
        let episodes = provider.all_episodes(site_id, video_id).await?;
        store::add_episodes(&self.services, video_id, &episodes).await?;
        self.services.cache.put(&key, &1, EPISODE_REFRESH_TTL).await;
        state.refreshed.insert(key);
        Ok(true)
    }

    async fn store_poster(&self, video_id: i64, show: &store::Show) {
        let services = &self.services;
        let stored = async {
            if store::has_cover(&services.db, video_id).await? {
                return Ok(());
            }
            let url = resolve_poster(services, show).await;
            if !url.is_empty() {
                store::save_poster(services, video_id, &url).await?;
            }
            anyhow::Ok(())
        };
        if let Err(failure) = stored.await {
            info!(
                video_id,
                error = format!("{failure:#}"),
                "TV poster download failed"
            );
        }
    }

    /// `processBatch`: a set of releases with one shared run state.
    pub async fn process_batch(&self, releases: &[Candidate]) -> Result<Stats> {
        let started = Instant::now();
        let mut state = RunState::default();
        let mut stats = Stats::default();
        for release in releases {
            stats.record(&self.process_release(release, &mut state).await?);
        }
        info!(
            processed = stats.processed,
            matched = stats.matched,
            episode_missing = stats.episode_missing,
            not_found = stats.not_found,
            deferred = stats.deferred,
            seconds = started.elapsed().as_secs_f64(),
            providers = ?stats.providers,
            "TV processing complete"
        );
        Ok(stats)
    }

    /// `process`: the releases of one bucket that are due.
    pub async fn process(
        &self,
        settings: &MetadataSettings,
        group_id: i64,
        guid_char: &str,
        renamed: Renamed,
    ) -> Result<Stats> {
        let mode = renamed.resolve(settings.tv_lookup);
        if !mode.enabled() {
            return Ok(Stats::default());
        }
        // The configured "renamed only" mode applies even when a caller asks for all.
        let renamed_only =
            mode == LookupMode::RenamedOnly || settings.tv_lookup == LookupMode::RenamedOnly;
        let condition = tv_work_condition(if renamed_only {
            LookupMode::RenamedOnly
        } else {
            LookupMode::All
        });
        let limit = if settings.max_tv_processed > 0 {
            settings.max_tv_processed
        } else {
            75
        };
        let scope = release_scope(group_id, guid_char);
        let statement = format!(
            "SELECT CAST(id AS SIGNED) AS id, searchname, CAST(videos_id AS SIGNED) AS videos_id \
             FROM releases WHERE {condition}{scope} ORDER BY postdate DESC LIMIT {limit}"
        );
        let releases = fetch_candidates(&self.services.db, statement).await?;
        self.process_batch(&releases).await
    }
}

pub async fn fetch_candidates(db: &MySqlPool, statement: String) -> Result<Vec<Candidate>> {
    let rows = sqlx::query(sqlx::AssertSqlSafe(statement))
        .fetch_all(db)
        .await?;
    rows.iter()
        .map(|row| {
            Ok(Candidate {
                id: row.try_get("id")?,
                searchname: row.try_get("searchname")?,
                videos_id: row.try_get("videos_id")?,
            })
        })
        .collect()
}

async fn find_local_episode(
    db: &MySqlPool,
    video_id: i64,
    parsed: &ParsedRelease,
) -> Result<Option<i64>> {
    store::by_season_episode(
        db,
        video_id,
        parsed.season.unwrap_or(-1),
        parsed.episode.unwrap_or(0),
        parsed.airdate.as_deref().unwrap_or_default(),
    )
    .await
}

struct Columns {
    video_id: i64,
    episode: i64,
    attempts: i64,
    retry_after_seconds: Option<i64>,
}

fn columns(video_id: i64, episode: i64) -> Columns {
    Columns {
        video_id,
        episode,
        attempts: 0,
        retry_after_seconds: None,
    }
}

fn columns_for(outcome: &Outcome, previous_status: i64, previous_attempts: i64) -> Columns {
    match outcome {
        Outcome::Matched {
            video_id,
            episode_id,
            ..
        } => columns(*video_id, *episode_id),
        Outcome::SeasonPack { video_id, .. } => columns(*video_id, tv_status::SEASON_PACK),
        Outcome::EpisodeMissing {
            video_id,
            transient,
            retry_after,
            ..
        } => {
            let attempts = if previous_status == tv_status::EPISODE_MISSING {
                previous_attempts + 1
            } else {
                1
            };
            let retry_after_seconds = if *transient {
                Some(retry_after.unwrap_or(0).max(TV_KNOWN_SHOW_TRANSIENT_DELAY))
            } else if attempts < TV_MAX_EPISODE_ATTEMPTS {
                Some(tv_episode_delay(attempts))
            } else {
                None
            };
            Columns {
                video_id: *video_id,
                episode: tv_status::EPISODE_MISSING,
                attempts,
                retry_after_seconds,
            }
        }
        Outcome::Transient { retry_after, .. } => {
            let attempts = if previous_status == tv_status::PENDING {
                previous_attempts + 1
            } else {
                1
            };
            if attempts >= TV_MAX_TRANSIENT_ATTEMPTS {
                return columns(0, tv_status::SHOW_NOT_FOUND);
            }
            Columns {
                video_id: 0,
                episode: tv_status::PENDING,
                attempts,
                retry_after_seconds: Some(tv_transient_delay(attempts, retry_after.unwrap_or(0))),
            }
        }
        Outcome::ParseFailed => columns(0, tv_status::PARSE_FAILED),
        Outcome::NotFound => columns(0, tv_status::SHOW_NOT_FOUND),
    }
}

/// `TvLookupOutcomeWriter`: writes only while the searchname is unchanged, so a rename during
/// processing isn't overwritten. Only a changed link reindexes the release, because the index
/// treats every `tv_episodes_id` <= 0 the same.
pub async fn record_outcome(
    services: &Services,
    candidate: &Candidate,
    outcome: &Outcome,
) -> Result<()> {
    let mut transaction: Transaction<'_, MySql> = services.db.begin().await?;
    let row = sqlx::query(
        "SELECT CAST(videos_id AS SIGNED) AS videos_id, CAST(tv_episodes_id AS SIGNED) AS episode, \
         CAST(tv_lookup_attempts AS SIGNED) AS attempts FROM releases \
         WHERE id = ? AND searchname = ? FOR UPDATE",
    )
    .bind(candidate.id)
    .bind(&candidate.searchname)
    .fetch_optional(&mut *transaction)
    .await?;
    let Some(row) = row else {
        return Ok(());
    };
    let old_video: i64 = row.try_get("videos_id")?;
    let old_episode: i64 = row.try_get("episode")?;
    let old_attempts: i64 = row.try_get("attempts")?;
    let new = columns_for(outcome, old_episode, old_attempts);
    let link_changed = new.video_id != old_video
        || (new.episode != old_episode && new.episode.max(old_episode) > 0);
    sqlx::query(
        "UPDATE releases SET videos_id = ?, tv_episodes_id = ?, tv_lookup_attempts = ?, \
         tv_lookup_retry_at = NOW() + INTERVAL ? SECOND WHERE id = ?",
    )
    .bind(new.video_id)
    .bind(new.episode)
    .bind(new.attempts)
    .bind(new.retry_after_seconds)
    .bind(candidate.id)
    .execute(&mut *transaction)
    .await?;
    transaction.commit().await?;
    if link_changed {
        services.sync_release(candidate.id).await;
        if matches!(
            outcome,
            Outcome::Matched { .. } | Outcome::SeasonPack { .. }
        ) {
            services.bump_release_cache().await;
        }
    }
    Ok(())
}

/// PHP's bucket workers run from `postprocess:tv-pipeline`, whose argument must be one hex digit.
pub fn check_guid_char(guid_char: &str) -> Result<()> {
    if guid_char.is_empty()
        || (guid_char.len() == 1
            && guid_char
                .bytes()
                .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte)))
    {
        return Ok(());
    }
    bail!("GUID character must be a-f or 0-9.")
}

/// `PostProcessRunner::processTv`: every GUID bucket with work, in parallel.
pub async fn dispatch_buckets(
    services: Arc<Services>,
    settings: &MetadataSettings,
    renamed: Renamed,
) -> Result<()> {
    if !settings.tv_lookup.enabled() {
        return Ok(());
    }
    let renamed_only = renamed == Renamed::Only
        || (renamed == Renamed::Configured && settings.tv_lookup == LookupMode::RenamedOnly);
    let condition = tv_work_condition(if renamed_only {
        LookupMode::RenamedOnly
    } else {
        settings.tv_lookup
    });
    let statement = format!(
        "SELECT DISTINCT LEFT(leftguid, 1) AS bucket FROM releases WHERE {condition} LIMIT 16"
    );
    let buckets: Vec<String> = sqlx::query_scalar(sqlx::AssertSqlSafe(statement))
        .fetch_all(&services.db)
        .await
        .context("listing the TV buckets")?;
    let threads: Option<i64> = sqlx::query_scalar(
        "SELECT CAST(post_threads_non AS SIGNED) FROM post_processing_configurations WHERE id = 1",
    )
    .fetch_optional(&services.db)
    .await?;
    let threads = usize::try_from(threads.unwrap_or(1).max(1))?;
    let bucket_mode = if renamed_only || settings.tv_lookup == LookupMode::RenamedOnly {
        Renamed::Only
    } else {
        Renamed::All
    };
    let pipeline = Arc::new(Pipeline::new(services));
    let results: Vec<Result<Stats>> = stream::iter(buckets)
        .map(|bucket| {
            let pipeline = pipeline.clone();
            let settings = settings.clone();
            async move { pipeline.process(&settings, 0, &bucket, bucket_mode).await }
        })
        .buffer_unordered(threads)
        .collect()
        .await;
    results
        .into_iter()
        .try_for_each(|result| result.map(|_| ()))
}

/// [`crate::process_tv`] and [`crate::process_tv_pipeline`] start here.
pub async fn run_bucket(ctx: &Ctx, group_id: i64, guid_char: &str, renamed: Renamed) -> Result<()> {
    check_guid_char(guid_char)?;
    let services = Services::shared(ctx)?;
    let settings = MetadataSettings::load(&services.db).await?;
    Pipeline::new(services)
        .process(&settings, group_id, guid_char, renamed)
        .await
        .map(|_| ())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn delays_follow_the_attempts() {
        let missing = |attempts_before, transient| {
            columns_for(
                &Outcome::EpisodeMissing {
                    video_id: 5,
                    provider: None,
                    transient,
                    retry_after: Some(60),
                },
                tv_status::EPISODE_MISSING,
                attempts_before,
            )
        };
        assert_eq!(missing(0, false).retry_after_seconds, Some(3600));
        assert_eq!(missing(1, false).retry_after_seconds, Some(21600));
        assert_eq!(missing(4, false).retry_after_seconds, Some(604800));
        assert_eq!(missing(5, false).retry_after_seconds, None);
        assert_eq!(missing(0, true).retry_after_seconds, Some(900));

        let transient = |attempts_before| {
            columns_for(
                &Outcome::Transient {
                    providers: "TVDB".to_owned(),
                    retry_after: None,
                },
                tv_status::PENDING,
                attempts_before,
            )
        };
        assert_eq!(transient(0).retry_after_seconds, Some(300));
        assert_eq!(transient(2).retry_after_seconds, Some(3600));
        assert_eq!(transient(7).episode, tv_status::SHOW_NOT_FOUND);
        assert_eq!(transient(7).attempts, 0);
    }

    #[test]
    fn missing_attempts_restart_from_another_status() {
        let columns = columns_for(
            &Outcome::EpisodeMissing {
                video_id: 5,
                provider: None,
                transient: false,
                retry_after: None,
            },
            tv_status::PENDING,
            4,
        );
        assert_eq!(columns.attempts, 1);
    }

    #[test]
    fn guid_char_is_one_lowercase_hex_digit() {
        assert!(check_guid_char("").is_ok());
        assert!(check_guid_char("a").is_ok());
        assert!(check_guid_char("9").is_ok());
        assert!(check_guid_char("g").is_err());
        assert!(check_guid_char("A").is_err());
        assert!(check_guid_char("ab").is_err());
    }

    #[test]
    fn outcomes_from_passable() {
        let mut passable = Passable::default();
        assert_eq!(passable.outcome(), Outcome::ParseFailed);
        passable.parsed = parse_release("Show.Name.S01E02.WEB");
        assert_eq!(passable.outcome(), Outcome::NotFound);
        passable.mark_transient("TVDB", Some(30));
        passable.mark_transient("TVDB", Some(20));
        assert_eq!(
            passable.outcome(),
            Outcome::Transient {
                providers: "TVDB".to_owned(),
                retry_after: Some(30),
            }
        );
        passable.video_id = 9;
        assert!(matches!(
            passable.outcome(),
            Outcome::EpisodeMissing {
                transient: true,
                ..
            }
        ));
        passable.season_pack = true;
        passable.matched_by = Some("Local DB".to_owned());
        assert!(matches!(passable.outcome(), Outcome::SeasonPack { .. }));
        passable.episode_id = 3;
        assert!(matches!(
            passable.outcome(),
            Outcome::Matched { episode_id: 3, .. }
        ));
    }
}
