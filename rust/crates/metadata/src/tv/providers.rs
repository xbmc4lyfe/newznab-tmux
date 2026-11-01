//! The TV providers. Each searches for a show and fetches its episodes; the pipeline stores them.

use anyhow::Result;
use async_trait::async_trait;
use chrono::DateTime;
use serde_json::Value;
use std::sync::Arc;
use tracing::info;

use super::parse::{Started, score_candidate};
use super::store::{
    Episode, SOURCE_TMDB, SOURCE_TRAKT, SOURCE_TVDB, SOURCE_TVMAZE, Show, Site, local_zone,
    site_ids,
};
use crate::clients::tmdb::{Tmdb, imdb_digits};
use crate::clients::trakt::{EpisodeIds, Trakt, format_imdb_id};
use crate::clients::tvmaze::MazeShow;
use crate::http::TransientError;
use crate::php;
use crate::services::Services;

#[async_trait]
pub trait TvProvider: Send + Sync {
    fn name(&self) -> &'static str;
    fn site(&self) -> Site;
    /// Unconfigured providers are skipped silently.
    fn available(&self) -> bool;
    /// The best match for a show title without any year suffix.
    async fn show_info(&self, name: &str, year: Option<i32>, country: &str)
    -> Result<Option<Show>>;
    /// Every episode of a show.
    async fn all_episodes(&self, site_id: i64, video_id: i64) -> Result<Vec<Episode>>;
    /// One episode, when the provider has it.
    async fn episode(
        &self,
        site_id: i64,
        season: i64,
        episode: i64,
        video_id: i64,
    ) -> Result<Option<Episode>>;
    /// Whether [`Self::episodes_by_airdate`] asks the provider for a single date.
    fn supports_airdate(&self) -> bool {
        false
    }
    async fn episodes_by_airdate(&self, _site_id: i64, _airdate: &str) -> Result<Vec<Episode>> {
        Ok(Vec::new())
    }
}

pub fn default_providers(services: &Arc<Services>) -> Vec<Box<dyn TvProvider>> {
    vec![
        Box::new(TvdbProvider(services.clone())),
        Box::new(TvMazeProvider(services.clone())),
        Box::new(TmdbProvider::new(services.clone())),
        Box::new(TraktProvider::new(services.clone())),
    ]
}

/// `lookupExternalIds`: optional enrichment, so failures never block a match.
async fn lookup_external_ids(services: &Services, tvdb: i64, imdb: &str) -> (i64, i64) {
    let (mut tmdb, mut trakt) = (0, 0);
    if services.tmdb.is_configured() {
        if tvdb > 0
            && let Ok(Some(ids)) = services.tmdb.lookup_tv_ids(&tvdb.to_string(), "tvdb").await
        {
            tmdb = ids.tmdb;
        }
        if tmdb == 0
            && is_valid_imdb(imdb)
            && let Ok(Some(ids)) = services.tmdb.lookup_tv_ids(imdb, "imdb").await
        {
            tmdb = ids.tmdb;
        }
    }
    if services.trakt.is_configured() {
        let mut ids = None;
        if tvdb > 0 {
            ids = services
                .trakt
                .lookup_show_ids(&tvdb.to_string(), "tvdb")
                .await
                .ok()
                .flatten();
        }
        if ids.as_ref().is_none_or(|ids| ids.trakt == 0) && is_valid_imdb(imdb) {
            ids = services
                .trakt
                .lookup_show_ids(imdb, "imdb")
                .await
                .ok()
                .flatten();
        }
        if let Some(ids) = ids.filter(|ids| ids.trakt != 0) {
            trakt = ids.trakt;
            if tmdb == 0 {
                tmdb = ids.tmdb;
            }
        }
    }
    (tmdb, trakt)
}

/// `imdb_id_is_valid`.
pub fn is_valid_imdb(id: &str) -> bool {
    let digits: String = php::trim(id).chars().filter(char::is_ascii_digit).collect();
    !digits.is_empty() && digits.bytes().any(|byte| byte != b'0')
}

