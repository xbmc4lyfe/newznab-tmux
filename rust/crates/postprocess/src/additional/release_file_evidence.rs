//! `ReleaseFileManager`, second half: what a downloaded PAR2 or NFO file adds to a release, and
//! naming the release from an archive's first inner file or from NZBSPLIT-wrapped NZB titles.
//!
//! As in [`super::release_files`], a rename counts once name fixing has committed it from the
//! identity this attempt holds; nothing here renames inside an outer transaction, so PHP's
//! "matched inside a pending transaction" case doesn't arise.
//!
//! PHP re-read each PAR2 descriptor from the file and checked its packet hash before taking the
//! size from its raw bytes. [`par2_info::parse`] already rejects a file with any packet whose hash
//! fails, so every descriptor it returns passed that check and its length is the size.

use std::path::Path;
use std::sync::LazyLock;

use anyhow::Result;
use chrono::NaiveDateTime;
use nntmux_categorize::cat::OTHERS_GROUP;
use nntmux_categorize::names::unwrap_nzbsplit;
use nntmux_core::pcre::Pcre;
use nntmux_namefix::par2_info::{self, Body};
use nntmux_predb::filename::{exact_value, is_plausible_release_title, normalize_candidate_title};
use sqlx::Row;

use super::context::{FullParHash, ReleaseContext};
use super::planner::NzbFile;
use super::release_files::ReleaseFiles;
use crate::archive_info::{ListEntry, Summary};
use crate::nfo::NfoService;

// PCRE's `$` also matches before a final newline; `Pcre` reads `$` as the very end, so these
// spell PHP's meaning out as `(?=\n?\z)`.
macro_rules! pattern {
    ($name:ident, $pattern:expr) => {
        static $name: LazyLock<Pcre> =
            LazyLock::new(|| Pcre::new($pattern).expect("a valid regex"));
    };
}

pattern!(NFO_EXTENSION, r"/\.(?:nfo|diz|info?)(?=\n?\z)/i");
pattern!(
    NFO_ALTERNATE,
    r"/^(?:file[_-]?id|readme|release|info(?:rmation)?|about|notes?)\.(?:txt|diz)(?=\n?\z)/i"
);
pattern!(NFO_SCENE_00, r"/^00-[a-z0-9_-]+\.nfo(?=\n?\z)/i");
pattern!(NFO_SCENE_ZEROS, r"/^0+-[a-z0-9_-]+\.nfo(?=\n?\z)/i");
pattern!(
    NFO_SCENE_GROUP,
    r"/^[a-z0-9_-]+-[a-z0-9_.-]+\.nfo(?=\n?\z)/i"
);
pattern!(NFO_INFO_TXT, r"/info\.txt(?=\n?\z)/i");
pattern!(
    INNER_EXTENSION,
    r"/\.(mkv|avi|mp4|m4v|mpg|mpeg|wmv|flv|mov|ts|vob|iso|divx|par2?|nfo|sfv|nzb|rar|zip|r\d{2,3}|pkg|exe|msi)(?=\n?\z)/i"
);
pattern!(SCENE_SHAPED, r"/^(.+[-.][A-Za-z0-9_]{2,})(?=\n?\z)/i");
pattern!(
    PREDB_REGEX,
    r"/([\w.'()\[\]-]+(?:[\s._-]+[\w.'()\[\]-]+)+[-.][\w]+)/ui"
);

/// The method and kind PHP's `updateRelease` calls here record.
const RAR_METHOD: &str = "RarInfo FileName Match";
const NZBSPLIT_METHOD: &str = "NZBSPLIT wrapper";
const FILENAMES: &str = "Filenames, ";

/// PHP's `basename` for a path with `/` separators.
fn php_basename(path: &str) -> &str {
    let trimmed = path.trim_end_matches('/');
    if trimmed.is_empty() {
        return "";
    }
    trimmed.rsplit('/').next().unwrap_or(trimmed)
}

/// PHP's `ucwords` with its own delimiters: an ASCII letter at the start or after a delimiter
/// is upper-cased.
fn ucwords(value: &str, delimiters: &[char]) -> String {
    let mut out = String::with_capacity(value.len());
    let mut start = true;
    for c in value.chars() {
        out.push(if start { c.to_ascii_uppercase() } else { c });
        start = delimiters.contains(&c);
    }
    out
}

