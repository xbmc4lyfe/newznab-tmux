use serde::Serialize;
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;

pub const MAX_HEADER_BYTES: usize = 131_072;
const MAX_HEADER_LINES: usize = 4_096;
const MAX_HEADER_FIELDS: usize = 2_048;
const MAX_RETAINED_VALUE_BYTES: usize = 256;
const MAX_RETAINED_NAME_BYTES: usize = 128;
const MAX_XREF_LOCATIONS: usize = 512;
const MAX_XREF_TOKEN_TEXT_BYTES: usize = 512;
const MAX_XREF_TOKEN_PREVIEW_BYTES: usize = 128;

#[derive(Debug, Serialize)]
pub struct HeaderMetadata {
    pub parser_engine: &'static str,
    pub schema_version: u8,
    pub input_bytes: usize,
    pub status: &'static str,
    pub line_count: usize,
    pub header_terminator_line_index: Option<usize>,
    pub field_count: usize,
    pub malformed_line_count: usize,
    pub orphan_continuation_count: usize,
    pub field_occurrences_truncated: bool,
    pub ordered_field_names_sha256: String,
    pub duplicate_field_names: Vec<String>,
    pub conflicting_duplicate_field_names: Vec<String>,
    pub fields: Vec<HeaderField>,
    pub newsgroups_xref_relationship: NewsgroupsXrefRelationship,
}

#[derive(Debug, Serialize)]
pub struct HeaderPairComparison {
    pub parser_engine: &'static str,
    pub schema_version: u8,
    pub comparison_basis: &'static str,
    pub identity_match: Option<bool>,
    pub same_article_comparison_status: &'static str,
    pub parsed_field_comparison_status: &'static str,
    pub left_status: &'static str,
    pub right_status: &'static str,
    pub left_field_count: usize,
    pub right_field_count: usize,
    pub left_field_occurrences_truncated: bool,
    pub right_field_occurrences_truncated: bool,
    pub left_raw_header_sha256: String,
    pub right_raw_header_sha256: String,
    pub raw_header_sha256_matches: Option<bool>,
    pub left_malformed_line_count: usize,
    pub right_malformed_line_count: usize,
    pub left_orphan_continuation_count: usize,
    pub right_orphan_continuation_count: usize,
    pub field_occurrence_counts_complete: bool,
    pub normalized_field_order_matches: Option<bool>,
    pub exact_field_name_order_matches: Option<bool>,
    pub fields: Vec<HeaderNameComparison>,
}

#[derive(Debug, Serialize)]
pub struct HeaderNameComparison {
    pub normalized_name: String,
    pub left_occurrence_count: usize,
    pub right_occurrence_count: usize,
    pub occurrence_count_matches: Option<bool>,
    pub occurrence_count_comparison_complete: bool,
    pub raw_value_hashes_match: Option<bool>,
    pub unfolded_value_hashes_match: Option<bool>,
    pub compared_occurrence_count: usize,
}

#[derive(Debug, Serialize)]
pub struct HeaderField {
    pub field_index: usize,
    pub name: String,
    pub name_byte_length: usize,
    pub name_hex: String,
    pub name_hex_truncated: bool,
    pub name_syntax_valid: bool,
    pub normalized_name: Option<String>,
    pub duplicate_occurrence_index: usize,
    pub duplicate_value_conflicts: bool,
    pub source_line_indices: Vec<usize>,
    pub continuation_line_count: usize,
    pub final_line_terminated: bool,
    pub final_line_crlf_terminated: bool,
    pub lines: Option<LinesFieldMetadata>,
    pub raw_value_byte_length: usize,
    pub raw_value_sha256: String,
    pub value_byte_length: usize,
    pub value_sha256: String,
    pub value_utf8_valid: bool,
    pub value_preview_utf8: Option<String>,
    pub value_preview_hex: String,
    pub value_preview_truncated: bool,
    pub newsgroups: Option<NewsgroupsMetadata>,
    pub xref: Option<XrefMetadata>,
}

#[derive(Debug, Serialize)]
pub struct LinesFieldMetadata {
    pub status: &'static str,
    pub required_separator_space_valid: bool,
    pub continuation_present: bool,
    pub digit_count: usize,
    pub leading_zero_count: usize,
    pub declared_body_line_count: Option<u64>,
    pub syntax_valid: bool,
    pub obsolete_header: bool,
}

#[derive(Debug, Serialize)]
pub struct NewsgroupsMetadata {
    pub status: &'static str,
    pub required_separator_space_valid: bool,
    pub group_count: usize,
    pub malformed_group_count: usize,
    pub groups_truncated: bool,
    pub duplicate_group_count: usize,
    pub duplicate_group_indices_truncated: bool,
    pub duplicate_group_indices: Vec<usize>,
    pub groups: Vec<XrefToken>,
}

#[derive(Debug, Serialize)]
pub struct NewsgroupsXrefRelationship {
    pub status: &'static str,
    pub comparison_basis: &'static str,
    pub newsgroups_field_occurrence_count: usize,
    pub xref_field_occurrence_count: usize,
    pub field_occurrence_counts_complete: bool,
    pub common_group_count: usize,
    pub newsgroups_only_count: usize,
    pub xref_only_count: usize,
    pub result_lists_truncated: bool,
    pub common_groups: Vec<XrefToken>,
    pub newsgroups_only_groups: Vec<XrefToken>,
    pub xref_only_groups: Vec<XrefToken>,
}

#[derive(Debug, Serialize)]
pub struct XrefMetadata {
    pub status: &'static str,
    pub comparison_basis: &'static str,
    pub required_separator_space_valid: bool,
    pub server_name: Option<XrefToken>,
    pub server_name_syntax_valid: Option<bool>,
    pub location_count: usize,
    pub malformed_location_count: usize,
    pub locations_truncated: bool,
    pub duplicate_newsgroup_location_count: usize,
    pub conflicting_newsgroup_location_count: usize,
    pub relationship_indices_truncated: bool,
    pub locations: Vec<XrefLocation>,
    pub duplicate_newsgroup_location_indices: Vec<usize>,
    pub conflicting_newsgroup_location_indices: Vec<usize>,
}

#[derive(Clone, Debug, Serialize)]
pub struct XrefToken {
    pub text: Option<String>,
    pub byte_length: usize,
    pub sha256: String,
    pub preview_hex: String,
    pub preview_truncated: bool,
    pub ascii_valid: bool,
}

#[derive(Debug, Serialize)]
pub struct XrefLocation {
    pub index: usize,
    pub raw: XrefToken,
    pub newsgroup: Option<XrefToken>,
    pub article_locator: Option<XrefToken>,
    pub syntax_valid: bool,
    pub article_locator_is_decimal: Option<bool>,
}

