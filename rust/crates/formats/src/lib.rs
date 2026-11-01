//! RAR, 7z, ZIP and PAR2 parsing, article identity, and fingerprinting formats.
//!
//! These parsers read hostile bytes and report what they find instead of failing.
//! Use the bounded functions in this file: each one keeps the byte budget the parser
//! was qualified against, and returns `None` over budget or if the parser panics, so
//! one bad article can't take a worker down.
//!
//! Owner: ws-formats. See `rust/WORKSTREAMS.md`.

use std::panic::{UnwindSafe, catch_unwind};

pub mod article_dates;
pub mod article_identity;
pub mod article_xref;
pub mod header_filename_identity;
mod header_filename_unicode;
pub mod nntp_body;
pub mod nntp_headers;

const MAX_METADATA_INPUT_BYTES: usize = 67_108_864;
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
