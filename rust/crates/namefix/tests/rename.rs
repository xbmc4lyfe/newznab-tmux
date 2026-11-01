//! The updater and the source passes against a real database.

use std::sync::Arc;

use async_trait::async_trait;
use nntmux_categorize::{Categorizer, cat};
use nntmux_namefix::queries::{self, FixNamesConfig, PredbTitle, Source};
use nntmux_namefix::release::Release;
use nntmux_namefix::service::{
    CandidatePage, CandidateSearch, NameFixer, Par2Outcome, Par2Probe, has_literal_predb_title,
};
use nntmux_namefix::update::{NoSearch, ReleaseUpdater};
use nntmux_predb::filename::fixer_cleaner;
use nntmux_predb::identity::{self, InsertOutcome, NewPredb};
use nntmux_testkit::TestDb;
use sqlx::MySqlPool;

const GUID: &str = "0123456789abcdef0123456789abcdef01234567";

async fn insert_release(
    pool: &MySqlPool,
    id: i64,
    guid: &str,
    name: &str,
    searchname: &str,
    size: i64,
) {
    let columns: Vec<(String, String)> = sqlx::query_as(
        "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() \
         AND TABLE_NAME = 'releases' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'",
    )
    .fetch_all(pool)
    .await
    .expect("columns");
    let mut names = vec![
        "id".to_owned(),
        "guid".to_owned(),
        "leftguid".to_owned(),
        "name".to_owned(),
        "searchname".to_owned(),
        "size".to_owned(),
        "categories_id".to_owned(),
        "groups_id".to_owned(),
    ];
    let mut values = vec![
        id.to_string(),
        format!("'{guid}'"),
        format!("'{}'", &guid[..1]),
        format!("'{name}'"),
        format!("'{searchname}'"),
        size.to_string(),
        cat::OTHER_MISC.to_string(),
        "0".to_owned(),
    ];
    for (column, kind) in columns {
        if names.contains(&column) {
            continue;
        }
        names.push(format!("`{column}`"));
        values.push(if kind.contains("int") {
            "0".to_owned()
        } else if kind.contains("date") || kind.contains("time") {
            "NOW()".to_owned()
        } else {
            format!("'{id}'")
        });
    }
    sqlx::query(sqlx::AssertSqlSafe(format!(
        "INSERT INTO releases ({}) VALUES ({})",
        names.join(", "),
        values.join(", ")
    )))
    .execute(pool)
    .await
    .expect("release");
}

fn release(id: i64, guid: &str, name: &str, searchname: &str, size: i64) -> Release {
    Release {
        id,
        guid: Some(guid.to_owned()),
        name: Some(name.to_owned()),
        searchname: Some(searchname.to_owned()),
        categories_id: cat::OTHER_MISC,
        relsize: size,
        ..Release::default()
    }
}

async fn updater(pool: &MySqlPool) -> ReleaseUpdater {
    let categorizer = Categorizer::load(pool).await.expect("categorizer");
    ReleaseUpdater::new(pool.clone(), categorizer, Arc::new(NoSearch), fixer_cleaner)
}

async fn fixer(pool: &MySqlPool) -> NameFixer {
    NameFixer::new(updater(pool).await, FixNamesConfig::from_env())
}

async fn row(pool: &MySqlPool, id: i64) -> (String, i64, i32, i32) {
    sqlx::query_as("SELECT searchname, CAST(predb_id AS SIGNED), isrenamed, categories_id FROM releases WHERE id = ?")
        .bind(id)
        .fetch_one(pool)
        .await
        .expect("row")
}

async fn predb(pool: &MySqlPool, title: &str) -> i64 {
    let row = NewPredb {
        title: title.to_owned(),
        source: "t".to_owned(),
        ..NewPredb::default()
    };
    match identity::insert_feed(pool, &row).await.expect("predb") {
        InsertOutcome::Inserted(id) => id as i64,
        InsertOutcome::Rejected(error) => panic!("{error}"),
    }
}

async fn certify(pool: &MySqlPool) {
    let (generation, _, _) = identity::rebuild_start(pool).await.expect("start");
    loop {
        let (_, _, done) = identity::rebuild_chunk(pool, &generation, 128)
            .await
            .expect("chunk");
        if done {
            break;
        }
    }
    identity::rebuild_certify(pool, &generation)
        .await
        .expect("certify");
}

