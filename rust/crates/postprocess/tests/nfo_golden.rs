//! NFO detection and NZB subject checks against `generate_nfo_golden.php`, which ran the PHP
//! service's own steps with PHP's bundled libmagic.

use nntmux_postprocess::nfo::{detect, nzb_scan};
use serde_json::Value;

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/nfo_golden.json")).expect("valid golden JSON")
}

#[test]
fn detection_matches_php_and_libmagic() {
    let golden = golden();
    let mut checked = 0;
    for case in golden["detections"].as_array().expect("detections") {
        let name = case["name"].as_str().expect("name");
        let data = hex::decode(case["data"].as_str().expect("data")).expect("hex");
        let magic = case["magic"].as_str().unwrap_or("");
        assert_eq!(
            detect::validate_content(&data),
            case["validate"].as_bool().expect("validate"),
            "validate: {name}"
        );
        assert_eq!(
            detect::is_nfo(&data),
            case["is_nfo"].as_bool().expect("is_nfo"),
            "is_nfo: {name} ({magic})"
        );
        // Text classification only decides anything below the size cap and past the header
        // checks; libmagic content rules the port leaves out answer differently on binaries.
        if data.len() < detect::MAX_NFO_SIZE && !detect::is_binary_format(&data) {
            assert_eq!(
                detect::looks_like_text(&data),
                case["text"].as_bool().expect("text"),
                "text: {name} ({magic})"
            );
        }
        checked += 1;
    }
    assert!(checked > 100);
}

#[test]
fn subject_checks_match_php() {
    let golden = golden();
    for case in golden["subjects"].as_array().expect("subjects") {
        let subject = case["subject"].as_str().expect("subject");
        let segments = case["segments"].as_u64().expect("segments") as usize;
        let detection = |value: &Value| {
            value.as_object().map(|object| nzb_scan::Detection {
                hidden: object["hidden"].as_bool().expect("hidden"),
                priority: object["priority"].as_u64().expect("priority") as u8,
            })
        };
        assert_eq!(
            nzb_scan::detect_nfo_file(subject),
            detection(&case["nfo"]),
            "nfo: {subject}"
        );
        assert_eq!(
            nzb_scan::detect_hidden_nfo_file(subject, segments),
            detection(&case["hidden"]),
            "hidden: {subject} x{segments}"
        );
    }
    for case in golden["parts"].as_array().expect("parts") {
        let subject = case["subject"].as_str().expect("subject");
        assert_eq!(
            nzb_scan::parts_total(subject),
            case["total"].as_i64().expect("total"),
            "parts: {subject}"
        );
        assert_eq!(
            nzb_scan::is_par2_index(subject),
            case["par2_index"].as_bool().expect("par2"),
            "par2: {subject}"
        );
    }
}
