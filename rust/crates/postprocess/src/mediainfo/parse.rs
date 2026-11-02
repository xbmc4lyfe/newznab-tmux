//! `mhor/php-mediainfo` over mediainfo's `--OUTPUT=OLDXML`: the XML read as
//! `json_decode(json_encode(simplexml_load_string($xml)), true)` reads it, and the tracks and typed
//! attributes its builder makes from that.
//!
//! The library's quirks are kept, because they decide what reaches the database: an attribute
//! that appears once is a string, and a checker indexes it, so a lone `<Width>1920</Width>` is
//! `Rate("1", "9")`. A blank element is an empty array. A checker given the wrong shape threw a
//! `TypeError`, which failed the whole parse; that is [`ParseError`] here.

use std::collections::HashMap;

use quick_xml::Reader;
use quick_xml::events::{BytesStart, Event};

/// The parse failed, as some exception in PHP did.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ParseError(pub &'static str);

impl std::fmt::Display for ParseError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(self.0)
    }
}

impl std::error::Error for ParseError {}

/// A value of the decoded array: a string, or an array with its keys in order.
#[derive(Clone, Debug, PartialEq)]
pub enum Node {
    Str(String),
    Arr(Vec<(String, Node)>),
}

impl Node {
    fn empty() -> Self {
        Self::Arr(Vec::new())
    }

    /// `$value[$key]`: a string's byte, or an array's entry.
    pub fn index(&self, key: usize) -> Option<Node> {
        match self {
            Self::Str(text) => text
                .as_bytes()
                .get(key)
                .map(|byte| Self::Str(String::from_utf8_lossy(&[*byte]).into_owned())),
            Self::Arr(entries) => entries
                .iter()
                .find(|(name, _)| *name == key.to_string())
                .map(|(_, value)| value.clone()),
        }
    }

    pub fn get(&self, key: &str) -> Option<&Node> {
        match self {
            Self::Arr(entries) => entries
                .iter()
                .find(|(name, _)| name == key)
                .map(|(_, value)| value),
            Self::Str(_) => None,
        }
    }

    /// PHP's `empty()`.
    pub fn is_empty(&self) -> bool {
        match self {
            Self::Str(text) => text.is_empty() || text == "0",
            Self::Arr(entries) => entries.is_empty(),
        }
    }

    /// The string a concatenation makes of it: an array is `"Array"`.
    pub fn concat_text(&self) -> String {
        match self {
            Self::Str(text) => text.clone(),
            Self::Arr(_) => "Array".to_owned(),
        }
    }

    /// `implode($glue, $array)` for an array, the string itself otherwise.
    pub fn implode(&self, glue: &str) -> String {
        match self {
            Self::Str(text) => text.clone(),
            Self::Arr(entries) => entries
                .iter()
                .map(|(_, value)| value.concat_text())
                .collect::<Vec<_>>()
                .join(glue),
        }
    }
}

/// One parsed element, before the JSON round trip.
#[derive(Debug, Default)]
struct Element {
    name: String,
    attributes: Vec<(String, String)>,
    children: Vec<Child>,
}

#[derive(Debug)]
enum Child {
    Element(Element),
    /// Character data, with entities resolved; adjacent pieces are merged, as libxml does.
    Text(String),
    CData(String),
    Comment,
}

fn attributes(start: &BytesStart<'_>) -> Result<Vec<(String, String)>, ParseError> {
    let mut out = Vec::new();
    for attribute in start.attributes() {
        let attribute = attribute.map_err(|_| ParseError("invalid XML"))?;
        let key = AsRef::<str>::as_ref(&attribute.key).to_owned();
        let value = attribute
            .normalized_value(quick_xml::XmlVersion::default())
            .map_err(|_| ParseError("invalid XML"))?
            .into_owned();
        out.push((key, value));
    }
    Ok(out)
}

