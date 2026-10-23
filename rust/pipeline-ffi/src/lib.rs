use std::slice;

/// Returns the ABI version of `libnntmux_pipeline`.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_pipeline_abi_version() -> u32 {
    1
}

/// Zero-allocation cursor over a caller-supplied output buffer.
/// Continues tracking the required byte length even if capacity is exceeded so the caller
/// can reallocate and retry with the exact size.
struct OutCursor<'a> {
    buf: &'a mut [u8],
    pos: usize,
}

impl<'a> OutCursor<'a> {
    #[inline]
    unsafe fn new(ptr: *mut u8, cap: usize) -> Self {
        let buf = if ptr.is_null() || cap == 0 {
            &mut []
        } else {
            unsafe { slice::from_raw_parts_mut(ptr, cap) }
        };
        Self { buf, pos: 0 }
    }

    #[inline]
    fn push(&mut self, byte: u8) {
        if self.pos < self.buf.len() {
            self.buf[self.pos] = byte;
        }
        self.pos += 1;
    }

    #[inline]
    fn extend(&mut self, data: &[u8]) {
        let end = self.pos + data.len();
        if end <= self.buf.len() {
            self.buf[self.pos..end].copy_from_slice(data);
        }
        self.pos = end;
    }

    #[inline]
    fn finish(self) -> isize {
        if self.pos == 0 {
            0
        } else if self.pos <= self.buf.len() {
            self.pos as isize
        } else {
            -(self.pos as isize)
        }
    }
}

/// Small stack-backed deduplication set for group names within a single Xref header.
struct SeenGroups<'a> {
    stack: [&'a [u8]; 16],
    len: usize,
    overflow: Vec<&'a [u8]>,
}

impl<'a> SeenGroups<'a> {
    #[inline]
    fn new() -> Self {
        Self {
            stack: [&[]; 16],
            len: 0,
            overflow: Vec::new(),
        }
    }

    #[inline]
    fn clear(&mut self) {
        self.len = 0;
        self.overflow.clear();
    }

    #[inline]
    fn insert(&mut self, item: &'a [u8]) -> bool {
        for i in 0..self.len {
            if self.stack[i] == item {
                return false;
            }
        }
        if self.len < 16 {
            self.stack[self.len] = item;
            self.len += 1;
            return true;
        }
        if self.overflow.contains(&item) {
            return false;
        }
        self.overflow.push(item);
        true
    }

    #[inline]
    fn contains(&self, item: &[u8]) -> bool {
        for i in 0..self.len {
            if self.stack[i] == item {
                return true;
            }
        }
        self.overflow.contains(&item)
    }

    #[inline]
    fn write_space_separated(&self, out: &mut OutCursor<'_>) {
        for i in 0..self.len {
            if i > 0 {
                out.push(b' ');
            }
            out.extend(self.stack[i]);
        }
        for group in &self.overflow {
            out.push(b' ');
            out.extend(group);
        }
    }
}

/// Matches a single space-delimited Xref token using the exact PCRE semantics of
/// `XrefService::XREF_PATTERN_WITH_NUM` and `XrefService::XREF_PATTERN_NO_NUM`:
/// - `/(^[a-zA-Z]{2,3}\.(bin(aries|arios|aer))\.[a-zA-Z0-9]?.+)(:\d+)/`
/// - `/(^[a-zA-Z]{2,3}\.(bin(aries|arios|aer))\.[a-zA-Z0-9]?.+)/`
///
/// Because neither PCRE pattern sets `/s` (`PCRE_DOTALL`), `.` matches any byte except `\n`.
#[inline]
pub fn match_xref_token(token: &[u8]) -> Option<&[u8]> {
    if token.len() < 11 {
        return None;
    }

    // Limit to bytes before the first '\n' (since PCRE `.` without `/s` stops at '\n').
    let line_end = token
        .iter()
        .position(|&b| b == b'\n')
        .unwrap_or(token.len());
    let line = &token[..line_end];
    if line.len() < 11 {
        return None;
    }

    // `^[a-zA-Z]{2,3}\.`
    if !line[0].is_ascii_alphabetic() || !line[1].is_ascii_alphabetic() {
        return None;
    }
    let dot1 = if line[2] == b'.' {
        2
    } else if line[2].is_ascii_alphabetic() && line.get(3) == Some(&b'.') {
        3
    } else {
        return None;
    };

    // `(bin(aries|arios|aer))\.` (case-sensitive in XrefService)
    let after_dot1 = &line[dot1 + 1..];
    let after_prefix_rel =
        if after_dot1.starts_with(b"binaries.") || after_dot1.starts_with(b"binarios.") {
            9
        } else if after_dot1.starts_with(b"binaer.") {
            7
        } else {
            return None;
        };

    let after_prefix = dot1 + 1 + after_prefix_rel;
    // `[a-zA-Z0-9]?.+` requires at least 1 byte in `line` after the second dot.
    if line.len() <= after_prefix {
        return None;
    }

    // Try `XREF_PATTERN_WITH_NUM` first:
    // Greedy `.+` consumes as much as possible while leaving at least 1 byte after the second dot,
    // so it matches the rightmost `:\d+` starting at `colon_pos >= after_prefix + 1`.
    let mut i = line.len();
    while i > after_prefix + 2 {
        i -= 1;
        if line[i - 1] == b':' && line[i].is_ascii_digit() {
            let mut end = i + 1;
            while end < line.len() && line[end].is_ascii_digit() {
                end += 1;
            }
            return Some(&line[..end]);
        }
    }

    // Fallback to `XREF_PATTERN_NO_NUM`: greedy `.+` matches the rest of `line`.
    Some(line)
}

/// Strips a trailing `:\d+$` from a matched Xref token, matching `preg_replace('/:\d+$/', '', $token)`.
#[inline]
pub fn strip_xref_number(matched_token: &[u8]) -> &[u8] {
    if matched_token.is_empty() || !matched_token[matched_token.len() - 1].is_ascii_digit() {
        return matched_token;
    }
    let mut i = matched_token.len() - 1;
    while i > 0 && matched_token[i - 1].is_ascii_digit() {
        i -= 1;
    }
    if i > 0 && matched_token[i - 1] == b':' {
        &matched_token[..i - 1]
    } else {
        matched_token
    }
}

/// Extracts valid Xref tokens from `input[..input_len]` and writes them separated by `\n` into `out_buf`.
/// Returns 0 if no tokens match, `> 0` bytes written, or `-(required_len as isize)` if `out_cap` is too small.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_xref_extract_tokens(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() || input_len == 0 || (input_len == 1 && unsafe { *input } == b'0') {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    let mut first = true;
    for raw_token in bytes.split(|&b| b == b' ') {
        if let Some(matched) = match_xref_token(raw_token) {
            if !first {
                out.push(b'\n');
            }
            first = false;
            out.extend(matched);
        }
    }
    out.finish()
}

/// Extracts deduplicated group names from `input[..input_len]` in first-seen order and writes them
/// separated by `\n` into `out_buf`.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_xref_extract_groups(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() || input_len == 0 || (input_len == 1 && unsafe { *input } == b'0') {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    let mut seen = SeenGroups::new();
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    let mut first = true;
    for raw_token in bytes.split(|&b| b == b' ') {
        if let Some(matched) = match_xref_token(raw_token) {
            let group = strip_xref_number(matched);
            if !group.is_empty() && seen.insert(group) {
                if !first {
                    out.push(b'\n');
                }
                first = false;
                out.extend(group);
            }
        }
    }
    out.finish()
}