fn string_list(value: Option<&Value>) -> Vec<String> {
    php::items(value)
        .iter()
        .filter_map(|alias| match alias {
            Value::String(alias) => Some(alias.clone()),
            Value::Object(fields) => fields
                .get("name")
                .and_then(Value::as_str)
                .map(str::to_owned),
            _ => None,
        })
        .collect()
}

pub struct TvdbProvider(pub Arc<Services>);

impl TvdbProvider {
    fn format_episode(episode: &Value) -> Episode {
        Episode::new(
            php::text(episode.get("name")),
            php::int(episode.get("seasonNumber")),
            php::int(episode.get("number")),
            php::text(episode.get("aired")),
            php::text(episode.get("overview")),
        )
    }

    fn has_required_episode(episode: &Value) -> bool {
        php::present(episode.get("seasonNumber")) && php::present(episode.get("number"))
    }

    /// Enrichment: a transient failure is logged rather than failing the match.
    async fn quietly<T: Default>(future: impl Future<Output = Result<T>>) -> Result<T> {
        match future.await {
            Err(failure) if failure.is::<TransientError>() => {
                info!(error = %failure, "TVDB enrichment request failed");
                Ok(T::default())
            }
            other => other,
        }
    }

    pub async fn format_show(&self, show: &Value) -> Result<Show> {
        let services = &self.0;
        let tvdb = php::int(show.get("tvdb_id"));
        let artworks = Self::quietly(services.tvdb.series_artworks(tvdb)).await?;
        let mut poster: Option<&Value> = None;
        for artwork in artworks
            .iter()
            .filter(|artwork| php::int(artwork.get("type")) == 2)
        {
            if poster
                .is_none_or(|best| php::int(artwork.get("score")) > php::int(best.get("score")))
            {
                poster = Some(artwork);
            }
        }
        let extended = Self::quietly(services.tvdb.series_extended(tvdb)).await?;
        let imdb = extended
            .as_ref()
            .and_then(|extended| {
                php::items(extended.get("remoteIds"))
                    .iter()
                    .find(|remote| php::int(remote.get("type")) == 2)
            })
            .map(|remote| imdb_digits(&php::text(remote.get("id"))))
            .unwrap_or_default();
        let (tmdb, trakt) = lookup_external_ids(services, tvdb, &imdb).await;
        Ok(Show {
            title: php::text(show.get("name")),
            summary: php::text(show.get("overview")),
            started: php::text(show.get("first_air_time")),
            publisher: extended
                .as_ref()
                .and_then(|extended| extended.get("originalNetwork"))
                .map(|network| php::text(network.get("name")))
                .unwrap_or_default(),
            country: php::text(show.get("country")),
            poster: poster
                .map(|poster| php::text(poster.get("image")))
                .unwrap_or_default(),
            source: SOURCE_TVDB,
            imdb,
            tvdb,
            trakt,
            tmdb,
            aliases: string_list(show.get("aliases")),
            ..Show::default()
        })
    }
}

