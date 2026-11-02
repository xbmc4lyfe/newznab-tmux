//! Matroska header and block reading from a prefix (or a resynced middle) of a file.

use crate::dovi::DoviConfig;
use crate::ebml::{self, Element, children};
use serde::Serialize;

pub const EBML_HEADER: u32 = 0x1A45_DFA3;
pub const SEGMENT: u32 = 0x1853_8067;
pub const CLUSTER: u32 = 0x1F43_B675;
const INFO: u32 = 0x1549_A966;
const TRACKS: u32 = 0x1654_AE6B;
const CHAPTERS: u32 = 0x1043_A770;

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "lowercase")]
pub enum TrackKind {
    Video,
    Audio,
    Subtitle,
    Other,
}

#[derive(Clone, Debug, Default, PartialEq, Serialize)]
pub struct VideoInfo {
    pub width: Option<u64>,
    pub height: Option<u64>,
    pub bits_per_channel: Option<u64>,
    pub matrix: Option<u64>,
    pub transfer: Option<u64>,
    pub primaries: Option<u64>,
    pub max_cll: Option<u64>,
    pub max_fall: Option<u64>,
    pub mastering_max_luminance: Option<f64>,
    pub mastering_min_luminance: Option<f64>,
}

#[derive(Clone, Debug, Default, PartialEq, Serialize)]
pub struct AudioInfo {
    pub channels: Option<u64>,
    pub sample_rate: Option<f64>,
    pub bit_depth: Option<u64>,
}

#[derive(Clone, Debug, PartialEq, Serialize)]
pub struct Track {
    pub number: u64,
    pub uid: Option<u64>,
    pub kind: TrackKind,
    pub codec_id: String,
    #[serde(skip)]
    pub codec_private: Vec<u8>,
    pub name: Option<String>,
    /// BCP 47 when the file has it, otherwise the ISO 639-2 code (`eng` when absent, per spec).
    pub language: String,
    pub default: bool,
    pub forced: bool,
    pub hearing_impaired: bool,
    pub visual_impaired: bool,
    pub original: bool,
    pub commentary: bool,
    pub default_duration_ns: Option<u64>,
    pub video: Option<VideoInfo>,
    pub audio: Option<AudioInfo>,
    pub dovi: Option<DoviConfig>,
}

#[derive(Clone, Debug, PartialEq, Serialize)]
pub struct Chapter {
    pub start_ns: u64,
    pub end_ns: Option<u64>,
    pub name: Option<String>,
    pub language: Option<String>,
    pub hidden: bool,
}

#[derive(Clone, Debug, Default, PartialEq, Serialize)]
pub struct Edition {
    pub uid: Option<u64>,
    pub default: bool,
    pub hidden: bool,
    pub ordered: bool,
    pub name: Option<String>,
    pub chapters: Vec<Chapter>,
}

#[derive(Clone, Debug, Default, PartialEq, Serialize)]
pub struct Header {
    pub doc_type: String,
    pub timestamp_scale: u64,
    pub duration_ms: Option<u64>,
    pub title: Option<String>,
    pub muxing_app: Option<String>,
    pub writing_app: Option<String>,
    /// SegmentUUID as lowercase hex: identifies one specific mux.
    pub segment_uid: Option<String>,
    pub tracks: Vec<Track>,
    pub editions: Vec<Edition>,
    /// Buffer offset of the first Cluster, when the prefix reaches it.
    #[serde(skip)]
    pub first_cluster: Option<usize>,
}

/// Why a header could not be read yet.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum HeaderError {
    /// Not Matroska or WebM.
    NotMatroska,
    /// The Tracks element (or something before it) runs past the buffer. The value is the total
    /// prefix length that should be enough, when the sizes say so.
    NeedMore(Option<usize>),
}

