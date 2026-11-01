//! RAR, 7z, ZIP and PAR2 parsing, article identity, and fingerprinting formats.
//!
//! These parsers read hostile bytes and report what they find instead of failing.
//! Use the bounded functions in this file: each one keeps the byte budget the parser
//! was qualified against, and returns `None` over budget or if the parser panics, so
//! one bad article can't take a worker down.
//!
//! Owner: ws-formats. See `rust/WORKSTREAMS.md`.

use std::panic::{UnwindSafe, catch_unwind};

pub mod archive;
pub mod article_dates;
pub mod article_identity;
pub mod article_xref;
pub mod header_filename_identity;
mod header_filename_unicode;
pub mod nntp_body;
pub mod nntp_headers;
pub mod par2;
pub mod par2_source_ranges;
pub mod seven_zip_ranges;

const MAX_METADATA_INPUT_BYTES: usize = 67_108_864;
const MAX_SEVEN_ZIP_RANGE_INPUT_BYTES: usize = 16 * 1024 * 1024 + 4096;
const MAX_PAR2_SOURCE_RANGE_INPUT_BYTES: usize = 16 * 1024 * 1024 + 16 * 1024;
const MAX_ARTICLE_DATES_INPUT_BYTES: usize = 65_536;
const MAX_ARTICLE_IDENTITY_INPUT_BYTES: usize = 131_072;
const MAX_ARTICLE_XREF_INPUT_BYTES: usize = 196_608;
const MAX_NNTP_HEADER_INPUT_BYTES: usize = 131_072;
const MAX_LINES_BODY_BYTES: usize = 2_000_000;
const MAX_HEADER_FILENAME_INPUT_BYTES: usize = 4_096;

const ABSENT_FIELD: [u8; 4] = [0xff; 4];

fn guarded<T>(parse: impl FnOnce() -> T + UnwindSafe) -> Option<T> {
    catch_unwind(parse).ok()
}

fn pack_field(packed: &mut Vec<u8>, value: &[u8]) -> Option<()> {
    packed.extend_from_slice(&u32::try_from(value.len()).ok()?.to_le_bytes());
    packed.extend_from_slice(value);
    Some(())
}

fn pack_optional(packed: &mut Vec<u8>, value: Option<&[u8]>) -> Option<()> {
    match value {
        Some(value) => pack_field(packed, value),
        None => {
            packed.extend_from_slice(&ABSENT_FIELD);
            Some(())
        }
    }
}

fn pack_lines(lines: &[&[u8]]) -> Option<Vec<u8>> {
    let mut packed = u32::try_from(lines.len()).ok()?.to_le_bytes().to_vec();
    for line in lines {
        pack_field(&mut packed, line)?;
    }
    Some(packed)
}

/// What one article's protocol exchange returned. A response code of `None` means
/// that command wasn't answered.
#[derive(Debug, Clone, Copy, Default)]
pub struct ArticleIdentityInput<'a> {
    pub requested_message_id: &'a [u8],
    /// Raw header lines, joined.
    pub headers: &'a [u8],
    pub overview_row: Option<&'a [u8]>,
    pub overview_response_code: Option<i32>,
    pub overview_attempted: bool,
    pub head_response_text: Option<&'a [u8]>,
    pub body_response_text: Option<&'a [u8]>,
    pub head_response_code: Option<i32>,
    pub body_response_code: Option<i32>,
}

pub fn article_identity(
    input: &ArticleIdentityInput<'_>,
) -> Option<article_identity::ArticleIdentity> {
    let mut packed = b"AI02".to_vec();
    pack_field(&mut packed, input.requested_message_id)?;
    pack_field(&mut packed, input.headers)?;
    pack_optional(&mut packed, input.overview_row)?;
    pack_optional(&mut packed, input.head_response_text)?;
    pack_optional(&mut packed, input.body_response_text)?;
    if packed.len() > MAX_ARTICLE_IDENTITY_INPUT_BYTES {
        return None;
    }
    let input = *input;
    guarded(move || {
        article_identity::parse(
            &packed,
            input.overview_response_code.unwrap_or(-1),
            input.overview_attempted,
            input.head_response_code.unwrap_or(-1),
            input.body_response_code.unwrap_or(-1),
        )
    })
}

#[derive(Debug, Clone, Copy, Default)]
pub struct ArticleXrefInput<'a> {
    /// Raw header lines, joined.
    pub headers: &'a [u8],
    pub overview_rows: &'a [&'a [u8]],
    pub overview_format: &'a [&'a [u8]],
    pub head_response_code: Option<i32>,
    pub overview_response_code: Option<i32>,
    pub overview_attempted: bool,
    pub overview_row_count: i32,
    pub overview_format_response_code: Option<i32>,
}

pub fn article_xref(input: &ArticleXrefInput<'_>) -> Option<article_xref::ArticleXref> {
    let mut packed = b"XR01".to_vec();
    pack_field(&mut packed, input.headers)?;
    pack_field(&mut packed, &pack_lines(input.overview_rows)?)?;
    pack_field(&mut packed, &pack_lines(input.overview_format)?)?;
    if packed.len() > MAX_ARTICLE_XREF_INPUT_BYTES {
        return None;
    }
    let (head, overview, attempted, rows, format) = (
        input.head_response_code.unwrap_or(-1),
        input.overview_response_code.unwrap_or(-1),
        input.overview_attempted,
        input.overview_row_count,
        input.overview_format_response_code.unwrap_or(-1),
    );
    guarded(move || article_xref::parse(&packed, head, overview, attempted, rows, format))
}

