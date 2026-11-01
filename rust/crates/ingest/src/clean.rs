//! Collection names, which collection hashes depend on, so a release's parts group together.

use std::sync::LazyLock;

use nntmux_core::pcre::Pcre;
use regex::bytes::Regex;
use sha1::{Digest, Sha1};

use crate::header::php_trim;

/// Subject matched the generic cleaning.
pub const GENERIC_MATCH: i32 = -10;
/// Subject matched the music cleaning.
pub const MUSIC_MATCH: i32 = -20;

const FILE_EXTENSIONS: &str = r#"([\-_](proof|sample|thumbs?))*(\.part\d*(\.rar)?|\.rar|\.7z|\.par2)?(\d{1,3}\.rev"|\.vol\d+\+\d+.+?"|\.[A-Za-z0-9]{2,4}"|")"#;

fn pattern(source: &str) -> Pcre {
    Pcre::with_flags(source, "i").expect("a valid built-in regex")
}

static GENERIC: LazyLock<Vec<Pcre>> = LazyLock::new(|| {
    [
        r#"\d{1,3}([,.\/])\d{1,3}\s([kmg])b|(])?\s\d+KB\s(yENC)?|"?\s\d+\sbytes?|[- ]?\d+([.,])?\d+\s([gkm])?B\s-?(\s?yenc)?|\s\(d{1,3},\d{1,3}\s{K,M,G}B\)\s|yEnc \d+k$|{\d+ yEnc bytes}|yEnc \d+ |\(\d+ ?([kmg])?b(ytes)?\) yEnc$"#,
        r"AutoRarPar\d{1,5}|\(\d+\)\s?yEnc|\d+(Amateur|Classic)| \d{4,}[a-z]{4,} |.vol\d+\+\d+|.part\d+",
        r#"((( \(\d\d\) -|(\d\d)? - \d\d\.|\d{4} \d\d -) | - \d\d-| \d\d\. [a-z]).+| \d\d of \d\d| \dof\d)\.mp3"?|([)(\[\s])\d{1,5}(\/|([\s_])of([\s_])|-)\d{1,5}([)\]\s$:])|\(\d{1,3}\|\d{1,3}\)|[^\d]{4}-\d{1,3}-\d{1,3}\.|\s\d{1,3}\sof\s\d{1,3}\.|\s\d{1,3}\/\d{1,3}|\d{1,3}of\d{1,3}\.|^\d{1,3}\/\d{1,3}\s|\d{1,3} - of \d{1,3}"#,
        EXTENSIONS_SWEEP,
        FILE_EXTENSIONS,
    ]
    .into_iter()
    .map(pattern)
    .collect()
});

const EXTENSIONS_SWEEP: &str = r#"(-? [a-z0-9]+-?|\(?\d{4}\)?([_-])[a-z0-9]+)\.jpg"?| [a-z0-9]+\.mu3"?|((\d{1,3})?\.part(\d{1,5})?|\d{1,5} ?|sample|- Partie \d+)?\.(7z|\d{3}(?=([\s"]))|avi|diz|docx?|epub|idx|iso|jpg|m3u|m4a|mds|mkv|mobi|mp4|nfo|nzb|par(\s?2|")|pdf|rar|rev|rtf|r\d\d|sfv|srs|srr|sub|txt|vol.+(par2)|xls|zip|z{2,3})"?|(\s|(\d{2,3})?-)\d{2,3}\.mp3|\d{2,3}\.pdf|\.part\d{1,4}\."#;

