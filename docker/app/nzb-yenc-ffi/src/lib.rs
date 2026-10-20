//! RapidYenc-compatible C ABI for NNTmux. Payload decoding writes directly
//! into the caller's buffer; metadata parsers expose separate JSON exports.
//! Framing and CRC validation remain at the PHP caller boundary.

use std::ffi::{CString, c_char, c_int, c_void};
use std::panic::catch_unwind;
use std::ptr;
use std::slice;

#[cfg(feature = "experimental-rapidyenc-abi")]
mod abi_crc;
#[cfg(feature = "experimental-rapidyenc-abi")]
mod abi_decoder;
mod article_dates;
pub mod article_fingerprint;
mod article_identity;
mod article_xref;
pub mod cpu_snapshot;
mod formats;
mod header_filename_identity;
mod header_filename_unicode;
mod nntp_body;
mod nntp_headers;
mod par2;
mod par2_source_ranges;
mod payload_decoder;
mod per_article_obfuscation;
mod release_names;

#[cfg(target_arch = "aarch64")]
mod payload_decoder_arm;
#[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
mod payload_decoder_x86;
mod sample_plan;
mod seven_zip_ranges;
mod yenc_framing;

/// Historical shim version retained for callers, packed as major << 16 | minor << 8 | patch.
const VERSION: c_int = 3 | (1 << 8);

/// Returned instead of a length when decoding fails; NativePayloadDecoder rejects
/// any length larger than its input.
const DECODE_FAILED: usize = usize::MAX;

const MAX_METADATA_INPUT_BYTES: usize = 67_108_864;
const MAX_SEVEN_ZIP_RANGE_INPUT_BYTES: usize = 16 * 1024 * 1024 + 4096;
const MAX_PAR2_SOURCE_RANGE_INPUT_BYTES: usize = 16 * 1024 * 1024 + 16 * 1024;
const MAX_ARTICLE_IDENTITY_INPUT_BYTES: usize = 131_072;
const MAX_ARTICLE_XREF_INPUT_BYTES: usize = 196_608;
const MAX_NNTP_HEADER_INPUT_BYTES: usize = 131_072;
const MAX_HEADER_FILENAME_INPUT_BYTES: usize = 4_096;

/// Render bounded UTF-8 HTML rows separated by 0xFF (impossible inside UTF-8).
/// The explicit byte length preserves embedded NULs. Free through the matching
/// export from this library; no allocator ownership crosses into PHP.
///
/// # Safety
/// `data` is readable for `length` bytes; `output_length` is writable. The caller
/// must release the returned pointer with its exact returned length once only.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_names_html(
    data: *const u8,
    length: usize,
    output_length: *mut usize,
) -> *mut u8 {
    if output_length.is_null() {
        return ptr::null_mut();
    }
    unsafe { *output_length = 0 };
    if length > 8 * 1024 * 1024 || data.is_null() {
        return ptr::null_mut();
    }
    let input = unsafe { slice::from_raw_parts(data, length) };
    match catch_unwind(|| release_names::render(input)) {
        Ok(Some(rows)) => {
            let mut output = Vec::new();
            for (index, row) in rows.iter().enumerate() {
                if index > 0 {
                    output.push(0xff);
                }
                output.extend_from_slice(row.as_bytes());
            }
            let mut output = output.into_boxed_slice();
            unsafe { *output_length = output.len() };
            let pointer = output.as_mut_ptr();
            std::mem::forget(output);
            pointer
        }
        _ => ptr::null_mut(),
    }
}

/// # Safety
/// `data` and `length` must be exactly the live pair returned by
/// `nntmux_release_names_html`, and must not have been freed already.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_names_free(data: *mut u8, length: usize) {
    if !data.is_null() {
        unsafe { drop(Box::from_raw(ptr::slice_from_raw_parts_mut(data, length))) };
    }
}

#[cfg(test)]
mod release_names_abi_tests {
    #[test]
    fn rejects_null_and_over_budget_inputs_without_reading_them() {
        unsafe {
            let mut length = 999;
            assert!(super::nntmux_release_names_html(std::ptr::null(), 1, &mut length).is_null());
            assert_eq!(length, 0);
            assert!(
                super::nntmux_release_names_html(
                    std::ptr::null(),
                    8 * 1024 * 1024 + 1,
                    &mut length
                )
                .is_null()
            );
            let input = br#"{"url_prefix":"/details/","url_suffix":"","releases":[]}"#;
            assert!(
                super::nntmux_release_names_html(input.as_ptr(), input.len(), std::ptr::null_mut())
                    .is_null()
            );
            let output = super::nntmux_release_names_html(input.as_ptr(), input.len(), &mut length);
            assert!(!output.is_null());
            assert_eq!(length, 0);
            super::nntmux_release_names_free(output, length);
        }
    }
}

/// Create an independently owned, bounded article identity accumulator.
/// Release it exactly once with `nntmux_article_fingerprint_free`.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_article_fingerprint_new() -> *mut c_void {
    catch_unwind(|| Box::into_raw(Box::new(article_fingerprint::Accumulator::new())).cast())
        .unwrap_or(ptr::null_mut())
}

/// Add a raw Message-ID; invalid input permanently invalidates the identity.
///
/// # Safety
/// `state` must be a live accumulator returned by the constructor, exclusively
/// borrowed for this call. `data` must be readable for `length` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_article_fingerprint_add(
    state: *mut c_void,
    data: *const u8,
    length: usize,
) -> c_int {
    if state.is_null() {
        return 0;
    }
    // SAFETY: the caller owns this live, exclusively borrowed accumulator.
    let accumulator = unsafe { &mut *state.cast::<article_fingerprint::Accumulator>() };
    if length > 0 && data.is_null() {
        accumulator.invalidate();
        return 0;
    }
    let input = if length == 0 {
        &[]
    } else {
        // SAFETY: the caller guarantees a readable span of exactly `length`.
        unsafe { slice::from_raw_parts(data, length) }
    };
    match catch_unwind(std::panic::AssertUnwindSafe(|| accumulator.add(input))) {
        Ok(success) => c_int::from(success),
        Err(_) => {
            accumulator.invalidate();
            0
        }
    }
}

/// Write the persisted digest's 40-byte big-endian count/lane preimage.
/// Empty or invalid state returns zero and leaves the destination unchanged.
///
/// # Safety
/// `state` must be a live, exclusively borrowed accumulator. `output` must be
/// writable for 40 bytes; it must not overlap the accumulator's storage.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_article_fingerprint_value(
    state: *mut c_void,
    output: *mut u8,
) -> c_int {
    if state.is_null() || output.is_null() {
        return 0;
    }
    // SAFETY: the caller owns this live, exclusively borrowed accumulator.
    let accumulator = unsafe { &mut *state.cast::<article_fingerprint::Accumulator>() };
    match catch_unwind(std::panic::AssertUnwindSafe(|| accumulator.value())) {
        Ok(Some(value)) => {
            // SAFETY: the caller guarantees a disjoint, writable 40-byte output.
            unsafe { ptr::copy_nonoverlapping(value.as_ptr(), output, value.len()) };
            1
        }
        Ok(None) => 0,
        Err(_) => {
            accumulator.invalidate();
            0
        }
    }
}

/// Release an accumulator and its owned temporary files. Null is accepted.
///
/// # Safety
/// `state` must be null or a live constructor result, with no active borrows.
/// It must not be used or freed again after this call.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_article_fingerprint_free(state: *mut c_void) {
    if !state.is_null() {
        let _ = catch_unwind(|| {
            // SAFETY: ownership of the live constructor result returns here.
            drop(unsafe { Box::from_raw(state.cast::<article_fingerprint::Accumulator>()) });
        });
    }
}

/// Decode a yEnc payload (CR/LF line endings are skipped, `=X` escapes resolved).
pub fn decode_payload(payload: &[u8]) -> Option<Vec<u8>> {
    let mut decoded = Vec::with_capacity(payload.len());
    // SAFETY: the destination has payload.len() bytes of capacity, and the
    // decoder initializes exactly the returned number of bytes (never more).
    let written = unsafe {
        payload_decoder::decode_into(payload.as_ptr(), decoded.as_mut_ptr(), payload.len())
    };
    unsafe { decoded.set_len(written) };
    Some(decoded)
}

#[unsafe(no_mangle)]
pub extern "C" fn rapidyenc_decode_init() {
    #[cfg(feature = "experimental-rapidyenc-abi")]
    {
        let _ = catch_unwind(abi_decoder::decode_init);
    }
}

#[cfg(feature = "experimental-rapidyenc-abi")]
#[unsafe(no_mangle)]
pub extern "C" fn rapidyenc_decode_kernel() -> c_int {
    catch_unwind(abi_decoder::decode_kernel).unwrap_or(0)
}

#[cfg(feature = "experimental-rapidyenc-abi")]
#[unsafe(no_mangle)]
pub extern "C" fn rapidyenc_encode_max_length(length: usize, line_size: c_int) -> usize {
    catch_unwind(|| abi_decoder::encode_max_length(length, line_size)).unwrap_or(DECODE_FAILED)
}

/// # Safety
/// The caller provides readable source and writable destination storage for length bytes.
#[cfg(feature = "experimental-rapidyenc-abi")]
#[unsafe(no_mangle)]
pub unsafe extern "C" fn rapidyenc_decode(
    src: *const c_void,
    dest: *mut c_void,
    length: usize,
) -> usize {
    catch_unwind(|| unsafe { abi_decoder::decode(src, dest, length) }).unwrap_or(DECODE_FAILED)
}

/// # Safety
/// Pointer-storage objects are distinct, aligned and writable. The source and destination
/// data allocations follow the decoder's capacity and supported-overlap contract.
#[cfg(feature = "experimental-rapidyenc-abi")]
#[unsafe(no_mangle)]
pub unsafe extern "C" fn rapidyenc_decode_incremental(
    src: *mut *const c_void,
    dest: *mut *mut c_void,
    length: usize,
    state: *mut c_int,
) -> c_int {
    catch_unwind(|| unsafe { abi_decoder::decode_incremental(src, dest, length, state) })
        .unwrap_or(0)
}

#[unsafe(no_mangle)]
pub extern "C" fn rapidyenc_version() -> c_int {
    VERSION
}

/// The default profile retains the incumbent helper's lenient split escapes.
/// The experimental RapidYenc ABI treats escaped CR/LF as value bytes;
/// legacy callers normalize them, while the separate span export remains lenient.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_yenc_supports_split_escapes() -> c_int {
    #[cfg(feature = "experimental-rapidyenc-abi")]
    {
        0
    }
    #[cfg(not(feature = "experimental-rapidyenc-abi"))]
    {
        1
    }
}

