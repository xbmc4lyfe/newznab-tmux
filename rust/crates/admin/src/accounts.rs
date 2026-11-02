//! Scheduled account cleanup: `nntmux:delete-unverified-users` and the `RemoveInactiveAccounts`
//! and `PurgeDeletedAccounts` jobs, with the side effects `UserActivityObserver`,
//! `UserApiCacheObserver` and spatie's `HasRoles` gave each delete.

use anyhow::Result;
use chrono::{Duration, NaiveDateTime, Utc};
use md5::{Digest, Md5};
use nntmux_core::{optional, parsed_or};
use nntmux_runtime::Ctx;
use redis::AsyncCommands;
use serde_json::json;
use sqlx::{FromRow, MySqlPool};
use tracing::warn;

/// `UserRole::USER`, the only role inactivity removes.
const ROLE_USER: i64 = 1;
/// `User::UNVERIFIED_CLEANUP_ROLES`.
const UNVERIFIED_CLEANUP_ROLES: [&str; 2] = ["User", "FreeUser"];
/// Unverified accounts get this long to confirm their address.
const UNVERIFIED_GRACE_DAYS: i64 = 3;
/// `chunkById(100)`.
const CHUNK: i64 = 100;
/// Spatie stores the model class with each role and permission link.
pub const USER_MODEL: &str = r"App\Models\User";

/// `config('nntmux.purge_inactive_users') === true`: Laravel's `env()` turned only the words
/// true and (true) into a boolean, so `1` or `yes` left both jobs off.
pub fn purge_enabled() -> bool {
    matches!(
        optional("PURGE_INACTIVE_USERS")
            .map(|value| value.to_ascii_lowercase())
            .as_deref(),
        Some("true" | "(true)")
    )
}

/// `nntmux.purge_inactive_users_days`.
pub fn purge_days() -> Result<i64> {
    parsed_or("PURGE_INACTIVE_USERS_DAYS", 180)
}

/// `User::deleteUnVerified`: soft-deletes accounts in the User or FreeUser role that haven't
/// confirmed their address within three days. A bulk query delete, so like Laravel it fires no
/// observers: no activity rows and no cache flush, since these accounts never had API access.
pub async fn delete_unverified(db: &MySqlPool, now: NaiveDateTime) -> Result<u64> {
    let cutoff = now - Duration::days(UNVERIFIED_GRACE_DAYS);
    let deleted = sqlx::query(
        "UPDATE users u INNER JOIN roles r ON r.id = u.roles_id \
         SET u.deleted_at = ?, u.updated_at = ? \
         WHERE r.name IN (?, ?) AND u.verified = 0 AND u.email_verified_at IS NULL \
         AND u.created_at < ? AND u.deleted_at IS NULL",
    )
    .bind(now)
    .bind(now)
    .bind(UNVERIFIED_CLEANUP_ROLES[0])
    .bind(UNVERIFIED_CLEANUP_ROLES[1])
    .bind(cutoff)
    .execute(db)
    .await?
    .rows_affected();
    Ok(deleted)
}

/// What `UserInactivityEvaluator::shouldPurge` reads.
#[derive(Clone, Debug, FromRow)]
pub struct Activity {
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
    pub lastlogin: Option<NaiveDateTime>,
    pub apiaccess: Option<NaiveDateTime>,
    pub lastdownload: Option<NaiveDateTime>,
    pub grabs: i64,
}

/// `UserInactivityEvaluator::shouldPurge`: no login, API use or download since `threshold`.
/// A missing login or API time falls back to the signup time. Downloads fall back to the last
/// profile update for users who grabbed before `lastdownload` was tracked.
pub fn inactive(user: &Activity, threshold: NaiveDateTime) -> bool {
    let stale = |at: Option<NaiveDateTime>| at.is_some_and(|at| at < threshold);
    let downloads_stale = match (user.lastdownload, user.grabs > 0) {
        (Some(last), _) => last < threshold,
        (None, true) => stale(user.updated_at),
        (None, false) => stale(user.created_at),
    };
    stale(user.lastlogin.or(user.created_at))
        && stale(user.apiaccess.or(user.created_at))
        && downloads_stale
}

#[derive(FromRow)]
struct Candidate {
    id: u64,
    username: String,
    email: String,
    api_token: String,
    #[sqlx(flatten)]
    activity: Activity,
}

/// `RemoveInactiveAccounts`: soft-deletes accounts in the User role older than the purge window
/// with no activity inside it. Each delete records who did it and logs it, as the observers did.
pub async fn remove_inactive(ctx: &Ctx, now: NaiveDateTime, days: i64) -> Result<u64> {
    let threshold = now - Duration::days(days);
    let mut after = 0_u64;
    let mut removed = 0;
    loop {
        let chunk: Vec<Candidate> = sqlx::query_as(
            "SELECT id, username, email, api_token, CAST(created_at AS DATETIME) AS created_at, \
             CAST(updated_at AS DATETIME) AS updated_at, lastlogin, apiaccess, lastdownload, CAST(grabs AS SIGNED) AS grabs FROM users \
             WHERE roles_id = ? AND created_at < ? AND deleted_at IS NULL AND id > ? \
             ORDER BY id LIMIT ?",
        )
        .bind(ROLE_USER)
        .bind(threshold)
        .bind(after)
        .bind(CHUNK)
        .fetch_all(&ctx.db)
        .await?;
        let Some(last) = chunk.last() else {
            break;
        };
        after = last.id;
        for user in chunk
            .iter()
            .filter(|user| inactive(&user.activity, threshold))
        {
            if soft_delete(&ctx.db, user, now).await? {
                forget_api_caches(ctx, user.id, &user.api_token).await;
                removed += 1;
            }
        }
    }
    Ok(removed)
}

