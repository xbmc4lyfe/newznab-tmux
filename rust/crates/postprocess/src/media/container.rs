//! What a downloaded media file is and how much of it arrived. Port of `VideoContainer`,
//! `SourcePosition` and `MediaSourceHints`.

use std::sync::LazyLock;

use nntmux_core::pcre::Pcre;

static EXTENSION: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r#"/\.([a-z0-9]{2,4})"?(?:\s|$)/i"#).expect("a valid regex"));

/// Video container formats, named by their ffmpeg demuxer. ffmpeg is told the demuxer instead
/// of probing for one, so a downloaded file can never be read as a playlist.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum VideoContainer {
    Matroska,
    Avi,
    MpegProgram,
    MpegTransport,
    Mp4,
    Asf,
    Ogg,
}

impl VideoContainer {
    /// The ffmpeg demuxer name.
    pub fn demuxer(self) -> &'static str {
        match self {
            Self::Matroska => "matroska",
            Self::Avi => "avi",
            Self::MpegProgram => "mpeg",
            Self::MpegTransport => "mpegts",
            Self::Mp4 => "mov",
            Self::Asf => "asf",
            Self::Ogg => "ogg",
        }
    }

    pub fn from_demuxer(name: &str) -> Option<Self> {
        [
            Self::Matroska,
            Self::Avi,
            Self::MpegProgram,
            Self::MpegTransport,
            Self::Mp4,
            Self::Asf,
            Self::Ogg,
        ]
        .into_iter()
        .find(|container| container.demuxer() == name)
    }

    /// `detect`: the container the first bytes of a file show.
    pub fn detect(head: &[u8]) -> Option<Self> {
        if head.len() < 12 {
            return None;
        }
        let at = |offset: usize, expected: &[u8]| head[offset..].starts_with(expected);
        if at(0, b"\x1A\x45\xDF\xA3") {
            Some(Self::Matroska)
        } else if at(0, b"RIFF") && at(8, b"AVI ") {
            Some(Self::Avi)
        } else if at(0, b"\x00\x00\x01\xBA") || at(0, b"\x00\x00\x01\xB3") {
            Some(Self::MpegProgram)
        } else if at(4, b"ftyp") || at(4, b"moov") {
            Some(Self::Mp4)
        } else if at(0, b"\x30\x26\xB2\x75\x8E\x66\xCF\x11") {
            Some(Self::Asf)
        } else if at(0, b"OggS") {
            Some(Self::Ogg)
        } else if transport_stream_offset(head).is_some() {
            Some(Self::MpegTransport)
        } else {
            None
        }
    }

    /// `fromFileName`: the container a file name's extension suggests.
    pub fn from_file_name(name: &str) -> Option<Self> {
        let trimmed = name.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B']);
        let captures = EXTENSION.captures(trimmed.as_bytes())?;
        let extension = String::from_utf8_lossy(captures.group(1)?).to_ascii_lowercase();
        match extension.as_str() {
            "mkv" | "mk3d" | "webm" => Some(Self::Matroska),
            "avi" | "divx" | "xvid" => Some(Self::Avi),
            "mpg" | "mpeg" | "vob" | "m2v" | "mpv" => Some(Self::MpegProgram),
            "ts" | "m2ts" | "mts" => Some(Self::MpegTransport),
            "mp4" | "m4v" | "mov" | "qt" => Some(Self::Mp4),
            "wmv" | "asf" => Some(Self::Asf),
            "ogv" | "ogm" => Some(Self::Ogg),
            _ => None,
        }
    }

    pub fn extension(self) -> &'static str {
        match self {
            Self::Matroska => "mkv",
            Self::Avi => "avi",
            Self::MpegProgram => "mpg",
            Self::MpegTransport => "ts",
            Self::Mp4 => "mp4",
            Self::Asf => "wmv",
            Self::Ogg => "ogv",
        }
    }

    /// Whether a chunk from the middle of a file decodes given at most the file's opening
    /// headers. MP4 keeps its index in one box, often at the end; ASF and Ogg need more.
    pub fn can_resume_mid_stream(self) -> bool {
        matches!(
            self,
            Self::Matroska | Self::Avi | Self::MpegProgram | Self::MpegTransport
        )
    }
}

/// `transportStreamOffset`: the first of four transport stream packets 188 or 192 bytes apart.
pub fn transport_stream_offset(data: &[u8]) -> Option<usize> {
    for stride in [188usize, 192] {
        let limit = (data.len() as i64 - stride as i64 * 4).min(stride as i64);
        for offset in 0..limit.max(0) as usize {
            if (0..4).all(|packet| data[offset + stride * packet] == 0x47) {
                return Some(offset);
            }
        }
    }
    None
}

/// Which part of a media file a downloaded copy holds.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum SourcePosition {
    Complete,
    Head,
    Mid,
}

/// What is known about a partial media file handed to ffmpeg.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct MediaSourceHints {
    pub position: SourcePosition,
    /// Size of the whole file, when the release told us.
    pub real_size: Option<i64>,
    /// Bytes of it that were downloaded.
    pub available_bytes: i64,
}

impl MediaSourceHints {
    pub fn complete() -> Self {
        Self {
            position: SourcePosition::Complete,
            real_size: None,
            available_bytes: 0,
        }
    }

    pub fn head(real_size: Option<i64>, available_bytes: i64) -> Self {
        let real_size = real_size.filter(|size| *size > 0);
        let position = match real_size {
            Some(size) if available_bytes >= size => SourcePosition::Complete,
            _ => SourcePosition::Head,
        };
        Self {
            position,
            real_size,
            available_bytes,
        }
    }

    pub fn mid(available_bytes: i64) -> Self {
        Self {
            position: SourcePosition::Mid,
            real_size: None,
            available_bytes,
        }
    }

    /// The share of the file that was downloaded, when the real size is known.
    pub fn available_fraction(&self) -> Option<f64> {
        if self.position == SourcePosition::Complete {
            return Some(1.0);
        }
        let real_size = self.real_size?;
        if self.available_bytes <= 0 {
            return None;
        }
        Some((self.available_bytes as f64 / real_size as f64).min(1.0))
    }
}
