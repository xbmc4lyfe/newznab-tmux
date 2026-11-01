//! `nntmux:update-expired-roles`: warns users whose role is about to expire, then moves expired users
//! to their pending role or back to the default one.

use anyhow::Result;
use chrono::{DateTime, Datelike, Days, NaiveDate, NaiveDateTime, TimeDelta, Utc};
use nntmux_core::{optional, var_or};
use nntmux_runtime::Ctx;
use nntmux_webkit::mail::{self, OutgoingMail};
use sqlx::{MySql, MySqlPool, Transaction};
use tracing::warn;

const DAYS_PER_YEAR: i64 = 365;
const DEFAULT_ROLE_ID: i64 = 1;
const DEFAULT_ROLE: &str = "User";
const USER_MODEL: &str = "App\\Models\\User";

#[derive(Clone, Debug, sqlx::FromRow)]
struct ExpiringUser {
    id: i64,
    username: String,
    email: String,
    roles_id: i64,
    role: Option<String>,
    rolechangedate: Option<NaiveDateTime>,
    pending_roles_id: Option<i64>,
    pending_role_start_date: Option<NaiveDateTime>,
    pending_role: Option<String>,
}

const USER_COLUMNS: &str = "CAST(u.id AS SIGNED) AS id, u.username, u.email, CAST(u.roles_id AS SIGNED) AS roles_id, \
     r.name AS role, u.rolechangedate, CAST(u.pending_roles_id AS SIGNED) AS pending_roles_id, \
     u.pending_role_start_date, p.name AS pending_role \
     FROM users u LEFT JOIN roles r ON r.id = u.roles_id LEFT JOIN roles p ON p.id = u.pending_roles_id \
     WHERE u.deleted_at IS NULL";

/// Carbon's `addMonth`: a day the next month doesn't have rolls over, so Jan 31 becomes early March.
pub fn add_month_with_overflow(date: NaiveDate) -> NaiveDate {
    let (year, month) = if date.month() == 12 {
        (date.year() + 1, 1)
    } else {
        (date.year(), date.month() + 1)
    };
    NaiveDate::from_ymd_opt(year, month, 1)
        .and_then(|first| first.checked_add_days(Days::new(u64::from(date.day() - 1))))
        .unwrap_or(date)
}

fn subject(text: &str) -> String {
    let prefix = optional("MAIL_SUBJECT_PREFIX")
        .unwrap_or_else(|| format!("[{}] ", var_or("APP_NAME", "NNTmux")));
    format!("{prefix}{text}")
}

fn escape(value: &str) -> String {
    value
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
}

fn letter(to: &str, subject_text: &str, heading: &str, paragraphs: &[String]) -> OutgoingMail {
    let site = var_or("APP_NAME", "NNTmux");
    let signoff = format!("The {site} Team");
    let html: String = paragraphs
        .iter()
        .map(|paragraph| format!("<p>{}</p>", escape(paragraph)))
        .collect();
    OutgoingMail {
        to: to.to_owned(),
        subject: subject(subject_text),
        html: format!(
            "<h1>{}</h1>{html}<p>Best regards,<br>{}</p>",
            escape(heading),
            escape(&signoff)
        ),
        text: format!(
            "{heading}\n\n{}\n\nBest regards,\n{signoff}\n",
            paragraphs.join("\n\n")
        ),
        reply_to: None,
    }
}

fn will_expire_mail(user: &ExpiringUser, days: i64) -> OutgoingMail {
    let account = user.role.as_deref().unwrap_or(DEFAULT_ROLE);
    let mut paragraphs = vec![
        format!("Dear {},", user.username),
        format!("Heads up: Your {account} role expires in less than {days} day(s)."),
    ];
    match (&user.pending_role, user.pending_role_start_date) {
        (Some(pending), Some(start)) if user.pending_roles_id.is_some() => {
            paragraphs.push(format!(
                "Your {pending} role is already scheduled to take effect on {} after your current role expires.",
                start.format("%b %-d, %Y")
            ));
            paragraphs.push(
                "No renewal action is needed for that pending role. If these details do not look right, please reach out before the expiry date."
                    .to_owned(),
            );
        }
        _ => paragraphs.push(
            "To continue enjoying uninterrupted access to all your current features and benefits, please take action before your subscription expires."
                .to_owned(),
        ),
    }
    paragraphs.push(
        "If you have any questions about renewing your account, please don't hesitate to reach out."
            .to_owned(),
    );
    letter(
        &user.email,
        "Your account is about to expire",
        "Account expiration notice",
        &paragraphs,
    )
}

