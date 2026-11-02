//! NFO processing against a real database and a scripted NNTP server.

#[allow(dead_code)]
mod support;

use std::os::unix::fs::PermissionsExt;
use std::path::Path;
use std::time::Duration;

use anyhow::Result;
use nntmux_postprocess::nfo::{self, NfoService, Settings};
use nntmux_testkit::TestDb;
use nntmux_verify::in_tx;
use nntmux_verify::nzb::{self, ReleaseRef};
use sqlx::MySqlPool;
use support::{article, client, serve};

const NFO: &str = "  Release Date ....: 2021-05-01\n  Source ..........: UHD BluRay\n  \
    Video ...........: HEVC 10bit HDR\n  Audio ...........: English TrueHD Atmos 7.1\n  \
    IMDB ............: https://www.imdb.com/title/tt0000001/\n";

fn nzb_document(files: &[(&str, &[&str])]) -> Vec<u8> {
    let mut document = String::from("<?xml version=\"1.0\"?><nzb>");
    for (subject, segments) in files {
        document.push_str(&format!(
            "<file subject=\"{}\"><groups><group>alt.binaries.test</group></groups><segments>",
            subject.replace('"', "&quot;")
        ));
        for (index, id) in segments.iter().enumerate() {
            document.push_str(&format!(
                "<segment bytes=\"100\" number=\"{}\">{id}</segment>",
                index + 1
            ));
        }
        document.push_str("</segments></file>");
    }
    document.push_str("</nzb>");
    document.into_bytes()
}

async fn release(
    pool: &MySqlPool,
    guid: &str,
    status: i64,
    files: &[(&str, &[&str])],
) -> Result<i64> {
    let id = sqlx::query(
        "INSERT INTO releases (name, searchname, totalpart, groups_id, fromname, size, postdate, \
         adddate, guid, leftguid, categories_id, nzbstatus, nfostatus) VALUES ('n', 's', 1, 1, 'f', \
         5000000, '2026-01-01 00:00:00', '2026-01-01 00:00:00', ?, ?, 10, 1, ?)",
    )
    .bind(guid)
    .bind(&guid[..1])
    .bind(status)
    .execute(pool)
    .await?
    .last_insert_id() as i64;
    let reference = ReleaseRef {
        id,
        guid: guid.to_owned(),
    };
    let body = nzb::compress(&nzb_document(files))?;
    in_tx!(pool, 1, |tx| {
        nzb::put_locked(tx, &reference, &body, None, false).await
    })?;
    Ok(id)
}

fn settings(tmp: &Path, unrar: Option<&Path>) -> Settings {
    Settings {
        batch: 100,
        lowest_status: nfo::lowest_status(5),
        max_size: 0,
        min_size: 0,
        lookup_par2: false,
        alternate: false,
        unrar: unrar.map(Path::to_owned),
        tmp_path: tmp.to_owned(),
        command_timeout: Duration::from_secs(10),
    }
}

/// (nfostatus, completion, stored NFO)
async fn state(pool: &MySqlPool, id: i64) -> Result<(i64, f64, Option<String>)> {
    let (status, completion): (i64, f64) = sqlx::query_as(
        "SELECT CAST(nfostatus AS SIGNED), CAST(completion AS DOUBLE) FROM releases WHERE id = ?",
    )
    .bind(id)
    .fetch_one(pool)
    .await?;
    let stored: Option<Vec<u8>> =
        sqlx::query_scalar("SELECT UNCOMPRESS(nfo) FROM release_nfos WHERE releases_id = ?")
            .bind(id)
            .fetch_optional(pool)
            .await?;
    Ok((
        status,
        completion,
        stored.map(|nfo| String::from_utf8(nfo).expect("UTF-8")),
    ))
}

#[tokio::test]
async fn nfos_named_in_the_nzb_are_fetched_checked_and_stored() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    sqlx::query("UPDATE usenet_groups SET name = 'alt.binaries.nfotest' WHERE id = 1")
        .execute(pool)
        .await?;
    let server = serve(vec![
        ("nfo@x", article("Movie.nfo", NFO.as_bytes())),
        ("junk@x", article("Junk.nfo", &[0x01; 200])),
    ])
    .await;
    let tmp = tempfile::tempdir()?;
    let found = release(
        pool,
        "a1",
        nfo::NFO_UNPROC,
        &[
            ("\"Movie.2160p.nfo\" yEnc (1/1)", &["nfo@x"]),
            ("\"Movie.2160p.mkv\" yEnc (1/4)", &["m1@x", "m2@x"]),
        ],
    )
    .await?;
    let missing = release(
        pool,
        "b1",
        nfo::NFO_UNPROC,
        &[("\"Gone.nfo\" yEnc (1/1)", &["gone@x"])],
    )
    .await?;
    let junk = release(pool, "c1", -3, &[("\"Junk.nfo\" yEnc (1/1)", &["junk@x"])]).await?;
    // A one-file post with no NFO: no NFO, and nothing else about it changes.
    let none = release(
        pool,
        "d1",
        nfo::NFO_UNPROC,
        &[(
            "\"Show.S01E01.2160p.WEB-DL.mkv\" yEnc (1/3)",
            &["s1@x", "s2@x", "s3@x"],
        )],
    )
    .await?;
    let retired = release(pool, "e1", -7, &[("\"Old.nfo\" yEnc (1/1)", &["nfo@x"])]).await?;

    let mut service = NfoService::new(
        pool.clone(),
        settings(tmp.path(), None),
        client(server.port),
    );
    assert_eq!(service.process_nfo_files(None, "").await?, 1);

    assert_eq!(
        state(pool, found).await?,
        (nfo::NFO_FOUND, 60.0, Some(NFO.to_owned()))
    );
    // A failed download takes one retry.
    assert_eq!(state(pool, missing).await?, (-2, 100.0, None));
    assert_eq!(state(pool, junk).await?.0, nfo::NFO_NONFO);
    assert_eq!(state(pool, none).await?, (nfo::NFO_NONFO, 100.0, None));
    // Past the retry limit, the release isn't looked at.
    assert_eq!(state(pool, retired).await?, (-7, 0.0, None));

    // A bucket filter leaves the others.
    sqlx::query("UPDATE releases SET nfostatus = -1 WHERE id = ?")
        .bind(missing)
        .execute(pool)
        .await?;
    assert_eq!(service.process_nfo_files(None, "z").await?, 0);
    assert_eq!(state(pool, missing).await?.0, -1);
    db.drop_database().await
}

