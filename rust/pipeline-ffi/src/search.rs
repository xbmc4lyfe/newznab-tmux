use std::slice;

#[inline]
fn is_ascii_ws(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C)
}

#[inline]
fn trim_php_ws(mut s: &[u8]) -> &[u8] {
    while let Some(&b) = s.first() {
        if is_ascii_ws(b) || b == 0 {
            s = &s[1..];
        } else {
            break;
        }
    }
    while let Some(&b) = s.last() {
        if is_ascii_ws(b) || b == 0 {
            s = &s[..s.len() - 1];
        } else {
            break;
        }
    }
    s
}

struct OutCursor {
    ptr: *mut u8,
    cap: usize,
    len: usize,
    overflow: bool,
}

impl OutCursor {
    #[inline]
    fn new(ptr: *mut u8, cap: usize) -> Self {
        Self {
            ptr,
            cap,
            len: 0,
            overflow: false,
        }
    }

    #[inline]
    fn push_byte(&mut self, b: u8) {
        if !self.overflow {
            if self.len < self.cap && !self.ptr.is_null() {
                unsafe {
                    *self.ptr.add(self.len) = b;
                }
            } else {
                self.overflow = true;
            }
        }
        self.len += 1;
    }

    #[inline]
    fn push_slice(&mut self, bytes: &[u8]) {
        if bytes.is_empty() {
            return;
        }
        let next_len = self.len + bytes.len();
        if !self.overflow {
            if next_len <= self.cap && !self.ptr.is_null() {
                unsafe {
                    std::ptr::copy_nonoverlapping(
                        bytes.as_ptr(),
                        self.ptr.add(self.len),
                        bytes.len(),
                    );
                }
            } else {
                self.overflow = true;
            }
        }
        self.len = next_len;
    }

    #[inline]
    fn finish(&self) -> isize {
        if self.overflow {
            -(self.len.max(4) as isize)
        } else {
            self.len as isize
        }
    }
}

struct SmallBytes {
    stack: [u8; 512],
    len: usize,
    heap: Option<Vec<u8>>,
}

impl SmallBytes {
    #[inline]
    fn new() -> Self {
        Self {
            stack: [0u8; 512],
            len: 0,
            heap: None,
        }
    }

    #[inline]
    fn clear(&mut self) {
        self.len = 0;
        if let Some(ref mut v) = self.heap {
            v.clear();
        }
    }

    #[inline]
    fn is_empty(&self) -> bool {
        self.as_slice().is_empty()
    }

    #[inline]
    fn push(&mut self, b: u8) {
        if let Some(ref mut v) = self.heap {
            v.push(b);
        } else if self.len < self.stack.len() {
            self.stack[self.len] = b;
            self.len += 1;
        } else {
            let mut v = Vec::with_capacity(1024);
            v.extend_from_slice(&self.stack[..self.len]);
            v.push(b);
            self.heap = Some(v);
        }
    }

    #[inline]
    fn extend_from_slice(&mut self, s: &[u8]) {
        if s.is_empty() {
            return;
        }
        if let Some(ref mut v) = self.heap {
            v.extend_from_slice(s);
        } else if self.len + s.len() <= self.stack.len() {
            self.stack[self.len..self.len + s.len()].copy_from_slice(s);
            self.len += s.len();
        } else {
            let mut v = Vec::with_capacity((self.len + s.len()).max(1024));
            v.extend_from_slice(&self.stack[..self.len]);
            v.extend_from_slice(s);
            self.heap = Some(v);
        }
    }

    #[inline]
    fn truncate(&mut self, new_len: usize) {
        if let Some(ref mut v) = self.heap {
            v.truncate(new_len);
        } else if new_len < self.len {
            self.len = new_len;
        }
    }

    #[inline]
    fn as_slice(&self) -> &[u8] {
        if let Some(ref v) = self.heap {
            v.as_slice()
        } else {
            &self.stack[..self.len]
        }
    }
}

/// Implements `ManticoreSearchDriver::escapeString(string $string)`.
fn manticore_escape_string_into(input: &[u8], out: &mut SmallBytes) {
    out.clear();
    if input.is_empty() || input == b"*" || input == b"0" {
        return;
    }

    let mut i = 0;
    while i < input.len() {
        let b = input[i];
        match b {
            b'\\' => out.extend_from_slice(b"\\\\"),
            b'(' | b')' | b'@' | b'~' | b'"' | b'&' | b'/' | b'$' | b'=' | b'\'' | b'[' | b']'
            | b'!' => {
                out.push(b'\\');
                out.push(b);
            }
            b'-' => {
                if i + 1 < input.len() && input[i + 1] == b'-' {
                    out.extend_from_slice(b"\\\\-\\-");
                    i += 2;
                    continue;
                } else {
                    out.extend_from_slice(b"\\-");
                }
            }
            _ => out.push(b),
        }
        i += 1;
    }

    // `$string = rtrim($string, '-!');`
    let mut end = out.as_slice().len();
    while end > 0 && matches!(out.as_slice()[end - 1], b'-' | b'!') {
        end -= 1;
    }
    out.truncate(end);

    // `return trim($string);`
    let trimmed = trim_php_ws(out.as_slice());
    if trimmed.len() != out.as_slice().len() {
        let mut tmp = SmallBytes::new();
        tmp.extend_from_slice(trimmed);
        out.clear();
        out.extend_from_slice(tmp.as_slice());
    }
}

/// Implements `ManticoreSearchDriver::prepareReleaseFieldSearchQuery('fromname', $query)`.
fn manticore_escape_fromname_into(input: &[u8], out: &mut SmallBytes) {
    out.clear();
    let trimmed = trim_php_ws(input);
    if trimmed.is_empty() {
        return;
    }
    for &b in trimmed {
        if matches!(
            b,
            b'\\'
                | b'@'
                | b'~'
                | b'&'
                | b'/'
                | b'$'
                | b'='
                | b'\''
                | b'['
                | b']'
                | b'('
                | b')'
                | b'"'
                | b'!'
                | b'-'
                | b'|'
                | b'^'
                | b'<'
                | b'>'
                | b'*'
                | b'?'
                | b'%'
        ) {
            out.push(b'\\');
        }
        out.push(b);
    }
}

#[inline]
fn is_user_query_escape_byte(b: u8) -> bool {
    matches!(
        b,
        b'\\' | b'@' | b'~' | b'&' | b'/' | b'$' | b'=' | b'\'' | b'[' | b']' | b'"' | b'!' | b'-'
    )
}

/// Implements `ManticoreSearchDriver::prepareUserSearchQuery(string $query)`.
fn manticore_prepare_user_query_into(input: &[u8], out: &mut SmallBytes) {
    out.clear();
    let query = trim_php_ws(input);
    if query.is_empty() || query == b"*" {
        return;
    }

    let n = query.len();
    let mut i = 0usize;
    let mut first_token = true;

    while i < n {
        while i < n && is_ascii_ws(query[i]) {
            i += 1;
        }
        if i >= n {
            break;
        }

        // Match `/[-!]?"[^"]*"|\S+/` at `i`
        let token_end = if query[i] == b'"' {
            if let Some(rel) = query[i + 1..].iter().position(|&b| b == b'"') {
                i + 1 + rel + 1
            } else {
                let mut e = i + 1;
                while e < n && !is_ascii_ws(query[e]) {
                    e += 1;
                }
                e
            }
        } else if matches!(query[i], b'-' | b'!') && i + 1 < n && query[i + 1] == b'"' {
            if let Some(rel) = query[i + 2..].iter().position(|&b| b == b'"') {
                i + 2 + rel + 1
            } else {
                let mut e = i + 1;
                while e < n && !is_ascii_ws(query[e]) {
                    e += 1;
                }
                e
            }
        } else {
            let mut e = i + 1;
            while e < n && !is_ascii_ws(query[e]) {
                e += 1;
            }
            e
        };

        let mut token = &query[i..token_end];
        i = token_end;

        if token == b"|" {
            if !first_token {
                out.push(b' ');
            }
            out.push(b'|');
            first_token = false;
            continue;
        }

        let mut leading_parens = 0usize;
        let mut trailing_parens = 0usize;
        while token.first() == Some(&b'(') {
            leading_parens += 1;
            token = &token[1..];
        }
        while token.last() == Some(&b')') && token.first() != Some(&b'"') {
            trailing_parens += 1;
            token = &token[..token.len() - 1];
        }

        if token.is_empty() {
            if leading_parens > 0 || trailing_parens > 0 {
                if !first_token {
                    out.push(b' ');
                }
                for _ in 0..leading_parens {
                    out.push(b'(');
                }
                for _ in 0..trailing_parens {
                    out.push(b')');
                }
                first_token = false;
            }
            continue;
        }

        let mut negation: Option<u8> = None;
        if token.len() > 1 && matches!(token[0], b'!' | b'-') {
            negation = Some(token[0]);
            token = &token[1..];
        }

        if token.len() > 1 && token.first() == Some(&b'"') && token.last() == Some(&b'"') {
            let inner = &token[1..token.len() - 1];
            if !first_token {
                out.push(b' ');
            }
            for _ in 0..leading_parens {
                out.push(b'(');
            }
            if let Some(neg) = negation {
                out.push(neg);
            }
            out.push(b'"');
            for &b in inner {
                if is_user_query_escape_byte(b) {
                    out.push(b'\\');
                }
                out.push(b);
            }
            out.push(b'"');
            for _ in 0..trailing_parens {
                out.push(b')');
            }
            first_token = false;
            continue;
        }

        let wildcard_prefix = token.first() == Some(&b'*');
        if wildcard_prefix {
            while token.first() == Some(&b'*') {
                token = &token[1..];
            }
        }
        let wildcard_suffix = token.last() == Some(&b'*');
        if wildcard_suffix {
            while token.last() == Some(&b'*') {
                token = &token[..token.len() - 1];
            }
        }

        if !token.is_empty() || wildcard_prefix || wildcard_suffix {
            if !first_token {
                out.push(b' ');
            }
            for _ in 0..leading_parens {
                out.push(b'(');
            }
            if let Some(neg) = negation {
                out.push(neg);
            }
            if wildcard_prefix {
                out.push(b'*');
            }
            for &b in token {
                if is_user_query_escape_byte(b) {
                    out.push(b'\\');
                }
                out.push(b);
            }
            if wildcard_suffix {
                out.push(b'*');
            }
            for _ in 0..trailing_parens {
                out.push(b')');
            }
            first_token = false;
        }
    }

    let trimmed = trim_php_ws(out.as_slice());
    if trimmed.len() != out.as_slice().len() {
        let mut tmp = SmallBytes::new();
        tmp.extend_from_slice(trimmed);
        out.clear();
        out.extend_from_slice(tmp.as_slice());
    }
}

/// Checks `ManticoreSearchDriver::queryHasNegation` on a single string (`/(?:^|\s)[!-]\S/`).
#[inline]
fn query_has_negation_bytes(s: &[u8]) -> bool {
    for i in 0..s.len().saturating_sub(1) {
        if matches!(s[i], b'!' | b'-')
            && !is_ascii_ws(s[i + 1])
            && (i == 0 || is_ascii_ws(s[i - 1]))
        {
            return true;
        }
    }
    false
}

/// Implements `ManticoreSearchDriver::scopePreparedQueryToField`.
fn scope_prepared_query_to_field_into(
    prepared_query: &[u8],
    field_selector: &[u8],
    out: &mut SmallBytes,
) {
    let pq = trim_php_ws(prepared_query);
    if pq.is_empty() {
        return;
    }
    if !field_selector.is_empty() {
        out.extend_from_slice(field_selector);
        out.push(b' ');
    }
    out.push(b'(');
    out.extend_from_slice(pq);
    out.push(b')');
}

/// Implements `ManticoreSearchDriver::scopeReleaseSearchQuery`.
/// Returns `false` if `raw_query` contains non-ASCII bytes so caller can fall back to PHP `/u`.
fn scope_release_search_query_into(
    raw_query: &[u8],
    prepared_query: &[u8],
    field_selector: &[u8],
    out: &mut SmallBytes,
) -> bool {
    if raw_query.iter().any(|&b| b >= 0x80 || b == 0) {
        return false;
    }
    out.clear();
    let mut primary = SmallBytes::new();
    scope_prepared_query_to_field_into(prepared_query, field_selector, &mut primary);

    let is_release_field =
        field_selector == b"@searchname" || field_selector == b"@(name,searchname)";
    if !is_release_field || query_has_negation_bytes(raw_query) {
        out.extend_from_slice(primary.as_slice());
        return true;
    }

    let mut has_sep = false;
    for &b in raw_query {
        if matches!(b, b'.' | b'_' | b'-') {
            has_sep = true;
        } else if !(b.is_ascii_alphanumeric() || is_ascii_ws(b)) {
            out.extend_from_slice(primary.as_slice());
            return true;
        }
    }
    if !has_sep {
        out.extend_from_slice(primary.as_slice());
        return true;
    }

    // `$normalized = preg_replace('/[._-]+/u', ' ', trim($rawQuery));`
    // `$normalized = trim(preg_replace('/\s+/', ' ', $normalized));`
    let trimmed_raw = trim_php_ws(raw_query);
    let mut normalized = SmallBytes::new();
    let mut prev_space = true;
    for &b in trimmed_raw {
        if matches!(b, b'.' | b'_' | b'-') || is_ascii_ws(b) {
            if !prev_space {
                normalized.push(b' ');
                prev_space = true;
            }
        } else {
            normalized.push(b);
            prev_space = false;
        }
    }
    let norm_slice = trim_php_ws(normalized.as_slice());
    let mut norm_prepared = SmallBytes::new();
    manticore_prepare_user_query_into(norm_slice, &mut norm_prepared);

    if norm_prepared.is_empty() || norm_prepared.as_slice() == prepared_query {
        out.extend_from_slice(primary.as_slice());
        return true;
    }

    let mut norm_scoped = SmallBytes::new();
    scope_prepared_query_to_field_into(norm_prepared.as_slice(), field_selector, &mut norm_scoped);

    out.push(b'(');
    out.extend_from_slice(primary.as_slice());
    out.extend_from_slice(b" | ");
    out.extend_from_slice(norm_scoped.as_slice());
    out.push(b')');
    true
}

/// Strips `.(mkv|avi|mp4|wmv|nfo|nzb|par2|rar|zip|r\d+)$` (case-insensitive) from `s`.
#[inline]
fn strip_suggestion_ext(s: &[u8]) -> &[u8] {
    let Some(dot_pos) = s.iter().rposition(|&b| b == b'.') else {
        return s;
    };
    let ext = &s[dot_pos + 1..];
    if ext.is_empty() {
        return s;
    }
    if matches!(ext[0], b'r' | b'R')
        && ext.len() >= 2
        && ext[1..].iter().all(|b| b.is_ascii_digit())
    {
        return &s[..dot_pos];
    }
    if ext.len() == 3 {
        let low = [
            ext[0].to_ascii_lowercase(),
            ext[1].to_ascii_lowercase(),
            ext[2].to_ascii_lowercase(),
        ];
        if matches!(
            &low,
            b"mkv" | b"avi" | b"mp4" | b"wmv" | b"nfo" | b"nzb" | b"rar" | b"zip"
        ) {
            return &s[..dot_pos];
        }
    } else if ext.len() == 4 && ext.eq_ignore_ascii_case(b"par2") {
        return &s[..dot_pos];
    }
    s
}

