//! MP4 and MOV: tracks, languages, runtime and titles from the `moov` box.
//!
//! A file made for streaming has `moov` at the front. Otherwise it's at the end, after `mdat`, so
//! a caller reads the file's last articles and passes them to [`probe_tail`].

use crate::dovi::DoviConfig;
use crate::lang;
use crate::summary::{
    AudioSummary, DoviSummary, MediaSummary, SubtitleSummary, VideoSummary, resolution,
};

/// Bigger than any real `moov`, and small enough that a corrupt length can't ask for much.
const MAX_MOOV_BYTES: u64 = 256 * 1024 * 1024;

#[derive(Clone, Debug, PartialEq)]
pub enum Mp4Probe {
    NotMp4,
    /// `moov` starts in this prefix and needs at least this many bytes.
    NeedPrefix {
        at_least: u64,
    },
    /// `moov` comes after `mdat`, so it's in the file's tail.
    MoovInTail,
    Read(Box<MediaSummary>),
}

/// The next box: its type, its contents, and what follows it. `None` when the header is cut off.
/// `size` is the box's whole length, which can run past the bytes at hand.
struct BoxHead<'a> {
    kind: [u8; 4],
    size: u64,
    header: usize,
    bytes: &'a [u8],
}

fn head(bytes: &[u8]) -> Option<BoxHead<'_>> {
    let small = u32::from_be_bytes(bytes.get(0..4)?.try_into().ok()?);
    let kind: [u8; 4] = bytes.get(4..8)?.try_into().ok()?;
    let (size, header) = match small {
        0 => (u64::MAX, 8),
        1 => (u64::from_be_bytes(bytes.get(8..16)?.try_into().ok()?), 16),
        size => (u64::from(size), 8),
    };
    if size < header as u64 {
        return None;
    }
    Some(BoxHead {
        kind,
        size,
        header,
        bytes,
    })
}

/// The boxes directly inside `bytes`, stopping at the first that doesn't fit.
fn children(mut bytes: &[u8]) -> impl Iterator<Item = ([u8; 4], &[u8])> {
    std::iter::from_fn(move || {
        let found = head(bytes)?;
        let size = usize::try_from(found.size).ok()?;
        let body = found.bytes.get(found.header..size)?;
        let kind = found.kind;
        bytes = &bytes[size..];
        Some((kind, body))
    })
}

fn child<'a>(bytes: &'a [u8], kind: &[u8; 4]) -> Option<&'a [u8]> {
    children(bytes)
        .find(|(found, _)| found == kind)
        .map(|(_, body)| body)
}

fn be16(bytes: &[u8], at: usize) -> Option<u64> {
    Some(u64::from(u16::from_be_bytes(
        bytes.get(at..at + 2)?.try_into().ok()?,
    )))
}

fn be32(bytes: &[u8], at: usize) -> Option<u64> {
    Some(u64::from(u32::from_be_bytes(
        bytes.get(at..at + 4)?.try_into().ok()?,
    )))
}

fn be64(bytes: &[u8], at: usize) -> Option<u64> {
    Some(u64::from_be_bytes(bytes.get(at..at + 8)?.try_into().ok()?))
}

/// Timescale and duration, from the shared layout of `mvhd` and `mdhd`.
fn timing(full_box: &[u8]) -> Option<(u64, u64)> {
    if *full_box.first()? == 1 {
        Some((be32(full_box, 20)?, be64(full_box, 24)?))
    } else {
        Some((be32(full_box, 12)?, be32(full_box, 16)?))
    }
}

fn millis(timescale: u64, duration: u64) -> Option<u64> {
    // All ones means unknown, as a fragmented file has it.
    if timescale == 0 || duration == 0 || duration == u64::MAX || duration == u64::from(u32::MAX) {
        return None;
    }
    u64::try_from(u128::from(duration) * 1000 / u128::from(timescale)).ok()
}

/// `mdhd`'s language: three letters packed in 15 bits, or `und`.
fn language(mdhd: &[u8]) -> String {
    let at = if mdhd.first() == Some(&1) { 32 } else { 20 };
    let Some(packed) = be16(mdhd, at) else {
        return "und".to_owned();
    };
    let letter = |shift: u32| (((packed >> shift) & 0x1f) as u8).wrapping_add(0x60) as char;
    let tag: String = [letter(10), letter(5), letter(0)].iter().collect();
    if tag.chars().all(|c| c.is_ascii_lowercase()) {
        lang::code(&tag)
    } else {
        "und".to_owned()
    }
}

