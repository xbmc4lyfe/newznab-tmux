//! Hash and gibberish detection. Runs first, because a hashed name must never be categorized
//! by its group or by a stray token.

use super::{Ctx, category as cat, hit};
use crate::names::*;
use crate::{Hit, mi, ms};

/// What the safety-net pipe needs to know about a name's media signals.
#[derive(Clone, Debug, Default)]
pub struct Signals {
    pub core_name: String,
    pub markers: Vec<&'static str>,
    pub low_signal: bool,
}

pub fn inspect_signals(name: &str) -> Signals {
    let core = core_name(&strip_extensions_for_analysis(name));
    let mut markers = Vec::new();
    let mut mark = |found: bool, marker: &'static str| {
        if found {
            markers.push(marker);
        }
    };
    mark(mi!(name, r"\bS\d{1,3}[._ -]?E\d{1,4}\b"), "season_episode");
    mark(mi!(name, r"\bS\d{1,3}\b"), "season_pack");
    mark(
        mi!(name, r"\b(480p|576p|720p|1080[pi]?|2160p|4k|uhd)\b"),
        "resolution",
    );
    mark(
        mi!(name, r"\b(x264|x265|h\.?264|h\.?265|hevc|xvid|av1)\b"),
        "codec",
    );
    mark(
        mi!(
            name,
            r"\b(bluray|bdrip|brrip|hdtv|web[._ -]?dl|web[._ -]?rip|dvdrip|remux)\b"
        ),
        "source",
    );
    mark(mi!(name, r"\b(aac|ac3|ddp|dts|flac|mp3)\b"), "audio");
    mark(
        mi!(
            name,
            r"\b(proper|repack|internal|limited|complete|dubbed|subbed|readnfo)\b"
        ),
        "scene_tag",
    );
    mark(ms!(name, r"\b(19|20)\d{2}\b"), "year");
    mark(
        ms!(name, r"-[A-Za-z0-9][A-Za-z0-9._-]{1,20}$"),
        "release_group",
    );
    mark(
        mi!(
            name,
            r"\.(mkv|avi|mp4|mp3|flac|iso|epub|pdf|exe|nzb|rar|7z)$"
        ),
        "known_extension",
    );

    let low_signal = markers.is_empty()
        && ms!(core, r"^[A-Za-z0-9+/_=-]+$")
        && core.len() >= 12
        && !has_strong_word_structure(name, &core);
    Signals {
        core_name: core,
        markers,
        low_signal,
    }
}

pub fn categorize(ctx: &Ctx) -> Option<Hit> {
    let name = ctx.name;
    if let Some(found) = hash(name) {
        return Some(found);
    }
    let signals = inspect_signals(name);
    if is_zero_vowel_long_token(&signals.core_name, 20) {
        return Some(hit(cat::OTHER_HASHED, 0.78, "gibberish_zero_vowels"));
    }
    obfuscated(name, &signals)
        .or_else(|| low_signal(name, &signals))
        .or_else(|| gibberish(name, &signals.core_name))
        .or_else(|| {
            mi!(
                name,
                r"\.(zip|rar|7z|tar|gz|bz2|xz|tgz|tbz2|cab|iso|img|dmg|pkg|archive)$"
            )
            .then_some(hit(cat::OTHER_MISC, 0.5, "archive"))
        })
        .or_else(|| dataset(name))
}

fn hash(name: &str) -> Option<Hit> {
    if is_bounded_md5(name) {
        return Some(hit(cat::OTHER_HASHED, 0.95, "hash_md5"));
    }
    if is_bounded_sha1(name) {
        return Some(hit(cat::OTHER_HASHED, 0.95, "hash_sha1"));
    }
    if is_bounded_sha256(name) {
        return Some(hit(cat::OTHER_HASHED, 0.95, "hash_sha256"));
    }
    if is_bounded_generic_hash(name) {
        return Some(hit(cat::OTHER_HASHED, 0.95, "hash_generic"));
    }
    if is_base64_like(name) {
        return Some(hit(cat::OTHER_HASHED, 0.9, "hash_base64_like"));
    }
    let core = core_name(&strip_extensions_for_analysis(name));
    if is_uuid(&core) {
        return Some(hit(cat::OTHER_HASHED, 0.95, "hash_uuid"));
    }
    if is_pure_hex(&core) {
        return Some(hit(cat::OTHER_HASHED, 0.95, "hash_hex"));
    }
    None
}

fn obfuscated(name: &str, signals: &Signals) -> Option<Hit> {
    if is_obfuscated_uppercase(name) {
        return Some(hit(cat::OTHER_HASHED, 0.7, "obfuscated_uppercase"));
    }
    if is_obfuscated_mixed(name) {
        return Some(hit(cat::OTHER_HASHED, 0.7, "obfuscated_mixed_alphanumeric"));
    }
    if is_obfuscated_usenet_filename(name) {
        return Some(hit(cat::OTHER_HASHED, 0.85, "obfuscated_usenet_filename"));
    }
    if is_obfuscated_punctuation(name) {
        let hash_like = is_base64_like(name)
            || is_bounded_generic_hash(name)
            || is_zero_vowel_long_token(&signals.core_name, 12)
            || signals.low_signal;
        return Some(if hash_like {
            hit(cat::OTHER_HASHED, 0.75, "obfuscated_pattern")
        } else {
            hit(cat::OTHER_MISC, 0.5, "obfuscated_pattern")
        });
    }
    None
}

fn gibberish(name: &str, core: &str) -> Option<Hit> {
    if is_random_by_character_analysis(core, name) {
        return Some(hit(cat::OTHER_HASHED, 0.75, "gibberish_random_transitions"));
    }
    if has_insufficient_word_structure(core) {
        return Some(hit(cat::OTHER_HASHED, 0.75, "gibberish_no_word_structure"));
    }
    if is_random_digit_pattern(core) {
        return Some(hit(cat::OTHER_HASHED, 0.7, "gibberish_random_digits"));
    }
    if is_zero_vowel_long_token(core, 20) {
        return Some(hit(cat::OTHER_HASHED, 0.78, "gibberish_zero_vowels"));
    }
    None
}

fn low_signal(name: &str, signals: &Signals) -> Option<Hit> {
    let core = &signals.core_name;
    if has_strong_word_structure(name, core) || is_zero_vowel_long_token(core, 20) {
        return None;
    }
    (signals.low_signal && core.len() >= 20).then_some(hit(
        cat::OTHER_HASHED,
        0.8,
        "gibberish_no_signal",
    ))
}

fn has_strong_word_structure(name: &str, core: &str) -> bool {
    max_consecutive_letters(core) >= 5
        && has_normal_vowel_ratio(core)
        && alphabetic_word_tokens(name) >= 2
}

fn alphabetic_word_tokens(name: &str) -> usize {
    let stripped = strip_extensions_for_analysis(name);
    crate::rx!(r"[.\s_-]+")
        .split(stripped.as_bytes())
        .filter(|token| crate::rx!(i r"[a-z]{3,}").is_match(token))
        .count()
}

fn dataset(name: &str) -> Option<Hit> {
    let media = mi!(name, r"\b(movie|tv|show|audio|video|book|game)\b");
    if mi!(name, r"\b(sql|csv|dump|backup|dataset|collection)\b") && !media {
        return Some(hit(cat::OTHER_MISC, 0.6, "dataset"));
    }
    if mi!(name, r"\b(leak|breach|data|database)\b")
        && mi!(name, r"\b(dump|export|backup)\b")
        && !media
    {
        return Some(hit(cat::OTHER_MISC, 0.6, "data_dump"));
    }
    None
}
