//! Name analysis shared by categorization and name fixing: hashed-name detection, the
//! NZBSPLIT wrapper and obfuscated-subject cleanup.

use std::sync::LazyLock;

use crate::pcre::{PHP_WS, replace_all, trim_chars};
use crate::{mi, ms, rx};

const YEAR: &str = r"\b(19|20)\d{2}\b";

pub fn strip_extensions_for_analysis(name: &str) -> String {
    let re = rx!(i
        r"\.(mkv|avi|mp4|m4v|mpg|mpeg|wmv|flv|mov|ts|vob|iso|divx|par2?|nfo|sfv|nzb|rar|r\d{2,3}|zip|7z|gz|tar|001|msix|msixbundle|appx|appxbundle|apk|xap|ipa|deb|rpm|pkg|dmg|exe|msi)$"
    );
    replace_all(re, trim_chars(name, PHP_WS), "")
}

pub fn core_name(cleaned: &str) -> String {
    replace_all(rx!(r"[.\-_\s]+"), cleaned, "")
}

pub fn is_hashed_or_gibberish(name: &str) -> bool {
    let core = core_name(&strip_extensions_for_analysis(name));
    is_uuid(&core)
        || is_pure_hex(&core)
        || is_bounded_md5(name)
        || is_bounded_sha1(name)
        || is_bounded_sha256(name)
        || is_bounded_generic_hash(name)
        || is_base64_like(name)
        || is_obfuscated_uppercase(name)
        || is_obfuscated_mixed(name)
        || is_obfuscated_usenet_filename(name)
        || is_random_by_character_analysis(&core, name)
        || has_insufficient_word_structure(&core)
        || is_zero_vowel_long_token(&core, 20)
        || is_random_digit_pattern(&core)
}

pub fn is_bounded_md5(name: &str) -> bool {
    mi!(
        name,
        r#"(?:^|["'\s\[\]/\-])([a-f0-9]{32})(?:["'\s\[\]/.\-]|$)"#
    )
}

pub fn is_bounded_sha1(name: &str) -> bool {
    mi!(
        name,
        r#"(?:^|["'\s\[\]/\-])([a-f0-9]{40})(?:["'\s\[\]/.\-]|$)"#
    )
}

pub fn is_bounded_sha256(name: &str) -> bool {
    mi!(
        name,
        r#"(?:^|["'\s\[\]/\-])([a-f0-9]{64})(?:["'\s\[\]/.\-]|$)"#
    )
}