/// Identify the implementation compiled into this library: 0 incumbent, 1 experimental.
/// This profile is not adoption authorization or performance qualification.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_yenc_implementation_profile_v1() -> c_int {
    c_int::from(cfg!(feature = "experimental-rapidyenc-abi"))
}

/// Return PAR2 packet metadata as a newly allocated UTF-8 JSON C string.
/// The caller must release it with `nntmux_metadata_string_free`.
///
/// # Safety
/// `data` must be readable for `length` bytes. Inputs over the bounded parser
/// budget are rejected by returning a null pointer.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_metadata_json(data: *const u8, length: usize) -> *mut c_char {
    if length > MAX_METADATA_INPUT_BYTES || (length > 0 && data.is_null()) {
        return ptr::null_mut();
    }
    let input = if length == 0 {
        &[]
    } else {
        // SAFETY: the caller guarantees `data` is readable for `length` bytes.
        unsafe { slice::from_raw_parts(data, length) }
    };
    let encoded = catch_unwind(|| serde_json::to_string(&par2::parse(input)));
    match encoded {
        Ok(Ok(json)) => CString::new(json)
            .map(CString::into_raw)
            .unwrap_or(ptr::null_mut()),
        _ => ptr::null_mut(),
    }
}

/// Return bounded format-signature and header metadata as UTF-8 JSON.
/// The caller must release it with `nntmux_metadata_string_free`.
///
/// # Safety
/// `data` must be readable for `length` bytes. Inputs over the bounded parser
/// budget are rejected by returning a null pointer.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_format_metadata_json(
    data: *const u8,
    length: usize,
) -> *mut c_char {
    if length > MAX_METADATA_INPUT_BYTES || (length > 0 && data.is_null()) {
        return ptr::null_mut();
    }
    let input = if length == 0 {
        &[]
    } else {
        // SAFETY: the caller guarantees `data` is readable for `length` bytes.
        unsafe { slice::from_raw_parts(data, length) }
    };
    let encoded = catch_unwind(|| serde_json::to_string(&formats::parse(input)));
    match encoded {
        Ok(Ok(json)) => CString::new(json)
            .map(CString::into_raw)
            .unwrap_or(ptr::null_mut()),
        _ => ptr::null_mut(),
    }
}

/// Parse bounded raw HEAD fields into occurrence-aware absolute date metadata.
/// The caller must release the returned JSON with `nntmux_metadata_string_free`.
///
/// # Safety
/// `data` must be readable for `length` bytes. Inputs above 65536 bytes are rejected.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_article_dates_v1_json(
    data: *const u8,
    length: usize,
) -> *mut c_char {
    if length > 65_536 || (length > 0 && data.is_null()) {
        return ptr::null_mut();
    }
    let input = if length == 0 {
        &[]
    } else {
        // SAFETY: the caller guarantees `data` is readable for `length` bytes.
        unsafe { slice::from_raw_parts(data, length) }
    };
    match catch_unwind(|| serde_json::to_string(&article_dates::parse(input))) {
        Ok(Ok(json)) => CString::new(json)
            .map(CString::into_raw)
            .unwrap_or(ptr::null_mut()),
        _ => ptr::null_mut(),
    }
}

/// Resolve one 7-Zip next-header range and any encoded-header PackInfo ranges
/// from a compact list of sampled file ranges. Input is: fragment count (u32 LE),
/// declared file size (u64 LE), then repeated file offset (u64 LE), byte length
/// (u32 LE), and raw decoded bytes.
/// The caller must release the returned UTF-8 JSON with `nntmux_metadata_string_free`.
///
/// # Safety
/// `data` must be readable for `length` bytes. Inputs over the range budget are rejected.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_seven_zip_ranges_json(
    data: *const u8,
    length: usize,
) -> *mut c_char {
    if length > MAX_SEVEN_ZIP_RANGE_INPUT_BYTES || data.is_null() || length == 0 {
        return ptr::null_mut();
    }
    // SAFETY: the caller guarantees `data` is readable for `length` bytes.
    let input = unsafe { slice::from_raw_parts(data, length) };
    let encoded = catch_unwind(|| {
        seven_zip_ranges::parse(input).and_then(|metadata| serde_json::to_string(&metadata).ok())
    });
    match encoded {
        Ok(Some(json)) => CString::new(json)
            .map(CString::into_raw)
            .unwrap_or(ptr::null_mut()),
        _ => ptr::null_mut(),
    }
}

/// Verify bounded PAR2 IFSC slices against decoded yEnc article fragments.
/// The input is a packed P2SR record; the caller must free the returned JSON.
///
/// # Safety
/// `data` must be readable for `length` bytes. Inputs over the range budget are rejected.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_source_ranges_json(
    data: *const u8,
    length: usize,
) -> *mut c_char {
    if length > MAX_PAR2_SOURCE_RANGE_INPUT_BYTES || data.is_null() || length == 0 {
        return ptr::null_mut();
    }
    // SAFETY: the caller guarantees `data` is readable for `length` bytes.
    let input = unsafe { slice::from_raw_parts(data, length) };
    let encoded = catch_unwind(|| {
        par2_source_ranges::parse(input).and_then(|metadata| serde_json::to_string(&metadata).ok())
    });
    match encoded {
        Ok(Some(json)) => CString::new(json)
            .map(CString::into_raw)
            .unwrap_or(ptr::null_mut()),
        _ => ptr::null_mut(),
    }
}

/// Return bounded, ordered yEnc control-field occurrences as UTF-8 JSON.
/// The caller must release it with `nntmux_metadata_string_free`.
///
/// # Safety
/// `data` must be readable for `length` bytes. Inputs over the bounded parser
/// budget are rejected by returning a null pointer.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_yenc_framing_metadata_json(
    data: *const u8,
    length: usize,
) -> *mut c_char {
    if length > MAX_METADATA_INPUT_BYTES || (length > 0 && data.is_null()) {
        return ptr::null_mut();
    }
    let input = if length == 0 {
        &[]
    } else {
        // SAFETY: the caller guarantees `data` is readable for `length` bytes.
        unsafe { slice::from_raw_parts(data, length) }
    };
    let encoded = catch_unwind(|| serde_json::to_string(&yenc_framing::parse(input)));
    match encoded {
        Ok(Ok(json)) => CString::new(json)
            .map(CString::into_raw)
            .unwrap_or(ptr::null_mut()),
        _ => ptr::null_mut(),
    }
}

/// Return bounded, ordered NNTP header-field occurrences as UTF-8 JSON.
/// The caller must release it with `nntmux_metadata_string_free`.
///
/// # Safety
/// `data` must be readable for `length` bytes. Header blocks over the parser
/// budget are rejected by returning a null pointer.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nntp_header_metadata_json(
    data: *const u8,
    length: usize,
) -> *mut c_char {
    if length > MAX_NNTP_HEADER_INPUT_BYTES || (length > 0 && data.is_null()) {
        return ptr::null_mut();
    }
    let input = if length == 0 {
        &[]
    } else {
        // SAFETY: the caller guarantees `data` is readable for `length` bytes.
        unsafe { slice::from_raw_parts(data, length) }
    };
    let encoded = catch_unwind(|| serde_json::to_string(&nntp_headers::parse(input)));
    match encoded {
        Ok(Ok(json)) => CString::new(json)
            .map(CString::into_raw)
            .unwrap_or(ptr::null_mut()),
        _ => ptr::null_mut(),
    }
}

/// Compare two length-prefixed NNTP header blocks without collapsing
/// duplicate field occurrences. The identity flag is -1 when unavailable,
/// zero when response identities disagree, and one when they agree.
///
/// # Safety
/// data must contain two little-endian u32-length-prefixed header blocks.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nntp_header_pair_comparison_json(
    data: *const u8,
    length: usize,
    identity_match: c_int,
) -> *mut c_char {
    if length > MAX_NNTP_HEADER_INPUT_BYTES * 2 + 8 || (length > 0 && data.is_null()) {
        return ptr::null_mut();
    }
    let input = if length == 0 {
        &[]
    } else {
        // SAFETY: the caller guarantees data is readable for length bytes.
        unsafe { slice::from_raw_parts(data, length) }
    };
    let Some((left, right)) = unpack_header_pair(input) else {
        return ptr::null_mut();
    };
    let identity_match = match identity_match {
        0 => Some(false),
        1 => Some(true),
        -1 => None,
        _ => return ptr::null_mut(),
    };
    let encoded =
        catch_unwind(|| serde_json::to_string(&nntp_headers::compare(left, right, identity_match)));
    match encoded {
        Ok(Ok(json)) => CString::new(json)
            .map(CString::into_raw)
            .unwrap_or(ptr::null_mut()),
        _ => ptr::null_mut(),
    }
}

fn unpack_header_pair(input: &[u8]) -> Option<(&[u8], &[u8])> {
    let left_length = usize::try_from(u32::from_le_bytes(input.get(..4)?.try_into().ok()?)).ok()?;
    let left_end = 4usize.checked_add(left_length)?;
    let right_length = usize::try_from(u32::from_le_bytes(
        input
            .get(left_end..left_end.checked_add(4)?)?
            .try_into()
            .ok()?,
    ))
    .ok()?;
    let right_start = left_end.checked_add(4)?;
    let right_end = right_start.checked_add(right_length)?;
    (right_end == input.len()).then(|| (&input[4..left_end], &input[right_start..right_end]))
}

/// Return body-line framing measurements for post-dot-unstuffing NNTP BODY bytes.
/// The caller must release the result with `nntmux_metadata_string_free`.
///
/// # Safety
/// `data` must be readable for `length` bytes. Inputs over the bounded parser
/// budget are rejected by returning a null pointer.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nntp_body_measurement_json(
    data: *const u8,
    length: usize,
) -> *mut c_char {
    if length > MAX_METADATA_INPUT_BYTES || (length > 0 && data.is_null()) {
        return ptr::null_mut();
    }
    let input = if length == 0 {
        &[]
    } else {
        // SAFETY: the caller guarantees `data` is readable for `length` bytes.
        unsafe { slice::from_raw_parts(data, length) }
    };
    let encoded = catch_unwind(|| serde_json::to_string(&nntp_body::parse(input)));
    match encoded {
        Ok(Ok(json)) => CString::new(json)
            .map(CString::into_raw)
            .unwrap_or(ptr::null_mut()),
        _ => ptr::null_mut(),
    }
}

