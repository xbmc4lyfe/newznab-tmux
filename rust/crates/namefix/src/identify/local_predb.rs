//! The PreDB table held in memory: by name, and by size for the in-scope rows.

use std::collections::HashMap;

use super::name::folded;
use crate::identity_key;

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct PredbRow {
    pub id: i64,
    pub title: String,
    pub category: String,
    pub size_mib: u32,
    pub files: u32,
    pub pre_at: Option<i64>,
}

/// How a name matched a row. `Exact` is the project's identity key, which ignores case and
/// separators; `Normalized` also ignores everything but letters and digits.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum NameMatch {
    Exact,
    Normalized,
}

#[derive(Default)]
pub struct LocalPredb {
    rows: Vec<PredbRow>,
    exact: HashMap<String, u32>,
    normalized: HashMap<String, u32>,
    /// (size, row) of the rows a high-quality TV or movie post could be, by size.
    sized: Vec<(u32, u32)>,
}

/// A post's encoded payload over a PreDB size runs from nothing to a few percent of overhead.
const PAYLOAD_RATIO: (f64, f64) = (1.0, 1.05);
pub(super) const POSTED_BEFORE_PRE_SECONDS: i64 = 86_400;
pub(super) const POSTED_AFTER_PRE_SECONDS: i64 = 7 * 86_400;

impl LocalPredb {
    pub fn insert(&mut self, row: PredbRow) {
        let index = u32::try_from(self.rows.len()).expect("under 4 billion PreDB rows");
        // Sources repeat a title. The first row with a size is the one worth keeping.
        let rows = &self.rows;
        let keep = |current: &mut u32| {
            if rows[*current as usize].size_mib == 0 && row.size_mib > 0 {
                *current = index;
            }
        };
        if let Some(key) = identity_key::normalize(&row.title) {
            self.exact.entry(key).and_modify(keep).or_insert(index);
        }
        let fold = folded(&row.title);
        if fold.len() >= 8 {
            self.normalized
                .entry(fold)
                .and_modify(keep)
                .or_insert(index);
        }
        if row.size_mib > 0 && row.pre_at.is_some() && in_scope_title(&row) {
            self.sized.push((row.size_mib, index));
        }
        self.rows.push(row);
    }

    /// Call once after the last [`Self::insert`].
    pub fn finish(&mut self) {
        self.sized.sort_unstable();
    }

    pub fn len(&self) -> usize {
        self.rows.len()
    }

    pub fn is_empty(&self) -> bool {
        self.rows.is_empty()
    }

    pub fn by_name(&self, name: &str) -> Option<(&PredbRow, NameMatch)> {
        if let Some(key) = identity_key::normalize(name)
            && let Some(index) = self.exact.get(&key)
        {
            return Some((&self.rows[*index as usize], NameMatch::Exact));
        }
        let fold = folded(name);
        if fold.len() < 8 {
            return None;
        }
        self.normalized
            .get(&fold)
            .map(|index| (&self.rows[*index as usize], NameMatch::Normalized))
    }

    /// Rows a post of this payload size, posted then, could be. The number of files isn't a
    /// constraint, because a repost as one MKV is still the same release.
    pub fn by_size(&self, payload_bytes: u64, posted_at: i64) -> Vec<&PredbRow> {
        let (low, high) = size_range_mib(payload_bytes);
        let start = self.sized.partition_point(|(size, _)| *size < low);
        self.sized[start..]
            .iter()
            .take_while(|(size, _)| *size <= high)
            .map(|(_, index)| &self.rows[*index as usize])
            .filter(|row| {
                row.pre_at.is_some_and(|pre_at| {
                    posted_at >= pre_at - POSTED_BEFORE_PRE_SECONDS
                        && posted_at <= pre_at + POSTED_AFTER_PRE_SECONDS
                })
            })
            .collect()
    }
}

/// The PreDB sizes a post of this payload could be.
pub(super) fn size_range_mib(payload_bytes: u64) -> (u32, u32) {
    let mib = payload_bytes as f64 / 1_048_576.0;
    (
        (mib / PAYLOAD_RATIO.1).floor() as u32,
        (mib / PAYLOAD_RATIO.0).ceil() as u32,
    )
}

/// The cheap check that keeps the size list down to rows in scope.
fn in_scope_title(row: &PredbRow) -> bool {
    let category = row.category.to_ascii_uppercase();
    if !(category.is_empty() || category.starts_with("TV") || category.starts_with("MOVIE")) {
        return false;
    }
    let lower = row.title.to_ascii_lowercase();
    let resolution = lower.contains("1080") || lower.contains("2160");
    let source = ["web", "bluray", "blu-ray", "remux", "bdrip"]
        .iter()
        .any(|word| lower.contains(word));
    resolution && source && !lower.contains("webrip") && !lower.contains("xxx")
}

/// PreDB sizes read like `2468MB`, and some feeds write `2468.37MB`.
pub fn parse_size_mib(value: &str) -> u32 {
    value
        .strip_suffix("MB")
        .and_then(|digits| digits.trim().parse::<f64>().ok())
        .map_or(0, |size| size.round() as u32)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn row(id: i64, title: &str, size_mib: u32, pre_at: i64) -> PredbRow {
        PredbRow {
            id,
            title: title.to_owned(),
            category: "TV".to_owned(),
            size_mib,
            files: 18,
            pre_at: Some(pre_at),
        }
    }

    fn index() -> LocalPredb {
        let mut index = LocalPredb::default();
        index.insert(row(
            1,
            "Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR",
            0,
            1000,
        ));
        index.insert(row(
            2,
            "Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR",
            2468,
            1000,
        ));
        index.insert(row(3, "Other.Show.S01E01.720p.WEB.h264-GRP", 2468, 1000));
        index.insert(row(4, "Third.Show.S01E01.2160p.WEB.h265-GRP", 9000, 1000));
        index.finish();
        index
    }

    #[test]
    fn a_repeat_with_a_size_wins() {
        let index = index();
        let (hit, how) = index
            .by_name("zmora s01e05 1080p amzn web-dl ddp5.1 h.264-rawr")
            .expect("match");
        assert_eq!((hit.id, how), (2, NameMatch::Exact));
    }

    #[test]
    fn separators_and_punctuation_only_match_normalized() {
        let index = index();
        let (hit, how) = index
            .by_name("Zmora-S01E05-1080p-AMZN-WEBDL-DDP51-H264-RAWR")
            .expect("match");
        assert_eq!((hit.id, how), (2, NameMatch::Normalized));
        assert!(index.by_name("Zmora.S01E06.1080p").is_none());
        assert!(index.by_name("abc").is_none());
    }

    #[test]
    fn size_needs_the_size_and_the_time_to_fit() {
        let index = index();
        let bytes = 2468 * 1_048_576 * 102 / 100;
        let ids =
            |posted| -> Vec<i64> { index.by_size(bytes, posted).iter().map(|r| r.id).collect() };
        // Row 3 is 720p, so it never gets on the list.
        assert_eq!(ids(1000), [2]);
        assert_eq!(ids(1000 + 8 * 86_400), Vec::<i64>::new());
        assert_eq!(ids(1000 - 2 * 86_400), Vec::<i64>::new());
        assert!(index.by_size(100 * 1_048_576, 1000).is_empty());
    }

    #[test]
    fn reads_sizes() {
        assert_eq!(parse_size_mib("2468MB"), 2468);
        assert_eq!(parse_size_mib("2468.37MB"), 2468);
        assert_eq!(parse_size_mib(""), 0);
        assert_eq!(parse_size_mib("0MB"), 0);
    }
}
