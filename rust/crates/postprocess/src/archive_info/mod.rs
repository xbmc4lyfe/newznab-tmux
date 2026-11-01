//! Reads archive file lists from downloaded bytes, without decompressing anything. Port of the
//! `dariusiii/rarinfo` library, which production ran.
//!
//! `nntmux_formats::archive` reports header evidence for one article. This module answers what
//! additional processing asks: which reader claims this data, what's in it, and can a stored
//! file be copied out. Warnings Laravel turned into exceptions come back as errors, so a caller
//! fails the same step the PHP failed.

pub mod par2;
pub mod rar;
pub mod reader;
pub mod szip;
pub mod zip;

use std::ffi::OsStr;
use std::fs::File;
use std::io::{Read, Write};
use std::os::unix::ffi::OsStrExt;
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};
use std::sync::Arc;

use anyhow::{Context, Result, anyhow, bail};
use tempfile::NamedTempFile;

use self::par2::Par2Reader;
use self::rar::RarReader;
use self::reader::{Cursor, Source, Thrown};
pub use self::reader::{FileRecord, ListEntry};
use self::szip::SzipReader;
use self::zip::ZipReader;
use crate::config;

pub const MAIN_SOURCE: &str = "main";

/// The archive types, as `ArchiveInfo` names them.
#[derive(Clone, Copy, Debug, PartialEq, Eq, serde::Serialize)]
pub enum Kind {
    None,
    Rar,
    Srr,
    Par2,
    Zip,
    Sfv,
    Szip,
}

#[derive(Clone, Debug)]
enum Reader {
    Rar(Box<RarReader>),
    Par2(Box<Par2Reader>),
    Zip(Box<ZipReader>),
    Sfv(Vec<FileRecord>),
    Szip(Box<SzipReader>),
}

impl Reader {
    fn open(kind: Kind, cursor: Cursor, fragment: bool) -> Result<Self> {
        Ok(match kind {
            Kind::Rar => Self::Rar(Box::new(RarReader::analyze(cursor, fragment, false))),
            Kind::Srr => Self::Rar(Box::new(RarReader::analyze(cursor, fragment, true))),
            Kind::Par2 => Self::Par2(Box::new(Par2Reader::analyze(cursor))),
            Kind::Zip => Self::Zip(Box::new(ZipReader::analyze(cursor))),
            Kind::Szip => Self::Szip(Box::new(
                SzipReader::analyze(cursor, fragment)
                    .map_err(|_| anyhow!("a 7z marker with no room for its start header"))?,
            )),
            Kind::Sfv | Kind::None => {
                let mut cursor = cursor;
                match par2::parse_sfv(&mut cursor) {
                    Ok(files) => Self::Sfv(files),
                    Err(message) if message.is_empty() => Self::Sfv(Vec::new()),
                    // No reader wants this; the caller moves on as for a reader error.
                    Err(_) => bail!("not an SFV"),
                }
            }
        })
    }

    fn error(&self) -> &str {
        match self {
            Self::Rar(reader) => &reader.error,
            Self::Par2(reader) => &reader.error,
            Self::Zip(reader) => &reader.error,
            Self::Sfv(_) => "",
            Self::Szip(reader) => &reader.error,
        }
    }

    fn marker(&mut self) -> Option<i64> {
        match self {
            Self::Rar(reader) => reader.find_marker(),
            Self::Par2(reader) => reader.find_marker(),
            Self::Zip(reader) => reader.find_marker(),
            Self::Sfv(_) => Some(0),
            Self::Szip(reader) => reader.find_marker(),
        }
    }

    fn is_encrypted(&self) -> bool {
        match self {
            Self::Rar(reader) => reader.is_encrypted,
            Self::Zip(reader) => reader.is_encrypted,
            Self::Szip(reader) => reader.is_encrypted,
            Self::Par2(_) | Self::Sfv(_) => false,
        }
    }

    /// Only readers that keep their data can hand out file bytes.
    fn cursor(&self) -> Option<&Cursor> {
        match self {
            Self::Rar(reader) => Some(reader.cursor()),
            Self::Zip(reader) => Some(reader.cursor()),
            Self::Szip(reader) => Some(reader.cursor()),
            Self::Par2(_) | Self::Sfv(_) => None,
        }
    }

