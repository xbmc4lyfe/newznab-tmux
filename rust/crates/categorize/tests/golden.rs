//! Every name in the corpus, run through the PHP pipeline by `fixtures/generate_golden.php`.

use nntmux_categorize::{Categorizer, names};
use serde_json::Value;

#[test]
fn matches_the_php_pipeline() {
    let mut failures = Vec::new();
    let golden = include_str!("fixtures/golden.jsonl");
    let mut cases = 0;
    for line in golden.lines() {
        let row: Value = serde_json::from_str(line).expect("golden row");
        let name = row["n"].as_str().expect("name");
        if names::is_hashed_or_gibberish(name) != row["h"].as_bool().expect("h") {
            failures.push(format!("hashed {name:?}: expected {}", row["h"]));
        }
        if names::unwrap_nzbsplit(name).as_deref() != row["u"].as_str() {
            failures.push(format!(
                "unwrap {name:?}: {:?} != {}",
                names::unwrap_nzbsplit(name),
                row["u"]
            ));
        }
        if names::extract_obfuscated_subject(name).as_deref() != row["x"].as_str() {
            failures.push(format!(
                "extract {name:?}: {:?} != {}",
                names::extract_obfuscated_subject(name),
                row["x"]
            ));
        }
        for case in row["r"].as_array().expect("results") {
            cases += 1;
            let categorizer = Categorizer {
                categorize_foreign: case["f"].as_bool().expect("f"),
                categorize_web_dl: case["w"].as_bool().expect("w"),
            };
            let decision = categorizer.classify(
                name,
                case["g"].as_str().expect("g"),
                case["p"].as_str().expect("p"),
            );
            if i64::from(decision.category) != case["c"].as_i64().expect("c")
                || decision.matched_by != case["m"].as_str().expect("m")
                || decision.confidence != case["v"].as_f64().expect("v")
                || decision.locked_to_misc != case["l"].as_bool().expect("l")
            {
                failures.push(format!("{name:?} {case}: got {decision:?}"));
            }
        }
    }
    assert!(cases > 1000, "corpus too small: {cases}");
    assert!(
        failures.is_empty(),
        "{} of {cases} differ:\n{}",
        failures.len(),
        failures
            .iter()
            .take(40)
            .cloned()
            .collect::<Vec<_>>()
            .join("\n")
    );
}
