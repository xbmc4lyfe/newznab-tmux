//! Bounded Zstandard declarations, not decompression or file validation.
//! Wire contract: RFC 8878 and facebook/zstd v1.5.7 compression format.
use serde::Serialize;

#[derive(Debug, Default, Serialize)]
pub struct Frame {
    pub offset_basis: &'static str,
    pub file_offset_known: bool,
    pub whole_file_validated: bool,
    pub content_decompressed: bool,
    pub header_status: &'static str,
    pub header_complete: bool,
    pub header_start_offset: usize,
    pub header_end_offset: Option<usize>,
    pub header_observed_hex: String,
    pub descriptor: Option<u8>,
    pub single_segment: Option<bool>,
    pub unused_bit_set: Option<bool>,
    pub reserved_bit_set: Option<bool>,
    pub checksum_declared: Option<bool>,
    pub window_descriptor: Option<u8>,
    pub window_size: Option<u64>,
    pub window_size_basis: Option<&'static str>,
    pub dictionary_id_field_bytes: Option<u8>,
    pub dictionary_id: Option<Integer>,
    pub dictionary_status: &'static str,
    pub content_size_field_bytes: Option<u8>,
    pub content_size: Option<Integer>,
    pub content_size_bias: Option<u16>,
    pub first_block: Option<Block>,
    pub size_relationship: Relationship,
    pub checksum: Checksum,
    pub observed_frame_end_offset: Option<usize>,
}

#[derive(Debug, Serialize)]
pub struct Integer {
    pub start_offset: usize,
    pub end_offset_exclusive: usize,
    pub width_bytes: u8,
    pub raw_hex: String,
    pub value: u64,
}

#[derive(Debug, Default, Serialize)]
pub struct Block {
    pub status: &'static str,
    pub header_offset: usize,
    pub header_observed_hex: String,
    pub last_block: Option<bool>,
    pub block_type: Option<&'static str>,
    pub block_type_code: Option<u8>,
    pub declared_size: Option<u32>,
    pub block_size_limit: Option<u64>,
    pub physical_payload_bytes: Option<u32>,
    pub regenerated_size: Option<u32>,
    pub physical_size_within_limit: Option<bool>,
    pub regenerated_size_within_limit: Option<bool>,
    pub payload_start_offset: Option<usize>,
    pub payload_end_offset: Option<usize>,
    pub payload_available_bytes: usize,
    pub payload_complete: bool,
}

#[derive(Debug, Default, Serialize)]
pub struct Relationship {
    pub status: &'static str,
    pub unavailable_reason: Option<&'static str>,
    pub basis: &'static str,
}

#[derive(Debug, Default, Serialize)]
pub struct Checksum {
    pub status: &'static str,
    pub verification_status: &'static str,
    pub start_offset: Option<usize>,
    pub available_bytes: usize,
    pub observed_hex: String,
    pub observed_little_endian_value: Option<u32>,
}

fn integer(data: &[u8], cursor: &mut usize, width: u8, bias: u16) -> Option<Integer> {
    let start = *cursor;
    let end = start.checked_add(usize::from(width))?;
    let bytes = data.get(start..end)?;
    let mut raw = [0u8; 8];
    raw[..usize::from(width)].copy_from_slice(bytes);
    *cursor = end;
    Some(Integer {
        start_offset: start,
        end_offset_exclusive: end,
        width_bytes: width,
        raw_hex: super::hex(bytes),
        value: u64::from_le_bytes(raw).checked_add(u64::from(bias))?,
    })
}

