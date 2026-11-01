//! Filename cleaning and the literal filename evidence PreDB identity rests on. Cleaning only
//! nominates: identity is established by [`OriginalFilenameEvidence::accepts`].

use std::sync::LazyLock;

use nntmux_categorize::names::{is_hashed_or_gibberish, unwrap_nzbsplit};
use nntmux_categorize::pcre::{PHP_WS, contains_ci, replace_all, trim_chars};
use nntmux_categorize::{mi, ms, rx};
use regex::Regex;
use regex::bytes::Regex as BytesRegex;

use crate::season::is_season_pack;

static CONTROL: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"\p{C}").expect("static pattern"));
static NUMERIC_ONLY: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"\A[\p{N}\p{P}\p{Z}\s]+\z").expect("static pattern"));
static TECHNICAL_SHAPE: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"\A([\p{L}\p{N}][\p{L}\p{N}\p{M}._-]*)-[A-Za-z0-9]{2,15}\z")
        .expect("static pattern")
});

/// `\p{C}` anywhere in the value.
pub fn has_control(value: &str) -> bool {
    CONTROL.is_match(value)
}

/// The search document's identity: `mb_strtolower`. Only letter case may differ.
pub fn exact_value(value: &str) -> String {
    value.to_lowercase()
}

fn archive_patterns() -> [&'static BytesRegex; 11] {
    [
        rx!(i r"\.part\d{1,4}\.rar$"),
        rx!(i r"\.r\d{2,4}$"),
        rx!(i r"\.rar$"),
        rx!(i r"\.z\d{2}$"),
        rx!(i r"\.zip$"),
        rx!(i r"\.7z\.\d{3}$"),
        rx!(i r"\.7z$"),
        rx!(i r"\.vol\d+[\+\-]\d+\.par2?$"),
        rx!(i r"\.par2?$"),
        rx!(i r"\.(tar|gz|bz2|xz|lz|lzma|cab|arj|ace|arc)$"),
        rx!(i r"\.\d{3}$"),
    ]
}

/// Video, audio, image, ebook, game and subtitle extensions, in the order they're stripped.
fn payload_patterns() -> [&'static BytesRegex; 6] {
    [
        rx!(i r"\.(mkv|avi|mp4|m4v|wmv|mpg|mpeg|mov|ts|m2ts|vob|divx|flv|webm|ogv|3gp|asf|rm|rmvb|f4v)$"),
        audio_extensions(),
        rx!(i r"\.(nfo|sfv|nzb|srr|srs|jpg|jpeg|png|gif|bmp|tiff?|webp|pdf|txt|diz|md5|sha1|cue|log)$"),
        rx!(i r"\.(epub|mobi|azw3?|pdf|djvu|cbr|cbz|fb2|lit|prc|opf)$"),
        rx!(i r"\.(iso|bin|cue|mdf|mds|nrg|img|ccd|sub|exe|msi|dmg|pkg|apk|xap|appx|deb|rpm)$"),
        rx!(i r"\.(srt|sub|idx|ass|ssa|vtt|sup)$"),
    ]
}

fn audio_extensions() -> &'static BytesRegex {
    rx!(i r"\.(mp3|flac|m4a|aac|ogg|wav|wma|ape|opus|mka|ac3|dts|eac3|truehd|mpc|shn|tak|tta|wv)$")
}

fn archive_suffix_patterns() -> Vec<&'static BytesRegex> {
    let mut patterns = vec![rx!(i r"\.7z\.\d{2,4}$"), rx!(i r"\.part\d+\.rar$")];
    patterns.extend(archive_patterns());
    patterns.push(rx!(i r"\.(zst|tgz|lz4|enc|rev)$"));
    patterns
}

/// Strips archive wrappers and part markers repeatedly, then at most one payload extension, so
/// a title that ends in a token that's also an extension (`Movie.2020.1080p.DTS.mkv`) keeps it.
pub fn strip_file_suffixes(file_name: &str) -> String {
    strip_known_suffixes(file_name, true)
}

/// Validates only bytes appended after an already confirmed complete canonical title.
pub fn is_known_file_suffix(suffix: &str) -> bool {
    suffix.is_empty() || strip_known_suffixes(&format!("Title{suffix}"), false) == "Title"
}