#[derive(Default)]
struct PendingField {
    name: Vec<u8>,
    value: Vec<u8>,
    raw_value: Vec<u8>,
    source_line_indices: Vec<usize>,
    continuation_line_count: usize,
    final_line_terminated: bool,
}

pub fn parse(input: &[u8]) -> HeaderMetadata {
    if input.len() > MAX_HEADER_BYTES {
        return HeaderMetadata {
            parser_engine: "nntmux-rust-nntp-headers",
            schema_version: 3,
            input_bytes: input.len(),
            status: "budget_exceeded",
            line_count: 0,
            header_terminator_line_index: None,
            field_count: 0,
            malformed_line_count: 0,
            orphan_continuation_count: 0,
            field_occurrences_truncated: false,
            ordered_field_names_sha256: sha256_hex(&[]),
            duplicate_field_names: Vec::new(),
            conflicting_duplicate_field_names: Vec::new(),
            fields: Vec::new(),
            newsgroups_xref_relationship: unavailable_group_relationship(0, 0, false),
        };
    }

    let mut metadata = HeaderMetadata {
        parser_engine: "nntmux-rust-nntp-headers",
        schema_version: 3,
        input_bytes: input.len(),
        status: "complete",
        line_count: 0,
        header_terminator_line_index: None,
        field_count: 0,
        malformed_line_count: 0,
        orphan_continuation_count: 0,
        field_occurrences_truncated: false,
        ordered_field_names_sha256: String::new(),
        duplicate_field_names: Vec::new(),
        conflicting_duplicate_field_names: Vec::new(),
        fields: Vec::new(),
        newsgroups_xref_relationship: unavailable_group_relationship(0, 0, true),
    };
    let mut pending = PendingField::default();
    let mut name_counts = BTreeMap::<String, usize>::new();
    let mut first_value_hashes = BTreeMap::<String, String>::new();
    let mut ordered_names = Vec::new();

    for (line_index, raw_line) in input.split_inclusive(|byte| *byte == b'\n').enumerate() {
        if line_index >= MAX_HEADER_LINES {
            metadata.status = "line_budget_exceeded";
            metadata.field_occurrences_truncated = true;
            break;
        }
        metadata.line_count += 1;
        let line_number = line_index + 1;
        let line_terminated = raw_line.ends_with(b"\n");
        let mut line = raw_line.strip_suffix(b"\n").unwrap_or(raw_line);
        if let Some(without_cr) = line.strip_suffix(b"\r") {
            line = without_cr;
        }

        if line.is_empty() {
            flush_field(
                &mut pending,
                &mut metadata,
                &mut name_counts,
                &mut first_value_hashes,
                &mut ordered_names,
            );
            metadata.header_terminator_line_index = Some(line_number);
            break;
        }

        if matches!(line.first(), Some(b' ' | b'\t')) {
            if pending.name.is_empty() {
                metadata.orphan_continuation_count += 1;
            } else {
                pending.raw_value.extend_from_slice(raw_line);
                pending.value.extend_from_slice(line);
                pending.source_line_indices.push(line_number);
                pending.continuation_line_count += 1;
                pending.final_line_terminated = line_terminated;
            }
            continue;
        }

        flush_field(
            &mut pending,
            &mut metadata,
            &mut name_counts,
            &mut first_value_hashes,
            &mut ordered_names,
        );

        let Some(colon) = line.iter().position(|byte| *byte == b':') else {
            metadata.malformed_line_count += 1;
            continue;
        };
        let name = &line[..colon];
        if !valid_field_name(name) {
            metadata.malformed_line_count += 1;
            continue;
        }

        pending.name.extend_from_slice(name);
        pending.value.extend_from_slice(&line[colon + 1..]);
        pending.raw_value.extend_from_slice(&raw_line[colon + 1..]);
        pending.source_line_indices.push(line_number);
        pending.final_line_terminated = line_terminated;
    }

    if metadata.header_terminator_line_index.is_none() && !pending.name.is_empty() {
        flush_field(
            &mut pending,
            &mut metadata,
            &mut name_counts,
            &mut first_value_hashes,
            &mut ordered_names,
        );
    }
    metadata.ordered_field_names_sha256 = sha256_hex(&ordered_names);
    metadata.newsgroups_xref_relationship =
        relate_newsgroups_to_xref(&metadata.fields, !metadata.field_occurrences_truncated);
    metadata
}

