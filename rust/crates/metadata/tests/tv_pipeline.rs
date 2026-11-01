//! The TV pipeline against a real database and Redis, with the provider APIs mocked.

use std::sync::Arc;

use nntmux_metadata::cache::Cache;
use nntmux_metadata::candidates::tv_status;
use nntmux_metadata::clients::fanart::Fanart;
use nntmux_metadata::clients::tmdb::Tmdb;
use nntmux_metadata::clients::trakt::{EpisodeIds, Trakt};
use nntmux_metadata::clients::tvdb::Tvdb;
use nntmux_metadata::clients::tvmaze::TvMaze;
use nntmux_metadata::images::Images;
use nntmux_metadata::services::Services;
use nntmux_metadata::tv::commands::{TvRepairLinksOptions, repair_links};
use nntmux_metadata::tv::providers::{TvMazeProvider, TvProvider, TvdbProvider};
use nntmux_metadata::tv::store::{Show, add_show};
use nntmux_metadata::tv::{Candidate, Outcome, Pipeline, RunState};
use nntmux_metadata::{LookupMode, MetadataSettings, Renamed};
use nntmux_testkit::TestDb;
use reqwest::Client;
use serde_json::json;
use sqlx::{MySqlPool, Row};
use tempfile::TempDir;
use wiremock::matchers::{header, method, path, query_param};
use wiremock::{Mock, MockServer, ResponseTemplate};

struct Harness {
    db: TestDb,
    services: Arc<Services>,
    mock: MockServer,
    covers: TempDir,
}

async fn harness(tvdb_key: &str) -> Option<Harness> {
    let db = TestDb::create().await.unwrap()?;
    let Ok(redis_url) = std::env::var("TEST_REDIS_URL") else {
        return None;
    };
    let client = redis::Client::open(redis_url).unwrap();
    let redis = redis::aio::ConnectionManager::new(client).await.unwrap();
    let cache = Cache::with_prefix(redis, &format!("{}:", db.name));
    let mock = MockServer::start().await;
    let http = Client::new();
    let covers = TempDir::new().unwrap();
    let mut images = Images::from_env().unwrap();
    images.covers = covers.path().to_owned();
    images.allow_private_hosts = true;
    let mut tmdb = Tmdb::new(http.clone(), String::new(), 5, 1, 1);
    tmdb.base_url = mock.uri();
    let mut tvdb = Tvdb::new(http.clone(), tvdb_key.to_owned(), None, 5);
    tvdb.base_url = mock.uri();
    let mut tvmaze = TvMaze::new(http.clone(), 5, 1);
    tvmaze.base_url = mock.uri();
    tvmaze.min_interval = std::time::Duration::ZERO;
    let mut trakt = Trakt::new(http.clone(), cache.clone(), String::new(), 5, 1, 1);
    trakt.base_url = mock.uri();
    trakt.throttled = false;
    let mut fanart = Fanart::new(http.clone(), cache.clone(), String::new(), 5, 1, 1);
    fanart.base_url = mock.uri();
    let services = Arc::new(Services {
        db: db.pool.clone(),
        cache,
        search: None,
        images,
        http,
        tmdb,
        tvdb,
        tvmaze,
        trakt,
        fanart,
    });
    Some(Harness {
        db,
        services,
        mock,
        covers,
    })
}

async fn insert_release(db: &MySqlPool, name: &str, guid: &str) -> i64 {
    let done = sqlx::query(
        "INSERT INTO releases (name, searchname, guid, leftguid, fromname, size, postdate, adddate, \
         categories_id, groups_id, totalpart) \
         VALUES (?, ?, ?, ?, 'poster', 5000000, NOW(), NOW(), 5030, 1, 1)",
    )
    .bind(name)
    .bind(name)
    .bind(guid)
    .bind(&guid[..1])
    .execute(db)
    .await
    .unwrap();
    done.last_insert_id() as i64
}

async fn release_state(db: &MySqlPool, id: i64) -> (i64, i64, i64, bool) {
    let row = sqlx::query(
        "SELECT CAST(videos_id AS SIGNED) AS v, CAST(tv_episodes_id AS SIGNED) AS e, \
         CAST(tv_lookup_attempts AS SIGNED) AS a, tv_lookup_retry_at IS NOT NULL AS r \
         FROM releases WHERE id = ?",
    )
    .bind(id)
    .fetch_one(db)
    .await
    .unwrap();
    (
        row.get("v"),
        row.get("e"),
        row.get("a"),
        row.get::<i32, _>("r") == 1,
    )
}

