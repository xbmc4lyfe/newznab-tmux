use serde::Serialize;

const MAGIC: &[u8; 4] = b"AI02";
const MAX_REQUEST_BYTES: usize = 8_192;
const MAX_HEADER_BYTES: usize = 65_536;
const MAX_OVERVIEW_BYTES: usize = 32_768;
const MAX_RESPONSE_TEXT_BYTES: usize = 4_096;
const MAX_RESPONSE_COMMENT_PREVIEW_BYTES: usize = 256;
const MAX_OCCURRENCES: usize = 4_096;
const MAX_MESSAGE_ID_CANDIDATE_BYTES: usize = 250;
const MAX_ARTICLE_NUMBER_DIGITS: usize = 16;

#[derive(Debug, Serialize)]
pub struct ArticleIdentity {
    pub parser_engine: &'static str,
    pub schema_version: u8,
    pub comparison_basis: &'static str,
    pub candidate_comparison_basis: &'static str,
    pub request: IdentityValue,
    pub head_response: ProtocolResponseIdentity,
    pub body_response: ProtocolResponseIdentity,
    pub head_message_id: IdentitySource,
    pub overview_message_id: IdentitySource,
    pub overview_attempted: bool,
    pub overview_row_structure_status: &'static str,
    pub overview_article_number: Option<String>,
    pub relations: IdentityRelations,
    pub overview_response_code: Option<i32>,
    pub interpretation: &'static str,
}

#[derive(Debug, Serialize)]
pub struct IdentityValue {
    pub raw_hex: String,
    pub token: Option<String>,
    pub candidate_token_hex: Option<String>,
    pub candidate_token_status: &'static str,
    pub syntax_status: &'static str,
}

#[derive(Debug, Serialize)]
pub struct IdentitySource {
    pub availability: &'static str,
    pub parse_status: &'static str,
    pub occurrence_count: usize,
    pub candidates: Vec<IdentityOccurrence>,
    pub matches_request: Option<bool>,
}

#[derive(Debug, Serialize)]
pub struct IdentityOccurrence {
    pub source_line_indices: Vec<usize>,
    pub source_overview_row_index: Option<usize>,
    pub source_overview_column_index: Option<usize>,
    pub unfolded_value_hex: String,
    pub token: Option<String>,
    pub token_hex: Option<String>,
    pub candidate_token_hex: Option<String>,
    pub candidate_token_status: &'static str,
    pub candidate_token_basis: &'static str,
    pub syntax_status: &'static str,
}

#[derive(Debug, Serialize)]
pub struct IdentityRelations {
    pub head_response_matches_request: Option<bool>,
    pub body_response_matches_request: Option<bool>,
    pub head_response_matches_body_response: Option<bool>,
    pub head_response_matches_head_field: Option<bool>,
    pub body_response_matches_head_field: Option<bool>,
    pub head_response_matches_overview: Option<bool>,
    pub body_response_matches_overview: Option<bool>,
    pub head_matches_overview: Option<bool>,
    pub head_candidate_matches_request: Option<bool>,
    pub overview_candidate_matches_request: Option<bool>,
    pub head_candidate_matches_overview: Option<bool>,
    pub head_response_matches_head_candidate: Option<bool>,
    pub body_response_matches_head_candidate: Option<bool>,
    pub head_response_matches_overview_candidate: Option<bool>,
    pub body_response_matches_overview_candidate: Option<bool>,
}

#[derive(Debug, Serialize)]
pub struct ProtocolResponseIdentity {
    pub response_code: Option<i32>,
    pub availability: &'static str,
    pub response_text_hex: String,
    pub response_text_basis: &'static str,
    pub article_number: Option<String>,
    pub message_id: Option<String>,
    pub message_id_hex: Option<String>,
    pub trailing_comment_status: &'static str,
    pub trailing_comment_bytes: Option<usize>,
    pub trailing_comment_hex: Option<String>,
    pub trailing_comment_hex_truncated: bool,
    pub parse_status: &'static str,
}

struct PackedFields<'a> {
    request: &'a [u8],
    headers: &'a [u8],
    overview: Option<&'a [u8]>,
    head_response: Option<&'a [u8]>,
    body_response: Option<&'a [u8]>,
}

