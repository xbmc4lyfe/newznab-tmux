//! Rust candidate for `App\Services\Releases\Verification\HeaderFilenameIdentity::matches`.
//!
//! Whether a spot-checked article's yEnc filename agrees with the filename its subject
//! declares. This is a hand-written, byte-exact port of the PHP rule and its PCRE patterns;
//! the PHP function stays authoritative until benchmarks qualify a replacement. Mirrored
//! PCRE behaviour (verified against PHP 8.5 in the parity harness):
//!
//! * `/u` on the quoted-value pattern: an invalid UTF-8 subject makes `preg_match_all` fail,
//!   which the PHP rule rejects (a declared name cannot be parsed, so it cannot be compared).
//! * Patterns without `/u` use ASCII `\s` (9..=13 and 32) and `\d`, ASCII-only `/i` and `\z` as an
//!   absolute end; patterns with `/u` are Unicode-aware (`\s`, `\d`), via tables generated from
//!   the PHP runtime's own PCRE2 (`header_filename_unicode.rs`).
//! * `trim()` strips space, `\t`, `\n`, `\r`, NUL and `\x0B` (not form feed); the executable
//!   test applies `rtrim(". \t")` and the NTFS stream-stripped name.
//! * Unicode case mapping, canonical (NFC) composition and PCRE's Unicode caseless matching are
//!   not ported. Wherever the PHP verdict would need them (a non-ASCII stem or extension that
//!   differs byte for byte, or a non-ASCII executable name searched in an unquoted subject) the
//!   export declines (-1) and the PHP rule decides.

use crate::header_filename_unicode::{DIGITS, LETTERS, MARKS, NUMBERS, SPACES};

/// Quoted filename spans are found in valid UTF-8 only, exactly like the `/u` PCRE pattern.
pub(crate) fn matches(subject: &[u8], actual: &[u8]) -> Option<bool> {
    // Article names are validated as UTF-8 upstream; anything else cannot be compared.
    if std::str::from_utf8(actual).is_err() {
        return Some(false);
    }
    let subject = trim(subject);
    let Ok(text) = std::str::from_utf8(subject) else {
        return Some(false);
    };
    let bytes = text.as_bytes();
    // Quoted spans, scanned left to right without overlap: (open, start, end, after).
    let mut spans: Vec<(usize, usize, usize, usize)> = Vec::new();
    let mut index = 0;
    while index < bytes.len() {
        let found = match bytes[index] {
            b'"' => double_quoted(bytes, index),
            b'\'' => single_quoted(text, index),
            _ => None,
        };
        let Some((start, end, after)) = found else {
            index += 1;
            continue;
        };
        spans.push((index, start, end, after));
        index = after;
    }
    // One yEnc marker ends the declaration: a second marker is ambiguous, and quotes after it are
    // metadata. Markers count only outside quoted names, which are blanked byte for byte.
    let (marker_count, first_marker) = if spans.is_empty() {
        yenc_markers(text)
    } else {
        let mut masked = bytes.to_vec();
        for &(open, _, _, after) in &spans {
            masked[open..after].fill(b' ');
        }
        match String::from_utf8(masked) {
            Ok(masked) => yenc_markers(&masked),
            Err(_) => return None,
        }
    };
    if marker_count > 1 {
        return Some(false);
    }
    let marker = first_marker.unwrap_or(bytes.len());
    let mut declared_before_yenc: Option<&[u8]> = None;
    let mut declared_after_counter: Option<&[u8]> = None;
    let mut last: Option<&[u8]> = None;
    let mut quoted_actual = false;
    for &(open, start, end, after) in &spans {
        if after > marker {
            break;
        }
        let value = &bytes[start..end];
        quoted_actual |= value == actual;
        if yenc_follows(&bytes[after..]) {
            // Two quoted names each marked by yEnc leave the article's filename ambiguous.
            if declared_before_yenc.is_some() {
                return Some(false);
            }
            declared_before_yenc = Some(value);
        }
        if declared_after_counter.is_none() && counter_precedes(&bytes[..open]) {
            declared_after_counter = Some(value);
        }
        last = Some(value);
    }
    let Some(last) = last else {
        // An unquoted subject declares a file only by naming it before the marker, not a longer
        // file such as body.exe.mkv.
        return named_in_subject(&text[..marker], trimmed(actual));
    };
    let declared = declared_before_yenc
        .or(declared_after_counter)
        .unwrap_or(last);
    // An executable body must be exactly the executable type its subject declares, even when
    // another quoted value names it.
    if has_executable_suffix(actual)
        && (!has_executable_suffix(declared)
            || !fold_equal(
                final_extension(bare(actual)),
                final_extension(bare(declared)),
            )?
            || !fold_equal(
                final_extension(trimmed(actual)),
                final_extension(trimmed(declared)),
            )?)
    {
        return Some(false);
    }
    // Parity and payload are not interchangeable: a declared PAR2 file is not payload evidence, and
    // a declared payload file is not satisfied by a PAR2 body, even when another quoted value
    // names it.
    if let (Some(declared_class), Some(body_class)) = (parity_class(declared), parity_class(actual))
        && declared_class != body_class
    {
        return Some(false);
    }
    if quoted_actual {
        return Some(true);
    }
    // A long or hexadecimal random token is plainly obfuscated. A shorter one could be a misread
    // title (12AngryMen), so it admits only a plainly random body or one that extends the token.
    if opaque(declared) {
        let token = token(declared);
        let bare_actual = bare(actual);
        return Some(
            random(declared)
                || random(actual)
                || (bare_actual.len() >= token.len()
                    && bare_actual[..token.len()].eq_ignore_ascii_case(token)),
        );
    }
    // A readable declared name must agree on its volume and extension chain, and on its final
    // extension when that chain does not cover it, as well as its stem.
    let (declared_stem, actual_stem) = (strip_suffixes(declared), strip_suffixes(actual));
    if !declared[declared_stem.len()..].eq_ignore_ascii_case(&actual[actual_stem.len()..])
        || !fold_equal(final_extension(declared), final_extension(actual))?
    {
        return Some(false);
    }
    stems_agree(declared_stem, actual_stem)
}