/// `isNfoFilename`: whether a file name looks like an NFO.
pub fn is_nfo_filename(filename: &str) -> bool {
    if NFO_EXTENSION.is_match(filename.as_bytes()) {
        return true;
    }
    let basename = php_basename(filename).as_bytes();
    [
        &NFO_ALTERNATE,
        &NFO_SCENE_00,
        &NFO_SCENE_ZEROS,
        &NFO_SCENE_GROUP,
        &NFO_INFO_TXT,
    ]
    .iter()
    .any(|pattern| pattern.is_match(basename))
}

/// `iconv('CP437', 'UTF-8//IGNORE//TRANSLIT', ...)` for bytes above ASCII.
const CP437_HIGH: &str = "ÇüéâäàåçêëèïîìÄÅÉæÆôöòûùÿÖÜ¢£¥₧ƒáíóúñÑªº¿⌐¬½¼¡«»░▒▓│┤╡╢╖╕╣║╗╝╜╛┐└┴┬├─┼╞╟╚╔╩╦╠═╬╧╨╤╥╙╘╒╓╫╪┘┌█▄▌▐▀αßΓπΣσµτΦΘΩδ∞φε∩≡±≥≤⌠⌡÷≈°∙·√ⁿ²■\u{a0}";

fn cp437_to_utf8(bytes: &[u8]) -> String {
    let high: Vec<char> = CP437_HIGH.chars().collect();
    bytes
        .iter()
        .map(|&byte| {
            if byte < 0x80 {
                char::from(byte)
            } else {
                high[usize::from(byte - 0x80)]
            }
        })
        .collect()
}

/// `mb_convert_encoding($data, 'UTF-8', 'UTF-16LE' or 'UTF-16BE')`: an unpaired surrogate or a
/// trailing odd byte becomes `?`.
fn utf16_to_utf8(data: &[u8], little_endian: bool) -> Vec<u8> {
    let (pairs, rest) = data.as_chunks::<2>();
    let units = pairs.iter().map(|pair| {
        if little_endian {
            u16::from_le_bytes(*pair)
        } else {
            u16::from_be_bytes(*pair)
        }
    });
    let mut out: String = char::decode_utf16(units)
        .map(|unit| unit.unwrap_or('?'))
        .collect();
    if !rest.is_empty() {
        out.push('?');
    }
    out.into_bytes()
}

/// `normalizeNfoEncoding`: strips a UTF-8 BOM, decodes UTF-16 with a BOM, keeps valid UTF-8 and
/// reads anything else as CP437.
pub fn normalize_nfo_encoding(data: &[u8]) -> Vec<u8> {
    let data = data.strip_prefix(b"\xEF\xBB\xBF").unwrap_or(data);
    let data = if let Some(rest) = data.strip_prefix(b"\xFF\xFE") {
        utf16_to_utf8(rest, true)
    } else if let Some(rest) = data.strip_prefix(b"\xFE\xFF") {
        utf16_to_utf8(rest, false)
    } else {
        data.to_vec()
    };
    if std::str::from_utf8(&data).is_ok() {
        return data;
    }
    cp437_to_utf8(&data).into_bytes()
}

/// `extractReleaseNameFromFile`: a release name from an inner file's name.
pub fn extract_release_name_from_file(filename: &str) -> Option<String> {
    let basename = php_basename(filename);
    if let Some(unwrapped) = unwrap_nzbsplit(basename) {
        return Some(unwrapped);
    }
    let cleaned = INNER_EXTENSION.replace_all(basename.as_bytes(), b"");
    if let Some(found) = SCENE_SHAPED.captures(&cleaned) {
        let name = String::from_utf8_lossy(found.group(1).unwrap_or_default());
        return Some(ucwords(&name, &['.', '-', '_', ' ']));
    }
    PREDB_REGEX.captures(&cleaned).map(|hit| {
        let name = String::from_utf8_lossy(hit.group(0).unwrap_or_default());
        ucwords(&name, &['.'])
    })
}

/// `releaseHasNzbSplitWrapper`.
pub fn has_nzbsplit_wrapper(name: &str, searchname: &str) -> bool {
    name.to_ascii_uppercase().contains("NZBSPLIT")
        || searchname.to_ascii_uppercase().contains("NZBSPLIT")
}