/// Case-insensitive ASCII substring search (`stripos`).
#[inline]
fn ascii_stripos(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    if needle.is_empty() {
        return Some(0);
    }
    if needle.len() > haystack.len() {
        return None;
    }
    let first_low = needle[0].to_ascii_lowercase();
    let max_pos = haystack.len() - needle.len();
    for i in 0..=max_pos {
        if haystack[i].to_ascii_lowercase() == first_low
            && haystack[i..i + needle.len()].eq_ignore_ascii_case(needle)
        {
            return Some(i);
        }
    }
    None
}

/// Implements `ManticoreSearchDriver::extractSuggestion` / `ElasticSearchDriver::extractSuggestion`.
fn extract_suggestion_into(
    searchname: &[u8],
    query: &[u8],
    out: &mut SmallBytes,
    clean_buf: &mut SmallBytes,
) -> bool {
    out.clear();
    if searchname
        .iter()
        .any(|&b| b >= 0x80 || b == 0 || b == b'\n')
        || query.iter().any(|&b| b >= 0x80 || b == 0)
    {
        return false;
    }
    let stripped = strip_suggestion_ext(searchname);
    clean_buf.clear();
    let mut prev_ws = true;
    for &b in stripped {
        let ch = if matches!(b, b'.' | b'_') { b' ' } else { b };
        if is_ascii_ws(ch) {
            if !prev_ws {
                clean_buf.push(b' ');
                prev_ws = true;
            }
        } else {
            clean_buf.push(ch);
            prev_ws = false;
        }
    }
    let clean = trim_php_ws(clean_buf.as_slice());
    if clean.is_empty() || clean == b"0" {
        return true;
    }
    if clean.len() <= 80 {
        out.extend_from_slice(clean);
        return true;
    }

    if let Some(pos) = ascii_stripos(clean, query) {
        let start = pos.saturating_sub(10);
        let end = (start + 80).min(clean.len());
        let mut extracted = &clean[start..end];
        if start > 0 {
            if let Some(ws_idx) = extracted.iter().position(|&b| is_ascii_ws(b)) {
                extracted = &extracted[ws_idx + 1..];
            }
        }
        if let Some(last_ws) = extracted.iter().rposition(|&b| is_ascii_ws(b)) {
            extracted = &extracted[..last_ws];
        }
        out.extend_from_slice(trim_php_ws(extracted));
        return true;
    }

    out.extend_from_slice(&clean[..80]);
    true
}

/// Standard byte-level Levenshtein distance matching PHP `levenshtein($a, $b)` (returns -1 if > 255 bytes).
#[inline]
fn php_levenshtein_max3(a: &[u8], b: &[u8]) -> i32 {
    if a.len() > 255 || b.len() > 255 {
        return -1;
    }
    if a.len().abs_diff(b.len()) > 3 {
        return 4;
    }
    let m = a.len();
    let n = b.len();
    if m == 0 {
        return n as i32;
    }
    if n == 0 {
        return m as i32;
    }
    let mut prev = [0u8; 68];
    let mut curr = [0u8; 68];
    for (j, slot) in prev.iter_mut().enumerate().take(n + 1) {
        *slot = j as u8;
    }
    for i in 1..=m {
        curr[0] = i as u8;
        let mut row_min = curr[0];
        let ca = a[i - 1];
        for j in 1..=n {
            let cost = u8::from(ca != b[j - 1]);
            let v = (prev[j] + 1).min(curr[j - 1] + 1).min(prev[j - 1] + cost);
            curr[j] = v;
            if v < row_min {
                row_min = v;
            }
        }
        if row_min > 3 {
            return 4;
        }
        prev[..=n].copy_from_slice(&curr[..=n]);
    }
    i32::from(prev[n])
}

// ============================================================================
// C ABI Exports
// ============================================================================

/// Accelerates `ManticoreSearchDriver::escapeString(string $string)`.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_manticore_escape_string(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() {
        return -1;
    }
    if input_len == 0 {
        return 0;
    }
    let slice = unsafe { slice::from_raw_parts(input, input_len) };
    let mut escaped = SmallBytes::new();
    manticore_escape_string_into(slice, &mut escaped);
    let mut cur = OutCursor::new(out_buf, out_cap);
    cur.push_slice(escaped.as_slice());
    cur.finish()
}

/// Accelerates `ManticoreSearchDriver::prepareUserSearchQuery` (`mode == 0`) and
/// `ManticoreSearchDriver::prepareReleaseFieldSearchQuery('fromname', $query)` (`mode == 1`).
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_manticore_prepare_query(
    input: *const u8,
    input_len: usize,
    mode: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() {
        return -1;
    }
    if input_len == 0 {
        return 0;
    }
    let slice = unsafe { slice::from_raw_parts(input, input_len) };
    let mut out = SmallBytes::new();
    if mode == 1 {
        manticore_escape_fromname_into(slice, &mut out);
    } else {
        manticore_prepare_user_query_into(slice, &mut out);
    }
    let mut cur = OutCursor::new(out_buf, out_cap);
    cur.push_slice(out.as_slice());
    cur.finish()
}

/// Accelerates `ManticoreSearchDriver::scopeReleaseSearchQuery`.
/// Returns `-2` when declined to PHP (non-ASCII `raw_query`), or `>= 0` written bytes.
///
/// # Safety
///
/// `raw_query`, `prepared_query`, and `field_selector` must be valid for reads of their lengths when length > 0.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_manticore_scope_release_query(
    raw_query: *const u8,
    raw_len: usize,
    prepared_query: *const u8,
    prepared_len: usize,
    field_selector: *const u8,
    field_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if (raw_len > 0 && raw_query.is_null())
        || (prepared_len > 0 && prepared_query.is_null())
        || (field_len > 0 && field_selector.is_null())
    {
        return -1;
    }
    let raw = if raw_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(raw_query, raw_len) }
    };
    let prep = if prepared_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(prepared_query, prepared_len) }
    };
    let field = if field_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(field_selector, field_len) }
    };

    let mut scoped = SmallBytes::new();
    if !scope_release_search_query_into(raw, prep, field, &mut scoped) {
        return -2;
    }
    let mut cur = OutCursor::new(out_buf, out_cap);
    cur.push_slice(scoped.as_slice());
    cur.finish()
}

/// Accelerates `ManticoreSearchDriver::buildFieldSearchExpression`.
/// Input is `count` NUL-separated pairs `field\0raw_value` (`2 * count` NUL-separated segments).
/// Writes the full `@@relaxed ...` expression into `out_buf`, or returns `-3` when `null`.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_manticore_build_field_search_expr_nul(
    input: *const u8,
    input_len: usize,
    pair_count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if pair_count == 0 {
        return -3;
    }
    if input.is_null() {
        return -1;
    }
    let slice = unsafe { slice::from_raw_parts(input, input_len) };
    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut any_term = false;
    let mut pos = 0usize;
    let mut field_sel = SmallBytes::new();
    let mut prepared = SmallBytes::new();
    let mut scoped = SmallBytes::new();

    for _ in 0..pair_count {
        let Some(f_end_rel) = slice[pos..].iter().position(|&b| b == 0) else {
            return -2;
        };
        let field = &slice[pos..pos + f_end_rel];
        pos += f_end_rel + 1;
        let v_end = slice[pos..]
            .iter()
            .position(|&b| b == 0)
            .map_or(slice.len(), |r| pos + r);
        let raw_val = &slice[pos..v_end];
        pos = (v_end + 1).min(slice.len());

        if raw_val.is_empty() {
            continue;
        }
        manticore_prepare_user_query_into(raw_val, &mut prepared);
        if prepared.is_empty() {
            continue;
        }
        field_sel.clear();
        field_sel.push(b'@');
        field_sel.extend_from_slice(field);

        if !scope_release_search_query_into(
            raw_val,
            prepared.as_slice(),
            field_sel.as_slice(),
            &mut scoped,
        ) {
            return -2;
        }
        if !scoped.is_empty() {
            if !any_term {
                cur.push_slice(b"@@relaxed ");
                any_term = true;
            } else {
                cur.push_byte(b' ');
            }
            cur.push_slice(scoped.as_slice());
        }
    }

    if !any_term {
        return -3;
    }
    cur.finish()
}

/// Accelerates `ManticoreSearchDriver::extractSuggestion` / `ElasticSearchDriver::extractSuggestion`.
/// Returns `-3` when `null`, `-2` when declined to PHP, or `>= 0` written bytes.
///
/// # Safety
///
/// `searchname` and `query` must be valid for reads of their lengths when non-null and length > 0.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_extract_suggestion(
    searchname: *const u8,
    searchname_len: usize,
    query: *const u8,
    query_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if searchname.is_null() || (query_len > 0 && query.is_null()) {
        return -1;
    }
    if searchname_len == 0 {
        return -3;
    }
    let sn = unsafe { slice::from_raw_parts(searchname, searchname_len) };
    let q = if query_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(query, query_len) }
    };
    let mut suggestion = SmallBytes::new();
    let mut clean_buf = SmallBytes::new();
    if !extract_suggestion_into(sn, q, &mut suggestion, &mut clean_buf) {
        return -2;
    }
    if suggestion.is_empty() {
        return -3;
    }
    let mut cur = OutCursor::new(out_buf, out_cap);
    cur.push_slice(suggestion.as_slice());
    cur.finish()
}

/// Accelerates `ManticoreSearchDriver::autocomplete` candidate extraction, normalization,
/// deduplication, and prefix/frequency ranking over `count` newline-delimited `searchname` strings.
/// Writes up to `max_results` records encoded as `"{suggest}\0{docs}\n"` into `out_buf`.
///
/// # Safety
///
/// `query` and `input` must be valid for reads of their lengths when non-null and length > 0.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_rank_autocomplete_nul(
    query: *const u8,
    query_len: usize,
    input: *const u8,
    input_len: usize,
    count: usize,
    max_results: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if count == 0 || max_results == 0 || query_len == 0 || query_len > 80 {
        return if query_len > 80 { -2 } else { 0 };
    }
    if query.is_null() || input.is_null() {
        return -1;
    }
    let q_slice = unsafe { slice::from_raw_parts(query, query_len) };
    let in_slice = unsafe { slice::from_raw_parts(input, input_len) };
    if q_slice.iter().any(|&b| b >= 0x80 || b == 0) {
        return -2;
    }

    #[derive(Clone, Copy)]
    struct RankedEntry {
        offset: u16,
        len: u8,
        prefix: u8,
        docs: u16,
    }
    let mut ranked = [RankedEntry {
        offset: 0,
        len: 0,
        prefix: 0,
        docs: 0,
    }; 64];
    let mut ranked_len = 0usize;
    let mut arena = SmallBytes::new();
    let mut suggestion = SmallBytes::new();
    let mut clean_buf = SmallBytes::new();

    let mut pos = 0usize;
    for idx in 0..count {
        let end = if idx + 1 == count {
            in_slice.len()
        } else {
            match in_slice[pos..].iter().position(|&b| b == b'\n') {
                Some(rel) => pos + rel,
                None => return -2,
            }
        };
        let sn = &in_slice[pos..end];
        if idx + 1 == count && sn.contains(&b'\n') {
            return -2;
        }
        pos = (end + 1).min(in_slice.len());

        if sn.is_empty() || sn == b"0" {
            continue;
        }
        if !extract_suggestion_into(sn, q_slice, &mut suggestion, &mut clean_buf) {
            return -2;
        }
        let sug_slice = suggestion.as_slice();
        if sug_slice.is_empty() || sug_slice.len() > 80 {
            continue;
        }
        let slen = sug_slice.len();

        let mut found = false;
        let arena_bytes = arena.as_slice();
        for entry in ranked.iter_mut().take(ranked_len) {
            let e_len = usize::from(entry.len);
            if e_len == slen {
                let e_off = usize::from(entry.offset);
                if arena_bytes[e_off..e_off + e_len].eq_ignore_ascii_case(sug_slice) {
                    entry.docs += 1;
                    found = true;
                    break;
                }
            }
        }
        if !found {
            if ranked_len >= ranked.len() || arena.as_slice().len() + slen > 0xFFFF {
                return -2;
            }
            let prefix =
                u8::from(slen >= query_len && sug_slice[..query_len].eq_ignore_ascii_case(q_slice));
            let offset = arena.as_slice().len() as u16;
            arena.extend_from_slice(sug_slice);
            ranked[ranked_len] = RankedEntry {
                offset,
                len: slen as u8,
                prefix,
                docs: 1,
            };
            ranked_len += 1;
        }
    }

    ranked[..ranked_len].sort_by(|a, b| (b.prefix, b.docs).cmp(&(a.prefix, a.docs)));

    let arena_bytes = arena.as_slice();
    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut num_buf = [0u8; 16];
    for entry in ranked[..ranked_len].iter().take(max_results) {
        let e_off = usize::from(entry.offset);
        let e_len = usize::from(entry.len);
        cur.push_slice(&arena_bytes[e_off..e_off + e_len]);
        cur.push_byte(0);
        let mut c = entry.docs;
        let mut n_idx = 16usize;
        if c == 0 {
            n_idx -= 1;
            num_buf[n_idx] = b'0';
        } else {
            while c > 0 {
                n_idx -= 1;
                num_buf[n_idx] = b'0' + (c % 10) as u8;
                c /= 10;
            }
        }
        cur.push_slice(&num_buf[n_idx..]);
        cur.push_byte(b'\n');
    }
    cur.finish()
}

