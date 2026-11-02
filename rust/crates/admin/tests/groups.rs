use anyhow::Result;
use chrono::NaiveDateTime;
use nntmux_admin::groups::{
    self, Changes, Filter, Flag, NewGroup, format_size, parse_size, valid_name,
};
use nntmux_core::Config;
use nntmux_ingest::groups::ServerGroup;
use nntmux_runtime::Ctx;
use nntmux_testkit::TestDb;
use nntmux_verify::cache::Cache;
use nntmux_verify::config::VerificationConfig;
use nntmux_verify::context::Verify;

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

/// A verification context on the test database, with its own scratch directory and cache prefix.
async fn verification(db: &TestDb) -> Result<Option<Verify>> {
    let Ok(url) = std::env::var("TEST_REDIS_URL") else {
        eprintln!("TEST_REDIS_URL is unset, skipping");
        return Ok(None);
    };
    let redis = redis::Client::open(url)?.get_connection_manager().await?;
    let prefix = format!(
        "t_admin_{}_{}:",
        std::process::id(),
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)?
            .as_nanos()
    );
    sqlx::query(
        "INSERT INTO release_verification_state (id, pending_bytes, audit_cursor) VALUES (1, 0, 0) \
         ON DUPLICATE KEY UPDATE pending_bytes = 0",
    )
    .execute(&db.pool)
    .await?;
    Ok(Some(Verify::new(
        db.pool.clone(),
        Cache::new(redis, prefix.clone()),
        VerificationConfig::for_tests(std::env::temp_dir().join(prefix)),
    )))
}

/// A context on the test database, so the processing drain's keys and lock are this test's own.
async fn context(db: &TestDb) -> Result<Ctx> {
    let url = std::env::var("TEST_REDIS_URL")?;
    let mut config = Config::from_env()?;
    config.redis_url = url.clone();
    config.database.database = db.name.clone();
    Ok(Ctx {
        config: std::sync::Arc::new(config),
        db: db.pool.clone(),
        redis: redis::aio::ConnectionManager::new(redis::Client::open(url)?).await?,
    })
}

fn exclusive<'a>(ctx: &'a Ctx, verify: &'a Verify) -> groups::Exclusive<'a> {
    groups::Exclusive {
        ctx,
        verify,
        drain_timeout: 5,
    }
}

async fn processing_flags(db: &TestDb) -> Result<Option<(bool, bool)>> {
    Ok(sqlx::query_as(
        "SELECT tmux_running, stop_requested FROM processing_runtime_states WHERE id = 1",
    )
    .fetch_optional(&db.pool)
    .await?)
}

/// A queued candidate of `group` with its manifest row, counted in the pending bytes.
async fn candidate(db: &TestDb, group: u32, tag: &str, release: Option<u32>) -> Result<String> {
    let manifest = format!("{:0>64}.json.gz", hex_tag(tag));
    sqlx::query(
        "INSERT INTO release_verification_candidates (identity, groups_id, manifest_path, \
         manifest_bytes, manifest_hash, status, last_unique_at, next_attempt_at, release_id) \
         VALUES (SHA1(?), ?, ?, 10, '', 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)",
    )
    .bind(tag)
    .bind(group)
    .bind(&manifest)
    .bind(release)
    .execute(&db.pool)
    .await?;
    sqlx::query(
        "INSERT INTO release_verification_manifests (name, body, bytes) VALUES (?, 'x', 1)",
    )
    .bind(&manifest)
    .execute(&db.pool)
    .await?;
    sqlx::query("UPDATE release_verification_state SET pending_bytes = pending_bytes + 10")
        .execute(&db.pool)
        .await?;
    Ok(manifest)
}

