//! Article downloads for additional processing, with a small per-release cache. Port of
//! `UsenetDownloadService`.
//!
//! Within a release scope a successful download is remembered (at most four entries and 16 MiB),
//! so the fallbacks that read an archive again don't fetch it again. Articles captured while
//! mapping PAR2 source files can be promoted into the cache for the archive or main-video reads
//! that follow.

use std::collections::{HashMap, VecDeque};
use std::sync::LazyLock;

use async_trait::async_trait;
use md5::{Digest as _, Md5};
use nntmux_core::pcre::Pcre;
use nntmux_nntp::{MessageReuse, NntpClient, NntpError};
use nntmux_yenc::YencPart;
use sha2::Sha256;

use super::planner::VIDEO_FILE;

const MAX_CACHE_ENTRIES: usize = 4;
const MAX_CACHE_ENTRY_BYTES: usize = 8_388_608;
const MAX_CACHE_BYTES: usize = 16_777_216;

static ARCHIVE_NAME: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/\.(?:rar|7z(?:\.\d+)?|zip|\d{3})$/i").expect("valid"));
static VIDEO_NAME: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(&format!("/{VIDEO_FILE}$/i")).expect("valid"));

/// Why a download was made. Preview sources count against the media byte budget.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum DownloadKind {
    Sample,
    MediaInfo,
    Audio,
    Jpg,
    Compressed,
    Par2Index,
    MediaSource,
}

impl DownloadKind {
    pub fn is_preview_source(self) -> bool {
        matches!(self, Self::Par2Index | Self::MediaSource | Self::Jpg)
    }
}

/// The traffic one release scope caused.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq, serde::Serialize)]
pub struct DownloadMetrics {
    pub logical_requests: i64,
    pub network_requests: i64,
    pub cache_hits: i64,
    pub bytes_downloaded: i64,
    pub bytes_reused: i64,
    pub media_bytes_downloaded: i64,
}

/// A download's outcome. A failure says whether the group was unavailable.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Download {
    pub data: Option<Vec<u8>>,
    pub group_unavailable: bool,
    pub error: Option<String>,
}

impl Download {
    pub fn success(&self) -> bool {
        self.data.is_some()
    }

    fn failed(error: impl Into<String>) -> Self {
        Self {
            data: None,
            group_unavailable: false,
            error: Some(error.into()),
        }
    }

    fn from_nntp(failure: &NntpError) -> Self {
        let message = failure.to_string();
        if is_group_unavailable(&message) {
            return Self {
                data: None,
                group_unavailable: true,
                error: Some(format!("Group unavailable: {message}")),
            };
        }
        Self::failed(message)
    }
}

/// One article with what its yEnc header says about its file.
#[derive(Clone, Debug, Default)]
pub struct ArticleDownload {
    pub download: Download,
    pub part: Option<YencPart>,
}

/// A bounded preview read. Every BODY byte counts, failed attempts included.
#[derive(Clone, Debug, Default)]
pub struct BoundedDownload {
    pub download: Download,
    pub bytes_downloaded: i64,
}

/// A file as the release's PAR2 index describes it.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Par2FileEntry {
    pub name: String,
    pub size: i64,
    /// MD5 of the file's first 16 KiB, lower-case hex.
    pub hash16k: String,
}

impl Par2FileEntry {
    /// Whether `data` is the start of this file.
    pub fn matches_start_of(&self, data: &[u8]) -> bool {
        let length = usize::try_from(self.size.clamp(0, 16_384)).unwrap_or(0);
        !self.hash16k.is_empty()
            && data.len() >= length
            && hex::encode(Md5::digest(&data[..length])) == self.hash16k
    }
}

#[derive(Clone)]
struct Article {
    data: Vec<u8>,
    part: YencPart,
}

struct Pending {
    key: String,
    article: Article,
}

/// What the media-source settings allow a mapped article to be kept as.
#[derive(Clone, Copy, Debug, Default)]
pub struct MappingPolicy {
    pub process_thumbnails: bool,
    pub main_video_fallback: bool,
    pub main_video_min_bytes: i64,
}

fn is_group_unavailable(message: &str) -> bool {
    let lower = message.to_ascii_lowercase();
    lower.contains("no such news group") || lower.contains("group not found")
}