pub fn compare(left: &[u8], right: &[u8], identity_match: Option<bool>) -> HeaderPairComparison {
    let left_metadata = parse(left);
    let right_metadata = parse(right);
    let mut names = Vec::<String>::new();
    for field in left_metadata
        .fields
        .iter()
        .chain(right_metadata.fields.iter())
    {
        if let Some(name) = &field.normalized_name
            && !names.contains(name)
        {
            names.push(name.clone());
        }
    }
    let fields_complete = left_metadata.status == "complete"
        && right_metadata.status == "complete"
        && !left_metadata.field_occurrences_truncated
        && !right_metadata.field_occurrences_truncated
        && left_metadata.malformed_line_count == 0
        && right_metadata.malformed_line_count == 0
        && left_metadata.orphan_continuation_count == 0
        && right_metadata.orphan_continuation_count == 0;
    let fields = names
        .into_iter()
        .map(|name| {
            let left_fields = left_metadata
                .fields
                .iter()
                .filter(|field| field.normalized_name.as_deref() == Some(&name))
                .collect::<Vec<_>>();
            let right_fields = right_metadata
                .fields
                .iter()
                .filter(|field| field.normalized_name.as_deref() == Some(&name))
                .collect::<Vec<_>>();
            let compared_occurrence_count = left_fields.len().min(right_fields.len());
            let raw_values_match = fields_complete.then(|| {
                left_fields.iter().zip(&right_fields).all(|(left, right)| {
                    left.raw_value_byte_length == right.raw_value_byte_length
                        && left.raw_value_sha256 == right.raw_value_sha256
                }) && left_fields.len() == right_fields.len()
            });
            let unfolded_values_match = fields_complete.then(|| {
                left_fields.iter().zip(&right_fields).all(|(left, right)| {
                    left.value_byte_length == right.value_byte_length
                        && left.value_sha256 == right.value_sha256
                }) && left_fields.len() == right_fields.len()
            });

            HeaderNameComparison {
                normalized_name: name,
                left_occurrence_count: left_fields.len(),
                right_occurrence_count: right_fields.len(),
                occurrence_count_matches: fields_complete
                    .then_some(left_fields.len() == right_fields.len()),
                occurrence_count_comparison_complete: fields_complete,
                raw_value_hashes_match: raw_values_match,
                unfolded_value_hashes_match: unfolded_values_match,
                compared_occurrence_count,
            }
        })
        .collect();
    let normalized_field_order_matches = fields_complete.then(|| {
        left_metadata
            .fields
            .iter()
            .map(|field| field.normalized_name.as_deref())
            .eq(right_metadata
                .fields
                .iter()
                .map(|field| field.normalized_name.as_deref()))
    });
    let exact_field_name_order_matches = fields_complete.then(|| {
        left_metadata
            .fields
            .iter()
            .map(|field| field.name.as_str())
            .eq(right_metadata
                .fields
                .iter()
                .map(|field| field.name.as_str()))
    });

    HeaderPairComparison {
        parser_engine: "nntmux-rust-nntp-header-comparison",
        schema_version: 1,
        comparison_basis: "case-insensitive field names paired by occurrence index; raw and unfolded values compared by full byte length and SHA-256",
        identity_match,
        same_article_comparison_status: match identity_match {
            Some(true) => "eligible_same_article",
            Some(false) => "identity_mismatch",
            None => "identity_unavailable",
        },
        parsed_field_comparison_status: if fields_complete {
            "complete"
        } else {
            "incomplete_or_malformed"
        },
        left_status: left_metadata.status,
        right_status: right_metadata.status,
        left_field_count: left_metadata.field_count,
        right_field_count: right_metadata.field_count,
        left_field_occurrences_truncated: left_metadata.field_occurrences_truncated,
        right_field_occurrences_truncated: right_metadata.field_occurrences_truncated,
        left_raw_header_sha256: sha256_hex(left),
        right_raw_header_sha256: sha256_hex(right),
        raw_header_sha256_matches: (left.len() <= MAX_HEADER_BYTES
            && right.len() <= MAX_HEADER_BYTES)
            .then(|| sha256_hex(left) == sha256_hex(right)),
        left_malformed_line_count: left_metadata.malformed_line_count,
        right_malformed_line_count: right_metadata.malformed_line_count,
        left_orphan_continuation_count: left_metadata.orphan_continuation_count,
        right_orphan_continuation_count: right_metadata.orphan_continuation_count,
        field_occurrence_counts_complete: fields_complete,
        normalized_field_order_matches,
        exact_field_name_order_matches,
        fields,
    }
}

fn flush_field(
    pending: &mut PendingField,
    metadata: &mut HeaderMetadata,
    name_counts: &mut BTreeMap<String, usize>,
    first_value_hashes: &mut BTreeMap<String, String>,
    ordered_names: &mut Vec<u8>,
) {
    if pending.name.is_empty() {
        return;
    }
    if metadata.fields.len() >= MAX_HEADER_FIELDS {
        metadata.status = "field_budget_exceeded";
        metadata.field_occurrences_truncated = true;
        pending.name.clear();
        pending.value.clear();
        pending.raw_value.clear();
        pending.source_line_indices.clear();
        return;
    }

    let normalized_name = String::from_utf8(pending.name.clone())
        .ok()
        .map(|name| name.to_ascii_lowercase());
    let duplicate_occurrence_index = normalized_name.as_ref().map_or(0, |name| {
        let count = name_counts.entry(name.clone()).or_default();
        let occurrence = *count;
        *count += 1;
        occurrence
    });
    let value_sha256 = sha256_hex(&pending.value);
    if duplicate_occurrence_index > 0 {
        if !metadata
            .duplicate_field_names
            .iter()
            .any(|name| Some(name) == normalized_name.as_ref())
            && let Some(name) = normalized_name.as_ref()
        {
            metadata.duplicate_field_names.push(name.clone());
        }
        if first_value_hashes
            .get(normalized_name.as_ref().unwrap())
            .is_some_and(|first| first != &value_sha256)
            && !metadata
                .conflicting_duplicate_field_names
                .iter()
                .any(|name| Some(name) == normalized_name.as_ref())
        {
            metadata
                .conflicting_duplicate_field_names
                .push(normalized_name.clone().unwrap());
        }
    } else if let Some(name) = normalized_name.as_ref() {
        first_value_hashes.insert(name.clone(), value_sha256.clone());
    }

    ordered_names.extend_from_slice(&(pending.name.len() as u32).to_le_bytes());
    ordered_names.extend_from_slice(&pending.name);
    let retained_name = &pending.name[..pending.name.len().min(MAX_RETAINED_NAME_BYTES)];
    let retained_value = &pending.value[..pending.value.len().min(MAX_RETAINED_VALUE_BYTES)];
    let xref = (normalized_name.as_deref() == Some("xref")).then(|| parse_xref(&pending.value));
    let newsgroups = (normalized_name.as_deref() == Some("newsgroups"))
        .then(|| parse_newsgroups(&pending.value));
    let lines = (normalized_name.as_deref() == Some("lines")).then(|| {
        parse_lines_field(
            &pending.value,
            pending.continuation_line_count > 0,
            pending.raw_value.ends_with(b"\r\n"),
        )
    });
    metadata.fields.push(HeaderField {
        field_index: metadata.fields.len(),
        name: String::from_utf8_lossy(&pending.name).into_owned(),
        name_byte_length: pending.name.len(),
        name_hex: hex(retained_name),
        name_hex_truncated: retained_name.len() < pending.name.len(),
        name_syntax_valid: valid_field_name(&pending.name),
        normalized_name,
        duplicate_occurrence_index,
        duplicate_value_conflicts: duplicate_occurrence_index > 0
            && first_value_hashes
                .get(&normalized_name_ref(&pending.name))
                .is_some_and(|first| first != &value_sha256),
        source_line_indices: pending.source_line_indices.clone(),
        continuation_line_count: pending.continuation_line_count,
        final_line_terminated: pending.final_line_terminated,
        final_line_crlf_terminated: pending.raw_value.ends_with(b"\r\n"),
        lines,
        raw_value_byte_length: pending.raw_value.len(),
        raw_value_sha256: sha256_hex(&pending.raw_value),
        value_byte_length: pending.value.len(),
        value_sha256,
        value_utf8_valid: std::str::from_utf8(&pending.value).is_ok(),
        value_preview_utf8: std::str::from_utf8(retained_value).ok().map(str::to_owned),
        value_preview_hex: hex(retained_value),
        value_preview_truncated: retained_value.len() < pending.value.len(),
        newsgroups,
        xref,
    });
    metadata.field_count = metadata.fields.len();
    pending.name.clear();
    pending.value.clear();
    pending.raw_value.clear();
    pending.source_line_indices.clear();
    pending.continuation_line_count = 0;
    pending.final_line_terminated = false;
}

