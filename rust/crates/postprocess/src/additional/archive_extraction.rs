//! `ArchiveExtractionService`: lists the archive in a release's downloaded bytes, spots plain
//! video and passwords, and copies chosen files out. 7z archives are listed only; nothing extracts
//! them.
//!
//! Everything here blocks (the archive readers and the external clients do), so async callers run
//! it on a blocking thread.
//!
//! PHP ran the external extractors under `timeout --signal=KILL` when a timeout binary was set, and
//! under Laravel's 1800 second process timeout either way. This kills the client itself at the
//! same limit, so no timeout binary is needed, and a client killed at the limit counts as one that
//! failed, as with `timeout`, rather than as an error.

use std::collections::{HashMap, HashSet};
use std::ffi::OsStr;
use std::fs;
use std::io::{Read, Seek, SeekFrom, Write};
use std::os::unix::ffi::OsStrExt;
use std::os::unix::fs::PermissionsExt;
use std::path::{Path, PathBuf};
use std::process::{Child, Command, ExitStatus, Stdio};
use std::sync::{Arc, mpsc};
use std::thread;
use std::time::{Duration, Instant};

use anyhow::Result;

use super::seven_zip_listing::Parser;
use crate::archive_info::szip::MARKER_SIGNATURE;
use crate::archive_info::{ArchiveInfo, FileRecord, Kind, ListEntry, MAIN_SOURCE, Summary};
use crate::config::Config;

/// Largest 7z volume the start header may describe before it is treated as corrupt.
pub const SEVENZIP_MAX_ARCHIVE_BYTES: u64 = 1 << 40;
/// Stop reading a `7z l` listing after this many bytes of output.
pub const SEVENZIP_LISTING_MAX_BYTES: usize = 4 << 20;
/// Or after this many file entries.
pub const SEVENZIP_LISTING_MAX_ENTRIES: usize = 1000;
/// How long the 7-Zip client gets to list an archive.
const SEVENZIP_LISTING_TIMEOUT: Duration = Duration::from_secs(60);
/// Laravel's process timeout around every extractor.
const COMMAND_TIMEOUT_CAP: Duration = Duration::from_secs(1800);

/// The `ReleaseProcessingContext` fields archive inspection sets.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ArchiveFlags {
    pub compressed_files_checked: u32,
    /// The listing failed in a way another segment may fix.
    pub archive_retryable: bool,
    /// A 7z whose compressed header can't be decoded here.
    pub archive_listing_undecodable: bool,
    /// The 7-Zip listing stopped early or left records out.
    pub archive_listing_incomplete: bool,
}

/// A plain media container found where an archive was expected.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum VideoKind {
    Avi,
    Mkv,
    Mpg,
    Ts,
    Mp4,
    Wmv,
    Flv,
    Ogv,
}

impl VideoKind {
    pub fn extension(self) -> &'static str {
        match self {
            Self::Avi => "avi",
            Self::Mkv => "mkv",
            Self::Mpg => "mpg",
            Self::Ts => "ts",
            Self::Mp4 => "mp4",
            Self::Wmv => "wmv",
            Self::Flv => "flv",
            Self::Ogv => "ogv",
        }
    }
}

/// The marker echoed for each archive type: `r`, `z` or `7`.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Marker {
    Rar,
    Zip,
    SevenZip,
}

impl Marker {
    pub fn as_char(self) -> char {
        match self {
            Self::Rar => 'r',
            Self::Zip => 'z',
            Self::SevenZip => '7',
        }
    }
}

/// A successful listing.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Listing {
    pub files: Vec<ListEntry>,
    pub marker: Option<Marker>,
    /// `dataSummary['main_type']`.
    pub kind: Kind,
    /// The rest of `dataSummary`; empty for a listing from the 7-Zip client.
    pub summary: Summary,
    /// Only the names are known: nothing was extracted.
    pub listing_only: bool,
    /// For a RAR, whether the listing reached the end block of the last volume.
    pub rar_listing_complete: Option<bool>,
}

