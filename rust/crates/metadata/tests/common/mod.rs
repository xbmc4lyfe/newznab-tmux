#![allow(dead_code)]

use std::sync::Arc;

use nntmux_metadata::cache::Cache;
use nntmux_metadata::clients::anilist::AniList;
use nntmux_metadata::clients::fanart::Fanart;
use nntmux_metadata::clients::imdb::Imdb;
use nntmux_metadata::clients::omdb::Omdb;
use nntmux_metadata::clients::tmdb::Tmdb;
use nntmux_metadata::clients::trakt::Trakt;
use nntmux_metadata::clients::tvdb::Tvdb;
use nntmux_metadata::clients::tvmaze::TvMaze;
use nntmux_metadata::images::Images;
use nntmux_metadata::services::Services;
use nntmux_testkit::TestDb;
use reqwest::Client;
use sqlx::MySqlPool;
use tempfile::TempDir;
use wiremock::MockServer;

pub struct Harness {
    pub db: TestDb,
    pub services: Arc<Services>,
    pub mock: MockServer,
    pub covers: TempDir,
}

/// API keys to configure. An empty key leaves that client off.
#[derive(Clone, Copy, Default)]
pub struct Keys {
    pub tvdb: &'static str,
    pub tmdb: &'static str,
    pub trakt: &'static str,
    pub omdb: &'static str,
    pub fanart: &'static str,
    pub imdb: bool,
}

pub async fn harness(keys: Keys) -> Option<Harness> {
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
    let mut tmdb = Tmdb::new(http.clone(), keys.tmdb.to_owned(), 5, 1, 1);
    tmdb.base_url = mock.uri();
    let mut tvdb = Tvdb::new(http.clone(), keys.tvdb.to_owned(), None, 5);
    tvdb.base_url = mock.uri();
    let mut tvmaze = TvMaze::new(http.clone(), 5, 1);
    tvmaze.base_url = mock.uri();
    tvmaze.min_interval = std::time::Duration::ZERO;
    let mut trakt = Trakt::new(http.clone(), cache.clone(), keys.trakt.to_owned(), 5, 1, 1);
    trakt.base_url = mock.uri();
    trakt.throttled = false;
    let mut fanart = Fanart::new(http.clone(), cache.clone(), keys.fanart.to_owned(), 5, 1, 1);
    fanart.base_url = mock.uri();
    let mut omdb = Omdb::new(http.clone(), keys.omdb.to_owned());
    omdb.base_url = mock.uri();
    let mut imdb = Imdb::from_env(cache.clone()).unwrap();
    imdb.scraping = keys.imdb;
    imdb.api_dev = false;
    imdb.title_url = format!("{}/title", mock.uri());
    imdb.suggestion_url = format!("{}/suggestion", mock.uri());
    imdb.find_url = format!("{}/find/", mock.uri());
    imdb.api_dev_url = format!("{}/imdbapi", mock.uri());
    let mut anilist = AniList::new(http.clone());
    anilist.url = format!("{}/graphql", mock.uri());
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
        omdb,
        imdb,
        anilist,
    });
    Some(Harness {
        db,
        services,
        mock,
        covers,
    })
}

pub async fn insert_release_in(db: &MySqlPool, name: &str, guid: &str, category: i64) -> i64 {
    let done = sqlx::query(
        "INSERT INTO releases (name, searchname, guid, leftguid, fromname, size, postdate, adddate, \
         categories_id, groups_id, totalpart) \
         VALUES (?, ?, ?, ?, 'poster', 5000000, NOW(), NOW(), ?, 1, 1)",
    )
    .bind(name)
    .bind(name)
    .bind(guid)
    .bind(&guid[..1])
    .bind(category)
    .execute(db)
    .await
    .unwrap();
    done.last_insert_id() as i64
}

pub async fn insert_release(db: &MySqlPool, name: &str, guid: &str) -> i64 {
    insert_release_in(db, name, guid, 5030).await
}