/// Accelerates the Levenshtein spell-fallback token extraction and top-K ranking in
/// `ManticoreSearchDriver::suggestFallback` and `ElasticSearchDriver::suggestFallback`.
/// Input is `count` newline-delimited `searchname` strings.
/// Writes up to `max_results` records encoded as `"{suggest}\0{distance}\0{docs}\n"` into `out_buf`.
///
/// # Safety
///
/// `query` and `input` must be valid for reads of their lengths when non-null and length > 0.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_suggest_fallback_nul(
    query: *const u8,
    query_len: usize,
    input: *const u8,
    input_len: usize,
    count: usize,
    max_results: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if count == 0 || max_results == 0 || query_len == 0 || query_len > 60 {
        return if query_len > 60 { -2 } else { 0 };
    }
    if query.is_null() || input.is_null() {
        return -1;
    }
    let q_slice = unsafe { slice::from_raw_parts(query, query_len) };
    let in_slice = unsafe { slice::from_raw_parts(input, input_len) };
    if q_slice.iter().any(|&b| b >= 0x80 || b == 0) || input_len > 0xFFFF {
        return -2;
    }

    let mut q_low_arr = [0u8; 64];
    for (i, &b) in q_slice.iter().enumerate() {
        q_low_arr[i] = b.to_ascii_lowercase();
    }
    let q_low = &q_low_arr[..query_len];

    #[derive(Clone, Copy)]
    struct TermEntry {
        offset: u16,
        len: u8,
        distance: u8,
        count: u16,
    }
    let mut terms = [TermEntry {
        offset: 0,
        len: 0,
        distance: 0,
        count: 0,
    }; 64];
    let mut terms_len = 0usize;
    let mut rejected = [(0u16, 0u8); 32];
    let mut word_low = [0u8; 64];

    let mut pos = 0usize;
    for idx in 0..count {
        let end = if idx + 1 == count {
            in_slice.len()
        } else {
            match in_slice[pos..].iter().position(|&b| b == b'\n') {
                Some(rel) => pos + rel,
                None => return -2,
            }
        };
        let sn = &in_slice[pos..end];
        if idx + 1 == count && sn.contains(&b'\n') {
            return -2;
        }
        let sn_base = pos;
        pos = (end + 1).min(in_slice.len());

        let mut w_start = 0usize;
        while w_start < sn.len() {
            while w_start < sn.len()
                && (is_ascii_ws(sn[w_start]) || matches!(sn[w_start], b'.' | b'-' | b'_'))
            {
                if sn[w_start] >= 0x80 || sn[w_start] == 0 {
                    return -2;
                }
                w_start += 1;
            }
            if w_start >= sn.len() {
                break;
            }
            let mut w_end = w_start;
            while w_end < sn.len()
                && !(is_ascii_ws(sn[w_end]) || matches!(sn[w_end], b'.' | b'-' | b'_'))
            {
                if sn[w_end] >= 0x80 || sn[w_end] == 0 {
                    return -2;
                }
                w_end += 1;
            }
            let w_len = w_end - w_start;
            if w_len >= 3 && w_len <= 64 && w_len.abs_diff(q_low.len()) <= 3 {
                let raw_word = &sn[w_start..w_end];
                if !raw_word.eq_ignore_ascii_case(q_low) {
                    let mut found = false;
                    for entry in terms.iter_mut().take(terms_len) {
                        let e_len = usize::from(entry.len);
                        if e_len == w_len {
                            let e_off = usize::from(entry.offset);
                            if in_slice[e_off..e_off + e_len].eq_ignore_ascii_case(raw_word) {
                                entry.count += 1;
                                found = true;
                                break;
                            }
                        }
                    }
                    if !found {
                        let slot_idx = ((usize::from(raw_word[0].to_ascii_lowercase()) * 31
                            + usize::from(raw_word[w_len - 1].to_ascii_lowercase()))
                            ^ w_len)
                            & 31;
                        let (r_off, r_len) = rejected[slot_idx];
                        if usize::from(r_len) == w_len
                            && in_slice[usize::from(r_off)..usize::from(r_off) + w_len]
                                .eq_ignore_ascii_case(raw_word)
                        {
                            w_start = w_end;
                            continue;
                        }
                        for (i, &b) in raw_word.iter().enumerate() {
                            word_low[i] = b.to_ascii_lowercase();
                        }
                        let wl = &word_low[..w_len];
                        let dist = php_levenshtein_max3(q_low, wl);
                        if (1..=3).contains(&dist) {
                            if terms_len >= terms.len() {
                                return -2;
                            }
                            terms[terms_len] = TermEntry {
                                offset: (sn_base + w_start) as u16,
                                len: w_len as u8,
                                distance: dist as u8,
                                count: 1,
                            };
                            terms_len += 1;
                        } else {
                            rejected[slot_idx] = ((sn_base + w_start) as u16, w_len as u8);
                        }
                    }
                }
            }
            w_start = w_end;
        }
    }

    terms[..terms_len].sort_by(|a, b| b.count.cmp(&a.count));

    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut num_buf = [0u8; 16];
    for entry in terms[..terms_len].iter().take(max_results) {
        let e_off = usize::from(entry.offset);
        let e_len = usize::from(entry.len);
        for &b in &in_slice[e_off..e_off + e_len] {
            cur.push_byte(b.to_ascii_lowercase());
        }
        cur.push_byte(0);
        cur.push_byte(b'0' + entry.distance);
        cur.push_byte(0);
        let mut c = entry.count;
        let mut n_idx = 16usize;
        if c == 0 {
            n_idx -= 1;
            num_buf[n_idx] = b'0';
        } else {
            while c > 0 {
                n_idx -= 1;
                num_buf[n_idx] = b'0' + (c % 10) as u8;
                c /= 10;
            }
        }
        cur.push_slice(&num_buf[n_idx..]);
        cur.push_byte(b'\n');
    }
    cur.finish()
}

#[inline]
fn is_lucene_operator(b: u8) -> bool {
    matches!(b, b'!' | b'+' | b'-' | b'?' | b'*')
}

#[inline]
fn is_lucene_special(b: u8) -> bool {
    matches!(
        b,
        b'+' | b'-'
            | b'='
            | b'&'
            | b'|'
            | b'>'
            | b'<'
            | b'!'
            | b'('
            | b')'
            | b'{'
            | b'}'
            | b'['
            | b']'
            | b'^'
            | b'"'
            | b'~'
            | b'*'
            | b'?'
            | b':'
            | b'\\'
            | b'/'
    )
}

#[inline]
fn emit_sanitized_lucene_token(st: &[u8], first: &mut bool, cur: &mut OutCursor) {
    if !*first {
        cur.push_byte(b' ');
    }
    *first = false;
    if st.is_empty() {
        return;
    }
    let keep_as_is = st.len() > 1
        && (is_lucene_operator(st[0]) || matches!(st[st.len() - 1], b'+' | b'-' | b'?' | b'*'))
        && !st
            .windows(2)
            .any(|w| is_lucene_operator(w[0]) && is_lucene_operator(w[1]));
    if keep_as_is {
        cur.push_slice(st);
    } else {
        for &b in st {
            if is_lucene_special(b) {
                cur.push_byte(b'\\');
            }
            cur.push_byte(b);
        }
    }
}

/// Accelerates `sanitize(string $phrases)` in `app/Extensions/helper/helpers.php`
/// (called by `ElasticSearchDriver::sanitizeSearchTerms`).
/// Returns `-2` when declined to PHP (non-ASCII bytes), or `>= 0` written bytes.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_sanitize_lucene(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input_len == 0 {
        return 0;
    }
    if input.is_null() {
        return -1;
    }
    let slice = unsafe { slice::from_raw_parts(input, input_len) };
    if slice.iter().any(|&b| b >= 0x80) {
        return -2;
    }

    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut first = true;

    for seg in slice.split(|&b| b == b' ' || b == b'.') {
        if seg.is_empty() {
            emit_sanitized_lucene_token(&[], &mut first, &mut cur);
            continue;
        }
        let mut p = 0usize;
        loop {
            let start = p;
            while p < seg.len() && !is_ascii_ws(seg[p]) {
                p += 1;
            }
            emit_sanitized_lucene_token(&seg[start..p], &mut first, &mut cur);
            if p >= seg.len() {
                break;
            }
            while p < seg.len() && is_ascii_ws(seg[p]) {
                p += 1;
            }
            if p >= seg.len() {
                emit_sanitized_lucene_token(&[], &mut first, &mut cur);
                break;
            }
        }
    }

    cur.finish()
}

#[inline]
fn is_known_unicode_c(c: char) -> bool {
    let u = c as u32;
    if u <= 0x1F || (0x7F..=0x9F).contains(&u) {
        return true;
    }
    if (0xE000..=0xF8FF).contains(&u)
        || (0xF0000..=0xFFFFD).contains(&u)
        || (0x100000..=0x10FFFD).contains(&u)
    {
        return true;
    }
    if (0xFDD0..=0xFDEF).contains(&u) || (u & 0xFFFE) == 0xFFFE {
        return true;
    }
    matches!(
        u,
        0x00AD
            | 0x0600..=0x0605
            | 0x061C
            | 0x06DD
            | 0x070F
            | 0x0890..=0x0891
            | 0x08E2
            | 0x180E
            | 0x200B..=0x200F
            | 0x202A..=0x202E
            | 0x2060..=0x2064
            | 0x2066..=0x206F
            | 0xFEFF
            | 0xFFF9..=0xFFFB
            | 0x110BD
            | 0x110CD
            | 0x13430..=0x1343F
            | 0x1BCA0..=0x1BCA3
            | 0x1D173..=0x1D17A
            | 0xE0001
            | 0xE0020..=0xE007F
    )
}

#[inline]
fn is_known_non_c_unicode(c: char) -> bool {
    let u = c as u32;
    (0x00A0..=0x024F).contains(&u) || u == 0x1F3B5
}

/// Validates a `title` or `filename` field for `PredbSearchDocument::filenameCandidate`.
/// Returns `Some(false)` when definitely invalid, `Some(true)` when valid and all codepoints are
/// verified non-`\p{C}`, or `None` when UTF-8/length/separator rules pass but non-ASCII codepoints
/// require PHP PCRE2 `/\p{C}/u` verification.
#[inline]
fn validate_predb_text_field(bytes: &[u8], max_chars: usize, forbid_separators: bool) -> Option<bool> {
    if bytes.len() > max_chars * 4 {
        return Some(false);
    }
    if bytes.is_ascii() {
        if bytes.len() > max_chars {
            return Some(false);
        }
        if forbid_separators {
            for &b in bytes {
                if b < 0x20 || b == 0x7F || matches!(b, b'/' | b'\\' | b'|') {
                    return Some(false);
                }
            }
        } else {
            for &b in bytes {
                if b < 0x20 || b == 0x7F {
                    return Some(false);
                }
            }
        }
        return Some(true);
    }

    if forbid_separators && bytes.iter().any(|&b| matches!(b, b'/' | b'\\' | b'|')) {
        return Some(false);
    }
    let Ok(s) = std::str::from_utf8(bytes) else {
        return Some(false);
    };
    let mut count = 0usize;
    let mut needs_pcre = false;
    for c in s.chars() {
        count += 1;
        if count > max_chars || is_known_unicode_c(c) {
            return Some(false);
        }
        if !c.is_ascii() && !is_known_non_c_unicode(c) {
            needs_pcre = true;
        }
    }
    if needs_pcre {
        None
    } else {
        Some(true)
    }
}

/// Accelerates `PredbSearchDocument::filenameCandidate` validation in a single zero-allocation call.
/// Returns the positive `i64` document ID when valid, `0` when invalid (`null`), `-1` on null pointers,
/// or `-2` when non-ASCII codepoints in `title`/`filename` should be checked by PHP `preg_match('/\p{C}/u', ...)`.
///
/// # Safety
///
/// All non-empty byte slice pointers (`id_str`, `title`, `filename`, `source`) must be valid for reads of their lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_validate_filename_candidate(
    id_str: *const u8,
    id_len: usize,
    id_int: i64,
    has_id_int: u32,
    title: *const u8,
    title_len: usize,
    filename: *const u8,
    filename_len: usize,
    source: *const u8,
    source_len: usize,
) -> i64 {
    if (has_id_int == 0 && id_len > 0 && id_str.is_null())
        || (title_len > 0 && title.is_null())
        || (filename_len > 0 && filename.is_null())
        || (source_len > 0 && source.is_null())
    {
        return -1;
    }

    let valid_id = if has_id_int != 0 {
        if id_int <= 0 {
            return 0;
        }
        id_int
    } else {
        if id_len == 0 {
            return 0;
        }
        let id_bytes = unsafe { slice::from_raw_parts(id_str, id_len) };
        let mut acc: i64 = 0;
        for &b in id_bytes {
            if !b.is_ascii_digit() {
                return 0;
            }
            acc = match acc.checked_mul(10).and_then(|v| v.checked_add(i64::from(b - b'0'))) {
                Some(v) => v,
                None => return 0,
            };
        }
        if acc <= 0 {
            return 0;
        }
        acc
    };

    if title_len == 0 || title_len > 255 * 4 || filename_len > 255 * 4 || source_len > 50 * 4 {
        return 0;
    }

    if source_len > 0 {
        let src_bytes = unsafe { slice::from_raw_parts(source, source_len) };
        if src_bytes.is_ascii() {
            if source_len > 50 {
                return 0;
            }
        } else {
            let Ok(s) = std::str::from_utf8(src_bytes) else {
                return 0;
            };
            if s.chars().count() > 50 {
                return 0;
            }
        }
    }

    let title_bytes = unsafe { slice::from_raw_parts(title, title_len) };
    let mut needs_pcre = false;
    match validate_predb_text_field(title_bytes, 255, true) {
        Some(true) => {}
        Some(false) => return 0,
        None => needs_pcre = true,
    }

    if filename_len > 0 {
        let fname_bytes = unsafe { slice::from_raw_parts(filename, filename_len) };
        match validate_predb_text_field(fname_bytes, 255, false) {
            Some(true) => {}
            Some(false) => return 0,
            None => needs_pcre = true,
        }
    }

    if needs_pcre {
        -2
    } else {
        valid_id
    }
}

struct SliceOut<'a> {
    buf: &'a mut [u8],
    len: usize,
}

impl<'a> SliceOut<'a> {
    #[inline(always)]
    fn new(buf: &'a mut [u8]) -> Self {
        Self { buf, len: 0 }
    }

    #[inline(always)]
    fn push_byte(&mut self, b: u8) {
        if self.len < self.buf.len() {
            unsafe {
                *self.buf.as_mut_ptr().add(self.len) = b;
            }
        }
        self.len += 1;
    }

    #[inline(always)]
    fn push_2bytes(&mut self, a: u8, b: u8) {
        if self.len + 2 <= self.buf.len() {
            unsafe {
                let p = self.buf.as_mut_ptr().add(self.len);
                *p = a;
                *p.add(1) = b;
            }
        }
        self.len += 2;
    }

    #[inline(always)]
    fn push_slice(&mut self, bytes: &[u8]) {
        let blen = bytes.len();
        let next = self.len + blen;
        if next <= self.buf.len() {
            unsafe {
                std::ptr::copy_nonoverlapping(
                    bytes.as_ptr(),
                    self.buf.as_mut_ptr().add(self.len),
                    blen,
                );
            }
        }
        self.len = next;
    }

    #[inline(always)]
    fn finish(&self) -> isize {
        if self.len > self.buf.len() {
            -(self.len.max(4) as isize)
        } else {
            self.len as isize
        }
    }
}

#[inline(always)]
fn prometheus_escape_help(help: &[u8], cur: &mut SliceOut<'_>) {
    let hlen = help.len();
    if cur.len + hlen * 2 <= cur.buf.len() {
        unsafe {
            let base = cur.buf.as_mut_ptr();
            let mut wp = base.add(cur.len);
            for &b in help {
                if b == b'\\' {
                    *wp = b'\\';
                    *wp.add(1) = b'\\';
                    wp = wp.add(2);
                } else if b == b'\n' {
                    *wp = b'\\';
                    *wp.add(1) = b'n';
                    wp = wp.add(2);
                } else {
                    *wp = b;
                    wp = wp.add(1);
                }
            }
            cur.len = wp.offset_from(base) as usize;
        }
        return;
    }
    for &b in help {
        match b {
            b'\\' => cur.push_2bytes(b'\\', b'\\'),
            b'\n' => cur.push_2bytes(b'\\', b'n'),
            _ => cur.push_byte(b),
        }
    }
}

#[inline(always)]
fn prometheus_escape_label_value(v: &[u8], cur: &mut SliceOut<'_>) {
    let vlen = v.len();
    if cur.len + vlen * 2 <= cur.buf.len() {
        unsafe {
            let base = cur.buf.as_mut_ptr();
            let mut wp = base.add(cur.len);
            for &b in v {
                match b {
                    b'\\' => {
                        *wp = b'\\';
                        *wp.add(1) = b'\\';
                        wp = wp.add(2);
                    }
                    b'"' => {
                        *wp = b'\\';
                        *wp.add(1) = b'"';
                        wp = wp.add(2);
                    }
                    b'\n' => {
                        *wp = b'\\';
                        *wp.add(1) = b'n';
                        wp = wp.add(2);
                    }
                    _ => {
                        *wp = b;
                        wp = wp.add(1);
                    }
                }
            }
            cur.len = wp.offset_from(base) as usize;
        }
        return;
    }
    for &b in v {
        match b {
            b'\\' => cur.push_2bytes(b'\\', b'\\'),
            b'"' => cur.push_2bytes(b'\\', b'"'),
            b'\n' => cur.push_2bytes(b'\\', b'n'),
            _ => cur.push_byte(b),
        }
    }
}

