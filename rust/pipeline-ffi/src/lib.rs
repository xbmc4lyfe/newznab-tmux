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
/// - `mode = 3`: `space_joined_tokens` + `\t` + `space_joined_groups` per record, records separated by `\n`.
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
                out.push(b'\t');
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
                out.push(b'\t');
                seen_groups.write_space_separated(&mut out);
            }
            _ => unreachable!(),
        }
    }

    out.finish()
}

#[cfg(test)]
mod tests {
    use super::*;

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
