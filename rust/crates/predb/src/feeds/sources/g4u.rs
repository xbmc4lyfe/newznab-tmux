//! Latest-only public game catalogue. Names come from literal card fields, never editorial
//! titles. The "today" date groups updates; it supplies neither a publication timestamp nor a PRE
//! time.

use std::sync::LazyLock;

use async_trait::async_trait;
use chrono::NaiveDate;
use regex::Regex;
use scraper::ElementRef;

use super::super::http::FeedHttp;
use super::super::{Entry, FeedResult, FeedSource};
use super::dom;
use super::literal::{
    Discard, as_libxml_reads, char_length, fail, has_control_bytes, has_unsafe_numeric_reference,
    is_nonliteral, php_trim,
};

pub const MAXIMUM_BODY_BYTES: usize = 1024 * 1024;

fn single<'a>(found: Vec<ElementRef<'a>>) -> FeedResult<ElementRef<'a>> {
    match found.as_slice() {
        [only] => Ok(*only),
        _ => fail("G4U returned a missing or ambiguous listing field."),
    }
}

/// PHP's `checkdate()`.
pub(super) fn is_calendar_date(year: i32, month: u32, day: u32) -> bool {
    year >= 1 && NaiveDate::from_ymd_opt(year, month, day).is_some()
}

fn today_header(document: &scraper::Html) -> FeedResult<ElementRef<'_>> {
    static DATE: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(r"^Updates from today, ([0-9]{4})-([0-9]{2})-([0-9]{2})$").expect("pattern")
    });
    let matches: Vec<ElementRef<'_>> = dom::all_elements(document)
        .into_iter()
        .filter(|element| {
            dom::is_tag(*element, "h1")
                && php_trim(&dom::text_content(*element)).starts_with("Updates from today,")
        })
        .collect();
    let [heading] = matches.as_slice() else {
        return fail("G4U returned no unique today heading.");
    };
    let text = dom::text_content(*heading);
    let valid = !dom::has_element_descendants(*heading)
        && DATE.captures(php_trim(&text)).is_some_and(|date| {
            let part = |index: usize| date[index].parse::<u32>().unwrap_or(0);
            is_calendar_date(part(1) as i32, part(2), part(3))
        });
    if !valid {
        return fail("G4U returned an invalid today heading.");
    }
    single(
        dom::parent_element(*heading)
            .filter(|parent| dom::is_tag(*parent, "div"))
            .into_iter()
            .collect(),
    )
}

fn card_title(link: ElementRef<'_>) -> FeedResult<String> {
    static HREF: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"^/(?:en|de)/[0-9]+-[^/?#]+$").expect("pattern"));
    if !dom::is_tag(link, "a")
        || !HREF.is_match(link.attr("href").unwrap_or(""))
        || dom::count_descendants_named(link, "ul") != 1
    {
        return fail("G4U returned an unrecognized game card.");
    }
    let card = single(
        dom::children_named(link, "ul")
            .into_iter()
            .filter(|ul| dom::has_class(*ul, "mgame"))
            .collect(),
    )?;
    let title_container = single(
        dom::children_named(card, "li")
            .into_iter()
            .filter(|li| dom::has_class(*li, "mtitel"))
            .collect(),
    )?;
    single(
        dom::descendants(card)
            .into_iter()
            .filter(|li| dom::is_tag(*li, "li") && dom::has_class(*li, "mtitel"))
            .collect(),
    )?;
    let literal = single(
        dom::children_named(title_container, "div")
            .into_iter()
            .filter(|div| dom::has_class(*div, "w3-opacity-min"))
            .collect(),
    )?;
    if dom::has_element_descendants(literal) {
        return fail("G4U returned nested release-name markup.");
    }
    let title = dom::text_content(literal);
    if title.is_empty() || char_length(&title) > 255 || is_nonliteral(&title) {
        return fail("G4U returned a nonliteral release name.");
    }
    Ok(title)
}

/// Parses the home page's "Updates from today" section. The whole section is validated before
/// `page_size` limits the output.
pub fn parse_html(
    html: &[u8],
    page_size: usize,
    maximum_body_bytes: usize,
) -> FeedResult<Vec<Entry>> {
    if html.len() > maximum_body_bytes {
        return fail("G4U HTML exceeds the body limit.");
    }
    let text = match std::str::from_utf8(html) {
        Ok(text) if !text.is_empty() && !has_control_bytes(html) => text,
        _ => return fail("G4U returned invalid HTML."),
    };
    if has_unsafe_numeric_reference(text, false) {
        return fail("G4U returned an invalid character reference.");
    }
    if dom::exceeds_lexical_depth(text, dom::MAXIMUM_LEXICAL_DEPTH) {
        return fail("G4U returned invalid HTML.");
    }
    let document = dom::parse(&as_libxml_reads(text, Discard::Noncharacters));

    let header = today_header(&document)?;
    single(
        dom::parent_element(header)
            .filter(|parent| {
                dom::is_tag(*parent, "div")
                    && ["w3-content", "w3-center", "w3-light-grey"]
                        .iter()
                        .all(|class| dom::has_class(*parent, class))
            })
            .into_iter()
            .collect(),
    )?;
    let count_node = single(
        dom::children_named(header, "span")
            .into_iter()
            .filter(|span| dom::has_class(*span, "w3-right"))
            .collect(),
    )?;
    let declared = php_trim(&dom::text_content(count_node)).to_owned();
    if dom::has_element_descendants(count_node)
        || declared.is_empty()
        || !declared.bytes().all(|b| b.is_ascii_digit())
    {
        return fail("G4U returned an invalid today card count.");
    }

    let mut entries = Vec::new();
    let mut card_count = 0usize;
    for sibling in dom::following_nodes(header) {
        let Some(element) = ElementRef::wrap(sibling) else {
            if dom::is_visible_text(sibling.value()) {
                return fail("G4U returned unrecognized today section content.");
            }
            continue;
        };
        let headings = dom::descendants(element)
            .into_iter()
            .filter(|node| matches!(dom::tag(*node), "h1" | "h2"))
            .count();
        if matches!(dom::tag(element), "h1" | "h2") || headings > 0 {
            if !matches!(dom::tag(element), "div" | "h1" | "h2") || headings > 1 {
                return fail("G4U returned an ambiguous section boundary.");
            }
            break;
        }
        let title = card_title(element)?;
        card_count += 1;
        if entries.len() < page_size {
            entries.push(Entry {
                title,
                source: "g4u.to".to_owned(),
                ..Entry::default()
            });
        }
    }
    if card_count as u64 != declared.parse::<u64>().unwrap_or(u64::MAX) {
        return fail("G4U today card count does not match its listing.");
    }
    Ok(entries)
}

pub struct G4uSource {
    endpoint: String,
    page_size: usize,
    http: FeedHttp,
}

impl G4uSource {
    pub fn new(endpoint: String, page_size: usize, http: FeedHttp) -> Self {
        Self {
            endpoint,
            page_size: page_size.max(1),
            http,
        }
    }
}

#[async_trait]
impl FeedSource for G4uSource {
    fn key(&self) -> &str {
        "g4u"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        if page > 1 {
            return Ok(Vec::new());
        }
        let body = self
            .http
            .download_body(&self.endpoint, "g4u", MAXIMUM_BODY_BYTES, 2)
            .await?;
        parse_html(&body, self.page_size, MAXIMUM_BODY_BYTES)
    }
}
