//! Sample pictures and NFOs copied back out of the release's archives. Port of
//! `ReleaseFilesArchiveFallback`.

use std::collections::HashMap;
use std::sync::Arc;

use anyhow::Result;

use super::archive_extraction::{blocking, complete_stored_entries, is_nfo_file};
use super::context::ReleaseContext;
use super::download::DownloadKind;
use super::processor::{IMAGE_FILE, ReleaseProcessor, unique_id};
use crate::archive_info::ListEntry;
use crate::media::images;

/// At most this many files are tried per fallback.
const CANDIDATES: usize = 3;

/// What an extracted file is tried as.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum Handler {
    SamplePicture,
    Nfo,
}

impl ReleaseProcessor<'_> {
    /// `processJpgFromArchiveFileList`: images listed in an archive already downloaded, those
    /// that can be copied straight out of it first, then the most telling.
    pub(crate) async fn process_jpg_from_archive_file_list(
        &mut self,
        data: &Arc<Vec<u8>>,
        files: &[ListEntry],
        context: &mut ReleaseContext,
    ) -> Result<()> {
        let mut image_files: Vec<ListEntry> = files
            .iter()
            .filter(|entry| match entry {
                ListEntry::File(file) => file.pass == 0 && IMAGE_FILE.is_match(&file.name),
                _ => false,
            })
            .cloned()
            .collect();
        if image_files.is_empty() {
            return Ok(());
        }
        let complete = complete_stored_entries(&image_files);
        image_files.sort_by_key(|entry| {
            let ListEntry::File(file) = entry else {
                return (true, u8::MAX, 0);
            };
            (
                !complete.contains_key(&file.name),
                images::rank(&String::from_utf8_lossy(&file.name)),
                -file.size,
            )
        });
        image_files.truncate(CANDIDATES);
        let names = names_of(&image_files);
        self.process_extracted_candidates_from_data(
            data,
            &names,
            context,
            "extracted_",
            Handler::SamplePicture,
        )
        .await
    }

    /// `processJpgFromReleaseFiles`: downloads the first volumes again for the images the
    /// release's file list names.
    pub(crate) async fn process_jpg_from_release_files(
        &mut self,
        context: &mut ReleaseContext,
    ) -> Result<()> {
        let mut rows: Vec<(String, i64)> = sqlx::query_as(
            "SELECT name, CAST(size AS SIGNED) FROM release_files WHERE releases_id = ? AND \
             (name LIKE '%.jpg' OR name LIKE '%.jpeg' OR name LIKE '%.png' OR name LIKE '%.webp') \
             ORDER BY size DESC LIMIT 10",
        )
        .bind(context.release.id)
        .fetch_all(&self.pool)
        .await?;
        if rows.is_empty() {
            return Ok(());
        }
        images::sort_candidates(&mut rows, |(name, _)| name, |(_, size)| *size);
        rows.truncate(CANDIDATES);
        let names = rows
            .into_iter()
            .map(|(name, _)| name.into_bytes())
            .collect();
        self.process_stored_release_files(context, names, Handler::SamplePicture)
            .await
    }

    /// `processNfoFromReleaseFiles`: downloads the first volumes again for the NFOs the release's
    /// file list names.
    pub(crate) async fn process_nfo_from_release_files(
        &mut self,
        context: &mut ReleaseContext,
    ) -> Result<()> {
        let names: Vec<String> = sqlx::query_scalar(
            "SELECT name FROM release_files WHERE releases_id = ? AND (name LIKE '%.nfo' OR \
             name LIKE '%.diz' OR name LIKE '%.inf' OR name LIKE '%file\\_id.diz' OR \
             name LIKE '%info.txt') LIMIT 3",
        )
        .bind(context.release.id)
        .fetch_all(&self.pool)
        .await?;
        if names.is_empty() {
            return Ok(());
        }
        let names = names.into_iter().map(String::into_bytes).collect();
        self.process_stored_release_files(context, names, Handler::Nfo)
            .await
    }

    /// `processNfoFromDownloadedArchives`: NFOs in the archives kept from the walk.
    pub(crate) async fn process_nfo_from_downloaded_archives(
        &mut self,
        context: &mut ReleaseContext,
    ) -> Result<()> {
        if context.downloaded_archives.is_empty() || !context.release_has_no_nfo {
            return Ok(());
        }
        for archive in context.downloaded_archives.clone() {
            let mut nfo_files: Vec<ListEntry> = archive
                .files
                .iter()
                .filter(|entry| matches!(entry, ListEntry::File(file) if is_nfo_file(&file.name)))
                .cloned()
                .collect();
            nfo_files.truncate(CANDIDATES);
            let names = names_of(&nfo_files);
            self.process_extracted_candidates_from_data(
                &archive.data,
                &names,
                context,
                "downloaded_nfo_",
                Handler::Nfo,
            )
            .await?;
            if !context.release_has_no_nfo {
                return Ok(());
            }
        }
        Ok(())
    }

    /// `processStoredReleaseFiles`: each likely first volume, downloaded again, until a named
    /// file in it is used.
    async fn process_stored_release_files(
        &mut self,
        context: &mut ReleaseContext,
        names: Vec<Vec<u8>>,
        handler: Handler,
    ) -> Result<()> {
        let candidates: Vec<_> = context
            .work_plan
            .as_ref()
            .map(|plan| {
                plan.archive_candidates
                    .iter()
                    .filter(|candidate| candidate.likely_first_volume)
                    .cloned()
                    .collect()
            })
            .unwrap_or_default();
        if names.is_empty() || candidates.is_empty() {
            return Ok(());
        }
        let wanted: Vec<Vec<u8>> = names.into_iter().filter(|name| !name.is_empty()).collect();
        for candidate in candidates {
            let done = match handler {
                Handler::SamplePicture => context.found_jpg_sample,
                Handler::Nfo => !context.release_has_no_nfo,
            };
            if done {
                break;
            }
            let download = self
                .downloads
                .download(
                    DownloadKind::Compressed,
                    &candidate.message_ids,
                    &context.release_group_name,
                    true,
                )
                .await;
            if download.group_unavailable {
                context.group_unavailable = true;
                return Ok(());
            }
            let Some(data) = download.data.filter(|data| !data.is_empty()) else {
                continue;
            };
            let data = Arc::new(data);
            let extracted = self
                .extract_blocking(&data, wanted.clone(), context)
                .await?;
            if self
                .use_extracted(&wanted, &extracted, context, "release_file_", handler)
                .await?
            {
                return Ok(());
            }
        }
        Ok(())
    }

    /// `processExtractedCandidatesFromData`.
    async fn process_extracted_candidates_from_data(
        &mut self,
        data: &Arc<Vec<u8>>,
        names: &[Vec<u8>],
        context: &mut ReleaseContext,
        prefix: &str,
        handler: Handler,
    ) -> Result<()> {
        let wanted = names
            .iter()
            .filter(|name| !name.is_empty())
            .cloned()
            .collect();
        let extracted = self.extract_blocking(data, wanted, context).await?;
        self.use_extracted(names, &extracted, context, prefix, handler)
            .await?;
        Ok(())
    }

    /// `Extractor::extract_specific_files` on a blocking thread.
    async fn extract_blocking(
        &self,
        data: &Arc<Vec<u8>>,
        names: Vec<Vec<u8>>,
        context: &ReleaseContext,
    ) -> Result<HashMap<Vec<u8>, Vec<u8>>> {
        let extractor = self.config.extractor.clone();
        let data = Arc::clone(data);
        let tmp = context.tmp_path.clone();
        blocking(move || {
            let filenames: Vec<&[u8]> = names.iter().map(Vec::as_slice).collect();
            extractor.extract_specific_files(&data, &filenames, &tmp)
        })
        .await?
    }

    /// The first extracted file, in the order named, that the handler takes.
    async fn use_extracted(
        &mut self,
        names: &[Vec<u8>],
        extracted: &HashMap<Vec<u8>, Vec<u8>>,
        context: &mut ReleaseContext,
        prefix: &str,
        handler: Handler,
    ) -> Result<bool> {
        for name in names {
            if name.is_empty() {
                continue;
            }
            let Some(data) = extracted.get(name).filter(|data| !data.is_empty()) else {
                continue;
            };
            if self
                .with_extracted_temp_file(name, data, context, prefix, handler)
                .await?
            {
                return Ok(true);
            }
        }
        Ok(false)
    }

    /// `withExtractedTempFile`: the file written under the release's temp folder, keeping its
    /// extension, for as long as the handler looks at it.
    async fn with_extracted_temp_file(
        &mut self,
        name: &[u8],
        data: &[u8],
        context: &mut ReleaseContext,
        prefix: &str,
        handler: Handler,
    ) -> Result<bool> {
        let path =
            context
                .tmp_path
                .join(format!("{prefix}{}{}", unique_id(), extension_suffix(name)));
        if std::fs::write(&path, data).is_err() {
            return Ok(false);
        }
        let used = match handler {
            Handler::SamplePicture => {
                let saved = self.media.usable_image(&path).await
                    && self.media.jpg_sample(&path, &context.release.guid).await;
                if saved {
                    context.found_jpg_sample = true;
                }
                Ok(saved)
            }
            Handler::Nfo => self.files.process_nfo_file(&path, context, self.nfo).await,
        };
        let _ = std::fs::remove_file(&path);
        used
    }
}

