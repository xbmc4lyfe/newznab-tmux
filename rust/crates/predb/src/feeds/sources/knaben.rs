//! Public Knaben API v2 movie metadata, restricted to one latest browse page. Titles and publisher
//! category labels stay literal. Torrent dates, sizes, files and payload links have no documented
//! scene PRE meaning and are never imported. The adapter doesn't implement API history paging.

use std::collections::BTreeMap;
use std::sync::LazyLock;

use async_trait::async_trait;
use regex::Regex;

use super::super::http::FeedHttp;
use super::super::{Entry, FeedResult, FeedSource};
use super::literal::{char_length, contains_ellipsis, fail, is_nonliteral};

pub const MAXIMUM_BODY_BYTES: usize = 1024 * 1024;
const MOVIES: i64 = 3_000_000;

/// A decoded JSON value, typed the way PHP's `json_decode` types it: integers that fit 64 bits
/// are `Int` (`-0` included) and every other number is `Float`.
#[derive(Debug)]
enum Json {
    Null,
    Bool,
    Int(i64),
    Float,
    Text(String),
    List(Vec<Json>),
    Object(Vec<(String, Json)>),
}

impl Json {
    /// `isset($object->name)`: present and not null.
    fn field(&self, name: &str) -> Option<&Json> {
        match self {
            Json::Object(members) => members
                .iter()
                .find(|(key, _)| key == name)
                .map(|(_, value)| value)
                .filter(|value| !matches!(value, Json::Null)),
            _ => None,
        }
    }
}

/// PHP's `json_decode($json, false, 64)` refuses 64 levels of arrays and objects, not 65.
const MAXIMUM_CONTAINERS: usize = 63;

/// A strict RFC 8259 reader with `json_decode`'s edges: lowercase literals only, no leading zeros,
/// lone surrogate escapes and NUL-led property names refused, floats that overflow allowed.
/// Duplicate object members are noted rather than refused, because PHP checked for them only
/// after the document had proven valid.
struct Reader<'a> {
    text: &'a [u8],
    at: usize,
    duplicate: bool,
}

