//! Videos stored uncompressed in archive volumes, as scene releases store them, cut straight out
//! of the downloaded data. Port of `ArchivedMediaSlicer` and `ArchivedMediaEntry`.
//!
//! No unrar is needed: the file listing says exactly where the video starts and how much of it
//! the volume holds.

use std::sync::{Arc, LazyLock};

use nntmux_core::pcre::Pcre;

use super::container::VideoContainer;
use crate::additional::context::ReleaseContext;
use crate::additional::planner::ArchiveCandidate;
use crate::archive_info::{ArchiveInfo, FileRecord, ListEntry, MAIN_SOURCE};

static SAMPLE: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/(?:^|[\\\/._ -])sample(?:[\\\/._ -]|$)/i").expect("valid"));

/// The settings the slicer reads.
#[derive(Clone, Copy, Debug)]
pub struct SlicerSettings {
    pub archive_slicing: bool,
    pub process_thumbnails: bool,
    pub sample_max_bytes: i64,
    pub main_video_min_bytes: i64,
}

impl SlicerSettings {
    pub fn from_config(config: &crate::config::Config) -> Self {
        Self {
            archive_slicing: config.media.archive_slicing,
            process_thumbnails: config.planner.process_thumbnails,
            sample_max_bytes: config.media.sample_max_bytes,
            main_video_min_bytes: config.planner.media_main_video_min_bytes,
        }
    }
}

/// A video stored uncompressed in a downloaded archive volume, and the bytes of it we hold.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ArchivedMediaEntry {
    pub name: Vec<u8>,
    pub real_size: i64,
    /// NZB file of the volume.
    pub source_index: usize,
    /// Start of the video, as far as it was downloaded.
    pub head_bytes: Vec<u8>,
    /// Offset of the video's first byte in the volume.
    pub range_start: i64,
    /// Offset of the video's last byte in the volume.
    pub in_volume_end: i64,
    /// Leading bytes of the volume that were downloaded.
    pub volume_bytes_downloaded: i64,
    /// Leading NZB segments those bytes came from.
    pub volume_segments_downloaded: usize,
    pub is_sample: bool,
    pub container: Option<VideoContainer>,
}

impl ArchivedMediaEntry {
    pub fn is_complete(&self) -> bool {
        self.head_bytes.len() as i64 >= self.real_size
    }

    /// Bytes that can still be appended from later segments of the same volume, up to `target`
    /// in all.
    pub fn continuation_bytes(&self, target: i64) -> i64 {
        let head = self.head_bytes.len() as i64;
        // Only a head that runs to the end of the downloaded data can be continued.
        if self.range_start + head != self.volume_bytes_downloaded {
            return 0;
        }
        let wanted = target.min(self.real_size) - head;
        let left_in_volume = self.in_volume_end + 1 - self.volume_bytes_downloaded;
        wanted.min(left_in_volume).max(0)
    }
}

/// `register`: keeps the start of each stored video in a volume's listing for the media-source
/// stage. `files` is the listing of `data`.
pub fn register(
    settings: &SlicerSettings,
    context: &mut ReleaseContext,
    data: &[u8],
    files: &[ListEntry],
    source: Option<&ArchiveCandidate>,
) {
    if !settings.archive_slicing || !settings.process_thumbnails {
        return;
    }
    let Some(source) = source else {
        return;
    };
    for file in stored_videos(files) {
        if let Some(entry) = entry_for(
            settings,
            data,
            file,
            source.source_index,
            source.message_ids.len(),
        ) {
            context.remember_archived_media(entry);
        }
    }
}

/// `chunkFromVolume`: the chunk of a stored video that a later volume holds, read from that
/// volume's first bytes.
pub fn chunk_from_volume(volume: &[u8], entry_name: &[u8]) -> Option<Vec<u8>> {
    let mut archive = ArchiveInfo::from_data(Arc::new(volume.to_vec()), true, None).ok()?;
    if !archive.error().is_empty() {
        return None;
    }
    let files = archive.archive_file_list(true).ok()??;
    let wanted = base_name(entry_name);
    for file in stored_videos(&files) {
        if file.name != entry_name && base_name(&file.name) != wanted {
            continue;
        }
        let (start, end) = file.range?;
        let length = volume.len() as i64;
        if start < length {
            return Some(php_substr(volume, start, end.min(length - 1) - start + 1));
        }
    }
    None
}

