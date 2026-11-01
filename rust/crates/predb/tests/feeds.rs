//! Feed parsing against `fixtures/generate_feed_golden.php`, plus the importer and command flow
//! against a real database and a mock server.

use nntmux_predb::PredbIndex;
use nntmux_predb::feeds::command::{ImportArgs, import, parse_since, rate_limit_wait_seconds};
use nntmux_predb::feeds::http::FeedHttp;
use nntmux_predb::feeds::importer::{Importer, Totals};
use nntmux_predb::feeds::sources::*;
use nntmux_predb::feeds::{
    Entry, FeedConfig, FeedError, PRE_NUKED, PRE_UNNUKED, size_from_megabytes,
};
use nntmux_testkit::TestDb;
use serde_json::{Value, json};
use wiremock::matchers::{method, query_param};
use wiremock::{Mock, MockServer, ResponseTemplate};

fn config() -> FeedConfig {
    FeedConfig {
        request_delay_ms: 0,
        rate_limit_wait_seconds: 0,
        ..FeedConfig::from_env()
    }
}

fn dump(entries: &[Entry]) -> Value {
    Value::Array(
        entries
            .iter()
            .map(|e| {
                json!({
                    "title": e.title, "source": e.source, "category": e.category, "size": e.size,
                    "files": e.files, "nuked": e.nuked, "reason": e.nuke_reason, "enrich": e.enrich_only,
                    "predate": e.predate.map(|d| d.format("%Y-%m-%d %H:%M:%S").to_string()),
                    "listed": e.listed_at.map(|d| d.format("%Y-%m-%d %H:%M:%S").to_string()),
                })
            })
            .collect(),
    )
}

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/feed_golden.json")).expect("golden")
}

fn rows(golden: &Value, key: &str) -> Vec<Value> {
    golden[key]["in"].as_array().expect("in").clone()
}

#[test]
fn json_sources_match_the_php() {
    let g = golden();
    let http = FeedHttp::new(&config()).expect("http");
    let url = || "http://x".to_owned();
    let cases = [
        (
            "club",
            dump(&PredbClubSource::parse_rows(&rows(&g, "club"))),
        ),
        ("net", dump(&PredbNetSource::parse_rows(&rows(&g, "net")))),
        ("org", dump(&PredbOrgSource::parse_rows(&rows(&g, "org")))),
        ("ovh", dump(&PredbOvhSource::parse_rows(&rows(&g, "ovh")))),
        (
            "srrdb",
            dump(
                &SrrdbSource::new(url(), http.clone(), "Europe/Brussels".into())
                    .parse_rows(&rows(&g, "srrdb")),
            ),
        ),
        (
            "xrel",
            dump(&XrelSource::new(url(), 100, http.clone(), false).parse_rows(&rows(&g, "xrel"))),
        ),
        (
            "xrel_p2p",
            dump(
                &XrelSource::new(url(), 100, http.clone(), true).parse_rows(&rows(&g, "xrel_p2p")),
            ),
        ),
        (
            "apibay",
            dump(&ApibaySource::new(url(), 100, http.clone()).parse_rows(&rows(&g, "apibay"))),
        ),
        (
            "predatabase",
            dump(&PredatabaseSource::parse_rows(&rows(&g, "predatabase"))),
        ),
    ];
    for (key, actual) in cases {
        assert_eq!(actual, g[key]["out"], "{key}");
    }
}

#[test]
fn rss_matches_the_php() {
    let g = golden();
    let http = FeedHttp::new(&config()).expect("http");
    let xml = g["rss"]["xml"].as_str().expect("xml");
    let parse = |options: RssOptions| {
        let source = RssFeedSource::new(
            "k".into(),
            "label",
            "http://x".into(),
            100,
            1 << 20,
            http.clone(),
            options,
        );
        match source.parse_xml(xml.as_bytes()) {
            Ok(entries) => dump(&entries),
            Err(error) => panic!("{error}"),
        }
    };
    assert_eq!(parse(RssOptions::default()), g["rss"]["plain"]);
    assert_eq!(
        parse(RssOptions {
            spaced_titles: true,
            map_category: true,
            ..RssOptions::default()
        }),
        g["rss"]["spaced"]
    );
    assert_eq!(
        parse(RssOptions {
            publication_dates_only: true,
            strip_display_size_suffix: true,
            ..RssOptions::default()
        }),
        g["rss"]["catalogue"]
    );
}

