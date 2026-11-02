//! The PAR2 file mapper against PHP's output, from `fixtures/generate_par2_mapping_golden.php`.

use std::collections::BTreeMap;

use nntmux_postprocess::additional::download::Par2FileEntry;
use nntmux_postprocess::additional::par2_mapping::{self, MappingConfidence};
use nntmux_postprocess::additional::planner::NzbFile;
use serde::Deserialize;

#[derive(Deserialize)]
struct Case {
    name: String,
    nzb: Vec<Entry>,
    files: Vec<File>,
    article_part_size: Option<i64>,
    verified: BTreeMap<String, usize>,
    mapped: Vec<Mapped>,
    candidates: Vec<Vec<usize>>,
}

#[derive(Deserialize)]
struct Entry {
    size: i64,
    segments: usize,
    filecount: i64,
}

#[derive(Deserialize)]
struct File {
    name: String,
    size: i64,
}

#[derive(Deserialize)]
struct Mapped {
    source_index: usize,
    file_index: usize,
    confidence: String,
}

#[test]
fn mappings_match_php() {
    let cases: Vec<Case> =
        serde_json::from_str(include_str!("fixtures/par2_mapping_golden.json")).expect("valid");
    for case in cases {
        let nzb: Vec<NzbFile> = case
            .nzb
            .iter()
            .map(|entry| NzbFile {
                title: "f".to_owned(),
                segments: (1..=entry.segments).map(|n| format!("s{n}@x")).collect(),
                size: entry.size,
                filecount: entry.filecount,
                firstsegment: true,
            })
            .collect();
        let files: Vec<Par2FileEntry> = case
            .files
            .iter()
            .map(|file| Par2FileEntry {
                name: file.name.clone(),
                size: file.size,
                hash16k: String::new(),
            })
            .collect();
        let verified: BTreeMap<usize, usize> = case
            .verified
            .iter()
            .map(|(source, file)| (source.parse().expect("index"), *file))
            .collect();
        let mapped: Vec<(usize, usize, &str)> =
            par2_mapping::map(&nzb, &files, case.article_part_size, &verified)
                .values()
                .map(|mapped| {
                    let confidence = match mapped.confidence {
                        MappingConfidence::Verified => "verified",
                        MappingConfidence::UniqueSize => "unique-size",
                    };
                    (mapped.source_index, mapped.file_index, confidence)
                })
                .collect();
        let expected: Vec<(usize, usize, &str)> = case
            .mapped
            .iter()
            .map(|mapped| {
                (
                    mapped.source_index,
                    mapped.file_index,
                    mapped.confidence.as_str(),
                )
            })
            .collect();
        assert_eq!(mapped, expected, "{}: map", case.name);
        for (index, file) in files.iter().enumerate() {
            assert_eq!(
                par2_mapping::candidate_indexes(&nzb, file, case.article_part_size),
                case.candidates[index],
                "{}: candidates for {}",
                case.name,
                file.name
            );
        }
    }
}
