use md5::{Digest, Md5};
use serde::Serialize;
use std::collections::{BTreeMap, BTreeSet};

const MAGIC: &[u8; 8] = b"PAR2\0PKT";
const MAX_PACKETS: usize = 20_000;
const MAX_MAIN_FILE_IDS: usize = 32_768;
// Current PAR2 GF(2^16) construction has 32,768 usable input constants.
const MAX_SOURCE_BLOCKS: u64 = 32_768;
const MAX_OPTIONAL_HEX_BYTES: usize = 256;
const MAX_IFSC_PREVIEW_BYTES: usize = 4096;
const MAX_FILESLICE_CHECKSUM_WORK_BYTES: u64 = 64 * 1024 * 1024;
const MAX_RETAINED_FILENAME_BYTES: usize = 4096;
const MAX_RETAINED_FILENAME_HEX_BYTES: usize = 256;
const MAX_DECODED_UNICODE_FILENAME_BYTES: usize = 16 * 1024;

#[derive(Debug, Serialize)]
pub struct Par2Metadata {
    pub parser_engine: &'static str,
    pub creator: Option<String>,
    pub packet_count: usize,
    pub packet_limit_reached: bool,
    pub trailing_unparsed_bytes: usize,
    pub unparsed_bytes: usize,
    pub invalid_packet_count: usize,
    pub unsupported_integer_range_count: usize,
    pub integer_range_valid: bool,
    pub source_block_size: Option<u64>,
    pub source_block_count: Option<u64>,
    pub source_block_limit: u64,
    pub source_block_count_within_current_v2_limit: Option<bool>,
    pub main_packet: Option<MainPacket>,
    pub main_packet_set_id_derivations: Vec<RecoverySetIdDerivation>,
    pub main_file_id_limit: usize,
    pub retained_main_file_id_count: usize,
    pub main_file_id_limit_reached: bool,
    pub main_file_id_limit_observations: Vec<MainFileIdLimitObservation>,
    pub source_inventory_complete: bool,
    pub ifsc_relationships_valid: bool,
    pub source_descriptions_valid: bool,
    pub main_definition_conflict: bool,
    pub conflicting_file_description_ids: Vec<String>,
    pub source_definition_conflicts: Vec<SourceDefinitionConflict>,
    pub missing_file_description_ids: Vec<String>,
    pub unexpected_file_description_ids: Vec<String>,
    pub invalid_file_description_ids: Vec<String>,
    pub duplicate_filename_file_ids: Vec<String>,
    pub file_id_derivation_mismatch_ids: Vec<String>,
    pub source_file_count: usize,
    pub source_file_bytes: Option<u64>,
    pub source_file_bytes_overflow: bool,
    pub source_block_count_overflow: bool,
    pub source_files: Vec<SourceFile>,
    pub unicode_filenames: Vec<UnicodeFilename>,
    pub input_file_slice_checksums: Vec<InputFileSliceChecksums>,
    pub ifsc_slice_count_mismatch_count: usize,
    pub recovery_set_ids: Vec<String>,
    pub recovery_sets: Vec<RecoverySetInventory>,
    pub unique_recovery_slice_count: Option<usize>,
    pub recovery_exponents: Option<Vec<u32>>,
    pub recovery_slice_conflict_count: usize,
    pub recovery_slice_length_unvalidated_count: usize,
    pub conflicting_recovery_exponents: Vec<RecoveryExponentConflict>,
    pub recovery_packets: Vec<RecoveryPacket>,
    pub recovery_to_source_percent: Option<f64>,
    pub packed_layout_present: bool,
    pub recovery_percentage_basis: &'static str,
    pub optional_packet_summaries: BTreeMap<String, Vec<OptionalPacketSummary>>,
    pub unicode_filename_packets: Vec<UnicodeFilenamePacket>,
    pub packet_summaries: Vec<PacketSummary>,
    pub packet_type_counts: BTreeMap<String, usize>,
}

#[derive(Clone, Debug, Serialize)]
pub struct MainPacket {
    pub recoverable_file_count: u32,
    pub recoverable_file_ids: Vec<String>,
    pub non_recoverable_file_ids: Vec<String>,
    pub all_file_ids: Vec<String>,
}

#[derive(Clone, Debug, Serialize)]
pub struct RecoverySetIdDerivation {
    pub packet_offset: usize,
    pub basis: &'static str,
    pub observed_header_recovery_set_id: String,
    pub computed_current_v2_recovery_set_id: String,
    pub matches_current_v2_derivation: bool,
    pub status: &'static str,
}

#[derive(Debug, Serialize)]
pub struct MainFileIdLimitObservation {
    pub packet_offset: usize,
    pub recovery_set_id: String,
    pub observed_file_id_count: usize,
    pub trailing_file_id_bytes: usize,
    pub limit_kind: &'static str,
    pub retained_file_id_count: usize,
}

#[derive(Clone, Debug, Serialize)]
pub struct SourceFile {
    pub recovery_set_id: String,
    pub filename: String,
    pub filename_bytes_length: usize,
    pub filename_bytes_nonempty: bool,
    pub filename_bytes_sha256: String,
    pub filename_field_sha256: String,
    pub filename_bytes_prefix_hex: String,
    pub filename_bytes_prefix_truncated: bool,
    pub filename_ascii_valid: bool,
    pub filename_padding_bytes: usize,
    pub filename_padding_valid: bool,
    pub file_length: u64,
    pub file_length_raw_little_endian_hex: String,
    pub md5: String,
    pub md5_first_16k: String,
    pub short_file_hashes_consistent: Option<bool>,
    pub file_id: String,
    pub derived_file_id: String,
    pub file_id_derivation_valid: bool,
    pub file_id_matches_derived: Option<bool>,
    pub file_id_matches_current_v2_derivation: bool,
    pub small_file_hashes_consistent: Option<bool>,
    pub filename_byte_length: usize,
    pub filename_truncated: bool,
    pub filename_sha256: String,
    pub filename_bytes_hex: String,
    pub filename_bytes_hex_truncated: bool,
    pub filename_utf8_valid: bool,
    pub filename_padding_byte_length: usize,
    pub filename_padding_zero_valid: bool,
    pub filename_termination_status: &'static str,
}

#[derive(Clone, Debug, Serialize)]
pub struct UnicodeFilename {
    pub recovery_set_id: String,
    pub file_id: String,
    pub filename: Option<String>,
    pub raw_name_bytes: usize,
    pub raw_name_sha256: String,
    pub filename_utf16_bytes: usize,
    pub filename_sha256: String,
    pub filename_truncated: bool,
    pub utf16_valid: bool,
    pub padding_byte_length: usize,
    pub first_packet_offset: usize,
    pub last_packet_offset: usize,
    pub packet_count: usize,
    pub duplicate_conflict: bool,
    pub source_file_present: Option<bool>,
}

#[derive(Clone, Debug, Serialize)]
pub struct InputFileSliceChecksums {
    pub recovery_set_id: String,
    pub file_id: String,
    pub slice_count: usize,
    pub checksum_records_sha256: String,
    pub checksum_records_preview_hex: String,
    pub preview_slice_count: usize,
    pub preview_truncated: bool,
    pub slice_preview: Vec<InputFileSliceChecksum>,
    pub source_file_present: bool,
    pub expected_slice_count_from_file_length: Option<u64>,
    pub slice_count_matches_file_length: Option<bool>,
    pub duplicate_conflict: bool,
}

#[derive(Clone, Debug, Serialize)]
pub struct InputFileSliceChecksum {
    pub slice_index: usize,
    pub md5: String,
    pub crc32: u32,
    pub crc32_little_endian_hex: String,
    pub source_byte_start: Option<u64>,
    pub source_byte_end_exclusive: Option<u64>,
    pub source_bytes_in_slice: Option<u64>,
    pub zero_padding_bytes: Option<u64>,
    pub source_range_valid: Option<bool>,
}

#[derive(Debug, Serialize)]
pub struct RecoverySetInventory {
    pub recovery_set_id: String,
    pub source_block_size: Option<u64>,
    pub source_block_count: Option<u64>,
    pub source_block_limit: u64,
    pub source_block_count_within_current_v2_limit: Option<bool>,
    pub source_block_count_overflow: bool,
    pub main_packet: Option<MainPacket>,
    pub source_inventory_complete: bool,
    pub ifsc_relationships_valid: bool,
    pub source_descriptions_valid: bool,
    pub main_definition_conflict: bool,
    pub conflicting_file_description_ids: Vec<String>,
    pub source_definition_conflicts: Vec<SourceDefinitionConflict>,
    pub missing_file_description_ids: Vec<String>,
    pub unexpected_file_description_ids: Vec<String>,
    pub invalid_file_description_ids: Vec<String>,
    pub duplicate_filename_file_ids: Vec<String>,
    pub file_id_derivation_mismatch_ids: Vec<String>,
    pub source_file_count: usize,
    pub source_file_bytes: Option<u64>,
    pub integer_range_valid: bool,
    pub source_file_bytes_overflow: bool,
    pub source_files: Vec<SourceFile>,
    pub input_file_slice_checksums: Vec<InputFileSliceChecksums>,
    pub ifsc_slice_count_mismatch_count: usize,
    pub unique_recovery_slice_count: usize,
    pub recovery_exponents: Vec<u32>,
    pub recovery_exponents_observed: Vec<u32>,
    pub recovery_slice_conflict_count: usize,
    pub recovery_slice_length_unvalidated_count: usize,
    pub conflicting_recovery_exponents: Vec<u32>,
}

#[derive(Clone, Debug, Serialize)]
pub struct RecoveryExponentConflict {
    pub recovery_set_id: String,
    pub exponent: u32,
    pub first_packet_offset: usize,
    pub conflicting_packet_offset: usize,
    pub first_slice_sha256: String,
    pub conflicting_slice_sha256: String,
}

#[derive(Clone, Debug, Serialize)]
pub struct SourceDefinitionConflict {
    pub recovery_set_id: String,
    pub definition_kind: &'static str,
    pub file_id: Option<String>,
    pub first_packet_offset: usize,
    pub conflicting_packet_offset: usize,
    pub first_payload_sha256: String,
    pub conflicting_payload_sha256: String,
}

#[derive(Debug, Serialize)]
pub struct OptionalPacketSummary {
    pub packet_offset: usize,
    pub recovery_set_id: String,
    pub related_file_id: Option<String>,
    pub related_file_id_status: Option<&'static str>,
    pub related_source_file_present: Option<bool>,
    pub input_slice_index: Option<u64>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub fileslic_relationship: Option<FileSlicRelationship>,
    pub recovery_checksum_record_count: Option<usize>,
    pub recovery_checksum_trailing_bytes: Option<usize>,
    pub payload_bytes: usize,
    pub payload_sha256: String,
    pub payload_hex: String,
    pub payload_hex_truncated: bool,
}

#[derive(Debug, Serialize)]
pub struct UnicodeFilenamePacket {
    pub packet_offset: usize,
    pub recovery_set_id: String,
    pub file_id: Option<String>,
    pub filename_field_bytes_length: Option<usize>,
    pub filename_field_sha256: Option<String>,
    pub filename_field_prefix_hex: Option<String>,
    pub filename_field_prefix_truncated: Option<bool>,
    pub source_file_present: Option<bool>,
    pub main_packet_membership: Option<bool>,
    pub duplicate_occurrence_count: Option<usize>,
    pub duplicate_name_conflict: Option<bool>,
}

#[derive(Debug, Serialize)]
pub struct FileSlicRelationship {
    pub index_basis: &'static str,
    pub layout_status: &'static str,
    pub duplicate_status: &'static str,
    pub logical_file_offset: Option<u64>,
    pub logical_byte_length: Option<u64>,
    pub file_description_id_derivation_matches: Option<bool>,
    pub physical_data_offset: Option<usize>,
    pub physical_slice_field_bytes: Option<usize>,
    pub expected_alignment_bytes: Option<usize>,
    pub alignment_padding_zero_valid: Option<bool>,
    pub ifsc_relationship_status: &'static str,
    pub ifsc_record_offset: Option<usize>,
    pub ifsc_record_index: Option<u64>,
    pub ifsc_md5: Option<String>,
    pub ifsc_crc32: Option<String>,
    pub source_slice_checksum_status: &'static str,
    pub source_slice_checksum_bytes: Option<u64>,
    pub source_slice_md5_calculated: Option<String>,
    pub source_slice_md5_matches: Option<bool>,
    pub source_slice_crc32_calculated: Option<String>,
    pub source_slice_crc32_matches: Option<bool>,
}

#[derive(Debug, Serialize)]
pub struct PacketSummary {
    pub offset: usize,
    pub packet_bytes: usize,
    pub packet_type: String,
    pub packet_type_raw_hex: String,
    pub set_id: String,
    pub packet_md5: String,
    pub checksum_valid: bool,
    pub payload_bytes: usize,
    pub payload_sha256: String,
    pub payload_prefix_hex: String,
    pub payload_prefix_truncated: bool,
    pub recovery_exponent: Option<u32>,
    pub recovery_data_sha256: Option<String>,
    pub main_body_md5: Option<String>,
    pub main_body_md5_matches_declared_set_id: Option<bool>,
    pub physical_regions: Vec<PacketPhysicalRegion>,
}

#[derive(Debug, Serialize)]
pub struct PacketPhysicalRegion {
    pub kind: &'static str,
    /// Zero-based byte offset within the fully decoded PAR2 volume.
    pub offset: usize,
    /// Length of this physical region; validate packet checksum separately.
    pub byte_length: usize,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub record_count: Option<usize>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub record_byte_length: Option<usize>,
}

#[derive(Default)]
struct Parsed {
    creator: Option<String>,
    sources_by_set: BTreeMap<(String, String), SourceFile>,
    block_sizes_by_set: BTreeMap<String, u64>,
    mains_by_set: BTreeMap<String, MainPacket>,
    main_packet_set_id_derivations: Vec<RecoverySetIdDerivation>,
    main_file_id_limit_observations: Vec<MainFileIdLimitObservation>,
    retained_main_file_id_count: usize,
    ifsc_by_set_and_file: BTreeMap<(String, String), IfscPacket>,
    unicode_filenames_by_set_and_file: BTreeMap<(String, String), UnicodeFilename>,
    recovery_exponents_by_set: BTreeMap<String, BTreeSet<u32>>,
    recovery_packets: Vec<RecoveryPacket>,
    recovery_slice_hashes_by_set_and_exponent: BTreeMap<(String, u32), (String, usize)>,
    conflicting_recovery_exponents: BTreeSet<(String, u32)>,
    recovery_exponent_conflicts: Vec<RecoveryExponentConflict>,
    packet_definitions: BTreeMap<String, SourceDefinitionObservation>,
    source_definition_conflicts: Vec<SourceDefinitionConflict>,
    recovery_slice_lengths_unvalidated_by_set: BTreeMap<String, usize>,
    recovery_exponents_observed_by_set: BTreeMap<String, BTreeSet<u32>>,
    optional_packets: BTreeMap<String, Vec<OptionalPacketSummary>>,
    unicode_filename_packets: Vec<UnicodeFilenamePacket>,
    packet_summaries: Vec<PacketSummary>,
    packet_type_counts: BTreeMap<String, usize>,
    packet_count: usize,
    invalid_packet_count: usize,
    unparsed_bytes: usize,
}

#[derive(Clone, Debug, Serialize)]
pub struct RecoveryPacket {
    pub recovery_set_id: String,
    pub exponent: u32,
    pub payload_bytes: usize,
    pub slice_sha256: String,
    pub packet_offset: usize,
}

#[derive(Debug)]
struct IfscPacket {
    records_offset: usize,
    slice_count: usize,
    records_sha256: String,
    preview_hex: String,
    preview_slice_count: usize,
    slice_preview: Vec<InputFileSliceChecksum>,
    duplicate_conflict: bool,
}

struct SourceDefinitionObservation {
    payload_sha256: String,
    packet_offset: usize,
}

