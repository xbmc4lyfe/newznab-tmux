//! Sample pictures posted in a release. Port of `ImageInspector` and `ImageCandidateRanker`.

use std::fs;
use std::io::{Read, Seek, SeekFrom};
use std::path::Path;
use std::sync::LazyLock;

use nntmux_core::pcre::Pcre;
use nntmux_metadata::images::{ImageKind, image_size, luma_measure};

/// Smallest width and height accepted; smaller images are icons and banners.
const MIN_EDGE: u32 = 150;
/// Larger images aren't decoded for the blank check; GD would have needed too much memory.
const MAX_MEASURED_PIXELS: u64 = 12_000_000;

/// `ImageInspector::isUsable`: a complete JPEG, PNG or WebP, large enough to show something, and
/// not blank. Blocking.
pub fn is_usable(path: &Path, min_stddev: f64) -> bool {
    let Some((kind, width, height)) = image_size(path) else {
        return false;
    };
    if kind == ImageKind::Other || width < MIN_EDGE || height < MIN_EDGE || !is_complete(path, kind)
    {
        return false;
    }
    if u64::from(width) * u64::from(height) > MAX_MEASURED_PIXELS {
        return true;
    }
    luma_measure(path).is_some_and(|(_, stddev)| stddev >= min_stddev)
}

/// A download cut short decodes to an image whose lower part is grey; its end marker is missing.
fn is_complete(path: &Path, kind: ImageKind) -> bool {
    let Ok(mut file) = fs::File::open(path) else {
        return false;
    };
    let size = file.metadata().map_or(0, |meta| meta.len());
    if size < 16 {
        return false;
    }
    if kind == ImageKind::Webp {
        let mut header = [0u8; 8];
        if file.read_exact(&mut header).is_err() {
            return false;
        }
        let declared = u32::from_le_bytes([header[4], header[5], header[6], header[7]]);
        return size >= u64::from(declared) + 8;
    }
    let mut tail = Vec::new();
    if file
        .seek(SeekFrom::Start(size.saturating_sub(4096)))
        .is_err()
        || file.take(4096).read_to_end(&mut tail).is_err()
    {
        return false;
    }
    while tail.last().is_some_and(|byte| b"\0\r\n ".contains(byte)) {
        tail.pop();
    }
    let last = |count: usize| &tail[tail.len().saturating_sub(count)..];
    match kind {
        ImageKind::Png => last(64).windows(4).any(|window| window == b"IEND"),
        _ => last(1024).windows(2).any(|window| window == b"\xFF\xD9"),
    }
}

static CONTENT: LazyLock<Pcre> = LazyLock::new(|| {
    Pcre::new(r"/(?:^|[^a-z])(?:screens?|screenshots?|screencaps?|caps|contact[ ._-]?sheets?|sheet|thumbs?|thumbnails?|previews?|samples?)(?:[^a-z]|$)/i")
        .expect("a valid regex")
});
static COVER: LazyLock<Pcre> = LazyLock::new(|| {
    Pcre::new(r"/(?:^|[^a-z])(?:cover|front|poster|folder|box|keyart)(?:[^a-z]|$)/i")
        .expect("a valid regex")
});
static LEAST: LazyLock<Pcre> = LazyLock::new(|| {
    Pcre::new(r"/(?:^|[^a-z])(?:back|rear|cd\d*|dvd\d*|disc\d*|disk\d*|label|inlay|insert|proof|logo|banner|avatar|sig|signature|spine)(?:[^a-z]|$)/i")
        .expect("a valid regex")
});
static QUOTED: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r#"/"([^"]+)"/"#).expect("a valid regex"));

/// `ImageCandidateRanker::rank`: screen captures and contact sheets first (0), covers (1), the
/// rest (2), and scans of labels, backs, proofs and banners last (3).
pub fn rank(name: &str) -> u8 {
    let name = file_name(name);
    let name = name.as_bytes();
    // "CD1.screens.jpg" is a contact sheet; "cover.back.jpg" is not a front cover.
    if CONTENT.is_match(name) {
        0
    } else if LEAST.is_match(name) {
        3
    } else if COVER.is_match(name) {
        1
    } else {
        2
    }
}

/// `ImageCandidateRanker::sort`: by rank, then larger first. Stable, as PHP 8's `usort` is.
pub fn sort_candidates<T>(
    items: &mut [T],
    name_of: impl Fn(&T) -> &str,
    size_of: impl Fn(&T) -> i64,
) {
    items.sort_by_key(|item| (rank(name_of(item)), std::cmp::Reverse(size_of(item))));
}

/// `ImageCandidateRanker::fileName`: the file name inside a yEnc subject, or a path's base name.
pub fn file_name(name: &str) -> String {
    let quoted = QUOTED
        .captures(name.as_bytes())
        .and_then(|captures| captures.group(1).map(<[u8]>::to_vec));
    let name = quoted.map_or_else(
        || name.to_owned(),
        |bytes| String::from_utf8_lossy(&bytes).into_owned(),
    );
    let path = name
        .trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B'])
        .replace('\\', "/");
    let path = path.trim_end_matches('/');
    path.rsplit('/').next().unwrap_or_default().to_owned()
}
