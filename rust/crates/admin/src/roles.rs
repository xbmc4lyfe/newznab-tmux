//! Role changes and role stacking: `role:manage`, and the side effects Laravel's observers gave
//! a change of `users.roles_id`, for anything else that changes a role.

use anyhow::{Result, bail};
use chrono::{NaiveDateTime, Utc};
use nntmux_runtime::Ctx;
use serde_json::json;
use sqlx::{FromRow, MySql, MySqlPool, Transaction};
use tracing::warn;

use crate::accounts::{USER_MODEL, forget_api_caches};

/// A user named by id or username, as `role:manage` took either.
#[derive(Clone, Debug, FromRow)]
pub struct Account {
    pub id: u64,
    pub username: String,
    pub email: String,
    pub api_token: String,
    pub roles_id: i32,
    pub pending_roles_id: Option<i32>,
    pub pending_role_start_date: Option<NaiveDateTime>,
}

const ACCOUNT: &str = "SELECT id, username, email, api_token, roles_id, pending_roles_id, \
                       pending_role_start_date FROM users";

/// `findUser`: a number is an id, anything else a username. Soft-deleted users don't match.
pub async fn find(db: &MySqlPool, identifier: &str) -> Result<Option<Account>> {
    let account = if identifier.parse::<u64>().is_ok() {
        sqlx::query_as(sqlx::AssertSqlSafe(format!(
            "{ACCOUNT} WHERE id = ? AND deleted_at IS NULL"
        )))
        .bind(identifier)
        .fetch_optional(db)
        .await?
    } else {
        sqlx::query_as(sqlx::AssertSqlSafe(format!(
            "{ACCOUNT} WHERE username = ? AND deleted_at IS NULL ORDER BY id LIMIT 1"
        )))
        .bind(identifier)
        .fetch_optional(db)
        .await?
    };
    Ok(account)
}

pub async fn role_name(db: &MySqlPool, id: i64) -> Result<Option<String>> {
    Ok(sqlx::query_scalar("SELECT name FROM roles WHERE id = ?")
        .bind(id)
        .fetch_optional(db)
        .await?)
}

/// The first spatie role linked to the user: what `$user->roles->first()` read.
async fn linked_role(db: &MySqlPool, user: u64) -> Result<Option<(i64, String)>> {
    Ok(sqlx::query_as(
        "SELECT CAST(r.id AS SIGNED), r.name FROM model_has_roles m \
         INNER JOIN roles r ON r.id = m.role_id \
         WHERE m.model_id = ? AND m.model_type = ? ORDER BY r.id LIMIT 1",
    )
    .bind(user)
    .bind(USER_MODEL)
    .fetch_optional(db)
    .await?)
}

/// A user waiting for a stacked role.
#[derive(Clone, Debug, PartialEq)]
pub struct Pending {
    pub id: u64,
    pub username: String,
    pub current: String,
    pub pending: String,
    pub starts: NaiveDateTime,
}

/// `role:manage list-pending`.
pub async fn list_pending(db: &MySqlPool) -> Result<Vec<Pending>> {
    let rows: Vec<(u64, String, i32, NaiveDateTime)> = sqlx::query_as(
        "SELECT id, username, pending_roles_id, pending_role_start_date FROM users \
         WHERE pending_roles_id IS NOT NULL AND pending_role_start_date IS NOT NULL \
         AND deleted_at IS NULL ORDER BY id",
    )
    .fetch_all(db)
    .await?;
    let mut pending = Vec::with_capacity(rows.len());
    for (id, username, role, starts) in rows {
        pending.push(Pending {
            current: linked_role(db, id)
                .await?
                .map_or_else(|| "None".to_owned(), |(_, name)| name),
            pending: role_name(db, role.into())
                .await?
                .unwrap_or_else(|| "Unknown".to_owned()),
            id,
            username,
            starts,
        });
    }
    Ok(pending)
}