#[async_trait]
impl TvProvider for TvdbProvider {
    fn name(&self) -> &'static str {
        "TVDB"
    }

    fn site(&self) -> Site {
        Site::Tvdb
    }

    fn available(&self) -> bool {
        self.0.tvdb.is_configured()
    }

    async fn show_info(
        &self,
        name: &str,
        year: Option<i32>,
        country: &str,
    ) -> Result<Option<Show>> {
        let client = &self.0.tvdb;
        let mut response = match year {
            Some(_) => client.search_series(name, year).await?,
            None => None,
        };
        if response.as_ref().is_none_or(Vec::is_empty) {
            response = client.search_series(name, None).await?;
        }
        let Some(response) = response else {
            return Ok(None);
        };
        let mut best = 0.0;
        let mut highest = None;
        for show in &response {
            if !php::present(show.get("tvdb_id")) || !php::present(show.get("name")) {
                continue;
            }
            let mut names = vec![php::text(show.get("name"))];
            names.extend(
                php::items(show.get("aliases"))
                    .iter()
                    .filter_map(Value::as_str)
                    .filter(|alias| !alias.is_empty())
                    .map(str::to_owned),
            );
            let started = show
                .get("first_air_time")
                .filter(|date| !date.is_null())
                .or_else(|| show.get("year"))
                .map(php_text_owned);
            let their_country = php::text(show.get("country"));
            for candidate in &names {
                let score = score_candidate(
                    name,
                    year,
                    candidate,
                    started.as_deref().map_or(Started::None, Started::Text),
                    country,
                    &their_country,
                );
                if score > best {
                    best = score;
                    highest = Some(show);
                }
            }
        }
        match highest {
            Some(show) => Ok(Some(self.format_show(show).await?)),
            None => Ok(None),
        }
    }

    async fn all_episodes(&self, site_id: i64, video_id: i64) -> Result<Vec<Episode>> {
        if site_id <= 0 || video_id <= 0 {
            return Ok(Vec::new());
        }
        let episodes = self.0.tvdb.all_episodes(site_id).await?.unwrap_or_default();
        Ok(episodes
            .iter()
            .filter(|episode| Self::has_required_episode(episode))
            .map(Self::format_episode)
            .collect())
    }

    async fn episode(
        &self,
        site_id: i64,
        season: i64,
        episode: i64,
        _video_id: i64,
    ) -> Result<Option<Episode>> {
        if site_id <= 0 || season < 0 || episode <= 0 {
            return Ok(None);
        }
        let records = self
            .0
            .tvdb
            .episodes(site_id, season, Some(episode), None)
            .await?
            .unwrap_or_default();
        Ok(records
            .iter()
            .find(|record| {
                Self::has_required_episode(record)
                    && php::int(record.get("seasonNumber")) == season
                    && php::int(record.get("number")) == episode
            })
            .map(Self::format_episode))
    }

    fn supports_airdate(&self) -> bool {
        true
    }

    async fn episodes_by_airdate(&self, site_id: i64, airdate: &str) -> Result<Vec<Episode>> {
        if site_id <= 0 || chrono::NaiveDate::parse_from_str(airdate, "%Y-%m-%d").is_err() {
            return Ok(Vec::new());
        }
        let records = self
            .0
            .tvdb
            .episodes(site_id, 0, None, Some(airdate))
            .await?
            .unwrap_or_default();
        Ok(records
            .iter()
            .filter(|record| Self::has_required_episode(record))
            .map(Self::format_episode)
            .collect())
    }
}

fn php_text_owned(value: &Value) -> String {
    php::text(Some(value))
}

pub struct TvMazeProvider(pub Arc<Services>);

impl TvMazeProvider {
    fn format_episode(episode: &Value) -> Episode {
        Episode::new(
            php::text(episode.get("name")),
            php::int(episode.get("season")),
            php::int(episode.get("number")),
            php::text(episode.get("airdate")),
            php::strip_tags(&php::text(episode.get("summary"))),
        )
    }

    fn format_episodes(episodes: &[Value]) -> Vec<Episode> {
        // Specials carry a season but no number; storage keys those by airdate.
        episodes
            .iter()
            .filter(|episode| {
                episode
                    .get("season")
                    .is_some_and(|season| !season.is_null())
            })
            .map(Self::format_episode)
            .collect()
    }

    async fn best(
        &self,
        shows: &[MazeShow],
        name: &str,
        year: Option<i32>,
        country: &str,
    ) -> Result<Option<Show>> {
        let mut best = 0.0;
        let mut highest = None;
        for show in shows {
            if !php::present(Some(&show.id)) || show.name.is_empty() {
                continue;
            }
            let names = std::iter::once(&show.name).chain(show.akas.iter());
            for candidate in names {
                let score = score_candidate(
                    name,
                    year,
                    candidate,
                    show.premiered
                        .as_deref()
                        .map_or(Started::None, Started::Text),
                    country,
                    &show.country,
                );
                if score > best {
                    best = score;
                    highest = Some(show);
                }
            }
        }
        match highest {
            Some(show) => Ok(Some(self.format_show(show).await)),
            None => Ok(None),
        }
    }

