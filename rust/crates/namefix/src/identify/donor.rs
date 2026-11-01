//! Donors: releases that already have a name and share a file with one that doesn't.
//!
//! A key is something about a file that a re-post leaves unchanged. A key whose donors disagree
//! about the name identifies nothing.

use std::collections::{HashMap, HashSet};

use super::name::folded;

/// Which fact two releases share, strongest first.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash, PartialOrd, Ord)]
pub enum DonorKey {
    MkvUid,
    FullMd5,
    Crc32,
    Hash16k,
    FileSize,
}

impl DonorKey {
    pub const ALL: [Self; 5] = [
        Self::MkvUid,
        Self::FullMd5,
        Self::Crc32,
        Self::Hash16k,
        Self::FileSize,
    ];

    pub const fn method(self) -> &'static str {
        match self {
            Self::MkvUid => "donor_mkv_uid",
            Self::FullMd5 => "donor_full_md5",
            Self::Crc32 => "donor_crc32",
            Self::Hash16k => "donor_hash16k",
            Self::FileSize => "donor_file_size",
        }
    }

    pub const fn confidence(self) -> f64 {
        match self {
            Self::MkvUid | Self::FullMd5 => 0.95,
            Self::Crc32 => 0.9,
            Self::Hash16k => 0.8,
            // Same bytes count, different CRC: usually the same encode with its tags rewritten.
            Self::FileSize => 0.6,
        }
    }
}

/// Small files recur across unrelated posts: uploader adverts, registry files, shared DLLs.
pub const MIN_DONOR_HASHED_SIZE: i64 = 50_000_000;
const MIN_DONOR_FILE_SIZE: i64 = 500_000_000;
const VIDEO_EXTENSIONS: [&str; 7] = [".mkv", ".mp4", ".avi", ".m2ts", ".ts", ".mov", ".wmv"];

/// Whether a file's exact size could point at one encode. It has to be big and a video, because
/// archive volumes all come in the same few sizes, and a round number of MiB is a split.
pub fn size_can_identify(file_name: &str, size: i64) -> bool {
    let lower = file_name.to_ascii_lowercase();
    size >= MIN_DONOR_FILE_SIZE
        && size % 1_048_576 != 0
        && VIDEO_EXTENSIONS.iter().any(|ext| lower.ends_with(ext))
}

/// Posts of one payload differ by their PAR2 share and yEnc overhead, not by more than this.
const SAME_POST_RATIO: std::ops::RangeInclusive<f64> = 0.8..=1.25;

#[derive(Default)]
pub struct DonorIndex {
    /// Each donor's name and the size of its whole post.
    names: HashMap<i64, (String, i64)>,
    keys: HashMap<(DonorKey, String), Vec<i64>>,
}

pub enum DonorMatch<'a> {
    None,
    Found {
        key: DonorKey,
        release_id: i64,
        name: &'a str,
    },
    /// Donors under this key carry different names.
    Conflict(DonorKey),
}

impl DonorIndex {
    /// Declares a release as a donor. Its keys only count once this has been called for it.
    pub fn add_name(&mut self, release_id: i64, name: String, size_bytes: i64) {
        self.names.insert(release_id, (name, size_bytes));
    }

    pub fn is_donor(&self, release_id: i64) -> bool {
        self.names.contains_key(&release_id)
    }

    pub fn add_key(&mut self, key: DonorKey, value: String, release_id: i64) {
        self.keys.entry((key, value)).or_default().push(release_id);
    }

