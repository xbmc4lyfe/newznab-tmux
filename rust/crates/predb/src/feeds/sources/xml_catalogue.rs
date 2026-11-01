//! Manual complete-export XML catalogues (NSWDB and 3DSDB). Catalogue IDs order entries and are
//! never PRE times. Gamecard image sizes, CRCs and archive aliases are deliberately not release
//! metadata.

use std::collections::{BTreeMap, HashSet};
use std::sync::LazyLock;

use async_trait::async_trait;
use regex::Regex;

use super::super::http::FeedHttp;
use super::super::{Entry, FeedResult, FeedSource};
use super::literal::{char_length, fail, is_nonliteral, php_trim};
use super::xml_tree::{self, XmlElement};

pub const DEFAULT_MAXIMUM_BODY_BYTES: usize = 8 * 1024 * 1024;
pub const DEFAULT_MAXIMUM_RECORDS: usize = 5000;

/// What a complete catalogue parse found. `rejections` counts the records left out, by reason.
#[derive(Debug, Default)]
pub struct Catalogue {
    pub entries: Vec<Entry>,
    pub rejections: BTreeMap<&'static str, usize>,
    /// Titles that only differed from the export by outer whitespace, which the importer trims.
    pub boundary_normalized: usize,
}

fn declares_external_entities(xml: &str) -> bool {
    static PATTERN: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"(?i)<!(?:DOCTYPE|ENTITY)(?-u:\b)").expect("pattern"));
    PATTERN.is_match(xml)
}

fn rejection_reason(record: &XmlElement, title: &str) -> Option<&'static str> {
    let mut names = record.named("releasename");
    let first = names.next();
    if first.is_none() || names.next().is_some() || first.is_some_and(|n| !n.elements.is_empty()) {
        return Some("missing_or_ambiguous_title");
    }
    if title.is_empty() {
        return Some("missing_or_ambiguous_title");
    }
    if is_nonliteral(title) {
        return Some("nonliteral_title");
    }
    if char_length(title) > 255 {
        return Some("overlength_title");
    }
    None
}

/// The accepted release names of a complete export, newest catalogue ID first.
pub fn parse_xml(
    key: &str,
    label: &str,
    xml: &[u8],
    maximum_body_bytes: usize,
    maximum_records: usize,
) -> FeedResult<Catalogue> {
    let upper = key.to_uppercase();
    let bounds = || {
        fail(format!(
            "{upper} export exceeds its bounds or declares external entities."
        ))
    };
    if xml.len() > maximum_body_bytes || xml.contains(&0) {
        return bounds();
    }
    let Ok(xml) = std::str::from_utf8(xml) else {
        return bounds();
    };
    if declares_external_entities(xml) {
        return bounds();
    }
    let invalid = || fail(format!("{upper} returned an invalid complete XML export."));
    let Some(document) = xml_tree::parse(xml) else {
        return invalid();
    };
    if document.name != "releases"
        || document.declares_namespaces()
        || !php_trim(&document.text).is_empty()
    {
        return invalid();
    }

    let total = document.named("release").count();
    if total < 1 || total > maximum_records {
        return fail(format!(
            "{upper} export has no records or exceeds the record limit."
        ));
    }

    let mut accepted: BTreeMap<i64, (Entry, bool)> = BTreeMap::new();
    let mut ids = HashSet::new();
    let mut rejections: BTreeMap<&'static str, usize> = BTreeMap::new();
    for record in &document.elements {
        let mut id_elements = record.named("id");
        let id_element = id_elements.next();
        let Some(id_element) = id_element.filter(|element| {
            record.name == "release" && id_elements.next().is_none() && element.elements.is_empty()
        }) else {
            return fail(format!(
                "{upper} export contains an unexpected record or missing catalogue ID."
            ));
        };
        let id = parse_catalogue_id(&id_element.text).filter(|id| ids.insert(*id));
        let Some(id) = id else {
            return fail(format!(
                "{upper} export contains an invalid or duplicate catalogue ID."
            ));
        };

        let raw_title = record
            .named("releasename")
            .next()
            .map_or("", |element| element.text.as_str());
        let title = php_trim(raw_title);
        if let Some(reason) = rejection_reason(record, title) {
            *rejections.entry(reason).or_default() += 1;
            continue;
        }
        let entry = Entry {
            title: title.to_owned(),
            source: label.to_owned(),
            ..Entry::default()
        };
        accepted.insert(id, (entry, raw_title != title));
    }

    let mut entries = Vec::new();
    let mut titles = HashSet::new();
    let mut boundary_normalized = 0;
    for (_, (entry, normalized)) in accepted.into_iter().rev() {
        if !titles.insert(entry.title.to_lowercase()) {
            *rejections.entry("duplicate_title").or_default() += 1;
            continue;
        }
        boundary_normalized += usize::from(normalized);
        entries.push(entry);
    }
    if !rejections.is_empty() {
        tracing::warn!(
            source = key,
            total_records = total,
            rejected_records = rejections.values().sum::<usize>(),
            reasons = ?rejections,
            "{upper} export omitted ambiguous, nonliteral or duplicate catalogue records."
        );
    }
    if boundary_normalized > 0 {
        tracing::info!(
            source = key,
            total_records = total,
            boundary_normalized_records = boundary_normalized,
            "{upper} export applied importer-compatible outer title formatting trim."
        );
    }
    Ok(Catalogue {
        entries,
        rejections,
        boundary_normalized,
    })
}