/// What one look at compressed data found.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Inspection {
    /// Plain media, not an archive. The caller already holds its bytes.
    StandaloneVideo(VideoKind),
    /// Nothing listable. `undecodable` is a 7z whose header needs an LZMA decoder.
    Failed {
        undecodable: bool,
        listing_only: bool,
    },
    /// `PASSWD_RAR`.
    Passworded {
        listing_only: bool,
    },
    Listed(Listing),
}

impl Inspection {
    /// `ReleaseBrowseService::PASSWD_RAR` for a password, else `PASSWD_NONE`.
    pub fn password_status(&self) -> i8 {
        i8::from(matches!(self, Self::Passworded { .. }))
    }
}

/// The configured clients.
#[derive(Clone, Debug, Default)]
pub struct Extractor {
    pub unrar: Option<PathBuf>,
    pub unzip: Option<PathBuf>,
    pub seven_zip: Option<PathBuf>,
    /// Read stored files with the archive readers instead of running unrar or unzip on the
    /// whole archive.
    pub extract_using_rar_info: bool,
    pub command_timeout: Duration,
}

impl Extractor {
    pub fn from_config(config: &Config) -> Self {
        let seconds = config.settings.timeout_seconds;
        let command_timeout = match u64::try_from(seconds) {
            Ok(seconds) if seconds > 0 && config.timeout_path.is_some() => {
                Duration::from_secs(seconds).min(COMMAND_TIMEOUT_CAP)
            }
            _ => COMMAND_TIMEOUT_CAP,
        };
        let path = |value: &Option<String>| {
            value
                .as_deref()
                .filter(|value| !value.is_empty())
                .map(PathBuf::from)
        };
        Self {
            unrar: path(&config.unrar_path),
            unzip: path(&config.unzip_path),
            seven_zip: path(&config.seven_zip_path),
            extract_using_rar_info: config.settings.extract_using_rar_info,
            command_timeout,
        }
    }

    /// `processCompressedData`: inspects the data and marks a failure as an undecodable 7z or
    /// else retryable. Plain video counts as a failure there too, as it did in PHP.
    pub fn process_compressed_data(
        &self,
        data: &Arc<Vec<u8>>,
        flags: &mut ArchiveFlags,
        tmp: &Path,
    ) -> Result<Inspection> {
        let inspection = self.inspect(data, flags, tmp)?;
        match inspection {
            Inspection::Failed {
                undecodable: true, ..
            } => flags.archive_listing_undecodable = true,
            Inspection::Failed { .. } | Inspection::StandaloneVideo(_) => {
                flags.archive_retryable = true;
            }
            Inspection::Passworded { .. } | Inspection::Listed(_) => {}
        }
        Ok(inspection)
    }

    fn inspect(
        &self,
        data: &Arc<Vec<u8>>,
        flags: &mut ArchiveFlags,
        tmp: &Path,
    ) -> Result<Inspection> {
        let failed = |listing_only| Inspection::Failed {
            undecodable: false,
            listing_only,
        };
        flags.compressed_files_checked += 1;
        // Plain media is not an archive, even if a reader finds a marker inside it.
        if !has_archive_signature(data)
            && let Some(kind) = detect_standalone_video(data)
        {
            return Ok(Inspection::StandaloneVideo(kind));
        }
        let Ok(mut info) = ArchiveInfo::from_data(data.clone(), true, self.unrar.as_deref()) else {
            return Ok(failed(false));
        };
        if !info.error().is_empty() {
            return Ok(failed(false));
        }
        let listing_only = info.kind == Kind::Szip;
        let Ok(summary) = info.summary(!listing_only) else {
            return Ok(failed(listing_only));
        };
        if info.is_encrypted() || summary.is_encrypted {
            return Ok(Inspection::Passworded { listing_only });
        }
        if !listing_only {
            self.prepare_extraction_directories(tmp);
        }
        let marker = self.extract_archive(data, info.kind, tmp)?;
        let files = info.archive_file_list(!listing_only)?.unwrap_or_default();
        if files.is_empty() {
            // A compressed 7z header needs an LZMA decoder, which isn't available.
            return Ok(Inspection::Failed {
                undecodable: listing_only && summary.enc_header,
                listing_only,
            });
        }
        Ok(Inspection::Listed(Listing {
            files,
            marker,
            kind: info.kind,
            rar_listing_complete: (info.kind == Kind::Rar)
                .then(|| info.has_terminal_rar_block(data.len())),
            summary,
            listing_only,
        }))
    }

