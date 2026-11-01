//! The maintenance commands against a real database.

use anyhow::Result;
use nntmux_releases::delete::{self, Criterion, DeleteArgs};
use nntmux_releases::import_database::{self, Imported};
use nntmux_releases::{archive_retry, crap, nzb_clean};
use nntmux_testkit::TestDb;
use nntmux_verify::nzb;
use sqlx::MySqlPool;

/// A release with enough columns for each command; `extra` is more `column = value` assignments.
async fn release(pool: &MySqlPool, guid: &str, searchname: &str, extra: &str) -> Result<i64> {
    let id = sqlx::query(
        "INSERT INTO releases (name, searchname, fromname, groups_id, guid, leftguid, size, adddate, postdate, \
         totalpart, categories_id, nzbstatus, iscategorized) \
         VALUES (?, ?, 'poster@example.com', 1, ?, 'a', 500000000, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 5, 2030, 1, 1)",
    )
    .bind(searchname)
    .bind(searchname)
    .bind(guid)
    .execute(pool)
    .await?
    .last_insert_id() as i64;
    if !extra.is_empty() {
        sqlx::query(sqlx::AssertSqlSafe(format!(
            "UPDATE releases SET {extra} WHERE id = ?"
        )))
        .bind(id)
        .execute(pool)
        .await?;
    }
    Ok(id)
}

async fn file(pool: &MySqlPool, release: i64, name: &str) -> Result<()> {
    sqlx::query("INSERT INTO release_files (releases_id, name, size) VALUES (?, ?, 1)")
        .bind(release)
        .bind(name)
        .execute(pool)
        .await?;
    Ok(())
}

async fn guids(pool: &MySqlPool) -> Result<Vec<String>> {
    Ok(
        sqlx::query_scalar("SELECT guid FROM releases ORDER BY guid")
            .fetch_all(pool)
            .await?,
    )
}

async fn seed_group(pool: &MySqlPool) -> Result<()> {
    sqlx::query("DELETE FROM usenet_groups WHERE id <> 1 AND name = 'alt.binaries.test'")
        .execute(pool)
        .await?;
    sqlx::query(
        "INSERT INTO usenet_groups (id, name, active, backfill) VALUES (1, 'alt.binaries.test', 1, 0) \
         ON DUPLICATE KEY UPDATE name = VALUES(name)",
    )
    .execute(pool)
    .await?;
    Ok(())
}

fn options(kind: &str, delete: bool) -> crap::Options {
    crap::Options {
        delete,
        time: "full".to_owned(),
        kind: kind.to_owned(),
        blacklist_id: None,
    }
}

