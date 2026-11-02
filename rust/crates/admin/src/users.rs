//! The admin user pages (`AdminUserController`, `DeletedUsersController`): listing, adding,
//! editing, deleting and restoring accounts, email verification and passkeys, with the side
//! effects `UserActivityObserver` and `UserApiCacheObserver` gave those changes.
//!
//! Role changes, with their stacking and promotions, are `nntmux-ctl admin role`'s.

use anyhow::{Context, Result, bail, ensure};
use chrono::{NaiveDate, NaiveDateTime};
use nntmux_runtime::Ctx;
use serde_json::json;
use sqlx::{FromRow, MySql, MySqlPool, QueryBuilder, Transaction};
use tracing::{info, warn};

use crate::accounts::forget_api_caches;

/// Who changed an account from the command line, as Laravel's observers recorded a console
/// change with no signed-in user.
pub const ACTOR: &str = "System";

/// The address `scopeExcludeSharing` hid from the user list.
const SHARING_EMAIL: &str = "sharing@nZEDb.com";

/// `getUserBrowseOrdering`.
pub const ORDERINGS: &[&str] = &[
    "username_asc",
    "username_desc",
    "email_asc",
    "email_desc",
    "host_asc",
    "host_desc",
    "createdat_asc",
    "createdat_desc",
    "lastlogin_asc",
    "lastlogin_desc",
    "apiaccess_asc",
    "apiaccess_desc",
    "apirequests_asc",
    "apirequests_desc",
    "grabs_asc",
    "grabs_desc",
    "role_asc",
    "role_desc",
    "rolechangedate_asc",
    "rolechangedate_desc",
    "verification_asc",
    "verification_desc",
];

/// `getBrowseOrder`: the column and direction for an ordering, `username_desc` by default.
fn order(ordering: &str) -> (&'static str, &'static str) {
    let ordering = if ordering.is_empty() {
        "username_desc"
    } else {
        ordering
    };
    let (field, direction) = ordering.split_once('_').unwrap_or((ordering, "desc"));
    let column = match field {
        "email" => "users.email",
        "host" => "users.host",
        "createdat" => "users.created_at",
        "lastlogin" => "users.lastlogin",
        "apiaccess" => "users.apiaccess",
        "apirequests" => "daily_api_count",
        "grabs" => "users.grabs",
        "role" => "role",
        "rolechangedate" => "users.rolechangedate",
        "verification" => "users.verified",
        _ => "users.username",
    };
    let direction = if direction.eq_ignore_ascii_case("asc") {
        "ASC"
    } else {
        "DESC"
    };
    (column, direction)
}

/// The user list filters (`AdminUserListRequest::filters`).
#[derive(Clone, Debug, Default)]
pub struct Filter {
    pub username: Option<String>,
    pub email: Option<String>,
    pub host: Option<String>,
    pub role: Option<i64>,
    pub verified: Option<bool>,
    pub created_from: Option<NaiveDate>,
    pub created_to: Option<NaiveDate>,
    /// Only soft-deleted accounts, as the deleted users page listed. Otherwise every account,
    /// deleted or not, as the user list did.
    pub deleted_only: bool,
    pub ordering: String,
    pub limit: u32,
    pub offset: u32,
}

#[derive(Debug, FromRow)]
pub struct Listed {
    pub id: u32,
    pub username: String,
    pub email: String,
    pub host: Option<String>,
    pub role: Option<String>,
    pub grabs: i32,
    /// 1 when the address is verified.
    pub verified: i64,
    pub created_at: Option<NaiveDateTime>,
    pub lastlogin: Option<NaiveDateTime>,
    pub apiaccess: Option<NaiveDateTime>,
    pub rolechangedate: Option<NaiveDateTime>,
    pub deleted_at: Option<NaiveDateTime>,
    pub deleted_by: Option<String>,
    pub daily_api_count: i64,
    pub daily_download_count: i64,
}