fn push_text(children: &mut Vec<Child>, text: &str) {
    if let Some(Child::Text(previous)) = children.last_mut() {
        previous.push_str(text);
    } else {
        children.push(Child::Text(text.to_owned()));
    }
}

/// The text of the document as libxml decodes it: invalid UTF-8 is first read as Latin-1, as
/// php-mediainfo's `utf8_encode` did, and a declared ISO-8859-1 document is then read as
/// Latin-1 again, as libxml honours the declaration.
fn document_text(bytes: &[u8]) -> Result<String, ParseError> {
    let latin1 = |bytes: &[u8]| {
        bytes
            .iter()
            .map(|&byte| char::from(byte))
            .collect::<String>()
    };
    let text = match std::str::from_utf8(bytes) {
        Ok(text) => text.to_owned(),
        Err(_) => latin1(bytes),
    };
    let declared = text
        .strip_prefix("<?xml")
        .and_then(|rest| rest.split_once("?>"))
        .map(|(declaration, _)| declaration.to_ascii_lowercase());
    let encoding = declared.as_deref().and_then(|declaration| {
        let at = declaration.find("encoding")?;
        let rest = declaration[at + "encoding".len()..].trim_start();
        let rest = rest.strip_prefix('=')?.trim_start();
        let quote = rest.chars().next()?;
        let rest = &rest[quote.len_utf8()..];
        Some(rest[..rest.find(quote)?].to_owned())
    });
    match encoding.as_deref() {
        None | Some("utf-8" | "utf8" | "us-ascii" | "ascii") => Ok(text),
        Some("iso-8859-1" | "latin1" | "iso_8859-1") => Ok(latin1(text.as_bytes())),
        Some(_) => Err(ParseError("unsupported document encoding")),
    }
}

/// The document's root element, or an error where `simplexml_load_string` returned false.
fn parse_document(bytes: &[u8]) -> Result<Element, ParseError> {
    let text = document_text(bytes)?;
    if text
        .chars()
        .any(|c| (c as u32) < 0x20 && !matches!(c, '\t' | '\n' | '\r'))
    {
        return Err(ParseError("invalid XML"));
    }
    let mut reader = Reader::from_str(&text);
    reader.config_mut().check_end_names = true;
    let mut stack: Vec<Element> = Vec::new();
    let mut root: Option<Element> = None;
    loop {
        let event = reader.read_event().map_err(|_| ParseError("invalid XML"))?;
        match event {
            Event::Start(start) => {
                if root.is_some() {
                    return Err(ParseError("content after the root element"));
                }
                stack.push(Element {
                    name: AsRef::<str>::as_ref(&start.name()).to_owned(),
                    attributes: attributes(&start)?,
                    children: Vec::new(),
                });
            }
            Event::Empty(start) => {
                let element = Element {
                    name: AsRef::<str>::as_ref(&start.name()).to_owned(),
                    attributes: attributes(&start)?,
                    children: Vec::new(),
                };
                match stack.last_mut() {
                    Some(parent) => parent.children.push(Child::Element(element)),
                    None if root.is_none() => root = Some(element),
                    None => return Err(ParseError("content after the root element")),
                }
            }
            Event::End(_) => {
                let element = stack.pop().ok_or(ParseError("invalid XML"))?;
                match stack.last_mut() {
                    Some(parent) => parent.children.push(Child::Element(element)),
                    None => root = Some(element),
                }
            }
            Event::Text(content) => {
                let decoded = content.xml10_content();
                match stack.last_mut() {
                    Some(parent) => push_text(&mut parent.children, &decoded),
                    None if decoded.trim().is_empty() => {}
                    None => return Err(ParseError("text outside the root element")),
                }
            }
            Event::GeneralRef(reference) => {
                let resolved = if let Ok(Some(character)) = reference.resolve_char_ref() {
                    character.to_string()
                } else {
                    match AsRef::<str>::as_ref(&reference) {
                        "amp" => "&".to_owned(),
                        "lt" => "<".to_owned(),
                        "gt" => ">".to_owned(),
                        "quot" => "\"".to_owned(),
                        "apos" => "'".to_owned(),
                        _ => return Err(ParseError("undeclared entity")),
                    }
                };
                let parent = stack.last_mut().ok_or(ParseError("invalid XML"))?;
                push_text(&mut parent.children, &resolved);
            }
            Event::CData(content) => {
                let parent = stack.last_mut().ok_or(ParseError("invalid XML"))?;
                parent
                    .children
                    .push(Child::CData(content.into_inner().into_owned()));
            }
            Event::Comment(_) => {
                if let Some(parent) = stack.last_mut() {
                    parent.children.push(Child::Comment);
                }
            }
            Event::Eof => break,
            Event::Decl(_) | Event::PI(_) | Event::DocType(_) => {}
        }
    }
    if !stack.is_empty() {
        return Err(ParseError("unclosed element"));
    }
    root.ok_or(ParseError("no root element"))
}

