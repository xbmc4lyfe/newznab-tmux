//! The preview steps with scripted ffmpeg and ffprobe, against a real database for samples.

use std::os::unix::fs::PermissionsExt;
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant};

use anyhow::Result;
use nntmux_metadata::images::Images;
use nntmux_postprocess::media::container::{MediaSourceHints, VideoContainer};
use nntmux_postprocess::media::frames::{
    FfmpegFrameGrabber, FrameQualityScorer, Probe, VideoFrameGrabber,
};
use nntmux_postprocess::media::{PreviewSettings, Previews};
use nntmux_testkit::TestDb;

fn media() -> PathBuf {
    Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/media")
}

fn script(directory: &Path, name: &str, body: &str) -> Result<PathBuf> {
    let path = directory.join(name);
    std::fs::write(&path, format!("#!/bin/sh\n{body}"))?;
    std::fs::set_permissions(&path, std::fs::Permissions::from_mode(0o755))?;
    Ok(path)
}

/// A stand-in ffmpeg that writes the given fixture frames under the requested pattern, and
/// records its arguments.
fn scripted_ffmpeg(directory: &Path, frames: &[&str]) -> Result<PathBuf> {
    let mut body =
        String::from("printf '%s\\n' \"$@\" >> \"$0.args\"\nfor last; do :; done\nn=1\n");
    for frame in frames {
        body.push_str(&format!(
            "cp '{}' \"$(printf \"$last\" $n)\"\nn=$((n+1))\n",
            media().join(frame).display()
        ));
    }
    script(directory, "ffmpeg", &body)
}

fn settings(thumbnails: bool) -> PreviewSettings {
    PreviewSettings {
        thumbnails,
        frame_attempts: 4,
        scorer: FrameQualityScorer {
            min_stddev: 12.0,
            min_luma: 24.0,
            max_luma: 232.0,
        },
    }
}

fn images(covers: &Path) -> Result<Images> {
    let mut images = Images::from_env()?;
    images.covers = covers.to_owned();
    Ok(images)
}

#[tokio::test]
async fn a_preview_frame_is_chosen_and_published() -> Result<()> {
    let tools = tempfile::tempdir()?;
    let covers = tempfile::tempdir()?;
    let tmp = tempfile::tempdir()?;
    let ffprobe = script(
        tools.path(),
        "ffprobe",
        "echo '{\"format\":{\"duration\":\"100\"},\"streams\":[{\"codec_type\":\"video\"}]}'\n",
    )?;
    let ffmpeg = scripted_ffmpeg(tools.path(), &["black.jpg", "detail.jpg", "white.jpg"])?;
    let grabber = FfmpegFrameGrabber {
        ffmpeg: Some(ffmpeg.clone()),
        ffprobe: Some(ffprobe),
        timeout: Duration::from_secs(10),
    };
    let images = images(covers.path())?;
    let previews = Previews {
        settings: settings(true),
        grabber: &grabber,
        images: &images,
    };
    let video = media().join("clip.mkv");
    // A one-file post is previewed like any other.
    assert!(
        previews
            .get_sample(
                &video,
                tmp.path(),
                "abc123",
                Some(&MediaSourceHints::complete())
            )
            .await
    );
    let published = covers.path().join("preview/abc123_thumb.webp");
    let (kind, width, height) =
        nntmux_metadata::images::image_size(&published).expect("a published image");
    assert_eq!(
        (kind, width, height),
        (nntmux_metadata::images::ImageKind::Webp, 320, 180)
    );
    // The first window starts a quarter of the way in, with the demuxer named.
    let arguments = std::fs::read_to_string(ffmpeg.with_extension("args"))?;
    assert!(arguments.contains("-f\nmatroska\n"), "{arguments}");
    assert!(arguments.contains("gte(t,25.000)"), "{arguments}");
    // The work directory is gone.
    assert_eq!(std::fs::read_dir(tmp.path())?.count(), 0);

    // Thumbnails off, a missing file, or only blank frames: nothing.
    let off = Previews {
        settings: settings(false),
        grabber: &grabber,
        images: &images,
    };
    assert!(!off.get_sample(&video, tmp.path(), "off", None).await);
    assert!(
        !previews
            .get_sample(&media().join("missing.mkv"), tmp.path(), "gone", None)
            .await
    );
    let blank = scripted_ffmpeg(tools.path(), &["black.jpg", "grey.jpg"])?;
    let blank_grabber = FfmpegFrameGrabber {
        ffmpeg: Some(blank),
        ..grabber.clone()
    };
    let blank_previews = Previews {
        settings: settings(true),
        grabber: &blank_grabber,
        images: &images,
    };
    assert!(
        !blank_previews
            .get_sample(&video, tmp.path(), "blank", None)
            .await
    );
    assert!(!covers.path().join("preview/blank_thumb.webp").exists());
    assert_eq!(std::fs::read_dir(tmp.path())?.count(), 0);

    // A playlist is never handed to ffmpeg.
    let playlist = tmp.path().join("list.mkv");
    std::fs::write(&playlist, "#EXTM3U\nhttp://example.invalid/a.ts\n")?;
    std::fs::remove_file(
        blank_grabber
            .ffmpeg
            .as_ref()
            .unwrap()
            .with_extension("args"),
    )
    .ok();
    assert!(
        !blank_previews
            .get_sample(&playlist, tmp.path(), "list", None)
            .await
    );
    assert!(
        !blank_grabber
            .ffmpeg
            .as_ref()
            .unwrap()
            .with_extension("args")
            .exists()
    );
    // Video clips were never made by PHP either.
    assert!(!previews.get_video());
    Ok(())
}

