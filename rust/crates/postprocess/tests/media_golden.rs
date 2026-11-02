//! The preview steps against `fixtures/media_golden.json`, generated from the PHP classes by
//! `fixtures/generate_media_golden.php`.

use std::path::{Path, PathBuf};
use std::sync::Mutex;

use anyhow::Result;
use async_trait::async_trait;
use nntmux_metadata::images::luma_measure;
use nntmux_postprocess::media::container::{MediaSourceHints, SourcePosition, VideoContainer};
use nntmux_postprocess::media::frames::{
    FfmpegFrameGrabber, FrameQualityScorer, Probe, ThumbnailFrameSelector, VideoFrameGrabber,
    parse_probe,
};
use nntmux_postprocess::media::images::{file_name, is_usable, rank, sort_candidates};
use serde_json::Value;

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/media_golden.json")).expect("valid golden JSON")
}

fn media() -> PathBuf {
    Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/media")
}

fn hints_of(hint: &Value) -> Option<MediaSourceHints> {
    let hint = hint.as_array().expect("hint");
    match hint[0].as_str().expect("kind") {
        "none" => None,
        "complete" => Some(MediaSourceHints::complete()),
        "head" => Some(MediaSourceHints::head(
            hint[1].as_i64(),
            hint[2].as_i64().expect("available"),
        )),
        "mid" => Some(MediaSourceHints::mid(
            hint.get(1).and_then(Value::as_i64).unwrap_or(0),
        )),
        other => panic!("unknown hint {other}"),
    }
}

fn close(left: f64, right: f64) -> bool {
    (left - right).abs() <= 1e-9 * left.abs().max(right.abs()).max(1.0)
}

fn probe_of(value: &Value) -> Probe {
    Probe {
        duration: value["duration"].as_f64(),
        bit_rate: value["bitRate"].as_i64(),
    }
}

struct FakeGrabber {
    probe: Probe,
    frames: Vec<Vec<PathBuf>>,
    calls: Mutex<Vec<(f64, f64, i64)>>,
}

#[async_trait]
impl VideoFrameGrabber for FakeGrabber {
    async fn grab(
        &self,
        _video: &Path,
        _container: Option<VideoContainer>,
        from: f64,
        spacing: f64,
        count: i64,
        _output: &Path,
    ) -> Vec<PathBuf> {
        let mut calls = self.calls.lock().expect("calls");
        calls.push((from, spacing, count));
        self.frames
            .get(calls.len() - 1)
            .cloned()
            .unwrap_or_default()
    }

    async fn probe(&self, _video: &Path, _container: Option<VideoContainer>) -> Result<Probe> {
        Ok(self.probe)
    }
}

const SCORER: FrameQualityScorer = FrameQualityScorer {
    min_stddev: 12.0,
    min_luma: 24.0,
    max_luma: 232.0,
};

#[test]
fn containers_and_hints_match_php() {
    let golden = golden();
    for case in golden["containers"].as_array().expect("containers") {
        let head = hex::decode(case["head"].as_str().expect("head")).expect("hex");
        assert_eq!(
            VideoContainer::detect(&head).map(VideoContainer::demuxer),
            case["container"].as_str(),
            "{}",
            case["name"]
        );
    }
    for case in golden["file_names"].as_array().expect("file names") {
        let name = case["name"].as_str().expect("name");
        assert_eq!(
            VideoContainer::from_file_name(name).map(VideoContainer::demuxer),
            case["container"].as_str(),
            "{name:?}"
        );
    }
    for case in golden["hints"].as_array().expect("hints") {
        let hints = hints_of(&case["hint"]).expect("hints");
        let position = match hints.position {
            SourcePosition::Complete => "complete",
            SourcePosition::Head => "head",
            SourcePosition::Mid => "mid",
        };
        assert_eq!(position, case["position"], "{}", case["hint"]);
        assert_eq!(
            hints.real_size,
            case["real_size"].as_i64(),
            "{}",
            case["hint"]
        );
        assert_eq!(
            hints.available_bytes,
            case["available_bytes"].as_i64().unwrap()
        );
        assert_eq!(
            hints.available_fraction(),
            case["fraction"].as_f64(),
            "{}",
            case["hint"]
        );
    }
}