/// `fold($a) === fold($b)` for extensions, where `fold` is NFC plus `mb_strtolower`. Equal bytes
/// fold equally and ASCII folds by case; any other non-ASCII difference needs Unicode semantics
/// that are not ported, so it is declined (None).
fn fold_equal(first: &[u8], second: &[u8]) -> Option<bool> {
    if first == second {
        Some(true)
    } else if first.is_ascii() && second.is_ascii() {
        Some(first.eq_ignore_ascii_case(second))
    } else {
        None
    }
}

/// Parity (`true`) when either filesystem form is a PAR2 name, payload (`false`) when the name has
/// another extension or is a readable extensionless payload such as README, and none otherwise.
fn parity_class(name: &[u8]) -> Option<bool> {
    if ends_with_par2(trimmed(name)) || ends_with_par2(bare(name)) {
        return Some(true);
    }
    if !final_extension(trimmed(name)).is_empty()
        || !final_extension(bare(name)).is_empty()
        || proves_payload(name)
    {
        return Some(false);
    }
    None
}

/// `preg_match('/\.par2\z/i', $name) === 1`.
fn ends_with_par2(name: &[u8]) -> bool {
    name.len() >= 5 && name[name.len() - 5..].eq_ignore_ascii_case(b".par2")
}

/// `preg_match('/\.[\p{L}\p{N}]{1,8}\z/u', $name) === 1`.
fn has_short_extension(name: &[u8]) -> bool {
    let Ok(text) = std::str::from_utf8(name) else {
        return false;
    };
    text.rfind('.').is_some_and(|dot| {
        let tail = &text[dot + 1..];
        (1..=8).contains(&tail.chars().count()) && tail.chars().all(is_letter_or_number)
    })
}

/// Whether a sampled article name is evidence of a source payload: a non-PAR2 name with an
/// extension, or a readable extensionless name such as README, never an obfuscated token.
fn proves_payload(name: &[u8]) -> bool {
    if ends_with_par2(trimmed(name)) || ends_with_par2(bare(name)) {
        return false;
    }
    if has_short_extension(trimmed(name)) || has_short_extension(bare(name)) {
        return true;
    }
    let bare_name = bare(name);
    !bare_name.is_empty()
        && !bare_name.contains(&b'.')
        && !opaque(bare_name)
        && std::str::from_utf8(bare_name)
            .is_ok_and(|text| text.chars().any(|c| in_ranges(LETTERS, c as u32)))
}

/// The number of whole-word `yEnc` markers
/// (`(?<![\p{L}\p{M}\p{N}_])yEnc(?![\p{L}\p{M}\p{N}_])`, `/iu`) and the byte offset of the first.
fn yenc_markers(subject: &str) -> (usize, Option<usize>) {
    let bytes = subject.as_bytes();
    let (mut count, mut first, mut at) = (0, None, 0);
    while at + 4 <= bytes.len() {
        if bytes[at..at + 4].eq_ignore_ascii_case(b"yenc")
            && !subject[..at]
                .chars()
                .next_back()
                .is_some_and(is_word_or_mark)
            && !subject[at + 4..]
                .chars()
                .next()
                .is_some_and(is_word_or_mark)
        {
            count += 1;
            first.get_or_insert(at);
            at += 4;
        } else {
            at += 1;
        }
    }
    (count, first)
}

