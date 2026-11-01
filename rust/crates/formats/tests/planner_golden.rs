//! Behaviour recorded from the php-final reference by `fixtures/generate_planner_golden.php`.

use nntmux_formats::article_fingerprint::Accumulator;
use nntmux_formats::per_article_obfuscation::{Article, mark};
use nntmux_formats::release_names_html;
use nntmux_formats::sample_plan::{SampleManifest, select};
use serde_json::{Value, json};

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/planner-golden.json")).unwrap()
}

#[test]
fn spot_check_plans_match_the_php_planner() {
    let golden = golden();
    for (index, case) in golden["plans"].as_array().unwrap().iter().enumerate() {
        let manifest: SampleManifest = serde_json::from_value(case["manifest"].clone()).unwrap();
        let actual = select(
            &manifest,
            case["maximum_articles"].as_i64().unwrap(),
            case["maximum_header_bytes"].as_i64().unwrap(),
            case["maximum_article_bytes"].as_i64(),
        );
        assert_eq!(json!(actual), case["expected"], "plan {index}");
    }
}

#[test]
fn obfuscation_marks_match_the_php_rule() {
    let golden = golden();
    let cases = golden["ranges"].as_array().unwrap();
    assert!(
        cases
            .iter()
            .any(|case| !case["expected"].as_array().unwrap().is_empty())
    );
    for (index, case) in cases.iter().enumerate() {
        let subjects: Vec<Vec<u8>> = case["subjects"]
            .as_array()
            .unwrap()
            .iter()
            .map(|subject| hex::decode(subject.as_str().unwrap()).unwrap())
            .collect();
        let articles: Vec<Article<'_>> = subjects
            .iter()
            .enumerate()
            .map(|(position, subject)| Article {
                number: 1001 + i64::try_from(position).unwrap(),
                subject,
            })
            .collect();
        let minimum = usize::try_from(case["minimum"].as_u64().unwrap()).unwrap();
        let mut expected: Vec<i64> = serde_json::from_value(case["expected"].clone()).unwrap();
        expected.sort_unstable();
        assert_eq!(mark(&articles, minimum), expected, "range {index}");
    }
}

#[test]
fn fingerprints_match_the_library() {
    for case in golden()["fingerprints"].as_array().unwrap() {
        let mut accumulator = Accumulator::new();
        if let Some(ids) = case["ids"].as_array() {
            for id in ids {
                accumulator.add(&hex::decode(id.as_str().unwrap()).unwrap());
            }
        } else {
            let duplicates = case["duplicates"].as_bool().unwrap();
            for index in 1..=case["generated_count"].as_u64().unwrap() {
                let index = if duplicates { index % 7 } else { index };
                accumulator.add(format!("id{index}@x").as_bytes());
            }
        }
        assert_eq!(
            accumulator.value().map(hex::encode).as_deref(),
            case["value"].as_str(),
            "{}",
            case["ids"]
        );
    }
}

#[test]
fn release_names_match_the_library() {
    for case in golden()["names"].as_array().unwrap() {
        let actual = release_names_html(case["request"].as_str().unwrap().as_bytes());
        assert_eq!(json!(actual), case["rows"], "{}", case["request"]);
    }
}