    pub async fn format_show(&self, show: &MazeShow) -> Show {
        let externals = &show.externals;
        let tvdb = php::int(externals.get("thetvdb"));
        let imdb = imdb_digits(&php::text(externals.get("imdb")));
        let (tmdb, trakt) = lookup_external_ids(&self.0, tvdb, &imdb).await;
        Show {
            title: show.name.clone(),
            summary: show.summary.clone(),
            started: show.premiered.clone().unwrap_or_default(),
            publisher: if show.network.is_empty() {
                show.web_channel.clone()
            } else {
                show.network.clone()
            },
            country: show.country.clone(),
            poster: show.image.clone(),
            source: SOURCE_TVMAZE,
            imdb,
            tvdb,
            tvmaze: php::int(Some(&show.id)),
            trakt,
            tvrage: php::int(externals.get("tvrage")),
            tmdb,
            aliases: show.akas.clone(),
            localzone: show.timezone.clone(),
            ..Show::default()
        }
    }
}

#[async_trait]
impl TvProvider for TvMazeProvider {
    fn name(&self) -> &'static str {
        "TVMaze"
    }

    fn site(&self) -> Site {
        Site::Tvmaze
    }

    fn available(&self) -> bool {
        true
    }

    async fn show_info(
        &self,
        name: &str,
        year: Option<i32>,
        country: &str,
    ) -> Result<Option<Show>> {
        let client = &self.0.tvmaze;
        // The single search embeds AKAs; the full search ranks better but carries none.
        let single: Vec<MazeShow> = client.single_search_akas(name).await?.into_iter().collect();
        let mut found = self.best(&single, name, year, country).await?;
        if found.is_none() {
            let shows = client.search_shows(name).await?;
            found = self.best(&shows, name, year, country).await?;
        }
        if let Some(show) = &mut found
            && show.aliases.is_empty()
            && show.tvmaze > 0
        {
            show.aliases = client.akas(show.tvmaze).await?;
        }
        Ok(found)
    }

    async fn all_episodes(&self, site_id: i64, video_id: i64) -> Result<Vec<Episode>> {
        if site_id <= 0 || video_id <= 0 {
            return Ok(Vec::new());
        }
        Ok(Self::format_episodes(
            &self.0.tvmaze.episodes_by_show(site_id).await?,
        ))
    }

    async fn episode(
        &self,
        site_id: i64,
        season: i64,
        episode: i64,
        _video_id: i64,
    ) -> Result<Option<Episode>> {
        if site_id <= 0 || season < 0 || episode <= 0 {
            return Ok(None);
        }
        let found = self
            .0
            .tvmaze
            .episode_by_number(site_id, season, episode)
            .await?;
        Ok(found
            .filter(|found| php::present(found.get("season")) && php::present(found.get("number")))
            .as_ref()
            .map(Self::format_episode))
    }

    fn supports_airdate(&self) -> bool {
        true
    }

    async fn episodes_by_airdate(&self, site_id: i64, airdate: &str) -> Result<Vec<Episode>> {
        if site_id <= 0 {
            return Ok(Vec::new());
        }
        Ok(Self::format_episodes(
            &self.0.tvmaze.episodes_by_date(site_id, airdate).await?,
        ))
    }
}

pub struct TmdbProvider {
    services: Arc<Services>,
    client: Tmdb,
}

impl TmdbProvider {
    pub fn new(services: Arc<Services>) -> Self {
        let client = services.tmdb.throwing();
        Self { services, client }
    }

    fn format_episode(episode: &Value) -> Episode {
        Episode::new(
            php::text(episode.get("name")),
            php::int(episode.get("season_number")),
            php::int(episode.get("episode_number")),
            php::text(episode.get("air_date")),
            php::text(episode.get("overview")),
        )
    }

    fn has_required_episode(episode: &Value) -> bool {
        php::present(episode.get("season_number")) && php::present(episode.get("episode_number"))
    }

