//! A writer producing the same bytes as PHP's `XMLWriter` without indentation.

/// XML 1.0 allows tab, LF, CR and the non-control Unicode ranges. `XML_Response::xmlString` strips
/// the rest before anything reaches the writer.
pub fn clean(value: &str) -> String {
    value
        .chars()
        .filter(|&c| {
            matches!(c, '\t' | '\n' | '\r' | '\u{20}'..='\u{D7FF}' | '\u{E000}'..='\u{FFFD}' | '\u{10000}'..='\u{10FFFF}')
        })
        .collect()
}

#[derive(Default)]
pub struct XmlWriter {
    out: String,
    open: Vec<String>,
    /// The innermost open tag still accepts attributes: its `>` isn't written yet.
    in_tag: bool,
}

impl XmlWriter {
    pub fn document() -> Self {
        Self {
            out: "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".to_owned(),
            ..Self::default()
        }
    }

    fn close_tag(&mut self) {
        if self.in_tag {
            self.out.push('>');
            self.in_tag = false;
        }
    }

    pub fn start(&mut self, name: &str) {
        self.close_tag();
        self.out.push('<');
        self.out.push_str(name);
        self.open.push(name.to_owned());
        self.in_tag = true;
    }

    pub fn attr(&mut self, name: &str, value: &str) {
        self.out.push(' ');
        self.out.push_str(name);
        self.out.push_str("=\"");
        escape_attr(&mut self.out, &clean(value));
        self.out.push('"');
    }

    pub fn text(&mut self, value: &str) {
        self.close_tag();
        escape_text(&mut self.out, &clean(value));
    }

    pub fn cdata(&mut self, value: &str) {
        self.close_tag();
        self.out.push_str("<![CDATA[");
        self.out.push_str(&clean(value));
        self.out.push_str("]]>");
    }

    /// `writeElement`: an empty value still writes both tags.
    pub fn element(&mut self, name: &str, value: &str) {
        self.start(name);
        self.close_tag();
        self.text(value);
        self.end();
    }

    pub fn end(&mut self) {
        let Some(name) = self.open.pop() else {
            return;
        };
        if self.in_tag {
            self.out.push_str("/>");
            self.in_tag = false;
        } else {
            self.out.push_str("</");
            self.out.push_str(&name);
            self.out.push('>');
        }
    }

    /// `endDocument`: closes what's open and ends with a newline.
    pub fn finish(mut self) -> String {
        while !self.open.is_empty() {
            self.end();
        }
        self.out.push('\n');
        self.out
    }
}

fn escape_attr(out: &mut String, value: &str) {
    for ch in value.chars() {
        match ch {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\t' => out.push_str("&#9;"),
            '\n' => out.push_str("&#10;"),
            '\r' => out.push_str("&#13;"),
            c => out.push(c),
        }
    }
}

fn escape_text(out: &mut String, value: &str) {
    for ch in value.chars() {
        match ch {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\r' => out.push_str("&#13;"),
            c => out.push(c),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn matches_php_xmlwriter() {
        let raw = include_str!("../tests/fixtures/xml_writer.json");
        let cases: serde_json::Value = serde_json::from_str(raw).expect("fixture");
        for case in cases.as_array().expect("list") {
            let input = case["input"].as_str().expect("input");
            let mut xml = XmlWriter::document();
            xml.start("root");
            xml.attr("a", input);
            xml.element("e", input);
            xml.start("empty");
            xml.end();
            xml.start("t");
            xml.attr("b", input);
            xml.text(input);
            xml.end();
            xml.start("c");
            xml.cdata(input);
            xml.end();
            xml.end();
            assert_eq!(
                xml.finish(),
                case["xml"].as_str().expect("xml"),
                "{input:?}"
            );
        }
    }
}
