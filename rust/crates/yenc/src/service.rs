//! Article-level decoding, encoding and metadata.

use crate::decoder;
use crate::frame::{ArticleFrame, Fields};
use std::borrow::Cow;

#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum YencError {
    #[error("Unmatched yEnc escape marker. The file is probably corrupt.")]
    UnmatchedEscape,
    #[error("Invalid or missing yEnc {0}.")]
    InvalidNumber(&'static str),
    #[error("Missing yEnc multipart range.")]
    MissingRange,
    #[error("Invalid yEnc multipart range or part number.")]
    InvalidRange,
    #[error("Missing yEnc part checksum.")]
    MissingPartChecksum,
    #[error("Declared and decoded yEnc sizes do not match. The file is probably corrupt.")]
    SizeMismatch,
    #[error("CRC32 checksums do not match. The file is probably corrupt.")]
    CrcMismatch,
    #[error("{0} is not a valid line length.")]
    InvalidLineLength(i64),
    #[error("A yEnc filename cannot contain line endings.")]
    FilenameLineEnding,
}

/// The file an article belongs to, as its yEnc header describes it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct YencPart {
    pub name: Vec<u8>,
    /// Size of the whole file, or 0 when the header's value isn't a usable number.
    pub size: i64,
    pub part: Option<i64>,
    /// 1-based offset of this part's first byte in the file.
    pub begin: Option<i64>,
    /// 1-based offset of this part's last byte in the file.
    pub end: Option<i64>,
}

impl YencPart {
    /// 0-based offset of this article's data in the file.
    pub fn offset(&self) -> i64 {
        self.begin.map_or(0, |begin| begin - 1)
    }