#[inline]
fn prometheus_format_float_bytes(bytes: &[u8], cur: &mut SliceOut<'_>) -> bool {
    match bytes {
        b"NAN" | b"NaN" | b"nan" => {
            cur.push_slice(b"NaN");
            return true;
        }
        b"INF" | b"+INF" | b"Inf" | b"+Inf" | b"inf" | b"+inf" => {
            cur.push_slice(b"+Inf");
            return true;
        }
        b"-INF" | b"-Inf" | b"-inf" => {
            cur.push_slice(b"-Inf");
            return true;
        }
        b"0" | b"-0" | b"0.0" | b"-0.0" => {
            cur.push_byte(b'0');
            return true;
        }
        _ => {}
    }
    if !bytes.is_empty() && bytes.len() <= 14 {
        let start = usize::from(bytes[0] == b'-');
        if start < bytes.len() {
            let mut dot_pos: Option<usize> = None;
            let mut all_valid = true;
            for (i, &b) in bytes[start..].iter().enumerate() {
                if b == b'.' {
                    if dot_pos.is_some() || i == 0 {
                        all_valid = false;
                        break;
                    }
                    dot_pos = Some(start + i);
                } else if !b.is_ascii_digit() {
                    all_valid = false;
                    break;
                }
            }
            if all_valid {
                if let Some(dp) = dot_pos {
                    let frac_len = bytes.len() - dp - 1;
                    if (1..=6).contains(&frac_len) {
                        let mut end = bytes.len();
                        while end > dp + 1 && bytes[end - 1] == b'0' {
                            end -= 1;
                        }
                        if end == dp + 1 {
                            end = dp;
                        }
                        let trimmed = &bytes[..end];
                        if trimmed == b"-0" || trimmed == b"0" {
                            cur.push_byte(b'0');
                        } else {
                            cur.push_slice(trimmed);
                        }
                        return true;
                    }
                } else {
                    if bytes == b"-0" || bytes == b"0" {
                        cur.push_byte(b'0');
                    } else {
                        cur.push_slice(bytes);
                    }
                    return true;
                }
            }
        }
    }
    let Ok(s) = std::str::from_utf8(bytes) else {
        return false;
    };
    let Ok(val) = s.parse::<f64>() else {
        return false;
    };
    if val.is_nan() {
        cur.push_slice(b"NaN");
        return true;
    }
    if val.is_infinite() {
        cur.push_slice(if val.is_sign_positive() { b"+Inf" } else { b"-Inf" });
        return true;
    }
    let mut buf = [0u8; 64];
    let mut w = std::io::Cursor::new(&mut buf[..]);
    if std::io::Write::write_fmt(&mut w, format_args!("{:.6}", val)).is_err() {
        return false;
    }
    let mut len = w.position() as usize;
    while len > 0 && buf[len - 1] == b'0' {
        len -= 1;
    }
    if len > 0 && buf[len - 1] == b'.' {
        len -= 1;
    }
    let formatted = &buf[..len];
    if formatted == b"-0" || formatted.is_empty() {
        cur.push_byte(b'0');
    } else {
        cur.push_slice(formatted);
    }
    true
}

#[inline]
fn parse_usize_ascii(bytes: &[u8]) -> Option<usize> {
    if bytes.is_empty() {
        return None;
    }
    let mut acc = 0usize;
    for &b in bytes {
        if !b.is_ascii_digit() {
            return None;
        }
        acc = acc.checked_mul(10)?.checked_add(usize::from(b - b'0'))?;
    }
    Some(acc)
}

#[inline]
fn php_ser_expect(slice: &[u8], pos: &mut usize, expected: &[u8]) -> bool {
    if slice.len().saturating_sub(*pos) < expected.len() {
        return false;
    }
    if &slice[*pos..*pos + expected.len()] != expected {
        return false;
    }
    *pos += expected.len();
    true
}

#[inline(always)]
fn php_ser_read_usize_until(slice: &[u8], pos: &mut usize, delim: u8) -> Option<usize> {
    let mut p = *pos;
    let n = slice.len();
    if p >= n || !slice[p].is_ascii_digit() {
        return None;
    }
    let mut acc = usize::from(slice[p] - b'0');
    p += 1;
    while p < n {
        let b = slice[p];
        if b == delim {
            *pos = p + 1;
            return Some(acc);
        }
        if !b.is_ascii_digit() {
            return None;
        }
        acc = acc.checked_mul(10)?.checked_add(usize::from(b - b'0'))?;
        p += 1;
    }
    None
}

#[inline(always)]
fn php_ser_read_str<'a>(slice: &'a [u8], pos: &mut usize) -> Option<&'a [u8]> {
    let p = *pos;
    if p + 4 > slice.len() || slice[p] != b's' || slice[p + 1] != b':' {
        return None;
    }
    *pos = p + 2;
    let len = php_ser_read_usize_until(slice, pos, b':')?;
    let q = *pos;
    if q + len + 3 > slice.len() || slice[q] != b'"' || slice[q + 1 + len] != b'"' || slice[q + 2 + len] != b';' {
        return None;
    }
    *pos = q + 3 + len;
    Some(&slice[q + 1..q + 1 + len])
}

#[inline]
fn php_ser_skip_key(slice: &[u8], pos: &mut usize) -> bool {
    if *pos >= slice.len() {
        return false;
    }
    if slice[*pos] == b'i' {
        if !php_ser_expect(slice, pos, b"i:") {
            return false;
        }
        let start = *pos;
        if *pos < slice.len() && slice[*pos] == b'-' {
            *pos += 1;
        }
        while *pos < slice.len() && slice[*pos].is_ascii_digit() {
            *pos += 1;
        }
        if *pos == start || !php_ser_expect(slice, pos, b";") {
            return false;
        }
        true
    } else if slice[*pos] == b's' {
        php_ser_read_str(slice, pos).is_some()
    } else {
        false
    }
}

#[inline]
fn php_ser_skip_samples(slice: &[u8], pos: &mut usize, sample_count: usize) -> bool {
    let n = slice.len();
    let mut p = *pos;
    for _ in 0..sample_count {
        if p + 26 >= n || slice[p] != b'i' || slice[p + 1] != b':' {
            return false;
        }
        p += 2;
        while p < n && slice[p] != b';' {
            p += 1;
        }
        // Skip `;a:2:{s:6:"labels";a:` (21 bytes)
        p += 21;
        if p >= n {
            return false;
        }
        let mut label_count = 0usize;
        while p < n && slice[p].is_ascii_digit() {
            label_count = label_count
                .wrapping_mul(10)
                .wrapping_add(usize::from(slice[p] - b'0'));
            p += 1;
        }
        // Skip `:{` (2 bytes)
        p += 2;
        for _ in 0..(label_count * 2) {
            // Skip `s:` (2 bytes)
            p += 2;
            if p >= n {
                return false;
            }
            let mut slen = 0usize;
            while p < n && slice[p].is_ascii_digit() {
                slen = slen
                    .wrapping_mul(10)
                    .wrapping_add(usize::from(slice[p] - b'0'));
                p += 1;
            }
            // Skip `:"` + slen + `";` (4 + slen bytes)
            p = p.saturating_add(4).saturating_add(slen);
        }
        // Skip `}s:5:"value";` + `i:`/`d:` (15 bytes)
        p += 15;
        while p < n && slice[p] != b';' {
            p += 1;
        }
        // Skip `;}` (2 bytes)
        p += 2;
    }
    if p > n {
        return false;
    }
    *pos = p;
    true
}

fn prometheus_format_php_serialized(slice: &[u8], cur: &mut SliceOut<'_>) -> bool {
    let mut pos = 0usize;
    if !php_ser_expect(slice, &mut pos, b"a:") {
        return false;
    }
    let Some(family_count) = php_ser_read_usize_until(slice, &mut pos, b':') else {
        return false;
    };
    if !php_ser_expect(slice, &mut pos, b"{") {
        return false;
    }

    const MF_HEADER: &[u8] = b"O:47:\"App\\Services\\Monitoring\\Prometheus\\MetricFamily\":4:{";
    const SAMPLES_PROP: &[u8] = b"\0App\\Services\\Monitoring\\Prometheus\\MetricFamily\0samples";

    for _ in 0..family_count {
        if !php_ser_skip_key(slice, &mut pos) {
            return false;
        }
        if !php_ser_expect(slice, &mut pos, MF_HEADER) {
            return false;
        }

        let mut name_slice: Option<&[u8]> = None;
        let mut help_slice: Option<&[u8]> = None;
        let mut type_slice: Option<&[u8]> = None;
        let mut samples_info: Option<(usize, &[u8])> = None;

        for _ in 0..4 {
            let Some(pname) = php_ser_read_str(slice, &mut pos) else {
                return false;
            };
            match pname {
                b"name" => {
                    name_slice = php_ser_read_str(slice, &mut pos);
                    if name_slice.is_none() {
                        return false;
                    }
                }
                b"help" => {
                    help_slice = php_ser_read_str(slice, &mut pos);
                    if help_slice.is_none() {
                        return false;
                    }
                }
                b"type" => {
                    type_slice = php_ser_read_str(slice, &mut pos);
                    if type_slice.is_none() {
                        return false;
                    }
                }
                SAMPLES_PROP => {
                    if !php_ser_expect(slice, &mut pos, b"a:") {
                        return false;
                    }
                    let Some(sc) = php_ser_read_usize_until(slice, &mut pos, b':') else {
                        return false;
                    };
                    if !php_ser_expect(slice, &mut pos, b"{") {
                        return false;
                    }
                    let s_start = pos;
                    if sc > 0 && !php_ser_skip_samples(slice, &mut pos, sc) {
                        return false;
                    }
                    let s_end = pos;
                    if !php_ser_expect(slice, &mut pos, b"}") {
                        return false;
                    }
                    samples_info = Some((sc, &slice[s_start..s_end]));
                }
                _ => return false,
            }
        }

        if !php_ser_expect(slice, &mut pos, b"}") {
            return false;
        }

        let (Some(name), Some(help), Some(kind), Some((sample_count, samples_bytes))) =
            (name_slice, help_slice, type_slice, samples_info)
        else {
            return false;
        };

        if sample_count == 0 {
            continue;
        }

        cur.push_slice(b"# HELP ");
        cur.push_slice(name);
        cur.push_byte(b' ');
        prometheus_escape_help(help, cur);
        cur.push_byte(b'\n');

        cur.push_slice(b"# TYPE ");
        cur.push_slice(name);
        cur.push_byte(b' ');
        cur.push_slice(kind);
        cur.push_byte(b'\n');

        let mut spos = 0usize;
        for _ in 0..sample_count {
            if !php_ser_skip_key(samples_bytes, &mut spos) {
                return false;
            }
            if !php_ser_expect(samples_bytes, &mut spos, b"a:2:{s:6:\"labels\";a:") {
                return false;
            }
            let Some(label_count) = php_ser_read_usize_until(samples_bytes, &mut spos, b':') else {
                return false;
            };
            if !php_ser_expect(samples_bytes, &mut spos, b"{") {
                return false;
            }

            cur.push_slice(name);
            if label_count > 0 {
                cur.push_byte(b'{');
                for l_idx in 0..label_count {
                    if l_idx > 0 {
                        cur.push_byte(b',');
                    }
                    let Some(k) = php_ser_read_str(samples_bytes, &mut spos) else {
                        return false;
                    };
                    let Some(v) = php_ser_read_str(samples_bytes, &mut spos) else {
                        return false;
                    };
                    cur.push_slice(k);
                    cur.push_2bytes(b'=', b'"');
                    prometheus_escape_label_value(v, cur);
                    cur.push_byte(b'"');
                }
                cur.push_byte(b'}');
            }

            if !php_ser_expect(samples_bytes, &mut spos, b"}s:5:\"value\";") {
                return false;
            }
            let vtag = samples_bytes[spos];
            spos += 2; // 'i:' or 'd:'
            let v_start = spos;
            while spos < samples_bytes.len() && samples_bytes[spos] != b';' {
                spos += 1;
            }
            if spos == v_start || spos >= samples_bytes.len() {
                return false;
            }
            let vbytes = &samples_bytes[v_start..spos];
            spos += 1;
            if !php_ser_expect(samples_bytes, &mut spos, b"}") {
                return false;
            }

            cur.push_byte(b' ');
            if vtag == b'i' {
                cur.push_slice(vbytes);
            } else if !prometheus_format_float_bytes(vbytes, cur) {
                return false;
            }
            cur.push_byte(b'\n');
        }
    }

    php_ser_expect(slice, &mut pos, b"}") && pos == slice.len()
}

#[inline]
fn read_nul_token<'a>(slice: &'a [u8], pos: &mut usize, allow_eof: bool) -> Option<&'a [u8]> {
    let start = *pos;
    if start > slice.len() {
        return None;
    }
    let mut p = start;
    while p < slice.len() && slice[p] != 0 {
        p += 1;
    }
    if p < slice.len() {
        *pos = p + 1;
        Some(&slice[start..p])
    } else if allow_eof && start < slice.len() {
        *pos = slice.len();
        Some(&slice[start..])
    } else {
        None
    }
}

fn prometheus_format_nul_packed(slice: &[u8], cur: &mut SliceOut<'_>) -> bool {
    let mut pos = 0usize;
    while pos < slice.len() {
        let Some(name) = read_nul_token(slice, &mut pos, false) else {
            return false;
        };
        if name.is_empty() {
            return false;
        }
        let Some(help) = read_nul_token(slice, &mut pos, false) else {
            return false;
        };
        let Some(kind) = read_nul_token(slice, &mut pos, false) else {
            return false;
        };
        let Some(sc_bytes) = read_nul_token(slice, &mut pos, true) else {
            return false;
        };
        let Some(sample_count) = parse_usize_ascii(sc_bytes) else {
            return false;
        };
        if sample_count == 0 {
            continue;
        }

        cur.push_slice(b"# HELP ");
        cur.push_slice(name);
        cur.push_byte(b' ');
        prometheus_escape_help(help, cur);
        cur.push_byte(b'\n');

        cur.push_slice(b"# TYPE ");
        cur.push_slice(name);
        cur.push_byte(b' ');
        cur.push_slice(kind);
        cur.push_byte(b'\n');

        for s_idx in 0..sample_count {
            let Some(lc_bytes) = read_nul_token(slice, &mut pos, false) else {
                return false;
            };
            let Some(label_count) = parse_usize_ascii(lc_bytes) else {
                return false;
            };

            cur.push_slice(name);
            if label_count > 0 {
                cur.push_byte(b'{');
                for l_idx in 0..label_count {
                    if l_idx > 0 {
                        cur.push_byte(b',');
                    }
                    let Some(k) = read_nul_token(slice, &mut pos, false) else {
                        return false;
                    };
                    let Some(v) = read_nul_token(slice, &mut pos, false) else {
                        return false;
                    };
                    cur.push_slice(k);
                    cur.push_2bytes(b'=', b'"');
                    prometheus_escape_label_value(v, cur);
                    cur.push_byte(b'"');
                }
                cur.push_byte(b'}');
            }

            let allow_eof = s_idx + 1 == sample_count;
            let Some(vbytes) = read_nul_token(slice, &mut pos, allow_eof) else {
                return false;
            };
            if vbytes.is_empty() {
                return false;
            }
            cur.push_byte(b' ');
            let is_plain_int = vbytes != b"-0"
                && vbytes.len() <= 19
                && if vbytes[0] == b'-' {
                    vbytes.len() > 1 && vbytes[1..].iter().all(|b| b.is_ascii_digit())
                } else {
                    vbytes.iter().all(|b| b.is_ascii_digit())
                };
            if is_plain_int {
                cur.push_slice(vbytes);
            } else if !prometheus_format_float_bytes(vbytes, cur) {
                return false;
            }
            cur.push_byte(b'\n');
        }
    }
    true
}