    /// `getFileList`. A row with no name, which SRR and PAR2 can produce, is an empty
    /// [`ListEntry::Nested`], because consumers treat both as nameless.
    fn file_list(&self, kind: Kind) -> Result<Vec<ListEntry>, Thrown> {
        let nameless = || ListEntry::Nested(Vec::new());
        Ok(match self {
            Self::Rar(reader) if kind == Kind::Srr => reader
                .srr_file_list()?
                .into_iter()
                .map(|(name, _)| {
                    name.map_or_else(nameless, |name| {
                        ListEntry::File(FileRecord {
                            name,
                            ..FileRecord::default()
                        })
                    })
                })
                .collect(),
            Self::Rar(reader) => files(reader.file_list(false)?),
            Self::Par2(reader) => reader
                .file_list()
                .into_iter()
                .map(|(_, file, blocks)| {
                    file.map_or_else(nameless, |mut file| {
                        file.blocks = Some(blocks);
                        ListEntry::File(file)
                    })
                })
                .collect(),
            Self::Zip(reader) => files(reader.file_list()),
            Self::Sfv(list) => files(list.clone()),
            Self::Szip(reader) => files(reader.file_list()?),
        })
    }
}

fn files(list: Vec<FileRecord>) -> Vec<ListEntry> {
    list.into_iter().map(ListEntry::File).collect()
}

/// `getSummary`: the fields additional processing reads.
#[derive(Clone, Debug, Default, PartialEq, Eq, serde::Serialize)]
pub struct Summary {
    pub is_volume: bool,
    pub is_encrypted: bool,
    pub enc_header: bool,
    /// Present only when asked for in full.
    pub file_list: Vec<ListEntry>,
    pub archives: Vec<(Vec<u8>, Summary)>,
}

/// An archive and, once listed, the ones stored inside it.
#[derive(Debug)]
pub struct ArchiveInfo {
    pub kind: Kind,
    source: Source,
    file_path: Option<PathBuf>,
    fragment: bool,
    reader: Option<Reader>,
    own_error: String,
    /// A compressed or encrypted entry that can't be read, kept so its error gets listed.
    readerless: bool,
    unrar: Option<PathBuf>,
    children: Option<Vec<(Vec<u8>, ArchiveInfo)>>,
    temporary: bool,
    temp_files: Vec<NamedTempFile>,
}

impl ArchiveInfo {
    /// `setData($data, $fragment)`, with the RAR client `ArchiveExtractionService` configures.
    pub fn from_data(data: Arc<Vec<u8>>, fragment: bool, unrar: Option<&Path>) -> Result<Self> {
        Self::open(
            Source::Memory(data),
            None,
            fragment,
            None,
            unrar.map(Path::to_path_buf),
        )
    }

    fn open(
        source: Source,
        file_path: Option<PathBuf>,
        fragment: bool,
        range: Option<(i64, i64)>,
        unrar: Option<PathBuf>,
    ) -> Result<Self> {
        let mut info = Self {
            kind: Kind::None,
            source,
            file_path,
            fragment,
            reader: None,
            own_error: String::new(),
            readerless: false,
            unrar,
            children: None,
            temporary: false,
            temp_files: Vec::new(),
        };
        match Cursor::open(info.source.clone(), range) {
            Ok(cursor) => {
                info.select_reader(&cursor)?;
                if info.reader.is_none() {
                    info.own_error = "Source is not a supported archive type".to_owned();
                }
            }
            Err(message) => info.own_error = message,
        }
        Ok(info)
    }

    fn open_file(path: &Path, fragment: bool, unrar: Option<PathBuf>) -> Result<Self> {
        let file = File::open(path).with_context(|| format!("opening {}", path.display()))?;
        let size = i64::try_from(file.metadata()?.len())?;
        Self::open(
            Source::File(Arc::new(file), size),
            Some(path.to_path_buf()),
            fragment,
            None,
            unrar,
        )
    }

    /// The reader with the earliest marker. A later reader wins a tie, and a marker at zero ends
    /// the search, so an SFV claim stops a 7z from being tried.
    fn select_reader(&mut self, cursor: &Cursor) -> Result<()> {
        let mut earliest: Option<i64> = None;
        for kind in [
            Kind::Rar,
            Kind::Srr,
            Kind::Par2,
            Kind::Zip,
            Kind::Sfv,
            Kind::Szip,
        ] {
            let mut reader = match Reader::open(kind, cursor.clone(), self.fragment) {
                Ok(reader) => reader,
                Err(_) if kind == Kind::Sfv => continue,
                Err(failure) => return Err(failure),
            };
            if !reader.error().is_empty() {
                continue;
            }
            let Some(marker) = reader.marker() else {
                continue;
            };
            let start = *earliest.get_or_insert(marker);
            if marker <= start {
                earliest = Some(marker);
                if kind == Kind::Rar
                    && let Some(client) = &self.unrar
                    && !config::is_executable(client)
                {
                    bail!("Not a valid client: {}", client.display());
                }
                self.kind = kind;
                self.reader = Some(reader);
            }
            if earliest == Some(0) {
                break;
            }
        }
        Ok(())
    }

