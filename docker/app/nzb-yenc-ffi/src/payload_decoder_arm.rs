//! ARM payload decoding with separate ordinary and dense-escape loops.
//! The CR/LF lookup technique follows RapidYenc's CC0/Public Domain decoder:
//! https://github.com/animetosho/rapidyenc/blob/480bd7b5896f8b3edecc721d23f1384d767ffe2f/src/decoder_neon64.cc
//! Mask packing and all lenient state handling here use the existing Rust contract.

use crate::payload_decoder::{COMPACT, ESCAPE_OFFSETS, block_masks, scalar_tail, store_word};

const fn single_marker_indexes() -> [[u8; 16]; 16] {
    let mut table = [[255; 16]; 16];
    let mut marker = 0;
    while marker < 16 {
        let mut input = 0;
        let mut output = 0;
        while input < 16 {
            if input != marker {
                table[marker][output] = input as u8;
                output += 1;
            }
            input += 1;
        }
        marker += 1;
    }
    table
}

static SINGLE_MARKER_INDEXES: [[u8; 16]; 16] = single_marker_indexes();

#[cfg(target_arch = "aarch64")]
#[target_feature(enable = "neon")]
#[inline]
unsafe fn decode_neon_block(
    bytes: std::arch::aarch64::uint8x16_t,
    equals: std::arch::aarch64::uint8x16_t,
    special: std::arch::aarch64::uint8x16_t,
    destination: *mut u8,
    escaped: &mut bool,
) -> usize {
    use std::arch::aarch64::*;
    unsafe {
        if !*escaped && vmaxvq_u8(special) == 0 {
            vst1q_u8(destination, vsubq_u8(bytes, vdupq_n_u8(42)));
            return 16;
        }
        let weights =
            vld1q_u8([1u8, 2, 4, 8, 16, 32, 64, 128, 1, 2, 4, 8, 16, 32, 64, 128].as_ptr());
        let newlines = vbicq_u8(special, equals);
        // Pairwise additions retain the low/high eight-byte masks of both
        // comparisons in one scalar word, avoiding four horizontal reductions.
        let pairs = vpaddq_u8(vandq_u8(equals, weights), vandq_u8(newlines, weights));
        let quads = vpaddq_u8(pairs, pairs);
        let masks = vgetq_lane_u32(vreinterpretq_u32_u8(vpaddq_u8(quads, quads)), 0);
        let equals_mask = masks as u16;
        let newline_mask = (masks >> 16) as u16;
        if !*escaped
            && newline_mask == 0
            && equals_mask != 0
            && equals_mask & equals_mask.wrapping_sub(1) == 0
        {
            // One ordinary escape needs a single full-vector shuffle, with no
            // per-half adjustment tables or variable-length output stores.
            let adjustment = vandq_u8(vextq_u8(vdupq_n_u8(0), equals, 15), vdupq_n_u8(64));
            let decoded = vsubq_u8(vsubq_u8(bytes, vdupq_n_u8(42)), adjustment);
            let indexes =
                vld1q_u8(SINGLE_MARKER_INDEXES[equals_mask.trailing_zeros() as usize].as_ptr());
            let compact = vqtbl1q_u8(decoded, indexes);
            vst1_u8(destination, vget_low_u8(compact));
            store_word(
                vgetq_lane_u64(vreinterpretq_u64_u8(compact), 1),
                destination.add(8),
                7,
            );
            *escaped = equals_mask & 0x8000 != 0;
            return 15;
        }
        let (remove, adjust) = block_masks(equals_mask, newline_mask, escaped);
        let low = vsub_u8(
            vsub_u8(vget_low_u8(bytes), vdup_n_u8(42)),
            vld1_u8(ESCAPE_OFFSETS[(adjust & 255) as usize].as_ptr()),
        );
        let high = vsub_u8(
            vsub_u8(vget_high_u8(bytes), vdup_n_u8(42)),
            vld1_u8(ESCAPE_OFFSETS[(adjust >> 8) as usize].as_ptr()),
        );
        let low_count = 8 - (remove & 255).count_ones() as usize;
        let high_count = 8 - (remove >> 8).count_ones() as usize;
        let low = vtbl1_u8(low, vld1_u8(COMPACT[(remove & 255) as usize].as_ptr()));
        let high = vtbl1_u8(high, vld1_u8(COMPACT[(remove >> 8) as usize].as_ptr()));
        store_word(
            vget_lane_u64(vreinterpret_u64_u8(low), 0),
            destination,
            low_count,
        );
        store_word(
            vget_lane_u64(vreinterpret_u64_u8(high), 0),
            destination.add(low_count),
            high_count,
        );
        low_count + high_count
    }
}