fn strip_known_suffixes(file_name: &str, preserve_codec_after_wrapper: bool) -> String {
    // PCRE's `$` matches before a final newline without consuming it.
    if let Some(line) = file_name.strip_suffix('\n') {
        return strip_known_suffixes(line, preserve_codec_after_wrapper) + "\n";
    }
    let mut wrappers = archive_suffix_patterns();
    wrappers.push(rx!(i r"\.vol\d+[+-]\d+$"));
    wrappers.push(rx!(i r"\.part\d*$"));
    wrappers.push(rx!(i r"\.(nfo|sfv|nzb|srr|srs)$"));

    let mut name = file_name.to_owned();
    loop {
        let previous = name.clone();
        for re in &wrappers {
            name = replace_all(re, &name, "");
        }
        if name == previous {
            break;
        }
    }

    // After a wrapper came off, a trailing codec word is part of the title, not an extension.
    if preserve_codec_after_wrapper
        && name != file_name
        && (audio_extensions().is_match(name.as_bytes())
            || mi!(
                name,
                r"\.(ts|divx|wmv|mpg|mpeg|rm|rmvb|m1v|m2v|mpv|mpgv|mp2|m4b|aiff?|amr|ra|w64)$"
            ))
    {
        return name;
    }

    for re in payload_patterns() {
        let stripped = replace_all(re, &name, "");
        if stripped != name {
            return stripped;
        }
    }
    replace_all(
        rx!(i r"\.(docx?|rtf|xlsx?|pptx?|odt|m3u8?|txt|m4b|aiff?|amr|m2v|m1v|mpv|mpgv|qt|mp2|ra|w64|ifo|mks|ogm)$"),
        &name,
        "",
    )
}

/// Literal spelling is kept for nomination; generic cleaning only validates usability.
pub fn clean_for_filename_discovery(evidence: &OriginalFilenameEvidence) -> Option<String> {
    clean_for_matching(&evidence.stem, true).map(|_| evidence.stem.clone())
}

/// Cleans a filename for PreDB matching; `None` when it's unusable.
pub fn clean_for_matching(file_name: &str, preserve_leading_number: bool) -> Option<String> {
    if file_name
        .chars()
        .any(|c| !c.is_ascii() && CONTROL.is_match(c.encode_utf8(&mut [0; 4])))
    {
        return None;
    }
    // Keep ASCII control cleanup without erasing valid Unicode spelling into another title.
    let name = replace_all(rx!(r"[\x00-\x1F\x7F]"), file_name, "");
    if name.is_empty() || name.starts_with('.') {
        return None;
    }
    let name = strip_literal_subject_trailer(&name);
    let name = extract_filename_from_path(&name).to_owned();
    let mut name = unwrap_nzbsplit(&name).unwrap_or(name);
    if is_ignorable_for_matching(&name) {
        return None;
    }
    name = replace_all(
        rx!(i r"[\.\-_](sample|proof|subs?|thumbs?|cover|screens?)[\.\-_]?$"),
        &name,
        "",
    );
    if name.contains('.') {
        for re in archive_patterns() {
            name = replace_all(re, &name, "");
        }
        for re in payload_patterns() {
            name = replace_all(re, &name, "");
        }
    }
    name = replace_all(rx!(i r"[\.\-_]?(part|vol|cd|dvd|disc|disk)\d*$"), &name, "");
    if !preserve_leading_number {
        name = strip_leading_track_number(&name);
    }
    let name = trim_chars(&name, " \t\n\r\0\x0B.-_");
    if name.is_empty() || looks_like_structural_junk(name) {
        return None;
    }
    Some(name.to_owned())
}

/// `^\d{1,3}[\.\-_\s]+(?=[A-Za-z])` removed.
fn strip_leading_track_number(name: &str) -> String {
    match rx!(r"^\d{1,3}[\.\-_\s]+[A-Za-z]").find(name.as_bytes()) {
        Some(found) => name[found.end() - 1..].to_owned(),
        None => name.to_owned(),
    }
}

/// Numeric-only aliases can't establish a canonical release identity.
pub fn matches_predb_filename_evidence(file_name: &str, query: &str) -> bool {
    clean_for_matching(file_name, true).is_some_and(|cleaned| {
        !NUMERIC_ONLY.is_match(&cleaned) && exact_value(&cleaned) == exact_value(query)
    })
}

pub fn extract_filename_from_path(path: &str) -> &str {
    match path.rfind(['\\', '/']) {
        Some(index) if index + 1 < path.len() && !path[index + 1..].contains('\n') => {
            &path[index + 1..]
        }
        _ => path,
    }
}

