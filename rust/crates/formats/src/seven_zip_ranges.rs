use serde::Serialize;
use sha2::{Digest, Sha256};
use std::collections::BTreeSet;

const SIGNATURE: &[u8; 6] = b"7z\xbc\xaf'\x1c";
const MAX_FRAGMENT_COUNT: usize = 32;
const MAX_RETAINED_BYTES: usize = 16 * 1024 * 1024;
const MAX_NEXT_HEADER_BYTES: usize = 1024 * 1024;
const MAX_PACK_STREAM_COUNT: usize = 32;
const MAX_PACKED_HEADER_BYTES: usize = 1024 * 1024;
const MAX_FOLDER_COUNT: usize = 32;
const MAX_CODER_COUNT: usize = 64;
const MAX_FOLDER_STREAM_COUNT: usize = 64;
const MAX_CODER_PROPERTIES_BYTES: usize = 4096;

#[derive(Debug, Serialize)]
pub struct SevenZipNextHeaderResolution {
    pub relationship: &'static str,
    pub resolution_status: &'static str,
    pub archive_start_file_offset: u64,
    pub next_header_file_start: Option<u64>,
    pub next_header_file_end_exclusive: Option<u64>,
    pub declared_next_header_size: Option<u64>,
    pub expected_crc32: Option<u32>,
    pub observed_crc32: Option<u32>,
    pub checksum_valid: Option<bool>,
    pub sha256: Option<String>,
    pub header_type: Option<u8>,
    pub header_kind: Option<&'static str>,
    pub covered_ranges: Vec<ByteRange>,
    pub missing_ranges: Vec<ByteRange>,
    pub source_fragment_indexes: Vec<usize>,
    pub conflicting_overlap_bytes: usize,
    pub encoded_header_pack_info: Option<EncodedHeaderPackInfo>,
}

#[derive(Debug, Serialize)]
pub struct EncodedHeaderPackInfo {
    pub parse_status: &'static str,
    pub pack_position: Option<u64>,
    pub declared_pack_stream_count: Option<u64>,
    pub streams: Vec<EncodedHeaderPackedStream>,
    pub unpack_info: EncodedHeaderUnpackInfo,
}

#[derive(Debug, Serialize)]
pub struct EncodedHeaderUnpackInfo {
    pub parse_status: &'static str,
    pub declared_folder_count: Option<u64>,
    pub external_folder_data: Option<bool>,
    pub folders: Vec<EncodedHeaderFolder>,
    pub total_input_streams: Option<u64>,
    pub total_output_streams: Option<u64>,
    pub packed_stream_index_count: Option<u64>,
    pub packed_stream_count_matches_pack_info: Option<bool>,
    pub coder_output_stream_size_total: Option<u64>,
    pub folder_crc32: Vec<Option<u32>>,
    pub end_marker_present: bool,
    pub following_stream_info_property_id: Option<u8>,
}

#[derive(Debug, Serialize)]
pub struct EncodedHeaderFolder {
    pub folder_index: usize,
    pub declared_coder_count: Option<u64>,
    pub coders: Vec<EncodedHeaderCoder>,
    pub total_input_streams: Option<u64>,
    pub total_output_streams: Option<u64>,
    pub bind_pairs: Vec<EncodedHeaderBindPair>,
    pub packed_input_stream_indexes: Vec<u64>,
    pub output_stream_unpack_sizes: Vec<u64>,
    pub final_output_stream_index: Option<u64>,
    pub final_output_unpack_size: Option<u64>,
    pub final_output_observation_status: &'static str,
    pub crc32: Option<u32>,
    pub parse_status: &'static str,
}

#[derive(Debug, Serialize)]
pub struct EncodedHeaderCoder {
    pub coder_index: usize,
    pub flags: u8,
    pub method_id_hex: String,
    pub method_name: Option<&'static str>,
    pub input_stream_count: Option<u64>,
    pub output_stream_count: Option<u64>,
    pub properties_length: Option<usize>,
    pub properties_sha256: Option<String>,
    pub properties_prefix_hex: Option<String>,
    pub properties_prefix_truncated: Option<bool>,
    pub properties_parse_status: &'static str,
    pub seven_zip_aes: Option<SevenZipAesProperties>,
    pub compression_properties: Option<SevenZipCompressionProperties>,
    pub properties_interpretation_scope: &'static str,
}

#[derive(Debug, Serialize)]
#[serde(tag = "method", rename_all = "snake_case")]
pub enum SevenZipCompressionProperties {
    Lzma {
        expected_properties_length: usize,
        properties_length_valid: bool,
        properties_length_canonical: bool,
        trailing_properties_bytes: usize,
        encoded_property_byte: Option<u8>,
        property_byte_in_range: Option<bool>,
        literal_context_bits: Option<u8>,
        literal_position_bits: Option<u8>,
        position_bits: Option<u8>,
        declared_dictionary_size_bytes: Option<u32>,
        decoder_effective_dictionary_size_bytes: Option<u32>,
    },
    Lzma2 {
        expected_properties_length: usize,
        properties_length_valid: bool,
        encoded_dictionary_property_byte: Option<u8>,
        dictionary_property_in_range: Option<bool>,
        decoder_effective_dictionary_size_bytes: Option<u32>,
    },
    Delta {
        expected_properties_length: usize,
        properties_length_valid: bool,
        encoded_distance_minus_one: Option<u8>,
        distance_bytes: Option<u16>,
    },
}

#[derive(Debug, Serialize)]
pub struct SevenZipAesProperties {
    pub cycles_power: Option<u8>,
    pub derivation_mode: Option<&'static str>,
    pub key_derivation_iteration_count: Option<u64>,
    pub salt_length: Option<usize>,
    pub initialization_vector_length: Option<usize>,
    pub expected_properties_length: Option<usize>,
    pub properties_length_valid: bool,
    pub salt_hex: Option<String>,
    pub initialization_vector_stored_hex: Option<String>,
    pub initialization_vector_zero_padded_hex: Option<String>,
    pub initialization_vector_zero_padding_bytes: Option<usize>,
    pub encryption_parameter_observation_status: &'static str,
    pub encryption_parameter_observation_basis: &'static str,
}

#[derive(Debug, Serialize)]
pub struct EncodedHeaderBindPair {
    pub input_stream_index: u64,
    pub output_stream_index: u64,
    pub indexes_unique_and_in_range: bool,
}

#[derive(Debug, Serialize)]
pub struct EncodedHeaderPackedStream {
    pub relationship: &'static str,
    pub stream_index: usize,
    pub declared_packed_size: u64,
    pub file_start: Option<u64>,
    pub file_end_exclusive: Option<u64>,
    pub expected_crc32: Option<u32>,
    pub observed_crc32: Option<u32>,
    pub checksum_valid: Option<bool>,
    pub sha256: Option<String>,
    pub resolution_status: &'static str,
    pub covered_ranges: Vec<ByteRange>,
    pub missing_ranges: Vec<ByteRange>,
    pub source_fragment_indexes: Vec<usize>,
    pub conflicting_overlap_bytes: usize,
}

#[derive(Debug, Serialize)]
pub struct ByteRange {
    pub start: u64,
    pub end_exclusive: u64,
}

struct Fragment<'a> {
    start: u64,
    bytes: &'a [u8],
}

