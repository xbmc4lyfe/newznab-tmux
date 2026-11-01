use anyhow::Result;
use askama::Template;
use axum::extract::{Path, State};
use axum::http::HeaderMap;
use axum::response::{Html, IntoResponse, Redirect};
use chrono::{DateTime, Duration, Utc};
use nntmux_runtime::Ctx;
use nntmux_webkit::config::{self, encode_component};
use nntmux_webkit::session::random_string;
use nntmux_webkit::{ClientIp, Input, Session, User, WebResult, auth};

use super::{back, captcha_error, flash_form};
use crate::guest::GuestLayout;
use crate::{mailer, validate};

const SENT: &str = "Password reset email has been sent!";
const TOKEN_MINUTES: i64 = 60;

#[derive(Template)]
#[template(path = "auth/forgot.html")]
struct ForgotPage {
    layout: GuestLayout,
}

#[derive(Template)]
#[template(path = "auth/reset.html")]
struct ResetPage {
    layout: GuestLayout,
    token: String,
    email: String,
}

pub async fn show_request(layout: GuestLayout) -> WebResult {
    Ok(Html(ForgotPage { layout }.render()?).into_response())
}

/// The broker's token: bcrypted in `password_reset_tokens`, one row per address.
async fn create_token(ctx: &Ctx, email: &str) -> Result<String> {
    let token = random_string(64);
    let hash = bcrypt::hash(&token, 12)?;
    sqlx::query("DELETE FROM password_reset_tokens WHERE email = ?")
        .bind(email)
        .execute(&ctx.db)
        .await?;
    sqlx::query(
        "INSERT INTO password_reset_tokens (email, token, created_at) VALUES (?, ?, NOW())",
    )
    .bind(email)
    .bind(hash)
    .execute(&ctx.db)
    .await?;
    Ok(token)
}

async fn token_is_valid(ctx: &Ctx, email: &str, token: &str) -> Result<bool> {
    let row: Option<(String, Option<DateTime<Utc>>)> =
        sqlx::query_as("SELECT token, created_at FROM password_reset_tokens WHERE email = ?")
            .bind(email)
            .fetch_optional(&ctx.db)
            .await?;
    let Some((hash, Some(created))) = row else {
        return Ok(false);
    };
    let fresh = created + Duration::minutes(TOKEN_MINUTES) > Utc::now();
    Ok(fresh && bcrypt::verify(token, &hash).unwrap_or(false))
}

fn reset_path(token: &str, email: &str) -> String {
    format!("/password/reset/{token}?email={}", encode_component(email))
}

async fn clear_guid(ctx: &Ctx, id: i64) -> Result<()> {
    sqlx::query("UPDATE users SET resetguid = NULL WHERE id = ?")
        .bind(id)
        .execute(&ctx.db)
        .await?;
    Ok(())
}

pub async fn request_link(
    State(ctx): State<Ctx>,
    session: Session,
    input: Input,
    ClientIp(ip): ClientIp,
) -> WebResult {
    let email = input.str("email");
    if email.is_empty() {
        flash_form(
            &session,
            &[(
                "email",
                "Please enter your email address to send a password reset link.".to_owned(),
            )],
            &[],
        );
        return Ok(Redirect::to("/forgottenpassword").into_response());
    }
    if captcha_error(&input, &ip.to_string(), "Captcha validation failed.")
        .await
        .is_some()
    {
        flash_form(
            &session,
            &[("error", "Captcha validation failed.".to_owned())],
            &[("email", email)],
        );
        return Ok(Redirect::to("/forgottenpassword").into_response());
    }
    // The same answer either way, so this can't be used to probe for accounts.
    if let Some(user) = User::find_by(&ctx.db, "email", &email).await? {
        let token = create_token(&ctx, &user.email).await?;
        let url = format!("{}{}", config::app_url(), reset_path(&token, &user.email));
        mailer::send_password_reset(&ctx, &user.email, &url).await?;
        clear_guid(&ctx, user.id).await?;
    }
    session.flash("success", SENT);
    Ok(Redirect::to("/forgottenpassword").into_response())
}

pub async fn legacy_form(State(ctx): State<Ctx>, session: Session, input: Input) -> WebResult {
    let refuse = |message: &str| {
        session.flash("error", message);
        Redirect::to("/password/reset").into_response()
    };
    if !input.has("guid") {
        return Ok(refuse("No reset code provided."));
    }
    let guid = input.str("guid");
    let row: Option<(i64, String, bool)> = sqlx::query_as(
        "SELECT CAST(id AS SIGNED), email, deleted_at IS NOT NULL FROM users WHERE resetguid = ? AND resetguid <> ''",
    )
    .bind(&guid)
    .fetch_optional(&ctx.db)
    .await?;
    let Some((id, email, deleted)) = row else {
        return Ok(refuse("Bad reset code provided."));
    };
    if deleted {
        return Ok(refuse("This account has been deactivated."));
    }
    let token = create_token(&ctx, &email).await?;
    clear_guid(&ctx, id).await?;
    Ok(Redirect::to(&reset_path(&token, &email)).into_response())
}

pub async fn show_reset(layout: GuestLayout, Path(token): Path<String>, input: Input) -> WebResult {
    let email = input.get("email").unwrap_or_else(|| layout.old("email"));
    Ok(Html(
        ResetPage {
            layout,
            token,
            email,
        }
        .render()?,
    )
    .into_response())
}

pub async fn reset(
    State(ctx): State<Ctx>,
    session: Session,
    input: Input,
    headers: HeaderMap,
) -> WebResult {
    let token = input.str("token");
    let email = input.str("email");
    let password = input.str("password");
    let mut errors: Vec<(&str, String)> = Vec::new();
    if token.is_empty() {
        errors.push(("token", "The token field is required.".to_owned()));
    }
    if email.is_empty() {
        errors.push(("email", "The email field is required.".to_owned()));
    } else if !validate::valid_email(&email) {
        errors.push((
            "email",
            "The email field must be a valid email address.".to_owned(),
        ));
    }
    if password.is_empty() {
        errors.push(("password", "The password field is required.".to_owned()));
    } else {
        if password != input.str("password_confirmation") {
            errors.push((
                "password",
                "The password field confirmation does not match.".to_owned(),
            ));
        }
        if password.chars().count() < 8 {
            errors.push((
                "password",
                "The password field must be at least 8 characters.".to_owned(),
            ));
        }
    }
    let fail = |errors: &[(&str, String)]| {
        flash_form(&session, errors, &[("email", email.clone())]);
        back(&headers, "/password/reset")
    };
    if !errors.is_empty() {
        return Ok(fail(&errors));
    }
    let Some(user) = User::find_by(&ctx.db, "email", &email).await? else {
        return Ok(fail(&[(
            "email",
            "We can't find a user with that email address.".to_owned(),
        )]));
    };
    if !token_is_valid(&ctx, &user.email, &token).await? {
        return Ok(fail(&[(
            "email",
            "This password reset token is invalid.".to_owned(),
        )]));
    }
    sqlx::query("UPDATE users SET password = ?, remember_token = ?, resetguid = NULL, updated_at = NOW() WHERE id = ?")
        .bind(auth::hash_password(&password)?)
        .bind(random_string(60))
        .bind(user.id)
        .execute(&ctx.db)
        .await?;
    sqlx::query("DELETE FROM password_reset_tokens WHERE email = ?")
        .bind(&user.email)
        .execute(&ctx.db)
        .await?;
    session.flash("success", "Your password has been reset.");
    Ok(Redirect::to("/login").into_response())
}
