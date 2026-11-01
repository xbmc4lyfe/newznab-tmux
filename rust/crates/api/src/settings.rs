//! Values the API reads from the environment. The names match Laravel's `config/` files.

use std::collections::HashMap;
use std::path::PathBuf;
use std::sync::{Mutex, OnceLock};
use std::time::{Duration, Instant};

use anyhow::Result;
use nntmux_core::{optional, parsed_or, var_or};
use nntmux_runtime::Ctx;
use nntmux_search::{CursorCodec, SearchClient, SearchConfig};

use crate::abuse::AbuseConfig;
use crate::json::J;

pub struct Settings {
    pub app_name: String,
    pub mail_from: String,
    pub max_limit: i64,
    pub default_limit: i64,
    pub query_max_body_bytes: usize,
    pub access_update_interval: u64,
    /// Prefixes every Redis key, so parallel tests can't collide. Empty in production.
    pub redis_prefix: String,
    /// Where cover images live, checked in order.
    pub cover_roots: Vec<PathBuf>,
    pub abuse: AbuseConfig,
    pub upload_folder: Option<PathBuf>,
    /// Replaces the Manticore settings from the environment, for tests.
    pub search_config: Option<SearchConfig>,
    /// Replaces the `APP_KEY` cursor signing, for tests.
    pub cursor_codec: Option<CursorCodec>,
    search_client: OnceLock<SearchClient>,
    /// Small read-mostly values such as the v1 server menu, which every XML response needs.
    local: Mutex<HashMap<String, (Instant, J)>>,
}

impl Settings {
    pub fn from_env() -> Result<Self> {
        let mut settings = Self::new(
            var_or("APP_NAME", "NNTmux"),
            var_or("MAIL_FROM_ADDRESS", "hello@example.com"),
            parsed_or("API_MAX_LIMIT", 500)?,
            parsed_or("API_DEFAULT_LIMIT", 250)?,
            parsed_or("API_QUERY_MAX_BODY_BYTES", 8192)?,
            parsed_or("API_ACCESS_UPDATE_INTERVAL", 60)?,
            var_or("NNTMUX_API_REDIS_PREFIX", ""),
        );
        settings.abuse = AbuseConfig::from_env()?;
        Ok(settings)
    }

    pub fn new(
        app_name: String,
        mail_from: String,
        max_limit: i64,
        default_limit: i64,
        query_max_body_bytes: usize,
        access_update_interval: u64,
        redis_prefix: String,
    ) -> Self {
        Self {
            app_name,
            mail_from,
            max_limit,
            default_limit,
            query_max_body_bytes,
            access_update_interval,
            redis_prefix,
            cover_roots: vec![
                PathBuf::from(var_or("COVERS_PATH", "/app/storage/covers")),
                PathBuf::from("/app/storage/covers"),
                PathBuf::from("/app/public/covers"),
            ],
            abuse: AbuseConfig::default(),
            upload_folder: optional("NZB_UPLOAD_FOLDER").map(PathBuf::from),
            search_config: None,
            cursor_codec: None,
            search_client: OnceLock::new(),
            local: Mutex::new(HashMap::new()),
        }
    }

    /// The Manticore client, built on first use.
    pub fn search(&self, ctx: &Ctx) -> Result<&SearchClient> {
        if let Some(client) = self.search_client.get() {
            return Ok(client);
        }
        let client = match &self.search_config {
            Some(config) => SearchClient::new(config.clone(), Some(ctx.redis.clone()))?,
            None => SearchClient::from_ctx(ctx)?,
        };
        Ok(self.search_client.get_or_init(|| client))
    }

    pub fn cursors(&self, client: &SearchClient) -> Result<CursorCodec> {
        match &self.cursor_codec {
            Some(codec) => Ok(codec.clone()),
            None => CursorCodec::from_env(&client.config().index_generation),
        }
    }

    /// `ApiQueryParameters::maxLimit`.
    pub fn limit_max(&self) -> i64 {
        self.max_limit.max(1)
    }

    /// `ApiQueryParameters::defaultLimit`.
    pub fn limit_default(&self) -> i64 {
        self.default_limit.min(self.limit_max()).max(1)
    }

    pub fn local_get(&self, key: &str) -> Option<J> {
        let cache = self.local.lock().ok()?;
        let (expires, value) = cache.get(key)?;
        (*expires > Instant::now()).then(|| value.clone())
    }

    pub fn local_put(&self, key: &str, ttl: Duration, value: J) {
        if let Ok(mut cache) = self.local.lock() {
            cache.insert(key.to_owned(), (Instant::now() + ttl, value));
        }
    }
}