pub fn article_dates(headers: &[u8]) -> Option<article_dates::ArticleDates> {
    if headers.len() > MAX_ARTICLE_DATES_INPUT_BYTES {
        return None;
    }
    guarded(|| article_dates::parse(headers))
}

pub fn nntp_header_metadata(headers: &[u8]) -> Option<nntp_headers::HeaderMetadata> {
    if headers.len() > MAX_NNTP_HEADER_INPUT_BYTES {
        return None;
    }
    guarded(|| nntp_headers::parse(headers))
}

pub fn nntp_header_comparison(
    left: &[u8],
    right: &[u8],
    identity_match: Option<bool>,
) -> Option<nntp_headers::HeaderPairComparison> {
    if left.len().checked_add(right.len())? > MAX_NNTP_HEADER_INPUT_BYTES * 2 {
        return None;
    }
    guarded(|| nntp_headers::compare(left, right, identity_match))
}

pub fn nntp_body_measurement(body: &[u8]) -> Option<nntp_body::NntpBodyMetadata> {
    if body.len() > MAX_METADATA_INPUT_BYTES {
        return None;
    }
    guarded(|| nntp_body::parse(body))
}

pub fn nntp_lines_body_relationship(
    headers: &[u8],
    body: &[u8],
) -> Option<nntp_body::LinesBodyRelationship> {
    if headers.len() > MAX_NNTP_HEADER_INPUT_BYTES || body.len() > MAX_LINES_BODY_BYTES {
        return None;
    }
    guarded(|| nntp_body::compare_lines_to_body(headers, body))
}

/// Whether an article's yEnc filename agrees with the one its subject declares.
/// `None` means an input over the budget or a parser failure, not a verdict.
pub fn header_filename_matches(
    subject: &[u8],
    actual: &[u8],
    is_par2_packet: bool,
) -> Option<bool> {
    if subject.len() > MAX_HEADER_FILENAME_INPUT_BYTES
        || actual.len() > MAX_HEADER_FILENAME_INPUT_BYTES
    {
        return None;
    }
    guarded(|| header_filename_identity::matches(subject, actual, is_par2_packet))
}

/// Identifies an archive from its first bytes and reports what its headers declare.
pub fn archive_metadata(bytes: &[u8]) -> Option<archive::FormatMetadata> {
    if bytes.len() > MAX_METADATA_INPUT_BYTES {
        return None;
    }
    guarded(|| archive::parse(bytes))
}

pub fn par2_metadata(bytes: &[u8]) -> Option<par2::Par2Metadata> {
    if bytes.len() > MAX_METADATA_INPUT_BYTES {
        return None;
    }
    guarded(|| par2::parse(bytes))
}

/// Bytes observed at one offset of a file that was only partly downloaded.
#[derive(Debug, Clone, Copy)]
pub struct RangeFragment<'a> {
    pub file_offset: u64,
    pub bytes: &'a [u8],
}

/// Resolves a 7z next header from fragments. `None` also covers the malformed
/// inputs the parser refuses, such as no fragments at all.
pub fn seven_zip_ranges(
    declared_file_size: u64,
    fragments: &[RangeFragment<'_>],
) -> Option<seven_zip_ranges::SevenZipNextHeaderResolution> {
    let mut packed = u32::try_from(fragments.len()).ok()?.to_le_bytes().to_vec();
    packed.extend_from_slice(&declared_file_size.to_le_bytes());
    pack_fragments(&mut packed, fragments)?;
    if packed.len() > MAX_SEVEN_ZIP_RANGE_INPUT_BYTES {
        return None;
    }
    guarded(move || seven_zip_ranges::parse(&packed)).flatten()
}

/// One entry of a PAR2 input file slice checksum packet, placed in its source file.
#[derive(Debug, Clone, Copy)]
pub struct Par2SliceChecksum {
    pub slice_index: u32,
    pub source_byte_start: u64,
    pub source_byte_end_exclusive: u64,
    pub zero_padding_bytes: u64,
    pub md5: [u8; 16],
    pub crc32: u32,
}

/// Checks observed source fragments against PAR2 slice checksums.
pub fn par2_source_ranges(
    file_length: u64,
    fragments: &[RangeFragment<'_>],
    slices: &[Par2SliceChecksum],
) -> Option<par2_source_ranges::SourceSliceResolution> {
    let mut packed = b"P2SR".to_vec();
    packed.extend_from_slice(&u32::try_from(fragments.len()).ok()?.to_le_bytes());
    packed.extend_from_slice(&u32::try_from(slices.len()).ok()?.to_le_bytes());
    packed.extend_from_slice(&file_length.to_le_bytes());
    pack_fragments(&mut packed, fragments)?;
    for slice in slices {
        packed.extend_from_slice(&slice.slice_index.to_le_bytes());
        packed.extend_from_slice(&slice.source_byte_start.to_le_bytes());
        packed.extend_from_slice(&slice.source_byte_end_exclusive.to_le_bytes());
        packed.extend_from_slice(&slice.zero_padding_bytes.to_le_bytes());
        packed.extend_from_slice(&slice.md5);
        packed.extend_from_slice(&slice.crc32.to_le_bytes());
    }
    if packed.len() > MAX_PAR2_SOURCE_RANGE_INPUT_BYTES {
        return None;
    }
    guarded(move || par2_source_ranges::parse(&packed)).flatten()
}

fn pack_fragments(packed: &mut Vec<u8>, fragments: &[RangeFragment<'_>]) -> Option<()> {
    for fragment in fragments {
        packed.extend_from_slice(&fragment.file_offset.to_le_bytes());
        pack_field(packed, fragment.bytes)?;
    }
    Some(())
}
