//! The PHP behaviours the ports share: capture access over both regex engines, the pieces of
//! `basename`, `dirname` and `pathinfo` that are used, and `$` handling.
//!
//! PHP's `$` (no `D` modifier) also matches before one final newline, and doesn't consume it.
//! `nntmux_categorize::rx!` rewrites `$` to `(?:\n?\z)`, which does consume it, so group 0 of a
//! pattern that ends in `$` comes back with that newline and [`Caps::whole`] takes it off again.
//! None of these patterns can match a newline any other way. [`pcre!`] makes the same rewrite
//! for the `Pcre` engine as a lookahead, which consumes nothing.

use nntmux_core::pcre::{Match, Pcre};
use regex::bytes::{Captures, Regex};

/// PHP `trim()`'s default character list.
pub use nntmux_categorize::pcre::PHP_WS;

/// What `rx!` appends for a trailing `$`.
const DOLLAR: &str = r"(?:\n?\z)";

/// One regex match from either engine. Group values are the subject's bytes; PHP strings are
/// bytes, so a group that splits a multibyte character (`.` takes one byte without `/u`) comes
/// back with U+FFFD in place of the split bytes. That can only happen for subjects that are
/// valid UTF-8, and the golden corpus pins the one pattern where it can.
pub struct Caps<'a> {
    inner: Inner<'a>,
    trailing_dollar: bool,
}

enum Inner<'a> {
    Bytes(Captures<'a>),
    Pcre(Match),
}

impl Caps<'_> {
    /// Group `index` (0 is the whole match), `None` if it didn't take part.
    pub fn group(&self, index: usize) -> Option<&[u8]> {
        match &self.inner {
            Inner::Bytes(caps) => caps.get(index).map(|m| m.as_bytes()),
            Inner::Pcre(found) => found.group(index),
        }
    }

    /// Group `index` as text.
    pub fn text(&self, index: usize) -> Option<String> {
        self.group(index)
            .map(|bytes| String::from_utf8_lossy(bytes).into_owned())
    }

    /// Group `index`, empty if it didn't take part (PHP leaves a gap as `''`).
    pub fn text_or_empty(&self, index: usize) -> String {
        self.text(index).unwrap_or_default()
    }

    /// A named group.
    pub fn named(&self, name: &str) -> Option<String> {
        match &self.inner {
            Inner::Bytes(caps) => caps
                .name(name)
                .map(|m| String::from_utf8_lossy(m.as_bytes()).into_owned()),
            Inner::Pcre(found) => found
                .named()
                .into_iter()
                .find(|(group, _)| *group == name)
                .map(|(_, value)| String::from_utf8_lossy(value).into_owned()),
        }
    }

    /// `$matches[0]`: the whole match, without the newline a trailing `$` was rewritten to take.
    pub fn whole(&self) -> String {
        let bytes = self.group(0).unwrap_or_default();
        let bytes = if self.trailing_dollar {
            bytes.strip_suffix(b"\n").unwrap_or(bytes)
        } else {
            bytes
        };
        String::from_utf8_lossy(bytes).into_owned()
    }
}

/// `preg_match` with a byte regex (`rx!`).
pub fn caps<'a>(re: &Regex, subject: &'a str) -> Option<Caps<'a>> {
    re.captures(subject.as_bytes()).map(|found| Caps {
        inner: Inner::Bytes(found),
        trailing_dollar: re.as_str().ends_with(DOLLAR),
    })
}

/// `preg_match` with the `Pcre` engine.
pub fn pcre_caps(re: &Pcre, subject: &str) -> Option<Caps<'static>> {
    re.captures(subject.as_bytes()).map(|found| Caps {
        inner: Inner::Pcre(found),
        trailing_dollar: false,
    })
}

/// Rewrites every `$` outside a character class to `(?=\n?\z)`, PCRE's `$` without `D`.
pub fn pcre_dollar(delimited: &str) -> String {
    let mut out = String::with_capacity(delimited.len() + 16);
    let mut chars = delimited.chars().peekable();
    let mut in_class = false;
    // The delimiter is the first character, and the closing one precedes the modifiers.
    let last_delimiter = delimited.rfind('/').unwrap_or(delimited.len());
    let mut index = 0;
    while let Some(c) = chars.next() {
        let at = index;
        index += c.len_utf8();
        match c {
            '\\' => {
                out.push(c);
                if let Some(next) = chars.next() {
                    index += next.len_utf8();
                    out.push(next);
                }
            }
            '[' if !in_class => {
                in_class = true;
                out.push(c);
            }
            ']' if in_class => {
                in_class = false;
                out.push(c);
            }
            '$' if !in_class && at > 0 && at < last_delimiter => out.push_str(r"(?=\n?\z)"),
            _ => out.push(c),
        }
    }
    out
}

