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

// Sparse ordinary payload blocks usually remove one escape marker or one CRLF
// pair. A whole-vector shuffle avoids joining two compacted scalar words.
const REMOVE_ONE: [[u8; 16]; 16] = removal_shuffles::<16>(1);
const REMOVE_PAIR: [[u8; 16]; 15] = removal_shuffles::<15>(2);

const fn removal_shuffles<const POSITIONS: usize>(removed: usize) -> [[u8; 16]; POSITIONS] {
    let mut table = [[0x80; 16]; POSITIONS];
    let mut position = 0;
    while position < POSITIONS {
        let mut source = 0;
        let mut destination = 0;
        while source < 16 {
            if source < position || source >= position + removed {
                table[position][destination] = source as u8;
                destination += 1;
            }
            source += 1;
        }
        position += 1;
    }
    table
}

// Each AVX2 lane gathers one sixteen-byte output window. The windows
// overlap by one or two bytes, so both full stores remain inside the result.
// The second mask gathers bytes from the opposite source lane.
const SPARSE_ONE: [[[u8; 32]; 2]; 32] = sparse_shuffles::<32>(1);
const SPARSE_PAIR: [[[u8; 32]; 2]; 31] = sparse_shuffles::<31>(2);

const fn sparse_shuffles<const POSITIONS: usize>(removed: usize) -> [[[u8; 32]; 2]; POSITIONS] {
    let mut table = [[[0x80; 32]; 2]; POSITIONS];
    let mut position = 0;
    while position < POSITIONS {
        let mut output_lane = 0;
        while output_lane < 2 {
            let start = if output_lane == 0 { 0 } else { 16 - removed };
            let mut offset = 0;
            while offset < 16 {
                let output_index = start + offset;
                let source_index = if output_index < position {
                    output_index
                } else {
                    output_index + removed
                };
                let opposite = (source_index / 16 != output_lane) as usize;
                table[position][opposite][output_lane * 16 + offset] = (source_index % 16) as u8;
                offset += 1;
            }
            output_lane += 1;
        }
        position += 1;
    }
    table
}

