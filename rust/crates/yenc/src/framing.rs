use serde::Serialize;
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;

pub const MAX_INPUT_BYTES: usize = 67_108_864;
const MAX_CONTROL_LINES: usize = 256;
const MAX_FIELDS_PER_LINE: usize = 64;
const MAX_FIELD_NAME_BYTES: usize = 128;
const MAX_RECORDED_OVERSIZED_NAMES_PER_LINE: usize = 64;
const MAX_RETAINED_VALUE_BYTES: usize = 128;
const MAX_RETAINED_CONTROL_LINE_BYTES: usize = 512;

#[derive(Debug, Serialize)]
pub struct YencFramingMetadata {
    pub parser_engine: &'static str,
    pub input_bytes: usize,
    pub control_line_count: usize,
    pub frame_count: usize,
    pub frames_truncated: bool,
    pub control_lines_truncated: bool,
    pub frames: Vec<YencFrame>,
    pub lines: Vec<ControlLine>,
}

#[derive(Debug, Serialize)]
pub struct YencFrame {
    pub frame_index: usize,
    pub begin_byte_offset: usize,
    pub end_byte_offset: Option<usize>,
    pub begin_control_line_count: usize,
    pub part_control_line_count: usize,
    pub end_control_line_count: usize,
    pub terminated: bool,
    pub ybegin_part_number: Option<u64>,
    pub ybegin_file_size: Option<u64>,
    pub ybegin_total_parts: Option<u64>,
    pub ypart_begin_byte: Option<u64>,
    pub ypart_end_byte_inclusive: Option<u64>,
    pub yend_size: Option<u64>,
    pub yend_part_number: Option<u64>,
    pub yend_total_parts: Option<u64>,
    pub part_number_matches: Option<bool>,
    pub part_number_within_total: Option<bool>,
    pub total_parts_match: Option<bool>,
    pub expected_segment_size: Option<u64>,
    pub expected_segment_size_decimal: Option<String>,
    pub part_range_valid: Option<bool>,
    pub part_range_within_file: Option<bool>,
    pub ypart_follows_ybegin: Option<bool>,
    pub yend_size_matches_expected: Option<bool>,
    pub multipart_pcrc32_status: &'static str,
    pub size_relationship_status: &'static str,
}

#[derive(Debug, Serialize)]
pub struct ControlLine {
    pub kind: &'static str,
    pub frame_index: Option<usize>,
    pub line_index: usize,
    pub byte_offset: usize,
    pub line_bytes: usize,
    pub line_terminated: bool,
    pub line_sha256: String,
    pub line_raw_hex: String,
    pub line_raw_hex_truncated: bool,
    pub malformed_token_count: usize,
    pub oversized_field_name_count: usize,
    pub oversized_field_names: Vec<OversizedFieldName>,
    pub field_occurrences_truncated: bool,
    pub fields: Vec<FieldOccurrence>,
    pub duplicate_field_names: Vec<String>,
    pub conflicting_duplicate_field_names: Vec<String>,
}

#[derive(Debug, Serialize)]
pub struct OversizedFieldName {
    pub occurrence: usize,
    pub name_byte_offset: usize,
    pub name_bytes: usize,
    pub name_sha256: String,
}

#[derive(Debug, Serialize)]
pub struct FieldOccurrence {
    pub occurrence: usize,
    pub name: String,
    pub original_name: String,
    pub name_byte_offset: usize,
    pub value_byte_offset: usize,
    pub value_bytes: usize,
    pub value_sha256: String,
    pub value_hex: String,
    pub value_hex_truncated: bool,
    pub value_utf8: Option<String>,
    pub decimal_u64: Option<u64>,
    pub decimal_status: &'static str,
}

/// [`parse`] for untrusted input, refusing anything over the parser's byte budget.
pub fn parse_bounded(data: &[u8]) -> Option<YencFramingMetadata> {
    (data.len() <= MAX_INPUT_BYTES).then(|| parse(data))
}

