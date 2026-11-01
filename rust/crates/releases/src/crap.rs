//! `nntmux-ctl releases remove-crap`: removing unwanted releases by SQL shape and blacklist.
//!
//! The SQL matches PHP's `ReleaseRemoverService`, backslashes included: the server's string parser
//! strips them before its regex engine runs. Two changes to the codec and wmv queries: the time
//! filter goes before `GROUP BY`, and they group by every selected column, which any `sql_mode`
//! accepts. PHP appended the filter after `GROUP BY r.id`, which made it part of the grouping
//! expression, so a timed run returned one row per page and could delete old releases. A timed run
//! here deletes every match in its window instead.

use std::collections::{HashMap, HashSet};
use std::time::{Duration, Instant};

use anyhow::{Result, bail};
use nntmux_categorize::cat;
use nntmux_core::pcre::Pcre;
use nntmux_verify::releases::delete_single_on;
use sqlx::{AssertSqlSafe, MySqlPool};
use tracing::{error, warn};

use crate::settings::Settings;

const BATCH_SIZE: usize = 500;
const BATCH_PAUSE: Duration = Duration::from_millis(10);

const OPTYPE_BLACKLIST: u32 = 1;
const FIELD_SUBJECT: u32 = 1;
const FIELD_FROM: u32 = 2;
const STATUS_ENABLED: u32 = 1;
/// PHP's fallback when the minimum release size setting is zero.
const DEFAULT_MIN_SIZE: i64 = 2_097_152;

/// Every type a bare run covers. `passwordurl` and `wmv_all` run only when named.
const DEFAULT_TYPES: [&str; 14] = [
    "blacklist",
    "blfiles",
    "executable",
    "gibberish",
    "hashed",
    "installbin",
    "passworded",
    "sample",
    "scr",
    "short",
    "size",
    "nzb",
    "codec",
    "par2only",
];

#[derive(Clone, Debug)]
pub struct Options {
    pub delete: bool,
    /// Hours, or `full` for no limit.
    pub time: String,
    /// One removal type, or empty for all.
    pub kind: String,
    pub blacklist_id: Option<String>,
}

/// One blacklist rule, compiled. `groups` of `None` applies everywhere.
struct BlacklistRule {
    id: u32,
    field: u32,
    pattern: Pcre,
    groups: Option<HashSet<i64>>,
}

struct Remover<'a> {
    pool: &'a MySqlPool,
    delete: bool,
    time: String,
    blacklist_id: Option<i64>,
    count: u64,
}

/// Runs the removal and returns how many releases were deleted, or would have been.
pub async fn remove(pool: &MySqlPool, options: &Options) -> Result<u64> {
    let time = time_filter(options.time.trim())?;
    let kind = options.kind.trim().to_ascii_lowercase();
    // An ID that doesn't parse must not widen to every rule.
    let blacklist_id = match options.blacklist_id.as_deref().map(str::trim) {
        None | Some("") => None,
        Some(id) => match id.parse::<i64>() {
            Ok(id) => Some(id),
            Err(_) => bail!("Error, blacklist ID must be a whole number."),
        },
    };
    let mut remover = Remover {
        pool,
        delete: options.delete,
        time,
        blacklist_id,
        count: 0,
    };
    if kind.is_empty() {
        for kind in DEFAULT_TYPES {
            remover.run(kind).await?;
        }
    } else {
        remover.run(&kind).await?;
    }
    Ok(remover.count)
}

fn time_filter(time: &str) -> Result<String> {
    if time == "full" {
        return Ok(String::new());
    }
    let hours: f64 = time.parse().unwrap_or(f64::NAN);
    if !hours.is_finite() {
        bail!("Error, time must be a number or full.");
    }
    Ok(format!(" AND r.adddate > (NOW() - INTERVAL {hours} HOUR)"))
}

fn list(categories: &[i32]) -> String {
    categories
        .iter()
        .map(i32::to_string)
        .collect::<Vec<_>>()
        .join(", ")
}

const SELECT: &str = "SELECT r.guid, r.searchname, r.id FROM releases r";
const JOIN_FILES: &str = "JOIN release_files rf ON r.id = rf.releases_id";

