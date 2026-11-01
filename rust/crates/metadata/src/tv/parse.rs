//! Reads a show, season, episode and airdate from a release name. Port of
//! `AbstractTvProvider::parseInfo` and `ParsedTvRelease`.

use std::sync::LazyLock;

use chrono::{Datelike, NaiveDate};
use nntmux_core::pcre::{Match, Pcre};
use nntmux_predb::selector::similar_text_percent;
use regex::bytes::Regex;

use crate::php::{bytes_to_string, trim};

/// Minimum title similarity, in percent, for a search result to be considered.
const MATCH_PROBABILITY: f64 = 75.0;

const FOLLOWING: &str = r"[^a-z0-9]([(|\[]\w+[)|\]]\s)*?(\d\d-\d\d|\d{1,3}x\d{2,3}|\(?(19|20)\d{2}\)?|(480|720|1080|2160)[ip]|AAC2?|BD-?Rip|Blu-?Ray|D0?\d|DD5|DiVX|DLMux|DTS|DVD(-?Rip)?|E\d{2,3}|[HX][\-_. ]?26[45]|ITA(-ENG)?|HEVC|[HPS]DTV|PROPER|REPACK|Season|Episode|S\d+[^a-z0-9]?((E\d+)[abr]?)*|WEB[\-_. ]?(DL|Rip)|XViD)[^a-z0-9]?";

fn pcre(pattern: &str) -> Pcre {
    Pcre::new(pattern).expect("a valid regex")
}

macro_rules! pattern {
    ($name:ident, $pattern:expr) => {
        static $name: LazyLock<Pcre> = LazyLock::new(|| pcre(&$pattern));
    };
}

pattern!(FANSUB_PREFIX, r"/^\[[^]]+][_\s]*/i");
pattern!(
    NAME_AFTER_NOISE,
    format!(r"/^([^a-z0-9]{{2,}}|(sample|proof|repost)-)(?P<name>[\w .\-!&]+?){FOLLOWING}/i")
);
pattern!(
    NAME_AT_START,
    format!(r"/^(?P<name>[\w!&][\s\w'._\-!&]*?){FOLLOWING}/i")
);
pattern!(
    NAME_BEFORE_SE,
    r"/^(?P<name>.+?)[_\s]*[-_\s]+S\d{1,2}E\d{1,3}/i"
);
pattern!(
    NAME_BEFORE_NUMBER,
    r"/^(?P<name>.+?)[_\s]+[-_]+[_\s]*\d{1,3}[_\s]*[\[(]/i"
);
pattern!(NAME_ANIME_TITLE, r"/^(?P<name>.+?)_-_.+?_-_\d{1,3}_[\[(]/i");
pattern!(NAME_ANIME, r"/^(?P<name>[^_]+(?:_[^_-]+)*)_-_/i");
pattern!(STRIP_FOLLOWING, format!("/{FOLLOWING}/i"));
pattern!(LEADING_SIX_DIGITS, r"/^\d{6}/");
pattern!(PARENS_OR_DOTS, r"/\(.*?\)|[._]/i");
pattern!(SPACES, r"/\s{2,}/");
pattern!(TRAILING_NUMBER, r"/ - \d+$/i");
pattern!(YEAR, r"/[^a-z0-9](?P<year>(19|20)(\d{2}))[^a-z0-9]/i");
pattern!(
    COUNTRY,
    r"/[^a-z0-9](US|UK|AU|NZ|CA|NL|Canada|Australia|America|United[^a-z0-9]States|United[^a-z0-9]Kingdom)/i"
);
pattern!(CHANNEL_PREFIX, r"/^(history|discovery) channel/i");
pattern!(
    LANGUAGE_TAGS,
    r"/\b(German|French|Spanish|Italian|Dutch|Russian|Japanese|Korean|Chinese|Portuguese|Swedish|Norwegian|Danish|Finnish|Polish|Czech|Hungarian|Romanian|Greek|Turkish|Arabic|Hebrew|Hindi|Thai|Vietnamese|Indonesian|Malay|Tagalog|DL|Dubbed|Subbed|MULTI|MULTi)\b/i"
);
pattern!(
    SUBTITLE_NOISE,
    r"/\s+-\s+.*(wo andere|where others|Arbeiten|Working).*$/i"
);
pattern!(TRAILING_HYPHEN, r"/\s*-\s*$/");
pattern!(TRAILING_EPISODE, r"/\s+\d{1,3}\s*$/");
pattern!(TRAILING_HYPHEN_2, r"/\s+-$/");
pattern!(TITLE_YEAR_SUFFIX, r"/\s*\((19|20)\d{2}\)$/");
pattern!(
    TITLE_WITH_YEAR,
    r"/^(?P<title>.+?)\s*\((?P<year>(19|20)\d{2})\)$/"
);
pattern!(YEAR_PREFIX, r"/^(?P<year>(19|20)\d{2})/");