fn parse_lines_field(
    value: &[u8],
    continuation_present: bool,
    crlf_terminated: bool,
) -> LinesFieldMetadata {
    let required_separator_space_valid = value.first() == Some(&b' ');
    let mut result = LinesFieldMetadata {
        status: "malformed",
        required_separator_space_valid,
        continuation_present,
        digit_count: 0,
        leading_zero_count: 0,
        declared_body_line_count: None,
        syntax_valid: false,
        obsolete_header: true,
    };
    if !crlf_terminated {
        result.status = "non_crlf_terminated";
        return result;
    }
    if continuation_present || !required_separator_space_valid {
        return result;
    }
    let after_space = &value[1..];
    let start = after_space
        .iter()
        .position(|byte| !matches!(*byte, b' ' | b'\t'))
        .unwrap_or(after_space.len());
    let token_and_tail = &after_space[start..];
    let digit_end = token_and_tail
        .iter()
        .position(|byte| !byte.is_ascii_digit())
        .unwrap_or(token_and_tail.len());
    if digit_end == 0
        || !token_and_tail[digit_end..]
            .iter()
            .all(|byte| matches!(*byte, b' ' | b'\t'))
    {
        return result;
    }
    let digits = &token_and_tail[..digit_end];
    result.digit_count = digits.len();
    result.leading_zero_count = digits.iter().take_while(|&&byte| byte == b'0').count();
    result.syntax_valid = true;
    let mut value = 0u64;
    for digit in digits {
        let Some(next) = value
            .checked_mul(10)
            .and_then(|current| current.checked_add(u64::from(*digit - b'0')))
        else {
            result.status = "numeric_overflow";
            return result;
        };
        value = next;
    }
    result.status = "complete";
    result.declared_body_line_count = Some(value);
    result
}

fn parse_newsgroups(value: &[u8]) -> NewsgroupsMetadata {
    let required_separator_space_valid = value.first() == Some(&b' ');
    let mut groups = Vec::new();
    let mut group_count = 0;
    let mut malformed_group_count = 0;
    let mut duplicate_group_count = 0;
    let mut duplicate_group_indices_truncated = false;
    let mut duplicate_group_indices = Vec::new();
    let mut first_group_indices = BTreeMap::<Vec<u8>, usize>::new();
    for raw_group in value.split(|byte| *byte == b',') {
        let group = trim_ascii_whitespace(raw_group);
        let index = group_count;
        group_count += 1;
        if !valid_newsgroup(group) {
            malformed_group_count += 1;
        } else if first_group_indices.contains_key(group) {
            duplicate_group_count += 1;
            if duplicate_group_indices.len() < MAX_XREF_LOCATIONS {
                duplicate_group_indices.push(index);
            } else {
                duplicate_group_indices_truncated = true;
            }
        } else {
            first_group_indices.insert(group.to_vec(), index);
        }
        if groups.len() < MAX_XREF_LOCATIONS {
            groups.push(xref_token(group));
        }
    }
    let groups_truncated = group_count > MAX_XREF_LOCATIONS;
    let status = if groups_truncated {
        "group_limit_exceeded"
    } else if !required_separator_space_valid || group_count == 0 || malformed_group_count > 0 {
        "malformed"
    } else {
        "complete"
    };

    NewsgroupsMetadata {
        status,
        required_separator_space_valid,
        group_count,
        malformed_group_count,
        groups_truncated,
        duplicate_group_count,
        duplicate_group_indices_truncated,
        duplicate_group_indices,
        groups,
    }
}

fn trim_ascii_whitespace(mut bytes: &[u8]) -> &[u8] {
    while matches!(bytes.first(), Some(b' ' | b'\t')) {
        bytes = &bytes[1..];
    }
    while matches!(bytes.last(), Some(b' ' | b'\t')) {
        bytes = &bytes[..bytes.len() - 1];
    }
    bytes
}

fn relate_newsgroups_to_xref(
    fields: &[HeaderField],
    field_occurrence_counts_complete: bool,
) -> NewsgroupsXrefRelationship {
    let newsgroups_fields = fields
        .iter()
        .filter(|field| field.normalized_name.as_deref() == Some("newsgroups"))
        .collect::<Vec<_>>();
    let xref_fields = fields
        .iter()
        .filter(|field| field.normalized_name.as_deref() == Some("xref"))
        .collect::<Vec<_>>();
    if !field_occurrence_counts_complete {
        return NewsgroupsXrefRelationship {
            status: "truncated",
            comparison_basis: "exact_newsgroup_octets_case_sensitive",
            newsgroups_field_occurrence_count: newsgroups_fields.len(),
            xref_field_occurrence_count: xref_fields.len(),
            field_occurrence_counts_complete: false,
            common_group_count: 0,
            newsgroups_only_count: 0,
            xref_only_count: 0,
            result_lists_truncated: false,
            common_groups: Vec::new(),
            newsgroups_only_groups: Vec::new(),
            xref_only_groups: Vec::new(),
        };
    }
    if newsgroups_fields.len() != 1 || xref_fields.len() != 1 {
        return unavailable_group_relationship(
            newsgroups_fields.len(),
            xref_fields.len(),
            field_occurrence_counts_complete,
        );
    }

    let newsgroups = newsgroups_fields[0].newsgroups.as_ref().unwrap();
    let xref = xref_fields[0].xref.as_ref().unwrap();
    if newsgroups.status == "malformed" || xref.status == "malformed" {
        return NewsgroupsXrefRelationship {
            status: "malformed",
            comparison_basis: "exact_newsgroup_octets_case_sensitive",
            newsgroups_field_occurrence_count: 1,
            xref_field_occurrence_count: 1,
            field_occurrence_counts_complete,
            common_group_count: 0,
            newsgroups_only_count: 0,
            xref_only_count: 0,
            result_lists_truncated: false,
            common_groups: Vec::new(),
            newsgroups_only_groups: Vec::new(),
            xref_only_groups: Vec::new(),
        };
    }
    if newsgroups.groups_truncated
        || xref.locations_truncated
        || newsgroups.groups.iter().any(|group| group.text.is_none())
        || xref.locations.iter().any(|location| {
            location
                .newsgroup
                .as_ref()
                .is_some_and(|group| group.text.is_none())
        })
    {
        return NewsgroupsXrefRelationship {
            status: "truncated",
            comparison_basis: "exact_newsgroup_octets_case_sensitive",
            newsgroups_field_occurrence_count: 1,
            xref_field_occurrence_count: 1,
            field_occurrence_counts_complete,
            common_group_count: 0,
            newsgroups_only_count: 0,
            xref_only_count: 0,
            result_lists_truncated: false,
            common_groups: Vec::new(),
            newsgroups_only_groups: Vec::new(),
            xref_only_groups: Vec::new(),
        };
    }

    let posted_groups = newsgroups
        .groups
        .iter()
        .filter_map(|group| group.text.as_deref().map(|text| (text.as_bytes(), group)))
        .collect::<BTreeMap<_, _>>();
    let filed_groups = xref
        .locations
        .iter()
        .filter_map(|location| {
            location
                .newsgroup
                .as_ref()
                .and_then(|group| group.text.as_deref())
                .map(|text| (text.as_bytes(), location.newsgroup.as_ref().unwrap()))
        })
        .collect::<BTreeMap<_, _>>();
    let common_names = posted_groups
        .keys()
        .filter(|group| filed_groups.contains_key(*group))
        .copied()
        .collect::<Vec<_>>();
    let posted_only_names = posted_groups
        .keys()
        .filter(|group| !filed_groups.contains_key(*group))
        .copied()
        .collect::<Vec<_>>();
    let filed_only_names = filed_groups
        .keys()
        .filter(|group| !posted_groups.contains_key(*group))
        .copied()
        .collect::<Vec<_>>();
    let result_lists_truncated = common_names.len() > MAX_XREF_LOCATIONS
        || posted_only_names.len() > MAX_XREF_LOCATIONS
        || filed_only_names.len() > MAX_XREF_LOCATIONS;
    let bounded_tokens = |names: &[&[u8]], source: &BTreeMap<&[u8], &XrefToken>| {
        names
            .iter()
            .take(MAX_XREF_LOCATIONS)
            .filter_map(|name| source.get(name).map(|token| (*token).clone()))
            .collect::<Vec<_>>()
    };

    NewsgroupsXrefRelationship {
        status: if result_lists_truncated {
            "relationship_limit_exceeded"
        } else {
            "complete"
        },
        comparison_basis: "exact_newsgroup_octets_case_sensitive",
        newsgroups_field_occurrence_count: 1,
        xref_field_occurrence_count: 1,
        field_occurrence_counts_complete,
        common_group_count: common_names.len(),
        newsgroups_only_count: posted_only_names.len(),
        xref_only_count: filed_only_names.len(),
        result_lists_truncated,
        common_groups: bounded_tokens(&common_names, &posted_groups),
        newsgroups_only_groups: bounded_tokens(&posted_only_names, &posted_groups),
        xref_only_groups: bounded_tokens(&filed_only_names, &filed_groups),
    }
}

