use serde::Serialize;

const MAGIC: &[u8; 4] = b"XR01";
const MAX_INPUT_BYTES: usize = 196_608;
const MAX_HEADER_BYTES: usize = 65_536;
const MAX_OVERVIEW_BYTES: usize = 32_768;
const MAX_FORMAT_BYTES: usize = 32_768;
const MAX_FORMAT_LINES: usize = 256;
const MAX_ROWS: usize = 64;
const MAX_OCCURRENCES: usize = 64;
const MAX_LOCATIONS: usize = 512;
const MAX_VALUE_BYTES: usize = 4_096;

#[derive(Debug, Serialize)]
pub struct ArticleXref {
    pub parser_engine: &'static str,
    pub schema_version: u8,
    pub head: XrefSource,
    pub overview: XrefSource,
    pub overview_format: OverviewFormat,
    pub relations: XrefRelations,
    pub interpretation: &'static str,
}

#[derive(Debug, Serialize)]
pub struct XrefSource {
    pub availability: &'static str,
    pub parse_status: &'static str,
    pub response_row_count: Option<usize>,
    pub occurrence_count: usize,
    pub candidates: Vec<XrefOccurrence>,
}

#[derive(Debug, Serialize)]
pub struct XrefOccurrence {
    pub source: &'static str,
    pub source_line_indices: Vec<usize>,
    pub source_overview_row_index: Option<usize>,
    pub source_overview_column_index: Option<usize>,
    pub raw_value_hex: String,
    pub server_name: Option<String>,
    pub server_name_hex: Option<String>,
    pub locations: Vec<XrefLocation>,
    pub syntax_status: &'static str,
    #[serde(skip)]
    raw_value: Vec<u8>,
}

#[derive(Debug, Serialize)]
pub struct XrefLocation {
    pub raw_token_hex: String,
    pub newsgroup: Option<String>,
    pub newsgroup_hex: Option<String>,
    pub newsgroup_syntax_status: &'static str,
    pub article_locator: Option<String>,
    pub article_locator_hex: Option<String>,
    pub article_locator_is_decimal: Option<bool>,
    pub syntax_status: &'static str,
}

#[derive(Debug, Serialize)]
pub struct OverviewFormat {
    pub response_code: Option<i32>,
    pub availability: &'static str,
    pub parse_status: &'static str,
    pub line_count: usize,
    pub xref_format_line_indices: Vec<usize>,
    pub xref_column_index_basis: &'static str,
    pub xref_column_full_header: bool,
    pub provenance: &'static str,
}

#[derive(Debug, Serialize)]
pub struct XrefRelations {
    pub head_matches_single_overview_value: Option<bool>,
    pub comparison_basis: &'static str,
    pub required_overview_row_count: usize,
}

struct PackedFields<'a> {
    headers: &'a [u8],
    overview_rows: Option<&'a [u8]>,
    overview_format: Option<&'a [u8]>,
}

