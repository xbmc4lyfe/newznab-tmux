//! Overview lines, and the subject parsing that decides whether an article is a yEnc part.
//!
//! Usenet headers aren't reliably UTF-8, so fields stay bytes until they reach the database.

use std::collections::{HashMap, HashSet};
use std::sync::LazyLock;

use chrono::{DateTime, NaiveDateTime};
use regex::bytes::Regex;

/// `name (12/45)`. The leading lookahead for `Usenet Index Post` is in [`match_subject`].
static SUBJECT: LazyLock<Regex> = LazyLock::new(|| compile(r"^\s*(.+?)\s+\(([0-9]+)/([0-9]+)\)"));
static SUBJECT_NO_SPACE: LazyLock<Regex> =
    LazyLock::new(|| compile(r"^(.+?)\s+\(([0-9]+)/([0-9]+)\)"));
/// A file counter inside a base name, such as `[03/62]` or `(3 of 62)`.
static FILE_COUNTER: LazyLock<Regex> =
    LazyLock::new(|| compile(r"(?i)[\[(\s]([0-9]{1,5})(/|[\s_]of[\s_]|-)([0-9]{1,5})[\])\[\s$:]"));
static TOKEN: LazyLock<Regex> = LazyLock::new(|| compile(r"[A-Za-z0-9]{16,}"));
static DIGITS: LazyLock<Regex> = LazyLock::new(|| compile(r"[0-9]+"));
static ALNUM: LazyLock<Regex> = LazyLock::new(|| compile(r"[A-Za-z0-9]+"));
static XREF_WITH_NUMBER: LazyLock<Regex> =
    LazyLock::new(|| compile(r"^[a-zA-Z]{2,3}\.(bin(aries|arios|aer))\.[a-zA-Z0-9]?.+(:[0-9]+)"));
static XREF_NO_NUMBER: LazyLock<Regex> =
    LazyLock::new(|| compile(r"^[a-zA-Z]{2,3}\.(bin(aries|arios|aer))\.[a-zA-Z0-9]?.+"));
static XREF_NUMBER_SUFFIX: LazyLock<Regex> = LazyLock::new(|| compile(r":[0-9]+$"));

fn compile(pattern: &str) -> Regex {
    Regex::new(&format!("(?-u){pattern}")).expect("a valid built-in regex")
}

/// One overview line.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Overview {
    pub number: u64,
    pub subject: Vec<u8>,
    pub from: Vec<u8>,
    pub date: Vec<u8>,
    pub message_id: Vec<u8>,
    pub bytes: i64,
    pub xref: Vec<u8>,
}

/// `(int)` on a string: leading whitespace and digits, saturating, zero when there are none.
pub fn php_int(value: &[u8]) -> i64 {
    let text = value.trim_ascii_start();
    let (negative, digits) = match text.first() {
        Some(b'-') => (true, &text[1..]),
        Some(b'+') => (false, &text[1..]),
        _ => (false, text),
    };
    let mut number: i64 = 0;
    for &byte in digits.iter().take_while(|byte| byte.is_ascii_digit()) {
        number = number
            .saturating_mul(10)
            .saturating_add(i64::from(byte - b'0'));
    }
    if negative { -number } else { number }
}

/// The parts of a subject that matched `name (part/total)`.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct SubjectMatch {
    pub base: Vec<u8>,
    pub part: i64,
    pub total: i64,
    /// The total as the subject wrote it. Obfuscation grouping keeps `045` and `45` apart.
    pub total_text: Vec<u8>,
}

