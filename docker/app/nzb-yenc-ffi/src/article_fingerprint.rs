//! Byte-exact article identity with bounded sorted runs and fail-closed I/O.
use sha2::{Digest, Sha256};
use std::fs::{File, OpenOptions};
use std::io::{self, BufReader, BufWriter, Read, Seek, SeekFrom, Write};
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{SystemTime, UNIX_EPOCH};

const MAX_ID_BYTES: usize = 255;
const BUFFER_IDS: usize = 4096;
const BUFFER_BYTES: usize = 1_048_576;
const MAX_RUN_LEVELS: usize = 32;
static NEXT_RUN: AtomicU64 = AtomicU64::new(0);

pub struct Accumulator {
    buffer: Vec<Vec<u8>>,
    buffer_bytes: usize,
    runs: [Option<Run>; MAX_RUN_LEVELS],
    directory: PathBuf,
    failed: bool,
    dirty: bool,
    fingerprint: Option<[u8; 40]>,
}

impl Default for Accumulator {
    fn default() -> Self {
        Self::new()
    }
}

impl Accumulator {
    pub fn new() -> Self {
        Self::in_directory(std::env::temp_dir())
    }

    fn in_directory(directory: PathBuf) -> Self {
        Self {
            buffer: Vec::new(),
            buffer_bytes: 0,
            runs: std::array::from_fn(|_| None),
            directory,
            failed: false,
            dirty: false,
            fingerprint: None,
        }
    }

    pub fn add(&mut self, message_id: &[u8]) -> bool {
        if self.failed {
            return false;
        }
        let id = normalize(message_id);
        if id.is_empty() || id.len() > MAX_ID_BYTES || id.iter().any(|&b| b <= 32 || b == 127) {
            self.invalidate();
            return false;
        }
        self.dirty = true;
        self.buffer_bytes += id.len();
        self.buffer.push(id.to_vec());
        if (self.buffer.len() >= BUFFER_IDS || self.buffer_bytes >= BUFFER_BYTES)
            && self.flush_buffer().is_err()
        {
            self.invalidate();
            return false;
        }
        true
    }

    pub fn value(&mut self) -> Option<[u8; 40]> {
        if self.failed || !self.dirty {
            return self.fingerprint;
        }
        match self.calculate() {
            Ok(value) => {
                self.fingerprint = value;
                self.dirty = false;
            }
            Err(_) => self.invalidate(),
        }
        self.fingerprint
    }

    /// Also used by the C ABI if a caught panic interrupts an operation.
    pub fn invalidate(&mut self) {
        self.failed = true;
        self.dirty = false;
        self.fingerprint = None;
        self.buffer = Vec::new();
        self.buffer_bytes = 0;
        self.runs = std::array::from_fn(|_| None);
    }

    fn flush_buffer(&mut self) -> io::Result<()> {
        if self.buffer.is_empty() {
            return Ok(());
        }
        self.buffer.sort_unstable();
        self.buffer.dedup();
        let mut run = Run::create(&self.directory)?;
        {
            let mut writer = BufWriter::new(run.file());
            for id in &self.buffer {
                write_record(&mut writer, id)?;
            }
            writer.flush()?;
        }
        self.buffer.clear();
        self.buffer_bytes = 0;
        for level in 0..MAX_RUN_LEVELS {
            match self.runs[level].take() {
                Some(other) => run = merge(other, run, &self.directory)?,
                None => {
                    self.runs[level] = Some(run);
                    return Ok(());
                }
            }
        }
        Err(io::Error::other("Article identity run limit exceeded"))
    }

