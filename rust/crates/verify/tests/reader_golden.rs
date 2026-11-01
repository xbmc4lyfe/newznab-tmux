//! The NZB reader and coverage rules against `fixtures/reader_golden.json`, recorded from the PHP
//! reference by `fixtures/generate_reader_golden.php`.

use nntmux_verify::nzb::coverage::{coverage_ids, document_fingerprint, parse_document};
use nntmux_verify::reader::{PasswordMetadataRejected, ReleaseFacts, read_manifest};
use serde_json::Value;

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/reader_golden.json")).expect("golden JSON")
}

#[test]
fn documents_read_as_php_read_them() {
    let facts = ReleaseFacts {
        searchname: "Search".to_owned(),
        fromname: "From".to_owned(),
        postdate: Some("2026-01-02 03:04:05".to_owned()),
        groups_id: 3,
        ..ReleaseFacts::default()
    };
    for (name, entry) in golden().as_object().expect("documents") {
        let xml = entry["xml"].as_str().expect("xml").as_bytes();
        let read = read_manifest(xml, &facts);
        match (&read, entry.get("error").and_then(Value::as_str)) {
            (Err(error), Some("password")) => {
                assert!(error.is::<PasswordMetadataRejected>(), "{name}: {error:#}");
            }
            (Err(error), Some(_)) => assert!(!error.is::<PasswordMetadataRejected>(), "{name}"),
            (Ok(manifest), None) => {
                let expected = &entry["manifest"];
                let got = serde_json::to_value(manifest).expect("value");
                for key in [
                    "subject",
                    "fromname",
                    "date",
                    "groups_id",
                    "regex_id",
                    "expected_files",
                    "groups",
                    "metadata_source",
                ] {
                    assert_eq!(got[key], expected[key], "{name}: {key}");
                }
                assert_eq!(
                    manifest.conflict,
                    expected["conflict"].as_bool().unwrap_or(false),
                    "{name}"
                );
                assert_eq!(got["passwords"], expected["passwords"], "{name}");
                assert_eq!(
                    got["verification_mode"], expected["verification_mode"],
                    "{name}"
                );
                let files = expected["files"].as_array().expect("files");
                assert_eq!(manifest.files.len(), files.len(), "{name}");
                for (file, want) in manifest.files.iter().zip(files) {
                    assert_eq!(file.key, want["key"].as_str().expect("key"), "{name}");
                    assert_eq!(
                        file.subject,
                        want["subject"].as_str().expect("subject"),
                        "{name}"
                    );
                    assert_eq!(
                        file.totalparts,
                        want["totalparts"].as_i64().expect("parts"),
                        "{name}"
                    );
                    assert_eq!(
                        file.totalparts_inferred,
                        want["totalparts_inferred"].as_bool().expect("inferred"),
                        "{name}"
                    );
                    let segments = want["segments"].as_array().expect("segments");
                    assert_eq!(file.segments.len(), segments.len(), "{name}");
                    for (segment, want) in file.segments.iter().zip(segments) {
                        assert_eq!(
                            segment.number,
                            want["number"].as_i64().expect("number"),
                            "{name}"
                        );
                        assert_eq!(
                            segment.bytes,
                            want["bytes"].as_i64().expect("bytes"),
                            "{name}"
                        );
                        assert_eq!(
                            segment.messageid,
                            want["messageid"].as_str().expect("id"),
                            "{name}"
                        );
                    }
                }
            }
            (read, expected) => panic!("{name}: {read:?} but PHP said {expected:?}"),
        }
    }
}

#[test]
fn coverage_and_fingerprints_match_php() {
    for (name, entry) in golden().as_object().expect("documents") {
        let document = parse_document(entry["xml"].as_str().expect("xml").as_bytes());
        let mut ids: Option<Vec<String>> = document
            .as_ref()
            .and_then(coverage_ids)
            .map(|ids| ids.into_iter().collect());
        if let Some(ids) = &mut ids {
            ids.sort();
        }
        let expected: Option<Vec<String>> = entry
            .get("ids")
            .map(|ids| serde_json::from_value(ids.clone()).expect("ids"));
        assert_eq!(ids, expected, "{name}");
        let fingerprint = document.as_ref().and_then(document_fingerprint);
        assert_eq!(
            fingerprint.as_deref(),
            entry["fingerprint"].as_str(),
            "{name}"
        );
    }
}
