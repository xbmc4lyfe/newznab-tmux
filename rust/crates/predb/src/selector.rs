//! Selects a PreDB hit whose title or cleaned filename has the query's literal UTF-8 identity.
//! Backend discovery and ranking may be fuzzy; acceptance permits only letter case differences.

use std::collections::HashSet;
use std::sync::LazyLock;

use nntmux_categorize::mi;
use regex::Regex;

use crate::filename::{
    OriginalFilenameEvidence, clean_for_matching, exact_value, matches_predb_filename_evidence,
    normalize_candidate_title,
};

const MAX_RESULTS_TO_SCORE: usize = 50;
const MIN_ACCEPTABLE_SCORE: f64 = 60.0;
const NOISE_TOKENS: [&str; 48] = [
    "a", "an", "and", "audio", "audio_ts", "bup", "cd", "cover", "covers", "disc", "disk", "dvd",
    "file", "files", "folder", "ifo", "image", "images", "img", "jpg", "jpeg", "nfo", "of", "par2",
    "part", "pdf", "png", "proof", "rar", "sample", "screen", "screens", "sfv", "srr", "srs",
    "sub", "subs", "the", "thumb", "thumbs", "txt", "url", "video", "video_ts", "vob", "vts",
    "webp", "zip",
];

static NON_WORD: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"[^\p{L}\p{N}]+").expect("static pattern"));

/// A row a backend returned for a PreDB lookup.
#[derive(Clone, Debug, PartialEq)]
pub struct PredbHit {
    pub id: i64,
    pub title: String,
    pub filename: String,
    pub source: String,
}

pub fn select_best_match<'a>(
    query: &str,
    hits: &'a [PredbHit],
    original: Option<&OriginalFilenameEvidence>,
) -> Option<&'a PredbHit> {
    let query_identity = exact_value(query);
    let normalized_query = normalize_for_comparison(query);
    if !is_searchable_query(&normalized_query) {
        return None;
    }
    let query_tokens = meaningful_tokens(&normalized_query);
    if query_tokens.is_empty() {
        return None;
    }

    let mut literal_titles = Vec::new();
    let mut literal_filenames = Vec::new();
    let mut filename_titles = HashSet::new();
    for hit in hits.iter().take(MAX_RESULTS_TO_SCORE) {
        if hit.title.is_empty() {
            continue;
        }
        let title_identity = exact_value(&hit.title);
        if title_identity == query_identity {
            literal_titles.push(hit);
        } else if match original {
            Some(evidence) => evidence.accepts(&hit.title, &hit.filename),
            None => matches_predb_filename_evidence(&hit.filename, &query_identity),
        } {
            literal_filenames.push(hit);
            filename_titles.insert(title_identity);
        }
    }
    if literal_titles.is_empty() && filename_titles.len() != 1 {
        return None;
    }

    let candidates = if literal_titles.is_empty() {
        literal_filenames
    } else {
        literal_titles
    };
    let mut best = None;
    let mut best_score = 0.0;
    for hit in candidates {
        let score = score_hit(&normalized_query, &query_tokens, hit);
        if score > best_score {
            best_score = score;
            best = Some(hit);
        }
    }
    if best_score < MIN_ACCEPTABLE_SCORE {
        return None;
    }
    best
}

fn score_hit(query: &str, query_tokens: &[String], hit: &PredbHit) -> f64 {
    let title = normalize_for_comparison(&hit.title);
    let filename = normalize_predb_filename(&hit.filename);
    if title.is_empty() && filename.is_empty() {
        return 0.0;
    }
    let title_score = score_against_field(query, query_tokens, &title);
    let bonus = if filename.is_empty() { 0.0 } else { 5.0 };
    let mut score = title_score.max(score_against_field(query, query_tokens, &filename) + bonus);
    let query_marker = mi!(query, r"\bxxx\b");
    let candidate_marker = mi!(title, r"\bxxx\b") || mi!(filename, r"\bxxx\b");
    if query_marker != candidate_marker {
        score -= 30.0;
    }
    score.max(0.0)
}

