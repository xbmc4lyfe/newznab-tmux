//! The admin area, and the account and GDPR housekeeping it schedules.
//!
//! Owner: ws-admin. See `rust/WORKSTREAMS.md`.

pub mod accounts;
pub mod captcha;
pub mod gdpr;
pub mod groups;
pub mod roles;
pub mod settings;

use anyhow::{Context, Result};
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
    /// Newsgroups (the admin group pages).
    #[command(subcommand)]
    Group(GroupCommand),
    /// Site, registration, ingestion, post-processing, metadata and tmux settings
    /// (the admin settings pages).
    #[command(subcommand)]
    Settings(SettingsCommand),
}

/// `nntmux-ctl admin settings ...`
#[derive(Debug, Subcommand)]
pub enum SettingsCommand {
    /// Show a domain's settings, or every domain's without one.
    Show {
        #[arg(value_enum)]
        domain: Option<settings::Domain>,
    },
    /// Change settings, as `column=value` pairs. Sizes take MB or GB (`500MB`); yes/no
    /// settings take 1 or 0; choices take their number or label.
    Set {
        #[arg(value_enum)]
        domain: settings::Domain,
        #[arg(required = true)]
        values: Vec<String>,
    },
    /// Replace the tmux cleanup rules. Without rules or `--clear`, list them.
    CleanupRules {
        rules: Vec<String>,
        /// Remove every rule.
        #[arg(long, conflicts_with = "rules")]
        clear: bool,
    },
    /// Replace the excluded tmux colors (0-255, inside the color range). Without colors or
    /// `--clear`, list them.
    ColorExclusions {
        #[arg(value_delimiter = ',')]
        colors: Vec<u8>,
        /// Remove every exclusion.
        #[arg(long, conflicts_with = "colors")]
        clear: bool,
    },
}

/// `nntmux-ctl admin group ...`. A group is named by its id or its full name.
#[derive(Debug, Subcommand)]
pub enum GroupCommand {
    /// List groups with their release counts.
    List {
        /// Only names containing this text.
        #[arg(long)]
        name: Option<String>,
        /// Only active groups.
        #[arg(long, conflicts_with = "inactive")]
        active: bool,
        /// Only inactive groups.
        #[arg(long)]
        inactive: bool,
        /// Only groups with backfill on.
        #[arg(long, conflicts_with = "no_backfill")]
        backfill: bool,
        /// Only groups with backfill off.
        #[arg(long)]
        no_backfill: bool,
    },
    /// Show one group.
    Show { group: String },
    /// Add a group. A leading `a.b.` becomes `alt.binaries.`.
    Add {
        name: String,
        #[arg(long, default_value = "")]
        description: String,
        /// Start updating it.
        #[arg(long)]
        active: bool,
        /// Backfill it.
        #[arg(long)]
        backfill: bool,
        /// Days to backfill (default 1).
        #[arg(long)]
        backfill_days: Option<i32>,
        /// Fewest files a release may have; the site setting applies without it.
        #[arg(long)]
        min_files: Option<i32>,
        /// Smallest release, such as 500MB or 2GB; the site setting applies without it.
        #[arg(long, value_parser = groups::parse_size)]
        min_size: Option<i64>,
    },
    /// Add every group on the news server whose name matches a regular expression
    /// (case-insensitive), active and backfilling unless told otherwise.
    BulkAdd {
        pattern: String,
        /// Add them turned off.
        #[arg(long)]
        inactive: bool,
        /// Add them without backfill.
        #[arg(long)]
        no_backfill: bool,
    },
    /// Change a group's settings.
    Edit {
        group: String,
        #[arg(long)]
        name: Option<String>,
        #[arg(long)]
        description: Option<String>,
        #[arg(long)]
        backfill_days: Option<i32>,
        #[arg(long)]
        first_record: Option<u64>,
        #[arg(long)]
        last_record: Option<u64>,
        #[arg(long)]
        active: Option<bool>,
        #[arg(long)]
        backfill: Option<bool>,
        /// Fewest files, or `default` for the site setting.
        #[arg(long, value_parser = parse_min_files)]
        min_files: Option<Minimum>,
        /// Smallest release such as 500MB, or `default` for the site setting.
        #[arg(long, value_parser = parse_min_size)]
        min_size: Option<Minimum>,
    },
    /// Turn updating on, or backfill with `--backfill`.
    Enable {
        group: String,
        #[arg(long)]
        backfill: bool,
    },
    /// Turn updating off, or backfill with `--backfill`.
    Disable {
        group: String,
        #[arg(long)]
        backfill: bool,
    },
    /// Drop a group's unfinished collections and part repair rows, start its counters over and
    /// turn it off. `--all` empties those tables for every group.
    Reset {
        group: Option<String>,
        #[arg(long, conflicts_with = "group")]
        all: bool,
        /// Confirm. Without it, only show what would change.
        #[arg(long)]
        yes: bool,
        /// Seconds to wait for active processing to drain first.
        #[arg(long, default_value_t = DRAIN_TIMEOUT)]
        drain_timeout: u64,
    },
    /// Reset a group (or `--all`) and delete its releases.
    Purge {
        group: Option<String>,
        #[arg(long, conflicts_with = "group")]
        all: bool,
        /// Confirm. Without it, only show what would change.
        #[arg(long)]
        yes: bool,
        /// Seconds to wait for active processing to drain first.
        #[arg(long, default_value_t = DRAIN_TIMEOUT)]
        drain_timeout: u64,
    },
    /// Purge a group, then remove it.
    Delete {
        group: String,
        /// Confirm. Without it, only show what would change.
        #[arg(long)]
        yes: bool,
        /// Seconds to wait for active processing to drain first.
        #[arg(long, default_value_t = DRAIN_TIMEOUT)]
        drain_timeout: u64,
    },
}

