//! `identify_release` and `releases:identity-suggestions` against a real database.

use nntmux_namefix::identify::nzb::summarize;
use nntmux_namefix::identify::{DonorIndex, Evidence, Hooks, Outcome, Scope, identify_release};
use nntmux_namefix::identity_suggestions::{Request, report};
use nntmux_testkit::TestDb;
use sqlx::MySqlPool;

const POSTED: i64 = 1_700_000_000;

async fn predb(pool: &MySqlPool, id: i64, title: &str, size: &str, offset: i64) {
    sqlx::query(
        "INSERT INTO predb (id, title, size, category, files, predate, source)
         VALUES (?, ?, ?, 'TV', '20', FROM_UNIXTIME(?), 'test')",
    )
    .bind(id)
    .bind(title)
    .bind(size)
    .bind(POSTED + offset)
    .execute(pool)
    .await
    .expect("predb row");
}

fn evidence(subject: &str, payload: u64) -> Evidence {
    let xml = format!(
        r#"<file subject="{}"><segments><segment bytes="{payload}">a</segment></segments></file>"#,
        subject.replace('"', "&quot;")
    );
    Evidence {
        id: 1,
        name: subject.to_owned(),
        search_name: subject.to_owned(),
        size_bytes: payload as i64,
        posted_at: POSTED,
        nzb: Some(summarize(&xml)),
        ..Evidence::default()
    }
}

#[tokio::test]
async fn identifies_by_name_and_by_size_from_mariadb() {
    let Some(db) = TestDb::create().await.expect("database") else {
        return;
    };
    predb(
        &db.pool,
        1,
        "Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR",
        "2468MB",
        -3600,
    )
    .await;
    predb(
        &db.pool,
        2,
        "Lone.Size.Film.2024.2160p.WEB.H265-GRP",
        "9000MB",
        -3600,
    )
    .await;
    predb(
        &db.pool,
        3,
        "Old.Film.2024.2160p.WEB.H265-GRP",
        "7000MB",
        -9_000_000,
    )
    .await;
    let donors = DonorIndex::default();

    // The file name matches, in another case and with its archive suffix.
    let named = evidence(
        r#""ZMORA.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR.part01.rar" yEnc"#,
        2468 * 1_048_576,
    );
    let (outcome, _) = identify_release(&db.pool, &named, &donors, Hooks::default())
        .await
        .expect("identify");
    let Outcome::Identified(found) = outcome else {
        panic!("expected a match, got {outcome:?}");
    };
    assert_eq!((found.method, found.predb_id), ("predb_exact", Some(1)));
    assert_eq!(found.scope, Scope::InScope);

    // Spaces where PreDB has dots still find the row.
    let spaced = evidence(
        r#""Zmora S01E05 1080p AMZN WEB-DL DDP5.1 H.264-RAWR.mkv" yEnc (1/90)"#,
        2468 * 1_048_576,
    );
    let (outcome, _) = identify_release(&db.pool, &spaced, &donors, Hooks::default())
        .await
        .expect("identify");
    assert!(
        matches!(&outcome, Outcome::Identified(found) if found.predb_id == Some(1)),
        "{outcome:?}"
    );

    // A single obfuscated MKV is looked at like anything else. Its size alone names nothing.
    let single = evidence(
        r#""kGb4MmDz8va2XUhd.mkv" yEnc"#,
        9000 * 1_048_576 * 102 / 100,
    );
    let (outcome, flags) = identify_release(&db.pool, &single, &donors, Hooks::default())
        .await
        .expect("identify");
    assert_eq!(outcome, Outcome::Unidentified);
    assert!(flags.predb_size_unique);

    // Right size, posted months after the pre: not even a candidate.
    let late = evidence(
        r#""kGb4MmDz8va2XUhd.mkv" yEnc"#,
        7000 * 1_048_576 * 102 / 100,
    );
    let (outcome, flags) = identify_release(&db.pool, &late, &donors, Hooks::default())
        .await
        .expect("identify");
    assert_eq!(outcome, Outcome::Unidentified);
    assert!(!flags.predb_size_any);
}