    /// The strongest key with an answer. `own_id` keeps a release from donating to itself. A name
    /// describes a donor's whole post, so a donor much bigger or smaller than `own_size` only
    /// shares a part of it, like an episode and its season pack, and has no say.
    pub fn find<'a>(
        &'a self,
        facts: &[(DonorKey, String)],
        own_id: i64,
        own_size: i64,
    ) -> DonorMatch<'a> {
        for kind in DonorKey::ALL {
            let mut names: HashSet<String> = HashSet::new();
            let mut best: Option<(i64, &'a str)> = None;
            for (key, value) in facts.iter().filter(|(key, _)| *key == kind) {
                let Some(donors) = self.keys.get(&(*key, value.clone())) else {
                    continue;
                };
                for donor in donors.iter().filter(|donor| **donor != own_id) {
                    let Some((name, size)) = self.names.get(donor) else {
                        continue;
                    };
                    let ratio = own_size as f64 / (*size).max(1) as f64;
                    if !SAME_POST_RATIO.contains(&ratio) {
                        continue;
                    }
                    names.insert(folded(name));
                    if best.is_none_or(|(id, _)| *donor < id) {
                        best = Some((*donor, name));
                    }
                }
            }
            match (names.len(), best) {
                (0, _) | (_, None) => {}
                (1, Some((release_id, name))) => {
                    return DonorMatch::Found {
                        key: kind,
                        release_id,
                        name,
                    };
                }
                _ => return DonorMatch::Conflict(kind),
            }
        }
        DonorMatch::None
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const SIZE: i64 = 5_000_000_000;

    fn index() -> DonorIndex {
        let mut index = DonorIndex::default();
        index.add_name(1, "Some.Film.2021.1080p.WEB-DL.H264-GRP".to_owned(), SIZE);
        index.add_name(2, "some film 2021 1080p web-dl h264-grp".to_owned(), SIZE);
        index.add_name(3, "Other.Film.2020.1080p.WEB-DL.H264-GRP".to_owned(), SIZE);
        index.add_key(DonorKey::Crc32, "same".to_owned(), 1);
        index.add_key(DonorKey::Crc32, "same".to_owned(), 2);
        index.add_key(DonorKey::Crc32, "split".to_owned(), 1);
        index.add_key(DonorKey::Crc32, "split".to_owned(), 3);
        index.add_key(DonorKey::FullMd5, "own".to_owned(), 9);
        index.add_key(DonorKey::FullMd5, "nameless".to_owned(), 8);
        index
    }

    fn fact(key: DonorKey, value: &str) -> (DonorKey, String) {
        (key, value.to_owned())
    }

    #[test]
    fn only_a_big_video_size_can_identify() {
        assert!(size_can_identify("7d8c650a91cf437a.mkv", 8_539_116_558));
        assert!(!size_can_identify("abc.part01.rar", 1_073_741_825));
        assert!(!size_can_identify("abc.mkv", 400_000_000));
        assert!(!size_can_identify("abc.mkv", 1_048_576_000));
    }

    #[test]
    fn donors_that_agree_name_the_release() {
        let found = index();
        let DonorMatch::Found {
            key, release_id, ..
        } = found.find(&[fact(DonorKey::Crc32, "same")], 100, SIZE)
        else {
            panic!("expected a donor");
        };
        assert_eq!((key, release_id), (DonorKey::Crc32, 1));
    }

    #[test]
    fn a_post_of_another_size_lends_no_name() {
        let index = index();
        let same = [fact(DonorKey::Crc32, "same")];
        // An episode's file is in a pack twenty times its size, and the other way around.
        for own_size in [SIZE / 20, SIZE * 20] {
            assert!(matches!(index.find(&same, 100, own_size), DonorMatch::None));
        }
        assert!(matches!(
            index.find(&same, 100, SIZE * 11 / 10),
            DonorMatch::Found { .. }
        ));
    }

    #[test]
    fn donors_that_disagree_name_nothing() {
        let index = index();
        assert!(matches!(
            index.find(&[fact(DonorKey::Crc32, "split")], 100, SIZE),
            DonorMatch::Conflict(DonorKey::Crc32)
        ));
    }

    #[test]
    fn a_release_cannot_donate_to_itself_and_needs_a_name() {
        let index = index();
        assert!(matches!(
            index.find(&[fact(DonorKey::FullMd5, "own")], 9, SIZE),
            DonorMatch::None
        ));
        assert!(matches!(
            index.find(&[fact(DonorKey::FullMd5, "nameless")], 100, SIZE),
            DonorMatch::None
        ));
    }

    #[test]
    fn the_stronger_key_is_asked_first() {
        let mut index = index();
        index.add_key(DonorKey::MkvUid, "uid".to_owned(), 3);
        let DonorMatch::Found {
            key, release_id, ..
        } = index.find(
            &[fact(DonorKey::Crc32, "same"), fact(DonorKey::MkvUid, "uid")],
            100,
            SIZE,
        )
        else {
            panic!("expected a donor");
        };
        assert_eq!((key, release_id), (DonorKey::MkvUid, 3));
    }
}
