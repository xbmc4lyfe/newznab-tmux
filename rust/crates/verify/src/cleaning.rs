//! Release names from article subjects: PreDB titles, stored naming regexes, then built-in patterns.

use std::collections::HashMap;
use std::sync::{Arc, LazyLock, Mutex};
use std::time::{Duration, Instant};

use anyhow::Result;
use nntmux_categorize::names::unwrap_nzbsplit;
use nntmux_core::pcre::Pcre;
use nntmux_predb::filename::{
    exact_value, extract_filename_from_path, is_plausible_release_title, strip_file_suffixes,
};
use sqlx::MySqlPool;
use tracing::warn;

use crate::nzb::fingerprint::php_trim;

/// What a stored regex list is reused for before reading it again.
const REGEX_CACHE: Duration = Duration::from_secs(60);

const E0: &str = r#"([\-_](proof|sample|thumbs?))*(\.part\d*(\.rar)?|\.rar|\.7z|\.par2)?(\d{1,3}\.rev"|\.vol\d+\+\d+.+?"|\.[A-Za-z0-9]{2,4}"|")"#;
const END: &str = r"[\-_\s]{0,3}yEnc$/ui";
const SIZE: &str = r"[\-_\s]{0,3}\d+([.,]\d+)? [kKmMgG][bB][\-_\s]{0,3}";
const TOWN_NAME: &str = r#""([\w\säöüÄÖÜß+¤¶!.,&_()\[\]'\`{}#-]{8,}?\b.?)"#;

fn pcre(pattern: &str) -> Pcre {
    Pcre::new(pattern).expect("a valid built-in regex")
}

macro_rules! rx {
    ($pattern:expr) => {{
        static REGEX: LazyLock<Pcre> = LazyLock::new(|| pcre($pattern));
        &*REGEX
    }};
}

/// What the cleaner found. A town pattern's bare capture is taken as a proper name.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Cleaned {
    Text(String),
    Named {
        name: String,
        proper: bool,
        predb: i64,
        regex_id: i64,
    },
}

fn named(name: String) -> Cleaned {
    Cleaned::Named {
        name,
        proper: false,
        predb: 0,
        regex_id: 0,
    }
}

fn text(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
}

