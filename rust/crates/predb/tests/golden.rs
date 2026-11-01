//! Filename cleaning, evidence and season detection against `fixtures/generate_golden.php`.

use md5::Digest;
use nntmux_predb::filename::*;
use nntmux_predb::identity::keys_for_row;
use nntmux_predb::season::*;
use nntmux_predb::selector::{PredbHit, select_best_match};
use serde_json::{Value, json};

#[test]
fn matches_the_php() {
    let mut failures = Vec::new();
    let mut rows = 0;
    for line in include_str!("fixtures/golden.jsonl").lines() {
        let row: Value = serde_json::from_str(line).expect("golden row");
        rows += 1;
        let name = row["n"].as_str().expect("name");
        let evidence = OriginalFilenameEvidence::new(name);
        let title = strip_file_suffixes(name);
        let hits = [
            PredbHit {
                id: 7,
                title: title.clone(),
                filename: name.to_owned(),
                source: "x".into(),
            },
            PredbHit {
                id: 8,
                title: format!("{title}.other"),
                filename: String::new(),
                source: "x".into(),
            },
        ];
        let twin = nntmux_categorize::pcre::compile(r"E0?(\d)", true);
        let twin =
            String::from_utf8_lossy(&twin.replacen(name.as_bytes(), 1, &b"E2$1"[..])).into_owned();
        let pairs = [name.to_owned(), twin];
        let actual = [
            ("strip", json!(title)),
            ("clean", json!(clean_for_matching(name, false))),
            ("cleanp", json!(clean_for_matching(name, true))),
            ("norm", json!(normalize_candidate_title(name))),
            ("fmt", json!(format_search_name(name, None))),
            ("plaus", json!(is_plausible_release_title(name))),
            ("scene", json!(looks_like_scene_release(name))),
            ("tech", json!(is_literal_technical_scene_title(name))),
            ("title", json!(clean_for_title_match(name))),
            ("payload", json!(literal_payload(name))),
            ("trail", json!(strip_literal_subject_trailer(name))),
            ("base", json!(evidence.basename)),
            ("stem", json!(evidence.stem)),
            ("pipe", json!(evidence.is_locally_rejected_pipe())),
            (
                "keys",
                json!(evidence.canonical_nomination_keys().map(|keys| {
                    keys.iter()
                        .map(|key| format!("{:x}", md5::Md5::digest(key)))
                        .collect::<Vec<_>>()
                })),
            ),
            ("acc", json!(evidence.accepts(&title, ""))),
            ("acca", json!(evidence.accepts("Unrelated.Title-GRP", name))),
            (
                "row",
                json!(keys_for_row(5, &title, name).map(|keys| {
                    let rows: Vec<String> = keys
                        .into_iter()
                        .map(|(kind, key)| format!(r#"{{"kind":{kind},"key_digest":"{key}"}}"#))
                        .collect();
                    format!("{:x}", md5::Md5::digest(format!("[{}]", rows.join(","))))
                })),
            ),
            (
                "sel",
                json!(select_best_match(&title, &hits, None).map(|h| h.id)),
            ),
            (
                "sele",
                json!(select_best_match(&title, &hits, Some(&evidence)).map(|h| h.id)),
            ),
            ("sp", json!(is_season_pack(name))),
            ("ep", json!(is_episode_name(name))),
            ("un", json!(is_unclassified_episode_name(name))),
            ("anc", json!(is_ancillary_name(name))),
            ("id", json!(episode_identity(name))),
            ("one", json!(season_name_from_episode(name))),
            ("set", json!(season_name_from_episodes(&pairs))),
            ("multi", json!(is_multi_episode_set(&pairs))),
            ("nons", json!(has_non_single_episode_evidence(&[name]))),
            ("fold", json!(season_folder(&[format!("{name}/x.mkv")]))),
        ];
        for (field, value) in actual {
            if value != row[field] {
                failures.push(format!("{field} {name:?}: got {value}, php {}", row[field]));
            }
        }
    }
    assert!(rows > 1000, "corpus too small: {rows}");
    let mut by_field = std::collections::BTreeMap::<String, usize>::new();
    for failure in &failures {
        *by_field
            .entry(failure.split(' ').next().unwrap_or("").to_owned())
            .or_default() += 1;
    }
    assert!(
        failures.is_empty(),
        "{} differ {by_field:?}:\n{}",
        failures.len(),
        failures
            .iter()
            .take(40)
            .cloned()
            .collect::<Vec<_>>()
            .join("\n")
    );
}
