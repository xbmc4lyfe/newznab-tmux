//! `FilePrioritizer`: the order release file names are tried in, and which ones are skipped.

use nntmux_categorize::pcre::{PHP_WS, trim_chars};
use nntmux_categorize::{mi, ms};

use crate::compat::{filename_from_path, php_extension, php_filename};

const VIDEO_EXTENSIONS: [&str; 8] = ["mkv", "avi", "mp4", "m4v", "wmv", "divx", "ts", "m2ts"];

const PRIORITY_MAIN_RAR: i32 = 2;
const PRIORITY_FIRST_PART_RAR: i32 = 3;
const PRIORITY_VIDEO: i32 = 4;
const PRIORITY_NFO: i32 = 5;
const PRIORITY_OTHER_RAR: i32 = 6;
const PRIORITY_SAMPLE: i32 = 100;
const PRIORITY_OTHER: i32 = 50;

#[derive(Default)]
struct Categories<'a> {
    srr: Vec<&'a str>,
    main_rar: Vec<&'a str>,
    first_part: Vec<&'a str>,
    video: Vec<&'a str>,
    nfo: Vec<&'a str>,
    other: Vec<&'a str>,
}

/// `prioritizeForMatching`: SRR/SRS, whole RARs (longest name first), first RAR parts, videos
/// (longest first), NFOs, the rest. Samples, proofs, subs, thumbs, `.url` files and DVD
/// structure files are dropped.
pub fn prioritize_for_matching<S: AsRef<str>>(files: &[S]) -> Vec<String> {
    let c = categorize(files);
    [c.srr, c.main_rar, c.first_part, c.video, c.nfo, c.other]
        .into_iter()
        .flatten()
        .map(str::to_owned)
        .collect()
}

/// `prioritizeForPreDb`: the same order without the NFOs.
pub fn prioritize_for_pre_db<S: AsRef<str>>(files: &[S]) -> Vec<String> {
    let c = categorize(files);
    [c.srr, c.main_rar, c.first_part, c.video, c.other]
        .into_iter()
        .flatten()
        .map(str::to_owned)
        .collect()
}

fn categorize<S: AsRef<str>>(files: &[S]) -> Categories<'_> {
    let mut c = Categories::default();
    for file in files {
        let file = file.as_ref();
        if should_ignore_for_name_matching(file) || is_sample_or_proof(file) {
            continue;
        }
        let lower = file.to_ascii_lowercase();
        if lower.ends_with(".srr") || lower.ends_with(".srs") {
            c.srr.push(file);
        } else if is_main_rar(&lower) {
            c.main_rar.push(file);
        } else if is_first_split_rar(&lower) {
            c.first_part.push(file);
        } else if is_video_file(&lower) {
            c.video.push(file);
        } else if lower.ends_with(".nfo") {
            c.nfo.push(file);
        } else {
            c.other.push(file);
        }
    }
    // usort is stable: equal lengths keep their order.
    c.video.sort_by_key(|name| std::cmp::Reverse(name.len()));
    c.main_rar.sort_by_key(|name| std::cmp::Reverse(name.len()));
    c
}

/// `getCrcPriority`: lower is tried first.
pub fn get_crc_priority(filename: &str) -> i32 {
    let lower = filename.to_ascii_lowercase();
    if is_sample_or_proof(filename) {
        PRIORITY_SAMPLE
    } else if is_video_file(&lower) {
        PRIORITY_VIDEO
    } else if is_main_rar(&lower) {
        PRIORITY_MAIN_RAR
    } else if is_first_split_rar(&lower) {
        PRIORITY_FIRST_PART_RAR
    } else if mi!(filename, r"\.(rar|r\d{2,3})$") {
        PRIORITY_OTHER_RAR
    } else if lower.ends_with(".nfo") {
        PRIORITY_NFO
    } else {
        PRIORITY_OTHER
    }
}

fn is_sample_or_proof(filename: &str) -> bool {
    mi!(filename, r"[\.\-_](sample|proof|subs?|thumbs?)[\.\-_]")
}

fn is_main_rar(lower: &str) -> bool {
    mi!(lower, r"\.rar$") && !mi!(lower, r"\.part\d+\.rar$")
}

fn is_first_split_rar(lower: &str) -> bool {
    mi!(lower, r"\.part0*1\.rar$")
}

fn is_video_file(lower: &str) -> bool {
    VIDEO_EXTENSIONS.contains(&php_extension(lower))
}

/// URL spam and DVD structure names (`VIDEO_TS`, `VTS_01_1`, bare numbers) carry no identity.
fn should_ignore_for_name_matching(filename: &str) -> bool {
    let base = filename_from_path(filename);
    if mi!(base, r"\.url$") {
        return true;
    }
    let stem = trim_chars(php_filename(base), &format!("{PHP_WS}.-_")).to_ascii_lowercase();
    mi!(stem, r"^(?:audio|video)[._-]?ts$")
        || mi!(stem, r"^vts[._-]?\d{1,2}[._-]?\d{1,2}$")
        || ms!(stem, r"^\d{1,3}$")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn matching_skips_url_and_dvd_structure_noise() {
        let prioritized = prioritize_for_matching(&[
            "Film ;-)/VIDEO_TS/VIDEO_TS.VOB",
            "Film ;-)/VIDEO_TS/VTS_01_1.VOB",
            "Film ;-)/1.jpg",
            "Film ;-)/cover the fisher king.jpg",
            "Film ;-)/Extreem Online Contact.url",
            "Film ;-)/release.nfo",
        ]);
        assert_eq!(
            prioritized,
            ["Film ;-)/release.nfo", "Film ;-)/cover the fisher king.jpg"]
        );
        assert!(
            prioritize_for_matching(&[
                "The Illusionist (2006)\\VIDEO_TS\\VIDEO_TS.BUP",
                "The Illusionist (2006)\\VIDEO_TS\\VTS_01_0.IFO",
            ])
            .is_empty()
        );
    }

    #[test]
    fn order_is_srr_rar_first_part_video_nfo_other() {
        let files = [
            "x.txt",
            "b.nfo",
            "a.mkv",
            "longer.mkv",
            "c.part01.rar",
            "d.rar",
            "ddd.rar",
            "e.srr",
            "f.part02.rar",
        ];
        assert_eq!(
            prioritize_for_matching(&files),
            [
                "e.srr",
                "ddd.rar",
                "d.rar",
                "c.part01.rar",
                "longer.mkv",
                "a.mkv",
                "b.nfo",
                "x.txt",
                "f.part02.rar"
            ]
        );
        assert_eq!(
            prioritize_for_pre_db(&files),
            [
                "e.srr",
                "ddd.rar",
                "d.rar",
                "c.part01.rar",
                "longer.mkv",
                "a.mkv",
                "x.txt",
                "f.part02.rar"
            ]
        );
    }

    #[test]
    fn crc_priority_orders_samples_last() {
        assert_eq!(get_crc_priority("a.sample.mkv"), 100);
        assert_eq!(get_crc_priority("a.mkv"), 4);
        assert_eq!(get_crc_priority("a.rar"), 2);
        assert_eq!(get_crc_priority("a.part01.rar"), 3);
        assert_eq!(get_crc_priority("a.part02.rar"), 6);
        assert_eq!(get_crc_priority("a.r01"), 6);
        assert_eq!(get_crc_priority("a.nfo"), 5);
        assert_eq!(get_crc_priority("a.txt"), 50);
    }
}