pattern!(
    SE_RANGE,
    r"/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]?e(\d{1,3})[e-](\d{1,3})[^a-z0-9]?/i"
);
pattern!(
    SE_DOUBLE,
    r"/^(.*?)[^a-z0-9]s(\d{2})[^a-z0-9]?e(\d{2})e?(\d{2})[^a-z0-9]?/i"
);
pattern!(
    SE_SINGLE,
    r"/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]?e(\d{1,3})[abr]?[^a-z0-9]?/i"
);
pattern!(
    SE_DISC,
    r"/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]?d\d[^a-z0-9]?/i"
);
pattern!(SE_SEASON, r"/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]?/i");
pattern!(SE_X, r"/^(.*?)[^a-z0-9](\d{1,2})x(\d{1,3})[^a-z0-9]?/i");
pattern!(
    DATE_YMD,
    r"/^(.*?)[^a-z0-9](?P<year>(19|20)\d{2})[.\/-](?P<month>\d{2})[.\/-](?P<day>\d{2})[^a-z0-9]?/i"
);
pattern!(
    DATE_DMY,
    r"/^(.*?)[^a-z0-9](?P<first>\d{2})(?P<sep>[.\/-])(?P<second>\d{2})(?P=sep)(?P<year>(19|20)\d{2})[^a-z0-9]?/i"
);
pattern!(
    DATE_DMY_SHORT,
    r"/^(.*?)[^a-z0-9](?P<first>\d{2})(?P<sep>[.\/-])(?P<second>\d{2})(?P=sep)(?P<year>\d{2})[^a-z0-9]?/i"
);
pattern!(
    YEAR_EPISODE,
    r"/^(.*?)[^a-z0-9](20\d{2})[^a-z0-9]E(\d{1,3})(?!\d)/i"
);
pattern!(
    YEAR_PART,
    r"/^(.*?)[^a-z0-9](19|20)(\d{2})[^a-z0-9]Part(\d{1,2})[^a-z0-9]?/i"
);
pattern!(
    PART,
    r"/^(.*?)[^a-z0-9](?:Part|Pt)[^a-z0-9](\d{1,2})[^a-z0-9]?/i"
);
pattern!(EPISODE_ONLY, r"/^(.*?)[^a-z0-9]EP?[^a-z0-9]?(\d{1,3})/i");
pattern!(ANIME_EPISODE, r"/[_\s][-_][_\s](\d{1,3})[_\s]*[\[(]/i");
pattern!(
    SEASON_WORD,
    r"/^(.*?)[^a-z0-9]Seasons?[^a-z0-9]?(\d{1,2})/i"
);

static ACRONYM: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"(?-u)\b((?:[A-Za-z]\.){2,}[A-Za-z]?\.?)\b").expect("a valid regex")
});

/// Show, season, episode and airdate parsed from a release searchname.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ParsedRelease {
    pub name: String,
    pub clean_name: String,
    pub country: String,
    pub season: Option<i64>,
    pub episode: Option<i64>,
    pub season_pack: bool,
    pub airdate: Option<String>,
    pub year: Option<i32>,
}

impl ParsedRelease {
    pub fn has_episode_numbers(&self) -> bool {
        self.season.is_some_and(|season| season >= 0) && self.episode.is_some_and(|ep| ep > 0)
    }

    pub fn has_airdate(&self) -> bool {
        self.airdate.is_some()
    }

    pub fn has_episode_reference(&self) -> bool {
        self.has_episode_numbers() || self.has_airdate()
    }