pub fn parse(input: &[u8]) -> Option<SevenZipNextHeaderResolution> {
    if input.len() < 12 {
        return None;
    }
    let fragment_count = usize::try_from(u32::from_le_bytes(input[0..4].try_into().ok()?)).ok()?;
    if fragment_count == 0 || fragment_count > MAX_FRAGMENT_COUNT {
        return None;
    }
    let declared_file_size = u64::from_le_bytes(input[4..12].try_into().ok()?);
    let mut cursor = 12usize;
    let mut fragments = Vec::with_capacity(fragment_count);
    let mut retained_bytes = 0usize;
    for _ in 0..fragment_count {
        let header_end = cursor.checked_add(12)?;
        let header = input.get(cursor..header_end)?;
        let start = u64::from_le_bytes(header[0..8].try_into().ok()?);
        let length = usize::try_from(u32::from_le_bytes(header[8..12].try_into().ok()?)).ok()?;
        retained_bytes = retained_bytes.checked_add(length)?;
        if retained_bytes > MAX_RETAINED_BYTES {
            return None;
        }
        let end = header_end.checked_add(length)?;
        let bytes = input.get(header_end..end)?;
        let fragment_end = start.checked_add(u64::try_from(length).ok()?)?;
        if fragment_end > declared_file_size {
            return Some(empty_result("fragment_outside_declared_file"));
        }
        fragments.push(Fragment { start, bytes });
        cursor = end;
    }
    if cursor != input.len() {
        return None;
    }

    let anchor = fragments
        .iter()
        .find(|fragment| fragment.start == 0 && fragment.bytes.len() >= 32);
    let Some(anchor) = anchor else {
        return Some(empty_result("unobserved"));
    };
    if declared_file_size < 32 {
        return Some(empty_result("declared_file_too_small_for_start_header"));
    }
    if anchor.bytes.get(..6)? != SIGNATURE {
        return Some(empty_result("unsupported_anchor"));
    }
    let mut conflicting_anchor_positions = [false; 32];
    for fragment in &fragments {
        let fragment_end = fragment
            .start
            .checked_add(u64::try_from(fragment.bytes.len()).ok()?)?;
        let intersect_start = fragment.start;
        let intersect_end = fragment_end.min(32);
        if intersect_start >= intersect_end {
            continue;
        }
        let source_start = usize::try_from(intersect_start.checked_sub(fragment.start)?).ok()?;
        let anchor_start = usize::try_from(intersect_start).ok()?;
        let length = usize::try_from(intersect_end.checked_sub(intersect_start)?).ok()?;
        let source = fragment
            .bytes
            .get(source_start..source_start.checked_add(length)?)?;
        for (offset, byte) in source.iter().enumerate() {
            let anchor_offset = anchor_start.checked_add(offset)?;
            if anchor.bytes.get(anchor_offset).copied() != Some(*byte) {
                conflicting_anchor_positions[anchor_offset] = true;
            }
        }
    }
    let conflicting_anchor_bytes = conflicting_anchor_positions
        .iter()
        .filter(|value| **value)
        .count();
    if conflicting_anchor_bytes > 0 {
        return Some(SevenZipNextHeaderResolution {
            resolution_status: "conflicting_anchor_overlaps",
            conflicting_overlap_bytes: conflicting_anchor_bytes,
            ..empty_result("conflicting_anchor_overlaps")
        });
    }
    if anchor.bytes.get(6).copied()? != 0 {
        return Some(empty_result("unsupported_major_version"));
    }
    let start_header_crc = le_u32(anchor.bytes, 8)?;
    if crc32fast::hash(anchor.bytes.get(12..32)?) != start_header_crc {
        return Some(empty_result("invalid_start_header_crc"));
    }
    let next_header_offset = le_u64(anchor.bytes, 12)?;
    let next_header_size = le_u64(anchor.bytes, 20)?;
    let expected_crc = le_u32(anchor.bytes, 28)?;
    let Some(next_header_start) = 32u64.checked_add(next_header_offset) else {
        return Some(empty_result("unsupported_integer_range"));
    };
    let Some(next_header_end) = next_header_start.checked_add(next_header_size) else {
        return Some(empty_result("unsupported_integer_range"));
    };

    if next_header_size == 0 {
        let status = if next_header_offset == 0 && expected_crc == 0 {
            "empty_archive_header"
        } else {
            "invalid_empty_header_pointer"
        };
        return Some(SevenZipNextHeaderResolution {
            relationship: "seven_zip_next_header_from_sampled_articles",
            resolution_status: status,
            archive_start_file_offset: 0,
            next_header_file_start: Some(next_header_start),
            next_header_file_end_exclusive: Some(next_header_end),
            declared_next_header_size: Some(next_header_size),
            expected_crc32: Some(expected_crc),
            observed_crc32: None,
            checksum_valid: None,
            sha256: None,
            header_type: None,
            header_kind: None,
            covered_ranges: Vec::new(),
            missing_ranges: Vec::new(),
            source_fragment_indexes: Vec::new(),
            conflicting_overlap_bytes: 0,
            encoded_header_pack_info: None,
        });
    }
    let Ok(header_length) = usize::try_from(next_header_size) else {
        return Some(empty_result("unsupported_integer_range"));
    };
    if header_length > MAX_NEXT_HEADER_BYTES {
        return Some(empty_result("budget_exceeded"));
    }
    if declared_file_size == 0 || next_header_end > declared_file_size {
        return Some(empty_result("pointer_outside_declared_file"));
    }

    let mut output = vec![0u8; header_length];
    let mut present = vec![false; header_length];
    let mut conflicted = vec![false; header_length];
    let mut contributors = BTreeSet::new();
    let mut conflicting_overlap_bytes = 0usize;
    for (index, fragment) in fragments.iter().enumerate() {
        let fragment_end = fragment
            .start
            .checked_add(u64::try_from(fragment.bytes.len()).ok()?)?;
        let intersect_start = fragment.start.max(next_header_start);
        let intersect_end = fragment_end.min(next_header_end);
        if intersect_start >= intersect_end {
            continue;
        }
        let fragment_offset = usize::try_from(intersect_start.checked_sub(fragment.start)?).ok()?;
        let output_offset =
            usize::try_from(intersect_start.checked_sub(next_header_start)?).ok()?;
        let intersection_length =
            usize::try_from(intersect_end.checked_sub(intersect_start)?).ok()?;
        let source = fragment
            .bytes
            .get(fragment_offset..fragment_offset.checked_add(intersection_length)?)?;
        let destination_end = output_offset.checked_add(intersection_length)?;
        let destination = output.get_mut(output_offset..destination_end)?;
        let covered = present.get_mut(output_offset..destination_end)?;
        for offset in 0..intersection_length {
            if covered[offset] {
                if destination[offset] != source[offset] {
                    let output_position = output_offset.checked_add(offset)?;
                    if !conflicted[output_position] {
                        conflicting_overlap_bytes += 1;
                        conflicted[output_position] = true;
                    }
                }
            } else {
                destination[offset] = source[offset];
                covered[offset] = true;
            }
        }
        contributors.insert(index);
    }

    let covered_ranges = ranges_for_presence(&present, next_header_start, true)?;
    let missing_ranges = ranges_for_presence(&present, next_header_start, false)?;
    let base = SevenZipNextHeaderResolution {
        relationship: "seven_zip_next_header_from_sampled_articles",
        resolution_status: "partially_covered",
        archive_start_file_offset: 0,
        next_header_file_start: Some(next_header_start),
        next_header_file_end_exclusive: Some(next_header_end),
        declared_next_header_size: Some(next_header_size),
        expected_crc32: Some(expected_crc),
        observed_crc32: None,
        checksum_valid: None,
        sha256: None,
        header_type: None,
        header_kind: None,
        covered_ranges,
        missing_ranges,
        source_fragment_indexes: contributors.into_iter().collect(),
        conflicting_overlap_bytes,
        encoded_header_pack_info: None,
    };
    if conflicting_overlap_bytes > 0 {
        return Some(SevenZipNextHeaderResolution {
            resolution_status: "conflicting_overlaps",
            ..base
        });
    }
    if present.iter().any(|is_present| !is_present) {
        return Some(base);
    }

    let observed_crc = crc32fast::hash(&output);
    let checksum_valid = observed_crc == expected_crc;
    let header_type = output.first().copied();
    let header_kind = header_type.map(|value| match value {
        0x00 => "end_marker",
        0x01 => "header",
        0x17 => "encoded_header",
        _ => "unknown",
    });
    let digest = Sha256::digest(&output);
    let encoded_header_pack_info = if checksum_valid && header_type == Some(0x17) {
        Some(parse_encoded_header_pack_info(
            &output,
            next_header_offset,
            declared_file_size,
            &fragments,
        ))
    } else {
        None
    };
    Some(SevenZipNextHeaderResolution {
        resolution_status: if checksum_valid {
            "resolved_checksum_valid"
        } else {
            "resolved_checksum_mismatch"
        },
        observed_crc32: Some(observed_crc),
        checksum_valid: Some(checksum_valid),
        sha256: Some(digest.iter().map(|byte| format!("{byte:02x}")).collect()),
        header_type,
        header_kind,
        encoded_header_pack_info,
        ..base
    })
}

