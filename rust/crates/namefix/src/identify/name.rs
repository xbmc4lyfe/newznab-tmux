//! Reads a release name into its parts and decides whether it is in scope: TV or movies, at
//! 4K or 1080p, from BluRay, remux or WEB-DL. Anime is out.

use std::sync::LazyLock;

use crate::checkers;
use nntmux_categorize::names::is_hashed_or_gibberish;
use nntmux_predb::filename::{is_plausible_release_title, strip_file_suffixes};
use regex::Regex;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Kind {
    Tv,
    Movie,
    Anime,
    Other,
    Unknown,
}

impl Kind {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Tv => "tv",
            Self::Movie => "movie",
            Self::Anime => "anime",
            Self::Other => "other",
            Self::Unknown => "unknown",
        }
    }
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ParsedName {
    pub title: String,
    pub year: Option<u16>,
    pub season: Option<u16>,
    pub episode: Option<u16>,
    pub resolution: Option<&'static str>,
    pub source: Option<&'static str>,
    pub codec: Option<&'static str>,
    pub group: Option<String>,
    pub kind: Kind,
}

/// A name's tag only counts when separators or the name's ends surround it. `\b` won't do,
/// because `_` is a word character and underscores separate many names.
macro_rules! token {
    ($body:literal) => {{
        static RE: LazyLock<Regex> = LazyLock::new(|| {
            Regex::new(concat!(
                r"(?i)(?:^|[._ \-\[\(])(?:",
                $body,
                r")(?:$|[._ \-\]\)])"
            ))
            .expect("static pattern")
        });
        &*RE
    }};
}