static MUSIC: LazyLock<Vec<Pcre>> = LazyLock::new(|| {
    [
        r#"((( \(\d\d\) -|(\d\d)? - \d\d\.|\d{4} \d\d -) | - \d\d-| \d\d\. [a-z]).+| \d\d of \d\d| \dof\d)\.mp3"?|([(\[\s])\d{1,4}(\/|([\s_])of([\s_])|-)\d{1,4}([)\]\s$:])|\(\d{1,3}\|\d{1,3}\)|-\d{1,3}-\d{1,3}\.|\s\d{1,3}\sof\s\d{1,3}\.|\s\d{1,3}\/\d{1,3}|\d{1,3}of\d{1,3}\.|^\d{1,3}\/\d{1,3}\s|\d{1,3} - of \d{1,3}"#,
        r#"".+""#,
        EXTENSIONS_SWEEP,
        r#"\d{1,3}([,.\/])\d{1,3}\s([kmg])b|(])?\s\d+KB\s(yENC)?|"?\s\d+\sbytes?|[- ]?\d+[.,]?\d+\s([gkm])?B\s-?(\s?yenc)?|\s\(d{1,3},\d{1,3}\s{K,M,G}B\)\s|yEnc \d+k$|{\d+ yEnc bytes}|yEnc \d+ |\(\d+ ?([kmg])?b(ytes)?\) yEnc$"#,
        r"AutoRarPar\d{1,5}|\(\d+\)\s?yEnc|\d+(Amateur|Classic)| \d{4,}[a-z]{4,} |.vol\d+\+\d+|.part\d+",
    ]
    .into_iter()
    .map(pattern)
    .collect()
});

static MUSIC_GROUP: LazyLock<Regex> =
    LazyLock::new(|| bytes_regex(r"\.(flac|lossless|mp3|music|sounds)"));