/// An opaque token that is hexadecimal (16+) or densely mixed (lower/upper/digit class switches at
/// 40% or more of the positions), or 20+ characters with at least two separate digit runs (a long
/// title has at most one number in it).
fn random(name: &[u8]) -> bool {
    let token = token(name);
    let class = |b: &u8| match b {
        b'a'..=b'z' => 0,
        b'A'..=b'Z' => 1,
        _ => 2,
    };
    let switches = token
        .windows(2)
        .filter(|pair| class(&pair[0]) != class(&pair[1]))
        .count();
    let digit_runs = token
        .split(|b| !b.is_ascii_digit())
        .filter(|run| !run.is_empty())
        .count();
    opaque(name)
        && ((token.len() >= 16 && token.iter().all(u8::is_ascii_hexdigit))
            || switches * 5 >= token.len() * 2
            || (token.len() >= 20 && digit_runs >= 2))
}

/// Equal normalised stems: only separators and case may differ (an added, removed or changed
/// word such as VI or Part A is another file), after the number-run check that stops separator
/// removal from merging different numbers (Show 1 10 is not Show 11 0).
///
/// PHP compares `mb_strtolower(Normalizer::normalize($stem, FORM_C))` with everything but letters,
/// marks and numbers removed. Canonical composition and Unicode case mapping are not ported, so a
/// non-ASCII stem that reaches this comparison is declined (None) and PHP decides; ASCII stems
/// are exact.
fn stems_agree(declared: &[u8], actual: &[u8]) -> Option<bool> {
    if !number_runs(declared).eq(number_runs(actual)) {
        return Some(false);
    }
    if !declared.is_ascii() || !actual.is_ascii() {
        return None;
    }
    let (expected, body) = (Normalized(declared), Normalized(actual));
    Some(expected.bytes().next().is_some() && expected.bytes().eq(body.bytes()))
}

/// `preg_match_all('/\p{N}+/u', $stem)`: maximal runs of Unicode numbers; none when the stem is
/// not valid UTF-8 (the match fails).
fn number_runs(stem: &[u8]) -> impl Iterator<Item = &str> {
    std::str::from_utf8(stem)
        .unwrap_or("")
        .split(|c: char| !in_ranges(NUMBERS, c as u32))
        .filter(|run| !run.is_empty())
}

/// `preg_match('/(?<![\p{L}\p{M}\p{N}_])'.preg_quote($actual).'(?![\p{L}\p{M}\p{N}_]|[.\-]+[\p{L}\p{N}])/iu',
/// $subject) === 1`. A character glued to the name on either side (a letter, mark, number or
/// underscore, or dashes and dots leading into a letter or number) means a longer name.
///
/// `matches` has already rejected an `actual` that is not valid UTF-8. `/iu` folds Unicode case
/// through PCRE's tables, which this port does not carry, so a valid non-ASCII `actual` is
/// declined (None) and PHP decides. For ASCII `actual` the only non-ASCII folds are
/// U+212A (K) for k and U+017F (long s) for s.
fn named_in_subject(subject: &str, actual: &[u8]) -> Option<bool> {
    if !actual.is_ascii() {
        return match std::str::from_utf8(actual) {
            Ok(_) => None,
            Err(_) => Some(false),
        };
    }
    let same = |subject_char: char, name_byte: u8| {
        subject_char.is_ascii() && subject_char.eq_ignore_ascii_case(&char::from(name_byte))
            || (subject_char == '\u{212a}' && name_byte.eq_ignore_ascii_case(&b'k'))
            || (subject_char == '\u{17f}' && name_byte.eq_ignore_ascii_case(&b's'))
    };
    let starts = subject
        .char_indices()
        .map(|(start, _)| start)
        .chain(std::iter::once(subject.len()));
    for start in starts {
        let mut rest = subject[start..].chars();
        if !actual
            .iter()
            .all(|byte| rest.next().is_some_and(|c| same(c, *byte)))
        {
            continue;
        }
        let before_ok = !subject[..start]
            .chars()
            .next_back()
            .is_some_and(is_word_or_mark);
        // (?![\p{L}\p{N}_]|[.\-]+[\p{L}\p{N}]): not a longer word, and not a longer file name.
        let after = rest.as_str();
        let separators = after.chars().take_while(|c| matches!(c, '.' | '-')).count();
        let after_ok = !after.chars().next().is_some_and(is_word_or_mark)
            && !(separators > 0
                && after
                    .chars()
                    .nth(separators)
                    .is_some_and(is_letter_or_number));
        if before_ok && after_ok {
            return Some(true);
        }
    }
    Some(false)
}

