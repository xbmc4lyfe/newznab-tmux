//! Whether a spot-checked article's yEnc filename agrees with the filename its subject declares.
//! Part, total, size, range and CRC checks already tie the article to its header, so this only
//! rejects a readable declared name that the body contradicts; an obfuscated subject declares none.

use std::sync::LazyLock;

use fancy_regex::Regex;
use unicode_normalization::UnicodeNormalization;

fn re(pattern: &str) -> Regex {
    Regex::new(pattern).expect("a valid identity pattern")
}

fn found(regex: &Regex, text: &str) -> bool {
    regex.is_match(text).unwrap_or(false)
}

/// For a pattern PHP ran without `/u`: its case folding never reached a non-ASCII byte, where this
/// engine would fold K to the Kelvin sign and s to the long s.
fn found_ascii(regex: &Regex, text: &str) -> bool {
    if text.is_ascii() {
        return found(regex, text);
    }
    let masked: String = text
        .chars()
        .map(|c| if c.is_ascii() { c } else { '\u{FFFD}' })
        .collect();
    found(regex, &masked)
}

// A single-quoted name may contain apostrophes (O'Brien's, Guns N' Roses, Directors' (2026) Cut): it
// closes at the first quote followed by a field boundary: the end, yEnc, an (n/m) or [n/m] counter, a
// dash before another field, or another quote.
static QUOTED: LazyLock<Regex> = LazyLock::new(|| {
    re(
        r#""([^"\r\n]+)"|(?<![\p{L}\p{N}_])'([^\r\n]+?)'(?=\s*+(?:\z|yEnc(?![\p{L}\p{M}\p{N}_])|\(\d+/\d+\)|\[\d+/\d+\]|-\s*+(?:[\[("']|\z)|["']))"#,
    )
});
static SUFFIX: LazyLock<Regex> = LazyLock::new(|| {
    re(
        r"(?i)\A(?:part[0-9]+|vol[0-9]+[+-][0-9]+|par2|rar|r[0-9]{2,3}|[0-9]{3}|7z|zip|tar|gz|tgz|xz|txz|bz2|tbz2|zst|zstd|lz|lz4|lzma|br|mkv|avi|mp4|m4v|ts|nfo|sfv|nzb|epub|pdf|flac|mp3|iso|srt|txt|jpg|png)\z",
    )
});
static EXECUTABLE: LazyLock<Regex> = LazyLock::new(|| {
    re(
        r"(?i)\.(?:exe|scr|bat|cmd|com|msi|msix|msp|lnk|pif|cpl|hta|reg|scf|js|jse|vbs|vbe|wsf|wsh|ps1|psm1|jar|apk|dll|sh|command|app|dmg|pkg|chm|msc|ps1xml|psd1|cab|appx|appxbundle|msixbundle|application|gadget|inf|url|xll|ws|wsc|sct|vb|ocx|sys|iso|img|vhd|vhdx|desktop|appimage|deb|rpm|snap|run|msu|py|pyw|pl|rb|php|so|dylib|bundle|ko|node|class|wasm|pyc|pyo)\z",
    )
});
static MARKER: LazyLock<Regex> =
    LazyLock::new(|| re(r"(?i)(?<![\p{L}\p{M}\p{N}_])yEnc(?![\p{L}\p{M}\p{N}_])"));
static YENC_AFTER: LazyLock<Regex> =
    LazyLock::new(|| re(r"(?i)\A\s*(?:(?:\(\d+/\d+\)|\[\d+/\d+\])\s*)?yEnc(?![\p{L}\p{M}\p{N}_])"));
static COUNTER_BEFORE: LazyLock<Regex> =
    LazyLock::new(|| re(r"\[[0-9]+/[0-9]+\][ \t\n\x0B\f\r]*+(?:-[ \t\n\x0B\f\r]*+)?\z"));
