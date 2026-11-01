//! PHP `preg_*` semantics over `fancy-regex`, for the regexes stored in the database and the
//! ones ported from PHP.
//!
//! Without `/u`, PCRE matches bytes: `\d`, `\w`, `\s` and `\b` are ASCII only and `.` takes one
//! byte, whatever the subject's encoding. `fancy-regex` only takes `&str`, so the subject and the
//! pattern are mapped byte for byte to U+0000..U+00FF and the Unicode-aware escapes are rewritten
//! to ASCII. PCRE syntax the `regex` crate rejects is rewritten too: `[` inside a class, and a `{`
//! that isn't a quantifier.
//!
//! With `/u` the subject must be valid UTF-8, or the regex doesn't match, as `preg_match` returns
//! false. A failed match, such as hitting the backtrack limit, counts as no match as well.
//!
//! One known difference: `$` doesn't match before a trailing newline, so match single lines.

use anyhow::{Context, Result, anyhow, bail};
use fancy_regex::{Regex, RegexBuilder};

/// PHP's `pcre.backtrack_limit` default.
const BACKTRACK_LIMIT: usize = 1_000_000;

/// A compiled PHP regex.
#[derive(Clone, Debug)]
pub struct Pcre {
    regex: Regex,
    utf: bool,
}

/// One match. Group values are the subject's bytes.
#[derive(Debug)]
pub struct Match {
    groups: Vec<Option<Vec<u8>>>,
    names: Vec<(String, usize)>,
}

impl Match {
    /// Group `index`, where 0 is the whole match. None if it didn't take part.
    pub fn group(&self, index: usize) -> Option<&[u8]> {
        self.groups.get(index)?.as_deref()
    }

    /// Named groups that took part in the match, sorted by name as `ksort` leaves them.
    pub fn named(&self) -> Vec<(&str, &[u8])> {
        let mut named: Vec<(&str, &[u8])> = self
            .names
            .iter()
            .filter_map(|(name, index)| Some((name.as_str(), self.group(*index)?)))
            .collect();
        named.sort_by(|a, b| a.0.cmp(b.0));
        named
    }
}

impl Pcre {
    /// Compiles a delimited PHP pattern, like `/foo(?P<bar>\d+)/i`.
    pub fn new(delimited: &str) -> Result<Self> {
        let (pattern, flags) =
            split_delimiters(delimited).with_context(|| format!("regex {delimited}"))?;
        Self::with_flags(pattern, flags).with_context(|| format!("regex {delimited}"))
    }

    /// Compiles an undelimited pattern with PHP modifier letters.
    pub fn with_flags(pattern: &str, flags: &str) -> Result<Self> {
        let utf = flags.contains('u');
        let mut inline = String::new();
        for flag in flags.chars() {
            match flag {
                'i' | 'm' | 's' | 'x' => inline.push(flag),
                'U' => inline.push('U'),
                // PHP ignores whitespace among the modifiers. `D` changes nothing here, because
                // `$` already refuses a trailing newline, and `A` is handled below.
                'u' | 'D' | 'S' | 'X' | 'J' | 'A' | ' ' | '\n' | '\r' => {}
                other => bail!("unknown modifier {other}"),
            }
        }
        let source = if utf {
            pattern.to_owned()
        } else {
            latin1(pattern.as_bytes())
        };
        let mut translated = translate(&source, utf);
        if flags.contains('A') {
            translated = format!("\\A(?:{translated})");
        }
        if !inline.is_empty() {
            translated = format!("(?{inline}){translated}");
        }
        let regex = RegexBuilder::new(&translated)
            .backtrack_limit(BACKTRACK_LIMIT)
            .build()
            .map_err(|failure| anyhow!("{failure}"))?;
        Ok(Self { regex, utf })
    }

    pub fn is_match(&self, subject: &[u8]) -> bool {
        match self.haystack(subject) {
            Some(text) => self.regex.is_match(&text).unwrap_or(false),
            None => false,
        }
    }

    pub fn captures(&self, subject: &[u8]) -> Option<Match> {
        let text = self.haystack(subject)?;
        let captures = self.regex.captures(&text).ok()??;
        let groups = (0..captures.len())
            .map(|index| captures.get(index).map(|m| self.bytes(m.as_str())))
            .collect();
        let names = self
            .regex
            .capture_names()
            .enumerate()
            .filter_map(|(index, name)| Some((name?.to_owned(), index)))
            .collect();
        Some(Match { groups, names })
    }