    fn prepare_extraction_directories(&self, tmp: &Path) {
        if self.extract_using_rar_info {
            return;
        }
        // Failures only matter to the extractor, which then finds nothing.
        if self.unrar.is_some() {
            let _ = fs::create_dir_all(tmp.join("unrar"));
        }
        let _ = fs::create_dir_all(tmp.join("unzip"));
    }

    /// `extractArchive`: unpacks a whole RAR or ZIP into `unrar/` or `unzip/` under `tmp`, unless
    /// the readers do the extracting.
    fn extract_archive(&self, data: &[u8], kind: Kind, tmp: &Path) -> Result<Option<Marker>> {
        let (marker, client, extension) = match kind {
            Kind::Rar => (Marker::Rar, &self.unrar, ".rar"),
            Kind::Zip => (Marker::Zip, &self.unzip, ".zip"),
            Kind::Szip => return Ok(Some(Marker::SevenZip)),
            _ => return Ok(None),
        };
        let Some(client) = client.as_deref().filter(|_| !self.extract_using_rar_info) else {
            return Ok(Some(marker));
        };
        let mut archive = tempfile::Builder::new()
            .suffix(extension)
            .tempfile_in(tmp)?;
        archive.write_all(data)?;
        archive.flush()?;
        let path = archive.path().as_os_str().to_owned();
        let mut command = Command::new(client);
        if kind == Kind::Rar {
            command
                .args([
                    "e", "-ai", "-ep", "-c-", "-id", "-inul", "-kb", "-or", "-p-", "-r", "-y",
                    "-@", "--",
                ])
                .arg(&path)
                .arg(directory_argument(&tmp.join("unrar")));
        } else {
            command
                .args(["-o", "-d"])
                .arg(directory_argument(&tmp.join("unzip")))
                .arg("--")
                .arg(&path);
        }
        run(command, self.command_timeout);
        Ok(Some(marker))
    }

    /// `extractSpecificFiles`: copies stored files straight out of the data where they lie whole
    /// inside it, and tries unrar, then unzip, for the rest. Keyed by name; a name that can't be
    /// extracted is missing.
    pub fn extract_specific_files(
        &self,
        data: &Arc<Vec<u8>>,
        filenames: &[&[u8]],
        tmp: &Path,
    ) -> Result<HashMap<Vec<u8>, Vec<u8>>> {
        let mut extracted = HashMap::new();
        if data.starts_with(MARKER_SIGNATURE) {
            return Ok(extracted);
        }
        let mut seen = HashSet::new();
        let filenames: Vec<&[u8]> = filenames
            .iter()
            .copied()
            .filter(|name| !name.is_empty() && seen.insert(*name))
            .collect();
        if let Ok(mut info) = ArchiveInfo::from_data(data.clone(), true, self.unrar.as_deref()) {
            if info.kind == Kind::Szip {
                return Ok(extracted);
            }
            let entries =
                complete_stored_entries(&info.archive_file_list(true)?.unwrap_or_default());
            for name in &filenames {
                let Some(size) = entries.get(*name) else {
                    continue;
                };
                if let Ok(Some(bytes)) = info.file_data(name)
                    && usize::try_from(*size) == Ok(bytes.len())
                {
                    extracted.insert(name.to_vec(), bytes);
                }
            }
        }
        for name in filenames {
            if extracted.contains_key(name) {
                continue;
            }
            if let Some(bytes) = self.extract_with_external_tools(data, name, tmp) {
                extracted.insert(name.to_vec(), bytes);
            }
        }
        Ok(extracted)
    }

