//! `NfoService::isNFO`: whether downloaded bytes are an NFO.
//!
//! PHP asked libmagic (`finfo`, always present in PHP) what the bytes were, and took them only
//! when it answered with a text encoding, then checked the content. Here the libmagic answer is
//! computed in Rust: its encoding classification (`encoding.c`), the trailing NUL trim, and the
//! CSV and JSON checks that run before it and answer without an encoding. libmagic's other
//! content rules ("soft magic") are not ported. Most of them still end in an encoding ("HTML
//! document, ASCII text"), and the ones that would reject text here, such as PostScript, read as
//! text and go on to the content check.
//!
//! PHP's fallback for an empty libmagic answer (PAR2, SFV and control-character checks) never ran
//! in practice and is not ported.

use std::sync::LazyLock;

use nntmux_core::pcre::Pcre;

/// `NfoService::MAX_NFO_SIZE`: this size and larger isn't an NFO.
pub const MAX_NFO_SIZE: usize = 65_535;
/// `NfoService::MIN_NFO_SIZE`.
pub const MIN_NFO_SIZE: usize = 12;

static NON_NFO_HEADER: LazyLock<Pcre> = LazyLock::new(|| {
    Pcre::new(
        r"/\A(\s*<\?xml|=newz\[NZB\]=|RIFF|\s*[RP]AR|.{0,10}(JFIF|matroska|ftyp|ID3)|PK\x03\x04|\x1f\x8b\x08|MZ|%PDF|GIF8[79]a|\x89PNG)|;\s*Generated\s*by.*SF\w/i",
    )
    .expect("a valid regex")
});
static FIELD_VALUE: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/^[A-Za-z\s]{2,20}\s*[:\.]\s*.+$/m").expect("a valid regex"));
static URL: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/https?:\/\/|www\./i").expect("a valid regex"));
static MEDIA_ID: LazyLock<Pcre> = LazyLock::new(|| {
    Pcre::new(r"/imdb\.com|thetvdb\.com|themoviedb\.org|anidb\.net/i").expect("a valid regex")
});

/// `NfoService::$_nfoKeywords`.
const KEYWORDS: &[&str] = &[
    "release",
    "group",
    "date",
    "size",
    "format",
    "source",
    "genre",
    "codec",
    "bitrate",
    "resolution",
    "language",
    "subtitle",
    "ripped",
    "cracked",
    "keygen",
    "serial",
    "patch",
    "trainer",
    "install",
    "notes",
    "greets",
    "nfo",
    "ascii",
    "artwork",
    "presents",
    "proudly",
    "brings",
    "another",
    "scene",
    "rls",
    "nuked",
    "proper",
    "repack",
    "internal",
    "retail",
    "webdl",
    "webrip",
    "bluray",
    "bdrip",
    "dvdrip",
    "hdtv",
    "pdtv",
    "video",
    "audio",
    "duration",
    "runtime",
    "aspect",
    "fps",
    "channels",
    "sample",
    "encoder",
    "x264",
    "x265",
    "hevc",
    "avc",
    "xvid",
    "divx",
    "aac",
    "ac3",
    "dts",
    "truehd",
    "atmos",
    "flac",
    "mp3",
    "movie",
    "film",
    "episode",
    "season",
    "series",
    "title",
    "year",
    "director",
    "cast",
    "actors",
    "plot",
    "synopsis",
    "imdb",
    "rating",
    "crack",
    "readme",
    "setup",
    "installer",
    "license",
    "registration",
    "protection",
    "requirements",
    "platform",
    "operating",
    "system",
    "contact",
    "irc",
    "www",
    "http",
    "ftp",
    "email",
    "apply",
    "join",
];

/// `detectBinaryFormat`'s magic numbers.
const MAGIC: &[&[u8]] = &[
    b"\x50\x4B\x03\x04",
    b"\x50\x4B\x05\x06",
    b"\x52\x61\x72\x21",
    b"\x37\x7A\xBC\xAF",
    b"\x1F\x8B\x08",
    b"\x42\x5A\x68",
    b"\xFD\x37\x7A\x58",
    b"\x89\x50\x4E\x47",
    b"\xFF\xD8\xFF",
    b"\x47\x49\x46\x38",
    b"\x25\x50\x44\x46",
    b"\x49\x44\x33",
    b"\xFF\xFB",
    b"\x4F\x67\x67\x53",
    b"\x66\x4C\x61\x43",
    b"\x52\x49\x46\x46",
    b"\x00\x00\x01\xBA",
    b"\x00\x00\x01\xB3",
    b"\x1A\x45\xDF\xA3",
    b"\x4D\x5A",
    b"\x7F\x45\x4C\x46",
    b"\xCA\xFE\xBA\xBE",
    b"\xD0\xCF\x11\xE0",
];

/// `isNFO`.
pub fn is_nfo(data: &[u8]) -> bool {
    if data.len() >= MAX_NFO_SIZE || data.len() < MIN_NFO_SIZE {
        return false;
    }
    if NON_NFO_HEADER.is_match(data) || is_binary_format(data) {
        return false;
    }
    looks_like_text(data) && validate_content(data)
}