pub fn is_bounded_generic_hash(name: &str) -> bool {
    let re = rx!(i r#"(?:^|["'\s\[\]/\-])([a-z0-9]{24,128})(?:["'\s\[\]/.\-]|$)"#);
    let Some(caps) = re.captures(name.as_bytes()) else {
        return false;
    };
    let token = String::from_utf8_lossy(&caps[1]);
    if ms!(token, YEAR) {
        return false;
    }
    token.bytes().any(|b| b.is_ascii_digit()) && looks_like_random_string(&token)
}

pub fn is_base64_like(name: &str) -> bool {
    let re = rx!(r#"(?:^|["'\s\[\]/\-])([A-Za-z0-9+/_=-]{24,})(?:["'\s\[\]/.\-]|$)"#);
    let Some(caps) = re.captures(name.as_bytes()) else {
        return false;
    };
    let token = String::from_utf8_lossy(&caps[1]);
    let token = token.trim_end_matches('=');
    if token.len() < 24 || ms!(token, YEAR) {
        return false;
    }
    let has = |test: fn(&u8) -> bool| token.as_bytes().iter().any(test);
    if !has(u8::is_ascii_uppercase) || !has(u8::is_ascii_lowercase) || !has(u8::is_ascii_digit) {
        return false;
    }
    looks_like_random_string(token)
}

pub fn is_uuid(core: &str) -> bool {
    mi!(
        core,
        r"^[a-f0-9]{8}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{12}$"
    )
}

pub fn is_pure_hex(core: &str) -> bool {
    mi!(core, r"^[a-f0-9]{16,}$")
}

pub fn is_obfuscated_uppercase(name: &str) -> bool {
    ms!(name, r"^[A-Z0-9]{15,}$")
}

pub fn is_obfuscated_mixed(name: &str) -> bool {
    ms!(name, r"^[a-zA-Z0-9]{15,}$")
        && !ms!(name, YEAR)
        && !ms!(name, r"^[A-Z][a-z]+([A-Z][a-z]+)+$")
}

pub fn is_obfuscated_usenet_filename(name: &str) -> bool {
    let re = rx!(i
        r#"\[\d+/\d+]\s*-\s*"([a-zA-Z0-9]{12,})\.(part\d+\.rar|7z\.\d{3}|rar|zip|vol\d+\+\d+\.par2|par2)""#
    );
    let Some(caps) = re.captures(name.as_bytes()) else {
        return false;
    };
    let filename = String::from_utf8_lossy(&caps[1]);
    !ms!(filename, r"[._ -]") && !ms!(filename, YEAR) && looks_like_random_string(&filename)
}

pub fn is_obfuscated_punctuation(name: &str) -> bool {
    ms!(name, r"^[^a-zA-Z]*[A-Z0-9._\-]{5,}[^a-zA-Z]*$")
        && !mi!(name, r"\.(mkv|avi|mp4|mp3|flac|pdf|epub|exe|iso)$")
}

pub fn is_random_by_character_analysis(core: &str, original: &str) -> bool {
    if core.len() < 16 || !ms!(core, r"^[a-zA-Z0-9]+$") {
        return false;
    }
    let rate = character_transitions(core) as f64 / (core.len() - 1) as f64;
    if rate > 0.35 {
        let check = if original.is_empty() { core } else { original };
        return !mi!(
            check,
            r"\b(movie|film|series|episode|season|show|video|audio|music|album|dvd|bluray|hdtv|webrip|xvid|x264|x265|hevc|aac|mp3|flac|720p|1080p|2160p|4k|complete|proper|repack|dubbed|subbed|english|french|german|spanish|italian|rip|web|hdr|remux|disc|internal|retail)\b"
        );
    }
    false
}

pub fn has_insufficient_word_structure(core: &str) -> bool {
    if core.len() < 20 || !ms!(core, r"^[a-zA-Z0-9]+$") {
        return false;
    }
    max_consecutive_letters(core) < 5 && !ms!(core, r"^[a-zA-Z]+\d{1,4}$")
}

pub fn is_random_digit_pattern(core: &str) -> bool {
    mi!(core, r"^[a-zA-Z]{1,3}\d{6,}[a-zA-Z]*$") || mi!(core, r"^[a-zA-Z0-9]{2,4}\d{8,}$")
}

pub fn is_zero_vowel_long_token(core: &str, min_length: usize) -> bool {
    if core.len() < min_length || !ms!(core, r"^[a-zA-Z0-9]+$") || ms!(core, YEAR) {
        return false;
    }
    let letters: Vec<u8> = core.bytes().filter(|b| !b.is_ascii_digit()).collect();
    letters.len() >= 10
        && !letters
            .iter()
            .any(|b| matches!(b.to_ascii_lowercase(), b'a' | b'e' | b'i' | b'o' | b'u'))
}

pub fn has_normal_vowel_ratio(value: &str) -> bool {
    let letters: Vec<u8> = value.bytes().filter(u8::is_ascii_alphabetic).collect();
    if letters.len() < 5 {
        return false;
    }
    let vowels = letters
        .iter()
        .filter(|b| matches!(b.to_ascii_lowercase(), b'a' | b'e' | b'i' | b'o' | b'u'))
        .count();
    vowels as f64 / letters.len() as f64 >= 0.2
}

pub fn looks_like_random_string(value: &str) -> bool {
    if ms!(value, r"^[A-Z]+$") || ms!(value, r"^[a-z]+$") {
        return value.len() >= 12;
    }
    let ratio = character_transitions(value) as f64 / value.len().saturating_sub(1).max(1) as f64;
    let has_word_pattern = mi!(
        value,
        r"[bcdfghjklmnpqrstvwxyz]{1,2}[aeiou][bcdfghjklmnpqrstvwxyz]{1,2}[aeiou]"
    );
    if ratio > 0.3 && !has_word_pattern {
        return true;
    }
    if mi!(value, r"[bcdfghjklmnpqrstvwxyz]{5,}") {
        return true;
    }
    ms!(value, r"[A-Z]") && ms!(value, r"[a-z]") && ms!(value, r"\d")
}

pub fn character_transitions(value: &str) -> usize {
    value
        .as_bytes()
        .windows(2)
        .filter(|pair| {
            let (prev, curr) = (pair[0], pair[1]);
            if prev.is_ascii_digit() != curr.is_ascii_digit() {
                true
            } else {
                !prev.is_ascii_digit() && prev.is_ascii_uppercase() != curr.is_ascii_uppercase()
            }
        })
        .count()
}

pub fn max_consecutive_letters(value: &str) -> usize {
    value
        .as_bytes()
        .split(|b| !b.is_ascii_alphabetic())
        .map(<[u8]>::len)
        .max()
        .unwrap_or(0)
}

/// Removes the `__NZBSPLIT__<hex>__NZBSPLIT__` wrapper that split uploads carry and returns
/// the title inside it.
pub fn unwrap_nzbsplit(value: &str) -> Option<String> {
    let re = rx!(i r"__NZBSPLIT__([a-f0-9]{8,})__NZBSPLIT__(.+)$");
    let caps = re.captures(value.as_bytes())?;
    let mut title = String::from_utf8_lossy(&caps[2]).into_owned();

    let subject = [
        rx!(i r#""\s*yEnc(?:\s*\([^)]*\))?$"#),
        rx!(i r"\s+yEnc(?:\s*\([^)]*\))?$"),
        rx!(i r#""\s*yEnc.*$"#),
        rx!(i r"\s+yEnc.*$"),
    ];
    let archive = [
        rx!(i r"\.part\d+\.rar$"),
        rx!(i r"\.r\d{2,4}$"),
        rx!(i r"\.7z\.\d{2,4}$"),
        rx!(i r"\.7z$"),
        rx!(i r"\.rar$"),
        rx!(i r"\.par2?$"),
        rx!(i r"\.vol\d+[+\-]\d+\.par2?$"),
        rx!(r"\.\d{3}$"),
    ];

    let mut did_strip = true;
    while did_strip {
        did_strip = false;
        for re in subject {
            let updated = replace_all(re, &title, "");
            if updated != title {
                title = trim_chars(&updated, " \t\n\r\0\x0B\"'").to_owned();
                did_strip = true;
            }
        }
        for re in archive {
            let updated = replace_all(re, &title, "");
            if updated != title {
                title = updated;
                did_strip = true;
            }
        }
    }

    let title = title.replace('_', ".").replace('+', " ");
    let title = replace_all(rx!(r"\.{2,}"), &title, ".");
    let title = replace_all(rx!(r"\s{2,}"), &title, " ");
    let title = replace_all(rx!(r"\.\s+"), &title, ".");
    let title = replace_all(rx!(r"\s+\."), &title, ".");
    let title = trim_chars(&title, " \t\n\r\0\x0B.-_");
    (!title.is_empty()).then(|| title.to_owned())
}

/// Pulls the readable name out of a subject that wraps it in posting noise, such as
/// `[1/9] - "some_name.part01.rar"`. Returns `None` when there's nothing to change.
pub fn extract_obfuscated_subject(value: &str) -> Option<String> {
    let original = trim_chars(value, PHP_WS);
    if original.is_empty() {
        return None;
    }

    let prefixes = [
        rx!(i r"^\s*N:/NZB\s*\[\d+/\d+\]\s*-\s*"),
        rx!(i r"^\s*N[\s._:-]*NZB[\s._-]*\[\d+(?:[/_]\d+)?\][\s._-]*-\s*"),
        rx!(r"^\s*\[[^\]]{2,80}\]\s*"),
        rx!(i r"^\s*(?:re\s*)?posted\s+by\s+[^-]{2,120}\s*-\s*"),
    ];
    let mut normalized = original.to_owned();
    let mut looks_obfuscated = false;
    for re in prefixes {
        let updated = replace_all(re, &normalized, "");
        if updated != normalized {
            looks_obfuscated = true;
            normalized = updated;
        }
    }

    let quoted = rx!(r#""([^"]{3,240})""#)
        .captures(normalized.as_bytes())
        .or_else(|| rx!(r"'([^']{3,240})'").captures(normalized.as_bytes()))
        .map(|caps| String::from_utf8_lossy(&caps[1]).into_owned());
    if let Some(quoted) = quoted {
        normalized = quoted;
        looks_obfuscated = true;
    }

    if looks_obfuscated {
        let archive = [
            rx!(i r"\.part\d+\.rar$"),
            rx!(i r"\.r\d{2,4}$"),
            rx!(i r"\.7z\.\d{2,4}$"),
            rx!(i r"\.7z$"),
            rx!(i r"\.rar$"),
            rx!(i r"\.par2?$"),
            rx!(i r"\.part$"),
            rx!(i r"\.vol\d+[+\-]\d+\.par2?$"),
            rx!(r"\.\d{3}$"),
        ];
        for re in archive {
            normalized = replace_all(re, &normalized, "");
        }
    }

    let normalized = normalized.replace(['_', '+'], " ");
    let normalized = dots_before_alnum_to_spaces(&normalized);
    let normalized = replace_all(rx!(r"\s{2,}"), &normalized, " ");
    let normalized = trim_chars(&normalized, " \t\n\r\0\x0B\"'`-_.");
    let normalized = readable_title(normalized);

    (!normalized.is_empty() && normalized != original).then_some(normalized)
}

fn dots_before_alnum_to_spaces(value: &str) -> String {
    let bytes = value.as_bytes();
    let mut out = String::with_capacity(value.len());
    for (index, c) in value.char_indices() {
        let next_alnum = bytes
            .get(index + 1)
            .is_some_and(|b| b.is_ascii_alphanumeric());
        out.push(if c == '.' && next_alnum { ' ' } else { c });
    }
    out
}

static UPPERCASE: LazyLock<regex::Regex> =
    LazyLock::new(|| regex::Regex::new(r"\p{Lu}").expect("static pattern"));

fn readable_title(value: &str) -> String {
    if UPPERCASE.is_match(value) {
        value.to_owned()
    } else {
        title_case(value)
    }
}

/// `mb_convert_case($value, MB_CASE_TITLE, 'UTF-8')`: a cased letter starts a word unless a
/// cased letter came before it, skipping case-ignorable characters like `.` and `'`.
pub fn title_case(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    let mut in_word = false;
    for c in value.chars() {
        if in_word {
            out.extend(c.to_lowercase());
        } else {
            let mut upper = c.to_uppercase();
            out.extend(upper.next());
            // A title-cased letter that expands, like ß to Ss, keeps its tail lowercase.
            out.extend(upper.flat_map(char::to_lowercase));
        }
        if !is_case_ignorable(c) {
            in_word = c.is_lowercase() || c.is_uppercase();
        }
    }
    out
}

fn is_case_ignorable(c: char) -> bool {
    matches!(
        c,
        '\'' | '.' | ':' | '^' | '`' | '\u{A8}' | '\u{AD}' | '\u{AF}' | '\u{B4}' | '\u{B7}'
            | '\u{B8}' | '\u{2018}' | '\u{2019}' | '\u{2024}' | '\u{2027}'
            | '\u{0300}'..='\u{036F}' | '\u{200B}'..='\u{200F}' | '\u{202A}'..='\u{202E}'
    )
}