pub fn parse(data: &[u8]) -> Par2Metadata {
    let mut parsed = Parsed::default();
    let mut offset = 0usize;
    let mut unsupported_integer_range_count = 0usize;
    let mut unsupported_integer_sets = BTreeSet::new();

    while offset.saturating_add(64) <= data.len() && parsed.packet_count < MAX_PACKETS {
        let Some(relative) = find_magic(data, offset) else {
            parsed.unparsed_bytes = parsed
                .unparsed_bytes
                .saturating_add(data.len().saturating_sub(offset));
            break;
        };
        parsed.unparsed_bytes = parsed
            .unparsed_bytes
            .saturating_add(relative.saturating_sub(offset));
        offset = relative;
        let Some(packet_len_u64) = read_u64(data, offset + 8) else {
            parsed.invalid_packet_count += 1;
            break;
        };
        let Ok(packet_len) = usize::try_from(packet_len_u64) else {
            parsed.invalid_packet_count += 1;
            break;
        };
        if packet_len < 64 || packet_len % 4 != 0 || packet_len > data.len().saturating_sub(offset)
        {
            parsed.invalid_packet_count += 1;
            break;
        }

        let packet = &data[offset..offset + packet_len];
        let packet_type_bytes = &packet[48..64];
        let packet_type = type_string(packet_type_bytes);
        let set_id = hex(&packet[32..48]);
        let packet_md5 = hex(&packet[16..32]);
        let checksum_valid = md5(&packet[32..]) == packet[16..32];
        let payload = &packet[64..];
        let recovery_exponent = (packet_type == "PAR 2.0\\0RecvSlic")
            .then(|| read_u32(payload, 0))
            .flatten();
        let recovery_data_sha256 = (packet_type == "PAR 2.0\\0RecvSlic" && payload.len() >= 4)
            .then(|| sha256_hex(&payload[4..]));
        let main_body_md5 = (packet_type == "PAR 2.0\\0Main").then(|| hex(&md5(payload)));
        let main_body_md5_matches_declared_set_id =
            main_body_md5.as_ref().map(|body_md5| body_md5 == &set_id);
        let payload_sha256 = sha256_hex(payload);

        parsed.packet_count += 1;
        *parsed
            .packet_type_counts
            .entry(packet_type.clone())
            .or_default() += 1;
        let payload_prefix = &payload[..payload.len().min(MAX_OPTIONAL_HEX_BYTES)];
        parsed.packet_summaries.push(PacketSummary {
            offset,
            packet_bytes: packet_len,
            packet_type: packet_type.clone(),
            packet_type_raw_hex: hex(packet_type_bytes),
            set_id: set_id.clone(),
            packet_md5,
            checksum_valid,
            payload_bytes: payload.len(),
            payload_sha256: payload_sha256.clone(),
            payload_prefix_hex: hex(payload_prefix),
            payload_prefix_truncated: payload.len() > MAX_OPTIONAL_HEX_BYTES,
            recovery_exponent,
            recovery_data_sha256: recovery_data_sha256.clone(),
            main_body_md5: main_body_md5.clone(),
            main_body_md5_matches_declared_set_id,
            physical_regions: packet_physical_regions(offset, packet_len, &packet_type, payload),
        });

        if !checksum_valid {
            parsed.invalid_packet_count += 1;
            offset += packet_len;
            continue;
        }

        if packet_type == "PAR 2.0\\0Main" || packet_type == "PAR 2.0\\0FileDesc" {
            let definition_key = if packet_type == "PAR 2.0\\0Main" {
                format!("{}:main", set_id)
            } else {
                let file_id = payload.get(..16).map(hex).unwrap_or_default();
                format!("{}:file:{file_id}", set_id)
            };
            let definition_sha256 = payload_sha256.clone();
            if let Some(first) = parsed.packet_definitions.get(&definition_key) {
                if first.payload_sha256 != definition_sha256 {
                    let is_main = packet_type == "PAR 2.0\\0Main";
                    parsed.invalid_packet_count += 1;
                    parsed
                        .source_definition_conflicts
                        .push(SourceDefinitionConflict {
                            recovery_set_id: set_id.clone(),
                            definition_kind: if is_main { "main" } else { "file_description" },
                            file_id: (!is_main)
                                .then(|| payload.get(..16).map(hex).unwrap_or_default()),
                            first_packet_offset: first.packet_offset,
                            conflicting_packet_offset: offset,
                            first_payload_sha256: first.payload_sha256.clone(),
                            conflicting_payload_sha256: definition_sha256,
                        });
                }
            } else {
                parsed.packet_definitions.insert(
                    definition_key,
                    SourceDefinitionObservation {
                        payload_sha256: definition_sha256,
                        packet_offset: offset,
                    },
                );
            }
        }

        match packet_type.as_str() {
            "PAR 2.0\\0Main" if payload.len() >= 12 => {
                let block_size = read_u64(payload, 0).unwrap_or_default();
                let recoverable_file_count = read_u32(payload, 8).unwrap_or_default();
                let file_id_bytes = payload.len() - 12;
                let file_id_count = file_id_bytes / 16;
                let main_already_retained = parsed.mains_by_set.contains_key(&set_id);
                let retained_budget_exceeded = !main_already_retained
                    && file_id_count
                        > MAX_MAIN_FILE_IDS.saturating_sub(parsed.retained_main_file_id_count);
                if file_id_bytes > MAX_MAIN_FILE_IDS * 16 || retained_budget_exceeded {
                    parsed
                        .main_file_id_limit_observations
                        .push(MainFileIdLimitObservation {
                            packet_offset: offset,
                            recovery_set_id: set_id.clone(),
                            observed_file_id_count: file_id_bytes / 16,
                            trailing_file_id_bytes: file_id_bytes % 16,
                            limit_kind: if file_id_bytes > MAX_MAIN_FILE_IDS * 16 {
                                "packet-file-id-limit"
                            } else {
                                "retained-file-id-budget"
                            },
                            retained_file_id_count: parsed.retained_main_file_id_count,
                        });
                    parsed.invalid_packet_count += 1;
                    offset += packet_len;
                    continue;
                }
                let identical_main = main_already_retained
                    && parsed
                        .packet_definitions
                        .get(&format!("{set_id}:main"))
                        .is_some_and(|first| first.payload_sha256 == payload_sha256);
                if identical_main {
                    record_main_set_id_derivation(
                        &mut parsed,
                        &set_id,
                        main_body_md5.unwrap_or_else(|| hex(&md5(payload))),
                        offset,
                    );
                    offset += packet_len;
                    continue;
                }
                let (file_id_records, trailing_file_id_bytes) = payload[12..].as_chunks::<16>();
                let ids = file_id_records
                    .iter()
                    .map(|record| hex(record))
                    .collect::<Vec<_>>();
                let file_ids_unique = ids.iter().collect::<BTreeSet<_>>().len() == ids.len();
                if block_size > 0
                    && block_size.is_multiple_of(4)
                    && trailing_file_id_bytes.is_empty()
                    && file_ids_unique
                    && usize::try_from(recoverable_file_count).is_ok_and(|count| count <= ids.len())
                {
                    let recoverable_count = recoverable_file_count as usize;
                    let computed_recovery_set_id =
                        main_body_md5.unwrap_or_else(|| hex(&md5(payload)));
                    record_main_set_id_derivation(
                        &mut parsed,
                        &set_id,
                        computed_recovery_set_id,
                        offset,
                    );
                    if !main_already_retained {
                        parsed.retained_main_file_id_count += ids.len();
                    }
                    let main = MainPacket {
                        recoverable_file_count,
                        recoverable_file_ids: ids[..recoverable_count].to_vec(),
                        non_recoverable_file_ids: ids[recoverable_count..].to_vec(),
                        all_file_ids: ids,
                    };
                    parsed
                        .block_sizes_by_set
                        .entry(set_id.clone())
                        .or_insert(block_size);
                    parsed
                        .mains_by_set
                        .entry(set_id.clone())
                        .or_insert_with(|| main);
                } else {
                    parsed.invalid_packet_count += 1;
                }
            }
            "PAR 2.0\\0Main" => parsed.invalid_packet_count += 1,
            "PAR 2.0\\0FileDesc" if payload.len() >= 57 => {
                let file_id = hex(&payload[0..16]);
                let filename_field = &payload[56..];
                let trailing_zero_bytes = filename_field
                    .iter()
                    .rev()
                    .take_while(|byte| **byte == 0)
                    .count();
                let filename_bytes = &filename_field[..filename_field.len() - trailing_zero_bytes];
                let filename_padding = &filename_field[filename_bytes.len()..];
                let expected_padding = (4 - filename_bytes.len() % 4) % 4;
                let filename_padding_zero_valid = trailing_zero_bytes <= 3
                    && filename_padding.len() == expected_padding
                    && !filename_bytes.contains(&0);
                let filename_termination_status = if filename_bytes.contains(&0) {
                    "embedded-null-invalid"
                } else if filename_padding_zero_valid {
                    "not-null-terminated"
                } else {
                    "padding-invalid"
                };
                let file_length = read_u64(payload, 48).unwrap_or_default();
                let calculated_file_id =
                    md5_file_id(&payload[32..48], &payload[48..56], filename_bytes);
                let file_id_matches_description_fields = calculated_file_id == payload[0..16];
                let md5_full = hex(&payload[16..32]);
                let md5_first_16k = hex(&payload[32..48]);
                let small_file_hashes_consistent =
                    (file_length <= 16_384).then_some(md5_full == md5_first_16k);
                let retained_filename =
                    &filename_bytes[..filename_bytes.len().min(MAX_RETAINED_FILENAME_BYTES)];
                let retained_filename_hex =
                    &filename_bytes[..filename_bytes.len().min(MAX_RETAINED_FILENAME_HEX_BYTES)];
                let filename_utf8_valid = std::str::from_utf8(filename_bytes).is_ok();
                let filename_ascii_valid = filename_bytes.is_ascii();
                if !filename_ascii_valid || !filename_padding_zero_valid {
                    parsed.invalid_packet_count += 1;
                }
                let derived_file_id = hex(&calculated_file_id);
                let file_id_derivation_valid = !filename_bytes.is_empty()
                    && filename_ascii_valid
                    && filename_padding_zero_valid;
                let file_id_matches_derived =
                    file_id_derivation_valid.then_some(file_id_matches_description_fields);
                let filename_prefix =
                    &filename_bytes[..filename_bytes.len().min(MAX_OPTIONAL_HEX_BYTES / 2)];
                let filename_sha256 = sha256_hex(filename_bytes);
                let source = SourceFile {
                    recovery_set_id: set_id.clone(),
                    filename: String::from_utf8_lossy(retained_filename).into_owned(),
                    filename_bytes_length: filename_bytes.len(),
                    filename_bytes_nonempty: !filename_bytes.is_empty(),
                    filename_bytes_sha256: filename_sha256.clone(),
                    filename_field_sha256: sha256_hex(filename_field),
                    filename_bytes_prefix_hex: hex(filename_prefix),
                    filename_bytes_prefix_truncated: filename_bytes.len() > filename_prefix.len(),
                    filename_padding_bytes: filename_padding.len(),
                    filename_padding_valid: filename_padding_zero_valid,
                    file_length_raw_little_endian_hex: hex(&payload[48..56]),
                    short_file_hashes_consistent: small_file_hashes_consistent,
                    derived_file_id,
                    file_id_derivation_valid,
                    file_id_matches_derived,
                    file_length,
                    md5: md5_full,
                    md5_first_16k,
                    file_id,
                    file_id_matches_current_v2_derivation: file_id_matches_description_fields,
                    small_file_hashes_consistent,
                    filename_byte_length: filename_bytes.len(),
                    filename_truncated: retained_filename.len() < filename_bytes.len(),
                    filename_sha256,
                    filename_bytes_hex: hex(retained_filename_hex),
                    filename_bytes_hex_truncated: retained_filename_hex.len()
                        < filename_bytes.len(),
                    filename_utf8_valid,
                    filename_ascii_valid,
                    filename_padding_byte_length: filename_padding.len(),
                    filename_padding_zero_valid,
                    filename_termination_status,
                };
                parsed
                    .sources_by_set
                    .entry((set_id.clone(), source.file_id.clone()))
                    .or_insert(source);
            }
            "PAR 2.0\\0FileDesc" => parsed.invalid_packet_count += 1,
            "PAR 2.0\\0RecvSlic" if payload.len() >= 4 => {
                if let Some(exponent) = read_u32(payload, 0) {
                    parsed.recovery_packets.push(RecoveryPacket {
                        recovery_set_id: set_id.clone(),
                        exponent,
                        payload_bytes: payload.len(),
                        slice_sha256: recovery_data_sha256
                            .unwrap_or_else(|| sha256_hex(&payload[4..])),
                        packet_offset: offset,
                    });
                }
            }
            "PAR 2.0\\0RecvSlic" => parsed.invalid_packet_count += 1,
            "PAR 2.0\\0Creator" => {
                let creator = payload.split(|byte| *byte == 0).next().unwrap_or_default();
                parsed.creator = Some(String::from_utf8_lossy(creator).trim().to_owned());
            }
            "PAR 2.0\\0IFSC" if payload.len() >= 16 && (payload.len() - 16).is_multiple_of(20) => {
                let file_id = hex(&payload[..16]);
                let records = &payload[16..];
                let preview_len = records.len().min(MAX_IFSC_PREVIEW_BYTES) / 20 * 20;
                let preview = &records[..preview_len];
                let slice_preview = preview
                    .as_chunks::<20>()
                    .0
                    .iter()
                    .enumerate()
                    .map(|(slice_index, record)| InputFileSliceChecksum {
                        slice_index,
                        md5: hex(&record[..16]),
                        crc32: read_u32(record, 16).unwrap_or_default(),
                        crc32_little_endian_hex: hex(&record[16..20]),
                        source_byte_start: None,
                        source_byte_end_exclusive: None,
                        source_bytes_in_slice: None,
                        zero_padding_bytes: None,
                        source_range_valid: None,
                    })
                    .collect();
                let key = (set_id.clone(), file_id.clone());
                let candidate = IfscPacket {
                    records_offset: offset.saturating_add(80),
                    slice_count: records.len() / 20,
                    records_sha256: sha256_hex(records),
                    preview_hex: hex(preview),
                    preview_slice_count: preview.len() / 20,
                    slice_preview,
                    duplicate_conflict: false,
                };
                if let Some(previous) = parsed.ifsc_by_set_and_file.get_mut(&key) {
                    if previous.records_sha256 != candidate.records_sha256 {
                        previous.duplicate_conflict = true;
                        parsed.invalid_packet_count += 1;
                    }
                } else {
                    parsed.ifsc_by_set_and_file.insert(key, candidate);
                }
            }
            "PAR 2.0\\0IFSC" => parsed.invalid_packet_count += 1,
            "PAR 2.0\\0UniFileN" | "PAR 2.0\\0CommASCI" | "PAR 2.0\\0CommUni"
            | "PAR 2.0\\0FileSlic" | "PAR 2.0\\0PkdMain" | "PAR 2.0\\0PkdRecvS"
            | "PAR 2.0\\0RFSC" => {
                if packet_type == "PAR 2.0\\0UniFileN" {
                    let file_id = payload.get(..16).map(hex);
                    let filename_field = payload.get(16..);
                    parsed.unicode_filename_packets.push(UnicodeFilenamePacket {
                        packet_offset: offset,
                        recovery_set_id: set_id.clone(),
                        file_id,
                        filename_field_bytes_length: filename_field.map(<[u8]>::len),
                        filename_field_sha256: filename_field.map(sha256_hex),
                        filename_field_prefix_hex: filename_field
                            .map(|field| hex(&field[..field.len().min(MAX_OPTIONAL_HEX_BYTES)])),
                        filename_field_prefix_truncated: filename_field
                            .map(|field| field.len() > MAX_OPTIONAL_HEX_BYTES),
                        source_file_present: None,
                        main_packet_membership: None,
                        duplicate_occurrence_count: None,
                        duplicate_name_conflict: None,
                    });
                }
                let type_key = packet_type.clone();
                let prefix = &payload[..payload.len().min(MAX_OPTIONAL_HEX_BYTES)];
                let file_relationship_packet = matches!(
                    packet_type.as_str(),
                    "PAR 2.0\\0UniFileN" | "PAR 2.0\\0FileSlic" | "PAR 2.0\\0RFSC"
                );
                let related_file_id = if file_relationship_packet {
                    payload.get(..16).map(hex)
                } else {
                    None
                };
                if packet_type == "PAR 2.0\\0UniFileN" {
                    if payload.len() < 20 {
                        parsed.invalid_packet_count += 1;
                    } else if let Some(candidate) = parse_unicode_filename(&set_id, payload, offset)
                    {
                        let key = (candidate.recovery_set_id.clone(), candidate.file_id.clone());
                        if let Some(previous) =
                            parsed.unicode_filenames_by_set_and_file.get_mut(&key)
                        {
                            previous.duplicate_conflict |=
                                previous.raw_name_sha256 != candidate.raw_name_sha256;
                            previous.packet_count = previous.packet_count.saturating_add(1);
                            previous.last_packet_offset = candidate.last_packet_offset;
                        } else {
                            parsed
                                .unicode_filenames_by_set_and_file
                                .insert(key, candidate);
                        }
                    }
                }
                let related_file_id_status =
                    file_relationship_packet.then_some(if related_file_id.is_some() {
                        "file-id-observed"
                    } else {
                        "payload-shorter-than-file-id"
                    });
                let input_slice_index = if packet_type == "PAR 2.0\\0FileSlic" {
                    read_u64(payload, 16)
                } else {
                    None
                };
                let (recovery_checksum_record_count, recovery_checksum_trailing_bytes) =
                    if packet_type == "PAR 2.0\\0RFSC" {
                        payload.get(16..).map_or((None, None), |records| {
                            (Some(records.len() / 24), Some(records.len() % 24))
                        })
                    } else {
                        (None, None)
                    };
                parsed
                    .optional_packets
                    .entry(type_key)
                    .or_default()
                    .push(OptionalPacketSummary {
                        packet_offset: offset,
                        recovery_set_id: set_id.clone(),
                        related_file_id,
                        related_file_id_status,
                        related_source_file_present: None,
                        input_slice_index,
                        fileslic_relationship: None,
                        recovery_checksum_record_count,
                        recovery_checksum_trailing_bytes,
                        payload_bytes: payload.len(),
                        payload_sha256,
                        payload_hex: hex(prefix),
                        payload_hex_truncated: payload.len() > MAX_OPTIONAL_HEX_BYTES,
                    });
            }
            _ => {}
        }

        offset += packet_len;
    }

    let mut unicode_filename_groups: BTreeMap<(String, String), (usize, BTreeSet<String>)> =
        BTreeMap::new();
    for unicode_filename in &parsed.unicode_filename_packets {
        if let (Some(file_id), Some(filename_hash)) = (
            unicode_filename.file_id.as_ref(),
            unicode_filename.filename_field_sha256.as_ref(),
        ) {
            let entry = unicode_filename_groups
                .entry((unicode_filename.recovery_set_id.clone(), file_id.clone()))
                .or_default();
            entry.0 = entry.0.saturating_add(1);
            entry.1.insert(filename_hash.clone());
        }
    }
    for unicode_filename in &mut parsed.unicode_filename_packets {
        let Some(file_id) = unicode_filename.file_id.as_ref() else {
            continue;
        };
        let key = (unicode_filename.recovery_set_id.clone(), file_id.clone());
        unicode_filename.source_file_present = Some(parsed.sources_by_set.contains_key(&key));
        unicode_filename.main_packet_membership = parsed.mains_by_set.get(&key.0).map(|main| {
            main.all_file_ids
                .iter()
                .any(|main_file_id| main_file_id == file_id)
        });
        if let Some((count, name_hashes)) = unicode_filename_groups.get(&key) {
            unicode_filename.duplicate_occurrence_count = Some(*count);
            unicode_filename.duplicate_name_conflict = Some(name_hashes.len() > 1);
        }
    }

    let recovery_set_ids = parsed
        .packet_summaries
        .iter()
        .filter(|packet| packet.checksum_valid)
        .map(|packet| packet.set_id.clone())
        .collect::<BTreeSet<_>>();
    for packet in &parsed.recovery_packets {
        let set_id = &packet.recovery_set_id;
        let raw_exponent = packet.exponent;
        parsed
            .recovery_exponents_observed_by_set
            .entry(set_id.clone())
            .or_default()
            .insert(raw_exponent);
        if raw_exponent > u16::MAX as u32 {
            parsed.invalid_packet_count += 1;
            continue;
        }
        let exponent = if raw_exponent == u16::MAX as u32 {
            0
        } else {
            raw_exponent
        };
        let expected_payload_length = parsed
            .block_sizes_by_set
            .get(set_id)
            .map(|block_size| block_size.checked_add(4));
        match expected_payload_length {
            Some(Some(expected)) if u64::try_from(packet.payload_bytes).ok() == Some(expected) => {}
            Some(Some(_)) => {
                parsed.invalid_packet_count += 1;
                continue;
            }
            Some(None) => {
                unsupported_integer_range_count += 1;
                unsupported_integer_sets.insert(set_id.clone());
                continue;
            }
            None => {
                *parsed
                    .recovery_slice_lengths_unvalidated_by_set
                    .entry(set_id.clone())
                    .or_default() += 1;
            }
        }
        let key = (set_id.clone(), exponent);
        if let Some((previous_hash, previous_offset)) =
            parsed.recovery_slice_hashes_by_set_and_exponent.get(&key)
        {
            if previous_hash != &packet.slice_sha256
                && parsed.conflicting_recovery_exponents.insert(key.clone())
            {
                parsed.invalid_packet_count += 1;
                parsed
                    .recovery_exponent_conflicts
                    .push(RecoveryExponentConflict {
                        recovery_set_id: set_id.clone(),
                        exponent,
                        first_packet_offset: *previous_offset,
                        conflicting_packet_offset: packet.packet_offset,
                        first_slice_sha256: previous_hash.clone(),
                        conflicting_slice_sha256: packet.slice_sha256.clone(),
                    });
            }
        } else {
            parsed
                .recovery_slice_hashes_by_set_and_exponent
                .entry(key)
                .or_insert_with(|| (packet.slice_sha256.clone(), packet.packet_offset));
        }
        parsed
            .recovery_exponents_by_set
            .entry(set_id.clone())
            .or_default()
            .insert(exponent);
    }
    let input_file_slice_checksums = parsed
        .ifsc_by_set_and_file
        .iter()
        .map(|((set_id, file_id), ifsc)| {
            let source = parsed
                .sources_by_set
                .get(&(set_id.clone(), file_id.clone()));
            let expected_slice_count = source.and_then(|source| {
                parsed
                    .block_sizes_by_set
                    .get(set_id)
                    .filter(|block_size| **block_size > 0)
                    .map(|block_size| source.file_length.div_ceil(*block_size))
            });
            let block_size = parsed.block_sizes_by_set.get(set_id).copied();
            let slice_preview = ifsc
                .slice_preview
                .iter()
                .map(|slice| map_ifsc_slice_to_source(slice, source, block_size))
                .collect();
            InputFileSliceChecksums {
                recovery_set_id: set_id.clone(),
                file_id: file_id.clone(),
                slice_count: ifsc.slice_count,
                checksum_records_sha256: ifsc.records_sha256.clone(),
                checksum_records_preview_hex: ifsc.preview_hex.clone(),
                preview_slice_count: ifsc.preview_slice_count,
                preview_truncated: ifsc.preview_slice_count < ifsc.slice_count,
                slice_preview,
                source_file_present: source.is_some(),
                expected_slice_count_from_file_length: expected_slice_count,
                slice_count_matches_file_length: expected_slice_count
                    .map(|expected| expected == ifsc.slice_count as u64),
                duplicate_conflict: ifsc.duplicate_conflict,
            }
        })
        .collect::<Vec<_>>();
    let ifsc_slice_count_mismatch_count = input_file_slice_checksums
        .iter()
        .filter(|ifsc| ifsc.slice_count_matches_file_length == Some(false))
        .count();
    parsed.invalid_packet_count = parsed
        .invalid_packet_count
        .saturating_add(ifsc_slice_count_mismatch_count);
    let ifsc_slice_count_mismatches_by_set = input_file_slice_checksums
        .iter()
        .filter(|ifsc| ifsc.slice_count_matches_file_length == Some(false))
        .fold(BTreeMap::<String, usize>::new(), |mut counts, ifsc| {
            *counts.entry(ifsc.recovery_set_id.clone()).or_default() += 1;
            counts
        });
    let main_file_ids_by_set = parsed
        .mains_by_set
        .iter()
        .map(|(set_id, main)| {
            (
                set_id.clone(),
                main.all_file_ids.iter().cloned().collect::<BTreeSet<_>>(),
            )
        })
        .collect::<BTreeMap<_, _>>();
    let conflicting_main_sets = parsed
        .source_definition_conflicts
        .iter()
        .filter(|conflict| conflict.definition_kind == "main")
        .map(|conflict| conflict.recovery_set_id.clone())
        .collect::<BTreeSet<_>>();
    let mut conflicting_file_descriptions = BTreeMap::<String, BTreeSet<String>>::new();
    for conflict in parsed
        .source_definition_conflicts
        .iter()
        .filter(|conflict| conflict.definition_kind == "file_description")
    {
        if let Some(file_id) = conflict.file_id.as_ref() {
            conflicting_file_descriptions
                .entry(conflict.recovery_set_id.clone())
                .or_default()
                .insert(file_id.clone());
        }
    }
    let mut fileslic_duplicate_groups =
        BTreeMap::<(String, String, u64), (String, usize, bool)>::new();
    if let Some(fileslic_packets) = parsed.optional_packets.get("PAR 2.0\\0FileSlic") {
        for summary in fileslic_packets {
            let (Some(file_id), Some(slice_index)) =
                (summary.related_file_id.as_ref(), summary.input_slice_index)
            else {
                continue;
            };
            let key = (
                summary.recovery_set_id.clone(),
                file_id.clone(),
                slice_index,
            );
            fileslic_duplicate_groups
                .entry(key)
                .and_modify(|group| {
                    group.1 = group.1.saturating_add(1);
                    group.2 |= group.0 != summary.payload_sha256;
                })
                .or_insert_with(|| (summary.payload_sha256.clone(), 1, false));
        }
    }
    let mut optional_packets = std::mem::take(&mut parsed.optional_packets);
    let mut checksum_work_remaining = MAX_FILESLICE_CHECKSUM_WORK_BYTES;
    for (packet_type, summaries) in &mut optional_packets {
        for summary in summaries {
            summary.related_source_file_present = summary.related_file_id.as_ref().map(|file_id| {
                parsed
                    .sources_by_set
                    .contains_key(&(summary.recovery_set_id.clone(), file_id.clone()))
            });
            if packet_type == "PAR 2.0\\0FileSlic" {
                let mut relationship = resolve_fileslic_relationship(
                    summary,
                    &parsed,
                    &main_file_ids_by_set,
                    &conflicting_main_sets,
                    &conflicting_file_descriptions,
                    data,
                );
                if let (Some(file_id), Some(slice_index)) =
                    (summary.related_file_id.as_ref(), summary.input_slice_index)
                    && let Some(group) = fileslic_duplicate_groups.get(&(
                        summary.recovery_set_id.clone(),
                        file_id.clone(),
                        slice_index,
                    ))
                {
                    relationship.duplicate_status = if group.2 {
                        "conflicting-duplicate"
                    } else if group.1 > 1 {
                        "identical-duplicate"
                    } else {
                        "unique"
                    };
                }
                verify_fileslic_checksums(
                    &mut relationship,
                    summary,
                    &parsed,
                    data,
                    &mut checksum_work_remaining,
                );
                summary.fileslic_relationship = Some(relationship);
            }
        }
    }
    parsed.optional_packets = optional_packets;
    let recovery_sets = recovery_set_ids
        .iter()
        .map(|set_id| {
            let source_files = parsed
                .sources_by_set
                .iter()
                .filter(|((candidate_set_id, _), _)| candidate_set_id == set_id)
                .map(|(_, source)| source.clone())
                .collect::<Vec<_>>();
            let described_file_ids = source_files
                .iter()
                .map(|source| source.file_id.clone())
                .collect::<BTreeSet<_>>();
            let main_packet = parsed.mains_by_set.get(set_id).cloned();
            let main_file_ids = main_packet
                .as_ref()
                .map(|main| main.all_file_ids.iter().cloned().collect::<BTreeSet<_>>())
                .unwrap_or_default();
            let missing_file_description_ids = main_file_ids
                .difference(&described_file_ids)
                .cloned()
                .collect::<Vec<_>>();
            let unexpected_file_description_ids = described_file_ids
                .difference(&main_file_ids)
                .cloned()
                .collect::<Vec<_>>();
            let invalid_file_description_ids = source_files
                .iter()
                .filter(|source| {
                    source.small_file_hashes_consistent == Some(false)
                        || !source.filename_ascii_valid
                        || !source.filename_padding_zero_valid
                })
                .map(|source| source.file_id.clone())
                .collect::<Vec<_>>();
            let set_definition_conflicts = parsed
                .source_definition_conflicts
                .iter()
                .filter(|conflict| &conflict.recovery_set_id == set_id)
                .cloned()
                .collect::<Vec<_>>();
            let main_definition_conflict = set_definition_conflicts
                .iter()
                .any(|conflict| conflict.definition_kind == "main");
            let mut conflicting_file_description_ids = set_definition_conflicts
                .iter()
                .filter(|conflict| conflict.definition_kind == "file_description")
                .filter_map(|conflict| conflict.file_id.clone())
                .collect::<BTreeSet<_>>()
                .into_iter()
                .collect::<Vec<_>>();
            conflicting_file_description_ids.sort();
            let mut file_ids_by_filename = BTreeMap::<(usize, String), Vec<String>>::new();
            for source in &source_files {
                file_ids_by_filename
                    .entry((source.filename_byte_length, source.filename_sha256.clone()))
                    .or_default()
                    .push(source.file_id.clone());
            }
            let mut duplicate_filename_file_ids = file_ids_by_filename
                .into_values()
                .filter(|file_ids| file_ids.len() > 1)
                .flatten()
                .collect::<Vec<_>>();
            duplicate_filename_file_ids.sort();
            let file_id_derivation_mismatch_ids = source_files
                .iter()
                .filter(|source| !source.file_id_matches_current_v2_derivation)
                .map(|source| source.file_id.clone())
                .collect::<Vec<_>>();
            let source_descriptions_valid = invalid_file_description_ids.is_empty()
                && duplicate_filename_file_ids.is_empty()
                && conflicting_file_description_ids.is_empty();
            let source_file_bytes = source_files
                .iter()
                .try_fold(0_u64, |total, source| total.checked_add(source.file_length));
            let source_inventory_complete = main_packet.is_some()
                && missing_file_description_ids.is_empty()
                && unexpected_file_description_ids.is_empty()
                && !main_definition_conflict
                && source_descriptions_valid
                && source_file_bytes.is_some();
            let mut integer_range_valid = !unsupported_integer_sets.contains(set_id);
            let mut source_block_count_overflow = false;
            let source_block_count = if source_inventory_complete {
                let recoverable_file_ids = main_packet
                    .as_ref()
                    .map(|main| main.recoverable_file_ids.iter().collect::<BTreeSet<_>>())
                    .unwrap_or_default();
                parsed
                    .block_sizes_by_set
                    .get(set_id)
                    .filter(|size| **size > 0)
                    .and_then(|block_size| {
                        let count = source_files
                            .iter()
                            .filter(|source| recoverable_file_ids.contains(&source.file_id))
                            .try_fold(0u64, |total, source| {
                                total.checked_add(source.file_length.div_ceil(*block_size))
                            });
                        if count.is_none() {
                            source_block_count_overflow = true;
                            unsupported_integer_range_count += 1;
                            integer_range_valid = false;
                        }
                        count
                    })
                    .filter(|count| *count > 0)
            } else {
                None
            };
            let set_slice_checksums = input_file_slice_checksums
                .iter()
                .filter(|ifsc| &ifsc.recovery_set_id == set_id)
                .cloned()
                .collect::<Vec<_>>();
            if source_file_bytes.is_none() {
                unsupported_integer_range_count += 1;
                integer_range_valid = false;
            }
            let ifsc_relationships_valid = set_slice_checksums.iter().all(|ifsc| {
                !ifsc.duplicate_conflict
                    && ifsc.slice_count_matches_file_length != Some(false)
                    && (main_packet.is_none() || main_file_ids.contains(&ifsc.file_id))
            });
            RecoverySetInventory {
                recovery_set_id: set_id.clone(),
                source_block_size: parsed.block_sizes_by_set.get(set_id).copied(),
                source_block_count,
                source_block_limit: MAX_SOURCE_BLOCKS,
                source_block_count_within_current_v2_limit: source_block_count
                    .map(|count| count <= MAX_SOURCE_BLOCKS),
                source_block_count_overflow,
                main_packet,
                source_inventory_complete,
                ifsc_relationships_valid,
                source_descriptions_valid,
                main_definition_conflict,
                conflicting_file_description_ids,
                source_definition_conflicts: set_definition_conflicts,
                missing_file_description_ids,
                unexpected_file_description_ids,
                invalid_file_description_ids,
                duplicate_filename_file_ids,
                file_id_derivation_mismatch_ids,
                source_file_count: source_files.len(),
                source_file_bytes,
                integer_range_valid,
                source_file_bytes_overflow: source_file_bytes.is_none(),
                source_files,
                input_file_slice_checksums: set_slice_checksums,
                ifsc_slice_count_mismatch_count: *ifsc_slice_count_mismatches_by_set
                    .get(set_id)
                    .unwrap_or(&0),
                unique_recovery_slice_count: parsed
                    .recovery_exponents_by_set
                    .get(set_id)
                    .map_or(0, BTreeSet::len),
                recovery_exponents: parsed
                    .recovery_exponents_by_set
                    .get(set_id)
                    .map_or_else(Vec::new, |exponents| exponents.iter().copied().collect()),
                recovery_exponents_observed: parsed
                    .recovery_exponents_observed_by_set
                    .get(set_id)
                    .map_or_else(Vec::new, |exponents| exponents.iter().copied().collect()),
                recovery_slice_conflict_count: parsed
                    .conflicting_recovery_exponents
                    .iter()
                    .filter(|(conflicting_set_id, _)| conflicting_set_id == set_id)
                    .count(),
                recovery_slice_length_unvalidated_count: *parsed
                    .recovery_slice_lengths_unvalidated_by_set
                    .get(set_id)
                    .unwrap_or(&0),
                conflicting_recovery_exponents: parsed
                    .conflicting_recovery_exponents
                    .iter()
                    .filter(|(conflicting_set_id, _)| conflicting_set_id == set_id)
                    .map(|(_, exponent)| *exponent)
                    .collect(),
            }
        })
        .collect::<Vec<_>>();
    let single_set = (recovery_sets.len() == 1).then(|| &recovery_sets[0]);
    let source_inventory_complete = single_set.is_some_and(|set| set.source_inventory_complete);
    let ifsc_relationships_valid = recovery_sets.iter().all(|set| set.ifsc_relationships_valid);
    let source_descriptions_valid = !recovery_sets.is_empty()
        && recovery_sets
            .iter()
            .all(|set| set.source_descriptions_valid);
    let missing_file_description_ids = single_set
        .map(|set| set.missing_file_description_ids.clone())
        .unwrap_or_default();
    let unexpected_file_description_ids = single_set
        .map(|set| set.unexpected_file_description_ids.clone())
        .unwrap_or_default();
    let invalid_file_description_ids = single_set
        .map(|set| set.invalid_file_description_ids.clone())
        .unwrap_or_default();
    let duplicate_filename_file_ids = single_set
        .map(|set| set.duplicate_filename_file_ids.clone())
        .unwrap_or_default();
    let file_id_derivation_mismatch_ids = single_set
        .map(|set| set.file_id_derivation_mismatch_ids.clone())
        .unwrap_or_default();
    let source_definition_conflicts = parsed.source_definition_conflicts.clone();
    let main_definition_conflict = source_definition_conflicts
        .iter()
        .any(|conflict| conflict.definition_kind == "main");
    let mut conflicting_file_description_ids = source_definition_conflicts
        .iter()
        .filter(|conflict| conflict.definition_kind == "file_description")
        .filter_map(|conflict| conflict.file_id.clone())
        .collect::<Vec<_>>();
    conflicting_file_description_ids.sort();
    conflicting_file_description_ids.dedup();
    let source_block_size = single_set.and_then(|set| set.source_block_size);
    let main_packet = single_set.and_then(|set| set.main_packet.clone());
    let source_file_count = recovery_sets.iter().map(|set| set.source_file_count).sum();
    let source_file_bytes = recovery_sets
        .iter()
        .try_fold(0u64, |total, set| total.checked_add(set.source_file_bytes?));
    if source_file_bytes.is_none()
        && recovery_sets
            .iter()
            .all(|set| set.source_file_bytes.is_some())
    {
        unsupported_integer_range_count += 1;
    }
    parsed.invalid_packet_count += unsupported_integer_range_count;
    let source_file_bytes_overflow = source_file_bytes.is_none();
    let source_files = recovery_sets
        .iter()
        .flat_map(|set| set.source_files.iter().cloned())
        .collect::<Vec<_>>();
    let source_block_count = single_set.and_then(|set| set.source_block_count);
    let source_block_count_within_current_v2_limit =
        source_block_count.map(|count| count <= MAX_SOURCE_BLOCKS);
    let source_block_count_overflow = single_set.is_some_and(|set| set.source_block_count_overflow);
    let recovery_count = (!recovery_sets.is_empty()).then(|| {
        recovery_sets
            .iter()
            .map(|set| set.unique_recovery_slice_count)
            .sum()
    });
    let recovery_exponents = single_set.map(|set| set.recovery_exponents.clone());
    let recovery_slice_conflict_count = parsed.conflicting_recovery_exponents.len();
    let recovery_slice_length_unvalidated_count = parsed
        .recovery_slice_lengths_unvalidated_by_set
        .values()
        .copied()
        .sum();
    let packed_layout_present = parsed.optional_packets.contains_key("PAR 2.0\\0PkdMain")
        || parsed.optional_packets.contains_key("PAR 2.0\\0PkdRecvS");
    let recovery_percent =
        recovery_count
            .zip(source_block_count)
            .and_then(|(recovery_count, source_block_count)| {
                (source_block_count > 0
                    && source_block_count_within_current_v2_limit == Some(true)
                    && source_inventory_complete
                    && ifsc_relationships_valid
                    && !source_file_bytes_overflow
                    && !source_block_count_overflow
                    && parsed.conflicting_recovery_exponents.is_empty()
                    && main_packet.is_some()
                    && parsed.invalid_packet_count == 0
                    && !(parsed.packet_count >= MAX_PACKETS && offset < data.len())
                    && !packed_layout_present)
                    .then(|| (recovery_count as f64 / source_block_count as f64) * 100.0)
            });
    let recovery_percentage_basis =
        if main_definition_conflict || !conflicting_file_description_ids.is_empty() {
            "unavailable-conflicting-source-definition-packets"
        } else if !parsed.conflicting_recovery_exponents.is_empty() {
            "unavailable-conflicting-recovery-exponent-packets"
        } else if source_file_bytes_overflow {
            "unavailable-source-file-byte-total-overflow"
        } else if source_block_count_overflow {
            "unavailable-source-block-count-overflow"
        } else if packed_layout_present {
            "unavailable-packed-recovery-layout"
        } else if source_block_count_within_current_v2_limit == Some(false) {
            "unavailable-source-block-count-exceeds-current-v2-limit"
        } else if !ifsc_relationships_valid {
            "unavailable-ifsc-source-slice-relationship-conflict"
        } else if source_block_size.is_none() && recovery_count.is_some_and(|count| count > 0) {
            "unavailable-source-block-size-not-in-this-volume"
        } else if !source_inventory_complete || !source_block_count.is_some_and(|count| count > 0) {
            "unavailable-source-block-denominator"
        } else if recovery_percent.is_none() {
            "unavailable-invalid-or-incomplete-packets"
        } else {
            "unique-valid-recovery-exponents-over-recoverable-source-blocks"
        };
    let recovery_packets = parsed
        .recovery_packets
        .iter()
        .filter(|packet| {
            packet.exponent <= u16::MAX as u32
                && match parsed.block_sizes_by_set.get(&packet.recovery_set_id) {
                    Some(block_size) => block_size.checked_add(4).is_some_and(|expected| {
                        u64::try_from(packet.payload_bytes).ok() == Some(expected)
                    }),
                    None => true,
                }
        })
        .cloned()
        .collect();
    for filename in parsed.unicode_filenames_by_set_and_file.values_mut() {
        filename.source_file_present = Some(
            parsed
                .sources_by_set
                .contains_key(&(filename.recovery_set_id.clone(), filename.file_id.clone())),
        );
    }
    let unicode_filenames = parsed
        .unicode_filenames_by_set_and_file
        .values()
        .cloned()
        .collect();

    Par2Metadata {
        parser_engine: "nntmux-rust-ffi",
        creator: parsed.creator,
        packet_count: parsed.packet_count,
        packet_limit_reached: parsed.packet_count >= MAX_PACKETS && offset < data.len(),
        trailing_unparsed_bytes: data.len().saturating_sub(offset),
        unparsed_bytes: parsed.unparsed_bytes,
        invalid_packet_count: parsed.invalid_packet_count,
        unsupported_integer_range_count,
        integer_range_valid: unsupported_integer_range_count == 0,
        source_block_size,
        source_block_count,
        source_block_limit: MAX_SOURCE_BLOCKS,
        source_block_count_within_current_v2_limit,
        main_packet,
        main_packet_set_id_derivations: parsed.main_packet_set_id_derivations,
        main_file_id_limit: MAX_MAIN_FILE_IDS,
        retained_main_file_id_count: parsed.retained_main_file_id_count,
        main_file_id_limit_reached: !parsed.main_file_id_limit_observations.is_empty(),
        main_file_id_limit_observations: parsed.main_file_id_limit_observations,
        source_inventory_complete,
        ifsc_relationships_valid,
        source_descriptions_valid,
        main_definition_conflict,
        conflicting_file_description_ids,
        source_definition_conflicts,
        missing_file_description_ids,
        unexpected_file_description_ids,
        invalid_file_description_ids,
        duplicate_filename_file_ids,
        file_id_derivation_mismatch_ids,
        source_file_count,
        source_file_bytes,
        source_file_bytes_overflow,
        source_block_count_overflow,
        source_files,
        unicode_filenames,
        input_file_slice_checksums,
        ifsc_slice_count_mismatch_count,
        recovery_set_ids: recovery_set_ids.into_iter().collect(),
        recovery_sets,
        unique_recovery_slice_count: recovery_count,
        recovery_exponents,
        recovery_slice_conflict_count,
        recovery_slice_length_unvalidated_count,
        conflicting_recovery_exponents: parsed.recovery_exponent_conflicts,
        recovery_packets,
        recovery_to_source_percent: recovery_percent,
        packed_layout_present,
        recovery_percentage_basis,
        optional_packet_summaries: parsed.optional_packets,
        unicode_filename_packets: parsed.unicode_filename_packets,
        packet_summaries: parsed.packet_summaries,
        packet_type_counts: parsed.packet_type_counts,
    }
}