const SCENE: &str = "Some.Movie.2021.1080p.BluRay.x264-GROUP";

#[tokio::test]
async fn renames_recategorizes_and_refuses_a_stale_snapshot() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    insert_release(pool, 1, GUID, "abc", "abc", 100).await;
    let mut updater = updater(pool).await;

    // A preview writes nothing.
    let candidate = release(1, GUID, "abc", "abc", 100);
    updater
        .update_release(
            &candidate, SCENE, "test", false, "NFO, ", true, 0, None, None,
        )
        .await
        .expect("update");
    assert!(updater.matched);
    assert_eq!(row(pool, 1).await.0, "abc");

    // A stale view of the release writes nothing either.
    let stale = release(1, GUID, "abc", "something else", 100);
    updater
        .update_release(&stale, SCENE, "test", true, "NFO, ", true, 0, None, None)
        .await
        .expect("update");
    assert!(!updater.matched);
    assert_eq!(row(pool, 1).await.0, "abc");

    updater
        .update_release(
            &candidate, SCENE, "test", true, "NFO, ", true, 0, None, None,
        )
        .await
        .expect("update");
    assert!(updater.matched);
    let (name, predb_id, renamed, category) = row(pool, 1).await;
    assert_eq!((name.as_str(), predb_id, renamed), (SCENE, 0, 1));
    assert_ne!(category, cat::OTHER_MISC, "recategorized after the rename");
    let identity = updater.last_committed_identity().expect("identity");
    assert_eq!(identity.after.searchname.as_deref(), Some(SCENE));

    // A hash-like candidate from an untrusted source isn't plausible.
    insert_release(
        pool,
        2,
        "1123456789abcdef0123456789abcdef01234567",
        "x",
        "x",
        100,
    )
    .await;
    let other = release(2, "1123456789abcdef0123456789abcdef01234567", "x", "x", 100);
    updater
        .update_release(
            &other,
            "0123456789abcdef0123456789abcdef",
            "test",
            true,
            "NFO, ",
            true,
            0,
            None,
            None,
        )
        .await
        .expect("update");
    assert!(!updater.matched);
    assert_eq!(row(pool, 2).await.0, "x");
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn confirmed_titles_keep_catalogue_bytes_and_season_packs_stay_packs() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    let predb_id = predb(pool, SCENE).await;
    insert_release(pool, 1, GUID, "abc", "abc", 100).await;
    let mut updater = updater(pool).await;
    let candidate = release(1, GUID, "abc", "abc", 100);

    // The title must be the row's; a different one is refused.
    updater
        .update_release(
            &candidate,
            "Other.Title.2021-GROUP",
            "m",
            true,
            "NFO, ",
            true,
            predb_id,
            None,
            None,
        )
        .await
        .expect("update");
    assert!(!updater.matched);

    updater
        .update_release(
            &candidate,
            &SCENE.to_lowercase(),
            "m",
            true,
            "NFO, ",
            true,
            predb_id,
            None,
            None,
        )
        .await
        .expect("update");
    assert!(updater.matched);
    assert_eq!(row(pool, 1).await.0, SCENE);
    assert_eq!(row(pool, 1).await.1, predb_id);

    // A whole season is never renamed to one of its episodes.
    let episode = "Show.Name.S01E01.1080p.WEB-DL-GROUP";
    let episode_id = predb(pool, episode).await;
    let (pack_guid, pack) = (
        "2123456789abcdef0123456789abcdef01234567",
        "Show.Name.S01.1080p.WEB-DL-GROUP",
    );
    insert_release(pool, 2, pack_guid, pack, pack, 100).await;
    let candidate = release(2, pack_guid, pack, pack, 100);
    updater
        .update_release(
            &candidate, episode, "m", true, "NFO, ", true, episode_id, None, None,
        )
        .await
        .expect("update");
    assert!(!updater.matched);
    assert_eq!(row(pool, 2).await.0, pack);

    // An episode name needs the archive evidence to agree.
    let guid = "3123456789abcdef0123456789abcdef01234567";
    insert_release(pool, 3, guid, "abc", "abc", 100).await;
    sqlx::query("INSERT INTO release_files (releases_id, name, size) VALUES (3, 'Show.Name.S01E01.mkv', 1), (3, 'Show.Name.S01E02.mkv', 1)")
        .execute(pool)
        .await
        .expect("files");
    let candidate = release(3, guid, "abc", "abc", 100);
    updater
        .update_release(
            &candidate, episode, "m", true, "NFO, ", true, episode_id, None, None,
        )
        .await
        .expect("update");
    assert!(!updater.matched, "the files show a pack");
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn nfo_pass_links_a_literal_title_and_marks_the_rest() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    let predb_id = predb(pool, SCENE).await;
    insert_release(pool, 1, GUID, "abc", "abc", 100).await;
    let other = "4123456789abcdef0123456789abcdef01234567";
    insert_release(pool, 2, other, "def", "def", 100).await;
    sqlx::query("UPDATE releases SET nfostatus = 1, nzbstatus = 1 WHERE id IN (1, 2)")
        .execute(pool)
        .await
        .expect("status");
    for (id, text) in [
        (1, format!("Release: {SCENE}\nmore text\n")),
        (2, "nothing of note here at all\n".to_owned()),
    ] {
        sqlx::query("INSERT INTO release_nfos (releases_id, nfo) VALUES (?, COMPRESS(?))")
            .bind(id)
            .bind(text)
            .execute(pool)
            .await
            .expect("nfo");
    }
    let mut fixer = fixer(pool).await;
    fixer
        .fix_names_with_nfo(2, true, 2, true, false)
        .await
        .expect("pass");
    assert_eq!(row(pool, 1).await.0, SCENE);
    assert_eq!(row(pool, 1).await.1, predb_id);
    let proc_nfo: i32 = sqlx::query_scalar("SELECT proc_nfo FROM releases WHERE id = 2")
        .fetch_one(pool)
        .await
        .expect("proc");
    assert_eq!(proc_nfo, 1);
    assert_eq!(row(pool, 2).await.0, "def");
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn file_pass_takes_certified_matches_and_never_acts_on_unknown() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    let predb_id = predb(pool, SCENE).await;
    insert_release(pool, 1, GUID, "abc", "abc", 100).await;
    sqlx::query("INSERT INTO release_files (releases_id, name, size) VALUES (1, ?, 1)")
        .bind(format!("{SCENE}.mkv"))
        .execute(pool)
        .await
        .expect("file");

    // Before certification the lookup is unknown: no rename, and the source stays pending.
    let mut fixer = fixer(pool).await;
    fixer
        .fix_names_with_files(2, true, 2, true, false)
        .await
        .expect("pass");
    assert_eq!(row(pool, 1).await.0, "abc");
    let proc_files: i32 = sqlx::query_scalar("SELECT proc_files FROM releases WHERE id = 1")
        .fetch_one(pool)
        .await
        .expect("proc");
    assert_eq!(proc_files, 0);

    certify(pool).await;
    fixer
        .fix_names_with_files(2, true, 2, true, false)
        .await
        .expect("pass");
    assert_eq!(row(pool, 1).await.0, SCENE);
    assert_eq!(row(pool, 1).await.1, predb_id);
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn crc_donors_within_tolerance_lend_their_name() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    let predb_id = predb(pool, SCENE).await;
    let donor_guid = "5123456789abcdef0123456789abcdef01234567";
    insert_release(pool, 1, donor_guid, SCENE, SCENE, 1000).await;
    sqlx::query("UPDATE releases SET predb_id = ? WHERE id = 1")
        .bind(predb_id)
        .execute(pool)
        .await
        .expect("link");
    insert_release(pool, 2, GUID, "abc", "abc", 1040).await;
    let far = "6123456789abcdef0123456789abcdef01234567";
    insert_release(pool, 3, far, "def", "def", 5000).await;
    for id in [1, 2, 3] {
        sqlx::query("INSERT INTO release_files (releases_id, name, size, crc32) VALUES (?, 'a.mkv', 1, 'ABCD1234')")
            .bind(id)
            .execute(pool)
            .await
            .expect("file");
    }
    let mut fixer = fixer(pool).await;
    fixer
        .fix_names_with_crc(2, true, 2, true, false)
        .await
        .expect("pass");
    assert_eq!(row(pool, 2).await.0, SCENE);
    assert_eq!(row(pool, 2).await.1, predb_id);
    assert_eq!(row(pool, 3).await.0, "def", "outside the size tolerance");
    let proc_crc: i32 = sqlx::query_scalar("SELECT proc_crc32 FROM releases WHERE id = 3")
        .fetch_one(pool)
        .await
        .expect("proc");
    assert_eq!(proc_crc, 1);
    db.drop_database().await.expect("drop");
}