fn names_of(files: &[ListEntry]) -> Vec<Vec<u8>> {
    files
        .iter()
        .map(|entry| match entry {
            ListEntry::File(file) => file.name.clone(),
            _ => Vec::new(),
        })
        .collect()
}

/// `.` and the lowercased extension of a file name, as `pathinfo` gave it; none when the
/// extension is not short and alphanumeric, so a listed name can't shape the temp path.
fn extension_suffix(name: &[u8]) -> String {
    let base = name.rsplit(|byte| *byte == b'/').next().unwrap_or_default();
    let Some(dot) = base.iter().rposition(|byte| *byte == b'.') else {
        return String::new();
    };
    let extension = &base[dot + 1..];
    if extension.is_empty()
        || extension.len() > 16
        || !extension.iter().all(u8::is_ascii_alphanumeric)
    {
        return String::new();
    }
    format!(
        ".{}",
        String::from_utf8_lossy(extension).to_ascii_lowercase()
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn suffixes_keep_short_extensions() {
        assert_eq!(extension_suffix(b"dir/Cover.JPG"), ".jpg");
        assert_eq!(extension_suffix(b".nfo"), ".nfo");
        assert_eq!(extension_suffix(b"file_id.diz"), ".diz");
        assert_eq!(extension_suffix(b"noext"), "");
        assert_eq!(extension_suffix(b"a.b/c"), "");
        assert_eq!(extension_suffix(b"x.tar gz"), "");
    }
}
