//! Sample-relative XZ first-block declarations, not decompression or stream validation.
//! XZ format 1.2.1 sections 1.2, 2.1, 3 and liblzma's 63-bit unpadded-size bound.
use serde::Serialize;

const UNPADDED_MAX: u64 = (1_u64 << 63) - 4;

#[derive(Debug, Serialize)]
pub struct Integer {
    pub start_offset: usize,
    pub end_offset_exclusive: usize,
    pub raw_hex: String,
    pub value: u64,
}

#[derive(Debug, Serialize)]
pub struct Filter {
    pub id: Integer,
    pub properties_size: Option<Integer>,
    pub properties_start_offset: Option<usize>,
    pub properties_end_offset_exclusive: Option<usize>,
    pub properties_observed_hex: String,
    pub properties_status: &'static str,
}

#[derive(Debug, Serialize)]
pub struct Range {
    pub start_offset: u64,
    pub end_offset_exclusive: u64,
    pub declared_bytes: u64,
    pub available_bytes: usize,
    pub complete: bool,
}

/// Only an immediately following one-record Index; never a search for a stream end.
#[derive(Debug, Default, Serialize)]
pub struct IndexObservation {
    pub locator_basis: &'static str,
    pub offset_basis: &'static str,
    pub start_offset: u64,
    pub first_byte: Option<u8>,
    pub status: &'static str,
    pub candidate_preview_hex: String,
    pub index_observed_hex: String,
    pub record_count: Option<Integer>,
    pub record_unpadded_size: Option<Integer>,
    pub record_uncompressed_size: Option<Integer>,
    pub unpadded_size_domain_valid: Option<bool>,
    pub declared_single_record_stream_bytes: Option<u64>,
    pub declared_stream_size_limit_valid: Option<bool>,
    pub padding: Option<Range>,
    pub padding_zero: Option<bool>,
    pub crc32_offset: Option<u64>,
    pub end_offset_exclusive: Option<u64>,
    pub crc32_expected: Option<u32>,
    pub crc32_calculated: Option<u32>,
    pub crc32_valid: Option<bool>,
    pub unpadded_size_relationship: &'static str,
    pub uncompressed_size_relationship: &'static str,
    pub relationship_basis: &'static str,
}

#[derive(Debug, Default, Serialize)]
pub struct FirstBlock {
    pub offset_basis: &'static str,
    pub file_offset_known: bool,
    pub whole_stream_validated: bool,
    pub content_decompressed: bool,
    pub filter_properties_semantically_validated: bool,
    pub stream_header_qualified: bool,
    pub header_start_offset: Option<usize>,
    pub first_byte: Option<u8>,
    pub header_size: Option<usize>,
    pub header_observed_hex: String,
    pub header_status: &'static str,
    pub framing_status: &'static str,
    pub flags: Option<u8>,
    pub reserved_flag_bits_valid: Option<bool>,
    pub declared_filter_count: Option<u8>,
    pub header_crc32_expected: Option<u32>,
    pub header_crc32_calculated: Option<u32>,
    pub header_crc32_valid: Option<bool>,
    pub compressed_size: Option<Integer>,
    pub compressed_size_status: &'static str,
    pub uncompressed_size: Option<Integer>,
    pub uncompressed_size_status: &'static str,
    pub filters: Vec<Filter>,
    pub header_padding: Option<Range>,
    pub header_padding_zero: Option<bool>,
    pub check_id: Option<u8>,
    pub check_size_bytes: Option<u32>,
    pub check_algorithm_status: &'static str,
    pub check_verification_status: &'static str,
    pub extent_status: &'static str,
    pub extent_basis: &'static str,
    pub unpadded_block_bytes: Option<u64>,
    pub padded_block_bytes: Option<u64>,
    pub minimum_stream_size_lower_bound_fits: Option<bool>,
    pub compressed_data: Option<Range>,
    pub block_padding: Option<Range>,
    pub block_padding_zero: Option<bool>,
    pub check: Option<Range>,
    pub check_observed_hex: String,
    pub next_item_offset: Option<u64>,
    pub following_byte: Option<u8>,
    pub following_index: Option<IndexObservation>,
}

fn hex(bytes: &[u8]) -> String {
    const DIGITS: &[u8; 16] = b"0123456789abcdef";
    let mut result = String::with_capacity(bytes.len() * 2);
    for byte in bytes {
        result.push(DIGITS[(byte >> 4) as usize] as char);
        result.push(DIGITS[(byte & 15) as usize] as char);
    }
    result
}

fn range(data: &[u8], start: u64, end: u64) -> Range {
    let available = (data.len() as u64).min(end).saturating_sub(start);
    Range {
        start_offset: start,
        end_offset_exclusive: end,
        declared_bytes: end - start,
        available_bytes: available as usize,
        complete: available == end - start && start <= data.len() as u64,
    }
}

fn observed<'a>(data: &'a [u8], range: &Range) -> &'a [u8] {
    // Conversion follows the sample bound, not the potentially enormous declaration.
    if range.start_offset > data.len() as u64 {
        return &[];
    }
    let start = range.start_offset as usize;
    &data[start..start + range.available_bytes]
}