/// Reads the header from the first bytes of a Matroska file.
///
/// Succeeds once Info and Tracks have been read whole. Chapters are read when they come before the
/// first Cluster, which is where mkvmerge and MakeMKV put them.
pub fn parse_header(buf: &[u8]) -> Result<Header, HeaderError> {
    let mut top = children(buf, 0);
    let ebml = top.next().ok_or(HeaderError::NeedMore(None))?;
    if ebml.id != EBML_HEADER {
        return Err(HeaderError::NotMatroska);
    }
    if ebml.truncated {
        return Err(HeaderError::NeedMore(None));
    }
    let doc_type = children(ebml.body, ebml.body_offset)
        .find(|e| e.id == 0x4282)
        .map(|e| ebml::string(e.body))
        .unwrap_or_else(|| "matroska".into());
    if doc_type != "matroska" && doc_type != "webm" {
        return Err(HeaderError::NotMatroska);
    }
    let segment = top
        .find(|e| e.id == SEGMENT)
        .ok_or(HeaderError::NeedMore(None))?;

    let mut header = Header {
        doc_type,
        timestamp_scale: 1_000_000,
        ..Header::default()
    };
    let mut duration: Option<f64> = None;
    let mut have_tracks = false;
    for element in children(segment.body, segment.body_offset) {
        match element.id {
            INFO => {
                if element.truncated {
                    return Err(need(&element));
                }
                for field in children(element.body, element.body_offset) {
                    match field.id {
                        0x2A_D7B1 => {
                            header.timestamp_scale = ebml::uint(field.body)
                                .filter(|&s| s > 0)
                                .unwrap_or(1_000_000)
                        }
                        0x4489 => duration = ebml::float(field.body),
                        0x7BA9 => header.title = non_empty(ebml::string(field.body)),
                        0x4D80 => header.muxing_app = non_empty(ebml::string(field.body)),
                        0x5741 => header.writing_app = non_empty(ebml::string(field.body)),
                        0x73A4 if field.body.len() == 16 => {
                            header.segment_uid = Some(hex::encode(field.body))
                        }
                        _ => {}
                    }
                }
            }
            TRACKS => {
                if element.truncated {
                    return Err(need(&element));
                }
                header.tracks = children(element.body, element.body_offset)
                    .filter(|e| e.id == 0xAE)
                    .filter_map(|e| parse_track(e.body))
                    .collect();
                have_tracks = true;
            }
            CHAPTERS if !element.truncated => {
                header.editions = children(element.body, element.body_offset)
                    .filter(|e| e.id == 0x45B9)
                    .map(|e| parse_edition(e.body))
                    .collect();
            }
            CLUSTER => {
                header.first_cluster = Some(element.offset);
                break;
            }
            _ if element.truncated => break,
            _ => {}
        }
    }
    if !have_tracks {
        return Err(HeaderError::NeedMore(None));
    }
    header.duration_ms = duration
        .filter(|d| d.is_finite() && *d > 0.0)
        .map(|d| (d * header.timestamp_scale as f64 / 1_000_000.0).round() as u64);
    Ok(header)
}

fn need(element: &Element<'_>) -> HeaderError {
    HeaderError::NeedMore(element.size.map(|size| element.body_offset + size as usize))
}

fn non_empty(s: String) -> Option<String> {
    (!s.is_empty()).then_some(s)
}

fn flag(body: &[u8]) -> bool {
    ebml::uint(body).unwrap_or(0) != 0
}