/// `detectBinaryFormat`. Its four-byte minimum also guards the two-byte signatures.
pub fn is_binary_format(data: &[u8]) -> bool {
    data.len() >= 4 && MAGIC.iter().any(|magic| data.starts_with(magic))
}

/// `validateNfoContent`: mostly printable, with words, and enough of what NFOs carry.
pub fn validate_content(content: &[u8]) -> bool {
    let length = content.len();
    if length < 50 {
        return false;
    }
    let printable = content
        .iter()
        .filter(|byte| (0x20..=0x7e).contains(*byte))
        .count();
    if (printable as f64 / length as f64) < 0.7 {
        return false;
    }
    if letter_runs(content) < 5 {
        return false;
    }
    let lower = content.to_ascii_lowercase();
    let mut indicators = 0.0_f64;
    for keyword in KEYWORDS {
        if contains(&lower, keyword.as_bytes()) {
            indicators += 1.0;
            if indicators >= 3.0 {
                return true;
            }
        }
    }
    if has_rule(content) {
        indicators += 1.0;
    }
    if URL.is_match(content) {
        indicators += 1.0;
    }
    if MEDIA_ID.is_match(content) {
        indicators += 2.0;
    }
    let fields = FIELD_VALUE.captures_all(content).len();
    if fields > 0 {
        indicators += (fields as f64 / 3.0).min(2.0);
    }
    indicators >= 2.0
}

/// `preg_match_all('/[A-Za-z]{2,}/')`: runs of two or more letters.
fn letter_runs(content: &[u8]) -> usize {
    content
        .split(|byte| !byte.is_ascii_alphabetic())
        .filter(|run| run.len() >= 2)
        .count()
}

/// `/[-=*]{5,}/`.
fn has_rule(content: &[u8]) -> bool {
    content
        .split(|byte| !matches!(byte, b'-' | b'=' | b'*'))
        .any(|run| run.len() >= 5)
}

fn contains(haystack: &[u8], needle: &[u8]) -> bool {
    haystack
        .windows(needle.len())
        .any(|window| window == needle)
}

/// libmagic's `text_chars` classes.
#[derive(Clone, Copy, PartialEq, Eq)]
enum Class {
    /// Never in text.
    F,
    /// Plain ASCII text.
    T,
    /// ISO-8859 text.
    I,
    /// Non-ISO extended ASCII, as Mac or IBM PC code pages.
    X,
}

fn class(byte: u8) -> Class {
    match byte {
        7..=13 | 27 | 0x20..=0x7e | 0x85 => Class::T,
        0x80..=0x9f => Class::X,
        0xa0..=0xff => Class::I,
        _ => Class::F,
    }
}