fn zero_observation(data: &[u8], range: &Range) -> Option<bool> {
    if observed(data, range).iter().any(|byte| *byte != 0) {
        Some(false)
    } else {
        range.complete.then_some(true)
    }
}

fn vli(header: &[u8], cursor: &mut usize, body_end: usize, base: usize) -> Option<Integer> {
    let start = *cursor;
    let mut value = 0_u64;
    for index in 0..9 {
        let byte = *header.get(*cursor).filter(|_| *cursor < body_end)?;
        *cursor += 1;
        if index > 0 && byte == 0 {
            return None; // Nonminimal encoding, including 80 00.
        }
        value |= u64::from(byte & 0x7f) << (index * 7);
        if byte & 0x80 == 0 {
            return Some(Integer {
                start_offset: base + start,
                end_offset_exclusive: base + *cursor,
                raw_hex: hex(&header[start..*cursor]),
                value,
            });
        }
    }
    None // A continuing ninth byte cannot fit an XZ VLI.
}

fn index_integer(data: &[u8], cursor: &mut usize, base: usize) -> Result<Integer, &'static str> {
    let start = *cursor;
    if start == data.len() {
        return Err("sample_truncated_vli");
    }
    vli(data, cursor, data.len(), base).ok_or_else(|| {
        if *cursor == data.len() && *cursor - start < 9 && data[*cursor - 1] & 0x80 != 0 {
            "sample_truncated_vli"
        } else {
            "invalid_vli"
        }
    })
}

fn observe_index(data: &[u8], block: &FirstBlock, start: u64) -> IndexObservation {
    let mut out = IndexObservation {
        locator_basis: "first_block_declared_end; no forward search",
        offset_basis: "decoded_parser_sample_relative; file origin is unknown",
        start_offset: start,
        status: "not_sampled",
        unpadded_size_relationship: "unqualified",
        uncompressed_size_relationship: "unqualified",
        relationship_basis: "Checksummed declarations only: Index unpadded size versus Header+Compressed+Check (excluding Block Padding), and Index uncompressed size versus header declaration. Declared single-record stream bytes use rounded record size + observed Index length + 24 bytes for Stream Header/Footer. No decompression, stream/footer validation or actual decoded-size verification.",
        ..IndexObservation::default()
    };
    // At most 24 bytes: indicator1 + count1 + two canonical VLIs<=9 each + padding + CRC4.
    // Prove sample coverage before converting potentially huge declared offsets to usize.
    if start >= data.len() as u64 {
        return out;
    }
    let start = start as usize;
    let candidate = &data[start..start + (data.len() - start).min(24)];
    out.candidate_preview_hex = hex(candidate);
    out.first_byte = candidate.first().copied();
    if out.first_byte != Some(0) {
        out.status = "next_item_not_index";
        return out;
    }
    let mut cursor = 1;
    match index_integer(candidate, &mut cursor, start) {
        Ok(value) => out.record_count = Some(value),
        Err(status) => {
            out.status = status;
            return out;
        }
    }
    if out
        .record_count
        .as_ref()
        .is_none_or(|value| value.value != 1)
    {
        out.status = "unsupported_record_count";
        return out;
    }
    match index_integer(candidate, &mut cursor, start) {
        Ok(value) => {
            out.unpadded_size_domain_valid = Some((5..=UNPADDED_MAX).contains(&value.value));
            out.record_unpadded_size = Some(value);
        }
        Err(status) => {
            out.status = status;
            return out;
        }
    }
    match index_integer(candidate, &mut cursor, start) {
        Ok(value) => out.record_uncompressed_size = Some(value),
        Err(status) => {
            out.status = status;
            return out;
        }
    }
    let crc_start = (cursor + 3) & !3; // Index-relative alignment, even in embedded samples.
    let index_end = crc_start + 4;
    out.declared_single_record_stream_bytes = out
        .record_unpadded_size
        .as_ref()
        .and_then(|v| v.value.checked_add(3))
        .map(|value| value & !3)
        .and_then(|padded| padded.checked_add(index_end as u64 + 24));
    out.declared_stream_size_limit_valid = out
        .declared_single_record_stream_bytes
        .map(|bytes| bytes < 1_u64 << 63);
    let Some(end_offset) = out.start_offset.checked_add(index_end as u64) else {
        out.status = "offset_overflow";
        return out;
    };
    let crc_offset = end_offset - 4;
    out.crc32_offset = Some(crc_offset);
    out.end_offset_exclusive = Some(end_offset);
    let padding = range(data, out.start_offset + cursor as u64, crc_offset);
    out.padding_zero = zero_observation(data, &padding);
    out.padding = Some(padding);
    out.index_observed_hex = hex(&candidate[..candidate.len().min(index_end)]);
    let Some(complete) = candidate.get(..index_end) else {
        out.status = "sample_truncated_index";
        return out;
    };
    let expected = u32::from_le_bytes(complete[crc_start..].try_into().expect("four CRC bytes"));
    let calculated = crc32fast::hash(&complete[..crc_start]);
    out.crc32_expected = Some(expected);
    out.crc32_calculated = Some(calculated);
    out.crc32_valid = Some(expected == calculated);
    out.status = if expected != calculated {
        "crc32_mismatch"
    } else if out.padding_zero != Some(true) {
        "nonzero_index_padding"
    } else if out.unpadded_size_domain_valid != Some(true) {
        "invalid_unpadded_size_domain"
    } else if out.declared_stream_size_limit_valid != Some(true) {
        "invalid_declared_stream_size"
    } else {
        "complete"
    };
    if out.status == "complete" {
        out.unpadded_size_relationship =
            if out.record_unpadded_size.as_ref().map(|v| v.value) == block.unpadded_block_bytes {
                "equal"
            } else {
                "different"
            };
        out.uncompressed_size_relationship = match block.uncompressed_size.as_ref() {
            None => "header_not_declared",
            Some(header)
                if out
                    .record_uncompressed_size
                    .as_ref()
                    .is_some_and(|v| v.value == header.value) =>
            {
                "equal"
            }
            Some(_) => "different",
        };
    }
    out
}

