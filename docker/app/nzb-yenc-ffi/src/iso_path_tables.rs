//! Conditional observations at declared same-endian path-table copy ranges.
//! ECMA-119 7.9.3 and 9.4.14–18. The parser has no image-origin witness.

use super::{FormatHeader, IsoDescriptorSequence, IsoPathTableRange, hex};
use serde::Serialize;
use sha2::{Digest, Sha256};

const TABLE_LIMIT: u32 = 65_536;

#[derive(Debug, Serialize)]
pub struct PathTableCopies {
    pub status: &'static str,
    pub basis: &'static str,
    pub mapping_assumption: &'static str,
    pub image_origin_known: bool,
    pub whole_image_validated: bool,
    pub qualified_path_table_copy_agreement: Option<bool>,
    pub source_primary_descriptor_offset: usize,
    pub source_primary_descriptor_sha256: String,
    pub sequence_status: &'static str,
    pub sampled_descriptor_sequence_terminated: bool,
    pub declared_table_size_bytes: Option<u32>,
    pub source_size_little_endian_field_offset: usize,
    pub source_size_big_endian_field_offset: usize,
    pub table_size_limit_bytes: u32,
    pub tables: Vec<Table>,
    pub pairs: Vec<Pair>,
}

#[derive(Debug, Serialize)]
pub struct Table {
    pub table_type: &'static str,
    pub byte_order: &'static str,
    pub optional: bool,
    pub location_logical_block: Option<u32>,
    pub source_location_field_offset: usize,
    pub declared_range_status: &'static str,
    pub declared_volume_byte_offset: Option<u64>,
    pub declared_volume_end_exclusive: Option<u64>,
    pub status: &'static str,
    pub candidate_sample_offset: Option<usize>,
    pub candidate_sample_end_exclusive: Option<usize>,
    pub available_bytes: Option<usize>,
    pub complete_candidate_range_available: Option<bool>,
    pub bytes_hashed: usize,
    pub raw_sha256: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct Pair {
    pub byte_order: &'static str,
    pub required_table_type: &'static str,
    pub optional_table_type: &'static str,
    pub status: &'static str,
    pub candidate_range_bytes_equal: Option<bool>,
    pub compared_bytes: usize,
    pub first_difference: Option<Difference>,
}

#[derive(Debug, Serialize)]
pub struct Difference {
    pub table_relative_offset: usize,
    pub required_sample_offset: usize,
    pub optional_sample_offset: usize,
    pub required_byte: u8,
    pub optional_byte: u8,
}

struct Observation<'a> {
    table: Table,
    bytes: Option<&'a [u8]>,
}

pub(super) fn parse(
    data: &[u8],
    header: &FormatHeader,
    sequence: &IsoDescriptorSequence,
) -> Option<PathTableCopies> {
    let FormatHeader::Iso9660PrimaryVolumeDescriptor {
        offset,
        version,
        file_structure_version,
        path_table_size,
        path_table_ranges,
        ..
    } = header
    else {
        return None;
    };
    let source_end = offset.checked_add(2048)?;
    let source = data.get(*offset..source_end)?;
    let ranges = [
        path_table_ranges.first()?,
        path_table_ranges.get(1)?,
        path_table_ranges.get(2)?,
        path_table_ranges.get(3)?,
    ];
    let supported = *version == 1 && *file_structure_version == Some(1);
    let observations: Vec<_> = ranges
        .iter()
        .enumerate()
        .map(|(index, range)| {
            observe_table(
                data,
                range,
                *path_table_size,
                supported,
                offset + 140 + index * 4,
                if index < 2 {
                    "little_endian"
                } else {
                    "big_endian"
                },
            )
        })
        .collect();
    let pairs = vec![
        compare(&observations[0], &observations[1]),
        compare(&observations[2], &observations[3]),
    ];
    Some(PathTableCopies {
        status: "conditional_candidate_ranges_observed",
        basis: "first observed primary descriptor declarations and bounded candidate sample bytes; path records and ISO conformance not validated; L and M tables are never compared as raw copies",
        mapping_assumption: "candidate sample offsets under assumed ISO origin at sample offset zero; no verified image-to-file or image-to-sample mapping",
        image_origin_known: false,
        whole_image_validated: false,
        qualified_path_table_copy_agreement: None,
        source_primary_descriptor_offset: *offset,
        source_primary_descriptor_sha256: hex(&Sha256::digest(source)),
        sequence_status: sequence.status,
        sampled_descriptor_sequence_terminated: sequence.status == "terminated",
        declared_table_size_bytes: *path_table_size,
        source_size_little_endian_field_offset: offset + 132,
        source_size_big_endian_field_offset: offset + 136,
        table_size_limit_bytes: TABLE_LIMIT,
        tables: observations
            .into_iter()
            .map(|observation| observation.table)
            .collect(),
        pairs,
    })
}

