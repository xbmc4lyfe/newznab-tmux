//! Reference checksum domain of a RAR5 file-copy entry, without resolving its target.
//! UnRAR 7.3.1 extract.cpp/hash.cpp: copy/link entry data are empty; target bytes
//! are written separately and are not fed into the entry's unpacked hash.

use super::Rar5FileHeaderObservation;
use serde::Serialize;

const EMPTY_BLAKE2SP: &str = "dd0e891776933f43c7d032b08a917e25741f8aa9a12c12e1cac8801500f2ca4f";

#[derive(Debug, Serialize)]
pub struct Relationship {
    pub status: &'static str,
    pub evidence_basis: &'static str,
    pub coordinate_basis: &'static str,
    pub source_header_offset: usize,
    pub source_crc32_byte_offset: Option<usize>,
    pub source_record_offset: Option<usize>,
    pub source_record_end_offset: Option<usize>,
    pub source_hash_record_index: Option<usize>,
    pub source_hash_record_offset: Option<usize>,
    pub source_hash_record_end_offset: Option<usize>,
    pub source_hash_digest_offset: Option<usize>,
    pub redirection_type_observed: Option<u64>,
    pub reference_data_domain: Option<&'static str>,
    pub reference_empty_crc32: u32,
    pub reference_empty_blake2sp_hex: &'static str,
    pub crc32_comparison_status: &'static str,
    pub stored_crc32_matches_empty_data: Option<bool>,
    pub blake2sp_comparison_status: &'static str,
    pub stored_blake2sp_matches_empty_data: Option<bool>,
    pub reference_selected_checksum: Option<&'static str>,
    pub reference_selection_status: &'static str,
    pub target_resolution_performed: bool,
}