/// Downloads for one worker. Not shared: each worker has its own connection and cache.
pub struct DownloadService {
    client: NntpClient,
    alternate: bool,
    policy: MappingPolicy,
    scope_active: bool,
    cache: HashMap<String, Download>,
    cache_order: VecDeque<String>,
    mapped_archives: Vec<(String, Article)>,
    mapped_videos: Vec<(String, Article)>,
    pending: Option<Pending>,
    cached_bytes: usize,
    metrics: DownloadMetrics,
}

/// Lets the NNTP client reuse mapped archive articles in place of network reads.
struct MappedReuse<'a> {
    articles: &'a [(String, Article)],
    group: &'a str,
    alternate: bool,
    reused: usize,
}

#[async_trait]
impl MessageReuse for MappedReuse<'_> {
    async fn lookup(&mut self, identifier: &str) -> Option<Vec<u8>> {
        let key = fingerprint(&[identifier.to_owned()], self.group, self.alternate);
        self.articles
            .iter()
            .find(|(cached, _)| *cached == key)
            .map(|(_, article)| article.data.clone())
    }

    async fn reused(&mut self, bytes: usize) {
        self.reused += bytes;
    }
}

/// PHP's `serialize` of a string.
fn serialized(value: &str) -> String {
    format!("s:{}:\"{value}\";", value.len())
}

/// The cache key of a request: PHP hashed `serialize()` of the IDs, the group and the alternate
/// setting, kept here so a key means the same request.
fn fingerprint(message_ids: &[String], group: &str, alternate: bool) -> String {
    let mut ids = format!("a:{}:{{", message_ids.len());
    for (index, id) in message_ids.iter().enumerate() {
        ids.push_str(&format!("i:{index};{}", serialized(id)));
    }
    ids.push('}');
    let text = format!(
        "a:3:{{{}{ids}{}{}{}b:{};}}",
        serialized("message_ids"),
        serialized("group"),
        serialized(group),
        serialized("alternate"),
        u8::from(alternate)
    );
    hex::encode(Sha256::digest(text.as_bytes()))
}

impl DownloadService {
    pub fn new(client: NntpClient, alternate: bool, policy: MappingPolicy) -> Self {
        Self {
            client,
            alternate,
            policy,
            scope_active: false,
            cache: HashMap::new(),
            cache_order: VecDeque::new(),
            mapped_archives: Vec::new(),
            mapped_videos: Vec::new(),
            pending: None,
            cached_bytes: 0,
            metrics: DownloadMetrics::default(),
        }
    }

    pub fn client(&mut self) -> &mut NntpClient {
        &mut self.client
    }

    pub fn begin_release_scope(&mut self) {
        self.clear_scope();
        self.scope_active = true;
    }

    pub fn finish_release_scope(&mut self) -> DownloadMetrics {
        let metrics = self.metrics;
        self.clear_scope();
        metrics
    }

    fn clear_scope(&mut self) {
        self.scope_active = false;
        self.cache.clear();
        self.cache_order.clear();
        self.mapped_archives.clear();
        self.mapped_videos.clear();
        self.pending = None;
        self.cached_bytes = 0;
        self.metrics = DownloadMetrics::default();
    }

    fn entry_count(&self) -> usize {
        self.cache.len()
            + self.mapped_archives.len()
            + self.mapped_videos.len()
            + usize::from(self.pending.is_some())
    }

    fn key(&self, message_ids: &[String], group: &str) -> String {
        fingerprint(message_ids, group, self.alternate)
    }

    fn cached(&mut self, key: &str) -> Option<Download> {
        let cached = self.cache.get(key)?.clone();
        self.metrics.cache_hits += 1;
        self.metrics.bytes_reused += cached.data.as_ref().map_or(0, |data| data.len() as i64);
        Some(cached)
    }