/// `adminListPaginator`, one page of it.
pub async fn list(db: &MySqlPool, filter: &Filter, now: NaiveDateTime) -> Result<Vec<Listed>> {
    if let (Some(from), Some(to)) = (filter.created_from, filter.created_to) {
        ensure!(
            to >= from,
            "--created-to must be on or after --created-from"
        );
    }
    let since = now - chrono::Duration::days(1);
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT users.id, users.username, users.email, users.host, \
         (SELECT name FROM roles WHERE roles.id = users.roles_id) AS role, users.grabs, \
         CAST(users.verified = 1 OR users.email_verified_at IS NOT NULL AS SIGNED) AS verified, \
         CAST(users.created_at AS DATETIME) AS created_at, users.lastlogin, users.apiaccess, \
         users.rolechangedate, CAST(users.deleted_at AS DATETIME) AS deleted_at, users.deleted_by, \
         (SELECT COUNT(*) FROM user_requests r WHERE r.users_id = users.id AND r.timestamp > ",
    );
    query.push_bind(since);
    query.push(
        ") AS daily_api_count, \
         (SELECT COUNT(*) FROM user_downloads d WHERE d.users_id = users.id AND d.timestamp > ",
    );
    query.push_bind(since);
    query.push(") AS daily_download_count FROM users WHERE users.email <> ");
    query.push_bind(SHARING_EMAIL);
    if filter.deleted_only {
        query.push(" AND users.deleted_at IS NOT NULL");
    }
    for (column, value) in [
        ("username", &filter.username),
        ("email", &filter.email),
        ("host", &filter.host),
    ] {
        if let Some(value) = value.as_deref().map(str::trim).filter(|v| !v.is_empty()) {
            query.push(format!(" AND users.{column} LIKE "));
            query.push_bind(format!("%{}%", escape_like(value)));
        }
    }
    if let Some(role) = filter.role {
        query.push(" AND users.roles_id = ");
        query.push_bind(role);
    }
    if let Some(from) = filter.created_from {
        query.push(" AND users.created_at >= ");
        query.push_bind(from.and_hms_opt(0, 0, 0));
    }
    if let Some(to) = filter.created_to {
        query.push(" AND users.created_at <= ");
        query.push_bind(to.and_hms_opt(23, 59, 59));
    }
    match filter.verified {
        Some(true) => {
            query.push(" AND (users.verified = 1 OR users.email_verified_at IS NOT NULL)");
        }
        Some(false) => {
            query.push(" AND users.verified = 0 AND users.email_verified_at IS NULL");
        }
        None => {}
    }
    let (column, direction) = if filter.deleted_only && filter.ordering.is_empty() {
        ("users.deleted_at", "DESC")
    } else {
        order(&filter.ordering)
    };
    query.push(format!(" ORDER BY {column} {direction}, users.id LIMIT "));
    query.push_bind(filter.limit);
    query.push(" OFFSET ");
    query.push_bind(filter.offset);
    Ok(query.build_query_as().fetch_all(db).await?)
}

/// `LIKE` treats `%` and `_` as wildcards, so a literal search escapes them.
fn escape_like(text: &str) -> String {
    text.replace('\\', "\\\\")
        .replace('%', "\\%")
        .replace('_', "\\_")
}

/// One account, deleted or not, for `show` and for the changes below.
#[derive(Clone, Debug, FromRow)]
pub struct Account {
    pub id: u32,
    pub username: String,
    pub email: String,
    pub api_token: String,
    pub host: Option<String>,
    pub roles_id: i32,
    pub role: Option<String>,
    pub notes: Option<String>,
    pub invites: i32,
    pub grabs: i32,
    pub rate_limit: i32,
    pub movieview: i32,
    pub musicview: i32,
    pub gameview: i32,
    pub consoleview: i32,
    pub bookview: i32,
    pub verified: bool,
    pub email_verified_at: Option<NaiveDateTime>,
    pub rolechangedate: Option<NaiveDateTime>,
    pub pending_roles_id: Option<i32>,
    pub pending_role_start_date: Option<NaiveDateTime>,
    pub created_at: Option<NaiveDateTime>,
    pub lastlogin: Option<NaiveDateTime>,
    pub apiaccess: Option<NaiveDateTime>,
    pub deleted_at: Option<NaiveDateTime>,
    pub deleted_by: Option<String>,
}

impl Account {
    /// `hasVerifiedEmail`, as the bulk actions read it.
    pub fn is_verified(&self) -> bool {
        self.verified || self.email_verified_at.is_some()
    }
}