fn first_of(name: &str, table: &[(&Regex, &'static str)]) -> Option<(usize, &'static str)> {
    table
        .iter()
        .filter_map(|(re, value)| re.find(name).map(|found| (found.start(), *value)))
        .min_by_key(|(start, _)| *start)
}

fn resolution(name: &str) -> Option<(usize, &'static str)> {
    first_of(
        name,
        &[
            (token!(r"2160[pi]|4k|uhd"), "2160p"),
            (token!(r"1080[pi]"), "1080p"),
            (token!(r"720p"), "720p"),
            (token!(r"576[pi]|480[pi]"), "sd"),
        ],
    )
}

/// Words that are unmistakably a source, wherever they turn up in a name.
fn strong_source(name: &str) -> Option<(usize, &'static str)> {
    // Remux first: a remux of a BluRay usually carries both words.
    if let Some(found) = token!(r"remux|bdremux").find(name) {
        return Some((found.start(), "remux"));
    }
    first_of(
        name,
        &[
            (
                token!(r"blu-?ray|bdrip|brrip|bd-?(?:25|50|66|100)|hdrip-?bd"),
                "bluray",
            ),
            (token!(r"web-?dl|webhd"), "web-dl"),
            (token!(r"web[._ -]?(?:dl)?[._ -]?rip"), "webrip"),
            (token!(r"hdtv|pdtv|sdtv"), "hdtv"),
            (token!(r"dvd-?rip|dvd-?r|dvd5|dvd9|xvid|divx"), "dvd"),
            (
                token!(r"hdcam|camrip|hdts|telesync|screener|vhs(?:rip)?"),
                "cam",
            ),
        ],
    )
}

/// Source words that are also ordinary title words, as in `Charlottes.Web.2006`. They only count
/// once the title has ended, so the caller passes the text after that point.
fn weak_source(rest: &str) -> Option<(usize, &'static str)> {
    first_of(
        rest,
        &[
            (token!(r"web"), "web"),
            (token!(r"dsr"), "hdtv"),
            (token!(r"cam|ts|scr|vhs"), "cam"),
        ],
    )
}

fn codec(name: &str) -> Option<(usize, &'static str)> {
    first_of(
        name,
        &[
            (token!(r"[xh][._ ]?265|hevc"), "h265"),
            (token!(r"[xh][._ ]?264|avc"), "h264"),
            (token!(r"av1"), "av1"),
            (token!(r"vc-?1"), "vc1"),
            (token!(r"mpeg-?2"), "mpeg2"),
        ],
    )
}

fn capture(re: &Regex, name: &str, index: usize) -> Option<u16> {
    re.captures(name)?.get(index)?.as_str().parse().ok()
}

/// Reads a name. It never fails: what it can't read stays empty and `kind` says `Unknown`.
pub fn parse(name: &str) -> ParsedName {
    let (season_episode, season_only, daily, cross, episode_only) = (
        token!(r"s(\d{1,3})[._ -]?e(\d{1,4})(?:-?e\d{1,4})?"),
        token!(r"s(?:eason[._ ]?)?(\d{1,3})"),
        token!(r"(?:19|20)\d\d[._ -]\d\d[._ -]\d\d"),
        token!(r"(\d{1,2})x(\d{2,3})"),
        token!(r"ep?(\d{2,4})"),
    );

    // A marker at the very start is the title itself, as in `10x10.2018`.
    let after_start = |re: &Regex| re.find(name).filter(|found| found.start() > 0);
    let mut markers: Vec<usize> = Vec::new();
    let mut season = None;
    let mut episode = None;
    let mut tv = false;
    if let Some(found) = after_start(season_episode) {
        season = capture(season_episode, name, 1);
        episode = capture(season_episode, name, 2);
        markers.push(found.start());
        tv = true;
    } else if let Some(found) = after_start(cross) {
        season = capture(cross, name, 1);
        episode = capture(cross, name, 2);
        markers.push(found.start());
        tv = true;
    } else if let Some(found) = after_start(season_only) {
        season = capture(season_only, name, 1);
        markers.push(found.start());
        tv = true;
    } else if let Some(found) = after_start(daily) {
        markers.push(found.start());
        tv = true;
    } else if let Some(found) = after_start(episode_only) {
        episode = capture(episode_only, name, 1);
        markers.push(found.start());
        tv = true;
    }

    let found_resolution = resolution(name);
    let mut found_source = strong_source(name);
    let found_codec = codec(name);
    markers.extend(
        [
            found_resolution.map(|f| f.0),
            found_source.map(|f| f.0),
            found_codec.map(|f| f.0),
        ]
        .into_iter()
        .flatten(),
    );
    let tags_start = markers.iter().copied().min().unwrap_or(name.len());

    // A year names the film only after the title starts, which keeps 2012.2009.1080p from
    // reading as a title of nothing. The last one before the tags wins, for Blade.Runner.2049.2017.
    static YEAR: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(r"(?:^|[._ \-\[\(])((?:19|20)\d\d)(?:$|[._ \-\]\)])").expect("static pattern")
    });
    let mut year = None;
    let mut year_start = None;
    let mut late_year = None;
    let mut offset = 0;
    while let Some(captures) = YEAR.captures(&name[offset..]) {
        let (whole, digits) = (
            captures.get(0).expect("whole match"),
            captures.get(1).expect("year group"),
        );
        let start = offset + whole.start();
        if start > 0 {
            let parsed = digits.as_str().parse().ok();
            if start < tags_start {
                year = parsed;
                year_start = Some(start);
            } else if late_year.is_none() {
                late_year = parsed;
            }
        }
        // The closing separator can open the next year, so step over the digits only.
        offset += digits.end();
    }
    // Some names put the year after the tags: Film.Name.1080p.BluRay.2021.
    let year_value = year.or(late_year);

    let title_end = year_start.map_or(tags_start, |start| start.min(tags_start));
    if found_source.is_none() {
        found_source =
            weak_source(&name[title_end..]).map(|(start, value)| (title_end + start, value));
    }
    let title = clean_title(&name[..title_end]);

    static GROUP: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"-([A-Za-z0-9]{2,20})\]?$").expect("static pattern"));
    let group = GROUP
        .captures(name)
        .map(|captures| captures[1].to_owned())
        .filter(|group| !matches!(group.to_ascii_lowercase().as_str(), "dl" | "hd" | "rip"));

    let kind = if is_other(name, found_resolution.is_some() || found_source.is_some()) {
        Kind::Other
    } else if is_anime(name, tv, year_value, title_end) {
        Kind::Anime
    } else if tv {
        Kind::Tv
    } else if year_value.is_some() {
        Kind::Movie
    } else {
        Kind::Unknown
    };

    ParsedName {
        title,
        year: year_value,
        season,
        episode,
        resolution: found_resolution.map(|f| f.1),
        source: found_source.map(|f| f.1),
        codec: found_codec.map(|f| f.1),
        group,
        kind,
    }
}