/// PHP `trim()` default character set.
fn trim(value: &[u8]) -> &[u8] {
    let is_trimmed = |byte: &u8| matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b);
    let start = value
        .iter()
        .position(|b| !is_trimmed(b))
        .unwrap_or(value.len());
    let end = value
        .iter()
        .rposition(|b| !is_trimmed(b))
        .map_or(start, |i| i + 1);
    &value[start..end]
}

/// PCRE `\s` without `/u` or locale tables: HT, LF, VT, FF, CR and space.
fn is_space(byte: u8) -> bool {
    matches!(byte, 9..=13 | 32)
}

/// `"([^"\r\n]+)"` starting at `open`: returns (value start, value end, match end).
fn double_quoted(bytes: &[u8], open: usize) -> Option<(usize, usize, usize)> {
    let start = open + 1;
    let length = bytes[start..]
        .iter()
        .position(|b| matches!(b, b'"' | b'\r' | b'\n'))
        .unwrap_or(bytes.len() - start);
    let end = start + length;
    (length > 0 && bytes.get(end) == Some(&b'"')).then_some((start, end, end + 1))
}

/// `(?<![\p{L}\p{N}_])'([^\r\n]+?)'(?=\s*+(?:\z|yEnc(?![\p{L}\p{M}\p{N}_])|\(\d+\/\d+\)|\[\d+\/\d+\]|
/// -\s*+(?:[\[(\"']|\z)|[\"']))` starting at `open`: the shortest non-empty run, free of line
/// breaks, whose closing quote is followed by a field boundary. The value may contain apostrophes
/// (O'Brien's, Guns N' Roses).
fn single_quoted(text: &str, open: usize) -> Option<(usize, usize, usize)> {
    if text[..open].chars().next_back().is_some_and(is_word) {
        return None;
    }
    let bytes = text.as_bytes();
    let start = open + 1;
    for end in start..bytes.len() {
        match bytes[end] {
            b'\r' | b'\n' => return None,
            b'\'' if end > start && closes_field(&text[end + 1..]) => {
                return Some((start, end, end + 1));
            }
            _ => {}
        }
    }
    None
}

/// The lookahead after a closing single quote: possessive whitespace, then the end, a case-exact
/// `yEnc` marker word, an (n/m) or [n/m] counter, a dash before another field, or another quote.
fn closes_field(after: &str) -> bool {
    let rest = after.trim_start_matches(is_ucp_space);
    if rest.is_empty() || rest.starts_with(['"', '\'']) {
        return true;
    }
    if let Some(tail) = rest.strip_prefix("yEnc") {
        return !tail.chars().next().is_some_and(is_word_or_mark);
    }
    if let Some(tail) = rest.strip_prefix('-') {
        let tail = tail.trim_start_matches(is_ucp_space);
        return tail.is_empty() || tail.starts_with(['[', '(', '"', '\'']);
    }
    let close = match rest.as_bytes().first() {
        Some(b'(') => ')',
        Some(b'[') => ']',
        _ => return false,
    };
    skip_decimal(&rest[1..])
        .and_then(|tail| tail.strip_prefix('/'))
        .and_then(skip_decimal)
        .is_some_and(|tail| tail.starts_with(close))
}

/// `[\p{L}\p{M}\p{N}_]`
fn is_word_or_mark(character: char) -> bool {
    is_word(character) || (!character.is_ascii() && in_ranges(MARKS, character as u32))
}

/// `[\p{L}\p{N}_]`
fn is_word(character: char) -> bool {
    character == '_' || is_letter_or_number(character)
}

/// `[\p{L}\p{N}]`
fn is_letter_or_number(character: char) -> bool {
    if character.is_ascii() {
        return character.is_ascii_alphanumeric();
    }
    let code = character as u32;
    in_ranges(LETTERS, code) || in_ranges(NUMBERS, code)
}

fn in_ranges(ranges: &[(u32, u32)], code: u32) -> bool {
    ranges
        .binary_search_by(|&(low, high)| {
            if code < low {
                std::cmp::Ordering::Greater
            } else if code > high {
                std::cmp::Ordering::Less
            } else {
                std::cmp::Ordering::Equal
            }
        })
        .is_ok()
}