/// A stand-in unrar that writes `$NFO_SCRIPT_BODY` under the requested name, or nothing.
fn scripted_unrar(directory: &Path, content: Option<&str>) -> Result<std::path::PathBuf> {
    let path = directory.join("unrar");
    let body = match content {
        Some(content) => format!(
            "#!/bin/sh\n# e -y -c- -inul -p- -@ -- archive name dest/\nname=$(basename \"$9\")\nprintf '%s' '{}' > \"${{10}}$name\"\n",
            content.replace('\'', "")
        ),
        None => "#!/bin/sh\nexit 1\n".to_owned(),
    };
    std::fs::write(&path, body)?;
    std::fs::set_permissions(&path, std::fs::Permissions::from_mode(0o755))?;
    Ok(path)
}

#[tokio::test]
async fn an_nfo_inside_the_archive_is_the_fallback() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let server = serve(vec![(
        "r1@x",
        article("Movie.part01.rar", b"Rar!\x1a\x07\x00 fake"),
    )])
    .await;
    let tmp = tempfile::tempdir()?;
    let scripts = tempfile::tempdir()?;
    let files: &[(&str, &[&str])] = &[("\"Movie.2160p.part01.rar\" yEnc (1/1)", &["r1@x"])];

    let failed = release(pool, "a1", nfo::NFO_FAILED, files).await?;
    let hopeless = release(pool, "b1", nfo::NFO_FAILED, files).await?;
    let empty = release(pool, "c1", nfo::NFO_FAILED, files).await?;
    for (id, name, size) in [
        (failed, "Movie.2160p.nfo", 900),
        (hopeless, "Other.nfo", 900),
        (empty, "Empty.nfo", 0),
    ] {
        sqlx::query("INSERT INTO release_files (releases_id, name, size) VALUES (?, ?, ?)")
            .bind(id)
            .bind(name)
            .bind(size)
            .execute(pool)
            .await?;
    }

    let unrar = scripted_unrar(scripts.path(), Some(NFO))?;
    let mut service = NfoService::new(
        pool.clone(),
        settings(tmp.path(), Some(&unrar)),
        client(server.port),
    );
    let extracted = service.attempt_nfo_from_archive("a1", failed).await?;
    assert_eq!(extracted.as_deref(), Some(NFO.as_bytes()));
    // An NFO listed with no content: no NFO, with no download.
    assert_eq!(service.attempt_nfo_from_archive("c1", empty).await?, None);
    assert_eq!(state(pool, empty).await?.0, nfo::NFO_NONFO);

    // The pass over releases out of retries stores one, and retires one that yields nothing.
    sqlx::query("UPDATE releases SET nfostatus = ? WHERE id = ?")
        .bind(nfo::NFO_FAILED)
        .bind(empty)
        .execute(pool)
        .await?;
    let broken = scripted_unrar(scripts.path(), None)?;
    let mut service = NfoService::new(
        pool.clone(),
        settings(tmp.path(), Some(&broken)),
        client(server.port),
    );
    assert_eq!(service.process_nfo_files(None, "").await?, 0);
    assert_eq!(state(pool, failed).await?.0, nfo::NFO_FAILED_ARCHIVE);
    assert_eq!(state(pool, hopeless).await?.0, nfo::NFO_FAILED_ARCHIVE);
    // Its listed NFO is empty, so it isn't picked for the archive pass at all.
    assert_eq!(state(pool, empty).await?.0, nfo::NFO_FAILED);
    // Nothing is left behind in the temporary directory.
    assert_eq!(std::fs::read_dir(tmp.path())?.count(), 0);
    db.drop_database().await
}

#[tokio::test]
async fn an_alternate_nfo_is_kept_once() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let tmp = tempfile::tempdir()?;
    let id = release(
        pool,
        "a1",
        nfo::NFO_UNPROC,
        &[("\"x.mkv\" yEnc (1/2)", &["x1@x"])],
    )
    .await?;
    let mut service = NfoService::new(pool.clone(), settings(tmp.path(), None), client(1));
    assert!(
        !service
            .add_alternate_nfo(b"not an nfo", id, "a1", 0.0)
            .await?
    );
    assert!(
        service
            .add_alternate_nfo(NFO.as_bytes(), id, "a1", 0.0)
            .await?
    );
    assert_eq!(
        state(pool, id).await?,
        (nfo::NFO_FOUND, 50.0, Some(NFO.to_owned()))
    );
    let other = format!("{NFO}\nGreets to everyone.\n");
    assert!(
        service
            .add_alternate_nfo(other.as_bytes(), id, "a1", 50.0)
            .await?
    );
    assert_eq!(state(pool, id).await?.2, Some(NFO.to_owned()));
    db.drop_database().await
}