/// Computes `XrefService::diffNewTokens(existing, incoming)` and writes the resulting tokens
/// separated by `\n` into `out_buf`.
///
/// # Safety
///
/// `existing` and `incoming` must be valid for reads of their respective lengths when non-null.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_xref_diff_tokens(
    existing: *const u8,
    existing_len: usize,
    incoming: *const u8,
    incoming_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if incoming.is_null()
        || incoming_len == 0
        || (incoming_len == 1 && unsafe { *incoming } == b'0')
    {
        return 0;
    }
    let incoming_bytes = unsafe { slice::from_raw_parts(incoming, incoming_len) };
    let existing_bytes: &[u8] = if existing.is_null()
        || existing_len == 0
        || (existing_len == 1 && unsafe { *existing } == b'0')
    {
        &[]
    } else {
        unsafe { slice::from_raw_parts(existing, existing_len) }
    };

    let mut existing_tokens = SeenGroups::new();
    if !existing_bytes.is_empty() {
        for raw_token in existing_bytes.split(|&b| b == b' ') {
            if let Some(matched) = match_xref_token(raw_token) {
                existing_tokens.insert(matched);
            }
        }
    }

    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    let mut first = true;
    for raw_token in incoming_bytes.split(|&b| b == b' ') {
        if let Some(matched) = match_xref_token(raw_token)
            && !existing_tokens.contains(matched)
        {
            if !first {
                out.push(b'\n');
            }
            first = false;
            out.extend(matched);
        }
    }
    out.finish()
}

/// Batch processes NUL-separated (`\0`) Xref strings from `input[..input_len]`.
/// - `mode = 1`: space-separated tokens per record, records separated by `\n`.
/// - `mode = 2`: space-separated deduplicated group names per record, records separated by `\n`.
/// - `mode = 3`: `space_joined_tokens` + `\0` + `space_joined_groups` per record, records separated by `\n`.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_xref_extract_batch_nul(
    input: *const u8,
    input_len: usize,
    mode: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() && input_len > 0 {
        return -1;
    }
    if !matches!(mode, 1..=3) {
        return -1;
    }
    let bytes: &[u8] = if input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };

    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    let mut seen_groups = SeenGroups::new();

    for (idx, record) in bytes.split(|&b| b == 0).enumerate() {
        if idx > 0 {
            out.push(b'\n');
        }
        if record.is_empty() || record == b"0" {
            if mode == 3 {
                out.push(0);
            }
            continue;
        }

        match mode {
            1 => {
                let mut first = true;
                for raw_token in record.split(|&b| b == b' ') {
                    if let Some(matched) = match_xref_token(raw_token) {
                        if !first {
                            out.push(b' ');
                        }
                        first = false;
                        out.extend(matched);
                    }
                }
            }
            2 => {
                seen_groups.clear();
                let mut first = true;
                for raw_token in record.split(|&b| b == b' ') {
                    if let Some(matched) = match_xref_token(raw_token) {
                        let group = strip_xref_number(matched);
                        if !group.is_empty() && seen_groups.insert(group) {
                            if !first {
                                out.push(b' ');
                            }
                            first = false;
                            out.extend(group);
                        }
                    }
                }
            }
            3 => {
                seen_groups.clear();
                let mut first_tok = true;
                for raw_token in record.split(|&b| b == b' ') {
                    if let Some(matched) = match_xref_token(raw_token) {
                        if !first_tok {
                            out.push(b' ');
                        }
                        first_tok = false;
                        out.extend(matched);

                        let group = strip_xref_number(matched);
                        if !group.is_empty() {
                            seen_groups.insert(group);
                        }
                    }
                }
                out.push(0);
                seen_groups.write_space_separated(&mut out);
            }
            _ => unreachable!(),
        }
    }

    out.finish()
}

const MD5_S: [u32; 64] = [
    7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 5, 9, 14, 20, 5, 9, 14, 20, 5, 9,
    14, 20, 5, 9, 14, 20, 4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23, 6, 10, 15,
    21, 6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21,
];

const MD5_K: [u32; 64] = [
    0xd76aa478, 0xe8c7b756, 0x242070db, 0xc1bdceee, 0xf57c0faf, 0x4787c62a, 0xa8304613, 0xfd469501,
    0x698098d8, 0x8b44f7af, 0xffff5bb1, 0x895cd7be, 0x6b901122, 0xfd987193, 0xa679438e, 0x49b40821,
    0xf61e2562, 0xc040b340, 0x265e5a51, 0xe9b6c7aa, 0xd62f105d, 0x02441453, 0xd8a1e681, 0xe7d3fbc8,
    0x21e1cde6, 0xc33707d6, 0xf4d50d87, 0x455a14ed, 0xa9e3e905, 0xfcefa3f8, 0x676f02d9, 0x8d2a4c8a,
    0xfffa3942, 0x8771f681, 0x6d9d6122, 0xfde5380c, 0xa4beea44, 0x4bdecfa9, 0xf6bb4b60, 0xbebfbc70,
    0x289b7ec6, 0xeaa127fa, 0xd4ef3085, 0x04881d05, 0xd9d4d039, 0xe6db99e5, 0x1fa27cf8, 0xc4ac5665,
    0xf4292244, 0x432aff97, 0xab9423a7, 0xfc93a039, 0x655b59c3, 0x8f0ccc92, 0xffeff47d, 0x85845dd1,
    0x6fa87e4f, 0xfe2ce6e0, 0xa3014314, 0x4e0811a1, 0xf7537e82, 0xbd3af235, 0x2ad7d2bb, 0xeb86d391,
];

const HEX_LOWER: &[u8; 16] = b"0123456789abcdef";

unsafe extern "C" {
    fn memchr(s: *const u8, c: i32, n: usize) -> *const u8;
}

#[inline]
fn find_nul(haystack: &[u8]) -> Option<usize> {
    if haystack.is_empty() {
        return None;
    }
    let ptr = unsafe { memchr(haystack.as_ptr(), 0, haystack.len()) };
    if ptr.is_null() {
        None
    } else {
        Some(unsafe { ptr.offset_from_unsigned(haystack.as_ptr()) })
    }
}

const ASCII_NORM_LUT: [u8; 256] = {
    let mut lut = [0u8; 256];
    let mut i = 1usize;
    while i < 128 {
        let b = i as u8;
        lut[i] = if matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r') {
            b' '
        } else if b >= b'A' && b <= b'Z' {
            b + 32
        } else {
            b
        };
        i += 1;
    }
    lut
};