    /// `extractSpecificFile`.
    pub fn extract_specific_file(
        &self,
        data: &Arc<Vec<u8>>,
        filename: &[u8],
        tmp: &Path,
    ) -> Result<Option<Vec<u8>>> {
        Ok(self
            .extract_specific_files(data, &[filename], tmp)?
            .remove(filename))
    }

    fn extract_with_external_tools(&self, data: &[u8], name: &[u8], tmp: &Path) -> Option<Vec<u8>> {
        let name_argument = OsStr::from_bytes(name);
        if let Some(unrar) = &self.unrar {
            let found = self.extract_with(data, name, tmp, ".rar", |archive, directory| {
                let mut command = Command::new(unrar);
                command
                    .args(["e", "-y", "-c-", "-inul", "-p-", "-@", "--"])
                    .arg(archive)
                    .arg(name_argument)
                    .arg(directory_argument(directory));
                command
            });
            if found.is_some() {
                return found;
            }
        }
        let unzip = self.unzip.as_ref()?;
        self.extract_with(data, name, tmp, ".zip", |archive, directory| {
            let mut command = Command::new(unzip);
            command
                .args(["-j", "-d"])
                .arg(directory_argument(directory))
                .arg("--")
                .arg(archive)
                .arg(name_argument);
            command
        })
    }

    /// `extractFileViaExternalTool`: the file under its base name in a fresh directory, or failing
    /// that the first file found there with that name in any case.
    fn extract_with(
        &self,
        data: &[u8],
        name: &[u8],
        tmp: &Path,
        extension: &str,
        build: impl FnOnce(&Path, &Path) -> Command,
    ) -> Option<Vec<u8>> {
        let directory = tempfile::Builder::new()
            .prefix("extract_")
            .tempdir_in(tmp)
            .ok()?;
        let mut archive = tempfile::Builder::new()
            .prefix("archive_")
            .suffix(extension)
            .tempfile_in(tmp)
            .ok()?;
        archive.write_all(data).ok()?;
        archive.flush().ok()?;
        run(
            build(archive.path(), directory.path()),
            self.command_timeout,
        );
        let base = php_basename(name);
        let direct = directory.path().join(OsStr::from_bytes(base));
        if direct.is_file() {
            return fs::read(direct).ok();
        }
        let wanted = base.to_ascii_lowercase();
        let mut files = Vec::new();
        visible_files(directory.path(), &mut files);
        files.sort();
        files
            .into_iter()
            .find(|path| {
                path.file_name()
                    .is_some_and(|file| file.as_bytes().to_ascii_lowercase() == wanted)
            })
            .and_then(|path| fs::read(path).ok())
    }

