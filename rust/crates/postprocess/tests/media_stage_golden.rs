//! Stored-video slicing and container splicing against the PHP classes, through
//! `tests/fixtures/media_stage_golden.json` (see `generate_media_stage_golden.php`).

use std::path::{Path, PathBuf};
use std::sync::Arc;

use nntmux_postprocess::additional::candidates::ClaimedRelease;
use nntmux_postprocess::additional::context::ReleaseContext;
use nntmux_postprocess::additional::planner::ArchiveCandidate;
use nntmux_postprocess::archive_info::ArchiveInfo;
use nntmux_postprocess::media::container::VideoContainer;
use nntmux_postprocess::media::slicer::{self, ArchivedMediaEntry, SlicerSettings};
use nntmux_postprocess::media::splicer;
use serde_json::{Value, json};
use sha2::{Digest, Sha256};

fn fixtures() -> PathBuf {
    Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures")
}

fn golden() -> Value {
    let text = std::fs::read_to_string(fixtures().join("media_stage_golden.json")).expect("golden");
    serde_json::from_str(&text).expect("json")
}

fn claimed() -> ClaimedRelease {
    ClaimedRelease {
        id: 1,
        guid: "g".to_owned(),
        name: "n".to_owned(),
        searchname: "s".to_owned(),
        size: 5_000_000,
        groups_id: 1,
        nfostatus: 0,
        fromname: None,
        completion: 100.0,
        categories_id: 2000,
        predb_id: 0,
        pp_timeout_count: 0,
        claim_token: None,
    }
}

fn volume(name: &str) -> Vec<u8> {
    let stored = fixtures().join("media/stored");
    let part = |number: u8| std::fs::read(stored.join(format!("movie.part{number}.rar"))).unwrap();
    match name {
        "part1" => part(1),
        "part1 cut in the feature" => part(1)[..60_000].to_vec(),
        "part1 cut in the sample" => part(1)[..20_000].to_vec(),
        "part2" => part(2),
        "part3" => part(3),
        "garbage" => b"junk".repeat(100),
        other => panic!("unknown volume {other}"),
    }
}

fn digest(bytes: Option<&[u8]>) -> Value {
    match bytes {
        None => Value::Null,
        Some(bytes) => json!({"len": bytes.len(), "sha256": hex::encode(Sha256::digest(bytes))}),
    }
}

fn entry_json(entry: &ArchivedMediaEntry) -> Value {
    let continuation =
        [1, 20_000, 65_536, 1_048_576].map(|target| entry.continuation_bytes(target));
    json!({
        "name": hex::encode(&entry.name),
        "real_size": entry.real_size,
        "source_index": entry.source_index,
        "head": digest(Some(&entry.head_bytes)),
        "range_start": entry.range_start,
        "in_volume_end": entry.in_volume_end,
        "volume_bytes_downloaded": entry.volume_bytes_downloaded,
        "volume_segments_downloaded": entry.volume_segments_downloaded,
        "is_sample": entry.is_sample,
        "container": entry.container.map(VideoContainer::demuxer),
        "complete": entry.is_complete(),
        "continuation": continuation,
    })
}

#[test]
fn stored_videos_are_registered_as_in_php() {
    for case in golden()["registers"].as_array().expect("registers") {
        let data = volume(case["volume"].as_str().unwrap());
        let values = &case["values"];
        let settings = SlicerSettings {
            archive_slicing: values["mediaArchiveSlicing"].as_bool().unwrap(),
            process_thumbnails: values["processThumbnails"].as_bool().unwrap(),
            sample_max_bytes: values["mediaSampleMaxBytes"].as_i64().unwrap(),
            main_video_min_bytes: values["mediaMainVideoMinBytes"].as_i64().unwrap(),
        };
        let files = match ArchiveInfo::from_data(Arc::new(data.clone()), true, None) {
            Ok(mut info) if info.error().is_empty() => {
                info.archive_file_list(true).unwrap().unwrap_or_default()
            }
            _ => Vec::new(),
        };
        let source = ArchiveCandidate {
            title: "\"movie.part1.rar\" yEnc (1/3)".to_owned(),
            message_ids: vec!["a@x".into(), "b@x".into(), "c@x".into()],
            likely_first_volume: true,
            source_index: 4,
            ..ArchiveCandidate::default()
        };
        let mut context = ReleaseContext::new(claimed());
        slicer::register(&settings, &mut context, &data, &files, Some(&source));
        let entries: Vec<Value> = context.archived_media.iter().map(entry_json).collect();
        assert_eq!(
            Value::Array(entries),
            case["entries"],
            "{} / {}",
            case["volume"],
            case["settings"]
        );
        // Without a source volume there is nothing to register.
        let mut unsourced = ReleaseContext::new(claimed());
        slicer::register(&settings, &mut unsourced, &data, &files, None);
        assert!(unsourced.archived_media.is_empty());
    }
}

#[test]
fn chunks_are_read_from_later_volumes_as_in_php() {
    for case in golden()["chunks"].as_array().expect("chunks") {
        let data = volume(case["volume"].as_str().unwrap());
        let name = hex::decode(case["name"].as_str().unwrap()).unwrap();
        let chunk = slicer::chunk_from_volume(&data, &name);
        assert_eq!(
            digest(chunk.as_deref()),
            case["chunk"],
            "{} / {}",
            case["volume"],
            String::from_utf8_lossy(&name)
        );
    }
}

#[test]
fn archived_media_is_kept_as_in_php() {
    for sequence in golden()["remembered"].as_array().expect("remembered") {
        let mut context = ReleaseContext::new(claimed());
        for step in sequence.as_array().unwrap() {
            let name = step["name"].as_str().unwrap();
            let entry = ArchivedMediaEntry {
                name: name.as_bytes().to_vec(),
                real_size: 10,
                source_index: 0,
                head_bytes: b"h".to_vec(),
                range_start: 0,
                in_volume_end: 9,
                volume_bytes_downloaded: 1,
                volume_segments_downloaded: 1,
                is_sample: step["sample"].as_bool().unwrap(),
                container: None,
            };
            assert_eq!(
                json!(context.remember_archived_media(entry)),
                step["kept"],
                "{name}"
            );
            let after: Vec<String> = context
                .archived_media
                .iter()
                .map(|known| String::from_utf8_lossy(&known.name).into_owned())
                .collect();
            assert_eq!(json!(after), step["after"], "{name}");
        }
        context.reset();
        assert!(context.archived_media.is_empty());
    }
}

#[test]
fn chunks_are_spliced_as_in_php() {
    for case in golden()["splices"].as_array().expect("splices") {
        let container = VideoContainer::from_demuxer(case["container"].as_str().unwrap()).unwrap();
        let head = hex::decode(case["head"].as_str().unwrap()).unwrap();
        let mid = hex::decode(case["mid"].as_str().unwrap()).unwrap();
        let result = splicer::splice(&head, &mid, container).map(hex::encode);
        assert_eq!(json!(result), case["result"], "{case}");
    }
}