pub fn normalize_candidate_title(title: &str) -> String {
    let trimmed = trim_chars(title, PHP_WS).to_owned();
    let t = unwrap_nzbsplit(&trimmed).unwrap_or(trimmed);
    let t = replace_all(
        rx!(i r"\.(mkv|avi|mp4|m4v|mpg|mpeg|wmv|flv|mov|ts|vob|iso|divx)$"),
        &t,
        "",
    );
    let t = replace_all(
        rx!(i r"\.(par2?|nfo|sfv|nzb|rar|zip|7z|gz|tar|bz2|xz|r\d{2,3}|\d{3}|pkg|exe|msi|jpe?g|png|gif|bmp|pdf|epub|mobi|azw3?|djvu|cbr|cbz|fb2|lit|prc|opf|txt|log)$"),
        &t,
        "",
    );
    let t = replace_all(rx!(i r"[.\-_ ](?:part|vol|r)\d+(?:\+\d+)?$"), &t, "");
    let t = replace_all(rx!(r"[\s_]+"), &t, " ");
    trim_chars(&t, " .-_\t\r\n").to_owned()
}

/// Formats a candidate for storage in searchname.
pub fn format_search_name(title: &str, normalized_fallback: Option<&str>) -> String {
    let fallback =
        || normalized_fallback.map_or_else(|| normalize_candidate_title(title), str::to_owned);
    let trimmed = trim_chars(title, PHP_WS).to_owned();
    let mut formatted = unwrap_nzbsplit(&trimmed).unwrap_or(trimmed);
    for re in payload_patterns() {
        formatted = replace_all(re, &formatted, "");
    }
    for re in archive_patterns() {
        formatted = replace_all(re, &formatted, "");
    }
    let formatted = replace_all(
        rx!(i r"[.\-_ ](?:part|vol|r)\d+(?:\+\d+)?$"),
        &formatted,
        "",
    );
    let formatted = trim_chars(&formatted, " .-_\t\r\n");
    if formatted.is_empty() || !looks_like_scene_release(formatted) {
        return fallback();
    }
    let dotted = replace_all(rx!(r"[ _]+"), formatted, ".");
    let dotted = replace_all(rx!(r"\.{2,}"), &dotted, ".");
    trim_chars(&dotted, " .-_\t\r\n").to_owned()
}

/// Whether a title looks like a valid release name.
pub fn is_plausible_release_title(title: &str) -> bool {
    let t = trim_chars(title, PHP_WS);
    if t.len() < 12 || rx!(r"[A-Za-z0-9]{3,}").find_iter(t.as_bytes()).count() < 2 {
        return false;
    }
    if is_hashed_or_gibberish(t)
        || mi!(t, r"(?:^|[.\-_ ])(?:part|vol|r)\d+(?:\+\d+)?$")
        || mi!(
            t,
            r"^(setup|install|installer|patch|update|crack|keygen)\d*[\s._-]"
        )
    {
        return false;
    }
    if ms!(t, r"[-.][A-Za-z0-9]{2,}$") {
        return true;
    }
    let has_tv = mi!(t, r"\bS\d{1,2}[Eex]\d{1,3}\b") || is_season_pack(t);
    let has_quality = mi!(
        t,
        r"\b(480p|720p|1080p|2160p|4k|webrip|web[ .-]?dl|bluray|bdrip|dvdrip|hdtv|hdrip|xvid|x264|x265|hevc|h\.?264|ts|cam|r5|proper|repack)\b"
    );
    mi!(t, r"\bXXX\b") || has_quality || has_tv
}

pub fn clean_for_title_match(filename: &str) -> String {
    let name = unwrap_nzbsplit(filename).unwrap_or_else(|| filename.to_owned());
    let clean = replace_all(
        rx!(i r"\.(mkv|avi|mp4|m4v|wmv|mpg|mpeg|mov|ts|m2ts|vob|divx|flv|nfo|sfv|nzb|srr|srs|rar|r\d{2,4}|zip|7z|par2?|vol\d+[\+\-]\d+|\d{3})$"),
        &name,
        "",
    );
    let clean = replace_all(
        rx!(i r"[\.\-_]?(part|vol|cd|dvd|disc|disk)\d+$"),
        &clean,
        "",
    );
    let clean = replace_all(rx!(i r"[\.\-_](sample|proof|subs?)$"), &clean, "");
    trim_chars(&clean, " \t\n\r\0\x0B.-_").to_owned()
}

