//! Latest printed day of the public music directory catalogue, using anchor text only. Its
//! unzoned day groups listings and supplies neither publication nor PRE timestamps.
//!
//! The site's crawl delay matters: one request, no retries and no redirects, and a 429 is a plain
//! failure the command must not back off and retry.

use std::collections::HashSet;
use std::sync::LazyLock;
use std::time::{Duration, Instant};

use anyhow::anyhow;
use async_trait::async_trait;
use futures_util::StreamExt;
use regex::Regex;
use reqwest::header::{ACCEPT_ENCODING, CONTENT_ENCODING};
use reqwest::redirect::Policy;
use scraper::ElementRef;

use super::super::http::decode_gzip;
use super::super::{Entry, FeedConfig, FeedError, FeedResult, FeedSource};
use super::dom;
use super::g4u::is_calendar_date;
use super::literal::{
    Discard, as_libxml_reads, char_length, fail, has_control_bytes, has_unsafe_numeric_reference,
    is_nonliteral,
};

pub const MAXIMUM_BODY_BYTES: usize = 1024 * 1024;

const GENRE_LISTS: [(&str, &str); 4] = [
    ("Hardcore", "0day-hardcore"),
    ("Hardstyle", "0day-hardstyle"),
    ("Happy Hardcore", "0day-happy-hardcore"),
    ("Drum And Bass", "0day-drum-and-bass"),
];

fn single<'a>(found: Vec<ElementRef<'a>>) -> FeedResult<ElementRef<'a>> {
    match found.as_slice() {
        [only] => Ok(*only),
        _ => fail("Gabber returned a missing or ambiguous listing field."),
    }
}

fn children_with_class<'a>(parent: ElementRef<'a>, name: &str, class: &str) -> Vec<ElementRef<'a>> {
    dom::children_named(parent, name)
        .into_iter()
        .filter(|child| dom::has_class(*child, class))
        .collect()
}

/// The parent's children must be exactly `expected`, in order, with no loose text.
fn only_children(parent: ElementRef<'_>, expected: &[ElementRef<'_>]) -> FeedResult<()> {
    let mut actual = Vec::new();
    for child in dom::child_nodes(parent) {
        if let Some(element) = ElementRef::wrap(child) {
            actual.push(element);
        } else if dom::is_visible_text(child.value()) {
            return fail("Gabber returned mixed listing content.");
        }
    }
    if actual != expected {
        return fail("Gabber returned unexpected listing children.");
    }
    Ok(())
}

fn row_names(row: ElementRef<'_>) -> FeedResult<(&'static str, Vec<String>)> {
    let genre_field = single(children_with_class(row, "div", "views-field-field-genre"))?;
    let body_field = single(children_with_class(row, "div", "views-field-body"))?;
    only_children(row, &[genre_field, body_field])?;
    let genre_content = single(children_with_class(genre_field, "div", "field-content"))?;
    only_children(genre_field, &[genre_content])?;
    let printed = dom::text_content(genre_content);
    let known = GENRE_LISTS.iter().find(|(name, _)| *name == printed);
    let Some(&(genre, list_class)) = known.filter(|_| !dom::has_element_descendants(genre_content))
    else {
        return fail("Gabber returned an unrecognized directory genre.");
    };
    let body = single(children_with_class(body_field, "div", "field-content"))?;
    only_children(body_field, &[body])?;
    let list = single(children_with_class(body, "ul", list_class))?;
    only_children(body, &[list])?;
    let items = dom::children_named(list, "li");
    if items.is_empty() {
        return fail("Gabber returned an empty directory list.");
    }
    only_children(list, &items)?;

    let mut names = Vec::new();
    for item in items {
        let literal = single(dom::children_named(item, "a"))?;
        only_children(item, &[literal])?;
        let name = dom::text_content(literal);
        if dom::has_element_descendants(literal)
            || name.is_empty()
            || char_length(&name) > 255
            || is_nonliteral(&name)
        {
            return fail("Gabber returned a nonliteral directory name.");
        }
        names.push(name);
    }
    Ok((genre, names))
}