    /// `listEncodedSevenZip`: lists a 7z whose end header is compressed, which the readers can't
    /// decode, with the 7-Zip client. `None` means fall back to the readers.
    ///
    /// The client gets a sparse copy of the archive holding only the downloaded first and last
    /// bytes at their real offsets; that is enough when the packed header stream lies inside them,
    /// as 7-Zip writes it just before the end header. Without a tail the whole archive must be in
    /// the head. A listing cut at the caps keeps the entries read until then and marks the flags
    /// incomplete, as does a record that can't be read unambiguously.
    pub fn list_encoded_seven_zip(
        &self,
        head: &[u8],
        tail: Option<&[u8]>,
        release_size: i64,
        flags: &mut ArchiveFlags,
        tmp: &Path,
    ) -> Option<Inspection> {
        let client = self
            .seven_zip
            .as_deref()
            .filter(|path| is_executable(path))?;
        let start = seven_zip_start_header(head)?;
        let length = seven_zip_archive_length(&start, release_size)?;
        let tail = match tail {
            Some(tail) => tail,
            None if length > head.len() as u64 => return None,
            None => &head[..usize::try_from(length).ok()?],
        };
        let joined = with_seven_zip_end_header(head, tail)?;
        let info = ArchiveInfo::from_data(Arc::new(joined), false, None).ok()?;
        if info.is_encrypted() {
            return None;
        }
        let (pack_offset, pack_sizes) = info.seven_zip_encoded_header()?;
        let length = i64::try_from(length).ok()?;
        let head_length = i64::try_from(head.len()).ok()?;
        let tail_start = length - i64::try_from(tail.len()).ok()?;
        let pack_start = pack_offset.checked_add(32)?;
        let pack_end = pack_sizes
            .iter()
            .try_fold(pack_start, |sum, size| sum.checked_add(*size))?;
        let next_size = i64::try_from(start.next_size).ok()?;
        if tail_start < 0
            || pack_start < 32
            || pack_end > length - next_size
            || (pack_start < tail_start && pack_end > head_length)
        {
            return None;
        }

        let mut file = tempfile::Builder::new()
            .prefix("7z_")
            .suffix(".7z")
            .tempfile_in(tmp)
            .ok()?;
        file.write_all(head).ok()?;
        file.seek(SeekFrom::Start(u64::try_from(tail_start).ok()?))
            .ok()?;
        file.write_all(tail).ok()?;
        file.flush().ok()?;
        // A dummy password makes an encrypted archive fail instead of prompting.
        let mut command = Command::new(client);
        command
            .args(["l", "-slt", "-ba", "-sccUTF-8", "-pnntmux", "-t7z", "--"])
            .arg(file.path());
        let (files, complete) = read_seven_zip_listing(command)?;
        drop(file);

        // Marked even when no record survived, as a rejected record may be the encrypted one.
        if !complete {
            flags.archive_listing_incomplete = true;
        }
        if files.is_empty() {
            return None;
        }
        flags.compressed_files_checked += 1;
        Some(Inspection::Listed(Listing {
            files: files.into_iter().map(ListEntry::File).collect(),
            marker: Some(Marker::SevenZip),
            kind: Kind::Szip,
            summary: Summary::default(),
            listing_only: true,
            rar_listing_complete: None,
        }))
    }
}

/// `readSevenZipListing`: parses the client's output as it streams, stopping it at the caps.
/// `None` when it can't be started or runs out of time; otherwise the files and whether the
/// listing was read to the end with every record understood.
fn read_seven_zip_listing(mut command: Command) -> Option<(Vec<FileRecord>, bool)> {
    let deadline = Instant::now() + SEVENZIP_LISTING_TIMEOUT;
    let mut child = command
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .spawn()
        .ok()?;
    let mut stdout = child.stdout.take()?;
    let (sender, chunks) = mpsc::channel();
    thread::spawn(move || {
        let mut buffer = vec![0; 64 * 1024];
        loop {
            match stdout.read(&mut buffer) {
                Ok(0) | Err(_) => break,
                Ok(read) => {
                    if sender.send(buffer[..read].to_vec()).is_err() {
                        break;
                    }
                }
            }
        }
    });
    let mut parser = Parser::default();
    let mut bytes = 0;
    loop {
        let remaining = deadline.saturating_duration_since(Instant::now());
        let mut chunk = match chunks.recv_timeout(remaining) {
            Ok(chunk) => chunk,
            Err(mpsc::RecvTimeoutError::Disconnected) => break,
            Err(mpsc::RecvTimeoutError::Timeout) => {
                stop(&mut child);
                return None;
            }
        };
        // Parse no more than the cap, however much output a single read returned.
        let cut = bytes + chunk.len() > SEVENZIP_LISTING_MAX_BYTES;
        if cut {
            chunk.truncate(SEVENZIP_LISTING_MAX_BYTES - bytes);
        }
        bytes += chunk.len();
        parser.feed(&chunk);
        if parser.files.len() >= SEVENZIP_LISTING_MAX_ENTRIES {
            stop(&mut child);
            parser.files.truncate(SEVENZIP_LISTING_MAX_ENTRIES);
            return Some((parser.files, false));
        }
        if cut {
            // The record being read when the output was cut is dropped, never half-parsed.
            stop(&mut child);
            return Some((parser.files, false));
        }
    }
    let status = wait_until(&mut child, deadline)?;
    if !status.success() {
        return Some((Vec::new(), true));
    }
    parser.finish();
    Some((parser.files, parser.reliable))
}

