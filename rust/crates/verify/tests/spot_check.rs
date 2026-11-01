//! The spot check against goldens from the PHP reference and a fake article source.

use std::collections::HashMap;

use anyhow::{Result, anyhow};
use async_trait::async_trait;
use nntmux_verify::identity;
use nntmux_verify::manifest::{Manifest, ManifestFile, Segment};
use nntmux_verify::spot_check::{
    self, ArticleBudgetExceeded, ArticleSource, SampledSegment, SpotCheckOptions,
};
use serde_json::Value;

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/identity_golden.json")).expect("golden JSON")
}

#[test]
fn filename_identity_matches_php() {
    for entry in golden()["pairs"].as_array().expect("pairs") {
        let subject = entry["subject"].as_str().expect("subject");
        let actual = entry["actual"].as_str().expect("actual");
        let packet = entry["packet"].as_bool().expect("packet");
        assert_eq!(
            identity::matches(subject, actual, packet),
            entry["matches"].as_bool().expect("matches"),
            "{subject} / {actual} / {packet}"
        );
    }
    for entry in golden()["names"].as_array().expect("names") {
        let name = entry["name"].as_str().expect("name");
        assert_eq!(
            identity::is_par2_filename(name),
            entry["par2"].as_bool().expect("par2"),
            "{name}"
        );
        assert_eq!(
            identity::proves_payload(name),
            entry["payload"].as_bool().expect("payload"),
            "{name}"
        );
    }
}

#[test]
fn sample_plans_match_php() {
    let golden = golden();
    let manifest: Manifest = serde_json::from_value(serde_json::json!({
        "identity": golden["manifest"]["identity"],
        "files": golden["manifest"]["files"].as_array().expect("files").iter().map(|file| {
            let mut file = file.clone();
            file["subject"] = "s".into();
            file
        }).collect::<Vec<_>>(),
    }))
    .expect("manifest");
    for plan in golden["plans"].as_array().expect("plans") {
        let options = SpotCheckOptions {
            articles: plan["articles"].as_i64().expect("articles"),
            bytes: plan["bytes"].as_i64().expect("bytes"),
            article_bytes: plan["article_bytes"].as_i64().expect("article_bytes"),
            stat_all_articles: false,
        };
        let expected: Vec<SampledSegment> =
            serde_json::from_value(plan["plan"].clone()).expect("plan");
        assert_eq!(
            spot_check::sample(&manifest, &options),
            expected,
            "{options:?}"
        );
    }
}

#[derive(Default)]
struct FakeSource {
    articles: HashMap<String, Vec<u8>>,
    too_big: bool,
}

#[async_trait]
impl ArticleSource for FakeSource {
    async fn fetch_bounded(&self, message_id: &str, max_bytes: i64) -> Result<Vec<u8>> {
        if self.too_big {
            return Err(ArticleBudgetExceeded(
                "Article exceeds verification memory budget.".to_owned(),
            )
            .into());
        }
        let article = self
            .articles
            .get(message_id)
            .ok_or_else(|| anyhow!("article missing"))?;
        assert!(article.len() as i64 <= max_bytes);
        Ok(article.clone())
    }

    async fn stat(&self, _: &[String]) -> Result<()> {
        Ok(())
    }
}

fn options() -> SpotCheckOptions {
    SpotCheckOptions {
        articles: 1,
        bytes: 1_048_576,
        article_bytes: 1_048_576,
        stat_all_articles: false,
    }
}

fn upload(name: &str, totalparts: i64, parts: i64, expected: i64) -> Manifest {
    Manifest {
        identity: "ab".repeat(20),
        expected_files: expected,
        files: vec![ManifestFile {
            key: "k".to_owned(),
            subject: format!("[1/1] - \"{name}\" yEnc"),
            totalparts,
            totalparts_inferred: false,
            segments: (1..=parts)
                .map(|number| Segment {
                    number,
                    messageid: format!("m{number}@x"),
                    bytes: 10_000,
                })
                .collect(),
        }],
        ..Manifest::default()
    }
}

fn article(
    name: &str,
    part: i64,
    total: i64,
    payload: &[u8],
    file_size: usize,
    begin: usize,
) -> Vec<u8> {
    let encoded: Vec<u8> = payload
        .iter()
        .flat_map(|byte| match byte.wrapping_add(42) {
            escape @ (0 | 10 | 13 | 61) => vec![b'=', escape.wrapping_add(64)],
            plain => vec![plain],
        })
        .collect();
    let end = begin + payload.len() - 1;
    let crc = format!("{:08x}", crc32fast::hash(payload));
    let mut raw = format!("=ybegin part={part} total={total} line=128 size={file_size} name={name}\r\n=ypart begin={begin} end={end}\r\n").into_bytes();
    raw.extend(encoded);
    raw.extend(
        format!(
            "\r\n=yend size={} part={part} pcrc32={crc}\r\n",
            payload.len()
        )
        .into_bytes(),
    );
    raw
}