/// The patterns `generic_town` tries, each with the group holding the name.
static TOWN: LazyLock<Vec<(Pcre, usize)>> = LazyLock::new(|| {
    [
        (
            format!(
                r#"/^<TOWN>.+?town\.ag.+?(www\..+?|News)\.[iI]nfo.+? \[\d+\/\d+\]( -)? "(.+?)(-sample)?{E0} - \d+[.,]\d+ [kKmMgG][bB]M? yEnc$/"#
            ),
            3,
        ),
        (
            format!(
                r#"/^\[\s*TOWN\s*\][\-_\s]{{0,3}}\[\s*www\.town\.ag\s*\][\-_\s]{{0,3}}\[\s*partner of www\.ssl-news\.info\s*\][\-_\s]{{0,3}}\[\s* .*\s*\][\-_\s]{{0,3}}\[\d+\/\d+\][\-_\s]{{0,4}}{TOWN_NAME}{E0}{SIZE}{END}"#
            ),
            1,
        ),
        (
            format!(
                r#"/^<TOWN>.+?town\.ag.+?(www\..+?|News)\.[iI]nfo.+? \[\d+\/\d+\]( -)? "(.+?)(-sample)?{E0}{END}"#
            ),
            3,
        ),
        (
            r#"/^\(\d+\/\d+\) -<TOWN><www\.town\.ag >\s+<partner.+> - ("|#34;)([\w. ()-]{8,}?\b)(\.par2|-\.part\d+\.rar|\.nfo)("|#34;) - \d+[.,]\d+ [kKmMgG][bB]( -)? yEnc$/"#.to_owned(),
            2,
        ),
        (
            r#"/^\[ TOWN \][ _-]{0,3}\[ www\.town\.ag \][ _-]{0,3}\[ partner of www\.ssl-news\.info \][ _-]{0,3}\[ .* \] \[\d+\/\d+\][ _-]{0,3}("|#34;)(.+)((\.part\d+\.rar)|(\.vol\d+\+\d+\.par2))("|#34;)[ _-]{0,3}\d+[.,]\d+ [kKmMgG][bB][ _-]{0,3}yEnc$/i"#.to_owned(),
            2,
        ),
        (
            r#"/^\[ TOWN \][ _-]{0,3}\[ www\.town\.ag \][ _-]{0,3}\[ partner of www\.ssl-news\.info \][ _-]{0,3}\[ .* \] \[\d+\/\d+\][ _-]{0,3}("|#34;)(.+)\.(par2|rar|nfo|nzb)("|#34;)[ _-]{0,3}\d+[.,]\d+ [kKmMgG][bB][ _-]{0,3}yEnc$/i"#.to_owned(),
            2,
        ),
        (
            r#"/^\[ TOWN \][ _-]{0,3}\[ www\.town\.ag \][ _-]{0,3}\[ partner of www\.ssl-news\.info \][ _-]{0,3}(\[ TV \] )?\[\d+\/\d+\][ _-]{0,3}("|#34;)(.+)((\.part\d+\.rar)|(\.vol\d+\+\d+\.par2)|\.nfo|\.vol\d+\+\.par2)("|#34;)[ _-]{0,3}\d+[.,]\d+ [kKmMgG][bB][ _-]{0,3}yEnc$/i"#.to_owned(),
            3,
        ),
        (
            r#"/^\[ TOWN \][ _-]{0,3}\[ www\.town\.ag \][ _-]{0,3}\[ partner of www\.ssl-news\.info \][ _-]{0,3}(\[ TV \] )?\[\d+\/\d+\][ _-]{0,3}("|#34;)(.+)\.par2("|#34;)[ _-]{0,3}\d+[.,]\d+ [kKmMgG][bB][ _-]{0,3}yEnc$/i"#.to_owned(),
            3,
        ),
        (
            r#"/^\d+\.-\.(.+) <TOWN><www\.town\.ag >\s+<partner .+>\s+<.+>\s+\[\d+\/\d+\] - ("|#34;).+("|#34;).+yEnc$/"#.to_owned(),
            1,
        ),
        (
            r#"/^\d+\.-\.(.+) <TOWN><www\.town\.ag >\s+<partner .+>\s+<.+>\s+\[\d+\/\d+\] - ("|#34;).+/"#.to_owned(),
            1,
        ),
        (
            r#"/^(\w.+) <TOWN><www\.town\.ag >\s+<partner.+>\s+\[\d+\/\d+\] - ("|#34;).+("|#34;).+yEnc$/"#.to_owned(),
            1,
        ),
        (
            r#"/^(\w.+) <TOWN><www\.town\.ag >\s+<partner.+>\s+\[\d+\/\d+\] - ("|#34;).+/"#.to_owned(),
            1,
        ),
        (
            r#"/^<TOWN><www\.town\.ag >\s+<partner .+>\s+(.+)\s+\[\d+\/\d+\] - ("|#34;).+("|#34;).+yEnc$/"#.to_owned(),
            1,
        ),
        (
            r#"/^<TOWN><www\.town\.ag >\s+<partner .+>\s+(.+)\s+\[\d+\/\d+\] - ("|#34;).+/"#.to_owned(),
            1,
        ),
        (
            r#"/^[ <\[]{0,2}TOWN[ >\]]{0,2}[ _-]{0,3}[ <\[]{0,2}www\.town\.ag[ >\]]{0,2}[ _-]{0,3}[ <\[]{0,2}partner of www.ssl-news\.info[ >\]]{0,2}[ _-]{0,3}\[\d+\/\d+\][ _-]{0,3}("|#34;)(.+)\.(par|vol|rar|nfo).*?("|#34;).+?yEnc$/i"#.to_owned(),
            2,
        ),
        (
            format!(
                r#"/^<TOWN> www\.town\.ag > sponsored by www\.ssl-news\.info > \(\d+\/\d+\) {TOWN_NAME}{E0} - \d+[,.]\d+ [mMkKgG][bB] - yEnc$/"#
            ),
            1,
        ),
        (
            format!(
                r#"/^\(\d+\/\d+\).+?www\.town\.ag.+?sponsored by (www\.)?ssl-news\.info<+?.+? {TOWN_NAME}{E0}{END}"#
            ),
            2,
        ),
        (
            r#"/^\[ TOWN \][ _-]{0,3}\[ www\.town\.ag \][ _-]{0,3}\[ (.+?) \][ _-]{0,3}\[ partner of www\.ssl-news\.info \][ _-]{0,3}\[\d+\/(\d+\])[ _-]{0,3}"(.+)(\.part\d*|\.rar)?(\.vol.+ \(\d+\/\d+\) "|\.[A-Za-z0-9]{2,4}")[ _-]{0,3}yEnc$/i"#.to_owned(),
            1,
        ),
        (
            r#"/^\(TOWN\)\(www\.town\.ag \)[ _-]{0,3}\(partner of www\.ssl-news\.info \)[ _-]{0,3} (.+?) \[\d+\/(\d+\][ _-]{0,3}("|#34;).+?)\.(par2|rar|nfo|nzb)("|#34;)[ _-]{0,3}\d+[.,]\d+ [kKmMgG][bB][ _-]{0,3}yEnc$/"#.to_owned(),
            1,
        ),
        (
            r#"/^<TOWN><www\.town\.ag > <partner of www\.ssl-news\.info > (.+) \[\d+\/\d+\][ _-]{0,3}("|#34;).+?("|#34;).+?yEnc$/i"#.to_owned(),
            1,
        ),
        (
            format!(
                r#"/^\[ TOWN \][ _-]{{0,3}}\[ www\.town\.ag \][ _-]{{0,3}}\[ .* \][ _-]{{0,3}}\[\d+\/\d+\][ _-]{{0,3}}{TOWN_NAME}{E0}{SIZE}{END}"#
            ),
            1,
        ),
    ]
    .into_iter()
    .map(|(pattern, group)| (pcre(&pattern), group))
    .collect()
});

