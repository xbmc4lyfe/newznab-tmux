//! The handles verification work runs with.

use std::sync::Arc;

use sqlx::MySqlPool;

use crate::cache::Cache;
use crate::config::VerificationConfig;

#[derive(Clone)]
pub struct Verify {
    pub db: MySqlPool,
    pub cache: Cache,
    pub config: Arc<VerificationConfig>,
}

impl Verify {
    pub fn new(db: MySqlPool, cache: Cache, config: VerificationConfig) -> Self {
        Self {
            db,
            cache,
            config: Arc::new(config),
        }
    }
}