pub fn parse(
    packed: &[u8],
    head_response_code: i32,
    overview_response_code: i32,
    overview_attempted: bool,
    overview_row_count: i32,
    overview_format_response_code: i32,
) -> ArticleXref {
    let Some(fields) = unpack(packed) else {
        return invalid_input(
            head_response_code,
            overview_response_code,
            overview_format_response_code,
        );
    };

    let (head_availability, head_status, head_candidates) = if head_response_code != 221 {
        ("unavailable", "head_response_not_successful", Vec::new())
    } else if fields.headers.len() > MAX_HEADER_BYTES {
        ("budget_exceeded", "budget_exceeded", Vec::new())
    } else {
        let (candidates, exceeded) = parse_head_xrefs(fields.headers);
        let status = if exceeded {
            "budget_exceeded"
        } else {
            source_status(&candidates)
        };
        ("available", status, candidates)
    };
    let head = XrefSource {
        availability: head_availability,
        parse_status: head_status,
        response_row_count: None,
        occurrence_count: head_candidates.len(),
        candidates: head_candidates,
    };

    let (format_availability, format_status, format_lines, xref_columns) =
        parse_overview_format(fields.overview_format, overview_format_response_code);
    let overview_format = OverviewFormat {
        response_code: (overview_format_response_code >= 0)
            .then_some(overview_format_response_code),
        availability: format_availability,
        parse_status: format_status,
        line_count: format_lines.len(),
        xref_format_line_indices: xref_columns.iter().map(|(index, _)| *index).collect(),
        xref_column_index_basis: "zero_based_format_line_index; OVER row field index is one greater",
        xref_column_full_header: xref_columns.len() == 1 && xref_columns[0].1,
        provenance: "separately_collected_profile_for_same_configured_provider",
    };

    let (overview_availability, overview_status, overview_candidates) = parse_overview_xrefs(
        fields.overview_rows,
        overview_response_code,
        overview_attempted,
        overview_row_count,
        format_availability,
        format_status,
        &xref_columns,
    );
    let overview = XrefSource {
        availability: overview_availability,
        parse_status: overview_status,
        response_row_count: (overview_row_count >= 0).then_some(overview_row_count as usize),
        occurrence_count: overview_candidates.len(),
        candidates: overview_candidates,
    };

    let relation = if overview_row_count == 1
        && overview_response_code == 224
        && overview_format_response_code == 215
        && format_status == "valid"
        && xref_columns.len() == 1
        && head.parse_status == "valid"
        && overview.parse_status == "valid"
        && head_candidates_len_is_unique(&head)
        && overview.candidates.len() == 1
        && overview.candidates[0].syntax_status == "valid"
    {
        let head_value = unique_header_value(&head);
        let overview_value = overview_value_without_field_name(&overview.candidates[0]);
        head_value
            .zip(overview_value)
            .map(|(left, right)| replace_tabs_with_spaces(left) == replace_tabs_with_spaces(right))
    } else {
        None
    };

    ArticleXref {
        parser_engine: "nntmux-rust-article-xref",
        schema_version: 1,
        head,
        overview,
        overview_format,
        relations: XrefRelations {
            head_matches_single_overview_value: relation,
            comparison_basis: "unfolded_header_value_with_tabs_replaced_by_spaces_exact_octets",
            required_overview_row_count: 1,
        },
        interpretation: "Xref identifies a filing server and group locators reported by that server; it does not prove uploader identity, endpoint ownership, or a shared backbone",
    }
}

fn unpack(data: &[u8]) -> Option<PackedFields<'_>> {
    if data.len() > MAX_INPUT_BYTES || data.get(..4)? != MAGIC {
        return None;
    }
    let mut cursor = 4usize;
    let headers = next_field(data, &mut cursor)?;
    let overview_rows = next_field(data, &mut cursor)?;
    let overview_format = next_field(data, &mut cursor)?;
    if cursor != data.len() || headers.is_none() {
        return None;
    }
    Some(PackedFields {
        headers: headers?,
        overview_rows,
        overview_format,
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

fn unpack_lines(
    data: Option<&[u8]>,
    maximum_bytes: usize,
    maximum_lines: usize,
) -> Option<Vec<&[u8]>> {
    let data = data?;
    let maximum_packed_bytes = maximum_bytes.checked_add(4 + maximum_lines.checked_mul(4)?)?;
    if data.len() > maximum_packed_bytes {
        return None;
    }
    let count_bytes = data.get(..4)?;
    let count = usize::try_from(u32::from_le_bytes(count_bytes.try_into().ok()?)).ok()?;
    if count > maximum_lines {
        return None;
    }
    let mut cursor = 4usize;
    let mut lines = Vec::with_capacity(count);
    let mut total_line_bytes = 0usize;
    for _ in 0..count {
        let line = next_field(data, &mut cursor)??;
        total_line_bytes = total_line_bytes.checked_add(line.len())?;
        if total_line_bytes > maximum_bytes {
            return None;
        }
        lines.push(line);
    }
    (cursor == data.len()).then_some(lines)
}

type ParsedOverviewFormat<'a> = (
    &'static str,
    &'static str,
    Vec<&'a [u8]>,
    Vec<(usize, bool)>,
);

fn parse_overview_format(data: Option<&[u8]>, response_code: i32) -> ParsedOverviewFormat<'_> {
    if response_code != 215 {
        return (
            if response_code < 0 {
                "failed"
            } else {
                "unavailable"
            },
            if response_code < 0 {
                "request_failed"
            } else {
                "response_not_successful"
            },
            Vec::new(),
            Vec::new(),
        );
    }
    let Some(lines) = unpack_lines(data, MAX_FORMAT_BYTES, MAX_FORMAT_LINES) else {
        return (
            "available",
            "missing_or_budget_exceeded",
            Vec::new(),
            Vec::new(),
        );
    };
    if lines.len() < 7 {
        return (
            "available",
            "incomplete_mandatory_fields",
            lines,
            Vec::new(),
        );
    }
    let mandatory = [
        b"subject:".as_slice(),
        b"from:",
        b"date:",
        b"message-id:",
        b"references:",
    ];
    if mandatory
        .iter()
        .zip(lines.iter())
        .any(|(expected, actual)| !actual.eq_ignore_ascii_case(expected))
        || !(lines[5].eq_ignore_ascii_case(b":bytes") || lines[5].eq_ignore_ascii_case(b"bytes:"))
        || !(lines[6].eq_ignore_ascii_case(b":lines") || lines[6].eq_ignore_ascii_case(b"lines:"))
    {
        return (
            "available",
            "invalid_mandatory_field_order",
            lines,
            Vec::new(),
        );
    }
    let columns = lines
        .iter()
        .enumerate()
        .skip(7)
        .filter_map(|(index, line)| {
            let (candidate, full_header) = optional_header_format_field(line)?;
            candidate
                .eq_ignore_ascii_case(b"xref")
                .then_some((index, full_header))
        })
        .collect::<Vec<_>>();
    let valid = lines
        .iter()
        .skip(7)
        .all(|line| is_valid_optional_format_line(line));
    (
        "available",
        if valid {
            "valid"
        } else {
            "invalid_optional_field"
        },
        lines,
        columns,
    )
}