/// How long a destructive group change waits for processing to drain, as deploys do.
const DRAIN_TIMEOUT: u64 = 900;

/// A per-group minimum, or the site setting.
#[derive(Clone, Copy, Debug)]
pub struct Minimum(Option<i64>);

fn parse_min_files(text: &str) -> Result<Minimum> {
    if text.eq_ignore_ascii_case("default") {
        return Ok(Minimum(None));
    }
    let files: i32 = text
        .trim()
        .parse()
        .map_err(|_| anyhow::anyhow!("{text} isn't a whole number or `default`"))?;
    Ok(Minimum(Some(files.into())))
}

fn parse_min_size(text: &str) -> Result<Minimum> {
    if text.eq_ignore_ascii_case("default") {
        return Ok(Minimum(None));
    }
    Ok(Minimum(Some(groups::parse_size(text)?)))
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
        Command::Group(command) => group(&ctx, command, now).await?,
        Command::Settings(command) => settings_command(&ctx.db, command, now).await?,
    }
    Ok(())
}

async fn settings_command(
    db: &sqlx::MySqlPool,
    command: SettingsCommand,
    now: chrono::NaiveDateTime,
) -> Result<()> {
    match command {
        SettingsCommand::Show { domain } => {
            let domains =
                domain.map_or_else(|| settings::Domain::ALL.to_vec(), |domain| vec![domain]);
            for (index, domain) in domains.into_iter().enumerate() {
                if index > 0 {
                    println!();
                }
                println!("[{domain}] {}", domain.guidance());
                for (field, value) in settings::show(db, domain).await? {
                    println!("  {} = {}", field.column, field.display(value.as_deref()));
                }
                if domain == settings::Domain::Tmux {
                    println!(
                        "  cleanup_rules = {}",
                        settings::cleanup_rules(db).await?.join(",")
                    );
                    let colors = settings::color_exclusions(db).await?;
                    println!(
                        "  color_exclusions = {}",
                        colors
                            .iter()
                            .map(u8::to_string)
                            .collect::<Vec<_>>()
                            .join(",")
                    );
                }
            }
        }
        SettingsCommand::Set { domain, values } => {
            let values = settings::assignments(domain, &values)?;
            settings::update(db, domain, &values, now).await?;
            let shown = settings::show(db, domain).await?;
            for (field, _) in &values {
                if let Some((_, value)) =
                    shown.iter().find(|(shown, _)| shown.column == field.column)
                {
                    println!(
                        "{domain}.{} = {}",
                        field.column,
                        field.display(value.as_deref())
                    );
                }
            }
            println!("{}", domain.guidance());
        }
        SettingsCommand::CleanupRules { rules, clear } => {
            if clear || !rules.is_empty() {
                settings::set_cleanup_rules(db, &rules, now).await?;
            }
            let rules = settings::cleanup_rules(db).await?;
            println!(
                "cleanup rules: {}",
                if rules.is_empty() {
                    "(none)".to_owned()
                } else {
                    rules.join(", ")
                }
            );
        }
        SettingsCommand::ColorExclusions { colors, clear } => {
            if clear || !colors.is_empty() {
                settings::set_color_exclusions(db, &colors, now).await?;
            }
            let colors = settings::color_exclusions(db).await?;
            println!(
                "excluded colors: {}",
                if colors.is_empty() {
                    "(none)".to_owned()
                } else {
                    colors
                        .iter()
                        .map(u8::to_string)
                        .collect::<Vec<_>>()
                        .join(", ")
                }
            );
        }
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

fn flag(on: bool, off: bool) -> Option<bool> {
    match (on, off) {
        (true, _) => Some(true),
        (_, true) => Some(false),
        _ => None,
    }
}

fn print_group(group: &groups::Group) {
    let date = |at: Option<chrono::NaiveDateTime>| {
        at.map_or_else(
            || "-".to_owned(),
            |at| at.format("%Y-%m-%d %H:%M").to_string(),
        )
    };
    println!("ID:            {}", group.id);
    println!("Name:          {}", group.name);
    println!(
        "Description:   {}",
        group.description.as_deref().unwrap_or_default()
    );
    println!("Active:        {}", if group.active { "yes" } else { "no" });
    println!(
        "Backfill:      {}",
        if group.backfill { "yes" } else { "no" }
    );
    println!("Backfill days: {}", group.backfill_target);
    println!(
        "First record:  {} ({})",
        group.first_record,
        date(group.first_record_postdate)
    );
    println!(
        "Last record:   {} ({})",
        group.last_record,
        date(group.last_record_postdate)
    );
    println!("Last updated:  {}", date(group.last_updated));
    println!(
        "Min files:     {}",
        group
            .minfilestoformrelease
            .map_or_else(|| "default".to_owned(), |files| files.to_string())
    );
    println!(
        "Min size:      {}",
        groups::format_size(group.minsizetoformrelease)
    );
    println!("Releases:      {}", group.releases);
}

async fn server_groups() -> Result<Vec<nntmux_ingest::groups::ServerGroup>> {
    use nntmux_ingest::run::Connector as _;
    let connector =
        nntmux_ingest::client::ClientConnector::new(nntmux_nntp::NntpConfig::from_env());
    let mut connection = connector
        .connect()
        .await
        .context("Problem connecting to usenet.")?;
    let listed = connection
        .list_groups()
        .await
        .map_err(|failure| anyhow::anyhow!("{}", failure.message))
        .context("Problem fetching usenet_groups from usenet.")?;
    Ok(listed)
}

#[allow(clippy::too_many_lines)]
async fn group(ctx: &Ctx, command: GroupCommand, now: chrono::NaiveDateTime) -> Result<()> {
    let db = &ctx.db;
    match command {
        GroupCommand::List {
            name,
            active,
            inactive,
            backfill,
            no_backfill,
        } => {
            let found = groups::list(
                db,
                &groups::Filter {
                    name,
                    active: flag(active, inactive),
                    backfill: flag(backfill, no_backfill),
                },
            )
            .await?;
            println!("ID\tName\tActive\tBackfill\tReleases\tLast updated");
            for group in &found {
                println!(
                    "{}\t{}\t{}\t{}\t{}\t{}",
                    group.id,
                    group.name,
                    u8::from(group.active),
                    u8::from(group.backfill),
                    group.releases,
                    group.last_updated.map_or_else(
                        || "-".to_owned(),
                        |at| at.format("%Y-%m-%d %H:%M").to_string()
                    )
                );
            }
            println!("Total: {} groups", found.len());
        }
        GroupCommand::Show { group } => print_group(&groups::require(db, &group).await?),
        GroupCommand::Add {
            name,
            description,
            active,
            backfill,
            backfill_days,
            min_files,
            min_size,
        } => {
            let Some(name) = groups::valid_name(name.trim()) else {
                anyhow::bail!("{name} isn't a valid group name");
            };
            let (id, created) = groups::add(
                db,
                &groups::NewGroup {
                    name: name.clone(),
                    description,
                    active,
                    backfill,
                    backfill_target: backfill_days,
                    min_files,
                    min_size,
                },
            )
            .await?;
            if created {
                println!("Added {name} (ID {id}).");
            } else {
                println!("{name} already exists (ID {id}); nothing changed.");
            }
        }
        GroupCommand::BulkAdd {
            pattern,
            inactive,
            no_backfill,
        } => {
            let listed = server_groups().await?;
            let created = groups::add_bulk(db, &listed, &pattern, !inactive, !no_backfill).await?;
            if created.is_empty() {
                println!(
                    "No groups found with your regex or groups already exist in database, try again!"
                );
            }
            for group in &created {
                println!("Created {} (ID {})", group.name, group.id);
            }
        }
        GroupCommand::Edit {
            group,
            name,
            description,
            backfill_days,
            first_record,
            last_record,
            active,
            backfill,
            min_files,
            min_size,
        } => {
            let found = groups::require(db, &group).await?;
            let changes = groups::Changes {
                name,
                description,
                backfill_target: backfill_days,
                first_record,
                last_record,
                active,
                backfill,
                min_files: min_files
                    .map(|Minimum(files)| files.map(i32::try_from).transpose())
                    .transpose()?,
                min_size: min_size.map(|Minimum(size)| size),
            };
            if changes.is_empty() {
                anyhow::bail!("Nothing to change. See --help for the settings.");
            }
            groups::update(db, found.id, &changes, now).await?;
            print_group(&groups::require(db, &found.id.to_string()).await?);
        }
        GroupCommand::Enable { group, backfill } => {
            set_group_flag(db, &group, backfill, true).await?;
        }
        GroupCommand::Disable { group, backfill } => {
            set_group_flag(db, &group, backfill, false).await?;
        }
        GroupCommand::Reset {
            group,
            all,
            yes,
            drain_timeout,
        } => {
            if all {
                if !yes {
                    println!(
                        "Would empty collections, binaries, parts and missed_parts for every \
                         group and reset all groups. Add --yes to do it."
                    );
                    return Ok(());
                }
                let reset =
                    groups::reset_all(&exclusive(ctx, &verify_context(ctx)?, drain_timeout))
                        .await?;
                println!("All groups reset successfully ({reset}).");
                return Ok(());
            }
            let found = target(db, group).await?;
            if !yes {
                println!(
                    "Would reset {} (ID {}): its unfinished collections and part repair rows are \
                     dropped and it's turned off. Add --yes to do it.",
                    found.name, found.id
                );
                return Ok(());
            }
            groups::reset(
                &exclusive(ctx, &verify_context(ctx)?, drain_timeout),
                found.id,
            )
            .await?;
            println!("Group {} reset successfully", found.id);
        }
        GroupCommand::Purge {
            group,
            all,
            yes,
            drain_timeout,
        } => {
            let found = if all {
                None
            } else {
                Some(target(db, group).await?)
            };
            if !yes {
                match &found {
                    Some(found) => println!(
                        "Would reset {} (ID {}) and delete its {} release(s). Add --yes to do it.",
                        found.name, found.id, found.releases
                    ),
                    None => println!(
                        "Would reset every group and delete every release. Add --yes to do it."
                    ),
                }
                return Ok(());
            }
            let (deleted, failed) = groups::purge(
                &exclusive(ctx, &verify_context(ctx)?, drain_timeout),
                found.as_ref().map(|found| found.id),
            )
            .await?;
            println!("Deleted {deleted} release(s).");
            if failed > 0 {
                anyhow::bail!("{failed} release(s) couldn't be deleted; see the log.");
            }
            match found {
                Some(found) => println!("Group {} purged successfully", found.id),
                None => println!("All groups purged successfully"),
            }
        }
        GroupCommand::Delete {
            group,
            yes,
            drain_timeout,
        } => {
            let found = groups::require(db, &group).await?;
            if !yes {
                println!(
                    "Would delete {} (ID {}) and its {} release(s). Add --yes to do it.",
                    found.name, found.id, found.releases
                );
                return Ok(());
            }
            let (deleted, _) = groups::delete(
                &exclusive(ctx, &verify_context(ctx)?, drain_timeout),
                found.id,
            )
            .await?;
            println!(
                "Deleted {deleted} release(s). Group {} deleted successfully",
                found.id
            );
        }
    }
    Ok(())
}

async fn target(db: &sqlx::MySqlPool, group: Option<String>) -> Result<groups::Group> {
    let Some(group) = group else {
        anyhow::bail!("Name a group, or use --all.");
    };
    groups::require(db, &group).await
}

async fn set_group_flag(db: &sqlx::MySqlPool, group: &str, backfill: bool, on: bool) -> Result<()> {
    let found = groups::require(db, group).await?;
    let flag = if backfill {
        groups::Flag::Backfill
    } else {
        groups::Flag::Active
    };
    groups::set_flag(db, found.id, flag, on).await?;
    println!(
        "{} {} for {} (ID {}).",
        if backfill { "Backfill" } else { "Updating" },
        if on { "enabled" } else { "disabled" },
        found.name,
        found.id
    );
    Ok(())
}

fn verify_context(ctx: &Ctx) -> Result<nntmux_verify::context::Verify> {
    Ok(nntmux_verify::context::Verify::new(
        ctx.db.clone(),
        nntmux_verify::cache::Cache::new(ctx.redis.clone(), "nntmux:"),
        nntmux_verify::config::VerificationConfig::from_env()?,
    ))
}

fn exclusive<'a>(
    ctx: &'a Ctx,
    verify: &'a nntmux_verify::context::Verify,
    drain_timeout: u64,
) -> groups::Exclusive<'a> {
    groups::Exclusive {
        ctx,
        verify,
        drain_timeout,
    }
}