fn parse_track(body: &[u8]) -> Option<Track> {
    let mut number = None;
    let mut track = Track {
        number: 0,
        uid: None,
        kind: TrackKind::Other,
        codec_id: String::new(),
        codec_private: Vec::new(),
        name: None,
        language: "eng".into(),
        default: true,
        forced: false,
        hearing_impaired: false,
        visual_impaired: false,
        original: false,
        commentary: false,
        default_duration_ns: None,
        video: None,
        audio: None,
        dovi: None,
    };
    let mut bcp47 = None;
    for field in children(body, 0) {
        match field.id {
            0xD7 => number = ebml::uint(field.body),
            0x73C5 => track.uid = ebml::uint(field.body),
            0x83 => {
                track.kind = match ebml::uint(field.body) {
                    Some(1) => TrackKind::Video,
                    Some(2) => TrackKind::Audio,
                    Some(17) => TrackKind::Subtitle,
                    _ => TrackKind::Other,
                }
            }
            0x86 => track.codec_id = ebml::string(field.body),
            0x63A2 => track.codec_private = field.body.to_vec(),
            0x536E => track.name = non_empty(ebml::string(field.body)),
            0x22_B59C => track.language = ebml::string(field.body),
            0x22_B59D => bcp47 = non_empty(ebml::string(field.body)),
            0x88 => track.default = flag(field.body),
            0x55AA => track.forced = flag(field.body),
            0x55AB => track.hearing_impaired = flag(field.body),
            0x55AC => track.visual_impaired = flag(field.body),
            0x55AE => track.original = flag(field.body),
            0x55AF => track.commentary = flag(field.body),
            0x23_E383 => track.default_duration_ns = ebml::uint(field.body),
            0xE0 => track.video = Some(parse_video(field.body)),
            0xE1 => track.audio = Some(parse_audio(field.body)),
            0x41E4 => {
                if let Some(config) = parse_block_addition_mapping(field.body) {
                    track.dovi = Some(config);
                }
            }
            _ => {}
        }
    }
    track.number = number?;
    if let Some(tag) = bcp47 {
        track.language = tag;
    }
    if track.language.is_empty() {
        track.language = "und".into();
    }
    Some(track)
}

fn parse_video(body: &[u8]) -> VideoInfo {
    let mut video = VideoInfo::default();
    for field in children(body, 0) {
        match field.id {
            0xB0 => video.width = ebml::uint(field.body),
            0xBA => video.height = ebml::uint(field.body),
            0x55B0 => {
                for colour in children(field.body, 0) {
                    match colour.id {
                        0x55B1 => video.matrix = ebml::uint(colour.body),
                        0x55B2 => video.bits_per_channel = ebml::uint(colour.body),
                        0x55BA => video.transfer = ebml::uint(colour.body),
                        0x55BB => video.primaries = ebml::uint(colour.body),
                        0x55BC => video.max_cll = ebml::uint(colour.body),
                        0x55BD => video.max_fall = ebml::uint(colour.body),
                        0x55D0 => {
                            for mastering in children(colour.body, 0) {
                                match mastering.id {
                                    0x55D9 => {
                                        video.mastering_max_luminance = ebml::float(mastering.body)
                                    }
                                    0x55DA => {
                                        video.mastering_min_luminance = ebml::float(mastering.body)
                                    }
                                    _ => {}
                                }
                            }
                        }
                        _ => {}
                    }
                }
            }
            _ => {}
        }
    }
    video
}

fn parse_audio(body: &[u8]) -> AudioInfo {
    let mut audio = AudioInfo::default();
    for field in children(body, 0) {
        match field.id {
            0x9F => audio.channels = ebml::uint(field.body),
            0xB5 => audio.sample_rate = ebml::float(field.body),
            0x6264 => audio.bit_depth = ebml::uint(field.body),
            _ => {}
        }
    }
    audio
}

/// The Dolby Vision configuration record, which mkvmerge 51+ writes as a BlockAdditionMapping
/// with type `dvcC`, `dvvC` or `dvwC`.
fn parse_block_addition_mapping(body: &[u8]) -> Option<DoviConfig> {
    let mut kind = None;
    let mut extra = None;
    for field in children(body, 0) {
        match field.id {
            0x41E7 => kind = ebml::uint(field.body),
            0x41ED => extra = Some(field.body),
            _ => {}
        }
    }
    const DVCC: u64 = 0x6476_6343;
    const DVVC: u64 = 0x6476_7643;
    const DVWC: u64 = 0x6476_7743;
    match kind? {
        DVCC | DVVC | DVWC => DoviConfig::parse(extra?),
        _ => None,
    }
}

fn parse_edition(body: &[u8]) -> Edition {
    let mut edition = Edition::default();
    for field in children(body, 0) {
        match field.id {
            0x45BC => edition.uid = ebml::uint(field.body),
            0x45BD => edition.hidden = flag(field.body),
            0x45DB => edition.default = flag(field.body),
            0x45DD => edition.ordered = flag(field.body),
            0x4520 => {
                edition.name = children(field.body, 0)
                    .find(|e| e.id == 0x4521)
                    .and_then(|e| non_empty(ebml::string(e.body)))
            }
            0xB6 => {
                if let Some(chapter) = parse_chapter(field.body) {
                    edition.chapters.push(chapter);
                }
            }
            _ => {}
        }
    }
    edition
}

