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
    sqlx::query("INSERT INTO release_nfos (releases_id, nfo) VALUES (?, COMPRESS(?))")
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

#[tokio::test]
async fn details_show_metadata_and_take_comments() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("zane");
    add_user(&db.pool, &name, true, 1).await;
    let group = add_group(&db.pool, &unique("alt.binaries.details")).await;
    let (release, guid) = add_release(&db.pool, "Details.Movie.2024.1080p.WEB", 2040, group).await;
    sqlx::query("INSERT INTO movieinfo (imdbid, title, year, genre, actors, plot, tagline, rating, type, director, language, cover, backdrop) VALUES ('1234567', 'Details Movie', '2024', 'Drama, War', 'Ann Lee, Bob Ray', 'A plot.', '', '7.1', '', '', '', 0, 0)")
        .execute(&db.pool)
        .await
        .unwrap();
    sqlx::query("UPDATE releases SET imdbid = '1234567', passwordstatus = 1 WHERE id = ?")
        .bind(release)
        .execute(&db.pool)
        .await
        .unwrap();
    sqlx::query("INSERT INTO video_data (releases_id, videowidth, videoheight, videocodec, videoduration) VALUES (?, 1920, 1080, 'h264', '5400000')")
        .bind(release)
        .execute(&db.pool)
        .await
        .unwrap();
    sqlx::query("INSERT INTO release_subtitles (releases_id, subsid, subslanguage) VALUES (?, 1, 'en'), (?, 2, 'de')")
        .bind(release)
        .bind(release)
        .execute(&db.pool)
        .await
        .unwrap();
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;

    let (response, html) = browser.get(&format!("/details/{guid}")).await;
    assert_eq!(response.status(), StatusCode::OK, "{html}");
    assert!(html.contains("Details Movie"));
    assert!(html.contains("/Movies?actors=Ann+Lee"));
    assert!(html.contains("1920x1080"));
    assert!(html.contains("90 min"));
    assert!(html.contains("en, de"));
    assert!(html.contains("Password Protected Release"));

    // Comments are escaped and counted.
    let (response, _) = browser
        .post_authed(
            &format!("/details/{guid}"),
            &[("txtAddComment", "<script>x</script> good")],
        )
        .await;
    assert_eq!(location(&response), format!("/details/{guid}"));
    let (_, html) = browser.get(&format!("/details/{guid}")).await;
    assert!(!html.contains("<script>x</script>"));
    assert!(html.contains("good"));
    let comments: i64 =
        sqlx::query_scalar("SELECT CAST(comments AS SIGNED) FROM releases WHERE id = ?")
            .bind(release)
            .fetch_one(&db.pool)
            .await
            .unwrap();
    assert_eq!(comments, 1);

    // A guid nobody has goes back rather than erroring.
    let (response, _) = browser.get("/details/missing").await;
    assert_eq!(response.status(), StatusCode::SEE_OTHER);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn failure_reports_need_a_token_or_a_browser_session() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("abel");
    let id = add_user(&db.pool, &name, true, 1).await;
    let group = add_group(&db.pool, &unique("alt.binaries.failed")).await;
    let (release, guid) = add_release(&db.pool, "Failed.Release", 2040, group).await;
    let mut browser = Browser::new(&ctx);

    let (response, _) = browser.get(&format!("/failed?guid={guid}")).await;
    assert_eq!(response.status(), StatusCode::METHOD_NOT_ALLOWED);
    let (response, _) = browser
        .get(&format!("/failed?guid={guid}&api_token=wrong"))
        .await;
    assert_eq!(response.status(), StatusCode::UNAUTHORIZED);
    assert_eq!(response.headers()["x-dnzb-rcode"], "401");
    let (response, _) = browser.get("/failed?api_token=wrong").await;
    assert_eq!(response.status(), StatusCode::BAD_REQUEST);

    // A real token records the failure, even with no alternate to offer.
    let (response, _) = browser
        .get(&format!("/failed?guid={guid}&api_token=token-{name}"))
        .await;
    assert_eq!(response.status(), StatusCode::NOT_FOUND);
    let failures: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM dnzb_failures WHERE release_id = ? AND users_id = ?",
    )
    .bind(release)
    .bind(id)
    .fetch_one(&db.pool)
    .await
    .unwrap();
    assert_eq!(failures, 1);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn browser_failure_reports_need_a_session_and_token() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let group = add_group(&db.pool, &unique("alt.binaries.post")).await;
    let (release, guid) = add_release(&db.pool, "Post.Release", 2040, group).await;
    let name = unique("beth");
    add_user(&db.pool, &name, true, 1).await;
    let mut browser = Browser::new(&ctx);

    // A guest with a valid token is still nobody.
    let (response, _) = browser.post("/failed", &[("guid", &guid)]).await;
    assert_eq!(response.status(), StatusCode::UNAUTHORIZED);

    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    let request = axum::http::Request::post("/failed").header(
        axum::http::header::CONTENT_TYPE,
        "application/x-www-form-urlencoded",
    );
    let (response, _) = browser
        .send(request, format!("guid={guid}&_token=wrong"))
        .await;
    assert_eq!(response.status().as_u16(), 419);
    let (response, _) = browser.post_authed("/failed", &[("guid", &guid)]).await;
    assert_eq!(
        response.status(),
        StatusCode::NOT_FOUND,
        "signed in, with no alternate to offer"
    );
    let failures: i64 =
        sqlx::query_scalar("SELECT COUNT(*) FROM dnzb_failures WHERE release_id = ?")
            .bind(release)
            .fetch_one(&db.pool)
            .await
            .unwrap();
    assert_eq!(failures, 1);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn cover_pages_group_releases_and_filter() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("cora");
    let id = add_user(&db.pool, &name, true, 1).await;
    for permission in [
        "view movies",
        "view audio",
        "view console",
        "view pc",
        "view books",
        "view adult",
    ] {
        grant(&db.pool, id, permission).await;
    }
    let group = add_group(&db.pool, &unique("alt.binaries.covers")).await;
    let (movie_release, movie_guid) = add_release(&db.pool, "Cover.Movie.2024", 2040, group).await;
    sqlx::query("INSERT INTO movieinfo (imdbid, title, year, genre, actors, plot, tagline, rating, type, director, language, cover, backdrop, trailer) VALUES ('7654321', 'Cover Movie', '2024', 'Drama', 'Ann Lee', 'A plot.', '', '7.5', '', 'Bob Ray', '', 1, 0, 'javascript:alert(1)')")
        .execute(&db.pool)
        .await
        .unwrap();
    sqlx::query("UPDATE releases SET imdbid = '7654321' WHERE id = ?")
        .bind(movie_release)
        .execute(&db.pool)
        .await
        .unwrap();
    let (music_release, _) = add_release(&db.pool, "Cover.Album.FLAC", 3040, group).await;
    let music = sqlx::query("INSERT INTO musicinfo (title, artist, publisher, year, cover, asin, url, salesrank, review, tracks) VALUES ('An Album', 'An Artist', 'Label', '2020', 1, '', '', 0, '', '')")
        .execute(&db.pool)
        .await
        .unwrap()
        .last_insert_id();
    sqlx::query("UPDATE releases SET musicinfo_id = ? WHERE id = ?")
        .bind(music)
        .bind(music_release)
        .execute(&db.pool)
        .await
        .unwrap();
    sqlx::query(
        "INSERT INTO user_downloads (users_id, timestamp, releases_id) VALUES (?, NOW(), ?)",
    )
    .bind(id)
    .bind(movie_release)
    .execute(&db.pool)
    .await
    .unwrap();

    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;

    let (response, html) = browser.get("/Movies").await;
    assert_eq!(response.status(), StatusCode::OK, "{html}");
    assert!(html.contains("Cover Movie") && html.contains(&movie_guid));
    assert!(html.contains("/Movies?actors=Ann+Lee"));
    let (_, html) = browser.get("/Movies?title=nothing-like-this").await;
    assert!(!html.contains("Cover Movie"));
    assert!(html.contains("No results found"));
    let (_, html) = browser.get("/Movies?ob=rating_desc&year=1;DROP").await;
    assert!(html.contains("Cover Movie"), "a bad year is ignored");

    let (response, html) = browser.get("/movie/7654321").await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains(&movie_guid));
    assert!(
        !html.contains("javascript:alert"),
        "only web trailers reach an iframe"
    );
    let (response, _) = browser.get("/movie/0000000").await;
    assert_eq!(location(&response), "/Movies");

    let (_, html) = browser.get("/Audio").await;
    assert!(html.contains("An Album") && html.contains("An Artist"));
    for path in ["/Console", "/Games", "/Books", "/XXX"] {
        let (response, html) = browser.get(path).await;
        assert_eq!(response.status(), StatusCode::OK, "{path}: {html}");
    }
    let (_, html) = browser.get("/trending-movies").await;
    assert!(html.contains("Cover Movie"));
    assert!(html.contains("1 downloads"));

    let (response, _) = browser.get("/movietrailers?id=abc").await;
    assert_eq!(response.status(), StatusCode::BAD_REQUEST);
    let (response, _) = browser
        .post_authed("/movies/update-layout", &[("layout", "9")])
        .await;
    assert_eq!(response.status(), StatusCode::UNPROCESSABLE_ENTITY);
    let (response, _) = browser
        .post_authed("/movies/update-layout", &[("layout", "1")])
        .await;
    assert_eq!(response.status(), StatusCode::OK);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn cover_pages_need_the_category_permission() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("dora");
    let id = add_user(&db.pool, &name, true, 1).await;
    sqlx::query("DELETE FROM model_has_permissions WHERE model_id = ?")
        .bind(id)
        .execute(&db.pool)
        .await
        .unwrap();
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    for path in [
        "/Movies",
        "/movie/1",
        "/trending-movies",
        "/Audio",
        "/Console",
        "/Games",
        "/Books",
        "/XXX",
    ] {
        let (response, _) = browser.get(path).await;
        assert_eq!(response.status(), StatusCode::FORBIDDEN, "{path}");
    }
    db.drop_database().await.unwrap();
}

