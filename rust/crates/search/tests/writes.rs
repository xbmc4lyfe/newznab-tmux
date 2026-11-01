//! Writes to the other indexes and table maintenance, on a real Manticore. Skips unless
//! `TEST_MANTICORE_HTTP` is set.

use nntmux_search::config::{IndexNames, LOGICAL_INDEXES};
use nntmux_search::{
    MovieDocument, MovieExternalIds, PredbHit, ReleaseDocument, SearchClient, SearchConfig,
    SecondaryIndex, TvShowDocument, create_table_sql,
};
use serde_json::json;

async fn live() -> Option<SearchClient> {
    let Ok(address) = std::env::var("TEST_MANTICORE_HTTP") else {
        eprintln!("TEST_MANTICORE_HTTP is unset, skipping");
        return None;
    };
    let prefix = format!("t{:016x}_", rand::random::<u64>());
    let mut config = SearchConfig::new(format!("http://{address}"));
    config.indexes = IndexNames::prefixed(&prefix);
    Some(SearchClient::new(config, None).unwrap())
}

async fn create_all(client: &SearchClient) {
    for logical in LOGICAL_INDEXES {
        let table = client.config().indexes.table(logical).to_owned();
        client
            .sql(&create_table_sql(&table, logical))
            .await
            .unwrap();
    }
}

async fn drop_all(client: &SearchClient) {
    for logical in LOGICAL_INDEXES {
        let table = client.config().indexes.table(logical).to_owned();
        client
            .sql(&format!("DROP TABLE IF EXISTS {table}"))
            .await
            .unwrap();
    }
}

#[tokio::test]
async fn predb_writes_replace_or_refuse_to() {
    let Some(client) = live().await else {
        return;
    };
    create_all(&client).await;
    let hit = |id, title: &str| PredbHit {
        id,
        title: title.to_owned(),
        filename: format!("{title}.nfo"),
        source: "srrdb".to_owned(),
    };

    let inserted = client
        .bulk_insert_predb(&[hit(1, "One.Release"), hit(2, "Two.Release")], false)
        .await;
    assert_eq!((inserted.success, inserted.errors), (2, 0));

    // An insert won't overwrite a fresher document.
    let conflict = client
        .bulk_insert_predb(&[hit(1, "Stale.Name")], false)
        .await;
    assert_eq!((conflict.success, conflict.errors), (0, 1));
    let found = client
        .match_predb_exact("One.Release")
        .await
        .unwrap()
        .unwrap();
    assert_eq!(found.id, 1);

    let replaced = client
        .bulk_insert_predb(&[hit(1, "Fresh.Name")], true)
        .await;
    assert_eq!((replaced.success, replaced.errors), (1, 0));
    assert!(
        client
            .match_predb_exact("One.Release")
            .await
            .unwrap()
            .is_none()
    );
    assert!(
        client
            .match_predb_exact("Fresh.Name")
            .await
            .unwrap()
            .is_some()
    );

    client.insert_predb(&hit(3, "Three.Release")).await.unwrap();
    assert!(client.insert_predb(&hit(0, "No.Id")).await.is_err());
    assert_eq!(
        client
            .bulk_insert_predb(&[hit(0, "No.Id")], true)
            .await
            .success,
        0
    );
    let table = client.config().indexes.table("predb").to_owned();
    assert_eq!(client.count_index_documents(&table).await.unwrap(), 3);

    drop_all(&client).await;
}

