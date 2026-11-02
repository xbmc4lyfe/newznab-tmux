//! Identification helpers: the OpenSubtitles hash, a chapter-timing signature and the people
//! named in commentary track titles.

use crate::matroska::{Edition, Header, TrackKind};
use sha2::{Digest, Sha256};

/// Bytes hashed at each end of the file.
pub const OSHASH_CHUNK: usize = 64 * 1024;

/// The OpenSubtitles "moviehash": the file size plus the wrapping sum of the little-endian u64
/// words of the first and last 64 KiB. Needs the exact inner file size and both chunks whole.
pub fn opensubtitles_hash(size: u64, head: &[u8], tail: &[u8]) -> Option<String> {
    if size < OSHASH_CHUNK as u64 || head.len() < OSHASH_CHUNK || tail.len() < OSHASH_CHUNK {
        return None;
    }
    let sum = |chunk: &[u8]| {
        chunk[..OSHASH_CHUNK]
            .as_chunks::<8>()
            .0
            .iter()
            .fold(0u64, |acc, w| acc.wrapping_add(u64::from_le_bytes(*w)))
    };
    let tail = &tail[tail.len() - OSHASH_CHUNK..];
    Some(format!(
        "{:016x}",
        size.wrapping_add(sum(head)).wrapping_add(sum(tail))
    ))
}

/// The edition players pick: the flagged default, otherwise the first one shown.
pub fn default_edition(header: &Header) -> Option<&Edition> {
    header
        .editions
        .iter()
        .find(|e| e.default && !e.hidden)
        .or_else(|| header.editions.iter().find(|e| !e.hidden))
}

/// A hash of the default edition's chapter start times, rounded to 100 ms so remuxes of the
/// same disc agree. `None` with fewer than three chapters, which says too little.
pub fn chapter_signature(header: &Header) -> Option<String> {
    let edition = default_edition(header)?;
    let starts: Vec<u64> = edition
        .chapters
        .iter()
        .filter(|c| !c.hidden)
        .map(|c| c.start_ns.saturating_add(50_000_000) / 100_000_000)
        .collect();
    if starts.len() < 3 {
        return None;
    }
    let mut hasher = Sha256::new();
    for start in &starts {
        hasher.update(start.to_le_bytes());
    }
    Some(hex::encode(&hasher.finalize()[..8]))
}

/// True when a chapter name says more than its number ("Chapter 01", "Chapitre 3", "12").
pub fn chapter_name_is_meaningful(name: &str) -> bool {
    let lower = name.trim().to_lowercase();
    if lower
        .chars()
        .all(|c| c.is_ascii_digit() || c == ':' || c == '.' || c.is_whitespace())
    {
        return false;
    }
    let generic = [
        "chapter",
        "chapitre",
        "kapitel",
        "capitolo",
        "capítulo",
        "hoofdstuk",
    ]
    .iter()
    .any(|word| {
        lower.strip_prefix(word).is_some_and(|rest| {
            let rest = rest.trim();
            !rest.is_empty() && rest.chars().all(|c| c.is_ascii_digit())
        })
    });
    !generic
}

/// A commentary track and the people its title names.
#[derive(Clone, Debug, PartialEq, Eq, serde::Serialize)]
pub struct Commentary {
    pub track: u64,
    pub title: Option<String>,
    pub people: Vec<String>,
}

/// Audio tracks flagged as commentary or titled so.
pub fn commentaries(header: &Header) -> Vec<Commentary> {
    header
        .tracks
        .iter()
        .filter(|t| t.kind == TrackKind::Audio)
        .filter(|t| {
            t.commentary
                || t.name
                    .as_deref()
                    .is_some_and(|n| n.to_lowercase().contains("comment"))
        })
        .map(|t| Commentary {
            track: t.number,
            title: t.name.clone(),
            people: t.name.as_deref().map(people_in).unwrap_or_default(),
        })
        .collect()
}

const ROLE_WORDS: &[&str] = &[
    "commentary",
    "audio",
    "director",
    "directors",
    "writer",
    "writers",
    "producer",
    "producers",
    "executive",
    "co-writer",
    "screenwriter",
    "cinematographer",
    "editor",
    "composer",
    "actor",
    "actors",
    "actress",
    "star",
    "stars",
    "cast",
    "crew",
    "creator",
    "showrunner",
    "visual",
    "effects",
    "supervisor",
    "production",
    "designer",
    "film",
    "historian",
    "critic",
    "the",
    "with",
    "by",
    "featuring",
    "feat",
    "and",
    "of",
    "from",
    "track",
    "dolby",
    "stereo",
    "english",
];

