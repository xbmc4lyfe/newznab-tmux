//! Settings for post-processing: `post_processing_configurations`, row 1, and the environment
//! keys `config/nntmux.php`, `nntmux_settings.php` and `nntmux_nntp.php` read.

use std::env;
use std::path::{Path, PathBuf};

use anyhow::Result;
use nntmux_core::{optional, parsed_or, var_or};
use sqlx::MySqlPool;

/// `post_processing_configurations`, row 1. A missing row means the app's defaults.
#[derive(Clone, Debug, PartialEq, Eq, sqlx::FromRow)]
pub struct Settings {
    #[sqlx(try_from = "u16")]
    pub post_threads: i64,
    #[sqlx(try_from = "u16")]
    pub post_threads_amazon: i64,
    #[sqlx(try_from = "u16")]
    pub post_threads_non: i64,
    #[sqlx(try_from = "u16")]
    pub nfo_threads: i64,
    #[sqlx(try_from = "u32")]
    pub timeout_seconds: i64,
    #[sqlx(try_from = "u32")]
    pub release_timeout_seconds: i64,
    #[sqlx(try_from = "u16")]
    pub max_timeout_count: i64,
    #[sqlx(try_from = "u32")]
    pub max_additional_processed: i64,
    #[sqlx(try_from = "u16")]
    pub max_parts_processed: i64,
    #[sqlx(try_from = "u16")]
    pub password_check_attempts: i64,
    #[sqlx(try_from = "u16")]
    pub max_nested_levels: i64,
    pub extract_using_rar_info: bool,
    #[sqlx(try_from = "u16")]
    pub segments_to_download: i64,
    #[sqlx(try_from = "u32")]
    pub ffmpeg_duration: i64,
    pub inner_file_blacklist: String,
    pub process_jpg: bool,
    pub process_thumbnails: bool,
    pub process_videos: bool,
    pub save_audio_preview: bool,
    #[sqlx(try_from = "u64")]
    pub min_size_to_post_process: i64,
    #[sqlx(try_from = "u64")]
    pub max_size_to_post_process: i64,
    #[sqlx(try_from = "u64")]
    pub min_size_to_process_nfo: i64,
    #[sqlx(try_from = "u64")]
    pub max_size_to_process_nfo: i64,
    #[sqlx(try_from = "u32")]
    pub max_nfo_processed: i64,
    #[sqlx(try_from = "u32")]
    pub max_nfo_retries: i64,
    pub lookup_nfo: bool,
    pub lookup_par2: bool,
}

impl Default for Settings {
    fn default() -> Self {
        Self {
            post_threads: 1,
            post_threads_amazon: 1,
            post_threads_non: 1,
            nfo_threads: 1,
            timeout_seconds: 60,
            release_timeout_seconds: 120,
            max_timeout_count: 3,
            max_additional_processed: 25,
            max_parts_processed: 3,
            password_check_attempts: 1,
            max_nested_levels: 3,
            extract_using_rar_info: false,
            segments_to_download: 2,
            ffmpeg_duration: 5,
            inner_file_blacklist: "/setup.exe|password.url/i".to_owned(),
            process_jpg: false,
            process_thumbnails: false,
            process_videos: false,
            save_audio_preview: false,
            min_size_to_post_process: 1_048_576,
            max_size_to_post_process: 107_374_182_400,
            min_size_to_process_nfo: 1_048_576,
            max_size_to_process_nfo: 107_374_182_400,
            max_nfo_processed: 100,
            max_nfo_retries: 5,
            lookup_nfo: true,
            lookup_par2: false,
        }
    }
}

impl Settings {
    pub async fn load(pool: &MySqlPool) -> Result<Self> {
        let row = sqlx::query_as("SELECT * FROM post_processing_configurations WHERE id = 1")
            .fetch_optional(pool)
            .await?;
        Ok(row.unwrap_or_default())
    }