pub fn parse(
    packed: &[u8],
    overview_response_code: i32,
    overview_attempted: bool,
    head_response_code: i32,
    body_response_code: i32,
) -> ArticleIdentity {
    let Some(fields) = unpack(packed) else {
        return invalid_input(
            overview_response_code,
            overview_attempted,
            head_response_code,
            body_response_code,
        );
    };

    let request_status = if fields.request.len() > MAX_REQUEST_BYTES {
        "budget_exceeded"
    } else {
        protocol_message_id_status(fields.request)
    };
    let (request_candidate_token_hex, request_candidate_token_status) =
        candidate_token(fields.request);
    let request = IdentityValue {
        raw_hex: hex(fields.request),
        token: (request_status == "valid")
            .then(|| String::from_utf8(fields.request.to_vec()).ok())
            .flatten(),
        candidate_token_hex: request_candidate_token_hex.clone(),
        candidate_token_status: request_candidate_token_status,
        syntax_status: request_status,
    };
    let head_response = parse_protocol_response(fields.head_response, head_response_code, 221);
    let body_response = parse_protocol_response(fields.body_response, body_response_code, 222);

    let head_candidates = parse_header_message_ids(fields.headers);
    let head_status =
        if fields.headers.len() > MAX_HEADER_BYTES || head_candidates.len() == MAX_OCCURRENCES {
            "budget_exceeded"
        } else {
            source_parse_status(&head_candidates)
        };
    let head_available = fields.headers.len() <= MAX_HEADER_BYTES;
    let head_matches_request = unique_valid_token(&head_candidates)
        .zip((request_status == "valid").then_some(fields.request))
        .map(|(candidate, request)| candidate == hex(request));
    let head_candidate_matches_request = relation(
        unique_candidate_token(&head_candidates),
        request_candidate_token_hex.as_deref(),
        fields.headers.len() <= MAX_HEADER_BYTES && request_candidate_token_hex.is_some(),
    );
    let head_message_id = IdentitySource {
        availability: if head_available {
            "available"
        } else {
            "budget_exceeded"
        },
        parse_status: head_status,
        occurrence_count: head_candidates.len(),
        candidates: head_candidates,
        matches_request: head_matches_request,
    };

    let overview_availability =
        overview_availability(fields.overview, overview_response_code, overview_attempted);
    let (overview_candidates, overview_row_structure_status, overview_article_number) =
        if overview_availability == "available" {
            parse_overview_message_id(fields.overview.expect("available OVER row"))
        } else {
            (Vec::new(), overview_availability, None)
        };
    let overview_status =
        if overview_availability == "available" && overview_row_structure_status != "valid" {
            overview_row_structure_status
        } else if overview_availability == "available" {
            source_parse_status(&overview_candidates)
        } else {
            overview_availability
        };
    let overview_matches_request = (overview_row_structure_status == "valid")
        .then(|| unique_valid_token(&overview_candidates))
        .flatten()
        .zip((request_status == "valid").then_some(fields.request))
        .map(|(candidate, request)| candidate == hex(request));
    let overview_candidate_matches_request = relation(
        unique_candidate_token(&overview_candidates),
        request_candidate_token_hex.as_deref(),
        overview_availability == "available" && request_candidate_token_hex.is_some(),
    );
    let overview_message_id = IdentitySource {
        availability: overview_availability,
        parse_status: overview_status,
        occurrence_count: overview_candidates.len(),
        candidates: overview_candidates,
        matches_request: overview_matches_request,
    };

    let head_overview_match = (overview_availability == "available"
        && overview_row_structure_status == "valid")
        .then(|| unique_valid_token(&head_message_id.candidates))
        .flatten()
        .zip(unique_valid_token(&overview_message_id.candidates))
        .map(|(head, overview)| head == overview);
    let head_candidate_matches_overview = relation(
        unique_candidate_token(&head_message_id.candidates),
        unique_candidate_token(&overview_message_id.candidates),
        head_available && overview_availability == "available",
    );

    let request_token_hex = (request_status == "valid").then(|| hex(fields.request));
    let head_response_matches_request = relation(
        head_response.message_id_hex.as_deref(),
        request_token_hex.as_deref(),
        head_response.availability == "available",
    );
    let body_response_matches_request = relation(
        body_response.message_id_hex.as_deref(),
        request_token_hex.as_deref(),
        body_response.availability == "available",
    );
    let head_response_matches_head_field = relation(
        head_response.message_id_hex.as_deref(),
        unique_valid_token(&head_message_id.candidates),
        head_response.availability == "available" && head_message_id.availability == "available",
    );
    let body_response_matches_head_field = relation(
        body_response.message_id_hex.as_deref(),
        unique_valid_token(&head_message_id.candidates),
        body_response.availability == "available" && head_message_id.availability == "available",
    );
    let overview_token = (overview_availability == "available"
        && overview_row_structure_status == "valid")
        .then(|| unique_valid_token(&overview_message_id.candidates))
        .flatten();
    let head_response_matches_body_response = relation(
        head_response.message_id_hex.as_deref(),
        body_response.message_id_hex.as_deref(),
        head_response.availability == "available" && body_response.availability == "available",
    );
    let head_response_matches_overview = relation(
        head_response.message_id_hex.as_deref(),
        overview_token,
        head_response.availability == "available" && overview_token.is_some(),
    );
    let body_response_matches_overview = relation(
        body_response.message_id_hex.as_deref(),
        overview_token,
        body_response.availability == "available" && overview_token.is_some(),
    );
    let head_response_matches_head_candidate = relation(
        head_response.message_id_hex.as_deref(),
        unique_candidate_token(&head_message_id.candidates),
        head_response.availability == "available" && head_available,
    );
    let body_response_matches_head_candidate = relation(
        body_response.message_id_hex.as_deref(),
        unique_candidate_token(&head_message_id.candidates),
        body_response.availability == "available" && head_available,
    );
    let head_response_matches_overview_candidate = relation(
        head_response.message_id_hex.as_deref(),
        unique_candidate_token(&overview_message_id.candidates),
        head_response.availability == "available" && overview_availability == "available",
    );
    let body_response_matches_overview_candidate = relation(
        body_response.message_id_hex.as_deref(),
        unique_candidate_token(&overview_message_id.candidates),
        body_response.availability == "available" && overview_availability == "available",
    );

    ArticleIdentity {
        parser_engine: "nntmux-rust-article-identity",
        schema_version: 3,
        comparison_basis: "exact_message_id_octets_case_sensitive",
        candidate_comparison_basis: "exact_delimited_message_id_octets_case_sensitive_candidate_only",
        request,
        head_response,
        body_response,
        head_message_id,
        overview_message_id,
        overview_attempted,
        overview_row_structure_status,
        overview_article_number,
        relations: IdentityRelations {
            head_response_matches_request,
            body_response_matches_request,
            head_response_matches_body_response,
            head_response_matches_head_field,
            body_response_matches_head_field,
            head_response_matches_overview,
            body_response_matches_overview,
            head_matches_overview: head_overview_match,
            head_candidate_matches_request,
            overview_candidate_matches_request,
            head_candidate_matches_overview,
            head_response_matches_head_candidate,
            body_response_matches_head_candidate,
            head_response_matches_overview_candidate,
            body_response_matches_overview_candidate,
        },
        overview_response_code: (overview_response_code >= 0).then_some(overview_response_code),
        interpretation: "mismatches are descriptive only; RFC 3977 permits the lookup Message-ID to differ from the article Message-ID header",
    }
}

