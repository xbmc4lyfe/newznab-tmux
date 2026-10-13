//! RapidYenc-compatible C ABI for NNTmux. Payload decoding writes directly
//! into the caller's buffer; metadata parsers expose separate JSON exports.
//! Framing and CRC validation remain at the PHP caller boundary.

use std::ffi::{CString, c_char, c_int, c_void};
use std::panic::catch_unwind;
use std::ptr;
use std::slice;

mod article_dates;
mod article_identity;
mod article_xref;
mod formats;
mod nntp_body;
mod nntp_headers;
mod par2;
mod par2_source_ranges;
mod payload_decoder;
#[cfg(target_arch = "aarch64")]
mod payload_decoder_arm;
#[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
mod payload_decoder_x86;
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
pub extern "C" fn rapidyenc_decode_init() {}

#[unsafe(no_mangle)]
pub extern "C" fn rapidyenc_version() -> c_int {
    VERSION
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

/// Decode `src_length` bytes from `src` into `dest` and return the decoded length.
///
/// `is_raw` and `state` exist for RapidYenc compatibility; NNTmux always passes
/// 0 and NULL, and dot-unstuffing has already happened by then.
///
/// # Safety
///
/// `src` must be readable and `dest` writable for `src_length` bytes. Decoded
/// yEnc is never longer than its input.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn rapidyenc_decode_ex(
    _is_raw: c_int,
    src: *const c_void,
    dest: *mut c_void,
    src_length: usize,
    _state: *mut c_int,
) -> usize {
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

#[unsafe(no_mangle)]
pub extern "C" fn rapidyenc_crc_init() {}

/// CRC-32 (the same digest as PHP's `crc32b`), continuing from `init_crc`.
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
    if src_length == 0 || src.is_null() {
        return init_crc;
    }
    // SAFETY: the caller guarantees `src` is readable for `src_length` bytes.
    let data = unsafe { slice::from_raw_parts(src.cast::<u8>(), src_length) };
    let mut hasher = crc32fast::Hasher::new_with_initial(init_crc);
    hasher.update(data);

    hasher.finalize()
}

#[cfg(test)]
mod tests {
    use super::*;
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

    #[test]
    fn joins_escapes_split_across_a_line_break() {
        assert_eq!(decode_via_abi(b"k=\r\n@").unwrap(), b"A\xd6");
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
}
