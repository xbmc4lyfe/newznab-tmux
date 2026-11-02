//! Reading a release's NZB for additional processing. Port of `NzbContentParser` and the keyed,
//! count-stripped form of `NzbParserService::parseNzbFileList` it uses.

use std::cmp::Ordering;
use std::collections::{HashMap, HashSet};
use std::sync::LazyLock;

use anyhow::Result;
use nntmux_core::pcre::Pcre;
use nntmux_verify::in_tx;
use nntmux_verify::nzb::{self, ReleaseRef};
use quick_xml::events::{BytesStart, Event};
use quick_xml::{Reader, XmlVersion};
use sqlx::MySqlPool;

use super::planner::NzbFile;

macro_rules! pattern {
    ($name:ident, $pattern:expr) => {
        static $name: LazyLock<Pcre> =
            LazyLock::new(|| Pcre::new($pattern).expect("a valid regex"));
    };
}

pattern!(COUNT, r"#\d+[- ._]?(/|\||[o0]f)[- ._]?\d+?(?![- ._]\d)#i");
pattern!(PAR2_VOLUME, r"#\.vol\d+\+\d+\.par2#i");
pattern!(RAR_ANYWHERE, r"#\.rar[^a-z0-9]#i");
pattern!(RAR_PART, r"#\.part\d+\.rar$#i");
pattern!(RAR_SUFFIX, r"#\.rar(?:[^a-z0-9])#i");
pattern!(
    QUOTED_NAME,
    r#"/^[^"\r\n]*"([^"\r\n]+)"(?:\s+yEnc)?(?:\s+\(\d+\/(\d+)\))?\s*$/iD"#
);
pattern!(
    PLAIN_NAME,
    r#"/^([^"\r\n]+?)(?:\s+yEnc)?(?:\s+\(\d+\/(\d+)\))?\s*$/iD"#
);
pattern!(CONTROL, r"/[\x00-\x1f\x7f]/");
pattern!(REPAIR_CONTROL, r"/[\x00-\x08\x0B\x0C\x0E-\x1F]/");
pattern!(CLOSED, r"/<\/nzb>\s*$/i");

/// Video containers a release can be posted as directly. Port of `VideoContainer::fromFileName`'s
/// extension list.
const VIDEO_EXTENSIONS: &[&str] = &[
    "mkv", "mk3d", "webm", "avi", "divx", "xvid", "mpg", "mpeg", "vob", "m2v", "mpv", "ts", "m2ts",
    "mts", "mp4", "m4v", "mov", "qt", "wmv", "asf", "ogv", "ogm",
];

/// A posted file: its name and its first article.
#[derive(Clone, Debug, PartialEq, Eq, serde::Serialize, serde::Deserialize)]
pub struct SourceFile {
    pub name: String,
    #[serde(rename = "firstMessageId")]
    pub first_message_id: String,
}

/// A release's NZB, as additional processing reads it.
#[derive(Clone, Debug, Default)]
pub struct ParsedNzb {
    /// Files by count-stripped subject, in natural order.
    pub contents: Vec<NzbFile>,
    pub source_digest: String,
    /// One entry per NZB file, when every file is a plain, uniquely numbered, single upload.
    pub source_files: Option<Vec<SourceFile>>,
    /// [`Self::source_files`], when every one of them is a video container.
    pub direct_video_files: Option<Vec<SourceFile>>,
}

/// Why an NZB couldn't be read. A broken NZB whose source didn't change is deleted with its
/// release; a changed source is retried.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct NzbError {
    pub message: String,
    pub source_changed: bool,
}

#[derive(Default)]
struct RawFile {
    subject: String,
    /// `(number attribute, message-ID)`.
    segments: Vec<(Option<String>, String)>,
    bytes: i64,
}

#[derive(Default)]
struct RawDocument {
    files: Vec<RawFile>,
    metadata: Vec<(String, String)>,
}

fn attribute(element: &BytesStart<'_>, name: &str) -> Option<String> {
    element
        .attributes()
        .flatten()
        .find(|attribute| {
            let key = attribute.key.local_name();
            AsRef::<str>::as_ref(&key) == name
        })
        .and_then(|attribute| {
            attribute
                .normalized_value(XmlVersion::default())
                .ok()
                .map(|value| value.into_owned())
        })
}