/// Compare a unique RFC 5536 Lines header occurrence with observed BODY framing.
/// `data` is a little-endian u32 header length, header bytes, then body bytes.
/// The caller must release the result with `nntmux_metadata_string_free`.
///
/// # Safety
/// `data` must be readable for `length` bytes. Inputs over the bounded parser
/// budget are rejected by returning a null pointer.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nntp_lines_body_relationship_json(
    data: *const u8,
    length: usize,
) -> *mut c_char {
    if length > MAX_NNTP_HEADER_INPUT_BYTES + 2_000_004 || (length > 0 && data.is_null()) {
        return ptr::null_mut();
    }
    let input = if length == 0 {
        &[]
    } else {
        // SAFETY: the caller guarantees `data` is readable for `length` bytes.
        unsafe { slice::from_raw_parts(data, length) }
    };
    let Some(header_length) = input
        .get(..4)
        .and_then(|bytes| usize::try_from(u32::from_le_bytes(bytes.try_into().ok()?)).ok())
    else {
        return ptr::null_mut();
    };
    let Some(header_end) = 4usize.checked_add(header_length) else {
        return ptr::null_mut();
    };
    let Some(headers) = input.get(4..header_end) else {
        return ptr::null_mut();
    };
    if headers.len() > MAX_NNTP_HEADER_INPUT_BYTES {
        return ptr::null_mut();
    }
    let Some(body) = input.get(header_end..) else {
        return ptr::null_mut();
    };
    if body.len() > 2_000_000 {
        return ptr::null_mut();
    }
    let encoded =
        catch_unwind(|| serde_json::to_string(&nntp_body::compare_lines_to_body(headers, body)));
    match encoded {
        Ok(Ok(json)) => CString::new(json)
            .map(CString::into_raw)
            .unwrap_or(ptr::null_mut()),
        _ => ptr::null_mut(),
    }
}

/// Parse a v2 relationship between a requested Message-ID, protocol response
/// lines, a HEAD field, and the mandatory Message-ID column in an OVER response.
/// `data` is AI02 followed by five little-endian length-prefixed byte strings;
/// u32::MAX means absent. The v2 symbol keeps this ABI separate from the legacy
/// four-argument function below.
/// The caller must release the result with `nntmux_metadata_string_free`.
///
/// # Safety
/// `data` must be readable for `length` bytes. Oversized inputs are rejected.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_article_identity_v2_json(
    data: *const u8,
    length: usize,
    overview_response_code: i32,
    overview_attempted: i32,
    head_response_code: i32,
    body_response_code: i32,
) -> *mut c_char {
    if length > MAX_ARTICLE_IDENTITY_INPUT_BYTES || (length > 0 && data.is_null()) {
        return ptr::null_mut();
    }
    let input = if length == 0 {
        &[]
    } else {
        // SAFETY: the caller guarantees `data` is readable for `length` bytes.
        unsafe { slice::from_raw_parts(data, length) }
    };
    article_identity_json(
        input,
        overview_response_code,
        overview_attempted != 0,
        head_response_code,
        body_response_code,
    )
}

/// Parse the Xref fields in a HEAD response and an OVER response using the
/// server's advertised LIST OVERVIEW.FMT layout. `data` is XR01 followed by
/// three little-endian length-prefixed fields: raw HEAD bytes, packed overview
/// rows, and packed overview-format lines. Each line list is a u32 count then
/// repeated length-prefixed byte strings; u32::MAX marks an absent outer field.
/// The caller must release the returned JSON with `nntmux_metadata_string_free`.
///
/// # Safety
/// `data` must be readable for `length` bytes. Oversized inputs are rejected.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_article_identity_xref_v1_json(
    data: *const u8,
    length: usize,
    head_response_code: i32,
    overview_response_code: i32,
    overview_attempted: i32,
    overview_row_count: i32,
    overview_format_response_code: i32,
) -> *mut c_char {
    if length > MAX_ARTICLE_XREF_INPUT_BYTES || (length > 0 && data.is_null()) {
        return ptr::null_mut();
    }
    let input = if length == 0 {
        &[]
    } else {
        // SAFETY: the caller guarantees `data` is readable for `length` bytes.
        unsafe { slice::from_raw_parts(data, length) }
    };
    let encoded = catch_unwind(|| {
        serde_json::to_string(&article_xref::parse(
            input,
            head_response_code,
            overview_response_code,
            overview_attempted != 0,
            overview_row_count,
            overview_format_response_code,
        ))
    });
    match encoded {
        Ok(Ok(json)) => CString::new(json)
            .map(CString::into_raw)
            .unwrap_or(ptr::null_mut()),
        _ => ptr::null_mut(),
    }
}

/// Parse legacy AI01 input while preserving the original four-argument ABI.
/// The added response fields are marked absent in the v2 output.
///
/// # Safety
/// `data` must be readable for `length` bytes. Oversized inputs are rejected.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_article_identity_json(
    data: *const u8,
    length: usize,
    overview_response_code: i32,
    overview_attempted: i32,
) -> *mut c_char {
    if !(4..=MAX_ARTICLE_IDENTITY_INPUT_BYTES).contains(&length) || data.is_null() {
        return ptr::null_mut();
    }
    // SAFETY: the caller guarantees `data` is readable for `length` bytes.
    let input = unsafe { slice::from_raw_parts(data, length) };
    if input.get(..4) != Some(b"AI01")
        || length
            .checked_add(8)
            .is_none_or(|size| size > MAX_ARTICLE_IDENTITY_INPUT_BYTES)
    {
        return ptr::null_mut();
    }
    let mut upgraded = Vec::with_capacity(length + 8);
    upgraded.extend_from_slice(b"AI02");
    upgraded.extend_from_slice(&input[4..]);
    upgraded.extend_from_slice(&[0xff; 8]);
    article_identity_json(
        &upgraded,
        overview_response_code,
        overview_attempted != 0,
        -1,
        -1,
    )
}

fn article_identity_json(
    input: &[u8],
    overview_response_code: i32,
    overview_attempted: bool,
    head_response_code: i32,
    body_response_code: i32,
) -> *mut c_char {
    match catch_unwind(|| {
        serde_json::to_string(&article_identity::parse(
            input,
            overview_response_code,
            overview_attempted,
            head_response_code,
            body_response_code,
        ))
    }) {
        Ok(Ok(json)) => CString::new(json)
            .map(CString::into_raw)
            .unwrap_or(ptr::null_mut()),
        _ => ptr::null_mut(),
    }
}

/// Release a metadata JSON string returned by this library.
///
/// # Safety
/// `value` must be null or a pointer returned by one of the metadata functions.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_metadata_string_free(value: *mut c_char) {
    if !value.is_null() {
        // SAFETY: the pointer was produced by `CString::into_raw` in this library.
        unsafe { drop(CString::from_raw(value)) };
    }
}

/// Versioned bounded sampling-planner ABI; unsupported inputs request PHP fallback.
/// Null requests PHP fallback for unsupported input. Free a result using
/// `nntmux_metadata_string_free` from this same library.
///
/// # Safety
/// `data` must be readable for `length` bytes when length is nonzero.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_spot_check_sample_v1_json(
    data: *const u8,
    length: usize,
    maximum_articles: i64,
    maximum_header_bytes: i64,
    maximum_article_bytes: i64,
    has_maximum_article_bytes: c_int,
) -> *mut c_char {
    if length > MAX_METADATA_INPUT_BYTES || (length > 0 && data.is_null()) {
        return ptr::null_mut();
    }
    let input = if length == 0 {
        &[]
    } else {
        // SAFETY: caller supplies readable input of the checked length.
        unsafe { slice::from_raw_parts(data, length) }
    };
    match catch_unwind(|| {
        sample_plan::select_json(
            input,
            maximum_articles,
            maximum_header_bytes,
            (has_maximum_article_bytes != 0).then_some(maximum_article_bytes),
        )
    }) {
        Ok(Some(json)) => CString::new(json)
            .map(CString::into_raw)
            .unwrap_or(ptr::null_mut()),
        _ => ptr::null_mut(),
    }
}

/// Rust candidate for `HeaderFilenameIdentity::matches`. Returns 1 when the article filename is
/// accepted, 0 when the declared subject filename contradicts it, and -1 when the port declines
/// so the caller keeps the PHP verdict: null with a nonzero length, an argument over 4 KiB, or a
/// shape needing Unicode case mapping, NFC or caseless Unicode matching (a valid non-ASCII name
/// searched in an unquoted subject, or a non-ASCII stem or extension that differs byte for byte).
/// A name or subject that is not valid UTF-8 is decided (0), as PHP rejects it before any
/// pattern runs.
///
/// # Safety
/// Each pointer must be readable for its length when that length is nonzero.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_header_filename_identity_v1(
    subject: *const u8,
    subject_len: usize,
    actual: *const u8,
    actual_len: usize,
) -> c_int {
    if subject_len > MAX_HEADER_FILENAME_INPUT_BYTES
        || actual_len > MAX_HEADER_FILENAME_INPUT_BYTES
        || (subject_len > 0 && subject.is_null())
        || (actual_len > 0 && actual.is_null())
    {
        return -1;
    }
    let subject: &[u8] = if subject_len == 0 {
        &[]
    } else {
        // SAFETY: the caller supplies readable input of the checked length.
        unsafe { slice::from_raw_parts(subject, subject_len) }
    };
    let actual: &[u8] = if actual_len == 0 {
        &[]
    } else {
        // SAFETY: the caller supplies readable input of the checked length.
        unsafe { slice::from_raw_parts(actual, actual_len) }
    };
    catch_unwind(|| header_filename_identity::matches(subject, actual))
        .ok()
        .flatten()
        .map_or(-1, c_int::from)
}

