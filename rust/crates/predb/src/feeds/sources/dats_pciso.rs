//! Manual PC ISO 1998 dictionary from one complete public DATS.SITE metadata export. Only
//! independent literal MIA ROM names are eligible (237 of the captured 301 games). The other 64
//! games contain archive-file metadata, and decorated game names never supply titles. Export and
//! version dates never establish a PRE or listing time. Catalogue nuke annotations are validated
//! but not imported as current scene status.

use std::collections::{BTreeMap, HashSet};
use std::sync::LazyLock;

use async_trait::async_trait;
use regex::Regex;

use super::super::http::FeedHttp;
use super::super::{Entry, FeedResult, FeedSource};
use super::literal::{char_length, fail, has_other_character, is_nonliteral, php_trim};
use super::xml_catalogue::local_page;
use super::xml_tree::{self, XmlElement};

pub const DEFAULT_MAXIMUM_BODY_BYTES: usize = 1024 * 1024;
pub const DEFAULT_MAXIMUM_RECORDS: usize = 5000;
const KEY: &str = "DATS_PCISO_1998";

#[derive(Debug, Default)]
pub struct DatsCatalogue {
    pub entries: Vec<Entry>,
    pub rejections: BTreeMap<&'static str, usize>,
}

fn has_attributes(element: &XmlElement, expected: &[&str]) -> bool {
    let mut expected = expected.to_vec();
    expected.sort_unstable();
    element.plain_attribute_names() == expected
}

fn matches(pattern: &'static LazyLock<Regex>, text: &str) -> bool {
    pattern.is_match(text)
}

fn validate_header(header: &XmlElement) -> FeedResult<()> {
    if !has_attributes(header, &[]) || !php_trim(&header.text).is_empty() {
        return fail(format!("{KEY} returned an invalid export header."));
    }
    let mut fields = Vec::new();
    for field in &header.elements {
        fields.push(field.name.as_str());
        let allowed: &[&str] = if field.name == "clrmamepro" {
            &["forcepacking"]
        } else {
            &[]
        };
        if !field.elements.is_empty() || !has_attributes(field, allowed) {
            return fail(format!(
                "{KEY} returned nested or unsupported header fields."
            ));
        }
    }
    fn text<'a>(header: &'a XmlElement, name: &str) -> &'a str {
        header
            .elements
            .iter()
            .find(|e| e.name == name)
            .map_or("", |e| e.text.as_str())
    }
    let packing = header
        .named("clrmamepro")
        .next()
        .and_then(|e| e.attribute("forcepacking"))
        .unwrap_or("");
    if fields
        != [
            "name",
            "description",
            "category",
            "version",
            "date",
            "clrmamepro",
        ]
        || text(header, "name") != "PC iSO Games Scene 1998"
        || text(header, "description") != text(header, "name")
        || text(header, "category") != "Scene"
        || text(header, "version").is_empty()
        || text(header, "date").is_empty()
        || packing != "unzip"
        || !text(header, "clrmamepro").is_empty()
    {
        return fail(format!(
            "{KEY} returned a different or unsupported metadata dataset."
        ));
    }
    Ok(())
}