fn unpack(data: &[u8]) -> Option<PackedFields<'_>> {
    if data.len() > 131_072 || data.get(..4)? != MAGIC {
        return None;
    }
    let mut cursor = 4usize;
    let request = next_field(data, &mut cursor)?;
    let headers = next_field(data, &mut cursor)?;
    let overview = next_field(data, &mut cursor)?;
    let head_response = next_field(data, &mut cursor)?;
    let body_response = next_field(data, &mut cursor)?;
    if cursor != data.len() || request.is_none() || headers.is_none() {
        return None;
    }
    let request = request?;
    let headers = headers?;
    Some(PackedFields {
        request,
        headers,
        overview,
        head_response,
        body_response,
    })
}

fn next_field<'a>(data: &'a [u8], cursor: &mut usize) -> Option<Option<&'a [u8]>> {
    let length_bytes = data.get(*cursor..cursor.checked_add(4)?)?;
    *cursor += 4;
    let length = u32::from_le_bytes(length_bytes.try_into().ok()?);
    if length == u32::MAX {
        return Some(None);
    }
    let length = usize::try_from(length).ok()?;
    let end = cursor.checked_add(length)?;
    let value = data.get(*cursor..end)?;
    *cursor = end;
    Some(Some(value))
}

fn invalid_input(
    overview_response_code: i32,
    overview_attempted: bool,
    head_response_code: i32,
    body_response_code: i32,
) -> ArticleIdentity {
    ArticleIdentity {
        parser_engine: "nntmux-rust-article-identity",
        schema_version: 3,
        comparison_basis: "exact_message_id_octets_case_sensitive",
        candidate_comparison_basis: "exact_delimited_message_id_octets_case_sensitive_candidate_only",
        request: IdentityValue {
            raw_hex: String::new(),
            token: None,
            candidate_token_hex: None,
            candidate_token_status: "unavailable_invalid_input",
            syntax_status: "invalid_input",
        },
        head_response: parse_protocol_response(None, head_response_code, 221),
        body_response: parse_protocol_response(None, body_response_code, 222),
        head_message_id: empty_source("invalid_input", "unavailable"),
        overview_message_id: empty_source("invalid_input", "unavailable"),
        overview_attempted,
        overview_row_structure_status: "invalid_input",
        overview_article_number: None,
        relations: IdentityRelations {
            head_response_matches_request: None,
            body_response_matches_request: None,
            head_response_matches_body_response: None,
            head_response_matches_head_field: None,
            body_response_matches_head_field: None,
            head_response_matches_overview: None,
            body_response_matches_overview: None,
            head_matches_overview: None,
            head_candidate_matches_request: None,
            overview_candidate_matches_request: None,
            head_candidate_matches_overview: None,
            head_response_matches_head_candidate: None,
            body_response_matches_head_candidate: None,
            head_response_matches_overview_candidate: None,
            body_response_matches_overview_candidate: None,
        },
        overview_response_code: (overview_response_code >= 0).then_some(overview_response_code),
        interpretation: "mismatches are descriptive only; RFC 3977 permits the lookup Message-ID to differ from the article Message-ID header",
    }
}