#[cfg(target_arch = "aarch64")]
#[target_feature(enable = "neon")]
// Keep dense-loop branches out of the ordinary loop's register allocation.
#[inline(never)]
unsafe fn decode_neon_regular(source: *const u8, destination: *mut u8, length: usize) -> usize {
    use std::arch::aarch64::*;
    let mut read = 0;
    let mut written = 0;
    let mut escaped = false;
    unsafe {
        let offset = vdupq_n_u8(42);
        let lookup = vld1q_u8([0u8, 0, 0, 0, 0, 0, 0, 0, 0, 0, 255, 0, 0, 255, 0, 0].as_ptr());
        while length - read >= 64 {
            // Encoders commonly wrap after a multiple of 64 encoded bytes.
            // Consume leading CR/LF without consuming escape state. A lone LF
            // also restores alignment after a line with an odd encoded length.
            while length - read >= 64 && matches!(source.add(read).read(), b'\r' | b'\n') {
                read += 1;
            }
            if length - read < 64 {
                break;
            }
            // Load the entire group before writing any output, including when
            // the destination overlaps the source at or below its cursor.
            let a = vld1q_u8(source.add(read));
            let b = vld1q_u8(source.add(read + 16));
            let c = vld1q_u8(source.add(read + 32));
            let d = vld1q_u8(source.add(read + 48));
            let eq_a = vceqq_u8(a, vdupq_n_u8(b'='));
            let eq_b = vceqq_u8(b, vdupq_n_u8(b'='));
            let eq_c = vceqq_u8(c, vdupq_n_u8(b'='));
            let eq_d = vceqq_u8(d, vdupq_n_u8(b'='));
            let special_a = vqtbx1q_u8(eq_a, lookup, a);
            let special_b = vqtbx1q_u8(eq_b, lookup, b);
            let special_c = vqtbx1q_u8(eq_c, lookup, c);
            let special_d = vqtbx1q_u8(eq_d, lookup, d);
            let any_special = vorrq_u8(
                vorrq_u8(special_a, special_b),
                vorrq_u8(special_c, special_d),
            );
            if !escaped && vmaxvq_u8(any_special) == 0 {
                vst1q_u8(destination.add(written), vsubq_u8(a, offset));
                vst1q_u8(destination.add(written + 16), vsubq_u8(b, offset));
                vst1q_u8(destination.add(written + 32), vsubq_u8(c, offset));
                vst1q_u8(destination.add(written + 48), vsubq_u8(d, offset));
                written += 64;
            } else {
                written +=
                    decode_neon_block(a, eq_a, special_a, destination.add(written), &mut escaped);
                written +=
                    decode_neon_block(b, eq_b, special_b, destination.add(written), &mut escaped);
                written +=
                    decode_neon_block(c, eq_c, special_c, destination.add(written), &mut escaped);
                written +=
                    decode_neon_block(d, eq_d, special_d, destination.add(written), &mut escaped);
            }
            read += 64;
        }
        while length - read >= 16 {
            let bytes = vld1q_u8(source.add(read));
            let equals = vceqq_u8(bytes, vdupq_n_u8(b'='));
            let special = vqtbx1q_u8(equals, lookup, bytes);
            written += decode_neon_block(
                bytes,
                equals,
                special,
                destination.add(written),
                &mut escaped,
            );
            read += 16;
        }
        scalar_tail(source, destination, length, read, written, escaped)
    }
}

#[cfg(target_arch = "aarch64")]
#[target_feature(enable = "neon")]
#[inline(never)]
unsafe fn decode_neon_dense(source: *const u8, destination: *mut u8, length: usize) -> usize {
    use std::arch::aarch64::*;
    let mut read = 0;
    let mut written = 0;
    let mut escaped = false;
    unsafe {
        let lookup = vld1q_u8([0u8, 0, 0, 0, 0, 0, 0, 0, 0, 0, 255, 0, 0, 255, 0, 0].as_ptr());
        let marker = vdupq_n_u8(b'=');
        let offset = vdupq_n_u8(106);
        while length - read >= 16 {
            // A line boundary does not consume a pending escape. Skipping only
            // leading CR/LF also restores pair alignment after a wrapped line.
            while read < length && matches!(source.add(read).read(), b'\r' | b'\n') {
                read += 1;
            }
            if length - read < 16 {
                break;
            }
            if length - read >= 64 {
                let first = vld2q_u8(source.add(read));
                let second = vld2q_u8(source.add(read + 32));
                let (markers_a, values_a, markers_b, values_b) = if escaped {
                    (first.1, first.0, second.1, second.0)
                } else {
                    (first.0, first.1, second.0, second.1)
                };
                let invalid_markers = vmvnq_u8(vandq_u8(
                    vceqq_u8(markers_a, marker),
                    vceqq_u8(markers_b, marker),
                ));
                let invalid_values =
                    vorrq_u8(vqtbl1q_u8(lookup, values_a), vqtbl1q_u8(lookup, values_b));
                if vmaxvq_u8(vorrq_u8(invalid_markers, invalid_values)) == 0 {
                    // Both interleaved loads precede these exact 32 output
                    // bytes. Values may themselves be '=', as scalar decoding
                    // consumes them rather than treating them as new markers.
                    vst1q_u8(destination.add(written), vsubq_u8(values_a, offset));
                    vst1q_u8(destination.add(written + 16), vsubq_u8(values_b, offset));
                    written += 32;
                    read += 64;
                    continue;
                }
            } else if length - read >= 32 {
                let pairs = vld2q_u8(source.add(read));
                let (markers, values) = if escaped {
                    (pairs.1, pairs.0)
                } else {
                    (pairs.0, pairs.1)
                };
                let invalid = vorrq_u8(
                    vmvnq_u8(vceqq_u8(markers, marker)),
                    vqtbl1q_u8(lookup, values),
                );
                if vmaxvq_u8(invalid) == 0 {
                    vst1q_u8(destination.add(written), vsubq_u8(values, offset));
                    written += 16;
                    read += 32;
                    continue;
                }
            }
            let bytes = vld1q_u8(source.add(read));
            let equals = vceqq_u8(bytes, marker);
            let special = vqtbx1q_u8(equals, lookup, bytes);
            written += decode_neon_block(
                bytes,
                equals,
                special,
                destination.add(written),
                &mut escaped,
            );
            read += 16;
        }
        scalar_tail(source, destination, length, read, written, escaped)
    }
}