/// PHP's `(int)` of a numeric-leading string, as the `bytes` attribute is summed.
fn php_int(value: &str) -> i64 {
    let trimmed = value.trim_start_matches([' ', '\t', '\n', '\r', '\x0B', '\x0C']);
    let (negative, digits) = match trimmed.strip_prefix('-') {
        Some(rest) => (true, rest),
        None => (false, trimmed.strip_prefix('+').unwrap_or(trimmed)),
    };
    let end = digits.bytes().take_while(u8::is_ascii_digit).count();
    let magnitude: i64 = digits[..end]
        .parse()
        .unwrap_or(if end == 0 { 0 } else { i64::MAX });
    if negative { -magnitude } else { magnitude }
}

enum Text {
    Segment,
    Meta,
}

/// The `<file>` and `<meta>` elements. Invalid XML (control characters included), or a root other
/// than `nzb`, reads as nothing,
/// as `simplexml_load_string` failing did.
fn read_document(xml: &[u8]) -> Option<RawDocument> {
    // XML 1.0 has no control characters other than tab and line breaks; libxml refuses them.
    if xml
        .iter()
        .any(|byte| *byte < 0x20 && !matches!(byte, b'\t' | b'\n' | b'\r'))
    {
        return None;
    }
    let mut reader = Reader::from_reader(xml);
    reader.config_mut().check_end_names = true;
    let mut buffer = Vec::new();
    let mut document = RawDocument::default();
    let mut current: Option<RawFile> = None;
    let mut text: Option<Text> = None;
    let mut root_seen = false;
    let mut depth = 0usize;
    let mut in_head = false;
    loop {
        match reader.read_event_into(&mut buffer).ok()? {
            Event::Start(element) => {
                let local = element.local_name();
                let name = AsRef::<str>::as_ref(&local).to_owned();
                depth += 1;
                if !root_seen {
                    root_seen = true;
                    if !name.eq_ignore_ascii_case("nzb") {
                        return None;
                    }
                    buffer.clear();
                    continue;
                }
                match name.as_str() {
                    "head" if depth == 2 => in_head = true,
                    "meta" if in_head && depth == 3 => {
                        document.metadata.push((
                            attribute(&element, "type").unwrap_or_default(),
                            String::new(),
                        ));
                        text = Some(Text::Meta);
                    }
                    "file" if depth == 2 => {
                        current = Some(RawFile {
                            subject: attribute(&element, "subject").unwrap_or_default(),
                            ..RawFile::default()
                        });
                    }
                    "segment" if current.is_some() => {
                        if let Some(file) = &mut current {
                            file.bytes = file.bytes.saturating_add(
                                attribute(&element, "bytes").map_or(0, |b| php_int(&b)),
                            );
                            file.segments
                                .push((attribute(&element, "number"), String::new()));
                        }
                        text = Some(Text::Segment);
                    }
                    _ => {}
                }
            }
            Event::Empty(element) => {
                let local = element.local_name();
                let name = AsRef::<str>::as_ref(&local).to_owned();
                if !root_seen {
                    return name.eq_ignore_ascii_case("nzb").then(RawDocument::default);
                }
                match name.as_str() {
                    "file" if depth == 1 => document.files.push(RawFile {
                        subject: attribute(&element, "subject").unwrap_or_default(),
                        ..RawFile::default()
                    }),
                    "segment" if current.is_some() => {
                        if let Some(file) = &mut current {
                            file.bytes = file.bytes.saturating_add(
                                attribute(&element, "bytes").map_or(0, |b| php_int(&b)),
                            );
                            file.segments
                                .push((attribute(&element, "number"), String::new()));
                        }
                    }
                    "meta" if in_head && depth == 2 => document.metadata.push((
                        attribute(&element, "type").unwrap_or_default(),
                        String::new(),
                    )),
                    _ => {}
                }
            }
            event @ (Event::Text(_) | Event::CData(_) | Event::GeneralRef(_)) => {
                let decoded = match &event {
                    Event::Text(content) => content.xml10_content().into_owned(),
                    Event::CData(content) => content.clone().into_inner().into_owned(),
                    Event::GeneralRef(reference) => {
                        if let Ok(Some(character)) = reference.resolve_char_ref() {
                            character.to_string()
                        } else {
                            match AsRef::<str>::as_ref(reference) {
                                "amp" => "&".to_owned(),
                                "lt" => "<".to_owned(),
                                "gt" => ">".to_owned(),
                                "quot" => "\"".to_owned(),
                                "apos" => "'".to_owned(),
                                // An undeclared entity is a fatal error to libxml.
                                _ => return None,
                            }
                        }
                    }
                    _ => String::new(),
                };
                match (&text, &mut current) {
                    (Some(Text::Segment), Some(file)) => {
                        if let Some(segment) = file.segments.last_mut() {
                            segment.1.push_str(&decoded);
                        }
                    }
                    (Some(Text::Meta), _) => {
                        if let Some(meta) = document.metadata.last_mut() {
                            meta.1.push_str(&decoded);
                        }
                    }
                    _ => {}
                }
            }
            Event::End(element) => {
                let local = element.local_name();
                match local.as_ref() {
                    "file" if depth == 2 => document.files.extend(current.take()),
                    "segment" | "meta" => text = None,
                    "head" if depth == 2 => in_head = false,
                    _ => {}
                }
                depth = depth.saturating_sub(1);
            }
            Event::DocType(_) => {}
            // An unclosed element is a truncated document, which libxml refuses.
            Event::Eof => return (root_seen && depth == 0).then_some(document),
            _ => {}
        }
        buffer.clear();
    }
}