    /// Its own error, or its reader's.
    pub fn error(&self) -> &str {
        if !self.own_error.is_empty() {
            return &self.own_error;
        }
        self.reader.as_ref().map_or("", Reader::error)
    }

    pub fn is_encrypted(&self) -> bool {
        self.reader.as_ref().is_some_and(Reader::is_encrypted)
    }

    pub fn allows_recursion(&self) -> bool {
        matches!(self.kind, Kind::Rar | Kind::Zip | Kind::Szip)
    }

    fn can_extract(&self) -> bool {
        self.reader.is_some() && self.kind == Kind::Rar && self.unrar.is_some()
    }

    fn entries(&self) -> Result<Vec<ListEntry>> {
        let Some(reader) = &self.reader else {
            return Ok(Vec::new());
        };
        reader
            .file_list(self.kind)
            .map_err(|_| anyhow!("the archive's file list could not be collated"))
    }

    fn records(&self) -> Result<Vec<FileRecord>> {
        Ok(self
            .entries()?
            .into_iter()
            .filter_map(|entry| match entry {
                ListEntry::File(file) => Some(file),
                _ => None,
            })
            .collect())
    }

    /// `getArchiveList`, computed once.
    fn ensure_children(&mut self) -> Result<()> {
        if self.children.is_some() || self.reader.is_none() || !self.allows_recursion() {
            return Ok(());
        }
        let records = self.records()?;
        let mut children: Vec<(Vec<u8>, Self)> = Vec::new();
        for record in &records {
            if !has_archive_extension(&record.name) {
                continue;
            }
            let Some(child) = self.child(&record.name, &records)? else {
                continue;
            };
            if child.kind == Kind::None && !child.readerless {
                continue;
            }
            match children.iter_mut().find(|(name, _)| *name == record.name) {
                Some(slot) => slot.1 = child,
                None => children.push((record.name.clone(), child)),
            }
        }
        if self.unrar.is_some() {
            self.extract_children(&mut children)?;
        }
        self.children = Some(children);
        Ok(())
    }

    /// `getArchive`: the archive stored as this named entry, if any.
    fn child(&mut self, name: &[u8], records: &[FileRecord]) -> Result<Option<Self>> {
        let Some(record) = records
            .iter()
            .find(|record| record.name == name && record.range.is_some())
        else {
            return Ok(None);
        };
        let compressed = record.compressed.unwrap_or(0) != 0;
        let encrypted = record.pass != 0;
        if compressed && !encrypted && self.can_extract() {
            return self.extracted_child(name).map(Some);
        }
        let mut child = if compressed || encrypted {
            Self {
                kind: Kind::None,
                source: self.source.clone(),
                file_path: self.file_path.clone(),
                fragment: self.fragment,
                reader: None,
                own_error: String::new(),
                readerless: true,
                unrar: self.unrar.clone(),
                children: None,
                temporary: false,
                temp_files: Vec::new(),
            }
        } else {
            // `explode('-', ...)` reads a negative end as an empty string, so as zero.
            let range = record.range.map(|(start, end)| (start, end.max(0)));
            Self::open(
                self.source.clone(),
                self.file_path.clone(),
                self.fragment,
                range,
                self.unrar.clone(),
            )?
        };
        if compressed {
            child.own_error = "The archive is compressed and cannot be read".to_owned();
        }
        if child.is_encrypted() || encrypted {
            child.own_error = "The archive is encrypted and cannot be read".to_owned();
        }
        Ok(Some(child))
    }

    fn extracted_child(&mut self, name: &[u8]) -> Result<Self> {
        let temp = NamedTempFile::new().context("creating a temp file for an inner archive")?;
        let outcome = self.extract_file(name, temp.path());
        let mut child = match outcome {
            Ok(()) => Self::open_file(temp.path(), self.fragment, self.unrar.clone())?,
            Err(message) => Self {
                kind: Kind::None,
                source: self.source.clone(),
                file_path: None,
                fragment: self.fragment,
                reader: None,
                own_error: message,
                readerless: true,
                unrar: self.unrar.clone(),
                children: None,
                temporary: false,
                temp_files: Vec::new(),
            },
        };
        child.temporary = true;
        self.temp_files.push(temp);
        Ok(child)
    }

