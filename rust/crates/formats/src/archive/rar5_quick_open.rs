//! RAR5 Quick Open cache declarations and same-sample byte relationships.
//! https://www.rarlab.com/technote.htm#qopen
//! Original positions use UnRAR's QOHeaderPos - Offset, not locator addition.

use super::{Rar5VisibleBlock, hex, read_rar5_service_name, read_vint_with_length};
use serde::Serialize;
use sha2::{Digest, Sha256};

const WORK_LIMIT: usize = 1_048_576;
const SCAN_LIMIT: usize = 4096;
const RETAIN_LIMIT: usize = 64;
const PREVIEW_LIMIT: usize = 128;
const CACHED_HEADER_LIMIT: usize = 4096;

pub(super) struct Budget {
    work: usize,
    scanned: usize,
    retained: usize,
}

impl Default for Budget {
    fn default() -> Self {
        Self {
            work: WORK_LIMIT,
            scanned: 0,
            retained: 0,
        }
    }
}

#[derive(Debug, Serialize)]
pub struct Metadata {
    pub status: &'static str,
    pub coordinate_basis: &'static str,
    pub relationship_basis: &'static str,
    pub budget_scope: &'static str,
    pub work_byte_limit: usize,
    pub scanned_record_limit: usize,
    pub retained_record_limit: usize,
    pub record_count: usize,
    pub declared_data_fully_scanned: bool,
    pub observations_omitted: usize,
    pub observed_original_positions_strictly_increasing: Option<bool>,
    pub cache_overlap_comparison_status: &'static str,
    pub stopped_offset: Option<usize>,
    pub service_data_crc32_status: &'static str,
    pub service_data_crc32_expected: Option<u32>,
    pub service_data_crc32_calculated: Option<u32>,
    pub service_data_crc32_valid: Option<bool>,
    pub records: Vec<Record>,
}

#[derive(Debug, Serialize)]
pub struct Record {
    pub index: usize,
    pub structure_start_offset: usize,
    pub structure_end_offset: usize,
    pub structure_size_vint_byte_length: usize,
    pub structure_size_vint_hex: String,
    pub structure_crc32_expected: u32,
    pub structure_crc32_calculated: u32,
    pub structure_crc32_valid: bool,
    pub structure_sha256: String,
    pub flags: Option<u64>,
    pub backward_offset: Option<u64>,
    pub declared_cached_data_size: Option<u64>,
    pub cached_data_start_offset: Option<usize>,
    pub cached_data_end_offset: Option<usize>,
    pub cached_data_sha256: Option<String>,
    pub cached_data_preview_hex: Option<String>,
    pub cached_data_preview_truncated: Option<bool>,
    pub trailing_structure_bytes: Option<usize>,
    pub original_start_offset: Option<usize>,
    pub original_end_offset: Option<usize>,
    pub original_data_sha256: Option<String>,
    pub original_block_index: Option<usize>,
    pub original_header_coverage: Option<&'static str>,
    pub comparison_status: &'static str,
    pub cached_bytes_match_original: Option<bool>,
    pub cached_header: Option<CachedHeader>,
}

#[derive(Debug, Serialize)]
pub struct CachedHeader {
    pub status: &'static str,
    pub coordinate_basis: &'static str,
    pub interpretation_basis: &'static str,
    pub header_byte_length: Option<usize>,
    pub header_type: Option<u64>,
    pub header_flags: Option<u64>,
    pub header_crc32_valid: Option<bool>,
    pub filename: Option<String>,
    pub name_byte_length: Option<usize>,
    pub name_utf8_valid: Option<bool>,
    pub name_sha256: Option<String>,
    pub name_preview_hex: Option<String>,
    pub name_preview_truncated: Option<bool>,
}