static MUSIC_SUBJECT: LazyLock<Regex> =
    LazyLock::new(|| bytes_regex(r#"^([0-9A-Za-z_]{10,}-[a-zA-Z0-9]+ ")[0-9][0-9]-.+?" yEnc$"#));
static WHITESPACE_RUN: LazyLock<Regex> = LazyLock::new(|| bytes_regex(r"\s\s+"));
static SHORT_SUBJECT: LazyLock<Regex> =
    LazyLock::new(|| bytes_regex(r"(?i)^[\-a-z0-9$ ]{1,7}yEnc$"));
static QUOTED_START: LazyLock<Regex> = LazyLock::new(|| bytes_regex(r#"(?i).*("[A-Z0-9]+).*?""#));
static NON_ALNUM: LazyLock<Regex> = LazyLock::new(|| bytes_regex(r"(?i)[^A-Z0-9]"));
static QUOTED: LazyLock<Regex> = LazyLock::new(|| bytes_regex(r#"".+?""#));
static ALNUM_OR_EXTENSION: LazyLock<Pcre> =
    LazyLock::new(|| pattern(&format!("[a-z0-9]|{FILE_EXTENSIONS}")));

fn bytes_regex(source: &str) -> Regex {
    Regex::new(&format!("(?-u){source}")).expect("a valid built-in regex")
}

/// A collection regex that applies to a group, in the order they're tried.
#[derive(Clone, Debug)]
pub struct CollectionRegex {
    pub id: i32,
    pub regex: Pcre,
}

/// The cleaned name of a subject and the regex that produced it.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct CleanName {
    pub id: i32,
    pub name: Vec<u8>,
}

/// `CollectionsCleaningService::collectionsCleaner`: the first stored regex that yields a name,
/// or else the generic cleaning.
pub fn clean_subject(regexes: &[CollectionRegex], subject: &[u8], group: &[u8]) -> CleanName {
    for candidate in regexes {
        let name = regex_name(&candidate.regex, subject);
        // PHP treats '' and '0' alike as nothing found.
        if !name.is_empty() && name != b"0" {
            return CleanName {
                id: candidate.id,
                name,
            };
        }
    }
    generic(subject, group)
}

/// Named groups concatenated in name order, without the ones for request ids and part counts.
fn regex_name(regex: &Pcre, subject: &[u8]) -> Vec<u8> {
    let Some(found) = regex.captures(subject) else {
        return Vec::new();
    };
    let mut name = Vec::new();
    for (group, value) in found.named() {
        let lower = group.to_ascii_lowercase();
        if !lower.contains("reqid") && !lower.contains("parts") {
            name.extend_from_slice(value);
        }
    }
    name
}

fn generic(subject: &[u8], group: &[u8]) -> CleanName {
    if !MUSIC_GROUP.is_match(group) {
        return CleanName {
            id: GENERIC_MATCH,
            name: normalize(&sweep(&GENERIC, subject)),
        };
    }
    if let Some(found) = MUSIC_SUBJECT.captures(subject) {
        return CleanName {
            id: MUSIC_MATCH,
            name: found[1].to_vec(),
        };
    }
    let mut name = normalize(&sweep(&MUSIC, subject));
    if name.len() <= 10 || SHORT_SUBJECT.is_match(&name) {
        name = enhance_short(name, subject);
    }
    CleanName {
        id: MUSIC_MATCH,
        name,
    }
}

fn sweep(patterns: &[Pcre], subject: &[u8]) -> Vec<u8> {
    patterns.iter().fold(subject.to_vec(), |text, regex| {
        regex.replace_all(&text, b" ")
    })
}

/// Too short or generic a name: add what's left of the subject once its alphanumerics and quoted
/// name are gone, then the start of the quoted name and the first three other characters.
fn enhance_short(mut name: Vec<u8>, subject: &[u8]) -> Vec<u8> {
    let mut extra = QUOTED_START
        .captures(subject)
        .map(|found| found[1].to_vec())
        .unwrap_or_default();
    for other in NON_ALNUM.find_iter(subject).take(3) {
        extra.extend_from_slice(other.as_bytes());
    }
    let without_quoted = QUOTED.replace_all(subject, &b""[..]);
    name.extend(ALNUM_OR_EXTENSION.replace_all(&without_quoted, b""));
    name.extend(extra);
    name
}

/// Collapses whitespace runs, trims, and makes the name UTF-8.
fn normalize(subject: &[u8]) -> Vec<u8> {
    let collapsed = WHITESPACE_RUN.replace_all(subject, &b" "[..]);
    to_utf8(php_trim(&collapsed)).into_owned().into_bytes()
}

/// `Utf8::clean`. Valid UTF-8 stays as it is. Anything else is read as Windows-1252, which is
/// what mbstring's detection picks for Latin text.
///
/// PHP detected over all 79 encodings it knows, and this doesn't port that: a few byte sequences
/// came out as SJIS and the like, and a valid `+AGE-` was read as UTF-7. Such a subject gets a
/// different name than PHP gave it, so a collection in progress at cutover could split in two.
pub fn to_utf8(value: &[u8]) -> std::borrow::Cow<'_, str> {
    match std::str::from_utf8(value) {
        Ok(text) => std::borrow::Cow::Borrowed(text),
        Err(_) => std::borrow::Cow::Owned(value.iter().map(|&byte| windows_1252(byte)).collect()),
    }
}

fn windows_1252(byte: u8) -> char {
    const HIGH: [char; 32] = [
        '€', '\u{81}', '‚', 'ƒ', '„', '…', '†', '‡', 'ˆ', '‰', 'Š', '‹', 'Œ', '\u{8D}', 'Ž',
        '\u{8F}', '\u{90}', '‘', '’', '“', '”', '•', '–', '—', '˜', '™', 'š', '›', 'œ', '\u{9D}',
        'ž', 'Ÿ',
    ];
    match byte {
        0x80..=0x9F => HIGH[usize::from(byte - 0x80)],
        other => char::from(other),
    }
}

/// `sha1(name . totalFiles)`, as binary.
pub fn collection_hash(name: &[u8], total_files: i64) -> [u8; 20] {
    let mut hash = Sha1::new();
    hash.update(name);
    hash.update(total_files.to_string());
    hash.finalize().into()
}

/// The lowercase hex md5 that identifies a binary inside its collection.
pub fn binary_hash(base: &[u8], from: &[u8], file_number: i64) -> String {
    use md5::Md5;
    let mut hash = Md5::new();
    if file_number > 0 {
        hash.update(format!("file:{file_number}"));
    } else {
        hash.update(b"subject:");
        hash.update(identity_text(base));
        hash.update(b"\0poster:");
        hash.update(identity_text(from));
    }
    hex::encode(hash.finalize())
}

/// `mb_strtolower(trim(preg_replace('/\s+/u', ' ', Utf8::clean($value))))`
fn identity_text(value: &[u8]) -> String {
    let clean = to_utf8(value);
    let mut collapsed = String::with_capacity(clean.len());
    let mut in_space = false;
    for c in clean.chars() {
        if c.is_whitespace() {
            if !in_space {
                collapsed.push(' ');
            }
            in_space = true;
        } else {
            collapsed.push(c);
            in_space = false;
        }
    }
    collapsed
        .trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B'])
        .to_lowercase()
}
