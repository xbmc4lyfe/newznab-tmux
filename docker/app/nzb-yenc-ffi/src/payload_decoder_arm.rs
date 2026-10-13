//! ARM payload decoding with separate ordinary and dense-escape loops.
//! The CR/LF lookup technique follows RapidYenc's CC0/Public Domain decoder:
//! https://github.com/animetosho/rapidyenc/blob/480bd7b5896f8b3edecc721d23f1384d767ffe2f/src/decoder_neon64.cc
//! Mask packing and all lenient state handling here use the existing Rust contract.

use crate::payload_decoder::{COMPACT, ESCAPE_OFFSETS, block_masks, scalar_tail, store_word};

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
        let weights = vld1_u8([1u8, 2, 4, 8, 16, 32, 64, 128].as_ptr());
        while length - read >= 16 {
            let bytes = vld1q_u8(source.add(read));
            let equals = vceqq_u8(bytes, vdupq_n_u8(b'='));
            let newlines = vorrq_u8(
                vceqq_u8(bytes, vdupq_n_u8(b'\r')),
                vceqq_u8(bytes, vdupq_n_u8(b'\n')),
            );
            if !escaped && vmaxvq_u8(vorrq_u8(equals, newlines)) == 0 {
                vst1q_u8(destination.add(written), vsubq_u8(bytes, offset));
                written += 16;
            } else {
                let equals_mask = u16::from(vaddv_u8(vand_u8(vget_low_u8(equals), weights)))
                    | (u16::from(vaddv_u8(vand_u8(vget_high_u8(equals), weights))) << 8);
                let newline_mask = u16::from(vaddv_u8(vand_u8(vget_low_u8(newlines), weights)))
                    | (u16::from(vaddv_u8(vand_u8(vget_high_u8(newlines), weights))) << 8);
                let (remove, adjust) = block_masks(equals_mask, newline_mask, &mut escaped);
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
                    destination.add(written),
                    low_count,
                );
                store_word(
                    vget_lane_u64(vreinterpret_u64_u8(high), 0),
                    destination.add(written + low_count),
                    high_count,
                );
                written += low_count + high_count;
            }
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
        let offset = vdupq_n_u8(42);
        let newlines_lookup =
            vld1q_u8([0u8, 0, 0, 0, 0, 0, 0, 0, 0, 0, 255, 0, 0, 255, 0, 0].as_ptr());
        let weights = vld1q_u16(
            [
                0x0101u16, 0x0202, 0x0404, 0x0808, 0x1010, 0x2020, 0x4040, 0x8080,
            ]
            .as_ptr(),
        );
        while length - read >= 16 {
            let bytes = vld1q_u8(source.add(read));
            let equals = vceqq_u8(bytes, vdupq_n_u8(b'='));
            let newlines = vqtbl1q_u8(newlines_lookup, bytes);
            if !escaped && vmaxvq_u8(vorrq_u8(equals, newlines)) == 0 {
                vst1q_u8(destination.add(written), vsubq_u8(bytes, offset));
                written += 16;
            } else {
                let equals_mask = vaddvq_u16(vandq_u16(
                    vreinterpretq_u16_u8(vzip1q_u8(equals, vextq_u8(equals, equals, 8))),
                    weights,
                ));
                let newline_mask = vaddvq_u16(vandq_u16(
                    vreinterpretq_u16_u8(vzip1q_u8(newlines, vextq_u8(newlines, newlines, 8))),
                    weights,
                ));
                // Eight independent marker/value pairs need no lookup compaction.
                // Incoming escape state selects even values and remains pending
                // because the final byte is then another marker.
                if newline_mask == 0
                    && ((!escaped && equals_mask == 0x5555) || (escaped && equals_mask == 0xaaaa))
                {
                    let values = if escaped {
                        vuzp1_u8(vget_low_u8(bytes), vget_high_u8(bytes))
                    } else {
                        vuzp2_u8(vget_low_u8(bytes), vget_high_u8(bytes))
                    };
                    vst1_u8(destination.add(written), vsub_u8(values, vdup_n_u8(106)));
                    written += 8;
                    read += 16;
                    continue;
                }
                let (remove, adjust) = block_masks(equals_mask, newline_mask, &mut escaped);
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
                    destination.add(written),
                    low_count,
                );
                store_word(
                    vget_lane_u64(vreinterpret_u64_u8(high), 0),
                    destination.add(written + low_count),
                    high_count,
                );
                written += low_count + high_count;
            }
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
        let mut actual = vec![0xcc; payload.len() + 32];
        let actual_len = unsafe {
            decode_neon_dense(payload.as_ptr(), actual.as_mut_ptr().add(16), payload.len())
        };
        assert_eq!(actual_len, expected_len, "payload {payload:?}");
        assert_eq!(actual, expected, "payload {payload:?}");
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
}