/// `$user->delete()` on a soft-deleting model, by the system.
async fn soft_delete(db: &MySqlPool, user: &Candidate, now: NaiveDateTime) -> Result<bool> {
    let mut tx = db.begin().await?;
    let changed = sqlx::query(
        "UPDATE users SET deleted_at = ?, updated_at = ?, deleted_by = 'System' \
         WHERE id = ? AND deleted_at IS NULL",
    )
    .bind(now)
    .bind(now)
    .bind(user.id)
    .execute(&mut *tx)
    .await?
    .rows_affected();
    if changed == 0 {
        return Ok(false);
    }
    log_activity(
        &mut tx,
        Some(user.id),
        &user.username,
        &format!("User deleted: {}", user.username),
        false,
        json!({ "email": user.email, "deleted_by": "System" }),
    )
    .await?;
    tx.commit().await?;
    Ok(true)
}

#[derive(FromRow)]
struct Trashed {
    id: u64,
    username: String,
    email: String,
    api_token: String,
}

/// `PurgeDeletedAccounts`: permanently removes accounts soft-deleted more than the purge window
/// ago. Rows that reference the user cascade in the schema; spatie's role and permission links
/// have no foreign key, so they go here, as `HasRoles` detached them on a force delete.
///
/// Laravel also fired its soft-delete `deleted` observer during a force delete, logging a second
/// "User deleted" row for an account already logged as deleted. Only the permanent row is kept.
pub async fn purge_deleted(ctx: &Ctx, now: NaiveDateTime, days: i64) -> Result<u64> {
    let cutoff = now - Duration::days(days);
    let trashed: Vec<Trashed> = sqlx::query_as(
        "SELECT id, username, email, api_token FROM users \
         WHERE deleted_at IS NOT NULL AND deleted_at < ? ORDER BY id",
    )
    .bind(cutoff)
    .fetch_all(&ctx.db)
    .await?;
    let mut purged = 0;
    for user in trashed {
        let mut tx = ctx.db.begin().await?;
        for table in ["model_has_roles", "model_has_permissions"] {
            sqlx::query(sqlx::AssertSqlSafe(format!(
                "DELETE FROM {table} WHERE model_id = ? AND model_type = ?"
            )))
            .bind(user.id)
            .bind(USER_MODEL)
            .execute(&mut *tx)
            .await?;
        }
        let deleted = sqlx::query("DELETE FROM users WHERE id = ? AND deleted_at IS NOT NULL")
            .bind(user.id)
            .execute(&mut *tx)
            .await?
            .rows_affected();
        if deleted == 0 {
            // Restored since the select.
            continue;
        }
        log_activity(
            &mut tx,
            None,
            &user.username,
            &format!("User permanently deleted: {}", user.username),
            true,
            json!({ "email": user.email, "deleted_by": "System", "permanent": true }),
        )
        .await?;
        tx.commit().await?;
        forget_api_caches(ctx, user.id, &user.api_token).await;
        purged += 1;
    }
    Ok(purged)
}

async fn log_activity(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    user_id: Option<u64>,
    username: &str,
    description: &str,
    permanent: bool,
    metadata: serde_json::Value,
) -> Result<()> {
    sqlx::query(
        "INSERT INTO user_activities \
         (user_id, username, activity_type, description, metadata, is_permanent, created_at) \
         VALUES (?, ?, 'deleted', ?, ?, ?, ?)",
    )
    .bind(user_id)
    .bind(username)
    .bind(description)
    .bind(metadata.to_string())
    .bind(permanent)
    .bind(Utc::now().naive_utc())
    .execute(&mut **tx)
    .await?;
    Ok(())
}

/// `UserApiCacheObserver`: the API caches a user's token and limits for minutes, so a deleted
/// account would keep working until they expire. Best effort, as a cache miss is harmless.
pub async fn forget_api_caches(ctx: &Ctx, user_id: u64, api_token: &str) {
    if api_token.is_empty() {
        return;
    }
    let prefix = nntmux_core::var_or("NNTMUX_API_REDIS_PREFIX", "");
    let keys = [
        format!("{prefix}api_user:{}", hex::encode(Md5::digest(api_token))),
        format!("{prefix}api_user_stats:{user_id}"),
        format!("{prefix}user_category_exclusions_{user_id}"),
    ];
    let mut redis = ctx.redis.clone();
    if let Err(failure) = redis.del::<_, ()>(&keys).await {
        warn!(user_id, error = %failure, "couldn't clear a deleted user's API caches");
    }
}