    /// `AdditionalCandidateQuery::claimTtlSeconds`. Long enough that a live worker's claim
    /// doesn't look abandoned.
    pub fn claim_ttl_seconds(&self) -> i64 {
        let timeout = if self.release_timeout_seconds == 0 {
            120
        } else {
            self.release_timeout_seconds
        };
        (timeout * 2).max(300)
    }

    /// `maxAdditionalProcessed ?: 25`.
    pub fn query_limit(&self) -> i64 {
        if self.max_additional_processed == 0 {
            25
        } else {
            self.max_additional_processed
        }
    }
}

/// The environment half of `ProcessingConfiguration`, plus the database values it clamps.
#[derive(Clone, Debug)]
pub struct Config {
    pub settings: Settings,
    pub check_passworded_rars: bool,
    pub fetch_last_files: bool,
    pub add_par2_files: bool,
    pub unrar_path: Option<String>,
    pub unzip_path: Option<String>,
    pub seven_zip_path: Option<String>,
    pub timeout_path: Option<String>,
    pub ffmpeg_path: Option<String>,
    pub ffprobe_path: Option<String>,
    pub mediainfo_path: Option<String>,
    pub covers_path: PathBuf,
    pub tmp_unrar_path: PathBuf,
    pub rename_par2: bool,
    pub rename_music_mediainfo: bool,
    pub child_timeout_seconds: i64,
    pub worker_child_time_seconds: i64,
    pub archive_retry_delay_seconds: i64,
    pub planner: PlannerConfig,
    pub media: MediaConfig,
}

/// What the work planner reads.
#[derive(Clone, Debug, serde::Deserialize)]
pub struct PlannerConfig {
    pub process_thumbnails: bool,
    pub process_jpg_sample: bool,
    pub process_media_info: bool,
    pub process_audio_info: bool,
    pub segments_to_download: i64,
    pub maximum_rar_segments: i64,
    pub archive_probe_files: i64,
    pub par2_media_mapping: bool,
    pub par2_index_max_segments: i64,
    pub media_main_video_fallback: bool,
    pub media_main_video_min_bytes: i64,
    pub media_image_max_bytes: i64,
}

/// Preview-source limits.
#[derive(Clone, Debug)]
pub struct MediaConfig {
    pub process_video: bool,
    pub process_audio_sample: bool,
    pub process_passwords: bool,
    pub maximum_rar_password_checks: i64,
    pub season_pack_archive_sets: i64,
    pub season_pack_max_release_files: i64,
    pub par2_verify_files: i64,
    pub archive_slicing: bool,
    pub mid_sources: bool,
    pub max_bytes: i64,
    pub sample_max_bytes: i64,
    pub mid_bytes: i64,
    pub stage_max_seconds: i64,
    pub ffmpeg_timeout: i64,
    pub thumbnail_frame_attempts: i64,
    pub thumbnail_min_stddev: f64,
    pub thumbnail_min_luma: f64,
    pub thumbnail_max_luma: f64,
    pub max_pp_timeout_count: i64,
    pub release_timeout_seconds: i64,
}

impl Config {
    pub async fn load(pool: &MySqlPool) -> Result<Self> {
        Self::from_settings(Settings::load(pool).await?)
    }

