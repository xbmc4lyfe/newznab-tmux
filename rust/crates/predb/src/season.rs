//! Recognises whole-season TV releases and derives a season-level release name from the
//! episode file names inside them. Season tokens must stand alone between separators, so audio
//! and codec tokens such as DTS5.1, Atmos7.1 or x264 are never mistaken for a season.

use fancy_regex::{Captures, Regex};
use nntmux_categorize::pcre::{PHP_WS, trim_chars};
use nntmux_categorize::{frx, mi, rx};

fn season_word() -> &'static Regex {
    frx!(i r"(?:^|[._ \-\[(])Seasons?[._ ]?\d{1,2}(?=$|[._ \-\])])")
}

fn complete() -> &'static Regex {
    frx!(i r"(?:^|[._ \-\[(])Complete[._ ](?:Series|Seasons?)(?=$|[._ \-\])])")
}

fn release_info_token(token: &str) -> bool {
    mi!(
        token,
        r"^(?:\d{3,4}[pi]|4k|uhd|hdr(?:10)?(?:plus)?|dv|sdr|web|webrip|webdl|bluray|blu|bd|bdrip|bdremux|brrip|remux|dvd|dvdrip|dvdr|dvd5|dvd9|hdtv|pdtv|sdtv|hdrip|dsr|amzn|nf|dsnp|hmax|atvp|hulu|pcok|pmtp|stan|crav|x26[45]|h26[45]|hevc|avc|xvid|divx|10bit|8bit|aac\d*|e?ac3|ddp?\d*|dts|truehd|atmos|flac|proper|repack\d*|rerip|internal|multi|dual|subbed|dubbed|extended|uncut|german|french|spanish|italian|dutch|nordic|swedish|danish|finnish|norwegian|polish|russian|hindi|japanese|korean)$"
    )
}

fn sample(base: &str) -> bool {
    mi!(base, r"(?:^|[._ \-])(?:sample|proof)(?:$|[._ \-])")
}

fn found(re: &Regex, value: &str) -> bool {
    re.is_match(value).unwrap_or(false)
}

fn caps<'a>(re: &Regex, value: &'a str) -> Option<Captures<'a>> {
    re.captures(value).ok().flatten()
}

fn group<'a>(caps: &Captures<'a>, name: &str) -> &'a str {
    caps.name(name).map_or("", |m| m.as_str())
}

/// Whether a release name describes a whole season (or several) rather than an episode.
pub fn is_season_pack(name: &str) -> bool {
    if is_episode_name(name) {
        return false;
    }
    found(
        frx!(i r"(?:^|[._ \-\[(])S\d{1,4}(?:[._ ]?D(?:is[ck])?[._ ]?\d{1,2})?(?=$|[._ \-\])])"),
        name,
    ) || found(season_word(), name)
        || found(complete(), name)
}

/// Whether a name carries an episode marker (S01E01, 1x05, Episode.5, E05...).
pub fn is_episode_name(name: &str) -> bool {
    found(
        frx!(i r"(?:^|[^a-z0-9])(?:S\d+[._ ]?E\d+|\d{1,2}x\d{2,}(?=$|[^a-z0-9]|[exs]\d)|Episode[._ ]?\d{1,4}(?!\d)|EP?\d{2,3}(?![0-9a-z]))"),
        name,
    )
}

/// Recognized episode markers without enough information to infer a season safely.
pub fn is_unclassified_episode_name(name: &str) -> bool {
    let base = base_name(name);
    !sample(&base) && is_episode_name(&base) && parse_episode(name).is_none()
}

/// Whether the file names are at least two distinct episodes of one show.
pub fn is_multi_episode_set<S: AsRef<str>>(names: &[S]) -> bool {
    parse_episode_set(names).is_some()
}

/// Naming admission needs one episode identity, even when different shows can't supply the
/// common title `season_name_from_episodes` requires.
pub fn has_distinct_episode_identities<S: AsRef<str>>(names: &[S]) -> bool {
    let mut first: Option<String> = None;
    for name in names {
        let Some(episode) = parse_episode(name.as_ref()) else {
            continue;
        };
        let identity = format!("{}:{}:{}", episode.key, episode.season, episode.episode);
        if first.as_ref().is_some_and(|seen| *seen != identity) {
            return true;
        }
        first = Some(identity);
    }
    false
}