fn empty_result(status: &'static str) -> SevenZipNextHeaderResolution {
    SevenZipNextHeaderResolution {
        relationship: "seven_zip_next_header_from_sampled_articles",
        resolution_status: status,
        archive_start_file_offset: 0,
        next_header_file_start: None,
        next_header_file_end_exclusive: None,
        declared_next_header_size: None,
        expected_crc32: None,
        observed_crc32: None,
        checksum_valid: None,
        sha256: None,
        header_type: None,
        header_kind: None,
        covered_ranges: Vec::new(),
        missing_ranges: Vec::new(),
        source_fragment_indexes: Vec::new(),
        conflicting_overlap_bytes: 0,
        encoded_header_pack_info: None,
    }
}

fn parse_encoded_header_pack_info(
    header: &[u8],
    next_header_offset: u64,
    declared_file_size: u64,
    fragments: &[Fragment<'_>],
) -> EncodedHeaderPackInfo {
    if header.first().copied() != Some(0x17) {
        return empty_pack_info("not_an_encoded_header", None, None);
    }

    let mut cursor = 1usize;
    if read_byte(header, &mut cursor) != Some(0x06) {
        return empty_pack_info("encoded_header_does_not_start_with_pack_info", None, None);
    }
    let Some(pack_position) = read_7z_uint64(header, &mut cursor) else {
        return empty_pack_info("truncated_pack_position", None, None);
    };
    let Some(stream_count) = read_7z_uint64(header, &mut cursor) else {
        return empty_pack_info("truncated_pack_stream_count", Some(pack_position), None);
    };
    let Ok(stream_count_usize) = usize::try_from(stream_count) else {
        return empty_pack_info(
            "pack_stream_count_exceeds_supported_range",
            Some(pack_position),
            Some(stream_count),
        );
    };
    if stream_count_usize > MAX_PACK_STREAM_COUNT {
        return empty_pack_info(
            "pack_stream_count_budget_exceeded",
            Some(pack_position),
            Some(stream_count),
        );
    }

    let mut sizes = None;
    let mut checksums = None;
    let mut ended = false;
    while cursor < header.len() {
        let Some(property) = read_byte(header, &mut cursor) else {
            break;
        };
        match property {
            0x00 => {
                ended = true;
                break;
            }
            0x09 if sizes.is_none() => {
                let mut parsed_sizes = Vec::with_capacity(stream_count_usize);
                for _ in 0..stream_count_usize {
                    let Some(size) = read_7z_uint64(header, &mut cursor) else {
                        return empty_pack_info(
                            "truncated_pack_sizes",
                            Some(pack_position),
                            Some(stream_count),
                        );
                    };
                    parsed_sizes.push(size);
                }
                sizes = Some(parsed_sizes);
            }
            0x09 => {
                return empty_pack_info(
                    "duplicate_pack_size_property",
                    Some(pack_position),
                    Some(stream_count),
                );
            }
            0x0A if checksums.is_none() => {
                if sizes.is_none() {
                    return empty_pack_info(
                        "pack_crc_before_pack_sizes",
                        Some(pack_position),
                        Some(stream_count),
                    );
                }
                match read_7z_digests(header, &mut cursor, stream_count_usize) {
                    Ok(parsed_checksums) => checksums = Some(parsed_checksums),
                    Err(status) => {
                        return empty_pack_info(status, Some(pack_position), Some(stream_count));
                    }
                }
            }
            0x0A => {
                return empty_pack_info(
                    "duplicate_pack_crc_property",
                    Some(pack_position),
                    Some(stream_count),
                );
            }
            _ => {
                return empty_pack_info(
                    "unsupported_pack_info_property",
                    Some(pack_position),
                    Some(stream_count),
                );
            }
        }
    }
    if !ended {
        return empty_pack_info(
            "missing_pack_info_end_marker",
            Some(pack_position),
            Some(stream_count),
        );
    }
    let Some(sizes) = sizes else {
        return empty_pack_info(
            "pack_sizes_missing",
            Some(pack_position),
            Some(stream_count),
        );
    };
    let checksums = checksums.unwrap_or_else(|| vec![None; stream_count_usize]);
    let Some(total_packed_bytes) = sizes
        .iter()
        .try_fold(0u64, |total, size| total.checked_add(*size))
    else {
        return empty_pack_info(
            "packed_size_sum_overflow",
            Some(pack_position),
            Some(stream_count),
        );
    };
    let Some(packed_end_relative_to_signature_header) =
        pack_position.checked_add(total_packed_bytes)
    else {
        return empty_pack_info(
            "packed_range_overflow",
            Some(pack_position),
            Some(stream_count),
        );
    };
    if packed_end_relative_to_signature_header > next_header_offset {
        return empty_pack_info(
            "packed_ranges_cross_next_header",
            Some(pack_position),
            Some(stream_count),
        );
    }

    let packed_byte_budget_exceeded = total_packed_bytes > MAX_PACKED_HEADER_BYTES as u64;
    let unpack_info = parse_encoded_header_unpack_info(header, cursor, stream_count);
    let mut streams = Vec::with_capacity(stream_count_usize);
    let mut range_offset = 0u64;
    for (stream_index, size) in sizes.into_iter().enumerate() {
        let stream_start = 32u64
            .checked_add(pack_position)
            .and_then(|start| start.checked_add(range_offset));
        let stream_end = stream_start.and_then(|start| start.checked_add(size));
        let expected_crc32 = checksums.get(stream_index).copied().flatten();
        let stream = match (stream_start, stream_end) {
            (Some(start), Some(end)) if end <= declared_file_size => {
                if packed_byte_budget_exceeded {
                    empty_packed_stream(
                        stream_index,
                        size,
                        Some(start),
                        Some(end),
                        expected_crc32,
                        "packed_stream_byte_budget_exceeded",
                    )
                } else {
                    resolve_packed_stream(fragments, stream_index, size, start, end, expected_crc32)
                }
            }
            (Some(start), Some(end)) => empty_packed_stream(
                stream_index,
                size,
                Some(start),
                Some(end),
                expected_crc32,
                "packed_range_outside_declared_file",
            ),
            _ => empty_packed_stream(
                stream_index,
                size,
                None,
                None,
                expected_crc32,
                "packed_range_overflow",
            ),
        };
        streams.push(stream);
        let Some(next_range_offset) = range_offset.checked_add(size) else {
            return empty_pack_info(
                "packed_range_overflow",
                Some(pack_position),
                Some(stream_count),
            );
        };
        range_offset = next_range_offset;
    }

    EncodedHeaderPackInfo {
        parse_status: if packed_byte_budget_exceeded {
            "pack_info_parsed_packed_stream_byte_budget_exceeded"
        } else {
            "pack_info_parsed"
        },
        pack_position: Some(pack_position),
        declared_pack_stream_count: Some(stream_count),
        streams,
        unpack_info,
    }
}

fn resolve_packed_stream(
    fragments: &[Fragment<'_>],
    stream_index: usize,
    declared_size: u64,
    start: u64,
    end: u64,
    expected_crc32: Option<u32>,
) -> EncodedHeaderPackedStream {
    let Ok(length) = usize::try_from(declared_size) else {
        return empty_packed_stream(
            stream_index,
            declared_size,
            Some(start),
            Some(end),
            expected_crc32,
            "unsupported_integer_range",
        );
    };
    let mut output = vec![0u8; length];
    let mut present = vec![false; length];
    let mut conflicted = vec![false; length];
    let mut contributors = BTreeSet::new();
    let mut conflicting_overlap_bytes = 0usize;

    for (index, fragment) in fragments.iter().enumerate() {
        let Some(fragment_end) = fragment
            .start
            .checked_add(u64::try_from(fragment.bytes.len()).unwrap_or(u64::MAX))
        else {
            continue;
        };
        let intersect_start = fragment.start.max(start);
        let intersect_end = fragment_end.min(end);
        if intersect_start >= intersect_end {
            continue;
        }
        let (Some(fragment_offset), Some(output_offset), Some(intersection_length)) = (
            usize::try_from(intersect_start.saturating_sub(fragment.start)).ok(),
            usize::try_from(intersect_start.saturating_sub(start)).ok(),
            usize::try_from(intersect_end.saturating_sub(intersect_start)).ok(),
        ) else {
            continue;
        };
        let Some(source) = fragment
            .bytes
            .get(fragment_offset..fragment_offset.saturating_add(intersection_length))
        else {
            continue;
        };
        let destination_end = output_offset.saturating_add(intersection_length);
        let (Some(destination), Some(covered)) = (
            output.get_mut(output_offset..destination_end),
            present.get_mut(output_offset..destination_end),
        ) else {
            continue;
        };
        for offset in 0..intersection_length {
            if covered[offset] {
                if destination[offset] != source[offset] && !conflicted[output_offset + offset] {
                    conflicting_overlap_bytes += 1;
                    conflicted[output_offset + offset] = true;
                }
            } else {
                destination[offset] = source[offset];
                covered[offset] = true;
            }
        }
        contributors.insert(index);
    }

    let covered_ranges = ranges_for_presence(&present, start, true).unwrap_or_default();
    let missing_ranges = ranges_for_presence(&present, start, false).unwrap_or_default();
    if conflicting_overlap_bytes > 0 {
        return EncodedHeaderPackedStream {
            relationship: "seven_zip_encoded_header_packed_stream_from_sampled_articles",
            stream_index,
            declared_packed_size: declared_size,
            file_start: Some(start),
            file_end_exclusive: Some(end),
            expected_crc32,
            observed_crc32: None,
            checksum_valid: None,
            sha256: None,
            resolution_status: "conflicting_overlaps",
            covered_ranges,
            missing_ranges,
            source_fragment_indexes: contributors.into_iter().collect(),
            conflicting_overlap_bytes,
        };
    }
    if present.iter().any(|is_present| !is_present) {
        return EncodedHeaderPackedStream {
            relationship: "seven_zip_encoded_header_packed_stream_from_sampled_articles",
            stream_index,
            declared_packed_size: declared_size,
            file_start: Some(start),
            file_end_exclusive: Some(end),
            expected_crc32,
            observed_crc32: None,
            checksum_valid: None,
            sha256: None,
            resolution_status: if covered_ranges.is_empty() {
                "unobserved"
            } else {
                "partially_covered"
            },
            covered_ranges,
            missing_ranges,
            source_fragment_indexes: contributors.into_iter().collect(),
            conflicting_overlap_bytes: 0,
        };
    }

    let observed_crc32 = crc32fast::hash(&output);
    let digest = Sha256::digest(&output);
    EncodedHeaderPackedStream {
        relationship: "seven_zip_encoded_header_packed_stream_from_sampled_articles",
        stream_index,
        declared_packed_size: declared_size,
        file_start: Some(start),
        file_end_exclusive: Some(end),
        expected_crc32,
        observed_crc32: Some(observed_crc32),
        checksum_valid: expected_crc32.map(|expected| expected == observed_crc32),
        sha256: Some(digest.iter().map(|byte| format!("{byte:02x}")).collect()),
        resolution_status: match expected_crc32 {
            Some(_) if expected_crc32 == Some(observed_crc32) => "resolved_checksum_valid",
            Some(_) => "resolved_checksum_mismatch",
            None => "resolved_without_declared_crc",
        },
        covered_ranges,
        missing_ranges,
        source_fragment_indexes: contributors.into_iter().collect(),
        conflicting_overlap_bytes: 0,
    }
}

fn parse_encoded_header_unpack_info(
    data: &[u8],
    mut cursor: usize,
    declared_pack_stream_count: u64,
) -> EncodedHeaderUnpackInfo {
    if read_byte(data, &mut cursor) != Some(0x07) {
        return empty_unpack_info("unpack_info_not_present");
    }
    if read_byte(data, &mut cursor) != Some(0x0b) {
        return empty_unpack_info("folder_marker_missing");
    }
    let Some(folder_count) = read_7z_uint64(data, &mut cursor) else {
        return empty_unpack_info("truncated_folder_count");
    };
    let Ok(folder_count_usize) = usize::try_from(folder_count) else {
        return empty_unpack_info("folder_count_exceeds_supported_range");
    };
    if folder_count_usize > MAX_FOLDER_COUNT {
        return empty_unpack_info("folder_count_budget_exceeded");
    }
    let Some(external) = read_byte(data, &mut cursor) else {
        return empty_unpack_info("truncated_external_folder_flag");
    };
    if external > 1 {
        return empty_unpack_info("invalid_external_folder_flag");
    }
    if external != 0 {
        return EncodedHeaderUnpackInfo {
            parse_status: "external_folder_data_unsupported",
            declared_folder_count: Some(folder_count),
            external_folder_data: Some(true),
            ..empty_unpack_info("external_folder_data_unsupported")
        };
    }

    let mut folders = Vec::with_capacity(folder_count_usize);
    let mut total_input_streams = 0u64;
    let mut total_output_streams = 0u64;
    let mut packed_stream_index_count = 0u64;
    let mut coder_output_stream_size_total = 0u64;
    for folder_index in 0..folder_count_usize {
        let Some(folder) = parse_encoded_header_folder(data, &mut cursor, folder_index) else {
            return empty_unpack_info("malformed_or_unsupported_folder");
        };
        total_input_streams =
            total_input_streams.saturating_add(folder.total_input_streams.unwrap_or_default());
        total_output_streams =
            total_output_streams.saturating_add(folder.total_output_streams.unwrap_or_default());
        packed_stream_index_count = packed_stream_index_count
            .saturating_add(folder.packed_input_stream_indexes.len() as u64);
        folders.push(folder);
    }

    if read_byte(data, &mut cursor) != Some(0x0c) {
        return empty_unpack_info("coders_unpack_size_marker_missing");
    }
    for folder in &mut folders {
        for _ in 0..folder.total_output_streams.unwrap_or_default() {
            let Some(size) = read_7z_uint64(data, &mut cursor) else {
                return empty_unpack_info("truncated_output_stream_unpack_sizes");
            };
            folder.output_stream_unpack_sizes.push(size);
            let Some(total) = coder_output_stream_size_total.checked_add(size) else {
                return empty_unpack_info("unpack_size_sum_overflow");
            };
            coder_output_stream_size_total = total;
        }
    }

    let mut folder_crc32 = vec![None; folder_count_usize];
    let mut property = read_byte(data, &mut cursor);
    if property == Some(0x0a) {
        match read_7z_digests(data, &mut cursor, folder_count_usize) {
            Ok(crcs) => folder_crc32 = crcs,
            Err(_) => {
                return EncodedHeaderUnpackInfo {
                    parse_status: "malformed_folder_crc_data",
                    declared_folder_count: Some(folder_count),
                    external_folder_data: Some(false),
                    folders,
                    total_input_streams: Some(total_input_streams),
                    total_output_streams: Some(total_output_streams),
                    packed_stream_index_count: Some(packed_stream_index_count),
                    packed_stream_count_matches_pack_info: Some(
                        packed_stream_index_count == declared_pack_stream_count,
                    ),
                    coder_output_stream_size_total: Some(coder_output_stream_size_total),
                    folder_crc32,
                    end_marker_present: false,
                    following_stream_info_property_id: None,
                };
            }
        }
        property = read_byte(data, &mut cursor);
    }
    let ended = property == Some(0x00);
    let following_property = ended.then(|| data.get(cursor).copied()).flatten();
    for (folder, crc) in folders.iter_mut().zip(folder_crc32.iter().copied()) {
        folder.crc32 = crc;
    }
    let stream_count_matches = packed_stream_index_count == declared_pack_stream_count;
    let parse_status = if !ended {
        "unpack_info_end_marker_missing"
    } else if !stream_count_matches {
        "pack_stream_count_mismatch"
    } else {
        "unpack_info_parsed"
    };
    for folder in &mut folders {
        folder.final_output_observation_status = if parse_status == "unpack_info_parsed" {
            // This is only the uniquely unbound output in the retained topology.
            // It proves neither graph connectivity nor successful decompression.
            folder.final_output_unpack_size = folder
                .final_output_stream_index
                .and_then(|index| usize::try_from(index).ok())
                .and_then(|index| folder.output_stream_unpack_sizes.get(index).copied());
            if folder.final_output_unpack_size.is_some() {
                "declared_not_decoded"
            } else {
                "final_output_size_unavailable"
            }
        } else {
            parse_status
        };
    }
    EncodedHeaderUnpackInfo {
        parse_status,
        declared_folder_count: Some(folder_count),
        external_folder_data: Some(false),
        folders,
        total_input_streams: Some(total_input_streams),
        total_output_streams: Some(total_output_streams),
        packed_stream_index_count: Some(packed_stream_index_count),
        packed_stream_count_matches_pack_info: Some(stream_count_matches),
        coder_output_stream_size_total: Some(coder_output_stream_size_total),
        folder_crc32,
        end_marker_present: ended,
        following_stream_info_property_id: following_property,
    }
}

fn parse_encoded_header_folder(
    data: &[u8],
    cursor: &mut usize,
    folder_index: usize,
) -> Option<EncodedHeaderFolder> {
    let coder_count = read_7z_uint64(data, cursor)?;
    let coder_count_usize = usize::try_from(coder_count).ok()?;
    if coder_count_usize == 0 || coder_count_usize > MAX_CODER_COUNT {
        return None;
    }
    let mut coders = Vec::with_capacity(coder_count_usize);
    let mut total_input_streams = 0u64;
    let mut total_output_streams = 0u64;
    for coder_index in 0..coder_count_usize {
        let flags = read_byte(data, cursor)?;
        if flags & 0xc0 != 0 {
            return None;
        }
        let method_length = usize::from(flags & 0x0f);
        if method_length > 8 {
            return None;
        }
        let method_end = cursor.checked_add(method_length)?;
        let method_id = data.get(*cursor..method_end)?;
        *cursor = method_end;
        let (input_stream_count, output_stream_count) = if flags & 0x10 != 0 {
            (read_7z_uint64(data, cursor)?, read_7z_uint64(data, cursor)?)
        } else {
            (1, 1)
        };
        if input_stream_count == 0 || output_stream_count == 0 {
            return None;
        }
        total_input_streams = total_input_streams.checked_add(input_stream_count)?;
        total_output_streams = total_output_streams.checked_add(output_stream_count)?;
        if total_input_streams > MAX_FOLDER_STREAM_COUNT as u64
            || total_output_streams > MAX_FOLDER_STREAM_COUNT as u64
        {
            return None;
        }
        let compression_properties;
        let (
            properties_length,
            properties_sha256,
            properties_prefix_hex,
            properties_prefix_truncated,
            properties_parse_status,
            seven_zip_aes,
        ) = if flags & 0x20 != 0 {
            let length = read_7z_uint64(data, cursor)?;
            let Ok(length_usize) = usize::try_from(length) else {
                return None;
            };
            if length_usize > MAX_CODER_PROPERTIES_BYTES {
                return None;
            }
            let end = cursor.checked_add(length_usize)?;
            let properties = data.get(*cursor..end)?;
            *cursor = end;
            let aes = (seven_zip_method_id(method_id) == 0x06f1_0701)
                .then(|| parse_seven_zip_aes_properties(properties));
            compression_properties = parse_compression_properties(method_id, properties);
            (
                Some(length_usize),
                Some(sha256_hex(properties)),
                Some(hex(&properties[..properties.len().min(256)])),
                Some(properties.len() > 256),
                if compression_properties
                    .as_ref()
                    .is_some_and(|value| !compression_properties_valid(value))
                {
                    "malformed_compression_properties"
                } else if aes
                    .as_ref()
                    .is_none_or(|value| value.properties_length_valid)
                {
                    "properties_parsed"
                } else {
                    "malformed_aes_properties"
                },
                aes,
            )
        } else {
            let aes = (seven_zip_method_id(method_id) == 0x06f1_0701)
                .then(|| parse_seven_zip_aes_properties(&[]));
            compression_properties = parse_compression_properties(method_id, &[]);
            (None, None, None, None, "properties_not_present", aes)
        };
        coders.push(EncodedHeaderCoder {
            coder_index,
            flags,
            method_id_hex: hex(method_id),
            method_name: seven_zip_method_name(method_id),
            input_stream_count: Some(input_stream_count),
            output_stream_count: Some(output_stream_count),
            properties_length,
            properties_sha256,
            properties_prefix_hex,
            properties_prefix_truncated,
            properties_parse_status,
            seven_zip_aes,
            compression_properties,
            properties_interpretation_scope:
                "encoded_header_coder_properties_without_stream_decompression",
        });
    }
    let bind_count = total_output_streams.checked_sub(1)?;
    if bind_count > MAX_FOLDER_STREAM_COUNT as u64 {
        return None;
    }
    let mut bind_pairs = Vec::with_capacity(usize::try_from(bind_count).ok()?);
    let mut bound_inputs = BTreeSet::new();
    let mut bound_outputs = BTreeSet::new();
    for _ in 0..bind_count {
        let input_stream_index = read_7z_uint64(data, cursor)?;
        let output_stream_index = read_7z_uint64(data, cursor)?;
        let valid = input_stream_index < total_input_streams
            && output_stream_index < total_output_streams
            && bound_inputs.insert(input_stream_index)
            && bound_outputs.insert(output_stream_index);
        if !valid {
            return None;
        }
        bind_pairs.push(EncodedHeaderBindPair {
            input_stream_index,
            output_stream_index,
            indexes_unique_and_in_range: valid,
        });
    }
    let packed_count = total_input_streams.checked_sub(bind_count)?;
    if packed_count == 0 || packed_count > MAX_FOLDER_STREAM_COUNT as u64 {
        return None;
    }
    let unbound_inputs = (0..total_input_streams)
        .filter(|index| !bound_inputs.contains(index))
        .collect::<Vec<_>>();
    let packed_input_stream_indexes = if packed_count == 1 {
        unbound_inputs
    } else {
        let mut indexes = Vec::with_capacity(usize::try_from(packed_count).ok()?);
        let mut unique = BTreeSet::new();
        for _ in 0..packed_count {
            let index = read_7z_uint64(data, cursor)?;
            if index >= total_input_streams
                || bound_inputs.contains(&index)
                || !unique.insert(index)
            {
                return None;
            }
            indexes.push(index);
        }
        indexes
    };
    if packed_input_stream_indexes.len() as u64 != packed_count {
        return None;
    }
    let mut unbound_outputs =
        (0..total_output_streams).filter(|index| !bound_outputs.contains(index));
    let final_output_stream_index = unbound_outputs.next();
    if final_output_stream_index.is_none() || unbound_outputs.next().is_some() {
        return None;
    }
    Some(EncodedHeaderFolder {
        folder_index,
        declared_coder_count: Some(coder_count),
        coders,
        total_input_streams: Some(total_input_streams),
        total_output_streams: Some(total_output_streams),
        bind_pairs,
        packed_input_stream_indexes,
        output_stream_unpack_sizes: Vec::with_capacity(usize::try_from(total_output_streams).ok()?),
        final_output_stream_index,
        final_output_unpack_size: None,
        final_output_observation_status: "unpack_info_incomplete",
        crc32: None,
        parse_status: "folder_parsed",
    })
}

fn seven_zip_method_name(method_id: &[u8]) -> Option<&'static str> {
    match seven_zip_method_id(method_id) {
        0x00 => Some("Copy"),
        0x03 => Some("Delta"),
        0x21 => Some("LZMA2"),
        0x03_01_01 => Some("LZMA"),
        0x06f1_0701 => Some("7zAES"),
        _ => None,
    }
}

fn seven_zip_method_id(method_id: &[u8]) -> u64 {
    method_id
        .iter()
        .fold(0u64, |value, byte| (value << 8) | u64::from(*byte))
}

fn parse_compression_properties(
    method_id: &[u8],
    properties: &[u8],
) -> Option<SevenZipCompressionProperties> {
    let first = properties.first().copied();
    match seven_zip_method_id(method_id) {
        0x03_01_01 => {
            // 7-Zip C/LzmaDec.c, LzmaProps_Decode: five canonical bytes.
            // Its decoder also tolerates trailing bytes; report both decoder
            // compatibility and canonical length without discarding raw bytes.
            let length_valid = properties.len() >= 5;
            let property_in_range = first.map(|value| value < 9 * 5 * 5);
            let valid = length_valid && property_in_range == Some(true);
            let declared_dictionary = properties
                .get(1..5)
                .and_then(|bytes| <[u8; 4]>::try_from(bytes).ok())
                .map(u32::from_le_bytes);
            Some(SevenZipCompressionProperties::Lzma {
                expected_properties_length: 5,
                properties_length_valid: length_valid,
                properties_length_canonical: properties.len() == 5,
                trailing_properties_bytes: properties.len().saturating_sub(5),
                encoded_property_byte: first,
                property_byte_in_range: property_in_range,
                literal_context_bits: first.filter(|_| valid).map(|value| value % 9),
                literal_position_bits: first.filter(|_| valid).map(|value| (value / 9) % 5),
                position_bits: first.filter(|_| valid).map(|value| (value / 9) / 5),
                declared_dictionary_size_bytes: declared_dictionary,
                decoder_effective_dictionary_size_bytes: declared_dictionary
                    .filter(|_| valid)
                    .map(|size| size.max(4096)),
            })
        }
        0x21 => {
            // 7-Zip C/Lzma2Dec.c, Lzma2Dec_GetOldProps. lc/lp/pb occur
            // inside stream chunks and cannot be inferred from this byte.
            let length_valid = properties.len() == 1;
            let property_in_range = first.map(|value| value <= 40);
            let dictionary = first
                .filter(|_| length_valid && property_in_range == Some(true))
                .map(|value| {
                    if value == 40 {
                        u32::MAX
                    } else {
                        (2u32 | u32::from(value & 1)) << (u32::from(value) / 2 + 11)
                    }
                });
            Some(SevenZipCompressionProperties::Lzma2 {
                expected_properties_length: 1,
                properties_length_valid: length_valid,
                encoded_dictionary_property_byte: first,
                dictionary_property_in_range: property_in_range,
                decoder_effective_dictionary_size_bytes: dictionary,
            })
        }
        0x03 => {
            // 7-Zip CPP/7zip/Compress/DeltaFilter.cpp, SetDecoderProperties2.
            let length_valid = properties.len() == 1;
            Some(SevenZipCompressionProperties::Delta {
                expected_properties_length: 1,
                properties_length_valid: length_valid,
                encoded_distance_minus_one: first,
                distance_bytes: first
                    .filter(|_| length_valid)
                    .map(|value| u16::from(value) + 1),
            })
        }
        _ => None,
    }
}

fn compression_properties_valid(properties: &SevenZipCompressionProperties) -> bool {
    match properties {
        SevenZipCompressionProperties::Lzma {
            properties_length_valid,
            property_byte_in_range,
            ..
        } => *properties_length_valid && *property_byte_in_range == Some(true),
        SevenZipCompressionProperties::Lzma2 {
            properties_length_valid,
            dictionary_property_in_range,
            ..
        } => *properties_length_valid && *dictionary_property_in_range == Some(true),
        SevenZipCompressionProperties::Delta {
            properties_length_valid,
            ..
        } => *properties_length_valid,
    }
}

fn parse_seven_zip_aes_properties(properties: &[u8]) -> SevenZipAesProperties {
    // 7-Zip CPP/7zip/Crypto/7zAes.cpp, SetDecoderProperties2 and Init:
    // exact property layout, salt followed by IV, with a zero-filled 16-byte IV.
    // These are coder declarations; no key derivation or decryption is performed.
    let first = properties.first().copied().unwrap_or(0);
    let cycles_power = first & 0x3f;
    let (material_start, salt_length, iv_length, expected_length) = if properties.is_empty() {
        (0, Some(0), Some(0), Some(0))
    } else if first & 0xc0 == 0 {
        (1, Some(0), Some(0), Some(1))
    } else if let Some(second) = properties.get(1).copied() {
        let salt_length = usize::from((first >> 7) & 1) + usize::from(second >> 4);
        let iv_length = usize::from((first >> 6) & 1) + usize::from(second & 0x0f);
        (
            2,
            Some(salt_length),
            Some(iv_length),
            Some(2 + salt_length + iv_length),
        )
    } else {
        (2, None, None, None)
    };
    let length_valid = expected_length == Some(properties.len());
    let mut result = SevenZipAesProperties {
        cycles_power: Some(cycles_power),
        derivation_mode: Some(if properties.is_empty() {
            "default_properties"
        } else if expected_length.is_none() {
            "malformed_properties"
        } else if cycles_power == 63 {
            "special_cycle_power_63"
        } else {
            "2_to_cycles_power"
        }),
        key_derivation_iteration_count: derivation_iterations(cycles_power),
        salt_length,
        initialization_vector_length: iv_length,
        expected_properties_length: expected_length,
        properties_length_valid: length_valid,
        salt_hex: None,
        initialization_vector_stored_hex: None,
        initialization_vector_zero_padded_hex: None,
        initialization_vector_zero_padding_bytes: None,
        encryption_parameter_observation_status: if !length_valid {
            "malformed_properties"
        } else if properties.is_empty() {
            "decoder_default_properties"
        } else {
            "declared_properties_not_decrypted"
        },
        encryption_parameter_observation_basis: "7-Zip AES coder properties; stored salt and IV plus decoder zero padding; no key derivation or decryption",
    };
    if length_valid && let (Some(salt_length), Some(iv_length)) = (salt_length, iv_length) {
        // Each additive 4-bit length is at most 16; never allocate from cycle power.
        let iv_start = material_start + salt_length;
        let salt = &properties[material_start..iv_start];
        let iv = &properties[iv_start..iv_start + iv_length];
        let mut padded_iv = [0u8; 16];
        padded_iv[..iv_length].copy_from_slice(iv);
        result.salt_hex = Some(hex(salt));
        result.initialization_vector_stored_hex = Some(hex(iv));
        result.initialization_vector_zero_padded_hex = Some(hex(&padded_iv));
        result.initialization_vector_zero_padding_bytes = Some(16 - iv_length);
    }
    result
}

fn derivation_iterations(cycles_power: u8) -> Option<u64> {
    (cycles_power < 63)
        .then(|| 1u64.checked_shl(u32::from(cycles_power)))
        .flatten()
}

fn empty_packed_stream(
    stream_index: usize,
    declared_size: u64,
    file_start: Option<u64>,
    file_end_exclusive: Option<u64>,
    expected_crc32: Option<u32>,
    resolution_status: &'static str,
) -> EncodedHeaderPackedStream {
    EncodedHeaderPackedStream {
        relationship: "seven_zip_encoded_header_packed_stream_from_sampled_articles",
        stream_index,
        declared_packed_size: declared_size,
        file_start,
        file_end_exclusive,
        expected_crc32,
        observed_crc32: None,
        checksum_valid: None,
        sha256: None,
        resolution_status,
        covered_ranges: Vec::new(),
        missing_ranges: Vec::new(),
        source_fragment_indexes: Vec::new(),
        conflicting_overlap_bytes: 0,
    }
}

fn empty_pack_info(
    parse_status: &'static str,
    pack_position: Option<u64>,
    declared_pack_stream_count: Option<u64>,
) -> EncodedHeaderPackInfo {
    EncodedHeaderPackInfo {
        parse_status,
        pack_position,
        declared_pack_stream_count,
        streams: Vec::new(),
        unpack_info: empty_unpack_info("pack_info_not_parsed"),
    }
}

fn empty_unpack_info(parse_status: &'static str) -> EncodedHeaderUnpackInfo {
    EncodedHeaderUnpackInfo {
        parse_status,
        declared_folder_count: None,
        external_folder_data: None,
        folders: Vec::new(),
        total_input_streams: None,
        total_output_streams: None,
        packed_stream_index_count: None,
        packed_stream_count_matches_pack_info: None,
        coder_output_stream_size_total: None,
        folder_crc32: Vec::new(),
        end_marker_present: false,
        following_stream_info_property_id: None,
    }
}

fn read_7z_digests(
    data: &[u8],
    cursor: &mut usize,
    stream_count: usize,
) -> Result<Vec<Option<u32>>, &'static str> {
    let Some(all_defined) = read_byte(data, cursor) else {
        return Err("truncated_pack_crc_definition_flag");
    };
    let mut defined = vec![false; stream_count];
    match all_defined {
        0 => {
            let bitmap_size = stream_count.saturating_add(7) / 8;
            for bitmap_index in 0..bitmap_size {
                let Some(bitmap) = read_byte(data, cursor) else {
                    return Err("truncated_pack_crc_definition_bitmap");
                };
                for bit in 0..8 {
                    let stream_index = bitmap_index.saturating_mul(8).saturating_add(bit);
                    if stream_index < stream_count {
                        defined[stream_index] = bitmap & (0x80 >> bit) != 0;
                    }
                }
            }
        }
        _ => defined.fill(true),
    }

    let mut checksums = Vec::with_capacity(stream_count);
    for is_defined in defined {
        if !is_defined {
            checksums.push(None);
            continue;
        }
        let Some(end) = cursor.checked_add(4) else {
            return Err("truncated_pack_crc_values");
        };
        let Some(bytes) = data.get(*cursor..end) else {
            return Err("truncated_pack_crc_values");
        };
        checksums.push(Some(u32::from_le_bytes(
            bytes.try_into().map_err(|_| "truncated_pack_crc_values")?,
        )));
        *cursor = end;
    }
    Ok(checksums)
}

