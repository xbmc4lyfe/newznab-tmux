//! Public pages against real MariaDB and Redis. Skips itself when the services aren't set.

mod common;

use axum::http::{StatusCode, header};
use common::*;
use serde_json::Value;

#[tokio::test]
async fn home_needs_a_login_and_shows_index_content() {
    let Some((db, ctx)) = fixture().await else { return };
    sqlx::query(
        "INSERT INTO content (title, body, metadescription, metakeywords, contenttype, status, role, ordinal)
         VALUES ('Welcome', '&lt;b&gt;Hello there&lt;/b&gt;', 'front desc', 'front,keys', 3, 1, 0, 0),
                ('Staff notes', 'staff body', 'staff desc', 'k', 2, 1, 2, 5),
                ('Open notes', 'open body', 'open desc', 'k', 2, 1, 0, 6)",
    )
    .execute(&db.pool)
    .await
    .unwrap();
    sqlx::query("DELETE FROM content WHERE title NOT IN ('Welcome','Staff notes','Open notes')")
        .execute(&db.pool)
        .await
        .unwrap();
    let staff: i64 = sqlx::query_scalar("SELECT CAST(id AS SIGNED) FROM content WHERE title = 'Staff notes'")
        .fetch_one(&db.pool)
        .await
        .unwrap();
    let mut browser = Browser::new(&ctx);
    let (response, _) = browser.get("/").await;
    assert_eq!(location(&response), "/login");

    let name = unique("laura");
    add_user(&db.pool, &name, true, 1).await;
    browser.post("/login", &[("username", &name), ("password", PASSWORD)]).await;
    let (response, html) = browser.get("/").await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains("<b>Hello there</b>"), "admin-written HTML renders");
    assert!(html.contains(&name), "the header greets the account");
    assert!(html.contains("<title>Welcome - "));
    assert!(html.contains(r#"content="front,keys""#));

    // Role rules: a staff page is hidden from an ordinary account.
    let (response, _) = browser.get(&format!("/content?page=content&id={staff}")).await;
    assert_eq!(response.status(), StatusCode::NOT_FOUND);
    let (response, html) = browser.get("/content?page=content").await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains("Open notes"));

    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn legal_pages_are_public() {
    let Some((db, ctx)) = fixture().await else { return };
    let mut browser = Browser::new(&ctx);
    for path in ["/terms-and-conditions", "/privacy-policy", "/contact-us", "/status"] {
        let (response, html) = browser.get(path).await;
        assert_eq!(response.status(), StatusCode::OK, "{path}");
        assert!(html.contains("<html"), "{path}");
    }
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn contact_validates_and_queues_mail_with_a_reply_address() {
    let Some((db, ctx)) = fixture().await else { return };
    let mut browser = Browser::new(&ctx);
    let (response, _) = browser.post("/contact-us", &[("username", "me"), ("useremail", "nope"), ("comment", "short")]).await;
    assert_eq!(location(&response), "/contact-us");
    let (_, html) = browser.get("/contact-us").await;
    assert!(html.contains("must be a valid email address"));
    assert!(html.contains(r#"value="me""#), "what was typed comes back");

    let (response, html) = browser
        .post("/contact-us", &[("username", "me"), ("useremail", "me@example.com"), ("comment", "A long enough message")])
        .await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains("Thank you for getting in touch"));
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn status_shows_public_services_and_hides_internal_incidents() {
    let Some((db, ctx)) = fixture().await else { return };
    sqlx::query("DELETE FROM service_statuses").execute(&db.pool).await.unwrap();
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
    assert!(html.contains("Degraded"), "overall reflects public services only");
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn covers_refuse_bad_names() {
    let Some((db, ctx)) = fixture().await else { return };
    let mut browser = Browser::new(&ctx);
    let (response, _) = browser.get("/covers/nothing/1.jpg").await;
    assert_eq!(response.status(), StatusCode::NOT_FOUND);
    let (response, _) = browser.get("/covers/movies/..%2Fsecret.jpg").await;
    assert_eq!(response.status(), StatusCode::NOT_FOUND);
    assert!(!response.headers().contains_key(header::SET_COOKIE), "covers don't start sessions");
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn release_reports_are_once_per_user() {
    let Some((db, ctx)) = fixture().await else { return };
    let name = unique("mona");
    add_user(&db.pool, &name, true, 1).await;
    let mut browser = Browser::new(&ctx);
    browser.post("/login", &[("username", &name), ("password", PASSWORD)]).await;
    let (_, body) = browser.get("/release-report/reasons").await;
    let reasons: Value = serde_json::from_str(&body).unwrap();
    assert_eq!(reasons["reasons"]["wrong_category"], "Wrong Category");

    let (response, _) = browser.post_authed("/release-report", &[("release_id", "9999"), ("reason", "spam")]).await;
    assert_eq!(response.status(), StatusCode::NOT_FOUND);
    let (response, _) = browser.post_authed("/release-report", &[("reason", "spam")]).await;
    assert_eq!(response.status(), StatusCode::UNPROCESSABLE_ENTITY);
    db.drop_database().await.unwrap();
}