const ACCOUNT: &str = "SELECT users.id, users.username, users.email, users.api_token, users.host, \
     users.roles_id, roles.name AS role, users.notes, users.invites, users.grabs, \
     users.rate_limit, users.movieview, users.musicview, users.gameview, users.consoleview, \
     users.bookview, users.verified, \
     CAST(users.email_verified_at AS DATETIME) AS email_verified_at, users.rolechangedate, \
     users.pending_roles_id, users.pending_role_start_date, \
     CAST(users.created_at AS DATETIME) AS created_at, users.lastlogin, users.apiaccess, \
     CAST(users.deleted_at AS DATETIME) AS deleted_at, users.deleted_by \
     FROM users LEFT JOIN roles ON roles.id = users.roles_id";

/// A user by id or exact username, deleted or not. A username shared by a deleted account
/// and a live one finds the live one. `id:N` and `name:X` say which is meant; a bare number
/// that is both one account's id and another's username is refused as ambiguous.
pub async fn find(db: &MySqlPool, identifier: &str) -> Result<Option<Account>> {
    let by_id = |id: u32| async move {
        sqlx::query_as::<_, Account>(sqlx::AssertSqlSafe(format!("{ACCOUNT} WHERE users.id = ?")))
            .bind(id)
            .fetch_optional(db)
            .await
    };
    let by_name = |name: String| async move {
        sqlx::query_as::<_, Account>(sqlx::AssertSqlSafe(format!(
            "{ACCOUNT} WHERE users.username = ? ORDER BY users.deleted_at IS NOT NULL, users.id \
             LIMIT 1"
        )))
        .bind(name)
        .fetch_optional(db)
        .await
    };
    if let Some(id) = identifier.strip_prefix("id:") {
        return Ok(by_id(id.parse().context("id: takes a number")?).await?);
    }
    if let Some(name) = identifier.strip_prefix("name:") {
        return Ok(by_name(name.to_owned()).await?);
    }
    let Ok(id) = identifier.parse::<u32>() else {
        return Ok(by_name(identifier.to_owned()).await?);
    };
    let (by_id, by_name) = (by_id(id).await?, by_name(identifier.to_owned()).await?);
    match (by_id, by_name) {
        (Some(account), Some(named)) if named.id != account.id => bail!(
            "{identifier} is user {}'s id and user {}'s name; say id:{identifier} or name:{identifier}",
            account.username,
            named.id
        ),
        (Some(account), _) => Ok(Some(account)),
        (None, named) => Ok(named),
    }
}

pub async fn require(db: &MySqlPool, identifier: &str) -> Result<Account> {
    find(db, identifier)
        .await?
        .with_context(|| format!("User not found: {identifier}"))
}

/// `getApiRequests` and `getDownloadRequests`: the last day's API calls and downloads.
pub async fn daily_counts(db: &MySqlPool, id: u32, now: NaiveDateTime) -> Result<(i64, i64)> {
    let since = now - chrono::Duration::days(1);
    let api = sqlx::query_scalar(
        "SELECT COUNT(*) FROM user_requests WHERE users_id = ? AND timestamp > ?",
    )
    .bind(id)
    .bind(since)
    .fetch_one(db)
    .await?;
    let downloads = sqlx::query_scalar(
        "SELECT COUNT(*) FROM user_downloads WHERE users_id = ? AND timestamp > ?",
    )
    .bind(id)
    .bind(since)
    .fetch_one(db)
    .await?;
    Ok((api, downloads))
}

/// Checks a username and address for a new or renamed account. The admin form skipped the
/// sign-up rules, so this only refuses what would break sign-in: an empty or overlong name,
/// a malformed address, or one another live account already uses.
async fn check_identity(
    db: &MySqlPool,
    username: &str,
    email: &str,
    except: Option<u32>,
) -> Result<()> {
    ensure!(!username.is_empty(), "the username can't be empty");
    ensure!(
        username.chars().count() <= 50,
        "the username can be at most 50 characters"
    );
    ensure!(
        nntmux_site::validate::valid_email(email) && email.chars().count() <= 255,
        "{email} isn't a valid email address"
    );
    let except = except.unwrap_or(0);
    let taken: Option<String> = sqlx::query_scalar(
        "SELECT CASE WHEN username = ? THEN 'username' ELSE 'email' END FROM users \
         WHERE (username = ? OR email = ?) AND id <> ? AND deleted_at IS NULL LIMIT 1",
    )
    .bind(username)
    .bind(username)
    .bind(email)
    .bind(except)
    .fetch_optional(db)
    .await?;
    match taken.as_deref() {
        Some("username") => bail!("Username in use."),
        Some(_) => bail!("Email in use."),
        None => Ok(()),
    }
}