#[test]
fn rss_rejects_what_isnt_rss() {
    let http = FeedHttp::new(&config()).expect("http");
    let source = RssFeedSource::new(
        "k".into(),
        "label",
        "http://x".into(),
        100,
        1 << 20,
        http,
        RssOptions::default(),
    );
    for bad in [
        "<html><body>nope</body></html>",
        "<rss><channel><item>",
        "<rss version=\"2.0\"></rss>",
        "<!DOCTYPE rss><rss><channel></channel></rss>",
    ] {
        assert!(
            matches!(source.parse_xml(bad.as_bytes()), Err(FeedError::Failed(_))),
            "{bad}"
        );
    }
}

#[test]
fn sizes_match_the_php() {
    let g = golden();
    for (input, expected) in g["sizes"].as_object().expect("sizes") {
        let value: f64 = input.parse().expect("float");
        assert_eq!(
            json!(size_from_megabytes(Some(value))),
            *expected,
            "{input}"
        );
    }
}

#[test]
fn since_and_backoff() {
    let now = chrono::DateTime::from_timestamp(1_800_000_000, 0).expect("time");
    assert_eq!(
        parse_since("2026-09-25", now)
            .map(|d| d.to_string())
            .as_deref(),
        Some("2026-09-25 00:00:00")
    );
    assert!(parse_since("2026-02-31", now).is_none());
    assert!(parse_since("yesterday", now).is_none());
    assert_eq!(
        parse_since("2 D", now).map(|d| d.and_utc().timestamp()),
        Some(1_800_000_000 - 172_800)
    );
    assert_eq!(rate_limit_wait_seconds(Some("120"), None, 60, now), 120);
    assert_eq!(
        rate_limit_wait_seconds(Some("junk"), Some("1800000090"), 60, now),
        90
    );
    assert_eq!(rate_limit_wait_seconds(None, None, 60, now), 60);
    assert_eq!(
        rate_limit_wait_seconds(Some("Fri, 15 Jan 2027 08:00:50 GMT"), None, 60, now),
        50
    );
}

fn entry(title: &str) -> Entry {
    Entry::new(title, "test")
}

#[tokio::test]
async fn importer_inserts_and_only_fills_gaps() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let mut importer = Importer::new(db.pool.clone(), PredbIndex::disabled());
    let first = Entry {
        category: Some("TV".into()),
        ..entry("Some.Rel-GRP")
    };
    assert_eq!(
        importer
            .import(&[first.clone()], false)
            .await
            .expect("import"),
        Totals {
            inserted: 1,
            updated: 0,
            skipped: 0
        }
    );
    assert_eq!(
        importer
            .import(&[first], false)
            .await
            .expect("import")
            .skipped,
        1
    );

    // Details fill empty columns and never overwrite.
    let more = Entry {
        category: Some("MOVIE".into()),
        size: Some("10MB".into()),
        ..entry("some.rel-grp")
    };
    assert_eq!(
        importer
            .import(&[more], false)
            .await
            .expect("import")
            .updated,
        1
    );
    let (category, size): (Option<String>, Option<String>) =
        sqlx::query_as("SELECT category, size FROM predb WHERE title = 'Some.Rel-GRP'")
            .fetch_one(&db.pool)
            .await
            .expect("row");
    assert_eq!(
        (category.as_deref(), size.as_deref()),
        (Some("TV"), Some("10MB"))
    );

    // Nukes transition; an un-nuke clears through its own reason.
    let nuke = Entry {
        nuked: PRE_NUKED,
        nuke_reason: Some("dupe".into()),
        ..entry("Some.Rel-GRP")
    };
    assert_eq!(
        importer
            .import(&[nuke], false)
            .await
            .expect("import")
            .updated,
        1
    );
    let unnuke = Entry {
        nuked: PRE_UNNUKED,
        ..entry("Some.Rel-GRP")
    };
    assert_eq!(
        importer
            .import(&[unnuke], false)
            .await
            .expect("import")
            .updated,
        1
    );
    let (nuked, reason): (i8, Option<String>) =
        sqlx::query_as("SELECT nuked, nukereason FROM predb WHERE title = 'Some.Rel-GRP'")
            .fetch_one(&db.pool)
            .await
            .expect("row");
    assert_eq!((nuked, reason.as_deref()), (PRE_UNNUKED, Some("dupe")));

    // INFO-style details never create a row, and accents aren't the same title.
    let info = Entry {
        enrich_only: true,
        ..entry("Unknown.Rel-GRP")
    };
    assert_eq!(
        importer
            .import(&[info], false)
            .await
            .expect("import")
            .skipped,
        1
    );
    assert_eq!(
        importer
            .import(&[entry("Sóme.Rel-GRP")], false)
            .await
            .expect("import")
            .skipped,
        1
    );
    assert_eq!(
        importer
            .import(&[entry("   ")], false)
            .await
            .expect("import")
            .skipped,
        1
    );
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn dry_run_writes_nothing_and_counts_overlap() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let mut importer = Importer::new(db.pool.clone(), PredbIndex::disabled());
    let totals = importer
        .import(
            &[
                entry("Dry.Rel-GRP"),
                entry("DRY.REL-GRP"),
                Entry {
                    size: Some("1MB".into()),
                    ..entry("Dry.Rel-GRP")
                },
            ],
            true,
        )
        .await
        .expect("import");
    assert_eq!(
        totals,
        Totals {
            inserted: 1,
            updated: 1,
            skipped: 1
        }
    );
    let count: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM predb")
        .fetch_one(&db.pool)
        .await
        .expect("count");
    assert_eq!(count, 0);
    db.drop_database().await.expect("drop");
}

