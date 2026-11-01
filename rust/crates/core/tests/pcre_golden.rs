//! Matches recorded from PHP's `preg_match`. `fixtures/pcre/generate.php` made them.

use nntmux_core::pcre::Pcre;
use serde::Deserialize;

#[derive(Deserialize)]
struct Case {
    regex: String,
    subject: String,
    whole: Option<String>,
    named: Option<Vec<(String, String)>>,
}

#[test]
fn matches_what_php_matched() {
    let golden = include_str!("fixtures/pcre/golden.jsonl");
    let mut cases = 0;
    let mut hits = 0;
    let mut failures = Vec::new();
    for line in golden.lines() {
        let case: Case = serde_json::from_str(line).unwrap();
        let regex = String::from_utf8(hex::decode(&case.regex).unwrap()).unwrap();
        let subject = hex::decode(&case.subject).unwrap();
        let found = Pcre::new(&regex).unwrap().captures(&subject);
        let whole = found.as_ref().map(|hit| hex::encode(hit.group(0).unwrap()));
        // PHP gives a group that didn't take part as ''; compare only the ones with text.
        let named = found.as_ref().map(|hit| {
            hit.named()
                .into_iter()
                .filter(|(_, value)| !value.is_empty())
                .map(|(name, value)| (name.to_owned(), hex::encode(value)))
                .collect::<Vec<_>>()
        });
        let expected = case.named.map(|groups| {
            groups
                .into_iter()
                .filter(|(_, value)| !value.is_empty())
                .collect()
        });
        cases += 1;
        hits += usize::from(case.whole.is_some());
        if whole != case.whole || named != expected {
            failures.push(format!(
                "{regex} on {:?}",
                String::from_utf8_lossy(&subject)
            ));
        }
    }
    assert!(cases > 1000 && hits > 100, "{cases} cases, {hits} hits");
    assert!(
        failures.is_empty(),
        "{} of {cases} differ:\n{}",
        failures.len(),
        failures.join("\n")
    );
}