fn parse_chapter(body: &[u8]) -> Option<Chapter> {
    let mut start = None;
    let mut chapter = Chapter {
        start_ns: 0,
        end_ns: None,
        name: None,
        language: None,
        hidden: false,
    };
    for field in children(body, 0) {
        match field.id {
            0x91 => start = ebml::uint(field.body),
            0x92 => chapter.end_ns = ebml::uint(field.body),
            0x98 => chapter.hidden = flag(field.body),
            0x80 if chapter.name.is_none() => {
                for display in children(field.body, 0) {
                    match display.id {
                        0x85 => chapter.name = non_empty(ebml::string(display.body)),
                        0x437C | 0x437D => chapter.language = non_empty(ebml::string(display.body)),
                        _ => {}
                    }
                }
            }
            _ => {}
        }
    }
    chapter.start_ns = start?;
    Some(chapter)
}

/// One block's frames, after lacing.
#[derive(Clone, Debug)]
pub struct Block<'a> {
    pub track: u64,
    /// Absolute timestamp in the segment's timestamp units (cluster timestamp plus block offset).
    pub timestamp: i64,
    pub keyframe: bool,
    pub frames: Vec<&'a [u8]>,
    /// BlockAdditions (BlockAddID, data), used by some muxers for Dolby Vision RPUs.
    pub additions: Vec<(u64, &'a [u8])>,
}

/// Every complete block in the clusters found in `buf`, in file order.
///
/// `buf` must start at a Cluster ID (see [`resync`] for a buffer taken from the middle of a file,
/// and [`Header::first_cluster`] for a prefix). Truncated blocks are skipped.
pub fn blocks(buf: &[u8]) -> Vec<Block<'_>> {
    let mut out = Vec::new();
    walk_clusters(buf, 0, &mut out);
    out
}

fn walk_clusters<'a>(data: &'a [u8], base: usize, out: &mut Vec<Block<'a>>) {
    for element in children(data, base) {
        if element.id == CLUSTER {
            read_cluster(element.body, element.body_offset, out);
        }
    }
}

fn read_cluster<'a>(data: &'a [u8], base: usize, out: &mut Vec<Block<'a>>) {
    let mut cluster_time: i64 = 0;
    for element in children(data, base) {
        match element.id {
            0xE7 => cluster_time = ebml::uint(element.body).unwrap_or(0) as i64,
            0xA3 if !element.truncated => {
                if let Some(block) = parse_block(element.body, cluster_time, None) {
                    out.push(block);
                }
            }
            0xA0 if !element.truncated => {
                let mut block_body = None;
                let mut additions = Vec::new();
                let mut has_reference = false;
                for field in children(element.body, 0) {
                    match field.id {
                        0xA1 => block_body = Some(field.body),
                        0xFB => has_reference = true,
                        0x75A1 => {
                            for more in children(field.body, 0).filter(|e| e.id == 0xA6) {
                                let mut id = 1;
                                let mut data = None;
                                for part in children(more.body, 0) {
                                    match part.id {
                                        0xEE => id = ebml::uint(part.body).unwrap_or(1),
                                        0xA5 => data = Some(part.body),
                                        _ => {}
                                    }
                                }
                                if let Some(data) = data {
                                    additions.push((id, data));
                                }
                            }
                        }
                        _ => {}
                    }
                }
                if let Some(body) = block_body
                    && let Some(mut block) = parse_block(body, cluster_time, Some(!has_reference))
                {
                    block.additions = additions;
                    out.push(block);
                }
            }
            // An unknown-sized cluster swallows the next one as a "child".
            CLUSTER => read_cluster(element.body, element.body_offset, out),
            _ => {}
        }
    }
}