/// Lowercase words that can sit inside a name ("Guillermo del Toro").
const NAME_PARTICLES: &[&str] = &[
    "de", "del", "della", "der", "di", "da", "van", "von", "le", "la", "du", "bin", "al",
];

/// Personal names in a commentary title, e.g. "Commentary with director Jane Doe and
/// cinematographer John Roe" gives `["Jane Doe", "John Roe"]`. A name is two to four
/// capitalized words that aren't role words.
pub fn people_in(title: &str) -> Vec<String> {
    let mut people = Vec::new();
    let mut current: Vec<&str> = Vec::new();
    let flush = |current: &mut Vec<&str>, people: &mut Vec<String>| {
        while current
            .last()
            .is_some_and(|w| NAME_PARTICLES.contains(&w.to_lowercase().as_str()))
        {
            current.pop();
        }
        if (2..=4).contains(&current.len()) {
            let name = current.join(" ");
            if !people.contains(&name) {
                people.push(name);
            }
        }
        current.clear();
    };
    for raw in title.split(|c: char| {
        c.is_whitespace() || matches!(c, ',' | ';' | '&' | '/' | '(' | ')' | ':' | '+')
    }) {
        let word =
            raw.trim_matches(|c: char| !c.is_alphanumeric() && c != '\'' && c != '-' && c != '.');
        if word.is_empty() {
            flush(&mut current, &mut people);
            continue;
        }
        let lower = word.to_lowercase();
        let capitalized = word.chars().next().is_some_and(char::is_uppercase);
        let initial = word.len() == 2 && word.ends_with('.');
        let particle = !current.is_empty() && NAME_PARTICLES.contains(&lower.as_str());
        if particle {
            current.push(word);
        } else if ROLE_WORDS.contains(&lower.as_str()) || !(capitalized || initial) {
            flush(&mut current, &mut people);
        } else {
            current.push(word);
        }
    }
    flush(&mut current, &mut people);
    people
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn oshash_matches_the_reference_algorithm() {
        // All-zero chunks: the hash is the size alone.
        let zeros = vec![0u8; OSHASH_CHUNK];
        assert_eq!(
            opensubtitles_hash(1_000_000, &zeros, &zeros).as_deref(),
            Some("00000000000f4240")
        );
        // One word of 1 in each chunk adds 2.
        let mut one = zeros.clone();
        one[0] = 1;
        assert_eq!(
            opensubtitles_hash(1_000_000, &one, &one).as_deref(),
            Some("00000000000f4242")
        );
        // The tail is taken from the end of what was passed.
        let mut tail = vec![9u8; 10];
        tail.extend(&one);
        assert_eq!(
            opensubtitles_hash(1_000_000, &one, &tail).as_deref(),
            Some("00000000000f4242")
        );
        assert_eq!(opensubtitles_hash(10, &zeros, &zeros), None);
    }

    #[test]
    fn people_in_commentary_titles() {
        assert_eq!(
            people_in("Commentary with director Jane Doe and cinematographer John Roe"),
            vec!["Jane Doe", "John Roe"]
        );
        assert_eq!(
            people_in("Audio Commentary by Writer/Director Guillermo del Toro"),
            vec!["Guillermo del Toro"]
        );
        assert_eq!(people_in("Commentary"), Vec::<String>::new());
        assert_eq!(
            people_in("Commentary: J.J. Abrams, Bryan Burk"),
            vec!["J.J. Abrams", "Bryan Burk"]
        );
    }

    #[test]
    fn meaningful_chapter_names() {
        assert!(!chapter_name_is_meaningful("Chapter 01"));
        assert!(!chapter_name_is_meaningful("Chapitre 3"));
        assert!(!chapter_name_is_meaningful("00:12:30.000"));
        assert!(!chapter_name_is_meaningful("12"));
        assert!(chapter_name_is_meaningful("Opening Credits"));
        assert!(chapter_name_is_meaningful("The Heist"));
        assert!(chapter_name_is_meaningful("Chapter of Fire"));
    }
}
