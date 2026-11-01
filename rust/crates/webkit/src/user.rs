//! The signed-in account: its row, roles and permissions.

use anyhow::Result;
use chrono::{DateTime, NaiveDateTime, Utc};
use md5::{Digest, Md5};
use sqlx::{FromRow, MySql, MySqlPool, Transaction};

use crate::session::random_string;

/// Spatie's `model_type` for users, as Laravel stored it.
pub const USER_MODEL: &str = "App\\Models\\User";
/// `roles.id` for a disabled account.
pub const ROLE_DISABLED: i32 = 3;

/// Which root category each `view ...` permission opens.
pub const CATEGORY_PERMISSIONS: [(&str, i64); 8] = [
    ("view console", 1000),
    ("view movies", 2000),
    ("view audio", 3000),
    ("view pc", 4000),
    ("view tv", 5000),
    ("view adult", 6000),
    ("view books", 7000),
    ("view other", 1),
];

#[derive(Clone, Debug, FromRow)]
pub struct User {
    pub id: i64,
    pub username: String,
    pub firstname: Option<String>,
    pub lastname: Option<String>,
    pub email: String,
    pub password: String,
    pub roles_id: i32,
    pub host: Option<String>,
    pub grabs: i32,
    pub api_token: String,
    pub lastlogin: Option<NaiveDateTime>,
    pub apiaccess: Option<NaiveDateTime>,
    pub lastdownload: Option<NaiveDateTime>,
    pub invites: i32,
    pub invitedby: Option<i32>,
    pub movieview: i32,
    pub xxxview: i32,
    pub musicview: i32,
    pub consoleview: i32,
    pub bookview: i32,
    pub gameview: i32,
    pub rate_limit: i32,
    pub notes: Option<String>,
    pub theme_preference: String,
    pub color_scheme: String,
    pub rolechangedate: Option<NaiveDateTime>,
    pub pending_role_start_date: Option<NaiveDateTime>,
    pub pending_roles_id: Option<i32>,
    pub email_verified_at: Option<DateTime<Utc>>,
    pub remember_token: Option<String>,
    pub session_token: Option<String>,
    pub timezone: Option<String>,
    pub movie_layout: i8,
    pub created_at: Option<DateTime<Utc>>,
    pub verified: i8,
    pub can_post: i8,
}

const COLUMNS: &str = "CAST(id AS SIGNED) AS id, username, firstname, lastname, email, password, roles_id, host, grabs, api_token, lastlogin, apiaccess, lastdownload, invites, invitedby, movieview, xxxview, musicview, consoleview, bookview, gameview, rate_limit, notes, theme_preference, color_scheme, rolechangedate, pending_role_start_date, pending_roles_id, email_verified_at, remember_token, session_token, timezone, movie_layout, created_at, verified, can_post";

impl User {
    pub async fn find(db: &MySqlPool, id: i64) -> Result<Option<Self>> {
        let sql = format!("SELECT {COLUMNS} FROM users WHERE id = ? AND deleted_at IS NULL");
        Ok(sqlx::query_as(sqlx::AssertSqlSafe(sql))
            .bind(id)
            .fetch_optional(db)
            .await?)
    }

    /// Finds by a column, skipping soft-deleted accounts. `column` must be a literal.
    pub async fn find_by(
        db: &MySqlPool,
        column: &'static str,
        value: &str,
    ) -> Result<Option<Self>> {
        let sql = format!("SELECT {COLUMNS} FROM users WHERE {column} = ? AND deleted_at IS NULL");
        Ok(sqlx::query_as(sqlx::AssertSqlSafe(sql))
            .bind(value)
            .fetch_optional(db)
            .await?)
    }

    pub fn has_verified_email(&self) -> bool {
        self.verified != 0 || self.email_verified_at.is_some()
    }

    pub fn is_disabled(&self) -> bool {
        self.roles_id == ROLE_DISABLED
    }

    /// Soft-deleted accounts never load, so this only looks at the rest of Laravel's rule.
    pub fn eligible_for_authentication(&self) -> bool {
        !self.is_disabled() && self.has_verified_email()
    }

    pub fn full_name(&self) -> String {
        let name = format!(
            "{} {}",
            self.firstname.as_deref().unwrap_or_default(),
            self.lastname.as_deref().unwrap_or_default()
        );
        let name = name.trim();
        if name.is_empty() {
            self.username.clone()
        } else {
            name.to_owned()
        }
    }
}

/// A user's roles and permissions, loaded once per request.
#[derive(Clone, Debug, Default)]
pub struct Access {
    pub roles: Vec<String>,
    /// Permissions granted to the user directly. Spatie's `hasDirectPermission`.
    pub direct: Vec<String>,
    /// Permissions from the user's roles.
    pub via_roles: Vec<String>,
}

impl Access {
    pub async fn load(db: &MySqlPool, user_id: i64) -> Result<Self> {
        let roles = sqlx::query_scalar(
            "SELECT r.name FROM roles r JOIN model_has_roles m ON m.role_id = r.id
             WHERE m.model_type = ? AND m.model_id = ? ORDER BY r.id",
        )
        .bind(USER_MODEL)
        .bind(user_id)
        .fetch_all(db)
        .await?;
        let direct = sqlx::query_scalar(
            "SELECT p.name FROM permissions p JOIN model_has_permissions m ON m.permission_id = p.id
             WHERE m.model_type = ? AND m.model_id = ?",
        )
        .bind(USER_MODEL)
        .bind(user_id)
        .fetch_all(db)
        .await?;
        let via_roles = sqlx::query_scalar(
            "SELECT DISTINCT p.name FROM permissions p
             JOIN role_has_permissions rp ON rp.permission_id = p.id
             JOIN model_has_roles m ON m.role_id = rp.role_id
             WHERE m.model_type = ? AND m.model_id = ?",
        )
        .bind(USER_MODEL)
        .bind(user_id)
        .fetch_all(db)
        .await?;
        Ok(Self {
            roles,
            direct,
            via_roles,
        })
    }