/// Rust port of `HeaderParser::markPerArticleObfuscated()` over `count` articles: `numbers` holds
/// their numbers as decimal text separated by `\n` (each `-?[0-9]+`, saturating as PHP's `(int)`
/// does below 309 digits), `lengths` their subject lengths as little-endian u32s, and `subjects` the subjects back to
/// back. Marked article numbers are written to `output` as little-endian i64s and their count is
/// returned. `usize::MAX` declines (a number in any other form, inconsistent lengths, too little
/// output space, or a subject longer than 512 bytes) so the caller falls back.
///
/// # Safety
/// `numbers` is readable for `numbers_len` bytes, `lengths` for `count * 4`, `subjects` for
/// `subjects_len` and `output` writable for `output_capacity` bytes whenever that size is nonzero.
#[unsafe(no_mangle)]
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn nntmux_per_article_obfuscation_v2(
    numbers: *const u8,
    numbers_len: usize,
    lengths: *const u8,
    count: usize,
    subjects: *const u8,
    subjects_len: usize,
    min_articles: usize,
    output: *mut u8,
    output_capacity: usize,
) -> usize {
    let Some(length_bytes) = count.checked_mul(4) else {
        return DECODE_FAILED;
    };
    if (numbers_len > 0 && numbers.is_null())
        || (count > 0 && lengths.is_null())
        || (subjects_len > 0 && subjects.is_null())
        || (output_capacity > 0 && output.is_null())
    {
        return DECODE_FAILED;
    }
    // SAFETY: each slice is built only for a nonzero size the caller guarantees readable.
    let (numbers, lengths, subjects): (&[u8], &[u8], &[u8]) = unsafe {
        (
            if numbers_len == 0 {
                &[]
            } else {
                slice::from_raw_parts(numbers, numbers_len)
            },
            if count == 0 {
                &[]
            } else {
                slice::from_raw_parts(lengths, length_bytes)
            },
            if subjects_len == 0 {
                &[]
            } else {
                slice::from_raw_parts(subjects, subjects_len)
            },
        )
    };
    let Some(articles) = per_article_obfuscation_articles(numbers, lengths, count, subjects) else {
        return DECODE_FAILED;
    };
    let Ok(Some(marked)) = catch_unwind(|| per_article_obfuscation::mark(&articles, min_articles))
    else {
        return DECODE_FAILED;
    };
    if marked.len().saturating_mul(8) > output_capacity {
        return DECODE_FAILED;
    }
    if marked.is_empty() {
        return 0;
    }
    // SAFETY: the caller supplies writable output of the checked capacity.
    let output = unsafe { slice::from_raw_parts_mut(output, output_capacity) };
    for (slot, number) in output.as_chunks_mut::<8>().0.iter_mut().zip(&marked) {
        *slot = number.to_le_bytes();
    }
    marked.len()
}

fn per_article_obfuscation_articles<'a>(
    numbers: &[u8],
    lengths: &[u8],
    count: usize,
    mut subjects: &'a [u8],
) -> Option<Vec<per_article_obfuscation::Article<'a>>> {
    if count == 0 {
        return (numbers.is_empty() && subjects.is_empty()).then(Vec::new);
    }
    let mut articles = Vec::with_capacity(count);
    let mut numbers = numbers.split(|byte| *byte == b'\n');
    for length in lengths.as_chunks::<4>().0 {
        let number = php_decimal(numbers.next()?)?;
        let length = usize::try_from(u32::from_le_bytes(*length)).ok()?;
        if subjects.len() < length {
            return None;
        }
        let (subject, rest) = subjects.split_at(length);
        articles.push(per_article_obfuscation::Article { number, subject });
        subjects = rest;
    }
    (subjects.is_empty() && numbers.next().is_none()).then_some(articles)
}

/// `-?[0-9]+` as PHP's `(int)` reads it, saturating at the i64 bounds; anything else, or a run of
/// `PHP_INT_DOUBLE_DIGITS` or more significant digits (where PHP's double can overflow and its
/// `(int)` give 0), is `None`.
fn php_decimal(text: &[u8]) -> Option<i64> {
    let (negative, digits) = match text.split_first() {
        Some((b'-', rest)) => (true, rest),
        _ => (false, text),
    };
    if digits.is_empty() || !digits.iter().all(u8::is_ascii_digit) {
        return None;
    }
    let significant = digits.iter().skip_while(|byte| **byte == b'0').count();
    if significant >= per_article_obfuscation::PHP_INT_DOUBLE_DIGITS {
        return None;
    }
    let saturated = if negative { i64::MIN } else { i64::MAX };
    Some(
        digits
            .iter()
            .try_fold(0_i64, |value, byte| {
                let digit = i64::from(byte - b'0');
                value
                    .checked_mul(10)?
                    .checked_add(if negative { -digit } else { digit })
            })
            .unwrap_or(saturated),
    )
}

#[cfg(test)]
mod per_article_obfuscation_abi_tests {
    use super::*;

    fn call(numbers: &[u8], subjects: &[&[u8]]) -> usize {
        let lengths: Vec<u8> = subjects
            .iter()
            .flat_map(|subject| (subject.len() as u32).to_le_bytes())
            .collect();
        let joined: Vec<u8> = subjects.concat();
        let mut output = vec![0_u8; subjects.len() * 8];
        // SAFETY: every buffer is a live Vec of the stated length.
        unsafe {
            nntmux_per_article_obfuscation_v2(
                numbers.as_ptr(),
                numbers.len(),
                lengths.as_ptr(),
                subjects.len(),
                joined.as_ptr(),
                joined.len(),
                16,
                output.as_mut_ptr(),
                output.len(),
            )
        }
    }

    #[test]
    fn per_article_obfuscation_abi_parses_numbers_like_php() {
        assert_eq!(php_decimal(b"0042"), Some(42));
        assert_eq!(php_decimal(b"-7"), Some(-7));
        assert_eq!(php_decimal(b"99999999999999999999"), Some(i64::MAX));
        assert_eq!(php_decimal(b"-99999999999999999999"), Some(i64::MIN));
        assert_eq!(php_decimal("9".repeat(308).as_bytes()), Some(i64::MAX));
        assert_eq!(php_decimal("9".repeat(309).as_bytes()), None);
        assert_eq!(
            php_decimal(format!("-{}", "9".repeat(400)).as_bytes()),
            None
        );
        for invalid in [&b""[..], b"-", b" 42", b"1e3", b"4.2", b"+4"] {
            assert_eq!(php_decimal(invalid), None);
        }
        assert_eq!(call(b"1\n2", &[b"a (1/2)", b"b (1/2)"]), 0);
        assert_eq!(call(b"1\n 2", &[b"a (1/2)", b"b (1/2)"]), DECODE_FAILED);
        assert_eq!(call(b"1", &[b"a (1/2)", b"b (1/2)"]), DECODE_FAILED);
        assert_eq!(call(b"1\n2\n3", &[b"a (1/2)", b"b (1/2)"]), DECODE_FAILED);
        assert_eq!(call(b"", &[]), 0);
    }
}

/// The PCRE version (NUL-terminated, static) whose Unicode classes this library's header filename
/// port was generated from. The PHP wrapper refuses a library whose version differs from the
/// runtime's `PCRE_VERSION`, because the port's letter, mark, number, digit and whitespace tables
/// must be those of the PCRE that runs the PHP rule.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_header_filename_identity_tables_v1() -> *const c_char {
    header_filename_unicode::PCRE_VERSION.as_ptr()
}

/// Decode `src_length` bytes from `src` into `dest` and return the decoded length.
///
/// The default profile uses incumbent lenient escape handling and ignores
/// `is_raw` and `state`. The experimental profile follows the complete RapidYenc
/// ABI's raw dot unstuffing and optional decoder state.
///
/// # Safety
///
/// `src` must be readable and `dest` writable for `src_length` bytes. Decoded
/// yEnc is never longer than its input.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn rapidyenc_decode_ex(
    is_raw: c_int,
    src: *const c_void,
    dest: *mut c_void,
    src_length: usize,
    state: *mut c_int,
) -> usize {
    #[cfg(feature = "experimental-rapidyenc-abi")]
    {
        catch_unwind(|| unsafe { abi_decoder::decode_ex(is_raw, src, dest, src_length, state) })
            .unwrap_or(DECODE_FAILED)
    }
    #[cfg(not(feature = "experimental-rapidyenc-abi"))]
    {
        let _ = (is_raw, state);
        if src_length == 0 {
            return 0;
        }
        if src.is_null() || dest.is_null() {
            return DECODE_FAILED;
        }
        match catch_unwind(|| {
            // SAFETY: the caller supplies readable/writable buffers of src_length.
            unsafe { payload_decoder::decode_into(src.cast(), dest.cast(), src_length) }
        }) {
            Ok(written) => written,
            Err(_) => DECODE_FAILED,
        }
    }
}

/// Decode a bounded payload span from a larger source buffer.
///
/// This application span API skips CR/LF inside escapes, retaining its lenient
/// contract separately from the canonical RapidYenc ABI. Only the returned
/// number of destination bytes are written. A nonnull `crc32_output` receives
/// the CRC-32 of those decoded bytes; null skips CRC computation.
/// Invalid ranges, capacity, or required null pointers return `usize::MAX`
/// without modifying the destination or CRC output. Valid empty spans return
/// zero and set a supplied CRC output to zero.
///
/// # Safety
///
/// For nonzero `source_length`, `src` must point to an allocation readable for
/// the whole `source_length`. For nonzero `payload_length`, `dest` must point
/// to an allocation writable for `destination_capacity` bytes. Source and
/// destination allocations may overlap, including in-place decoding.
/// A nonnull `crc32_output` must be writable for four bytes, and its four-byte
/// range must not overlap either source or destination allocation. Alignment
/// is not required. These pointer requirements apply when validation succeeds;
/// invalid argument combinations are rejected before any pointer access.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_yenc_decode_span_v1(
    src: *const c_void,
    source_length: usize,
    payload_offset: usize,
    payload_length: usize,
    dest: *mut c_void,
    destination_capacity: usize,
    crc32_output: *mut u32,
) -> usize {
    if source_length > isize::MAX as usize
        || payload_offset > source_length
        || payload_length > source_length - payload_offset
        || destination_capacity < payload_length
        || (source_length > 0 && src.is_null())
        || (payload_length > 0 && dest.is_null())
    {
        return DECODE_FAILED;
    }
    match catch_unwind(|| {
        if payload_length == 0 {
            if !crc32_output.is_null() {
                // SAFETY: the caller provides four writable, disjoint bytes.
                unsafe { crc32_output.write_unaligned(0) };
            }
            return 0;
        }
        // SAFETY: validation bounds this nonempty span within the readable
        // source allocation and ensures sufficient writable destination space.
        let written = unsafe {
            payload_decoder::decode_into(
                src.cast::<u8>().add(payload_offset),
                dest.cast(),
                payload_length,
            )
        };
        if !crc32_output.is_null() {
            // SAFETY: the decoder initialized exactly `written` bytes, and
            // the disjoint CRC output remains writable during this shared read.
            let decoded = unsafe { slice::from_raw_parts(dest.cast::<u8>(), written) };
            let crc = crc32fast::hash(decoded);
            unsafe { crc32_output.write_unaligned(crc) };
        }
        written
    }) {
        Ok(written) => written,
        Err(_) => DECODE_FAILED,
    }
}

#[unsafe(no_mangle)]
pub extern "C" fn rapidyenc_crc_init() {
    #[cfg(feature = "experimental-rapidyenc-abi")]
    {
        let _ = catch_unwind(abi_crc::crc_init);
    }
}

