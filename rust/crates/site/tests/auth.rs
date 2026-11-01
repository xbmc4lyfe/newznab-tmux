//! Sign-in, registration, reset and verification against real MariaDB and Redis. Skips itself
//! when the test services aren't set. The breached-password check calls the live range API and
//! fails open without a network.

use axum::http::{StatusCode, header};
use nntmux_webkit::auth;
use totp_rs::{Algorithm, Builder, Secret};

mod common;

use common::*;

#[tokio::test]
async fn login_logout_and_failures() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("alice");
    let id = add_user(&db.pool, &name, true, 1).await;
    let mut browser = Browser::new(&ctx);

    let (response, html) = browser.get("/login").await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains("Sign in to your account"));
    assert!(
        response.headers()[header::CACHE_CONTROL]
            .to_str()
            .unwrap()
            .contains("no-store")
    );

    let (response, _) = browser
        .post("/login", &[("username", &name), ("password", "wrong")])
        .await;
    assert_eq!(location(&response), "/login");
    let (_, html) = browser.get("/login").await;
    assert!(html.contains("does not match our records"));

    // By email as well as username.
    let (response, _) = browser
        .post(
            "/login",
            &[
                ("username", &format!("{name}@example.com")),
                ("password", PASSWORD),
            ],
        )
        .await;
    assert_eq!(location(&response), "/");
    let lastlogin: Option<chrono::NaiveDateTime> =
        sqlx::query_scalar("SELECT lastlogin FROM users WHERE id = ?")
            .bind(id)
            .fetch_one(&db.pool)
            .await
            .unwrap();
    assert!(lastlogin.is_some());

    // Signed in, the form sends you away.
    let (response, _) = browser.get("/login").await;
    assert_eq!(location(&response), "/");

    let (response, _) = browser.post_with_token("/logout").await;
    assert_eq!(location(&response), "/login");
    let (response, html) = browser.get("/login").await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains("logged out"));

    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn three_failures_lock_the_form() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("carol");
    add_user(&db.pool, &name, true, 1).await;
    let mut browser = Browser::new(&ctx);
    for _ in 0..3 {
        browser
            .post("/login", &[("username", &name), ("password", "wrong")])
            .await;
    }
    // Even the right password is refused now.
    let (response, _) = browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    assert_eq!(location(&response), "/login");
    assert!(!browser_is_signed_in(&mut browser).await);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn unverified_and_disabled_accounts_cant_sign_in() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let unverified = unique("dave");
    let disabled = unique("erin");
    add_user(&db.pool, &unverified, false, 1).await;
    add_user(&db.pool, &disabled, true, 3).await;
    let mut browser = Browser::new(&ctx);
    browser
        .post(
            "/login",
            &[("username", &unverified), ("password", PASSWORD)],
        )
        .await;
    assert!(!browser_is_signed_in(&mut browser).await);
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &disabled), ("password", PASSWORD)])
        .await;
    assert!(!browser_is_signed_in(&mut browser).await);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn remember_cookie_signs_back_in_until_the_next_sign_in() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("frank");
    add_user(&db.pool, &name, true, 1).await;
    let mut browser = Browser::new(&ctx);
    browser
        .post(
            "/login",
            &[
                ("username", &name),
                ("password", PASSWORD),
                ("rememberme", "on"),
            ],
        )
        .await;
    assert!(browser.has_cookie("remember_web"));

    // The session is gone, the cookie still signs in.
    browser
        .cookies
        .retain(|(cookie, _)| cookie == "remember_web");
    assert!(browser_is_signed_in(&mut browser).await);

    // Signing in elsewhere ends it.
    let remembered = browser
        .cookies
        .iter()
        .find(|(cookie, _)| cookie == "remember_web")
        .unwrap()
        .clone();
    let mut other = Browser::new(&ctx);
    other
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    browser.cookies = vec![remembered];
    assert!(!browser_is_signed_in(&mut browser).await);
    assert!(
        !browser.has_cookie("remember_web"),
        "the dead cookie is cleared"
    );
    db.drop_database().await.unwrap();
}

