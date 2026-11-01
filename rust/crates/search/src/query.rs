//! Turning user text into Manticore query expressions, from the static helpers on
//! `ManticoreSearchDriver`. PHP's byte semantics matter here, so the helpers below spell out
//! ASCII-only whitespace where the PHP patterns lacked the `u` flag.

use std::sync::LazyLock;

use regex::Regex;

const WS: &str = r" \t\n\x0B\x0C\r";

/// PHP `trim`, which strips NUL and vertical tab, but not form feed.
pub fn php_trim(value: &str) -> &str {
    value.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B'])
}

/// PHP `empty` on a string, where "0" counts as empty.
pub(crate) fn php_empty(value: &str) -> bool {
    value.is_empty() || value == "0"
}

/// PHP `str_replace` with arrays: each pair runs over the result of the one before it.
fn replace_each(value: &str, pairs: &[(&str, &str)]) -> String {
    pairs
        .iter()
        .fold(value.to_owned(), |text, (from, to)| text.replace(from, to))
}

fn is_ascii_space(character: char) -> bool {
    matches!(character, ' ' | '\t' | '\n' | '\x0B' | '\x0C' | '\r')
}

fn collapse_ascii_spaces(value: &str) -> String {
    let mut collapsed = String::with_capacity(value.len());
    let mut in_space = false;
    for character in value.chars() {
        if is_ascii_space(character) {
            if !in_space {
                collapsed.push(' ');
            }
            in_space = true;
        } else {
            collapsed.push(character);
            in_space = false;
        }
    }
    collapsed
}

/// Escapes every operator. Autocomplete and "did you mean" use this. It keeps PHP's quirk of
/// trimming the trailing `-` or `!` but not the backslash before it.
pub fn escape_string(value: &str) -> String {
    if value == "*" || php_empty(value) {
        return String::new();
    }
    let escaped = replace_each(
        value,
        &[
            ("\\", "\\\\"),
            ("(", "\\("),
            (")", "\\)"),
            ("@", "\\@"),
            ("~", "\\~"),
            ("\"", "\\\""),
            ("&", "\\&"),
            ("/", "\\/"),
            ("$", "\\$"),
            ("=", "\\="),
            ("'", "\\'"),
            ("--", "\\--"),
            ("[", "\\["),
            ("]", "\\]"),
            ("!", "\\!"),
            ("-", "\\-"),
        ],
    );
    php_trim(escaped.trim_end_matches(['-', '!'])).to_owned()
}

const USER_ESCAPES: &[(&str, &str)] = &[
    ("\\", "\\\\"),
    ("@", "\\@"),
    ("~", "\\~"),
    ("&", "\\&"),
    ("/", "\\/"),
    ("$", "\\$"),
    ("=", "\\="),
    ("'", "\\'"),
    ("[", "\\["),
    ("]", "\\]"),
    ("\"", "\\\""),
];

const INNER_OPERATOR_ESCAPES: &[(&str, &str)] = &[("!", "\\!"), ("-", "\\-")];

/// Keeps the operators users may type (`!`, `-`, `"…"`, `|`, `*`, parentheses) and escapes the
/// rest.
pub fn prepare_user_search_query(query: &str) -> String {
    static TOKENS: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(&format!(r#"[-!]?"[^"]*"|[^{WS}]+"#)).expect("valid token pattern")
    });

    let query = php_trim(query);
    if query.is_empty() || query == "*" {
        return String::new();
    }

    let mut processed = Vec::new();
    for found in TOKENS.find_iter(query) {
        let mut token = found.as_str();
        if token == "|" {
            processed.push("|".to_owned());
            continue;
        }

        let mut leading = String::new();
        let mut trailing = String::new();
        while let Some(rest) = token.strip_prefix('(') {
            leading.push('(');
            token = rest;
        }
        while !token.starts_with('"') {
            let Some(rest) = token.strip_suffix(')') else {
                break;
            };
            trailing.push(')');
            token = rest;
        }

        if token.is_empty() {
            if !leading.is_empty() || !trailing.is_empty() {
                processed.push(format!("{leading}{trailing}"));
            }
            continue;
        }

        let mut negation = "";
        if token.len() > 1 && (token.starts_with('!') || token.starts_with('-')) {
            negation = &token[..1];
            token = &token[1..];
        }

        if token.len() > 1 && token.starts_with('"') && token.ends_with('"') {
            let inner = replace_each(
                &replace_each(&token[1..token.len() - 1], USER_ESCAPES),
                INNER_OPERATOR_ESCAPES,
            );
            processed.push(format!("{leading}{negation}\"{inner}\"{trailing}"));
            continue;
        }

        let mut wildcard_prefix = "";
        let mut wildcard_suffix = "";
        if token.starts_with('*') {
            wildcard_prefix = "*";
            token = token.trim_start_matches('*');
        }
        if token.ends_with('*') {
            wildcard_suffix = "*";
            token = token.trim_end_matches('*');
        }

        let word = replace_each(&replace_each(token, USER_ESCAPES), INNER_OPERATOR_ESCAPES);
        if !word.is_empty() || !wildcard_prefix.is_empty() || !wildcard_suffix.is_empty() {
            processed.push(format!(
                "{leading}{negation}{wildcard_prefix}{word}{wildcard_suffix}{trailing}"
            ));
        }
    }

    php_trim(&processed.join(" ")).to_owned()
}