#[tokio::test]
async fn crap_removal_previews_then_deletes_each_shape() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    seed_group(pool).await?;
    let keep = "Good.Show.S01E01.1080p-GROUP";
    let unlisted = "keep0000";
    release(pool, unlisted, keep, "nfostatus = 1").await?;
    let exe = release(pool, "exe00000", "Movie.With.Exe", "").await?;
    file(pool, exe, "setup.exe").await?;
    file(pool, exe, "second.exe").await?;
    release(
        pool,
        "gibb0000",
        "abcdefghij1234567890",
        "nfostatus = 0, rarinnerfilecount = 0",
    )
    .await?;
    release(pool, "pass0000", "Some.Passworded.Thing", "nfostatus = 1").await?;
    release(pool, "nopw0000", "No.Password.Recovery", "nfostatus = 1").await?;
    let par = release(pool, "par20000", "Name.vol001+02.par2", "nfostatus = 1").await?;
    file(pool, par, "Name.vol001+02.par2").await?;
    let mixed = release(pool, "mixe0000", "Mixed.par2", "nfostatus = 1").await?;
    file(pool, mixed, "Mixed.par2").await?;
    file(pool, mixed, "Mixed.mkv").await?;
    let codec = release(
        pool,
        "code0000",
        "Codec.Movie",
        "nfostatus = 1, haspreview = 0, jpgstatus = 0, predb_id = 0, videostatus = 0, imdbid = '123'",
    )
    .await?;
    file(pool, codec, r"Stuff\Codec\Setup.exe").await?;

    // A preview deletes nothing and counts each release once, whatever its file count.
    assert_eq!(crap::remove(pool, &options("executable", false)).await?, 2);
    assert_eq!(guids(pool).await?.len(), 8);

    assert_eq!(crap::remove(pool, &options("executable", true)).await?, 2);
    assert_eq!(crap::remove(pool, &options("gibberish", true)).await?, 1);
    assert_eq!(crap::remove(pool, &options("passworded", true)).await?, 1);
    assert_eq!(crap::remove(pool, &options("par2only", true)).await?, 1);
    assert_eq!(guids(pool).await?, vec!["keep0000", "mixe0000", "nopw0000"]);

    // Named types only, with a usable time filter even for the grouped queries.
    let timed = crap::Options {
        time: "24".to_owned(),
        ..options("wmv_all", false)
    };
    assert_eq!(crap::remove(pool, &timed).await?, 0);
    let timed = crap::Options {
        time: "24".to_owned(),
        ..options("codec", false)
    };
    assert_eq!(crap::remove(pool, &timed).await?, 0);

    let wrong = crap::remove(pool, &options("nonsense", false)).await;
    assert!(wrong.is_err());
    let bad_time = crap::Options {
        time: "1; DROP TABLE releases".to_owned(),
        ..options("short", false)
    };
    assert!(crap::remove(pool, &bad_time).await.is_err());

    // A bare run covers every default type without failing on any query.
    crap::remove(pool, &options("", false)).await?;
    Ok(())
}

#[tokio::test]
async fn blacklist_rules_apply_by_field_and_group() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    seed_group(pool).await?;
    sqlx::query("DELETE FROM binaryblacklist")
        .execute(pool)
        .await?;
    let rule = "INSERT INTO binaryblacklist (id, groupname, regex, msgcol, optype, status) VALUES (?, ?, ?, ?, 1, ?)";
    for (id, group, regex, field, status) in [
        (1, "alt.binaries.*", "spamname", 1, 1),
        (2, "^alt\\.binaries\\.test$", "badposter", 2, 1),
        (3, "^alt\\.binaries\\.other$", "keepname", 1, 1),
        (4, "alt.binaries.*", "disabledname", 1, 0),
        (5, "alt.binaries.*", "(unclosed", 1, 1),
        (6, "alt.binaries.*", "evilfile", 1, 1),
    ] {
        sqlx::query(rule)
            .bind(id)
            .bind(group)
            .bind(regex)
            .bind(field)
            .bind(status)
            .execute(pool)
            .await?;
    }
    release(pool, "spam0000", "A.SpamName.Release", "").await?;
    release(pool, "post0000", "Plain", "fromname = 'BadPoster'").await?;
    release(pool, "keep0000", "KeepName", "").await?;
    release(pool, "disa0000", "DisabledName", "").await?;
    let with_file = release(pool, "file0000", "Innocent", "").await?;
    file(pool, with_file, "evilfile.bin").await?;

    assert_eq!(crap::remove(pool, &options("blacklist", false)).await?, 2);

    // A disabled rule can be previewed by ID, but can't delete.
    let preview = crap::Options {
        blacklist_id: Some("4".to_owned()),
        ..options("blacklist", false)
    };
    assert_eq!(crap::remove(pool, &preview).await?, 1);
    let delete = crap::Options {
        blacklist_id: Some("4".to_owned()),
        ..options("blacklist", true)
    };
    assert_eq!(crap::remove(pool, &delete).await?, 0);

    assert_eq!(crap::remove(pool, &options("blacklist", true)).await?, 2);
    assert_eq!(crap::remove(pool, &options("blfiles", true)).await?, 1);
    assert_eq!(guids(pool).await?, vec!["disa0000", "keep0000"]);
    Ok(())
}

