//! Matches the files of an NZB to the real names in its PAR2 index. Port of `Par2FileMapper`.
//!
//! An NZB only knows the posted size of each file: its yEnc-encoded articles, a few percent larger
//! than the file itself. A PAR2 entry is matched to an NZB file when that file is the only one
//! whose posted size fits it, and it is the only entry that fits the file. Equal-sized archive
//! volumes stay unmatched unless their first 16 KiB was checked against the index.

use std::collections::{BTreeMap, BTreeSet, HashMap};

use super::download::Par2FileEntry;
use super::planner::NzbFile;

/// Smallest real size, relative to the posted size, still taken as the same file.
const MIN_SIZE_RATIO: f64 = 0.90;

/// Posted sizes are sometimes the decoded size; allow a little rounding above it.
const MAX_SIZE_RATIO: f64 = 1.001;

/// How an NZB file was matched to a PAR2 file entry.
#[derive(Clone, Copy, Debug, PartialEq, Eq, serde::Serialize)]
#[serde(rename_all = "kebab-case")]
pub enum MappingConfidence {
    /// Its first 16 KiB, or its yEnc name and size, matched the PAR2 entry.
    Verified,
    /// It was the only file whose size fits the PAR2 entry, and the other way round.
    UniqueSize,
}

/// An NZB file whose real name the PAR2 index revealed.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct MappedNzbFile {
    pub source_index: usize,
    /// The entry's position in the PAR2 file list.
    pub file_index: usize,
    pub file: Par2FileEntry,
    pub confidence: MappingConfidence,
}

/// Stripped subjects can merge several NZB files into one entry, whose size is then wrong.
fn is_single_file(entry: &NzbFile) -> bool {
    entry.filecount == 1 && entry.size > 0 && !entry.segments.is_empty()
}

/// `(int) ceil($size / $articlePartSize)`; a size of zero or less expects no segments.
fn expected_segments(size: i64, article_part_size: i64) -> usize {
    if size <= 0 {
        return 0;
    }
    usize::try_from((size - 1) / article_part_size + 1).unwrap_or(usize::MAX)
}

/// Maps NZB files (by position) to PAR2 entries. `verified` holds files whose start was already
/// matched, as source index to PAR2 file index; the result is keyed by source index.
pub fn map(
    nzb: &[NzbFile],
    files: &[Par2FileEntry],
    article_part_size: Option<i64>,
    verified: &BTreeMap<usize, usize>,
) -> BTreeMap<usize, MappedNzbFile> {
    let mut mapped = BTreeMap::new();
    for (&source_index, &file_index) in verified {
        if let Some(file) = files.get(file_index) {
            mapped.insert(
                source_index,
                MappedNzbFile {
                    source_index,
                    file_index,
                    file: file.clone(),
                    confidence: MappingConfidence::Verified,
                },
            );
        }
    }
    let taken: BTreeSet<usize> = verified.values().copied().collect();
    let remaining: Vec<usize> = (0..files.len())
        .filter(|index| !taken.contains(index))
        .collect();
    if remaining.is_empty() {
        return mapped;
    }

    // Posted sizes in ascending order; equal sizes keep NZB order, as PHP's stable sort does.
    let mut sources: Vec<(i64, usize)> = nzb
        .iter()
        .enumerate()
        .filter(|(index, entry)| is_single_file(entry) && !mapped.contains_key(index))
        .map(|(index, entry)| (entry.size, index))
        .collect();
    if sources.is_empty() {
        return mapped;
    }
    sources.sort_by_key(|(size, _)| *size);
    let part_size = article_part_size.filter(|size| *size > 0);

    let mut file_fits: Vec<(usize, usize, usize)> = Vec::new(); // (file index, fits, first source)
    let mut entry_fits: HashMap<usize, usize> = HashMap::new();
    for &file_index in &remaining {
        let file_size = files[file_index].size;
        if file_size <= 0 {
            continue;
        }
        let expected = part_size.map(|part| expected_segments(file_size, part));
        let min_posted = file_size - (file_size >> 9);
        let start = sources.partition_point(|(size, _)| *size < min_posted);
        let mut fits = 0usize;
        let mut first = 0usize;
        for &(posted, source_index) in &sources[start..] {
            let ratio = file_size as f64 / posted as f64;
            if ratio < MIN_SIZE_RATIO {
                break;
            }
            if ratio > MAX_SIZE_RATIO {
                continue;
            }
            if expected.is_some_and(|expected| expected != nzb[source_index].segments.len()) {
                continue;
            }
            fits += 1;
            if fits == 1 {
                first = source_index;
            }
            *entry_fits.entry(source_index).or_default() += 1;
        }
        if fits > 0 {
            file_fits.push((file_index, fits, first));
        }
    }
    for (file_index, fits, source_index) in file_fits {
        if fits == 1 && entry_fits.get(&source_index) == Some(&1) {
            mapped.insert(
                source_index,
                MappedNzbFile {
                    source_index,
                    file_index,
                    file: files[file_index].clone(),
                    confidence: MappingConfidence::UniqueSize,
                },
            );
        }
    }
    mapped
}

/// NZB files whose posted size, and segment count when the article size is known, fit a PAR2
/// entry.
pub fn candidate_indexes(
    nzb: &[NzbFile],
    file: &Par2FileEntry,
    article_part_size: Option<i64>,
) -> Vec<usize> {
    let part_size = article_part_size.filter(|size| *size > 0);
    nzb.iter()
        .enumerate()
        .filter(|(_, entry)| is_single_file(entry))
        .filter(|(_, entry)| {
            let ratio = file.size as f64 / entry.size as f64;
            (MIN_SIZE_RATIO..=MAX_SIZE_RATIO).contains(&ratio)
        })
        .filter(|(_, entry)| {
            part_size.is_none_or(|part| expected_segments(file.size, part) == entry.segments.len())
        })
        .map(|(index, _)| index)
        .collect()
}
