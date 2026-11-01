//! What ingest needs from a news server. The `nntp` crate implements this for real connections,
//! and tests implement it with recorded articles.

use async_trait::async_trait;

use crate::groups::ServerGroup;
use crate::header::Overview;

/// Server says the group doesn't exist.
pub const NO_SUCH_GROUP: u16 = 411;
/// Server says no article with that number is selected.
pub const NO_CURRENT_ARTICLE: u16 = 420;
/// Server says no article has that number.
pub const NO_SUCH_ARTICLE: u16 = 423;

#[derive(Clone, Debug, thiserror::Error)]
#[error("NNTP {code:?}: {message}")]
pub struct NntpFailure {
    /// The response code, or None for a connection failure.
    pub code: Option<u16>,
    pub message: String,
}

impl NntpFailure {
    /// The server confirmed that no article has this number.
    pub fn no_article(&self) -> bool {
        matches!(self.code, Some(NO_CURRENT_ARTICLE | NO_SUCH_ARTICLE))
    }
}

/// A selected group's article bounds.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct SelectedGroup {
    pub name: String,
    pub first: u64,
    pub last: u64,
}

#[async_trait]
pub trait Nntp: Send {
    async fn select_group(&mut self, group: &str) -> Result<SelectedGroup, NntpFailure>;
    /// The overview of articles `first..=last` in the selected group.
    async fn xover(&mut self, first: u64, last: u64) -> Result<Vec<Overview>, NntpFailure>;
    /// Reconnects. The group must be selected again afterwards.
    async fn reconnect(&mut self, compression: bool) -> Result<(), NntpFailure>;
    /// Turns header compression back on after a retry without it.
    async fn enable_compression(&mut self) -> Result<(), NntpFailure>;
    async fn list_groups(&mut self) -> Result<Vec<ServerGroup>, NntpFailure>;
}
