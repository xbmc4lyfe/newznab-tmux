//! `FileNameExtractor::extractFromFile`: a release name from a file name or path, trying the
//! patterns in order of specificity. The cheap literal checks run first, so a complete
//! scene-style basename is kept byte for byte instead of being re-derived from quality tokens.
//!
//! Four PHP branches can never return, and aren't ported; the golden test asserts PHP never
//! returns their methods:
//! - `R&C` and `tvp` end in `(?=\.ts)$` / `(?=\.(avi|mkv))$`, so `$` would have to hold at a
//!   position the lookahead has just shown is followed by an extension.
//! - `TVP` is the "Scene release with group" pattern with the group fixed to `TVP`, which that
//!   pattern has always matched first.
//! - `Scene RAR release` is the "RAR archive path" pattern with its optional parts empty and a
//!   narrower name and extension list, and it only runs after that one has failed.

use nntmux_categorize::names::unwrap_nzbsplit;
use nntmux_categorize::pcre::{PHP_WS, replace_all, trim_chars};
use nntmux_categorize::{mi, ms, rx};
use nntmux_predb::filename::{
    clean_for_title_match, is_literal_technical_scene_title, is_plausible_release_title,
    normalize_candidate_title, strip_file_suffixes,
};
use nntmux_predb::frx;

use crate::compat::{caps, filename_from_path, pcre_caps, php_basename, php_dirname, str_replace};
use crate::result::NameFixResult;
use crate::{pcre, rx_d};

fn found(name: impl Into<String>, method: &str) -> Option<NameFixResult> {
    Some(NameFixResult::from_match(name, method, "File"))
}

/// `FileNameExtractor::PREDB_REGEX`.
fn predb_regex() -> &'static nntmux_core::pcre::Pcre {
    pcre!(r"/([\w.'()\[\]-]+(?:[\s._-]+[\w.'()\[\]-]+)+[-.][\w]+)/ui")
}

/// Returns on the first of these that matches, with the given group as the name.
macro_rules! hit {
    ($subject:expr, $re:expr, $group:expr, $method:expr) => {
        if let Some(c) = caps($re, $subject) {
            return found(c.text_or_empty($group), $method);
        }
    };
}

/// A title usable as a video file's identity: ASCII-only characters, a year after a real
/// title (an uploader tag alone doesn't count), and a bounded resolution token.
fn plausible_year_title(candidate: &str) -> bool {
    if !ms!(candidate, r"^[A-Za-z0-9 ._()\[\]@+,!'&-]+$") {
        return false;
    }
    let Some(year) = pcre_caps(
        pcre!(r"/^(.+?)[ ._(\[](?:19|20)\d{2}(?=$|[ ._)\]-])/u"),
        candidate,
    ) else {
        return false;
    };
    let title = year.text_or_empty(1);
    ms!(
        replace_all(rx!(r"^(?:\[[^\]]+\]\s*)+"), &title, ""),
        r"[A-Za-z]{3}"
    ) && frx!(i r"(?<![a-z0-9])(?:720p|1080p|2160p)(?![a-z0-9])").is_match(candidate.as_bytes())
}