    /// `preg_replace` with a literal replacement. A failed match leaves the rest unchanged.
    pub fn replace_all(&self, subject: &[u8], replacement: &[u8]) -> Vec<u8> {
        let Some(text) = self.haystack(subject) else {
            return subject.to_vec();
        };
        let mut output = Vec::with_capacity(subject.len());
        let mut last = 0;
        for found in self.regex.find_iter(&text) {
            let Ok(found) = found else { break };
            output.extend(self.bytes(&text[last..found.start()]));
            output.extend_from_slice(replacement);
            last = found.end();
        }
        output.extend(self.bytes(&text[last..]));
        output
    }

    fn haystack(&self, subject: &[u8]) -> Option<String> {
        if self.utf {
            std::str::from_utf8(subject).ok().map(str::to_owned)
        } else {
            Some(latin1(subject))
        }
    }

    fn bytes(&self, text: &str) -> Vec<u8> {
        if self.utf {
            text.as_bytes().to_vec()
        } else {
            unlatin1(text)
        }
    }
}

/// Maps each byte to the code point of the same value.
pub fn latin1(bytes: &[u8]) -> String {
    bytes.iter().map(|&byte| char::from(byte)).collect()
}

/// The inverse of [`latin1`]. Code points above U+00FF can't come from it, and are dropped.
pub fn unlatin1(text: &str) -> Vec<u8> {
    text.chars()
        .filter_map(|c| u8::try_from(u32::from(c)).ok())
        .collect()
}

fn split_delimiters(delimited: &str) -> Result<(&str, &str)> {
    let trimmed = delimited.trim_start();
    let Some(open) = trimmed.chars().next() else {
        bail!("empty regex");
    };
    if open.is_ascii_alphanumeric() || open == '\\' {
        bail!("delimiter must not be alphanumeric or backslash");
    }
    let close = match open {
        '(' => ')',
        '[' => ']',
        '{' => '}',
        '<' => '>',
        other => other,
    };
    let body = &trimmed[open.len_utf8()..];
    let end = closing_delimiter(body, open, close)
        .ok_or_else(|| anyhow!("no ending delimiter {close}"))?;
    Ok((&body[..end], &body[end + close.len_utf8()..]))
}

/// PHP takes the first unescaped delimiter as the end, so `/a/b/` has modifiers `b/` and fails.
/// Bracket delimiters nest.
fn closing_delimiter(body: &str, open: char, close: char) -> Option<usize> {
    let mut depth = 0;
    let mut chars = body.char_indices();
    while let Some((at, c)) = chars.next() {
        if c == '\\' {
            chars.next();
        } else if c == close {
            if depth == 0 {
                return Some(at);
            }
            depth -= 1;
        } else if c == open {
            depth += 1;
        }
    }
    None
}

const SPACE: &str = r" \t\n\x0B\f\r";
const DIGIT: &str = "0-9";
const WORD: &str = "0-9A-Za-z_";
const HORIZONTAL: &str = r" \t\xA0";
const VERTICAL: &str = r"\n\x0B\f\r\x85";

fn translate(pattern: &str, utf: bool) -> String {
    let chars: Vec<char> = pattern.chars().collect();
    let mut out = String::with_capacity(pattern.len() + 16);
    let mut i = 0;
    while i < chars.len() {
        match chars[i] {
            '\\' => i = outside_escape(&chars, i, utf, &mut out),
            '[' => i = class(&chars, i, utf, &mut out),
            '{' => {
                if let Some(end) = quantifier_end(&chars, i) {
                    out.extend(&chars[i..=end]);
                    i = end + 1;
                } else {
                    out.push_str(r"\{");
                    i += 1;
                }
            }
            '}' => {
                out.push_str(r"\}");
                i += 1;
            }
            '(' if chars.get(i + 1) == Some(&'?') && chars.get(i + 2) == Some(&'\'') => {
                // (?'name'...) is (?<name>...)
                out.push_str("(?<");
                i += 3;
                while i < chars.len() && chars[i] != '\'' {
                    out.push(chars[i]);
                    i += 1;
                }
                out.push('>');
                i += 1;
            }
            other => {
                out.push(other);
                i += 1;
            }
        }
    }
    out
}

fn quantifier_end(chars: &[char], open: usize) -> Option<usize> {
    let mut i = open + 1;
    let digits = |i: &mut usize| {
        let start = *i;
        while chars.get(*i).is_some_and(char::is_ascii_digit) {
            *i += 1;
        }
        *i > start
    };
    if !digits(&mut i) {
        return None;
    }
    if chars.get(i) == Some(&',') {
        i += 1;
        digits(&mut i);
    }
    (chars.get(i) == Some(&'}')).then_some(i)
}