/// `hdlr`'s handler type and a track name, when it's more than the muxer's stock text.
fn handler(hdlr: &[u8]) -> Option<([u8; 4], Option<String>)> {
    let kind: [u8; 4] = hdlr.get(8..12)?.try_into().ok()?;
    let raw = hdlr.get(24..).unwrap_or_default();
    let end = raw.iter().position(|byte| *byte == 0).unwrap_or(raw.len());
    let name = String::from_utf8_lossy(&raw[..end]).trim().to_owned();
    let stock = name.is_empty() || name.ends_with("Handler") || name.contains("Media Handler");
    Some((kind, (!stock).then_some(name)))
}

fn video_codec(kind: &[u8; 4]) -> Option<&'static str> {
    Some(match kind {
        b"avc1" | b"avc3" | b"dva1" | b"dvav" => "AVC",
        b"hvc1" | b"hev1" | b"dvh1" | b"dvhe" => "HEVC",
        b"av01" | b"dav1" => "AV1",
        b"vp09" => "VP9",
        b"mp4v" => "MPEG-4",
        _ => return None,
    })
}

fn audio_codec(kind: &[u8; 4]) -> Option<&'static str> {
    Some(match kind {
        b"mp4a" => "AAC",
        b"ac-3" => "AC-3",
        b"ec-3" => "E-AC-3",
        b"dtsc" | b"dtse" => "DTS",
        b"dtsh" | b"dtsl" => "DTS-HD MA",
        b"dtsx" => "DTS:X",
        b"mlpa" => "TrueHD",
        b"fLaC" => "FLAC",
        b"Opus" => "Opus",
        b".mp3" => "MP3",
        _ => return None,
    })
}

fn subtitle_codec(kind: &[u8; 4]) -> &'static str {
    match kind {
        b"tx3g" | b"text" => "TEXT",
        b"wvtt" => "WEBVTT",
        b"stpp" => "TTML",
        _ => "Unknown",
    }
}

/// AC-3 and E-AC-3 state their real channels in `dac3` and `dec3`. The sample entry's count is
/// a placeholder of 2 for both.
fn ac3_channels(acmod: u8, lfe: bool) -> String {
    const LAYOUTS: [&str; 8] = ["2.0", "1.0", "2.0", "3.0", "3.0", "4.0", "4.0", "5.0"];
    let layout = LAYOUTS[usize::from(acmod & 7)];
    if lfe {
        format!("{}.1", layout.trim_end_matches(".0"))
    } else {
        layout.to_owned()
    }
}

fn channels(codec_boxes: &[u8], entry_count: Option<u64>) -> Option<String> {
    if let Some(dac3) = child(codec_boxes, b"dac3") {
        let word = (u32::from(*dac3.first()?) << 16)
            | (u32::from(*dac3.get(1)?) << 8)
            | u32::from(*dac3.get(2)?);
        return Some(ac3_channels(
            ((word >> 11) & 7) as u8,
            (word >> 10) & 1 == 1,
        ));
    }
    if let Some(dec3) = child(codec_boxes, b"dec3") {
        let acmod = (dec3.get(4)? >> 1) & 7;
        let lfe = dec3.get(4)? & 1 == 1;
        return Some(ac3_channels(acmod, lfe));
    }
    match entry_count? {
        0 => None,
        1 => Some("1.0".to_owned()),
        2 => Some("2.0".to_owned()),
        6 => Some("5.1".to_owned()),
        8 => Some("7.1".to_owned()),
        count => Some(format!("{count}.0")),
    }
}

/// The `moov` text an iTunes-style tag holds, as `©nam` or `©too`.
fn tag(moov: &[u8], kind: &[u8; 4]) -> Option<String> {
    let udta = child(moov, b"udta")?;
    // `meta` is a full box in MP4 and a plain one in MOV.
    let meta = child(udta, b"meta")?;
    let ilst = child(meta.get(4..)?, b"ilst").or_else(|| child(meta, b"ilst"))?;
    let item = child(ilst, kind)?;
    let data = child(item, b"data")?;
    let text = String::from_utf8_lossy(data.get(8..)?).trim().to_owned();
    (!text.is_empty()).then_some(text)
}