fn clean_title(raw: &str) -> String {
    let words: Vec<&str> = raw
        .split(['.', '_', ' '])
        .map(|word| word.trim_matches(['-', '[', ']', '(', ')']))
        .filter(|word| !word.is_empty())
        .collect();
    words.join(" ")
}

/// `XXX` as a tag, not a film spelled xXx. Audio and platform words appear on real video too
/// (`REMUX.AVC.FLAC.2.0`), so they only count on a name with no video tags.
fn is_other(name: &str, video_tags: bool) -> bool {
    if token!(r"xxx")
        .find(name)
        .is_some_and(|found| found.start() > 0 || found.as_str().starts_with("XXX"))
        || token!(r"hentai|imageset").is_match(name)
    {
        return true;
    }
    !video_tags
        && token!(r"mp3|flac|audiobook|ebook|epub|nintendo|xbox|ps[345]|macos|x64|x86|keygen")
            .is_match(name)
}

fn is_anime(name: &str, tv: bool, year: Option<u16>, title_end: usize) -> bool {
    static FANSUB: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(r"^\[[^\]]{2,30}\][ ._].+?[ ._-]+\d{2,4}(?:v\d)?[ ._]*(?:\[|\(|$)")
            .expect("static pattern")
    });
    // Only after the title, because The.Bad.Batch and Anime.Supremacy are titles.
    // CR is Crunchyroll, which carries almost nothing else.
    token!(r"anime|batch|cr|hidive")
        .find(name)
        .is_some_and(|found| found.start() >= title_end)
        || FANSUB.is_match(name)
        || (!tv
            && year.is_none()
            && name.starts_with('[')
            && !name.contains("]-[")
            && token!(r"\d{2,4}").is_match(name))
}

/// Long-running anime that scene names rarely tag, for when PreDB has no row to say so. Keep it
/// to titles no live-action series shares.
const KNOWN_ANIME_TITLES: [&str; 24] = [
    "attackontitan",
    "bleach",
    "blackclover",
    "boruto",
    "demonslayer",
    "detectiveconan",
    "dragonball",
    "dragonballsuper",
    "dragonballz",
    "fairytail",
    "fullmetalalchemist",
    "gintama",
    "hunterxhunter",
    "jujutsukaisen",
    "jojosbizarreadventure",
    "myheroacademia",
    "naruto",
    "narutoshippuden",
    "onepiece",
    "onepunchman",
    "sailormoon",
    "spyxfamily",
    "tokyoghoul",
    "vinlandsaga",
];

pub fn is_known_anime_title(title: &str) -> bool {
    KNOWN_ANIME_TITLES.contains(&folded(title).as_str())
}

/// Whether a release is in scope, and why not if it isn't.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Scope {
    InScope,
    Anime,
    NotTvOrMovie,
    LowResolution,
    LowSource,
    UnknownQuality,
}

impl Scope {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::InScope => "in_scope",
            Self::Anime => "anime",
            Self::NotTvOrMovie => "not_tv_movie",
            Self::LowResolution => "low_resolution",
            Self::LowSource => "low_source",
            Self::UnknownQuality => "unknown_quality",
        }
    }
}

/// `predb_category` overrides a kind the name alone couldn't settle. `height` comes from media
/// info when the name doesn't state a resolution. File counts play no part in this decision.
pub fn scope(parsed: &ParsedName, predb_category: Option<&str>, height: Option<i64>) -> Scope {
    let category = predb_category.unwrap_or_default().to_ascii_uppercase();
    if parsed.kind == Kind::Anime || category.starts_with("ANIME") {
        return Scope::Anime;
    }
    let category_is_other = ["XXX", "MP3", "FLAC", "GAME", "EBOOK", "APP", "0DAY", "PDA"]
        .iter()
        .any(|prefix| category.starts_with(prefix));
    let video = category.starts_with("TV") || category.starts_with("MOVIE");
    let tv_or_movie =
        matches!(parsed.kind, Kind::Tv | Kind::Movie) || (parsed.kind == Kind::Unknown && video);
    if parsed.kind == Kind::Other || category_is_other || !tv_or_movie {
        return Scope::NotTvOrMovie;
    }
    let resolution = parsed.resolution.or(match height {
        Some(height) if height >= 1500 => Some("2160p"),
        Some(height) if height >= 800 => Some("1080p"),
        Some(height) if height > 0 => Some("720p"),
        _ => None,
    });
    match resolution {
        None => return Scope::UnknownQuality,
        Some("2160p" | "1080p") => {}
        Some(_) => return Scope::LowResolution,
    }
    match parsed.source {
        None => Scope::UnknownQuality,
        Some("bluray" | "remux" | "web-dl" | "web") => Scope::InScope,
        Some(_) => Scope::LowSource,
    }
}