    /// Bytes this article carries, or `None` when its header doesn't say.
    pub fn length(&self) -> Option<i64> {
        Some(self.end? - self.begin? + 1)
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct YencMetadata {
    pub name: Vec<u8>,
    pub size: Option<i64>,
    pub line: Option<i64>,
    /// Uppercase, as the trailer spelled it.
    pub crc32: Option<String>,
}

/// Decode and verify an article. `Ok(None)` means the text holds no yEnc frame.
pub fn decode(text: &[u8]) -> Result<Option<Vec<u8>>, YencError> {
    let Some(frame) = ArticleFrame::parse(text, true) else {
        return Ok(None);
    };
    let payload = frame.payload(text);
    if has_dangling_escape(payload) {
        return Err(YencError::UnmatchedEscape);
    }
    let decoded = decoder::decode(payload);
    let mut size = number(&frame.header, "size")?;
    let trailer_size = number(&frame.trailer, "size")?;
    let mut checksum = frame.trailer.get("crc32");
    if frame.is_multipart() {
        let range = frame.part.as_ref().ok_or(YencError::MissingRange)?;
        let part = number(&frame.header, "part")?;
        let begin = number(range, "begin")?;
        let end = number(range, "end")?;
        let invalid = part < 1
            || (frame.trailer.contains("part") && part != number(&frame.trailer, "part")?)
            || begin < 1
            || end < begin
            || end > size
            || (frame.header.contains("total") && part > number(&frame.header, "total")?)
            || (frame.trailer.contains("total")
                && number(&frame.trailer, "total")? != number(&frame.header, "total")?);
        if invalid {
            return Err(YencError::InvalidRange);
        }
        size = end - begin + 1;
        checksum = Some(
            frame
                .trailer
                .get("pcrc32")
                .ok_or(YencError::MissingPartChecksum)?,
        );
    }
    if size != trailer_size || usize::try_from(size) != Ok(decoded.len()) {
        return Err(YencError::SizeMismatch);
    }
    if let Some(checksum) = checksum
        && parse_checksum(checksum) != Some(crc32fast::hash(&decoded))
    {
        return Err(YencError::CrcMismatch);
    }
    Ok(Some(decoded))
}

/// Decode without checking sizes or checksums. Text with no frame comes back unchanged.
pub fn decode_lenient(text: &[u8]) -> Cow<'_, [u8]> {
    match ArticleFrame::parse(text, false) {
        Some(frame) => Cow::Owned(decode_payload_lenient(frame.payload(text))),
        None => Cow::Borrowed(text),
    }
}

/// [`decode_lenient`], plus what the yEnc header says about the file.
pub fn decode_with_frame(text: &[u8]) -> (Cow<'_, [u8]>, Option<YencPart>) {
    let Some(frame) = ArticleFrame::parse(text, false) else {
        return (Cow::Borrowed(text), None);
    };
    let decoded = decode_payload_lenient(frame.payload(text));
    let range = frame.part.as_ref();
    let part = YencPart {
        name: trim(frame.header.get("name").unwrap_or_default()).to_vec(),
        size: field_number(&frame.header, "size").unwrap_or(0),
        part: field_number(&frame.header, "part"),
        begin: range.and_then(|fields| field_number(fields, "begin")),
        end: range.and_then(|fields| field_number(fields, "end")),
    };
    (Cow::Owned(decoded), Some(part))
}

/// Whether an article carries the checksum its framing calls for, and strict
/// decoding agrees with `decoded`. Decides if a lenient result may be reused.
pub fn has_validated_integrity(text: &[u8], decoded: &[u8]) -> bool {
    let Some(frame) = ArticleFrame::parse(text, true) else {
        return false;
    };
    let checksum = if frame.is_multipart() {
        "pcrc32"
    } else {
        "crc32"
    };
    frame.trailer.contains(checksum)
        && matches!(decode(text), Ok(Some(strict)) if strict == decoded)
}

pub fn is_yenc_encoded(text: &[u8]) -> bool {
    ArticleFrame::parse(text, true).is_some()
}

/// Encode one complete single-part article. `line_length` above 254 is capped.
pub fn encode(
    data: &[u8],
    filename: &[u8],
    line_length: i64,
    include_crc32: bool,
) -> Result<Vec<u8>, YencError> {
    let line_length = line_length.min(254);
    if line_length < 1 {
        return Err(YencError::InvalidLineLength(line_length));
    }
    if filename.iter().any(|byte| matches!(byte, b'\r' | b'\n')) {
        return Err(YencError::FilenameLineEnding);
    }
    let mut result = format!("=ybegin line={line_length} size={} name=", data.len()).into_bytes();
    result.reserve(data.len() + data.len() / 32 + 64);
    result.extend_from_slice(trim(filename));
    result.extend_from_slice(b"\r\n");
    let mut column = 0;
    for byte in data {
        let encoded = byte.wrapping_add(42);
        if column >= line_length {
            result.extend_from_slice(b"\r\n");
            column = 0;
        }
        if matches!(encoded, 0 | 9 | 10 | 13 | 32 | 46 | 61) {
            result.push(b'=');
            result.push(encoded.wrapping_add(64));
            column += 2;
        } else {
            result.push(encoded);
            column += 1;
        }
    }
    result.extend_from_slice(format!("\r\n=yend size={}", data.len()).as_bytes());
    if include_crc32 {
        result.extend_from_slice(format!(" crc32={}", crc32(data)).as_bytes());
    }
    Ok(result)
}

pub fn extract_metadata(text: &[u8]) -> Option<YencMetadata> {
    let frame = ArticleFrame::parse(text, true)?;
    Some(YencMetadata {
        name: frame.header.get("name").unwrap_or_default().to_vec(),
        size: field_number(&frame.header, "size"),
        line: field_number(&frame.header, "line"),
        crc32: frame
            .trailer
            .get("crc32")
            .map(|value| String::from_utf8_lossy(value).to_ascii_uppercase()),
    })
}

/// Lowercase eight-character hex CRC-32 of `data`.
pub fn crc32(data: &[u8]) -> String {
    format!("{:08x}", crc32fast::hash(data))
}

/// An odd run of final `=` markers leaves the last one with no value byte. Lenient
/// decoding drops that marker instead of treating it as data, as the kernels do.
fn decode_payload_lenient(payload: &[u8]) -> Vec<u8> {
    if has_dangling_escape(payload)
        && let Some(marker) = payload.iter().rposition(|byte| *byte == b'=')
    {
        return decoder::decode(&payload[..marker]);
    }
    decoder::decode(payload)
}

fn has_dangling_escape(payload: &[u8]) -> bool {
    let mut count = 0usize;
    for byte in payload.iter().rev() {
        match byte {
            b'=' => count += 1,
            b'\r' | b'\n' => {}
            _ => break,
        }
    }
    count % 2 == 1
}

/// One to eight hex digits, in any case, compared with leading zeros restored.
fn parse_checksum(value: &[u8]) -> Option<u32> {
    if value.is_empty() || value.len() > 8 || !value.iter().all(u8::is_ascii_hexdigit) {
        return None;
    }
    u32::from_str_radix(std::str::from_utf8(value).ok()?, 16).ok()
}

fn number(fields: &Fields, key: &'static str) -> Result<i64, YencError> {
    field_number(fields, key).ok_or(YencError::InvalidNumber(key))
}

fn field_number(fields: &Fields, key: &str) -> Option<i64> {
    metadata_number(fields.get(key)?)
}

/// Digits only, with leading zeros allowed, and no larger than `i64::MAX`.
fn metadata_number(value: &[u8]) -> Option<i64> {
    if value.is_empty() || !value.iter().all(u8::is_ascii_digit) {
        return None;
    }
    let mut total = 0i64;
    for digit in value {
        total = total
            .checked_mul(10)?
            .checked_add(i64::from(digit - b'0'))?;
    }
    Some(total)
}

/// PHP's default `trim` set, which real filenames depend on: NUL and VT count as blanks.
fn trim(value: &[u8]) -> &[u8] {
    let blank = |byte: &u8| matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b);
    let start = value
        .iter()
        .position(|byte| !blank(byte))
        .unwrap_or(value.len());
    let end = value
        .iter()
        .rposition(|byte| !blank(byte))
        .map_or(start, |last| last + 1);
    &value[start..end]
}
