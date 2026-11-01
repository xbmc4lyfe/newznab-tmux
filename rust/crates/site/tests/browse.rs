//! Browse, basket and NFO pages against real MariaDB and Redis. Skips itself when the services
//! aren't set.

mod common;

use axum::http::StatusCode;
use common::*;
use nntmux_webkit::user::USER_MODEL;

async fn grant(db: &sqlx::MySqlPool, user: i64, permission: &str) {
    sqlx::query("INSERT INTO model_has_permissions (permission_id, model_type, model_id) SELECT id, ?, ? FROM permissions WHERE name = ?")
        .bind(USER_MODEL)
        .bind(user)
        .bind(permission)
        .execute(db)
        .await
        .unwrap();
}

async fn add_release(db: &sqlx::MySqlPool, name: &str, category: i64, group: i64) -> (i64, String) {
    let guid = unique(name);
    let id = sqlx::query(
        "INSERT INTO releases (name, searchname, guid, leftguid, categories_id, groups_id, size, totalpart, postdate, adddate, fromname, passwordstatus)
         VALUES (?, ?, ?, 'a', ?, ?, 2147483648, 3, NOW(), NOW(), 'poster@example.com', 0)",
    )
    .bind(name)
    .bind(name)
    .bind(&guid)
    .bind(category)
    .bind(group)
    .execute(db)
    .await
    .unwrap()
    .last_insert_id() as i64;
    (id, guid)
}

async fn add_group(db: &sqlx::MySqlPool, name: &str) -> i64 {
    sqlx::query("INSERT INTO usenet_groups (name, active, backfill, description) VALUES (?, 1, 0, 'A test group')")
        .bind(name)
        .execute(db)
        .await
        .unwrap()
        .last_insert_id() as i64
}