static PAR2: LazyLock<Regex> = LazyLock::new(|| re(r"(?i)\.par2\z"));
static PAYLOAD_EXTENSION: LazyLock<Regex> = LazyLock::new(|| re(r"\.[\p{L}\p{N}]{1,8}\z"));
static LETTER: LazyLock<Regex> = LazyLock::new(|| re(r"\p{L}"));
static LAST_EXTENSION: LazyLock<Regex> = LazyLock::new(|| re(r"\.([^.\s]+)\z"));
static TERMINAL_EXTENSION: LazyLock<Regex> = LazyLock::new(|| re(r"\.[A-Za-z0-9]{1,8}\z"));
static ALNUM: LazyLock<Regex> = LazyLock::new(|| re(r"\A[A-Za-z0-9]+\z"));
static HEX_16: LazyLock<Regex> = LazyLock::new(|| re(r"(?i)\A[0-9a-f]{16,}\z"));
static SWITCH: LazyLock<Regex> =
    LazyLock::new(|| re(r"[a-z](?=[A-Z0-9])|[A-Z](?=[a-z0-9])|[0-9](?=[A-Za-z])"));
static DIGIT_RUN: LazyLock<Regex> = LazyLock::new(|| re(r"[0-9]+"));
static TITLE_PART: LazyLock<Regex> = LazyLock::new(|| re(r"[A-Z]?[a-z]+|[A-Z]+(?![a-z])|[0-9]+"));
static NUMBER: LazyLock<Regex> = LazyLock::new(|| re(r"\p{N}+"));
static NOT_TITLE: LazyLock<Regex> = LazyLock::new(|| re(r"[^\p{L}\p{M}\p{N}+#&]"));

/// Filesystem writes drop trailing dots and spaces.
fn trimmed(name: &str) -> &str {
    name.trim_end_matches(['.', ' ', '\t'])
}

/// The name an NTFS write keeps, without a stream suffix after the first colon.
fn bare(name: &str) -> &str {
    trimmed(name.split(':').next().unwrap_or(name))
}

fn extension(name: &str) -> &str {
    match LAST_EXTENSION.captures(name) {
        Ok(Some(captures)) => captures.get(1).map_or("", |group| group.as_str()),
        _ => "",
    }
}

/// The name without its trailing chain of known archive and media suffixes.
fn stem(name: &str) -> &str {
    let mut stem = name;
    while let Some(dot) = stem.rfind('.') {
        if !found_ascii(&SUFFIX, &stem[dot + 1..]) {
            break;
        }
        stem = &stem[..dot];
    }
    stem
}

fn suffix(name: &str) -> &str {
    &name[stem(name).len()..]
}

/// The stem, or the name without an unlisted final extension such as `.wmv` when no known suffix
/// applies. Only the original terminal extension may be unlisted: a dotted `.S01E01` stays.
fn token(name: &str) -> &str {
    let stemmed = stem(name);
    if stemmed.len() != name.len() {
        return stemmed;
    }
    match TERMINAL_EXTENSION.find(stemmed) {
        Ok(Some(found)) => &stemmed[..found.start()],
        _ => stemmed,
    }
}

fn fold(text: &str) -> String {
    text.nfc().collect::<String>().to_lowercase()
}

/// Whether a token reads as a run-together title (BBC2Documentary, 13GoingOn30, S01E02Movie): it
/// splits into an optional leading lowercase word, capitalized words, numbers, one-letter labels
/// before numbers and at most one short acronym, and every word has a vowel.
fn titled(stem: &str) -> bool {
    let parts: Vec<&str> = TITLE_PART
        .find_iter(stem)
        .flatten()
        .map(|part| part.as_str())
        .collect();
    let digits =
        |part: Option<&&str>| part.is_some_and(|part| part.bytes().all(|b| b.is_ascii_digit()));
    let mut acronyms = 0;
    for (index, part) in parts.iter().enumerate() {
        let all_upper = part.bytes().all(|b| b.is_ascii_uppercase());
        // A one-letter label beside a number (S01E02, 4K, 3D) is a title component.
        let previous = index.checked_sub(1).and_then(|at| parts.get(at));
        if part.bytes().all(|b| b.is_ascii_digit())
            || (part.len() == 1 && all_upper && (digits(parts.get(index + 1)) || digits(previous)))
        {
            continue;
        }
        if all_upper && part.len() > 1 {
            acronyms += 1;
            if acronyms > 1 || part.len() > 4 {
                return false;
            }
            continue;
        }
        let all_lower = part.bytes().all(|b| b.is_ascii_lowercase());
        let vowel = part.bytes().any(|b| b"aeiouyAEIOUY".contains(&b));
        if (all_lower && index > 0) || !vowel {
            return false;
        }
    }
    !parts.is_empty()
}