/// `fromname` holds addresses, so a poster search treats every operator as literal text.
pub fn prepare_poster_query(query: &str) -> String {
    let query = php_trim(query);
    replace_each(
        query,
        &[
            ("\\", "\\\\"),
            ("@", "\\@"),
            ("~", "\\~"),
            ("&", "\\&"),
            ("/", "\\/"),
            ("$", "\\$"),
            ("=", "\\="),
            ("'", "\\'"),
            ("[", "\\["),
            ("]", "\\]"),
            ("(", "\\("),
            (")", "\\)"),
            ("\"", "\\\""),
            ("!", "\\!"),
            ("-", "\\-"),
            ("|", "\\|"),
            ("^", "\\^"),
            ("<", "\\<"),
            (">", "\\>"),
            ("*", "\\*"),
            ("?", "\\?"),
            ("%", "\\%"),
        ],
    )
}

pub(crate) fn scope_to_field(prepared: &str, selector: &str) -> String {
    let prepared = php_trim(prepared);
    if prepared.is_empty() {
        String::new()
    } else if selector.is_empty() {
        format!("({prepared})")
    } else {
        format!("{selector} ({prepared})")
    }
}

/// Scopes a prepared query to a field. A plain release name also gets an alternative with its
/// separators as spaces, because Manticore tokenizes `WEB-DL.x265` on them while the prepared
/// form escapes them.
pub fn scope_release_search_query(raw: &str, prepared: &str, selector: &str) -> String {
    static NOT_PLAIN: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"[^\p{L}\p{N}\s._-]").expect("valid pattern"));
    static SEPARATORS: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"[._-]+").expect("valid pattern"));

    let primary = scope_to_field(prepared, selector);
    if !matches!(selector, "@searchname" | "@(name,searchname)") || has_negation(raw) {
        return primary;
    }
    if NOT_PLAIN.is_match(raw) || !raw.contains(['.', '_', '-']) {
        return primary;
    }
    let spaced = SEPARATORS.replace_all(php_trim(raw), " ");
    let normalized = collapse_ascii_spaces(php_trim(&spaced));
    let normalized = php_trim(&normalized);
    let normalized_prepared = prepare_user_search_query(normalized);
    if normalized_prepared.is_empty() || normalized_prepared == prepared {
        return primary;
    }
    format!(
        "({primary} | {})",
        scope_to_field(&normalized_prepared, selector)
    )
}

/// Treats a name as literal text inside a quoted phrase.
pub(crate) fn escape_literal(name: &str) -> String {
    let mut literal = String::with_capacity(name.len());
    for character in name.chars() {
        if "\\()|!@~\"&/^$=<>[]{}*-?".contains(character) {
            literal.push('\\');
        }
        literal.push(character);
    }
    literal
}

/// Keeps letters and numbers, in any script, with single spaces between the runs.
pub(crate) fn title_tokens(title: &str) -> String {
    let mapped: String = title
        .chars()
        .map(|c| if c.is_alphanumeric() { c } else { ' ' })
        .collect();
    mapped.split_whitespace().collect::<Vec<_>>().join(" ")
}

/// `@@relaxed` is query-wide and only valid once, so per-field terms share one.
pub fn build_field_search_expression(
    fields: &[(String, String)],
    allowed: Option<&[&str]>,
) -> Option<String> {
    let terms: Vec<String> = fields
        .iter()
        .filter(|(field, _)| allowed.is_none_or(|allowed| allowed.contains(&field.as_str())))
        .filter(|(_, value)| !value.is_empty())
        .filter_map(|(field, value)| {
            let prepared = prepare_user_search_query(value);
            (!prepared.is_empty())
                .then(|| scope_release_search_query(value, &prepared, &format!("@{field}")))
        })
        .collect();
    (!terms.is_empty()).then(|| format!("@@relaxed {}", terms.join(" ")))
}