/// Whether libmagic's answer for these bytes would match PHP's `_textFileRegex`, `(ASCII|
/// ISO-8859|UTF-(8|16|32)...|Non-ISO extended-ASCII) text`.
pub fn looks_like_text(data: &[u8]) -> bool {
    // ascmagic ignores trailing NULs, keeping at least one byte.
    let mut end = data.len();
    while end > 1 && data[end - 1] == 0 {
        end -= 1;
    }
    let data = &data[..end];
    let Some(encoding) = encoding(data) else {
        return false;
    };
    if is_json(data) || is_csv(data) {
        // "JSON text data" and "CSV text" name no encoding.
        return false;
    }
    encoding != Encoding::Utf7
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum Encoding {
    Ascii,
    Utf7,
    Utf8,
    Utf16,
    Utf32,
    Latin1,
    Extended,
}

/// `file_encoding`, without EBCDIC, whose "EBCDIC text" never matched.
fn encoding(data: &[u8]) -> Option<Encoding> {
    if data.iter().all(|byte| class(*byte) == Class::T) {
        let utf7 = data.len() >= 4
            && data.starts_with(b"+/v")
            && matches!(data[3], b'8' | b'9' | b'+' | b'/');
        return Some(if utf7 {
            Encoding::Utf7
        } else {
            Encoding::Ascii
        });
    }
    if data.len() > 3 && data.starts_with(b"\xef\xbb\xbf") && looks_utf8(&data[3..]).is_some() {
        return Some(Encoding::Utf8);
    }
    if looks_utf8(data) == Some(true) {
        return Some(Encoding::Utf8);
    }
    if looks_ucs32(data) {
        return Some(Encoding::Utf32);
    }
    if looks_ucs16(data) {
        return Some(Encoding::Utf16);
    }
    if data
        .iter()
        .all(|byte| matches!(class(*byte), Class::T | Class::I))
    {
        return Some(Encoding::Latin1);
    }
    if data.iter().all(|byte| class(*byte) != Class::F) {
        return Some(Encoding::Extended);
    }
    None
}

/// `file_looks_utf8`: `None` when not UTF-8, else whether any multibyte sequence was seen. A
/// sequence cut off by the end of the data is accepted, as libmagic reads a prefix.
fn looks_utf8(data: &[u8]) -> Option<bool> {
    let mut multibyte = false;
    let mut index = 0;
    while index < data.len() {
        let byte = data[index];
        if byte & 0x80 == 0 {
            if class(byte) != Class::T {
                return None;
            }
            index += 1;
            continue;
        }
        if byte & 0x40 == 0 {
            return None;
        }
        let following = if byte & 0x20 == 0 {
            1
        } else if byte & 0x10 == 0 {
            2
        } else if byte & 0x08 == 0 {
            3
        } else if byte & 0x04 == 0 {
            4
        } else if byte & 0x02 == 0 {
            5
        } else {
            return None;
        };
        index += 1;
        for _ in 0..following {
            let Some(next) = data.get(index) else {
                return Some(multibyte);
            };
            if next & 0xc0 != 0x80 {
                return None;
            }
            index += 1;
        }
        multibyte = true;
    }
    Some(multibyte)
}

/// `looks_ucs16`: a byte order mark, then 16-bit units whose ASCII range is text.
fn looks_ucs16(data: &[u8]) -> bool {
    if data.len() < 2 {
        return false;
    }
    let big = match (data[0], data[1]) {
        (0xff, 0xfe) => false,
        (0xfe, 0xff) => true,
        _ => return false,
    };
    data[2..].as_chunks::<2>().0.iter().all(|unit| {
        let value = if big {
            u16::from_be_bytes([unit[0], unit[1]])
        } else {
            u16::from_le_bytes([unit[0], unit[1]])
        };
        value != 0xfffe && (value >= 128 || class(value as u8) == Class::T)
    })
}

/// `looks_ucs32`, likewise.
fn looks_ucs32(data: &[u8]) -> bool {
    if data.len() < 4 {
        return false;
    }
    let big = if data.starts_with(b"\xff\xfe\x00\x00") {
        false
    } else if data.starts_with(b"\x00\x00\xfe\xff") {
        true
    } else {
        return false;
    };
    data[4..].as_chunks::<4>().0.iter().all(|unit| {
        let value = if big {
            u32::from_be_bytes([unit[0], unit[1], unit[2], unit[3]])
        } else {
            u32::from_le_bytes([unit[0], unit[1], unit[2], unit[3]])
        };
        value != 0xfffe && (value >= 128 || class(value as u8) == Class::T)
    })
}

/// `file_is_json`: the whole buffer is one JSON object or array.
fn is_json(data: &[u8]) -> bool {
    let trimmed = data.trim_ascii();
    matches!(trimmed.first(), Some(b'{' | b'['))
        && serde_json::from_slice::<serde_json::Value>(trimmed).is_ok()
}

/// libmagic's `csv_parse`: the first lines carry the same nonzero number of commas.
fn is_csv(data: &[u8]) -> bool {
    const CSV_LINES: usize = 10;
    let (mut fields, mut expected, mut lines) = (0usize, 0usize, 0usize);
    let mut index = 0;
    while index < data.len() {
        let byte = data[index];
        index += 1;
        match byte {
            b'"' => index = skip_quoted(data, index),
            b',' => fields += 1,
            b'\n' => {
                lines += 1;
                if lines == CSV_LINES {
                    return expected != 0 && expected == fields;
                }
                if expected == 0 {
                    if fields == 0 {
                        return false;
                    }
                    expected = fields;
                } else if expected != fields {
                    return false;
                }
                fields = 0;
            }
            _ => {}
        }
    }
    expected != 0 && lines >= 3
}

/// libmagic's `eatquote`, entered just after an opening quote: a doubled quote is an escape.
fn skip_quoted(data: &[u8], mut index: usize) -> usize {
    let mut quote = false;
    while index < data.len() {
        let byte = data[index];
        index += 1;
        if byte != b'"' {
            if quote {
                return index - 1;
            }
            continue;
        }
        quote = !quote;
    }
    data.len()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn libmagic_classes() {
        assert!(looks_like_text(b"hello world"));
        assert!(looks_like_text(b"hello\0\0"));
        assert!(!looks_like_text(b"hello\x1a"));
        assert!(looks_like_text("caf\u{e9} release".as_bytes()));
        assert!(looks_like_text(b"caf\xe9 release"));
        assert!(looks_like_text(b"\x81\x82 abc"));
        assert!(!looks_like_text(b"+/v8 hi"));
        assert!(!looks_like_text(b"a,b\nc,d\ne,f\n"));
        assert!(looks_like_text(b"a,b\nc,d\n"));
        assert!(!looks_like_text(b"  {\"a\": 1}  "));
        assert!(looks_like_text(b"{bad json, release}\n"));
    }

    #[test]
    fn content_checks() {
        assert!(letter_runs(b"a bb ccc d1ee") == 3);
        assert!(has_rule(b"x ===== y"));
        assert!(!has_rule(b"x ==-= y"));
    }
}
