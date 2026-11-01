//! Payload-only yEnc decoding into the caller's storage. Framing and CRC checks
//! belong to the caller; CR/LF is discarded even inside an escape sequence.
//! The SIMD kernels are the one place this workspace allows `unsafe`.
#![allow(unsafe_code)]

pub(crate) static COMPACT: [[u8; 8]; 256] = compact_table();
pub(crate) static ESCAPE_OFFSETS: [[u8; 8]; 256] = escape_offset_table();

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

/// Decode a payload into a new buffer. Decoded yEnc is never longer than its input.
pub fn decode(source: &[u8]) -> Vec<u8> {
    let mut decoded = Vec::with_capacity(source.len());
    // SAFETY: the source is readable and the spare capacity writable for
    // `source.len()` bytes, and the decoder initializes exactly `written` of them.
    unsafe {
        let written = decode_raw(source.as_ptr(), decoded.as_mut_ptr(), source.len());
        decoded.set_len(written);
    }
    decoded
}

/// Decode into `destination`, returning bytes written. Bytes past that are untouched.
///
/// # Panics
/// If `destination` is shorter than `source`.
pub fn decode_into(source: &[u8], destination: &mut [u8]) -> usize {
    assert!(destination.len() >= source.len());
    // SAFETY: the assert bounds both ranges, and `&mut` rules out overlap.
    unsafe { decode_raw(source.as_ptr(), destination.as_mut_ptr(), source.len()) }
}

/// Decode in place, returning the length of the decoded prefix.
pub fn decode_in_place(buffer: &mut [u8]) -> usize {
    let length = buffer.len();
    let start = buffer.as_mut_ptr();
    // SAFETY: both pointers span `buffer`, and forward decoding only ever writes
    // behind the read cursor.
    unsafe { decode_raw(start.cast_const(), start, length) }
}

/// Which SIMD kernel this machine selected: 0 scalar, 1 SSSE3, 2 AVX2, 3 VBMI2, 4 NEON.
pub fn kernel() -> u32 {
    selected_kernel()
}

/// Decode bytes into a destination with capacity `length`, returning bytes written.
///
/// # Safety
/// The source must be readable and destination writable for `length` bytes.
/// Overlapping ranges, including in-place decoding, are supported.
pub(crate) unsafe fn decode_raw(source: *const u8, destination: *mut u8, length: usize) -> usize {
    // Forward decoding only writes behind the source cursor. A destination that
    // begins inside the unread source needs a snapshot, as the old shim allowed.
    let source_address = source as usize;
    let destination_address = destination as usize;
    if destination_address > source_address && destination_address - source_address < length {
        // SAFETY: the caller guarantees the source is readable for `length` bytes.
        let snapshot = unsafe { std::slice::from_raw_parts(source, length) }.to_vec();
        // SAFETY: the snapshot is a separate allocation of `length` bytes.
        return unsafe { decode_forward(snapshot.as_ptr(), destination, length) };
    }
    // SAFETY: the caller's contract is the one `decode_forward` requires.
    unsafe { decode_forward(source, destination, length) }
}

/// Runtime dispatch identity: 0 scalar, 1 SSSE3, 2 AVX2, 3 VBMI2, 4 NEON.
/// Every optional feature required by a kernel is checked independently.
#[inline]
pub(crate) fn selected_kernel() -> u32 {
    #[cfg(target_arch = "aarch64")]
    {
        return 4;
    }
    #[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
    {
        use std::sync::atomic::{AtomicU32, Ordering};
        static SELECTED: AtomicU32 = AtomicU32::new(u32::MAX);
        let cached = SELECTED.load(Ordering::Relaxed);
        if cached != u32::MAX {
            return cached;
        }
        let detected = if is_x86_feature_detected!("avx2")
            && is_x86_feature_detected!("avx512vbmi2")
            && is_x86_feature_detected!("avx512bw")
            && is_x86_feature_detected!("avx512vl")
            && is_x86_feature_detected!("popcnt")
        {
            3
        } else if is_x86_feature_detected!("avx2") && is_x86_feature_detected!("ssse3") {
            2
        } else if is_x86_feature_detected!("ssse3") {
            1
        } else {
            0
        };
        SELECTED.store(detected, Ordering::Relaxed);
        return detected;
    }
    #[allow(unreachable_code)]
    0
}

unsafe fn decode_forward(source: *const u8, destination: *mut u8, length: usize) -> usize {
    // SAFETY: `selected_kernel` only returns a kernel whose CPU features were
    // detected at runtime, and the caller bounds both ranges by `length`.
    match selected_kernel() {
        #[cfg(target_arch = "aarch64")]
        4 => unsafe { crate::decoder_arm::decode_neon(source, destination, length) },
        #[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
        3 => unsafe { crate::decoder_x86::decode_vbmi2(source, destination, length) },
        #[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
        2 => unsafe { crate::decoder_x86::decode_avx2(source, destination, length) },
        #[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
        1 => unsafe { crate::decoder_x86::decode_ssse3(source, destination, length) },
        _ => unsafe { scalar_tail(source, destination, length, 0, 0, false) },
    }
}

/// Return removal and escaped-value masks for one sixteen-byte block.
#[inline]
pub(crate) fn block_masks(mut equals: u16, newlines: u16, escaped: &mut bool) -> (u16, u16) {
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
pub(crate) unsafe fn store_word(mut word: u64, mut destination: *mut u8, count: usize) {
    // SAFETY: the caller guarantees `count` writable bytes, and the writes below
    // add up to exactly `count`, all unaligned-tolerant.
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
pub(crate) unsafe fn scalar_tail(
    source: *const u8,
    destination: *mut u8,
    length: usize,
    mut read: usize,
    mut written: usize,
    mut escaped: bool,
) -> usize {
    // SAFETY: reads stop at `length`, and each read yields at most one write, so
    // `written` can't pass the caller's `length` writable bytes.
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

#[cfg(test)]
mod tests {
    use super::*;

    use super::decode_raw as decode_into;

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
        use crate::decoder_x86::{decode_avx2, decode_ssse3};
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