fn parse_protocol_response(
    response_text: Option<&[u8]>,
    response_code: i32,
    expected_code: i32,
) -> ProtocolResponseIdentity {
    let mut result = ProtocolResponseIdentity {
        response_code: (response_code >= 0).then_some(response_code),
        availability: if response_code < 0 {
            "failed"
        } else if response_code == expected_code {
            "available"
        } else {
            "unavailable"
        },
        response_text_hex: response_text.map(hex).unwrap_or_default(),
        response_text_basis: "net_nntp_current_status_response_trimmed_remainder",
        article_number: None,
        message_id: None,
        message_id_hex: None,
        trailing_comment_status: "unavailable",
        trailing_comment_bytes: None,
        trailing_comment_hex: None,
        trailing_comment_hex_truncated: false,
        parse_status: "unavailable",
    };
    if result.availability != "available" {
        return result;
    }
    let Some(text) = response_text else {
        result.parse_status = "missing_response_text";
        return result;
    };
    if text.len() > MAX_RESPONSE_TEXT_BYTES {
        result.parse_status = "budget_exceeded";
        result.availability = "budget_exceeded";
        return result;
    }
    let Some(separator) = text.iter().position(|byte| *byte == b' ') else {
        result.parse_status = "malformed";
        return result;
    };
    let article_number = &text[..separator];
    if article_number.is_empty()
        || article_number.len() > MAX_ARTICLE_NUMBER_DIGITS
        || !article_number.iter().all(u8::is_ascii_digit)
    {
        result.parse_status = "malformed_article_number";
        return result;
    }
    result.article_number = Some(String::from_utf8_lossy(article_number).into_owned());
    let message_id = &text[separator + 1..];
    if message_id.is_empty() {
        result.parse_status = "missing_message_id";
        return result;
    }
    if message_id[0] == b' ' {
        result.parse_status = "malformed_response_spacing";
        return result;
    }
    let Some(message_id_end) = message_id.iter().position(|byte| *byte == b'>') else {
        result.parse_status = protocol_message_id_status(message_id);
        return result;
    };
    let message_id_token = &message_id[..=message_id_end];
    let comment_suffix = &message_id[message_id_end + 1..];
    let trailing_comment = if comment_suffix.is_empty() {
        result.trailing_comment_status = "absent";
        None
    } else if comment_suffix[0] != b' ' {
        result.parse_status = "malformed_response_spacing";
        result.trailing_comment_status = "malformed";
        return result;
    } else {
        let comment = &comment_suffix[1..];
        let preview = &comment[..comment.len().min(MAX_RESPONSE_COMMENT_PREVIEW_BYTES)];
        result.trailing_comment_bytes = Some(comment.len());
        result.trailing_comment_hex = Some(hex(preview));
        result.trailing_comment_hex_truncated = preview.len() < comment.len();
        Some(comment)
    };

    if let Some(comment) = trailing_comment {
        if !valid_trailing_comment(comment) {
            result.parse_status = "malformed_trailing_comment";
            result.trailing_comment_status = "malformed";
            return result;
        }
        result.trailing_comment_status = "present";
    }
    result.parse_status = protocol_message_id_status(message_id_token);
    if result.parse_status == "valid" {
        result.message_id = String::from_utf8(message_id_token.to_vec()).ok();
        result.message_id_hex = Some(hex(message_id_token));
    }
    result
}