pub(super) fn derive(file: &mut Rar5FileHeaderObservation, offset: usize, kind: u64, flags: u64) {
    if file.file_extra_records.redirection_record_count == 0
        && file.file_extra_records.status == "complete"
    {
        return;
    }
    let extras = &file.file_extra_records;
    let record = extras.records.iter().find(|r| r.redirection.is_some());
    let redirection = record.and_then(|r| r.redirection.as_ref());
    let hash_record = (extras.hash_record_count == 1)
        .then(|| extras.records.iter().find(|r| r.hash.is_some()))
        .flatten();
    let mut relationship = Relationship {
        status: "redirection_observation_unavailable",
        evidence_basis: "UnRAR 7.3.1 file-copy entry empty-data checksum domain; independent stored checksum declarations, not target contents, extraction success, archive authentication or uploader identity; a supported BLAKE2sp extra replaces CRC32 as the reference-selected algorithm",
        coordinate_basis: "decoded-sample-relative; source_record_* identifies the redirection; source_hash_record_* identifies the stored hash declaration; source_crc32_byte_offset identifies the base CRC declaration",
        source_header_offset: offset,
        source_crc32_byte_offset: file.data_crc32_byte_offset,
        source_record_offset: record.map(|r| r.offset),
        source_record_end_offset: record.map(|r| r.record_end_offset),
        source_hash_record_index: hash_record.map(|r| r.index),
        source_hash_record_offset: hash_record.map(|r| r.offset),
        source_hash_record_end_offset: hash_record.map(|r| r.record_end_offset),
        source_hash_digest_offset: hash_record
            .filter(|r| r.hash.as_ref().is_some_and(|h| h.status == "parsed"))
            .and_then(|r| r.record_end_offset.checked_sub(32)),
        redirection_type_observed: redirection.and_then(|r| r.redirection_type),
        reference_data_domain: None,
        reference_empty_crc32: 0,
        reference_empty_blake2sp_hex: EMPTY_BLAKE2SP,
        crc32_comparison_status: "domain_unqualified",
        stored_crc32_matches_empty_data: None,
        blake2sp_comparison_status: "domain_unqualified",
        stored_blake2sp_matches_empty_data: None,
        reference_selected_checksum: None,
        reference_selection_status: "domain_unqualified",
        target_resolution_performed: false,
    };
    let unqualified = if kind != 2 {
        Some("service_redirection_scope_not_interpreted")
    } else if file.header_crc32_valid != Some(true) {
        Some("header_checksum_unconfirmed")
    } else if file.status != "parsed" {
        Some("file_header_fields_incomplete")
    } else if extras.status != "complete" {
        Some("extra_framing_incomplete")
    } else if extras.redirection_record_count != 1 {
        Some("redirection_records_ambiguous")
    } else if flags & !0x7f != 0 || file.file_flags.is_none_or(|f| f & !0x0e != 0) {
        Some("file_flags_unsupported")
    } else if file.split_before || file.split_after {
        Some("split_entry_scope_not_interpreted")
    } else if file.packed_size != Some(0) || file.unpacked_size_unknown != Some(false) {
        Some("entry_sizes_unqualified")
    } else if file.compression_method != Some(0)
        || file.solid != Some(false)
        || !file
            .compression_metadata
            .as_ref()
            .is_some_and(|c| c.interpretation_status == "supported")
    {
        Some("entry_compression_unsupported")
    } else if let Some(r) = redirection {
        if r.status != "parsed" || r.interpretation_status != "validated_header_declaration" {
            Some("redirection_declaration_unqualified")
        } else if r.redirection_type != Some(5) || r.flags != Some(0) {
            Some("redirection_type_or_flags_not_interpreted")
        } else if r.target_byte_length == Some(0) || r.target_text_truncated {
            Some("reference_target_name_unqualified")
        } else {
            None
        }
    } else {
        Some("redirection_observation_unavailable")
    };
    file.checksum_domain = "redirection_checksum_domain_unqualified";
    if let Some(status) = unqualified {
        relationship.status = status;
    } else {
        file.checksum_domain = "empty_file_copy_entry_data";
        relationship.reference_data_domain = Some(file.checksum_domain);
        relationship.status = "qualified_empty_entry_domain";
        if file.encryption_presence != "absent" || file.checksum_transformation != "plain" {
            relationship.status = "empty_entry_checksum_transformation_unqualified";
            relationship.crc32_comparison_status = "plaintext_checksum_unqualified";
            relationship.blake2sp_comparison_status = "plaintext_checksum_unqualified";
            relationship.reference_selection_status = "plaintext_checksum_unqualified";
        } else {
            if let Some(crc) = file.data_crc32 {
                relationship.stored_crc32_matches_empty_data = Some(crc == 0);
                relationship.crc32_comparison_status =
                    if crc == 0 { "matched" } else { "mismatch" };
            } else {
                relationship.crc32_comparison_status = "not_declared";
            }
            if extras.hash_record_count == 0 {
                relationship.blake2sp_comparison_status = "not_declared";
                relationship.reference_selected_checksum = file.data_crc32.map(|_| "CRC32");
                relationship.reference_selection_status =
                    "no_hash_extra_crc32_selection_if_declared";
            } else if extras.hash_record_count == 1 && hash_record.is_none() {
                relationship.blake2sp_comparison_status = "hash_source_observation_unavailable";
                relationship.reference_selection_status = "hash_source_observation_unavailable";
            } else if extras.hash_record_count == 1
                && extras.stored_hash_status == "unambiguous_stored_checksum_not_payload_verified"
                && let Some(hash) = &extras.unambiguous_stored_blake2sp_hex
            {
                let matched = hash == EMPTY_BLAKE2SP;
                relationship.stored_blake2sp_matches_empty_data = Some(matched);
                relationship.blake2sp_comparison_status =
                    if matched { "matched" } else { "mismatch" };
                relationship.reference_selected_checksum = Some("BLAKE2sp");
                relationship.reference_selection_status =
                    "unique_supported_hash_extra_replaces_crc32";
            } else {
                relationship.blake2sp_comparison_status = extras.stored_hash_status;
                relationship.reference_selection_status = "hash_extra_selection_unqualified";
            }
        }
    }
    if file.data_crc32.is_some() {
        file.data_crc32_scope = Some(file.checksum_domain);
        file.data_crc32_scope_basis = Some("RAR5_redirection_entry_domain_qualification");
    }
    file.redirection_checksum_relationship = Some(relationship);
}

