//! Reads an anime title from a release name. Port of `AnimeProcessor::extractTitleEpisode`, which
//! `anime:refresh` repeats.

use std::sync::LazyLock;

use nntmux_core::pcre::Pcre;
use regex::bytes::Regex as BytesRegex;
use unicode_normalization::UnicodeNormalization;

use crate::php::{bytes_to_string, trim};

fn pcre(pattern: &str) -> Pcre {
    Pcre::new(pattern).expect("a valid regex")
}

macro_rules! patterns {
    ($($name:ident = $pattern:expr;)*) => {
        $(static $name: LazyLock<Pcre> = LazyLock::new(|| pcre($pattern));)*
    };
}

const LANGUAGES: &str =
    "ENG|JAP|JPN|SUB|DUB|MULTI|RAW|HARDSUB|SOFTSUB|HARDDUB|SOFTDUB|ITA|SPA|FRE|GER|RUS|CHI|KOR";

patterns! {
    BROKEN_1 = r"/Ã¢Â[_\sÂ]*/u";
    BROKEN_2 = r"/Ã[¢Â©€£]/u";
    BROKEN_3 = r"/Â+/u";
    BROKEN_4 = r"/Ã[^\s]*/u";
    CONTROL = r"/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u";
    STRAY = r"/[\xC0-\xC1\xC2-\xC5]/u";
    SPACES = r"/\s+/";
    GROUP_TAGS = r"/^(?:\[[^\]]+\]\s*)+/";
    BRACKETS = r"/\[[^\]]+\]/";
    PARENS = r"/\([^)]+\)/";
    METADATA = r"/\b(JAV|Uncensored|Censored|Mosaic|Mosaic-less|HD|SD|FHD|UHD)\b/i";
    SIX_DIGITS = r"/\b\d{6}\b/";
    TRAILING_NUMBER_1 = r"/[-_]\s*\d{1,4}\s*$/i";
    TRAILING_NUMBER_2 = r"/\s+\d{1,4}\s*$/i";
    SEASON_X = r"/\s*-\s*\d+x\d+.*$/i";
    SEASON_E = r"/\s+S\d+E\d+.*$/i";
    DASH_NUMBER = r"/\s*-\s*\d{1,4}(?:\s*-\s*.*)?\s*$/i";
    TRAILING_DASH = r"/\s*-\s*$/i";
    EPISODE_WORD = r"/\s+E(?:p(?:isode)?)?\s*0*\d{1,4}\s*$/i";
    QUALITY = r"/\b(480p|720p|1080p|2160p|4K|BD|BDRip|BluRay|Blu-Ray|HEVC|x264|x265|H264|H265|WEB|WEBRip|DVDRip|TVRip)\b/i";
    RELEASE_TAGS = r"/\b(PROPER|REPACK|RIP|ISO|CRACK|BETA|ALPHA|FINAL|COMPLETE|FULL)\b/i";
    VOLUME = r"/\s+Vol\.?\s*\d*\s*$/i";
    CHAPTER = r"/\s+Ch\.?\s*\d*\s*$/i";
    TRAILING_SEPARATOR = r"/\s*[-_]\s*$/";
}

static LANGUAGE_BRACKETS: LazyLock<Pcre> =
    LazyLock::new(|| pcre(&format!(r"/\[(?:{LANGUAGES})\]/i")));
static LANGUAGE_PARENS: LazyLock<Pcre> =
    LazyLock::new(|| pcre(&format!(r"/\((?:{LANGUAGES})\)/i")));
static LANGUAGE_WORDS: LazyLock<Pcre> = LazyLock::new(|| pcre(&format!(r"/\b({LANGUAGES})\b/i")));

/// Where a title ends, in the order PHP tries them. Byte-mode, for `PREG_OFFSET_CAPTURE`.
static TITLE_ENDS: LazyLock<Vec<BytesRegex>> = LazyLock::new(|| {
    [
        r"(?i-u)\sS\d+E\d+",
        r"(?i-u)\s\d+x\d+",
        r"(?-u)\s-\s*(\d{1,3})\b",
        r"(?i-u)\sE(?:p(?:isode)?)?\s*0*(\d{1,3})\b",
        r"(?i-u)\b(Movie|OVA|Complete Series|Complete|Full Series)\b",
        r"(?i-u)\[(?:BD|BDRip|BluRay|Blu-Ray|\d{3,4}[ipx]|HEVC|x264|x265|H264|H265)\]",
    ]
    .iter()
    .map(|pattern| BytesRegex::new(pattern).expect("a valid regex"))
    .collect()
});

fn apply(text: &[u8], pattern: &Pcre, replacement: &[u8]) -> Vec<u8> {
    pattern.replace_all(text, replacement)
}

/// `fixEncoding`: removes the artifacts double-encoded UTF-8 leaves behind.
fn fix_encoding(text: &str) -> String {
    let mut bytes = text.as_bytes().to_vec();
    for pattern in [&BROKEN_1, &BROKEN_2, &BROKEN_3, &BROKEN_4, &CONTROL] {
        bytes = apply(&bytes, pattern, b"");
    }
    let normalized: String = bytes_to_string(bytes).nfc().collect();
    bytes_to_string(apply(normalized.as_bytes(), &STRAY, b""))
}

fn clean_title(title: &str) -> String {
    let fixed = fix_encoding(title);
    let mut bytes = fixed.into_bytes();
    let steps: [(&Pcre, &[u8]); 18] = [
        (&BRACKETS, b" "),
        (&PARENS, b" "),
        (&LANGUAGE_WORDS, b" "),
        (&METADATA, b" "),
        (&SIX_DIGITS, b" "),
        (&TRAILING_NUMBER_1, b""),
        (&TRAILING_NUMBER_2, b""),
        (&SEASON_X, b""),
        (&SEASON_E, b""),
        (&DASH_NUMBER, b""),
        (&TRAILING_DASH, b""),
        (&EPISODE_WORD, b""),
        (&QUALITY, b" "),
        (&RELEASE_TAGS, b" "),
        (&VOLUME, b""),
        (&CHAPTER, b""),
        (&TRAILING_SEPARATOR, b""),
        (&SPACES, b" "),
    ];
    for (pattern, replacement) in steps {
        bytes = apply(&bytes, pattern, replacement);
    }
    trim(&bytes_to_string(bytes)).to_owned()
}

/// The title in a release name. `None` when nothing is left of it.
pub fn extract_title(name: &str) -> Option<String> {
    let fixed = fix_encoding(name).replace(['_', '.'], " ");
    let bytes = apply(fixed.as_bytes(), &SPACES, b" ");
    let text = bytes_to_string(bytes);
    let bytes = apply(trim(&text).as_bytes(), &GROUP_TAGS, b"");
    let text = bytes_to_string(bytes);
    let bytes = apply(trim(&text).as_bytes(), &LANGUAGE_BRACKETS, b" ");
    let bytes = apply(&bytes, &LANGUAGE_PARENS, b" ");
    let end = TITLE_ENDS
        .iter()
        .find_map(|pattern| pattern.find(&bytes))
        .map_or(bytes.len(), |found| found.start());
    let title = clean_title(&String::from_utf8_lossy(&bytes[..end]));
    (!title.is_empty()).then_some(title)
}