/// Computes the 32-byte lowercase hex MD5 digest of `data` directly over 64-byte slices without intermediate buffering.
#[inline]
pub fn md5_hex_slice(data: &[u8]) -> [u8; 32] {
    let mut state = [0x67452301u32, 0xefcdab89, 0x98badcfe, 0x10325476];

    #[inline(always)]
    fn compress_block(state: &mut [u32; 4], block: &[u8; 64]) {
        let mut m = [0u32; 16];
        for (i, &chunk) in block.as_chunks::<4>().0.iter().enumerate() {
            m[i] = u32::from_le_bytes(chunk);
        }
        let [mut a, mut b, mut c, mut d] = *state;
        for i in 0..16 {
            let f = d ^ (b & (c ^ d));
            let temp = d;
            d = c;
            c = b;
            let sum = a.wrapping_add(f).wrapping_add(MD5_K[i]).wrapping_add(m[i]);
            b = b.wrapping_add(sum.rotate_left(MD5_S[i]));
            a = temp;
        }
        for i in 16..32 {
            let f = c ^ (d & (b ^ c));
            let g = (5 * i + 1) & 15;
            let temp = d;
            d = c;
            c = b;
            let sum = a.wrapping_add(f).wrapping_add(MD5_K[i]).wrapping_add(m[g]);
            b = b.wrapping_add(sum.rotate_left(MD5_S[i]));
            a = temp;
        }
        for i in 32..48 {
            let f = b ^ c ^ d;
            let g = (3 * i + 5) & 15;
            let temp = d;
            d = c;
            c = b;
            let sum = a.wrapping_add(f).wrapping_add(MD5_K[i]).wrapping_add(m[g]);
            b = b.wrapping_add(sum.rotate_left(MD5_S[i]));
            a = temp;
        }
        for i in 48..64 {
            let f = c ^ (b | !d);
            let g = (7 * i) & 15;
            let temp = d;
            d = c;
            c = b;
            let sum = a.wrapping_add(f).wrapping_add(MD5_K[i]).wrapping_add(m[g]);
            b = b.wrapping_add(sum.rotate_left(MD5_S[i]));
            a = temp;
        }
        state[0] = state[0].wrapping_add(a);
        state[1] = state[1].wrapping_add(b);
        state[2] = state[2].wrapping_add(c);
        state[3] = state[3].wrapping_add(d);
    }

    let (blocks, remainder) = data.as_chunks::<64>();
    for block in blocks {
        compress_block(&mut state, block);
    }

    let bit_len = (data.len() as u64).wrapping_mul(8);
    let mut tail = [0u8; 64];
    tail[..remainder.len()].copy_from_slice(remainder);
    tail[remainder.len()] = 0x80;
    if remainder.len() >= 56 {
        compress_block(&mut state, &tail);
        tail.fill(0);
    }
    tail[56..64].copy_from_slice(&bit_len.to_le_bytes());
    compress_block(&mut state, &tail);

    let mut hex = [0u8; 32];
    for (i, word) in state.iter().enumerate() {
        for (j, &byte) in word.to_le_bytes().iter().enumerate() {
            let pos = (i * 4 + j) * 2;
            hex[pos] = HEX_LOWER[(byte >> 4) as usize];
            hex[pos + 1] = HEX_LOWER[(byte & 0x0f) as usize];
        }
    }
    hex
}

/// Appends trimmed, whitespace-collapsed, lowercased ASCII bytes to `dst[pos..]`.
/// Returns the new `pos`, or `None` if any byte is `0x00` or non-ASCII (`>= 0x80`) or exceeds `dst`.
#[inline]
fn append_normalized_ascii(dst: &mut [u8], mut pos: usize, bytes: &[u8]) -> Option<usize> {
    if pos.checked_add(bytes.len())? > dst.len() {
        return None;
    }

    let dst_ptr = dst.as_mut_ptr();
    let mut started = false;
    let mut in_space = false;
    for &b in bytes {
        let c = ASCII_NORM_LUT[b as usize];
        if c == 0 {
            return None;
        }
        if c == b' ' {
            if started {
                in_space = true;
            }
        } else {
            // SAFETY: Each `in_space` write corresponds to at least one skipped whitespace byte
            // in `bytes`, so total bytes written is at most `bytes.len()`, and `pos + bytes.len() <= dst.len()`.
            unsafe {
                if in_space {
                    *dst_ptr.add(pos) = b' ';
                    pos += 1;
                    in_space = false;
                }
                *dst_ptr.add(pos) = c;
                pos += 1;
            }
            started = true;
        }
    }
    Some(pos)
}

#[inline]
fn compute_subject_poster_md5_hex(
    subject: &[u8],
    poster: &[u8],
    stack_buf: &mut [u8; 1024],
) -> Option<[u8; 32]> {
    let pos = append_normalized_ascii(stack_buf, 8, subject)?;
    if pos + 8 > stack_buf.len() {
        return None;
    }
    stack_buf[pos..pos + 8].copy_from_slice(b"\0poster:");
    let end_pos = append_normalized_ascii(stack_buf, pos + 8, poster)?;
    Some(md5_hex_slice(&stack_buf[..end_pos]))
}

/// Computes the 32-byte lowercase hex MD5 identity hash for `(subject, poster, file_number)`.
/// Returns `None` if `file_number <= 0` and either `subject` or `poster` contains non-ASCII/NUL bytes.
#[inline]
pub fn compute_binary_identity_md5_hex(
    subject: &[u8],
    poster: &[u8],
    file_number: i64,
) -> Option<[u8; 32]> {
    if file_number > 0 {
        let mut buf = [0u8; 32];
        buf[..5].copy_from_slice(b"file:");
        let mut digits = [0u8; 24];
        let mut n = file_number as u64;
        let mut dpos = digits.len();
        while n > 0 {
            dpos -= 1;
            digits[dpos] = b'0' + (n % 10) as u8;
            n /= 10;
        }
        let dlen = digits.len() - dpos;
        buf[5..5 + dlen].copy_from_slice(&digits[dpos..]);
        return Some(md5_hex_slice(&buf[..5 + dlen]));
    }

    let mut stack_buf = [0u8; 1024];
    stack_buf[..8].copy_from_slice(b"subject:");
    compute_subject_poster_md5_hex(subject, poster, &mut stack_buf)
}

/// Computes a single binary identity hash (`BinaryHandler::identityHash`).
/// Writes 32 lowercase hex bytes into `out_hex32`.
/// Returns `32` on success, `0` when declined (non-ASCII input requiring PHP fallback), or `-1` on invalid arguments.
///
/// # Safety
///
/// `subject` and `poster` must be valid for reads of their respective lengths when non-null.
/// `out_hex32` must be valid for writes of at least 32 bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_binary_identity_hash(
    subject: *const u8,
    subject_len: usize,
    poster: *const u8,
    poster_len: usize,
    file_number: i64,
    out_hex32: *mut u8,
) -> isize {
    if out_hex32.is_null() {
        return -1;
    }
    let subj_bytes: &[u8] = if subject.is_null() || subject_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(subject, subject_len) }
    };
    let post_bytes: &[u8] = if poster.is_null() || poster_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(poster, poster_len) }
    };

    match compute_binary_identity_md5_hex(subj_bytes, post_bytes, file_number) {
        Some(hex) => {
            unsafe {
                std::ptr::copy_nonoverlapping(hex.as_ptr(), out_hex32, 32);
            }
            32
        }
        None => 0,
    }
}

