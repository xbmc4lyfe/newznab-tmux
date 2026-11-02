//! Preview frames from a video, which is often only the start of the file. Port of
//! `FfmpegFrameGrabber`, `FrameQualityScorer` and `ThumbnailFrameSelector`.

use std::fs;
use std::path::{Path, PathBuf};
use std::process::Stdio;
use std::sync::LazyLock;
use std::time::Duration;

use anyhow::{Result, anyhow, bail};
use async_trait::async_trait;
use nntmux_core::pcre::Pcre;
use rand::Rng;
use serde_json::Value;
use tokio::process::Command;

use super::container::{MediaSourceHints, SourcePosition, VideoContainer};

/// What the container says about the whole video.
#[derive(Clone, Copy, Debug, Default, PartialEq)]
pub struct Probe {
    pub duration: Option<f64>,
    /// The video stream's own bit rate, never one derived from a partial download's size.
    pub bit_rate: Option<i64>,
}

/// `VideoFrameGrabber`.
#[async_trait]
pub trait VideoFrameGrabber: Send + Sync {
    /// Up to `count` keyframes from `from` seconds on, at least `spacing` seconds apart, saved
    /// as JPEG files in `output`. Paths in playback order.
    async fn grab(
        &self,
        video: &Path,
        container: Option<VideoContainer>,
        from: f64,
        spacing: f64,
        count: i64,
        output: &Path,
    ) -> Vec<PathBuf>;

    /// An error is a probe output PHP choked on, which ended the whole preview.
    async fn probe(&self, video: &Path, container: Option<VideoContainer>) -> Result<Probe>;
}

/// `FfmpegFrameGrabber`: one ffmpeg call that decodes only keyframes, so frames far into a file
/// come quickly, chosen by timestamp because a partial download can't be seeked.
#[derive(Clone, Debug)]
pub struct FfmpegFrameGrabber {
    pub ffmpeg: Option<PathBuf>,
    pub ffprobe: Option<PathBuf>,
    /// php-ffmpeg's process timeout.
    pub timeout: Duration,
}

/// Keeps ffmpeg and ffprobe to local files, including for anything the input refers to. PHP let
/// them sniff the format freely, so a downloaded manifest (DASH, SDP and the like) could make
/// them fetch from the network; the port only reads the file it was given.
pub const LOCAL_ONLY: [&str; 2] = ["-protocol_whitelist", "file"];

impl FfmpegFrameGrabber {
    /// The ffmpeg arguments for a grab into files starting with `prefix`.
    pub fn grab_arguments(
        video: &Path,
        container: Option<VideoContainer>,
        from: f64,
        spacing: f64,
        count: i64,
        prefix: &str,
    ) -> Vec<String> {
        let mut arguments: Vec<String> = ["-y", "-v", "error"].map(String::from).to_vec();
        arguments.extend(LOCAL_ONLY.map(String::from));
        arguments.extend(["-skip_frame", "nokey"].map(String::from));
        arguments.extend(input_format(container));
        arguments.extend([
            "-i".to_owned(),
            video.to_string_lossy().into_owned(),
            "-an".to_owned(),
            "-sn".to_owned(),
            "-dn".to_owned(),
            "-vf".to_owned(),
            // Selected frames are renumbered a second apart and written one per second, so the
            // image muxer never repeats a frame to fill a gap.
            format!(
                "select='gte(t,{:.3})*(isnan(prev_selected_t)+gte(t-prev_selected_t,{:.3}))',setpts=N/TB,scale='min(1280,iw)':-2",
                from.max(0.0),
                spacing.max(0.0),
            ),
            "-r".to_owned(),
            "1".to_owned(),
            "-frames:v".to_owned(),
            count.max(1).to_string(),
            "-q:v".to_owned(),
            "3".to_owned(),
            format!("{prefix}%02d.jpg"),
        ]);
        arguments
    }

    pub fn probe_arguments(video: &Path, container: Option<VideoContainer>) -> Vec<String> {
        let mut arguments = vec!["-v".to_owned(), "error".to_owned()];
        arguments.extend(LOCAL_ONLY.map(String::from));
        arguments.extend(input_format(container));
        arguments
            .extend(["-show_format", "-show_streams", "-print_format", "json"].map(String::from));
        arguments.push(video.to_string_lossy().into_owned());
        arguments
    }
}

