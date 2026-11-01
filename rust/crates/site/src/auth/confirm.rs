use askama::Template;
use axum::response::{Html, IntoResponse, Redirect};
use chrono::Utc;
use nntmux_webkit::{CurrentUser, Input, Session, WebResult, auth};

use crate::guest::GuestLayout;

/// Session key Laravel's `password.confirm` middleware reads, and the passkey routes will too.
pub(crate) const CONFIRMED_AT: &str = "auth.password_confirmed_at";

#[derive(Template)]
#[template(path = "auth/confirm.html")]
struct ConfirmPage {
    layout: GuestLayout,
}

pub async fn show(layout: GuestLayout) -> WebResult {
    Ok(Html(ConfirmPage { layout }.render()?).into_response())
}

pub async fn submit(
    session: Session,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let password = input.str("password");
    if password.is_empty() {
        session.flash_errors(&[("password", "The password field is required.".to_owned())]);
        return Ok(Redirect::to("/confirm-password").into_response());
    }
    if !auth::verify_password(&password, &signed_in.user.password) {
        session.flash_errors(&[("password", "The password is incorrect.".to_owned())]);
        return Ok(Redirect::to("/confirm-password").into_response());
    }
    session.regenerate();
    session.put(CONFIRMED_AT, Utc::now().timestamp());
    Ok(Redirect::to("/profileedit#security").into_response())
}
