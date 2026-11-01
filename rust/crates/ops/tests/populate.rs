//! Populate against real MariaDB and Manticore, on tables private to each test.

use anyhow::Result;
use nntmux_ops::populate::{INDEXES, Options, anime_document_id, populate};
use nntmux_search::config::{IndexNames, LOGICAL_INDEXES};
use nntmux_search::{SearchClient, SearchConfig};
use nntmux_testkit::TestDb;
use serde_json::Value;

const OPTIONS: Options = Options {
    count: 2,
    parallel: 3,
};

async fn live() -> Result<Option<(TestDb, SearchClient)>> {
    let Ok(address) = std::env::var("TEST_MANTICORE_HTTP") else {
        eprintln!("TEST_MANTICORE_HTTP is unset, skipping");
        return Ok(None);
    };
    let Some(db) = TestDb::create().await? else {
        return Ok(None);
    };
    let mut config = SearchConfig::new(format!("http://{address}"));
    config.indexes = IndexNames::prefixed(&format!("{}_", db.name));
    let client = SearchClient::new(config, None)?;
    client.create_indexes(&[], false).await?;
    Ok(Some((db, client)))
}

async fn finish(db: TestDb, client: SearchClient) -> Result<()> {
    for logical in LOGICAL_INDEXES {
        let table = client.config().indexes.table(logical).to_owned();
        client.sql(&format!("DROP TABLE IF EXISTS {table}")).await?;
    }
    db.drop_database().await
}

async fn document(client: &SearchClient, logical: &str, id: i64) -> Result<Value> {
    let table = client.config().indexes.table(logical);
    let rows = client
        .sql(&format!("SELECT * FROM {table} WHERE id = {id}"))
        .await?;
    Ok(Value::Object(
        rows.into_iter().next().expect("the document exists"),
    ))
}

#[tokio::test]
async fn every_index_is_rebuilt_from_its_table() -> Result<()> {
    let Some((test, client)) = live().await? else {
        return Ok(());
    };
    let db = &test.pool;
    for statement in [
        "INSERT INTO releases (id, name, searchname, fromname, adddate, postdate, guid, leftguid, groups_id, categories_id) VALUES \
         (3, 'n3', 'Some.Release-GRP', 'f', NOW(), NOW(), 'g3', 'g', 1, 10), \
         (40, 'n40', 'Other.Release-GRP', 'f', NOW(), NOW(), 'g40', 'g', 1, 10)",
        "INSERT INTO release_files (releases_id, name, size) VALUES (3, 'a.mkv', 1), (3, 'b.nfo', 1)",
        "INSERT INTO predb (id, title, filename, source) VALUES (1, 'Pre.One', NULL, 'src'), (9, 'Pre.Nine', 'f', 'src')",
        "INSERT INTO movieinfo (id, imdbid, title, year, genre, actors, director, rating, plot, tagline, language, type, trailer) VALUES (5, '0133093', 'Matrix', '1999', 'SF', 'K', 'W', '8.7', 'p', '', '', '', '')",
        "INSERT INTO videos (id, type, title, countries_id, started, source, tvdb, imdb) VALUES (6, 0, 'Show', '', '2020-01-02 00:00:00', 0, 77, '0944947')",
        "INSERT INTO musicinfo (id, title, year, artist) VALUES (2, 'Album', '2001', 'Band')",
        "INSERT INTO bookinfo (id, title, author, genre, publishdate) VALUES (2, 'Book', 'Writer', '', '2011-05-06 00:00:00')",
        "INSERT INTO gamesinfo (id, title, releasedate) VALUES (2, 'Game', '2015-01-01 00:00:00')",
        "INSERT INTO consoleinfo (id, title, platform, releasedate) VALUES (2, 'Console Game', 'PS5', NULL)",
        "INSERT INTO steam_apps (id, name, appid) VALUES (2, 'Steam Game', 440)",
        "INSERT INTO anidb_titles (anidbid, type, lang, title) VALUES (8, 'main', 'en', 'Anime One'), (8, 'syn', 'en', 'Anime Alt')",
        "INSERT INTO anidb_info (anidbid, anilist_id, mal_id, media_type, status) VALUES (8, 11, 12, 'TV', 'done')",
    ] {
        sqlx::query(statement).execute(db).await?;
    }

    for logical in INDEXES {
        let expected = match logical {
            "releases" | "predb" | "anime" => 2,
            _ => 1,
        };
        assert_eq!(
            populate(&client, db, logical, OPTIONS).await?,
            expected,
            "{logical}"
        );
    }
    // A second run truncates first, so it doesn't add to the count.
    assert_eq!(populate(&client, db, "predb", OPTIONS).await?, 2);

    let release = document(&client, "releases", 3).await?;
    assert_eq!(release["searchname"], "Some.Release-GRP");
    assert_eq!(release["filename"], "a.mkv b.nfo");
    assert_eq!(document(&client, "predb", 9).await?["title"], "Pre.Nine");
    assert_eq!(document(&client, "movies", 5).await?["title"], "Matrix");
    assert_eq!(
        document(&client, "tvshows", 6).await?["tvdb"]
            .to_string()
            .trim_matches('"'),
        "77"
    );
    assert_eq!(document(&client, "books", 2).await?["author"], "Writer");
    assert_eq!(document(&client, "steam", 2).await?["name"], "Steam Game");
    let games = document(&client, "games", 2).await?;
    assert_eq!(
        games["releasedate_ts"].to_string().trim_matches('"'),
        "1420070400"
    );
    let console = document(&client, "console", 2).await?;
    assert_eq!(console["releasedate_ts"].to_string().trim_matches('"'), "0");
    let anime = document(
        &client,
        "anime",
        anime_document_id(8, "main", "en", "Anime One"),
    )
    .await?;
    assert_eq!(anime["title"], "Anime One");
    assert_eq!(anime["media_type"], "TV");
    finish(test, client).await
}

#[tokio::test]
async fn empty_tables_and_unknown_indexes() -> Result<()> {
    let Some((test, client)) = live().await? else {
        return Ok(());
    };
    assert_eq!(populate(&client, &test.pool, "steam", OPTIONS).await?, 0);
    assert!(
        populate(&client, &test.pool, "nope", OPTIONS)
            .await
            .is_err()
    );
    finish(test, client).await
}