pub(super) fn parse(data: &[u8], offset: usize) -> Frame {
    // Called only for a fully observed four-byte magic from the bounded scan.
    let start = offset + 4;
    let mut frame = Frame {
        offset_basis: "decoded parser-sample-relative; serialized frame extent is not file extent",
        header_start_offset: start,
        header_status: "partial_header",
        dictionary_status: "unavailable_header",
        size_relationship: Relationship {
            status: "unavailable",
            unavailable_reason: Some("header_partial"),
            basis: "declared inner content size versus implied regenerated size of one fully observed first-and-final raw/RLE block; no decompression",
        },
        checksum: Checksum {
            status: "unavailable_header",
            verification_status: "not_performed",
            start_offset: None,
            available_bytes: 0,
            observed_hex: String::new(),
            observed_little_endian_value: None,
        },
        ..Frame::default()
    };
    let Some(&descriptor) = data.get(start) else {
        return frame;
    };
    let single = descriptor & 0x20 != 0;
    let dictionary_width = [0, 1, 2, 4][usize::from(descriptor & 3)];
    let content_width = match descriptor >> 6 {
        0 => u8::from(single),
        1 => 2,
        2 => 4,
        _ => 8,
    };
    let expected_end = start
        + 1
        + usize::from(!single)
        + usize::from(dictionary_width)
        + usize::from(content_width);
    frame.header_observed_hex = super::hex(&data[start..data.len().min(expected_end)]);
    frame.descriptor = Some(descriptor);
    frame.single_segment = Some(single);
    frame.unused_bit_set = Some(descriptor & 0x10 != 0);
    frame.reserved_bit_set = Some(descriptor & 8 != 0);
    frame.checksum_declared = Some(descriptor & 4 != 0);
    frame.checksum.status = if descriptor & 4 != 0 {
        "declared_location_unavailable"
    } else {
        "not_declared"
    };
    frame.dictionary_id_field_bytes = Some(dictionary_width);
    frame.content_size_field_bytes = Some(content_width);
    frame.content_size_bias = Some(if content_width == 2 { 256 } else { 0 });
    let mut cursor = start + 1;
    if !single {
        let Some(&window) = data.get(cursor) else {
            return frame;
        };
        cursor += 1;
        let base = 1u64 << (10 + (window >> 3));
        frame.window_descriptor = Some(window);
        frame.window_size = Some(base + (base / 8) * u64::from(window & 7));
        frame.window_size_basis = Some("window_descriptor_declaration");
    }
    if dictionary_width != 0 {
        frame.dictionary_id = integer(data, &mut cursor, dictionary_width, 0);
        if frame.dictionary_id.is_none() {
            return frame;
        }
    }
    frame.dictionary_status = if frame.dictionary_id.as_ref().is_some_and(|id| id.value != 0) {
        "nonzero_dictionary_id_declared"
    } else {
        "dictionary_unspecified_not_proven_independent"
    };
    if content_width != 0 {
        frame.content_size = integer(
            data,
            &mut cursor,
            content_width,
            frame.content_size_bias.unwrap(),
        );
        if frame.content_size.is_none() {
            return frame;
        }
    }
    if single {
        frame.window_size = frame.content_size.as_ref().map(|size| size.value);
        frame.window_size_basis = Some("single_segment_content_size_declaration");
    }
    frame.header_complete = true;
    frame.header_end_offset = Some(cursor);
    if descriptor & 8 != 0 {
        frame.header_status = "unsupported_reserved_bit";
        frame.size_relationship.unavailable_reason = Some("reserved_header_bit");
        return frame;
    }
    frame.header_status = "observed_supported_header";
    frame.size_relationship.unavailable_reason = Some("block_header_partial");
    let mut block = Block {
        status: "partial_header",
        header_offset: cursor,
        header_observed_hex: super::hex(&data[cursor..data.len().min(cursor.saturating_add(3))]),
        ..Block::default()
    };
    if let Some(header) = data.get(cursor..cursor.saturating_add(3)) {
        let raw = u32::from_le_bytes([header[0], header[1], header[2], 0]);
        let size = raw >> 3;
        let kind = ((raw >> 1) & 3) as u8;
        block.last_block = Some(raw & 1 != 0);
        block.block_type_code = Some(kind);
        block.block_type = Some(["raw", "rle", "compressed", "reserved"][usize::from(kind)]);
        block.declared_size = Some(size);
        block.block_size_limit = frame.window_size.map(|v| v.min(131_072));
        if kind == 3 {
            block.status = "unsupported_reserved_block_type";
            frame.size_relationship.unavailable_reason = Some("reserved_block_type");
        } else {
            let physical = if kind == 1 { 1 } else { size };
            block.physical_payload_bytes = Some(physical);
            block.regenerated_size = (kind != 2).then_some(size);
            block.physical_size_within_limit =
                block.block_size_limit.map(|v| u64::from(physical) <= v);
            block.regenerated_size_within_limit = block
                .regenerated_size
                .zip(block.block_size_limit)
                .map(|(size, limit)| u64::from(size) <= limit);
            let payload_start = cursor + 3;
            let payload_end = payload_start.checked_add(physical as usize);
            block.payload_start_offset = Some(payload_start);
            block.payload_end_offset = payload_end;
            block.payload_available_bytes = data
                .len()
                .saturating_sub(payload_start)
                .min(physical as usize);
            block.payload_complete = block.payload_available_bytes == physical as usize;
            let reason = if !block.payload_complete {
                Some("payload_partial")
            } else if kind == 2 {
                Some("compressed_block_not_decoded")
            } else if raw & 1 == 0 {
                Some("first_block_not_final")
            } else {
                None
            };
            // Limit comparisons remain independent observations. Even a malformed
            // declaration cannot erase a fully observed literal byte extent.
            block.status = reason.unwrap_or("observed_literal_payload");
            frame.size_relationship.unavailable_reason = reason;
            if reason.is_none() {
                if let Some(declared) = &frame.content_size {
                    frame.size_relationship.status = if declared.value == u64::from(size) {
                        "equal"
                    } else {
                        "different"
                    };
                } else {
                    frame.size_relationship.unavailable_reason = Some("content_size_absent");
                }
                // Checksum coordinates follow physical framing, independently
                // of whether the inner content-size declaration agrees.
                if let Some(end) = payload_end {
                    if descriptor & 4 != 0 {
                        frame.checksum.start_offset = Some(end);
                        let bytes = &data[end..data.len().min(end.saturating_add(4))];
                        frame.checksum.available_bytes = bytes.len();
                        frame.checksum.observed_hex = super::hex(bytes);
                        frame.checksum.status = if bytes.len() == 4 {
                            "observed_unverified"
                        } else {
                            "partial_checksum"
                        };
                        if let Ok(bytes) = <[u8; 4]>::try_from(bytes) {
                            frame.checksum.observed_little_endian_value =
                                Some(u32::from_le_bytes(bytes));
                            frame.observed_frame_end_offset = end.checked_add(4);
                        }
                    } else {
                        frame.observed_frame_end_offset = Some(end);
                    }
                }
            }
        }
    }
    frame.first_block = Some(block);
    frame
}