/// `\A\s*(?:(?:\(\d+\/\d+\)|\[\d+\/\d+\])\s*)?yEnc(?![\p{L}\p{N}_])` with `/iu`: `\s` and `\d`
/// are Unicode-aware under `/u`, and the marker must be a whole word.
fn yenc_follows(tail: &[u8]) -> bool {
    let Ok(text) = std::str::from_utf8(tail) else {
        return false;
    };
    let mut rest = text.trim_start_matches(is_ucp_space);
    if let Some(close) = match rest.as_bytes().first() {
        Some(b'(') => Some(')'),
        Some(b'[') => Some(']'),
        _ => None,
    } {
        let Some(after_part) = skip_decimal(&rest[1..]) else {
            return false;
        };
        let Some(after_slash) = after_part.strip_prefix('/') else {
            return false;
        };
        let Some(after_total) = skip_decimal(after_slash) else {
            return false;
        };
        let Some(after_close) = after_total.strip_prefix(close) else {
            return false;
        };
        rest = after_close.trim_start_matches(is_ucp_space);
    }
    rest.as_bytes()
        .get(..4)
        .is_some_and(|word| word.eq_ignore_ascii_case(b"yenc"))
        && !rest[4..].chars().next().is_some_and(is_word_or_mark)
}

/// PCRE `\s` under `/u` (UCP).
fn is_ucp_space(character: char) -> bool {
    in_ranges(SPACES, character as u32)
}

/// The text after a non-empty run of `\d` (UCP decimal digits), or None without one.
fn skip_decimal(text: &str) -> Option<&str> {
    let rest = text.trim_start_matches(|c: char| in_ranges(DIGITS, c as u32));
    (rest.len() < text.len()).then_some(rest)
}

/// `\[\d+\/\d+\]\s*-?\s*\z` somewhere in `prefix` (the subject before a quoted value).
fn counter_precedes(prefix: &[u8]) -> bool {
    let mut end = prefix.len();
    while end > 0 && is_space(prefix[end - 1]) {
        end -= 1;
    }
    if end > 0 && prefix[end - 1] == b'-' {
        end -= 1;
        while end > 0 && is_space(prefix[end - 1]) {
            end -= 1;
        }
    }
    if end == 0 || prefix[end - 1] != b']' {
        return false;
    }
    end -= 1;
    let total = count_digits_backwards(&prefix[..end]);
    if total == 0 || end - total == 0 || prefix[end - total - 1] != b'/' {
        return false;
    }
    end -= total + 1;
    let part = count_digits_backwards(&prefix[..end]);
    part > 0 && end - part > 0 && prefix[end - part - 1] == b'['
}

fn count_digits_backwards(bytes: &[u8]) -> usize {
    bytes
        .iter()
        .rev()
        .take_while(|b| b.is_ascii_digit())
        .count()
}

const EXECUTABLE: &[&[u8]] = &[
    b"exe",
    b"scr",
    b"bat",
    b"cmd",
    b"com",
    b"msi",
    b"msix",
    b"msp",
    b"lnk",
    b"pif",
    b"cpl",
    b"hta",
    b"reg",
    b"scf",
    b"js",
    b"jse",
    b"vbs",
    b"vbe",
    b"wsf",
    b"wsh",
    b"ps1",
    b"psm1",
    b"jar",
    b"apk",
    b"dll",
    b"sh",
    b"command",
    b"app",
    b"dmg",
    b"pkg",
    b"chm",
    b"msc",
    b"ps1xml",
    b"psd1",
    b"cab",
    b"appx",
    b"appxbundle",
    b"msixbundle",
    b"application",
    b"gadget",
    b"inf",
    b"url",
    b"xll",
    b"ws",
    b"wsc",
    b"sct",
    b"vb",
    b"ocx",
    b"sys",
    b"iso",
    b"img",
    b"vhd",
    b"vhdx",
    b"desktop",
    b"appimage",
    b"deb",
    b"rpm",
    b"snap",
    b"run",
    b"msu",
    b"py",
    b"pyw",
    b"pl",
    b"rb",
    b"php",
    b"so",
    b"dylib",
    b"bundle",
    b"ko",
    b"node",
    b"class",
    b"wasm",
    b"pyc",
    b"pyo",
];

/// The name a filesystem writes, without trailing dots and spaces: `rtrim($name, ". \t")`.
fn trimmed(name: &[u8]) -> &[u8] {
    let end = name
        .iter()
        .rposition(|b| !matches!(b, b'.' | b' ' | b'\t'))
        .map_or(0, |i| i + 1);
    &name[..end]
}

/// The name an NTFS write keeps: `trimmed(preg_replace('/:.*\z/s', '', $name))`, i.e. without a
/// stream suffix (everything from the first colon).
fn bare(name: &[u8]) -> &[u8] {
    trimmed(&name[..name.iter().position(|b| *b == b':').unwrap_or(name.len())])
}

/// `\.(?:exe|scr|...|pkg)\z` (case-insensitive, no `/u`) on the trimmed or the bare name.
fn has_executable_suffix(name: &[u8]) -> bool {
    ends_with_executable_extension(trimmed(name)) || ends_with_executable_extension(bare(name))
}

