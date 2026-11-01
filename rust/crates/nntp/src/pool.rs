//! Shares connections between tasks without exceeding a provider's connection limit.

use std::ops::{Deref, DerefMut};
use std::sync::{Arc, Mutex};

use tokio::sync::{OwnedSemaphorePermit, Semaphore};

use crate::client::NntpClient;
use crate::config::NntpConfig;
use crate::error::NntpError;

struct Slot {
    permits: Arc<Semaphore>,
    idle: Mutex<Vec<NntpClient>>,
}

impl Slot {
    fn new(connections: usize) -> Self {
        Self {
            permits: Arc::new(Semaphore::new(connections.max(1))),
            idle: Mutex::new(Vec::new()),
        }
    }
}

/// Connection limits per provider, from `NNTP_CONNECTIONS` and `NNTP_CONNECTIONS_A`.
#[derive(Clone)]
pub struct NntpPool {
    config: Arc<NntpConfig>,
    primary: Arc<Slot>,
    alternate: Arc<Slot>,
}

impl NntpPool {
    pub fn new(config: NntpConfig) -> Self {
        let primary = Arc::new(Slot::new(config.primary_connections));
        let alternate = Arc::new(Slot::new(config.alternate_connections));
        Self {
            config: Arc::new(config),
            primary,
            alternate,
        }
    }

    /// Waits for a free slot and returns a connected client. A failed connect gives the slot back.
    pub async fn acquire(&self, alternate: bool) -> Result<PooledClient, NntpError> {
        let slot = if alternate {
            &self.alternate
        } else {
            &self.primary
        };
        let permit = Arc::clone(&slot.permits)
            .acquire_owned()
            .await
            .map_err(|_| NntpError::transport("The NNTP pool is closed."))?;
        let idle = slot.idle.lock().map_or(None, |mut idle| idle.pop());
        let mut client = idle.unwrap_or_else(|| NntpClient::new(Arc::clone(&self.config)));
        // do_connect keeps a live session on this provider and replaces a dead one.
        client
            .do_connect(self.config.compressed_headers, alternate)
            .await?;
        Ok(PooledClient {
            client: Some(client),
            slot: Arc::clone(slot),
            _permit: permit,
        })
    }
}

/// Goes back to the pool on drop. A client that lost its connection, or was dropped with a
/// response unread, is discarded instead.
pub struct PooledClient {
    client: Option<NntpClient>,
    slot: Arc<Slot>,
    _permit: OwnedSemaphorePermit,
}

impl Deref for PooledClient {
    type Target = NntpClient;
    fn deref(&self) -> &NntpClient {
        self.client.as_ref().expect("present until drop")
    }
}

impl DerefMut for PooledClient {
    fn deref_mut(&mut self) -> &mut NntpClient {
        self.client.as_mut().expect("present until drop")
    }
}

impl Drop for PooledClient {
    fn drop(&mut self) {
        if let Some(client) = self.client.take()
            && client.reusable()
            && let Ok(mut idle) = self.slot.idle.lock()
        {
            idle.push(client);
        }
    }
}