#[tokio::test]
async fn browse_lists_filters_and_guards_categories() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("uma");
    let id = add_user(&db.pool, &name, true, 1).await;
    sqlx::query("DELETE FROM model_has_permissions WHERE model_id = ?")
        .bind(id)
        .execute(&db.pool)
        .await
        .unwrap();
    grant(&db.pool, id, "view movies").await;
    let group = add_group(&db.pool, &unique("alt.binaries.test")).await;
    let (movie, movie_guid) = add_release(&db.pool, "Movie.Release.2024", 2040, group).await;
    let (_, tv_guid) = add_release(&db.pool, "Tv.Release.S01E01", 5040, group).await;
    sqlx::query("INSERT INTO release_reports (releases_id, users_id, reason, description, status) VALUES (?, ?, 'spam', 'junk', 'pending')")
        .bind(movie)
        .bind(id)
        .execute(&db.pool)
        .await
        .unwrap();

    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;

    // Everything visible: the movie, not the excluded TV root.
    let (response, html) = browser.get("/browse/All").await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains(&movie_guid));
    assert!(!html.contains(&tv_guid), "no view tv permission");
    assert!(html.contains("2.00 GB"));
    assert!(html.contains("Reported (1)"));
    assert!(html.contains("Spam/Advertisement"));

    // A category without the permission gets the refusal page.
    let (response, html) = browser.get("/browse/TV").await;
    assert_eq!(response.status(), StatusCode::FORBIDDEN);
    assert!(html.contains("Category Disabled"));
    let (response, html) = browser.get("/browse/Movies").await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains(&movie_guid));

    // Sort links are real, junk orders fall back, and the query can't reach SQL.
    let (response, _) = browser.get("/browse/All?ob=1;DROP").await;
    assert_eq!(response.status(), StatusCode::OK);
    let (response, html) = browser
        .get("/browse/All?ob=size_asc&page=9999999999999")
        .await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains("No releases found"));

    // A subcategory the account excluded is closed.
    sqlx::query("INSERT INTO user_excluded_categories (users_id, categories_id) VALUES (?, 2040)")
        .bind(id)
        .execute(&db.pool)
        .await
        .unwrap();
    let title: String = sqlx::query_scalar("SELECT title FROM categories WHERE id = 2040")
        .fetch_one(&db.pool)
        .await
        .unwrap();
    let (response, _) = browser.get(&format!("/browse/Movies/{title}")).await;
    assert_eq!(response.status(), StatusCode::FORBIDDEN);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn the_group_pages_list_and_filter() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("vera");
    let id = add_user(&db.pool, &name, true, 1).await;
    grant(&db.pool, id, "view movies").await;
    let group_name = unique("alt.binaries.group");
    let group = add_group(&db.pool, &group_name).await;
    let (_, guid) = add_release(&db.pool, "Grouped.Release", 2040, group).await;
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;

    let (_, html) = browser.get("/browsegroup").await;
    assert!(html.contains(&group_name.replace("alt.binaries", "a.b")));
    let (_, html) = browser.get("/browsegroup?search=nothing-matches").await;
    assert!(html.contains("nothing-matches"));
    assert!(!html.contains(&group_name.replace("alt.binaries", "a.b")));
    let (_, html) = browser.get(&format!("/browse/group?g={group_name}")).await;
    assert!(html.contains(&guid));
    let (response, _) = browser.get("/browse/group").await;
    assert_eq!(response.status(), StatusCode::SEE_OTHER);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn the_basket_adds_once_and_removes_only_your_own() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("wren");
    let id = add_user(&db.pool, &name, true, 1).await;
    let group = add_group(&db.pool, &unique("alt.binaries.cart")).await;
    let (_, guid) = add_release(&db.pool, "Basket.Release", 2040, group).await;
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;

    let (response, body) = browser.post_authed("/cart/add", &[("id", &guid)]).await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(body.contains("1 item(s) added to cart"));
    let (_, body) = browser.post_authed("/cart/add", &[("id", &guid)]).await;
    assert!(body.contains("Items already in cart"));
    let (response, _) = browser.post_authed("/cart/add", &[("id", "missing")]).await;
    assert_eq!(response.status(), StatusCode::NOT_FOUND);

    let (_, html) = browser.get("/cart/index").await;
    assert!(html.contains("Basket.Release"));

    let other = unique("xena");
    add_user(&db.pool, &other, true, 1).await;
    let mut stranger = Browser::new(&ctx);
    stranger
        .post("/login", &[("username", &other), ("password", PASSWORD)])
        .await;
    stranger
        .post_authed(&format!("/cart/delete/{guid}"), &[])
        .await;
    let count = || {
        sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM users_releases WHERE users_id = ?")
            .bind(id)
            .fetch_one(&db.pool)
    };
    assert_eq!(
        count().await.unwrap(),
        1,
        "someone else's delete does nothing"
    );
    browser
        .post_authed(&format!("/cart/delete/{guid}"), &[])
        .await;
    assert_eq!(count().await.unwrap(), 0);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn nfo_pages_convert_cp437() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("yara");
    add_user(&db.pool, &name, true, 1).await;
    let group = add_group(&db.pool, &unique("alt.binaries.nfo")).await;
    let (release, guid) = add_release(&db.pool, "Nfo.Release", 2040, group).await;
    sqlx::query("INSERT INTO release_nfos (releases_id, nfo) VALUES (?, ?)")
        .bind(release)
        .bind(vec![0x41u8, 0xDB])
        .execute(&db.pool)
        .await
        .unwrap();
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    let (_, html) = browser.get(&format!("/nfo/{guid}")).await;
    assert!(html.contains("A█"));
    let (_, html) = browser.get(&format!("/nfo/{guid}?modal=1")).await;
    assert!(html.starts_with("<pre"));
    let (response, _) = browser.get("/nfo/missing").await;
    assert_eq!(response.status(), StatusCode::NOT_FOUND);
    db.drop_database().await.unwrap();
}
