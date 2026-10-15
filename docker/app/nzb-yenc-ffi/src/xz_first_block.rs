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
}