fn is_valid_optional_format_line(line: &[u8]) -> bool {
    if line.starts_with(b":") {
        return line.len() > 1
            && line[1..]
                .iter()
                .all(|byte| (33..=126).contains(byte) && *byte != b':');
    }
    let Some((name, _)) = optional_header_format_field(line) else {
        return false;
    };
    !name.is_empty()
        && name
            .iter()
            .all(|byte| (33..=126).contains(byte) && *byte != b':')
}

fn optional_header_format_field(line: &[u8]) -> Option<(&[u8], bool)> {
    if line.starts_with(b":") {
        return None;
    }
    if let Some(name) = strip_full_suffix(line) {
        Some((name, true))
    } else if !line.contains(&b':') {
        Some((line, false))
    } else {
        None
    }
}

fn strip_full_suffix(value: &[u8]) -> Option<&[u8]> {
    let suffix_start = value.len().checked_sub(5)?;
    value[suffix_start..]
        .eq_ignore_ascii_case(b":full")
        .then_some(&value[..suffix_start])
}

fn parse_overview_xrefs(
    data: Option<&[u8]>,
    response_code: i32,
    attempted: bool,
    reported_row_count: i32,
    format_availability: &'static str,
    format_status: &'static str,
    xref_columns: &[(usize, bool)],
) -> (&'static str, &'static str, Vec<XrefOccurrence>) {
    if !attempted {
        return ("not_attempted", "not_attempted", Vec::new());
    }
    if response_code != 224 {
        return (
            if response_code < 0 {
                "failed"
            } else {
                "unavailable"
            },
            "response_not_successful",
            Vec::new(),
        );
    }
    if format_availability != "available" {
        return (
            format_availability,
            "overview_format_unavailable",
            Vec::new(),
        );
    }
    if format_status != "valid" {
        return ("available", "overview_format_invalid", Vec::new());
    }
    match xref_columns.len() {
        0 => return ("available", "not_advertised", Vec::new()),
        1 => {}
        _ => return ("available", "ambiguous_format_column", Vec::new()),
    }
    let Some(rows) = unpack_lines(data, MAX_OVERVIEW_BYTES, MAX_ROWS) else {
        return ("budget_exceeded", "missing_or_budget_exceeded", Vec::new());
    };
    if reported_row_count < 0 || rows.len() != reported_row_count as usize {
        return ("available", "reported_row_count_mismatch", Vec::new());
    }
    if rows.is_empty() {
        return ("available", "empty_response", Vec::new());
    }
    let mut candidates = Vec::new();
    let mut malformed_rows = 0usize;
    let mut missing_values = 0usize;
    for (row_index, row) in rows.iter().enumerate() {
        if row.iter().any(|byte| matches!(byte, b'\r' | b'\n' | 0)) {
            malformed_rows += 1;
            continue;
        }
        let fields = row.split(|byte| *byte == b'\t').collect::<Vec<_>>();
        if fields.len() < 8 || fields[0].is_empty() || !fields[0].iter().all(u8::is_ascii_digit) {
            malformed_rows += 1;
            continue;
        }
        let (column_index, full_header) = xref_columns[0];
        let Some(value) = fields.get(column_index + 1) else {
            missing_values += 1;
            continue;
        };
        if value.is_empty() {
            missing_values += 1;
            continue;
        }
        candidates.push(parse_occurrence(
            "overview",
            Vec::new(),
            Some(row_index),
            Some(column_index + 1),
            value,
            full_header,
            true,
        ));
        if candidates.len() >= MAX_ROWS {
            break;
        }
    }
    let status = if malformed_rows > 0 {
        "row_missing_or_malformed_xref_column"
    } else if candidates.is_empty() {
        "missing"
    } else if missing_values > 0 {
        "row_missing_or_malformed_xref_column"
    } else if rows.len() != 1 {
        "multiple_rows"
    } else {
        source_status(&candidates)
    };
    ("available", status, candidates)
}

