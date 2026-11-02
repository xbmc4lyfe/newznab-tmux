//! `SevenZipListingParser`: reads `7z l -slt -ba` output as it streams, in chunks of any size.
//!
//! Lines end only at LF, with one optional CR before it, and records end at blank lines. Nothing
//! else breaks a line: the output is raw UTF-8, and a Unicode-aware line break would also split
//! the 0x85 byte inside characters like "Å" (C3 85).
//!
//! A record that can't be read unambiguously is left out and clears [`Parser::reliable`]: a line
//! that isn't `Key = value`, a property given twice, a Path with control characters, or no Path or
//! Size at all. Such output most likely comes from a file name with a line break in it, so the
//! record boundaries can't be trusted; callers keep the other entries but must not treat the
//! listing as complete.

use std::collections::HashMap;

use chrono::{NaiveDate, NaiveDateTime};

use crate::archive_info::{FileRecord, MAIN_SOURCE};

/// The parser's state between chunks.
#[derive(Debug)]
pub struct Parser {
    /// The files read so far, directories left out.
    pub files: Vec<FileRecord>,
    /// False once any record had to be rejected.
    pub reliable: bool,
    partial_line: Vec<u8>,
    record: HashMap<Vec<u8>, Vec<u8>>,
    record_malformed: bool,
}

impl Default for Parser {
    fn default() -> Self {
        Self {
            files: Vec::new(),
            reliable: true,
            partial_line: Vec::new(),
            record: HashMap::new(),
            record_malformed: false,
        }
    }
}

impl Parser {
    pub fn feed(&mut self, chunk: &[u8]) {
        let mut start = 0;
        while let Some(offset) = chunk[start..].iter().position(|byte| *byte == b'\n') {
            let mut line = std::mem::take(&mut self.partial_line);
            line.extend_from_slice(&chunk[start..start + offset]);
            self.line(&line);
            start += offset + 1;
        }
        self.partial_line.extend_from_slice(&chunk[start..]);
    }

    /// End of output: the last line and record count even without a trailing line break.
    pub fn finish(&mut self) {
        if !self.partial_line.is_empty() {
            let line = std::mem::take(&mut self.partial_line);
            self.line(&line);
        }
        self.end_record();
    }

    fn line(&mut self, line: &[u8]) {
        let line = line.strip_suffix(b"\r").unwrap_or(line);
        if line.iter().all(|byte| matches!(byte, b' ' | b'\t')) {
            self.end_record();
            return;
        }
        let (key, value) = if let Some(separator) = find(line, b" = ") {
            (&line[..separator], &line[separator + 3..])
        } else if let Some(key) = line.strip_suffix(b" =") {
            (key, &b""[..])
        } else {
            self.record_malformed = true;
            return;
        };
        if !valid_key(key) || self.record.contains_key(key) {
            self.record_malformed = true;
            return;
        }
        self.record.insert(key.to_vec(), value.to_vec());
    }

    fn end_record(&mut self) {
        let entry = std::mem::take(&mut self.record);
        let malformed = std::mem::take(&mut self.record_malformed);
        if entry.is_empty() && !malformed {
            return;
        }
        let field = |key: &[u8]| entry.get(key).map_or(&b""[..], Vec::as_slice);
        let path = field(b"Path");
        if malformed || path.is_empty() || !entry.contains_key(&b"Size"[..]) || has_control(path) {
            self.reliable = false;
            return;
        }
        if field(b"Folder") == b"+" || field(b"Attributes").starts_with(b"D") {
            return;
        }
        let crc = field(b"CRC");
        let crc32 = (!crc.is_empty() && crc.len() <= 8 && crc.iter().all(u8::is_ascii_hexdigit))
            .then(|| {
                let text = String::from_utf8_lossy(crc);
                format!("{:x}", u32::from_str_radix(&text, 16).unwrap_or(0))
            });
        self.files.push(FileRecord {
            name: path.to_vec(),
            size: php_int(field(b"Size")),
            date: modified(field(b"Modified")),
            pass: i64::from(field(b"Encrypted") == b"+"),
            compressed: Some(i64::from(!matches!(field(b"Method"), b"" | b"Copy"))),
            crc32,
            source: MAIN_SOURCE.to_owned(),
            ..FileRecord::default()
        });
    }
}

