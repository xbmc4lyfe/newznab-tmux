//! The article source backed by the NNTP client: one pooled session, held for as long as the
//! source lives, so a standard batch or a sweep doesn't reconnect per release.

use async_trait::async_trait;
use nntmux_nntp::{NntpConfig, NntpPool, PooledClient};

use crate::par2_recovery::{ArticleSource, Fetched};

pub struct NntpArticles {
    pool: NntpPool,
    /// `USE_ALTERNATE_NNTP_SERVER`: the session is the alternate provider's from the start.
    use_alternate: bool,
    client: Option<PooledClient>,
}

impl NntpArticles {
    pub fn new(config: NntpConfig) -> Self {
        let use_alternate = config.use_alternate;
        Self {
            pool: NntpPool::new(config),
            use_alternate,
            client: None,
        }
    }

    /// A source over a pool the caller shares, so sessions are reused across passes.
    pub fn with_pool(pool: NntpPool, use_alternate: bool) -> Self {
        Self {
            pool,
            use_alternate,
            client: None,
        }
    }

    pub fn from_env() -> Self {
        Self::new(NntpConfig::from_env())
    }
}

#[async_trait]
impl ArticleSource for NntpArticles {
    async fn connect(&mut self) -> Result<(), String> {
        if self.client.is_some() {
            return Ok(());
        }
        match self.pool.acquire(self.use_alternate).await {
            Ok(client) => {
                self.client = Some(client);
                Ok(())
            }
            Err(error) => Err(error.to_string()),
        }
    }

    async fn get_message_bounded(
        &mut self,
        group: &str,
        message_id: &str,
        max_decoded_bytes: usize,
        alternate: bool,
        on_read: &mut (dyn FnMut() -> bool + Send),
    ) -> Fetched {
        if self.connect().await.is_err() {
            return Fetched::default();
        }
        let Some(client) = self.client.as_mut() else {
            return Fetched::default();
        };
        let limit = i64::try_from(max_decoded_bytes).unwrap_or(i64::MAX);
        match client
            .get_message_bounded(group, message_id, limit, alternate, Some(on_read))
            .await
        {
            Ok(article) => Fetched {
                body: article.body,
                part: article.part,
                oversized: article.oversized,
            },
            Err(error) => {
                tracing::debug!(%error, "PAR2 article fetch failed");
                Fetched::default()
            }
        }
    }
}