/// Batch computes `BinaryHandler::identityHash` for `count` pairs of NUL-separated slices in `input[..input_len]`.
/// Each item `i` (`0..count`) consists of two consecutive NUL-separated slices `(first, second)`:
/// - If `second == b"\x01"`, `first` is the ASCII `file_number` digits (e.g. `"1"`, `"62"`), hashed as `md5("file:" . first)`.
/// - Otherwise, `first` is `subject` and `second` is `poster`, hashed via `compute_binary_identity_md5_hex(subject, poster, 0)`.
///
/// Output format: `\n`-separated lines (each 32 hex bytes, or empty if declined to PHP fallback).
/// Returns `-1` if the number of NUL-separated fields does not equal `2 * count`.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_binary_identity_hash_batch_nul(
    input: *const u8,
    input_len: usize,
    count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if count == 0 {
        return 0;
    }
    if input.is_null() && input_len > 0 {
        return -1;
    }
    let bytes: &[u8] = if input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };

    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    let mut rem = bytes;
    let mut last_pair: Option<(&[u8], &[u8])> = None;
    let mut last_hex = [0u8; 32];
    let mut file_buf = [0u8; 32];
    file_buf[..5].copy_from_slice(b"file:");
    let mut stack_buf = [0u8; 1024];
    stack_buf[..8].copy_from_slice(b"subject:");

    for idx in 0..count {
        let Some(first_nul) = find_nul(rem) else {
            return -1;
        };
        let first = &rem[..first_nul];
        rem = &rem[first_nul + 1..];

        let second = if idx + 1 < count {
            let Some(second_nul) = find_nul(rem) else {
                return -1;
            };
            let s = &rem[..second_nul];
            rem = &rem[second_nul + 1..];
            s
        } else {
            if find_nul(rem).is_some() {
                return -1;
            }
            let s = rem;
            rem = &[];
            s
        };

        if idx > 0 {
            out.push(b'\n');
        }
        if Some((first, second)) == last_pair {
            out.extend(&last_hex);
            continue;
        }
        if second == b"\x01" {
            if !first.is_empty() && first.len() <= 24 {
                file_buf[5..5 + first.len()].copy_from_slice(first);
                let hex = md5_hex_slice(&file_buf[..5 + first.len()]);
                last_pair = Some((first, second));
                last_hex = hex;
                out.extend(&hex);
            }
        } else if let Some(hex) = compute_subject_poster_md5_hex(first, second, &mut stack_buf) {
            last_pair = Some((first, second));
            last_hex = hex;
            out.extend(&hex);
        }
    }

    out.finish()
}

#[inline]
fn is_known_nzb_ext(lower: &[u8]) -> bool {
    matches!(
        lower,
        b"7z"
            | b"ace"
            | b"ai7"
            | b"srr"
            | b"srt"
            | b"sub"
            | b"aiff"
            | b"asc"
            | b"avi"
            | b"audio"
            | b"bin"
            | b"bz2"
            | b"c"
            | b"cfc"
            | b"cfm"
            | b"chm"
            | b"class"
            | b"conf"
            | b"cpp"
            | b"cs"
            | b"css"
            | b"csv"
            | b"cue"
            | b"deb"
            | b"divx"
            | b"doc"
            | b"dot"
            | b"eml"
            | b"enc"
            | b"exe"
            | b"file"
            | b"gif"
            | b"gz"
            | b"hlp"
            | b"htm"
            | b"html"
            | b"image"
            | b"iso"
            | b"jar"
            | b"java"
            | b"jpeg"
            | b"jpg"
            | b"js"
            | b"lua"
            | b"m"
            | b"m3u"
            | b"mkv"
            | b"mm"
            | b"mov"
            | b"mp3"
            | b"mp4"
            | b"mpg"
            | b"nfo"
            | b"nzb"
            | b"odc"
            | b"odf"
            | b"odg"
            | b"odi"
            | b"odp"
            | b"ods"
            | b"odt"
            | b"ogg"
            | b"par2"
            | b"parity"
            | b"pdf"
            | b"pgp"
            | b"php"
            | b"pl"
            | b"png"
            | b"ppt"
            | b"ps"
            | b"py"
            | b"ram"
            | b"rar"
            | b"rb"
            | b"rm"
            | b"rpm"
            | b"rtf"
            | b"sfv"
            | b"sig"
            | b"sql"
            | b"srs"
            | b"swf"
            | b"sxc"
            | b"sxd"
            | b"sxi"
            | b"sxw"
            | b"tar"
            | b"tex"
            | b"tgz"
            | b"txt"
            | b"vcf"
            | b"video"
            | b"vsd"
            | b"wav"
            | b"wma"
            | b"wmv"
            | b"xls"
            | b"xml"
            | b"xpi"
            | b"xvid"
            | b"zip7"
            | b"zip"
    )
}

/// Matches `NzbParserService::detectFileExtension` regex:
/// `/\.(\d{2,3}|7z|...|r\d{2,3}|...|zip)[" ](?!([\)|\-]))/i`
/// normalizing `.r\d{2,3}` to `"rar"`.
#[inline]
pub fn detect_nzb_file_extension(subject: &[u8], out_ext: &mut [u8; 8]) -> usize {
    let n = subject.len();
    let mut i = 0;
    while i < n {
        if subject[i] == b'.' {
            let start = i + 1;
            let mut end = start;
            while end < n && end - start <= 6 && subject[end].is_ascii_alphanumeric() {
                end += 1;
            }
            let len = end - start;
            if (1..=6).contains(&len) && end < n && matches!(subject[end], b'"' | b' ') {
                let next_after_delim = subject.get(end + 1).copied();
                if !matches!(next_after_delim, Some(b')' | b'|' | b'-')) {
                    let mut lower = [0u8; 6];
                    for (dst, &src) in lower[..len].iter_mut().zip(&subject[start..end]) {
                        *dst = src.to_ascii_lowercase();
                    }
                    let cand = &lower[..len];
                    if (len == 2 || len == 3) && cand.iter().all(u8::is_ascii_digit) {
                        out_ext[..len].copy_from_slice(cand);
                        return len;
                    }
                    if (len == 3 || len == 4)
                        && cand[0] == b'r'
                        && cand[1..].iter().all(u8::is_ascii_digit)
                    {
                        out_ext[..3].copy_from_slice(b"rar");
                        return 3;
                    }
                    if is_known_nzb_ext(cand) {
                        out_ext[..len].copy_from_slice(cand);
                        return len;
                    }
                }
            }
        }
        i += 1;
    }
    0
}

/// Matches `/(\d+)\)$/` on `subject` (where subject contains no `\n`).
#[inline]
fn extract_trailing_paren_digits(subject: &[u8]) -> Option<&[u8]> {
    let trimmed = subject.strip_suffix(b"\n").unwrap_or(subject);
    let before_paren = trimmed.strip_suffix(b")")?;
    let mut start = before_paren.len();
    while start > 0 && before_paren[start - 1].is_ascii_digit() {
        start -= 1;
    }
    if start < before_paren.len() {
        Some(&before_paren[start..])
    } else {
        None
    }
}

/// Detects the file extension from an NZB subject string (`NzbParserService::detectFileExtension`).
/// Writes the lowercase extension (up to 6 bytes) into `out_buf`.
///
/// # Safety
///
/// `subject` must be valid for reads of `subject_len` bytes when non-null.
/// `out_buf` must be valid for writes of at least 8 bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_detect_file_extension(
    subject: *const u8,
    subject_len: usize,
    out_buf: *mut u8,
) -> isize {
    if out_buf.is_null() || (subject.is_null() && subject_len > 0) {
        return -1;
    }
    let subj = if subject_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(subject, subject_len) }
    };
    let mut ext = [0u8; 8];
    let len = detect_nzb_file_extension(subj, &mut ext);
    if len > 0 {
        unsafe {
            std::ptr::copy_nonoverlapping(ext.as_ptr(), out_buf, len);
        }
    }
    len as isize
}

#[inline]
fn is_xml_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\r' | b'\n')
}

#[inline]
fn skip_xml_ws(bytes: &[u8], mut pos: usize) -> usize {
    while pos < bytes.len() && is_xml_space(bytes[pos]) {
        pos += 1;
    }
    pos
}

