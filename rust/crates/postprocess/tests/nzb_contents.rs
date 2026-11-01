//! Reading a release's stored NZB for additional processing, against a real database.

use anyhow::Result;
use nntmux_postprocess::additional::nzb_contents::parse_release_nzb;
use nntmux_testkit::TestDb;
use nntmux_verify::in_tx;
use nntmux_verify::nzb::{self, ReleaseRef};
use sqlx::MySqlPool;

async fn release(pool: &MySqlPool, guid: &str, document: Option<&[u8]>) -> Result<i64> {
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
    if let Some(document) = document {
        let reference = ReleaseRef {
            id,
            guid: guid.to_owned(),
        };
        let body = nzb::compress(document)?;
        in_tx!(pool, 1, |tx| {
            nzb::put_locked(tx, &reference, &body, None, false).await
        })?;
    }
    Ok(id)
}

const FILES: &str = "<file subject=\"&quot;Show.S01E01.2160p.WEB-DL.mkv&quot; yEnc (1/2)\">\
    <segments><segment number=\"2\" bytes=\"40\">m2@x</segment>\
    <segment number=\"1\" bytes=\"60\">m1@x</segment></segments></file>\
    <file subject=\"&quot;Show.S01E01.2160p.WEB-DL.nfo&quot; yEnc (1/1)\">\
    <segments><segment number=\"1\" bytes=\"5\">n1@x</segment></segments></file>";

#[tokio::test]
async fn a_stored_nzb_lists_its_files_in_natural_order() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let document = format!("<nzb>{FILES}</nzb>");
    release(pool, "a1", Some(document.as_bytes())).await?;
    let parsed = parse_release_nzb(pool, "a1").await?.expect("readable");
    let titles: Vec<&str> = parsed
        .contents
        .iter()
        .map(|file| file.title.as_str())
        .collect();
    assert_eq!(
        titles,
        [
            "\"Show.S01E01.2160p.WEB-DL.mkv\" yEnc (1/2)",
            "\"Show.S01E01.2160p.WEB-DL.nfo\" yEnc (1/1)"
        ]
    );
    assert_eq!(parsed.contents[0].segments, ["m1@x", "m2@x"]);
    assert_eq!(parsed.contents[0].size, 100);
    assert_eq!(parsed.source_digest, nzb::digest(document.as_bytes()));
    let sources = parsed.source_files.expect("a plain inventory");
    assert_eq!(sources.len(), 2);
    assert_eq!(sources[0].first_message_id, "m1@x");
    // An NFO beside the video means the files aren't all direct video.
    assert!(parsed.direct_video_files.is_none());
    db.drop_database().await
}

#[tokio::test]
async fn one_video_file_is_direct_video() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let document = b"<nzb><file subject=\"&quot;Film.2160p.BluRay.REMUX.mkv&quot; yEnc (1/1)\">\
        <segments><segment number=\"1\" bytes=\"60000000000\">big@x</segment></segments></file></nzb>";
    release(pool, "b1", Some(document)).await?;
    let parsed = parse_release_nzb(pool, "b1").await?.expect("readable");
    assert_eq!(parsed.contents.len(), 1);
    assert_eq!(parsed.contents[0].size, 60_000_000_000);
    let direct = parsed.direct_video_files.expect("a single video file");
    assert_eq!(direct[0].name, "Film.2160p.BluRay.REMUX.mkv");
    db.drop_database().await
}

#[tokio::test]
async fn a_broken_nzb_is_repaired_and_installed() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    // A control byte and a missing closing tag; the repair removes one and adds the other.
    let broken = format!("<nzb>\x01{FILES}");
    release(pool, "c1", Some(broken.as_bytes())).await?;
    let parsed = parse_release_nzb(pool, "c1").await?.expect("repaired");
    assert_eq!(parsed.contents.len(), 2);
    let stored = nzb::read(pool, "c1").await?.expect("stored");
    let fixed = format!("<nzb>{FILES}\n</nzb>");
    assert_eq!(stored, fixed.as_bytes());
    assert_eq!(parsed.source_digest, nzb::digest(fixed.as_bytes()));
    db.drop_database().await
}

#[tokio::test]
async fn missing_and_unreadable_nzbs_say_why() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let missing = parse_release_nzb(pool, "d1")
        .await?
        .expect_err("no release");
    assert_eq!(missing.message, "NZB is missing or broken for GUID: d1");
    assert!(missing.source_changed);
    release(pool, "d2", None).await?;
    let absent = parse_release_nzb(pool, "d2").await?.expect_err("no NZB");
    assert_eq!(absent.message, "NZB is missing or broken for GUID: d2");
    release(pool, "d3", Some(b"<html>nothing</html>")).await?;
    let hopeless = parse_release_nzb(pool, "d3")
        .await?
        .expect_err("unrepairable");
    assert_eq!(hopeless.message, "NZB is potentially broken for GUID: d3");
    db.drop_database().await
}