    pub fn from_settings(settings: Settings) -> Result<Self> {
        let storage = PathBuf::from(var_or("NNTMUX_STORAGE_DIR", "/app/storage"));
        let ffmpeg_path = env_path("FFMPEG_PATH", "/usr/bin/ffmpeg");
        let ffprobe_path = resolve_ffprobe(
            env_path("FFPROBE_PATH", "/usr/bin/ffprobe"),
            ffmpeg_path.as_deref(),
        );
        let mediainfo_path = env_path("MEDIAINFO_PATH", "/usr/bin/mediainfo");
        let unrar_path = env_path("UNRAR_PATH", "/usr/bin/unrar");
        let default_seven_zip = if is_executable(Path::new("/usr/bin/7z")) {
            "/usr/bin/7z"
        } else {
            ""
        };
        let check_passworded_rars = env_true("CHECK_PASSWORDED_RARS");
        let has_ffmpeg = ffmpeg_path.is_some();
        let process_thumbnails = has_ffmpeg && settings.process_thumbnails;
        let process_media_info = mediainfo_path.is_some();
        let planner = PlannerConfig {
            process_thumbnails,
            process_jpg_sample: settings.process_jpg,
            process_media_info,
            process_audio_info: process_media_info,
            segments_to_download: or_when_zero(settings.segments_to_download, 2),
            maximum_rar_segments: or_when_zero(settings.max_parts_processed, 3),
            archive_probe_files: parsed_or::<i64>("NN_ARCHIVE_PROBE_FILES", 3)?.max(0),
            par2_media_mapping: env_bool("NN_PAR2_MEDIA_MAPPING", true),
            par2_index_max_segments: parsed_or::<i64>("NN_PAR2_INDEX_MAX_SEGMENTS", 2)?.max(1),
            media_main_video_fallback: env_bool("NN_MEDIA_MAIN_VIDEO_FALLBACK", true),
            media_main_video_min_bytes: parsed_or::<i64>(
                "NN_MEDIA_MAIN_VIDEO_MIN_BYTES",
                104_857_600,
            )?
            .max(0),
            media_image_max_bytes: parsed_or::<i64>("NN_MEDIA_IMAGE_MAX_BYTES", 8_388_608)?
                .max(1_048_576),
        };
        let media = MediaConfig {
            process_video: has_ffmpeg && settings.process_videos,
            process_audio_sample: has_ffmpeg && settings.save_audio_preview,
            process_passwords: check_passworded_rars && unrar_path.is_some(),
            maximum_rar_password_checks: or_when_zero(settings.password_check_attempts, 1).max(1),
            season_pack_archive_sets: parsed_or::<i64>("NN_SEASON_PACK_ARCHIVE_SETS", 3)?
                .clamp(1, 50),
            season_pack_max_release_files: parsed_or::<i64>(
                "NN_SEASON_PACK_MAX_RELEASE_FILES",
                30,
            )?
            .clamp(11, 200),
            par2_verify_files: parsed_or::<i64>("NN_PAR2_VERIFY_FILES", 2)?.max(0),
            archive_slicing: env_bool("NN_MEDIA_ARCHIVE_SLICING", true),
            mid_sources: env_bool("NN_MEDIA_MID_SOURCES", false),
            max_bytes: parsed_or::<i64>("NN_MEDIA_MAX_BYTES", 16_777_216)?.max(0),
            sample_max_bytes: parsed_or::<i64>("NN_MEDIA_SAMPLE_MAX_BYTES", 8_388_608)?
                .max(1_048_576),
            mid_bytes: parsed_or::<i64>("NN_MEDIA_MID_BYTES", 3_145_728)?.max(262_144),
            stage_max_seconds: parsed_or::<i64>("NN_MEDIA_STAGE_MAX_SECONDS", 30)?.max(0),
            ffmpeg_timeout: parsed_or::<i64>("NN_MEDIA_FFMPEG_TIMEOUT", 30)?.max(1),
            thumbnail_frame_attempts: parsed_or::<i64>("NN_THUMBNAIL_FRAME_ATTEMPTS", 4)?
                .clamp(1, 10),
            thumbnail_min_stddev: parsed_or("NN_THUMBNAIL_MIN_STDDEV", 12.0)?,
            thumbnail_min_luma: parsed_or("NN_THUMBNAIL_MIN_LUMA", 24.0)?,
            thumbnail_max_luma: parsed_or("NN_THUMBNAIL_MAX_LUMA", 232.0)?,
            max_pp_timeout_count: or_when_zero(settings.max_timeout_count, 3),
            release_timeout_seconds: or_when_zero(settings.release_timeout_seconds, 120),
        };
        let worker_child_time_seconds = parsed_or("NN_MULTIPROCESSING_MAX_CHILD_TIME", 1800)?;
        Ok(Self {
            check_passworded_rars,
            fetch_last_files: env_bool("FETCH_LAST_FILE", true),
            add_par2_files: env_bool("ADD_PAR2", false),
            unrar_path,
            unzip_path: env_path("UNZIP_PATH", "/usr/bin/unzip"),
            seven_zip_path: env_path("SEVENZIP_PATH", default_seven_zip),
            timeout_path: env_path("TIMEOUT_PATH", "/usr/bin/timeout"),
            ffmpeg_path,
            ffprobe_path,
            mediainfo_path,
            covers_path: optional("COVERS_PATH")
                .map_or_else(|| storage.join("covers"), PathBuf::from),
            tmp_unrar_path: optional("TEMP_UNRAR_PATH")
                .map_or_else(|| storage.join("tmp/unrar/"), PathBuf::from),
            rename_par2: env_bool("RENAME_PAR2", true),
            rename_music_mediainfo: env_bool("RENAME_MUSIC_MEDIAINFO", true),
            child_timeout_seconds: parsed_or("NN_CONCURRENCY_TIMEOUT", worker_child_time_seconds)?,
            worker_child_time_seconds,
            archive_retry_delay_seconds: parsed_or::<i64>("NN_ARCHIVE_RETRY_DELAY", 86_400)?.max(0),
            planner,
            media,
            settings,
        })
    }
}

