/// Why a search call produced no answer. Callers must not show any of these as "no matches".
#[derive(Debug, thiserror::Error)]
pub enum SearchError {
    #[error("{0} isn't ported to Rust yet")]
    NotPorted(&'static str),
    #[error("Manticore is unreachable: {0}")]
    Unavailable(String),
    #[error("Manticore rejected the request: {0}")]
    Query(String),
    #[error("Manticore sent a malformed response: {0}")]
    Malformed(String),
    #[error("the search cursor is invalid or expired")]
    InvalidCursor,
    #[error("the search cursor doesn't match this query or index generation")]
    CursorMismatch,
}

impl SearchError {
    /// True when Manticore refused this query, as with a lone `!word` or unbalanced brackets,
    /// and is otherwise fine. A site can show that as no results; PHP always did.
    pub fn is_rejected_query(&self) -> bool {
        matches!(self, Self::Query(_))
    }
}
