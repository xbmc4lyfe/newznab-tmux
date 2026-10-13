//! Typed bounded sampling that preserves the PHP planner, with explicit fallback.

use std::borrow::Cow;
use std::collections::{HashMap, HashSet};

use serde::de::{MapAccess, Visitor, value::MapAccessDeserializer};
use serde::{Deserialize, Serialize};
use std::marker::PhantomData;
use std::ops::Deref;

// serde structs otherwise also accept positional JSON arrays. PHP inventory
// records are named arrays; reject sequence records and request PHP fallback.
struct StrictObject<T>(T);

impl<T> Deref for StrictObject<T> {
    type Target = T;
    fn deref(&self) -> &T {
        &self.0
    }
}

impl<'de, T: Deserialize<'de>> Deserialize<'de> for StrictObject<T> {
    fn deserialize<D: serde::Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        struct ObjectVisitor<T>(PhantomData<T>);
        impl<'de, T: Deserialize<'de>> Visitor<'de> for ObjectVisitor<T> {
            type Value = StrictObject<T>;
            fn expecting(&self, formatter: &mut std::fmt::Formatter) -> std::fmt::Result {
                formatter.write_str("a named JSON object record")
            }
            fn visit_map<M: MapAccess<'de>>(self, map: M) -> Result<Self::Value, M::Error> {
                T::deserialize(MapAccessDeserializer::new(map)).map(StrictObject)
            }
        }
        deserializer.deserialize_map(ObjectVisitor(PhantomData))
    }
}
use sha2::{Digest, Sha256};

const MAX_NATIVE_ARTICLES: i64 = 16;
const MAX_RESULT_BYTES: usize = 1_048_576;

#[derive(Deserialize)]
struct Manifest<'a> {
    #[serde(default, borrow)]
    identity: Cow<'a, str>,
    #[serde(borrow)]
    files: Vec<StrictObject<File<'a>>>,
}

#[derive(Deserialize)]
struct File<'a> {
    #[serde(borrow)]
    key: Cow<'a, str>,
    #[serde(default)]
    totalparts: Option<i64>,
    #[serde(default)]
    totalparts_inferred: Option<bool>,
    #[serde(borrow)]
    segments: Vec<StrictObject<Segment<'a>>>,
}

#[derive(Deserialize)]
struct Segment<'a> {
    number: i64,
    #[serde(borrow)]
    messageid: Cow<'a, str>,
    bytes: i64,
}

struct RankedSegment {
    segment_index: usize,
    terminal: bool,
    digest: [u8; 32],
}

struct RankedFile {
    file_index: usize,
    inferred: bool,
    digest: [u8; 32],
    segments: Vec<RankedSegment>,
}

// Declaration order intentionally matches SpotCheckPlanner's PHP row order.
#[derive(Serialize)]
struct SampleRow<'a> {
    file_key: &'a str,
    totalparts: i64,
    totalparts_inferred: bool,
    number: i64,
    messageid: &'a str,
    bytes: i64,
}

/// Select the PHP planner's sample for the inventory's typed JSON subset.
///
/// `None` asks the caller to run the unchanged PHP planner. This
/// includes malformed/coercion-dependent fields, associative lists, counts over
/// sixteen, sums that PHP would promote to float, and digests that PHP could
/// compare as numeric strings. Unused manifest fields are ignored. No manifest
/// state or cache survives this call.
pub fn select_json(
    input: &[u8],
    maximum_articles: i64,
    maximum_header_bytes: i64,
    maximum_article_bytes: Option<i64>,
) -> Option<String> {
    if maximum_articles < 1 || maximum_header_bytes < 1 {
        return Some("[]".to_owned());
    }
    if maximum_articles > MAX_NATIVE_ARTICLES {
        return None;
    }

    let manifest: StrictObject<Manifest<'_>> = serde_json::from_slice(input).ok()?;
    let article_budget =
        maximum_header_bytes.min(maximum_article_bytes.unwrap_or(maximum_header_bytes));
    let mut files = prepare_files(&manifest, article_budget)?;
    sort_files(&mut files);

    let mut selected = Vec::with_capacity(maximum_articles as usize);
    let mut seen = HashSet::with_capacity(maximum_articles as usize);
    let mut bytes = 0_u64;
    let budget = maximum_header_bytes as u64;
    let mut round = 0;
    let mut result_bound = 2_usize;
    while selected.len() < maximum_articles as usize {
        let mut added = false;
        for ranked in &files {
            let Some(part) = ranked.segments.get(round) else {
                continue;
            };
            let file = &manifest.files[ranked.file_index];
            let segment = &file.segments[part.segment_index];
            if seen.contains(segment.messageid.as_ref()) {
                continue;
            }
            let next_bytes = bytes.checked_add(segment.bytes as u64)?;
            // PHP promotes overflowing integer sums to float. Rounded float
            // comparisons can admit an article above this integer budget, so
            // delegate that coercion-sensitive case rather than change it.
            if next_bytes > i64::MAX as u64 {
                return None;
            }
            if next_bytes > budget {
                continue;
            }
            // File keys repeat in every output row. Bound the worst-case JSON
            // expansion before serialization, so a large key cannot multiply a
            // bounded input into an unbounded result allocation. PHP retains
            // its original strings when this optional native path falls back.
            let row_bound = file
                .key
                .len()
                .checked_add(segment.messageid.len())?
                .checked_mul(6)?
                .checked_add(256)?;
            result_bound = result_bound.checked_add(row_bound)?;
            if result_bound > MAX_RESULT_BYTES {
                return None;
            }
            selected.push(SampleRow {
                file_key: file.key.as_ref(),
                totalparts: file.totalparts.unwrap_or(0).max(0),
                totalparts_inferred: ranked.inferred,
                number: segment.number,
                messageid: segment.messageid.as_ref(),
                bytes: segment.bytes,
            });
            seen.insert(segment.messageid.as_ref());
            bytes = next_bytes;
            added = true;
            if selected.len() >= maximum_articles as usize {
                break;
            }
        }
        // PHP stops here even when a later round might contain unseen IDs or
        // smaller articles. Advancing past an empty round changes its sample.
        if !added {
            break;
        }
        round += 1;
    }

    serde_json::to_string(&selected).ok()
}

