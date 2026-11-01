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

#[tokio::test]
async fn a_lookup_after_an_earlier_read_sees_current_rows() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    sqlx::query("INSERT INTO predb (title, source, filename) VALUES ('First.Alias.2020-GRP', 't', 'shared-stem-name.mkv')")
        .execute(pool)
        .await
        .expect("insert");
    rebuild(pool).await;

    let mut caller = pool.begin().await.expect("begin");
    let _: Option<u64> = sqlx::query_scalar("SELECT id FROM predb LIMIT 1")
        .fetch_optional(&mut *caller)
        .await
        .expect("read");
    let row = IrcRow {
        title: "Second.Alias.2020-GRP".to_owned(),
        filename: Some("shared-stem-name.mkv".to_owned()),
        ..IrcRow::default()
    };
    assert!(matches!(
        insert_irc(pool, &row).await.expect("insert"),
        LegacyOutcome::Inserted(_)
    ));
    let found = lookup_many_in(
        &mut caller,
        &[OriginalFilenameEvidence::new("shared-stem-name.mkv")],
    )
    .await;
    assert_eq!(found, vec![Lookup::Ambiguous]);
    caller.rollback().await.expect("rollback");

    // Any other isolation level answers Unknown rather than risk a stale answer.
    let mut other = pool.acquire().await.expect("acquire");
    sqlx::query("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ")
        .execute(&mut *other)
        .await
        .expect("set");
    let found = lookup_many_in(
        &mut other,
        &[OriginalFilenameEvidence::new("shared-stem-name.mkv")],
    )
    .await;
    assert_eq!(found, vec![Lookup::Unknown]);
    sqlx::query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED")
        .execute(&mut *other)
        .await
        .expect("reset");
    drop(other);
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn irc_rows_fill_only_what_php_filled() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let pool = &db.pool;
    let row = IrcRow {
        title: "Irc.Rel-GRP".to_owned(),
        source: Some("#pre".to_owned()),
        category: Some(String::new()),
        filename: Some("0".to_owned()),
        size: Some(String::new()),
        ..IrcRow::default()
    };
    let LegacyOutcome::Inserted(id) = insert_irc(pool, &row).await.expect("insert") else {
        panic!("not inserted");
    };
    let stored: (Option<String>, String, Option<String>) =
        sqlx::query_as("SELECT category, filename, size FROM predb WHERE id = ?")
            .bind(id)
            .fetch_one(pool)
            .await
            .expect("row");
    assert_eq!(
        stored,
        (None, String::new(), None),
        "PHP's empty() values are not stored"
    );

    let update = IrcRow {
        title: "IRC.rel-grp".to_owned(),
        source: Some("#other".to_owned()),
        category: Some("TV".to_owned()),
        ..IrcRow::default()
    };
    assert_eq!(
        update_irc(pool, id, "Irc.Rel-GRP", &update)
            .await
            .expect("update"),
        LegacyOutcome::Updated
    );
    let stored: (String, String, Option<String>) =
        sqlx::query_as("SELECT title, source, category FROM predb WHERE id = ?")
            .bind(id)
            .fetch_one(pool)
            .await
            .expect("row");
    assert_eq!(
        stored,
        (
            "IRC.rel-grp".to_owned(),
            "#pre".to_owned(),
            Some("TV".to_owned())
        )
    );
    assert_eq!(
        update_irc(pool, id, "Irc.Rel-GRP", &update)
            .await
            .expect("update"),
        LegacyOutcome::Stale
    );
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn match_pre_needs_the_current_row_to_agree() {
    let (Some(db), Ok(http)) = (
        TestDb::create().await.expect("db"),
        std::env::var("TEST_MANTICORE_HTTP"),
    ) else {
        return;
    };
    let base = format!("http://{http}");
    let table = db.name.clone();
    let client = reqwest::Client::new();
    let sql = |statement: String| {
        let client = client.clone();
        let base = base.clone();
        async move {
            client
                .post(format!("{base}/cli"))
                .body(statement)
                .send()
                .await
                .expect("sql")
                .error_for_status()
                .expect("sql status");
        }
    };
    sql(format!(
        "CREATE TABLE {table} (title text, filename text, source text)"
    ))
    .await;
    let index = nntmux_predb::PredbIndex::at(base.clone(), table.clone());
    let pool = &db.pool;

    let InsertOutcome::Inserted(id) = insert_feed(pool, &row("Some.Rel-2020-GRP"))
        .await
        .expect("insert")
    else {
        panic!("rejected");
    };
    index
        .replace(id, "Some.Rel-2020-GRP", "alias.name-grp", "t")
        .await;
    sqlx::query("UPDATE predb SET filename = 'alias.name-grp' WHERE id = ?")
        .bind(id)
        .execute(pool)
        .await
        .expect("update");

    let found = nntmux_predb::match_pre(pool, &index, "some.rel-2020-grp")
        .await
        .expect("match");
    assert_eq!(
        found.map(|m| (m.predb_id, m.title)),
        Some((id, "Some.Rel-2020-GRP".to_owned()))
    );
    let found = nntmux_predb::match_pre(pool, &index, "ALIAS.name-grp")
        .await
        .expect("match");
    assert_eq!(found.map(|m| m.predb_id), Some(id));
    assert_eq!(
        nntmux_predb::match_pre(pool, &index, "Some.Rel-2020")
            .await
            .expect("match"),
        None
    );
    assert_eq!(
        nntmux_predb::match_pre(pool, &index, "  ")
            .await
            .expect("match"),
        None
    );
    assert_eq!(
        nntmux_predb::match_pre(pool, &index, "(!)")
            .await
            .expect("match"),
        None
    );

    // A stale document never overrides the database.
    sqlx::query("UPDATE predb SET title = 'Renamed.Rel-2020-GRP', filename = '' WHERE id = ?")
        .bind(id)
        .execute(pool)
        .await
        .expect("update");
    assert_eq!(
        nntmux_predb::match_pre(pool, &index, "Some.Rel-2020-GRP")
            .await
            .expect("match"),
        None
    );

    sql(format!("DROP TABLE {table}")).await;
    db.drop_database().await.expect("drop");
}