/// Accelerates `Prometheus\TextFormatter::format` over either a PHP `serialize()` payload of
/// `MetricFamily[]` (`a:<count>:{...}`) or a NUL-separated packed stream
/// `name\0help\0type\0sample_count\0(label_count\0(k\0v\0)*value_str\0)*`.
/// Returns `>= 0` written bytes, `-2` when declined to PHP, `-1` on null input, or `< -2` when `out_buf` is too small.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_prometheus_format_text(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input_len == 0 {
        return 0;
    }
    if input.is_null() {
        return -1;
    }
    let slice = unsafe { slice::from_raw_parts(input, input_len) };
    let mut empty = [0u8; 0];
    let out_slice = if out_buf.is_null() || out_cap == 0 {
        &mut empty[..]
    } else {
        unsafe { slice::from_raw_parts_mut(out_buf, out_cap) }
    };
    let mut cur = SliceOut::new(out_slice);

    let is_php_serialized = slice.starts_with(b"a:") && {
        let mut p = 2usize;
        while p < slice.len() && slice[p].is_ascii_digit() {
            p += 1;
        }
        p > 2 && slice[p..].starts_with(b":{")
    };

    let ok = if is_php_serialized {
        prometheus_format_php_serialized(slice, &mut cur)
    } else {
        prometheus_format_nul_packed(slice, &mut cur)
    };

    if !ok {
        return -2;
    }
    cur.finish()
}

/// Accelerates `ReleaseSearchIndexDocument::plainSearchName`:
/// `trim((string) preg_replace('/[._-]+/', ' ', $value))`.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_plain_search_name(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input_len == 0 {
        return 0;
    }
    if input.is_null() {
        return -1;
    }
    let slice = unsafe { slice::from_raw_parts(input, input_len) };
    let mut out = SmallBytes::new();
    let mut in_sep = false;
    for &b in slice {
        if matches!(b, b'.' | b'_' | b'-') {
            if !in_sep {
                out.push(b' ');
                in_sep = true;
            }
        } else {
            out.push(b);
            in_sep = false;
        }
    }
    let trimmed = trim_php_ws(out.as_slice());
    let mut cur = OutCursor::new(out_buf, out_cap);
    cur.push_slice(trimmed);
    cur.finish()
}

#[inline]
fn is_leap_year(y: i64) -> bool {
    (y % 4 == 0 && y % 100 != 0) || (y % 400 == 0)
}

#[inline]
fn days_in_month(y: i64, m: u32) -> u32 {
    match m {
        1 | 3 | 5 | 7 | 8 | 10 | 12 => 31,
        4 | 6 | 9 | 11 => 30,
        2 => {
            if is_leap_year(y) {
                29
            } else {
                28
            }
        }
        _ => 0,
    }
}

#[inline]
fn days_from_civil(y: i64, m: u32, d: u32) -> i64 {
    let y = y - i64::from(m <= 2);
    let era = if y >= 0 { y } else { y - 399 } / 400;
    let yoe = (y - era * 400) as u32;
    let doy = (153 * (if m > 2 { m - 3 } else { m + 9 }) + 2) / 5 + d - 1;
    let doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    era * 146097 + i64::from(doe) - 719468
}

#[inline]
fn parse_2digits(b: &[u8]) -> Option<u32> {
    if b.len() >= 2 && b[0].is_ascii_digit() && b[1].is_ascii_digit() {
        Some(u32::from(b[0] - b'0') * 10 + u32::from(b[1] - b'0'))
    } else {
        None
    }
}

#[inline]
fn parse_4digits(b: &[u8]) -> Option<i64> {
    if b.len() >= 4
        && b[0].is_ascii_digit()
        && b[1].is_ascii_digit()
        && b[2].is_ascii_digit()
        && b[3].is_ascii_digit()
    {
        Some(
            i64::from(b[0] - b'0') * 1000
                + i64::from(b[1] - b'0') * 100
                + i64::from(b[2] - b'0') * 10
                + i64::from(b[3] - b'0'),
        )
    } else {
        None
    }
}

/// Parses a standard UTC `YYYY-MM-DD HH:MM:SS`, `YYYY-MM-DDTHH:MM:SS[Z]`, or `YYYY-MM-DD` datetime string
/// into a Unix timestamp (`i64`), or returns `i64::MIN` when declined to PHP `strtotime`.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_datetime_to_unix_utc(input: *const u8, input_len: usize) -> i64 {
    if input_len == 0 {
        return 0;
    }
    if input.is_null() {
        return i64::MIN;
    }
    let s = trim_php_ws(unsafe { slice::from_raw_parts(input, input_len) });
    if s.is_empty() {
        return 0;
    }
    if !matches!(s.len(), 10 | 19 | 20) {
        return i64::MIN;
    }
    if s[4] != b'-' || s[7] != b'-' {
        return i64::MIN;
    }
    let Some(year) = parse_4digits(&s[0..4]) else {
        return i64::MIN;
    };
    let Some(month) = parse_2digits(&s[5..7]) else {
        return i64::MIN;
    };
    let Some(day) = parse_2digits(&s[8..10]) else {
        return i64::MIN;
    };
    if !(1902..=9999).contains(&year)
        || !(1..=12).contains(&month)
        || day == 0
        || day > days_in_month(year, month)
    {
        return i64::MIN;
    }

    let (hour, min, sec) = if s.len() == 10 {
        (0u32, 0u32, 0u32)
    } else {
        if !matches!(s[10], b' ' | b'T' | b't') || s[13] != b':' || s[16] != b':' {
            return i64::MIN;
        }
        if s.len() == 20 && !matches!(s[19], b'Z' | b'z') {
            return i64::MIN;
        }
        let Some(h) = parse_2digits(&s[11..13]) else {
            return i64::MIN;
        };
        let Some(m) = parse_2digits(&s[14..16]) else {
            return i64::MIN;
        };
        let Some(sc) = parse_2digits(&s[17..19]) else {
            return i64::MIN;
        };
        if h > 23 || m > 59 || sc > 59 {
            return i64::MIN;
        }
        (h, m, sc)
    };

    days_from_civil(year, month, day) * 86_400
        + i64::from(hour) * 3_600
        + i64::from(min) * 60
        + i64::from(sec)
}

const MIN_RFC2822_TS_UTC: i64 = -62_167_219_200; // 0000-01-01 00:00:00 UTC
const MAX_RFC2822_TS_UTC: i64 = 253_402_300_799; // 9999-12-31 23:59:59 UTC

const DOW_NAMES: [[u8; 3]; 7] = [
    *b"Thu", *b"Fri", *b"Sat", *b"Sun", *b"Mon", *b"Tue", *b"Wed",
];

const MONTH_NAMES: [[u8; 3]; 12] = [
    *b"Jan", *b"Feb", *b"Mar", *b"Apr", *b"May", *b"Jun", *b"Jul", *b"Aug", *b"Sep", *b"Oct",
    *b"Nov", *b"Dec",
];

#[inline]
fn civil_from_days(z: i64) -> (i64, u32, u32) {
    let z = z + 719_468;
    let era = if z >= 0 { z } else { z - 146_096 } / 146_097;
    let doe = (z - era * 146_097) as u32;
    let yoe = (doe - doe / 1_460 + doe / 36_524 - doe / 146_096) / 365;
    let y = i64::from(yoe) + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    let y = y + i64::from(m <= 2);
    (y, m, d)
}

#[inline]
fn rfc2822_from_unix_utc_bytes(ts: i64) -> Option<[u8; 31]> {
    if !(MIN_RFC2822_TS_UTC..=MAX_RFC2822_TS_UTC).contains(&ts) {
        return None;
    }
    let days = ts.div_euclid(86_400);
    let sod = ts.rem_euclid(86_400) as u32;
    let dow = days.rem_euclid(7) as usize;
    let (year, month, day) = civil_from_days(days);
    if !(0..=9999).contains(&year) {
        return None;
    }
    let y = year as u32;
    let hour = sod / 3_600;
    let rem = sod % 3_600;
    let min = rem / 60;
    let sec = rem % 60;

    let dow_b = DOW_NAMES[dow];
    let mon_b = MONTH_NAMES[(month - 1) as usize];

    Some([
        dow_b[0],
        dow_b[1],
        dow_b[2],
        b',',
        b' ',
        b'0' + (day / 10) as u8,
        b'0' + (day % 10) as u8,
        b' ',
        mon_b[0],
        mon_b[1],
        mon_b[2],
        b' ',
        b'0' + ((y / 1000) % 10) as u8,
        b'0' + ((y / 100) % 10) as u8,
        b'0' + ((y / 10) % 10) as u8,
        b'0' + (y % 10) as u8,
        b' ',
        b'0' + (hour / 10) as u8,
        b'0' + (hour % 10) as u8,
        b':',
        b'0' + (min / 10) as u8,
        b'0' + (min % 10) as u8,
        b':',
        b'0' + (sec / 10) as u8,
        b'0' + (sec % 10) as u8,
        b' ',
        b'+',
        b'0',
        b'0',
        b'0',
        b'0',
    ])
}

#[inline]
fn trim_filter_validate_int_ws(mut s: &[u8]) -> &[u8] {
    while let Some(&b) = s.first() {
        if matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0B) {
            s = &s[1..];
        } else {
            break;
        }
    }
    while let Some(&b) = s.last() {
        if matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0B) {
            s = &s[..s.len() - 1];
        } else {
            break;
        }
    }
    s
}

#[inline]
fn parse_non_negative_filter_int(input: &[u8]) -> Option<i64> {
    let s = trim_filter_validate_int_ws(input);
    if s.is_empty() {
        return None;
    }
    if s[0] == b'-' {
        return if &s[1..] == b"0" { Some(0) } else { None };
    }
    let digits = if s[0] == b'+' { &s[1..] } else { s };
    if digits.is_empty() {
        return None;
    }
    if digits[0] == b'0' {
        return if digits.len() == 1 { Some(0) } else { None };
    }
    if digits.len() > 19 {
        return None;
    }
    let mut acc: i64 = 0;
    for &b in digits {
        if !b.is_ascii_digit() {
            return None;
        }
        acc = acc.checked_mul(10)?.checked_add(i64::from(b - b'0'))?;
    }
    Some(acc)
}

/// Accelerates `ApiInputCanonicalizer::listValue` for comma-separated strings:
/// splits on `,`, trims PHP default ASCII whitespace (` \t\n\r\x0B\0`), filters empty items,
/// and joins remaining items with `,`.
///
/// # Safety
///
/// `input_ptr` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_api_canonicalize_list_csv(
    input_ptr: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input_len == 0 {
        return 0;
    }
    if input_ptr.is_null() {
        return -1;
    }
    let slice = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut first = true;
    for raw_item in slice.split(|&b| b == b',') {
        let item = trim_php_default(raw_item);
        if !item.is_empty() {
            if !first {
                cur.push_byte(b',');
            }
            first = false;
            cur.push_slice(item);
        }
    }
    cur.finish()
}

/// Accelerates `ReleaseSearchQuery::normalizeMediaUniqueId`:
/// trims PHP default ASCII whitespace (` \t\n\r\x0B\0`) and returns `0` (`null`) for `""` or `"-1"`.
///
/// # Safety
///
/// `input_ptr` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_normalize_media_unique_id(
    input_ptr: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input_len == 0 {
        return 0;
    }
    if input_ptr.is_null() {
        return -1;
    }
    let slice = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    let trimmed = trim_php_default(slice);
    if trimmed.is_empty() || trimmed == b"-1" {
        return 0;
    }
    let mut cur = OutCursor::new(out_buf, out_cap);
    cur.push_slice(trimmed);
    cur.finish()
}

/// Accelerates `ReleaseSearchQuery::normalizeRangeBound` matching
/// `filter_var($value, FILTER_VALIDATE_INT)` >= 0.
/// Returns `1` and writes the non-negative `i64` to `*out_val` when valid, `0` when invalid/negative,
/// or `-1` on null pointer arguments.
///
/// # Safety
///
/// `input_ptr` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_val` must be valid for writes of one `i64`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_normalize_range_bound(
    input_ptr: *const u8,
    input_len: usize,
    out_val: *mut i64,
) -> i32 {
    if out_val.is_null() || (input_len > 0 && input_ptr.is_null()) {
        return -1;
    }
    if input_len == 0 {
        return 0;
    }
    let slice = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    match parse_non_negative_filter_int(slice) {
        Some(val) => {
            unsafe {
                *out_val = val;
            }
            1
        }
        None => 0,
    }
}

/// Formats a Unix timestamp `ts` into PHP `date(DATE_RSS, $ts)` (`"D, d M Y H:i:s +0000"`, 31 bytes)
/// when the default timezone is UTC and year is in `0000..=9999`.
/// Returns `31` on success, `-2` when outside `0000..=9999`, or `-31` when `out_cap < 31`.
///
/// # Safety
///
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rfc2822_from_unix_utc(
    ts: i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(rss) = rfc2822_from_unix_utc_bytes(ts) else {
        return -2;
    };
    let mut cur = OutCursor::new(out_buf, out_cap);
    cur.push_slice(&rss);
    cur.finish()
}

/// Formats `adddate` (`pubDate`) and `postdate` (`usenetdate`, falling back to `adddate` when empty)
/// into two `\n`-separated 31-byte `DATE_RSS` strings (`63` bytes total) when the default timezone
/// is UTC, reusing `nntmux_datetime_to_unix_utc`.
/// Returns `63` on success, `-2` when declined to PHP, `-1` on null pointers, or `-63` when `out_cap < 63`.
///
/// # Safety
///
/// `adddate_ptr` and `postdate_ptr` must be valid for reads of their lengths when length > 0.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_item_rss_dates(
    adddate_ptr: *const u8,
    adddate_len: usize,
    postdate_ptr: *const u8,
    postdate_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if (adddate_len > 0 && adddate_ptr.is_null()) || (postdate_len > 0 && postdate_ptr.is_null()) {
        return -1;
    }
    if adddate_len == 0 {
        return -2;
    }
    let add_slice = unsafe { slice::from_raw_parts(adddate_ptr, adddate_len) };
    if add_slice.contains(&0) {
        return -2;
    }
    let add_trimmed = trim_php_ws(add_slice);
    if add_trimmed.is_empty() {
        return -2;
    }
    let add_ts = unsafe { nntmux_datetime_to_unix_utc(adddate_ptr, adddate_len) };
    if add_ts == i64::MIN {
        return -2;
    }
    let Some(pub_rss) = rfc2822_from_unix_utc_bytes(add_ts) else {
        return -2;
    };

    let post_slice = if postdate_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(postdate_ptr, postdate_len) }
    };
    if post_slice.contains(&0) {
        return -2;
    }
    let post_trimmed = trim_php_default(post_slice);
    let use_rss = if post_trimmed.is_empty() || post_trimmed == add_trimmed {
        pub_rss
    } else {
        if trim_php_ws(post_trimmed).is_empty() {
            return -2;
        }
        let post_ts = unsafe { nntmux_datetime_to_unix_utc(postdate_ptr, postdate_len) };
        if post_ts == i64::MIN {
            return -2;
        }
        let Some(r) = rfc2822_from_unix_utc_bytes(post_ts) else {
            return -2;
        };
        r
    };

    let mut cur = OutCursor::new(out_buf, out_cap);
    cur.push_slice(&pub_rss);
    cur.push_byte(b'\n');
    cur.push_slice(&use_rss);
    cur.finish()
}

