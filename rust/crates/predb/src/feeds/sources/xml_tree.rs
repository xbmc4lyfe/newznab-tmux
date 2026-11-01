//! A strict, small XML reader with the view of the document SimpleXML gave the PHP: elements,
//! their attributes and their direct text. The catalogue sources reject whatever libxml would have
//! refused to load, so this parser is strict about well-formedness and never recovers.

use quick_xml::events::Event;
use quick_xml::{Reader, XmlVersion};

#[derive(Debug, Default)]
pub(super) struct XmlElement {
    pub name: String,
    pub attributes: Vec<(String, String)>,
    pub elements: Vec<XmlElement>,
    /// The element's own text and CDATA, without descendants: SimpleXML's `(string)` cast.
    pub text: String,
}

impl XmlElement {
    pub fn named<'a>(&'a self, name: &'a str) -> impl Iterator<Item = &'a XmlElement> {
        self.elements
            .iter()
            .filter(move |element| element.name == name)
    }

    pub fn attribute(&self, name: &str) -> Option<&str> {
        self.attributes
            .iter()
            .find(|(key, _)| key == name)
            .map(|(_, value)| value.as_str())
    }

    /// Attribute names, skipping prefixed ones the way `->attributes()` does without a namespace.
    pub fn plain_attribute_names(&self) -> Vec<&str> {
        let mut names: Vec<&str> = self
            .attributes
            .iter()
            .map(|(key, _)| key.as_str())
            .filter(|key| !key.contains(':'))
            .collect();
        names.sort_unstable();
        names
    }

    /// Whether any element in the subtree declares a namespace: `getDocNamespaces(true) !== []`.
    pub fn declares_namespaces(&self) -> bool {
        self.attributes
            .iter()
            .any(|(key, _)| key == "xmlns" || key.starts_with("xmlns:"))
            || self.elements.iter().any(XmlElement::declares_namespaces)
    }
}

/// XML 1.0 `Char`.
fn is_xml_char(c: char) -> bool {
    matches!(c, '\t' | '\n' | '\r' | '\u{20}'..='\u{d7ff}' | '\u{e000}'..='\u{fffd}' | '\u{10000}'..='\u{10ffff}')
}

fn predefined(name: &str) -> Option<&'static str> {
    Some(match name {
        "amp" => "&",
        "lt" => "<",
        "gt" => ">",
        "quot" => "\"",
        "apos" => "'",
        _ => return None,
    })
}

/// A reference's replacement text, or `None` for an undeclared entity or an invalid character.
fn resolve_reference(name: &str) -> Option<String> {
    if let Some(number) = name.strip_prefix('#') {
        let code = match number.strip_prefix('x') {
            Some(hex) if !hex.is_empty() && hex.bytes().all(|b| b.is_ascii_hexdigit()) => {
                u32::from_str_radix(hex, 16).ok()?
            }
            Some(_) => return None,
            None if !number.is_empty() && number.bytes().all(|b| b.is_ascii_digit()) => {
                number.parse().ok()?
            }
            None => return None,
        };
        return char::from_u32(code)
            .filter(|c| is_xml_char(*c))
            .map(String::from);
    }
    predefined(name).map(str::to_owned)
}

/// libxml's `xmlParserMaxDepth` without `LIBXML_PARSEHUGE`. It also keeps recursion over the
/// tree, and dropping it, within the stack.
const MAX_DEPTH: usize = 256;

