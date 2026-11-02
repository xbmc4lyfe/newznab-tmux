//! Probes `fixtures/mkv/sample.mkv`, made by `fixtures/mkv/make_fixture.sh` with ffmpeg, whose
//! ffprobe output is the reference: 3 s of 3840x1608 10-bit HEVC at 23.976 with PQ transfer,
//! English E-AC-3 5.1, an English AC-3 commentary, French forced and English SDH subtitles, and
//! three chapters.

use nntmux_mediaprobe::{Limits, Probe, probe_prefix};

fn sample() -> Vec<u8> {
    std::fs::read(format!(
        "{}/tests/fixtures/mkv/sample.mkv",
        env!("CARGO_MANIFEST_DIR")
    ))
    .unwrap()
}

fn read(buf: &[u8]) -> (Box<nntmux_mediaprobe::summary::MediaSummary>, bool) {
    match probe_prefix(buf, Limits::default()) {
        Probe::Read {
            summary,
            wants_more,
        } => (summary, wants_more),
        other => panic!("expected a read header, got {other:?}"),
    }
}

#[test]
fn reads_everything_from_the_whole_file() {
    let (summary, wants_more) = read(&sample());
    assert!(!wants_more);
    assert_eq!(summary.container, "matroska");
    // 72 frames at 24000/1001.
    assert_eq!(summary.duration_ms, Some(3003));
    assert_eq!(
        summary.title.as_deref(),
        Some("Sample Movie 2024 2160p UHD BluRay REMUX")
    );
    assert!(summary.segment_uid.as_ref().is_some_and(|u| u.len() == 32));

    let video = summary.video.as_ref().unwrap();
    assert_eq!(video.codec, "HEVC");
    assert_eq!((video.width, video.height), (Some(3840), Some(1608)));
    assert_eq!(video.resolution, Some("2160p"));
    assert_eq!(video.frame_rate.as_deref(), Some("23.976"));
    assert_eq!(video.hdr, vec!["HDR10"]);
    assert!(video.dovi.is_none());
    assert!(
        video
            .encoder
            .as_deref()
            .is_some_and(|e| e.starts_with("x265"))
    );

    assert_eq!(summary.audio.len(), 2);
    assert_eq!(summary.audio[0].label(), "E-AC-3 5.1");
    assert_eq!(summary.audio[0].language, "eng");
    assert!(!summary.audio[0].atmos);
    assert!(summary.audio[1].commentary);
    assert_eq!(summary.commentaries.len(), 1);
    assert_eq!(summary.commentaries[0].people, vec!["Jane Doe", "John Roe"]);

    assert_eq!(summary.subtitles.len(), 2);
    assert_eq!(summary.subtitles[0].language, "fre");
    assert!(summary.subtitles[0].forced);
    assert_eq!(summary.subtitles[1].language, "eng");
    assert!(summary.subtitles[1].sdh);
    assert_eq!(summary.subtitles[1].codec, "SRT");

    let names: Vec<_> = summary
        .chapters
        .iter()
        .map(|c| (c.start_ms, c.name.as_deref().unwrap_or(""), c.named))
        .collect();
    assert_eq!(
        names,
        vec![
            (0, "Opening Credits", true),
            (1000, "Chapter 02", false),
            (2000, "The Heist", true),
        ]
    );
    assert!(summary.chapter_signature.is_some());

    let attrs = summary.attrs();
    let get = |name: &str| {
        attrs
            .iter()
            .find(|(k, _)| *k == name)
            .map(|(_, v)| v.as_str())
    };
    assert_eq!(get("video"), Some("HEVC"));
    assert_eq!(get("resolution"), Some("3840x1608"));
    assert_eq!(get("framerate"), Some("23.976"));
    assert_eq!(get("audio"), Some("E-AC-3 5.1"));
    assert_eq!(get("language"), Some("English"));
    assert_eq!(get("subs"), Some("French, English"));
    assert_eq!(get("runtime"), Some("0"));
    assert_eq!(get("hdr"), Some("HDR10"));
    assert_eq!(get("chapters"), Some("3"));
    assert_eq!(get("commentary"), Some("1"));

    let tags = summary.tags();
    for expected in [
        "hevc",
        "2160p",
        "hdr10",
        "10bit",
        "eac3",
        "eng",
        "subs-fre",
        "subs-eng",
        "commentary",
        "namedchapters",
    ] {
        assert!(
            tags.contains(&expected.to_string()),
            "missing {expected} in {tags:?}"
        );
    }
}

#[test]
fn short_prefixes_ask_for_more() {
    let file = sample();
    // A few bytes: not enough for the EBML header and Segment.
    assert!(matches!(
        probe_prefix(&file[..16], Limits::default()),
        Probe::NeedHeader { .. }
    ));
    // Find the first prefix length where the header reads, then check it still wants frames.
    let mut first_read = None;
    for len in (64..file.len()).step_by(64) {
        if let Probe::Read { wants_more, .. } = probe_prefix(&file[..len], Limits::default()) {
            first_read = Some((len, wants_more));
            break;
        }
    }
    let (len, wants_more) = first_read.expect("header never read");
    assert!(len < 16 * 1024, "header needed {len} bytes");
    assert!(wants_more, "frames can't have been seen at {len} bytes");
}

#[test]
fn not_matroska() {
    assert_eq!(
        probe_prefix(b"Rar!\x1a\x07\x01\x00 not a video", Limits::default()),
        Probe::NotMatroska
    );
}
