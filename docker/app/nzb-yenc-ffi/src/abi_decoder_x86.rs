//! SSSE3 canonical prefixes. CR/LF and incomplete escapes stay with the scalar
//! public-call state machine; only the shared newline-free compaction is used.
#[cfg(target_arch = "x86")]
use std::arch::x86::*;
#[cfg(target_arch = "x86_64")]
use std::arch::x86_64::*;

#[target_feature(enable = "ssse3")]
pub(super) unsafe fn prefix(src: *const u8, dst: *mut u8, len: usize) -> (usize, usize) {
    let mut read = 0;
    let mut written = 0;
    unsafe {
        let offset = _mm_set1_epi8(42);
        let marker = _mm_set1_epi8(b'=' as i8);
        while len - read >= 16 {
            let bytes = _mm_loadu_si128(src.add(read).cast());
            let lines = _mm_or_si128(
                _mm_cmpeq_epi8(bytes, _mm_set1_epi8(13)),
                _mm_cmpeq_epi8(bytes, _mm_set1_epi8(10)),
            );
            if _mm_movemask_epi8(lines) != 0 {
                break;
            }
            let equals = _mm_cmpeq_epi8(bytes, marker);
            let mask = _mm_movemask_epi8(equals) as u16;
            if mask & 0x8000 != 0 {
                break;
            }
            if mask == 0 {
                _mm_storeu_si128(dst.add(written).cast(), _mm_sub_epi8(bytes, offset));
                written += 16;
            } else {
                let mut pending = false;
                written += crate::payload_decoder_x86::compact_ssse3(
                    bytes,
                    equals,
                    mask,
                    0,
                    &mut pending,
                    dst.add(written),
                );
                debug_assert!(!pending);
            }
            read += 16;
        }
    }
    (read, written)
}