#[cfg(test)]
mod tests {
    use serde_json::Value;

    fn bytes(hex: &str) -> Vec<u8> {
        (0..hex.len())
            .step_by(2)
            .map(|i| u8::from_str_radix(&hex[i..i + 2], 16).unwrap())
            .collect()
    }

    fn frame(hex: &str) -> Value {
        let metadata = serde_json::to_value(super::super::parse(&bytes(hex))).unwrap();
        metadata["headers"]
            .as_array()
            .unwrap()
            .iter()
            .find(|v| v["format"] == "zstandard")
            .unwrap()["frame"]
            .clone()
    }

    #[test]
    fn literal_raw_and_rle_sizes_have_distinct_physical_and_inner_domains() {
        for (hex, physical, regenerated, status) in [
            ("28b52ffd2003190000414243", 3, 3, "equal"),
            ("28b52ffd20031b000041", 1, 3, "equal"),
            ("28b52ffd2004190000414243", 3, 3, "different"),
            ("28b52ffd20041b000041", 1, 3, "different"),
            ("28b52ffd2000010000", 0, 0, "equal"),
            ("28b52ffd200103000041", 1, 0, "different"),
        ] {
            let f = frame(hex);
            assert_eq!(
                f["first_block"]["physical_payload_bytes"], physical,
                "{hex}"
            );
            assert_eq!(f["first_block"]["regenerated_size"], regenerated, "{hex}");
            assert_eq!(f["size_relationship"]["status"], status, "{hex}");
            assert_eq!(f["whole_file_validated"], false);
            assert_eq!(f["content_decompressed"], false);
        }
    }