struct Pages(Vec<i64>);

#[async_trait]
impl CandidateSearch for Pages {
    async fn release_candidates_page(
        &self,
        _title: &str,
        limit: u64,
        after: i64,
    ) -> anyhow::Result<CandidatePage> {
        let ids: Vec<i64> = self
            .0
            .iter()
            .copied()
            .filter(|id| *id > after)
            .take(limit as usize)
            .collect();
        let has_more = ids.len() as u64 == limit;
        Ok(CandidatePage { ids, has_more })
    }
}

#[tokio::test]
async fn fulltext_renames_literal_candidates_only() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    let predb_id = predb(pool, SCENE).await;
    insert_release(
        pool,
        1,
        GUID,
        &format!("[01/20] - \"{SCENE}.part01.rar\" yEnc"),
        "abc",
        100,
    )
    .await;
    let other = "7123456789abcdef0123456789abcdef01234567";
    insert_release(pool, 2, other, &format!("{SCENE}-EXTRA"), "def", 100).await;
    let mut fixer = fixer(pool).await;
    let pre = PredbTitle {
        predb_id: predb_id as u64,
        title: SCENE.to_owned(),
        source: "t".to_owned(),
        searched: 0,
    };
    let outcome = fixer
        .match_predb_fulltext(&pre, &Pages(vec![1, 2]))
        .await
        .expect("match");
    assert_eq!(outcome.updated, 1);
    assert!(outcome.matched);
    assert!(!outcome.retry);
    assert_eq!(row(pool, 1).await.0, SCENE);
    assert_eq!(row(pool, 2).await.0, "def");
    db.drop_database().await.expect("drop");
}