async fn page(browser: &mut Browser, path: &str) -> (StatusCode, String) {
    let (response, html) = browser.get(path).await;
    (response.status(), html)
}

async fn act(browser: &mut Browser, path: &str, fields: &[(&str, &str)]) -> (StatusCode, String) {
    let (response, _) = browser.post_authed(path, fields).await;
    (response.status(), location(&response))
}

async fn add_show(db: &sqlx::MySqlPool, title: &str) -> i64 {
    let video = sqlx::query("INSERT INTO videos (type, title, countries_id, started, source) VALUES (0, ?, 'US', NOW(), 0)")
        .bind(title)
        .execute(db)
        .await
        .unwrap()
        .last_insert_id() as i64;
    sqlx::query(
        "INSERT INTO tv_info (videos_id, summary, publisher) VALUES (?, 'A summary', 'A network')",
    )
    .bind(video)
    .execute(db)
    .await
    .unwrap();
    video
}

async fn add_episode(db: &sqlx::MySqlPool, video: i64, release: i64, season: i64, episode: i64) {
    let id = sqlx::query("INSERT INTO tv_episodes (videos_id, series, episode, se_complete, title, firstaired, summary) VALUES (?, ?, ?, ?, 'An episode', '2020-01-02', '')")
        .bind(video)
        .bind(season)
        .bind(episode)
        .bind(format!("S{season:02}E{episode:02}"))
        .execute(db)
        .await
        .unwrap()
        .last_insert_id() as i64;
    sqlx::query("UPDATE releases SET videos_id = ?, tv_episodes_id = ? WHERE id = ?")
        .bind(video)
        .bind(id)
        .bind(release)
        .execute(db)
        .await
        .unwrap();
}