/// Reads a whole `moov` box's contents.
pub fn parse_moov(moov: &[u8]) -> Option<MediaSummary> {
    let mvhd = child(moov, b"mvhd")?;
    let (timescale, duration) = timing(mvhd)?;
    let mut summary = MediaSummary {
        container: "mp4".to_owned(),
        duration_ms: millis(timescale, duration),
        title: tag(moov, b"\xa9nam"),
        writing_app: tag(moov, b"\xa9too"),
        ..MediaSummary::default()
    };
    for (kind, trak) in children(moov) {
        if &kind != b"trak" {
            continue;
        }
        let Some(track) = child(trak, b"tkhd").and_then(|tkhd| {
            if tkhd.first() == Some(&1) {
                be32(tkhd, 20)
            } else {
                be32(tkhd, 12)
            }
        }) else {
            continue;
        };
        let Some(mdia) = child(trak, b"mdia") else {
            continue;
        };
        let Some((handler_kind, name)) = child(mdia, b"hdlr").and_then(handler) else {
            continue;
        };
        let language = child(mdia, b"mdhd").map_or_else(|| "und".to_owned(), language);
        let track_ms = child(mdia, b"mdhd")
            .and_then(timing)
            .and_then(|(scale, length)| millis(scale, length));
        if summary.duration_ms.is_none() {
            summary.duration_ms = track_ms;
        }
        let Some(stsd) = child(mdia, b"minf")
            .and_then(|minf| child(minf, b"stbl"))
            .and_then(|stbl| child(stbl, b"stsd"))
        else {
            continue;
        };
        // A full box and an entry count, then the first sample entry.
        let Some((entry_kind, entry)) = stsd.get(8..).and_then(|rest| children(rest).next()) else {
            continue;
        };
        match &handler_kind {
            b"vide" => {
                let Some(codec) = video_codec(&entry_kind) else {
                    continue;
                };
                if summary.video.is_some() {
                    continue;
                }
                let (width, height) = (be16(entry, 24), be16(entry, 26));
                let boxes = entry.get(78..).unwrap_or_default();
                let dovi = child(boxes, b"dvcC")
                    .or_else(|| child(boxes, b"dvvC"))
                    .and_then(DoviConfig::parse);
                summary.video = Some(VideoSummary {
                    track,
                    codec: codec.to_owned(),
                    width,
                    height,
                    resolution: resolution(width, height),
                    hdr: if dovi.is_some() {
                        vec!["DV"]
                    } else {
                        Vec::new()
                    },
                    dovi: dovi.map(|config| DoviSummary {
                        profile: Some(config.profile_name()),
                        el_present: config.el_present,
                        ..DoviSummary::default()
                    }),
                    ..VideoSummary::default()
                });
            }
            b"soun" => {
                let boxes = entry.get(28..).unwrap_or_default();
                summary.audio.push(AudioSummary {
                    track,
                    codec: audio_codec(&entry_kind).map_or_else(
                        || String::from_utf8_lossy(&entry_kind).into_owned(),
                        str::to_owned,
                    ),
                    channels: channels(boxes, be16(entry, 16)),
                    language,
                    name,
                    atmos: false,
                    dtsx: &entry_kind == b"dtsx",
                    commentary: false,
                    default: summary.audio.is_empty(),
                });
            }
            b"sbtl" | b"subt" | b"text" | b"clcp" => {
                let lower = name.as_deref().unwrap_or_default().to_lowercase();
                summary.subtitles.push(SubtitleSummary {
                    track,
                    codec: subtitle_codec(&entry_kind).to_owned(),
                    language,
                    name,
                    forced: lower.contains("forced"),
                    sdh: lower.contains("sdh"),
                    commentary: lower.contains("comment"),
                });
            }
            _ => {}
        }
    }
    Some(summary)
}