// ---------------------------------------------------------------------------
// Verified NZB 1.0 document renderer (VerifiedNzbWriter::document parity).
//
// Mirrors PHP XMLWriter output for a UTF-8 document byte-for-byte. Attribute values escape
// `& < > "` plus `\t \n \r` as character references; element text escapes `& < > "` and `\r`.
// Any other control byte (< 0x20) or invalid UTF-8 declines with -1 so PHP renders it.
//
// meta:  poster\0 date\0 group*\0 password*\0   (group_count, password_count tokens)
// files: per non-empty file `subject (1/N)\0 segment_count\0` then per segment
//        `number\0 bytes\0 messageid\0` (file_count files, segment_count segments in total).
// flags: bit 0 = spot_check verification mode.
// ---------------------------------------------------------------------------

const VNZB_DECLINE: u8 = 2;

const fn vnzb_escape_table(attr: bool) -> [u8; 256] {
    let mut table = [0u8; 256];
    let mut i = 0;
    while i < 0x20 {
        table[i] = VNZB_DECLINE;
        i += 1;
    }
    table[b'\r' as usize] = 1;
    table[b'\t' as usize] = if attr { 1 } else { 0 };
    table[b'\n' as usize] = if attr { 1 } else { 0 };
    table[b'&' as usize] = 1;
    table[b'<' as usize] = 1;
    table[b'>' as usize] = 1;
    table[b'"' as usize] = 1;
    table
}

static VNZB_ATTR: [u8; 256] = vnzb_escape_table(true);
static VNZB_TEXT: [u8; 256] = vnzb_escape_table(false);

trait VnzbSink {
    fn put(&mut self, bytes: &[u8]);
}

impl VnzbSink for OutCursor {
    #[inline]
    fn put(&mut self, bytes: &[u8]) {
        self.push_slice(bytes);
    }
}

impl VnzbSink for Vec<u8> {
    #[inline]
    fn put(&mut self, bytes: &[u8]) {
        self.extend_from_slice(bytes);
    }
}

#[inline]
fn vnzb_escape<S: VnzbSink>(out: &mut S, s: &[u8], table: &[u8; 256]) -> bool {
    let mut start = 0;
    for (i, &b) in s.iter().enumerate() {
        let class = table[b as usize];
        if class == 0 {
            continue;
        }
        if class == VNZB_DECLINE {
            return false;
        }
        out.put(&s[start..i]);
        let reference: &[u8] = match b {
            b'&' => b"&amp;",
            b'<' => b"&lt;",
            b'>' => b"&gt;",
            b'"' => b"&quot;",
            b'\t' => b"&#9;",
            b'\n' => b"&#10;",
            _ => b"&#13;",
        };
        out.put(reference);
        start = i + 1;
    }
    out.put(&s[start..]);
    true
}

struct VnzbTokens<'a> {
    buf: &'a [u8],
    pos: usize,
}

impl<'a> VnzbTokens<'a> {
    #[inline]
    fn next(&mut self) -> Option<&'a [u8]> {
        let rest = &self.buf[self.pos..];
        let end = rest.iter().position(|&b| b == 0)?;
        self.pos += end + 1;
        Some(&rest[..end])
    }

    #[inline]
    fn exhausted(&self) -> bool {
        self.pos == self.buf.len()
    }
}

#[inline]
fn vnzb_parse_count(token: &[u8]) -> Option<usize> {
    if token.is_empty() || token.len() > 18 {
        return None;
    }
    let mut value = 0usize;
    for &b in token {
        if !b.is_ascii_digit() {
            return None;
        }
        value = value * 10 + usize::from(b - b'0');
    }
    Some(value)
}

#[inline]
fn vnzb_trim_angle(mut s: &[u8]) -> &[u8] {
    while let [b'<' | b'>', rest @ ..] = s {
        s = rest;
    }
    while let [rest @ .., b'<' | b'>'] = s {
        s = rest;
    }
    s
}

#[allow(clippy::too_many_arguments)]
fn verified_nzb_render(
    meta: &[u8],
    group_count: usize,
    password_count: usize,
    inventory: i64,
    spot_check: bool,
    files: &[u8],
    file_count: usize,
    segment_count: usize,
    out: &mut OutCursor,
) -> Option<()> {
    if std::str::from_utf8(meta).is_err() || std::str::from_utf8(files).is_err() {
        return None;
    }
    let mut meta_tokens = VnzbTokens { buf: meta, pos: 0 };
    let poster = meta_tokens.next()?;
    let date = meta_tokens.next()?;

    // Shared per-file bytes: the poster/date attributes and the groups element.
    let mut file_open: Vec<u8> = Vec::with_capacity(64 + poster.len() + date.len());
    file_open.extend_from_slice(b"<file poster=\"");
    if !vnzb_escape(&mut file_open, poster, &VNZB_ATTR) {
        return None;
    }
    file_open.extend_from_slice(b"\" date=\"");
    if !vnzb_escape(&mut file_open, date, &VNZB_ATTR) {
        return None;
    }
    file_open.extend_from_slice(b"\" subject=\"");
    let mut groups_xml: Vec<u8> = Vec::with_capacity(64);
    if group_count == 0 {
        groups_xml.extend_from_slice(b"<groups/>");
    } else {
        groups_xml.extend_from_slice(b"<groups>");
        for _ in 0..group_count {
            groups_xml.extend_from_slice(b"<group>");
            if !vnzb_escape(&mut groups_xml, meta_tokens.next()?, &VNZB_TEXT) {
                return None;
            }
            groups_xml.extend_from_slice(b"</group>");
        }
        groups_xml.extend_from_slice(b"</groups>");
    }
    groups_xml.extend_from_slice(b"<segments>");

    out.push_slice(
        b"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<nzb xmlns=\"http://www.newzbin.com/DTD/2003/nzb\"",
    );
    let has_head = inventory > 0 || spot_check || password_count > 0;
    if !has_head && file_count == 0 {
        if password_count != 0 || !meta_tokens.exhausted() || !files.is_empty() {
            return None;
        }
        out.push_slice(b"/>\n");
        return Some(());
    }
    out.push_slice(b">");
    if has_head {
        out.push_slice(b"<head>");
        let inventory_text = inventory.to_string();
        if spot_check {
            if inventory > 0 {
                out.push_slice(b"<meta type=\"nntmux:declared-file-count\">");
                out.push_slice(inventory_text.as_bytes());
                out.push_slice(b"</meta>");
            }
            out.push_slice(b"<meta type=\"nntmux:verification-mode\">spot-check</meta>");
        } else if inventory > 0 {
            out.push_slice(b"<meta type=\"nntmux:verified-file-count\">");
            out.push_slice(inventory_text.as_bytes());
            out.push_slice(b"</meta>");
        }
        for _ in 0..password_count {
            out.push_slice(b"<meta type=\"password\">");
            if !vnzb_escape(out, meta_tokens.next()?, &VNZB_TEXT) {
                return None;
            }
            out.push_slice(b"</meta>");
        }
        out.push_slice(b"</head>");
    }
    if !meta_tokens.exhausted() {
        return None;
    }

    let mut file_tokens = VnzbTokens { buf: files, pos: 0 };
    let mut segments_seen = 0usize;
    for _ in 0..file_count {
        let subject = file_tokens.next()?;
        let segments = vnzb_parse_count(file_tokens.next()?)?;
        if segments == 0 {
            return None;
        }
        segments_seen = segments_seen.checked_add(segments)?;
        if segments_seen > segment_count {
            return None;
        }
        out.push_slice(&file_open);
        if !vnzb_escape(out, subject, &VNZB_ATTR) {
            return None;
        }
        out.push_slice(b"\">");
        out.push_slice(&groups_xml);
        for _ in 0..segments {
            let number = file_tokens.next()?;
            let bytes = file_tokens.next()?;
            let message_id = vnzb_trim_angle(file_tokens.next()?);
            out.push_slice(b"<segment number=\"");
            if !vnzb_escape(out, number, &VNZB_ATTR) {
                return None;
            }
            out.push_slice(b"\" bytes=\"");
            if !vnzb_escape(out, bytes, &VNZB_ATTR) {
                return None;
            }
            out.push_slice(b"\">");
            if !vnzb_escape(out, message_id, &VNZB_TEXT) {
                return None;
            }
            out.push_slice(b"</segment>");
        }
        out.push_slice(b"</segments></file>");
    }
    if segments_seen != segment_count || !file_tokens.exhausted() {
        return None;
    }
    out.push_slice(b"</nzb>\n");
    Some(())
}

/// Render a verified NZB document in `VerifiedNzbWriter::document` byte layout.
///
/// Returns the written length, `-1` when PHP must render the input (control bytes, invalid
/// UTF-8, malformed packing), or `-(required)` (≤ -4) when `out_cap` is too small.
///
/// # Safety
/// Non-empty input pointers must be readable for their lengths; `out_buf` writable for `out_cap`.
#[unsafe(no_mangle)]
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn nntmux_verified_nzb_document(
    meta: *const u8,
    meta_len: usize,
    group_count: usize,
    password_count: usize,
    inventory: i64,
    flags: u32,
    files: *const u8,
    files_len: usize,
    file_count: usize,
    segment_count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if (meta_len > 0 && meta.is_null()) || (files_len > 0 && files.is_null()) {
        return -1;
    }
    let meta = if meta_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(meta, meta_len) }
    };
    let files = if files_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(files, files_len) }
    };
    let mut out = OutCursor::new(out_buf, out_cap);
    match verified_nzb_render(
        meta,
        group_count,
        password_count,
        inventory,
        flags & 1 == 1,
        files,
        file_count,
        segment_count,
        &mut out,
    ) {
        Some(()) => out.finish(),
        None => -1,
    }
}

// ---------------------------------------------------------------------------
// ConstrainedPredbMatcher (`parse`, `suggest`, `completeEvidence` parity).
// ---------------------------------------------------------------------------

const CP_MARKER_GROUP: usize = 0;
const CP_MARKER_YEAR: usize = 1;
const CP_MARKER_EPISODE: usize = 2;
const CP_MARKER_SEASON: usize = 3;
const CP_MARKER_RESOLUTION: usize = 4;
const CP_MARKER_LANGUAGE: usize = 5;
const CP_MARKER_EDITION: usize = 6;
const CP_MARKER_ADULT: usize = 7;
const CP_MARKER_COUNT: usize = 8;

const CP_MARKER_NAMES: [&[u8]; CP_MARKER_COUNT] = [
    b"group",
    b"year",
    b"episode",
    b"season",
    b"resolution",
    b"language",
    b"edition",
    b"adult",
];

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
enum ConstrainedParseStatus {
    Ok,
    Null,
    Declined,
}

struct ConstrainedParsed {
    arena: [u8; 1024],
    title_spans: [(u16, u16); 64],
    title_len: usize,
    title_char_sum: usize,
    empty_single_title: bool,
    suffix_spans: [(u16, u16); 64],
    suffix_len: usize,
    marker_spans: [Option<(u16, u16)>; CP_MARKER_COUNT],
    marker_order: [u8; CP_MARKER_COUNT],
    marker_order_len: usize,
}

impl ConstrainedParsed {
    #[inline]
    fn new() -> Self {
        Self {
            arena: [0u8; 1024],
            title_spans: [(0, 0); 64],
            title_len: 0,
            title_char_sum: 0,
            empty_single_title: false,
            suffix_spans: [(0, 0); 64],
            suffix_len: 0,
            marker_spans: [None; CP_MARKER_COUNT],
            marker_order: [0; CP_MARKER_COUNT],
            marker_order_len: 0,
        }
    }

    #[inline(always)]
    fn title_token(&self, idx: usize) -> &[u8] {
        let (off, len) = self.title_spans[idx];
        let o = usize::from(off);
        &self.arena[o..o + usize::from(len)]
    }

    #[inline(always)]
    fn suffix_token(&self, idx: usize) -> &[u8] {
        let (off, len) = self.suffix_spans[idx];
        let o = usize::from(off);
        &self.arena[o..o + usize::from(len)]
    }

    #[inline(always)]
    fn marker_slice(&self, key: usize) -> Option<&[u8]> {
        self.marker_spans[key].map(|(off, len)| {
            let o = usize::from(off);
            &self.arena[o..o + usize::from(len)]
        })
    }
}

#[inline]
fn trim_php_default(mut s: &[u8]) -> &[u8] {
    while let Some(&b) = s.first() {
        if matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x00 | 0x0B) {
            s = &s[1..];
        } else {
            break;
        }
    }
    while let Some(&b) = s.last() {
        if matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x00 | 0x0B) {
            s = &s[..s.len() - 1];
        } else {
            break;
        }
    }
    s
}

#[inline]
fn match_and_normalize_episode_in_place(buf: &mut [u8]) -> Option<usize> {
    if buf.len() < 4 || buf[0] != b's' {
        return None;
    }
    let mut pos = 1usize;
    let s_start = pos;
    while pos < buf.len() && buf[pos].is_ascii_digit() {
        pos += 1;
    }
    let s_digits = pos - s_start;
    if !(1..=2).contains(&s_digits) || pos >= buf.len() {
        return None;
    }
    let mut check_pos = pos;
    while check_pos < buf.len() {
        if buf[check_pos] != b'e' {
            return None;
        }
        check_pos += 1;
        let e_start = check_pos;
        while check_pos < buf.len() && buf[check_pos].is_ascii_digit() {
            check_pos += 1;
        }
        let e_digits = check_pos - e_start;
        if !(1..=3).contains(&e_digits) {
            return None;
        }
    }

    // Valid episode token; strip leading zeros on each digit run in-place.
    let mut write_pos = 1usize;
    let mut s_nz = s_start;
    while s_nz + 1 < pos && buf[s_nz] == b'0' {
        s_nz += 1;
    }
    for i in s_nz..pos {
        buf[write_pos] = buf[i];
        write_pos += 1;
    }
    while pos < buf.len() {
        // buf[pos] == b'e'
        buf[write_pos] = b'e';
        write_pos += 1;
        pos += 1;
        let e_start = pos;
        while pos < buf.len() && buf[pos].is_ascii_digit() {
            pos += 1;
        }
        let mut e_nz = e_start;
        while e_nz + 1 < pos && buf[e_nz] == b'0' {
            e_nz += 1;
        }
        for i in e_nz..pos {
            buf[write_pos] = buf[i];
            write_pos += 1;
        }
    }
    Some(write_pos)
}