fn score_against_field(query: &str, query_tokens: &[String], field: &str) -> f64 {
    if field.is_empty() {
        return 0.0;
    }
    let field_tokens = meaningful_tokens(field);
    let similarity = similarity_percent(query, field);
    let shared: Vec<&String> = query_tokens
        .iter()
        .filter(|t| field_tokens.contains(t))
        .collect();
    let overlap = shared.len() as f64 / query_tokens.len().max(1) as f64;
    let mut score = similarity * 0.6 + overlap * 45.0 + shared.len() as f64 * 6.0;
    if field == query {
        score += 80.0;
    } else if query.len() >= 8 && field.contains(query) {
        score += 18.0;
    }
    if shared.iter().map(|t| t.len()).max().unwrap_or(0) >= 8 {
        score += 8.0;
    }
    if shared.is_empty() {
        score -= 15.0;
    }
    score
}

fn normalize_predb_filename(filename: &str) -> String {
    let cleaned = clean_for_matching(filename, true).filter(|c| !c.is_empty());
    normalize_for_comparison(cleaned.as_deref().unwrap_or(filename))
}

pub fn normalize_for_comparison(value: &str) -> String {
    let lowered = normalize_candidate_title(value).to_lowercase();
    NON_WORD.replace_all(&lowered, " ").trim().to_owned()
}

fn is_searchable_query(normalized: &str) -> bool {
    if normalized.len() < 3
        || nntmux_categorize::ms!(normalized, r"^\d+$")
        || mi!(normalized, r"^(?:audio|video)[ ._-]?ts$")
        || mi!(normalized, r"^vts[ ._-]?\d+(?:[ ._-]\d+)?$")
        || mi!(normalized, r"^(?:image|img)\d*$")
        || mi!(normalized, r"^(?:cd|dvd|disc|disk|part|vol)\d*$")
    {
        return false;
    }
    let tokens = meaningful_tokens(normalized);
    !tokens.is_empty() && !(tokens.len() == 1 && tokens[0].len() < 5)
}

fn meaningful_tokens(normalized: &str) -> Vec<String> {
    let mut tokens: Vec<String> = Vec::new();
    for token in normalized.split_whitespace() {
        if NOISE_TOKENS.contains(&token)
            || mi!(token, r"^(?:cd|dvd|disc|disk|part|vol)\d*$")
            || nntmux_categorize::ms!(token, r"^\d{1,2}$")
            || token.len() < 2
            || tokens.iter().any(|seen| seen == token)
        {
            continue;
        }
        tokens.push(token.to_owned());
    }
    tokens
}

fn similarity_percent(left: &str, right: &str) -> f64 {
    if !left.is_empty() && left == right {
        return 100.0;
    }
    similar_text_percent(left.as_bytes(), right.as_bytes())
}

/// PHP `similar_text`, as a percentage.
pub fn similar_text_percent(left: &[u8], right: &[u8]) -> f64 {
    if left.is_empty() && right.is_empty() {
        return 0.0;
    }
    similar(left, right) as f64 * 2.0 * 100.0 / (left.len() + right.len()) as f64
}

fn similar(left: &[u8], right: &[u8]) -> usize {
    if left.is_empty() || right.is_empty() {
        return 0;
    }
    // php_similar_str: the first longest common substring in scan order.
    let (mut best, mut pos_left, mut pos_right) = (0, 0, 0);
    for i in 0..left.len() {
        for j in 0..right.len() {
            let mut length = 0;
            while i + length < left.len()
                && j + length < right.len()
                && left[i + length] == right[j + length]
            {
                length += 1;
            }
            if length > best {
                (best, pos_left, pos_right) = (length, i, j);
            }
        }
    }
    if best == 0 {
        return 0;
    }
    best + similar(&left[..pos_left], &right[..pos_right])
        + similar(&left[pos_left + best..], &right[pos_right + best..])
}