    #[test]
    fn limit_observations_do_not_hide_literal_mismatches_or_checksum_coordinates() {
        for (hex, status, checksum_offset) in [
            ("28b52ffd240119000041424311223344", "different", 12),
            ("28b52ffd24011300004111223344", "different", 10),
            ("28b52ffd24000300004111223344", "equal", 10),
        ] {
            let sample = bytes(hex);
            for length in checksum_offset..=sample.len() {
                let f = serde_json::to_value(super::super::parse(&sample[..length])).unwrap()
                    ["headers"][0]["frame"].clone();
                assert_eq!(f["size_relationship"]["status"], status, "{hex}/{length}");
                assert!(f["size_relationship"]["unavailable_reason"].is_null());
                assert_eq!(f["checksum"]["start_offset"], checksum_offset);
                assert_eq!(f["checksum"]["available_bytes"], length - checksum_offset);
                assert_eq!(f["checksum"]["verification_status"], "not_performed");
                assert_eq!(f["whole_file_validated"], false);
                if length == sample.len() {
                    assert_eq!(f["checksum"]["status"], "observed_unverified");
                    assert_eq!(f["observed_frame_end_offset"], length);
                } else {
                    assert_eq!(f["checksum"]["status"], "partial_checksum");
                    assert!(f["observed_frame_end_offset"].is_null());
                }
            }
        }
    }

    #[test]
    fn physical_and_regenerated_limits_are_independent_including_empty_rle() {
        for (hex, physical_ok, regenerated_ok, relationship) in [
            ("28b52ffd200003000041", false, true, "equal"),
            ("28b52ffd200113000041", true, false, "different"),
            ("28b52ffd20011100004142", false, false, "different"),
            ("28b52ffd000003200041", true, true, "unavailable"),
            ("28b52ffd00000b200041", true, false, "unavailable"),
            ("28b52ffd003803001041", true, true, "unavailable"),
            ("28b52ffd00380b001041", true, false, "unavailable"),
        ] {
            let f = frame(hex);
            assert_eq!(
                f["first_block"]["physical_size_within_limit"], physical_ok,
                "{hex}"
            );
            assert_eq!(
                f["first_block"]["regenerated_size_within_limit"], regenerated_ok,
                "{hex}"
            );
            assert_eq!(f["size_relationship"]["status"], relationship, "{hex}");
            if relationship != "unavailable" {
                assert!(f["size_relationship"]["unavailable_reason"].is_null());
            } else {
                assert_eq!(
                    f["size_relationship"]["unavailable_reason"],
                    "content_size_absent"
                );
            }
            assert!(f["observed_frame_end_offset"].is_number());
        }
    }

    #[test]
    fn zero_repeat_rle_still_requires_its_one_physical_content_byte() {
        let f = frame("28b52ffd2001030000");
        assert_eq!(f["first_block"]["physical_payload_bytes"], 1);
        assert_eq!(f["first_block"]["regenerated_size"], 0);
        assert_eq!(f["first_block"]["payload_complete"], false);
        assert_eq!(f["first_block"]["payload_available_bytes"], 0);
        assert_eq!(f["size_relationship"]["status"], "unavailable");
        assert_eq!(
            f["size_relationship"]["unavailable_reason"],
            "payload_partial"
        );
        assert!(f["observed_frame_end_offset"].is_null());
        assert!(f["checksum"]["start_offset"].is_null());
    }

