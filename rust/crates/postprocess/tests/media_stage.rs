//! The media-source stage against a scripted NNTP server, with recorded media steps.

#[allow(dead_code)]
mod support;

use std::path::Path;
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use nntmux_postprocess::additional::candidates::ClaimedRelease;
use nntmux_postprocess::additional::context::ReleaseContext;
use nntmux_postprocess::additional::media_source::MediaByteBudget;
use nntmux_postprocess::additional::media_stage::{
    MediaSourceStage, MediaSteps, StageEvent, StageSettings,
};
use nntmux_postprocess::additional::planner::{ArchiveCandidate, NzbFile, WorkPlan};
use nntmux_postprocess::archive_info::ArchiveInfo;
use nntmux_postprocess::media::container::{MediaSourceHints, SourcePosition};
use nntmux_postprocess::media::slicer::{self, SlicerSettings};
use sha2::{Digest, Sha256};
use support::{serve, service};

const SEGMENT: usize = 20_000;

fn fixture(name: &str) -> Vec<u8> {
    std::fs::read(
        Path::new(env!("CARGO_MANIFEST_DIR"))
            .join("tests/fixtures/media")
            .join(name),
    )
    .unwrap()
}

/// One article of a multi-part yEnc post.
fn part(name: &str, file: &[u8], number: usize) -> Vec<u8> {
    let begin = (number - 1) * SEGMENT;
    let data = &file[begin..file.len().min(begin + SEGMENT)];
    let single = nntmux_yenc::encode(data, name.as_bytes(), 128, false).unwrap();
    let body_start = single.windows(2).position(|w| w == b"\r\n").unwrap() + 2;
    let body_end = single.windows(7).rposition(|w| w == b"\r\n=yend").unwrap();
    let mut article = format!(
        "=ybegin part={number} line=128 size={} name={name}\r\n=ypart begin={} end={}\r\n",
        file.len(),
        begin + 1,
        begin + data.len()
    )
    .into_bytes();
    article.extend_from_slice(&single[body_start..body_end]);
    article.extend_from_slice(
        format!(
            "\r\n=yend size={} part={number} pcrc32={}",
            data.len(),
            nntmux_yenc::crc32(data)
        )
        .as_bytes(),
    );
    article
}

/// A posted file's NZB entry and its articles, as `prefix-N@x`.
fn post(prefix: &'static str, name: &str, file: &[u8]) -> (NzbFile, Vec<(&'static str, Vec<u8>)>) {
    let count = file.len().div_ceil(SEGMENT);
    let ids: Vec<&'static str> = (1..=count)
        .map(|number| &*Box::leak(format!("{prefix}-{number}@x").into_boxed_str()))
        .collect();
    let articles = ids
        .iter()
        .enumerate()
        .map(|(index, id)| (*id, part(name, file, index + 1)))
        .collect();
    let nzb = NzbFile {
        title: format!("\"{name}\" yEnc (1/{count})"),
        segments: ids.iter().map(|id| (*id).to_owned()).collect(),
        size: (file.len() as f64 * 1.05) as i64,
        filecount: 1,
        firstsegment: true,
    };
    (nzb, articles)
}

#[derive(Clone, Debug)]
struct Call {
    step: &'static str,
    sha256: String,
    len: usize,
    hints: Option<MediaSourceHints>,
}

/// Records what each step was given; samples succeed from the `succeed_from`-th call on.
#[derive(Default)]
struct Recorder {
    calls: Mutex<Vec<Call>>,
    succeed_from: Option<usize>,
}

impl Recorder {
    fn record(&self, step: &'static str, file: &Path, hints: Option<&MediaSourceHints>) -> usize {
        let data = std::fs::read(file).unwrap_or_default();
        let mut calls = self.calls.lock().unwrap();
        calls.push(Call {
            step,
            sha256: hex::encode(Sha256::digest(&data)),
            len: data.len(),
            hints: hints.copied(),
        });
        calls.iter().filter(|call| call.step == step).count()
    }

    fn calls(&self) -> Vec<Call> {
        self.calls.lock().unwrap().clone()
    }
}