fn current_code(secret: &str) -> String {
    let now = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap()
        .as_secs();
    Builder::new()
        .with_algorithm(Algorithm::SHA1)
        .with_digits(6)
        .with_skew(1)
        .with_step_duration(30)
        .with_secret(Secret::try_from_base32(secret).unwrap())
        .build_noncompliant()
        .generate(now)
        .to_string()
}

#[tokio::test]
async fn two_factor_challenge_and_trusted_device() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("grace");
    let id = add_user(&db.pool, &name, true, 1).await;
    let secret = "GEZDGNBVGY3TQOJQ";
    sqlx::query("INSERT INTO password_securities (user_id, google2fa_enable, google2fa_secret) VALUES (?, 1, ?)")
        .bind(id)
        .bind(secret)
        .execute(&db.pool)
        .await
        .unwrap();

    let mut browser = Browser::new(&ctx);
    let (response, _) = browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    assert_eq!(location(&response), "/2fa/verify");
    assert!(
        !browser_is_signed_in(&mut browser).await,
        "the password alone doesn't sign in"
    );

    let (response, html) = browser.get("/2fa/verify").await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains("Two-Factor Authentication"));

    let (response, _) = browser
        .post("/2fa/verify", &[("one_time_password", "000000")])
        .await;
    assert_eq!(location(&response), "/2fa/verify");
    assert!(!browser_is_signed_in(&mut browser).await);

    let code = current_code(secret);
    let (response, _) = browser
        .post(
            "/2faVerify",
            &[("one_time_password", &code), ("trust_device", "1")],
        )
        .await;
    assert_eq!(location(&response), "/");
    assert!(browser_is_signed_in(&mut browser).await);
    assert!(browser.has_cookie(auth::TRUSTED_DEVICE_COOKIE));

    // The trusted browser skips the challenge next time.
    let trusted = browser
        .cookies
        .iter()
        .find(|(cookie, _)| cookie == auth::TRUSTED_DEVICE_COOKIE)
        .unwrap()
        .clone();
    let mut returning = Browser::new(&ctx);
    returning.cookies.push(trusted);
    let (response, _) = returning
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    assert_eq!(location(&response), "/");
    assert!(browser_is_signed_in(&mut returning).await);

    // A device row that's gone means the challenge again.
    sqlx::query("DELETE FROM trusted_devices")
        .execute(&db.pool)
        .await
        .unwrap();
    let trusted = returning
        .cookies
        .iter()
        .find(|(cookie, _)| cookie == auth::TRUSTED_DEVICE_COOKIE)
        .unwrap()
        .clone();
    let mut stale = Browser::new(&ctx);
    stale.cookies.push(trusted);
    let (response, _) = stale
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    assert_eq!(location(&response), "/2fa/verify");

    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn registration_creates_a_usable_account_and_verification_opens_it() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("henry");
    let email = format!("{name}@example.com");
    let mut browser = Browser::new(&ctx);

    let (response, html) = browser.get("/register").await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains("Create Account"));

    // Weak password: back to the form with the reason, and what was typed.
    browser
        .post(
            "/register",
            &[
                ("username", &name),
                ("email", &email),
                ("password", "weak"),
                ("password_confirmation", "weak"),
                ("action", "submit"),
            ],
        )
        .await;
    let (_, html) = browser.get("/register").await;
    assert!(html.contains("at least 8 characters"));
    assert!(html.contains(&email));

    let (response, _) = browser
        .post(
            "/register",
            &[
                ("username", &name),
                ("email", &email),
                ("password", PASSWORD),
                ("password_confirmation", PASSWORD),
                ("action", "submit"),
            ],
        )
        .await;
    assert_eq!(location(&response), "/login");

    // The default role, its role row, and the role's view permissions as direct ones.
    let (id, role): (i64, i32) =
        sqlx::query_as("SELECT CAST(id AS SIGNED), roles_id FROM users WHERE username = ?")
            .bind(&name)
            .fetch_one(&db.pool)
            .await
            .unwrap();
    let default_role: i64 =
        sqlx::query_scalar("SELECT CAST(id AS SIGNED) FROM roles WHERE isdefault = 1 LIMIT 1")
            .fetch_one(&db.pool)
            .await
            .unwrap();
    assert_eq!(i64::from(role), default_role);
    let access = nntmux_webkit::user::Access::load(&db.pool, id)
        .await
        .unwrap();
    assert_eq!(access.roles.len(), 1);
    let via_role_views = access
        .via_roles
        .iter()
        .filter(|name| name.starts_with("view "))
        .count();
    let direct_views = access
        .direct
        .iter()
        .filter(|name| name.starts_with("view "))
        .count();
    assert_eq!(direct_views, via_role_views);

    // Not verified yet: no way in.
    let mut login = Browser::new(&ctx);
    login
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    assert!(!browser_is_signed_in(&mut login).await);

    // Mail only goes out when a from address is configured.
    if nntmux_core::optional("MAIL_FROM_ADDRESS").is_some() {
        let mail = take_mail(&ctx, &email).await;
        assert_eq!(mail.len(), 2, "welcome and verification");
    }

    // A tampered link is refused, a signed one verifies.
    let path = format!("/email/verify/{id}/nothash?expires=99999999999&signature=bad");
    let (response, _) = browser.get(&path).await;
    assert_eq!(response.status(), StatusCode::FORBIDDEN);

    use nntmux_webkit::config::{app_key, app_url, hmac_hex};
    let hash = sha1_hex(&email);
    let expires = chrono::Utc::now().timestamp() + 600;
    let path = format!("/email/verify/{id}/{hash}");
    let signature = hmac_hex(
        &app_key(),
        &format!("{}{path}?expires={expires}", app_url()),
    );
    let (response, _) = browser
        .get(&format!("{path}?expires={expires}&signature={signature}"))
        .await;
    assert_eq!(location(&response), "/login");
    let mut login = Browser::new(&ctx);
    login
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    assert!(
        browser_is_signed_in(&mut login).await,
        "verified accounts can sign in"
    );

    db.drop_database().await.unwrap();
}