fn record_main_set_id_derivation(
    parsed: &mut Parsed,
    set_id: &str,
    computed: String,
    offset: usize,
) {
    let matches_current_v2_derivation = computed == set_id;
    parsed
        .main_packet_set_id_derivations
        .push(RecoverySetIdDerivation {
            packet_offset: offset,
            basis: "md5-of-exact-main-packet-body-par2-v2",
            observed_header_recovery_set_id: set_id.to_owned(),
            computed_current_v2_recovery_set_id: computed,
            matches_current_v2_derivation,
            status: if matches_current_v2_derivation {
                "matches-current-v2-derivation"
            } else {
                "differs-from-current-v2-derivation-observed-only"
            },
        });
}

fn resolve_fileslic_relationship(
    summary: &OptionalPacketSummary,
    parsed: &Parsed,
    main_file_ids_by_set: &BTreeMap<String, BTreeSet<String>>,
    conflicting_main_sets: &BTreeSet<String>,
    conflicting_file_descriptions: &BTreeMap<String, BTreeSet<String>>,
    data: &[u8],
) -> FileSlicRelationship {
    let file_id = summary.related_file_id.as_deref();
    let slice_index = summary.input_slice_index;
    let has_complete_identity = summary.payload_bytes >= 24;
    let physical_data_offset =
        has_complete_identity.then(|| summary.packet_offset.saturating_add(88));
    let physical_slice_field_bytes =
        has_complete_identity.then(|| summary.payload_bytes.saturating_sub(24));
    let main = parsed.mains_by_set.get(&summary.recovery_set_id);
    let block_size = parsed
        .block_sizes_by_set
        .get(&summary.recovery_set_id)
        .copied();
    let source = file_id.and_then(|file_id| {
        parsed
            .sources_by_set
            .get(&(summary.recovery_set_id.clone(), file_id.to_owned()))
    });
    let conflicted = conflicting_main_sets.contains(&summary.recovery_set_id)
        || file_id.is_some_and(|file_id| {
            conflicting_file_descriptions
                .get(&summary.recovery_set_id)
                .is_some_and(|file_ids| file_ids.contains(file_id))
        });

    let layout_status = if file_id.is_none() || slice_index.is_none() {
        "truncated-identity"
    } else if main.is_none() || block_size.is_none() {
        "unavailable-main"
    } else if conflicted {
        "conflicting-definition"
    } else if !file_id.is_some_and(|file_id| {
        main_file_ids_by_set
            .get(&summary.recovery_set_id)
            .is_some_and(|file_ids| file_ids.contains(file_id))
    }) {
        "file-not-in-main"
    } else if source.is_none() {
        "unavailable-file-description"
    } else {
        "pending"
    };

    let mut logical_file_offset = None;
    let mut logical_byte_length = None;
    let mut expected_alignment_bytes = None;
    let mut alignment_padding_zero_valid = None;
    let mut final_layout_status = layout_status;

    if layout_status == "pending" {
        let source = source.expect("pending layout has a source description");
        let block_size = block_size.expect("pending layout has a Main slice size");
        let slice_index = slice_index.expect("pending layout has a slice index");
        match slice_index.checked_mul(block_size) {
            None => final_layout_status = "offset-overflow",
            Some(file_offset) if file_offset >= source.file_length => {
                final_layout_status = "index-out-of-range";
                logical_file_offset = Some(file_offset);
            }
            Some(file_offset) => {
                logical_file_offset = Some(file_offset);
                let content_length = source
                    .file_length
                    .saturating_sub(file_offset)
                    .min(block_size);
                logical_byte_length = Some(content_length);
                let padding_bytes = ((4 - content_length % 4) % 4) as usize;
                expected_alignment_bytes = Some(padding_bytes);
                let content_length_usize = usize::try_from(content_length).ok();
                let expected_physical_length =
                    content_length_usize.and_then(|length| length.checked_add(padding_bytes));
                if expected_physical_length != physical_slice_field_bytes {
                    final_layout_status = "physical-length-mismatch";
                } else {
                    let padding_start = physical_data_offset
                        .unwrap_or_default()
                        .saturating_add(content_length_usize.unwrap_or_default());
                    let padding_end = padding_start.saturating_add(padding_bytes);
                    let padding_is_zero = data
                        .get(padding_start..padding_end)
                        .is_some_and(|padding| padding.iter().all(|byte| *byte == 0));
                    alignment_padding_zero_valid = Some(padding_is_zero);
                    if !padding_is_zero {
                        final_layout_status = "nonzero-alignment-padding";
                    } else {
                        final_layout_status = "resolved";
                    }
                }
            }
        }
    }

    let (ifsc_relationship_status, ifsc_record_offset, ifsc_md5, ifsc_crc32) =
        if logical_byte_length.is_none() {
            ("layout-unresolved", None, None, None)
        } else if let (Some(file_id), Some(slice_index)) = (file_id, slice_index) {
            match parsed
                .ifsc_by_set_and_file
                .get(&(summary.recovery_set_id.clone(), file_id.to_owned()))
            {
                None => ("unavailable-ifsc", None, None, None),
                Some(ifsc) if ifsc.duplicate_conflict => ("conflicting-ifsc", None, None, None),
                Some(ifsc)
                    if source.is_some_and(|source| {
                        block_size.is_some_and(|block_size| {
                            source.file_length.div_ceil(block_size) != ifsc.slice_count as u64
                        })
                    }) =>
                {
                    ("slice-count-mismatch", None, None, None)
                }
                Some(ifsc)
                    if usize::try_from(slice_index).map_or(true, |i| i >= ifsc.slice_count) =>
                {
                    ("record-out-of-range", None, None, None)
                }
                Some(ifsc) => {
                    let record_offset = usize::try_from(slice_index)
                        .ok()
                        .and_then(|index| index.checked_mul(20))
                        .and_then(|delta| ifsc.records_offset.checked_add(delta));
                    match record_offset.and_then(|offset| {
                        offset
                            .checked_add(20)
                            .and_then(|end| data.get(offset..end).map(|record| (offset, record)))
                    }) {
                        None => ("record-out-of-range", None, None, None),
                        Some((offset, record)) => {
                            let crc32 = read_u32(record, 16).map(|value| format!("{value:08X}"));
                            (
                                "record-linked",
                                Some(offset),
                                Some(hex(&record[..16])),
                                crc32,
                            )
                        }
                    }
                }
            }
        } else {
            ("layout-unresolved", None, None, None)
        };

    FileSlicRelationship {
        index_basis: "file-local-byte-offset-formula",
        layout_status: final_layout_status,
        duplicate_status: "unique",
        logical_file_offset,
        logical_byte_length,
        file_description_id_derivation_matches: source
            .map(|source| source.file_id_matches_current_v2_derivation),
        physical_data_offset,
        physical_slice_field_bytes,
        expected_alignment_bytes,
        alignment_padding_zero_valid,
        ifsc_relationship_status,
        ifsc_record_offset,
        ifsc_record_index: (ifsc_record_offset.is_some())
            .then_some(slice_index)
            .flatten(),
        ifsc_md5,
        ifsc_crc32,
        source_slice_checksum_status: "not-checked",
        source_slice_checksum_bytes: None,
        source_slice_md5_calculated: None,
        source_slice_md5_matches: None,
        source_slice_crc32_calculated: None,
        source_slice_crc32_matches: None,
    }
}

