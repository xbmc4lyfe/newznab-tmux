//! The probe's result, in the shape the release tables, the newznab attrs and search tags need.

use crate::audio::AudioFindings;
use crate::dovi::ElType;
use crate::ident::{self, Commentary};
use crate::lang;
use crate::matroska::{Header, Track, TrackKind};
use crate::video::VideoFindings;
use serde::Serialize;

#[derive(Clone, Debug, Default, PartialEq, Serialize)]
pub struct VideoSummary {
    pub track: u64,
    pub codec: String,
    pub width: Option<u64>,
    pub height: Option<u64>,
    /// `2160p`, `1080p`, `720p`, `576p`, `480p`.
    pub resolution: Option<&'static str>,
    pub frame_rate: Option<String>,
    pub bit_depth: Option<u64>,
    /// Ordered `DV`, `HDR10+`, `HDR10`, `HLG`, or `SDR`.
    pub hdr: Vec<&'static str>,
    pub dovi: Option<DoviSummary>,
    pub max_cll: Option<u64>,
    pub max_fall: Option<u64>,
    pub encoder: Option<String>,
}

#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize)]
pub struct DoviSummary {
    /// `5`, `7.6`, `8.1`, `8.4`.
    pub profile: Option<String>,
    pub el: Option<ElType>,
    pub cm_version: Option<&'static str>,
    /// The enhancement layer is present (from the config record or an EL NAL).
    pub el_present: bool,
}

#[derive(Clone, Debug, PartialEq, Serialize)]
pub struct AudioSummary {
    pub track: u64,
    pub codec: String,
    pub channels: Option<String>,
    pub language: String,
    pub name: Option<String>,
    pub atmos: bool,
    pub dtsx: bool,
    pub commentary: bool,
    pub default: bool,
}

#[derive(Clone, Debug, PartialEq, Serialize)]
pub struct SubtitleSummary {
    pub track: u64,
    pub codec: String,
    pub language: String,
    pub name: Option<String>,
    pub forced: bool,
    pub sdh: bool,
    pub commentary: bool,
}

#[derive(Clone, Debug, PartialEq, Serialize)]
pub struct ChapterSummary {
    pub start_ms: u64,
    pub name: Option<String>,
    /// The name says more than "Chapter 01".
    pub named: bool,
}

#[derive(Clone, Debug, Default, PartialEq, Serialize)]
pub struct MediaSummary {
    pub container: String,
    pub duration_ms: Option<u64>,
    pub title: Option<String>,
    pub muxing_app: Option<String>,
    pub writing_app: Option<String>,
    pub segment_uid: Option<String>,
    pub video: Option<VideoSummary>,
    pub audio: Vec<AudioSummary>,
    pub subtitles: Vec<SubtitleSummary>,
    pub editions: usize,
    pub chapters: Vec<ChapterSummary>,
    pub chapter_signature: Option<String>,
    pub commentaries: Vec<Commentary>,
}