/// Skips whitespace and `<!-- ... -->` comments.
/// Rejects unclosed comments or `--` inside a comment per XML 1.0 (`Comment ::= '<!--' ((Char - '-') | ('-' (Char - '-')))* '-->'`).
fn skip_xml_ws_and_comments(bytes: &[u8], mut pos: usize) -> Option<usize> {
    loop {
        pos = skip_xml_ws(bytes, pos);
        if bytes.get(pos..pos + 4) == Some(b"<!--") {
            pos += 4;
            let mut found = false;
            while pos + 2 <= bytes.len() {
                if &bytes[pos..pos + 2] == b"--" {
                    if bytes.get(pos + 2) == Some(&b'>') {
                        pos += 3;
                        found = true;
                        break;
                    }
                    return None;
                }
                pos += 1;
            }
            if !found {
                return None;
            }
        } else {
            return Some(pos);
        }
    }
}

/// Unescapes standard XML entities into `dst` (when `Some`) or validates them in place (when `None`).
/// Rejects (`false`) raw `<`, control chars (`< 0x20`), non-ASCII (`>= 0x7F`), or unknown/control entities
/// so callers fall back to SimpleXML.
fn unescape_or_validate_xml_clean(raw: &[u8], mut dst: Option<&mut Vec<u8>>) -> bool {
    let mut i = 0;
    let n = raw.len();
    while i < n {
        let b = raw[i];
        if b == b'<' || !(0x20..0x7F).contains(&b) {
            return false;
        }
        if b != b'&' {
            if let Some(out) = dst.as_deref_mut() {
                out.push(b);
            }
            i += 1;
            continue;
        }
        let rest = &raw[i + 1..];
        let Some(semi) = rest.iter().take(12).position(|&c| c == b';') else {
            return false;
        };
        let ent = &rest[..semi];
        match ent {
            b"amp" => {
                if let Some(out) = dst.as_deref_mut() {
                    out.push(b'&');
                }
            }
            b"lt" => {
                if let Some(out) = dst.as_deref_mut() {
                    out.push(b'<');
                }
            }
            b"gt" => {
                if let Some(out) = dst.as_deref_mut() {
                    out.push(b'>');
                }
            }
            b"quot" => {
                if let Some(out) = dst.as_deref_mut() {
                    out.push(b'"');
                }
            }
            b"apos" => {
                if let Some(out) = dst.as_deref_mut() {
                    out.push(b'\'');
                }
            }
            _ if ent.starts_with(b"#x") || ent.starts_with(b"#X") => {
                let hex_digits = &ent[2..];
                if hex_digits.is_empty()
                    || hex_digits.len() > 6
                    || !hex_digits.iter().all(u8::is_ascii_hexdigit)
                {
                    return false;
                }
                let Ok(s) = std::str::from_utf8(hex_digits) else {
                    return false;
                };
                let Ok(code) = u32::from_str_radix(s, 16) else {
                    return false;
                };
                let Some(ch) = char::from_u32(code) else {
                    return false;
                };
                if code < 0x20 || code == 0x7F {
                    return false;
                }
                if let Some(out) = dst.as_deref_mut() {
                    let mut u8_buf = [0u8; 4];
                    out.extend_from_slice(ch.encode_utf8(&mut u8_buf).as_bytes());
                }
            }
            _ if ent.starts_with(b"#") => {
                let dec_digits = &ent[1..];
                if dec_digits.is_empty()
                    || dec_digits.len() > 7
                    || !dec_digits.iter().all(u8::is_ascii_digit)
                {
                    return false;
                }
                let Ok(s) = std::str::from_utf8(dec_digits) else {
                    return false;
                };
                let Ok(code) = s.parse::<u32>() else {
                    return false;
                };
                let Some(ch) = char::from_u32(code) else {
                    return false;
                };
                if code < 0x20 || code == 0x7F {
                    return false;
                }
                if let Some(out) = dst.as_deref_mut() {
                    let mut u8_buf = [0u8; 4];
                    out.extend_from_slice(ch.encode_utf8(&mut u8_buf).as_bytes());
                }
            }
            _ => return false,
        }
        i += 1 + semi + 1;
    }
    true
}

#[inline]
fn unescape_xml_clean(raw: &[u8], dst: &mut Vec<u8>) -> bool {
    unescape_or_validate_xml_clean(raw, Some(dst))
}

#[inline]
fn validate_xml_clean(raw: &[u8]) -> bool {
    unescape_or_validate_xml_clean(raw, None)
}

#[inline]
fn matches_tag_exact(bytes: &[u8], pos: usize, tag_exact: &[u8]) -> Option<usize> {
    let end = pos + tag_exact.len();
    if bytes.get(pos..end) != Some(tag_exact) {
        return None;
    }
    let next = bytes.get(end).copied()?;
    if is_xml_space(next) || next == b'>' || next == b'/' {
        Some(end)
    } else {
        None
    }
}

#[inline]
fn expect_closing_tag_exact(bytes: &[u8], pos: usize, close_exact: &[u8]) -> Option<usize> {
    let end = pos + close_exact.len();
    if bytes.get(pos..end) != Some(close_exact) {
        return None;
    }
    let after_ws = skip_xml_ws(bytes, end);
    if bytes.get(after_ws) == Some(&b'>') {
        Some(after_ws + 1)
    } else {
        None
    }
}

/// Parses attributes of an opening tag starting at `pos` (after the tag name).
/// Validates attribute syntax, rejects duplicate attributes and namespaces (`:` or `xmlns` when `!allow_xmlns`),
/// validates every attribute value for clean XML entities/characters, and calls `on_attr(attr_name_raw, attr_val_raw)`.
/// Returns `Some((new_pos_after_gt, is_self_closing))` or `None` on malformed/unsupported syntax.
fn parse_xml_attributes<F>(
    bytes: &[u8],
    mut pos: usize,
    allow_xmlns: bool,
    mut on_attr: F,
) -> Option<(usize, bool)>
where
    F: FnMut(&[u8], &[u8]) -> bool,
{
    let mut seen_names: [&[u8]; 8] = [&[]; 8];
    let mut seen_count = 0usize;

    loop {
        let prev_pos = pos;
        pos = skip_xml_ws(bytes, pos);
        let b = *bytes.get(pos)?;
        if b == b'>' {
            return Some((pos + 1, false));
        }
        if b == b'/' {
            if bytes.get(pos + 1) == Some(&b'>') {
                return Some((pos + 2, true));
            }
            return None;
        }
        // Attributes must be separated by whitespace
        if seen_count > 0 && pos == prev_pos {
            return None;
        }
        if !b.is_ascii_alphabetic() && b != b'_' {
            return None;
        }
        let name_start = pos;
        pos += 1;
        while pos < bytes.len() {
            let c = bytes[pos];
            if c == b':' {
                return None;
            }
            if c.is_ascii_alphanumeric() || matches!(c, b'_' | b'-' | b'.') {
                pos += 1;
            } else {
                break;
            }
        }
        let attr_name = &bytes[name_start..pos];
        if !allow_xmlns && attr_name == b"xmlns" {
            return None;
        }
        if seen_count >= seen_names.len() || seen_names[..seen_count].contains(&attr_name) {
            return None;
        }
        seen_names[seen_count] = attr_name;
        seen_count += 1;

        pos = skip_xml_ws(bytes, pos);
        if bytes.get(pos) != Some(&b'=') {
            return None;
        }
        pos = skip_xml_ws(bytes, pos + 1);
        let quote = *bytes.get(pos)?;
        if quote != b'"' && quote != b'\'' {
            return None;
        }
        pos += 1;
        let val_start = pos;
        while pos < bytes.len() && bytes[pos] != quote {
            pos += 1;
        }
        if pos >= bytes.len() {
            return None;
        }
        let attr_val = &bytes[val_start..pos];
        pos += 1;
        if !validate_xml_clean(attr_val) || !on_attr(attr_name, attr_val) {
            return None;
        }
    }
}