#[tokio::test]
async fn movie_tv_and_secondary_writes_round_trip() {
    let Some(client) = live().await else {
        return;
    };
    create_all(&client).await;

    let movie = MovieDocument {
        id: 5,
        imdbid: "0133093".to_owned(),
        tmdbid: 603,
        title: "The Matrix".to_owned(),
        ..MovieDocument::default()
    };
    client.insert_movie(&movie).await.unwrap();
    let ids = MovieExternalIds {
        tmdbid: Some(603),
        ..MovieExternalIds::default()
    };
    let found = client
        .search_movie_by_external_ids(&ids)
        .await
        .unwrap()
        .unwrap();
    assert_eq!(found["title"], json!("The Matrix"));
    client.delete_movie(5).await.unwrap();
    assert!(
        client
            .search_movie_by_external_ids(&ids)
            .await
            .unwrap()
            .is_none()
    );
    let bulk = client
        .bulk_insert_movies(&[movie.clone(), MovieDocument::default()])
        .await;
    assert_eq!((bulk.success, bulk.errors), (1, 1));

    let show = TvShowDocument {
        id: 9,
        title: "Lost".to_owned(),
        tvdb: 73739,
        kind: 1,
        ..TvShowDocument::default()
    };
    client.insert_tv_show(&show).await.unwrap();
    let hits = client.search_tv_shows("lost").await.unwrap();
    assert_eq!(hits.id, [9]);
    assert_eq!(hits.data[0]["type"], json!(1));
    client.delete_tv_show(9).await.unwrap();
    assert!(client.search_tv_shows("lost").await.unwrap().id.is_empty());
    let bulk = client.bulk_insert_tv_shows(&[show]).await;
    assert_eq!((bulk.success, bulk.errors), (1, 0));

    client
        .insert_secondary(
            SecondaryIndex::Music,
            1,
            &json!({"title": "Abbey Road", "artist": "The Beatles"}),
        )
        .await
        .unwrap();
    assert_eq!(
        client
            .search_secondary(SecondaryIndex::Music, "abbey", 10)
            .await
            .unwrap()
            .id,
        [1]
    );
    client
        .delete_secondary(SecondaryIndex::Music, 1)
        .await
        .unwrap();
    let bulk = client
        .bulk_insert_secondary(
            SecondaryIndex::Music,
            &[
                json!({"id": 2, "title": "Let It Be"}),
                json!({"title": "No Id"}),
            ],
        )
        .await;
    assert_eq!((bulk.success, bulk.errors), (1, 1));

    drop_all(&client).await;
}

#[tokio::test]
async fn a_secondary_write_creates_its_table() {
    let Some(client) = live().await else {
        return;
    };
    client
        .insert_secondary(
            SecondaryIndex::Anime,
            7,
            &json!({"title": "Cowboy Bebop", "anidbid": 23}),
        )
        .await
        .unwrap();
    assert_eq!(client.search_anime_title("cowboy", 10).await.unwrap(), [23]);
    drop_all(&client).await;
}

#[tokio::test]
async fn maintenance_counts_pages_truncates_and_optimizes() {
    let Some(client) = live().await else {
        return;
    };
    create_all(&client).await;
    let documents: Vec<ReleaseDocument> = (1..=3)
        .map(|id| ReleaseDocument {
            id,
            searchname: format!("Release {id}"),
            ..ReleaseDocument::default()
        })
        .collect();
    let bulk = client.bulk_insert_releases(&documents).await;
    assert_eq!((bulk.success, bulk.errors), (3, 0));
    let table = client.config().indexes.table("releases").to_owned();
    assert_eq!(client.count_index_documents(&table).await.unwrap(), 3);
    assert_eq!(client.indexed_release_ids(2, 0).await.unwrap(), [1, 2]);
    assert_eq!(client.indexed_release_ids(2, 2).await.unwrap(), [3]);
    assert!(client.count_index_documents("bad name").await.is_err());

    client.optimize_indexes().await.unwrap();

    client.truncate_indexes(&[&table]).await.unwrap();
    assert_eq!(client.count_index_documents(&table).await.unwrap(), 0);
    assert!(client.truncate_indexes(&["not_configured"]).await.is_err());

    // Truncating a table that's gone creates it.
    client.sql(&format!("DROP TABLE {table}")).await.unwrap();
    client.truncate_indexes(&[&table]).await.unwrap();
    assert_eq!(client.count_index_documents(&table).await.unwrap(), 0);

    drop_all(&client).await;
}
