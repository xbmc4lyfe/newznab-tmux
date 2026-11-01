//! Turns release names and queries into the words the index holds.
//!
//! Names write the same thing many ways: `S01E02`, `S01.E02`, `S1E2` and `1x02`, or `H.264` and
//! `H264`, or `It's` and `Its`. A release keeps its literal words and gains the canonical form of
//! each of these, and a query asks for the canonical form only, so any spelling finds any other.
//! Episodes stay exact: `S01E02` never matches `S01E03`. An episode is also indexed under its
//! season, so `S01` finds the season's episodes as well as its packs.

use std::sync::OnceLock;
use tantivy::tokenizer::{
    AsciiFoldingFilter, LowerCaser, SimpleTokenizer, TextAnalyzer, TokenStream,
};

/// A parsed `q`: words that must all appear, and groups of words that must not appear together.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ParsedQuery {
    pub must: Vec<String>,
    pub must_not: Vec<Vec<String>>,
}

/// The words a release name is indexed under, sorted and without duplicates.
pub fn index_words(name: &str) -> Vec<String> {
    let mut out = words(name);
    if name.contains(is_apostrophe) {
        out.extend(words(&without_apostrophes(name)));
    }
    let canonical = canonical_forms(&out);
    out.extend(canonical);
    out.sort_unstable();
    out.dedup();
    out
}

pub fn parse_query(text: &str) -> ParsedQuery {
    let mut wanted = String::with_capacity(text.len());
    let mut must_not = Vec::new();
    for chunk in text.split_whitespace() {
        let excluded = ["--", "-", "!"].iter().find_map(|p| chunk.strip_prefix(p));
        match excluded.map(query_words) {
            Some(group) if !group.is_empty() => must_not.push(group),
            _ => {
                wanted.push_str(chunk);
                wanted.push(' ');
            }
        }
    }
    let mut must = query_words(&wanted);
    must.sort_unstable();
    must.dedup();
    ParsedQuery { must, must_not }
}

fn words(text: &str) -> Vec<String> {
    static ANALYZER: OnceLock<TextAnalyzer> = OnceLock::new();
    let mut analyzer = ANALYZER
        .get_or_init(|| {
            TextAnalyzer::builder(SimpleTokenizer::default())
                .filter(LowerCaser)
                .filter(AsciiFoldingFilter)
                .build()
        })
        .clone();
    let mut stream = analyzer.token_stream(text);
    let mut out = Vec::new();
    while let Some(token) = stream.next() {
        out.push(token.text.clone());
    }
    out
}

fn is_apostrophe(c: char) -> bool {
    matches!(c, '\'' | '\u{2019}' | '`' | '\u{b4}')
}

fn without_apostrophes(text: &str) -> String {
    text.chars().filter(|c| !is_apostrophe(*c)).collect()
}

/// The canonical words a name's literal words imply.
fn canonical_forms(words: &[String]) -> Vec<String> {
    let mut out = Vec::new();
    for (i, word) in words.iter().enumerate() {
        let next = words.get(i + 1).map(String::as_str);
        if let Some((season, episodes)) = parse_episodes(word) {
            out.extend(episodes.into_iter().map(|e| episode_word(season, e)));
            // `S03E21-E22` covers both episodes.
            if let Some(e) = next.and_then(bare_episode) {
                out.push(episode_word(season, e));
            }
            out.push(season_word(season));
        } else if let Some((season, e)) = parse_cross(word) {
            out.push(episode_word(season, e));
            out.push(season_word(season));
        } else if let (Some(season), Some(e)) = (bare_season(word), next.and_then(bare_episode)) {
            out.push(episode_word(season, e));
            out.push(season_word(season));
        } else if let Some(season) = bare_season(word) {
            out.push(season_word(season));
        } else if let Some(codec) = next.and_then(|n| codec_word(word, n)) {
            out.push(codec);
        }
    }
    out
}

/// A query's words, with each spelling replaced by its canonical form.
fn query_words(text: &str) -> Vec<String> {
    let literal = words(&without_apostrophes(text));
    let mut out = Vec::with_capacity(literal.len());
    let mut i = 0;
    while i < literal.len() {
        let word = literal[i].as_str();
        let next = literal.get(i + 1).map(String::as_str);
        let mut pair = false;
        if let Some((season, episodes)) = parse_episodes(word) {
            out.extend(episodes.into_iter().map(|e| episode_word(season, e)));
            if let Some(e) = next.and_then(bare_episode) {
                out.push(episode_word(season, e));
                pair = true;
            }
        } else if let Some((season, e)) = parse_cross(word) {
            out.push(episode_word(season, e));
        } else if let (Some(season), Some(e)) = (bare_season(word), next.and_then(bare_episode)) {
            out.push(episode_word(season, e));
            pair = true;
        } else if let Some(season) = bare_season(word) {
            out.push(season_word(season));
        } else if let Some(codec) = next.and_then(|n| codec_word(word, n)) {
            out.push(codec);
            pair = true;
        } else {
            out.push(word.to_string());
        }
        i += if pair { 2 } else { 1 };
    }
    out
}

fn episode_word(season: u32, episode: u32) -> String {
    format!("s{season:02}e{episode:02}")
}

fn season_word(season: u32) -> String {
    format!("s{season:02}")
}