fn verify_fileslic_checksums(
    relationship: &mut FileSlicRelationship,
    summary: &OptionalPacketSummary,
    parsed: &Parsed,
    data: &[u8],
    checksum_work_remaining: &mut u64,
) {
    if relationship.layout_status != "resolved" {
        relationship.source_slice_checksum_status = "layout-unresolved";
        return;
    }
    if relationship.duplicate_status == "conflicting-duplicate" {
        relationship.source_slice_checksum_status = "conflicting-fileslic";
        return;
    }
    if relationship.ifsc_relationship_status != "record-linked" {
        relationship.source_slice_checksum_status = match relationship.ifsc_relationship_status {
            "conflicting-ifsc" => "conflicting-ifsc",
            _ => "ifsc-unavailable",
        };
        return;
    }

    let Some(block_size) = parsed
        .block_sizes_by_set
        .get(&summary.recovery_set_id)
        .copied()
    else {
        relationship.source_slice_checksum_status = "ifsc-unavailable";
        return;
    };
    if block_size > *checksum_work_remaining {
        relationship.source_slice_checksum_status = "work-budget-exceeded";
        return;
    }
    let (Some(data_start), Some(content_length), Some(record_start)) = (
        relationship.physical_data_offset,
        relationship.logical_byte_length,
        relationship.ifsc_record_offset,
    ) else {
        relationship.source_slice_checksum_status = "content-range-unavailable";
        return;
    };
    let (Ok(block_size_usize), Ok(content_length_usize)) =
        (usize::try_from(block_size), usize::try_from(content_length))
    else {
        relationship.source_slice_checksum_status = "content-range-unavailable";
        return;
    };
    if content_length > block_size {
        relationship.source_slice_checksum_status = "content-range-unavailable";
        return;
    }
    let Some(packet_length_offset) = summary.packet_offset.checked_add(8) else {
        relationship.source_slice_checksum_status = "content-range-unavailable";
        return;
    };
    let Some(packet_length) =
        read_u64(data, packet_length_offset).and_then(|length| usize::try_from(length).ok())
    else {
        relationship.source_slice_checksum_status = "content-range-unavailable";
        return;
    };
    let Some(packet_end) = summary.packet_offset.checked_add(packet_length) else {
        relationship.source_slice_checksum_status = "content-range-unavailable";
        return;
    };
    let Some(content_end) = data_start.checked_add(content_length_usize) else {
        relationship.source_slice_checksum_status = "content-range-unavailable";
        return;
    };
    let Some(record_end) = record_start.checked_add(20) else {
        relationship.source_slice_checksum_status = "content-range-unavailable";
        return;
    };
    let (Some(content), Some(record)) = (
        (data_start <= content_end && content_end <= packet_end && packet_end <= data.len())
            .then(|| data.get(data_start..content_end))
            .flatten(),
        data.get(record_start..record_end),
    ) else {
        relationship.source_slice_checksum_status = "content-range-unavailable";
        return;
    };

    let mut md5_hasher = Md5::new();
    let mut crc32_hasher = crc32fast::Hasher::new();
    md5_hasher.update(content);
    crc32_hasher.update(content);
    let zero_padding_length = block_size_usize - content_length_usize;
    let zeros = [0u8; 8192];
    let mut zero_bytes_remaining = zero_padding_length;
    while zero_bytes_remaining > 0 {
        let chunk_length = zero_bytes_remaining.min(zeros.len());
        md5_hasher.update(&zeros[..chunk_length]);
        crc32_hasher.update(&zeros[..chunk_length]);
        zero_bytes_remaining -= chunk_length;
    }

    let calculated_md5 = md5_hasher.finalize();
    let calculated_crc32 = crc32_hasher.finalize();
    let md5_matches = calculated_md5[..] == record[..16];
    let expected_crc32 = u32::from_le_bytes(record[16..20].try_into().expect("four-byte CRC32"));
    let crc32_matches = calculated_crc32 == expected_crc32;
    relationship.source_slice_checksum_bytes = Some(block_size);
    relationship.source_slice_md5_calculated = Some(hex(&calculated_md5));
    relationship.source_slice_md5_matches = Some(md5_matches);
    relationship.source_slice_crc32_calculated = Some(format!("{calculated_crc32:08X}"));
    relationship.source_slice_crc32_matches = Some(crc32_matches);
    relationship.source_slice_checksum_status = if md5_matches && crc32_matches {
        "match"
    } else {
        "mismatch"
    };
    *checksum_work_remaining -= block_size;
}

