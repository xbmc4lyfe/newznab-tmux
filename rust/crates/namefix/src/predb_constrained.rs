//! `ConstrainedPredbMatcher`: bounded identity suggestions from partial release names. A
//! suggestion is never proof of identity, so nothing here links a release to a PreDB row.
//! Its only caller is `releases:identity-suggestions`, which reports the result.

use std::collections::BTreeMap;

use nntmux_categorize::pcre::{PHP_WS, replace_all, trim_chars};
use nntmux_categorize::{ms, rx};
use nntmux_core::pcre::Pcre;

use crate::identity_key;

const LANGUAGES: [&str; 10] = [
    "english", "german", "french", "finnish", "japanese", "dutch", "spanish", "italian", "multi",
    "dual",
];
const EDITIONS: [&str; 7] = [
    "extended",
    "unrated",
    "directors",
    "theatrical",
    "remastered",
    "repack",
    "proper",
];
const SOURCES: [&str; 18] = [
    "bluray", "bdrip", "web", "webdl", "webrip", "hdtv", "x264", "x265", "h264", "h265", "hevc",
    "avc", "ddp", "dts", "hdr", "dovi", "aac", "mp4",
];
const STOP_WORDS: [&str; 5] = ["the", "a", "an", "and", "of"];

/// A name split into title words, typed markers (year, episode, resolution...) and the
/// technical words after the first marker or source tag.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Parsed {
    pub title: Vec<String>,
    pub markers: BTreeMap<String, String>,
    pub suffix: Vec<String>,
}

/// A PreDB row offered as a candidate.
#[derive(Clone, Debug, PartialEq)]
pub struct Hit {
    pub id: i64,
    pub title: String,
}

/// A candidate that cleared every constraint.
#[derive(Clone, Debug, PartialEq)]
pub struct Ranked {
    pub id: i64,
    pub score: f64,
    pub title: String,
}

/// The answer: `id` is `None` with the `reason` it abstained, or the chosen row.
#[derive(Clone, Debug, PartialEq)]
pub struct Suggestion {
    pub id: Option<i64>,
    pub reason: &'static str,
    pub score: Option<f64>,
    pub title: Option<String>,
    /// The top two when `reason` is `ambiguous`.
    pub ranked: Vec<Ranked>,
    /// The content-only suggestion when `reason` is `unresolved_release_variant`.
    pub suggestion: Option<Box<Suggestion>>,
}

impl Suggestion {
    fn none(reason: &'static str) -> Self {
        Self {
            id: None,
            reason,
            score: None,
            title: None,
            ranked: Vec::new(),
            suggestion: None,
        }
    }
}

fn letters_and_digits_only() -> &'static Pcre {
    static RE: std::sync::LazyLock<Pcre> =
        std::sync::LazyLock::new(|| Pcre::new(r"/[^\pL\pN]+/u").expect("static pattern"));
    &RE
}

/// `parse`: `None` for a name over 512 bytes, one the identity key refuses, or more than 64
/// words.
pub fn parse(name: &str) -> Option<Parsed> {
    if name.len() > 512 {
        return None;
    }
    let name = identity_key::normalize(name)?;
    let mut name = replace_all(rx!(r"\.(?:mkv|mp4|avi|rar|nfo|par2)$"), &name, "");
    let mut markers = BTreeMap::new();
    if let Some(group) = rx!(r"-([a-z0-9]{2,20})$").captures(name.as_bytes()) {
        let whole = group.get(0).map_or(0, |m| m.len());
        markers.insert(
            "group".to_owned(),
            String::from_utf8_lossy(&group[1]).into_owned(),
        );
        name.truncate(name.len() - whole);
    }
    let spaced = letters_and_digits_only().replace_all(name.as_bytes(), b" ");
    let spaced = String::from_utf8_lossy(&spaced).into_owned();
    // preg_split('/\s+/') of the trimmed text; only an empty text leaves an empty word.
    let trimmed = trim_chars(&spaced, PHP_WS);
    let tokens: Vec<&str> = if trimmed.is_empty() {
        vec![""]
    } else {
        trimmed.split(' ').filter(|word| !word.is_empty()).collect()
    };
    if tokens.len() > 64 {
        return None;
    }

    let mut title = Vec::new();
    let mut suffix = Vec::new();
    let mut after_boundary = false;
    for token in tokens {
        let mut token = token.to_owned();
        let key = if ms!(token, r"^(?:19|20)\d{2}$") {
            Some("year")
        } else if ms!(token, r"^s\d{1,2}(?:e\d{1,3})+$") {
            token = strip_leading_zeros(&token);
            Some("episode")
        } else if ms!(token, r"^s\d{1,2}$") {
            Some("season")
        } else if ms!(token, r"^(?:480|576|720|1080|2160)[pi]$") {
            Some("resolution")
        } else if LANGUAGES.contains(&token.as_str()) {
            Some("language")
        } else if EDITIONS.contains(&token.as_str()) {
            Some("edition")
        } else if token == "xxx" {
            Some("adult")
        } else {
            None
        };
        if let Some(key) = key {
            markers
                .entry(key.to_owned())
                .and_modify(|seen: &mut String| {
                    seen.push(' ');
                    seen.push_str(&token);
                })
                .or_insert(token);
            after_boundary = true;
            continue;
        }
        if SOURCES.contains(&token.as_str()) {
            after_boundary = true;
        }
        if after_boundary {
            suffix.push(token.clone());
        }
        if !after_boundary && !STOP_WORDS.contains(&token.as_str()) {
            title.push(token);
        }
    }
    Some(Parsed {
        title,
        markers,
        suffix,
    })
}