pub fn parse(data: &[u8]) -> YencFramingMetadata {
    let mut lines = Vec::new();
    let mut control_line_count = 0usize;
    let mut frame_count = 0usize;
    let mut current_frame = None;
    let mut frames = Vec::new();
    let mut line_index = 0usize;
    let mut offset = 0usize;

    while offset < data.len() {
        let line_start = offset;
        let newline = data[offset..].iter().position(|byte| *byte == b'\n');
        let line_terminated = newline.is_some();
        let raw_end = newline.map_or(data.len(), |relative| offset + relative);
        let content_end = if raw_end > line_start && data[raw_end - 1] == b'\r' {
            raw_end - 1
        } else {
            raw_end
        };
        let line = &data[line_start..content_end];

        if let Some((kind, prefix_len)) = control_kind(line) {
            control_line_count = control_line_count.saturating_add(1);
            let frame_index = if kind == "ybegin" {
                frame_count = frame_count.saturating_add(1);
                current_frame = Some(frame_count);
                if frames.len() < MAX_CONTROL_LINES {
                    frames.push(YencFrame {
                        frame_index: frame_count,
                        begin_byte_offset: line_start,
                        end_byte_offset: None,
                        begin_control_line_count: 1,
                        part_control_line_count: 0,
                        end_control_line_count: 0,
                        terminated: false,
                        ybegin_part_number: None,
                        ybegin_file_size: None,
                        ybegin_total_parts: None,
                        ypart_begin_byte: None,
                        ypart_end_byte_inclusive: None,
                        yend_size: None,
                        yend_part_number: None,
                        yend_total_parts: None,
                        part_number_matches: None,
                        part_number_within_total: None,
                        total_parts_match: None,
                        expected_segment_size: None,
                        expected_segment_size_decimal: None,
                        part_range_valid: None,
                        part_range_within_file: None,
                        ypart_follows_ybegin: None,
                        yend_size_matches_expected: None,
                        multipart_pcrc32_status: "not-applicable",
                        size_relationship_status: "incomplete",
                    });
                }
                current_frame
            } else {
                current_frame
            };
            if kind == "ypart"
                && let Some(frame) = frame_index.and_then(|index| frames.get_mut(index - 1))
            {
                frame.part_control_line_count = frame.part_control_line_count.saturating_add(1);
            }
            if kind == "yend"
                && let Some(frame) = frame_index.and_then(|index| frames.get_mut(index - 1))
            {
                frame.end_byte_offset = Some(line_start);
                frame.end_control_line_count = frame.end_control_line_count.saturating_add(1);
                frame.terminated = true;
            }
            if lines.len() < MAX_CONTROL_LINES
                && let Some(control_line) = parse_control_line(
                    kind,
                    frame_index,
                    line_index,
                    line_start,
                    line_terminated,
                    line,
                    prefix_len,
                )
            {
                if let Some(frame) = frame_index.and_then(|index| frames.get_mut(index - 1)) {
                    match kind {
                        "ybegin" => frame.ybegin_part_number = unique_part_number(&control_line),
                        "yend" => frame.yend_part_number = unique_part_number(&control_line),
                        _ => {}
                    }
                    frame.part_number_matches = frame
                        .ybegin_part_number
                        .zip(frame.yend_part_number)
                        .map(|(begin, end)| begin == end);
                }
                lines.push(control_line);
            }
            if kind == "yend" {
                current_frame = None;
            }
        }

        line_index = line_index.saturating_add(1);
        offset = if line_terminated {
            raw_end + 1
        } else {
            raw_end
        };
    }

    for frame in &mut frames {
        derive_frame_size_relationship(frame, &lines);
    }

    YencFramingMetadata {
        parser_engine: "nntmux-rust-ffi",
        input_bytes: data.len(),
        control_line_count,
        frame_count,
        frames_truncated: frame_count > frames.len(),
        control_lines_truncated: control_line_count > lines.len(),
        frames,
        lines,
    }
}

fn control_kind(line: &[u8]) -> Option<(&'static str, usize)> {
    for (name, kind) in [
        (b"=ybegin".as_slice(), "ybegin"),
        (b"=ypart".as_slice(), "ypart"),
        (b"=yend".as_slice(), "yend"),
    ] {
        if line.len() >= name.len()
            && line[..name.len()].eq_ignore_ascii_case(name)
            && (line.len() == name.len() || matches!(line[name.len()], b' ' | b'\t'))
        {
            return Some((kind, name.len()));
        }
    }
    None
}