#[cfg(test)]
mod tests {
    use super::*;

    fn literal(hex: &str) -> Vec<u8> {
        hex.as_bytes()
            .as_chunks::<2>()
            .0
            .iter()
            .map(|b| u8::from_str_radix(std::str::from_utf8(b).unwrap(), 16).unwrap())
            .collect()
    }

    fn file(header: &[u8], prefix: &[u8]) -> serde_json::Value {
        let sample = [
            prefix,
            &literal("526172211a070100c51a333203010000"),
            header,
            &literal("19b23a3503050000"),
        ]
        .concat();
        let value = serde_json::to_value(super::super::parse(&sample)).unwrap();
        let block = &value["headers"][0]["visible_block_walk"]["blocks"][1];
        assert_eq!(block["header_crc32_valid"], true);
        if block["file_header"].is_null() {
            block["service_header"].clone()
        } else {
            block["file_header"].clone()
        }
    }

    fn vint(mut value: u64) -> Vec<u8> {
        let mut out = Vec::new();
        loop {
            let byte = (value & 127) as u8;
            value >>= 7;
            out.push(byte | if value > 0 { 128 } else { 0 });
            if value == 0 {
                return out;
            }
        }
    }

    fn framed(extra: &[u8], config: (u64, u64, u64, u64, u64)) -> Vec<u8> {
        let (common, file_flags, packed, unpacked, compression) = config;
        let mut body = vec![2];
        body.extend(vint(common));
        body.extend(vint(extra.len() as u64));
        body.extend(vint(packed));
        body.extend(vint(file_flags));
        body.extend(vint(unpacked));
        body.push(0);
        if file_flags & 4 != 0 {
            body.extend(0u32.to_le_bytes());
        }
        body.extend(vint(compression));
        body.extend([0, 1, b'b']);
        body.extend(extra);
        let mut checked = vint(body.len() as u64);
        checked.extend(body);
        let mut header = crc32fast::hash(&checked).to_le_bytes().to_vec();
        header.extend(checked);
        header
    }

    #[test]
    fn stored_crc_mismatch_is_not_a_comparison_with_target_data() {
        let f = file(
            &literal("c1cf578015020306000401000100000000000162050505000161"),
            b"xyz",
        );
        let r = &f["redirection_checksum_relationship"];
        assert_eq!(f["data_crc32"], 1);
        assert_eq!(f["checksum_domain"], "empty_file_copy_entry_data");
        assert_eq!(r["stored_crc32_matches_empty_data"], false);
        assert_eq!(r["crc32_comparison_status"], "mismatch");
        assert_eq!(r["source_header_offset"], 19);
        assert_eq!(r["source_record_offset"], 39);
        assert_eq!(r["target_resolution_performed"], false);
    }

    #[test]
    fn partial_ambiguous_empty_and_service_redirections_withhold_comparisons() {
        for (hex, status) in [
            (
                "57d998ea1b02030c000401000000000000000162050505000161050505000161",
                "redirection_records_ambiguous",
            ),
            (
                "747d753615020306000401000000000000000162050505000261",
                "redirection_declaration_unqualified",
            ),
            (
                "195ccc9b15020306000401000000000000000162060505000161",
                "extra_framing_incomplete",
            ),
            (
                "8de46fdf140203050004010000000000000001620405050000",
                "reference_target_name_unqualified",
            ),
            (
                "29ad828215030306000401000000000000000162050505000161",
                "service_redirection_scope_not_interpreted",
            ),
        ] {
            let f = file(&literal(hex), b"");
            let r = &f["redirection_checksum_relationship"];
            assert_eq!(r["status"], status);
            assert!(r["stored_crc32_matches_empty_data"].is_null());
            assert!(r["stored_blake2sp_matches_empty_data"].is_null());
            assert_eq!(
                f["checksum_domain"],
                "redirection_checksum_domain_unqualified"
            );
            assert_eq!(
                f["data_crc32_scope"],
                "redirection_checksum_domain_unqualified"
            );
            assert_eq!(f["data_crc32"], 0);
        }
    }