fn parse_block(body: &[u8], cluster_time: i64, keyframe: Option<bool>) -> Option<Block<'_>> {
    let (track, len) = ebml::read_size(body)?;
    let track = track?;
    let rest = body.get(len..)?;
    if rest.len() < 3 {
        return None;
    }
    let offset = i16::from_be_bytes([rest[0], rest[1]]);
    let flags = rest[2];
    let payload = &rest[3..];
    let frames = delace(payload, (flags >> 1) & 3)?;
    Some(Block {
        track,
        timestamp: cluster_time + i64::from(offset),
        keyframe: keyframe.unwrap_or(flags & 0x80 != 0),
        frames,
        additions: Vec::new(),
    })
}

fn delace(payload: &[u8], lacing: u8) -> Option<Vec<&[u8]>> {
    if lacing == 0 {
        return Some(vec![payload]);
    }
    let count = usize::from(*payload.first()?) + 1;
    let mut pos = 1;
    let mut sizes = Vec::with_capacity(count);
    match lacing {
        // Xiph
        1 => {
            for _ in 0..count - 1 {
                let mut size = 0usize;
                loop {
                    let byte = *payload.get(pos)?;
                    pos += 1;
                    size += usize::from(byte);
                    if byte != 255 {
                        break;
                    }
                }
                sizes.push(size);
            }
        }
        // Fixed
        2 => {
            let total = payload.len() - 1;
            if !total.is_multiple_of(count) {
                return None;
            }
            sizes = vec![total / count; count - 1];
        }
        // EBML
        _ => {
            let (first, len) = ebml::read_size(&payload[pos..])?;
            pos += len;
            let mut size = first? as i64;
            sizes.push(size as usize);
            for _ in 1..count - 1 {
                let (delta, len) = ebml::read_signed(&payload[pos..])?;
                pos += len;
                size += delta;
                if size < 0 {
                    return None;
                }
                sizes.push(size as usize);
            }
        }
    }
    let used: usize = sizes.iter().sum();
    let last = payload.len().checked_sub(pos + used)?;
    sizes.push(last);
    let mut frames = Vec::with_capacity(count);
    for size in sizes {
        frames.push(payload.get(pos..pos + size)?);
        pos += size;
    }
    Some(frames)
}

/// Finds the first plausible Cluster start in a buffer cut from the middle of a file.
///
/// A Cluster ID is only accepted when it is followed by a valid size and a Timestamp child, which
/// rules out the ID bytes turning up inside compressed data.
pub fn resync(buf: &[u8]) -> Option<usize> {
    let id = CLUSTER.to_be_bytes();
    let mut start = 0;
    while let Some(found) = buf[start..].windows(4).position(|w| w == id) {
        let at = start + found;
        let rest = &buf[at + 4..];
        if let Some((_, len)) = ebml::read_size(rest)
            && let Some((child, _)) = ebml::read_id(&rest[len..])
            && child == 0xE7
        {
            return Some(at);
        }
        start = at + 1;
    }
    None
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn delaces_xiph_and_ebml() {
        // Xiph: 3 frames of 2, 3 and the rest (1).
        let payload = [2, 2, 3, b'a', b'a', b'b', b'b', b'b', b'c'];
        let frames = delace(&payload, 1).unwrap();
        assert_eq!(frames, vec![&b"aa"[..], &b"bbb"[..], &b"c"[..]]);
        // EBML: first size 2 (0x82), delta +1 (0xC0), last gets the rest.
        let payload = [2, 0x82, 0xC0, b'a', b'a', b'b', b'b', b'b', b'c'];
        let frames = delace(&payload, 3).unwrap();
        assert_eq!(frames, vec![&b"aa"[..], &b"bbb"[..], &b"c"[..]]);
        // Fixed: 2 frames of 2.
        let payload = [1, 1, 2, 3, 4];
        assert_eq!(delace(&payload, 2).unwrap(), vec![&[1, 2][..], &[3, 4][..]]);
    }

    #[test]
    fn resync_needs_a_timestamp_child() {
        let mut buf = vec![0u8; 10];
        buf.extend_from_slice(&[0x1F, 0x43, 0xB6, 0x75, 0x85, 0x00]); // no timestamp
        buf.extend_from_slice(&[0x1F, 0x43, 0xB6, 0x75, 0x85, 0xE7, 0x81, 0x00, 0, 0]);
        assert_eq!(resync(&buf), Some(16));
    }
}