fn read_7z_uint64(data: &[u8], cursor: &mut usize) -> Option<u64> {
    let first = read_byte(data, cursor)?;
    let mut value = 0u64;
    for index in 0..8 {
        let mask = 0x80u8 >> index;
        if first & mask == 0 {
            value |= u64::from(first & (mask - 1)) << (index * 8);
            return Some(value);
        }
        value |= u64::from(read_byte(data, cursor)?) << (index * 8);
    }
    Some(value)
}

fn read_byte(data: &[u8], cursor: &mut usize) -> Option<u8> {
    let value = *data.get(*cursor)?;
    *cursor = cursor.checked_add(1)?;
    Some(value)
}

fn ranges_for_presence(present: &[bool], start: u64, value: bool) -> Option<Vec<ByteRange>> {
    let mut ranges = Vec::new();
    let mut index = 0usize;
    while index < present.len() {
        if present[index] != value {
            index += 1;
            continue;
        }
        let range_start = index;
        while index < present.len() && present[index] == value {
            index += 1;
        }
        ranges.push(ByteRange {
            start: start.checked_add(u64::try_from(range_start).ok()?)?,
            end_exclusive: start.checked_add(u64::try_from(index).ok()?)?,
        });
    }
    Some(ranges)
}

fn le_u32(bytes: &[u8], offset: usize) -> Option<u32> {
    Some(u32::from_le_bytes(
        bytes.get(offset..offset.checked_add(4)?)?.try_into().ok()?,
    ))
}

