//! Parser and scorer against the PHP reference. See fixtures/generate_tv_parse.php.

use nntmux_metadata::tv::parse::{Started, parse_release, score_candidate};
use serde_json::Value;

fn opt_str(value: &Value) -> Option<String> {
    value.as_str().map(str::to_owned)
}

#[test]
fn matches_the_php_reference() {
    let golden = include_str!("fixtures/tv_parse.jsonl");
    let mut failures = Vec::new();
    let mut checked = 0;
    for line in golden.lines() {
        let case: Value = serde_json::from_str(line).unwrap();
        if let Some(input) = case["input"].as_str() {
            checked += 1;
            let parsed = parse_release(input);
            let got = parsed.as_ref().map(|parsed| {
                serde_json::json!({
                    "name": parsed.name,
                    "clean_name": parsed.clean_name,
                    "country": parsed.country,
                    "season": parsed.season,
                    "episode": parsed.episode,
                    "season_pack": parsed.season_pack,
                    "airdate": parsed.airdate,
                    "year": parsed.year,
                    "title_without_year": parsed.title_without_year(),
                })
            });
            let got = got.unwrap_or(Value::Null);
            if got != case["parsed"] {
                failures.push(format!(
                    "{input}\n  php:  {}\n  rust: {got}",
                    case["parsed"]
                ));
            }
        } else {
            let args = &case["score"];
            let started_text = opt_str(&args[3]);
            let started = match (&args[3], started_text.as_deref()) {
                (Value::Number(year), _) => Started::Year(year.as_i64().unwrap()),
                (_, Some(text)) => Started::Text(text),
                _ => Started::None,
            };
            let year = args[1].as_i64().map(|year| year as i32);
            let got = score_candidate(
                args[0].as_str().unwrap(),
                year,
                args[2].as_str().unwrap(),
                started,
                args[4].as_str().unwrap(),
                args[5].as_str().unwrap(),
            );
            let want = case["result"].as_f64().unwrap();
            if (got - want).abs() > 1e-9 {
                failures.push(format!("score {args}: php {want}, rust {got}"));
            }
        }
    }
    assert!(checked > 100);
    assert!(
        failures.is_empty(),
        "{} of {checked} differ:\n{}",
        failures.len(),
        failures.join("\n")
    );
}