/// `preg_replace_callback('/\d+/', fn => (string)(int)$m[0])`: leading zeros off every number.
fn strip_leading_zeros(token: &str) -> String {
    let mut out = String::with_capacity(token.len());
    let mut digits = String::new();
    for c in token.chars().chain(std::iter::once(' ')) {
        if c.is_ascii_digit() {
            digits.push(c);
            continue;
        }
        if !digits.is_empty() {
            let trimmed = digits.trim_start_matches('0');
            out.push_str(if trimmed.is_empty() { "0" } else { trimmed });
            digits.clear();
        }
        out.push(c);
    }
    out.pop();
    out
}

/// PHP `levenshtein()`, by bytes with unit costs.
fn levenshtein(a: &str, b: &str) -> usize {
    let (a, b) = (a.as_bytes(), b.as_bytes());
    let mut row: Vec<usize> = (0..=b.len()).collect();
    for (i, &x) in a.iter().enumerate() {
        let mut diagonal = row[0];
        row[0] = i + 1;
        for (j, &y) in b.iter().enumerate() {
            let above = row[j + 1];
            row[j + 1] = (diagonal + usize::from(x != y))
                .min(above + 1)
                .min(row[j] + 1);
            diagonal = above;
        }
    }
    row[b.len()]
}

/// `ctype_digit` of a string: nonempty and all ASCII digits.
fn is_digits(value: &str) -> bool {
    !value.is_empty() && value.bytes().all(|b| b.is_ascii_digit())
}

/// PHP 8.4+ `round($value, 3)`.
fn round3(value: f64) -> f64 {
    let scaled = value * 1000.0;
    if !scaled.is_finite() || scaled.abs() >= 1e15 {
        return value;
    }
    let rounded = if scaled >= 0.0 {
        (scaled + 0.5).floor()
    } else {
        (scaled - 0.5).ceil()
    };
    rounded / 1000.0
}

/// `suggest`: the one candidate whose title words match the query's (allowing a couple of
/// typos in long words), with every marker the query states, and a clear margin over the next.
pub fn suggest(query: &str, hits: &[Hit]) -> Suggestion {
    if hits.len() > 50 {
        return Suggestion::none("candidate_overflow");
    }
    let q = match parse(query) {
        Some(q) if q.title.len() >= 2 && q.title.concat().len() >= 9 => q,
        _ => return Suggestion::none("insufficient_title"),
    };
    if !q.markers.contains_key("year") && !q.markers.contains_key("episode") {
        return Suggestion::none("missing_identity_anchor");
    }

    // Keyed by row id: a repeated id keeps its first position and takes the later values.
    let mut ranked: Vec<Ranked> = Vec::new();
    'hits: for hit in hits {
        if hit.id <= 0 {
            continue;
        }
        let Some(c) = parse(&hit.title) else {
            continue;
        };
        if c.title.len() != q.title.len() {
            continue;
        }
        for (key, value) in &q.markers {
            if c.markers.get(key) != Some(value) {
                continue 'hits;
            }
        }
        // Missing cut/language/adult metadata must not silently select a specific variant.
        if !q.suffix.is_empty() && q.suffix != c.suffix {
            continue;
        }
        for key in ["year", "edition", "language", "adult"] {
            if c.markers.get(key) != q.markers.get(key) {
                continue 'hits;
            }
        }
        let (mut sum, mut exact, mut edits) = (0.0_f64, 0, 0);
        for (token, candidate) in q.title.iter().zip(&c.title) {
            let distance = levenshtein(token, candidate);
            if distance == 0 {
                exact += 1;
                sum += 1.0;
                continue;
            }
            // Numeric sequel identity and short words never receive edit tolerance.
            let length = token.len().max(candidate.len());
            if is_digits(token)
                || is_digits(candidate)
                || token.len().min(candidate.len()) < 5
                || distance > if length >= 10 { 2 } else { 1 }
            {
                continue 'hits;
            }
            edits += distance;
            sum += 1.0 - distance as f64 / length as f64;
        }
        let score = 100.0 * sum / q.title.len() as f64;
        if exact < 1 || edits > 2 || score < 90.0 {
            continue;
        }
        let entry = Ranked {
            id: hit.id,
            score: round3(score),
            title: hit.title.clone(),
        };
        match ranked.iter_mut().find(|seen| seen.id == hit.id) {
            Some(slot) => *slot = entry,
            None => ranked.push(entry),
        }
    }
    // usort is stable: equal scores keep their order.
    ranked.sort_by(|a, b| b.score.total_cmp(&a.score));
    let Some(best) = ranked.first() else {
        return Suggestion::none("constraints_or_score");
    };
    if let Some(second) = ranked.get(1)
        && best.score - second.score < 8.0
    {
        return Suggestion {
            ranked: ranked.into_iter().take(2).collect(),
            ..Suggestion::none("ambiguous")
        };
    }
    Suggestion {
        id: Some(best.id),
        score: Some(best.score),
        title: Some(best.title.clone()),
        ..Suggestion::none("constrained_match")
    }
}