/// The queries `tests/fixtures/identity_suggestions/generate.sh` asked the PHP, with its answers.
#[tokio::test]
async fn suggestions_match_the_php_command() {
    let Some(db) = TestDb::create().await.expect("database") else {
        return;
    };
    for (id, title) in [
        (990001, "Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR"),
        (990002, "Zmora.S01E05.720p.AMZN.WEB-DL.DDP5.1.H.264-RAWR"),
        (990003, "Some.Film.2021.1080p.BluRay.x264-GRP"),
        (990004, "Some.Film.2021.2160p.BluRay.x265-OTHER"),
        (990005, "Unrelated.Show.S02E01.1080p.WEB.h264-NOPE"),
        (
            990006,
            "The.Wandering.Stars.Over.Quiet.Harbor.2021.1080p.BluRay.x264-GRP",
        ),
        (
            990007,
            "The.Wandering.Stars.Over.Quiet.Harbor.2021.2160p.BluRay.x265-OTHER",
        ),
    ] {
        predb(&db.pool, id, title, "", 0).await;
    }
    let cases: &[(&str, &[&str])] = &[
        (
            "zmora s01e05 1080p amzn web-dl ddp5.1 h.264-rawr",
            &["990001", "990002"],
        ),
        ("Zmora.S01E05.1080p", &["990001", "990002"]),
        ("Zmora.S01E05", &["990001", "990002"]),
        ("Some.Film.2021", &["990003", "990004"]),
        ("Some.Film.2021.1080p.BluRay.x264-GRP.mkv", &["990003"]),
        ("Some/Film.2021.1080p", &["990003"]),
        ("Nothing.Like.It.1999", &["990005"]),
        ("Zmora.S01E05", &["999999"]),
        (
            "The.Wandering.Stars.Over.Quiet.Harbor.2021.1080p.BluRay.x264-GRP",
            &["990006", "990007"],
        ),
        (
            "the wandering stars over quiet harbor 2021 1080p bluray x264-grp",
            &["990006"],
        ),
        ("The Wandering Stars Over Quiet Harbor 2021", &["990006"]),
        (
            "The Wandering Stars Over Quiet Harbor 2021",
            &["990006", "990007"],
        ),
        (
            "The Wandering Stars Over Quiet Harbor 1080p",
            &["990006", "990007"],
        ),
        (
            "The/Wandering Stars Over Quiet Harbor 2021 1080p BluRay",
            &["990006"],
        ),
    ];
    let golden = include_str!("fixtures/identity_suggestions/golden.jsonl");
    let lines: Vec<&str> = golden.lines().collect();
    assert_eq!(lines.len(), cases.len());
    for ((query, ids), expected) in cases.iter().zip(lines) {
        let request = Request {
            query: Some((*query).to_owned()),
            release_id: None,
            candidate_ids: ids.iter().map(|id| (*id).to_owned()).collect(),
        };
        let line = report(&db.pool, request, |_| async {
            panic!("named candidates need no search")
        })
        .await
        .expect("report");
        assert_eq!(line, expected, "{query}");
    }
}

#[tokio::test]
async fn suggestions_read_a_release_and_refuse_too_many_hits() {
    let Some(db) = TestDb::create().await.expect("database") else {
        return;
    };
    let overflow = report(
        &db.pool,
        Request {
            query: Some("Some.Film.2021".to_owned()),
            ..Request::default()
        },
        |text| async move {
            assert_eq!(text, "Some.Film.2021");
            Ok((1..=51).collect())
        },
    )
    .await
    .expect("report");
    assert_eq!(
        overflow,
        r#"{"candidate_predb_id":null,"reason":"candidate_overflow","auto_link":false}"#
    );

    let missing = report(
        &db.pool,
        Request {
            release_id: Some("424242".to_owned()),
            ..Request::default()
        },
        |_| async { Ok(Vec::new()) },
    )
    .await
    .expect_err("no such release");
    assert!(missing.to_string().contains("Provide a release ID"));
}
