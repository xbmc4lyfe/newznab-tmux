use anyhow::Result;
use askama::Template;
use axum::extract::State;
use axum::http::HeaderMap;
use axum::response::{Html, IntoResponse, Response};
use chrono::NaiveDateTime;
use md5::{Digest, Md5};
use nntmux_runtime::Ctx;
use nntmux_webkit::session::random_string;
use nntmux_webkit::user::{CATEGORY_PERMISSIONS, USER_MODEL};
use nntmux_webkit::{ClientIp, Input, Session, WebResult, auth};
use serde_json::Value;
use sqlx::{MySql, Transaction};
use tracing::warn;

use super::{back, captcha_error, flash_form, verify};
use crate::guest::GuestLayout;
use crate::{mailer, validate};

const DEFAULT_ROLE: i32 = 1;
const DEFAULT_INVITES: i32 = 1;
const STATUS_OPEN: i32 = 1;
const STATUS_INVITE: i32 = 2;

#[derive(Template)]
#[template(path = "auth/register.html")]
struct RegisterPage {
    layout: GuestLayout,
    show_register: bool,
    error: String,
    notice: String,
    email: String,
    invite_code: String,
}

impl RegisterPage {
    fn invite_only_error(&self) -> bool {
        self.error.contains("invite only")
    }
}

/// `RegistrationStatusService::resolve`: a scheduled period opens registration regardless.
pub(crate) struct RegistrationStatus {
    pub is_open: bool,
    pub is_invite_only: bool,
    pub scheduled_override: bool,
    pub message: String,
}

pub(crate) async fn registration_status(ctx: &Ctx) -> Result<RegistrationStatus> {
    let manual: Option<i32> = sqlx::query_scalar(
        "SELECT CAST(status AS SIGNED) FROM registration_configurations ORDER BY id LIMIT 1",
    )
    .fetch_optional(&ctx.db)
    .await?
    .map(|status: i64| status as i32);
    let manual = manual
        .filter(|status| (1..=3).contains(status))
        .unwrap_or(STATUS_OPEN);
    let period: Option<(String, NaiveDateTime)> = sqlx::query_as(
        "SELECT name, ends_at FROM registration_periods
         WHERE is_enabled = 1 AND starts_at <= NOW() AND ends_at >= NOW() ORDER BY starts_at LIMIT 1",
    )
    .fetch_optional(&ctx.db)
    .await?;
    Ok(resolve_status(manual, period))
}

fn resolve_status(manual: i32, period: Option<(String, NaiveDateTime)>) -> RegistrationStatus {
    let scheduled_override = period.is_some() && manual != STATUS_OPEN;
    let effective = if period.is_some() {
        STATUS_OPEN
    } else {
        manual
    };
    let message = match (&period, scheduled_override) {
        (Some((name, ends)), true) => format!(
            "Registrations are temporarily open until {} because of the scheduled period \"{name}\".",
            ends.format("%Y-%m-%d %H:%M")
        ),
        _ => match effective {
            STATUS_OPEN => "Registrations are currently open.".to_owned(),
            STATUS_INVITE => "Registrations are currently invite only.".to_owned(),
            _ => "Registrations are currently closed.".to_owned(),
        },
    };
    RegistrationStatus {
        is_open: effective == STATUS_OPEN,
        is_invite_only: effective == STATUS_INVITE,
        scheduled_override,
        message,
    }
}

fn invite_code(input: &Input) -> String {
    ["invitecode", "token", "invitation"]
        .iter()
        .find_map(|name| input.get(name))
        .unwrap_or_default()
}

struct Invitation {
    id: i64,
    email: String,
    metadata: Option<String>,
}

async fn find_invitation(ctx: &Ctx, token: &str) -> Result<Option<Invitation>> {
    if token.is_empty() {
        return Ok(None);
    }
    let row: Option<(i64, String, Option<String>)> = sqlx::query_as(
        "SELECT CAST(id AS SIGNED), email, metadata FROM invitations
         WHERE token = ? AND is_active = 1 AND expires_at > NOW() AND used_at IS NULL",
    )
    .bind(token)
    .fetch_optional(&ctx.db)
    .await?;
    Ok(row.map(|(id, email, metadata)| Invitation {
        id,
        email,
        metadata,
    }))
}