#[tokio::test]
async fn delete_criteria_fail_closed() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    seed_group(pool).await?;
    release(pool, "aaaa0000", "Spam One", "size = 100").await?;
    release(pool, "bbbb0000", "Other Spam", "size = 900").await?;
    release(pool, "cccc0000", "Keep", "size = 900, imdbid = NULL").await?;

    let args = DeleteArgs {
        group: Some("alt.binaries.missing".to_owned()),
        ..DeleteArgs::default()
    };
    let filter = delete::build_filter(pool, &args.criteria()?).await?;
    assert_eq!(filter.count(pool).await?, 0);

    for bad in [
        "rageid=equals=1",
        "name=sounds=x",
        "size=bigger=1; DROP TABLE releases",
    ] {
        let criteria: Vec<Criterion> = Criterion::parse(bad)?.into_iter().collect();
        assert!(
            delete::build_filter(pool, &criteria).await.is_err(),
            "{bad}"
        );
    }
    assert!(Criterion::parse("ignore")?.is_none());
    assert!(Criterion::parse("name=equals").is_err());
    assert!(Criterion::parse("name=equals=a=b").is_err());

    let args = DeleteArgs {
        search_like: Some("spam".to_owned()),
        size_min: Some("500".to_owned()),
        ..DeleteArgs::default()
    };
    let filter = delete::build_filter(pool, &args.criteria()?).await?;
    assert_eq!(filter.count(pool).await?, 1);
    assert_eq!(delete::delete_matching(pool, &filter).await?, 1);
    assert_eq!(guids(pool).await?, vec!["aaaa0000", "cccc0000"]);

    let advanced = DeleteArgs {
        criteria: vec![
            "ignore".to_owned(),
            "groupname=equals=\"alt.binaries.test\"".to_owned(),
            "imdbid=equals=NULL".to_owned(),
            "adddate=smaller=24".to_owned(),
        ],
        ..DeleteArgs::default()
    };
    let filter = delete::build_filter(pool, &advanced.criteria()?).await?;
    assert_eq!(filter.count(pool).await?, 2);
    Ok(())
}

#[tokio::test]
async fn archive_retry_queues_once_within_bounds() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    seed_group(pool).await?;
    sqlx::query("UPDATE categories SET disablepreview = 0 WHERE id = 2030")
        .execute(pool)
        .await?;
    sqlx::query(
        "UPDATE post_processing_configurations SET min_size_to_post_process = 1000, max_size_to_post_process = 0",
    )
    .execute(pool)
    .await?;
    let eligible = "passwordstatus = -1, haspreview = 0";
    release(pool, "rar00000", "x.part01.rar", eligible).await?;
    sqlx::query("UPDATE releases SET name = 'x.part01.rar' WHERE guid = 'rar00000'")
        .execute(pool)
        .await?;
    release(pool, "7z000000", "y.7z.001", eligible).await?;
    release(pool, "zip00000", "z.zip", eligible).await?;
    release(pool, "none0000", "plain", eligible).await?;
    release(
        pool,
        "smal0000",
        "tiny.rar",
        "passwordstatus = -1, haspreview = 0, size = 10",
    )
    .await?;
    release(
        pool,
        "done0000",
        "done.rar",
        "passwordstatus = 0, haspreview = 0",
    )
    .await?;

    let counts = archive_retry::preview(pool, 0).await?;
    assert_eq!(
        (counts.seven_zip, counts.rar, counts.zip, counts.unknown),
        (1, 1, 1, 1)
    );
    assert_eq!(archive_retry::preview(pool, 2).await?.total(), 2);

    assert_eq!(archive_retry::queue(pool, 1).await?, 1);
    assert_eq!(archive_retry::queue(pool, 0).await?, 3);
    assert_eq!(archive_retry::queue(pool, 0).await?, 0);
    let (queued,): (i64,) = sqlx::query_as(
        "SELECT COUNT(*) FROM releases WHERE haspreview = -1 AND archive_retry_at IS NOT NULL",
    )
    .fetch_one(pool)
    .await?;
    assert_eq!(queued, 4);
    Ok(())
}