/// `releaseCleanerHelper`: drops the trailing yEnc marker and repeated whitespace.
pub fn cleaner_helper(subject: &str) -> String {
    let without = rx!(r"/(\- )?yEnc$/").replace_all(subject.as_bytes(), b"");
    let collapsed = rx!(r"/\s\s+/").replace_all(&without, b" ");
    php_trim(&text(&collapsed)).to_owned()
}

/// The release name a posted file name implies, or `None` when it doesn't look like one.
pub fn release_name_from_file(file: &str) -> Option<String> {
    let name = strip_file_suffixes(extract_filename_from_path(file));
    let name = rx!(r"/[.\-_](sample|proof|thumbs?)$/i").replace_all(name.as_bytes(), b"");
    let name = rx!(r"/\s\s+/u").replace_all(&name, b" ");
    let name = text(&name);
    let name = name.trim_matches([' ', '.', '-', '_', '\t']).to_owned();
    // A plausibility check counts any final `.word` as a group suffix, which every dotted file name
    // has. Checking with that last dot made a space leaves a `-GROUP`, year, quality or episode tag.
    let without_suffix = match rx!(r"/\.([^.\-]+)$/").captures(name.as_bytes()) {
        Some(found) => {
            let suffix = text(found.group(1).unwrap_or_default());
            let whole = found.group(0).map_or(0, <[u8]>::len);
            let start = name.len().saturating_sub(whole);
            format!("{} {suffix}", name.get(..start).unwrap_or(&name))
        }
        None => name.clone(),
    };
    is_plausible_release_title(&without_suffix).then_some(name)
}

/// `[01/10] - "Release.Name-GRP.mkv" yEnc`: the file name, minus its extensions, is the release name.
/// Hashed names give `None`, so the subject stays for the name-fixing passes.
fn counter_file_name(subject: &str) -> Option<Cleaned> {
    let found = rx!(
        r#"/^\[\s*\d+\s*\/\s*\d+\s*\]\s*-?\s*(?:"|#34;)(?P<file>(?:(?!"|#34;).){4,})(?:"|#34;)/"#
    )
    .captures(subject.as_bytes())?;
    // `lRap` is `yEnc` in ROT13: the whole subject is scrambled, so its file name is too.
    let rest = &subject.as_bytes()[found.group(0)?.len()..];
    if rx!(r"/\blRap\b/i").is_match(rest) {
        return None;
    }
    let file = found
        .named()
        .into_iter()
        .find(|(name, _)| *name == "file")?
        .1;
    release_name_from_file(&text(file)).map(named)
}

fn teevee(subject: &str) -> Cleaned {
    if rx!(r#"/\[\d+\]-\[.+\] - \[\d+\/\d+\] - "\d+\-.+" yEnc/"#).is_match(subject.as_bytes()) {
        return named(subject.to_owned());
    }
    counter_file_name(subject).unwrap_or_else(|| named(cleaner_helper(subject)))
}