fn entry_for(
    settings: &SlicerSettings,
    data: &[u8],
    file: &FileRecord,
    source_index: usize,
    segments_downloaded: usize,
) -> Option<ArchivedMediaEntry> {
    let (start, _) = file.range?;
    let length = data.len() as i64;
    if start >= length {
        return None;
    }
    let size = file.size;
    let in_volume_end = match file.next_offset {
        Some(next) if next > start => next - 1,
        _ => start + size - 1,
    };
    let available = in_volume_end.min(length - 1) - start + 1;
    let head = php_substr(data, start, available.min(settings.sample_max_bytes));
    let container = VideoContainer::detect(&head)
        .or_else(|| VideoContainer::from_file_name(&String::from_utf8_lossy(&file.name)));
    Some(ArchivedMediaEntry {
        name: file.name.clone(),
        real_size: size,
        source_index,
        head_bytes: head,
        range_start: start,
        in_volume_end,
        volume_bytes_downloaded: length,
        volume_segments_downloaded: segments_downloaded,
        is_sample: SAMPLE.is_match(&file.name) || size < settings.main_video_min_bytes,
        container,
    })
}

/// Unencrypted, uncompressed video entries of the archive itself, not of nested archives.
fn stored_videos(files: &[ListEntry]) -> impl Iterator<Item = &FileRecord> {
    files.iter().filter_map(|entry| match entry {
        ListEntry::File(file)
            if file.range.is_some()
                && file.source == MAIN_SOURCE
                && file.pass == 0
                && !file.is_dir
                && file.compressed == Some(0)
                && file.size > 0
                && VideoContainer::from_file_name(&String::from_utf8_lossy(&file.name))
                    .is_some() =>
        {
            Some(file)
        }
        _ => None,
    })
}

/// `basename(str_replace('\\', '/', $name))`.
fn base_name(name: &[u8]) -> Vec<u8> {
    let path: Vec<u8> = name
        .iter()
        .map(|byte| if *byte == b'\\' { b'/' } else { *byte })
        .collect();
    let trimmed = match path.iter().rposition(|byte| *byte != b'/') {
        Some(last) => &path[..=last],
        None => return Vec::new(),
    };
    match trimmed.iter().rposition(|byte| *byte == b'/') {
        Some(slash) => trimmed[slash + 1..].to_vec(),
        None => trimmed.to_vec(),
    }
}

/// `substr($data, $start, $length)` for a start inside the data: a negative length leaves that
/// many bytes off the end.
fn php_substr(data: &[u8], start: i64, length: i64) -> Vec<u8> {
    let total = data.len() as i64;
    let start = start.clamp(0, total);
    let end = if length < 0 {
        total + length
    } else {
        start.saturating_add(length).min(total)
    };
    if end <= start {
        return Vec::new();
    }
    data[start as usize..end as usize].to_vec()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn base_names_match_php() {
        assert_eq!(base_name(b"dir\\Movie.mkv"), b"Movie.mkv");
        assert_eq!(base_name(b"a/b/"), b"b");
        assert_eq!(base_name(b"///"), b"");
        assert_eq!(base_name(b"plain"), b"plain");
    }

    #[test]
    fn substr_takes_negative_lengths_from_the_end() {
        assert_eq!(php_substr(b"abcdef", 1, 3), b"bcd");
        assert_eq!(php_substr(b"abcdef", 1, -2), b"bcd");
        assert_eq!(php_substr(b"abcdef", 4, -3), b"");
        assert_eq!(php_substr(b"abcdef", 2, 0), b"");
        assert_eq!(php_substr(b"abcdef", 2, 100), b"cdef");
    }

    #[test]
    fn continuation_needs_a_head_that_reaches_the_end_of_the_data() {
        let entry = ArchivedMediaEntry {
            name: b"Movie.mkv".to_vec(),
            real_size: 10_000,
            source_index: 0,
            head_bytes: vec![0; 900],
            range_start: 100,
            in_volume_end: 4_999,
            volume_bytes_downloaded: 1_000,
            volume_segments_downloaded: 2,
            is_sample: false,
            container: None,
        };
        // Up to the target, the real size, and the end of the volume.
        assert_eq!(entry.continuation_bytes(2_000), 1_100);
        assert_eq!(entry.continuation_bytes(100_000), 4_000);
        assert_eq!(entry.continuation_bytes(500), 0);
        let cut = ArchivedMediaEntry {
            volume_bytes_downloaded: 1_500,
            ..entry.clone()
        };
        assert_eq!(cut.continuation_bytes(2_000), 0);
        assert!(!entry.is_complete());
    }
}