fn unavailable_group_relationship(
    newsgroups_field_occurrence_count: usize,
    xref_field_occurrence_count: usize,
    field_occurrence_counts_complete: bool,
) -> NewsgroupsXrefRelationship {
    NewsgroupsXrefRelationship {
        status: if newsgroups_field_occurrence_count > 1 || xref_field_occurrence_count > 1 {
            "ambiguous"
        } else {
            "unavailable"
        },
        comparison_basis: "exact_newsgroup_octets_case_sensitive",
        newsgroups_field_occurrence_count,
        xref_field_occurrence_count,
        field_occurrence_counts_complete,
        common_group_count: 0,
        newsgroups_only_count: 0,
        xref_only_count: 0,
        result_lists_truncated: false,
        common_groups: Vec::new(),
        newsgroups_only_groups: Vec::new(),
        xref_only_groups: Vec::new(),
    }
}

fn parse_xref(value: &[u8]) -> XrefMetadata {
    let required_separator_space_valid = value.first() == Some(&b' ');
    let mut tokens = value
        .split(|byte| matches!(byte, b' ' | b'\t'))
        .filter(|token| !token.is_empty());
    let server_bytes = tokens.next();
    let server_name = server_bytes.map(xref_token);
    let server_name_syntax_valid = server_bytes.map(valid_xref_server_name);
    let mut locations = Vec::new();
    let mut location_count = 0;
    let mut malformed_location_count = 0;
    let mut duplicate_newsgroup_location_indices = Vec::new();
    let mut conflicting_newsgroup_location_indices = Vec::new();
    let mut duplicate_newsgroup_location_count = 0;
    let mut conflicting_newsgroup_location_count = 0;
    let mut relationship_indices_truncated = false;
    let mut first_locations = BTreeMap::<Vec<u8>, Vec<u8>>::new();

    for raw in tokens {
        let index = location_count;
        location_count += 1;
        let (newsgroup, locator) = raw
            .iter()
            .position(|byte| *byte == b':')
            .map(|colon| (&raw[..colon], &raw[colon + 1..]))
            .unwrap_or((raw, &[]));
        let has_separator = newsgroup.len() < raw.len();
        let syntax_valid =
            has_separator && valid_newsgroup(newsgroup) && valid_article_locator(locator);
        if !syntax_valid {
            malformed_location_count += 1;
        }
        if valid_newsgroup(newsgroup) && has_separator {
            if let Some(first_locator) = first_locations.get(newsgroup) {
                duplicate_newsgroup_location_count += 1;
                if duplicate_newsgroup_location_indices.len() < MAX_XREF_LOCATIONS {
                    duplicate_newsgroup_location_indices.push(index);
                } else {
                    relationship_indices_truncated = true;
                }
                if first_locator.as_slice() != locator {
                    conflicting_newsgroup_location_count += 1;
                    if conflicting_newsgroup_location_indices.len() < MAX_XREF_LOCATIONS {
                        conflicting_newsgroup_location_indices.push(index);
                    } else {
                        relationship_indices_truncated = true;
                    }
                }
            } else {
                first_locations.insert(newsgroup.to_vec(), locator.to_vec());
            }
        }
        if locations.len() < MAX_XREF_LOCATIONS {
            locations.push(XrefLocation {
                index,
                raw: xref_token(raw),
                newsgroup: has_separator.then(|| xref_token(newsgroup)),
                article_locator: has_separator.then(|| xref_token(locator)),
                syntax_valid,
                article_locator_is_decimal: has_separator
                    .then(|| !locator.is_empty() && locator.iter().all(u8::is_ascii_digit)),
            });
        }
    }

    let locations_truncated = location_count > MAX_XREF_LOCATIONS;
    let status = if locations_truncated {
        "location_limit_exceeded"
    } else if server_bytes.is_none()
        || !required_separator_space_valid
        || server_name_syntax_valid == Some(false)
        || location_count == 0
        || malformed_location_count > 0
    {
        "malformed"
    } else {
        "complete"
    };

    XrefMetadata {
        status,
        comparison_basis: "exact_newsgroup_octets_case_sensitive",
        required_separator_space_valid,
        server_name,
        server_name_syntax_valid,
        location_count,
        malformed_location_count,
        locations_truncated,
        duplicate_newsgroup_location_count,
        conflicting_newsgroup_location_count,
        relationship_indices_truncated,
        locations,
        duplicate_newsgroup_location_indices,
        conflicting_newsgroup_location_indices,
    }
}

