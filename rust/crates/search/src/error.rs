/// Why a search call produced no answer. Callers must not show any of these as "no matches".
#[derive(Debug, thiserror::Error)]
pub enum SearchError {
    #[error("{0} isn't ported to Rust yet")]
    NotPorted(&'static str),
    #[error("Manticore is unreachable: {0}")]
    Unavailable(String),
    /// Manticore couldn't parse or run this query text, as with a lone `!word` or unbalanced
    /// brackets. Nothing is wrong with the service, so a site can show this as no results, as
    /// PHP always did.
    #[error("Manticore can't run this query: {0}")]
    RejectedQuery(String),
    /// Manticore refused the request for another reason, such as a missing table or column.
    #[error("Manticore refused the request: {0}")]
    Query(String),
    /// The page starts past `max_matches`, the most Manticore will page through. Matches exist
    /// there, but it won't return them.
    #[error("offset {offset} is past the {max_matches} results Manticore pages through")]
    BeyondWindow { offset: u64, max_matches: u64 },
    #[error("Manticore sent a malformed response: {0}")]
    Malformed(String),
    #[error("the database failed: {0}")]
    Database(String),
    /// The caller passed an argument this call can't use.
    #[error("invalid search argument: {0}")]
    InvalidArgument(&'static str),
    #[error("the search cursor is invalid or expired")]
    InvalidCursor,
    #[error("the search cursor doesn't match this query or index generation")]
    CursorMismatch,
}
