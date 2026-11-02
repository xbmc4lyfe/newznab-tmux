//! NFO processing: `NfoService` and the NFO half of `NzbContentsService`.
//!
//! Each round takes releases whose NFO is unknown or still being retried, finds the NFO article
//! the NZB names (or a small file that may be one), downloads it, and keeps it when
//! [`detect::is_nfo`] agrees. When that fails, an NFO listed inside the release's archive is
//! extracted with unrar. A second pass gives releases that ran out of retries one archive try.
//!
//! Two differences from PHP:
//! - With `lookup_par2` set, PHP also parsed the first PAR2 index file it met while scanning the
//!   NZB for an NFO. That PAR2 rename isn't ported here yet; the scan reports the index files and
//!   the setting is ignored with a warning.
//! - The NFO is stored with MySQL's `COMPRESS()`, which `UNCOMPRESS()` reads like PHP's
//!   hand-built header did.

pub mod detect;
pub mod nzb_scan;

use std::path::{Path, PathBuf};
use std::sync::LazyLock;
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::Duration;

use anyhow::Result;
use nntmux_core::pcre::Pcre;
use nntmux_nntp::{Identifier, NntpClient};
use sqlx::{MySql, MySqlPool, QueryBuilder};

use crate::additional::archive_extraction::{
    COMMAND_TIMEOUT_CAP, directory_argument, run, visible_files,
};
use crate::additional::nzb_contents::document_files;
use crate::config::Config;
use nzb_scan::NfoArticle;

/// `NfoService::NFO_FAILED`: out of retries.
pub const NFO_FAILED: i64 = -9;
/// `NFO_FAILED_ARCHIVE`: the archive try failed too; no more retries.
pub const NFO_FAILED_ARCHIVE: i64 = -10;
/// `NFO_UNPROC`: not processed yet.
pub const NFO_UNPROC: i64 = -1;
/// `NFO_NONFO`: the release has no NFO.
pub const NFO_NONFO: i64 = 0;
/// `NFO_FOUND`.
pub const NFO_FOUND: i64 = 1;

/// Segments of an archive downloaded to look for an NFO inside.
const ARCHIVE_SEGMENTS: usize = 40;
/// NFO names from the release's file list tried per archive.
const ARCHIVE_NFO_NAMES: i64 = 3;
/// `release_files` names an NFO can have, as `ReleaseFile::scopeNfoFiles`.
const NFO_FILE_NAMES: &str = "(name LIKE '%.nfo' OR name LIKE '%.diz' OR name LIKE '%.inf' \
     OR name LIKE '%file\\_id.diz' OR name LIKE '%info.txt')";

static FIRST_ARCHIVE: LazyLock<Pcre> = LazyLock::new(|| {
    Pcre::new(
        r#"/(\.(part0*1|rar|zip))(\s*\.rar)*($|[ ")]|-])|"[a-f0-9]{32}\.[1-9]\d{1,2}".*\(\d+\/\d{2,}\)$/i"#,
    )
    .expect("a valid regex")
});

static PAR2_WARNED: AtomicBool = AtomicBool::new(false);

/// What NFO processing reads from the settings and environment.
#[derive(Clone, Debug)]
pub struct Settings {
    /// Releases per pass.
    pub batch: i64,
    /// The lowest `nfostatus` still retried.
    pub lowest_status: i64,
    /// Releases this size and up are skipped; 0 for no limit.
    pub max_size: i64,
    /// Releases this size and down are skipped; 0 for no limit.
    pub min_size: i64,
    pub lookup_par2: bool,
    pub alternate: bool,
    pub unrar: Option<PathBuf>,
    pub tmp_path: PathBuf,
    pub command_timeout: Duration,
}