/// The key `parseNzbFileList` files a subject under with `strip-count`: the file and part counts
/// removed, and a plain `.par2` or `.rar` renamed to sort before its volumes.
pub fn strip_count_key(subject: &str) -> String {
    let stripped = COUNT.replace_all(subject.as_bytes(), b"");
    let stripped = String::from_utf8_lossy(&stripped).into_owned();
    if stripped.contains(".par2") && !PAR2_VOLUME.is_match(stripped.as_bytes()) {
        return stripped.replace(".par2", ".vol0.par2");
    }
    if RAR_ANYWHERE.is_match(stripped.as_bytes()) && !RAR_PART.is_match(stripped.as_bytes()) {
        let replaced = RAR_SUFFIX.replace_all(stripped.as_bytes(), b".part0.rar");
        return String::from_utf8_lossy(&replaced).into_owned();
    }
    stripped
}

/// PHP's `strnatcmp`, case-sensitive: runs of digits compare as numbers (as fractions when either
/// starts with zero), leading zeros and whitespace are skipped.
pub fn strnatcmp(a: &[u8], b: &[u8]) -> Ordering {
    if a.is_empty() || b.is_empty() {
        return a.len().cmp(&b.len());
    }
    let at = |s: &[u8], i: usize| s.get(i).copied().unwrap_or(0);
    let digit = |s: &[u8], i: usize| i < s.len() && s[i].is_ascii_digit();
    let space = |c: u8| matches!(c, b' ' | b'\t' | b'\n' | b'\x0B' | b'\x0C' | b'\r');
    let (mut ai, mut bi) = (0usize, 0usize);
    let mut leading = true;
    loop {
        let mut ca = at(a, ai);
        let mut cb = at(b, bi);
        while leading && ca == b'0' && ai + 1 < a.len() && a[ai + 1].is_ascii_digit() {
            ai += 1;
            ca = at(a, ai);
        }
        while leading && cb == b'0' && bi + 1 < b.len() && b[bi + 1].is_ascii_digit() {
            bi += 1;
            cb = at(b, bi);
        }
        leading = false;
        while space(ca) {
            ai += 1;
            ca = at(a, ai);
        }
        while space(cb) {
            bi += 1;
            cb = at(b, bi);
        }
        if ca.is_ascii_digit() && cb.is_ascii_digit() {
            let fractional = ca == b'0' || cb == b'0';
            let result = if fractional {
                // Left-aligned: the first difference wins.
                loop {
                    match (digit(a, ai), digit(b, bi)) {
                        (false, false) => break Ordering::Equal,
                        (false, true) => break Ordering::Less,
                        (true, false) => break Ordering::Greater,
                        (true, true) => match a[ai].cmp(&b[bi]) {
                            Ordering::Equal => {}
                            other => break other,
                        },
                    }
                    ai += 1;
                    bi += 1;
                }
            } else {
                // Right-aligned: the longer run wins, then the first difference.
                let mut bias = Ordering::Equal;
                loop {
                    match (digit(a, ai), digit(b, bi)) {
                        (false, false) => break bias,
                        (false, true) => break Ordering::Less,
                        (true, false) => break Ordering::Greater,
                        (true, true) => {
                            if bias == Ordering::Equal {
                                bias = a[ai].cmp(&b[bi]);
                            }
                        }
                    }
                    ai += 1;
                    bi += 1;
                }
            };
            if result != Ordering::Equal {
                return result;
            }
            match (ai >= a.len(), bi >= b.len()) {
                (true, true) => return Ordering::Equal,
                (true, false) => return Ordering::Less,
                (false, true) => return Ordering::Greater,
                (false, false) => {
                    ca = at(a, ai);
                    cb = at(b, bi);
                }
            }
        }
        match ca.cmp(&cb) {
            Ordering::Equal => {}
            other => return other,
        }
        ai += 1;
        bi += 1;
        match (ai >= a.len(), bi >= b.len()) {
            (true, true) => return Ordering::Equal,
            (true, false) => return Ordering::Less,
            (false, true) => return Ordering::Greater,
            (false, false) => {}
        }
    }
}