/// Runs an extractor to completion or its time limit, without a shell. What it did is judged by
/// the files it left.
fn run(mut command: Command, limit: Duration) {
    let child = command
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn();
    if let Ok(mut child) = child {
        let _ = wait_until(&mut child, Instant::now() + limit);
    }
}

/// The exit status, or `None` once the deadline passes and the child is killed.
fn wait_until(child: &mut Child, deadline: Instant) -> Option<ExitStatus> {
    let mut pause = Duration::from_millis(1);
    loop {
        match child.try_wait() {
            Ok(Some(status)) => return Some(status),
            Ok(None) if Instant::now() < deadline => {
                thread::sleep(pause.min(deadline.saturating_duration_since(Instant::now())));
                pause = (pause * 2).min(Duration::from_millis(50));
            }
            _ => {
                stop(child);
                return None;
            }
        }
    }
}

fn stop(child: &mut Child) {
    let _ = child.kill();
    let _ = child.wait();
}

/// unrar reads a destination as a directory only with its trailing slash.
fn directory_argument(directory: &Path) -> PathBuf {
    let mut argument = directory.as_os_str().to_owned();
    argument.push("/");
    PathBuf::from(argument)
}

/// Laravel's `File::allFiles` without hidden files: dot files and dot directories are skipped.
fn visible_files(directory: &Path, files: &mut Vec<PathBuf>) {
    let Ok(entries) = fs::read_dir(directory) else {
        return;
    };
    for entry in entries.flatten() {
        if entry.file_name().as_bytes().starts_with(b".") {
            continue;
        }
        let path = entry.path();
        match entry.file_type() {
            Ok(kind) if kind.is_dir() => visible_files(&path, files),
            Ok(kind) if kind.is_file() => files.push(path),
            _ => {}
        }
    }
}

fn is_executable(path: &Path) -> bool {
    fs::metadata(path).is_ok_and(|meta| meta.is_file() && meta.permissions().mode() & 0o111 != 0)
}

/// `completeStoredEntries`: entries whose bytes can be copied straight out of the downloaded
/// data, because they are stored, not encrypted, and wholly inside it. Name to size.
pub fn complete_stored_entries(files: &[ListEntry]) -> HashMap<Vec<u8>, i64> {
    let mut entries = HashMap::new();
    for entry in files {
        let ListEntry::File(file) = entry else {
            continue;
        };
        let Some((start, end)) = file.range else {
            continue;
        };
        let main = file.source.is_empty() || file.source == MAIN_SOURCE;
        if !main || file.pass != 0 || file.is_dir || file.compressed != Some(0) {
            continue;
        }
        if file.size > 0
            && end.checked_sub(start).and_then(|span| span.checked_add(1)) == Some(file.size)
        {
            entries.insert(file.name.clone(), file.size);
        }
    }
    entries
}

/// True when the data starts with a RAR, ZIP or 7z signature.
pub fn has_archive_signature(data: &[u8]) -> bool {
    [
        &b"Rar!\x1a\x07\x00"[..],
        b"Rar!\x1a\x07\x01\x00",
        b"PK\x03\x04",
        MARKER_SIGNATURE,
    ]
    .iter()
    .any(|signature| data.starts_with(signature))
}