fn valid_trailing_comment(comment: &[u8]) -> bool {
    !comment
        .iter()
        .any(|byte| matches!(*byte, 0 | b'\r' | b'\n' | 0x7f))
        && std::str::from_utf8(comment).is_ok()
}

fn relation(left: Option<&str>, right: Option<&str>, available: bool) -> Option<bool> {
    available
        .then(|| left.zip(right).map(|(left, right)| left == right))
        .flatten()
}

fn empty_source(parse_status: &'static str, availability: &'static str) -> IdentitySource {
    IdentitySource {
        availability,
        parse_status,
        occurrence_count: 0,
        candidates: Vec::new(),
        matches_request: None,
    }
}

fn parse_header_message_ids(headers: &[u8]) -> Vec<IdentityOccurrence> {
    if headers.len() > MAX_HEADER_BYTES {
        return Vec::new();
    }
    let mut candidates = Vec::new();
    let mut pending_name = Vec::new();
    let mut pending_value = Vec::new();
    let mut pending_lines = Vec::new();
    let mut line_number = 0usize;
    let mut have_field = false;

    for raw_line in headers.split(|byte| *byte == b'\n') {
        line_number += 1;
        let line = raw_line.strip_suffix(b"\r").unwrap_or(raw_line);
        if line.is_empty() {
            break;
        }
        if matches!(line.first(), Some(b' ' | b'\t')) {
            if have_field {
                pending_lines.push(line_number);
                pending_value.extend_from_slice(line);
            } else {
                flush_header_candidate(
                    &pending_name,
                    &pending_value,
                    &pending_lines,
                    &mut candidates,
                );
                pending_name.clear();
                pending_value.clear();
                pending_lines.clear();
            }
            continue;
        }

        if have_field {
            flush_header_candidate(
                &pending_name,
                &pending_value,
                &pending_lines,
                &mut candidates,
            );
            pending_name.clear();
            pending_value.clear();
            pending_lines.clear();
        }
        have_field = false;
        let Some(colon) = line.iter().position(|byte| *byte == b':') else {
            continue;
        };
        pending_name.extend_from_slice(&line[..colon]);
        pending_value.extend_from_slice(&line[colon + 1..]);
        pending_lines.push(line_number);
        have_field = true;
        if candidates.len() >= MAX_OCCURRENCES {
            break;
        }
    }
    if have_field && candidates.len() < MAX_OCCURRENCES {
        flush_header_candidate(
            &pending_name,
            &pending_value,
            &pending_lines,
            &mut candidates,
        );
    }

    candidates
}