fn packet_physical_regions(
    packet_offset: usize,
    packet_bytes: usize,
    packet_type: &str,
    payload: &[u8],
) -> Vec<PacketPhysicalRegion> {
    let Some(payload_offset) = packet_offset.checked_add(64) else {
        return Vec::new();
    };
    let Some(packet_end) = packet_offset.checked_add(packet_bytes) else {
        return Vec::new();
    };
    let mut regions = vec![
        PacketPhysicalRegion {
            kind: "packet_header",
            offset: packet_offset,
            byte_length: 64,
            record_count: None,
            record_byte_length: None,
        },
        PacketPhysicalRegion {
            kind: "packet_payload",
            offset: payload_offset,
            byte_length: payload.len(),
            record_count: None,
            record_byte_length: None,
        },
    ];

    match packet_type {
        "PAR 2.0\\0IFSC" if payload.len() >= 16 => {
            let records_offset = match payload_offset.checked_add(16) {
                Some(offset) => offset,
                None => return regions,
            };
            let records_bytes = payload.len() - 16;
            let record_count = records_bytes / 20;
            regions.push(PacketPhysicalRegion {
                kind: "ifsc_file_id",
                offset: payload_offset,
                byte_length: 16,
                record_count: None,
                record_byte_length: None,
            });
            regions.push(PacketPhysicalRegion {
                kind: "ifsc_records",
                offset: records_offset,
                byte_length: record_count * 20,
                record_count: Some(record_count),
                record_byte_length: Some(20),
            });
            if !records_bytes.is_multiple_of(20)
                && let Some(trailing_offset) = records_offset.checked_add(record_count * 20)
            {
                regions.push(PacketPhysicalRegion {
                    kind: "ifsc_trailing_bytes",
                    offset: trailing_offset,
                    byte_length: records_bytes % 20,
                    record_count: None,
                    record_byte_length: None,
                });
            }
        }
        "PAR 2.0\\0RecvSlic" if payload.len() >= 4 => {
            let Some(recovery_data_offset) = payload_offset.checked_add(4) else {
                return regions;
            };
            regions.push(PacketPhysicalRegion {
                kind: "recovery_exponent",
                offset: payload_offset,
                byte_length: 4,
                record_count: None,
                record_byte_length: None,
            });
            regions.push(PacketPhysicalRegion {
                kind: "recovery_data",
                offset: recovery_data_offset,
                byte_length: payload.len() - 4,
                record_count: None,
                record_byte_length: None,
            });
        }
        "PAR 2.0\\0FileSlic" => {
            let file_id_bytes = payload.len().min(16);
            if file_id_bytes > 0 {
                regions.push(PacketPhysicalRegion {
                    kind: if file_id_bytes == 16 {
                        "fileslic_file_id"
                    } else {
                        "fileslic_truncated_file_id"
                    },
                    offset: payload_offset,
                    byte_length: file_id_bytes,
                    record_count: None,
                    record_byte_length: None,
                });
            }
            let index_bytes = payload.len().saturating_sub(16).min(8);
            if index_bytes > 0
                && let Some(index_offset) = payload_offset.checked_add(16)
            {
                regions.push(PacketPhysicalRegion {
                    kind: if index_bytes == 8 {
                        "fileslic_input_slice_index"
                    } else {
                        "fileslic_truncated_input_slice_index"
                    },
                    offset: index_offset,
                    byte_length: index_bytes,
                    record_count: None,
                    record_byte_length: None,
                });
            }
            if payload.len() > 24
                && let Some(slice_field_offset) = payload_offset.checked_add(24)
            {
                regions.push(PacketPhysicalRegion {
                    kind: "fileslic_physical_slice_field",
                    offset: slice_field_offset,
                    byte_length: payload.len() - 24,
                    record_count: None,
                    record_byte_length: None,
                });
            }
        }
        _ => {}
    }

    debug_assert_eq!(packet_end - payload_offset, payload.len());
    regions
}

fn find_magic(data: &[u8], from: usize) -> Option<usize> {
    data.get(from..)?
        .windows(MAGIC.len())
        .position(|window| window == MAGIC)
        .map(|position| from + position)
}

fn read_u32(data: &[u8], offset: usize) -> Option<u32> {
    Some(u32::from_le_bytes(
        data.get(offset..offset + 4)?.try_into().ok()?,
    ))
}

fn read_u64(data: &[u8], offset: usize) -> Option<u64> {
    Some(u64::from_le_bytes(
        data.get(offset..offset + 8)?.try_into().ok()?,
    ))
}

fn type_string(bytes: &[u8]) -> String {
    // Packet types are exact 16-byte identifiers, not NUL-terminated strings.
    // Preserve the legacy labels only for canonical core/optional identifiers.
    // An extension with a matching prefix must never dispatch as a known type.
    let known = match bytes {
        b"PAR 2.0\0Main\0\0\0\0" => Some("PAR 2.0\\0Main"),
        b"PAR 2.0\0FileDesc" => Some("PAR 2.0\\0FileDesc"),
        b"PAR 2.0\0IFSC\0\0\0\0" => Some("PAR 2.0\\0IFSC"),
        b"PAR 2.0\0RecvSlic" => Some("PAR 2.0\\0RecvSlic"),
        b"PAR 2.0\0Creator\0" => Some("PAR 2.0\\0Creator"),
        b"PAR 2.0\0UniFileN" => Some("PAR 2.0\\0UniFileN"),
        b"PAR 2.0\0CommASCI" => Some("PAR 2.0\\0CommASCI"),
        b"PAR 2.0\0CommUni\0" => Some("PAR 2.0\\0CommUni"),
        b"PAR 2.0\0FileSlic" => Some("PAR 2.0\\0FileSlic"),
        b"PAR 2.0\0PkdMain\0" => Some("PAR 2.0\\0PkdMain"),
        b"PAR 2.0\0PkdRecvS" => Some("PAR 2.0\\0PkdRecvS"),
        b"PAR 2.0\0RFSC\0\0\0\0" => Some("PAR 2.0\\0RFSC"),
        _ => None,
    };
    known.map_or_else(|| format!("unknown:{}", hex(bytes)), str::to_owned)
}

fn md5(bytes: &[u8]) -> [u8; 16] {
    Md5::digest(bytes).into()
}

fn md5_file_id(md5_first_16k: &[u8], file_length: &[u8], filename: &[u8]) -> [u8; 16] {
    let mut digest = Md5::new();
    digest.update(md5_first_16k);
    digest.update(file_length);
    digest.update(filename);
    digest.finalize().into()
}

fn hex(bytes: &[u8]) -> String {
    const DIGITS: &[u8; 16] = b"0123456789abcdef";
    let mut output = String::with_capacity(bytes.len() * 2);
    for byte in bytes {
        output.push(DIGITS[(byte >> 4) as usize] as char);
        output.push(DIGITS[(byte & 0x0f) as usize] as char);
    }
    output
}

fn sha256_hex(bytes: &[u8]) -> String {
    use sha2::{Digest as Sha2Digest, Sha256 as Sha2};

    hex(&Sha2::digest(bytes))
}

fn map_ifsc_slice_to_source(
    slice: &InputFileSliceChecksum,
    source: Option<&SourceFile>,
    block_size: Option<u64>,
) -> InputFileSliceChecksum {
    let Some((source, block_size)) = source.zip(block_size.filter(|size| *size > 0)) else {
        return slice.clone();
    };
    let Some(slice_index) = u64::try_from(slice.slice_index).ok() else {
        return slice.clone();
    };
    let Some(source_byte_start) = slice_index.checked_mul(block_size) else {
        return slice.clone();
    };
    let expected_slice_count = source.file_length.div_ceil(block_size);
    let source_range_valid = slice_index < expected_slice_count;
    let source_bytes_in_slice = source_range_valid.then(|| {
        source
            .file_length
            .saturating_sub(source_byte_start)
            .min(block_size)
    });
    let source_byte_end_exclusive =
        source_bytes_in_slice.and_then(|slice_bytes| source_byte_start.checked_add(slice_bytes));
    let zero_padding_bytes = source_bytes_in_slice.map(|slice_bytes| block_size - slice_bytes);

    InputFileSliceChecksum {
        slice_index: slice.slice_index,
        md5: slice.md5.clone(),
        crc32: slice.crc32,
        crc32_little_endian_hex: slice.crc32_little_endian_hex.clone(),
        source_byte_start: Some(source_byte_start),
        source_byte_end_exclusive,
        source_bytes_in_slice,
        zero_padding_bytes,
        source_range_valid: Some(source_range_valid),
    }
}

fn parse_unicode_filename(
    recovery_set_id: &str,
    payload: &[u8],
    packet_offset: usize,
) -> Option<UnicodeFilename> {
    let file_id = hex(payload.get(..16)?);
    let raw_name = payload.get(16..)?;
    if raw_name.is_empty() || raw_name.len() % 4 != 0 {
        return None;
    }

    // UniFileN has no terminator. MultiPar stores UTF-16LE and adds a zero
    // code unit when needed to align an odd number of UTF-16 units to four bytes.
    let (name_bytes, padding_byte_length) = if raw_name.ends_with(&[0, 0]) {
        (&raw_name[..raw_name.len() - 2], 2)
    } else {
        (raw_name, 0)
    };
    let utf16_valid = valid_utf16le_filename(name_bytes);
    let filename_truncated = name_bytes.len() > MAX_DECODED_UNICODE_FILENAME_BYTES;
    let filename = if utf16_valid && !filename_truncated {
        let units = name_bytes
            .as_chunks::<2>()
            .0
            .iter()
            .map(|pair| u16::from_le_bytes(*pair))
            .collect::<Vec<_>>();
        String::from_utf16(&units).ok()
    } else {
        None
    };

    Some(UnicodeFilename {
        recovery_set_id: recovery_set_id.to_owned(),
        file_id,
        filename,
        raw_name_bytes: raw_name.len(),
        raw_name_sha256: sha256_hex(raw_name),
        filename_utf16_bytes: name_bytes.len(),
        filename_sha256: sha256_hex(name_bytes),
        filename_truncated,
        utf16_valid,
        padding_byte_length,
        first_packet_offset: packet_offset,
        last_packet_offset: packet_offset,
        packet_count: 1,
        duplicate_conflict: false,
        source_file_present: None,
    })
}

fn valid_utf16le_filename(bytes: &[u8]) -> bool {
    if bytes.is_empty() || !bytes.len().is_multiple_of(2) {
        return false;
    }

    let mut units = bytes
        .as_chunks::<2>()
        .0
        .iter()
        .map(|pair| u16::from_le_bytes(*pair));
    while let Some(unit) = units.next() {
        if unit == 0 {
            return false;
        }
        if (0xD800..=0xDBFF).contains(&unit) {
            if !units
                .next()
                .is_some_and(|low| (0xDC00..=0xDFFF).contains(&low))
            {
                return false;
            }
        } else if (0xDC00..=0xDFFF).contains(&unit) {
            return false;
        }
    }

    true
}

#[cfg(test)]
mod tests {
    use super::*;

