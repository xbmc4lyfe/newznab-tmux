//! Parsing and naming recorded from the PHP reference. `fixtures/parse/generate.php` made them.

use std::collections::{BTreeMap, HashMap};

use nntmux_core::pcre::Pcre;
use nntmux_ingest::clean::{CollectionRegex, binary_hash, clean_subject};
use nntmux_ingest::header::{
    Overview, article_range, file_counter, mark_per_article_obfuscated, match_subject, parse_date,
    validation_error, xref_groups, xref_tokens,
};
use serde_json::Value;

fn golden(kind: &str) -> Vec<Value> {
    include_str!("fixtures/parse/golden.jsonl")
        .lines()
        .map(|line| serde_json::from_str::<Value>(line).unwrap())
        .filter(|row| row["kind"] == kind)
        .collect()
}

fn bytes(value: &Value) -> Vec<u8> {
    hex::decode(value.as_str().unwrap()).unwrap()
}

fn regexes() -> HashMap<String, Vec<CollectionRegex>> {
    let raw: BTreeMap<String, Vec<(i32, String)>> =
        serde_json::from_str(include_str!("fixtures/parse/regexes.json")).unwrap();
    raw.into_iter()
        .map(|(group, rows)| {
            let compiled = rows
                .into_iter()
                .map(|(id, regex)| CollectionRegex {
                    id,
                    regex: Pcre::new(&regex).unwrap(),
                })
                .collect();
            (group, compiled)
        })
        .collect()
}

#[test]
fn parses_subjects_like_php() {
    let rows = golden("subject");
    assert!(rows.len() > 500, "{} subjects", rows.len());
    let mut failures = Vec::new();
    for row in &rows {
        let subject = bytes(&row["subject"]);
        let found = match_subject(&subject);
        let expected = row["raw_base"].as_str().map(|_| {
            (
                bytes(&row["raw_base"]),
                row["part"].as_i64().unwrap(),
                row["total"].as_i64().unwrap(),
            )
        });
        let actual = found.map(|hit| (hit.base, hit.part, hit.total));
        if actual != expected {
            failures.push(format!("{:?}", String::from_utf8_lossy(&subject)));
        }
    }
    assert!(failures.is_empty(), "{}", failures.join("\n"));
}

#[test]
fn counts_files_like_php() {
    for row in golden("subject") {
        let base = bytes(&row["base"]);
        let (file, total) = file_counter(&base);
        assert_eq!(
            [file, total],
            [
                row["counter"][0].as_i64().unwrap(),
                row["counter"][1].as_i64().unwrap()
            ],
            "{:?}",
            String::from_utf8_lossy(&base)
        );
    }
}

#[test]
fn names_collections_like_php() {
    let regexes = regexes();
    let mut failures = Vec::new();
    let mut compared = 0;
    for row in golden("subject") {
        let base = bytes(&row["base"]);
        for (group, expected) in row["names"].as_object().unwrap() {
            let clean = clean_subject(&regexes[group], &base, group.as_bytes());
            compared += 1;
            let id = expected[0].as_i64().unwrap();
            let name = bytes(&expected[1]);
            if i64::from(clean.id) != id || clean.name != name {
                failures.push(format!(
                    "{group}: {:?}\n  php  {id} {:?}\n  rust {} {:?}",
                    String::from_utf8_lossy(&base),
                    String::from_utf8_lossy(&name),
                    clean.id,
                    String::from_utf8_lossy(&clean.name),
                ));
            }
        }
    }
    assert!(compared > 2500, "{compared} names");
    assert!(
        failures.is_empty(),
        "{} of {compared} differ:\n{}",
        failures.len(),
        failures.join("\n")
    );
}

#[test]
fn hashes_binaries_like_php() {
    for row in golden("binary") {
        let hash = binary_hash(
            &bytes(&row["base"]),
            &bytes(&row["from"]),
            row["file"].as_i64().unwrap(),
        );
        assert_eq!(hash, row["hash"].as_str().unwrap(), "{row}");
    }
}

#[test]
fn reads_xref_like_php() {
    let hexes = |values: Vec<Vec<u8>>| values.iter().map(hex::encode).collect::<Vec<_>>();
    let expected = |value: &Value| {
        value
            .as_array()
            .unwrap()
            .iter()
            .map(|item| item.as_str().unwrap().to_owned())
            .collect::<Vec<_>>()
    };
    for row in golden("xref") {
        let xref = bytes(&row["xref"]);
        assert_eq!(hexes(xref_tokens(&xref)), expected(&row["tokens"]), "{row}");
        assert_eq!(hexes(xref_groups(&xref)), expected(&row["groups"]), "{row}");
    }
}

#[test]
fn validates_message_ids_like_php() {
    for row in golden("message_id") {
        let error = validation_error(1, &bytes(&row["id"]));
        assert_eq!(error, row["error"].as_str(), "{row}");
    }
    assert_eq!(validation_error(0, b"<a@b>"), Some("Invalid part number"));
}

#[test]
fn reads_dates_like_strtotime() {
    for row in golden("date") {
        let date = row["date"].as_str().unwrap();
        assert_eq!(parse_date(date), row["time"].as_i64(), "{date}");
    }
}

#[test]
fn marks_obfuscation_like_php() {
    let rows = golden("obfuscation");
    assert!(
        rows.iter()
            .any(|row| !row["marked"].as_array().unwrap().is_empty())
    );
    for row in rows {
        let headers: Vec<Overview> = row["headers"]
            .as_array()
            .unwrap()
            .iter()
            .map(|header| Overview {
                number: header[0].as_u64().unwrap(),
                subject: bytes(&header[1]),
                ..Overview::default()
            })
            .collect();
        let min = row["min"].as_u64().unwrap() as usize;
        let mut marked: Vec<u64> = mark_per_article_obfuscated(&headers, min)
            .into_iter()
            .collect();
        marked.sort_unstable();
        let expected: Vec<u64> = row["marked"]
            .as_array()
            .unwrap()
            .iter()
            .map(|number| number.as_u64().unwrap())
            .collect();
        assert_eq!(marked, expected, "{} at {min}", row["name"]);
    }
}

#[test]
fn bounds_the_article_range_to_the_request() {
    let row = &golden("range")[0];
    let headers: Vec<Overview> = row["headers"]
        .as_array()
        .unwrap()
        .iter()
        .map(|header| Overview {
            number: header["Number"].as_u64().unwrap(),
            date: header["Date"].as_str().unwrap().as_bytes().to_vec(),
            ..Overview::default()
        })
        .collect();
    let range = article_range(&headers, 5, 10).unwrap();
    assert_eq!(
        range.first_number,
        row["result"]["firstArticleNumber"].as_u64().unwrap()
    );
    assert_eq!(
        range.last_number,
        row["result"]["lastArticleNumber"].as_u64().unwrap()
    );
    assert_eq!(range.first_date, b"a");
    assert_eq!(range.last_date, b"b");
    assert!(article_range(&headers, 100, 200).is_none());
}
