//! Movie lookups against a real database and Redis, with the provider APIs mocked.

mod common;

use common::{Harness, Keys, harness, insert_release_in};
use nntmux_metadata::LookupMode;
use nntmux_metadata::MetadataSettings;
use nntmux_metadata::movies::commands::{MovieReverifyOptions, reverify_matches};
use nntmux_metadata::movies::{MovieLookup, parse_movie_name};
use serde_json::{Value, json};
use sqlx::{MySqlPool, Row};
use wiremock::matchers::{method, path, query_param};
use wiremock::{Mock, MockServer, ResponseTemplate};

#[test]
fn name_parser_matches_the_php_reference() {
    let mut failures = Vec::new();
    for line in include_str!("fixtures/movie_parse.jsonl").lines() {
        let case: Value = serde_json::from_str(line).unwrap();
        let input = case["input"].as_str().unwrap();
        let want = case["title"]
            .as_str()
            .map(|title| (title.to_owned(), case["year"].as_str().unwrap().to_owned()));
        let got = parse_movie_name(input);
        if got != want {
            failures.push(format!("{input}: php {want:?}, rust {got:?}"));
        }
    }
    assert!(failures.is_empty(), "{}", failures.join("\n"));
}

const GUID: &str = "a0000000000000000000000000000001";

async fn release_imdb(db: &MySqlPool, id: i64) -> (Option<String>, Option<i64>) {
    let row = sqlx::query(
        "SELECT imdbid, CAST(movieinfo_id AS SIGNED) AS movie FROM releases WHERE id = ?",
    )
    .bind(id)
    .fetch_one(db)
    .await
    .unwrap();
    (row.get("imdbid"), row.get("movie"))
}

fn lookup(h: &Harness) -> MovieLookup {
    MovieLookup::new(h.services.clone(), MetadataSettings::default())
}

async fn mock_tmdb_movie(mock: &MockServer) {
    Mock::given(path("/find/tt0133093"))
        .respond_with(
            ResponseTemplate::new(200).set_body_json(json!({"movie_results": [{"id": 603}]})),
        )
        .mount(mock)
        .await;
    Mock::given(path("/movie/603"))
        .and(query_param("append_to_response", "credits"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "id": 603, "title": "The Matrix", "imdb_id": "tt0133093", "release_date": "1999-03-30",
            "overview": "A hacker.", "tagline": "Welcome.", "vote_average": 8.7,
            "credits": {
                "cast": [{"name": "Keanu Reeves"}, {"name": "Laurence Fishburne"}],
                "crew": [{"department": "Directing", "job": "Director", "name": "Lana Wachowski"}],
            },
            "genres": [{"name": "Action"}, {"name": "Sci-Fi"}],
            "poster_path": "/p.jpg", "backdrop_path": "/b.jpg",
        })))
        .mount(mock)
        .await;
}