fn parse_head_xrefs(headers: &[u8]) -> (Vec<XrefOccurrence>, bool) {
    let mut candidates = Vec::new();
    let mut pending_name = Vec::new();
    let mut pending_value = Vec::new();
    let mut pending_lines = Vec::new();
    let mut pending_spacing_valid = true;
    let mut have_field = false;
    let mut line_number = 0usize;
    let mut exceeded = false;
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
            }
            continue;
        }
        if have_field {
            flush_head_xref(
                &pending_name,
                &pending_value,
                &pending_lines,
                &mut candidates,
                &mut exceeded,
                pending_spacing_valid,
            );
        }
        pending_name.clear();
        pending_value.clear();
        pending_lines.clear();
        have_field = false;
        pending_spacing_valid = true;
        let Some(colon) = line.iter().position(|byte| *byte == b':') else {
            continue;
        };
        pending_name.extend_from_slice(&line[..colon]);
        pending_value.extend_from_slice(&line[colon + 1..]);
        pending_lines.push(line_number);
        pending_spacing_valid = !pending_name.eq_ignore_ascii_case(b"Xref")
            || matches!(line.get(colon + 1), Some(b' ' | b'\t'));
        have_field = true;
    }
    if have_field {
        flush_head_xref(
            &pending_name,
            &pending_value,
            &pending_lines,
            &mut candidates,
            &mut exceeded,
            pending_spacing_valid,
        );
    }
    (candidates, exceeded)
}

fn flush_head_xref(
    name: &[u8],
    value: &[u8],
    lines: &[usize],
    candidates: &mut Vec<XrefOccurrence>,
    exceeded: &mut bool,
    spacing_valid: bool,
) {
    if !name.eq_ignore_ascii_case(b"Xref") {
        return;
    }
    if candidates.len() >= MAX_OCCURRENCES || value.len() > MAX_VALUE_BYTES {
        *exceeded = true;
        return;
    }
    candidates.push(parse_occurrence(
        "head",
        lines.to_vec(),
        None,
        None,
        value,
        false,
        spacing_valid,
    ));
}