#[tokio::test]
async fn import_resumes_keeps_originals_and_stops_at_the_first_failure() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    seed_group(pool).await?;
    let root = std::env::temp_dir().join(format!("nn-import-{}", rand_suffix()));
    std::fs::create_dir_all(root.join("g"))?;
    let body = nzb::compress(b"<nzb></nzb>")?;
    let first = release(pool, "guid0001", "one", "").await?;
    let second = release(pool, "guid0002", "two", "").await?;
    let third = release(pool, "guid0003", "three", "").await?;
    std::fs::write(root.join("g/guid0001.nzb.gz"), &body)?;
    std::fs::write(root.join("guid0002.nzb.gz"), &body)?;

    let options = |dry_run| import_database::Options {
        roots: vec![root.clone()],
        limit: 2,
        after_id: None,
        name: "test".to_owned(),
        dry_run,
    };
    let dry = import_database::import(pool, &options(true)).await?;
    assert_eq!(
        dry,
        Imported {
            copied: 2,
            existing: 0,
            cursor: second
        }
    );
    assert!(!nzb::exists(pool, "guid0001").await?);

    let done = import_database::import(pool, &options(false)).await?;
    assert_eq!((done.copied, done.cursor), (2, second));
    assert!(nzb::exists(pool, "guid0001").await?);
    assert!(root.join("g/guid0001.nzb.gz").exists());
    let queued: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM release_fingerprint_metadata")
        .fetch_one(pool)
        .await?;
    assert_eq!(queued, 0, "history adds no fingerprint work");

    // The next run resumes after the checkpoint and stops at the missing file.
    let failure = import_database::import(pool, &options(false))
        .await
        .err()
        .map(|error| format!("{error:#}"))
        .unwrap_or_default();
    assert!(
        failure.contains(&format!("Stopped at release {third}"))
            && failure.contains(&format!("Last committed release ID: {second}")),
        "{failure}"
    );

    // Running from the start again finds them stored.
    let replay = import_database::import(
        pool,
        &import_database::Options {
            after_id: Some(first - 1),
            ..options(false)
        },
    )
    .await?;
    assert_eq!((replay.copied, replay.existing), (0, 2));

    // A source differing from committed naming evidence is refused.
    std::fs::write(root.join("guid0003.nzb.gz"), nzb::compress(b"<nzb/>")?)?;
    sqlx::query(
        "INSERT INTO release_archive_naming_evidence (releases_id, release_guid, source_sha256, proof_version, scope, episode_files, updated_at) \
         VALUES (?, 'guid0003', ?, 1, 'release', '[]', UTC_TIMESTAMP())",
    )
    .bind(third)
    .bind("0".repeat(64))
    .execute(pool)
    .await?;
    let refused = import_database::import(
        pool,
        &import_database::Options {
            after_id: Some(second),
            ..options(false)
        },
    )
    .await;
    assert!(refused.is_err());
    std::fs::remove_dir_all(root)?;
    Ok(())
}

fn rand_suffix() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map_or(0, |elapsed| elapsed.as_nanos() as u64)
}

#[tokio::test]
async fn orphans_are_reported_and_kept() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    seed_group(pool).await?;
    release(pool, "here0000", "x", "").await?;
    let root = std::env::temp_dir().join(format!("nn-orphans-{}", rand_suffix()));
    std::fs::create_dir_all(root.join("h"))?;
    std::fs::write(root.join("h/here0000.nzb.gz"), b"x")?;
    std::fs::write(root.join("h/gone0000.nzb.gz"), b"x")?;
    std::fs::write(root.join("h/notes.txt"), b"x")?;
    let found = nzb_clean::orphans(pool, &root).await?;
    assert_eq!((found.checked, found.orphans), (2, 1));
    assert!(root.join("h/gone0000.nzb.gz").exists());

    let refused = nzb_clean::Options {
        root: root.clone(),
        not_in_db: false,
        not_on_disk: true,
        temps: false,
        temp_age_hours: 24,
        delete: true,
    };
    assert!(nzb_clean::run(pool, &refused).await.is_err());
    std::fs::remove_dir_all(root)?;
    Ok(())
}