    /// The clean name without the " (YYYY)" suffix the parser appends.
    pub fn title_without_year(&self) -> String {
        trim(&bytes_to_string(
            TITLE_YEAR_SUFFIX.replace_all(self.clean_name.as_bytes(), b""),
        ))
        .to_owned()
    }

    /// Two-letter country parsed from the name, or nothing.
    pub fn country_code(&self) -> &str {
        if self.country.len() == 2 {
            &self.country
        } else {
            ""
        }
    }
}

#[derive(Clone, Debug, PartialEq)]
enum EpisodeNumber {
    Number(i64),
    All,
}

#[derive(Clone, Debug, PartialEq)]
struct SeasonEpisode {
    season: i64,
    episode: EpisodeNumber,
    airdate: Option<String>,
}

fn number(found: &Match, group: usize) -> i64 {
    found
        .group(group)
        .and_then(|digits| std::str::from_utf8(digits).ok())
        .and_then(|digits| digits.parse().ok())
        .unwrap_or(0)
}

fn named_number(found: &Match, name: &str) -> i64 {
    found
        .named()
        .into_iter()
        .find(|(group, _)| *group == name)
        .and_then(|(_, digits)| std::str::from_utf8(digits).ok())
        .and_then(|digits| digits.parse().ok())
        .unwrap_or(0)
}

fn named_text(found: &Match, name: &str) -> String {
    found
        .named()
        .into_iter()
        .find(|(group, _)| *group == name)
        .map(|(_, bytes)| bytes_to_string(bytes.to_vec()))
        .unwrap_or_default()
}

fn format_airdate(year: i64, month: i64, day: i64) -> String {
    let valid = i32::try_from(year).ok().and_then(|year| {
        NaiveDate::from_ymd_opt(year, u32::try_from(month).ok()?, u32::try_from(day).ok()?)
    });
    valid.map_or_else(String::new, |_| format!("{year:04}-{month:02}-{day:02}"))
}

/// A value over 12 decides the order. Otherwise dotted dates are read day-first and slashed or
/// dashed ones month-first.
fn day_month_airdate(first: i64, second: i64, year: i64, separator: &str) -> String {
    let (day, month) = if first > 12 || (second <= 12 && separator == ".") {
        (first, second)
    } else {
        (second, first)
    };
    format_airdate(year, month, day)
}

fn numbered(season: i64, episode: i64) -> SeasonEpisode {
    SeasonEpisode {
        season,
        episode: EpisodeNumber::Number(episode),
        airdate: None,
    }
}

fn whole_season(season: i64) -> SeasonEpisode {
    SeasonEpisode {
        season,
        episode: EpisodeNumber::All,
        airdate: None,
    }
}

fn aired(airdate: String) -> SeasonEpisode {
    SeasonEpisode {
        season: 0,
        episode: EpisodeNumber::Number(0),
        airdate: Some(airdate),
    }
}