    fn packet(kind: &[u8], payload: &[u8]) -> Vec<u8> {
        let padded_payload_length = payload.len().div_ceil(4) * 4;
        let mut packet = Vec::new();
        packet.extend_from_slice(MAGIC);
        let packet_length = 64 + padded_payload_length as u64;
        packet.extend_from_slice(&packet_length.to_le_bytes());
        packet.extend_from_slice(&[0; 16]);
        packet.extend_from_slice(&[b'S'; 16]);
        let mut packet_type = [0; 16];
        packet_type[..kind.len()].copy_from_slice(kind);
        packet.extend_from_slice(&packet_type);
        packet.extend_from_slice(payload);
        packet.resize(64 + padded_payload_length, 0);
        let digest = md5(&packet[32..]);
        packet[16..32].copy_from_slice(&digest);
        packet
    }

    fn packet_offset_of(data: &[u8], kind: &[u8]) -> usize {
        let mut offset = 0;
        loop {
            let packet_offset = find_magic(data, offset).expect("fixture packet exists");
            if &data[packet_offset + 48..packet_offset + 48 + kind.len()] == kind {
                return packet_offset;
            }
            let packet_length =
                usize::try_from(read_u64(data, packet_offset + 8).unwrap()).unwrap();
            offset = packet_offset + packet_length;
        }
    }

    fn rehash_packet(data: &mut [u8], packet_offset: usize) {
        let packet_length = usize::try_from(read_u64(data, packet_offset + 8).unwrap()).unwrap();
        let packet_end = packet_offset + packet_length;
        let digest = md5(&data[packet_offset + 32..packet_end]);
        data[packet_offset + 16..packet_offset + 32].copy_from_slice(&digest);
    }

    fn fileslic_checksum_fixture() -> (Vec<u8>, [usize; 2]) {
        let file_id = [b'F'; 16];
        let mut input = single_file_set(file_id, 16, 21, b"file.rar", None);
        let ifsc_offset = packet_offset_of(&input, b"PAR 2.0\0IFSC");
        input[ifsc_offset + 80..ifsc_offset + 96].copy_from_slice(&[
            0x1d, 0x64, 0xdc, 0xe2, 0x39, 0xc4, 0x43, 0x7b, 0x77, 0x36, 0x04, 0x1d, 0xb0, 0x89,
            0xe1, 0xb9,
        ]);
        input[ifsc_offset + 96..ifsc_offset + 100].copy_from_slice(&[0x93, 0xc0, 0x3a, 0x94]);
        input[ifsc_offset + 100..ifsc_offset + 116].copy_from_slice(&[
            0x01, 0x9f, 0x1e, 0x7d, 0x7b, 0xdd, 0x0b, 0xe0, 0x6c, 0xbb, 0x61, 0x3e, 0x55, 0xe7,
            0x82, 0x68,
        ]);
        input[ifsc_offset + 116..ifsc_offset + 120].copy_from_slice(&[0x88, 0x74, 0xc5, 0x63]);
        rehash_packet(&mut input, ifsc_offset);

        let mut full_slice = file_id.to_vec();
        full_slice.extend_from_slice(&0u64.to_le_bytes());
        full_slice.extend_from_slice(b"abcdefghijklmnop");
        let full_packet_offset = input.len();
        input.extend(packet(b"PAR 2.0\0FileSlic", &full_slice));

        let mut final_slice = file_id.to_vec();
        final_slice.extend_from_slice(&1u64.to_le_bytes());
        final_slice.extend_from_slice(b"qrstu");
        final_slice.extend_from_slice(&[0; 3]);
        let final_packet_offset = input.len();
        input.extend(packet(b"PAR 2.0\0FileSlic", &final_slice));

        (input, [full_packet_offset + 88, final_packet_offset + 88])
    }

    #[test]
    fn parses_file_lengths_recovery_counts_and_checksum_validation() {
        let file_id = [b'F'; 16];
        let input = single_file_set(file_id, 4096, 5000, b"payload.rar", Some(0));
        let metadata = parse(&input);

        assert_eq!(metadata.packet_count, 4);
        assert_eq!(metadata.invalid_packet_count, 0);
        assert_eq!(metadata.source_files[0].filename, "payload.rar");
        assert_eq!(metadata.source_files[0].file_length, 5000);
        assert_eq!(metadata.source_block_count, Some(2));
        assert_eq!(metadata.recovery_to_source_percent, Some(50.0));
        assert!(metadata.source_inventory_complete);
        assert_eq!(metadata.recovery_sets.len(), 1);
        assert_eq!(metadata.recovery_sets[0].unique_recovery_slice_count, 1);
    }

    #[test]
    fn gates_recovery_percent_on_current_v2_source_slice_capacity() {
        for (count, within) in [(32_768u64, true), (32_769, false)] {
            let input = single_file_set([b'F'; 16], 4, count * 4, b"payload.rar", Some(65535));
            let metadata = parse(&input);
            assert_eq!(metadata.source_block_count, Some(count));
            assert_eq!(metadata.invalid_packet_count, 0);
            assert!(metadata.source_inventory_complete);
            assert_eq!(metadata.recovery_exponents, Some(vec![0]));
            assert_eq!(metadata.recovery_to_source_percent.is_some(), within);
            let json = serde_json::to_value(metadata).unwrap();
            assert_eq!(json["source_block_limit"], 32_768);
            assert_eq!(json["source_block_count_within_current_v2_limit"], within);
            assert_eq!(
                json["recovery_sets"][0]["source_block_count_within_current_v2_limit"],
                within
            );
            if !within {
                assert_eq!(
                    json["recovery_percentage_basis"],
                    "unavailable-source-block-count-exceeds-current-v2-limit"
                );
            }
        }
        let input = single_file_set([b'F'; 16], 4, 4, b"payload.rar", None);
        let main_length = read_u64(&input, 8).unwrap() as usize;
        let json = serde_json::to_value(parse(&input[main_length..])).unwrap();
        assert_eq!(
            json["source_block_count_within_current_v2_limit"],
            serde_json::Value::Null
        );
    }

    #[test]
    fn source_slice_capacity_sums_only_recoverable_files() {
        for (recoverable_count, expected_count, within) in
            [(1u32, 16_384u64, true), (2, 32_769, false)]
        {
            let mut main = 4u64.to_le_bytes().to_vec();
            main.extend(recoverable_count.to_le_bytes());
            main.extend([b'A'; 16]);
            main.extend([b'B'; 16]);
            let mut input = packet(b"PAR 2.0\0Main", &main);
            for (id, length, name) in [(b'A', 65_536u64, b"a.rar"), (b'B', 65_540, b"b.rar")] {
                let mut desc = vec![id; 16];
                desc.extend([b'M'; 16]);
                desc.extend([b'N'; 16]);
                desc.extend(length.to_le_bytes());
                desc.extend(name);
                input.extend(packet(b"PAR 2.0\0FileDesc", &desc));
            }
            input.extend(packet(b"PAR 2.0\0RecvSlic", &[0; 8]));
            let metadata = parse(&input);
            assert_eq!(metadata.source_block_count, Some(expected_count));
            assert_eq!(metadata.recovery_to_source_percent.is_some(), within);
        }
    }

    #[test]
    fn missing_and_zero_source_denominators_never_report_a_success_basis() {
        let input = single_file_set([b'F'; 16], 4, 4, b"payload.rar", Some(0));
        let desc_offset = packet_offset_of(&input, b"PAR 2.0\0FileDesc");
        let desc_end = desc_offset + read_u64(&input, desc_offset + 8).unwrap() as usize;
        let missing_description = [&input[..desc_offset], &input[desc_end..]].concat();
        for sample in [
            missing_description,
            single_file_set([b'F'; 16], 4, 0, b"empty.rar", Some(0)),
        ] {
            let metadata = parse(&sample);
            assert_eq!(metadata.source_block_size, Some(4));
            assert_eq!(metadata.source_block_count, None);
            assert_eq!(metadata.recovery_to_source_percent, None);
            assert_eq!(
                metadata.recovery_percentage_basis,
                "unavailable-source-block-denominator"
            );
        }
    }

    #[test]
    fn invalid_packets_and_scan_limits_never_report_a_success_basis() {
        let valid = single_file_set([b'F'; 16], 4, 4, b"payload.rar", Some(0));
        let mut broken = packet(b"PAR 2.0\0Creator", b"test");
        broken[16] ^= 1;
        let invalid = [&valid[..], &broken].concat();
        let limited = [
            valid,
            packet(b"PAR 2.0\0Creator", b"test").repeat(MAX_PACKETS),
        ]
        .concat();
        for sample in [invalid, limited] {
            let metadata = parse(&sample);
            assert_eq!(metadata.source_block_count, Some(1));
            assert!(metadata.source_inventory_complete);
            assert_eq!(metadata.recovery_to_source_percent, None);
            assert_eq!(
                metadata.recovery_percentage_basis,
                "unavailable-invalid-or-incomplete-packets"
            );
        }
    }

    #[test]
    fn does_not_treat_nonzero_packet_type_suffix_as_a_known_main_packet() {
        let file_id = [b'F'; 16];
        let mut main = Vec::new();
        main.extend_from_slice(&4096u64.to_le_bytes());
        main.extend_from_slice(&1u32.to_le_bytes());
        main.extend_from_slice(&file_id);
        let input = packet(b"PAR 2.0\0Main\0X", &main);

        let metadata = parse(&input);

        assert_eq!(metadata.source_block_size, None);
        assert_eq!(
            metadata
                .packet_type_counts
                .get(&format!("unknown:{}", hex(&input[48..64]))),
            Some(&1)
        );
    }

    #[test]
    fn relates_ifsc_to_file_length_within_its_recovery_set() {
        let file_id = [b'F'; 16];
        let input = single_file_set(file_id, 4096, 5000, b"file.rar", None);
        let metadata = parse(&input);

        assert_eq!(metadata.input_file_slice_checksums.len(), 1);
        let checksums = &metadata.input_file_slice_checksums[0];
        assert_eq!(checksums.slice_count, 2);
        assert_eq!(checksums.expected_slice_count_from_file_length, Some(2));
        assert_eq!(checksums.slice_count_matches_file_length, Some(true));
        assert_eq!(metadata.source_files[0].filename, "file.rar");
        assert!(metadata.source_inventory_complete);
    }

    #[test]
    fn rejects_a_small_file_description_with_inconsistent_md5_fields() {
        let set_id = [b'S'; 16];
        let file_id = [b'F'; 16];
        let mut input = single_file_set(file_id, 4096, 5000, b"file.rar", Some(0));
        let description_offset = find_magic(&input, 0).unwrap();
        let description_offset = find_magic(&input, description_offset + 64).unwrap();
        input[description_offset + 96..description_offset + 112].copy_from_slice(&[b'N'; 16]);
        set_packet_ids(&mut input, set_id, file_id);

        let metadata = parse(&input);

        assert!(!metadata.source_inventory_complete);
        assert_eq!(metadata.invalid_file_description_ids, vec![hex(&file_id)]);
        assert_eq!(metadata.recovery_to_source_percent, None);
    }

    #[test]
    fn decodes_and_relates_unicode_filename_packets() {
        let file_id = [b'F'; 16];
        let mut input = single_file_set(file_id, 4096, 5000, b"fallback.txt", None);
        let name = "資料/naïve-🛰.mkv";
        let mut utf16le = name
            .encode_utf16()
            .flat_map(u16::to_le_bytes)
            .collect::<Vec<_>>();
        if utf16le.len() % 4 != 0 {
            utf16le.extend_from_slice(&[0, 0]);
        }
        let mut payload = file_id.to_vec();
        payload.extend_from_slice(&utf16le);
        input.extend(packet(b"PAR 2.0\0UniFileN", &payload));

        let metadata = parse(&input);

        assert_eq!(metadata.unicode_filenames.len(), 1);
        let filename = &metadata.unicode_filenames[0];
        assert_eq!(filename.file_id, hex(&file_id));
        assert_eq!(filename.filename.as_deref(), Some(name));
        assert!(filename.utf16_valid);
        assert_eq!(filename.padding_byte_length, 2);
        assert_eq!(filename.source_file_present, Some(true));
        assert_eq!(filename.packet_count, 1);
        assert!(!filename.duplicate_conflict);
    }

    #[test]
    fn marks_conflicting_unicode_filename_duplicates_and_unmatched_file_ids() {
        let file_id = [b'F'; 16];
        let unmatched_id = [b'X'; 16];
        let mut input = single_file_set(file_id, 4096, 5000, b"fallback.txt", None);
        let unicode_packet = |file_id: [u8; 16], name: &str| {
            let mut name_bytes = name
                .encode_utf16()
                .flat_map(u16::to_le_bytes)
                .collect::<Vec<_>>();
            if name_bytes.len() % 4 != 0 {
                name_bytes.extend_from_slice(&[0, 0]);
            }
            let mut payload = file_id.to_vec();
            payload.extend_from_slice(&name_bytes);
            packet(b"PAR 2.0\0UniFileN", &payload)
        };
        input.extend(unicode_packet(file_id, "first.mkv"));
        input.extend(unicode_packet(file_id, "second.mkv"));
        input.extend(unicode_packet(unmatched_id, "orphan.mkv"));

        let metadata = parse(&input);

        assert_eq!(metadata.unicode_filenames.len(), 2);
        assert_eq!(metadata.unicode_filenames[0].packet_count, 2);
        assert!(metadata.unicode_filenames[0].duplicate_conflict);
        assert_eq!(
            metadata.unicode_filenames[0].filename.as_deref(),
            Some("first.mkv")
        );
        assert_eq!(
            metadata.unicode_filenames[1].source_file_present,
            Some(false)
        );
    }

    #[test]
    fn packet_summaries_report_physical_ifsc_and_recovery_regions() {
        let file_id = [b'F'; 16];
        let input = single_file_set(file_id, 4096, 5000, b"file.rar", Some(3));
        let metadata = parse(&input);
        let json = serde_json::to_value(metadata).unwrap();
        let packets = json["packet_summaries"].as_array().unwrap();
        let ifsc = packets
            .iter()
            .find(|packet| packet["packet_type"] == "PAR 2.0\\0IFSC")
            .unwrap();
        let recovery = packets
            .iter()
            .find(|packet| packet["packet_type"] == "PAR 2.0\\0RecvSlic")
            .unwrap();
        let ifsc_offset = ifsc["offset"].as_u64().unwrap();
        let recovery_offset = recovery["offset"].as_u64().unwrap();

        assert_eq!(
            ifsc["physical_regions"][0],
            serde_json::json!({"kind":"packet_header", "offset":ifsc_offset, "byte_length":64})
        );
        assert_eq!(
            ifsc["physical_regions"][2],
            serde_json::json!({"kind":"ifsc_file_id", "offset":ifsc_offset + 64, "byte_length":16})
        );
        assert_eq!(
            ifsc["physical_regions"][3],
            serde_json::json!({"kind":"ifsc_records", "offset":ifsc_offset + 80, "byte_length":40, "record_count":2, "record_byte_length":20})
        );
        assert_eq!(
            recovery["physical_regions"][2],
            serde_json::json!({"kind":"recovery_exponent", "offset":recovery_offset + 64, "byte_length":4})
        );
        assert_eq!(
            recovery["physical_regions"][3],
            serde_json::json!({"kind":"recovery_data", "offset":recovery_offset + 68, "byte_length":4096})
        );
    }

    #[test]
    fn reports_fileslic_physical_regions_without_interpreting_alignment_padding() {
        let mut payload = vec![b'F'; 16];
        payload.extend_from_slice(&1u64.to_le_bytes());
        payload.extend(std::iter::repeat_n(b'S', 905));
        payload.extend_from_slice(&[0; 3]);
        let metadata = parse(&packet(b"PAR 2.0\0FileSlic", &payload));
        let json = serde_json::to_value(metadata).unwrap();
        let packet_summary = &json["packet_summaries"][0];
        let packet_offset = packet_summary["offset"].as_u64().unwrap();
        let regions = packet_summary["physical_regions"].as_array().unwrap();
        let region = |kind| {
            regions
                .iter()
                .find(|region| region["kind"] == kind)
                .unwrap()
        };

        assert_eq!(
            region("fileslic_file_id"),
            &serde_json::json!({"kind":"fileslic_file_id", "offset":packet_offset + 64, "byte_length":16})
        );
        assert_eq!(
            region("fileslic_input_slice_index"),
            &serde_json::json!({"kind":"fileslic_input_slice_index", "offset":packet_offset + 80, "byte_length":8})
        );
        assert_eq!(
            region("fileslic_physical_slice_field"),
            &serde_json::json!({"kind":"fileslic_physical_slice_field", "offset":packet_offset + 88, "byte_length":908})
        );
        assert_eq!(json["source_block_count"], serde_json::Value::Null);
    }

