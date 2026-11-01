//! Reads NZB XML into the elements the app trusts, traversing by namespace URI: an element in a
//! foreign namespace is never a file, group, segment or head.

use std::borrow::Cow;

use anyhow::{Result, bail};
use encoding_rs::{Encoding, UTF_16BE, UTF_16LE};
use roxmltree::{Document, Node, ParsingOptions};

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
    /// `head` elements in the document's namespace, and whether any of them holds a password.
    pub heads: usize,
    pub password_in_head: bool,
    pub files: Vec<ParsedFile>,
    /// Root children named `file` in any namespace.
    pub file_elements: usize,
    /// Whether the document declares an entity.
    pub declares_entities: bool,
}

/// libxml's limit without `XML_PARSE_HUGE`.
const MAX_DEPTH: usize = 256;

fn declares_entities(xml: &str) -> bool {
    let bytes = xml.as_bytes();
    bytes.windows(9).any(|window| {
        window[..8].eq_ignore_ascii_case(b"<!ENTITY") && window[8].is_ascii_whitespace()
    })
}

/// The encoding an XML declaration names, when the document opens with one.
fn declared_encoding(xml: &[u8]) -> Option<String> {
    let rest = xml.strip_prefix(b"<?xml")?;
    let end = rest.windows(2).position(|pair| pair == b"?>")?;
    let declaration = std::str::from_utf8(&rest[..end]).ok()?;
    let (_, value) = declaration.split_once("encoding")?;
    let value = value.trim_start().strip_prefix('=')?.trim_start();
    let quote = value.chars().next().filter(|c| matches!(c, '"' | '\''))?;
    let value = &value[1..];
    Some(value[..value.find(quote)?].to_owned())
}

/// The document as text, by its byte order mark or else its XML declaration, as libxml reads it.
/// Anything invalid in that encoding, or an encoding nothing here knows, is an error.
fn decoded(xml: &[u8]) -> Result<Cow<'_, str>> {
    let invalid = || anyhow::anyhow!("Invalid NZB.");
    let utf16 = match xml {
        [0xFF, 0xFE, rest @ ..] => Some((UTF_16LE, rest)),
        [0xFE, 0xFF, rest @ ..] => Some((UTF_16BE, rest)),
        [0x3C, 0x00, 0x3F, 0x00, ..] => Some((UTF_16LE, xml)),
        [0x00, 0x3C, 0x00, 0x3F, ..] => Some((UTF_16BE, xml)),
        _ => None,
    };
    if let Some((encoding, rest)) = utf16 {
        return encoding
            .decode_without_bom_handling_and_without_replacement(rest)
            .ok_or_else(invalid);
    }
    if let Some(rest) = xml.strip_prefix(&[0xEF, 0xBB, 0xBF]) {
        return Ok(Cow::Borrowed(
            std::str::from_utf8(rest).map_err(|_| invalid())?,
        ));
    }
    let label = declared_encoding(xml).unwrap_or_default();
    match label.to_ascii_lowercase().as_str() {
        "" | "utf-8" | "utf8" => Ok(Cow::Borrowed(
            std::str::from_utf8(xml).map_err(|_| invalid())?,
        )),
        // `for_label` gives windows-1252 for this, which reads 0x80 to 0x9F differently.
        "iso-8859-1" | "iso8859-1" | "latin1" | "latin-1" => {
            Ok(Cow::Owned(xml.iter().map(|&byte| byte as char).collect()))
        }
        "us-ascii" | "ascii" if xml.is_ascii() => Ok(Cow::Borrowed(
            std::str::from_utf8(xml).map_err(|_| invalid())?,
        )),
        other => Encoding::for_label(other.as_bytes())
            .and_then(|encoding| encoding.decode_without_bom_handling_and_without_replacement(xml))
            .ok_or_else(invalid),
    }
}

