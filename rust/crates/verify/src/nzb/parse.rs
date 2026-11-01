//! Reads NZB XML into the elements the app trusts, traversing by namespace URI: an element in a
//! foreign namespace is never a file, group, segment or head.

use anyhow::{Result, bail};
use quick_xml::events::attributes::Attributes;
use quick_xml::events::{BytesStart, Event};
use quick_xml::name::ResolveResult;
use quick_xml::{NsReader, XmlVersion};

pub const CANONICAL_NAMESPACE: &str = "http://www.newzbin.com/DTD/2003/nzb";

/// One `segment` in the document's namespace.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ParsedSegment {
    pub number: String,
    pub bytes: String,
    pub text: String,
    pub child_elements: usize,
}

/// One `file` in the document's namespace.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ParsedFile {
    pub subject: String,
    /// `group` children of the first `groups` container.
    pub groups: Vec<String>,
    /// `segment` children of the first `segments` container.
    pub segments: Vec<ParsedSegment>,
    /// Children named `segments` in any namespace.
    pub segments_containers: usize,
    /// Element children of `segments` containers, in any namespace.
    pub segments_children: usize,
}

/// One `meta` in the first `head`.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ParsedMeta {
    pub kind: String,
    pub text: String,
    pub child_elements: usize,
}

/// An NZB as far as the app reads one.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ParsedNzb {
    /// The first `head`'s metadata.
    pub metadata: Vec<ParsedMeta>,
    /// `head` elements in the document's namespace, and whether a later one held a password.
    pub heads: usize,
    pub password_in_later_head: bool,
    pub files: Vec<ParsedFile>,
    /// Root children named `file` in any namespace.
    pub file_elements: usize,
    /// Whether the document declares a namespace prefix or a doctype.
    pub has_prefixes: bool,
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum Level {
    Other,
    Root,
    Head,
    Meta,
    LaterMeta,
    File,
    Groups,
    Group,
    Segments,
    Segment,
    Skipped,
}

fn attribute(attributes: Attributes<'_>, name: &str) -> Result<String> {
    for attribute in attributes {
        let attribute = attribute?;
        // Attributes in a namespace are other attributes.
        if attribute.key.as_ref() == name {
            return Ok(attribute
                .normalized_value(XmlVersion::Implicit1_0)?
                .into_owned());
        }
    }
    Ok(String::new())
}

/// Parses a document. Malformed XML, an undeclared entity or a non-NZB root is an error.
pub fn parse(xml: &[u8]) -> Result<ParsedNzb> {
    let Ok(xml) = std::str::from_utf8(xml) else {
        bail!("Invalid NZB.");
    };
    let mut reader = NsReader::from_str(xml);
    reader.config_mut().check_end_names = true;
    let mut nzb = ParsedNzb::default();
    let mut namespace = String::new();
    let mut stack: Vec<Level> = Vec::new();
    let mut seen_root = false;
    let mut first_groups = false;
    let mut first_segments = false;
    loop {
        let (resolved, event) = reader.read_resolved_event()?;
        match event {
            Event::Start(ref element) | Event::Empty(ref element) => {
                let empty = matches!(event, Event::Empty(_));
                let in_namespace = match &resolved {
                    ResolveResult::Bound(found) => AsRef::<str>::as_ref(found) == namespace,
                    ResolveResult::Unbound => namespace.is_empty(),
                    ResolveResult::Unknown(_) => bail!("Invalid NZB."),
                };
                if element
                    .attributes()
                    .flatten()
                    .any(|a| a.key.as_ref().starts_with("xmlns:"))
                {
                    nzb.has_prefixes = true;
                }
                let local = element.local_name();
                let local: &str = local.as_ref();
                let level = if !seen_root {
                    if stack.is_empty() {
                        if local != "nzb" {
                            bail!("Invalid NZB.");
                        }
                        namespace = match resolved {
                            ResolveResult::Bound(found) => AsRef::<str>::as_ref(&found).to_owned(),
                            _ => String::new(),
                        };
                        if !namespace.is_empty() && namespace != CANONICAL_NAMESPACE {
                            bail!("Invalid NZB.");
                        }
                        seen_root = true;
                    }
                    Level::Root
                } else {
                    child_level(
                        &mut nzb,
                        element,
                        stack.last().copied(),
                        local,
                        in_namespace,
                        &mut first_groups,
                        &mut first_segments,
                    )?
                };
                if !empty {
                    stack.push(level);
                }
            }
            Event::End(_) => {
                stack.pop();
            }
            Event::Text(text) => {
                let decoded = text.xml10_content();
                push_text(&mut nzb, stack.last().copied(), &decoded);
            }
            Event::CData(data) => {
                let decoded = data.xml10_content();
                push_text(&mut nzb, stack.last().copied(), &decoded);
            }
            Event::GeneralRef(reference) => {
                let decoded = match reference.resolve_char_ref()? {
                    Some(c) => c.to_string(),
                    None => {
                        let name = reference.xml10_content();
                        match quick_xml::escape::resolve_predefined_entity(&name) {
                            Some(value) => value.to_owned(),
                            None => bail!("Invalid NZB."),
                        }
                    }
                };
                push_text(&mut nzb, stack.last().copied(), &decoded);
            }
            Event::DocType(_) => nzb.has_prefixes = true,
            Event::Eof => break,
            _ => {}
        }
    }
    if !seen_root || !stack.is_empty() {
        bail!("Invalid NZB.");
    }
    Ok(nzb)
}

