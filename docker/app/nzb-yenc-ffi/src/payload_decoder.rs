//! Payload-only yEnc decoding into the caller's storage. Framing and CRC checks
//! belong to the caller; CR/LF is discarded even inside an escape sequence.

static COMPACT: [[u8; 8]; 256] = compact_table();
static ESCAPE_OFFSETS: [[u8; 8]; 256] = escape_offset_table();

const fn compact_table() -> [[u8; 8]; 256] {
    let mut table = [[0x80; 8]; 256];
    let mut mask = 0;
    while mask < 256 {
        let mut source = 0;
        let mut destination = 0;
        while source < 8 {
            if mask & (1 << source) == 0 {
                table[mask][destination] = source as u8;
                destination += 1;
            }
            source += 1;
        }
        mask += 1;
    }
    table
}

const fn escape_offset_table() -> [[u8; 8]; 256] {
    let mut table = [[0; 8]; 256];
    let mut mask = 0;
    while mask < 256 {
        let mut lane = 0;
        while lane < 8 {
            if mask & (1 << lane) != 0 {
                table[mask][lane] = 64;
            }
            lane += 1;
        }
        mask += 1;
    }
    table
}

/// Decode bytes into a destination with capacity `length`, returning bytes written.
///
/// # Safety
/// The source must be readable and destination writable for `length` bytes.
/// Overlapping ranges, including in-place decoding, are supported.
pub unsafe fn decode_into(source: *const u8, destination: *mut u8, length: usize) -> usize {
    // Forward decoding only writes behind the source cursor. A destination that
    // begins inside the unread source needs a snapshot, as the old shim allowed.
    let source_address = source as usize;
    let destination_address = destination as usize;
    if destination_address > source_address && destination_address - source_address < length {
        let snapshot = unsafe { std::slice::from_raw_parts(source, length) }.to_vec();
        return unsafe { decode_forward(snapshot.as_ptr(), destination, length) };
    }
    unsafe { decode_forward(source, destination, length) }
}

unsafe fn decode_forward(source: *const u8, destination: *mut u8, length: usize) -> usize {
    #[cfg(target_arch = "aarch64")]
    {
        // AArch64 requires Advanced SIMD; no optional ISA is assumed here.
        return unsafe { decode_neon(source, destination, length) };
    }
    #[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
    {
        if is_x86_feature_detected!("avx2") && is_x86_feature_detected!("ssse3") {
            return unsafe { decode_avx2(source, destination, length) };
        }
        if is_x86_feature_detected!("ssse3") {
            return unsafe { decode_ssse3(source, destination, length) };
        }
    }
    #[allow(unreachable_code)]
    unsafe {
        scalar_tail(source, destination, length, 0, 0, false)
    }
}

/// Return removal and escaped-value masks for one sixteen-byte block.
#[inline]
fn block_masks(mut equals: u16, newlines: u16, escaped: &mut bool) -> (u16, u16) {
    let mut adjustments = 0u16;
    if *escaped {
        let available = !newlines;
        if available == 0 {
            return (newlines, 0);
        }
        let first = 1u16 << available.trailing_zeros();
        adjustments |= first;
        equals &= !first;
        *escaped = false;
    }

    let following = equals.wrapping_shl(1);
    // Ordinary yEnc escapes are separated by their value byte. Handle that
    // common case with masks, retaining a pending escape across block boundaries.
    if equals & following == 0 && newlines & following == 0 {
        *escaped = equals & 0x8000 != 0;
        return (newlines | equals, adjustments | following);
    }

    let mut removals = newlines;
    while equals != 0 {
        let position = equals.trailing_zeros();
        let marker = 1u16 << position;
        equals &= !marker;
        removals |= marker;
        let available = if position == 15 {
            0
        } else {
            !newlines & (u16::MAX << (position + 1))
        };
        if available == 0 {
            *escaped = true;
            break;
        }
        let value = 1u16 << available.trailing_zeros();
        adjustments |= value;
        equals &= !value;
    }
    (removals, adjustments)
}