    /// Downloads and joins articles for one step. `cache` false keeps the result out of the
    /// release cache.
    pub async fn download(
        &mut self,
        kind: DownloadKind,
        message_ids: &[String],
        group: &str,
        cache: bool,
    ) -> Download {
        let key = self.key(message_ids, group);
        if self.scope_active {
            self.metrics.logical_requests += 1;
            if let Some(cached) = self.cached(&key) {
                return cached;
            }
        }
        if message_ids.is_empty() {
            return Download::failed("No message IDs provided");
        }
        let wanted = message_ids.iter().any(|id| !id.is_empty());
        let mut reused = 0usize;
        let fetched = if self.scope_active
            && kind == DownloadKind::Compressed
            && !self.mapped_archives.is_empty()
        {
            let network_required = wanted
                && message_ids.iter().any(|id| {
                    let single = fingerprint(std::slice::from_ref(id), group, self.alternate);
                    !self
                        .mapped_archives
                        .iter()
                        .any(|(cached, _)| *cached == single)
                });
            let mut reuse = MappedReuse {
                articles: &self.mapped_archives,
                group,
                alternate: self.alternate,
                reused: 0,
            };
            let fetched = self
                .client
                .get_messages_by_message_id(message_ids, self.alternate, Some(&mut reuse))
                .await;
            reused = reuse.reused;
            if network_required {
                self.metrics.network_requests += 1;
            }
            if reused > 0 {
                self.metrics.cache_hits += 1;
                self.metrics.bytes_reused += reused as i64;
            }
            fetched
        } else {
            if self.scope_active && wanted {
                self.metrics.network_requests += 1;
            }
            self.client
                .get_messages_by_message_id(message_ids, self.alternate, None)
                .await
        };
        let result = match fetched {
            Ok(data) if !data.is_empty() => Download {
                data: Some(data),
                ..Download::default()
            },
            Ok(_) => Download::failed("Download failed"),
            Err(failure) => Download::from_nntp(&failure),
        };
        if self.scope_active
            && let Some(data) = &result.data
        {
            let size = data.len();
            self.metrics.bytes_downloaded += size as i64 - reused as i64;
            if kind.is_preview_source() {
                self.metrics.media_bytes_downloaded += size as i64;
            }
            if cache {
                self.remember(key, &result, size);
            }
        }
        result
    }

    /// Reads a complete preview file within a hard BODY-byte allowance. Partial files never count
    /// as success, and bounded reads bypass the cache.
    pub async fn download_bounded(
        &mut self,
        kind: DownloadKind,
        message_ids: &[String],
        maximum_bytes: i64,
    ) -> BoundedDownload {
        let mut result = BoundedDownload::default();
        if maximum_bytes <= 0 || message_ids.is_empty() {
            result.download = Download::failed("No preview download allowance or message IDs.");
            return result;
        }
        if self.scope_active {
            self.metrics.logical_requests += 1;
        }
        let mut data = Vec::new();
        for id in message_ids {
            let remaining = maximum_bytes - result.bytes_downloaded;
            if remaining <= 0 {
                result.download = Download::failed("Preview download byte budget exhausted.");
                return result;
            }
            if self.scope_active {
                self.metrics.network_requests += 1;
            }
            let article = self
                .client
                .get_bounded_article_by_message_id(id, remaining, self.alternate)
                .await;
            result.bytes_downloaded += article.bytes_downloaded;
            if self.scope_active {
                self.metrics.bytes_downloaded += article.bytes_downloaded;
                if kind.is_preview_source() {
                    self.metrics.media_bytes_downloaded += article.bytes_downloaded;
                }
            }
            let Some(article_data) = article.data else {
                let unavailable = article.error.as_deref().is_some_and(is_group_unavailable);
                result.download = Download {
                    data: None,
                    group_unavailable: unavailable,
                    error: article.error,
                };
                return result;
            };
            data.extend_from_slice(&article_data);
        }
        if !data.is_empty() {
            result.download.data = Some(data);
        }
        result
    }

    /// One article with its yEnc header. A cached copy is reused, but the article is never added
    /// to the release cache, which keeps room for the archives the fallbacks read again.
    pub async fn download_article(
        &mut self,
        kind: DownloadKind,
        message_id: &str,
        group: &str,
    ) -> ArticleDownload {
        self.article(kind, message_id, group, false).await
    }

