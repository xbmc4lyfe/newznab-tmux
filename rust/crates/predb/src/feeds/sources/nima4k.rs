//! Opt-in, latest-only NIMA4K catalogue names. The 1 MiB default bounds the HTML download and DOM
//! parsing. Listing dates have no timezone and delivery metadata is not PRE metadata, so both stay
//! unknown.
//!
//! The PHP read each page twice, with libxml and with PHP 8.4's HTML5 parser, and refused any page
//! the two read differently. This reads it once, with the HTML5 parser, and keeps every check that
//! doesn't depend on the legacy parser's own diagnostics. See `tests/catalogues.rs` for the inputs
//! where the two differ.

use std::sync::LazyLock;

use async_trait::async_trait;
use regex::Regex;
use scraper::{ElementRef, Html, Node};

use super::super::http::FeedHttp;
use super::super::{Entry, FeedError, FeedResult, FeedSource};
use super::dom;
use super::literal::{Discard, as_libxml_reads, fail, is_nonliteral, php_trim};

pub const MAXIMUM_BODY_BYTES: usize = 1024 * 1024;

/// `\x{0000}-\x{0008}`, `\x{000B}`, `\x{000C}`, `\x{000E}-\x{001F}`, `\x{007F}-\x{009F}`, the
/// `FDD0` to `FDEF` block and the last two code points of every plane.
fn is_forbidden(c: char) -> bool {
    let code = c as u32;
    matches!(code, 0..=8 | 0x0b | 0x0c | 0x0e..=0x1f | 0x7f..=0x9f | 0xfdd0..=0xfdef)
        || code & 0xfffe == 0xfffe
}

fn invalid_html<T>() -> FeedResult<T> {
    fail("NIMA4K returned invalid HTML.")
}

/// Text under raw-text parents isn't decoded, so it's outside the decoded-value check. Neither
/// is RCDATA (`title` and `textarea`) here: the PHP masked those out before it compared decoded
/// values, so a bad reference in one is refused as a reference, below, rather than as a value.
const RAW_TEXT_PARENTS: [&str; 9] = [
    "script",
    "style",
    "iframe",
    "xmp",
    "noembed",
    "noframes",
    "plaintext",
    "title",
    "textarea",
];

/// Every decoded text and attribute value must be free of forbidden characters. A reference can
/// produce one that was never in the source.
fn assert_decoded_values(document: &Html) -> FeedResult<()> {
    for element in dom::all_elements(document) {
        if element
            .value()
            .attrs()
            .any(|(_, value)| value.chars().any(is_forbidden))
        {
            return invalid_html();
        }
        if RAW_TEXT_PARENTS.contains(&dom::tag(element)) {
            continue;
        }
        for child in dom::child_nodes(element) {
            if let Node::Text(text) = child.value()
                && text.chars().any(is_forbidden)
            {
                return invalid_html();
            }
        }
    }
    Ok(())
}

/// The UTF-8 labels of the WHATWG Encoding Standard; prefixes are not aliases.
const UTF8_LABELS: [&str; 6] = [
    "unicode-1-1-utf-8",
    "unicode11utf8",
    "unicode20utf8",
    "utf-8",
    "utf8",
    "x-unicode20utf8",
];

fn is_html_space(c: char) -> bool {
    matches!(c, ' ' | '\t' | '\r' | '\n' | '\x0c')
}

fn conflicting<T>() -> FeedResult<T> {
    fail("NIMA4K returned a conflicting HTML encoding.")
}

fn unquote_encoding(encoding: &str) -> FeedResult<String> {
    let encoding = encoding.trim_matches(is_html_space);
    let Some(first) = encoding.chars().next().filter(|c| matches!(c, '"' | '\'')) else {
        return Ok(encoding.to_owned());
    };
    if encoding.len() < 2 || !encoding.ends_with(first) {
        return conflicting();
    }
    Ok(encoding[1..encoding.len() - 1]
        .trim_matches(is_html_space)
        .to_owned())
}