/// PHP array keys: a decimal integer string becomes an integer key, which a natural sort compares
/// by its decimal form, the same text.
fn file_list(document: &RawDocument) -> Vec<NzbFile> {
    let mut keys: Vec<String> = Vec::new();
    let mut positions: HashMap<String, usize> = HashMap::new();
    let mut entries: Vec<NzbFile> = Vec::new();
    for file in &document.files {
        let key = strip_count_key(&file.subject);
        let index = match positions.get(&key) {
            Some(index) => *index,
            None => {
                positions.insert(key.clone(), entries.len());
                keys.push(key);
                entries.push(NzbFile::default());
                entries.len() - 1
            }
        };
        let entry = &mut entries[index];
        entry.title = file.subject.clone();
        entry.filecount += 1;
        let mut segments: Vec<(Option<i64>, &str)> = file
            .segments
            .iter()
            .map(|(number, id)| (number.as_deref().map(php_int), id.as_str()))
            .collect();
        // Segments may be listed out of order; readers expect them in file order.
        if segments.iter().all(|(number, _)| number.is_some()) {
            segments.sort_by_key(|(number, _)| *number);
        }
        if entry.filecount == 1 {
            // Segment 1 holds the file header; without it the file can't be inspected.
            entry.firstsegment = segments
                .first()
                .is_none_or(|(number, _)| number.unwrap_or(1) == 1);
        }
        entry
            .segments
            .extend(segments.into_iter().map(|(_, id)| id.to_owned()));
        // PHP keeps the last file's size for a merged entry.
        entry.size = file.bytes;
    }
    let mut order: Vec<usize> = (0..entries.len()).collect();
    order.sort_by(|left, right| strnatcmp(keys[*left].as_bytes(), keys[*right].as_bytes()));
    order
        .into_iter()
        .map(|index| entries[index].clone())
        .collect()
}

/// One `<file>` as `simplexml` lists it: the subject and its segments' message-IDs, in document
/// order.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct DocumentFile {
    pub subject: String,
    pub segments: Vec<String>,
    /// Each segment's `number` attribute, as PHP's `(int)` read it.
    pub numbers: Vec<Option<i64>>,
}

impl DocumentFile {
    /// The message-IDs in file order, as `parseNzbFileList` sorts them when every segment is
    /// numbered.
    pub fn segments_in_file_order(&self) -> Vec<String> {
        let mut segments: Vec<(Option<i64>, &String)> =
            self.numbers.iter().copied().zip(&self.segments).collect();
        if segments.iter().all(|(number, _)| number.is_some()) {
            segments.sort_by_key(|(number, _)| *number);
        }
        segments.into_iter().map(|(_, id)| id.clone()).collect()
    }
}

/// The NZB's files in document order, or `None` where `simplexml_load_string` failed.
pub fn document_files(xml: &[u8]) -> Option<Vec<DocumentFile>> {
    let document = read_document(xml)?;
    Some(
        document
            .files
            .into_iter()
            .map(|file| DocumentFile {
                subject: file.subject,
                numbers: file
                    .segments
                    .iter()
                    .map(|(number, _)| number.as_deref().map(php_int))
                    .collect(),
                segments: file.segments.into_iter().map(|(_, id)| id).collect(),
            })
            .collect(),
    )
}

/// `parseNzbFileList($nzb, ['no-file-key' => false, 'strip-count' => true])`, in `ksort`'s natural
/// order.
pub fn parse_file_list(xml: &[u8]) -> Vec<NzbFile> {
    let cleaned: Vec<u8> = xml.iter().copied().filter(|byte| *byte != 0x0f).collect();
    read_document(&cleaned)
        .map(|document| file_list(&document))
        .unwrap_or_default()
}