fn parse_control_line(
    kind: &'static str,
    frame_index: Option<usize>,
    line_index: usize,
    byte_offset: usize,
    line_terminated: bool,
    line: &[u8],
    prefix_len: usize,
) -> Option<ControlLine> {
    let mut fields = Vec::new();
    let mut first_values = BTreeMap::<String, (usize, String)>::new();
    let mut duplicate_field_names = Vec::new();
    let mut conflicting_duplicate_field_names = Vec::new();
    let mut malformed_token_count = 0usize;
    let mut oversized_field_name_count = 0usize;
    let mut oversized_field_names = Vec::new();
    let mut fields_truncated = false;
    let mut parsed_field_count = 0usize;
    let mut cursor = prefix_len;

    while cursor < line.len() {
        while cursor < line.len() && matches!(line[cursor], b' ' | b'\t') {
            cursor += 1;
        }
        if cursor == line.len() {
            break;
        }

        let token_start = cursor;
        let token_end = line[cursor..]
            .iter()
            .position(|byte| matches!(*byte, b' ' | b'\t'))
            .map_or(line.len(), |relative| cursor + relative);
        let Some(equal_relative) = line[cursor..token_end]
            .iter()
            .position(|byte| *byte == b'=')
        else {
            malformed_token_count = malformed_token_count.saturating_add(1);
            cursor = token_end;
            continue;
        };
        let equal = cursor + equal_relative;
        let original_name = &line[token_start..equal];
        if !valid_field_name(original_name) {
            malformed_token_count = malformed_token_count.saturating_add(1);
            while cursor < line.len() && !matches!(line[cursor], b' ' | b'\t') {
                cursor += 1;
            }
            continue;
        }
        if parsed_field_count >= MAX_FIELDS_PER_LINE {
            fields_truncated = true;
            break;
        }
        parsed_field_count = parsed_field_count.saturating_add(1);
        if original_name.len() > MAX_FIELD_NAME_BYTES {
            oversized_field_name_count = oversized_field_name_count.saturating_add(1);
            if oversized_field_names.len() < MAX_RECORDED_OVERSIZED_NAMES_PER_LINE {
                oversized_field_names.push(OversizedFieldName {
                    occurrence: parsed_field_count.saturating_sub(1),
                    name_byte_offset: byte_offset.saturating_add(token_start),
                    name_bytes: original_name.len(),
                    name_sha256: sha256_hex(original_name),
                });
            }
            cursor = equal.saturating_add(1);
            while cursor < line.len() && !matches!(line[cursor], b' ' | b'\t') {
                cursor += 1;
            }
            continue;
        }
        let value_start = equal + 1;
        let name = String::from_utf8_lossy(original_name).to_ascii_lowercase();
        let is_filename_field = name == "name";
        let value_end = if is_filename_field && kind == "ybegin" {
            line.len()
        } else {
            line[value_start..]
                .iter()
                .position(|byte| matches!(byte, b' ' | b'\t'))
                .map_or(line.len(), |relative| value_start + relative)
        };
        let value = &line[value_start..value_end];
        let value_hash = sha256_hex(value);
        if let Some((previous_bytes, previous_hash)) = first_values.get(&name) {
            if !duplicate_field_names
                .iter()
                .any(|candidate| candidate == &name)
            {
                duplicate_field_names.push(name.clone());
            }
            if (*previous_bytes != value.len() || previous_hash != &value_hash)
                && !conflicting_duplicate_field_names
                    .iter()
                    .any(|candidate| candidate == &name)
            {
                conflicting_duplicate_field_names.push(name.clone());
            }
        } else {
            first_values.insert(name.clone(), (value.len(), value_hash.clone()));
        }

        if fields.len() < MAX_FIELDS_PER_LINE {
            let numeric = if matches!(
                name.as_str(),
                "line" | "size" | "part" | "total" | "begin" | "end"
            ) {
                parse_decimal(value)
            } else {
                DecimalValue::NotApplicable
            };
            let retained_value = &value[..value.len().min(MAX_RETAINED_VALUE_BYTES)];
            fields.push(FieldOccurrence {
                occurrence: parsed_field_count.saturating_sub(1),
                name,
                original_name: String::from_utf8_lossy(original_name).into_owned(),
                name_byte_offset: byte_offset.saturating_add(token_start),
                value_byte_offset: byte_offset.saturating_add(value_start),
                value_bytes: value.len(),
                value_sha256: value_hash,
                value_hex: hex(retained_value),
                value_hex_truncated: retained_value.len() < value.len(),
                value_utf8: std::str::from_utf8(retained_value).ok().map(str::to_owned),
                decimal_u64: numeric.value(),
                decimal_status: numeric.status(),
            });
        } else {
            fields_truncated = true;
            break;
        }
        cursor = value_end;
        if is_filename_field {
            break;
        }
    }

    Some(ControlLine {
        kind,
        frame_index,
        line_index,
        byte_offset,
        line_bytes: line.len(),
        line_terminated,
        line_sha256: sha256_hex(line),
        line_raw_hex: hex(&line[..line.len().min(MAX_RETAINED_CONTROL_LINE_BYTES)]),
        line_raw_hex_truncated: line.len() > MAX_RETAINED_CONTROL_LINE_BYTES,
        malformed_token_count,
        oversized_field_name_count,
        oversized_field_names,
        field_occurrences_truncated: fields_truncated,
        fields,
        duplicate_field_names,
        conflicting_duplicate_field_names,
    })
}