#[async_trait]
impl MediaSteps for Recorder {
    async fn sample(&self, file: &Path, _: &Path, _: &str, hints: &MediaSourceHints) -> bool {
        let number = self.record("sample", file, Some(hints));
        self.succeed_from.is_some_and(|from| number >= from)
    }

    async fn media_info(&self, file: &Path, _: i64) -> bool {
        self.record("media_info", file, None);
        false
    }

    async fn video(&self, file: &Path, _: &Path, _: &str, hints: &MediaSourceHints) -> bool {
        self.record("video", file, Some(hints));
        false
    }
}

fn settings() -> StageSettings {
    StageSettings {
        process_thumbnails: true,
        stage_max_seconds: 30,
        main_video_fallback: true,
        mid_sources: true,
        max_bytes: 16_777_216,
        sample_max_bytes: 8_388_608,
        mid_bytes: 262_144,
        release_timeout_seconds: 120,
        ffmpeg_timeout: 30,
    }
}

fn claimed() -> ClaimedRelease {
    ClaimedRelease {
        id: 7,
        guid: "abc".to_owned(),
        name: "n".to_owned(),
        searchname: "s".to_owned(),
        size: 5_000_000,
        groups_id: 1,
        nfostatus: 0,
        fromname: None,
        completion: 100.0,
        categories_id: 2040,
        predb_id: 0,
        pp_timeout_count: 0,
        claim_token: None,
    }
}

fn context(tmp: &Path, nzb: Vec<NzbFile>, plan: WorkPlan) -> ReleaseContext {
    let mut context = ReleaseContext::new(claimed());
    context.tmp_path = tmp.to_owned();
    context.release_group_name = "alt.binaries.test".to_owned();
    context.nzb_source_digest = Some("digest".to_owned());
    context.nzb_contents = nzb;
    context.work_plan = Some(plan);
    context
}

fn sha(data: &[u8]) -> String {
    hex::encode(Sha256::digest(data))
}

#[tokio::test]
async fn a_one_file_post_is_previewed_from_its_head_then_its_middle() {
    // A single posted video, as one-file remux posts are.
    let video = fixture("clip.mkv").repeat(4);
    let (nzb, articles) = post("v", "Movie.2023.2160p.BluRay.REMUX.mkv", &video);
    assert!(nzb.segments.len() >= 4);
    let server = serve(articles).await;
    let mut downloads = service(server.port);
    downloads.begin_release_scope();
    let tmp = tempfile::tempdir().unwrap();
    let plan = WorkPlan {
        main_video_source_indexes: vec![0],
        ..WorkPlan::default()
    };
    let mut context = context(tmp.path(), vec![nzb.clone()], plan);
    // Frames from the opening are rejected, the middle gives one.
    let recorder = Recorder {
        succeed_from: Some(2),
        ..Recorder::default()
    };
    let stage = MediaSourceStage {
        settings: settings(),
        media: &recorder,
    };
    assert!(stage.has_work(&context));
    let events = stage.run(&mut context, &mut downloads).await;
    assert_eq!(
        events,
        [
            StageEvent::Source("video"),
            StageEvent::Source("mid"),
            StageEvent::SampleCreated
        ]
    );
    assert!(context.found_sample);
    let calls = recorder.calls();
    assert_eq!(calls.len(), 3, "{calls:?}");
    // The whole head, in order, with its real size estimated from the posted size.
    assert_eq!((calls[0].step, calls[0].len), ("sample", video.len()));
    assert_eq!(calls[0].sha256, sha(&video));
    let hints = calls[0].hints.unwrap();
    assert_eq!(hints.position, SourcePosition::Head);
    assert_eq!(
        hints.real_size,
        Some((nzb.size as f64 * 0.97).round() as i64)
    );
    assert_eq!(calls[1].step, "media_info");
    // The middle chunk continues the head's headers at its first cluster.
    assert_eq!(calls[2].step, "sample");
    assert_eq!(calls[2].hints.unwrap().position, SourcePosition::Mid);
    let cluster = b"\x1F\x43\xB6\x75";
    let head_end = video.windows(4).position(|w| w == cluster).unwrap();
    let mid_from = (nzb.segments.len() / 2) * SEGMENT;
    let mid = &video[mid_from..];
    let mid_start = mid.windows(4).position(|w| w == cluster).unwrap();
    let mut expected = video[..head_end].to_vec();
    expected.extend_from_slice(&mid[mid_start..]);
    assert_eq!(calls[2].sha256, sha(&expected));
    // The first article named a plain video, so it is a direct video witness.
    let witness = context
        .direct_video_witnesses
        .get(&sha(b"v-1@x"))
        .expect("a witness");
    assert_eq!(witness.name, "Movie.2023.2160p.BluRay.REMUX.mkv");
    assert_eq!(witness.size, video.len() as i64);
    // Every downloaded byte was counted, and the work directory is gone.
    let budget = context.media_budget.unwrap();
    assert!(budget.remaining() < 16_777_216 - video.len() as i64);
    assert_eq!(std::fs::read_dir(tmp.path()).unwrap().count(), 0);
}