fn ends_with_executable_extension(name: &[u8]) -> bool {
    name.iter().rposition(|b| *b == b'.').is_some_and(|dot| {
        EXECUTABLE
            .iter()
            .any(|extension| name[dot + 1..].eq_ignore_ascii_case(extension))
    })
}

/// `preg_match(SUFFIX, $piece) === 1`:
/// `\A(?:part\d+|vol\d+[+-]\d+|par2|rar|r\d{2,3}|\d{3}|7z|zip|tar|gz|tgz|xz|txz|bz2|tbz2|zst|zstd|
/// lz|lz4|lzma|br|mkv|avi|mp4|m4v|ts|nfo|sfv|nzb|epub|pdf|flac|mp3|iso|srt|txt|jpg|png)\z`, `/i`.
fn is_suffix_piece(piece: &[u8]) -> bool {
    const LITERALS: &[&[u8]] = &[
        b"par2", b"rar", b"7z", b"zip", b"tar", b"gz", b"tgz", b"xz", b"txz", b"bz2", b"tbz2",
        b"zst", b"zstd", b"lz", b"lz4", b"lzma", b"br", b"mkv", b"avi", b"mp4", b"m4v", b"ts",
        b"nfo", b"sfv", b"nzb", b"epub", b"pdf", b"flac", b"mp3", b"iso", b"srt", b"txt", b"jpg",
        b"png",
    ];
    let all_digits = |bytes: &[u8]| !bytes.is_empty() && bytes.iter().all(u8::is_ascii_digit);
    if LITERALS.iter().any(|word| piece.eq_ignore_ascii_case(word)) {
        return true;
    }
    if piece.len() > 4 && piece[..4].eq_ignore_ascii_case(b"part") {
        return all_digits(&piece[4..]);
    }
    if piece.len() > 3 && piece[..3].eq_ignore_ascii_case(b"vol") {
        let rest = &piece[3..];
        return rest
            .iter()
            .position(|b| matches!(b, b'+' | b'-'))
            .is_some_and(|sign| all_digits(&rest[..sign]) && all_digits(&rest[sign + 1..]));
    }
    if piece.len() >= 3 && piece[0].eq_ignore_ascii_case(&b'r') && piece.len() <= 4 {
        return all_digits(&piece[1..]);
    }
    piece.len() == 3 && all_digits(piece)
}

/// The name without its trailing chain of known archive and media suffixes: while the last
/// dot-separated piece is entirely one suffix alternative (`/i`), drop it. Linear time.
fn strip_suffixes(name: &[u8]) -> &[u8] {
    let mut end = name.len();
    while let Some(dot) = name[..end].iter().rposition(|b| *b == b'.') {
        if !is_suffix_piece(&name[dot + 1..end]) {
            break;
        }
        end = dot;
    }
    &name[..end]
}

/// The stem, or the name without an unlisted final extension such as .wmv when no known suffix
/// applies (a dotted component such as .S01E01 after a known suffix stays).
fn token(name: &[u8]) -> &[u8] {
    let stem = strip_suffixes(name);
    if stem.len() != name.len() {
        stem
    } else {
        strip_final_alphanumeric_extension(stem)
    }
}

/// A single random-looking token, such as an obfuscated posting name, makes no filename claim.
fn opaque(name: &[u8]) -> bool {
    let stem = token(name);
    if stem.is_empty() || !stem.iter().all(u8::is_ascii_alphanumeric) {
        return false;
    }
    if stem.len() >= 16 && stem.iter().all(u8::is_ascii_hexdigit) {
        return true;
    }
    // Capitalized words, optionally after a leading acronym or lowercase word, or one single-case
    // run, with only trailing digits, read as a run-together title rather than a random token.
    if titled(stem) {
        return false;
    }
    let classes = u32::from(stem.iter().any(u8::is_ascii_lowercase))
        + u32::from(stem.iter().any(u8::is_ascii_uppercase))
        + u32::from(stem.iter().any(u8::is_ascii_digit));
    stem.len() >= 10 && classes >= 2
}

/// `preg_replace('/\.[A-Za-z0-9]{1,8}\z/', '', $stem)`.
fn strip_final_alphanumeric_extension(stem: &[u8]) -> &[u8] {
    match stem.iter().rposition(|b| *b == b'.') {
        Some(dot)
            if (1..=8).contains(&(stem.len() - dot - 1))
                && stem[dot + 1..].iter().all(u8::is_ascii_alphanumeric) =>
        {
            &stem[..dot]
        }
        _ => stem,
    }
}