enum DecimalValue {
    NotApplicable,
    Valid(u64),
    Empty,
    NonDecimal,
    Overflow,
}

impl DecimalValue {
    fn value(&self) -> Option<u64> {
        match self {
            Self::Valid(value) => Some(*value),
            Self::NotApplicable | Self::Empty | Self::NonDecimal | Self::Overflow => None,
        }
    }

    fn status(&self) -> &'static str {
        match self {
            Self::NotApplicable => "not-numeric-field",
            Self::Valid(_) => "valid-u64-decimal",
            Self::Empty => "empty",
            Self::NonDecimal => "non-decimal",
            Self::Overflow => "u64-overflow",
        }
    }
}

fn parse_decimal(value: &[u8]) -> DecimalValue {
    if value.is_empty() {
        return DecimalValue::Empty;
    }
    if !value.iter().all(u8::is_ascii_digit) {
        return DecimalValue::NonDecimal;
    }
    match std::str::from_utf8(value)
        .ok()
        .and_then(|text| text.parse::<u64>().ok())
    {
        Some(value) => DecimalValue::Valid(value),
        None => DecimalValue::Overflow,
    }
}

fn valid_field_name(name: &[u8]) -> bool {
    !name.is_empty()
        && name[0].is_ascii_alphabetic()
        && name
            .iter()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(*byte, b'_' | b'-'))
}

fn unique_part_number(control_line: &ControlLine) -> Option<u64> {
    match unique_field(Some(control_line), "part") {
        FieldLookup::Found(field) if field.decimal_status == "valid-u64-decimal" => {
            field.decimal_u64
        }
        _ => None,
    }
}

enum FieldLookup<'a> {
    Missing,
    Ambiguous,
    Found(&'a FieldOccurrence),
}

fn unique_field<'a>(control_line: Option<&'a ControlLine>, name: &str) -> FieldLookup<'a> {
    let Some(control_line) = control_line else {
        return FieldLookup::Missing;
    };
    if control_line.field_occurrences_truncated
        || control_line
            .conflicting_duplicate_field_names
            .iter()
            .any(|candidate| candidate == name)
    {
        return FieldLookup::Ambiguous;
    }

    let mut matching_fields = control_line
        .fields
        .iter()
        .filter(|field| field.name == name);
    let Some(field) = matching_fields.next() else {
        return FieldLookup::Missing;
    };
    // Occurrences remain visible. Equality uses full-value evidence, never the bounded preview
    // or a normalized numeric value (e.g. "1" and "01" are different declarations).
    if matching_fields.any(|other| {
        other.value_bytes != field.value_bytes || other.value_sha256 != field.value_sha256
    }) {
        return FieldLookup::Ambiguous;
    }

    FieldLookup::Found(field)
}

fn numeric_field(
    control_line: Option<&ControlLine>,
    name: &str,
) -> Result<Option<u64>, &'static str> {
    match unique_field(control_line, name) {
        FieldLookup::Missing => Ok(None),
        FieldLookup::Ambiguous => Err("ambiguous"),
        FieldLookup::Found(field) if field.decimal_status == "valid-u64-decimal" => {
            Ok(field.decimal_u64)
        }
        FieldLookup::Found(_) => Err("malformed"),
    }
}

fn hex_crc32_field(control_line: Option<&ControlLine>) -> &'static str {
    match unique_field(control_line, "pcrc32") {
        FieldLookup::Missing => "missing",
        FieldLookup::Ambiguous => "ambiguous",
        FieldLookup::Found(field) => {
            let Some(value) = field.value_utf8.as_deref() else {
                return "malformed";
            };
            if !field.value_hex_truncated
                && value.len() == 8
                && value.bytes().all(|byte| byte.is_ascii_hexdigit())
            {
                "present-hex"
            } else {
                "malformed"
            }
        }
    }
}