pub(super) fn parse(
    data: &[u8],
    block: &Rar5VisibleBlock,
    previous_blocks: &[Rar5VisibleBlock],
    budget: &mut Budget,
) -> Metadata {
    let mut result = Metadata {
        status: "service_header_unavailable",
        coordinate_basis: "decoded-sample-relative; original start = QO service-header CRC32 offset minus backward offset; absolute file origin unknown",
        relationship_basis: "observed cached bytes versus declared original interval in the same sample; not archive integrity, extraction behavior, authentication or uploader identity",
        budget_scope: "all RAR5 candidates and QO services in this format-parser invocation",
        work_byte_limit: WORK_LIMIT,
        scanned_record_limit: SCAN_LIMIT,
        retained_record_limit: RETAIN_LIMIT,
        record_count: 0,
        declared_data_fully_scanned: false,
        observations_omitted: 0,
        observed_original_positions_strictly_increasing: None,
        cache_overlap_comparison_status: "not_evaluated_between_cache_records",
        stopped_offset: block.data_start_offset,
        service_data_crc32_status: "not_inspected",
        service_data_crc32_expected: None,
        service_data_crc32_calculated: None,
        service_data_crc32_valid: None,
        records: Vec::new(),
    };
    if block.header_crc32_valid != Some(true) {
        result.status = "service_header_untrusted";
        return result;
    }
    let Some(header) = &block.service_header else {
        return result;
    };
    let unsupported = if header.status != "parsed" {
        Some("service_header_incomplete")
    } else if block.header_flags.is_some_and(|flags| flags & !0x7f != 0)
        || header.file_flags.is_some_and(|flags| flags & !0x0e != 0)
    {
        Some("service_flags_unsupported")
    } else if header.split_before || header.split_after {
        Some("split_service_unsupported")
    } else if header.encryption_presence != "absent"
        || header.file_extra_records.status != "complete"
    {
        Some("service_plaintext_not_established")
    } else if header.compression_method != Some(0)
        || header.compression_info.is_some_and(|info| info & 0x40 != 0)
        || !header
            .compression_metadata
            .as_ref()
            .is_some_and(|m| m.interpretation_status == "supported")
    {
        Some("service_compression_unsupported")
    } else if header.unpacked_size_unknown != Some(false)
        || header.packed_size.is_none()
        || header.packed_size != header.unpacked_size_effective
    {
        Some("service_sizes_unqualified")
    } else {
        None
    };
    if let Some(status) = unsupported {
        result.status = status;
        return result;
    }
    let Some(start) = block.data_start_offset else {
        return result;
    };
    let Some(declared_end) = block.declared_data_end_offset else {
        return result;
    };
    let observed_end = declared_end.min(data.len());
    let Some(observed) = data.get(start..observed_end) else {
        return result;
    };
    result.service_data_crc32_expected = header.data_crc32;
    result.service_data_crc32_status = "not_declared";
    if let Some(expected) = header.data_crc32 {
        result.service_data_crc32_status = if declared_end > data.len() {
            "data_partial"
        } else if observed.len() > budget.work {
            "work_budget_exhausted"
        } else {
            budget.work -= observed.len();
            let calculated = crc32fast::hash(observed);
            result.service_data_crc32_calculated = Some(calculated);
            result.service_data_crc32_valid = Some(expected == calculated);
            if expected == calculated {
                "matched"
            } else {
                "mismatch"
            }
        };
    }
    let service_checksum_qualified =
        header.data_crc32.is_none() || result.service_data_crc32_valid == Some(true);
    let mut cursor = start;
    let mut previous_distance = None;
    result.status = if declared_end <= data.len() {
        "complete"
    } else {
        "service_data_partial"
    };
    while cursor < observed_end {
        result.stopped_offset = Some(cursor);
        if budget.scanned >= SCAN_LIMIT {
            result.status = "scan_budget_exhausted";
            break;
        }
        let Some(size_start) = cursor.checked_add(4) else {
            break;
        };
        let Some(crc_bytes) = data
            .get(cursor..size_start)
            .filter(|_| size_start <= observed_end)
        else {
            result.status = "structure_crc_truncated";
            break;
        };
        let mut fields = size_start;
        let Some((size, size_length)) = read_vint_with_length(&data[..observed_end], &mut fields)
        else {
            result.status = "structure_size_truncated";
            break;
        };
        if size_length > 3 {
            result.status = "structure_size_encoding_unsupported";
            break;
        }
        let Some(end) = usize::try_from(size)
            .ok()
            .and_then(|n| fields.checked_add(n))
        else {
            result.status = "structure_end_overflow";
            break;
        };
        if end > observed_end {
            result.status = "structure_partial";
            break;
        }
        let bounded = &data[..end];
        let flags = read_vint_with_length(bounded, &mut fields).map(|x| x.0);
        let distance = flags.and_then(|_| read_vint_with_length(bounded, &mut fields).map(|x| x.0));
        let cached_size =
            distance.and_then(|_| read_vint_with_length(bounded, &mut fields).map(|x| x.0));
        let cached_end = cached_size
            .and_then(|n| usize::try_from(n).ok())
            .and_then(|n| fields.checked_add(n))
            .filter(|n| *n <= end);
        // Reserve an upper bound for CRC/SHA scans, byte comparison, name
        // inspection and previews. This budget is shared across all candidates.
        let work = (end - cursor).checked_mul(2).and_then(|n| {
            cached_end
                .and_then(|e| (e - fields).checked_mul(12))
                .unwrap_or(0)
                .checked_add(n)
        });
        let Some(work) = work.filter(|n| *n <= budget.work) else {
            result.status = "work_budget_exhausted";
            break;
        };
        budget.work -= work;
        budget.scanned += 1;
        result.record_count += 1;
        let expected = u32::from_le_bytes(crc_bytes.try_into().expect("four-byte CRC"));
        let calculated = crc32fast::hash(&data[size_start..end]);
        let valid = expected == calculated;
        let mut record = Record {
            index: result.record_count - 1,
            structure_start_offset: cursor,
            structure_end_offset: end,
            structure_size_vint_byte_length: size_length,
            structure_size_vint_hex: hex(&data[size_start..size_start + size_length]),
            structure_crc32_expected: expected,
            structure_crc32_calculated: calculated,
            structure_crc32_valid: valid,
            structure_sha256: String::new(),
            flags,
            backward_offset: distance,
            declared_cached_data_size: cached_size,
            cached_data_start_offset: cached_end.map(|_| fields),
            cached_data_end_offset: cached_end,
            cached_data_sha256: None,
            cached_data_preview_hex: None,
            cached_data_preview_truncated: None,
            trailing_structure_bytes: cached_end.map(|e| end - e),
            original_start_offset: None,
            original_end_offset: None,
            original_data_sha256: None,
            original_block_index: None,
            original_header_coverage: None,
            comparison_status: "structure_fields_incomplete",
            cached_bytes_match_original: None,
            cached_header: None,
        };
        let retained = budget.retained < RETAIN_LIMIT;
        if retained {
            record.structure_sha256 = hex(&Sha256::digest(&data[cursor..end]));
            if let Some(e) = cached_end {
                let bytes = &data[fields..e];
                record.cached_data_sha256 = Some(hex(&Sha256::digest(bytes)));
                record.cached_data_preview_hex =
                    Some(hex(&bytes[..bytes.len().min(PREVIEW_LIMIT)]));
                record.cached_data_preview_truncated = Some(bytes.len() > PREVIEW_LIMIT);
            }
        }
        if !valid {
            record.comparison_status = "structure_checksum_invalid";
            result.status = "structure_checksum_invalid";
        } else if flags.is_some_and(|flags| flags != 0) {
            record.comparison_status = "structure_flags_unsupported";
            result.status = "structure_flags_unsupported";
        } else if let (Some(distance), Some(e)) = (distance, cached_end) {
            if let Some(previous) = previous_distance {
                let increasing = distance < previous;
                result.observed_original_positions_strictly_increasing = Some(
                    result
                        .observed_original_positions_strictly_increasing
                        .unwrap_or(true)
                        && increasing,
                );
            }
            previous_distance = Some(distance);
            let bytes = &data[fields..e];
            record.original_start_offset = usize::try_from(distance)
                .ok()
                .and_then(|n| block.header_start_offset.checked_sub(n));
            record.original_end_offset = record
                .original_start_offset
                .and_then(|s| s.checked_add(bytes.len()));
            record.comparison_status = if bytes.is_empty() {
                "empty_cached_range"
            } else if record.original_start_offset.is_none() {
                "original_precedes_sample"
            } else {
                "original_range_unavailable"
            };
            if retained {
                record.cached_header = cached_header(bytes);
                if let Some(original) = record
                    .original_start_offset
                    .zip(record.original_end_offset)
                    .and_then(|(s, e)| data.get(s..e))
                    .filter(|_| !bytes.is_empty())
                {
                    record.original_data_sha256 = Some(hex(&Sha256::digest(original)));
                    record.comparison_status = if !service_checksum_qualified {
                        "service_data_checksum_unqualified"
                    } else if record.trailing_structure_bytes != Some(0) {
                        "structure_extension_uninterpreted"
                    } else if bytes == original {
                        "matched"
                    } else {
                        "mismatch"
                    };
                    if matches!(record.comparison_status, "matched" | "mismatch") {
                        record.cached_bytes_match_original = Some(bytes == original);
                    }
                    if let Some(original_block) = previous_blocks.iter().find(|b| {
                        Some(b.header_start_offset) == record.original_start_offset
                            && b.header_crc32_valid == Some(true)
                    }) {
                        record.original_block_index = Some(original_block.index);
                        record.original_header_coverage =
                            original_block.header_end_offset.map(|end| {
                                if record.original_end_offset < Some(end) {
                                    "header_prefix"
                                } else if record.original_end_offset == Some(end) {
                                    "exact_header"
                                } else {
                                    "header_and_following_bytes"
                                }
                            });
                    }
                }
            }
        } else if cached_size.is_some() {
            record.comparison_status = "cached_data_range_invalid";
            result.status = "cached_data_range_invalid";
        }
        if retained {
            budget.retained += 1;
            result.records.push(record);
        } else {
            result.observations_omitted += 1;
        }
        cursor = end;
        result.stopped_offset = Some(cursor);
        if !valid || cached_end.is_none() {
            if result.status == "complete" {
                result.status = "structure_fields_incomplete";
            }
            break;
        }
    }
    result.declared_data_fully_scanned = cursor == declared_end;
    if result.status == "complete" && header.data_crc32.is_some() && !service_checksum_qualified {
        result.status = if result.service_data_crc32_valid == Some(false) {
            "service_data_checksum_invalid"
        } else {
            "service_data_checksum_unverified"
        };
    }
    result
}