    async fn article(
        &mut self,
        kind: DownloadKind,
        message_id: &str,
        group: &str,
        capturing: bool,
    ) -> ArticleDownload {
        if message_id.is_empty() {
            return ArticleDownload {
                download: Download::failed("No message IDs provided"),
                part: None,
            };
        }
        let key = self.key(&[message_id.to_owned()], group);
        if self.scope_active {
            self.metrics.logical_requests += 1;
            if let Some(cached) = self.cached(&key) {
                return ArticleDownload {
                    download: cached,
                    part: None,
                };
            }
            self.metrics.network_requests += 1;
        }
        let mut reused = 0usize;
        let fetched = if self.scope_active && kind == DownloadKind::MediaSource && !capturing {
            match self.mapped_videos.iter().find(|(cached, _)| *cached == key) {
                Some((_, article)) => {
                    reused = article.data.len();
                    self.metrics.network_requests -= 1;
                    self.metrics.cache_hits += 1;
                    self.metrics.bytes_reused += reused as i64;
                    Ok((article.data.clone(), Some(article.part.clone()), false))
                }
                None => self.fetch_article(message_id).await,
            }
        } else {
            self.fetch_article(message_id).await
        };
        let (data, part, validated) = match fetched {
            Ok(found) if !found.0.is_empty() => found,
            Ok(_) => {
                return ArticleDownload {
                    download: Download::failed("Download failed"),
                    part: None,
                };
            }
            Err(failure) => {
                return ArticleDownload {
                    download: Download::from_nntp(&failure),
                    part: None,
                };
            }
        };
        if capturing
            && self.scope_active
            && validated
            && let Some(part) = &part
        {
            let bytes = data.len();
            if self.pending.is_none()
                && bytes > 0
                && bytes <= MAX_CACHE_ENTRY_BYTES
                && self.make_room_for_mapping(bytes)
            {
                self.pending = Some(Pending {
                    key: key.clone(),
                    article: Article {
                        data: data.clone(),
                        part: part.clone(),
                    },
                });
                self.cached_bytes += bytes;
            }
        }
        if self.scope_active {
            let size = data.len() as i64 - reused as i64;
            self.metrics.bytes_downloaded += size;
            if kind.is_preview_source() {
                self.metrics.media_bytes_downloaded += size;
            }
        }
        ArticleDownload {
            download: Download {
                data: Some(data),
                ..Download::default()
            },
            part,
        }
    }

    async fn fetch_article(
        &mut self,
        message_id: &str,
    ) -> Result<(Vec<u8>, Option<YencPart>, bool), NntpError> {
        let (article, validated) = self
            .client
            .get_article_by_message_id(message_id, self.alternate)
            .await?;
        Ok((article.data, article.part, validated))
    }

    /// Captures a fresh, integrity-checked primary article that PAR2 source mapping may keep.
    pub async fn download_mapping_article(
        &mut self,
        message_id: &str,
        group: &str,
    ) -> ArticleDownload {
        self.discard_pending_mapping();
        self.article(DownloadKind::MediaSource, message_id, group, true)
            .await
    }

    /// Keeps the captured article when it starts an archive the PAR2 index named.
    pub fn retain_mapped_archive(&mut self, file: &Par2FileEntry) {
        self.promote(file, false);
    }

    /// Keeps the captured article when the resolver matched it to its real PAR2 file.
    pub fn retain_mapped_source(&mut self, file: &Par2FileEntry) {
        self.promote(file, true);
    }

    fn promote(&mut self, file: &Par2FileEntry, allow_video: bool) {
        let pending = self.pending.take();
        if let Some(pending) = &pending {
            self.cached_bytes -= pending.article.data.len();
        }
        let Some(Pending { key, article }) = pending.filter(|_| self.scope_active) else {
            return;
        };
        let name_matches =
            article.part.name == file.name.as_bytes() && article.part.size == file.size;
        if !file.matches_start_of(&article.data) && !name_matches {
            return;
        }
        let archive = ARCHIVE_NAME.is_match(file.name.as_bytes());
        let video = allow_video
            && !archive
            && self.policy.process_thumbnails
            && self.policy.main_video_fallback
            && file.size >= self.policy.main_video_min_bytes
            && !file.name.to_ascii_lowercase().contains("sample")
            && VIDEO_NAME.is_match(file.name.as_bytes())
            && article.part.size == file.size
            && article.part.offset() == 0
            && article.part.part.is_none_or(|part| part == 1);
        let size = article.data.len();
        let known = self
            .mapped_archives
            .iter()
            .any(|(cached, _)| *cached == key)
            || self.mapped_videos.iter().any(|(cached, _)| *cached == key);
        if (!archive && !video)
            || known
            || self.entry_count() >= MAX_CACHE_ENTRIES
            || self.cached_bytes + size > MAX_CACHE_BYTES
        {
            return;
        }
        if archive {
            self.mapped_archives.push((key, article));
        } else {
            self.mapped_videos.push((key, article));
        }
        self.cached_bytes += size;
    }