fn contains_ignore_ascii_case(haystack: &[u8], needle: &[u8]) -> bool {
    haystack
        .windows(needle.len())
        .any(|window| window.eq_ignore_ascii_case(needle))
}

/// The full unmerged source inventory: one plain name and first article per NZB file. Subjects
/// alone never attest whether a file is an archive, direct media or harmless metadata, so any
/// doubt gives `None`.
pub fn source_file_inventory(decoded: &[u8], parsed: &[NzbFile]) -> Option<Vec<SourceFile>> {
    if contains_ignore_ascii_case(decoded, b"<!ENTITY") {
        return None;
    }
    let document = read_document(decoded)?;
    let count = document.files.len();
    if count == 0 || count > 256 || count != parsed.len() {
        return None;
    }
    if parsed.iter().any(|file| file.filecount != 1) {
        return None;
    }
    for (kind, value) in &document.metadata {
        if matches!(
            kind.as_str(),
            "nntmux:declared-file-count" | "nntmux:verified-file-count"
        ) && (value.is_empty()
            || !value.bytes().all(|byte| byte.is_ascii_digit())
            || value
                .parse::<u128>()
                .map_or(true, |declared| declared > count as u128))
        {
            return None;
        }
    }
    let mut inventory = Vec::with_capacity(count);
    let mut first_ids: HashSet<String> = HashSet::new();
    let mut name_bytes = 0usize;
    for file in &document.files {
        let subject = file.subject.as_bytes();
        let found = QUOTED_NAME
            .captures(subject)
            .or_else(|| PLAIN_NAME.captures(subject))?;
        let name = String::from_utf8(found.group(1)?.to_vec()).ok()?;
        let name = name
            .trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B'])
            .to_owned();
        name_bytes += name.len();
        if name.is_empty()
            || name.len() > 4096
            || name_bytes > 32768
            || CONTROL.is_match(name.as_bytes())
            || name.starts_with(['.', '-'])
            || name.contains('/')
            || name.contains('\\')
        {
            return None;
        }
        let mut segments: Vec<(u64, &str)> = Vec::with_capacity(file.segments.len());
        for (number, id) in &file.segments {
            let number = number.as_deref().unwrap_or("");
            if number.is_empty()
                || !number.bytes().all(|byte| byte.is_ascii_digit())
                || id
                    .trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B'])
                    .is_empty()
            {
                return None;
            }
            let number: u64 = number.parse().ok()?;
            if number < 1 {
                return None;
            }
            segments.push((number, id));
        }
        segments.sort_by_key(|(number, _)| *number);
        // A repeated number is refused, found next to its twin once sorted.
        if segments.windows(2).any(|pair| pair[0].0 == pair[1].0) {
            return None;
        }
        let contiguous = segments
            .iter()
            .enumerate()
            .all(|(index, (number, _))| *number == index as u64 + 1);
        let declared = found
            .group(2)
            .and_then(|total| std::str::from_utf8(total).ok())
            .map(|total| total.parse::<u64>().unwrap_or(u64::MAX));
        let first = segments.first()?.1.to_owned();
        if !contiguous
            || declared.is_some_and(|total| total != segments.len() as u64)
            || !first_ids.insert(first.clone())
        {
            return None;
        }
        inventory.push(SourceFile {
            name,
            first_message_id: first,
        });
    }
    Some(inventory)
}

fn is_video_name(name: &str) -> bool {
    std::path::Path::new(name)
        .extension()
        .and_then(|extension| extension.to_str())
        .is_some_and(|extension| {
            VIDEO_EXTENSIONS.contains(&extension.to_ascii_lowercase().as_str())
        })
}

/// The document a broken NZB repairs to: control characters removed and the root closed. `None`
/// when even that has no `<file>`.
pub fn repair(raw: &[u8]) -> Option<Vec<u8>> {
    let mut fixed = REPAIR_CONTROL.replace_all(raw, b"");
    if !contains_ignore_ascii_case(&fixed, b"<nzb") {
        let mut wrapped = b"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<nzb xmlns=\"http://www.newzbin.com/DTD/2003/nzb\">\n".to_vec();
        wrapped.extend_from_slice(&fixed);
        wrapped.extend_from_slice(b"\n</nzb>");
        fixed = wrapped;
    } else if !CLOSED.is_match(&fixed) {
        fixed.extend_from_slice(b"\n</nzb>");
    }
    let document = read_document(&fixed)?;
    (!document.files.is_empty()).then_some(fixed)
}