/// `/^\s*(?!Usenet Index Post)(.+?)\s+\((\d+)\/(\d+)\)/`
pub fn match_subject(subject: &[u8]) -> Option<SubjectMatch> {
    let spaces = subject
        .iter()
        .take_while(|byte| matches!(byte, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r'))
        .count();
    let captures = if subject[spaces..].starts_with(b"Usenet Index Post") {
        // The `\s*` backs off, so the lookahead sees whitespace and passes. With no whitespace
        // to give back there's nothing to match.
        (0..spaces)
            .rev()
            .find_map(|start| SUBJECT_NO_SPACE.captures(&subject[start..]))?
    } else {
        SUBJECT.captures(subject)?
    };
    Some(SubjectMatch {
        base: captures[1].to_vec(),
        part: php_int(&captures[2]),
        total: php_int(&captures[3]),
        total_text: captures[3].to_vec(),
    })
}

/// The file number and file total inside a base name, zero when there's no counter.
pub fn file_counter(base: &[u8]) -> (i64, i64) {
    FILE_COUNTER.captures(base).map_or((0, 0), |captures| {
        (php_int(&captures[1]), php_int(&captures[3]))
    })
}

/// `XrefService::extractTokens`: tokens that name a binaries group, optionally with a number.
pub fn xref_tokens(xref: &[u8]) -> Vec<Vec<u8>> {
    xref.split(|&byte| byte == b' ')
        .filter_map(|token| {
            XREF_WITH_NUMBER
                .find(token)
                .or_else(|| XREF_NO_NUMBER.find(token))
                .map(|found| found.as_bytes().to_vec())
        })
        .collect()
}

/// `XrefService::extractGroupNames`: unique group names, in first-seen order.
pub fn xref_groups(xref: &[u8]) -> Vec<Vec<u8>> {
    let mut seen = HashSet::new();
    xref_tokens(xref)
        .into_iter()
        .filter_map(|token| {
            let group = XREF_NUMBER_SUFFIX.replace(&token, &b""[..]).into_owned();
            (!group.is_empty() && seen.insert(group.clone())).then_some(group)
        })
        .collect()
}

/// Article numbers whose subject is randomised per article across a downloaded range.
///
/// Some uploaders (nyuu's article obfuscation) give every article its own random subject token,
/// so no two parts share a binary, and each becomes a one-part collection that can never
/// complete. Multi-part articles whose base name holds a 16+ alphanumeric token are grouped by
/// subject shape, part total and file counter. A group of at least `min_articles` with at least
/// 90% distinct names has its once-seen names, other than first and last parts, marked when they
/// span at least `min_articles` part positions. A real upload repeats its name on every part,
/// and a first or last part can be all a range boundary left of one, so those stay.
///
/// Pass 0 to disable, as a sparse part repair does.
pub fn mark_per_article_obfuscated(headers: &[Overview], min_articles: usize) -> HashSet<u64> {
    let mut marked = HashSet::new();
    if min_articles < 1 {
        return marked;
    }

    #[derive(Default)]
    struct Signature {
        names: HashMap<Vec<u8>, Vec<(u64, i64)>>,
        articles: usize,
        total: i64,
    }
    let mut signatures: HashMap<Vec<u8>, Signature> = HashMap::new();
    for header in headers {
        let Some(subject) = match_subject(&header.subject) else {
            continue;
        };
        if subject.total < 2 || !TOKEN.is_match(&subject.base) {
            continue;
        }
        let name = comparable_name(&subject.base);
        let counter = FILE_COUNTER
            .captures(&subject.base)
            .map(|captures| [&captures[1], b"/", &captures[3]].concat())
            .unwrap_or_default();
        let shape = ALNUM.replace_all(&subject.base, &b"x"[..]);
        let key = [&shape[..], b"\0", &subject.total_text, b"\0", &counter].concat();
        let signature = signatures.entry(key).or_default();
        signature.total = subject.total;
        signature
            .names
            .entry(name)
            .or_default()
            .push((header.number, subject.part));
        signature.articles += 1;
    }

    for signature in signatures.values() {
        let articles = signature.articles;
        if articles < min_articles || signature.names.len() * 10 < articles * 9 {
            continue;
        }
        let mut candidates: HashMap<u64, i64> = HashMap::new();
        for found in signature.names.values() {
            let (number, part) = found[0];
            if found.len() == 1 && part != 1 && part != signature.total {
                candidates.insert(number, part);
            }
        }
        // A slice of files posted round-robin, each with its own random name, shows one article
        // per file at only one or two positions.
        let positions: HashSet<i64> = candidates.values().copied().collect();
        if positions.len() >= min_articles {
            marked.extend(candidates.keys());
        }
    }
    marked
}

/// Digit runs outside the long tokens are dropped, so numbered volumes of one upload
/// (`<hash>.part07.rar`) share a name while random tokens stay whole.
fn comparable_name(base: &[u8]) -> Vec<u8> {
    let mut name = Vec::with_capacity(base.len());
    let mut last = 0;
    for token in TOKEN.find_iter(base) {
        name.extend_from_slice(&DIGITS.replace_all(&base[last..token.start()], &b""[..]));
        name.extend_from_slice(token.as_bytes());
        last = token.end();
    }
    name.extend_from_slice(&DIGITS.replace_all(&base[last..], &b""[..]));
    name
}

/// The lowest and highest articles inside the requested range.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ArticleRange {
    pub first_number: u64,
    pub first_date: Vec<u8>,
    pub last_number: u64,
    pub last_date: Vec<u8>,
}

/// Only numbers inside the requested range count: a malformed overview line can carry a bogus
/// number, and saving it as `last_record` makes the group re-scan from article 0.
pub fn article_range(headers: &[Overview], first: u64, last: u64) -> Option<ArticleRange> {
    let mut low: Option<&Overview> = None;
    let mut high: Option<&Overview> = None;
    for header in headers {
        if header.number < first || header.number > last {
            continue;
        }
        if low.is_none_or(|current| header.number < current.number) {
            low = Some(header);
        }
        if high.is_none_or(|current| header.number > current.number) {
            high = Some(header);
        }
    }
    Some(ArticleRange {
        first_number: low?.number,
        first_date: low?.date.clone(),
        last_number: high?.number,
        last_date: high?.date.clone(),
    })
}

/// Why a header can't become a part, or None if it can.
pub fn validation_error(part: i64, message_id: &[u8]) -> Option<&'static str> {
    if part <= 0 {
        return Some("Invalid part number");
    }
    let message_id = php_trim(message_id);
    if message_id.is_empty()
        || message_id.len() > 255
        || !message_id.iter().all(|byte| (0x20..=0x7E).contains(byte))
    {
        return Some("Invalid message ID");
    }
    None
}

