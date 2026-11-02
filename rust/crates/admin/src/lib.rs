//! The admin area, and the account and GDPR housekeeping it schedules.
//!
//! Owner: ws-admin. See `rust/WORKSTREAMS.md`.

pub mod accounts;
pub mod captcha;
pub mod gdpr;
pub mod roles;

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
    /// Show which CAPTCHA provider is configured and whether it's complete (`captcha:status`).
    CaptchaStatus,
    /// Role stacking (`role:manage`).
    #[command(subcommand)]
    Role(RoleCommand),
}

/// `nntmux-ctl admin role ...`
#[derive(Debug, Subcommand)]
pub enum RoleCommand {
    /// List users waiting for a stacked role.
    ListPending,
    /// Cancel a user's pending role, or every pending role with `--all`.
    CancelPending {
        /// User id or username.
        user: Option<String>,
        /// Cancel for every user.
        #[arg(long)]
        all: bool,
        /// Confirm the change. Without it, only show what would change.
        #[arg(long)]
        yes: bool,
    },
    /// Show a user's role change history.
    History {
        /// User id or username.
        user: String,
    },
    /// Activate pending roles whose start date has passed, which the expiry job normally does.
    ActivatePending {
        /// Confirm the change. Without it, only show what would change.
        #[arg(long)]
        yes: bool,
    },
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
        Command::CaptchaStatus => {
            for line in captcha::report() {
                println!("{line}");
            }
        }
        Command::Role(command) => role(&ctx, command, now).await?,
    }
    Ok(())
}

async fn role(ctx: &Ctx, command: RoleCommand, now: chrono::NaiveDateTime) -> Result<()> {
    match command {
        RoleCommand::ListPending => {
            let pending = roles::list_pending(&ctx.db).await?;
            if pending.is_empty() {
                println!("No users with pending roles found.");
                return Ok(());
            }
            println!("User ID\tUsername\tCurrent Role\tPending Role\tActivation Date");
            for user in &pending {
                println!(
                    "{}\t{}\t{}\t{}\t{}",
                    user.id,
                    user.username,
                    user.current,
                    user.pending,
                    user.starts.format("%Y-%m-%d %H:%M")
                );
            }
            println!("Total: {} users", pending.len());
        }
        RoleCommand::CancelPending { user, all, yes } => {
            if all {
                if !yes {
                    let count = roles::list_pending(&ctx.db).await?.len();
                    println!(
                        "{count} pending role(s) would be cancelled. Add --yes to cancel them."
                    );
                    return Ok(());
                }
                let cancelled = roles::cancel_all_pending(&ctx.db).await?;
                for name in &cancelled {
                    println!("Cancelled pending role for user: {name}");
                }
                println!("Cancelled pending roles for {} users.", cancelled.len());
                return Ok(());
            }
            let Some(user) = user else {
                anyhow::bail!("User ID or username required. Use --all to cancel for all users.");
            };
            let account = roles::require(&ctx.db, &user).await?;
            let (Some(pending), Some(_)) =
                (account.pending_roles_id, account.pending_role_start_date)
            else {
                println!("User {} has no pending role.", account.username);
                return Ok(());
            };
            let name = roles::role_name(&ctx.db, pending.into())
                .await?
                .unwrap_or_else(|| "Unknown".to_owned());
            if !yes {
                println!(
                    "Would cancel pending role '{name}' for user '{}'. Add --yes to cancel it.",
                    account.username
                );
                return Ok(());
            }
            if !roles::cancel_pending(&ctx.db, account.id).await? {
                anyhow::bail!("Failed to cancel pending role.");
            }
            println!(
                "Successfully cancelled pending role for user: {}",
                account.username
            );
        }
        RoleCommand::History { user } => {
            let account = roles::require(&ctx.db, &user).await?;
            let history = roles::history(&ctx.db, account.id).await?;
            if history.is_empty() {
                println!(
                    "No role change history found for user: {}",
                    account.username
                );
                return Ok(());
            }
            println!(
                "Role Change History for: {} (ID: {})",
                account.username, account.id
            );
            println!("Date\tFrom Role\tTo Role\tType\tReason\tChanged By");
            for change in &history {
                println!(
                    "{}\t{}\t{}\t{}\t{}\t{}",
                    change.effective.format("%Y-%m-%d %H:%M"),
                    change.from,
                    change.to,
                    if change.stacked {
                        "Stacked"
                    } else {
                        "Immediate"
                    },
                    change.reason.as_deref().unwrap_or("N/A"),
                    change.changed_by
                );
            }
            println!("Total changes: {}", history.len());
            let stacked = history.iter().filter(|change| change.stacked).count();
            if stacked > 0 {
                println!("Stacked role changes: {stacked}");
            }
        }
        RoleCommand::ActivatePending { yes } => {
            if !yes {
                let due = roles::list_pending(&ctx.db)
                    .await?
                    .into_iter()
                    .filter(|user| user.starts <= now)
                    .count();
                println!(
                    "{due} pending role(s) are ready for activation. Add --yes to activate them."
                );
                return Ok(());
            }
            let (activated, failed) = roles::activate_pending(ctx, now).await?;
            if activated.is_empty() && failed.is_empty() {
                println!("No pending roles ready for activation.");
                return Ok(());
            }
            for change in &activated {
                println!(
                    "✓ Activated {} -> {} for user: {}",
                    change.from, change.to, change.username
                );
            }
            for (name, reason) in &failed {
                println!("✗ Failed to activate role for user {name}: {reason}");
            }
            println!("Activation complete:\n  Successful: {}", activated.len());
            if !failed.is_empty() {
                anyhow::bail!("  Failed: {}", failed.len());
            }
        }
    }
    Ok(())
}