    /// Mapping captures may replace videos, never archives or ordinary downloads.
    fn make_room_for_mapping(&mut self, bytes: usize) -> bool {
        while !self.mapped_videos.is_empty()
            && (self.entry_count() >= MAX_CACHE_ENTRIES
                || self.cached_bytes + bytes > MAX_CACHE_BYTES)
        {
            self.evict_video();
        }
        self.entry_count() < MAX_CACHE_ENTRIES && self.cached_bytes + bytes <= MAX_CACHE_BYTES
    }

    fn evict_video(&mut self) {
        let (_, article) = self.mapped_videos.remove(0);
        self.cached_bytes -= article.data.len();
    }

    /// Drops an unmatched capture at the end of a mapping attempt.
    pub fn discard_pending_mapping(&mut self) {
        if let Some(pending) = self.pending.take() {
            self.cached_bytes -= pending.article.data.len();
        }
    }

    fn remember(&mut self, key: String, result: &Download, size: usize) {
        if size > MAX_CACHE_ENTRY_BYTES || self.cache.len() >= MAX_CACHE_ENTRIES {
            return;
        }
        while (self.pending.is_some()
            || !self.mapped_videos.is_empty()
            || !self.mapped_archives.is_empty())
            && (self.entry_count() >= MAX_CACHE_ENTRIES
                || self.cached_bytes + size > MAX_CACHE_BYTES)
        {
            if self.pending.is_some() {
                self.discard_pending_mapping();
            } else if !self.mapped_videos.is_empty() {
                self.evict_video();
            } else {
                let (_, article) = self.mapped_archives.remove(0);
                self.cached_bytes -= article.data.len();
            }
        }
        if self.cached_bytes + size > MAX_CACHE_BYTES {
            return;
        }
        if !self.cache.contains_key(&key) {
            self.cache_order.push_back(key.clone());
        }
        self.cache.insert(key, result.clone());
        self.cached_bytes += size;
    }
}

/// `meetsMinimumSize`: more than `minimum` bytes.
pub fn meets_minimum_size(data: &[u8], minimum: usize) -> bool {
    data.len() > minimum
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn fingerprints_hash_php_serialize() {
        // php -r 'echo hash("sha256", serialize(["message_ids" => ["a@b"], "group" => "g", "alternate" => false]));'
        assert_eq!(
            fingerprint(&["a@b".to_owned()], "g", false),
            hex::encode(Sha256::digest(
                br#"a:3:{s:11:"message_ids";a:1:{i:0;s:3:"a@b";}s:5:"group";s:1:"g";s:9:"alternate";b:0;}"#
            ))
        );
    }

    #[test]
    fn a_par2_entry_matches_the_start_of_its_file() {
        let data = vec![7u8; 20_000];
        let entry = Par2FileEntry {
            name: "a.mkv".to_owned(),
            size: 20_000,
            hash16k: hex::encode(Md5::digest(&data[..16_384])),
        };
        assert!(entry.matches_start_of(&data));
        assert!(!entry.matches_start_of(&data[..100]));
        let unhashed = Par2FileEntry {
            hash16k: String::new(),
            ..entry
        };
        assert!(!unhashed.matches_start_of(&data));
    }

    #[test]
    fn group_errors_are_recognized() {
        assert!(is_group_unavailable("411 No Such News Group"));
        assert!(is_group_unavailable("group not found"));
        assert!(!is_group_unavailable("430 No such article"));
    }
}
