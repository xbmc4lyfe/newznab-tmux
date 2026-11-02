//! The role half of the admin user edit form: cancelling a pending role, setting the current
//! role's expiry by hand, and `User::updateUserRole` with its stacking and promotions.

use anyhow::{Context, Result, ensure};
use chrono::{Duration, NaiveDate, NaiveDateTime};
use nntmux_runtime::Ctx;
use sqlx::{FromRow, MySqlPool};
use tracing::warn;

use crate::accounts::forget_api_caches;
use crate::roles::{HistoryEntry, log_role_updated, record_history, sync_role};
use crate::users::{ACTOR, Account};

/// `User::PROMOTION_EXCLUDED_ROLES`: roles promotions never lengthen.
const PROMOTION_EXCLUDED: &[&str] = &["User", "Admin", "Moderator", "Disabled", "Friend"];

const DAYS_PER_YEAR: i64 = 365;

#[derive(Clone, Debug, FromRow)]
pub struct Role {
    pub id: u32,
    pub name: String,
    pub addyears: i32,
    pub rate_limit: i32,
}

const ROLE: &str = "SELECT id, name, addyears, rate_limit FROM roles";

/// A role by id or name.
pub async fn find_role(db: &MySqlPool, identifier: &str) -> Result<Role> {
    let role = if let Ok(id) = identifier.parse::<u32>() {
        sqlx::query_as(sqlx::AssertSqlSafe(format!("{ROLE} WHERE id = ?")))
            .bind(id)
            .fetch_optional(db)
            .await?
    } else {
        sqlx::query_as(sqlx::AssertSqlSafe(format!("{ROLE} WHERE name = ?")))
            .bind(identifier)
            .fetch_optional(db)
            .await?
    };
    role.with_context(|| format!("Role not found: {identifier}"))
}

async fn role_by_id(db: &MySqlPool, id: i64) -> Result<Option<Role>> {
    Ok(
        sqlx::query_as(sqlx::AssertSqlSafe(format!("{ROLE} WHERE id = ?")))
            .bind(id)
            .fetch_optional(db)
            .await?,
    )
}

/// Running promotions for a role (`RolePromotion::getActivePromotions`), as (id, days).
async fn active_promotions(
    db: &MySqlPool,
    role: u32,
    now: NaiveDateTime,
) -> Result<Vec<(u64, i64)>> {
    Ok(sqlx::query_as(
        "SELECT id, CAST(additional_days AS SIGNED) FROM role_promotions WHERE is_active = 1 \
         AND (start_date IS NULL OR start_date <= ?) AND (end_date IS NULL OR end_date >= ?) \
         AND (JSON_LENGTH(applicable_roles) = 0 OR JSON_CONTAINS(applicable_roles, ?)) \
         ORDER BY id",
    )
    .bind(now)
    .bind(now)
    .bind(role.to_string())
    .fetch_all(db)
    .await?)
}

/// `RolePromotion::calculateAdditionalDays`, zero for roles promotions skip.
async fn promotion_days(db: &MySqlPool, role: &Role, now: NaiveDateTime) -> Result<i64> {
    if PROMOTION_EXCLUDED.contains(&role.name.as_str()) {
        return Ok(0);
    }
    Ok(active_promotions(db, role.id, now)
        .await?
        .iter()
        .map(|(_, days)| days)
        .sum())
}

/// `resolveRoleDurationDays`.
fn base_days(role: &Role) -> i64 {
    i64::from(role.addyears.max(0)) * DAYS_PER_YEAR
}

/// What [`change`] did.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Outcome {
    /// The role is unchanged.
    Unchanged,
    /// The new role starts when the current one (or the queued one) ends.
    Stacked {
        starts: NaiveDateTime,
        expires: NaiveDateTime,
    },
    /// The new role applies now.
    Immediate { expires: Option<NaiveDateTime> },
}

/// The role part of the admin edit form.
#[derive(Clone, Debug, Default)]
pub struct Request {
    /// The new role; the role stays as it is without one.
    pub role: Option<String>,
    /// Queue the new role after the current one, when the current one is still running.
    pub stack: bool,
    /// Set the current role's expiry; `Some(None)` clears it.
    pub expires: Option<Option<NaiveDate>>,
    /// Drop a queued role first.
    pub cancel_pending: bool,
}