fn expired_mail(user: &ExpiringUser) -> OutgoingMail {
    letter(
        &user.email,
        "Your account has expired",
        "Account expired",
        &[
            format!("Dear {},", user.username),
            "We regret to inform you that your account subscription has expired.".to_owned(),
            format!("Account downgraded: Your account has been automatically downgraded to {DEFAULT_ROLE}."),
            "Don't worry — you can still access the site with your downgraded account level.".to_owned(),
            "If you have any questions or need help with the renewal process, please don't hesitate to contact us.".to_owned(),
        ],
    )
}

async fn send(ctx: &Ctx, message: &OutgoingMail) {
    if let Err(failure) = mail::enqueue(ctx, message).await {
        warn!(
            error = format!("{failure:#}"),
            "unable to queue the role expiry mail"
        );
    }
}

async fn send_warnings(ctx: &Ctx, now: DateTime<Utc>) -> Result<u64> {
    let today = now.date_naive();
    let periods = [
        today + TimeDelta::days(1),
        today + TimeDelta::days(7),
        add_month_with_overflow(today),
    ];
    let mut sent = 0;
    for period in periods {
        let days = (period - today).num_days();
        let users: Vec<ExpiringUser> = sqlx::query_as(sqlx::AssertSqlSafe(format!(
            "SELECT {USER_COLUMNS} AND DATE(u.rolechangedate) = ?"
        )))
        .bind(period)
        .fetch_all(&ctx.db)
        .await?;
        for user in users {
            send(ctx, &will_expire_mail(&user, days)).await;
            sent += 1;
        }
    }
    Ok(sent)
}

async fn sync_role(tx: &mut Transaction<'_, MySql>, user: i64, role: i64) -> Result<()> {
    sqlx::query("DELETE FROM model_has_roles WHERE model_type = ? AND model_id = ?")
        .bind(USER_MODEL)
        .bind(user)
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

struct Change<'a> {
    new_role: i64,
    new_expiry: Option<NaiveDateTime>,
    stacked: bool,
    reason: &'a str,
}

/// The user's row, their permission role and the history entry change together. Returns false if an
/// admin changed the user's expiry after it was read, which leaves the user alone.
async fn apply(
    db: &MySqlPool,
    user: &ExpiringUser,
    change: Change<'_>,
    now: NaiveDateTime,
) -> Result<bool> {
    let mut tx = db.begin().await?;
    let updated = sqlx::query(
        "UPDATE users SET roles_id = ?, rolechangedate = ?, pending_roles_id = NULL, \
         pending_role_start_date = NULL, updated_at = NOW() WHERE id = ? AND rolechangedate <=> ?",
    )
    .bind(change.new_role)
    .bind(change.new_expiry)
    .bind(user.id)
    .bind(user.rolechangedate)
    .execute(&mut *tx)
    .await?
    .rows_affected();
    if updated == 0 {
        return Ok(false);
    }
    sync_role(&mut tx, user.id, change.new_role).await?;
    sqlx::query(
        "INSERT INTO user_role_history (user_id, old_role_id, new_role_id, old_expiry_date, new_expiry_date, \
         effective_date, is_stacked, change_reason, created_at, updated_at) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
    )
    .bind(user.id)
    .bind(user.roles_id)
    .bind(change.new_role)
    .bind(user.rolechangedate)
    .bind(change.new_expiry)
    .bind(now)
    .bind(change.stacked)
    .bind(change.reason)
    .execute(&mut *tx)
    .await?;
    tx.commit().await?;
    Ok(true)
}