#[inline]
fn classify_constrained_marker(tok: &mut [u8]) -> Option<(usize, usize)> {
    let len = tok.len();
    if len == 4
        && (tok.starts_with(b"19") || tok.starts_with(b"20"))
        && tok[2].is_ascii_digit()
        && tok[3].is_ascii_digit()
    {
        return Some((CP_MARKER_YEAR, 4));
    }
    if tok.first() == Some(&b's') {
        if let Some(ep_len) = match_and_normalize_episode_in_place(tok) {
            return Some((CP_MARKER_EPISODE, ep_len));
        }
        if (len == 2 && tok[1].is_ascii_digit())
            || (len == 3 && tok[1].is_ascii_digit() && tok[2].is_ascii_digit())
        {
            return Some((CP_MARKER_SEASON, len));
        }
    }
    let tok: &[u8] = tok;
    if matches!(
        tok,
        b"480p"
            | b"480i"
            | b"576p"
            | b"576i"
            | b"720p"
            | b"720i"
            | b"1080p"
            | b"1080i"
            | b"2160p"
            | b"2160i"
    ) {
        return Some((CP_MARKER_RESOLUTION, len));
    }
    if matches!(
        tok,
        b"english"
            | b"german"
            | b"french"
            | b"finnish"
            | b"japanese"
            | b"dutch"
            | b"spanish"
            | b"italian"
            | b"multi"
            | b"dual"
    ) {
        return Some((CP_MARKER_LANGUAGE, len));
    }
    if matches!(
        tok,
        b"extended"
            | b"unrated"
            | b"directors"
            | b"theatrical"
            | b"remastered"
            | b"repack"
            | b"proper"
    ) {
        return Some((CP_MARKER_EDITION, len));
    }
    if tok == b"xxx" {
        return Some((CP_MARKER_ADULT, 3));
    }
    None
}

#[inline]
fn is_constrained_boundary_token(tok: &[u8]) -> bool {
    matches!(
        tok,
        b"bluray"
            | b"bdrip"
            | b"web"
            | b"webdl"
            | b"webrip"
            | b"hdtv"
            | b"x264"
            | b"x265"
            | b"h264"
            | b"h265"
            | b"hevc"
            | b"avc"
            | b"ddp"
            | b"dts"
            | b"hdr"
            | b"dovi"
            | b"aac"
            | b"mp4"
    )
}

#[inline]
fn is_constrained_stopword(tok: &[u8]) -> bool {
    matches!(tok, b"the" | b"a" | b"an" | b"and" | b"of")
}

fn constrained_predb_parse_into(input: &[u8], out: &mut ConstrainedParsed) -> ConstrainedParseStatus {
    out.title_len = 0;
    out.title_char_sum = 0;
    out.empty_single_title = false;
    out.suffix_len = 0;
    out.marker_spans = [None; CP_MARKER_COUNT];
    out.marker_order_len = 0;

    if input.is_empty() || input.len() > 512 {
        return ConstrainedParseStatus::Null;
    }
    if !input.is_ascii() {
        return if std::str::from_utf8(input).is_err() {
            ConstrainedParseStatus::Null
        } else {
            ConstrainedParseStatus::Declined
        };
    }

    let trimmed = trim_php_default(input);
    let mut norm_len = 0usize;
    let mut in_dot_sep = false;
    for &b in trimmed {
        if matches!(b, b'.' | b'_' | b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r') {
            if !in_dot_sep {
                out.arena[norm_len] = b'.';
                norm_len += 1;
                in_dot_sep = true;
            }
        } else {
            out.arena[norm_len] = b.to_ascii_lowercase();
            norm_len += 1;
            in_dot_sep = false;
        }
    }

    let mut s_len = norm_len;
    if s_len >= 4 {
        let tail4 = &out.arena[s_len - 4..s_len];
        if matches!(tail4, b".mkv" | b".mp4" | b".avi" | b".rar" | b".nfo") {
            s_len -= 4;
        } else if s_len >= 5 && &out.arena[s_len - 5..s_len] == b".par2" {
            s_len -= 5;
        }
    }

    let mut raw_markers = [(0u8, 0u16, 0u16); 65];
    let mut raw_markers_len = 0usize;
    let mut marker_multi_mask = 0u8;

    let mut group_len = 0usize;
    while group_len < s_len && out.arena[s_len - 1 - group_len].is_ascii_alphanumeric() {
        group_len += 1;
    }
    if (2..=20).contains(&group_len) && s_len > group_len && out.arena[s_len - 1 - group_len] == b'-'
    {
        let g_off = (s_len - group_len) as u16;
        let g_len = group_len as u16;
        out.marker_spans[CP_MARKER_GROUP] = Some((g_off, g_len));
        out.marker_order[0] = CP_MARKER_GROUP as u8;
        out.marker_order_len = 1;
        raw_markers[0] = (CP_MARKER_GROUP as u8, g_off, g_len);
        raw_markers_len = 1;
        s_len -= 1 + group_len;
    }

    let mut token_spans = [(0u16, 0u16); 64];
    let mut token_count = 0usize;
    let mut pos = 0usize;
    while pos < s_len {
        while pos < s_len && !out.arena[pos].is_ascii_alphanumeric() {
            pos += 1;
        }
        if pos >= s_len {
            break;
        }
        let start = pos;
        while pos < s_len && out.arena[pos].is_ascii_alphanumeric() {
            pos += 1;
        }
        if token_count >= 64 {
            return ConstrainedParseStatus::Null;
        }
        token_spans[token_count] = (start as u16, (pos - start) as u16);
        token_count += 1;
    }

    if token_count == 0 {
        out.title_spans[0] = (0, 0);
        out.title_len = 1;
        out.empty_single_title = true;
        return ConstrainedParseStatus::Ok;
    }

    let mut after_boundary = false;
    for &(t_off, t_len) in &token_spans[..token_count] {
        let start = usize::from(t_off);
        let end = start + usize::from(t_len);
        if let Some((key, m_len)) = classify_constrained_marker(&mut out.arena[start..end]) {
            let m_len_u16 = m_len as u16;
            if out.marker_spans[key].is_none() {
                out.marker_spans[key] = Some((t_off, m_len_u16));
                out.marker_order[out.marker_order_len] = key as u8;
                out.marker_order_len += 1;
            } else {
                marker_multi_mask |= 1u8 << key;
            }
            raw_markers[raw_markers_len] = (key as u8, t_off, m_len_u16);
            raw_markers_len += 1;
            after_boundary = true;
            continue;
        }

        let tok = &out.arena[start..end];
        if is_constrained_boundary_token(tok) {
            after_boundary = true;
        }
        if after_boundary {
            out.suffix_spans[out.suffix_len] = (t_off, t_len);
            out.suffix_len += 1;
        } else if !is_constrained_stopword(tok) {
            out.title_spans[out.title_len] = (t_off, t_len);
            out.title_len += 1;
            out.title_char_sum += usize::from(t_len);
        }
    }

    if marker_multi_mask != 0 {
        let mut cursor = norm_len;
        for key in 0..CP_MARKER_COUNT {
            if (marker_multi_mask & (1u8 << key)) == 0 {
                continue;
            }
            let joined_start = cursor;
            let mut first = true;
            for &(rk, r_off, r_len) in &raw_markers[..raw_markers_len] {
                if usize::from(rk) != key {
                    continue;
                }
                if !first {
                    out.arena[cursor] = b' ';
                    cursor += 1;
                }
                first = false;
                let ro = usize::from(r_off);
                let rl = usize::from(r_len);
                out.arena.copy_within(ro..ro + rl, cursor);
                cursor += rl;
            }
            out.marker_spans[key] = Some((joined_start as u16, (cursor - joined_start) as u16));
        }
    }

    ConstrainedParseStatus::Ok
}

/// Bounded byte-level Levenshtein distance returning `<= max_dist` or `max_dist + 1` when exceeded.
#[inline]
fn constrained_levenshtein_bounded(a: &[u8], b: &[u8], max_dist: usize) -> usize {
    if a.len().abs_diff(b.len()) > max_dist {
        return max_dist + 1;
    }
    let m = a.len();
    let n = b.len();
    let mut prev = [0u16; 513];
    let mut curr = [0u16; 513];
    for (j, slot) in prev.iter_mut().enumerate().take(n + 1) {
        *slot = j as u16;
    }
    let limit = max_dist as u16;
    for i in 1..=m {
        curr[0] = i as u16;
        let mut row_min = curr[0];
        let ca = a[i - 1];
        for j in 1..=n {
            let cost = u16::from(ca != b[j - 1]);
            let v = (prev[j] + 1).min(curr[j - 1] + 1).min(prev[j - 1] + cost);
            curr[j] = v;
            if v < row_min {
                row_min = v;
            }
        }
        if row_min > limit {
            return max_dist + 1;
        }
        prev[..=n].copy_from_slice(&curr[..=n]);
    }
    usize::from(prev[n])
}

#[inline]
fn parse_positive_i64_ascii(bytes: &[u8]) -> Option<i64> {
    if bytes.is_empty() || bytes.len() > 19 {
        return None;
    }
    let mut acc: i64 = 0;
    for &b in bytes {
        if !b.is_ascii_digit() {
            return None;
        }
        acc = acc.checked_mul(10)?.checked_add(i64::from(b - b'0'))?;
    }
    if acc > 0 { Some(acc) } else { None }
}

/// Accelerates `ConstrainedPredbMatcher::parse(string $name)`.
/// Returns:
/// - `-1` when declined to PHP (non-ASCII UTF-8 input),
/// - `-2` when `parse()` returns `null` (empty, `> 512` bytes, invalid UTF-8, or `> 64` tokens),
/// - `< -2` when `out_buf` capacity is too small (needed bytes = `-ret`),
/// - `>= 0` number of bytes written to `out_buf` in format `"{title_line}\n{markers_line}\n{suffix_line}"`.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_constrained_predb_parse(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input_len > 0 && input.is_null() {
        return -1;
    }
    if input_len == 0 || input_len > 512 {
        return -2;
    }
    let slice = unsafe { slice::from_raw_parts(input, input_len) };
    let mut parsed = ConstrainedParsed::new();
    match constrained_predb_parse_into(slice, &mut parsed) {
        ConstrainedParseStatus::Null => return -2,
        ConstrainedParseStatus::Declined => return -1,
        ConstrainedParseStatus::Ok => {}
    }

    let mut cur = OutCursor::new(out_buf, out_cap);
    if parsed.empty_single_title {
        cur.push_byte(0x01);
    } else {
        for i in 0..parsed.title_len {
            if i > 0 {
                cur.push_byte(b' ');
            }
            cur.push_slice(parsed.title_token(i));
        }
    }
    cur.push_byte(b'\n');

    for i in 0..parsed.marker_order_len {
        if i > 0 {
            cur.push_byte(b'\t');
        }
        let key = usize::from(parsed.marker_order[i]);
        cur.push_slice(CP_MARKER_NAMES[key]);
        cur.push_byte(b':');
        if let Some(val) = parsed.marker_slice(key) {
            cur.push_slice(val);
        }
    }
    cur.push_byte(b'\n');

    for i in 0..parsed.suffix_len {
        if i > 0 {
            cur.push_byte(b' ');
        }
        cur.push_slice(parsed.suffix_token(i));
    }

    cur.finish()
}