fn args(source: &str) -> ImportArgs {
    ImportArgs {
        source: vec![source.to_owned()],
        pages: 1,
        since: None,
        max_pages: None,
        dry_run: false,
        quiet: true,
    }
}

#[tokio::test]
async fn command_retries_a_throttled_page_and_stops_at_the_cutoff() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(429).insert_header("Retry-After", "0"))
        .up_to_n_times(1)
        .mount(&server)
        .await;
    Mock::given(method("GET"))
        .and(query_param("page", "1"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "status": "success",
            "data": {"rows": [{"name": "New.Rel-GRP", "preAt": 1_900_000_000}]},
        })))
        .mount(&server)
        .await;
    Mock::given(method("GET"))
        .and(query_param("page", "2"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "data": {"rows": [{"name": "Old.Rel-GRP", "preAt": 1_000_000_000}]},
        })))
        .mount(&server)
        .await;
    let mut settings = config();
    settings.endpoint_override = Some(server.uri());
    let importer = Importer::new(db.pool.clone(), PredbIndex::disabled());
    let mut request = args("predb_club");
    request.since = Some("2026-01-01".into());
    let reports = import(&settings, importer, &request).await.expect("import");
    assert_eq!(reports[0].status, "ok");
    assert_eq!(reports[0].totals.inserted, 2);

    // A failing source is reported, not fatal.
    let dead = MockServer::start().await;
    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(404))
        .mount(&dead)
        .await;
    settings.endpoint_override = Some(dead.uri());
    let importer = Importer::new(db.pool.clone(), PredbIndex::disabled());
    let reports = import(&settings, importer, &args("predb_net"))
        .await
        .expect("import");
    assert!(
        reports[0].status.starts_with("failed"),
        "{}",
        reports[0].status
    );

    // Latest-only listings can't verify history.
    let rss = MockServer::start().await;
    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(200).set_body_string(
            "<rss><channel><item><title>Rss.Rel-GRP</title><pubDate>Sat, 01 Aug 2026 10:00:00 +0000</pubDate></item></channel></rss>",
        ))
        .mount(&rss)
        .await;
    settings.endpoint_override = Some(rss.uri());
    let importer = Importer::new(db.pool.clone(), PredbIndex::disabled());
    let mut request = args("ddlvalley");
    request.since = Some("1d".into());
    let reports = import(&settings, importer, &request).await.expect("import");
    assert_eq!(
        reports[0].status,
        "incomplete: history paging is disabled for this source"
    );
    db.drop_database().await.expect("drop");
}