pub fn looks_like_scene_release(filename: &str) -> bool {
    let name = unwrap_nzbsplit(filename).unwrap_or_else(|| filename.to_owned());
    let base = replace_all(rx!(i r"\.[a-z0-9]{2,4}$"), &name, "");
    if !ms!(base, r"\-[A-Za-z0-9]{2,15}$") || !ms!(base, r"[._-]") {
        return false;
    }
    const TAGS: [&str; 29] = [
        "720p", "1080p", "2160p", "4k", "x264", "x265", "hevc", "xvid", "divx", "bluray", "bdrip",
        "dvdrip", "hdtv", "webrip", "web-dl", "webdl", "aac", "ac3", "dts", "flac", "mp3",
        "proper", "repack", "internal", "retail", "pal", "ntsc", "multi", "dual",
    ];
    TAGS.iter().any(|tag| contains_ci(&base, tag))
        || mi!(base, r"s\d{1,2}e\d{1,3}")
        || mi!(base, r"[._-](19|20)\d{2}[._-]")
}

/// Keeps only complete technical scene titles eligible for literal byte preservation.
pub fn is_literal_technical_scene_title(title: &str) -> bool {
    if title.chars().count() > 255 || has_control(title) {
        return false;
    }
    let Some(parts) = TECHNICAL_SHAPE.captures(title) else {
        return false;
    };
    if mi!(
        title,
        r"[._-](?:sample|proof|thumbs?|part\d+|vol\d+(?:[+-]\d+)?)\z"
    ) {
        return false;
    }
    mi!(
        &parts[1],
        r"(?:^|[._-])(?:480p|576p|720p|1080[pi]|2160p|4320p|[48]k|web[._-]?dl|webrip|bluray|bdrip|brrip|dvdrip|hdtv|sdtv|x264|x265|h[._]?26[45]|hevc|avc|xvid|divx|aac|ac3|eac3|ddp?[0-9](?:[._][0-9])?|dts(?:[._-]?hd)?|flac|mp3|truehd|pal|ntsc)(?:$|[._-])"
    ) && looks_like_scene_release(title)
        && is_plausible_release_title(title)
}

fn is_ignorable_for_matching(name: &str) -> bool {
    if mi!(name, r"\.url$") {
        return true;
    }
    // pathinfo(PATHINFO_FILENAME): the basename up to its last dot.
    let base = crate::season::php_basename(name);
    let stem = base.rfind('.').map_or(base, |dot| &base[..dot]);
    looks_like_structural_junk(stem)
}

fn looks_like_structural_junk(name: &str) -> bool {
    let normalized = trim_chars(name, " \t\n\r\0\x0B.-_").to_ascii_lowercase();
    normalized.is_empty()
        || mi!(normalized, r"^(?:audio|video)[._-]?ts$")
        || mi!(normalized, r"^vts[._-]?\d{1,2}[._-]?\d{1,2}$")
        || ms!(normalized, r"^\d{1,3}$")
}

/// Decodes a single NZBSPLIT wrapper's literal payload; the caller owns suffix handling.
pub fn literal_payload(value: &str) -> Option<String> {
    let lower = value.to_ascii_lowercase();
    const MARK: &str = "__nzbsplit__";
    let first = lower.find(MARK)?;
    let second = first + 1 + lower[first + 1..].find(MARK)?;
    if lower[second + 1..].contains(MARK) {
        return None;
    }
    let caps = rx!(i r"(?s)\A__NZBSPLIT__(?:[a-f0-9]{8,})__NZBSPLIT__(.+)\z")
        .captures(&value.as_bytes()[first..])?;
    // Separator encoding is defined by the wrapper. Every other payload byte is preserved.
    Some(
        String::from_utf8_lossy(&caps[1])
            .replace('_', ".")
            .replace('+', " "),
    )
}