fn parse_occurrence(
    source: &'static str,
    source_line_indices: Vec<usize>,
    source_overview_row_index: Option<usize>,
    source_overview_column_index: Option<usize>,
    raw_value: &[u8],
    full_header: bool,
    header_spacing_valid: bool,
) -> XrefOccurrence {
    let mut value = raw_value;
    if full_header {
        if let Some(rest) = strip_xref_prefix(value) {
            value = rest;
        } else {
            return XrefOccurrence {
                source,
                source_line_indices,
                source_overview_row_index,
                source_overview_column_index,
                raw_value_hex: hex(&raw_value[..raw_value.len().min(MAX_VALUE_BYTES)]),
                server_name: None,
                server_name_hex: None,
                locations: Vec::new(),
                syntax_status: "full_header_name_missing",
                raw_value: raw_value[..raw_value.len().min(MAX_VALUE_BYTES)].to_vec(),
            };
        }
    }
    if raw_value.len() > MAX_VALUE_BYTES {
        return XrefOccurrence {
            source,
            source_line_indices,
            source_overview_row_index,
            source_overview_column_index,
            raw_value_hex: hex(&raw_value[..MAX_VALUE_BYTES]),
            server_name: None,
            server_name_hex: None,
            locations: Vec::new(),
            syntax_status: "budget_exceeded",
            raw_value: raw_value[..MAX_VALUE_BYTES].to_vec(),
        };
    }
    if !header_spacing_valid {
        return XrefOccurrence {
            source,
            source_line_indices,
            source_overview_row_index,
            source_overview_column_index,
            raw_value_hex: hex(raw_value),
            server_name: None,
            server_name_hex: None,
            locations: Vec::new(),
            syntax_status: "malformed_header_whitespace",
            raw_value: raw_value.to_vec(),
        };
    }
    if !value
        .iter()
        .all(|byte| *byte == b' ' || *byte == b'\t' || (0x21..=0x7e).contains(byte))
    {
        return XrefOccurrence {
            source,
            source_line_indices,
            source_overview_row_index,
            source_overview_column_index,
            raw_value_hex: hex(raw_value),
            server_name: None,
            server_name_hex: None,
            locations: Vec::new(),
            syntax_status: "malformed_whitespace_or_octet",
            raw_value: raw_value.to_vec(),
        };
    }
    let value = trim_fws(value);
    let tokens = value
        .split(|byte| matches!(byte, b' ' | b'\t'))
        .filter(|token| !token.is_empty())
        .collect::<Vec<_>>();
    if tokens.is_empty() {
        return XrefOccurrence {
            source,
            source_line_indices,
            source_overview_row_index,
            source_overview_column_index,
            raw_value_hex: hex(raw_value),
            server_name: None,
            server_name_hex: None,
            locations: Vec::new(),
            syntax_status: "missing_server_and_locations",
            raw_value: raw_value[..raw_value.len().min(MAX_VALUE_BYTES)].to_vec(),
        };
    }
    let server = tokens[0];
    let server_valid = valid_path_identity(server);
    let mut locations = Vec::new();
    let budget_exceeded = tokens.len().saturating_sub(1) > MAX_LOCATIONS;
    for token in tokens.iter().skip(1).take(MAX_LOCATIONS) {
        locations.push(parse_location(token));
    }
    let all_locations_valid = !locations.is_empty()
        && locations
            .iter()
            .all(|location| location.syntax_status == "valid");
    let syntax_status = if raw_value.len() > MAX_VALUE_BYTES || budget_exceeded {
        "budget_exceeded"
    } else if !server_valid {
        "malformed_server_name"
    } else if locations.is_empty() {
        "missing_locations"
    } else if all_locations_valid {
        "valid"
    } else {
        "one_or_more_malformed_locations"
    };
    XrefOccurrence {
        source,
        source_line_indices,
        source_overview_row_index,
        source_overview_column_index,
        raw_value_hex: hex(raw_value),
        server_name: server_valid.then(|| String::from_utf8_lossy(server).into_owned()),
        server_name_hex: Some(hex(server)),
        locations,
        syntax_status,
        raw_value: raw_value[..raw_value.len().min(MAX_VALUE_BYTES)].to_vec(),
    }
}

fn parse_location(token: &[u8]) -> XrefLocation {
    let Some(separator) = token.iter().position(|byte| *byte == b':') else {
        return invalid_location(token, "missing_group_separator", "missing_article_locator");
    };
    let group = &token[..separator];
    let locator = &token[separator + 1..];
    let group_valid = valid_newsgroup(group);
    let locator_valid = !locator.is_empty()
        && locator.iter().all(|byte| {
            (0x21..=0x27).contains(byte)
                || (0x29..=0x3a).contains(byte)
                || (0x3c..=0x7e).contains(byte)
        });
    XrefLocation {
        raw_token_hex: hex(token),
        newsgroup: group_valid.then(|| String::from_utf8_lossy(group).into_owned()),
        newsgroup_hex: Some(hex(group)),
        newsgroup_syntax_status: if group_valid { "valid" } else { "malformed" },
        article_locator: locator_valid.then(|| String::from_utf8_lossy(locator).into_owned()),
        article_locator_hex: Some(hex(locator)),
        article_locator_is_decimal: locator_valid.then(|| locator.iter().all(u8::is_ascii_digit)),
        syntax_status: if group_valid && locator_valid {
            "valid"
        } else {
            "malformed"
        },
    }
}

fn invalid_location(
    token: &[u8],
    group_status: &'static str,
    locator_status: &'static str,
) -> XrefLocation {
    XrefLocation {
        raw_token_hex: hex(token),
        newsgroup: None,
        newsgroup_hex: None,
        newsgroup_syntax_status: group_status,
        article_locator: None,
        article_locator_hex: None,
        article_locator_is_decimal: None,
        syntax_status: locator_status,
    }
}