    #[test]
    fn relates_fileslic_to_source_byte_range_and_ifsc_record() {
        let file_id = [b'F'; 16];
        let mut input = single_file_set(file_id, 4096, 5001, b"file.rar", None);
        let mut fileslic = file_id.to_vec();
        fileslic.extend_from_slice(&1u64.to_le_bytes());
        fileslic.extend(std::iter::repeat_n(b'S', 905));
        fileslic.extend_from_slice(&[0; 3]);
        let fileslic_packet = packet(b"PAR 2.0\0FileSlic", &fileslic);
        let fileslic_offset = input.len();
        input.extend(fileslic_packet);

        let metadata = parse(&input);
        let json = serde_json::to_value(metadata).unwrap();
        let fileslic =
            json["optional_packet_summaries"]["PAR 2.0\\0FileSlic"][0]["fileslic_relationship"]
                .clone();
        let ifsc_packet_offset = json["packet_summaries"]
            .as_array()
            .unwrap()
            .iter()
            .find(|packet| packet["packet_type"] == "PAR 2.0\\0IFSC")
            .unwrap()["offset"]
            .as_u64()
            .unwrap();

        assert_eq!(fileslic["layout_status"], "resolved");
        assert_eq!(fileslic["logical_file_offset"], 4096);
        assert_eq!(fileslic["logical_byte_length"], 905);
        assert_eq!(fileslic["physical_data_offset"], fileslic_offset + 88);
        assert_eq!(fileslic["physical_slice_field_bytes"], 908);
        assert_eq!(fileslic["expected_alignment_bytes"], 3);
        assert_eq!(fileslic["alignment_padding_zero_valid"], true);
        assert_eq!(fileslic["ifsc_relationship_status"], "record-linked");
        assert_eq!(fileslic["ifsc_record_offset"], ifsc_packet_offset + 100);
        assert_eq!(fileslic["ifsc_record_index"], 1);
        assert_eq!(fileslic["ifsc_md5"], hex(&[b'C'; 16]));
        assert_eq!(fileslic["ifsc_crc32"], "43434343");
    }

    #[test]
    fn verifies_fileslic_bytes_against_full_and_zero_padded_final_ifsc_slices() {
        let (input, _) = fileslic_checksum_fixture();
        let metadata = parse(&input);
        let json = serde_json::to_value(metadata).unwrap();
        let slices = json["optional_packet_summaries"]["PAR 2.0\\0FileSlic"]
            .as_array()
            .unwrap();

        assert_eq!(
            slices[0]["fileslic_relationship"]["layout_status"],
            "resolved"
        );
        assert_eq!(
            slices[0]["fileslic_relationship"]["source_slice_checksum_status"],
            "match"
        );
        assert_eq!(
            slices[0]["fileslic_relationship"]["source_slice_md5_matches"],
            true
        );
        assert_eq!(
            slices[0]["fileslic_relationship"]["source_slice_crc32_matches"],
            true
        );
        assert_eq!(
            slices[0]["fileslic_relationship"]["source_slice_md5_calculated"],
            "1d64dce239c4437b7736041db089e1b9"
        );
        assert_eq!(
            slices[0]["fileslic_relationship"]["source_slice_crc32_calculated"],
            "943AC093"
        );
        assert_eq!(
            slices[1]["fileslic_relationship"]["source_slice_checksum_status"],
            "match"
        );
        assert_eq!(
            slices[1]["fileslic_relationship"]["source_slice_md5_matches"],
            true
        );
        assert_eq!(
            slices[1]["fileslic_relationship"]["source_slice_crc32_matches"],
            true
        );
        assert_eq!(
            slices[1]["fileslic_relationship"]["source_slice_checksum_bytes"],
            16
        );
        assert_eq!(
            slices[1]["fileslic_relationship"]["source_slice_md5_calculated"],
            "019f1e7d7bdd0be06cbb613e55e78268"
        );
        assert_eq!(
            slices[1]["fileslic_relationship"]["source_slice_crc32_calculated"],
            "63C57488"
        );
    }

    #[test]
    fn detects_fileslic_content_mismatch_after_packet_checksum_is_recomputed() {
        let (mut input, fileslic_offsets) = fileslic_checksum_fixture();
        let packet_offset = fileslic_offsets[0] - 88;
        input[fileslic_offsets[0]] ^= 1;
        rehash_packet(&mut input, packet_offset);

        let metadata = parse(&input);
        let json = serde_json::to_value(metadata).unwrap();
        let relationship =
            &json["optional_packet_summaries"]["PAR 2.0\\0FileSlic"][0]["fileslic_relationship"];

        assert_eq!(relationship["source_slice_checksum_status"], "mismatch");
        assert_eq!(relationship["source_slice_md5_matches"], false);
        assert_eq!(relationship["source_slice_crc32_matches"], false);
    }

    #[test]
    fn reports_md5_and_crc32_mismatches_independently() {
        let (mut input, _) = fileslic_checksum_fixture();
        let ifsc_offset = packet_offset_of(&input, b"PAR 2.0\0IFSC");
        input[ifsc_offset + 80] ^= 1;
        rehash_packet(&mut input, ifsc_offset);

        let metadata = parse(&input);
        let json = serde_json::to_value(metadata).unwrap();
        let relationship =
            &json["optional_packet_summaries"]["PAR 2.0\\0FileSlic"][0]["fileslic_relationship"];

        assert_eq!(relationship["source_slice_checksum_status"], "mismatch");
        assert_eq!(relationship["source_slice_md5_matches"], false);
        assert_eq!(relationship["source_slice_crc32_matches"], true);

        let (mut crc_mismatch_input, _) = fileslic_checksum_fixture();
        let ifsc_offset = packet_offset_of(&crc_mismatch_input, b"PAR 2.0\0IFSC");
        crc_mismatch_input[ifsc_offset + 96] ^= 1;
        rehash_packet(&mut crc_mismatch_input, ifsc_offset);
        let metadata = parse(&crc_mismatch_input);
        let json = serde_json::to_value(metadata).unwrap();
        let relationship =
            &json["optional_packet_summaries"]["PAR 2.0\\0FileSlic"][0]["fileslic_relationship"];

        assert_eq!(relationship["source_slice_checksum_status"], "mismatch");
        assert_eq!(relationship["source_slice_md5_matches"], true);
        assert_eq!(relationship["source_slice_crc32_matches"], false);
    }

    #[test]
    fn withholds_fileslic_checksum_claims_for_invalid_padding_or_conflicting_duplicates() {
        let (mut invalid_padding_input, fileslic_offsets) = fileslic_checksum_fixture();
        let final_packet_offset = fileslic_offsets[1] - 88;
        invalid_padding_input[fileslic_offsets[1] + 5] = 1;
        rehash_packet(&mut invalid_padding_input, final_packet_offset);
        let metadata = parse(&invalid_padding_input);
        let json = serde_json::to_value(metadata).unwrap();
        let relationship =
            &json["optional_packet_summaries"]["PAR 2.0\\0FileSlic"][1]["fileslic_relationship"];
        assert_eq!(relationship["layout_status"], "nonzero-alignment-padding");
        assert_eq!(relationship["ifsc_relationship_status"], "record-linked");
        assert_eq!(
            relationship["source_slice_checksum_status"],
            "layout-unresolved"
        );
        assert_eq!(
            relationship["source_slice_md5_matches"],
            serde_json::Value::Null
        );

        let (mut extra_data_input, fileslic_offsets) = fileslic_checksum_fixture();
        let packet_offset = fileslic_offsets[0] - 88;
        let original_packet_length =
            usize::try_from(read_u64(&extra_data_input, packet_offset + 8).unwrap()).unwrap();
        let packet_end = packet_offset + original_packet_length;
        extra_data_input.splice(packet_end..packet_end, [0; 4]);
        let extended_packet_length = original_packet_length + 4;
        extra_data_input[packet_offset + 8..packet_offset + 16]
            .copy_from_slice(&(extended_packet_length as u64).to_le_bytes());
        rehash_packet(&mut extra_data_input, packet_offset);
        let metadata = parse(&extra_data_input);
        let json = serde_json::to_value(metadata).unwrap();
        let relationship =
            &json["optional_packet_summaries"]["PAR 2.0\\0FileSlic"][0]["fileslic_relationship"];
        assert_eq!(relationship["layout_status"], "physical-length-mismatch");
        assert_eq!(
            relationship["source_slice_checksum_status"],
            "layout-unresolved"
        );
        assert_eq!(
            relationship["source_slice_md5_matches"],
            serde_json::Value::Null
        );

        let (mut duplicate_input, _) = fileslic_checksum_fixture();
        let file_id = [b'F'; 16];
        let mut conflicting_slice = file_id.to_vec();
        conflicting_slice.extend_from_slice(&0u64.to_le_bytes());
        conflicting_slice.extend_from_slice(b"XXXXXXXXXXXXXXXX");
        duplicate_input.extend(packet(b"PAR 2.0\0FileSlic", &conflicting_slice));
        let metadata = parse(&duplicate_input);
        let json = serde_json::to_value(metadata).unwrap();
        let relationships = json["optional_packet_summaries"]["PAR 2.0\\0FileSlic"]
            .as_array()
            .unwrap();
        let conflicted_slice_zero = relationships
            .iter()
            .filter(|packet| packet["fileslic_relationship"]["ifsc_record_index"] == 0)
            .collect::<Vec<_>>();
        assert_eq!(conflicted_slice_zero.len(), 2);
        assert!(conflicted_slice_zero.iter().all(|packet| {
            packet["fileslic_relationship"]["duplicate_status"] == "conflicting-duplicate"
                && packet["fileslic_relationship"]["source_slice_checksum_status"]
                    == "conflicting-fileslic"
                && packet["fileslic_relationship"]["source_slice_md5_matches"].is_null()
        }));
        assert_eq!(
            relationships[1]["fileslic_relationship"]["source_slice_checksum_status"],
            "match"
        );
    }

    #[test]
    fn withholds_fileslic_verification_for_conflicting_ifsc_records() {
        let (mut input, _) = fileslic_checksum_fixture();
        let file_id = [b'F'; 16];
        let mut conflicting_ifsc = file_id.to_vec();
        conflicting_ifsc.extend([b'X'; 40]);
        input.extend(packet(b"PAR 2.0\0IFSC", &conflicting_ifsc));

        let metadata = parse(&input);
        let json = serde_json::to_value(metadata).unwrap();
        let relationship =
            &json["optional_packet_summaries"]["PAR 2.0\\0FileSlic"][0]["fileslic_relationship"];

        assert_eq!(relationship["ifsc_relationship_status"], "conflicting-ifsc");
        assert_eq!(
            relationship["source_slice_checksum_status"],
            "conflicting-ifsc"
        );
        assert_eq!(
            relationship["source_slice_md5_matches"],
            serde_json::Value::Null
        );
    }

    #[test]
    fn bounds_fileslic_hashing_for_huge_virtual_padding() {
        let file_id = [b'F'; 16];
        let block_size = u64::MAX - 3;
        let mut input = single_file_set(file_id, block_size, 1, b"tiny.rar", None);
        let mut fileslic = file_id.to_vec();
        fileslic.extend_from_slice(&0u64.to_le_bytes());
        fileslic.extend_from_slice(&[b'x', 0, 0, 0]);
        input.extend(packet(b"PAR 2.0\0FileSlic", &fileslic));

        let metadata = parse(&input);
        let json = serde_json::to_value(metadata).unwrap();
        let relationship =
            &json["optional_packet_summaries"]["PAR 2.0\\0FileSlic"][0]["fileslic_relationship"];

        assert_eq!(relationship["layout_status"], "resolved");
        assert_eq!(
            relationship["source_slice_checksum_status"],
            "work-budget-exceeded"
        );
        assert_eq!(
            relationship["source_slice_md5_matches"],
            serde_json::Value::Null
        );
        assert_eq!(
            relationship["source_slice_crc32_matches"],
            serde_json::Value::Null
        );
    }

    #[test]
    fn shares_fileslic_hash_budget_across_recovery_sets() {
        let block_size = 40 * 1024 * 1024;
        let first_file_id = [b'F'; 16];
        let second_file_id = [b'G'; 16];
        let mut first = single_file_set(first_file_id, block_size, 1, b"first.rar", None);
        let mut second = single_file_set(second_file_id, block_size, 1, b"second.rar", None);
        set_packet_ids(&mut first, [b'A'; 16], first_file_id);
        set_packet_ids(&mut second, [b'B'; 16], second_file_id);
        let mut first_slice = first_file_id.to_vec();
        first_slice.extend_from_slice(&0u64.to_le_bytes());
        first_slice.extend_from_slice(&[b'a', 0, 0, 0]);
        let mut second_slice = second_file_id.to_vec();
        second_slice.extend_from_slice(&0u64.to_le_bytes());
        second_slice.extend_from_slice(&[b'b', 0, 0, 0]);
        let mut first_slice_packet = packet(b"PAR 2.0\0FileSlic", &first_slice);
        first_slice_packet[32..48].copy_from_slice(&[b'A'; 16]);
        rehash_packet(&mut first_slice_packet, 0);
        let mut second_slice_packet = packet(b"PAR 2.0\0FileSlic", &second_slice);
        second_slice_packet[32..48].copy_from_slice(&[b'B'; 16]);
        rehash_packet(&mut second_slice_packet, 0);
        let input = [first, first_slice_packet, second, second_slice_packet].concat();

        let metadata = parse(&input);
        let json = serde_json::to_value(metadata).unwrap();
        let relationships = json["optional_packet_summaries"]["PAR 2.0\\0FileSlic"]
            .as_array()
            .unwrap();

        assert_eq!(
            relationships[0]["fileslic_relationship"]["layout_status"],
            "resolved"
        );
        assert_eq!(
            relationships[0]["fileslic_relationship"]["source_slice_checksum_bytes"],
            block_size
        );
        assert_eq!(
            relationships[1]["fileslic_relationship"]["source_slice_checksum_status"],
            "work-budget-exceeded"
        );
    }

    #[test]
    fn marks_conflicting_fileslic_duplicates_for_the_same_set_file_and_index() {
        let file_id = [b'F'; 16];
        let mut input = single_file_set(file_id, 4096, 5001, b"file.rar", None);
        let fileslic_packet = |fill: u8| {
            let mut fileslic = file_id.to_vec();
            fileslic.extend_from_slice(&1u64.to_le_bytes());
            fileslic.extend(std::iter::repeat_n(fill, 905));
            fileslic.extend_from_slice(&[0; 3]);
            packet(b"PAR 2.0\0FileSlic", &fileslic)
        };
        input.extend(fileslic_packet(b'A'));
        input.extend(fileslic_packet(b'B'));

        let metadata = parse(&input);
        let json = serde_json::to_value(metadata).unwrap();
        let fileslic_packets = json["optional_packet_summaries"]["PAR 2.0\\0FileSlic"]
            .as_array()
            .unwrap();

        assert_eq!(fileslic_packets.len(), 2);
        assert!(fileslic_packets.iter().all(|packet| {
            packet["fileslic_relationship"]["duplicate_status"] == "conflicting-duplicate"
        }));
    }

    #[test]
    fn reports_truncated_fileslic_identity_fields_as_partial_regions() {
        let mut payload = vec![b'F'; 16];
        payload.extend_from_slice(&[1, 0, 0, 0]);
        let metadata = parse(&packet(b"PAR 2.0\0FileSlic", &payload));
        let json = serde_json::to_value(metadata).unwrap();
        let regions = json["packet_summaries"][0]["physical_regions"]
            .as_array()
            .unwrap();

        assert!(
            regions.iter().any(|region| {
                region["kind"] == "fileslic_file_id" && region["byte_length"] == 16
            })
        );
        assert!(regions.iter().any(|region| {
            region["kind"] == "fileslic_truncated_input_slice_index" && region["byte_length"] == 4
        }));
        assert!(
            !regions
                .iter()
                .any(|region| region["kind"] == "fileslic_input_slice_index")
        );
        assert!(
            !regions
                .iter()
                .any(|region| region["kind"] == "fileslic_physical_slice_field")
        );
    }

    #[test]
    fn malformed_ifsc_trailing_bytes_do_not_overlap_complete_records() {
        let mut payload = vec![b'F'; 16];
        payload.extend(std::iter::repeat_n(b'C', 21));
        let input = packet(b"PAR 2.0\0IFSC", &payload);
        let metadata = parse(&input);
        let json = serde_json::to_value(metadata).unwrap();
        let ifsc = &json["packet_summaries"][0];
        let regions = ifsc["physical_regions"].as_array().unwrap();
        let records = regions
            .iter()
            .find(|region| region["kind"] == "ifsc_records")
            .unwrap();
        let trailing = regions
            .iter()
            .find(|region| region["kind"] == "ifsc_trailing_bytes")
            .unwrap();

        assert_eq!(records["byte_length"], 20);
        assert_eq!(trailing["offset"], records["offset"].as_u64().unwrap() + 20);
        assert_eq!(trailing["byte_length"], 4);
    }

