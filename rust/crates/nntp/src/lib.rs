//! NNTP client, connection pool and circuit breaker.
//!
//! [`NntpClient`] is one session with one provider at a time: connect with retries, select
//! groups, read overviews, and fetch article bodies under the budgets each caller needs.
//! [`pipeline`] drains pipelined verification requests over several connections,
//! [`NntpPool`] shares connections between tasks, and [`breaker`] stops hammering a provider
//! that has failed. [`fingerprint`] is the stricter client the fingerprint collector uses.
//!
//! Owner: ws-formats. See `rust/WORKSTREAMS.md`.

mod articles;
pub mod breaker;
mod client;
mod config;
mod error;
pub mod fingerprint;
mod overview;
pub mod pipeline;
mod pool;
mod transport;

pub use articles::{
    ArticleData, BoundedArticleResult, BoundedPreview, CommandResult, FingerprintArticle,
    FingerprintFullArticle, Identifier, MessageReuse, OnRead, ResponseMetadata, ServerProfile,
    verification_message_id,
};
pub use client::{GroupInfo, GroupSummary, NntpClient};
pub use config::{NntpConfig, Provider, TlsOptions};
pub use error::NntpError;
pub use overview::OverviewRow;
pub use pool::{NntpPool, PooledClient};