fn le_u64(bytes: &[u8], offset: usize) -> Option<u64> {
    Some(u64::from_le_bytes(
        bytes.get(offset..offset.checked_add(8)?)?.try_into().ok()?,
    ))
}

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|byte| format!("{byte:02x}")).collect()
}

fn sha256_hex(bytes: &[u8]) -> String {
    Sha256::digest(bytes)
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

#[cfg(test)]
mod aes_material_tests {
    use super::*;

    #[test]
    fn all_additive_length_encodings_stay_within_fixed_salt_and_iv_bounds() {
        for flags in [0x40, 0x80, 0xc0] {
            for lengths in 0..=255u8 {
                let salt_length = usize::from((flags >> 7) & 1) + usize::from(lengths >> 4);
                let iv_length = usize::from((flags >> 6) & 1) + usize::from(lengths & 15);
                let mut properties = vec![flags | 19, lengths];
                properties.extend(std::iter::repeat_n(0xa5, salt_length));
                properties.extend(std::iter::repeat_n(0x5a, iv_length));
                let result = parse_seven_zip_aes_properties(&properties);
                assert!(result.properties_length_valid);
                assert_eq!(result.salt_hex, Some("a5".repeat(salt_length)));
                assert_eq!(
                    result.initialization_vector_stored_hex,
                    Some("5a".repeat(iv_length))
                );
                assert_eq!(
                    result.initialization_vector_zero_padded_hex,
                    Some(format!(
                        "{}{}",
                        "5a".repeat(iv_length),
                        "00".repeat(16 - iv_length)
                    ))
                );
                for end in 1..properties.len() {
                    let truncated = parse_seven_zip_aes_properties(&properties[..end]);
                    assert!(!truncated.properties_length_valid);
                    assert!(truncated.salt_hex.is_none());
                    assert!(truncated.initialization_vector_zero_padded_hex.is_none());
                }
                properties.push(0);
                assert!(!parse_seven_zip_aes_properties(&properties).properties_length_valid);
            }
        }
    }

    #[test]
    fn sampled_archive_crypto_material_requires_complete_nonconflicting_checksum_evidence() {
        let mut next_header = vec![0x17, 0x06, 0, 1, 0x09, 16, 0];
        next_header.extend_from_slice(&[0x07, 0x0b, 1, 0, 1, 0x24, 0x06, 0xf1, 0x07, 0x01, 7]);
        next_header.extend_from_slice(&[0xd3, 0x12, 0xaa, 0xbb, 0x10, 0x20, 0x30]);
        next_header.extend_from_slice(&[0x0c, 16, 0, 0]);
        let mut archive = vec![0u8; 48];
        archive[..6].copy_from_slice(SIGNATURE);
        archive[7] = 4;
        archive[12..20].copy_from_slice(&16u64.to_le_bytes());
        archive[20..28].copy_from_slice(&(next_header.len() as u64).to_le_bytes());
        archive[28..32].copy_from_slice(&crc32fast::hash(&next_header).to_le_bytes());
        let crc = crc32fast::hash(&archive[12..32]);
        archive[8..12].copy_from_slice(&crc.to_le_bytes());
        archive.extend_from_slice(&next_header);
        let envelope = |fragments: &[(u64, &[u8])]| {
            let mut input = (fragments.len() as u32).to_le_bytes().to_vec();
            input.extend_from_slice(&(archive.len() as u64).to_le_bytes());
            for (offset, bytes) in fragments {
                input.extend_from_slice(&offset.to_le_bytes());
                input.extend_from_slice(&(bytes.len() as u32).to_le_bytes());
                input.extend_from_slice(bytes);
            }
            input
        };
        let input = envelope(&[(0, &archive)]);
        let value = serde_json::to_value(parse(&input).unwrap()).unwrap();
        assert_eq!(value["resolution_status"], "resolved_checksum_valid");
        assert_eq!(value["source_fragment_indexes"], serde_json::json!([0]));
        let material = &value["encoded_header_pack_info"]["unpack_info"]["folders"][0]["coders"][0]
            ["seven_zip_aes"];
        assert_eq!(material["salt_hex"], "aabb");
        assert_eq!(material["initialization_vector_stored_hex"], "102030");
        assert_eq!(
            material["encryption_parameter_observation_status"],
            "declared_properties_not_decrypted"
        );

        let mut corrupt = archive.clone();
        *corrupt.last_mut().unwrap() ^= 1;
        let mut invalid_anchor = archive.clone();
        invalid_anchor[8] ^= 1;
        for (input, status) in [
            (envelope(&[(0, &corrupt)]), "resolved_checksum_mismatch"),
            (
                envelope(&[(0, &invalid_anchor)]),
                "invalid_start_header_crc",
            ),
            (
                envelope(&[(0, &archive[..archive.len() - 1])]),
                "partially_covered",
            ),
            (
                envelope(&[(0, &archive), (48, &corrupt[48..])]),
                "conflicting_overlaps",
            ),
        ] {
            let value = serde_json::to_value(parse(&input).unwrap()).unwrap();
            assert_eq!(value["resolution_status"], status);
            assert!(value["encoded_header_pack_info"].is_null());
        }
    }

    #[test]
    fn observes_stored_salt_and_iv_separately_from_decoder_zero_padding() {
        for (properties, salt, iv, padding) in [
            (
                vec![0xd3, 0x12, 0xaa, 0xbb, 0x10, 0x20, 0x30],
                "aabb",
                "102030",
                13,
            ),
            (vec![0x93, 0x02, 0xaa, 0x10, 0x20], "aa", "1020", 14),
            (vec![0xff, 0, 0xaa, 0xbb], "aa", "bb", 15),
            (vec![0x13], "", "", 16),
        ] {
            let value = serde_json::to_value(parse_seven_zip_aes_properties(&properties)).unwrap();
            assert_eq!(value["salt_hex"], salt);
            assert_eq!(value["initialization_vector_stored_hex"], iv);
            assert_eq!(value["initialization_vector_zero_padding_bytes"], padding);
            assert_eq!(
                value["initialization_vector_zero_padded_hex"],
                format!("{iv}{}", "00".repeat(padding))
            );
            assert_eq!(
                value["encryption_parameter_observation_status"],
                "declared_properties_not_decrypted"
            );
        }
        let properties = [vec![0xd3, 0xff], (0..32).collect::<Vec<u8>>()].concat();
        let value = serde_json::to_value(parse_seven_zip_aes_properties(&properties)).unwrap();
        assert_eq!(value["salt_hex"], "000102030405060708090a0b0c0d0e0f");
        assert_eq!(
            value["initialization_vector_stored_hex"],
            "101112131415161718191a1b1c1d1e1f"
        );
        assert_eq!(
            value["initialization_vector_zero_padded_hex"],
            value["initialization_vector_stored_hex"]
        );
        assert_eq!(value["initialization_vector_zero_padding_bytes"], 0);
    }

    #[test]
    fn malformed_aes_properties_withhold_interpreted_material() {
        for properties in [
            vec![0xd3],
            vec![0xd3, 0x12, 0xaa, 0xbb, 0x10, 0x20],
            vec![0xd3, 0x12, 0xaa, 0xbb, 0x10, 0x20, 0x30, 0x99],
            vec![0x13, 0],
        ] {
            let value = serde_json::to_value(parse_seven_zip_aes_properties(&properties)).unwrap();
            assert_eq!(value["properties_length_valid"], false);
            assert_eq!(
                value["encryption_parameter_observation_status"],
                "malformed_properties"
            );
            for field in [
                "salt_hex",
                "initialization_vector_stored_hex",
                "initialization_vector_zero_padded_hex",
                "initialization_vector_zero_padding_bytes",
            ] {
                assert!(value[field].is_null());
            }
        }
    }

    #[test]
    fn empty_properties_identify_defaults_instead_of_stored_crypto_bytes() {
        let value = serde_json::to_value(parse_seven_zip_aes_properties(&[])).unwrap();
        assert_eq!(
            value["encryption_parameter_observation_status"],
            "decoder_default_properties"
        );
        assert_eq!(value["salt_hex"], "");
        assert_eq!(value["initialization_vector_stored_hex"], "");
        assert_eq!(
            value["initialization_vector_zero_padded_hex"],
            "00".repeat(16)
        );
        assert_eq!(value["initialization_vector_zero_padding_bytes"], 16);
    }

    #[test]
    fn aes_material_is_attached_to_its_encoded_header_coder() {
        let mut data = vec![0x07, 0x0b, 1, 0, 1, 0x24, 0x06, 0xf1, 0x07, 0x01, 7];
        data.extend_from_slice(&[0xd3, 0x12, 0xaa, 0xbb, 0x10, 0x20, 0x30]);
        data.extend_from_slice(&[0x0c, 16, 0, 0]);
        let value = serde_json::to_value(parse_encoded_header_unpack_info(&data, 0, 1)).unwrap();
        assert_eq!(value["parse_status"], "unpack_info_parsed");
        let coder = &value["folders"][0]["coders"][0];
        assert_eq!(coder["method_id_hex"], "06f10701");
        assert_eq!(coder["properties_prefix_hex"], "d312aabb102030");
        assert_eq!(coder["seven_zip_aes"]["salt_hex"], "aabb");
        assert_eq!(
            coder["seven_zip_aes"]["initialization_vector_stored_hex"],
            "102030"
        );
        assert_eq!(
            coder["seven_zip_aes"]["encryption_parameter_observation_status"],
            "declared_properties_not_decrypted"
        );
        assert_eq!(
            coder["properties_interpretation_scope"],
            "encoded_header_coder_properties_without_stream_decompression"
        );
        assert!(value["folders"][0]["crc32"].is_null());
    }
}

#[cfg(test)]
mod final_output_tests {
    use super::*;

    fn two_coder_unpack_info(input: u8, output: u8, terminator: u8) -> Vec<u8> {
        vec![
            0x07, 0x0b, 1, 0, 2, 1, 0, 1, 0, input, output, 0x0c, 10, 20, terminator, 0,
        ]
    }

    #[test]
    fn final_folder_output_uses_the_unbound_index_not_sum_or_last_output() {
        for (input, output, index, size) in [(1, 0, 1, 20), (0, 1, 0, 10), (0, 0, 1, 20)] {
            let result =
                parse_encoded_header_unpack_info(&two_coder_unpack_info(input, output, 0), 0, 1);
            let value = serde_json::to_value(result).unwrap();
            assert_eq!(value["parse_status"], "unpack_info_parsed");
            assert_eq!(value["coder_output_stream_size_total"], 30);
            assert_eq!(value["folders"][0]["final_output_stream_index"], index);
            assert_eq!(value["folders"][0]["final_output_unpack_size"], size);
            assert_eq!(
                value["folders"][0]["final_output_observation_status"],
                "declared_not_decoded"
            );
            assert!(value["folders"][0]["crc32"].is_null());
        }
    }

    #[test]
    fn incomplete_or_mismatched_unpack_info_withholds_final_size() {
        for (data, pack_count, status) in [
            (
                two_coder_unpack_info(1, 0, 1),
                1,
                "unpack_info_end_marker_missing",
            ),
            (
                two_coder_unpack_info(1, 0, 0),
                2,
                "pack_stream_count_mismatch",
            ),
        ] {
            let value =
                serde_json::to_value(parse_encoded_header_unpack_info(&data, 0, pack_count))
                    .unwrap();
            assert_eq!(value["parse_status"], status);
            assert!(value["folders"][0]["final_output_unpack_size"].is_null());
            assert_eq!(
                value["folders"][0]["final_output_observation_status"],
                status
            );
        }
        let mut missing_size = two_coder_unpack_info(1, 0, 0);
        missing_size.truncate(13);
        let result = parse_encoded_header_unpack_info(&missing_size, 0, 1);
        assert_eq!(result.parse_status, "truncated_output_stream_unpack_sizes");
        assert!(result.folders.is_empty());
    }

    #[test]
    fn invalid_bindings_and_external_folders_do_not_invent_a_final_output() {
        let data = two_coder_unpack_info(1, 2, 0);
        let result = parse_encoded_header_unpack_info(&data, 0, 1);
        assert_eq!(result.parse_status, "malformed_or_unsupported_folder");
        assert!(result.folders.is_empty());
        let result = parse_encoded_header_unpack_info(&[0x07, 0x0b, 1, 1, 0], 0, 1);
        assert_eq!(result.parse_status, "external_folder_data_unsupported");
        assert!(result.folders.is_empty());
    }
}
