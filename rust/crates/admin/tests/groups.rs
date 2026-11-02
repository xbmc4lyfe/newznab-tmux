use anyhow::Result;
use chrono::NaiveDateTime;
use nntmux_admin::groups::{
    self, Changes, Filter, Flag, NewGroup, format_size, parse_size, valid_name,
};
use nntmux_ingest::groups::ServerGroup;
use nntmux_testkit::TestDb;

fn at(raw: &str) -> NaiveDateTime {
    NaiveDateTime::parse_from_str(raw, "%Y-%m-%d %H:%M:%S").expect("a timestamp")
}

#[test]
fn group_names_follow_is_valid_group() {
    assert_eq!(
        valid_name("a.b.teevee").as_deref(),
        Some("alt.binaries.teevee")
    );
    assert_eq!(
        valid_name("A.B.HDTV.x264").as_deref(),
        Some("alt.binaries.HDTV.x264")
    );
    assert_eq!(
        valid_name("alt.binaries.multimedia").as_deref(),
        Some("alt.binaries.multimedia")
    );
    assert_eq!(
        valid_name("alt.bin-aries_2").as_deref(),
        Some("alt.bin-aries_2")
    );
    for bad in [
        "teevee",
        "alt..binaries",
        ".alt.binaries",
        "alt.binaries.",
        "alt.bin aries",
        "alt.bïnaries",
        "",
    ] {
        assert_eq!(valid_name(bad), None, "{bad}");
    }
}

#[test]
fn sizes_are_megabytes_unless_marked() {
    assert_eq!(parse_size("500").unwrap(), 500 * 1_048_576);
    assert_eq!(parse_size("500mb").unwrap(), 500 * 1_048_576);
    assert_eq!(parse_size(" 2GB ").unwrap(), 2 * 1_073_741_824);
    assert_eq!(parse_size("1.5GB").unwrap(), 1_610_612_736);
    assert_eq!(parse_size("-3").unwrap(), 0);
    assert!(parse_size("big").is_err());
    assert_eq!(format_size(None), "default");
    assert_eq!(format_size(Some(0)), "0");
    assert_eq!(format_size(Some(2 * 1_073_741_824)), "2GB");
    assert_eq!(format_size(Some(500 * 1_048_576)), "500MB");
    assert_eq!(format_size(Some(1_500_000)), "1.43MB");
}

async fn release(db: &TestDb, group: u32, guid: &str) -> Result<()> {
    sqlx::query(
        "INSERT INTO releases (name, searchname, groups_id, guid, leftguid, size, adddate, postdate) \
         VALUES ('n', 's', ?, ?, 'r', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
    )
    .bind(group)
    .bind(guid)
    .execute(&db.pool)
    .await?;
    Ok(())
}

async fn collection(db: &TestDb, group: u32, hash: &str) -> Result<()> {
    let id = sqlx::query(
        "INSERT INTO collections (subject, groups_id, collectionhash) VALUES ('s', ?, UNHEX(SHA1(?)))",
    )
    .bind(group)
    .bind(hash)
    .execute(&db.pool)
    .await?
    .last_insert_id();
    let binary = sqlx::query(
        "INSERT INTO binaries (binaryhash, name, collections_id) VALUES (UNHEX(MD5(?)), 'b', ?)",
    )
    .bind(hash)
    .bind(id)
    .execute(&db.pool)
    .await?
    .last_insert_id();
    sqlx::query(
        "INSERT INTO parts (binaries_id, messageid, number, partnumber) VALUES (?, 'm', 1, 1)",
    )
    .bind(binary)
    .execute(&db.pool)
    .await?;
    sqlx::query("INSERT INTO collection_groups (collections_id, group_name) VALUES (?, 'g')")
        .bind(id)
        .execute(&db.pool)
        .await?;
    sqlx::query("INSERT INTO missed_parts (numberid, groups_id) VALUES (?, ?)")
        .bind(id)
        .bind(group)
        .execute(&db.pool)
        .await?;
    Ok(())
}

async fn count(db: &TestDb, sql: &str) -> Result<i64> {
    Ok(sqlx::query_scalar(sqlx::AssertSqlSafe(sql.to_owned()))
        .fetch_one(&db.pool)
        .await?)
}