/// Writes the escape at `chars[at]` and returns the index after it.
fn outside_escape(chars: &[char], at: usize, utf: bool, out: &mut String) -> usize {
    let Some(&next) = chars.get(at + 1) else {
        out.push_str(r"\\");
        return at + 1;
    };
    let ascii = |class: &str, negated: bool, out: &mut String| {
        out.push('[');
        if negated {
            out.push('^');
        }
        out.push_str(class);
        out.push(']');
    };
    match next {
        'd' if !utf => ascii(DIGIT, false, out),
        'D' if !utf => ascii(DIGIT, true, out),
        'w' if !utf => ascii(WORD, false, out),
        'W' if !utf => ascii(WORD, true, out),
        's' if !utf => ascii(SPACE, false, out),
        'S' if !utf => ascii(SPACE, true, out),
        // fancy-regex refuses `(?-u:\b)`, and a Unicode `\b` sees Latin-1 letters as word bytes.
        'b' if !utf => out.push_str(&format!(
            "(?:(?<=[{WORD}])(?![{WORD}])|(?<![{WORD}])(?=[{WORD}]))"
        )),
        'B' if !utf => out.push_str(&format!(
            "(?:(?<=[{WORD}])(?=[{WORD}])|(?<![{WORD}])(?![{WORD}]))"
        )),
        'h' => ascii(HORIZONTAL, false, out),
        'H' => ascii(HORIZONTAL, true, out),
        'v' => ascii(VERTICAL, false, out),
        'V' => ascii(VERTICAL, true, out),
        'N' => out.push_str(r"[^\n]"),
        'R' => out.push_str(r"(?:\r\n|\n|\x0B|\f|\r|\x85)"),
        'Z' => out.push_str(r"\z"),
        'e' => out.push_str(r"\x1B"),
        // PCRE ignores a `\E` with no `\Q`.
        'E' => {}
        'Q' => {
            let mut i = at + 2;
            while i < chars.len() {
                if chars[i] == '\\' && chars.get(i + 1) == Some(&'E') {
                    return i + 2;
                }
                push_literal(chars[i], out);
                i += 1;
            }
            return i;
        }
        // `\<` is a literal in PCRE and a word boundary in the regex crate.
        '<' | '>' => out.push(next),
        // PCRE's NUL and octal escapes, which the regex crate refuses.
        '0' => {
            let digits = chars[at + 2..]
                .iter()
                .take(2)
                .take_while(|c| ('0'..='7').contains(*c))
                .count();
            let octal: String = chars[at + 2..at + 2 + digits].iter().collect();
            let value = u32::from_str_radix(&octal, 8).unwrap_or(0);
            out.push_str(&format!(r"\x{{{value:X}}}"));
            return at + 2 + digits;
        }
        // These take a braced argument, which isn't a stray brace.
        'p' | 'P' | 'x' | 'o' | 'g' | 'k' if chars.get(at + 2) == Some(&'{') => {
            let Some(end) = chars[at + 2..].iter().position(|&c| c == '}') else {
                out.extend(&chars[at..at + 2]);
                return at + 2;
            };
            out.extend(&chars[at..=at + 2 + end]);
            return at + 3 + end;
        }
        other => {
            out.push('\\');
            out.push(other);
        }
    }
    at + 2
}

fn push_literal(c: char, out: &mut String) {
    if c.is_ascii_punctuation() {
        out.push('\\');
    }
    out.push(c);
}