#[tokio::test]
async fn series_pages_group_by_season_and_lists_follow_shows() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("tess");
    let id = add_user(&db.pool, &name, true, 1).await;
    grant(&db.pool, id, "view tv").await;
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    let group = add_group(&db.pool, &unique("alt.binaries.tv")).await;
    let title = unique("Zed Show");
    let video = add_show(&db.pool, &title).await;
    let (first, _) = add_release(&db.pool, "Zed.S01E01", 5040, group).await;
    let (second, _) = add_release(&db.pool, "Zed.S02E01", 5040, group).await;
    add_episode(&db.pool, video, first, 1, 1).await;
    add_episode(&db.pool, video, second, 2, 1).await;
    let (named, _) = add_release(&db.pool, "Zed.S03E04", 5040, group).await;
    sqlx::query("UPDATE releases SET videos_id = ? WHERE id = ?")
        .bind(video)
        .bind(named)
        .execute(&db.pool)
        .await
        .unwrap();

    let (status, body) = page(&mut browser, "/series/Z").await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains(&title));

    let (status, body) = page(&mut browser, &format!("/series/{video}")).await;
    assert_eq!(status, StatusCode::OK);
    assert!(
        body.contains("Season 1 (1)")
            && body.contains("Season 2 (1)")
            && body.contains("Season 3 (1)")
    );
    assert!(body.contains("Zed.S01E01") && !body.contains("Zed.S02E01"));
    let (_, body) = page(
        &mut browser,
        &format!("/series/{video}?season=2&_fragment=season"),
    )
    .await;
    let json: serde_json::Value = serde_json::from_str(&body).unwrap();
    assert_eq!(json["selectedSeason"], 2);
    assert!(json["contentHtml"].as_str().unwrap().contains("Zed.S02E01"));

    let (status, body) = page(&mut browser, &format!("/myshows?action=add&id={video}")).await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("category[]"));
    let (status, _) = page(&mut browser, &format!("/myshows?action=doadd&id={video}")).await;
    assert_eq!(status, StatusCode::METHOD_NOT_ALLOWED);
    let (status, target) = act(
        &mut browser,
        &format!("/myshows?action=doadd&id={video}"),
        &[("category[]", "5040"), ("from", "//evil.example")],
    )
    .await;
    assert_eq!(status, StatusCode::SEE_OTHER);
    assert_eq!(target, "/myshows");
    let stored: String = sqlx::query_scalar(
        "SELECT categories FROM user_series WHERE users_id = ? AND videos_id = ?",
    )
    .bind(id)
    .bind(video)
    .fetch_one(&db.pool)
    .await
    .unwrap();
    assert_eq!(stored, "5040");
    let (_, body) = page(&mut browser, "/myshows").await;
    assert!(body.contains(&title));
    let (status, body) = page(&mut browser, "/myshows/browse").await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("Zed.S01E01"));
    let (status, _) = act(
        &mut browser,
        &format!("/myshows?action=delete&id={video}"),
        &[],
    )
    .await;
    assert_eq!(status, StatusCode::SEE_OTHER);
    let (_, body) = page(&mut browser, "/myshows/browse").await;
    assert!(!body.contains("Zed.S01E01"));

    let (status, _) = page(&mut browser, "/trending-tv").await;
    assert_eq!(status, StatusCode::OK);
}

