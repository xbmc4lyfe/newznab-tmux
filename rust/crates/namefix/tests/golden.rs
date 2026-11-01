//! Every ported function against the real PHP classes. `fixtures/generate_golden.php` ran them
//! over the shared categorize corpus, the PHP tests' strings and hand-built inputs, and wrote one
//! row per input; each row's results are compared field for field.

use nntmux_namefix::checkers::check;
use nntmux_namefix::donor::{Donor, select, select_canonical};
use nntmux_namefix::extractors::{
    extract_from_file, extract_from_nfo, identity_text, nfo::*, predb_candidates,
};
use nntmux_namefix::file_prioritizer::{
    get_crc_priority, prioritize_for_matching, prioritize_for_pre_db,
};
use nntmux_namefix::identity_key;
use nntmux_namefix::predb_constrained::{Hit, Suggestion, complete_evidence, parse, suggest};
use nntmux_namefix::result::NameFixResult;
use serde_json::{Map, Value, json};

fn result(found: Option<NameFixResult>) -> Value {
    found.map_or(Value::Null, |r| {
        json!({"new_name": r.new_name, "method": r.method, "checker": r.checker_name,
            "confidence": r.confidence})
    })
}

/// PHP writes a whole float as `1`, so compare every number as a float.
fn canon(value: &Value) -> Value {
    match value {
        Value::Number(n) => json!(n.as_f64().expect("finite")),
        Value::Array(items) => Value::Array(items.iter().map(canon).collect()),
        Value::Object(map) => {
            Value::Object(map.iter().map(|(k, v)| (k.clone(), canon(v))).collect())
        }
        other => other.clone(),
    }
}

fn strings(value: &Value) -> Vec<String> {
    value
        .as_array()
        .expect("array")
        .iter()
        .map(|v| v.as_str().expect("string").to_owned())
        .collect()
}

fn donor(value: &Value) -> Donor {
    let int = |key: &str| value.get(key).and_then(Value::as_i64);
    let text = |key: &str| value.get(key).and_then(Value::as_str).map(str::to_owned);
    Donor {
        releases_id: int("releases_id"),
        id: int("id"),
        relsize: int("relsize"),
        size: int("size"),
        searchname: text("searchname"),
        predb_id: int("predb_id"),
        canonical_title: text("canonical_title"),
    }
}

fn donor_json(donor: &Donor) -> Value {
    let mut map = Map::new();
    let mut put = |key: &str, value: Option<Value>| {
        if let Some(value) = value {
            map.insert(key.to_owned(), value);
        }
    };
    put("releases_id", donor.releases_id.map(Value::from));
    put("id", donor.id.map(Value::from));
    put("relsize", donor.relsize.map(Value::from));
    put("size", donor.size.map(Value::from));
    put("searchname", donor.searchname.clone().map(Value::from));
    put("predb_id", donor.predb_id.map(Value::from));
    put(
        "canonical_title",
        donor.canonical_title.clone().map(Value::from),
    );
    Value::Object(map)
}

fn suggestion_json(s: &Suggestion) -> Value {
    let mut map = Map::new();
    map.insert("id".into(), s.id.map_or(Value::Null, Value::from));
    map.insert("reason".into(), json!(s.reason));
    if let Some(score) = s.score {
        map.insert("score".into(), json!(score));
    }
    if let Some(title) = &s.title {
        map.insert("title".into(), json!(title));
    }
    if !s.ranked.is_empty() {
        let ranked: Vec<Value> = s
            .ranked
            .iter()
            .map(|r| json!({"id": r.id, "score": r.score, "title": r.title}))
            .collect();
        map.insert("ranked".into(), Value::Array(ranked));
    }
    if let Some(inner) = &s.suggestion {
        map.insert("suggestion".into(), suggestion_json(inner));
    }
    Value::Object(map)
}

/// PHP encodes an empty `markers` array as `[]`, a populated one as an object.
fn parsed_json(name: &str) -> Value {
    parse(name).map_or(Value::Null, |p| {
        let markers: Map<String, Value> =
            p.markers.into_iter().map(|(k, v)| (k, json!(v))).collect();
        json!({"title": p.title, "markers": markers, "suffix": p.suffix})
    })
}

fn php_parsed(value: &Value) -> Value {
    if value.is_null() {
        return Value::Null;
    }
    let mut value = value.clone();
    if value["markers"] == json!([]) {
        value["markers"] = json!({});
    }
    value
}