/// An account the admin adds (`User::signUp` with validation and invites off).
#[derive(Clone, Debug)]
pub struct NewUser {
    pub username: String,
    pub email: String,
    pub password: String,
    /// The default role when absent.
    pub role: Option<i32>,
    pub notes: String,
    /// The role's default invites when absent.
    pub invites: Option<i32>,
    /// Mark the address verified at once.
    pub verified: bool,
}

/// Creates the account, as the admin add form did, and logs its registration.
pub async fn add(db: &MySqlPool, user: &NewUser) -> Result<u32> {
    let username = user.username.trim();
    let email = user.email.trim();
    let password = user.password.trim();
    check_identity(db, username, email, None).await?;
    ensure!(
        password.chars().count() >= 8,
        "Bad password. Try a longer one."
    );
    let role: Option<(u32, i64)> = match user.role {
        Some(role) => {
            sqlx::query_as("SELECT id, CAST(defaultinvites AS SIGNED) FROM roles WHERE id = ?")
                .bind(role)
                .fetch_optional(db)
                .await?
        }
        None => {
            sqlx::query_as(
                "SELECT id, CAST(defaultinvites AS SIGNED) FROM roles \
                 ORDER BY isdefault DESC, id LIMIT 1",
            )
            .fetch_optional(db)
            .await?
        }
    };
    let (role, default_invites) = role.context("that role doesn't exist")?;
    let hash = nntmux_webkit::auth::hash_password(password)?;
    let mut tx = db.begin().await?;
    let id = nntmux_webkit::user::create_user(
        &mut tx,
        username,
        email,
        &hash,
        "",
        i32::try_from(role)?,
        user.invites
            .unwrap_or_else(|| i32::try_from(default_invites).unwrap_or(i32::MAX)),
    )
    .await?;
    let id = u32::try_from(id)?;
    sqlx::query("UPDATE users SET notes = ? WHERE id = ?")
        .bind(truncate(&user.notes, 255))
        .bind(id)
        .execute(&mut *tx)
        .await?;
    if user.verified {
        mark_verified(&mut tx, id).await?;
    }
    log_activity(
        &mut tx,
        id,
        username,
        "registered",
        &format!("New user registered: {username}"),
        json!({ "email": email, "role": role }),
    )
    .await?;
    tx.commit().await?;
    Ok(id)
}

fn truncate(text: &str, max: usize) -> String {
    text.chars().take(max).collect()
}

/// The admin edit form's account fields (`User::updateUser` and `updatePassword`).
#[derive(Clone, Debug, Default)]
pub struct Changes {
    pub username: Option<String>,
    pub email: Option<String>,
    pub password: Option<String>,
    pub notes: Option<String>,
    pub invites: Option<i32>,
    pub movieview: Option<bool>,
    pub musicview: Option<bool>,
    pub gameview: Option<bool>,
    pub consoleview: Option<bool>,
    pub bookview: Option<bool>,
}

impl Changes {
    pub fn is_empty(&self) -> bool {
        self.username.is_none()
            && self.email.is_none()
            && self.password.is_none()
            && self.notes.is_none()
            && self.invites.is_none()
            && self.movieview.is_none()
            && self.musicview.is_none()
            && self.gameview.is_none()
            && self.consoleview.is_none()
            && self.bookview.is_none()
    }
}

