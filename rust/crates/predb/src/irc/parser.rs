//! Announce lines: the NNTmux bot format and the public scene pre channels. Messages must
//! already have IRC colour and formatting codes stripped.

use std::sync::LazyLock;

use chrono::NaiveDateTime;
use nntmux_core::pcre::{Match, Pcre};
use regex::Regex;

use crate::feeds::{
    Entry, PRE_MODNUKE, PRE_NONUKE, PRE_NUKED, PRE_OLDNUKE, PRE_RENUKED, PRE_UNNUKED,
    files_from_count, size_from_megabytes,
};

fn compile(patterns: &[&str]) -> Vec<Pcre> {
    patterns
        .iter()
        .map(|p| Pcre::new(p).expect("static pattern"))
        .collect()
}

static CORRUPTNET: LazyLock<Vec<Pcre>> = LazyLock::new(|| {
    compile(&[
        r"/^(?<type>PRE):\s*\[(?<section>[^\]]+)\]\s+(?<title>\S+)\z/",
        r"/^(?<type>INFO):\s*(?<title>\S+)\s+\[(?<files>\d+)F\s+(?<size>\d+(?:\.\d+)?)MB\]\z/",
        r"/^(?<type>NUKE|UNNUKE|MODNUKE|RENUKE|OLDNUKE):\s*(?<title>\S+)\s+\[(?<reason>[^\]]*)\](?:\s+\[NUKENET\])?\z/",
    ])
});
static ZENET: LazyLock<Vec<Pcre>> = LazyLock::new(|| {
    compile(&[
        r"/^\((?<type>PRE)\)\s*\((?<section>[^)]+)\)\s*\((?<title>\S+)\)\z/i",
        r"/^\((?<type>INFO)\)\s*\((?<title>\S+?)\)\s*\((?<files>\d+) Files?\)\s*\((?<size>\d+(?:\.\d+)?) MB\)\z/i",
        r"/^\((?<type>NUKE|UNNUKE|MODNUKE|RENUKE|OLDNUKE)\)\s*\((?<title>\S+?)\)\s*\((?<reason>[^)]*)\)\z/i",
    ])
});
static PREDATABASE: LazyLock<Vec<Pcre>> = LazyLock::new(|| {
    compile(&[
        r"/^(?<type>pre)\s*\|\s*(?<section>[^|]+?)\s*\|\s*(?<title>\S+)\z/i",
        r"/^(?<type>info)\s*\|\s*(?<title>\S+)\s+-\s+(?<files>\d+)\s+F\s+&\s+(?<size>\d+(?:\.\d+)?)\s+MB\z/i",
        // Only the first site to get a P2P release announces "#1 ... WIN!"; later lines repeat it.
        r"/^(?<type>p2p)\s*\|\s*#1\s*\|\s*\S+\s*\|\s*(?<title>\S+?),\s*WIN!\z/i",
    ])
});
static NGP: LazyLock<Vec<Pcre>> = LazyLock::new(|| {
    compile(&[
        r"/^\.:::\s*(?<type>[A-Z]+)\s*:::\s*(?<section>\S+)\s*:::\s*(?<title>\S+)\s*:::\.\z/",
        r"/^\[(?<type>INFO)\]\s*FOR\s+(?<title>\S+)\s+with\s+(?<files>\d+)F\s+(?<size>\d+(?:\.\d+)?)MB added\.?\z/",
    ])
});
static RIZON: LazyLock<Vec<Pcre>> = LazyLock::new(|| {
    compile(&[
        r"/^\[(?<type>PRE)\]\s*\[(?<section>[^\]]+)\]\s*(?<title>\S+)\z/",
        r"/^\[(?<type>NUKE|UNNUKE|MODNUKE|RENUKE|OLDNUKE)\]\s+(?<title>\S+)\s+\[(?<reason>[^\]]*)\](?:\s+\[NUKENET\])?\z/",
    ])
});
static RELEASE_NAME: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^[\w.()&'+,-]+-[\w]+\z").expect("static pattern"));

fn patterns(format: &str) -> Option<&'static [Pcre]> {
    Some(match format {
        "corruptnet" => &CORRUPTNET,
        "zenet" => &ZENET,
        "predatabase" => &PREDATABASE,
        "ngp" => &NGP,
        "rizon" => &RIZON,
        _ => return None,
    })
}

fn group(m: &Match, name: &str) -> Option<String> {
    m.named()
        .into_iter()
        .find(|(group, _)| *group == name)
        .map(|(_, value)| String::from_utf8_lossy(value).into_owned())
}

/// Scene and P2P release names are a single token with a group suffix.
fn is_release_name(title: &str) -> bool {
    title.len() <= 255 && RELEASE_NAME.is_match(title)
}

pub struct AnnounceParser {
    format: String,
    source: String,
}

impl AnnounceParser {
    pub fn new(format: &str, source: &str) -> anyhow::Result<Self> {
        if format != "opentrackers" && patterns(format).is_none() {
            anyhow::bail!(
                "Unknown IRC pre announce format [{format}]. Available: corruptnet, zenet, predatabase, ngp, rizon, opentrackers"
            );
        }
        Ok(Self {
            format: format.to_owned(),
            source: source.to_owned(),
        })
    }

    pub fn parse(&self, message: &str, received_at: NaiveDateTime) -> Option<Entry> {
        if message.contains(['\r', '\n']) {
            return None;
        }
        let message = php_trim(message);
        if self.format == "opentrackers" {
            return self.parse_relay(message, received_at);
        }
        for pattern in patterns(&self.format)? {
            if let Some(m) = pattern.captures(message.as_bytes()) {
                return self.entry(&m, received_at);
            }
        }
        None
    }