/// Writes the character class at `chars[open]` and returns the index after it.
fn class(chars: &[char], open: usize, utf: bool, out: &mut String) -> usize {
    out.push('[');
    let mut i = open + 1;
    if chars.get(i) == Some(&'^') {
        out.push('^');
        i += 1;
    }
    // A `]` first in a class is literal.
    if chars.get(i) == Some(&']') {
        out.push_str(r"\]");
        i += 1;
    }
    while i < chars.len() {
        match chars[i] {
            ']' => {
                out.push(']');
                return i + 1;
            }
            '[' if chars.get(i + 1) == Some(&':') => {
                let Some(end) = (i + 2..chars.len().saturating_sub(1))
                    .find(|&j| chars[j] == ':' && chars[j + 1] == ']')
                else {
                    out.push_str(r"\[");
                    i += 1;
                    continue;
                };
                out.extend(&chars[i..end + 2]);
                i = end + 2;
            }
            '[' => {
                out.push_str(r"\[");
                i += 1;
            }
            '&' | '~' => {
                out.push('\\');
                out.push(chars[i]);
                i += 1;
            }
            '-' if chars.get(i + 1) == Some(&'-') => {
                out.push_str(r"\-");
                i += 1;
            }
            '\\' => {
                let Some(&next) = chars.get(i + 1) else {
                    out.push_str(r"\\");
                    i += 1;
                    continue;
                };
                let expansion = match next {
                    'd' if !utf => Some(DIGIT.to_owned()),
                    'w' if !utf => Some(WORD.to_owned()),
                    's' if !utf => Some(SPACE.to_owned()),
                    'D' if !utf => Some(format!("[^{DIGIT}]")),
                    'W' if !utf => Some(format!("[^{WORD}]")),
                    'S' if !utf => Some(format!("[^{SPACE}]")),
                    'h' => Some(HORIZONTAL.to_owned()),
                    'H' => Some(format!("[^{HORIZONTAL}]")),
                    'v' => Some(VERTICAL.to_owned()),
                    'V' => Some(format!("[^{VERTICAL}]")),
                    _ => None,
                };
                i += 2;
                if let Some(expansion) = expansion {
                    out.push_str(&expansion);
                    // After a class escape a `-` can't start a range, and is literal.
                    if chars.get(i) == Some(&'-') {
                        out.push_str(r"\-");
                        i += 1;
                    }
                } else if next == 'b' {
                    out.push_str(r"\x08");
                } else if next == 'e' {
                    out.push_str(r"\x1B");
                } else {
                    out.push('\\');
                    out.push(next);
                }
            }
            other => {
                out.push(other);
                i += 1;
            }
        }
    }
    // PCRE refuses an unterminated class; closing it lets the compile report the pattern.
    out.push(']');
    i
}

#[cfg(test)]
mod tests {
    use super::*;

    fn replace(pattern: &str, subject: &[u8]) -> Vec<u8> {
        Pcre::new(pattern).unwrap().replace_all(subject, b"_")
    }

    #[test]
    fn matches_bytes_without_unicode_classes() {
        let word = Pcre::new(r"/^\w+$/").unwrap();
        assert!(word.is_match(b"abc_09"));
        // é is two bytes (or one in Latin-1), and neither is a word character.
        assert!(!word.is_match("é".as_bytes()));
        assert!(!word.is_match(b"\xe9"));
        // \s doesn't take NBSP or NEL; \d doesn't take other digits.
        assert!(!Pcre::new(r"/\s/").unwrap().is_match(b"\xa0\x85"));
        assert!(!Pcre::new(r"/\d/").unwrap().is_match("٣".as_bytes()));
        assert!(Pcre::new(r"/^.$/").unwrap().is_match(b"\xe9"));
        assert!(!Pcre::new(r"/^.$/").unwrap().is_match("é".as_bytes()));
    }

    #[test]
    fn word_boundary_is_ascii() {
        // Latin-1 é isn't a word character, so there's a boundary after it.
        assert_eq!(replace(r"/\bfoo/", b"\xe9foo"), b"\xe9_");
        assert_eq!(replace(r"/\Bfoo/", b"afoo"), b"a_");
    }

    #[test]
    fn utf8_mode_is_unicode_aware_and_rejects_invalid_subjects() {
        let word = Pcre::new(r"/^\w+$/u").unwrap();
        assert!(word.is_match("é".as_bytes()));
        assert!(!word.is_match(b"\xe9"));
        assert_eq!(
            Pcre::new(r"/é/u").unwrap().replace_all(b"\xe9", b"_"),
            b"\xe9"
        );
    }