impl MediaSummary {
    pub fn build(
        header: &Header,
        video: Option<(&Track, &VideoFindings)>,
        audio: &[(&Track, AudioFindings)],
    ) -> Self {
        let commentaries = ident::commentaries(header);
        let commentary_tracks: Vec<u64> = commentaries.iter().map(|c| c.track).collect();
        let chapters = ident::default_edition(header)
            .map(|edition| {
                edition
                    .chapters
                    .iter()
                    .filter(|c| !c.hidden)
                    .map(|c| ChapterSummary {
                        start_ms: c.start_ns / 1_000_000,
                        name: c.name.clone(),
                        named: c
                            .name
                            .as_deref()
                            .is_some_and(ident::chapter_name_is_meaningful),
                    })
                    .collect()
            })
            .unwrap_or_default();
        Self {
            container: header.doc_type.clone(),
            duration_ms: header.duration_ms,
            title: header.title.clone(),
            muxing_app: header.muxing_app.clone(),
            writing_app: header.writing_app.clone(),
            segment_uid: header.segment_uid.clone(),
            video: video.map(|(track, findings)| video_summary(track, findings)),
            audio: audio
                .iter()
                .map(|(track, findings)| AudioSummary {
                    track: track.number,
                    codec: audio_codec(&track.codec_id, findings),
                    channels: track
                        .audio
                        .as_ref()
                        .and_then(|a| a.channels)
                        .map(channel_label),
                    language: lang::code(&track.language),
                    name: track.name.clone(),
                    atmos: findings.atmos,
                    dtsx: findings.dtsx,
                    commentary: commentary_tracks.contains(&track.number),
                    default: track.default,
                })
                .collect(),
            subtitles: header
                .tracks
                .iter()
                .filter(|t| t.kind == TrackKind::Subtitle)
                .map(|t| {
                    let lower = t.name.as_deref().unwrap_or_default().to_lowercase();
                    SubtitleSummary {
                        track: t.number,
                        codec: subtitle_codec(&t.codec_id).to_string(),
                        language: lang::code(&t.language),
                        name: t.name.clone(),
                        forced: t.forced || lower.contains("forced"),
                        sdh: t.hearing_impaired || lower.contains("sdh"),
                        commentary: t.commentary || lower.contains("comment"),
                    }
                })
                .collect(),
            editions: header.editions.iter().filter(|e| !e.hidden).count(),
            chapters,
            chapter_signature: ident::chapter_signature(header),
            commentaries,
        }
    }

    /// Newznab attributes, standard names first, then this indexer's own.
    pub fn attrs(&self) -> Vec<(&'static str, String)> {
        let mut attrs = Vec::new();
        if let Some(video) = &self.video {
            attrs.push(("video", video.codec.clone()));
            if let (Some(w), Some(h)) = (video.width, video.height) {
                attrs.push(("resolution", format!("{w}x{h}")));
            }
            if let Some(rate) = &video.frame_rate {
                attrs.push(("framerate", rate.clone()));
            }
        }
        let main_audio: Vec<String> = self
            .audio
            .iter()
            .filter(|a| !a.commentary)
            .map(AudioSummary::label)
            .collect();
        if !main_audio.is_empty() {
            attrs.push(("audio", dedup(main_audio).join(", ")));
        }
        let languages = names(
            self.audio
                .iter()
                .filter(|a| !a.commentary)
                .map(|a| &a.language),
        );
        if !languages.is_empty() {
            attrs.push(("language", languages.join(", ")));
        }
        let subs = names(self.subtitles.iter().map(|s| &s.language));
        if !subs.is_empty() {
            attrs.push(("subs", subs.join(", ")));
        }
        if let Some(ms) = self.duration_ms {
            attrs.push(("runtime", (ms.saturating_add(30_000) / 60_000).to_string()));
        }
        if let Some(video) = &self.video {
            attrs.push(("hdr", video.hdr.join(" ")));
            if let Some(dovi) = &video.dovi {
                if let Some(profile) = &dovi.profile {
                    attrs.push(("dvprofile", profile.clone()));
                }
                if let Some(el) = dovi.el {
                    attrs.push(("dvel", el.as_str().to_string()));
                }
                if let Some(cm) = dovi.cm_version {
                    attrs.push(("dvcm", cm.to_string()));
                }
            }
        }
        if self.audio.iter().any(|a| a.atmos) {
            attrs.push(("atmos", "1".into()));
        }
        if self.audio.iter().any(|a| a.dtsx) {
            attrs.push(("dtsx", "1".into()));
        }
        if !self.chapters.is_empty() {
            attrs.push(("chapters", self.chapters.len().to_string()));
        }
        if !self.commentaries.is_empty() {
            attrs.push(("commentary", self.commentaries.len().to_string()));
        }
        attrs
    }