#[tokio::test]
async fn a_stored_feature_is_continued_from_its_volume_and_spliced_from_a_later_one() {
    let stored = |number: u8| fixture(&format!("stored/movie.part{number}.rar"));
    let volumes = [stored(1), stored(2), stored(3)];
    let mut nzb = Vec::new();
    let mut articles = Vec::new();
    let mut candidates = Vec::new();
    for (index, (prefix, volume)) in ["p1", "p2", "p3"].iter().zip(&volumes).enumerate() {
        let name = format!("movie.part{}.rar", index + 1);
        let (file, posted) = post(prefix, &name, volume);
        candidates.push(ArchiveCandidate {
            title: file.title.clone(),
            message_ids: file.segments[..3.min(file.segments.len())].to_vec(),
            source_index: index,
            ..ArchiveCandidate::default()
        });
        nzb.push(file);
        articles.extend(posted);
    }
    let server = serve(articles).await;
    let mut downloads = service(server.port);
    downloads.begin_release_scope();
    let tmp = tempfile::tempdir().unwrap();
    let plan = WorkPlan {
        archive_candidates: candidates.clone(),
        ..WorkPlan::default()
    };
    let mut context = context(tmp.path(), nzb, plan);
    // The first three articles of the first volume were downloaded for its listing.
    let downloaded = volumes[0][..3 * SEGMENT].to_vec();
    let mut info = ArchiveInfo::from_data(Arc::new(downloaded.clone()), true, None).unwrap();
    let files = info.archive_file_list(true).unwrap().unwrap();
    let slicing = SlicerSettings {
        archive_slicing: true,
        process_thumbnails: true,
        sample_max_bytes: 8_388_608,
        main_video_min_bytes: 30_000,
    };
    slicer::register(
        &slicing,
        &mut context,
        &downloaded,
        &files,
        Some(&candidates[0]),
    );
    let names: Vec<_> = context
        .archived_media
        .iter()
        .map(|entry| {
            (
                String::from_utf8_lossy(&entry.name).into_owned(),
                entry.is_sample,
            )
        })
        .collect();
    assert_eq!(
        names,
        [
            ("Sample/movie-sample.mkv".to_owned(), true),
            ("Movie.2023.1080p.BluRay.x264-GRP.mkv".to_owned(), false)
        ]
    );
    let feature = context.archived_media[1].clone();

    let recorder = Recorder::default();
    let stage = MediaSourceStage {
        settings: settings(),
        media: &recorder,
    };
    let events = stage.run(&mut context, &mut downloads).await;
    assert_eq!(
        events,
        [
            StageEvent::Source("archive"),
            StageEvent::Source("archive"),
            // The sample isn't in the later volume; the feature is.
            StageEvent::Source("mid")
        ]
    );
    let calls = recorder.calls();
    let steps: Vec<_> = calls.iter().map(|call| call.step).collect();
    // A sample also gets a clip attempt; the feature doesn't.
    assert_eq!(
        steps,
        [
            "sample",
            "media_info",
            "video",
            "sample",
            "media_info",
            "sample"
        ]
    );
    // The sample was complete in the listing's bytes.
    let sample = &context.archived_media[0];
    assert_eq!(calls[0].sha256, sha(&sample.head_bytes));
    assert_eq!(calls[0].hints.unwrap().position, SourcePosition::Complete);
    // The feature's head ran to the end of the downloaded bytes and was continued to the end
    // of the first volume's share of it.
    let end = usize::try_from(feature.in_volume_end).unwrap() + 1;
    let start = usize::try_from(feature.range_start).unwrap();
    assert_eq!(calls[3].sha256, sha(&volumes[0][start..end]));
    assert_eq!(calls[3].hints.unwrap().position, SourcePosition::Head);
    // The middle volume of the other two, by name, gave the feature's later bytes.
    let later = slicer::chunk_from_volume(&volumes[2], &feature.name).unwrap();
    let cluster = b"\x1F\x43\xB6\x75";
    let head = &volumes[0][start..end];
    let head_end = head.windows(4).position(|w| w == cluster).unwrap();
    let later_start = later.windows(4).position(|w| w == cluster).unwrap();
    let mut expected = head[..head_end].to_vec();
    expected.extend_from_slice(&later[later_start..]);
    assert_eq!(calls[5].sha256, sha(&expected));
    assert_eq!(calls[5].hints.unwrap().position, SourcePosition::Mid);
    assert_eq!(std::fs::read_dir(tmp.path()).unwrap().count(), 0);
}

