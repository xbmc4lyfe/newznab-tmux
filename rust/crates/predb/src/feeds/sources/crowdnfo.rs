//! Opt-in, latest-only crowdNFO canonical catalogue headings, including contributed names. File
//! trust scores don't verify names, and creation timestamps are not PRE times. No aliases, payload
//! metadata or original spelling are inferred from the listing.

use std::collections::HashSet;
use std::sync::LazyLock;

use async_trait::async_trait;
use regex::Regex;
use scraper::ElementRef;

use super::super::http::FeedHttp;
use super::super::{Entry, FeedResult, FeedSource};
use super::dom::{self, Unclosed};
use super::literal::{
    Discard, as_libxml_reads, char_length, contains_ellipsis, fail, has_control_bytes,
    has_noncharacter_pair, has_unsafe_numeric_reference, is_nonliteral, php_trim,
};

pub const MAXIMUM_BODY_BYTES: usize = 1024 * 1024;

fn tag_pattern() -> &'static regex::bytes::Regex {
    static PATTERN: LazyLock<regex::bytes::Regex> = LazyLock::new(|| {
        regex::bytes::Regex::new(
            r#"(?is-u)<(/?)(html|body|div|h2|h5|a)\b(?:"[^"]*"|'[^']*'|[^'">])*>"#,
        )
        .expect("pattern")
    });
    &PATTERN
}

/// Parses the "Recent Releases" cards of the home page. Every card is validated before
/// `page_size` limits the output.
pub fn parse_html(
    html: &[u8],
    page_size: usize,
    maximum_body_bytes: usize,
) -> FeedResult<Vec<Entry>> {
    static TITLE: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(r"^[^.]+\.[^.]+(?:\.[^.]+)*-[\p{L}\p{N}_]+$").expect("pattern")
    });
    static DETAIL: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"^/release/([1-9][0-9]*)$").expect("pattern"));
    if html.len() > maximum_body_bytes {
        return fail("CROWDNFO response exceeds the body limit.");
    }
    let text = match std::str::from_utf8(html) {
        Ok(text)
            if !text.is_empty() && !has_control_bytes(html) && !has_noncharacter_pair(text) =>
        {
            text
        }
        _ => return fail("CROWDNFO returned invalid HTML."),
    };
    if has_unsafe_numeric_reference(text, true) {
        return fail("CROWDNFO returned an invalid character reference.");
    }
    if dom::exceeds_lexical_depth(text, dom::MAXIMUM_LEXICAL_DEPTH) {
        return fail("CROWDNFO returned invalid HTML.");
    }
    match dom::closed_listing_structure(text, tag_pattern()) {
        Err(Unclosed::Document) => {
            return fail("CROWDNFO returned an incomplete listing document.");
        }
        Err(Unclosed::Scope) => return fail("CROWDNFO returned an incomplete listing scope."),
        Ok(()) => {}
    }
    let document = dom::parse(&as_libxml_reads(text, Discard::None));
    if dom::has_duplicate_attribute(&document) {
        return fail("CROWDNFO returned duplicate HTML attributes.");
    }

    let headings: Vec<ElementRef<'_>> = dom::all_elements(&document)
        .into_iter()
        .filter(|element| {
            dom::is_tag(*element, "h2")
                && dom::normalize_space(&dom::text_content(*element)) == "Recent Releases"
        })
        .collect();
    let [heading] = headings.as_slice() else {
        return fail("CROWDNFO returned an ambiguous recent release scope.");
    };
    // `following-sibling::*[1][self::div[row]]`.
    let scope = heading
        .next_siblings()
        .find_map(ElementRef::wrap)
        .filter(|next| dom::is_tag(*next, "div") && dom::has_class(*next, "row"));
    let Some(scope) = scope else {
        return fail("CROWDNFO returned no recognized recent release listing.");
    };
    let wrappers = dom::children(scope);
    let inside = dom::descendants(scope);
    let titles = inside
        .iter()
        .filter(|element| dom::has_class(**element, "card-title"))
        .count();
    let links = inside
        .iter()
        .filter(|element| {
            dom::is_tag(**element, "a")
                && element
                    .attr("href")
                    .is_some_and(|href| href.starts_with("/release/"))
        })
        .count();
    if wrappers.is_empty() || titles != wrappers.len() || links != wrappers.len() {
        return fail("CROWDNFO returned incomplete or ambiguous release cards.");
    }

    let mut entries = Vec::new();
    let mut identities = HashSet::new();
    let mut seen_titles = HashSet::new();
    for wrapper in wrappers {
        let cards: Vec<ElementRef<'_>> = dom::children_named(wrapper, "div")
            .into_iter()
            .filter(|div| dom::has_class(*div, "card"))
            .collect();
        let mut links = Vec::new();
        for card in &cards {
            for body in dom::children_named(*card, "div") {
                if !dom::has_class(body, "card-body") {
                    continue;
                }
                for heading in dom::children_named(body, "h5") {
                    if dom::has_class(heading, "card-title") {
                        links.extend(dom::children_named(heading, "a"));
                    }
                }
            }
        }
        let ([_], [link]) = (cards.as_slice(), links.as_slice()) else {
            return fail("CROWDNFO returned an ambiguous release card heading.");
        };
        let identity = if dom::has_element_descendants(*link) {
            None
        } else {
            DETAIL
                .captures(link.attr("href").unwrap_or(""))
                .map(|captured| captured[1].to_owned())
        };
        let Some(identity) = identity else {
            return fail("CROWDNFO returned an invalid release detail link.");
        };
        let title = php_trim(&dom::text_content(*link)).to_owned();
        let heading = dom::parent_element(*link);
        let clean_heading = heading.is_some_and(|heading| {
            dom::children(heading).len() == 1 && php_trim(&dom::text_content(heading)) == title
        });
        if !clean_heading {
            return fail("CROWDNFO returned additional heading content.");
        }
        if title.is_empty()
            || char_length(&title) > 255
            || is_nonliteral(&title)
            || contains_ellipsis(&title)
            || !TITLE.is_match(&title)
            || !identities.insert(identity)
            || !seen_titles.insert(title.clone())
        {
            return fail("CROWDNFO returned a malformed or duplicate literal heading.");
        }
        if entries.len() < page_size {
            entries.push(Entry {
                title,
                source: "crowdnfo.net".to_owned(),
                ..Entry::default()
            });
        }
    }
    Ok(entries)
}

pub struct CrowdnfoSource {
    endpoint: String,
    page_size: usize,
    http: FeedHttp,
}

impl CrowdnfoSource {
    pub fn new(endpoint: String, page_size: usize, http: FeedHttp) -> Self {
        Self {
            endpoint,
            page_size: page_size.max(1),
            http,
        }
    }
}

#[async_trait]
impl FeedSource for CrowdnfoSource {
    fn key(&self) -> &str {
        "crowdnfo"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        if page < 1 {
            return fail("CROWDNFO pages start at one.");
        }
        if page > 1 {
            return Ok(Vec::new());
        }
        let body = self
            .http
            .download_body(&self.endpoint, "crowdnfo", MAXIMUM_BODY_BYTES, 2)
            .await?;
        parse_html(&body, self.page_size, MAXIMUM_BODY_BYTES)
    }
}
