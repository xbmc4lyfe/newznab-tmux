//! NFO file names, NFO encoding normalization and inner-file release names against
//! `generate_release_file_evidence_golden.php`, which ran the PHP manager's own methods.

use nntmux_postprocess::additional::release_file_evidence::{
    extract_release_name_from_file, is_nfo_filename, normalize_nfo_encoding,
};
use nntmux_predb::filename::{is_plausible_release_title, normalize_candidate_title};
use serde_json::Value;

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/release_file_evidence_golden.json"))
        .expect("valid golden JSON")
}

#[test]
fn nfo_file_names_match_php() {
    let golden = golden();
    let cases = golden["nfo_filenames"].as_array().expect("cases");
    for case in cases {
        let name = case["name"].as_str().expect("name");
        assert_eq!(
            is_nfo_filename(name),
            case["nfo"].as_bool().expect("nfo"),
            "{name:?}"
        );
    }
    assert!(cases.len() > 20);
}

#[test]
fn nfo_encodings_normalize_as_php_did() {
    let golden = golden();
    let cases = golden["encodings"].as_array().expect("cases");
    for case in cases {
        let name = case["name"].as_str().expect("name");
        let data = hex::decode(case["data"].as_str().expect("data")).expect("hex");
        let expected = hex::decode(case["normalized"].as_str().expect("normalized")).expect("hex");
        assert_eq!(
            normalize_nfo_encoding(&data),
            expected,
            "{name}: {:?}",
            String::from_utf8_lossy(&expected)
        );
    }
    assert!(cases.len() > 15);
}

#[test]
fn inner_file_names_become_the_same_candidates() {
    let golden = golden();
    let cases = golden["extractions"].as_array().expect("cases");
    for case in cases {
        let name = case["name"].as_str().expect("name");
        let extracted = extract_release_name_from_file(name);
        assert_eq!(
            extracted.as_deref(),
            case["extracted"].as_str(),
            "extracted: {name:?}"
        );
        let candidate = extracted.as_deref().map(normalize_candidate_title);
        assert_eq!(
            candidate.as_deref(),
            case["candidate"].as_str(),
            "candidate: {name:?}"
        );
        assert_eq!(
            candidate.as_deref().map(is_plausible_release_title),
            case["plausible"].as_bool(),
            "plausible: {name:?}"
        );
    }
    assert!(cases.len() > 20);
}
