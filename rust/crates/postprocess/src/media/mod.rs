//! Preview images for a release: a frame from its video, or a sample picture it posted. Port of
//! `MediaExtractionService::getSample`, `getVideo`, `getJPGSample` and `isUsableImage`, and the
//! services under `AdditionalProcessing/Media` they use.

pub mod container;
pub mod frames;
pub mod images;
pub mod slicer;
pub mod splicer;

use std::path::{Path, PathBuf};

use anyhow::Result;
use nntmux_metadata::images::{Images, Profile};
use rand::Rng;
use sqlx::MySqlPool;

use container::MediaSourceHints;
use frames::{FrameQualityScorer, ThumbnailFrameSelector, VideoFrameGrabber};

/// The settings the preview steps read.
#[derive(Clone, Copy, Debug)]
pub struct PreviewSettings {
    /// `processThumbnails`: on in the settings, with ffmpeg configured.
    pub thumbnails: bool,
    pub frame_attempts: i64,
    pub scorer: FrameQualityScorer,
}

impl PreviewSettings {
    pub fn from_config(config: &crate::config::Config) -> Self {
        Self {
            thumbnails: config.planner.process_thumbnails,
            frame_attempts: config.media.thumbnail_frame_attempts,
            scorer: FrameQualityScorer {
                min_stddev: config.media.thumbnail_min_stddev,
                min_luma: config.media.thumbnail_min_luma,
                max_luma: config.media.thumbnail_max_luma,
            },
        }
    }
}

/// Removes a frame work directory when dropped.
struct WorkDirectory(PathBuf);

impl Drop for WorkDirectory {
    fn drop(&mut self) {
        let _ = std::fs::remove_dir_all(&self.0);
    }
}

/// The preview steps for one worker.
pub struct Previews<'a> {
    pub settings: PreviewSettings,
    pub grabber: &'a dyn VideoFrameGrabber,
    pub images: &'a Images,
}

impl Previews<'_> {
    /// Where preview frames are published (`covers/preview`).
    pub fn preview_directory(&self) -> PathBuf {
        self.images.covers.join("preview")
    }

    /// Where sample pictures are published (`covers/sample`).
    pub fn sample_directory(&self) -> PathBuf {
        self.images.covers.join("sample")
    }

    /// `getSample`: publish the best frame of a video as `<guid>_thumb`, skipping blank frames.
    pub async fn get_sample(
        &self,
        file: &Path,
        tmp_path: &Path,
        guid: &str,
        hints: Option<&MediaSourceHints>,
    ) -> bool {
        if !self.settings.thumbnails || !file.is_file() {
            return false;
        }
        let work = tmp_path.join(format!(
            "frames_{}",
            hex::encode(rand::rng().random::<[u8; 6]>())
        ));
        // Removed however the step ends, including when a stage timeout drops it.
        let _cleanup = WorkDirectory(work.clone());
        let saved = self.save_frame(file, &work, guid, hints).await;
        // PHP logged the failure only in debug mode.
        saved.unwrap_or(false)
    }

    async fn save_frame(
        &self,
        file: &Path,
        work: &Path,
        guid: &str,
        hints: Option<&MediaSourceHints>,
    ) -> Result<bool> {
        tokio::fs::create_dir_all(work).await?;
        let selector = ThumbnailFrameSelector {
            attempts: self.settings.frame_attempts,
            grabber: self.grabber,
            scorer: self.settings.scorer,
        };
        let Some(frame) = selector.select(file, work, hints).await? else {
            return Ok(false);
        };
        let images = self.images.clone();
        let name = format!("{guid}_thumb");
        let directory = self.preview_directory();
        Ok(tokio::task::spawn_blocking(move || {
            images.save_local(&name, &frame, &directory, Profile::Preview)
        })
        .await?)
    }

    /// `getVideo`. PHP built its clip with php-ffmpeg's `Dimension(320, -1)`, whose constructor
    /// rejects a negative height, so the clip was never encoded and the step always reported no
    /// video, leaving `videostatus` alone. The port keeps that result without running ffmpeg.
    pub fn get_video(&self) -> bool {
        false
    }

    /// `getJPGSample`: publish a sample picture as `<guid>_thumb` and mark the release.
    pub async fn get_jpg_sample(&self, pool: &MySqlPool, file: &Path, guid: &str) -> Result<bool> {
        let images = self.images.clone();
        let name = format!("{guid}_thumb");
        let source = file.to_owned();
        let directory = self.sample_directory();
        let saved = tokio::task::spawn_blocking(move || {
            images.save_local(&name, &source, &directory, Profile::Sample)
        })
        .await?;
        if !saved {
            return Ok(false);
        }
        sqlx::query("UPDATE releases SET jpgstatus = 1 WHERE guid = ?")
            .bind(guid)
            .execute(pool)
            .await?;
        Ok(true)
    }

    /// `isUsableImage`: complete, big enough to show something, and not blank.
    pub async fn is_usable_image(&self, file: &Path) -> bool {
        let file = file.to_owned();
        let min_stddev = self.settings.scorer.min_stddev;
        tokio::task::spawn_blocking(move || file.is_file() && images::is_usable(&file, min_stddev))
            .await
            .unwrap_or(false)
    }
}