#[async_trait]
impl VideoFrameGrabber for FfmpegFrameGrabber {
    async fn grab(
        &self,
        video: &Path,
        container: Option<VideoContainer>,
        from: f64,
        spacing: f64,
        count: i64,
        output: &Path,
    ) -> Vec<PathBuf> {
        let directory = output.to_string_lossy();
        let name = format!("frame_{}_", hex::encode(rand::rng().random::<[u8; 4]>()));
        let prefix = format!("{}/{name}", directory.trim_end_matches('/'));
        if let Some(ffmpeg) = &self.ffmpeg {
            let arguments = Self::grab_arguments(video, container, from, spacing, count, &prefix);
            // A truncated file often fails after the frames we need were written.
            let _ = run(ffmpeg, &arguments, self.timeout).await;
        }
        let Ok(entries) = fs::read_dir(Path::new(&prefix).parent().unwrap_or(output)) else {
            return Vec::new();
        };
        let mut frames: Vec<PathBuf> = entries
            .filter_map(|entry| entry.ok())
            .filter(|entry| {
                let file = entry.file_name();
                let file = file.to_string_lossy();
                file.starts_with(&name) && file.ends_with(".jpg")
            })
            .map(|entry| entry.path())
            .filter(|path| fs::metadata(path).is_ok_and(|meta| meta.len() > 0))
            .collect();
        frames.sort();
        frames
    }

    async fn probe(&self, video: &Path, container: Option<VideoContainer>) -> Result<Probe> {
        let Some(ffprobe) = &self.ffprobe else {
            return Ok(Probe::default());
        };
        match run(
            ffprobe,
            &Self::probe_arguments(video, container),
            self.timeout,
        )
        .await
        {
            Ok(output) => parse_probe(&output),
            Err(_) => Ok(Probe::default()),
        }
    }
}

fn input_format(container: Option<VideoContainer>) -> Vec<String> {
    container.map_or_else(Vec::new, |container| {
        vec!["-f".to_owned(), container.demuxer().to_owned()]
    })
}

/// Runs a tool as php-ffmpeg's driver did: its stdout on success, an error on a non-zero exit or
/// at the time limit, when the tool is killed.
async fn run(binary: &Path, arguments: &[String], timeout: Duration) -> Result<Vec<u8>> {
    let child = Command::new(binary)
        .args(arguments)
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .kill_on_drop(true)
        .spawn()?;
    let output = tokio::time::timeout(timeout, child.wait_with_output())
        .await
        .map_err(|_| anyhow!("{} timed out", binary.display()))??;
    if !output.status.success() {
        bail!("{} failed", binary.display());
    }
    Ok(output.stdout)
}

static CLOCK: LazyLock<Pcre> = LazyLock::new(|| {
    Pcre::new(r"/^(\d+):(\d{2}):(\d{2}(?:\.\d+)?)(?=\n?\z)/").expect("a valid regex")
});

/// `probe`'s reading of ffprobe's JSON.
pub fn parse_probe(output: &[u8]) -> Result<Probe> {
    let Ok(Value::Object(info)) = serde_json::from_slice::<Value>(output) else {
        return Ok(Probe::default());
    };
    let streams: &[Value] = match info.get("streams") {
        None | Some(Value::Null) => &[],
        Some(Value::Array(streams)) => streams,
        Some(Value::Object(streams)) => {
            return parse_streams(&info, &streams.values().cloned().collect::<Vec<_>>());
        }
        // PHP's foreach warned, which Laravel turned into an exception.
        Some(_) => bail!("foreach() argument must be of type array|object"),
    };
    parse_streams(&info, streams)
}

fn parse_streams(info: &serde_json::Map<String, Value>, streams: &[Value]) -> Result<Probe> {
    let video = streams.iter().find_map(|stream| {
        stream
            .as_object()
            .filter(|stream| stream.get("codec_type").and_then(Value::as_str) == Some("video"))
    });
    let tags: Vec<(String, &Value)> = video
        .and_then(|video| video.get("tags"))
        .and_then(Value::as_object)
        .map(|tags| {
            tags.iter()
                .map(|(key, value)| (key.to_ascii_uppercase(), value))
                .collect()
        })
        .unwrap_or_default();
    let tag = |name: &str| {
        tags.iter()
            .rev()
            .find(|(key, _)| key == name)
            .map(|(_, value)| *value)
    };
    let format_duration = info
        .get("format")
        .and_then(Value::as_object)
        .and_then(|format| format.get("duration"));
    let duration = format_duration
        .and_then(positive_float)
        .or_else(|| {
            video
                .and_then(|video| video.get("duration"))
                .and_then(positive_float)
        })
        .or_else(|| tag("DURATION").and_then(clock_seconds));
    let bit_rate = tag("BPS").and_then(positive_float).or_else(|| {
        video
            .and_then(|video| video.get("bit_rate"))
            .and_then(positive_float)
    });
    Ok(Probe {
        duration,
        bit_rate: bit_rate.map(php_int),
    })
}

