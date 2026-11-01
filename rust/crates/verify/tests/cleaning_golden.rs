//! The built-in release name patterns against `fixtures/cleaning_golden.json`, recorded from the PHP
//! reference by `fixtures/generate_cleaning_golden.php`.

use nntmux_verify::cleaning::{Cleaned, built_in, cleaner_helper, release_name_from_file};
use serde_json::Value;

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/cleaning_golden.json")).expect("golden JSON")
}

fn string(value: &Value) -> &str {
    value.as_str().expect("a string")
}

#[test]
fn built_in_patterns_match_php() {
    for case in golden()["built_in"].as_array().expect("cases") {
        let (subject, group) = (string(&case["subject"]), string(&case["group"]));
        let (text, name) = match built_in(subject, group) {
            Cleaned::Text(text) => (Some(text), None),
            Cleaned::Named { name, .. } => (None, Some(name)),
        };
        assert_eq!(text.as_deref(), case["text"].as_str(), "{subject}");
        assert_eq!(name.as_deref(), case["name"].as_str(), "{subject}");
    }
}

#[test]
fn file_names_match_php() {
    for case in golden()["files"].as_array().expect("cases") {
        let file = string(&case["file"]);
        assert_eq!(
            release_name_from_file(file).as_deref(),
            case["name"].as_str(),
            "{file}"
        );
    }
}

#[test]
fn the_helper_matches_php() {
    for case in golden()["helpers"].as_array().expect("cases") {
        let subject = string(&case["subject"]);
        assert_eq!(cleaner_helper(subject), string(&case["clean"]), "{subject}");
    }
}