/// Extract a release name from a filename or path.
pub fn extract_from_file(filename: &str) -> Option<NameFixResult> {
    if filename.contains("__NZBSPLIT__") {
        return unwrap_nzbsplit(filename)
            .filter(|unwrapped| is_plausible_release_title(unwrapped))
            .and_then(|unwrapped| found(unwrapped, "NZBSPLIT wrapper"));
    }

    let basename = filename_from_path(filename);
    let literal_title = strip_file_suffixes(basename);
    if literal_title != basename
        && rx_d!(r"\.(?:mkv|avi|mp4|m4v|wmv|mpg|mpeg|mov|ts|m2ts|vob|divx|flv|webm|ogv|3gp|asf|rm|rmvb|f4v|mp3|flac|m4a|aac|ogg|wav|wma|ape|opus|mka|ac3|dts|eac3|truehd|mpc|shn|tak|tta|wv|rar|r\d{2,4}|zip|z\d{2}|7z|par2?|tar|gz|bz2|xz|lz|lzma|zst|tgz|lz4|rev|enc|\d{3})$").is_match(basename.as_bytes())
        && is_literal_technical_scene_title(&literal_title)
    {
        return found(literal_title, "Complete literal basename");
    }

    // Scene TV release with group suffix.
    hit!(
        filename,
        rx!(i r"^(.+?(x264|x265|HEVC|XviD|H\.?264|H\.?265)\-[A-Za-z0-9]+)\\"),
        1,
        "Scene release with group"
    );

    for (pattern, method) in [
        (
            pcre!(
                r"/^(\\|\/)?(.+(\\|\/))*(.+?S\d{1,3}[.-_ ]?E\d{1,3}(?:[.-_ ]?E\d{1,3})?[.-_ ].+?(?:720p|1080p|2160p|4K|HDTV|WEB-?DL|WEB-?RIP|BluRay|AMZN|HMAX|NF|DSNP).+?)\.(.+)$/iu"
            ),
            "TV SxxExx with quality",
        ),
        (
            pcre!(r"/^(\\|\/)?(.+(\\|\/))*(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+)\.(.+)$/iu"),
            "Generic TV",
        ),
        (
            pcre!(
                r"/^(\\|\/)?(.+(\\|\/))*(.+?[\.\-_ ](19|20)\d\d[\.\-_ ].+?(2160p|4K|UHD).+?(HDR10?\+?|DV|Dolby[\.\-_ ]?Vision)?.+?(HEVC|x265|H\.?265).+?)\.(.+)$/iu"
            ),
            "4K/UHD Movie",
        ),
        (
            pcre!(
                r"/^(\\|\/)?(.+(\\|\/))*(.+?[\.\-_ ](19|20)\d\d[\.\-_ ].+?(720p|1080p).+?(BluRay|WEB-?DL|WEB-?RIP|BDRip|REMUX).+?(x264|x265|HEVC|H\.?264|H\.?265|AVC).+?)\.(.+)$/iu"
            ),
            "HD Movie modern codec",
        ),
        (
            pcre!(
                r"/^(\\|\/)?(.+(\\|\/))*(.+?([\.\-_ ]\d{4}[\.\-_ ].+?(BDRip|bluray|DVDRip|XVID|WEB-?DL|HDTV)).+)\.(.+)$/iu"
            ),
            "Generic movie 1",
        ),
    ] {
        if let Some(c) = pcre_caps(pattern, filename) {
            return found(c.text_or_empty(4), method);
        }
    }

    hit!(
        filename,
        rx!(i r"^([a-z0-9\.\-_]+(19|20)\d\d[a-z0-9\.\-_]+[\.\-_ ](720p|1080p|2160p|4K|BDRip|bluray|DVDRip|x264|x265|XviD|HEVC)[a-z0-9\.\-_]+)\.[a-z]{2,}$"),
        1,
        "Generic movie 2"
    );
    hit!(
        filename,
        rx!(i r"^([A-Za-z0-9\.\-_]+[\.\-_ ](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|TVNZ|VUDU)[\.\-_ ].+?(WEB-?DL|WEB-?RIP).+?)\.(.+)$"),
        1,
        "Streaming service release"
    );

    // Music releases.
    hit!(
        filename,
        rx!(i r"(.+?([\.\-_ ](CD|FM)|[\.\-_ ]\dCD|CDR|FLAC|SAT|WEB).+?(19|20)\d\d.+?)\\.+"),
        1,
        "Generic music"
    );
    hit!(
        filename,
        rx!(i r"^(.+?(19|20)\d\d\-([a-z0-9]{3}|[a-z]{2,}|C4))\\"),
        1,
        "music groups"
    );
    hit!(
        filename,
        rx!(i r"^(.+?[\.\-_ ](FLAC|MP3|AAC|OGG)[\.\-_ ].+?[\.\-_ ]\d{4}[\.\-_ ].+?\-[A-Za-z0-9]+)[\\\/.]"),
        1,
        "Music with codec"
    );

    // Movie with the year in parentheses.
    if let Some(c) = caps(rx!(i r".+\\(.+\((19|20)\d\d\)\.avi)$"), filename) {
        let name = str_replace(&c.text_or_empty(1), ".avi", " DVDRip XVID NoGroup");
        return found(name, "Movie (year) avi");
    }
    if let Some(c) = caps(rx!(i r".+\\(.+\((19|20)\d\d\)\.iso)$"), filename) {
        let name = str_replace(&c.text_or_empty(1), ".iso", " DVD NoGroup");
        return found(name, "Movie (year) iso");
    }
    if let Some(c) = caps(rx!(i r".+\\(.+\((19|20)\d\d\)\.(mkv|mp4|m4v))$"), filename) {
        let name = replace_all(
            rx!(i r"\.(mkv|mp4|m4v)$"),
            &c.text_or_empty(1),
            " BDRip x264 NoGroup",
        );
        return found(name, "Movie (year) mkv/mp4");
    }

    // RAR file contents: the release name is the directory.
    if filename.contains(['\\', '/']) {
        hit!(
            filename,
            rx!(i r"^([A-Za-z0-9][\w.\-]+(?:[\.\-_ ][\w.\-]+)+)[\\\/](?:CD\d|Disc\d|DVD\d|Subs?)?[\\\/]?.+\.(rar|r\d{2,4}|zip|7z)$"),
            1,
            "RAR archive path"
        );
    }

    hit!(
        filename,
        rx!(i r"^(.+?IMAGESET.+?)\\.+"),
        1,
        "XXX Imagesets"
    );

    if let Some(c) = caps(
        rx!(
            r"^VIDEOOT-[A-Z0-9]+\\([\w!.,& ()\[\]'\`-]{8,}?\b.?)([\-_](proof|sample|thumbs?))*(\.part\d*(\.rar)?|\.rar|\.7z)?(\d{1,3}\.rev|\.vol.+?|\.mp4)"
        ),
        filename,
    ) {
        return found(
            format!("{} XXX DVDRIP XviD-VIDEOOT", c.text_or_empty(1)),
            "XXX XviD VIDEOOT",
        );
    }

    if let Some(c) = caps(rx!(i r"^.+?SDPORN"), filename) {
        return found(c.whole(), "XXX SDPORN");
    }

    hit!(
        filename,
        rx!(i r"\w[\-\w.',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -]nSD[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -]NhaNC3[\-\w.',;& ]+\w"),
        0,
        "NhaNc3"
    );
    hit!(
        filename,
        rx!(i r"\w[\-\w.',;& ]+\d{3,4}\.hdtv-lol\.(avi|mp4|mkv|ts|nfo|nzb)"),
        0,
        "Title.211.hdtv-lol.extension"
    );
    hit!(
        filename,
        rx!(i r"\w[\-\w.',;& ]+-S\d{1,2}[EX]\d{1,2}-XVID-DL\.avi"),
        0,
        "Title-SxxExx-XVID-DL.avi"
    );
    hit!(
        filename,
        rx!(i r"\S.*[\w.\-',;]+\s\-\ss\d{2}[ex]\d{2}\s\-\s[\w.\-',;].+\."),
        0,
        "Title - SxxExx - Eptitle"
    );
    if let Some(c) = caps(rx!(i r"\w.+?\)\.nds$"), filename) {
        return found(c.whole(), ").nds Nintendo DS");
    }
    if let Some(c) = caps(rx!(i r"3DS_\d{4}.+\d{4} - (.+?)\.3ds"), filename) {
        return found(format!("3DS {}", c.text_or_empty(1)), ".3ds Nintendo 3DS");
    }
    if let Some(c) = caps(rx!(i r"^(.+?)\[[\w]+\]\.(?:nsp|xci|nsz)$"), filename) {
        let title = c.text_or_empty(1);
        return found(
            format!("{} Switch", trim_chars(&title, PHP_WS)),
            "Nintendo Switch",
        );
    }
    hit!(
        filename,
        rx!(i r"^(.+?[\.\-_ ](PS[345P]|PSV|XBOX360|XBOXONE|NSW)[\.\-_ ].+?\-[A-Za-z0-9]+)[\\\/.]"),
        1,
        "Console game release"
    );

    // EBooks: `.epub` becomes ` (epub)`.
    if let Some(c) = caps(
        rx!(i r"\w.+?\.(epub|mobi|azw3?|opf|fb2|prc|djvu|cb[rz])"),
        filename,
    ) {
        let extension = c.text_or_empty(1);
        let name = str_replace(
            &c.text_or_empty(0),
            &format!(".{extension}"),
            &format!(" ({extension})"),
        );
        return found(name, "EBook");
    }
    hit!(
        filename,
        rx!(i r"^(.+?[\.\-_ ]Audiobook[\.\-_ ].+?)[\\\/.]"),
        1,
        "Audiobook"
    );

    // A scene release name once the file suffixes are off.
    let cleaned = clean_for_title_match(filename);
    if let Some(c) = caps(
        rx!(i r"^([A-Za-z0-9][\w.\-]+\-[A-Za-z0-9]{2,15})$"),
        &cleaned,
    ) && predb_regex().is_match(cleaned.as_bytes())
    {
        return found(c.text_or_empty(1), "Cleaned scene name");
    }

    let slashed = filename.replace('\\', "/");
    let basename = php_basename(&slashed);
    let candidate = strip_file_suffixes(basename);
    if candidate != basename
        && mi!(
            basename,
            r"\.(?:mkv|avi|mp4|m4v|ts|m2ts|rar|r\d{2,4}|7z|par2|nfo|sfv|md5|sha1)$"
        )
        && plausible_year_title(&candidate)
        && frx!(i r"(?<![a-z0-9])(?:HDTV(?:Light)?|BluRay|BDRip|WEB[- .]?DL|WEBRip|BD)(?![a-z0-9])")
            .is_match(candidate.as_bytes())
        && frx!(i r"(?<![a-z0-9])(?:x264|x265|HEVC|H[ .]?264|H[ .]?265|XviD)(?![a-z0-9])")
            .is_match(candidate.as_bytes())
        && is_plausible_release_title(&normalize_candidate_title(&candidate))
    {
        return found(candidate, "Technical video basename");
    }

    let parent = php_basename(php_dirname(&slashed));
    if parent == candidate
        && mi!(basename, r"\.(?:mkv|avi|mp4|m4v)$")
        && plausible_year_title(&candidate)
        && is_plausible_release_title(&normalize_candidate_title(&candidate))
    {
        return found(candidate, "Matching video parent basename");
    }

    // Folder name fallback.
    if let Some(c) = caps(rx!(i r"\w+[\-\w.',;& ]+$"), filename)
        && predb_regex().is_match(filename.as_bytes())
    {
        return found(c.whole(), "Folder name");
    }

    None
}