#[tokio::test]
async fn ffprobe_failures_and_hangs_give_no_probe() -> Result<()> {
    let tools = tempfile::tempdir()?;
    let failing = script(
        tools.path(),
        "ffprobe",
        "echo '{\"format\":{\"duration\":\"5\"}}'\nexit 1\n",
    )?;
    let grabber = FfmpegFrameGrabber {
        ffmpeg: None,
        ffprobe: Some(failing),
        timeout: Duration::from_secs(10),
    };
    let clip = media().join("clip.mkv");
    assert_eq!(grabber.probe(&clip, None).await?, Probe::default());

    let hanging = script(tools.path(), "slow", "exec sleep 30\n")?;
    let grabber = FfmpegFrameGrabber {
        ffmpeg: Some(hanging.clone()),
        ffprobe: Some(hanging),
        timeout: Duration::from_millis(300),
    };
    let started = Instant::now();
    assert_eq!(
        grabber.probe(&clip, Some(VideoContainer::Matroska)).await?,
        Probe::default()
    );
    let out = tempfile::tempdir()?;
    assert!(
        grabber
            .grab(&clip, None, 0.0, 0.0, 4, out.path())
            .await
            .is_empty()
    );
    assert!(started.elapsed() < Duration::from_secs(10));
    Ok(())
}

#[tokio::test]
async fn sample_pictures_are_checked_published_and_marked() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let covers = tempfile::tempdir()?;
    let images = images(covers.path())?;
    let grabber = FfmpegFrameGrabber {
        ffmpeg: None,
        ffprobe: None,
        timeout: Duration::from_secs(1),
    };
    let previews = Previews {
        settings: settings(true),
        grabber: &grabber,
        images: &images,
    };
    for guid in ["g1", "g2"] {
        sqlx::query(
            "INSERT INTO releases (name, searchname, totalpart, groups_id, fromname, size, \
             postdate, adddate, guid, leftguid, categories_id, jpgstatus) VALUES ('n', 's', 1, 1, \
             'f', 5000000, '2026-01-01 00:00:00', '2026-01-01 00:00:00', ?, 'g', 10, 0)",
        )
        .bind(guid)
        .execute(pool)
        .await?;
    }
    assert!(previews.is_usable_image(&media().join("edge150.jpg")).await);
    assert!(!previews.is_usable_image(&media().join("cut.jpg")).await);
    assert!(!previews.is_usable_image(&media().join("missing.jpg")).await);

    assert!(
        previews
            .get_jpg_sample(pool, &media().join("big.jpg"), "g1")
            .await?
    );
    let (_, width, height) =
        nntmux_metadata::images::image_size(&covers.path().join("sample/g1_thumb.webp"))
            .expect("a published sample");
    // Bounded to 650 by 650, keeping the ratio.
    assert_eq!((width, height), (650, 366));
    assert!(
        !previews
            .get_jpg_sample(pool, &media().join("text.jpg"), "g2")
            .await?
    );
    let statuses: Vec<(String, i64)> =
        sqlx::query_as("SELECT guid, CAST(jpgstatus AS SIGNED) FROM releases ORDER BY guid")
            .fetch_all(pool)
            .await?;
    assert_eq!(statuses, [("g1".to_owned(), 1), ("g2".to_owned(), 0)]);
    db.drop_database().await
}