impl Reader<'_> {
    fn skip_space(&mut self) {
        while matches!(self.text.get(self.at), Some(b' ' | b'\t' | b'\n' | b'\r')) {
            self.at += 1;
        }
    }

    fn eat(&mut self, expected: u8) -> Option<()> {
        (self.text.get(self.at) == Some(&expected)).then(|| self.at += 1)
    }

    fn literal(&mut self, word: &[u8]) -> Option<()> {
        self.text[self.at..]
            .starts_with(word)
            .then(|| self.at += word.len())
    }

    fn value(&mut self, containers: usize) -> Option<Json> {
        self.skip_space();
        match *self.text.get(self.at)? {
            b'{' => self.object(containers + 1),
            b'[' => self.list(containers + 1),
            b'"' => self.string().map(Json::Text),
            b't' => self.literal(b"true").map(|()| Json::Bool),
            b'f' => self.literal(b"false").map(|()| Json::Bool),
            b'n' => self.literal(b"null").map(|()| Json::Null),
            _ => self.number(),
        }
    }

    fn list(&mut self, containers: usize) -> Option<Json> {
        if containers > MAXIMUM_CONTAINERS {
            return None;
        }
        self.at += 1;
        let mut items = Vec::new();
        self.skip_space();
        if self.eat(b']').is_some() {
            return Some(Json::List(items));
        }
        loop {
            items.push(self.value(containers)?);
            self.skip_space();
            if self.eat(b',').is_none() {
                self.eat(b']')?;
                return Some(Json::List(items));
            }
        }
    }

    fn object(&mut self, containers: usize) -> Option<Json> {
        if containers > MAXIMUM_CONTAINERS {
            return None;
        }
        self.at += 1;
        let mut members: Vec<(String, Json)> = Vec::new();
        self.skip_space();
        if self.eat(b'}').is_some() {
            return Some(Json::Object(members));
        }
        loop {
            self.skip_space();
            let key = self.string()?;
            // PHP refuses a property name that starts with a NUL byte.
            if key.starts_with('\0') {
                return None;
            }
            self.skip_space();
            self.eat(b':')?;
            let value = self.value(containers)?;
            self.duplicate |= members.iter().any(|(seen, _)| *seen == key);
            members.push((key, value));
            self.skip_space();
            if self.eat(b',').is_none() {
                self.eat(b'}')?;
                return Some(Json::Object(members));
            }
        }
    }

    fn hex4(&mut self) -> Option<u32> {
        let digits = self.text.get(self.at..self.at + 4)?;
        if !digits.iter().all(u8::is_ascii_hexdigit) {
            return None;
        }
        let code = u32::from_str_radix(std::str::from_utf8(digits).ok()?, 16).ok()?;
        self.at += 4;
        Some(code)
    }

    fn string(&mut self) -> Option<String> {
        self.eat(b'"')?;
        let mut out = Vec::new();
        loop {
            let byte = *self.text.get(self.at)?;
            self.at += 1;
            match byte {
                b'"' => return String::from_utf8(out).ok(),
                0..=0x1f => return None,
                b'\\' => {
                    let escape = *self.text.get(self.at)?;
                    self.at += 1;
                    let character = match escape {
                        b'"' | b'\\' | b'/' => char::from(escape),
                        b'b' => '\u{8}',
                        b'f' => '\u{c}',
                        b'n' => '\n',
                        b'r' => '\r',
                        b't' => '\t',
                        b'u' => {
                            let first = self.hex4()?;
                            let code = if (0xd800..0xdc00).contains(&first) {
                                self.eat(b'\\')?;
                                self.eat(b'u')?;
                                let second = self.hex4()?;
                                if !(0xdc00..0xe000).contains(&second) {
                                    return None;
                                }
                                0x10000 + ((first - 0xd800) << 10) + (second - 0xdc00)
                            } else {
                                first
                            };
                            char::from_u32(code)?
                        }
                        _ => return None,
                    };
                    let mut buffer = [0; 4];
                    out.extend_from_slice(character.encode_utf8(&mut buffer).as_bytes());
                }
                _ => out.push(byte),
            }
        }
    }

    fn number(&mut self) -> Option<Json> {
        let start = self.at;
        self.eat(b'-');
        match self.text.get(self.at)? {
            b'0' => self.at += 1,
            b'1'..=b'9' => {
                while self.text.get(self.at).is_some_and(u8::is_ascii_digit) {
                    self.at += 1;
                }
            }
            _ => return None,
        }
        let mut integral = true;
        if self.text.get(self.at) == Some(&b'.') {
            integral = false;
            self.at += 1;
            let digits = self.at;
            while self.text.get(self.at).is_some_and(u8::is_ascii_digit) {
                self.at += 1;
            }
            (self.at > digits).then_some(())?;
        }
        if matches!(self.text.get(self.at), Some(b'e' | b'E')) {
            integral = false;
            self.at += 1;
            if matches!(self.text.get(self.at), Some(b'+' | b'-')) {
                self.at += 1;
            }
            let digits = self.at;
            while self.text.get(self.at).is_some_and(u8::is_ascii_digit) {
                self.at += 1;
            }
            (self.at > digits).then_some(())?;
        }
        if integral
            && let Ok(number) = std::str::from_utf8(&self.text[start..self.at])
                .ok()?
                .parse::<i64>()
        {
            return Some(Json::Int(number));
        }
        Some(Json::Float)
    }
}

/// `None` is invalid JSON; the flag is whether any object repeated a member name.
fn decode(json: &[u8]) -> Option<(Json, bool)> {
    let mut reader = Reader {
        text: json,
        at: 0,
        duplicate: false,
    };
    let value = reader.value(0)?;
    reader.skip_space();
    (reader.at == json.len()).then_some((value, reader.duplicate))
}

#[derive(Debug, Default)]
pub struct KnabenPage {
    pub entries: Vec<Entry>,
    pub rejections: BTreeMap<&'static str, usize>,
}

fn rejection_reason(title: &str, category_ids: &[i64]) -> Option<&'static str> {
    static LITERAL: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"^[^.]+\.[^.]+(?:\.[^.]+)*$").expect("pattern"));
    if !category_ids.contains(&MOVIES) {
        return Some("non_movie_category");
    }
    if contains_ellipsis(title) {
        return Some("truncated_title");
    }
    if is_nonliteral(title) || !LITERAL.is_match(title) {
        return Some("nonliteral_title");
    }
    if char_length(title) > 255 {
        return Some("overlength_title");
    }
    None
}