pub async fn show(State(ctx): State<Ctx>, layout: GuestLayout, input: Input) -> WebResult {
    let invite = invite_code(&input);
    let status = registration_status(&ctx).await?;
    let invitation = find_invitation(&ctx, &invite).await?;
    let mut page = RegisterPage {
        layout,
        show_register: false,
        error: String::new(),
        notice: String::new(),
        email: String::new(),
        invite_code: invite.clone(),
    };
    if status.is_open {
        page.show_register = true;
        if let Some(invitation) = &invitation {
            page.email = invitation.email.clone();
        } else if !invite.is_empty() {
            page.notice =
                "Registration is currently open, so you can register without a valid invitation."
                    .to_owned();
        }
        if status.scheduled_override {
            page.notice = status.message;
        }
    } else if status.is_invite_only {
        if let Some(invitation) = &invitation {
            page.show_register = true;
            page.email = invitation.email.clone();
        } else if !invite.is_empty() {
            page.error = "Invalid or expired invitation token!".to_owned();
        } else {
            page.error = status.message;
        }
    } else {
        page.error = status.message;
    }
    Ok(Html(page.render()?).into_response())
}

fn back_with(
    session: &Session,
    headers: &HeaderMap,
    errors: &[(&str, String)],
    input: &Input,
) -> Response {
    let old = [
        ("username", input.str("username")),
        ("email", input.str("email")),
    ];
    flash_form(session, errors, &old);
    back(headers, "/register")
}

async fn validate_form(ctx: &Ctx, input: &Input) -> Result<Vec<(&'static str, String)>> {
    let mut errors = Vec::new();
    let username = input.str("username");
    if username.is_empty() {
        errors.push(("username", "The username field is required.".to_owned()));
    } else if username.chars().count() < 5 {
        errors.push((
            "username",
            "The username field must be at least 5 characters.".to_owned(),
        ));
    } else if username.chars().count() > 255 {
        errors.push((
            "username",
            "The username field must not be greater than 255 characters.".to_owned(),
        ));
    } else {
        let taken: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM users WHERE username = ?")
            .bind(&username)
            .fetch_one(&ctx.db)
            .await?;
        if taken > 0 {
            errors.push((
                "username",
                "The username has already been taken.".to_owned(),
            ));
        }
    }

    let email = input.str("email");
    if email.is_empty() {
        errors.push(("email", "The email field is required.".to_owned()));
    } else if !validate::valid_email(&email) {
        errors.push((
            "email",
            "The email field must be a valid email address.".to_owned(),
        ));
    } else if email.chars().count() > 255 {
        errors.push((
            "email",
            "The email field must not be greater than 255 characters.".to_owned(),
        ));
    } else {
        let taken: i64 =
            sqlx::query_scalar("SELECT COUNT(*) FROM users WHERE email = ? AND deleted_at IS NULL")
                .bind(&email)
                .fetch_one(&ctx.db)
                .await?;
        if taken > 0 {
            errors.push(("email", "The email has already been taken.".to_owned()));
        } else if let Some(message) = validate::disposable_error(&email) {
            errors.push(("email", message.to_owned()));
        } else if let Some((_, domain)) = email.rsplit_once('@')
            && !validate::domain_has_mail_records(domain).await
        {
            errors.push((
                "email",
                "The email domain does not appear to be valid or reachable.".to_owned(),
            ));
        }
    }

    let password = input.str("password");
    if password.is_empty() {
        errors.push(("password", "The password field is required.".to_owned()));
    } else {
        if password != input.str("password_confirmation") {
            errors.push((
                "password",
                "The password field confirmation does not match.".to_owned(),
            ));
        }
        let policy = validate::password_policy_errors(&password);
        let clean = policy.is_empty();
        errors.extend(
            policy
                .into_iter()
                .map(|message| ("password", message.to_owned())),
        );
        if clean && validate::is_breached(&password, 0).await {
            errors.push((
                "password",
                "The password appears in a data breach and should not be used.".to_owned(),
            ));
        }
    }
    Ok(errors)
}

