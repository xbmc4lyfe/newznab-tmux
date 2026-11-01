//! What a subject line says about the upload it belongs to.

use std::collections::HashSet;
use std::sync::LazyLock;

use anyhow::{Result, bail};
use fancy_regex::Regex as FancyRegex;
use regex::Regex;
use sha2::{Digest, Sha256};

use crate::manifest::{Manifest, ManifestFile, Segment};
use crate::nzb::fingerprint::php_trim;
use crate::nzb::parse::parse;

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
        let total: i64 = capture[2].parse().unwrap_or(i64::MAX);
        if total > 0 && index <= total {
            expected = expected.max(total);
        }
    }
    expected
}

/// What a manifest built from an NZB takes from its release row.
#[derive(Clone, Debug, Default)]
pub struct ReleaseFacts {
    pub searchname: String,
    pub fromname: String,
    pub postdate: Option<String>,
    pub groups_id: i64,
    /// Set for an uploaded or imported NZB; other documents read as an audit.
    pub import: bool,
    pub categories_id: Option<i64>,
    pub import_name_override: bool,
    pub original_subject: Option<String>,
}

/// An NZB's password metadata can't be trusted: the import is rejected rather than retried.
#[derive(Debug, thiserror::Error)]
#[error("{0}")]
pub struct PasswordMetadataRejected(pub &'static str);

const MAX_DOCUMENT_BYTES: usize = 268_435_456;

static PART_COUNTER: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"\([0-9]+/([0-9]+)\)[ \t\n\x0B\f\r]*$").expect("valid"));
static COUNT: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[1-9][0-9]{0,6}$").expect("valid"));

/// PHP's `(int)` of a numeric-leading string: saturating for a plain integer, and through a float
/// for one with an exponent, whose fraction truncates and whose overflow gives 0.
fn php_int(value: &str) -> i64 {
    let trimmed = value.trim_start_matches([' ', '\t', '\n', '\r', '\x0B', '\x0C']);
    let (negative, digits) = match trimmed.strip_prefix('-') {
        Some(rest) => (true, rest),
        None => (false, trimmed.strip_prefix('+').unwrap_or(trimmed)),
    };
    let end = digits.bytes().take_while(u8::is_ascii_digit).count();
    if end > 0 && digits[end..].starts_with(['e', 'E']) {
        let rest = &digits[end + 1..];
        let sign = usize::from(rest.starts_with(['-', '+']));
        let exponent = rest[sign..].bytes().take_while(u8::is_ascii_digit).count();
        if exponent > 0 {
            let float: f64 = digits[..end + 1 + sign + exponent]
                .parse()
                .unwrap_or(f64::INFINITY);
            let float = if negative { -float } else { float };
            return if float.abs() < 9.2e18 {
                float as i64
            } else {
                0
            };
        }
    }
    if negative {
        format!("-{}", &digits[..end])
            .parse::<i64>()
            .unwrap_or(if end == 0 { 0 } else { i64::MIN })
    } else {
        digits[..end]
            .parse::<i64>()
            .unwrap_or(if end == 0 { 0 } else { i64::MAX })
    }
}

/// Reads an NZB as a manifest. `Err` with [`PasswordMetadataRejected`] when its password metadata
/// can't be trusted.
pub fn read_manifest(xml: &[u8], facts: &ReleaseFacts) -> Result<Manifest> {
    if xml.len() > MAX_DOCUMENT_BYTES {
        bail!("NZB exceeds audit metadata budget.");
    }
    let document = parse(xml)?;
    // PHP rejected an entity reference where metadata lives, and read one elsewhere. A declared
    // entity has no honest use in an NZB, so any declaration is held for review.
    if document.declares_entities || (document.password_in_head && document.heads > 1) {
        return Err(PasswordMetadataRejected("Invalid NZB password metadata structure.").into());
    }
    let mut passwords = Vec::new();
    let mut expected = 0;
    let mut verification_mode = None;
    for meta in &document.metadata {
        if meta.kind == "password" {
            if meta.child_elements > 0 {
                return Err(
                    PasswordMetadataRejected("Invalid NZB password metadata structure.").into(),
                );
            }
            if meta.text.len() > 4096 || passwords.len() >= 64 {
                return Err(PasswordMetadataRejected(
                    "NZB password metadata exceeds the supported limit.",
                )
                .into());
            }
            passwords.push(meta.text.clone());
        }
        if meta.kind == "nntmux:verification-mode" && meta.text == "spot-check" {
            verification_mode = Some("spot_check".to_owned());
        }
        if matches!(
            meta.kind.as_str(),
            "nntmux:verified-file-count" | "nntmux:declared-file-count"
        ) && COUNT.is_match(&meta.text)
        {
            expected = expected.max(php_int(&meta.text));
        }
    }
    let mut conflict = false;
    let mut groups: Vec<String> = Vec::new();
    let mut files = Vec::with_capacity(document.files.len());
    for file in &document.files {
        expected = expected.max(declared_file_count(&file.subject));
        let declared = PART_COUNTER
            .captures(&file.subject)
            .map(|captures| captures[1].parse::<i64>().unwrap_or(i64::MAX));
        let mut observed = 0;
        let mut numbers = HashSet::new();
        let mut segments = Vec::with_capacity(file.segments.len());
        for segment in &file.segments {
            let number = php_int(&segment.number);
            let messageid = php_trim(&segment.text).to_owned();
            if number < 1 || !numbers.insert(number) {
                conflict = true;
            }
            if number > 0 && !messageid.is_empty() {
                observed = observed.max(number);
            }
            segments.push(Segment {
                number,
                messageid,
                bytes: php_int(&segment.bytes),
            });
        }
        for group in &file.groups {
            if !groups.contains(group) {
                groups.push(group.clone());
            }
        }
        files.push(ManifestFile {
            key: hex::encode(Sha256::digest(&file.subject)),
            subject: TRAILING_COUNTER.replace(&file.subject, "").into_owned(),
            totalparts: declared.unwrap_or(observed),
            totalparts_inferred: declared.is_none(),
            segments,
        });
    }
    if files.is_empty() {
        bail!("NZB contains no files.");
    }
    Ok(Manifest {
        subject: facts.searchname.clone(),
        fromname: facts.fromname.clone(),
        date: facts.postdate.clone(),
        groups_id: facts.groups_id,
        expected_files: expected,
        groups,
        files,
        conflict,
        metadata_source: Some(if facts.import { "import" } else { "audit" }.to_owned()),
        passwords: (!passwords.is_empty()).then_some(passwords),
        verification_mode,
        categories_id: facts.categories_id.filter(|id| facts.import && *id > 0),
        import_name_override: facts.import && facts.import_name_override,
        original_subject: facts.original_subject.clone().filter(|_| facts.import),
        ..Manifest::default()
    })
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