fn xref_token(bytes: &[u8]) -> XrefToken {
    let preview = &bytes[..bytes.len().min(MAX_XREF_TOKEN_PREVIEW_BYTES)];
    XrefToken {
        text: (bytes.len() <= MAX_XREF_TOKEN_TEXT_BYTES)
            .then(|| std::str::from_utf8(bytes).ok().map(str::to_owned))
            .flatten(),
        byte_length: bytes.len(),
        sha256: sha256_hex(bytes),
        preview_hex: hex(preview),
        preview_truncated: preview.len() < bytes.len(),
        ascii_valid: bytes.is_ascii(),
    }
}

fn valid_xref_server_name(bytes: &[u8]) -> bool {
    if bytes.is_empty() {
        return false;
    }
    let labels = bytes.split(|byte| *byte == b'.').collect::<Vec<_>>();
    if labels.len() == 1 {
        return labels[0]
            .iter()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_'));
    }
    labels.len() >= 2
        && labels.iter().all(|label| valid_domain_label(label))
        && labels
            .last()
            .is_some_and(|top| top.iter().any(u8::is_ascii_alphabetic) || top.contains(&b'-'))
}

fn valid_domain_label(bytes: &[u8]) -> bool {
    !bytes.is_empty()
        && bytes[0].is_ascii_alphanumeric()
        && bytes[bytes.len() - 1].is_ascii_alphanumeric()
        && bytes
            .iter()
            .all(|byte| byte.is_ascii_alphanumeric() || *byte == b'-')
}

fn valid_newsgroup(bytes: &[u8]) -> bool {
    !bytes.is_empty()
        && bytes.split(|byte| *byte == b'.').all(|component| {
            !component.is_empty()
                && component
                    .iter()
                    .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'+' | b'-' | b'_'))
        })
}

fn valid_article_locator(bytes: &[u8]) -> bool {
    !bytes.is_empty()
        && bytes.iter().all(|byte| {
            (0x21..=0x27).contains(byte)
                || (0x29..=0x3a).contains(byte)
                || (0x3c..=0x7e).contains(byte)
        })
}

fn normalized_name_ref(name: &[u8]) -> String {
    String::from_utf8_lossy(name).to_ascii_lowercase()
}

fn valid_field_name(name: &[u8]) -> bool {
    !name.is_empty()
        && name
            .iter()
            .all(|byte| (33..=57).contains(byte) || (59..=126).contains(byte))
}

fn sha256_hex(bytes: &[u8]) -> String {
    let digest = Sha256::digest(bytes);
    hex(&digest)
}

fn hex(bytes: &[u8]) -> String {
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let mut output = String::with_capacity(bytes.len().saturating_mul(2));
    for byte in bytes {
        output.push(HEX[(byte >> 4) as usize] as char);
        output.push(HEX[(byte & 0x0f) as usize] as char);
    }
    output
}

#[cfg(test)]
mod tests {
    use super::{compare, parse};

    #[test]
    fn retains_ordered_duplicate_fields_and_unfolded_values() {
        let metadata = parse(b"Path: first\r\n\tsecond\r\nPath: third\nSubject: a:b\r\n\r\nbody");

        assert_eq!(metadata.status, "complete");
        assert_eq!(metadata.field_count, 3);
        assert_eq!(metadata.fields[0].name, "Path");
        assert_eq!(metadata.fields[0].normalized_name, Some("path".to_owned()));
        assert_eq!(metadata.fields[0].source_line_indices, vec![1, 2]);
        assert_eq!(metadata.fields[0].continuation_line_count, 1);
        assert_eq!(
            metadata.fields[0].value_preview_utf8.as_deref(),
            Some(" first\tsecond")
        );
        assert_eq!(metadata.fields[1].name, "Path");
        assert_eq!(
            metadata.fields[1].value_preview_utf8.as_deref(),
            Some(" third")
        );
        assert_eq!(
            metadata.fields[2].value_preview_utf8.as_deref(),
            Some(" a:b")
        );
        assert_eq!(metadata.duplicate_field_names, vec!["path"]);
        assert_eq!(metadata.conflicting_duplicate_field_names, vec!["path"]);
    }

    #[test]
    fn compares_duplicate_raw_and_unfolded_values_without_collapsing_occurrences() {
        let comparison = compare(
            b"Path: first\r\nPath: second\r\n\r\n",
            b"Path: first\nPath: second\n\n",
            Some(true),
        );

        assert_eq!(
            comparison.same_article_comparison_status,
            "eligible_same_article"
        );
        assert_eq!(comparison.normalized_field_order_matches, Some(true));
        assert_eq!(comparison.fields[0].left_occurrence_count, 2);
        assert_eq!(comparison.fields[0].occurrence_count_matches, Some(true));
        assert_eq!(comparison.fields[0].raw_value_hashes_match, Some(false));
        assert_eq!(comparison.fields[0].unfolded_value_hashes_match, Some(true));
    }

    #[test]
    fn compares_header_name_order_case_insensitively_and_retains_exact_case_difference() {
        let comparison = compare(
            b"X-Test: value\r\n\r\n",
            b"x-test: value\r\n\r\n",
            Some(true),
        );

        assert_eq!(comparison.normalized_field_order_matches, Some(true));
        assert_eq!(comparison.exact_field_name_order_matches, Some(false));
        assert_eq!(comparison.fields[0].unfolded_value_hashes_match, Some(true));
    }

    #[test]
    fn does_not_claim_parsed_field_equality_when_malformed_lines_differ() {
        let comparison = compare(
            b"X-Test: value\r\nMalformed One\r\n\r\n",
            b"X-Test: value\r\nMalformed Two\r\n\r\n",
            Some(true),
        );

        assert_eq!(comparison.raw_header_sha256_matches, Some(false));
        assert_eq!(
            comparison.parsed_field_comparison_status,
            "incomplete_or_malformed"
        );
        assert_eq!(comparison.fields[0].unfolded_value_hashes_match, None);
        assert_eq!(comparison.normalized_field_order_matches, None);
    }

    #[test]
    fn withholds_order_and_value_match_claims_when_header_parse_is_incomplete() {
        let comparison = compare(
            b"X-Header: a\r\n\r\n",
            &vec![b'a'; super::MAX_HEADER_BYTES + 1],
            None,
        );

        assert_eq!(
            comparison.same_article_comparison_status,
            "identity_unavailable"
        );
        assert_eq!(comparison.normalized_field_order_matches, None);
        assert_eq!(comparison.right_status, "budget_exceeded");
        assert_eq!(comparison.fields[0].raw_value_hashes_match, None);
    }

