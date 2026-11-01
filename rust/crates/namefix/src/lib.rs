//! Release name fixing.
//!
//! Owner: ws-naming. See `rust/WORKSTREAMS.md`.

pub mod checkers;
pub mod commands;
pub mod compat;
pub mod donor;
pub mod extractors;
pub mod file_prioritizer;
pub mod guard;
pub mod identity_key;
pub mod par2_progress;
pub mod predb_constrained;
pub mod queries;
pub mod release;
pub mod result;
pub mod service;
pub mod update;

pub use commands::{Command, ctl, run};