/// `is_numeric($value) && (float) $value > 0`.
fn positive_float(value: &Value) -> Option<f64> {
    let number = match value {
        Value::Number(number) => number.as_f64()?,
        Value::String(text) => php_numeric(text)?,
        _ => return None,
    };
    (number > 0.0).then_some(number)
}

/// `(int)` of a float: zero for non-finite values, wrapped modulo 2^64 past the integer range.
pub fn php_int(value: f64) -> i64 {
    const TWO_POW_63: f64 = 9_223_372_036_854_775_808.0;
    const TWO_POW_64: f64 = 18_446_744_073_709_551_616.0;
    if !value.is_finite() {
        return 0;
    }
    if (-TWO_POW_63..TWO_POW_63).contains(&value) {
        return value as i64;
    }
    let mut wrapped = value % TWO_POW_64;
    if wrapped < 0.0 {
        wrapped += TWO_POW_64;
    }
    if wrapped >= TWO_POW_63 {
        wrapped -= TWO_POW_64;
    }
    wrapped as i64
}

/// A PHP numeric string's value: surrounding whitespace, sign, digits, fraction, exponent.
pub fn php_numeric(text: &str) -> Option<f64> {
    let whitespace = [' ', '\t', '\n', '\r', '\u{b}', '\u{c}'];
    let trimmed = text
        .trim_start_matches(whitespace)
        .trim_end_matches(whitespace);
    let bytes = trimmed.as_bytes();
    let mut end = usize::from(matches!(bytes.first(), Some(b'+' | b'-')));
    let start = end;
    while bytes.get(end).is_some_and(u8::is_ascii_digit) {
        end += 1;
    }
    let mut digits = end - start;
    if bytes.get(end) == Some(&b'.') {
        let mut stop = end + 1;
        while bytes.get(stop).is_some_and(u8::is_ascii_digit) {
            stop += 1;
        }
        digits += stop - end - 1;
        end = stop;
    }
    if digits == 0 {
        return None;
    }
    if matches!(bytes.get(end), Some(b'e' | b'E')) {
        let mut stop = end + 1;
        if matches!(bytes.get(stop), Some(b'+' | b'-')) {
            stop += 1;
        }
        let exponent = stop;
        while bytes.get(stop).is_some_and(u8::is_ascii_digit) {
            stop += 1;
        }
        if stop > exponent {
            end = stop;
        }
    }
    if end != bytes.len() {
        return None;
    }
    trimmed.parse().ok()
}

/// Seconds in a Matroska `HH:MM:SS.nnnnnnnnn` duration tag.
fn clock_seconds(value: &Value) -> Option<f64> {
    let text = value.as_str()?;
    let captures = CLOCK.captures(text.as_bytes())?;
    let number = |index: usize| {
        String::from_utf8_lossy(captures.group(index).unwrap_or_default()).into_owned()
    };
    // `(int)` of a digit string saturates.
    let hours = number(1).parse::<i64>().unwrap_or(i64::MAX);
    let minutes = number(2).parse::<i64>().unwrap_or(i64::MAX);
    let seconds: f64 = number(3).parse().ok()?;
    let total = hours as f64 * 3600.0 + minutes as f64 * 60.0 + seconds;
    (total > 0.0).then_some(total)
}

/// `FrameQualityScorer`: a blank frame (black, white or one flat colour) is never chosen; among
/// the rest, frames of normal brightness come first, then the most detailed.
#[derive(Clone, Copy, Debug, PartialEq)]
pub struct FrameQualityScorer {
    pub min_stddev: f64,
    pub min_luma: f64,
    pub max_luma: f64,
}

impl FrameQualityScorer {
    /// The best frame, or None when every frame is blank or unreadable. Blocking.
    pub fn best(&self, frames: &[PathBuf]) -> Option<PathBuf> {
        let mut best: Option<(&PathBuf, (bool, f64))> = None;
        for frame in frames {
            let Some((mean, stddev)) = nntmux_metadata::images::luma_measure(frame) else {
                continue;
            };
            if stddev < self.min_stddev {
                continue;
            }
            let rank = (mean >= self.min_luma && mean <= self.max_luma, stddev);
            if best.is_none_or(|(_, current)| rank > current) {
                best = Some((frame, rank));
            }
        }
        best.map(|(frame, _)| frame.clone())
    }
}

/// Share of the downloaded playing time skipped before taking frames.
const START_FRACTION: f64 = 0.25;
/// Margin for bytes that hold headers or decode to less than the average.
const PLAYABLE_MARGIN: f64 = 0.85;