/// The `charset=` parameters of a `Content-Type`-style `content` value, in order.
///
/// Mirrors `(?:^|;)\s*charset\s*=\s*("[^"]*"|'[^']*'|[^;]*)(?=\s*(?:;|$))` with its
/// backtracking: a quoted value only counts when `;` or the end follows it.
fn charset_parameters(content: &str) -> Vec<&str> {
    let lower = content.to_ascii_lowercase();
    let bytes = content.as_bytes();
    let skip_space = |mut at: usize| {
        while at < bytes.len() && is_html_space(bytes[at] as char) {
            at += 1;
        }
        at
    };
    let ends_here = |at: usize| {
        let after = skip_space(at);
        after == bytes.len() || bytes[after] == b';'
    };
    let mut found = Vec::new();
    let mut search = 0;
    while search <= bytes.len() {
        let mut matched = None;
        // Candidate starts are the beginning of the text and every `;`.
        for start in search..=bytes.len() {
            let openings: &[usize] = match (start == 0, bytes.get(start) == Some(&b';')) {
                (true, true) => &[0, 1],
                (true, false) => &[0],
                (false, true) => &[1],
                (false, false) => &[],
            };
            for opening in openings {
                let mut at = skip_space(start + opening);
                if !lower[at..].starts_with("charset") {
                    continue;
                }
                at = skip_space(at + "charset".len());
                if bytes.get(at) != Some(&b'=') {
                    continue;
                }
                at = skip_space(at + 1);
                let quoted = match bytes.get(at) {
                    Some(&quote @ (b'"' | b'\'')) => bytes[at + 1..]
                        .iter()
                        .position(|byte| *byte == quote)
                        .map(|length| at + 1 + length + 1)
                        .filter(|end| ends_here(*end)),
                    _ => None,
                };
                let end = quoted.unwrap_or_else(|| {
                    at + bytes[at..]
                        .iter()
                        .position(|byte| *byte == b';')
                        .unwrap_or(bytes.len() - at)
                });
                matched = Some((at, end));
                break;
            }
            if matched.is_some() {
                break;
            }
        }
        let Some((at, end)) = matched else {
            break;
        };
        found.push(&content[at..end]);
        search = end.max(search + 1);
    }
    found
}

/// What libxml keeps of a `content` value: everything after the first `charset=` or `charset =`
/// (any case), less the blanks that follow it.
fn after_charset_marker(content: &str) -> Option<&str> {
    let lower = content.to_ascii_lowercase();
    let (index, length) = lower
        .find("charset=")
        .map(|index| (index, "charset=".len()))
        .or_else(|| {
            lower
                .find("charset =")
                .map(|index| (index, "charset =".len()))
        })?;
    Some(content[index + length..].trim_start_matches([' ', '\t', '\r', '\n', '\x0c']))
}

/// A `<meta>` declaration can override the UTF-8 the page was read as and corrupt both labels
/// alike, so any declaration of another encoding is refused.
fn assert_utf8_encoding(document: &Html) -> FeedResult<()> {
    let mut encodings = Vec::new();
    let mut legacy_reading = None;
    for meta in dom::all_elements(document) {
        if !dom::is_tag(meta, "meta") {
            continue;
        }
        if let Some(charset) = meta.attr("charset") {
            encodings.push(charset.to_owned());
            legacy_reading.get_or_insert_with(|| charset.to_owned());
        }
        let http_equiv = php_trim(meta.attr("http-equiv").unwrap_or(""));
        if http_equiv.eq_ignore_ascii_case("content-type") {
            let content = meta.attr("content").unwrap_or("");
            for parameter in charset_parameters(content) {
                encodings.push(unquote_encoding(parameter)?);
            }
            if let Some(rest) = after_charset_marker(content) {
                legacy_reading.get_or_insert_with(|| rest.to_owned());
            }
        }
    }
    // libxml takes the first declaration's whole remainder as the document encoding, trailing
    // parameters and quotes included, so `charset=utf-8; x` conflicts there.
    if let Some(reading) = legacy_reading {
        encodings.push(unquote_encoding(&reading)?);
    }
    for encoding in encodings {
        let label = encoding.trim_matches(is_html_space).to_lowercase();
        if !UTF8_LABELS.contains(&label.as_str()) {
            return conflicting();
        }
    }
    Ok(())
}