#[tokio::test]
async fn tmdb_search_finds_a_movie_and_stores_it() {
    let Some(h) = harness(Keys {
        tmdb: "k",
        ..Keys::default()
    })
    .await
    else {
        return;
    };
    let id = insert_release_in(&h.db.pool, "The.Matrix.1999.1080p.BluRay", GUID, 2040).await;
    Mock::given(path("/search/movie"))
        .and(query_param("query", "The Matrix"))
        .and(query_param("year", "1999"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "total_results": 2,
            "results": [
                {"id": 1, "release_date": "2003-01-01"},
                {"id": 603, "release_date": "1999-03-30"},
            ],
        })))
        .mount(&h.mock)
        .await;
    Mock::given(path("/movie/603"))
        .and(query_param("append_to_response", "credits"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "id": 603, "title": "The Matrix", "imdb_id": "tt0133093", "release_date": "1999-03-30",
            "overview": "A hacker.", "vote_average": 8.7, "genres": [{"name": "Action"}],
        })))
        .mount(&h.mock)
        .await;
    mock_tmdb_movie(&h.mock).await;

    lookup(&h).process(0, "a", LookupMode::All).await.unwrap();

    let (imdb, movie) = release_imdb(&h.db.pool, id).await;
    assert_eq!(imdb.as_deref(), Some("0133093"));
    let row = sqlx::query(
        "SELECT CAST(id AS SIGNED) AS id, title, year, rating, plot, genre, director, actors, \
         CAST(tmdbid AS SIGNED) AS tmdbid, rtrating FROM movieinfo WHERE imdbid = '0133093'",
    )
    .fetch_one(&h.db.pool)
    .await
    .unwrap();
    assert_eq!(movie, Some(row.get::<i64, _>("id")));
    assert_eq!(row.get::<String, _>("title"), "The Matrix");
    assert_eq!(row.get::<String, _>("year"), "1999");
    assert_eq!(
        row.get::<String, _>("rating"),
        "8",
        "(int) truncates the rating, as in PHP"
    );
    assert_eq!(row.get::<String, _>("genre"), "Action");
    assert_eq!(row.get::<i64, _>("tmdbid"), 603);
    assert_eq!(row.get::<String, _>("rtrating"), "N/A");
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn a_miss_marks_the_release() {
    let Some(h) = harness(Keys::default()).await else {
        return;
    };
    let miss = insert_release_in(&h.db.pool, "Nothing.Known.2011.BluRay", GUID, 2040).await;
    lookup(&h).process(0, "a", LookupMode::All).await.unwrap();
    assert_eq!(release_imdb(&h.db.pool, miss).await.0.as_deref(), Some(""));
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn the_local_table_is_checked_first_and_a_conflicting_year_is_rejected() {
    let Some(h) = harness(Keys::default()).await else {
        return;
    };
    let db = &h.db.pool;
    sqlx::query(
        "INSERT INTO movieinfo (imdbid, title, year, updated_at) VALUES \
         ('0111111', 'Moana', '2016', NOW()), ('0222222', 'Heat', '1995', NOW())",
    )
    .execute(db)
    .await
    .unwrap();
    let moana = insert_release_in(db, "Moana.2026.1080p.WEB-DL", GUID, 2040).await;
    let heat = insert_release_in(
        db,
        "Heat.1995.1080p.BluRay",
        "a0000000000000000000000000000002",
        2040,
    )
    .await;
    lookup(&h).process(0, "a", LookupMode::All).await.unwrap();
    assert_eq!(
        release_imdb(db, moana).await.0.as_deref(),
        Some(""),
        "ten years off is another movie"
    );
    let (imdb, movie) = release_imdb(db, heat).await;
    assert_eq!(imdb.as_deref(), Some("0222222"));
    assert!(movie.is_some());
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn imdb_search_scrapes_the_title_and_saves_nothing_for_a_blocked_page() {
    let Some(h) = harness(Keys {
        imdb: true,
        ..Keys::default()
    })
    .await
    else {
        return;
    };
    let db = &h.db.pool;
    Mock::given(path("/suggestion/f/fight_club.json"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({"d": [
            {"id": "tt0137523", "l": "Fight Club", "y": 1999},
        ]})))
        .mount(&h.mock)
        .await;
    let page = r#"<html><script type="application/ld+json">{"@type":"Movie","name":"Fight Club",
        "datePublished":"1999-10-15","description":"An insomniac.","aggregateRating":{"ratingValue":8.8},
        "genre":["Drama"],"director":[{"name":"David Fincher"}],"actor":[{"name":"Brad Pitt"}]}</script></html>"#;
    Mock::given(path("/title/tt0137523/"))
        .respond_with(ResponseTemplate::new(200).set_body_string(page))
        .mount(&h.mock)
        .await;
    let id = insert_release_in(db, "Fight.Club.1999.1080p.BluRay", GUID, 2040).await;
    lookup(&h).process(0, "a", LookupMode::All).await.unwrap();
    assert_eq!(release_imdb(db, id).await.0.as_deref(), Some("0137523"));
    let row = sqlx::query("SELECT director, actors, type FROM movieinfo WHERE imdbid = '0137523'")
        .fetch_one(db)
        .await
        .unwrap();
    assert_eq!(row.get::<String, _>("director"), "David Fincher");
    assert_eq!(row.get::<String, _>("actors"), "Brad Pitt");
    assert_eq!(row.get::<String, _>("type"), "Movie");

    Mock::given(path("/suggestion/h/heat.json"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({"d": [
            {"id": "tt0113277", "l": "Heat", "y": 1995},
        ]})))
        .mount(&h.mock)
        .await;
    Mock::given(path("/title/tt0113277/"))
        .respond_with(ResponseTemplate::new(202).set_body_string("challenge.js"))
        .expect(1)
        .mount(&h.mock)
        .await;
    let blocked = insert_release_in(
        db,
        "Heat.1995.1080p.BluRay",
        "b0000000000000000000000000000002",
        2040,
    )
    .await;
    lookup(&h).process(0, "b", LookupMode::All).await.unwrap();
    assert_eq!(
        release_imdb(db, blocked).await.0.as_deref(),
        Some("0113277"),
        "kept as PHP does"
    );
    let fetched = h.services.imdb.fetch_by_id("0099685").await;
    assert!(fetched.skipped, "the back-off stops further requests");
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn omdb_fills_a_movie_with_its_covers() {
    let Some(h) = harness(Keys {
        omdb: "k",
        ..Keys::default()
    })
    .await
    else {
        return;
    };
    let db = &h.db.pool;
    let mut png = Vec::new();
    image::DynamicImage::new_rgb8(30, 40)
        .write_to(&mut std::io::Cursor::new(&mut png), image::ImageFormat::Png)
        .unwrap();
    Mock::given(path("/poster.png"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(png))
        .mount(&h.mock)
        .await;
    Mock::given(method("GET"))
        .and(query_param("s", "se7en"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "Response": "True",
            "Search": [{"imdbID": "tt0114369", "Title": "Se7en", "Year": "1995"}],
        })))
        .mount(&h.mock)
        .await;
    Mock::given(method("GET"))
        .and(query_param("i", "tt0114369"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "Response": "True", "Title": "Se7en", "Year": "1995", "Plot": "Two detectives.",
            "Poster": format!("{}/poster.png", h.mock.uri()), "Genre": "Crime, Drama",
            "imdbRating": "8.6", "Director": "David Fincher", "Actors": "Brad Pitt",
            "Language": "English",
            "Ratings": [{"Source": "IMDb", "Value": "8.6/10"}, {"Source": "RT", "Value": "83%"}],
        })))
        .mount(&h.mock)
        .await;
    let id = insert_release_in(db, "Se7en.1995.1080p.BluRay", GUID, 2040).await;
    lookup(&h).process(0, "a", LookupMode::All).await.unwrap();
    assert_eq!(release_imdb(db, id).await.0.as_deref(), Some("0114369"));
    let row = sqlx::query(
        "SELECT genre, rtrating, cover, language FROM movieinfo WHERE imdbid = '0114369'",
    )
    .fetch_one(db)
    .await
    .unwrap();
    assert_eq!(row.get::<String, _>("genre"), "Crime, Drama");
    assert_eq!(row.get::<String, _>("rtrating"), "83%");
    assert_eq!(row.get::<i8, _>("cover"), 1);
    assert!(h.covers.path().join("movies/0114369-cover.webp").is_file());
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn reverify_resets_mismatches_unless_dry() {
    let Some(h) = harness(Keys::default()).await else {
        return;
    };
    let db = &h.db.pool;
    sqlx::query(
        "INSERT INTO movieinfo (imdbid, title, year) VALUES ('0111111', 'Moana 2', '2024')",
    )
    .execute(db)
    .await
    .unwrap();
    let wrong = insert_release_in(db, "Moana.2026.1080p.WEB-DL", GUID, 2040).await;
    let unknown = insert_release_in(
        db,
        "Other.2020.1080p",
        "a0000000000000000000000000000002",
        2040,
    )
    .await;
    for id in [wrong, unknown] {
        let imdb = if id == wrong { "0111111" } else { "0999999" };
        sqlx::query("UPDATE releases SET imdbid = ? WHERE id = ?")
            .bind(imdb)
            .bind(id)
            .execute(db)
            .await
            .unwrap();
    }
    let dry = MovieReverifyOptions {
        dry_run: true,
        limit: None,
    };
    let report = reverify_matches(h.services.clone(), &dry).await.unwrap();
    assert_eq!(report.checked, 2);
    assert_eq!(report.mismatched, [wrong]);
    assert_eq!(release_imdb(db, wrong).await.0.as_deref(), Some("0111111"));
    let run = MovieReverifyOptions::default();
    reverify_matches(h.services.clone(), &run).await.unwrap();
    assert_eq!(release_imdb(db, wrong).await.0, None);
    assert_eq!(
        release_imdb(db, unknown).await.0.as_deref(),
        Some("0999999")
    );
    h.db.drop_database().await.unwrap();
}