fn flush_header_candidate(
    name: &[u8],
    value: &[u8],
    lines: &[usize],
    candidates: &mut Vec<IdentityOccurrence>,
) {
    if !name.eq_ignore_ascii_case(b"Message-ID") || candidates.len() >= MAX_OCCURRENCES {
        return;
    }
    candidates.push(make_occurrence(lines.to_vec(), None, None, value, true));
}

fn parse_overview_message_id(
    row: &[u8],
) -> (Vec<IdentityOccurrence>, &'static str, Option<String>) {
    let row = row.strip_suffix(b"\r\n").unwrap_or(row);
    let row = row.strip_suffix(b"\n").unwrap_or(row);
    if row.iter().any(|byte| matches!(byte, b'\r' | b'\n' | 0)) {
        return (Vec::new(), "malformed_row", None);
    }
    let fields = row.split(|byte| *byte == b'\t').collect::<Vec<_>>();
    let Some(article_number) = fields.first() else {
        return (Vec::new(), "malformed_row", None);
    };
    if article_number.is_empty()
        || article_number.len() > MAX_ARTICLE_NUMBER_DIGITS
        || !article_number.iter().all(u8::is_ascii_digit)
    {
        return (Vec::new(), "malformed_row", None);
    }
    let article_number = String::from_utf8_lossy(article_number).into_owned();
    if fields.len() <= 4 {
        return (
            Vec::new(),
            "missing_message_id_column",
            Some(article_number),
        );
    }
    // OVER preserves the field value's outer whitespace as spaces, including
    // whitespace originally present after the Message-ID header colon.
    (
        vec![make_occurrence(
            Vec::new(),
            Some(0),
            Some(4),
            fields[4],
            true,
        )],
        "valid",
        Some(article_number),
    )
}

fn make_occurrence(
    source_line_indices: Vec<usize>,
    source_overview_row_index: Option<usize>,
    source_overview_column_index: Option<usize>,
    raw_value: &[u8],
    trim_space: bool,
) -> IdentityOccurrence {
    let token = if trim_space {
        trim_ascii_space(raw_value)
    } else {
        raw_value
    };
    let status = message_id_status(token);
    let (candidate_token_hex, candidate_token_status) = candidate_token(token);
    IdentityOccurrence {
        source_line_indices,
        source_overview_row_index,
        source_overview_column_index,
        unfolded_value_hex: hex(raw_value),
        token: (status == "valid")
            .then(|| String::from_utf8(token.to_vec()).ok())
            .flatten(),
        token_hex: (status == "valid").then(|| hex(token)),
        candidate_token_hex,
        candidate_token_status,
        candidate_token_basis: "single_exact_delimited_protocol_token_candidate_only",
        syntax_status: status,
    }
}

fn overview_availability(row: Option<&[u8]>, response_code: i32, attempted: bool) -> &'static str {
    if row.is_some_and(|value| value.len() > MAX_OVERVIEW_BYTES) {
        return "budget_exceeded";
    }
    if !attempted {
        return "not_attempted";
    }
    if response_code == 224 {
        return if row.is_some() {
            "available"
        } else {
            "empty_response"
        };
    }
    if response_code < 0 {
        return "failed";
    }
    if matches!(response_code, 500 | 501 | 503) {
        return "unsupported";
    }
    "unavailable"
}

fn source_parse_status(candidates: &[IdentityOccurrence]) -> &'static str {
    match candidates.len() {
        0 => "missing",
        1 if candidates[0].syntax_status == "valid" => "valid",
        1 => "malformed",
        _ => "duplicate",
    }
}

fn unique_valid_token(candidates: &[IdentityOccurrence]) -> Option<&str> {
    (candidates.len() == 1 && candidates[0].syntax_status == "valid")
        .then(|| candidates[0].token_hex.as_deref())
        .flatten()
}

fn unique_candidate_token(candidates: &[IdentityOccurrence]) -> Option<&str> {
    (candidates.len() == 1 && candidates[0].candidate_token_status != "unavailable")
        .then(|| candidates[0].candidate_token_hex.as_deref())
        .flatten()
}