    #[test]
    fn reports_orphan_folds_malformed_lines_and_preserves_missing_final_newline() {
        let metadata = parse(b"\tno owner\nBad Name: value\r\nX-Trace: ok");

        assert_eq!(metadata.status, "complete");
        assert_eq!(metadata.orphan_continuation_count, 1);
        assert_eq!(metadata.malformed_line_count, 1);
        assert_eq!(metadata.field_count, 1);
        assert_eq!(metadata.fields[0].name, "X-Trace");
        assert!(!metadata.fields[0].final_line_terminated);
        assert_eq!(metadata.fields[0].source_line_indices, vec![3]);
    }

    #[test]
    fn bounds_value_previews_but_hashes_the_full_unfolded_value() {
        let mut input = b"X-Long:".to_vec();
        input.extend(std::iter::repeat_n(b'a', 2048));

        let metadata = parse(&input);

        assert_eq!(metadata.fields[0].value_byte_length, 2048);
        assert_eq!(metadata.fields[0].value_preview_hex.len(), 512);
        assert!(metadata.fields[0].value_preview_truncated);
        assert_eq!(metadata.fields[0].value_sha256.len(), 64);
    }

    #[test]
    fn marks_header_input_over_the_limit_without_parsing_a_prefix() {
        let input = vec![b'x'; super::MAX_HEADER_BYTES + 1];

        let metadata = parse(&input);

        assert_eq!(metadata.status, "budget_exceeded");
        assert_eq!(metadata.field_count, 0);
        assert!(metadata.fields.is_empty());
    }

    #[test]
    fn accepts_a_header_terminator_on_the_last_allowed_line() {
        let mut input = b"Malformed\r\n".repeat(super::MAX_HEADER_LINES - 1);
        input.extend_from_slice(b"\r\n");

        let metadata = parse(&input);

        assert_eq!(metadata.line_count, super::MAX_HEADER_LINES);
        assert_eq!(
            metadata.header_terminator_line_index,
            Some(super::MAX_HEADER_LINES)
        );
        assert_eq!(metadata.status, "complete");
        assert!(!metadata.field_occurrences_truncated);
    }

    #[test]
    fn marks_truncation_when_a_line_follows_the_line_budget() {
        let input = b"Malformed\r\n".repeat(super::MAX_HEADER_LINES + 1);

        let metadata = parse(&input);

        assert_eq!(metadata.line_count, super::MAX_HEADER_LINES);
        assert_eq!(metadata.status, "line_budget_exceeded");
        assert!(metadata.field_occurrences_truncated);
    }

    #[test]
    fn parses_folded_xref_locations_and_preserves_opaque_locator_text() {
        let metadata = parse(
            b"Xref: news.edge.test alt.binaries.a:100\r\n\talt.binaries.b:opaque:part\r\n\r\n",
        );
        let json = serde_json::to_value(metadata).unwrap();
        let xref = &json["fields"][0]["xref"];

        assert_eq!(xref["status"], "complete");
        assert_eq!(xref["server_name"]["text"], "news.edge.test");
        assert_eq!(xref["location_count"], 2);
        assert_eq!(xref["locations"].as_array().unwrap().len(), 2);
        assert_eq!(xref["locations"][0]["newsgroup"]["text"], "alt.binaries.a");
        assert_eq!(xref["locations"][0]["article_locator"]["text"], "100");
        assert_eq!(xref["locations"][0]["article_locator_is_decimal"], true);
        assert_eq!(
            xref["locations"][1]["article_locator"]["text"],
            "opaque:part"
        );
        assert_eq!(xref["locations"][1]["article_locator_is_decimal"], false);
        assert_eq!(
            xref["comparison_basis"],
            "exact_newsgroup_octets_case_sensitive"
        );
    }

    #[test]
    fn reports_duplicate_xref_groups_and_malformed_locations() {
        let metadata =
            parse(b"Xref: news.test group.one:10 group.one:11 malformed group.two:abc\r\n");
        let json = serde_json::to_value(metadata).unwrap();
        let xref = &json["fields"][0]["xref"];

        assert_eq!(xref["status"], "malformed");
        assert_eq!(xref["location_count"], 4);
        assert_eq!(xref["malformed_location_count"], 1);
        assert_eq!(
            xref["duplicate_newsgroup_location_indices"],
            serde_json::json!([1])
        );
        assert_eq!(
            xref["conflicting_newsgroup_location_indices"],
            serde_json::json!([1])
        );
        assert_eq!(xref["locations"][3]["article_locator_is_decimal"], false);
    }

    #[test]
    fn bounds_retained_xref_locations_but_keeps_observed_count() {
        let mut input = b"Xref: news.test".to_vec();
        for index in 0..600 {
            input.extend_from_slice(format!(" g{index}:{}", index + 1).as_bytes());
        }
        let metadata = parse(&input);
        let json = serde_json::to_value(metadata).unwrap();
        let xref = &json["fields"][0]["xref"];

        assert_eq!(xref["location_count"], 600);
        assert!(xref["locations"].as_array().unwrap().len() <= 512);
        assert!(xref["locations"].as_array().unwrap().len() < 600);
        assert!(xref["locations_truncated"].as_bool().unwrap());
        assert_eq!(xref["status"], "location_limit_exceeded");
    }

    #[test]
    fn parses_xref_beyond_generic_preview_and_bounds_relationship_indices() {
        let long_group = format!("alt.binaries.{}", "x".repeat(150));
        let mut input = format!("Xref: news.test {}:1", long_group).into_bytes();
        for index in 0..700 {
            input.extend_from_slice(format!(" {long_group}:{}", index + 2).as_bytes());
        }

        let metadata = parse(&input);
        let json = serde_json::to_value(metadata).unwrap();
        let xref = &json["fields"][0]["xref"];

        assert!(
            json["fields"][0]["value_preview_truncated"]
                .as_bool()
                .unwrap()
        );
        assert_eq!(xref["location_count"], 701);
        assert_eq!(xref["duplicate_newsgroup_location_count"], 700);
        assert_eq!(xref["conflicting_newsgroup_location_count"], 700);
        assert!(
            xref["duplicate_newsgroup_location_indices"]
                .as_array()
                .unwrap()
                .len()
                <= 512
        );
        assert!(xref["relationship_indices_truncated"].as_bool().unwrap());
        assert_eq!(xref["locations"][0]["newsgroup"]["text"], long_group);
    }