pub async fn update(db: &MySqlPool, account: &Account, changes: &Changes) -> Result<()> {
    ensure!(!changes.is_empty(), "nothing to change");
    live(account)?;
    let username = changes
        .username
        .as_deref()
        .map_or(account.username.as_str(), str::trim);
    let email = changes
        .email
        .as_deref()
        .map_or(account.email.as_str(), str::trim);
    if changes.username.is_some() || changes.email.is_some() {
        check_identity(db, username, email, Some(account.id)).await?;
    }
    let password = match changes.password.as_deref().map(str::trim) {
        Some(password) => {
            ensure!(
                password.chars().count() >= 8,
                "Bad password. Try a longer one."
            );
            Some(nntmux_webkit::auth::hash_password(password)?)
        }
        None => None,
    };
    let flag = |change: Option<bool>, current: i32| change.map_or(current, i32::from);
    sqlx::query(
        "UPDATE users SET username = ?, email = ?, notes = ?, invites = ?, movieview = ?, \
         musicview = ?, gameview = ?, consoleview = ?, bookview = ?, \
         password = COALESCE(?, password), updated_at = NOW() WHERE id = ?",
    )
    .bind(username)
    .bind(email)
    .bind(
        changes
            .notes
            .as_deref()
            .map_or_else(|| account.notes.clone(), |notes| Some(truncate(notes, 255))),
    )
    .bind(changes.invites.unwrap_or(account.invites))
    .bind(flag(changes.movieview, account.movieview))
    .bind(flag(changes.musicview, account.musicview))
    .bind(flag(changes.gameview, account.gameview))
    .bind(flag(changes.consoleview, account.consoleview))
    .bind(flag(changes.bookview, account.bookview))
    .bind(password)
    .bind(account.id)
    .execute(db)
    .await?;
    Ok(())
}

/// The admin pages found only live accounts, so a deleted one is restored before it changes.
fn live(account: &Account) -> Result<()> {
    ensure!(
        account.deleted_at.is_none(),
        "{} is deleted; restore it first",
        account.username
    );
    Ok(())
}

/// Ids of the `Admin` role, which bulk actions leave alone.
async fn is_admin(db: &MySqlPool, account: &Account) -> Result<bool> {
    let admin: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM roles r WHERE r.name = 'Admin' AND (r.id = ? OR EXISTS ( \
         SELECT 1 FROM model_has_roles m WHERE m.role_id = r.id AND m.model_id = ? \
         AND m.model_type = ?))",
    )
    .bind(account.roles_id)
    .bind(account.id)
    .bind(crate::accounts::USER_MODEL)
    .fetch_one(db)
    .await?;
    Ok(admin > 0)
}

/// Fails for an Admin account unless `allow_admin`, as the bulk actions skipped them.
pub async fn guard_admin(db: &MySqlPool, account: &Account, allow_admin: bool) -> Result<()> {
    if !allow_admin && is_admin(db, account).await? {
        bail!(
            "{} is an Admin; pass --allow-admin to change it anyway",
            account.username
        );
    }
    Ok(())
}

/// Soft-deletes the account (`$user->delete()`), recording who did it and logging it.
pub async fn delete(ctx: &Ctx, account: &Account, now: NaiveDateTime) -> Result<bool> {
    let mut tx = ctx.db.begin().await?;
    let changed = sqlx::query(
        "UPDATE users SET deleted_at = ?, updated_at = ?, deleted_by = ? \
         WHERE id = ? AND deleted_at IS NULL",
    )
    .bind(now)
    .bind(now)
    .bind(ACTOR)
    .bind(account.id)
    .execute(&mut *tx)
    .await?
    .rows_affected();
    if changed == 0 {
        return Ok(false);
    }
    log_activity(
        &mut tx,
        account.id,
        &account.username,
        "deleted",
        &format!("User deleted: {}", account.username),
        json!({ "email": account.email, "deleted_by": ACTOR }),
    )
    .await?;
    tx.commit().await?;
    forget_api_caches(ctx, u64::from(account.id), &account.api_token).await;
    Ok(true)
}

/// Brings back a soft-deleted account (`restore()`), clearing who deleted it and logging it.
pub async fn restore(ctx: &Ctx, account: &Account, now: NaiveDateTime) -> Result<bool> {
    if account.deleted_at.is_some() {
        // Another live account may have taken the name or address since.
        check_identity(&ctx.db, &account.username, &account.email, Some(account.id)).await?;
    }
    let mut tx = ctx.db.begin().await?;
    let changed = sqlx::query(
        "UPDATE users SET deleted_at = NULL, deleted_by = NULL, updated_at = ? \
         WHERE id = ? AND deleted_at IS NOT NULL",
    )
    .bind(now)
    .bind(account.id)
    .execute(&mut *tx)
    .await?
    .rows_affected();
    if changed == 0 {
        return Ok(false);
    }
    log_activity(
        &mut tx,
        account.id,
        &account.username,
        "registered",
        &format!("User restored: {}", account.username),
        json!({ "restored_by": ACTOR }),
    )
    .await?;
    tx.commit().await?;
    forget_api_caches(ctx, u64::from(account.id), &account.api_token).await;
    Ok(true)
}