#[tokio::test]
async fn a_matching_article_passes_with_evidence() {
    let manifest = upload("Good.Release.mkv", 1, 1, 1);
    let mut source = FakeSource::default();
    source.articles.insert(
        "m1@x".to_owned(),
        article("Good.Release.mkv", 1, 1, b"payload", 7, 1),
    );
    let result = spot_check::verify(&source, &options(), &manifest, false).await;
    assert!(result.passed(), "{result:?}");
    assert_eq!(result.verdict, "sampled");
    let sampled = result.manifest.expect("manifest");
    assert_eq!(sampled.verification_mode.as_deref(), Some("spot_check"));
    assert_eq!(sampled.publication_file_count, Some(1));
    assert_eq!(
        sampled.verified_file_names,
        Some(vec!["Good.Release.mkv".to_owned()])
    );
    let evidence = spot_check::evidence(&sampled).expect("evidence");
    assert_eq!(
        spot_check::with_evidence(&manifest, &evidence).spot_check,
        sampled.spot_check
    );
}

#[tokio::test]
async fn another_files_article_is_a_deterministic_failure() {
    let manifest = upload("Good.Release.mkv", 1, 1, 1);
    let mut source = FakeSource::default();
    source.articles.insert(
        "m1@x".to_owned(),
        article("Other.Thing.mkv", 1, 1, b"payload", 7, 1),
    );
    let result = spot_check::verify(&source, &options(), &manifest, false).await;
    assert!(!result.passed());
    assert!(result.deterministic);
    assert_eq!(
        result.reason,
        "Spot-check article identity differs from its header."
    );
}

#[tokio::test]
async fn a_missing_article_retries_on_the_ordinary_interval() {
    let manifest = upload("Good.Release.mkv", 1, 1, 1);
    let result = spot_check::verify(&FakeSource::default(), &options(), &manifest, false).await;
    assert_eq!(result.verdict, "unknown");
    assert!(!result.deterministic);
}

#[tokio::test]
async fn a_corrupt_article_isnt_held_against_the_upload() {
    let manifest = upload("Good.Release.mkv", 1, 1, 1);
    let mut body = article("Good.Release.mkv", 1, 1, b"payload", 7, 1);
    let at = body
        .iter()
        .position(|byte| *byte == b'p' + 42)
        .expect("payload");
    body[at] ^= 1;
    let mut source = FakeSource::default();
    source.articles.insert("m1@x".to_owned(), body);
    let result = spot_check::verify(&source, &options(), &manifest, false).await;
    assert!(!result.passed());
    assert!(!result.deterministic, "{result:?}");
}

#[tokio::test]
async fn an_oversized_article_is_deterministic() {
    let manifest = upload("Good.Release.mkv", 1, 1, 1);
    let source = FakeSource {
        too_big: true,
        ..FakeSource::default()
    };
    let result = spot_check::verify(&source, &options(), &manifest, false).await;
    assert!(result.deterministic);
}

#[tokio::test]
async fn an_incomplete_upload_waits_unless_partial_is_allowed() {
    let manifest = upload("Good.Release.mkv", 3, 1, 1);
    let mut source = FakeSource::default();
    source.articles.insert(
        "m1@x".to_owned(),
        article("Good.Release.mkv", 1, 3, b"payload", 21, 1),
    );

    let waiting = spot_check::verify(&source, &options(), &manifest, false).await;
    assert!(waiting.waiting_for_headers);
    assert!(!waiting.passed());

    let partial = spot_check::verify(&source, &options(), &manifest, true).await;
    assert!(partial.passed(), "{partial:?}");
    let sampled = partial.manifest.expect("manifest");
    let counts = sampled.partial.expect("partial counts");
    assert_eq!((counts.articles_found, counts.articles_expected), (1, 3));
    assert!(partial.reason.contains("1 of 3 articles"));
}

#[tokio::test]
async fn a_rejected_inventory_never_fetches() {
    let mut manifest = upload("Good.Release.mkv", 1, 1, 1);
    manifest.conflict = true;
    let result = spot_check::verify(&FakeSource::default(), &options(), &manifest, true).await;
    assert!(result.header_rejected);
}

#[test]
fn a_regex_that_gives_up_rejects_rather_than_falling_through() {
    let junk = "' .".repeat(800);
    let executable = format!(r#"{junk} "real.mkv" "body.exe" "x.mkv" yEnc"#);
    assert!(!nntmux_verify::identity::matches(
        &executable,
        "body.exe",
        false
    ));
    let parity = format!(r#"{junk} "real.par2" "body.mkv" "x.par2" yEnc"#);
    assert!(!nntmux_verify::identity::matches(
        &parity, "body.mkv", false
    ));
}

#[test]
fn byte_patterns_stay_ascii() {
    use nntmux_verify::identity::matches;
    assert!(matches("\"Show.١٢٣.mkv\"", "Show ١٢٣.mkv", false));
    assert!(!matches("\"Movie.\u{212A}o\"", "movie.ko", false));
    assert!(!matches("[1/2]\u{A0}\"a.mkv\" \"b.par2\"", "a.mkv", false));
    assert!(!matches(
        "\"Kq9bbbbbbbbbb.mkv\"",
        "\u{212A}q9bbbbbbbbbb.mkv",
        false
    ));
}