fn cached_header(bytes: &[u8]) -> Option<CachedHeader> {
    if bytes.len() < 5 {
        return None;
    }
    let mut result = CachedHeader {
        status: "cached_header_partial",
        coordinate_basis: "cached-data-relative",
        interpretation_basis: "stored cached header/name declaration only; extra records not interpreted here; not extraction name or replacement of the original header",
        header_byte_length: None,
        header_type: None,
        header_flags: None,
        header_crc32_valid: None,
        filename: None,
        name_byte_length: None,
        name_utf8_valid: None,
        name_sha256: None,
        name_preview_hex: None,
        name_preview_truncated: None,
    };
    let mut cursor = 4;
    let (size, width) = read_vint_with_length(bytes, &mut cursor)?;
    if width > 3 {
        result.status = "cached_header_size_encoding_unsupported";
        return Some(result);
    }
    let end = usize::try_from(size)
        .ok()
        .and_then(|n| cursor.checked_add(n))?;
    result.header_byte_length = Some(end);
    if end > CACHED_HEADER_LIMIT {
        result.status = "cached_header_inspection_limit";
        return Some(result);
    }
    let Some(header) = bytes.get(..end) else {
        return Some(result);
    };
    let valid = crc32fast::hash(&header[4..]) == u32::from_le_bytes(header[..4].try_into().ok()?);
    result.header_crc32_valid = Some(valid);
    if !valid {
        result.status = "cached_header_checksum_invalid";
        return Some(result);
    }
    result.header_type = read_vint_with_length(header, &mut cursor).map(|x| x.0);
    let flags = read_vint_with_length(header, &mut cursor)?.0;
    result.header_flags = Some(flags);
    let extra = if flags & 1 != 0 {
        read_vint_with_length(header, &mut cursor)?.0
    } else {
        0
    };
    if flags & 2 != 0 {
        read_vint_with_length(header, &mut cursor)?;
    }
    let fields_end = end.checked_sub(usize::try_from(extra).ok()?)?;
    if fields_end < cursor {
        return Some(result);
    }
    if !matches!(result.header_type, Some(2 | 3)) {
        result.status = "cached_non_file_header";
        return Some(result);
    }
    let Some(name) = read_rar5_service_name(header, cursor, fields_end) else {
        return Some(result);
    };
    result.name_byte_length = Some(name.len());
    result.name_utf8_valid = Some(std::str::from_utf8(&name).is_ok());
    result.filename = std::str::from_utf8(&name).ok().map(str::to_owned);
    result.name_sha256 = Some(hex(&Sha256::digest(&name)));
    result.name_preview_hex = Some(hex(&name[..name.len().min(PREVIEW_LIMIT)]));
    result.name_preview_truncated = Some(name.len() > PREVIEW_LIMIT);
    result.status = "cached_name_observed";
    Some(result)
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

    fn vint(mut value: usize) -> Vec<u8> {
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

    fn archive(records: &[u8]) -> Vec<u8> {
        archive_with_data_crc(records, None)
    }

    fn archive_with_data_crc(records: &[u8], crc: Option<u32>) -> Vec<u8> {
        archive_with_service_fields(
            records,
            2,
            if crc.is_some() { 4 } else { 0 },
            records.len(),
            0,
            &[],
            crc,
        )
    }

    fn archive_with_service_fields(
        records: &[u8],
        flags: u8,
        file_flags: u8,
        unpacked: usize,
        compression: usize,
        extras: &[u8],
        crc: Option<u32>,
    ) -> Vec<u8> {
        let mut out =
            literal("526172211a070100fec2942c08010104000301011bb50bc71209020000000000000161");
        let mut body = vec![3, flags];
        if flags & 1 != 0 {
            body.extend(vint(extras.len()));
        }
        body.extend(vint(records.len()));
        body.push(file_flags);
        body.extend(vint(unpacked));
        body.push(0);
        if let Some(crc) = crc {
            body.extend(crc.to_le_bytes());
        }
        body.extend(vint(compression));
        body.extend([0, 2, b'Q', b'O']);
        body.extend(extras);
        let mut checked = vint(body.len());
        checked.extend(body);
        out.extend(crc32fast::hash(&checked).to_le_bytes());
        out.extend(checked);
        out.extend(records);
        out.extend(literal("19b23a3503050000"));
        out
    }

    fn metadata(data: &[u8]) -> serde_json::Value {
        let all = serde_json::to_value(super::super::parse(data)).unwrap();
        all["headers"][0]["visible_block_walk"]["blocks"][2]["quick_open_cache"].clone()
    }

    const MATCH: &str = "a49e3f0311000e0eb50bc71209020000000000000161";
    const DIFFERENT: &str = "67a54b8b11000e0e0f5ace8b09020000000000000162";

    #[test]
    fn service_trust_and_plaintext_gates_withhold_cached_interpretations() {
        let records = literal(MATCH);
        for (flags, file_flags, unpacked, compression, extras, status) in [
            (10, 0, 22, 0, &[][..], "split_service_unsupported"),
            (2, 8, 22, 0, &[][..], "service_sizes_unqualified"),
            (2, 0, 21, 0, &[][..], "service_sizes_unqualified"),
            (2, 0, 22, 128, &[][..], "service_compression_unsupported"),
            (2, 0, 22, 64, &[][..], "service_compression_unsupported"),
            (2, 1, 22, 0, &[][..], "service_flags_unsupported"),
            (
                3,
                0,
                22,
                0,
                &[1, 1][..],
                "service_plaintext_not_established",
            ),
            (
                3,
                0,
                22,
                0,
                &[2, 1][..],
                "service_plaintext_not_established",
            ),
        ] {
            let m = metadata(&archive_with_service_fields(
                &records,
                flags,
                file_flags,
                unpacked,
                compression,
                extras,
                None,
            ));
            assert_eq!(m["status"], status);
            assert_eq!(m["record_count"], 0);
            assert!(m["records"].as_array().unwrap().is_empty());
        }
        let mut data = archive(&records);
        data[35] ^= 1;
        let m = metadata(&data);
        assert_eq!(m["status"], "service_header_untrusted");
        assert_eq!(m["record_count"], 0);
    }

    #[test]
    fn malformed_record_framing_never_invents_cache_bytes() {
        for (body, status) in [
            (
                &[0x80, 0x80, 0x80, 0, 0, 0, 0][..],
                "structure_size_encoding_unsupported",
            ),
            (&[0x80][..], "structure_size_truncated"),
            (&[0][..], "structure_fields_incomplete"),
            (&[1, 0x80][..], "structure_fields_incomplete"),
            (&[2, 0, 0x80][..], "structure_fields_incomplete"),
        ] {
            let mut record = crc32fast::hash(body).to_le_bytes().to_vec();
            record.extend(body);
            let m = metadata(&archive(&record));
            assert_eq!(m["status"], status);
            for r in m["records"].as_array().unwrap() {
                assert!(r["cached_bytes_match_original"].is_null());
                assert!(r["cached_header"].is_null());
            }
        }
    }

    #[test]
    fn cached_header_names_respect_inner_crc_utf8_and_inspection_limit() {
        let header = |name: &[u8]| {
            let mut body = vec![2, 0, 0, 0, 0, 0, 0];
            body.extend(vint(name.len()));
            body.extend(name);
            let mut checked = vint(body.len());
            checked.extend(body);
            let mut bytes = crc32fast::hash(&checked).to_le_bytes().to_vec();
            bytes.extend(checked);
            bytes
        };
        let exact = header(&vec![b'a'; 4081]);
        assert_eq!(exact.len(), CACHED_HEADER_LIMIT);
        assert_eq!(
            cached_header(&exact).unwrap().status,
            "cached_name_observed"
        );
        let larger = header(&vec![b'a'; 4082]);
        let m = cached_header(&larger).unwrap();
        assert_eq!(m.status, "cached_header_inspection_limit");
        assert!(m.filename.is_none());
        let mut damaged = header(b"a");
        damaged[0] ^= 1;
        let m = cached_header(&damaged).unwrap();
        assert_eq!(m.status, "cached_header_checksum_invalid");
        assert!(m.filename.is_none());
        let m = cached_header(&header(&[0xff])).unwrap();
        assert_eq!(m.name_utf8_valid, Some(false));
        assert!(m.filename.is_none());
        assert_eq!(m.name_preview_hex.as_deref(), Some("ff"));
    }

    #[test]
    fn unverified_service_crc_preserves_work_and_scan_stop_reasons() {
        let records = literal(MATCH);
        let data = archive_with_data_crc(&records, Some(crc32fast::hash(&records)));
        let all = super::super::parse(&data);
        let super::super::FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &all.headers[0]
        else {
            panic!()
        };
        assert_eq!(walk.blocks[2].header_crc32_valid, Some(true));
        for (work, scanned, status) in [
            (0, 0, "work_budget_exhausted"),
            (0, SCAN_LIMIT, "scan_budget_exhausted"),
        ] {
            let mut budget = Budget {
                work,
                scanned,
                retained: 0,
            };
            let m = parse(&data, &walk.blocks[2], &walk.blocks[..2], &mut budget);
            assert_eq!(m.status, status);
            assert_eq!(m.service_data_crc32_valid, None);
            assert_eq!(m.service_data_crc32_status, "work_budget_exhausted");
            assert!(!m.declared_data_fully_scanned);
        }
    }

    #[test]
    fn service_crc_qualification_does_not_replace_structure_crc_or_partial_status() {
        let records = literal(MATCH);
        let expected = crc32fast::hash(&records);
        for (crc, status, valid) in [
            (expected, "complete", true),
            (expected ^ 1, "service_data_checksum_invalid", false),
        ] {
            let m = metadata(&archive_with_data_crc(&records, Some(crc)));
            assert_eq!(m["status"], status);
            assert_eq!(m["service_data_crc32_valid"], valid);
            assert_eq!(
                m["service_data_crc32_status"],
                if valid { "matched" } else { "mismatch" }
            );
            assert_eq!(m["records"][0]["structure_crc32_valid"], true);
            assert_eq!(m["declared_data_fully_scanned"], true);
            if valid {
                assert_eq!(m["records"][0]["cached_bytes_match_original"], true);
            } else {
                assert!(m["records"][0]["cached_bytes_match_original"].is_null());
                assert_eq!(
                    m["records"][0]["comparison_status"],
                    "service_data_checksum_unqualified"
                );
            }
        }
        let doubled = [records.clone(), records].concat();
        let data = archive_with_data_crc(&doubled, Some(crc32fast::hash(&doubled)));
        let m = metadata(&data[..55 + literal(MATCH).len()]);
        assert_eq!(m["status"], "service_data_partial");
        assert_eq!(m["service_data_crc32_status"], "data_partial");
        assert_eq!(m["record_count"], 1);
        assert!(m["service_data_crc32_valid"].is_null());
        assert!(m["records"][0]["cached_bytes_match_original"].is_null());
    }

    #[test]
    fn overlapping_original_ranges_and_uninterpreted_suffixes_are_explicit() {
        let mut records = literal(MATCH);
        records.extend(literal("655bce110400010161"));
        let m = metadata(&archive(&records));
        assert_eq!(m["observed_original_positions_strictly_increasing"], true);
        assert_eq!(
            m["cache_overlap_comparison_status"],
            "not_evaluated_between_cache_records"
        );
        assert_eq!(m["records"][0]["original_end_offset"], 35);
        assert_eq!(m["records"][1]["original_start_offset"], 34);
        assert_eq!(m["records"][1]["cached_bytes_match_original"], true);
        let m = metadata(&archive(&literal(
            "e65d566012000e0eb50bc712090200000000000001617f",
        )));
        assert_eq!(m["records"][0]["structure_crc32_valid"], true);
        assert_eq!(m["records"][0]["trailing_structure_bytes"], 1);
        assert_eq!(
            m["records"][0]["comparison_status"],
            "structure_extension_uninterpreted"
        );
        assert!(m["records"][0]["cached_bytes_match_original"].is_null());
    }

    #[test]
    fn differing_cached_name_is_observed_without_replacing_original_name() {
        let data = archive(&literal(DIFFERENT));
        let all = serde_json::to_value(super::super::parse(&data)).unwrap();
        let blocks = &all["headers"][0]["visible_block_walk"]["blocks"];
        assert_eq!(blocks[1]["file_header"]["filename"], "a");
        let r = &blocks[2]["quick_open_cache"]["records"][0];
        assert_eq!(r["cached_header"]["filename"], "b");
        assert_eq!(r["cached_header"]["header_crc32_valid"], true);
        assert_eq!(r["structure_crc32_valid"], true);
        assert_eq!(r["comparison_status"], "mismatch");
        assert_eq!(r["cached_bytes_match_original"], false);
        assert_ne!(r["cached_data_sha256"], r["original_data_sha256"]);
    }

    #[test]
    fn padded_size_and_field_vints_are_preserved_and_accepted() {
        for (record, width, encoded) in [
            ("7d116c219100000e0eb50bc71209020000000000000161", 2, "9100"),
            (
                "822841de918000000e0eb50bc71209020000000000000161",
                3,
                "918000",
            ),
            (
                "6929f5d01480008e008e00b50bc71209020000000000000161",
                1,
                "14",
            ),
        ] {
            let m = metadata(&archive(&literal(record)));
            assert_eq!(m["status"], "complete");
            let r = &m["records"][0];
            assert_eq!(r["structure_crc32_valid"], true);
            assert_eq!(r["structure_size_vint_byte_length"], width);
            assert_eq!(r["structure_size_vint_hex"], encoded);
            assert_eq!(r["cached_bytes_match_original"], true);
        }
    }

    #[test]
    fn partial_cached_header_bytes_can_match_without_becoming_a_filename() {
        let m = metadata(&archive(&literal("655bce110400010161")));
        let r = &m["records"][0];
        assert_eq!(r["original_start_offset"], 34);
        assert_eq!(r["original_end_offset"], 35);
        assert_eq!(r["cached_data_preview_hex"], "61");
        assert_eq!(r["cached_bytes_match_original"], true);
        assert!(r["cached_header"].is_null());
        assert!(r["original_block_index"].is_null());
    }

    #[test]
    fn empty_unsupported_and_before_sample_ranges_withhold_comparison() {
        for (record, status) in [
            ("7c5d72ad03000e00", "empty_cached_range"),
            (
                "e755998411010e0eb50bc71209020000000000000161",
                "structure_flags_unsupported",
            ),
            (
                "f55fb3e01100240eb50bc71209020000000000000161",
                "original_precedes_sample",
            ),
            (
                "4c45c4ba11000e0fb50bc71209020000000000000161",
                "cached_data_range_invalid",
            ),
        ] {
            let m = metadata(&archive(&literal(record)));
            let r = &m["records"][0];
            assert_eq!(r["structure_crc32_valid"], true);
            assert_eq!(r["comparison_status"], status);
            assert!(r["cached_bytes_match_original"].is_null());
        }
    }

    #[test]
    fn damaged_structure_keeps_raw_evidence_and_stops_before_later_records() {
        let mut record = literal(MATCH);
        record[0] ^= 1;
        record.extend(literal(MATCH));
        let m = metadata(&archive(&record));
        assert_eq!(m["status"], "structure_checksum_invalid");
        assert_eq!(m["record_count"], 1);
        assert_eq!(m["declared_data_fully_scanned"], false);
        let r = &m["records"][0];
        assert_eq!(r["structure_crc32_valid"], false);
        assert!(r["cached_bytes_match_original"].is_null());
        assert!(r["cached_header"].is_null());
        assert_eq!(r["cached_data_preview_hex"], "b50bc71209020000000000000161");
    }

    #[test]
    fn complete_prefix_records_survive_partial_qo_data_without_complete_coverage_claim() {
        let mut records = literal(MATCH);
        records.extend(literal(DIFFERENT));
        let full = archive(&records);
        let first_end = 51 + literal(MATCH).len();
        let m = metadata(&full[..first_end]);
        assert_eq!(m["status"], "service_data_partial");
        assert_eq!(m["declared_data_fully_scanned"], false);
        assert_eq!(m["record_count"], 1);
        assert_eq!(m["records"][0]["cached_bytes_match_original"], true);
        for end in 0..full.len() {
            let _ = super::super::parse(&full[..end]);
        }
    }

    #[test]
    fn repeated_original_offsets_are_flagged_and_retention_is_global() {
        let records = literal(MATCH).repeat(RETAIN_LIMIT + 2);
        let data = archive(&records);
        let m = metadata(&data);
        assert_eq!(m["record_count"], RETAIN_LIMIT + 2);
        assert_eq!(m["records"].as_array().unwrap().len(), RETAIN_LIMIT);
        assert_eq!(m["observations_omitted"], 2);
        assert_eq!(m["observed_original_positions_strictly_increasing"], false);
        let doubled = [data.clone(), data].concat();
        let all = serde_json::to_value(super::super::parse(&doubled)).unwrap();
        let retained: usize = all["headers"]
            .as_array()
            .unwrap()
            .iter()
            .map(|h| {
                h["visible_block_walk"]["blocks"]
                    .as_array()
                    .unwrap()
                    .iter()
                    .map(|b| {
                        b["quick_open_cache"]["records"]
                            .as_array()
                            .map_or(0, Vec::len)
                    })
                    .sum::<usize>()
            })
            .sum();
        assert_eq!(retained, RETAIN_LIMIT);
    }

    #[test]
    fn work_and_scan_limits_stop_without_skipping_unexamined_ranges() {
        let data = archive(&literal(MATCH));
        let all = super::super::parse(&data);
        let super::super::FormatHeader::Rar5 {
            visible_block_walk: Some(walk),
            ..
        } = &all.headers[0]
        else {
            panic!()
        };
        for (work, scanned, status) in [
            (0, 0, "work_budget_exhausted"),
            (WORK_LIMIT, SCAN_LIMIT, "scan_budget_exhausted"),
        ] {
            let mut budget = Budget {
                work,
                scanned,
                retained: 0,
            };
            let m = parse(&data, &walk.blocks[2], &walk.blocks[..2], &mut budget);
            assert_eq!(m.status, status);
            assert_eq!(m.record_count, 0);
            assert!(!m.declared_data_fully_scanned);
            assert!(m.records.is_empty());
        }
    }
}