fn prepare_files(manifest: &Manifest<'_>, article_budget: i64) -> Option<Vec<RankedFile>> {
    let mut prefix = Sha256::new();
    prefix.update(manifest.identity.as_bytes());
    prefix.update(b"\0");

    let mut files: Vec<RankedFile> = Vec::new();
    let mut positions = HashMap::new();
    for (file_index, file) in manifest.files.iter().enumerate() {
        let inferred = file.totalparts_inferred == Some(true);
        let totalparts = file.totalparts.unwrap_or(0).max(0);
        let mut segments = Vec::new();
        for (segment_index, segment) in file.segments.iter().enumerate() {
            if segment.messageid.is_empty()
                || segment.number < 1
                || segment.bytes < 1
                || segment.bytes > article_budget
            {
                continue;
            }
            segments.push(RankedSegment {
                segment_index,
                terminal: inferred && segment.number == totalparts,
                digest: rank_digest(&prefix, segment.messageid.as_bytes())?,
            });
        }
        if segments.is_empty() {
            continue;
        }
        // Stable sorts preserve input order for duplicate message IDs and hash
        // ties, as PHP 8's usort does. Each comparator uses cached SHA bytes.
        segments.sort_by(|a, b| {
            b.terminal
                .cmp(&a.terminal)
                .then_with(|| a.digest.cmp(&b.digest))
        });
        if let Some(&position) = positions.get(file.key.as_ref()) {
            let previous: &mut RankedFile = &mut files[position];
            previous.file_index = file_index;
            previous.inferred = inferred;
            previous.segments = segments;
        } else {
            let digest = rank_digest(&prefix, file.key.as_bytes())?;
            positions.insert(file.key.as_ref(), files.len());
            files.push(RankedFile {
                file_index,
                inferred,
                digest,
                segments,
            });
        }
    }
    Some(files)
}

fn sort_files(files: &mut [RankedFile]) {
    files.sort_by(|a, b| {
        b.inferred
            .cmp(&a.inferred)
            .then_with(|| a.digest.cmp(&b.digest))
    });
}

fn rank_digest(prefix: &Sha256, value: &[u8]) -> Option<[u8; 32]> {
    let mut hasher = prefix.clone();
    hasher.update(value);
    let digest: [u8; 32] = hasher.finalize().into();
    (!php_numeric_digest(&digest)).then_some(digest)
}