/// Store only selected output bytes, preserving storage beyond the returned length.
#[inline]
unsafe fn store_word(mut word: u64, mut destination: *mut u8, count: usize) {
    unsafe {
        if count == 8 {
            destination.cast::<u64>().write_unaligned(word.to_le());
            return;
        }
        if count & 4 != 0 {
            destination
                .cast::<u32>()
                .write_unaligned((word as u32).to_le());
            destination = destination.add(4);
            word >>= 32;
        }
        if count & 2 != 0 {
            destination
                .cast::<u16>()
                .write_unaligned((word as u16).to_le());
            destination = destination.add(2);
            word >>= 16;
        }
        if count & 1 != 0 {
            destination.write(word as u8);
        }
    }
}

#[inline]
unsafe fn scalar_tail(
    source: *const u8,
    destination: *mut u8,
    length: usize,
    mut read: usize,
    mut written: usize,
    mut escaped: bool,
) -> usize {
    unsafe {
        while read < length {
            let byte = source.add(read).read();
            read += 1;
            if byte == b'\r' || byte == b'\n' {
                continue;
            }
            if escaped {
                destination.add(written).write(byte.wrapping_sub(106));
                written += 1;
                escaped = false;
            } else if byte == b'=' {
                escaped = true;
            } else {
                destination.add(written).write(byte.wrapping_sub(42));
                written += 1;
            }
        }
        if escaped {
            // The previous lenient shim treated an unpaired final '=' as data.
            destination.add(written).write(b'='.wrapping_sub(42));
            written += 1;
        }
    }
    written
}