/// libxml's `xmlIsBlankNode` for a text node.
fn is_blank(text: &str) -> bool {
    text.chars().all(|c| matches!(c, ' ' | '\t' | '\n' | '\r'))
}

/// How a child element reads in its parent's JSON: a string when its first node is non-blank text
/// (every text and CDATA node joined, attributes and elements dropped), else an object.
fn child_value(element: &Element) -> Node {
    if let Some(Child::Text(first)) = element.children.first()
        && !is_blank(first)
    {
        let mut text = String::new();
        for child in &element.children {
            match child {
                Child::Text(piece) | Child::CData(piece) => text.push_str(piece),
                _ => {}
            }
        }
        return Node::Str(text);
    }
    object(element)
}

/// An element as a JSON object: its attributes, then its children grouped by name in order of
/// first appearance, repeated names becoming lists. Comments are children named `comment`.
fn object(element: &Element) -> Node {
    let mut entries: Vec<(String, Node)> = Vec::new();
    if !element.attributes.is_empty() {
        entries.push((
            "@attributes".to_owned(),
            Node::Arr(
                element
                    .attributes
                    .iter()
                    .map(|(key, value)| (key.clone(), Node::Str(value.clone())))
                    .collect(),
            ),
        ));
    }
    let mut groups: Vec<(String, Vec<Node>)> = Vec::new();
    let mut positions: HashMap<String, usize> = HashMap::new();
    for child in &element.children {
        let (name, value) = match child {
            Child::Element(inner) => (inner.name.clone(), child_value(inner)),
            Child::Comment => ("comment".to_owned(), Node::empty()),
            Child::Text(_) | Child::CData(_) => continue,
        };
        match positions.get(&name) {
            Some(&at) => groups[at].1.push(value),
            None => {
                positions.insert(name.clone(), groups.len());
                groups.push((name, vec![value]));
            }
        }
    }
    for (name, mut values) in groups {
        let value = if values.len() == 1 {
            values.remove(0)
        } else {
            Node::Arr(
                values
                    .into_iter()
                    .enumerate()
                    .map(|(index, value)| (index.to_string(), value))
                    .collect(),
            )
        };
        entries.push((name, value));
    }
    Node::Arr(entries)
}

/// `transformXmlToArray`.
pub fn to_array(xml: &[u8]) -> Result<Node, ParseError> {
    Ok(object(&parse_document(xml)?))
}

/// A typed attribute, as the default attribute checkers make them.
#[derive(Clone, Debug, PartialEq)]
pub enum Attribute {
    Raw(Node),
    Duration(i64),
    Mode { short: String, full: String },
    Rate { absolute: f64, text: String },
    Ratio { absolute: f64, text: String },
    Size(i64),
    DateTime(String),
    Cover(String),
}