    #[test]
    fn empty_blake2sp_declaration_and_reference_algorithm_selection_are_separate() {
        let f = file(
            &literal(
                "24c3ca09340203290000010000000162050505000161220200dd0e891776933f43c7d032b08a917e25741f8aa9a12c12e1cac8801500f2ca4f",
            ),
            b"",
        );
        let r = &f["redirection_checksum_relationship"];
        assert_eq!(r["stored_blake2sp_matches_empty_data"], true);
        assert_eq!(r["reference_selected_checksum"], "BLAKE2sp");
        assert_eq!(r["crc32_comparison_status"], "not_declared");
        assert_eq!(r["source_hash_record_index"], 1);
        assert_eq!(r["source_hash_record_offset"], 38);
        assert_eq!(r["source_hash_record_end_offset"], 73);
        assert_eq!(r["source_hash_digest_offset"], 41);
        assert!(r["source_crc32_byte_offset"].is_null());
        let mut extra = literal("050505000161220200");
        extra.extend(literal(EMPTY_BLAKE2SP));
        let mut header = framed(&extra, (3, 4, 0, 0, 0));
        // Literal field position: a valid header with CRC32=1, but BLAKE2sp empty.
        header[12] = 1;
        let crc = crc32fast::hash(&header[4..]);
        header[..4].copy_from_slice(&crc.to_le_bytes());
        let f = file(&header, b"");
        let r = &f["redirection_checksum_relationship"];
        assert_eq!(r["stored_crc32_matches_empty_data"], false);
        assert_eq!(r["stored_blake2sp_matches_empty_data"], true);
        assert_eq!(r["reference_selected_checksum"], "BLAKE2sp");
        let mut bad_digest = extra.clone();
        *bad_digest.last_mut().unwrap() ^= 1;
        let f = file(&framed(&bad_digest, (3, 4, 0, 1, 0)), b"");
        let r = &f["redirection_checksum_relationship"];
        assert_eq!(r["status"], "qualified_empty_entry_domain");
        assert_eq!(r["stored_blake2sp_matches_empty_data"], false);
        assert_eq!(r["blake2sp_comparison_status"], "mismatch");
        assert_eq!(r["reference_selected_checksum"], "BLAKE2sp");
        assert_eq!(r["target_resolution_performed"], false);
        let duplicate_hash = [extra.clone(), literal("220200"), literal(EMPTY_BLAKE2SP)].concat();
        let f = file(&framed(&duplicate_hash, (3, 4, 0, 0, 0)), b"");
        let r = &f["redirection_checksum_relationship"];
        assert_eq!(r["stored_crc32_matches_empty_data"], true);
        assert!(r["stored_blake2sp_matches_empty_data"].is_null());
        assert!(r["reference_selected_checksum"].is_null());
        assert!(r["source_hash_record_offset"].is_null());
        assert_eq!(r["blake2sp_comparison_status"], "duplicate_hash_records");
    }

    #[test]
    fn reference_domain_requires_supported_entry_but_not_nonzero_unpacked_size() {
        let extra = literal("050505000161");
        for (config, status) in [
            ((11, 4, 0, 1, 0), "split_entry_scope_not_interpreted"),
            ((19, 4, 0, 1, 0), "split_entry_scope_not_interpreted"),
            ((131, 4, 0, 1, 0), "file_flags_unsupported"),
            ((3, 20, 0, 1, 0), "file_flags_unsupported"),
            ((3, 12, 0, 1, 0), "entry_sizes_unqualified"),
            ((3, 4, 1, 1, 0), "entry_sizes_unqualified"),
            ((3, 4, 0, 1, 128), "entry_compression_unsupported"),
            ((3, 4, 0, 1, 64), "entry_compression_unsupported"),
        ] {
            let f = file(&framed(&extra, config), b"");
            let r = &f["redirection_checksum_relationship"];
            assert_eq!(r["status"], status);
            assert!(r["stored_crc32_matches_empty_data"].is_null());
        }
        for size in [0, 1, u64::MAX] {
            let f = file(&framed(&extra, (3, 4, 0, size, 0)), b"");
            assert_eq!(
                f["redirection_checksum_relationship"]["stored_crc32_matches_empty_data"],
                true
            );
        }
    }