    pub fn has_role(&self, role: &str) -> bool {
        self.roles.iter().any(|name| name == role)
    }

    pub fn has_any_role(&self, roles: &[&str]) -> bool {
        roles.iter().any(|role| self.has_role(role))
    }

    pub fn has_direct_permission(&self, permission: &str) -> bool {
        self.direct.iter().any(|name| name == permission)
    }

    /// Spatie's `can`: granted directly or through a role.
    pub fn can(&self, permission: &str) -> bool {
        self.has_direct_permission(permission)
            || self.via_roles.iter().any(|name| name == permission)
    }

    /// Root categories this account can't see. Spatie's `getAllPermissions` includes direct
    /// permissions, so the intersection Laravel took is simply the direct set.
    pub fn excluded_roots(&self) -> Vec<i64> {
        CATEGORY_PERMISSIONS
            .iter()
            .filter(|(permission, _)| !self.has_direct_permission(permission))
            .map(|(_, root)| *root)
            .collect()
    }
}

/// Every category hidden from a user: all children of roots their permissions exclude, plus
/// what they excluded themselves. Roots and their children both count, so callers can filter
/// `categories_id` directly.
pub async fn category_exclusions(
    db: &MySqlPool,
    user_id: i64,
    access: &Access,
) -> Result<Vec<i64>> {
    let roots = access.excluded_roots();
    let mut hidden: Vec<i64> = sqlx::query_scalar(
        "SELECT CAST(c.id AS SIGNED) FROM categories c
         JOIN user_excluded_categories u ON u.categories_id = c.id
         WHERE u.users_id = ?",
    )
    .bind(user_id)
    .fetch_all(db)
    .await?;
    if !roots.is_empty() {
        let marks = vec!["?"; roots.len()].join(",");
        let sql = format!(
            "SELECT CAST(id AS SIGNED) FROM categories WHERE root_categories_id IN ({marks})"
        );
        let mut query = sqlx::query_scalar(sqlx::AssertSqlSafe(sql));
        for root in &roots {
            query = query.bind(*root);
        }
        hidden.extend::<Vec<i64>>(query.fetch_all(db).await?);
    }
    hidden.sort_unstable();
    hidden.dedup();
    Ok(hidden)
}

/// Inserts the account with its role and the view permissions that role allows, which is what
/// the observer and `grantInheritedPermissions` did. Category access checks direct permissions.
pub async fn create_user(
    tx: &mut Transaction<'_, MySql>,
    username: &str,
    email: &str,
    password_hash: &str,
    host: &str,
    role: i32,
    invites: i32,
) -> Result<i64> {
    let rate_limit: Option<i32> = sqlx::query_scalar("SELECT rate_limit FROM roles WHERE id = ?")
        .bind(role)
        .fetch_optional(&mut **tx)
        .await?;
    let api_token = hex::encode(Md5::digest(random_string(40).as_bytes()));
    let id = sqlx::query(
        "INSERT INTO users (username, email, password, host, roles_id, notes, invites, api_token, rate_limit,
                            verified, email_verified_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, '', ?, ?, ?, 0, NULL, NOW(), NOW())",
    )
    .bind(username)
    .bind(email)
    .bind(password_hash)
    .bind(host)
    .bind(role)
    .bind(invites)
    .bind(api_token)
    .bind(rate_limit.unwrap_or(60))
    .execute(&mut **tx)
    .await?
    .last_insert_id();
    sqlx::query("INSERT INTO model_has_roles (role_id, model_type, model_id) VALUES (?, ?, ?)")
        .bind(role)
        .bind(USER_MODEL)
        .bind(id)
        .execute(&mut **tx)
        .await?;
    let names = CATEGORY_PERMISSIONS
        .iter()
        .map(|(name, _)| format!("'{name}'"))
        .collect::<Vec<_>>()
        .join(",");
    let grant = format!(
        "INSERT INTO model_has_permissions (permission_id, model_type, model_id)
         SELECT p.id, ?, ? FROM permissions p
         JOIN role_has_permissions rp ON rp.permission_id = p.id
         WHERE rp.role_id = ? AND p.name IN ({names})"
    );
    sqlx::query(sqlx::AssertSqlSafe(grant))
        .bind(USER_MODEL)
        .bind(id)
        .bind(role)
        .execute(&mut **tx)
        .await?;
    Ok(id as i64)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_direct_permissions_open_categories() {
        let access = Access {
            direct: vec!["view tv".to_owned()],
            via_roles: vec!["view movies".to_owned()],
            ..Access::default()
        };
        let roots = access.excluded_roots();
        assert!(!roots.contains(&5000));
        assert!(
            roots.contains(&2000),
            "a role's permission doesn't lift the exclusion"
        );
        assert!(access.can("view movies"));
        assert!(!access.has_direct_permission("view movies"));
    }
}