/// `User::cancelPendingRole`: false when there was nothing pending.
pub async fn cancel_pending(db: &MySqlPool, user: u64) -> Result<bool> {
    let changed = sqlx::query(
        "UPDATE users SET pending_roles_id = NULL, pending_role_start_date = NULL, updated_at = ? \
         WHERE id = ? AND pending_roles_id IS NOT NULL AND pending_role_start_date IS NOT NULL",
    )
    .bind(Utc::now().naive_utc())
    .bind(user)
    .execute(db)
    .await?
    .rows_affected();
    Ok(changed > 0)
}

/// `role:manage cancel-pending --all`: users with a pending role id, even without a start date,
/// whose cancel then finds nothing to do, as in Laravel.
pub async fn cancel_all_pending(db: &MySqlPool) -> Result<Vec<String>> {
    let users: Vec<(u64, String)> = sqlx::query_as(
        "SELECT id, username FROM users WHERE pending_roles_id IS NOT NULL \
         AND deleted_at IS NULL ORDER BY id",
    )
    .fetch_all(db)
    .await?;
    let mut cancelled = Vec::new();
    for (id, username) in users {
        if cancel_pending(db, id).await? {
            cancelled.push(username);
        }
    }
    Ok(cancelled)
}

/// One row of `user_role_history`, with names resolved as `role:manage history` showed them.
#[derive(Clone, Debug, PartialEq)]
pub struct HistoryRow {
    pub effective: NaiveDateTime,
    pub from: String,
    pub to: String,
    pub stacked: bool,
    pub reason: Option<String>,
    pub changed_by: String,
}

#[derive(FromRow)]
struct HistoryDbRow {
    effective_date: NaiveDateTime,
    from_name: Option<String>,
    to_name: Option<String>,
    is_stacked: bool,
    change_reason: Option<String>,
    changed_by: Option<String>,
}

/// `UserRoleHistory::getUserHistory`, newest first.
pub async fn history(db: &MySqlPool, user: u64) -> Result<Vec<HistoryRow>> {
    let rows: Vec<HistoryDbRow> = sqlx::query_as(
        "SELECT h.effective_date, o.name AS from_name, n.name AS to_name, h.is_stacked, \
             h.change_reason, u.username AS changed_by \
             FROM user_role_history h \
             LEFT JOIN roles o ON o.id = h.old_role_id \
             LEFT JOIN roles n ON n.id = h.new_role_id \
             LEFT JOIN users u ON u.id = h.changed_by \
             WHERE h.user_id = ? ORDER BY h.effective_date DESC, h.id DESC",
    )
    .bind(user)
    .fetch_all(db)
    .await?;
    Ok(rows
        .into_iter()
        .map(|row| HistoryRow {
            effective: row.effective_date,
            from: row.from_name.unwrap_or_else(|| "None".to_owned()),
            to: row.to_name.unwrap_or_else(|| "Unknown".to_owned()),
            stacked: row.is_stacked,
            reason: row.change_reason,
            changed_by: row.changed_by.unwrap_or_else(|| "System".to_owned()),
        })
        .collect())
}

/// `UserRoleHistory::recordRoleChange`.
pub struct HistoryEntry<'a> {
    pub user: u64,
    pub old_role: Option<i64>,
    pub new_role: i64,
    pub old_expiry: Option<NaiveDateTime>,
    pub new_expiry: Option<NaiveDateTime>,
    pub effective: NaiveDateTime,
    pub stacked: bool,
    pub reason: Option<&'a str>,
    pub changed_by: Option<u64>,
}