/// The first named file of a listing, as `array_column($files, 'name')[0]` picks it.
fn first_name(files: &[ListEntry]) -> Option<&[u8]> {
    files.iter().find_map(|entry| match entry {
        ListEntry::File(file) => Some(file.name.as_slice()),
        _ => None,
    })
}

/// PHP's `empty()` of a string.
fn php_empty(value: &[u8]) -> bool {
    value.is_empty() || value == b"0"
}

impl ReleaseFiles {
    /// `processPar2File`: queues the PAR2 descriptors' full hashes and file names, and names a
    /// release still in an "other" category from them when PAR2 renaming is on. True when the
    /// file parsed.
    pub async fn process_par2_file(
        &mut self,
        location: &Path,
        context: &mut ReleaseContext,
    ) -> Result<bool> {
        self.fixer.updater.reset();
        let Ok(data) = std::fs::read(location) else {
            return Ok(false);
        };
        let Ok(par2) = par2_info::parse(&data) else {
            return Ok(false);
        };
        let Some(row) = sqlx::query("SELECT postdate, proc_pp FROM releases WHERE id = ?")
            .bind(context.release.id)
            .fetch_optional(&self.pool)
            .await?
        else {
            return Ok(false);
        };
        let post_date: Option<NaiveDateTime> = row.try_get("postdate")?;
        let proc_pp: i64 = row.try_get("proc_pp")?;
        let post_timestamp = post_date.map_or(0, |date| date.and_utc().timestamp());

        let mut found_name = !(proc_pp == 0
            && self.settings.rename_par2
            && i32::try_from(context.release.categories_id)
                .is_ok_and(|category| OTHERS_GROUP.contains(&category)));

        queue_full_par2_evidence(&par2, context);
        let mut files_added = 0usize;
        let limit = self.release_file_limit(context);
        for file in par2.files() {
            let Some(name) = file.name.as_deref() else {
                continue;
            };
            if found_name && files_added >= limit {
                break;
            }
            let name = String::from_utf8_lossy(name).into_owned();
            if self.settings.add_par2_files {
                if files_added < limit
                    && self
                        .queue_release_file(
                            context,
                            &name,
                            i64::try_from(file.size.unwrap_or(0)).unwrap_or(i64::MAX),
                            post_timestamp,
                            0,
                            file.hash_16k.as_deref().unwrap_or(""),
                            "",
                        )
                        .await?
                {
                    files_added += 1;
                }
            } else {
                files_added += 1;
            }
            if !found_name && let Some(release) = self.producer_entry(context) {
                self.fixer.updater.reset();
                self.fixer
                    .check_name(
                        &release,
                        &name,
                        self.settings.echo_cli,
                        "PAR2, ",
                        true,
                        false,
                        true,
                    )
                    .await?;
                let proof = self.fixer.updater.last_committed_identity().cloned();
                if self.accept_producer_name(context, proof.as_ref()) {
                    found_name = true;
                }
            }
        }
        context.found_par2_info = true;
        Ok(true)
    }

    /// `processNfoFile`: stores a downloaded file as the release's NFO when it is one. True when
    /// stored.
    pub async fn process_nfo_file(
        &self,
        location: &Path,
        context: &mut ReleaseContext,
        nfo: &mut NfoService,
    ) -> Result<bool> {
        let data = match std::fs::read(location) {
            Ok(data) => data,
            Err(error) => {
                eprintln!(
                    "could not read potential NFO file {}: {error}",
                    location.display()
                );
                return Ok(false);
            }
        };
        let data = normalize_nfo_encoding(&data);
        if crate::nfo::detect::is_nfo(&data)
            && nfo
                .add_alternate_nfo(
                    &data,
                    context.release.id,
                    &context.release.guid,
                    context.release.completion,
                )
                .await?
        {
            context.release_has_no_nfo = false;
            return Ok(true);
        }
        Ok(false)
    }

    /// `processReleaseNameFromRar`: names the release from the first inner file, or from the
    /// first file of the archive stored under that name.
    pub async fn process_release_name_from_rar(
        &mut self,
        summary: &Summary,
        context: &mut ReleaseContext,
    ) -> Result<()> {
        if context.defers_archive_name_checks() {
            return Ok(());
        }
        let Some(first) = first_name(&summary.file_list) else {
            return Ok(());
        };
        if php_empty(first) {
            return Ok(());
        }
        let first_text = String::from_utf8_lossy(first);
        if let Some(extracted) = extract_release_name_from_file(&first_text) {
            return self.rename_from_inner_name(context, &extracted).await;
        }
        let nested = summary
            .archives
            .iter()
            .find(|(name, _)| name.as_slice() == first)
            .map(|(_, nested)| &nested.file_list)
            .filter(|files| !files.is_empty());
        if let Some(files) = nested {
            let name = String::from_utf8_lossy(first_name(files).unwrap_or_default());
            if let Some(extracted) = extract_release_name_from_file(&name) {
                self.rename_from_inner_name(context, &extracted).await?;
            }
        }
        Ok(())
    }

