//! One-time, read-only importer from the old MariaDB database.
//!
//! The source is the only copy of its data, so nothing here can write to it: the
//! login should be SELECT-only, every session is `READ ONLY`, and [`Source`] only
//! runs statements that start with `SELECT`. Rows and NZBs are copied in batches
//! that commit with their resume point, so a stopped import continues where it
//! left off. [`verify`] then compares every row and every NZB with the source.

mod convert;
mod legacy;
mod plan;
mod run;
mod source;
mod spec;
mod verify;
mod writer;

pub use run::{ImportOptions, ImportReport, probe, run};
pub use source::{Credentials, Source, SourceConfig, Tls};
pub use verify::{VerifyReport, verify};