impl Settings {
    pub fn from_config(config: &Config, alternate: bool) -> Self {
        let settings = &config.settings;
        let command_timeout = match u64::try_from(settings.timeout_seconds) {
            Ok(seconds) if seconds > 0 && config.timeout_path.is_some() => {
                Duration::from_secs(seconds).min(COMMAND_TIMEOUT_CAP)
            }
            _ => COMMAND_TIMEOUT_CAP,
        };
        Self {
            batch: settings.max_nfo_processed,
            lowest_status: lowest_status(settings.max_nfo_retries),
            max_size: settings.max_size_to_process_nfo,
            min_size: settings.min_size_to_process_nfo,
            lookup_par2: settings.lookup_par2,
            alternate,
            unrar: config
                .unrar_path
                .as_deref()
                .filter(|path| !path.is_empty())
                .map(PathBuf::from),
            tmp_path: config.tmp_unrar_path.clone(),
            command_timeout,
        }
    }
}

/// `getMaxRetries`: each retry takes the status one lower, and -8 is the floor.
pub fn lowest_status(max_retries: i64) -> i64 {
    let computed = if max_retries >= 0 {
        -(max_retries + 1)
    } else {
        NFO_UNPROC
    };
    computed.max(-8)
}

/// A release to look for an NFO for.
#[derive(Clone, Debug, sqlx::FromRow)]
pub struct Candidate {
    #[sqlx(try_from = "u32")]
    pub id: i64,
    pub guid: String,
    #[sqlx(try_from = "u32")]
    pub groups_id: i64,
    pub name: String,
}

/// One worker's NFO processing, with its own NNTP connection.
pub struct NfoService {
    pub pool: MySqlPool,
    pub settings: Settings,
    pub client: NntpClient,
}

impl NfoService {
    pub fn new(pool: MySqlPool, settings: Settings, client: NntpClient) -> Self {
        Self {
            pool,
            settings,
            client,
        }
    }

    /// `processNfoFiles`: one pass over the backlog, optionally for one group or GUID bucket,
    /// then the archive pass. Returns the NFOs stored.
    pub async fn process_nfo_files(
        &mut self,
        group_id: Option<i64>,
        guid_char: &str,
    ) -> Result<i64> {
        let mut query = QueryBuilder::<MySql>::new(
            "SELECT id, guid, groups_id, name FROM releases WHERE nfostatus BETWEEN ",
        );
        query
            .push_bind(self.settings.lowest_status)
            .push(" AND ")
            .push_bind(NFO_UNPROC)
            .push(" AND late_article_candidate_id IS NULL");
        self.push_filters(&mut query, group_id, guid_char);
        if self.settings.max_size > 0 {
            query.push(" AND size < ").push_bind(self.settings.max_size);
        }
        if self.settings.min_size > 0 {
            query.push(" AND size > ").push_bind(self.settings.min_size);
        }
        query
            .push(" ORDER BY nfostatus, postdate DESC LIMIT ")
            .push_bind(self.settings.batch.max(0));
        let releases: Vec<Candidate> = query.build_query_as().fetch_all(&self.pool).await?;
        let mut stored = 0;
        for release in &releases {
            match self.process_release(release).await {
                Ok(true) => stored += 1,
                Ok(false) => {}
                Err(failure) => eprintln!("Error processing release {}: {failure:#}", release.id),
            }
        }
        stored += self.process_failed_via_archive(group_id, guid_char).await?;
        if stored > 0 {
            println!("{stored} NFO file(s) found/processed.");
        }
        Ok(stored)
    }

    fn push_filters(
        &self,
        query: &mut QueryBuilder<MySql>,
        group_id: Option<i64>,
        guid_char: &str,
    ) {
        if !guid_char.is_empty() {
            query
                .push(" AND leftguid = ")
                .push_bind(guid_char.to_owned());
        }
        if let Some(group_id) = group_id {
            query.push(" AND groups_id = ").push_bind(group_id);
        }
    }

    async fn process_release(&mut self, release: &Candidate) -> Result<bool> {
        let group_name: Option<String> =
            sqlx::query_scalar("SELECT name FROM usenet_groups WHERE id = ?")
                .bind(release.groups_id)
                .fetch_optional(&self.pool)
                .await?;
        let mut nfo = self
            .get_nfo_from_nzb(release, group_name.as_deref().unwrap_or(""))
            .await?;
        if nfo.is_none() {
            nfo = self
                .attempt_nfo_from_archive(&release.guid, release.id)
                .await?;
        }
        let Some(nfo) = nfo else {
            return Ok(false);
        };
        match self.store(release.id, &nfo).await {
            Ok(()) => Ok(true),
            Err(failure) => {
                eprintln!("Error saving NFO for release {}: {failure:#}", release.id);
                Ok(false)
            }
        }
    }