    /// `show` is a search result or the details themselves, which carry the same fields.
    pub async fn format_show(&self, show: &Value, details: &Value) -> Show {
        let tmdb = php::int(show.get("id"));
        let aliases = php::items(
            details
                .get("alternative_titles")
                .and_then(|titles| titles.get("results")),
        )
        .iter()
        .filter_map(|aka| aka.get("title"))
        .filter_map(Value::as_str)
        .map(str::to_owned)
        .collect();
        let external = details.get("external_ids").unwrap_or(&Value::Null);
        let imdb = imdb_digits(&php::text(external.get("imdb_id")));
        let tvdb = php::int(external.get("tvdb_id"));
        let trakt = self.lookup_trakt_id(tmdb, &imdb, tvdb).await;
        let poster_path = show
            .get("poster_path")
            .and_then(Value::as_str)
            .unwrap_or_default();
        let text_of = |key: &str| {
            show.get(key)
                .and_then(Value::as_str)
                .unwrap_or_default()
                .to_owned()
        };
        Show {
            title: php::text(show.get("name")),
            summary: text_of("overview"),
            started: text_of("first_air_date"),
            publisher: php::text(
                php::items(details.get("networks"))
                    .first()
                    .and_then(|network| network.get("name")),
            ),
            country: php::text(php::items(show.get("origin_country")).first()),
            poster: Tmdb::image_url(poster_path),
            source: SOURCE_TMDB,
            imdb,
            tvdb,
            trakt,
            tvrage: php::int(external.get("tvrage_id")),
            tmdb,
            aliases,
            ..Show::default()
        }
    }

    /// Tries the TMDB id first, then IMDb, then TVDB. Optional enrichment.
    async fn lookup_trakt_id(&self, tmdb: i64, imdb: &str, tvdb: i64) -> i64 {
        let trakt = &self.services.trakt;
        if !trakt.is_configured() {
            return 0;
        }
        let tmdb_id = tmdb.to_string();
        let tvdb_id = tvdb.to_string();
        for (kind, id, usable) in [
            ("tmdb", tmdb_id.as_str(), tmdb > 0),
            ("imdb", imdb, is_valid_imdb(imdb)),
            ("tvdb", tvdb_id.as_str(), tvdb > 0),
        ] {
            if !usable {
                continue;
            }
            if let Ok(Some(ids)) = trakt.lookup_show_ids(id, kind).await
                && ids.trakt != 0
            {
                return ids.trakt;
            }
        }
        0
    }
}

