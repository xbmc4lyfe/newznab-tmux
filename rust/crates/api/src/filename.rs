//! `FilenameSanitizer` and Symfony's `HeaderUtils::makeDisposition`.

use unicode_normalization::UnicodeNormalization;

use crate::usage::raw_url_encode;

const MAX_FILENAME_LENGTH: usize = 200;
const UNICODE_PATH_SEPARATORS: [char; 11] =
    ['⁄', '∕', '⧸', '／', '＼', '﹨', '⧵', '⟋', '⟍', '╱', '╲'];

fn trim_filename(name: &str) -> &str {
    name.trim_matches([' ', '\t', '\n', '\r', '\0', '\u{b}', '.', '_', '-'])
        .trim_start_matches('.')
}

fn collapse_underscores(name: &str) -> String {
    let mut out = String::with_capacity(name.len());
    for ch in name.chars() {
        if ch != '_' || !out.ends_with('_') {
            out.push(ch);
        }
    }
    out
}

fn normalize(name: &str) -> String {
    let name: String = crate::input::trim(name).nfkc().collect();
    let mut out = String::with_capacity(name.len());
    for ch in name.chars() {
        let ch = if UNICODE_PATH_SEPARATORS.contains(&ch) {
            '/'
        } else {
            ch
        };
        match ch {
            '\u{0}'..='\u{1f}' | '\u{7f}' => {}
            '/' | '\\' | ':' | '*' | '?' | '"' | '<' | '>' | '|' => out.push('_'),
            c if c.is_whitespace() || c == ',' => out.push('_'),
            c => out.push(c),
        }
    }
    trim_filename(&collapse_underscores(&out)).to_owned()
}

fn finalize(name: &str, fallback: &str) -> String {
    let cut: String = name.chars().take(MAX_FILENAME_LENGTH).collect();
    let trimmed = trim_filename(&cut);
    if trimmed.is_empty() {
        safe_fallback(fallback)
    } else {
        trimmed.to_owned()
    }
}

/// `Str::ascii`: accents fold to their base letters; scripts with no ASCII form drop out.
fn ascii(name: &str) -> String {
    let mut out = String::with_capacity(name.len());
    for ch in name.nfkd() {
        match ch {
            c if c.is_ascii() => out.push(c),
            'ß' => out.push_str("ss"),
            'æ' => out.push_str("ae"),
            'Æ' => out.push_str("AE"),
            'œ' => out.push_str("oe"),
            'Œ' => out.push_str("OE"),
            'ø' => out.push('o'),
            'Ø' => out.push('O'),
            'đ' | 'ð' => out.push('d'),
            'Đ' | 'Ð' => out.push('D'),
            'ł' => out.push('l'),
            'Ł' => out.push('L'),
            'þ' => out.push_str("th"),
            'Þ' => out.push_str("Th"),
            _ => {}
        }
    }
    out
}

fn ascii_safe(name: &str) -> String {
    let mut out = String::with_capacity(name.len());
    let mut run = false;
    for ch in ascii(name).chars() {
        if ch.is_ascii_alphanumeric() || matches!(ch, '.' | '_' | '-') {
            out.push(ch);
            run = false;
        } else if !run {
            out.push('_');
            run = true;
        }
    }
    collapse_underscores(&out)
}

fn safe_fallback(fallback: &str) -> String {
    let safe: String = ascii_safe(fallback)
        .chars()
        .take(MAX_FILENAME_LENGTH)
        .collect();
    let trimmed = trim_filename(&safe);
    if trimmed.is_empty() {
        "download".to_owned()
    } else {
        trimmed.to_owned()
    }
}

pub fn sanitize(name: &str, fallback: &str) -> String {
    let normalized = normalize(name);
    if normalized.is_empty() {
        return safe_fallback(fallback);
    }
    finalize(&normalized, fallback)
}

pub fn ascii_fallback(name: &str, fallback: &str) -> String {
    finalize(&ascii_safe(&sanitize(name, fallback)), fallback)
}

fn is_token(value: &str) -> bool {
    !value.is_empty()
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || b"!#$%&'*.^_`|~-".contains(&byte))
}

/// `attachment; filename=...`, with an RFC 5987 form when the ASCII fallback differs.
pub fn attachment(filename: &str, fallback: &str) -> String {
    let shown = if is_token(fallback) {
        fallback.to_owned()
    } else {
        format!(
            "\"{}\"",
            fallback.replace('\\', "\\\\").replace('"', "\\\"")
        )
    };
    let mut header = format!("attachment; filename={shown}");
    if filename != fallback {
        header.push_str("; filename*=utf-8''");
        header.push_str(&raw_url_encode(filename));
    }
    header
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn names_sanitize_like_php() {
        let name = "Déjà Vu: 日本語/x, y";
        assert_eq!(sanitize(name, "f"), "Déjà_Vu_日本語_x_y");
        assert_eq!(ascii_fallback(name, "f"), "Deja_Vu_x_y");
        assert_eq!(sanitize("  ..  ", "release-1"), "release-1");
        assert_eq!(sanitize("a／b", "f"), "a_b");
    }
}