/// Release cards, validated in full: one literal subtitle and one agreeing cover label each.
fn release_cards(document: &Html) -> FeedResult<Vec<String>> {
    let articles: Vec<ElementRef<'_>> = dom::all_elements(document)
        .into_iter()
        .filter(|element| dom::is_tag(*element, "div") && dom::has_class(*element, "article"))
        .filter(|element| {
            !element.ancestors().any(
                |ancestor| matches!(ancestor.value(), Node::Element(e) if e.name() == "template"),
            )
        })
        .collect();
    if articles.is_empty() {
        return fail("NIMA4K returned no recognized release cards.");
    }
    let mut cards = Vec::new();
    for article in articles {
        let inside = dom::descendants(article);
        let subtitle_class = |element: &ElementRef<'_>| {
            dom::is_tag(*element, "span") && dom::has_class(*element, "subtitle")
        };
        let subtitles = inside.iter().filter(|e| subtitle_class(e)).count();
        let direct: Vec<ElementRef<'_>> = dom::children_named(article, "div")
            .into_iter()
            .filter(|div| dom::has_class(*div, "article-right"))
            .flat_map(|div| dom::children_named(div, "h2"))
            .flat_map(|h2| dom::children_named(h2, "span"))
            .filter(|span| dom::has_class(*span, "subtitle"))
            .collect();
        // `.//div[cover-box]//img[@alt]`: a node set, so an image under two boxes counts once. A
        // second distinct image already makes the card ambiguous.
        let mut covers: Vec<ElementRef<'_>> = Vec::new();
        'boxes: for cover_box in inside
            .iter()
            .filter(|e| dom::is_tag(**e, "div") && dom::has_class(**e, "cover-box"))
        {
            for image in dom::descendants(*cover_box) {
                if dom::is_tag(image, "img")
                    && image.attr("alt").is_some()
                    && !covers.contains(&image)
                {
                    covers.push(image);
                    if covers.len() > 1 {
                        break 'boxes;
                    }
                }
            }
        }
        let ([subtitle], [cover], 1) = (direct.as_slice(), covers.as_slice(), subtitles) else {
            return fail("NIMA4K returned an ambiguous release card.");
        };
        if dom::child_nodes(*subtitle).any(|child| !child.value().is_text()) {
            return fail("NIMA4K returned an invalid release subtitle.");
        }
        let title = php_trim(&dom::text_content(*subtitle)).to_owned();
        if title.is_empty() || is_nonliteral(&title) || cover.attr("alt") != Some(title.as_str()) {
            return fail("NIMA4K returned a nonliteral or inconsistent release name.");
        }
        cards.push(title);
    }
    Ok(cards)
}

/// Rewrites carriage-return references to line feed and form-feed ones to vertical tab, however
/// they are spelled. A reference that goes on with another digit is a different number.
fn mirror_whitespace_references(html: &str) -> String {
    static PATTERN: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"&#(?:0*(?:13|12)|[xX]0*[dDcC])(?:;?)").expect("pattern"));
    let mut out = String::with_capacity(html.len());
    let mut copied = 0;
    for found in PATTERN.find_iter(html) {
        let reference = found.as_str();
        let hex = matches!(reference.as_bytes().get(2), Some(b'x' | b'X'));
        // Only an unterminated reference can go on into more digits.
        let continues = !reference.ends_with(';')
            && html[found.end()..].chars().next().is_some_and(|c| {
                if hex {
                    c.is_ascii_hexdigit()
                } else {
                    c.is_ascii_digit()
                }
            });
        if continues {
            continue;
        }
        let digits = reference
            .trim_start_matches("&#")
            .trim_start_matches(['x', 'X'])
            .trim_end_matches(';')
            .trim_start_matches('0');
        let carriage_return = digits.eq_ignore_ascii_case(if hex { "d" } else { "13" });
        out.push_str(&html[copied..found.start()]);
        out.push_str(if carriage_return { "&#10;" } else { "&#11;" });
        copied = found.end();
    }
    out.push_str(&html[copied..]);
    out
}

/// Whether a numeric reference decodes to NUL, a surrogate, a noncharacter, a value beyond Unicode
/// or a control. Carriage return is the one control allowed, form feed is not: HTML5 can drop
/// form feed as whitespace without warning, so the check reads a copy where it reads as VT.
fn has_invalid_numeric_reference(html: &str) -> bool {
    let diagnostic = mirror_whitespace_references(html);
    dom::parse(&diagnostic)
        .errors
        .iter()
        .any(|error| error.starts_with("Invalid numeric character reference"))
}