const MAX_NAME_BYTES: usize = 1024;

/// Repost tags that uploaders stick on the end of a scene name.
const REPOST_TAGS: [&str; 12] = [
    "-obfuscated",
    "-scrambled",
    "-xpost",
    "-postbot",
    "-asrequested",
    "-buymore",
    "-rakuv",
    "-repost",
    "-rp",
    "[rarbg]",
    "[rartv]",
    "[eztv]",
];

/// The title spelled out, minus suffixes and repost tags.
pub fn stem(file_name: &str) -> String {
    let file_name = file_name.trim();
    // No real name is this long, and the suffix stripper recurses on odd input.
    if file_name.len() > MAX_NAME_BYTES {
        return String::new();
    }
    let mut stem = strip_file_suffixes(file_name).trim().to_owned();
    loop {
        let lower = stem.to_ascii_lowercase();
        let Some(tag) = REPOST_TAGS.iter().find(|tag| lower.ends_with(**tag)) else {
            return stem;
        };
        stem.truncate(stem.len() - tag.len());
    }
}

/// The names a path offers, folder first: `Show.S01E01-GRP/abc123.mkv` is named by its folder.
pub fn names_from_path(path: &str) -> Vec<String> {
    let parts: Vec<&str> = path.split(['/', '\\']).filter(|p| !p.is_empty()).collect();
    let mut names = Vec::new();
    if let [.., parent, _] = parts.as_slice() {
        names.push(stem(parent));
    }
    if let Some(last) = parts.last() {
        names.push(stem(last));
    }
    names.retain(|name| usable(name));
    names
}

/// The filename a posting's subject declares: its first quoted value, or else the subject minus
/// its part counters and the yEnc trailer.
pub fn name_from_subject(subject: &str) -> Option<String> {
    static QUOTED: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r#""([^"]{3,})""#).expect("static pattern"));
    static COUNTER: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"[\[\(]\s*\d+\s*/\s*\d+\s*[\]\)]").expect("static pattern"));
    if let Some(captures) = QUOTED.captures(subject) {
        return Some(captures[1].trim().to_owned());
    }
    if subject.contains('>') {
        return None;
    }
    // Counters first, because the usual subject ends in `yEnc (1/500)`.
    let without = COUNTER.replace_all(subject, " ");
    let trimmed = without.trim();
    let trimmed = if trimmed.to_ascii_lowercase().ends_with("yenc") {
        &trimmed[..trimmed.len() - 4]
    } else {
        trimmed
    };
    let clean = trimmed.trim().trim_matches(['-', ' ']).to_owned();
    (!clean.is_empty()).then_some(clean)
}

/// The title before a subject's first part counter, as in `Some.Film.2021 [26/78] - "x.rar" yEnc`,
/// or a name wrapped like `(www.site.org) >Dark.Tide.2012.1080p.BluRay.x264-GRP< <ad>`.
pub fn subject_prefix(subject: &str) -> Option<String> {
    static WRAPPED: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r">([^<>]{6,})<").expect("static pattern"));
    static COUNTER: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"[\[\(]\s*\d+\s*/\s*\d+\s*[\]\)]").expect("static pattern"));
    if let Some(captures) = WRAPPED.captures(subject) {
        let name = captures[1].trim();
        return usable(name).then(|| name.to_owned());
    }
    let prefix = &subject[..COUNTER.find(subject)?.start()];
    let prefix = prefix.trim().trim_matches(['-', ' ']);
    if prefix.contains('"') || prefix.to_ascii_lowercase().ends_with("yenc") {
        return None;
    }
    let prefix = stem(prefix);
    usable(&prefix).then_some(prefix)
}