    /// `getNfoFromNzb`: the NFO the NZB points at, verified. A missing or wrong candidate means
    /// no NFO; a failed download takes one retry.
    pub async fn get_nfo_from_nzb(
        &mut self,
        release: &Candidate,
        group_name: &str,
    ) -> Result<Option<Vec<u8>>> {
        let Some(article) = self.parse_nzb(&release.guid, release.id, true).await? else {
            self.set_status(release.id, NFO_NONFO).await?;
            return Ok(None);
        };
        let fetched = self
            .client
            .get_messages(
                group_name,
                &[Identifier::parse(&article.message_id)],
                self.settings.alternate,
                None,
            )
            .await;
        let data = match fetched {
            Ok(data) => data,
            Err(_) => {
                sqlx::query("UPDATE releases SET nfostatus = nfostatus - 1 WHERE id = ?")
                    .bind(release.id)
                    .execute(&self.pool)
                    .await?;
                return Ok(None);
            }
        };
        if detect::is_nfo(&data) {
            return Ok(Some(data));
        }
        self.set_status(release.id, NFO_NONFO).await?;
        Ok(None)
    }

    /// `parseNzb`: records the completion and, with `nfo_check`, returns the NFO article to try.
    /// No candidate in a readable NZB marks the release as having no NFO.
    pub async fn parse_nzb(
        &mut self,
        guid: &str,
        release_id: i64,
        nfo_check: bool,
    ) -> Result<Option<NfoArticle>> {
        let Some(contents) = nntmux_verify::nzb::read(&self.pool, guid).await? else {
            return Ok(None);
        };
        let Some(files) = document_files(&contents) else {
            return Ok(None);
        };
        let scan = nzb_scan::scan(&files, nfo_check);
        if self.settings.lookup_par2
            && !scan.par2_indexes.is_empty()
            && !PAR2_WARNED.swap(true, Ordering::Relaxed)
        {
            eprintln!("lookup_par2 is set, but PAR2 parsing during NFO lookup isn't ported yet.");
        }
        sqlx::query("UPDATE releases SET completion = ? WHERE id = ?")
            .bind(scan.completion)
            .bind(release_id)
            .execute(&self.pool)
            .await?;
        if !nfo_check {
            return Ok(None);
        }
        match scan.nfo {
            Some(article) if article.message_id.len() > 1 => Ok(Some(article)),
            Some(_) => Ok(None),
            None => {
                self.set_status(release_id, NFO_NONFO).await?;
                Ok(None)
            }
        }
    }

