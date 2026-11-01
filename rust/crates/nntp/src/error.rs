#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum NntpError {
    /// The server answered with a code that isn't what the command needed.
    #[error("{message}")]
    Server {
        code: u16,
        message: String,
        text: String,
    },
    /// No usable response: a timeout, a lost connection or a malformed reply.
    #[error("{0}")]
    Transport(String),
    /// A complete "no such article" reply. The connection is still usable.
    #[error("{0}")]
    ArticleMissing(String),
    #[error("{0}")]
    ProviderUnavailable(String),
    #[error("{0}")]
    BudgetExceeded(String),
    /// This request wasn't answered, because an earlier failure closed its connection.
    /// It says nothing about the article, so it's safe to retry.
    #[error("{0}")]
    PipelineRetry(String),
    #[error("{0}")]
    Invalid(String),
}

impl NntpError {
    pub(crate) fn transport(message: impl Into<String>) -> Self {
        Self::Transport(message.into())
    }

    /// The response code, when the server sent one.
    pub fn code(&self) -> Option<u16> {
        match self {
            Self::Server { code, .. } => Some(*code),
            _ => None,
        }
    }

    pub fn is_missing_article(&self) -> bool {
        matches!(self, Self::ArticleMissing(_)) || matches!(self.code(), Some(423 | 430))
    }
}
