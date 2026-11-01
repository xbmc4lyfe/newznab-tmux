//! What every reader shares: a byte source with PHP's bounds semantics, the integer codecs and
//! the file record. Port of `ArchiveReader`.

use std::fs::File;
use std::io::Write;
use std::os::unix::fs::FileExt;
use std::sync::Arc;
use std::sync::atomic::{AtomicI64, Ordering};
use std::time::{Duration, Instant};

/// `ArchiveReader::$maxReadBytes`. In-memory data past this isn't seen at all.
pub const MAX_READ_BYTES: i64 = 1_048_576;
/// `ArchiveReader::$maxFilenameLength`.
pub const MAX_FILENAME_LENGTH: usize = 256;

/// What one top-level archive and everything nested in it may hold in memory. Corrupt counts and
/// overlapping records multiply small inputs, and PHP's `memory_limit` was what stopped them.
pub const MAX_RETAINED_BYTES: i64 = 256 * 1024 * 1024;
/// Bytes read, plus [`READ_COST`] per read. Overlapping candidates make parsing quadratic.
pub const MAX_WORK: i64 = 16 * 1024 * 1024 * 1024;
/// The part of that one search for a header in a fragment may use. A whole article of plain text
/// asks for about 2 GiB of CRC, and a real header can sit at the end of it.
pub const MAX_SCAN_WORK: i64 = 4 * 1024 * 1024 * 1024;
const READ_COST: i64 = 64;
/// A single read from a file. PAR2 reads whole recovery packets to check them.
const MAX_FILE_READ: i64 = 1024 * 1024 * 1024;

/// Limits shared by a top-level archive and everything nested in it. Running out reads as the
/// data ending, so the listing comes back partial and callers treat it as they treat truncation.
#[derive(Debug)]
pub struct Budget {
    retained: AtomicI64,
    work: AtomicI64,
    work_limit: i64,
    nested: AtomicI64,
    extractions: AtomicI64,
    extracted: AtomicI64,
    open_files: AtomicI64,
    started: Instant,
}

impl Default for Budget {
    fn default() -> Self {
        Self::with_work_limit(MAX_WORK)
    }
}

impl Budget {
    /// A file whose reader only moves forward can need more work than it has corrupt candidates.
    pub fn with_work_limit(work_limit: i64) -> Self {
        Self {
            retained: AtomicI64::new(0),
            work: AtomicI64::new(0),
            work_limit,
            nested: AtomicI64::new(0),
            extractions: AtomicI64::new(0),
            extracted: AtomicI64::new(0),
            open_files: AtomicI64::new(0),
            started: Instant::now(),
        }
    }

    /// Accounts for memory about to be kept.
    pub fn retain(&self, bytes: usize) -> Parse<()> {
        let bytes = i64::try_from(bytes).map_err(|_| Thrown)?;
        self.retain_count(bytes, 1)
    }

    /// As [`Self::retain`], for `count` items of `size` bytes, before allocating them. Filling
    /// them is work too, and a scan hands the memory back but not the time.
    pub fn retain_count(&self, count: i64, size: usize) -> Parse<()> {
        let size = i64::try_from(size).map_err(|_| Thrown)?;
        let bytes = count.max(0).saturating_mul(size);
        self.spend_work(bytes)?;
        if bytes > MAX_RETAINED_BYTES
            || self.retained.fetch_add(bytes, Ordering::Relaxed) + bytes > MAX_RETAINED_BYTES
        {
            self.retained
                .fetch_max(MAX_RETAINED_BYTES, Ordering::Relaxed);
            return Err(Thrown);
        }
        Ok(())
    }

    pub fn retained(&self) -> i64 {
        self.retained.load(Ordering::Relaxed)
    }

    /// Hands back what a discarded parse held.
    pub fn restore_retained(&self, mark: i64) {
        self.retained.store(mark, Ordering::Relaxed);
    }

    pub fn work(&self) -> i64 {
        self.work.load(Ordering::Relaxed)
    }

    pub fn work_exhausted(&self) -> bool {
        self.work() >= self.work_limit
    }

    /// Whether a limit has been hit, so what's read from here on can't be trusted to be whole.
    pub fn exhausted(&self) -> bool {
        self.work_exhausted() || self.retained() >= MAX_RETAINED_BYTES
    }

    fn spend_work(&self, amount: i64) -> Parse<()> {
        if self
            .work
            .fetch_add(amount, Ordering::Relaxed)
            .saturating_add(amount)
            > self.work_limit
        {
            self.work.fetch_max(self.work_limit, Ordering::Relaxed);
            return Err(Thrown);
        }
        Ok(())
    }