/// True when any word starts with `!` or `-`. Fuzzy search strips those, so it would return
/// exactly what the user excluded.
pub fn has_negation(value: &str) -> bool {
    static NEGATION: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(&format!(r"(?:^|[{WS}])[!-][^{WS}]")).expect("valid pattern"));
    NEGATION.is_match(value)
}

/// What fuzzy search can take: ASCII word characters and spaces. A non-ASCII character becomes
/// a space, because the PHP pattern worked on bytes.
pub fn fuzzy_clean(value: &str) -> String {
    let cleaned: String = value
        .chars()
        .map(|character| {
            if character.is_ascii_alphanumeric() || character == '_' || is_ascii_space(character) {
                character
            } else {
                ' '
            }
        })
        .collect();
    collapse_ascii_spaces(php_trim(&cleaned))
}

/// A readable suggestion from a release name, cut to about 80 bytes around the match. PHP cut
/// bytes and could split a character; this moves the cut to the nearest character boundary.
pub fn extract_suggestion(searchname: &str, query: &str) -> Option<String> {
    static EXTENSION: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(r"(?i)\.(?:mkv|avi|mp4|wmv|nfo|nzb|par2|rar|zip|r[0-9]+)(\n?)\z")
            .expect("valid pattern")
    });
    static LEADING_FRAGMENT: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(&format!(r"^[^{WS}]*[{WS}]")).expect("valid pattern"));
    static TRAILING_FRAGMENT: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(&format!(r"[{WS}][^{WS}]*\z")).expect("valid pattern"));

    let clean = EXTENSION.replace(searchname, "$1").replace(['.', '_'], " ");
    let clean = collapse_ascii_spaces(&clean);
    let clean = php_trim(&clean);
    if php_empty(clean) {
        return None;
    }
    if clean.len() <= 80 {
        return Some(clean.to_owned());
    }
    let Some(position) = find_ignore_ascii_case(clean, query) else {
        return Some(byte_window(clean, 0, 80).to_owned());
    };
    let start = position.saturating_sub(10);
    let mut extracted = byte_window(clean, start, 80).to_owned();
    if start > 0 {
        extracted = LEADING_FRAGMENT.replace(&extracted, "").into_owned();
    }
    extracted = TRAILING_FRAGMENT.replace(&extracted, "").into_owned();
    Some(php_trim(&extracted).to_owned())
}

fn find_ignore_ascii_case(haystack: &str, needle: &str) -> Option<usize> {
    if needle.is_empty() {
        return Some(0);
    }
    haystack
        .as_bytes()
        .windows(needle.len())
        .position(|window| window.eq_ignore_ascii_case(needle.as_bytes()))
}

fn byte_window(value: &str, start: usize, length: usize) -> &str {
    let mut from = start.min(value.len());
    while !value.is_char_boundary(from) {
        from += 1;
    }
    let mut to = from.saturating_add(length).min(value.len());
    while !value.is_char_boundary(to) {
        to -= 1;
    }
    &value[from..to]
}

/// PHP `levenshtein`, counting bytes.
pub(crate) fn levenshtein(left: &str, right: &str) -> usize {
    let (left, right) = (left.as_bytes(), right.as_bytes());
    let mut row: Vec<usize> = (0..=right.len()).collect();
    for (i, a) in left.iter().enumerate() {
        let mut previous = row[0];
        row[0] = i + 1;
        for (j, b) in right.iter().enumerate() {
            let substitute = previous + usize::from(a != b);
            previous = row[j + 1];
            row[j + 1] = substitute.min(row[j + 1] + 1).min(row[j] + 1);
        }
    }
    row[right.len()]
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn levenshtein_counts_edits() {
        assert_eq!(levenshtein("kitten", "sitting"), 3);
        assert_eq!(levenshtein("", "abc"), 3);
        assert_eq!(levenshtein("same", "same"), 0);
    }

    #[test]
    fn suggestions_cut_on_character_boundaries() {
        let long = format!("{}.Needle.{}", "é".repeat(50), "é".repeat(50));
        assert!(
            extract_suggestion(&long, "needle")
                .unwrap()
                .contains("Needle")
        );
        assert!(extract_suggestion(&long, "zzz").unwrap().len() <= 80);
    }
}