fn candidate(id: i64, name: &str) -> Candidate {
    Candidate {
        id,
        searchname: name.to_owned(),
        videos_id: 0,
    }
}

fn maze_show() -> serde_json::Value {
    json!({
        "id": 82, "name": "Game of Thrones", "premiered": "2011-04-17",
        "summary": "<p>Seven kingdoms.</p>",
        "network": {"name": "HBO", "country": {"code": "US", "timezone": "America/New_York"}},
        "webChannel": null,
        "externals": {"tvrage": 24493, "thetvdb": 121361, "imdb": "tt0944947"},
        "image": {"original": null, "medium": null},
        "_embedded": {"akas": [{"name": "Juego de Tronos"}]},
    })
}

fn maze_only(services: &Arc<Services>) -> Vec<Box<dyn TvProvider>> {
    vec![Box::new(TvMazeProvider(services.clone()))]
}

#[tokio::test]
async fn a_new_show_is_stored_and_its_episode_matched() {
    let Some(h) = harness("").await else { return };
    let name = "Game.of.Thrones.S01E02.720p.HDTV";
    let id = insert_release(&h.db.pool, name, "a0000000000000000000000000000001").await;
    Mock::given(path("/singlesearch/shows"))
        .and(query_param("q", "Game of Thrones"))
        .respond_with(ResponseTemplate::new(200).set_body_json(maze_show()))
        .mount(&h.mock)
        .await;
    Mock::given(path("/shows/82/episodes"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!([
            {"season": 1, "number": 1, "name": "Winter", "airdate": "2011-04-17", "summary": "<p>A</p>"},
            {"season": 1, "number": 2, "name": "Kingsroad", "airdate": "2011-04-24", "summary": "<p>B</p>"},
            {"season": 0, "number": null, "name": "Special", "airdate": "2011-01-01", "summary": ""},
        ])))
        .expect(1)
        .mount(&h.mock)
        .await;

    let pipeline = Pipeline::with_providers(h.services.clone(), maze_only(&h.services));
    let outcome = pipeline
        .process_release(&candidate(id, name), &mut RunState::default())
        .await
        .unwrap();
    let Outcome::Matched {
        video_id,
        episode_id,
        provider,
    } = outcome
    else {
        panic!("expected a match, got {outcome:?}");
    };
    assert_eq!(provider, "TVMaze");
    let (videos_id, episodes_id, attempts, retry) = release_state(&h.db.pool, id).await;
    assert_eq!(
        (videos_id, episodes_id, attempts, retry),
        (video_id, episode_id, 0, false)
    );

    let show = sqlx::query(
        "SELECT title, countries_id, source + 0 AS source, CAST(tvmaze AS SIGNED) AS tvmaze, \
         CAST(tvdb AS SIGNED) AS tvdb, CAST(tvrage AS SIGNED) AS tvrage, imdb FROM videos WHERE id = ?",
    )
    .bind(video_id)
    .fetch_one(&h.db.pool)
    .await
    .unwrap();
    assert_eq!(show.get::<String, _>("title"), "Game of Thrones");
    assert_eq!(show.get::<String, _>("countries_id"), "US");
    assert_eq!(show.get::<i64, _>("tvmaze"), 82);
    assert_eq!(show.get::<i64, _>("tvdb"), 121361);
    assert_eq!(show.get::<i64, _>("tvrage"), 24493);
    assert_eq!(show.get::<String, _>("imdb"), "0944947");

    let info = sqlx::query("SELECT summary, publisher, localzone FROM tv_info WHERE videos_id = ?")
        .bind(video_id)
        .fetch_one(&h.db.pool)
        .await
        .unwrap();
    assert_eq!(info.get::<String, _>("summary"), "Seven kingdoms.");
    assert_eq!(info.get::<String, _>("publisher"), "HBO");
    assert_eq!(info.get::<String, _>("localzone"), "America/New_York");

    let episodes: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM tv_episodes WHERE videos_id = ?")
        .bind(video_id)
        .fetch_one(&h.db.pool)
        .await
        .unwrap();
    assert_eq!(
        episodes, 3,
        "the unnumbered special is kept, keyed by airdate"
    );
    let alias: String = sqlx::query_scalar("SELECT title FROM videos_aliases WHERE videos_id = ?")
        .bind(video_id)
        .fetch_one(&h.db.pool)
        .await
        .unwrap();
    assert_eq!(alias, "Juego de Tronos");

    // A second release of the show resolves locally, without touching the API again.
    let next = "Game.of.Thrones.S01E01.1080p.WEB";
    let next_id = insert_release(&h.db.pool, next, "a0000000000000000000000000000002").await;
    let outcome = pipeline
        .process_release(&candidate(next_id, next), &mut RunState::default())
        .await
        .unwrap();
    assert!(
        matches!(&outcome, Outcome::Matched { provider, .. } if provider == "Local DB"),
        "{outcome:?}"
    );
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn rate_limits_defer_and_trip_the_circuit_breaker() {
    let Some(h) = harness("").await else { return };
    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(429).insert_header("Retry-After", "7200"))
        .expect(3)
        .mount(&h.mock)
        .await;
    let pipeline = Pipeline::with_providers(h.services.clone(), maze_only(&h.services));
    let mut state = RunState::default();
    for number in 0..4 {
        let name = format!("Unknown.Show.{number}.S01E02.WEB");
        let guid = format!("b000000000000000000000000000000{number}");
        let id = insert_release(&h.db.pool, &name, &guid).await;
        let outcome = pipeline
            .process_release(&candidate(id, &name), &mut state)
            .await
            .unwrap();
        assert!(matches!(outcome, Outcome::Transient { .. }), "{outcome:?}");
        let (videos_id, episode, attempts, retry) = release_state(&h.db.pool, id).await;
        assert_eq!((videos_id, episode, attempts, retry), (0, 0, 1, true));
        if number == 0 {
            let seconds: i64 = sqlx::query_scalar(
                "SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), tv_lookup_retry_at) FROM releases WHERE id = ?",
            )
            .bind(id)
            .fetch_one(&h.db.pool)
            .await
            .unwrap();
            assert!(
                (7000..=7200).contains(&seconds),
                "Retry-After wins: {seconds}"
            );
        }
    }
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn a_rename_during_processing_is_not_overwritten() {
    let Some(h) = harness("").await else { return };
    let name = "Show.Gone.S01E02.WEB";
    let id = insert_release(&h.db.pool, name, "c0000000000000000000000000000001").await;
    sqlx::query("UPDATE releases SET searchname = 'Renamed' WHERE id = ?")
        .bind(id)
        .execute(&h.db.pool)
        .await
        .unwrap();
    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(404))
        .mount(&h.mock)
        .await;
    let pipeline = Pipeline::with_providers(h.services.clone(), maze_only(&h.services));
    let outcome = pipeline
        .process_release(&candidate(id, name), &mut RunState::default())
        .await
        .unwrap();
    assert_eq!(outcome, Outcome::NotFound);
    assert_eq!(release_state(&h.db.pool, id).await, (0, 0, 0, false));
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn parse_failures_and_misses_get_their_statuses() {
    let Some(h) = harness("").await else { return };
    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(404))
        .mount(&h.mock)
        .await;
    let pipeline = Pipeline::with_providers(h.services.clone(), maze_only(&h.services));
    let bad = "Not a tv show at all";
    let bad_id = insert_release(&h.db.pool, bad, "d0000000000000000000000000000001").await;
    let miss = "Nothing.Like.It.S01E02.WEB";
    let miss_id = insert_release(&h.db.pool, miss, "d0000000000000000000000000000002").await;
    let mut state = RunState::default();
    pipeline
        .process_release(&candidate(bad_id, bad), &mut state)
        .await
        .unwrap();
    pipeline
        .process_release(&candidate(miss_id, miss), &mut state)
        .await
        .unwrap();
    assert_eq!(
        release_state(&h.db.pool, bad_id).await.1,
        tv_status::PARSE_FAILED
    );
    assert_eq!(
        release_state(&h.db.pool, miss_id).await.1,
        tv_status::SHOW_NOT_FOUND
    );
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn a_season_pack_links_the_show_and_a_missing_episode_is_scheduled() {
    let Some(h) = harness("").await else { return };
    let video: u64 = sqlx::query(
        "INSERT INTO videos (type, title, started, tvmaze) VALUES (0, 'Known Show', '2011-01-01', 9)",
    )
    .execute(&h.db.pool)
    .await
    .unwrap()
    .last_insert_id();
    let video = video as i64;
    Mock::given(path("/shows/9/episodes"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!([
            {"season": 1, "number": 1, "name": "One", "airdate": "2011-01-01", "summary": ""},
        ])))
        .mount(&h.mock)
        .await;
    Mock::given(path("/shows/9/episodebynumber"))
        .respond_with(ResponseTemplate::new(404))
        .mount(&h.mock)
        .await;
    let pipeline = Pipeline::with_providers(h.services.clone(), maze_only(&h.services));
    let pack = "Known.Show.S01.1080p.WEB";
    let pack_id = insert_release(&h.db.pool, pack, "e0000000000000000000000000000001").await;
    let later = "Known.Show.S01E09.1080p.WEB";
    let later_id = insert_release(&h.db.pool, later, "e0000000000000000000000000000002").await;
    let mut state = RunState::default();
    pipeline
        .process_release(&candidate(pack_id, pack), &mut state)
        .await
        .unwrap();
    pipeline
        .process_release(&candidate(later_id, later), &mut state)
        .await
        .unwrap();
    assert_eq!(
        release_state(&h.db.pool, pack_id).await,
        (video, tv_status::SEASON_PACK, 0, false)
    );
    assert_eq!(
        release_state(&h.db.pool, later_id).await,
        (video, tv_status::EPISODE_MISSING, 1, true)
    );
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn tvdb_logs_in_retries_a_401_and_reads_the_extended_record() {
    let Some(h) = harness("key").await else {
        return;
    };
    Mock::given(method("POST"))
        .and(path("/login"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({"data": {"token": "tok"}})))
        .expect(2)
        .mount(&h.mock)
        .await;
    Mock::given(path("/search"))
        .respond_with(ResponseTemplate::new(401))
        .up_to_n_times(1)
        .mount(&h.mock)
        .await;
    Mock::given(path("/search"))
        .and(header("authorization", "Bearer tok"))
        .and(query_param("type", "series"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({"data": [
            {"tvdb_id": "77", "name": "Lost World", "first_air_time": "1999-01-01", "country": "gbr", "aliases": ["The Lost World"], "overview": "O"},
        ]})))
        .mount(&h.mock)
        .await;
    Mock::given(path("/series/77/artworks"))
        .respond_with(
            ResponseTemplate::new(200).set_body_json(json!({"data": {"artworks": [
                {"type": 2, "score": 1, "image": "low.jpg"},
                {"type": 2, "score": 9, "image": "high.jpg"},
                {"type": 3, "score": 99, "image": "banner.jpg"},
            ]}})),
        )
        .mount(&h.mock)
        .await;
    Mock::given(path("/series/77/extended"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({"data": {
            "remoteIds": [{"type": 4, "id": "x"}, {"type": 2, "id": "tt0123456"}],
            "originalNetwork": {"name": "BBC"},
        }})))
        .mount(&h.mock)
        .await;
    let provider = TvdbProvider(h.services.clone());
    let show = provider
        .show_info("Lost World", None, "")
        .await
        .unwrap()
        .unwrap();
    assert_eq!(show.tvdb, 77);
    assert_eq!(show.poster, "high.jpg");
    assert_eq!(show.imdb, "0123456");
    assert_eq!(show.publisher, "BBC");
    assert_eq!(show.aliases, ["The Lost World"]);
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn posters_are_saved_and_marked() {
    let Some(h) = harness("").await else { return };
    let mut png = Vec::new();
    image::DynamicImage::new_rgb8(40, 30)
        .write_to(&mut std::io::Cursor::new(&mut png), image::ImageFormat::Png)
        .unwrap();
    Mock::given(path("/poster.png"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(png))
        .mount(&h.mock)
        .await;
    let mut show = maze_show();
    show["image"] = json!({"original": format!("{}/poster.png", h.mock.uri())});
    Mock::given(path("/singlesearch/shows"))
        .respond_with(ResponseTemplate::new(200).set_body_json(show))
        .mount(&h.mock)
        .await;
    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(404))
        .mount(&h.mock)
        .await;
    let name = "Game.of.Thrones.S01.WEB";
    let id = insert_release(&h.db.pool, name, "f0000000000000000000000000000001").await;
    let pipeline = Pipeline::with_providers(h.services.clone(), maze_only(&h.services));
    let outcome = pipeline
        .process_release(&candidate(id, name), &mut RunState::default())
        .await
        .unwrap();
    let video = outcome.video_id().unwrap();
    let image: i8 = sqlx::query_scalar("SELECT image FROM tv_info WHERE videos_id = ?")
        .bind(video)
        .fetch_one(&h.db.pool)
        .await
        .unwrap();
    assert_eq!(image, 1);
    let saved = h
        .covers
        .path()
        .join("tvshows")
        .join(format!("{video}.webp"));
    assert!(saved.is_file(), "{saved:?}");
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn process_takes_only_due_releases_in_the_bucket() {
    let Some(h) = harness("").await else { return };
    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(404))
        .mount(&h.mock)
        .await;
    let in_bucket = insert_release(
        &h.db.pool,
        "One.S01E01.WEB",
        "a0000000000000000000000000000011",
    )
    .await;
    let other = insert_release(
        &h.db.pool,
        "Two.S01E01.WEB",
        "b0000000000000000000000000000012",
    )
    .await;
    let later = insert_release(
        &h.db.pool,
        "Three.S01E01.WEB",
        "a0000000000000000000000000000013",
    )
    .await;
    sqlx::query("UPDATE releases SET tv_lookup_retry_at = NOW() + INTERVAL 1 DAY WHERE id = ?")
        .bind(later)
        .execute(&h.db.pool)
        .await
        .unwrap();
    let settings = MetadataSettings {
        tv_lookup: LookupMode::All,
        ..MetadataSettings::default()
    };
    let pipeline = Pipeline::with_providers(h.services.clone(), maze_only(&h.services));
    let stats = pipeline
        .process(&settings, 0, "a", Renamed::Configured)
        .await
        .unwrap();
    assert_eq!(stats.processed, 1);
    assert_eq!(
        release_state(&h.db.pool, in_bucket).await.1,
        tv_status::SHOW_NOT_FOUND
    );
    assert_eq!(release_state(&h.db.pool, other).await.1, tv_status::PENDING);

    // The configured renamed-only mode applies even when a caller asks for all.
    let settings = MetadataSettings {
        tv_lookup: LookupMode::RenamedOnly,
        ..settings
    };
    let stats = pipeline
        .process(&settings, 0, "b", Renamed::All)
        .await
        .unwrap();
    assert_eq!(stats.processed, 0);
    let disabled = MetadataSettings {
        tv_lookup: LookupMode::Disabled,
        ..settings
    };
    let stats = pipeline
        .process(&disabled, 0, "b", Renamed::Configured)
        .await
        .unwrap();
    assert_eq!(stats.processed, 0);
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn repair_links_normalizes_and_is_idempotent() {
    let Some(h) = harness("").await else { return };
    let db = &h.db.pool;
    let legacy = insert_release(db, "Legacy.S01E01.WEB", "a0000000000000000000000000000021").await;
    let exhausted = insert_release(db, "Gone.S01E01.WEB", "a0000000000000000000000000000022").await;
    let video =
        sqlx::query("INSERT INTO videos (type, title, started) VALUES (0, 'Real', '2011-01-01')")
            .execute(db)
            .await
            .unwrap()
            .last_insert_id() as i64;
    let keep = sqlx::query("INSERT INTO tv_episodes (videos_id, series, episode, se_complete, title, firstaired, summary) VALUES (?, 1, 1, 'S01E01', 'A', '2011-01-01', '')")
        .bind(video)
        .execute(db)
        .await
        .unwrap()
        .last_insert_id() as i64;
    let duplicate = sqlx::query("INSERT INTO tv_episodes (videos_id, series, episode, se_complete, title, firstaired, summary) VALUES (?, 1, 1, 'S01E01', 'B', NULL, '')")
        .bind(video)
        .execute(db)
        .await
        .unwrap()
        .last_insert_id() as i64;
    let stuck = insert_release(db, "Real.S01E05.WEB", "a0000000000000000000000000000023").await;
    let pack = insert_release(db, "Real.S01.WEB", "a0000000000000000000000000000024").await;
    let orphan = insert_release(db, "Orphan.S01E01.WEB", "a0000000000000000000000000000025").await;
    let on_duplicate =
        insert_release(db, "Real.S01E01.WEB", "a0000000000000000000000000000026").await;
    for (id, video_id, episode) in [
        (legacy, 0, -2),
        (exhausted, 0, -4),
        (stuck, video, 0),
        (pack, video, 0),
        (orphan, 999_999, 0),
        (on_duplicate, video, duplicate),
    ] {
        sqlx::query("UPDATE releases SET videos_id = ?, tv_episodes_id = ? WHERE id = ?")
            .bind(video_id)
            .bind(episode)
            .bind(id)
            .execute(db)
            .await
            .unwrap();
    }
    sqlx::query(
        "INSERT INTO tv_info (videos_id, summary, publisher, localzone) VALUES (?, '', '', '''''')",
    )
    .bind(video)
    .execute(db)
    .await
    .unwrap();

    let dry = TvRepairLinksOptions {
        all: true,
        dry_run: true,
        chunk: 2,
        ..TvRepairLinksOptions::default()
    };
    let counted = repair_links(&h.services, &dry).await.unwrap();
    assert_eq!(
        release_state(db, legacy).await.1,
        -2,
        "a dry run changes nothing"
    );
    assert!(
        counted
            .iter()
            .any(|(label, count)| label.starts_with("Legacy") && *count == 2)
    );

    let run = TvRepairLinksOptions {
        all: true,
        chunk: 2,
        ..TvRepairLinksOptions::default()
    };
    repair_links(&h.services, &run).await.unwrap();
    assert_eq!(release_state(db, legacy).await.1, tv_status::PENDING);
    assert_eq!(
        release_state(db, exhausted).await.1,
        tv_status::SHOW_NOT_FOUND
    );
    assert_eq!(
        release_state(db, stuck).await,
        (video, tv_status::EPISODE_MISSING, 5, true)
    );
    assert_eq!(
        release_state(db, pack).await,
        (video, tv_status::SEASON_PACK, 0, false)
    );
    assert_eq!(release_state(db, orphan).await, (0, 0, 0, false));
    assert_eq!(release_state(db, on_duplicate).await.1, keep);
    let zone: String = sqlx::query_scalar("SELECT localzone FROM tv_info WHERE videos_id = ?")
        .bind(video)
        .fetch_one(db)
        .await
        .unwrap();
    assert_eq!(zone, "");
    let episodes: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM tv_episodes WHERE videos_id = ?")
        .bind(video)
        .fetch_one(db)
        .await
        .unwrap();
    assert_eq!(episodes, 1);

    let again = repair_links(&h.services, &run).await.unwrap();
    assert!(again.iter().all(|(_, count)| *count == 0), "{again:?}");
    assert!(
        repair_links(&h.services, &TvRepairLinksOptions::default())
            .await
            .is_err()
    );
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn external_id_matches_stay_on_tv_rows() {
    let Some(h) = harness("").await else { return };
    let film = sqlx::query(
        "INSERT INTO videos (type, title, started, tmdb) VALUES (1, 'A Film', '2011-01-01', 55)",
    )
    .execute(&h.db.pool)
    .await
    .unwrap()
    .last_insert_id() as i64;
    let show = Show {
        title: "A Show".to_owned(),
        started: "2012-01-01".to_owned(),
        tmdb: 55,
        ..Show::default()
    };
    let added = add_show(&h.services, &show).await.unwrap();
    assert_ne!(
        added, film,
        "a film with the same TMDB id is another entity"
    );
    h.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn trakt_resolves_external_ids_before_asking_for_an_episode() {
    let Some(h) = harness("").await else { return };
    let cache = h.services.cache.clone();
    let mut trakt = Trakt::new(Client::new(), cache, "key".to_owned(), 5, 1, 1);
    trakt.base_url = h.mock.uri();
    trakt.throttled = false;
    Mock::given(path("/search/tmdb/55"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!([
            {"show": {"ids": {"trakt": 900, "tmdb": 55}}},
        ])))
        .mount(&h.mock)
        .await;
    // Trakt id 55 is some other show: asking for it would store that show's episode.
    Mock::given(path("/shows/55/seasons/1/episodes/2"))
        .respond_with(
            ResponseTemplate::new(200)
                .set_body_json(json!({"season": 1, "number": 2, "title": "Wrong"})),
        )
        .expect(0)
        .mount(&h.mock)
        .await;
    Mock::given(path("/shows/900/seasons/1/episodes/2"))
        .respond_with(
            ResponseTemplate::new(200)
                .set_body_json(json!({"season": 1, "number": 2, "title": "Right"})),
        )
        .mount(&h.mock)
        .await;
    let ids = EpisodeIds {
        trakt: 0,
        tmdb: 55,
        tvdb: 0,
        imdb: "",
    };
    let episode = trakt
        .episode_with_fallback(ids, 1, 2)
        .await
        .unwrap()
        .unwrap();
    assert_eq!(episode["title"], "Right");
    h.db.drop_database().await.unwrap();
}