    /// False once `limit` nested archives have been opened.
    pub fn admit_nested(&self, limit: i64) -> bool {
        self.nested.fetch_add(1, Ordering::Relaxed) < limit
    }

    /// False once `limit` files have been unpacked.
    pub fn admit_extraction(&self, limit: i64) -> bool {
        self.extractions.fetch_add(1, Ordering::Relaxed) < limit
    }

    /// False once `limit` unpacked archives are being held open.
    pub fn admit_open_file(&self, limit: i64) -> bool {
        self.open_files.fetch_add(1, Ordering::Relaxed) < limit
    }

    /// How long this archive has been read for.
    pub fn elapsed(&self) -> Duration {
        self.started.elapsed()
    }

    /// Bytes unpacked so far.
    pub fn extracted(&self) -> i64 {
        self.extracted.load(Ordering::Relaxed)
    }

    pub fn add_extracted(&self, bytes: i64) {
        self.extracted.fetch_add(bytes, Ordering::Relaxed);
    }
}

/// A read or seek out of bounds, or a PHP warning Laravel would have turned into an exception.
/// Every parser loop treats it as the end of readable data unless `error` was set too.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Thrown;

pub type Parse<T> = Result<T, Thrown>;

#[derive(Clone, Debug)]
pub enum Source {
    Memory(Arc<Vec<u8>>),
    File(Arc<File>, i64),
}

impl Source {
    fn size(&self) -> i64 {
        match self {
            Self::Memory(data) => i64::try_from(data.len())
                .unwrap_or(i64::MAX)
                .min(MAX_READ_BYTES),
            Self::File(_, size) => *size,
        }
    }

    /// The whole of this source, as a reader would have stored it.
    pub fn stored_data(&self) -> Option<&[u8]> {
        match self {
            Self::Memory(data) => {
                let size = usize::try_from(self.size()).ok()?;
                data.get(..size)
            }
            Self::File(..) => None,
        }
    }
}

/// A window of a [`Source`]. Offsets are relative to `start`.
#[derive(Clone, Debug)]
pub struct Cursor {
    source: Source,
    pub budget: Arc<Budget>,
    pub start: i64,
    pub end: i64,
    pub length: i64,
    pub offset: i64,
}

impl Cursor {
    /// `setData` or `open`. An `Err` is the reader's error message.
    pub fn open(
        source: Source,
        range: Option<(i64, i64)>,
        budget: Arc<Budget>,
    ) -> Result<Self, String> {
        let (start, mut end) = range.unwrap_or((0, 0));
        if start < 0 || end < 0 {
            return Err(format!(
                "Start ({start}) and end ({end}) points must be positive integers"
            ));
        }
        if end < start {
            return Err(format!(
                "End point ({end}) must be higher than start point ({start})"
            ));
        }
        let size = source.size();
        // setRange checks before the size is known to the file path, and with it for data.
        if matches!(source, Source::Memory(_)) && size == 0 {
            return Err("No data was passed, nothing to analyze".to_owned());
        }
        if end == 0 {
            end = size - 1;
        }
        let length = end - start + 1;
        if size != 0 && (end >= size || start >= size || length < 1) {
            return Err(format!("Byte range ({start}-{end}) is invalid"));
        }
        Ok(Self {
            source,
            budget,
            start,
            end,
            length,
            offset: 0,
        })
    }

    pub fn source(&self) -> &Source {
        &self.source
    }

    pub fn read(&mut self, count: i64) -> Parse<Vec<u8>> {
        if count == 0 {
            return Ok(Vec::new());
        }
        let new_position = self.offset.checked_add(count).ok_or(Thrown)?;
        if count < 1 || new_position > self.length {
            return Err(Thrown);
        }
        self.budget.spend_work(count.saturating_add(READ_COST))?;
        let bytes = self.bytes_at(self.start + self.offset, count)?;
        self.offset = new_position;
        Ok(bytes)
    }

    /// `read`, for bytes nothing looks at.
    pub fn skip(&mut self, count: i64) -> Parse<()> {
        if count == 0 {
            return Ok(());
        }
        let new_position = self.offset.checked_add(count).ok_or(Thrown)?;
        if count < 1 || new_position > self.length {
            return Err(Thrown);
        }
        self.budget.spend_work(READ_COST)?;
        self.offset = new_position;
        Ok(())
    }