pub async fn record_history(
    tx: &mut Transaction<'_, MySql>,
    entry: &HistoryEntry<'_>,
) -> Result<()> {
    let now = Utc::now().naive_utc();
    sqlx::query(
        "INSERT INTO user_role_history (user_id, old_role_id, new_role_id, old_expiry_date, \
         new_expiry_date, effective_date, is_stacked, change_reason, changed_by, created_at, \
         updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(entry.user)
    .bind(entry.old_role)
    .bind(entry.new_role)
    .bind(entry.old_expiry)
    .bind(entry.new_expiry)
    .bind(entry.effective)
    .bind(entry.stacked)
    .bind(entry.reason)
    .bind(entry.changed_by)
    .bind(now)
    .bind(now)
    .execute(&mut **tx)
    .await?;
    Ok(())
}

/// `$user->syncRoles([$name])`: the user's spatie links become exactly this role.
pub async fn sync_role(tx: &mut Transaction<'_, MySql>, user: u64, role: i64) -> Result<()> {
    sqlx::query("DELETE FROM model_has_roles WHERE model_id = ? AND model_type = ?")
        .bind(user)
        .bind(USER_MODEL)
        .execute(&mut **tx)
        .await?;
    sqlx::query("INSERT INTO model_has_roles (role_id, model_type, model_id) VALUES (?, ?, ?)")
        .bind(role)
        .bind(USER_MODEL)
        .bind(user)
        .execute(&mut **tx)
        .await?;
    Ok(())
}

/// The `role_updated` activity row `UserActivityObserver::updated` wrote when `roles_id` changed.
pub async fn log_role_updated(
    tx: &mut Transaction<'_, MySql>,
    user: u64,
    username: &str,
    (old_id, old_name): (i64, &str),
    (new_id, new_name): (i64, &str),
    updated_by: &str,
) -> Result<()> {
    let metadata = json!({
        "old_role_id": old_id,
        "new_role_id": new_id,
        "old_role_name": old_name,
        "new_role_name": new_name,
        "updated_by": updated_by,
    });
    sqlx::query(
        "INSERT INTO user_activities (user_id, username, activity_type, description, metadata, \
         is_permanent, created_at) VALUES (?, ?, 'role_updated', ?, ?, 0, ?)",
    )
    .bind(user)
    .bind(username)
    .bind(format!("User role updated: {username} → {new_name}"))
    .bind(metadata.to_string())
    .bind(Utc::now().naive_utc())
    .execute(&mut **tx)
    .await?;
    Ok(())
}

/// What happened to one user in [`activate_pending`].
#[derive(Clone, Debug, PartialEq)]
pub struct Activation {
    pub username: String,
    pub from: String,
    pub to: String,
}

/// `role:manage activate-pending`: moves every user whose pending role has started onto it,
/// with the history row, activity row, cache flush and account-changed email the PHP gave
/// each. One user's failure is reported and the rest carry on.
pub async fn activate_pending(
    ctx: &Ctx,
    now: NaiveDateTime,
) -> Result<(Vec<Activation>, Vec<(String, String)>)> {
    let due: Vec<u64> = sqlx::query_scalar(
        "SELECT id FROM users WHERE pending_roles_id IS NOT NULL \
         AND pending_role_start_date IS NOT NULL AND pending_role_start_date <= ? \
         AND deleted_at IS NULL ORDER BY id",
    )
    .bind(now)
    .fetch_all(&ctx.db)
    .await?;
    let mut activated = Vec::new();
    let mut failed = Vec::new();
    for id in due {
        match activate_one(ctx, id, now).await {
            Ok(Some(activation)) => activated.push(activation),
            Ok(None) => {}
            Err(failure) => {
                let name = sqlx::query_scalar("SELECT username FROM users WHERE id = ?")
                    .bind(id)
                    .fetch_optional(&ctx.db)
                    .await
                    .ok()
                    .flatten()
                    .unwrap_or_else(|| id.to_string());
                failed.push((name, format!("{failure:#}")));
            }
        }
    }
    Ok((activated, failed))
}

async fn activate_one(ctx: &Ctx, id: u64, now: NaiveDateTime) -> Result<Option<Activation>> {
    let mut tx = ctx.db.begin().await?;
    let account: Option<Account> = sqlx::query_as(sqlx::AssertSqlSafe(format!(
        "{ACCOUNT} WHERE id = ? AND pending_roles_id IS NOT NULL \
         AND pending_role_start_date IS NOT NULL AND pending_role_start_date <= ? FOR UPDATE"
    )))
    .bind(id)
    .bind(now)
    .fetch_optional(&mut *tx)
    .await?;
    // Activated or cancelled since the select.
    let Some(account) = account else {
        return Ok(None);
    };
    let Some(target) = account.pending_roles_id else {
        return Ok(None);
    };
    let target = i64::from(target);
    let linked = linked_role(&ctx.db, id).await?;
    // A pending role deleted since it was queued stays pending, as the scheduled activation
    // left it, rather than pointing the user at a role that doesn't exist.
    let Some(pending_name) = role_name(&ctx.db, target).await? else {
        bail!("pending role {target} no longer exists");
    };
    let old_expiry: Option<NaiveDateTime> =
        sqlx::query_scalar("SELECT rolechangedate FROM users WHERE id = ?")
            .bind(id)
            .fetch_one(&mut *tx)
            .await?;
    // `User::activatePendingRole`'s expiry. Laravel's manual command kept the old date, so the
    // next expiry run took away the role it had just granted.
    let new_expiry = new_expiry(&ctx.db, target, now).await?;
    sqlx::query(
        "UPDATE users SET roles_id = ?, rolechangedate = ?, pending_roles_id = NULL, \
         pending_role_start_date = NULL, updated_at = ? WHERE id = ?",
    )
    .bind(target)
    .bind(new_expiry)
    .bind(now)
    .bind(id)
    .execute(&mut *tx)
    .await?;
    sync_role(&mut tx, id, target).await?;
    let previous = i64::from(account.roles_id);
    let role_changed = previous != target;
    if role_changed {
        let old_name = role_name(&ctx.db, previous)
            .await?
            .unwrap_or_else(|| format!("Role #{previous}"));
        log_role_updated(
            &mut tx,
            id,
            &account.username,
            (previous, &old_name),
            (target, &pending_name),
            "System",
        )
        .await?;
    }
    record_history(
        &mut tx,
        &HistoryEntry {
            user: id,
            old_role: linked.as_ref().map(|(role, _)| *role),
            new_role: target,
            old_expiry,
            new_expiry,
            effective: now,
            stacked: true,
            reason: Some("manual_activation"),
            changed_by: None,
        },
    )
    .await?;
    tx.commit().await?;

    if role_changed {
        forget_api_caches(ctx, id, &account.api_token).await;
        if let Err(failure) = nntmux_mail::account::send_account_changed(
            ctx,
            &account.email,
            &account.username,
            &pending_name,
        )
        .await
        {
            warn!(
                user = id,
                error = format!("{failure:#}"),
                "couldn't queue the account-changed email"
            );
        }
    }
    Ok(Some(Activation {
        username: account.username,
        from: linked.map_or_else(|| "None".to_owned(), |(_, name)| name),
        to: pending_name,
    }))
}

/// A role's length, `addyears` years of 365 days plus every running promotion for it
/// (`RolePromotion::calculateAdditionalDays`), from `now`. None when the role doesn't expire.
pub async fn new_expiry(
    db: &MySqlPool,
    role: i64,
    now: NaiveDateTime,
) -> Result<Option<NaiveDateTime>> {
    let years: i64 = sqlx::query_scalar("SELECT CAST(addyears AS SIGNED) FROM roles WHERE id = ?")
        .bind(role)
        .fetch_one(db)
        .await?;
    let promotion: i64 = sqlx::query_scalar(
        "SELECT CAST(COALESCE(SUM(additional_days), 0) AS SIGNED) FROM role_promotions \
         WHERE is_active = 1 AND (start_date IS NULL OR start_date <= ?) \
         AND (end_date IS NULL OR end_date >= ?) \
         AND (JSON_LENGTH(applicable_roles) = 0 OR JSON_CONTAINS(applicable_roles, ?))",
    )
    .bind(now)
    .bind(now)
    .bind(role.to_string())
    .fetch_one(db)
    .await?;
    let days = years * 365 + promotion;
    Ok((days > 0).then(|| now + chrono::Duration::days(days)))
}

/// Fails with `role:manage`'s message when a user is missing.
pub async fn require(db: &MySqlPool, identifier: &str) -> Result<Account> {
    match find(db, identifier).await? {
        Some(account) => Ok(account),
        None => bail!("User not found: {identifier}"),
    }
}