#[cfg(feature = "experimental-rapidyenc-abi")]
#[unsafe(no_mangle)]
pub extern "C" fn rapidyenc_crc_kernel() -> c_int {
    catch_unwind(abi_crc::crc_kernel).unwrap_or(0)
}

#[cfg(feature = "experimental-rapidyenc-abi")]
#[unsafe(no_mangle)]
pub extern "C" fn rapidyenc_crc_combine(first: u32, second: u32, length: u64) -> u32 {
    abi_crc::crc_combine(first, second, length)
}

#[cfg(feature = "experimental-rapidyenc-abi")]
#[unsafe(no_mangle)]
pub extern "C" fn rapidyenc_crc_zeros(initial: u32, length: u64) -> u32 {
    abi_crc::crc_zeros(initial, length)
}

#[cfg(feature = "experimental-rapidyenc-abi")]
#[unsafe(no_mangle)]
pub extern "C" fn rapidyenc_crc_unzero(initial: u32, length: u64) -> u32 {
    abi_crc::crc_unzero(initial, length)
}

#[cfg(feature = "experimental-rapidyenc-abi")]
#[unsafe(no_mangle)]
pub extern "C" fn rapidyenc_crc_multiply(first: u32, second: u32) -> u32 {
    abi_crc::crc_multiply(first, second)
}

#[cfg(feature = "experimental-rapidyenc-abi")]
#[unsafe(no_mangle)]
pub extern "C" fn rapidyenc_crc_2pow(exponent: i64) -> u32 {
    abi_crc::crc_2pow(exponent)
}

#[cfg(feature = "experimental-rapidyenc-abi")]
#[unsafe(no_mangle)]
pub extern "C" fn rapidyenc_crc_256pow(exponent: u64) -> u32 {
    abi_crc::crc_256pow(exponent)
}

/// Identity from the same runtime selector used by payload decoding.
/// 0 scalar, 1 SSSE3, 2 AVX2, 3 AVX-512 VBMI2, 4 AArch64 NEON.
/// This is decoder provenance, not evidence about the posting application.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_yenc_payload_kernel_v1() -> u32 {
    payload_decoder::selected_kernel()
}