/// Days a role grants: its years, plus every promotion active now that applies to it. A promotion
/// naming no roles applies to all of them.
async fn granted_days(db: &MySqlPool, role: i64, now: NaiveDateTime) -> Result<Option<i64>> {
    let years: Option<i32> = sqlx::query_scalar("SELECT addyears FROM roles WHERE id = ?")
        .bind(role)
        .fetch_optional(db)
        .await?;
    let Some(years) = years else {
        return Ok(None);
    };
    let promotions: i64 = sqlx::query_scalar(
        "SELECT CAST(COALESCE(SUM(additional_days), 0) AS SIGNED) FROM role_promotions WHERE is_active = 1 \
         AND (start_date IS NULL OR start_date <= ?) AND (end_date IS NULL OR end_date >= ?) \
         AND (JSON_LENGTH(applicable_roles) = 0 OR JSON_CONTAINS(applicable_roles, CAST(? AS CHAR)))",
    )
    .bind(now)
    .bind(now)
    .bind(role)
    .fetch_one(db)
    .await?;
    Ok(Some(i64::from(years) * DAYS_PER_YEAR + promotions))
}

/// Returns `(activated, downgraded)`. One user's failure is logged and leaves them for tomorrow.
async fn process_expired(ctx: &Ctx, now: DateTime<Utc>) -> Result<(u64, u64)> {
    let db = &ctx.db;
    let moment = now.naive_utc();
    let users: Vec<ExpiringUser> = sqlx::query_as(sqlx::AssertSqlSafe(format!(
        "SELECT {USER_COLUMNS} AND u.rolechangedate IS NOT NULL AND u.rolechangedate < ?"
    )))
    .bind(moment)
    .fetch_all(db)
    .await?;
    let (mut activated, mut downgraded) = (0, 0);
    for user in users {
        let pending = user.pending_roles_id.filter(|_| {
            user.pending_role_start_date
                .is_some_and(|start| start <= moment)
        });
        let outcome = if let Some(role) = pending {
            match granted_days(db, role, moment).await {
                // A pending role that no longer exists leaves the user for an admin to sort out.
                Ok(None) => continue,
                Ok(Some(days)) => {
                    let new_expiry = (days > 0).then(|| moment + TimeDelta::days(days));
                    let change = Change {
                        new_role: role,
                        new_expiry,
                        stacked: true,
                        reason: "stacked_role_activated",
                    };
                    apply(db, &user, change, moment).await.map(|applied| {
                        activated += u64::from(applied);
                    })
                }
                Err(failure) => Err(failure),
            }
        } else {
            let change = Change {
                new_role: DEFAULT_ROLE_ID,
                new_expiry: None,
                stacked: false,
                reason: "role_expired",
            };
            match apply(db, &user, change, moment).await {
                Ok(true) => {
                    send(ctx, &expired_mail(&user)).await;
                    downgraded += 1;
                    Ok(())
                }
                Ok(false) => Ok(()),
                Err(failure) => Err(failure),
            }
        };
        if let Err(failure) = outcome {
            warn!(
                user = user.id,
                error = format!("{failure:#}"),
                "unable to update an expired role"
            );
        }
    }
    Ok((activated, downgraded))
}

#[derive(Debug, Default, PartialEq, Eq)]
pub struct Report {
    pub warned: u64,
    pub activated: u64,
    pub downgraded: u64,
}

pub async fn update_expired_roles(ctx: &Ctx) -> Result<Report> {
    update_expired_roles_at(ctx, Utc::now()).await
}

pub async fn update_expired_roles_at(ctx: &Ctx, now: DateTime<Utc>) -> Result<Report> {
    let warned = send_warnings(ctx, now).await?;
    let (activated, downgraded) = process_expired(ctx, now).await?;
    Ok(Report {
        warned,
        activated,
        downgraded,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn months_overflow_like_carbon() {
        let date = |y, m, d| NaiveDate::from_ymd_opt(y, m, d).expect("valid date");
        assert_eq!(
            add_month_with_overflow(date(2026, 3, 15)),
            date(2026, 4, 15)
        );
        assert_eq!(add_month_with_overflow(date(2026, 1, 31)), date(2026, 3, 3));
        assert_eq!(
            add_month_with_overflow(date(2026, 12, 31)),
            date(2027, 1, 31)
        );
    }
}