/// libxml stops building the tree at 257 nested elements, silently dropping the rest, so a page
/// that deep was refused rather than read short.
const MAXIMUM_DEPTH: usize = 257;

/// Release-card openers in the source itself, outside comments, scripts and styles. Every one of
/// them has to have become a card in the tree: a card the parser hid inside a template or a
/// raw-text element is a different reading of the page.
fn card_openers(html: &str) -> usize {
    static DIV: LazyLock<regex::bytes::Regex> = LazyLock::new(|| {
        regex::bytes::Regex::new(r#"(?is-u)<div\b(?:"[^"]*"|'[^']*'|[^'">])*>"#).expect("pattern")
    });
    static CLASS: LazyLock<regex::bytes::Regex> = LazyLock::new(|| {
        regex::bytes::Regex::new(r#"(?is-u)[\s/]class\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))"#)
            .expect("pattern")
    });
    let markup = dom::strip_inert(html);
    DIV.find_iter(&markup)
        .filter(|tag| {
            CLASS.captures(tag.as_bytes()).is_some_and(|class| {
                let value = (1..=3)
                    .find_map(|group| class.get(group))
                    .map_or(&b""[..], |m| m.as_bytes());
                String::from_utf8_lossy(value)
                    .split([' ', '\t', '\r', '\n'])
                    .any(|token| token == "article")
            })
        })
        .count()
}

/// Parses the home page's release cards. Every card is validated before `page_size` limits the
/// output.
pub fn parse_html(
    html: &[u8],
    page_size: usize,
    maximum_body_bytes: usize,
) -> FeedResult<Vec<Entry>> {
    if html.len() > maximum_body_bytes {
        return fail("NIMA4K HTML exceeds the body limit.");
    }
    let text = match std::str::from_utf8(html) {
        Ok(text) if !text.is_empty() => text,
        _ => return invalid_html(),
    };
    if text.chars().any(is_forbidden)
        || dom::exceeds_lexical_depth(text, dom::MAXIMUM_LEXICAL_DEPTH)
    {
        return invalid_html();
    }
    // The tree is built from the text as libxml would have read the references, so a dropped
    // control can't make a subtitle and its cover disagree before the reference itself is refused.
    let document = dom::parse(&as_libxml_reads(text, Discard::NonXmlCharacters));
    if dom::has_duplicate_attribute(&document) || dom::exceeds_depth(&document, MAXIMUM_DEPTH) {
        return invalid_html();
    }
    assert_utf8_encoding(&document)?;
    assert_decoded_values(&document)?;
    let cards = release_cards(&document)?;
    if has_invalid_numeric_reference(text) {
        return fail("NIMA4K returned an invalid numeric character reference.");
    }
    if card_openers(text) != cards.len() {
        return fail("NIMA4K returned inconsistent HTML release semantics.");
    }
    Ok(cards
        .into_iter()
        .take(page_size)
        .map(|title| Entry {
            title,
            source: "nima4k.org".to_owned(),
            ..Entry::default()
        })
        .collect())
}

pub struct Nima4kSource {
    endpoint: String,
    page_size: usize,
    maximum_body_bytes: usize,
    http: FeedHttp,
}

impl Nima4kSource {
    pub fn new(endpoint: String, page_size: usize, http: FeedHttp) -> Self {
        Self {
            endpoint,
            page_size: page_size.max(1),
            maximum_body_bytes: MAXIMUM_BODY_BYTES,
            http,
        }
    }

    pub fn with_maximum_body_bytes(mut self, maximum_body_bytes: usize) -> Self {
        self.maximum_body_bytes = maximum_body_bytes;
        self
    }
}

#[async_trait]
impl FeedSource for Nima4kSource {
    fn key(&self) -> &str {
        "nima4k"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        if page > 1 {
            return Ok(Vec::new());
        }
        let body = self
            .http
            .download_body(&self.endpoint, "nima4k", self.maximum_body_bytes, 2)
            .await
            .map_err(|error| match error {
                FeedError::Failed(inner)
                    if inner
                        .to_string()
                        .ends_with("response exceeds the body limit.") =>
                {
                    FeedError::Failed(anyhow::anyhow!("NIMA4K HTML exceeds the body limit."))
                }
                other => other,
            })?;
        parse_html(&body, self.page_size, self.maximum_body_bytes)
    }
}