#[async_trait]
impl TvProvider for TmdbProvider {
    fn name(&self) -> &'static str {
        "TMDB"
    }

    fn site(&self) -> Site {
        Site::Tmdb
    }

    fn available(&self) -> bool {
        self.client.is_configured()
    }

    async fn show_info(
        &self,
        name: &str,
        year: Option<i32>,
        country: &str,
    ) -> Result<Option<Show>> {
        let results_of = |response: &Option<Value>| {
            response
                .as_ref()
                .and_then(|response| response.get("results"))
                .and_then(Value::as_array)
                .filter(|results| !results.is_empty())
                .cloned()
        };
        let mut results = match year {
            Some(_) => results_of(&self.client.search_tv(name, year).await?),
            None => None,
        };
        if results.is_none() {
            results = results_of(&self.client.search_tv(name, None).await?);
        }
        let Some(results) = results else {
            return Ok(None);
        };
        let mut best = 0.0;
        let mut highest = None;
        for show in &results {
            if !php::present(show.get("id")) || !php::present(show.get("name")) {
                continue;
            }
            let title = show.get("name").and_then(Value::as_str).unwrap_or_default();
            let original = show
                .get("original_name")
                .and_then(Value::as_str)
                .unwrap_or_default();
            let started = show
                .get("first_air_date")
                .and_then(Value::as_str)
                .unwrap_or_default();
            let their_country = php::text(php::items(show.get("origin_country")).first());
            let mut candidates = vec![title];
            if original != title {
                candidates.push(original);
            }
            for candidate in candidates {
                let score = score_candidate(
                    name,
                    year,
                    candidate,
                    Started::Text(started),
                    country,
                    &their_country,
                );
                if score > best {
                    best = score;
                    highest = Some(show);
                }
            }
        }
        let Some(show) = highest else {
            return Ok(None);
        };
        let tmdb = php::int(show.get("id"));
        if tmdb == 0 {
            return Ok(None);
        }
        // One request for the details the search result lacks.
        let details = self
            .client
            .tv_show(tmdb, &["external_ids", "alternative_titles"])
            .await?
            .unwrap_or(Value::Null);
        Ok(Some(self.format_show(show, &details).await))
    }

    async fn all_episodes(&self, site_id: i64, video_id: i64) -> Result<Vec<Episode>> {
        if site_id <= 0 || video_id <= 0 || !self.client.is_configured() {
            return Ok(Vec::new());
        }
        let Some(details) = self.client.tv_show(site_id, &[]).await? else {
            return Ok(Vec::new());
        };
        let mut formatted = Vec::new();
        for season in php::items(details.get("seasons")) {
            // Season 0 holds the specials.
            let number = season
                .get("season_number")
                .map_or(-1, |n| php::int(Some(n)));
            if number < 0 {
                continue;
            }
            let data = self
                .client
                .tv_season(site_id, number)
                .await?
                .unwrap_or(Value::Null);
            formatted.extend(
                php::items(data.get("episodes"))
                    .iter()
                    .filter(|episode| Self::has_required_episode(episode))
                    .map(Self::format_episode),
            );
        }
        Ok(formatted)
    }

    async fn episode(
        &self,
        site_id: i64,
        season: i64,
        episode: i64,
        video_id: i64,
    ) -> Result<Option<Episode>> {
        if season < 0 || episode <= 0 || !self.client.is_configured() {
            return Ok(None);
        }
        let response = if video_id > 0 {
            let ids = site_ids(&self.services.db, video_id).await?;
            let tmdb = if site_id > 0 { site_id } else { ids.tmdb };
            self.client
                .tv_episode_with_fallback(tmdb, ids.tvdb, &ids.imdb, season, episode)
                .await?
        } else if site_id > 0 {
            self.client.tv_episode(site_id, season, episode).await?
        } else {
            return Ok(None);
        };
        Ok(response
            .filter(Self::has_required_episode)
            .as_ref()
            .map(Self::format_episode))
    }
}

pub struct TraktProvider {
    services: Arc<Services>,
    client: Trakt,
}

impl TraktProvider {
    pub fn new(services: Arc<Services>) -> Self {
        let client = services.trakt.throwing();
        Self { services, client }
    }

    fn format_episode(episode: &Value, zone: &str) -> Episode {
        let number = if episode.get("number").is_some_and(|n| !n.is_null()) {
            php::int(episode.get("number"))
        } else {
            php::int(episode.get("episode"))
        };
        Episode::new(
            php::text(episode.get("title")),
            php::int(episode.get("season")),
            number,
            trakt_date(&php::text(episode.get("first_aired")), zone),
            php::text(episode.get("overview")),
        )
    }

    fn has_required_episode(episode: &Value) -> bool {
        php::present(episode.get("season")) && php::present(episode.get("number"))
    }

    /// Optional enrichment.
    async fn lookup_tvmaze_id(&self, tvdb: i64, imdb: &str) -> i64 {
        let tvmaze = &self.services.tvmaze;
        let mut show = None;
        if tvdb > 0 {
            match tvmaze.lookup("thetvdb", &tvdb.to_string()).await {
                Ok(found) => show = found,
                Err(_) => return 0,
            }
        }
        if show.is_none()
            && is_valid_imdb(imdb)
            && let Ok(found) = tvmaze.lookup("imdb", &format!("tt{imdb}")).await
        {
            show = found;
        }
        show.map_or(0, |show| php::int(Some(&show.id)))
    }