fn generic_town(subject: &str) -> Cleaned {
    for (pattern, group) in TOWN.iter() {
        if let Some(found) = pattern.captures(subject.as_bytes()) {
            return Cleaned::Text(text(found.group(*group).unwrap_or_default()));
        }
    }
    named(cleaner_helper(subject))
}

fn generic(subject: &str) -> Cleaned {
    if let Some(name) = counter_file_name(subject) {
        return name;
    }
    // Almost every PreDB release name. Not every subject ends with yEnc: some are truncated at 255
    // characters and some have (5/10) tacked on.
    let found = rx!(r#"/^\[\d+\][\-_\s]{0,3}(\[(reup|full|repost.+?|part|re-repost|xtr|sample)(\])?[\-_\s]{0,3}\[[\- #@\.\w]+\][\-_\s]{0,3}|\[[\- #@\.\w]+\][\-_\s]{0,3}\[(reup|full|repost.+?|part|re-repost|xtr|sample)(\])?[\-_\s]{0,3}|\[.+?efnet\][\-_\s]{0,3}|\[(reup|full|repost.+?|part|re-repost|xtr|sample)(\])?[\-_\s]{0,3})(\[FULL\])?[\-_\s]{0,3}(\[ )?(\[)? ?(\/sz\/)?(F: - )?(?P<title>[\- _!@\.'\w\(\)~]{10,}) ?(\])?[\-_\s]{0,3}(\[)? ?(REPOST|REPACK|SCENE|EXTRA PARS|REAL)? ?(\])?[\-_\s]{0,3}?(\[\d+[\-\/~]\d+\])?[\-_\s]{0,3}["|#34;]*.+["|#34;]* ?[yEnc]{0,4}/i"#)
        .captures(subject.as_bytes());
    if let Some(found) = found
        && let Some((_, title)) = found.named().into_iter().find(|(name, _)| *name == "title")
    {
        return named(text(title));
    }
    named(cleaner_helper(subject))
}

/// The patterns in the code, after PreDB lookups and stored regexes found nothing.
pub fn built_in(subject: &str, group: &str) -> Cleaned {
    if rx!(r"/www\.town\.ag$/i").is_match(subject.as_bytes()) {
        return generic_town(subject);
    }
    if group == "alt.binaries.teevee" {
        return teevee(subject);
    }
    generic(subject)
}

/// The group a request is crossposted from, for the known cases.
fn crosspost_group(group: &str, poster: &str) -> Option<&'static str> {
    match (group, poster) {
        ("alt.binaries.etc", "kingofpr0n (brian@iamking.ws)") => Some("alt.binaries.teevee"),
        (
            "alt.binaries.mom",
            "Yenc@power-post.org (Yenc-PP-A&A)" | "yEncBin@Poster.com (yEncBin)",
        )
        | ("alt.binaries.hdtv.x264", "moovee@4u.tv (moovee)") => Some("alt.binaries.moovee"),
        _ => None,
    }
}

fn season_pack(group: &str, title: &str) -> bool {
    group == "alt.binaries.teevee" && rx!(r"/\.S\d\d\./").is_match(title.as_bytes())
}

/// Every group's every match, in `preg_match_all`'s order.
fn pre_style_names(subject: &str) -> Vec<String> {
    let matches = rx!(r"/([\w\(\)]+[\s\._-]([\w\(\)]+[\s\._-])+[\w\(\)]+-\w+)/")
        .captures_all(subject.as_bytes());
    (0..3)
        .flat_map(|group| {
            matches
                .iter()
                .filter_map(move |found| found.group(group).map(text))
        })
        .collect()
}

async fn request_title(
    pool: &MySqlPool,
    request: &str,
    group: &str,
) -> Result<Option<(String, i64)>> {
    Ok(sqlx::query_as(
        "SELECT p.title, CAST(p.id AS SIGNED) FROM predb p JOIN usenet_groups g ON g.id = p.groups_id \
         WHERE p.requestid = ? AND g.name = ? LIMIT 1",
    )
    .bind(request)
    .bind(group)
    .fetch_optional(pool)
    .await?)
}

type StoredRegexes = Arc<Vec<(i64, Pcre)>>;

/// `ReleaseCleaningService`.
#[derive(Debug, Default)]
pub struct ReleaseCleaner {
    regexes: Mutex<HashMap<String, (Instant, StoredRegexes)>>,
}

impl ReleaseCleaner {
    pub fn new() -> Self {
        Self::default()
    }