#[cfg(test)]
mod tests {
    use super::extract_from_file;

    fn name(filename: &str) -> Option<(String, String)> {
        extract_from_file(filename).map(|r| (r.new_name, r.method))
    }

    #[test]
    fn unwraps_nzbsplit_names_and_rejects_low_information_ones() {
        let found = extract_from_file(
            "NBA__NZBSPLIT__bdab31d6f79989608009e7e8eadcbe66__NZBSPLIT__NBA_20260419_PHI_BOS_1080p60_ABC.7z.073",
        )
        .expect("a name");
        assert_eq!(found.new_name, "NBA.20260419.PHI.BOS.1080p60.ABC");
        assert_eq!(found.method, "NZBSPLIT wrapper");
        assert_eq!(found.checker_name, "File");
        assert_eq!(
            name("TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__setup.7z.001"),
            None
        );
    }

    #[test]
    fn keeps_a_complete_technical_basename_byte_for_byte() {
        let title = "The.West.Wing.S07E01.The.Ticket.1080p.BluRay.10bit.EAC3.2.0.x265-iVy";
        for filename in [
            format!("{title}.mkv"),
            format!("D:\\Unsorted\\{title}.mkv"),
            format!("{title}.mkv.rar.par2"),
        ] {
            assert_eq!(
                name(&filename),
                Some((title.to_owned(), "Complete literal basename".to_owned())),
                "{filename}"
            );
        }
        let cafe = "Cafe\u{301}.Movie.2026.1080p.BluRay.x264-GROUP";
        assert_eq!(
            name(&format!("{cafe}.mkv")).map(|r| r.0).as_deref(),
            Some(cafe)
        );
    }