#[tokio::test]
async fn gaps_unavailable_groups_and_spent_budgets_stop_the_downloads() {
    let video = fixture("clip.mkv").repeat(2);
    let (nzb, mut articles) = post("v", "Movie.mkv", &video);
    // The second article claims to start somewhere else.
    articles[1].1 = part("Movie.mkv", &video, 1);
    let server = serve(articles.clone()).await;
    let tmp = tempfile::tempdir().unwrap();
    let plan = WorkPlan {
        main_video_source_indexes: vec![0],
        ..WorkPlan::default()
    };
    let recorder = Recorder::default();
    let stage = MediaSourceStage {
        settings: StageSettings {
            mid_sources: false,
            ..settings()
        },
        media: &recorder,
    };
    let mut downloads = service(server.port);
    downloads.begin_release_scope();
    let mut gapped = context(tmp.path(), vec![nzb.clone()], plan.clone());
    stage.run(&mut gapped, &mut downloads).await;
    // Only the first article, never a file with a hole.
    assert_eq!(recorder.calls()[0].len, SEGMENT);

    // No budget for even one article: nothing is downloaded or tried.
    let quiet = Recorder::default();
    let stage = MediaSourceStage {
        settings: settings(),
        media: &quiet,
    };
    let mut broke = context(tmp.path(), vec![nzb.clone()], plan.clone());
    broke.media_budget = Some(MediaByteBudget::new(100));
    let before = server.bodies();
    assert!(stage.run(&mut broke, &mut downloads).await.is_empty());
    assert_eq!(server.bodies(), before);
    assert!(quiet.calls().is_empty());

    // Nothing to do once a sample exists, the release is passworded, or the stage is off.
    let mut done = context(tmp.path(), vec![nzb.clone()], plan.clone());
    done.found_sample = true;
    assert!(!stage.has_work(&done));
    let mut locked = context(tmp.path(), vec![nzb.clone()], plan.clone());
    locked.release_has_password = true;
    assert!(!stage.has_work(&locked));
    let off = MediaSourceStage {
        settings: StageSettings {
            stage_max_seconds: 0,
            ..settings()
        },
        media: &quiet,
    };
    assert!(!off.has_work(&context(tmp.path(), vec![nzb.clone()], plan.clone())));
    let no_fallback = MediaSourceStage {
        settings: StageSettings {
            main_video_fallback: false,
            ..settings()
        },
        media: &quiet,
    };
    assert!(!no_fallback.has_work(&context(tmp.path(), vec![nzb.clone()], plan.clone())));

    // A release already past its own timeout gets no downloads at all.
    let late = MediaSourceStage {
        settings: StageSettings {
            release_timeout_seconds: 10,
            ..settings()
        },
        media: &quiet,
    };
    let mut expired = context(tmp.path(), vec![nzb.clone()], plan.clone());
    let before = server.bodies();
    assert!(late.run(&mut expired, &mut downloads).await.is_empty());
    assert_eq!(server.bodies(), before);
    assert_eq!(std::fs::read_dir(tmp.path()).unwrap().count(), 0);
}