pub fn episode_identity(name: &str) -> Option<String> {
    let base = base_name(name);
    if base.is_empty() || sample(&base) || has_explicit_pack_marker(&base) {
        return None;
    }
    let m = caps(
        frx!(i r"^(?<prefix>.+?)[._ \-]+(?:S(?<season>\d{1,4})[._ ]?E(?<episode>\d{1,4})|(?<xseason>\d{1,2})x(?<xepisode>\d{2,3}))(?!\d)(?<rest>.*)$"),
        &base,
    )?;
    let rest = group(&m, "rest");
    let prefix = group(&m, "prefix");
    if is_episode_name(prefix)
        || is_episode_name(rest)
        || mi!(rest, r"^[a-z0-9]")
        || mi!(
            rest,
            r"(?:^|[^a-z0-9])(?:S\d+[._ ]?E\d+|E(?:pisode|P)?[._ ]?\d+)"
        )
        || has_x_episode_continuation(rest)
        || found(
            frx!(i r"^[._ ]*(?:[-+&,]|–|—|(?:to|through)[._ ]+)[._ ]*\d+(?=$|[^a-z0-9])"),
            rest,
        )
    {
        return None;
    }
    let key = show_key(prefix);
    if key.is_empty() {
        return None;
    }
    let number = |primary: &str, fallback: &str| -> u32 {
        let value = group(&m, primary);
        let value = if value.is_empty() {
            group(&m, fallback)
        } else {
            value
        };
        value.parse().unwrap_or(0)
    };
    Some(format!(
        "{key}:{}:{}",
        number("season", "xseason"),
        number("episode", "xepisode")
    ))
}

fn has_x_episode_continuation(rest: &str) -> bool {
    // Keep the whole token: x264/x265 are codecs, but x02 and x264E02 can't become codecs by
    // accepting only an initial numeric prefix.
    rx!(i r"(?:^|[^a-z0-9])(x\d+[^._ \-\[\](){}]*)")
        .captures_iter(rest.as_bytes())
        .any(|c| !release_info_token(&String::from_utf8_lossy(&c[1])))
}

/// Explicit pack or compound evidence can't become an opaque canonical singleton.
pub fn has_non_single_episode_evidence<S: AsRef<str>>(names: &[S]) -> bool {
    for name in names {
        let name = name.as_ref();
        let base = base_name(name);
        if sample(&base) {
            continue;
        }
        if has_explicit_pack_marker(&base)
            || (is_episode_name(&base) && episode_identity(name).is_none())
        {
            return true;
        }
    }
    has_distinct_episode_identities(names)
}

fn has_explicit_pack_marker(base: &str) -> bool {
    found(
        frx!(i r"(?:^|[._ \-\[(])S\d{1,4}(?!\d)(?![._ ]?E\d)(?:[._ ]?D(?:is[ck])?[._ ]?\d{1,2})?(?=$|[._ \-\])])"),
        base,
    ) || found(season_word(), base)
        || found(complete(), base)
}

/// Only explicit metadata, subtitle, artwork and sample conventions are ancillary.
pub fn is_ancillary_name(name: &str) -> bool {
    if sample(&base_name(name)) {
        return true;
    }
    let base = php_basename(&trim_chars(name, PHP_WS).replace('\\', "/")).to_owned();
    mi!(
        base,
        r"\.(?:nfo|sfv|nzb|srr|srs|par2|jpe?g|png|gif|bmp|tiff?|webp|txt|diz|md5|sha1|cue|log|srt|sub|idx|ass|ssa|vtt|sup)$"
    )
}

/// Collapses the episode file names of one show into a season-level release name: the common
/// release information is kept and per-episode titles are dropped.
pub fn season_name_from_episodes<S: AsRef<str>>(names: &[S]) -> Option<String> {
    let episodes = parse_episode_set(names)?;
    let first = &episodes[0];
    let mut seasons: Vec<u32> = episodes.iter().map(|e| e.season).collect();
    seasons.sort_unstable();
    seasons.dedup();
    let width = first.season_digits.len().max(2);
    let mut token = format!("S{:0width$}", seasons[0]);
    if seasons.len() > 1 {
        token.push_str(&format!("-S{:0width$}", seasons[seasons.len() - 1]));
    }
    let lists: Vec<_> = episodes.iter().map(|e| tokenize(&e.rest)).collect();
    match trim_to_release_info(common_token_suffix(&lists)) {
        Some(common) => Some(format!(
            "{}{}{token}{}",
            first.prefix,
            first.sep,
            join_tokens(common)
        )),
        None if seasons.len() > 1 => None,
        None => season_name_from_episode(&first.name),
    }
}

/// Turns one episode file name into the season-level name of its pack; `None` when no release
/// information follows the episode.
pub fn season_name_from_episode(name: &str) -> Option<String> {
    let episode = parse_episode(name)?;
    let tokens = tokenize(&episode.rest);
    let info = trim_to_release_info(&tokens)?;
    let width = episode.season_digits.len().max(2);
    Some(format!(
        "{}{}S{:0width$}{}",
        episode.prefix,
        episode.sep,
        episode.season,
        join_tokens(info)
    ))
}

/// The outermost folder in the paths that is named as a season pack.
pub fn season_folder<S: AsRef<str>>(paths: &[S]) -> Option<String> {
    for path in paths {
        let path = path.as_ref().replace('\\', "/");
        let mut segments: Vec<&str> = path.split('/').collect();
        segments.pop();
        for segment in segments {
            let segment = trim_chars(segment, PHP_WS);
            if !segment.is_empty() && is_season_pack(segment) {
                return Some(segment.to_owned());
            }
        }
    }
    None
}

struct Episode {
    name: String,
    prefix: String,
    sep: String,
    key: String,
    season: u32,
    season_digits: String,
    episode: u32,
    rest: String,
}