    #[test]
    fn requires_a_known_suffix_a_group_and_a_clean_title() {
        for filename in [
            "Some.Show.S01E03.1080p.BluRay.10bit.x265-GROUP.unknown",
            "Some.Show.S01E03.1080p.BluRay.10bit.x265-GROUP.txt",
            "setup.2026.1080p.x265-GROUP.rar",
            "e5a5b12627947bf5ac5b7e75cfa31d53.mkv",
        ] {
            assert_ne!(
                name(filename).map(|r| r.1).as_deref(),
                Some("Complete literal basename"),
                "{filename}"
            );
        }
    }

    #[test]
    fn archive_volumes_keep_the_title_and_the_observed_parent() {
        let title =
            "[pcela] Homefront (2013) - [BD 1080p][H264 8bit][DTS 5 1][MULTI][English+Polish]";
        for suffix in [".r00", ".r999", ".r1000", ".r9999"] {
            assert_eq!(
                name(&format!("{title}{suffix}")),
                Some((title.to_owned(), "Technical video basename".to_owned()))
            );
            assert_eq!(
                name(&format!("Canonical.Archive.Release-GRP/obfuscated{suffix}")),
                Some((
                    "Canonical.Archive.Release-GRP".to_owned(),
                    "RAR archive path".to_owned()
                ))
            );
        }
        for suffix in [".r9", ".r10000", ".r100a", ".r1000.exe"] {
            assert_ne!(
                name(&format!("{title}{suffix}")).map(|r| r.0).as_deref(),
                Some(title)
            );
        }
    }

