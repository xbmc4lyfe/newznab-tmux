//! Anime lookups against a real database and Redis, with AniList mocked.

mod common;

use common::{Keys, harness, insert_release_in};
use nntmux_metadata::MetadataSettings;
use nntmux_metadata::anime::AnimeLookup;
use nntmux_metadata::anime::commands::{AnimeRefreshOptions, migrate_covers, refresh};
use nntmux_metadata::anime::title::extract_title;
use serde_json::{Value, json};
use sqlx::{MySqlPool, Row};
use wiremock::matchers::{method, path};
use wiremock::{Mock, ResponseTemplate};

#[test]
fn titles_match_the_php_reference() {
    let mut failures = Vec::new();
    for line in include_str!("fixtures/anime_titles.jsonl").lines() {
        let case: Value = serde_json::from_str(line).unwrap();
        let input = case["input"].as_str().unwrap();
        let got = extract_title(input);
        if got.as_deref() != case["title"].as_str() {
            failures.push(format!("{input}: php {}, rust {got:?}", case["title"]));
        }
    }
    assert!(failures.is_empty(), "{}", failures.join("\n"));
}

const GUID: &str = "a0000000000000000000000000000001";
const TV_ANIME: i64 = 5070;

fn media() -> Value {
    json!({
        "id": 777, "idMal": 55, "title": {"romaji": "Sousou no Frieren", "english": "Frieren", "native": "葬送のフリーレン"},
        "type": "ANIME", "format": "TV", "status": "FINISHED", "countryOfOrigin": "JP",
        "episodes": 28, "duration": 24, "source": "MANGA", "hashtag": "#frieren",
        "startDate": {"year": 2023, "month": 9, "day": 29}, "endDate": {"year": 2024, "month": null, "day": null},
        "description": "<i>An elf</i> &amp; friends", "averageScore": 91, "coverImage": {"large": null},
        "genres": ["Fantasy", "Drama"], "studios": {"nodes": [{"name": "Madhouse"}]},
        "characters": {"nodes": [{"name": {"full": "Frieren"}}]},
        "relations": {"edges": [{"relationType": "SEQUEL", "node": {"id": 2, "title": {"english": "Two"}}}]},
    })
}

async fn anidbid(db: &MySqlPool, id: i64) -> Option<i64> {
    sqlx::query_scalar("SELECT CAST(anidbid AS SIGNED) FROM releases WHERE id = ?")
        .bind(id)
        .fetch_one(db)
        .await
        .unwrap()
}

async fn mock_anilist(mock: &wiremock::MockServer) {
    Mock::given(method("POST"))
        .and(path("/graphql"))
        .respond_with(move |request: &wiremock::Request| {
            let body: Value = serde_json::from_slice(&request.body).unwrap();
            let data = if body["variables"]["id"].is_null() {
                if body["variables"]["search"] == "Sousou no Frieren" {
                    json!({"Page": {"media": [media()]}})
                } else {
                    json!({"Page": {"media": []}})
                }
            } else {
                json!({"Media": media()})
            };
            ResponseTemplate::new(200).set_body_json(json!({"data": data}))
        })
        .mount(mock)
        .await;
}