    pub async fn format_show(&self, show: &Value) -> Show {
        let ids = show.get("ids").unwrap_or(&Value::Null);
        let airs = show.get("airs").unwrap_or(&Value::Null);
        let images = show.get("images").unwrap_or(&Value::Null);
        let zone = php::text(airs.get("timezone"));
        let imdb = imdb_digits(&php::text(ids.get("imdb")));
        let tvdb = php::int(ids.get("tvdb"));
        let tvmaze = self.lookup_tvmaze_id(tvdb, &imdb).await;
        Show {
            title: php::text(show.get("title")),
            summary: php::text(show.get("overview")),
            started: trakt_date(&php::text(show.get("first_aired")), &zone),
            publisher: php::text(show.get("network")),
            country: php::text(show.get("country")),
            poster: image_url(images.get("poster")),
            fallback_poster: image_url(images.get("fanart")),
            source: SOURCE_TRAKT,
            imdb,
            tvdb,
            trakt: php::int(ids.get("trakt")),
            tvrage: php::int(ids.get("tvrage")),
            tvmaze,
            tmdb: php::int(ids.get("tmdb")),
            aliases: string_list(show.get("aliases")),
            localzone: zone,
            ..Show::default()
        }
    }
}

/// Trakt image fields are the legacy `{thumb, full, medium}` object or a list of host paths
/// without a scheme.
fn image_url(image: Option<&Value>) -> String {
    let Some(image) = image else {
        return String::new();
    };
    let url = if image.is_object() {
        ["full", "medium", "thumb"]
            .iter()
            .find_map(|key| image.get(*key).filter(|value| !value.is_null()))
            .map(|value| php::text(Some(value)))
            .unwrap_or_default()
    } else if image.is_array() {
        image
            .get(0)
            .and_then(Value::as_str)
            .unwrap_or_default()
            .to_owned()
    } else {
        return String::new();
    };
    if !url.is_empty() && !url.starts_with("http") {
        return format!("https://{}", url.trim_start_matches('/'));
    }
    url
}

/// Trakt dates are UTC instants; the airdate is the local date in the show's timezone.
pub fn trakt_date(date: &str, zone: &str) -> String {
    if date.is_empty() || date == "0" {
        return String::new();
    }
    let Ok(instant) = DateTime::parse_from_rfc3339(date) else {
        return date
            .get(..10)
            .filter(|prefix| chrono::NaiveDate::parse_from_str(prefix, "%Y-%m-%d").is_ok())
            .unwrap_or_default()
            .to_owned();
    };
    let utc = instant.with_timezone(&chrono::Utc);
    match zone.parse::<chrono_tz::Tz>() {
        Ok(zone) => utc.with_timezone(&zone).format("%Y-%m-%d").to_string(),
        Err(_) => utc.format("%Y-%m-%d").to_string(),
    }
}

#[async_trait]
impl TvProvider for TraktProvider {
    fn name(&self) -> &'static str {
        "Trakt"
    }

    fn site(&self) -> Site {
        Site::Trakt
    }

    fn available(&self) -> bool {
        self.client.is_configured()
    }

    async fn show_info(
        &self,
        name: &str,
        year: Option<i32>,
        _country: &str,
    ) -> Result<Option<Show>> {
        let results = self.client.search_shows(name).await?.unwrap_or(Value::Null);
        let mut best = 0.0;
        let mut highest = None;
        for result in php::items(Some(&results)) {
            let Some(show) = result.get("show").filter(|show| show.is_object()) else {
                continue;
            };
            let title = php::text(show.get("title"));
            if title.is_empty() || title == "0" {
                continue;
            }
            let started = if show.get("year").is_some_and(|year| !year.is_null()) {
                Started::Year(php::int(show.get("year")))
            } else {
                Started::None
            };
            let score = score_candidate(name, year, &title, started, "", "");
            if score > best {
                best = score;
                highest = Some(show);
            }
        }
        let Some(highest) = highest else {
            return Ok(None);
        };
        let trakt = php::int(highest.get("ids").and_then(|ids| ids.get("trakt")));
        if trakt == 0 {
            return Ok(None);
        }
        let full = self.client.show_summary(&trakt.to_string()).await?;
        match full.filter(|full| php::present(full.get("title")) && php::present(full.get("ids"))) {
            Some(full) => Ok(Some(self.format_show(&full).await)),
            None => Ok(None),
        }
    }

    async fn all_episodes(&self, site_id: i64, video_id: i64) -> Result<Vec<Episode>> {
        if site_id <= 0 || video_id <= 0 || !self.client.is_configured() {
            return Ok(Vec::new());
        }
        let zone = local_zone(&self.services.db, video_id).await?;
        let Some(seasons) = self
            .client
            .show_seasons(&site_id.to_string(), "full,episodes")
            .await?
        else {
            return Ok(Vec::new());
        };
        let mut formatted = Vec::new();
        for season in php::items(Some(&seasons)) {
            let number = season.get("number").map_or(-1, |n| php::int(Some(n)));
            if number < 0 {
                continue;
            }
            formatted.extend(
                php::items(season.get("episodes"))
                    .iter()
                    .filter(|episode| Self::has_required_episode(episode))
                    .map(|episode| Self::format_episode(episode, &zone)),
            );
        }
        Ok(formatted)
    }

    async fn episode(
        &self,
        site_id: i64,
        season: i64,
        episode: i64,
        video_id: i64,
    ) -> Result<Option<Episode>> {
        if season < 0 || episode <= 0 || !self.client.is_configured() {
            return Ok(None);
        }
        let mut zone = String::new();
        let response = if video_id > 0 {
            zone = local_zone(&self.services.db, video_id).await?;
            let ids = site_ids(&self.services.db, video_id).await?;
            let imdb = format_imdb_id(&ids.imdb).unwrap_or_default();
            self.client
                .episode_with_fallback(
                    EpisodeIds {
                        trakt: if site_id > 0 { site_id } else { ids.trakt },
                        tmdb: ids.tmdb,
                        tvdb: ids.tvdb,
                        imdb: &imdb,
                    },
                    season,
                    episode,
                )
                .await?
        } else if site_id > 0 {
            self.client
                .episode_by_trakt_id(site_id, season, episode)
                .await?
        } else {
            return Ok(None);
        };
        Ok(response
            .filter(Self::has_required_episode)
            .map(|found| Self::format_episode(&found, &zone)))
    }
}