/// `completeEvidence`: [`suggest`], accepted only when the markers and the technical words
/// after them agree completely. Even then it is a suggestion, never global identity proof.
pub fn complete_evidence(query: &str, hits: &[Hit]) -> Suggestion {
    let result = suggest(query, hits);
    let Some(title) = result.title.as_deref() else {
        return result;
    };
    let (Some(q), Some(c)) = (parse(query), parse(title)) else {
        return Suggestion::none("invalid_identity");
    };
    if q.markers != c.markers || q.suffix != c.suffix {
        return Suggestion {
            suggestion: Some(Box::new(result)),
            ..Suggestion::none("unresolved_release_variant")
        };
    }
    result
}

#[cfg(test)]
mod tests {
    use super::*;

    fn hit(id: i64, title: &str) -> Hit {
        Hit {
            id,
            title: title.to_owned(),
        }
    }

    #[test]
    fn a_partial_typo_gives_a_suggestion_but_not_release_identity() {
        let hits = [hit(
            1,
            "Kanata.Kara.S01E02.1080p.AMZN.WEB-DL.JPN.DDP2.0.H.264-ToonsHub",
        )];
        assert_eq!(suggest("kazata.kara.s1e2", &hits).id, Some(1));
        assert_eq!(complete_evidence("kazata.kara.s1e2", &hits).id, None);
        let full = "Kazata.Kara.S01E02.1080p.AMZN.WEB-DL.JPN.DDP2.0.H.264-ToonsHub";
        assert_eq!(complete_evidence(full, &hits).id, Some(1));
    }

    #[test]
    fn a_changed_marker_rejects_the_candidate() {
        let query = "Known.Movie.2026.S01E02.1080p.AMZN.WEB-DL.REPACK-GRP";
        for (from, to) in [
            ("2026", "2025"),
            ("S01E02", "S01E03"),
            ("1080p", "720p"),
            ("AMZN", "DSNP"),
            ("REPACK", "PROPER"),
            ("-GRP", "-OTHER"),
        ] {
            let hits = [hit(1, &query.replace(from, to))];
            assert_eq!(suggest(query, &hits).id, None, "{from} -> {to}");
        }
    }

    #[test]
    fn neighbours_overflow_and_numeric_sequels_abstain() {
        let hits = [
            hit(1, "Known.Movie.2026.1080p.WEB-DL-GRP"),
            hit(2, "Known.Movie.2026.720p.WEB-DL-GRP"),
        ];
        assert_eq!(suggest("Known.Movie.2026", &hits).id, None);
        assert_eq!(complete_evidence("Known.Movie.2026", &hits[..1]).id, None);
        let many = vec![hit(1, "Known.Movie.2026"); 51];
        assert_eq!(
            suggest("Known.Movie.2026", &many).reason,
            "candidate_overflow"
        );
        assert_eq!(
            suggest("Known.Movie.2.2026", &[hit(1, "Known.Movie.3.2026")]).id,
            None
        );
        for word in ["Sample", "Cover"] {
            let query = format!("Known.{word}.Movie.2026.1080p.WEB-DL-GRP");
            assert_eq!(
                suggest(&query, &[hit(1, "Known.Movie.2026.1080p.WEB-DL-GRP")]).id,
                None
            );
        }
    }

    #[test]
    fn episode_numbers_lose_their_leading_zeros() {
        let parsed = parse("Show.Name.S01E02E03.720p-GRP").unwrap();
        assert_eq!(parsed.title, ["show", "name"]);
        assert_eq!(parsed.markers["episode"], "s1e2e3");
        assert_eq!(parsed.markers["group"], "grp");
        assert_eq!(parsed.markers["resolution"], "720p");
        assert_eq!(strip_leading_zeros("s00e000"), "s0e0");
    }

    #[test]
    fn levenshtein_counts_bytes() {
        assert_eq!(levenshtein("kitten", "sitting"), 3);
        assert_eq!(levenshtein("", "abc"), 3);
        assert_eq!(levenshtein("é", "e"), 2);
    }
}