    /// `RarInfo::extractFile`: `unrar p` into `destination`. `Err` is the reader's error text.
    fn extract_file(&mut self, name: &[u8], destination: &Path) -> Result<(), String> {
        let Some(client) = self.unrar.clone() else {
            return Err("An external client and valid data source are needed".to_owned());
        };
        let source = match &self.file_path {
            Some(path) => path.clone(),
            None => {
                let mut temp = NamedTempFile::new().map_err(|failure| failure.to_string())?;
                let data = self.source.stored_data().unwrap_or_default();
                temp.write_all(data)
                    .map_err(|failure| failure.to_string())?;
                let path = temp.path().to_path_buf();
                self.temp_files.push(temp);
                path
            }
        };
        let filename: Vec<u8> = name
            .iter()
            .filter(|byte| **byte != 0)
            .map(|byte| if *byte == b'\\' { b'/' } else { *byte })
            .collect();
        let output = File::create(destination).map_err(|failure| failure.to_string())?;
        let mut child = Command::new(client)
            .args(["p", "-kb", "-y", "-c-", "-ierr", "-ip", "-p-", "--"])
            .arg(&source)
            .arg(OsStr::from_bytes(&filename))
            .stdout(Stdio::from(output))
            .stderr(Stdio::piped())
            .stdin(Stdio::null())
            .spawn()
            .map_err(|failure| failure.to_string())?;
        let mut errors = Vec::new();
        if let Some(mut stderr) = child.stderr.take() {
            let _ = stderr.read_to_end(&mut errors);
        }
        let _ = child.wait();
        let errors = String::from_utf8_lossy(&errors);
        if !errors.is_empty() && !errors.contains("All OK") {
            return Err(errors.into_owned());
        }
        Ok(())
    }

    /// `extractArchives`: an inner RAR with compressed files is reopened from its own temp file,
    /// so the client can read it.
    fn extract_children(&mut self, children: &mut [(Vec<u8>, Self)]) -> Result<()> {
        if !self.can_extract() {
            return Ok(());
        }
        for (_, child) in children.iter_mut() {
            if child.temporary || !child.can_extract() {
                continue;
            }
            let wants_extraction = child
                .records()?
                .iter()
                .any(|record| record.compressed.unwrap_or(0) != 0 && record.pass == 0);
            if !wants_extraction {
                continue;
            }
            let Some(cursor) = child.reader.as_ref().and_then(Reader::cursor) else {
                continue;
            };
            let bytes = cursor
                .absolute_range(cursor.start, cursor.end)
                .unwrap_or_default();
            let mut temp = NamedTempFile::new().context("creating a temp file")?;
            temp.write_all(&bytes)?;
            let mut opened = Self::open_file(temp.path(), child.fragment, child.unrar.clone())?;
            opened.temporary = true;
            *child = opened;
            self.temp_files.push(temp);
        }
        Ok(())
    }

    /// `getSummary($full)`.
    pub fn summary(&mut self, full: bool) -> Result<Summary> {
        let mut summary = Summary::default();
        match &self.reader {
            Some(Reader::Rar(reader)) => {
                // SRR's summary has no such field.
                summary.is_volume = reader.is_volume && self.kind == Kind::Rar;
                summary.is_encrypted = reader.is_encrypted;
                self.entries()?;
            }
            Some(Reader::Szip(reader)) => {
                summary.is_encrypted = reader.is_encrypted;
                summary.enc_header = reader.has_encoded_header;
                self.entries()?;
            }
            Some(Reader::Zip(reader)) => summary.is_encrypted = reader.is_encrypted,
            _ => {}
        }
        if full {
            summary.file_list = self.entries()?;
            self.ensure_children()?;
            if let Some(children) = &mut self.children {
                for (name, child) in children.iter_mut() {
                    summary.archives.push((name.clone(), child.summary(true)?));
                }
            }
        }
        Ok(summary)
    }

    /// `getArchiveFileList($recurse)`. `None` when no reader claimed the data.
    pub fn archive_file_list(&mut self, recurse: bool) -> Result<Option<Vec<ListEntry>>> {
        if self.reader.is_none() {
            return Ok(None);
        }
        let main = self.entries()?;
        let mut list = flatten(main, MAIN_SOURCE);
        if recurse {
            self.append_children(MAIN_SOURCE, &mut list)?;
        }
        Ok(Some(list))
    }