fn codec_word(first: &str, second: &str) -> Option<String> {
    (first == "h" && matches!(second, "264" | "265")).then(|| format!("h{second}"))
}

fn number(digits: &str) -> Option<u32> {
    let valid = (1..=4).contains(&digits.len()) && digits.bytes().all(|b| b.is_ascii_digit());
    valid.then(|| digits.parse().ok()).flatten()
}

/// `s01` as a season number.
fn bare_season(word: &str) -> Option<u32> {
    number(word.strip_prefix('s')?)
}

/// `e02` as an episode number.
fn bare_episode(word: &str) -> Option<u32> {
    number(word.strip_prefix('e')?)
}

/// `s01e02` or `s01e02e03` as a season and its episodes.
fn parse_episodes(word: &str) -> Option<(u32, Vec<u32>)> {
    let mut parts = word.strip_prefix('s')?.split('e');
    let season = number(parts.next()?)?;
    let episodes = parts.map(number).collect::<Option<Vec<_>>>()?;
    (!episodes.is_empty()).then_some((season, episodes))
}

/// `1x02` as a season and episode. Two or more episode digits keep out `4x4` and similar.
fn parse_cross(word: &str) -> Option<(u32, u32)> {
    let (season, episode) = word.split_once('x')?;
    if season.len() > 2 || !(2..=3).contains(&episode.len()) {
        return None;
    }
    Some((number(season)?, number(episode)?))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn has(name: &str, word: &str) -> bool {
        index_words(name).iter().any(|w| w == word)
    }

    #[test]
    fn episode_spellings_share_a_canonical_word() {
        for name in [
            "Show.S01E02.1080p",
            "Show.S01.E02.1080p",
            "Show S1E2 1080p",
            "Show.1x02.1080p",
            "Show.S01E002.1080p",
        ] {
            assert!(has(name, "s01e02"), "{name}");
        }
        for query in ["show s01e02", "show S01.E02", "show s1e2", "show 1x02"] {
            assert_eq!(parse_query(query).must, ["s01e02", "show"], "{query}");
        }
    }

    #[test]
    fn a_season_finds_its_episodes_and_packs() {
        for name in [
            "Show.S03E02.1080p",
            "Show.S3E2.1080p",
            "Show.3x02.1080p",
            "Show.S03.E02.1080p",
            "Show.S03.1080p",
            "Show.S3.1080p",
        ] {
            assert!(has(name, "s03"), "{name}");
        }
        for query in ["show s03", "show S3", "show s003"] {
            assert_eq!(parse_query(query).must, ["s03", "show"], "{query}");
        }
        assert!(!has("Show.S03E02.1080p", "s02"));
    }

    #[test]
    fn episodes_stay_exact() {
        assert!(!has("Show.S01E03.1080p", "s01e02"));
        assert!(!has("Show.S02E01.1080p", "s01e01"));
    }

    #[test]
    fn multi_episode_names_match_each_episode() {
        for name in ["Show.S03E21-E22.1080p", "Show.S03E21E22.1080p"] {
            assert!(has(name, "s03e21"), "{name}");
            assert!(has(name, "s03e22"), "{name}");
        }
    }

    #[test]
    fn three_digit_episodes_keep_their_digits() {
        assert!(has("Show.S01E145.1080p", "s01e145"));
        assert_eq!(parse_query("show s01e145").must, ["s01e145", "show"]);
    }

    #[test]
    fn resolutions_are_not_episodes() {
        assert_eq!(parse_cross("1920x1080"), None);
        assert_eq!(parse_cross("4x4"), None);
        assert_eq!(parse_query("1080p").must, ["1080p"]);
    }

    #[test]
    fn codec_spellings_share_a_canonical_word() {
        assert!(has("Show.H.264-GRP", "h264"));
        assert!(has("Show.H264-GRP", "h264"));
        assert_eq!(parse_query("H.265").must, ["h265"]);
        assert_eq!(parse_query("h265").must, ["h265"]);
    }

    #[test]
    fn apostrophes_match_with_or_without() {
        let name = "It's.Okay.S01E01";
        assert!(has(name, "its"));
        assert!(has(name, "it"));
        assert_eq!(parse_query("it's okay").must, ["its", "okay"]);
        assert_eq!(parse_query("its okay").must, ["its", "okay"]);
    }

    #[test]
    fn accents_fold_and_case_lowers() {
        assert!(has("Am\u{e9}lie.2001", "amelie"));
        assert_eq!(parse_query("AM\u{c9}LIE").must, ["amelie"]);
    }

    #[test]
    fn exclusions_cover_each_prefix() {
        for query in ["show -cam", "show --cam", "show !cam"] {
            let parsed = parse_query(query);
            assert_eq!(parsed.must, ["show"], "{query}");
            assert_eq!(parsed.must_not, [["cam"]], "{query}");
        }
    }

    #[test]
    fn a_hyphen_inside_a_word_is_not_an_exclusion() {
        let parsed = parse_query("WEB-DL - x");
        assert_eq!(parsed.must, ["dl", "web", "x"]);
        assert!(parsed.must_not.is_empty());
    }

    #[test]
    fn a_hyphenated_exclusion_excludes_the_words_together() {
        assert_eq!(parse_query("show -WEB-DL").must_not, [["web", "dl"]]);
    }

    #[test]
    fn an_empty_query_has_no_words() {
        assert_eq!(parse_query("  "), ParsedQuery::default());
    }
}