/// Parses one browse page. `page_size` is the most hits the request asked for.
pub fn parse_json(
    json: &[u8],
    page_size: usize,
    maximum_body_bytes: usize,
) -> FeedResult<KnabenPage> {
    if json.len() > maximum_body_bytes {
        return fail("KNABEN response exceeds the body limit.");
    }
    // PHP validates the document first and only then looks for duplicate members, so a syntax
    // error wins over a duplicate that comes earlier in the text.
    let Some((document, duplicate)) = std::str::from_utf8(json).ok().and(decode(json)) else {
        return fail("KNABEN returned invalid JSON.");
    };
    if duplicate {
        return fail("KNABEN returned ambiguous duplicate JSON object members.");
    }

    let envelope = (|| {
        let Json::List(hits) = document.field("hits")? else {
            return None;
        };
        let total = document.field("total")?;
        if hits.len() > page_size || !matches!(total, Json::Object(_)) {
            return None;
        }
        let relation = total.field("relation")?;
        let value = total.field("value")?;
        let relation_ok = matches!(relation, Json::Text(text) if text == "eq" || text == "gte");
        let value_ok = matches!(value, Json::Int(value) if *value >= hits.len() as i64);
        (relation_ok && value_ok).then_some(hits)
    })();
    let Some(hits) = envelope else {
        return fail(
            "KNABEN returned an invalid browse envelope or exceeded the requested hit limit.",
        );
    };

    let malformed = || fail("KNABEN returned malformed title or category metadata.");
    let mut entries = Vec::new();
    let mut rejections: BTreeMap<&'static str, usize> = BTreeMap::new();
    for hit in hits {
        let (Some(Json::Text(title)), Some(Json::List(categories))) =
            (hit.field("title"), hit.field("categoryId"))
        else {
            return malformed();
        };
        let category = match hit.field("category") {
            None => None,
            Some(Json::Text(text)) => Some(text.clone()),
            Some(_) => return malformed(),
        };
        if !matches!(hit.field("date"), None | Some(Json::Text(_)))
            || title.is_empty()
            || categories.is_empty()
        {
            return malformed();
        }
        let mut category_ids = Vec::with_capacity(categories.len());
        for id in categories {
            match id {
                Json::Int(id) if *id >= 1 => category_ids.push(*id),
                _ => return fail("KNABEN returned malformed category IDs."),
            }
        }
        if let Some(reason) = rejection_reason(title, &category_ids) {
            *rejections.entry(reason).or_default() += 1;
            continue;
        }
        entries.push(Entry {
            title: title.clone(),
            source: "knaben.org".to_owned(),
            category,
            ..Entry::default()
        });
    }
    if !rejections.is_empty() {
        tracing::warn!(
            total_records = hits.len(),
            accepted_records = entries.len(),
            rejected_records = rejections.values().sum::<usize>(),
            reasons = ?rejections,
            "KNABEN latest movie page omitted nonliteral or ineligible records."
        );
    }
    Ok(KnabenPage {
        entries,
        rejections,
    })
}

pub struct KnabenSource {
    endpoint: String,
    page_size: usize,
    maximum_body_bytes: usize,
    http: FeedHttp,
    accepted: usize,
    rejections: BTreeMap<&'static str, usize>,
}

impl KnabenSource {
    /// The most hits one request asks for.
    pub const MAXIMUM_PAGE_SIZE: usize = 300;

    pub fn new(endpoint: &str, page_size: usize, http: FeedHttp) -> Self {
        let size = page_size.clamp(1, Self::MAXIMUM_PAGE_SIZE);
        Self {
            endpoint: format!("{endpoint}?c={MOVIES}&o=date&d=desc&s={size}&f=0"),
            page_size: size,
            maximum_body_bytes: MAXIMUM_BODY_BYTES,
            http,
            accepted: 0,
            rejections: BTreeMap::new(),
        }
    }

    pub fn url(&self) -> &str {
        &self.endpoint
    }

    pub fn rejection_counts(&self) -> &BTreeMap<&'static str, usize> {
        &self.rejections
    }

    /// Eligible entries in the last page parsed. Knaben isn't a complete catalogue, so this isn't
    /// the `FeedSource` hook.
    pub fn accepted(&self) -> usize {
        self.accepted
    }

    pub fn parse_json(&mut self, json: &[u8]) -> FeedResult<Vec<Entry>> {
        self.rejections.clear();
        self.accepted = 0;
        let page = parse_json(json, self.page_size, self.maximum_body_bytes)?;
        self.accepted = page.entries.len();
        self.rejections = page.rejections;
        Ok(page.entries)
    }
}

#[async_trait]
impl FeedSource for KnabenSource {
    fn key(&self) -> &str {
        "knaben"
    }

    async fn fetch(&mut self, page: u32) -> FeedResult<Vec<Entry>> {
        if page < 1 {
            return fail("KNABEN pages start at one.");
        }
        if page > 1 {
            return Ok(Vec::new());
        }
        let body = self
            .http
            .download_body(&self.endpoint, "knaben", self.maximum_body_bytes, 2)
            .await?;
        self.parse_json(&body)
    }
}