    fn calculate(&mut self) -> io::Result<Option<[u8; 40]>> {
        if self.runs.iter().all(Option::is_none) {
            self.buffer.sort_unstable();
            self.buffer.dedup();
            self.buffer_bytes = self.buffer.iter().map(Vec::len).sum();
            let mut lanes = [0u32; 8];
            for id in &self.buffer {
                add_lanes(&mut lanes, id);
            }
            return Ok(encode_value(self.buffer.len() as u64, lanes));
        }
        self.flush_buffer()?;
        let mut combined = None;
        for slot in &mut self.runs {
            if let Some(run) = slot.take() {
                combined = Some(match combined {
                    None => run,
                    Some(other) => merge(other, run, &self.directory)?,
                });
            }
        }
        let Some(mut run) = combined else {
            return Ok(None);
        };
        run.file().seek(SeekFrom::Start(0))?;
        let mut count = 0u64;
        let mut lanes = [0u32; 8];
        {
            let mut reader = BufReader::new(run.file());
            while let Some(id) = read_record(&mut reader)? {
                add_lanes(&mut lanes, &id);
                count = count
                    .checked_add(1)
                    .ok_or_else(|| io::Error::other("Article identity count overflow"))?;
            }
        }
        self.runs[0] = Some(run);
        Ok(encode_value(count, lanes))
    }
}

fn add_lanes(lanes: &mut [u32; 8], id: &[u8]) {
    let digest = Sha256::digest(id);
    for (lane, word) in lanes.iter_mut().zip(digest.as_chunks::<4>().0.iter()) {
        *lane = lane.wrapping_add(u32::from_be_bytes(*word));
    }
}

fn encode_value(count: u64, lanes: [u32; 8]) -> Option<[u8; 40]> {
    if count == 0 {
        return None;
    }
    let mut value = [0u8; 40];
    value[..8].copy_from_slice(&count.to_be_bytes());
    for (out, lane) in value[8..].as_chunks_mut::<4>().0.iter_mut().zip(lanes) {
        out.copy_from_slice(&lane.to_be_bytes());
    }
    Some(value)
}

fn trim(mut bytes: &[u8]) -> &[u8] {
    let is_trim = |b: u8| matches!(b, b' ' | b'\t' | b'\r' | b'\n' | 0 | 11);
    while bytes.first().is_some_and(|&b| is_trim(b)) {
        bytes = &bytes[1..];
    }
    while bytes.last().is_some_and(|&b| is_trim(b)) {
        bytes = &bytes[..bytes.len() - 1];
    }
    bytes
}

fn normalize(bytes: &[u8]) -> &[u8] {
    let mut id = trim(bytes);
    if id.len() >= 2 && (id[0] == b'"' || id[0] == b'\'') && id.last() == Some(&id[0]) {
        id = trim(&id[1..id.len() - 1]);
    }
    if id.starts_with(b"<") && id.ends_with(b">") {
        id = &id[1..id.len() - 1];
    }
    trim(id)
}

/// File is removed immediately on Unix; other platforms remove it after closing.
struct Run {
    file: Option<File>,
    path: Option<PathBuf>,
}

impl Run {
    fn create(directory: &Path) -> io::Result<Self> {
        let timestamp = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_nanos();
        for _ in 0..128 {
            let sequence = NEXT_RUN.fetch_add(1, Ordering::Relaxed);
            let path = directory.join(format!(
                "nntmux-article-{}-{timestamp:x}-{sequence:x}",
                std::process::id()
            ));
            let mut options = OpenOptions::new();
            options.read(true).write(true).create_new(true);
            #[cfg(unix)]
            {
                use std::os::unix::fs::OpenOptionsExt;
                options.mode(0o600);
            }
            let file = match options.open(&path) {
                Ok(file) => file,
                Err(error) if error.kind() == io::ErrorKind::AlreadyExists => continue,
                Err(error) => return Err(error),
            };
            let mut run = Self {
                file: Some(file),
                path: Some(path),
            };
            #[cfg(unix)]
            {
                std::fs::remove_file(run.path.as_ref().unwrap())?;
                run.path = None;
            }
            return Ok(run);
        }
        Err(io::Error::new(
            io::ErrorKind::AlreadyExists,
            "Article identity temporary name collisions",
        ))
    }

    fn file(&mut self) -> &mut File {
        self.file.as_mut().unwrap()
    }
}

impl Drop for Run {
    fn drop(&mut self) {
        drop(self.file.take());
        if let Some(path) = self.path.take() {
            let _ = std::fs::remove_file(path);
        }
    }
}