/// Reads a file's first bytes.
pub fn probe_prefix(buf: &[u8]) -> Mp4Probe {
    if buf.get(4..8) != Some(b"ftyp") {
        return Mp4Probe::NotMp4;
    }
    let mut offset = 0u64;
    loop {
        let Some(found) = usize::try_from(offset)
            .ok()
            .and_then(|at| buf.get(at..))
            .and_then(head)
        else {
            return Mp4Probe::NeedPrefix {
                at_least: offset.saturating_add(16),
            };
        };
        let end = offset.saturating_add(found.size);
        match &found.kind {
            b"moov" => {
                if found.size > MAX_MOOV_BYTES {
                    return Mp4Probe::NotMp4;
                }
                let Some(body) = usize::try_from(end)
                    .ok()
                    .and_then(|end| buf.get(offset as usize + found.header..end))
                else {
                    return Mp4Probe::NeedPrefix { at_least: end };
                };
                return parse_moov(body).map_or(Mp4Probe::NotMp4, |summary| {
                    Mp4Probe::Read(Box::new(summary))
                });
            }
            // Past the media with no `moov` so far. A fragmented file's would have come first.
            b"mdat" => return Mp4Probe::MoovInTail,
            _ => {
                if found.size == u64::MAX {
                    return Mp4Probe::NotMp4;
                }
                offset = end;
            }
        }
    }
}

/// Finds and reads `moov` in a file's last bytes, which can start anywhere. `None` when it isn't
/// there whole, and the caller needs more of the tail.
pub fn probe_tail(buf: &[u8]) -> Option<MediaSummary> {
    let mut from = 0;
    while let Some(found) = buf[from..].windows(4).position(|window| window == b"moov") {
        let at = from + found;
        from = at + 4;
        let Some(start) = at.checked_sub(4) else {
            continue;
        };
        let Some(boxed) = head(&buf[start..]) else {
            continue;
        };
        let Some(end) = usize::try_from(boxed.size)
            .ok()
            .and_then(|size| start.checked_add(size))
        else {
            continue;
        };
        if let Some(summary) = buf
            .get(start + boxed.header..end)
            .and_then(parse_moov)
            .filter(|summary| summary.video.is_some() || !summary.audio.is_empty())
        {
            return Some(summary);
        }
    }
    None
}

#[cfg(test)]
mod tests {
    use super::*;

    fn boxed(kind: &[u8; 4], body: &[u8]) -> Vec<u8> {
        let mut out = ((body.len() + 8) as u32).to_be_bytes().to_vec();
        out.extend_from_slice(kind);
        out.extend_from_slice(body);
        out
    }

    #[test]
    fn a_cut_off_or_lying_box_reads_as_nothing_rather_than_panicking() {
        assert_eq!(probe_prefix(b"not an mp4 at all"), Mp4Probe::NotMp4);
        assert!(probe_tail(b"xxxxmoov").is_none());
        let huge = [&[0xff, 0xff, 0xff, 0xff][..], b"moov"].concat();
        assert!(probe_tail(&huge).is_none());
        assert!(parse_moov(&boxed(b"mvhd", &[0; 3])).is_none());
        // A box too small to hold its own header.
        let tiny = [&[0, 0, 0, 2][..], b"moov"].concat();
        assert!(probe_tail(&tiny).is_none());
    }

    #[test]
    fn the_order_of_boxes_decides_where_to_look() {
        let ftyp = boxed(b"ftyp", b"isom");
        let mut late = ftyp.clone();
        late.extend(boxed(b"mdat", &[0; 32]));
        assert_eq!(probe_prefix(&late), Mp4Probe::MoovInTail);
        assert_eq!(
            probe_prefix(&ftyp),
            Mp4Probe::NeedPrefix {
                at_least: ftyp.len() as u64 + 16
            }
        );
    }

    #[test]
    fn languages_unpack() {
        // `eng` packed, at a version 0 `mdhd`'s language offset.
        let mut mdhd = vec![0; 22];
        let packed: u16 = (5 << 10) | (14 << 5) | 7;
        mdhd[20..22].copy_from_slice(&packed.to_be_bytes());
        assert_eq!(language(&mdhd), lang::code("eng"));
        assert_eq!(language(&[0; 3]), "und");
    }

    #[test]
    fn ac3_layouts_read() {
        assert_eq!(ac3_channels(7, true), "5.1");
        assert_eq!(ac3_channels(2, false), "2.0");
    }
}