#[tokio::test]
async fn the_frame_window_matches_php() -> Result<()> {
    let golden = golden();
    let tmp = tempfile::tempdir()?;
    let video = tmp.path().join("window.bin");
    for case in golden["windows"].as_array().expect("windows") {
        let file = std::fs::File::create(&video)?;
        file.set_len(case["file_size"].as_u64().expect("size"))?;
        let grabber = FakeGrabber {
            probe: Probe {
                duration: case["duration"].as_f64(),
                bit_rate: case["bit_rate"].as_i64(),
            },
            frames: Vec::new(),
            calls: Mutex::new(Vec::new()),
        };
        let selector = ThumbnailFrameSelector {
            attempts: case["attempts"].as_i64().expect("attempts"),
            grabber: &grabber,
            scorer: SCORER,
        };
        let (from, spacing) = selector
            .window(&video, None, hints_of(&case["hint"]).as_ref())
            .await?;
        let expected = case["window"].as_array().expect("window");
        assert!(
            close(from, expected[0].as_f64().unwrap())
                && close(spacing, expected[1].as_f64().unwrap()),
            "{case}: got ({from}, {spacing})"
        );
    }
    Ok(())
}

#[tokio::test]
async fn frame_selection_matches_php() -> Result<()> {
    let golden = golden();
    let tmp = tempfile::tempdir()?;
    for case in golden["selections"].as_array().expect("selections") {
        let video = tmp.path().join(case["video"].as_str().expect("video"));
        std::fs::write(&video, hex::decode(case["head"].as_str().expect("head"))?)?;
        let frames = case["frames"]
            .as_array()
            .expect("frames")
            .iter()
            .map(|list| {
                list.as_array()
                    .expect("list")
                    .iter()
                    .map(|frame| media().join(frame.as_str().expect("frame")))
                    .collect()
            })
            .collect();
        let grabber = FakeGrabber {
            probe: probe_of(&case["probe"]),
            frames,
            calls: Mutex::new(Vec::new()),
        };
        let selector = ThumbnailFrameSelector {
            attempts: 4,
            grabber: &grabber,
            scorer: SCORER,
        };
        let best = selector
            .select(&video, tmp.path(), hints_of(&case["hint"]).as_ref())
            .await?;
        let calls: Vec<Value> = grabber
            .calls
            .lock()
            .expect("calls")
            .iter()
            .map(|(from, spacing, count)| serde_json::json!([from, spacing, count]))
            .collect();
        assert_eq!(Value::Array(calls), case["calls"], "{}", case["name"]);
        assert_eq!(
            best.map(|path| path.file_name().unwrap().to_string_lossy().into_owned()),
            case["best"].as_str().map(str::to_owned),
            "{}",
            case["name"]
        );
    }
    Ok(())
}

#[test]
fn image_measures_and_checks_match_php() {
    let golden = golden();
    for case in golden["images"].as_array().expect("images") {
        let name = case["name"].as_str().expect("name");
        let path = media().join(name);
        let measure = luma_measure(&path);
        match (measure, case["measure"].as_object()) {
            (None, None) => {}
            (Some((mean, stddev)), Some(expected)) => {
                // JPEG decoders round a few pixels differently; lossless images match exactly.
                let tolerance = if name.ends_with(".jpg") { 1.5 } else { 1e-6 };
                let (want_mean, want_stddev) = (
                    expected["mean"].as_f64().unwrap(),
                    expected["stddev"].as_f64().unwrap(),
                );
                assert!(
                    (mean - want_mean).abs() <= tolerance
                        && (stddev - want_stddev).abs() <= tolerance,
                    "{name}: ({mean}, {stddev}) vs ({want_mean}, {want_stddev})"
                );
            }
            (got, _) => panic!("{name}: measured {got:?}, PHP {}", case["measure"]),
        }
        assert_eq!(is_usable(&path, 12.0), case["usable"], "{name}");
    }
    for case in golden["best"].as_array().expect("best") {
        let scorer = if case["strict"] == true {
            FrameQualityScorer {
                min_stddev: 40.0,
                min_luma: 60.0,
                max_luma: 180.0,
            }
        } else {
            SCORER
        };
        let frames: Vec<PathBuf> = case["frames"]
            .as_array()
            .expect("frames")
            .iter()
            .map(|frame| media().join(frame.as_str().expect("frame")))
            .collect();
        assert_eq!(
            scorer.best(&frames).map(|path| path
                .file_name()
                .unwrap()
                .to_string_lossy()
                .into_owned()),
            case["best"].as_str().map(str::to_owned),
            "{}",
            case["frames"]
        );
    }
}