pub fn parse_xml(
    xml: &[u8],
    maximum_body_bytes: usize,
    maximum_records: usize,
) -> FeedResult<DatsCatalogue> {
    static ENTITIES: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"(?i)<!(?:DOCTYPE|ENTITY)(?-u:\b)").expect("pattern"));
    let Ok(text) = std::str::from_utf8(xml) else {
        return fail(format!(
            "{KEY} returned an invalid, oversized or entity-declaring export."
        ));
    };
    if xml.len() > maximum_body_bytes || xml.contains(&0) || matches(&ENTITIES, text) {
        return fail(format!(
            "{KEY} returned an invalid, oversized or entity-declaring export."
        ));
    }
    let schema = || fail(format!("{KEY} returned an invalid complete export schema."));
    let Some(document) = xml_tree::parse(text) else {
        return schema();
    };
    let games = document.named("game").count();
    if document.name != "datafile"
        || document.declares_namespaces()
        || !has_attributes(&document, &[])
        || !php_trim(&document.text).is_empty()
        || document.named("header").count() != 1
        || games < 1
        || games > maximum_records
    {
        return schema();
    }
    if let Some(header) = document.named("header").next() {
        validate_header(header)?;
    }

    static HEX8: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[0-9a-fA-F]{8}$").expect("p"));
    static HEX32: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[0-9a-fA-F]{32}$").expect("p"));
    static HEX40: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[0-9a-fA-F]{40}$").expect("p"));
    static SIZE: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^(?:0|[1-9][0-9]*)$").expect("p"));

    let mut entries = Vec::new();
    let mut game_names = HashSet::new();
    let mut titles = HashSet::new();
    let mut unsupported = 0;
    for game in &document.elements {
        if game.name == "header" {
            continue;
        }
        let descriptions: Vec<&XmlElement> = game.named("description").collect();
        let roms = game.named("rom").count();
        if game.name != "game"
            || !has_attributes(game, &["name"])
            || descriptions.len() != 1
            || !descriptions[0].elements.is_empty()
            || !has_attributes(descriptions[0], &[])
            || roms < 1
            || !php_trim(&game.text).is_empty()
        {
            return fail(format!("{KEY} returned an invalid game schema."));
        }
        let nuked: Vec<&XmlElement> = game.named("nukedreason").collect();
        if nuked.len() > 1
            || (nuked.len() == 1
                && (!nuked[0].elements.is_empty()
                    || !has_attributes(nuked[0], &[])
                    || nuked[0].text.is_empty()))
        {
            return fail(format!("{KEY} returned an ambiguous catalogue annotation."));
        }
        let game_name = game.attribute("name").unwrap_or("");
        if game_name.is_empty()
            || descriptions[0].text != game_name
            || !game_names.insert(game_name.to_lowercase())
        {
            return fail(format!(
                "{KEY} returned duplicate games or conflicting descriptions."
            ));
        }

        let mut rom_names = HashSet::new();
        let mut mia = Vec::new();
        for rom in &game.elements {
            if matches!(rom.name.as_str(), "description" | "nukedreason") {
                continue;
            }
            let name = rom.attribute("name").unwrap_or("");
            if rom.name != "rom"
                || !rom.elements.is_empty()
                || !rom.text.is_empty()
                || name.is_empty()
                || char_length(name) > 255
                || has_other_character(name)
                || !rom_names.insert(name.to_lowercase())
            {
                return fail(format!("{KEY} returned invalid or duplicate ROM records."));
            }
            if let Some(flag) = rom.attribute("mia") {
                if !has_attributes(rom, &["name", "mia"]) || flag != "yes" {
                    return fail(format!("{KEY} returned an ambiguous MIA record."));
                }
                mia.push(name);
            } else {
                let attribute = |key: &str| rom.attribute(key).unwrap_or("");
                if !has_attributes(rom, &["name", "size", "crc", "md5", "sha1"])
                    || !matches(&SIZE, attribute("size"))
                    || !matches(&HEX8, attribute("crc"))
                    || !matches(&HEX32, attribute("md5"))
                    || !matches(&HEX40, attribute("sha1"))
                {
                    return fail(format!("{KEY} returned invalid archive-file metadata."));
                }
            }
        }
        if mia.is_empty() {
            unsupported += 1;
            continue;
        }
        if mia.len() != 1 || roms != 1 {
            return fail(format!(
                "{KEY} returned mixed or ambiguous MIA title records."
            ));
        }
        let title = mia[0];
        if is_nonliteral(title)
            || title.contains(['/', '\\'])
            || !game_name.ends_with(&format!("-{title}"))
            || !titles.insert(title.to_lowercase())
        {
            return fail(format!(
                "{KEY} returned a nonliteral or conflicting MIA title association."
            ));
        }
        entries.push(Entry {
            title: title.to_owned(),
            source: "dats.site".to_owned(),
            ..Entry::default()
        });
    }
    let mut rejections = BTreeMap::new();
    if unsupported > 0 {
        rejections.insert("archive_file_metadata", unsupported);
    }
    Ok(DatsCatalogue {
        entries,
        rejections,
    })
}

pub struct DatsPcIsoSource {
    endpoint: String,
    page_size: usize,
    maximum_body_bytes: usize,
    maximum_records: usize,
    http: FeedHttp,
    entries: Option<Vec<Entry>>,
    accepted: usize,
    rejections: BTreeMap<&'static str, usize>,
}

impl DatsPcIsoSource {
    pub fn new(endpoint: String, page_size: usize, http: FeedHttp) -> Self {
        Self {
            endpoint,
            page_size: page_size.max(1),
            maximum_body_bytes: DEFAULT_MAXIMUM_BODY_BYTES,
            maximum_records: DEFAULT_MAXIMUM_RECORDS,
            http,
            entries: None,
            accepted: 0,
            rejections: BTreeMap::new(),
        }
    }

    pub fn with_limits(mut self, maximum_body_bytes: usize, maximum_records: usize) -> Self {
        self.maximum_body_bytes = maximum_body_bytes;
        self.maximum_records = maximum_records;
        self
    }

    pub fn rejection_counts(&self) -> &BTreeMap<&'static str, usize> {
        &self.rejections
    }

    pub fn parse_xml(&mut self, xml: &[u8]) -> FeedResult<Vec<Entry>> {
        self.entries = None;
        self.accepted = 0;
        self.rejections.clear();
        let catalogue = parse_xml(xml, self.maximum_body_bytes, self.maximum_records)?;
        self.accepted = catalogue.entries.len();
        self.rejections = catalogue.rejections;
        Ok(catalogue.entries)
    }
}

#[async_trait]
impl FeedSource for DatsPcIsoSource {
    fn key(&self) -> &str {
        "dats_pciso_1998"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        if page < 1 {
            return fail("DATS_PCISO_1998 local pages start at one.");
        }
        if self.entries.is_none() {
            let body = self
                .http
                .download_body(
                    &self.endpoint,
                    "dats_pciso_1998",
                    self.maximum_body_bytes,
                    2,
                )
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