/// Whether a name can identify anything at all. Hashes, part numbers and samples can't.
pub fn usable(name: &str) -> bool {
    // The plausibility check reads one long token as random, and underscores and dashes as part
    // of it. A name with a real tag is safe to split. Otherwise one underscore is more likely
    // noise, as in 2F4lf3rZZOa7GRSFEAkZiLRF_re.
    let tagged = resolution(name).is_some() || token!(r"s\d{1,3}[._ -]?e\d{1,4}").is_match(name);
    let dotted = if tagged {
        name.replace(['_', '-'], ".")
    } else if name.matches('_').count() >= 2 {
        name.replace('_', ".")
    } else {
        name.to_owned()
    };
    if name.len() > 300 || !is_plausible_release_title(&dotted) {
        return false;
    }
    // Sample and proof only mean that at the end, not in Death.Proof.2007.
    static EXTRA: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(r"(?i)(?:^sample-|(?:^|[._ -])(?:sample|proof)$)").expect("static pattern")
    });
    !EXTRA.is_match(name) && !token!(r"vts|video[._ ]ts").is_match(name)
}

/// Whether a name is only noise, for the evidence report.
pub fn looks_obfuscated(name: &str) -> bool {
    let stem = stem(name);
    stem.is_empty() || is_hashed_or_gibberish(&stem) || !usable(&stem)
}

/// Whether a name, with no PreDB row behind it, is good enough to stand as an identification.
///
/// `quality_required` is for sources that could be coincidence, such as a decoded name.
pub fn acceptable(parsed: &ParsedName, name: &str, quality_required: bool) -> bool {
    // Anime stands too, so it gets reported as out of scope rather than as nothing.
    if !matches!(parsed.kind, Kind::Tv | Kind::Movie | Kind::Anime) {
        return false;
    }
    let letters = parsed.title.chars().filter(|c| c.is_alphanumeric()).count();
    // Noise is one token. The gibberish check misreads some real titles of several words.
    if letters < 2 || (!parsed.title.contains(' ') && is_hashed_or_gibberish(&parsed.title)) {
        return false;
    }
    let quality = parsed.resolution.is_some() || parsed.source.is_some();
    if quality_required && !quality {
        return false;
    }
    // The ported checkers try TV and movies first, so Game or App means neither fits.
    !matches!(
        checkers::check(name).map(|hit| hit.checker_name).as_deref(),
        Some("Game" | "App")
    )
}

/// Other spellings of an obfuscated name that are cheap to try: ROT13 and reversal.
pub fn decoded_variants(name: &str) -> [String; 2] {
    let rot13 = name
        .chars()
        .map(|c| match c {
            'a'..='m' | 'A'..='M' => (c as u8 + 13) as char,
            'n'..='z' | 'N'..='Z' => (c as u8 - 13) as char,
            other => other,
        })
        .collect();
    [rot13, name.chars().rev().collect()]
}

/// A pack's name: the name of one of its episodes with the episode taken out.
pub fn pack_name(name: &str) -> String {
    static EPISODE: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(r"(?i)(s\d{1,3})[._ -]?e\d{1,4}(?:-?e\d{1,4})?").expect("static pattern")
    });
    EPISODE.replace(name, "$1").into_owned()
}