/// `^[A-Za-z][A-Za-z0-9 ()._-]*$`.
fn valid_key(key: &[u8]) -> bool {
    key.first().is_some_and(u8::is_ascii_alphabetic)
        && key[1..]
            .iter()
            .all(|byte| byte.is_ascii_alphanumeric() || b" ()._-".contains(byte))
}

/// C0 controls, DEL, and C1 controls in their UTF-8 form (C2 80 to C2 9F).
fn has_control(path: &[u8]) -> bool {
    path.iter().any(|byte| *byte < 0x20 || *byte == 0x7f)
        || path
            .windows(2)
            .any(|pair| pair[0] == 0xc2 && (0x80..=0x9f).contains(&pair[1]))
}

fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    haystack
        .windows(needle.len())
        .position(|window| window == needle)
}

/// PHP's `(int)` of a numeric-leading string.
fn php_int(value: &[u8]) -> i64 {
    let start = value
        .iter()
        .take_while(|byte| matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c))
        .count();
    let value = &value[start..];
    let (negative, digits) = match value.first() {
        Some(b'-') => (true, &value[1..]),
        Some(b'+') => (false, &value[1..]),
        _ => (false, value),
    };
    let end = digits
        .iter()
        .take_while(|byte| byte.is_ascii_digit())
        .count();
    let magnitude = std::str::from_utf8(&digits[..end])
        .ok()
        .and_then(|text| text.parse::<i64>().ok())
        .unwrap_or(if end == 0 { 0 } else { i64::MAX });
    if negative { -magnitude } else { magnitude }
}

/// `strtotime(substr($modified, 0, 19))` in UTC, for the forms 7-Zip writes; 0 for anything else,
/// as `(int) false`.
fn modified(value: &[u8]) -> i64 {
    let value = &value[..value.len().min(19)];
    let Ok(text) = std::str::from_utf8(value) else {
        return 0;
    };
    if let Ok(when) = NaiveDateTime::parse_from_str(text, "%Y-%m-%d %H:%M:%S") {
        return when.and_utc().timestamp();
    }
    if let Ok(when) = NaiveDateTime::parse_from_str(text, "%Y-%m-%d %H:%M") {
        return when.and_utc().timestamp();
    }
    NaiveDate::parse_from_str(text, "%Y-%m-%d")
        .ok()
        .and_then(|day| day.and_hms_opt(0, 0, 0))
        .map_or(0, |when| when.and_utc().timestamp())
}

/// `ArchiveExtractionService::parseSevenZipListing`: a whole listing at once, and whether every
/// record in it was understood.
pub fn parse(output: &[u8]) -> (Vec<FileRecord>, bool) {
    let mut parser = Parser::default();
    parser.feed(output);
    parser.finish();
    (parser.files, parser.reliable)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn chunks_split_anywhere_read_the_same() {
        let output = "Path = a/b.mkv\r\nSize = 12\nMethod = Copy\nCRC = 0000ABCD\n\nPath = c\nSize = 1\nMethod = LZMA2\n";
        let (whole, reliable) = parse(output.as_bytes());
        assert!(reliable);
        for cut in 0..output.len() {
            let mut parser = Parser::default();
            parser.feed(&output.as_bytes()[..cut]);
            parser.feed(&output.as_bytes()[cut..]);
            parser.finish();
            assert_eq!(parser.files, whole, "cut at {cut}");
        }
        assert_eq!(whole[0].crc32.as_deref(), Some("abcd"));
        assert_eq!(whole[0].compressed, Some(0));
        assert_eq!(whole[1].compressed, Some(1));
    }

    #[test]
    fn a_c1_byte_inside_a_character_is_not_a_line_break() {
        let (files, reliable) = parse("Path = \u{c5}\u{4e45}.mkv\nSize = 3\n".as_bytes());
        assert!(reliable);
        assert_eq!(files[0].name, "\u{c5}\u{4e45}.mkv".as_bytes());
    }
}