impl Attribute {
    /// `getFullName()`, for a mode.
    pub fn full_name(&self) -> Option<&str> {
        match self {
            Self::Mode { full, .. } => Some(full),
            _ => None,
        }
    }

    /// `getTextValue()`, for a rate or ratio.
    pub fn text_value(&self) -> Option<&str> {
        match self {
            Self::Rate { text, .. } | Self::Ratio { text, .. } => Some(text),
            _ => None,
        }
    }

    /// `Rate::getAbsoluteValue()`: rounded half away from zero.
    pub fn rounded(&self) -> Option<i64> {
        match self {
            Self::Rate { absolute, .. } => Some(absolute.round() as i64),
            _ => None,
        }
    }

    /// The value PDO binds for it: an object through `__toString`. `None` for an array, which
    /// can't be bound.
    pub fn bound(&self) -> Option<String> {
        Some(match self {
            Self::Raw(Node::Str(text)) => text.clone(),
            Self::Raw(Node::Arr(_)) => return None,
            Self::Duration(value) | Self::Size(value) => value.to_string(),
            Self::Mode { short, .. } => short.clone(),
            Self::Rate { text, .. } | Self::Ratio { text, .. } => text.clone(),
            Self::DateTime(text) | Self::Cover(text) => text.clone(),
        })
    }
}

const DURATION: &[&str] = &[
    "duration",
    "delay_relative_to_video",
    "video0_delay",
    "delay",
];
const MODE: &[&str] = &[
    "overall_bit_rate_mode",
    "overall_bit_rate",
    "bit_rate_mode",
    "compression_mode",
    "codec",
    "format",
    "kind_of_stream",
    "writing_library",
    "id",
    "format_settings_sbr",
    "channel_positions",
    "default",
    "forced",
    "delay_origin",
    "scan_type",
    "interlacement",
    "frame_rate_mode",
    "format_settings_cabac",
    "unique_id",
];
const RATE: &[&str] = &[
    "maximum_bit_rate",
    "channel_s",
    "bit_rate",
    "sampling_rate",
    "bit_depth",
    "width",
    "nominal_bit_rate",
    "format_settings_reframes",
    "height",
    "resolution",
];
const RATIO: &[&str] = &["display_aspect_ratio", "original_display_aspect_ratio"];
const SIZE: &[&str] = &["file_size", "stream_size"];
const DATE_TIME: &[&str] = &[
    "file_last_modification_date",
    "file_last_modification_date_local",
];

/// PHP's `(float)` of a value: a string's numeric prefix, an array's emptiness, null's zero.
fn php_float(value: Option<&Node>) -> f64 {
    match value {
        None => 0.0,
        Some(Node::Arr(entries)) => f64::from(u8::from(!entries.is_empty())),
        Some(Node::Str(text)) => numeric_prefix(text),
    }
}

/// PHP's `(int)` of a value.
fn php_int(value: Option<&Node>) -> i64 {
    match value {
        Some(Node::Str(text)) => {
            let trimmed = text.trim_start_matches([' ', '\t', '\n', '\r', '\x0B', '\x0C']);
            let digits = trimmed.trim_start_matches(['+', '-']);
            let plain = digits.bytes().take_while(u8::is_ascii_digit).count();
            // A plain integer keeps full precision; anything with a fraction or exponent goes
            // through a float, as PHP's string-to-number conversion does.
            if plain > 0 && !matches!(digits.as_bytes().get(plain), Some(b'.' | b'e' | b'E')) {
                let negative = trimmed.starts_with('-');
                let magnitude = digits[..plain].parse::<i64>().unwrap_or(i64::MAX);
                return if negative { -magnitude } else { magnitude };
            }
            let float = numeric_prefix(text);
            if float.is_finite() { float as i64 } else { 0 }
        }
        other => php_float(other) as i64,
    }
}