/// Whether an alphanumeric token reads as a run-together title (BBC2Documentary, 13GoingOn30,
/// iPhoneMovie): tokens of `/[A-Z]?[a-z]+|[A-Z]+(?![a-z])|[0-9]+/` are an optional leading
/// lowercase word, capitalized words, numbers and at most one acronym of 2 to 4 capitals, and
/// every word has a vowel (`y` counts).
fn titled(stem: &[u8]) -> bool {
    let (mut at, mut index, mut acronyms) = (0, 0, 0);
    let mut previous_is_digit = false;
    while at < stem.len() {
        let rest = &stem[at..];
        let length = if rest[0].is_ascii_digit() {
            rest.iter().take_while(|b| b.is_ascii_digit()).count()
        } else if rest[0].is_ascii_lowercase() {
            rest.iter().take_while(|b| b.is_ascii_lowercase()).count()
        } else {
            let capitals = rest.iter().take_while(|b| b.is_ascii_uppercase()).count();
            let lowercase_follows = rest.get(capitals).is_some_and(u8::is_ascii_lowercase);
            match (capitals, lowercase_follows) {
                // [A-Z][a-z]+: one capital and its lowercase run.
                (1, true) => {
                    1 + rest[1..]
                        .iter()
                        .take_while(|b| b.is_ascii_lowercase())
                        .count()
                }
                // [A-Z]+(?![a-z]) backs off one capital, which starts the next word.
                (_, true) => capitals - 1,
                _ => capitals,
            }
        };
        let token = &rest[..length];
        at += length;
        index += 1;
        let next_is_digit = stem.get(at).is_some_and(u8::is_ascii_digit);
        let was_digit = previous_is_digit;
        previous_is_digit = token[0].is_ascii_digit();
        // A one-letter label beside a number (S01E02, 4K, 3D) is a title component.
        if previous_is_digit
            || (token.len() == 1 && token[0].is_ascii_uppercase() && (next_is_digit || was_digit))
        {
            continue;
        }
        if token.len() > 1 && token.iter().all(u8::is_ascii_uppercase) {
            acronyms += 1;
            if acronyms > 1 || token.len() > 4 {
                return false;
            }
            continue;
        }
        let lowercase = token.iter().all(u8::is_ascii_lowercase);
        let vowel = token.iter().any(|b| b"aeiouyAEIOUY".contains(b));
        if (lowercase && index > 1) || !vowel {
            return false;
        }
    }
    !stem.is_empty()
}

/// `/\.([^.\s]+)\z/u`: the text after the last dot when it is non-empty and has no whitespace
/// (Unicode `\s`, since `/u` implies UCP), else empty. A name that is not valid UTF-8 makes the
/// pattern fail, which PHP reads as no extension.
fn final_extension(name: &[u8]) -> &[u8] {
    let Ok(text) = std::str::from_utf8(name) else {
        return &[];
    };
    match name.iter().rposition(|b| *b == b'.') {
        Some(dot) => {
            let tail = &text[dot + 1..];
            if tail.is_empty() || tail.chars().any(|c| in_ranges(SPACES, c as u32)) {
                &[]
            } else {
                tail.as_bytes()
            }
        }
        None => &[],
    }
}

/// The ASCII-only case of the normalisation, as a byte iterator.
struct Normalized<'a>(&'a [u8]);

impl Normalized<'_> {
    fn bytes(&self) -> impl Iterator<Item = u8> + '_ {
        self.0.iter().map(u8::to_ascii_lowercase).filter(|b| {
            b.is_ascii_lowercase() || b.is_ascii_digit() || matches!(b, b'+' | b'#' | b'&')
        })
    }
}

#[cfg(test)]
mod tests {
    use super::{is_letter_or_number, matches};

    fn verdict(subject: &str, actual: &str) -> bool {
        matches(subject.as_bytes(), actual.as_bytes()).expect("decided")
    }

    #[test]
    fn an_invalid_utf8_subject_is_rejected_like_a_failed_preg_match_all() {
        for subject in [
            &b"[1/2] - \"Readable.Release.Name\xff.mkv\" yEnc"[..],
            b"\"a\xc0\x80.mkv\"",
            b"\"a\xed\xa0\x80.mkv\"",
            b"\"a\xf4\x90\x80\x80.mkv\"",
            b"\"abc.mkv\" \xe2\x82",
            b"\xff",
        ] {
            assert_eq!(matches(subject, b"other.mkv"), Some(false), "{subject:?}");
            assert_eq!(matches(subject, subject), Some(false), "{subject:?}");
        }
        // A body name that is not valid UTF-8 is rejected up front, whatever the subject says.
        for (subject, actual) in [
            (&b"Readable Release yEnc (1/2)"[..], &b"setup\xff.exe"[..]),
            (b"[1/9] - \"Xk29dPq8LmZ0Yt5R.mkv\" yEnc", b"payload\xff.mkv"),
            (b"no quotes at all", b"\xc3"),
            (b"\"x\xff\"", b"x\xff"),
        ] {
            assert_eq!(matches(subject, actual), Some(false), "{actual:?}");
        }
        // Noncharacters and astral characters are valid UTF-8 to PCRE and Rust alike.
        assert_eq!(
            matches("\"abc.mkv\" \u{fffe}".as_bytes(), b"other.mkv"),
            Some(false)
        );
        assert_eq!(
            matches("abc other.mkv \u{1f600}".as_bytes(), b"other.mkv"),
            Some(true)
        );
    }