impl Remover<'_> {
    async fn run(&mut self, kind: &str) -> Result<()> {
        let time = self.time.clone();
        match kind {
            "blacklist" => self.blacklist().await,
            "blfiles" => self.blacklist_files().await,
            "executable" => {
                let categories =
                    list(&[cat::PC_0DAY, cat::PC_GAMES, cat::OTHER_MISC, cat::OTHER_HASHED]);
                self.simple(
                    "Executable",
                    format!(
                        "{SELECT} {JOIN_FILES} WHERE rf.name LIKE '%.exe' \
                         AND r.categories_id NOT IN ({categories}) {time}"
                    ),
                )
                .await
            }
            "gibberish" => {
                self.simple(
                    "Gibberish",
                    format!(
                        "{SELECT} WHERE r.nfostatus = 0 AND r.iscategorized = 1 AND r.rarinnerfilecount = 0 \
                         AND r.categories_id NOT IN ({}) AND r.searchname REGEXP '^[a-zA-Z0-9]{{15,}}$' {time}",
                        cat::OTHER_HASHED
                    ),
                )
                .await
            }
            "hashed" => {
                self.simple(
                    "Hashed",
                    format!(
                        "{SELECT} WHERE r.nfostatus = 0 AND r.iscategorized = 1 AND r.rarinnerfilecount = 0 \
                         AND r.categories_id NOT IN ({}) AND r.searchname REGEXP '[a-zA-Z0-9]{{25,}}' {time}",
                        list(&[cat::OTHER_MISC, cat::OTHER_HASHED])
                    ),
                )
                .await
            }
            "short" => {
                self.simple(
                    "Short",
                    format!(
                        "{SELECT} WHERE r.nfostatus = 0 AND r.iscategorized = 1 AND r.rarinnerfilecount = 0 \
                         AND r.categories_id NOT IN ({}) AND r.searchname REGEXP '^[a-zA-Z0-9]{{0,5}}$' {time}",
                        cat::OTHER_MISC
                    ),
                )
                .await
            }
            "installbin" => {
                self.simple(
                    "Install.bin",
                    format!("{SELECT} {JOIN_FILES} WHERE rf.name LIKE '%install.bin%' {time}"),
                )
                .await
            }
            "passwordurl" => {
                self.simple(
                    "Password.url",
                    format!("{SELECT} {JOIN_FILES} WHERE rf.name LIKE '%password.url%' {time}"),
                )
                .await
            }
            "passworded" => {
                let categories = list(&[
                    cat::PC_GAMES,
                    cat::PC_0DAY,
                    cat::PC_ISO,
                    cat::PC_MAC,
                    cat::PC_PHONE_ANDROID,
                    cat::PC_PHONE_IOS,
                    cat::PC_PHONE_OTHER,
                    cat::OTHER_MISC,
                    cat::OTHER_HASHED,
                ]);
                self.simple(
                    "Passworded",
                    format!(
                        "{SELECT} WHERE r.searchname LIKE '%passwor%' AND r.searchname NOT LIKE '%advanced%' \
                         AND r.searchname NOT LIKE '%no password%' AND r.searchname NOT LIKE '%not password%' \
                         AND r.searchname NOT LIKE '%recovery%' AND r.searchname NOT LIKE '%reset%' \
                         AND r.searchname NOT LIKE '%unlocker%' AND r.categories_id NOT IN ({categories}) {time}"
                    ),
                )
                .await
            }
            "size" => {
                let configured = Settings::load(self.pool).await?.min_size;
                let min_size = if configured == 0 {
                    DEFAULT_MIN_SIZE
                } else {
                    configured
                };
                let categories = list(&[
                    cat::MUSIC_MP3,
                    cat::BOOKS_COMICS,
                    cat::BOOKS_EBOOK,
                    cat::BOOKS_FOREIGN,
                    cat::BOOKS_MAGAZINES,
                    cat::BOOKS_TECHNICAL,
                    cat::BOOKS_UNKNOWN,
                    cat::PC_0DAY,
                    cat::PC_GAMES,
                    cat::OTHER_MISC,
                    cat::OTHER_HASHED,
                ]);
                self.simple(
                    "Size",
                    format!(
                        "{SELECT} WHERE r.totalpart = 1 AND r.size < {min_size} \
                         AND r.categories_id NOT IN ({categories}) {time}"
                    ),
                )
                .await
            }
            "nzb" => {
                self.simple(
                    ".nzb",
                    format!(
                        "{SELECT} {JOIN_FILES} WHERE r.totalpart = 1 AND rf.name LIKE '%.nzb%' {time}"
                    ),
                )
                .await
            }
            "sample" => {
                let categories = list(&[
                    cat::TV_ANIME,
                    cat::TV_DOCU,
                    cat::TV_FOREIGN,
                    cat::TV_HD,
                    cat::TV_OTHER,
                    cat::TV_SD,
                    cat::TV_SPORT,
                    cat::TV_WEBDL,
                    cat::MOVIE_3D,
                    cat::MOVIE_BLURAY,
                    cat::MOVIE_DVD,
                    cat::MOVIE_FOREIGN,
                    cat::MOVIE_HD,
                    cat::MOVIE_OTHER,
                    cat::MOVIE_SD,
                ]);
                self.simple(
                    "Sample",
                    format!(
                        "{SELECT} WHERE r.totalpart > 1 AND r.size < 40000000 AND r.name LIKE '%sample%' \
                         AND r.categories_id IN ({categories}) {time}"
                    ),
                )
                .await
            }
            "scr" => {
                self.simple(
                    ".scr",
                    format!(
                        "{SELECT} {JOIN_FILES} WHERE (rf.name REGEXP '[.]scr[$ \"]' \
                         OR r.name REGEXP '[.]scr[$ \"]') {time}"
                    ),
                )
                .await
            }
            "wmv_all" => {
                self.simple(
                    "WMV_ALL",
                    format!(
                        "{SELECT} LEFT JOIN release_files rf ON (r.id = rf.releases_id) \
                         WHERE r.categories_id BETWEEN {} AND {} AND rf.name REGEXP 'x264.*\\.wmv$' {time} \
                         GROUP BY r.id, r.guid, r.searchname",
                        cat::TV_ROOT,
                        cat::TV_OTHER
                    ),
                )
                .await
            }
            "codec" => self.codec().await,
            "par2only" => self.par2_only().await,
            other => bail!("Wrong type: {other}"),
        }
    }

    async fn codec(&mut self) -> Result<()> {
        let time = self.time.clone();
        let categories = list(&[
            cat::MOVIE_3D,
            cat::MOVIE_BLURAY,
            cat::MOVIE_DVD,
            cat::MOVIE_FOREIGN,
            cat::MOVIE_HD,
            cat::MOVIE_OTHER,
            cat::MOVIE_SD,
            cat::XXX_WMV,
            cat::XXX_X264,
            cat::XXX_XVID,
            cat::XXX_OTHER,
        ]);
        let video = "\\.*((DVDrip|BRRip)[. ].*[. ](R[56]|HQ)|720p[ .](DVDrip|HQ)|Webrip.*[. ](R[56]|Xvid|AC3|US)\
                     |720p.*[. ]WEB-DL[. ]Xvid[. ]AC3[. ]US|HDRip.*[. ]Xvid[. ]DD5).*[. ]avi$";
        self.simple(
            "Codec",
            format!(
                "{SELECT} LEFT JOIN release_files rf ON r.id = rf.releases_id \
                 WHERE r.categories_id IN ({categories}) \
                 AND ((r.imdbid IS NOT NULL AND r.imdbid NOT IN ('', '0')) OR r.categories_id BETWEEN {} AND {}) \
                 AND r.nfostatus = 1 AND r.haspreview = 0 AND r.jpgstatus = 0 AND r.predb_id = 0 AND r.videostatus = 0 \
                 AND (rf.name REGEXP 'XviD-[a-z]{{3}}\\.(avi|mkv|wmv)$' \
                 OR rf.name REGEXP 'x264.*\\.(wmv|avi)$' \
                 OR rf.name REGEXP '{video}' \
                 OR rf.name LIKE '%\\Codec%Setup.exe%' \
                 OR rf.name LIKE '%\\Codec%Installer.exe%' \
                 OR rf.name LIKE '%\\Codec.exe%' \
                 OR rf.name LIKE '%If_you_get_error.txt%' \
                 OR rf.name LIKE '%read me if the movie not playing.txt%' \
                 OR rf.name LIKE '%Lisez moi si le film ne demarre pas.txt%' \
                 OR rf.name LIKE '%lees me als de film niet spelen.txt%' \
                 OR rf.name LIKE '%Lesen Sie mir wenn der Film nicht abgespielt.txt%' \
                 OR rf.name LIKE '%Lesen Sie mir, wenn der Film nicht starten.txt%') {time} \
                 GROUP BY r.id, r.guid, r.searchname",
                cat::XXX_ROOT,
                cat::XXX_OTHER
            ),
        )
        .await
    }

    /// Releases of nothing but PAR2 files, which can't repair anything alone. The raw subject isn't
    /// consulted: many good releases name their `.par2` index there.
    async fn par2_only(&mut self) -> Result<()> {
        let time = self.time.clone();
        self.simple(
            "Par2Only",
            format!(
                "{SELECT} WHERE r.searchname REGEXP '[.](vol[0-9]+[+][0-9]+[.]par2|par2)([]\" _[)(>]|$)' \
                 AND r.id NOT IN (SELECT rf.releases_id FROM release_files rf WHERE rf.name NOT REGEXP '[.]par2') {time}"
            ),
        )
        .await?;
        self.simple(
            "Par2Only_Files",
            format!(
                "{SELECT} INNER JOIN release_files rf ON r.id = rf.releases_id WHERE 1=1 {time} \
                 GROUP BY r.id, r.guid, r.searchname \
                 HAVING COUNT(*) = SUM(CASE WHEN rf.name REGEXP '[.]par2' THEN 1 ELSE 0 END)"
            ),
        )
        .await
    }

    async fn simple(&mut self, method: &str, query: String) -> Result<()> {
        let page = format!(
            "SELECT CAST(candidates.id AS SIGNED), candidates.guid, candidates.searchname FROM ({query}) candidates \
             WHERE candidates.id > ? ORDER BY candidates.id ASC LIMIT {BATCH_SIZE}"
        );
        let mut last = 0_i64;
        loop {
            let rows: Vec<(i64, String, String)> = sqlx::query_as(AssertSqlSafe(page.clone()))
                .bind(last)
                .fetch_all(self.pool)
                .await?;
            let Some((max, _, _)) = rows.last() else {
                return Ok(());
            };
            last = *max;
            let full = rows.len() == BATCH_SIZE;
            let mut previous = None;
            for (id, guid, searchname) in rows {
                if previous != Some(id) {
                    self.remove_one(method, id, &guid, &searchname).await;
                }
                previous = Some(id);
            }
            if full {
                tokio::time::sleep(BATCH_PAUSE).await;
            }
        }
    }

    /// Deletes one release. PHP logged a failed removal and kept going, and so does this.
    async fn remove_one(&mut self, method: &str, id: i64, guid: &str, searchname: &str) {
        if self.delete {
            println!("Deleting: {method}: {searchname}");
            if let Err(failure) = delete_single_on(self.pool, id, guid).await {
                error!(
                    release = id,
                    error = format!("{failure:#}"),
                    "release removal failed"
                );
                return;
            }
        } else {
            println!("Would be deleting: {method}: {searchname}");
        }
        self.count += 1;
    }

    async fn rules(
        &self,
        rows: Vec<(u32, String, Option<String>, u32)>,
    ) -> Result<Vec<BlacklistRule>> {
        let groups: Vec<(i64, String)> =
            sqlx::query_as("SELECT CAST(id AS SIGNED), name FROM usenet_groups")
                .fetch_all(self.pool)
                .await?;
        Ok(rows
            .into_iter()
            .filter_map(|(id, regex, group, field)| {
                let Ok(pattern) = Pcre::new(&format!("/{regex}/i")) else {
                    warn!(id, "skipping an invalid release-removal blacklist regex");
                    return None;
                };
                let group = group.unwrap_or_default();
                let groups = if group.eq_ignore_ascii_case("alt.binaries.*") {
                    None
                } else {
                    let Ok(pattern) = Pcre::new(&format!("/{group}/i")) else {
                        warn!(id, "skipping a blacklist with an invalid group regex");
                        return None;
                    };
                    let ids: HashSet<i64> = groups
                        .iter()
                        .filter(|(_, name)| pattern.is_match(name.as_bytes()))
                        .map(|(id, _)| *id)
                        .collect();
                    if ids.is_empty() {
                        return None;
                    }
                    Some(ids)
                };
                Some(BlacklistRule {
                    id,
                    field,
                    pattern,
                    groups,
                })
            })
            .collect())
    }

    async fn blacklist(&mut self) -> Result<()> {
        // A single rule may be previewed while disabled, but never deletes unless it is enabled.
        let enabled_only = self.blacklist_id.is_none() || self.delete;
        let status = if enabled_only {
            format!("AND status = {STATUS_ENABLED}")
        } else {
            String::new()
        };
        let only = self
            .blacklist_id
            .map(|id| format!("AND id = {id}"))
            .unwrap_or_default();
        let rows: Vec<(u32, String, Option<String>, u32)> = sqlx::query_as(AssertSqlSafe(format!(
            "SELECT id, regex, groupname, msgcol FROM binaryblacklist \
             WHERE optype = {OPTYPE_BLACKLIST} AND msgcol IN ({FIELD_SUBJECT}, {FIELD_FROM}) {only} {status} ORDER BY id ASC"
        )))
        .fetch_all(self.pool)
        .await?;
        if rows.is_empty() {
            eprintln!(
                "No regular expressions were selected for blacklist removal. Make sure you have activated REGEXPs in Site Edit and you're specifying a valid ID."
            );
            return Ok(());
        }
        let rules = self.rules(rows).await?;
        if rules.is_empty() {
            return Ok(());
        }
        let time = self.time.clone();
        let page = format!(
            "SELECT CAST(r.id AS SIGNED), r.guid, r.searchname, r.fromname, CAST(r.groups_id AS SIGNED) \
             FROM releases r WHERE r.id > ? {time} ORDER BY r.id ASC LIMIT {BATCH_SIZE}"
        );
        let mut last = 0_i64;
        loop {
            let rows: Vec<(i64, String, String, Option<String>, i64)> =
                sqlx::query_as(AssertSqlSafe(page.clone()))
                    .bind(last)
                    .fetch_all(self.pool)
                    .await?;
            let Some(tail) = rows.last() else {
                return Ok(());
            };
            last = tail.0;
            let full = rows.len() == BATCH_SIZE;
            for (id, guid, searchname, fromname, group) in rows {
                let hit = rules.iter().find(|rule| {
                    rule.groups.as_ref().is_none_or(|ids| ids.contains(&group))
                        && rule.pattern.is_match(if rule.field == FIELD_SUBJECT {
                            searchname.as_bytes()
                        } else {
                            fromname.as_deref().unwrap_or("").as_bytes()
                        })
                });
                if let Some(rule) = hit {
                    let method = format!("Blacklist [{}]", rule.id);
                    self.remove_one(&method, id, &guid, &searchname).await;
                }
            }
            if full {
                tokio::time::sleep(BATCH_PAUSE).await;
            }
        }
    }

    async fn blacklist_files(&mut self) -> Result<()> {
        let rows: Vec<(u32, String, Option<String>, u32)> = sqlx::query_as(AssertSqlSafe(format!(
            "SELECT id, regex, groupname, msgcol FROM binaryblacklist \
             WHERE status = {STATUS_ENABLED} AND optype = {OPTYPE_BLACKLIST} AND msgcol = {FIELD_SUBJECT} ORDER BY id ASC"
        )))
        .fetch_all(self.pool)
        .await?;
        let rules = self.rules(rows).await?;
        if rules.is_empty() {
            return Ok(());
        }
        let time = self.time.clone();
        let page = format!(
            "SELECT CAST(r.id AS SIGNED), r.guid, r.searchname, CAST(r.groups_id AS SIGNED) FROM releases r \
             WHERE EXISTS (SELECT 1 FROM release_files rf WHERE rf.releases_id = r.id) AND r.id > ? {time} \
             ORDER BY r.id ASC LIMIT {BATCH_SIZE}"
        );
        let mut last = 0_i64;
        loop {
            let rows: Vec<(i64, String, String, i64)> = sqlx::query_as(AssertSqlSafe(page.clone()))
                .bind(last)
                .fetch_all(self.pool)
                .await?;
            let Some(tail) = rows.last() else {
                return Ok(());
            };
            last = tail.0;
            let full = rows.len() == BATCH_SIZE;
            let ids = rows
                .iter()
                .map(|row| row.0.to_string())
                .collect::<Vec<_>>()
                .join(",");
            let files: Vec<(i64, String)> = sqlx::query_as(AssertSqlSafe(format!(
                "SELECT CAST(releases_id AS SIGNED), name FROM release_files WHERE releases_id IN ({ids})"
            )))
            .fetch_all(self.pool)
            .await?;
            let mut names: HashMap<i64, Vec<String>> = HashMap::new();
            for (release, name) in files {
                names.entry(release).or_default().push(name);
            }
            for (id, guid, searchname, group) in rows {
                let files = names.get(&id).map_or(&[][..], Vec::as_slice);
                let hit = rules.iter().find(|rule| {
                    rule.groups.as_ref().is_none_or(|ids| ids.contains(&group))
                        && files
                            .iter()
                            .any(|name| rule.pattern.is_match(name.as_bytes()))
                });
                if let Some(rule) = hit {
                    let method = format!("Blacklist Files {}", rule.id);
                    self.remove_one(&method, id, &guid, &searchname).await;
                }
            }
            if full {
                tokio::time::sleep(BATCH_PAUSE).await;
            }
        }
    }
}

/// The command: reports the count the way PHP's closing line did.
pub async fn run(pool: &MySqlPool, options: &Options) -> Result<()> {
    if !options.delete {
        println!("Running in DRY-RUN mode. Use --delete to actually remove releases.");
    }
    let started = Instant::now();
    let count = remove(pool, options).await?;
    println!(
        "{} {count} release(s). This script ran for {} seconds",
        if options.delete {
            "Deleted"
        } else {
            "Would have deleted"
        },
        started.elapsed().as_secs()
    );
    Ok(())
}
