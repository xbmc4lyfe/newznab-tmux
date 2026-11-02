//! Media metadata from a few articles of a release: the Matroska header (tracks, languages,
//! chapters, runtime, SegmentUID), Dolby Vision profile and MEL or FEL from the first RPU, HDR10+,
//! Atmos and DTS:X, and the identification and poster signatures that go with them.
//!
//! Everything here is pure and works on bytes the caller fetched. [`probe_prefix`] says whether it
//! wants more of the file, so a caller can fetch one article at a time up to its own budget.

pub mod audio;
pub mod dovi;
pub mod ebml;
pub mod ident;
pub mod lang;
pub mod matroska;
pub mod posting;
pub mod summary;
pub mod video;

use audio::AudioFindings;
use matroska::{HeaderError, TrackKind};
use summary::MediaSummary;
use video::{Codec, VideoFindings};

/// How many frames to look at before calling the stream read.
#[derive(Clone, Copy, Debug)]
pub struct Limits {
    /// Video access units (the first keyframe carries the RPU and HDR10+ SEI).
    pub video_access_units: usize,
    /// Frames per audio track.
    pub audio_frames: usize,
}

impl Default for Limits {
    fn default() -> Self {
        Self {
            video_access_units: 2,
            audio_frames: 12,
        }
    }
}

#[derive(Clone, Debug, PartialEq)]
pub enum Probe {
    /// Not a Matroska file: nothing more to learn by reading on.
    NotMatroska,
    /// The header isn't whole yet. `at_least` is the prefix length the element sizes ask for.
    NeedHeader { at_least: Option<usize> },
    /// The header is read. `wants_more` is true while the first video or audio frames that the
    /// HDR, Dolby Vision and object-audio checks need haven't been seen.
    Read {
        summary: Box<MediaSummary>,
        wants_more: bool,
    },
}

/// Probes the first bytes of a Matroska file.
pub fn probe_prefix(buf: &[u8], limits: Limits) -> Probe {
    let header = match matroska::parse_header(buf) {
        Ok(header) => header,
        Err(HeaderError::NotMatroska) => return Probe::NotMatroska,
        Err(HeaderError::NeedMore(at_least)) => return Probe::NeedHeader { at_least },
    };
    let video_track = header
        .tracks
        .iter()
        .find(|t| t.kind == TrackKind::Video && t.default)
        .or_else(|| header.tracks.iter().find(|t| t.kind == TrackKind::Video));
    let video_codec = video_track.and_then(|t| Codec::from_codec_id(&t.codec_id));
    let length_size = match (video_track, video_codec) {
        (Some(track), Some(codec)) => video::nal_length_size(codec, &track.codec_private),
        _ => 4,
    };
    let mut video = VideoFindings::default();
    let audio_tracks: Vec<_> = header
        .tracks
        .iter()
        .filter(|t| t.kind == TrackKind::Audio)
        .collect();
    let mut audio: Vec<AudioFindings> = vec![AudioFindings::default(); audio_tracks.len()];

    if let Some(start) = header.first_cluster {
        for block in matroska::blocks(&buf[start..]) {
            if let (Some(track), Some(codec)) = (video_track, video_codec)
                && block.track == track.number
                && video.access_units < limits.video_access_units
            {
                for frame in &block.frames {
                    video.add_access_unit(codec, length_size, frame);
                }
                for (_, data) in &block.additions {
                    video.add_block_addition(data);
                }
            }
            if let Some(index) = audio_tracks.iter().position(|t| t.number == block.track)
                && audio[index].frames < limits.audio_frames
            {
                for frame in &block.frames {
                    audio[index].add_frame(&audio_tracks[index].codec_id, frame);
                }
            }
        }
    }

    let video_wanted = video_codec.is_some() && video.access_units == 0;
    let audio_wanted = audio_tracks.iter().zip(&audio).any(|(track, found)| {
        found.frames == 0
            && (track.codec_id == "A_TRUEHD"
                || track.codec_id == "A_EAC3"
                || track.codec_id.starts_with("A_DTS"))
    });
    let pairs: Vec<_> = audio_tracks.iter().copied().zip(audio).collect();
    let summary = MediaSummary::build(&header, video_track.map(|t| (t, &video)), &pairs);
    Probe::Read {
        summary: Box::new(summary),
        wants_more: video_wanted || audio_wanted,
    }
}