    #[test]
    fn content_size_presence_zero_bias_and_u64_maxima_are_exact_declarations() {
        let absent = frame("28b52ffd0000010000");
        assert!(absent["content_size"].is_null());
        assert_eq!(absent["content_size_field_bytes"], 0);
        assert_eq!(absent["window_size"], 1024);
        for (hex, width, value) in [
            ("28b52ffd2000", 1, 0u64),
            ("28b52ffd600000", 2, 256),
            ("28b52ffda000000000", 4, 0),
            ("28b52ffde0ffffffffffffffff", 8, u64::MAX),
            ("28b52ffde0feffffffffffffff", 8, u64::MAX - 1),
        ] {
            let f = frame(hex);
            assert_eq!(f["header_complete"], true, "{hex}");
            assert_eq!(f["content_size"]["width_bytes"], width);
            assert_eq!(f["content_size"]["value"].as_u64(), Some(value));
            assert_eq!(f["window_size"].as_u64(), Some(value));
            assert_eq!(f["first_block"]["status"], "partial_header");
        }
        let huge_window = frame("28b52ffd00ff010000");
        assert_eq!(huge_window["window_size"], 4_123_168_604_160u64);
        assert_eq!(huge_window["first_block"]["block_size_limit"], 131_072);
    }

    #[test]
    fn dictionary_widths_and_ignored_and_reserved_descriptor_bits_are_distinct() {
        for (hex, width) in [
            ("28b52ffd210d0109000041", 1),
            ("28b52ffd220d000109000041", 2),
            ("28b52ffd230d0000000109000041", 4),
        ] {
            let f = frame(hex);
            assert_eq!(f["dictionary_id"]["value"], 13);
            assert_eq!(f["dictionary_id"]["width_bytes"], width);
            assert_eq!(f["size_relationship"]["status"], "equal");
        }
        let zero = frame("28b52ffd21000109000041");
        assert_eq!(zero["dictionary_id"]["value"], 0);
        assert_eq!(
            zero["dictionary_status"],
            "dictionary_unspecified_not_proven_independent"
        );
        let unused = frame("28b52ffd300109000041");
        assert_eq!(unused["unused_bit_set"], true);
        assert_eq!(unused["size_relationship"]["status"], "equal");
        let reserved = frame("28b52ffd280109000041");
        assert_eq!(reserved["header_complete"], true);
        assert_eq!(reserved["header_status"], "unsupported_reserved_bit");
        assert!(reserved["first_block"].is_null());
        assert_eq!(
            reserved["size_relationship"]["unavailable_reason"],
            "reserved_header_bit"
        );
    }

    #[test]
    fn every_maximum_header_prefix_preserves_field_presence_without_size_based_work() {
        // Descriptor c3: non-single-segment, four-byte dictionary, eight-byte
        // content size. Window ff is the largest encoded window declaration.
        let mut sample = bytes("28b52ffdc3ff");
        sample.extend_from_slice(&[0xff; 12]);
        assert_eq!(sample.len(), 18);
        for length in 4..=18 {
            let metadata = serde_json::to_value(super::super::parse(&sample[..length])).unwrap();
            let f = &metadata["headers"][0]["frame"];
            assert_eq!(f["header_complete"], length == 18, "{length}");
            assert_eq!(
                f["header_observed_hex"].as_str().unwrap().len(),
                (length - 4) * 2
            );
            assert_eq!(f["dictionary_id"].is_null(), length < 10);
            assert_eq!(f["content_size"].is_null(), length < 18);
            if length == 18 {
                assert_eq!(f["content_size"]["value"].as_u64(), Some(u64::MAX));
                assert_eq!(f["dictionary_id"]["value"], u32::MAX);
                assert_eq!(f["header_end_offset"], 18);
            }
        }
    }