/// Parses an optional leading `<?xml ...?>` declaration at `pos`.
/// Rejects non-UTF-8 encodings or malformed pseudo-attributes.
fn parse_xml_decl(bytes: &[u8], mut pos: usize) -> Option<usize> {
    if bytes.get(pos..pos + 5) != Some(b"<?xml") {
        return Some(pos);
    }
    let after_kw = pos + 5;
    if !bytes.get(after_kw).copied().is_some_and(is_xml_space) {
        return None;
    }
    pos = after_kw;
    let mut seen_version = false;
    let mut seen_encoding = false;
    let mut seen_standalone = false;

    loop {
        let prev = pos;
        pos = skip_xml_ws(bytes, pos);
        if bytes.get(pos..pos + 2) == Some(b"?>") {
            return if seen_version { Some(pos + 2) } else { None };
        }
        if pos == prev {
            return None;
        }
        let name_start = pos;
        while pos < bytes.len() && bytes[pos].is_ascii_alphabetic() {
            pos += 1;
        }
        let name = &bytes[name_start..pos];
        pos = skip_xml_ws(bytes, pos);
        if bytes.get(pos) != Some(&b'=') {
            return None;
        }
        pos = skip_xml_ws(bytes, pos + 1);
        let quote = *bytes.get(pos)?;
        if quote != b'"' && quote != b'\'' {
            return None;
        }
        pos += 1;
        let val_start = pos;
        while pos < bytes.len() && bytes[pos] != quote {
            pos += 1;
        }
        if pos >= bytes.len() {
            return None;
        }
        let val = &bytes[val_start..pos];
        pos += 1;

        match name {
            b"version" => {
                if seen_version || seen_encoding || seen_standalone || val != b"1.0" {
                    return None;
                }
                seen_version = true;
            }
            b"encoding" => {
                if !seen_version || seen_encoding || seen_standalone || !val.eq_ignore_ascii_case(b"utf-8") {
                    return None;
                }
                seen_encoding = true;
            }
            b"standalone" => {
                if !seen_version || seen_standalone || (val != b"yes" && val != b"no") {
                    return None;
                }
                seen_standalone = true;
            }
            _ => return None,
        }
    }
}

#[inline]
fn parse_positive_or_zero_ascii_i64(raw: &[u8]) -> Option<i64> {
    if raw.is_empty() || raw.len() > 18 {
        return None;
    }
    let mut val = 0i64;
    for &b in raw {
        if !b.is_ascii_digit() {
            return None;
        }
        val = val.checked_mul(10)?.checked_add(i64::from(b - b'0'))?;
    }
    Some(val)
}

#[inline]
fn write_u64_ascii(out: &mut OutCursor<'_>, mut n: u64) {
    if n == 0 {
        out.push(b'0');
        return;
    }
    let mut buf = [0u8; 20];
    let mut pos = buf.len();
    while n > 0 {
        pos -= 1;
        buf[pos] = b'0' + (n % 10) as u8;
        n /= 10;
    }
    out.extend(&buf[pos..]);
}