fn hex_tag(tag: &str) -> String {
    tag.bytes().map(|byte| format!("{byte:02x}")).collect()
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
    let Some(verify) = verification(&db).await? else {
        return Ok(());
    };
    let ctx = context(&db).await?;
    let lock = exclusive(&ctx, &verify);
    // Processing is running; each change drains it and puts it back.
    nntmux_ops::runtime_state::start(&ctx).await?;

    groups::reset(&lock, one).await?;
    assert_eq!(processing_flags(&db).await?, Some((true, false)));
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

    assert_eq!(groups::purge(&lock, Some(two)).await?, (1, 0));
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

    assert_eq!(groups::delete(&lock, three).await?, (1, true));
    assert!(groups::find(&db.pool, &three.to_string()).await?.is_none());

    // Purging everything resets every group and deletes the remaining release.
    assert_eq!(groups::purge(&lock, None).await?, (1, 0));
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

#[tokio::test]
async fn a_group_reset_retires_its_queued_verification_work() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(verify) = verification(&db).await? else {
        return Ok(());
    };
    sqlx::query("DELETE FROM usenet_groups")
        .execute(&db.pool)
        .await?;
    let mut ids = Vec::new();
    for name in ["alt.binaries.one", "alt.binaries.two"] {
        let (id, _) = groups::add(
            &db.pool,
            &NewGroup {
                name: name.to_owned(),
                active: true,
                ..NewGroup::default()
            },
        )
        .await?;
        ids.push(id);
    }
    let (one, two) = (ids[0], ids[1]);
    let queued = candidate(&db, one, "a", None).await?;
    candidate(&db, one, "b", None).await?;
    // A published candidate belongs to its release, and another group's work is untouched.
    candidate(&db, one, "c", Some(77)).await?;
    let other = candidate(&db, two, "d", None).await?;
    let ctx = context(&db).await?;
    // An operator's pause from before stays in place afterwards.
    nntmux_ops::runtime_state::drain(&ctx, 5, None, &nntmux_runtime::Shutdown::default()).await?;
    assert_eq!(processing_flags(&db).await?, Some((false, true)));

    groups::reset(&exclusive(&ctx, &verify), one).await?;
    assert_eq!(processing_flags(&db).await?, Some((false, true)));

    let rows: Vec<(String, String, u32)> = sqlx::query_as(
        "SELECT identity, status, groups_id FROM release_verification_candidates \
         WHERE manifest_path = '' ORDER BY id",
    )
    .fetch_all(&db.pool)
    .await?;
    assert_eq!(rows.len(), 2);
    assert!(rows.iter().all(|row| row.1 == "expired" && row.2 == one));
    let manifests: Vec<String> =
        sqlx::query_scalar("SELECT name FROM release_verification_manifests ORDER BY name")
            .fetch_all(&db.pool)
            .await?;
    assert!(!manifests.contains(&queued));
    assert!(manifests.contains(&other));
    assert_eq!(manifests.len(), 2);
    let pending: u64 =
        sqlx::query_scalar("SELECT pending_bytes FROM release_verification_state WHERE id = 1")
            .fetch_one(&db.pool)
            .await?;
    assert_eq!(pending, 20);
    // The fence is gone afterwards.
    assert!(!nntmux_verify::locks::paused(&db.pool, None).await?);

    // Work that won't finish blocks the change, which leaves the group as it was.
    nntmux_ops::runtime_state::resume(&ctx, None).await?;
    let mut redis = ctx.redis.clone();
    let busy = format!("nntmux:busy:{}:update:test", db.name);
    redis::cmd("SET")
        .arg(&busy)
        .arg(1)
        .arg("EX")
        .arg(60)
        .query_async::<()>(&mut redis)
        .await?;
    let blocked = groups::delete(
        &groups::Exclusive {
            ctx: &ctx,
            verify: &verify,
            drain_timeout: 1,
        },
        two,
    )
    .await;
    redis::cmd("DEL")
        .arg(&busy)
        .query_async::<()>(&mut redis)
        .await?;
    assert!(blocked.is_err());
    assert!(groups::find(&db.pool, &two.to_string()).await?.is_some());
    assert_eq!(
        sqlx::query_scalar::<_, String>(
            "SELECT status FROM release_verification_candidates WHERE groups_id = ?"
        )
        .bind(two)
        .fetch_one(&db.pool)
        .await?,
        "pending"
    );
    db.drop_database().await
}