    #[test]
    fn conflicting_recovery_packets_invalidate_the_percentage_evidence() {
        let file_id = [b'F'; 16];
        let mut input = single_file_set(file_id, 4096, 5000, b"file.rar", Some(3));
        let mut identical_recovery = 3u32.to_le_bytes().to_vec();
        identical_recovery.extend(std::iter::repeat_n(b'R', 4096));
        input.extend(packet(b"PAR 2.0\0RecvSlic", &identical_recovery));
        let mut conflicting_recovery = 3u32.to_le_bytes().to_vec();
        conflicting_recovery.extend(std::iter::repeat_n(b'X', 4096));
        input.extend(packet(b"PAR 2.0\0RecvSlic", &conflicting_recovery));

        let metadata = parse(&input);

        assert_eq!(metadata.invalid_packet_count, 1);
        assert_eq!(metadata.unique_recovery_slice_count, Some(1));
        assert_eq!(metadata.recovery_to_source_percent, None);
        assert_eq!(
            metadata.recovery_percentage_basis,
            "unavailable-conflicting-recovery-exponent-packets"
        );
        assert_eq!(metadata.conflicting_recovery_exponents.len(), 1);
        assert_eq!(metadata.conflicting_recovery_exponents[0].exponent, 3);
        assert_eq!(
            metadata.conflicting_recovery_exponents[0].first_packet_offset,
            metadata.packet_summaries[3].offset
        );
        assert_eq!(
            metadata.conflicting_recovery_exponents[0].conflicting_packet_offset,
            metadata.packet_summaries[5].offset
        );
    }

    #[test]
    fn conflicting_source_definitions_are_scoped_to_recovery_set() {
        let first_set_id = [b'S'; 16];
        let second_set_id = [b'T'; 16];
        let first_file_id = [b'F'; 16];
        let second_file_id = [b'G'; 16];
        let mut first = single_file_set(first_file_id, 4096, 5000, b"one.rar", Some(0));
        let mut second = single_file_set(second_file_id, 8192, 9000, b"two.rar", Some(1));
        set_packet_ids(&mut first, first_set_id, first_file_id);
        set_packet_ids(&mut second, second_set_id, second_file_id);

        let mut conflicting_main = Vec::new();
        conflicting_main.extend_from_slice(&8192_u64.to_le_bytes());
        conflicting_main.extend_from_slice(&1_u32.to_le_bytes());
        conflicting_main.extend_from_slice(&first_file_id);
        first.extend(packet(b"PAR 2.0\0Main", &conflicting_main));

        let mut conflicting_description = first_file_id.to_vec();
        conflicting_description.extend_from_slice(&[b'M'; 16]);
        conflicting_description.extend_from_slice(&[b'N'; 16]);
        conflicting_description.extend_from_slice(&5001_u64.to_le_bytes());
        conflicting_description.extend_from_slice(b"one.rar\0");
        first.extend(packet(b"PAR 2.0\0FileDesc", &conflicting_description));

        let metadata = parse(&[first, second].concat());
        let json = serde_json::to_value(metadata).unwrap();
        let sets = json["recovery_sets"].as_array().unwrap();
        let first_set = sets
            .iter()
            .find(|set| set["recovery_set_id"] == hex(&first_set_id))
            .unwrap();
        let second_set = sets
            .iter()
            .find(|set| set["recovery_set_id"] == hex(&second_set_id))
            .unwrap();

        assert_eq!(first_set["main_definition_conflict"], true);
        assert_eq!(
            first_set["conflicting_file_description_ids"],
            serde_json::json!([hex(&first_file_id)])
        );
        assert_eq!(first_set["source_inventory_complete"], false);
        assert_eq!(first_set["source_descriptions_valid"], false);
        assert_eq!(second_set["source_inventory_complete"], true);
        assert_eq!(json["recovery_to_source_percent"], serde_json::Value::Null);
        assert_eq!(
            json["recovery_percentage_basis"],
            "unavailable-conflicting-source-definition-packets"
        );
    }

    #[test]
    fn accepts_recovery_only_volume_packets_pending_set_level_validation() {
        let mut recovery = 7u32.to_le_bytes().to_vec();
        recovery.extend(std::iter::repeat_n(b'R', 4096));
        let input = [
            packet(b"PAR 2.0\0Creator", b"par2j v1.3.3\0"),
            packet(b"PAR 2.0\0RecvSlic", &recovery),
        ]
        .concat();

        let metadata = parse(&input);

        assert_eq!(metadata.invalid_packet_count, 0);
        assert_eq!(metadata.recovery_exponents, Some(vec![7]));
        assert_eq!(metadata.recovery_to_source_percent, None);
        assert_eq!(
            metadata.recovery_percentage_basis,
            "unavailable-source-block-size-not-in-this-volume"
        );
    }

    #[test]
    fn rejects_ifsc_file_ids_missing_from_known_main_even_when_descriptions_are_incomplete() {
        let described_file_id = [b'F'; 16];
        let main_listed_file_id = [b'G'; 16];
        let orphan_file_id = [b'X'; 16];
        let mut main = Vec::new();
        main.extend_from_slice(&4096u64.to_le_bytes());
        main.extend_from_slice(&1u32.to_le_bytes());
        main.extend_from_slice(&described_file_id);
        main.extend_from_slice(&main_listed_file_id);
        let mut description = described_file_id.to_vec();
        description.extend_from_slice(&[b'M'; 16]);
        description.extend_from_slice(&[b'M'; 16]);
        description.extend_from_slice(&5000u64.to_le_bytes());
        description.extend_from_slice(b"file.rar\0");
        let mut ifsc = orphan_file_id.to_vec();
        ifsc.extend(std::iter::repeat_n(b'C', 40));
        let input = [
            packet(b"PAR 2.0\0Main", &main),
            packet(b"PAR 2.0\0FileDesc", &description),
            packet(b"PAR 2.0\0IFSC", &ifsc),
        ]
        .concat();

        let metadata = parse(&input);

        assert!(!metadata.source_inventory_complete);
        assert_eq!(
            metadata.recovery_sets[0].missing_file_description_ids,
            vec![hex(&main_listed_file_id)]
        );
        assert!(!metadata.ifsc_relationships_valid);
        assert_eq!(metadata.recovery_to_source_percent, None);
    }

    #[test]
    fn keeps_ifsc_for_main_listed_file_pending_when_its_description_is_in_another_volume() {
        let described_file_id = [b'F'; 16];
        let pending_file_id = [b'G'; 16];
        let mut main = Vec::new();
        main.extend_from_slice(&4096u64.to_le_bytes());
        main.extend_from_slice(&1u32.to_le_bytes());
        main.extend_from_slice(&described_file_id);
        main.extend_from_slice(&pending_file_id);
        let mut description = described_file_id.to_vec();
        description.extend_from_slice(&[b'M'; 16]);
        description.extend_from_slice(&[b'M'; 16]);
        description.extend_from_slice(&5000u64.to_le_bytes());
        description.extend_from_slice(b"file.rar\0");
        let mut ifsc = pending_file_id.to_vec();
        ifsc.extend(std::iter::repeat_n(b'C', 40));
        let input = [
            packet(b"PAR 2.0\0Main", &main),
            packet(b"PAR 2.0\0FileDesc", &description),
            packet(b"PAR 2.0\0IFSC", &ifsc),
        ]
        .concat();

        let metadata = parse(&input);

        assert!(!metadata.source_inventory_complete);
        assert!(metadata.ifsc_relationships_valid);
        assert_eq!(
            metadata.input_file_slice_checksums[0].slice_count_matches_file_length,
            None
        );
        assert_eq!(metadata.recovery_to_source_percent, None);
    }

    #[test]
    fn excludes_wrong_sized_recovery_packets_from_cross_volume_evidence() {
        let file_id = [b'F'; 16];
        let mut input = single_file_set(file_id, 4096, 5000, b"file.rar", None);
        input.extend(packet(b"PAR 2.0\0RecvSlic", &7u32.to_le_bytes()));

        let metadata = parse(&input);

        assert_eq!(metadata.invalid_packet_count, 1);
        assert!(metadata.recovery_packets.is_empty());
    }

    #[test]
    fn rejects_recovery_packets_when_main_block_size_plus_header_overflows() {
        let file_id = [b'F'; 16];
        let mut main = Vec::new();
        main.extend_from_slice(&(u64::MAX - 3).to_le_bytes());
        main.extend_from_slice(&1u32.to_le_bytes());
        main.extend_from_slice(&file_id);
        let mut description = file_id.to_vec();
        description.extend_from_slice(&[b'M'; 16]);
        description.extend_from_slice(&[b'M'; 16]);
        description.extend_from_slice(&1u64.to_le_bytes());
        description.extend_from_slice(b"file.rar");
        let mut recovery = 7u32.to_le_bytes().to_vec();
        recovery.push(b'R');
        let input = [
            packet(b"PAR 2.0\0Main", &main),
            packet(b"PAR 2.0\0FileDesc", &description),
            packet(b"PAR 2.0\0RecvSlic", &recovery),
        ]
        .concat();

        let metadata = parse(&input);

        assert_eq!(metadata.invalid_packet_count, 1);
        assert!(metadata.recovery_packets.is_empty());
        assert_eq!(metadata.recovery_to_source_percent, None);
    }

    fn single_file_set(
        file_id: [u8; 16],
        block_size: u64,
        file_length: u64,
        filename: &[u8],
        recovery_exponent: Option<u32>,
    ) -> Vec<u8> {
        let mut main = Vec::new();
        main.extend_from_slice(&block_size.to_le_bytes());
        main.extend_from_slice(&1u32.to_le_bytes());
        main.extend_from_slice(&file_id);
        let mut description = Vec::new();
        description.extend_from_slice(&file_id);
        description.extend_from_slice(&[b'M'; 16]);
        // For files at most 16 KiB, PAR2's whole-file and first-16-KiB MD5s are identical.
        let first_16k_md5 = if file_length <= 16_384 {
            [b'M'; 16]
        } else {
            [b'N'; 16]
        };
        description.extend_from_slice(&first_16k_md5);
        description.extend_from_slice(&file_length.to_le_bytes());
        description.extend_from_slice(filename);
        let mut ifsc = file_id.to_vec();
        let slice_count = file_length.div_ceil(block_size) as usize;
        ifsc.extend(std::iter::repeat_n(b'C', slice_count * 20));
        let mut packets = vec![
            packet(b"PAR 2.0\0Main", &main),
            packet(b"PAR 2.0\0FileDesc", &description),
            packet(b"PAR 2.0\0IFSC", &ifsc),
        ];
        if let Some(exponent) = recovery_exponent {
            let mut recovery = Vec::with_capacity(block_size as usize + 4);
            recovery.extend_from_slice(&exponent.to_le_bytes());
            recovery.extend(std::iter::repeat_n(b'R', block_size as usize));
            packets.push(packet(b"PAR 2.0\0RecvSlic", &recovery));
        }

        packets.concat()
    }

    #[test]
    fn keeps_recovery_sets_separate_and_disables_mixed_set_percentage() {
        let first_set_id = [b'S'; 16];
        let second_set_id = [b'T'; 16];
        let first_file_id = [b'F'; 16];
        let second_file_id = [b'G'; 16];
        let mut first = single_file_set(first_file_id, 4096, 5000, b"one.rar", Some(0));
        let mut second = single_file_set(second_file_id, 8192, 9000, b"two.rar", Some(1));
        set_packet_ids(&mut first, first_set_id, first_file_id);
        set_packet_ids(&mut second, second_set_id, second_file_id);

        let metadata = parse(&[first, second].concat());

        assert_eq!(metadata.recovery_sets.len(), 2);
        assert_eq!(metadata.recovery_sets[0].source_block_size, Some(4096));
        assert_eq!(metadata.recovery_sets[1].source_block_size, Some(8192));
        assert!(
            metadata
                .recovery_sets
                .iter()
                .all(|set| set.source_inventory_complete)
        );
        assert_eq!(metadata.recovery_sets[0].recovery_exponents, vec![0]);
        assert_eq!(metadata.recovery_sets[1].recovery_exponents, vec![1]);
        assert!(!metadata.source_inventory_complete);
        assert_eq!(metadata.source_block_count, None);
        assert_eq!(metadata.recovery_to_source_percent, None);
    }

    fn set_packet_ids(data: &mut [u8], set_id: [u8; 16], file_id: [u8; 16]) {
        let mut offset = 0;
        while let Some(relative) = find_magic(data, offset) {
            let packet_length = read_u64(data, relative + 8).unwrap() as usize;
            data[relative + 32..relative + 48].copy_from_slice(&set_id);
            if &data[relative + 48..relative + 64] == b"PAR 2.0\0FileDesc"
                || &data[relative + 48..relative + 64] == b"PAR 2.0\0IFSC\0\0\0\0"
            {
                data[relative + 64..relative + 80].copy_from_slice(&file_id);
            }
            let packet_md5 = md5(&data[relative + 32..relative + packet_length]);
            data[relative + 16..relative + 32].copy_from_slice(&packet_md5);
            offset = relative + packet_length;
        }
    }

    #[test]
    fn bounds_main_file_ids_before_expanding_an_oversized_inventory() {
        let mut main = 4096u64.to_le_bytes().to_vec();
        main.extend_from_slice(&0u32.to_le_bytes());
        for index in 0..=MAX_MAIN_FILE_IDS {
            let mut file_id = [0u8; 16];
            file_id[..4].copy_from_slice(&(index as u32).to_le_bytes());
            main.extend_from_slice(&file_id);
        }
        let metadata = parse(&packet(b"PAR 2.0\0Main", &main));

        assert!(metadata.main_file_id_limit_reached);
        assert_eq!(metadata.retained_main_file_id_count, 0);
        assert!(metadata.main_packet.is_none());
        assert!(metadata.missing_file_description_ids.is_empty());
        assert_eq!(metadata.invalid_packet_count, 1);
        assert_eq!(
            metadata.main_file_id_limit_observations[0].observed_file_id_count,
            MAX_MAIN_FILE_IDS + 1
        );
        assert_eq!(
            metadata.main_file_id_limit_observations[0].limit_kind,
            "packet-file-id-limit"
        );
        assert_eq!(metadata.packet_count, 1);
        assert!(metadata.packet_summaries[0].checksum_valid);
    }

    #[test]
    fn bounds_retained_main_inventory_across_sets_and_reuses_identical_packets() {
        let mut main = 4096u64.to_le_bytes().to_vec();
        main.extend_from_slice(&0u32.to_le_bytes());
        for index in 0..MAX_MAIN_FILE_IDS {
            let mut file_id = [0u8; 16];
            file_id[..4].copy_from_slice(&(index as u32).to_le_bytes());
            main.extend_from_slice(&file_id);
        }
        let first = packet(b"PAR 2.0\0Main", &main);
        let mut second_main = 4096u64.to_le_bytes().to_vec();
        second_main.extend_from_slice(&0u32.to_le_bytes());
        second_main.extend_from_slice(&[b'Z'; 16]);
        let mut second = packet(b"PAR 2.0\0Main", &second_main);
        set_packet_ids(&mut second, [b'T'; 16], [b'Z'; 16]);
        let metadata = parse(&[first.clone(), first, second].concat());

        assert!(metadata.main_file_id_limit_reached);
        assert_eq!(metadata.retained_main_file_id_count, MAX_MAIN_FILE_IDS);
        assert_eq!(metadata.main_packet_set_id_derivations.len(), 2);
        assert_eq!(metadata.invalid_packet_count, 1);
        assert_eq!(metadata.main_file_id_limit_observations.len(), 1);
        assert_eq!(
            metadata.main_file_id_limit_observations[0].observed_file_id_count,
            1
        );
        assert_eq!(
            metadata.main_file_id_limit_observations[0].limit_kind,
            "retained-file-id-budget"
        );
        assert_eq!(
            metadata
                .recovery_sets
                .iter()
                .filter(|set| set.main_packet.is_some())
                .count(),
            1
        );
        assert_eq!(metadata.packet_count, 3);
    }

    #[test]
    fn sha256_helper_matches_known_answer() {
        assert_eq!(
            sha256_hex(b"abc"),
            "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
        );
    }
}