/// Parses a document. Anything not well-formed, an undeclared entity or a non-NZB root is an error.
///
/// libxml recovers from an undeclared element prefix, and this refuses it, so such a document is
/// never trusted.
pub fn parse(xml: &[u8]) -> Result<ParsedNzb> {
    let text = decoded(xml)?;
    let options = ParsingOptions {
        allow_dtd: true,
        ..ParsingOptions::default()
    };
    let Ok(document) = Document::parse_with_options(&text, options) else {
        bail!("Invalid NZB.");
    };
    let root = document.root_element();
    let namespace = root.tag_name().namespace().unwrap_or("");
    if root.tag_name().name() != "nzb" || !["", CANONICAL_NAMESPACE].contains(&namespace) {
        bail!("Invalid NZB.");
    }
    if too_deep(root) {
        bail!("Invalid NZB.");
    }
    let mut nzb = ParsedNzb {
        declares_entities: declares_entities(&text),
        ..ParsedNzb::default()
    };
    let named = |node: &Node<'_, '_>, name: &str| {
        node.tag_name().name() == name && node.tag_name().namespace().unwrap_or("") == namespace
    };
    for child in root.children().filter(Node::is_element) {
        if child.tag_name().name() == "file" {
            nzb.file_elements += 1;
        }
        if named(&child, "head") {
            nzb.heads += 1;
            for meta in child.children().filter(|node| named(node, "meta")) {
                let kind = attribute(meta, "type");
                nzb.password_in_head |= kind == "password";
                if nzb.heads == 1 {
                    nzb.metadata.push(ParsedMeta {
                        kind: kind.to_owned(),
                        text: direct_text(meta),
                        child_elements: element_count(meta),
                    });
                }
            }
        } else if named(&child, "file") {
            nzb.files.push(parsed_file(child, &named));
        }
    }
    Ok(nzb)
}

fn parsed_file(file: Node<'_, '_>, named: &impl Fn(&Node<'_, '_>, &str) -> bool) -> ParsedFile {
    let mut parsed = ParsedFile {
        subject: attribute(file, "subject").to_owned(),
        ..ParsedFile::default()
    };
    if let Some(groups) = file.children().find(|node| named(node, "groups")) {
        parsed.groups = groups
            .children()
            .filter(|node| named(node, "group"))
            .map(direct_text)
            .collect();
    }
    for container in file
        .children()
        .filter(|node| node.is_element() && node.tag_name().name() == "segments")
    {
        parsed.segments_containers += 1;
        parsed.segments_children += element_count(container);
    }
    if let Some(segments) = file.children().find(|node| named(node, "segments")) {
        parsed.segments = segments
            .children()
            .filter(|node| named(node, "segment"))
            .map(|segment| ParsedSegment {
                number: attribute(segment, "number").to_owned(),
                bytes: attribute(segment, "bytes").to_owned(),
                text: direct_text(segment),
                child_elements: element_count(segment),
            })
            .collect();
    }
    parsed
}

/// Attributes in a namespace are other attributes.
fn attribute<'a>(node: Node<'a, '_>, name: &str) -> &'a str {
    node.attributes()
        .find(|attribute| attribute.namespace().is_none() && attribute.name() == name)
        .map_or("", |attribute| attribute.value())
}

fn element_count(node: Node<'_, '_>) -> usize {
    node.children().filter(Node::is_element).count()
}

fn direct_text(node: Node<'_, '_>) -> String {
    node.children()
        .filter(Node::is_text)
        .filter_map(|text| text.text())
        .collect()
}

fn too_deep(root: Node<'_, '_>) -> bool {
    let mut pending = vec![(root, 1)];
    while let Some((node, depth)) = pending.pop() {
        if depth > MAX_DEPTH {
            return true;
        }
        pending.extend(
            node.children()
                .filter(Node::is_element)
                .map(|child| (child, depth + 1)),
        );
    }
    false
}

#[cfg(test)]
mod tests {
    use super::*;

    const FILE: &str = r#"<file subject="s (1/1)"><groups><group>g</group></groups><segments><segment number="1" bytes="1">a@x</segment></segments></file>"#;