    #[test]
    fn unretained_hash_source_withholds_comparison_even_when_summary_survives() {
        let extra = [
            literal("050505000161"),
            literal("0163").repeat(63),
            literal("220200"),
            literal(EMPTY_BLAKE2SP),
        ]
        .concat();
        let f = file(&framed(&extra, (3, 4, 0, 1, 0)), b"");
        assert_eq!(f["file_extra_records"]["redirection_record_count"], 1);
        assert_eq!(f["file_extra_records"]["hash_record_count"], 1);
        assert_eq!(
            f["file_extra_records"]["unambiguous_stored_blake2sp_hex"],
            EMPTY_BLAKE2SP
        );
        let r = &f["redirection_checksum_relationship"];
        assert_eq!(
            r["blake2sp_comparison_status"],
            "hash_source_observation_unavailable"
        );
        assert!(r["stored_blake2sp_matches_empty_data"].is_null());
        assert!(r["reference_selected_checksum"].is_null());
    }

    #[test]
    fn encrypted_or_unretained_targets_withhold_checksum_comparisons() {
        let mut encryption = literal("2401000000");
        encryption.extend([0xaa; 16]);
        encryption.extend([0xbb; 16]);
        let extra = [literal("050505000161"), encryption].concat();
        let f = file(&framed(&extra, (3, 4, 0, 1, 0)), b"");
        let r = &f["redirection_checksum_relationship"];
        assert_eq!(
            r["status"],
            "empty_entry_checksum_transformation_unqualified"
        );
        assert_eq!(f["checksum_domain"], "empty_file_copy_entry_data");
        assert!(r["stored_crc32_matches_empty_data"].is_null());
        assert!(r["stored_blake2sp_matches_empty_data"].is_null());
        assert!(r["reference_selected_checksum"].is_null());
        let target = vec![b'x'; 4097];
        let mut payload = vec![5, 5, 0];
        payload.extend(vint(target.len() as u64));
        payload.extend(target);
        let mut extra = vint(payload.len() as u64);
        extra.extend(payload);
        let f = file(&framed(&extra, (3, 4, 0, 1, 0)), b"");
        assert_eq!(
            f["redirection_checksum_relationship"]["status"],
            "reference_target_name_unqualified"
        );
        assert!(
            f["redirection_checksum_relationship"]["stored_crc32_matches_empty_data"].is_null()
        );
        let late = [literal("0163").repeat(65), literal("050505000161")].concat();
        let f = file(&framed(&late, (3, 4, 0, 1, 0)), b"");
        assert_eq!(
            f["redirection_checksum_relationship"]["status"],
            "redirection_observation_unavailable"
        );
        assert!(
            f["redirection_checksum_relationship"]["stored_crc32_matches_empty_data"].is_null()
        );
    }

    #[test]
    fn complete_copy_header_survives_later_truncation_and_bad_header_crc_withholds() {
        let mut sample = literal(
            "526172211a070100c51a333203010000b72e581d15020306000401000000000000000162050505000161",
        );
        let value = serde_json::to_value(super::super::parse(&sample)).unwrap();
        let r = &value["headers"][0]["visible_block_walk"]["blocks"][1]["file_header"]["redirection_checksum_relationship"];
        assert_eq!(r["stored_crc32_matches_empty_data"], true);
        sample[16] ^= 1;
        let value = serde_json::to_value(super::super::parse(&sample)).unwrap();
        let r = &value["headers"][0]["visible_block_walk"]["blocks"][1]["file_header"]["redirection_checksum_relationship"];
        assert_eq!(r["status"], "header_checksum_unconfirmed");
        assert!(r["stored_crc32_matches_empty_data"].is_null());
        for end in 0..sample.len() {
            let _ = super::super::parse(&sample[..end]);
        }
    }
}
