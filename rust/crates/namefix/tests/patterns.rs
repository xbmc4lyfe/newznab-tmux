//! Pattern checks that don't depend on which branches the golden corpus reaches: every regex
//! literal in the PHP is in the Rust source byte for byte, and every literal compiles in the
//! engine its macro uses, so a typo in a lazily compiled static can't first show up at runtime.

use nntmux_core::pcre::Pcre;
use nntmux_namefix::compat::pcre_dollar;
use regex::Regex;
use serde_json::Value;

const SOURCES: [(&str, &str); 14] = [
    ("checkers/mod.rs", include_str!("../src/checkers/mod.rs")),
    ("checkers/tv.rs", include_str!("../src/checkers/tv.rs")),
    (
        "checkers/movie.rs",
        include_str!("../src/checkers/movie.rs"),
    ),
    ("checkers/game.rs", include_str!("../src/checkers/game.rs")),
    ("checkers/app.rs", include_str!("../src/checkers/app.rs")),
    (
        "extractors/file.rs",
        include_str!("../src/extractors/file.rs"),
    ),
    (
        "extractors/nfo.rs",
        include_str!("../src/extractors/nfo.rs"),
    ),
    (
        "file_prioritizer.rs",
        include_str!("../src/file_prioritizer.rs"),
    ),
    (
        "predb_constrained.rs",
        include_str!("../src/predb_constrained.rs"),
    ),
    ("identity_key.rs", include_str!("../src/identity_key.rs")),
    ("donor.rs", include_str!("../src/donor.rs")),
    ("compat.rs", include_str!("../src/compat.rs")),
    ("result.rs", include_str!("../src/result.rs")),
    (
        "extractors/mod.rs",
        include_str!("../src/extractors/mod.rs"),
    ),
];

/// A raw string literal and the macro call it sits directly in, if any.
struct Literal {
    file: &'static str,
    body: String,
    macro_name: Option<String>,
}

fn literals() -> Vec<Literal> {
    let before = Regex::new(r"(\w+)!\(\s*(?:i\s+)?(?:[\w.&]+\s*,\s*)?$").unwrap();
    let mut found = Vec::new();
    for (file, source) in SOURCES {
        // Tests in the sources assert against literals of their own; they aren't patterns.
        let source = source.split("#[cfg(test)]").next().unwrap();
        let bytes = source.as_bytes();
        let mut at = 0;
        while at < bytes.len() {
            let starts_raw = bytes[at] == b'r'
                && (at == 0 || !(bytes[at - 1].is_ascii_alphanumeric() || bytes[at - 1] == b'_'))
                && {
                    let hashes = bytes[at + 1..].iter().take_while(|&&b| b == b'#').count();
                    bytes.get(at + 1 + hashes) == Some(&b'"')
                };
            if !starts_raw {
                at += 1;
                continue;
            }
            let hashes = bytes[at + 1..].iter().take_while(|&&b| b == b'#').count();
            let open = at + 2 + hashes;
            let closer = format!("\"{}", "#".repeat(hashes));
            let close = open
                + source[open..]
                    .find(&closer)
                    .expect("unterminated raw string");
            let context = &source[at.saturating_sub(120)..at];
            found.push(Literal {
                file,
                body: source[open..close].to_owned(),
                macro_name: before.captures(context).map(|c| c[1].to_owned()),
            });
            at = close + closer.len();
        }
    }
    found
}

/// PHP regexes whose job Rust code does without a regex, or that can never match.
const NOT_PORTED_AS_REGEX: [(&str, &str); 9] = [
    (r"\p{C}", "has_control"),
    (r"\r\n|\r|\n", "split_lines"),
    (
        r"\s+",
        "split on the single spaces left by the previous replace",
    ),
    (r"\d+", "strip_leading_zeros"),
    (r"[\\\/]([^\\\/]+)$", "filename_from_path"),
    (
        r"\w[\-\w.',;& ]+1080i[._ -]DD5[._ -]1[._ -]MPEG2-R&C(?=\.ts)$",
        "dead: $ cannot hold before .ts",
    ),
    (
        r"\wtvp-[\w.',;]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](720p|1080p|xvid)(?=\.(avi|mkv))$",
        "dead: $ cannot hold before the extension",
    ),
    (
        r"^(.+?(x264|XviD)\-TVP)\\",
        "dead: subsumed by Scene release with group",
    ),
    (
        r"^([A-Za-z0-9][\w.\-]+\-[A-Za-z0-9]+)[\\\/].+\.(rar|r\d{2,4})$",
        "dead: subsumed by RAR archive path",
    ),
];

#[test]
fn every_php_pattern_is_in_the_rust_source() {
    let patterns: Vec<Value> =
        serde_json::from_str(include_str!("fixtures/patterns.json")).unwrap();
    assert!(patterns.len() > 100);
    let have: Vec<String> = literals().into_iter().map(|l| l.body).collect();
    let mut missing = Vec::new();
    for pattern in &patterns {
        let body = pattern["body"].as_str().unwrap();
        let flags = pattern["flags"].as_str().unwrap();
        let file = pattern["file"].as_str().unwrap();
        // Pcre literals carry their delimiters and flags.
        let delimited = format!("/{body}/{flags}");
        let ported = have.iter().any(|b| b == body || *b == delimited)
            || NOT_PORTED_AS_REGEX.iter().any(|(php, _)| *php == body);
        if !ported {
            missing.push(format!("{file}: /{body}/{flags}"));
        }
    }
    assert!(
        missing.is_empty(),
        "not in the Rust source:\n{}",
        missing.join("\n")
    );
}

#[test]
fn every_literal_compiles_in_its_engine() {
    let lookaround = Regex::new(r"\(\?(<!|<=|!|=)").unwrap();
    let mut checked = 0;
    for l in literals() {
        let body = l.body.as_str();
        // Test fixtures and doc examples that merely look like raw strings.
        if l.file == "compat.rs" && (body.starts_with("/[$]") || body.starts_with("/a$")) {
            continue;
        }
        let tag = format!("{}: {body}", l.file);
        match l.macro_name.as_deref() {
            Some("rx" | "mi" | "ms") => {
                assert!(
                    !lookaround.is_match(body),
                    "lookaround in a byte regex: {tag}"
                );
                // `compile` panics on a bad pattern.
                nntmux_categorize::pcre::compile(body, true);
                checked += 1;
            }
            Some("rx_d" | "rx_m") => {
                regex::bytes::RegexBuilder::new(body)
                    .unicode(false)
                    .build()
                    .unwrap_or_else(|e| panic!("{tag}: {e}"));
                checked += 1;
            }
            Some("frx") => {
                Pcre::with_flags(body, "i").unwrap_or_else(|e| panic!("{tag}: {e:#}"));
                checked += 1;
            }
            Some("pcre") => {
                Pcre::new(&pcre_dollar(body)).unwrap_or_else(|e| panic!("{tag}: {e:#}"));
                checked += 1;
            }
            _ => {
                // Anywhere else (a pattern passed to a helper, a delimited `Pcre::new`): the
                // engine that accepts the most must accept it.
                if body.starts_with('/') && body.rfind('/').is_some_and(|end| end > 0) {
                    Pcre::new(&pcre_dollar(body)).unwrap_or_else(|e| panic!("{tag}: {e:#}"));
                } else {
                    Pcre::with_flags(body, "i").unwrap_or_else(|e| panic!("{tag}: {e:#}"));
                }
                checked += 1;
            }
        }
    }
    assert!(checked > 125, "only {checked} literals found");
}