fn valid_newsgroup(value: &[u8]) -> bool {
    !value.is_empty()
        && value.split(|byte| *byte == b'.').all(|component| {
            !component.is_empty()
                && component
                    .iter()
                    .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'+' | b'-' | b'_'))
        })
}

fn source_status(candidates: &[XrefOccurrence]) -> &'static str {
    match candidates.len() {
        0 => "missing",
        1 if candidates[0].syntax_status == "valid" => "valid",
        1 => candidates[0].syntax_status,
        _ => "duplicate",
    }
}

fn head_candidates_len_is_unique(source: &XrefSource) -> bool {
    source.availability == "available"
        && source.candidates.len() == 1
        && source.candidates[0].syntax_status == "valid"
}

fn unique_header_value(source: &XrefSource) -> Option<&[u8]> {
    head_candidates_len_is_unique(source).then_some(source.candidates[0].raw_value.as_slice())
}

fn overview_value_without_field_name(occurrence: &XrefOccurrence) -> Option<&[u8]> {
    strip_xref_prefix(&occurrence.raw_value)
}

fn strip_xref_prefix(value: &[u8]) -> Option<&[u8]> {
    let prefix = value.get(..5)?;
    if !prefix.eq_ignore_ascii_case(b"xref:") {
        return None;
    }
    matches!(value.get(5), Some(b' ' | b'\t')).then(|| &value[5..])
}

fn replace_tabs_with_spaces(value: &[u8]) -> Vec<u8> {
    value
        .iter()
        .map(|byte| if *byte == b'\t' { b' ' } else { *byte })
        .collect()
}

fn trim_fws(mut value: &[u8]) -> &[u8] {
    while matches!(value.first(), Some(b' ' | b'\t')) {
        value = &value[1..];
    }
    while matches!(value.last(), Some(b' ' | b'\t')) {
        value = &value[..value.len() - 1];
    }
    value
}

fn valid_path_identity(value: &[u8]) -> bool {
    if value.is_empty() {
        return false;
    }
    if !value.contains(&b'.') {
        return value
            .iter()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_'));
    }
    let labels = value.split(|byte| *byte == b'.').collect::<Vec<_>>();
    labels.len() >= 2
        && labels.iter().all(|label| {
            !label.is_empty()
                && label
                    .iter()
                    .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_'))
                && !label.first().is_some_and(|byte| *byte == b'-')
                && !label.last().is_some_and(|byte| *byte == b'-')
        })
}

fn invalid_input(head_code: i32, overview_code: i32, format_code: i32) -> ArticleXref {
    ArticleXref {
        parser_engine: "nntmux-rust-article-xref",
        schema_version: 1,
        head: XrefSource {
            availability: "invalid_input",
            parse_status: "invalid_input",
            response_row_count: None,
            occurrence_count: 0,
            candidates: Vec::new(),
        },
        overview: XrefSource {
            availability: "invalid_input",
            parse_status: "invalid_input",
            response_row_count: None,
            occurrence_count: 0,
            candidates: Vec::new(),
        },
        overview_format: OverviewFormat {
            response_code: (format_code >= 0).then_some(format_code),
            availability: "invalid_input",
            parse_status: "invalid_input",
            line_count: 0,
            xref_format_line_indices: Vec::new(),
            xref_column_index_basis: "zero_based_format_line_index; OVER row field index is one greater",
            xref_column_full_header: false,
            provenance: "separately_collected_profile_for_same_configured_provider",
        },
        relations: XrefRelations {
            head_matches_single_overview_value: None,
            comparison_basis: "unfolded_header_value_with_tabs_replaced_by_spaces_exact_octets",
            required_overview_row_count: 1,
        },
        interpretation: if head_code != 221 || overview_code != 224 {
            "raw query outcomes are retained separately; Xref server identity does not prove uploader identity"
        } else {
            "Xref identifies a filing server and group locators reported by that server; it does not prove uploader identity, endpoint ownership, or a shared backbone"
        },
    }
}

fn hex(bytes: &[u8]) -> String {
    const DIGITS: &[u8; 16] = b"0123456789abcdef";
    let mut output = String::with_capacity(bytes.len().saturating_mul(2));
    for byte in bytes {
        output.push(DIGITS[(byte >> 4) as usize] as char);
        output.push(DIGITS[(byte & 0x0f) as usize] as char);
    }
    output
}