fn candidate_token(value: &[u8]) -> (Option<String>, &'static str) {
    if value.len() > MAX_MESSAGE_ID_CANDIDATE_BYTES {
        return (None, "budget_exceeded");
    }
    let trimmed = trim_ascii_space(value);
    if protocol_message_id_status(trimmed) != "valid" {
        return (None, "unavailable");
    }
    let status = if message_id_status(trimmed) == "valid" {
        "rfc5322_valid"
    } else {
        "protocol_valid_non_rfc5322"
    };

    (Some(hex(trimmed)), status)
}

fn message_id_status(value: &[u8]) -> &'static str {
    if value.len() < 3
        || value.len() > 250
        || value.first() != Some(&b'<')
        || value.last() != Some(&b'>')
    {
        return "malformed";
    }
    let core = &value[1..value.len() - 1];
    if !core.is_ascii() || core.iter().any(|byte| !(33..=126).contains(byte)) {
        return "malformed";
    }
    let Some(separator) = core.iter().position(|byte| *byte == b'@') else {
        return "malformed";
    };
    if !is_dot_atom(&core[..separator]) || !is_id_right(&core[separator + 1..]) {
        return "malformed";
    }
    "valid"
}

fn protocol_message_id_status(value: &[u8]) -> &'static str {
    if value.len() < 3
        || value.len() > 250
        || value.first() != Some(&b'<')
        || value.last() != Some(&b'>')
    {
        return "malformed";
    }
    let core = &value[1..value.len() - 1];
    if core.is_empty()
        || core
            .iter()
            .any(|byte| !(33..=61).contains(byte) && !(63..=126).contains(byte))
    {
        return "malformed";
    }
    "valid"
}

fn is_dot_atom(value: &[u8]) -> bool {
    if value.is_empty() {
        return false;
    }
    let mut segment_len = 0usize;
    for byte in value {
        if *byte == b'.' {
            if segment_len == 0 {
                return false;
            }
            segment_len = 0;
        } else if is_atext(*byte) {
            segment_len += 1;
        } else {
            return false;
        }
    }
    segment_len > 0
}

fn is_atext(byte: u8) -> bool {
    byte.is_ascii_alphanumeric() || b"!#$%&'*+-/=?^_`{|}~".contains(&byte)
}

fn is_id_right(value: &[u8]) -> bool {
    if value.first() == Some(&b'[') && value.last() == Some(&b']') {
        return value[1..value.len() - 1].iter().all(|byte| {
            (33..=61).contains(byte) || (63..=90).contains(byte) || (94..=126).contains(byte)
        });
    }
    is_dot_atom(value)
}

fn trim_ascii_space(mut value: &[u8]) -> &[u8] {
    while matches!(value.first(), Some(b' ' | b'\t')) {
        value = &value[1..];
    }
    while matches!(value.last(), Some(b' ' | b'\t')) {
        value = &value[..value.len() - 1];
    }
    value
}

fn hex(bytes: &[u8]) -> String {
    const DIGITS: &[u8; 16] = b"0123456789abcdef";
    let mut out = String::with_capacity(bytes.len().saturating_mul(2));
    for byte in bytes {
        out.push(DIGITS[(byte >> 4) as usize] as char);
        out.push(DIGITS[(byte & 0x0f) as usize] as char);
    }
    out
}

#[cfg(test)]
mod tests {
    use super::{
        message_id_status, parse_overview_message_id, parse_protocol_response,
        protocol_message_id_status,
    };

    #[test]
    fn accepts_opaque_protocol_message_ids_without_requiring_email_syntax() {
        assert_eq!(protocol_message_id_status(b"<opaque>"), "valid");
        assert_eq!(protocol_message_id_status(b"<a:b@example>"), "valid");
        assert_eq!(message_id_status(b"<opaque>"), "malformed");
    }

    #[test]
    fn bounds_protocol_message_id_length_and_octets() {
        let mut maximum = vec![b'<'];
        maximum.extend(std::iter::repeat_n(b'x', 248));
        maximum.push(b'>');
        assert_eq!(protocol_message_id_status(&maximum), "valid");
        assert_eq!(protocol_message_id_status(b"<>"), "malformed");
        assert_eq!(protocol_message_id_status(b"<has space>"), "malformed");
        assert_eq!(protocol_message_id_status(b"<bad\nvalue>"), "malformed");

        let mut too_long = vec![b'<'];
        too_long.extend(std::iter::repeat_n(b'x', 249));
        too_long.push(b'>');
        assert_eq!(protocol_message_id_status(&too_long), "malformed");
    }