/// Inserts the account with its role and the view permissions that role allows, which is what
/// the observer and `grantInheritedPermissions` did. Category access checks direct permissions.
async fn create_user(
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

fn metadata_role(metadata: Option<&str>) -> Option<i32> {
    let parsed: Value = serde_json::from_str(metadata?).ok()?;
    let role = parsed.get("role")?;
    role.as_i64()
        .or_else(|| role.as_str().and_then(|text| text.parse().ok()))
        .map(|role| role as i32)
}

pub async fn submit(
    State(ctx): State<Ctx>,
    session: Session,
    input: Input,
    ClientIp(ip): ClientIp,
    headers: HeaderMap,
) -> WebResult {
    let ip = ip.to_string();
    if let Some(error) = captcha_error(&input, &ip, "Please complete the human verification.").await
    {
        return Ok(back_with(&session, &headers, &[error], &input));
    }
    let errors = validate_form(&ctx, &input).await?;
    if !errors.is_empty() {
        warn!(target: "registration", reason = "validation_failed", "registration attempt failed");
        return Ok(back_with(&session, &headers, &errors, &input));
    }

    let invite = invite_code(&input);
    let email = input.str("email");
    let username = input.str("username");
    let refuse = |message: &str, reason: &str| {
        warn!(target: "registration", reason, "registration attempt failed");
        back_with(
            &session,
            &headers,
            &[("registration", message.to_owned())],
            &input,
        )
    };

    let deactivated: i64 =
        sqlx::query_scalar("SELECT COUNT(*) FROM users WHERE email = ? AND deleted_at IS NOT NULL")
            .bind(&email)
            .fetch_one(&ctx.db)
            .await?;
    if deactivated > 0 {
        return Ok(refuse(
            "This email address belongs to a deactivated account. Please contact us if you need help reactivating it.",
            "deactivated_account",
        ));
    }

    let status = registration_status(&ctx).await?;
    let invitation = find_invitation(&ctx, &invite).await?;
    let invitation = invitation.filter(|found| {
        found.email.is_empty() || found.email.trim().eq_ignore_ascii_case(email.trim())
    });
    if !(status.is_open || (status.is_invite_only && invitation.is_some())) {
        if status.is_invite_only
            && !invite.is_empty()
            && find_invitation(&ctx, &invite).await?.is_none()
        {
            return Ok(refuse(
                "Invalid or expired invitation token!",
                "invalid_or_expired_invitation",
            ));
        }
        let reason = if status.is_invite_only {
            "registrations_invite_only"
        } else {
            "registrations_closed"
        };
        return Ok(refuse(&status.message, reason));
    }

    let default: Option<(i32, i32)> = sqlx::query_as(
        "SELECT CAST(id AS SIGNED) + 0, CAST(defaultinvites AS SIGNED) + 0 FROM roles WHERE isdefault = 1 LIMIT 1",
    )
    .fetch_optional(&ctx.db)
    .await?;
    let (mut role, invites) = default.unwrap_or((DEFAULT_ROLE, DEFAULT_INVITES));
    if let Some(from_invite) = invitation
        .as_ref()
        .and_then(|found| metadata_role(found.metadata.as_deref()))
    {
        role = from_invite;
    }

    let hash = auth::hash_password(&input.str("password"))?;
    let created = async {
        let mut tx = ctx.db.begin().await?;
        let id = create_user(&mut tx, &username, &email, &hash, &ip, role, invites).await?;
        if let Some(found) = &invitation {
            sqlx::query("UPDATE invitations SET used_at = NOW(), used_by = ?, is_active = 0, updated_at = NOW() WHERE id = ?")
                .bind(id)
                .bind(found.id)
                .execute(&mut *tx)
                .await?;
        }
        tx.commit().await?;
        anyhow::Ok(id)
    }
    .await;
    let id = match created {
        Ok(id) => id,
        Err(failure) => {
            warn!(error = format!("{failure:#}"), "registration failed");
            return Ok(refuse(
                "We could not complete your registration right now. Please try again in a few minutes. If the problem continues, contact support.",
                "unexpected_exception",
            ));
        }
    };

    if let Some(mailbox) = mailer::site_mailbox() {
        auth::log_if_failed(
            mailer::send_new_account_notice(&ctx, &mailbox, &username).await,
            "new account notice",
        );
        auth::log_if_failed(
            mailer::send_welcome(&ctx, &email, &username).await,
            "welcome mail",
        );
        auth::log_if_failed(
            verify::send_verification(&ctx, id, &email, &username).await,
            "verification mail",
        );
    }
    session.flash("info", "Your account has been created. You will receive an account confirmation email shortly. Please verify your email address before logging in.");
    Ok(axum::response::Redirect::to("/login").into_response())
}

#[cfg(test)]
mod tests {
    use super::*;
    use chrono::NaiveDate;

    fn period() -> Option<(String, NaiveDateTime)> {
        let ends = NaiveDate::from_ymd_opt(2030, 1, 2)
            .unwrap()
            .and_hms_opt(3, 4, 0)
            .unwrap();
        Some(("Launch".to_owned(), ends))
    }

    #[test]
    fn a_scheduled_period_overrides_a_closed_site() {
        let status = resolve_status(3, period());
        assert!(status.is_open && status.scheduled_override);
        assert_eq!(
            status.message,
            "Registrations are temporarily open until 2030-01-02 03:04 because of the scheduled period \"Launch\"."
        );
        let plain = resolve_status(STATUS_OPEN, period());
        assert!(plain.is_open && !plain.scheduled_override);
        assert_eq!(plain.message, "Registrations are currently open.");
        assert!(resolve_status(STATUS_INVITE, None).is_invite_only);
        assert_eq!(
            resolve_status(3, None).message,
            "Registrations are currently closed."
        );
    }

    #[test]
    fn invitation_roles_parse_as_number_or_text() {
        assert_eq!(metadata_role(Some(r#"{"role":4}"#)), Some(4));
        assert_eq!(metadata_role(Some(r#"{"role":"5"}"#)), Some(5));
        assert_eq!(metadata_role(Some("{}")), None);
        assert_eq!(metadata_role(None), None);
    }
}