/// A lazily compiled `Pcre` from a delimited PHP pattern, with `$` rewritten by
/// [`pcre_dollar`]: `pcre!("/foo(?=$)/iu")`.
#[macro_export]
macro_rules! pcre {
    ($delimited:expr) => {{
        static RE: std::sync::LazyLock<nntmux_core::pcre::Pcre> = std::sync::LazyLock::new(|| {
            nntmux_core::pcre::Pcre::new(&$crate::compat::pcre_dollar($delimited))
                .expect("static pattern")
        });
        &*RE
    }};
}

/// A lazily compiled case-insensitive byte regex whose `$` is the PHP `D` modifier's: end of
/// subject only. The regex crate's own `$`, without multi-line mode, is exactly that.
#[macro_export]
macro_rules! rx_d {
    ($pattern:expr) => {{
        static RE: std::sync::LazyLock<regex::bytes::Regex> = std::sync::LazyLock::new(|| {
            regex::bytes::RegexBuilder::new($pattern)
                .unicode(false)
                .case_insensitive(true)
                .build()
                .expect("static pattern")
        });
        &*RE
    }};
}

/// A lazily compiled case-insensitive byte regex in multi-line mode, for `/.../im`: `^` and
/// `$` match at every line, and `$` only before `\n`, as in PCRE.
#[macro_export]
macro_rules! rx_m {
    ($pattern:expr) => {{
        static RE: std::sync::LazyLock<regex::bytes::Regex> = std::sync::LazyLock::new(|| {
            regex::bytes::RegexBuilder::new($pattern)
                .unicode(false)
                .case_insensitive(true)
                .multi_line(true)
                .build()
                .expect("static pattern")
        });
        &*RE
    }};
}

/// `basename()` for `/` separators, the only kind PHP knows on Linux: trailing slashes go,
/// then everything up to the last one.
pub fn php_basename(path: &str) -> &str {
    path.trim_end_matches('/').rsplit('/').next().unwrap_or("")
}

/// `dirname()`: `zend_dirname`'s rules for `/`-only paths.
pub fn php_dirname(path: &str) -> &str {
    if path.is_empty() {
        return "";
    }
    let bytes = path.as_bytes();
    let mut end = bytes.len();
    // Trailing slashes.
    while end > 0 && bytes[end - 1] == b'/' {
        end -= 1;
    }
    if end == 0 {
        return "/";
    }
    // The last component.
    while end > 0 && bytes[end - 1] != b'/' {
        end -= 1;
    }
    if end == 0 {
        return ".";
    }
    // The slashes before it.
    while end > 0 && bytes[end - 1] == b'/' {
        end -= 1;
    }
    if end == 0 {
        return "/";
    }
    &path[..end]
}

/// `pathinfo($path, PATHINFO_EXTENSION)`: after the last dot of the basename, `""` if none.
pub fn php_extension(path: &str) -> &str {
    let base = php_basename(path);
    base.rfind('.').map_or("", |dot| &base[dot + 1..])
}

/// `pathinfo($path, PATHINFO_FILENAME)`: the basename up to its last dot.
pub fn php_filename(path: &str) -> &str {
    let base = php_basename(path);
    base.rfind('.').map_or(base, |dot| &base[..dot])
}

/// `FileNameCleaner::extractFilenameFromPath`: `/[\\\/]([^\\\/]+)$/` takes what follows the last
/// separator, and only when that isn't empty. A newline is an ordinary character to it.
pub fn filename_from_path(path: &str) -> &str {
    match path.rfind(['\\', '/']) {
        Some(index) if index + 1 < path.len() => &path[index + 1..],
        _ => path,
    }
}

/// `str_replace` of one literal: every occurrence, case-sensitively.
pub fn str_replace(subject: &str, from: &str, to: &str) -> String {
    subject.replace(from, to)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn path_functions_follow_php() {
        assert_eq!(php_basename("a/b/c.mkv"), "c.mkv");
        assert_eq!(php_basename("a/b/"), "b");
        assert_eq!(php_basename("///"), "");
        assert_eq!(php_dirname("a/b/c.mkv"), "a/b");
        assert_eq!(php_dirname("a/b/"), "a");
        assert_eq!(php_dirname("a"), ".");
        assert_eq!(php_dirname("/a"), "/");
        assert_eq!(php_dirname("a//b"), "a");
        assert_eq!(php_dirname("/"), "/");
        assert_eq!(php_dirname(""), "");
        assert_eq!(php_extension("x/y.tar.gz"), "gz");
        assert_eq!(php_extension("x/y"), "");
        assert_eq!(php_filename("x/y.tar.gz"), "y.tar");
        assert_eq!(php_filename(".hidden"), "");
        assert_eq!(filename_from_path("a\\b/c\n"), "c\n");
        assert_eq!(filename_from_path("a/b/"), "a/b/");
    }

    #[test]
    fn dollar_is_rewritten_outside_classes_only() {
        assert_eq!(pcre_dollar(r"/a$/i"), r"/a(?=\n?\z)/i");
        assert_eq!(pcre_dollar(r"/[$]\$(?=$|x)/"), r"/[$]\$(?=(?=\n?\z)|x)/");
    }
}
