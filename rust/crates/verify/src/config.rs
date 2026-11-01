//! `config/release_verification.php`, read from the same environment variables.

use std::path::PathBuf;

use anyhow::Result;
use nntmux_core::{parsed_or, var_or};

#[derive(Clone, Debug)]
pub struct VerificationConfig {
    /// Shared capacity for stored candidate manifests.
    pub pending_bytes: i64,
    /// Most header metadata one candidate may hold, counted as 128 bytes plus the message-ID.
    pub metadata_bytes: i64,
    pub expiry_hours: i64,
    pub lease_seconds: i64,
    pub retry_seconds: i64,
    pub deterministic_retry_seconds: i64,
    pub partial_after_hours: i64,
    pub partial_ready_limit: i64,
    /// Candidate worker slots on this host.
    pub candidate_workers: i64,
    /// Hosts sharing one database schedule disjoint slots: this host runs `slot_offset + 1` through
    /// `slot_offset + candidate_workers`.
    pub slot_offset: i64,
    /// Names this host's scratch inventory and budget. The primary host keeps an empty name.
    pub host: String,
    /// Where legacy manifest files, scratch directories and reports live.
    pub storage_path: PathBuf,
}

impl VerificationConfig {
    pub fn from_env() -> Result<Self> {
        Ok(Self {
            pending_bytes: parsed_or("RELEASE_VERIFICATION_PENDING_BYTES", 10_737_418_240)?,
            metadata_bytes: parsed_or("RELEASE_VERIFICATION_METADATA_BYTES", 268_435_456)?,
            expiry_hours: 48,
            lease_seconds: 300,
            retry_seconds: 900,
            deterministic_retry_seconds: parsed_or(
                "RELEASE_VERIFICATION_DETERMINISTIC_RETRY_SECONDS",
                86_400,
            )?,
            partial_after_hours: parsed_or("RELEASE_VERIFICATION_PARTIAL_AFTER_HOURS", 4)?,
            partial_ready_limit: parsed_or("RELEASE_VERIFICATION_PARTIAL_READY_LIMIT", 500)?,
            candidate_workers: parsed_or("RELEASE_VERIFICATION_CANDIDATE_WORKERS", 2)?,
            slot_offset: parsed_or("RELEASE_VERIFICATION_SLOT_OFFSET", 0)?,
            host: var_or("RELEASE_VERIFICATION_HOST", ""),
            storage_path: PathBuf::from(var_or(
                "RELEASE_VERIFICATION_STORAGE_PATH",
                "/app/storage/app/release-verification",
            )),
        })
    }

    /// Defaults for tests, with no environment involved.
    pub fn for_tests(storage_path: PathBuf) -> Self {
        Self {
            pending_bytes: 10_737_418_240,
            metadata_bytes: 268_435_456,
            expiry_hours: 48,
            lease_seconds: 300,
            retry_seconds: 900,
            deterministic_retry_seconds: 86_400,
            partial_after_hours: 4,
            partial_ready_limit: 500,
            candidate_workers: 2,
            slot_offset: 0,
            host: String::new(),
            storage_path,
        }
    }

    /// The per-candidate metadata budget, never below one byte.
    pub fn metadata_budget(&self) -> i64 {
        self.metadata_bytes.max(1)
    }
}