/// PHP `trim`: space, tab, newline, carriage return, NUL and vertical tab.
pub fn php_trim(value: &[u8]) -> &[u8] {
    let strip = |byte: &u8| matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B);
    let start = value
        .iter()
        .position(|byte| !strip(byte))
        .unwrap_or(value.len());
    let end = value
        .iter()
        .rposition(|byte| !strip(byte))
        .map_or(start, |at| at + 1);
    &value[start..end]
}

/// The Unix time of an overview date: an all-digit value is the time itself, as `is_numeric`
/// made it, and otherwise the mail date formats `strtotime` read. None if it can't be read.
pub fn header_time(date: &[u8]) -> Option<i64> {
    let text = std::str::from_utf8(date).ok()?.trim();
    if !text.is_empty() && text.bytes().all(|byte| byte.is_ascii_digit()) {
        return text.parse().ok();
    }
    parse_date(text)
}

/// `strtotime` for the date shapes news servers send. Times without a zone are UTC, which is the
/// application timezone.
pub fn parse_date(text: &str) -> Option<i64> {
    let text = text.trim();
    if text.is_empty() {
        return None;
    }
    // strtotime doesn't know the obsolete `UT` zone that RFC 2822 allows.
    if !text.ends_with(" UT")
        && let Ok(parsed) = DateTime::parse_from_rfc2822(text)
    {
        return Some(parsed.timestamp());
    }
    for format in ["%d %B %Y %I:%M:%S %p GMT", "%d %b %Y %H:%M GMT"] {
        if let Ok(parsed) = NaiveDateTime::parse_from_str(text, format) {
            return Some(parsed.and_utc().timestamp());
        }
    }
    for format in [
        "%d %b %Y %H:%M %z",
        "%a, %d %b %Y %H:%M %z",
        "%d %b %Y %H:%M:%S %z",
        "%a, %d %b %Y %H:%M:%S %z",
    ] {
        if let Ok(parsed) = DateTime::parse_from_str(text, format) {
            return Some(parsed.timestamp());
        }
    }
    for format in ["%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S"] {
        if let Ok(parsed) = NaiveDateTime::parse_from_str(text, format) {
            return Some(parsed.and_utc().timestamp());
        }
    }
    DateTime::parse_from_rfc3339(text)
        .ok()
        .map(|parsed| parsed.timestamp())
}