#[tokio::test]
async fn my_movies_add_edit_and_browse() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("mina");
    let id = add_user(&db.pool, &name, true, 1).await;
    grant(&db.pool, id, "view movies").await;
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    let group = add_group(&db.pool, &unique("alt.binaries.mov")).await;
    let imdb = "0123456";
    sqlx::query("INSERT INTO movieinfo (imdbid, title) VALUES (?, 'Listed Movie')")
        .bind(imdb)
        .execute(&db.pool)
        .await
        .unwrap();
    let (release, _) = add_release(&db.pool, "Listed.Movie.2020", 2040, group).await;
    sqlx::query("UPDATE releases SET imdbid = ? WHERE id = ?")
        .bind(imdb)
        .bind(release)
        .execute(&db.pool)
        .await
        .unwrap();

    let (status, body) = page(&mut browser, &format!("/mymovies?id=add&imdb={imdb}")).await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("Listed Movie"));
    let (status, _) = act(
        &mut browser,
        &format!("/mymovies?id=doadd&imdb={imdb}"),
        &[("category[]", "2040")],
    )
    .await;
    assert_eq!(status, StatusCode::SEE_OTHER);
    let (_, body) = page(&mut browser, "/mymovies").await;
    assert!(body.contains("Listed Movie"));
    let (status, body) = page(&mut browser, "/mymovies?id=browse").await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("Listed.Movie.2020"));
    let (status, _) = act(
        &mut browser,
        &format!("/mymovies?id=doedit&imdb={imdb}"),
        &[("category[]", "2030")],
    )
    .await;
    assert_eq!(status, StatusCode::SEE_OTHER);
    let (_, body) = page(&mut browser, "/mymovies?id=browse").await;
    assert!(!body.contains("Listed.Movie.2020"));
}

#[tokio::test]
async fn search_runs_exact_poster_filters_and_reports_bad_input() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("sage");
    let id = add_user(&db.pool, &name, true, 1).await;
    grant(&db.pool, id, "view tv").await;
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;
    let group = add_group(&db.pool, &unique("alt.binaries.find")).await;
    add_release(&db.pool, "Found.Me", 5040, group).await;

    let (status, body) = page(&mut browser, "/search").await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("Start Your Search"));
    let (_, body) = page(&mut browser, "/search?poster=poster%40example.com").await;
    assert!(body.contains("Found.Me"));
    let (_, body) = page(&mut browser, "/search?poster=POSTER%40example.com").await;
    assert!(!body.contains("Found.Me") && body.contains("No results found"));
    let (_, body) = page(
        &mut browser,
        "/search?poster=poster%40example.com&minfiles=99",
    )
    .await;
    assert!(!body.contains("Found.Me"));
    let (_, body) = page(&mut browser, "/search?minfiles=x").await;
    assert!(body.contains("must be a whole number"));
}
