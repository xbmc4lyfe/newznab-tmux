//! The handles and provider clients a lookup needs.

use std::sync::{Arc, OnceLock};

use anyhow::Result;
use nntmux_core::{optional, parsed_or};
use nntmux_runtime::Ctx;
use nntmux_search::SearchClient;
use reqwest::Client;
use sqlx::MySqlPool;
use tracing::{error, warn};

use crate::cache::Cache;
use crate::clients::fanart::Fanart;
use crate::clients::tmdb::Tmdb;
use crate::clients::trakt::Trakt;
use crate::clients::tvdb::Tvdb;
use crate::clients::tvmaze::TvMaze;
use crate::images::Images;

pub struct Services {
    pub db: MySqlPool,
    pub cache: Cache,
    /// Unset in tests that don't run an index.
    pub search: Option<SearchClient>,
    pub images: Images,
    pub http: Client,
    pub tmdb: Tmdb,
    pub tvdb: Tvdb,
    pub tvmaze: TvMaze,
    pub trakt: Trakt,
    pub fanart: Fanart,
}

static SHARED: OnceLock<Arc<Services>> = OnceLock::new();

pub fn key(name: &str) -> String {
    optional(name).unwrap_or_default().trim().to_owned()
}

impl Services {
    /// One set per process, so a TVDB token and the throttles outlive a single pass.
    pub fn shared(ctx: &Ctx) -> Result<Arc<Self>> {
        if let Some(services) = SHARED.get() {
            return Ok(services.clone());
        }
        let services = Arc::new(Self::from_ctx(ctx)?);
        Ok(SHARED.get_or_init(|| services).clone())
    }

    pub fn from_ctx(ctx: &Ctx) -> Result<Self> {
        let http = Client::builder()
            .connect_timeout(std::time::Duration::from_secs(10))
            .user_agent("NNTmux")
            .build()?;
        Ok(Self {
            db: ctx.db.clone(),
            cache: Cache::new(ctx.redis.clone()),
            search: Some(SearchClient::from_ctx(ctx)?),
            images: Images::from_env()?,
            tmdb: Tmdb::new(
                http.clone(),
                key("TMDB_APIKEY"),
                parsed_or("TMDB_TIMEOUT", 30)?,
                parsed_or("TMDB_RETRY_TIMES", 3)?,
                parsed_or("TMDB_RETRY_DELAY", 100)?,
            ),
            tvdb: Tvdb::new(
                http.clone(),
                key("TVDB_APIKEY"),
                optional("TVDB_PIN"),
                parsed_or("TVDB_TIMEOUT", 30)?,
            ),
            tvmaze: TvMaze::new(
                http.clone(),
                parsed_or("TVMAZE_TIMEOUT", 30)?,
                parsed_or("TVMAZE_RETRY_TIMES", 2)?,
            ),
            trakt: Trakt::new(
                http.clone(),
                Cache::new(ctx.redis.clone()),
                key("TRAKTTV_APIKEY"),
                parsed_or("TRAKTTV_TIMEOUT", 30)?,
                parsed_or("TRAKTTV_RETRY_TIMES", 3)?,
                parsed_or("TRAKTTV_RETRY_DELAY", 100)?,
            ),
            fanart: Fanart::new(
                http.clone(),
                Cache::new(ctx.redis.clone()),
                key("FANARTTV_APIKEY"),
                parsed_or("FANARTTV_TIMEOUT", 30)?,
                parsed_or("FANARTTV_RETRY_TIMES", 3)?,
                parsed_or("FANARTTV_RETRY_DELAY", 100)?,
            ),
            http,
        })
    }

    /// Refreshes one release's search document. A failure is logged and repaired by the sweep.
    pub async fn sync_release(&self, release_id: i64) {
        if let Some(search) = &self.search {
            search.update_release(&self.db, release_id).await;
        }
    }

    pub async fn sync_releases(&self, ids: &[i64]) {
        if let Some(search) = &self.search {
            for chunk in ids.chunks(500) {
                search.update_releases(&self.db, chunk).await;
            }
        }
    }

    /// `ReleaseSearchIndexSync::forVideo`.
    pub async fn sync_video_releases(&self, video_id: i64) {
        if video_id <= 0 || self.search.is_none() {
            return;
        }
        let mut last = 0_i64;
        loop {
            let ids: Vec<i64> = match sqlx::query_scalar(
                "SELECT CAST(id AS SIGNED) FROM releases WHERE videos_id = ? AND id > ? \
                 ORDER BY id LIMIT 500",
            )
            .bind(video_id)
            .bind(last)
            .fetch_all(&self.db)
            .await
            {
                Ok(ids) => ids,
                Err(failure) => {
                    error!(video_id, error = %failure, "couldn't list a show's releases to reindex");
                    return;
                }
            };
            let Some(&next) = ids.last() else {
                return;
            };
            last = next;
            self.sync_releases(&ids).await;
        }
    }

    /// Stale cached release lists only last until they expire, so a failure here isn't fatal.
    pub async fn bump_release_cache(&self) {
        if let Err(failure) = self.cache.bump_release_version().await {
            warn!(
                error = format!("{failure:#}"),
                "couldn't bump the release cache version"
            );
        }
    }
}