fn or_when_zero(value: i64, default: i64) -> i64 {
    if value == 0 { default } else { value }
}

/// `(bool) env($key, $default)`: Laravel maps the keywords, then PHP's string truthiness applies,
/// so only an empty string and `0` are false among the rest.
pub fn env_bool(key: &str, default: bool) -> bool {
    match env::var(key) {
        Ok(value) => laravel_truthy(&value),
        Err(_) => default,
    }
}

fn laravel_truthy(value: &str) -> bool {
    !matches!(
        value.to_ascii_lowercase().as_str(),
        "" | "0" | "false" | "(false)" | "null" | "(null)" | "empty" | "(empty)"
    )
}

/// `config(...) === true` over `env($key, false)`, which only the `true` keyword satisfies.
fn env_true(key: &str) -> bool {
    env::var(key)
        .is_ok_and(|value| matches!(value.to_ascii_lowercase().as_str(), "true" | "(true)"))
}

/// A tool path with PHP's `?: false`: empty means the tool is off.
fn env_path(key: &str, default: &str) -> Option<String> {
    let value = var_or(key, default);
    (!value.is_empty()).then_some(value)
}

/// The configured ffprobe, or the one installed next to ffmpeg.
pub fn resolve_ffprobe(ffprobe: Option<String>, ffmpeg: Option<&str>) -> Option<String> {
    if ffprobe
        .as_deref()
        .is_some_and(|path| is_executable(Path::new(path)))
    {
        return ffprobe;
    }
    if let Some(ffmpeg) = ffmpeg {
        let sibling = Path::new(ffmpeg)
            .parent()
            .unwrap_or(Path::new("."))
            .join("ffprobe");
        if is_executable(&sibling) {
            return Some(sibling.to_string_lossy().into_owned());
        }
    }
    ffprobe
}

pub fn is_executable(path: &Path) -> bool {
    use std::os::unix::fs::PermissionsExt;
    path.metadata()
        .is_ok_and(|meta| meta.is_file() && meta.permissions().mode() & 0o111 != 0)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn php_truthiness_of_env_values() {
        for value in ["", "0", "false", "FALSE", "(false)", "null", "(empty)"] {
            assert!(!laravel_truthy(value), "{value}");
        }
        for value in ["1", "true", "yes", "off", "00"] {
            assert!(laravel_truthy(value), "{value}");
        }
    }

    #[test]
    fn claim_ttl_is_at_least_five_minutes_and_twice_the_release_timeout() {
        let mut settings = Settings::default();
        assert_eq!(settings.claim_ttl_seconds(), 300);
        settings.release_timeout_seconds = 0;
        assert_eq!(settings.claim_ttl_seconds(), 300);
        settings.release_timeout_seconds = 200;
        assert_eq!(settings.claim_ttl_seconds(), 400);
    }
}
