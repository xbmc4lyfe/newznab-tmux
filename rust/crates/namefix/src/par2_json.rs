//! `json_encode` with PHP's default flags, for the values PAR2 progress hashes and stores.
//!
//! Signatures and stored byte counts are computed over PHP's output, which escapes `/` and
//! writes every non-ASCII character as `\uXXXX`. Reproducing it exactly keeps progress rows
//! written by either implementation valid for the other, and member order is part of the
//! output, so values are built in order rather than through a sorted map.

use sha2::{Digest, Sha256};

/// A JSON value with ordered members.
#[derive(Clone, Debug, PartialEq)]
pub enum Json {
    Null,
    Bool(bool),
    Int(i64),
    Str(String),
    Arr(Vec<Json>),
    Obj(Vec<(&'static str, Json)>),
}

impl Json {
    pub fn str(value: impl Into<String>) -> Self {
        Self::Str(value.into())
    }

    pub fn encode(&self) -> String {
        let mut out = String::new();
        self.write(&mut out);
        out
    }

    /// `hash('sha256', json_encode(...))`.
    pub fn sha256(&self) -> String {
        hex::encode(Sha256::digest(self.encode().as_bytes()))
    }

    fn write(&self, out: &mut String) {
        match self {
            Self::Null => out.push_str("null"),
            Self::Bool(true) => out.push_str("true"),
            Self::Bool(false) => out.push_str("false"),
            Self::Int(value) => out.push_str(&value.to_string()),
            Self::Str(value) => write_string(value, out),
            Self::Arr(items) => {
                out.push('[');
                for (index, item) in items.iter().enumerate() {
                    if index > 0 {
                        out.push(',');
                    }
                    item.write(out);
                }
                out.push(']');
            }
            Self::Obj(members) => {
                out.push('{');
                for (index, (key, value)) in members.iter().enumerate() {
                    if index > 0 {
                        out.push(',');
                    }
                    write_string(key, out);
                    out.push(':');
                    value.write(out);
                }
                out.push('}');
            }
        }
    }
}

/// A string literal as PHP writes it.
pub fn write_string(value: &str, out: &mut String) {
    out.push('"');
    for c in value.chars() {
        match c {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '/' => out.push_str("\\/"),
            '\u{8}' => out.push_str("\\b"),
            '\u{c}' => out.push_str("\\f"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            c if (c as u32) < 0x20 => out.push_str(&format!("\\u{:04x}", c as u32)),
            c if c.is_ascii() => out.push(c),
            c => {
                let mut units = [0u16; 2];
                for unit in c.encode_utf16(&mut units) {
                    out.push_str(&format!("\\u{unit:04x}"));
                }
            }
        }
    }
    out.push('"');
}

/// The encoded length of a base64 string, which can only contain `/` as a character PHP
/// escapes.
pub fn base64_json_len(encoded: &str) -> usize {
    encoded.len() + encoded.bytes().filter(|b| *b == b'/').count() + 2
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn escapes_like_php() {
        let value = Json::Obj(vec![
            ("a/b", Json::str("x/y\"\\\n\u{1}é😀")),
            ("n", Json::Null),
            ("l", Json::Arr(vec![Json::Int(-3), Json::Bool(true)])),
        ]);
        assert_eq!(
            value.encode(),
            r#"{"a\/b":"x\/y\"\\\n\u0001\u00e9\ud83d\ude00","n":null,"l":[-3,true]}"#
        );
    }
}