/// Lowercase letters and digits only, in any script, for names that differ in spelling but not in substance.
pub fn folded(name: &str) -> String {
    name.chars()
        .filter(|c| c.is_alphanumeric())
        .flat_map(char::to_lowercase)
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_an_episode() {
        let parsed = parse(
            "Knife.Edge.Chasing.Michelin.Stars.S02E07.We.do.it.MY.way.2160p.ATVP.WEB-DL.DD.5.1.Atmos.DoVi.HDR.H.265-playWEB",
        );
        assert_eq!(parsed.title, "Knife Edge Chasing Michelin Stars");
        assert_eq!((parsed.season, parsed.episode), (Some(2), Some(7)));
        assert_eq!(parsed.resolution, Some("2160p"));
        assert_eq!(parsed.source, Some("web-dl"));
        assert_eq!(parsed.codec, Some("h265"));
        assert_eq!(parsed.group.as_deref(), Some("playWEB"));
        assert_eq!(parsed.kind, Kind::Tv);
        assert_eq!(scope(&parsed, None, None), Scope::InScope);
    }

    #[test]
    fn reads_a_show_with_its_year() {
        let parsed = parse("Furia.2025.S02E04.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR");
        assert_eq!(parsed.title, "Furia");
        assert_eq!(parsed.year, Some(2025));
        assert_eq!(parsed.episode, Some(4));
        assert_eq!(scope(&parsed, None, None), Scope::InScope);
    }

    #[test]
    fn reads_a_film_named_after_a_year() {
        let parsed = parse("2012.2009.1080p.BluRay.x264-GECKOS");
        assert_eq!(parsed.title, "2012");
        assert_eq!(parsed.year, Some(2009));
        assert_eq!(parsed.kind, Kind::Movie);

        let parsed = parse("Blade.Runner.2049.2017.2160p.UHD.BluRay.REMUX.HEVC.TrueHD.7.1-FGT");
        assert_eq!(parsed.title, "Blade Runner 2049");
        assert_eq!(parsed.year, Some(2017));
        assert_eq!(parsed.source, Some("remux"));
        assert_eq!(scope(&parsed, None, None), Scope::InScope);
    }

    #[test]
    fn a_single_file_remux_passes_on_its_name_alone() {
        let name = stem("Sharp.Corner.2024.2160p.UHD.BluRay.REMUX.DV.HEVC.TrueHD.7.1-GRP.mkv");
        let parsed = parse(&name);
        assert_eq!(scope(&parsed, None, None), Scope::InScope);
        assert!(acceptable(&parsed, &name, true));
    }

    #[test]
    fn underscores_separate_tags() {
        let parsed = parse("disney_animation_megapack_english_german_h264_1080p_AC3");
        assert_eq!(parsed.resolution, Some("1080p"));
        assert_eq!(parsed.codec, Some("h264"));
    }

    #[test]
    fn low_quality_is_out_of_scope_with_a_reason() {
        let low_res = parse("Paradise.City.S01E06.German.DL.720p.WEB.h264-WvF");
        assert_eq!(scope(&low_res, None, None), Scope::LowResolution);
        let low_source = parse("Marshals.S02E02.1080p.HDTV.x264-SYNCOPY");
        assert_eq!(scope(&low_source, None, None), Scope::LowSource);
        let rip = parse("Some.Film.2021.1080p.WEBRip.x265-RARBG");
        assert_eq!(scope(&rip, None, None), Scope::LowSource);
        let bare = parse("Some.Film.2021");
        assert_eq!(scope(&bare, None, None), Scope::UnknownQuality);
    }

    #[test]
    fn media_info_can_settle_the_resolution() {
        let parsed = parse("Some.Film.2021.BluRay.x264-GRP");
        assert_eq!(scope(&parsed, None, Some(1080)), Scope::InScope);
        assert_eq!(scope(&parsed, None, Some(544)), Scope::LowResolution);
    }

    #[test]
    fn anime_and_other_things_stay_out() {
        let fansub = parse("[SubsPlease] Sousou no Frieren - 12 (1080p) [A1B2C3D4]");
        assert_eq!(scope(&fansub, None, None), Scope::Anime);
        let german =
            parse("Cyberpunk.Edgerunners.S02E03.GERMAN.DL.ANiME.2160p.WEB.h265-SAUERKRAUT");
        assert_eq!(scope(&german, None, None), Scope::Anime);
        let xxx = parse("BrazzersExxtra.26.10.15.Codi.Vore.XXX.1080p.MP4-WRB");
        assert_eq!(scope(&xxx, None, None), Scope::NotTvOrMovie);
        let category = parse("Some.Show.S01E01.1080p.WEB.h264-GRP");
        assert_eq!(scope(&category, Some("ANIME"), None), Scope::Anime);
        assert_eq!(scope(&category, Some("XXX-HD"), None), Scope::NotTvOrMovie);
        let album =
            parse("Bob_Mover_and_Walter_Davis_Jr.-The_Salerno_Concert-(RTRCD013)-CD-2024-FANG");
        assert_ne!(scope(&album, Some("MP3"), None), Scope::InScope);
    }

    #[test]
    fn the_category_settles_a_name_with_no_year_or_episode() {
        let parsed = parse("Some.Concert.1080p.BluRay.x264-GRP");
        assert_eq!(parsed.kind, Kind::Unknown);
        assert_eq!(scope(&parsed, Some("MOVIE"), None), Scope::InScope);
        assert_eq!(scope(&parsed, None, None), Scope::NotTvOrMovie);
    }

    #[test]
    fn obfuscated_names_are_noise() {
        for name in [
            "NRV90xZFaYfUr9J1bQ8oCE.par2",
            "GPInOt3kYN.part001.rar",
            "cefa04e55d92423cadd5d10e4a05d89a.part01.rar",
            "yALdHhmO1OsMxZR0oJJHfDX7NIUzaKnG.mp4",
            "{51D291AB-0F02-49F1-A7BD-E19253D4FAAF}",
            "2F4lf3rZZOa7GRSFEAkZiLRF_re.par2",
        ] {
            assert!(looks_obfuscated(name), "{name}");
        }
        assert!(!looks_obfuscated(
            "Marshals.S02E02.720p.HDTV.x264-SYNCOPY.part01.rar"
        ));
    }

    #[test]
    fn subjects_give_up_their_names() {
        assert_eq!(
            name_from_subject(r#"[01/99] "GPInOt3kYN.part001.rar" yEnc"#).as_deref(),
            Some("GPInOt3kYN.part001.rar")
        );
        assert_eq!(
            name_from_subject("funxd.mkv yEnc").as_deref(),
            Some("funxd.mkv")
        );
        assert_eq!(
            subject_prefix(
                r#"Some.Film.2021.1080p.WEB-DL.H264-GRP [26/78] - "abc.part01.rar" yEnc"#
            )
            .as_deref(),
            Some("Some.Film.2021.1080p.WEB-DL.H264-GRP")
        );
        assert_eq!(
            subject_prefix(r#"Tokyo Dolls [01/45] - "x.rar" yEnc"#),
            None
        );
    }

    #[test]
    fn a_folder_names_its_obfuscated_file() {
        assert_eq!(
            names_from_path(
                "Marshals.S02E02.1080p.WEB.x264-SYNCOPY/7d8c650a91cf437a8dd9ecce444c1ddc.mkv"
            ),
            ["Marshals.S02E02.1080p.WEB.x264-SYNCOPY"]
        );
        assert!(names_from_path("VIDEO_TS\\VTS_01_1.VOB").is_empty());
    }

    #[test]
    fn repost_tags_come_off() {
        assert_eq!(
            stem("Some.Film.2021.1080p.WEB-DL.H264-GRP-Obfuscated.mkv"),
            "Some.Film.2021.1080p.WEB-DL.H264-GRP"
        );
    }

    #[test]
    fn decoding_finds_rot13() {
        let [rot13, _] = decoded_variants("qvfarl_navzngvba_u264_1080c_NP3");
        assert_eq!(rot13, "disney_animation_h264_1080p_AC3");
        let [_, reversed] = decoded_variants("PRG-462x.pR0801.1202.mliF");
        assert_eq!(reversed, "Film.2021.1080Rp.x264-GRP");
    }

    #[test]
    fn a_pack_loses_its_episode() {
        assert_eq!(
            pack_name("Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR"),
            "Zmora.S01.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR"
        );
    }

    fn in_scope(name: &str) -> bool {
        let stem = stem(name);
        let parsed = parse(&stem);
        usable(&stem)
            && acceptable(&parsed, &stem, true)
            && scope(&parsed, None, None) == Scope::InScope
    }

    #[test]
    fn titles_can_hold_tag_words() {
        for name in [
            "Some.Film.2019.1080p.BluRay.REMUX.AVC.FLAC.2.0-GRP",
            "Star.Wars.The.Bad.Batch.S01E01.1080p.DSNP.WEB-DL.DDP5.1.H.264-GRP",
            "xXx.2002.1080p.BluRay.x264-GRP",
            "The.Portable.Door.2023.1080p.WEB-DL.H264-GRP",
            "Death.Proof.2007.1080p.BluRay.x264-GRP",
            "Sample.Size.2021.1080p.WEB-DL.H264-GRP",
            "Charlottes.Web.2006.1080p.BluRay.x264-GRP",
            "Web.Of.Lies.S01E01.1080p.WEB-DL.H264-GRP",
            "Cam.2020.1080p.NF.WEB-DL.DDP5.1.x264-GRP",
            "The_Last_Show_S01E02_1080p_WEB-DL_H264-GRP",
            "The_Last_Film_2021_1080p_WEB-DL_H264-GRP",
            "Film.Name.1080p.BluRay.2021.x264-GRP",
            "10x10.2018.1080p.BluRay.x264-GRP",
            "Anime.Supremacy.2020.1080p.WEB-DL.H264-GRP",
            "Some-Show-S01E02-1080p-WEB-DL-H264-GRP",
            "Film_Name-S01E01-1080p-WEB-DL-x264-GRP",
        ] {
            assert!(in_scope(name), "{name}");
        }
        assert_eq!(
            parse("Charlottes.Web.2006.1080p.BluRay.x264-GRP").title,
            "Charlottes Web"
        );
        assert_eq!(
            parse("Web.Of.Lies.S01E01.1080p.WEB-DL.H264-GRP").title,
            "Web Of Lies"
        );
    }

    #[test]
    fn anime_and_adult_tags_count_before_the_technical_tags_too() {
        let film = parse("Your.Name.2016.GERMAN.DL.ANiME.1080p.BluRay.x264-GRP");
        assert_eq!(scope(&film, None, None), Scope::Anime);
        let scene = parse("XXX.Some.Scene.2021.1080p.WEB.x264-GRP");
        assert_eq!(scope(&scene, None, None), Scope::NotTvOrMovie);
    }

    #[test]
    fn a_leading_sample_tag_is_still_a_sample() {
        assert!(!usable("Sample-Zmora.S01E05.1080p.WEB.H264-RAWR"));
        assert!(usable("Sample.Size.2021.1080p.WEB-DL.H264-GRP"));
    }

    #[test]
    fn subjects_are_read_whatever_the_case_of_yenc() {
        assert_eq!(
            name_from_subject("Film.2021.1080p.WEB-DL.H264-GRP.mkv yenc (1/500)").as_deref(),
            Some("Film.2021.1080p.WEB-DL.H264-GRP.mkv")
        );
        assert_eq!(
            subject_prefix(r#"Film.2021.1080p.WEB-DL.H264-GRP.part01.rar [01/78] - "x" yEnc"#)
                .as_deref(),
            Some("Film.2021.1080p.WEB-DL.H264-GRP")
        );
    }

    #[test]
    fn a_crunchyroll_tag_means_anime() {
        let parsed = parse("Some.Show.S01E02.1080p.CR.WEB-DL.AAC2.0.H.264-GRP");
        assert_eq!(scope(&parsed, None, None), Scope::Anime);
    }

    #[test]
    fn an_indexer_wrapper_is_not_a_fansub_tag() {
        let parsed = parse("[9220]-[FULL]-[a.b.teeveeEFNet]-[ Ally.Mcbeal.S01E01.DVDRip.XviD ]");
        assert_ne!(parsed.kind, Kind::Anime);
    }

    #[test]
    fn rips_are_not_web_dl() {
        for name in [
            "Some.Film.2019.1080p.WEB.Rip.x264-GRP",
            "Some.Film.2019.1080p.WEB-DLRip.x264-GRP",
        ] {
            assert_eq!(parse(name).source, Some("webrip"), "{name}");
        }
    }

    #[test]
    fn a_bare_tag_still_counts_after_the_title() {
        let parsed = parse("Zmora.S01E05.1080p.WEB.h264-RAWR");
        assert_eq!(parsed.source, Some("web"));
        assert_eq!(
            parse("Some.Film.2021.1080p.CAM.x264-GRP").source,
            Some("cam")
        );
    }

    #[test]
    fn production_subjects_end_in_a_counter() {
        for subject in [
            r#"[01/01] - "Unlisted.Film.2023.1080p.BluRay.x264-NOGRP.mkv" yEnc (1/500)"#,
            "Unlisted.Film.2023.1080p.BluRay.x264-NOGRP.mkv yEnc (1/500)",
        ] {
            assert_eq!(
                name_from_subject(subject).as_deref(),
                Some("Unlisted.Film.2023.1080p.BluRay.x264-NOGRP.mkv"),
                "{subject}"
            );
            assert_eq!(subject_prefix(subject), None, "{subject}");
        }
    }

    #[test]
    fn other_scripts_do_not_fold_together() {
        assert_ne!(
            folded("Другой.Фильм.2021.1080p"),
            folded("Совсем.Иной.2021.1080p")
        );
    }

    #[test]
    fn an_absurd_name_is_refused_without_recursing() {
        assert_eq!(stem(&format!("x{}", "\n".repeat(300_000))), "x");
        assert_eq!(stem(&"x".repeat(2000)), "");
    }
}