/// The longest leading decimal number of a string (after whitespace), as PHP reads one.
fn numeric_prefix(text: &str) -> f64 {
    let trimmed = text.trim_start_matches([' ', '\t', '\n', '\r', '\x0B', '\x0C']);
    let bytes = trimmed.as_bytes();
    let mut end = 0;
    if matches!(bytes.first(), Some(b'+' | b'-')) {
        end = 1;
    }
    let digits_start = end;
    while bytes.get(end).is_some_and(u8::is_ascii_digit) {
        end += 1;
    }
    let mut mantissa = end > digits_start;
    if bytes.get(end) == Some(&b'.') {
        let fraction = end + 1;
        let mut after = fraction;
        while bytes.get(after).is_some_and(u8::is_ascii_digit) {
            after += 1;
        }
        if after > fraction || mantissa {
            mantissa = true;
            end = after;
        }
    }
    if !mantissa {
        return 0.0;
    }
    if matches!(bytes.get(end), Some(b'e' | b'E')) {
        let mut exponent = end + 1;
        if matches!(bytes.get(exponent), Some(b'+' | b'-')) {
            exponent += 1;
        }
        let start = exponent;
        while bytes.get(exponent).is_some_and(u8::is_ascii_digit) {
            exponent += 1;
        }
        if exponent > start {
            end = exponent;
        }
    }
    trimmed[..end].parse().unwrap_or(0.0)
}

/// A typed `string` parameter: a string passes, anything else is a `TypeError`.
fn string_parameter(value: Option<Node>) -> Result<String, ParseError> {
    match value {
        Some(Node::Str(text)) => Ok(text),
        _ => Err(ParseError("a string parameter got another type")),
    }
}

/// `ModeChecker::create`.
fn mode(value: &Node) -> Result<Attribute, ParseError> {
    let list = match value {
        Node::Str(_) => Node::Arr(vec![("0".to_owned(), value.clone())]),
        Node::Arr(_) => value.clone(),
    };
    let first = list.index(0);
    let second = list.index(1).or_else(|| first.clone());
    let name = |part: Option<Node>| match part {
        Some(array @ Node::Arr(_)) => Ok(array.implode(" ")),
        other => string_parameter(other),
    };
    Ok(Attribute::Mode {
        short: name(first)?,
        full: name(second)?,
    })
}

/// `AttributeFactory::create` with the default checkers, in their order.
fn attribute(name: &str, value: &Node) -> Result<Attribute, ParseError> {
    if name == "cover_data" {
        return string_parameter(Some(value.clone())).map(Attribute::Cover);
    }
    if DURATION.contains(&name) {
        return Ok(Attribute::Duration(php_int(value.index(0).as_ref())));
    }
    if MODE.contains(&name) {
        return mode(value);
    }
    if RATE.contains(&name) || name == "frame_rate" {
        return Ok(Attribute::Rate {
            absolute: php_float(value.index(0).as_ref()),
            text: string_parameter(value.index(1))?,
        });
    }
    if RATIO.contains(&name) {
        return Ok(Attribute::Ratio {
            absolute: php_float(value.index(0).as_ref()),
            text: string_parameter(value.index(1))?,
        });
    }
    if SIZE.contains(&name) {
        return Ok(Attribute::Size(php_int(value.index(0).as_ref())));
    }
    if DATE_TIME.contains(&name) {
        // `new DateTime($value)`; mediainfo's dates parse, and only the shape matters here.
        return string_parameter(Some(value.clone())).map(Attribute::DateTime);
    }
    Ok(Attribute::Raw(value.clone()))
}

/// One track's attributes by sanitized name.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Track {
    attributes: Vec<(String, Attribute)>,
}

impl Track {
    pub fn get(&self, name: &str) -> Option<&Attribute> {
        self.attributes
            .iter()
            .find(|(key, _)| key == name)
            .map(|(_, value)| value)
    }
}

/// `MediaInfoContainer`, for the track kinds read here.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Container {
    pub general: Option<Track>,
    pub videos: Vec<Track>,
    pub audios: Vec<Track>,
    pub subtitles: Vec<Track>,
}