    #[test]
    fn accepts_only_space_separated_article_identity_response_fields() {
        let valid = parse_protocol_response(Some(b"0 <opaque@example>"), 221, 221);
        assert_eq!(valid.parse_status, "valid");
        assert_eq!(valid.article_number.as_deref(), Some("0"));
        assert_eq!(valid.message_id.as_deref(), Some("<opaque@example>"));

        let commented =
            parse_protocol_response(Some(b"0 <opaque@example> Headers follow"), 221, 221);
        assert_eq!(commented.parse_status, "valid");
        assert_eq!(commented.message_id.as_deref(), Some("<opaque@example>"));
        assert_eq!(
            commented.trailing_comment_hex.as_deref(),
            Some("4865616465727320666f6c6c6f77")
        );
        assert_eq!(commented.trailing_comment_bytes, Some(14));
        assert_eq!(commented.trailing_comment_status, "present");

        for malformed in [
            b"0\t<opaque@example>".as_slice(),
            b"0\r<opaque@example>",
            b"0\n<opaque@example>",
            b"0 <opaque@example>extra",
        ] {
            let parsed = parse_protocol_response(Some(malformed), 221, 221);
            assert_ne!(parsed.parse_status, "valid", "accepted {malformed:?}");
            assert!(parsed.message_id.is_none(), "retained {malformed:?}");
        }

        let malformed_comment =
            parse_protocol_response(Some(b"0 <opaque@example> bad\rcomment"), 221, 221);
        assert_eq!(malformed_comment.parse_status, "malformed_trailing_comment");
        assert_eq!(malformed_comment.trailing_comment_status, "malformed");

        let malformed_del_comment =
            parse_protocol_response(Some(b"0 <opaque@example> bad\x7fcomment"), 221, 221);
        assert_eq!(
            malformed_del_comment.parse_status,
            "malformed_trailing_comment"
        );
        assert_eq!(malformed_del_comment.trailing_comment_status, "malformed");
    }

    #[test]
    fn rejects_empty_article_numbers_but_accepts_zero_sentinel() {
        for (response_code, expected_code) in [(221, 221), (222, 222)] {
            let empty =
                parse_protocol_response(Some(b" <opaque@example>"), response_code, expected_code);
            assert_eq!(empty.parse_status, "malformed_article_number");
            assert!(empty.article_number.is_none());
            assert!(empty.message_id.is_none());
            assert!(empty.message_id_hex.is_none());

            for article_number in [b"0".as_slice(), b"123"] {
                let mut response = article_number.to_vec();
                response.extend_from_slice(b" <opaque@example>");
                let parsed = parse_protocol_response(Some(&response), response_code, expected_code);
                assert_eq!(parsed.parse_status, "valid");
                assert_eq!(parsed.message_id.as_deref(), Some("<opaque@example>"));
            }
        }
    }

    #[test]
    fn enforces_sixteen_digit_article_number_limit_for_responses_and_overview() {
        for (article_number, is_valid) in [
            (b"0".as_slice(), true),
            (b"0000", true),
            (b"2147483647", true),
            (b"0000002147483647", true),
            (b"00000002147483647", false),
        ] {
            let mut response = article_number.to_vec();
            response.extend_from_slice(b" <opaque@example>");
            let parsed = parse_protocol_response(Some(&response), 221, 221);
            assert_eq!(parsed.parse_status == "valid", is_valid);
            assert_eq!(parsed.message_id.is_some(), is_valid);

            let mut overview = article_number.to_vec();
            overview.extend_from_slice(b"\tdate\tfrom\tsubject\t<opaque@example>");
            let (_, status, parsed_number) = parse_overview_message_id(&overview);
            assert_eq!(status == "valid", is_valid);
            assert_eq!(parsed_number.is_some(), is_valid);
        }
    }
}