fn derive_frame_size_relationship(frame: &mut YencFrame, lines: &[ControlLine]) {
    let frame_lines: Vec<&ControlLine> = lines
        .iter()
        .filter(|line| line.frame_index == Some(frame.frame_index))
        .collect();
    let begin_lines: Vec<&ControlLine> = frame_lines
        .iter()
        .copied()
        .filter(|line| line.kind == "ybegin")
        .collect();
    let part_lines: Vec<&ControlLine> = frame_lines
        .iter()
        .copied()
        .filter(|line| line.kind == "ypart")
        .collect();
    let end_lines: Vec<&ControlLine> = frame_lines
        .iter()
        .copied()
        .filter(|line| line.kind == "yend")
        .collect();
    let begin_line = begin_lines.first().copied();
    let part_line = part_lines.first().copied();
    let end_line = end_lines.first().copied();

    let retained_count = begin_lines.len() + part_lines.len() + end_lines.len();
    let observed_count = frame
        .begin_control_line_count
        .saturating_add(frame.part_control_line_count)
        .saturating_add(frame.end_control_line_count);
    let mut ambiguous = retained_count != observed_count
        || begin_lines.len() != 1
        || end_lines.len() > 1
        || part_lines.len() > 1;
    let mut malformed = false;
    let mut incomplete = false;

    let has_part_field = begin_line.into_iter().chain(end_line).any(|line| {
        line.fields.iter().any(|field| field.name == "part")
            || line.duplicate_field_names.iter().any(|name| name == "part")
    });
    let multipart = has_part_field || !part_lines.is_empty();

    let mut read_number =
        |line: Option<&ControlLine>, name: &str, required: bool| match numeric_field(line, name) {
            Ok(Some(value)) => Some(value),
            Ok(None) => {
                if required {
                    incomplete = true;
                }
                None
            }
            Err("ambiguous") => {
                ambiguous = true;
                None
            }
            Err(_) => {
                malformed = true;
                None
            }
        };

    let begin_part = read_number(begin_line, "part", multipart);
    let file_size = read_number(begin_line, "size", true);
    // `total` was added in yEnc 1.2; older multipart posts may omit it.
    let begin_total = read_number(begin_line, "total", false);
    let ypart_begin = if multipart {
        read_number(part_line, "begin", true)
    } else {
        None
    };
    let ypart_end = if multipart {
        read_number(part_line, "end", true)
    } else {
        None
    };
    let yend_size = read_number(end_line, "size", true);
    let end_part = if multipart {
        read_number(end_line, "part", true)
    } else {
        None
    };
    let end_total = if multipart {
        read_number(end_line, "total", false)
    } else {
        None
    };

    frame.ybegin_part_number = begin_part;
    frame.ybegin_file_size = file_size;
    frame.ybegin_total_parts = begin_total;
    frame.ypart_begin_byte = ypart_begin;
    frame.ypart_end_byte_inclusive = ypart_end;
    frame.yend_size = yend_size;
    frame.yend_part_number = end_part;
    frame.yend_total_parts = end_total;
    frame.part_number_matches = begin_part.zip(end_part).map(|(begin, end)| begin == end);
    frame.part_number_within_total = begin_part
        .zip(begin_total)
        .map(|(part, total)| part >= 1 && total >= part);
    frame.total_parts_match = begin_total.zip(end_total).map(|(begin, end)| begin == end);
    frame.ypart_follows_ybegin = if multipart {
        begin_line
            .zip(part_line)
            .map(|(begin, part)| part.line_index == begin.line_index.saturating_add(1))
    } else {
        None
    };

    let mut expected_size = None;
    if multipart {
        match ypart_begin.zip(ypart_end) {
            Some((begin, end)) if begin >= 1 && end >= begin => {
                frame.part_range_valid = Some(true);
                expected_size = end
                    .checked_sub(begin)
                    .and_then(|length| length.checked_add(1));
                if expected_size.is_none() {
                    malformed = true;
                }
                frame.part_range_within_file = file_size.map(|size| end <= size);
                if frame.part_range_within_file == Some(false) {
                    malformed = true;
                }
            }
            Some(_) => {
                frame.part_range_valid = Some(false);
                frame.part_range_within_file = None;
                malformed = true;
            }
            None => {
                frame.part_range_valid = None;
                frame.part_range_within_file = None;
            }
        }
        frame.multipart_pcrc32_status = hex_crc32_field(end_line);
        match frame.multipart_pcrc32_status {
            "missing" => incomplete = true,
            "ambiguous" => ambiguous = true,
            "malformed" => malformed = true,
            _ => {}
        }
    } else {
        frame.part_range_valid = None;
        frame.part_range_within_file = None;
        frame.multipart_pcrc32_status = "not-required-single-part";
        expected_size = file_size;
    }

    frame.expected_segment_size = expected_size;
    frame.expected_segment_size_decimal = expected_size.map(|value| value.to_string());
    frame.yend_size_matches_expected = expected_size
        .zip(yend_size)
        .map(|(expected, observed)| expected == observed);
    if frame.part_number_matches.is_none() && multipart {
        incomplete = true;
    }
    if frame.part_number_within_total == Some(false) {
        malformed = true;
    }
    if frame.ypart_follows_ybegin == Some(false) {
        malformed = true;
    }
    if frame.yend_size_matches_expected.is_none() {
        incomplete = true;
    }

    frame.size_relationship_status = if ambiguous {
        "ambiguous"
    } else if malformed {
        "malformed"
    } else if incomplete || !frame.terminated {
        "incomplete"
    } else {
        "complete"
    };
}

