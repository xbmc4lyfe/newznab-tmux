//! What a subject line says about the upload it belongs to.

use std::sync::LazyLock;

use fancy_regex::Regex as FancyRegex;
use regex::Regex;

static QUOTED: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r#""[^"\r\n]*"|'[^'\r\n]*'"#).expect("valid"));
static TRAILING_COUNTER: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"[ \t\n\x0B\f\r]*\([0-9]+/[0-9]+\)[ \t\n\x0B\f\r]*$").expect("valid")
});
static COUNTER: LazyLock<FancyRegex> = LazyLock::new(|| {
    FancyRegex::new(r"(?<![^ \t\n\x0B\f\r])[\[(]([0-9]+)/([0-9]+)[\])](?=[ \t\n\x0B\f\r]|$)")
        .expect("valid")
});

// PCRE without `/u` matches ASCII `\\s` and `\\d` only, so the classes are spelled out.

/// The file count a subject declares outside its quoted names and trailing part counter.
pub fn declared_file_count(subject: &str) -> i64 {
    let outside = QUOTED.replace_all(subject, "");
    let outside = TRAILING_COUNTER.replace(&outside, "");
    let mut expected = 0;
    for capture in COUNTER.captures_iter(&outside).flatten() {
        let index: i64 = capture[1].parse().unwrap_or(i64::MAX);
        let total: i64 = capture[2].parse().unwrap_or(0);
        if total > 0 && index <= total {
            expected = expected.max(total);
        }
    }
    expected
}

#[cfg(test)]
mod tests {
    use super::declared_file_count;

    /// Each answer is what `NzbManifestReader::declaredFileCount` returns.
    #[test]
    fn counts_match_the_php_reader() {
        for (subject, expected) in [
            ("[01/10] - \"file.rar\" yEnc (1/5)", 10),
            ("\"[01/10] inside.rar\" yEnc (1/5)", 0),
            ("Release (1/5)", 0),
            ("Release (1/5) tail", 5),
            ("(3/2) over", 0),
            ("x[1/9] attached", 0),
            ("[1/9]attached", 0),
            ("(1/3) [2/7] both", 7),
            ("[1/0] zero", 0),
        ] {
            assert_eq!(declared_file_count(subject), expected, "{subject}");
        }
    }
}