    /// `attemptNfoFromArchive`: an NFO the release's file list names, extracted from the first
    /// archive in the NZB. NFO names with no content, or an archive that didn't yield one, mean
    /// no NFO.
    pub async fn attempt_nfo_from_archive(
        &mut self,
        guid: &str,
        release_id: i64,
    ) -> Result<Option<Vec<u8>>> {
        let Some(unrar) = self.settings.unrar.clone() else {
            return Ok(None);
        };
        let names: Vec<String> = sqlx::query_scalar(sqlx::AssertSqlSafe(format!(
            "SELECT name FROM release_files WHERE releases_id = ? AND {NFO_FILE_NAMES} LIMIT {ARCHIVE_NFO_NAMES}"
        )))
        .bind(release_id)
        .fetch_all(&self.pool)
        .await?;
        if names.is_empty() {
            return Ok(None);
        }
        let contentful: Option<i64> = sqlx::query_scalar(sqlx::AssertSqlSafe(format!(
            "SELECT 1 FROM release_files WHERE releases_id = ? AND {NFO_FILE_NAMES} AND size > 0 LIMIT 1"
        )))
        .bind(release_id)
        .fetch_optional(&self.pool)
        .await?;
        if contentful.is_none() {
            self.set_status(release_id, NFO_NONFO).await?;
            return Ok(None);
        }
        let Some(contents) = nntmux_verify::nzb::read(&self.pool, guid).await? else {
            return Ok(None);
        };
        let cleaned: Vec<u8> = contents.into_iter().filter(|byte| *byte != 0x0f).collect();
        let Some(files) = document_files(&cleaned) else {
            return Ok(None);
        };
        let mut tried = false;
        for file in &files {
            if !FIRST_ARCHIVE.is_match(file.subject.as_bytes()) {
                continue;
            }
            let segments = file.segments_in_file_order();
            if segments.is_empty() {
                continue;
            }
            let wanted: Vec<String> = segments.into_iter().take(ARCHIVE_SEGMENTS).collect();
            let data = match self
                .client
                .get_messages_by_message_id(&wanted, self.settings.alternate, None)
                .await
            {
                Ok(data) if !data.is_empty() => data,
                _ => continue,
            };
            tried = true;
            let (tmp, timeout, names) = (
                self.settings.tmp_path.clone(),
                self.settings.command_timeout,
                names.clone(),
            );
            let extracted = tokio::task::spawn_blocking(move || {
                extract_with_unrar(&unrar, &tmp, timeout, &data, &names)
            })
            .await?;
            if extracted.is_some() {
                return Ok(extracted);
            }
            break;
        }
        if tried {
            self.set_status(release_id, NFO_NONFO).await?;
        }
        Ok(None)
    }

    /// `processFailedReleasesViaArchive`: releases out of retries whose file list names an NFO
    /// with content get one archive try, and are retired when it fails.
    async fn process_failed_via_archive(
        &mut self,
        group_id: Option<i64>,
        guid_char: &str,
    ) -> Result<i64> {
        let mut query = QueryBuilder::<MySql>::new(
            "SELECT id, guid, groups_id, name FROM releases WHERE nfostatus = ",
        );
        query.push_bind(NFO_FAILED).push(format!(
            " AND EXISTS (SELECT 1 FROM release_files WHERE release_files.releases_id = releases.id \
             AND size > 0 AND {NFO_FILE_NAMES}) AND late_article_candidate_id IS NULL"
        ));
        self.push_filters(&mut query, group_id, guid_char);
        query.push(" LIMIT ").push_bind(self.settings.batch.max(0));
        let releases: Vec<Candidate> = query.build_query_as().fetch_all(&self.pool).await?;
        let mut stored = 0;
        for release in &releases {
            match self
                .attempt_nfo_from_archive(&release.guid, release.id)
                .await
            {
                Ok(Some(nfo)) => match self.store(release.id, &nfo).await {
                    Ok(()) => stored += 1,
                    Err(failure) => {
                        eprintln!(
                            "Error saving archive NFO for release {}: {failure:#}",
                            release.id
                        )
                    }
                },
                Ok(None) => self.set_status(release.id, NFO_FAILED_ARCHIVE).await?,
                Err(failure) => {
                    eprintln!(
                        "Error in archive NFO extraction for release {}: {failure:#}",
                        release.id
                    );
                    self.set_status(release.id, NFO_FAILED_ARCHIVE).await?;
                }
            }
        }
        Ok(stored)
    }

    /// `addAlternateNfo`: an NFO found elsewhere, such as inside an archive. Keeps an NFO already
    /// stored, and reads the NZB's completion when it wasn't known.
    pub async fn add_alternate_nfo(
        &mut self,
        nfo: &[u8],
        release_id: i64,
        guid: &str,
        completion: f64,
    ) -> Result<bool> {
        if release_id <= 0 || !detect::is_nfo(nfo) {
            return Ok(false);
        }
        sqlx::query("INSERT IGNORE INTO release_nfos (releases_id, nfo) VALUES (?, COMPRESS(?))")
            .bind(release_id)
            .bind(nfo)
            .execute(&self.pool)
            .await?;
        self.set_status(release_id, NFO_FOUND).await?;
        if completion == 0.0 {
            self.parse_nzb(guid, release_id, false).await?;
        }
        Ok(true)
    }