#[test]
fn matches_the_php() {
    let mut failures: Vec<String> = Vec::new();
    let mut counts = std::collections::BTreeMap::<String, usize>::new();
    let mut warned = 0;
    let mut expect = |what: &str, input: &str, expected: &Value, actual: Value| {
        if canon(expected) != canon(&actual) {
            failures.push(format!(
                "{what} {input:?}\n   php:  {expected}\n   rust: {actual}"
            ));
        }
    };
    for line in include_str!("fixtures/golden.jsonl").lines() {
        let row: Value = serde_json::from_str(line).expect("golden row");
        let kind = row["k"].as_str().expect("kind");
        *counts.entry(kind.to_owned()).or_default() += 1;
        warned += usize::from(row.get("warn").is_some());
        match kind {
            "name" => {
                let name = row["n"].as_str().expect("name");
                expect("check", name, &row["check"], result(check(name)));
                expect("file", name, &row["file"], result(extract_from_file(name)));
                expect("crc", name, &row["crc"], json!(get_crc_priority(name)));
            }
            "ident" => {
                let name = row["n"].as_str().expect("name");
                expect(
                    "key",
                    name,
                    &row["key"],
                    json!(identity_key::normalize(name)),
                );
                expect("parse", name, &php_parsed(&row["parse"]), parsed_json(name));
            }
            "files" => {
                let files = strings(&row["files"]);
                let label = format!("{files:?}");
                expect(
                    "matching",
                    &label,
                    &row["pm"],
                    json!(prioritize_for_matching(&files)),
                );
                expect(
                    "predb",
                    &label,
                    &row["pp"],
                    json!(prioritize_for_pre_db(&files)),
                );
                let crc: Vec<i32> = files.iter().map(|f| get_crc_priority(f)).collect();
                expect("crc", &label, &row["crc"], json!(crc));
            }
            "donor" => {
                let donors: Vec<Donor> = row["donors"]
                    .as_array()
                    .unwrap()
                    .iter()
                    .map(donor)
                    .collect();
                let picked = select(
                    &donors,
                    row["target"].as_i64().unwrap(),
                    row["tolerance"].as_i64().unwrap(),
                )
                .map(|d| donors.iter().position(|x| std::ptr::eq(x, d)).unwrap());
                expect("select", line, &row["sel"], json!(picked));
            }
            "canonical" => {
                let donors: Vec<Donor> = row["donors"]
                    .as_array()
                    .unwrap()
                    .iter()
                    .map(donor)
                    .collect();
                let picked = select_canonical(&donors).map_or(Value::Null, |d| donor_json(&d));
                expect("canonical", line, &row["sel"], picked);
            }
            "constrained" => {
                let query = row["q"].as_str().unwrap();
                let hits: Vec<Hit> = row["hits"]
                    .as_array()
                    .unwrap()
                    .iter()
                    .map(|h| Hit {
                        id: h["id"].as_i64().unwrap(),
                        title: h["title"].as_str().unwrap().to_owned(),
                    })
                    .collect();
                expect(
                    "suggest",
                    query,
                    &row["suggest"],
                    suggestion_json(&suggest(query, &hits)),
                );
                expect(
                    "complete",
                    query,
                    &row["complete"],
                    suggestion_json(&complete_evidence(query, &hits)),
                );
            }
            "nfo" => {
                let text = row["n"].as_str().expect("nfo");
                let label = format!("{:.80}", text);
                expect("identity", &label, &row["id"], json!(identity_text(text)));
                let view = predb_candidates(text);
                expect(
                    "candidates",
                    &label,
                    &row["pc"],
                    json!({"titles": view.titles, "complete": view.complete}),
                );
                expect("nfo", &label, &row["nfo"], result(extract_from_nfo(text)));
                expect("tv", &label, &row["tv"], result(check_for_tv(text)));
                expect(
                    "movie",
                    &label,
                    &row["movie"],
                    result(check_for_movie(text)),
                );
                expect(
                    "music",
                    &label,
                    &row["music"],
                    result(check_for_music(text)),
                );
                expect(
                    "title-year",
                    &label,
                    &row["ty"],
                    result(check_for_title_year(text)),
                );
                expect("game", &label, &row["game"], result(check_for_game(text)));
                expect("iguana", &label, &row["ig"], result(check_for_iguana(text)));
                expect(
                    "nfo check",
                    &label,
                    &row["chk"],
                    result(check(&identity_text(text))),
                );
            }
            other => panic!("unknown row kind {other}"),
        }
    }
    println!("rows: {counts:?}, {warned} with PHP warnings");
    assert!(
        failures.is_empty(),
        "{} mismatches\n{}",
        failures.len(),
        failures
            .iter()
            .take(25)
            .cloned()
            .collect::<Vec<_>>()
            .join("\n")
    );
    assert!(counts["name"] > 5000 && counts["nfo"] > 500 && counts["constrained"] > 300);
    // The IGUANA stage reads `$result[2]` of a group that is often unset; PHP warns there.
    assert_eq!(warned, 3);
}

/// Branches that can never return are not ported. The golden rows prove PHP never returns them.
#[test]
fn php_never_returns_the_unported_file_branches() {
    for line in include_str!("fixtures/golden.jsonl").lines() {
        let row: Value = serde_json::from_str(line).expect("golden row");
        if row["k"] == "name" {
            let method = row["file"]["method"].as_str().unwrap_or("");
            assert!(
                !["R&C", "tvp", "TVP", "Scene RAR release"].contains(&method),
                "{line}"
            );
        }
    }
}
