//! Makes a chunk from the middle of a video decodable by giving it the file's headers. Port of
//! `ContainerSplicer`.
//!
//! Openings are often black or show logos; a frame from the middle shows the content. Only the
//! containers whose stream can be picked up again at a known marker are supported.

use super::container::{VideoContainer, transport_stream_offset};

const MATROSKA_CLUSTER: &[u8] = b"\x1F\x43\xB6\x75";
const MPEG_PACK_START: &[u8] = b"\x00\x00\x01\xBA";

/// `splice`: `head` is the start of the file, `mid` bytes from later in the same file.
pub fn splice(head: &[u8], mid: &[u8], container: VideoContainer) -> Option<Vec<u8>> {
    match container {
        VideoContainer::Matroska => join_at(head, MATROSKA_CLUSTER, mid, MATROSKA_CLUSTER),
        VideoContainer::MpegTransport => {
            transport_stream_offset(mid).map(|offset| mid[offset..].to_vec())
        }
        VideoContainer::MpegProgram => {
            find(mid, MPEG_PACK_START).map(|offset| mid[offset..].to_vec())
        }
        VideoContainer::Avi => splice_avi(head, mid),
        _ => None,
    }
}

/// Keeps `head` up to its first `head_marker`, then continues at `mid`'s first `mid_marker`.
fn join_at(head: &[u8], head_marker: &[u8], mid: &[u8], mid_marker: &[u8]) -> Option<Vec<u8>> {
    let head_end = find(head, head_marker)?;
    let mid_start = find(mid, mid_marker)?;
    let mut joined = head[..head_end].to_vec();
    joined.extend_from_slice(&mid[mid_start..]);
    Some(joined)
}

/// Headers up to the start of the `movi` list, then the first stream chunk of `mid`.
fn splice_avi(head: &[u8], mid: &[u8]) -> Option<Vec<u8>> {
    let movi = find(head, b"movi")?;
    if movi < 8 || &head[movi - 8..movi - 4] != b"LIST" {
        return None;
    }
    // `/\d\d(?:dc|db|wb)/`: a stream number and a chunk type.
    let chunk = mid.windows(4).position(|window| {
        window[0].is_ascii_digit()
            && window[1].is_ascii_digit()
            && matches!(&window[2..], b"dc" | b"db" | b"wb")
    })?;
    let mut joined = head[..movi + 4].to_vec();
    joined.extend_from_slice(&mid[chunk..]);
    Some(joined)
}

/// `strpos`.
pub(crate) fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    haystack
        .windows(needle.len())
        .position(|window| window == needle)
}