    #[test]
    fn every_short_prefix_is_partial_without_inventing_missing_bytes() {
        let sample = bytes("28b52ffd200109000041");
        for length in 0..=sample.len() {
            let metadata = serde_json::to_value(super::super::parse(&sample[..length])).unwrap();
            if length < 4 {
                assert!(metadata["headers"].as_array().unwrap().is_empty());
            } else {
                let f = &metadata["headers"][0]["frame"];
                assert_eq!(f["header_complete"], length >= 6, "{length}");
                assert_eq!(
                    f["size_relationship"]["status"],
                    if length == 10 { "equal" } else { "unavailable" }
                );
                if (6..9).contains(&length) {
                    assert_eq!(f["first_block"]["status"], "partial_header");
                }
                if length == 9 {
                    assert_eq!(f["first_block"]["payload_available_bytes"], 0);
                    assert_eq!(
                        f["size_relationship"]["unavailable_reason"],
                        "payload_partial"
                    );
                }
            }
        }
    }

    #[test]
    fn checksum_availability_is_separate_from_declared_size_agreement_and_verification() {
        let sample = bytes("28b52ffd24041b00004111223344");
        for length in 10..=14 {
            let f =
                serde_json::to_value(super::super::parse(&sample[..length])).unwrap()["headers"][0]
                    ["frame"]
                    .clone();
            assert_eq!(f["size_relationship"]["status"], "different");
            assert_eq!(f["checksum"]["start_offset"], 10);
            assert_eq!(f["checksum"]["available_bytes"], length - 10);
            assert_eq!(f["checksum"]["verification_status"], "not_performed");
            if length == 14 {
                assert_eq!(f["checksum"]["status"], "observed_unverified");
                assert_eq!(f["checksum"]["observed_little_endian_value"], 0x44332211u32);
                assert_eq!(f["observed_frame_end_offset"], 14);
            } else {
                assert_eq!(f["checksum"]["status"], "partial_checksum");
                assert!(f["observed_frame_end_offset"].is_null());
            }
        }
    }

    #[test]
    fn compressed_reserved_and_nonfinal_blocks_do_not_imply_whole_frame_output_sizes() {
        for (hex, reason) in [
            ("28b52ffd20031d0000414243", "compressed_block_not_decoded"),
            ("28b52ffd20031f0000414243", "reserved_block_type"),
            ("28b52ffd2003180000414243", "first_block_not_final"),
        ] {
            let f = frame(hex);
            assert_eq!(f["size_relationship"]["status"], "unavailable");
            assert_eq!(f["size_relationship"]["unavailable_reason"], reason);
            assert!(f["observed_frame_end_offset"].is_null());
        }
    }

    #[test]
    fn embedded_and_concatenated_candidates_keep_offsets_and_the_sixteen_candidate_cap() {
        let literal = bytes("28b52ffd2003190000414243");
        let mut sample = vec![0; 7];
        sample.extend_from_slice(&literal);
        sample.extend_from_slice(&literal);
        let metadata = serde_json::to_value(super::super::parse(&sample)).unwrap();
        assert_eq!(metadata["headers"][0]["offset"], 7);
        assert_eq!(
            metadata["headers"][0]["frame"]["first_block"]["payload_start_offset"],
            16
        );
        assert_eq!(
            metadata["headers"][0]["frame"]["observed_frame_end_offset"],
            19
        );
        assert_eq!(metadata["headers"][1]["offset"], 19);
        assert_eq!(metadata["headers"][0]["frame"]["file_offset_known"], false);
        let saturated = literal.repeat(17);
        let metadata = serde_json::to_value(super::super::parse(&saturated)).unwrap();
        assert_eq!(metadata["headers"].as_array().unwrap().len(), 16);
        assert_eq!(metadata["candidate_scan_truncated_formats"][0], "Zstandard");
    }
}