/// Removes a bounded transport trailer only from a valid archive-wrapped NZBSPLIT filename.
pub fn strip_literal_subject_trailer(value: &str) -> String {
    if literal_payload(value).is_none() {
        return value.to_owned();
    }
    let trailer = rx!(i r#""? +yEnc(?: +\([1-9]\d*(?:/[1-9]\d*)?\))?\z"#);
    let Some(found) = trailer.find(value.as_bytes()) else {
        return value.to_owned();
    };
    let filename = &value[..found.start()];
    if archive_suffix_patterns()
        .iter()
        .any(|re| re.is_match(filename.as_bytes()))
    {
        filename.to_owned()
    } else {
        value.to_owned()
    }
}

/// `['/\\|]`-free, control-free, nonempty.
fn valid(value: &str) -> bool {
    !value.is_empty() && !value.contains(['\\', '/', '|']) && !has_control(value)
}

/// Immutable basename evidence. Nomination cleaning must never establish identity.
#[derive(Clone, Debug)]
pub struct OriginalFilenameEvidence {
    pub basename: String,
    pub stem: String,
    normalized_literal: String,
    literal_is_ascii: bool,
}

impl OriginalFilenameEvidence {
    pub fn new(filename: &str) -> Self {
        let source = strip_literal_subject_trailer(filename);
        if contains_ci(&source, "__NZBSPLIT__") {
            // A wrapper's payload is a filename, never a path. Validate before basename
            // extraction can discard malformed transport text before a slash.
            match literal_payload(&source) {
                Some(payload) if !payload.contains(['/', '\\']) => {}
                _ => {
                    return Self {
                        basename: String::new(),
                        stem: String::new(),
                        normalized_literal: String::new(),
                        literal_is_ascii: false,
                    };
                }
            }
        }
        let basename = match source.rfind(['\\', '/']) {
            Some(index) => source[index + 1..].to_owned(),
            None => source,
        };
        let stem = strip_file_suffixes(&basename);
        let stem = literal_payload(&stem).unwrap_or(stem);
        let literal = literal_payload(&basename).unwrap_or_else(|| basename.clone());
        let literal_is_ascii = literal.is_ascii();
        let normalized_literal = if literal_is_ascii {
            literal.to_ascii_lowercase()
        } else {
            exact_value(&literal)
        };
        Self {
            basename,
            stem,
            normalized_literal,
            literal_is_ascii,
        }
    }

    /// A bounded basename no title or alias can satisfy under the literal acceptance rule.
    pub fn is_locally_rejected_pipe(&self) -> bool {
        self.basename.len() <= 8192
            && self.basename.matches('.').count() < 128
            && self.basename.contains('|')
            && !has_control(&self.basename)
    }

    /// Exhaustive bounded nomination; current rows still need acceptance and ambiguity checks.
    /// `None` is UNKNOWN, never permission to use truncated evidence or a rename fallback.
    pub fn canonical_nomination_keys(&self) -> Option<Vec<String>> {
        let raw = &self.basename;
        if !valid(raw) || raw.len() > 8192 || raw.matches('.').count() + 1 > 128 {
            return None;
        }
        let mut keys: Vec<String> = Vec::new();
        // Every nonempty accepted transport suffix begins with a literal dot. Encoded wrapper
        // separators belong to the title and aren't cut points.
        let cuts = raw
            .bytes()
            .enumerate()
            .skip(1)
            .filter(|(_, b)| *b == b'.')
            .map(|(i, _)| i)
            .chain(std::iter::once(raw.len()));
        for offset in cuts {
            let prefix = &raw[..offset];
            let prefix = literal_payload(prefix).unwrap_or_else(|| prefix.to_owned());
            if self.accepts(&prefix, "") {
                let key = exact_value(&prefix);
                if key.len() > 8192 {
                    return None;
                }
                if !keys.contains(&key) {
                    keys.push(key);
                }
            }
        }
        Some(keys)
    }

    pub fn accepts(&self, title: &str, alias: &str) -> bool {
        if !valid(&self.basename) || !valid(title) {
            return false;
        }
        if self.accepts_canonical(title) {
            return true;
        }
        if alias.is_empty() || !valid(&self.stem) || NUMERIC_ONLY.is_match(&self.stem) {
            return false;
        }
        let other = Self::new(alias);
        if !valid(&other.basename) {
            return false;
        }
        [&self.stem, &other.stem].iter().any(|identity| {
            valid(identity)
                && !NUMERIC_ONLY.is_match(identity)
                && self.accepts_canonical(identity)
                && other.accepts_canonical(identity)
        })
    }

    /// Validates only raw suffix bytes after the complete case-folded nominated title.
    fn accepts_canonical(&self, title: &str) -> bool {
        let identity = exact_value(title);
        let literal = self.normalized_literal.as_bytes();
        // Lowercasing the whole filename can change contextual characters in its title (Greek
        // final sigma before an extension), so this only locates a candidate boundary.
        if identity.is_empty() || literal.len() < identity.len() {
            return false;
        }
        if !literal.starts_with(identity.as_bytes()) && self.literal_is_ascii {
            return false;
        }
        let folded_suffix = &literal[identity.len()..];
        if !folded_suffix.is_ascii() {
            return false;
        }
        let basename = self.basename.as_bytes();
        let Some(split) = basename.len().checked_sub(folded_suffix.len()) else {
            return false;
        };
        let suffix = &basename[split..];
        if !suffix.is_ascii() {
            return false;
        }
        let prefix = &self.basename[..split];
        let prefix = literal_payload(prefix).unwrap_or_else(|| prefix.to_owned());
        if exact_value(&prefix) != identity {
            return false;
        }
        let suffix = std::str::from_utf8(suffix).unwrap_or_default();
        strip_file_suffixes(&format!("Title{suffix}")) == "Title"
    }
}