fn observe_table<'a>(
    data: &'a [u8],
    range: &IsoPathTableRange,
    size: Option<u32>,
    supported: bool,
    source_location_field_offset: usize,
    byte_order: &'static str,
) -> Observation<'a> {
    let mut table = Table {
        table_type: range.table_type,
        byte_order,
        optional: range.optional,
        location_logical_block: range.location_logical_block,
        source_location_field_offset,
        declared_range_status: range.status,
        declared_volume_byte_offset: range.byte_offset,
        declared_volume_end_exclusive: range.byte_end_exclusive,
        status: "unavailable_declared_geometry",
        candidate_sample_offset: None,
        candidate_sample_end_exclusive: None,
        available_bytes: None,
        complete_candidate_range_available: None,
        bytes_hashed: 0,
        raw_sha256: None,
    };
    let failure = if range.optional && range.location_logical_block == Some(0) {
        Some("absent")
    } else if range.location_logical_block == Some(0) {
        Some("required_location_zero_unqualified")
    } else if !supported {
        Some("unsupported_file_structure")
    } else {
        match size {
            None => Some("unavailable_declared_geometry"),
            Some(0) => Some("zero_length_unqualified"),
            Some(size) if size > TABLE_LIMIT => Some("size_limit_exceeded"),
            _ if range.within_declared_volume == Some(false) => Some("outside_declared_volume"),
            _ if range.within_declared_volume != Some(true) => {
                Some("unavailable_declared_geometry")
            }
            _ => None,
        }
    };
    if let Some(status) = failure {
        table.status = status;
        return Observation { table, bytes: None };
    }
    let coordinates = range
        .byte_offset
        .zip(range.byte_end_exclusive)
        .and_then(|(start, end)| Some((usize::try_from(start).ok()?, usize::try_from(end).ok()?)));
    let Some((start, end)) = coordinates else {
        table.status = "sample_coordinate_unrepresentable";
        return Observation { table, bytes: None };
    };
    let Some(length) = end
        .checked_sub(start)
        .filter(|length| *length <= TABLE_LIMIT as usize)
    else {
        table.status = "unavailable_declared_geometry";
        return Observation { table, bytes: None };
    };
    table.candidate_sample_offset = Some(start);
    table.candidate_sample_end_exclusive = Some(end);
    table.available_bytes = Some(data.len().saturating_sub(start).min(length));
    let bytes = data.get(start..end);
    table.complete_candidate_range_available = Some(bytes.is_some());
    if let Some(bytes) = bytes {
        table.status = "complete_candidate_range";
        table.bytes_hashed = bytes.len();
        table.raw_sha256 = Some(hex(&Sha256::digest(bytes)));
    } else {
        table.status = "candidate_range_not_fully_sampled";
    }
    Observation { table, bytes }
}

fn compare(required: &Observation<'_>, optional: &Observation<'_>) -> Pair {
    let mut result = Pair {
        byte_order: required.table.byte_order,
        required_table_type: required.table.table_type,
        optional_table_type: optional.table.table_type,
        status: "unavailable_candidate_range",
        candidate_range_bytes_equal: None,
        compared_bytes: 0,
        first_difference: None,
    };
    let Some((left, right)) = required.bytes.zip(optional.bytes) else {
        if optional.table.status == "absent" {
            result.status = "optional_table_absent";
        }
        return result;
    };
    // Complete observations necessarily contain validated start/end coordinates.
    let Some((left_start, left_end, right_start, right_end)) = required
        .table
        .candidate_sample_offset
        .zip(required.table.candidate_sample_end_exclusive)
        .zip(
            optional
                .table
                .candidate_sample_offset
                .zip(optional.table.candidate_sample_end_exclusive),
        )
        .map(|((a, b), (c, d))| (a, b, c, d))
    else {
        return result;
    };
    if left_start == right_start && left_end == right_end {
        result.status = "same_declared_extent";
        return result;
    }
    if left_start < right_end && right_start < left_end {
        result.status = "overlapping_declared_extents";
        return result;
    }
    if left.len() != right.len() {
        return result;
    }
    result.compared_bytes = left.len();
    result.first_difference = left
        .iter()
        .zip(right)
        .position(|(a, b)| a != b)
        .map(|relative| Difference {
            table_relative_offset: relative,
            required_sample_offset: left_start + relative,
            optional_sample_offset: right_start + relative,
            required_byte: left[relative],
            optional_byte: right[relative],
        });
    let equal = result.first_difference.is_none();
    result.candidate_range_bytes_equal = Some(equal);
    result.status = if equal {
        "candidate_ranges_equal"
    } else {
        "candidate_ranges_differ"
    };
    result
}
