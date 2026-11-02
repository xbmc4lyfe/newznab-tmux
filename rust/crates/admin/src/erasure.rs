//! `GdprErasureService`: erasing an account's personal data, then soft- or force-deleting it.
//! Usage, preferences and security records go; community and retained records keep only
//! anonymous identifiers (`GdprDataInventory`). Like the PHP, a table or column missing from
//! this schema is skipped.

use std::collections::{HashMap, HashSet};

use anyhow::{Context, Result};
use chrono::NaiveDateTime;
use nntmux_runtime::Ctx;
use rand::RngCore;
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use sqlx::{MySql, Transaction};

use crate::accounts::{USER_MODEL, forget_api_caches};

/// `GdprDataInventory::retainedRecords`, recorded in the audit row.
fn retained_records() -> Value {
    json!([
        {
            "table": "payments",
            "reason": "Accounting, fraud prevention, dispute handling, and legal/tax obligations; direct account identifiers are anonymized while transaction details are retained.",
            "erasure_action": "retained_anonymized",
        },
        {
            "table": "paypal_payments",
            "reason": "Legacy payment audit data retained for accounting and dispute handling; linked account identity resolves only to the anonymized/deleted account record.",
            "erasure_action": "retained_minimized",
        },
        {
            "table": "user_activities",
            "reason": "Security and administrative audit trail.",
            "erasure_action": "retained_minimized",
        },
        {
            "table": "gdpr_audit_logs",
            "reason": "GDPR accountability record for data subject requests.",
            "erasure_action": "retained_minimized",
        },
        {
            "table": "user_role_history",
            "reason": "Administrative audit trail for role changes, upgrades, expirations, and account security reviews.",
            "erasure_action": "retained_minimized",
        },
    ])
}

/// A column the delete must also match, with its value.
type Condition = Option<(&'static str, &'static str)>;

/// `GdprDataInventory::erasableTables`: (table, user column, extra condition).
const ERASABLE: &[(&str, &str, Condition)] = &[
    ("user_requests", "users_id", None),
    ("user_downloads", "users_id", None),
    ("dnzb_failures", "users_id", None),
    ("users_releases", "users_id", None),
    ("user_series", "users_id", None),
    ("user_movies", "users_id", None),
    ("user_excluded_categories", "users_id", None),
    ("user_invitations", "user_id", None),
    ("trusted_devices", "user_id", None),
    ("password_securities", "user_id", None),
    (
        "passkeys",
        "authenticatable_id",
        Some(("authenticatable_type", USER_MODEL)),
    ),
    (
        "model_has_permissions",
        "model_id",
        Some(("model_type", USER_MODEL)),
    ),
    (
        "model_has_roles",
        "model_id",
        Some(("model_type", USER_MODEL)),
    ),
    ("role_promotion_stats", "user_id", None),
];

/// The columns of every table in this database, as `Schema::hasColumn` read them.
struct Schema(HashMap<String, HashSet<String>>);

impl Schema {
    async fn load(tx: &mut Transaction<'_, MySql>) -> Result<Self> {
        let rows: Vec<(String, String)> = sqlx::query_as(
            "SELECT CAST(TABLE_NAME AS CHAR), CAST(COLUMN_NAME AS CHAR) \
             FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()",
        )
        .fetch_all(&mut **tx)
        .await?;
        let mut tables: HashMap<String, HashSet<String>> = HashMap::new();
        for (table, column) in rows {
            tables.entry(table).or_default().insert(column);
        }
        Ok(Self(tables))
    }

    fn has(&self, table: &str, column: &str) -> bool {
        self.0
            .get(table)
            .is_some_and(|columns| columns.contains(column))
    }
}

fn random_hex(bytes: usize) -> String {
    let mut buffer = vec![0_u8; bytes];
    rand::rng().fill_bytes(&mut buffer);
    hex::encode(buffer)
}

/// `sanitizeMetadata`: drops addresses and hosts and marks the row minimized.
fn sanitize(metadata: Option<&str>) -> String {
    let mut map = metadata
        .and_then(|text| serde_json::from_str::<Value>(text).ok())
        .and_then(|value| match value {
            Value::Object(map) => Some(map),
            _ => None,
        })
        .unwrap_or_default();
    for key in ["email", "ip_address", "host"] {
        map.remove(key);
    }
    map.insert("gdpr_minimized".to_owned(), Value::Bool(true));
    Value::Object(map).to_string()
}

/// What [`erase`] did.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Erased {
    pub id: u32,
    pub username: String,
    pub permanent: bool,
}

