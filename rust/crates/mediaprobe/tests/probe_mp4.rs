//! Reads `fixtures/mp4`, made by `fixtures/mp4/make_fixture.sh` with ffmpeg. `expected.json` is
//! ffprobe's reading, and every value here is checked against it.

use nntmux_mediaprobe::lang;
use nntmux_mediaprobe::mp4::{Mp4Probe, probe_prefix, probe_tail};
use nntmux_mediaprobe::summary::MediaSummary;

fn fixture(name: &str) -> Vec<u8> {
    std::fs::read(format!(
        "{}/tests/fixtures/mp4/{name}",
        env!("CARGO_MANIFEST_DIR")
    ))
    .unwrap()
}

fn agrees_with_ffprobe(summary: &MediaSummary) {
    let expected: serde_json::Value = serde_json::from_slice(&fixture("expected.json")).unwrap();
    let streams = expected["streams"].as_array().unwrap();
    let of = |kind: &str| -> Vec<&serde_json::Value> {
        streams.iter().filter(|s| s["codec_type"] == kind).collect()
    };
    let seconds: f64 = expected["format"]["duration"]
        .as_str()
        .unwrap()
        .parse()
        .unwrap();
    let duration = summary.duration_ms.unwrap() as f64 / 1000.0;
    assert!((duration - seconds).abs() < 0.1, "{duration} and {seconds}");
    assert_eq!(
        summary.title.as_deref(),
        expected["format"]["tags"]["title"].as_str()
    );

    let video = summary.video.as_ref().unwrap();
    let wanted = of("video")[0];
    assert_eq!(video.codec, "AVC");
    assert_eq!(wanted["codec_name"], "h264");
    assert_eq!(video.width, wanted["width"].as_u64());
    assert_eq!(video.height, wanted["height"].as_u64());

    let audio = of("audio");
    assert_eq!(summary.audio.len(), audio.len());
    for (read, wanted) in summary.audio.iter().zip(&audio) {
        let channels = wanted["channels"].as_u64().unwrap();
        let label = match channels {
            6 => "5.1",
            2 => "2.0",
            other => panic!("unexpected {other} channels"),
        };
        assert_eq!(read.channels.as_deref(), Some(label));
        assert_eq!(
            read.codec,
            match wanted["codec_name"].as_str().unwrap() {
                "aac" => "AAC",
                "ac3" => "AC-3",
                other => panic!("unexpected {other}"),
            }
        );
        // The project writes French as `fre` where ffmpeg writes `fra`.
        assert_eq!(
            read.language,
            lang::code(wanted["tags"]["language"].as_str().unwrap())
        );
    }

    let subtitles = of("subtitle");
    assert_eq!(summary.subtitles.len(), subtitles.len());
    assert_eq!(
        summary.subtitles[0].language,
        lang::code(subtitles[0]["tags"]["language"].as_str().unwrap())
    );
}

#[test]
fn reads_moov_at_the_front() {
    let bytes = fixture("front.mp4");
    let Mp4Probe::Read(summary) = probe_prefix(&bytes) else {
        panic!("expected a read");
    };
    agrees_with_ffprobe(&summary);
}

#[test]
fn asks_for_the_tail_and_reads_moov_from_it() {
    let bytes = fixture("tail.mp4");
    assert_eq!(probe_prefix(&bytes[..1024]), Mp4Probe::MoovInTail);
    // Starts partway through the media, as a post's last articles would.
    let tail = &bytes[bytes.len() / 2..];
    agrees_with_ffprobe(&probe_tail(tail).unwrap());
}

#[test]
fn asks_for_more_when_moov_is_cut_off() {
    let bytes = fixture("front.mp4");
    assert!(matches!(
        probe_prefix(&bytes[..256]),
        Mp4Probe::NeedPrefix { .. }
    ));
}

#[test]
fn truncated_input_never_panics() {
    let bytes = fixture("front.mp4");
    for end in (0..bytes.len()).step_by(97) {
        let _ = probe_prefix(&bytes[..end]);
        let _ = probe_tail(&bytes[..end]);
    }
}