fn write_record(writer: &mut impl Write, id: &[u8]) -> io::Result<()> {
    writer.write_all(&(id.len() as u32).to_be_bytes())?;
    writer.write_all(id)
}

fn read_record(reader: &mut impl Read) -> io::Result<Option<Vec<u8>>> {
    let mut header = [0u8; 4];
    loop {
        match reader.read(&mut header[..1]) {
            Ok(0) => return Ok(None),
            Ok(_) => break,
            Err(error) if error.kind() == io::ErrorKind::Interrupted => continue,
            Err(error) => return Err(error),
        }
    }
    reader.read_exact(&mut header[1..])?;
    let length = u32::from_be_bytes(header) as usize;
    if length == 0 || length > MAX_ID_BYTES {
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "Invalid article identity record length",
        ));
    }
    let mut id = vec![0; length];
    reader.read_exact(&mut id)?;
    Ok(Some(id))
}

fn merge(mut left: Run, mut right: Run, directory: &Path) -> io::Result<Run> {
    left.file().seek(SeekFrom::Start(0))?;
    right.file().seek(SeekFrom::Start(0))?;
    let mut output = Run::create(directory)?;
    {
        let mut left = BufReader::new(left.file());
        let mut right = BufReader::new(right.file());
        let mut writer = BufWriter::new(output.file());
        let mut a = read_record(&mut left)?;
        let mut b = read_record(&mut right)?;
        let mut previous = None;
        while a.is_some() || b.is_some() {
            let next = if b.is_none() || (a.is_some() && a <= b) {
                let next = a.take().unwrap();
                a = read_record(&mut left)?;
                next
            } else {
                let next = b.take().unwrap();
                b = read_record(&mut right)?;
                next
            };
            if previous.as_ref() != Some(&next) {
                write_record(&mut writer, &next)?;
                previous = Some(next);
            }
        }
        writer.flush()?;
    }
    Ok(output)
}

#[cfg(test)]
mod tests {
    use super::*;

    struct Directory(PathBuf);

    impl Directory {
        fn new() -> Self {
            let sequence = NEXT_RUN.fetch_add(1, Ordering::Relaxed);
            let directory = std::env::temp_dir().join(format!(
                "nntmux-article-tests-{}-{sequence}",
                std::process::id()
            ));
            std::fs::create_dir(&directory).unwrap();
            Self(directory)
        }

        fn entries(&self) -> usize {
            std::fs::read_dir(&self.0).unwrap().count()
        }
    }