    /// The PreDB title for an inner name when one matches it exactly, else the cleaned name, if
    /// plausible.
    async fn rename_from_inner_name(
        &mut self,
        context: &mut ReleaseContext,
        extracted: &str,
    ) -> Result<()> {
        let pre: Option<(i64, String)> =
            sqlx::query_as("SELECT CAST(id AS SIGNED), title FROM predb WHERE title = ? LIMIT 1")
                .bind(extracted)
                .fetch_optional(&self.pool)
                .await?;
        let pre = pre.filter(|(_, title)| exact_value(title) == exact_value(extracted));
        let (pre_id, candidate) = match pre {
            Some((id, title)) => (id, title),
            None => (0, normalize_candidate_title(extracted)),
        };
        if is_plausible_release_title(&candidate) {
            self.apply_update(context, &candidate, RAR_METHOD, pre_id)
                .await?;
        }
        Ok(())
    }

    /// `processReleaseNameFromNzbContents`: names an NZBSPLIT-wrapped release from the first
    /// plausible title inside a wrapped NZB file name. True when a rename was accepted.
    pub async fn process_release_name_from_nzb_contents(
        &mut self,
        files: &[NzbFile],
        context: &mut ReleaseContext,
    ) -> Result<bool> {
        if !has_nzbsplit_wrapper(&context.release.name, &context.release.searchname) {
            return Ok(false);
        }
        for file in files {
            if file.title.is_empty() {
                continue;
            }
            let Some(candidate) = unwrap_nzbsplit(&file.title) else {
                continue;
            };
            let candidate = normalize_candidate_title(&candidate);
            if !is_plausible_release_title(&candidate) {
                continue;
            }
            return self
                .apply_update(context, &candidate, NZBSPLIT_METHOD, 0)
                .await;
        }
        Ok(false)
    }

    /// `applyProducerName` around `updateRelease`. True when the rename was accepted.
    async fn apply_update(
        &mut self,
        context: &mut ReleaseContext,
        name: &str,
        method: &str,
        pre_id: i64,
    ) -> Result<bool> {
        let Some(release) = self.producer_entry(context) else {
            return Ok(false);
        };
        self.fixer
            .updater
            .update_release(
                &release, name, method, true, FILENAMES, true, pre_id, None, None,
            )
            .await?;
        let proof = self.fixer.updater.last_committed_identity().cloned();
        Ok(self.accept_producer_name(context, proof.as_ref()))
    }
}

/// `queueFullPar2Evidence`: every descriptor's full MD5, 16 KiB MD5 and size.
fn queue_full_par2_evidence(par2: &par2_info::Par2Info, context: &mut ReleaseContext) {
    for packet in &par2.packets {
        let Body::FileDesc {
            hash,
            hash_16k,
            length,
            ..
        } = &packet.body
        else {
            continue;
        };
        let full = hash.to_ascii_lowercase();
        let prefix = hash_16k.to_ascii_lowercase();
        let size = length.to_string();
        context.pending_full_par_hashes.insert(
            format!("{full}:{prefix}:{size}"),
            FullParHash {
                full_md5: full,
                hash16k: prefix,
                size: Some(size),
            },
        );
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn basenames_follow_php() {
        assert_eq!(php_basename("a/b/c.nfo"), "c.nfo");
        assert_eq!(php_basename("c.nfo"), "c.nfo");
        assert_eq!(php_basename("a/b/"), "b");
        assert_eq!(php_basename(""), "");
        assert_eq!(php_basename("/"), "");
    }

    #[test]
    fn wrapper_detection_ignores_case() {
        assert!(has_nzbsplit_wrapper("x__nzbsplit__y", ""));
        assert!(has_nzbsplit_wrapper("", "NZBSPLIT"));
        assert!(!has_nzbsplit_wrapper("Movie.2160p", "Movie.2160p"));
    }
}