fn parse_season_episode(name: &[u8]) -> Option<SeasonEpisode> {
    if let Some(hits) = SE_RANGE.captures(name) {
        return Some(numbered(number(&hits, 2), number(&hits, 3)));
    }
    if let Some(hits) = SE_DOUBLE.captures(name) {
        return Some(numbered(number(&hits, 2), number(&hits, 3)));
    }
    if let Some(hits) = SE_SINGLE.captures(name) {
        return Some(numbered(number(&hits, 2), number(&hits, 3)));
    }
    // Checked before a plain S01, which would otherwise swallow it.
    if let Some(hits) = SE_DISC.captures(name) {
        return Some(whole_season(number(&hits, 2)));
    }
    if let Some(hits) = SE_SEASON.captures(name) {
        return Some(whole_season(number(&hits, 2)));
    }
    if let Some(hits) = SE_X.captures(name) {
        return Some(numbered(number(&hits, 2), number(&hits, 3)));
    }
    if let Some(hits) = DATE_YMD.captures(name) {
        return Some(aired(format_airdate(
            named_number(&hits, "year"),
            named_number(&hits, "month"),
            named_number(&hits, "day"),
        )));
    }
    if let Some(hits) = DATE_DMY.captures(name) {
        return Some(aired(day_month_airdate(
            named_number(&hits, "first"),
            named_number(&hits, "second"),
            named_number(&hits, "year"),
            &named_text(&hits, "sep"),
        )));
    }
    if let Some(hits) = DATE_DMY_SHORT.captures(name) {
        let short = named_number(&hits, "year");
        let this_year = i64::from(chrono::Utc::now().year() % 100);
        let year = if short <= this_year + 1 {
            2000 + short
        } else {
            1900 + short
        };
        return Some(aired(day_month_airdate(
            named_number(&hits, "first"),
            named_number(&hits, "second"),
            year,
            &named_text(&hits, "sep"),
        )));
    }
    if let Some(hits) = YEAR_EPISODE.captures(name) {
        return Some(numbered(number(&hits, 2), number(&hits, 3)));
    }
    if let Some(hits) = YEAR_PART.captures(name) {
        return Some(numbered(
            number(&hits, 2) * 100 + number(&hits, 3),
            number(&hits, 4),
        ));
    }
    if let Some(hits) = PART.captures(name) {
        return Some(numbered(1, number(&hits, 2)));
    }
    if let Some(hits) = EPISODE_ONLY.captures(name) {
        return Some(numbered(1, number(&hits, 2)));
    }
    if let Some(hits) = ANIME_EPISODE.captures(name) {
        return Some(numbered(1, number(&hits, 1)));
    }
    if let Some(hits) = SEASON_WORD.captures(name) {
        return Some(whole_season(number(&hits, 2)));
    }
    None
}

fn parse_name(release: &[u8]) -> String {
    let clean = FANSUB_PREFIX.replace_all(release, b"");
    let name_of = |pattern: &Pcre| {
        pattern
            .captures(&clean)
            .map(|hits| named_text(&hits, "name"))
    };
    let mut name = name_of(&NAME_AFTER_NOISE)
        .or_else(|| name_of(&NAME_AT_START))
        .unwrap_or_default();
    if name.is_empty() || name == "0" {
        name = name_of(&NAME_BEFORE_SE)
            .or_else(|| name_of(&NAME_BEFORE_NUMBER))
            .or_else(|| name_of(&NAME_ANIME_TITLE))
            .or_else(|| name_of(&NAME_ANIME))
            .unwrap_or_default();
    }
    let name = STRIP_FOLLOWING.replace_all(name.as_bytes(), b" ");
    let name = LEADING_SIX_DIGITS.replace_all(&name, b"");
    let name = ACRONYM.replace_all(&name, |hits: &regex::bytes::Captures<'_>| {
        hits[1]
            .iter()
            .copied()
            .filter(|byte| *byte != b'.')
            .collect::<Vec<u8>>()
    });
    let name = PARENS_OR_DOTS.replace_all(&name, b" ");
    let name = SPACES.replace_all(&name, b" ");
    trim(&bytes_to_string(name)).to_owned()
}

fn parse_country(name: &str) -> String {
    let Some(hits) = COUNTRY.captures(name.as_bytes()) else {
        return String::new();
    };
    let found = bytes_to_string(hits.group(1).unwrap_or_default().to_vec());
    match found.to_ascii_lowercase().as_str() {
        "canada" => "CA".to_owned(),
        "australia" => "AU".to_owned(),
        "america" | "united states" => "US".to_owned(),
        "united kingdom" => "UK".to_owned(),
        _ => found.to_ascii_uppercase(),
    }
}