pub fn observe(
    data: &[u8],
    candidate: usize,
    stream_valid: bool,
    check_id: Option<u8>,
) -> FirstBlock {
    let check_id = check_id.filter(|id| *id <= 15);
    let mut out = FirstBlock {
        offset_basis: "decoded_parser_sample_relative; candidate signature is not proof of file origin",
        stream_header_qualified: stream_valid,
        header_status: "sample_truncated",
        framing_status: "unavailable",
        compressed_size_status: "unavailable",
        uncompressed_size_status: "unavailable",
        check_id,
        check_size_bytes: check_id.map(|id| if id == 0 { 0 } else { 4 << ((id - 1) / 3) }),
        check_algorithm_status: match check_id {
            Some(0) => "none",
            Some(1 | 4 | 10) => "specified_algorithm_not_verified",
            Some(_) => "reserved_algorithm",
            None => "unavailable",
        },
        check_verification_status: if check_id == Some(0) {
            "not_present"
        } else {
            "not_performed_without_decompression"
        },
        extent_status: "unqualified_header",
        extent_basis: "Conditional declared first-block ranges only; no decompression, content-check verification, stream end or whole-file validation. Minimum stream-size test uses only a 32-byte overhead lower bound.",
        ..FirstBlock::default()
    };
    let Some(start) = candidate.checked_add(12) else {
        out.extent_status = "offset_overflow";
        return out;
    };
    out.header_start_offset = Some(start);
    let Some(&first) = data.get(start) else {
        return out;
    };
    out.first_byte = Some(first);
    if first == 0 {
        out.header_status = "index_indicator_observed";
        out.header_observed_hex = "00".into();
        return out;
    }
    let size = (usize::from(first) + 1) * 4; // 8..1024, never attacker-sized allocation.
    out.header_size = Some(size);
    let available = size.min(data.len() - start);
    out.header_observed_hex = hex(&data[start..start + available]);
    let Some(end) = start.checked_add(size) else {
        return out;
    };
    let Some(header) = data.get(start..end) else {
        return out;
    };
    out.header_status = "complete";
    let body_end = size - 4;
    let expected = u32::from_le_bytes(header[body_end..].try_into().expect("four CRC bytes"));
    let calculated = crc32fast::hash(&header[..body_end]);
    out.header_crc32_expected = Some(expected);
    out.header_crc32_calculated = Some(calculated);
    out.header_crc32_valid = Some(expected == calculated);
    let flags = header[1];
    out.flags = Some(flags);
    out.reserved_flag_bits_valid = Some(flags & 0x3c == 0);
    out.declared_filter_count = Some((flags & 3) + 1);
    if flags & 0x3c != 0 {
        out.framing_status = "unsupported_block_flags";
        return out;
    }
    let mut cursor = 2;
    out.compressed_size_status = if flags & 0x40 == 0 {
        "not_declared"
    } else {
        "invalid_vli"
    };
    if flags & 0x40 != 0 {
        out.compressed_size = vli(header, &mut cursor, body_end, start);
        if out.compressed_size.is_none() {
            out.framing_status = "invalid_compressed_size_vli";
            return out;
        }
        out.compressed_size_status = if out.compressed_size.as_ref().is_some_and(|v| v.value == 0) {
            "invalid_zero"
        } else {
            "declared"
        };
    }
    out.uncompressed_size_status = if flags & 0x80 == 0 {
        "not_declared"
    } else {
        "invalid_vli"
    };
    if flags & 0x80 != 0 {
        out.uncompressed_size = vli(header, &mut cursor, body_end, start);
        if out.uncompressed_size.is_none() {
            out.framing_status = "invalid_uncompressed_size_vli";
            return out;
        }
        out.uncompressed_size_status = "declared";
    }
    for _ in 0..(flags & 3) + 1 {
        let Some(id) = vli(header, &mut cursor, body_end, start) else {
            out.framing_status = "invalid_filter_id_vli";
            return out;
        };
        let forbidden = id.value >= 1_u64 << 62;
        let mut filter = Filter {
            id,
            properties_size: None,
            properties_start_offset: None,
            properties_end_offset_exclusive: None,
            properties_observed_hex: String::new(),
            properties_status: "unavailable",
        };
        if forbidden {
            filter.properties_status = "forbidden_internal_filter_id";
            out.filters.push(filter);
            out.framing_status = "forbidden_internal_filter_id";
            return out;
        }
        filter.properties_size = vli(header, &mut cursor, body_end, start);
        let Some(property_size) = filter.properties_size.as_ref() else {
            out.filters.push(filter);
            out.framing_status = "invalid_filter_properties_size_vli";
            return out;
        };
        filter.properties_start_offset = Some(start + cursor);
        let remaining = body_end - cursor;
        let count = property_size.value.min(remaining as u64) as usize;
        filter.properties_observed_hex = hex(&header[cursor..cursor + count]);
        if property_size.value > remaining as u64 {
            filter.properties_status = "exceeds_header_body";
            out.filters.push(filter);
            out.framing_status = "filter_properties_exceed_header_body";
            return out;
        }
        cursor += count;
        filter.properties_end_offset_exclusive = Some(start + cursor);
        filter.properties_status = "observed_not_semantically_validated";
        out.filters.push(filter);
    }
    let padding = range(data, (start + cursor) as u64, (start + body_end) as u64);
    out.header_padding_zero = zero_observation(data, &padding);
    out.header_padding = Some(padding);
    out.framing_status = if out.header_padding_zero == Some(true) {
        "complete"
    } else {
        "nonzero_header_padding"
    };
    if !stream_valid
        || expected != calculated
        || out.framing_status != "complete"
        || out.compressed_size_status == "invalid_zero"
    {
        return out;
    }
    let Some(compressed) = out.compressed_size.as_ref() else {
        out.extent_status = "compressed_size_not_declared";
        return out;
    };
    let Some(check_size) = out.check_size_bytes else {
        return out;
    };
    let Some(unpadded) = (size as u64)
        .checked_add(compressed.value)
        .and_then(|n| n.checked_add(u64::from(check_size)))
        .filter(|n| *n <= UNPADDED_MAX)
    else {
        out.extent_status = "invalid_unpadded_block_size";
        return out;
    };
    let padded = (unpadded + 3) & !3;
    out.unpadded_block_bytes = Some(unpadded);
    out.padded_block_bytes = Some(padded);
    out.minimum_stream_size_lower_bound_fits = Some(padded + 32 < 1_u64 << 63);
    let payload_start = end as u64;
    let geometry = payload_start
        .checked_add(compressed.value)
        .and_then(|payload_end| {
            let padding_end = payload_end.checked_add((4 - compressed.value % 4) % 4)?;
            Some((
                payload_end,
                padding_end,
                padding_end.checked_add(u64::from(check_size))?,
            ))
        });
    let Some((payload_end, padding_end, check_end)) = geometry else {
        out.extent_status = "offset_overflow";
        return out;
    };
    out.extent_status = "declared_block_ranges";
    out.compressed_data = Some(range(data, payload_start, payload_end));
    let padding = range(data, payload_end, padding_end);
    out.block_padding_zero = zero_observation(data, &padding);
    out.block_padding = Some(padding);
    let check = range(data, padding_end, check_end);
    out.check_observed_hex = hex(observed(data, &check));
    out.check = Some(check);
    out.next_item_offset = Some(check_end);
    out.following_index = Some(observe_index(data, &out, check_end));
    out.following_byte = usize::try_from(check_end)
        .ok()
        .and_then(|offset| data.get(offset).copied());
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn bytes(value: &str) -> Vec<u8> {
        value
            .as_bytes()
            .as_chunks::<2>()
            .0
            .iter()
            .map(|p| u8::from_str_radix(std::str::from_utf8(p).unwrap(), 16).unwrap())
            .collect()
    }
    fn sample(header: &str) -> Vec<u8> {
        let mut data = bytes("fd377a585a000000ff12d941");
        data.extend(bytes(header));
        data
    }
    fn block(header: &str) -> FirstBlock {
        observe(&sample(header), 0, true, Some(0))
    }

    #[test]
    fn literal_grammar_conflicts_retain_declarations_without_ranges() {
        for (header, framing, compressed_status) in [
            ("02c0000021010000f3e556f2", "complete", "invalid_zero"),
            (
                "02408100210100007cc8acaf",
                "invalid_compressed_size_vli",
                "invalid_vli",
            ),
            (
                "02c4010021010000451245cd",
                "unsupported_block_flags",
                "unavailable",
            ),
            (
                "02c0010021010001c0060d4e",
                "nonzero_header_padding",
                "declared",
            ),
            (
                "02c0010021040000bdf4c13f",
                "filter_properties_exceed_header_body",
                "declared",
            ),
            (
                "044081808080808080808021010000003c1e915a",
                "invalid_compressed_size_vli",
                "invalid_vli",
            ),
            (
                "0440018080808080808080400000000046c80cc5",
                "forbidden_internal_filter_id",
                "declared",
            ),
        ] {
            let out = block(header);
            assert_eq!(out.header_crc32_valid, Some(true), "{header}");
            assert_eq!(out.framing_status, framing, "{header}");
            assert_eq!(out.compressed_size_status, compressed_status, "{header}");
            assert!(out.compressed_data.is_none(), "{header}");
            assert!(!out.whole_stream_validated);
        }
    }

    #[test]
    fn distinguishes_unsupported_filter_semantics_from_bounded_framing() {
        let out = block("02c001007f000000f0241a13");
        assert_eq!(out.framing_status, "complete");
        assert_eq!(out.filters[0].id.value, 127);
        assert_eq!(out.extent_status, "declared_block_ranges");
        assert!(!out.filter_properties_semantically_validated);
        // A framed LZMA2 ID with zero property bytes is not a valid LZMA2 setup.
        let mut header = bytes("0200210000000000");
        let crc = crc32fast::hash(&header);
        header.extend(crc.to_le_bytes());
        let mut data = bytes("fd377a585a000000ff12d941");
        data.extend(header);
        let out = observe(&data, 0, true, Some(0));
        assert_eq!(out.framing_status, "complete");
        assert!(!out.filter_properties_semantically_validated);
    }

    #[test]
    fn absent_size_is_not_the_liblzma_unknown_sentinel_or_zero() {
        let out = block("028000210100000037530ceb");
        assert_eq!(out.header_crc32_valid, Some(true));
        assert_eq!(out.framing_status, "complete");
        assert_eq!(out.uncompressed_size.unwrap().value, 0);
        assert_eq!(out.compressed_size_status, "not_declared");
        assert!(out.compressed_size.is_none());
        assert_eq!(out.extent_status, "compressed_size_not_declared");
        assert!(out.check.is_none());
        assert!(out.next_item_offset.is_none());
    }

    #[test]
    fn enforces_unpadded_domain_independently_of_raw_vli_and_sample_coverage() {
        let maximum = block("0440ffffffffffffffff7f2101000000dbb3297e");
        assert_eq!(maximum.header_crc32_valid, Some(true));
        assert_eq!(maximum.compressed_size.unwrap().value, (1_u64 << 63) - 1);
        assert_eq!(maximum.extent_status, "invalid_unpadded_block_size");
        assert!(maximum.compressed_data.is_none());
        let boundary = block("0440e8ffffffffffffff7f21010000007725567d");
        assert_eq!(boundary.header_crc32_valid, Some(true));
        assert_eq!(boundary.unpadded_block_bytes, Some(UNPADDED_MAX));
        assert_eq!(boundary.next_item_offset, Some(9_223_372_036_854_775_816));
        assert_eq!(boundary.minimum_stream_size_lower_bound_fits, Some(false));
        assert_eq!(boundary.compressed_data.unwrap().available_bytes, 0);
        let beyond = block("0440e9ffffffffffffff7f210100000001c459e0");
        assert_eq!(beyond.header_crc32_valid, Some(true));
        assert_eq!(beyond.extent_status, "invalid_unpadded_block_size");
    }

    #[test]
    fn all_check_widths_include_reserved_algorithms_without_verifying_content() {
        let widths = [0, 4, 4, 4, 8, 8, 8, 16, 16, 16, 32, 32, 32, 64, 64, 64];
        let mut data = sample("02c0050121010000f05dfb9f");
        data.extend([0; 80]);
        for id in 0..16 {
            let out = observe(&data, 0, true, Some(id));
            assert_eq!(out.check_size_bytes, Some(widths[id as usize]));
            assert_eq!(out.check.as_ref().unwrap().start_offset, 32);
            assert_eq!(
                out.check.unwrap().end_offset_exclusive,
                32 + u64::from(widths[id as usize])
            );
            assert_eq!(
                out.check_verification_status,
                if id == 0 {
                    "not_present"
                } else {
                    "not_performed_without_decompression"
                }
            );
            assert!(!out.content_decompressed);
        }
    }

    #[test]
    fn payload_padding_and_check_coverage_are_independent() {
        let mut data = sample("02c001002101000056360a39");
        data.extend([0; 8]);
        for length in [24, 25, 27, 28, 31, 32] {
            let out = observe(&data[..length], 0, true, Some(1));
            assert_eq!(out.header_crc32_valid, Some(true));
            assert_eq!(out.compressed_data.as_ref().unwrap().complete, length >= 25);
            assert_eq!(out.block_padding.as_ref().unwrap().complete, length >= 28);
            assert_eq!(out.check.as_ref().unwrap().complete, length >= 32);
            assert_eq!(
                out.block_padding_zero,
                if length >= 28 { Some(true) } else { None }
            );
        }
        data[26] = 1;
        let out = observe(&data, 0, true, Some(1));
        assert_eq!(out.block_padding_zero, Some(false));
        assert_eq!(out.extent_status, "declared_block_ranges");
        assert_eq!(out.header_crc32_valid, Some(true));
        let out = observe(&data[..27], 0, true, Some(1));
        assert_eq!(out.block_padding_zero, Some(false));
    }

    #[test]
    fn invalid_stream_or_block_crc_withholds_ranges_but_retains_raw_block_sizes() {
        let data = sample("02c0050121010000f05dfb9f");
        let out = observe(&data, 0, false, Some(0));
        assert_eq!(out.header_crc32_valid, Some(true));
        assert_eq!(out.compressed_size.unwrap().value, 5);
        assert!(out.compressed_data.is_none());
        let mut bad = data;
        bad[20] ^= 1;
        let out = observe(&bad, 0, true, Some(0));
        assert_eq!(out.header_crc32_valid, Some(false));
        assert_eq!(out.compressed_size.unwrap().value, 5);
        assert!(out.compressed_data.is_none());
    }

    #[test]
    fn every_prefix_and_embedded_offset_preserves_sample_provenance() {
        let mut data = sample("02c0050121010000f05dfb9f");
        data.extend([0; 8]);
        for length in 0..=data.len() {
            let out = observe(&data[..length], 0, length >= 12, Some(0));
            assert_eq!(
                out.header_status,
                if length < 24 {
                    "sample_truncated"
                } else {
                    "complete"
                }
            );
            assert!(!out.file_offset_known);
            assert!(out.header_observed_hex.len() <= 2048);
        }
        let mut prefixed = vec![42; 7];
        prefixed.extend(data);
        let out = observe(&prefixed, 7, true, Some(0));
        assert_eq!(out.header_start_offset, Some(19));
        assert_eq!(out.compressed_size.unwrap().start_offset, 21);
        assert_eq!(out.compressed_data.unwrap().start_offset, 31);
        assert_eq!(out.next_item_offset, Some(39));
        let out = observe(&prefixed, usize::MAX, true, None);
        assert_eq!(out.extent_status, "offset_overflow");
    }

    #[test]
    fn maximum_header_and_index_indicator_have_bounded_distinct_states() {
        let mut header = bytes("ffc00100210100");
        header.resize(1020, 0);
        assert_eq!(crc32fast::hash(&header), 0xd3270872);
        header.extend(0xd3270872_u32.to_le_bytes());
        let mut data = bytes("fd377a585a000000ff12d941");
        data.extend(header);
        let out = observe(&data, 0, true, Some(0));
        assert_eq!(out.header_size, Some(1024));
        assert_eq!(out.framing_status, "complete");
        assert_eq!(out.header_observed_hex.len(), 2048);
        assert_eq!(
            observe(&data[..data.len() - 1], 0, true, Some(0)).header_status,
            "sample_truncated"
        );
        let mut index = bytes("fd377a585a000000ff12d941");
        index.push(0);
        let out = observe(&index, 0, true, Some(0));
        assert_eq!(out.header_status, "index_indicator_observed");
        assert!(out.header_size.is_none());
        assert!(out.compressed_data.is_none());
        assert!(!out.whole_stream_validated);
    }

    #[test]
    fn huge_property_declarations_do_not_allocate_or_read_beyond_header_crc() {
        let mut header = bytes("ff007fffffffffffffffff7f");
        header.resize(1020, 0);
        header.extend(crc32fast::hash(&header).to_le_bytes());
        let mut data = bytes("fd377a585a000000ff12d941");
        data.extend(header);
        let out = observe(&data, 0, true, Some(0));
        assert_eq!(out.header_crc32_valid, Some(true));
        assert_eq!(out.framing_status, "filter_properties_exceed_header_body");
        assert_eq!(out.filters.len(), 1);
        assert_eq!(
            out.filters[0].properties_size.as_ref().unwrap().value,
            (1_u64 << 63) - 1
        );
        assert!(out.filters[0].properties_observed_hex.len() <= 2048);
        assert!(out.filters[0].properties_end_offset_exclusive.is_none());
        assert!(out.compressed_data.is_none());
    }

    #[test]
    fn every_declared_filter_is_walked_without_claiming_chain_validity() {
        let mut header = bytes("03037f007f007f007f000000");
        header.extend(crc32fast::hash(&header).to_le_bytes());
        let mut data = bytes("fd377a585a000000ff12d941");
        data.extend(header);
        let out = observe(&data, 0, true, Some(0));
        assert_eq!(out.framing_status, "complete");
        assert_eq!(out.declared_filter_count, Some(4));
        assert_eq!(out.filters.len(), 4);
        assert!(!out.filter_properties_semantically_validated);
        assert!(!out.whole_stream_validated);
    }
    #[test]
    fn minimum_header_and_largest_external_filter_id_are_bounded_framing() {
        for (prefix, expected_id, size) in [
            ("01007f00", 127, 8),
            ("044001ffffffffffffffff3f00000000", (1_u64 << 62) - 1, 20),
        ] {
            let mut header = bytes(prefix);
            header.extend(crc32fast::hash(&header).to_le_bytes());
            let mut data = bytes("fd377a585a000000ff12d941");
            data.extend(header);
            let out = observe(&data, 0, true, Some(0));
            assert_eq!(out.header_size, Some(size));
            assert_eq!(out.header_crc32_valid, Some(true));
            assert_eq!(out.framing_status, "complete");
            assert_eq!(out.filters[0].id.value, expected_id);
            assert!(!out.filter_properties_semantically_validated);
            assert!(
                observe(&data, 0, true, Some(255))
                    .check_size_bytes
                    .is_none()
            );
        }
    }
    #[test]
    fn following_index_compares_checksummed_declarations_without_decoding() {
        let data = bytes(
            "fd377a585a000000ff12d94102c0050121010000f05dfb9f010000410000000000011101ada65804",
        );
        let out = serde_json::to_value(observe(&data, 0, true, Some(0))).unwrap();
        let index = &out["following_index"];
        assert_eq!(index["status"], "complete");
        assert_eq!(index["crc32_valid"], true);
        assert_eq!(index["start_offset"], 32);
        assert_eq!(index["end_offset_exclusive"], 40);
        assert_eq!(index["record_unpadded_size"]["value"], 17);
        assert_eq!(index["record_uncompressed_size"]["value"], 1);
        assert_eq!(index["unpadded_size_relationship"], "equal");
        assert_eq!(index["uncompressed_size_relationship"], "equal");
        assert_eq!(out["whole_stream_validated"], false);
        assert_eq!(out["content_decompressed"], false);
    }
    fn with_index(index: &str) -> Vec<u8> {
        let mut data = bytes("fd377a585a000000ff12d94102c0050121010000f05dfb9f0100004100000000");
        data.extend(bytes(index));
        data
    }

    #[test]
    fn following_index_retains_checksummed_conflicts_and_independent_failures() {
        for (literal, status, unpadded, unpacked) in [
            ("00011101ada65804", "complete", "equal", "equal"),
            ("000112016ef5752f", "complete", "different", "equal"),
            ("0001110217f7519d", "complete", "equal", "different"),
            ("00010501f871f62a", "complete", "different", "equal"),
            (
                "00010401b940ed33",
                "invalid_unpadded_size_domain",
                "unqualified",
                "unqualified",
            ),
            (
                "0001fdffffffffffffff7f01e8554633",
                "invalid_unpadded_size_domain",
                "unqualified",
                "unqualified",
            ),
            (
                "00011101ada65805",
                "crc32_mismatch",
                "unqualified",
                "unqualified",
            ),
            (
                "000111800101000023b84703",
                "nonzero_index_padding",
                "unqualified",
                "unqualified",
            ),
            (
                "000111800000000071b539ba",
                "invalid_vli",
                "unqualified",
                "unqualified",
            ),
            (
                "008100110100000078e77fae",
                "invalid_vli",
                "unqualified",
                "unqualified",
            ),
            (
                "0002110111010000036c30ee",
                "unsupported_record_count",
                "unqualified",
                "unqualified",
            ),
            (
                "0000000000000000",
                "unsupported_record_count",
                "unqualified",
                "unqualified",
            ),
        ] {
            let out = observe(&with_index(literal), 0, true, Some(0));
            let index = out.following_index.unwrap();
            assert_eq!(index.status, status, "{literal}");
            assert_eq!(index.unpadded_size_relationship, unpadded, "{literal}");
            assert_eq!(index.uncompressed_size_relationship, unpacked, "{literal}");
            assert!(index.candidate_preview_hex.len() <= 48);
            assert!(!out.content_decompressed);
        }
        let mut data = with_index("00011101ada65804");
        data[29] = 1;
        let out = observe(&data, 0, true, Some(0));
        assert_eq!(out.block_padding_zero, Some(false));
        assert_eq!(
            out.following_index.unwrap().unpadded_size_relationship,
            "equal"
        );
    }

    #[test]
    fn following_index_alignment_maximum_and_all_partial_prefixes_are_bounded() {
        for (literal, expected_status) in [
            ("00011101ada65804", "complete"),
            ("000111800100000014d28502", "complete"),
            ("00011180800100007d69a256", "complete"),
            ("00011180808001008b294eaf", "complete"),
            (
                "0001fcffffffffffffff7fffffffffffffffff7f3621c263",
                "invalid_declared_stream_size",
            ),
        ] {
            let data = with_index(literal);
            for length in 0..data.len() {
                let out = observe(&data[..length], 0, true, Some(0));
                if let Some(index) = out.following_index {
                    assert_ne!(index.status, "complete", "{literal} length={length}");
                    assert_eq!(index.unpadded_size_relationship, "unqualified");
                    assert!(index.index_observed_hex.len() <= 48);
                }
            }
            let index = observe(&data, 0, true, Some(0)).following_index.unwrap();
            assert_eq!(index.status, expected_status, "{literal}");
            assert_eq!(index.crc32_valid, Some(true));
            assert_eq!(index.end_offset_exclusive, Some(data.len() as u64));
            assert_eq!(index.index_observed_hex, literal);
            let mut embedded = vec![0x77; 7];
            embedded.extend(&data);
            let shifted = observe(&embedded, 7, true, Some(0))
                .following_index
                .unwrap();
            assert_eq!(shifted.start_offset, index.start_offset + 7);
            assert_eq!(shifted.crc32_offset, index.crc32_offset.map(|n| n + 7));
            assert_eq!(
                shifted.end_offset_exclusive,
                index.end_offset_exclusive.map(|n| n + 7)
            );
            assert_eq!(shifted.status, index.status);
            assert_eq!(
                shifted.record_uncompressed_size.unwrap().start_offset,
                index.record_uncompressed_size.unwrap().start_offset + 7
            );
        }
        let index = observe(
            &with_index("0001fcffffffffffffff7fffffffffffffffff7f3621c263"),
            0,
            true,
            Some(0),
        )
        .following_index
        .unwrap();
        assert_eq!(index.record_unpadded_size.unwrap().value, UNPADDED_MAX);
        assert_eq!(
            index.record_uncompressed_size.unwrap().value,
            (1_u64 << 63) - 1
        );
    }

    #[test]
    fn following_index_distinguishes_incomplete_and_malformed_vlis() {
        for literal in ["00", "0080", "0001", "000180", "000111", "00011180"] {
            let index = observe(&with_index(literal), 0, true, Some(0))
                .following_index
                .unwrap();
            assert_eq!(index.status, "sample_truncated_vli", "{literal}");
        }
        for literal in [
            "008000",
            "00018000",
            "0001118000",
            "00808080808080808080",
            "0001808080808080808080",
            "000111808080808080808080",
        ] {
            let index = observe(&with_index(literal), 0, true, Some(0))
                .following_index
                .unwrap();
            assert_eq!(index.status, "invalid_vli", "{literal}");
        }
        for literal in ["00011101", "00011101ada658"] {
            let index = observe(&with_index(literal), 0, true, Some(0))
                .following_index
                .unwrap();
            assert_eq!(index.status, "sample_truncated_index");
            assert!(index.crc32_valid.is_none());
        }
    }

    #[test]
    fn following_index_requires_qualified_locator_without_searching() {
        let mut data = with_index("00011101ada65804");
        assert!(observe(&data, 0, false, Some(0)).following_index.is_none());
        data[20] ^= 1;
        assert!(observe(&data, 0, true, Some(0)).following_index.is_none());
        let mut missing_c = bytes("fd377a585a000000ff12d941");
        let mut header = bytes("0180012101000000");
        // Header is 12 bytes: optional U1, filter21 properties01/00 and zero padding.
        header.extend(crc32fast::hash(&header).to_le_bytes());
        header[0] = 2;
        let crc = crc32fast::hash(&header[..8]);
        header[8..].copy_from_slice(&crc.to_le_bytes());
        missing_c.extend(header);
        missing_c.extend(bytes("00011101ada65804"));
        let out = observe(&missing_c, 0, true, Some(0));
        assert_eq!(out.compressed_size_status, "not_declared");
        assert!(out.following_index.is_none());
        let mut data = with_index("0100011101ada65804");
        let index = observe(&data, 0, true, Some(0)).following_index.unwrap();
        assert_eq!(index.status, "next_item_not_index");
        assert!(index.record_count.is_none());
        data.truncate(32);
        let index = observe(&data, 0, true, Some(0)).following_index.unwrap();
        assert_eq!(index.status, "not_sampled");
        assert!(index.candidate_preview_hex.is_empty());
    }
    #[test]
    fn following_index_uses_check_width_without_verifying_content_or_requiring_header_u() {
        for (id, width) in [
            0_usize, 4, 4, 4, 8, 8, 8, 16, 16, 16, 32, 32, 32, 64, 64, 64,
        ]
        .into_iter()
        .enumerate()
        {
            let mut data = with_index("");
            data[7] = id as u8;
            let crc = crc32fast::hash(&data[6..8]);
            data[8..12].copy_from_slice(&crc.to_le_bytes());
            data.extend(vec![0; width]);
            let mut index = vec![0, 1, (17 + width) as u8, 1];
            index.extend(crc32fast::hash(&index).to_le_bytes());
            data.extend(index);
            let out = observe(&data, 0, true, Some(id as u8));
            let index = out.following_index.unwrap();
            assert_eq!(index.start_offset, (32 + width) as u64);
            assert_eq!(index.unpadded_size_relationship, "equal");
            assert_eq!(index.uncompressed_size_relationship, "equal");
            assert!(!out.content_decompressed);
        }
        let mut data = bytes("fd377a585a000000ff12d941");
        let mut header = bytes("0240052101000000");
        header.extend(crc32fast::hash(&header).to_le_bytes());
        data.extend(header);
        data.extend(bytes("010000410000000000011101ada65804"));
        let out = observe(&data, 0, true, Some(0));
        assert_eq!(out.uncompressed_size_status, "not_declared");
        let index = out.following_index.unwrap();
        assert_eq!(index.status, "complete");
        assert_eq!(index.uncompressed_size_relationship, "header_not_declared");
        let huge = bytes("fd377a585a000000ff12d9410440e8ffffffffffffff7f21010000007725567d");
        let index = observe(&huge, 0, true, Some(0)).following_index.unwrap();
        assert_eq!(index.start_offset, 9223372036854775816);
        assert_eq!(index.status, "not_sampled");
    }
    #[test]
    fn following_index_checks_aggregate_stream_limit_separately_from_vli_domain() {
        for (literal, total, valid) in [
            (
                "0001d4ffffffffffffff7f01f27d0e80",
                9223372036854775804_u64,
                true,
            ),
            (
                "0001d8ffffffffffffff7f017c9d329a",
                9223372036854775808,
                false,
            ),
            (
                "0001fcffffffffffffff7f01d63e84dc",
                9223372036854775844,
                false,
            ),
        ] {
            let out =
                serde_json::to_value(observe(&with_index(literal), 0, true, Some(0))).unwrap();
            let index = &out["following_index"];
            assert_eq!(index["declared_single_record_stream_bytes"], total);
            assert_eq!(index["declared_stream_size_limit_valid"], valid);
            assert_eq!(index["unpadded_size_domain_valid"], true);
            assert_eq!(index["crc32_valid"], true);
            assert_eq!(
                index["status"],
                if valid {
                    "complete"
                } else {
                    "invalid_declared_stream_size"
                }
            );
            assert_eq!(
                index["unpadded_size_relationship"],
                if valid { "different" } else { "unqualified" }
            );
        }
    }
}