    fn append_children(&mut self, source: &str, list: &mut Vec<ListEntry>) -> Result<()> {
        self.ensure_children()?;
        let Some(children) = &mut self.children else {
            return Ok(());
        };
        for (name, child) in children.iter_mut() {
            if child.error().is_empty() && !child.allows_recursion() {
                continue;
            }
            let branch = format!("{source} > {}", String::from_utf8_lossy(name));
            let files = child.entries()?;
            if !child.error().is_empty() || files.is_empty() {
                let error = if child.error().is_empty() {
                    "No files found".to_owned()
                } else {
                    child.error().to_owned()
                };
                list.push(ListEntry::Error {
                    error,
                    source: branch,
                });
                continue;
            }
            list.push(ListEntry::Nested(flatten(files, &branch)));
            let mut nested = Vec::new();
            child.append_children(&branch, &mut nested)?;
            if child.children.as_ref().is_some_and(|c| !c.is_empty()) {
                list.push(ListEntry::Nested(nested));
            }
        }
        Ok(())
    }

    /// `getFileData`, for an entry of the top archive.
    pub fn file_data(&mut self, name: &[u8]) -> Result<Option<Vec<u8>>> {
        let Some(cursor) = self.reader.as_ref().and_then(Reader::cursor).cloned() else {
            return Ok(None);
        };
        let Some(list) = self.archive_file_list(true)? else {
            return Ok(None);
        };
        for entry in list {
            let ListEntry::File(file) = entry else {
                continue;
            };
            if file.is_dir || file.name != name || reader::php_empty(&file.name) {
                continue;
            }
            let Some((start, end)) = file.range else {
                return Ok(None);
            };
            return Ok(cursor.absolute_range(start, end.max(0)));
        }
        Ok(None)
    }

    /// `hasTerminalRarBlock`: the listing reached the archive's own end, and this is the last
    /// volume. `data_length` is the data as downloaded, past any read limit.
    pub fn has_terminal_rar_block(&self, data_length: usize) -> bool {
        let Some(Reader::Rar(reader)) = &self.reader else {
            return false;
        };
        let Ok(length) = i64::try_from(data_length) else {
            return false;
        };
        reader
            .last_end_block()
            .is_some_and(|(more_volumes, next)| !more_volumes && next == length)
    }

    /// The encoded-header fields of a 7z, as `SzipInfo::getHeaders` reports them. `None` unless
    /// a 7z reader claimed the data. Each entry is the pack offset and pack sizes.
    pub fn seven_zip_encoded_header(&self) -> Option<(i64, Vec<i64>)> {
        let Some(Reader::Szip(reader)) = &self.reader else {
            return None;
        };
        let header = reader.encoded_headers().last()?;
        Some((header.pack_offset?, header.pack_sizes.clone()?))
    }
}

/// `flattenFileList`: stamps the source. Offsets mean nothing outside the top archive.
fn flatten(entries: Vec<ListEntry>, source: &str) -> Vec<ListEntry> {
    entries
        .into_iter()
        .map(|entry| match entry {
            ListEntry::File(mut file) => {
                file.source = source.to_owned();
                if source != MAIN_SOURCE {
                    file.next_offset = None;
                }
                ListEntry::File(file)
            }
            other => other,
        })
        .collect()
}

/// `rar|r[0-9]+|zip|srr|par2|sfv|7z|[0-9]+`, case-sensitive, on the base name's extension.
fn has_archive_extension(name: &[u8]) -> bool {
    let base = name.rsplit(|byte| *byte == b'/').next().unwrap_or(name);
    let Some(dot) = base.iter().rposition(|byte| *byte == b'.') else {
        return false;
    };
    let extension = &base[dot + 1..];
    let digits = |bytes: &[u8]| !bytes.is_empty() && bytes.iter().all(u8::is_ascii_digit);
    matches!(
        extension,
        b"rar" | b"zip" | b"srr" | b"par2" | b"sfv" | b"7z"
    ) || digits(extension)
        || extension.first() == Some(&b'r') && digits(&extension[1..])
}

/// `Par2Info` over bytes. `setData` without the fragment flag.
pub fn par2_from_data(data: Arc<Vec<u8>>) -> Result<Par2Reader, String> {
    Ok(Par2Reader::analyze(Cursor::open(
        Source::Memory(data),
        None,
    )?))
}

/// `Par2Info::open`, which unlike `setData` isn't capped at the read limit.
pub fn par2_from_file(path: &Path) -> Result<Par2Reader, String> {
    let file = File::open(path).map_err(|failure| failure.to_string())?;
    let size = file
        .metadata()
        .map_err(|failure| failure.to_string())
        .and_then(|meta| i64::try_from(meta.len()).map_err(|failure| failure.to_string()))?;
    Ok(Par2Reader::analyze(Cursor::open(
        Source::File(Arc::new(file), size),
        None,
    )?))
}