fn sha1_hex(text: &str) -> String {
    use sha1::{Digest, Sha1};
    hex::encode(Sha1::digest(text.as_bytes()))
}

#[tokio::test]
async fn closed_registration_refuses_and_an_invitation_opens_invite_only() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    sqlx::query("UPDATE registration_configurations SET status = 1")
        .execute(&db.pool)
        .await
        .unwrap();
    let inviter = add_user(&db.pool, &unique("inviter"), true, 1).await;
    let name = unique("irene");
    let email = format!("{name}@example.com");
    let token = unique("tok");
    sqlx::query(
        "INSERT INTO invitations (token, email, invited_by, expires_at, is_active, created_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), 1, NOW())",
    )
    .bind(&token)
    .bind(&email)
    .bind(inviter)
    .execute(&db.pool)
    .await
    .unwrap();

    let mut browser = Browser::new(&ctx);
    let (_, html) = browser.get("/register").await;
    assert!(html.contains("invite only"));
    assert!(!html.contains("Create Account"));

    let (_, html) = browser.get(&format!("/register?invitecode={token}")).await;
    assert!(html.contains("Create Account"));
    assert!(html.contains(&email), "the invited address fills the form");
    assert!(
        html.contains(&format!("value=\"{token}\"")),
        "the form carries the code"
    );

    // No code: refused. With the code: created, and the invitation is spent.
    browser
        .post(
            "/register",
            &[
                ("username", &name),
                ("email", &email),
                ("password", PASSWORD),
                ("password_confirmation", PASSWORD),
            ],
        )
        .await;
    let count = || {
        sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM users WHERE email = ?")
            .bind(&email)
            .fetch_one(&db.pool)
    };
    assert_eq!(count().await.unwrap(), 0);
    browser
        .post(
            "/register",
            &[
                ("username", &name),
                ("email", &email),
                ("password", PASSWORD),
                ("password_confirmation", PASSWORD),
                ("invitecode", &token),
            ],
        )
        .await;
    assert_eq!(count().await.unwrap(), 1);
    let active: i8 = sqlx::query_scalar("SELECT is_active FROM invitations WHERE token = ?")
        .bind(&token)
        .fetch_one(&db.pool)
        .await
        .unwrap();
    assert_eq!(active, 0);

    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn closed_registration_refuses_even_an_invitation() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    sqlx::query("UPDATE registration_configurations SET status = 2")
        .execute(&db.pool)
        .await
        .unwrap();
    let inviter = add_user(&db.pool, &unique("inviter"), true, 1).await;
    let name = unique("kevin");
    let email = format!("{name}@example.com");
    let token = unique("tok");
    sqlx::query(
        "INSERT INTO invitations (token, email, invited_by, expires_at, is_active, created_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), 1, NOW())",
    )
    .bind(&token)
    .bind(&email)
    .bind(inviter)
    .execute(&db.pool)
    .await
    .unwrap();
    let mut browser = Browser::new(&ctx);
    let (_, html) = browser.get(&format!("/register?invitecode={token}")).await;
    assert!(html.contains("currently closed"));
    assert!(!html.contains("Create Account"));
    browser
        .post(
            "/register",
            &[
                ("username", &name),
                ("email", &email),
                ("password", PASSWORD),
                ("password_confirmation", PASSWORD),
                ("invitecode", &token),
            ],
        )
        .await;
    let count: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM users WHERE email = ?")
        .bind(&email)
        .fetch_one(&db.pool)
        .await
        .unwrap();
    assert_eq!(count, 0);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn password_reset_round_trip() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("judy");
    let email = format!("{name}@example.com");
    add_user(&db.pool, &name, true, 1).await;
    let mut browser = Browser::new(&ctx);

    // Same answer for an address nobody has.
    let (response, _) = browser
        .post("/forgottenpassword", &[("email", "nobody@example.com")])
        .await;
    assert_eq!(location(&response), "/forgottenpassword");
    let (_, html) = browser.get("/forgottenpassword").await;
    assert!(html.contains("Password reset email has been sent!"));
    assert!(take_mail(&ctx, "nobody@example.com").await.is_empty());

    browser
        .post("/forgottenpassword", &[("email", &email)])
        .await;
    let mail = take_mail(&ctx, &email).await;
    assert_eq!(mail.len(), 1);
    let link = link_in(
        &mail[0],
        &format!("{}/password/reset/", nntmux_webkit::config::app_url()),
    );
    let path = link
        .strip_prefix(&nntmux_webkit::config::app_url())
        .unwrap()
        .to_owned();
    let token = path
        .trim_start_matches("/password/reset/")
        .split('?')
        .next()
        .unwrap()
        .to_owned();

    let (response, html) = browser.get(&path).await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains(&email));

    let new_password = "Vq7!another-horse-battery-plugh";
    browser
        .post(
            "/password/update",
            &[
                ("token", "wrong"),
                ("email", &email),
                ("password", new_password),
                ("password_confirmation", new_password),
            ],
        )
        .await;
    let mut login = Browser::new(&ctx);
    login
        .post("/login", &[("username", &name), ("password", new_password)])
        .await;
    assert!(
        !browser_is_signed_in(&mut login).await,
        "a bad token changes nothing"
    );

    let (response, _) = browser
        .post(
            "/password/update",
            &[
                ("token", &token),
                ("email", &email),
                ("password", new_password),
                ("password_confirmation", new_password),
            ],
        )
        .await;
    assert_eq!(location(&response), "/login");
    let mut login = Browser::new(&ctx);
    login
        .post("/login", &[("username", &name), ("password", new_password)])
        .await;
    assert!(browser_is_signed_in(&mut login).await);

    // The token is single use.
    let (_, _) = browser
        .post(
            "/password/update",
            &[
                ("token", &token),
                ("email", &email),
                ("password", PASSWORD),
                ("password_confirmation", PASSWORD),
            ],
        )
        .await;
    let mut login = Browser::new(&ctx);
    login
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    assert!(!browser_is_signed_in(&mut login).await);

    db.drop_database().await.unwrap();
}