/// Applies the form's role fields in its order: cancel the pending role, set the expiry by
/// hand, then change the role. An expiry set by hand is kept and skips promotions, as the form
/// did.
pub async fn change(
    ctx: &Ctx,
    account: &Account,
    request: &Request,
    now: NaiveDateTime,
) -> Result<Outcome> {
    let db = &ctx.db;
    ensure!(
        account.deleted_at.is_none(),
        "{} is deleted; restore it first",
        account.username
    );
    ensure!(
        request.role.is_some() || request.expires.is_some() || request.cancel_pending,
        "nothing to change"
    );
    let role = match request.role.as_deref() {
        Some(role) => Some(find_role(db, role).await?),
        None => None,
    };
    let original_expiry = account.rolechangedate;
    let mut tx = db.begin().await?;
    // Read the row again under a lock, so a concurrent change can't interleave.
    let (roles_id, mut current_expiry, mut pending_role, mut pending_start): (
        i32,
        Option<NaiveDateTime>,
        Option<i32>,
        Option<NaiveDateTime>,
    ) = sqlx::query_as(
        "SELECT roles_id, rolechangedate, pending_roles_id, pending_role_start_date FROM users \
         WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
    )
    .bind(account.id)
    .fetch_optional(&mut *tx)
    .await?
    .with_context(|| format!("{} is deleted; restore it first", account.username))?;

    if request.cancel_pending && pending_role.is_some() {
        sqlx::query(
            "UPDATE users SET pending_roles_id = NULL, pending_role_start_date = NULL, \
             updated_at = ? WHERE id = ?",
        )
        .bind(now)
        .bind(account.id)
        .execute(&mut *tx)
        .await?;
        pending_role = None;
        pending_start = None;
    }
    let mut manual_expiry = false;
    if let Some(expires) = request.expires {
        current_expiry = expires.and_then(|date| date.and_hms_opt(0, 0, 0));
        manual_expiry = current_expiry.is_some();
        sqlx::query("UPDATE users SET rolechangedate = ?, updated_at = ? WHERE id = ?")
            .bind(current_expiry)
            .bind(now)
            .bind(account.id)
            .execute(&mut *tx)
            .await?;
    }
    let Some(role) = role.filter(|role| i64::from(role.id) != i64::from(roles_id)) else {
        tx.commit().await?;
        return Ok(Outcome::Unchanged);
    };

    let apply_promotions = !manual_expiry;
    let old_expiry = original_expiry.or(current_expiry);
    let base = base_days(&role);
    let promotion = if apply_promotions {
        promotion_days(db, &role, now).await?
    } else {
        0
    };
    let total = base + promotion;

    if let Some(current) = current_expiry.filter(|expiry| request.stack && *expiry > now) {
        // `handleStackedRoleChange`.
        let mut starts = current;
        if let (Some(pending), Some(pending_start)) = (pending_role, pending_start) {
            if let Some(pending) = role_by_id(db, i64::from(pending)).await? {
                let days = base_days(&pending) + promotion_days(db, &pending, now).await?;
                let pending_ends = pending_start + Duration::days(days);
                if pending_ends > now {
                    starts = pending_ends;
                }
            }
        } else if let Some(old) = old_expiry.filter(|old| *old > now && *old > current) {
            starts = old;
        }
        let expires = starts + Duration::days(total);
        sqlx::query(
            "UPDATE users SET pending_roles_id = ?, pending_role_start_date = ?, updated_at = ? \
             WHERE id = ?",
        )
        .bind(role.id)
        .bind(starts)
        .bind(now)
        .bind(account.id)
        .execute(&mut *tx)
        .await?;
        record_history(
            &mut tx,
            &HistoryEntry {
                user: u64::from(account.id),
                old_role: Some(i64::from(roles_id)),
                new_role: i64::from(role.id),
                old_expiry: Some(starts),
                new_expiry: Some(expires),
                effective: starts,
                stacked: true,
                reason: Some("stacked_role_change"),
                changed_by: None,
            },
        )
        .await?;
        tx.commit().await?;
        return Ok(Outcome::Stacked { starts, expires });
    }

    // `handleImmediateRoleChange`. The form then saved the account with the new role's rate
    // limit through `updateUser`.
    let expires = match current_expiry {
        Some(current) if manual_expiry => Some(current),
        _ if total > 0 => Some(now + Duration::days(total)),
        _ => None,
    };
    sqlx::query(
        "UPDATE users SET roles_id = ?, rolechangedate = ?, rate_limit = ?, updated_at = ? \
         WHERE id = ?",
    )
    .bind(role.id)
    .bind(expires)
    .bind(role.rate_limit)
    .bind(now)
    .bind(account.id)
    .execute(&mut *tx)
    .await?;
    sync_role(&mut tx, u64::from(account.id), i64::from(role.id)).await?;
    record_history(
        &mut tx,
        &HistoryEntry {
            user: u64::from(account.id),
            old_role: Some(i64::from(roles_id)),
            new_role: i64::from(role.id),
            old_expiry,
            new_expiry: expires,
            effective: now,
            stacked: false,
            reason: Some("immediate_role_change"),
            changed_by: None,
        },
    )
    .await?;
    let old_name = role_by_id(db, i64::from(roles_id))
        .await?
        .map_or_else(|| "Unknown".to_owned(), |role| role.name);
    log_role_updated(
        &mut tx,
        u64::from(account.id),
        &account.username,
        (i64::from(roles_id), &old_name),
        (i64::from(role.id), &role.name),
        ACTOR,
    )
    .await?;
    if promotion > 0 {
        // `trackPromotionApplication`.
        for (promotion, days) in active_promotions(db, role.id, now).await? {
            sqlx::query(
                "INSERT INTO role_promotion_stats (user_id, role_promotion_id, role_id, \
                 days_added, previous_expiry_date, new_expiry_date, applied_at, created_at, \
                 updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            )
            .bind(account.id)
            .bind(promotion)
            .bind(role.id)
            .bind(days)
            .bind(old_expiry)
            .bind(expires)
            .bind(now)
            .bind(now)
            .bind(now)
            .execute(&mut *tx)
            .await?;
        }
    }
    tx.commit().await?;
    forget_api_caches(ctx, u64::from(account.id), &account.api_token).await;
    if let Err(failure) = nntmux_mail::account::send_account_changed(
        ctx,
        &account.email,
        &account.username,
        &role.name,
    )
    .await
    {
        warn!(
            user = account.id,
            error = format!("{failure:#}"),
            "couldn't queue the account-changed email"
        );
    }
    Ok(Outcome::Immediate { expires })
}