    fn bytes_at(&self, position: i64, count: i64) -> Parse<Vec<u8>> {
        let from = usize::try_from(position).map_err(|_| Thrown)?;
        let count = usize::try_from(count).map_err(|_| Thrown)?;
        match &self.source {
            Source::Memory(data) => data
                .get(from..from.checked_add(count).ok_or(Thrown)?)
                .map(<[u8]>::to_vec)
                .ok_or(Thrown),
            Source::File(file, _) => {
                if i64::try_from(count).map_err(|_| Thrown)? > MAX_FILE_READ {
                    return Err(Thrown);
                }
                let mut buffer = vec![0; count];
                file.read_exact_at(&mut buffer, u64::try_from(from).map_err(|_| Thrown)?)
                    .map_err(|_| Thrown)?;
                Ok(buffer)
            }
        }
    }

    pub fn seek(&mut self, position: i64) -> Parse<()> {
        if position > self.length || position < 0 {
            return Err(Thrown);
        }
        self.offset = position;
        Ok(())
    }

    pub fn byte(&mut self) -> Parse<i64> {
        Ok(i64::from(self.read(1)?[0]))
    }

    pub fn u16(&mut self) -> Parse<i64> {
        let bytes = self.read(2)?;
        Ok(i64::from(u16::from_le_bytes([bytes[0], bytes[1]])))
    }

    pub fn u32(&mut self) -> Parse<i64> {
        let bytes = self.read(4)?;
        Ok(i64::from(u32::from_le_bytes([
            bytes[0], bytes[1], bytes[2], bytes[3],
        ])))
    }

    pub fn u64_pair(&mut self) -> Parse<i64> {
        let low = self.u32()?;
        let high = self.u32()?;
        Ok(int64(low, high))
    }

    /// The buffer marker searches run over.
    pub fn marker_buffer(&mut self) -> Parse<Vec<u8>> {
        let buffer = self.read(self.length.min(MAX_READ_BYTES))?;
        self.seek(0)?;
        Ok(buffer)
    }

    /// `getRange`, for an absolute range of the source.
    pub fn absolute_range(&self, start: i64, end: i64) -> Option<Vec<u8>> {
        let mut window =
            Self::open(self.source.clone(), Some((start, end)), self.budget.clone()).ok()?;
        let length = window.length;
        window.read(length).ok()
    }

    /// Writes this window to `output` without holding it in memory.
    pub fn copy_to(&self, output: &mut impl Write) -> std::io::Result<()> {
        const CHUNK: usize = 64 * 1024;
        let invalid = || std::io::Error::from(std::io::ErrorKind::InvalidInput);
        let start = u64::try_from(self.start).map_err(|_| invalid())?;
        let length = usize::try_from(self.length).map_err(|_| invalid())?;
        match &self.source {
            Source::Memory(data) => {
                let from = usize::try_from(start).map_err(|_| invalid())?;
                let bytes = from
                    .checked_add(length)
                    .and_then(|to| data.get(from..to))
                    .ok_or_else(invalid)?;
                output.write_all(bytes)
            }
            Source::File(file, _) => {
                let mut buffer = vec![0; CHUNK.min(length)];
                let mut copied = 0_usize;
                while copied < length {
                    let take = CHUNK.min(length - copied);
                    let position = start + u64::try_from(copied).map_err(|_| invalid())?;
                    file.read_exact_at(&mut buffer[..take], position)?;
                    output.write_all(&buffer[..take])?;
                    copied += take;
                }
                Ok(())
            }
        }
    }
}

/// `int64()`. The PHP goes to a float past `PHP_INT_MAX`, and such a value fails every later
/// bounds check, as the saturated integer does.
pub fn int64(low: i64, high: i64) -> i64 {
    let value = i128::from(low) + i128::from(high) * 0x1_0000_0000;
    i64::try_from(value).unwrap_or(i64::MAX)
}

/// `dos2unixtime`, in UTC, which is the app's timezone. Out-of-range fields roll over as
/// `mktime` rolls them.
pub fn dos_to_unix(dos: i64) -> i64 {
    let second = 2 * (dos & 0x1f);
    let minute = (dos >> 5) & 0x3f;
    let hour = (dos >> 11) & 0x1f;
    let day = (dos >> 16) & 0x1f;
    let month = (dos >> 21) & 0x0f;
    let year = ((dos >> 25) & 0x7f) + 1980;
    let months = year * 12 + (month - 1);
    let days = days_from_civil(months.div_euclid(12), months.rem_euclid(12) + 1) + (day - 1);
    days * 86_400 + hour * 3600 + minute * 60 + second
}