/// `detectStandaloneVideo`: a plain media container, from its leading bytes.
pub fn detect_standalone_video(data: &[u8]) -> Option<VideoKind> {
    if data.len() < 16 {
        return None;
    }
    let sig4 = &data[..4];
    let at = |index: usize| data.get(index).copied();
    Some(if sig4 == b"RIFF" && &data[8..12] == b"AVI " {
        VideoKind::Avi
    } else if sig4 == b"\x1a\x45\xdf\xa3" {
        VideoKind::Mkv
    } else if sig4 == b"\x00\x00\x01\xba" || sig4 == b"\x00\x00\x01\xb3" {
        VideoKind::Mpg
    } else if data[0] == 0x47 && at(188) == Some(0x47) && at(376) == Some(0x47) {
        VideoKind::Ts
    } else if &data[4..8] == b"ftyp" && data[8..12].iter().all(|byte| (0x20..=0x7e).contains(byte))
    {
        VideoKind::Mp4
    } else if data.starts_with(b"\x30\x26\xb2\x75\x8e\x66\xcf\x11") {
        VideoKind::Wmv
    } else if data.starts_with(b"FLV\x01") {
        VideoKind::Flv
    } else if sig4 == b"OggS" {
        VideoKind::Ogv
    } else {
        return None;
    })
}

/// The fields of a 7z start header.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct StartHeader {
    pub next_offset: u64,
    pub next_size: u64,
    pub next_crc: u32,
}

/// `sevenZipStartHeader`: `None` without a 7z signature or past the size cap, which only
/// corruption reaches.
pub fn seven_zip_start_header(data: &[u8]) -> Option<StartHeader> {
    if data.len() < 32 || !data.starts_with(MARKER_SIGNATURE) {
        return None;
    }
    let u64_at = |at: usize| u64::from_le_bytes(data[at..at + 8].try_into().unwrap_or_default());
    let start = StartHeader {
        next_offset: u64_at(12),
        next_size: u64_at(20),
        next_crc: u32::from_le_bytes(data[28..32].try_into().unwrap_or_default()),
    };
    let max = SEVENZIP_MAX_ARCHIVE_BYTES - 32;
    (start.next_offset <= max && start.next_size <= max - start.next_offset).then_some(start)
}

/// `sevenZipArchiveLength`: `None` when the archive is bigger than the release itself.
fn seven_zip_archive_length(start: &StartHeader, release_size: i64) -> Option<u64> {
    let length = 32 + start.next_offset + start.next_size;
    match u64::try_from(release_size) {
        Ok(size) if size > 0 && length > size => None,
        _ => Some(length),
    }
}

/// `needsSevenZipEndHeader`: a 7z whose end header (the file list) lies beyond the data.
pub fn needs_seven_zip_end_header(data: &[u8]) -> bool {
    seven_zip_start_header(data)
        .is_some_and(|start| 32 + start.next_offset + start.next_size > data.len() as u64)
}

/// `withSevenZipEndHeader`: a listable 7z from its start header and the archive's last bytes.
///
/// The start header gives the size and CRC of the end header, which 7-Zip writes last, so it can
/// be cut from the tail and checked. Packed data is left out.
pub fn with_seven_zip_end_header(head: &[u8], tail: &[u8]) -> Option<Vec<u8>> {
    let start = seven_zip_start_header(head)?;
    let size = usize::try_from(start.next_size).ok()?;
    if size == 0 || size > tail.len() {
        return None;
    }
    let end_header = &tail[tail.len() - size..];
    if crc32fast::hash(end_header) != start.next_crc {
        return None;
    }
    let mut start_header = Vec::with_capacity(20);
    start_header.extend_from_slice(&0u64.to_le_bytes());
    start_header.extend_from_slice(&start.next_size.to_le_bytes());
    start_header.extend_from_slice(&start.next_crc.to_le_bytes());
    let mut joined = Vec::with_capacity(32 + size);
    joined.extend_from_slice(&head[..8]);
    joined.extend_from_slice(&crc32fast::hash(&start_header).to_le_bytes());
    joined.extend_from_slice(&start_header);
    joined.extend_from_slice(end_header);
    Some(joined)
}