/// A single random-looking token, such as an obfuscated posting name, makes no filename claim.
fn opaque(name: &str) -> bool {
    let stem = token(name);
    if !found(&ALNUM, stem) {
        return false;
    }
    if found(&HEX_16, stem) {
        return true;
    }
    if titled(stem) {
        return false;
    }
    let classes = u8::from(stem.bytes().any(|b| b.is_ascii_lowercase()))
        + u8::from(stem.bytes().any(|b| b.is_ascii_uppercase()))
        + u8::from(stem.bytes().any(|b| b.is_ascii_digit()));
    stem.len() >= 10 && classes >= 2
}

/// An opaque token that's long, hexadecimal or densely mixed, too random to be a misread title.
/// Random tokens switch between lowercase, uppercase and digits at nearly every other character. A
/// long token counts only with separate digit runs, since a long title has at most one number.
fn random(name: &str) -> bool {
    let token = token(name);
    let switches = SWITCH.find_iter(token).flatten().count();
    opaque(name)
        && (found(&HEX_16, token)
            || switches as f64 >= 0.4 * token.len() as f64
            || (token.len() >= 20 && DIGIT_RUN.find_iter(token).flatten().count() >= 2))
}

fn executable(name: &str) -> bool {
    found_ascii(&EXECUTABLE, trimmed(name)) || found_ascii(&EXECUTABLE, bare(name))
}

pub fn is_par2_filename(name: &str) -> bool {
    found_ascii(&PAR2, trimmed(name)) || found_ascii(&PAR2, bare(name))
}

/// Whether a sampled article name is evidence of a source payload: a non-PAR2 name with an extension,
/// or a readable extensionless name such as README, but never an obfuscated extensionless token.
pub fn proves_payload(name: &str) -> bool {
    if is_par2_filename(name) {
        return false;
    }
    let bare = bare(name);
    found(&PAYLOAD_EXTENSION, trimmed(name))
        || found(&PAYLOAD_EXTENSION, bare)
        || (!bare.is_empty() && !bare.contains('.') && !opaque(bare) && found(&LETTER, bare))
}

#[derive(PartialEq, Eq, Clone, Copy)]
enum Parity {
    Parity,
    Payload,
}

/// Parity when either filesystem form is a PAR2 name, payload when the name has another extension or
/// is a readable extensionless payload, and `None` when it declares neither.
fn parity_class(name: &str) -> Option<Parity> {
    if is_par2_filename(name) {
        return Some(Parity::Parity);
    }
    if !extension(trimmed(name)).is_empty()
        || !extension(bare(name)).is_empty()
        || proves_payload(name)
    {
        return Some(Parity::Payload);
    }
    None
}

fn numbers(name: &str) -> Vec<&str> {
    NUMBER
        .find_iter(stem(name))
        .flatten()
        .map(|number| number.as_str())
        .collect()
}

fn normalized(name: &str) -> String {
    // Separators are interchangeable, but symbols such as + # & distinguish titles (C++, F#).
    NOT_TITLE.replace_all(&fold(stem(name)), "").into_owned()
}