    #[test]
    fn accepts_php_only_syntax() {
        // The file counter pattern: `[` inside a class and `]` first in one.
        let counter =
            Pcre::new(r"/[[(\s](\d{1,5})(\/|[\s_]of[\s_]|-)(\d{1,5})[])[\s$:]/i").unwrap();
        let hit = counter.captures(b"foo [03/62] bar").unwrap();
        assert_eq!(hit.group(1), Some(&b"03"[..]));
        assert_eq!(hit.group(3), Some(&b"62"[..]));
        // A brace that isn't a quantifier is literal.
        assert!(Pcre::new(r"/\s{K,M,G}B/").unwrap().is_match(b" {K,M,G}B"));
        assert!(Pcre::new(r"/a{2}/").unwrap().is_match(b"aa"));
        // Possessive quantifiers, lookaround and named groups in both spellings.
        assert!(Pcre::new(r"/a++b(?=c)/").unwrap().is_match(b"aabc"));
        assert!(Pcre::new(r"/^(?!foo)/").unwrap().is_match(b"bar"));
        assert!(Pcre::new("#(?'one'a)(?<two>b)(?P<three>c)#").is_ok());
        // Class escapes followed by `-`, and class set syntax that is literal in PCRE.
        assert!(Pcre::new(r"/^[\w-]+$/").unwrap().is_match(b"a-b"));
        assert!(Pcre::new(r"/^[\d-x]+$/").unwrap().is_match(b"1-x"));
        assert!(Pcre::new(r"/^[a&&b]+$/").unwrap().is_match(b"a&b"));
        assert!(Pcre::new(r"/^[[:alpha:]]+$/").unwrap().is_match(b"abc"));
        assert!(Pcre::new(r"/^\p{L}+$/u").unwrap().is_match("é".as_bytes()));
        assert!(Pcre::new(r"/\x{e9}/u").unwrap().is_match("é".as_bytes()));
        assert!(Pcre::new(r"/\x{e9}/").unwrap().is_match(b"\xe9"));
        assert_eq!(replace(r"/\<a\>/", b"<a>"), b"_");
        assert!(Pcre::new(r"/a\0b/").unwrap().is_match(b"a\0b"));
        assert!(Pcre::new(r"/\012/").unwrap().is_match(b"\n"));
        // PHP ends the pattern at the first unescaped delimiter.
        assert!(Pcre::new("/a/b/").is_err());
        assert!(Pcre::new(r"/a\/b/").unwrap().is_match(b"a/b"));
        assert!(Pcre::new("{a{2}}").unwrap().is_match(b"aa"));
        assert!(Pcre::new(r"/^\Qa.b\E$/").unwrap().is_match(b"a.b"));
        assert!(!Pcre::new(r"/^\Qa.b\E$/").unwrap().is_match(b"axb"));
    }

    #[test]
    fn named_groups_sort_by_name_and_skip_unmatched() {
        let regex = Pcre::new(r"/(?P<b>x)(?P<a>y)(?P<c>z)?/").unwrap();
        let hit = regex.captures(b"xy").unwrap();
        assert_eq!(hit.named(), vec![("a", &b"y"[..]), ("b", &b"x"[..])]);
    }

    #[test]
    fn replaces_bytes_and_keeps_the_rest() {
        assert_eq!(replace(r"/\d+/", b"\xe91\xff22\xfe"), b"\xe9_\xff_\xfe");
        assert_eq!(replace(r"/z/", b"\xe9abc"), b"\xe9abc");
    }

    #[test]
    fn compiles_every_seeded_regex() {
        // Everything Laravel seeded has to compile, since a regex that doesn't never matches.
        let seed = include_str!("../../../migrations/0002_seed_baseline.sql");
        let mut compiled = 0;
        let mut failures = Vec::new();
        for table in ["collection_regexes", "release_naming_regexes"] {
            let prefix = format!("INSERT INTO `{table}`");
            for line in seed.lines().filter(|line| line.starts_with(&prefix)) {
                match Pcre::new(&third_value(line)) {
                    Ok(_) => compiled += 1,
                    Err(failure) => failures.push(format!("{failure:#}")),
                }
            }
        }
        assert!(failures.is_empty(), "{}", failures.join("\n"));
        assert!(compiled > 1000, "only {compiled} regexes found");
    }

    /// The `regex` column of a seeded row: id, group_regex, regex.
    fn third_value(line: &str) -> String {
        let values = line.split(" VALUES (").nth(1).unwrap();
        let mut column = 0;
        let mut text = String::new();
        let mut chars = values.chars();
        let mut quoted = false;
        while let Some(c) = chars.next() {
            match (quoted, c) {
                (false, '\'') => quoted = true,
                (false, ',') => column += 1,
                (true, '\'') => {
                    quoted = false;
                    if column == 2 {
                        return text;
                    }
                }
                (true, '\\') => {
                    let escaped = chars.next().unwrap();
                    if column == 2 {
                        text.push(match escaped {
                            'n' => '\n',
                            't' => '\t',
                            'r' => '\r',
                            other => other,
                        });
                    }
                }
                (true, other) if column == 2 => text.push(other),
                _ => {}
            }
        }
        panic!("no regex in {line}");
    }
}
