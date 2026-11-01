//! Bounded, stable spot-check sampling that spreads first across different files.
//! A stable candidate identity keeps retries from repeatedly sampling a new set.

use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::collections::{HashMap, HashSet};

#[derive(Debug, Clone, Default, Deserialize)]
pub struct SampleManifest {
    #[serde(default)]
    pub identity: String,
    pub files: Vec<SampleFile>,
}

#[derive(Debug, Clone, Default, Deserialize)]
pub struct SampleFile {
    pub key: String,
    #[serde(default)]
    pub totalparts: Option<i64>,
    #[serde(default)]
    pub totalparts_inferred: Option<bool>,
    pub segments: Vec<SampleSegment>,
}

#[derive(Debug, Clone, Default, Deserialize)]
pub struct SampleSegment {
    pub number: i64,
    pub messageid: String,
    pub bytes: i64,
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

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct SampleRow<'a> {
    pub file_key: &'a str,
    pub totalparts: i64,
    pub totalparts_inferred: bool,
    pub number: i64,
    pub messageid: &'a str,
    pub bytes: i64,
}

/// Select the sample for a manifest. Parts with a bad number or size, or too large
/// for either budget, are never chosen. A later duplicate file key replaces the
/// earlier file's parts but keeps its place in the order.
pub fn select(
    manifest: &SampleManifest,
    maximum_articles: i64,
    maximum_header_bytes: i64,
    maximum_article_bytes: Option<i64>,
) -> Vec<SampleRow<'_>> {
    if maximum_articles < 1 || maximum_header_bytes < 1 {
        return Vec::new();
    }
    let article_budget =
        maximum_header_bytes.min(maximum_article_bytes.unwrap_or(maximum_header_bytes));
    let mut files = prepare_files(manifest, article_budget);
    sort_files(&mut files);

    let maximum_articles = usize::try_from(maximum_articles).unwrap_or(usize::MAX);
    let mut selected = Vec::new();
    let mut seen = HashSet::new();
    let mut bytes = 0_i64;
    let mut round = 0;
    while selected.len() < maximum_articles {
        let mut added = false;
        for ranked in &files {
            let Some(part) = ranked.segments.get(round) else {
                continue;
            };
            let file = &manifest.files[ranked.file_index];
            let segment = &file.segments[part.segment_index];
            if seen.contains(segment.messageid.as_str()) {
                continue;
            }
            // Each part fits the budget alone, so only a budget near i64::MAX can
            // overflow here, and such a sum is over any budget that matters.
            let Some(next_bytes) = bytes.checked_add(segment.bytes) else {
                continue;
            };
            if next_bytes > maximum_header_bytes {
                continue;
            }
            selected.push(SampleRow {
                file_key: &file.key,
                totalparts: file.totalparts.unwrap_or(0).max(0),
                totalparts_inferred: ranked.inferred,
                number: segment.number,
                messageid: &segment.messageid,
                bytes: segment.bytes,
            });
            seen.insert(segment.messageid.as_str());
            bytes = next_bytes;
            added = true;
            if selected.len() >= maximum_articles {
                break;
            }
        }
        // Stop even when a later round might contain unseen IDs or smaller articles,
        // because advancing past an empty round would change the sample.
        if !added {
            break;
        }
        round += 1;
    }
    selected
}

fn prepare_files(manifest: &SampleManifest, article_budget: i64) -> Vec<RankedFile> {
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
                digest: rank_digest(&prefix, segment.messageid.as_bytes()),
            });
        }
        if segments.is_empty() {
            continue;
        }
        // Stable sorts keep input order for duplicate message IDs and hash ties.
        segments.sort_by(|a, b| {
            b.terminal
                .cmp(&a.terminal)
                .then_with(|| a.digest.cmp(&b.digest))
        });
        if let Some(&position) = positions.get(file.key.as_str()) {
            let previous: &mut RankedFile = &mut files[position];
            previous.file_index = file_index;
            previous.inferred = inferred;
            previous.segments = segments;
        } else {
            let digest = rank_digest(&prefix, file.key.as_bytes());
            positions.insert(file.key.as_str(), files.len());
            files.push(RankedFile {
                file_index,
                inferred,
                digest,
                segments,
            });
        }
    }
    files
}

fn sort_files(files: &mut [RankedFile]) {
    files.sort_by(|a, b| {
        b.inferred
            .cmp(&a.inferred)
            .then_with(|| a.digest.cmp(&b.digest))
    });
}

fn rank_digest(prefix: &Sha256, value: &[u8]) -> [u8; 32] {
    let mut hasher = prefix.clone();
    hasher.update(value);
    hasher.finalize().into()
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::{Value, json};

    fn select_json(
        input: &[u8],
        articles: i64,
        bytes: i64,
        article_bytes: Option<i64>,
    ) -> Option<String> {
        let manifest: SampleManifest = serde_json::from_slice(input).ok()?;
        serde_json::to_string(&super::select(&manifest, articles, bytes, article_bytes)).ok()
    }

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
        let parsed: SampleManifest = serde_json::from_slice(&input).unwrap();
        let files = prepare_files(&parsed, 100);
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
}
