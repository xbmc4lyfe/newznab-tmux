//! Generic RSS 2.0 feed. Only the item title, first category and pubDate are used.

use anyhow::anyhow;
use async_trait::async_trait;
use chrono::{DateTime, NaiveDateTime};
use nntmux_categorize::{mi, rx};
use quick_xml::Reader;
use quick_xml::events::Event;

use super::super::http::FeedHttp;
use super::super::{Entry, FeedError, FeedResult, FeedSource};

const MAXIMUM_ITEMS: usize = 5000;

/// Feeds with human-readable titles set `spaced_titles`: whitespace becomes dots and a trailing
/// " EZTV" tag is dropped. That's lossy, so those sources stay opt-in. Catalogues set
/// `publication_dates_only` so listing times are never treated as PRE times.
#[derive(Clone, Copy, Debug, Default)]
pub struct RssOptions {
    pub spaced_titles: bool,
    pub map_category: bool,
    pub publication_dates_only: bool,
    pub strip_display_size_suffix: bool,
}

pub struct RssFeedSource {
    key: String,
    label: &'static str,
    endpoint: String,
    page_size: usize,
    maximum_body_bytes: usize,
    http: FeedHttp,
    options: RssOptions,
}

impl RssFeedSource {
    pub fn new(
        key: String,
        label: &'static str,
        endpoint: String,
        page_size: usize,
        maximum_body_bytes: usize,
        http: FeedHttp,
        options: RssOptions,
    ) -> Self {
        Self {
            key,
            label,
            endpoint,
            page_size,
            maximum_body_bytes,
            http,
            options,
        }
    }

    pub fn parse_xml(&self, xml: &[u8]) -> FeedResult<Vec<Entry>> {
        let invalid =
            || FeedError::Failed(anyhow!("{} returned an invalid RSS document.", self.label));
        if xml.len() > self.maximum_body_bytes {
            return Err(FeedError::Failed(anyhow!(
                "{} response exceeds the body limit.",
                self.key.to_uppercase()
            )));
        }
        if rx!(i r"<!(?:DOCTYPE|ENTITY)\b").is_match(xml) {
            return Err(invalid());
        }
        let items = read_items(xml)
            .map_err(|_| invalid())?
            .ok_or_else(invalid)?;
        if items.len() > MAXIMUM_ITEMS {
            return Err(FeedError::Failed(anyhow!(
                "{} RSS document exceeds the item limit.",
                self.label
            )));
        }
        let mut entries = Vec::new();
        for item in items {
            let mut title = item.title.trim().to_owned();
            if self.options.strip_display_size_suffix {
                title = strip_display_size(&title);
            }
            if self.options.spaced_titles {
                title = dotted(&title);
            }
            if title.is_empty() || title.contains(' ') {
                continue;
            }
            let category = if self.options.map_category {
                item.category.trim()
            } else {
                ""
            };
            let published = parse_date(&item.pub_date, self.options.publication_dates_only);
            entries.push(Entry {
                title,
                source: self.label.to_owned(),
                category: (!category.is_empty()).then(|| category.to_owned()),
                predate: if self.options.publication_dates_only {
                    None
                } else {
                    published
                },
                listed_at: if self.options.publication_dates_only {
                    published
                } else {
                    None
                },
                ..Entry::default()
            });
            if entries.len() >= self.page_size {
                break;
            }
        }
        Ok(entries)
    }
}

#[async_trait]
impl FeedSource for RssFeedSource {
    fn key(&self) -> &str {
        &self.key
    }

    /// RSS feeds expose only their latest items, so every page past the first is empty.
    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        if page > 1 {
            return Ok(Vec::new());
        }
        let body = self
            .http
            .download_body(&self.endpoint, &self.key, self.maximum_body_bytes, 2)
            .await?;
        self.parse_xml(&body)
    }
}

#[derive(Default)]
struct Item {
    title: String,
    category: String,
    pub_date: String,
}