fn parse_episode_set<S: AsRef<str>>(names: &[S]) -> Option<Vec<Episode>> {
    let mut episodes: Vec<Episode> = Vec::new();
    let mut seen = std::collections::HashSet::new();
    for name in names {
        if let Some(episode) = parse_episode(name.as_ref())
            && seen.insert(episode.name.to_ascii_lowercase())
        {
            episodes.push(episode);
        }
    }
    let first = episodes.first()?;
    let one_show = episodes.iter().all(|e| e.key == first.key);
    let numbers: std::collections::HashSet<_> =
        episodes.iter().map(|e| (e.season, e.episode)).collect();
    (one_show && numbers.len() >= 2).then_some(episodes)
}

fn parse_episode(name: &str) -> Option<Episode> {
    let base = base_name(name);
    if base.is_empty() || sample(&base) {
        return None;
    }
    let m = caps(
        frx!(i r"^(?<prefix>.+?)(?<sep>[._ \-]+)S(?<season>\d{1,4})[._ ]?E(?<episode>\d{1,4})(?!\d)(?:[._\-]?E\d{1,4}|-\d{1,4}(?=$|[._ ]))*(?<rest>.*)$"),
        &base,
    )
    .or_else(|| {
        caps(
            frx!(i r"^(?<prefix>.+?)(?<sep>[._ \-]+)(?<season>\d{1,2})x(?<episode>\d{2,3})(?![0-9a-z])(?<rest>.*)$"),
            &base,
        )
    })?;
    let prefix = trim_chars(group(&m, "prefix"), PHP_WS);
    if prefix.is_empty() {
        return None;
    }
    Some(Episode {
        name: base.clone(),
        prefix: prefix.to_owned(),
        sep: group(&m, "sep").to_owned(),
        key: show_key(prefix),
        season: group(&m, "season").parse().unwrap_or(0),
        season_digits: group(&m, "season").to_owned(),
        episode: group(&m, "episode").parse().unwrap_or(0),
        rest: group(&m, "rest").to_owned(),
    })
}

fn show_key(prefix: &str) -> String {
    let dotted = nntmux_categorize::pcre::replace_all(rx!(r"[._ \-]+"), prefix, ".");
    dotted.trim_matches('.').to_ascii_lowercase()
}

/// PHP `basename()`: trailing slashes don't count.
pub(crate) fn php_basename(path: &str) -> &str {
    let trimmed = path.trim_end_matches('/');
    trimmed.rsplit('/').next().unwrap_or("")
}

fn base_name(name: &str) -> String {
    let normalized = trim_chars(name, PHP_WS).replace('\\', "/");
    let mut base = php_basename(&normalized).to_owned();
    for _ in 0..3 {
        let stripped = nntmux_categorize::pcre::replace_all(
            rx!(i r"(?:\.part\d+)?\.(?:rar|r\d{2,3}|zip|7z|\d{3}|par2|vol\d+[+-]\d+|mkv|mp4|avi|m4v|mov|wmv|mpg|mpeg|ts|m2ts|vob|webm|flv|divx|nfo|sfv|srt|sub|idx|ass|ssa|jpg|png)$"),
            &base,
            "",
        );
        if stripped == base {
            break;
        }
        base = stripped;
    }
    trim_chars(&base, " \t\n\r\0\x0B.").to_owned()
}

type Token = (String, String);

/// `[separator before the token, token]` pairs.
fn tokenize(rest: &str) -> Vec<Token> {
    let bytes = rest.as_bytes();
    let mut tokens = Vec::new();
    let mut separator = ".".to_owned();
    let mut start = 0;
    for delimiter in rx!(r"[._ \-]+").find_iter(bytes) {
        if delimiter.start() > start {
            tokens.push((separator, rest[start..delimiter.start()].to_owned()));
        }
        separator = rest[delimiter.start()..delimiter.end()].to_owned();
        start = delimiter.end();
    }
    if start < rest.len() {
        tokens.push((separator, rest[start..].to_owned()));
    }
    tokens
}

/// Tokens every list ends with, compared case-insensitively, in the first list's spelling.
fn common_token_suffix(lists: &[Vec<Token>]) -> &[Token] {
    let Some(first) = lists.first() else {
        return &[];
    };
    let length = lists.iter().map(Vec::len).min().unwrap_or(0);
    let mut common = 0;
    while common < length {
        let token = first[first.len() - 1 - common].1.to_ascii_lowercase();
        if lists
            .iter()
            .any(|t| t[t.len() - 1 - common].1.to_ascii_lowercase() != token)
        {
            break;
        }
        common += 1;
    }
    &first[first.len() - common..]
}

/// Drops leading tokens (an episode title) up to the first release-information token.
fn trim_to_release_info(tokens: &[Token]) -> Option<&[Token]> {
    let index = tokens
        .iter()
        .position(|(_, token)| release_info_token(token))?;
    Some(&tokens[index..])
}

fn join_tokens(tokens: &[Token]) -> String {
    tokens
        .iter()
        .map(|(sep, token)| format!("{sep}{token}"))
        .collect()
}