#[target_feature(enable = "neon")]
pub(crate) unsafe fn decode_neon(source: *const u8, destination: *mut u8, length: usize) -> usize {
    use std::arch::aarch64::*;
    unsafe {
        // This bounded classifier only chooses a kernel. Both kernels accept every
        // payload byte and retain the same CR/LF, dangling-escape and store semantics.
        if length >= 16 {
            let bytes = vld1q_u8(source);
            let even = vandq_u16(vreinterpretq_u16_u8(bytes), vdupq_n_u16(255));
            if vminvq_u16(vceqq_u16(even, vdupq_n_u16(u16::from(b'=')))) == u16::MAX {
                return decode_neon_dense(source, destination, length);
            }
        }
        decode_neon_regular(source, destination, length)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn verify_dense(payload: &[u8]) {
        let mut expected = vec![0xcc; payload.len() + 32];
        let expected_len = unsafe {
            scalar_tail(
                payload.as_ptr(),
                expected.as_mut_ptr().add(16),
                payload.len(),
                0,
                0,
                false,
            )
        };
        for kernel in [
            decode_neon_dense as unsafe fn(*const u8, *mut u8, usize) -> usize,
            decode_neon_regular,
            decode_neon,
        ] {
            let mut actual = vec![0xcc; payload.len() + 32];
            let actual_len =
                unsafe { kernel(payload.as_ptr(), actual.as_mut_ptr().add(16), payload.len()) };
            assert_eq!(actual_len, expected_len, "payload {payload:?}");
            assert_eq!(actual, expected, "payload {payload:?}");
        }
    }

    #[test]
    fn dense_loop_matches_scalar_for_all_values_alignments_and_escape_carries() {
        for value in 0..=255u8 {
            for alignment in 0..32 {
                let mut payload = vec![b'k'; alignment];
                for _ in 0..40 {
                    payload.extend_from_slice(&[b'=', value]);
                }
                payload.extend_from_slice(b"=\r\n=\r\n=\r\n");
                verify_dense(&payload);
            }
        }
        // The first vector leaves a marker pending; subsequent alternating
        // vectors contain values in even lanes and markers in odd lanes.
        let mut carry = vec![b'k'; 15];
        carry.push(b'=');
        for _ in 0..40 {
            carry.extend_from_slice(b"@=");
        }
        verify_dense(&carry);
        carry.extend_from_slice(b"\r\n\r\n");
        verify_dense(&carry);
    }

    #[test]
    fn dense_loop_matches_scalar_for_seeded_arbitrary_payloads() {
        let mut state = 0x8618_72bd_2354_f09au64;
        for length in 0..513 {
            let mut payload = Vec::with_capacity(length);
            for _ in 0..length {
                state ^= state << 13;
                state ^= state >> 7;
                state ^= state << 17;
                payload.push(match state as u8 % 8 {
                    0 => b'=',
                    1 => b'\r',
                    2 => b'\n',
                    _ => state as u8,
                });
            }
            verify_dense(&payload);
        }
    }

    #[test]
    fn neon_groups_preserve_escape_carry_across_wrapped_pairs_and_short_tails() {
        let encoded = b"=@=J=M=}==".repeat(40);
        for width in [1, 2, 15, 16, 17, 31, 32, 33, 63, 64, 65, 127, 128, 129] {
            let mut wrapped = Vec::new();
            for line in encoded.chunks(width) {
                wrapped.extend_from_slice(line);
                wrapped.extend_from_slice(b"\r\n");
            }
            for prefix in 0..32 {
                let mut payload = vec![b'k'; prefix];
                payload.extend_from_slice(&wrapped);
                for tail in 0..65 {
                    verify_dense(&payload[..payload.len() - tail]);
                }
            }
        }
    }
}
