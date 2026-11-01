//! API users: token lookup, role limits and category exclusions.

use anyhow::Result;
use md5::{Digest, Md5};
use nntmux_runtime::Ctx;
use serde::{Deserialize, Serialize};
use sqlx::{AssertSqlSafe, Row};

use crate::cache;
use crate::settings::Settings;

const ROLE_DISABLED: i64 = 3;

/// The roots each `view ...` permission guards.
const CATEGORY_PERMISSIONS: [(&str, i64); 8] = [
    ("view console", 1000),
    ("view movies", 2000),
    ("view audio", 3000),
    ("view pc", 4000),
    ("view tv", 5000),
    ("view adult", 6000),
    ("view books", 7000),
    ("view other", 1),
];

#[derive(Clone, Debug, Deserialize, Serialize)]
pub struct User {
    pub id: i64,
    pub username: String,
    pub api_token: String,
    pub roles_id: i64,
    pub rate_limit: i64,
    pub verified: bool,
    pub can_post: bool,
    /// Holds the Disabled role through the permission tables, separately from `roles_id`.
    pub has_disabled_role: bool,
    pub api_requests: i64,
    pub download_requests: i64,
}

impl User {
    /// `$user->is_disabled || $user->hasRole('Disabled')`.
    pub fn disabled(&self) -> bool {
        self.roles_id == ROLE_DISABLED || self.has_disabled_role
    }

    /// `$user?->roles_id === UserRole::DISABLED->value`, the throttle's narrower test.
    pub fn disabled_by_role_id(&self) -> bool {
        self.roles_id == ROLE_DISABLED
    }
}

/// Finds the user owning a token. Soft-deleted users never match. Cached for five minutes under
/// `api_user:<md5>`, the key user updates clear.
pub async fn resolve(ctx: &Ctx, settings: &Settings, token: &str) -> Result<Option<User>> {
    let key = format!("api_user:{}", hex::encode(Md5::digest(token)));
    if let Some(user) = cache::get::<User>(ctx, settings, &key).await {
        return Ok(Some(user));
    }
    let user = find_by_token(ctx, token).await?;
    if let Some(user) = &user {
        cache::put(ctx, settings, &key, 300, user).await;
    }
    Ok(user)
}

/// `User::findVerifiedByApiToken` behind the same cache. Laravel shared one key between its verified
/// and unverified lookups, so a cached unverified user could pass here; this checks every time.
pub async fn resolve_verified(ctx: &Ctx, settings: &Settings, token: &str) -> Result<Option<User>> {
    Ok(resolve(ctx, settings, token)
        .await?
        .filter(|user| user.verified))
}

/// `findVerifiedByApiToken` without a cache, as RSS and getnzb use it.
pub async fn find_verified(ctx: &Ctx, token: &str) -> Result<Option<User>> {
    Ok(find_by_token(ctx, token)
        .await?
        .filter(|user| user.verified))
}

async fn find_by_token(ctx: &Ctx, token: &str) -> Result<Option<User>> {
    let row = sqlx::query(
        "SELECT u.id, u.username, u.api_token, u.roles_id, u.rate_limit, u.can_post,
                (u.verified = 1 OR u.email_verified_at IS NOT NULL) AS is_verified,
                CAST(COALESCE(r.apirequests, 0) AS SIGNED) AS apirequests,
                CAST(COALESCE(r.downloadrequests, 0) AS SIGNED) AS downloadrequests,
                EXISTS (SELECT 1 FROM model_has_roles mhr
                        INNER JOIN roles dr ON dr.id = mhr.role_id
                        WHERE mhr.model_id = u.id AND mhr.model_type = 'App\\\\Models\\\\User'
                          AND dr.name = 'Disabled') AS has_disabled_role
         FROM users u
         LEFT JOIN roles r ON r.id = u.roles_id
         WHERE u.api_token = ? AND u.deleted_at IS NULL
         LIMIT 1",
    )
    .bind(token)
    .fetch_optional(&ctx.db)
    .await?;
    let Some(row) = row else {
        return Ok(None);
    };
    Ok(Some(User {
        id: i64::from(row.try_get::<u32, _>("id")?),
        username: row.try_get("username")?,
        api_token: row.try_get("api_token")?,
        roles_id: i64::from(row.try_get::<i32, _>("roles_id")?),
        rate_limit: i64::from(row.try_get::<i32, _>("rate_limit")?),
        can_post: row.try_get("can_post")?,
        verified: row.try_get::<i64, _>("is_verified")? == 1,
        has_disabled_role: row.try_get::<i64, _>("has_disabled_role")? == 1,
        api_requests: row.try_get("apirequests")?,
        download_requests: row.try_get("downloadrequests")?,
    }))
}

/// `User::getCachedCategoryExclusionById`: category ids a user may not see, from missing `view`
/// permissions and their own excluded list. The key is the one profile changes clear.
pub async fn category_exclusions(ctx: &Ctx, settings: &Settings, user_id: i64) -> Result<Vec<i64>> {
    let key = format!("user_category_exclusions_{user_id}");
    if let Some(cached) = cache::get::<Vec<i64>>(ctx, settings, &key).await {
        return Ok(cached);
    }
    let exclusions = load_exclusions(ctx, user_id).await?;
    cache::put(ctx, settings, &key, 300, &exclusions).await;
    Ok(exclusions)
}

async fn load_exclusions(ctx: &Ctx, user_id: i64) -> Result<Vec<i64>> {
    // Allowed means granted directly: Laravel intersected all permissions with direct ones.
    let allowed: Vec<String> = sqlx::query_scalar(
        "SELECT p.name FROM model_has_permissions m
         INNER JOIN permissions p ON p.id = m.permission_id
         WHERE m.model_id = ? AND m.model_type = 'App\\\\Models\\\\User'",
    )
    .bind(user_id)
    .fetch_all(&ctx.db)
    .await?;
    let excluded_roots: Vec<i64> = CATEGORY_PERMISSIONS
        .iter()
        .filter(|(name, _)| !allowed.iter().any(|permission| permission == name))
        .map(|(_, root)| *root)
        .collect();

    let mut exclusions: Vec<i64> = Vec::new();
    if !excluded_roots.is_empty() {
        let roots = join_ids(&excluded_roots);
        let ids: Vec<i32> = sqlx::query_scalar(AssertSqlSafe(format!(
            "SELECT id FROM categories WHERE root_categories_id IN ({roots})"
        )))
        .fetch_all(&ctx.db)
        .await?;
        exclusions.extend(ids.into_iter().map(i64::from));
    }

    let not_in_excluded_root = if excluded_roots.is_empty() {
        String::new()
    } else {
        format!(
            " AND c.root_categories_id NOT IN ({})",
            join_ids(&excluded_roots)
        )
    };
    let own: Vec<i32> = sqlx::query_scalar(AssertSqlSafe(format!(
        "SELECT c.id FROM categories c
         INNER JOIN user_excluded_categories e ON e.categories_id = c.id
         WHERE e.users_id = ?{not_in_excluded_root}"
    )))
    .bind(user_id)
    .fetch_all(&ctx.db)
    .await?;
    for id in own {
        let id = i64::from(id);
        if !exclusions.contains(&id) {
            exclusions.push(id);
        }
    }
    Ok(exclusions)
}

fn join_ids(ids: &[i64]) -> String {
    ids.iter().map(i64::to_string).collect::<Vec<_>>().join(",")
}