    impl Drop for Directory {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self.0);
        }
    }

    fn bytes(hex: &str) -> [u8; 40] {
        std::array::from_fn(|i| u8::from_str_radix(&hex[2 * i..2 * i + 2], 16).unwrap())
    }

    // Python hashlib independently generated these vectors from raw unique IDs.
    const TWO_IDS: &str =
        "00000000000000027cb8414b5de1fc619a1120f24493e67ebab04cf203f8a6f22212cd35c0034e47";
    const BYTE_ID: &str =
        "00000000000000011408ef3bfdb9ca55880ed6fd448e76712e4ed7df200099a7ceed78012782d7c8";
    const TEN_THOUSAND: &str =
        "0000000000002710ca4349ad87696b9ba28aa3c103516e88c10b2cca937a912ea08abdc9b7e53f2f";

    #[test]
    fn empty_has_no_identity() {
        assert_eq!(Accumulator::new().value(), None);
    }

    #[test]
    fn unique_ids_produce_persisted_big_endian_count_and_wrapping_lanes() {
        let mut acc = Accumulator::new();
        assert!(acc.add(b"b@host"));
        assert!(acc.add(b"a@host"));
        assert!(acc.add(b"b@host"));
        assert_eq!(acc.value(), Some(bytes(TWO_IDS)));
    }

    #[test]
    fn php_trim_quotes_and_angles_normalize_before_deduplication() {
        let mut acc = Accumulator::new();
        for id in [
            b" \t\r\n\0\x0b\" < a@host > \"\x0b".as_slice(),
            b"' <b@host> '",
            b"a@host",
            b"<b@host>",
        ] {
            assert!(acc.add(id));
        }
        assert_eq!(acc.value(), Some(bytes(TWO_IDS)));
    }

    #[test]
    fn non_utf8_bytes_remain_valid_identity_bytes() {
        let mut acc = Accumulator::new();
        assert!(acc.add(b"\xff\x80@host"));
        assert_eq!(acc.value(), Some(bytes(BYTE_ID)));
    }

    #[test]
    fn invalid_ids_discard_prior_value_and_failure_remains_sticky() {
        let mut invalid = vec![Vec::new(), b"<>".to_vec(), b"a b".to_vec(), vec![b'a'; 256]];
        for byte in 0..=32 {
            invalid.push(vec![b'a', byte, b'b']);
        }
        invalid.push(b"a\x7fb".to_vec());
        invalid.push(b"\x0ca".to_vec());
        for id in invalid {
            let mut acc = Accumulator::new();
            assert!(acc.add(b"a@host"));
            assert!(acc.value().is_some());
            assert!(!acc.add(&id), "accepted {id:?}");
            assert_eq!(acc.value(), None);
            assert!(!acc.add(b"b@host"));
            assert_eq!(acc.value(), None);
        }
    }

    #[test]
    fn value_can_be_read_repeatedly_then_extended_with_duplicates() {
        let mut acc = Accumulator::new();
        assert!(acc.add(b"a@host"));
        let initial = acc.value().unwrap();
        assert_eq!(acc.value(), Some(initial));
        assert!(acc.add(b"a@host"));
        assert_eq!(acc.value(), Some(initial));
        assert!(acc.add(b"b@host"));
        assert_eq!(acc.value(), Some(bytes(TWO_IDS)));
    }

    #[test]
    fn spilled_runs_deduplicate_across_levels_and_preserve_lanes() {
        let mut acc = Accumulator::new();
        for i in (0..10000).rev().chain(0..10000) {
            assert!(acc.add(format!("message-{i:05}@host").as_bytes()));
        }
        assert_eq!(acc.value(), Some(bytes(TEN_THOUSAND)));
    }

    #[test]
    fn explicit_invalidation_discards_cached_identity() {
        let mut acc = Accumulator::new();
        assert!(acc.add(b"a@host"));
        assert!(acc.value().is_some());
        acc.invalidate();
        assert_eq!(acc.value(), None);
        assert!(!acc.add(b"b@host"));
    }

    #[test]
    fn small_identity_needs_no_temporary_directory_and_remains_extendible() {
        let directory = Directory::new();
        let missing = directory.0.join("missing");
        let mut acc = Accumulator::in_directory(missing);
        assert!(acc.add(b"a@host"));
        let initial = acc.value().unwrap();
        assert!(acc.add(b"a@host"));
        assert_eq!(acc.value(), Some(initial));
        assert!(acc.add(b"b@host"));
        assert_eq!(acc.value(), Some(bytes(TWO_IDS)));
        assert_eq!(directory.entries(), 0);
    }

    #[test]
    fn unavailable_temporary_directory_fails_closed_when_spill_is_needed() {
        let directory = Directory::new();
        let missing = directory.0.join("missing");
        let mut acc = Accumulator::in_directory(missing);
        for _ in 0..4095 {
            assert!(acc.add(b"a@host"));
        }
        assert!(!acc.add(b"a@host"));
        assert_eq!(acc.value(), None);
    }

    #[test]
    fn in_memory_deduplication_reclaims_byte_budget_before_new_adds() {
        let directory = Directory::new();
        let mut acc = Accumulator::in_directory(directory.0.join("missing"));
        let id = [b'a'; MAX_ID_BYTES];
        for _ in 0..BUFFER_IDS - 1 {
            assert!(acc.add(&id));
        }
        let value = acc.value().unwrap();
        for _ in 0..100 {
            assert!(acc.add(&id));
        }
        assert_eq!(acc.value(), Some(value));
    }

    #[test]
    fn normalization_removes_exactly_one_quote_pair_and_one_angle_pair() {
        for (input, expected) in [
            (b"\"\"a\"\"".as_slice(), b"\"a\"".as_slice()),
            (b"<<a>>", b"<a>"),
            (b"' \"a\" '", b"\"a\""),
            (b"<a", b"<a"),
            (b"a>", b"a>"),
        ] {
            assert_eq!(normalize(input), expected);
        }
    }

    #[test]
    fn max_length_ids_spill_with_bounded_buffer_and_file_count() {
        let mut acc = Accumulator::new();
        for i in 0..25000 {
            let id = format!("{i:05}{}", "x".repeat(250));
            assert!(acc.add(id.as_bytes()));
            assert!(acc.buffer.len() < BUFFER_IDS);
            assert!(acc.buffer_bytes < BUFFER_BYTES);
            assert!(acc.runs.iter().flatten().count() <= MAX_RUN_LEVELS);
        }
        let value = acc.value().unwrap();
        assert_eq!(u64::from_be_bytes(value[..8].try_into().unwrap()), 25000);
        assert!(acc.buffer.is_empty());
        assert_eq!(acc.buffer_bytes, 0);
    }

    #[test]
    fn truncated_run_discards_cached_identity_and_closes_owned_files() {
        let directory = Directory::new();
        let mut acc = Accumulator::in_directory(directory.0.clone());
        for _ in 0..BUFFER_IDS {
            assert!(acc.add(b"a@host"));
        }
        assert!(acc.value().is_some());
        acc.runs[0].as_mut().unwrap().file().set_len(2).unwrap();
        assert!(acc.add(b"b@host"));
        assert_eq!(acc.value(), None);
        assert!(!acc.add(b"c@host"));
        assert!(acc.runs.iter().all(Option::is_none));
        assert_eq!(directory.entries(), 0);
    }

    #[test]
    fn creating_a_run_after_cached_value_failure_discards_all_partial_identity() {
        let directory = Directory::new();
        let mut acc = Accumulator::in_directory(directory.0.clone());
        assert!(acc.add(b"a@host"));
        assert!(acc.value().is_some());
        let unavailable = directory.0.join("regular-file");
        File::create(&unavailable).unwrap();
        acc.directory = unavailable.clone();
        for _ in 0..BUFFER_IDS - 2 {
            assert!(acc.add(b"b@host"));
        }
        assert!(!acc.add(b"b@host"));
        assert_eq!(acc.value(), None);
        assert!(acc.runs.iter().all(Option::is_none));
        std::fs::remove_file(unavailable).unwrap();
        assert_eq!(directory.entries(), 0);
    }

    #[test]
    fn malformed_records_fail_closed_without_unbounded_allocations() {
        for record in [
            b"\0\0\0\0".as_slice(),
            b"\0\0\x01\0",
            b"\0\0\0\x05ab",
            b"\0\0",
        ] {
            assert!(read_record(&mut std::io::Cursor::new(record)).is_err());
        }
        assert_eq!(read_record(&mut std::io::Cursor::new([])).unwrap(), None);
    }

    #[test]
    fn exhausting_run_levels_fails_closed_and_releases_every_run() {
        let directory = Directory::new();
        let mut acc = Accumulator::in_directory(directory.0.clone());
        for slot in &mut acc.runs {
            let mut run = Run::create(&directory.0).unwrap();
            write_record(run.file(), b"a@host").unwrap();
            *slot = Some(run);
        }
        for _ in 0..BUFFER_IDS - 1 {
            assert!(acc.add(b"b@host"));
        }
        assert!(!acc.add(b"b@host"));
        assert_eq!(acc.value(), None);
        assert!(acc.runs.iter().all(Option::is_none));
        assert_eq!(directory.entries(), 0);
    }

    #[cfg(unix)]
    #[test]
    fn unix_runs_are_private_and_unlinked_while_live() {
        use std::os::unix::fs::MetadataExt;
        let directory = Directory::new();
        let mut run = Run::create(&directory.0).unwrap();
        let metadata = run.file().metadata().unwrap();
        assert_eq!(metadata.mode() & 0o777, 0o600);
        assert_eq!(metadata.nlink(), 0);
        write_record(run.file(), b"a@host").unwrap();
        assert_eq!(directory.entries(), 0);
        drop(run);
        assert_eq!(directory.entries(), 0);
    }
}
