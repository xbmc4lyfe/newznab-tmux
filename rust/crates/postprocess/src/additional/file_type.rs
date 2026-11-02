//! What an extracted file without a telling name holds, from its first bytes. Stands in for the
//! libmagic descriptions PHP's `fileInfo` matched, for the kinds the processor acts on.

use std::fs::File;
use std::io::Read;
use std::path::Path;

/// Bytes read from the start of a file; libmagic finds the Matroska doctype within them.
const HEAD_BYTES: usize = 4096;
const EBML_MAGIC: &[u8] = b"\x1A\x45\xDF\xA3";
/// `0x4282` (DocType), a one-byte length of 8, then the type.
const MATROSKA_DOCTYPE: &[u8] = b"\x42\x82\x88matroska";

/// The kinds of file the processor handles after extraction.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum FileType {
    /// `^JPE?G|^PNG`. libmagic calls WebP "RIFF … Web/P image", which never matched `^WebP`.
    Image,
    /// `Matroska data|MPEG v4|MPEG sequence, v2|\WAVI\W`.
    Video,
    /// `^Parity`: a PAR2 file.
    Par2,
}

/// The kind of the file at `path`, when it is one the processor handles.
pub fn sniff_file(path: &Path) -> Option<FileType> {
    let mut head = Vec::with_capacity(HEAD_BYTES);
    File::open(path)
        .ok()?
        .take(HEAD_BYTES as u64)
        .read_to_end(&mut head)
        .ok()?;
    sniff(&head)
}

/// The kind of a file from its first bytes.
pub fn sniff(head: &[u8]) -> Option<FileType> {
    if head.starts_with(b"\xFF\xD8\xFF") || head.starts_with(b"\x89PNG\r\n\x1A\n") {
        return Some(FileType::Image);
    }
    if head.starts_with(b"PAR2") {
        return Some(FileType::Par2);
    }
    let matroska = head.starts_with(EBML_MAGIC)
        && head
            .windows(MATROSKA_DOCTYPE.len())
            .any(|window| window == MATROSKA_DOCTYPE);
    // "RIFF (little-endian) data, AVI".
    let avi = head.len() >= 12 && head.starts_with(b"RIFF") && &head[8..12] == b"AVI ";
    // "ISO Media, MPEG v4 system, version 1" and "version 2".
    let mpeg4 =
        head.len() >= 12 && &head[4..8] == b"ftyp" && matches!(&head[8..12], b"mp41" | b"mp42");
    // "MPEG sequence, v2, program multiplex": an MPEG-2 pack header.
    let mpeg2 = head.len() >= 5 && head.starts_with(b"\x00\x00\x01\xBA") && head[4] & 0xC0 == 0x40;
    (matroska || avi || mpeg4 || mpeg2).then_some(FileType::Video)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn kinds_follow_the_descriptions_php_matched() {
        assert_eq!(sniff(b"\xFF\xD8\xFF\xE0rest"), Some(FileType::Image));
        assert_eq!(sniff(b"\x89PNG\r\n\x1A\nrest"), Some(FileType::Image));
        assert_eq!(sniff(b"RIFF\0\0\0\0WEBPVP8 "), None);
        assert_eq!(sniff(b"PAR2\0PKTrest"), Some(FileType::Par2));
        let mut mkv = EBML_MAGIC.to_vec();
        mkv.extend_from_slice(b"\xA3\x42\x86\x81\x01");
        mkv.extend_from_slice(MATROSKA_DOCTYPE);
        assert_eq!(sniff(&mkv), Some(FileType::Video));
        let mut webm = EBML_MAGIC.to_vec();
        webm.extend_from_slice(b"\x42\x82\x84webm");
        assert_eq!(sniff(&webm), None);
        assert_eq!(sniff(b"RIFF\0\0\0\0AVI LIST"), Some(FileType::Video));
        assert_eq!(sniff(b"\0\0\0\x18ftypmp42\0\0"), Some(FileType::Video));
        assert_eq!(sniff(b"\0\0\0\x18ftypisom\0\0"), None);
        assert_eq!(sniff(b"\x00\x00\x01\xBA\x44\x00"), Some(FileType::Video));
        assert_eq!(sniff(b"\x00\x00\x01\xBA\x21\x00"), None);
        assert_eq!(sniff(b"\x47\x40\x00\x10"), None);
        assert_eq!(sniff(b""), None);
    }
}
