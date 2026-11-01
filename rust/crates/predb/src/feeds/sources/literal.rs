//! Checks the catalogue sources share. They exist because of real data: a listing may show a
//! heading, a page title or a markup artefact where a release name should be, and only an exact,
//! unsplit identifier is safe to import.

use std::borrow::Cow;
use std::sync::LazyLock;

use anyhow::anyhow;
use regex::Regex;

use super::super::{FeedError, FeedResult};

pub(super) fn fail<T>(message: impl std::fmt::Display) -> FeedResult<T> {
    Err(FeedError::Failed(anyhow!("{message}")))
}

/// PHP's `trim()` default set. Unicode spaces are deliberately not stripped, so a stray NBSP
/// stays in the value and the literal check rejects it.
pub(super) fn php_trim(text: &str) -> &str {
    text.trim_matches(|c| matches!(c, ' ' | '\t' | '\n' | '\r' | '\0' | '\x0b'))
}

/// `mb_strlen($value, 'UTF-8')`.
pub(super) fn char_length(text: &str) -> usize {
    text.chars().count()
}

/// `preg_match('/[\s\p{Z}\p{C}]/u', ...)`: whitespace, separators and control or format
/// characters mean the text is a heading, not a release identifier.
pub(super) fn is_nonliteral(text: &str) -> bool {
    static PATTERN: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"[\s\p{Z}\p{C}]").expect("pattern"));
    PATTERN.is_match(text)
}

/// `preg_match('/\p{C}/u', ...)`.
pub(super) fn has_other_character(text: &str) -> bool {
    static PATTERN: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"\p{C}").expect("pattern"));
    PATTERN.is_match(text)
}

/// `preg_match('/\p{Cc}/u', ...)`.
pub(super) fn has_control_character(text: &str) -> bool {
    static PATTERN: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"\p{Cc}").expect("pattern"));
    PATTERN.is_match(text)
}

pub(super) fn contains_ellipsis(text: &str) -> bool {
    text.contains("...") || text.contains('\u{2026}')
}

/// `preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $html)`.
pub(super) fn has_control_bytes(bytes: &[u8]) -> bool {
    bytes
        .iter()
        .any(|byte| matches!(byte, 0..=8 | 0x0b | 0x0c | 0x0e..=0x1f))
}

pub(super) fn has_noncharacter_pair(text: &str) -> bool {
    text.contains(['\u{fffe}', '\u{ffff}'])
}

/// A numeric character reference the way PHP's validator finds them: decimal or hex digits with
/// an optional semicolon.
fn numeric_references() -> &'static Regex {
    static PATTERN: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"&#(?:([0-9]+)|[xX]([0-9a-fA-F]+));?").expect("pattern"));
    &PATTERN
}

/// `None` when the digits are too long to be any code point. The PHP validators cap the digits
/// (after leading zeros) at 7 decimal or 6 hex.
fn reference_value(captures: &regex::Captures<'_>) -> Option<u32> {
    let (digits, radix, limit) = match captures.get(1) {
        Some(decimal) => (decimal.as_str(), 10, 7),
        None => (captures.get(2).map_or("", |hex| hex.as_str()), 16, 6),
    };
    let digits = digits.trim_start_matches('0');
    if digits.len() > limit {
        return None;
    }
    Some(if digits.is_empty() {
        0
    } else {
        u32::from_str_radix(digits, radix).ok()?
    })
}

/// Whether libxml could discard a numeric reference, which would silently change a literal name.
/// `noncharacters` also rejects U+FFFE and U+FFFF, as the heading sources do.
pub(super) fn has_unsafe_numeric_reference(html: &str, noncharacters: bool) -> bool {
    numeric_references().captures_iter(html).any(|captures| {
        let Some(code) = reference_value(&captures) else {
            return true;
        };
        (code < 0x20 && !matches!(code, 9 | 10 | 13))
            || code > 0x10_ffff
            || (noncharacters && matches!(code, 0xfffe | 0xffff))
            || (0xd800..=0xdfff).contains(&code)
    })
}

/// What libxml does with the numeric references HTML5 reads differently.
#[derive(Clone, Copy, PartialEq, Eq)]
pub(super) enum Discard {
    /// Only U+FFFE and U+FFFF, which libxml drops.
    Noncharacters,
    /// Every reference to something that isn't an XML character: libxml drops those too.
    NonXmlCharacters,
    None,
}

/// The text libxml would have built, for the references HTML5 reads differently. A numeric
/// reference to C1 (U+0080 to U+009F) is a control character to libxml but a Windows-1252 letter
/// to HTML5, and libxml silently drops references to anything that isn't an XML character
/// (controls, surrogates, U+FFFE and U+FFFF, values beyond Unicode) where HTML5 substitutes
/// U+FFFD or keeps the control. Without this, `&#x85;` in a name would read as an ellipsis and
/// be accepted.
pub(super) fn as_libxml_reads(html: &str, discard: Discard) -> Cow<'_, str> {
    numeric_references().replace_all(html, |captures: &regex::Captures<'_>| {
        let code = reference_value(captures);
        let dropped = match (discard, code) {
            (Discard::None, _) => false,
            (Discard::Noncharacters, code) => matches!(code, Some(0xfffe | 0xffff)),
            (Discard::NonXmlCharacters, None) => true,
            (Discard::NonXmlCharacters, Some(code)) => {
                matches!(code, 0..=8 | 0xb | 0xc | 0xe..=0x1f | 0xd800..=0xdfff | 0xfffe | 0xffff)
                    || code > 0x10_ffff
            }
        };
        match code {
            _ if dropped => String::new(),
            Some(code @ 0x80..=0x9f) => char::from_u32(code).map(String::from).unwrap_or_default(),
            _ => captures[0].to_owned(),
        }
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn php_trim_keeps_unicode_spaces() {
        assert_eq!(php_trim(" \t\0x\u{a0}\x0b"), "x\u{a0}");
    }

    #[test]
    fn references_follow_the_php_validator() {
        assert!(has_unsafe_numeric_reference("&#0;", false));
        assert!(has_unsafe_numeric_reference("&#x0", false));
        assert!(has_unsafe_numeric_reference("&#xD800;", false));
        assert!(has_unsafe_numeric_reference("&#x110000;", false));
        assert!(has_unsafe_numeric_reference("&#00000001;", false));
        assert!(has_unsafe_numeric_reference("&#99999999;", false));
        assert!(!has_unsafe_numeric_reference("&#xFFFE;", false));
        assert!(has_unsafe_numeric_reference("&#xFFFE;", true));
        assert!(!has_unsafe_numeric_reference(
            "&#x0009;&#10;&#13;&#233;",
            true
        ));
    }

    #[test]
    fn c1_references_become_controls() {
        assert_eq!(
            as_libxml_reads("a&#x85;b&#150;c&#xA0;", Discard::None),
            "a\u{85}b\u{96}c&#xA0;"
        );
        assert_eq!(as_libxml_reads("a&#xFFFE;b", Discard::Noncharacters), "ab");
        assert_eq!(as_libxml_reads("a&#xFFFE;b", Discard::None), "a&#xFFFE;b");
        assert_eq!(
            as_libxml_reads(
                "a&#1;&#0;&#xD800;&#x110000;&#12;&#9;&#xFDD0;b",
                Discard::NonXmlCharacters
            ),
            "a&#9;&#xFDD0;b"
        );
    }
}