/// `formatAttribute`.
fn format_attribute(name: &str) -> String {
    name.to_ascii_lowercase()
        .replace(' ', "_")
        .replace("__", "_")
        .trim_matches('_')
        .to_owned()
}

/// PHP's `$left + $right` for arrays: the left side, then the right side's new keys.
fn union(left: Node, right: Node) -> Node {
    let wrap = |node: Node| match node {
        Node::Arr(entries) => entries,
        string => vec![("0".to_owned(), string)],
    };
    let mut entries = wrap(left);
    for (key, value) in wrap(right) {
        if !entries.iter().any(|(existing, _)| *existing == key) {
            entries.push((key, value));
        }
    }
    Node::Arr(entries)
}

fn track(fields: &[(String, Node)]) -> Result<Track, ParseError> {
    let mut sanitized: Vec<(String, Node)> = Vec::new();
    for (key, value) in fields {
        let key = format_attribute(key);
        match sanitized.iter_mut().find(|(existing, _)| *existing == key) {
            Some((_, existing)) => {
                let left = std::mem::replace(existing, Node::empty());
                *existing = union(left, value.clone());
            }
            None => sanitized.push((key, value.clone())),
        }
    }
    let mut attributes = Vec::new();
    for (key, value) in sanitized {
        if key.starts_with('@') {
            continue;
        }
        let typed = attribute(&key, &value)?;
        attributes.push((key, typed));
    }
    Ok(Track { attributes })
}

/// `MediaInfoOutputParser::getMediaInfoContainer` with unknown track types ignored.
pub fn container(xml: &[u8]) -> Result<Container, ParseError> {
    let root = to_array(xml)?;
    let file = root
        .get("File")
        .ok_or(ParseError("not mediainfo's old XML format"))?;
    let tracks = match file {
        Node::Arr(_) => file.get("track"),
        Node::Str(_) => return Err(ParseError("File is a string")),
    };
    let mut container = Container::default();
    let entries = match tracks {
        Some(Node::Arr(entries)) => entries.as_slice(),
        _ => &[],
    };
    for (_, entry) in entries {
        let Node::Arr(fields) = entry else {
            continue;
        };
        let Some(Node::Str(kind)) = entry.get("@attributes").and_then(|a| a.get("type")) else {
            continue;
        };
        let target = match kind.as_str() {
            "General" => None,
            "Video" => Some(&mut container.videos),
            "Audio" => Some(&mut container.audios),
            "Text" => Some(&mut container.subtitles),
            // Images, menus and others are built, and can fail, but nothing reads them.
            "Image" | "Menu" | "Other" => {
                track(fields)?;
                continue;
            }
            _ => continue,
        };
        let built = track(fields)?;
        match target {
            Some(list) => list.push(built),
            None => container.general = Some(built),
        }
    }
    Ok(container)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn numbers_read_as_php_reads_them() {
        let int = |text: &str| php_int(Some(&Node::Str(text.to_owned())));
        assert_eq!(int("5400000"), 5_400_000);
        assert_eq!(int("1 h 30 min"), 1);
        assert_eq!(int("58.9"), 58);
        assert_eq!(int("1e3"), 1000);
        assert_eq!(int("x"), 0);
        assert_eq!(numeric_prefix("23.976 FPS"), 23.976);
        assert_eq!(numeric_prefix(".5"), 0.5);
        assert_eq!(numeric_prefix("-"), 0.0);
    }

    #[test]
    fn attribute_names_are_sanitized() {
        assert_eq!(format_attribute("Channel_s_"), "channel_s");
        assert_eq!(
            format_attribute("Format_settings__SBR"),
            "format_settings_sbr"
        );
        assert_eq!(format_attribute("Bit rate"), "bit_rate");
        assert_eq!(format_attribute("a___b"), "a__b");
    }
}