/// `^[1-9][0-9]*$` that also fits PHP's integer.
fn parse_catalogue_id(text: &str) -> Option<i64> {
    let digits = text.as_bytes();
    if digits.first().is_none_or(|b| !(b'1'..=b'9').contains(b))
        || !digits.iter().all(u8::is_ascii_digit)
    {
        return None;
    }
    text.parse().ok()
}

/// A complete XML export, cached and paged locally.
pub struct XmlCatalogueSource {
    key: &'static str,
    label: &'static str,
    endpoint: String,
    page_size: usize,
    maximum_body_bytes: usize,
    maximum_records: usize,
    http: FeedHttp,
    entries: Option<Vec<Entry>>,
    accepted: usize,
    rejections: BTreeMap<&'static str, usize>,
    boundary_normalized: usize,
}

impl XmlCatalogueSource {
    pub fn nswdb(endpoint: String, page_size: usize, http: FeedHttp) -> Self {
        Self::new("nswdb", "nswdb.com", endpoint, page_size, http)
    }

    pub fn three_ds_db(endpoint: String, page_size: usize, http: FeedHttp) -> Self {
        Self::new("3dsdb", "3dsdb.com", endpoint, page_size, http)
    }

    fn new(
        key: &'static str,
        label: &'static str,
        endpoint: String,
        page_size: usize,
        http: FeedHttp,
    ) -> Self {
        Self {
            key,
            label,
            endpoint,
            page_size: page_size.max(1),
            maximum_body_bytes: DEFAULT_MAXIMUM_BODY_BYTES,
            maximum_records: DEFAULT_MAXIMUM_RECORDS,
            http,
            entries: None,
            accepted: 0,
            rejections: BTreeMap::new(),
            boundary_normalized: 0,
        }
    }

    /// Tighter bounds than the 8 MiB and 5000 records the complete exports use.
    pub fn with_limits(mut self, maximum_body_bytes: usize, maximum_records: usize) -> Self {
        self.maximum_body_bytes = maximum_body_bytes;
        self.maximum_records = maximum_records;
        self
    }

    pub fn rejection_counts(&self) -> &BTreeMap<&'static str, usize> {
        &self.rejections
    }

    pub fn boundary_normalized_count(&self) -> usize {
        self.boundary_normalized
    }

    pub fn parse_xml(&mut self, xml: &[u8]) -> FeedResult<Vec<Entry>> {
        self.entries = None;
        self.accepted = 0;
        self.rejections.clear();
        self.boundary_normalized = 0;
        let catalogue = parse_xml(
            self.key,
            self.label,
            xml,
            self.maximum_body_bytes,
            self.maximum_records,
        )?;
        self.accepted = catalogue.entries.len();
        self.rejections = catalogue.rejections;
        self.boundary_normalized = catalogue.boundary_normalized;
        Ok(catalogue.entries)
    }
}

/// One page of a cached catalogue; a page past the end is empty.
pub(super) fn local_page(entries: &[Entry], page: u32, page_size: usize) -> Vec<Entry> {
    let start = (page as usize - 1).saturating_mul(page_size);
    entries
        .iter()
        .skip(start)
        .take(page_size)
        .cloned()
        .collect()
}

#[async_trait]
impl FeedSource for XmlCatalogueSource {
    fn key(&self) -> &str {
        self.key
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        if page < 1 {
            return fail(format!(
                "{} local pages start at one.",
                self.key.to_uppercase()
            ));
        }
        if self.entries.is_none() {
            self.rejections.clear();
            self.accepted = 0;
            self.boundary_normalized = 0;
            let body = self
                .http
                .download_body(&self.endpoint, self.key, self.maximum_body_bytes, 2)
                .await?;
            let entries = self.parse_xml(&body)?;
            self.entries = Some(entries);
        }
        let entries = self.entries.as_deref().unwrap_or_default();
        Ok(local_page(entries, page, self.page_size))
    }

    fn accepted_count(&self) -> Option<usize> {
        Some(self.accepted)
    }
}