    /// Lowercase search tokens, e.g. `hevc 2160p dv dvp7 fel hdr10 atmos truehd eng subs-eng`.
    pub fn tags(&self) -> Vec<String> {
        let mut tags = Vec::new();
        if let Some(video) = &self.video {
            tags.push(video.codec.to_lowercase());
            if let Some(res) = video.resolution {
                tags.push(res.to_string());
            }
            for hdr in &video.hdr {
                tags.push(match *hdr {
                    "HDR10+" => "hdr10plus".to_string(),
                    other => other.to_lowercase(),
                });
            }
            if let Some(dovi) = &video.dovi {
                if let Some(profile) = &dovi.profile {
                    let major = profile.split('.').next().unwrap_or(profile);
                    tags.push(format!("dvp{major}"));
                    if profile.contains('.') {
                        tags.push(format!("dvp{}", profile.replace('.', "")));
                    }
                }
                if let Some(el) = dovi.el {
                    tags.push(el.as_str().to_lowercase());
                }
            }
            if video.bit_depth == Some(10) {
                tags.push("10bit".into());
            }
        }
        for audio in &self.audio {
            tags.push(audio.codec.to_lowercase().replace([' ', '-', ':', '+'], ""));
            if audio.atmos {
                tags.push("atmos".into());
            }
            if audio.dtsx {
                tags.push("dtsx".into());
            }
            if audio.language != "und" {
                tags.push(audio.language.clone());
            }
        }
        for sub in &self.subtitles {
            if sub.language != "und" {
                tags.push(format!("subs-{}", sub.language));
            }
        }
        if !self.commentaries.is_empty() {
            tags.push("commentary".into());
        }
        if self.chapters.iter().any(|c| c.named) {
            tags.push("namedchapters".into());
        }
        dedup(tags)
    }
}

impl AudioSummary {
    /// `TrueHD Atmos 7.1`, `DTS-HD MA 5.1`, `E-AC-3 Atmos 5.1`.
    pub fn label(&self) -> String {
        let mut label = self.codec.clone();
        if self.atmos {
            label.push_str(" Atmos");
        }
        if let Some(channels) = &self.channels {
            label.push(' ');
            label.push_str(channels);
        }
        label
    }
}

fn dedup(items: Vec<String>) -> Vec<String> {
    let mut out: Vec<String> = Vec::with_capacity(items.len());
    for item in items {
        if !out.contains(&item) {
            out.push(item);
        }
    }
    out
}

fn names<'a>(codes: impl Iterator<Item = &'a String>) -> Vec<String> {
    dedup(
        codes
            .filter_map(|code| lang::name(code))
            .map(String::from)
            .collect(),
    )
}

fn video_summary(track: &Track, findings: &VideoFindings) -> VideoSummary {
    let info = track.video.clone().unwrap_or_default();
    let codec = match track.codec_id.as_str() {
        "V_MPEGH/ISO/HEVC" => "HEVC",
        "V_MPEG4/ISO/AVC" => "AVC",
        "V_AV1" => "AV1",
        "V_VP9" => "VP9",
        "V_MPEG2" => "MPEG-2",
        "V_MS/VFW/FOURCC" => "VFW",
        other => other.trim_start_matches("V_"),
    }
    .to_string();
    let config = track.dovi;
    let rpu = findings.rpu.as_ref();
    let dovi = (config.is_some() || rpu.is_some()).then(|| DoviSummary {
        profile: config
            .map(|c| c.profile_name())
            .or_else(|| rpu.map(|r| r.profile.to_string())),
        el: rpu.and_then(|r| r.el_type),
        cm_version: rpu.map(|r| r.cm_version),
        el_present: config.is_some_and(|c| c.el_present) || findings.el_nal_seen,
    });
    let profile5 = config.map(|c| c.profile) == Some(5) || rpu.map(|r| r.profile) == Some(5);
    let mut hdr = Vec::new();
    if dovi.is_some() {
        hdr.push("DV");
    }
    if findings.hdr10plus {
        hdr.push("HDR10+");
    }
    match info.transfer {
        Some(16) if !profile5 => hdr.push("HDR10"),
        Some(18) => hdr.push("HLG"),
        _ => {}
    }
    if hdr.is_empty() {
        hdr.push("SDR");
    }
    VideoSummary {
        track: track.number,
        codec,
        width: info.width,
        height: info.height,
        resolution: resolution(info.width, info.height),
        frame_rate: track.default_duration_ns.and_then(frame_rate),
        // ffmpeg doesn't write BitsPerChannel, so fall back to hvcC's bitDepthLumaMinus8.
        bit_depth: info.bits_per_channel.filter(|&b| b > 0).or_else(|| {
            (track.codec_id == "V_MPEGH/ISO/HEVC")
                .then(|| track.codec_private.get(17).map(|b| u64::from(b & 7) + 8))
                .flatten()
        }),
        hdr,
        dovi,
        max_cll: info
            .max_cll
            .or_else(|| rpu.and_then(|r| r.max_cll).map(u64::from)),
        max_fall: info
            .max_fall
            .or_else(|| rpu.and_then(|r| r.max_fall).map(u64::from)),
        encoder: findings.encoder.clone(),
    }
}