    #[test]
    fn a_video_matching_its_folder_keeps_the_observed_title() {
        for path in [
            "Ordinary World (2016) 1080p\\Ordinary World (2016) 1080p.mkv",
            "Ordinary World (2016) 1080p/Ordinary World (2016) 1080p.mkv",
        ] {
            assert_eq!(
                name(path),
                Some((
                    "Ordinary World (2016) 1080p".to_owned(),
                    "Matching video parent basename".to_owned()
                ))
            );
        }
        for path in [
            "Other World (2016) 1080p/Ordinary World (2016) 1080p.mkv",
            "Ordinary World (2016)/Ordinary World (2016).mkv",
            "Ordinary World (2016) 1080p/Ordinary World (2016) 1080p.nfo",
            "Ordinary World (12016) 1080p/Ordinary World (12016) 1080p.mkv",
        ] {
            assert_ne!(
                name(path).map(|r| r.1).as_deref(),
                Some("Matching video parent basename"),
                "{path}"
            );
        }
    }

    #[test]
    fn a_trailing_newline_is_invisible_to_dollar() {
        assert_eq!(
            name("D:\\tv\\Show.Name.S01E02.720p.HDTV.x264-GRP.mkv\n"),
            Some((
                "Show.Name.S01E02.720p.HDTV.x264-GRP".to_owned(),
                "TV SxxExx with quality".to_owned()
            ))
        );
        assert_eq!(
            name("Movie.Name.2010.BDRip.XviD-GRP\n"),
            Some((
                "Movie.Name.2010.BDRip.XviD-GRP".to_owned(),
                "Cleaned scene name".to_owned()
            ))
        );
    }
}