/// `eraseForAccountDeletion`: erases the account's data in one transaction, then soft-deletes
/// it, or removes it for good when `permanent` (`forceDeleteWithErasure`). Changes made here
/// record "System" as the actor, as an artisan run did.
pub async fn erase(ctx: &Ctx, id: u32, permanent: bool, now: NaiveDateTime) -> Result<Erased> {
    let mut tx = ctx.db.begin().await?;
    let (username, email, api_token, deleted_at): (String, String, String, Option<NaiveDateTime>) =
        sqlx::query_as(
            "SELECT username, email, api_token, CAST(deleted_at AS DATETIME) FROM users \
         WHERE id = ? FOR UPDATE",
        )
        .bind(id)
        .fetch_optional(&mut *tx)
        .await?
        .with_context(|| format!("user {id} doesn't exist"))?;
    let schema = Schema::load(&mut tx).await?;
    let anonymous_username = format!("deleted_user_{id}");
    let anonymous_email = format!("deleted-user-{id}@deleted.invalid");

    // `deleteErasableRecords`.
    for (table, column, extra) in ERASABLE {
        if !schema.has(table, column) {
            continue;
        }
        let mut sql = format!("DELETE FROM `{table}` WHERE `{column}` = ?");
        let extra = extra.filter(|(extra, _)| schema.has(table, extra));
        if let Some((extra, _)) = extra {
            sql.push_str(&format!(" AND `{extra}` = ?"));
        }
        let mut query = sqlx::query(sqlx::AssertSqlSafe(sql)).bind(id);
        if let Some((_, value)) = extra {
            query = query.bind(value);
        }
        query.execute(&mut *tx).await?;
    }

    // `anonymizeCommunityRecords`. The comment's own username column takes the anonymous
    // account name, as the PHP's later `username` override did.
    if schema.has("release_comments", "users_id") {
        let host = if schema.has("release_comments", "host") {
            ", host = NULL"
        } else {
            ""
        };
        let username = if schema.has("release_comments", "username") {
            ", username = ?"
        } else {
            ""
        };
        if !host.is_empty() || !username.is_empty() {
            let sql = format!(
                "UPDATE release_comments SET users_id = users_id{username}{host} WHERE users_id = ?"
            );
            let mut query = sqlx::query(sqlx::AssertSqlSafe(sql));
            if !username.is_empty() {
                query = query.bind(&anonymous_username);
            }
            query.bind(id).execute(&mut *tx).await?;
        }
    }
    if schema.has("release_reports", "users_id") && schema.has("release_reports", "description") {
        let username = if schema.has("release_reports", "username") {
            ", username = ?"
        } else {
            ""
        };
        let sql = format!(
            "UPDATE release_reports SET description = '[Removed during account erasure]'{username} \
             WHERE users_id = ?"
        );
        let mut query = sqlx::query(sqlx::AssertSqlSafe(sql));
        if !username.is_empty() {
            query = query.bind(&anonymous_username);
        }
        query.bind(id).execute(&mut *tx).await?;
    }

    // `minimizeInvitations`.
    if schema.has("invitations", "email") {
        sqlx::query("UPDATE invitations SET email = ? WHERE email = ?")
            .bind(&anonymous_email)
            .bind(&email)
            .execute(&mut *tx)
            .await?;
    }
    if schema.has("invitations", "used_by") {
        sqlx::query("UPDATE invitations SET used_by = NULL WHERE used_by = ?")
            .bind(id)
            .execute(&mut *tx)
            .await?;
    }
    if schema.has("invitations", "invited_by") && schema.has("invitations", "used_at") {
        sqlx::query("DELETE FROM invitations WHERE invited_by = ? AND used_at IS NULL")
            .bind(id)
            .execute(&mut *tx)
            .await?;
    }

    // `anonymizeRetainedPaymentRecords`.
    let (pay_email, pay_username) = (
        schema.has("payments", "email"),
        schema.has("payments", "username"),
    );
    if pay_email || pay_username {
        let mut sets = Vec::new();
        let mut wheres = Vec::new();
        if pay_email {
            sets.push("email = ?");
            wheres.push("email = ?");
        }
        if pay_username {
            sets.push("username = ?");
            wheres.push("username = ?");
        }
        let sql = format!(
            "UPDATE payments SET {} WHERE {}",
            sets.join(", "),
            wheres.join(" OR ")
        );
        let mut query = sqlx::query(sqlx::AssertSqlSafe(sql));
        if pay_email {
            query = query.bind(&anonymous_email);
        }
        if pay_username {
            query = query.bind(&anonymous_username);
        }
        if pay_email {
            query = query.bind(&email);
        }
        if pay_username {
            query = query.bind(&username);
        }
        query.execute(&mut *tx).await?;
    }

    // `anonymizeRetainedAuditRecords`.
    if schema.has("user_activities", "username") {
        let rows: Vec<(u64, Option<String>)> = sqlx::query_as(
            "SELECT id, CAST(metadata AS CHAR) FROM user_activities \
             WHERE user_id = ? OR username = ? ORDER BY id",
        )
        .bind(id)
        .bind(&username)
        .fetch_all(&mut *tx)
        .await?;
        for (row, metadata) in rows {
            sqlx::query("UPDATE user_activities SET username = ?, metadata = ? WHERE id = ?")
                .bind(&anonymous_username)
                .bind(sanitize(metadata.as_deref()))
                .bind(row)
                .execute(&mut *tx)
                .await?;
        }
    }
    if schema.has("gdpr_audit_logs", "metadata") {
        sqlx::query("UPDATE gdpr_audit_logs SET metadata = ? WHERE user_id = ?")
            .bind(json!({ "subject": anonymous_username }).to_string())
            .bind(id)
            .execute(&mut *tx)
            .await?;
    }

    // `anonymizeUser`.
    let password = nntmux_webkit::auth::hash_password(&random_hex(32))?;
    let api = hex::encode(Sha256::digest(
        format!("deleted-{id}-{}", random_hex(16)).as_bytes(),
    ));
    sqlx::query(
        "UPDATE users SET username = ?, email = ?, password = ?, remember_token = NULL, \
         session_token = NULL, api_token = ?, resetguid = NULL, verification_token = NULL, \
         firstname = NULL, lastname = NULL, name = ?, host = '', notes = NULL, \
         email_verified_at = NULL, verified = 0, can_post = 0, invites = 0 WHERE id = ?",
    )
    .bind(&anonymous_username)
    .bind(&anonymous_email)
    .bind(password)
    .bind(&api)
    .bind(format!("Deleted user {id}"))
    .bind(id)
    .execute(&mut *tx)
    .await?;

    sqlx::query(
        "INSERT INTO gdpr_audit_logs (gdpr_request_id, user_id, actor_id, event, description, \
         metadata, created_at) VALUES (NULL, ?, NULL, ?, ?, ?, ?)",
    )
    .bind(id)
    .bind(if permanent {
        "erasure_force_delete"
    } else {
        "erasure_soft_delete"
    })
    .bind(
        "GDPR erasure workflow completed; retained payment and audit records were \
         anonymized/minimized.",
    )
    .bind(json!({ "retained_records": retained_records(), "force_delete": permanent }).to_string())
    .bind(now)
    .execute(&mut *tx)
    .await?;

    if permanent {
        sqlx::query("DELETE FROM users WHERE id = ?")
            .bind(id)
            .execute(&mut *tx)
            .await?;
        log_deleted(
            &mut tx,
            None,
            &anonymous_username,
            &format!("User permanently deleted: {anonymous_username}"),
            true,
            json!({ "email": anonymous_email, "deleted_by": crate::users::ACTOR, "permanent": true }),
            now,
        )
        .await?;
    } else if deleted_at.is_none() {
        sqlx::query("UPDATE users SET deleted_at = ?, updated_at = ?, deleted_by = ? WHERE id = ?")
            .bind(now)
            .bind(now)
            .bind(crate::users::ACTOR)
            .bind(id)
            .execute(&mut *tx)
            .await?;
        log_deleted(
            &mut tx,
            Some(id),
            &anonymous_username,
            &format!("User deleted: {anonymous_username}"),
            false,
            json!({ "email": anonymous_email, "deleted_by": crate::users::ACTOR }),
            now,
        )
        .await?;
    }
    tx.commit().await?;
    // The API cached the account under its old token, so that entry goes too.
    forget_api_caches(ctx, u64::from(id), &api_token).await;
    Ok(Erased {
        id,
        username,
        permanent,
    })
}

async fn log_deleted(
    tx: &mut Transaction<'_, MySql>,
    user_id: Option<u32>,
    username: &str,
    description: &str,
    permanent: bool,
    metadata: Value,
    now: NaiveDateTime,
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
    .bind(now)
    .execute(&mut **tx)
    .await?;
    Ok(())
}