#[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
#[target_feature(enable = "avx2")]
#[inline]
unsafe fn store_sparse_avx2(
    decoded: x86::__m256i,
    masks: &[[u8; 32]; 2],
    count: usize,
    destination: *mut u8,
) {
    use x86::*;
    unsafe {
        let packed = _mm256_or_si256(
            _mm256_shuffle_epi8(decoded, _mm256_loadu_si256(masks[0].as_ptr().cast())),
            _mm256_shuffle_epi8(
                _mm256_permute4x64_epi64::<0x4e>(decoded),
                _mm256_loadu_si256(masks[1].as_ptr().cast()),
            ),
        );
        _mm_storeu_si128(destination.cast(), _mm256_castsi256_si128(packed));
        _mm_storeu_si128(
            destination.add(count - 16).cast(),
            _mm256_extracti128_si256::<1>(packed),
        );
    }
}

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
        if newlines == 0 && ((!*escaped && equals == 0x5555) || (*escaped && equals == 0xaaaa)) {
            // Exact alternating marker/value pairs retain eight bytes. If a
            // previous marker is pending, values occupy even lanes and the
            // final marker remains pending; otherwise values occupy odd lanes.
            let indices = if *escaped {
                _mm_setr_epi8(
                    0, 2, 4, 6, 8, 10, 12, 14, -128, -128, -128, -128, -128, -128, -128, -128,
                )
            } else {
                _mm_setr_epi8(
                    1, 3, 5, 7, 9, 11, 13, 15, -128, -128, -128, -128, -128, -128, -128, -128,
                )
            };
            let decoded = _mm_shuffle_epi8(_mm_sub_epi8(bytes, _mm_set1_epi8(106)), indices);
            _mm_storel_epi64(destination.cast(), decoded);
            return 8;
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
        if remove == 0 {
            _mm_storeu_si128(destination.cast(), decoded);
            return 16;
        }
        let first_removed = remove.trailing_zeros() as usize;
        if remove.is_power_of_two() {
            let packed = _mm_shuffle_epi8(
                decoded,
                _mm_loadu_si128(REMOVE_ONE[first_removed].as_ptr().cast()),
            );
            // Two overlapping eight-byte stores cover exactly fifteen bytes.
            _mm_storel_epi64(destination.cast(), packed);
            _mm_storel_epi64(destination.add(7).cast(), _mm_srli_si128::<7>(packed));
            return 15;
        }
        if remove >> first_removed == 3 {
            let packed = _mm_shuffle_epi8(
                decoded,
                _mm_loadu_si128(REMOVE_PAIR[first_removed].as_ptr().cast()),
            );
            _mm_storel_epi64(destination.cast(), packed);
            _mm_storel_epi64(destination.add(6).cast(), _mm_srli_si128::<6>(packed));
            return 14;
        }
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
            // Keep wrapped payloads aligned after full encoded lines. CR/LF
            // are ignored even while an escape value is pending; preserve
            // that state and leave a short remaining span to the scalar tail.
            if matches!(source.add(read).read(), b'\r' | b'\n') {
                read += 1;
                while read < length && matches!(source.add(read).read(), b'\r' | b'\n') {
                    read += 1;
                }
                continue;
            }
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
                if newlines == 0
                    && ((!escaped && equals == 0x5555_5555) || (escaped && equals == 0xaaaa_aaaa))
                {
                    let indices = if escaped {
                        _mm_setr_epi8(
                            0, 2, 4, 6, 8, 10, 12, 14, -128, -128, -128, -128, -128, -128, -128,
                            -128,
                        )
                    } else {
                        _mm_setr_epi8(
                            1, 3, 5, 7, 9, 11, 13, 15, -128, -128, -128, -128, -128, -128, -128,
                            -128,
                        )
                    };
                    let packed = _mm256_shuffle_epi8(
                        _mm256_sub_epi8(bytes, _mm256_set1_epi8(106)),
                        _mm256_broadcastsi128_si256(indices),
                    );
                    let contiguous = _mm_unpacklo_epi64(
                        _mm256_castsi256_si128(packed),
                        _mm256_extracti128_si256::<1>(packed),
                    );
                    _mm_storeu_si128(destination.add(written).cast(), contiguous);
                    written += 16;
                    read += 32;
                    continue;
                }
                if !escaped {
                    // A single special cannot contain a malformed escape. An
                    // escape in lane 15 needs a cross-lane value adjustment;
                    // retain the established general path for that boundary.
                    let position = specials.trailing_zeros() as usize;
                    let single = specials.is_power_of_two() && equals & 0x8000 == 0;
                    let newline_pair = equals == 0 && specials >> position == 3;
                    if single || newline_pair {
                        let decoded = _mm256_sub_epi8(
                            _mm256_sub_epi8(bytes, _mm256_set1_epi8(42)),
                            _mm256_and_si256(
                                _mm256_slli_si256::<1>(equals_vector),
                                _mm256_set1_epi8(64),
                            ),
                        );
                        let (masks, count) = if single {
                            (&SPARSE_ONE[position], 31)
                        } else {
                            (&SPARSE_PAIR[position], 30)
                        };
                        // Both input lanes are already loaded. The overlapping
                        // stores are safe for in-place and destination-behind
                        // decoding, and neither store reaches beyond count.
                        store_sparse_avx2(decoded, masks, count, destination.add(written));
                        escaped = equals & 0x8000_0000 != 0;
                        written += count;
                        read += 32;
                        continue;
                    }
                }
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

// Register compression avoids a memory-form compress-store. The final masked
// store writes only the contiguous decoded prefix, never the unused suffix.
#[target_feature(enable = "avx2,avx512vbmi2,avx512bw,avx512vl,popcnt")]
#[inline]
unsafe fn compact_vbmi2(
    bytes: x86::__m256i,
    specials: u32,
    destination: *mut u8,
    escaped: &mut bool,
) -> usize {
    use x86::*;
    unsafe {
        if !*escaped && specials == 0 {
            _mm256_storeu_si256(
                destination.cast(),
                _mm256_sub_epi8(bytes, _mm256_set1_epi8(42)),
            );
            return 32;
        }
        let equals =
            _mm256_movemask_epi8(_mm256_cmpeq_epi8(bytes, _mm256_set1_epi8(b'=' as i8))) as u32;
        let newlines = specials & !equals;
        let incoming = *escaped;
        if newlines == 0
            && ((!incoming && equals == 0x5555_5555) || (incoming && equals == 0xaaaa_aaaa))
        {
            let packed =
                _mm256_maskz_compress_epi8(!equals, _mm256_sub_epi8(bytes, _mm256_set1_epi8(106)));
            _mm_storeu_si128(destination.cast(), _mm256_castsi256_si128(packed));
            return 16;
        }
        let following = equals.wrapping_shl(1);
        let (remove, adjust) = if following & (equals | newlines) == 0
            && (!incoming || (equals | newlines) & 1 == 0)
        {
            *escaped = equals & 0x8000_0000 != 0;
            (equals | newlines, following | u32::from(incoming))
        } else {
            let (lo_remove, lo_adjust) = block_masks(equals as u16, newlines as u16, escaped);
            let (hi_remove, hi_adjust) =
                block_masks((equals >> 16) as u16, (newlines >> 16) as u16, escaped);
            (
                u32::from(lo_remove) | (u32::from(hi_remove) << 16),
                u32::from(lo_adjust) | (u32::from(hi_adjust) << 16),
            )
        };
        // Expand the full 32-bit mask instead of a lane-local byte shift:
        // an escape marker in byte 15 still adjusts its value in byte 16.
        let offsets = _mm256_add_epi8(
            _mm256_set1_epi8(42),
            _mm256_and_si256(_mm256_movm_epi8(adjust), _mm256_set1_epi8(64)),
        );
        let keep = !remove;
        let count = keep.count_ones() as usize;
        if count != 0 {
            let packed = _mm256_maskz_compress_epi8(keep, _mm256_sub_epi8(bytes, offsets));
            let prefix = if count == 32 {
                u32::MAX
            } else {
                (1u32 << count) - 1
            };
            _mm256_mask_storeu_epi8(destination.cast(), prefix, packed);
        }
        count
    }
}

#[target_feature(enable = "avx2,avx512vbmi2,avx512bw,avx512vl,popcnt")]
#[inline]
unsafe fn vbmi2_specials(bytes: x86::__m256i) -> u32 {
    use x86::*;
    unsafe {
        _mm256_movemask_epi8(_mm256_cmpeq_epi8(
            bytes,
            _mm256_shuffle_epi8(
                _mm256_broadcastsi128_si256(_mm_loadu_si128(SPECIAL_BYTES.as_ptr().cast())),
                _mm256_min_epu8(bytes, _mm256_set1_epi8(46)),
            ),
        )) as u32
    }
}

// Join the already loaded halves before any output write. One register
// compression handles removals across byte 31 without joining two outputs.
#[target_feature(enable = "avx2,avx512f,avx512vbmi2,avx512bw,avx512vl,popcnt")]
#[inline]
unsafe fn compact_wide_vbmi2(
    low: x86::__m256i,
    high: x86::__m256i,
    specials: u64,
    destination: *mut u8,
    escaped: &mut bool,
) -> usize {
    use x86::*;
    unsafe {
        let bytes = _mm512_inserti64x4::<1>(_mm512_castsi256_si512(low), high);
        let equals = _mm512_cmpeq_epi8_mask(bytes, _mm512_set1_epi8(b'=' as i8));
        let newlines = specials & !equals;
        let incoming = *escaped;
        if newlines == 0
            && ((!incoming && equals == 0x5555_5555_5555_5555)
                || (incoming && equals == 0xaaaa_aaaa_aaaa_aaaa))
        {
            let packed =
                _mm512_maskz_compress_epi8(!equals, _mm512_sub_epi8(bytes, _mm512_set1_epi8(106)));
            _mm256_storeu_si256(destination.cast(), _mm512_castsi512_si256(packed));
            return 32;
        }
        let following = equals.wrapping_shl(1);
        let (remove, adjust) = if following & (equals | newlines) == 0
            && (!incoming || (equals | newlines) & 1 == 0)
        {
            *escaped = equals & (1u64 << 63) != 0;
            (equals | newlines, following | u64::from(incoming))
        } else {
            let mut remove = 0u64;
            let mut adjust = 0u64;
            for shift in [0, 16, 32, 48] {
                let (lane_remove, lane_adjust) = block_masks(
                    (equals >> shift) as u16,
                    (newlines >> shift) as u16,
                    escaped,
                );
                remove |= u64::from(lane_remove) << shift;
                adjust |= u64::from(lane_adjust) << shift;
            }
            (remove, adjust)
        };
        let keep = !remove;
        let count = keep.count_ones() as usize;
        if count != 0 {
            let decoded = _mm512_sub_epi8(bytes, _mm512_set1_epi8(42));
            let decoded = _mm512_mask_sub_epi8(decoded, adjust, decoded, _mm512_set1_epi8(64));
            let packed = _mm512_maskz_compress_epi8(keep, decoded);
            let prefix = if count == 64 {
                u64::MAX
            } else {
                (1u64 << count) - 1
            };
            _mm512_mask_storeu_epi8(destination.cast(), prefix, packed);
        }
        count
    }
}

#[target_feature(enable = "avx2,avx512f,avx512vbmi2,avx512bw,avx512vl,popcnt")]
pub(crate) unsafe fn decode_vbmi2(source: *const u8, destination: *mut u8, length: usize) -> usize {
    use x86::*;
    let mut read = 0;
    let mut written = 0;
    let mut escaped = false;
    unsafe {
        while length - read >= 64 {
            if matches!(source.add(read).read(), b'\r' | b'\n') {
                read += 1;
                continue;
            }
            // Load both vectors before any store, including overlapping output.
            let low = _mm256_loadu_si256(source.add(read).cast());
            let high = _mm256_loadu_si256(source.add(read + 32).cast());
            let low_specials = vbmi2_specials(low);
            let high_specials = vbmi2_specials(high);
            if !escaped && low_specials | high_specials == 0 {
                _mm256_storeu_si256(
                    destination.add(written).cast(),
                    _mm256_sub_epi8(low, _mm256_set1_epi8(42)),
                );
                _mm256_storeu_si256(
                    destination.add(written + 32).cast(),
                    _mm256_sub_epi8(high, _mm256_set1_epi8(42)),
                );
                written += 64;
            } else {
                written += compact_wide_vbmi2(
                    low,
                    high,
                    u64::from(low_specials) | (u64::from(high_specials) << 32),
                    destination.add(written),
                    &mut escaped,
                );
            }
            read += 64;
        }
        while length - read >= 32 {
            let bytes = _mm256_loadu_si256(source.add(read).cast());
            written += compact_vbmi2(
                bytes,
                vbmi2_specials(bytes),
                destination.add(written),
                &mut escaped,
            );
            read += 32;
        }
        scalar_tail(source, destination, length, read, written, escaped)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn vbmi2_kernel_preserves_escape_carry_and_exact_output_for_every_boundary() {
        if !is_x86_feature_detected!("avx2")
            || !is_x86_feature_detected!("avx512f")
            || !is_x86_feature_detected!("avx512vbmi2")
            || !is_x86_feature_detected!("avx512bw")
            || !is_x86_feature_detected!("avx512vl")
            || !is_x86_feature_detected!("popcnt")
        {
            return;
        }
        assert_eq!(crate::payload_decoder::selected_kernel(), 3);
        // Byte 31 carries an escape into a completely ordinary second vector:
        // its full 32-byte prefix store must adjust byte zero and touch no tail.
        let mut carry_input = [b'k'; 64];
        carry_input[31] = b'=';
        let mut carry_output = [0xa5; 96];
        let carry_written = unsafe {
            decode_vbmi2(
                carry_input.as_ptr(),
                carry_output.as_mut_ptr(),
                carry_input.len(),
            )
        };
        assert_eq!(carry_written, 63);
        assert!(carry_output[..31].iter().all(|&byte| byte == b'A'));
        assert_eq!(carry_output[31], 1);
        assert!(carry_output[32..63].iter().all(|&byte| byte == b'A'));
        assert!(carry_output[63..].iter().all(|&byte| byte == 0xa5));
        let tails: &[&[u8]] = &[b"", b"=", b"==", b"=\r\n@", b"\r\n\r\n", b"===\n=\r="];
        for boundary in 0..=65 {
            for special in [b"=@".as_slice(), b"==", b"\r\n", b"=\r\n@"] {
                for tail in tails {
                    let mut input = vec![b'k'; boundary];
                    input.extend_from_slice(special);
                    input.extend_from_slice(&[b'\n'; 64]);
                    input.extend_from_slice(b"=@=J=M=}==");
                    input.extend_from_slice(&[b'k'; 67]);
                    input.extend_from_slice(tail);
                    let mut expected = vec![0; input.len()];
                    let count = unsafe {
                        scalar_tail(
                            input.as_ptr(),
                            expected.as_mut_ptr(),
                            input.len(),
                            0,
                            0,
                            false,
                        )
                    };
                    for alignment in 0..64 {
                        let mut output = vec![0xa5; input.len() + 64];
                        let written = unsafe {
                            decode_vbmi2(
                                input.as_ptr(),
                                output.as_mut_ptr().add(alignment),
                                input.len(),
                            )
                        };
                        assert_eq!(written, count);
                        assert_eq!(&output[alignment..alignment + count], &expected[..count]);
                        assert!(output[..alignment].iter().all(|&byte| byte == 0xa5));
                        assert!(output[alignment + count..].iter().all(|&byte| byte == 0xa5));
                    }
                    for behind in [0, 1, 7, 15, 31, 63] {
                        let mut overlap = vec![0xa5; input.len() + behind + 32];
                        overlap[behind..behind + input.len()].copy_from_slice(&input);
                        let before = overlap.clone();
                        let written = unsafe {
                            decode_vbmi2(
                                overlap.as_ptr().add(behind),
                                overlap.as_mut_ptr(),
                                input.len(),
                            )
                        };
                        assert_eq!(&overlap[..written], &expected[..count]);
                        assert_eq!(&overlap[written..], &before[written..]);
                    }
                }
            }
        }
    }

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
        if is_x86_feature_detected!("avx2")
            && is_x86_feature_detected!("avx512f")
            && is_x86_feature_detected!("avx512vbmi2")
            && is_x86_feature_detected!("avx512bw")
            && is_x86_feature_detected!("avx512vl")
            && is_x86_feature_detected!("popcnt")
        {
            kernels.push(decode_vbmi2);
        }
        for kernel in kernels {
            for value in 0..=u8::MAX {
                for alignment in 0..32 {
                    let mut input = vec![b'k'; alignment];
                    for _ in 0..80 {
                        input.extend_from_slice(&[b'=', value]);
                    }
                    input.extend_from_slice(b"=\r\n@==\n=\r=");
                    for length in [
                        0,
                        1,
                        15,
                        16,
                        17,
                        31,
                        32,
                        33,
                        63,
                        64,
                        65,
                        127,
                        128,
                        129,
                        input.len() - 1,
                        input.len(),
                    ] {
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

    #[test]
    fn sparse_avx2_blocks_preserve_exact_output_for_every_removal_and_tail() {
        if !is_x86_feature_detected!("avx2") || !is_x86_feature_detected!("ssse3") {
            return;
        }
        for position in 0..32 {
            for special in [b"=".as_slice(), b"\r", b"\n", b"\r\n", b"\n\r"] {
                let tails: Vec<Vec<u8>> = (0..32)
                    .map(|length| vec![b'k'; length])
                    .chain([
                        b"=".to_vec(),
                        b"\r\n".to_vec(),
                        b"=\r\n@".to_vec(),
                        b"==".to_vec(),
                    ])
                    .collect();
                for tail in tails {
                    let mut payload = vec![b'k'; 32];
                    payload.splice(position..position + 1, special.iter().copied());
                    payload.extend_from_slice(&tail);
                    let mut reference = vec![0; payload.len()];
                    let expected = unsafe {
                        scalar_tail(
                            payload.as_ptr(),
                            reference.as_mut_ptr(),
                            payload.len(),
                            0,
                            0,
                            false,
                        )
                    };
                    for destination_offset in 0..32 {
                        let mut output = vec![0xa5; payload.len() + 64];
                        let written = unsafe {
                            decode_avx2(
                                payload.as_ptr(),
                                output.as_mut_ptr().add(destination_offset),
                                payload.len(),
                            )
                        };
                        assert_eq!(
                            written, expected,
                            "position {position}, special {special:?}, tail {tail:?}"
                        );
                        assert_eq!(
                            &output[destination_offset..destination_offset + written],
                            &reference[..expected]
                        );
                        assert!(
                            output[..destination_offset]
                                .iter()
                                .all(|&byte| byte == 0xa5)
                        );
                        assert!(
                            output[destination_offset + written..]
                                .iter()
                                .all(|&byte| byte == 0xa5)
                        );
                    }
                }
            }
        }
    }

    #[test]
    fn wrapped_avx2_payloads_preserve_escape_carry_and_unused_storage() {
        if !is_x86_feature_detected!("avx2") || !is_x86_feature_detected!("ssse3") {
            return;
        }
        for width in [31, 32, 33, 63, 64, 65, 127, 128, 129] {
            for tail_length in 0..32 {
                for prefix in [b"".as_slice(), b"=", b"\r\n", b"=\r\n"] {
                    let mut payload = prefix.to_vec();
                    for _ in 0..5 {
                        payload.extend(
                            (0..width).map(|index| if index % 2 == 0 { b'=' } else { b'@' }),
                        );
                        payload.extend_from_slice(b"\r\n");
                    }
                    payload.extend(std::iter::repeat_n(b'k', tail_length));
                    payload.extend_from_slice(b"=\r\n");
                    let mut reference = vec![0; payload.len()];
                    let expected = unsafe {
                        scalar_tail(
                            payload.as_ptr(),
                            reference.as_mut_ptr(),
                            payload.len(),
                            0,
                            0,
                            false,
                        )
                    };
                    let mut output = vec![0xa5; payload.len() + 32];
                    let written = unsafe {
                        decode_avx2(payload.as_ptr(), output.as_mut_ptr(), payload.len())
                    };
                    assert_eq!(
                        written, expected,
                        "width {width}, tail {tail_length}, prefix {prefix:?}"
                    );
                    assert_eq!(&output[..written], &reference[..expected]);
                    assert!(output[written..].iter().all(|&byte| byte == 0xa5));
                    let mut in_place = payload.clone();
                    in_place.extend_from_slice(&[0xa5; 32]);
                    let untouched = in_place[expected..].to_vec();
                    let written = unsafe {
                        decode_avx2(in_place.as_ptr(), in_place.as_mut_ptr(), payload.len())
                    };
                    assert_eq!(written, expected);
                    assert_eq!(&in_place[..written], &reference[..expected]);
                    assert_eq!(&in_place[written..], &untouched);
                }
            }
        }
    }

    #[test]
    fn sparse_removals_and_dense_carries_preserve_overlapping_storage() {
        let mut kernels: Vec<unsafe fn(*const u8, *mut u8, usize) -> usize> = Vec::new();
        if is_x86_feature_detected!("ssse3") {
            kernels.push(decode_ssse3);
        }
        if is_x86_feature_detected!("avx2") && is_x86_feature_detected!("ssse3") {
            kernels.push(decode_avx2);
        }
        let mut payloads = Vec::new();
        for position in 0..32 {
            let mut payload = vec![b'k'; 96];
            payload[position] = b'=';
            payloads.push(payload);
            let mut payload = vec![b'k'; 96];
            payload[position] = b'\r';
            payload[position + 1] = b'\n';
            payloads.push(payload);
        }
        for prefix in 0..33 {
            let mut payload = vec![b'k'; prefix];
            payload.extend_from_slice(&b"=@=J=M=}".repeat(40));
            payload.extend_from_slice(b"=\r\n=\n\r");
            payload.extend_from_slice(&b"@=".repeat(40));
            payload.extend_from_slice(b"\r\n");
            payloads.push(payload);
        }
        for kernel in kernels {
            for payload in &payloads {
                let mut reference = vec![0; payload.len()];
                let reference_len = unsafe {
                    scalar_tail(
                        payload.as_ptr(),
                        reference.as_mut_ptr(),
                        payload.len(),
                        0,
                        0,
                        false,
                    )
                };
                for (source_start, destination_start) in
                    [(32, 32), (32, 33), (33, 32), (32, 63), (63, 32)]
                {
                    let mut storage = vec![0xa5; payload.len() + 96];
                    storage[source_start..source_start + payload.len()].copy_from_slice(payload);
                    let original = storage.clone();
                    // destination-ahead overlaps are the public wrapper's
                    // responsibility; exercise that wrapper for those cases.
                    let written = unsafe {
                        if destination_start > source_start {
                            crate::payload_decoder::decode_into(
                                storage.as_ptr().add(source_start),
                                storage.as_mut_ptr().add(destination_start),
                                payload.len(),
                            )
                        } else {
                            kernel(
                                storage.as_ptr().add(source_start),
                                storage.as_mut_ptr().add(destination_start),
                                payload.len(),
                            )
                        }
                    };
                    assert_eq!(written, reference_len);
                    assert_eq!(
                        &storage[destination_start..destination_start + written],
                        &reference[..reference_len]
                    );
                    assert_eq!(
                        &storage[..destination_start],
                        &original[..destination_start]
                    );
                    assert_eq!(
                        &storage[destination_start + written..],
                        &original[destination_start + written..]
                    );
                }
            }
        }
    }
}