#[tokio::test]
async fn a_release_matches_through_the_api_and_a_later_one_locally() {
    let Some(h) = harness(Keys::default()).await else {
        return;
    };
    let db = &h.db.pool;
    mock_anilist(&h.mock).await;
    let found = insert_release_in(
        db,
        "[SubsPlease] Sousou no Frieren - 06 (1080p)",
        GUID,
        TV_ANIME,
    )
    .await;
    let unknown = insert_release_in(
        db,
        "[X] Nothing Like It - 01",
        "a0000000000000000000000000000002",
        TV_ANIME,
    )
    .await;
    let empty = insert_release_in(
        db,
        "[OnlyGroup]",
        "a0000000000000000000000000000003",
        TV_ANIME,
    )
    .await;
    let settings = MetadataSettings::default();
    AnimeLookup::new(h.services.clone())
        .process(&settings, 0, "a")
        .await
        .unwrap();
    assert_eq!(anidbid(db, found).await, Some(777));
    assert_eq!(anidbid(db, unknown).await, Some(-2));
    assert_eq!(anidbid(db, empty).await, Some(-1));

    let row = sqlx::query(
        "SELECT CAST(anilist_id AS SIGNED) AS anilist_id, type, description, categories, related, \
         CAST(startdate AS CHAR) AS startdate, enddate IS NULL AS no_end, rating FROM anidb_info WHERE anidbid = 777",
    )
    .fetch_one(db)
    .await
    .unwrap();
    assert_eq!(row.get::<i64, _>("anilist_id"), 777);
    assert_eq!(row.get::<String, _>("type"), "TV");
    assert_eq!(row.get::<String, _>("description"), "An elf & friends");
    assert_eq!(row.get::<String, _>("categories"), "Fantasy, Drama");
    assert_eq!(row.get::<String, _>("related"), "Two (2)");
    assert_eq!(row.get::<String, _>("startdate"), "2023-09-29");
    assert_eq!(row.get::<i32, _>("no_end"), 1);
    assert_eq!(row.get::<String, _>("rating"), "91");
    let titles: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM anidb_titles WHERE anidbid = 777")
        .fetch_one(db)
        .await
        .unwrap();
    assert_eq!(titles, 3);

    let local = insert_release_in(
        db,
        "[Y] Frieren - 02",
        "b0000000000000000000000000000001",
        TV_ANIME,
    )
    .await;
    AnimeLookup::new(h.services.clone())
        .process(&settings, 0, "b")
        .await
        .unwrap();
    assert_eq!(anidbid(db, local).await, Some(777));
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn an_update_keeps_what_anilist_left_empty() {
    let Some(h) = harness(Keys::default()).await else {
        return;
    };
    let db = &h.db.pool;
    nntmux_metadata::anime::store_info(&h.services, 9, &media())
        .await
        .unwrap();
    let sparse =
        json!({"id": 777, "description": "", "genres": [], "format": null, "type": "ANIME"});
    nntmux_metadata::anime::store_info(&h.services, 9, &sparse)
        .await
        .unwrap();
    let row = sqlx::query(
        "SELECT description, categories, type, status FROM anidb_info WHERE anidbid = 9",
    )
    .fetch_one(db)
    .await
    .unwrap();
    assert_eq!(row.get::<String, _>("description"), "An elf & friends");
    assert_eq!(row.get::<String, _>("categories"), "Fantasy, Drama");
    assert_eq!(
        row.get::<String, _>("type"),
        "ANIME",
        "the format falls back to the type"
    );
    assert_eq!(row.get::<String, _>("status"), "FINISHED");
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn a_rate_limit_leaves_releases_to_retry() {
    let Some(h) = harness(Keys::default()).await else {
        return;
    };
    let db = &h.db.pool;
    Mock::given(method("POST"))
        .respond_with(ResponseTemplate::new(429))
        .expect(1)
        .mount(&h.mock)
        .await;
    let first = insert_release_in(db, "[A] One - 01", GUID, TV_ANIME).await;
    let second = insert_release_in(
        db,
        "[A] Two - 01",
        "a0000000000000000000000000000002",
        TV_ANIME,
    )
    .await;
    let settings = MetadataSettings::default();
    let outcome = AnimeLookup::new(h.services.clone())
        .process(&settings, 0, "")
        .await;
    assert!(outcome.is_err());
    assert_eq!(anidbid(db, first).await, None);
    assert_eq!(anidbid(db, second).await, None);
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn a_rate_limit_stops_the_refresh() {
    let Some(h) = harness(Keys::default()).await else {
        return;
    };
    let db = &h.db.pool;
    Mock::given(method("POST"))
        .respond_with(ResponseTemplate::new(429))
        .expect(1)
        .mount(&h.mock)
        .await;
    insert_release_in(db, "[A] One - 01", GUID, TV_ANIME).await;
    insert_release_in(
        db,
        "[A] Two - 01",
        "a0000000000000000000000000000002",
        TV_ANIME,
    )
    .await;
    let options = AnimeRefreshOptions {
        limit: 0,
        missing_only: false,
        retry_failed: false,
        force: false,
    };
    let report = refresh(&h.services, &options).await.unwrap();
    assert!(report.rate_limited);
    assert_eq!(report.processed, 0);
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn refresh_skips_complete_rows_and_links_the_rest() {
    let Some(h) = harness(Keys::default()).await else {
        return;
    };
    let db = &h.db.pool;
    mock_anilist(&h.mock).await;
    let id = insert_release_in(db, "[A] Sousou no Frieren - 01", GUID, TV_ANIME).await;
    let options = AnimeRefreshOptions {
        limit: 0,
        missing_only: false,
        retry_failed: false,
        force: false,
    };
    let first = refresh(&h.services, &options).await.unwrap();
    assert_eq!(first.successful, 1);
    assert_eq!(anidbid(db, id).await, Some(777));
    let second = refresh(&h.services, &options).await.unwrap();
    assert_eq!(second.skipped, 1);
    h.db.drop_database().await.unwrap();
}

#[test]
fn covers_migrate_once() {
    let root = tempfile::TempDir::new().unwrap();
    let anime = root.path().join("anime");
    std::fs::create_dir_all(&anime).unwrap();
    for name in ["1.jpg", "2.jpg", "2-cover.jpg", "x.jpg", "3.webp"] {
        std::fs::write(anime.join(name), b"x").unwrap();
    }
    assert_eq!(migrate_covers(root.path(), true).unwrap(), (1, 1));
    assert!(anime.join("1.jpg").exists());
    assert_eq!(migrate_covers(root.path(), false).unwrap(), (1, 1));
    assert!(anime.join("1-cover.jpg").exists());
    assert!(anime.join("x.jpg").exists());
    assert!(migrate_covers(&root.path().join("none"), false).is_err());
}
