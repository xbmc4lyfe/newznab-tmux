use md5::Md5;
use serde::Serialize;
use sha2::{Digest, Sha256};

mod iso_path_tables;
mod iso_primary_copies;
mod rar5_copy_checksums;
mod rar5_quick_open;
mod xz_first_block;
mod zstd_frame;

const SAMPLE_PREFIX_IDENTITY_LIMIT_BYTES: usize = 16_384;

#[derive(Debug, Serialize)]
pub struct FormatMetadata {
    pub parser_engine: &'static str,
    pub candidate_scan_limit_per_format: usize,
    pub candidate_scan_truncated_formats: Vec<&'static str>,
    pub sample_prefix_identity: SamplePrefixIdentity,
    pub signatures: Vec<SignatureMatch>,
    pub headers: Vec<FormatHeader>,
    pub iso_descriptor_sequence: Option<IsoDescriptorSequence>,
    pub iso_primary_volume_descriptor_copies: Option<iso_primary_copies::PrimaryDescriptorCopies>,
    pub iso_path_table_copies: Option<iso_path_tables::PathTableCopies>,
}

#[derive(Debug, Serialize)]
pub struct SamplePrefixIdentity {
    pub status: &'static str,
    pub basis: &'static str,
    pub prefix_limit_bytes: usize,
    pub bytes_hashed: usize,
    pub md5: String,
    pub parser_input_offset_zero: bool,
    pub file_offset_known: bool,
}

#[derive(Debug, Serialize)]
pub struct IsoDescriptorSequence {
    pub status: &'static str,
    pub offset_basis: &'static str,
    pub image_origin_known: bool,
    pub descriptors: Vec<IsoDescriptorObservation>,
    pub first_primary_volume_descriptor_offset: Option<usize>,
    pub primary_volume_descriptor_count: usize,
    pub terminator_offset: Option<usize>,
    pub terminator_version_valid: Option<bool>,
    pub terminator_reserved_bytes_zero: Option<bool>,
    pub scan_limit_sectors: usize,
}

#[derive(Debug, Serialize)]
pub struct IsoDescriptorObservation {
    pub sector: usize,
    pub offset: usize,
    pub descriptor_type: u8,
    pub version: u8,
    pub standard_identifier_valid: bool,
    pub type_version_supported: bool,
    pub character_set_declaration: Option<IsoCharacterSetDeclaration>,
}

#[derive(Debug, Serialize)]
pub struct IsoCharacterSetDeclaration {
    pub status: &'static str,
    pub descriptor_kind: &'static str,
    pub basis: &'static str,
    pub volume_flags_observed: u8,
    pub unregistered_escape_sequence_declared: bool,
    pub reserved_volume_flag_bits_valid: bool,
    pub file_structure_version_observed: u8,
    pub file_structure_version_valid: bool,
    pub escape_sequences_raw_hex: String,
    pub escape_sequences_all_zero: bool,
    pub joliet_prefix_level_observed: Option<u8>,
    pub joliet_prefix_remaining_bytes_zero: bool,
    pub recognized_character_set: Option<&'static str>,
    pub directory_hierarchy_inspected: bool,
}

#[derive(Debug, Serialize)]
pub struct SignatureMatch {
    pub format: &'static str,
    pub offset: usize,
    pub basis: &'static str,
}

#[derive(Debug, Serialize)]
pub struct IsoPathTableRange {
    pub table_type: &'static str,
    pub optional: bool,
    pub location_logical_block: Option<u32>,
    pub byte_offset: Option<u64>,
    pub byte_end_exclusive: Option<u64>,
    pub within_declared_volume: Option<bool>,
    pub status: &'static str,
}

#[derive(Debug, Serialize)]
pub struct IsoIdentifierObservation {
    pub observed_byte_length: usize,
    pub complete: bool,
    pub raw_bytes_hex: String,
    pub raw_sha256: String,
    pub first_byte_hex: Option<String>,
    pub all_space_filler: bool,
    pub contains_nul: bool,
    pub a_character_bytes_valid: bool,
    pub reference_marker_at_first_byte: bool,
}

#[derive(Debug, Serialize)]
pub struct IsoRootDirectoryRecord {
    pub record_length: Option<u8>,
    pub record_length_valid: Option<bool>,
    pub extended_attribute_record_length_blocks: Option<u8>,
    pub extent_location_little_endian_observed: Option<u32>,
    pub extent_location_big_endian_observed: Option<u32>,
    pub dual_endian_extent_location_match: Option<bool>,
    pub effective_extent_location: Option<u32>,
    pub data_length_little_endian_observed: Option<u32>,
    pub data_length_big_endian_observed: Option<u32>,
    pub dual_endian_data_length_match: Option<bool>,
    pub effective_data_length: Option<u32>,
    pub volume_sequence_number_little_endian_observed: Option<u16>,
    pub volume_sequence_number_big_endian_observed: Option<u16>,
    pub dual_endian_volume_sequence_number_match: Option<bool>,
    pub effective_volume_sequence_number: Option<u16>,
    pub descriptor_volume_sequence_matches: Option<bool>,
    pub file_flags: Option<u8>,
    pub is_directory: Option<bool>,
    pub directory_reserved_flag_bits_valid: Option<bool>,
    pub protection_flag_requires_extended_attributes_valid: Option<bool>,
    pub file_unit_size: Option<u8>,
    pub interleave_gap_size: Option<u8>,
    pub interleave_fields_valid: Option<bool>,
    pub file_identifier_length: Option<u8>,
    pub file_identifier_byte: Option<u8>,
    pub root_identifier_valid: Option<bool>,
    pub record_structure_valid: Option<bool>,
    pub extent_start_byte_offset: Option<u64>,
    pub extent_start_sector_aligned: Option<bool>,
    pub data_start_byte_offset: Option<u64>,
    pub data_end_byte_exclusive: Option<u64>,
    pub within_declared_volume: Option<bool>,
    pub extent_status: &'static str,
    pub available: bool,
    pub extended_attribute_record_length: Option<u8>,
    pub extent_location: Option<u32>,
    pub extent_offset_bytes: Option<u64>,
    pub data_length: Option<u32>,
    pub recording_date_bytes_hex: Option<String>,
    pub directory_flag_set: Option<bool>,
    pub reserved_flags_clear: Option<bool>,
    pub volume_sequence_number: Option<u16>,
    pub file_identifier_hex: Option<String>,
    pub is_root_directory_identifier: Option<bool>,
    pub basic_structure_checks_pass: bool,
}

#[derive(Debug, Serialize)]
pub struct SevenZipEncodedHeaderPackInfo {
    pub status: &'static str,
    pub pack_pos: Option<u64>,
    pub num_pack_streams: Option<u64>,
    pub total_packed_size: Option<u64>,
    pub packed_range_within_pre_header_area: Option<bool>,
    pub packed_streams: Vec<SevenZipPackedStream>,
    pub pack_info_end_offset: Option<usize>,
}

#[derive(Debug, Serialize)]
pub struct SevenZipEncodedHeaderStreamsInfo {
    pub status: &'static str,
    pub num_folders: Option<u64>,
    pub external: Option<bool>,
    pub external_data_stream_index: Option<u64>,
    pub num_coders: Option<u64>,
    pub coder: Option<SevenZipEncodedHeaderCoder>,
    pub packed_input_index: Option<u64>,
    pub pack_stream_count_matches_folder: Option<bool>,
    pub unpack_size: Option<u64>,
    pub unpack_crc32_expected: Option<u32>,
    pub unpack_crc32_calculated: Option<u32>,
    pub unpack_crc32_valid: Option<bool>,
    pub unpack_info_terminator_seen: bool,
    pub streams_info_terminator_seen: bool,
    pub trailing_bytes: usize,
}

#[derive(Debug, Serialize)]
pub struct SevenZipEncodedHeaderCoder {
    pub flags: u8,
    pub method_id_hex: String,
    pub method_name: Option<&'static str>,
    pub num_input_streams: Option<u64>,
    pub num_output_streams: Option<u64>,
    pub properties_present: bool,
    pub properties_byte_length: usize,
    pub properties_sha256: Option<String>,
    pub properties_preview_hex: Option<String>,
    pub properties_preview_truncated: bool,
}

#[derive(Debug, Serialize)]
pub struct SevenZipPackedStream {
    pub index: usize,
    pub size: u64,
    pub byte_offset: Option<u64>,
    pub byte_end_exclusive: Option<u64>,
    pub within_pre_header_area: Option<bool>,
    pub sample_available: bool,
    pub crc32_expected: Option<u32>,
    pub crc32_calculated: Option<u32>,
    pub crc32_valid: Option<bool>,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Rar4FirstFileHeader {
    pub offset: usize,
    pub header_crc: u16,
    pub header_type: u8,
    pub flags: u16,
    pub header_size: u16,
    pub header_complete: bool,
    pub checksum_valid: Option<bool>,
    pub data_offset: usize,
    pub pack_size_low: u32,
    pub unp_size_low: u32,
    pub high_pack_size: Option<u32>,
    pub high_unp_size: Option<u32>,
    pub compressed_size: u64,
    pub uncompressed_size: Option<u64>,
    pub uncompressed_size_unknown: bool,
    pub host_os: u8,
    pub host_os_name: Option<&'static str>,
    pub file_crc32: u32,
    pub ftime_dos: u32,
    pub unpack_version: u8,
    pub method: u8,
    pub method_name: Option<&'static str>,
    pub name_size: u16,
    pub file_attributes: u32,
    pub split_before: bool,
    pub split_after: bool,
    pub encrypted: bool,
    pub comment_present: bool,
    pub solid: bool,
    pub is_directory: bool,
    pub dictionary_size_bytes: Option<u32>,
    pub large_file: bool,
    pub unicode_filename: bool,
    pub salt_present: bool,
    pub version_flag: bool,
    pub exttime_present: bool,
    pub salt_hex: Option<String>,
    pub name_bytes_available: bool,
    pub name_contains_nul: bool,
    pub name_nul_offset: Option<u16>,
    pub name_utf8_valid: bool,
    pub name_encoding: Option<&'static str>,
    pub filename: Option<String>,
    pub ascii_name_prefix: Option<String>,
    pub name_preview_hex: Option<String>,
    pub name_preview_hex_truncated: bool,
    pub name_sha256: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct Rar4VisibleBlockWalk {
    pub status: &'static str,
    pub coordinate_basis: &'static str,
    pub start_offset: Option<usize>,
    pub inspected_block_count: usize,
    pub file_block_count: usize,
    pub subblock_count: usize,
    pub comment_block_seen: bool,
    pub recovery_block_seen: bool,
    pub authenticity_block_seen: bool,
    pub end_of_archive_seen: bool,
    pub end_of_archive_next_volume: Option<bool>,
    pub end_of_archive_data_crc32: Option<u32>,
    pub end_of_archive_rev_space_present: Option<bool>,
    pub end_of_archive_volume_number: Option<u16>,
    pub stopped_offset: Option<usize>,
    pub trailing_sample_bytes: usize,
    pub blocks: Vec<Rar4VisibleBlock>,
}

#[derive(Debug, Serialize)]
pub struct Rar4VisibleBlock {
    pub index: usize,
    pub header_start_offset: usize,
    pub header_end_offset: Option<usize>,
    pub header_crc16_expected: Option<u16>,
    pub header_crc16_calculated: Option<u16>,
    pub header_crc16_valid: Option<bool>,
    pub header_type: Option<u8>,
    pub header_type_name: Option<&'static str>,
    pub header_flags: Option<u16>,
    pub header_size: Option<u16>,
    pub add_size_present: Option<bool>,
    pub data_size: Option<u64>,
    pub data_start_offset: Option<usize>,
    pub declared_data_end_offset: Option<usize>,
    pub data_range_available: Option<bool>,
    pub subblock_name: Option<String>,
    pub end_archive_next_volume: Option<bool>,
    pub end_archive_data_crc32: Option<u32>,
    pub end_archive_rev_space_present: Option<bool>,
    pub end_archive_volume_number: Option<u16>,
    pub file_header: Option<Rar4FirstFileHeader>,
}

const MAX_RAR4_VISIBLE_BLOCKS: usize = 32;

#[derive(Debug, Serialize)]
#[serde(tag = "format", rename_all = "snake_case")]
pub enum FormatHeader {
    Zstandard {
        offset: usize,
        frame: zstd_frame::Frame,
    },
    Rar4 {
        offset: usize,
        main_header_available: bool,
        main_header_type: Option<u8>,
        main_header_size: Option<u16>,
        main_header_checksum_valid: Option<bool>,
        main_header_flags: Option<u16>,
        volume: Option<bool>,
        comment: Option<bool>,
        locked: Option<bool>,
        solid: Option<bool>,
        authenticity_present: Option<bool>,
        recovery_record_present: Option<bool>,
        first_volume: Option<bool>,
        new_numbering: Option<bool>,
        encrypted_headers: Option<bool>,
        high_pos_av: Option<u16>,
        pos_av: Option<u32>,
        authenticity_position_present: Option<bool>,
        encrypted_header_salt_hex: Option<String>,
        encrypted_header_salt_available: Option<bool>,
        first_file_header_status: &'static str,
        next_block_type: Option<u8>,
        first_file: Option<Rar4FirstFileHeader>,
        visible_block_walk: Rar4VisibleBlockWalk,
    },
    Rar5 {
        offset: usize,
        first_block_header_available: bool,
        first_block_header_checksum_valid: Option<bool>,
        first_block_type: Option<u64>,
        first_block_flags: Option<u64>,
        first_block_data_area_present: Option<bool>,
        first_block_data_size: Option<u64>,
        extra_area_range_valid: Option<bool>,
        main_archive_flags: Option<u64>,
        main_is_volume: Option<bool>,
        main_is_solid: Option<bool>,
        main_has_recovery_record: Option<bool>,
        main_is_locked: Option<bool>,
        main_volume_number: Option<u64>,
        encryption_algorithm_version: Option<u64>,
        encryption_flags: Option<u64>,
        kdf_count_log2: Option<u8>,
        kdf_iteration_count: Option<u64>,
        header_encryption_salt_hex: Option<String>,
        header_encryption_iv_hex: Option<String>,
        header_encryption_iv_available: Option<bool>,
        header_encryption_iv_status: &'static str,
        password_check_present: Option<bool>,
        password_check_data_hex: Option<String>,
        password_check_trailer_checksum_expected_hex: Option<String>,
        password_check_trailer_checksum_calculated_hex: Option<String>,
        password_check_trailer_checksum_valid: Option<bool>,
        first_block_header_size_vint_byte_length: Option<usize>,
        first_block_header_size_encoding_valid: Option<bool>,
        first_block_header_size: Option<u64>,
        main_extra_records: Option<Box<Rar5MainExtraRecords>>,
        visible_block_walk: Option<Rar5VisibleBlockWalk>,
        first_block_header_size_vint_bytes: Option<u8>,
        first_block_extra_size: Option<u64>,
        main_extra_record_types: Vec<u64>,
        main_extra_records_complete: bool,
        main_locator_records: Vec<Rar5LocatorRecord>,
        main_archive_metadata_records: Vec<Rar5ArchiveMetadata>,
    },
    SevenZip {
        offset: usize,
        start_header_available: bool,
        major_version: Option<u8>,
        minor_version: Option<u8>,
        major_version_supported: Option<bool>,
        start_header_crc32: Option<u32>,
        calculated_start_header_crc32: Option<u32>,
        start_header_crc_valid: Option<bool>,
        next_header_offset: Option<u64>,
        next_header_size: Option<u64>,
        next_header_crc32: Option<u32>,
        header_range_within_sample: Option<bool>,
        next_header_checksum_valid: Option<bool>,
        next_header_observed_type: Option<u8>,
        next_header_kind: Option<&'static str>,
        next_header_interpretation_status: &'static str,
        next_header_observed_byte_length: Option<usize>,
        next_header_sha256: Option<String>,
        next_header_preview_hex: Option<String>,
        next_header_preview_truncated: bool,
        encoded_header_pack_info: Option<SevenZipEncodedHeaderPackInfo>,
        encoded_header_streams_info: Option<SevenZipEncodedHeaderStreamsInfo>,
        next_header_type: Option<u8>,
    },
    Iso9660PrimaryVolumeDescriptor {
        offset: usize,
        version: u8,
        system_identifier: String,
        volume_identifier: String,
        volume_space_blocks: Option<u32>,
        volume_space_blocks_little_endian_observed: Option<u32>,
        volume_space_blocks_big_endian_observed: Option<u32>,
        dual_endian_volume_space_match: Option<bool>,
        effective_volume_space_blocks: Option<u32>,
        volume_space_bytes: Option<u64>,
        volume_set_size: Option<u16>,
        volume_set_size_observed: Option<u16>,
        dual_endian_volume_set_size_match: Option<bool>,
        volume_set_sequence_relationship_valid: Option<bool>,
        volume_sequence_number: Option<u16>,
        volume_sequence_number_observed: Option<u16>,
        dual_endian_volume_sequence_number_match: Option<bool>,
        path_table_size: Option<u32>,
        path_table_size_little_endian_observed: Option<u32>,
        path_table_size_big_endian_observed: Option<u32>,
        dual_endian_path_table_size_match: Option<bool>,
        type_l_path_table_location: Option<u32>,
        optional_type_l_path_table_location: Option<u32>,
        optional_type_l_path_table_location_observed: Option<u32>,
        type_m_path_table_location: Option<u32>,
        optional_type_m_path_table_location: Option<u32>,
        optional_type_m_path_table_location_observed: Option<u32>,
        path_table_ranges: Vec<IsoPathTableRange>,
        root_directory_record: Box<IsoRootDirectoryRecord>,
        logical_block_size: Option<u16>,
        logical_block_size_little_endian_observed: Option<u16>,
        logical_block_size_big_endian_observed: Option<u16>,
        dual_endian_logical_block_size_match: Option<bool>,
        effective_logical_block_size: Option<u16>,
        logical_block_size_valid: Option<bool>,
        volume_set_identifier: String,
        publisher_identifier: String,
        publisher_identifier_observation: IsoIdentifierObservation,
        preparer_identifier: String,
        preparer_identifier_observation: IsoIdentifierObservation,
        application_identifier: String,
        application_identifier_observation: IsoIdentifierObservation,
        copyright_file_identifier: String,
        abstract_file_identifier: String,
        bibliographic_file_identifier: String,
        creation_timestamp_raw_hex: Option<String>,
        modification_timestamp_raw_hex: Option<String>,
        expiration_timestamp_raw_hex: Option<String>,
        effective_timestamp_raw_hex: Option<String>,
        file_structure_version: Option<u8>,
        application_use_observed_byte_length: usize,
        application_use_sha256: String,
        application_use_preview_hex: String,
        application_use_preview_truncated: bool,
        publisher_identifier_kind: &'static str,
        preparer_identifier_kind: &'static str,
        application_identifier_kind: &'static str,
        system_identifier_bytes_sha256: String,
        volume_identifier_bytes_sha256: String,
        type_l_path_table_offset_bytes: Option<u64>,
        optional_type_l_path_table_offset_bytes: Option<u64>,
        type_m_path_table_offset_bytes: Option<u64>,
        optional_type_m_path_table_offset_bytes: Option<u64>,
        volume_set_identifier_bytes_sha256: String,
        volume_dates: IsoVolumeDates,
        application_use_prefix_hex: String,
    },
    Zip {
        offset: usize,
        local_file_header_signature: bool,
        fixed_header_available: bool,
        version_needed: Option<u16>,
        general_purpose_flags: Option<u16>,
        encrypted: Option<bool>,
        data_descriptor_present: Option<bool>,
        filename_utf8_flag: Option<bool>,
        compression_method: Option<u16>,
        effective_compression_method: Option<u16>,
        effective_compression_method_source: Option<&'static str>,
        deflate_compression_option_bits: Option<u8>,
        deflate_compression_option: Option<&'static str>,
        deflate_effort_class: Option<&'static str>,
        deflate_effort_status: &'static str,
        dos_modification_time: Option<u16>,
        dos_modification_date: Option<u16>,
        crc32: Option<u32>,
        crc32_authority_status: &'static str,
        effective_crc32: Option<u32>,
        aes_metadata: ZipAesMetadata,
        compressed_size_32: Option<u32>,
        uncompressed_size_32: Option<u32>,
        zip64_size_sentinels_present: Option<bool>,
        filename_declared_byte_length: Option<usize>,
        filename_byte_length: Option<usize>,
        filename_complete: Option<bool>,
        filename_sha256: Option<String>,
        filename_bytes_hex: Option<String>,
        filename_bytes_hex_truncated: bool,
        filename_utf8_valid: Option<bool>,
        extra_field_declared_byte_length: Option<usize>,
        extra_field_byte_length: Option<usize>,
        extra_field_complete: Option<bool>,
        extra_field_sha256: Option<String>,
        extra_field_bytes_hex: Option<String>,
        extra_field_bytes_hex_truncated: bool,
        extra_field_ids: Vec<u16>,
        extra_field_count: usize,
        malformed_extra_field_bytes: usize,
        extra_field_ids_truncated: bool,
        zip64_size_metadata: Zip64SizeMetadata,
        local_header_available: bool,
        structure_valid: bool,
        flags: Option<u16>,
        modification_time_dos: Option<u16>,
        modification_date_dos: Option<u16>,
        compressed_size: Option<u32>,
        uncompressed_size: Option<u32>,
        zip64_uncompressed_size: Option<u64>,
        zip64_compressed_size: Option<u64>,
        resolved_uncompressed_size: Option<u64>,
        resolved_uncompressed_size_source: Option<&'static str>,
        resolved_compressed_size: Option<u64>,
        resolved_compressed_size_source: Option<&'static str>,
        filename_bytes_sha256: Option<String>,
        filename_hex_truncated: Option<bool>,
        filename_bytes_length: Option<usize>,
        filename_utf8_flag_set: Option<bool>,
        filename_encoding_hint: Option<&'static str>,
        extra_field_bytes_length: Option<usize>,
        extra_field_bytes_sha256: Option<String>,
        extra_field_prefix_hex: Option<String>,
        extra_field_hex_truncated: Option<bool>,
        extra_field_records: Option<Vec<ZipExtraField>>,
        extra_field_record_count: Option<usize>,
        extra_field_records_truncated: Option<bool>,
        extra_field_records_complete: Option<bool>,
        zip64_extra_payload_length: Option<usize>,
        data_descriptor_follows: Option<bool>,
        zip64_extra_present: Option<bool>,
        zip64_extra_record_count: Option<usize>,
        zip64_extra_duplicate: Option<bool>,
        zip64_extra_valid: Option<bool>,
        zip64_size_sentinel_present: Option<bool>,
        zip_aes_records: Option<Vec<ZipAesExtra>>,
        zip_aes_record_count: Option<usize>,
        zip_aes_records_truncated: Option<bool>,
        zip_aes_record_count_matches_encryption_indicators: Option<bool>,
        zip_extended_timestamp_records: Option<Vec<ZipExtendedTimestamp>>,
        zip_extended_timestamp_record_count: Option<usize>,
        zip_extended_timestamp_records_truncated: Option<bool>,
        zip_unicode_path_records: Option<Vec<ZipUnicodePathExtra>>,
        zip_unicode_path_record_count: Option<usize>,
        zip_unicode_path_records_truncated: Option<bool>,
        filename: Option<String>,
        filename_display_truncated: Option<bool>,
        resolved_filename: Option<String>,
        resolved_filename_display_truncated: Option<bool>,
        resolved_filename_source: Option<&'static str>,
        resolved_filename_status: &'static str,
    },
    Gzip {
        offset: usize,
        header_complete: bool,
        header_valid: Option<bool>,
        compression_method: Option<u8>,
        flags: Option<u8>,
        flags_reserved_bits_valid: Option<bool>,
        text_hint: Option<bool>,
        modification_time: Option<u32>,
        extra_flags: Option<u8>,
        deflate_extra_flags_meaning: Option<&'static str>,
        deflate_effort_class: Option<&'static str>,
        deflate_effort_status: &'static str,
        operating_system: Option<u8>,
        operating_system_name: Option<&'static str>,
        extra_field_present: Option<bool>,
        extra_field_length: Option<u16>,
        extra_field_sha256: Option<String>,
        extra_field_bytes_hex: Option<String>,
        extra_field_bytes_hex_truncated: bool,
        extra_subfield_count: Option<usize>,
        extra_subfield_ids_hex: Vec<String>,
        extra_subfield_ids_truncated: bool,
        extra_subfield_framing_valid: Option<bool>,
        extra_subfield_unparsed_suffix_bytes: Option<usize>,
        filename_present: Option<bool>,
        filename_terminated: Option<bool>,
        filename_byte_length: Option<usize>,
        filename_full_byte_length: Option<usize>,
        filename_sha256: Option<String>,
        filename_sha256_basis: Option<&'static str>,
        filename_bytes_hex: Option<String>,
        filename_bytes_hex_truncated: bool,
        filename_utf8_valid: Option<bool>,
        filename_latin1_printable: Option<bool>,
        filename_encoding: Option<&'static str>,
        filename: Option<String>,
        filename_display_truncated: Option<bool>,
        filename_utf8_text: Option<String>,
        filename_utf8_text_display_truncated: Option<bool>,
        comment_present: Option<bool>,
        comment_terminated: Option<bool>,
        comment_byte_length: Option<usize>,
        comment_full_byte_length: Option<usize>,
        comment_sha256: Option<String>,
        comment_sha256_basis: Option<&'static str>,
        comment_bytes_hex: Option<String>,
        comment_bytes_hex_truncated: bool,
        comment_utf8_valid: Option<bool>,
        comment_latin1_printable: Option<bool>,
        comment_encoding: Option<&'static str>,
        comment: Option<String>,
        comment_display_truncated: Option<bool>,
        comment_utf8_text: Option<String>,
        comment_utf8_text_display_truncated: Option<bool>,
        header_crc16_expected: Option<u16>,
        header_crc16_calculated: Option<u16>,
        header_crc16_valid: Option<bool>,
        header_bytes: Option<usize>,
        fixed_header_available: bool,
        structure_valid: bool,
        extra_field_declared_length: Option<usize>,
        extra_field_bytes_length: Option<usize>,
        extra_field_bytes_sha256: Option<String>,
        extra_field_prefix_hex: Option<String>,
        extra_field_hex_truncated: Option<bool>,
        original_filename_bytes_hex: Option<String>,
        original_filename_bytes_length: Option<usize>,
        original_filename_bytes_sha256: Option<String>,
        original_filename_hex_truncated: Option<bool>,
        comment_bytes_length: Option<usize>,
        comment_bytes_sha256: Option<String>,
        comment_hex_truncated: Option<bool>,
        header_crc16_present: Option<bool>,
    },
    Xz {
        offset: usize,
        first_block: xz_first_block::FirstBlock,
        header_complete: bool,
        stream_flags_hex: Option<String>,
        reserved_bits_valid: Option<bool>,
        check_id: Option<u8>,
        check_name: Option<&'static str>,
        header_crc32_expected: Option<u32>,
        header_crc32_calculated: Option<u32>,
        header_crc32_valid: Option<bool>,
        header_valid: Option<bool>,
        stream_header_available: bool,
        structure_valid: bool,
        check_id_supported: Option<bool>,
        reserved_flags_valid: Option<bool>,
        header_crc32: Option<u32>,
        calculated_header_crc32: Option<u32>,
    },
}

#[derive(Debug, Serialize)]
pub struct Zip64SizeMetadata {
    pub status: &'static str,
    pub extra_field_count: usize,
    pub payload_byte_length: Option<usize>,
    pub observed_uncompressed_size: Option<String>,
    pub observed_compressed_size: Option<String>,
    pub effective_uncompressed_size: Option<String>,
    pub effective_compressed_size: Option<String>,
    pub effective_size_basis: &'static str,
}

#[derive(Debug, Serialize)]
pub struct ZipAesMetadata {
    pub status: &'static str,
    pub extra_field_count: usize,
    pub payload_byte_length: Option<usize>,
    pub vendor_version: Option<u16>,
    pub vendor_id_hex: Option<String>,
    pub strength: Option<u8>,
    pub actual_compression_method: Option<u16>,
    pub payload_extension_byte_length: Option<usize>,
    pub payload_extension_sha256: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct Rar5MainExtraRecords {
    pub available: bool,
    pub parsed_record_count: usize,
    pub malformed_record_count: usize,
    pub locator_record_count: usize,
    pub locator_records_ambiguous: bool,
    pub metadata_record_count: usize,
    pub metadata_records_ambiguous: bool,
    pub record_types: Vec<u64>,
    pub record_types_truncated: bool,
    pub archive_name: Option<String>,
    pub archive_name_byte_length: Option<usize>,
    pub archive_name_sha256: Option<String>,
    pub archive_name_utf8_valid: Option<bool>,
    pub archive_name_bytes_hex: Option<String>,
    pub archive_name_bytes_hex_truncated: bool,
    pub archive_name_status: Option<&'static str>,
    pub creation_time_value: Option<u64>,
    pub creation_time_format: Option<&'static str>,
    pub creation_time_unit: Option<&'static str>,
    pub quick_open_offset: Option<u64>,
    pub recovery_record_offset: Option<u64>,
}

#[derive(Debug, Serialize)]
pub struct Rar5VisibleBlockWalk {
    pub status: &'static str,
    pub coordinate_basis: &'static str,
    pub start_offset: usize,
    pub inspected_block_count: usize,
    pub end_of_archive_seen: bool,
    pub stopped_offset: Option<usize>,
    pub trailing_sample_bytes: usize,
    pub blocks: Vec<Rar5VisibleBlock>,
    pub quick_open: Rar5LocatorResolution,
    pub recovery_record: Rar5LocatorResolution,
}

#[derive(Debug, Serialize)]
pub struct Rar5VisibleBlock {
    pub index: usize,
    pub header_start_offset: usize,
    pub header_size_vint_byte_length: Option<usize>,
    pub header_size_encoding_valid: Option<bool>,
    pub header_end_offset: Option<usize>,
    pub header_crc32_expected: Option<u32>,
    pub header_crc32_calculated: Option<u32>,
    pub header_crc32_valid: Option<bool>,
    pub header_type: Option<u64>,
    pub header_flags: Option<u64>,
    pub end_archive_flags: Option<u64>,
    pub data_size: Option<u64>,
    pub data_start_offset: Option<usize>,
    pub declared_data_end_offset: Option<usize>,
    pub data_range_available: Option<bool>,
    pub service_name_bytes_hex: Option<String>,
    pub service_name_utf8_valid: Option<bool>,
    pub file_header: Option<Rar5FileHeaderObservation>,
    pub service_header: Option<Rar5FileHeaderObservation>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub quick_open_cache: Option<rar5_quick_open::Metadata>,
}

#[derive(Debug, Serialize)]
pub struct Rar5FileHeaderObservation {
    pub status: &'static str,
    pub header_crc32_valid: Option<bool>,
    pub packed_size: Option<u64>,
    pub directory: Option<bool>,
    pub split_before: bool,
    pub split_after: bool,
    pub file_flags: Option<u64>,
    pub unpacked_size_observed: Option<u64>,
    pub unpacked_size_effective: Option<u64>,
    pub unpacked_size_unknown: Option<bool>,
    pub attributes: Option<u64>,
    pub modification_time_unix_seconds: Option<u32>,
    pub data_crc32: Option<u32>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub data_crc32_byte_offset: Option<usize>,
    pub data_crc32_scope: Option<&'static str>,
    pub data_crc32_scope_basis: Option<&'static str>,
    pub checksum_domain: &'static str,
    pub checksum_transformation: &'static str,
    pub checksum_transformation_status: &'static str,
    pub encryption_presence: &'static str,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub redirection_checksum_relationship: Option<rar5_copy_checksums::Relationship>,
    pub file_extra_records: Rar5FileExtraRecords,
    pub compression_info: Option<u64>,
    pub compression_version: Option<u8>,
    pub solid: Option<bool>,
    pub compression_method: Option<u8>,
    pub dictionary_size_exponent: Option<u8>,
    pub compression_metadata: Option<Rar5CompressionMetadata>,
    pub host_os: Option<u64>,
    pub name_byte_length: Option<usize>,
    pub name_utf8_valid: Option<bool>,
    pub filename: Option<String>,
    pub name_interpretation: &'static str,
    pub name_sha256: Option<String>,
    pub name_preview_hex: Option<String>,
    pub name_preview_truncated: bool,
}

#[derive(Debug, Serialize)]
pub struct Rar5CompressionMetadata {
    pub status: &'static str,
    pub interpretation_status: &'static str,
    pub evidence_basis: &'static str,
    pub encoding_version: u8,
    pub dictionary_exponent_observed: u8,
    pub dictionary_fraction_bits_observed: u8,
    pub algorithm_zero_compatibility_bit_observed: bool,
    pub algorithm_version_from_flags: Option<u8>,
    pub encoding_generation: Option<&'static str>,
    pub declared_dictionary_bytes: Option<u64>,
    pub effective_dictionary_bytes: Option<u64>,
}

fn rar5_compression_metadata(info: u64, header_trusted: bool) -> Rar5CompressionMetadata {
    let version = (info & 0x3f) as u8;
    let method = ((info >> 7) & 7) as u8;
    let exponent = ((info >> 10) & 31) as u8;
    let fraction = ((info >> 15) & 31) as u8;
    let compatibility = info & 0x100000 != 0;
    let known_encoding = match version {
        0 => info & !0x7fff == 0,
        1 => info & !0x1fffff == 0,
        _ => false,
    };
    let mut interpretation_status = if version > 1 {
        "compression_version_unsupported"
    } else if !known_encoding {
        "compression_flags_unsupported"
    } else if exponent > if version == 0 { 15 } else { 23 } {
        "dictionary_exponent_unsupported"
    } else if method > 5 {
        "compression_method_unsupported"
    } else {
        "supported"
    };
    let declared_dictionary_bytes = (interpretation_status == "supported")
        .then(|| {
            let base = 131_072u64.checked_shl(u32::from(exponent))?;
            let addition = (base / 32).checked_mul(u64::from(fraction))?;
            base.checked_add(addition)
        })
        .flatten();
    if declared_dictionary_bytes.is_some_and(|size| size > (1u64 << 40)) {
        interpretation_status = "dictionary_size_exceeds_format_limit";
    }
    Rar5CompressionMetadata {
        status: if header_trusted {
            interpretation_status
        } else {
            "header_untrusted"
        },
        interpretation_status,
        evidence_basis: "RAR5 file/service compression-info vint; RAR technote dictionary encoding, not measured memory allocation, writer version, or uploader identity",
        encoding_version: version,
        dictionary_exponent_observed: exponent,
        dictionary_fraction_bits_observed: fraction,
        algorithm_zero_compatibility_bit_observed: compatibility,
        algorithm_version_from_flags: known_encoding.then_some(if version == 1 && !compatibility {
            1
        } else {
            0
        }),
        encoding_generation: known_encoding.then_some(if version == 0 {
            "RAR 5.0"
        } else {
            "RAR 7.0"
        }),
        declared_dictionary_bytes,
        effective_dictionary_bytes: (header_trusted && interpretation_status == "supported")
            .then_some(declared_dictionary_bytes)
            .flatten(),
    }
}

#[derive(Debug, Serialize)]
pub struct Rar5FileExtraRecords {
    pub status: &'static str,
    pub coordinate_basis: &'static str,
    pub byte_length: usize,
    pub sha256: String,
    pub record_count: usize,
    pub encryption_record_count: usize,
    pub hash_record_count: usize,
    pub redirection_record_count: usize,
    pub time_record_count: usize,
    pub version_record_count: usize,
    pub version: Option<Rar5ArchivedVersionRecord>,
    pub reference_interpreted_name: Option<String>,
    pub reference_name_relationship_status: &'static str,
    pub reference_name_relationship_basis: &'static str,
    pub time: Option<Rar5FileTimeRecord>,
    pub mtime_comparison_status: &'static str,
    pub base_header_mtime_seconds_matches: Option<bool>,
    pub encryption: Option<Rar5FileEncryptionRecord>,
    pub hash: Option<Rar5FileHashRecord>,
    pub unambiguous_stored_blake2sp_hex: Option<String>,
    pub stored_hash_status: &'static str,
    pub records: Vec<Rar5FileExtraRecord>,
    pub records_truncated: bool,
    pub stopped_offset: Option<usize>,
    pub unparsed_byte_count: usize,
}

#[derive(Debug, Serialize)]
pub struct Rar5FileExtraRecord {
    pub index: usize,
    pub offset: usize,
    pub record_end_offset: usize,
    pub record_size: u64,
    pub record_size_vint_byte_length: usize,
    pub record_type: u64,
    pub payload_offset: usize,
    pub payload_byte_length: usize,
    pub payload_sha256: String,
    pub payload_preview_hex: String,
    pub payload_preview_truncated: bool,
    pub status: &'static str,
    pub encryption: Option<Rar5FileEncryptionRecord>,
    pub hash: Option<Rar5FileHashRecord>,
    pub redirection: Option<Rar5RedirectionRecord>,
    pub time: Option<Rar5FileTimeRecord>,
    pub version: Option<Rar5ArchivedVersionRecord>,
}

#[derive(Debug, Serialize, Clone)]
pub struct Rar5ArchivedVersionRecord {
    pub status: &'static str,
    pub interpretation_status: &'static str,
    pub evidence_basis: &'static str,
    pub coordinate_basis: &'static str,
    pub source_coordinate_basis: &'static str,
    pub source_record_index: usize,
    pub source_record_offset: usize,
    pub source_payload_offset: usize,
    pub source_payload_byte_length: usize,
    pub source_payload_sha256: String,
    pub flags: Option<u64>,
    pub flags_encoding_status: &'static str,
    pub flags_raw_hex: String,
    pub version_number: Option<u64>,
    pub version_number_byte_offset: Option<usize>,
    pub version_number_encoding_status: &'static str,
    pub version_number_raw_hex: String,
    pub trailing_byte_count: usize,
}

#[derive(Debug, Serialize, Clone)]
pub struct Rar5FileTimeRecord {
    pub status: &'static str,
    pub interpretation_status: &'static str,
    pub evidence_basis: &'static str,
    pub coordinate_basis: &'static str,
    pub source_coordinate_basis: &'static str,
    pub source_record_index: usize,
    pub source_record_offset: usize,
    pub source_payload_offset: usize,
    pub source_payload_byte_length: usize,
    pub source_payload_sha256: String,
    pub flags: Option<u64>,
    pub unknown_flags: Option<u64>,
    pub storage_format: Option<&'static str>,
    pub times: Vec<Rar5TimeValue>,
    pub trailing_byte_count: usize,
}

#[derive(Debug, Serialize, Clone)]
pub struct Rar5TimeValue {
    pub kind: &'static str,
    pub base_value_byte_offset: Option<usize>,
    pub base_value: Option<u64>,
    pub base_value_raw_hex: String,
    pub base_value_bytes_available: usize,
    pub nanoseconds_declared: bool,
    pub nanoseconds_byte_offset: Option<usize>,
    pub nanoseconds: Option<u32>,
    pub nanoseconds_raw_hex: String,
    pub nanoseconds_bytes_available: usize,
    pub nanoseconds_range_valid: Option<bool>,
    pub filetime_unix_seconds_floor: Option<i64>,
    pub filetime_unix_subsecond_nanoseconds: Option<u32>,
    pub filetime_calendar_api_input_range_supported: Option<bool>,
    pub filetime_epoch_relationship_status: &'static str,
    pub filetime_epoch_relationship_basis: &'static str,
}

#[derive(Debug, Serialize)]
pub struct Rar5RedirectionRecord {
    pub status: &'static str,
    pub interpretation_status: &'static str,
    pub evidence_basis: &'static str,
    pub redirection_type: Option<u64>,
    pub kind_observed: Option<&'static str>,
    pub flags: Option<u64>,
    pub unknown_flags: Option<u64>,
    pub target_directory_observed: Option<bool>,
    pub target_byte_length: Option<u64>,
    pub target_utf8_valid: Option<bool>,
    pub target_contains_nul: Option<bool>,
    pub target_utf8: Option<String>,
    pub target_text_truncated: bool,
    pub target_sha256: Option<String>,
    pub target_preview_hex: Option<String>,
    pub target_preview_truncated: bool,
    pub trailing_byte_count: Option<usize>,
    pub target_resolution_performed: bool,
}

#[derive(Debug, Serialize, Clone)]
pub struct Rar5FileEncryptionRecord {
    pub status: &'static str,
    pub version: Option<u64>,
    pub algorithm: Option<&'static str>,
    pub flags: Option<u64>,
    pub unknown_flags: Option<u64>,
    pub checksums_key_dependent_observed: Option<bool>,
    pub kdf_count_log2: Option<u8>,
    pub kdf_iteration_count_decimal: Option<String>,
    pub salt_hex: Option<String>,
    pub iv_hex: Option<String>,
    pub password_check_present: Option<bool>,
    pub password_check_data_hex: Option<String>,
    pub password_check_checksum_expected_hex: Option<String>,
    pub password_check_checksum_calculated_hex: Option<String>,
    pub password_check_checksum_valid: Option<bool>,
    pub password_check_basis: &'static str,
    pub trailing_byte_count: usize,
}

#[derive(Debug, Serialize, Clone)]
pub struct Rar5FileHashRecord {
    pub status: &'static str,
    pub hash_type: Option<u64>,
    pub algorithm: Option<&'static str>,
    pub stored_digest_hex: Option<String>,
    pub trailing_byte_count: usize,
}

const MAX_RAR5_FILE_EXTRA_RECORD_OBSERVATIONS: usize = 64;
const MAX_RAR5_FILE_EXTRA_RECORDS_SCANNED: usize = 4096;
const MAX_RAR5_FILE_EXTRA_PAYLOAD_PREVIEW_BYTES: usize = 128;

#[derive(Debug, Serialize)]
pub struct Rar5LocatorResolution {
    pub expected_service_name: &'static str,
    pub relative_offset: Option<u64>,
    pub target_sample_offset: Option<usize>,
    pub status: &'static str,
    pub matched_block_index: Option<usize>,
    pub target_type: Option<u64>,
    pub target_service_name_bytes_hex: Option<String>,
    pub target_header_crc32_valid: Option<bool>,
    pub target_data_range_available: Option<bool>,
}

const RAR4: &[u8] = b"Rar!\x1a\x07\x00";
const RAR5: &[u8] = b"Rar!\x1a\x07\x01\x00";
const SEVEN_ZIP: &[u8] = b"7z\xbc\xaf'\x1c";
const ZIP: &[u8] = b"PK\x03\x04";
const GZIP: &[u8] = b"\x1f\x8b\x08";
const XZ: &[u8] = b"\xfd7zXZ\0";
const MAX_7Z_NEXT_HEADER_PREVIEW_BYTES: usize = 256;
const MAX_RECORDED_RAR5_EXTRA_RECORD_TYPES: usize = 4_096;
const MAX_RETAINED_RAR5_ARCHIVE_NAME_BYTES: usize = 4_096;
const MAX_RECORDED_GZIP_FIELD_HEX_BYTES: usize = 256;
// Support this many non-NUL text bytes, with one bounded terminator/lookahead
// byte. Retained previews are capped independently above.
const MAX_GZIP_OPTIONAL_FIELD_BYTES: usize = 65_536;
const MAX_RETAINED_GZIP_TEXT_DISPLAY_BYTES: usize = 4_096;
const MAX_RECORDED_GZIP_EXTRA_SUBFIELD_IDS: usize = 4_096;
const MAX_RECORDED_ZIP_FIELD_HEX_BYTES: usize = 256;
const MAX_RETAINED_ZIP_FILENAME_DISPLAY_BYTES: usize = 4_096;
const MAX_RECORDED_ZIP_EXTRA_FIELD_IDS: usize = 4_096;
#[cfg(test)]
const ISO_PVD_OFFSET: usize = 32_768;
const ISO_LOGICAL_SECTOR_SIZE_BYTES: u64 = 2048;

struct GzipTextFieldObservation {
    terminated: bool,
    observed_byte_length: usize,
    full_byte_length: Option<usize>,
    sha256: String,
    sha256_basis: &'static str,
    bytes_hex: String,
    bytes_hex_truncated: bool,
    utf8_valid: bool,
    latin1_printable: bool,
    encoding: &'static str,
    decoded_text: Option<String>,
    display_truncated: bool,
    utf8_text: Option<String>,
    utf8_text_display_truncated: Option<bool>,
    next_offset: usize,
    complete: bool,
}

pub fn parse(data: &[u8]) -> FormatMetadata {
    let prefix_length = data.len().min(SAMPLE_PREFIX_IDENTITY_LIMIT_BYTES);
    let sample_prefix_identity = SamplePrefixIdentity {
        status: if prefix_length == 0 {
            "empty_input"
        } else {
            "sampled_prefix"
        },
        basis: "decoded parser input bytes beginning at input offset zero; absolute file offset requires separate yEnc evidence",
        prefix_limit_bytes: SAMPLE_PREFIX_IDENTITY_LIMIT_BYTES,
        bytes_hashed: prefix_length,
        md5: hex(&Md5::digest(&data[..prefix_length])),
        parser_input_offset_zero: true,
        file_offset_known: false,
    };
    let mut signatures = Vec::new();
    let mut headers = Vec::new();
    let mut candidate_scan_truncated_formats = Vec::new();
    let mut quick_open_budget = rar5_quick_open::Budget::default();

    for (format, signature) in [
        ("RAR 5", RAR5),
        ("RAR 4", RAR4),
        ("7-Zip", SEVEN_ZIP),
        ("ZIP", ZIP),
        ("gzip", GZIP),
        ("XZ", XZ),
        ("Zstandard", b"\x28\xb5\x2f\xfd"),
    ] {
        let maximum_end = if matches!(format, "RAR 4" | "RAR 5") {
            1_048_576 + signature.len()
        } else {
            data.len()
        };
        let (offsets, truncated) = find_all_with_limit(
            data,
            signature,
            maximum_end,
            MAX_SIGNATURE_CANDIDATES_PER_FORMAT,
        );
        if truncated {
            candidate_scan_truncated_formats.push(format);
        }
        for offset in offsets {
            signatures.push(SignatureMatch {
                format,
                offset,
                basis: "decoded sample bytes",
            });
            match format {
                "RAR 4" => headers.push(parse_rar4(data, offset)),
                "RAR 5" => headers.push(parse_rar5(data, offset, &mut quick_open_budget)),
                "7-Zip" => headers.push(parse_7z(data, offset)),
                "ZIP" => headers.push(parse_zip(data, offset)),
                "gzip" => headers.push(parse_gzip(data, offset)),
                "XZ" => headers.push(parse_xz(data, offset)),
                "Zstandard" => headers.push(FormatHeader::Zstandard {
                    offset,
                    frame: zstd_frame::parse(data, offset),
                }),
                _ => {}
            }
        }
    }

    let iso_descriptor_sequence = scan_iso_descriptor_sequence(data);
    let iso_primary_volume_descriptor_copies = iso_descriptor_sequence
        .as_ref()
        .map(|sequence| iso_primary_copies::parse(data, sequence));
    let mut iso_path_table_copies = None;
    if let Some(offset) = iso_descriptor_sequence
        .as_ref()
        .and_then(|sequence| sequence.first_primary_volume_descriptor_offset)
    {
        signatures.push(SignatureMatch {
            format: "ISO 9660 primary volume descriptor",
            offset,
            basis: "validated type-1 descriptor in the bounded ISO descriptor sequence",
        });
        let primary = parse_iso_pvd(data, offset);
        if let Some(sequence) = iso_descriptor_sequence.as_ref() {
            iso_path_table_copies = iso_path_tables::parse(data, &primary, sequence);
        }
        headers.push(primary);
    }

    FormatMetadata {
        parser_engine: "nntmux-rust-ffi",
        iso_primary_volume_descriptor_copies,
        iso_path_table_copies,
        sample_prefix_identity,
        candidate_scan_limit_per_format: MAX_SIGNATURE_CANDIDATES_PER_FORMAT,
        candidate_scan_truncated_formats,
        signatures,
        headers,
        iso_descriptor_sequence,
    }
}

fn parse_rar4(data: &[u8], offset: usize) -> FormatHeader {
    let header = offset + RAR4.len();
    let header_size = data
        .get(header + 5..header + 7)
        .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]]));
    let header_end = header_size.and_then(|size| header.checked_add(usize::from(size)));
    let full_header = header_end.and_then(|end| data.get(header..end));
    let header_type = data.get(header + 2).copied();
    let checksum_valid = full_header.and_then(|block| {
        (block.len() >= 13 && header_type == Some(0x73)).then(|| {
            let expected = u16::from_le_bytes([block[0], block[1]]);
            let flags = u16::from_le_bytes([block[3], block[4]]);
            let checksum_end = if flags & 0x0002 != 0 { 13 } else { block.len() };
            let calculated = crc32fast::hash(&block[2..checksum_end]) as u16;
            expected == calculated
        })
    });
    let flags = full_header
        .filter(|block| block.len() >= 13 && header_type == Some(0x73))
        .map(|bytes| u16::from_le_bytes([bytes[3], bytes[4]]));
    let main_header_available =
        full_header.is_some_and(|block| block.len() >= 13 && header_type == Some(0x73));
    let encrypted_header_salt_available = flags
        .filter(|flags| flags & 0x0080 != 0)
        .map(|_| header_end.is_some_and(|end| data.get(end..end.saturating_add(8)).is_some()));
    let encrypted_header_salt_hex = encrypted_header_salt_available
        .filter(|available| *available)
        .and_then(|_| {
            header_end.and_then(|end| {
                end.checked_add(8)
                    .and_then(|salt_end| data.get(end..salt_end))
            })
        })
        .map(hex);
    let high_pos_av = full_header
        .filter(|block| block.len() >= 13 && header_type == Some(0x73))
        .map(|bytes| u16::from_le_bytes([bytes[7], bytes[8]]));
    let pos_av = full_header
        .filter(|block| block.len() >= 13 && header_type == Some(0x73))
        .map(|bytes| u32::from_le_bytes(bytes[9..13].try_into().expect("four-byte RAR4 PosAV")));
    let encrypted_headers_flag = flags.map(|value| value & 0x0080 != 0);
    let (mut first_file_header_status, next_block_type, mut first_file) =
        parse_rar4_first_file_header(
            data,
            header_end,
            main_header_available,
            checksum_valid,
            encrypted_headers_flag,
        );
    let visible_block_walk = walk_rar4_visible_blocks(
        data,
        header_end,
        main_header_available,
        checksum_valid,
        encrypted_headers_flag,
    );
    if first_file.is_none()
        && first_file_header_status == "non_file_block"
        && let Some(discovered) = visible_block_walk
            .blocks
            .iter()
            .find_map(|block| block.file_header.as_ref())
    {
        first_file_header_status = if discovered.checksum_valid == Some(true) {
            "parsed_after_prefix_blocks"
        } else {
            "checksum_invalid_after_prefix_blocks"
        };
        first_file = Some(discovered.clone());
    }
    FormatHeader::Rar4 {
        offset,
        main_header_available,
        main_header_type: header_type,
        main_header_size: header_size,
        main_header_checksum_valid: checksum_valid,
        main_header_flags: flags,
        volume: flags.map(|value| value & 0x0001 != 0),
        comment: flags.map(|value| value & 0x0002 != 0),
        locked: flags.map(|value| value & 0x0004 != 0),
        solid: flags.map(|value| value & 0x0008 != 0),
        authenticity_present: flags.map(|value| value & 0x0020 != 0),
        recovery_record_present: flags.map(|value| value & 0x0040 != 0),
        first_volume: flags.map(|value| value & 0x0100 != 0),
        new_numbering: flags.map(|value| value & 0x0010 != 0),
        encrypted_headers: encrypted_headers_flag,
        high_pos_av,
        pos_av,
        authenticity_position_present: high_pos_av
            .zip(pos_av)
            .map(|(high, low)| high != 0 || low != 0),
        encrypted_header_salt_hex,
        encrypted_header_salt_available,
        first_file_header_status,
        next_block_type,
        first_file,
        visible_block_walk,
    }
}

fn rar4_block_type_name(header_type: u8) -> Option<&'static str> {
    match header_type {
        0x72 => Some("marker"),
        0x73 => Some("main_header"),
        0x74 => Some("file_header"),
        0x75 => Some("comment_header"),
        0x76 => Some("authenticity_info"),
        0x77 => Some("old_subblock"),
        0x78 => Some("recovery_record"),
        0x79 => Some("authenticity_signature"),
        0x7a => Some("new_subblock"),
        0x7b => Some("end_of_archive"),
        _ => None,
    }
}

fn walk_rar4_visible_blocks(
    data: &[u8],
    header_end: Option<usize>,
    main_header_available: bool,
    main_checksum_valid: Option<bool>,
    encrypted_headers: Option<bool>,
) -> Rar4VisibleBlockWalk {
    let mut walk = Rar4VisibleBlockWalk {
        status: "pending",
        coordinate_basis: "decoded-sample-relative",
        start_offset: header_end,
        inspected_block_count: 0,
        file_block_count: 0,
        subblock_count: 0,
        comment_block_seen: false,
        recovery_block_seen: false,
        authenticity_block_seen: false,
        end_of_archive_seen: false,
        end_of_archive_next_volume: None,
        end_of_archive_data_crc32: None,
        end_of_archive_rev_space_present: None,
        end_of_archive_volume_number: None,
        stopped_offset: header_end,
        trailing_sample_bytes: 0,
        blocks: Vec::new(),
    };

    if !main_header_available {
        walk.status = "main_header_incomplete";
        return walk;
    }
    if main_checksum_valid != Some(true) {
        walk.status = "main_header_checksum_invalid";
        return walk;
    }
    if encrypted_headers == Some(true) {
        walk.status = "encrypted_headers";
        return walk;
    }
    let Some(mut cursor) = header_end else {
        walk.status = "main_header_incomplete";
        return walk;
    };

    for block_index in 0..MAX_RAR4_VISIBLE_BLOCKS {
        if cursor >= data.len() {
            walk.status = "sample_end";
            walk.stopped_offset = Some(cursor);
            break;
        }
        let mut block_obs = Rar4VisibleBlock {
            index: block_index,
            header_start_offset: cursor,
            header_end_offset: None,
            header_crc16_expected: None,
            header_crc16_calculated: None,
            header_crc16_valid: None,
            header_type: None,
            header_type_name: None,
            header_flags: None,
            header_size: None,
            add_size_present: None,
            data_size: None,
            data_start_offset: None,
            declared_data_end_offset: None,
            data_range_available: None,
            subblock_name: None,
            end_archive_next_volume: None,
            end_archive_data_crc32: None,
            end_archive_rev_space_present: None,
            end_archive_volume_number: None,
            file_header: None,
        };

        let Some(base_end) = cursor.checked_add(7) else {
            walk.inspected_block_count += 1;
            walk.blocks.push(block_obs);
            walk.status = "truncated_base_header";
            walk.stopped_offset = Some(cursor);
            break;
        };
        let Some(base) = data.get(cursor..base_end) else {
            walk.inspected_block_count += 1;
            walk.blocks.push(block_obs);
            walk.status = "truncated_base_header";
            walk.stopped_offset = Some(cursor);
            break;
        };
        let expected_crc = u16::from_le_bytes([base[0], base[1]]);
        let header_type = base[2];
        let flags = u16::from_le_bytes([base[3], base[4]]);
        let header_size = u16::from_le_bytes([base[5], base[6]]);
        let add_size_present =
            (flags & 0x8000 != 0) || matches!(header_type, 0x74 | 0x77 | 0x78 | 0x7a);

        block_obs.header_crc16_expected = Some(expected_crc);
        block_obs.header_type = Some(header_type);
        block_obs.header_type_name = rar4_block_type_name(header_type);
        block_obs.header_flags = Some(flags);
        block_obs.header_size = Some(header_size);
        block_obs.add_size_present = Some(add_size_present);

        let min_header_size = if matches!(header_type, 0x74 | 0x7a) {
            if flags & 0x0100 != 0 {
                40usize
            } else {
                32usize
            }
        } else if add_size_present {
            11usize
        } else {
            7usize
        };
        if usize::from(header_size) < min_header_size {
            walk.inspected_block_count += 1;
            walk.blocks.push(block_obs);
            walk.status = "invalid_header_size";
            walk.stopped_offset = Some(cursor);
            break;
        }
        let Some(block_header_end) = cursor.checked_add(usize::from(header_size)) else {
            walk.inspected_block_count += 1;
            walk.blocks.push(block_obs);
            walk.status = "header_end_overflow";
            walk.stopped_offset = Some(cursor);
            break;
        };
        block_obs.header_end_offset = Some(block_header_end);
        let Some(header_bytes) = data.get(cursor..block_header_end) else {
            walk.inspected_block_count += 1;
            walk.blocks.push(block_obs);
            walk.status = "header_truncated";
            walk.stopped_offset = Some(cursor);
            break;
        };
        let calculated_crc = (crc32fast::hash(&header_bytes[2..]) & 0xffff) as u16;
        let crc_valid = calculated_crc == expected_crc;
        block_obs.header_crc16_calculated = Some(calculated_crc);
        block_obs.header_crc16_valid = Some(crc_valid);

        let data_size: u64 = if matches!(header_type, 0x74 | 0x7a) {
            let low = u32::from_le_bytes([
                header_bytes[7],
                header_bytes[8],
                header_bytes[9],
                header_bytes[10],
            ]);
            if flags & 0x0100 != 0 {
                let high = u32::from_le_bytes([
                    header_bytes[32],
                    header_bytes[33],
                    header_bytes[34],
                    header_bytes[35],
                ]);
                (u64::from(high) << 32) | u64::from(low)
            } else {
                u64::from(low)
            }
        } else if add_size_present {
            u64::from(u32::from_le_bytes([
                header_bytes[7],
                header_bytes[8],
                header_bytes[9],
                header_bytes[10],
            ]))
        } else {
            0
        };
        block_obs.data_size = Some(data_size);
        block_obs.data_start_offset = Some(block_header_end);

        match header_type {
            0x74 => {
                let (file_status, _, parsed_file) =
                    parse_rar4_first_file_header(data, Some(cursor), true, Some(true), Some(false));
                if parsed_file.is_none() {
                    walk.inspected_block_count += 1;
                    walk.blocks.push(block_obs);
                    walk.status = file_status;
                    walk.stopped_offset = Some(cursor);
                    break;
                }
                walk.file_block_count += 1;
                block_obs.file_header = parsed_file;
            }
            0x75 => {
                walk.comment_block_seen = true;
            }
            0x76 | 0x79 => {
                walk.authenticity_block_seen = true;
            }
            0x78 => {
                walk.recovery_block_seen = true;
            }
            0x7a => {
                walk.subblock_count += 1;
                let name_offset = if flags & 0x0100 != 0 {
                    40usize
                } else {
                    32usize
                };
                let name_size =
                    usize::from(u16::from_le_bytes([header_bytes[26], header_bytes[27]]));
                let Some(name_end) = name_offset.checked_add(name_size) else {
                    walk.inspected_block_count += 1;
                    walk.blocks.push(block_obs);
                    walk.status = "invalid_header_size";
                    walk.stopped_offset = Some(cursor);
                    break;
                };
                if name_end > header_bytes.len() {
                    walk.inspected_block_count += 1;
                    walk.blocks.push(block_obs);
                    walk.status = "invalid_header_size";
                    walk.stopped_offset = Some(cursor);
                    break;
                }
                let name_bytes = &header_bytes[name_offset..name_end];
                if name_bytes.is_ascii() && !name_bytes.iter().any(u8::is_ascii_control) {
                    let sub_name = String::from_utf8_lossy(name_bytes).into_owned();
                    match sub_name.as_str() {
                        "CMT" => walk.comment_block_seen = true,
                        "RR" => walk.recovery_block_seen = true,
                        "AV" => walk.authenticity_block_seen = true,
                        _ => {}
                    }
                    block_obs.subblock_name = Some(sub_name);
                }
            }
            0x7b => {
                let next_vol = flags & 0x0001 != 0;
                let has_crc = flags & 0x0002 != 0;
                let rev_space = flags & 0x0004 != 0;
                let has_volnum = flags & 0x0008 != 0;
                let mut end_cursor = 7usize;
                let data_crc = if has_crc {
                    if let Some(bytes) = header_bytes.get(end_cursor..end_cursor + 4) {
                        end_cursor += 4;
                        Some(u32::from_le_bytes([bytes[0], bytes[1], bytes[2], bytes[3]]))
                    } else {
                        None
                    }
                } else {
                    None
                };
                let vol_num = if has_volnum {
                    header_bytes
                        .get(end_cursor..end_cursor + 2)
                        .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]]))
                } else {
                    None
                };
                block_obs.end_archive_next_volume = Some(next_vol);
                block_obs.end_archive_data_crc32 = data_crc;
                block_obs.end_archive_rev_space_present = Some(rev_space);
                block_obs.end_archive_volume_number = vol_num;
                if crc_valid {
                    walk.end_of_archive_seen = true;
                    walk.end_of_archive_next_volume = Some(next_vol);
                    walk.end_of_archive_data_crc32 = data_crc;
                    walk.end_of_archive_rev_space_present = Some(rev_space);
                    walk.end_of_archive_volume_number = vol_num;
                }
            }
            _ => {}
        }

        let Some(data_size_usize) = usize::try_from(data_size)
            .ok()
            .and_then(|sz| block_header_end.checked_add(sz))
        else {
            walk.inspected_block_count += 1;
            walk.blocks.push(block_obs);
            walk.status = "data_end_overflow";
            walk.stopped_offset = Some(cursor);
            break;
        };
        let block_end = data_size_usize;
        block_obs.declared_data_end_offset = Some(block_end);
        block_obs.data_range_available = Some(block_end <= data.len());

        walk.inspected_block_count += 1;
        walk.blocks.push(block_obs);

        if !crc_valid {
            walk.status = "header_checksum_invalid";
            walk.stopped_offset = Some(cursor);
            break;
        }
        if header_type == 0x7b {
            if block_end > data.len() {
                walk.status = "data_truncated";
                walk.stopped_offset = Some(cursor);
                break;
            }
            walk.status = "end_of_archive";
            walk.stopped_offset = Some(cursor);
            walk.trailing_sample_bytes = data.len().saturating_sub(block_end);
            break;
        }
        if block_end > data.len() {
            walk.status = "data_truncated";
            walk.stopped_offset = Some(cursor);
            break;
        }
        cursor = block_end;
        if block_index + 1 == MAX_RAR4_VISIBLE_BLOCKS {
            walk.status = "block_budget_exhausted";
            walk.stopped_offset = Some(cursor);
        }
    }

    if walk.status == "pending" {
        walk.status = "block_budget_exhausted";
        walk.stopped_offset = Some(cursor);
    }
    walk
}

fn parse_rar4_first_file_header(
    data: &[u8],
    header_end: Option<usize>,
    main_header_available: bool,
    main_checksum_valid: Option<bool>,
    encrypted_headers: Option<bool>,
) -> (&'static str, Option<u8>, Option<Rar4FirstFileHeader>) {
    if !main_header_available {
        return ("main_header_incomplete", None, None);
    }
    if main_checksum_valid != Some(true) {
        return ("main_header_checksum_invalid", None, None);
    }
    if encrypted_headers == Some(true) {
        return ("encrypted_headers", None, None);
    }
    let Some(block_start) = header_end else {
        return ("main_header_incomplete", None, None);
    };
    if data.len() <= block_start {
        return ("truncated_before_next_block", None, None);
    }
    let Some(base_end) = block_start.checked_add(7) else {
        return ("truncated_base_header", None, None);
    };
    let Some(base) = data.get(block_start..base_end) else {
        return ("truncated_base_header", None, None);
    };
    let header_crc = u16::from_le_bytes([base[0], base[1]]);
    let header_type = base[2];
    let flags = u16::from_le_bytes([base[3], base[4]]);
    let header_size = u16::from_le_bytes([base[5], base[6]]);
    if header_type != 0x74 {
        return ("non_file_block", Some(header_type), None);
    }
    let large_file = flags & 0x0100 != 0;
    let salt_present = flags & 0x0400 != 0;
    let name_offset = if large_file { 40usize } else { 32usize };
    if usize::from(header_size) < name_offset {
        return ("invalid_header_size", Some(header_type), None);
    }
    let Some(fixed_end) = block_start.checked_add(name_offset) else {
        return ("truncated_file_header", Some(header_type), None);
    };
    let Some(fixed_block) = data.get(block_start..fixed_end) else {
        return ("truncated_file_header", Some(header_type), None);
    };
    let name_size = u16::from_le_bytes([fixed_block[26], fixed_block[27]]);
    let Some(name_end) = name_offset.checked_add(usize::from(name_size)) else {
        return ("invalid_header_size", Some(header_type), None);
    };
    let Some(required_min_size) = name_end.checked_add(if salt_present { 8 } else { 0 }) else {
        return ("invalid_header_size", Some(header_type), None);
    };
    if usize::from(header_size) < required_min_size {
        return ("invalid_header_size", Some(header_type), None);
    }
    let Some(block_end) = block_start.checked_add(usize::from(header_size)) else {
        return ("truncated_file_header", Some(header_type), None);
    };
    let Some(block) = data.get(block_start..block_end) else {
        return ("truncated_file_header", Some(header_type), None);
    };
    let calculated_crc = (crc32fast::hash(&block[2..]) & 0xffff) as u16;
    let checksum_valid = calculated_crc == header_crc;

    let pack_size_low = u32::from_le_bytes([block[7], block[8], block[9], block[10]]);
    let unp_size_low = u32::from_le_bytes([block[11], block[12], block[13], block[14]]);
    let host_os = block[15];
    let file_crc32 = u32::from_le_bytes([block[16], block[17], block[18], block[19]]);
    let ftime_dos = u32::from_le_bytes([block[20], block[21], block[22], block[23]]);
    let unpack_version = block[24];
    let method = block[25];
    let file_attributes = u32::from_le_bytes([block[28], block[29], block[30], block[31]]);

    let (
        high_pack_size,
        high_unp_size,
        compressed_size,
        uncompressed_size,
        uncompressed_size_unknown,
    ) = if large_file {
        let high_pack = u32::from_le_bytes([block[32], block[33], block[34], block[35]]);
        let high_unp = u32::from_le_bytes([block[36], block[37], block[38], block[39]]);
        let comp = (u64::from(high_pack) << 32) | u64::from(pack_size_low);
        let raw_unp = (u64::from(high_unp) << 32) | u64::from(unp_size_low);
        let unknown = raw_unp == u64::MAX;
        (
            Some(high_pack),
            Some(high_unp),
            comp,
            (!unknown).then_some(raw_unp),
            unknown,
        )
    } else {
        (
            None,
            None,
            u64::from(pack_size_low),
            Some(u64::from(unp_size_low)),
            false,
        )
    };

    let window_bits = (flags >> 5) & 0x07;
    let is_directory = window_bits == 7;
    let dictionary_size_bytes = (!is_directory).then_some(65_536u32 << window_bits);
    let unicode_filename = flags & 0x0200 != 0;

    let name_bytes = &block[name_offset..name_end];
    let nul_pos = name_bytes.iter().position(|&b| b == 0);
    let name_contains_nul = nul_pos.is_some();
    let name_nul_offset = nul_pos.map(|pos| pos as u16);
    let name_utf8_valid = !name_contains_nul && std::str::from_utf8(name_bytes).is_ok();

    let (name_encoding, filename, ascii_name_prefix) = if name_contains_nul {
        let prefix = &name_bytes[..nul_pos.unwrap()];
        let valid_ascii_prefix =
            !prefix.is_empty() && prefix.is_ascii() && !prefix.iter().any(u8::is_ascii_control);
        let prefix_str = valid_ascii_prefix.then(|| String::from_utf8_lossy(prefix).into_owned());
        if unicode_filename {
            ("rar4_unicode_split", None, prefix_str)
        } else {
            ("embedded_nul", None, prefix_str)
        }
    } else if unicode_filename {
        if let Ok(valid) = std::str::from_utf8(name_bytes) {
            let owned = valid.to_owned();
            let ascii_prefix = valid
                .is_ascii()
                .then(|| (!name_bytes.iter().any(u8::is_ascii_control)).then(|| owned.clone()))
                .flatten();
            ("utf8", Some(owned), ascii_prefix)
        } else {
            ("invalid_utf8", None, None)
        }
    } else if name_bytes.is_ascii() && !name_bytes.iter().any(u8::is_ascii_control) {
        let owned = String::from_utf8_lossy(name_bytes).into_owned();
        ("ascii", Some(owned.clone()), Some(owned))
    } else {
        ("oem_non_ascii", None, None)
    };

    let retained_name = &name_bytes[..name_bytes.len().min(MAX_RECORDED_ZIP_FIELD_HEX_BYTES)];
    let salt_hex = salt_present.then(|| hex(&block[name_end..name_end + 8]));

    let status = if checksum_valid {
        "parsed"
    } else {
        "checksum_invalid"
    };

    (
        status,
        Some(header_type),
        Some(Rar4FirstFileHeader {
            offset: block_start,
            header_crc,
            header_type,
            flags,
            header_size,
            header_complete: true,
            checksum_valid: Some(checksum_valid),
            data_offset: block_end,
            pack_size_low,
            unp_size_low,
            high_pack_size,
            high_unp_size,
            compressed_size,
            uncompressed_size,
            uncompressed_size_unknown,
            host_os,
            host_os_name: rar4_host_os_name(host_os),
            file_crc32,
            ftime_dos,
            unpack_version,
            method,
            method_name: rar4_method_name(method),
            name_size,
            file_attributes,
            split_before: flags & 0x0001 != 0,
            split_after: flags & 0x0002 != 0,
            encrypted: flags & 0x0004 != 0,
            comment_present: flags & 0x0008 != 0,
            solid: flags & 0x0010 != 0,
            is_directory,
            dictionary_size_bytes,
            large_file,
            unicode_filename,
            salt_present,
            version_flag: flags & 0x0800 != 0,
            exttime_present: flags & 0x1000 != 0,
            salt_hex,
            name_bytes_available: true,
            name_contains_nul,
            name_nul_offset,
            name_utf8_valid,
            name_encoding: Some(name_encoding),
            filename,
            ascii_name_prefix,
            name_preview_hex: Some(hex(retained_name)),
            name_preview_hex_truncated: name_bytes.len() > MAX_RECORDED_ZIP_FIELD_HEX_BYTES,
            name_sha256: Some(hex(&Sha256::digest(name_bytes))),
        }),
    )
}

fn rar4_host_os_name(host_os: u8) -> Option<&'static str> {
    match host_os {
        0 => Some("ms_dos"),
        1 => Some("os2"),
        2 => Some("win32"),
        3 => Some("unix"),
        4 => Some("mac_os"),
        5 => Some("beos"),
        _ => None,
    }
}

fn rar4_method_name(method: u8) -> Option<&'static str> {
    match method {
        0x30 => Some("store"),
        0x31 => Some("fastest"),
        0x32 => Some("fast"),
        0x33 => Some("normal"),
        0x34 => Some("good"),
        0x35 => Some("best"),
        _ => None,
    }
}

fn parse_zip(data: &[u8], offset: usize) -> FormatHeader {
    let fixed = offset.checked_add(30).and_then(|end| data.get(offset..end));
    let fixed_header_available = fixed.is_some_and(|header| header.get(..4) == Some(ZIP));
    let version_needed = fixed.map(|header| u16::from_le_bytes([header[4], header[5]]));
    let flags = fixed.map(|header| u16::from_le_bytes([header[6], header[7]]));
    let filename_length =
        fixed.map(|header| usize::from(u16::from_le_bytes([header[26], header[27]])));
    let extra_length =
        fixed.map(|header| usize::from(u16::from_le_bytes([header[28], header[29]])));
    let variable_start = offset.checked_add(30);
    let filename_range = variable_start
        .zip(filename_length)
        .and_then(|(start, length)| {
            start
                .checked_add(length)
                .and_then(|end| data.get(start..end))
        });
    let extra_range = variable_start
        .zip(filename_length)
        .zip(extra_length)
        .and_then(|((start, name_length), extra_length)| {
            let extra_start = start.checked_add(name_length)?;
            let extra_end = extra_start.checked_add(extra_length)?;
            data.get(extra_start..extra_end)
        });
    let (extra_field_ids, extra_field_count, malformed_extra_field_bytes) =
        parse_zip_extra_ids(extra_range.unwrap_or_default());
    let filename = filename_range.unwrap_or_default();
    let extra = extra_range.unwrap_or_default();
    let retained_filename = &filename[..filename.len().min(MAX_RECORDED_ZIP_FIELD_HEX_BYTES)];
    let retained_extra = &extra[..extra.len().min(MAX_RECORDED_ZIP_FIELD_HEX_BYTES)];
    let compressed_size =
        fixed.map(|header| u32::from_le_bytes(header[18..22].try_into().unwrap()));
    let uncompressed_size =
        fixed.map(|header| u32::from_le_bytes(header[22..26].try_into().unwrap()));
    let compression_method = fixed.map(|header| u16::from_le_bytes([header[8], header[9]]));
    let crc32 = fixed.map(|header| u32::from_le_bytes(header[14..18].try_into().unwrap()));
    let aes_metadata = parse_zip_aes_metadata(extra_range, compression_method, flags);
    let ZipDeflateEffortSummary {
        effective_compression_method,
        effective_compression_method_source,
        deflate_compression_option_bits,
        deflate_compression_option,
        deflate_effort_class,
        deflate_effort_status,
    } = zip_deflate_effort_metadata(flags, compression_method, &aes_metadata);
    let crc32_authority_status =
        zip_crc32_authority_status(flags, compression_method, crc32, &aes_metadata);
    let effective_crc32 = matches!(
        crc32_authority_status,
        "local_crc_authoritative" | "aes_ae1_crc_authoritative"
    )
    .then_some(crc32)
    .flatten();
    let zip64_size_metadata = parse_zip64_size_metadata(
        extra_range,
        uncompressed_size,
        compressed_size,
        version_needed,
        flags,
    );

    let ZipLocalObservations {
        local_header_available,
        structure_valid,
        flags,
        modification_time_dos,
        modification_date_dos,
        compressed_size,
        uncompressed_size,
        zip64_uncompressed_size,
        zip64_compressed_size,
        resolved_uncompressed_size,
        resolved_uncompressed_size_source,
        resolved_compressed_size,
        resolved_compressed_size_source,
        filename_bytes_sha256,
        filename_hex_truncated,
        filename_bytes_length,
        filename_utf8_flag_set,
        filename_encoding_hint,
        extra_field_bytes_length,
        extra_field_bytes_sha256,
        extra_field_prefix_hex,
        extra_field_hex_truncated,
        extra_field_records,
        extra_field_record_count,
        extra_field_records_truncated,
        extra_field_records_complete,
        zip64_extra_payload_length,
        data_descriptor_follows,
        zip64_extra_present,
        zip64_extra_record_count,
        zip64_extra_duplicate,
        zip64_extra_valid,
        zip64_size_sentinel_present,
        zip_aes_records,
        zip_aes_record_count,
        zip_aes_records_truncated,
        zip_aes_record_count_matches_encryption_indicators,
        zip_extended_timestamp_records,
        zip_extended_timestamp_record_count,
        zip_extended_timestamp_records_truncated,
        zip_unicode_path_records,
        zip_unicode_path_record_count,
        zip_unicode_path_records_truncated,
        ..
    } = read_zip_local_observations(data, offset, &zip64_size_metadata);
    let (local_filename_str, local_filename_display_truncated) = match filename_range {
        Some(bytes) => {
            let (s, trunc) =
                bounded_utf8_prefix_string(bytes, MAX_RETAINED_ZIP_FILENAME_DISPLAY_BYTES);
            (s, Some(trunc))
        }
        None => (None, None),
    };
    let (
        resolved_filename,
        resolved_filename_display_truncated,
        resolved_filename_source,
        resolved_filename_status,
    ) = resolve_zip_filename_authority(
        fixed_header_available,
        filename_length,
        filename_range,
        flags.map(|value| value & 0x0800 != 0),
        flags.map(|value| value & 0x2000 != 0),
        extra_field_records_complete,
        local_filename_str.as_ref(),
        local_filename_display_truncated,
        zip_unicode_path_records.as_deref(),
        zip_unicode_path_record_count,
    );
    FormatHeader::Zip {
        local_header_available,
        structure_valid,
        flags,
        modification_time_dos,
        modification_date_dos,
        compressed_size,
        uncompressed_size,
        zip64_uncompressed_size,
        zip64_compressed_size,
        resolved_uncompressed_size,
        resolved_uncompressed_size_source,
        resolved_compressed_size,
        resolved_compressed_size_source,
        filename_bytes_sha256,
        filename_hex_truncated,
        filename_bytes_length,
        filename_utf8_flag_set,
        filename_encoding_hint,
        extra_field_bytes_length,
        extra_field_bytes_sha256,
        extra_field_prefix_hex,
        extra_field_hex_truncated,
        extra_field_records,
        extra_field_record_count,
        extra_field_records_truncated,
        extra_field_records_complete,
        zip64_extra_payload_length,
        data_descriptor_follows,
        zip64_extra_present,
        zip64_extra_record_count,
        zip64_extra_duplicate,
        zip64_extra_valid,
        zip64_size_sentinel_present,
        zip_aes_records,
        zip_aes_record_count,
        zip_aes_records_truncated,
        zip_aes_record_count_matches_encryption_indicators,
        zip_extended_timestamp_records,
        zip_extended_timestamp_record_count,
        zip_extended_timestamp_records_truncated,
        zip_unicode_path_records,
        zip_unicode_path_record_count,
        zip_unicode_path_records_truncated,
        filename: local_filename_str,
        filename_display_truncated: local_filename_display_truncated,
        resolved_filename,
        resolved_filename_display_truncated,
        resolved_filename_source,
        resolved_filename_status,
        offset,
        local_file_header_signature: true,
        fixed_header_available,
        version_needed,
        general_purpose_flags: flags,
        encrypted: flags.map(|value| value & 0x0001 != 0),
        data_descriptor_present: flags.map(|value| value & 0x0008 != 0),
        filename_utf8_flag: flags.map(|value| value & 0x0800 != 0),
        compression_method,
        effective_compression_method,
        effective_compression_method_source,
        deflate_compression_option_bits,
        deflate_compression_option,
        deflate_effort_class,
        deflate_effort_status,
        dos_modification_time: fixed.map(|header| u16::from_le_bytes([header[10], header[11]])),
        dos_modification_date: fixed.map(|header| u16::from_le_bytes([header[12], header[13]])),
        crc32,
        crc32_authority_status,
        effective_crc32,
        aes_metadata,
        compressed_size_32: compressed_size,
        uncompressed_size_32: uncompressed_size,
        zip64_size_sentinels_present: compressed_size
            .zip(uncompressed_size)
            .map(|(compressed, uncompressed)| compressed == u32::MAX || uncompressed == u32::MAX),
        filename_declared_byte_length: filename_length,
        filename_byte_length: filename_range.map(<[u8]>::len),
        filename_complete: filename_length.map(|_| filename_range.is_some()),
        filename_sha256: filename_range.map(|bytes| hex(&Sha256::digest(bytes))),
        filename_bytes_hex: filename_range.map(|_| hex(retained_filename)),
        filename_bytes_hex_truncated: filename.len() > MAX_RECORDED_ZIP_FIELD_HEX_BYTES,
        filename_utf8_valid: filename_range.map(|bytes| std::str::from_utf8(bytes).is_ok()),
        extra_field_declared_byte_length: extra_length,
        extra_field_byte_length: extra_range.map(<[u8]>::len),
        extra_field_complete: extra_length.map(|_| extra_range.is_some()),
        extra_field_sha256: extra_range.map(|bytes| hex(&Sha256::digest(bytes))),
        extra_field_bytes_hex: extra_range.map(|_| hex(retained_extra)),
        extra_field_bytes_hex_truncated: extra.len() > MAX_RECORDED_ZIP_FIELD_HEX_BYTES,
        extra_field_ids,
        extra_field_count,
        malformed_extra_field_bytes,
        extra_field_ids_truncated: extra_field_count > MAX_RECORDED_ZIP_EXTRA_FIELD_IDS,
        zip64_size_metadata,
    }
}

fn bounded_utf8_prefix_string(bytes: &[u8], max_bytes: usize) -> (Option<String>, bool) {
    let Ok(full_str) = std::str::from_utf8(bytes) else {
        return (None, bytes.len() > max_bytes);
    };
    if full_str.len() <= max_bytes {
        return (Some(full_str.to_owned()), false);
    }
    let mut end = max_bytes;
    while end > 0 && !full_str.is_char_boundary(end) {
        end -= 1;
    }
    (Some(full_str[..end].to_owned()), true)
}

#[allow(clippy::too_many_arguments)]
fn resolve_zip_filename_authority(
    fixed_header_available: bool,
    filename_length: Option<usize>,
    filename_range: Option<&[u8]>,
    efs_utf8_flag: Option<bool>,
    local_header_masked: Option<bool>,
    extra_fields_fully_inspected: Option<bool>,
    local_filename_str: Option<&String>,
    local_filename_display_truncated: Option<bool>,
    unicode_path_records: Option<&[ZipUnicodePathExtra]>,
    unicode_path_record_count: Option<usize>,
) -> (
    Option<String>,
    Option<bool>,
    Option<&'static str>,
    &'static str,
) {
    if !fixed_header_available {
        return (None, None, None, "fixed_header_incomplete");
    }
    if filename_length.is_some() && filename_range.is_none() {
        return (None, None, None, "local_filename_truncated");
    }
    let Some(filename_bytes) = filename_range else {
        return (None, None, None, "fixed_header_incomplete");
    };
    // General-purpose bit 13 (central directory encryption) replaces local header
    // fields with masking tokens, so the observed name is not the real filename.
    // The raw bytes stay available through the existing hash/hex observations.
    if local_header_masked != Some(false) {
        return (None, None, None, "local_header_masked");
    }
    // An unseen or unframed extra area can still hold a 0x7075 override or a duplicate
    // of one, so authority needs a complete extra-field inspection.
    if extra_fields_fully_inspected != Some(true) {
        return (None, None, None, "extra_fields_incomplete");
    }
    // Readers truncate at an embedded NUL (`visible.txt\0hidden.mkv` is `visible.txt`),
    // so the full byte string is not a filename any reader agrees on. The raw bytes
    // stay available through the existing observations.
    if filename_bytes.contains(&0) {
        return (None, None, None, "local_filename_contains_nul");
    }
    if filename_bytes.is_empty() {
        return (Some(String::new()), Some(false), None, "empty_filename");
    }

    // Common ZIP forbids duplicate 0x7075 tags and readers disagree on first/last
    // handling, so any second Unicode Path record, usable or not, withholds
    // authority. Count every complete record, including ones beyond the retained cap.
    let records = unicode_path_records.unwrap_or_default();
    let total_records = unicode_path_record_count.unwrap_or(0).max(records.len());
    if total_records > 1 {
        return (None, None, None, "unicode_path_extra_ambiguous");
    }
    if let Some(first) = records
        .iter()
        .find(|record| record.usable_as_filename_override)
    {
        if efs_utf8_flag == Some(true) && first.unicode_filename_matches_efs_filename == Some(true)
        {
            return (
                local_filename_str.cloned(),
                local_filename_display_truncated,
                Some("local_header_utf8_confirmed_by_unicode_path_extra"),
                "resolved",
            );
        }
        return (
            first.unicode_filename.clone(),
            first.unicode_filename_display_truncated,
            Some("unicode_path_extra"),
            "resolved",
        );
    }

    if efs_utf8_flag == Some(true) {
        if let Some(name) = local_filename_str {
            return (
                Some(name.clone()),
                local_filename_display_truncated,
                Some("local_header_utf8_flag"),
                "resolved",
            );
        }
        return (None, None, None, "utf8_flag_set_but_invalid_utf8");
    }

    if filename_bytes.is_ascii() {
        return (
            local_filename_str.cloned(),
            local_filename_display_truncated,
            Some("local_header_ascii"),
            "resolved",
        );
    }

    if local_filename_str.is_some() {
        return (
            local_filename_str.cloned(),
            local_filename_display_truncated,
            Some("local_header_utf8_unflagged"),
            "resolved_unflagged_utf8",
        );
    }

    (
        None,
        Some(filename_bytes.len() > MAX_RETAINED_ZIP_FILENAME_DISPLAY_BYTES),
        None,
        "legacy_non_utf8_without_unicode_path_extra",
    )
}

fn parse_zip_aes_metadata(
    extra: Option<&[u8]>,
    compression_method: Option<u16>,
    flags: Option<u16>,
) -> ZipAesMetadata {
    let mut cursor = 0usize;
    let mut payload = None;
    let mut extra_field_count = 0usize;
    let mut extra_framing_valid = true;

    if let Some(extra) = extra {
        while cursor < extra.len() {
            let Some(header_end) = cursor.checked_add(4) else {
                extra_framing_valid = false;
                break;
            };
            let Some(field_header) = extra.get(cursor..header_end) else {
                extra_framing_valid = false;
                break;
            };
            let field_id = u16::from_le_bytes([field_header[0], field_header[1]]);
            let field_length = usize::from(u16::from_le_bytes([field_header[2], field_header[3]]));
            let Some(field_end) = header_end.checked_add(field_length) else {
                extra_framing_valid = false;
                break;
            };
            let Some(field_payload) = extra.get(header_end..field_end) else {
                extra_framing_valid = false;
                break;
            };
            if field_id == 0x9901 {
                extra_field_count = extra_field_count.saturating_add(1);
                if payload.is_none() {
                    payload = Some(field_payload);
                }
            }
            cursor = field_end;
        }
    }

    let vendor_version = payload
        .and_then(|bytes| bytes.get(..2))
        .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]]));
    let vendor_id_hex = payload.and_then(|bytes| bytes.get(2..4)).map(hex);
    let strength = payload.and_then(|bytes| bytes.get(4)).copied();
    let actual_compression_method = payload
        .and_then(|bytes| bytes.get(5..7))
        .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]]));
    let payload_extension = payload.and_then(|bytes| bytes.get(7..));

    let status = if extra.is_none() {
        "extra_fields_unavailable"
    } else if !extra_framing_valid {
        "extra_framing_malformed"
    } else if extra_field_count > 1 {
        "duplicate_ambiguous"
    } else if compression_method != Some(99) {
        if extra_field_count == 0 {
            "not_applicable"
        } else {
            "unexpected_aes_extra_field"
        }
    } else if extra_field_count == 0 {
        "missing_required_extra"
    } else if payload.is_none_or(|bytes| bytes.len() < 7) {
        "payload_truncated"
    } else if vendor_id_hex.as_deref() != Some("4145") {
        "invalid_vendor_id"
    } else if !matches!(vendor_version, Some(1 | 2)) {
        "unsupported_version"
    } else if !matches!(strength, Some(1..=3)) {
        "unsupported_strength"
    } else if flags.is_none() {
        "encryption_flag_unavailable"
    } else if flags.is_some_and(|value| value & 0x0001 == 0) {
        "encryption_flag_missing"
    } else {
        "valid"
    };

    ZipAesMetadata {
        status,
        extra_field_count,
        payload_byte_length: payload.map(<[u8]>::len),
        vendor_version,
        vendor_id_hex,
        strength,
        actual_compression_method,
        payload_extension_byte_length: payload_extension.map(<[u8]>::len),
        payload_extension_sha256: payload_extension.map(|bytes| hex(&Sha256::digest(bytes))),
    }
}

fn zip_crc32_authority_status(
    flags: Option<u16>,
    compression_method: Option<u16>,
    crc32: Option<u32>,
    aes_metadata: &ZipAesMetadata,
) -> &'static str {
    let Some(flags) = flags else {
        return "local_header_incomplete";
    };
    let Some(crc32) = crc32 else {
        return "local_header_incomplete";
    };
    if flags & 0x0008 != 0 {
        return "data_descriptor_deferred";
    }
    if flags & 0x2000 != 0 {
        return "masked_local_metadata";
    }
    if compression_method == Some(99) {
        match aes_metadata.status {
            "missing_required_extra" => return "aes_metadata_missing",
            "valid" => {}
            _ => return "aes_metadata_invalid",
        }
        return match aes_metadata.vendor_version {
            Some(1) => "aes_ae1_crc_authoritative",
            Some(2) if crc32 == 0 => "aes_ae2_crc_not_stored",
            Some(2) => "aes_ae2_crc_nonzero",
            _ => "aes_metadata_invalid",
        };
    }
    if aes_metadata.extra_field_count > 0 {
        return "unexpected_aes_extra_field";
    }
    "local_crc_authoritative"
}

struct ZipDeflateEffortSummary {
    effective_compression_method: Option<u16>,
    effective_compression_method_source: Option<&'static str>,
    deflate_compression_option_bits: Option<u8>,
    deflate_compression_option: Option<&'static str>,
    deflate_effort_class: Option<&'static str>,
    deflate_effort_status: &'static str,
}

impl ZipDeflateEffortSummary {
    const fn unresolved(status: &'static str) -> Self {
        Self {
            effective_compression_method: None,
            effective_compression_method_source: None,
            deflate_compression_option_bits: None,
            deflate_compression_option: None,
            deflate_effort_class: None,
            deflate_effort_status: status,
        }
    }
}

fn zip_deflate_effort_metadata(
    flags: Option<u16>,
    compression_method: Option<u16>,
    aes_metadata: &ZipAesMetadata,
) -> ZipDeflateEffortSummary {
    let (Some(flags), Some(method)) = (flags, compression_method) else {
        return ZipDeflateEffortSummary::unresolved("local_header_incomplete");
    };
    if flags & 0x2000 != 0 {
        return ZipDeflateEffortSummary::unresolved("masked_local_metadata");
    }
    let (effective_method, effective_source) = if method == 99 {
        match (aes_metadata.status, aes_metadata.actual_compression_method) {
            ("valid", Some(actual)) => (actual, "aes_extra_field"),
            ("missing_required_extra", _) => {
                return ZipDeflateEffortSummary::unresolved("aes_metadata_missing");
            }
            _ => return ZipDeflateEffortSummary::unresolved("aes_metadata_invalid"),
        }
    } else if aes_metadata.extra_field_count > 0 {
        return ZipDeflateEffortSummary::unresolved("unexpected_aes_extra_field");
    } else {
        (method, "local_header")
    };

    let status_when_resolved = if effective_source == "aes_extra_field" {
        "resolved_via_aes_extra_field"
    } else {
        "resolved_local_header"
    };

    if !matches!(effective_method, 8 | 9) {
        return ZipDeflateEffortSummary {
            effective_compression_method: Some(effective_method),
            effective_compression_method_source: Some(effective_source),
            deflate_compression_option_bits: None,
            deflate_compression_option: None,
            deflate_effort_class: None,
            deflate_effort_status: "not_deflate",
        };
    }

    let option_bits = ((flags >> 1) & 0x03) as u8;
    let (option_name, effort_class) = match option_bits {
        0 => ("normal", "normal"),
        1 => ("maximum", "maximum"),
        2 => ("fast", "fast"),
        3 => ("super_fast", "fast"),
        _ => unreachable!(),
    };
    ZipDeflateEffortSummary {
        effective_compression_method: Some(effective_method),
        effective_compression_method_source: Some(effective_source),
        deflate_compression_option_bits: Some(option_bits),
        deflate_compression_option: Some(option_name),
        deflate_effort_class: Some(effort_class),
        deflate_effort_status: status_when_resolved,
    }
}

fn parse_zip64_size_metadata(
    extra: Option<&[u8]>,
    uncompressed_size_32: Option<u32>,
    compressed_size_32: Option<u32>,
    version_needed: Option<u16>,
    flags: Option<u16>,
) -> Zip64SizeMetadata {
    let uncompressed_sentinel = uncompressed_size_32 == Some(u32::MAX);
    let compressed_sentinel = compressed_size_32 == Some(u32::MAX);
    let size_sentinel_present = uncompressed_sentinel || compressed_sentinel;
    let mut cursor = 0usize;
    let mut zip64_payload = None;
    let mut zip64_extra_field_count = 0usize;
    let mut malformed_extra_data = false;

    if let Some(extra) = extra {
        while cursor < extra.len() {
            let Some(header_end) = cursor.checked_add(4) else {
                malformed_extra_data = true;
                break;
            };
            let Some(field_header) = extra.get(cursor..header_end) else {
                malformed_extra_data = true;
                break;
            };
            let field_id = u16::from_le_bytes([field_header[0], field_header[1]]);
            let field_length = usize::from(u16::from_le_bytes([field_header[2], field_header[3]]));
            let Some(field_end) = header_end.checked_add(field_length) else {
                malformed_extra_data = true;
                break;
            };
            let Some(payload) = extra.get(header_end..field_end) else {
                malformed_extra_data = true;
                break;
            };
            if field_id == 0x0001 {
                zip64_extra_field_count = zip64_extra_field_count.saturating_add(1);
                if zip64_payload.is_none() {
                    zip64_payload = Some(payload);
                }
            }
            cursor = field_end;
        }
    }

    let payload = zip64_payload;
    let (observed_uncompressed_size, observed_compressed_size) = payload
        .filter(|payload| payload.len() >= 16)
        .map_or((None, None), |payload| {
            let uncompressed =
                u64::from_le_bytes(payload[..8].try_into().expect("eight-byte size"));
            let compressed =
                u64::from_le_bytes(payload[8..16].try_into().expect("eight-byte size"));
            (Some(uncompressed.to_string()), Some(compressed.to_string()))
        });
    let status = if extra.is_none() {
        "extra-fields-unavailable"
    } else if zip64_extra_field_count > 1 {
        "duplicate-ambiguous"
    } else if zip64_extra_field_count == 0 && malformed_extra_data {
        "extra-framing-malformed"
    } else if flags.is_some_and(|value| value & 0x0008 != 0) {
        "descriptor-deferred"
    } else if flags.is_some_and(|value| value & 0x2000 != 0) {
        "masked-local-metadata"
    } else if zip64_extra_field_count == 0 && size_sentinel_present {
        "missing-required-zip64-extra"
    } else if zip64_extra_field_count == 0 {
        "not-required"
    } else if payload.is_none_or(|payload| payload.len() < 16) {
        "malformed-local-zip64-sizes"
    } else if malformed_extra_data {
        "zip64-observed-extra-framing-malformed"
    } else if version_needed.is_some_and(|version| version < 45) {
        "version-needed-below-45"
    } else if !size_sentinel_present {
        "present-without-size-sentinel"
    } else {
        "observed"
    };
    let size_authority_available = status == "observed" || status == "not-required";
    let effective_uncompressed_size = size_authority_available
        .then(|| {
            if uncompressed_sentinel {
                observed_uncompressed_size.clone()
            } else {
                uncompressed_size_32.map(|value| value.to_string())
            }
        })
        .flatten();
    let effective_compressed_size = size_authority_available
        .then(|| {
            if compressed_sentinel {
                observed_compressed_size.clone()
            } else {
                compressed_size_32.map(|value| value.to_string())
            }
        })
        .flatten();
    let effective_size_basis = if flags.is_some_and(|value| value & 0x0008 != 0) {
        "data-descriptor-signaled"
    } else if flags.is_some_and(|value| value & 0x2000 != 0) {
        "masked-local-metadata"
    } else if uncompressed_sentinel || compressed_sentinel {
        "zip64-extra-field"
    } else {
        "local-header-32-bit-fields"
    };

    Zip64SizeMetadata {
        status,
        extra_field_count: zip64_extra_field_count,
        payload_byte_length: payload.map(<[u8]>::len),
        observed_uncompressed_size,
        observed_compressed_size,
        effective_uncompressed_size,
        effective_compressed_size,
        effective_size_basis,
    }
}

fn parse_zip_extra_ids(extra: &[u8]) -> (Vec<u16>, usize, usize) {
    let mut ids = Vec::new();
    let mut count = 0;
    let mut cursor = 0;
    while cursor < extra.len() {
        let Some(header_end) = cursor.checked_add(4) else {
            return (ids, count, extra.len() - cursor);
        };
        let Some(field_header) = extra.get(cursor..header_end) else {
            return (ids, count, extra.len() - cursor);
        };
        let field_id = u16::from_le_bytes([field_header[0], field_header[1]]);
        let field_length = usize::from(u16::from_le_bytes([field_header[2], field_header[3]]));
        let Some(field_end) = header_end.checked_add(field_length) else {
            return (ids, count, extra.len() - cursor);
        };
        if field_end > extra.len() {
            return (ids, count, extra.len() - cursor);
        }
        count += 1;
        if ids.len() < MAX_RECORDED_ZIP_EXTRA_FIELD_IDS {
            ids.push(field_id);
        }
        cursor = field_end;
    }
    (ids, count, 0)
}

fn parse_rar5(
    data: &[u8],
    offset: usize,
    quick_open_budget: &mut rar5_quick_open::Budget,
) -> FormatHeader {
    let block_start = offset + RAR5.len();
    let Some(crc_bytes) = data.get(block_start..block_start + 4) else {
        return empty_rar5(offset);
    };
    let expected_crc = u32::from_le_bytes(crc_bytes.try_into().expect("four-byte CRC"));
    let mut cursor = block_start + 4;
    let Some((header_size, header_size_vint_byte_length)) =
        read_vint_with_length(data, &mut cursor)
    else {
        return empty_rar5(offset);
    };
    let header_size_encoding_valid = Some(header_size_vint_byte_length <= 3);
    let Ok(header_size_usize) = usize::try_from(header_size) else {
        return empty_rar5(offset);
    };
    let header_end = match cursor.checked_add(header_size_usize) {
        Some(end) if end <= data.len() => end,
        _ => return empty_rar5(offset),
    };
    let calculated_crc = crc32fast::hash(&data[block_start + 4..header_end]);
    let Some(block_type) = read_vint_bounded(data, &mut cursor, header_end) else {
        return empty_rar5(offset);
    };
    let Some(block_flags) = read_vint_bounded(data, &mut cursor, header_end) else {
        return empty_rar5(offset);
    };
    let extra_size = if block_flags & 0x01 != 0 {
        read_vint_bounded(data, &mut cursor, header_end)
    } else {
        Some(0)
    };
    let data_size = if block_flags & 0x02 != 0 {
        read_vint_bounded(data, &mut cursor, header_end)
    } else {
        Some(0)
    };
    let mut main_archive_flags = None;
    let mut main_volume_number = None;
    let mut algorithm_version = None;
    let mut encryption_flags = None;
    let mut kdf_count = None;
    let mut salt = None;
    let mut encryption_iv = None;
    let mut password_check_data = None;
    let mut password_check_trailer_checksum_expected = None;
    let mut password_check_trailer_checksum_calculated = None;
    let mut password_check_trailer_checksum_valid = None;
    let mut typed_header_complete =
        header_size_encoding_valid == Some(true) && extra_size.is_some() && data_size.is_some();
    let extra_start_candidate = extra_size
        .and_then(|size| usize::try_from(size).ok())
        .and_then(|size| header_end.checked_sub(size));
    let extra_area_range_valid =
        extra_size.map(|_| extra_start_candidate.is_some_and(|extra_start| extra_start >= cursor));
    let extra_start = extra_start_candidate.filter(|extra_start| *extra_start >= cursor);
    let main_fields_end = extra_start.unwrap_or(cursor);
    typed_header_complete &= extra_start.is_some();

    match block_type {
        1 => {
            main_archive_flags = read_vint_bounded(data, &mut cursor, main_fields_end);
            typed_header_complete &= main_archive_flags.is_some();
            if main_archive_flags.is_some_and(|flags| flags & 0x02 != 0) {
                main_volume_number = read_vint_bounded(data, &mut cursor, main_fields_end);
                typed_header_complete &= main_volume_number.is_some();
            }
        }
        4 => {
            algorithm_version = read_vint_bounded(data, &mut cursor, main_fields_end);
            encryption_flags = read_vint_bounded(data, &mut cursor, main_fields_end);
            if cursor < main_fields_end {
                kdf_count = data.get(cursor).copied();
                cursor = cursor.saturating_add(1);
            }
            if cursor.saturating_add(16) <= main_fields_end {
                salt = Some(hex(&data[cursor..cursor + 16]));
                cursor += 16;
            }
            if encryption_flags.is_some_and(|flags| flags & 0x01 != 0)
                && cursor.saturating_add(12) <= main_fields_end
            {
                let check_data = &data[cursor..cursor + 12];
                password_check_data = Some(hex(check_data));
                if algorithm_version == Some(0) {
                    let digest = Sha256::digest(&check_data[..8]);
                    let expected = &check_data[8..12];
                    let calculated = &digest[..4];
                    password_check_trailer_checksum_expected = Some(hex(expected));
                    password_check_trailer_checksum_calculated = Some(hex(calculated));
                    password_check_trailer_checksum_valid = Some(expected == calculated);
                }
                cursor += 12;
            }
            typed_header_complete &= cursor <= main_fields_end
                && algorithm_version.is_some()
                && encryption_flags.is_some()
                && kdf_count.is_some()
                && salt.is_some()
                && (encryption_flags.is_none_or(|flags| flags & 0x01 == 0)
                    || password_check_data.is_some());
        }
        _ => {}
    }
    let calculated_crc_is_valid = expected_crc == calculated_crc;
    let encryption_iv_start =
        (block_type == 4 && typed_header_complete && algorithm_version == Some(0))
            .then(|| {
                data_size
                    .and_then(|size| usize::try_from(size).ok())
                    .and_then(|size| header_end.checked_add(size))
            })
            .flatten();
    let encryption_iv_available = encryption_iv_start.and_then(|start| {
        start
            .checked_add(16)
            .map(|end| data.get(start..end).is_some())
    });
    let encryption_iv_status = if block_type != 4 {
        "not_encryption_header"
    } else if !typed_header_complete {
        "encryption_header_incomplete"
    } else if algorithm_version != Some(0) {
        "encryption_version_unsupported"
    } else if encryption_iv_available == Some(true) {
        "available"
    } else {
        "truncated"
    };
    if encryption_iv_available == Some(true) {
        encryption_iv = encryption_iv_start
            .and_then(|start| start.checked_add(16).and_then(|end| data.get(start..end)))
            .map(hex);
    }

    let main_extra_records = if block_type == 1 {
        extra_start
            .filter(|start| *start >= cursor)
            .map(|start| Box::new(parse_rar5_main_extra_records(data, start, header_end)))
    } else {
        None
    };
    let visible_block_walk = (block_type == 1).then(|| {
        read_rar5_visible_block_walk(
            data,
            block_start,
            calculated_crc_is_valid && typed_header_complete,
            main_extra_records.as_deref(),
            quick_open_budget,
        )
    });

    let (
        main_extra_record_types,
        main_locator_records,
        main_archive_metadata_records,
        legacy_extra_complete,
    ) = if block_type == 1 {
        extra_start
            .map(|start| read_rar5_main_extra_observations(data, start, header_end, block_start))
            .unwrap_or_default()
    } else {
        (Vec::new(), Vec::new(), Vec::new(), extra_start.is_some())
    };
    let main_extra_records_complete = legacy_extra_complete && typed_header_complete;
    FormatHeader::Rar5 {
        first_block_header_size_vint_bytes: u8::try_from(header_size_vint_byte_length).ok(),
        first_block_extra_size: extra_size,
        main_extra_record_types,
        main_extra_records_complete,
        main_locator_records,
        main_archive_metadata_records,
        offset,
        first_block_header_available: typed_header_complete,
        first_block_header_checksum_valid: Some(calculated_crc_is_valid),
        first_block_type: Some(block_type),
        first_block_flags: Some(block_flags),
        first_block_data_area_present: Some(block_flags & 0x02 != 0),
        first_block_data_size: (block_flags & 0x02 != 0).then_some(data_size).flatten(),
        extra_area_range_valid,
        main_archive_flags,
        main_is_volume: main_archive_flags.map(|flags| flags & 0x01 != 0),
        main_is_solid: main_archive_flags.map(|flags| flags & 0x04 != 0),
        main_has_recovery_record: main_archive_flags.map(|flags| flags & 0x08 != 0),
        main_is_locked: main_archive_flags.map(|flags| flags & 0x10 != 0),
        main_volume_number,
        encryption_algorithm_version: algorithm_version,
        encryption_flags,
        kdf_count_log2: kdf_count,
        kdf_iteration_count: kdf_count.and_then(|count| 1u64.checked_shl(u32::from(count))),
        header_encryption_salt_hex: salt,
        header_encryption_iv_hex: encryption_iv,
        header_encryption_iv_available: encryption_iv_available,
        header_encryption_iv_status: encryption_iv_status,
        password_check_present: encryption_flags.map(|flags| flags & 0x01 != 0),
        password_check_data_hex: password_check_data,
        password_check_trailer_checksum_expected_hex: password_check_trailer_checksum_expected,
        password_check_trailer_checksum_calculated_hex: password_check_trailer_checksum_calculated,
        password_check_trailer_checksum_valid,
        first_block_header_size_vint_byte_length: Some(header_size_vint_byte_length),
        first_block_header_size_encoding_valid: header_size_encoding_valid,
        first_block_header_size: Some(header_size),
        main_extra_records,
        visible_block_walk,
    }
}

fn empty_rar5(offset: usize) -> FormatHeader {
    FormatHeader::Rar5 {
        first_block_header_size_vint_bytes: None,
        first_block_extra_size: None,
        main_extra_record_types: Vec::new(),
        main_extra_records_complete: false,
        main_locator_records: Vec::new(),
        main_archive_metadata_records: Vec::new(),
        offset,
        first_block_header_available: false,
        first_block_header_checksum_valid: None,
        first_block_type: None,
        first_block_flags: None,
        first_block_data_area_present: None,
        first_block_data_size: None,
        extra_area_range_valid: None,
        main_archive_flags: None,
        main_is_volume: None,
        main_is_solid: None,
        main_has_recovery_record: None,
        main_is_locked: None,
        main_volume_number: None,
        encryption_algorithm_version: None,
        encryption_flags: None,
        kdf_count_log2: None,
        kdf_iteration_count: None,
        header_encryption_salt_hex: None,
        header_encryption_iv_hex: None,
        header_encryption_iv_available: None,
        header_encryption_iv_status: "header_unavailable",
        password_check_present: None,
        password_check_data_hex: None,
        password_check_trailer_checksum_expected_hex: None,
        password_check_trailer_checksum_calculated_hex: None,
        password_check_trailer_checksum_valid: None,
        first_block_header_size_vint_byte_length: None,
        first_block_header_size_encoding_valid: None,
        first_block_header_size: None,
        main_extra_records: None,
        visible_block_walk: None,
    }
}

const MAX_RAR5_VISIBLE_BLOCKS: usize = 256;
const MAX_RAR5_FILENAME_RETAIN_BYTES: usize = 4096;
const RAR5_FILENAME_PREVIEW_BYTES: usize = 128;

struct Rar5BlockDescriptor {
    header_end: usize,
    header_type: u64,
    data_end: usize,
    service_name: Option<Vec<u8>>,
}

fn read_rar5_visible_block_walk(
    data: &[u8],
    main_header_start: usize,
    main_header_trusted: bool,
    main_extra_records: Option<&Rar5MainExtraRecords>,
    quick_open_budget: &mut rar5_quick_open::Budget,
) -> Rar5VisibleBlockWalk {
    let (quick_open_offset, recovery_record_offset, locator_status) = match main_extra_records {
        None => (None, None, "locator_unavailable"),
        Some(records) if records.locator_records_ambiguous => (None, None, "locator_ambiguous"),
        Some(records) if !records.available || records.malformed_record_count > 0 => {
            (None, None, "locator_malformed")
        }
        Some(records) if records.locator_record_count == 0 => (None, None, "locator_unavailable"),
        Some(records) => (
            records.quick_open_offset,
            records.recovery_record_offset,
            "locator_offsets_available",
        ),
    };
    let quick_open =
        make_rar5_locator_resolution(quick_open_offset, main_header_start, locator_status, "QO");
    let recovery_record = make_rar5_locator_resolution(
        recovery_record_offset,
        main_header_start,
        locator_status,
        "RR",
    );
    let mut walk = Rar5VisibleBlockWalk {
        status: "pending",
        coordinate_basis: "Decoded sample-relative byte offsets; locator target is main-header CRC32 field offset plus locator distance",
        start_offset: main_header_start,
        inspected_block_count: 0,
        end_of_archive_seen: false,
        stopped_offset: None,
        trailing_sample_bytes: 0,
        blocks: Vec::new(),
        quick_open,
        recovery_record,
    };
    if !main_header_trusted {
        walk.status = "main_header_untrusted";
        walk.stopped_offset = Some(main_header_start);
        for locator in [&mut walk.quick_open, &mut walk.recovery_record] {
            locator.status = "main_header_untrusted";
            locator.relative_offset = None;
            locator.target_sample_offset = None;
        }
        return walk;
    }

    let mut cursor = main_header_start;
    for block_index in 0..MAX_RAR5_VISIBLE_BLOCKS {
        if cursor == data.len() {
            walk.status = "sample_end";
            walk.stopped_offset = Some(cursor);
            break;
        }
        let (mut observation, descriptor, status) =
            read_rar5_block_observation(data, cursor, block_index);
        if observation.header_type == Some(3)
            && observation.service_name_bytes_hex.as_deref() == Some("514f")
        {
            observation.quick_open_cache = Some(rar5_quick_open::parse(
                data,
                &observation,
                &walk.blocks,
                quick_open_budget,
            ));
        }
        walk.inspected_block_count += 1;
        walk.blocks.push(observation);
        let Some(block) = descriptor else {
            walk.status = status;
            walk.stopped_offset = Some(cursor);
            break;
        };
        let current = walk.blocks.last().expect("just pushed RAR5 block");
        resolve_rar5_locator_target(&mut walk.quick_open, current, &block);
        resolve_rar5_locator_target(&mut walk.recovery_record, current, &block);

        if current.header_crc32_valid != Some(true) {
            walk.status = "header_checksum_invalid";
            walk.stopped_offset = Some(cursor);
            break;
        }
        if block.header_type == 4 {
            walk.status = "header_encryption_encountered";
            walk.stopped_offset = Some(cursor);
            break;
        }
        if block.header_type == 5 {
            if block.data_end > data.len() {
                walk.status = "data_truncated";
                walk.stopped_offset = Some(cursor);
                break;
            }
            walk.status = "end_of_archive";
            walk.end_of_archive_seen = true;
            walk.stopped_offset = Some(cursor);
            walk.trailing_sample_bytes = data.len().saturating_sub(block.data_end);
            break;
        }
        if block.data_end > data.len() {
            walk.status = "data_truncated";
            walk.stopped_offset = Some(cursor);
            break;
        }
        if block.data_end <= cursor {
            walk.status = "non_advancing_block";
            walk.stopped_offset = Some(cursor);
            break;
        }
        cursor = block.data_end;
        if block_index + 1 == MAX_RAR5_VISIBLE_BLOCKS {
            walk.status = "block_budget_exhausted";
            walk.stopped_offset = Some(cursor);
        }
    }
    if walk.status == "pending" {
        walk.status = "block_budget_exhausted";
        walk.stopped_offset = Some(cursor);
    }
    finish_rar5_locator_status(&mut walk.quick_open, walk.status, data.len());
    finish_rar5_locator_status(&mut walk.recovery_record, walk.status, data.len());
    walk
}

fn make_rar5_locator_resolution(
    relative_offset: Option<u64>,
    main_header_start: usize,
    locator_status: &'static str,
    expected_name: &'static str,
) -> Rar5LocatorResolution {
    let target_sample_offset = relative_offset
        .filter(|offset| *offset != 0)
        .and_then(|offset| usize::try_from(offset).ok())
        .and_then(|offset| main_header_start.checked_add(offset));
    let status = if locator_status != "locator_offsets_available" {
        locator_status
    } else if relative_offset.is_none() {
        "locator_offset_missing_or_zero"
    } else if target_sample_offset.is_none() {
        "locator_target_offset_overflow"
    } else {
        "target_unresolved"
    };
    let _ = expected_name;
    Rar5LocatorResolution {
        expected_service_name: expected_name,
        relative_offset,
        target_sample_offset,
        status,
        matched_block_index: None,
        target_type: None,
        target_service_name_bytes_hex: None,
        target_header_crc32_valid: None,
        target_data_range_available: None,
    }
}

fn resolve_rar5_locator_target(
    resolution: &mut Rar5LocatorResolution,
    observation: &Rar5VisibleBlock,
    block: &Rar5BlockDescriptor,
) {
    let block_index = observation.index;
    let block_start = observation.header_start_offset;
    let header_end = block.header_end;
    let data_end = block.data_end;
    let header_type = block.header_type;
    let service_name = block.service_name.as_deref();
    let header_crc_valid = observation.header_crc32_valid;
    let Some(target) = resolution.target_sample_offset else {
        return;
    };
    if target == block_start {
        resolution.matched_block_index = Some(block_index);
        resolution.target_type = Some(header_type);
        resolution.target_service_name_bytes_hex = observation.service_name_bytes_hex.clone();
        resolution.target_header_crc32_valid = header_crc_valid;
        resolution.target_data_range_available = observation.data_range_available;
        resolution.status = if header_crc_valid != Some(true) {
            "target_header_checksum_invalid"
        } else if header_type != 3 {
            "target_header_type_mismatch"
        } else if service_name.is_none() {
            "target_service_name_unavailable"
        } else if service_name != Some(resolution.expected_service_name.as_bytes()) {
            "target_service_name_mismatch"
        } else {
            "target_header_matches"
        };
    } else if header_crc_valid == Some(true) && target > block_start && target < data_end {
        resolution.status = if target < header_end {
            "target_inside_header"
        } else {
            "target_inside_data"
        };
    }
}

fn finish_rar5_locator_status(
    resolution: &mut Rar5LocatorResolution,
    walk_status: &'static str,
    sample_length: usize,
) {
    if resolution.status != "target_unresolved" {
        return;
    }
    if resolution
        .target_sample_offset
        .is_some_and(|target| target >= sample_length)
    {
        resolution.status = "target_outside_sample";
    } else {
        resolution.status = match walk_status {
            "end_of_archive" => "target_after_archive_end",
            "header_encryption_encountered" => "header_encryption_encountered",
            "block_budget_exhausted" => "block_limit_reached",
            _ => "walk_interrupted",
        };
    }
}

fn read_rar5_block_observation(
    data: &[u8],
    start: usize,
    index: usize,
) -> (Rar5VisibleBlock, Option<Rar5BlockDescriptor>, &'static str) {
    let mut observation = Rar5VisibleBlock {
        index,
        header_start_offset: start,
        header_size_vint_byte_length: None,
        header_size_encoding_valid: None,
        header_end_offset: None,
        header_crc32_expected: None,
        header_crc32_calculated: None,
        header_crc32_valid: None,
        header_type: None,
        header_flags: None,
        end_archive_flags: None,
        data_size: None,
        data_start_offset: None,
        declared_data_end_offset: None,
        data_range_available: None,
        service_name_bytes_hex: None,
        service_name_utf8_valid: None,
        file_header: None,
        service_header: None,
        quick_open_cache: None,
    };
    let Some(size_start) = start.checked_add(4) else {
        return (observation, None, "header_offset_overflow");
    };
    let Some(crc_end) = start.checked_add(4) else {
        return (observation, None, "header_offset_overflow");
    };
    let Some(crc_bytes) = data.get(start..crc_end) else {
        return (observation, None, "header_crc_truncated");
    };
    let expected_crc = u32::from_le_bytes(crc_bytes.try_into().expect("four-byte CRC"));
    observation.header_crc32_expected = Some(expected_crc);
    let mut cursor = size_start;
    let Some((header_size, size_vint_length)) = read_vint_with_length(data, &mut cursor) else {
        return (observation, None, "header_size_truncated");
    };
    observation.header_size_vint_byte_length = Some(size_vint_length);
    observation.header_size_encoding_valid = Some(size_vint_length <= 3);
    if size_vint_length > 3 {
        return (observation, None, "header_size_encoding_nonconforming");
    }
    let Ok(header_size) = usize::try_from(header_size) else {
        return (observation, None, "header_size_overflow");
    };
    let Some(header_end) = cursor.checked_add(header_size) else {
        return (observation, None, "header_end_overflow");
    };
    observation.header_end_offset = Some(header_end);
    if header_end > data.len() {
        return (observation, None, "header_truncated");
    }
    let Some(header_bytes) = data.get(size_start..header_end) else {
        return (observation, None, "header_truncated");
    };
    let calculated_crc = crc32fast::hash(header_bytes);
    observation.header_crc32_calculated = Some(calculated_crc);
    observation.header_crc32_valid = Some(calculated_crc == expected_crc);
    let Some(header_data) = data.get(..header_end) else {
        return (observation, None, "header_truncated");
    };
    let Some(header_type) = read_rar5_vint_bounded(header_data, &mut cursor, header_end) else {
        return (observation, None, "header_type_unavailable");
    };
    let Some(header_flags) = read_rar5_vint_bounded(header_data, &mut cursor, header_end) else {
        return (observation, None, "header_flags_unavailable");
    };
    observation.header_type = Some(header_type);
    observation.header_flags = Some(header_flags);
    let extra_size = if header_flags & 0x01 != 0 {
        read_rar5_vint_bounded(header_data, &mut cursor, header_end)
    } else {
        Some(0)
    };
    let Some(extra_size) = extra_size.and_then(|size| usize::try_from(size).ok()) else {
        return (observation, None, "extra_size_unavailable");
    };
    let data_size = if header_flags & 0x02 != 0 {
        read_rar5_vint_bounded(header_data, &mut cursor, header_end)
    } else {
        Some(0)
    };
    let Some(data_size) = data_size else {
        return (observation, None, "data_size_unavailable");
    };
    let Ok(data_size_usize) = usize::try_from(data_size) else {
        return (observation, None, "data_size_overflow");
    };
    let Some(extra_start) = header_end.checked_sub(extra_size) else {
        return (observation, None, "extra_area_range_invalid");
    };
    if extra_start < cursor {
        return (observation, None, "extra_area_range_invalid");
    }
    if header_type == 5 {
        let Some(end_archive_flags) = read_rar5_vint_bounded(header_data, &mut cursor, extra_start)
        else {
            return (observation, None, "end_archive_flags_unavailable");
        };
        observation.end_archive_flags = Some(end_archive_flags);
    }
    let service_name = if header_type == 3 {
        read_rar5_service_name(data, cursor, extra_start)
    } else {
        None
    };
    if matches!(header_type, 2 | 3) {
        let mut header = parse_rar5_file_header(
            header_data,
            cursor,
            extra_start..header_end,
            header_flags,
            (header_flags & 0x02 != 0).then_some(data_size),
            observation.header_crc32_valid,
        );
        if header_type == 2 {
            rar5_copy_checksums::derive(&mut header, start, header_type, header_flags);
            observation.file_header = Some(header);
        } else {
            header.name_interpretation = "service_identifier_not_archived_file_name";
            header.directory = None;
            header.solid = None;
            // The documented packed-data exception describes split files. Do not
            // transfer it to split service data without independently verified evidence.
            header.checksum_domain = if header.split_before || header.split_after {
                "split_service_data_scope_not_interpreted"
            } else {
                "unpacked_service_data"
            };
            if header.data_crc32.is_some() {
                header.data_crc32_scope = Some(header.checksum_domain);
                header.data_crc32_scope_basis =
                    Some(if header.split_before || header.split_after {
                        "RAR5_service_data_declaration_split_scope_withheld"
                    } else {
                        "RAR5_unsplit_service_data_declaration"
                    });
            }
            // The directory flag is valid only for file headers. Keep its bytes,
            // but revoke timestamp authority when it appears on a service header.
            let unsupported = if header.file_flags.is_some_and(|flags| flags & 1 != 0) {
                Some("service_file_flags_unsupported")
            } else if header.compression_info.is_some_and(|info| info & 0x40 != 0) {
                Some("service_compression_flags_unsupported")
            } else {
                None
            };
            if let Some(status) = unsupported {
                if let Some(compression) = &mut header.compression_metadata {
                    compression.status = if header.header_crc32_valid == Some(true) {
                        status
                    } else {
                        "header_untrusted"
                    };
                    compression.interpretation_status = status;
                    compression.effective_dictionary_bytes = None;
                }
                let extras = &mut header.file_extra_records;
                for time in extras
                    .records
                    .iter_mut()
                    .filter_map(|record| record.time.as_mut())
                    .chain(extras.time.iter_mut())
                {
                    if time.interpretation_status == "validated_header_declaration" {
                        derive_rar5_filetime_epoch_relationship(time, status);
                    }
                }
                extras.base_header_mtime_seconds_matches = None;
                if extras
                    .time
                    .as_ref()
                    .is_some_and(|time| time.interpretation_status == status)
                {
                    extras.mtime_comparison_status = status;
                }
                if extras.version.as_ref().is_some_and(|version| {
                    version.interpretation_status == "validated_header_declaration"
                }) {
                    set_rar5_version_authority(extras, status);
                }
            }
            rar5_copy_checksums::derive(&mut header, start, header_type, header_flags);
            observation.service_header = Some(header);
        }
    }
    if let Some(name) = service_name.as_deref() {
        observation.service_name_bytes_hex = Some(hex(name));
        observation.service_name_utf8_valid = Some(std::str::from_utf8(name).is_ok());
    }
    let Some(block_end) = header_end.checked_add(data_size_usize) else {
        return (observation, None, "data_end_overflow");
    };
    observation.data_size = Some(data_size);
    observation.data_start_offset = Some(header_end);
    observation.declared_data_end_offset = Some(block_end);
    observation.data_range_available = Some(block_end <= data.len());
    (
        observation,
        Some(Rar5BlockDescriptor {
            header_end,
            header_type,
            data_end: block_end,
            service_name,
        }),
        if block_end <= data.len() {
            "block_complete"
        } else {
            "data_truncated"
        },
    )
}

fn read_rar5_vint_bounded(data: &[u8], cursor: &mut usize, end: usize) -> Option<u64> {
    let bounded = data.get(..end)?;
    read_vint_with_length(bounded, cursor).and_then(|(value, _)| (*cursor <= end).then_some(value))
}

fn read_rar5_service_name(data: &[u8], mut cursor: usize, fields_end: usize) -> Option<Vec<u8>> {
    let bounded = data.get(..fields_end)?;
    let file_flags = read_vint_with_length(bounded, &mut cursor)?.0;
    let _unpacked_size = read_vint_with_length(bounded, &mut cursor)?.0;
    let _attributes = read_vint_with_length(bounded, &mut cursor)?.0;
    if file_flags & 0x02 != 0 {
        cursor = cursor.checked_add(4)?;
    }
    if file_flags & 0x04 != 0 {
        cursor = cursor.checked_add(4)?;
    }
    if cursor > fields_end {
        return None;
    }
    let _compression_info = read_vint_with_length(bounded, &mut cursor)?.0;
    let _host_os = read_vint_with_length(bounded, &mut cursor)?.0;
    let name_length = read_vint_with_length(bounded, &mut cursor)?.0;
    let name_length = usize::try_from(name_length).ok()?;
    let name_end = cursor
        .checked_add(name_length)
        .filter(|end| *end <= fields_end)?;
    Some(data.get(cursor..name_end)?.to_vec())
}

fn parse_rar5_file_header(
    data: &[u8],
    mut cursor: usize,
    extra_area: std::ops::Range<usize>,
    header_flags: u64,
    packed_size: Option<u64>,
    header_crc32_valid: Option<bool>,
) -> Rar5FileHeaderObservation {
    let fields_end = extra_area.start;
    let header_end = extra_area.end;
    let mut result = Rar5FileHeaderObservation {
        status: "file_header_fields_truncated",
        header_crc32_valid,
        packed_size,
        directory: None,
        split_before: header_flags & 0x08 != 0,
        split_after: header_flags & 0x10 != 0,
        file_flags: None,
        unpacked_size_observed: None,
        unpacked_size_effective: None,
        unpacked_size_unknown: None,
        attributes: None,
        modification_time_unix_seconds: None,
        data_crc32: None,
        data_crc32_byte_offset: None,
        data_crc32_scope: None,
        data_crc32_scope_basis: None,
        checksum_domain: if header_flags & 0x10 != 0 {
            "packed_current_volume_data"
        } else {
            "unpacked_file_data"
        },
        checksum_transformation: "unknown",
        checksum_transformation_status: "file_header_fields_unavailable",
        encryption_presence: "unknown",
        redirection_checksum_relationship: None,
        file_extra_records: parse_rar5_file_extra_records(data, fields_end, header_end),
        compression_info: None,
        compression_version: None,
        solid: None,
        compression_method: None,
        dictionary_size_exponent: None,
        compression_metadata: None,
        host_os: None,
        name_byte_length: None,
        name_utf8_valid: None,
        filename: None,
        name_interpretation: "archived_file_name",
        name_sha256: None,
        name_preview_hex: None,
        name_preview_truncated: false,
    };

    let Some(file_flags) = read_rar5_vint_bounded(data, &mut cursor, fields_end) else {
        return result;
    };
    result.file_flags = Some(file_flags);
    result.directory = Some(file_flags & 0x01 != 0);

    let Some(unpacked_size) = read_rar5_vint_bounded(data, &mut cursor, fields_end) else {
        return result;
    };
    let unpacked_size_unknown = file_flags & 0x08 != 0;
    result.unpacked_size_observed = Some(unpacked_size);
    result.unpacked_size_unknown = Some(unpacked_size_unknown);
    result.unpacked_size_effective = (!unpacked_size_unknown).then_some(unpacked_size);

    let Some(attributes) = read_rar5_vint_bounded(data, &mut cursor, fields_end) else {
        return result;
    };
    result.attributes = Some(attributes);

    if file_flags & 0x02 != 0 {
        let Some(mtime_end) = cursor.checked_add(4).filter(|end| *end <= fields_end) else {
            return result;
        };
        let Some(bytes) = data.get(cursor..mtime_end) else {
            return result;
        };
        result.modification_time_unix_seconds = Some(u32::from_le_bytes(
            bytes.try_into().expect("four-byte RAR5 mtime"),
        ));
        cursor = mtime_end;
    }

    if file_flags & 0x04 != 0 {
        let Some(crc_end) = cursor.checked_add(4).filter(|end| *end <= fields_end) else {
            return result;
        };
        let Some(bytes) = data.get(cursor..crc_end) else {
            return result;
        };
        result.data_crc32 = Some(u32::from_le_bytes(
            bytes.try_into().expect("four-byte RAR5 file CRC32"),
        ));
        result.data_crc32_byte_offset = Some(cursor);
        result.data_crc32_scope = Some(if result.split_after {
            "packed_current_volume_data"
        } else {
            "unpacked_file_data"
        });
        result.data_crc32_scope_basis = Some("RAR5_split_after_flag");
        cursor = crc_end;
    }

    let Some(compression_info) = read_rar5_vint_bounded(data, &mut cursor, fields_end) else {
        return result;
    };
    result.compression_info = Some(compression_info);
    result.compression_version = Some((compression_info & 0x3f) as u8);
    result.solid = Some(compression_info & 0x40 != 0);
    result.compression_method = Some(((compression_info >> 7) & 0x07) as u8);
    result.dictionary_size_exponent = Some(((compression_info >> 10) & 0x1f) as u8);
    result.compression_metadata = Some(rar5_compression_metadata(compression_info, false));
    if header_crc32_valid == Some(true) {
        result
            .compression_metadata
            .as_mut()
            .expect("just set compression metadata")
            .status = "file_header_incomplete";
    }

    let Some(host_os) = read_rar5_vint_bounded(data, &mut cursor, fields_end) else {
        return result;
    };
    result.host_os = Some(host_os);

    let Some(name_length) = read_rar5_vint_bounded(data, &mut cursor, fields_end) else {
        return result;
    };
    let Ok(name_length) = usize::try_from(name_length) else {
        result.status = "filename_length_overflow";
        return result;
    };
    result.name_byte_length = Some(name_length);
    let Some(name_end) = cursor
        .checked_add(name_length)
        .filter(|end| *end <= fields_end)
    else {
        result.status = "filename_range_invalid";
        return result;
    };
    let Some(name_bytes) = data.get(cursor..name_end) else {
        result.status = "filename_range_invalid";
        return result;
    };
    let utf8_name = std::str::from_utf8(name_bytes);
    result.name_utf8_valid = Some(utf8_name.is_ok());
    result.name_sha256 = Some(hex(&Sha256::digest(name_bytes)));
    let preview = &name_bytes[..name_bytes.len().min(RAR5_FILENAME_PREVIEW_BYTES)];
    result.name_preview_hex = Some(hex(preview));
    result.name_preview_truncated = preview.len() < name_bytes.len();
    if name_bytes.len() <= MAX_RAR5_FILENAME_RETAIN_BYTES {
        result.filename = utf8_name.ok().map(str::to_owned);
    }
    result.status = "parsed";
    let mut compression =
        rar5_compression_metadata(compression_info, header_crc32_valid == Some(true));
    if header_crc32_valid == Some(true)
        && (file_flags & 1 != 0 || result.compression_method == Some(0))
    {
        compression.status = "dictionary_not_applicable";
        compression.effective_dictionary_bytes = None;
    }
    result.compression_metadata = Some(compression);
    for record in &mut result.file_extra_records.records {
        if let Some(redirection) = &mut record.redirection {
            redirection.interpretation_status = if header_crc32_valid != Some(true) {
                "header_checksum_unconfirmed"
            } else if name_end != fields_end {
                "file_header_fields_trailing_bytes"
            } else if redirection.status != "parsed" {
                "record_not_interpretable"
            } else {
                "validated_header_declaration"
            };
        }
    }
    derive_rar5_file_checksum_relations(&mut result);
    derive_rar5_file_time_relations(&mut result, name_end == fields_end);
    derive_rar5_archived_version_relations(&mut result, name_end == fields_end, header_flags);

    result
}

fn parse_rar5_file_extra_records(data: &[u8], start: usize, end: usize) -> Rar5FileExtraRecords {
    let bytes = data.get(start..end);
    let mut result = Rar5FileExtraRecords {
        status: if bytes.is_some() {
            "complete"
        } else {
            "extra_range_invalid"
        },
        coordinate_basis: "decoded-sample-relative",
        byte_length: end.saturating_sub(start),
        sha256: bytes
            .map(|bytes| hex(&Sha256::digest(bytes)))
            .unwrap_or_default(),
        record_count: 0,
        encryption_record_count: 0,
        hash_record_count: 0,
        redirection_record_count: 0,
        time_record_count: 0,
        version_record_count: 0,
        version: None,
        reference_interpreted_name: None,
        reference_name_relationship_status: "file_header_fields_incomplete",
        reference_name_relationship_basis: "Supported-format, bounded UTF-8 name plus unique version: ordinary 32-bit UnRAR 7.3.1 header-name append only; not stored-name mutation, extraction/version-selection compatibility, archiver version or uploader identity",
        time: None,
        mtime_comparison_status: "file_header_fields_incomplete",
        base_header_mtime_seconds_matches: None,
        encryption: None,
        hash: None,
        unambiguous_stored_blake2sp_hex: None,
        stored_hash_status: "file_header_fields_unavailable",
        records: Vec::new(),
        records_truncated: false,
        stopped_offset: None,
        unparsed_byte_count: end.saturating_sub(start),
    };
    let Some(bounded) = data.get(..end).filter(|_| bytes.is_some()) else {
        return result;
    };
    let mut cursor = start;
    while cursor < end {
        let record_start = cursor;
        if result.record_count >= MAX_RAR5_FILE_EXTRA_RECORDS_SCANNED {
            result.status = "record_scan_limit_reached";
            break;
        }
        let Some((size, size_length)) = read_vint_with_length(bounded, &mut cursor) else {
            result.status = "record_size_unavailable";
            cursor = record_start;
            break;
        };
        let record_end = usize::try_from(size)
            .ok()
            .and_then(|size| cursor.checked_add(size));
        let Some(record_end) =
            record_end.filter(|record_end| *record_end <= end && *record_end > cursor)
        else {
            result.status = "record_range_invalid";
            cursor = record_start;
            break;
        };
        let Some(kind) = read_rar5_vint_bounded(bounded, &mut cursor, record_end) else {
            result.status = "record_type_unavailable";
            cursor = record_start;
            break;
        };
        let payload = &bounded[cursor..record_end];
        let encryption = (kind == 1).then(|| parse_rar5_file_encryption_record(payload));
        let hash = (kind == 2).then(|| parse_rar5_file_hash_record(payload));
        let payload_sha256 = (result.records.len() < MAX_RAR5_FILE_EXTRA_RECORD_OBSERVATIONS
            || (kind == 3 && result.time.is_none())
            || (kind == 4 && result.version.is_none()))
        .then(|| hex(&Sha256::digest(payload)));
        // Count every scanned record, including occurrences beyond the observation budget.
        let time = (kind == 3
            && (result.time.is_none()
                || result.records.len() < MAX_RAR5_FILE_EXTRA_RECORD_OBSERVATIONS))
            .then(|| {
                parse_rar5_file_time_record(
                    payload,
                    result.record_count,
                    record_start,
                    cursor,
                    payload_sha256
                        .as_deref()
                        .expect("retained time payload hash"),
                )
            });
        if kind == 3 {
            result.time_record_count += 1;
            if result.time.is_none() {
                result.time = time.clone();
            }
        }
        let version = (kind == 4
            && (result.version.is_none()
                || result.records.len() < MAX_RAR5_FILE_EXTRA_RECORD_OBSERVATIONS))
            .then(|| {
                parse_rar5_archived_version_record(
                    payload,
                    result.record_count,
                    record_start,
                    cursor,
                    payload_sha256
                        .as_deref()
                        .expect("retained version payload hash"),
                )
            });
        if kind == 4 {
            result.version_record_count += 1;
            if result.version.is_none() {
                result.version = version.clone();
            }
        }
        if kind == 5 {
            result.redirection_record_count += 1;
        }
        if let Some(encryption) = &encryption {
            result.encryption_record_count += 1;
            if result.encryption.is_none() {
                result.encryption = Some(encryption.clone());
            }
        }
        if let Some(hash) = &hash {
            result.hash_record_count += 1;
            if result.hash.is_none() {
                result.hash = Some(hash.clone());
            }
        }
        if result.records.len() < MAX_RAR5_FILE_EXTRA_RECORD_OBSERVATIONS {
            let redirection = (kind == 5).then(|| parse_rar5_redirection_record(payload));
            let preview = &payload[..payload.len().min(MAX_RAR5_FILE_EXTRA_PAYLOAD_PREVIEW_BYTES)];
            result.records.push(Rar5FileExtraRecord {
                index: result.record_count,
                offset: record_start,
                record_end_offset: record_end,
                record_size: size,
                record_size_vint_byte_length: size_length,
                record_type: kind,
                payload_offset: cursor,
                payload_byte_length: payload.len(),
                payload_sha256: payload_sha256.expect("retained record payload hash"),
                payload_preview_hex: hex(preview),
                payload_preview_truncated: preview.len() != payload.len(),
                status: encryption
                    .as_ref()
                    .map(|value| value.status)
                    .or_else(|| hash.as_ref().map(|value| value.status))
                    .or_else(|| redirection.as_ref().map(|value| value.status))
                    .or_else(|| time.as_ref().map(|value| value.status))
                    .or_else(|| version.as_ref().map(|value| value.status))
                    .unwrap_or("unknown_record_type_skipped"),
                encryption,
                hash,
                redirection,
                time,
                version,
            });
        } else {
            result.records_truncated = true;
        }
        result.record_count += 1;
        cursor = record_end;
    }
    result.unparsed_byte_count = end.saturating_sub(cursor);
    if cursor < end {
        result.stopped_offset = Some(cursor);
    }
    result
}

fn observe_rar5_version_vint(
    payload: &[u8],
    cursor: &mut usize,
) -> (Option<u64>, String, &'static str) {
    let start = *cursor;
    let value = read_vint_with_length(payload, cursor).map(|(value, _)| value);
    let observed = &payload[start..*cursor];
    let status = if value.is_some() {
        "complete"
    } else if observed.len() == 10 && observed[9] & 0x7f > 1 {
        "numeric_overflow"
    } else if observed.len() == 10 {
        "vint_length_exceeds_64bit"
    } else {
        "truncated"
    };
    (value, hex(observed), status)
}

fn parse_rar5_archived_version_record(
    payload: &[u8],
    index: usize,
    record_offset: usize,
    payload_offset: usize,
    payload_sha256: &str,
) -> Rar5ArchivedVersionRecord {
    let mut result = Rar5ArchivedVersionRecord {
        status: "payload_not_interpretable",
        interpretation_status: "file_header_fields_incomplete",
        evidence_basis: "RAR5 file/service extra record 4 archived revision declaration; consistent with archive -ver feature, not software version or uploader identity",
        coordinate_basis: "record-payload-relative",
        source_coordinate_basis: "decoded-sample-relative",
        source_record_index: index,
        source_record_offset: record_offset,
        source_payload_offset: payload_offset,
        source_payload_byte_length: payload.len(),
        source_payload_sha256: payload_sha256.to_owned(),
        flags: None,
        flags_encoding_status: "not_observed",
        flags_raw_hex: String::new(),
        version_number: None,
        version_number_byte_offset: None,
        version_number_encoding_status: "not_observed",
        version_number_raw_hex: String::new(),
        trailing_byte_count: payload.len(),
    };
    let mut cursor = 0;
    (
        result.flags,
        result.flags_raw_hex,
        result.flags_encoding_status,
    ) = observe_rar5_version_vint(payload, &mut cursor);
    result.trailing_byte_count = payload.len().saturating_sub(cursor);
    if result.flags.is_none() {
        return result;
    }
    result.version_number_byte_offset = Some(cursor);
    (
        result.version_number,
        result.version_number_raw_hex,
        result.version_number_encoding_status,
    ) = observe_rar5_version_vint(payload, &mut cursor);
    result.trailing_byte_count = payload.len().saturating_sub(cursor);
    if result.version_number.is_none() {
        return result;
    }
    result.status = if result.flags != Some(0) {
        "unknown_flags"
    } else if result.trailing_byte_count != 0 {
        "trailing_bytes"
    } else {
        "parsed"
    };
    result
}

fn set_rar5_version_authority(extras: &mut Rar5FileExtraRecords, status: &'static str) {
    for version in extras
        .records
        .iter_mut()
        .filter_map(|record| record.version.as_mut())
        .chain(extras.version.iter_mut())
    {
        version.interpretation_status = status;
    }
    extras.reference_interpreted_name = None;
    extras.reference_name_relationship_status = status;
}

fn derive_rar5_archived_version_relations(
    file: &mut Rar5FileHeaderObservation,
    fields_complete: bool,
    header_flags: u64,
) {
    let extras = &mut file.file_extra_records;
    let status = if file.header_crc32_valid != Some(true) {
        "header_checksum_unconfirmed"
    } else if !fields_complete {
        "file_header_fields_trailing_bytes"
    } else if header_flags & !0x7f != 0 {
        "common_header_flags_unsupported"
    } else if file.file_flags.is_none_or(|flags| flags & !0x0f != 0) {
        "file_flags_unsupported"
    } else if extras.status != "complete" {
        "extra_framing_incomplete"
    } else if extras.version_record_count == 0 {
        "version_record_absent"
    } else if extras.version_record_count != 1 {
        "duplicate_version_records"
    } else if extras.records_truncated {
        "record_observation_limit"
    } else if extras
        .version
        .as_ref()
        .is_none_or(|version| version.status != "parsed")
    {
        "version_record_not_interpretable"
    } else {
        "validated_header_declaration"
    };
    set_rar5_version_authority(extras, status);
    if status != "validated_header_declaration" {
        return;
    }
    let version = extras
        .version
        .as_ref()
        .and_then(|version| version.version_number)
        .expect("unique parsed version");
    let Ok(version) = u32::try_from(version) else {
        extras.reference_name_relationship_status = "version_outside_32bit_reference_range";
        return;
    };
    let Some(name) = file.filename.as_ref() else {
        extras.reference_name_relationship_status = "filename_not_retained_as_utf8";
        return;
    };
    if name.contains('\0') {
        extras.reference_name_relationship_status = "filename_contains_nul";
        return;
    }
    let suffix = if version == 0 {
        String::new()
    } else {
        format!(";{version}")
    };
    if name
        .len()
        .checked_add(suffix.len())
        .is_none_or(|size| size > MAX_RAR5_FILENAME_RETAIN_BYTES)
    {
        extras.reference_name_relationship_status = "reference_name_exceeds_observation_budget";
        return;
    }
    extras.reference_interpreted_name = Some(format!("{name}{suffix}"));
    extras.reference_name_relationship_status =
        "qualified_32bit_unrar_header_name_interpretation_only";
}

fn parse_rar5_file_time_record(
    payload: &[u8],
    index: usize,
    record_offset: usize,
    payload_offset: usize,
    payload_sha256: &str,
) -> Rar5FileTimeRecord {
    let mut result = Rar5FileTimeRecord {
        status: "payload_truncated",
        interpretation_status: "file_header_fields_incomplete",
        evidence_basis: "RAR5 file/service extra record 3 timestamp declarations; writer-controlled, not upload time or identity",
        coordinate_basis: "record-payload-relative",
        source_coordinate_basis: "decoded-sample-relative",
        source_record_index: index,
        source_record_offset: record_offset,
        source_payload_offset: payload_offset,
        source_payload_byte_length: payload.len(),
        source_payload_sha256: payload_sha256.to_owned(),
        flags: None,
        unknown_flags: None,
        storage_format: None,
        times: Vec::with_capacity(3),
        trailing_byte_count: payload.len(),
    };
    let mut cursor = 0;
    let Some(flags) = read_vint(payload, &mut cursor) else {
        return result;
    };
    result.flags = Some(flags);
    result.unknown_flags = Some(flags & !0x1f);
    let unix = flags & 1 != 0;
    let nanos = unix && flags & 0x10 != 0;
    result.storage_format = Some(if unix {
        "unix_seconds"
    } else {
        "windows_filetime_100ns"
    });
    let width = if unix { 4 } else { 8 };
    for (flag, kind) in [(2, "modification"), (4, "creation"), (8, "access")] {
        if flags & flag != 0 {
            result.times.push(Rar5TimeValue {
                kind,
                base_value_byte_offset: None,
                base_value: None,
                base_value_raw_hex: String::new(),
                base_value_bytes_available: 0,
                nanoseconds_declared: nanos,
                nanoseconds_byte_offset: None,
                nanoseconds: None,
                nanoseconds_raw_hex: String::new(),
                nanoseconds_bytes_available: 0,
                nanoseconds_range_valid: None,
                filetime_unix_seconds_floor: None,
                filetime_unix_subsecond_nanoseconds: None,
                filetime_calendar_api_input_range_supported: None,
                filetime_epoch_relationship_status: if unix {
                    "not_applicable_unix_seconds"
                } else {
                    "file_header_fields_incomplete"
                },
                filetime_epoch_relationship_basis: "Exact encoded 100ns tick arithmetic relative to Unix epoch; calendar API input range only, not calendar validation, original clock precision, upload time or identity",
            });
        }
    }
    // The complete sequence of base timestamps precedes every precision field.
    for time in &mut result.times {
        time.base_value_byte_offset = Some(cursor);
        let available = payload.len().saturating_sub(cursor).min(width);
        time.base_value_bytes_available = available;
        time.base_value_raw_hex = hex(&payload[cursor..cursor + available]);
        let Some(bytes) = take_rar5_extra_bytes(payload, &mut cursor, width) else {
            result.trailing_byte_count = available;
            return result;
        };
        time.base_value = Some(if unix {
            u64::from(u32::from_le_bytes(
                bytes.try_into().expect("four-byte Unix time"),
            ))
        } else {
            u64::from_le_bytes(bytes.try_into().expect("eight-byte FILETIME"))
        });
    }
    if nanos {
        for time in &mut result.times {
            time.nanoseconds_byte_offset = Some(cursor);
            let available = payload.len().saturating_sub(cursor).min(4);
            time.nanoseconds_bytes_available = available;
            time.nanoseconds_raw_hex = hex(&payload[cursor..cursor + available]);
            let Some(bytes) = take_rar5_extra_bytes(payload, &mut cursor, 4) else {
                result.trailing_byte_count = available;
                return result;
            };
            let value = u32::from_le_bytes(bytes.try_into().expect("four-byte Unix nanoseconds"));
            time.nanoseconds = Some(value);
            // Retain raw values. Do not mask or silently carry malformed precision into seconds.
            time.nanoseconds_range_valid = Some(value < 1_000_000_000);
        }
    }
    result.trailing_byte_count = payload.len().saturating_sub(cursor);
    result.status = if flags & !0x1f != 0 {
        "unknown_flags"
    } else if result
        .times
        .iter()
        .any(|time| time.nanoseconds_range_valid == Some(false))
    {
        "nanoseconds_out_of_range"
    } else if result.trailing_byte_count != 0 {
        "trailing_bytes"
    } else {
        "parsed"
    };
    result
}

fn derive_rar5_file_time_relations(file: &mut Rar5FileHeaderObservation, fields_complete: bool) {
    let extras = &mut file.file_extra_records;
    let status = if file.header_crc32_valid != Some(true) {
        "header_checksum_unconfirmed"
    } else if !fields_complete {
        "file_header_fields_trailing_bytes"
    } else if file.file_flags.is_none_or(|flags| flags & !0x0f != 0) {
        "file_flags_unsupported"
    } else if extras.status != "complete" {
        "extra_framing_incomplete"
    } else if extras.time_record_count == 0 {
        "time_record_absent"
    } else if extras.time_record_count != 1 {
        "duplicate_time_records"
    } else if extras.records_truncated {
        "record_observation_limit"
    } else if extras
        .time
        .as_ref()
        .is_none_or(|time| time.status != "parsed")
    {
        "time_record_not_interpretable"
    } else {
        "validated_header_declaration"
    };
    for time in extras
        .records
        .iter_mut()
        .filter_map(|record| record.time.as_mut())
    {
        derive_rar5_filetime_epoch_relationship(time, status);
    }
    if let Some(time) = &mut extras.time {
        derive_rar5_filetime_epoch_relationship(time, status);
    }
    extras.mtime_comparison_status = status;
    if status != "validated_header_declaration" {
        return;
    }
    let time = extras.time.as_ref().expect("unique parsed time record");
    let Some(modified) = time.times.iter().find(|value| value.kind == "modification") else {
        extras.mtime_comparison_status = "modification_time_not_declared";
        return;
    };
    let Some(base) = file.modification_time_unix_seconds else {
        extras.mtime_comparison_status = "base_header_mtime_absent";
        return;
    };
    if time.storage_format != Some("unix_seconds") {
        let Some(seconds) = modified
            .filetime_unix_seconds_floor
            .and_then(|value| u32::try_from(value).ok())
        else {
            extras.mtime_comparison_status = "filetime_seconds_not_representable_in_base_header";
            return;
        };
        extras.base_header_mtime_seconds_matches = Some(seconds == base);
        extras.mtime_comparison_status = "declared_filetime_floor_seconds_consistency_only";
        return;
    }
    extras.base_header_mtime_seconds_matches =
        modified.base_value.map(|value| value == u64::from(base));
    extras.mtime_comparison_status = "declared_base_seconds_consistency_only";
}

fn derive_rar5_filetime_epoch_relationship(time: &mut Rar5FileTimeRecord, status: &'static str) {
    time.interpretation_status = status;
    if time.storage_format != Some("windows_filetime_100ns") {
        return;
    }
    for value in &mut time.times {
        value.filetime_unix_seconds_floor = None;
        value.filetime_unix_subsecond_nanoseconds = None;
        value.filetime_calendar_api_input_range_supported = None;
        value.filetime_epoch_relationship_status = status;
        if status != "validated_header_declaration" {
            continue;
        }
        let Some(ticks) = value.base_value else {
            value.filetime_epoch_relationship_status = "filetime_scalar_incomplete";
            continue;
        };
        // Divide before subtracting so pre-epoch values retain floor seconds and a
        // nonnegative fraction. Every u64 quotient and remainder fits these types.
        value.filetime_unix_seconds_floor = i64::try_from(ticks / 10_000_000)
            .ok()
            .and_then(|seconds| seconds.checked_sub(11_644_473_600));
        value.filetime_unix_subsecond_nanoseconds = u32::try_from((ticks % 10_000_000) * 100).ok();
        // This is FileTimeToSystemTime's documented input bound, not a calendar conversion.
        value.filetime_calendar_api_input_range_supported = Some(ticks < (1u64 << 63));
        value.filetime_epoch_relationship_status = "declared_epoch_arithmetic_only";
    }
}

fn parse_rar5_redirection_record(payload: &[u8]) -> Rar5RedirectionRecord {
    let mut result = Rar5RedirectionRecord {
        status: "payload_truncated",
        interpretation_status: "file_header_fields_incomplete",
        evidence_basis: "RAR5_file_extra_record_5_declaration",
        redirection_type: None,
        kind_observed: None,
        flags: None,
        unknown_flags: None,
        target_directory_observed: None,
        target_byte_length: None,
        target_utf8_valid: None,
        target_contains_nul: None,
        target_utf8: None,
        target_text_truncated: false,
        target_sha256: None,
        target_preview_hex: None,
        target_preview_truncated: false,
        trailing_byte_count: None,
        target_resolution_performed: false,
    };
    let mut cursor = 0;
    let Some(kind) = read_vint(payload, &mut cursor) else {
        return result;
    };
    result.redirection_type = Some(kind);
    result.kind_observed = match kind {
        1 => Some("unix_symlink"),
        2 => Some("windows_symlink"),
        3 => Some("windows_junction"),
        4 => Some("hard_link"),
        5 => Some("file_copy"),
        _ => None,
    };
    let Some(flags) = read_vint(payload, &mut cursor) else {
        return result;
    };
    result.flags = Some(flags);
    result.unknown_flags = Some(flags & !1);
    result.target_directory_observed = Some(flags & 1 != 0);
    let Some(length) = read_vint(payload, &mut cursor) else {
        return result;
    };
    result.target_byte_length = Some(length);
    let Some(target) = usize::try_from(length)
        .ok()
        .and_then(|length| take_rar5_extra_bytes(payload, &mut cursor, length))
    else {
        return result;
    };
    let utf8 = std::str::from_utf8(target);
    result.target_utf8_valid = Some(utf8.is_ok());
    result.target_contains_nul = Some(target.contains(&0));
    result.target_sha256 = Some(hex(&Sha256::digest(target)));
    let preview = &target[..target.len().min(MAX_RAR5_FILE_EXTRA_PAYLOAD_PREVIEW_BYTES)];
    result.target_preview_hex = Some(hex(preview));
    result.target_preview_truncated = preview.len() < target.len();
    result.target_text_truncated = target.len() > MAX_RAR5_FILENAME_RETAIN_BYTES;
    if !result.target_text_truncated {
        result.target_utf8 = utf8.ok().map(str::to_owned);
    }
    result.trailing_byte_count = Some(payload.len().saturating_sub(cursor));
    result.status = if result.trailing_byte_count != Some(0) {
        "trailing_bytes"
    } else if result.kind_observed.is_none() {
        "unsupported_redirection_type"
    } else if flags & !1 != 0 {
        "unknown_flags"
    } else if result.target_utf8_valid != Some(true) {
        "invalid_target_utf8"
    } else if result.target_contains_nul == Some(true) {
        "target_contains_nul"
    } else {
        "parsed"
    };
    result
}

fn take_rar5_extra_bytes<'a>(
    data: &'a [u8],
    cursor: &mut usize,
    length: usize,
) -> Option<&'a [u8]> {
    let end = cursor.checked_add(length)?;
    let bytes = data.get(*cursor..end)?;
    *cursor = end;
    Some(bytes)
}

fn parse_rar5_file_encryption_record(payload: &[u8]) -> Rar5FileEncryptionRecord {
    let mut result = Rar5FileEncryptionRecord {
        status: "payload_truncated",
        version: None,
        algorithm: None,
        flags: None,
        unknown_flags: None,
        checksums_key_dependent_observed: None,
        kdf_count_log2: None,
        kdf_iteration_count_decimal: None,
        salt_hex: None,
        iv_hex: None,
        password_check_present: None,
        password_check_data_hex: None,
        password_check_checksum_expected_hex: None,
        password_check_checksum_calculated_hex: None,
        password_check_checksum_valid: None,
        password_check_basis: "SHA256(check8)[0..4]; record consistency only, no password validation",
        trailing_byte_count: 0,
    };
    let mut cursor = 0;
    let Some(version) = read_vint(payload, &mut cursor) else {
        return result;
    };
    result.version = Some(version);
    if version != 0 {
        result.status = "unsupported_version";
        result.trailing_byte_count = payload.len() - cursor;
        return result;
    }
    result.algorithm = Some("AES-256");
    let Some(flags) = read_vint(payload, &mut cursor) else {
        return result;
    };
    result.flags = Some(flags);
    result.unknown_flags = Some(flags & !3);
    result.checksums_key_dependent_observed = Some(flags & 2 != 0);
    result.password_check_present = Some(flags & 1 != 0);
    let Some(kdf) = take_rar5_extra_bytes(payload, &mut cursor, 1) else {
        return result;
    };
    result.kdf_count_log2 = Some(kdf[0]);
    result.kdf_iteration_count_decimal = 1u64
        .checked_shl(u32::from(kdf[0]))
        .map(|count| count.to_string());
    let Some(salt) = take_rar5_extra_bytes(payload, &mut cursor, 16) else {
        return result;
    };
    result.salt_hex = Some(hex(salt));
    let Some(iv) = take_rar5_extra_bytes(payload, &mut cursor, 16) else {
        return result;
    };
    result.iv_hex = Some(hex(iv));
    if flags & 1 != 0 {
        let Some(check) = take_rar5_extra_bytes(payload, &mut cursor, 12) else {
            return result;
        };
        let digest = Sha256::digest(&check[..8]);
        result.password_check_data_hex = Some(hex(&check[..8]));
        result.password_check_checksum_expected_hex = Some(hex(&check[8..]));
        result.password_check_checksum_calculated_hex = Some(hex(&digest[..4]));
        result.password_check_checksum_valid = Some(check[8..] == digest[..4]);
    }
    result.trailing_byte_count = payload.len() - cursor;
    result.status = if flags & !3 != 0 {
        "unsupported_flags"
    } else if cursor != payload.len() {
        "trailing_bytes"
    } else if result.password_check_checksum_valid == Some(false) {
        "password_check_checksum_mismatch"
    } else {
        "parsed"
    };
    result
}

fn parse_rar5_file_hash_record(payload: &[u8]) -> Rar5FileHashRecord {
    let mut result = Rar5FileHashRecord {
        status: "payload_truncated",
        hash_type: None,
        algorithm: None,
        stored_digest_hex: None,
        trailing_byte_count: 0,
    };
    let mut cursor = 0;
    let Some(kind) = read_vint(payload, &mut cursor) else {
        return result;
    };
    result.hash_type = Some(kind);
    if kind != 0 {
        result.status = "unsupported_hash_type";
        result.trailing_byte_count = payload.len() - cursor;
        return result;
    }
    result.algorithm = Some("BLAKE2sp");
    let Some(digest) = take_rar5_extra_bytes(payload, &mut cursor, 32) else {
        return result;
    };
    result.stored_digest_hex = Some(hex(digest));
    result.trailing_byte_count = payload.len() - cursor;
    result.status = if cursor == payload.len() {
        "parsed"
    } else {
        "trailing_bytes"
    };
    result
}

fn derive_rar5_file_checksum_relations(file: &mut Rar5FileHeaderObservation) {
    let extras = &mut file.file_extra_records;
    if file.header_crc32_valid == Some(true) && extras.encryption_record_count > 0 {
        file.encryption_presence = "present";
    }
    file.checksum_transformation_status = if file.header_crc32_valid != Some(true) {
        "header_checksum_invalid"
    } else if extras.status != "complete" {
        "extra_framing_incomplete"
    } else if extras.encryption_record_count > 1 {
        "duplicate_encryption_records"
    } else if extras.encryption_record_count == 0 {
        file.encryption_presence = "absent";
        file.checksum_transformation = "plain";
        "encryption_record_absent_after_complete_scan"
    } else if let Some(encryption) = &extras.encryption {
        file.encryption_presence = "present";
        if encryption.status != "parsed" {
            "encryption_record_not_supported"
        } else {
            file.checksum_transformation =
                if encryption.checksums_key_dependent_observed == Some(true) {
                    "keyed"
                } else {
                    "plain"
                };
            "RAR5_file_encryption_flag_0x02"
        }
    } else {
        "encryption_record_unavailable"
    };
    extras.stored_hash_status = if file.checksum_transformation == "unknown" {
        "checksum_transformation_unknown"
    } else if extras.hash_record_count == 0 {
        "hash_record_absent"
    } else if extras.hash_record_count > 1 {
        "duplicate_hash_records"
    } else if let Some(hash) = &extras.hash {
        if hash.status == "parsed" {
            extras.unambiguous_stored_blake2sp_hex = hash.stored_digest_hex.clone();
            "unambiguous_stored_checksum_not_payload_verified"
        } else {
            "hash_record_not_supported"
        }
    } else {
        "hash_record_unavailable"
    };
}

fn parse_rar5_main_extra_records(data: &[u8], start: usize, end: usize) -> Rar5MainExtraRecords {
    let mut result = empty_rar5_main_extra_records();
    let Some(bytes) = data.get(start..end) else {
        result.available = false;
        result.malformed_record_count = 1;
        return result;
    };
    let mut offset = 0usize;
    while offset < bytes.len() {
        let Some(record_size) =
            read_vint(bytes, &mut offset).and_then(|size| usize::try_from(size).ok())
        else {
            result.malformed_record_count += 1;
            break;
        };
        let Some(record_end) = offset
            .checked_add(record_size)
            .filter(|record_end| *record_end <= bytes.len())
        else {
            result.malformed_record_count += 1;
            break;
        };
        let mut record_cursor = offset;
        let Some(record_type) = read_vint_bounded(bytes, &mut record_cursor, record_end) else {
            result.malformed_record_count += 1;
            break;
        };
        result.parsed_record_count += 1;
        if result.record_types.len() < MAX_RECORDED_RAR5_EXTRA_RECORD_TYPES {
            result.record_types.push(record_type);
        } else {
            result.record_types_truncated = true;
        }
        match record_type {
            1 => {
                result.locator_record_count = result.locator_record_count.saturating_add(1);
                let mut candidate = empty_rar5_main_extra_records();
                parse_rar5_locator_record(bytes, record_cursor, record_end, &mut candidate);
                result.malformed_record_count = result
                    .malformed_record_count
                    .saturating_add(candidate.malformed_record_count);
                if result.locator_record_count == 1 && candidate.malformed_record_count == 0 {
                    result.quick_open_offset = candidate.quick_open_offset;
                    result.recovery_record_offset = candidate.recovery_record_offset;
                } else if result.locator_record_count > 1 {
                    result.locator_records_ambiguous = true;
                    result.quick_open_offset = None;
                    result.recovery_record_offset = None;
                }
            }
            2 => {
                result.metadata_record_count = result.metadata_record_count.saturating_add(1);
                let mut candidate = empty_rar5_main_extra_records();
                parse_rar5_metadata_record(bytes, record_cursor, record_end, &mut candidate);
                result.malformed_record_count = result
                    .malformed_record_count
                    .saturating_add(candidate.malformed_record_count);
                if result.metadata_record_count == 1 && candidate.malformed_record_count == 0 {
                    copy_rar5_metadata_fields(&mut result, candidate);
                } else if result.metadata_record_count > 1 {
                    result.metadata_records_ambiguous = true;
                    clear_rar5_metadata_fields(&mut result);
                }
            }
            _ => {}
        }
        offset = record_end;
    }
    result
}

fn empty_rar5_main_extra_records() -> Rar5MainExtraRecords {
    Rar5MainExtraRecords {
        available: true,
        parsed_record_count: 0,
        malformed_record_count: 0,
        locator_record_count: 0,
        locator_records_ambiguous: false,
        metadata_record_count: 0,
        metadata_records_ambiguous: false,
        record_types: Vec::new(),
        record_types_truncated: false,
        archive_name: None,
        archive_name_byte_length: None,
        archive_name_sha256: None,
        archive_name_utf8_valid: None,
        archive_name_bytes_hex: None,
        archive_name_bytes_hex_truncated: false,
        archive_name_status: None,
        creation_time_value: None,
        creation_time_format: None,
        creation_time_unit: None,
        quick_open_offset: None,
        recovery_record_offset: None,
    }
}

fn copy_rar5_metadata_fields(destination: &mut Rar5MainExtraRecords, source: Rar5MainExtraRecords) {
    destination.archive_name = source.archive_name;
    destination.archive_name_byte_length = source.archive_name_byte_length;
    destination.archive_name_sha256 = source.archive_name_sha256;
    destination.archive_name_utf8_valid = source.archive_name_utf8_valid;
    destination.archive_name_bytes_hex = source.archive_name_bytes_hex;
    destination.archive_name_bytes_hex_truncated = source.archive_name_bytes_hex_truncated;
    destination.archive_name_status = source.archive_name_status;
    destination.creation_time_value = source.creation_time_value;
    destination.creation_time_format = source.creation_time_format;
    destination.creation_time_unit = source.creation_time_unit;
}

fn clear_rar5_metadata_fields(result: &mut Rar5MainExtraRecords) {
    result.archive_name = None;
    result.archive_name_byte_length = None;
    result.archive_name_sha256 = None;
    result.archive_name_utf8_valid = None;
    result.archive_name_bytes_hex = None;
    result.archive_name_bytes_hex_truncated = false;
    result.archive_name_status = None;
    result.creation_time_value = None;
    result.creation_time_format = None;
    result.creation_time_unit = None;
}

fn parse_rar5_locator_record(
    data: &[u8],
    mut cursor: usize,
    end: usize,
    result: &mut Rar5MainExtraRecords,
) {
    let Some(flags) = read_vint_bounded(data, &mut cursor, end) else {
        result.malformed_record_count += 1;
        return;
    };
    let quick_open_offset = if flags & 0x01 != 0 {
        read_vint_bounded(data, &mut cursor, end)
    } else {
        None
    };
    result.quick_open_offset = quick_open_offset.filter(|offset| *offset != 0);
    let recovery_record_offset = if flags & 0x02 != 0 {
        read_vint_bounded(data, &mut cursor, end)
    } else {
        None
    };
    result.recovery_record_offset = recovery_record_offset.filter(|offset| *offset != 0);
    if cursor > end
        || (flags & 0x01 != 0 && quick_open_offset.is_none())
        || (flags & 0x02 != 0 && recovery_record_offset.is_none())
        || cursor != end
    {
        result.malformed_record_count += 1;
    }
}

fn parse_rar5_metadata_record(
    data: &[u8],
    mut cursor: usize,
    end: usize,
    result: &mut Rar5MainExtraRecords,
) {
    let Some(flags) = read_vint_bounded(data, &mut cursor, end) else {
        result.malformed_record_count += 1;
        return;
    };
    if flags & 0x01 != 0 {
        let Some(name_length) = read_vint_bounded(data, &mut cursor, end)
            .and_then(|length| usize::try_from(length).ok())
        else {
            result.malformed_record_count += 1;
            return;
        };
        let Some(name_end) = cursor
            .checked_add(name_length)
            .filter(|name_end| *name_end <= end)
        else {
            result.malformed_record_count += 1;
            return;
        };
        let raw_name = &data[cursor..name_end];
        let effective_name = raw_name.split(|byte| *byte == 0).next().unwrap_or_default();
        let reserved_empty = raw_name.first() == Some(&0);
        let retained_name_prefix = &effective_name[..effective_name
            .len()
            .min(MAX_RETAINED_RAR5_ARCHIVE_NAME_BYTES)];
        result.archive_name_status = Some(if reserved_empty {
            "reserved_buffer_empty"
        } else if effective_name.len() > MAX_RETAINED_RAR5_ARCHIVE_NAME_BYTES {
            "name_exceeds_retention_limit"
        } else {
            "present"
        });
        result.archive_name_byte_length = Some(effective_name.len());
        result.archive_name_sha256 = Some(hex(&Sha256::digest(effective_name)));
        result.archive_name_bytes_hex = Some(hex(retained_name_prefix));
        result.archive_name_bytes_hex_truncated = retained_name_prefix.len() < effective_name.len();
        result.archive_name_utf8_valid = Some(std::str::from_utf8(effective_name).is_ok());
        result.archive_name = (!reserved_empty
            && effective_name.len() <= MAX_RETAINED_RAR5_ARCHIVE_NAME_BYTES)
            .then(|| std::str::from_utf8(effective_name).ok())
            .flatten()
            .map(str::to_owned);
        cursor = name_end;
    }
    if flags & 0x02 != 0 {
        let time_bytes = if flags & 0x04 != 0 {
            if flags & 0x08 != 0 { 8 } else { 4 }
        } else {
            8
        };
        let Some(time_end) = cursor
            .checked_add(time_bytes)
            .filter(|time_end| *time_end <= end)
        else {
            result.malformed_record_count += 1;
            return;
        };
        let raw_time = &data[cursor..time_end];
        result.creation_time_value = Some(if time_bytes == 4 {
            u64::from(u32::from_le_bytes(
                raw_time.try_into().expect("four-byte timestamp"),
            ))
        } else {
            u64::from_le_bytes(raw_time.try_into().expect("eight-byte timestamp"))
        });
        result.creation_time_format = Some(if flags & 0x04 != 0 {
            "unix"
        } else {
            "windows_filetime"
        });
        result.creation_time_unit = Some(if flags & 0x04 != 0 {
            if flags & 0x08 != 0 {
                "nanoseconds_since_unix_epoch"
            } else {
                "seconds_since_unix_epoch"
            }
        } else {
            "100_nanosecond_ticks_since_1601"
        });
        cursor = time_end;
    }
    if cursor != end {
        result.malformed_record_count += 1;
    }
}

fn read_vint(data: &[u8], cursor: &mut usize) -> Option<u64> {
    read_vint_with_length(data, cursor).map(|(value, _)| value)
}

fn read_vint_with_length(data: &[u8], cursor: &mut usize) -> Option<(u64, usize)> {
    let start = *cursor;
    let mut value = 0u64;
    for shift in (0..70).step_by(7) {
        let byte = *data.get(*cursor)?;
        *cursor = cursor.checked_add(1)?;
        let part = byte & 0x7f;
        if shift == 63 && part > 1 {
            return None;
        }
        value |= u64::from(part) << shift;
        if byte & 0x80 == 0 {
            return Some((value, cursor.checked_sub(start)?));
        }
    }
    None
}

fn read_vint_bounded(data: &[u8], cursor: &mut usize, end: usize) -> Option<u64> {
    if *cursor >= end {
        return None;
    }
    let bounded = data.get(..end)?;
    let value = read_vint(bounded, cursor)?;
    (*cursor <= end).then_some(value)
}

const MAX_7Z_PACK_STREAMS: usize = 128;
const MAX_7Z_CODER_PROPERTIES_BYTES: usize = 65_536;
const MAX_7Z_CODER_PROPERTY_PREVIEW_BYTES: usize = 64;

fn read_7z_uint64(data: &[u8], cursor: &mut usize, end: usize) -> Option<u64> {
    let first = *data.get(*cursor)?;
    *cursor = cursor.checked_add(1)?;
    let mut additional_bytes = 0usize;
    let mut mask = 0x80u8;
    while additional_bytes < 8 && first & mask != 0 {
        additional_bytes += 1;
        mask >>= 1;
    }
    if *cursor > end || additional_bytes > end.saturating_sub(*cursor) {
        return None;
    }

    let mut value = if additional_bytes == 8 {
        0
    } else {
        u64::from(first & (mask.wrapping_sub(1))) << (additional_bytes * 8)
    };
    for byte_index in 0..additional_bytes {
        value |= u64::from(*data.get(*cursor)?) << (byte_index * 8);
        *cursor = cursor.checked_add(1)?;
    }
    Some(value)
}

fn read_7z_pack_info(
    data: &[u8],
    next_header: &[u8],
    archive_offset: usize,
    next_header_offset: u64,
) -> Option<SevenZipEncodedHeaderPackInfo> {
    if next_header.get(..2) != Some(&[0x17, 0x06]) {
        return None;
    }

    let mut cursor = 2usize;
    let end = next_header.len();
    let Some(pack_pos) = read_7z_uint64(next_header, &mut cursor, end) else {
        return Some(incomplete_7z_pack_info("pack_position_unavailable"));
    };
    let Some(num_pack_streams) = read_7z_uint64(next_header, &mut cursor, end) else {
        return Some(incomplete_7z_pack_info_with_position(
            "stream_count_unavailable",
            Some(pack_pos),
            None,
        ));
    };
    let Ok(stream_count) = usize::try_from(num_pack_streams) else {
        return Some(incomplete_7z_pack_info_with_position(
            "stream_count_exceeds_limit",
            Some(pack_pos),
            Some(num_pack_streams),
        ));
    };
    if stream_count > MAX_7Z_PACK_STREAMS {
        return Some(incomplete_7z_pack_info_with_position(
            "stream_count_exceeds_limit",
            Some(pack_pos),
            Some(num_pack_streams),
        ));
    }

    let mut sizes = None;
    let mut crcs: Option<Vec<Option<u32>>> = None;
    let mut status = "pack_info_complete";
    loop {
        let Some(property_id) = next_header.get(cursor).copied() else {
            status = "pack_info_truncated";
            break;
        };
        cursor += 1;
        match property_id {
            0x00 => break,
            0x09 => {
                if sizes.is_some() {
                    status = "duplicate_size_property";
                    break;
                }
                let mut values = Vec::with_capacity(stream_count);
                let mut valid = true;
                for _ in 0..stream_count {
                    match read_7z_uint64(next_header, &mut cursor, end) {
                        Some(size) => values.push(size),
                        None => {
                            valid = false;
                            break;
                        }
                    }
                }
                if !valid {
                    status = "stream_sizes_truncated";
                    break;
                }
                sizes = Some(values);
            }
            0x0a => {
                if crcs.is_some() || cursor >= end {
                    status = "crc_property_malformed";
                    break;
                }
                let all_defined = next_header[cursor] != 0;
                cursor += 1;
                let mut defined = vec![all_defined; stream_count];
                if !all_defined {
                    let bitmap_length = stream_count.div_ceil(8);
                    let Some(bitmap_end) = cursor.checked_add(bitmap_length) else {
                        status = "crc_property_truncated";
                        break;
                    };
                    let Some(bitmap) = next_header.get(cursor..bitmap_end) else {
                        status = "crc_property_truncated";
                        break;
                    };
                    for (index, is_defined) in defined.iter_mut().enumerate() {
                        *is_defined = bitmap[index / 8] & (0x80 >> (index % 8)) != 0;
                    }
                    cursor = bitmap_end;
                }
                let mut values = vec![None; stream_count];
                for (index, is_defined) in defined.into_iter().enumerate() {
                    if !is_defined {
                        continue;
                    }
                    let Some(bytes) = next_header.get(cursor..cursor.saturating_add(4)) else {
                        status = "crc_property_truncated";
                        break;
                    };
                    values[index] = Some(le_u32(bytes, 0));
                    cursor += 4;
                }
                if status == "crc_property_truncated" {
                    break;
                }
                crcs = Some(values);
            }
            _ => {
                status = "unsupported_pack_info_property";
                break;
            }
        }
    }

    let Some(sizes) = sizes else {
        if status == "pack_info_complete" {
            status = "size_property_missing";
        }
        return Some(incomplete_7z_pack_info_with_position(
            status,
            Some(pack_pos),
            Some(num_pack_streams),
        ));
    };

    let total_packed_size = sizes
        .iter()
        .try_fold(0u64, |total, size| total.checked_add(*size));
    let packed_range_within_pre_header_area = total_packed_size
        .map(|total| pack_pos <= next_header_offset && total <= next_header_offset - pack_pos);
    let archive_base = u64::try_from(archive_offset)
        .ok()
        .and_then(|offset| offset.checked_add(32));
    let mut relative_stream_start = Some(pack_pos);
    let mut packed_streams = Vec::with_capacity(stream_count);
    for (index, size) in sizes.into_iter().enumerate() {
        let relative_start = relative_stream_start;
        let relative_end = relative_start.and_then(|start| start.checked_add(size));
        let byte_offset = archive_base
            .zip(relative_start)
            .and_then(|(base, start)| base.checked_add(start));
        let byte_end_exclusive = archive_base
            .zip(relative_end)
            .and_then(|(base, end)| base.checked_add(end));
        let within_pre_header_area =
            relative_end.map(|end| pack_pos <= next_header_offset && end <= next_header_offset);
        let sample = byte_offset
            .zip(byte_end_exclusive)
            .and_then(|(start, end)| {
                data.get(usize::try_from(start).ok()?..usize::try_from(end).ok()?)
            });
        let crc32_expected = crcs
            .as_ref()
            .and_then(|values| values.get(index))
            .copied()
            .flatten();
        let crc32_calculated = within_pre_header_area
            .filter(|within| *within)
            .and_then(|_| sample.zip(crc32_expected))
            .map(|(bytes, _)| crc32fast::hash(bytes));
        packed_streams.push(SevenZipPackedStream {
            index,
            size,
            byte_offset,
            byte_end_exclusive,
            within_pre_header_area,
            sample_available: sample.is_some(),
            crc32_expected,
            crc32_calculated,
            crc32_valid: crc32_calculated
                .zip(crc32_expected)
                .map(|(actual, expected)| actual == expected),
        });
        relative_stream_start = relative_end;
    }

    Some(SevenZipEncodedHeaderPackInfo {
        status,
        pack_pos: Some(pack_pos),
        num_pack_streams: Some(num_pack_streams),
        total_packed_size,
        packed_range_within_pre_header_area,
        packed_streams,
        pack_info_end_offset: (status == "pack_info_complete").then_some(cursor),
    })
}

fn incomplete_7z_pack_info(status: &'static str) -> SevenZipEncodedHeaderPackInfo {
    incomplete_7z_pack_info_with_position(status, None, None)
}

fn incomplete_7z_pack_info_with_position(
    status: &'static str,
    pack_pos: Option<u64>,
    num_pack_streams: Option<u64>,
) -> SevenZipEncodedHeaderPackInfo {
    SevenZipEncodedHeaderPackInfo {
        status,
        pack_pos,
        num_pack_streams,
        total_packed_size: None,
        packed_range_within_pre_header_area: None,
        packed_streams: Vec::new(),
        pack_info_end_offset: None,
    }
}

fn read_7z_encoded_header_streams_info(
    next_header: &[u8],
    mut cursor: usize,
    num_pack_streams: Option<u64>,
) -> SevenZipEncodedHeaderStreamsInfo {
    let mut result = SevenZipEncodedHeaderStreamsInfo {
        status: "unpack_info_unavailable",
        num_folders: None,
        external: None,
        external_data_stream_index: None,
        num_coders: None,
        coder: None,
        packed_input_index: None,
        pack_stream_count_matches_folder: None,
        unpack_size: None,
        unpack_crc32_expected: None,
        unpack_crc32_calculated: None,
        unpack_crc32_valid: None,
        unpack_info_terminator_seen: false,
        streams_info_terminator_seen: false,
        trailing_bytes: next_header.len().saturating_sub(cursor),
    };
    macro_rules! stop {
        ($status:literal) => {{
            result.status = $status;
            result.trailing_bytes = next_header.len().saturating_sub(cursor);
            return result;
        }};
    }

    if next_header.get(cursor) != Some(&0x07) {
        stop!("unpack_info_not_found");
    }
    cursor += 1;
    if next_header.get(cursor) != Some(&0x0b) {
        stop!("folder_property_missing");
    }
    cursor += 1;
    let Some(num_folders) = read_7z_uint64(next_header, &mut cursor, next_header.len()) else {
        stop!("folder_count_unavailable");
    };
    result.num_folders = Some(num_folders);
    if num_folders != 1 {
        stop!("folder_count_unsupported");
    }
    let Some(external_flag) = next_header.get(cursor).copied() else {
        stop!("external_flag_unavailable");
    };
    cursor += 1;
    let external = external_flag != 0;
    result.external = Some(external);
    if external {
        result.external_data_stream_index =
            read_7z_uint64(next_header, &mut cursor, next_header.len());
        stop!("external_folder_unsupported");
    }
    let Some(num_coders) = read_7z_uint64(next_header, &mut cursor, next_header.len()) else {
        stop!("coder_count_unavailable");
    };
    result.num_coders = Some(num_coders);
    if num_coders != 1 {
        stop!("coder_count_unsupported");
    }
    let Some(flags) = next_header.get(cursor).copied() else {
        stop!("coder_flags_unavailable");
    };
    cursor += 1;
    if flags & 0xc0 != 0 {
        stop!("coder_reserved_flags_set");
    }
    let method_id_length = usize::from(flags & 0x0f);
    if method_id_length > 8 {
        stop!("method_id_too_long");
    }
    let Some(method_id_end) = cursor.checked_add(method_id_length) else {
        stop!("method_id_range_overflow");
    };
    let Some(method_id) = next_header.get(cursor..method_id_end) else {
        stop!("method_id_truncated");
    };
    cursor = method_id_end;
    let is_complex = flags & 0x10 != 0;
    let (num_input_streams, num_output_streams) = if is_complex {
        let Some(inputs) = read_7z_uint64(next_header, &mut cursor, next_header.len()) else {
            stop!("input_stream_count_unavailable");
        };
        let Some(outputs) = read_7z_uint64(next_header, &mut cursor, next_header.len()) else {
            stop!("output_stream_count_unavailable");
        };
        (inputs, outputs)
    } else {
        (1, 1)
    };
    let properties_present = flags & 0x20 != 0;
    let mut properties_byte_length = 0usize;
    let mut properties_sha256 = None;
    let mut properties_preview_hex = None;
    let mut properties_preview_truncated = false;
    if properties_present {
        let Some(properties_length) = read_7z_uint64(next_header, &mut cursor, next_header.len())
        else {
            stop!("coder_properties_length_unavailable");
        };
        let Ok(properties_length) = usize::try_from(properties_length) else {
            stop!("coder_properties_length_exceeds_limit");
        };
        properties_byte_length = properties_length;
        let Some(properties_end) = cursor.checked_add(properties_length) else {
            stop!("coder_properties_range_overflow");
        };
        let Some(properties) = next_header.get(cursor..properties_end) else {
            stop!("coder_properties_truncated");
        };
        if properties_length > MAX_7Z_CODER_PROPERTIES_BYTES {
            result.coder = Some(SevenZipEncodedHeaderCoder {
                flags,
                method_id_hex: hex(method_id),
                method_name: seven_zip_method_name(method_id),
                num_input_streams: Some(num_input_streams),
                num_output_streams: Some(num_output_streams),
                properties_present,
                properties_byte_length,
                properties_sha256: None,
                properties_preview_hex: None,
                properties_preview_truncated: true,
            });
            stop!("coder_properties_exceed_limit");
        }
        let preview_length = properties.len().min(MAX_7Z_CODER_PROPERTY_PREVIEW_BYTES);
        properties_sha256 = Some(hex(&Sha256::digest(properties)));
        properties_preview_hex = Some(hex(&properties[..preview_length]));
        properties_preview_truncated = preview_length < properties.len();
        cursor = properties_end;
    }
    result.coder = Some(SevenZipEncodedHeaderCoder {
        flags,
        method_id_hex: hex(method_id),
        method_name: seven_zip_method_name(method_id),
        num_input_streams: Some(num_input_streams),
        num_output_streams: Some(num_output_streams),
        properties_present,
        properties_byte_length,
        properties_sha256,
        properties_preview_hex,
        properties_preview_truncated,
    });
    if num_input_streams != 1 || num_output_streams != 1 {
        stop!("coder_stream_shape_unsupported");
    }
    result.pack_stream_count_matches_folder = num_pack_streams.map(|count| count == 1);
    if result.pack_stream_count_matches_folder == Some(false) {
        stop!("pack_stream_count_mismatch");
    }
    result.packed_input_index = Some(0);

    if next_header.get(cursor) != Some(&0x0c) {
        stop!("unpack_size_property_missing");
    }
    cursor += 1;
    let Some(unpack_size) = read_7z_uint64(next_header, &mut cursor, next_header.len()) else {
        stop!("unpack_size_unavailable");
    };
    result.unpack_size = Some(unpack_size);

    if next_header.get(cursor) == Some(&0x0a) {
        cursor += 1;
        let Some(all_defined) = next_header.get(cursor).copied() else {
            stop!("unpack_crc_bitmap_unavailable");
        };
        cursor += 1;
        let defined = if all_defined != 0 {
            true
        } else {
            let Some(bitmap) = next_header.get(cursor).copied() else {
                stop!("unpack_crc_bitmap_unavailable");
            };
            cursor += 1;
            bitmap & 0x80 != 0
        };
        if defined {
            let Some(crc_end) = cursor.checked_add(4) else {
                stop!("unpack_crc_range_overflow");
            };
            let Some(crc_bytes) = next_header.get(cursor..crc_end) else {
                stop!("unpack_crc_truncated");
            };
            result.unpack_crc32_expected = Some(le_u32(crc_bytes, 0));
            cursor = crc_end;
        }
    }
    if next_header.get(cursor) != Some(&0x00) {
        stop!("unpack_info_terminator_missing");
    }
    cursor += 1;
    result.unpack_info_terminator_seen = true;
    match next_header.get(cursor) {
        Some(0x00) => {
            cursor += 1;
            result.streams_info_terminator_seen = true;
            result.trailing_bytes = next_header.len().saturating_sub(cursor);
            result.status = if result.trailing_bytes == 0 {
                "streams_info_complete"
            } else {
                "streams_info_trailing_bytes"
            };
        }
        Some(0x08) => {
            result.status = "substreams_info_unsupported";
            result.trailing_bytes = next_header.len().saturating_sub(cursor);
        }
        _ => stop!("streams_info_terminator_missing"),
    }
    result
}

fn seven_zip_method_name(method_id: &[u8]) -> Option<&'static str> {
    match method_id {
        [] | [0x00] => Some("Copy"),
        [0x21] => Some("LZMA2"),
        [0x03, 0x01, 0x01] => Some("LZMA"),
        [0x06, 0xf1, 0x07, 0x01] => Some("7zAES"),
        _ => None,
    }
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

fn parse_7z(data: &[u8], offset: usize) -> FormatHeader {
    let start = offset + SEVEN_ZIP.len();
    let header = start.checked_add(26).and_then(|end| data.get(start..end));
    let major_version = header.and_then(|bytes| bytes.first()).copied();
    let minor_version = header.and_then(|bytes| bytes.get(1)).copied();
    let major_version_supported = major_version.map(|version| version == 0);
    let start_crc = header.map(|bytes| le_u32(bytes, 2));
    let next_header_offset = header.map(|bytes| le_u64(bytes, 6));
    let next_header_size = header.map(|bytes| le_u64(bytes, 14));
    let next_header_crc = header.map(|bytes| le_u32(bytes, 22));
    let calculated_start_header_crc = header.map(|bytes| crc32fast::hash(&bytes[6..26]));
    let start_header_crc_valid = start_crc
        .zip(calculated_start_header_crc)
        .map(|(expected, calculated)| expected == calculated);
    let archive_start = u64::try_from(offset).ok();
    let header_range_within_sample = header.map(|_| {
        next_header_offset
            .zip(next_header_size)
            .and_then(|(header_offset, header_size)| header_offset.checked_add(header_size))
            .and_then(|range_end| {
                archive_start.and_then(|base| base.checked_add(32)?.checked_add(range_end))
            })
            .is_some_and(|end| end <= data.len() as u64)
    });
    let next_header = header_range_within_sample
        .filter(|within_sample| *within_sample)
        .and_then(|_| {
            archive_start
                .and_then(|base| base.checked_add(32))
                .and_then(|base| base.checked_add(next_header_offset?))
                .and_then(|start| usize::try_from(start).ok())
                .zip(usize::try_from(next_header_size?).ok())
                .and_then(|(start, size)| data.get(start..start.checked_add(size)?))
        });
    let next_header_checksum_valid = next_header
        .zip(next_header_crc)
        .map(|(bytes, expected)| crc32fast::hash(bytes) == expected);
    let next_header_observed_type = next_header.and_then(|bytes| bytes.first()).copied();
    let empty_header_fields_valid = next_header_size
        .filter(|size| *size == 0)
        .map(|_| next_header_offset == Some(0) && next_header_crc == Some(0));
    let next_header_interpretation_status = if header.is_none() {
        "start_header_unavailable"
    } else if start_header_crc_valid != Some(true) {
        "start_header_checksum_invalid"
    } else if major_version_supported == Some(false) {
        "unsupported_major_version"
    } else if empty_header_fields_valid == Some(false) {
        "empty_header_fields_inconsistent"
    } else if header_range_within_sample == Some(false) {
        "range_outside_sample"
    } else if next_header.is_none() {
        "range_unavailable"
    } else if next_header_checksum_valid != Some(true) {
        "next_header_checksum_invalid"
    } else if next_header.is_some_and(|bytes| bytes.is_empty()) {
        if empty_header_fields_valid == Some(true) {
            "empty"
        } else {
            "empty_header_fields_inconsistent"
        }
    } else {
        "checksums_valid"
    };
    let next_header_kind = if next_header_interpretation_status == "empty" {
        Some("empty")
    } else if next_header_interpretation_status == "checksums_valid" {
        next_header_observed_type.map(|kind| match kind {
            0x01 => "plain_header",
            0x17 => "encoded_header",
            _ => "unknown",
        })
    } else {
        None
    };
    let next_header_preview =
        next_header.map(|bytes| &bytes[..bytes.len().min(MAX_7Z_NEXT_HEADER_PREVIEW_BYTES)]);
    let encoded_header_pack_info = if next_header_interpretation_status == "checksums_valid"
        && next_header_kind == Some("encoded_header")
    {
        next_header.and_then(|bytes| read_7z_pack_info(data, bytes, offset, next_header_offset?))
    } else {
        None
    };
    let encoded_header_streams_info = encoded_header_pack_info.as_ref().and_then(|pack_info| {
        let cursor = pack_info.pack_info_end_offset?;
        Some(read_7z_encoded_header_streams_info(
            next_header?,
            cursor,
            pack_info.num_pack_streams,
        ))
    });
    FormatHeader::SevenZip {
        offset,
        start_header_available: header.is_some(),
        major_version,
        minor_version,
        major_version_supported,
        start_header_crc32: start_crc,
        calculated_start_header_crc32: calculated_start_header_crc,
        start_header_crc_valid,
        next_header_offset,
        next_header_size,
        next_header_crc32: next_header_crc,
        header_range_within_sample,
        next_header_checksum_valid,
        next_header_observed_type,
        next_header_type: next_header_observed_type,
        next_header_kind,
        next_header_interpretation_status,
        next_header_observed_byte_length: next_header.map(|bytes| bytes.len()),
        next_header_sha256: next_header.map(|bytes| hex(&Sha256::digest(bytes))),
        next_header_preview_hex: next_header_preview.map(hex),
        next_header_preview_truncated: next_header
            .is_some_and(|bytes| bytes.len() > MAX_7Z_NEXT_HEADER_PREVIEW_BYTES),
        encoded_header_pack_info,
        encoded_header_streams_info,
    }
}

fn parse_gzip(data: &[u8], offset: usize) -> FormatHeader {
    let fixed_end = offset.checked_add(10);
    let fixed = fixed_end.and_then(|end| data.get(offset..end));
    let compression_method = fixed.and_then(|header| header.get(2)).copied();
    let flags = fixed.and_then(|header| header.get(3)).copied();
    let reserved_bits_valid = flags.map(|value| value & 0xe0 == 0);
    let modification_time = fixed.map(|header| le_u32(header, 4));
    let extra_flags = fixed.and_then(|header| header.get(8)).copied();
    let operating_system = fixed.and_then(|header| header.get(9)).copied();
    let mut cursor = fixed_end.unwrap_or(data.len());
    let mut complete = fixed.is_some();
    let mut extra_field_length = None;
    let mut extra_field_sha256 = None;
    let mut extra_field_bytes_hex = None;
    let mut extra_field_bytes_hex_truncated = false;
    let mut extra_subfield_count = None;
    let mut extra_subfield_ids_hex = Vec::new();
    let mut extra_subfield_ids_truncated = false;
    let mut extra_subfield_framing_valid = None;
    let mut extra_subfield_unparsed_suffix_bytes = None;
    let mut filename_terminated = None;
    let mut filename_byte_length = None;
    let mut filename_full_byte_length = None;
    let mut filename_sha256 = None;
    let mut filename_sha256_basis = None;
    let mut filename_bytes_hex = None;
    let mut filename_bytes_hex_truncated = false;
    let mut filename_utf8_valid = None;
    let mut filename_latin1_printable = None;
    let mut filename_encoding = None;
    let mut filename = None;
    let mut filename_display_truncated = None;
    let mut filename_utf8_text = None;
    let mut filename_utf8_text_display_truncated = None;
    let mut comment_terminated = None;
    let mut comment_byte_length = None;
    let mut comment_full_byte_length = None;
    let mut comment_sha256 = None;
    let mut comment_sha256_basis = None;
    let mut comment_bytes_hex = None;
    let mut comment_bytes_hex_truncated = false;
    let mut comment_utf8_valid = None;
    let mut comment_latin1_printable = None;
    let mut comment_encoding = None;
    let mut comment = None;
    let mut comment_display_truncated = None;
    let mut comment_utf8_text = None;
    let mut comment_utf8_text_display_truncated = None;
    let mut header_crc16_expected = None;
    let mut header_crc16_calculated = None;
    let mut header_crc16_valid = None;

    if let Some(flags) = flags {
        if flags & 0x04 != 0 {
            let length_end = cursor.checked_add(2);
            if let Some(length_bytes) = length_end.and_then(|end| data.get(cursor..end)) {
                let length = u16::from_le_bytes([length_bytes[0], length_bytes[1]]);
                extra_field_length = Some(length);
                cursor = length_end.expect("validated extra length");
                let extra_end = cursor.checked_add(usize::from(length));
                if let Some(extra) = extra_end.and_then(|end| data.get(cursor..end)) {
                    extra_field_sha256 = Some(hex(&Sha256::digest(extra)));
                    let retained = &extra[..extra.len().min(MAX_RECORDED_GZIP_FIELD_HEX_BYTES)];
                    extra_field_bytes_hex = Some(hex(retained));
                    extra_field_bytes_hex_truncated = retained.len() < extra.len();
                    let summary = parse_gzip_extra_subfields(extra);
                    extra_subfield_count = Some(summary.subfield_count);
                    extra_subfield_ids_hex = summary.subfield_ids_hex;
                    extra_subfield_ids_truncated = summary.subfield_ids_truncated;
                    extra_subfield_framing_valid = Some(summary.framing_valid);
                    extra_subfield_unparsed_suffix_bytes = Some(summary.unparsed_suffix_bytes);
                    cursor = extra_end.expect("validated extra field range");
                } else {
                    complete = false;
                    cursor = data.len();
                }
            } else {
                complete = false;
                cursor = data.len();
            }
        }

        if flags & 0x08 != 0 && complete {
            let field = parse_gzip_text_field(data, cursor);
            filename_terminated = Some(field.terminated);
            filename_byte_length = Some(field.observed_byte_length);
            filename_full_byte_length = field.full_byte_length;
            filename_sha256 = Some(field.sha256);
            filename_sha256_basis = Some(field.sha256_basis);
            filename_bytes_hex = Some(field.bytes_hex);
            filename_bytes_hex_truncated = field.bytes_hex_truncated;
            filename_utf8_valid = Some(field.utf8_valid);
            filename_latin1_printable = Some(field.latin1_printable);
            filename_encoding = Some(field.encoding);
            filename = field.decoded_text;
            filename_display_truncated = Some(field.display_truncated);
            filename_utf8_text = field.utf8_text;
            filename_utf8_text_display_truncated = field.utf8_text_display_truncated;
            cursor = field.next_offset;
            complete &= field.complete;
        }
        if flags & 0x10 != 0 && complete {
            let field = parse_gzip_text_field(data, cursor);
            comment_terminated = Some(field.terminated);
            comment_byte_length = Some(field.observed_byte_length);
            comment_full_byte_length = field.full_byte_length;
            comment_sha256 = Some(field.sha256);
            comment_sha256_basis = Some(field.sha256_basis);
            comment_bytes_hex = Some(field.bytes_hex);
            comment_bytes_hex_truncated = field.bytes_hex_truncated;
            comment_utf8_valid = Some(field.utf8_valid);
            comment_latin1_printable = Some(field.latin1_printable);
            comment_encoding = Some(field.encoding);
            comment = field.decoded_text;
            comment_display_truncated = Some(field.display_truncated);
            comment_utf8_text = field.utf8_text;
            comment_utf8_text_display_truncated = field.utf8_text_display_truncated;
            cursor = field.next_offset;
            complete &= field.complete;
        }
        if flags & 0x02 != 0 && complete {
            let crc_end = cursor.checked_add(2);
            if let Some(crc_bytes) = crc_end.and_then(|end| data.get(cursor..end)) {
                let expected = u16::from_le_bytes([crc_bytes[0], crc_bytes[1]]);
                let calculated = crc32fast::hash(&data[offset..cursor]) as u16;
                header_crc16_expected = Some(expected);
                header_crc16_calculated = Some(calculated);
                header_crc16_valid = Some(expected == calculated);
                cursor = crc_end.expect("validated gzip header checksum range");
            } else {
                complete = false;
                cursor = data.len();
            }
        }
    }

    let header_valid = complete.then(|| {
        reserved_bits_valid == Some(true)
            && header_crc16_valid != Some(false)
            && extra_subfield_framing_valid != Some(false)
    });
    let (deflate_extra_flags_meaning, deflate_effort_class, deflate_effort_status) =
        gzip_deflate_effort_metadata(fixed.is_some(), compression_method, extra_flags);
    let operating_system_name = operating_system.map(gzip_operating_system_name);
    let GzipLocalObservations {
        fixed_header_available,
        structure_valid,
        extra_field_declared_length,
        extra_field_bytes_length,
        extra_field_bytes_sha256,
        extra_field_prefix_hex,
        extra_field_hex_truncated,
        original_filename_bytes_hex,
        original_filename_bytes_length,
        original_filename_bytes_sha256,
        original_filename_hex_truncated,
        comment_bytes_length,
        comment_bytes_sha256,
        comment_hex_truncated,
        header_crc16_present,
        ..
    } = read_gzip_local_observations(data, offset);
    FormatHeader::Gzip {
        fixed_header_available,
        structure_valid,
        extra_field_declared_length,
        extra_field_bytes_length,
        extra_field_bytes_sha256,
        extra_field_prefix_hex,
        extra_field_hex_truncated,
        original_filename_bytes_hex,
        original_filename_bytes_length,
        original_filename_bytes_sha256,
        original_filename_hex_truncated,
        comment_bytes_length,
        comment_bytes_sha256,
        comment_hex_truncated,
        header_crc16_present,
        offset,
        header_complete: complete,
        header_valid,
        compression_method,
        flags,
        flags_reserved_bits_valid: reserved_bits_valid,
        text_hint: flags.map(|value| value & 0x01 != 0),
        modification_time,
        extra_flags,
        deflate_extra_flags_meaning,
        deflate_effort_class,
        deflate_effort_status,
        operating_system,
        operating_system_name,
        extra_field_present: flags.map(|value| value & 0x04 != 0),
        extra_field_length,
        extra_field_sha256,
        extra_field_bytes_hex,
        extra_field_bytes_hex_truncated,
        extra_subfield_count,
        extra_subfield_ids_hex,
        extra_subfield_ids_truncated,
        extra_subfield_framing_valid,
        extra_subfield_unparsed_suffix_bytes,
        filename_present: flags.map(|value| value & 0x08 != 0),
        filename_terminated,
        filename_byte_length,
        filename_full_byte_length,
        filename_sha256,
        filename_sha256_basis,
        filename_bytes_hex,
        filename_bytes_hex_truncated,
        filename_utf8_valid,
        filename_latin1_printable,
        filename_encoding,
        filename,
        filename_display_truncated,
        filename_utf8_text,
        filename_utf8_text_display_truncated,
        comment_present: flags.map(|value| value & 0x10 != 0),
        comment_terminated,
        comment_byte_length,
        comment_full_byte_length,
        comment_sha256,
        comment_sha256_basis,
        comment_bytes_hex,
        comment_bytes_hex_truncated,
        comment_utf8_valid,
        comment_latin1_printable,
        comment_encoding,
        comment,
        comment_display_truncated,
        comment_utf8_text,
        comment_utf8_text_display_truncated,
        header_crc16_expected,
        header_crc16_calculated,
        header_crc16_valid,
        header_bytes: complete.then_some(cursor - offset),
    }
}

fn gzip_deflate_effort_metadata(
    fixed_header_available: bool,
    compression_method: Option<u8>,
    extra_flags: Option<u8>,
) -> (Option<&'static str>, Option<&'static str>, &'static str) {
    if !fixed_header_available {
        return (None, None, "fixed_header_incomplete");
    }
    if compression_method != Some(8) {
        return (None, None, "not_deflate");
    }
    match extra_flags {
        Some(0) => (
            Some("default_or_unspecified"),
            Some("normal"),
            "resolved_extra_flags",
        ),
        Some(2) => (
            Some("maximum_compression"),
            Some("maximum"),
            "resolved_extra_flags",
        ),
        Some(4) => (
            Some("fastest_algorithm"),
            Some("fast"),
            "resolved_extra_flags",
        ),
        Some(_) => (Some("unrecognized"), None, "unrecognized_extra_flags"),
        None => (None, None, "fixed_header_incomplete"),
    }
}

fn gzip_operating_system_name(os: u8) -> &'static str {
    match os {
        0 => "fat_filesystem",
        1 => "amiga",
        2 => "vms",
        3 => "unix",
        4 => "vm_cms",
        5 => "atari_tos",
        6 => "hpfs_filesystem",
        7 => "macintosh",
        8 => "z_system",
        9 => "cp_m",
        10 => "tops_20",
        11 => "ntfs_filesystem",
        12 => "qdos",
        13 => "acorn_risCOS",
        255 => "unknown",
        _ => "unassigned",
    }
}

struct GzipExtraSubfieldSummary {
    subfield_count: usize,
    subfield_ids_hex: Vec<String>,
    subfield_ids_truncated: bool,
    framing_valid: bool,
    unparsed_suffix_bytes: usize,
}

fn parse_gzip_extra_subfields(extra: &[u8]) -> GzipExtraSubfieldSummary {
    let mut summary = GzipExtraSubfieldSummary {
        subfield_count: 0,
        subfield_ids_hex: Vec::new(),
        subfield_ids_truncated: false,
        framing_valid: true,
        unparsed_suffix_bytes: 0,
    };
    let mut cursor = 0usize;
    while cursor < extra.len() {
        let Some(header_end) = cursor.checked_add(4).filter(|end| *end <= extra.len()) else {
            summary.framing_valid = false;
            summary.unparsed_suffix_bytes = extra.len().saturating_sub(cursor);
            break;
        };
        let id = &extra[cursor..cursor + 2];
        let subfield_length =
            usize::from(u16::from_le_bytes([extra[cursor + 2], extra[cursor + 3]]));
        let Some(subfield_end) = header_end
            .checked_add(subfield_length)
            .filter(|end| *end <= extra.len())
        else {
            summary.framing_valid = false;
            summary.unparsed_suffix_bytes = extra.len().saturating_sub(cursor);
            if summary.subfield_ids_hex.len() < MAX_RECORDED_GZIP_EXTRA_SUBFIELD_IDS {
                summary.subfield_ids_hex.push(hex(id));
            } else {
                summary.subfield_ids_truncated = true;
            }
            summary.subfield_count = summary.subfield_count.saturating_add(1);
            break;
        };
        if summary.subfield_ids_hex.len() < MAX_RECORDED_GZIP_EXTRA_SUBFIELD_IDS {
            summary.subfield_ids_hex.push(hex(id));
        } else {
            summary.subfield_ids_truncated = true;
        }
        summary.subfield_count = summary.subfield_count.saturating_add(1);
        cursor = subfield_end;
    }
    summary
}

/// Decoded view of one Gzip `FNAME` or `FCOMMENT` field.
///
/// RFC 1952 section 2.3.1 specifies ISO 8859-1 (Latin-1) for both fields, so the
/// Latin-1 decoding in `decoded_text` is the only authoritative reading. Bytes that
/// also happen to be valid UTF-8 (for example `caf\xc3\xa9`) decode to `cafÃ©` under
/// that rule. Many writers emit UTF-8 regardless, so a clean UTF-8 reading is exposed
/// separately in `utf8_text` as an observed, non-authoritative alternative; it never
/// replaces `decoded_text`.
///
/// Neither reading is rejected because of C1 bytes (0x80..=0x9F): they are
/// continuation bytes in most non-Latin UTF-8 text, so valid UTF-8 text is never
/// classified as binary. Only bytes that no text reading can explain (C0 controls
/// other than TAB/LF/CR, DEL, or C1 bytes that are not part of clean UTF-8 text) are
/// reported as `binary_or_control_bytes` with no decoded text.
struct GzipTextDecoding {
    utf8_valid: bool,
    /// True only when every byte is a printable Latin-1 character (no C0, DEL or C1).
    latin1_printable: bool,
    encoding: &'static str,
    /// Authoritative RFC 1952 ISO 8859-1 decoding (may contain C1 characters when
    /// `latin1_printable` is false but the bytes are clean UTF-8 text).
    decoded_text: Option<String>,
    display_truncated: bool,
    /// Non-authoritative UTF-8 reading of non-ASCII bytes that are valid, control-free UTF-8.
    utf8_text: Option<String>,
    utf8_text_display_truncated: Option<bool>,
}

fn decode_gzip_text_bytes(observed: &[u8], terminated: bool) -> GzipTextDecoding {
    let utf8 = std::str::from_utf8(observed).ok();
    let utf8_valid = utf8.is_some();
    let latin1_printable = observed
        .iter()
        .all(|&b| matches!(b, 0x09 | 0x0a | 0x0d | 0x20..=0x7e | 0xa0..=0xff));
    let display_truncated = observed.len() > MAX_RETAINED_GZIP_TEXT_DISPLAY_BYTES;
    let undecoded = |encoding: &'static str| GzipTextDecoding {
        utf8_valid,
        latin1_printable,
        encoding,
        decoded_text: None,
        display_truncated,
        utf8_text: None,
        utf8_text_display_truncated: None,
    };
    if !terminated {
        return undecoded("unterminated");
    }
    let prefix = &observed[..observed.len().min(MAX_RETAINED_GZIP_TEXT_DISPLAY_BYTES)];
    if observed.is_ascii() {
        if !latin1_printable {
            return undecoded("binary_or_control_bytes");
        }
        return GzipTextDecoding {
            decoded_text: std::str::from_utf8(prefix).ok().map(str::to_owned),
            encoding: "ascii",
            ..undecoded("ascii")
        };
    }
    let clean_utf8 = utf8.filter(|text| {
        text.chars()
            .all(|c| !c.is_control() || matches!(c, '\t' | '\n' | '\r'))
    });
    if !latin1_printable && clean_utf8.is_none() {
        return undecoded("binary_or_control_bytes");
    }
    let (utf8_text, utf8_text_display_truncated) = match clean_utf8 {
        Some(text) => {
            let (bounded, truncated) =
                bounded_utf8_prefix_string(text.as_bytes(), MAX_RETAINED_GZIP_TEXT_DISPLAY_BYTES);
            (bounded, Some(truncated))
        }
        None => (None, None),
    };
    GzipTextDecoding {
        decoded_text: Some(prefix.iter().map(|&b| char::from(b)).collect()),
        utf8_text,
        utf8_text_display_truncated,
        ..undecoded("iso_8859_1")
    }
}

fn parse_gzip_text_field(data: &[u8], cursor: usize) -> GzipTextFieldObservation {
    let Some(search_end) = cursor
        .checked_add(MAX_GZIP_OPTIONAL_FIELD_BYTES + 1)
        .map(|end| end.min(data.len()))
    else {
        return GzipTextFieldObservation {
            terminated: false,
            observed_byte_length: 0,
            full_byte_length: None,
            sha256: hex(&Sha256::digest([])),
            sha256_basis: "observed-prefix-sample-ended",
            bytes_hex: String::new(),
            bytes_hex_truncated: false,
            utf8_valid: true,
            latin1_printable: true,
            encoding: "unterminated",
            decoded_text: None,
            display_truncated: false,
            utf8_text: None,
            utf8_text_display_truncated: None,
            next_offset: cursor,
            complete: false,
        };
    };
    let Some(field) = data.get(cursor..search_end) else {
        return GzipTextFieldObservation {
            terminated: false,
            observed_byte_length: 0,
            full_byte_length: None,
            sha256: hex(&Sha256::digest([])),
            sha256_basis: "observed-prefix-sample-ended",
            bytes_hex: String::new(),
            bytes_hex_truncated: false,
            utf8_valid: true,
            latin1_printable: true,
            encoding: "unterminated",
            decoded_text: None,
            display_truncated: false,
            utf8_text: None,
            utf8_text_display_truncated: None,
            next_offset: cursor,
            complete: false,
        };
    };
    let terminator = field.iter().position(|byte| *byte == 0);
    let observed_length = terminator.unwrap_or(field.len().min(MAX_GZIP_OPTIONAL_FIELD_BYTES));
    let observed = &field[..observed_length];
    let retained = &observed[..observed.len().min(MAX_RECORDED_GZIP_FIELD_HEX_BYTES)];
    let terminated = terminator.is_some();
    let GzipTextDecoding {
        utf8_valid,
        latin1_printable,
        encoding,
        decoded_text,
        display_truncated,
        utf8_text,
        utf8_text_display_truncated,
    } = decode_gzip_text_bytes(observed, terminated);
    let next = if terminated {
        cursor + observed_length + 1
    } else {
        cursor + observed_length
    };
    GzipTextFieldObservation {
        terminated,
        observed_byte_length: observed_length,
        full_byte_length: terminated.then_some(observed_length),
        sha256: hex(&Sha256::digest(observed)),
        sha256_basis: if terminated {
            "complete-nul-terminated-field"
        } else if field.len() > observed_length {
            "observed-prefix-scan-limited"
        } else {
            "observed-prefix-sample-ended"
        },
        bytes_hex: hex(retained),
        bytes_hex_truncated: retained.len() < observed.len(),
        utf8_valid,
        latin1_printable,
        encoding,
        decoded_text,
        display_truncated,
        utf8_text,
        utf8_text_display_truncated,
        next_offset: next,
        complete: terminated,
    }
}

fn parse_xz(data: &[u8], offset: usize) -> FormatHeader {
    let end = offset.checked_add(12);
    let header = end.and_then(|end| data.get(offset..end));
    let flags = header.map(|bytes| &bytes[6..8]);
    let check_id = flags.map(|bytes| bytes[1] & 0x0f);
    let reserved_bits_valid = flags.map(|bytes| bytes[0] == 0 && bytes[1] & 0xf0 == 0);
    let expected_crc = header.map(|bytes| le_u32(bytes, 8));
    let calculated_crc = flags.map(crc32fast::hash);
    let crc_valid = expected_crc
        .zip(calculated_crc)
        .map(|(expected, calculated)| expected == calculated);
    let header_valid = header
        .is_some()
        .then(|| reserved_bits_valid == Some(true) && crc_valid == Some(true));
    let check_name = check_id.map(|id| match id {
        0 => "none",
        1 => "crc32",
        4 => "crc64",
        10 => "sha256",
        _ => "reserved",
    });

    let XzLocalObservations {
        stream_header_available,
        structure_valid,
        check_id_supported,
        reserved_flags_valid,
        header_crc32,
        calculated_header_crc32,
        ..
    } = read_xz_local_observations(data, offset);
    FormatHeader::Xz {
        first_block: xz_first_block::observe(data, offset, header_valid == Some(true), check_id),
        stream_header_available,
        structure_valid,
        check_id_supported,
        reserved_flags_valid,
        header_crc32,
        calculated_header_crc32,
        offset,
        header_complete: header.is_some(),
        stream_flags_hex: flags.map(hex),
        reserved_bits_valid,
        check_id,
        check_name,
        header_crc32_expected: expected_crc,
        header_crc32_calculated: calculated_crc,
        header_crc32_valid: crc_valid,
        header_valid,
    }
}

#[cfg(test)]
fn find_iso_pvd(data: &[u8]) -> Option<usize> {
    scan_iso_descriptor_sequence(data)
        .and_then(|sequence| sequence.first_primary_volume_descriptor_offset)
}

fn scan_iso_descriptor_sequence(data: &[u8]) -> Option<IsoDescriptorSequence> {
    let first_offset = ISO_LOGICAL_SECTOR_SIZE_BYTES
        .checked_mul(16)
        .and_then(|offset| usize::try_from(offset).ok())?;
    if data.get(first_offset + 1..first_offset + 6)? != b"CD001" {
        return None;
    }
    let mut descriptors = Vec::new();
    let mut first_primary_volume_descriptor_offset = None;
    let mut primary_volume_descriptor_count = 0usize;
    for sector in 16usize..256 {
        let offset = sector * 2048;
        let Some(descriptor) = data.get(offset..offset.saturating_add(2048)) else {
            return Some(IsoDescriptorSequence {
                status: "sample_truncated",
                offset_basis: "decoded-sample-relative; yEnc file offset not supplied",
                image_origin_known: false,
                descriptors,
                first_primary_volume_descriptor_offset,
                primary_volume_descriptor_count,
                terminator_offset: None,
                terminator_version_valid: None,
                terminator_reserved_bytes_zero: None,
                scan_limit_sectors: 240,
            });
        };
        let descriptor_type = descriptor[0];
        let version = descriptor[6];
        let standard_identifier_valid = &descriptor[1..6] == b"CD001";
        let type_version_supported = match descriptor_type {
            0 | 1 | 3 | 255 => version == 1,
            2 => matches!(version, 1 | 2),
            _ => false,
        };
        descriptors.push(IsoDescriptorObservation {
            sector,
            offset,
            descriptor_type,
            version,
            standard_identifier_valid,
            type_version_supported,
            character_set_declaration: (standard_identifier_valid
                && descriptor_type == 2
                && type_version_supported)
                .then(|| iso_character_set_declaration(descriptor)),
        });
        if !standard_identifier_valid {
            return Some(IsoDescriptorSequence {
                status: "malformed",
                offset_basis: "decoded-sample-relative; yEnc file offset not supplied",
                image_origin_known: false,
                descriptors,
                first_primary_volume_descriptor_offset,
                primary_volume_descriptor_count,
                terminator_offset: None,
                terminator_version_valid: None,
                terminator_reserved_bytes_zero: None,
                scan_limit_sectors: 240,
            });
        }
        if descriptor_type == 255 {
            let terminator_version_valid = version == 1;
            let terminator_reserved_bytes_zero = descriptor[7..].iter().all(|byte| *byte == 0);
            return Some(IsoDescriptorSequence {
                status: if !terminator_version_valid {
                    "malformed_terminator_version"
                } else if !terminator_reserved_bytes_zero {
                    "malformed_terminator_reserved_bytes"
                } else {
                    "terminated"
                },
                offset_basis: "decoded-sample-relative; yEnc file offset not supplied",
                image_origin_known: false,
                descriptors,
                first_primary_volume_descriptor_offset,
                primary_volume_descriptor_count,
                terminator_offset: Some(offset),
                terminator_version_valid: Some(terminator_version_valid),
                terminator_reserved_bytes_zero: Some(terminator_reserved_bytes_zero),
                scan_limit_sectors: 240,
            });
        }
        if !type_version_supported {
            return Some(IsoDescriptorSequence {
                status: if (4..=254).contains(&descriptor_type) {
                    "unsupported_reserved_type"
                } else {
                    "malformed"
                },
                offset_basis: "decoded-sample-relative; yEnc file offset not supplied",
                image_origin_known: false,
                descriptors,
                first_primary_volume_descriptor_offset,
                primary_volume_descriptor_count,
                terminator_offset: None,
                terminator_version_valid: None,
                terminator_reserved_bytes_zero: None,
                scan_limit_sectors: 240,
            });
        }
        if descriptor_type == 1 {
            primary_volume_descriptor_count += 1;
            first_primary_volume_descriptor_offset.get_or_insert(offset);
        }
    }
    Some(IsoDescriptorSequence {
        status: "scan_limit_reached",
        offset_basis: "decoded-sample-relative; yEnc file offset not supplied",
        image_origin_known: false,
        descriptors,
        first_primary_volume_descriptor_offset,
        primary_volume_descriptor_count,
        terminator_offset: None,
        terminator_version_valid: None,
        terminator_reserved_bytes_zero: None,
        scan_limit_sectors: 240,
    })
}

fn iso_character_set_declaration(descriptor: &[u8]) -> IsoCharacterSetDeclaration {
    // The scanner supplies complete 2048-byte type-2 descriptors. ECMA-119
    // 9.5.3/4/7 and Annex C distinguish declarations from directory conformance.
    let version = descriptor[6];
    let flags = descriptor[7];
    let escape_sequences = &descriptor[88..120];
    let reserved_volume_flag_bits_valid = flags & 0xfe == 0;
    let unregistered_escape_sequence_declared = flags & 1 != 0;
    let file_structure_version_observed = descriptor[881];
    let file_structure_version_valid = file_structure_version_observed == version;
    let escape_sequences_all_zero = escape_sequences.iter().all(|byte| *byte == 0);
    let joliet_prefix_level_observed = match &escape_sequences[..3] {
        b"%/@" => Some(1),
        b"%/C" => Some(2),
        b"%/E" => Some(3),
        _ => None,
    };
    let joliet_prefix_remaining_bytes_zero = escape_sequences[3..].iter().all(|byte| *byte == 0);
    let (status, recognized_character_set) =
        if !reserved_volume_flag_bits_valid || !file_structure_version_valid {
            ("inconsistent_declaration", None)
        } else if version == 2 {
            // Enhanced descriptor text is subject to originator/recipient agreement.
            ("enhanced_declaration_uninterpreted", None)
        } else if unregistered_escape_sequence_declared
            && (escape_sequences_all_zero
                || (joliet_prefix_level_observed.is_some() && joliet_prefix_remaining_bytes_zero))
        {
            ("inconsistent_declaration", None)
        } else if escape_sequences_all_zero {
            ("recognized_ecma6_declaration", Some("ecma6"))
        } else if !unregistered_escape_sequence_declared
            && joliet_prefix_level_observed.is_some()
            && joliet_prefix_remaining_bytes_zero
        {
            ("recognized_joliet_declaration", Some("ucs2_be"))
        } else {
            // Other or multiple contiguous escape sequences may be legitimate;
            // retaining raw bytes does not imply that they are malformed.
            ("uninterpreted_escape_declaration", None)
        };
    IsoCharacterSetDeclaration {
        status,
        descriptor_kind: if version == 1 {
            "supplementary"
        } else {
            "enhanced"
        },
        basis: "descriptor declaration only; directory hierarchy and character contents not inspected",
        volume_flags_observed: flags,
        unregistered_escape_sequence_declared,
        reserved_volume_flag_bits_valid,
        file_structure_version_observed,
        file_structure_version_valid,
        escape_sequences_raw_hex: hex(escape_sequences),
        escape_sequences_all_zero,
        joliet_prefix_level_observed,
        joliet_prefix_remaining_bytes_zero,
        recognized_character_set,
        directory_hierarchy_inspected: false,
    }
}

fn parse_iso_pvd(data: &[u8], offset: usize) -> FormatHeader {
    let pvd = &data[offset..offset + 2048];
    let volume_space_blocks_little_endian_observed = pvd
        .get(80..84)
        .map(|bytes| u32::from_le_bytes(bytes.try_into().expect("four-byte ISO field")));
    let volume_space_blocks_big_endian_observed = pvd
        .get(84..88)
        .map(|bytes| u32::from_be_bytes(bytes.try_into().expect("four-byte ISO field")));
    let dual_endian_volume_space_match = volume_space_blocks_little_endian_observed
        .zip(volume_space_blocks_big_endian_observed)
        .map(|(little, big)| little == big);
    let effective_volume_space_blocks = dual_endian_volume_space_match
        .filter(|matches| *matches)
        .and(volume_space_blocks_little_endian_observed)
        .filter(|blocks| *blocks > 0);
    let logical_block_size_little_endian_observed = pvd
        .get(128..130)
        .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]]));
    let logical_block_size_big_endian_observed = pvd
        .get(130..132)
        .map(|bytes| u16::from_be_bytes([bytes[0], bytes[1]]));
    let dual_endian_logical_block_size_match = logical_block_size_little_endian_observed
        .zip(logical_block_size_big_endian_observed)
        .map(|(little, big)| little == big);
    let logical_block_size_valid = dual_endian_logical_block_size_match.map(|matches| {
        matches
            && logical_block_size_little_endian_observed
                .is_some_and(|size| (512..=2048).contains(&size) && size.is_power_of_two())
    });
    let effective_logical_block_size = dual_endian_logical_block_size_match
        .filter(|matches| *matches)
        .and(logical_block_size_little_endian_observed)
        .filter(|size| (512..=2048).contains(size) && size.is_power_of_two());
    let volume_space_bytes = effective_volume_space_blocks
        .zip(effective_logical_block_size)
        .and_then(|(blocks, block_size)| u64::from(blocks).checked_mul(u64::from(block_size)));
    let volume_set_size_observed = pvd
        .get(120..122)
        .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]]));
    let dual_endian_volume_set_size_match = pvd.get(120..124).map(|bytes| {
        u16::from_le_bytes([bytes[0], bytes[1]]) == u16::from_be_bytes([bytes[2], bytes[3]])
    });
    let volume_sequence_number_observed = pvd
        .get(124..126)
        .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]]));
    let dual_endian_volume_sequence_number_match = pvd.get(124..128).map(|bytes| {
        u16::from_le_bytes([bytes[0], bytes[1]]) == u16::from_be_bytes([bytes[2], bytes[3]])
    });
    let volume_set_sequence_relationship_valid = dual_endian_volume_set_size_match
        .zip(dual_endian_volume_sequence_number_match)
        .zip(volume_set_size_observed.zip(volume_sequence_number_observed))
        .and_then(|((set_matches, sequence_matches), (set_size, sequence))| {
            (set_matches && sequence_matches)
                .then_some(set_size > 0 && sequence > 0 && sequence <= set_size)
        });
    let path_table_size_little_endian_observed = pvd
        .get(132..136)
        .map(|bytes| u32::from_le_bytes(bytes.try_into().expect("four-byte path-table size")));
    let path_table_size_big_endian_observed = pvd
        .get(136..140)
        .map(|bytes| u32::from_be_bytes(bytes.try_into().expect("four-byte path-table size")));
    let dual_endian_path_table_size_match = path_table_size_little_endian_observed
        .zip(path_table_size_big_endian_observed)
        .map(|(little, big)| little == big);
    let path_table_size = dual_endian_path_table_size_match
        .filter(|matches| *matches)
        .and(path_table_size_little_endian_observed);
    let type_l_path_table_location = pvd
        .get(140..144)
        .map(|bytes| u32::from_le_bytes(bytes.try_into().expect("four-byte path-table location")));
    let optional_type_l_path_table_location_observed = pvd
        .get(144..148)
        .map(|bytes| u32::from_le_bytes(bytes.try_into().expect("four-byte path-table location")));
    let optional_type_l_path_table_location =
        optional_type_l_path_table_location_observed.filter(|location| *location != 0);
    let type_m_path_table_location = pvd
        .get(148..152)
        .map(|bytes| u32::from_be_bytes(bytes.try_into().expect("four-byte path-table location")));
    let optional_type_m_path_table_location_observed = pvd
        .get(152..156)
        .map(|bytes| u32::from_be_bytes(bytes.try_into().expect("four-byte path-table location")));
    let optional_type_m_path_table_location =
        optional_type_m_path_table_location_observed.filter(|location| *location != 0);
    let path_table_ranges = vec![
        iso_path_table_range(
            "type_l",
            false,
            type_l_path_table_location,
            path_table_size,
            effective_logical_block_size,
            volume_space_bytes,
        ),
        iso_path_table_range(
            "optional_type_l",
            true,
            optional_type_l_path_table_location_observed,
            path_table_size,
            effective_logical_block_size,
            volume_space_bytes,
        ),
        iso_path_table_range(
            "type_m",
            false,
            type_m_path_table_location,
            path_table_size,
            effective_logical_block_size,
            volume_space_bytes,
        ),
        iso_path_table_range(
            "optional_type_m",
            true,
            optional_type_m_path_table_location_observed,
            path_table_size,
            effective_logical_block_size,
            volume_space_bytes,
        ),
    ];
    let root_directory_record = parse_iso_root_directory_record(
        pvd,
        effective_logical_block_size,
        volume_space_bytes,
        volume_set_sequence_relationship_valid
            .filter(|valid| *valid)
            .and(volume_sequence_number_observed),
    );
    let publisher_identifier_bytes = &pvd[318..446];
    let preparer_identifier_bytes = &pvd[446..574];
    let application_identifier_bytes = &pvd[574..702];
    let publisher_identifier = ascii_field(pvd, 318, 128);
    let preparer_identifier = ascii_field(pvd, 446, 128);
    let application_identifier = ascii_field(pvd, 574, 128);
    let publisher_identifier_observation = iso_identifier_observation(publisher_identifier_bytes);
    let preparer_identifier_observation = iso_identifier_observation(preparer_identifier_bytes);
    let application_identifier_observation =
        iso_identifier_observation(application_identifier_bytes);
    let application_use = pvd.get(883..1395).unwrap_or_default();
    let application_use_preview = &application_use[..application_use.len().min(256)];
    let IsoVolumeObservations {
        system_identifier_bytes_sha256,
        volume_identifier_bytes_sha256,
        volume_set_identifier_bytes_sha256,
        volume_dates,
        application_use_prefix_hex,
        ..
    } = read_iso_volume_observations(data, offset);
    let type_l_path_table_offset_bytes = path_table_ranges[0].byte_offset;
    let optional_type_l_path_table_offset_bytes = path_table_ranges[1].byte_offset;
    let type_m_path_table_offset_bytes = path_table_ranges[2].byte_offset;
    let optional_type_m_path_table_offset_bytes = path_table_ranges[3].byte_offset;
    FormatHeader::Iso9660PrimaryVolumeDescriptor {
        system_identifier_bytes_sha256,
        volume_identifier_bytes_sha256,
        type_l_path_table_offset_bytes,
        optional_type_l_path_table_offset_bytes,
        type_m_path_table_offset_bytes,
        optional_type_m_path_table_offset_bytes,
        volume_set_identifier_bytes_sha256,
        volume_dates,
        application_use_prefix_hex,
        offset,
        version: pvd.get(6).copied().unwrap_or_default(),
        system_identifier: ascii_field(pvd, 8, 32),
        volume_identifier: ascii_field(pvd, 40, 32),
        volume_space_blocks: volume_space_blocks_little_endian_observed,
        volume_space_blocks_little_endian_observed,
        volume_space_blocks_big_endian_observed,
        dual_endian_volume_space_match,
        effective_volume_space_blocks,
        volume_space_bytes,
        volume_set_size: dual_endian_volume_set_size_match
            .filter(|matches| *matches)
            .and(volume_set_size_observed)
            .filter(|size| *size > 0),
        volume_set_size_observed,
        dual_endian_volume_set_size_match,
        volume_set_sequence_relationship_valid,
        volume_sequence_number: volume_set_sequence_relationship_valid
            .filter(|valid| *valid)
            .and(volume_sequence_number_observed),
        volume_sequence_number_observed,
        dual_endian_volume_sequence_number_match,
        path_table_size,
        path_table_size_little_endian_observed,
        path_table_size_big_endian_observed,
        dual_endian_path_table_size_match,
        type_l_path_table_location,
        optional_type_l_path_table_location_observed,
        optional_type_l_path_table_location,
        type_m_path_table_location,
        optional_type_m_path_table_location_observed,
        optional_type_m_path_table_location,
        path_table_ranges,
        root_directory_record: Box::new(root_directory_record),
        logical_block_size: logical_block_size_little_endian_observed,
        logical_block_size_little_endian_observed,
        logical_block_size_big_endian_observed,
        dual_endian_logical_block_size_match,
        effective_logical_block_size,
        logical_block_size_valid,
        volume_set_identifier: ascii_field(pvd, 190, 128),
        publisher_identifier_kind: iso_identifier_kind(publisher_identifier_bytes),
        preparer_identifier_kind: iso_identifier_kind(preparer_identifier_bytes),
        application_identifier_kind: iso_identifier_kind(application_identifier_bytes),
        publisher_identifier,
        publisher_identifier_observation,
        preparer_identifier,
        preparer_identifier_observation,
        application_identifier,
        application_identifier_observation,
        copyright_file_identifier: ascii_field(pvd, 702, 37),
        abstract_file_identifier: ascii_field(pvd, 739, 37),
        bibliographic_file_identifier: ascii_field(pvd, 776, 37),
        creation_timestamp_raw_hex: pvd.get(813..830).map(hex),
        modification_timestamp_raw_hex: pvd.get(830..847).map(hex),
        expiration_timestamp_raw_hex: pvd.get(847..864).map(hex),
        effective_timestamp_raw_hex: pvd.get(864..881).map(hex),
        file_structure_version: pvd.get(881).copied(),
        application_use_observed_byte_length: application_use.len(),
        application_use_sha256: hex(&Sha256::digest(application_use)),
        application_use_preview_hex: hex(application_use_preview),
        application_use_preview_truncated: application_use_preview.len() < application_use.len(),
    }
}

fn parse_iso_root_directory_record(
    pvd: &[u8],
    logical_block_size: Option<u16>,
    volume_space_bytes: Option<u64>,
    descriptor_volume_sequence_number: Option<u16>,
) -> IsoRootDirectoryRecord {
    let record = pvd.get(156..190);
    let record_length = record.and_then(|record| record.first().copied());
    let extended_attribute_record_length_blocks = record.and_then(|record| record.get(1).copied());
    let extent_location_little_endian_observed = record
        .and_then(|record| record.get(2..6))
        .map(|bytes| u32::from_le_bytes(bytes.try_into().expect("four-byte extent location")));
    let extent_location_big_endian_observed = record
        .and_then(|record| record.get(6..10))
        .map(|bytes| u32::from_be_bytes(bytes.try_into().expect("four-byte extent location")));
    let dual_endian_extent_location_match = extent_location_little_endian_observed
        .zip(extent_location_big_endian_observed)
        .map(|(little, big)| little == big);
    let effective_extent_location = dual_endian_extent_location_match
        .filter(|matches| *matches)
        .and(extent_location_little_endian_observed);
    let data_length_little_endian_observed = record
        .and_then(|record| record.get(10..14))
        .map(|bytes| u32::from_le_bytes(bytes.try_into().expect("four-byte data length")));
    let data_length_big_endian_observed = record
        .and_then(|record| record.get(14..18))
        .map(|bytes| u32::from_be_bytes(bytes.try_into().expect("four-byte data length")));
    let dual_endian_data_length_match = data_length_little_endian_observed
        .zip(data_length_big_endian_observed)
        .map(|(little, big)| little == big);
    let effective_data_length = dual_endian_data_length_match
        .filter(|matches| *matches)
        .and(data_length_little_endian_observed);
    let volume_sequence_number_little_endian_observed = record
        .and_then(|record| record.get(28..30))
        .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]]));
    let volume_sequence_number_big_endian_observed = record
        .and_then(|record| record.get(30..32))
        .map(|bytes| u16::from_be_bytes([bytes[0], bytes[1]]));
    let dual_endian_volume_sequence_number_match = volume_sequence_number_little_endian_observed
        .zip(volume_sequence_number_big_endian_observed)
        .map(|(little, big)| little == big);
    let effective_volume_sequence_number = dual_endian_volume_sequence_number_match
        .filter(|matches| *matches)
        .and(volume_sequence_number_little_endian_observed);
    let descriptor_volume_sequence_matches = effective_volume_sequence_number
        .zip(descriptor_volume_sequence_number)
        .map(|(record, descriptor)| record == descriptor);
    let file_flags = record.and_then(|record| record.get(25).copied());
    let is_directory = file_flags.map(|flags| flags & 0x02 != 0);
    // Bits 2, 3, and 5-7 are reserved in a directory record.
    let directory_reserved_flag_bits_valid = file_flags.map(|flags| flags & 0xec == 0);
    let protection_flag_requires_extended_attributes_valid = file_flags
        .zip(extended_attribute_record_length_blocks)
        .map(|(flags, attribute_blocks)| flags & 0x10 == 0 || attribute_blocks > 0);
    let file_unit_size = record.and_then(|record| record.get(26).copied());
    let interleave_gap_size = record.and_then(|record| record.get(27).copied());
    let interleave_fields_valid = file_unit_size
        .zip(interleave_gap_size)
        .map(|(unit_size, gap_size)| unit_size == 0 && gap_size == 0);
    let file_identifier_length = record.and_then(|record| record.get(32).copied());
    let file_identifier_byte = record.and_then(|record| record.get(33).copied());
    let root_identifier_valid = file_identifier_length
        .zip(file_identifier_byte)
        .map(|(length, identifier)| length == 1 && identifier == 0);
    let record_length_valid = record_length.map(|length| length == 34);
    let record_structure_valid = record_length_valid
        .zip(is_directory)
        .zip(directory_reserved_flag_bits_valid)
        .zip(protection_flag_requires_extended_attributes_valid)
        .zip(interleave_fields_valid)
        .zip(root_identifier_valid)
        .map(
            |(
                ((((length_valid, directory), reserved_valid), protection_valid), interleave_valid),
                identifier_valid,
            )| {
                length_valid
                    && directory
                    && reserved_valid
                    && protection_valid
                    && interleave_valid
                    && identifier_valid
            },
        );

    let mut extent_start_byte_offset = None;
    let mut extent_start_sector_aligned = None;
    let mut data_start_byte_offset = None;
    let mut data_end_byte_exclusive = None;
    let mut within_declared_volume = None;
    let extent_status = if record_structure_valid == Some(false) {
        "invalid_record_structure"
    } else if descriptor_volume_sequence_matches == Some(false) {
        "different_volume"
    } else if record_structure_valid != Some(true)
        || descriptor_volume_sequence_matches != Some(true)
        || effective_extent_location.is_none()
        || effective_data_length.is_none()
        || extended_attribute_record_length_blocks.is_none()
        || logical_block_size.is_none()
        || volume_space_bytes.is_none()
    {
        "unavailable"
    } else {
        let calculated_extent_start = effective_extent_location.and_then(|location| {
            u64::from(logical_block_size.unwrap()).checked_mul(u64::from(location))
        });
        let start_block = effective_extent_location.and_then(|location| {
            u64::from(location)
                .checked_add(u64::from(extended_attribute_record_length_blocks.unwrap()))
        });
        let calculated_start =
            start_block.and_then(|block| u64::from(logical_block_size.unwrap()).checked_mul(block));
        let calculated_end = calculated_start
            .and_then(|start| start.checked_add(u64::from(effective_data_length.unwrap())));
        match (
            calculated_extent_start,
            calculated_start,
            calculated_end,
            volume_space_bytes,
        ) {
            (Some(extent_start), Some(start), Some(end), Some(volume_bytes)) => {
                extent_start_byte_offset = Some(extent_start);
                let aligned = extent_start % ISO_LOGICAL_SECTOR_SIZE_BYTES == 0;
                extent_start_sector_aligned = Some(aligned);
                data_start_byte_offset = Some(start);
                data_end_byte_exclusive = Some(end);
                let within = end <= volume_bytes;
                within_declared_volume = Some(within);
                if within {
                    if aligned {
                        "within_declared_volume"
                    } else {
                        "misaligned_extent_start"
                    }
                } else {
                    "outside_declared_volume"
                }
            }
            _ => "arithmetic_overflow",
        }
    };

    let IsoRootDirectoryObservations {
        available,
        extended_attribute_record_length,
        extent_location,
        data_length,
        recording_date_bytes_hex,
        directory_flag_set,
        volume_sequence_number,
        file_identifier_hex,
        is_root_directory_identifier,
        ..
    } = read_iso_root_observations(pvd);
    IsoRootDirectoryRecord {
        available,
        extended_attribute_record_length,
        extent_location,
        extent_offset_bytes: extent_start_byte_offset,
        data_length,
        recording_date_bytes_hex,
        directory_flag_set,
        reserved_flags_clear: directory_reserved_flag_bits_valid,
        volume_sequence_number,
        file_identifier_hex,
        is_root_directory_identifier,
        basic_structure_checks_pass: record_structure_valid == Some(true)
            && dual_endian_extent_location_match == Some(true)
            && dual_endian_data_length_match == Some(true)
            && dual_endian_volume_sequence_number_match == Some(true),
        record_length,
        record_length_valid,
        extended_attribute_record_length_blocks,
        extent_location_little_endian_observed,
        extent_location_big_endian_observed,
        dual_endian_extent_location_match,
        effective_extent_location,
        data_length_little_endian_observed,
        data_length_big_endian_observed,
        dual_endian_data_length_match,
        effective_data_length,
        volume_sequence_number_little_endian_observed,
        volume_sequence_number_big_endian_observed,
        dual_endian_volume_sequence_number_match,
        effective_volume_sequence_number,
        descriptor_volume_sequence_matches,
        file_flags,
        is_directory,
        directory_reserved_flag_bits_valid,
        protection_flag_requires_extended_attributes_valid,
        file_unit_size,
        interleave_gap_size,
        interleave_fields_valid,
        file_identifier_length,
        file_identifier_byte,
        root_identifier_valid,
        record_structure_valid,
        extent_start_byte_offset,
        extent_start_sector_aligned,
        data_start_byte_offset,
        data_end_byte_exclusive,
        within_declared_volume,
        extent_status,
    }
}

fn iso_path_table_range(
    table_type: &'static str,
    optional: bool,
    location_logical_block: Option<u32>,
    path_table_size: Option<u32>,
    logical_block_size: Option<u16>,
    volume_space_bytes: Option<u64>,
) -> IsoPathTableRange {
    if optional && location_logical_block == Some(0) {
        return IsoPathTableRange {
            table_type,
            optional,
            location_logical_block,
            byte_offset: None,
            byte_end_exclusive: None,
            within_declared_volume: None,
            status: "absent",
        };
    }
    let Some(location) = location_logical_block else {
        return IsoPathTableRange {
            table_type,
            optional,
            location_logical_block,
            byte_offset: None,
            byte_end_exclusive: None,
            within_declared_volume: None,
            status: "unavailable",
        };
    };
    let Some((size, block_size, volume_bytes)) = path_table_size
        .zip(logical_block_size)
        .zip(volume_space_bytes)
        .map(|((size, block_size), volume_bytes)| (size, block_size, volume_bytes))
    else {
        return IsoPathTableRange {
            table_type,
            optional,
            location_logical_block,
            byte_offset: None,
            byte_end_exclusive: None,
            within_declared_volume: None,
            status: "unavailable",
        };
    };
    let Some(byte_offset) = u64::from(location).checked_mul(u64::from(block_size)) else {
        return IsoPathTableRange {
            table_type,
            optional,
            location_logical_block,
            byte_offset: None,
            byte_end_exclusive: None,
            within_declared_volume: None,
            status: "arithmetic_overflow",
        };
    };
    let Some(byte_end_exclusive) = byte_offset.checked_add(u64::from(size)) else {
        return IsoPathTableRange {
            table_type,
            optional,
            location_logical_block,
            byte_offset: Some(byte_offset),
            byte_end_exclusive: None,
            within_declared_volume: None,
            status: "arithmetic_overflow",
        };
    };
    let within_declared_volume = byte_end_exclusive <= volume_bytes;
    IsoPathTableRange {
        table_type,
        optional,
        location_logical_block,
        byte_offset: Some(byte_offset),
        byte_end_exclusive: Some(byte_end_exclusive),
        within_declared_volume: Some(within_declared_volume),
        status: if within_declared_volume {
            "within_declared_volume"
        } else {
            "outside_declared_volume"
        },
    }
}

fn iso_identifier_kind(identifier: &[u8]) -> &'static str {
    if identifier.len() != 128 {
        "unavailable"
    } else if identifier.iter().all(|byte| *byte == b' ') {
        "unspecified"
    } else if identifier.first() == Some(&b'_') {
        "root-directory-file-reference"
    } else {
        "inline"
    }
}

fn iso_identifier_observation(identifier: &[u8]) -> IsoIdentifierObservation {
    IsoIdentifierObservation {
        observed_byte_length: identifier.len(),
        complete: identifier.len() == 128,
        raw_bytes_hex: hex(identifier),
        raw_sha256: hex(&Sha256::digest(identifier)),
        first_byte_hex: identifier
            .first()
            .map(|byte| hex(std::slice::from_ref(byte))),
        all_space_filler: identifier.len() == 128 && identifier.iter().all(|byte| *byte == b' '),
        contains_nul: identifier.contains(&0),
        a_character_bytes_valid: identifier.len() == 128
            && identifier.iter().all(|byte| {
                (0x20..=0x22).contains(byte)
                    || (0x25..=0x3f).contains(byte)
                    || (0x41..=0x5a).contains(byte)
                    || *byte == 0x5f
            }),
        reference_marker_at_first_byte: identifier.first() == Some(&b'_'),
    }
}

fn ascii_field(data: &[u8], offset: usize, length: usize) -> String {
    let Some(field) = data.get(offset..offset.saturating_add(length)) else {
        return String::new();
    };
    String::from_utf8_lossy(field)
        .trim_matches(|character: char| character == '\0' || character == ' ')
        .to_owned()
}

fn le_u32(data: &[u8], offset: usize) -> u32 {
    u32::from_le_bytes(
        data[offset..offset + 4]
            .try_into()
            .expect("validated four-byte field"),
    )
}

fn le_u64(data: &[u8], offset: usize) -> u64 {
    u64::from_le_bytes(
        data[offset..offset + 8]
            .try_into()
            .expect("validated eight-byte field"),
    )
}

#[derive(Clone, Debug, Serialize)]
pub struct ZipExtraField {
    pub record_index: usize,
    pub header_id: Option<u16>,
    pub header_id_hex: Option<String>,
    pub offset_in_extra_area: usize,
    pub declared_payload_length: Option<usize>,
    pub observed_payload_length: usize,
    pub payload_sha256: Option<String>,
    pub payload_prefix_hex: Option<String>,
    pub payload_prefix_truncated: Option<bool>,
    pub complete: bool,
}

#[derive(Clone, Debug, Serialize)]
pub struct ZipAesExtra {
    pub record_index: usize,
    pub payload_length: usize,
    pub vendor_version: Option<u16>,
    pub vendor_id: Option<String>,
    pub encryption_strength: Option<u8>,
    pub key_bits: Option<u16>,
    pub actual_compression_method: Option<u16>,
    pub vendor_id_is_ae: Option<bool>,
    pub local_encryption_flag_set: Option<bool>,
    pub local_compression_method_is_99: Option<bool>,
    pub local_indicators_match_aes_record: Option<bool>,
    pub ae2_local_header_crc32_is_zero: Option<bool>,
    pub expected_payload_length: usize,
    pub payload_has_required_fields: bool,
}

#[derive(Clone, Debug, Serialize)]
pub struct ZipUnicodePathExtra {
    pub record_index: usize,
    pub payload_length: usize,
    pub version: Option<u8>,
    pub saved_filename_crc32: Option<u32>,
    pub observed_filename_crc32: Option<u32>,
    pub filename_crc32_matches: Option<bool>,
    pub unicode_filename_bytes_length: Option<usize>,
    pub unicode_filename_bytes_sha256: Option<String>,
    pub unicode_filename_prefix_hex: Option<String>,
    pub unicode_filename_prefix_truncated: Option<bool>,
    pub unicode_filename_utf8_valid: Option<bool>,
    pub unicode_filename: Option<String>,
    pub unicode_filename_display_truncated: Option<bool>,
    pub unicode_filename_bom_present: Option<bool>,
    pub unicode_filename_matches_efs_filename: Option<bool>,
    pub usable_as_filename_override: bool,
    pub payload_has_required_fields: bool,
}

#[derive(Clone, Debug, Serialize)]
pub struct ZipExtendedTimestamp {
    pub record_index: usize,
    pub payload_length: usize,
    pub flags: Option<u8>,
    pub modification_time_unix_seconds: Option<u32>,
    pub access_time_unix_seconds: Option<u32>,
    pub creation_time_unix_seconds: Option<u32>,
    pub declared_time_fields_mask: Option<u8>,
    pub available_time_fields_mask: u8,
    pub missing_declared_time_fields_mask: Option<u8>,
    pub unknown_flag_bits: Option<u8>,
    pub trailing_payload_length: usize,
    pub payload_has_flag_byte: bool,
    pub declared_time_fields_complete: Option<bool>,
}

#[derive(Clone, Debug, Serialize)]
pub struct IsoVolumeDates {
    pub creation_raw_bytes_hex: String,
    pub creation_ascii_digits_valid: bool,
    pub creation_unspecified: bool,
    pub creation_timezone_quarters: Option<i8>,
    pub modification_raw_bytes_hex: String,
    pub modification_ascii_digits_valid: bool,
    pub modification_unspecified: bool,
    pub modification_timezone_quarters: Option<i8>,
    pub expiration_raw_bytes_hex: String,
    pub expiration_ascii_digits_valid: bool,
    pub expiration_unspecified: bool,
    pub expiration_timezone_quarters: Option<i8>,
    pub effective_raw_bytes_hex: String,
    pub effective_ascii_digits_valid: bool,
    pub effective_unspecified: bool,
    pub effective_timezone_quarters: Option<i8>,
}

#[derive(Clone, Debug, Serialize)]
pub struct Rar5LocatorRecord {
    pub record_size: u64,
    pub flags: Option<u64>,
    pub quick_open_offset_relative_to_main_header: Option<u64>,
    pub quick_open_target_sample_offset: Option<u64>,
    pub recovery_record_offset_relative_to_main_header: Option<u64>,
    pub recovery_record_target_sample_offset: Option<u64>,
    pub record_fields_complete: bool,
}

#[derive(Clone, Debug, Serialize)]
pub struct Rar5ArchiveMetadata {
    pub record_size: u64,
    pub flags: Option<u64>,
    pub archive_name: Option<String>,
    pub archive_name_bytes_hex: Option<String>,
    pub archive_name_bytes_length: Option<usize>,
    pub archive_name_bytes_sha256: Option<String>,
    pub archive_name_declared_length: Option<usize>,
    pub archive_name_buffer_sha256: Option<String>,
    pub archive_name_hex_truncated: Option<bool>,
    pub archive_name_display_truncated: Option<bool>,
    pub archive_name_utf8_valid: Option<bool>,
    pub archive_creation_time_raw: Option<u64>,
    pub archive_creation_time_encoding: Option<&'static str>,
    pub record_fields_complete: bool,
}

#[derive(Debug)]
struct ZipExtraInspection<'a> {
    records: Vec<ZipExtraField>,
    record_count: usize,
    records_truncated: bool,
    records_complete: bool,
    zip64_payload: Option<&'a [u8]>,
    zip64_present: bool,
    zip64_record_count: usize,
    zip64_duplicate: bool,
    aes_records: Vec<ZipAesExtra>,
    aes_record_count: usize,
    extended_timestamp_records: Vec<ZipExtendedTimestamp>,
    extended_timestamp_record_count: usize,
    unicode_path_records: Vec<ZipUnicodePathExtra>,
    unicode_path_record_count: usize,
}

#[derive(Debug, Serialize)]
struct IsoRootDirectoryObservations {
    pub available: bool,
    pub record_length: Option<u8>,
    pub extended_attribute_record_length: Option<u8>,
    pub extent_location: Option<u32>,
    pub extent_offset_bytes: Option<u64>,
    pub dual_endian_extent_location_match: Option<bool>,
    pub data_length: Option<u32>,
    pub dual_endian_data_length_match: Option<bool>,
    pub recording_date_bytes_hex: Option<String>,
    pub file_flags: Option<u8>,
    pub directory_flag_set: Option<bool>,
    pub reserved_flags_clear: Option<bool>,
    pub file_unit_size: Option<u8>,
    pub interleave_gap_size: Option<u8>,
    pub volume_sequence_number: Option<u16>,
    pub dual_endian_volume_sequence_number_match: Option<bool>,
    pub file_identifier_length: Option<u8>,
    pub file_identifier_hex: Option<String>,
    pub is_root_directory_identifier: Option<bool>,
    pub basic_structure_checks_pass: bool,
}

#[derive(Debug, Serialize)]
struct ZipLocalObservations {
    offset: usize,
    local_file_header_signature: bool,
    local_header_available: bool,
    structure_valid: bool,
    version_needed: Option<u16>,
    flags: Option<u16>,
    compression_method: Option<u16>,
    modification_time_dos: Option<u16>,
    modification_date_dos: Option<u16>,
    crc32: Option<u32>,
    compressed_size: Option<u32>,
    uncompressed_size: Option<u32>,
    zip64_uncompressed_size: Option<u64>,
    zip64_compressed_size: Option<u64>,
    resolved_uncompressed_size: Option<u64>,
    resolved_uncompressed_size_source: Option<&'static str>,
    resolved_compressed_size: Option<u64>,
    resolved_compressed_size_source: Option<&'static str>,
    filename_bytes_hex: Option<String>,
    filename_bytes_sha256: Option<String>,
    filename_hex_truncated: Option<bool>,
    filename_utf8_valid: Option<bool>,
    filename_bytes_length: Option<usize>,
    filename_utf8_flag_set: Option<bool>,
    filename_encoding_hint: Option<&'static str>,
    extra_field_bytes_length: Option<usize>,
    extra_field_bytes_sha256: Option<String>,
    extra_field_prefix_hex: Option<String>,
    extra_field_hex_truncated: Option<bool>,
    extra_field_records: Option<Vec<ZipExtraField>>,
    extra_field_record_count: Option<usize>,
    extra_field_records_truncated: Option<bool>,
    extra_field_records_complete: Option<bool>,
    zip64_extra_payload_length: Option<usize>,
    data_descriptor_follows: Option<bool>,
    zip64_extra_present: Option<bool>,
    zip64_extra_record_count: Option<usize>,
    zip64_extra_duplicate: Option<bool>,
    zip64_extra_valid: Option<bool>,
    zip64_size_sentinel_present: Option<bool>,
    zip_aes_records: Option<Vec<ZipAesExtra>>,
    zip_aes_record_count: Option<usize>,
    zip_aes_records_truncated: Option<bool>,
    zip_aes_record_count_matches_encryption_indicators: Option<bool>,
    zip_extended_timestamp_records: Option<Vec<ZipExtendedTimestamp>>,
    zip_extended_timestamp_record_count: Option<usize>,
    zip_extended_timestamp_records_truncated: Option<bool>,
    zip_unicode_path_records: Option<Vec<ZipUnicodePathExtra>>,
    zip_unicode_path_record_count: Option<usize>,
    zip_unicode_path_records_truncated: Option<bool>,
}

fn read_zip_local_observations(
    data: &[u8],
    offset: usize,
    size_metadata: &Zip64SizeMetadata,
) -> ZipLocalObservations {
    let header_start = offset.checked_add(ZIP.len());
    let fixed_header =
        header_start.and_then(|start| start.checked_add(26).and_then(|end| data.get(start..end)));
    let version_needed = fixed_header.map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]]));
    let flags = fixed_header.map(|bytes| u16::from_le_bytes([bytes[2], bytes[3]]));
    let compression_method = fixed_header.map(|bytes| u16::from_le_bytes([bytes[4], bytes[5]]));
    let modification_time_dos = fixed_header.map(|bytes| u16::from_le_bytes([bytes[6], bytes[7]]));
    let modification_date_dos = fixed_header.map(|bytes| u16::from_le_bytes([bytes[8], bytes[9]]));
    let crc32 = fixed_header.map(|bytes| le_u32(bytes, 10));
    let compressed_size = fixed_header.map(|bytes| le_u32(bytes, 14));
    let uncompressed_size = fixed_header.map(|bytes| le_u32(bytes, 18));
    let filename_length =
        fixed_header.map(|bytes| u16::from_le_bytes([bytes[22], bytes[23]]) as usize);
    let extra_length =
        fixed_header.map(|bytes| u16::from_le_bytes([bytes[24], bytes[25]]) as usize);
    let variable_fields = header_start
        .and_then(|start| start.checked_add(26))
        .zip(filename_length)
        .zip(extra_length)
        .and_then(|((start, filename_len), extra_len)| {
            let filename_end = start.checked_add(filename_len)?;
            let extra_end = filename_end.checked_add(extra_len)?;
            Some((
                data.get(start..filename_end)?,
                data.get(filename_end..extra_end)?,
            ))
        });
    let filename = variable_fields.map(|(name, _)| name);
    let extra = variable_fields.map(|(_, extra)| extra);
    let extra_inspection = extra
        .map(|bytes| inspect_zip_extra_fields(bytes, filename, flags, compression_method, crc32));
    let zip64_extra_present = extra_inspection.as_ref().and_then(|inspection| {
        if inspection.zip64_present {
            Some(true)
        } else {
            inspection.records_complete.then_some(false)
        }
    });
    let zip64_extra_payload_length = extra_inspection.as_ref().and_then(|inspection| {
        (inspection.records_complete && !inspection.zip64_duplicate)
            .then_some(inspection.zip64_payload)
            .flatten()
            .map(<[u8]>::len)
    });
    let zip64_payload = extra_inspection.as_ref().and_then(|inspection| {
        (inspection.records_complete && !inspection.zip64_duplicate)
            .then_some(inspection.zip64_payload)
            .flatten()
            .filter(|payload| payload.len() >= 16)
    });
    let zip64_uncompressed_size = zip64_payload
        .and_then(|payload| payload.get(..8))
        .map(|bytes| u64::from_le_bytes(bytes.try_into().expect("bounded 8-byte slice")));
    let zip64_compressed_size = zip64_payload
        .and_then(|payload| payload.get(8..16))
        .map(|bytes| u64::from_le_bytes(bytes.try_into().expect("bounded 8-byte slice")));
    let zip64_size_sentinel_present = compressed_size
        .zip(uncompressed_size)
        .map(|(compressed, uncompressed)| compressed == u32::MAX || uncompressed == u32::MAX);
    let zip64_extra_valid = zip64_size_sentinel_present
        .zip(extra_inspection.as_ref())
        .and_then(|(has_size_sentinel, inspection)| {
            if !inspection.records_complete {
                return None;
            }
            if inspection.zip64_duplicate {
                return Some(false);
            }
            if !inspection.zip64_present {
                return Some(!has_size_sentinel);
            }
            Some(
                inspection
                    .zip64_payload
                    .is_some_and(|payload| payload.len() >= 16),
            )
        });
    let data_descriptor_follows = flags.map(|value| value & 0x0008 != 0);
    let (resolved_uncompressed_size, resolved_uncompressed_size_source) = resolve_zip_local_size(
        uncompressed_size,
        size_metadata.effective_uncompressed_size.as_deref(),
        size_metadata.status,
    );
    let (resolved_compressed_size, resolved_compressed_size_source) = resolve_zip_local_size(
        compressed_size,
        size_metadata.effective_compressed_size.as_deref(),
        size_metadata.status,
    );
    let local_header_available = fixed_header.is_some();
    let structure_valid = local_header_available
        && variable_fields.is_some()
        && extra_inspection
            .as_ref()
            .is_some_and(|inspection| inspection.records_complete)
        && zip64_extra_present.is_some()
        && zip64_extra_valid == Some(true)
        && size_metadata.status != "version-needed-below-45";

    ZipLocalObservations {
        offset,
        local_file_header_signature: true,
        local_header_available,
        structure_valid,
        version_needed,
        flags,
        compression_method,
        modification_time_dos,
        modification_date_dos,
        crc32,
        compressed_size,
        uncompressed_size,
        zip64_uncompressed_size,
        zip64_compressed_size,
        resolved_uncompressed_size,
        resolved_uncompressed_size_source,
        resolved_compressed_size,
        resolved_compressed_size_source,
        filename_bytes_hex: filename.map(|bytes| hex(&bytes[..bytes.len().min(256)])),
        filename_bytes_sha256: filename.map(sha256_hex),
        filename_hex_truncated: filename.map(|bytes| bytes.len() > 256),
        filename_utf8_valid: filename.map(|bytes| std::str::from_utf8(bytes).is_ok()),
        filename_bytes_length: filename.map(<[u8]>::len),
        filename_utf8_flag_set: flags.map(|value| value & 0x0800 != 0),
        filename_encoding_hint: flags.map(|value| {
            if value & 0x0800 != 0 {
                "utf8"
            } else {
                "cp437_or_legacy"
            }
        }),
        extra_field_bytes_length: extra.map(<[u8]>::len),
        extra_field_bytes_sha256: extra.map(sha256_hex),
        extra_field_prefix_hex: extra.map(|bytes| hex(&bytes[..bytes.len().min(128)])),
        extra_field_hex_truncated: extra.map(|bytes| bytes.len() > 128),
        extra_field_records: extra_inspection
            .as_ref()
            .map(|inspection| inspection.records.clone()),
        extra_field_record_count: extra_inspection
            .as_ref()
            .map(|inspection| inspection.record_count),
        extra_field_records_truncated: extra_inspection
            .as_ref()
            .map(|inspection| inspection.records_truncated),
        extra_field_records_complete: extra_inspection
            .as_ref()
            .map(|inspection| inspection.records_complete),
        zip64_extra_payload_length,
        data_descriptor_follows,
        zip64_extra_present,
        zip64_extra_record_count: extra_inspection
            .as_ref()
            .map(|inspection| inspection.zip64_record_count),
        zip64_extra_duplicate: extra_inspection.as_ref().and_then(|inspection| {
            if inspection.zip64_duplicate {
                Some(true)
            } else {
                inspection.records_complete.then_some(false)
            }
        }),
        zip64_extra_valid,
        zip64_size_sentinel_present,
        zip_aes_record_count: extra_inspection
            .as_ref()
            .map(|inspection| inspection.aes_record_count),
        zip_aes_record_count_matches_encryption_indicators: extra_inspection.as_ref().and_then(
            |inspection| {
                if !inspection.records_complete {
                    return None;
                }
                compression_method.zip(flags).map(|(method, flags)| {
                    if method == 99 {
                        inspection.aes_record_count == 1 && flags & 0x0001 != 0
                    } else {
                        inspection.aes_record_count == 0
                    }
                })
            },
        ),
        zip_aes_records: extra_inspection
            .as_ref()
            .map(|inspection| inspection.aes_records.clone()),
        zip_aes_records_truncated: extra_inspection
            .as_ref()
            .map(|inspection| inspection.aes_record_count > inspection.aes_records.len()),
        zip_extended_timestamp_records: extra_inspection
            .as_ref()
            .map(|inspection| inspection.extended_timestamp_records.clone()),
        zip_extended_timestamp_record_count: extra_inspection
            .as_ref()
            .map(|inspection| inspection.extended_timestamp_record_count),
        zip_extended_timestamp_records_truncated: extra_inspection.as_ref().map(|inspection| {
            inspection.extended_timestamp_record_count > inspection.extended_timestamp_records.len()
        }),
        zip_unicode_path_record_count: extra_inspection
            .as_ref()
            .map(|inspection| inspection.unicode_path_record_count),
        zip_unicode_path_records: extra_inspection
            .as_ref()
            .map(|inspection| inspection.unicode_path_records.clone()),
        zip_unicode_path_records_truncated: extra_inspection.as_ref().map(|inspection| {
            inspection.unicode_path_record_count > inspection.unicode_path_records.len()
        }),
    }
}

#[derive(Debug, Serialize)]
struct GzipLocalObservations {
    offset: usize,
    fixed_header_available: bool,
    structure_valid: bool,
    compression_method: Option<u8>,
    flags: Option<u8>,
    modification_time: Option<u32>,
    extra_flags: Option<u8>,
    operating_system: Option<u8>,
    extra_field_declared_length: Option<usize>,
    extra_field_bytes_length: Option<usize>,
    extra_field_bytes_sha256: Option<String>,
    extra_field_prefix_hex: Option<String>,
    extra_field_hex_truncated: Option<bool>,
    original_filename_bytes_hex: Option<String>,
    original_filename_bytes_length: Option<usize>,
    original_filename_bytes_sha256: Option<String>,
    original_filename_hex_truncated: Option<bool>,
    comment_bytes_hex: Option<String>,
    comment_bytes_length: Option<usize>,
    comment_bytes_sha256: Option<String>,
    comment_hex_truncated: Option<bool>,
    header_crc16_present: Option<bool>,
    header_crc16_valid: Option<bool>,
}

fn read_gzip_local_observations(data: &[u8], offset: usize) -> GzipLocalObservations {
    let fixed = offset.checked_add(10).and_then(|end| data.get(offset..end));
    let compression_method = fixed.and_then(|bytes| bytes.get(2).copied());
    let flags = fixed.and_then(|bytes| bytes.get(3).copied());
    let modification_time = fixed.map(|bytes| le_u32(bytes, 4));
    let extra_flags = fixed.and_then(|bytes| bytes.get(8).copied());
    let operating_system = fixed.and_then(|bytes| bytes.get(9).copied());
    let mut cursor = offset.saturating_add(10);
    let mut optional_fields_valid = fixed.is_some();
    let mut extra_field_declared_length = None;
    let mut extra_field = None;
    let mut original_filename = None;
    let mut comment = None;
    if let Some(flags_value) = flags {
        if flags_value & 0x04 != 0 {
            if let Some(length_bytes) = data.get(cursor..cursor.saturating_add(2)) {
                let length = u16::from_le_bytes([length_bytes[0], length_bytes[1]]) as usize;
                extra_field_declared_length = Some(length);
                cursor = cursor.saturating_add(2);
                if length <= MAX_GZIP_OPTIONAL_FIELD_BYTES {
                    if let Some(extra_end) =
                        cursor.checked_add(length).filter(|end| *end <= data.len())
                    {
                        extra_field = data.get(cursor..extra_end);
                        cursor = extra_end;
                    } else {
                        optional_fields_valid = false;
                    }
                } else {
                    optional_fields_valid = false;
                }
            } else {
                optional_fields_valid = false;
            }
        }
        if optional_fields_valid && flags_value & 0x08 != 0 {
            // Match parse_gzip_text_field's content bound plus NUL lookahead.
            match take_nul_terminated(data, cursor, MAX_GZIP_OPTIONAL_FIELD_BYTES + 1) {
                Some((field, next)) => {
                    original_filename = Some(field);
                    cursor = next;
                }
                None => optional_fields_valid = false,
            }
        }
        if optional_fields_valid && flags_value & 0x10 != 0 {
            match take_nul_terminated(data, cursor, MAX_GZIP_OPTIONAL_FIELD_BYTES + 1) {
                Some((field, next)) => {
                    comment = Some(field);
                    cursor = next;
                }
                None => optional_fields_valid = false,
            }
        }
    }
    let header_crc16_present = flags.map(|value| value & 0x02 != 0);
    let header_crc16_valid = if header_crc16_present == Some(true) && optional_fields_valid {
        let checksum_bytes = data.get(cursor..cursor.saturating_add(2));
        checksum_bytes.map(|bytes| {
            let expected = u16::from_le_bytes([bytes[0], bytes[1]]);
            let calculated = crc32fast::hash(&data[offset..cursor]) as u16;
            expected == calculated
        })
    } else {
        None
    };
    if header_crc16_present == Some(true) && header_crc16_valid.is_none() {
        optional_fields_valid = false;
    }
    let reserved_flags_valid = flags.is_some_and(|value| value & 0xe0 == 0);

    GzipLocalObservations {
        offset,
        fixed_header_available: fixed.is_some(),
        structure_valid: fixed.is_some()
            && optional_fields_valid
            && compression_method == Some(8)
            && reserved_flags_valid,
        compression_method,
        flags,
        modification_time,
        extra_flags,
        operating_system,
        extra_field_declared_length,
        extra_field_bytes_length: extra_field.map(<[u8]>::len),
        extra_field_bytes_sha256: extra_field.map(sha256_hex),
        extra_field_prefix_hex: extra_field.map(|bytes| hex(&bytes[..bytes.len().min(128)])),
        extra_field_hex_truncated: extra_field.map(|bytes| bytes.len() > 128),
        original_filename_bytes_hex: original_filename
            .map(|bytes| hex(&bytes[..bytes.len().min(256)])),
        original_filename_bytes_length: original_filename.map(<[u8]>::len),
        original_filename_bytes_sha256: original_filename.map(sha256_hex),
        original_filename_hex_truncated: original_filename.map(|bytes| bytes.len() > 256),
        comment_bytes_hex: comment.map(|bytes| hex(&bytes[..bytes.len().min(256)])),
        comment_bytes_length: comment.map(<[u8]>::len),
        comment_bytes_sha256: comment.map(sha256_hex),
        comment_hex_truncated: comment.map(|bytes| bytes.len() > 256),
        header_crc16_present,
        header_crc16_valid,
    }
}

#[derive(Debug, Serialize)]
struct XzLocalObservations {
    offset: usize,
    stream_header_available: bool,
    structure_valid: bool,
    stream_flags_hex: Option<String>,
    check_id: Option<u8>,
    check_name: Option<&'static str>,
    check_id_supported: Option<bool>,
    reserved_flags_valid: Option<bool>,
    header_crc32: Option<u32>,
    calculated_header_crc32: Option<u32>,
    header_crc32_valid: Option<bool>,
}

fn read_xz_local_observations(data: &[u8], offset: usize) -> XzLocalObservations {
    let header = offset.checked_add(12).and_then(|end| data.get(offset..end));
    let stream_flags = header.and_then(|bytes| bytes.get(6..8));
    let stream_flags_hex = stream_flags.map(hex);
    let check_id = stream_flags.and_then(|bytes| bytes.get(1).copied());
    let check_name = check_id.and_then(|id| match id {
        0 => Some("none"),
        1 => Some("crc32"),
        4 => Some("crc64"),
        10 => Some("sha256"),
        _ => None,
    });
    let reserved_flags_valid = stream_flags.map(|bytes| bytes[0] == 0 && bytes[1] & 0xf0 == 0);
    let check_id_supported = check_id.map(|id| check_name.is_some() && id <= 15);
    let header_crc32 = header.map(|bytes| le_u32(bytes, 8));
    let calculated_header_crc32 = stream_flags.map(crc32fast::hash);
    let header_crc32_valid = header_crc32
        .zip(calculated_header_crc32)
        .map(|(expected, calculated)| expected == calculated);

    XzLocalObservations {
        offset,
        stream_header_available: header.is_some(),
        structure_valid: header.is_some() && reserved_flags_valid == Some(true),
        stream_flags_hex,
        check_id,
        check_name,
        check_id_supported,
        reserved_flags_valid,
        header_crc32,
        calculated_header_crc32,
        header_crc32_valid,
    }
}

#[derive(Debug, Serialize)]
struct IsoVolumeObservations {
    offset: usize,
    version: u8,
    system_identifier: String,
    system_identifier_bytes_sha256: String,
    volume_identifier: String,
    volume_identifier_bytes_sha256: String,
    volume_space_blocks: Option<u32>,
    dual_endian_volume_space_match: Option<bool>,
    volume_space_bytes: Option<u64>,
    volume_set_size: Option<u16>,
    dual_endian_volume_set_size_match: Option<bool>,
    volume_sequence_number: Option<u16>,
    dual_endian_volume_sequence_number_match: Option<bool>,
    logical_block_size: Option<u16>,
    dual_endian_logical_block_size_match: Option<bool>,
    path_table_size: Option<u32>,
    dual_endian_path_table_size_match: Option<bool>,
    type_l_path_table_location: Option<u32>,
    type_l_path_table_offset_bytes: Option<u64>,
    optional_type_l_path_table_location: Option<u32>,
    optional_type_l_path_table_offset_bytes: Option<u64>,
    type_m_path_table_location: Option<u32>,
    type_m_path_table_offset_bytes: Option<u64>,
    optional_type_m_path_table_location: Option<u32>,
    optional_type_m_path_table_offset_bytes: Option<u64>,
    root_directory_record: IsoRootDirectoryObservations,
    volume_set_identifier: String,
    volume_set_identifier_bytes_sha256: String,
    publisher_identifier: String,
    preparer_identifier: String,
    application_identifier: String,
    copyright_file_identifier: String,
    abstract_file_identifier: String,
    bibliographic_file_identifier: String,
    volume_dates: IsoVolumeDates,
    file_structure_version: Option<u8>,
    application_use_sha256: String,
    application_use_prefix_hex: String,
}

fn read_iso_volume_observations(data: &[u8], offset: usize) -> IsoVolumeObservations {
    let pvd = &data[offset..offset + 2048];
    IsoVolumeObservations {
        offset,
        version: pvd.get(6).copied().unwrap_or_default(),
        system_identifier: ascii_field(pvd, 8, 32),
        system_identifier_bytes_sha256: sha256_hex(pvd.get(8..40).unwrap_or_default()),
        volume_identifier: ascii_field(pvd, 40, 32),
        volume_identifier_bytes_sha256: sha256_hex(pvd.get(40..72).unwrap_or_default()),
        volume_space_blocks: pvd
            .get(80..84)
            .map(|bytes| u32::from_le_bytes(bytes.try_into().expect("four-byte ISO field"))),
        dual_endian_volume_space_match: pvd.get(80..88).map(|bytes| {
            u32::from_le_bytes(bytes[0..4].try_into().expect("four-byte ISO field"))
                == u32::from_be_bytes(bytes[4..8].try_into().expect("four-byte ISO field"))
        }),
        volume_space_bytes: iso_block_offset(
            pvd.get(80..84)
                .map(|bytes| u32::from_le_bytes(bytes.try_into().expect("four-byte ISO field"))),
            pvd.get(128..130)
                .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]])),
        ),
        volume_set_size: pvd
            .get(120..122)
            .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]])),
        dual_endian_volume_set_size_match: pvd.get(120..124).map(|bytes| {
            u16::from_le_bytes([bytes[0], bytes[1]]) == u16::from_be_bytes([bytes[2], bytes[3]])
        }),
        volume_sequence_number: pvd
            .get(124..126)
            .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]])),
        dual_endian_volume_sequence_number_match: pvd.get(124..128).map(|bytes| {
            u16::from_le_bytes([bytes[0], bytes[1]]) == u16::from_be_bytes([bytes[2], bytes[3]])
        }),
        logical_block_size: pvd
            .get(128..130)
            .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]])),
        dual_endian_logical_block_size_match: pvd.get(128..132).map(|bytes| {
            u16::from_le_bytes([bytes[0], bytes[1]]) == u16::from_be_bytes([bytes[2], bytes[3]])
        }),
        path_table_size: pvd
            .get(132..136)
            .map(|bytes| u32::from_le_bytes(bytes.try_into().expect("four-byte ISO field"))),
        dual_endian_path_table_size_match: pvd.get(132..140).map(|bytes| {
            u32::from_le_bytes(bytes[0..4].try_into().expect("four-byte ISO field"))
                == u32::from_be_bytes(bytes[4..8].try_into().expect("four-byte ISO field"))
        }),
        type_l_path_table_location: pvd
            .get(140..144)
            .map(|bytes| u32::from_le_bytes(bytes.try_into().expect("four-byte ISO field"))),
        type_l_path_table_offset_bytes: iso_block_offset(
            pvd.get(140..144)
                .map(|bytes| u32::from_le_bytes(bytes.try_into().expect("four-byte ISO field"))),
            pvd.get(128..130)
                .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]])),
        ),
        optional_type_l_path_table_location: pvd
            .get(144..148)
            .map(|bytes| u32::from_le_bytes(bytes.try_into().expect("four-byte ISO field"))),
        optional_type_l_path_table_offset_bytes: iso_block_offset(
            pvd.get(144..148)
                .map(|bytes| u32::from_le_bytes(bytes.try_into().expect("four-byte ISO field"))),
            pvd.get(128..130)
                .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]])),
        ),
        type_m_path_table_location: pvd
            .get(148..152)
            .map(|bytes| u32::from_be_bytes(bytes.try_into().expect("four-byte ISO field"))),
        type_m_path_table_offset_bytes: iso_block_offset(
            pvd.get(148..152)
                .map(|bytes| u32::from_be_bytes(bytes.try_into().expect("four-byte ISO field"))),
            pvd.get(128..130)
                .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]])),
        ),
        optional_type_m_path_table_location: pvd
            .get(152..156)
            .map(|bytes| u32::from_be_bytes(bytes.try_into().expect("four-byte ISO field"))),
        optional_type_m_path_table_offset_bytes: iso_block_offset(
            pvd.get(152..156)
                .map(|bytes| u32::from_be_bytes(bytes.try_into().expect("four-byte ISO field"))),
            pvd.get(128..130)
                .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]])),
        ),
        root_directory_record: read_iso_root_observations(pvd),
        volume_set_identifier: ascii_field(pvd, 190, 128),
        volume_set_identifier_bytes_sha256: sha256_hex(pvd.get(190..318).unwrap_or_default()),
        publisher_identifier: ascii_field(pvd, 318, 128),
        preparer_identifier: ascii_field(pvd, 446, 128),
        application_identifier: ascii_field(pvd, 574, 128),
        copyright_file_identifier: ascii_field(pvd, 702, 37),
        abstract_file_identifier: ascii_field(pvd, 739, 37),
        bibliographic_file_identifier: ascii_field(pvd, 776, 37),
        volume_dates: parse_iso_volume_dates(pvd),
        file_structure_version: pvd.get(881).copied(),
        application_use_sha256: sha256_hex(pvd.get(883..1395).unwrap_or_default()),
        application_use_prefix_hex: hex_prefix(pvd.get(883..1395).unwrap_or_default(), 64),
    }
}

fn read_rar5_main_extra_observations(
    data: &[u8],
    start: usize,
    end: usize,
    main_header_offset: usize,
) -> (
    Vec<u64>,
    Vec<Rar5LocatorRecord>,
    Vec<Rar5ArchiveMetadata>,
    bool,
) {
    let mut cursor = start;
    let mut record_types = Vec::new();
    let mut locator_records = Vec::new();
    let mut metadata_records = Vec::new();
    let mut complete = true;

    while cursor < end && record_types.len() < MAX_RAR5_MAIN_EXTRA_RECORDS {
        let Some(record_size) = read_vint_bounded(data, &mut cursor, end) else {
            complete = false;
            break;
        };
        let Some(record_end) = usize::try_from(record_size)
            .ok()
            .and_then(|size| cursor.checked_add(size))
            .filter(|record_end| *record_end <= end && *record_end > cursor)
        else {
            complete = false;
            break;
        };
        let Some(record_type) = read_vint_bounded(data, &mut cursor, record_end) else {
            complete = false;
            break;
        };
        record_types.push(record_type);
        match record_type {
            1 => {
                let locator = read_rar5_locator_observation(
                    data,
                    cursor,
                    record_end,
                    record_size,
                    main_header_offset,
                );
                complete &= locator.record_fields_complete;
                locator_records.push(locator);
            }
            2 => {
                let metadata =
                    read_rar5_archive_metadata_observation(data, cursor, record_end, record_size);
                complete &= metadata.record_fields_complete;
                metadata_records.push(metadata);
            }
            _ => {}
        }
        cursor = record_end;
    }
    if cursor < end {
        complete = false;
    }

    (record_types, locator_records, metadata_records, complete)
}

fn read_rar5_locator_observation(
    data: &[u8],
    mut cursor: usize,
    end: usize,
    record_size: u64,
    main_header_offset: usize,
) -> Rar5LocatorRecord {
    let flags = read_vint_bounded(data, &mut cursor, end);
    let quick_open_offset = match flags.filter(|value| value & 0x01 != 0) {
        Some(_) => read_vint_bounded(data, &mut cursor, end),
        None => None,
    };
    let recovery_record_offset = match flags.filter(|value| value & 0x02 != 0) {
        Some(_) if quick_open_offset.is_some() || flags.is_some_and(|value| value & 0x01 == 0) => {
            read_vint_bounded(data, &mut cursor, end)
        }
        _ => None,
    };
    let record_fields_complete = flags.is_some_and(|value| value & !0x03 == 0)
        && (flags.is_none_or(|value| value & 0x01 == 0) || quick_open_offset.is_some())
        && (flags.is_none_or(|value| value & 0x02 == 0) || recovery_record_offset.is_some())
        && cursor == end;
    let sample_target = |offset: Option<u64>| {
        offset
            .filter(|value| *value > 0)
            .and_then(|value| u64::try_from(main_header_offset).ok()?.checked_add(value))
    };

    Rar5LocatorRecord {
        record_size,
        flags,
        quick_open_offset_relative_to_main_header: quick_open_offset,
        quick_open_target_sample_offset: sample_target(quick_open_offset),
        recovery_record_offset_relative_to_main_header: recovery_record_offset,
        recovery_record_target_sample_offset: sample_target(recovery_record_offset),
        record_fields_complete,
    }
}

fn read_rar5_archive_metadata_observation(
    data: &[u8],
    mut cursor: usize,
    end: usize,
    record_size: u64,
) -> Rar5ArchiveMetadata {
    let Some(flags) = read_vint_bounded(data, &mut cursor, end) else {
        return Rar5ArchiveMetadata {
            record_size,
            flags: None,
            archive_name: None,
            archive_name_bytes_hex: None,
            archive_name_bytes_length: None,
            archive_name_bytes_sha256: None,
            archive_name_declared_length: None,
            archive_name_buffer_sha256: None,
            archive_name_hex_truncated: None,
            archive_name_display_truncated: None,
            archive_name_utf8_valid: None,
            archive_creation_time_raw: None,
            archive_creation_time_encoding: None,
            record_fields_complete: false,
        };
    };

    let mut archive_name = None;
    let mut archive_name_bytes_hex = None;
    let mut archive_name_bytes_length = None;
    let mut archive_name_bytes_sha256 = None;
    let mut archive_name_declared_length = None;
    let mut archive_name_buffer_sha256 = None;
    let mut archive_name_hex_truncated = None;
    let mut archive_name_display_truncated = None;
    let mut archive_name_utf8_valid = None;
    let mut archive_creation_time_raw = None;
    let mut archive_creation_time_encoding = None;
    let mut complete = flags & !0x0f == 0;
    if flags & 0x08 != 0 && flags & 0x04 == 0 {
        complete = false;
    }
    let mut fields_aligned = true;

    if flags & 0x01 != 0 {
        if let Some(name_length) = read_vint_bounded(data, &mut cursor, end) {
            if let Some(name_end) = usize::try_from(name_length)
                .ok()
                .and_then(|length| cursor.checked_add(length))
                .filter(|name_end| *name_end <= end)
            {
                let raw_name = &data[cursor..name_end];
                let name_end_at_nul = raw_name
                    .iter()
                    .position(|byte| *byte == 0)
                    .unwrap_or(raw_name.len());
                let name_bytes = &raw_name[..name_end_at_nul];
                archive_name_bytes_hex = Some(hex(
                    &name_bytes[..name_bytes.len().min(MAX_RAR5_ARCHIVE_NAME_HEX_BYTES)]
                ));
                archive_name_bytes_length = Some(name_bytes.len());
                archive_name_bytes_sha256 = Some(sha256_hex(name_bytes));
                archive_name_declared_length = Some(raw_name.len());
                archive_name_buffer_sha256 = Some(sha256_hex(raw_name));
                archive_name_hex_truncated =
                    Some(name_bytes.len() > MAX_RAR5_ARCHIVE_NAME_HEX_BYTES);
                archive_name_display_truncated =
                    Some(name_bytes.len() > MAX_RAR5_ARCHIVE_NAME_DISPLAY_BYTES);
                archive_name_utf8_valid = Some(std::str::from_utf8(name_bytes).is_ok());
                if raw_name.first() != Some(&0) {
                    archive_name = Some(
                        String::from_utf8_lossy(
                            &name_bytes
                                [..name_bytes.len().min(MAX_RAR5_ARCHIVE_NAME_DISPLAY_BYTES)],
                        )
                        .into_owned(),
                    );
                }
                cursor = name_end;
            } else {
                complete = false;
                fields_aligned = false;
            }
        } else {
            complete = false;
            fields_aligned = false;
        }
    }

    if flags & 0x02 != 0 && fields_aligned {
        let (time_size, encoding) = if flags & 0x04 != 0 {
            if flags & 0x08 != 0 {
                (8, "unix_nanoseconds")
            } else {
                (4, "unix_seconds")
            }
        } else {
            (8, "windows_filetime_100ns_since_1601")
        };
        if let Some(time_end) = cursor
            .checked_add(time_size)
            .filter(|time_end| *time_end <= end)
        {
            let time_bytes = &data[cursor..time_end];
            archive_creation_time_raw = Some(if time_size == 4 {
                u64::from(u32::from_le_bytes(
                    time_bytes.try_into().expect("four-byte time"),
                ))
            } else {
                u64::from_le_bytes(time_bytes.try_into().expect("eight-byte time"))
            });
            archive_creation_time_encoding = Some(encoding);
            cursor = time_end;
        } else {
            complete = false;
        }
    }

    Rar5ArchiveMetadata {
        record_size,
        flags: Some(flags),
        archive_name,
        archive_name_bytes_hex,
        archive_name_bytes_length,
        archive_name_bytes_sha256,
        archive_name_declared_length,
        archive_name_buffer_sha256,
        archive_name_hex_truncated,
        archive_name_display_truncated,
        archive_name_utf8_valid,
        archive_creation_time_raw,
        archive_creation_time_encoding,
        record_fields_complete: complete && cursor == end,
    }
}

fn read_iso_root_observations(pvd: &[u8]) -> IsoRootDirectoryObservations {
    let record = pvd.get(156..190);
    let logical_block_size = pvd
        .get(128..130)
        .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]]));
    let record_length = record.and_then(|bytes| bytes.first().copied());
    let file_identifier_length = record.and_then(|bytes| bytes.get(32).copied());
    let file_identifier = record.and_then(|bytes| {
        file_identifier_length.and_then(|length| {
            let end = 33 + usize::from(length);
            (end <= bytes.len()).then(|| &bytes[33..end])
        })
    });
    let extent_match = record.and_then(|bytes| bytes.get(2..10)).map(|bytes| {
        u32::from_le_bytes(bytes[0..4].try_into().expect("four-byte ISO field"))
            == u32::from_be_bytes(bytes[4..8].try_into().expect("four-byte ISO field"))
    });
    let data_length_match = record.and_then(|bytes| bytes.get(10..18)).map(|bytes| {
        u32::from_le_bytes(bytes[0..4].try_into().expect("four-byte ISO field"))
            == u32::from_be_bytes(bytes[4..8].try_into().expect("four-byte ISO field"))
    });
    let volume_sequence_match = record.and_then(|bytes| bytes.get(28..32)).map(|bytes| {
        u16::from_le_bytes([bytes[0], bytes[1]]) == u16::from_be_bytes([bytes[2], bytes[3]])
    });
    let flags = record.and_then(|bytes| bytes.get(25).copied());
    let directory_flag_set = flags.map(|value| value & 0x02 != 0);
    let reserved_flags_clear = flags.map(|value| value & 0x60 == 0);
    let basic_structure_checks_pass = record.is_some_and(|bytes| {
        bytes.len() == 34
            && record_length == Some(34)
            && extent_match == Some(true)
            && data_length_match == Some(true)
            && volume_sequence_match == Some(true)
            && file_identifier_length == Some(1)
            && file_identifier == Some(&[0][..])
            && directory_flag_set == Some(true)
            && reserved_flags_clear == Some(true)
    });

    IsoRootDirectoryObservations {
        available: record.is_some(),
        record_length,
        extended_attribute_record_length: record.and_then(|bytes| bytes.get(1).copied()),
        extent_location: record.and_then(|bytes| {
            bytes
                .get(2..6)
                .map(|value| u32::from_le_bytes(value.try_into().expect("four-byte ISO field")))
        }),
        extent_offset_bytes: iso_block_offset(
            record.and_then(|bytes| {
                bytes
                    .get(2..6)
                    .map(|value| u32::from_le_bytes(value.try_into().expect("four-byte ISO field")))
            }),
            logical_block_size,
        ),
        dual_endian_extent_location_match: extent_match,
        data_length: record.and_then(|bytes| {
            bytes
                .get(10..14)
                .map(|value| u32::from_le_bytes(value.try_into().expect("four-byte ISO field")))
        }),
        dual_endian_data_length_match: data_length_match,
        recording_date_bytes_hex: record.and_then(|bytes| bytes.get(18..25)).map(hex),
        file_flags: flags,
        directory_flag_set,
        reserved_flags_clear,
        file_unit_size: record.and_then(|bytes| bytes.get(26).copied()),
        interleave_gap_size: record.and_then(|bytes| bytes.get(27).copied()),
        volume_sequence_number: record.and_then(|bytes| {
            bytes
                .get(28..30)
                .map(|value| u16::from_le_bytes([value[0], value[1]]))
        }),
        dual_endian_volume_sequence_number_match: volume_sequence_match,
        file_identifier_length,
        file_identifier_hex: file_identifier.map(hex),
        is_root_directory_identifier: file_identifier.map(|value| value == [0]),
        basic_structure_checks_pass,
    }
}

fn sha256_hex(bytes: &[u8]) -> String {
    use sha2::{Digest, Sha256};

    let digest = Sha256::digest(bytes);
    hex(&digest)
}

fn inspect_zip_extra_fields<'a>(
    mut extra: &'a [u8],
    filename: Option<&[u8]>,
    flags: Option<u16>,
    compression_method: Option<u16>,
    crc32: Option<u32>,
) -> ZipExtraInspection<'a> {
    const MAX_EXTRA_RECORDS: usize = 256;
    const MAX_EXTRA_PAYLOAD_PREFIX_BYTES: usize = 32;

    let mut inspection = ZipExtraInspection {
        records: Vec::new(),
        record_count: 0,
        records_truncated: false,
        records_complete: true,
        zip64_payload: None,
        zip64_present: false,
        zip64_record_count: 0,
        zip64_duplicate: false,
        aes_records: Vec::new(),
        aes_record_count: 0,
        extended_timestamp_records: Vec::new(),
        extended_timestamp_record_count: 0,
        unicode_path_records: Vec::new(),
        unicode_path_record_count: 0,
    };
    let mut offset = 0usize;

    while !extra.is_empty() {
        let record_index = inspection.record_count;
        inspection.record_count = inspection.record_count.saturating_add(1);
        if record_index >= MAX_EXTRA_RECORDS {
            inspection.records_truncated = true;
        }

        let Some(header) = extra.get(..4) else {
            if record_index < MAX_EXTRA_RECORDS {
                inspection.records.push(ZipExtraField {
                    record_index,
                    header_id: None,
                    header_id_hex: None,
                    offset_in_extra_area: offset,
                    declared_payload_length: None,
                    observed_payload_length: extra.len(),
                    payload_sha256: (!extra.is_empty()).then(|| sha256_hex(extra)),
                    payload_prefix_hex: Some(hex(
                        &extra[..extra.len().min(MAX_EXTRA_PAYLOAD_PREFIX_BYTES)]
                    )),
                    payload_prefix_truncated: Some(extra.len() > MAX_EXTRA_PAYLOAD_PREFIX_BYTES),
                    complete: false,
                });
            }
            inspection.records_complete = false;
            break;
        };
        let header_id = u16::from_le_bytes([header[0], header[1]]);
        let data_size = u16::from_le_bytes([header[2], header[3]]) as usize;
        let payload_available = extra.len().saturating_sub(4);
        let observed_payload_length = data_size.min(payload_available);
        let payload = &extra[4..4 + observed_payload_length];
        let complete = observed_payload_length == data_size;
        let mut id_hex = String::with_capacity(4);
        id_hex.push_str(&hex(&header_id.to_le_bytes()));
        if record_index < MAX_EXTRA_RECORDS {
            inspection.records.push(ZipExtraField {
                record_index,
                header_id: Some(header_id),
                header_id_hex: Some(id_hex),
                offset_in_extra_area: offset,
                declared_payload_length: Some(data_size),
                observed_payload_length,
                payload_sha256: Some(sha256_hex(payload)),
                payload_prefix_hex: Some(hex(
                    &payload[..payload.len().min(MAX_EXTRA_PAYLOAD_PREFIX_BYTES)]
                )),
                payload_prefix_truncated: Some(payload.len() > MAX_EXTRA_PAYLOAD_PREFIX_BYTES),
                complete,
            });
        }

        if !complete {
            inspection.records_complete = false;
        }
        if header_id == 0x0001 {
            inspection.zip64_present = true;
            inspection.zip64_record_count = inspection.zip64_record_count.saturating_add(1);
        }
        match header_id {
            0x0001 if complete => {
                if inspection.zip64_record_count > 1 {
                    inspection.zip64_duplicate = true;
                    inspection.zip64_payload = None;
                } else {
                    inspection.zip64_payload = Some(payload);
                }
            }
            0x9901 if complete => {
                inspection.aes_record_count = inspection.aes_record_count.saturating_add(1);
                if inspection.aes_records.len() < MAX_EXTRA_RECORDS {
                    inspection.aes_records.push(parse_zip_aes_record(
                        record_index,
                        payload,
                        flags,
                        compression_method,
                        crc32,
                    ));
                }
            }
            0x5455 if complete => {
                inspection.extended_timestamp_record_count =
                    inspection.extended_timestamp_record_count.saturating_add(1);
                if inspection.extended_timestamp_records.len() < MAX_EXTRA_RECORDS {
                    inspection
                        .extended_timestamp_records
                        .push(parse_zip_extended_timestamp_record(record_index, payload));
                }
            }
            0x7075 if complete => {
                inspection.unicode_path_record_count =
                    inspection.unicode_path_record_count.saturating_add(1);
                if inspection.unicode_path_records.len() < MAX_EXTRA_RECORDS {
                    inspection
                        .unicode_path_records
                        .push(parse_zip_unicode_path_record(
                            record_index,
                            payload,
                            filename,
                            flags,
                        ));
                }
            }
            _ => {}
        }

        let consumed = 4usize.saturating_add(observed_payload_length);
        offset = offset.saturating_add(consumed);
        extra = &extra[consumed..];
        if !complete {
            break;
        }
    }

    inspection
}

fn resolve_zip_local_size(
    local_size: Option<u32>,
    effective_size: Option<&str>,
    authority_status: &'static str,
) -> (Option<u64>, Option<&'static str>) {
    let resolved = effective_size.and_then(|size| size.parse::<u64>().ok());
    let source = match (resolved, local_size) {
        (Some(_), Some(u32::MAX)) => Some("zip64_local_extra"),
        (Some(_), Some(_)) => Some("local_header"),
        _ => Some(match authority_status {
            "descriptor-deferred" => "data_descriptor_expected",
            "masked-local-metadata" => "encrypted_central_directory_masked",
            _ => "canonical_size_authority_unavailable",
        }),
    };
    (resolved, source)
}

fn parse_zip_extended_timestamp_record(
    record_index: usize,
    payload: &[u8],
) -> ZipExtendedTimestamp {
    let flags = payload.first().copied();
    let declared_time_fields_mask = flags.map(|value| value & 0x07);
    let unknown_flag_bits = flags.map(|value| value & !0x07);
    let mut offset = usize::from(flags.is_some());
    let mut available_time_fields_mask = 0u8;
    let mut values = [None, None, None];
    for (index, bit) in [0x01, 0x02, 0x04].into_iter().enumerate() {
        if declared_time_fields_mask.is_some_and(|mask| mask & bit != 0) {
            if let Some(bytes) = payload.get(offset..offset.saturating_add(4)) {
                values[index] = Some(u32::from_le_bytes([bytes[0], bytes[1], bytes[2], bytes[3]]));
                available_time_fields_mask |= bit;
                offset += 4;
            } else {
                offset = payload.len();
            }
        }
    }
    let missing_declared_time_fields_mask =
        declared_time_fields_mask.map(|mask| mask & !available_time_fields_mask);

    ZipExtendedTimestamp {
        record_index,
        payload_length: payload.len(),
        flags,
        modification_time_unix_seconds: values[0],
        access_time_unix_seconds: values[1],
        creation_time_unix_seconds: values[2],
        declared_time_fields_mask,
        available_time_fields_mask,
        missing_declared_time_fields_mask,
        unknown_flag_bits,
        trailing_payload_length: payload.len().saturating_sub(offset),
        payload_has_flag_byte: flags.is_some(),
        declared_time_fields_complete: declared_time_fields_mask
            .map(|_| missing_declared_time_fields_mask == Some(0)),
    }
}

fn parse_zip_aes_record(
    record_index: usize,
    payload: &[u8],
    flags: Option<u16>,
    compression_method: Option<u16>,
    crc32: Option<u32>,
) -> ZipAesExtra {
    let vendor_version = payload
        .get(..2)
        .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]]));
    let vendor_id = payload
        .get(2..4)
        .map(|bytes| String::from_utf8_lossy(bytes).into_owned());
    let encryption_strength = payload.get(4).copied();
    let key_bits = encryption_strength.and_then(|strength| match strength {
        1 => Some(128),
        2 => Some(192),
        3 => Some(256),
        _ => None,
    });
    let actual_compression_method = payload
        .get(5..7)
        .map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]]));
    let vendor_id_is_ae = vendor_id.as_deref().map(|vendor| vendor == "AE");
    let local_encryption_flag_set = flags.map(|value| value & 0x0001 != 0);
    let local_compression_method_is_99 = compression_method.map(|method| method == 99);
    let local_indicators_match_aes_record = local_encryption_flag_set
        .zip(local_compression_method_is_99)
        .map(|(encrypted, aes_method)| encrypted && aes_method);
    let ae2_local_header_crc32_is_zero = vendor_version
        .filter(|version| *version == 2)
        .and(crc32.map(|value| value == 0));

    ZipAesExtra {
        record_index,
        payload_length: payload.len(),
        vendor_version,
        vendor_id,
        encryption_strength,
        key_bits,
        actual_compression_method,
        vendor_id_is_ae,
        local_encryption_flag_set,
        local_compression_method_is_99,
        local_indicators_match_aes_record,
        ae2_local_header_crc32_is_zero,
        expected_payload_length: 7,
        payload_has_required_fields: payload.len() >= 7,
    }
}

fn parse_zip_unicode_path_record(
    record_index: usize,
    payload: &[u8],
    filename: Option<&[u8]>,
    flags: Option<u16>,
) -> ZipUnicodePathExtra {
    let version = payload.first().copied();
    let saved_filename_crc32 = payload
        .get(1..5)
        .map(|bytes| u32::from_le_bytes([bytes[0], bytes[1], bytes[2], bytes[3]]));
    let observed_filename_crc32 = filename.map(crc32fast::hash);
    let filename_crc32_matches = saved_filename_crc32
        .zip(observed_filename_crc32)
        .map(|(saved, observed)| saved == observed);
    let unicode_filename = payload.get(5..);
    let unicode_filename_utf8_valid =
        unicode_filename.map(|bytes| std::str::from_utf8(bytes).is_ok());
    let (unicode_filename_str, unicode_filename_display_truncated) = match unicode_filename {
        Some(bytes) => {
            let (s, trunc) =
                bounded_utf8_prefix_string(bytes, MAX_RETAINED_ZIP_FILENAME_DISPLAY_BYTES);
            (s, Some(trunc))
        }
        None => (None, None),
    };
    let unicode_filename_bom_present =
        unicode_filename.map(|bytes| bytes.starts_with(&[0xef, 0xbb, 0xbf]));
    let efs_flag_set = flags.map(|value| value & 0x0800 != 0);
    let unicode_filename_matches_efs_filename = efs_flag_set
        .zip(unicode_filename)
        .and_then(|(efs_set, unicode_name)| efs_set.then(|| filename == Some(unicode_name)));
    // The record needs at least one UnicodeName byte (data size >= 6); a header-only
    // record would otherwise resolve a non-empty local name to an empty string.
    let usable_as_filename_override = version == Some(1)
        && filename_crc32_matches == Some(true)
        && unicode_filename.is_some_and(|bytes| !bytes.is_empty() && !bytes.contains(&0))
        && unicode_filename_utf8_valid == Some(true)
        && unicode_filename_bom_present == Some(false)
        && unicode_filename_matches_efs_filename != Some(false);

    ZipUnicodePathExtra {
        record_index,
        payload_length: payload.len(),
        version,
        saved_filename_crc32,
        observed_filename_crc32,
        filename_crc32_matches,
        unicode_filename_bytes_length: unicode_filename.map(<[u8]>::len),
        unicode_filename_bytes_sha256: unicode_filename.map(sha256_hex),
        unicode_filename_prefix_hex: unicode_filename
            .map(|bytes| hex(&bytes[..bytes.len().min(128)])),
        unicode_filename_prefix_truncated: unicode_filename.map(|bytes| bytes.len() > 128),
        unicode_filename_utf8_valid,
        unicode_filename: unicode_filename_str,
        unicode_filename_display_truncated,
        unicode_filename_bom_present,
        unicode_filename_matches_efs_filename,
        usable_as_filename_override,
        payload_has_required_fields: payload.len() >= 5,
    }
}

fn take_nul_terminated(data: &[u8], start: usize, maximum: usize) -> Option<(&[u8], usize)> {
    let end = start.checked_add(maximum)?.min(data.len());
    let relative_end = data.get(start..end)?.iter().position(|byte| *byte == 0)?;
    let field_end = start.checked_add(relative_end)?;
    Some((data.get(start..field_end)?, field_end.checked_add(1)?))
}

fn iso_block_offset(location: Option<u32>, logical_block_size: Option<u16>) -> Option<u64> {
    u64::from(location?).checked_mul(u64::from(logical_block_size?))
}

fn parse_iso_volume_dates(pvd: &[u8]) -> IsoVolumeDates {
    let creation = parse_iso_volume_date(pvd.get(813..830).unwrap_or_default());
    let modification = parse_iso_volume_date(pvd.get(830..847).unwrap_or_default());
    let expiration = parse_iso_volume_date(pvd.get(847..864).unwrap_or_default());
    let effective = parse_iso_volume_date(pvd.get(864..881).unwrap_or_default());
    IsoVolumeDates {
        creation_raw_bytes_hex: creation.0,
        creation_ascii_digits_valid: creation.1,
        creation_unspecified: creation.2,
        creation_timezone_quarters: creation.3,
        modification_raw_bytes_hex: modification.0,
        modification_ascii_digits_valid: modification.1,
        modification_unspecified: modification.2,
        modification_timezone_quarters: modification.3,
        expiration_raw_bytes_hex: expiration.0,
        expiration_ascii_digits_valid: expiration.1,
        expiration_unspecified: expiration.2,
        expiration_timezone_quarters: expiration.3,
        effective_raw_bytes_hex: effective.0,
        effective_ascii_digits_valid: effective.1,
        effective_unspecified: effective.2,
        effective_timezone_quarters: effective.3,
    }
}

fn parse_iso_volume_date(value: &[u8]) -> (String, bool, bool, Option<i8>) {
    let raw = hex(value);
    let digits = value.get(..16);
    let digits_valid =
        value.len() == 17 && digits.is_some_and(|bytes| bytes.iter().all(u8::is_ascii_digit));
    let unspecified = digits_valid
        && digits.is_some_and(|bytes| bytes.iter().all(|byte| *byte == b'0'))
        && value[16] == 0;
    let timezone = value
        .get(16)
        .map(|offset| *offset as i8)
        .filter(|offset| (-48..=52).contains(offset));
    (raw, digits_valid, unspecified, timezone)
}

fn hex_prefix(bytes: &[u8], maximum_bytes: usize) -> String {
    hex(&bytes[..bytes.len().min(maximum_bytes)])
}

fn find_all_with_limit(
    data: &[u8],
    signature: &[u8],
    maximum_end: usize,
    maximum_matches: usize,
) -> (Vec<usize>, bool) {
    if signature.is_empty() || maximum_matches == 0 {
        return (Vec::new(), false);
    }
    let mut matches = data
        .get(..data.len().min(maximum_end))
        .into_iter()
        .flat_map(|bounded_data| bounded_data.windows(signature.len()).enumerate())
        .filter_map(|(offset, window)| (window == signature).then_some(offset))
        .take(maximum_matches.saturating_add(1))
        .collect::<Vec<_>>();
    let truncated = matches.len() > maximum_matches;
    matches.truncate(maximum_matches);
    (matches, truncated)
}

const MAX_RAR5_MAIN_EXTRA_RECORDS: usize = 4096;

const MAX_RAR5_ARCHIVE_NAME_DISPLAY_BYTES: usize = 4096;

const MAX_RAR5_ARCHIVE_NAME_HEX_BYTES: usize = 256;

const MAX_SIGNATURE_CANDIDATES_PER_FORMAT: usize = 16;

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reports_zstandard_literal_size_relationship_with_sample_provenance() {
        let parsed = serde_json::to_value(parse(b"\x28\xb5\x2f\xfd\x20\x01\x09\x00\x00A")).unwrap();
        assert_eq!(parsed["headers"][0]["format"], "zstandard");
        let frame = &parsed["headers"][0]["frame"];
        assert_eq!(frame["header_status"], "observed_supported_header");
        assert_eq!(frame["header_end_offset"], 6);
        assert_eq!(frame["content_size"]["value"], 1);
        assert_eq!(frame["first_block"]["payload_start_offset"], 9);
        assert_eq!(frame["first_block"]["payload_end_offset"], 10);
        assert_eq!(frame["size_relationship"]["status"], "equal");
        assert_eq!(frame["checksum"]["status"], "not_declared");
        assert_eq!(frame["file_offset_known"], false);
        assert_eq!(frame["whole_file_validated"], false);
        assert_eq!(frame["content_decompressed"], false);
    }

    #[test]
    fn parses_7z_header_and_iso_identifiers_from_sample_bytes() {
        let mut seven_zip = vec![0u8; 32];
        seven_zip[..6].copy_from_slice(SEVEN_ZIP);
        seven_zip[6] = 0;
        seven_zip[7] = 4;
        seven_zip[12..20].copy_from_slice(&0u64.to_le_bytes());
        seven_zip[20..28].copy_from_slice(&0u64.to_le_bytes());
        let crc = crc32fast::hash(&seven_zip[12..32]);
        seven_zip[8..12].copy_from_slice(&crc.to_le_bytes());
        let parsed_7z = parse(&seven_zip);
        assert_eq!(parsed_7z.signatures[0].format, "7-Zip");
        assert!(matches!(
            parsed_7z.headers[0],
            FormatHeader::SevenZip {
                start_header_crc_valid: Some(true),
                ..
            }
        ));

        let mut iso = vec![0u8; ISO_PVD_OFFSET + 2048];
        iso[ISO_PVD_OFFSET] = 1;
        iso[ISO_PVD_OFFSET + 1..ISO_PVD_OFFSET + 6].copy_from_slice(b"CD001");
        iso[ISO_PVD_OFFSET + 6] = 1;
        iso[ISO_PVD_OFFSET + 574..ISO_PVD_OFFSET + 586].copy_from_slice(b"MKISOFS     ");
        iso[ISO_PVD_OFFSET + 702..ISO_PVD_OFFSET + 710].copy_from_slice(b"COPY.TXT");
        iso[ISO_PVD_OFFSET + 813..ISO_PVD_OFFSET + 830].copy_from_slice(b"2026010203040500\0");
        iso[ISO_PVD_OFFSET + 881] = 1;
        iso[ISO_PVD_OFFSET + 883..ISO_PVD_OFFSET + 887].copy_from_slice(b"APP!");
        let parsed_iso = parse(&iso);
        assert!(
            parsed_iso
                .signatures
                .iter()
                .any(|item| item.format == "ISO 9660 primary volume descriptor")
        );
        let FormatHeader::Iso9660PrimaryVolumeDescriptor {
            copyright_file_identifier,
            creation_timestamp_raw_hex,
            file_structure_version,
            application_use_observed_byte_length,
            application_use_preview_hex,
            ..
        } = &parsed_iso.headers[0]
        else {
            panic!("expected ISO PVD metadata");
        };
        assert_eq!(copyright_file_identifier, "COPY.TXT");
        assert_eq!(
            creation_timestamp_raw_hex.as_deref(),
            Some("3230323630313032303330343035303000")
        );
        assert_eq!(*file_structure_version, Some(1));
        assert_eq!(*application_use_observed_byte_length, 512);
        assert!(application_use_preview_hex.starts_with("41505021"));
    }

    #[test]
    fn recognizes_supplementary_version_one_before_the_primary_descriptor() {
        let supplementary_offset = ISO_PVD_OFFSET;
        let primary_offset = supplementary_offset + 2048;
        let mut iso = vec![0u8; primary_offset + 2048];
        for (offset, descriptor_type, version) in
            [(supplementary_offset, 2, 1), (primary_offset, 1, 1)]
        {
            iso[offset] = descriptor_type;
            iso[offset + 1..offset + 6].copy_from_slice(b"CD001");
            iso[offset + 6] = version;
        }

        let parsed = parse(&iso);

        assert!(
            parsed
                .signatures
                .iter()
                .any(|signature| { signature.format == "ISO 9660 primary volume descriptor" })
        );
        assert!(matches!(
            parsed.headers.first(),
            Some(FormatHeader::Iso9660PrimaryVolumeDescriptor { offset, .. })
                if *offset == primary_offset
        ));

        iso[supplementary_offset + 6] = 3;
        assert_eq!(find_iso_pvd(&iso), None);
    }

    #[test]
    fn reports_iso_sequence_termination_and_keeps_offsets_sample_relative() {
        let mut iso = vec![0u8; ISO_PVD_OFFSET];
        iso.extend_from_slice(&iso_volume_descriptor(1, 1));
        iso.extend_from_slice(&iso_volume_descriptor(255, 1));

        let sequence = scan_iso_descriptor_sequence(&iso).unwrap();

        assert_eq!(sequence.status, "terminated");
        assert_eq!(sequence.primary_volume_descriptor_count, 1);
        assert_eq!(
            sequence.first_primary_volume_descriptor_offset,
            Some(ISO_PVD_OFFSET)
        );
        assert_eq!(sequence.terminator_offset, Some(ISO_PVD_OFFSET + 2048));
        assert_eq!(sequence.terminator_version_valid, Some(true));
        assert_eq!(sequence.terminator_reserved_bytes_zero, Some(true));
        assert!(!sequence.image_origin_known);
    }

    #[test]
    fn retains_primary_descriptor_when_later_iso_sequence_data_is_reserved_or_malformed() {
        for (later_type, later_version, expected_status) in [
            (4, 1, "unsupported_reserved_type"),
            (255, 2, "malformed_terminator_version"),
        ] {
            let mut iso = vec![0u8; ISO_PVD_OFFSET];
            iso.extend_from_slice(&iso_volume_descriptor(1, 1));
            iso.extend_from_slice(&iso_volume_descriptor(later_type, later_version));

            let metadata = parse(&iso);
            let sequence = metadata.iso_descriptor_sequence.as_ref().unwrap();
            assert_eq!(sequence.status, expected_status);
            assert_eq!(
                sequence.first_primary_volume_descriptor_offset,
                Some(ISO_PVD_OFFSET)
            );
            assert_eq!(sequence.primary_volume_descriptor_count, 1);
            assert_eq!(
                sequence.terminator_version_valid,
                (later_type == 255).then_some(false)
            );
            assert!(metadata.headers.iter().any(|header| matches!(
                header,
                FormatHeader::Iso9660PrimaryVolumeDescriptor { .. }
            )));
        }
    }

    #[test]
    fn distinguishes_truncated_and_scan_limited_iso_descriptor_sequences() {
        let mut single_pvd = vec![0u8; ISO_PVD_OFFSET];
        single_pvd.extend_from_slice(&iso_volume_descriptor(1, 1));
        let truncated = scan_iso_descriptor_sequence(&single_pvd).unwrap();
        assert_eq!(truncated.status, "sample_truncated");

        let mut full_scan = vec![0u8; 256 * 2048];
        for sector in 16..256 {
            let descriptor = iso_volume_descriptor(if sector == 16 { 1 } else { 2 }, 1);
            full_scan[sector * 2048..(sector + 1) * 2048].copy_from_slice(&descriptor);
        }
        let limited = scan_iso_descriptor_sequence(&full_scan).unwrap();
        assert_eq!(limited.status, "scan_limit_reached");
        assert_eq!(limited.descriptors.len(), 240);
    }

    #[test]
    fn compares_only_complete_supported_iso_primary_descriptor_recordings() {
        let mut primary = iso_volume_descriptor(1, 1);
        primary[40] = b'A';
        let mut iso = vec![0; ISO_PVD_OFFSET];
        iso.extend_from_slice(&iso_volume_descriptor(0, 1));
        iso.extend_from_slice(&primary);
        iso.extend_from_slice(&iso_volume_descriptor(2, 2));
        iso.extend_from_slice(&primary);
        iso.extend_from_slice(&iso_volume_descriptor(255, 1));
        // A later descriptor outside the terminated sequence must not contribute.
        primary[40] = b'B';
        iso.extend_from_slice(&primary);
        let metadata = serde_json::to_value(parse(&iso)).unwrap();
        let copies = &metadata["iso_primary_volume_descriptor_copies"];
        assert_eq!(copies["status"], "observed_copies_agree");
        assert_eq!(copies["sequence_status"], "terminated");
        assert_eq!(copies["sampled_descriptor_sequence_terminated"], true);
        assert_eq!(copies["observed_descriptor_count"], 2);
        assert_eq!(copies["repeat_comparison_count"], 1);
        assert_eq!(copies["matching_reference_count"], 2);
        assert_eq!(copies["differing_reference_count"], 0);
        assert_eq!(copies["all_observed_copies_equal"], true);
        assert_eq!(copies["reference_descriptor_offset"], 34_816);
        assert_eq!(copies["copies"][0]["descriptor_sequence_index"], 1);
        assert_eq!(copies["copies"][1]["descriptor_sequence_index"], 3);
        assert_eq!(copies["copies"][1]["sector"], 19);
        assert_eq!(copies["copies"][1]["offset"], 38_912);
        assert_eq!(copies["copies"][1]["end_offset_exclusive"], 40_960);
        assert_eq!(copies["copies"][1]["bytes_hashed"], 2048);
        // Independently calculated SHA-256 of the literal header, A at byte 40,
        // and zero-filled remainder. It is not an ISO conformance fixture.
        assert_eq!(
            copies["copies"][0]["raw_sha256"],
            "725f9ad391992113e66c59367b134c9d0b9d3c8582705a3316eebf40925f01c0"
        );
        assert_eq!(copies["copies"][1]["matches_reference"], true);
        assert!(copies["copies"][1]["first_difference"].is_null());
        assert!(copies["first_difference"].is_null());
        assert_eq!(copies["whole_image_validated"], false);
        assert_eq!(copies["image_origin_known"], false);
        assert!(
            copies["offset_basis"]
                .as_str()
                .unwrap()
                .contains("sample-relative")
        );
    }

    #[test]
    fn retains_iso_primary_byte_differences_in_partial_sequences() {
        for changed in [7, 40, 883, 2047] {
            let mut first = iso_volume_descriptor(1, 1);
            first[40] = b'A';
            let mut later = first.clone();
            later[changed] ^= 1;
            let mut iso = vec![0; ISO_PVD_OFFSET];
            iso.extend_from_slice(&first);
            iso.extend_from_slice(&later);
            let metadata = serde_json::to_value(parse(&iso)).unwrap();
            let copies = &metadata["iso_primary_volume_descriptor_copies"];
            assert_eq!(copies["status"], "observed_copies_differ");
            assert_eq!(copies["sequence_status"], "sample_truncated");
            assert_eq!(copies["sampled_descriptor_sequence_terminated"], false);
            assert_eq!(copies["observed_descriptor_count"], 2);
            assert_eq!(copies["matching_reference_count"], 1);
            assert_eq!(copies["differing_reference_count"], 1);
            assert_eq!(copies["all_observed_copies_equal"], false);
            let difference = &copies["first_difference"];
            assert_eq!(difference["descriptor_relative_offset"], changed);
            assert_eq!(difference["reference_sample_offset"], 32_768 + changed);
            assert_eq!(difference["differing_sample_offset"], 34_816 + changed);
            assert_eq!(difference["reference_byte"], first[changed]);
            assert_eq!(difference["differing_byte"], later[changed]);
            assert_eq!(copies["copies"][1]["first_difference"], *difference);
        }
    }

    #[test]
    fn withholds_iso_primary_equality_without_repeated_complete_observations() {
        for (later_type, later_version, expected_status) in [
            (1, 2, "malformed"),
            (4, 1, "unsupported_reserved_type"),
            (255, 2, "malformed_terminator_version"),
        ] {
            let mut iso = vec![0; ISO_PVD_OFFSET];
            iso.extend_from_slice(&iso_volume_descriptor(1, 1));
            iso.extend_from_slice(&iso_volume_descriptor(later_type, later_version));
            let metadata = serde_json::to_value(parse(&iso)).unwrap();
            let copies = &metadata["iso_primary_volume_descriptor_copies"];
            assert_eq!(copies["status"], "single_primary_descriptor_observed");
            assert_eq!(copies["observed_descriptor_count"], 1);
            assert_eq!(copies["repeat_comparison_count"], 0);
            assert!(copies["all_observed_copies_equal"].is_null());
            assert_eq!(copies["sequence_status"], expected_status);
            assert_eq!(copies["sampled_descriptor_sequence_terminated"], false);
        }
        let mut iso = vec![0; ISO_PVD_OFFSET];
        iso.extend_from_slice(&iso_volume_descriptor(2, 1));
        iso.extend_from_slice(&iso_volume_descriptor(255, 1));
        let metadata = serde_json::to_value(parse(&iso)).unwrap();
        let copies = &metadata["iso_primary_volume_descriptor_copies"];
        assert_eq!(copies["status"], "no_primary_descriptor_observed");
        assert_eq!(copies["observed_descriptor_count"], 0);
        assert!(copies["all_observed_copies_equal"].is_null());
        assert!(copies["reference_descriptor_offset"].is_null());
        assert_eq!(copies["copies"].as_array().unwrap().len(), 0);
        assert!(serde_json::to_value(parse(b"not an ISO")).unwrap()
            ["iso_primary_volume_descriptor_copies"].is_null());
    }

    #[test]
    fn compares_iso_primary_copies_after_detail_limit_without_unbounded_output() {
        let first = iso_volume_descriptor(1, 1);
        let mut iso = vec![0; ISO_PVD_OFFSET];
        for index in 0..240 {
            let mut descriptor = first.clone();
            if index == 239 {
                descriptor[2047] = 1;
            }
            iso.extend_from_slice(&descriptor);
        }
        let metadata = serde_json::to_value(parse(&iso)).unwrap();
        let copies = &metadata["iso_primary_volume_descriptor_copies"];
        assert_eq!(copies["status"], "observed_copies_differ");
        assert_eq!(copies["sequence_status"], "scan_limit_reached");
        assert_eq!(copies["sampled_descriptor_sequence_terminated"], false);
        assert_eq!(copies["observed_descriptor_count"], 240);
        assert_eq!(copies["repeat_comparison_count"], 239);
        assert_eq!(copies["matching_reference_count"], 239);
        assert_eq!(copies["differing_reference_count"], 1);
        assert_eq!(copies["detail_limit"], 64);
        assert_eq!(copies["details_omitted_count"], 176);
        assert_eq!(copies["copies"].as_array().unwrap().len(), 64);
        assert_eq!(copies["all_observed_copies_equal"], false);
        assert_eq!(
            copies["first_difference"]["descriptor_relative_offset"],
            2047
        );
        assert_eq!(
            copies["first_difference"]["reference_sample_offset"],
            34_815
        );
        assert_eq!(
            copies["first_difference"]["differing_sample_offset"],
            524_287
        );
    }

    #[test]
    fn iso_primary_copy_coverage_keeps_failures_and_earliest_difference() {
        let first = iso_volume_descriptor(1, 1);
        let mut different = first.clone();
        different[2047] = 1;
        let mut later_difference = first.clone();
        later_difference[7] = 2;
        for ending in [
            "partial_descriptor",
            "bad_identifier",
            "bad_terminator",
            "terminated",
        ] {
            let mut iso = vec![0; ISO_PVD_OFFSET];
            iso.extend_from_slice(&first);
            iso.extend_from_slice(&different);
            iso.extend_from_slice(&later_difference);
            iso.extend_from_slice(&first);
            let (tail, expected_status) = match ending {
                "partial_descriptor" => (first[..2047].to_vec(), "sample_truncated"),
                "bad_identifier" => {
                    let mut bad = first.clone();
                    bad[1] = b'X';
                    (bad, "malformed")
                }
                "bad_terminator" => {
                    let mut bad = iso_volume_descriptor(255, 1);
                    bad[7] = 1;
                    (bad, "malformed_terminator_reserved_bytes")
                }
                _ => (iso_volume_descriptor(255, 1), "terminated"),
            };
            iso.extend_from_slice(&tail);
            // Neither a malformed boundary nor a terminator permits scanning
            // onward for convenient matching descriptors.
            if ending != "partial_descriptor" {
                iso.extend_from_slice(&first);
            }
            let metadata = serde_json::to_value(parse(&iso)).unwrap();
            let copies = &metadata["iso_primary_volume_descriptor_copies"];
            assert_eq!(copies["observed_descriptor_count"], 4, "{ending}");
            assert_eq!(copies["matching_reference_count"], 2);
            assert_eq!(copies["differing_reference_count"], 2);
            assert_eq!(copies["sequence_status"], expected_status, "{ending}");
            assert_eq!(
                copies["sampled_descriptor_sequence_terminated"],
                ending == "terminated"
            );
            assert_eq!(copies["all_observed_copies_equal"], false);
            // Earliest conflicting recording wins, not the minimum relative
            // byte index among all conflicting recordings, nor the last one.
            assert_eq!(
                copies["first_difference"]["descriptor_relative_offset"],
                2047
            );
            assert_eq!(
                copies["first_difference"]["differing_sample_offset"],
                36_863
            );
        }
        // A complete matching prefix is still explicitly incomplete.
        let mut iso = vec![0; ISO_PVD_OFFSET];
        iso.extend_from_slice(&first);
        iso.extend_from_slice(&first);
        let metadata = serde_json::to_value(parse(&iso)).unwrap();
        let copies = &metadata["iso_primary_volume_descriptor_copies"];
        assert_eq!(copies["status"], "observed_copies_agree");
        assert_eq!(copies["sampled_descriptor_sequence_terminated"], false);
        assert_eq!(copies["sequence_status"], "sample_truncated");
        // Declared logical block size cannot change descriptor sector offsets.
        let mut small_block = first.clone();
        small_block[128..132].copy_from_slice(&[0, 2, 2, 0]);
        iso.truncate(ISO_PVD_OFFSET);
        iso.extend_from_slice(&small_block);
        iso.extend_from_slice(&small_block);
        let metadata = serde_json::to_value(parse(&iso)).unwrap();
        assert_eq!(
            metadata["iso_primary_volume_descriptor_copies"]["copies"][1]["offset"],
            34_816
        );
    }

    fn iso_path_table_sample() -> Vec<u8> {
        let mut sample = vec![0; 49_152];
        let mut pvd = iso_volume_descriptor(1, 1);
        pvd[80..88].copy_from_slice(&[32, 0, 0, 0, 0, 0, 0, 32]);
        pvd[128..132].copy_from_slice(&[0, 8, 8, 0]);
        pvd[132..140].copy_from_slice(&[10, 0, 0, 0, 0, 0, 0, 10]);
        pvd[140..156].copy_from_slice(&[20, 0, 0, 0, 21, 0, 0, 0, 0, 0, 0, 22, 0, 0, 0, 23]);
        pvd[881] = 1;
        sample[32_768..34_816].copy_from_slice(&pvd);
        sample[34_816..36_864].copy_from_slice(&iso_volume_descriptor(255, 1));
        for offset in [40_960, 43_008] {
            sample[offset..offset + 10].copy_from_slice(&[1, 0, 24, 0, 0, 0, 1, 0, 0, 0]);
        }
        for offset in [45_056, 47_104] {
            sample[offset..offset + 10].copy_from_slice(&[1, 0, 0, 0, 0, 24, 0, 1, 0, 0]);
        }
        sample
    }

    #[test]
    fn iso_path_tables_compare_only_same_endian_conditional_candidate_ranges() {
        let metadata = serde_json::to_value(parse(&iso_path_table_sample())).unwrap();
        let copies = &metadata["iso_path_table_copies"];
        assert_eq!(copies["status"], "conditional_candidate_ranges_observed");
        assert_eq!(copies["source_primary_descriptor_offset"], 32_768);
        assert_eq!(copies["sequence_status"], "terminated");
        assert_eq!(copies["sampled_descriptor_sequence_terminated"], true);
        assert_eq!(copies["image_origin_known"], false);
        assert_eq!(copies["whole_image_validated"], false);
        assert!(copies["qualified_path_table_copy_agreement"].is_null());
        assert!(
            copies["mapping_assumption"]
                .as_str()
                .unwrap()
                .contains("assumed")
        );
        assert_eq!(copies["tables"].as_array().unwrap().len(), 4);
        assert_eq!(copies["pairs"].as_array().unwrap().len(), 2);
        for (index, start, end, field, order, digest) in [
            (
                0,
                40_960,
                40_970,
                32_908,
                "little_endian",
                "57fa0495ccc913dcdb2fb3976b359de7f16080b01ee9a64d09d522c872206beb",
            ),
            (
                1,
                43_008,
                43_018,
                32_912,
                "little_endian",
                "57fa0495ccc913dcdb2fb3976b359de7f16080b01ee9a64d09d522c872206beb",
            ),
            (
                2,
                45_056,
                45_066,
                32_916,
                "big_endian",
                "b1248feaae11e6b0e2cba00df46e0b7bfb2176efa7dc48ef4da4a9620a7447a1",
            ),
            (
                3,
                47_104,
                47_114,
                32_920,
                "big_endian",
                "b1248feaae11e6b0e2cba00df46e0b7bfb2176efa7dc48ef4da4a9620a7447a1",
            ),
        ] {
            let table = &copies["tables"][index];
            assert_eq!(table["candidate_sample_offset"], start);
            assert_eq!(table["candidate_sample_end_exclusive"], end);
            assert_eq!(table["source_location_field_offset"], field);
            assert_eq!(table["byte_order"], order);
            assert_eq!(table["status"], "complete_candidate_range");
            assert_eq!(table["bytes_hashed"], 10);
            assert_eq!(table["available_bytes"], 10);
            assert_eq!(table["raw_sha256"], digest);
        }
        for (index, order) in [(0, "little_endian"), (1, "big_endian")] {
            let pair = &copies["pairs"][index];
            assert_eq!(pair["byte_order"], order);
            assert_eq!(pair["status"], "candidate_ranges_equal");
            assert_eq!(pair["candidate_range_bytes_equal"], true);
            assert_eq!(pair["compared_bytes"], 10);
            assert!(pair["first_difference"].is_null());
        }
        let mut padding_changed = iso_path_table_sample();
        padding_changed[43_018] = 255;
        let after = serde_json::to_value(parse(&padding_changed)).unwrap();
        assert_eq!(
            after["iso_path_table_copies"]["tables"][1]["raw_sha256"],
            copies["tables"][1]["raw_sha256"]
        );
        assert_eq!(
            after["iso_path_table_copies"]["pairs"][0]["candidate_range_bytes_equal"],
            true
        );
    }

    #[test]
    fn iso_path_tables_retain_byte_witnesses_and_descriptor_sequence_coverage() {
        let mut sample = iso_path_table_sample();
        sample[43_010] = 25;
        sample[47_109] = 25;
        // Later malformed descriptor does not erase the explicitly first-PVD
        // declarations, nor grant a complete descriptor-sequence claim.
        sample[34_817] = b'X';
        let metadata = serde_json::to_value(parse(&sample)).unwrap();
        let copies = &metadata["iso_path_table_copies"];
        assert_eq!(copies["sequence_status"], "malformed");
        assert_eq!(copies["sampled_descriptor_sequence_terminated"], false);
        for (index, relative, required, optional) in
            [(0, 2, 40_962, 43_010), (1, 5, 45_061, 47_109)]
        {
            let pair = &copies["pairs"][index];
            assert_eq!(pair["status"], "candidate_ranges_differ");
            assert_eq!(pair["candidate_range_bytes_equal"], false);
            assert_eq!(pair["compared_bytes"], 10);
            assert_eq!(pair["first_difference"]["table_relative_offset"], relative);
            assert_eq!(pair["first_difference"]["required_sample_offset"], required);
            assert_eq!(pair["first_difference"]["optional_sample_offset"], optional);
            assert_eq!(pair["first_difference"]["required_byte"], 24);
            assert_eq!(pair["first_difference"]["optional_byte"], 25);
        }
        assert_eq!(
            copies["tables"][1]["raw_sha256"],
            "17beacfa5fb7a68e2dc29f8aad02afc68e48a2698c1d8a4c696c1c19ec5350e9"
        );
        assert_eq!(
            copies["tables"][3]["raw_sha256"],
            "24b363ad2217b1d3133ff49b0a25a2eb656ca1d66a8840003736580cfbcab424"
        );
    }

    #[test]
    fn iso_path_tables_withhold_equality_for_absent_aliased_partial_or_unsupported_ranges() {
        for (field, bytes, index, expected) in [
            (144, [0, 0, 0, 0], 1, "absent"),
            (140, [0, 0, 0, 0], 0, "required_location_zero_unqualified"),
            (136, [0, 0, 0, 11], 1, "unavailable_declared_geometry"),
            (140, [32, 0, 0, 0], 0, "outside_declared_volume"),
        ] {
            let mut sample = iso_path_table_sample();
            sample[32_768 + field..32_772 + field].copy_from_slice(&bytes);
            let metadata = serde_json::to_value(parse(&sample)).unwrap();
            let copies = &metadata["iso_path_table_copies"];
            assert_eq!(copies["tables"][index]["status"], expected);
            assert!(copies["tables"][index]["raw_sha256"].is_null());
            assert_eq!(copies["tables"][index]["bytes_hashed"], 0);
            assert!(copies["pairs"][0]["candidate_range_bytes_equal"].is_null());
        }
        let mut alias = iso_path_table_sample();
        alias[32_912..32_916].copy_from_slice(&[20, 0, 0, 0]);
        let metadata = serde_json::to_value(parse(&alias)).unwrap();
        assert_eq!(
            metadata["iso_path_table_copies"]["pairs"][0]["status"],
            "same_declared_extent"
        );
        assert!(
            metadata["iso_path_table_copies"]["pairs"][0]["candidate_range_bytes_equal"].is_null()
        );
        let mut partial = iso_path_table_sample();
        partial.truncate(43_017);
        let metadata = serde_json::to_value(parse(&partial)).unwrap();
        assert_eq!(
            metadata["iso_path_table_copies"]["tables"][1]["status"],
            "candidate_range_not_fully_sampled"
        );
        assert_eq!(
            metadata["iso_path_table_copies"]["tables"][1]["available_bytes"],
            9
        );
        assert!(metadata["iso_path_table_copies"]["tables"][1]["raw_sha256"].is_null());
        assert!(
            metadata["iso_path_table_copies"]["pairs"][0]["candidate_range_bytes_equal"].is_null()
        );
        let mut unsupported = iso_path_table_sample();
        unsupported[33_649] = 2;
        let metadata = serde_json::to_value(parse(&unsupported)).unwrap();
        assert_eq!(
            metadata["iso_path_table_copies"]["tables"][0]["status"],
            "unsupported_file_structure"
        );
        assert!(
            metadata["iso_path_table_copies"]["pairs"][0]["candidate_range_bytes_equal"].is_null()
        );
    }

    #[test]
    fn iso_path_tables_bound_hash_work_and_withhold_overlapping_copy_equality() {
        for size in [0u32, 65_536, 65_537] {
            let mut sample = iso_path_table_sample();
            sample.resize(303_105, 0);
            let pvd = &mut sample[32_768..34_816];
            pvd[80..84].copy_from_slice(&200u32.to_le_bytes());
            pvd[84..88].copy_from_slice(&200u32.to_be_bytes());
            pvd[132..136].copy_from_slice(&size.to_le_bytes());
            pvd[136..140].copy_from_slice(&size.to_be_bytes());
            for (field, location, big) in [
                (140, 20u32, false),
                (144, 52, false),
                (148, 84, true),
                (152, 116, true),
            ] {
                pvd[field..field + 4].copy_from_slice(&if big {
                    location.to_be_bytes()
                } else {
                    location.to_le_bytes()
                });
            }
            // Exact bounded table payloads, with different endian encodings.
            sample[40_960..303_105].fill(0);
            for offset in [40_960, 106_496] {
                sample[offset..offset + 10].copy_from_slice(&[1, 0, 24, 0, 0, 0, 1, 0, 0, 0]);
            }
            for offset in [172_032, 237_568] {
                sample[offset..offset + 10].copy_from_slice(&[1, 0, 0, 0, 0, 24, 0, 1, 0, 0]);
            }
            let metadata = serde_json::to_value(parse(&sample)).unwrap();
            let copies = &metadata["iso_path_table_copies"];
            assert_eq!(copies["table_size_limit_bytes"], 65_536);
            for (index, table) in copies["tables"].as_array().unwrap().iter().enumerate() {
                if size == 65_536 {
                    assert_eq!(table["status"], "complete_candidate_range");
                    assert_eq!(table["bytes_hashed"], 65_536);
                    assert_eq!(
                        table["raw_sha256"],
                        if index < 2 {
                            "94eb97e11bcf0a5e73894b443959f0296cd6ec9603de03444d3b0958362d9b17"
                        } else {
                            "47003487573514d7300a1bee6e45039ede7b2aa76cc5f41e57a7bcfdba047f84"
                        }
                    );
                } else {
                    assert_eq!(
                        table["status"],
                        if size == 0 {
                            "zero_length_unqualified"
                        } else {
                            "size_limit_exceeded"
                        }
                    );
                    assert_eq!(table["bytes_hashed"], 0);
                    assert!(table["raw_sha256"].is_null());
                }
            }
            for pair in copies["pairs"].as_array().unwrap() {
                if size == 65_536 {
                    assert_eq!(pair["candidate_range_bytes_equal"], true);
                    assert_eq!(pair["compared_bytes"], 65_536);
                } else {
                    assert!(pair["candidate_range_bytes_equal"].is_null());
                    assert_eq!(pair["compared_bytes"], 0);
                }
            }
        }
        let mut sample = iso_path_table_sample();
        sample[32_848..32_856].copy_from_slice(&[200, 0, 0, 0, 0, 0, 0, 200]);
        sample[32_896..32_900].copy_from_slice(&[0, 2, 2, 0]);
        sample[32_900..32_908].copy_from_slice(&[0, 4, 0, 0, 0, 0, 4, 0]);
        sample[32_908..32_916].copy_from_slice(&[80, 0, 0, 0, 81, 0, 0, 0]);
        let metadata = serde_json::to_value(parse(&sample)).unwrap();
        let copies = &metadata["iso_path_table_copies"];
        assert_eq!(copies["pairs"][0]["status"], "overlapping_declared_extents");
        assert!(copies["pairs"][0]["candidate_range_bytes_equal"].is_null());
        assert_eq!(copies["pairs"][0]["compared_bytes"], 0);
        assert_eq!(copies["tables"][0]["bytes_hashed"], 1024);
        assert_eq!(copies["tables"][1]["bytes_hashed"], 1024);
        assert!(
            serde_json::to_value(parse(b"not ISO")).unwrap()["iso_path_table_copies"].is_null()
        );
    }

    #[test]
    fn iso_path_tables_use_first_primary_descriptor_after_other_descriptor_types() {
        for preceding_type in [0, 2] {
            let mut sample = iso_path_table_sample();
            let primary = sample[32_768..34_816].to_vec();
            sample[32_768..34_816].copy_from_slice(&iso_volume_descriptor(preceding_type, 1));
            sample[34_816..36_864].copy_from_slice(&primary);
            let mut conflicting_primary = primary.clone();
            conflicting_primary[140..144].copy_from_slice(&24u32.to_le_bytes());
            sample[36_864..38_912].copy_from_slice(&conflicting_primary);
            sample[38_912..40_960].copy_from_slice(&iso_volume_descriptor(255, 1));
            let metadata = serde_json::to_value(parse(&sample)).unwrap();
            let copies = &metadata["iso_path_table_copies"];
            assert_eq!(copies["source_primary_descriptor_offset"], 34_816);
            assert_eq!(
                copies["source_primary_descriptor_sha256"],
                hex(&Sha256::digest(&primary))
            );
            assert_eq!(copies["source_size_little_endian_field_offset"], 34_948);
            assert_eq!(copies["source_size_big_endian_field_offset"], 34_952);
            assert_eq!(copies["tables"][0]["source_location_field_offset"], 34_956);
            assert_eq!(copies["tables"][0]["location_logical_block"], 20);
            assert_eq!(copies["tables"][0]["candidate_sample_offset"], 40_960);
            assert_eq!(copies["pairs"][0]["candidate_range_bytes_equal"], true);
            assert_eq!(copies["sequence_status"], "terminated");
            assert_eq!(copies["image_origin_known"], false);
            assert!(copies["qualified_path_table_copy_agreement"].is_null());
            assert_eq!(
                metadata["iso_primary_volume_descriptor_copies"]["all_observed_copies_equal"],
                false
            );
        }
    }

    fn iso_volume_descriptor(descriptor_type: u8, version: u8) -> Vec<u8> {
        let mut descriptor = vec![0u8; 2048];
        descriptor[0] = descriptor_type;
        descriptor[1..6].copy_from_slice(b"CD001");
        descriptor[6] = version;
        descriptor
    }

    fn iso_declaration_json(descriptor: &[u8]) -> serde_json::Value {
        let mut iso = vec![0u8; ISO_PVD_OFFSET];
        iso.extend_from_slice(descriptor);
        let metadata = serde_json::to_value(parse(&iso)).unwrap();
        metadata["iso_descriptor_sequence"]["descriptors"][0]["character_set_declaration"].clone()
    }

    #[test]
    fn iso_character_set_declarations_recognize_each_joliet_level_and_ecma6() {
        for (marker, level) in [(b"%/@", 1), (b"%/C", 2), (b"%/E", 3)] {
            let mut descriptor = iso_volume_descriptor(2, 1);
            descriptor[881] = 1;
            descriptor[88..91].copy_from_slice(marker);
            let declaration = iso_declaration_json(&descriptor);
            assert_eq!(declaration["status"], "recognized_joliet_declaration");
            assert_eq!(declaration["joliet_prefix_level_observed"], level);
            assert_eq!(declaration["recognized_character_set"], "ucs2_be");
            assert_eq!(
                declaration["escape_sequences_raw_hex"],
                hex(&descriptor[88..120])
            );
            assert_eq!(declaration["directory_hierarchy_inspected"], false);
        }
        let mut descriptor = iso_volume_descriptor(2, 1);
        descriptor[881] = 1;
        let declaration = iso_declaration_json(&descriptor);
        assert_eq!(declaration["status"], "recognized_ecma6_declaration");
        assert_eq!(declaration["recognized_character_set"], "ecma6");
        assert_eq!(declaration["escape_sequences_all_zero"], true);
        assert!(declaration["joliet_prefix_level_observed"].is_null());
        descriptor[7] = 1;
        let conflicting_default = iso_declaration_json(&descriptor);
        assert_eq!(conflicting_default["status"], "inconsistent_declaration");
        assert_eq!(conflicting_default["escape_sequences_all_zero"], true);
        assert_eq!(
            conflicting_default["escape_sequences_raw_hex"],
            "00".repeat(32)
        );
        assert!(conflicting_default["recognized_character_set"].is_null());
    }

    #[test]
    fn iso_character_set_declarations_withhold_conflicting_and_uninterpreted_values() {
        let mut descriptor = iso_volume_descriptor(2, 1);
        descriptor[881] = 1;
        descriptor[88..91].copy_from_slice(b"%/E");
        for flags in [1, 2, 4, 8, 16, 32, 64, 128, 255] {
            descriptor[7] = flags;
            let declaration = iso_declaration_json(&descriptor);
            assert_eq!(declaration["status"], "inconsistent_declaration");
            assert_eq!(declaration["volume_flags_observed"], flags);
            assert_eq!(declaration["joliet_prefix_level_observed"], 3);
            assert!(declaration["recognized_character_set"].is_null());
        }
        descriptor[7] = 0;
        descriptor[881] = 2;
        assert_eq!(
            iso_declaration_json(&descriptor)["status"],
            "inconsistent_declaration"
        );
        descriptor[6] = 2;
        let enhanced = iso_declaration_json(&descriptor);
        assert_eq!(enhanced["status"], "enhanced_declaration_uninterpreted");
        assert_eq!(enhanced["file_structure_version_valid"], true);
        assert_eq!(enhanced["joliet_prefix_level_observed"], 3);
        assert!(enhanced["recognized_character_set"].is_null());

        descriptor[6] = 1;
        descriptor[881] = 1;
        for position in [91, 100, 119] {
            descriptor[position] = 0x41;
            let declaration = iso_declaration_json(&descriptor);
            assert_eq!(declaration["status"], "uninterpreted_escape_declaration");
            assert!(declaration["recognized_character_set"].is_null());
            descriptor[position] = 0;
        }
        descriptor[7] = 1;
        descriptor[91..94].copy_from_slice(b"$+:");
        let multiple = iso_declaration_json(&descriptor);
        assert_eq!(multiple["status"], "uninterpreted_escape_declaration");
        assert_eq!(multiple["joliet_prefix_level_observed"], 3);
        assert!(multiple["recognized_character_set"].is_null());
        descriptor[7] = 0;
        descriptor[91..94].fill(0);
        for marker in [b"\x1b%/".as_slice(), b"%/e", b" %/"] {
            descriptor[88..91].copy_from_slice(marker);
            let declaration = iso_declaration_json(&descriptor);
            assert_eq!(declaration["status"], "uninterpreted_escape_declaration");
            assert!(declaration["joliet_prefix_level_observed"].is_null());
        }
    }

    #[test]
    fn iso_character_set_declarations_remain_bounded_and_sample_relative() {
        let mut descriptor = iso_volume_descriptor(2, 1);
        descriptor[881] = 1;
        descriptor[88..91].copy_from_slice(b"%/E");
        for descriptor_type in [0, 1, 3, 255] {
            descriptor[0] = descriptor_type;
            assert!(iso_declaration_json(&descriptor).is_null());
        }
        descriptor[0] = 2;
        descriptor[6] = 3;
        assert!(iso_declaration_json(&descriptor).is_null());
        descriptor[6] = 1;
        // A later malformed identifier stays visible without being interpreted.
        let mut iso = vec![0u8; ISO_PVD_OFFSET];
        iso.extend_from_slice(&descriptor);
        let mut bad = descriptor.clone();
        bad[1] = b'X';
        iso.extend_from_slice(&bad);
        let json = serde_json::to_value(parse(&iso)).unwrap();
        let sequence = &json["iso_descriptor_sequence"];
        assert_eq!(sequence["status"], "malformed");
        assert_eq!(sequence["image_origin_known"], false);
        assert_eq!(
            sequence["descriptors"][0]["character_set_declaration"]["status"],
            "recognized_joliet_declaration"
        );
        assert!(sequence["descriptors"][1]["character_set_declaration"].is_null());
        for retained_bytes in [7, 88, 91, 119, 120, 881, 882, 2047] {
            let json =
                serde_json::to_value(parse(&iso[..ISO_PVD_OFFSET + retained_bytes])).unwrap();
            assert!(
                json["iso_descriptor_sequence"]["descriptors"]
                    .as_array()
                    .unwrap()
                    .is_empty()
            );
            assert_eq!(
                json["iso_descriptor_sequence"]["status"],
                "sample_truncated"
            );
        }
        let json = serde_json::to_value(parse(&iso[..ISO_PVD_OFFSET + 2048])).unwrap();
        assert_eq!(
            json["iso_descriptor_sequence"]["status"],
            "sample_truncated"
        );
        assert_eq!(
            json["iso_descriptor_sequence"]["descriptors"][0]["character_set_declaration"]["recognized_character_set"],
            "ucs2_be"
        );
        let mut bounded = vec![0u8; ISO_PVD_OFFSET];
        for _ in 0..241 {
            bounded.extend_from_slice(&descriptor);
        }
        let json = serde_json::to_value(parse(&bounded)).unwrap();
        let sequence = &json["iso_descriptor_sequence"];
        assert_eq!(sequence["status"], "scan_limit_reached");
        let observations = sequence["descriptors"].as_array().unwrap();
        assert_eq!(observations.len(), 240);
        assert!(observations.iter().all(|observation| {
            observation["character_set_declaration"]["recognized_character_set"] == "ucs2_be"
                && observation["character_set_declaration"]["escape_sequences_raw_hex"]
                    .as_str()
                    .unwrap()
                    .len()
                    == 64
        }));
    }

    #[test]
    fn withholds_iso_geometry_when_dual_endian_fields_conflict() {
        let mut iso = vec![0u8; ISO_PVD_OFFSET + 2048];
        let pvd = &mut iso[ISO_PVD_OFFSET..];
        pvd[0] = 1;
        pvd[1..6].copy_from_slice(b"CD001");
        pvd[6] = 1;
        pvd[80..84].copy_from_slice(&100u32.to_le_bytes());
        pvd[84..88].copy_from_slice(&200u32.to_be_bytes());
        pvd[128..130].copy_from_slice(&2048u16.to_le_bytes());
        pvd[130..132].copy_from_slice(&4096u16.to_be_bytes());

        let metadata = parse(&iso);
        let json = serde_json::to_value(metadata).unwrap();
        let header = &json["headers"][0];

        assert_eq!(header["volume_space_blocks_little_endian_observed"], 100);
        assert_eq!(header["volume_space_blocks_big_endian_observed"], 200);
        assert_eq!(header["dual_endian_volume_space_match"], false);
        assert_eq!(header["volume_space_blocks"], 100);
        assert_eq!(
            header["effective_volume_space_blocks"],
            serde_json::Value::Null
        );
        assert_eq!(header["logical_block_size_little_endian_observed"], 2048);
        assert_eq!(header["logical_block_size_big_endian_observed"], 4096);
        assert_eq!(header["dual_endian_logical_block_size_match"], false);
        assert_eq!(header["logical_block_size"], 2048);
        assert_eq!(header["logical_block_size_valid"], false);
        assert_eq!(
            header["effective_logical_block_size"],
            serde_json::Value::Null
        );
        assert_eq!(header["volume_space_bytes"], serde_json::Value::Null);
    }

    #[test]
    fn derives_iso_volume_bytes_only_from_matching_supported_geometry() {
        for block_size in [512u16, 1024, 2048] {
            let mut iso = vec![0u8; ISO_PVD_OFFSET + 2048];
            let pvd = &mut iso[ISO_PVD_OFFSET..];
            pvd[0] = 1;
            pvd[1..6].copy_from_slice(b"CD001");
            pvd[6] = 1;
            pvd[80..84].copy_from_slice(&100u32.to_le_bytes());
            pvd[84..88].copy_from_slice(&100u32.to_be_bytes());
            pvd[128..130].copy_from_slice(&block_size.to_le_bytes());
            pvd[130..132].copy_from_slice(&block_size.to_be_bytes());

            let metadata = parse(&iso);
            let json = serde_json::to_value(metadata).unwrap();
            let header = &json["headers"][0];

            assert_eq!(header["effective_volume_space_blocks"], 100);
            assert_eq!(header["effective_logical_block_size"], block_size);
            assert_eq!(
                header["volume_space_bytes"],
                u64::from(100u32) * u64::from(block_size)
            );
        }

        for unsupported_size in [0u16, 256, 4096] {
            let mut iso = vec![0u8; ISO_PVD_OFFSET + 2048];
            let pvd = &mut iso[ISO_PVD_OFFSET..];
            pvd[0] = 1;
            pvd[1..6].copy_from_slice(b"CD001");
            pvd[6] = 1;
            pvd[80..84].copy_from_slice(&100u32.to_le_bytes());
            pvd[84..88].copy_from_slice(&100u32.to_be_bytes());
            pvd[128..130].copy_from_slice(&unsupported_size.to_le_bytes());
            pvd[130..132].copy_from_slice(&unsupported_size.to_be_bytes());

            let metadata = parse(&iso);
            let json = serde_json::to_value(metadata).unwrap();
            let header = &json["headers"][0];

            assert_eq!(header["logical_block_size_valid"], false);
            assert_eq!(
                header["effective_logical_block_size"],
                serde_json::Value::Null
            );
            assert_eq!(header["volume_space_bytes"], serde_json::Value::Null);
        }
    }

    #[test]
    fn validates_iso_root_directory_flags_and_separates_extent_from_data_start() {
        let mut iso = vec![0u8; ISO_PVD_OFFSET + 2048];
        let pvd = &mut iso[ISO_PVD_OFFSET..];
        pvd[0] = 1;
        pvd[1..6].copy_from_slice(b"CD001");
        pvd[6] = 1;
        pvd[80..84].copy_from_slice(&100u32.to_le_bytes());
        pvd[84..88].copy_from_slice(&100u32.to_be_bytes());
        pvd[120..122].copy_from_slice(&1u16.to_le_bytes());
        pvd[122..124].copy_from_slice(&1u16.to_be_bytes());
        pvd[124..126].copy_from_slice(&1u16.to_le_bytes());
        pvd[126..128].copy_from_slice(&1u16.to_be_bytes());
        pvd[128..130].copy_from_slice(&2048u16.to_le_bytes());
        pvd[130..132].copy_from_slice(&2048u16.to_be_bytes());
        pvd[156] = 34;
        pvd[157] = 2;
        pvd[158..162].copy_from_slice(&10u32.to_le_bytes());
        pvd[162..166].copy_from_slice(&10u32.to_be_bytes());
        pvd[166..170].copy_from_slice(&4096u32.to_le_bytes());
        pvd[170..174].copy_from_slice(&4096u32.to_be_bytes());
        pvd[181] = 0x02;
        pvd[184..186].copy_from_slice(&1u16.to_le_bytes());
        pvd[186..188].copy_from_slice(&1u16.to_be_bytes());
        pvd[188] = 1;
        pvd[189] = 0;

        let valid = serde_json::to_value(parse(&iso)).unwrap();
        assert_eq!(
            valid["headers"][0]["volume_set_sequence_relationship_valid"],
            true
        );
        let root = &valid["headers"][0]["root_directory_record"];
        assert_eq!(root["record_structure_valid"], true);
        assert_eq!(root["extent_start_byte_offset"], 20_480);
        assert_eq!(root["data_start_byte_offset"], 24_576);
        assert_eq!(root["data_end_byte_exclusive"], 28_672);

        iso[ISO_PVD_OFFSET + 181] = 0x22;
        let reserved_flag = serde_json::to_value(parse(&iso)).unwrap();
        let root = &reserved_flag["headers"][0]["root_directory_record"];
        assert_eq!(root["directory_reserved_flag_bits_valid"], false);
        assert_eq!(root["record_structure_valid"], false);
        assert_eq!(root["extent_status"], "invalid_record_structure");

        iso[ISO_PVD_OFFSET + 181] = 0x12;
        iso[ISO_PVD_OFFSET + 157] = 0;
        let missing_attributes = serde_json::to_value(parse(&iso)).unwrap();
        let root = &missing_attributes["headers"][0]["root_directory_record"];
        assert_eq!(
            root["protection_flag_requires_extended_attributes_valid"],
            false
        );
        assert_eq!(root["record_structure_valid"], false);
    }

    #[test]
    fn validates_iso_root_extent_start_against_logical_sector_boundary() {
        for (root_lbn, expected_aligned, expected_status) in [
            (80u32, true, "within_declared_volume"),
            (81u32, false, "misaligned_extent_start"),
        ] {
            let mut iso = vec![0u8; ISO_PVD_OFFSET + 2048];
            let pvd = &mut iso[ISO_PVD_OFFSET..];
            pvd[0] = 1;
            pvd[1..6].copy_from_slice(b"CD001");
            pvd[6] = 1;
            pvd[80..84].copy_from_slice(&128u32.to_le_bytes());
            pvd[84..88].copy_from_slice(&128u32.to_be_bytes());
            pvd[120..122].copy_from_slice(&1u16.to_le_bytes());
            pvd[122..124].copy_from_slice(&1u16.to_be_bytes());
            pvd[124..126].copy_from_slice(&1u16.to_le_bytes());
            pvd[126..128].copy_from_slice(&1u16.to_be_bytes());
            pvd[128..130].copy_from_slice(&512u16.to_le_bytes());
            pvd[130..132].copy_from_slice(&512u16.to_be_bytes());
            pvd[156] = 34;
            pvd[158..162].copy_from_slice(&root_lbn.to_le_bytes());
            pvd[162..166].copy_from_slice(&root_lbn.to_be_bytes());
            pvd[166..170].copy_from_slice(&2048u32.to_le_bytes());
            pvd[170..174].copy_from_slice(&2048u32.to_be_bytes());
            pvd[181] = 0x02;
            pvd[184..186].copy_from_slice(&1u16.to_le_bytes());
            pvd[186..188].copy_from_slice(&1u16.to_be_bytes());
            pvd[188] = 1;

            let metadata = serde_json::to_value(parse(&iso)).unwrap();
            let root = &metadata["headers"][0]["root_directory_record"];
            assert_eq!(root["extent_start_sector_aligned"], expected_aligned);
            assert_eq!(root["within_declared_volume"], true);
            assert_eq!(root["extent_status"], expected_status);
        }
    }

    #[test]
    fn rejects_iso_volume_sequence_outside_declared_set() {
        for (set_size, sequence) in [(0u16, 0u16), (1, 0), (1, 2)] {
            let mut iso = vec![0u8; ISO_PVD_OFFSET + 2048];
            let pvd = &mut iso[ISO_PVD_OFFSET..];
            pvd[0] = 1;
            pvd[1..6].copy_from_slice(b"CD001");
            pvd[6] = 1;
            pvd[80..84].copy_from_slice(&100u32.to_le_bytes());
            pvd[84..88].copy_from_slice(&100u32.to_be_bytes());
            pvd[120..122].copy_from_slice(&set_size.to_le_bytes());
            pvd[122..124].copy_from_slice(&set_size.to_be_bytes());
            pvd[124..126].copy_from_slice(&sequence.to_le_bytes());
            pvd[126..128].copy_from_slice(&sequence.to_be_bytes());
            pvd[128..130].copy_from_slice(&2048u16.to_le_bytes());
            pvd[130..132].copy_from_slice(&2048u16.to_be_bytes());
            pvd[156] = 34;
            pvd[158..162].copy_from_slice(&10u32.to_le_bytes());
            pvd[162..166].copy_from_slice(&10u32.to_be_bytes());
            pvd[166..170].copy_from_slice(&2048u32.to_le_bytes());
            pvd[170..174].copy_from_slice(&2048u32.to_be_bytes());
            pvd[181] = 0x02;
            pvd[184..186].copy_from_slice(&sequence.to_le_bytes());
            pvd[186..188].copy_from_slice(&sequence.to_be_bytes());
            pvd[188] = 1;

            let metadata = serde_json::to_value(parse(&iso)).unwrap();
            let header = &metadata["headers"][0];
            assert_eq!(header["volume_set_sequence_relationship_valid"], false);
            assert_eq!(header["volume_sequence_number"], serde_json::Value::Null);
            assert_eq!(
                header["root_directory_record"]["descriptor_volume_sequence_matches"],
                serde_json::Value::Null
            );
            assert_eq!(
                header["root_directory_record"]["extent_status"],
                "unavailable"
            );
        }
    }

    #[test]
    fn relates_iso_path_table_ranges_to_consistent_declared_volume_geometry() {
        let mut iso = vec![0u8; ISO_PVD_OFFSET + 2048];
        {
            let pvd = &mut iso[ISO_PVD_OFFSET..];
            pvd[0] = 1;
            pvd[1..6].copy_from_slice(b"CD001");
            pvd[6] = 1;
            pvd[80..84].copy_from_slice(&10u32.to_le_bytes());
            pvd[84..88].copy_from_slice(&10u32.to_be_bytes());
            pvd[128..130].copy_from_slice(&512u16.to_le_bytes());
            pvd[130..132].copy_from_slice(&512u16.to_be_bytes());
            pvd[132..136].copy_from_slice(&512u32.to_le_bytes());
            pvd[136..140].copy_from_slice(&512u32.to_be_bytes());
            pvd[140..144].copy_from_slice(&9u32.to_le_bytes());
            pvd[144..148].copy_from_slice(&0u32.to_le_bytes());
            pvd[148..152].copy_from_slice(&10u32.to_be_bytes());
            pvd[152..156].copy_from_slice(&8u32.to_be_bytes());
        }

        let json = serde_json::to_value(parse(&iso)).unwrap();
        let ranges = json["headers"][0]["path_table_ranges"].as_array().unwrap();

        assert_eq!(ranges[0]["byte_offset"], 4608);
        assert_eq!(ranges[0]["byte_end_exclusive"], 5120);
        assert_eq!(ranges[0]["within_declared_volume"], true);
        assert_eq!(ranges[0]["status"], "within_declared_volume");
        assert_eq!(ranges[1]["status"], "absent");
        assert_eq!(ranges[1]["within_declared_volume"], serde_json::Value::Null);
        assert_eq!(ranges[2]["byte_offset"], 5120);
        assert_eq!(ranges[2]["within_declared_volume"], false);
        assert_eq!(ranges[2]["status"], "outside_declared_volume");
        assert_eq!(ranges[3]["within_declared_volume"], true);

        iso[ISO_PVD_OFFSET + 136..ISO_PVD_OFFSET + 140].copy_from_slice(&513u32.to_be_bytes());
        let conflicting = serde_json::to_value(parse(&iso)).unwrap();
        let conflicting_ranges = conflicting["headers"][0]["path_table_ranges"]
            .as_array()
            .unwrap();
        assert_eq!(conflicting_ranges[0]["status"], "unavailable");
        assert_eq!(
            conflicting_ranges[0]["within_declared_volume"],
            serde_json::Value::Null
        );
    }

    #[test]
    fn reports_7z_next_header_kind_and_bounded_payload_observations() {
        for (observed_type, expected_kind) in [(0x01, "plain_header"), (0x17, "encoded_header")] {
            let mut next_header = vec![b'x'; 300];
            next_header[0] = observed_type;
            let metadata = parse(&seven_zip_with_next_header(&next_header));
            let json = serde_json::to_value(metadata).unwrap();
            let header = &json["headers"][0];

            assert_eq!(header["next_header_observed_type"], observed_type);
            assert_eq!(header["next_header_kind"], expected_kind);
            assert_eq!(
                header["next_header_interpretation_status"],
                "checksums_valid"
            );
            assert_eq!(header["next_header_observed_byte_length"], 300);
            assert_eq!(
                header["next_header_preview_hex"].as_str().unwrap().len(),
                512
            );
            assert!(header["next_header_preview_truncated"].as_bool().unwrap());
            assert_eq!(header["next_header_sha256"].as_str().unwrap().len(), 64);
        }
    }

    #[test]
    fn withholds_7z_next_header_classification_when_checksums_fail() {
        let mut data = seven_zip_with_next_header(&[0x01, 0x00]);
        data[32] = 0x17;

        let metadata = parse(&data);
        let json = serde_json::to_value(metadata).unwrap();
        let header = &json["headers"][0];

        assert_eq!(header["next_header_observed_type"], 0x17);
        assert_eq!(header["next_header_kind"], serde_json::Value::Null);
        assert_eq!(
            header["next_header_interpretation_status"],
            "next_header_checksum_invalid"
        );
        assert_eq!(header["next_header_observed_byte_length"], 2);
        assert!(header["next_header_sha256"].as_str().is_some());
    }

    #[test]
    fn identifies_an_empty_7z_next_header_without_inventing_a_type_byte() {
        let metadata = parse(&seven_zip_with_next_header(&[]));
        let json = serde_json::to_value(metadata).unwrap();
        let header = &json["headers"][0];

        assert_eq!(header["next_header_observed_type"], serde_json::Value::Null);
        assert_eq!(header["next_header_kind"], "empty");
        assert_eq!(header["next_header_interpretation_status"], "empty");
        assert_eq!(header["next_header_observed_byte_length"], 0);
        assert_eq!(header["next_header_sha256"].as_str().unwrap().len(), 64);
        assert!(!header["next_header_preview_truncated"].as_bool().unwrap());
    }

    #[test]
    fn rejects_7z_empty_next_header_with_nonzero_offset_or_crc() {
        let mut data = seven_zip_with_next_header(&[0]);
        data[12..20].copy_from_slice(&1u64.to_le_bytes());
        data[20..28].copy_from_slice(&0u64.to_le_bytes());
        data[28..32].copy_from_slice(&0u32.to_le_bytes());
        let start_header_crc = crc32fast::hash(&data[12..32]);
        data[8..12].copy_from_slice(&start_header_crc.to_le_bytes());

        let json = serde_json::to_value(parse(&data)).unwrap();
        let header = &json["headers"][0];
        assert_eq!(
            header["next_header_interpretation_status"],
            "empty_header_fields_inconsistent"
        );
        assert_eq!(header["next_header_kind"], serde_json::Value::Null);
        assert_eq!(header["next_header_observed_byte_length"], 0);
        assert!(header["next_header_sha256"].as_str().is_some());
    }

    #[test]
    fn observes_but_does_not_interpret_unsupported_7z_major_version() {
        let mut data = seven_zip_with_next_header(&[0x17, 0x06]);
        data[6] = 1;
        let start_header_crc = crc32fast::hash(&data[12..32]);
        data[8..12].copy_from_slice(&start_header_crc.to_le_bytes());

        let json = serde_json::to_value(parse(&data)).unwrap();
        let header = &json["headers"][0];
        assert_eq!(header["major_version"], 1);
        assert_eq!(header["major_version_supported"], false);
        assert_eq!(
            header["next_header_interpretation_status"],
            "unsupported_major_version"
        );
        assert_eq!(header["next_header_kind"], serde_json::Value::Null);
        assert_eq!(header["next_header_observed_type"], 0x17);
        assert!(header["next_header_sha256"].as_str().is_some());
    }

    #[test]
    fn parses_7z_encoded_header_pack_stream_range_and_crc() {
        let packed = b"xy";
        let expected_crc = crc32fast::hash(packed);
        let mut encoded_header = vec![0x17, 0x06, 0x00, 0x01, 0x09, 0x02, 0x0a, 0x01];
        encoded_header.extend_from_slice(&expected_crc.to_le_bytes());
        encoded_header.extend_from_slice(&[0x00, 0x00]);
        let mut data = vec![0u8; 32];
        data[..6].copy_from_slice(SEVEN_ZIP);
        data[6] = 0;
        data[7] = 4;
        data[12..20].copy_from_slice(&(packed.len() as u64).to_le_bytes());
        data[20..28].copy_from_slice(&(encoded_header.len() as u64).to_le_bytes());
        data[28..32].copy_from_slice(&crc32fast::hash(&encoded_header).to_le_bytes());
        let start_header_crc = crc32fast::hash(&data[12..32]);
        data[8..12].copy_from_slice(&start_header_crc.to_le_bytes());
        data.extend_from_slice(packed);
        data.extend_from_slice(&encoded_header);

        let json = serde_json::to_value(parse(&data)).unwrap();
        let pack_info = &json["headers"][0]["encoded_header_pack_info"];

        assert_eq!(pack_info["status"], "pack_info_complete");
        assert_eq!(pack_info["pack_pos"], 0);
        assert_eq!(pack_info["num_pack_streams"], 1);
        assert_eq!(pack_info["total_packed_size"], 2);
        assert_eq!(pack_info["packed_range_within_pre_header_area"], true);
        assert_eq!(pack_info["packed_streams"][0]["size"], 2);
        assert_eq!(pack_info["packed_streams"][0]["byte_offset"], 32);
        assert_eq!(pack_info["packed_streams"][0]["sample_available"], true);
        assert_eq!(
            pack_info["packed_streams"][0]["crc32_expected"],
            expected_crc
        );
        assert_eq!(
            pack_info["packed_streams"][0]["crc32_calculated"],
            expected_crc
        );
        assert_eq!(pack_info["packed_streams"][0]["crc32_valid"], true);
    }

    #[test]
    fn relates_7z_encoded_header_copy_coder_to_packed_input_and_unpack_output() {
        let packed = b"xy";
        let expected_unpack_crc = 0x1234_5678u32;
        let mut encoded_header = vec![
            0x17, 0x06, 0x00, 0x01, 0x09, 0x02, 0x00, // PackInfo
            0x07, 0x0b, 0x01, 0x00, 0x01, 0x01, 0x00, // one inline Copy coder
            0x0c, 0x02, 0x0a, 0x01, // unpack size and folder CRC
        ];
        encoded_header.extend_from_slice(&expected_unpack_crc.to_le_bytes());
        encoded_header.extend_from_slice(&[0x00, 0x00]); // UnPackInfo and StreamsInfo end

        let mut data = vec![0u8; 32];
        data[..6].copy_from_slice(SEVEN_ZIP);
        data[6] = 0;
        data[7] = 4;
        data[12..20].copy_from_slice(&(packed.len() as u64).to_le_bytes());
        data[20..28].copy_from_slice(&(encoded_header.len() as u64).to_le_bytes());
        data[28..32].copy_from_slice(&crc32fast::hash(&encoded_header).to_le_bytes());
        let start_header_crc = crc32fast::hash(&data[12..32]);
        data[8..12].copy_from_slice(&start_header_crc.to_le_bytes());
        data.extend_from_slice(packed);
        data.extend_from_slice(&encoded_header);

        let json = serde_json::to_value(parse(&data)).unwrap();
        let streams = &json["headers"][0]["encoded_header_streams_info"];

        assert_eq!(streams["status"], "streams_info_complete");
        assert_eq!(streams["num_folders"], 1);
        assert_eq!(streams["external"], false);
        assert_eq!(streams["num_coders"], 1);
        assert_eq!(streams["coder"]["method_id_hex"], "00");
        assert_eq!(streams["coder"]["method_name"], "Copy");
        assert_eq!(streams["coder"]["num_input_streams"], 1);
        assert_eq!(streams["coder"]["num_output_streams"], 1);
        assert_eq!(streams["packed_input_index"], 0);
        assert_eq!(streams["pack_stream_count_matches_folder"], true);
        assert_eq!(streams["unpack_size"], 2);
        assert_eq!(streams["unpack_crc32_expected"], expected_unpack_crc);
        assert_eq!(streams["unpack_crc32_calculated"], serde_json::Value::Null);
        assert_eq!(streams["unpack_crc32_valid"], serde_json::Value::Null);
        assert_eq!(streams["trailing_bytes"], 0);
    }

    #[test]
    fn parses_7z_coder_properties_by_length_even_when_they_contain_control_bytes() {
        let packed = b"x";
        let encoded_header = vec![
            0x17, 0x06, 0x00, 0x01, 0x09, 0x01, 0x00, // one packed stream
            0x07, 0x0b, 0x01, 0x00, 0x01, 0x21, 0x21, // one LZMA2 coder with properties
            0x03, 0x07, 0x0a, 0x00, // three properties containing section-like bytes
            0x0c, 0x01, 0x00, 0x00, // unpack size and both terminators
        ];
        let expected_properties_hash = hex(&Sha256::digest([0x07, 0x0a, 0x00]));
        let data = seven_zip_with_packed_next_header(packed, &encoded_header);

        let json = serde_json::to_value(parse(&data)).unwrap();
        let streams = &json["headers"][0]["encoded_header_streams_info"];

        assert_eq!(streams["status"], "streams_info_complete");
        assert_eq!(streams["coder"]["method_name"], "LZMA2");
        assert_eq!(streams["coder"]["properties_byte_length"], 3);
        assert_eq!(
            streams["coder"]["properties_sha256"],
            expected_properties_hash
        );
        assert_eq!(streams["coder"]["properties_preview_hex"], "070a00");
        assert_eq!(streams["coder"]["properties_preview_truncated"], false);
    }

    #[test]
    fn reports_7z_external_encoded_header_folder_as_unresolved() {
        let packed = b"x";
        let encoded_header = [
            0x17, 0x06, 0x00, 0x01, 0x09, 0x01, 0x00, // PackInfo
            0x07, 0x0b, 0x01, 0x01, 0x03, // one external folder, stream index 3
        ];
        let data = seven_zip_with_packed_next_header(packed, &encoded_header);

        let json = serde_json::to_value(parse(&data)).unwrap();
        let streams = &json["headers"][0]["encoded_header_streams_info"];

        assert_eq!(streams["status"], "external_folder_unsupported");
        assert_eq!(streams["external"], true);
        assert_eq!(streams["external_data_stream_index"], 3);
        assert_eq!(streams["streams_info_terminator_seen"], false);
    }

    #[test]
    fn supports_complex_7z_single_stream_coder_and_preserves_unknown_method_id() {
        let packed = b"x";
        let encoded_header = [
            0x17, 0x06, 0x00, 0x01, 0x09, 0x01, 0x00, // PackInfo
            0x07, 0x0b, 0x01, 0x00, 0x01, 0x11, 0x99, 0x01, 0x01, // complex 1-in/1-out coder
            0x0c, 0x01, 0x00, 0x00,
        ];
        let data = seven_zip_with_packed_next_header(packed, &encoded_header);
        let json = serde_json::to_value(parse(&data)).unwrap();
        let streams = &json["headers"][0]["encoded_header_streams_info"];

        assert_eq!(streams["status"], "streams_info_complete");
        assert_eq!(streams["coder"]["method_id_hex"], "99");
        assert_eq!(streams["coder"]["method_name"], serde_json::Value::Null);
        assert_eq!(streams["coder"]["num_input_streams"], 1);
        assert_eq!(streams["coder"]["num_output_streams"], 1);
        assert_eq!(streams["packed_input_index"], 0);
    }

    #[test]
    fn withholds_7z_unpack_relationship_for_multi_output_coder() {
        let packed = b"x";
        let encoded_header = [
            0x17, 0x06, 0x00, 0x01, 0x09, 0x01, 0x00, // PackInfo
            0x07, 0x0b, 0x01, 0x00, 0x01, 0x11, 0x99, 0x01, 0x02, // complex 1-in/2-out
            0x0c,
        ];
        let data = seven_zip_with_packed_next_header(packed, &encoded_header);
        let json = serde_json::to_value(parse(&data)).unwrap();
        let streams = &json["headers"][0]["encoded_header_streams_info"];

        assert_eq!(streams["status"], "coder_stream_shape_unsupported");
        assert_eq!(streams["coder"]["num_output_streams"], 2);
        assert_eq!(streams["packed_input_index"], serde_json::Value::Null);
        assert_eq!(streams["unpack_size"], serde_json::Value::Null);
    }

    #[test]
    fn withholds_7z_relationship_when_pack_stream_count_disagrees_with_coder() {
        let packed = b"x";
        let encoded_header = [
            0x17, 0x06, 0x00, 0x00, 0x09, 0x00, // PackInfo declares zero streams.
            0x07, 0x0b, 0x01, 0x00, 0x01, 0x01, 0x00, // One-folder, one-input Copy coder.
            0x0c, 0x01, 0x00, 0x00, // Unpack size and terminators.
        ];
        let data = seven_zip_with_packed_next_header(packed, &encoded_header);
        let json = serde_json::to_value(parse(&data)).unwrap();
        let streams = &json["headers"][0]["encoded_header_streams_info"];

        assert_eq!(streams["status"], "pack_stream_count_mismatch");
        assert_eq!(streams["pack_stream_count_matches_folder"], false);
        assert_eq!(streams["packed_input_index"], serde_json::Value::Null);
        assert_eq!(streams["unpack_size"], serde_json::Value::Null);
    }

    #[test]
    fn accepts_nonzero_7z_all_crcs_defined_flag_values() {
        let packed = b"xy";
        let expected_crc = crc32fast::hash(packed);
        let mut encoded_header = vec![0x17, 0x06, 0x00, 0x01, 0x09, 0x02, 0x0a, 0x02];
        encoded_header.extend_from_slice(&expected_crc.to_le_bytes());
        encoded_header.extend_from_slice(&[0x00, 0x00]);
        let mut data = vec![0u8; 32];
        data[..6].copy_from_slice(SEVEN_ZIP);
        data[6] = 0;
        data[7] = 4;
        data[12..20].copy_from_slice(&(packed.len() as u64).to_le_bytes());
        data[20..28].copy_from_slice(&(encoded_header.len() as u64).to_le_bytes());
        data[28..32].copy_from_slice(&crc32fast::hash(&encoded_header).to_le_bytes());
        let start_header_crc = crc32fast::hash(&data[12..32]);
        data[8..12].copy_from_slice(&start_header_crc.to_le_bytes());
        data.extend_from_slice(packed);
        data.extend_from_slice(&encoded_header);

        let json = serde_json::to_value(parse(&data)).unwrap();
        let pack_info = &json["headers"][0]["encoded_header_pack_info"];

        assert_eq!(pack_info["status"], "pack_info_complete");
        assert_eq!(
            pack_info["packed_streams"][0]["crc32_expected"],
            expected_crc
        );
        assert_eq!(pack_info["packed_streams"][0]["crc32_valid"], true);
    }

    #[test]
    fn rejects_7z_encoded_header_pack_range_beyond_next_header() {
        let encoded_header = [0x17, 0x06, 0x00, 0x01, 0x09, 0x03, 0x00, 0x00];
        let mut data = vec![0u8; 32];
        data[..6].copy_from_slice(SEVEN_ZIP);
        data[6] = 0;
        data[7] = 4;
        data[12..20].copy_from_slice(&0u64.to_le_bytes());
        data[20..28].copy_from_slice(&(encoded_header.len() as u64).to_le_bytes());
        data[28..32].copy_from_slice(&crc32fast::hash(&encoded_header).to_le_bytes());
        let start_header_crc = crc32fast::hash(&data[12..32]);
        data[8..12].copy_from_slice(&start_header_crc.to_le_bytes());
        data.extend_from_slice(&encoded_header);

        let json = serde_json::to_value(parse(&data)).unwrap();
        let pack_info = &json["headers"][0]["encoded_header_pack_info"];

        assert_eq!(pack_info["status"], "pack_info_complete");
        assert_eq!(pack_info["packed_range_within_pre_header_area"], false);
        assert_eq!(
            pack_info["packed_streams"][0]["within_pre_header_area"],
            false
        );
        assert_eq!(pack_info["packed_streams"][0]["sample_available"], true);
        assert_eq!(
            pack_info["packed_streams"][0]["crc32_valid"],
            serde_json::Value::Null
        );
    }

    #[test]
    fn withholds_7z_packed_stream_crc_when_stream_overlaps_next_header() {
        let packed_sample = [b'x', b'y', 0x17];
        let mut encoded_header = vec![0x17, 0x06, 0x00, 0x01, 0x09, 0x03, 0x0a, 0x01];
        encoded_header.extend_from_slice(&crc32fast::hash(&packed_sample).to_le_bytes());
        encoded_header.extend_from_slice(&[0x00, 0x00]);

        let mut data = vec![0u8; 32];
        data[..6].copy_from_slice(SEVEN_ZIP);
        data[6] = 0;
        data[7] = 4;
        data[12..20].copy_from_slice(&2u64.to_le_bytes());
        data[20..28].copy_from_slice(&(encoded_header.len() as u64).to_le_bytes());
        data[28..32].copy_from_slice(&crc32fast::hash(&encoded_header).to_le_bytes());
        let start_header_crc = crc32fast::hash(&data[12..32]);
        data[8..12].copy_from_slice(&start_header_crc.to_le_bytes());
        data.extend_from_slice(&packed_sample[..2]);
        data.extend_from_slice(&encoded_header);

        let json = serde_json::to_value(parse(&data)).unwrap();
        let pack_info = &json["headers"][0]["encoded_header_pack_info"];
        let stream = &pack_info["packed_streams"][0];

        assert_eq!(pack_info["packed_range_within_pre_header_area"], false);
        assert_eq!(stream["within_pre_header_area"], false);
        assert_eq!(stream["sample_available"], true);
        assert_eq!(stream["crc32_expected"], crc32fast::hash(&packed_sample));
        assert_eq!(stream["crc32_calculated"], serde_json::Value::Null);
        assert_eq!(stream["crc32_valid"], serde_json::Value::Null);
    }

    #[test]
    fn parses_7z_pack_stream_crc_bitmap_and_multibyte_size() {
        let first = b"a";
        let second = b"bcd";
        let mut encoded_header = vec![0x17, 0x06, 0x00, 0x02, 0x09, 0x01, 0x03, 0x0a, 0x00, 0xc0];
        encoded_header.extend_from_slice(&crc32fast::hash(first).to_le_bytes());
        encoded_header.extend_from_slice(&crc32fast::hash(second).to_le_bytes());
        encoded_header.extend_from_slice(&[0x00, 0x00]);
        let packed = [first.as_slice(), second.as_slice()].concat();
        let mut data = vec![0u8; 32];
        data[..6].copy_from_slice(SEVEN_ZIP);
        data[6] = 0;
        data[7] = 4;
        data[12..20].copy_from_slice(&(packed.len() as u64).to_le_bytes());
        data[20..28].copy_from_slice(&(encoded_header.len() as u64).to_le_bytes());
        data[28..32].copy_from_slice(&crc32fast::hash(&encoded_header).to_le_bytes());
        let start_header_crc = crc32fast::hash(&data[12..32]);
        data[8..12].copy_from_slice(&start_header_crc.to_le_bytes());
        data.extend_from_slice(&packed);
        data.extend_from_slice(&encoded_header);

        let json = serde_json::to_value(parse(&data)).unwrap();
        let streams = json["headers"][0]["encoded_header_pack_info"]["packed_streams"]
            .as_array()
            .unwrap();
        assert_eq!(streams.len(), 2);
        assert_eq!(streams[0]["byte_offset"], 32);
        assert_eq!(streams[0]["size"], 1);
        assert_eq!(streams[0]["crc32_valid"], true);
        assert_eq!(streams[1]["byte_offset"], 33);
        assert_eq!(streams[1]["size"], 3);
        assert_eq!(streams[1]["crc32_valid"], true);

        let packed = vec![b'z'; 257];
        let encoded_header = [0x17, 0x06, 0, 1, 0x09, 0x81, 1, 0, 0];
        let mut data = vec![0u8; 32];
        data[..6].copy_from_slice(SEVEN_ZIP);
        data[6] = 0;
        data[7] = 4;
        data[12..20].copy_from_slice(&(packed.len() as u64).to_le_bytes());
        data[20..28].copy_from_slice(&(encoded_header.len() as u64).to_le_bytes());
        data[28..32].copy_from_slice(&crc32fast::hash(&encoded_header).to_le_bytes());
        let start_header_crc = crc32fast::hash(&data[12..32]);
        data[8..12].copy_from_slice(&start_header_crc.to_le_bytes());
        data.extend_from_slice(&packed);
        data.extend_from_slice(&encoded_header);

        let json = serde_json::to_value(parse(&data)).unwrap();
        let pack_info = &json["headers"][0]["encoded_header_pack_info"];
        assert_eq!(pack_info["status"], "pack_info_complete");
        assert_eq!(pack_info["packed_streams"][0]["size"], 257);
        assert_eq!(pack_info["packed_range_within_pre_header_area"], true);
    }

    #[test]
    fn leaves_7z_pack_position_unknown_when_encoded_header_is_truncated() {
        let encoded_header = [0x17, 0x06];
        let mut data = vec![0u8; 32];
        data[..6].copy_from_slice(SEVEN_ZIP);
        data[6] = 0;
        data[7] = 4;
        data[12..20].copy_from_slice(&0u64.to_le_bytes());
        data[20..28].copy_from_slice(&(encoded_header.len() as u64).to_le_bytes());
        data[28..32].copy_from_slice(&crc32fast::hash(&encoded_header).to_le_bytes());
        let start_header_crc = crc32fast::hash(&data[12..32]);
        data[8..12].copy_from_slice(&start_header_crc.to_le_bytes());
        data.extend_from_slice(&encoded_header);

        let json = serde_json::to_value(parse(&data)).unwrap();
        let pack_info = &json["headers"][0]["encoded_header_pack_info"];

        assert_eq!(pack_info["status"], "pack_position_unavailable");
        assert_eq!(pack_info["pack_pos"], serde_json::Value::Null);
    }

    fn seven_zip_with_next_header(next_header: &[u8]) -> Vec<u8> {
        let mut data = vec![0u8; 32 + next_header.len()];
        data[..6].copy_from_slice(SEVEN_ZIP);
        data[6] = 0;
        data[7] = 4;
        data[12..20].copy_from_slice(&0u64.to_le_bytes());
        data[20..28].copy_from_slice(&(next_header.len() as u64).to_le_bytes());
        data[28..32].copy_from_slice(&crc32fast::hash(next_header).to_le_bytes());
        let start_header_crc = crc32fast::hash(&data[12..32]);
        data[8..12].copy_from_slice(&start_header_crc.to_le_bytes());
        data[32..].copy_from_slice(next_header);
        data
    }

    fn seven_zip_with_packed_next_header(packed: &[u8], next_header: &[u8]) -> Vec<u8> {
        let mut data = vec![0u8; 32];
        data[..6].copy_from_slice(SEVEN_ZIP);
        data[6] = 0;
        data[7] = 4;
        data[12..20].copy_from_slice(&(packed.len() as u64).to_le_bytes());
        data[20..28].copy_from_slice(&(next_header.len() as u64).to_le_bytes());
        data[28..32].copy_from_slice(&crc32fast::hash(next_header).to_le_bytes());
        let start_header_crc = crc32fast::hash(&data[12..32]);
        data[8..12].copy_from_slice(&start_header_crc.to_le_bytes());
        data.extend_from_slice(packed);
        data.extend_from_slice(next_header);
        data
    }

    fn encode_rar5_test_vint(mut value: u64) -> Vec<u8> {
        let mut encoded = Vec::new();
        loop {
            let mut byte = (value & 0x7f) as u8;
            value >>= 7;
            if value != 0 {
                byte |= 0x80;
            }
            encoded.push(byte);
            if value == 0 {
                return encoded;
            }
        }
    }

    fn rar5_test_block(header_data: &[u8], block_data: &[u8]) -> Vec<u8> {
        let size = encode_rar5_test_vint(header_data.len() as u64);
        let mut checksum_input = size.clone();
        checksum_input.extend_from_slice(header_data);
        let mut block = crc32fast::hash(&checksum_input).to_le_bytes().to_vec();
        block.extend_from_slice(&size);
        block.extend_from_slice(header_data);
        block.extend_from_slice(block_data);
        block
    }

    #[expect(
        clippy::too_many_arguments,
        reason = "Fixture fields independently control the wire header layout"
    )]
    fn rar5_test_file_block(
        header_flags: u64,
        file_flags: u64,
        unpacked_size: u64,
        attributes: u64,
        mtime: Option<u32>,
        data_crc32: Option<u32>,
        compression_info: u64,
        host_os: u64,
        name: &[u8],
        extra_area: &[u8],
        block_data: &[u8],
    ) -> Vec<u8> {
        let mut header = vec![2];
        header.extend_from_slice(&encode_rar5_test_vint(header_flags));
        if header_flags & 0x01 != 0 {
            header.extend_from_slice(&encode_rar5_test_vint(extra_area.len() as u64));
        }
        if header_flags & 0x02 != 0 {
            header.extend_from_slice(&encode_rar5_test_vint(block_data.len() as u64));
        }
        header.extend_from_slice(&encode_rar5_test_vint(file_flags));
        header.extend_from_slice(&encode_rar5_test_vint(unpacked_size));
        header.extend_from_slice(&encode_rar5_test_vint(attributes));
        if file_flags & 0x02 != 0 {
            header.extend_from_slice(&mtime.unwrap_or_default().to_le_bytes());
        }
        if file_flags & 0x04 != 0 {
            header.extend_from_slice(&data_crc32.unwrap_or_default().to_le_bytes());
        }
        header.extend_from_slice(&encode_rar5_test_vint(compression_info));
        header.extend_from_slice(&encode_rar5_test_vint(host_os));
        header.extend_from_slice(&encode_rar5_test_vint(name.len() as u64));
        header.extend_from_slice(name);
        header.extend_from_slice(extra_area);

        rar5_test_block(&header, block_data)
    }

    fn recalculate_rar5_header_crc(data: &mut [u8], block_start: usize) {
        let mut size_cursor = block_start + 4;
        let (header_size, _) = read_vint_with_length(data, &mut size_cursor).unwrap();
        let header_end = size_cursor + usize::try_from(header_size).unwrap();
        let checksum = crc32fast::hash(&data[block_start + 4..header_end]).to_le_bytes();
        data[block_start..block_start + 4].copy_from_slice(&checksum);
    }

    fn rar5_archive_with_quick_open_locator(locator_to_embedded_copy: bool) -> Vec<u8> {
        let embedded_quick_open =
            rar5_test_block(&[3, 2, 4, 0, 4, 0, 0, 0, 2, b'Q', b'O'], b"fake");
        let mut file_header = vec![2, 2];
        file_header.extend_from_slice(&encode_rar5_test_vint(embedded_quick_open.len() as u64));
        file_header.extend_from_slice(&[0, 0, 0, 0, 0, 1, b'x']);
        let file_block = rar5_test_block(&file_header, &embedded_quick_open);

        let quick_open_header = [3, 2, 4, 0, 4, 0, 0, 0, 2, b'Q', b'O'];
        let quick_open_block = rar5_test_block(&quick_open_header, b"data");
        let recovery_record_header = [3, 2, 4, 0, 4, 0, 0, 0, 2, b'R', b'R'];
        let recovery_record_block = rar5_test_block(&recovery_record_header, b"data");
        let main_header_block_length = 14usize;
        let quick_open_relative_offset = if locator_to_embedded_copy {
            (main_header_block_length + (file_block.len() - embedded_quick_open.len())) as u64
        } else {
            (main_header_block_length + file_block.len()) as u64
        };
        let recovery_record_relative_offset =
            (main_header_block_length + file_block.len() + quick_open_block.len()) as u64;
        let locator_record = [
            4,
            1,
            3,
            quick_open_relative_offset as u8,
            recovery_record_relative_offset as u8,
        ];
        let mut main_header = vec![1, 1, locator_record.len() as u8, 0];
        main_header.extend_from_slice(&locator_record);
        let main_block = rar5_test_block(&main_header, &[]);
        assert_eq!(main_block.len(), main_header_block_length);
        let end_block = rar5_test_block(&[5, 0, 0], &[]);

        let mut archive = RAR5.to_vec();
        archive.extend_from_slice(&main_block);
        archive.extend_from_slice(&file_block);
        archive.extend_from_slice(&quick_open_block);
        archive.extend_from_slice(&recovery_record_block);
        archive.extend_from_slice(&end_block);
        archive
    }

    fn rar5_archive_with_end_before_locator_target(end_header: &[u8]) -> Vec<u8> {
        let quick_open_block = rar5_test_block(&[3, 2, 4, 0, 4, 0, 0, 0, 2, b'Q', b'O'], b"data");
        let recovery_record_block =
            rar5_test_block(&[3, 2, 4, 0, 4, 0, 0, 0, 2, b'R', b'R'], b"data");
        let end_block = rar5_test_block(end_header, &[]);
        let quick_open_relative_offset = (14 + end_block.len()) as u8;
        let recovery_record_relative_offset = (14 + end_block.len() + quick_open_block.len()) as u8;
        let locator_record = [
            4,
            1,
            3,
            quick_open_relative_offset,
            recovery_record_relative_offset,
        ];
        let mut main_header = vec![1, 1, locator_record.len() as u8, 0];
        main_header.extend_from_slice(&locator_record);
        let main_block = rar5_test_block(&main_header, &[]);
        assert_eq!(main_block.len(), 14);

        let mut archive = RAR5.to_vec();
        archive.extend_from_slice(&main_block);
        archive.extend_from_slice(&end_block);
        archive.extend_from_slice(&quick_open_block);
        archive.extend_from_slice(&recovery_record_block);
        archive
    }

    #[test]
    fn extracts_visible_rar5_header_encryption_parameters() {
        let mut rar = RAR5.to_vec();
        rar.extend_from_slice(&[0; 4]);
        rar.push(33); // Header data length: type, flags, version, encryption flags, KDF, salt, check.
        rar.extend_from_slice(&[4, 0, 0, 1, 15]);
        rar.extend_from_slice(&[0xAA; 16]);
        rar.extend_from_slice(&[0xCC; 12]);
        let header_crc = crc32fast::hash(&rar[12..]);
        rar[8..12].copy_from_slice(&header_crc.to_le_bytes());

        let metadata = parse(&rar);
        let FormatHeader::Rar5 {
            first_block_header_checksum_valid,
            kdf_count_log2,
            kdf_iteration_count,
            password_check_present,
            header_encryption_salt_hex,
            password_check_data_hex,
            first_block_header_size_vint_byte_length,
            first_block_header_size_encoding_valid,
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 metadata");
        };
        assert_eq!(*first_block_header_checksum_valid, Some(true));
        assert_eq!(*first_block_header_size_vint_byte_length, Some(1));
        assert_eq!(*first_block_header_size_encoding_valid, Some(true));
        assert_eq!(*kdf_count_log2, Some(15));
        assert_eq!(*kdf_iteration_count, Some(32_768));
        assert_eq!(*password_check_present, Some(true));
        assert_eq!(
            header_encryption_salt_hex.as_deref(),
            Some("aa".repeat(16).as_str())
        );
        assert_eq!(
            password_check_data_hex.as_deref(),
            Some("cc".repeat(12).as_str())
        );
    }

    #[test]
    fn extracts_bounded_rar5_file_header_metadata_and_keeps_crc_scope_observational() {
        let name = "folder/файл.bin".as_bytes();
        let compression_info = 1 | 0x0040 | (3 << 7) | (4 << 10);
        let file_block = rar5_test_file_block(
            0x02 | 0x08 | 0x10,
            0x02 | 0x04,
            0x2000,
            0x20,
            Some(0x1234_5678),
            Some(0xAABB_CCDD),
            compression_info,
            1,
            name,
            &[],
            b"packed bytes",
        );
        let mut archive = RAR5.to_vec();
        archive.extend_from_slice(&rar5_test_block(&[1, 0, 0], &[]));
        archive.extend_from_slice(&file_block);
        archive.extend_from_slice(&rar5_test_block(&[5, 0, 0], &[]));

        let metadata = parse(&archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };
        let file_header = walk.blocks[1]
            .file_header
            .as_ref()
            .expect("file header observation");

        assert_eq!(file_header.status, "parsed");
        assert_eq!(file_header.header_crc32_valid, Some(true));
        assert_eq!(file_header.packed_size, Some(b"packed bytes".len() as u64));
        assert_eq!(file_header.directory, Some(false));
        assert!(file_header.split_before);
        assert!(file_header.split_after);
        assert_eq!(file_header.file_flags, Some(0x06));
        assert_eq!(file_header.unpacked_size_observed, Some(0x2000));
        assert_eq!(file_header.unpacked_size_effective, Some(0x2000));
        assert_eq!(file_header.attributes, Some(0x20));
        assert_eq!(
            file_header.modification_time_unix_seconds,
            Some(0x1234_5678)
        );
        assert_eq!(file_header.data_crc32, Some(0xAABB_CCDD));
        assert_eq!(
            file_header.data_crc32_scope,
            Some("packed_current_volume_data")
        );
        assert_eq!(
            file_header.data_crc32_scope_basis,
            Some("RAR5_split_after_flag")
        );
        assert_eq!(file_header.compression_info, Some(compression_info));
        assert_eq!(file_header.compression_version, Some(1));
        assert_eq!(file_header.solid, Some(true));
        assert_eq!(file_header.compression_method, Some(3));
        assert_eq!(file_header.dictionary_size_exponent, Some(4));
        assert_eq!(file_header.host_os, Some(1));
        assert_eq!(file_header.name_byte_length, Some(name.len()));
        assert_eq!(file_header.name_utf8_valid, Some(true));
        assert_eq!(file_header.filename.as_deref(), Some("folder/файл.bin"));
        assert_eq!(
            file_header.name_sha256.as_deref(),
            Some(hex(&Sha256::digest(name)).as_str())
        );
    }

    #[test]
    fn rar5_dictionary_encoding_and_actual_algorithm_are_separate_evidence() {
        let cases = [
            (
                15 << 10,
                "supported",
                Some(4_294_967_296u64),
                Some(0),
                Some("RAR 5.0"),
            ),
            (
                1 | (19 << 10) | (16 << 15),
                "supported",
                Some(103_079_215_104),
                Some(1),
                Some("RAR 7.0"),
            ),
            (
                1 | (23 << 10) | (31 << 15) | 0x100000,
                "dictionary_size_exceeds_format_limit",
                Some(2_164_663_517_184),
                Some(0),
                Some("RAR 7.0"),
            ),
            (
                16 << 10,
                "dictionary_exponent_unsupported",
                None,
                Some(0),
                Some("RAR 5.0"),
            ),
            (
                1 | (24 << 10),
                "dictionary_exponent_unsupported",
                None,
                Some(1),
                Some("RAR 7.0"),
            ),
            (2, "compression_version_unsupported", None, None, None),
            (1 << 15, "compression_flags_unsupported", None, None, None),
            (
                1 | (1 << 21),
                "compression_flags_unsupported",
                None,
                None,
                None,
            ),
            (
                6 << 7,
                "compression_method_unsupported",
                None,
                Some(0),
                Some("RAR 5.0"),
            ),
        ];
        for (info, status, dictionary, algorithm, minimum_generation) in cases {
            let info = if (info >> 7) & 7 == 0 {
                info | (1 << 7)
            } else {
                info
            };
            let block = rar5_test_file_block(0, 0, 0, 0, None, None, info, 0, b"x", &[], &[]);
            let mut archive = RAR5.to_vec();
            archive.extend_from_slice(&rar5_test_block(&[1, 0, 0], &[]));
            let file_start = archive.len();
            archive.extend_from_slice(&block);
            let metadata = serde_json::to_value(parse(&archive)).unwrap();
            let compression = &metadata["headers"][0]["visible_block_walk"]["blocks"][1]["file_header"]
                ["compression_metadata"];
            assert_eq!(compression["status"], status, "info={info}");
            assert_eq!(
                compression["declared_dictionary_bytes"],
                serde_json::json!(dictionary)
            );
            assert_eq!(
                compression["effective_dictionary_bytes"],
                serde_json::json!(if status == "supported" {
                    dictionary
                } else {
                    None
                })
            );
            assert_eq!(
                compression["algorithm_version_from_flags"],
                serde_json::json!(algorithm)
            );
            assert_eq!(
                compression["encoding_generation"],
                serde_json::json!(minimum_generation)
            );
            archive[file_start] ^= 1;
            let metadata = serde_json::to_value(parse(&archive)).unwrap();
            let compression = &metadata["headers"][0]["visible_block_walk"]["blocks"][1]["file_header"]
                ["compression_metadata"];
            assert_eq!(compression["status"], "header_untrusted");
            assert!(compression["effective_dictionary_bytes"].is_null());
        }
    }

    #[test]
    fn rar5_dictionary_full_size_limit_preserves_the_oversized_declaration() {
        for compatibility in [0, 0x100000] {
            let boundary =
                rar5_compression_metadata(1 | (1 << 7) | (23 << 10) | compatibility, true);
            assert_eq!(boundary.status, "supported");
            assert_eq!(boundary.effective_dictionary_bytes, Some(1u64 << 40));
            for fraction in [1u64, 31] {
                let result = rar5_compression_metadata(
                    1 | (1 << 7) | (23 << 10) | (fraction << 15) | compatibility,
                    true,
                );
                assert_eq!(result.status, "dictionary_size_exceeds_format_limit");
                assert!(result.declared_dictionary_bytes.unwrap() > (1u64 << 40));
                assert_eq!(result.effective_dictionary_bytes, None);
            }
        }
    }

    #[test]
    fn rar5_incomplete_stored_and_directory_headers_withhold_effective_dictionary() {
        for (info, file_flags, malformed) in
            [(1 << 7, 0, true), (1, 0, false), (1 | (1 << 7), 1, false)]
        {
            let mut file =
                rar5_test_file_block(0, file_flags, 0, 0, None, None, info, 0, b"x", &[], &[]);
            if malformed {
                let name_length_offset = file.len() - 2;
                file[name_length_offset] = 5;
                recalculate_rar5_header_crc(&mut file, 0);
            }
            let mut archive = RAR5.to_vec();
            archive.extend_from_slice(&rar5_test_block(&[1, 0, 0], &[]));
            archive.extend_from_slice(&file);
            let metadata = serde_json::to_value(parse(&archive)).unwrap();
            let header = &metadata["headers"][0]["visible_block_walk"]["blocks"][1]["file_header"];
            let compression = &header["compression_metadata"];
            assert!(compression["declared_dictionary_bytes"].is_number());
            assert!(compression["effective_dictionary_bytes"].is_null());
            assert_eq!(
                compression["status"],
                if malformed {
                    "file_header_incomplete"
                } else {
                    "dictionary_not_applicable"
                }
            );
        }
    }

    #[test]
    fn rar5_unknown_unpacked_size_is_observed_but_not_effective() {
        let file_block = rar5_test_file_block(
            0x02,
            0x08,
            123_456,
            0,
            None,
            None,
            0,
            0,
            b"stdin.part",
            &[],
            b"part bytes",
        );
        let mut archive = RAR5.to_vec();
        archive.extend_from_slice(&rar5_test_block(&[1, 0, 0], &[]));
        archive.extend_from_slice(&file_block);

        let metadata = parse(&archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };
        let file_header = walk.blocks[1]
            .file_header
            .as_ref()
            .expect("file header observation");

        assert_eq!(file_header.unpacked_size_observed, Some(123_456));
        assert_eq!(file_header.unpacked_size_effective, None);
        assert_eq!(file_header.unpacked_size_unknown, Some(true));
    }

    fn rar5_test_file_extra_record(kind: u64, payload: &[u8]) -> Vec<u8> {
        let kind = encode_rar5_test_vint(kind);
        let mut record = encode_rar5_test_vint((kind.len() + payload.len()) as u64);
        record.extend_from_slice(&kind);
        record.extend_from_slice(payload);
        record
    }

    fn rar5_test_file_encryption_payload(flags: u8) -> Vec<u8> {
        let mut payload = vec![0, flags, 17];
        payload.extend_from_slice(&[0xaa; 16]);
        payload.extend_from_slice(&[0xbb; 16]);
        if flags & 1 != 0 {
            let check = [0xcc; 8];
            payload.extend_from_slice(&check);
            payload.extend_from_slice(&Sha256::digest(check)[..4]);
        }
        payload
    }

    fn rar5_test_file_extra_json(
        extra: &[u8],
        split_flags: u64,
        corrupt_crc: bool,
    ) -> serde_json::Value {
        let mut block = rar5_test_file_block(
            2 | split_flags | u64::from(!extra.is_empty()),
            4,
            16,
            0,
            None,
            Some(0x1234_5678),
            0,
            0,
            b"test.bin",
            extra,
            b"data",
        );
        if corrupt_crc {
            block[0] ^= 1;
        }
        let (observation, _, _) = read_rar5_block_observation(&block, 0, 0);
        serde_json::to_value(observation.file_header.unwrap()).unwrap()
    }

    #[test]
    fn rar5_file_time_literal_ffi_preserves_all_base_times_before_nanoseconds() {
        let sample = [
            0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x01, 0x00, 0xc5, 0x1a, 0x33, 0x32, 0x03, 0x01,
            0x00, 0x00, 0xaf, 0x35, 0xbf, 0x2f, 0x29, 0x02, 0x01, 0x1b, 0x02, 0x00, 0x00, 0x01,
            0x00, 0x00, 0x00, 0x00, 0x01, 0x01, 0x78, 0x1a, 0x03, 0x1f, 0x01, 0x00, 0x00, 0x00,
            0x02, 0x00, 0x00, 0x00, 0x03, 0x00, 0x00, 0x00, 0x04, 0x00, 0x00, 0x00, 0x05, 0x00,
            0x00, 0x00, 0x06, 0x00, 0x00, 0x00, 0x19, 0xb2, 0x3a, 0x35, 0x03, 0x05, 0x00, 0x00,
        ];
        let json = serde_json::to_value(parse(&sample)).unwrap();
        let extras = &json["headers"][0]["visible_block_walk"]["blocks"][1]["file_header"]["file_extra_records"];
        assert_eq!(extras["time_record_count"], 1);
        assert_eq!(extras["base_header_mtime_seconds_matches"], true);
        let time = &extras["records"][0]["time"];
        assert_eq!(time["status"], "parsed");
        assert_eq!(
            time["interpretation_status"],
            "validated_header_declaration"
        );
        assert_eq!(time["storage_format"], "unix_seconds");
        assert_eq!(time["source_payload_offset"], 37);
        assert_eq!(
            time["source_payload_offset"],
            extras["records"][0]["payload_offset"]
        );
        for (index, base, nanos) in [(0, 1, 4), (1, 2, 5), (2, 3, 6)] {
            assert_eq!(time["times"][index]["base_value"], base);
            assert_eq!(time["times"][index]["nanoseconds"], nanos);
            assert_eq!(
                time["times"][index]["base_value_byte_offset"],
                1 + index * 4
            );
            assert_eq!(
                time["times"][index]["nanoseconds_byte_offset"],
                13 + index * 4
            );
        }
    }

    #[test]
    fn rar5_file_time_partial_precision_keeps_complete_base_observations() {
        let extra = [
            0x12, 0x03, 0x1f, 1, 0, 0, 0, 2, 0, 0, 0, 3, 0, 0, 0, 4, 0, 0, 0,
        ];
        let file = rar5_test_file_extra_json(&extra, 0, false);
        let time = &file["file_extra_records"]["time"];
        assert_eq!(time["status"], "payload_truncated");
        assert_eq!(time["times"][2]["base_value"], 3);
        assert_eq!(time["times"][0]["nanoseconds"], 4);
        assert!(time["times"][1]["nanoseconds"].is_null());
        assert!(file["file_extra_records"]["base_header_mtime_seconds_matches"].is_null());
    }

    #[test]
    fn rar5_file_time_filetime_precision_flag_does_not_require_unix_nanoseconds() {
        let extra = [0x0a, 0x03, 0x12, 0, 0, 0, 0, 0, 0, 0, 0];
        let file = rar5_test_file_extra_json(&extra, 0, false);
        let time = &file["file_extra_records"]["time"];
        assert_eq!(time["status"], "parsed");
        assert_eq!(time["storage_format"], "windows_filetime_100ns");
        assert_eq!(time["times"][0]["base_value"], 0);
        assert_eq!(time["times"][0]["nanoseconds_declared"], false);
    }

    fn rar5_test_filetime_with_coarse_base(
        extra: &[u8],
        base: u32,
        corrupt_crc: bool,
    ) -> serde_json::Value {
        let mut block =
            rar5_test_file_block(3, 2, 0, 0, Some(base), None, 0, 1, b"time", extra, &[]);
        if corrupt_crc {
            block[0] ^= 1;
        }
        let (observation, _, _) = read_rar5_block_observation(&block, 0, 0);
        serde_json::to_value(observation.file_header.unwrap()).unwrap()
    }

    #[test]
    fn rar5_filetime_epoch_arithmetic_keeps_floor_seconds_and_exact_fraction() {
        for (raw, seconds, nanos, base, matches, calendar_supported) in [
            ([0; 8], -11_644_473_600i64, 0, 0, None, true),
            (
                [0x00, 0x80, 0x3e, 0xd5, 0xde, 0xb1, 0x9d, 0x01],
                0,
                0,
                0,
                Some(true),
                true,
            ),
            (
                [0xff, 0x7f, 0x3e, 0xd5, 0xde, 0xb1, 0x9d, 0x01],
                -1,
                999_999_900,
                0,
                None,
                true,
            ),
            (
                [0x07, 0xed, 0xe9, 0xd5, 0xde, 0xb1, 0x9d, 0x01],
                1,
                123_456_700,
                1,
                Some(true),
                true,
            ),
            (
                [0x07, 0xed, 0xe9, 0xd5, 0xde, 0xb1, 0x9d, 0x01],
                1,
                123_456_700,
                2,
                Some(false),
                true,
            ),
            (
                [0xff, 0x7f, 0x3e, 0xd5, 0x5e, 0x48, 0x36, 0x02],
                4_294_967_295,
                999_999_900,
                u32::MAX,
                Some(true),
                true,
            ),
            (
                [0x00, 0x80, 0x3e, 0xd5, 0x5e, 0x48, 0x36, 0x02],
                4_294_967_296,
                0,
                0,
                None,
                true,
            ),
            (
                [0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x80],
                910_692_730_085,
                477_580_800,
                0,
                None,
                false,
            ),
            ([0xff; 8], 1_833_029_933_770, 955_161_500, 0, None, false),
        ] {
            let mut extra = vec![0x0a, 3, 2];
            extra.extend(raw);
            let file = rar5_test_filetime_with_coarse_base(&extra, base, false);
            let extras = &file["file_extra_records"];
            let time = &extras["time"]["times"][0];
            assert_eq!(time["filetime_unix_seconds_floor"], seconds);
            assert_eq!(time["filetime_unix_subsecond_nanoseconds"], nanos);
            assert_eq!(
                time["filetime_calendar_api_input_range_supported"],
                calendar_supported
            );
            assert_eq!(
                time["filetime_epoch_relationship_status"],
                "declared_epoch_arithmetic_only"
            );
            assert_eq!(
                extras["time"]["times"],
                extras["records"][0]["time"]["times"]
            );
            assert_eq!(
                extras["base_header_mtime_seconds_matches"].as_bool(),
                matches
            );
            assert_eq!(
                extras["mtime_comparison_status"],
                if matches.is_some() {
                    "declared_filetime_floor_seconds_consistency_only"
                } else {
                    "filetime_seconds_not_representable_in_base_header"
                }
            );
        }
    }

    #[test]
    fn rar5_file_copy_literal_ffi_distinguishes_empty_entry_from_target_crc() {
        let sample: Vec<u8> = "526172211a070100c51a333203010000ab8240160e0202010401008b9ed9d30000016141b72e581d1502030600040100000000000000016205050500016119b23a3503050000"
            .as_bytes().as_chunks::<2>().0.iter()
            .map(|b| u8::from_str_radix(std::str::from_utf8(b).unwrap(), 16).unwrap()).collect();
        let json = serde_json::to_value(parse(&sample)).unwrap();
        let blocks = &json["headers"][0]["visible_block_walk"]["blocks"];
        let source = &blocks[1]["file_header"];
        let copy = &blocks[2]["file_header"];
        assert_eq!(source["data_crc32"], 0xd3d99e8bu32);
        assert_eq!(source["checksum_domain"], "unpacked_file_data");
        assert_eq!(copy["data_crc32"], 0);
        assert_eq!(copy["unpacked_size_effective"], 1);
        assert_eq!(copy["packed_size"], 0);
        assert_eq!(copy["checksum_domain"], "empty_file_copy_entry_data");
        assert_eq!(copy["data_crc32_scope"], "empty_file_copy_entry_data");
        let relationship = &copy["redirection_checksum_relationship"];
        assert_eq!(relationship["status"], "qualified_empty_entry_domain");
        assert_eq!(relationship["stored_crc32_matches_empty_data"], true);
        assert_eq!(relationship["source_header_offset"], 36);
        assert_eq!(relationship["source_record_offset"], 56);
        assert_eq!(relationship["source_record_end_offset"], 62);
        assert_eq!(relationship["target_resolution_performed"], false);
    }

    #[test]
    fn rar5_quick_open_literal_ffi_compares_cache_with_original_sample_bytes() {
        let sample = [
            0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x01, 0x00, 0xfe, 0xc2, 0x94, 0x2c, 0x08, 0x01,
            0x01, 0x04, 0x00, 0x03, 0x01, 0x01, 0x1b, 0xb5, 0x0b, 0xc7, 0x12, 0x09, 0x02, 0x00,
            0x00, 0x00, 0x00, 0x00, 0x00, 0x01, 0x61, 0x28, 0xa2, 0xc3, 0x0a, 0x0b, 0x03, 0x02,
            0x16, 0x00, 0x16, 0x00, 0x00, 0x00, 0x02, 0x51, 0x4f, 0xa4, 0x9e, 0x3f, 0x03, 0x11,
            0x00, 0x0e, 0x0e, 0xb5, 0x0b, 0xc7, 0x12, 0x09, 0x02, 0x00, 0x00, 0x00, 0x00, 0x00,
            0x00, 0x01, 0x61, 0x19, 0xb2, 0x3a, 0x35, 0x03, 0x05, 0x00, 0x00,
        ];
        let json = serde_json::to_value(parse(&sample)).unwrap();
        let cache = &json["headers"][0]["visible_block_walk"]["blocks"][2]["quick_open_cache"];
        assert_eq!(cache["status"], "complete");
        let record = &cache["records"][0];
        assert_eq!(record["structure_start_offset"], 51);
        assert_eq!(record["structure_end_offset"], 73);
        assert_eq!(record["cached_data_start_offset"], 59);
        assert_eq!(record["cached_data_end_offset"], 73);
        assert_eq!(record["backward_offset"], 14);
        assert_eq!(record["original_start_offset"], 21);
        assert_eq!(record["original_end_offset"], 35);
        assert_eq!(record["structure_crc32_valid"], true);
        assert_eq!(record["cached_bytes_match_original"], true);
        assert_eq!(record["cached_header"]["filename"], "a");
        assert_eq!(record["original_block_index"], 1);
    }

    #[test]
    fn rar5_filetime_literal_ffi_exports_raw_ticks_and_qualified_relationships() {
        let sample = [
            0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x01, 0x00, 0xc5, 0x1a, 0x33, 0x32, 0x03, 0x01,
            0x00, 0x00, 0xc1, 0xf0, 0x85, 0xc5, 0x19, 0x02, 0x01, 0x0b, 0x02, 0x00, 0x00, 0x01,
            0x00, 0x00, 0x00, 0x00, 0x01, 0x01, 0x78, 0x0a, 0x03, 0x02, 0x07, 0xed, 0xe9, 0xd5,
            0xde, 0xb1, 0x9d, 0x01, 0x19, 0xb2, 0x3a, 0x35, 0x03, 0x05, 0x00, 0x00,
        ];
        let json = serde_json::to_value(parse(&sample)).unwrap();
        let extras = &json["headers"][0]["visible_block_walk"]["blocks"][1]["file_header"]["file_extra_records"];
        assert_eq!(
            extras["mtime_comparison_status"],
            "declared_filetime_floor_seconds_consistency_only"
        );
        assert_eq!(extras["base_header_mtime_seconds_matches"], true);
        let time = &extras["time"];
        assert_eq!(time["source_payload_offset"], 37);
        assert_eq!(
            time["source_payload_sha256"],
            hex(&Sha256::digest(&sample[37..46]))
        );
        let value = &time["times"][0];
        assert_eq!(value["base_value"], 116_444_736_011_234_567u64);
        assert_eq!(value["base_value_raw_hex"], "07ede9d5deb19d01");
        assert_eq!(value["filetime_unix_seconds_floor"], 1);
        assert_eq!(value["filetime_unix_subsecond_nanoseconds"], 123_456_700);
        assert_eq!(
            value["filetime_epoch_relationship_status"],
            "declared_epoch_arithmetic_only"
        );
        assert_eq!(time["times"], extras["records"][0]["time"]["times"]);
    }

    #[test]
    fn rar5_archived_version_incomplete_and_trailing_file_fields_withhold_name_relationships() {
        let extra = [3, 4, 0, 7];
        let partial = parse_rar5_file_header(&extra, 0, 0..extra.len(), 1, None, Some(true));
        let mut trailing = vec![0, 0, 0, 0, 1, 1, b'x', 0xff];
        trailing.extend(extra);
        let trailing = parse_rar5_file_header(&trailing, 0, 8..trailing.len(), 1, None, Some(true));
        let mut unsupported = vec![0x10, 0, 0, 0, 1, 1, b'x'];
        unsupported.extend(extra);
        let unsupported =
            parse_rar5_file_header(&unsupported, 0, 7..unsupported.len(), 1, None, Some(true));
        for (file, status) in [
            (partial, "file_header_fields_incomplete"),
            (trailing, "file_header_fields_trailing_bytes"),
            (unsupported, "file_flags_unsupported"),
        ] {
            let file = serde_json::to_value(file).unwrap();
            let extras = &file["file_extra_records"];
            assert_eq!(extras["version_record_count"], 1);
            assert!(extras["reference_interpreted_name"].is_null());
            for version in [&extras["version"], &extras["records"][0]["version"]] {
                assert_eq!(version["version_number"], 7);
                assert_eq!(version["interpretation_status"], status);
            }
        }
    }

    #[test]
    fn rar5_archived_version_unknown_common_flags_withhold_name_authority() {
        let block = rar5_test_file_block(0x81, 0, 0, 0, None, None, 0, 1, b"x", &[3, 4, 0, 7], &[]);
        let (observation, _, _) = read_rar5_block_observation(&block, 0, 0);
        let file = serde_json::to_value(observation.file_header.unwrap()).unwrap();
        let extras = &file["file_extra_records"];
        assert_eq!(extras["version"]["version_number"], 7);
        assert_eq!(
            extras["version"]["interpretation_status"],
            "common_header_flags_unsupported"
        );
        assert!(extras["reference_interpreted_name"].is_null());
    }

    #[test]
    fn rar5_archived_version_late_summary_preserves_nonzero_sample_coordinates() {
        let mut extra = [1, 11].repeat(64);
        extra.extend([3, 4, 0, 7]);
        let block = rar5_test_file_block(1, 0, 0, 0, None, None, 0, 1, b"x", &extra, &[]);
        let mut sample = vec![0xaa; 9];
        sample.extend(block);
        let (observation, _, _) = read_rar5_block_observation(&sample, 9, 0);
        let file = serde_json::to_value(observation.file_header.unwrap()).unwrap();
        let extras = &file["file_extra_records"];
        let version = &extras["version"];
        assert_eq!(version["source_record_index"], 64);
        assert_eq!(version["source_record_offset"], 154);
        assert_eq!(version["source_payload_offset"], 156);
        assert_eq!(version["source_payload_byte_length"], 2);
        assert_eq!(
            version["source_payload_sha256"],
            hex(&Sha256::digest([0, 7]))
        );
        assert_eq!(version["version_number_byte_offset"], 1);
        assert_eq!(version["version_number"], 7);
        assert_eq!(version["interpretation_status"], "record_observation_limit");
        assert!(extras["reference_interpreted_name"].is_null());
    }

    #[test]
    fn rar5_archived_version_invalid_flags_never_borrow_the_next_record() {
        for (payload, status) in [
            (vec![], "truncated"),
            (vec![0x80], "truncated"),
            (
                {
                    let mut v = vec![0xff; 9];
                    v.push(2);
                    v
                },
                "numeric_overflow",
            ),
            (vec![0x80; 10], "vint_length_exceeds_64bit"),
        ] {
            let mut extra = rar5_test_file_extra_record(4, &payload);
            extra.extend([3, 4, 0, 7]);
            let file = rar5_test_file_extra_json(&extra, 0, false);
            let extras = &file["file_extra_records"];
            let version = &extras["version"];
            assert_eq!(version["flags_encoding_status"], status);
            assert_eq!(version["flags_raw_hex"], hex(&payload));
            assert!(version["flags"].is_null());
            assert!(version["version_number"].is_null());
            assert_eq!(extras["records"][1]["version"]["version_number"], 7);
            assert_eq!(extras["version_record_count"], 2);
            assert!(extras["reference_interpreted_name"].is_null());
        }
    }

    #[test]
    fn rar5_archived_version_vints_preserve_overflow_and_partial_bytes_without_borrowing() {
        for (bytes, expected, encoding_status, relation_status) in [
            (
                vec![0xff, 0xff, 0xff, 0xff, 0x0f],
                Some(4_294_967_295u64),
                "complete",
                "qualified_32bit_unrar_header_name_interpretation_only",
            ),
            (
                vec![0x80, 0x80, 0x80, 0x80, 0x10],
                Some(4_294_967_296),
                "complete",
                "version_outside_32bit_reference_range",
            ),
            (
                {
                    let mut v = vec![0xff; 9];
                    v.push(1);
                    v
                },
                Some(u64::MAX),
                "complete",
                "version_outside_32bit_reference_range",
            ),
            (
                {
                    let mut v = vec![0xff; 9];
                    v.push(2);
                    v
                },
                None,
                "numeric_overflow",
                "version_record_not_interpretable",
            ),
            (
                vec![0x80; 10],
                None,
                "vint_length_exceeds_64bit",
                "version_record_not_interpretable",
            ),
            (
                vec![0x80],
                None,
                "truncated",
                "version_record_not_interpretable",
            ),
        ] {
            let mut payload = vec![0];
            payload.extend(&bytes);
            let mut extra = rar5_test_file_extra_record(4, &payload);
            extra.extend([1, 11]);
            let file = rar5_test_file_extra_json(&extra, 0, false);
            let extras = &file["file_extra_records"];
            let version = &extras["version"];
            assert_eq!(version["version_number"].as_u64(), expected);
            assert_eq!(version["version_number_encoding_status"], encoding_status);
            assert_eq!(version["version_number_raw_hex"], hex(&bytes));
            assert_eq!(version["version_number_byte_offset"], 1);
            assert_eq!(version["coordinate_basis"], "record-payload-relative");
            assert_eq!(
                extras["reference_name_relationship_status"],
                relation_status
            );
            assert_eq!(extras["records"][1]["record_type"], 11);
        }
    }

    #[test]
    fn rar5_archived_version_names_respect_text_and_output_bounds() {
        for (name, status) in [
            (vec![0xff], "filename_not_retained_as_utf8"),
            (b"a\0b".to_vec(), "filename_contains_nul"),
            (
                vec![b'x'; 4096],
                "reference_name_exceeds_observation_budget",
            ),
        ] {
            let block =
                rar5_test_file_block(1, 0, 0, 0, None, None, 0, 1, &name, &[3, 4, 0, 7], &[]);
            let (observation, _, _) = read_rar5_block_observation(&block, 0, 0);
            let file = serde_json::to_value(observation.file_header.unwrap()).unwrap();
            let extras = &file["file_extra_records"];
            assert_eq!(extras["version"]["version_number"], 7);
            assert_eq!(
                extras["version"]["interpretation_status"],
                "validated_header_declaration"
            );
            assert_eq!(extras["reference_name_relationship_status"], status);
            assert!(extras["reference_interpreted_name"].is_null());
        }
        let name = vec![b'x'; 4094];
        let block = rar5_test_file_block(1, 0, 0, 0, None, None, 0, 1, &name, &[3, 4, 0, 7], &[]);
        let (observation, _, _) = read_rar5_block_observation(&block, 0, 0);
        let file = serde_json::to_value(observation.file_header.unwrap()).unwrap();
        let name = file["file_extra_records"]["reference_interpreted_name"]
            .as_str()
            .unwrap();
        assert_eq!(name.len(), 4096);
        assert!(name.ends_with(";7"));
    }

    #[test]
    fn rar5_archived_service_versions_preserve_identifier_and_checksum_authority() {
        for (flags, compression, bad_crc, status) in [
            (0, 0, false, "validated_header_declaration"),
            (1, 0, false, "service_file_flags_unsupported"),
            (0, 0x40, false, "service_compression_flags_unsupported"),
            (1, 0, true, "header_checksum_unconfirmed"),
        ] {
            let mut block = rar5_test_file_block(
                1,
                flags,
                0,
                0,
                None,
                None,
                compression,
                1,
                b"CMT",
                &[3, 4, 0, 7],
                &[],
            );
            block[5] = 3;
            recalculate_rar5_header_crc(&mut block, 0);
            if bad_crc {
                block[0] ^= 1;
            }
            let (observation, _, _) = read_rar5_block_observation(&block, 0, 0);
            let json = serde_json::to_value(observation).unwrap();
            assert!(json["file_header"].is_null());
            let service = &json["service_header"];
            assert_eq!(service["filename"], "CMT");
            let extras = &service["file_extra_records"];
            assert_eq!(extras["version"]["interpretation_status"], status);
            assert_eq!(extras["version"], extras["records"][0]["version"]);
            if status == "validated_header_declaration" {
                assert_eq!(extras["reference_interpreted_name"], "CMT;7");
            } else {
                assert!(extras["reference_interpreted_name"].is_null());
            }
        }
    }

    #[test]
    fn rar5_archived_versions_preserve_raw_declarations_and_reference_name_relationships() {
        for (extra, name, expected_version, expected_name) in [
            (&[3, 4, 0, 7][..], &b"report.txt"[..], 7, "report.txt;7"),
            (&[3, 4, 0, 0][..], &b"report.txt"[..], 0, "report.txt"),
            (&[3, 4, 0, 7][..], &b"report.txt;2"[..], 7, "report.txt;2;7"),
            (&[4, 4, 0, 0xe8, 7][..], &b"x"[..], 1000, "x;1000"),
        ] {
            let block = rar5_test_file_block(1, 0, 0, 0, None, None, 0, 1, name, extra, &[]);
            let (observation, _, _) = read_rar5_block_observation(&block, 0, 0);
            let file = serde_json::to_value(observation.file_header.unwrap()).unwrap();
            let extras = &file["file_extra_records"];
            assert_eq!(file["filename"], std::str::from_utf8(name).unwrap());
            assert_eq!(extras["version_record_count"], 1);
            assert_eq!(extras["version"]["flags"], 0);
            assert_eq!(extras["version"]["version_number"], expected_version);
            assert_eq!(
                extras["version"]["interpretation_status"],
                "validated_header_declaration"
            );
            assert_eq!(
                extras["reference_name_relationship_status"],
                "qualified_32bit_unrar_header_name_interpretation_only"
            );
            assert_eq!(extras["reference_interpreted_name"], expected_name);
            assert_eq!(extras["version"], extras["records"][0]["version"]);
        }
    }

    #[test]
    fn rar5_archived_version_partial_or_ambiguous_records_withhold_reference_names() {
        let version = [3, 4, 0, 7];
        let mut late_duplicate = version.to_vec();
        late_duplicate.extend([1, 11].repeat(63));
        late_duplicate.extend(version);
        let mut first_late = [1, 11].repeat(64);
        first_late.extend(version);
        for (extra, bad_crc, status) in [
            (version.to_vec(), true, "header_checksum_unconfirmed"),
            (
                [3, 4, 1, 7].to_vec(),
                false,
                "version_record_not_interpretable",
            ),
            (
                [2, 4, 0].to_vec(),
                false,
                "version_record_not_interpretable",
            ),
            (
                [4, 4, 0, 7, 0xff].to_vec(),
                false,
                "version_record_not_interpretable",
            ),
            (version.repeat(2), false, "duplicate_version_records"),
            (late_duplicate, false, "duplicate_version_records"),
            (first_late, false, "record_observation_limit"),
            (version.repeat(4097), false, "extra_framing_incomplete"),
        ] {
            let file = rar5_test_file_extra_json(&extra, 0, bad_crc);
            let extras = &file["file_extra_records"];
            assert!(extras["reference_interpreted_name"].is_null());
            assert_eq!(extras["reference_name_relationship_status"], status);
            assert_eq!(extras["version"]["interpretation_status"], status);
        }
    }

    #[test]
    fn rar5_service_crc_basis_distinguishes_unsplit_and_split_headers() {
        for (header_flags, expected_scope, expected_basis) in [
            (
                1,
                "unpacked_service_data",
                "RAR5_unsplit_service_data_declaration",
            ),
            (
                0x09,
                "split_service_data_scope_not_interpreted",
                "RAR5_service_data_declaration_split_scope_withheld",
            ),
            (
                0x11,
                "split_service_data_scope_not_interpreted",
                "RAR5_service_data_declaration_split_scope_withheld",
            ),
        ] {
            let mut block = rar5_test_file_block(
                header_flags,
                4,
                0,
                0,
                None,
                Some(123),
                0,
                1,
                b"CMT",
                &[],
                &[],
            );
            block[5] = 3;
            recalculate_rar5_header_crc(&mut block, 0);
            let (observation, _, _) = read_rar5_block_observation(&block, 0, 0);
            let json = serde_json::to_value(observation).unwrap();
            let service = &json["service_header"];
            assert_eq!(service["header_crc32_valid"], true);
            assert_eq!(service["data_crc32_scope"], expected_scope);
            assert_eq!(service["data_crc32_scope_basis"], expected_basis);
        }
    }

    #[test]
    fn rar5_service_prohibited_flags_preserve_untrusted_compression_status() {
        for (file_flags, compression_info, problem) in [
            (3, 0, "service_file_flags_unsupported"),
            (2, 0x40, "service_compression_flags_unsupported"),
        ] {
            let extra = [0x0a, 3, 2, 0x07, 0xed, 0xe9, 0xd5, 0xde, 0xb1, 0x9d, 0x01];
            let mut block = rar5_test_file_block(
                1,
                file_flags,
                0,
                0,
                Some(1),
                None,
                compression_info,
                1,
                b"CMT",
                &extra,
                &[],
            );
            block[5] = 3;
            recalculate_rar5_header_crc(&mut block, 0);
            block[0] ^= 1;
            let (observation, _, _) = read_rar5_block_observation(&block, 0, 0);
            let json = serde_json::to_value(observation).unwrap();
            let service = &json["service_header"];
            assert_eq!(service["header_crc32_valid"], false);
            assert_eq!(
                service["compression_metadata"]["status"],
                "header_untrusted"
            );
            assert_eq!(
                service["compression_metadata"]["interpretation_status"],
                problem
            );
            assert!(service["compression_metadata"]["effective_dictionary_bytes"].is_null());
            let extras = &service["file_extra_records"];
            assert_eq!(
                extras["mtime_comparison_status"],
                "header_checksum_unconfirmed"
            );
            assert!(extras["base_header_mtime_seconds_matches"].is_null());
            for time in [&extras["time"], &extras["records"][0]["time"]] {
                assert_eq!(time["interpretation_status"], "header_checksum_unconfirmed");
                assert!(time["times"][0]["filetime_unix_seconds_floor"].is_null());
            }
        }
    }

    #[test]
    fn rar5_service_headers_retain_time_extras_without_claiming_an_archived_file() {
        let extra = [0x0a, 3, 2, 0x07, 0xed, 0xe9, 0xd5, 0xde, 0xb1, 0x9d, 0x01];
        for (bad_crc, service_flags, compression, header_flags, expected_status) in [
            (false, 6, 0, 1, "validated_header_declaration"),
            (true, 2, 0, 1, "header_checksum_unconfirmed"),
            (false, 3, 0, 1, "service_file_flags_unsupported"),
            (false, 2, 0x40, 1, "service_compression_flags_unsupported"),
            (false, 6, 0, 0x11, "validated_header_declaration"),
        ] {
            let mut block = rar5_test_file_block(
                header_flags,
                service_flags,
                0,
                0,
                Some(1),
                Some(123),
                compression,
                1,
                b"CMT",
                &extra,
                &[],
            );
            block[5] = 3; // Change only the literal header type, then independently recompute CRC.
            recalculate_rar5_header_crc(&mut block, 0);
            if bad_crc {
                block[0] ^= 1;
            }
            let (observation, _, _) = read_rar5_block_observation(&block, 0, 0);
            let json = serde_json::to_value(observation).unwrap();
            assert!(json["file_header"].is_null());
            assert_eq!(json["header_type"], 3);
            assert_eq!(json["service_name_bytes_hex"], "434d54");
            let service = &json["service_header"];
            assert!(service["directory"].is_null());
            assert!(service["solid"].is_null());
            assert_eq!(
                service["name_interpretation"],
                "service_identifier_not_archived_file_name"
            );
            if compression == 0x40 {
                assert!(service["compression_metadata"]["effective_dictionary_bytes"].is_null());
                assert_eq!(
                    service["compression_metadata"]["interpretation_status"],
                    "service_compression_flags_unsupported"
                );
            }
            if service_flags & 4 != 0 {
                assert_eq!(
                    service["data_crc32_scope"],
                    if header_flags & 0x10 != 0 {
                        "split_service_data_scope_not_interpreted"
                    } else {
                        "unpacked_service_data"
                    }
                );
            }
            let extras = &service["file_extra_records"];
            assert_eq!(extras["time_record_count"], 1);
            assert_eq!(extras["time"]["interpretation_status"], expected_status);
            assert_eq!(
                extras["time"]["times"][0]["base_value"],
                116_444_736_011_234_567u64
            );
            assert_eq!(
                extras["time"]["times"],
                extras["records"][0]["time"]["times"]
            );
            if expected_status == "validated_header_declaration" {
                assert_eq!(extras["time"]["times"][0]["filetime_unix_seconds_floor"], 1);
                assert_eq!(extras["base_header_mtime_seconds_matches"], true);
            } else {
                assert!(extras["time"]["times"][0]["filetime_unix_seconds_floor"].is_null());
                assert!(extras["base_header_mtime_seconds_matches"].is_null());
            }
        }
    }

    #[test]
    fn rar5_filetime_epoch_relationship_respects_all_record_authority_gates() {
        let epoch = [0x0a, 3, 2, 0x00, 0x80, 0x3e, 0xd5, 0xde, 0xb1, 0x9d, 0x01];
        let mut duplicate = epoch.to_vec();
        duplicate.extend(epoch);
        let mut unknown = epoch.to_vec();
        unknown[2] = 0x22;
        let mut late = [1, 11].repeat(64);
        late.extend(epoch);
        let mut late_duplicate = epoch.to_vec();
        late_duplicate.extend([1, 11].repeat(63));
        late_duplicate.extend(epoch);
        let partial = [0x09, 3, 2, 0x00, 0x80, 0x3e, 0xd5, 0xde, 0xb1, 0x9d];
        let malformed_following = {
            let mut data = epoch.to_vec();
            data.extend([0x80]);
            data
        };
        for (extra, bad_crc, status) in [
            (epoch.to_vec(), true, "header_checksum_unconfirmed"),
            (duplicate, false, "duplicate_time_records"),
            (unknown, false, "time_record_not_interpretable"),
            (late, false, "record_observation_limit"),
            (late_duplicate, false, "duplicate_time_records"),
            (epoch.repeat(4097), false, "extra_framing_incomplete"),
            (partial.to_vec(), false, "time_record_not_interpretable"),
            (malformed_following, false, "extra_framing_incomplete"),
        ] {
            let file = rar5_test_filetime_with_coarse_base(&extra, 0, bad_crc);
            let extras = &file["file_extra_records"];
            assert!(extras["time"]["times"][0]["filetime_unix_seconds_floor"].is_null());
            assert!(extras["time"]["times"][0]["filetime_unix_subsecond_nanoseconds"].is_null());
            assert!(
                extras["time"]["times"][0]["filetime_calendar_api_input_range_supported"].is_null()
            );
            assert_eq!(
                extras["time"]["times"][0]["filetime_epoch_relationship_status"],
                status
            );
            assert_eq!(extras["mtime_comparison_status"], status);
            assert!(extras["base_header_mtime_seconds_matches"].is_null());
            for record in extras["records"].as_array().unwrap() {
                if let Some(values) = record["time"]["times"].as_array() {
                    for value in values {
                        assert!(value["filetime_unix_seconds_floor"].is_null());
                        assert!(value["filetime_unix_subsecond_nanoseconds"].is_null());
                        assert!(value["filetime_calendar_api_input_range_supported"].is_null());
                        assert_eq!(value["filetime_epoch_relationship_status"], status);
                    }
                }
            }
        }
    }

    #[test]
    fn rar5_filetime_incomplete_and_trailing_file_fields_withhold_epoch_relationships() {
        let epoch = [0x0a, 3, 2, 0x00, 0x80, 0x3e, 0xd5, 0xde, 0xb1, 0x9d, 0x01];
        let mut trailing = vec![0, 0, 0, 0, 1, 1, b'x', 0xff];
        trailing.extend(epoch);
        let partial = parse_rar5_file_header(&epoch, 0, 0..epoch.len(), 1, None, Some(true));
        let trailing = parse_rar5_file_header(&trailing, 0, 8..trailing.len(), 1, None, Some(true));
        let mut unsupported = vec![0x10, 0, 0, 0, 1, 1, b'x'];
        unsupported.extend(epoch);
        let unsupported =
            parse_rar5_file_header(&unsupported, 0, 7..unsupported.len(), 1, None, Some(true));
        for (file, status) in [
            (
                serde_json::to_value(partial).unwrap(),
                "file_header_fields_incomplete",
            ),
            (
                serde_json::to_value(trailing).unwrap(),
                "file_header_fields_trailing_bytes",
            ),
            (
                serde_json::to_value(unsupported).unwrap(),
                "file_flags_unsupported",
            ),
        ] {
            let extras = &file["file_extra_records"];
            for time in [&extras["time"], &extras["records"][0]["time"]] {
                assert_eq!(time["times"][0]["base_value"], 116_444_736_000_000_000u64);
                let value = &time["times"][0];
                assert!(value["filetime_unix_seconds_floor"].is_null());
                assert!(value["filetime_unix_subsecond_nanoseconds"].is_null());
                assert!(value["filetime_calendar_api_input_range_supported"].is_null());
                assert_eq!(value["filetime_epoch_relationship_status"], status);
            }
            assert!(extras["base_header_mtime_seconds_matches"].is_null());
        }
    }

    #[test]
    fn rar5_filetime_epoch_relationship_covers_creation_access_without_base_mtime() {
        let extra = [
            0x1a, 3, 0x0e, 0x00, 0x80, 0x3e, 0xd5, 0xde, 0xb1, 0x9d, 0x01, 0xff, 0x7f, 0x3e, 0xd5,
            0xde, 0xb1, 0x9d, 0x01, 0x07, 0xed, 0xe9, 0xd5, 0xde, 0xb1, 0x9d, 0x01,
        ];
        let file = rar5_test_file_extra_json(&extra, 0, false);
        let extras = &file["file_extra_records"];
        assert_eq!(
            extras["mtime_comparison_status"],
            "base_header_mtime_absent"
        );
        assert!(extras["base_header_mtime_seconds_matches"].is_null());
        for (index, kind, seconds, nanos) in [
            (0, "modification", 0, 0),
            (1, "creation", -1, 999_999_900),
            (2, "access", 1, 123_456_700),
        ] {
            let value = &extras["time"]["times"][index];
            assert_eq!(value["kind"], kind);
            assert_eq!(value["filetime_unix_seconds_floor"], seconds);
            assert_eq!(value["filetime_unix_subsecond_nanoseconds"], nanos);
        }
        assert_eq!(
            extras["time"]["times"],
            extras["records"][0]["time"]["times"]
        );
        // A complete mtime followed by a partial ctime must not authorize either conversion.
        let mut partial = vec![0x0b, 3, 6];
        partial.extend(&extra[3..11]);
        partial.push(0xff);
        let file = rar5_test_filetime_with_coarse_base(&partial, 0, false);
        let time = &file["file_extra_records"]["time"];
        assert_eq!(time["times"][0]["base_value"], 116_444_736_000_000_000u64);
        for value in time["times"].as_array().unwrap() {
            assert!(value["filetime_unix_seconds_floor"].is_null());
            assert_eq!(
                value["filetime_epoch_relationship_status"],
                "time_record_not_interpretable"
            );
        }
    }

    #[test]
    fn rar5_file_time_first_late_observation_retains_its_source_identity() {
        let mut extra = [1, 11].repeat(64);
        extra.extend([6, 3, 3, 1, 0, 0, 0]);
        let file = rar5_test_file_extra_json(&extra, 0, false);
        let extras = &file["file_extra_records"];
        let time = &extras["time"];
        assert_eq!(time["source_record_index"], 64);
        let first = extras["records"][0]["offset"].as_u64().unwrap();
        assert_eq!(time["source_record_offset"], first + 128);
        assert_eq!(time["source_payload_offset"], first + 130);
        assert_eq!(time["source_payload_byte_length"], 5);
        assert_eq!(
            time["source_payload_sha256"],
            hex(&Sha256::digest([3, 1, 0, 0, 0]))
        );
        assert_eq!(time["interpretation_status"], "record_observation_limit");
        assert_eq!(time["times"][0]["base_value"], 1);
    }

    #[test]
    fn rar5_file_time_partial_scalar_bytes_never_borrow_from_following_records() {
        for available in 1..4 {
            let mut payload = vec![3];
            payload.extend(vec![0xaa; available]);
            let mut extra = rar5_test_file_extra_record(3, &payload);
            extra.extend(rar5_test_file_extra_record(11, &[0xbb; 8]));
            let file = rar5_test_file_extra_json(&extra, 0, false);
            let time = &file["file_extra_records"]["time"];
            assert_eq!(time["status"], "payload_truncated");
            assert_eq!(time["times"][0]["base_value_bytes_available"], available);
            assert!(time["times"][0]["base_value"].is_null());
            assert_eq!(
                time["times"][0]["base_value_raw_hex"],
                "aa".repeat(available)
            );

            let mut payload = vec![0x13, 1, 0, 0, 0];
            payload.extend(vec![0xaa; available]);
            let mut extra = rar5_test_file_extra_record(3, &payload);
            extra.extend(rar5_test_file_extra_record(11, &[0xbb; 8]));
            let file = rar5_test_file_extra_json(&extra, 0, false);
            let time = &file["file_extra_records"]["time"];
            assert_eq!(time["times"][0]["base_value"], 1);
            assert_eq!(time["times"][0]["nanoseconds_bytes_available"], available);
            assert!(time["times"][0]["nanoseconds"].is_null());
            assert_eq!(
                time["times"][0]["nanoseconds_raw_hex"],
                "aa".repeat(available)
            );
        }
    }

    #[test]
    fn rar5_file_time_malformed_precision_and_flags_preserve_raw_evidence() {
        for (extra, status) in [
            (
                vec![0x0a, 3, 0x13, 1, 0, 0, 0, 0, 0xca, 0x9a, 0x3b],
                "nanoseconds_out_of_range",
            ),
            (vec![6, 3, 0x23, 1, 0, 0, 0], "unknown_flags"),
            (vec![7, 3, 3, 1, 0, 0, 0, 0xff], "trailing_bytes"),
        ] {
            let file = rar5_test_file_extra_json(&extra, 0, false);
            let time = &file["file_extra_records"]["time"];
            assert_eq!(time["status"], status);
            assert_eq!(time["times"][0]["base_value"], 1);
            assert_eq!(
                time["interpretation_status"],
                "time_record_not_interpretable"
            );
            assert!(file["file_extra_records"]["base_header_mtime_seconds_matches"].is_null());
            if status == "nanoseconds_out_of_range" {
                assert_eq!(time["times"][0]["nanoseconds"], 1_000_000_000);
                assert_eq!(time["times"][0]["nanoseconds_range_valid"], false);
            }
        }
    }

    #[test]
    fn rar5_file_time_duplicates_after_retention_and_scan_limits_withhold_interpretation() {
        let time = [6, 3, 3, 1, 0, 0, 0];
        let mut extra = time.to_vec();
        extra.extend([1, 11].repeat(63));
        extra.extend(time);
        let file = rar5_test_file_extra_json(&extra, 0, false);
        let extras = &file["file_extra_records"];
        assert_eq!(extras["time_record_count"], 2);
        assert_eq!(extras["records_truncated"], true);
        assert_eq!(extras["mtime_comparison_status"], "duplicate_time_records");
        assert_eq!(extras["time"]["times"][0]["base_value"], 1);

        let limited = rar5_test_file_extra_json(&time.repeat(4097), 0, false);
        assert_eq!(limited["file_extra_records"]["time_record_count"], 4096);
        assert_eq!(
            limited["file_extra_records"]["mtime_comparison_status"],
            "extra_framing_incomplete"
        );
        assert!(limited["file_extra_records"]["base_header_mtime_seconds_matches"].is_null());
    }

    #[test]
    fn rar5_file_time_rejected_and_partial_headers_keep_observations_without_authority() {
        let time = [6, 3, 3, 1, 0, 0, 0];
        let invalid = rar5_test_file_extra_json(&time, 0, true);
        assert_eq!(invalid["file_extra_records"]["time"]["status"], "parsed");
        assert_eq!(
            invalid["file_extra_records"]["time"]["interpretation_status"],
            "header_checksum_unconfirmed"
        );
        let partial = parse_rar5_file_header(&time, 0, 0..time.len(), 1, None, Some(true));
        let partial = serde_json::to_value(partial).unwrap();
        assert_eq!(
            partial["file_extra_records"]["time"]["times"][0]["base_value"],
            1
        );
        assert_eq!(
            partial["file_extra_records"]["time"]["interpretation_status"],
            "file_header_fields_incomplete"
        );
        assert!(partial["file_extra_records"]["base_header_mtime_seconds_matches"].is_null());
    }

    #[test]
    fn rar5_redirection_retains_each_declared_link_kind_without_resolving_targets() {
        for (kind, name) in [
            (1, "unix_symlink"),
            (2, "windows_symlink"),
            (3, "windows_junction"),
            (4, "hard_link"),
            (5, "file_copy"),
        ] {
            let extra = rar5_test_file_extra_record(5, &[kind, 1, 1, b'x']);
            let file = rar5_test_file_extra_json(&extra, 0, false);
            let link = &file["file_extra_records"]["records"][0]["redirection"];
            assert_eq!(file["file_extra_records"]["redirection_record_count"], 1);
            assert_eq!(link["status"], "parsed");
            assert_eq!(
                link["interpretation_status"],
                "validated_header_declaration"
            );
            assert_eq!(link["kind_observed"], name);
            assert_eq!(link["target_directory_observed"], true);
            assert_eq!(link["target_utf8"], "x");
            assert_eq!(
                link["target_sha256"],
                "2d711642b726b04401627ca9fbac32f5c8530fb1903cc4db02258717921a4881"
            );
            assert_eq!(link["target_resolution_performed"], false);
            assert_eq!(link["trailing_byte_count"], 0);
        }
    }

    #[test]
    fn rar5_redirection_never_borrows_target_bytes_from_the_next_record() {
        for payload in [&[][..], &[1], &[1, 0], &[1, 0, 2, b'x'], &[0x80; 10]] {
            let mut extra = rar5_test_file_extra_record(5, payload);
            extra.extend(rar5_test_file_extra_record(2, &[0; 33]));
            let file = rar5_test_file_extra_json(&extra, 0, false);
            let records = &file["file_extra_records"]["records"];
            assert_eq!(records.as_array().unwrap().len(), 2);
            assert_eq!(records[0]["redirection"]["status"], "payload_truncated");
            assert_eq!(
                records[0]["redirection"]["target_sha256"],
                serde_json::Value::Null
            );
            assert_eq!(
                records[0]["redirection"]["trailing_byte_count"],
                serde_json::Value::Null
            );
            assert_eq!(records[1]["hash"]["status"], "parsed");
        }
        for (payload, status) in [
            (&[9, 0, 1, b'x'][..], "unsupported_redirection_type"),
            (&[1, 2, 1, b'x'][..], "unknown_flags"),
            (&[1, 0, 1, 0xff][..], "invalid_target_utf8"),
            (&[1, 0, 1, b'x', 0][..], "trailing_bytes"),
            (&[1, 0, 1, 0][..], "target_contains_nul"),
        ] {
            let file =
                rar5_test_file_extra_json(&rar5_test_file_extra_record(5, payload), 0, false);
            let link = &file["file_extra_records"]["records"][0]["redirection"];
            assert_eq!(link["status"], status);
            assert_ne!(
                link["interpretation_status"],
                "validated_header_declaration"
            );
        }
    }

    #[test]
    fn rar5_redirection_bounds_retention_and_keeps_duplicate_and_crc_evidence() {
        let target = vec![b'x'; MAX_RAR5_FILENAME_RETAIN_BYTES + 1];
        let mut payload = vec![1, 0];
        payload.extend(encode_rar5_test_vint(target.len() as u64));
        payload.extend(&target);
        let extra = rar5_test_file_extra_record(5, &payload);
        let file = rar5_test_file_extra_json(&extra, 0, true);
        let link = &file["file_extra_records"]["records"][0]["redirection"];
        assert_eq!(link["status"], "parsed");
        assert_eq!(link["interpretation_status"], "header_checksum_unconfirmed");
        assert_eq!(link["target_byte_length"], target.len());
        assert_eq!(link["target_utf8"], serde_json::Value::Null);
        assert_eq!(link["target_text_truncated"], true);
        assert_eq!(link["target_preview_hex"].as_str().unwrap().len(), 256);
        assert_eq!(link["target_sha256"], hex(&Sha256::digest(&target)));
        let repeated = rar5_test_file_extra_record(5, &[1, 0, 1, b'x']).repeat(65);
        let file = rar5_test_file_extra_json(&repeated, 0, false);
        assert_eq!(file["file_extra_records"]["redirection_record_count"], 65);
        assert_eq!(
            file["file_extra_records"]["records"]
                .as_array()
                .unwrap()
                .len(),
            64
        );
        assert_eq!(file["file_extra_records"]["records_truncated"], true);
    }

    #[test]
    fn rar5_redirection_ffi_preserves_header_scope_and_partial_observations() {
        // Padded VInts are permitted; the target bytes contain no terminator.
        let extra = rar5_test_file_extra_record(5, &[0x81, 0, 0x80, 0, 0x81, 0, b'x']);
        let mut sample = RAR5.to_vec();
        sample.extend(rar5_test_block(&[1, 0, 0], &[]));
        sample.extend(rar5_test_file_block(
            3,
            0,
            0,
            0,
            None,
            None,
            0,
            0,
            b"link",
            &extra,
            &[],
        ));
        let json = serde_json::to_value(parse(&sample)).unwrap();
        let link = &json["headers"][0]["visible_block_walk"]["blocks"][1]["file_header"]["file_extra_records"]
            ["records"][0]["redirection"];
        assert_eq!(link["target_utf8"], "x");
        assert_eq!(
            link["interpretation_status"],
            "validated_header_declaration"
        );

        // Complete extra bytes and a valid CRC cannot supply missing file fields.
        let file = parse_rar5_file_header(&extra, 0, 0..extra.len(), 1, None, Some(true));
        let json = serde_json::to_value(file).unwrap();
        assert_eq!(json["status"], "file_header_fields_truncated");
        let link = &json["file_extra_records"]["records"][0]["redirection"];
        assert_eq!(link["status"], "parsed");
        assert_eq!(
            link["interpretation_status"],
            "file_header_fields_incomplete"
        );

        let many = rar5_test_file_extra_record(5, &[1, 0, 1, b'x']).repeat(4097);
        let json = rar5_test_file_extra_json(&many, 0, false);
        assert_eq!(
            json["file_extra_records"]["status"],
            "record_scan_limit_reached"
        );
        assert_eq!(json["file_extra_records"]["redirection_record_count"], 4096);
        assert!(
            json["file_extra_records"]["unparsed_byte_count"]
                .as_u64()
                .unwrap()
                > 0
        );
    }

    #[test]
    fn rar5_file_extra_checksums_link_to_encryption_independent_of_record_order() {
        let encryption = rar5_test_file_extra_record(1, &rar5_test_file_encryption_payload(3));
        let mut hash_payload = vec![0];
        hash_payload.extend_from_slice(&[0xdd; 32]);
        let hash = rar5_test_file_extra_record(2, &hash_payload);
        for extra in [
            [hash.clone(), encryption.clone()].concat(),
            [encryption.clone(), hash.clone()].concat(),
        ] {
            let result = rar5_test_file_extra_json(&extra, 0, false);
            assert_eq!(result["checksum_transformation"], "keyed");
            assert_eq!(result["checksum_domain"], "unpacked_file_data");
            assert_eq!(result["file_extra_records"]["encryption_record_count"], 1);
            assert_eq!(result["file_extra_records"]["hash_record_count"], 1);
            let records = result["file_extra_records"]["records"].as_array().unwrap();
            let enc = records
                .iter()
                .find(|record| record["record_type"] == 1)
                .unwrap();
            assert_eq!(enc["encryption"]["algorithm"], "AES-256");
            assert_eq!(enc["encryption"]["kdf_iteration_count_decimal"], "131072");
            assert_eq!(enc["encryption"]["salt_hex"], "aa".repeat(16));
            assert_eq!(enc["encryption"]["iv_hex"], "bb".repeat(16));
            assert_eq!(enc["encryption"]["password_check_checksum_valid"], true);
            assert_eq!(
                result["file_extra_records"]["unambiguous_stored_blake2sp_hex"],
                "dd".repeat(32)
            );
        }
    }

    #[test]
    fn rar5_file_checksum_domain_and_transformation_are_independent() {
        for split in [0, 8, 16, 24] {
            for flags in [0, 2] {
                let extra =
                    rar5_test_file_extra_record(1, &rar5_test_file_encryption_payload(flags));
                let result = rar5_test_file_extra_json(&extra, split, false);
                assert_eq!(
                    result["checksum_domain"],
                    if split & 16 != 0 {
                        "packed_current_volume_data"
                    } else {
                        "unpacked_file_data"
                    }
                );
                assert_eq!(
                    result["checksum_transformation"],
                    if flags == 2 { "keyed" } else { "plain" }
                );
            }
        }
        let absent = rar5_test_file_extra_json(&[], 0, false);
        assert_eq!(absent["checksum_transformation"], "plain");
        assert_eq!(absent["encryption_presence"], "absent");
    }

    #[test]
    fn rar5_file_extra_ambiguity_and_damage_preserve_raw_evidence() {
        let encryption = rar5_test_file_extra_record(1, &rar5_test_file_encryption_payload(2));
        let duplicate = [encryption.clone(), encryption.clone()].concat();
        let result = rar5_test_file_extra_json(&duplicate, 0, false);
        assert_eq!(result["checksum_transformation"], "unknown");
        assert_eq!(
            result["checksum_transformation_status"],
            "duplicate_encryption_records"
        );
        assert_eq!(result["file_extra_records"]["encryption_record_count"], 2);
        let bad_framing = [encryption.clone(), vec![0x80]].concat();
        assert_eq!(
            rar5_test_file_extra_json(&bad_framing, 0, false)["checksum_transformation"],
            "unknown"
        );
        let bad_crc = rar5_test_file_extra_json(&encryption, 0, true);
        assert_eq!(
            bad_crc["checksum_transformation_status"],
            "header_checksum_invalid"
        );
        assert_eq!(
            bad_crc["file_extra_records"]["records"][0]["encryption"]["flags"],
            2
        );
        let unknown_type = [rar5_test_file_extra_record(99, b"unknown"), encryption].concat();
        assert_eq!(
            rar5_test_file_extra_json(&unknown_type, 0, false)["checksum_transformation"],
            "keyed"
        );
    }

    #[test]
    fn rar5_file_encryption_never_borrows_bytes_from_next_extra_record() {
        let payload = rar5_test_file_encryption_payload(3);
        for length in 0..payload.len() {
            let extra = [
                rar5_test_file_extra_record(1, &payload[..length]),
                rar5_test_file_extra_record(99, &[0; 80]),
            ]
            .concat();
            let result = rar5_test_file_extra_json(&extra, 0, false);
            assert_eq!(
                result["checksum_transformation"], "unknown",
                "payload length {length}"
            );
        }
        let mut unsupported = payload.clone();
        unsupported[0] = 1;
        assert_eq!(
            rar5_test_file_extra_json(&rar5_test_file_extra_record(1, &unsupported), 0, false)["checksum_transformation_status"],
            "encryption_record_not_supported"
        );
        let mut damaged_check = payload;
        *damaged_check.last_mut().unwrap() ^= 1;
        assert_eq!(
            rar5_test_file_extra_json(&rar5_test_file_extra_record(1, &damaged_check), 0, false)["file_extra_records"]
                ["records"][0]["encryption"]["password_check_checksum_valid"],
            false
        );
    }

    #[test]
    fn rar5_file_extra_hash_ambiguity_does_not_override_independent_crc_encoding() {
        let mut payload = vec![0];
        payload.extend_from_slice(&[0xdd; 32]);
        let hash = rar5_test_file_extra_record(2, &payload);
        let duplicate = rar5_test_file_extra_json(&[hash.clone(), hash].concat(), 0, false);
        assert_eq!(duplicate["checksum_transformation"], "plain");
        assert_eq!(
            duplicate["file_extra_records"]["stored_hash_status"],
            "duplicate_hash_records"
        );
        assert!(duplicate["file_extra_records"]["unambiguous_stored_blake2sp_hex"].is_null());
        let truncated =
            rar5_test_file_extra_json(&rar5_test_file_extra_record(2, &payload[..32]), 0, false);
        assert_eq!(truncated["checksum_transformation"], "plain");
        assert_eq!(
            truncated["file_extra_records"]["hash"]["status"],
            "payload_truncated"
        );
        let unknown =
            rar5_test_file_extra_json(&rar5_test_file_extra_record(2, &[1, 2, 3]), 0, false);
        assert_eq!(
            unknown["file_extra_records"]["hash"]["status"],
            "unsupported_hash_type"
        );
    }

    #[test]
    fn rar5_file_extra_retention_limits_do_not_hide_late_encryption_or_scan_limits() {
        let unknown = rar5_test_file_extra_record(99, b"x");
        let encryption = rar5_test_file_extra_record(1, &rar5_test_file_encryption_payload(2));
        let mut extra = unknown.repeat(MAX_RAR5_FILE_EXTRA_RECORD_OBSERVATIONS + 1);
        extra.extend_from_slice(&encryption);
        let result = rar5_test_file_extra_json(&extra, 0, false);
        assert_eq!(result["checksum_transformation"], "keyed");
        assert_eq!(result["file_extra_records"]["encryption"]["flags"], 2);
        assert_eq!(
            result["file_extra_records"]["records"]
                .as_array()
                .unwrap()
                .len(),
            MAX_RAR5_FILE_EXTRA_RECORD_OBSERVATIONS
        );
        assert_eq!(result["file_extra_records"]["records_truncated"], true);
        let mut extra = unknown.repeat(MAX_RAR5_FILE_EXTRA_RECORDS_SCANNED);
        extra.extend_from_slice(&encryption);
        let limited = rar5_test_file_extra_json(&extra, 0, false);
        assert_eq!(
            limited["file_extra_records"]["status"],
            "record_scan_limit_reached"
        );
        assert_eq!(limited["checksum_transformation"], "unknown");
        assert_eq!(limited["encryption_presence"], "unknown");
        assert_eq!(
            limited["file_extra_records"]["unparsed_byte_count"],
            encryption.len()
        );
    }

    #[test]
    fn rar5_file_extra_accepts_padded_vints_without_guessing_extensions() {
        let mut payload = vec![0x80, 0, 0x80, 0];
        payload.extend_from_slice(&rar5_test_file_encryption_payload(0)[2..]);
        payload[4] = 255;
        let parsed = rar5_test_file_extra_json(&rar5_test_file_extra_record(1, &payload), 0, false);
        assert_eq!(parsed["checksum_transformation"], "plain");
        assert_eq!(
            parsed["file_extra_records"]["encryption"]["kdf_count_log2"],
            255
        );
        assert!(
            parsed["file_extra_records"]["encryption"]["kdf_iteration_count_decimal"].is_null()
        );
        payload.push(0);
        let extended =
            rar5_test_file_extra_json(&rar5_test_file_extra_record(1, &payload), 0, false);
        assert_eq!(extended["checksum_transformation"], "unknown");
        assert_eq!(
            extended["file_extra_records"]["encryption"]["status"],
            "trailing_bytes"
        );
        let flags = rar5_test_file_extra_json(
            &rar5_test_file_extra_record(1, &rar5_test_file_encryption_payload(4)),
            0,
            false,
        );
        assert_eq!(flags["checksum_transformation"], "unknown");
        assert_eq!(
            flags["file_extra_records"]["encryption"]["status"],
            "unsupported_flags"
        );
    }

    #[test]
    fn rar5_file_header_retains_full_hash_but_bounds_filename_preview() {
        let name = "n".repeat(RAR5_FILENAME_PREVIEW_BYTES + 20);
        let file_block =
            rar5_test_file_block(0, 0, 5, 0, None, None, 0, 0, name.as_bytes(), &[], &[]);
        let mut archive = RAR5.to_vec();
        archive.extend_from_slice(&rar5_test_block(&[1, 0, 0], &[]));
        archive.extend_from_slice(&file_block);

        let metadata = parse(&archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };
        let file_header = walk.blocks[1]
            .file_header
            .as_ref()
            .expect("file header observation");

        assert_eq!(file_header.status, "parsed");
        assert_eq!(file_header.packed_size, None);
        assert_eq!(file_header.name_byte_length, Some(name.len()));
        assert_eq!(
            file_header.name_preview_hex.as_deref(),
            Some("6e".repeat(RAR5_FILENAME_PREVIEW_BYTES).as_str())
        );
        assert!(file_header.name_preview_truncated);
        assert_eq!(file_header.filename.as_deref(), Some(name.as_str()));
        assert_eq!(
            file_header.name_sha256.as_deref(),
            Some(hex(&Sha256::digest(name.as_bytes())).as_str())
        );
    }

    #[test]
    fn malformed_rar5_file_name_is_reported_without_stopping_block_walk() {
        let malformed_file_header = [2, 0, 0, 0, 0, 0, 0, 5, b'x'];
        let mut archive = RAR5.to_vec();
        archive.extend_from_slice(&rar5_test_block(&[1, 0, 0], &[]));
        archive.extend_from_slice(&rar5_test_block(&malformed_file_header, &[]));
        archive.extend_from_slice(&rar5_test_block(&[5, 0, 0], &[]));

        let metadata = parse(&archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };

        assert_eq!(walk.status, "end_of_archive");
        assert_eq!(
            walk.blocks[1]
                .file_header
                .as_ref()
                .map(|observation| observation.status),
            Some("filename_range_invalid")
        );
    }

    #[test]
    fn untrusted_rar5_main_header_withholds_locator_targets() {
        let mut archive = rar5_archive_with_quick_open_locator(false);
        archive[RAR5.len()] ^= 1;
        let metadata = serde_json::to_value(parse(&archive)).unwrap();
        let walk = &metadata["headers"][0]["visible_block_walk"];
        assert_eq!(walk["status"], "main_header_untrusted");
        for kind in ["quick_open", "recovery_record"] {
            assert_eq!(walk[kind]["status"], "main_header_untrusted");
            assert!(walk[kind]["relative_offset"].is_null());
            assert!(walk[kind]["target_sample_offset"].is_null());
            assert!(walk[kind]["matched_block_index"].is_null());
        }
        // The raw locator observations remain attached to the rejected header.
        assert!(metadata["headers"][0]["main_extra_records"]["quick_open_offset"].is_number());
    }

    #[test]
    fn incomplete_rar5_main_fields_with_valid_crc_withhold_locator_targets() {
        let mut archive = RAR5.to_vec();
        // Five bytes of locator extra data, but no main archive-flags field.
        archive.extend_from_slice(&rar5_test_block(&[1, 1, 5, 4, 1, 3, 20, 30], &[]));
        let metadata = serde_json::to_value(parse(&archive)).unwrap();
        let header = &metadata["headers"][0];
        assert_eq!(header["first_block_header_checksum_valid"], true);
        assert!(header["main_archive_flags"].is_null());
        assert_eq!(header["main_extra_records"]["quick_open_offset"], 20);
        assert_eq!(header["main_extra_records"]["recovery_record_offset"], 30);
        for kind in ["quick_open", "recovery_record"] {
            assert_eq!(
                header["visible_block_walk"][kind]["status"],
                "main_header_untrusted"
            );
            assert!(header["visible_block_walk"][kind]["relative_offset"].is_null());
            assert!(header["visible_block_walk"][kind]["target_sample_offset"].is_null());
        }
    }

    #[test]
    fn resolves_rar5_quick_open_locator_at_sequential_service_boundary() {
        let archive = rar5_archive_with_quick_open_locator(false);
        let metadata = parse(&archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };

        assert_eq!(walk.status, "end_of_archive");
        assert_eq!(walk.inspected_block_count, 5);
        assert_eq!(walk.quick_open.status, "target_header_matches");
        assert_eq!(walk.quick_open.matched_block_index, Some(2));
        assert_eq!(walk.quick_open.target_type, Some(3));
        assert_eq!(
            walk.quick_open.target_service_name_bytes_hex.as_deref(),
            Some("514f")
        );
        assert_eq!(walk.quick_open.target_data_range_available, Some(true));
        assert_eq!(walk.recovery_record.status, "target_header_matches");
        assert_eq!(walk.recovery_record.matched_block_index, Some(3));
        assert_eq!(
            walk.recovery_record
                .target_service_name_bytes_hex
                .as_deref(),
            Some("5252")
        );
    }

    #[test]
    fn rejects_rar5_end_markers_without_complete_flags_or_data() {
        let malformed_headers: [&[u8]; 5] = [
            &[5, 0],
            &[5, 0, 0x80],
            &[5, 1, 1, 0],
            &[5, 1, 2, 0x80, 0, 0],
            &[
                5, 0, 0x80, 0x80, 0x80, 0x80, 0x80, 0x80, 0x80, 0x80, 0x80, 0x80, 0,
            ],
        ];

        for header in malformed_headers {
            let mut archive = RAR5.to_vec();
            archive.extend_from_slice(&rar5_test_block(&[1, 0, 0], &[]));
            archive.extend_from_slice(&rar5_test_block(header, &[]));
            let metadata = parse(&archive);
            let FormatHeader::Rar5 {
                visible_block_walk: Some(walk),
                ..
            } = &metadata.headers[0]
            else {
                panic!("expected RAR5 visible block walk");
            };

            assert_eq!(
                walk.status, "end_archive_flags_unavailable",
                "header={header:?}"
            );
            assert!(!walk.end_of_archive_seen, "header={header:?}");
        }

        let mut truncated_data_archive = RAR5.to_vec();
        truncated_data_archive.extend_from_slice(&rar5_test_block(&[1, 0, 0], &[]));
        truncated_data_archive.extend_from_slice(&rar5_test_block(&[5, 2, 127, 0], &[]));
        let metadata = parse(&truncated_data_archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };
        assert_eq!(walk.status, "data_truncated");
        assert!(!walk.end_of_archive_seen);
    }

    #[test]
    fn only_a_valid_rar5_end_marker_classifies_later_locator_targets_as_after_end() {
        let malformed_end_archive = rar5_archive_with_end_before_locator_target(&[5, 0]);
        let metadata = parse(&malformed_end_archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };
        assert_eq!(walk.status, "end_archive_flags_unavailable");
        assert_eq!(walk.quick_open.status, "walk_interrupted");
        assert_eq!(walk.recovery_record.status, "walk_interrupted");
        assert!(!walk.end_of_archive_seen);

        let valid_end_archive = rar5_archive_with_end_before_locator_target(&[5, 0, 0]);
        let metadata = parse(&valid_end_archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };
        assert_eq!(walk.status, "end_of_archive");
        assert_eq!(walk.quick_open.status, "target_after_archive_end");
        assert_eq!(walk.recovery_record.status, "target_after_archive_end");
        assert!(walk.end_of_archive_seen);
    }

    #[test]
    fn accepts_padded_rar5_end_flags_and_nonfinal_volume_flag() {
        for (flags, expected_flags) in [
            (vec![0], 0),
            (vec![1], 1),
            (vec![0x80, 0], 0),
            (
                {
                    let mut ten_byte_zero = vec![0x80; 9];
                    ten_byte_zero.push(0);
                    ten_byte_zero
                },
                0,
            ),
        ] {
            let mut archive = RAR5.to_vec();
            archive.extend_from_slice(&rar5_test_block(&[1, 0, 0], &[]));
            let mut end_header = vec![5, 0];
            end_header.extend_from_slice(&flags);
            archive.extend_from_slice(&rar5_test_block(&end_header, &[]));
            let metadata = parse(&archive);
            let FormatHeader::Rar5 {
                visible_block_walk: Some(walk),
                ..
            } = &metadata.headers[0]
            else {
                panic!("expected RAR5 visible block walk");
            };

            assert_eq!(walk.status, "end_of_archive", "flags={flags:?}");
            assert!(walk.end_of_archive_seen, "flags={flags:?}");
            assert_eq!(
                walk.blocks.last().and_then(|block| block.end_archive_flags),
                Some(expected_flags),
                "flags={flags:?}"
            );
        }
    }

    #[test]
    fn does_not_resolve_rar5_quick_open_bytes_embedded_in_file_data() {
        let archive = rar5_archive_with_quick_open_locator(true);
        let metadata = parse(&archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };

        assert_eq!(walk.quick_open.status, "target_inside_data");
        assert_eq!(walk.quick_open.matched_block_index, None);
    }

    #[test]
    fn rejects_rar5_quick_open_target_with_wrong_service_name_or_bad_crc() {
        let mut wrong_name_archive = rar5_archive_with_quick_open_locator(false);
        let parsed = parse(&wrong_name_archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &parsed.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };
        let quick_open_start = walk.blocks[2].header_start_offset;
        wrong_name_archive[quick_open_start + 14] = b'X';
        recalculate_rar5_header_crc(&mut wrong_name_archive, quick_open_start);
        let wrong_name = parse(&wrong_name_archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &wrong_name.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };
        assert_eq!(walk.quick_open.status, "target_service_name_mismatch");

        let mut bad_crc_archive = rar5_archive_with_quick_open_locator(false);
        let parsed = parse(&bad_crc_archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &parsed.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };
        bad_crc_archive[walk.blocks[1].header_start_offset] ^= 0x01;
        let bad_crc = parse(&bad_crc_archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &bad_crc.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };
        assert_eq!(walk.status, "header_checksum_invalid");
        assert_eq!(walk.quick_open.status, "walk_interrupted");
        assert_eq!(walk.quick_open.matched_block_index, None);
    }

    #[test]
    fn keeps_rar5_service_header_match_separate_from_truncated_service_data() {
        let mut archive = rar5_archive_with_quick_open_locator(false);
        let parsed = parse(&archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &parsed.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };
        archive.truncate(walk.blocks[2].data_start_offset.unwrap() + 2);
        let truncated = parse(&archive);
        let FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &truncated.headers[0]
        else {
            panic!("expected RAR5 visible block walk");
        };
        assert_eq!(walk.status, "data_truncated");
        assert_eq!(walk.quick_open.status, "target_header_matches");
        assert_eq!(walk.quick_open.target_data_range_available, Some(false));
    }

    #[test]
    fn extracts_rar4_encrypted_header_salt_and_visible_position_fields() {
        let mut rar = RAR4.to_vec();
        let mut main = vec![0u8; 13];
        main[2] = 0x73;
        main[3..5].copy_from_slice(&(0x0010u16 | 0x0080).to_le_bytes());
        main[5..7].copy_from_slice(&13u16.to_le_bytes());
        main[7..9].copy_from_slice(&2u16.to_le_bytes());
        main[9..13].copy_from_slice(&3u32.to_le_bytes());
        let checksum = crc32fast::hash(&main[2..13]) as u16;
        main[..2].copy_from_slice(&checksum.to_le_bytes());
        rar.extend_from_slice(&main);
        rar.extend_from_slice(&[0xAB; 8]);

        let metadata = parse(&rar);
        let FormatHeader::Rar4 {
            new_numbering,
            high_pos_av,
            pos_av,
            authenticity_position_present,
            encrypted_header_salt_hex,
            encrypted_header_salt_available,
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR4 metadata");
        };
        assert_eq!(*new_numbering, Some(true));
        assert_eq!(*high_pos_av, Some(2));
        assert_eq!(*pos_av, Some(3));
        assert_eq!(*authenticity_position_present, Some(true));
        assert_eq!(*encrypted_header_salt_available, Some(true));
        assert_eq!(
            encrypted_header_salt_hex.as_deref(),
            Some("ab".repeat(8).as_str())
        );
    }

    #[test]
    fn extracts_rar5_header_encryption_iv_from_declared_encryption_data() {
        let mut rar = RAR5.to_vec();
        let mut header = vec![4, 0, 0, 0, 15];
        header.extend_from_slice(&[0xAA; 16]);
        rar.extend_from_slice(&crc32fast::hash(&header).to_le_bytes());
        rar.push(header.len() as u8);
        rar.extend_from_slice(&header);
        rar.extend_from_slice(&[0xBB; 16]);

        let metadata = parse(&rar);
        let FormatHeader::Rar5 {
            first_block_data_area_present,
            first_block_data_size,
            header_encryption_iv_hex,
            header_encryption_iv_available,
            header_encryption_iv_status,
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 metadata");
        };

        assert_eq!(*first_block_data_area_present, Some(false));
        assert_eq!(*first_block_data_size, None);
        assert_eq!(*header_encryption_iv_available, Some(true));
        assert_eq!(*header_encryption_iv_status, "available");
        assert_eq!(
            header_encryption_iv_hex.as_deref(),
            Some("bb".repeat(16).as_str())
        );
    }

    #[test]
    fn skips_declared_rar5_encryption_header_data_before_reading_iv() {
        let mut rar = RAR5.to_vec();
        let mut header = vec![4, 2, 16, 0, 0, 0, 15];
        header.extend_from_slice(&[0xAA; 16]);
        rar.extend_from_slice(&crc32fast::hash(&header).to_le_bytes());
        rar.push(header.len() as u8);
        rar.extend_from_slice(&header);
        rar.extend_from_slice(&[0xAA; 16]);
        rar.extend_from_slice(&[0xBB; 16]);

        let metadata = parse(&rar);
        let FormatHeader::Rar5 {
            first_block_data_size,
            header_encryption_iv_hex,
            header_encryption_iv_available,
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 metadata");
        };

        assert_eq!(*first_block_data_size, Some(16));
        assert_eq!(*header_encryption_iv_available, Some(true));
        assert_eq!(
            header_encryption_iv_hex.as_deref(),
            Some("bb".repeat(16).as_str())
        );
    }

    #[test]
    fn does_not_report_rar5_encryption_iv_when_data_size_is_malformed() {
        let mut rar = RAR5.to_vec();
        let header = [4, 2]; // Encryption header with a truncated declared data size.
        rar.extend_from_slice(&crc32fast::hash(&header).to_le_bytes());
        rar.push(header.len() as u8);
        rar.extend_from_slice(&header);
        rar.extend_from_slice(&[0xBB; 16]);

        let metadata = parse(&rar);
        let FormatHeader::Rar5 {
            first_block_header_available,
            first_block_data_area_present,
            first_block_data_size,
            header_encryption_iv_hex,
            header_encryption_iv_available,
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 metadata");
        };

        assert!(!first_block_header_available);
        assert_eq!(*first_block_data_area_present, Some(true));
        assert_eq!(*first_block_data_size, None);
        assert_eq!(*header_encryption_iv_available, None);
        assert_eq!(header_encryption_iv_hex, &None);
    }

    #[test]
    fn does_not_interpret_rar5_iv_after_incomplete_or_unsupported_encryption_header() {
        let mut unsupported = vec![4, 0, 1, 0, 15];
        unsupported.extend_from_slice(&[0xAA; 16]);
        let headers = [
            (vec![4, 0], "encryption_header_incomplete"),
            (unsupported, "encryption_version_unsupported"),
        ];

        for (header, expected_status) in headers {
            let mut rar = RAR5.to_vec();
            rar.extend_from_slice(&crc32fast::hash(&header).to_le_bytes());
            rar.push(header.len() as u8);
            rar.extend_from_slice(&header);
            rar.extend_from_slice(&[0xBB; 16]);

            let metadata = parse(&rar);
            let FormatHeader::Rar5 {
                header_encryption_iv_hex,
                header_encryption_iv_available,
                header_encryption_iv_status,
                ..
            } = &metadata.headers[0]
            else {
                panic!("expected RAR5 metadata");
            };

            assert_eq!(*header_encryption_iv_available, None);
            assert_eq!(*header_encryption_iv_status, expected_status);
            assert_eq!(header_encryption_iv_hex, &None);
        }
    }

    #[test]
    fn reports_overwide_rar5_header_size_vints_as_nonconforming() {
        let make_archive = |encoded_size: &[u8]| {
            let mut rar = RAR5.to_vec();
            rar.extend_from_slice(&[0; 4]);
            rar.extend_from_slice(encoded_size);
            rar.extend_from_slice(&[4, 0, 0, 1, 15]);
            rar.extend_from_slice(&[0xaa; 16]);
            rar.extend_from_slice(&[0xcc; 12]);
            let header_crc = crc32fast::hash(&rar[12..]);
            rar[8..12].copy_from_slice(&header_crc.to_le_bytes());
            rar
        };

        // RAR5 permits padded VInts, but Header size is limited to three bytes.
        for (encoded_size, expected_width, valid) in [
            (&[0xa1, 0x80, 0x00][..], 3, true),
            (&[0xa1, 0x80, 0x80, 0x00][..], 4, false),
        ] {
            let metadata = serde_json::to_value(parse(&make_archive(encoded_size))).unwrap();
            let header = &metadata["headers"][0];
            assert_eq!(
                header["first_block_header_size_vint_byte_length"],
                expected_width
            );
            assert_eq!(header["first_block_header_size_encoding_valid"], valid);
            assert_eq!(header["first_block_header_available"], valid);
            assert_eq!(header["first_block_header_checksum_valid"], true);
        }
    }

    #[test]
    fn validates_rar5_password_check_trailer_checksum_without_claiming_password_validity() {
        let make_archive = |trailer_checksum: [u8; 4]| {
            let mut rar = RAR5.to_vec();
            rar.extend_from_slice(&[0; 4]);
            rar.push(33);
            rar.extend_from_slice(&[4, 0, 0, 1, 15]);
            rar.extend_from_slice(&[0xaa; 16]);
            rar.extend_from_slice(&[0xaa; 8]);
            rar.extend_from_slice(&trailer_checksum);
            let header_crc = crc32fast::hash(&rar[12..]);
            rar[8..12].copy_from_slice(&header_crc.to_le_bytes());
            rar
        };

        let valid_json =
            serde_json::to_value(parse(&make_archive([0x68, 0x93, 0xa0, 0x8c]))).unwrap();
        let valid_header = &valid_json["headers"][0];
        assert_eq!(
            valid_header["password_check_trailer_checksum_expected_hex"],
            "6893a08c"
        );
        assert_eq!(
            valid_header["password_check_trailer_checksum_calculated_hex"],
            "6893a08c"
        );
        assert_eq!(valid_header["password_check_trailer_checksum_valid"], true);

        let invalid_json =
            serde_json::to_value(parse(&make_archive([0x68, 0x93, 0xa0, 0x8d]))).unwrap();
        assert_eq!(
            invalid_json["headers"][0]["password_check_trailer_checksum_valid"],
            false
        );
    }

    #[test]
    fn gzip_public_parse_keeps_long_text_fields_and_fhcrc_consistent() {
        // Literal header, 4097 'A' bytes, NUL, 4097 'B' bytes, NUL.
        // Independent IEEE CRC32 low 16 bits: 0xa583 (little endian below).
        let mut gzip = vec![0x1f, 0x8b, 8, 0x1a, 0, 0, 0, 0, 0, 3];
        gzip.extend_from_slice(&[b'A'; 4097]);
        gzip.push(0);
        gzip.extend_from_slice(&[b'B'; 4097]);
        gzip.push(0);
        gzip.extend_from_slice(&[0x83, 0xa5]);
        let mut sample = vec![0x55; 7];
        sample.extend_from_slice(&gzip);
        let json = serde_json::to_value(parse(&sample)).unwrap();
        let header = &json["headers"][0];
        assert_eq!(header["offset"], 7);
        assert_eq!(header["structure_valid"], true);
        assert_eq!(header["header_complete"], true);
        assert_eq!(header["header_valid"], true);
        assert_eq!(header["filename_terminated"], true);
        assert_eq!(header["filename_full_byte_length"], 4097);
        assert_eq!(header["filename_byte_length"], 4097);
        assert_eq!(header["original_filename_bytes_length"], 4097);
        assert_eq!(
            header["filename_sha256"],
            "225e68d4d9603bdfca93fe3896ba95ab8a10e6505172d8a386af21c5525b950a"
        );
        assert_eq!(
            header["filename_sha256"],
            header["original_filename_bytes_sha256"]
        );
        assert_eq!(
            header["filename_sha256_basis"],
            "complete-nul-terminated-field"
        );
        assert_eq!(header["comment_terminated"], true);
        assert_eq!(header["comment_full_byte_length"], 4097);
        assert_eq!(header["comment_byte_length"], 4097);
        assert_eq!(header["comment_bytes_length"], 4097);
        assert_eq!(
            header["comment_sha256"],
            "e9b66ae7cb510f0ad5cb78b88084b5769bd2d684204cb9293063923f7232c09c"
        );
        assert_eq!(header["comment_sha256"], header["comment_bytes_sha256"]);
        assert_eq!(
            header["comment_sha256_basis"],
            "complete-nul-terminated-field"
        );
        assert_eq!(header["filename_bytes_hex"].as_str().unwrap().len(), 512);
        assert_eq!(header["comment_bytes_hex"].as_str().unwrap().len(), 512);
        assert_eq!(header["filename_bytes_hex_truncated"], true);
        assert_eq!(header["comment_bytes_hex_truncated"], true);
        assert_eq!(header["header_crc16_expected"], 0xa583);
        assert_eq!(header["header_crc16_calculated"], 0xa583);
        assert_eq!(header["header_crc16_valid"], true);
        assert_eq!(header["header_bytes"], 8208);

        // Header completion is independent of the CRC result.
        *sample.last_mut().unwrap() ^= 1;
        let corrupt = serde_json::to_value(parse(&sample)).unwrap();
        assert_eq!(corrupt["headers"][0]["header_complete"], true);
        assert_eq!(corrupt["headers"][0]["header_valid"], false);
        assert_eq!(corrupt["headers"][0]["header_crc16_valid"], false);
    }

    #[test]
    fn gzip_public_parse_never_admits_unsupported_compression_methods() {
        for method in [0, 1, 2, 3, 4, 5, 6, 7, 9, 255] {
            let candidate = [0x1f, 0x8b, method, 0, 0, 0, 0, 0, 0, 3];
            let parsed = parse(&candidate);
            assert!(parsed.signatures.iter().all(|item| item.format != "gzip"));
            assert!(
                parsed
                    .headers
                    .iter()
                    .all(|header| !matches!(header, FormatHeader::Gzip { .. }))
            );
        }
        let supported = parse(&[0x1f, 0x8b, 8, 0, 0, 0, 0, 0, 0, 3]);
        assert!(matches!(
            supported.headers.as_slice(),
            [FormatHeader::Gzip {
                header_valid: Some(true),
                ..
            }]
        ));
    }

    #[test]
    fn gzip_public_parse_text_boundaries_keep_exact_lengths_and_bounded_previews() {
        for (flag, field, alias) in [
            (0x08, "filename", "original_filename"),
            (0x10, "comment", "comment"),
        ] {
            for length in [0, 255, 256, 257, 4095, 4096, 4097, 65535, 65536] {
                let mut gzip = vec![0x1f, 0x8b, 8, flag | 2, 0, 0, 0, 0, 0, 3];
                gzip.extend(std::iter::repeat_n(b'a', length));
                gzip.push(0);
                let crc = crc32fast::hash(&gzip) as u16;
                gzip.extend_from_slice(&crc.to_le_bytes());
                let json = serde_json::to_value(parse(&gzip)).unwrap();
                let header = &json["headers"][0];
                assert_eq!(header["header_complete"], true, "{field}: {length}");
                assert_eq!(header["structure_valid"], true, "{field}: {length}");
                assert_eq!(header["header_valid"], true, "{field}: {length}");
                assert_eq!(header[format!("{field}_terminated")], true);
                assert_eq!(header[format!("{field}_byte_length")], length);
                assert_eq!(header[format!("{field}_full_byte_length")], length);
                assert_eq!(header[format!("{alias}_bytes_length")], length);
                assert_eq!(
                    header[format!("{field}_sha256")],
                    header[format!("{alias}_bytes_sha256")]
                );
                assert_eq!(
                    header[format!("{field}_sha256_basis")],
                    "complete-nul-terminated-field"
                );
                assert_eq!(
                    header[format!("{field}_bytes_hex")],
                    "61".repeat(length.min(256))
                );
                assert_eq!(header[format!("{field}_bytes_hex_truncated")], length > 256);
                assert_eq!(header[format!("{alias}_hex_truncated")], length > 256);
                assert_eq!(header["header_crc16_valid"], true);
                assert_eq!(header["header_bytes"], length + 13);
            }
        }
    }

    #[test]
    fn gzip_public_parse_distinguishes_sample_end_from_text_scan_exhaustion() {
        for (flag, field, alias) in [
            (0x08, "filename", "original_filename"),
            (0x10, "comment", "comment"),
        ] {
            for (length, trailing_nul, observed, basis) in [
                (0, false, 0, "observed-prefix-sample-ended"),
                (4097, false, 4097, "observed-prefix-sample-ended"),
                (65536, false, 65536, "observed-prefix-sample-ended"),
                (65537, false, 65536, "observed-prefix-scan-limited"),
                (65537, true, 65536, "observed-prefix-scan-limited"),
            ] {
                // FNAME also advertises a later comment. It must remain unavailable
                // when the name has no supported terminator, as must FHCRC.
                let flags = if flag == 0x08 { 0x1a } else { 0x12 };
                let mut gzip = vec![0x1f, 0x8b, 8, flags, 0, 0, 0, 0, 0, 3];
                gzip.extend(std::iter::repeat_n(b'a', length));
                if trailing_nul {
                    gzip.extend_from_slice(b"\0later\0\x12\x34");
                }
                let json = serde_json::to_value(parse(&gzip)).unwrap();
                let header = &json["headers"][0];
                assert_eq!(header["header_complete"], false);
                assert_eq!(header["structure_valid"], false);
                assert!(header["header_valid"].is_null());
                assert_eq!(header[format!("{field}_terminated")], false);
                assert_eq!(header[format!("{field}_byte_length")], observed);
                assert!(header[format!("{field}_full_byte_length")].is_null());
                assert_eq!(header[format!("{field}_sha256_basis")], basis);
                assert!(header[format!("{alias}_bytes_length")].is_null());
                assert!(header["header_bytes"].is_null());
                assert!(header["header_crc16_expected"].is_null());
                assert!(header["header_crc16_calculated"].is_null());
                assert!(header["header_crc16_valid"].is_null());
                if flag == 0x08 {
                    assert!(header["comment_terminated"].is_null());
                    assert!(header["comment_bytes_length"].is_null());
                }
            }
        }
    }

    #[test]
    fn gzip_public_parse_keeps_long_field_evidence_when_fhcrc_is_truncated() {
        for (flag, field, alias) in [
            (0x08, "filename", "original_filename"),
            (0x10, "comment", "comment"),
        ] {
            let mut gzip = vec![0x1f, 0x8b, 8, flag | 2, 0, 0, 0, 0, 0, 3];
            gzip.extend_from_slice(&[b'a'; 4097]);
            gzip.extend_from_slice(&[0, 0x42]);
            let json = serde_json::to_value(parse(&gzip)).unwrap();
            let header = &json["headers"][0];
            assert_eq!(header[format!("{field}_terminated")], true);
            assert_eq!(header[format!("{field}_full_byte_length")], 4097);
            assert_eq!(header[format!("{alias}_bytes_length")], 4097);
            assert_eq!(header["header_complete"], false);
            assert_eq!(header["structure_valid"], false);
            assert!(header["header_valid"].is_null());
            assert!(header["header_crc16_valid"].is_null());
        }
    }

    #[test]
    fn gzip_public_parse_caps_raw_previews_without_splitting_into_lossy_text() {
        for (flag, field, alias) in [
            (0x08, "filename", "original_filename"),
            (0x10, "comment", "comment"),
        ] {
            let mut gzip = vec![0x1f, 0x8b, 8, flag, 0, 0, 0, 0, 0, 3];
            gzip.extend_from_slice(&[b'a'; 255]);
            // Raw bytes resembling a UTF-8 multibyte character cross the preview
            // boundary; gzip text is not interpreted as UTF-8 by this parser.
            gzip.extend_from_slice(&[0xe2, 0x82, 0xac, 0]);
            let json = serde_json::to_value(parse(&gzip)).unwrap();
            let header = &json["headers"][0];
            assert_eq!(
                header[format!("{field}_bytes_hex")],
                format!("{}e2", "61".repeat(255))
            );
            assert_eq!(
                header[format!("{alias}_bytes_hex")],
                header[format!("{field}_bytes_hex")]
            );
            assert_eq!(header[format!("{field}_full_byte_length")], 258);
            assert_eq!(header[format!("{alias}_bytes_length")], 258);
            assert_eq!(header[format!("{field}_bytes_hex_truncated")], true);
            assert_eq!(header["header_complete"], true);
        }
    }

    #[test]
    fn parses_gzip_optional_fields_and_header_crc() {
        let mut gzip = GZIP.to_vec();
        gzip.extend_from_slice(&[0x1e, 0x78, 0x56, 0x34, 0x12, 2, 3]);
        gzip.extend_from_slice(&4u16.to_le_bytes());
        gzip.extend_from_slice(&[1, 2, 3, 4]);
        gzip.extend_from_slice(b"payload.gz\0release comment\0");
        let crc = crc32fast::hash(&gzip) as u16;
        gzip.extend_from_slice(&crc.to_le_bytes());

        let metadata = parse(&gzip);
        let FormatHeader::Gzip {
            header_complete,
            compression_method,
            flags,
            flags_reserved_bits_valid,
            modification_time,
            extra_field_length,
            extra_field_sha256,
            filename_byte_length,
            filename_terminated,
            filename_bytes_hex,
            comment_byte_length,
            comment_terminated,
            header_crc16_valid,
            header_bytes,
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected gzip metadata");
        };
        assert!(*header_complete);
        assert_eq!(*compression_method, Some(8));
        assert_eq!(*flags, Some(0x1e));
        assert_eq!(*flags_reserved_bits_valid, Some(true));
        assert_eq!(*modification_time, Some(0x1234_5678));
        assert_eq!(*extra_field_length, Some(4));
        assert_eq!(
            extra_field_sha256.as_deref(),
            Some(hex(&Sha256::digest([1, 2, 3, 4])).as_str())
        );
        assert_eq!(*filename_byte_length, Some(10));
        assert_eq!(*filename_terminated, Some(true));
        assert_eq!(filename_bytes_hex.as_deref(), Some("7061796c6f61642e677a"));
        assert_eq!(*comment_byte_length, Some(15));
        assert_eq!(*comment_terminated, Some(true));
        assert_eq!(*header_crc16_valid, Some(true));
        assert_eq!(*header_bytes, Some(gzip.len()));
    }

    #[test]
    fn reports_truncated_gzip_filename_and_reserved_flags() {
        let mut truncated = GZIP.to_vec();
        truncated.extend_from_slice(&[0x08, 0, 0, 0, 0, 0, 3]);
        truncated.extend_from_slice(b"unterminated");
        let parsed = parse(&truncated);
        assert!(matches!(
            &parsed.headers[0],
            FormatHeader::Gzip {
                header_complete: false,
                filename_terminated: Some(false),
                ..
            }
        ));

        let mut reserved = GZIP.to_vec();
        reserved.extend_from_slice(&[0xe0, 0, 0, 0, 0, 0, 3]);
        let parsed = parse(&reserved);
        assert!(matches!(
            &parsed.headers[0],
            FormatHeader::Gzip {
                flags_reserved_bits_valid: Some(false),
                header_complete: true,
                header_valid: Some(false),
                ..
            }
        ));
    }

    #[test]
    fn rejects_rar5_encryption_fields_that_overlap_the_extra_area() {
        let mut rar = RAR5.to_vec();
        rar.extend_from_slice(&[0; 4]);
        let mut header = vec![4, 1, 16, 4, 0, 15];
        header.extend_from_slice(&[0xaa; 16]);
        rar.push(header.len() as u8);
        rar.extend_from_slice(&header);
        let checksum = crc32fast::hash(&rar[12..]);
        rar[8..12].copy_from_slice(&checksum.to_le_bytes());

        let metadata = parse(&rar);
        let FormatHeader::Rar5 {
            first_block_header_checksum_valid,
            first_block_header_available,
            header_encryption_salt_hex,
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 metadata");
        };
        assert_eq!(*first_block_header_checksum_valid, Some(true));
        assert!(!first_block_header_available);
        assert_eq!(header_encryption_salt_hex, &None);
    }

    #[test]
    fn parses_xz_stream_flags_and_header_crc() {
        let flags = [0, 4];
        let mut xz = XZ.to_vec();
        xz.extend_from_slice(&flags);
        xz.extend_from_slice(&crc32fast::hash(&flags).to_le_bytes());

        let metadata = parse(&xz);
        assert!(matches!(
            &metadata.headers[0],
            FormatHeader::Xz {
                header_complete: true,
                stream_flags_hex: Some(flags),
                reserved_bits_valid: Some(true),
                check_id: Some(4),
                check_name: Some("crc64"),
                header_crc32_valid: Some(true),
                header_valid: Some(true),
                ..
            } if flags == "0004"
        ));

        let mut invalid = XZ.to_vec();
        invalid.extend_from_slice(&[1, 0xf1]);
        invalid.extend_from_slice(&crc32fast::hash(&[1, 0xf1]).to_le_bytes());
        let metadata = parse(&invalid);
        assert!(matches!(
            &metadata.headers[0],
            FormatHeader::Xz {
                reserved_bits_valid: Some(false),
                header_crc32_valid: Some(true),
                header_valid: Some(false),
                ..
            }
        ));
    }

    #[test]
    fn reports_winzip_aes_version_strength_and_crc_authority() {
        for strength in 1..=3 {
            for (vendor_version, stored_crc, expected_crc_status, expected_effective_crc) in [
                (
                    1u16,
                    0x1234_5678u32,
                    "aes_ae1_crc_authoritative",
                    Some(0x1234_5678u32),
                ),
                (2u16, 0u32, "aes_ae2_crc_not_stored", None),
            ] {
                let aes_extra = zip_aes_extra(vendor_version, strength, 8, &[0xaa, 0xbb]);
                let zip = zip_local_header(0x0001, 99, stored_crc, &aes_extra);
                let metadata = serde_json::to_value(parse(&zip)).unwrap();
                let header = &metadata["headers"][0];

                assert_eq!(header["crc32"], stored_crc);
                assert_eq!(header["crc32_authority_status"], expected_crc_status);
                assert_eq!(
                    header["effective_crc32"],
                    expected_effective_crc
                        .map(serde_json::Value::from)
                        .unwrap_or(serde_json::Value::Null)
                );
                assert_eq!(header["aes_metadata"]["status"], "valid");
                assert_eq!(header["aes_metadata"]["vendor_version"], vendor_version);
                assert_eq!(header["aes_metadata"]["vendor_id_hex"], "4145");
                assert_eq!(header["aes_metadata"]["strength"], strength);
                assert_eq!(header["aes_metadata"]["actual_compression_method"], 8);
                assert_eq!(header["aes_metadata"]["payload_extension_byte_length"], 2);
                assert_eq!(
                    header["aes_metadata"]["payload_extension_sha256"],
                    hex(&Sha256::digest([0xaa, 0xbb]))
                );
            }
        }
    }

    #[test]
    fn withholds_zip_crc_when_aes_metadata_is_ambiguous_or_local_values_are_deferred() {
        let aes_extra = zip_aes_extra(1, 1, 8, &[]);
        let ae2_nonzero_crc_extra = zip_aes_extra(2, 1, 8, &[]);
        let duplicate = [aes_extra.as_slice(), aes_extra.as_slice()].concat();
        let cases = [
            (
                0x0001,
                99,
                0x1234_5678,
                duplicate.as_slice(),
                "aes_metadata_invalid",
            ),
            (
                0x0009,
                99,
                0x1234_5678,
                aes_extra.as_slice(),
                "data_descriptor_deferred",
            ),
            (
                0x2001,
                99,
                0x1234_5678,
                aes_extra.as_slice(),
                "masked_local_metadata",
            ),
            (0x0001, 99, 0x1234_5678, &[][..], "aes_metadata_missing"),
            (
                0x0001,
                99,
                0x1234_5678,
                &[0x01, 0x99, 0x06, 0x00, 1, 0][..],
                "aes_metadata_invalid",
            ),
            (
                0x0000,
                99,
                0x1234_5678,
                aes_extra.as_slice(),
                "aes_metadata_invalid",
            ),
            (
                0x0001,
                99,
                0x1234_5678,
                ae2_nonzero_crc_extra.as_slice(),
                "aes_ae2_crc_nonzero",
            ),
        ];

        for (flags, method, crc, extra, expected_status) in cases {
            let zip = zip_local_header(flags, method, crc, extra);
            let metadata = serde_json::to_value(parse(&zip)).unwrap();
            let header = &metadata["headers"][0];

            assert_eq!(header["crc32"], crc);
            assert_eq!(header["crc32_authority_status"], expected_status);
            assert!(header["effective_crc32"].is_null());
        }
    }

    fn zip_aes_extra(vendor_version: u16, strength: u8, method: u16, suffix: &[u8]) -> Vec<u8> {
        let mut payload = Vec::new();
        payload.extend_from_slice(&vendor_version.to_le_bytes());
        payload.extend_from_slice(b"AE");
        payload.push(strength);
        payload.extend_from_slice(&method.to_le_bytes());
        payload.extend_from_slice(suffix);

        let mut extra = Vec::new();
        extra.extend_from_slice(&0x9901u16.to_le_bytes());
        extra.extend_from_slice(&(payload.len() as u16).to_le_bytes());
        extra.extend_from_slice(&payload);
        extra
    }

    fn zip_local_header(flags: u16, method: u16, crc32: u32, extra: &[u8]) -> Vec<u8> {
        let mut zip = vec![0u8; 30];
        zip[..4].copy_from_slice(ZIP);
        zip[4..6].copy_from_slice(&20u16.to_le_bytes());
        zip[6..8].copy_from_slice(&flags.to_le_bytes());
        zip[8..10].copy_from_slice(&method.to_le_bytes());
        zip[14..18].copy_from_slice(&crc32.to_le_bytes());
        zip[26..28].copy_from_slice(&0u16.to_le_bytes());
        zip[28..30].copy_from_slice(&(extra.len() as u16).to_le_bytes());
        zip.extend_from_slice(extra);
        zip
    }

    #[test]
    fn zip_resolved_size_aliases_follow_canonical_zip64_authority() {
        let mut extra = vec![1, 0, 16, 0];
        extra.extend_from_slice(&1234u64.to_le_bytes());
        extra.extend_from_slice(&567u64.to_le_bytes());
        let mut zip = zip_local_header(0, 0, 0, &extra);
        zip[18..22].copy_from_slice(&u32::MAX.to_le_bytes());
        zip[22..26].copy_from_slice(&u32::MAX.to_le_bytes());
        let metadata = serde_json::to_value(parse(&zip)).unwrap();
        let header = &metadata["headers"][0];
        assert_eq!(
            header["zip64_size_metadata"]["status"],
            "version-needed-below-45"
        );
        assert!(header["zip64_size_metadata"]["effective_uncompressed_size"].is_null());
        assert!(header["resolved_uncompressed_size"].is_null());
        assert!(header["resolved_compressed_size"].is_null());
        assert_eq!(header["structure_valid"], false);

        zip[4..6].copy_from_slice(&45u16.to_le_bytes());
        let metadata = serde_json::to_value(parse(&zip)).unwrap();
        let header = &metadata["headers"][0];
        assert_eq!(header["resolved_uncompressed_size"], 1234);
        assert_eq!(header["resolved_compressed_size"], 567);
    }

    #[test]
    fn zip64_local_extra_requires_both_sizes_even_with_one_sentinel() {
        // APPNOTE 4.5.3 requires BOTH sizes in a local header. Conditional
        // omission in central-directory entries does not apply here.
        for (compressed, uncompressed) in [(u32::MAX, 7), (7, u32::MAX)] {
            let mut extra = vec![1, 0, 8, 0];
            extra.extend_from_slice(&1234u64.to_le_bytes());
            let mut zip = zip_local_header(0, 0, 0, &extra);
            zip[4..6].copy_from_slice(&45u16.to_le_bytes());
            zip[18..22].copy_from_slice(&compressed.to_le_bytes());
            zip[22..26].copy_from_slice(&uncompressed.to_le_bytes());
            let metadata = serde_json::to_value(parse(&zip)).unwrap();
            let header = &metadata["headers"][0];
            assert_eq!(
                header["zip64_size_metadata"]["status"],
                "malformed-local-zip64-sizes"
            );
            assert!(header["resolved_uncompressed_size"].is_null());
            assert!(header["resolved_compressed_size"].is_null());
            assert_eq!(header["structure_valid"], false);
        }
    }

    #[test]
    fn rar5_metadata_record_aliases_reject_unparsed_trailing_bytes() {
        let mut archive = RAR5.to_vec();
        archive.extend_from_slice(&rar5_test_block(&[1, 1, 4, 0, 3, 2, 0, 0xaa], &[]));
        let metadata = serde_json::to_value(parse(&archive)).unwrap();
        let header = &metadata["headers"][0];

        assert_eq!(header["main_extra_records"]["malformed_record_count"], 1);
        assert_eq!(header["main_extra_records_complete"], false);
        assert_eq!(
            header["main_archive_metadata_records"][0]["record_fields_complete"],
            false
        );
    }

    #[test]
    fn iso_offset_aliases_do_not_use_conflicting_or_unsupported_geometry() {
        for big_endian_block_size in [4096u16, 2048] {
            let mut iso = vec![0u8; ISO_PVD_OFFSET + 2048];
            let pvd = &mut iso[ISO_PVD_OFFSET..];
            pvd[..7].copy_from_slice(&[1, b'C', b'D', b'0', b'0', b'1', 1]);
            pvd[80..84].copy_from_slice(&100u32.to_le_bytes());
            pvd[84..88].copy_from_slice(&100u32.to_be_bytes());
            pvd[128..130].copy_from_slice(&2048u16.to_le_bytes());
            pvd[130..132].copy_from_slice(&big_endian_block_size.to_be_bytes());
            pvd[132..136].copy_from_slice(&32u32.to_le_bytes());
            pvd[136..140].copy_from_slice(&32u32.to_be_bytes());
            pvd[140..144].copy_from_slice(&18u32.to_le_bytes());
            pvd[148..152].copy_from_slice(&19u32.to_be_bytes());
            pvd[120..122].copy_from_slice(&1u16.to_le_bytes());
            pvd[122..124].copy_from_slice(&1u16.to_be_bytes());
            pvd[124..126].copy_from_slice(&1u16.to_le_bytes());
            pvd[126..128].copy_from_slice(&1u16.to_be_bytes());
            pvd[156] = 34;
            pvd[158..162].copy_from_slice(&20u32.to_le_bytes());
            pvd[162..166].copy_from_slice(&20u32.to_be_bytes());
            pvd[166..170].copy_from_slice(&2048u32.to_le_bytes());
            pvd[170..174].copy_from_slice(&2048u32.to_be_bytes());
            pvd[181] = 2;
            pvd[184..186].copy_from_slice(&1u16.to_le_bytes());
            pvd[186..188].copy_from_slice(&1u16.to_be_bytes());
            pvd[188] = 1;
            let metadata = serde_json::to_value(parse(&iso)).unwrap();
            let header = &metadata["headers"][0];
            if big_endian_block_size == 4096 {
                assert!(header["type_l_path_table_offset_bytes"].is_null());
                assert!(header["type_m_path_table_offset_bytes"].is_null());
                assert!(header["root_directory_record"]["extent_offset_bytes"].is_null());
            } else {
                assert_eq!(header["type_l_path_table_offset_bytes"], 18 * 2048);
                assert_eq!(header["type_m_path_table_offset_bytes"], 19 * 2048);
                assert_eq!(
                    header["root_directory_record"]["extent_offset_bytes"],
                    20 * 2048
                );
            }
            assert!(header["optional_type_l_path_table_offset_bytes"].is_null());
            assert!(header["optional_type_m_path_table_offset_bytes"].is_null());
        }
    }

    #[test]
    fn hashes_a_bounded_decoded_input_prefix_for_cross_format_relationships() {
        let metadata = serde_json::to_value(parse(&vec![b'a'; 20_000])).unwrap();
        let prefix = &metadata["sample_prefix_identity"];

        assert_eq!(
            prefix["basis"],
            "decoded parser input bytes beginning at input offset zero; absolute file offset requires separate yEnc evidence"
        );
        assert_eq!(prefix["prefix_limit_bytes"], 16_384);
        assert_eq!(prefix["bytes_hashed"], 16_384);
        assert_eq!(prefix["md5"], "09b47cf07c9d0ea589e0e2aa7d0d3550");
        assert_eq!(prefix["status"], "sampled_prefix");
        assert_eq!(prefix["parser_input_offset_zero"], true);
        assert_eq!(prefix["file_offset_known"], false);

        let short = serde_json::to_value(parse(b"abc")).unwrap();
        assert_eq!(short["sample_prefix_identity"]["bytes_hashed"], 3);
        assert_eq!(
            short["sample_prefix_identity"]["md5"],
            "900150983cd24fb0d6963f7d28e17f72"
        );
    }

    #[test]
    fn rar5_extra_area_does_not_satisfy_missing_main_archive_flags() {
        let mut rar = RAR5.to_vec();
        rar.extend_from_slice(&[0; 4]);
        // MAIN type, extra-area flag, one-byte extra record (size/type/payload).
        rar.push(6); // Header data length.
        rar.extend_from_slice(&[1, 1, 3, 2, 1, 0]);
        let header_crc = crc32fast::hash(&rar[12..]);
        rar[8..12].copy_from_slice(&header_crc.to_le_bytes());

        let metadata = parse(&rar);
        let FormatHeader::Rar5 {
            first_block_header_available,
            main_archive_flags,
            main_extra_records_complete,
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 metadata");
        };
        assert!(!first_block_header_available);
        assert_eq!(*main_archive_flags, None);
        assert!(!main_extra_records_complete);
    }

    #[test]
    fn complete_rar5_main_header_retains_complete_extra_records() {
        let mut rar = RAR5.to_vec();
        rar.extend_from_slice(&[0; 4]);
        // MAIN type, extra-area flag, extra size, archive flags, locator record.
        rar.push(7);
        rar.extend_from_slice(&[1, 1, 3, 0, 2, 1, 0]);
        let header_crc = crc32fast::hash(&rar[12..]);
        rar[8..12].copy_from_slice(&header_crc.to_le_bytes());

        let metadata = parse(&rar);
        let FormatHeader::Rar5 {
            first_block_header_available,
            main_archive_flags,
            main_extra_records_complete,
            main_extra_record_types,
            ..
        } = &metadata.headers[0]
        else {
            panic!("expected RAR5 metadata");
        };
        assert!(*first_block_header_available);
        assert_eq!(*main_archive_flags, Some(0));
        assert!(*main_extra_records_complete);
        assert_eq!(main_extra_record_types, &[1]);
    }
    #[test]
    fn xz_first_block_exposes_declared_ranges_without_decompression() {
        let sample: Vec<u8> = "fd377a585a000000ff12d94102c0050121010000f05dfb9f010000410000000000011101ada6580406729e7a010000000000595a"
            .as_bytes().as_chunks::<2>().0.iter()
            .map(|pair| u8::from_str_radix(std::str::from_utf8(pair).unwrap(), 16).unwrap()).collect();
        let metadata = serde_json::to_value(parse(&sample)).unwrap();
        let block = &metadata["headers"][0]["first_block"];
        assert_eq!(block["header_start_offset"], 12);
        assert_eq!(block["header_crc32_valid"], true);
        assert_eq!(block["compressed_size"]["value"], 5);
        assert_eq!(block["compressed_data"]["start_offset"], 24);
        assert_eq!(block["compressed_data"]["end_offset_exclusive"], 29);
        assert_eq!(block["block_padding"]["end_offset_exclusive"], 32);
        assert_eq!(block["block_padding_zero"], true);
        assert_eq!(block["following_byte"], 0);
        {
            assert!(block.get("following_index").is_none());
            assert!(block.get("following_footer").is_none());
        }
        assert_eq!(block["content_decompressed"], false);
    }

    #[test]
    fn xz_first_block_metadata_keeps_existing_candidate_limit_and_crc_authority() {
        let mut stream = XZ.to_vec();
        stream.extend([0, 0]);
        stream.extend(crc32fast::hash(&[0, 0]).to_le_bytes());
        stream.push(0);
        let many = stream.repeat(17);
        let metadata = parse(&many);
        assert_eq!(
            metadata
                .headers
                .iter()
                .filter(|header| matches!(header, FormatHeader::Xz { .. }))
                .count(),
            16
        );
        assert!(metadata.candidate_scan_truncated_formats.contains(&"XZ"));
        let mut sample: Vec<u8> = "fd377a585a000000ff12d94102c0050121010000f05dfb9f"
            .as_bytes()
            .as_chunks::<2>()
            .0
            .iter()
            .map(|pair| u8::from_str_radix(std::str::from_utf8(pair).unwrap(), 16).unwrap())
            .collect();
        sample[8] ^= 1;
        let metadata = serde_json::to_value(parse(&sample)).unwrap();
        assert_eq!(metadata["headers"][0]["structure_valid"], true);
        let block = &metadata["headers"][0]["first_block"];
        assert_eq!(block["stream_header_qualified"], false);
        assert_eq!(block["header_crc32_valid"], true);
        assert_eq!(block["compressed_size"]["value"], 5);
        assert!(block["compressed_data"].is_null());
        sample[6] = 1;
        let crc = crc32fast::hash(&sample[6..8]);
        sample[8..12].copy_from_slice(&crc.to_le_bytes());
        let metadata = serde_json::to_value(parse(&sample)).unwrap();
        assert_eq!(metadata["headers"][0]["header_crc32_valid"], true);
        assert_eq!(metadata["headers"][0]["reserved_bits_valid"], false);
        assert_eq!(
            metadata["headers"][0]["first_block"]["stream_header_qualified"],
            false
        );
        assert!(metadata["headers"][0]["first_block"]["compressed_data"].is_null());
    }

    #[test]
    fn zip_and_gzip_expose_comparable_deflate_effort_and_os_metadata() {
        let mut zip_deflate_max = ZIP.to_vec();
        zip_deflate_max.extend_from_slice(&20u16.to_le_bytes()); // version needed
        zip_deflate_max.extend_from_slice(&0x0002u16.to_le_bytes()); // flags: bits 1-2 = 1 (maximum)
        zip_deflate_max.extend_from_slice(&8u16.to_le_bytes()); // compression method = 8 (Deflate)
        zip_deflate_max.extend_from_slice(&[0u8; 20]); // time, date, crc, sizes, lengths

        let mut zip_aes_fast = ZIP.to_vec();
        zip_aes_fast.extend_from_slice(&51u16.to_le_bytes());
        zip_aes_fast.extend_from_slice(&0x0005u16.to_le_bytes()); // encrypted (bit 0) + bits 1-2 = 2 (fast)
        zip_aes_fast.extend_from_slice(&99u16.to_le_bytes()); // WinZip AES
        zip_aes_fast.extend_from_slice(&[0u8; 16]);
        zip_aes_fast.extend_from_slice(&0u16.to_le_bytes()); // filename len = 0
        zip_aes_fast.extend_from_slice(&11u16.to_le_bytes()); // extra len = 11
        zip_aes_fast.extend_from_slice(&0x9901u16.to_le_bytes());
        zip_aes_fast.extend_from_slice(&7u16.to_le_bytes());
        zip_aes_fast.extend_from_slice(&2u16.to_le_bytes()); // AE-2
        zip_aes_fast.extend_from_slice(b"AE");
        zip_aes_fast.push(3); // AES-256
        zip_aes_fast.extend_from_slice(&8u16.to_le_bytes()); // actual method = 8 (Deflate)

        let mut zip_masked = zip_deflate_max.clone();
        zip_masked[6..8].copy_from_slice(&0x2002u16.to_le_bytes());

        let gzip_max = b"\x1f\x8b\x08\x00\x00\x00\x00\x00\x02\x03".to_vec();
        let gzip_fast = b"\x1f\x8b\x08\x00\x00\x00\x00\x00\x04\x0b".to_vec();
        let gzip_unrecognized = b"\x1f\x8b\x08\x00\x00\x00\x00\x00\x01\xff".to_vec();

        let mut combined = Vec::new();
        combined.extend_from_slice(&zip_deflate_max);
        combined.extend_from_slice(&zip_aes_fast);
        combined.extend_from_slice(&zip_masked);
        combined.extend_from_slice(&gzip_max);
        combined.extend_from_slice(&gzip_fast);
        combined.extend_from_slice(&gzip_unrecognized);

        let metadata = serde_json::to_value(parse(&combined)).unwrap();
        let headers = metadata["headers"].as_array().unwrap();
        assert_eq!(headers.len(), 6);

        assert_eq!(headers[0]["format"], "zip");
        assert_eq!(headers[0]["effective_compression_method"], 8);
        assert_eq!(
            headers[0]["effective_compression_method_source"],
            "local_header"
        );
        assert_eq!(headers[0]["deflate_compression_option_bits"], 1);
        assert_eq!(headers[0]["deflate_compression_option"], "maximum");
        assert_eq!(headers[0]["deflate_effort_class"], "maximum");
        assert_eq!(headers[0]["deflate_effort_status"], "resolved_local_header");

        assert_eq!(headers[1]["format"], "zip");
        assert_eq!(headers[1]["effective_compression_method"], 8);
        assert_eq!(
            headers[1]["effective_compression_method_source"],
            "aes_extra_field"
        );
        assert_eq!(headers[1]["deflate_compression_option_bits"], 2);
        assert_eq!(headers[1]["deflate_compression_option"], "fast");
        assert_eq!(headers[1]["deflate_effort_class"], "fast");
        assert_eq!(
            headers[1]["deflate_effort_status"],
            "resolved_via_aes_extra_field"
        );

        assert_eq!(headers[2]["format"], "zip");
        assert!(headers[2]["effective_compression_method"].is_null());
        assert!(headers[2]["deflate_effort_class"].is_null());
        assert_eq!(headers[2]["deflate_effort_status"], "masked_local_metadata");

        assert_eq!(headers[3]["format"], "gzip");
        assert_eq!(
            headers[3]["deflate_extra_flags_meaning"],
            "maximum_compression"
        );
        assert_eq!(headers[3]["deflate_effort_class"], "maximum");
        assert_eq!(headers[3]["deflate_effort_status"], "resolved_extra_flags");
        assert_eq!(headers[3]["operating_system_name"], "unix");

        assert_eq!(headers[4]["format"], "gzip");
        assert_eq!(
            headers[4]["deflate_extra_flags_meaning"],
            "fastest_algorithm"
        );
        assert_eq!(headers[4]["deflate_effort_class"], "fast");
        assert_eq!(headers[4]["operating_system_name"], "ntfs_filesystem");

        assert_eq!(headers[5]["format"], "gzip");
        assert_eq!(headers[5]["deflate_extra_flags_meaning"], "unrecognized");
        assert!(headers[5]["deflate_effort_class"].is_null());
        assert_eq!(
            headers[5]["deflate_effort_status"],
            "unrecognized_extra_flags"
        );
        assert_eq!(headers[5]["operating_system_name"], "unknown");
    }

    #[test]
    fn rar4_exposes_first_file_header_metadata_and_handles_edge_cases() {
        let build_rar4_block =
            |head_type: u8, head_flags: u16, body_after_size: &[u8]| -> Vec<u8> {
                let head_size = (7 + body_after_size.len()) as u16;
                let mut body = Vec::with_capacity(5 + body_after_size.len());
                body.push(head_type);
                body.extend_from_slice(&head_flags.to_le_bytes());
                body.extend_from_slice(&head_size.to_le_bytes());
                body.extend_from_slice(body_after_size);
                let crc16 = (crc32fast::hash(&body) & 0xffff) as u16;
                let mut block = Vec::with_capacity(2 + body.len());
                block.extend_from_slice(&crc16.to_le_bytes());
                block.extend_from_slice(&body);
                block
            };

        // 1. Standard 32-bit RAR4 FILE_HEAD (0x74) with Unix OS, Best method (0x35), split flags
        let mut standard_archive = RAR4.to_vec();
        standard_archive.extend_from_slice(&build_rar4_block(0x73, 0x0001, &[0u8; 6]));
        let filename = b"scene-release.mkv";
        let mut std_file_body = Vec::new();
        std_file_body.extend_from_slice(&50_000_000u32.to_le_bytes()); // pack_size_low
        std_file_body.extend_from_slice(&50_000_000u32.to_le_bytes()); // unp_size_low
        std_file_body.push(3); // host_os = Unix
        std_file_body.extend_from_slice(&0xdead_beefu32.to_le_bytes()); // file_crc
        std_file_body.extend_from_slice(&0x5421_1234u32.to_le_bytes()); // ftime
        std_file_body.push(29); // unp_ver
        std_file_body.push(0x35); // method = best
        std_file_body.extend_from_slice(&(filename.len() as u16).to_le_bytes());
        std_file_body.extend_from_slice(&0x0020u32.to_le_bytes()); // attr
        std_file_body.extend_from_slice(filename);
        // flags: LHD_SPLIT_AFTER (0x0002) | LHD_SOLID (0x0010) | dict 4096 KB (0b110 << 5 = 0x00c0)
        standard_archive.extend_from_slice(&build_rar4_block(0x74, 0x00d2, &std_file_body));

        let std_json = serde_json::to_value(parse(&standard_archive)).unwrap();
        let std_rar4 = &std_json["headers"][0];
        assert_eq!(std_rar4["format"], "rar4");
        assert_eq!(std_rar4["first_file_header_status"], "parsed");
        assert_eq!(std_rar4["next_block_type"], 0x74);
        let first_file = &std_rar4["first_file"];
        assert_eq!(first_file["checksum_valid"], true);
        assert_eq!(first_file["compressed_size"], 50_000_000);
        assert_eq!(first_file["uncompressed_size"], 50_000_000);
        assert_eq!(first_file["uncompressed_size_unknown"], false);
        assert_eq!(first_file["host_os"], 3);
        assert_eq!(first_file["host_os_name"], "unix");
        assert_eq!(first_file["file_crc32"], 0xdead_beefu32);
        assert_eq!(first_file["unpack_version"], 29);
        assert_eq!(first_file["method"], 0x35);
        assert_eq!(first_file["method_name"], "best");
        assert_eq!(first_file["dictionary_size_bytes"], 4096 * 1024);
        assert_eq!(first_file["is_directory"], false);
        assert_eq!(first_file["split_before"], false);
        assert_eq!(first_file["split_after"], true);
        assert_eq!(first_file["solid"], true);
        assert_eq!(first_file["filename"], "scene-release.mkv");
        assert_eq!(first_file["ascii_name_prefix"], "scene-release.mkv");
        assert_eq!(first_file["name_encoding"], "ascii");
        assert_eq!(first_file["name_utf8_valid"], true);

        // 2. 64-bit LHD_LARGE + unknown unpacked size (u64::MAX) + LHD_UNICODE NUL-split + LHD_SALT
        let mut large_archive = RAR4.to_vec();
        large_archive.extend_from_slice(&build_rar4_block(0x73, 0x0000, &[0u8; 6]));
        let raw_unicode_name = b"ascii_fallback.bin\0encoded_tail";
        let mut large_body = Vec::new();
        large_body.extend_from_slice(&0x1000_0000u32.to_le_bytes()); // pack_size_low
        large_body.extend_from_slice(&0xffff_ffffu32.to_le_bytes()); // unp_size_low
        large_body.push(2); // host_os = win32
        large_body.extend_from_slice(&0x1122_3344u32.to_le_bytes());
        large_body.extend_from_slice(&0u32.to_le_bytes());
        large_body.push(29);
        large_body.push(0x30); // store
        large_body.extend_from_slice(&(raw_unicode_name.len() as u16).to_le_bytes());
        large_body.extend_from_slice(&0u32.to_le_bytes());
        large_body.extend_from_slice(&1u32.to_le_bytes()); // high_pack_size = 1
        large_body.extend_from_slice(&0xffff_ffffu32.to_le_bytes()); // high_unp_size = u32::MAX
        large_body.extend_from_slice(raw_unicode_name);
        large_body.extend_from_slice(&[0xaa; 8]); // 8-byte salt
        // flags: LHD_LARGE (0x0100) | LHD_UNICODE (0x0200) | LHD_SALT (0x0400) | LHD_PASSWORD (0x0004)
        large_archive.extend_from_slice(&build_rar4_block(0x74, 0x0704, &large_body));

        let large_json = serde_json::to_value(parse(&large_archive)).unwrap();
        let large_file = &large_json["headers"][0]["first_file"];
        assert_eq!(
            large_json["headers"][0]["first_file_header_status"],
            "parsed"
        );
        assert_eq!(large_file["large_file"], true);
        assert_eq!(large_file["compressed_size"], (1u64 << 32) | 0x1000_0000u64);
        assert!(large_file["uncompressed_size"].is_null());
        assert_eq!(large_file["uncompressed_size_unknown"], true);
        assert_eq!(large_file["host_os_name"], "win32");
        assert_eq!(large_file["method_name"], "store");
        assert_eq!(large_file["encrypted"], true);
        assert_eq!(large_file["salt_present"], true);
        assert_eq!(large_file["salt_hex"], "aaaaaaaaaaaaaaaa");
        assert_eq!(large_file["unicode_filename"], true);
        assert_eq!(large_file["name_encoding"], "rar4_unicode_split");
        assert!(large_file["filename"].is_null());
        assert_eq!(large_file["ascii_name_prefix"], "ascii_fallback.bin");

        // 3. Encrypted headers (MHD_PASSWORD = 0x0080)
        let mut enc_archive = RAR4.to_vec();
        enc_archive.extend_from_slice(&build_rar4_block(0x73, 0x0080, &[0u8; 6]));
        let enc_json = serde_json::to_value(parse(&enc_archive)).unwrap();
        assert_eq!(
            enc_json["headers"][0]["first_file_header_status"],
            "encrypted_headers"
        );
        assert!(enc_json["headers"][0]["first_file"].is_null());

        // 4. Non-file block following ARCHIVE_HEAD (e.g., CMT_HEAD = 0x75)
        let mut cmt_archive = RAR4.to_vec();
        cmt_archive.extend_from_slice(&build_rar4_block(0x73, 0x0000, &[0u8; 6]));
        cmt_archive.extend_from_slice(&build_rar4_block(0x75, 0x0000, &[0u8; 4]));
        let cmt_json = serde_json::to_value(parse(&cmt_archive)).unwrap();
        assert_eq!(
            cmt_json["headers"][0]["first_file_header_status"],
            "non_file_block"
        );
        assert_eq!(cmt_json["headers"][0]["next_block_type"], 0x75);
        assert!(cmt_json["headers"][0]["first_file"].is_null());
        assert_eq!(
            cmt_json["headers"][0]["visible_block_walk"]["comment_block_seen"],
            true
        );

        // 5. Invalid FILE_HEAD CRC16
        let mut bad_crc_archive = standard_archive.clone();
        bad_crc_archive[20] ^= 0xff;
        let bad_crc_json = serde_json::to_value(parse(&bad_crc_archive)).unwrap();
        assert_eq!(
            bad_crc_json["headers"][0]["first_file_header_status"],
            "checksum_invalid"
        );
        assert_eq!(
            bad_crc_json["headers"][0]["first_file"]["checksum_valid"],
            false
        );
        assert_eq!(
            bad_crc_json["headers"][0]["visible_block_walk"]["status"],
            "header_checksum_invalid"
        );

        // 6. Visible block walk across CMT subblock (0x7a), FILE_HEAD (0x74), RR subblock (0x7a), and ENDARC_HEAD (0x7b)
        let mut walk_archive = RAR4.to_vec();
        walk_archive.extend_from_slice(&build_rar4_block(0x73, 0x0001, &[0u8; 6]));

        // Subblock 0x7a ("CMT") with 4 bytes of comment payload
        let mut cmt_sub_body = Vec::new();
        cmt_sub_body.extend_from_slice(&4u32.to_le_bytes()); // pack_size_low = 4
        cmt_sub_body.extend_from_slice(&4u32.to_le_bytes()); // unp_size_low = 4
        cmt_sub_body.push(3); // host_os
        cmt_sub_body.extend_from_slice(&0x1234_5678u32.to_le_bytes());
        cmt_sub_body.extend_from_slice(&0u32.to_le_bytes());
        cmt_sub_body.push(29);
        cmt_sub_body.push(0x30);
        cmt_sub_body.extend_from_slice(&3u16.to_le_bytes()); // name_size = 3 ("CMT")
        cmt_sub_body.extend_from_slice(&0u32.to_le_bytes());
        cmt_sub_body.extend_from_slice(b"CMT");
        walk_archive.extend_from_slice(&build_rar4_block(0x7a, 0x8000, &cmt_sub_body));
        walk_archive.extend_from_slice(b"test"); // 4-byte subblock payload

        // FILE_HEAD (0x74) with 3 bytes of packed payload so the walk can advance to the next block
        let small_name = b"episode01.mkv";
        let mut small_file_body = Vec::new();
        small_file_body.extend_from_slice(&3u32.to_le_bytes()); // pack_size_low = 3
        small_file_body.extend_from_slice(&1024u32.to_le_bytes()); // unp_size_low = 1024
        small_file_body.push(3); // unix
        small_file_body.extend_from_slice(&0xcafe_babeu32.to_le_bytes());
        small_file_body.extend_from_slice(&0x5421_1234u32.to_le_bytes());
        small_file_body.push(29);
        small_file_body.push(0x30); // store
        small_file_body.extend_from_slice(&(small_name.len() as u16).to_le_bytes());
        small_file_body.extend_from_slice(&0x0020u32.to_le_bytes());
        small_file_body.extend_from_slice(small_name);
        walk_archive.extend_from_slice(&build_rar4_block(0x74, 0x8000, &small_file_body));
        walk_archive.extend_from_slice(b"abc"); // 3-byte file payload

        // Subblock 0x7a ("RR") with 0 bytes payload
        let mut rr_sub_body = Vec::new();
        rr_sub_body.extend_from_slice(&0u32.to_le_bytes());
        rr_sub_body.extend_from_slice(&0u32.to_le_bytes());
        rr_sub_body.push(3);
        rr_sub_body.extend_from_slice(&0u32.to_le_bytes());
        rr_sub_body.extend_from_slice(&0u32.to_le_bytes());
        rr_sub_body.push(29);
        rr_sub_body.push(0x30);
        rr_sub_body.extend_from_slice(&2u16.to_le_bytes()); // "RR"
        rr_sub_body.extend_from_slice(&0u32.to_le_bytes());
        rr_sub_body.extend_from_slice(b"RR");
        walk_archive.extend_from_slice(&build_rar4_block(0x7a, 0x8000, &rr_sub_body));

        // ENDARC_HEAD (0x7b) with EARC_NEXT_VOLUME (0x0001) | EARC_DATACRC (0x0002) | EARC_VOLNUMBER (0x0008)
        let mut endarc_body = Vec::new();
        endarc_body.extend_from_slice(&0x89ab_cdefu32.to_le_bytes()); // archive data CRC32
        endarc_body.extend_from_slice(&7u16.to_le_bytes()); // volume number = 7
        walk_archive.extend_from_slice(&build_rar4_block(0x7b, 0x000b, &endarc_body));

        let walk_json = serde_json::to_value(parse(&walk_archive)).unwrap();
        let walk_rar4 = &walk_json["headers"][0];
        assert_eq!(walk_rar4["next_block_type"], 0x7a);
        assert_eq!(
            walk_rar4["first_file_header_status"],
            "parsed_after_prefix_blocks"
        );
        assert_eq!(walk_rar4["first_file"]["filename"], "episode01.mkv");
        assert_eq!(walk_rar4["first_file"]["file_crc32"], 0xcafe_babeu32);

        let walk = &walk_rar4["visible_block_walk"];
        assert_eq!(walk["status"], "end_of_archive");
        assert_eq!(walk["inspected_block_count"], 4);
        assert_eq!(walk["file_block_count"], 1);
        assert_eq!(walk["subblock_count"], 2);
        assert_eq!(walk["comment_block_seen"], true);
        assert_eq!(walk["recovery_block_seen"], true);
        assert_eq!(walk["authenticity_block_seen"], false);
        assert_eq!(walk["end_of_archive_seen"], true);
        assert_eq!(walk["end_of_archive_next_volume"], true);
        assert_eq!(walk["end_of_archive_data_crc32"], 0x89ab_cdefu32);
        assert_eq!(walk["end_of_archive_rev_space_present"], false);
        assert_eq!(walk["end_of_archive_volume_number"], 7);
        assert_eq!(walk["blocks"][0]["header_type_name"], "new_subblock");
        assert_eq!(walk["blocks"][0]["subblock_name"], "CMT");
        assert_eq!(walk["blocks"][1]["header_type_name"], "file_header");
        assert_eq!(
            walk["blocks"][1]["file_header"]["filename"],
            "episode01.mkv"
        );
        assert_eq!(walk["blocks"][2]["subblock_name"], "RR");
        assert_eq!(walk["blocks"][3]["header_type_name"], "end_of_archive");
    }

    #[test]
    fn zip_and_gzip_resolve_filenames_and_unicode_path_authority() {
        let build_zip = |flags: u16, name: &[u8], extra: &[u8]| -> Vec<u8> {
            let mut buf = Vec::new();
            buf.extend_from_slice(ZIP);
            buf.extend_from_slice(&20u16.to_le_bytes()); // version needed
            buf.extend_from_slice(&flags.to_le_bytes());
            buf.extend_from_slice(&8u16.to_le_bytes()); // deflate
            buf.extend_from_slice(&0u16.to_le_bytes()); // time
            buf.extend_from_slice(&0u16.to_le_bytes()); // date
            buf.extend_from_slice(&0x1234_5678u32.to_le_bytes()); // crc32
            buf.extend_from_slice(&100u32.to_le_bytes()); // compressed
            buf.extend_from_slice(&200u32.to_le_bytes()); // uncompressed
            buf.extend_from_slice(&(name.len() as u16).to_le_bytes());
            buf.extend_from_slice(&(extra.len() as u16).to_le_bytes());
            buf.extend_from_slice(name);
            buf.extend_from_slice(extra);
            buf
        };

        let build_upath_extra = |version: u8, crc32: u32, utf8_name: &[u8]| -> Vec<u8> {
            let payload_len = 5 + utf8_name.len();
            let mut extra = Vec::new();
            extra.extend_from_slice(&0x7075u16.to_le_bytes());
            extra.extend_from_slice(&(payload_len as u16).to_le_bytes());
            extra.push(version);
            extra.extend_from_slice(&crc32.to_le_bytes());
            extra.extend_from_slice(utf8_name);
            extra
        };

        // 1. Legacy CP437 local filename overridden by valid 0x7075 Info-ZIP Unicode Path extra field
        let legacy_name = b"caf\x82.mkv";
        let legacy_crc = crc32fast::hash(legacy_name);
        let override_utf8 = "café.mkv".as_bytes();
        let upath_extra = build_upath_extra(1, legacy_crc, override_utf8);
        let zip_override = build_zip(0x0000, legacy_name, &upath_extra);
        let json_override = serde_json::to_value(parse(&zip_override)).unwrap();
        let h_override = &json_override["headers"][0];
        assert!(h_override["filename"].is_null());
        assert_eq!(h_override["filename_display_truncated"], false);
        assert_eq!(h_override["resolved_filename"], "café.mkv");
        assert_eq!(h_override["resolved_filename_display_truncated"], false);
        assert_eq!(h_override["resolved_filename_source"], "unicode_path_extra");
        assert_eq!(h_override["resolved_filename_status"], "resolved");
        assert_eq!(
            h_override["zip_unicode_path_records"][0]["unicode_filename"],
            "café.mkv"
        );
        assert_eq!(
            h_override["zip_unicode_path_records"][0]["unicode_filename_display_truncated"],
            false
        );

        // 2. EFS UTF-8 flag set + matching 0x7075 extra field
        let utf8_name = "série-01.mkv".as_bytes();
        let utf8_crc = crc32fast::hash(utf8_name);
        let upath_confirm = build_upath_extra(1, utf8_crc, utf8_name);
        let zip_confirmed = build_zip(0x0800, utf8_name, &upath_confirm);
        let json_confirmed = serde_json::to_value(parse(&zip_confirmed)).unwrap();
        let h_confirmed = &json_confirmed["headers"][0];
        assert_eq!(h_confirmed["filename"], "série-01.mkv");
        assert_eq!(h_confirmed["resolved_filename"], "série-01.mkv");
        assert_eq!(
            h_confirmed["resolved_filename_source"],
            "local_header_utf8_confirmed_by_unicode_path_extra"
        );
        assert_eq!(h_confirmed["resolved_filename_status"], "resolved");

        // 3. Conflicting duplicate 0x7075 extra fields -> unicode_path_extra_ambiguous
        let mut conflicting_extra = build_upath_extra(1, legacy_crc, "café-a.mkv".as_bytes());
        conflicting_extra.extend_from_slice(&build_upath_extra(
            1,
            legacy_crc,
            "café-b.mkv".as_bytes(),
        ));
        let zip_conflict = build_zip(0x0000, legacy_name, &conflicting_extra);
        let json_conflict = serde_json::to_value(parse(&zip_conflict)).unwrap();
        let h_conflict = &json_conflict["headers"][0];
        assert!(h_conflict["resolved_filename"].is_null());
        assert!(h_conflict["resolved_filename_source"].is_null());
        assert_eq!(
            h_conflict["resolved_filename_status"],
            "unicode_path_extra_ambiguous"
        );

        // 4. ASCII local filename without EFS flag
        let zip_ascii = build_zip(0x0000, b"release.r00", &[]);
        let json_ascii = serde_json::to_value(parse(&zip_ascii)).unwrap();
        let h_ascii = &json_ascii["headers"][0];
        assert_eq!(h_ascii["filename"], "release.r00");
        assert_eq!(h_ascii["resolved_filename"], "release.r00");
        assert_eq!(h_ascii["resolved_filename_source"], "local_header_ascii");
        assert_eq!(h_ascii["resolved_filename_status"], "resolved");

        // 5. Legacy non-UTF-8 without 0x7075 extra field
        let zip_legacy = build_zip(0x0000, legacy_name, &[]);
        let json_legacy = serde_json::to_value(parse(&zip_legacy)).unwrap();
        let h_legacy = &json_legacy["headers"][0];
        assert!(h_legacy["filename"].is_null());
        assert!(h_legacy["resolved_filename"].is_null());
        assert!(h_legacy["resolved_filename_source"].is_null());
        assert_eq!(
            h_legacy["resolved_filename_status"],
            "legacy_non_utf8_without_unicode_path_extra"
        );

        // 6. Gzip with ISO-8859-1 FNAME and UTF-8-looking FCOMMENT. RFC 1952 mandates
        // Latin-1, so the comment's authoritative text is the Latin-1 reading and the
        // UTF-8 reading is exposed only as a non-authoritative alternative.
        let mut gzip_buf = vec![0x1f, 0x8b, 0x08, 0x18, 0, 0, 0, 0, 0x02, 0x03];
        gzip_buf.extend_from_slice(b"caf\xe9.tar\0"); // ISO-8859-1 'é' (0xe9)
        gzip_buf.extend_from_slice("packed by café\0".as_bytes()); // UTF-8 bytes
        let json_gzip = serde_json::to_value(parse(&gzip_buf)).unwrap();
        let h_gzip = &json_gzip["headers"][0];
        assert_eq!(h_gzip["filename_utf8_valid"], false);
        assert_eq!(h_gzip["filename_latin1_printable"], true);
        assert_eq!(h_gzip["filename_encoding"], "iso_8859_1");
        assert_eq!(h_gzip["filename"], "café.tar");
        assert!(h_gzip["filename_utf8_text"].is_null());
        assert_eq!(h_gzip["filename_display_truncated"], false);
        assert_eq!(h_gzip["comment_utf8_valid"], true);
        assert_eq!(h_gzip["comment_latin1_printable"], true);
        assert_eq!(h_gzip["comment_encoding"], "iso_8859_1");
        assert_eq!(h_gzip["comment"], "packed by caf\u{c3}\u{a9}");
        assert_eq!(h_gzip["comment_utf8_text"], "packed by café");
        assert_eq!(h_gzip["comment_utf8_text_display_truncated"], false);
        assert_eq!(h_gzip["comment_display_truncated"], false);
    }

    fn zip_upath_bytes(flags: u16, name: &[u8], extras: &[Vec<u8>]) -> Vec<u8> {
        let extra: Vec<u8> = extras.concat();
        let mut buf = Vec::new();
        buf.extend_from_slice(ZIP);
        buf.extend_from_slice(&20u16.to_le_bytes());
        buf.extend_from_slice(&flags.to_le_bytes());
        buf.extend_from_slice(&8u16.to_le_bytes());
        buf.extend_from_slice(&0u16.to_le_bytes());
        buf.extend_from_slice(&0u16.to_le_bytes());
        buf.extend_from_slice(&0x1234_5678u32.to_le_bytes());
        buf.extend_from_slice(&100u32.to_le_bytes());
        buf.extend_from_slice(&200u32.to_le_bytes());
        buf.extend_from_slice(&(name.len() as u16).to_le_bytes());
        buf.extend_from_slice(&(extra.len() as u16).to_le_bytes());
        buf.extend_from_slice(name);
        buf.extend_from_slice(&extra);
        buf
    }

    fn zip_upath_fixture(flags: u16, name: &[u8], extras: &[Vec<u8>]) -> serde_json::Value {
        serde_json::to_value(parse(&zip_upath_bytes(flags, name, extras))).unwrap()
    }

    fn zip_upath_record(version: u8, crc32: u32, unicode_name: &[u8]) -> Vec<u8> {
        let mut extra = Vec::new();
        extra.extend_from_slice(&0x7075u16.to_le_bytes());
        extra.extend_from_slice(&((5 + unicode_name.len()) as u16).to_le_bytes());
        extra.push(version);
        extra.extend_from_slice(&crc32.to_le_bytes());
        extra.extend_from_slice(unicode_name);
        extra
    }

    #[test]
    fn zip_duplicate_unicode_path_records_are_ambiguous_even_when_one_is_invalid() {
        let local = b"caf\x82.mkv";
        let crc = crc32fast::hash(local);
        let valid = zip_upath_record(1, crc, "café.mkv".as_bytes());
        let stale_crc = zip_upath_record(1, crc ^ 1, "cafe-stale.mkv".as_bytes());
        let bad_version = zip_upath_record(2, crc, "cafe-v2.mkv".as_bytes());
        let invalid_utf8 = zip_upath_record(1, crc, b"caf\xe9.mkv");
        let empty_name = zip_upath_record(1, crc, b"");

        // A single valid record still resolves.
        let single = zip_upath_fixture(0, local, std::slice::from_ref(&valid));
        assert_eq!(single["headers"][0]["resolved_filename"], "café.mkv");
        assert_eq!(single["headers"][0]["resolved_filename_status"], "resolved");

        // Every ordering of a valid record and one unusable record is ambiguous.
        for unusable in [&stale_crc, &bad_version, &invalid_utf8, &empty_name] {
            for pair in [
                [valid.clone(), unusable.clone()],
                [unusable.clone(), valid.clone()],
            ] {
                let json = zip_upath_fixture(0, local, &pair);
                let header = &json["headers"][0];
                assert_eq!(header["zip_unicode_path_record_count"], 2);
                assert!(header["resolved_filename"].is_null());
                assert!(header["resolved_filename_source"].is_null());
                assert_eq!(
                    header["resolved_filename_status"],
                    "unicode_path_extra_ambiguous"
                );
            }
        }

        // Identical duplicates are also withheld: duplicates are forbidden outright.
        let identical = zip_upath_fixture(0, local, &[valid.clone(), valid.clone()]);
        assert_eq!(
            identical["headers"][0]["resolved_filename_status"],
            "unicode_path_extra_ambiguous"
        );

        // Two unusable duplicates are ambiguous too, rather than silently falling
        // back to the local name.
        let both_bad = zip_upath_fixture(0, local, &[stale_crc.clone(), bad_version.clone()]);
        assert_eq!(
            both_bad["headers"][0]["resolved_filename_status"],
            "unicode_path_extra_ambiguous"
        );
    }

    #[test]
    fn zip_empty_unicode_path_name_is_not_a_filename_override() {
        let local = b"release.mkv";
        let crc = crc32fast::hash(local);
        let header_only = zip_upath_record(1, crc, b"");
        let json = zip_upath_fixture(0, local, &[header_only]);
        let header = &json["headers"][0];
        let record = &header["zip_unicode_path_records"][0];
        assert_eq!(record["payload_length"], 5);
        assert_eq!(record["filename_crc32_matches"], true);
        assert_eq!(record["unicode_filename_utf8_valid"], true);
        assert_eq!(record["usable_as_filename_override"], false);
        // The non-empty local name keeps authority instead of becoming "".
        assert_eq!(header["resolved_filename"], "release.mkv");
        assert_eq!(header["resolved_filename_source"], "local_header_ascii");
        assert_eq!(header["resolved_filename_status"], "resolved");

        // A one-byte name is the smallest usable record.
        let minimal = zip_upath_record(1, crc, b"x");
        let json = zip_upath_fixture(0, local, &[minimal]);
        assert_eq!(
            json["headers"][0]["zip_unicode_path_records"][0]["usable_as_filename_override"],
            true
        );
        assert_eq!(json["headers"][0]["resolved_filename"], "x");
    }

    #[test]
    fn concatenated_sample_yields_zip_and_both_gzip_text_cases_for_the_php_integration() {
        // Mirrors the byte layout in ReleaseFingerprintMetadataTest so the Linux-only
        // PHP integration assertions are also exercised by cargo test.
        let local = b"caf\x82.mkv";
        let zip = {
            let record = zip_upath_record(1, crc32fast::hash(local), "café.mkv".as_bytes());
            let mut buf = Vec::new();
            buf.extend_from_slice(ZIP);
            buf.extend_from_slice(&20u16.to_le_bytes());
            buf.extend_from_slice(&0u16.to_le_bytes());
            buf.extend_from_slice(&8u16.to_le_bytes());
            buf.extend_from_slice(&0u16.to_le_bytes());
            buf.extend_from_slice(&0u16.to_le_bytes());
            buf.extend_from_slice(&0x1234_5678u32.to_le_bytes());
            buf.extend_from_slice(&100u32.to_le_bytes());
            buf.extend_from_slice(&200u32.to_le_bytes());
            buf.extend_from_slice(&(local.len() as u16).to_le_bytes());
            buf.extend_from_slice(&(record.len() as u16).to_le_bytes());
            buf.extend_from_slice(local);
            buf.extend_from_slice(&record);
            buf
        };
        let gzip_head = [0x1f, 0x8b, 0x08, 0x18, 0, 0, 0, 0, 0x02, 0x03];
        let mut sample = zip;
        sample.extend_from_slice(&gzip_head);
        sample.extend_from_slice(b"caf\xe9.tar\0packed by caf\xc3\xa9\0");
        sample.extend_from_slice(&gzip_head);
        sample.extend_from_slice(b"\xc3\x89pisode.tar\0\xe2\x80\x93 note\0");
        let json = serde_json::to_value(parse(&sample)).unwrap();
        let headers = json["headers"].as_array().unwrap();
        assert_eq!(headers.len(), 3);
        assert_eq!(headers[0]["resolved_filename"], "café.mkv");
        assert_eq!(headers[1]["comment_utf8_text"], "packed by café");
        assert_eq!(headers[2]["filename_encoding"], "iso_8859_1");
        assert_eq!(headers[2]["filename_utf8_text"], "Épisode.tar");
        assert_eq!(headers[2]["comment_utf8_text"], "– note");
    }

    #[test]
    fn zip_filename_authority_is_withheld_for_embedded_nul_names() {
        // Readers stop at the NUL, so the full byte string is not a shared filename.
        let local = b"visible.txt\0hidden.mkv";
        for flags in [0u16, 0x0800] {
            let json = zip_upath_fixture(flags, local, &[]);
            let header = &json["headers"][0];
            assert!(header["resolved_filename"].is_null());
            assert!(header["resolved_filename_source"].is_null());
            assert_eq!(
                header["resolved_filename_status"],
                "local_filename_contains_nul"
            );
            assert_eq!(header["filename_bytes_length"], local.len());
        }

        // A Unicode Path name with an embedded NUL is not an override either; the
        // clean local name keeps authority.
        let clean = b"release.mkv";
        let record = zip_upath_record(1, crc32fast::hash(clean), b"a.mkv\0b.mkv");
        let json = zip_upath_fixture(0, clean, std::slice::from_ref(&record));
        let header = &json["headers"][0];
        assert_eq!(
            header["zip_unicode_path_records"][0]["usable_as_filename_override"],
            false
        );
        assert_eq!(header["resolved_filename"], "release.mkv");

        // An NUL-bearing local name cannot be rescued by a valid Unicode Path record.
        let record = zip_upath_record(1, crc32fast::hash(local), b"real.mkv");
        let json = zip_upath_fixture(0, local, std::slice::from_ref(&record));
        assert_eq!(
            json["headers"][0]["resolved_filename_status"],
            "local_filename_contains_nul"
        );
    }

    #[test]
    fn zip_filename_authority_is_withheld_for_masked_names_and_uninspected_extras() {
        // Bit 13 (central directory encryption) masks local header names: the token
        // is not the filename, even though it looks like plain ASCII.
        let masked = zip_upath_fixture(0x2000, b"00000001", &[]);
        let header = &masked["headers"][0];
        assert!(header["resolved_filename"].is_null());
        assert!(header["resolved_filename_source"].is_null());
        assert_eq!(header["resolved_filename_status"], "local_header_masked");
        // The raw observation stays available.
        assert_eq!(header["filename_bytes_length"], 8);
        assert_eq!(header["filename"], "00000001");

        // A Unicode Path record can also not rescue a masked name.
        let local = b"00000001";
        let record = zip_upath_record(1, crc32fast::hash(local), b"real.mkv");
        let masked_with_extra = zip_upath_fixture(0x2000, local, std::slice::from_ref(&record));
        assert_eq!(
            masked_with_extra["headers"][0]["resolved_filename_status"],
            "local_header_masked"
        );

        // Declared extra area is cut off by the end of the sample: an unseen 0x7075
        // record could override the visible ASCII name.
        let full = zip_upath_bytes(0, b"release.mkv", std::slice::from_ref(&record));
        let cut = &full[..full.len() - 3];
        let truncated = serde_json::to_value(parse(cut)).unwrap();
        let header = &truncated["headers"][0];
        assert!(header["resolved_filename"].is_null());
        assert_eq!(
            header["resolved_filename_status"],
            "extra_fields_incomplete"
        );
        let no_extra_at_all = zip_upath_bytes(0, b"release.mkv", &[vec![0u8; 8]]);
        let cut = &no_extra_at_all[..30 + 11];
        let truncated = serde_json::to_value(parse(cut)).unwrap();
        assert_eq!(
            truncated["headers"][0]["resolved_filename_status"],
            "extra_fields_incomplete"
        );

        // A malformed suffix after a usable record can hide a duplicate, so the
        // usable record no longer carries authority.
        let usable = zip_upath_record(1, crc32fast::hash(b"release.mkv"), b"x.mkv");
        let clean = zip_upath_fixture(0, b"release.mkv", std::slice::from_ref(&usable));
        assert_eq!(clean["headers"][0]["resolved_filename"], "x.mkv");
        let suffixed = zip_upath_fixture(0, b"release.mkv", &[usable, vec![0x75, 0x70, 0x40]]);
        let header = &suffixed["headers"][0];
        assert_eq!(header["extra_field_records_complete"], false);
        assert!(header["resolved_filename"].is_null());
        assert_eq!(
            header["resolved_filename_status"],
            "extra_fields_incomplete"
        );

        // Without any extra area the declared-empty case is complete and resolves.
        let plain = zip_upath_fixture(0, b"release.mkv", &[]);
        assert_eq!(plain["headers"][0]["resolved_filename"], "release.mkv");
    }

    fn gzip_text_fixture(fname: &[u8], fcomment: &[u8]) -> serde_json::Value {
        let mut buf = vec![0x1f, 0x8b, 0x08, 0x18, 0, 0, 0, 0, 0x02, 0x03];
        buf.extend_from_slice(fname);
        buf.push(0);
        buf.extend_from_slice(fcomment);
        buf.push(0);
        serde_json::to_value(parse(&buf)).unwrap()
    }

    #[test]
    fn gzip_text_fields_use_rfc_latin1_and_never_reject_valid_utf8_as_binary() {
        // Valid UTF-8 whose continuation bytes fall in 0x80..=0x9F ("É" = C3 89) is
        // text, not binary. RFC 1952 Latin-1 remains authoritative; UTF-8 is the
        // observed alternative.
        let json = gzip_text_fixture("Épisode.tar".as_bytes(), "– naïve 日本語".as_bytes());
        let h = &json["headers"][0];
        assert_eq!(h["filename_utf8_valid"], true);
        assert_eq!(h["filename_latin1_printable"], false); // C1 control bytes present
        assert_eq!(h["filename_encoding"], "iso_8859_1");
        assert_eq!(h["filename"], "\u{c3}\u{89}pisode.tar");
        assert_eq!(h["filename_utf8_text"], "Épisode.tar");
        assert_eq!(h["filename_utf8_text_display_truncated"], false);
        assert_eq!(h["comment_encoding"], "iso_8859_1");
        assert_eq!(h["comment_utf8_text"], "– naïve 日本語");

        // UTF-8-looking printable bytes are still recorded as Latin-1.
        let json = gzip_text_fixture(b"caf\xc3\xa9", b"plain");
        let h = &json["headers"][0];
        assert_eq!(h["filename_utf8_valid"], true);
        assert_eq!(h["filename_latin1_printable"], true);
        assert_eq!(h["filename_encoding"], "iso_8859_1");
        assert_eq!(h["filename"], "caf\u{c3}\u{a9}");
        assert_ne!(h["filename"], "café");
        assert_eq!(h["filename_utf8_text"], "café");
        assert_eq!(h["comment_encoding"], "ascii");
        assert_eq!(h["comment"], "plain");
        assert!(h["comment_utf8_text"].is_null());

        // Pure Latin-1 has no UTF-8 alternative.
        let json = gzip_text_fixture(b"caf\xe9", b"");
        let h = &json["headers"][0];
        assert_eq!(h["filename_utf8_valid"], false);
        assert_eq!(h["filename_encoding"], "iso_8859_1");
        assert_eq!(h["filename"], "café");
        assert!(h["filename_utf8_text"].is_null());

        // Bytes no text reading explains stay binary with no decoded text: C0
        // controls, DEL, and C1 bytes that are not clean UTF-8.
        for bytes in [
            &b"a\x01b"[..],
            b"a\x7fb",
            b"a\x85b",
            b"\xff\x80\x90",
            b"\xc3\x89\x01",
        ] {
            let json = gzip_text_fixture(bytes, b"ok");
            let h = &json["headers"][0];
            assert_eq!(
                h["filename_encoding"], "binary_or_control_bytes",
                "{bytes:?}"
            );
            assert!(h["filename"].is_null(), "{bytes:?}");
            assert!(h["filename_utf8_text"].is_null(), "{bytes:?}");
            assert_eq!(h["comment_encoding"], "ascii");
        }
        // UTF-8 whose scalar values are control characters is binary, not text.
        let json = gzip_text_fixture(b"\xc2\x85\xc2\x9f", b"ok");
        assert_eq!(
            json["headers"][0]["filename_encoding"],
            "binary_or_control_bytes"
        );

        // The alternative text is bounded on a char boundary.
        let long = "é".repeat(4_000); // 8,000 bytes
        let json = gzip_text_fixture(long.as_bytes(), b"");
        let h = &json["headers"][0];
        assert_eq!(h["filename_encoding"], "iso_8859_1");
        assert_eq!(h["filename_display_truncated"], true);
        assert_eq!(h["filename_utf8_text_display_truncated"], true);
        assert_eq!(
            h["filename_utf8_text"].as_str().unwrap().chars().count(),
            2_048
        );
    }
}
