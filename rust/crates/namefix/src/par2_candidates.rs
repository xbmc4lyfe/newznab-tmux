//! Which files of an NZB may carry PAR2 metadata, and in what order to try them.
//!
//! `NzbContentsService::par2Candidates`, its manifest and the signatures progress rows are keyed
//! by. A signature covers every ordered candidate's segment numbers, Message-IDs and sizes plus
//! the configured limits, so any change to the NZB or the limits starts the release's progress
//! afresh. The JSON they hash is PHP's, byte for byte.

use std::sync::LazyLock;

use quick_xml::escape::resolve_predefined_entity;
use quick_xml::events::{BytesStart, Event};
use quick_xml::{Reader, XmlVersion};
use regex::Regex;

use crate::par2_json::Json;

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct RawSegment {
    pub number: String,
    pub id: String,
    pub bytes: String,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct NzbFile {
    pub subject: String,
    pub segments: Vec<RawSegment>,
    /// Whether the file has a `segments` element. Without one, `count()` of the missing
    /// element throws, which fails the whole check.
    pub has_segments: bool,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Segment {
    pub number: i64,
    pub id: String,
    pub bytes: i64,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Class {
    Index,
    Volume,
    Legacy,
    Hidden,
}

impl Class {
    pub fn name(self) -> &'static str {
        match self {
            Self::Index => "index",
            Self::Volume => "volume",
            Self::Legacy => "legacy",
            Self::Hidden => "hidden",
        }
    }
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Candidate {
    pub subject: String,
    pub explicit: bool,
    pub segments: Vec<Segment>,
    pub class: Class,
}

impl Candidate {
    fn segments_json(&self) -> Json {
        Json::Arr(
            self.segments
                .iter()
                .map(|s| {
                    Json::Obj(vec![
                        ("number", Json::Int(s.number)),
                        ("id", Json::str(s.id.clone())),
                        ("bytes", Json::Int(s.bytes)),
                    ])
                })
                .collect(),
        )
    }

    pub fn json(&self) -> Json {
        Json::Obj(vec![
            ("subject", Json::str(self.subject.clone())),
            ("explicit", Json::Bool(self.explicit)),
            ("segments", self.segments_json()),
            ("classification", Json::str(self.class.name())),
        ])
    }

    /// The candidate's own signature: what a checkpoint is keyed by.
    pub fn signature(&self) -> String {
        Json::Obj(vec![
            ("subject", Json::str(self.subject.clone())),
            ("explicit", Json::Bool(self.explicit)),
            ("segments", self.segments_json()),
        ])
        .sha256()
    }
}

/// The ordered candidates and what the manifest recorded about every file.
#[derive(Clone, Debug)]
pub struct Plan {
    pub candidates: Vec<Candidate>,
    pub manifest: Json,
    /// Bytes outside explicit PAR2 files, which bound what a recovery set can describe.
    pub non_explicit_bytes: i64,
    /// False when a PAR2-capable file's segment list is unusable, so absence of a match is
    /// never proof.
    pub structure_valid: bool,
}

/// PHP `trim` with its default characters.
fn php_trim(value: &str) -> &str {
    value.trim_matches(|c| matches!(c, ' ' | '\t' | '\n' | '\r' | '\0' | '\x0B'))
}

/// `filter_var($value, FILTER_VALIDATE_INT, ['min_range' => 1])`.
pub fn validate_positive_int(value: &str) -> Option<i64> {
    let value = value.trim_matches(|c| matches!(c, ' ' | '\t' | '\n' | '\r' | '\x0B' | '\x0C'));
    let digits = value.strip_prefix(['+', '-']).unwrap_or(value);
    if digits.is_empty()
        || !digits.bytes().all(|b| b.is_ascii_digit())
        || (digits.len() > 1 && digits.starts_with('0'))
    {
        return None;
    }
    let parsed: i64 = value.strip_prefix('+').unwrap_or(value).parse().ok()?;
    (parsed >= 1).then_some(parsed)
}

macro_rules! pattern {
    ($name:ident, $source:expr) => {
        static $name: LazyLock<Regex> =
            LazyLock::new(|| Regex::new($source).expect("static pattern"));
    };
}

// PCRE's `$` also matches before a final newline, which the optional `\n?` covers.
pattern!(EXPLICIT, r#"(?i)\.par2(?:["\s]|\z)"#);
pattern!(VOLUME, r#"(?i)\.vol[0-9]+\+[0-9]+\.par2(?:["\s]|\z)"#);
pattern!(
    KNOWN_CONTENT,
    r#"(?i)\.(?:rar|r[0-9]{2,4}|[0-9]{3}|7z|zip|mkv|mp4|avi|m2ts|ts|iso|nfo|sfv|srr|srs|jpg|png|mp3|flac)(?:["\s]|\z)"#
);
pattern!(LEGACY, r#"(?i)\.(par["\s]|[0-9]{2,3}").+\(1/1\)"#);
pattern!(PARTS_PRIMARY, r"[(\[]?([0-9]+)[/)\]]([0-9]+)[)\]]?\n?\z");
pattern!(PARTS_FALLBACK, r"([0-9]+)\)\n?\z");
pattern!(
    FAMILY_PREFIX,
    r"^[ \t\n\r\x0B\x0C]*\[[0-9]+/[0-9]+\][ \t\n\r\x0B\x0C]*"
);
pattern!(FAMILY_QUOTED, r#"(?i)"([^"]+\.par2)""#);
pattern!(
    FAMILY_BARE,
    r#"(?i)(?:^|[ \t\n\r\x0B\x0C])([^ \t\n\r\x0B\x0C"]+\.par2)(?:[ \t\n\r\x0B\x0C]|\z)"#
);
pattern!(FAMILY_VOLUME, r"(?i)\.vol[0-9]+\+[0-9]+\.par2(\n?)\z");
pattern!(FAMILY_PAR2, r"(?i)\.par2\n?\z");

/// `NzbParserService::extractPartsTotal`.
pub fn extract_parts_total(subject: &str) -> i64 {
    let int = |text: &str| text.parse::<i64>().unwrap_or(i64::MAX);
    if let Some(found) = PARTS_PRIMARY.captures(subject)
        && int(&found[2]) > 0
    {
        return int(&found[2]);
    }
    PARTS_FALLBACK
        .captures(subject)
        .map_or(0, |found| int(&found[1]))
}

/// `par2FamilyKey`: the index name a volume belongs to.
pub fn family_key(subject: &str) -> Option<String> {
    let subject = FAMILY_PREFIX.replace(subject, "").into_owned();
    let name = if let Some(found) = FAMILY_QUOTED.captures(&subject) {
        found[1].to_owned()
    } else if let Some(found) = FAMILY_BARE.captures(&subject) {
        found[1].to_owned()
    } else {
        subject
            .trim_matches(|c| matches!(c, ' ' | '\t' | '"' | '\''))
            .to_owned()
    };
    let name = FAMILY_VOLUME.replace(&name, ".par2$1").into_owned();
    FAMILY_PAR2.is_match(&name).then_some(name)
}

/// Parses the NZB's `file` elements. `None` when the document isn't well-formed XML, as
/// `simplexml_load_string` returns false.
pub fn parse_nzb(xml: &[u8]) -> Option<Vec<NzbFile>> {
    let mut reader = Reader::from_reader(xml);
    reader.config_mut().check_end_names = true;
    let mut buffer = Vec::new();
    let mut files: Vec<NzbFile> = Vec::new();
    let mut depth = 0usize;
    // Where we are inside the current `file`: its first `segments` and the open `segment`.
    let mut current: Option<(NzbFile, bool, u32)> = None; // file, segments seen, segments depth
    let mut segment_text: Option<RawSegment> = None;
    let mut root_seen = false;
    loop {
        match reader.read_event_into(&mut buffer).ok()? {
            Event::Start(element) => {
                root_seen = true;
                depth += 1;
                let name = local(&element);
                if depth == 2 && name == "file" {
                    current = Some((
                        NzbFile {
                            subject: attribute(&element, "subject").unwrap_or_default(),
                            segments: Vec::new(),
                            has_segments: false,
                        },
                        false,
                        0,
                    ));
                } else if let Some((_, seen, level)) = &mut current {
                    if depth == 3 && name == "segments" && !*seen {
                        *seen = true;
                        *level = 3;
                    } else if depth == 4 && *level == 3 && name == "segment" {
                        segment_text = Some(RawSegment {
                            number: attribute(&element, "number").unwrap_or_default(),
                            id: String::new(),
                            bytes: attribute(&element, "bytes").unwrap_or_default(),
                        });
                    }
                }
            }
            Event::Empty(element) => {
                root_seen = true;
                let name = local(&element);
                if depth == 1 && name == "file" {
                    files.push(NzbFile {
                        subject: attribute(&element, "subject").unwrap_or_default(),
                        segments: Vec::new(),
                        has_segments: false,
                    });
                } else if let Some((file, seen, level)) = &mut current {
                    if depth == 2 && name == "segments" && !*seen {
                        *seen = true;
                    } else if depth == 3 && *level == 3 && name == "segment" {
                        file.segments.push(RawSegment {
                            number: attribute(&element, "number").unwrap_or_default(),
                            id: String::new(),
                            bytes: attribute(&element, "bytes").unwrap_or_default(),
                        });
                    }
                }
            }
            Event::Text(text) => {
                if let Some(segment) = &mut segment_text {
                    let content: &str = text.as_ref();
                    segment.id.push_str(content);
                }
            }
            Event::CData(text) => {
                if let Some(segment) = &mut segment_text {
                    let content: &str = text.as_ref();
                    segment.id.push_str(content);
                }
            }
            Event::GeneralRef(reference) => {
                if let Some(segment) = &mut segment_text {
                    let name: &str = reference.as_ref();
                    if let Some(resolved) = resolve_predefined_entity(name) {
                        segment.id.push_str(resolved);
                    } else if let Some(c) = reference.resolve_char_ref().ok().flatten() {
                        segment.id.push(c);
                    }
                }
            }
            Event::End(_) => {
                if depth == 4
                    && let Some(segment) = segment_text.take()
                    && let Some((file, _, _)) = &mut current
                {
                    file.segments.push(segment);
                }
                if depth == 3
                    && let Some((_, _, level)) = &mut current
                {
                    *level = 0;
                }
                if depth == 2
                    && let Some((mut file, seen, _)) = current.take()
                {
                    file.has_segments = seen;
                    files.push(file);
                }
                depth = depth.checked_sub(1)?;
            }
            Event::Eof => return (root_seen && depth == 0).then_some(files),
            _ => {}
        }
        buffer.clear();
    }
}

fn local(element: &BytesStart<'_>) -> String {
    let local = element.local_name();
    let name: &str = local.as_ref();
    name.to_owned()
}

fn attribute(element: &BytesStart<'_>, name: &str) -> Option<String> {
    element
        .attributes()
        .flatten()
        .find(|attribute| attribute.key.0 == name)
        .and_then(|attribute| {
            attribute
                .normalized_value(XmlVersion::default())
                .ok()
                .map(|value| value.into_owned())
        })
}

/// Classifies every file and orders the candidates: explicit indexes, volume sets that match
/// an index, legacy and hidden files by first-article size, then the rest.
pub fn plan(files: &[NzbFile], max_probe_bytes: i64) -> Result<Plan, MissingSegments> {
    let mut explicit: Vec<(String, Candidate)> = Vec::new();
    let mut volumes: Vec<(String, Candidate)> = Vec::new();
    let mut first_volumes: Vec<(String, Candidate)> = Vec::new();
    let mut legacy: Vec<(String, Candidate)> = Vec::new();
    let mut hidden: Vec<(String, Candidate)> = Vec::new();
    let mut index_families: Vec<String> = Vec::new();
    let mut families: Vec<String> = Vec::new();
    let mut manifest = Vec::new();
    let mut non_explicit_bytes = 0i64;
    let mut structure_valid = !files.is_empty();

    for file in files {
        if !file.has_segments {
            return Err(MissingSegments);
        }
        let mut valid = !file.segments.is_empty();
        let mut segments: Vec<Segment> = Vec::new();
        for entry in &file.segments {
            let number = validate_positive_int(&entry.number);
            let bytes = validate_positive_int(&entry.bytes);
            let id = php_trim(&entry.id).to_owned();
            valid = valid && number.is_some() && bytes.is_some() && !id.is_empty();
            segments.push(Segment {
                number: number.unwrap_or(0),
                id,
                bytes: bytes.unwrap_or(0),
            });
        }
        segments.sort_by_key(|segment| segment.number);
        for (index, segment) in segments.iter().enumerate() {
            if segment.number != index as i64 + 1 {
                valid = false;
            }
        }
        let is_explicit = EXPLICIT.is_match(&file.subject);
        if !is_explicit {
            non_explicit_bytes =
                non_explicit_bytes.saturating_add(segments.iter().map(|s| s.bytes).sum::<i64>());
        }
        let mut class = if is_explicit {
            Some(if VOLUME.is_match(&file.subject) {
                Class::Volume
            } else {
                Class::Index
            })
        } else {
            None
        };
        let single_part = segments.len() == 1 && extract_parts_total(&file.subject) <= 1;
        let known_content = KNOWN_CONTENT.is_match(&file.subject);
        if !is_explicit
            && (!known_content || single_part)
            && !segments.is_empty()
            && (!valid || segments[0].bytes <= max_probe_bytes)
        {
            class = Some(if single_part && LEGACY.is_match(&file.subject) {
                Class::Legacy
            } else {
                Class::Hidden
            });
        }
        manifest.push(Json::Obj(vec![
            ("subject", Json::str(file.subject.clone())),
            (
                "classification",
                Json::str(class.map_or("excluded", Class::name)),
            ),
            (
                "segments",
                Json::Arr(
                    file.segments
                        .iter()
                        .map(|s| {
                            Json::Obj(vec![
                                ("number", Json::str(s.number.clone())),
                                ("id", Json::str(s.id.clone())),
                                ("bytes", Json::str(s.bytes.clone())),
                            ])
                        })
                        .collect(),
                ),
            ),
        ]));
        if !valid && class.is_some() {
            structure_valid = false;
        }
        let Some(class) = class else { continue };
        if segments.is_empty() {
            continue;
        }
        if !valid {
            segments[0].number = 0;
        }
        let candidate = Candidate {
            subject: file.subject.clone(),
            explicit: is_explicit,
            segments,
            class,
        };
        let key = candidate.json().sha256();
        let family = || {
            family_key(&candidate.subject)
                .unwrap_or_else(|| key.clone())
                .to_lowercase()
        };
        let target = match class {
            Class::Index => &mut explicit,
            Class::Volume => &mut volumes,
            Class::Legacy => &mut legacy,
            Class::Hidden => &mut hidden,
        };
        match target.iter_mut().find(|(known, _)| *known == key) {
            Some(slot) => slot.1 = candidate.clone(),
            None => target.push((key.clone(), candidate.clone())),
        }
        if class == Class::Index {
            let family = family();
            if !index_families.contains(&family) {
                index_families.push(family);
            }
        }
        if class == Class::Volume {
            let family = family();
            if !families.contains(&family) {
                families.push(family);
                match first_volumes.iter_mut().find(|(known, _)| *known == key) {
                    Some(slot) => slot.1 = candidate.clone(),
                    None => first_volumes.push((key.clone(), candidate.clone())),
                }
            }
        }
    }
    hidden.sort_by_key(|(_, candidate)| candidate.segments[0].bytes);

    let matching_volumes: Vec<(String, Candidate)> = first_volumes
        .iter()
        .filter(|(key, candidate)| {
            let family = family_key(&candidate.subject)
                .unwrap_or_else(|| key.clone())
                .to_lowercase();
            index_families.contains(&family)
        })
        .cloned()
        .collect();

    // Family matches affect priority only; standalone and later volumes remain resumable.
    let mut ordered: Vec<(String, Candidate)> = Vec::new();
    for group in [
        explicit,
        matching_volumes,
        legacy,
        hidden,
        first_volumes,
        volumes,
    ] {
        for (key, candidate) in group {
            if !ordered.iter().any(|(known, _)| *known == key) {
                ordered.push((key, candidate));
            }
        }
    }
    Ok(Plan {
        candidates: ordered.into_iter().map(|(_, c)| c).collect(),
        manifest: Json::Arr(manifest),
        non_explicit_bytes,
        structure_valid,
    })
}

impl Plan {
    /// The signature a release's progress row is keyed by: the NZB's manifest and ordered
    /// candidates plus every limit that can block recovery.
    pub fn signature(
        &self,
        guid: &str,
        group: i64,
        article_limit: i64,
        state_limit: i64,
        alternate: bool,
    ) -> String {
        Json::Obj(vec![
            ("guid", Json::str(guid)),
            ("group", Json::Int(group)),
            ("article_limit", Json::Int(article_limit)),
            ("state_limit", Json::Int(state_limit)),
            ("alternate", Json::Bool(alternate)),
            ("manifest", self.manifest.clone()),
            (
                "candidates",
                Json::Arr(self.candidates.iter().map(Candidate::json).collect()),
            ),
        ])
        .sha256()
    }
}

/// A `file` element has no `segments` element.
#[derive(Debug)]
pub struct MissingSegments;

impl std::fmt::Display for MissingSegments {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("An NZB file has no segments element")
    }
}

impl std::error::Error for MissingSegments {}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn validates_integers_like_filter_var() {
        for (text, want) in [
            ("1", Some(1)),
            (" 2 ", Some(2)),
            ("+3", Some(3)),
            ("01", None),
            ("0", None),
            ("-1", None),
            ("1.0", None),
            ("", None),
            ("9223372036854775808", None),
        ] {
            assert_eq!(validate_positive_int(text), want, "{text:?}");
        }
    }
}