fn sha256_hex(data: &[u8]) -> String {
    let mut hasher = Sha256::new();
    hasher.update(data);
    let digest = hasher.finalize();
    hex(&digest)
}

fn hex(data: &[u8]) -> String {
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let mut output = String::with_capacity(data.len().saturating_mul(2));
    for byte in data {
        output.push(HEX[(byte >> 4) as usize] as char);
        output.push(HEX[(byte & 0x0f) as usize] as char);
    }
    output
}

#[cfg(test)]
mod tests {
    use super::parse;

    #[test]
    fn accepts_byte_identical_fields_and_preserves_every_occurrence() {
        let metadata = parse(b"=ybegin part=1 part=1 total=2 total=2 line=128 size=10 size=10 name=file.bin\r\n=ypart begin=1 begin=1 end=5 end=5\r\ndata\r\n=yend size=5 size=5 part=1 part=1 pcrc32=abcdef12 pcrc32=abcdef12\r\n");
        let json = serde_json::to_value(metadata).unwrap();
        let frame = &json["frames"][0];
        assert_eq!(frame["size_relationship_status"], "complete");
        assert_eq!(frame["ybegin_part_number"], 1);
        assert_eq!(frame["yend_part_number"], 1);
        assert_eq!(frame["expected_segment_size"], 5);
        assert_eq!(frame["multipart_pcrc32_status"], "present-hex");
        for line in json["lines"].as_array().unwrap() {
            assert!(!line["duplicate_field_names"].as_array().unwrap().is_empty());
            assert!(
                line["conflicting_duplicate_field_names"]
                    .as_array()
                    .unwrap()
                    .is_empty()
            );
        }
        assert_eq!(json["lines"][2]["fields"].as_array().unwrap().len(), 6);
    }

    #[test]
    fn byte_distinct_equivalent_numbers_and_crc_case_remain_ambiguous() {
        let normal = "=ybegin part=1 total=2 line=128 size=10 name=file.bin\r\n=ypart begin=1 end=5\r\ndata\r\n=yend size=5 part=1 pcrc32=abcdef12\r\n";
        for input in [
            normal.replacen("part=1", "part=1 part=01", 1),
            normal.replace("begin=1", "begin=1 begin=01"),
            normal.replace("pcrc32=abcdef12", "pcrc32=abcdef12 pcrc32=ABCDEF12"),
        ] {
            let json = serde_json::to_value(parse(input.as_bytes())).unwrap();
            assert_eq!(json["frames"][0]["size_relationship_status"], "ambiguous");
        }
    }

    #[test]
    fn identical_duplicates_cannot_override_a_truncated_occurrence_scan() {
        for (occurrences, expected_status) in [(63, "complete"), (64, "ambiguous")] {
            let input = format!(
                "=ybegin {}size=10\r\n=ypart begin=1 end=5\r\ndata\r\n=yend size=5 part=1 pcrc32=abcdef12\r\n",
                "part=1 ".repeat(occurrences)
            );
            let json = serde_json::to_value(parse(input.as_bytes())).unwrap();
            assert_eq!(
                json["frames"][0]["size_relationship_status"],
                expected_status
            );
            assert_eq!(json["lines"][0]["fields"].as_array().unwrap().len(), 64);
            assert_eq!(
                json["lines"][0]["field_occurrences_truncated"],
                occurrences == 64
            );
        }
    }

    #[test]
    fn compares_duplicate_values_beyond_the_retained_preview() {
        let prefix = "0".repeat(129);
        for (last_digits, expected_status) in [("10", "complete"), ("11", "ambiguous")] {
            let input = format!(
                "=ybegin part=1 size={prefix}10 size={prefix}{last_digits}\r\n=ypart begin=1 end=5\r\ndata\r\n=yend size=5 part=1 pcrc32=abcdef12\r\n"
            );
            let json = serde_json::to_value(parse(input.as_bytes())).unwrap();
            assert_eq!(
                json["frames"][0]["size_relationship_status"],
                expected_status
            );
            let fields = json["lines"][0]["fields"].as_array().unwrap();
            assert_eq!(fields[1]["value_utf8"], fields[2]["value_utf8"]);
            assert_eq!(fields[1]["value_hex_truncated"], true);
        }
    }