#[test]
fn literal_titles_need_delimiters() {
    let title = "Some.Movie.2021-GROUP";
    assert!(has_literal_predb_title("some.movie.2021-group", title));
    assert!(has_literal_predb_title(
        "[1/9] \"Some.Movie.2021-GROUP.part01.rar\" yEnc",
        title
    ));
    assert!(has_literal_predb_title(
        "x Some.Movie.2021-GROUP.mkv y",
        title
    ));
    assert!(!has_literal_predb_title(
        "xSome.Movie.2021-GROUP.mkv",
        title
    ));
    assert!(!has_literal_predb_title(
        "Some.Movie.2021-GROUP-EXTRA",
        title
    ));
    assert!(!has_literal_predb_title(
        "Some.Movie.2021-GROUP.unknownext",
        title
    ));
}

struct Deferred;

#[async_trait]
impl Par2Probe for Deferred {
    async fn probe(&mut self, _release: &Release) -> anyhow::Result<Par2Outcome> {
        Ok(Par2Outcome::Deferred)
    }
}

#[tokio::test]
async fn standard_batch_walks_sources_and_marks_them_done() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    insert_release(pool, 1, GUID, "abc", "abc", 100).await;
    sqlx::query("UPDATE releases SET passwordstatus = 0, nfostatus = 0 WHERE id = 1")
        .execute(pool)
        .await
        .expect("status");
    certify(pool).await;
    let config = FixNamesConfig::from_env();
    let batch = queries::standard_candidate_batch(
        pool,
        &FixNamesConfig {
            category: "all".to_owned(),
            ..config
        },
        "0",
        10,
        None,
    )
    .await
    .expect("batch");
    assert_eq!(batch.len(), 1);
    let mut fixer = fixer(pool).await;
    let (checked, fixed) = fixer
        .process_standard_batch(batch, false, &mut Deferred)
        .await
        .expect("batch");
    assert_eq!((checked, fixed), (1, 0));
    let flags: (i32, i32, i32, i32, i32) = sqlx::query_as(
        "SELECT proc_uid, proc_crc32, proc_srr, proc_hash16k, proc_par2 FROM releases WHERE id = 1",
    )
    .fetch_one(pool)
    .await
    .expect("flags");
    assert_eq!(
        flags,
        (1, 1, 1, 1, 0),
        "a deferred PAR2 probe stays pending"
    );
    assert_eq!(
        queries::count_candidates(pool, Source::Files, 2, 2)
            .await
            .expect("count"),
        0
    );
    db.drop_database().await.expect("drop");
}