fn push_text(nzb: &mut ParsedNzb, level: Option<Level>, text: &str) {
    match level {
        Some(Level::Meta) => {
            if let Some(meta) = nzb.metadata.last_mut() {
                meta.text.push_str(text);
            }
        }
        Some(Level::Group) => {
            if let Some(group) = nzb.files.last_mut().and_then(|file| file.groups.last_mut()) {
                group.push_str(text);
            }
        }
        Some(Level::Segment) => {
            if let Some(segment) = nzb
                .files
                .last_mut()
                .and_then(|file| file.segments.last_mut())
            {
                segment.text.push_str(text);
            }
        }
        _ => {}
    }
}

#[allow(clippy::too_many_arguments)]
fn child_level(
    nzb: &mut ParsedNzb,
    element: &BytesStart<'_>,
    parent: Option<Level>,
    local: &str,
    in_namespace: bool,
    first_groups: &mut bool,
    first_segments: &mut bool,
) -> Result<Level> {
    Ok(match parent {
        Some(Level::Root) => {
            if local == "file" {
                nzb.file_elements += 1;
            }
            if !in_namespace {
                Level::Other
            } else if local == "file" {
                nzb.files.push(ParsedFile {
                    subject: attribute(element.attributes(), "subject")?,
                    ..ParsedFile::default()
                });
                *first_groups = true;
                *first_segments = true;
                Level::File
            } else if local == "head" {
                nzb.heads += 1;
                Level::Head
            } else {
                Level::Other
            }
        }
        Some(Level::Head) if in_namespace && local == "meta" => {
            if nzb.heads == 1 {
                nzb.metadata.push(ParsedMeta {
                    kind: attribute(element.attributes(), "type")?,
                    ..ParsedMeta::default()
                });
                Level::Meta
            } else {
                if attribute(element.attributes(), "type")? == "password" {
                    nzb.password_in_later_head = true;
                }
                Level::LaterMeta
            }
        }
        Some(Level::Meta) => {
            if let Some(meta) = nzb.metadata.last_mut() {
                meta.child_elements += 1;
            }
            Level::Skipped
        }
        Some(Level::File) => {
            let file = nzb.files.last_mut().expect("a file level has its file");
            if local == "segments" {
                file.segments_containers += 1;
            }
            if !in_namespace {
                if local == "segments" {
                    Level::Segments
                } else {
                    Level::Other
                }
            } else if local == "groups" && std::mem::take(first_groups) {
                Level::Groups
            } else if local == "segments" && std::mem::take(first_segments) {
                Level::Segments
            } else if local == "segments" {
                Level::Skipped
            } else {
                Level::Other
            }
        }
        Some(Level::Groups) if in_namespace && local == "group" => {
            nzb.files
                .last_mut()
                .expect("groups has its file")
                .groups
                .push(String::new());
            Level::Group
        }
        Some(Level::Segments) => {
            let file = nzb.files.last_mut().expect("segments has its file");
            file.segments_children += 1;
            if in_namespace && local == "segment" {
                file.segments.push(ParsedSegment {
                    number: attribute(element.attributes(), "number")?,
                    bytes: attribute(element.attributes(), "bytes")?,
                    ..ParsedSegment::default()
                });
                Level::Segment
            } else {
                Level::Other
            }
        }
        Some(Level::Segment) => {
            if let Some(segment) = nzb
                .files
                .last_mut()
                .and_then(|file| file.segments.last_mut())
            {
                segment.child_elements += 1;
            }
            Level::Skipped
        }
        _ => Level::Other,
    })
}