/// PHP's `basename` on Linux, in the C locale.
fn php_basename(path: &[u8]) -> &[u8] {
    let end = path.len() - path.iter().rev().take_while(|byte| **byte == b'/').count();
    let trimmed = &path[..end];
    trimmed
        .iter()
        .rposition(|byte| *byte == b'/')
        .map_or(trimmed, |slash| &trimmed[slash + 1..])
}

const NFO_NAMES: [&[u8]; 13] = [
    b"file_id.diz",
    b"fileid.diz",
    b"file-id.diz",
    b"readme.txt",
    b"readme.1st",
    b"read.me",
    b"readmenow.txt",
    b"info.txt",
    b"information.txt",
    b"about.txt",
    b"notes.txt",
    b"release.txt",
    b"release.nfo",
];

/// `isNfoFile`: an NFO or info file, by name.
pub fn is_nfo_file(name: &[u8]) -> bool {
    let base = php_basename(name).to_ascii_lowercase();
    if NFO_NAMES.contains(&base.as_slice()) {
        return true;
    }
    // PCRE's `$` also matches before a final line break.
    let base = base.strip_suffix(b"\n").unwrap_or(&base);
    if [&b".nfo"[..], b".diz", b".inf"]
        .iter()
        .any(|extension| base.ends_with(extension))
    {
        return true;
    }
    // Scene-style names: 00-groupname.nfo, group-release.nfo.
    let Some(stem) = base
        .strip_suffix(b".nfo")
        .or_else(|| base.strip_suffix(b".txt"))
    else {
        return false;
    };
    let alnum = |byte: &u8| byte.is_ascii_lowercase() || byte.is_ascii_digit();
    let rest_of = |rest: &[u8], extra: &[u8]| {
        !rest.is_empty() && rest.iter().all(|byte| alnum(byte) || extra.contains(byte))
    };
    let numbered = stem
        .strip_prefix(b"00-")
        .or_else(|| stem.strip_prefix(b"0-"))
        .is_some_and(|rest| rest_of(rest, b"_-"));
    let leading = stem.iter().take_while(|byte| alnum(byte)).count();
    let grouped =
        leading > 0 && stem.get(leading) == Some(&b'-') && rest_of(&stem[leading + 1..], b"._-");
    numbered || grouped
}

/// `sortFilesWithNfoPriority`: NFO files first, otherwise in the order given.
pub fn sort_files_with_nfo_priority(files: &mut [ListEntry]) {
    files.sort_by_key(|entry| match entry {
        ListEntry::File(file) => !is_nfo_file(&file.name),
        _ => true,
    });
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn basename_matches_php() {
        assert_eq!(php_basename(b"a/b/c.nfo"), b"c.nfo");
        assert_eq!(php_basename(b"a/b/"), b"b");
        assert_eq!(php_basename(b"/"), b"");
        assert_eq!(php_basename(b"plain"), b"plain");
    }

    #[test]
    fn nfo_files_go_first_and_keep_their_order() {
        let file = |name: &str| {
            ListEntry::File(FileRecord {
                name: name.as_bytes().to_vec(),
                ..FileRecord::default()
            })
        };
        let mut files = vec![file("b.mkv"), file("x.nfo"), file("a.mkv"), file("y.diz")];
        sort_files_with_nfo_priority(&mut files);
        assert_eq!(
            files,
            vec![file("x.nfo"), file("y.diz"), file("b.mkv"), file("a.mkv")]
        );
    }
}