/// Whether `actual`, the article's name, agrees with what `subject` declares. Article names are
/// validated as UTF-8 before this check.
pub fn matches(subject: &str, actual: &str, is_par2_packet: bool) -> bool {
    let subject = crate::nzb::fingerprint::php_trim(subject);
    if is_par2_packet && parity_class(actual) == Some(Parity::Payload) {
        return false;
    }
    // A regex that gives up has no answer. PHP's preg_match_all returned false there, and so does this.
    let Ok(quoted_matches) = QUOTED
        .captures_iter(subject)
        .map(|captures| {
            let captures = captures?;
            let whole = captures.get(0).expect("group 0 always exists");
            let value = captures
                .get(1)
                .or_else(|| captures.get(2))
                .map_or("", |group| group.as_str());
            Ok((whole.start(), whole.end(), value.to_owned()))
        })
        .collect::<Result<Vec<(usize, usize, String)>, fancy_regex::Error>>()
    else {
        return false;
    };
    // One yEnc marker ends the declaration: a second is ambiguous, and quotes after it are metadata.
    // Markers count only outside quoted names, so a filename may contain the word yEnc.
    let mut unquoted = subject.to_owned();
    for (start, end, _) in &quoted_matches {
        unquoted.replace_range(*start..*end, &" ".repeat(end - start));
    }
    let Ok(markers) = MARKER
        .find_iter(&unquoted)
        .map(|marker| marker.map(|found| found.start()))
        .collect::<Result<Vec<usize>, _>>()
    else {
        return false;
    };
    if markers.len() > 1 {
        return false;
    }
    let marker = markers.first().copied().unwrap_or(subject.len());
    let quoted: Vec<&(usize, usize, String)> = quoted_matches
        .iter()
        .filter(|(_, end, _)| *end <= marker)
        .collect();
    if quoted.is_empty() {
        // An unquoted subject declares a file only by naming it before the marker, not a longer file
        // such as body.exe.mkv.
        let pattern = format!(
            r"(?i)(?<![\p{{L}}\p{{M}}\p{{N}}_]){}(?![\p{{L}}\p{{M}}\p{{N}}_]|[.\-]+[\p{{L}}\p{{N}}])",
            fancy_regex::escape(trimmed(actual))
        );
        return Regex::new(&pattern).is_ok_and(|regex| found(&regex, &subject[..marker]));
    }
    let mut declared: Option<&str> = None;
    let mut after_counter: Option<&str> = None;
    for (start, end, value) in &quoted {
        // The filename is the quoted value that yEnc follows, optionally after a counter, else the
        // one after a [n/m] counter.
        let Ok(followed) = YENC_AFTER.is_match(&subject[*end..]) else {
            return false;
        };
        if followed {
            // Two quoted names each marked by yEnc leave the article's filename ambiguous.
            if declared.is_some() {
                return false;
            }
            declared = Some(value);
        }
        if after_counter.is_none() {
            let Ok(counted) = COUNTER_BEFORE.is_match(&subject[..*start]) else {
                return false;
            };
            if counted {
                after_counter = Some(value);
            }
        }
    }
    let last = quoted.last().map_or("", |(_, _, value)| value.as_str());
    let declared = declared.or(after_counter).unwrap_or(last);
    // An executable body must be exactly the executable type its subject declares, even when another
    // quoted value names it.
    if executable(actual)
        && (!executable(declared)
            || fold(extension(bare(actual))) != fold(extension(bare(declared)))
            || fold(extension(trimmed(actual))) != fold(extension(trimmed(declared))))
    {
        return false;
    }
    // Parity and payload aren't interchangeable: a declared PAR2 file isn't payload evidence, and a
    // declared payload file isn't satisfied by a PAR2 body, even when another quoted value names it.
    let declared_class = parity_class(declared);
    let body_class = if is_par2_packet {
        Some(Parity::Parity)
    } else {
        parity_class(actual)
    };
    if let (Some(left), Some(right)) = (declared_class, body_class)
        && left != right
    {
        return false;
    }
    if quoted.iter().any(|(_, _, value)| value == actual) {
        return true;
    }
    // A long, hexadecimal or densely mixed token is plainly random. A weaker one could be a misread
    // title (XFilesMovie), so it admits only a plainly random body or one extending the declared token.
    if opaque(declared) {
        return random(declared)
            || random(actual)
            || bare(actual)
                .to_ascii_lowercase()
                .starts_with(&token(declared).to_ascii_lowercase());
    }
    // A readable declared name must agree on its volume and extension chain, and on its final
    // extension when that chain doesn't cover it, as well as its stem.
    if fold(suffix(declared)) != fold(suffix(actual))
        || fold(extension(declared)) != fold(extension(actual))
    {
        return false;
    }
    let expected = normalized(declared);
    let body = normalized(actual);
    // Removing separators must not merge different numbers: Show 1 10 isn't Show 11 0. Only separators
    // and case may differ: an added, removed or changed word (VI, Part A) is another file.
    !expected.is_empty()
        && !body.is_empty()
        && numbers(declared) == numbers(actual)
        && expected == body
}