    /// PHP's verdicts over the deterministic synthetic corpus, regenerated with
    /// scripts/generate-header-filename-golden.php and checked against live PHP by
    /// tests/Unit/HeaderFilenameIdentityNativeTest.php.
    #[test]
    fn matches_php_verdicts_over_the_golden_corpus() {
        let fixture: serde_json::Value =
            serde_json::from_str(include_str!("../fixtures/header-filename-identity-v1.json"))
                .expect("golden fixture is valid JSON");
        assert_eq!(fixture["format"], "header-filename-identity-v1");
        let cases = fixture["cases"].as_array().expect("cases");
        assert!(cases.len() > 1500);
        // The leading cases are tests/Unit/HeaderFilenameIdentityTest.php's own rows, in order
        // (the PHP parity test pins this count to the live data provider).
        assert!(
            fixture["unit_cases"]
                .as_u64()
                .is_some_and(|count| count > 100)
        );
        let decode = |value: &serde_json::Value| -> Vec<u8> {
            let text = value.as_str().expect("base64 string").as_bytes();
            let mut output = Vec::new();
            let (mut buffer, mut bits) = (0u32, 0);
            for byte in text.iter().filter(|b| **b != b'=') {
                let sextet = match byte {
                    b'A'..=b'Z' => byte - b'A',
                    b'a'..=b'z' => byte - b'a' + 26,
                    b'0'..=b'9' => byte - b'0' + 52,
                    b'+' => 62,
                    b'/' => 63,
                    other => panic!("bad base64 byte {other}"),
                };
                buffer = (buffer << 6) | u32::from(sextet);
                bits += 6;
                if bits >= 8 {
                    bits -= 8;
                    output.push((buffer >> bits) as u8);
                    buffer &= (1 << bits) - 1;
                }
            }
            output
        };
        let (mut accepted, mut rejected, mut declined) = (0, 0, 0);
        for (index, case) in cases.iter().enumerate() {
            let (subject, actual) = (decode(&case[0]), decode(&case[1]));
            let expected = case[2].as_bool().expect("verdict");
            let Some(verdict) = matches(&subject, &actual) else {
                assert!(
                    !subject.is_ascii() || !actual.is_ascii(),
                    "case {index} declined with ASCII input"
                );
                declined += 1;
                continue;
            };
            assert_eq!(
                verdict,
                expected,
                "case {index}: {:?} / {:?}",
                String::from_utf8_lossy(&subject),
                String::from_utf8_lossy(&actual)
            );
            if expected {
                accepted += 1;
            } else {
                rejected += 1;
            }
        }
        assert!(accepted > 500 && rejected > 500, "{accepted}/{rejected}");
        // Only non-ASCII input is ever declined (Unicode case folding and NFC are left to PHP).
        assert!(
            declined > 0 && declined * 4 < cases.len(),
            "declined {declined}"
        );
    }

    #[test]
    fn unicode_tables_cover_letters_and_numbers_only() {
        for character in [
            'a', 'Z', '0', '9', '\u{e9}', '\u{416}', '\u{4e2d}', '\u{661}', '\u{2160}', '\u{ff21}',
        ] {
            assert!(is_letter_or_number(character), "{character}");
        }
        for character in [
            '_',
            '-',
            ' ',
            '\'',
            '\u{301}',
            '\u{2014}',
            '\u{1f600}',
            '\u{fffd}',
            '\u{a0}',
        ] {
            assert!(!is_letter_or_number(character), "{character}");
        }
    }

    #[test]
    fn linear_scans_survive_adversarial_chains() {
        let chain = ".mkv".repeat(20_000);
        assert!(!verdict(&format!("\"abcdefgh{chain}x\""), "zzzzzzzz.mkv"));
        assert!(verdict(
            &format!("\"abcdefgh{chain}\""),
            &format!("abcdefgh{chain}")
        ));
        assert!(!verdict(&"'a'a".repeat(10_000), "x"));
    }
}