/// The release's committed NZB under its row lock, starting a naming inspection of that
/// generation.
async fn snapshot(pool: &MySqlPool, guid: &str) -> Result<Option<(ReleaseRef, Vec<u8>, String)>> {
    in_tx!(pool, 3, |tx| {
        let id: Option<i64> =
            sqlx::query_scalar("SELECT CAST(id AS SIGNED) FROM releases WHERE guid = ? FOR UPDATE")
                .bind(guid)
                .fetch_optional(&mut *tx)
                .await?;
        let Some(id) = id else {
            return Ok::<_, anyhow::Error>(None);
        };
        let release = ReleaseRef {
            id,
            guid: guid.to_owned(),
        };
        // A read failure is an error, as PHP's integrity check throws; a lock wait retries.
        let Some(contents) = nzb::read(&mut *tx, guid).await? else {
            return Ok(None);
        };
        let digest = nzb::digest(&contents);
        // The first inspection leaves legacy admission before any producer can mistake one
        // readable file for the whole NZB.
        nzb::store::source_replaced_locked(tx, &release, &digest).await?;
        Ok(Some((release, contents, digest)))
    })
}

/// Installs a repaired NZB, unless its source changed since it was read.
async fn install_repair(
    pool: &MySqlPool,
    release: &ReleaseRef,
    fixed: &[u8],
    raw_digest: &str,
) -> Result<()> {
    let body = nzb::compress(fixed)?;
    in_tx!(pool, 1, |tx| {
        nzb::put_locked(tx, release, &body, Some(raw_digest), true).await
    })?;
    Ok(())
}

/// `parseNzb`: the release's file list, or why it can't be read.
pub async fn parse_release_nzb(
    pool: &MySqlPool,
    guid: &str,
) -> Result<std::result::Result<ParsedNzb, NzbError>> {
    let changed = |message: String| NzbError {
        message,
        source_changed: true,
    };
    let Some((release, mut contents, mut digest)) = snapshot(pool, guid).await? else {
        return Ok(Err(changed(format!(
            "NZB is missing or broken for GUID: {guid}"
        ))));
    };
    let mut files = parse_file_list(&contents);
    if files.is_empty() {
        // Proposed bytes are never evidence unless the replacement was installed.
        let repaired = match repair(&contents) {
            Some(fixed) if fixed == contents => Some(fixed),
            Some(fixed) => install_repair(pool, &release, &fixed, &digest)
                .await
                .ok()
                .map(|()| fixed),
            None => None,
        };
        let retained = snapshot(pool, guid).await?;
        let unchanged = match (&retained, &repaired) {
            (Some((_, now, now_digest)), None) => {
                *now_digest == digest && now.len() == contents.len()
            }
            (Some((_, now, _)), Some(fixed)) => now == fixed,
            (None, _) => false,
        };
        let Some((_, now, now_digest)) = retained.filter(|_| unchanged) else {
            return Ok(Err(changed(format!(
                "NZB source changed during repair for GUID: {guid}"
            ))));
        };
        contents = now;
        digest = now_digest;
        if let Some(fixed) = &repaired {
            files = parse_file_list(fixed);
        }
        if files.is_empty() {
            return Ok(Err(changed(format!(
                "NZB is potentially broken for GUID: {guid}"
            ))));
        }
    }
    let source_files = source_file_inventory(&contents, &files);
    let direct_video_files = source_files
        .as_ref()
        .filter(|files| files.iter().all(|file| is_video_name(&file.name)))
        .cloned();
    Ok(Ok(ParsedNzb {
        contents: files,
        source_digest: digest,
        source_files,
        direct_video_files,
    }))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn natural_order_matches_php() {
        let mut names = vec!["x10", "x9", "x1", "X2", "x01", "x 3", "a", "", "x09"];
        names.sort_by(|a, b| strnatcmp(a.as_bytes(), b.as_bytes()));
        assert_eq!(
            names,
            vec!["", "X2", "a", "x01", "x09", "x1", "x 3", "x9", "x10"]
        );
    }

    #[test]
    fn a_failure_to_parse_is_not_a_failure_to_read() {
        assert!(parse_file_list(b"<html></html>").is_empty());
        assert!(parse_file_list(b"not xml").is_empty());
        assert!(repair(b"nothing here").is_none());
    }
}