    #[test]
    fn documents_libxml_refuses_are_refused() {
        for document in [
            format!("<nzb>{FILE}</nzb>junk"),
            format!("<nzb>{FILE}</nzb><nzb/>"),
            format!(" <?xml version=\"1.0\"?><nzb>{FILE}</nzb>"),
            r#"<nzb><file poster="a & b" subject="s"/></nzb>"#.to_owned(),
            r#"<nzb><file subject="a<b (1/1)"/></nzb>"#.to_owned(),
            format!(r#"<nzb><x a="1" a="2"/>{FILE}</nzb>"#),
            format!("<nzb>\u{1}{FILE}</nzb>"),
            format!("<nzb>&#1;{FILE}</nzb>"),
            format!("<nzb>\0{FILE}</nzb>"),
            format!(
                "<nzb>{}{}{FILE}</nzb>",
                "<a>".repeat(300),
                "</a>".repeat(300)
            ),
        ] {
            assert!(parse(document.as_bytes()).is_err(), "{document}");
        }
    }

    #[test]
    fn segments_in_a_foreign_container_are_never_trusted() -> Result<()> {
        let foreign = r#"<o:segments><segment number="1" bytes="1">evil@x</segment></o:segments>"#;
        let alone =
            format!(r#"<nzb xmlns:o="urn:o"><file subject="s (1/1)">{foreign}</file></nzb>"#);
        let parsed = parse(alone.as_bytes())?;
        assert!(parsed.files[0].segments.is_empty());
        assert_eq!(parsed.files[0].segments_containers, 1);

        let real = r#"<segments><segment number="2" bytes="1">a@x</segment></segments>"#;
        for inner in [format!("{foreign}{real}"), format!("{real}{foreign}")] {
            let beside =
                format!(r#"<nzb xmlns:o="urn:o"><file subject="s (1/1)">{inner}</file></nzb>"#);
            let parsed = parse(beside.as_bytes())?;
            let texts: Vec<&str> = parsed.files[0]
                .segments
                .iter()
                .map(|s| s.text.as_str())
                .collect();
            assert_eq!(texts, ["a@x"]);
            assert_eq!(parsed.files[0].segments_containers, 2);
        }
        Ok(())
    }

    #[test]
    fn the_encoding_comes_from_the_document() -> Result<()> {
        let latin1 = b"<?xml version=\"1.0\" encoding=\"iso-8859-1\"?><nzb><file subject=\"caf\xE9\"/></nzb>";
        assert_eq!(parse(latin1)?.files[0].subject, "caf\u{e9}");
        let doubled = b"<?xml version=\"1.0\" encoding=\"iso-8859-1\"?><nzb><file subject=\"caf\xC3\xA9\"/></nzb>";
        assert_eq!(parse(doubled)?.files[0].subject, "caf\u{c3}\u{a9}");

        let text =
            "<?xml version=\"1.0\" encoding=\"utf-16\"?><nzb><file subject=\"caf\u{e9}\"/></nzb>";
        let little: Vec<u8> = text.encode_utf16().flat_map(u16::to_le_bytes).collect();
        let big: Vec<u8> = text.encode_utf16().flat_map(u16::to_be_bytes).collect();
        for document in [
            [&[0xFF, 0xFE], little.as_slice()].concat(),
            [&[0xFE, 0xFF], big.as_slice()].concat(),
            little,
            big,
        ] {
            assert_eq!(parse(&document)?.files[0].subject, "caf\u{e9}");
        }

        assert!(parse(b"<nzb><file subject=\"caf\xE9\"/></nzb>").is_err());
        assert!(parse(b"<?xml version=\"1.0\" encoding=\"no-such\"?><nzb/>").is_err());
        Ok(())
    }

    #[test]
    fn namespaced_attributes_are_other_attributes() -> Result<()> {
        let document = r#"<nzb xmlns:o="urn:o"><file o:subject="z" subject="s"/></nzb>"#;
        assert_eq!(parse(document.as_bytes())?.files[0].subject, "s");
        let document =
            r#"<nzb xmlns:o="urn:o"><head><meta o:type="password">x</meta></head></nzb>"#;
        let parsed = parse(document.as_bytes())?;
        assert!(!parsed.password_in_head);
        assert_eq!(parsed.metadata[0].kind, "");
        Ok(())
    }
}