/// Days from 1970-01-01 to the first of a month.
fn days_from_civil(year: i64, month: i64) -> i64 {
    let shifted = if month <= 2 { year - 1 } else { year };
    let era = shifted.div_euclid(400);
    let year_of_era = shifted.rem_euclid(400);
    let month_index = (month + 9) % 12;
    let day_of_year = (153 * month_index + 2) / 5;
    let day_of_era = year_of_era * 365 + year_of_era / 4 - year_of_era / 100 + day_of_year;
    era * 146_097 + day_of_era - 719_468
}

/// `win2unixtime`.
pub fn windows_to_unix(low: i64, high: i64) -> i64 {
    let filetime = i128::from(low) + i128::from(high) * 0x1_0000_0000;
    let seconds = (filetime - 116_444_736_000_000_000).div_euclid(10_000_000);
    i64::try_from(seconds).unwrap_or(i64::MAX)
}

/// PHP's `empty()` on a string, which counts `"0"` as empty.
pub fn php_empty(bytes: &[u8]) -> bool {
    bytes.is_empty() || bytes == b"0"
}

/// Position of `needle` in `haystack`.
pub fn find(haystack: &[u8], needle: &[u8]) -> Option<i64> {
    haystack
        .windows(needle.len())
        .position(|window| window == needle)
        .and_then(|position| i64::try_from(position).ok())
}

pub fn truncated_name(name: &[u8]) -> Vec<u8> {
    name[..name.len().min(MAX_FILENAME_LENGTH)].to_vec()
}

/// `iconv('UTF-16LE', 'UTF-8//IGNORE//TRANSLIT', ...)`.
pub fn utf16le_to_utf8(bytes: &[u8]) -> Vec<u8> {
    let units = bytes
        .as_chunks::<2>()
        .0
        .iter()
        .map(|pair| u16::from_le_bytes(*pair));
    let mut out = String::new();
    for unit in char::decode_utf16(units).flatten() {
        out.push(unit);
    }
    out.into_bytes()
}

/// One row of a reader's file list. Fields a format doesn't have stay at their default, and the
/// consumers check them the way the PHP's `empty()` and `??` did.
#[derive(Clone, Debug, Default, PartialEq, Eq, serde::Serialize)]
pub struct FileRecord {
    pub name: Vec<u8>,
    pub size: i64,
    pub date: i64,
    pub pass: i64,
    /// `None` where the format has no such field, or RAR's empty string for a method-less block.
    pub compressed: Option<i64>,
    pub next_offset: Option<i64>,
    pub is_dir: bool,
    /// Absolute and inclusive. The end can precede the start for a zero-length file.
    pub range: Option<(i64, i64)>,
    pub crc32: Option<String>,
    pub split: bool,
    pub split_after: bool,
    pub source: String,
    pub hash: Option<String>,
    pub hash_16k: Option<String>,
    pub blocks: Option<i64>,
    pub checksum: Option<String>,
}

/// One element of `getArchiveFileList`, which mixes rows, errors and whole nested lists.
#[derive(Clone, Debug, PartialEq, Eq, serde::Serialize)]
pub enum ListEntry {
    File(FileRecord),
    Error { error: String, source: String },
    Nested(Vec<ListEntry>),
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn dos_times_match_mktime() {
        // 2013-05-27 14:32:10.
        let dos = ((2013 - 1980) << 25) | (5 << 21) | (27 << 16) | (14 << 11) | (32 << 5) | 5;
        assert_eq!(dos_to_unix(dos), 1_369_665_130);
        // Month and day zero roll back, to 1979-11-30.
        assert_eq!(dos_to_unix(0), 312_768_000);
    }

    #[test]
    fn windows_times_floor() {
        assert_eq!(windows_to_unix(0xd53e_8000, 0x019d_b1de), 0);
        assert_eq!(windows_to_unix(0xd53e_7fff, 0x019d_b1de), -1);
    }

    #[test]
    fn memory_sources_stop_at_the_read_limit() -> Result<(), String> {
        let data = Arc::new(vec![0_u8; 2_000_000]);
        let cursor = Cursor::open(Source::Memory(data), None, Arc::default())?;
        assert_eq!(cursor.length, MAX_READ_BYTES);
        Ok(())
    }

    #[test]
    fn ranges_are_checked() {
        let source = || Source::Memory(Arc::new(vec![0_u8; 10]));
        assert_eq!(
            Cursor::open(source(), Some((5, 20)), Arc::default()).unwrap_err(),
            "Byte range (5-20) is invalid"
        );
        assert!(Cursor::open(source(), Some((5, 3)), Arc::default()).is_err());
        let window = Cursor::open(source(), Some((2, 4)), Arc::default()).expect("valid");
        assert_eq!(window.length, 3);
    }
}
