use crate::payload_decoder::{COMPACT, ESCAPE_OFFSETS, block_masks, scalar_tail, store_word};

// The min/shuffle special-byte classifier follows RapidYenc's CC0 decoder:
// animetosho/rapidyenc, revision 480bd7b5896f8b3edecc721d23f1384d767ffe2f,
// src/decoder_avx2_base.h. Unlike its stores, these stores preserve every byte
// after the returned output length.
const SPECIAL_BYTES: [u8; 16] = [
    255, 255, 255, 255, 255, 255, 255, 255, 255, 255, b'\n', 255, 255, b'\r', b'=', 255,
];

// Neither SSSE3 nor AVX2 implies POPCNT. A small table avoids software popcounts
// on the generic kernels without assuming another optional CPU feature.
static KEPT_BYTES: [u8; 256] = {
    let mut counts = [0; 256];
    let mut mask = 0;
    while mask < 256 {
        counts[mask] = 8 - (mask as u8).count_ones() as u8;
        mask += 1;
    }
    counts
};

/// Join two zero-padded compacted words and write exactly their retained bytes.
#[inline]
unsafe fn store_pair(
    low: u64,
    high: u64,
    low_count: usize,
    high_count: usize,
    destination: *mut u8,
) {
    let count = low_count + high_count;
    let joined = u128::from(low) | (u128::from(high) << (low_count * 8));
    unsafe {
        if count >= 8 {
            destination
                .cast::<u64>()
                .write_unaligned((joined as u64).to_le());
            if count > 8 {
                // The second store overlaps the first when count < 16, but
                // both ranges are wholly inside [destination, destination+count).
                destination
                    .add(count - 8)
                    .cast::<u64>()
                    .write_unaligned(((joined >> ((count - 8) * 8)) as u64).to_le());
            }
        } else {
            store_word(joined as u64, destination, count);
        }
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
#[inline]
unsafe fn compact_ssse3(
    bytes: x86::__m128i,
    equals_vector: x86::__m128i,
    equals: u16,
    newlines: u16,
    escaped: &mut bool,
    destination: *mut u8,
) -> usize {
    use x86::*;
    unsafe {
        // An AVX2 block can have specials in only one half. Avoid compacting
        // its clean half, which still contributes sixteen complete output bytes.
        if !*escaped && equals | newlines == 0 {
            _mm_storeu_si128(destination.cast(), _mm_sub_epi8(bytes, _mm_set1_epi8(42)));
            return 16;
        }
        let following = equals.wrapping_shl(1);
        let incoming = *escaped;
        let (remove, offsets) = if following & (equals | newlines) == 0
            && (!incoming || (equals | newlines) & 1 == 0)
        {
            // A valid escape's value is the following byte. Keep this common
            // case in registers, including a value carried from the last block.
            *escaped = equals & 0x8000 != 0;
            let adjustments = _mm_or_si128(
                _mm_and_si128(_mm_slli_si128::<1>(equals_vector), _mm_set1_epi8(64)),
                _mm_cvtsi32_si128(i32::from(incoming) << 6),
            );
            (
                newlines | equals,
                _mm_add_epi8(_mm_set1_epi8(42), adjustments),
            )
        } else {
            // Preserve the lenient semantics for adjacent equals signs and
            // newlines inside an escape, including all-newline blocks.
            let (remove, adjust) = block_masks(equals, newlines, escaped);
            let low = _mm_loadl_epi64(ESCAPE_OFFSETS[(adjust & 255) as usize].as_ptr().cast());
            let high = _mm_loadl_epi64(ESCAPE_OFFSETS[(adjust >> 8) as usize].as_ptr().cast());
            (
                remove,
                _mm_add_epi8(_mm_set1_epi8(42), _mm_unpacklo_epi64(low, high)),
            )
        };
        let decoded = _mm_sub_epi8(bytes, offsets);
        let low = _mm_shuffle_epi8(
            decoded,
            _mm_loadl_epi64(COMPACT[(remove & 255) as usize].as_ptr().cast()),
        );
        let high = _mm_shuffle_epi8(
            _mm_srli_si128::<8>(decoded),
            _mm_loadl_epi64(COMPACT[(remove >> 8) as usize].as_ptr().cast()),
        );
        let low_count = KEPT_BYTES[(remove & 255) as usize] as usize;
        let high_count = KEPT_BYTES[(remove >> 8) as usize] as usize;
        let mut low_word = 0u64;
        let mut high_word = 0u64;
        _mm_storel_epi64(std::ptr::addr_of_mut!(low_word).cast(), low);
        _mm_storel_epi64(std::ptr::addr_of_mut!(high_word).cast(), high);
        store_pair(low_word, high_word, low_count, high_count, destination);
        low_count + high_count
    }
}

#[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
#[target_feature(enable = "ssse3")]
pub(crate) unsafe fn decode_ssse3(source: *const u8, destination: *mut u8, length: usize) -> usize {
    use x86::*;
    let mut read = 0;
    let mut written = 0;
    let mut escaped = false;
    unsafe {
        while length - read >= 16 {
            let bytes = _mm_loadu_si128(source.add(read).cast());
            let specials = _mm_movemask_epi8(_mm_cmpeq_epi8(
                bytes,
                _mm_shuffle_epi8(
                    _mm_loadu_si128(SPECIAL_BYTES.as_ptr().cast()),
                    _mm_min_epu8(bytes, _mm_set1_epi8(46)),
                ),
            )) as u16;
            if !escaped && specials == 0 {
                _mm_storeu_si128(
                    destination.add(written).cast(),
                    _mm_sub_epi8(bytes, _mm_set1_epi8(42)),
                );
                written += 16;
            } else {
                let equals_vector = _mm_cmpeq_epi8(bytes, _mm_set1_epi8(b'=' as i8));
                let equals = _mm_movemask_epi8(equals_vector) as u16;
                written += compact_ssse3(
                    bytes,
                    equals_vector,
                    equals,
                    specials & !equals,
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
pub(crate) unsafe fn decode_avx2(source: *const u8, destination: *mut u8, length: usize) -> usize {
    use x86::*;
    let mut read = 0;
    let mut written = 0;
    let mut escaped = false;
    unsafe {
        while length - read >= 32 {
            let bytes = _mm256_loadu_si256(source.add(read).cast());
            let specials = _mm256_movemask_epi8(_mm256_cmpeq_epi8(
                bytes,
                _mm256_shuffle_epi8(
                    _mm256_broadcastsi128_si256(_mm_loadu_si128(SPECIAL_BYTES.as_ptr().cast())),
                    _mm256_min_epu8(bytes, _mm256_set1_epi8(46)),
                ),
            )) as u32;
            if !escaped && specials == 0 {
                _mm256_storeu_si256(
                    destination.add(written).cast(),
                    _mm256_sub_epi8(bytes, _mm256_set1_epi8(42)),
                );
                written += 32;
            } else {
                let equals_vector = _mm256_cmpeq_epi8(bytes, _mm256_set1_epi8(b'=' as i8));
                let equals = _mm256_movemask_epi8(equals_vector) as u32;
                let newlines = specials & !equals;
                written += compact_ssse3(
                    _mm256_castsi256_si128(bytes),
                    _mm256_castsi256_si128(equals_vector),
                    equals as u16,
                    newlines as u16,
                    &mut escaped,
                    destination.add(written),
                );
                written += compact_ssse3(
                    _mm256_extracti128_si256::<1>(bytes),
                    _mm256_extracti128_si256::<1>(equals_vector),
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

    #[test]
    fn compact_word_pairs_preserve_all_bytes_after_the_result() {
        for low_count in 0..=8 {
            for high_count in 0..=8 {
                let low_bytes: Vec<u8> = (1..=low_count as u8).collect();
                let high_bytes: Vec<u8> = (101..101 + high_count as u8).collect();
                let mut low = [0; 8];
                let mut high = [0; 8];
                low[..low_count].copy_from_slice(&low_bytes);
                high[..high_count].copy_from_slice(&high_bytes);
                let expected: Vec<_> = low_bytes.into_iter().chain(high_bytes).collect();
                for alignment in 0..16 {
                    let mut output = [0xa5; 48];
                    unsafe {
                        store_pair(
                            u64::from_le_bytes(low),
                            u64::from_le_bytes(high),
                            low_count,
                            high_count,
                            output.as_mut_ptr().add(alignment),
                        );
                    }
                    assert_eq!(&output[alignment..alignment + expected.len()], expected);
                    assert!(output[..alignment].iter().all(|&byte| byte == 0xa5));
                    assert!(
                        output[alignment + expected.len()..]
                            .iter()
                            .all(|&byte| byte == 0xa5)
                    );
                }
            }
        }
    }

    #[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
    #[test]
    fn each_x86_kernel_matches_scalar_for_all_values_and_escape_boundaries() {
        let mut kernels: Vec<unsafe fn(*const u8, *mut u8, usize) -> usize> = Vec::new();
        if is_x86_feature_detected!("ssse3") {
            kernels.push(decode_ssse3);
        }
        if is_x86_feature_detected!("avx2") && is_x86_feature_detected!("ssse3") {
            kernels.push(decode_avx2);
        }
        for kernel in kernels {
            for value in 0..=u8::MAX {
                for alignment in 0..32 {
                    let mut input = vec![b'k'; alignment];
                    for _ in 0..40 {
                        input.extend_from_slice(&[b'=', value]);
                    }
                    input.extend_from_slice(b"=\r\n@==\n=\r=");
                    for length in [0, 1, 15, 16, 17, 31, 32, 33, input.len() - 1, input.len()] {
                        let mut expected = vec![0; length];
                        let expected_length = unsafe {
                            scalar_tail(input.as_ptr(), expected.as_mut_ptr(), length, 0, 0, false)
                        };
                        let mut output = vec![0xa5; length + 32];
                        let written =
                            unsafe { kernel(input.as_ptr(), output.as_mut_ptr(), length) };
                        assert_eq!(&output[..written], &expected[..expected_length]);
                        assert!(output[written..].iter().all(|&byte| byte == 0xa5));
                    }
                }
            }
        }
    }
}