#[test]
fn image_names_rank_as_in_php() {
    let golden = golden();
    for case in golden["ranked"].as_array().expect("ranked") {
        let name = case["name"].as_str().expect("name");
        assert_eq!(file_name(name), case["file_name"], "{name:?}");
        assert_eq!(i64::from(rank(name)), case["rank"], "{name:?}");
    }
    let mut items: Vec<(String, i64)> = golden["sort_input"]
        .as_array()
        .expect("input")
        .iter()
        .map(|item| {
            (
                item[0].as_str().unwrap().to_owned(),
                item[1].as_i64().unwrap(),
            )
        })
        .collect();
    sort_candidates(&mut items, |item| &item.0, |item| item.1);
    let names: Vec<&str> = items.iter().map(|item| item.0.as_str()).collect();
    assert_eq!(serde_json::json!(names), golden["sorted"]);
}

#[test]
fn ffprobe_output_is_read_as_in_php() {
    let golden = golden();
    let clip = Path::new("MEDIA/clip.mkv");
    for case in golden["probes"].as_array().expect("probes") {
        let name = case["name"].as_str().expect("name");
        let parsed = parse_probe(case["json"].as_str().expect("json").as_bytes());
        match case["mkv"].get("error") {
            Some(_) => assert!(parsed.is_err(), "{name}"),
            None => assert_eq!(parsed.expect(name), probe_of(&case["mkv"]), "{name}"),
        }
        assert_eq!(
            serde_json::json!(FfmpegFrameGrabber::probe_arguments(
                clip,
                Some(VideoContainer::Matroska)
            )),
            case["args"],
            "{name}"
        );
        assert_eq!(
            serde_json::json!(FfmpegFrameGrabber::probe_arguments(clip, None)),
            case["unknown_args"],
            "{name}"
        );
    }
}

fn ffmpeg() -> Option<PathBuf> {
    [
        "/usr/bin/ffmpeg",
        "/usr/local/bin/ffmpeg",
        "/opt/homebrew/bin/ffmpeg",
    ]
    .iter()
    .map(PathBuf::from)
    .find(|path| path.is_file())
}

#[tokio::test]
async fn frame_grabs_run_ffmpeg_as_php_did() -> Result<()> {
    let golden = golden();
    let ffmpeg = ffmpeg();
    for (index, case) in golden["grabs"]
        .as_array()
        .expect("grabs")
        .iter()
        .enumerate()
    {
        let container = case["container"]
            .as_str()
            .and_then(VideoContainer::from_demuxer);
        let (from, spacing, count) = (
            case["from"].as_f64().unwrap(),
            case["spacing"].as_f64().unwrap(),
            case["count"].as_i64().unwrap(),
        );
        let file = case["file"].as_str().unwrap();
        let arguments = FfmpegFrameGrabber::grab_arguments(
            &Path::new("MEDIA").join(file),
            container,
            from,
            spacing,
            count,
            &format!("WORK/grab_{index}/frame_RANDOM_"),
        );
        assert_eq!(serde_json::json!(arguments), case["args"], "grab {index}");

        // The frames themselves need the real ffmpeg.
        let Some(ffmpeg) = &ffmpeg else { continue };
        let grabber = FfmpegFrameGrabber {
            ffmpeg: Some(ffmpeg.clone()),
            ffprobe: None,
            timeout: std::time::Duration::from_secs(30),
        };
        let out = tempfile::tempdir()?;
        let frames = grabber
            .grab(
                &media().join(file),
                container,
                from,
                spacing,
                count,
                out.path(),
            )
            .await;
        let expected = case["frames"].as_array().unwrap();
        assert_eq!(frames.len(), expected.len(), "grab {index}");
        for (frame, want) in frames.iter().zip(expected) {
            let (_, stddev) = luma_measure(frame).expect("a decodable frame");
            let want = want["measure"]["stddev"].as_f64().unwrap();
            // Blank frames stay blank and detailed ones detailed, whatever the encoder version.
            assert_eq!(
                stddev < 12.0,
                want < 12.0,
                "grab {index}: {stddev} vs {want}"
            );
        }
    }
    Ok(())
}
