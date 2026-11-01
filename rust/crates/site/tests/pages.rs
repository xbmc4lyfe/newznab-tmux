//! Public pages against real MariaDB and Redis. Skips itself when the services aren't set.

mod common;

use axum::http::{StatusCode, header};
use common::*;
use serde_json::Value;

#[tokio::test]
async fn home_needs_a_login_and_shows_index_content() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    sqlx::query(
        "INSERT INTO content (title, body, metadescription, metakeywords, contenttype, status, role, ordinal)
         VALUES ('Welcome', '&lt;b&gt;Hello there&lt;/b&gt;', 'front desc', 'front,keys', 3, 1, 0, 0),
                ('Staff notes', 'staff body', 'staff desc', 'k', 2, 1, 2, 5),
                ('Staff home', 'staff home body', 'd', 'k', 3, 1, 2, 7),
                ('Open notes', 'open body', 'open desc', 'k', 2, 1, 0, 6)",
    )
    .execute(&db.pool)
    .await
    .unwrap();
    sqlx::query("DELETE FROM content WHERE title NOT IN ('Welcome','Staff notes','Staff home','Open notes')")
        .execute(&db.pool)
        .await
        .unwrap();
    let staff: i64 =
        sqlx::query_scalar("SELECT CAST(id AS SIGNED) FROM content WHERE title = 'Staff notes'")
            .fetch_one(&db.pool)
            .await
            .unwrap();
    let mut browser = Browser::new(&ctx);
    let (response, _) = browser.get("/").await;
    assert_eq!(location(&response), "/login");

    let name = unique("laura");
    add_user(&db.pool, &name, true, 1).await;
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    let (response, html) = browser.get("/").await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(
        html.contains("<b>Hello there</b>"),
        "admin-written HTML renders"
    );
    assert!(html.contains(&name), "the header greets the account");
    assert!(
        !html.contains("staff home body"),
        "staff-only home rows stay hidden"
    );
    assert!(html.contains("<title>Welcome - "));
    assert!(html.contains(r#"content="front,keys""#));

    // Role rules: a staff page is hidden from an ordinary account.
    let (response, _) = browser
        .get(&format!("/content?page=content&id={staff}"))
        .await;
    assert_eq!(response.status(), StatusCode::NOT_FOUND);
    let (response, html) = browser.get("/content?page=content").await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains("Open notes"));
    assert!(
        !html.contains("Staff notes"),
        "the list hides staff pages too"
    );

    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn legal_pages_are_public() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let mut browser = Browser::new(&ctx);
    for path in [
        "/terms-and-conditions",
        "/privacy-policy",
        "/contact-us",
        "/status",
    ] {
        let (response, html) = browser.get(path).await;
        assert_eq!(response.status(), StatusCode::OK, "{path}");
        assert!(html.contains("<html"), "{path}");
    }
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn contact_validates_and_queues_mail_with_a_reply_address() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let mut browser = Browser::new(&ctx);
    let (response, _) = browser
        .post(
            "/contact-us",
            &[
                ("username", "me"),
                ("useremail", "nope"),
                ("comment", "short"),
            ],
        )
        .await;
    assert_eq!(location(&response), "/contact-us");
    let (_, html) = browser.get("/contact-us").await;
    assert!(html.contains("Please enter a valid email address."));
    assert!(html.contains(r#"value="me""#), "what was typed comes back");

    let (response, html) = browser
        .post(
            "/contact-us",
            &[
                ("username", "me"),
                ("useremail", "me@example.com"),
                ("comment", "A long enough message"),
            ],
        )
        .await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains("Thank you for getting in touch"));
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn status_shows_public_services_and_hides_internal_incidents() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    sqlx::query("DELETE FROM service_statuses")
        .execute(&db.pool)
        .await
        .unwrap();
    sqlx::query(
        "INSERT INTO service_statuses (id, name, slug, check_type, status, uptime_percentage, is_enabled, sort_order)
         VALUES (1, 'Website', 'web', 'http', 'degraded', 99.50, 1, 1),
                (2, 'Probe', 'probe', 'internal', 'major_outage', 50.00, 1, 2)",
    )
    .execute(&db.pool)
    .await
    .unwrap();
    sqlx::query(
        "INSERT INTO service_incidents (id, title, description, status, impact, started_at)
         VALUES (1, 'Public trouble', 'seen', 'investigating', 'major', NOW()),
                (2, 'Internal trouble', 'hidden', 'investigating', 'major', NOW())",
    )
    .execute(&db.pool)
    .await
    .unwrap();
    sqlx::query("INSERT INTO service_incident_service_status (service_incident_id, service_status_id) VALUES (1, 1), (2, 2)")
        .execute(&db.pool)
        .await
        .unwrap();
    let mut browser = Browser::new(&ctx);
    let (_, html) = browser.get("/status").await;
    assert!(html.contains("Website") && html.contains("99.50"));
    assert!(!html.contains("Probe"), "internal services stay private");
    assert!(html.contains("Public trouble"));
    assert!(!html.contains("Internal trouble"));
    assert!(
        html.contains("Degraded"),
        "overall reflects public services only"
    );
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn covers_refuse_bad_names() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let mut browser = Browser::new(&ctx);
    let (response, _) = browser.get("/covers/nothing/1.jpg").await;
    assert_eq!(response.status(), StatusCode::NOT_FOUND);
    let (response, _) = browser.get("/covers/movies/..%2Fsecret.jpg").await;
    assert_eq!(response.status(), StatusCode::NOT_FOUND);
    assert!(
        !response.headers().contains_key(header::SET_COOKIE),
        "covers don't start sessions"
    );
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn release_reports_are_once_per_user() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("mona");
    add_user(&db.pool, &name, true, 1).await;
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    let (_, body) = browser.get("/release-report/reasons").await;
    let reasons: Value = serde_json::from_str(&body).unwrap();
    assert_eq!(reasons["reasons"]["wrong_category"], "Wrong Category");

    let (response, _) = browser
        .post_authed(
            "/release-report",
            &[("release_id", "9999"), ("reason", "spam")],
        )
        .await;
    assert_eq!(response.status(), StatusCode::NOT_FOUND);
    let (response, _) = browser
        .post_authed("/release-report", &[("reason", "spam")])
        .await;
    assert_eq!(response.status(), StatusCode::UNPROCESSABLE_ENTITY);

    let release = sqlx::query("INSERT INTO releases (guid, leftguid) VALUES (UUID(), 'a')")
        .execute(&db.pool)
        .await
        .unwrap();
    let id = release.last_insert_id().to_string();
    let (response, _) = browser
        .post_authed(
            "/release-report",
            &[("release_id", &id), ("reason", "spam")],
        )
        .await;
    assert_eq!(response.status(), StatusCode::OK);
    let (response, _) = browser
        .post_authed(
            "/release-report",
            &[("release_id", &id), ("reason", "spam")],
        )
        .await;
    assert_eq!(response.status(), StatusCode::CONFLICT);
    let (_, body) = browser
        .get(&format!("/release-report/check?release_id={id}"))
        .await;
    assert!(body.contains("true"));
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn invitations_follow_the_registration_mode() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("nora");
    let id = add_user(&db.pool, &name, true, 1).await;
    sqlx::query("UPDATE users SET invites = 1 WHERE id = ?")
        .bind(id)
        .execute(&db.pool)
        .await
        .unwrap();
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;

    // Registration is open by default, so invitations are off.
    let (_, html) = browser.get("/invitations").await;
    assert!(html.contains("Invitations disabled"));
    sqlx::query("UPDATE registration_configurations SET status = 1")
        .execute(&db.pool)
        .await
        .unwrap();

    let email = format!("{}@example.com", unique("friend"));
    let (response, _) = browser
        .post_authed(
            "/invitations/store",
            &[("email", &email), ("expiry_days", "3")],
        )
        .await;
    assert_eq!(location(&response), "/invitations");
    let mail = take_mail(&ctx, &email).await;
    assert_eq!(mail.len(), 1);
    let (_, html) = browser.get("/invitations?status=pending").await;
    assert!(html.contains(&email));

    // The one invite is in use, so another is refused.
    let other = format!("{}@example.com", unique("friend"));
    browser
        .post_authed("/invitations/store", &[("email", &other)])
        .await;
    assert!(take_mail(&ctx, &other).await.is_empty());

    let (invitation, token): (i64, String) =
        sqlx::query_as("SELECT CAST(id AS SIGNED), token FROM invitations WHERE email = ?")
            .bind(&email)
            .fetch_one(&db.pool)
            .await
            .unwrap();
    let (response, html) = browser.get(&format!("/invitation/{token}")).await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(
        html.contains(&email) && html.contains(&name),
        "the inviter is named"
    );

    // Someone else's invitation is off limits.
    let mut stranger = Browser::new(&ctx);
    let stranger_name = unique("oscar");
    add_user(&db.pool, &stranger_name, true, 1).await;
    stranger
        .post(
            "/login",
            &[("username", &stranger_name), ("password", PASSWORD)],
        )
        .await;
    let (response, _) = stranger
        .post_authed(&format!("/invitations/{invitation}/resend"), &[])
        .await;
    assert_eq!(response.status(), StatusCode::FORBIDDEN);

    let (response, _) = browser
        .post_authed(&format!("/invitations/{invitation}/resend"), &[])
        .await;
    assert_eq!(location(&response), "/invitations");
    assert_eq!(take_mail(&ctx, &email).await.len(), 1);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn profile_settings_save_and_guard_the_email_and_password() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("petra");
    let id = add_user(&db.pool, &name, true, 1).await;
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;

    let (response, html) = browser.get("/profile").await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(
        html.contains(&format!("token-{name}")),
        "your own API token shows"
    );
    let (response, html) = browser.get("/profileedit").await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains("Europe/Berlin"));

    // Wrong current password changes nothing.
    let (_, html) = browser
        .post_authed(
            "/profileedit",
            &[
                ("action", "submit"),
                ("current_password", "nope"),
                ("password", "Abcdef1!x"),
                ("password_confirmation", "Abcdef1!x"),
            ],
        )
        .await;
    assert!(html.contains("The password is incorrect."));

    let category: i64 = sqlx::query_scalar("SELECT CAST(id AS SIGNED) FROM categories WHERE root_categories_id IS NOT NULL ORDER BY id LIMIT 1")
        .fetch_one(&db.pool)
        .await
        .unwrap();
    let (response, _) = browser
        .post_authed(
            "/profileedit",
            &[
                ("action", "submit"),
                ("movieview", "1"),
                ("theme_preference", "dark"),
                ("color_scheme", "teal"),
                ("timezone", "Europe/Berlin"),
                ("viewmovies", "1"),
                ("excluded_categories[]", &category.to_string()),
            ],
        )
        .await;
    assert_eq!(location(&response), "/profile");
    let (movieview, musicview, theme, scheme, zone): (i32, i32, String, String, Option<String>) =
        sqlx::query_as("SELECT movieview, musicview, theme_preference, color_scheme, timezone FROM users WHERE id = ?")
            .bind(id)
            .fetch_one(&db.pool)
            .await
            .unwrap();
    assert_eq!((movieview, musicview), (1, 0));
    assert_eq!(
        (theme.as_str(), scheme.as_str(), zone.as_deref()),
        ("dark", "teal", Some("Europe/Berlin"))
    );
    let access = nntmux_webkit::user::Access::load(&db.pool, id)
        .await
        .unwrap();
    assert!(access.has_direct_permission("view movies"));
    assert!(!access.has_direct_permission("view tv"));
    let excluded: i64 =
        sqlx::query_scalar("SELECT COUNT(*) FROM user_excluded_categories WHERE users_id = ?")
            .bind(id)
            .fetch_one(&db.pool)
            .await
            .unwrap();
    assert_eq!(excluded, 1);

    // A new email unverifies the account and signs it out.
    let new_email = format!("{}@example.com", unique("moved"));
    let (response, _) = browser
        .post_authed(
            "/profileedit",
            &[("action", "submit"), ("email", &new_email)],
        )
        .await;
    assert_eq!(location(&response), "/login");
    assert_eq!(take_mail(&ctx, &new_email).await.len(), 1);
    assert!(!browser_is_signed_in(&mut browser).await);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn two_factor_setup_round_trip_from_settings() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("quinn");
    let id = add_user(&db.pool, &name, true, 1).await;
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;

    let (response, _) = browser
        .post_authed("/generate2faSecret", &[("from_profile", "1")])
        .await;
    assert_eq!(location(&response), "/profileedit#security");
    let (_, html) = browser.get("/profileedit").await;
    assert!(html.contains("<svg"), "the QR code renders");
    let secret: String =
        sqlx::query_scalar("SELECT google2fa_secret FROM password_securities WHERE user_id = ?")
            .bind(id)
            .fetch_one(&db.pool)
            .await
            .unwrap();

    browser
        .post_authed("/profileedit/enable2fa", &[("verify-code", "000000")])
        .await;
    let enabled = |pool: sqlx::MySqlPool| async move {
        sqlx::query_scalar::<_, i8>(
            "SELECT google2fa_enable FROM password_securities WHERE user_id = ?",
        )
        .bind(id)
        .fetch_one(&pool)
        .await
        .unwrap()
    };
    assert_eq!(enabled(db.pool.clone()).await, 0);
    let now = chrono::Utc::now().timestamp() as u64;
    let code = totp_rs::Builder::new()
        .with_algorithm(totp_rs::Algorithm::SHA1)
        .with_digits(6)
        .with_skew(1)
        .with_step_duration(30)
        .with_secret(totp_rs::Secret::try_from_base32(&secret).unwrap())
        .build_noncompliant()
        .generate(now)
        .to_string();
    browser
        .post_authed("/profileedit/enable2fa", &[("verify-code", &code)])
        .await;
    assert_eq!(enabled(db.pool.clone()).await, 1);

    // A live secret can't be answered here, which would dodge the challenge's throttle.
    auth_passed_not_marked(&mut browser).await;

    // Disabling needs the password, then removes the row.
    browser
        .post_authed("/profileedit/disable2fa", &[("current-password", "wrong")])
        .await;
    assert_eq!(enabled(db.pool.clone()).await, 1);
    browser
        .post_authed("/profileedit/disable2fa", &[("current-password", PASSWORD)])
        .await;
    let rows: i64 =
        sqlx::query_scalar("SELECT COUNT(*) FROM password_securities WHERE user_id = ?")
            .bind(id)
            .fetch_one(&db.pool)
            .await
            .unwrap();
    assert_eq!(rows, 0);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn the_theme_endpoint_validates() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("rosa");
    add_user(&db.pool, &name, true, 1).await;
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    let (response, _) = browser
        .post_authed("/profile/update-theme", &[("theme_preference", "neon")])
        .await;
    assert_eq!(response.status(), StatusCode::UNPROCESSABLE_ENTITY);
    let (response, body) = browser
        .post_authed("/profile/update-theme", &[("theme_preference", "dark")])
        .await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(body.contains(r#""theme_preference":"dark""#));
    db.drop_database().await.unwrap();
}

async fn auth_passed_not_marked(browser: &mut Browser) {
    let (response, _) = browser
        .post_authed("/2fa", &[("verify-code", "000000")])
        .await;
    assert_eq!(location(&response), "/profileedit#security");
    let (_, html) = browser.get("/profileedit").await;
    assert!(html.contains("Invalid Verification Code"));
}

#[tokio::test]
async fn profile_privacy_follows_the_viewer_not_the_viewed() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let moderator = unique("sam");
    let id = add_user(&db.pool, &moderator, true, 1).await;
    sqlx::query("INSERT INTO model_has_roles (role_id, model_type, model_id) SELECT id, ?, ? FROM roles WHERE name = 'Moderator'")
        .bind(nntmux_webkit::user::USER_MODEL)
        .bind(id)
        .execute(&db.pool)
        .await
        .unwrap();
    let admin = unique("tina");
    let admin_id = add_user(&db.pool, &admin, true, 1).await;
    sqlx::query("INSERT INTO model_has_roles (role_id, model_type, model_id) SELECT id, ?, ? FROM roles WHERE name = 'Admin'")
        .bind(nntmux_webkit::user::USER_MODEL)
        .bind(admin_id)
        .execute(&db.pool)
        .await
        .unwrap();
    let mut browser = Browser::new(&ctx);
    browser
        .post(
            "/login",
            &[("username", &moderator), ("password", PASSWORD)],
        )
        .await;
    for path in [
        format!("/profile?id={admin_id}"),
        format!("/profile?name={admin}"),
    ] {
        let (response, html) = browser.get(&path).await;
        assert_eq!(response.status(), StatusCode::OK);
        assert!(html.contains(&admin));
        assert!(
            !html.contains(&format!("token-{admin}")),
            "{path} must not show the token"
        );
        assert!(
            !html.contains(&format!("{admin}@example.com")),
            "{path} must not show the email"
        );
    }
    let mut owner = Browser::new(&ctx);
    owner
        .post("/login", &[("username", &admin), ("password", PASSWORD)])
        .await;
    let (_, html) = owner.get("/profile").await;
    assert!(html.contains(&format!("token-{admin}")));
    db.drop_database().await.unwrap();
}