#[tokio::test]
async fn groups_are_added_listed_and_edited() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    sqlx::query("DELETE FROM usenet_groups")
        .execute(&db.pool)
        .await?;
    let (tv, created) = groups::add(
        &db.pool,
        &NewGroup {
            name: " alt.binaries.teevee ".to_owned(),
            active: true,
            ..NewGroup::default()
        },
    )
    .await?;
    assert!(created);
    // Adding it again changes nothing.
    assert_eq!(
        groups::add(
            &db.pool,
            &NewGroup {
                name: "alt.binaries.teevee".to_owned(),
                ..NewGroup::default()
            }
        )
        .await?,
        (tv, false)
    );
    let (movies, _) = groups::add(
        &db.pool,
        &NewGroup {
            name: "alt.binaries.movies".to_owned(),
            description: "films".to_owned(),
            backfill: true,
            backfill_target: Some(30),
            min_files: Some(2),
            min_size: Some(1_048_576),
            ..NewGroup::default()
        },
    )
    .await?;
    release(&db, tv, "g1").await?;
    release(&db, tv, "g2").await?;

    let all = groups::list(&db.pool, &Filter::default()).await?;
    assert_eq!(
        all.iter()
            .map(|g| (g.name.as_str(), g.releases))
            .collect::<Vec<_>>(),
        [("alt.binaries.movies", 0), ("alt.binaries.teevee", 2)]
    );
    let shown = groups::require(&db.pool, "alt.binaries.movies").await?;
    assert_eq!(
        (
            shown.id,
            shown.backfill_target,
            shown.minfilestoformrelease,
            shown.minsizetoformrelease
        ),
        (movies, 30, Some(2), Some(1_048_576))
    );
    assert_eq!(shown.description.as_deref(), Some("films"));
    let active = groups::list(
        &db.pool,
        &Filter {
            active: Some(true),
            ..Filter::default()
        },
    )
    .await?;
    assert_eq!(active.len(), 1);
    assert_eq!(active[0].id, tv);
    let named = groups::list(
        &db.pool,
        &Filter {
            name: Some("mov".to_owned()),
            backfill: Some(true),
            ..Filter::default()
        },
    )
    .await?;
    assert_eq!(named.len(), 1);
    // `_` matches only itself.
    assert!(
        groups::list(
            &db.pool,
            &Filter {
                name: Some("alt_binaries".to_owned()),
                ..Filter::default()
            }
        )
        .await?
        .is_empty()
    );

    let now = at("2026-11-02 10:00:00");
    groups::update(
        &db.pool,
        movies,
        &Changes {
            name: Some("a.b.moovies".to_owned()),
            min_files: Some(None),
            min_size: Some(Some(5)),
            last_record: Some(900),
            ..Changes::default()
        },
        now,
    )
    .await?;
    let edited = groups::require(&db.pool, &movies.to_string()).await?;
    assert_eq!(edited.name, "alt.binaries.moovies");
    assert_eq!(
        (
            edited.minfilestoformrelease,
            edited.minsizetoformrelease,
            edited.last_record
        ),
        (None, Some(5), 900)
    );
    assert_eq!(edited.last_updated, Some(now));
    // Unchanged fields stay.
    assert_eq!((edited.backfill_target, edited.backfill), (30, true));
    let clash = groups::update(
        &db.pool,
        movies,
        &Changes {
            name: Some("alt.binaries.teevee".to_owned()),
            ..Changes::default()
        },
        now,
    )
    .await;
    assert!(clash.is_err());

    assert!(groups::set_flag(&db.pool, tv, Flag::Active, false).await?);
    assert!(groups::set_flag(&db.pool, tv, Flag::Backfill, true).await?);
    let toggled = groups::require(&db.pool, "alt.binaries.teevee").await?;
    assert_eq!((toggled.active, toggled.backfill), (false, true));
    assert!(!groups::set_flag(&db.pool, 999_999, Flag::Active, true).await?);
    db.drop_database().await
}