    async fn stored(&self, pool: &MySqlPool, group: &str) -> Result<StoredRegexes> {
        if let Ok(cache) = self.regexes.lock()
            && let Some((read, regexes)) = cache.get(group)
            && read.elapsed() < REGEX_CACHE
        {
            return Ok(regexes.clone());
        }
        let rows: Vec<(i64, String)> = sqlx::query_as(
            "SELECT CAST(r.id AS SIGNED), r.regex FROM release_naming_regexes r \
             WHERE ? REGEXP r.group_regex AND r.status = 1 ORDER BY r.ordinal ASC, r.group_regex ASC",
        )
        .bind(group)
        .fetch_all(pool)
        .await?;
        let regexes: StoredRegexes = Arc::new(
            rows.into_iter()
                .filter_map(|(id, regex)| match Pcre::new(&regex) {
                    Ok(regex) => Some((id, regex)),
                    Err(failure) => {
                        warn!(
                            id,
                            error = format!("{failure:#}"),
                            "skipping a naming regex"
                        );
                        None
                    }
                })
                .collect(),
        );
        if let Ok(mut cache) = self.regexes.lock() {
            cache.insert(group.to_owned(), (Instant::now(), regexes.clone()));
        }
        Ok(regexes)
    }

    /// The first stored regex that yields a name: its named groups joined in name order, without
    /// request IDs and part counts. PHP treated '' and '0' alike as nothing found.
    async fn try_stored(
        &self,
        pool: &MySqlPool,
        subject: &str,
        group: &str,
    ) -> Result<Option<(i64, String)>> {
        for (id, regex) in self.stored(pool, group).await?.iter() {
            let Some(found) = regex.captures(subject.as_bytes()) else {
                continue;
            };
            let mut name = Vec::new();
            for (key, value) in found.named() {
                let key = key.to_ascii_lowercase();
                if !key.contains("reqid") && !key.contains("parts") {
                    name.extend_from_slice(value);
                }
            }
            if !name.is_empty() && name != b"0" {
                return Ok(Some((*id, text(&name))));
            }
        }
        Ok(None)
    }

    /// `releaseCleaner` without `$usePre`, which only the name fixer passes.
    pub async fn clean(
        &self,
        pool: &MySqlPool,
        subject: &str,
        poster: &str,
        group: &str,
    ) -> Result<Cleaned> {
        if let Some(unwrapped) = unwrap_nzbsplit(subject) {
            return Ok(Cleaned::Named {
                name: unwrapped,
                proper: true,
                predb: 0,
                regex_id: 0,
            });
        }
        for candidate in pre_style_names(subject) {
            let candidate = php_trim(&candidate);
            let title: Option<(String, i64)> = sqlx::query_as(
                "SELECT title, CAST(id AS SIGNED) FROM predb WHERE title = ? LIMIT 1",
            )
            .bind(candidate)
            .fetch_optional(pool)
            .await?;
            if let Some((title, id)) = title
                && exact_value(&title) == exact_value(candidate)
                && !season_pack(group, &title)
            {
                return Ok(Cleaned::Named {
                    name: title,
                    proper: true,
                    predb: id,
                    regex_id: 0,
                });
            }
        }
        let bytes = subject.as_bytes();
        let request = rx!(r"/^\[ ?(\d{4,6}) ?\]/")
            .captures(bytes)
            .or_else(|| rx!(r"/^REQ\s*(\d{4,6})/i").captures(bytes))
            .or_else(|| rx!(r"/^(\d{4,6})-\d{1}\[/").captures(bytes))
            .or_else(|| rx!(r"/(\d{4,6}) -/").captures(bytes));
        if let Some(request) = request.as_ref().and_then(|found| found.group(1)) {
            let request = text(request);
            let mut title = request_title(pool, &request, group).await?;
            if title.is_none()
                && let Some(other) = crosspost_group(group, poster)
            {
                title = request_title(pool, &request, other).await?;
            }
            if let Some((title, id)) = title
                && !season_pack(group, &title)
            {
                return Ok(Cleaned::Named {
                    name: title,
                    proper: true,
                    predb: id,
                    regex_id: 0,
                });
            }
        }
        if let Some((id, name)) = self.try_stored(pool, subject, group).await? {
            return Ok(Cleaned::Named {
                name,
                proper: false,
                predb: 0,
                regex_id: id,
            });
        }
        Ok(built_in(subject, group))
    }
}