    #[test]
    fn trailer_only_part_declarations_cannot_establish_single_part_size() {
        for (part, expected_part) in [
            ("1", Some(1)),
            ("0", Some(0)),
            ("", None),
            ("invalid", None),
        ] {
            let input = format!(
                "=ybegin line=128 size=3 name=x.rar\r\nklm\r\n=yend size=3 part={part} pcrc32=a3830348\r\n"
            );
            let metadata = parse(input.as_bytes());
            let json = serde_json::to_value(metadata).unwrap();
            let frame = &json["frames"][0];

            assert_eq!(json["control_line_count"], 2);
            assert_eq!(json["frame_count"], 1);
            assert_eq!(json["control_lines_truncated"], false);
            assert_eq!(json["frames_truncated"], false);
            assert_eq!(frame["part_control_line_count"], 0);
            assert_eq!(frame["yend_part_number"], serde_json::json!(expected_part));
            assert_eq!(frame["multipart_pcrc32_status"], "present-hex");
            assert_eq!(frame["expected_segment_size"], serde_json::Value::Null);
            assert_eq!(
                frame["expected_segment_size_decimal"],
                serde_json::Value::Null
            );
            assert_eq!(frame["part_range_valid"], serde_json::Value::Null);
            assert_eq!(frame["yend_size_matches_expected"], serde_json::Value::Null);
            if part == "1" {
                assert_eq!(frame["size_relationship_status"], "incomplete");
            } else {
                assert_ne!(frame["size_relationship_status"], "complete");
            }
            let trailer = &json["lines"][1];
            assert_eq!(trailer["kind"], "yend");
            assert_eq!(trailer["frame_index"], 1);
            assert_eq!(trailer["field_occurrences_truncated"], false);
            assert_eq!(trailer["fields"][1]["name"], "part");
            assert_eq!(trailer["fields"][1]["value_utf8"], part);
            assert_eq!(trailer["fields"][2]["name"], "pcrc32");
            assert_eq!(trailer["fields"][2]["value_utf8"], "a3830348");
        }
    }

    #[test]
    fn relates_multipart_range_file_size_and_trailer_size() {
        let metadata = parse(
            b"=ybegin part=1 total=2 line=128 size=10 name=file.bin\r\n=ypart begin=1 end=5\r\ndata\r\n=yend size=5 part=1 total=2 pcrc32=abcdef12\r\n",
        );
        let json = serde_json::to_value(metadata).unwrap();
        let frame = &json["frames"][0];

        assert_eq!(frame["expected_segment_size"], 5);
        assert_eq!(frame["part_range_valid"], true);
        assert_eq!(frame["part_range_within_file"], true);
        assert_eq!(frame["ypart_follows_ybegin"], true);
        assert_eq!(frame["yend_size_matches_expected"], true);
        assert_eq!(frame["size_relationship_status"], "complete");
    }

    #[test]
    fn reports_a_complete_but_mismatched_declared_trailer_size() {
        let metadata = parse(
            b"=ybegin part=1 total=2 line=128 size=10 name=file.bin\r\n=ypart begin=1 end=5\r\ndata\r\n=yend size=4 part=1 total=2 pcrc32=abcdef12\r\n",
        );
        let json = serde_json::to_value(metadata).unwrap();
        let frame = &json["frames"][0];

        assert_eq!(frame["expected_segment_size"], 5);
        assert_eq!(frame["yend_size_matches_expected"], false);
        assert_eq!(frame["size_relationship_status"], "complete");
    }

    #[test]
    fn withholds_size_relationship_for_duplicate_or_out_of_file_ranges() {
        let duplicate = parse(
            b"=ybegin part=1 total=2 line=128 size=10 name=file.bin\r\n=ypart begin=1 end=5 end=6\r\ndata\r\n=yend size=5 part=1 total=2 pcrc32=abcdef12\r\n",
        );
        let duplicate_json = serde_json::to_value(duplicate).unwrap();
        assert_eq!(
            duplicate_json["frames"][0]["size_relationship_status"],
            "ambiguous"
        );

        let outside = parse(
            b"=ybegin part=1 total=2 line=128 size=10 name=file.bin\r\n=ypart begin=1 end=11\r\ndata\r\n=yend size=11 part=1 total=2 pcrc32=abcdef12\r\n",
        );
        let outside_json = serde_json::to_value(outside).unwrap();
        assert_eq!(outside_json["frames"][0]["part_range_valid"], true);
        assert_eq!(outside_json["frames"][0]["part_range_within_file"], false);
        assert_eq!(
            outside_json["frames"][0]["size_relationship_status"],
            "malformed"
        );
    }