#[tokio::test]
async fn bulk_add_takes_new_matching_server_groups() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    sqlx::query("DELETE FROM usenet_groups")
        .execute(&db.pool)
        .await?;
    groups::add(
        &db.pool,
        &NewGroup {
            name: "alt.binaries.hdtv".to_owned(),
            ..NewGroup::default()
        },
    )
    .await?;
    let listed: Vec<ServerGroup> = [
        "alt.binaries.HDTV.x264",
        "alt.binaries.hdtv",
        "alt.binaries.ebook",
        "alt.binaries.uhd",
    ]
    .into_iter()
    .map(|name| ServerGroup {
        name: name.to_owned(),
        first: 1,
        last: 2,
    })
    .collect();
    let created = groups::add_bulk(&db.pool, &listed, "hdtv|uhd", true, false).await?;
    assert_eq!(
        created.iter().map(|g| g.name.as_str()).collect::<Vec<_>>(),
        ["alt.binaries.HDTV.x264", "alt.binaries.uhd"]
    );
    let added = groups::require(&db.pool, "alt.binaries.uhd").await?;
    assert_eq!((added.active, added.backfill), (true, false));
    assert_eq!(added.description.as_deref(), Some("Added by bulkAdd"));
    assert!(
        groups::add_bulk(&db.pool, &listed, "hdtv|uhd", true, true)
            .await?
            .is_empty()
    );
    assert!(
        groups::add_bulk(&db.pool, &listed, "  ", true, true)
            .await
            .is_err()
    );
    assert!(
        groups::add_bulk(&db.pool, &listed, "(", true, true)
            .await
            .is_err()
    );
    db.drop_database().await
}

#[tokio::test]
async fn reset_purge_and_delete_clear_a_group() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    sqlx::query("DELETE FROM usenet_groups")
        .execute(&db.pool)
        .await?;
    let mut ids = Vec::new();
    for name in ["alt.binaries.one", "alt.binaries.two", "alt.binaries.three"] {
        let (id, _) = groups::add(
            &db.pool,
            &NewGroup {
                name: name.to_owned(),
                active: true,
                backfill_target: Some(9),
                ..NewGroup::default()
            },
        )
        .await?;
        sqlx::query(
            "UPDATE usenet_groups SET first_record = 5, last_record = 50, \
             last_updated = UTC_TIMESTAMP() WHERE id = ?",
        )
        .bind(id)
        .execute(&db.pool)
        .await?;
        collection(&db, id, name).await?;
        release(&db, id, &format!("{name}-r")).await?;
        ids.push(id);
    }
    let (one, two, three) = (ids[0], ids[1], ids[2]);

    groups::reset(&db.pool, one).await?;
    let reset = groups::require(&db.pool, &one.to_string()).await?;
    assert_eq!(
        (
            reset.active,
            reset.backfill_target,
            reset.first_record,
            reset.last_record,
            reset.last_updated
        ),
        (false, 1, 0, 0, None)
    );
    assert_eq!(count(&db, "SELECT COUNT(*) FROM collections").await?, 2);
    assert_eq!(count(&db, "SELECT COUNT(*) FROM binaries").await?, 2);
    assert_eq!(count(&db, "SELECT COUNT(*) FROM parts").await?, 2);
    assert_eq!(
        count(&db, "SELECT COUNT(*) FROM collection_groups").await?,
        2
    );
    assert_eq!(count(&db, "SELECT COUNT(*) FROM missed_parts").await?, 2);
    // Reset keeps releases; the others are untouched.
    assert_eq!(count(&db, "SELECT COUNT(*) FROM releases").await?, 3);
    assert!(groups::require(&db.pool, &two.to_string()).await?.active);

    assert_eq!(groups::purge(&db.pool, Some(two)).await?, (1, 0));
    assert_eq!(
        count(
            &db,
            &format!("SELECT COUNT(*) FROM releases WHERE groups_id = {two}")
        )
        .await?,
        0
    );
    assert_eq!(count(&db, "SELECT COUNT(*) FROM releases").await?, 2);
    assert_eq!(count(&db, "SELECT COUNT(*) FROM collections").await?, 1);

    assert_eq!(groups::delete(&db.pool, three).await?, (1, true));
    assert!(groups::find(&db.pool, &three.to_string()).await?.is_none());

    // Purging everything resets every group and deletes the remaining release.
    assert_eq!(groups::purge(&db.pool, None).await?, (1, 0));
    for table in [
        "collections",
        "binaries",
        "parts",
        "collection_groups",
        "missed_parts",
        "releases",
    ] {
        assert_eq!(
            count(&db, &format!("SELECT COUNT(*) FROM {table}")).await?,
            0,
            "{table}"
        );
    }
    let checks: i64 = sqlx::query_scalar("SELECT @@FOREIGN_KEY_CHECKS")
        .fetch_one(&db.pool)
        .await?;
    assert_eq!(checks, 1);
    db.drop_database().await
}
