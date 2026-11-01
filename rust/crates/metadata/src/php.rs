//! Small PHP behaviours the providers rely on.

use std::sync::LazyLock;

use nntmux_core::pcre::Pcre;
use serde_json::Value;

static TAG: LazyLock<Pcre> = LazyLock::new(|| Pcre::new("/<[^>]*>/").expect("a valid regex"));

/// `mb_substr($text, 0, $length)`.
pub fn mb_substr(text: &str, length: usize) -> String {
    text.chars().take(length).collect()
}

/// PHP's default `trim`.
pub fn trim(text: &str) -> &str {
    text.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B'])
}

/// `strip_tags`, for the HTML summaries TVMaze sends.
pub fn strip_tags(text: &str) -> String {
    bytes_to_string(TAG.replace_all(text.as_bytes(), b""))
}

pub fn bytes_to_string(bytes: Vec<u8>) -> String {
    String::from_utf8(bytes)
        .unwrap_or_else(|failure| String::from_utf8_lossy(failure.as_bytes()).into_owned())
}

/// `(string) $value`: scalars become text, anything else is empty.
pub fn text(value: Option<&Value>) -> String {
    match value {
        Some(Value::String(text)) => text.clone(),
        Some(Value::Number(number)) => number.to_string(),
        Some(Value::Bool(true)) => "1".to_owned(),
        _ => String::new(),
    }
}

/// `(int) $value`, where only numbers and numeric text count.
pub fn int(value: Option<&Value>) -> i64 {
    match value {
        Some(Value::Number(number)) => number
            .as_i64()
            .or_else(|| number.as_f64().map(|float| float as i64))
            .unwrap_or(0),
        Some(Value::String(text)) => {
            let text = trim(text);
            text.parse::<i64>()
                .ok()
                .or_else(|| text.parse::<f64>().ok().map(|float| float as i64))
                .unwrap_or(0)
        }
        Some(Value::Bool(true)) => 1,
        _ => 0,
    }
}

/// The items of a JSON array, or nothing.
pub fn items(value: Option<&Value>) -> &[Value] {
    value.and_then(Value::as_array).map_or(&[], Vec::as_slice)
}

/// `$value !== null && $value !== ''`.
pub fn present(value: Option<&Value>) -> bool {
    !matches!(value, None | Some(Value::Null)) && value.and_then(Value::as_str) != Some("")
}

/// `str_ireplace` for ASCII needles.
pub fn ireplace_ascii(text: &str, needle: &str, replacement: &str) -> String {
    let lower = text.to_ascii_lowercase();
    let mut output = String::with_capacity(text.len());
    let mut last = 0;
    for (start, _) in lower.match_indices(needle) {
        output.push_str(&text[last..start]);
        output.push_str(replacement);
        last = start + needle.len();
    }
    output.push_str(&text[last..]);
    output
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn casts_follow_php() {
        assert_eq!(text(Some(&json!(12))), "12");
        assert_eq!(text(Some(&json!(null))), "");
        assert_eq!(text(Some(&json!(["a"]))), "");
        assert_eq!(int(Some(&json!("42"))), 42);
        assert_eq!(int(Some(&json!("x"))), 0);
        assert_eq!(int(Some(&json!(3.9))), 3);
        assert!(present(Some(&json!(0))));
        assert!(!present(Some(&json!(""))));
        assert!(!present(None));
    }

    #[test]
    fn strips_html_and_replaces_case_insensitively() {
        assert_eq!(strip_tags("<p>One <b>two</b></p>"), "One two");
        assert_eq!(
            ireplace_ascii("Tom AND Jerry and", " and ", " & "),
            "Tom & Jerry and"
        );
        assert_eq!(ireplace_ascii("Theater", "er", "re"), "Theatre");
        assert_eq!(mb_substr("héllo", 2), "hé");
    }
}