/// Labels by width first, so scope crops (1920x800) still read as 1080p.
fn resolution(width: Option<u64>, height: Option<u64>) -> Option<&'static str> {
    let (w, h) = (width.unwrap_or(0), height.unwrap_or(0));
    Some(if w >= 3200 || h >= 1800 {
        "2160p"
    } else if w >= 1700 || h >= 1000 {
        "1080p"
    } else if w >= 1200 || h >= 700 {
        "720p"
    } else if h >= 560 {
        "576p"
    } else if w > 0 || h > 0 {
        "480p"
    } else {
        return None;
    })
}

fn frame_rate(default_duration_ns: u64) -> Option<String> {
    if default_duration_ns == 0 {
        return None;
    }
    let fps = 1e9 / default_duration_ns as f64;
    for (nominal, label) in [
        (24000.0 / 1001.0, "23.976"),
        (24.0, "24"),
        (25.0, "25"),
        (30000.0 / 1001.0, "29.97"),
        (30.0, "30"),
        (50.0, "50"),
        (60000.0 / 1001.0, "59.94"),
        (60.0, "60"),
    ] {
        if (fps - nominal).abs() < 0.005 {
            return Some(label.into());
        }
    }
    Some(format!("{fps:.3}"))
}

fn channel_label(channels: u64) -> String {
    match channels {
        1 => "1.0".into(),
        2 => "2.0".into(),
        3 => "2.1".into(),
        6 => "5.1".into(),
        7 => "6.1".into(),
        8 => "7.1".into(),
        n => format!("{n}ch"),
    }
}

fn audio_codec(codec_id: &str, findings: &AudioFindings) -> String {
    match codec_id {
        "A_TRUEHD" => "TrueHD",
        "A_EAC3" => "E-AC-3",
        "A_AC3" | "A_AC3/BSID9" | "A_AC3/BSID10" => "AC-3",
        id if id.starts_with("A_DTS") => {
            if findings.dtsx {
                "DTS:X"
            } else if findings.dts_hd_ma {
                "DTS-HD MA"
            } else {
                "DTS"
            }
        }
        "A_FLAC" => "FLAC",
        id if id.starts_with("A_AAC") => "AAC",
        "A_OPUS" => "Opus",
        "A_VORBIS" => "Vorbis",
        id if id.starts_with("A_PCM") => "PCM",
        "A_MPEG/L3" => "MP3",
        "A_MPEG/L2" => "MP2",
        other => other.trim_start_matches("A_"),
    }
    .to_string()
}

fn subtitle_codec(codec_id: &str) -> &str {
    match codec_id {
        "S_HDMV/PGS" => "PGS",
        "S_TEXT/UTF8" => "SRT",
        "S_TEXT/ASS" | "S_ASS" => "ASS",
        "S_TEXT/SSA" | "S_SSA" => "SSA",
        "S_VOBSUB" => "VobSub",
        "S_TEXT/WEBVTT" => "WebVTT",
        "S_HDMV/TEXTST" => "TextST",
        other => other.trim_start_matches("S_"),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn labels() {
        assert_eq!(resolution(Some(1920), Some(800)), Some("1080p"));
        assert_eq!(resolution(Some(3840), Some(1608)), Some("2160p"));
        assert_eq!(resolution(Some(1280), Some(536)), Some("720p"));
        assert_eq!(resolution(None, None), None);
        assert_eq!(frame_rate(41_708_333).as_deref(), Some("23.976"));
        assert_eq!(frame_rate(40_000_000).as_deref(), Some("25"));
        assert_eq!(channel_label(8), "7.1");
    }
}
