//! JSON in the exact bytes PHP's `json_encode` writes, with keys in insertion order.

use std::fmt::Write;

#[derive(Clone, Debug, PartialEq)]
pub enum J {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    Str(String),
    Arr(Vec<J>),
    Obj(Vec<(String, J)>),
}

/// `Plain` is `response()->json()` with no flags; `Unescaped` adds `JSON_UNESCAPED_SLASHES` and
/// `JSON_UNESCAPED_UNICODE`, which the v1 and v2 presenters use.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Style {
    Plain,
    Unescaped,
}

impl J {
    pub fn str(value: impl Into<String>) -> Self {
        Self::Str(value.into())
    }

    pub fn opt_str(value: Option<String>) -> Self {
        value.map_or(Self::Null, Self::Str)
    }

    pub fn opt_int(value: Option<i64>) -> Self {
        value.map_or(Self::Null, Self::Int)
    }

    pub fn obj<const N: usize>(entries: [(&str, J); N]) -> Self {
        Self::Obj(
            entries
                .into_iter()
                .map(|(key, value)| (key.to_owned(), value))
                .collect(),
        )
    }

    pub fn encode(&self, style: Style) -> String {
        let mut out = String::new();
        self.write(&mut out, style);
        out
    }

    fn write(&self, out: &mut String, style: Style) {
        match self {
            Self::Null => out.push_str("null"),
            Self::Bool(value) => out.push_str(if *value { "true" } else { "false" }),
            Self::Int(value) => {
                let _ = write!(out, "{value}");
            }
            Self::Float(value) => write_float(out, *value),
            Self::Str(value) => write_string(out, value, style),
            Self::Arr(items) => {
                out.push('[');
                for (index, item) in items.iter().enumerate() {
                    if index > 0 {
                        out.push(',');
                    }
                    item.write(out, style);
                }
                out.push(']');
            }
            Self::Obj(entries) => {
                out.push('{');
                for (index, (key, value)) in entries.iter().enumerate() {
                    if index > 0 {
                        out.push(',');
                    }
                    write_string(out, key, style);
                    out.push(':');
                    value.write(out, style);
                }
                out.push('}');
            }
        }
    }
}

/// PHP's `serialize_precision = -1` gives the shortest round trip and keeps `.0` on whole values.
fn write_float(out: &mut String, value: f64) {
    if value.is_finite() {
        let _ = write!(out, "{value:?}");
    } else {
        out.push('0');
    }
}

fn write_string(out: &mut String, value: &str, style: Style) {
    out.push('"');
    for ch in value.chars() {
        match ch {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '/' if style == Style::Plain => out.push_str("\\/"),
            '\u{8}' => out.push_str("\\b"),
            '\u{c}' => out.push_str("\\f"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            c if (c as u32) < 0x20 => {
                let _ = write!(out, "\\u{:04x}", c as u32);
            }
            // Escaped even with JSON_UNESCAPED_UNICODE, unless JSON_UNESCAPED_LINE_TERMINATORS is set.
            '\u{2028}' => out.push_str("\\u2028"),
            '\u{2029}' => out.push_str("\\u2029"),
            c if !c.is_ascii() && style == Style::Plain => {
                let mut units = [0u16; 2];
                for unit in c.encode_utf16(&mut units) {
                    let _ = write!(out, "\\u{unit:04x}");
                }
            }
            c => out.push(c),
        }
    }
    out.push('"');
}
