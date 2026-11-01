//! `PredbIdentityKey`: the case- and separator-insensitive form two release names are compared
//! in, so `Some Show S01E01` and `some.show.s01e01` are the same key.

use nntmux_categorize::pcre::{PHP_WS, trim_chars};
use nntmux_core::pcre::Pcre;
use unicode_normalization::UnicodeNormalization;

fn separators() -> &'static Pcre {
    static RE: std::sync::LazyLock<Pcre> =
        std::sync::LazyLock::new(|| Pcre::new(r"/[._\s]+/u").expect("static pattern"));
    &RE
}

/// NFC, trimmed, lowercased, with every run of dots, underscores and whitespace one dot. `None`
/// for an empty value or one over 512 bytes. (Rust strings are always valid UTF-8, so PHP's
/// `mb_check_encoding` refusal has no counterpart.)
pub fn normalize(value: &str) -> Option<String> {
    if value.is_empty() || value.len() > 512 {
        return None;
    }
    let composed: String = value.nfc().collect();
    let lowered = trim_chars(&composed, PHP_WS).to_lowercase();
    let bytes = separators().replace_all(lowered.as_bytes(), b".");
    Some(String::from_utf8_lossy(&bytes).into_owned())
}

#[cfg(test)]
mod tests {
    use super::normalize;

    #[test]
    fn collapses_separators_and_case() {
        assert_eq!(
            normalize(" Some__Show..S01E01 ").as_deref(),
            Some("some.show.s01e01")
        );
        assert_eq!(normalize(""), None);
        assert_eq!(normalize(&"a".repeat(513)), None);
        // A value of only whitespace trims to the empty key, which isn't `None`.
        assert_eq!(normalize("  ").as_deref(), Some(""));
    }
}