/// CRC-32 (the same digest as PHP's `crc32b`), continuing from `init_crc`.
/// The default profile uses the incumbent implementation; the experimental
/// profile uses the canonical RapidYenc CRC implementation.
///
/// # Safety
///
/// `src` must be readable for `src_length` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn rapidyenc_crc(
    src: *const c_void,
    src_length: usize,
    init_crc: u32,
) -> u32 {
    #[cfg(feature = "experimental-rapidyenc-abi")]
    {
        catch_unwind(|| unsafe { abi_crc::crc(src, src_length, init_crc) }).unwrap_or(init_crc)
    }
    #[cfg(not(feature = "experimental-rapidyenc-abi"))]
    {
        if src_length == 0 || src.is_null() {
            return init_crc;
        }
        // SAFETY: the caller guarantees `src` is readable for `src_length` bytes.
        let data = unsafe { slice::from_raw_parts(src.cast::<u8>(), src_length) };
        let mut hasher = crc32fast::Hasher::new_with_initial(init_crc);
        hasher.update(data);

        hasher.finalize()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn article_dates_export_preserves_clock_relations_and_owned_json() {
        for (input, expected) in [
            (
                b"Date: 01 Jan 2024 00:00:00 +0000\r\nInjection-Date: 01 Jan 2024 01:00:00 +0000\r\nNNTP-Posting-Date: 31 Dec 2023 19:00:00 -0500\r\nExpires: 02 Jan 2024 00:00:00 +0000\r\n".as_slice(),
                [
                    ("compared", "valid", "valid", Some(3600), Some("before")),
                    ("compared", "valid", "valid", Some(0), Some("equal")),
                    ("compared", "valid", "valid", Some(-3600), Some("after")),
                    ("compared", "valid", "valid", Some(86_400), Some("before")),
                    ("compared", "valid", "valid", Some(82_800), Some("before")),
                    ("compared", "valid", "valid", Some(86_400), Some("before")),
                ],
            ),
            (
                b"Date: bad\r\nInjection-Date: 01 Jan 2024 01:00:00 +0000\r\n".as_slice(),
                [
                    ("source_unavailable", "malformed_or_unsupported_absolute_date", "valid", None, None),
                    ("source_unavailable", "malformed_or_unsupported_absolute_date", "missing", None, None),
                    ("source_unavailable", "valid", "missing", None, None),
                    ("source_unavailable", "malformed_or_unsupported_absolute_date", "missing", None, None),
                    ("source_unavailable", "valid", "missing", None, None),
                    ("source_unavailable", "missing", "missing", None, None),
                ],
            ),
        ] {
            let output = unsafe { nntmux_article_dates_v1_json(input.as_ptr(), input.len()) };
            assert!(!output.is_null());
            let bytes = unsafe { CStr::from_ptr(output) }.to_bytes().to_owned();
            unsafe { nntmux_metadata_string_free(output) };
            let metadata: serde_json::Value = serde_json::from_slice(&bytes).unwrap();
            assert_eq!(metadata["schema_version"], 2);
            assert_eq!(metadata["parser_engine"], "nntmux-rust-article-dates");
            let relations = &metadata["reported_clock_relations"];
            assert_eq!(relations.as_object().unwrap().len(), 6);
            for (pair, (status, lhs, rhs, delta, order)) in [
                "date_to_injection_date",
                "date_to_legacy_nntp_posting_date",
                "injection_date_to_legacy_nntp_posting_date",
                "date_to_expires",
                "injection_date_to_expires",
                "legacy_nntp_posting_date_to_expires",
            ].into_iter().zip(expected) {
                assert_eq!(relations[pair], serde_json::json!({
                    "comparison_status": status,
                    "lhs_parse_status": lhs,
                    "rhs_parse_status": rhs,
                    "rhs_minus_lhs_seconds": delta,
                    "lhs_order_relative_to_rhs": order,
                }));
            }
        }
        assert!(unsafe { nntmux_article_dates_v1_json(ptr::null(), 1) }.is_null());
        let oversized = vec![b'X'; 65_537];
        assert!(
            unsafe { nntmux_article_dates_v1_json(oversized.as_ptr(), oversized.len()) }.is_null()
        );
        for input in [None, Some(&oversized[..65_536])] {
            let (pointer, length) =
                input.map_or((ptr::null(), 0), |bytes| (bytes.as_ptr(), bytes.len()));
            let output = unsafe { nntmux_article_dates_v1_json(pointer, length) };
            assert!(!output.is_null());
            let bytes = unsafe { CStr::from_ptr(output) }.to_bytes().to_owned();
            unsafe { nntmux_metadata_string_free(output) };
            let metadata: serde_json::Value = serde_json::from_slice(&bytes).unwrap();
            assert_eq!(metadata["schema_version"], 2);
            assert_eq!(metadata["date"]["parse_status"], "missing");
            assert_eq!(metadata["expires"]["parse_status"], "missing");
        }
    }

    #[test]
    fn payload_kernel_export_reports_the_runtime_selector_without_decoding() {
        let selected = nntmux_yenc_payload_kernel_v1();
        assert!(selected <= 4);
        #[cfg(target_arch = "aarch64")]
        assert_eq!(selected, 4);
        #[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
        if selected == 3 {
            assert!(is_x86_feature_detected!("avx2"));
            assert!(is_x86_feature_detected!("avx512vbmi2"));
            assert!(is_x86_feature_detected!("avx512bw"));
            assert!(is_x86_feature_detected!("avx512vl"));
            assert!(is_x86_feature_detected!("popcnt"));
        }
        assert_eq!(decode_payload(b"klm").unwrap(), b"ABC");
        assert_eq!(nntmux_yenc_payload_kernel_v1(), selected);
    }

    #[test]
    fn framing_export_retains_trailer_only_part_without_single_part_authority() {
        let body =
            b"=ybegin line=128 size=3 name=x.rar\r\nklm\r\n=yend size=3 part=1 pcrc32=a3830348\r\n";
        let output = unsafe { nntmux_yenc_framing_metadata_json(body.as_ptr(), body.len()) };
        assert!(!output.is_null());
        let json: serde_json::Value = unsafe {
            let text = std::ffi::CStr::from_ptr(output).to_bytes();
            let parsed = serde_json::from_slice(text);
            nntmux_metadata_string_free(output);
            parsed.unwrap()
        };
        assert_eq!(json["frames"][0]["yend_part_number"], 1);
        assert_eq!(json["frames"][0]["multipart_pcrc32_status"], "present-hex");
        assert_eq!(json["frames"][0]["size_relationship_status"], "incomplete");
        assert_eq!(
            json["frames"][0]["expected_segment_size"],
            serde_json::Value::Null
        );
    }
    use std::alloc::{GlobalAlloc, Layout, System};
    use std::cell::Cell;
    use std::ffi::CStr;

    thread_local! {
        static COUNT_ALLOCATIONS: Cell<bool> = const { Cell::new(false) };
        static ALLOCATION_COUNT: Cell<usize> = const { Cell::new(0) };
    }

    struct ObservedAllocator;

    #[global_allocator]
    static TEST_ALLOCATOR: ObservedAllocator = ObservedAllocator;

    unsafe impl GlobalAlloc for ObservedAllocator {
        unsafe fn alloc(&self, layout: Layout) -> *mut u8 {
            count_allocation();
            unsafe { System.alloc(layout) }
        }

        unsafe fn alloc_zeroed(&self, layout: Layout) -> *mut u8 {
            count_allocation();
            unsafe { System.alloc_zeroed(layout) }
        }

        unsafe fn realloc(&self, pointer: *mut u8, layout: Layout, size: usize) -> *mut u8 {
            count_allocation();
            unsafe { System.realloc(pointer, layout, size) }
        }

        unsafe fn dealloc(&self, pointer: *mut u8, layout: Layout) {
            unsafe { System.dealloc(pointer, layout) }
        }
    }

    fn count_allocation() {
        let _ = COUNT_ALLOCATIONS.try_with(|enabled| {
            if enabled.get() {
                let _ = ALLOCATION_COUNT.try_with(|count| count.set(count.get() + 1));
            }
        });
    }

    pub(crate) fn observed_allocations<T>(operation: impl FnOnce() -> T) -> (T, usize) {
        struct StopCounting;
        impl Drop for StopCounting {
            fn drop(&mut self) {
                COUNT_ALLOCATIONS.with(|enabled| enabled.set(false));
            }
        }
        COUNT_ALLOCATIONS.with(|enabled| assert!(!enabled.get()));
        ALLOCATION_COUNT.with(|count| count.set(0));
        COUNT_ALLOCATIONS.with(|enabled| enabled.set(true));
        let stop = StopCounting;
        let result = operation();
        drop(stop);
        (result, ALLOCATION_COUNT.with(Cell::get))
    }

    #[test]
    fn article_dates_do_not_allocate_for_unrelated_headers() {
        let input = b"Subject: unrelated\r\n\tfolded subject\r\nX-Trace: unrelated\r\n\r\nDate: 01 Jan 2024 00:00 +0000\r\n";
        let (metadata, allocations) = observed_allocations(|| article_dates::parse(input));
        assert_eq!(metadata.date.parse_status, "missing");
        assert_eq!(metadata.injection_date.parse_status, "missing");
        assert_eq!(metadata.legacy_nntp_posting_date.parse_status, "missing");
        assert_eq!(metadata.expires.parse_status, "missing");
        assert_eq!(
            allocations, 0,
            "unrelated fields have no retained date evidence"
        );
    }

    #[test]
    fn abi_decodes_without_heap_allocation() {
        let payload = b"klm=@=J=M=}..\x0b==";
        let mut output = [0xcc; 32];
        ALLOCATION_COUNT.with(|count| count.set(0));
        COUNT_ALLOCATIONS.with(|enabled| enabled.set(true));
        let written = unsafe {
            rapidyenc_decode_ex(
                0,
                payload.as_ptr().cast(),
                output.as_mut_ptr().cast(),
                payload.len(),
                ptr::null_mut(),
            )
        };
        COUNT_ALLOCATIONS.with(|enabled| enabled.set(false));
        let allocations = ALLOCATION_COUNT.with(Cell::get);
        assert_eq!(&output[..written], b"ABC\xd6\xe0\xe3\x13\x04\x04\xe1\xd3");
        assert_eq!(
            allocations, 0,
            "the caller already supplies the destination buffer"
        );
    }

    fn decode_via_abi(payload: &[u8]) -> Result<Vec<u8>, usize> {
        let mut output = vec![0u8; payload.len()];
        let written = unsafe {
            rapidyenc_decode_ex(
                0,
                payload.as_ptr().cast(),
                output.as_mut_ptr().cast(),
                payload.len(),
                ptr::null_mut(),
            )
        };
        if written > payload.len() {
            return Err(written);
        }
        output.truncate(written);

        Ok(output)
    }

    #[test]
    fn span_abi_decodes_all_values_from_nonzero_offsets_with_optional_crc_and_exact_writes() {
        let original: Vec<u8> = (0..=255).cycle().take(4096).collect();
        let mut payload = Vec::new();
        for &byte in &original {
            let encoded = byte.wrapping_add(42);
            if matches!(encoded, 0 | 10 | 13 | 61) {
                payload.extend_from_slice(&[b'=', b'\r', b'\n', encoded.wrapping_add(64)]);
            } else {
                payload.push(encoded);
            }
            if payload.len() % 31 == 0 {
                payload.extend_from_slice(b"\r\n");
            }
        }
        let mut source = b"unselected prefix=".to_vec();
        let offset = source.len();
        source.extend_from_slice(&payload);
        source.extend_from_slice(b"unselected suffix=");
        for alignment in 0..32 {
            for with_crc in [false, true] {
                let mut output = vec![0xa5; payload.len() + 64];
                let mut crc = 0xa1b2_c3d4;
                let written = unsafe {
                    nntmux_yenc_decode_span_v1(
                        source.as_ptr().cast(),
                        source.len(),
                        offset,
                        payload.len(),
                        output.as_mut_ptr().add(alignment).cast(),
                        payload.len(),
                        if with_crc { &mut crc } else { ptr::null_mut() },
                    )
                };
                assert_eq!(written, original.len());
                assert_eq!(&output[alignment..alignment + written], &original);
                assert!(output[..alignment].iter().all(|&byte| byte == 0xa5));
                assert!(
                    output[alignment + written..]
                        .iter()
                        .all(|&byte| byte == 0xa5)
                );
                assert_eq!(
                    crc,
                    if with_crc {
                        crc32fast::hash(&original)
                    } else {
                        0xa1b2_c3d4
                    }
                );
            }
        }
    }

    #[test]
    fn span_abi_rejects_invalid_arguments_without_writing_destination_or_crc() {
        let source = b"klm";
        let cases = [
            (
                source.len(),
                source.len() + 1,
                0,
                source.len(),
                false,
                false,
            ),
            (source.len(), source.len(), 1, source.len(), false, false),
            (source.len(), 1, source.len(), source.len(), false, false),
            (
                source.len(),
                0,
                source.len(),
                source.len() - 1,
                false,
                false,
            ),
            (source.len(), usize::MAX, 0, source.len(), false, false),
            (source.len(), 0, usize::MAX, usize::MAX, false, false),
            (usize::MAX, 0, 0, 0, false, false),
            (isize::MAX as usize + 1, 0, 0, 0, false, false),
            (source.len(), 0, 0, 0, true, false),
            (source.len(), 0, source.len(), source.len(), false, true),
            (0, 1, 0, 0, true, true),
            (0, 0, 1, 1, true, false),
        ];
        for (source_length, offset, length, capacity, null_source, null_destination) in cases {
            let mut output = [0xa5; 8];
            let mut crc = 0xa1b2_c3d4;
            let written = unsafe {
                nntmux_yenc_decode_span_v1(
                    if null_source {
                        ptr::null()
                    } else {
                        source.as_ptr().cast()
                    },
                    source_length,
                    offset,
                    length,
                    if null_destination {
                        ptr::null_mut()
                    } else {
                        output.as_mut_ptr().cast()
                    },
                    capacity,
                    &mut crc,
                )
            };
            assert_eq!(
                written, DECODE_FAILED,
                "source {source_length}, offset {offset}, length {length}, capacity {capacity}"
            );
            assert_eq!(output, [0xa5; 8]);
            assert_eq!(crc, 0xa1b2_c3d4);
        }
    }

    #[test]
    fn span_abi_accepts_empty_spans_and_initializes_only_requested_crc() {
        let source = b"prefix";
        for (pointer, source_length, offset) in [
            (ptr::null(), 0, 0),
            (source.as_ptr().cast(), source.len(), 0),
            (source.as_ptr().cast(), source.len(), source.len()),
        ] {
            let mut crc = 0xa1b2_c3d4;
            let written = unsafe {
                nntmux_yenc_decode_span_v1(
                    pointer,
                    source_length,
                    offset,
                    0,
                    ptr::null_mut(),
                    0,
                    &mut crc,
                )
            };
            assert_eq!(written, 0);
            assert_eq!(crc, 0);
            assert_eq!(
                unsafe {
                    nntmux_yenc_decode_span_v1(
                        pointer,
                        source_length,
                        offset,
                        0,
                        ptr::null_mut(),
                        0,
                        ptr::null_mut(),
                    )
                },
                0
            );
        }
    }

    #[test]
    fn span_abi_preserves_lenient_split_escape_and_dangling_marker_semantics() {
        for (payload, expected) in [
            (b"klm\r\n".as_slice(), b"ABC".as_slice()),
            (b"k=\r\n@", b"A\xd6"),
            (b"=\r\n=\n=\r=", b"\xd3\xd3"),
            (b"k=\r\n", b"A\x13"),
            (b"\r\n\r\n", b""),
        ] {
            let mut source = b"prefix".to_vec();
            let offset = source.len();
            source.extend_from_slice(payload);
            source.extend_from_slice(b"suffix");
            let mut output = [0xa5; 32];
            let mut crc = 0;
            let written = unsafe {
                nntmux_yenc_decode_span_v1(
                    source.as_ptr().cast(),
                    source.len(),
                    offset,
                    payload.len(),
                    output.as_mut_ptr().cast(),
                    output.len(),
                    &mut crc,
                )
            };
            assert_eq!(written, expected.len());
            assert_eq!(&output[..written], expected);
            assert!(output[written..].iter().all(|&byte| byte == 0xa5));
            assert_eq!(crc, crc32fast::hash(expected));
        }
    }

    #[test]
    fn span_abi_supports_both_overlap_directions_and_in_place_exact_writes() {
        let payload = [b"klm=@=J=M=}\r\n".repeat(9), b"=\r\n@===".to_vec()].concat();
        let expected = [b"ABC\xd6\xe0\xe3\x13".repeat(9), b"\xd6\xd3\x13".to_vec()].concat();
        for destination_start in [16, 40, 41, 63] {
            let mut storage = vec![0xa5; payload.len() + 128];
            storage[40..40 + payload.len()].copy_from_slice(&payload);
            let before = storage.clone();
            let mut crc = 0;
            let written = unsafe {
                nntmux_yenc_decode_span_v1(
                    storage.as_ptr().cast(),
                    storage.len(),
                    40,
                    payload.len(),
                    storage.as_mut_ptr().add(destination_start).cast(),
                    payload.len(),
                    &mut crc,
                )
            };
            assert_eq!(written, expected.len());
            assert_eq!(
                &storage[destination_start..destination_start + written],
                &expected
            );
            assert_eq!(&storage[..destination_start], &before[..destination_start]);
            assert_eq!(
                &storage[destination_start + written..],
                &before[destination_start + written..]
            );
            assert_eq!(crc, crc32fast::hash(&expected));
        }
    }

    #[test]
    fn span_abi_uses_no_heap_allocation_for_disjoint_buffers_even_with_crc() {
        let payload = b"klm=@=J=M=}..\x0b==";
        for with_crc in [false, true] {
            let mut output = [0xa5; 32];
            let mut crc = 0;
            ALLOCATION_COUNT.with(|count| count.set(0));
            COUNT_ALLOCATIONS.with(|enabled| enabled.set(true));
            let written = unsafe {
                nntmux_yenc_decode_span_v1(
                    payload.as_ptr().cast(),
                    payload.len(),
                    0,
                    payload.len(),
                    output.as_mut_ptr().cast(),
                    output.len(),
                    if with_crc { &mut crc } else { ptr::null_mut() },
                )
            };
            COUNT_ALLOCATIONS.with(|enabled| enabled.set(false));
            let allocations = ALLOCATION_COUNT.with(Cell::get);
            assert_eq!(written, 11);
            assert_eq!(allocations, 0);
        }
    }

    #[test]
    fn span_abi_writes_unaligned_crc_without_touching_adjacent_bytes() {
        let payload = b"klm";
        let mut output = [0xa5; 8];
        let mut crc_storage = [0xa5; 8];
        let crc_pointer = unsafe { crc_storage.as_mut_ptr().add(1).cast::<u32>() };
        let written = unsafe {
            nntmux_yenc_decode_span_v1(
                payload.as_ptr().cast(),
                payload.len(),
                0,
                payload.len(),
                output.as_mut_ptr().cast(),
                output.len(),
                crc_pointer,
            )
        };
        assert_eq!(written, 3);
        assert_eq!(&output[..written], b"ABC");
        assert!(output[written..].iter().all(|&byte| byte == 0xa5));
        assert_eq!(
            unsafe { crc_pointer.read_unaligned() },
            crc32fast::hash(b"ABC")
        );
        assert_eq!(crc_storage[0], 0xa5);
        assert_eq!(&crc_storage[5..], &[0xa5; 3]);
    }

    #[test]
    #[cfg(not(feature = "experimental-xz-index"))]
    fn default_format_abi_keeps_unqualified_index_extraction_inactive() {
        let sample = [
            253, 55, 122, 88, 90, 0, 0, 0, 255, 18, 217, 65, 2, 192, 5, 1, 33, 1, 0, 0, 240, 93,
            251, 159, 1, 0, 0, 65, 0, 0, 0, 0, 0, 1, 17, 1, 173, 166, 88, 4,
        ];
        let pointer = unsafe { nntmux_format_metadata_json(sample.as_ptr(), sample.len()) };
        assert!(!pointer.is_null());
        let json = unsafe { CStr::from_ptr(pointer) }.to_bytes().to_owned();
        unsafe { nntmux_metadata_string_free(pointer) };
        let metadata: serde_json::Value = serde_json::from_slice(&json).unwrap();
        let block = &metadata["headers"][0]["first_block"];
        assert_eq!(block["compressed_size"]["value"], 5);
        assert!(block.get("following_index").is_none());
        assert!(block.get("following_footer").is_none());
    }

    #[test]
    #[cfg(feature = "experimental-xz-index")]
    fn experimental_format_abi_exposes_bounded_footer_evidence() {
        let sample = [
            253, 55, 122, 88, 90, 0, 0, 0, 255, 18, 217, 65, 2, 192, 5, 1, 33, 1, 0, 0, 240, 93,
            251, 159, 1, 0, 0, 65, 0, 0, 0, 0, 0, 1, 17, 1, 173, 166, 88, 4, 6, 114, 158, 122, 1,
            0, 0, 0, 0, 0, 89, 90,
        ];
        let pointer = unsafe { nntmux_format_metadata_json(sample.as_ptr(), sample.len()) };
        assert!(!pointer.is_null());
        let json = unsafe { CStr::from_ptr(pointer) }.to_bytes().to_owned();
        unsafe { nntmux_metadata_string_free(pointer) };
        let metadata: serde_json::Value = serde_json::from_slice(&json).unwrap();
        let block = &metadata["headers"][0]["first_block"];
        let footer = &block["following_footer"];
        assert_eq!(
            footer["status"],
            "footer_index_and_header_declarations_match"
        );
        assert_eq!(footer["observed_hex"], "06729e7a010000000000595a");
        assert_eq!(footer["start_offset"], 40);
        assert_eq!(footer["end_offset_exclusive"], 52);
        assert_eq!(block["whole_stream_validated"], false);
        assert_eq!(block["content_decompressed"], false);
    }

    #[test]
    fn exposes_iso_character_set_declaration_through_ffi() {
        let offset = 16 * 2048;
        let mut sample = vec![0u8; offset + 2048];
        sample[offset] = 2;
        sample[offset + 1..offset + 6].copy_from_slice(b"CD001");
        sample[offset + 6] = 1;
        sample[offset + 88..offset + 91].copy_from_slice(b"%/E");
        sample[offset + 881] = 1;
        let pointer = unsafe { nntmux_format_metadata_json(sample.as_ptr(), sample.len()) };
        assert!(!pointer.is_null());
        let json = unsafe { CStr::from_ptr(pointer) }
            .to_str()
            .unwrap()
            .to_owned();
        unsafe { nntmux_metadata_string_free(pointer) };
        let parsed: serde_json::Value = serde_json::from_str(&json).unwrap();
        let sequence = &parsed["iso_descriptor_sequence"];
        assert_eq!(sequence["status"], "sample_truncated");
        assert_eq!(sequence["image_origin_known"], false);
        assert_eq!(sequence["descriptors"][0]["offset"], offset);
        assert_eq!(
            sequence["descriptors"][0]["character_set_declaration"]["recognized_character_set"],
            "ucs2_be"
        );
        assert_eq!(
            sequence["descriptors"][0]["character_set_declaration"]["joliet_prefix_level_observed"],
            3
        );
    }

    #[test]
    fn exposes_ordered_provider_header_comparison_through_ffi() {
        let left = b"X-Test: a\r\nX-Test: b\r\n\r\n";
        let right = b"X-Test: a, b\r\n\r\n";
        let mut packed = Vec::new();
        packed.extend_from_slice(&(left.len() as u32).to_le_bytes());
        packed.extend_from_slice(left);
        packed.extend_from_slice(&(right.len() as u32).to_le_bytes());
        packed.extend_from_slice(right);
        let pointer =
            unsafe { nntmux_nntp_header_pair_comparison_json(packed.as_ptr(), packed.len(), 1) };
        assert!(!pointer.is_null());
        let json = unsafe { CStr::from_ptr(pointer) }
            .to_str()
            .unwrap()
            .to_owned();
        unsafe { nntmux_metadata_string_free(pointer) };
        let parsed: serde_json::Value = serde_json::from_str(&json).unwrap();
        assert_eq!(
            parsed["same_article_comparison_status"],
            "eligible_same_article"
        );
        assert_eq!(parsed["fields"][0]["left_occurrence_count"], 2);
        assert_eq!(parsed["fields"][0]["right_occurrence_count"], 1);
        assert_eq!(parsed["fields"][0]["unfolded_value_hashes_match"], false);
    }

    #[test]
    fn exposes_lines_body_relationship_through_ffi() {
        let headers = b"Lines: 2\r\n\r\n";
        let body = b"a\r\n\r\n";
        let mut packed = Vec::new();
        packed.extend_from_slice(&(headers.len() as u32).to_le_bytes());
        packed.extend_from_slice(headers);
        packed.extend_from_slice(body);
        let pointer =
            unsafe { nntmux_nntp_lines_body_relationship_json(packed.as_ptr(), packed.len()) };
        assert!(!pointer.is_null());
        let json = unsafe { CStr::from_ptr(pointer) }
            .to_str()
            .unwrap()
            .to_owned();
        unsafe { nntmux_metadata_string_free(pointer) };
        let parsed: serde_json::Value = serde_json::from_str(&json).unwrap();
        assert_eq!(parsed["status"], "match");
        assert_eq!(parsed["declared_body_line_count"], 2);
        assert_eq!(parsed["observed_body_line_count"], 2);
    }

    #[test]
    fn rejects_over_budget_lines_body_headers_at_the_ffi_boundary() {
        let headers = vec![b'x'; MAX_NNTP_HEADER_INPUT_BYTES + 1];
        let mut packed = Vec::new();
        packed.extend_from_slice(&(headers.len() as u32).to_le_bytes());
        packed.extend_from_slice(&headers);
        packed.extend_from_slice(b"body\r\n");
        let pointer =
            unsafe { nntmux_nntp_lines_body_relationship_json(packed.as_ptr(), packed.len()) };
        assert!(pointer.is_null());
    }

    #[test]
    fn passes_the_known_answer_check_native_payload_decoder_runs_on_load() {
        assert_eq!(
            decode_via_abi(b"klm=@=J=M=}..\x0b==").unwrap(),
            b"ABC\xd6\xe0\xe3\x13\x04\x04\xe1\xd3"
        );
    }

    #[test]
    fn skips_line_endings_in_raw_payloads() {
        assert_eq!(decode_via_abi(b"klm\r\nklm\nklm").unwrap(), b"ABCABCABC");
    }

    #[test]
    fn round_trips_every_byte_value_through_the_encoder() {
        let original: Vec<u8> = (0..=255).cycle().take(4096).collect();
        let (article, _) =
            nzb_decode::yenc::encode_article(&original, "all.bin", 1, 1, 0, original.len() as u64);
        let text = article.as_slice();
        let start = text.windows(2).position(|w| w == b"\r\n").unwrap() + 2;
        let start = if text[start..].starts_with(b"=ypart ") {
            start + text[start..].windows(2).position(|w| w == b"\r\n").unwrap() + 2
        } else {
            start
        };
        let end = start
            + text[start..]
                .windows(5)
                .position(|w| w == b"=yend")
                .unwrap();

        assert_eq!(decode_via_abi(&text[start..end]).unwrap(), original);
    }

    #[test]
    fn treats_line_leading_yenc_keywords_as_escaped_data() {
        let expected = [
            121u8.wrapping_sub(106),
            101 - 42,
            110 - 42,
            100 - 42,
            120 - 42,
        ];
        assert_eq!(decode_via_abi(b"=yendx").unwrap(), expected);
        assert_eq!(
            decode_via_abi(b"klm\r\n=yendx").unwrap(),
            [b"ABC".as_slice(), &expected].concat()
        );
        assert_eq!(decode_via_abi(b"=y=ypart x").unwrap().len(), 8);
    }

    #[cfg(not(feature = "experimental-rapidyenc-abi"))]
    #[test]
    fn current_policy_default_advertises_incumbent_split_escape_capability() {
        assert_eq!(nntmux_yenc_supports_split_escapes(), 1);
    }

    #[cfg(not(feature = "experimental-rapidyenc-abi"))]
    #[test]
    fn current_policy_default_decode_ex_keeps_lenient_split_escapes() {
        for (payload, expected) in [
            (b"k=\r\n@".as_slice(), b"A\xd6".as_slice()),
            (b"=\n@".as_slice(), b"\xd6".as_slice()),
            (b"=\r@".as_slice(), b"\xd6".as_slice()),
            (b"=\r\n\r\n@".as_slice(), b"\xd6".as_slice()),
        ] {
            assert_eq!(decode_payload(payload).unwrap(), expected);
            assert_eq!(decode_via_abi(payload).unwrap(), expected);
        }
    }

    #[cfg(not(feature = "experimental-rapidyenc-abi"))]
    #[test]
    fn current_policy_default_decode_ex_ignores_raw_flag_like_incumbent() {
        let payload = b"..klm";
        let expected = b"\x04\x04ABC";
        assert_eq!(decode_payload(payload).unwrap(), expected);
        for raw in [0, 1, -1] {
            let mut output = [0xcc; 16];
            let written = unsafe {
                rapidyenc_decode_ex(
                    raw,
                    payload.as_ptr().cast(),
                    output.as_mut_ptr().cast(),
                    payload.len(),
                    ptr::null_mut(),
                )
            };
            assert_eq!(written, expected.len(), "raw flag {raw}");
            assert_eq!(&output[..written], expected);
            assert!(output[written..].iter().all(|&byte| byte == 0xcc));
        }
    }

    #[cfg(not(feature = "experimental-rapidyenc-abi"))]
    #[test]
    fn current_policy_default_decode_ex_leaves_state_untouched_like_incumbent() {
        let payload = b"klm";
        for initial in [0, 1, 6, c_int::MIN] {
            let mut state = initial;
            let mut output = [0xcc; 16];
            let written = unsafe {
                rapidyenc_decode_ex(
                    0,
                    payload.as_ptr().cast(),
                    output.as_mut_ptr().cast(),
                    payload.len(),
                    &mut state,
                )
            };
            assert_eq!(state, initial, "incumbent ignores state storage");
            assert_eq!(written, 3, "incumbent ignores state value {initial}");
            assert_eq!(&output[..written], b"ABC");
            assert!(output[written..].iter().all(|&byte| byte == 0xcc));
        }
    }

    #[cfg(not(feature = "experimental-rapidyenc-abi"))]
    #[test]
    fn implementation_profile_default_identifies_incumbent() {
        rapidyenc_decode_init();
        rapidyenc_crc_init();
        assert_eq!(nntmux_yenc_implementation_profile_v1(), 0);
        assert_eq!(nntmux_yenc_supports_split_escapes(), 1);
        assert_eq!(rapidyenc_version(), 0x0103);
    }

    #[cfg(feature = "experimental-rapidyenc-abi")]
    #[test]
    fn implementation_profile_experimental_identifies_canonical_candidate() {
        rapidyenc_decode_init();
        rapidyenc_crc_init();
        assert_eq!(nntmux_yenc_implementation_profile_v1(), 1);
        assert_eq!(nntmux_yenc_supports_split_escapes(), 0);
        assert_eq!(rapidyenc_version(), 0x0103);
    }

    #[cfg(feature = "experimental-rapidyenc-abi")]
    #[test]
    fn implementation_profile_experimental_exports_keep_raw_and_state_semantics() {
        for (raw, expected) in [(0, b"\x04\x04ABC".as_slice()), (1, b"\x04ABC".as_slice())] {
            let payload = b"..klm";
            let mut state = 0;
            let mut output = [0xcc; 16];
            let written = unsafe {
                rapidyenc_decode_ex(
                    raw,
                    payload.as_ptr().cast(),
                    output.as_mut_ptr().cast(),
                    payload.len(),
                    &mut state,
                )
            };
            assert_eq!(state, 3);
            assert_eq!(written, expected.len());
            assert_eq!(&output[..written], expected);
            assert!(output[written..].iter().all(|&byte| byte == 0xcc));
        }
    }

    #[test]
    fn payload_helper_preserves_lenient_split_escapes() {
        assert_eq!(decode_payload(b"k=\r\n@").unwrap(), b"A\xd6");
        #[cfg(not(feature = "experimental-rapidyenc-abi"))]
        assert_eq!(decode_via_abi(b"k=\r\n@").unwrap(), b"A\xd6");
        #[cfg(feature = "experimental-rapidyenc-abi")]
        assert_eq!(decode_via_abi(b"k=\r\n@").unwrap(), b"A\xa3\x16");
    }

    #[test]
    fn advertised_split_escape_capability_matches_abi_decoding() {
        let advertised = nntmux_yenc_supports_split_escapes() == 1;
        for payload in [b"=\n@".as_slice(), b"=\r@", b"=\r\n\r\n@", b"=\r\n=\n=\r="] {
            let expected = if payload.ends_with(b"@") {
                b"\xd6".as_slice()
            } else {
                b"\xd3\xd3".as_slice()
            };
            assert_eq!(advertised, decode_via_abi(payload).unwrap() == expected);
            assert_eq!(decode_payload(payload).unwrap(), expected);
        }
    }

    #[test]
    fn decodes_empty_input_to_nothing() {
        assert_eq!(decode_via_abi(b"").unwrap(), b"");
    }

    #[test]
    fn crc_matches_php_crc32b() {
        let data = b"ABC";
        assert_eq!(
            unsafe { rapidyenc_crc(data.as_ptr().cast(), data.len(), 0) },
            0xa383_0348
        );
    }

    #[test]
    fn crc_continues_from_an_initial_value() {
        let whole = unsafe { rapidyenc_crc(b"ABCDEF".as_ptr().cast(), 6, 0) };
        let first = unsafe { rapidyenc_crc(b"ABC".as_ptr().cast(), 3, 0) };
        let resumed = unsafe { rapidyenc_crc(b"DEF".as_ptr().cast(), 3, first) };
        assert_eq!(resumed, whole);
    }

    #[test]
    fn reports_the_compatible_shim_version() {
        assert_eq!(rapidyenc_version(), 0x0103);
    }
    #[test]
    fn sampling_abi_checks_input_bounds_and_result_ownership() {
        unsafe {
            assert!(nntmux_spot_check_sample_v1_json(ptr::null(), 1, 1, 2, 0, 0).is_null());
            assert!(nntmux_spot_check_sample_v1_json(ptr::null(), 0, 1, 2, 0, 0).is_null());
            assert!(
                nntmux_spot_check_sample_v1_json(
                    ptr::null(),
                    MAX_METADATA_INPUT_BYTES + 1,
                    1,
                    2,
                    0,
                    0
                )
                .is_null()
            );
            nntmux_metadata_string_free(ptr::null_mut());
            let input =
                br#"{"files":[{"key":"f","segments":[{"number":1,"messageid":"m","bytes":2}]}]}"#;
            let result = nntmux_spot_check_sample_v1_json(input.as_ptr(), input.len(), 1, 2, 0, 0);
            assert!(!result.is_null());
            assert_eq!(std::ffi::CStr::from_ptr(result).to_bytes(), br#"[{"file_key":"f","totalparts":0,"totalparts_inferred":false,"number":1,"messageid":"m","bytes":2}]"#);
            nntmux_metadata_string_free(result);
        }
    }

    #[test]
    fn header_filename_identity_reports_the_pcre_version_of_its_tables() {
        let version =
            unsafe { std::ffi::CStr::from_ptr(nntmux_header_filename_identity_tables_v1()) };
        assert!(version.to_bytes().starts_with(b"10."), "{version:?}");
    }

    #[test]
    fn header_filename_identity_abi_checks_input_bounds_and_returns_verdicts() {
        unsafe {
            let subject = br#"[1/9] - "Readable.Release.Name.mkv" yEnc"#;
            let same = b"Readable.Release.Name.mkv";
            let other = b"Different.Release.mkv";
            let call = |s: &[u8], a: &[u8]| {
                nntmux_header_filename_identity_v1(s.as_ptr(), s.len(), a.as_ptr(), a.len())
            };
            assert_eq!(call(subject, same), 1);
            assert_eq!(call(subject, other), 0);
            // Empty arguments need no pointer; an invalid UTF-8 subject is rejected like PHP.
            assert_eq!(
                nntmux_header_filename_identity_v1(ptr::null(), 0, ptr::null(), 0),
                1
            );
            assert_eq!(call(b"\"a\xff.mkv\"", other), 0);
            assert_eq!(
                nntmux_header_filename_identity_v1(ptr::null(), 1, same.as_ptr(), same.len()),
                -1
            );
            assert_eq!(
                nntmux_header_filename_identity_v1(subject.as_ptr(), subject.len(), ptr::null(), 1),
                -1
            );
            let oversized = vec![b'a'; MAX_HEADER_FILENAME_INPUT_BYTES + 1];
            assert_eq!(call(&oversized, same), -1);
            assert_eq!(call(subject, &oversized), -1);
            assert_eq!(call(&oversized[..MAX_HEADER_FILENAME_INPUT_BYTES], same), 0);
            // A valid non-ASCII executable name against an unquoted subject needs PCRE's Unicode
            // case folding, so the port declines; ASCII and invalid UTF-8 names are decided.
            assert_eq!(
                call(
                    "Release \u{e9}.exe yEnc".as_bytes(),
                    "\u{e9}.exe".as_bytes()
                ),
                -1
            );
            assert_eq!(call(b"Release yEnc", b"a\xff.exe"), 0);
            assert_eq!(call(b"Release setup.exe yEnc", b"SETUP.EXE"), 1);
            assert_eq!(call(b"Release yEnc", b"setup.exe"), 0);
        }
    }
}

#[cfg(test)]
mod article_fingerprint_ffi_tests {
    use super::*;

    #[test]
    fn fingerprint_ffi_owns_state_and_never_returns_partial_identity() {
        unsafe {
            let state = nntmux_article_fingerprint_new();
            assert!(!state.is_null());
            let mut output = [0xa5; 40];
            assert_eq!(
                nntmux_article_fingerprint_value(state, output.as_mut_ptr()),
                0
            );
            assert_eq!(output, [0xa5; 40]);
            assert_eq!(
                nntmux_article_fingerprint_add(state, b"a1@x".as_ptr(), 4),
                1
            );
            assert_eq!(
                nntmux_article_fingerprint_value(state, output.as_mut_ptr()),
                1
            );
            assert_eq!(&output[..8], &1u64.to_be_bytes());
            assert_eq!(
                nntmux_article_fingerprint_add(state, b"bad id".as_ptr(), 6),
                0
            );
            output.fill(0xa5);
            assert_eq!(
                nntmux_article_fingerprint_value(state, output.as_mut_ptr()),
                0
            );
            assert_eq!(output, [0xa5; 40]);
            assert_eq!(
                nntmux_article_fingerprint_add(state, b"later@x".as_ptr(), 7),
                0
            );
            nntmux_article_fingerprint_free(state);
            nntmux_article_fingerprint_free(ptr::null_mut());
        }
    }

    #[test]
    fn fingerprint_ffi_rejects_null_arguments() {
        unsafe {
            assert_eq!(
                nntmux_article_fingerprint_add(ptr::null_mut(), ptr::null(), 0),
                0
            );
            assert_eq!(
                nntmux_article_fingerprint_value(ptr::null_mut(), ptr::null_mut()),
                0
            );
            let state = nntmux_article_fingerprint_new();
            assert_eq!(nntmux_article_fingerprint_add(state, ptr::null(), 1), 0);
            assert_eq!(nntmux_article_fingerprint_value(state, ptr::null_mut()), 0);
            nntmux_article_fingerprint_free(state);
        }
    }
}
