//! NZB candidate selection, manifests and signatures against `NzbContentsService::par2Candidates`.

use nntmux_namefix::par2_candidates::{extract_parts_total, family_key, parse_nzb, plan};
use serde_json::Value;

fn fixture() -> Value {
    serde_json::from_str(include_str!("fixtures/par2_candidates.json")).expect("fixture")
}

#[test]
fn candidates_match_php() {
    for case in fixture()["cases"].as_array().unwrap() {
        let name = case["name"].as_str().unwrap();
        let files = parse_nzb(case["xml"].as_str().unwrap().as_bytes());
        if !case["parsed"].as_bool().unwrap() {
            assert!(files.is_none(), "{name}: should not parse");
            continue;
        }
        let files = files.unwrap_or_else(|| panic!("{name}: should parse"));
        let max = case["max_bytes"].as_i64().unwrap();
        let planned = plan(&files, max);
        if case.get("error").is_some() {
            assert!(planned.is_err(), "{name}: should fail");
            continue;
        }
        let planned = planned.unwrap_or_else(|e| panic!("{name}: {e}"));
        let expected = case["candidates"].as_array().unwrap();
        assert_eq!(planned.candidates.len(), expected.len(), "{name} count");
        for (got, want) in planned.candidates.iter().zip(expected) {
            let label = format!("{name}: {}", got.subject);
            assert_eq!(got.subject, want["subject"].as_str().unwrap(), "{label}");
            assert_eq!(got.explicit, want["explicit"].as_bool().unwrap(), "{label}");
            assert_eq!(got.class.name(), want["classification"].as_str().unwrap(), "{label}");
            assert_eq!(got.signature(), want["signature"].as_str().unwrap(), "{label} signature");
            assert_eq!(
                family_key(&got.subject).as_deref(),
                want["family"].as_str(),
                "{label} family"
            );
            let segments = want["segments"].as_array().unwrap();
            assert_eq!(got.segments.len(), segments.len(), "{label}");
            for (segment, want) in got.segments.iter().zip(segments) {
                assert_eq!(segment.number, want["number"].as_i64().unwrap(), "{label}");
                assert_eq!(segment.id, want["id"].as_str().unwrap(), "{label}");
                assert_eq!(segment.bytes, want["bytes"].as_i64().unwrap(), "{label}");
            }
        }
        assert_eq!(planned.manifest.encode(), case["manifest"].as_str().unwrap(), "{name} manifest");
        assert_eq!(
            planned.non_explicit_bytes,
            case["non_explicit_bytes"].as_i64().unwrap(),
            "{name} bytes"
        );
        assert_eq!(
            planned.structure_valid,
            case["structure_valid"].as_bool().unwrap(),
            "{name} structure"
        );
        assert_eq!(
            planned.signature("abc", 7, 2_097_152, 8_388_608, true),
            case["signature"].as_str().unwrap(),
            "{name} signature"
        );
    }
}

#[test]
fn subjects_match_php() {
    for case in fixture()["subjects"].as_array().unwrap() {
        let subject = case["subject"].as_str().unwrap();
        assert_eq!(extract_parts_total(subject), case["parts"].as_i64().unwrap(), "{subject:?}");
        assert_eq!(family_key(subject).as_deref(), case["family"].as_str(), "{subject:?}");
    }
}
