//! Latest public music catalogue headings. Forum timestamps and track counts are not PRE
//! metadata. The numeric topic link and publisher author are structural evidence, not verified
//! original PREs.

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
const PINNED_DISCUSSION: &str = "https://seriousaboutsound.net/topic/1374-renegades-corner/";
const PUBLISHER_PROFILE: &str = "https://seriousaboutsound.net/profile/1294-rls-bot/";

fn tag_pattern() -> &'static regex::bytes::Regex {
    static PATTERN: LazyLock<regex::bytes::Regex> = LazyLock::new(|| {
        regex::bytes::Regex::new(
            r#"(?is-u)<(/?)(html|body|ol|ul|li|div|h4|a|span)\b(?:"[^"]*"|'[^']*'|[^'">])*>"#,
        )
        .expect("pattern")
    });
    &PATTERN
}

fn with_class<'a>(elements: Vec<ElementRef<'a>>, class: &str) -> Vec<ElementRef<'a>> {
    elements
        .into_iter()
        .filter(|element| dom::has_class(*element, class))
        .collect()
}

pub fn parse_html(
    html: &[u8],
    page_size: usize,
    maximum_body_bytes: usize,
) -> FeedResult<Vec<Entry>> {
    static TITLE: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(r"^[\p{L}\p{N}_][\p{L}\p{M}\p{N}_.()&'+,-]*-[\p{L}\p{N}_]+$").expect("pattern")
    });
    static ROW_ID: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"^[1-9][0-9]*$").expect("pattern"));
    static TOPIC: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(r"^https://seriousaboutsound\.net/topic/([1-9][0-9]*)-[a-z0-9_-]+/$")
            .expect("pattern")
    });
    if html.len() > maximum_body_bytes {
        return fail("SERIOUSABOUTSOUND response exceeds the body limit.");
    }
    let text = match std::str::from_utf8(html) {
        Ok(text)
            if !text.is_empty() && !has_control_bytes(html) && !has_noncharacter_pair(text) =>
        {
            text
        }
        _ => return fail("SERIOUSABOUTSOUND returned invalid HTML."),
    };
    if has_unsafe_numeric_reference(text, true) {
        return fail("SERIOUSABOUTSOUND returned an invalid character reference.");
    }
    if dom::exceeds_lexical_depth(text, dom::MAXIMUM_LEXICAL_DEPTH) {
        return fail("SERIOUSABOUTSOUND returned invalid HTML.");
    }
    match dom::closed_listing_structure(text, tag_pattern()) {
        Err(Unclosed::Document) => {
            return fail("SERIOUSABOUTSOUND returned an incomplete listing document.");
        }
        Err(Unclosed::Scope) => {
            return fail("SERIOUSABOUTSOUND returned an incomplete listing scope.");
        }
        Ok(()) => {}
    }
    let document = dom::parse(&as_libxml_reads(text, Discard::None));
    if dom::has_duplicate_attribute(&document) {
        return fail("SERIOUSABOUTSOUND returned duplicate HTML attributes.");
    }

    let scopes: Vec<ElementRef<'_>> = dom::all_elements(&document)
        .into_iter()
        .filter(|element| {
            dom::is_tag(*element, "ol")
                && element.attr("data-role") == Some("tableRows")
                && dom::has_class(*element, "cForumTopicTable")
        })
        .collect();
    let [scope] = scopes.as_slice() else {
        return fail("SERIOUSABOUTSOUND returned an ambiguous topic scope.");
    };
    let rows = dom::children(*scope);
    let all_rows = dom::descendants(*scope)
        .into_iter()
        .filter(|element| element.attr("data-rowid").is_some())
        .count();
    if rows.is_empty() || rows.len() != all_rows {
        return fail("SERIOUSABOUTSOUND returned an incomplete topic listing.");
    }

    let mut entries = Vec::new();
    let mut ids = HashSet::new();
    let mut titles = HashSet::new();
    for row in rows {
        let id = row.attr("data-rowid").unwrap_or("");
        if !dom::is_tag(row, "li") || !ROW_ID.is_match(id) {
            return fail("SERIOUSABOUTSOUND returned an invalid topic row.");
        }
        if !ids.insert(id.to_owned()) {
            return fail("SERIOUSABOUTSOUND returned duplicate topic identities.");
        }
        let inside = dom::descendants(row);
        let pinned = inside
            .iter()
            .filter(|element| {
                element.attr("title") == Some("Pinned") && dom::has_class(**element, "ipsBadge")
            })
            .count();
        if pinned > 0 {
            let discussion = inside
                .iter()
                .filter(|element| {
                    dom::is_tag(**element, "a") && element.attr("href") == Some(PINNED_DISCUSSION)
                })
                .count();
            if id != "1374" || pinned != 1 || discussion != 1 {
                return fail("SERIOUSABOUTSOUND returned an unknown pinned topic.");
            }
            continue;
        }

        let mains = with_class(dom::children_named(row, "div"), "ipsDataItem_main");
        let headings: Vec<ElementRef<'_>> = mains
            .iter()
            .flat_map(|main| with_class(dom::children_named(*main, "h4"), "ipsDataItem_title"))
            .collect();
        let [heading] = headings.as_slice() else {
            return fail("SERIOUSABOUTSOUND returned an ambiguous topic heading.");
        };
        if dom::children(*heading).len() != 1 {
            return fail("SERIOUSABOUTSOUND returned additional heading structure.");
        }
        let canonical: usize = with_class(dom::children_named(*heading, "span"), "ipsType_break")
            .into_iter()
            .map(|span| dom::children_named(span, "a").len())
            .sum();
        if canonical != 1 {
            return fail("SERIOUSABOUTSOUND returned no canonical heading link.");
        }
        let links: Vec<ElementRef<'_>> = dom::descendants(*heading)
            .into_iter()
            .filter(|element| dom::is_tag(*element, "a"))
            .collect();
        let authors: Vec<ElementRef<'_>> = mains
            .iter()
            .flat_map(|main| with_class(dom::children_named(*main, "div"), "ipsDataItem_meta"))
            .flat_map(dom::descendants)
            .filter(|element| {
                dom::is_tag(*element, "a")
                    && element
                        .attr("href")
                        .is_some_and(|href| href.contains("/profile/"))
            })
            .collect();
        let ([link], [author]) = (links.as_slice(), authors.as_slice()) else {
            return fail("SERIOUSABOUTSOUND returned ambiguous topic links or author evidence.");
        };
        let topic = TOPIC.captures(link.attr("href").unwrap_or(""));
        let associated = author.attr("href") == Some(PUBLISHER_PROFILE)
            && php_trim(&dom::text_content(*author)) == "RLS Bot"
            && topic.is_some_and(|topic| &topic[1] == id);
        if !associated {
            return fail("SERIOUSABOUTSOUND returned an invalid topic association or publisher.");
        }
        let spans = dom::children_named(*link, "span");
        let link_text = dom::text_content(*link);
        let editorial = match spans.as_slice() {
            [span] => {
                dom::children(*link).len() != 1
                    || dom::has_element_descendants(*span)
                    || php_trim(&dom::text_content(*span)) != php_trim(&link_text)
            }
            _ => true,
        };
        if editorial {
            return fail("SERIOUSABOUTSOUND returned editorial heading markup.");
        }
        let title = php_trim(&link_text).to_owned();
        if php_trim(&dom::text_content(*heading)) != title
            || char_length(&title) > 255
            || is_nonliteral(&title)
            || contains_ellipsis(&title)
            || !TITLE.is_match(&title)
            || !title.contains(['.', '_'])
            || !titles.insert(title.to_lowercase())
        {
            return fail("SERIOUSABOUTSOUND returned a malformed or duplicate literal heading.");
        }
        if entries.len() < page_size {
            entries.push(Entry {
                title,
                source: "seriousaboutsound.net".to_owned(),
                ..Entry::default()
            });
        }
    }
    if entries.is_empty() {
        return fail("SERIOUSABOUTSOUND returned no literal release headings.");
    }
    Ok(entries)
}

pub struct SeriousAboutSoundSource {
    endpoint: String,
    page_size: usize,
    http: FeedHttp,
}

impl SeriousAboutSoundSource {
    pub fn new(endpoint: String, page_size: usize, http: FeedHttp) -> Self {
        Self {
            endpoint,
            page_size: page_size.max(1),
            http,
        }
    }
}

#[async_trait]
impl FeedSource for SeriousAboutSoundSource {
    fn key(&self) -> &str {
        "seriousaboutsound"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        if page < 1 {
            return fail("SERIOUSABOUTSOUND pages start at one.");
        }
        if page > 1 {
            return Ok(Vec::new());
        }
        let body = self
            .http
            .download_body(&self.endpoint, "seriousaboutsound", MAXIMUM_BODY_BYTES, 2)
            .await?;
        parse_html(&body, self.page_size, MAXIMUM_BODY_BYTES)
    }
}