/// `cleanName`: a derived show name made easier to match.
pub fn clean_name(name: &str) -> String {
    let mut text = name.replace(['.', '_'], " ");
    for (from, to) in [
        (
            &['à', 'á', 'â', 'ã', 'ä', 'æ', 'À', 'Á', 'Â', 'Ã', 'Ä'][..],
            "a",
        ),
        (&['ç', 'Ç'][..], "c"),
        (&['Σ', 'è', 'é', 'ê', 'ë', 'È', 'É', 'Ê', 'Ë'][..], "e"),
        (&['ì', 'í', 'î', 'ï', 'Ì', 'Í', 'Î', 'Ï'][..], "i"),
        (&['ò', 'ó', 'ô', 'õ', 'ö', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö'][..], "o"),
        (&['ù', 'ú', 'û', 'ü', 'ū', 'Ú', 'Û', 'Ü', 'Ū'][..], "u"),
    ] {
        text = text.replace(from, to);
    }
    let text = text.replace('ß', "ss").replace('&', "and");
    let text = bytes_to_string(CHANNEL_PREFIX.replace_all(text.as_bytes(), b""));
    let text = text
        .replace(['\'', ':', '!', '"', '#', '*', ',', '(', ')', '?'], "")
        .replace('$', "s");
    let bytes = LANGUAGE_TAGS.replace_all(text.as_bytes(), b"");
    let bytes = SUBTITLE_NOISE.replace_all(&bytes, b"");
    let bytes = TRAILING_HYPHEN.replace_all(&bytes, b"");
    let bytes = TRAILING_EPISODE.replace_all(&bytes, b"");
    let bytes = TRAILING_HYPHEN_2.replace_all(&bytes, b"");
    let bytes = SPACES.replace_all(&bytes, b" ");
    let text = bytes_to_string(bytes);
    trim(text.trim_matches(['"', '-'])).to_owned()
}

/// Well-known daily and talk show titles, and German titles of popular shows, by their canonical
/// names.
pub fn normalize_show_title(clean: &str) -> String {
    let canonical = match trim(clean).to_ascii_lowercase().as_str() {
        "grits" => "Girls Raised in the South",
        "shield" => "Agents of S.H.I.E.L.D.",
        "stephen colbert"
        | "late show with stephen colbert"
        | "late show stephen colbert"
        | "the late show stephen colbert"
        | "colbert" => "The Late Show with Stephen Colbert",
        "daily show" | "the daily show" => "The Daily Show",
        "daily show with jon stewart" | "the daily show with jon stewart" => {
            "The Daily Show with Jon Stewart"
        }
        "daily show with trevor noah" | "the daily show with trevor noah" => {
            "The Daily Show with Trevor Noah"
        }
        "seth meyers" | "late night with seth meyers" | "late night seth meyers" => {
            "Late Night with Seth Meyers"
        }
        "jimmy kimmel" | "jimmy kimmel live" => "Jimmy Kimmel Live!",
        "the late late show with james corden"
        | "late late show with james corden"
        | "late show with james corden"
        | "james corden"
        | "late late show james corden" => "The Late Late Show with James Corden",
        "jimmy fallon"
        | "the tonight show starring jimmy fallon"
        | "tonight show with jimmy fallon"
        | "tonight show jimmy fallon" => "The Tonight Show Starring Jimmy Fallon",
        "oak island fluch und legende"
        | "oak island - fluch und legende"
        | "die schatzsucher von oak island" => "The Curse of Oak Island",
        "tierische freundschaften" => "Animal Odd Couples",
        "zwischen meer und maloche" => "Zwischen Meer und Maloche",
        "game of thrones das lied von eis und feuer" => "Game of Thrones",
        "die simpsons" => "The Simpsons",
        "die wilden siebziger" => "That 70s Show",
        "how i met your mother" => "How I Met Your Mother",
        "scrubs die anfanger" | "scrubs die anfaenger" => "Scrubs",
        "der prinz von bel-air" | "prinz von bel-air" | "der prinz von bel air" => {
            "The Fresh Prince of Bel-Air"
        }
        "alarm fur cobra 11" | "alarm fuer cobra 11" => "Alarm for Cobra 11",
        "soko" => "SOKO",
        "der bergdoktor" => "Der Bergdoktor",
        "das boot" => "Das Boot",
        "babylon berlin" => "Babylon Berlin",
        "dark" => "Dark",
        "tatort" => "Tatort",
        "polizeiruf 110" => "Polizeiruf 110",
        _ => return clean.to_owned(),
    };
    canonical.to_owned()
}

/// `parseInfo` then `ParsedTvRelease::fromParseInfo`. `None` when the name can't be parsed.
pub fn parse_release(release: &str) -> Option<ParsedRelease> {
    let name = parse_name(release.as_bytes());
    if name.is_empty() || name == "0" {
        return None;
    }
    let country = parse_country(&name);
    let cleaned = bytes_to_string(TRAILING_NUMBER.replace_all(clean_name(&name).as_bytes(), b""));
    let mut clean = normalize_show_title(&cleaned);
    let season_episode = parse_season_episode(release.as_bytes())?;
    let mut year = None;
    if season_episode.airdate.is_none()
        && let Some(hits) = YEAR.captures(release.as_bytes())
    {
        let found = named_number(&hits, "year");
        clean.push_str(&format!(" ({found})"));
        year = i32::try_from(found).ok();
    }
    let season_pack = season_episode.episode == EpisodeNumber::All;
    Some(ParsedRelease {
        name,
        clean_name: clean,
        country,
        season: Some(season_episode.season),
        episode: match season_episode.episode {
            EpisodeNumber::Number(episode) => Some(episode),
            EpisodeNumber::All => None,
        },
        season_pack,
        airdate: season_episode.airdate.filter(|airdate| !airdate.is_empty()),
        year,
    })
}

fn year_of(date: Option<&str>) -> Option<i32> {
    let hits = YEAR_PREFIX.captures(trim(date?).as_bytes())?;
    i32::try_from(named_number(&hits, "year")).ok()
}

/// A candidate's start date, which providers give as text or as a year.
#[derive(Clone, Copy, Debug)]
pub enum Started<'a> {
    None,
    Text(&'a str),
    Year(i64),
}

/// `scoreCandidate`: 0 means "not a candidate". Title similarity (exact = 100) plus a bonus when
/// the start year agrees. A year more than one off rejects a fuzzy match and demotes an exact one,
/// so "Doctor Who 2005" prefers the 2005 series. A matching country breaks remaining ties.
pub fn score_candidate(
    ours: &str,
    our_year: Option<i32>,
    candidate: &str,
    started: Started<'_>,
    our_country: &str,
    candidate_country: &str,
) -> f64 {
    let mut candidate_year = match started {
        Started::None => None,
        Started::Text(date) => year_of(Some(date)),
        Started::Year(year) => (year >= 1900).then(|| i32::try_from(year).ok()).flatten(),
    };
    let mut title = candidate.to_owned();
    if let Some(hits) = TITLE_WITH_YEAR.captures(trim(candidate).as_bytes()) {
        title = named_text(&hits, "title");
        candidate_year = candidate_year.or(i32::try_from(named_number(&hits, "year")).ok());
    }
    let ours = trim(ours).to_lowercase();
    let theirs = trim(&title).to_lowercase();
    if ours.is_empty() || theirs.is_empty() {
        return 0.0;
    }
    let exact = ours == theirs;
    let mut score = if exact {
        100.0
    } else {
        let percent = similar_text_percent(theirs.as_bytes(), ours.as_bytes());
        if percent >= MATCH_PROBABILITY {
            percent
        } else {
            0.0
        }
    };
    if score <= 0.0 {
        return 0.0;
    }
    if let (Some(our_year), Some(candidate_year)) = (our_year, candidate_year) {
        if (our_year - candidate_year).abs() <= 1 {
            score += 10.0;
        } else if !exact {
            return 0.0;
        } else {
            score -= 20.0;
        }
    }
    if !our_country.is_empty()
        && !candidate_country.is_empty()
        && country_key(our_country) == country_key(candidate_country)
    {
        score += 2.0;
    }
    score
}

fn country_key(country: &str) -> String {
    let code = trim(country).to_uppercase();
    if code == "UK" { "GB".to_owned() } else { code }
}

/// `AbstractTvProvider::normalizeImdbValue`.
pub fn normalize_imdb(value: &str) -> String {
    let value = normalize_text(value);
    if value.is_empty() || value == "0" {
        return String::new();
    }
    let digits = value
        .get(..2)
        .filter(|prefix| prefix.eq_ignore_ascii_case("tt"))
        .map_or(value.as_str(), |_| &value[2..]);
    if digits.len() >= 6 && digits.bytes().all(|byte| byte.is_ascii_digit()) {
        return digits.to_owned();
    }
    value
}

/// `normalizeOptionalString`.
pub fn normalize_text(value: &str) -> String {
    let value = trim(value);
    if value == "''" {
        String::new()
    } else {
        value.to_owned()
    }
}