    /// The relay carries several grammars. Its receipt is an observation, not a PRE time.
    fn parse_relay(&self, message: &str, received_at: NaiveDateTime) -> Option<Entry> {
        let mut matches = Vec::new();
        for format in ["corruptnet", "zenet", "predatabase", "rizon"] {
            for pattern in patterns(format)? {
                let Some(m) = pattern.captures(message.as_bytes()) else {
                    continue;
                };
                let kind = group(&m, "type").unwrap_or_default().to_uppercase();
                if kind == "PRE" && group(&m, "section").is_none() {
                    continue;
                }
                if let Some(entry) = self.entry(&m, received_at) {
                    matches.push(entry);
                }
            }
        }
        if matches.len() != 1 {
            return None;
        }
        let mut entry = matches.remove(0);
        entry.source = "opentrackers-relay".to_owned();
        entry.listed_at = entry.predate.map(|_| received_at);
        entry.predate = None;
        Some(entry)
    }

    fn entry(&self, m: &Match, received_at: NaiveDateTime) -> Option<Entry> {
        let kind = group(m, "type")?.to_uppercase();
        let title = group(m, "title")?;
        let nuked = match kind.as_str() {
            "PRE" | "P2P" | "INFO" => PRE_NONUKE,
            "NUKE" => PRE_NUKED,
            "UNNUKE" => PRE_UNNUKED,
            "MODNUKE" => PRE_MODNUKE,
            "RENUKE" => PRE_RENUKED,
            "OLDNUKE" => PRE_OLDNUKE,
            _ => return None,
        };
        if !is_release_name(&title) {
            return None;
        }
        let reason = group(m, "reason").unwrap_or_default();
        let reason = php_trim(&reason);
        let section = group(m, "section").unwrap_or_default();
        let section = php_trim(&section);
        let info = kind == "INFO";
        if kind == "PRE" && section.is_empty() {
            return None;
        }
        let files = group(m, "files").unwrap_or_default();
        let size = group(m, "size").unwrap_or_default();
        // An INFO line only counts when it carries both the file count and the size.
        if info && (files.is_empty() || size.is_empty()) {
            return None;
        }
        Some(Entry {
            title,
            source: self.source.clone(),
            category: (!section.is_empty()).then(|| section.to_owned()),
            size: if info {
                size_from_megabytes(size.parse().ok())
            } else {
                None
            },
            files: if info {
                files_from_count(files.parse().ok())
            } else {
                None
            },
            // Only a PRE line carries the release time; INFO and nukes can come much later.
            predate: matches!(kind.as_str(), "PRE" | "P2P").then_some(received_at),
            nuked,
            nuke_reason: (nuked != PRE_NONUKE && !reason.is_empty()).then(|| reason.to_owned()),
            listed_at: None,
            enrich_only: info,
        })
    }
}

fn php_trim(value: &str) -> &str {
    nntmux_categorize::pcre::trim_chars(value, nntmux_categorize::pcre::PHP_WS)
}

/// A line from the NNTmux pre bot.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct BotLine {
    pub time: String,
    pub title: String,
    pub source: String,
    pub category: String,
    pub req: String,
    pub size: String,
    pub files: String,
    pub filename: Option<String>,
    pub nuked: Option<String>,
    pub reason: Option<String>,
}

pub fn parse_bot_line(message: &str) -> Option<BotLine> {
    static LINE: LazyLock<Pcre> = LazyLock::new(|| {
        Pcre::new(
            r"/^(NEW|UPD|NUK): \[DT: (?P<time>.+?)\]\s?\[TT: (?P<title>.+?)\]\s?\[SC: (?P<source>.+?)\]\s?\[CT: (?P<category>.+?)\]\s?\[RQ: (?P<req>.+?)\]\s?\[SZ: (?P<size>.+?)\]\s?\[FL: (?P<files>.+?)\]\s?(\[FN: (?P<filename>.+?)\]\s?)?(\[(?P<nuked>(UN|MOD|RE|OLD)?NUKED?): (?P<reason>.+?)\])?$/i",
        )
        .expect("static pattern")
    });
    let m = LINE.captures(message.as_bytes())?;
    let required = |name: &str| group(&m, name).unwrap_or_default();
    Some(BotLine {
        time: required("time"),
        title: required("title"),
        source: required("source"),
        category: required("category"),
        req: required("req"),
        size: required("size"),
        files: required("files"),
        filename: group(&m, "filename"),
        nuked: group(&m, "nuked"),
        reason: group(&m, "reason"),
    })
}

/// `_stripControlCharacters`: the IRC colour and formatting codes.
pub fn strip_control_characters(line: &str) -> String {
    let re = nntmux_categorize::rx!(
        r"\x03\d{1,2}(?:,\d{1,2})?|\x03|\x04[0-9A-Fa-f]{6}(?:,[0-9A-Fa-f]{6})?|\x04|\x02|\x0F|\x16|\x1F|\x1D|\x1E|\x11"
    );
    nntmux_categorize::pcre::replace_all(re, line, "")
}

/// A channel message's nickname, channel and text.
pub fn parse_privmsg(line: &str) -> Option<(String, String, String)> {
    static MSG: LazyLock<Pcre> = LazyLock::new(|| {
        Pcre::new(
            r"/^:(?P<nickname>.+?)!.+?\s+PRIVMSG\s+(?P<channel>#.+?)\s+:\s*(?P<message>.+?)\s*$/",
        )
        .expect("static pattern")
    });
    let m = MSG.captures(line.as_bytes())?;
    Some((
        group(&m, "nickname")?,
        group(&m, "channel")?,
        group(&m, "message")?,
    ))
}