/// Parses the home page's newest day. Every row is validated before `page_size` limits the output.
pub fn parse_html(
    html: &[u8],
    page_size: usize,
    maximum_body_bytes: usize,
) -> FeedResult<Vec<Entry>> {
    static DATE: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"^([0-9]{4})-([0-9]{2})-([0-9]{2})$").expect("pattern"));
    if html.len() > maximum_body_bytes {
        return fail("Gabber HTML exceeds the body limit.");
    }
    let text = match std::str::from_utf8(html) {
        Ok(text) if !text.is_empty() && !has_control_bytes(html) => text,
        _ => return fail("Gabber returned invalid HTML."),
    };
    if has_unsafe_numeric_reference(text, false) {
        return fail("Gabber returned an invalid character reference.");
    }
    if dom::exceeds_lexical_depth(text, dom::MAXIMUM_LEXICAL_DEPTH) {
        return fail("Gabber returned invalid HTML.");
    }
    let document = dom::parse(&as_libxml_reads(text, Discard::Noncharacters));

    let view = single(
        dom::all_elements(&document)
            .into_iter()
            .filter(|element| {
                dom::is_tag(*element, "div") && dom::has_class(*element, "view-id-0day_releases")
            })
            .collect(),
    )?;
    let content = single(children_with_class(view, "div", "view-content"))?;
    // `./h3[1]` is the first h3 child.
    let header = single(
        dom::children_named(content, "h3")
            .into_iter()
            .take(1)
            .collect(),
    )?;
    for before in dom::child_nodes(content) {
        if before.id() == header.id() {
            break;
        }
        if ElementRef::wrap(before).is_some() || dom::is_visible_text(before.value()) {
            return fail("Gabber returned content before the latest day.");
        }
    }
    let link = single(dom::children_named(header, "a"))?;
    let date = single(children_with_class(link, "span", "date-display-single"))?;
    only_children(header, &[link])?;
    only_children(link, &[date])?;
    let printed = dom::text_content(date);
    let valid_day = !dom::has_element_descendants(date)
        && DATE.captures(&printed).is_some_and(|parts| {
            let part = |index: usize| parts[index].parse::<u32>().unwrap_or(0);
            is_calendar_date(part(1) as i32, part(2), part(3))
        });
    if !valid_day {
        return fail("Gabber returned an invalid printed day.");
    }

    let mut titles: Vec<String> = Vec::new();
    let mut seen = HashSet::new();
    let mut genres = HashSet::new();
    for sibling in dom::following_nodes(header) {
        let Some(element) = ElementRef::wrap(sibling) else {
            if dom::is_visible_text(sibling.value()) {
                return fail("Gabber returned unrecognized latest-day content.");
            }
            continue;
        };
        if dom::is_tag(element, "h3") {
            break;
        }
        if !dom::is_tag(element, "div") || !dom::has_class_pcre_space(element, "views-row") {
            return fail("Gabber returned an unrecognized latest-day row.");
        }
        let (genre, names) = row_names(element)?;
        if !genres.insert(genre) {
            return fail("Gabber returned duplicate latest-day genre rows.");
        }
        for name in names {
            if seen.insert(name.to_lowercase()) {
                titles.push(name);
            }
        }
    }
    if titles.is_empty() {
        return fail("Gabber returned no latest-day directory names.");
    }
    Ok(titles
        .into_iter()
        .take(page_size)
        .map(|title| Entry {
            title,
            source: "gabber.od.ua".to_owned(),
            ..Entry::default()
        })
        .collect())
}

pub struct GabberSource {
    endpoint: String,
    page_size: usize,
    client: reqwest::Client,
    timeout: Duration,
}

impl GabberSource {
    /// Builds its own client: the shared one follows redirects and Gabber must not.
    pub fn new(endpoint: String, config: &FeedConfig) -> anyhow::Result<Self> {
        let timeout = Duration::from_secs(config.timeout_seconds);
        let client = reqwest::Client::builder()
            .timeout(timeout)
            .connect_timeout(timeout.min(Duration::from_secs(10)))
            .user_agent(&config.user_agent)
            .redirect(Policy::none())
            .no_gzip()
            .build()?;
        Ok(Self {
            endpoint,
            page_size: config.page_size.max(1),
            client,
            timeout,
        })
    }

    /// One bounded request. No retry: a failed status is reported, not repeated.
    async fn download(&self) -> FeedResult<Vec<u8>> {
        let response = self
            .client
            .get(&self.endpoint)
            .header(ACCEPT_ENCODING, "gzip")
            .send()
            .await
            .map_err(|error| FeedError::Failed(error.into()))?;
        let status = response.status();
        if status.is_client_error() || status.is_server_error() {
            return fail(format!("Gabber returned HTTP status {}.", status.as_u16()));
        }
        if !status.is_success() {
            return fail("GABBER returned an unsuccessful listing response.");
        }
        let encoding = response
            .headers()
            .get(CONTENT_ENCODING)
            .and_then(|value| value.to_str().ok())
            .unwrap_or("")
            .trim()
            .to_ascii_lowercase();
        if !matches!(encoding.as_str(), "" | "identity" | "gzip") {
            return fail("GABBER returned an unsupported content encoding.");
        }
        let deadline = Instant::now() + self.timeout;
        let mut body = Vec::new();
        let mut stream = response.bytes_stream();
        loop {
            let remaining = deadline.saturating_duration_since(Instant::now());
            let chunk = tokio::time::timeout(remaining, stream.next())
                .await
                .map_err(|_| anyhow!("GABBER response exceeded the total body-read deadline."))?;
            let Some(chunk) = chunk else {
                break;
            };
            body.extend_from_slice(&chunk.map_err(|error| FeedError::Failed(error.into()))?);
            if body.len() > MAXIMUM_BODY_BYTES {
                return fail("GABBER response exceeds the body limit.");
            }
        }
        if encoding == "gzip" {
            return Ok(decode_gzip(&body, MAXIMUM_BODY_BYTES, "GABBER")?);
        }
        Ok(body)
    }
}

#[async_trait]
impl FeedSource for GabberSource {
    fn key(&self) -> &str {
        "gabber"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        if page > 1 {
            return Ok(Vec::new());
        }
        let body = self.download().await?;
        parse_html(&body, self.page_size, MAXIMUM_BODY_BYTES)
    }
}