    /// Stores an NFO unless one is already there, and marks the release found.
    async fn store(&self, release_id: i64, nfo: &[u8]) -> Result<()> {
        let mut tx = self.pool.begin().await?;
        sqlx::query("INSERT IGNORE INTO release_nfos (releases_id, nfo) VALUES (?, COMPRESS(?))")
            .bind(release_id)
            .bind(nfo)
            .execute(&mut *tx)
            .await?;
        sqlx::query("UPDATE releases SET nfostatus = ? WHERE id = ?")
            .bind(NFO_FOUND)
            .bind(release_id)
            .execute(&mut *tx)
            .await?;
        tx.commit().await?;
        Ok(())
    }

    async fn set_status(&self, release_id: i64, status: i64) -> Result<()> {
        sqlx::query("UPDATE releases SET nfostatus = ? WHERE id = ?")
            .bind(status)
            .bind(release_id)
            .execute(&self.pool)
            .await?;
        Ok(())
    }
}

/// `extractNfoViaUnrar`: each named NFO in turn, the first one that is an NFO. Everything written
/// is removed afterwards.
pub fn extract_with_unrar(
    unrar: &Path,
    tmp: &Path,
    timeout: Duration,
    data: &[u8],
    names: &[String],
) -> Option<Vec<u8>> {
    std::fs::create_dir_all(tmp).ok()?;
    let work = tempfile::Builder::new()
        .prefix("nfo_")
        .tempdir_in(tmp)
        .ok()?;
    let archive = work.path().join("archive.rar");
    let extract = work.path().join("extract");
    std::fs::create_dir_all(&extract).ok()?;
    std::fs::write(&archive, data).ok()?;
    for name in names {
        let mut command = std::process::Command::new(unrar);
        command
            .args(["e", "-y", "-c-", "-inul", "-p-", "-@", "--"])
            .arg(&archive)
            .arg(name)
            .arg(directory_argument(&extract));
        run(command, timeout);
        let base = basename(name);
        let mut path = extract.join(base);
        if !path.is_file() {
            let mut found = Vec::new();
            visible_files(&extract, &mut found);
            if let Some(matched) = found.into_iter().find(|file| {
                file.file_name()
                    .and_then(|file| file.to_str())
                    .is_some_and(|file| file.eq_ignore_ascii_case(base))
            }) {
                path = matched;
            }
        }
        if let Ok(content) = std::fs::read(&path)
            && !content.is_empty()
            && detect::is_nfo(&content)
        {
            return Some(content);
        }
    }
    None
}

/// PHP's `basename` for the separators an archive name uses.
fn basename(name: &str) -> &str {
    name.trim_end_matches('/')
        .rsplit('/')
        .next()
        .unwrap_or(name)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn retries_floor_at_minus_eight() {
        assert_eq!(lowest_status(5), -6);
        assert_eq!(lowest_status(0), -1);
        assert_eq!(lowest_status(20), -8);
        assert_eq!(lowest_status(-3), -1);
    }

    #[test]
    fn first_archives_are_recognised() {
        for subject in [
            "\"Movie.2160p.part01.rar\" yEnc (1/50)",
            "\"Movie.2160p.rar\" yEnc (1/50)",
            "\"Movie.zip\" yEnc (1/5)",
            "\"0123456789abcdef0123456789abcdef.10\" yEnc (1/50)",
        ] {
            assert!(FIRST_ARCHIVE.is_match(subject.as_bytes()), "{subject}");
        }
        // Any RAR volume ends in `.rar"`, so PHP took the first listed volume, whichever it was.
        assert!(FIRST_ARCHIVE.is_match(b"\"Movie.2160p.part02.rar\" yEnc (1/50)"));
        assert!(!FIRST_ARCHIVE.is_match(b"\"Movie.mkv\" yEnc (1/50)"));
        assert_eq!(basename("dir/x.nfo"), "x.nfo");
        assert_eq!(basename("x.nfo"), "x.nfo");
    }
}