/// The items under `rss/channel`, or `None` when the document isn't RSS.
fn read_items(xml: &[u8]) -> anyhow::Result<Option<Vec<Item>>> {
    let mut reader = Reader::from_reader(xml);
    let config = reader.config_mut();
    config.trim_text(false);
    config.check_end_names = true;
    let mut buffer = Vec::new();
    let mut path: Vec<Vec<u8>> = Vec::new();
    let mut items = Vec::new();
    let mut current: Option<Item> = None;
    let mut seen_channel = false;
    let mut seen_root = false;
    let mut first_child_text: Option<(String, String)> = None;
    loop {
        let event = reader.read_event_into(&mut buffer)?;
        match event {
            Event::Start(start) => {
                let name = start.name().as_ref().to_ascii_lowercase();
                if path.is_empty() {
                    if name != b"rss" {
                        return Ok(None);
                    }
                    seen_root = true;
                }
                if path.len() == 1 && name == b"channel" {
                    seen_channel = true;
                }
                if path.len() == 2 && name == b"item" && path[1] == b"channel" {
                    current = Some(Item::default());
                }
                if path.len() == 3 && current.is_some() {
                    first_child_text =
                        Some((String::from_utf8_lossy(&name).into_owned(), String::new()));
                }
                path.push(name);
            }
            Event::Empty(empty) => {
                let name = empty.name().as_ref().to_ascii_lowercase();
                if path.is_empty() {
                    return Ok(None);
                }
                if path.len() == 1 && name == b"channel" {
                    seen_channel = true;
                }
                if path.len() == 2 && name == b"item" && path[1] == b"channel" {
                    items.push(Item::default());
                }
            }
            Event::Text(text) => {
                if let Some((_, value)) = &mut first_child_text
                    && path.len() == 4
                {
                    value.push_str(&text.xml10_content()?);
                }
            }
            Event::GeneralRef(reference) => {
                if let Some((_, value)) = &mut first_child_text
                    && path.len() == 4
                {
                    value.push_str(&resolve_reference(&reference.decode()?));
                }
            }
            Event::CData(data) => {
                if let Some((_, value)) = &mut first_child_text
                    && path.len() == 4
                {
                    value.push_str(&String::from_utf8_lossy(&data.into_inner()));
                }
            }
            Event::End(_) => {
                path.pop();
                if path.len() == 3
                    && let (Some(item), Some((name, value))) =
                        (&mut current, first_child_text.take())
                {
                    // SimpleXML's `$item->title` reads the first element of that name.
                    let slot = match name.as_str() {
                        "title" => Some(&mut item.title),
                        "category" => Some(&mut item.category),
                        "pubdate" => Some(&mut item.pub_date),
                        _ => None,
                    };
                    if let Some(slot) = slot
                        && slot.is_empty()
                    {
                        *slot = value;
                    }
                }
                if path.len() == 2
                    && let Some(item) = current.take()
                {
                    items.push(item);
                }
            }
            Event::Eof => break,
            _ => {}
        }
        buffer.clear();
    }
    if !path.is_empty() || !seen_root || !seen_channel {
        return Ok(None);
    }
    Ok(Some(items))
}

fn resolve_reference(name: &str) -> String {
    if let Some(number) = name.strip_prefix('#') {
        let parsed = match number.strip_prefix(['x', 'X']) {
            Some(hex) => u32::from_str_radix(hex, 16).ok(),
            None => number.parse().ok(),
        };
        return parsed
            .and_then(char::from_u32)
            .map(String::from)
            .unwrap_or_default();
    }
    match name {
        "amp" => "&",
        "lt" => "<",
        "gt" => ">",
        "quot" => "\"",
        "apos" => "'",
        _ => "",
    }
    .to_owned()
}

fn strip_display_size(title: &str) -> String {
    match title.rfind(" \u{2013} ") {
        Some(index) if mi!(title[index + 5..], r"^[0-9]+(?:\.[0-9]+)? (?:MB|GB)$") => {
            // The PHP pattern is case-sensitive; check again without /i.
            let tail = &title[index + 5..];
            if tail.ends_with("MB") || tail.ends_with("GB") {
                title[..index].to_owned()
            } else {
                title.to_owned()
            }
        }
        _ => title.to_owned(),
    }
}

fn dotted(title: &str) -> String {
    let trimmed = title.trim();
    let without = nntmux_categorize::pcre::replace_all(rx!(i r"\s+EZTV$"), trimmed, "");
    nntmux_categorize::pcre::replace_all(rx!(r"\s+"), &without, ".")
}

fn parse_date(value: &str, requires_zone: bool) -> Option<NaiveDateTime> {
    let value = value.trim();
    if value.is_empty() {
        return None;
    }
    if let Ok(parsed) = DateTime::parse_from_rfc2822(value) {
        return Some(parsed.naive_utc());
    }
    if let Ok(parsed) = DateTime::parse_from_rfc3339(value) {
        return Some(parsed.naive_utc());
    }
    if requires_zone {
        return None;
    }
    NaiveDateTime::parse_from_str(value, "%Y-%m-%d %H:%M:%S").ok()
}