    #[test]
    fn treats_multiple_ypart_lines_as_ambiguous_and_missing_trailers_as_incomplete() {
        let multiple = parse(
            b"=ybegin part=1 total=2 line=128 size=10 name=file.bin\r\n=ypart begin=1 end=5\r\n=ypart begin=6 end=10\r\ndata\r\n=yend size=5 part=1 total=2 pcrc32=abcdef12\r\n",
        );
        let multiple_json = serde_json::to_value(multiple).unwrap();
        assert_eq!(
            multiple_json["frames"][0]["size_relationship_status"],
            "ambiguous"
        );

        let missing = parse(b"=ybegin line=128 size=5 name=file.bin\r\ndata\r\n");
        let missing_json = serde_json::to_value(missing).unwrap();
        assert_eq!(
            missing_json["frames"][0]["size_relationship_status"],
            "incomplete"
        );
        assert_eq!(
            missing_json["frames"][0]["yend_size_matches_expected"],
            serde_json::Value::Null
        );
    }

    #[test]
    fn requires_a_valid_multipart_crc_and_compares_optional_trailer_total() {
        let missing_crc = parse(
            b"=ybegin part=1 total=2 line=128 size=10 name=file.bin\r\n=ypart begin=1 end=5\r\ndata\r\n=yend size=5 part=1 total=2\r\n",
        );
        let missing_crc_json = serde_json::to_value(missing_crc).unwrap();
        assert_eq!(
            missing_crc_json["frames"][0]["multipart_pcrc32_status"],
            "missing"
        );
        assert_eq!(
            missing_crc_json["frames"][0]["size_relationship_status"],
            "incomplete"
        );

        let mismatch = parse(
            b"=ybegin part=1 total=3 line=128 size=10 name=file.bin\r\n=ypart begin=1 end=5\r\ndata\r\n=yend size=5 part=1 total=2 pcrc32=abcdef12\r\n",
        );
        let mismatch_json = serde_json::to_value(mismatch).unwrap();
        assert_eq!(mismatch_json["frames"][0]["total_parts_match"], false);
        assert_eq!(
            mismatch_json["frames"][0]["size_relationship_status"],
            "complete"
        );
    }

    #[test]
    fn reports_single_part_size_relationship_without_requiring_multipart_crc() {
        let metadata = parse(
            b"=ybegin line=128 size=5 name=file.bin\r\ndata\r\n=yend size=5 crc32=abcdef12\r\n",
        );
        let json = serde_json::to_value(metadata).unwrap();
        let frame = &json["frames"][0];

        assert_eq!(frame["expected_segment_size"], 5);
        assert_eq!(frame["yend_size_matches_expected"], true);
        assert_eq!(frame["multipart_pcrc32_status"], "not-required-single-part");
        assert_eq!(frame["size_relationship_status"], "complete");
    }

    #[test]
    fn allows_legacy_multipart_without_total_and_checks_part_when_total_exists() {
        let legacy = parse(
            b"=ybegin part=1 line=128 size=10 name=file.bin\r\n=ypart begin=1 end=5\r\ndata\r\n=yend size=5 part=1 pcrc32=abcdef12\r\n",
        );
        let legacy_json = serde_json::to_value(legacy).unwrap();
        assert_eq!(
            legacy_json["frames"][0]["total_parts_match"],
            serde_json::Value::Null
        );
        assert_eq!(
            legacy_json["frames"][0]["part_number_within_total"],
            serde_json::Value::Null
        );
        assert_eq!(
            legacy_json["frames"][0]["size_relationship_status"],
            "complete"
        );

        let invalid = parse(
            b"=ybegin part=3 total=2 line=128 size=10 name=file.bin\r\n=ypart begin=1 end=5\r\ndata\r\n=yend size=5 part=3 pcrc32=abcdef12\r\n",
        );
        let invalid_json = serde_json::to_value(invalid).unwrap();
        assert_eq!(invalid_json["frames"][0]["part_number_within_total"], false);
        assert_eq!(
            invalid_json["frames"][0]["size_relationship_status"],
            "malformed"
        );
    }

    #[test]
    fn flags_nonadjacent_multipart_range_line() {
        let metadata = parse(
            b"=ybegin part=1 line=128 size=5 name=file.bin\r\ncomment\r\n=ypart begin=1 end=5\r\ndata\r\n=yend size=5 part=1 pcrc32=abcdef12\r\n",
        );
        let json = serde_json::to_value(metadata).unwrap();
        assert_eq!(json["frames"][0]["ypart_follows_ybegin"], false);
        assert_eq!(json["frames"][0]["size_relationship_status"], "malformed");
    }
}