/// Fast single-pass NZB XML `<file>` parser for `NzbParserService::parseNzbFileList`.
///
/// Emits one `\n`-separated line per `<file>` element:
/// `title \0 ext \0 firstsegment("1"|"0") \0 size \0 partsactual \0 partstotal \0 groups_tab \0 segments_tab`
///
/// Returns:
/// - `>= 0`: bytes written to `out_buf` (`0` when `<nzb>` has 0 `<file>` children)
/// - `< -1`: `-(required_bytes as isize)` when `out_cap` was too small
/// - `-1`: declined (malformed XML, namespaces, entities/CDATA, or control bytes requiring PHP SimpleXML fallback)
///
/// # Safety
///
/// `xml` must be valid for reads of `xml_len` bytes when non-null and `xml_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_parse_files(
    xml: *const u8,
    xml_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if xml_len == 0 {
        return 0;
    }
    if xml.is_null() {
        return -1;
    }
    let bytes = unsafe { slice::from_raw_parts(xml, xml_len) };

    let mut pos = 0;
    if bytes.starts_with(b"\xEF\xBB\xBF") {
        pos = 3;
    }

    // Reject control bytes (except \t, \n, \r) and non-ASCII bytes so SimpleXML handles them.
    for &b in &bytes[pos..] {
        if (b < 0x20 && !matches!(b, b'\t' | b'\n' | b'\r')) || b >= 0x7F {
            return -1;
        }
    }

    let Some(after_decl) = parse_xml_decl(bytes, pos) else {
        return -1;
    };
    pos = after_decl;

    // Skip prologue: whitespace, comments, <!DOCTYPE nzb ...>
    let mut seen_doctype = false;
    loop {
        let Some(next_pos) = skip_xml_ws_and_comments(bytes, pos) else {
            return -1;
        };
        pos = next_pos;
        if matches_tag_exact(bytes, pos, b"<!DOCTYPE").is_some() {
            if seen_doctype {
                return -1;
            }
            seen_doctype = true;
            pos = skip_xml_ws(bytes, pos + 9);
            let Some(after_name) = matches_tag_exact(bytes, pos, b"nzb") else {
                return -1;
            };
            pos = skip_xml_ws(bytes, after_name);
            if let Some(after_pub) = matches_tag_exact(bytes, pos, b"PUBLIC") {
                pos = skip_xml_ws(bytes, after_pub);
                for qi in 0..2 {
                    if qi == 1 {
                        let prev = pos;
                        pos = skip_xml_ws(bytes, pos);
                        if pos == prev {
                            return -1;
                        }
                    }
                    let Some(&q) = bytes.get(pos) else {
                        return -1;
                    };
                    if q != b'"' && q != b'\'' {
                        return -1;
                    }
                    pos += 1;
                    while pos < bytes.len() && bytes[pos] != q {
                        if matches!(bytes[pos], b'<' | b'>' | b'[') {
                            return -1;
                        }
                        pos += 1;
                    }
                    if bytes.get(pos) != Some(&q) {
                        return -1;
                    }
                    pos += 1;
                }
                pos = skip_xml_ws(bytes, pos);
            } else if let Some(after_sys) = matches_tag_exact(bytes, pos, b"SYSTEM") {
                pos = skip_xml_ws(bytes, after_sys);
                let Some(&q) = bytes.get(pos) else {
                    return -1;
                };
                if q != b'"' && q != b'\'' {
                    return -1;
                }
                pos += 1;
                while pos < bytes.len() && bytes[pos] != q {
                    if matches!(bytes[pos], b'<' | b'>' | b'[') {
                        return -1;
                    }
                    pos += 1;
                }
                if bytes.get(pos) != Some(&q) {
                    return -1;
                }
                pos = skip_xml_ws(bytes, pos + 1);
            }
            if bytes.get(pos) != Some(&b'>') {
                return -1;
            }
            pos += 1;
        } else {
            break;
        }
    }

    let Some(nzb_attr_start) = matches_tag_exact(bytes, pos, b"<nzb") else {
        return -1;
    };
    let Some((after_nzb_open, nzb_self_closing)) =
        parse_xml_attributes(bytes, nzb_attr_start, true, |_k, _v| true)
    else {
        return -1;
    };
    pos = after_nzb_open;
    if nzb_self_closing {
        let Some(end_pos) = skip_xml_ws_and_comments(bytes, pos) else {
            return -1;
        };
        return if end_pos == bytes.len() { 0 } else { -1 };
    }

    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    let mut file_idx = 0usize;
    let mut title_buf = Vec::with_capacity(256);
    let mut group_arena = Vec::with_capacity(256);
    let mut groups: Vec<(usize, usize)> = Vec::with_capacity(8);
    let mut seg_arena = Vec::with_capacity(4096);
    let mut segments: Vec<(Option<i64>, usize, usize)> = Vec::with_capacity(128);

    loop {
        let Some(next_pos) = skip_xml_ws_and_comments(bytes, pos) else {
            return -1;
        };
        pos = next_pos;

        if let Some(after_nzb_close) = expect_closing_tag_exact(bytes, pos, b"</nzb") {
            let Some(end_pos) = skip_xml_ws_and_comments(bytes, after_nzb_close) else {
                return -1;
            };
            if end_pos != bytes.len() {
                return -1;
            }
            break;
        }

        if let Some(head_attr_start) = matches_tag_exact(bytes, pos, b"<head") {
            let Some((after_head_open, head_self_closing)) =
                parse_xml_attributes(bytes, head_attr_start, false, |_k, _v| true)
            else {
                return -1;
            };
            pos = after_head_open;
            if !head_self_closing {
                loop {
                    let Some(p) = skip_xml_ws_and_comments(bytes, pos) else {
                        return -1;
                    };
                    pos = p;
                    if let Some(after_head_close) = expect_closing_tag_exact(bytes, pos, b"</head")
                    {
                        pos = after_head_close;
                        break;
                    }
                    if let Some(meta_attr_start) = matches_tag_exact(bytes, pos, b"<meta") {
                        let Some((after_meta_open, meta_self_closing)) =
                            parse_xml_attributes(bytes, meta_attr_start, false, |_k, _v| true)
                        else {
                            return -1;
                        };
                        pos = after_meta_open;
                        if !meta_self_closing {
                            let text_start = pos;
                            while pos < bytes.len() && bytes[pos] != b'<' {
                                pos += 1;
                            }
                            if !validate_xml_clean(&bytes[text_start..pos]) {
                                return -1;
                            }
                            let Some(after_meta_close) =
                                expect_closing_tag_exact(bytes, pos, b"</meta")
                            else {
                                return -1;
                            };
                            pos = after_meta_close;
                        }
                    } else {
                        return -1;
                    }
                }
            }
            continue;
        }

        let Some(file_attr_start) = matches_tag_exact(bytes, pos, b"<file") else {
            return -1;
        };
        title_buf.clear();
        let mut seen_subject = false;
        let Some((after_file_open, file_self_closing)) =
            parse_xml_attributes(bytes, file_attr_start, false, |k, v| {
                if k == b"subject" {
                    if seen_subject {
                        return false;
                    }
                    seen_subject = true;
                    return unescape_xml_clean(v, &mut title_buf);
                }
                true
            })
        else {
            return -1;
        };
        pos = after_file_open;

        group_arena.clear();
        groups.clear();
        seg_arena.clear();
        segments.clear();
        let mut file_size = 0u64;
        let mut has_missing_seg_number = false;
        let mut seen_groups_block = false;
        let mut seen_segments_block = false;

        if !file_self_closing {
            loop {
                let Some(p) = skip_xml_ws_and_comments(bytes, pos) else {
                    return -1;
                };
                pos = p;
                if let Some(after_file_close) = expect_closing_tag_exact(bytes, pos, b"</file") {
                    pos = after_file_close;
                    break;
                }
                if let Some(groups_attr_start) = matches_tag_exact(bytes, pos, b"<groups") {
                    if seen_groups_block {
                        return -1;
                    }
                    seen_groups_block = true;
                    let Some((after_groups_open, groups_self_closing)) =
                        parse_xml_attributes(bytes, groups_attr_start, false, |_k, _v| true)
                    else {
                        return -1;
                    };
                    pos = after_groups_open;
                    if !groups_self_closing {
                        loop {
                            let Some(gp) = skip_xml_ws_and_comments(bytes, pos) else {
                                return -1;
                            };
                            pos = gp;
                            if let Some(after_groups_close) =
                                expect_closing_tag_exact(bytes, pos, b"</groups")
                            {
                                pos = after_groups_close;
                                break;
                            }
                            let Some(group_attr_start) = matches_tag_exact(bytes, pos, b"<group")
                            else {
                                return -1;
                            };
                            let Some((after_group_open, group_self_closing)) =
                                parse_xml_attributes(bytes, group_attr_start, false, |_k, _v| true)
                            else {
                                return -1;
                            };
                            if group_self_closing {
                                return -1;
                            }
                            pos = after_group_open;
                            let g_start = group_arena.len();
                            let text_start = pos;
                            while pos < bytes.len() && bytes[pos] != b'<' {
                                pos += 1;
                            }
                            if !unescape_xml_clean(&bytes[text_start..pos], &mut group_arena) {
                                return -1;
                            }
                            let Some(after_group_close) =
                                expect_closing_tag_exact(bytes, pos, b"</group")
                            else {
                                return -1;
                            };
                            pos = after_group_close;
                            let g_end = group_arena.len();
                            if g_start == g_end {
                                return -1;
                            }
                            groups.push((g_start, g_end));
                        }
                    }
                } else if let Some(segs_attr_start) = matches_tag_exact(bytes, pos, b"<segments") {
                    if seen_segments_block {
                        return -1;
                    }
                    seen_segments_block = true;
                    let Some((after_segs_open, segs_self_closing)) =
                        parse_xml_attributes(bytes, segs_attr_start, false, |_k, _v| true)
                    else {
                        return -1;
                    };
                    pos = after_segs_open;
                    if !segs_self_closing {
                        loop {
                            let Some(sp) = skip_xml_ws_and_comments(bytes, pos) else {
                                return -1;
                            };
                            pos = sp;
                            if let Some(after_segs_close) =
                                expect_closing_tag_exact(bytes, pos, b"</segments")
                            {
                                pos = after_segs_close;
                                break;
                            }
                            let Some(seg_attr_start) = matches_tag_exact(bytes, pos, b"<segment")
                            else {
                                return -1;
                            };
                            let mut seg_bytes = 0u64;
                            let mut seg_number: Option<i64> = None;
                            let Some((after_seg_open, seg_self_closing)) =
                                parse_xml_attributes(bytes, seg_attr_start, false, |k, v| {
                                    if k == b"bytes" {
                                        let Some(bval) = parse_positive_or_zero_ascii_i64(v) else {
                                            return false;
                                        };
                                        seg_bytes = bval as u64;
                                    } else if k == b"number" {
                                        let Some(nval) = parse_positive_or_zero_ascii_i64(v) else {
                                            return false;
                                        };
                                        seg_number = Some(nval);
                                    }
                                    true
                                })
                            else {
                                return -1;
                            };
                            if seg_self_closing {
                                return -1;
                            }
                            pos = after_seg_open;
                            let s_start = seg_arena.len();
                            let text_start = pos;
                            while pos < bytes.len() && bytes[pos] != b'<' {
                                pos += 1;
                            }
                            if !unescape_xml_clean(&bytes[text_start..pos], &mut seg_arena) {
                                return -1;
                            }
                            let Some(after_seg_close) =
                                expect_closing_tag_exact(bytes, pos, b"</segment")
                            else {
                                return -1;
                            };
                            pos = after_seg_close;
                            let s_end = seg_arena.len();
                            if s_start == s_end {
                                return -1;
                            }
                            if seg_number.is_none() {
                                has_missing_seg_number = true;
                            }
                            file_size = match file_size.checked_add(seg_bytes) {
                                Some(v) => v,
                                None => return -1,
                            };
                            segments.push((seg_number, s_start, s_end));
                        }
                    }
                } else {
                    return -1;
                }
            }
        }

        if !has_missing_seg_number && segments.len() > 1 {
            segments.sort_by_key(|s| s.0.unwrap_or(0));
        }

        let first_segment = segments.is_empty() || segments[0].0.unwrap_or(1) == 1;
        let mut ext_buf = [0u8; 8];
        let ext_len = detect_nzb_file_extension(&title_buf, &mut ext_buf);
        let parts_total = extract_trailing_paren_digits(&title_buf);

        if file_idx > 0 {
            out.push(b'\n');
        }
        file_idx += 1;

        out.extend(&title_buf);
        out.push(0);
        out.extend(&ext_buf[..ext_len]);
        out.push(0);
        out.push(if first_segment { b'1' } else { b'0' });
        out.push(0);
        write_u64_ascii(&mut out, file_size);
        out.push(0);
        write_u64_ascii(&mut out, segments.len() as u64);
        out.push(0);
        if let Some(pt) = parts_total {
            out.extend(pt);
        }
        out.push(0);
        for (gi, &(gs, ge)) in groups.iter().enumerate() {
            if gi > 0 {
                out.push(b'\t');
            }
            out.extend(&group_arena[gs..ge]);
        }
        out.push(0);
        for (si, &(_num, ss, se)) in segments.iter().enumerate() {
            if si > 0 {
                out.push(b'\t');
            }
            out.extend(&seg_arena[ss..se]);
        }
    }

    out.finish()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_md5_and_binary_identity_hash() {
        assert_eq!(&md5_hex_slice(b""), b"d41d8cd98f00b204e9800998ecf8427e");
        assert_eq!(
            &md5_hex_slice(b"message digest"),
            b"f96b697d7cb7938d525a2f31aaf161d0"
        );

        let hex_file = compute_binary_identity_md5_hex(b"ignored", b"ignored", 3).unwrap();
        assert_eq!(hex_file, md5_hex_slice(b"file:3"));

        let hex_subj = compute_binary_identity_md5_hex(
            b"  My.Show.S01E01   yEnc\t(1/10) ",
            b" Poster@Usenet.ORG (Poster) ",
            0,
        )
        .unwrap();
        assert_eq!(
            hex_subj,
            md5_hex_slice(b"subject:my.show.s01e01 yenc (1/10)\0poster:poster@usenet.org (poster)")
        );

        // Non-ASCII declines so PHP handles Utf8::clean + mb_strtolower
        assert!(compute_binary_identity_md5_hex("Café".as_bytes(), b"poster", 0).is_none());
    }

    #[test]
    fn test_xref_token_matching_and_group_extraction() {
        assert_eq!(
            match_xref_token(b"alt.binaries.teevee:12345"),
            Some(&b"alt.binaries.teevee:12345"[..])
        );
        assert_eq!(
            match_xref_token(b"alt.binaries.teevee"),
            Some(&b"alt.binaries.teevee"[..])
        );
        assert_eq!(
            match_xref_token(b"de.binaer.misc:99"),
            Some(&b"de.binaer.misc:99"[..])
        );
        assert_eq!(
            match_xref_token(b"es.binarios.cine:42"),
            Some(&b"es.binarios.cine:42"[..])
        );
        assert_eq!(match_xref_token(b"news.example.com"), None);
        assert_eq!(match_xref_token(b"alt.binaries."), None);
        assert_eq!(
            match_xref_token(b"alt.binaries.:123"),
            Some(&b"alt.binaries.:123"[..])
        );
        assert_eq!(
            match_xref_token(b"alt.binaries.test:123foo"),
            Some(&b"alt.binaries.test:123"[..])
        );
        assert_eq!(
            strip_xref_number(b"alt.binaries.teevee:12345"),
            &b"alt.binaries.teevee"[..]
        );
        assert_eq!(
            strip_xref_number(b"alt.binaries.teevee"),
            &b"alt.binaries.teevee"[..]
        );
    }

    #[test]
    fn test_nzb_detect_file_extension() {
        let mut ext = [0u8; 8];
        let n = detect_nzb_file_extension(b"\"Show.S01E01.part01.rar\" yEnc (1/10)", &mut ext);
        assert_eq!(&ext[..n], b"rar");

        let n = detect_nzb_file_extension(b"\"Show.S01E01.r05\" yEnc (1/10)", &mut ext);
        assert_eq!(&ext[..n], b"rar");

        let n = detect_nzb_file_extension(b"\"Show.S01E01.001\" yEnc (1/10)", &mut ext);
        assert_eq!(&ext[..n], b"001");

        let n = detect_nzb_file_extension(b"\"Show.S01E01.MKV\" yEnc (1/10)", &mut ext);
        assert_eq!(&ext[..n], b"mkv");

        // Negative lookahead `(?!([\)|\-]))` rejects `.rar"-` or `.rar )`
        let n = detect_nzb_file_extension(b"\"Show.S01E01.rar\"-ignored", &mut ext);
        assert_eq!(n, 0);
    }

    #[test]
    fn test_nzb_parse_files_basic_and_sorting() {
        let xml = br#"<?xml version="1.0" encoding="UTF-8"?>
<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
  <head><meta type="title">Test</meta></head>
  <file poster="p" date="1" subject="&quot;release.part01.rar&quot; yEnc (1/3)">
    <groups><group>alt.binaries.misc</group><group>alt.binaries.tv</group></groups>
    <segments>
      <segment bytes="200" number="3">p1-3@x</segment>
      <segment bytes="100" number="2">p1-2@x</segment>
    </segments>
  </file>
</nzb>"#;
        let mut buf = vec![0u8; 4096];
        let written = unsafe {
            nntmux_nzb_parse_files(xml.as_ptr(), xml.len(), buf.as_mut_ptr(), buf.len())
        };
        assert!(written > 0);
        let line = &buf[..written as usize];
        let fields: Vec<&[u8]> = line.split(|&b| b == 0).collect();
        assert_eq!(fields.len(), 8);
        assert_eq!(fields[0], b"\"release.part01.rar\" yEnc (1/3)");
        assert_eq!(fields[1], b"rar");
        assert_eq!(fields[2], b"0"); // missing segment 1
        assert_eq!(fields[3], b"300");
        assert_eq!(fields[4], b"2");
        assert_eq!(fields[5], b"3");
        assert_eq!(fields[6], b"alt.binaries.misc\talt.binaries.tv");
        assert_eq!(fields[7], b"p1-2@x\tp1-3@x");
    }
}
