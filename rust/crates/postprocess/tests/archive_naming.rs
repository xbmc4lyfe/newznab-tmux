//! Recording archive naming evidence against a real database.

use anyhow::Result;
use nntmux_postprocess::additional::archive_naming::{Observation, Scope, record_locked};
use nntmux_testkit::TestDb;
use nntmux_verify::in_tx;
use nntmux_verify::nzb::{self, ReleaseRef};
use sqlx::MySqlPool;

const DOCUMENT: &[u8] =
    b"<nzb><file subject=\"&quot;Show.S01E01.2160p.WEB-DL.mkv&quot; yEnc (1/1)\">\
    <segments><segment number=\"1\" bytes=\"60\">m1@x</segment></segments></file></nzb>";

async fn release(pool: &MySqlPool, guid: &str) -> Result<(i64, String)> {
    let id = sqlx::query(
        "INSERT INTO releases (name, searchname, totalpart, groups_id, fromname, size, postdate, \
         adddate, guid, leftguid, categories_id, nzbstatus) VALUES ('n', 's', 1, 1, 'f', 5000000, \
         '2026-01-01 00:00:00', '2026-01-01 00:00:00', ?, ?, 10, 1)",
    )
    .bind(guid)
    .bind(&guid[..1])
    .execute(pool)
    .await?
    .last_insert_id() as i64;
    let reference = ReleaseRef {
        id,
        guid: guid.to_owned(),
    };
    let body = nzb::compress(DOCUMENT)?;
    in_tx!(pool, 1, |tx| {
        nzb::put_locked(tx, &reference, &body, None, false).await
    })?;
    Ok((id, nzb::digest(DOCUMENT)))
}

struct Seen {
    id: i64,
    guid: String,
    digest: String,
    scope: Scope,
    names: Vec<String>,
    authoritative: bool,
    omitted: bool,
}

impl Seen {
    fn new(id: i64, guid: &str, digest: &str, scope: Scope, names: &[&str]) -> Self {
        Self {
            id,
            guid: guid.to_owned(),
            digest: digest.to_owned(),
            scope,
            names: names.iter().map(|name| (*name).to_owned()).collect(),
            authoritative: true,
            omitted: false,
        }
    }
}

async fn record(pool: &MySqlPool, seen: &Seen) -> Result<bool> {
    let observation = Observation {
        release_id: seen.id,
        guid: &seen.guid,
        source_digest: &seen.digest,
        scope: seen.scope,
        filenames: &seen.names,
        authoritative_complete: seen.authoritative,
        evidence_omitted: seen.omitted,
    };
    let mut tx = pool.begin().await?;
    let recorded = record_locked(&mut tx, &observation).await;
    tx.commit().await?;
    recorded
}

async fn stored(pool: &MySqlPool, id: i64) -> Result<Option<(String, Vec<String>)>> {
    let row: Option<(String, String)> = sqlx::query_as(
        "SELECT scope, episode_files FROM release_archive_naming_evidence WHERE releases_id = ?",
    )
    .bind(id)
    .fetch_optional(pool)
    .await?;
    Ok(row.map(|(scope, files)| (scope, serde_json::from_str(&files).expect("JSON"))))
}

fn row(scope: &str, names: &[&str]) -> Option<(String, Vec<String>)> {
    Some((
        scope.to_owned(),
        names.iter().map(|name| (*name).to_owned()).collect(),
    ))
}

const EPISODE: &str = "Show.S01E01.2160p.WEB-DL.mkv";

#[tokio::test]
async fn scopes_are_recorded_and_downgraded_as_the_evidence_allows() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let (id, digest) = release(pool, "a1").await?;

    let seen = Seen::new(id, "a1", &digest, Scope::CompleteSingle, &[EPISODE]);
    assert!(record(pool, &seen).await?);
    assert_eq!(stored(pool, id).await?, row("complete_single", &[EPISODE]));

    // A later, partial look can't weaken a complete single.
    let partial = Seen::new(id, "a1", &digest, Scope::Incomplete, &["other.nfo"]);
    assert!(record(pool, &partial).await?);
    assert_eq!(stored(pool, id).await?, row("complete_single", &[EPISODE]));

    // Not authoritative, or nothing named, is incomplete.
    let (id2, digest2) = release(pool, "b1").await?;
    let mut guess = Seen::new(id2, "b1", &digest2, Scope::CompleteSingle, &[EPISODE]);
    guess.authoritative = false;
    assert!(record(pool, &guess).await?);
    assert_eq!(stored(pool, id2).await?, row("incomplete", &[EPISODE]));

    // Incomplete looks accumulate.
    let more = Seen::new(id2, "b1", &digest2, Scope::Incomplete, &["Show.S01E01.nfo"]);
    assert!(record(pool, &more).await?);
    assert_eq!(
        stored(pool, id2).await?,
        row("incomplete", &[EPISODE, "Show.S01E01.nfo"])
    );

    // A dropped name makes even an authoritative look incomplete.
    let (id3, digest3) = release(pool, "c1").await?;
    let mut omitted = Seen::new(id3, "c1", &digest3, Scope::CompleteSingle, &[EPISODE]);
    omitted.omitted = true;
    assert!(record(pool, &omitted).await?);
    assert_eq!(stored(pool, id3).await?, row("incomplete", &[EPISODE]));
    db.drop_database().await
}

#[tokio::test]
async fn pack_evidence_wins_and_sticks() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let (id, digest) = release(pool, "a1").await?;
    let pack = "Show.S01.COMPLETE.2160p.WEB-DL.mkv";
    let seen = Seen::new(id, "a1", &digest, Scope::CompleteSingle, &[pack]);
    assert!(record(pool, &seen).await?);
    assert_eq!(stored(pool, id).await?, row("multi_episode", &[pack]));

    let later = Seen::new(id, "a1", &digest, Scope::CompleteSingle, &[EPISODE]);
    assert!(record(pool, &later).await?);
    assert_eq!(stored(pool, id).await?, row("multi_episode", &[pack]));
    db.drop_database().await
}

#[tokio::test]
async fn a_changed_source_or_identity_rejects_the_finalization() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let (id, _) = release(pool, "a1").await?;
    let stale = Seen::new(id, "a1", &"0".repeat(64), Scope::CompleteSingle, &[EPISODE]);
    assert!(!record(pool, &stale).await?);
    // Storing the NZB recorded an unknown scope, which stays.
    assert_eq!(stored(pool, id).await?, row("unknown", &[]));

    let digest = nzb::digest(DOCUMENT);
    let moved = Seen::new(id, "zz", &digest, Scope::CompleteSingle, &[EPISODE]);
    assert!(record(pool, &moved).await.is_err());
    let bad = Seen::new(id, "a1", "not a digest", Scope::CompleteSingle, &[EPISODE]);
    assert!(record(pool, &bad).await.is_err());

    // Stored evidence that isn't well formed is an error, never absence.
    sqlx::query(
        "UPDATE release_archive_naming_evidence SET scope = 'season' WHERE releases_id = ?",
    )
    .bind(id)
    .execute(pool)
    .await?;
    let fine = Seen::new(id, "a1", &digest, Scope::CompleteSingle, &[EPISODE]);
    assert!(record(pool, &fine).await.is_err());
    db.drop_database().await
}