/// `markEmailAsVerified`. Returns false when the address was already verified.
pub async fn verify(ctx: &Ctx, account: &Account) -> Result<bool> {
    live(account)?;
    if account.is_verified() {
        return Ok(false);
    }
    let mut tx = ctx.db.begin().await?;
    mark_verified(&mut tx, account.id).await?;
    tx.commit().await?;
    forget_api_caches(ctx, u64::from(account.id), &account.api_token).await;
    Ok(true)
}

async fn mark_verified(tx: &mut Transaction<'_, MySql>, id: u32) -> Result<()> {
    sqlx::query(
        "UPDATE users SET email_verified_at = NOW(), verified = 1, verification_token = NULL, \
         updated_at = NOW() WHERE id = ?",
    )
    .bind(id)
    .execute(&mut **tx)
    .await?;
    Ok(())
}

/// `sendEmailVerificationNotification`, for an account that isn't verified yet.
pub async fn resend_verification(ctx: &Ctx, account: &Account) -> Result<()> {
    live(account)?;
    ensure!(
        !account.is_verified(),
        "{} is already verified",
        account.username
    );
    nntmux_site::send_verification_email(
        ctx,
        i64::from(account.id),
        &account.email,
        &account.username,
    )
    .await
}

#[derive(Debug, FromRow)]
pub struct Passkey {
    pub id: u64,
    pub authenticatable_id: u32,
    pub name: String,
    pub last_used_at: Option<NaiveDateTime>,
    pub created_at: Option<NaiveDateTime>,
}

const PASSKEY: &str = "SELECT id, authenticatable_id, name, \
     CAST(last_used_at AS DATETIME) AS last_used_at, CAST(created_at AS DATETIME) AS created_at \
     FROM passkeys";

pub async fn passkeys(db: &MySqlPool, user: u32) -> Result<Vec<Passkey>> {
    Ok(sqlx::query_as(sqlx::AssertSqlSafe(format!(
        "{PASSKEY} WHERE authenticatable_id = ? ORDER BY id"
    )))
    .bind(user)
    .fetch_all(db)
    .await?)
}

pub async fn passkey(db: &MySqlPool, id: u64) -> Result<Option<Passkey>> {
    Ok(
        sqlx::query_as(sqlx::AssertSqlSafe(format!("{PASSKEY} WHERE id = ?")))
            .bind(id)
            .fetch_optional(db)
            .await?,
    )
}

/// `destroyPasskey`.
pub async fn delete_passkey(db: &MySqlPool, passkey: &Passkey) -> Result<bool> {
    let deleted = sqlx::query("DELETE FROM passkeys WHERE id = ?")
        .bind(passkey.id)
        .execute(db)
        .await?
        .rows_affected();
    info!(
        target: "admin",
        target_user_id = passkey.authenticatable_id,
        passkey_id = passkey.id,
        passkey_name = %passkey.name,
        "admin deleted user passkey"
    );
    Ok(deleted > 0)
}

/// `wipePasskeys`: removes every passkey, so the user signs in with a password again.
pub async fn wipe_passkeys(db: &MySqlPool, account: &Account) -> Result<u64> {
    let wiped = sqlx::query("DELETE FROM passkeys WHERE authenticatable_id = ?")
        .bind(account.id)
        .execute(db)
        .await?
        .rows_affected();
    warn!(
        target: "admin",
        target_user_id = account.id,
        target_username = %account.username,
        wiped_count = wiped,
        "admin wiped user passkeys"
    );
    Ok(wiped)
}

async fn log_activity(
    tx: &mut Transaction<'_, MySql>,
    user_id: u32,
    username: &str,
    activity: &str,
    description: &str,
    metadata: serde_json::Value,
) -> Result<()> {
    sqlx::query(
        "INSERT INTO user_activities \
         (user_id, username, activity_type, description, metadata, is_permanent, created_at) \
         VALUES (?, ?, ?, ?, ?, 0, NOW())",
    )
    .bind(user_id)
    .bind(username)
    .bind(activity)
    .bind(description)
    .bind(metadata.to_string())
    .execute(&mut **tx)
    .await?;
    Ok(())
}