/// `ThumbnailFrameSelector`: openings tend to be black or show logos, so frames are taken a
/// quarter of the way into the part that was downloaded.
pub struct ThumbnailFrameSelector<'a> {
    pub attempts: i64,
    pub grabber: &'a dyn VideoFrameGrabber,
    pub scorer: FrameQualityScorer,
}

impl ThumbnailFrameSelector<'_> {
    /// The best frame's path, inside `work`, or None when every frame was blank.
    pub async fn select(
        &self,
        video: &Path,
        work: &Path,
        hints: Option<&MediaSourceHints>,
    ) -> Result<Option<PathBuf>> {
        let head = read_head(video);
        let container = VideoContainer::detect(&head);
        if container.is_none() && looks_like_text(&head) {
            // Never let ffmpeg read a playlist or concat script out of downloaded data.
            return Ok(None);
        }
        let (from, spacing) = self.window(video, container, hints).await?;
        let mut windows = vec![(from, spacing)];
        if from > 0.0 {
            windows.push((0.0, 0.0));
        }
        for (start, gap) in windows {
            let frames = self
                .grabber
                .grab(video, container, start, gap, self.attempts, work)
                .await;
            let scorer = self.scorer;
            let best = tokio::task::spawn_blocking(move || scorer.best(&frames)).await?;
            if best.is_some() {
                return Ok(best);
            }
        }
        Ok(None)
    }

    /// When to take the first frame, and how far apart the candidates are so they cover the rest
    /// of the downloaded playing time, in seconds.
    pub async fn window(
        &self,
        video: &Path,
        container: Option<VideoContainer>,
        hints: Option<&MediaSourceHints>,
    ) -> Result<(f64, f64)> {
        if hints.is_some_and(|hints| hints.position == SourcePosition::Mid) {
            return Ok((0.0, 0.0));
        }
        let playable = self.playable_seconds(video, container, hints).await?;
        let from = php_round3(playable * START_FRACTION);
        Ok((from, php_round3((playable - from) / self.attempts as f64)))
    }

    async fn playable_seconds(
        &self,
        video: &Path,
        container: Option<VideoContainer>,
        hints: Option<&MediaSourceHints>,
    ) -> Result<f64> {
        let probe = self.grabber.probe(video, container).await?;
        let available = match hints {
            Some(hints) if hints.available_bytes > 0 => hints.available_bytes,
            _ => fs::metadata(video).map_or(0, |meta| meta.len() as i64),
        };
        let fraction = hints.and_then(MediaSourceHints::available_fraction);
        Ok(match (probe.duration, fraction, probe.bit_rate) {
            (Some(duration), Some(fraction), _) => {
                duration * fraction * if fraction < 1.0 { PLAYABLE_MARGIN } else { 1.0 }
            }
            (_, _, Some(bit_rate)) if hints.is_some() && available > 0 => {
                if bit_rate == 0 {
                    bail!("Division by zero");
                }
                (available * 8) as f64 / bit_rate as f64 * PLAYABLE_MARGIN
            }
            // Unknown size: assume the whole file is here; the retry from 0 covers a partial one.
            (duration, _, _) => duration.unwrap_or(0.0),
        })
    }
}

/// The first 4 KiB of a file, or nothing when it can't be read.
fn read_head(path: &Path) -> Vec<u8> {
    use std::io::Read;
    let mut head = Vec::new();
    if let Ok(file) = fs::File::open(path) {
        let _ = file.take(4096).read_to_end(&mut head);
    }
    head
}

fn looks_like_text(head: &[u8]) -> bool {
    let start = head
        .iter()
        .position(|byte| !b"\xEF\xBB\xBF \t\r\n".contains(byte))
        .map_or(&[][..], |offset| &head[offset..]);
    let starts_with = |prefix: &[u8]| {
        start.len() >= prefix.len() && start[..prefix.len()].eq_ignore_ascii_case(prefix)
    };
    starts_with(b"#EXTM3U") || starts_with(b"ffconcat")
}

/// PHP's `round($value, 3)`, which pre-rounds to 15 significant digits before rounding half away
/// from zero.
pub fn php_round3(value: f64) -> f64 {
    if !value.is_finite() || value == 0.0 {
        return value;
    }
    let scaled = value * 1000.0;
    let pre: f64 = format!("{scaled:.14e}").parse().unwrap_or(scaled);
    let rounded = pre.round();
    let result = rounded / 1000.0;
    format!("{result:.15e}").parse().unwrap_or(result)
}