/// Parses a document that is already valid UTF-8 with no DOCTYPE. `None` is any well-formedness
/// failure.
pub(super) fn parse(xml: &str) -> Option<XmlElement> {
    if !xml.chars().all(is_xml_char) {
        return None;
    }
    let mut reader = Reader::from_str(xml);
    let config = reader.config_mut();
    config.trim_text(false);
    config.check_end_names = true;
    config.check_comments = true;
    let mut stack: Vec<XmlElement> = Vec::new();
    let mut root: Option<XmlElement> = None;
    let mut index = 0usize;
    loop {
        let event = reader.read_event().ok()?;
        let first = index == 0;
        index += 1;
        match event {
            Event::Decl(declaration) if first => {
                // libxml transcodes from the declared encoding. The text here has been read as
                // UTF-8, so a declaration of anything else is trusted only when it can't matter
                // (plain ASCII) and never for the wide encodings, which libxml refuses on 8-bit
                // text.
                if let Some(Ok(encoding)) = declaration.encoding() {
                    let name = encoding.to_ascii_lowercase();
                    let utf8 = matches!(name.as_str(), "utf-8" | "utf8");
                    let wide = ["utf-16", "utf-32", "ucs-"]
                        .iter()
                        .any(|prefix| name.starts_with(prefix));
                    if !utf8 && (wide || !xml.is_ascii()) {
                        return None;
                    }
                }
            }
            Event::Decl(_) | Event::DocType(_) => return None,
            Event::Start(start) => {
                if root.is_some() {
                    return None;
                }
                if stack.len() > MAX_DEPTH {
                    return None;
                }
                stack.push(element_from(&start)?);
            }
            Event::Empty(start) => {
                let element = element_from(&start)?;
                match stack.last_mut() {
                    Some(parent) => parent.elements.push(element),
                    None if root.is_none() => root = Some(element),
                    None => return None,
                }
            }
            Event::End(_) => {
                let element = stack.pop()?;
                match stack.last_mut() {
                    Some(parent) => parent.elements.push(element),
                    None => root = Some(element),
                }
            }
            Event::Text(text) => {
                let content = text.xml10_content();
                if content.contains("]]>") {
                    return None;
                }
                match stack.last_mut() {
                    Some(parent) => parent.text.push_str(&content),
                    None if content.trim_matches([' ', '\t', '\r', '\n']).is_empty() => {}
                    None => return None,
                }
            }
            Event::CData(data) => stack.last_mut()?.text.push_str(&data.into_inner()),
            Event::GeneralRef(reference) => {
                let replacement = resolve_reference(&reference.xml10_content())?;
                stack.last_mut()?.text.push_str(&replacement);
            }
            Event::Comment(_) | Event::PI(_) => {}
            Event::Eof => break,
        }
    }
    if stack.is_empty() { root } else { None }
}

fn element_from(start: &quick_xml::events::BytesStart<'_>) -> Option<XmlElement> {
    let name = start.name().as_ref().to_owned();
    let mut attributes = Vec::new();
    for attribute in start.attributes() {
        let attribute = attribute.ok()?;
        let key = attribute.key.as_ref().to_owned();
        let value = attribute
            .normalized_value_with(XmlVersion::Implicit1_0, 1, predefined)
            .ok()?
            .into_owned();
        if !value.chars().all(is_xml_char) {
            return None;
        }
        attributes.push((key, value));
    }
    Some(XmlElement {
        name,
        attributes,
        ..XmlElement::default()
    })
}

#[cfg(test)]
mod tests {
    #[test]
    fn nesting_past_libxmls_limit_is_refused() {
        let nested = |depth: usize| format!("{}{}", "<a>".repeat(depth), "</a>".repeat(depth));
        assert!(super::parse(&nested(257)).is_some());
        assert!(super::parse(&nested(258)).is_none());
        assert!(super::parse(&nested(200_000)).is_none());
    }

    use super::*;

    #[test]
    fn reads_elements_text_and_attributes() {
        let root = parse(
            "<?xml version=\"1.0\"?><a x=\"1&amp;\t2\"><!-- c --><b>t<![CDATA[<u>]]>&#65;</b><c/></a>\n",
        )
        .expect("well formed");
        assert_eq!(root.name, "a");
        assert_eq!(root.attribute("x"), Some("1& 2"));
        assert_eq!(root.elements.len(), 2);
        assert_eq!(root.elements[0].text, "t<u>A");
    }

    #[test]
    fn refuses_what_libxml_refuses() {
        for bad in [
            "",
            "<a>",
            "<a></b>",
            "<a/><b/>",
            "x<a/>",
            "<a/>x",
            " <?xml version=\"1.0\"?><a/>",
            "<a>&nope;</a>",
            "<a>&#0;</a>",
            "<a>&#xD800;</a>",
            "<a>\u{1}</a>",
            "<a>]]></a>",
            "<a x=\"1\" x=\"2\"/>",
            "<a><!-- -- --></a>",
            "<a>&</a>",
            "<a x=1/>",
        ] {
            assert!(parse(bad).is_none(), "{bad:?}");
        }
    }
}
