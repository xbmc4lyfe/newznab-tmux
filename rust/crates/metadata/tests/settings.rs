use nntmux_metadata::{LookupMode, MetadataSettings};
use nntmux_testkit::TestDb;

#[tokio::test]
async fn loads_the_seeded_row_and_falls_back_without_one() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    sqlx::query("UPDATE metadata_configurations SET tv_lookup = 2, max_tv_processed = 12")
        .execute(&db.pool)
        .await
        .unwrap();
    let settings = MetadataSettings::load(&db.pool).await.unwrap();
    assert_eq!(settings.tv_lookup, LookupMode::RenamedOnly);
    assert_eq!(settings.anime_lookup, LookupMode::Disabled);
    assert_eq!(settings.max_tv_processed, 12);
    assert_eq!(settings.movie_language, "en");

    sqlx::query("DELETE FROM metadata_configurations")
        .execute(&db.pool)
        .await
        .unwrap();
    assert_eq!(
        MetadataSettings::load(&db.pool).await.unwrap(),
        MetadataSettings::default()
    );
    db.drop_database().await.unwrap();
}