/// Accelerates `ConstrainedPredbMatcher::suggest` and `ConstrainedPredbMatcher::completeEvidence`
/// across a batch of `hit_count` NUL-separated `(id_ascii, title)` pairs in a single FFI call.
///
/// Return status codes:
/// - `-1`: declined to PHP (non-ASCII bytes or malformed packing)
/// - `0`: `candidate_overflow` (`hit_count > 50`)
/// - `1`: `insufficient_title`
/// - `2`: `missing_identity_anchor`
/// - `3`: `constraints_or_score`
/// - `4`: `ambiguous` (`out_f64[0..4]` = `[idx0, idx1, score0, score1]`)
/// - `5`: `constrained_match` with complete marker/suffix evidence (`out_f64[0]` = `idx0`, `out_f64[2]` = `score0`)
/// - `6`: `constrained_match` with `unresolved_release_variant` (`out_f64[0]` = `idx0`, `out_f64[2]` = `score0`)
///
/// # Safety
///
/// `query` and `hits_nul` must be valid for reads of their lengths when length > 0.
/// `out_f64` must be valid for writes of 4 `f64` values.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_constrained_predb_suggest_batch(
    query: *const u8,
    query_len: usize,
    hits_nul: *const u8,
    hits_len: usize,
    hit_count: usize,
    out_f64: *mut f64,
) -> i32 {
    if (query_len > 0 && query.is_null())
        || (hits_len > 0 && hits_nul.is_null())
        || out_f64.is_null()
    {
        return -1;
    }
    if hit_count > 50 {
        return 0;
    }
    let q_bytes = if query_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(query, query_len) }
    };

    let mut q = ConstrainedParsed::new();
    match constrained_predb_parse_into(q_bytes, &mut q) {
        ConstrainedParseStatus::Declined => return -1,
        ConstrainedParseStatus::Null => return 1,
        ConstrainedParseStatus::Ok => {}
    }

    if q.title_len < 2 || q.title_char_sum < 9 {
        return 1;
    }
    if q.marker_spans[CP_MARKER_YEAR].is_none() && q.marker_spans[CP_MARKER_EPISODE].is_none() {
        return 2;
    }
    if hit_count == 0 {
        return 3;
    }

    let h_slice = if hits_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(hits_nul, hits_len) }
    };

    #[derive(Clone, Copy)]
    struct RankedHit {
        id: i64,
        hit_idx: u32,
        rounded_score: f64,
        complete_match: bool,
    }

    let mut ranked = [RankedHit {
        id: 0,
        hit_idx: 0,
        rounded_score: 0.0,
        complete_match: false,
    }; 50];
    let mut ranked_len = 0usize;
    let mut c = ConstrainedParsed::new();

    let mut pos = 0usize;
    'candidates: for idx in 0..hit_count {
        let Some(id_end_rel) = h_slice[pos..].iter().position(|&b| b == 0) else {
            return -1;
        };
        let id_bytes = &h_slice[pos..pos + id_end_rel];
        pos += id_end_rel + 1;

        let title_bytes = if idx + 1 == hit_count {
            let rem = &h_slice[pos..];
            if rem.contains(&0) {
                return -1;
            }
            pos = h_slice.len();
            rem
        } else {
            let Some(t_end_rel) = h_slice[pos..].iter().position(|&b| b == 0) else {
                return -1;
            };
            let t = &h_slice[pos..pos + t_end_rel];
            pos += t_end_rel + 1;
            t
        };

        let Some(hit_id) = parse_positive_i64_ascii(id_bytes) else {
            return -1;
        };

        match constrained_predb_parse_into(title_bytes, &mut c) {
            ConstrainedParseStatus::Declined => return -1,
            ConstrainedParseStatus::Null => continue,
            ConstrainedParseStatus::Ok => {}
        }

        if c.title_len != q.title_len {
            continue;
        }

        // All markers present on q must match c; year, edition, language, adult must match even when absent on q.
        for key in 0..CP_MARKER_COUNT {
            let q_val = q.marker_slice(key);
            let c_val = c.marker_slice(key);
            if matches!(
                key,
                CP_MARKER_YEAR | CP_MARKER_EDITION | CP_MARKER_LANGUAGE | CP_MARKER_ADULT
            ) {
                if c_val != q_val {
                    continue 'candidates;
                }
            } else if q_val.is_some() && c_val != q_val {
                continue 'candidates;
            }
        }

        if q.suffix_len > 0 {
            if c.suffix_len != q.suffix_len {
                continue;
            }
            for s_idx in 0..q.suffix_len {
                if q.suffix_token(s_idx) != c.suffix_token(s_idx) {
                    continue 'candidates;
                }
            }
        }

        let mut sum = 0.0f64;
        let mut exact = 0usize;
        let mut edits = 0usize;
        for t_idx in 0..q.title_len {
            let tok = q.title_token(t_idx);
            let cand_tok = c.title_token(t_idx);
            if tok == cand_tok {
                exact += 1;
                sum += 1.0;
                continue;
            }
            let min_len = tok.len().min(cand_tok.len());
            if min_len < 5
                || edits >= 2
                || tok.iter().all(|b| b.is_ascii_digit())
                || cand_tok.iter().all(|b| b.is_ascii_digit())
            {
                continue 'candidates;
            }
            let length = tok.len().max(cand_tok.len());
            let max_allowed = (if length >= 10 { 2usize } else { 1usize }).min(2 - edits);
            let dist = constrained_levenshtein_bounded(tok, cand_tok, max_allowed);
            if dist > max_allowed {
                continue 'candidates;
            }
            edits += dist;
            sum += 1.0 - (dist as f64) / (length as f64);
        }

        let score = 100.0 * sum / (q.title_len as f64);
        if exact < 1 || edits > 2 || score < 90.0 {
            continue;
        }

        let rounded_score = (score * 1000.0 + 0.5).floor() / 1000.0;
        let complete_match =
            c.marker_order_len == q.marker_order_len && c.suffix_len == q.suffix_len;

        let entry = RankedHit {
            id: hit_id,
            hit_idx: idx as u32,
            rounded_score,
            complete_match,
        };

        let mut existing_slot = None;
        for (slot_idx, existing) in ranked[..ranked_len].iter().enumerate() {
            if existing.id == hit_id {
                existing_slot = Some(slot_idx);
                break;
            }
        }
        if let Some(slot_idx) = existing_slot {
            ranked[slot_idx] = entry;
        } else {
            ranked[ranked_len] = entry;
            ranked_len += 1;
        }
    }

    if ranked_len == 0 {
        return 3;
    }

    ranked[..ranked_len].sort_by(|a, b| b.rounded_score.total_cmp(&a.rounded_score));

    if ranked_len >= 2 && ranked[0].rounded_score - ranked[1].rounded_score < 8.0 {
        unsafe {
            *out_f64.add(0) = f64::from(ranked[0].hit_idx);
            *out_f64.add(1) = f64::from(ranked[1].hit_idx);
            *out_f64.add(2) = ranked[0].rounded_score;
            *out_f64.add(3) = ranked[1].rounded_score;
        }
        return 4;
    }

    unsafe {
        *out_f64.add(0) = f64::from(ranked[0].hit_idx);
        *out_f64.add(2) = ranked[0].rounded_score;
    }
    if ranked[0].complete_match { 5 } else { 6 }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn render_verified_nzb(
        meta: &[u8],
        groups: usize,
        passwords: usize,
        inventory: i64,
        flags: u32,
        files: &[u8],
        file_count: usize,
        segment_count: usize,
    ) -> Result<Vec<u8>, isize> {
        let mut buf = vec![0u8; 4];
        loop {
            let n = unsafe {
                nntmux_verified_nzb_document(
                    meta.as_ptr(),
                    meta.len(),
                    groups,
                    passwords,
                    inventory,
                    flags,
                    files.as_ptr(),
                    files.len(),
                    file_count,
                    segment_count,
                    buf.as_mut_ptr(),
                    buf.len(),
                )
            };
            if n >= 0 {
                buf.truncate(n as usize);
                return Ok(buf);
            }
            if n == -1 {
                return Err(n);
            }
            buf = vec![0u8; (-n) as usize];
        }
    }

    #[test]
    fn test_verified_nzb_document_matches_xmlwriter_layout() {
        let doc = render_verified_nzb(
            b"Po\"ster <a&b>\x001700000000\0alt.bin\0a&b\0pw\r1\0",
            2,
            1,
            2,
            0,
            b"Sub\tj \"x\" (1/2)\x002\x001\x00100\x00<a@b>\x002\x0050\x00x&y\r\x00",
            1,
            2,
        )
        .unwrap();
        let expected = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<nzb xmlns=\"http://www.newzbin.com/DTD/2003/nzb\"><head><meta type=\"nntmux:verified-file-count\">2</meta><meta type=\"password\">pw&#13;1</meta></head><file poster=\"Po&quot;ster &lt;a&amp;b&gt;\" date=\"1700000000\" subject=\"Sub&#9;j &quot;x&quot; (1/2)\"><groups><group>alt.bin</group><group>a&amp;b</group></groups><segments><segment number=\"1\" bytes=\"100\">a@b</segment><segment number=\"2\" bytes=\"50\">x&amp;y&#13;</segment></segments></file></nzb>\n";
        assert_eq!(String::from_utf8(doc).unwrap(), expected);
    }

    #[test]
    fn test_verified_nzb_document_empty_and_spot_check() {
        let empty = render_verified_nzb(b"\0\0", 0, 0, 0, 0, b"", 0, 0).unwrap();
        assert_eq!(
            empty,
            b"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<nzb xmlns=\"http://www.newzbin.com/DTD/2003/nzb\"/>\n"
        );
        let spot = render_verified_nzb(b"\0\0", 0, 0, 3, 1, b"", 0, 0).unwrap();
        assert!(String::from_utf8(spot).unwrap().ends_with("<head><meta type=\"nntmux:declared-file-count\">3</meta><meta type=\"nntmux:verification-mode\">spot-check</meta></head></nzb>\n"));
    }

    #[test]
    fn test_verified_nzb_document_declines_control_bytes_and_bad_packing() {
        let render = |meta: &[u8], files: &[u8], segments: usize| {
            render_verified_nzb(meta, 0, 0, 0, 0, files, 1, segments)
        };
        assert_eq!(
            render(b"p\x01\0d\0", b"s\x001\x001\x002\x00m\x00", 1),
            Err(-1)
        );
        assert_eq!(
            render(b"p\0d\0", b"s\x001\x001\x002\x00m\xff\x00", 1),
            Err(-1)
        );
        // Embedded NUL inside a message-id shifts the token stream and is rejected.
        assert_eq!(
            render(b"p\0d\0", b"s\x001\x001\x002\x00m\x00x\x00", 1),
            Err(-1)
        );
        assert_eq!(render(b"p\0d\0", b"s\x001\x001\x002\x00m\x00", 2), Err(-1));
    }

    #[test]
    fn test_manticore_escape_string_matches_php() {
        let mut out = SmallBytes::new();
        manticore_escape_string_into(b"a--b-c!", &mut out);
        assert_eq!(out.as_slice(), b"a\\\\-\\-b\\-c\\");

        manticore_escape_string_into(b"a---b", &mut out);
        assert_eq!(out.as_slice(), b"a\\\\-\\-\\-b");
    }

    #[test]
    fn test_manticore_prepare_user_query_matches_php() {
        let mut out = SmallBytes::new();
        manticore_prepare_user_query_into(
            b"(matrix | \"reloaded-2\") -cam !ts spider-man *720p*",
            &mut out,
        );
        assert_eq!(
            out.as_slice(),
            b"(matrix | \"reloaded\\-2\" ) -cam !ts spider\\-man *720p*"
        );
    }

    #[test]
    fn test_search_sanitize_lucene_matches_php() {
        let input = b"movie.title +1080p -cam --double *wild* (test:1)";
        let mut buf = [0u8; 256];
        let n = unsafe {
            nntmux_search_sanitize_lucene(input.as_ptr(), input.len(), buf.as_mut_ptr(), buf.len())
        };
        assert!(n > 0);
        assert_eq!(
            &buf[..n as usize],
            b"movie title +1080p -cam \\-\\-double *wild* \\(test\\:1\\)"
        );
    }

    #[test]
    fn test_predb_validate_filename_candidate_rules() {
        let title = b"Canonical.Release-GRP";
        let fname = b"C:\\directory\\Alias.Release-GRP.rar";
        let src = b"srrdb";
        let id = unsafe {
            nntmux_predb_validate_filename_candidate(
                b"07".as_ptr(),
                2,
                0,
                0,
                title.as_ptr(),
                title.len(),
                fname.as_ptr(),
                fname.len(),
                src.as_ptr(),
                src.len(),
            )
        };
        assert_eq!(id, 7);

        // Forbidden title separator
        let bad_title = b"bad/title";
        let id_bad = unsafe {
            nntmux_predb_validate_filename_candidate(
                std::ptr::null(),
                0,
                7,
                1,
                bad_title.as_ptr(),
                bad_title.len(),
                std::ptr::null(),
                0,
                std::ptr::null(),
                0,
            )
        };
        assert_eq!(id_bad, 0);

        // Control character U+200B in filename
        let zwsp_fname = "/bad\u{200B}directory/Alias.rar".as_bytes();
        let id_zwsp = unsafe {
            nntmux_predb_validate_filename_candidate(
                std::ptr::null(),
                0,
                7,
                1,
                title.as_ptr(),
                title.len(),
                zwsp_fname.as_ptr(),
                zwsp_fname.len(),
                std::ptr::null(),
                0,
            )
        };
        assert_eq!(id_zwsp, 0);
    }

    #[test]
    fn test_prometheus_format_text_nul_and_escapes() {
        let input = b"nntmux_test\0Line one\nline \\ two\0gauge\02\01\0server\0news \"primary\"\\eu\nwest\01\01\0case\0fraction\00.250000\0";
        let mut buf = [0u8; 512];
        let n = unsafe {
            nntmux_prometheus_format_text(input.as_ptr(), input.len(), buf.as_mut_ptr(), buf.len())
        };
        assert!(n > 0);
        let expected = b"# HELP nntmux_test Line one\\nline \\\\ two\n# TYPE nntmux_test gauge\nnntmux_test{server=\"news \\\"primary\\\"\\\\eu\\nwest\"} 1\nnntmux_test{case=\"fraction\"} 0.25\n";
        assert_eq!(&buf[..n as usize], &expected[..]);
    }

    #[test]
    fn test_datetime_to_unix_utc_and_plain_search_name() {
        let dt = b"2026-01-02 03:04:05";
        let ts = unsafe { nntmux_datetime_to_unix_utc(dt.as_ptr(), dt.len()) };
        assert_eq!(ts, 1_767_323_045);

        let sn = b"..Release._-Name--2026_.";
        let mut buf = [0u8; 64];
        let n = unsafe {
            nntmux_release_plain_search_name(sn.as_ptr(), sn.len(), buf.as_mut_ptr(), buf.len())
        };
        assert_eq!(&buf[..n as usize], b"Release Name 2026");
    }

    #[test]
    fn test_constrained_predb_parse_and_suggest_batch() {
        let input = b"Kanata.Kara.S01E02.1080p.AMZN.WEB-DL.JPN.DDP2.0.H.264-ToonsHub.mkv";
        let mut buf = [0u8; 512];
        let n = unsafe {
            nntmux_constrained_predb_parse(input.as_ptr(), input.len(), buf.as_mut_ptr(), buf.len())
        };
        assert!(n > 0);
        assert_eq!(
            &buf[..n as usize],
            b"kanata kara\ngroup:toonshub\tepisode:s1e2\tresolution:1080p\namzn web dl jpn ddp2 0 h 264"
        );

        // Partial query suggests hit 0 with unresolved release variant (status 6)
        let q1 = b"kazata.kara.s1e2";
        let hits = b"1\0Kanata.Kara.S01E02.1080p.AMZN.WEB-DL.JPN.DDP2.0.H.264-ToonsHub";
        let mut out_f64 = [0.0f64; 4];
        let s1 = unsafe {
            nntmux_constrained_predb_suggest_batch(
                q1.as_ptr(),
                q1.len(),
                hits.as_ptr(),
                hits.len(),
                1,
                out_f64.as_mut_ptr(),
            )
        };
        assert_eq!(s1, 6);
        assert_eq!(out_f64[0] as usize, 0);
        assert!((out_f64[2] - 91.667).abs() < 1e-6);

        // Full query suggests hit 0 with complete evidence (status 5)
        let q2 = b"Kazata.Kara.S01E02.1080p.AMZN.WEB-DL.JPN.DDP2.0.H.264-ToonsHub";
        let s2 = unsafe {
            nntmux_constrained_predb_suggest_batch(
                q2.as_ptr(),
                q2.len(),
                hits.as_ptr(),
                hits.len(),
                1,
                out_f64.as_mut_ptr(),
            )
        };
        assert_eq!(s2, 5);
        assert_eq!(out_f64[0] as usize, 0);
    }

    #[test]
    fn test_api_canonicalize_list_csv_and_search_normalizers() {
        let mut buf = [0u8; 64];
        let csv = b" 2000 , \t\n\r\0\x0B , 5030 , 0 , ";
        let n = unsafe {
            nntmux_api_canonicalize_list_csv(csv.as_ptr(), csv.len(), buf.as_mut_ptr(), buf.len())
        };
        assert_eq!(n, 11);
        assert_eq!(&buf[..n as usize], b"2000,5030,0");

        let empty_csv = b" , \t\n , ";
        let n_empty = unsafe {
            nntmux_api_canonicalize_list_csv(
                empty_csv.as_ptr(),
                empty_csv.len(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(n_empty, 0);

        let neg_one = b"  -1 \n";
        assert_eq!(
            unsafe {
                nntmux_search_normalize_media_unique_id(
                    neg_one.as_ptr(),
                    neg_one.len(),
                    buf.as_mut_ptr(),
                    buf.len(),
                )
            },
            0
        );
        let valid_uid = b" \t1234567890\r\n ";
        let n_uid = unsafe {
            nntmux_search_normalize_media_unique_id(
                valid_uid.as_ptr(),
                valid_uid.len(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(n_uid, 10);
        assert_eq!(&buf[..10], b"1234567890");

        let mut val: i64 = -1;
        for (raw, expected) in [
            (b"0" as &[u8], Some(0i64)),
            (b"-0", Some(0)),
            (b"+0", Some(0)),
            (b" 42\n", Some(42)),
            (b"+9223372036854775807", Some(i64::MAX)),
            (b"00", None),
            (b"01", None),
            (b"-1", None),
            (b"42\0", None),
            (b"\x0c42", None),
            (b"9223372036854775808", None),
        ] {
            let rc = unsafe {
                nntmux_search_normalize_range_bound(raw.as_ptr(), raw.len(), &mut val)
            };
            match expected {
                Some(exp) => {
                    assert_eq!(rc, 1, "expected valid for {:?}", raw);
                    assert_eq!(val, exp);
                }
                None => assert_eq!(rc, 0, "expected invalid for {:?}", raw),
            }
        }
    }

    #[test]
    fn test_rfc2822_and_release_item_rss_dates() {
        let mut buf = [0u8; 128];
        let n = unsafe { nntmux_rfc2822_from_unix_utc(1_767_323_045, buf.as_mut_ptr(), buf.len()) };
        assert_eq!(n, 31);
        assert_eq!(&buf[..31], b"Fri, 02 Jan 2026 03:04:05 +0000");

        let add = b"2026-01-02 03:04:05";
        let post = b"2024-02-29 23:59:59";
        let n2 = unsafe {
            nntmux_release_item_rss_dates(
                add.as_ptr(),
                add.len(),
                post.as_ptr(),
                post.len(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(n2, 63);
        assert_eq!(
            &buf[..63],
            b"Fri, 02 Jan 2026 03:04:05 +0000\nThu, 29 Feb 2024 23:59:59 +0000"
        );
    }
}