/// A show's poster URL: the provider's own, then TMDB, then Fanart.tv. Port of `TvPosterResolver`.
pub async fn resolve_poster(services: &Services, show: &Show) -> String {
    if !show.poster.is_empty() {
        return show.poster.clone();
    }
    if show.tmdb > 0 && services.tmdb.is_configured() {
        match services.tmdb.tv_show(show.tmdb, &[]).await {
            Ok(Some(details)) => {
                let path = details
                    .get("poster_path")
                    .and_then(Value::as_str)
                    .unwrap_or_default();
                if !path.is_empty() {
                    return Tmdb::image_url(path);
                }
            }
            Ok(None) => {}
            Err(failure) => {
                info!(title = %show.title, error = %failure, "TV poster lookup failed");
                return String::new();
            }
        }
    }
    if show.tvdb > 0 && services.fanart.is_configured() {
        return services
            .fanart
            .best_tv_poster(show.tvdb)
            .await
            .unwrap_or_default();
    }
    String::new()
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn trakt_dates_are_local_to_the_show() {
        assert_eq!(
            trakt_date("2014-04-07T01:00:00.000Z", "America/New_York"),
            "2014-04-06"
        );
        assert_eq!(trakt_date("2014-04-07T01:00:00.000Z", ""), "2014-04-07");
        assert_eq!(
            trakt_date("2014-04-07T01:00:00.000Z", "Not/AZone"),
            "2014-04-07"
        );
        assert_eq!(trakt_date("", "UTC"), "");
        assert_eq!(trakt_date("nonsense", "UTC"), "");
    }

    #[test]
    fn trakt_images_come_in_two_shapes() {
        assert_eq!(
            image_url(Some(&json!({"full": "http://a/f.jpg", "thumb": "t"}))),
            "http://a/f.jpg"
        );
        assert_eq!(
            image_url(Some(&json!(["media.trakt.tv/p.jpg"]))),
            "https://media.trakt.tv/p.jpg"
        );
        assert_eq!(image_url(Some(&json!(null))), "");
        assert_eq!(image_url(None), "");
    }

    #[test]
    fn imdb_validity() {
        assert!(is_valid_imdb("0137523"));
        assert!(!is_valid_imdb("0000000"));
        assert!(!is_valid_imdb(""));
    }
}
