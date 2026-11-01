//! The identity index against a real database: triggers, certification and invalidation.

use nntmux_predb::filename::OriginalFilenameEvidence;
use nntmux_predb::identity::*;
use nntmux_testkit::TestDb;
use sqlx::MySqlPool;

fn row(title: &str) -> NewPredb {
    NewPredb {
        title: title.to_owned(),
        source: "test".to_owned(),
        ..NewPredb::default()
    }
}

async fn rebuild(pool: &MySqlPool) {
    let (generation, _, _) = rebuild_start(pool).await.expect("start");
    loop {
        let (_, _, exhausted) = rebuild_chunk(pool, &generation, 2).await.expect("chunk");
        if exhausted {
            break;
        }
    }
    rebuild_certify(pool, &generation).await.expect("certify");
}

async fn find(pool: &MySqlPool, filename: &str) -> Lookup {
    lookup_many(pool, &[OriginalFilenameEvidence::new(filename)])
        .await
        .remove(0)
}

#[tokio::test]
async fn lookup_is_unknown_until_certified_and_stays_certified_through_writes() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    let InsertOutcome::Inserted(first) =
        insert_feed(pool, &row("Some.Show.S01E01.720p.HDTV.x264-GRP"))
            .await
            .expect("insert")
    else {
        panic!("rejected");
    };
    assert_eq!(
        find(pool, "Some.Show.S01E01.720p.HDTV.x264-GRP.mkv").await,
        Lookup::Unknown
    );

    rebuild(pool).await;
    let Lookup::Unique(found) = find(pool, "some.show.s01e01.720p.hdtv.x264-grp.part01.rar").await
    else {
        panic!("not unique");
    };
    assert_eq!(found.id, first);
    assert_eq!(
        find(pool, "Other.Show.S01E01.720p.HDTV.x264-GRP.mkv").await,
        Lookup::Absent
    );

    // A writer keeps the index certified and the new row is findable at once.
    let InsertOutcome::Inserted(second) =
        insert_feed(pool, &row("Other.Show.S01E01.720p.HDTV.x264-GRP"))
            .await
            .expect("insert")
    else {
        panic!("rejected");
    };
    let Lookup::Unique(found) = find(pool, "Other.Show.S01E01.720p.HDTV.x264-GRP.mkv").await else {
        panic!("not unique");
    };
    assert_eq!(found.id, second);

    // A duplicate title is the row's fault, not a failure.
    assert!(matches!(
        insert_feed(pool, &row("Other.Show.S01E01.720p.HDTV.x264-GRP"))
            .await
            .expect("insert"),
        InsertOutcome::Rejected(_)
    ));

    // An outside write fires the trigger, which drops certification.
    sqlx::query("UPDATE predb SET filename = 'x.mkv' WHERE id = ?")
        .bind(first)
        .execute(pool)
        .await
        .expect("update");
    assert_eq!(
        find(pool, "Other.Show.S01E01.720p.HDTV.x264-GRP.mkv").await,
        Lookup::Unknown
    );
    rebuild(pool).await;
    assert!(matches!(
        find(pool, "Other.Show.S01E01.720p.HDTV.x264-GRP.mkv").await,
        Lookup::Unique(_)
    ));
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn a_write_during_a_rebuild_fails_certification() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    insert_feed(pool, &row("Some.Title.2020.1080p-GRP"))
        .await
        .expect("insert");
    let (generation, _, _) = rebuild_start(pool).await.expect("start");
    rebuild_chunk(pool, &generation, 128).await.expect("chunk");
    insert_feed(pool, &row("Later.Title.2020.1080p-GRP"))
        .await
        .expect("insert");
    assert!(rebuild_certify(pool, &generation).await.is_err());
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn aliases_and_ambiguity() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    for (title, filename) in [
        ("Alias.Title.2020.1080p-GRP", "shared-stem-name.mkv"),
        ("Other.Alias.2020.1080p-GRP", "shared-stem-name.mkv"),
        ("Solo.Title.2020.1080p-GRP", "solo-stem-name.mkv"),
    ] {
        sqlx::query("INSERT INTO predb (title, source, filename) VALUES (?, 't', ?)")
            .bind(title)
            .bind(filename)
            .execute(pool)
            .await
            .expect("insert");
    }
    rebuild(pool).await;
    assert_eq!(find(pool, "shared-stem-name.mkv").await, Lookup::Ambiguous);
    let Lookup::Unique(found) = find(pool, "SOLO-stem-name.rar").await else {
        panic!("not unique");
    };
    assert_eq!(found.title, "Solo.Title.2020.1080p-GRP");
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn check_links_only_exact_titles() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    let columns: Vec<(String, Option<String>, String)> = sqlx::query_as(
        "SELECT COLUMN_NAME, COLUMN_DEFAULT, COLUMN_TYPE FROM information_schema.COLUMNS \
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'releases' AND IS_NULLABLE = 'NO' \
         AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'",
    )
    .fetch_all(pool)
    .await
    .expect("columns");
    insert_feed(pool, &row("Linked.Title.2020-GRP"))
        .await
        .expect("insert");
    insert_feed(pool, &row("Accént.Title.2020-GRP"))
        .await
        .expect("insert");
    for (id, searchname) in [(1, "linked.title.2020-grp"), (2, "Accent.Title.2020-GRP")] {
        let mut sql = String::from("INSERT INTO releases (id, searchname");
        let mut values = format!("VALUES ({id}, '{searchname}'");
        for (name, _, kind) in &columns {
            if name == "id" || name == "searchname" {
                continue;
            }
            sql.push_str(&format!(", `{name}`"));
            if kind.contains("int") {
                values.push_str(", 0");
            } else if kind.contains("date") || kind.contains("time") {
                values.push_str(", NOW()");
            } else {
                values.push_str(&format!(", '{id}'"));
            }
        }
        sqlx::query(sqlx::AssertSqlSafe(format!("{sql}) {values})")))
            .execute(pool)
            .await
            .expect("release");
    }
    let (matched, total) = nntmux_predb::check_pre(pool, None).await.expect("check");
    assert_eq!((matched, total), (1, 2));
    let links: Vec<(u64, u32)> = sqlx::query_as("SELECT id, predb_id FROM releases ORDER BY id")
        .fetch_all(pool)
        .await
        .expect("links");
    assert_eq!(links[0].1, 1, "case differs only");
    assert_eq!(
        links[1].1, 0,
        "the collation nominated this but it's a different title"
    );
    db.drop_database().await.expect("drop");
}