#[cfg(target_arch = "aarch64")]
#[target_feature(enable = "neon")]
unsafe fn decode_neon(source: *const u8, destination: *mut u8, length: usize) -> usize {
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

#[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
mod x86 {
    #[cfg(target_arch = "x86")]
    pub use std::arch::x86::*;
    #[cfg(target_arch = "x86_64")]
    pub use std::arch::x86_64::*;
}

#[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
#[target_feature(enable = "ssse3")]
unsafe fn compact_ssse3(
    bytes: x86::__m128i,
    equals: u16,
    newlines: u16,
    escaped: &mut bool,
    destination: *mut u8,
) -> usize {
    use x86::*;
    unsafe {
        let (remove, adjust) = block_masks(equals, newlines, escaped);
        let low_offsets = _mm_loadl_epi64(ESCAPE_OFFSETS[(adjust & 255) as usize].as_ptr().cast());
        let high_offsets = _mm_loadl_epi64(ESCAPE_OFFSETS[(adjust >> 8) as usize].as_ptr().cast());
        let offsets = _mm_add_epi8(
            _mm_set1_epi8(42),
            _mm_unpacklo_epi64(low_offsets, high_offsets),
        );
        let decoded = _mm_sub_epi8(bytes, offsets);
        let low = _mm_shuffle_epi8(
            decoded,
            _mm_loadl_epi64(COMPACT[(remove & 255) as usize].as_ptr().cast()),
        );
        let high = _mm_shuffle_epi8(
            _mm_srli_si128::<8>(decoded),
            _mm_loadl_epi64(COMPACT[(remove >> 8) as usize].as_ptr().cast()),
        );
        let low_count = 8 - (remove & 255).count_ones() as usize;
        let high_count = 8 - (remove >> 8).count_ones() as usize;
        let mut low_word = 0u64;
        let mut high_word = 0u64;
        _mm_storel_epi64(std::ptr::addr_of_mut!(low_word).cast(), low);
        _mm_storel_epi64(std::ptr::addr_of_mut!(high_word).cast(), high);
        store_word(low_word, destination, low_count);
        store_word(high_word, destination.add(low_count), high_count);
        low_count + high_count
    }
}

#[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
#[target_feature(enable = "ssse3")]
unsafe fn decode_ssse3(source: *const u8, destination: *mut u8, length: usize) -> usize {
    use x86::*;
    let mut read = 0;
    let mut written = 0;
    let mut escaped = false;
    unsafe {
        while length - read >= 16 {
            let bytes = _mm_loadu_si128(source.add(read).cast());
            let equals = _mm_movemask_epi8(_mm_cmpeq_epi8(bytes, _mm_set1_epi8(b'=' as i8))) as u16;
            let newlines = _mm_movemask_epi8(_mm_or_si128(
                _mm_cmpeq_epi8(bytes, _mm_set1_epi8(b'\r' as i8)),
                _mm_cmpeq_epi8(bytes, _mm_set1_epi8(b'\n' as i8)),
            )) as u16;
            if !escaped && equals | newlines == 0 {
                _mm_storeu_si128(
                    destination.add(written).cast(),
                    _mm_sub_epi8(bytes, _mm_set1_epi8(42)),
                );
                written += 16;
            } else {
                written += compact_ssse3(
                    bytes,
                    equals,
                    newlines,
                    &mut escaped,
                    destination.add(written),
                );
            }
            read += 16;
        }
        scalar_tail(source, destination, length, read, written, escaped)
    }
}

#[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
#[target_feature(enable = "avx2")]
unsafe fn decode_avx2(source: *const u8, destination: *mut u8, length: usize) -> usize {
    use x86::*;
    let mut read = 0;
    let mut written = 0;
    let mut escaped = false;
    unsafe {
        while length - read >= 32 {
            let bytes = _mm256_loadu_si256(source.add(read).cast());
            let equals =
                _mm256_movemask_epi8(_mm256_cmpeq_epi8(bytes, _mm256_set1_epi8(b'=' as i8))) as u32;
            let newlines = _mm256_movemask_epi8(_mm256_or_si256(
                _mm256_cmpeq_epi8(bytes, _mm256_set1_epi8(b'\r' as i8)),
                _mm256_cmpeq_epi8(bytes, _mm256_set1_epi8(b'\n' as i8)),
            )) as u32;
            if !escaped && equals | newlines == 0 {
                _mm256_storeu_si256(
                    destination.add(written).cast(),
                    _mm256_sub_epi8(bytes, _mm256_set1_epi8(42)),
                );
                written += 32;
            } else {
                written += compact_ssse3(
                    _mm256_castsi256_si128(bytes),
                    equals as u16,
                    newlines as u16,
                    &mut escaped,
                    destination.add(written),
                );
                written += compact_ssse3(
                    _mm256_extracti128_si256::<1>(bytes),
                    (equals >> 16) as u16,
                    (newlines >> 16) as u16,
                    &mut escaped,
                    destination.add(written),
                );
            }
            read += 32;
        }
        scalar_tail(source, destination, length, read, written, escaped)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn scalar_reference(payload: &[u8]) -> Vec<u8> {
        let flattened: Vec<_> = payload
            .iter()
            .copied()
            .filter(|byte| !matches!(byte, b'\r' | b'\n'))
            .collect();
        let mut expected = Vec::new();
        let mut position = 0;
        while position < flattened.len() {
            let byte = flattened[position];
            if byte == b'=' && position + 1 < flattened.len() {
                expected.push(flattened[position + 1].wrapping_sub(106));
                position += 2;
            } else {
                expected.push(byte.wrapping_sub(42));
                position += 1;
            }
        }
        expected
    }

    #[test]
    fn direct_output_matches_lenient_scalar_with_boundary_guards() {
        let cases: &[&[u8]] = &[
            b"",
            b"\r\n",
            b"=",
            b"=\r\n",
            b"=\r\n@",
            b"=y=yend",
            b"..\r\n.",
            b"===\r\n=",
            b"=@=J=M=}",
            b"=\r\n=\r\n=\r\n=\r\n=",
        ];
        for prefix_length in 0..65 {
            for case in cases {
                let mut payload = vec![b'k'; prefix_length];
                payload.extend_from_slice(case);
                payload.extend_from_slice(&[b'='; 33]);
                let expected = scalar_reference(&payload);
                let mut output = vec![0xcc; payload.len() + 32];
                let written = unsafe {
                    decode_into(payload.as_ptr(), output.as_mut_ptr().add(16), payload.len())
                };
                assert_eq!(
                    &output[16..16 + written],
                    expected,
                    "prefix {prefix_length}, case {case:?}"
                );
                assert!(output[..16].iter().all(|byte| *byte == 0xcc));
                assert!(output[16 + written..].iter().all(|byte| *byte == 0xcc));
            }
        }
    }

    #[test]
    fn direct_output_matches_scalar_for_seeded_arbitrary_payloads() {
        let mut state = 0x76f9_48d2_01ab_5c37u64;
        for length in 0..513 {
            let mut payload = Vec::new();
            for _ in 0..length {
                state ^= state << 13;
                state ^= state >> 7;
                state ^= state << 17;
                let choice = state as u8;
                payload.push(match choice % 8 {
                    0 => b'=',
                    1 => b'\r',
                    2 => b'\n',
                    _ => choice,
                });
            }
            let expected = scalar_reference(&payload);
            let mut output = vec![0xcc; payload.len() + 8];
            let written =
                unsafe { decode_into(payload.as_ptr(), output.as_mut_ptr(), payload.len()) };
            assert_eq!(&output[..written], expected, "length {length}");
            assert!(output[written..].iter().all(|byte| *byte == 0xcc));
        }
    }

    #[test]
    fn supports_in_place_and_both_overlap_directions() {
        let payload = b"klm=@=J=M=}..\x0b==\r\n===\r\n@".repeat(32);
        let expected = scalar_reference(&payload);
        for (source_start, destination_start) in [(0, 0), (0, 3), (3, 0)] {
            let mut storage = vec![0xcc; payload.len() + 32];
            storage[source_start..source_start + payload.len()].copy_from_slice(&payload);
            let written = unsafe {
                decode_into(
                    storage.as_ptr().add(source_start),
                    storage.as_mut_ptr().add(destination_start),
                    payload.len(),
                )
            };
            assert_eq!(
                &storage[destination_start..destination_start + written],
                expected
            );
        }
    }

    #[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
    #[test]
    fn each_available_x86_kernel_matches_scalar_at_boundaries() {
        for prefix_length in 0..65 {
            let mut payload = vec![b'k'; prefix_length];
            payload.extend_from_slice(&b"=y=@\r\n===\r\n=\r\n@=J=M=}..\x0b==".repeat(65));
            let expected = scalar_reference(&payload);
            let mut output = vec![0xcc; payload.len() + 8];
            if is_x86_feature_detected!("ssse3") {
                let written =
                    unsafe { decode_ssse3(payload.as_ptr(), output.as_mut_ptr(), payload.len()) };
                assert_eq!(&output[..written], expected);
                assert!(output[written..].iter().all(|byte| *byte == 0xcc));
            }
            output.fill(0xcc);
            if is_x86_feature_detected!("avx2") && is_x86_feature_detected!("ssse3") {
                let written =
                    unsafe { decode_avx2(payload.as_ptr(), output.as_mut_ptr(), payload.len()) };
                assert_eq!(&output[..written], expected);
                assert!(output[written..].iter().all(|byte| *byte == 0xcc));
            }
        }
    }

    #[test]
    fn scalar_fallback_matches_vector_dispatch() {
        let payload = b"=y=@\r\n===\r\n=\r\n@=J=M=}..\x0b==".repeat(65);
        let expected = scalar_reference(&payload);
        let mut output = vec![0; payload.len()];
        let written = unsafe {
            scalar_tail(
                payload.as_ptr(),
                output.as_mut_ptr(),
                payload.len(),
                0,
                0,
                false,
            )
        };
        assert_eq!(&output[..written], expected);
    }
}
