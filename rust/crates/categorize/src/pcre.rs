//! The PHP rules ran on PCRE without `/u`: bytes, ASCII-only `\b`, `\d`, `\w` and `/i`, and a
//! `$` that also matches before a final newline. These helpers keep those semantics, because
//! release names aren't always ASCII and the rules were tuned against that behaviour.

use regex::bytes::{Regex, RegexBuilder};

/// Compiles a PCRE pattern with byte semantics. Lookaround isn't supported, so a rule that
/// needs it is written by hand.
pub fn compile(pattern: &str, case_insensitive: bool) -> Regex {
    RegexBuilder::new(&translate_dollar(pattern))
        .unicode(false)
        .case_insensitive(case_insensitive)
        .build()
        .unwrap_or_else(|error| panic!("bad pattern {pattern}: {error}"))
}

fn translate_dollar(pattern: &str) -> String {
    let mut out = String::with_capacity(pattern.len());
    let mut chars = pattern.chars();
    let mut in_class = false;
    while let Some(c) = chars.next() {
        match c {
            '\\' => {
                out.push(c);
                out.extend(chars.next());
            }
            '[' if !in_class => {
                in_class = true;
                out.push(c);
            }
            ']' if in_class => {
                in_class = false;
                out.push(c);
            }
            '$' if !in_class => out.push_str(r"(?:\n?\z)"),
            _ => out.push(c),
        }
    }
    out
}

/// Compiles a PCRE pattern that needs lookaround. `fancy_regex` works on `str` with Unicode on,
/// so `\\d`, `\\w` and `$` are spelled out to keep their ASCII and end-of-string meaning.
pub fn compile_fancy(pattern: &str, case_insensitive: bool) -> fancy_regex::Regex {
    let mut out = String::with_capacity(pattern.len() + 16);
    if case_insensitive {
        out.push_str("(?i)");
    }
    let mut chars = pattern.chars();
    let mut in_class = false;
    while let Some(c) = chars.next() {
        match c {
            '\\' => match chars.next() {
                Some('d') if in_class => out.push_str("0-9"),
                Some('d') => out.push_str("[0-9]"),
                Some('w') if in_class => out.push_str("0-9A-Za-z_"),
                Some('w') => out.push_str("[0-9A-Za-z_]"),
                Some(next) => {
                    out.push('\\');
                    out.push(next);
                }
                None => out.push('\\'),
            },
            '[' if !in_class => {
                in_class = true;
                out.push(c);
            }
            ']' if in_class => {
                in_class = false;
                out.push(c);
            }
            '$' if !in_class => out.push_str(r"(?=\n?\z)"),
            _ => out.push(c),
        }
    }
    fancy_regex::Regex::new(&out).unwrap_or_else(|error| panic!("bad pattern {pattern}: {error}"))
}

/// A lazily compiled pattern with lookaround: `frx!("...")` or `frx!(i "...")`.
#[macro_export]
macro_rules! frx {
    (i $pattern:expr) => {{
        static RE: std::sync::LazyLock<fancy_regex::Regex> =
            std::sync::LazyLock::new(|| $crate::pcre::compile_fancy($pattern, true));
        &*RE
    }};
    ($pattern:expr) => {{
        static RE: std::sync::LazyLock<fancy_regex::Regex> =
            std::sync::LazyLock::new(|| $crate::pcre::compile_fancy($pattern, false));
        &*RE
    }};
}

/// A lazily compiled pattern: `rx!("...")` is case-sensitive, `rx!(i "...")` isn't.
#[macro_export]
macro_rules! rx {
    (i $pattern:expr) => {{
        static RE: std::sync::LazyLock<regex::bytes::Regex> =
            std::sync::LazyLock::new(|| $crate::pcre::compile($pattern, true));
        &*RE
    }};
    ($pattern:expr) => {{
        static RE: std::sync::LazyLock<regex::bytes::Regex> =
            std::sync::LazyLock::new(|| $crate::pcre::compile($pattern, false));
        &*RE
    }};
}

/// `mi!(name, "...")` is `preg_match('/.../i', $name) === 1`.
#[macro_export]
macro_rules! mi {
    ($name:expr, $pattern:expr) => {
        $crate::rx!(i $pattern).is_match($name.as_bytes())
    };
}

/// `ms!(name, "...")` is `preg_match('/.../', $name) === 1`.
#[macro_export]
macro_rules! ms {
    ($name:expr, $pattern:expr) => {
        $crate::rx!($pattern).is_match($name.as_bytes())
    };
}

/// `preg_replace` on a string. Every pattern used here only removes or inserts ASCII around
/// whole matches, so the result stays valid UTF-8; anything else is replaced, not panicked on.
pub fn replace_all(re: &Regex, value: &str, with: &str) -> String {
    String::from_utf8_lossy(&re.replace_all(value.as_bytes(), with.as_bytes())).into_owned()
}

/// PHP `trim()` with an explicit character list. Default list: [`PHP_WS`].
pub fn trim_chars<'a>(value: &'a str, list: &str) -> &'a str {
    value.trim_matches(|c| list.contains(c))
}

pub const PHP_WS: &str = " \t\n\r\0\x0B";

/// `stripos($haystack, $needle) !== false` for an ASCII needle.
pub fn contains_ci(haystack: &str, needle: &str) -> bool {
    haystack
        .as_bytes()
        .windows(needle.len())
        .any(|window| window.eq_ignore_ascii_case(needle.as_bytes()))
}