    #[test]
    fn validates_xref_server_and_location_syntax_without_requiring_decimal_locators() {
        for (header, expected_status) in [
            (
                b"Xref: legacy-host group.one:opaque:part\r\n".as_slice(),
                "complete",
            ),
            (b"Xref: news..test group.one:1\r\n".as_slice(), "malformed"),
            (b"Xref: news.test group..one:1\r\n".as_slice(), "malformed"),
            (
                b"Xref: news.test group.one:bad;locator\r\n".as_slice(),
                "malformed",
            ),
            (
                b"Xref: news.test group.one:bad(locator\r\n".as_slice(),
                "malformed",
            ),
            (b"Xref: news.test\r\n".as_slice(), "malformed"),
            (b"Xref:news.test group.one:1\r\n".as_slice(), "malformed"),
        ] {
            let json = serde_json::to_value(parse(header)).unwrap();
            assert_eq!(json["fields"][0]["xref"]["status"], expected_status);
        }
    }

    #[test]
    fn compares_posted_newsgroups_with_server_filed_xref_groups_as_observations() {
        let metadata = parse(
            b"Newsgroups: alt.posted.one, alt.shared\r\nXref: news.test alt.shared:17 alt.filed.only:29\r\n",
        );
        let json = serde_json::to_value(metadata).unwrap();
        let relationship = &json["newsgroups_xref_relationship"];

        assert_eq!(relationship["status"], "complete");
        assert_eq!(relationship["common_group_count"], 1);
        assert_eq!(relationship["newsgroups_only_count"], 1);
        assert_eq!(relationship["xref_only_count"], 1);
        assert_eq!(relationship["common_groups"][0]["text"], "alt.shared");
        assert_eq!(
            relationship["newsgroups_only_groups"][0]["text"],
            "alt.posted.one"
        );
        assert_eq!(
            relationship["xref_only_groups"][0]["text"],
            "alt.filed.only"
        );
    }

    #[test]
    fn withholds_group_relationship_when_later_field_occurrences_are_truncated() {
        let mut input = b"Newsgroups: alt.one\r\nXref: news.test alt.one:1\r\n".to_vec();
        for _ in 0..super::MAX_HEADER_FIELDS - 2 {
            input.extend_from_slice(b"X-Other: value\r\n");
        }
        input.extend_from_slice(b"Newsgroups: alt.two\r\n\r\n");

        let metadata = parse(&input);
        let json = serde_json::to_value(metadata).unwrap();
        let relationship = &json["newsgroups_xref_relationship"];

        assert_eq!(json["field_occurrences_truncated"], true);
        assert_eq!(relationship["status"], "truncated");
        assert_eq!(relationship["field_occurrence_counts_complete"], false);
        assert_eq!(relationship["common_group_count"], 0);
        assert_eq!(relationship["common_groups"].as_array().unwrap().len(), 0);
    }

    #[test]
    fn keeps_group_relationship_complete_at_the_exact_field_limit() {
        let mut input = b"Newsgroups: alt.one\r\nXref: news.test alt.one:1\r\n".to_vec();
        for _ in 0..super::MAX_HEADER_FIELDS - 2 {
            input.extend_from_slice(b"X-Other: value\r\n");
        }
        input.extend_from_slice(b"\r\n");

        let metadata = parse(&input);
        let json = serde_json::to_value(metadata).unwrap();

        assert_eq!(json["field_count"], super::MAX_HEADER_FIELDS);
        assert_eq!(json["field_occurrences_truncated"], false);
        assert_eq!(json["newsgroups_xref_relationship"]["status"], "complete");
        assert_eq!(
            json["newsgroups_xref_relationship"]["field_occurrence_counts_complete"],
            true
        );
    }

    #[test]
    fn reports_duplicate_newsgroup_entries_without_collapsing_the_source_order() {
        let json = serde_json::to_value(parse(
            b"Newsgroups: alt.one, alt.two, alt.one\r\nXref: news.test alt.one:1 alt.two:2\r\n",
        ))
        .unwrap();

        assert_eq!(json["fields"][0]["newsgroups"]["group_count"], 3);
        assert_eq!(json["fields"][0]["newsgroups"]["duplicate_group_count"], 1);
        assert_eq!(
            json["fields"][0]["newsgroups"]["duplicate_group_indices"],
            serde_json::json!([2])
        );
        assert_eq!(
            json["newsgroups_xref_relationship"]["common_group_count"],
            2
        );
    }

    #[test]
    fn withholds_newsgroups_xref_comparison_for_ambiguous_or_malformed_fields() {
        let ambiguous = parse(
            b"Newsgroups: alt.one\r\nXref: news.test alt.one:1\r\nXref: other.test alt.one:2\r\n",
        );
        let ambiguous_json = serde_json::to_value(ambiguous).unwrap();
        assert_eq!(
            ambiguous_json["newsgroups_xref_relationship"]["status"],
            "ambiguous"
        );

        let malformed = parse(b"Newsgroups: alt..one\r\nXref: news.test alt.one:1\r\n");
        let malformed_json = serde_json::to_value(malformed).unwrap();
        assert_eq!(
            malformed_json["newsgroups_xref_relationship"]["status"],
            "malformed"
        );
    }

    #[test]
    fn validates_required_newsgroups_separator_space() {
        let missing = serde_json::to_value(parse(
            b"Newsgroups:alt.one\r\nXref: news.test alt.one:1\r\n",
        ))
        .unwrap();
        let tab_only = serde_json::to_value(parse(
            b"Newsgroups:\talt.one\r\nXref: news.test alt.one:1\r\n",
        ))
        .unwrap();
        let space_then_tab = serde_json::to_value(parse(
            b"Newsgroups: \talt.one\r\nXref: news.test alt.one:1\r\n",
        ))
        .unwrap();
        let folded = serde_json::to_value(parse(
            b"Newsgroups:\r\n alt.one\r\nXref: news.test alt.one:1\r\n",
        ))
        .unwrap();

        for malformed in [&missing, &tab_only] {
            assert_eq!(malformed["fields"][0]["newsgroups"]["status"], "malformed");
            assert_eq!(
                malformed["fields"][0]["newsgroups"]["required_separator_space_valid"],
                false
            );
            assert_eq!(
                malformed["newsgroups_xref_relationship"]["status"],
                "malformed"
            );
        }
        for valid in [&space_then_tab, &folded] {
            assert_eq!(valid["fields"][0]["newsgroups"]["status"], "complete");
            assert_eq!(
                valid["fields"][0]["newsgroups"]["required_separator_space_valid"],
                true
            );
            assert_eq!(valid["newsgroups_xref_relationship"]["status"], "complete");
        }
    }
}
