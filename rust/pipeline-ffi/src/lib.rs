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
}
