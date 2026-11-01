//! The admin area, and the account and GDPR housekeeping it schedules.
//!
//! Owner: ws-admin. See `rust/WORKSTREAMS.md`.

pub mod accounts;
pub mod gdpr;

use anyhow::Result;
use axum::Router;
use chrono::Utc;
use clap::Subcommand;
use nntmux_runtime::Ctx;

pub fn router() -> Router<Ctx> {
    Router::new()
}

/// `nntmux-ctl admin ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Soft-delete User and FreeUser accounts left unverified for three days
    /// (`nntmux:delete-unverified-users`).
    DeleteUnverifiedUsers,
    /// Soft-delete User accounts with no login, API use or download within
    /// `PURGE_INACTIVE_USERS_DAYS` (the `RemoveInactiveAccounts` job).
    RemoveInactiveAccounts,
    /// Permanently remove accounts soft-deleted more than `PURGE_INACTIVE_USERS_DAYS` ago
    /// (`nntmux:purge-deleted-accounts`).
    PurgeDeletedAccounts,
    /// Delete GDPR export files past their expiry, keeping the requests
    /// (`gdpr:purge-expired-exports`).
    GdprPurgeExpiredExports,
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    let now = Utc::now().naive_utc();
    match command {
        Command::DeleteUnverifiedUsers => {
            let deleted = accounts::delete_unverified(&ctx.db, now).await?;
            println!("deleted {deleted} unverified user(s)");
        }
        Command::RemoveInactiveAccounts => {
            let removed = accounts::remove_inactive(&ctx, now, accounts::purge_days()?).await?;
            println!("removed {removed} inactive account(s)");
        }
        Command::PurgeDeletedAccounts => {
            let purged = accounts::purge_deleted(&ctx, now, accounts::purge_days()?).await?;
            println!("purged {purged} deleted account(s)");
        }
        Command::GdprPurgeExpiredExports => {
            let purged = gdpr::purge_expired_exports(&ctx.db, &gdpr::storage_dir(), now).await?;
            println!("Purged {purged} expired GDPR export file(s).");
        }
    }
    Ok(())
}