fn php_numeric_digest(digest: &[u8; 32]) -> bool {
    // A hex SHA is a PHP numeric string when it contains only decimal digits,
    // or digits followed by one 'e' and further digits (decimal exponent).
    let mut digits = 0;
    let mut exponent = false;
    for nibble in digest.iter().flat_map(|byte| [byte >> 4, byte & 0x0f]) {
        match nibble {
            0..=9 => digits += 1,
            14 if !exponent && digits > 0 => {
                exponent = true;
                digits = 0;
            }
            _ => return false,
        }
    }
    digits > 0
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::{Value, json};

    fn select(manifest: Value, articles: i64, bytes: i64, article_bytes: Option<i64>) -> Value {
        let input = serde_json::to_vec(&manifest).unwrap();
        serde_json::from_str(&select_json(&input, articles, bytes, article_bytes).unwrap()).unwrap()
    }

    #[test]
    fn prioritizes_inferred_files_and_their_terminal_part() {
        let manifest = json!({"identity":"release", "files":[
            {"key":"normal", "totalparts":2, "segments":[
                {"number":1,"messageid":"normal-one","bytes":3}]},
            {"key":"inferred", "totalparts":3, "totalparts_inferred":true,"segments":[
                {"number":1,"messageid":"first","bytes":3},
                {"number":3,"messageid":"last","bytes":4}]}
        ]});
        let result = select(manifest, 3, 20, None);
        assert_eq!(result[0]["messageid"], "last");
        assert_eq!(result[1]["messageid"], "normal-one");
        assert_eq!(result[2]["messageid"], "first");
    }

    #[test]
    fn retains_first_segment_when_duplicate_ids_have_equal_rank() {
        let result = select(
            json!({"files":[{"key":"file","segments":[
                {"number":7,"messageid":"same","bytes":2},
                {"number":8,"messageid":"same","bytes":3}
            ]}]}),
            16,
            20,
            None,
        );
        assert_eq!(
            result,
            json!([{"file_key":"file","totalparts":0,
            "totalparts_inferred":false,"number":7,"messageid":"same","bytes":2}])
        );
    }

    #[test]
    fn replaces_duplicate_file_keys_only_when_replacement_has_eligible_segments() {
        let manifest = json!({"files":[
            {"key":"same","segments":[{"number":1,"messageid":"old","bytes":1}]},
            {"key":"other","segments":[{"number":1,"messageid":"other","bytes":1}]},
            {"key":"same","segments":[{"number":2,"messageid":"new","bytes":2}]},
            {"key":"same","segments":[{"number":0,"messageid":"invalid","bytes":1}]}
        ]});
        let input = serde_json::to_vec(&manifest).unwrap();
        let parsed: Manifest<'_> = serde_json::from_slice(&input).unwrap();
        let files = prepare_files(&parsed, 100).unwrap();
        assert_eq!(files.len(), 2);
        assert_eq!(files[0].file_index, 2);
        assert_eq!(files[1].file_index, 1);
        let result = select(manifest, 16, 100, None);
        assert!(
            result
                .as_array()
                .unwrap()
                .iter()
                .any(|row| row["messageid"] == "new")
        );
        assert!(
            !result
                .as_array()
                .unwrap()
                .iter()
                .any(|row| row["messageid"] == "old")
        );
    }

    #[test]
    fn skips_duplicate_ids_across_files() {
        let result = select(
            json!({"files":[
                {"key":"inferred","totalparts":1,"totalparts_inferred":true,
                    "segments":[{"number":1,"messageid":"same","bytes":2}]},
                {"key":"normal","segments":[{"number":2,"messageid":"same","bytes":3}]}
            ]}),
            16,
            100,
            None,
        );
        assert_eq!(result.as_array().unwrap().len(), 1);
        assert_eq!(result[0]["file_key"], "inferred");
    }

    #[test]
    fn stops_after_a_round_adds_nothing_even_if_later_parts_fit() {
        let result = select(
            json!({"files":[{"key":"file","totalparts":3,
            "totalparts_inferred":true,"segments":[
                {"number":3,"messageid":"same","bytes":2},
                {"number":3,"messageid":"same","bytes":2},
                {"number":1,"messageid":"later","bytes":1}
            ]}]}),
            16,
            100,
            None,
        );
        assert_eq!(result.as_array().unwrap().len(), 1);
        assert_eq!(result[0]["messageid"], "same");
    }

    #[test]
    fn filters_invalid_parts_and_enforces_both_byte_budgets() {
        let result = select(
            json!({"files":[{"key":"file","totalparts":1,
            "totalparts_inferred":true,"segments":[
                {"number":1,"messageid":"valid","bytes":4},
                {"number":2,"messageid":"too-large","bytes":6},
                {"number":0,"messageid":"zero-number","bytes":1},
                {"number":2,"messageid":"zero-bytes","bytes":0},
                {"number":2,"messageid":"","bytes":1},
                {"number":2,"messageid":"later","bytes":2}
            ]}]}),
            16,
            5,
            Some(4),
        );
        assert_eq!(result.as_array().unwrap().len(), 1);
        assert_eq!(result[0]["messageid"], "valid");
        assert_eq!(select(json!({"files":[]}), 1, 1, Some(0)), json!([]));
    }

    #[test]
    fn handles_non_positive_budgets_before_parsing() {
        assert_eq!(select_json(b"invalid", 0, 1, None).as_deref(), Some("[]"));
        assert_eq!(select_json(b"invalid", 1, -1, None).as_deref(), Some("[]"));
        assert_eq!(select_json(b"invalid", -1, 1, None).as_deref(), Some("[]"));
    }

    #[test]
    fn falls_back_for_unsupported_shapes_and_sample_counts() {
        for input in [
            r#"{"files":{}}"#,
            r#"{"files":[null]}"#,
            r#"{"identity":3,"files":[]}"#,
            r#"{"files":[{"key":3,"segments":[]}]}"#,
            r#"{"files":[{"key":"f","segments":{}}]}"#,
            r#"{"files":[{"key":"f","totalparts_inferred":1,"segments":[]}]}"#,
            r#"{"files":[{"key":"f","totalparts":"3","segments":[]}]}"#,
            r#"{"files":[{"key":"f","segments":[{"number":"1","bytes":1,"messageid":"m"}]}]}"#,
            r#"{"files":[{"key":"f","segments":[{"number":1,"bytes":1.0,"messageid":"m"}]}]}"#,
            r#"{"files":[{"key":"f","segments":[{"number":1,"bytes":9223372036854775808,"messageid":"m"}]}]}"#,
            "invalid",
        ] {
            assert_eq!(select_json(input.as_bytes(), 1, 100, None), None, "{input}");
        }
        assert_eq!(select_json(br#"{"files":[]}"#, 17, 100, None), None);
    }

    #[test]
    fn preserves_row_order_unicode_and_default_metadata() {
        let input = br#"{"identity":"\u96ea","unused":{"deep":[1,2]},"files":[{"key":"\u96ea/\u00e9","totalparts":-3,"totalparts_inferred":null,"segments":[{"number":1,"messageid":"\u2603@host","bytes":3,"unused":true}]}]}"#;
        let expected = "[{\"file_key\":\"雪/é\",\"totalparts\":0,\"totalparts_inferred\":false,\"number\":1,\"messageid\":\"☃@host\",\"bytes\":3}]";
        assert_eq!(select_json(input, 1, 3, None).as_deref(), Some(expected));
        assert_eq!(
            select_json(input, 1, 3, None),
            select_json(input, 1, 3, None)
        );
    }

    #[test]
    fn stable_sort_keeps_file_order_for_equal_rank() {
        let mut files = vec![
            RankedFile {
                file_index: 7,
                inferred: false,
                digest: [0; 32],
                segments: vec![],
            },
            RankedFile {
                file_index: 2,
                inferred: false,
                digest: [0; 32],
                segments: vec![],
            },
        ];
        sort_files(&mut files);
        assert_eq!(
            files.iter().map(|file| file.file_index).collect::<Vec<_>>(),
            vec![7, 2]
        );
    }

    #[test]
    fn recognizes_numeric_hex_digests_that_need_php_comparison() {
        assert!(php_numeric_digest(&[0x12; 32]));
        let mut exponent = [0x12; 32];
        exponent[1] = 0xe1;
        assert!(php_numeric_digest(&exponent));
        exponent[2] = 0xe1;
        assert!(!php_numeric_digest(&exponent));
        assert!(!php_numeric_digest(&[0xab; 32]));
    }

    #[test]
    fn falls_back_when_php_would_promote_the_sum_to_float() {
        let manifest = json!({"files":[{"key":"file","totalparts":2,
        "totalparts_inferred":true,"segments":[
            {"number":2,"messageid":"first","bytes":9223372036854775807_i64},
            {"number":1,"messageid":"second","bytes":2}
        ]}]});
        let input = serde_json::to_vec(&manifest).unwrap();
        // The experimental caller must run PHP after this None, preserving
        // PHP's float promotion and comparison instead of skipping the row.
        assert_eq!(select_json(&input, 16, i64::MAX, None), None);
    }
    #[test]
    fn rejects_positional_records_at_every_level() {
        for input in [
            r#"["release",[{"key":"f","segments":[{"number":1,"messageid":"m","bytes":2}]}]]"#,
            r#"{"files":[["f",0,false,[{"number":1,"messageid":"m","bytes":2}]]]}"#,
            r#"{"files":[{"key":"f","segments":[[1,"m",2]]}]}"#,
        ] {
            assert!(
                select_json(input.as_bytes(), 1, 10, None).is_none(),
                "{input}"
            );
        }
    }
    #[test]
    fn bounds_repeated_key_output_before_serialization() {
        let manifest = json!({"files":[{"key":"x".repeat(100_000),"segments":[
            {"number":1,"messageid":"one","bytes":1},
            {"number":2,"messageid":"two","bytes":1}
        ]}]});
        let input = serde_json::to_vec(&manifest).unwrap();
        assert!(select_json(&input, 2, 2, None).is_none());
        assert!(select_json(&input, 1, 1, None).is_some());
    }
}
