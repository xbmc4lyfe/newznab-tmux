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

// ---------------------------------------------------------------------------
// Wave 9 Slice 9.6: API XML CDATA, ReleaseSearchService SQL Builders, and
// ManticoreSchemaInspector FFI Exports
// ---------------------------------------------------------------------------

#[inline]
fn push_u64_decimal(cur: &mut OutCursor, mut n: u64) {
    if n == 0 {
        cur.push_byte(b'0');
        return;
    }
    let mut buf = [0u8; 20];
    let mut i = 20usize;
    while n > 0 {
        i -= 1;
        buf[i] = b'0' + (n % 10) as u8;
        n /= 10;
    }
    cur.push_slice(&buf[i..]);
}

#[inline]
fn push_i64_decimal(cur: &mut OutCursor, n: i64) {
    if n < 0 {
        cur.push_byte(b'-');
        push_u64_decimal(cur, n.unsigned_abs());
    } else {
        push_u64_decimal(cur, n as u64);
    }
}

#[inline]
fn gmdate_ymd_hms_bytes(ts: i64) -> Option<[u8; 19]> {
    if !(0..=MAX_RFC2822_TS_UTC).contains(&ts) {
        return None;
    }
    let days = ts / 86_400;
    let sod = (ts % 86_400) as u32;
    let (year, month, day) = civil_from_days(days);
    if !(0..=9999).contains(&year) {
        return None;
    }
    let y = year as u32;
    let hour = sod / 3_600;
    let rem = sod % 3_600;
    let min = rem / 60;
    let sec = rem % 60;
    Some([
        b'0' + ((y / 1000) % 10) as u8,
        b'0' + ((y / 100) % 10) as u8,
        b'0' + ((y / 10) % 10) as u8,
        b'0' + (y % 10) as u8,
        b'-',
        b'0' + (month / 10) as u8,
        b'0' + (month % 10) as u8,
        b'-',
        b'0' + (day / 10) as u8,
        b'0' + (day % 10) as u8,
        b' ',
        b'0' + (hour / 10) as u8,
        b'0' + (hour % 10) as u8,
        b':',
        b'0' + (min / 10) as u8,
        b'0' + (min % 10) as u8,
        b':',
        b'0' + (sec / 10) as u8,
        b'0' + (sec % 10) as u8,
    ])
}

/// Parses a PHP `(int) $string` cast for normal integer strings, returning `None` when
/// scientific/float suffixes or `> 18` significant digits require declining to PHP.
#[inline]
fn php_cast_str_to_i64(s: &[u8]) -> Option<i64> {
    let mut i = 0usize;
    while i < s.len() && is_ascii_ws(s[i]) {
        i += 1;
    }
    if i >= s.len() {
        return Some(0);
    }
    let mut neg = false;
    if s[i] == b'-' {
        neg = true;
        i += 1;
    } else if s[i] == b'+' {
        i += 1;
    }
    if i >= s.len() || !s[i].is_ascii_digit() {
        return Some(0);
    }
    while i < s.len() && s[i] == b'0' {
        i += 1;
    }
    let start = i;
    while i < s.len() && s[i].is_ascii_digit() {
        i += 1;
    }
    if i < s.len() && matches!(s[i], b'.' | b'e' | b'E') {
        return None;
    }
    let digits = &s[start..i];
    if digits.is_empty() {
        return Some(0);
    }
    if digits.len() > 18 {
        return None;
    }
    let mut acc: i64 = 0;
    for &b in digits {
        acc = acc * 10 + i64::from(b - b'0');
    }
    Some(if neg { -acc } else { acc })
}

#[inline]
fn size_bucket_bytes(bucket: i64) -> i64 {
    const MULTIPLIERS: [i64; 12] = [0, 1, 2, 5, 10, 20, 30, 40, 80, 160, 320, 640];
    if (1..=11).contains(&bucket) {
        104_857_600 * MULTIPLIERS[bucket as usize]
    } else {
        0
    }
}

#[inline]
fn ascii_contains_ignore_case_any(haystack: &[u8], needle: &[u8]) -> bool {
    if needle.is_empty() {
        return true;
    }
    if needle.len() > haystack.len() {
        return false;
    }
    let first = needle[0].to_ascii_lowercase();
    let max_i = haystack.len() - needle.len();
    for i in 0..=max_i {
        if haystack[i].to_ascii_lowercase() == first
            && haystack[i..i + needle.len()].eq_ignore_ascii_case(needle)
        {
            return true;
        }
    }
    false
}

#[inline]
fn is_mysql_phrase_trim_or_sep(b: u8) -> bool {
    matches!(
        b,
        b' ' | b'\t' | b'\n' | b'\r' | 0x00 | 0x0B | 0x0C | b'.' | b'-' | b'_'
    )
}

const MYSQL_PHRASE_TABLE: [u8; 256] = {
    let mut table = [0u8; 256];
    let mut i = 0usize;
    while i < 256 {
        let b = i as u8;
        table[i] = match b {
            b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C | b'.' | b'-' | b'_' => b' ',
            b'A'..=b'Z' => b + 32,
            _ => b,
        };
        i += 1;
    }
    table
};

/// Accelerates `XML_Response::buildCdata` across `pair_count` NUL-separated `(col, value)` pairs.
/// Returns `>= 0` written bytes, `-1` on null/malformed packing, `-2` when `mu_releasedate`
/// requires PHP `strtotime`/`date` fallback, or `< -2` when `out_cap` is too small.
///
/// # Safety
///
/// `pairs_nul` must be valid for reads of `pairs_len` bytes when non-null and `pairs_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_api_build_cdata(
    pairs_nul: *const u8,
    pairs_len: usize,
    pair_count: usize,
    tz_is_utc: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if pairs_len > 0 && pairs_nul.is_null() {
        return -1;
    }
    if pair_count == 0 {
        return if pairs_len == 0 { 0 } else { -1 };
    }
    if pairs_nul.is_null() {
        return -1;
    }
    let slice = if pairs_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(pairs_nul, pairs_len) }
    };
    let mut iter = slice.split(|&b| b == 0);
    let mut cur = OutCursor::new(out_buf, out_cap);
    for _ in 0..pair_count {
        let Some(col) = iter.next() else {
            return -1;
        };
        let Some(val) = iter.next() else {
            return -1;
        };
        if col.is_empty() || val.is_empty() || val == b"0" {
            continue;
        }
        if col == b"mu_releasedate" {
            if tz_is_utc == 0 {
                return -2;
            }
            let trimmed = trim_php_ws(val);
            if trimmed.len() < 10 {
                return -2;
            }
            let ts = unsafe { nntmux_datetime_to_unix_utc(val.as_ptr(), val.len()) };
            if ts == i64::MIN {
                return -2;
            }
            cur.push_slice(b"<li>Released: ");
            cur.push_slice(&trimmed[..10]);
            cur.push_slice(b"</li>\n");
        } else {
            let stripped = if col.len() >= 3
                && col[0].is_ascii_alphabetic()
                && col[1].is_ascii_alphabetic()
                && col[2] == b'_'
            {
                &col[3..]
            } else {
                col
            };
            cur.push_slice(b"<li>");
            if let Some((&first, tail)) = stripped.split_first() {
                cur.push_byte(first.to_ascii_uppercase());
                cur.push_slice(tail);
            }
            cur.push_slice(b": ");
            cur.push_slice(val);
            cur.push_slice(b"</li>\n");
        }
    }
    if iter.next().is_some() {
        return -1;
    }
    cur.finish()
}

/// Accelerates `ReleaseSearchService::normalizeMysqlFallbackPhrase(string $value)`.
///
/// # Safety
///
/// `input_ptr` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_normalize_mysql_fallback_phrase(
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
    let mut slice = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    while let Some(&b) = slice.first() {
        if is_mysql_phrase_trim_or_sep(b) {
            slice = &slice[1..];
        } else {
            break;
        }
    }
    while let Some(&b) = slice.last() {
        if is_mysql_phrase_trim_or_sep(b) {
            slice = &slice[..slice.len() - 1];
        } else {
            break;
        }
    }
    if slice.is_empty() {
        return 0;
    }
    if !out_buf.is_null() && out_cap >= slice.len() {
        let mut out_len = 0usize;
        let mut prev_space = false;
        for &b in slice {
            let mapped = MYSQL_PHRASE_TABLE[b as usize];
            if mapped == b' ' {
                if !prev_space {
                    unsafe {
                        *out_buf.add(out_len) = b' ';
                    }
                    out_len += 1;
                    prev_space = true;
                }
            } else {
                unsafe {
                    *out_buf.add(out_len) = mapped;
                }
                out_len += 1;
                prev_space = false;
            }
        }
        return out_len as isize;
    }
    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut prev_space = false;
    for &b in slice {
        let mapped = MYSQL_PHRASE_TABLE[b as usize];
        if mapped == b' ' {
            if !prev_space {
                cur.push_byte(b' ');
                prev_space = true;
            }
        } else {
            cur.push_byte(mapped);
            prev_space = false;
        }
    }
    cur.finish()
}

/// Accelerates `ReleaseSearchService::buildRangeSqlConditions(array $ranges)`.
/// Each bound is `< 0` when omitted, or `>= 0` when active.
/// Writes `\n`-separated SQL condition strings to `out_buf`.
///
/// # Safety
///
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn nntmux_search_build_range_sql_conditions(
    size_min: i64,
    size_max: i64,
    totalpart_min: i64,
    totalpart_max: i64,
    grabs_min: i64,
    grabs_max: i64,
    comments_min: i64,
    comments_max: i64,
    adddate_min: i64,
    adddate_max: i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if adddate_min > MAX_RFC2822_TS_UTC || adddate_max > MAX_RFC2822_TS_UTC {
        return -2;
    }
    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut first = true;
    let scalar_bounds: [(&[u8], i64); 8] = [
        (b"r.size >= ", size_min),
        (b"r.size <= ", size_max),
        (b"r.totalpart >= ", totalpart_min),
        (b"r.totalpart <= ", totalpart_max),
        (b"r.grabs >= ", grabs_min),
        (b"r.grabs <= ", grabs_max),
        (b"r.comments >= ", comments_min),
        (b"r.comments <= ", comments_max),
    ];
    for &(prefix, val) in &scalar_bounds {
        if val >= 0 {
            if !first {
                cur.push_byte(b'\n');
            }
            first = false;
            cur.push_slice(prefix);
            push_u64_decimal(&mut cur, val as u64);
        }
    }
    for (prefix, val) in [
        (&b"r.adddate >= '"[..], adddate_min),
        (&b"r.adddate <= '"[..], adddate_max),
    ] {
        if val >= 0 {
            let Some(dt) = gmdate_ymd_hms_bytes(val) else {
                return -2;
            };
            if !first {
                cur.push_byte(b'\n');
            }
            first = false;
            cur.push_slice(prefix);
            cur.push_slice(&dt);
            cur.push_byte(b'\'');
        }
    }
    cur.finish()
}

/// Accelerates `ReleaseSearchService::buildEpisodeJoinPredicates(string $series, string $episode, string $airDate)`.
/// Writes `\n`-separated SQL predicates to `out_buf`, or returns `-2` when `escapeString($airDate)`
/// or float-like `(int)` coercion requires PHP fallback.
///
/// # Safety
///
/// `series_ptr`, `episode_ptr`, and `airdate_ptr` must be valid for reads of their lengths when length > 0.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn nntmux_search_build_episode_join_predicates(
    series_ptr: *const u8,
    series_len: usize,
    episode_ptr: *const u8,
    episode_len: usize,
    airdate_ptr: *const u8,
    airdate_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if (series_len > 0 && series_ptr.is_null())
        || (episode_len > 0 && episode_ptr.is_null())
        || (airdate_len > 0 && airdate_ptr.is_null())
    {
        return -1;
    }
    let series = if series_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(series_ptr, series_len) }
    };
    let episode = if episode_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(episode_ptr, episode_len) }
    };
    let airdate = if airdate_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(airdate_ptr, airdate_len) }
    };

    let mut cur = OutCursor::new(out_buf, out_cap);
    if !series.is_empty() && series != b"0" {
        let Some(series_cast) = php_cast_str_to_i64(series) else {
            return -2;
        };
        if series_cast < 1900 {
            let stripped_series = if matches!(series.first(), Some(b's' | b'S')) {
                let mut idx = 1usize;
                while idx < series.len() && series[idx] == b'0' {
                    idx += 1;
                }
                &series[idx..]
            } else {
                series
            };
            let Some(series_num) = php_cast_str_to_i64(stripped_series) else {
                return -2;
            };
            cur.push_slice(b"tve.series = ");
            push_i64_decimal(&mut cur, series_num);

            if !episode.is_empty() && episode != b"0" && !episode.contains(&b'/') {
                let stripped_ep = if matches!(episode.first(), Some(b'e' | b'E')) {
                    let mut idx = 1usize;
                    while idx < episode.len() && episode[idx] == b'0' {
                        idx += 1;
                    }
                    &episode[idx..]
                } else {
                    episode
                };
                let Some(ep_num) = php_cast_str_to_i64(stripped_ep) else {
                    return -2;
                };
                cur.push_slice(b"\ntve.episode = ");
                push_i64_decimal(&mut cur, ep_num);
            }
            return cur.finish();
        }
    }

    if !airdate.is_empty() && airdate != b"0" {
        if !airdate
            .iter()
            .all(|&b| b.is_ascii_alphanumeric() || matches!(b, b'-' | b'/' | b'.' | b'_' | b':' | b' '))
        {
            return -2;
        }
        cur.push_slice(b"DATE(tve.firstaired) = '");
        cur.push_slice(airdate);
        cur.push_byte(b'\'');
        return cur.finish();
    }

    0
}

/// Accelerates `ReleaseSearchService::resolveSizeRangeBounds(mixed $sizeFrom, mixed $sizeTo)`.
/// Writes `[size_min_bytes, size_max_bytes]` to `out_bounds2` and returns `0` (or `-1` on null `out_bounds2`).
///
/// # Safety
///
/// `out_bounds2` must be valid for writes of 2 `i64` values.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_resolve_size_range_bounds(
    size_from: i64,
    size_to: i64,
    out_bounds2: *mut i64,
) -> i32 {
    if out_bounds2.is_null() {
        return -1;
    }
    unsafe {
        *out_bounds2.add(0) = size_bucket_bytes(size_from);
        *out_bounds2.add(1) = size_bucket_bytes(size_to);
    }
    0
}

/// Accelerates `ManticoreSchemaInspector::compareColumns(array $actual, array $expected)`.
/// `actual_nul` contains `actual_count` NUL-separated `(name, type)` pairs.
/// `expected_nul` contains `expected_count` NUL-separated `(name, expected_type)` pairs.
/// Writes `\n`-separated lines (`"M\t{name}"` or `"I\t{name}\t{expected_low}\t{actual_low}"`) to `out_buf`.
///
/// # Safety
///
/// `actual_nul` and `expected_nul` must be valid for reads of their lengths when length > 0.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn nntmux_manticore_schema_compare_columns_nul(
    actual_nul: *const u8,
    actual_len: usize,
    actual_count: usize,
    expected_nul: *const u8,
    expected_len: usize,
    expected_count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if (actual_len > 0 && actual_nul.is_null()) || (expected_len > 0 && expected_nul.is_null()) {
        return -1;
    }
    let act_slice = if actual_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(actual_nul, actual_len) }
    };
    let exp_slice = if expected_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(expected_nul, expected_len) }
    };

    let mut stack_pairs: [(&[u8], &[u8]); 64] = [(&[], &[]); 64];
    let mut stack_len = 0usize;
    let mut heap_pairs: Option<Vec<(&[u8], &[u8])>> = None;

    if actual_count == 0 {
        if !act_slice.is_empty() {
            return -1;
        }
    } else {
        let mut act_iter = act_slice.split(|&b| b == 0);
        for _ in 0..actual_count {
            let Some(name) = act_iter.next() else {
                return -1;
            };
            let Some(ty) = act_iter.next() else {
                return -1;
            };
            if !name.is_empty() {
                if let Some(ref mut v) = heap_pairs {
                    v.push((name, ty));
                } else if stack_len < 64 {
                    stack_pairs[stack_len] = (name, ty);
                    stack_len += 1;
                } else {
                    let mut v = Vec::with_capacity(actual_count);
                    v.extend_from_slice(&stack_pairs[..stack_len]);
                    v.push((name, ty));
                    heap_pairs = Some(v);
                }
            }
        }
        if act_iter.next().is_some() {
            return -1;
        }
    }

    if expected_count == 0 {
        return if exp_slice.is_empty() { 0 } else { -1 };
    }

    let act_pairs: &[(&[u8], &[u8])] = match heap_pairs {
        Some(ref v) => v.as_slice(),
        None => &stack_pairs[..stack_len],
    };

    let mut exp_iter = exp_slice.split(|&b| b == 0);
    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut first_line = true;

    for _ in 0..expected_count {
        let Some(name) = exp_iter.next() else {
            return -1;
        };
        let Some(exp_type_raw) = exp_iter.next() else {
            return -1;
        };
        if name.contains(&b'\n')
            || name.contains(&b'\t')
            || exp_type_raw.contains(&b'\n')
            || exp_type_raw.contains(&b'\t')
        {
            return -2;
        }

        if let Some(&(_, act_type_raw)) = act_pairs.iter().rev().find(|&&(n, _)| n == name) {
            if act_type_raw.contains(&b'\n') || act_type_raw.contains(&b'\t') {
                return -2;
            }
            let compatible = if exp_type_raw.eq_ignore_ascii_case(b"integer") {
                ascii_contains_ignore_case_any(act_type_raw, b"integer")
                    || ascii_contains_ignore_case_any(act_type_raw, b"uint")
            } else {
                ascii_contains_ignore_case_any(act_type_raw, exp_type_raw)
            };
            if !compatible {
                if !first_line {
                    cur.push_byte(b'\n');
                }
                first_line = false;
                cur.push_slice(b"I\t");
                cur.push_slice(name);
                cur.push_byte(b'\t');
                for &b in exp_type_raw {
                    cur.push_byte(b.to_ascii_lowercase());
                }
                cur.push_byte(b'\t');
                for &b in act_type_raw {
                    cur.push_byte(b.to_ascii_lowercase());
                }
            }
        } else {
            if !first_line {
                cur.push_byte(b'\n');
            }
            first_line = false;
            cur.push_slice(b"M\t");
            cur.push_slice(name);
        }
    }
    if exp_iter.next().is_some() {
        return -1;
    }
    cur.finish()
}

struct SettingsSliceMap<'a> {
    stack: [(&'a [u8], &'a [u8]); 12],
    len: usize,
    heap: Option<Vec<(&'a [u8], &'a [u8])>>,
}

impl<'a> SettingsSliceMap<'a> {
    #[inline]
    fn new() -> Self {
        Self {
            stack: [(&[], &[]); 12],
            len: 0,
            heap: None,
        }
    }

    #[inline]
    fn push(&mut self, k: &'a [u8], v: &'a [u8]) {
        if let Some(ref mut h) = self.heap {
            h.push((k, v));
        } else if self.len < 12 {
            self.stack[self.len] = (k, v);
            self.len += 1;
        } else {
            let mut h = Vec::with_capacity(32);
            h.extend_from_slice(&self.stack[..self.len]);
            h.push((k, v));
            self.heap = Some(h);
        }
    }

    #[inline]
    fn as_slice(&self) -> &[(&'a [u8], &'a [u8])] {
        match self.heap {
            Some(ref h) => h.as_slice(),
            None => &self.stack[..self.len],
        }
    }
}

fn parse_manticore_settings_blob_into<'a>(
    blob: &'a [u8],
    settings: &mut SettingsSliceMap<'a>,
) -> bool {
    if blob.iter().any(|&b| b >= 0x80) {
        return false;
    }
    let mut parse_line = |line: &'a [u8]| {
        let mut i = 0usize;
        while i < line.len() && is_ascii_ws(line[i]) {
            i += 1;
        }
        let key_start = i;
        while i < line.len() && (line[i].is_ascii_alphanumeric() || line[i] == b'_') {
            i += 1;
        }
        let key_slice = &line[key_start..i];
        if key_slice.is_empty() {
            return;
        }
        while i < line.len() && is_ascii_ws(line[i]) {
            i += 1;
        }
        if i >= line.len() || line[i] != b'=' {
            return;
        }
        i += 1;
        while i < line.len() && is_ascii_ws(line[i]) {
            i += 1;
        }
        let mut rest = &line[i..];
        while let Some(&b) = rest.last() {
            if is_ascii_ws(b) {
                rest = &rest[..rest.len() - 1];
            } else {
                break;
            }
        }
        if rest.first().is_some_and(|&b| b == b'\'' || b == b'"') {
            rest = &rest[1..];
        }
        if rest.last().is_some_and(|&b| b == b'\'' || b == b'"') {
            rest = &rest[..rest.len() - 1];
        }
        if rest.is_empty() || rest.iter().any(|&b| b == b'\'' || b == b'"') {
            return;
        }
        let trimmed_val = trim_php_default(rest);
        settings.push(key_slice, trimmed_val);
    };

    let mut start = 0usize;
    let mut i = 0usize;
    while i < blob.len() {
        let b = blob[i];
        if b == b'\r' {
            parse_line(&blob[start..i]);
            if i + 1 < blob.len() && blob[i + 1] == b'\n' {
                i += 2;
            } else {
                i += 1;
            }
            start = i;
        } else if matches!(b, b'\n' | 0x0B | 0x0C) {
            parse_line(&blob[start..i]);
            i += 1;
            start = i;
        } else {
            i += 1;
        }
    }
    parse_line(&blob[start..]);
    true
}

/// Accelerates `ManticoreSchemaInspector::missingSettings(mixed $actual, array $expected)`.
/// `actual_nul` contains `actual_count` NUL-separated `(key_or_blob_marker, value)` pairs,
/// where `key == "\x01"` denotes a bundled multi-line `settings` string.
/// `expected_nul` contains `expected_count` NUL-separated `(setting_name, expected_value)` pairs.
/// Writes `\n`-separated missing setting names to `out_buf`.
///
/// # Safety
///
/// `actual_nul` and `expected_nul` must be valid for reads of their lengths when length > 0.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn nntmux_manticore_schema_missing_settings_nul(
    actual_nul: *const u8,
    actual_len: usize,
    actual_count: usize,
    expected_nul: *const u8,
    expected_len: usize,
    expected_count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if (actual_len > 0 && actual_nul.is_null()) || (expected_len > 0 && expected_nul.is_null()) {
        return -1;
    }
    let act_slice = if actual_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(actual_nul, actual_len) }
    };
    let exp_slice = if expected_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(expected_nul, expected_len) }
    };

    let mut settings = SettingsSliceMap::new();
    if actual_count == 0 {
        if !act_slice.is_empty() {
            return -1;
        }
    } else if actual_count == 0xFFFF_FFFF {
        if !parse_manticore_settings_blob_into(act_slice, &mut settings) {
            return -2;
        }
    } else {
        let mut act_iter = act_slice.split(|&b| b == 0);
        for _ in 0..actual_count {
            let Some(k) = act_iter.next() else {
                return -1;
            };
            let Some(v) = act_iter.next() else {
                return -1;
            };
            if k == b"\x01" {
                if !parse_manticore_settings_blob_into(v, &mut settings) {
                    return -2;
                }
            } else {
                settings.push(k, v);
            }
        }
        if act_iter.next().is_some() {
            return -1;
        }
    }

    if expected_count == 0 {
        return if exp_slice.is_empty() { 0 } else { -1 };
    }

    let settings_slice = settings.as_slice();
    let mut exp_iter = exp_slice.split(|&b| b == 0);
    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut first_line = true;

    for _ in 0..expected_count {
        let Some(setting) = exp_iter.next() else {
            return -1;
        };
        let Some(exp_val) = exp_iter.next() else {
            return -1;
        };
        if setting.is_empty() || setting.contains(&b'\n') {
            return -2;
        }
        let matches_expected = settings_slice
            .iter()
            .rev()
            .find(|&&(k, _)| k.eq_ignore_ascii_case(setting))
            .is_some_and(|&(_, v)| v.eq_ignore_ascii_case(exp_val));
        if !matches_expected {
            if !first_line {
                cur.push_byte(b'\n');
            }
            first_line = false;
            cur.push_slice(setting);
        }
    }
    if exp_iter.next().is_some() {
        return -1;
    }
    cur.finish()
}

// ---------------------------------------------------------------------------
// Wave 10 Worker 5: API v2 RSS Date Batch, SecondaryIndexDocuments Timestamp,
// Manticore Fuzzy Phrase Normalizer, and Elasticsearch Escape String FFI
// ---------------------------------------------------------------------------

#[inline]
fn parse_utc_datetime_for_rss(raw: &[u8]) -> Option<i64> {
    if raw.contains(&0) {
        return None;
    }
    let s = trim_php_ws(raw);
    if s.len() < 10 || s[4] != b'-' || s[7] != b'-' {
        return None;
    }
    let year = parse_4digits(&s[0..4])?;
    let month = parse_2digits(&s[5..7])?;
    let day = parse_2digits(&s[8..10])?;
    if !(1902..=9999).contains(&year)
        || !(1..=12).contains(&month)
        || day == 0
        || day > days_in_month(year, month)
    {
        return None;
    }

    let (hour, min, sec) = if s.len() == 10 {
        (0u32, 0u32, 0u32)
    } else {
        if s.len() < 19 || !matches!(s[10], b' ' | b'T' | b't') || s[13] != b':' || s[16] != b':' {
            return None;
        }
        let h = parse_2digits(&s[11..13])?;
        let m = parse_2digits(&s[14..16])?;
        let sc = parse_2digits(&s[17..19])?;
        if h > 23 || m > 59 || sc > 59 {
            return None;
        }
        let mut rest = &s[19..];
        if rest.first() == Some(&b'.') {
            let frac_end = rest[1..]
                .iter()
                .position(|b| !b.is_ascii_digit())
                .map_or(rest.len(), |p| 1 + p);
            let frac_digits = frac_end - 1;
            if !(1..=6).contains(&frac_digits) {
                return None;
            }
            rest = &rest[frac_end..];
        }
        if !matches!(
            rest,
            b"" | b"Z" | b"z" | b"+00:00" | b"-00:00" | b"+0000" | b"-0000"
        ) {
            return None;
        }
        (h, m, sc)
    };

    Some(
        days_from_civil(year, month, day) * 86_400
            + i64::from(hour) * 3_600
            + i64::from(min) * 60
            + i64::from(sec),
    )
}

/// Formats 1, 2, or 3 UTC datetime strings (`d1`, `d2`, `d3`) into `\n`-separated 31-byte
/// `DATE_RSS` / `DATE_RFC2822` strings (`31`, `63`, or `95` bytes) for `ReleaseData`,
/// `DetailsData`, and `ApiV2Presenter`.
/// Returns `31` / `63` / `95` on success, `-1` on invalid count or null pointer,
/// `-2` when declined to PHP `Carbon::parse` (non-UTC timezone or non-standard date string),
/// or `-(required)` when `out_cap` is too small.
///
/// # Safety
///
/// Each active pointer (`1..=count`) with non-zero length must be valid for reads of its length.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn nntmux_api_v2_rss_dates_batch(
    d1_ptr: *const u8,
    d1_len: usize,
    d2_ptr: *const u8,
    d2_len: usize,
    d3_ptr: *const u8,
    d3_len: usize,
    count: usize,
    tz_is_utc: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if !(1..=3).contains(&count) {
        return -1;
    }
    if (d1_len > 0 && d1_ptr.is_null())
        || (count >= 2 && d2_len > 0 && d2_ptr.is_null())
        || (count >= 3 && d3_len > 0 && d3_ptr.is_null())
    {
        return -1;
    }
    if tz_is_utc == 0 {
        return -2;
    }
    if d1_len == 0 || (count >= 2 && d2_len == 0) || (count >= 3 && d3_len == 0) {
        return -2;
    }

    let s1 = unsafe { slice::from_raw_parts(d1_ptr, d1_len) };
    let Some(ts1) = parse_utc_datetime_for_rss(s1) else {
        return -2;
    };
    let Some(rss1) = rfc2822_from_unix_utc_bytes(ts1) else {
        return -2;
    };

    let mut cur = OutCursor::new(out_buf, out_cap);
    cur.push_slice(&rss1);
    if count == 1 {
        return cur.finish();
    }

    let s2 = unsafe { slice::from_raw_parts(d2_ptr, d2_len) };
    let rss2 = if s2 == s1 {
        rss1
    } else {
        let Some(ts2) = parse_utc_datetime_for_rss(s2) else {
            return -2;
        };
        let Some(r2) = rfc2822_from_unix_utc_bytes(ts2) else {
            return -2;
        };
        r2
    };
    cur.push_byte(b'\n');
    cur.push_slice(&rss2);
    if count == 2 {
        return cur.finish();
    }

    let s3 = unsafe { slice::from_raw_parts(d3_ptr, d3_len) };
    let rss3 = if s3 == s1 {
        rss1
    } else if s3 == s2 {
        rss2
    } else {
        let Some(ts3) = parse_utc_datetime_for_rss(s3) else {
            return -2;
        };
        let Some(r3) = rfc2822_from_unix_utc_bytes(ts3) else {
            return -2;
        };
        r3
    };
    cur.push_byte(b'\n');
    cur.push_slice(&rss3);
    cur.finish()
}

/// Accelerates `SecondaryIndexDocuments::releasedateToTimestamp(mixed $value): int`.
/// Returns `0` for empty input, a Unix timestamp (`i64`) for standard UTC date/datetime strings,
/// or `i64::MIN` when declined to PHP `Carbon::parse` (non-UTC timezone, null pointer, or non-standard string).
///
/// # Safety
///
/// `input_ptr` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_secondary_index_releasedate_to_ts(
    input_ptr: *const u8,
    input_len: usize,
    tz_is_utc: u32,
) -> i64 {
    if input_len == 0 {
        return 0;
    }
    if input_ptr.is_null() || tz_is_utc == 0 {
        return i64::MIN;
    }
    let slice = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    parse_utc_datetime_for_rss(slice).unwrap_or(i64::MIN)
}

#[inline]
fn is_fuzzy_word_or_ff(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_' || b == 0x0C
}

#[inline]
fn fuzzy_clean_segment_into(seg: &[u8], cur: &mut OutCursor, has_any: &mut bool) {
    if seg.is_empty() || seg == b"0" {
        return;
    }
    let mut start = 0usize;
    while start < seg.len() && !is_fuzzy_word_or_ff(seg[start]) {
        start += 1;
    }
    if start >= seg.len() {
        return;
    }
    let mut end = seg.len();
    while end > start && !is_fuzzy_word_or_ff(seg[end - 1]) {
        end -= 1;
    }
    if *has_any {
        cur.push_byte(b' ');
    }
    *has_any = true;
    let mut in_ws = false;
    for &b in &seg[start..end] {
        if b.is_ascii_alphanumeric() || b == b'_' {
            cur.push_byte(b);
            in_ws = false;
        } else if !in_ws {
            cur.push_byte(b' ');
            in_ws = true;
        }
    }
}

/// Accelerates `ManticoreSearchDriver::phrasesToFuzzyString(array|string $phrases): string`.
/// When `segment_count == 1`, `input_ptr[..input_len]` is cleaned as a single phrase (preserving
/// internal NUL byte handling as `[^\w\s]`). When `segment_count > 1`, `input_ptr[..input_len]`
/// is split into `segment_count` NUL-separated phrase segments.
///
/// # Safety
///
/// `input_ptr` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_manticore_phrases_to_fuzzy(
    input_ptr: *const u8,
    input_len: usize,
    segment_count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input_len > 0 && input_ptr.is_null() {
        return -1;
    }
    if segment_count == 0 {
        return if input_len == 0 { 0 } else { -1 };
    }
    if input_len == 0 {
        return if segment_count == 1 { 0 } else { -1 };
    }
    let slice = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut has_any = false;
    if segment_count == 1 {
        fuzzy_clean_segment_into(slice, &mut cur, &mut has_any);
        return cur.finish();
    }
    let mut iter = slice.split(|&b| b == 0);
    for _ in 0..segment_count {
        let Some(seg) = iter.next() else {
            return -1;
        };
        fuzzy_clean_segment_into(seg, &mut cur, &mut has_any);
    }
    if iter.next().is_some() {
        return -1;
    }
    cur.finish()
}

/// Accelerates `ElasticSearchDriver::escapeString(string $string): string`.
///
/// # Safety
///
/// `input_ptr` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_elastic_escape_string(
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
    if slice == b"0" || slice == b"*" {
        return 0;
    }
    let mut start = 0usize;
    while start < slice.len()
        && matches!(
            slice[start],
            b'.' | b' ' | b'\t' | b'\n' | b'\r' | 0x00 | 0x0B
        )
    {
        start += 1;
    }
    if start >= slice.len() {
        return 0;
    }
    let mut end = slice.len();
    while end > start
        && matches!(
            slice[end - 1],
            b'.' | b' ' | b'\t' | b'\n' | b'\r' | 0x00 | 0x0B
        )
    {
        end -= 1;
    }
    let trimmed = &slice[start..end];
    if !out_buf.is_null() && out_cap >= trimmed.len() {
        let mut out_len = 0usize;
        let mut in_ws = false;
        for &b in trimmed {
            if matches!(b, b'.' | b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C) {
                if !in_ws {
                    unsafe {
                        *out_buf.add(out_len) = b' ';
                    }
                    out_len += 1;
                    in_ws = true;
                }
            } else {
                unsafe {
                    *out_buf.add(out_len) = b;
                }
                out_len += 1;
                in_ws = false;
            }
        }
        return out_len as isize;
    }
    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut in_ws = false;
    for &b in trimmed {
        if matches!(b, b'.' | b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C) {
            if !in_ws {
                cur.push_byte(b' ');
                in_ws = true;
            }
        } else {
            cur.push_byte(b);
            in_ws = false;
        }
    }
    cur.finish()
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

    #[test]
    fn test_wave9_slice96_cdata_search_sql_and_manticore_schema() {
        let mut buf = [0u8; 512];

        // 1. nntmux_api_build_cdata
        let cdata_in = b"mu_artist\0Daft Punk\0mu_releasedate\02013-05-17 00:00:00\0year\02013";
        let n_cdata = unsafe {
            nntmux_api_build_cdata(
                cdata_in.as_ptr(),
                cdata_in.len(),
                3,
                1,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(n_cdata > 0);
        assert_eq!(
            &buf[..n_cdata as usize],
            b"<li>Artist: Daft Punk</li>\n<li>Released: 2013-05-17</li>\n<li>Year: 2013</li>\n"
        );

        // 2. nntmux_search_normalize_mysql_fallback_phrase
        let phrase_in = b"  Example.Show-S01_E02 \t\n O'Reilly.. ";
        let n_phrase = unsafe {
            nntmux_search_normalize_mysql_fallback_phrase(
                phrase_in.as_ptr(),
                phrase_in.len(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(n_phrase > 0);
        assert_eq!(
            &buf[..n_phrase as usize],
            b"example show s01 e02 o'reilly"
        );

        // 3. nntmux_search_build_range_sql_conditions
        let n_range = unsafe {
            nntmux_search_build_range_sql_conditions(
                20,
                40,
                -1,
                -1,
                -1,
                -1,
                -1,
                0,
                1_700_000_000,
                1_700_086_399,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(n_range > 0);
        assert_eq!(
            &buf[..n_range as usize],
            b"r.size >= 20\nr.size <= 40\nr.comments <= 0\nr.adddate >= '2023-11-14 22:13:20'\nr.adddate <= '2023-11-15 22:13:19'"
        );

        // 4. nntmux_search_build_episode_join_predicates
        let n_ep = unsafe {
            nntmux_search_build_episode_join_predicates(
                b"S06".as_ptr(),
                3,
                b"E024".as_ptr(),
                4,
                std::ptr::null(),
                0,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(n_ep > 0);
        assert_eq!(&buf[..n_ep as usize], b"tve.series = 6\ntve.episode = 24");

        let n_air = unsafe {
            nntmux_search_build_episode_join_predicates(
                b"2024".as_ptr(),
                4,
                std::ptr::null(),
                0,
                b"2024-03-05".as_ptr(),
                10,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(n_air > 0);
        assert_eq!(
            &buf[..n_air as usize],
            b"DATE(tve.firstaired) = '2024-03-05'"
        );

        // 5. nntmux_search_resolve_size_range_bounds
        let mut bounds = [0i64; 2];
        let rc_bounds =
            unsafe { nntmux_search_resolve_size_range_bounds(2, 4, bounds.as_mut_ptr()) };
        assert_eq!(rc_bounds, 0);
        assert_eq!(bounds, [209_715_200, 1_048_576_000]);

        // 6. nntmux_manticore_schema_compare_columns_nul
        let act_cols = b"passwordstatus\0uint\0categories_id\0uint";
        let exp_cols = b"passwordstatus\0bigint\0haspreview\0bigint\0categories_id\0integer";
        let n_cols = unsafe {
            nntmux_manticore_schema_compare_columns_nul(
                act_cols.as_ptr(),
                act_cols.len(),
                2,
                exp_cols.as_ptr(),
                exp_cols.len(),
                3,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(n_cols > 0);
        assert_eq!(
            &buf[..n_cols as usize],
            b"I\tpasswordstatus\tbigint\tuint\nM\thaspreview"
        );

        // 7. nntmux_manticore_schema_missing_settings_nul
        let act_set = b"\x01\0min_infix_len = '2'\r\nindex_field_lengths = 1";
        let exp_set = b"min_infix_len\02\0exact_words\01\0index_field_lengths\01";
        let n_set = unsafe {
            nntmux_manticore_schema_missing_settings_nul(
                act_set.as_ptr(),
                act_set.len(),
                1,
                exp_set.as_ptr(),
                exp_set.len(),
                3,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(n_set > 0);
        assert_eq!(&buf[..n_set as usize], b"exact_words");

        let raw_blob = b"min_infix_len = '2'\r\nindex_field_lengths = 1";
        let n_blob = unsafe {
            nntmux_manticore_schema_missing_settings_nul(
                raw_blob.as_ptr(),
                raw_blob.len(),
                0xFFFF_FFFF,
                exp_set.as_ptr(),
                exp_set.len(),
                3,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(n_blob, n_set);
        assert_eq!(&buf[..n_blob as usize], b"exact_words");
    }

    #[test]
    fn wave10_api_v2_rss_dates_secondary_index_fuzzy_and_escape() {
        let mut buf = [0u8; 256];

        // 1. nntmux_api_v2_rss_dates_batch (1, 2, and 3 dates)
        let d1 = b"2026-03-14 01:02:03";
        let d2 = b"2026-03-14 04:05:06";
        let d3 = b"2026-03-13 23:59:59";
        let n1 = unsafe {
            nntmux_api_v2_rss_dates_batch(
                d1.as_ptr(),
                d1.len(),
                std::ptr::null(),
                0,
                std::ptr::null(),
                0,
                1,
                1,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(n1, 31);
        assert_eq!(&buf[..31], b"Sat, 14 Mar 2026 01:02:03 +0000");

        let n2 = unsafe {
            nntmux_api_v2_rss_dates_batch(
                d1.as_ptr(),
                d1.len(),
                d2.as_ptr(),
                d2.len(),
                std::ptr::null(),
                0,
                2,
                1,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(n2, 63);
        assert_eq!(
            &buf[..63],
            b"Sat, 14 Mar 2026 01:02:03 +0000\nSat, 14 Mar 2026 04:05:06 +0000"
        );

        let n3 = unsafe {
            nntmux_api_v2_rss_dates_batch(
                d1.as_ptr(),
                d1.len(),
                d2.as_ptr(),
                d2.len(),
                d3.as_ptr(),
                d3.len(),
                3,
                1,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(n3, 95);
        assert_eq!(
            &buf[..95],
            b"Sat, 14 Mar 2026 01:02:03 +0000\nSat, 14 Mar 2026 04:05:06 +0000\nFri, 13 Mar 2026 23:59:59 +0000"
        );

        // Decline on non-UTC or empty date
        let dec = unsafe {
            nntmux_api_v2_rss_dates_batch(
                d1.as_ptr(),
                d1.len(),
                std::ptr::null(),
                0,
                std::ptr::null(),
                0,
                1,
                0,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(dec, -2);

        // 2. nntmux_secondary_index_releasedate_to_ts
        let ts_empty = unsafe { nntmux_secondary_index_releasedate_to_ts(std::ptr::null(), 0, 1) };
        assert_eq!(ts_empty, 0);
        let ts_date =
            unsafe { nntmux_secondary_index_releasedate_to_ts(b"2023-11-14".as_ptr(), 10, 1) };
        assert_eq!(ts_date, 1_699_920_000);
        let ts_declined =
            unsafe { nntmux_secondary_index_releasedate_to_ts(b"   ".as_ptr(), 3, 1) };
        assert_eq!(ts_declined, i64::MIN);

        // 3. nntmux_manticore_phrases_to_fuzzy
        let fz_in = b"Hello...World!\0\x0cTest_123\x0c\00\0   ";
        let n_fz = unsafe {
            nntmux_manticore_phrases_to_fuzzy(
                fz_in.as_ptr(),
                fz_in.len(),
                4,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(n_fz > 0);
        assert_eq!(&buf[..n_fz as usize], b"Hello World  Test_123 ");

        // 4. nntmux_elastic_escape_string
        let esc_in = b" ..Show.Name\t\nS01E02.. ";
        let n_esc = unsafe {
            nntmux_elastic_escape_string(
                esc_in.as_ptr(),
                esc_in.len(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(n_esc > 0);
        assert_eq!(&buf[..n_esc as usize], b"Show Name S01E02");
        assert_eq!(
            unsafe { nntmux_elastic_escape_string(b"*".as_ptr(), 1, buf.as_mut_ptr(), buf.len()) },
            0
        );
    }
}

// Wave 11: byte-oriented helpers. Length-prefixed fields preserve embedded NULs.
fn w11_fields(mut input: &[u8]) -> Option<Vec<&[u8]>> {
    let mut fields = Vec::new();
    while !input.is_empty() {
        let n = u32::from_le_bytes(input.get(..4)?.try_into().ok()?) as usize;
        input = &input[4..];
        fields.push(input.get(..n)?);
        input = &input[n..];
    }
    Some(fields)
}

fn w11_entity(input: &[u8]) -> Vec<u8> {
    let mut value: Vec<u8> = input
        .iter()
        .map(|b| {
            if *b == b'\\' {
                b'/'
            } else {
                b.to_ascii_lowercase()
            }
        })
        .collect();
    while value.last() == Some(&b'/') {
        value.pop();
    }
    let start = value.iter().rposition(|b| *b == b'/').map_or(0, |i| i + 1);
    let mut value = value[start..].to_vec();
    for b in &mut value {
        if matches!(*b, b'-' | b' ') {
            *b = b'_';
        }
    }
    match value.as_slice() {
        b"release" | b"releases" => b"release".to_vec(),
        b"pre" | b"pres" | b"predb" | b"predbs" => b"predb".to_vec(),
        b"movie" | b"movies" | b"movieinfo" | b"movie_info" => b"movie".to_vec(),
        b"tv" | b"video" | b"videos" | b"tvshow" | b"tvshows" | b"tv_show" | b"tv_shows" => {
            b"tvshow".to_vec()
        }
        b"secondary" | b"secondary_search" | b"secondary_search_index" => b"secondary".to_vec(),
        _ => value,
    }
}

fn w11_secondary(entity: &[u8]) -> Option<&'static [u8]> {
    match entity {
        b"music" | b"musicinfo" | b"music_info" => Some(b"music"),
        b"book" | b"books" | b"bookinfo" | b"book_info" => Some(b"books"),
        b"game" | b"games" | b"gamesinfo" | b"games_info" => Some(b"games"),
        b"console" | b"consoleinfo" | b"console_info" => Some(b"console"),
        b"steam" | b"steamapp" | b"steamapps" | b"steam_app" | b"steam_apps" => Some(b"steam"),
        b"anime" | b"anidb" | b"anidbtitle" | b"anidbtitles" | b"anidb_title" | b"anidb_titles"
        | b"anidbinfo" | b"anidb_info" => Some(b"anime"),
        _ => None,
    }
}

fn w11_key(entity: &[u8], id: &[u8], secondary: &[u8]) -> Vec<u8> {
    let entity = w11_entity(entity);
    let index = if entity == b"secondary" {
        w11_secondary(&w11_entity(secondary))
    } else {
        w11_secondary(&entity)
    };
    let mut key = Vec::new();
    if let Some(index) = index {
        key.extend_from_slice(b"secondary:");
        key.extend_from_slice(index);
    } else {
        key.extend_from_slice(&entity);
    }
    key.push(b':');
    key.extend_from_slice(id);
    key
}

fn w11_word(s: &[u8], at: usize, word: &[u8]) -> Option<usize> {
    s.get(at..at.checked_add(word.len())?)
        .filter(|v| v.eq_ignore_ascii_case(word))
        .map(|_| at + word.len())
}
fn w11_ws(s: &[u8], at: usize) -> Option<usize> {
    let mut end = at;
    while s.get(end).is_some_and(|b| is_ascii_ws(*b)) {
        end += 1;
    }
    (end > at).then_some(end)
}
fn w11_words(s: &[u8], at: usize, words: &[&[u8]]) -> Option<usize> {
    let mut end = at;
    for (i, word) in words.iter().enumerate() {
        if i > 0 {
            end = w11_ws(s, end)?;
        }
        end = w11_word(s, end, word)?;
    }
    Some(end)
}
fn w11_find(s: &[u8], from: usize, words: &[&[u8]]) -> Option<(usize, usize)> {
    (from..s.len()).find_map(|i| w11_words(s, i, words).map(|end| (i, end)))
}
fn w11_dollar(s: &[u8], at: usize) -> bool {
    at == s.len() || (at + 1 == s.len() && s[at] == b'\n')
}

fn w11_count_query(query: &[u8], fallback: bool) -> Option<Vec<u8>> {
    if fallback {
        let mut from = 0;
        let (start, mut end) = loop {
            let (at, prefix) = w11_find(query, from, &[b"FROM", b"releases", b"r"])?;
            let Some(ws) = w11_ws(query, prefix) else {
                from = at + 1;
                continue;
            };
            // Greedy whitespace may backtrack one byte to satisfy (.+?).
            let start = if ws == query.len() { ws - 1 } else { ws };
            if start <= prefix || start == query.len() {
                from = at + 1;
                continue;
            }
            let end = (start + 1..query.len())
                .find(|&i| {
                    w11_words(query, i, &[b"ORDER", b"BY"]).is_some()
                        || w11_word(query, i, b"LIMIT").is_some()
                })
                .unwrap_or(query.len());
            break (start, end);
        };
        let mut conditions = query[start..end].to_vec();
        let mut i = 0;
        while i < conditions.len() {
            let mut p = i;
            if let Some(next) = w11_word(&conditions, p, b"LEFT")
                .or_else(|| w11_word(&conditions, p, b"INNER"))
                .and_then(|v| w11_ws(&conditions, v))
            {
                p = next;
            }
            if let Some(next) =
                w11_word(&conditions, p, b"OUTER").and_then(|v| w11_ws(&conditions, v))
            {
                p = next;
            }
            if let Some(join) =
                w11_word(&conditions, p, b"JOIN").and_then(|v| w11_ws(&conditions, v))
            {
                let content = if join == conditions.len() {
                    join - 1
                } else {
                    join
                };
                if content > p + 4 && content < conditions.len() {
                    end = (content + 1..conditions.len())
                        .find(|&j| {
                            [b"WHERE" as &[u8], b"LEFT", b"INNER", b"JOIN"]
                                .iter()
                                .any(|word| w11_word(&conditions, j, word).is_some())
                        })
                        .unwrap_or(conditions.len());
                    conditions.drain(i..end);
                    continue;
                }
            }
            i += 1;
        }
        let mut result = b"SELECT COUNT(*) as count FROM releases r ".to_vec();
        // PHP trim excludes form feed.
        let ws = |b: &u8| matches!(*b, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b);
        let start = conditions
            .iter()
            .position(|b| !ws(b))
            .unwrap_or(conditions.len());
        let end = conditions
            .iter()
            .rposition(|b| !ws(b))
            .map_or(start, |i| i + 1);
        result.extend_from_slice(&conditions[start..end]);
        return Some(result);
    }
    if (0..query.len()).any(|i| {
        w11_words(query, i, &[b"SELECT", b"COUNT"]).is_some_and(|end| {
            let end = w11_ws(query, end).unwrap_or(end);
            query.get(end) == Some(&b'(')
        })
    }) {
        return Some(query.to_vec());
    }
    let mut s = query.to_vec();
    if let Some((at, _)) = (0..s.len()).find_map(|i| {
        w11_words(&s, i, &[b"ORDER", b"BY"])
            .filter(|&end| {
                s.get(end).is_some_and(|b| is_ascii_ws(*b))
                    && end + 1 < s.len()
                    && !s[end + 1..].contains(&b')')
            })
            .map(|end| (i, end))
    }) {
        s.truncate(at);
    }
    if let Some(at) = (0..s.len()).find(|&i| {
        w11_words(&s, i, &[b"GROUP", b"BY", b"r.id"])
            .is_some_and(|end| s[end..].iter().all(|b| is_ascii_ws(*b)))
    }) {
        s.truncate(at);
    }
    let distinct = w11_find(&s, 0, &[b"SELECT", b"DISTINCT"]).is_some()
        || w11_find(&s, 0, &[b"GROUP", b"BY"]).is_some();
    let replacement: &[u8] = if distinct {
        b"SELECT COUNT(DISTINCT r.id) as count FROM"
    } else {
        b"SELECT COUNT(*) as count FROM"
    };
    let mut result = Vec::new();
    let mut copied = 0;
    let mut scan = 0;
    while let Some((at, select_end)) = w11_find(&s, scan, &[b"SELECT"]) {
        let Some(ws) = w11_ws(&s, select_end) else {
            scan = at + 1;
            continue;
        };
        // SELECT\s+.+?\s+FROM: \s+ after SELECT is greedy, then lazy .+? (at least 1 byte), then \s+FROM.
        let found = (ws + 1..s.len())
            .find_map(|i| w11_ws(&s, i).and_then(|end| w11_word(&s, end, b"FROM")))
            .or_else(|| {
                (select_end + 2..s.len())
                    .find_map(|i| w11_ws(&s, i).and_then(|end| w11_word(&s, end, b"FROM")))
            });
        if let Some(end) = found {
            result.extend_from_slice(&s[copied..at]);
            result.extend_from_slice(replacement);
            copied = end;
            scan = end;
        } else {
            break;
        }
    }
    result.extend_from_slice(&s[copied..]);
    let mut limit_at = None;
    for i in 0..result.len() {
        if let Some(mut end) = w11_word(&result, i, b"LIMIT").and_then(|v| w11_ws(&result, v)) {
            let first = end;
            while result.get(end).is_some_and(|b| b.is_ascii_digit()) {
                end += 1;
            }
            if end == first {
                continue;
            }
            if let Some(offset) = w11_ws(&result, end)
                .and_then(|v| w11_word(&result, v, b"OFFSET"))
                .and_then(|v| w11_ws(&result, v))
            {
                let mut last = offset;
                while result.get(last).is_some_and(|b| b.is_ascii_digit()) {
                    last += 1;
                }
                if last > offset && w11_dollar(&result, last) {
                    limit_at = Some((i, last));
                    break;
                }
            }
            if w11_dollar(&result, end) {
                limit_at = Some((i, end));
                break;
            }
        }
    }
    if let Some((at, end)) = limit_at {
        result.drain(at..end);
    }
    Some(result)
}

/// Modes: entity/action/key/compact/release order/movie order/user SQL/count/fallback.
/// Returns -1 for invalid input, -2 for unsupported input, or negative required capacity.
///
/// # Safety
/// Input/output pointers must reference readable/writable nonoverlapping buffers of the given lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_outbox_browse_sql(
    mode: u32,
    input_ptr: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input_ptr.is_null() && input_len != 0 {
        return -1;
    }
    let input = if input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input_ptr, input_len) }
    };
    let result = match mode {
        0 => {
            if !input.is_ascii() {
                return -2;
            }
            w11_entity(input)
        }
        1 => {
            let value: Vec<u8> = input
                .iter()
                .map(|b| {
                    if matches!(*b, b'-' | b' ') {
                        b'_'
                    } else {
                        b.to_ascii_lowercase()
                    }
                })
                .collect();
            match value.as_slice() {
                b"insert" | b"inserted" | b"create" | b"created" => b"insert".to_vec(),
                b"upsert" | b"upserted" | b"replace" | b"replaced" | b"save" | b"saved"
                | b"index" | b"indexed" => b"upsert".to_vec(),
                b"update" | b"updated" => b"update".to_vec(),
                b"delete" | b"deleted" | b"destroy" | b"destroyed" | b"remove" | b"removed" => {
                    b"delete".to_vec()
                }
                _ => value,
            }
        }
        2 | 3 => {
            let Some(fields) = w11_fields(input) else {
                return -1;
            };
            if mode == 2 {
                if fields.len() != 3 {
                    return -1;
                }
                if !fields[0].is_ascii() || !fields[2].is_ascii() {
                    return -2;
                }
                w11_key(fields[0], fields[1], fields[2])
            } else {
                if fields.len() % 4 != 0 {
                    return -1;
                }
                let mut latest = std::collections::HashMap::new();
                for (i, row) in fields.chunks_exact(4).enumerate() {
                    if !row[0].is_ascii() || !row[2].is_ascii() {
                        return -2;
                    }
                    let Ok(bytes) = row[3].try_into() else {
                        return -1;
                    };
                    let id = i64::from_ne_bytes(bytes);
                    let entry = latest
                        .entry(w11_key(row[0], row[1], row[2]))
                        .or_insert((id, i));
                    if (id, i) > *entry {
                        *entry = (id, i);
                    }
                }
                // Stable input order for equal ids, as with both PHP stable usort calls.
                // PHP's first sort determines insertion order by the FIRST row for a key,
                // so ties between surviving keys use that first occurrence below.
                let mut first = std::collections::HashMap::new();
                for (i, row) in fields.chunks_exact(4).enumerate() {
                    let id = i64::from_ne_bytes(row[3].try_into().unwrap());
                    first
                        .entry(w11_key(row[0], row[1], row[2]))
                        .and_modify(|v: &mut (i64, usize)| *v = (*v).min((id, i)))
                        .or_insert((id, i));
                }
                let mut survivors: Vec<_> = latest
                    .into_iter()
                    .map(|(key, (id, i))| (id, first[&key], i))
                    .collect();
                survivors.sort_unstable();
                survivors
                    .iter()
                    .map(|(_, _, i)| i.to_string())
                    .collect::<Vec<_>>()
                    .join(",")
                    .into_bytes()
            }
        }
        4 | 5 => {
            let mut parts = input.split(|b| *b == b'_');
            let name = parts.next().unwrap_or(b"");
            let dir = parts.next().unwrap_or(b"desc");
            let lower = dir.to_ascii_lowercase();
            let valid = if mode == 4 {
                lower == b"asc" || lower == b"desc" || lower == b"asc\n" || lower == b"desc\n"
            } else {
                lower.starts_with(b"asc") || lower.ends_with(b"desc") || lower.ends_with(b"desc\n")
            };
            let field: &[u8] = if mode == 4 {
                match name {
                    b"cat" => b"categories_id",
                    b"name" => b"searchname",
                    b"size" => b"size",
                    b"files" => b"totalpart",
                    b"stats" => b"grabs",
                    b"comments" => b"comments",
                    b"added" => b"adddate",
                    _ => b"postdate",
                }
            } else {
                match name {
                    b"title" => b"m.title",
                    b"year" => b"m.year",
                    b"rating" => b"m.rating",
                    _ => b"MAX(r.postdate)",
                }
            };
            let mut out = field.to_vec();
            out.push(0);
            out.extend_from_slice(if valid { dir } else { b"desc" });
            out
        }
        6 => {
            let Some(fields) = w11_fields(input) else {
                return -1;
            };
            if fields.is_empty() || (fields.len() - 1) % 3 != 0 {
                return -1;
            }
            let mut out = b"(1=2 ".to_vec();
            for row in fields[1..].chunks_exact(3) {
                out.extend_from_slice(b"OR (r.");
                out.extend_from_slice(fields[0]);
                out.extend_from_slice(b" = ");
                out.extend_from_slice(row[0]);
                if row[1] == b"1" {
                    out.extend_from_slice(b" AND r.categories_id = ");
                    out.extend_from_slice(row[2]);
                }
                if row[1] == b"2" {
                    out.extend_from_slice(b" AND r.categories_id IN (");
                    for b in row[2] {
                        out.push(if *b == b'|' { b',' } else { *b });
                    }
                    out.push(b')');
                }
                out.extend_from_slice(b") ");
            }
            out.extend_from_slice(b") ");
            out
        }
        7 | 8 => {
            if input.len() > 16384 || !input.is_ascii() {
                return -2;
            }
            match w11_count_query(input, mode == 8) {
                Some(v) => v,
                None => return -2,
            }
        }
        _ => return -1,
    };
    let mut out = OutCursor::new(out_buf, out_cap);
    out.push_slice(&result);
    out.finish()
}

#[cfg(test)]
mod wave11_tests {
    use super::*;
    #[test]
    fn outbox_order_sql_and_abi_contracts() {
        assert_eq!(w11_entity(b"App\\Models\\Movie-Info///"), b"movie");
        assert_eq!(w11_key(b"MusicInfo", b"42", b""), b"secondary:music:42");
        assert_eq!(
            w11_count_query(b"SELECT r.id FROM releases r ORDER BY r.id LIMIT 5", false).unwrap(),
            b"SELECT COUNT(*) as count FROM releases r "
        );
        assert_eq!(
            w11_count_query(
                b"SELECT r.* FROM releases r LEFT JOIN x ON 1 WHERE r.id=2",
                true
            )
            .unwrap(),
            b"SELECT COUNT(*) as count FROM releases r WHERE r.id=2"
        );
        let mut out = [0u8; 64];
        unsafe {
            assert_eq!(
                nntmux_search_outbox_browse_sql(
                    0,
                    std::ptr::null(),
                    1,
                    out.as_mut_ptr(),
                    out.len()
                ),
                -1
            );
            assert_eq!(
                nntmux_search_outbox_browse_sql(2, b"bad".as_ptr(), 3, out.as_mut_ptr(), out.len()),
                -1
            );
            assert_eq!(
                nntmux_search_outbox_browse_sql(
                    99,
                    std::ptr::null(),
                    0,
                    out.as_mut_ptr(),
                    out.len()
                ),
                -1
            );
            assert_eq!(
                nntmux_search_outbox_browse_sql(1, b"CREATED".as_ptr(), 7, out.as_mut_ptr(), 1),
                -6
            );
        }
    }
}
// ---------------------------------------------------------------------------
// Wave 12: log viewer backward scanner, log index documents, registration audit parser and
// admin log search boundaries. Each export mirrors the PHP reference byte-for-byte.
// ---------------------------------------------------------------------------

/// PCRE's `\s` in the C locale (space, `\t`, `\n`, `\v`, `\f`, `\r`).
#[inline]
fn w12_pcre_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')
}

#[inline]
fn w12_channel_byte(b: u8) -> bool {
    b.is_ascii_alphanumeric() || matches!(b, b'_' | b'.' | b'-')
}

#[inline]
unsafe fn w12_input<'a>(ptr: *const u8, len: usize) -> Option<&'a [u8]> {
    if len == 0 {
        Some(&[])
    } else if ptr.is_null() {
        None
    } else {
        Some(unsafe { slice::from_raw_parts(ptr, len) })
    }
}

/// `LogReader::cap()`: `strlen($line) > $maxBytes ? substr($line, 0, $maxBytes) : $line`.
/// Returns the kept length when the line is not truncated and `-(kept + 1)` when it is.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_logviewer_cap_len(line_len: usize, max_bytes: i64) -> i64 {
    let len = line_len as i64;
    if len > max_bytes {
        let kept = if max_bytes >= 0 {
            max_bytes
        } else {
            (len + max_bytes).max(0)
        };
        -(kept + 1)
    } else {
        len
    }
}

/// Newline positions of a backward chunk (`explode("\n", $buffer)` in `LogReader::linesBackward()`).
/// Writes each `\n` index as a native-endian `i64` into `out_buf` and returns the number of bytes
/// written, `-(required)` when `out_cap` is too small, or `-1` for an invalid pointer.
///
/// # Safety
/// `buf` must reference `len` readable bytes and `out_buf` `out_cap` writable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_logviewer_split_backward(
    buf: *const u8,
    len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(input) = (unsafe { w12_input(buf, len) }) else {
        return -1;
    };
    if out_buf.is_null() && out_cap != 0 {
        return -1;
    }
    let mut cursor = OutCursor::new(out_buf, out_cap);
    let mut start = 0usize;
    while let Some(found) = input[start..].iter().position(|&b| b == b'\n') {
        let index = start + found;
        cursor.push_slice(&(index as i64).to_ne_bytes());
        start = index + 1;
    }
    if cursor.overflow {
        -(cursor.len as isize)
    } else {
        cursor.len as isize
    }
}

/// `LogIndex::documentId()`: `($fileId << 40) | $offset` with PHP's wrapping integer shift.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_logviewer_document_id(file_id: i64, offset: i64) -> i64 {
    (((file_id as u64) << 40) as i64) | offset
}

/// `preg_match('/^[A-Za-z0-9_]+$/', $table) === 1`, including `$` before one trailing newline.
///
/// # Safety
/// `name` must reference `len` readable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_logviewer_valid_table_name(name: *const u8, len: usize) -> i32 {
    let Some(input) = (unsafe { w12_input(name, len) }) else {
        return -1;
    };
    let body = input.strip_suffix(b"\n").unwrap_or(input);
    i32::from(!body.is_empty() && body.iter().all(|&b| b.is_ascii_alphanumeric() || b == b'_'))
}

/// `mb_scrub($input, 'UTF-8')`: every maximal invalid subpart becomes one `?`.
/// Returns `-1` when the input is already valid (nothing written), the written length otherwise,
/// `-(required + 1)` when `out_cap` is too small, or `isize::MIN` for an invalid pointer.
///
/// # Safety
/// `input` must reference `len` readable bytes and `out_buf` `out_cap` writable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_logviewer_scrub_utf8(
    input: *const u8,
    len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(mut rest) = (unsafe { w12_input(input, len) }) else {
        return isize::MIN;
    };
    if std::str::from_utf8(rest).is_ok() {
        return -1;
    }
    let mut cursor = OutCursor::new(out_buf, out_cap);
    loop {
        match std::str::from_utf8(rest) {
            Ok(valid) => {
                cursor.push_slice(valid.as_bytes());
                break;
            }
            Err(error) => {
                let good = error.valid_up_to();
                cursor.push_slice(&rest[..good]);
                cursor.push_byte(b'?');
                match error.error_len() {
                    Some(bad) => rest = &rest[good + bad..],
                    None => break,
                }
            }
        }
    }
    if cursor.overflow {
        -(cursor.len as isize) - 1
    } else {
        cursor.len as isize
    }
}

const W12_REGISTRATION_MAX_LINE: usize = 262_144;

/// Byte ranges of `RegistrationFailureLogService::parseLine()`'s two Monolog patterns.
///
/// Writes `[ts_start, ts_len, level_start, level_len, message_start, message_len, context_start,
/// context_len]` into `out` (context_start is `-1` for the context-less pattern). Returns `1` for the
/// context pattern, `2` for the plain pattern, `0` when neither matches, and `-1` for input the PHP
/// reference must handle (newlines, oversized lines, invalid pointers).
///
/// # Safety
/// `line` must reference `len` readable bytes and `out` eight writable `i64` values.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_registration_parse_line(
    line: *const u8,
    len: usize,
    out: *mut i64,
) -> i32 {
    if out.is_null() || len > W12_REGISTRATION_MAX_LINE {
        return -1;
    }
    let Some(l) = (unsafe { w12_input(line, len) }) else {
        return -1;
    };
    if l.contains(&b'\n') {
        return -1;
    }
    let Some(found) = w12_registration_match(l) else {
        return 0;
    };
    let (kind, values) = found;
    let target = unsafe { slice::from_raw_parts_mut(out, 8) };
    for (slot, value) in target.iter_mut().zip(values) {
        *slot = value as i64;
    }
    kind
}

fn w12_registration_match(l: &[u8]) -> Option<(i32, [isize; 8])> {
    let n = l.len();
    if l.first() != Some(&b'[') {
        return None;
    }
    let close = l[1..].iter().position(|&b| b == b']')? + 1;
    if close == 1 {
        return None;
    }
    let mut i = close + 1;
    let ws_start = i;
    while i < n && w12_pcre_space(l[i]) {
        i += 1;
    }
    if i == ws_start {
        return None;
    }
    let run_start = i;
    while i < n && w12_channel_byte(l[i]) {
        i += 1;
    }
    let run_end = i;
    if run_end >= n || l[run_end] != b':' {
        return None;
    }
    let dot = l[run_start..run_end].iter().rposition(|&b| b == b'.')? + run_start;
    if dot == run_start || dot + 1 == run_end {
        return None;
    }
    if !l[dot + 1..run_end].iter().all(u8::is_ascii_uppercase) {
        return None;
    }
    let colon = run_end;
    let mut w_end = colon + 1;
    while w_end < n && w12_pcre_space(l[w_end]) {
        w_end += 1;
    }
    let w_len = w_end - (colon + 1);
    if w_len == 0 {
        return None;
    }
    let head = [
        1,
        (close - 1) as isize,
        (dot + 1) as isize,
        (run_end - dot - 1) as isize,
    ];

    // Pattern 1: `(?<message>.*?)\s+(?<context>\{.*\})(?:\s+\[\])?$`.
    let context_end = if l[n - 1] == b'}' {
        Some(n - 1)
    } else if l.ends_with(b"[]") {
        let mut j = n - 2;
        while j > 0 && w12_pcre_space(l[j - 1]) {
            j -= 1;
        }
        (j < n - 2 && j > 0 && l[j - 1] == b'}').then(|| j - 1)
    } else {
        None
    };
    if let Some(e) = context_end {
        let mut message_end = None;
        let mut p = w_end;
        while p < e {
            if w12_pcre_space(l[p]) {
                let mut q = p;
                while q < n && w12_pcre_space(l[q]) {
                    q += 1;
                }
                if q < e && l[q] == b'{' {
                    message_end = Some((p, q));
                    break;
                }
                p = q;
            } else {
                p += 1;
            }
        }
        let resolved = match message_end {
            Some((p, q)) => Some((w_end, p, q)),
            // `:\s+` gives one whitespace back so an empty message can precede the context.
            None if w_len >= 2 && w_end < e && l[w_end] == b'{' => {
                Some((w_end - 1, w_end - 1, w_end))
            }
            None => None,
        };
        if let Some((message_start, p, q)) = resolved {
            return Some((
                1,
                [
                    head[0],
                    head[1],
                    head[2],
                    head[3],
                    message_start as isize,
                    (p - message_start) as isize,
                    q as isize,
                    (e + 1 - q) as isize,
                ],
            ));
        }
    }

    // Pattern 2: `(?<message>.+)$`.
    let (message_start, message_len) = if w_end < n {
        (w_end, n - w_end)
    } else if w_len >= 2 {
        (n - 1, 1)
    } else {
        return None;
    };
    Some((
        2,
        [
            head[0],
            head[1],
            head[2],
            head[3],
            message_start as isize,
            message_len as isize,
            -1,
            0,
        ],
    ))
}

#[inline]
fn w12_strip_final_newline(value: &[u8]) -> Option<&[u8]> {
    value.strip_suffix(b"\n")
}

#[inline]
fn w12_is_date(value: &[u8]) -> bool {
    value.len() == 10
        && value.iter().enumerate().all(|(index, &b)| {
            if index == 4 || index == 7 {
                b == b'-'
            } else {
                b.is_ascii_digit()
            }
        })
}

#[inline]
fn w12_ends_minute(value: &[u8]) -> bool {
    let n = value.len();
    n >= 6 && {
        let tail = &value[n - 6..];
        matches!(tail[0], b'T' | b' ')
            && tail[1].is_ascii_digit()
            && tail[2].is_ascii_digit()
            && tail[3] == b':'
            && tail[4].is_ascii_digit()
            && tail[5].is_ascii_digit()
    }
}

/// `AdminLogSearchRequest` boundary shape: `1` for `^\d{4}-\d{2}-\d{2}$`, `2` for `[T ]\d{2}:\d{2}$`,
/// `0` otherwise (PCRE's `$` also matches before one final newline). `-1` for an invalid pointer.
///
/// # Safety
/// `value` must reference `len` readable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_admin_log_boundary_shape(value: *const u8, len: usize) -> i32 {
    let Some(input) = (unsafe { w12_input(value, len) }) else {
        return -1;
    };
    let stripped = w12_strip_final_newline(input);
    if w12_is_date(input) || stripped.is_some_and(w12_is_date) {
        return 1;
    }
    if w12_ends_minute(input) || stripped.is_some_and(w12_ends_minute) {
        return 2;
    }
    0
}

#[cfg(test)]
mod wave12_tests {
    use super::*;

    fn parse(line: &[u8]) -> (i32, [i64; 8]) {
        let mut out = [0i64; 8];
        let kind =
            unsafe { nntmux_registration_parse_line(line.as_ptr(), line.len(), out.as_mut_ptr()) };
        (kind, out)
    }

    fn range(line: &[u8], start: i64, len: i64) -> &[u8] {
        &line[start as usize..(start + len) as usize]
    }

    #[test]
    fn registration_context_line() {
        let line = br#"[2026-10-24 10:00:00] production.WARNING: Registration attempt failed: {"reason":"x","ip":"1.2.3.4"} []"#;
        let (kind, out) = parse(line);
        assert_eq!(kind, 1);
        assert_eq!(range(line, out[0], out[1]), b"2026-10-24 10:00:00");
        assert_eq!(range(line, out[2], out[3]), b"WARNING");
        assert_eq!(range(line, out[4], out[5]), b"Registration attempt failed:");
        assert_eq!(
            range(line, out[6], out[7]),
            br#"{"reason":"x","ip":"1.2.3.4"}"#
        );
    }

    #[test]
    fn registration_plain_and_empty_message() {
        let line = b"[ts] a.b.ERROR: plain message";
        let (kind, out) = parse(line);
        assert_eq!(kind, 2);
        assert_eq!(range(line, out[2], out[3]), b"ERROR");
        assert_eq!(range(line, out[4], out[5]), b"plain message");

        let line = b"[ts] a.ERROR:  {}";
        let (kind, out) = parse(line);
        assert_eq!(kind, 1);
        assert_eq!(out[5], 0);
        assert_eq!(range(line, out[6], out[7]), b"{}");

        assert_eq!(parse(b"[ts] a.ERROR: ").0, 0);
        assert_eq!(parse(b"[ts] a.Error: x").0, 0);
        assert_eq!(parse(b"[ts] a.ERROR: x\ny").0, -1);
    }

    #[test]
    fn boundary_and_table_shapes() {
        let shape = |v: &[u8]| unsafe { nntmux_admin_log_boundary_shape(v.as_ptr(), v.len()) };
        assert_eq!(shape(b"2026-10-24"), 1);
        assert_eq!(shape(b"2026-10-24\n"), 1);
        assert_eq!(shape(b"2026-10-24T10:30"), 2);
        assert_eq!(shape(b"2026-10-24 10:30\n"), 2);
        assert_eq!(shape(b"2026-10-24T10:30:15"), 0);
        let table = |v: &[u8]| unsafe { nntmux_logviewer_valid_table_name(v.as_ptr(), v.len()) };
        assert_eq!(table(b"log_entries"), 1);
        assert_eq!(table(b"log_entries\n"), 1);
        assert_eq!(table(b"\n"), 0);
        assert_eq!(table(b"bad-name"), 0);
        assert_eq!(table(b""), 0);
    }

    #[test]
    fn scrub_document_cap_and_split() {
        let mut out = [0u8; 32];
        let scrub = |v: &[u8], out: &mut [u8]| unsafe {
            nntmux_logviewer_scrub_utf8(v.as_ptr(), v.len(), out.as_mut_ptr(), out.len())
        };
        assert_eq!(scrub("é ok".as_bytes(), &mut out), -1);
        let written = scrub(b"a\xed\xa0\x80b\xf0\x9f\x98", &mut out);
        assert_eq!(&out[..written as usize], b"a???b?");
        assert_eq!(scrub(b"\xff\xff", &mut out[..1]), -3);

        assert_eq!(nntmux_logviewer_document_id(3, 17), (3 << 40) | 17);
        assert_eq!(nntmux_logviewer_document_id(-1, 0), -1i64 << 40);
        assert_eq!(nntmux_logviewer_cap_len(10, 20), 10);
        assert_eq!(nntmux_logviewer_cap_len(10, 4), -5);
        assert_eq!(nntmux_logviewer_cap_len(3, -5), -1);

        let mut buf = [0u8; 64];
        let data = b"ab\n\ncd\n";
        let written = unsafe {
            nntmux_logviewer_split_backward(data.as_ptr(), data.len(), buf.as_mut_ptr(), buf.len())
        };
        assert_eq!(written, 24);
        let indices: Vec<i64> = buf[..24]
            .chunks_exact(8)
            .map(|c| i64::from_ne_bytes(c.try_into().unwrap()))
            .collect();
        assert_eq!(indices, vec![2, 3, 6]);
        let needed = unsafe {
            nntmux_logviewer_split_backward(data.as_ptr(), data.len(), buf.as_mut_ptr(), 8)
        };
        assert_eq!(needed, -24);
    }
}

// ---------------------------------------------------------------------------
// Wave 13 Slice 4: RSS parameters, search reconcile, check-index and Manticore
// client configuration helpers.
// ---------------------------------------------------------------------------

/// PHP `trim()` default character mask: `" \t\n\r\0\x0B"` (no form feed).
#[inline]
fn w13_is_php_trim(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B)
}

#[inline]
fn w13_trim(mut s: &[u8]) -> &[u8] {
    while let Some((&b, rest)) = s.split_first() {
        if !w13_is_php_trim(b) {
            break;
        }
        s = rest;
    }
    while let Some((&b, rest)) = s.split_last() {
        if !w13_is_php_trim(b) {
            break;
        }
        s = rest;
    }
    s
}

/// Whitespace accepted around PHP 8 numeric strings: `" \t\n\r\v\f"`.
#[inline]
fn w13_is_numeric_ws(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C)
}

/// Shape of a PHP 8 string under `is_numeric()` and integer casts.
#[derive(Debug, PartialEq, Eq, Clone, Copy)]
enum W13Numeric {
    /// Whole string is `ws* [+-]? digits ws*` and fits in `i64`.
    Int(i64),
    /// Numeric (`is_numeric() === true`) but not a plain in-range integer.
    OtherNumeric,
    /// Not numeric and `(int)` yields `0` (no leading numeric prefix).
    NonNumericZero,
    /// Not numeric but has a leading numeric prefix (`"5abc"`, `"1.5x"`).
    LeadingNumeric,
}

fn w13_classify_numeric(input: &[u8]) -> W13Numeric {
    let mut i = 0;
    let n = input.len();
    while i < n && w13_is_numeric_ws(input[i]) {
        i += 1;
    }
    let mut negative = false;
    if i < n && (input[i] == b'+' || input[i] == b'-') {
        negative = input[i] == b'-';
        i += 1;
    }
    let int_start = i;
    while i < n && input[i].is_ascii_digit() {
        i += 1;
    }
    let int_digits = i - int_start;
    let mut frac_digits = 0;
    let mut has_dot = false;
    if i < n && input[i] == b'.' {
        has_dot = true;
        i += 1;
        let frac_start = i;
        while i < n && input[i].is_ascii_digit() {
            i += 1;
        }
        frac_digits = i - frac_start;
    }
    if int_digits == 0 && frac_digits == 0 {
        // No numeric prefix at all: `(int)` is 0 and `is_numeric()` is false.
        return W13Numeric::NonNumericZero;
    }
    let mantissa_end = i;
    let mut has_exp = false;
    if i < n && (input[i] == b'e' || input[i] == b'E') {
        let mut j = i + 1;
        if j < n && (input[j] == b'+' || input[j] == b'-') {
            j += 1;
        }
        let exp_start = j;
        while j < n && input[j].is_ascii_digit() {
            j += 1;
        }
        if j > exp_start {
            has_exp = true;
            i = j;
        }
    }
    let mut k = i;
    while k < n && w13_is_numeric_ws(input[k]) {
        k += 1;
    }
    if k != n {
        return W13Numeric::LeadingNumeric;
    }
    if has_dot || has_exp {
        return W13Numeric::OtherNumeric;
    }
    let digits = &input[int_start..mantissa_end];
    let mut value: i64 = 0;
    for &d in digits {
        let Some(next) = value.checked_mul(10).and_then(|v| {
            if negative {
                v.checked_sub((d - b'0') as i64)
            } else {
                v.checked_add((d - b'0') as i64)
            }
        }) else {
            return W13Numeric::OtherNumeric;
        };
        value = next;
    }
    W13Numeric::Int(value)
}

/// `RssController::categoryFeedRss()` id list: `explode(',')`, `trim()`, keep `^-?\d+$`.
/// Writes kept tokens joined by `,`; returns the byte length (`0` for none), `-1` for an invalid
/// pointer, or `-(needed)` (at most `-4`) when `out_cap` is too small.
///
/// # Safety
/// `input` must reference `len` readable bytes and `out` must reference `out_cap` writable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rss_parse_category_ids(
    input: *const u8,
    len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(input) = (unsafe { w12_input(input, len) }) else {
        return -1;
    };
    let mut cursor = OutCursor::new(out, out_cap);
    let mut first = true;
    for piece in input.split(|&b| b == b',') {
        let token = w13_trim(piece);
        let digits = token.strip_prefix(b"-").unwrap_or(token);
        if digits.is_empty() || !digits.iter().all(u8::is_ascii_digit) {
            continue;
        }
        if !first {
            cursor.push_byte(b',');
        }
        first = false;
        cursor.push_slice(token);
    }
    cursor.finish()
}

/// `RssController` `show`/`anidb` value: `(int) $v === 0 ? -1 : $v + 0` for string inputs.
/// Returns `1` with `*out` set, `0` when PHP must decide (decimal, exponent, overflow, or leading
/// numeric garbage that emits a warning), or `-1` for invalid pointers.
///
/// # Safety
/// `input` must reference `len` readable bytes and `out` must be a valid `int64_t` pointer.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rss_show_id(input: *const u8, len: usize, out: *mut i64) -> i32 {
    let Some(input) = (unsafe { w12_input(input, len) }) else {
        return -1;
    };
    if out.is_null() {
        return -1;
    }
    let value = match w13_classify_numeric(input) {
        W13Numeric::NonNumericZero | W13Numeric::Int(0) => -1,
        W13Numeric::Int(v) => v,
        W13Numeric::OtherNumeric | W13Numeric::LeadingNumeric => return 0,
    };
    unsafe { *out = value };
    1
}

/// `RssController` `airdate`/`num`: `is_numeric($v) ? abs((int) $v) : default` for strings.
/// Returns `1` (numeric, `*out = abs(int)`), `2` (not numeric; caller uses its default), `0` when PHP
/// must decide (decimal, exponent, overflow, or `PHP_INT_MIN`), or `-1` for invalid pointers.
///
/// # Safety
/// `input` must reference `len` readable bytes and `out` must be a valid `int64_t` pointer.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rss_abs_numeric_int(
    input: *const u8,
    len: usize,
    out: *mut i64,
) -> i32 {
    let Some(input) = (unsafe { w12_input(input, len) }) else {
        return -1;
    };
    if out.is_null() {
        return -1;
    }
    match w13_classify_numeric(input) {
        W13Numeric::Int(v) => match v.checked_abs() {
            Some(abs) => {
                unsafe { *out = abs };
                1
            }
            None => 0,
        },
        W13Numeric::NonNumericZero | W13Numeric::LeadingNumeric => 2,
        W13Numeric::OtherNumeric => 0,
    }
}

#[inline]
fn w13_push_i64(cursor: &mut OutCursor, value: i64) {
    let mut buf = [0u8; 20];
    let mut pos = buf.len();
    let negative = value < 0;
    let mut v = value.unsigned_abs();
    loop {
        pos -= 1;
        buf[pos] = b'0' + (v % 10) as u8;
        v /= 10;
        if v == 0 {
            break;
        }
    }
    if negative {
        cursor.push_byte(b'-');
    }
    cursor.push_slice(&buf[pos..]);
}

/// `RSS::getRss()` filter fragments for integer inputs, separated by NUL:
/// videos, anidb, airdate and limit/offset. Returns bytes written, `-(needed)` when `out_cap` is too
/// small, or `-1` for an invalid output pointer.
///
/// # Safety
/// `out` must reference `out_cap` writable bytes.
#[unsafe(no_mangle)]
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn nntmux_rss_filter_fragments(
    videos_id: i64,
    anidb_id: i64,
    air_date: i64,
    cat_search_empty: i32,
    limit: i64,
    offset: i64,
    tv_root: i64,
    tv_other: i64,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    if out.is_null() && out_cap > 0 {
        return -1;
    }
    let mut cursor = OutCursor::new(out, out_cap);
    let cat_limit = |cursor: &mut OutCursor| {
        if cat_search_empty != 0 {
            cursor.push_slice(b"AND r.categories_id BETWEEN ");
            w13_push_i64(cursor, tv_root);
            cursor.push_slice(b" AND ");
            w13_push_i64(cursor, tv_other);
        }
    };
    if videos_id > 0 {
        cursor.push_slice(b"AND r.videos_id = ");
        w13_push_i64(&mut cursor, videos_id);
        cursor.push_byte(b' ');
        cat_limit(&mut cursor);
    }
    cursor.push_byte(0);
    if anidb_id > 0 {
        cursor.push_slice(b"AND r.anidbid = ");
        w13_push_i64(&mut cursor, anidb_id);
        cursor.push_byte(b' ');
        cat_limit(&mut cursor);
    }
    cursor.push_byte(0);
    if air_date > -1 {
        cursor.push_slice(b"AND tve.firstaired >= DATE_SUB(CURDATE(), INTERVAL ");
        w13_push_i64(&mut cursor, air_date);
        cursor.push_slice(b" DAY)");
    }
    cursor.push_byte(0);
    if limit != -1 {
        cursor.push_slice(b" LIMIT ");
        w13_push_i64(&mut cursor, limit);
        cursor.push_slice(b" OFFSET ");
        w13_push_i64(&mut cursor, offset);
    }
    cursor.finish()
}

/// `NntmuxSearchReconcile::parseSinceCutoff()` window: `trim()` then `^(\d+)h$` / `^(\d+)d$`
/// (case-insensitive). Returns `1` (hours) or `2` (days) with `*amount` set using PHP's saturating
/// integer-string cast, `0` when neither matches, or `-1` for invalid pointers.
///
/// # Safety
/// `input` must reference `len` readable bytes and `amount` must be a valid `int64_t` pointer.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_reconcile_parse_since(
    input: *const u8,
    len: usize,
    amount: *mut i64,
) -> i32 {
    let Some(input) = (unsafe { w12_input(input, len) }) else {
        return -1;
    };
    if amount.is_null() {
        return -1;
    }
    let trimmed = w13_trim(input);
    // PCRE `$` also matches before one final newline, which `trim()` already removed.
    let Some((&unit, digits)) = trimmed.split_last() else {
        return 0;
    };
    let kind = match unit {
        b'h' | b'H' => 1,
        b'd' | b'D' => 2,
        _ => return 0,
    };
    if digits.is_empty() || !digits.iter().all(u8::is_ascii_digit) {
        return 0;
    }
    let mut value: i64 = 0;
    for &d in digits {
        value = value.saturating_mul(10).saturating_add((d - b'0') as i64);
    }
    unsafe { *amount = value };
    kind
}

#[inline]
unsafe fn w13_read_i64s<'a>(ptr: *const u8, count: usize) -> Option<&'a [u8]> {
    let bytes = count.checked_mul(8)?;
    unsafe { w12_input(ptr, bytes) }
}

#[inline]
fn w13_i64_at(bytes: &[u8], index: usize) -> i64 {
    let mut raw = [0u8; 8];
    raw.copy_from_slice(&bytes[index * 8..index * 8 + 8]);
    i64::from_ne_bytes(raw)
}

/// `array_values(array_diff($batch, $indexed))` for packed native-endian `int64` lists.
/// Writes the kept ids (packed `int64`, batch order, duplicates retained) to `out` and returns the
/// byte length, `-(needed)` when `out_cap` is too small, or `-1` for invalid pointers.
///
/// # Safety
/// `batch`/`indexed` must reference `count * 8` readable bytes; `out` must reference `out_cap`
/// writable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_reconcile_missing_ids(
    batch: *const u8,
    batch_count: usize,
    indexed: *const u8,
    indexed_count: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    let (Some(batch), Some(indexed)) = (unsafe { w13_read_i64s(batch, batch_count) }, unsafe {
        w13_read_i64s(indexed, indexed_count)
    }) else {
        return -1;
    };
    let mut cursor = OutCursor::new(out, out_cap);
    if indexed_count == 0 {
        cursor.push_slice(batch);
        return cursor.finish();
    }
    let mut seen: Vec<i64> = (0..indexed_count).map(|i| w13_i64_at(indexed, i)).collect();
    seen.sort_unstable();
    for i in 0..batch_count {
        let id = w13_i64_at(batch, i);
        if seen.binary_search(&id).is_err() {
            cursor.push_slice(&id.to_ne_bytes());
        }
    }
    cursor.finish()
}

/// `NntmuxSearchReconcile` id probe:
/// ``SELECT id FROM `index` WHERE id IN (ids) LIMIT n OPTION max_matches=n`` with backticks doubled
/// and `n = max(count, 1)`. Returns bytes written, `-(needed)` when `out_cap` is too small, or `-1`
/// for invalid pointers.
///
/// # Safety
/// `index` must reference `index_len` readable bytes, `ids` `count * 8` readable bytes, and `out`
/// `out_cap` writable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_reconcile_probe_sql(
    index: *const u8,
    index_len: usize,
    ids: *const u8,
    count: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    let (Some(index), Some(ids)) = (unsafe { w12_input(index, index_len) }, unsafe {
        w13_read_i64s(ids, count)
    }) else {
        return -1;
    };
    let mut cursor = OutCursor::new(out, out_cap);
    cursor.push_slice(b"SELECT id FROM `");
    for chunk in index.split_inclusive(|&b| b == b'`') {
        cursor.push_slice(chunk);
        if chunk.last() == Some(&b'`') {
            cursor.push_byte(b'`');
        }
    }
    cursor.push_slice(b"` WHERE id IN (");
    for i in 0..count {
        if i > 0 {
            cursor.push_byte(b',');
        }
        w13_push_i64(&mut cursor, w13_i64_at(ids, i));
    }
    let limit = count.max(1) as i64;
    cursor.push_slice(b") LIMIT ");
    w13_push_i64(&mut cursor, limit);
    cursor.push_slice(b" OPTION max_matches=");
    w13_push_i64(&mut cursor, limit);
    cursor.finish()
}

/// PHP 8.4+ `round($value, 2)` (`PHP_ROUND_HALF_UP`) for finite non-negative values.
fn w13_php_round2(value: f64) -> f64 {
    if !value.is_finite() || value == 0.0 {
        return value;
    }
    let exponent = 100.0f64;
    let mut tmp = (value * exponent).floor();
    if (tmp + 1.0) / exponent == value {
        tmp += 1.0;
    }
    if tmp.abs() >= 1e16 {
        return value;
    }
    let edge = (tmp + 0.5) / exponent;
    if value >= edge {
        tmp += 1.0;
    }
    tmp / exponent
}

/// `NntmuxCheckIndex::formatBytes()`: `round($bytes / 1024 ** $pow, 2).' '.$unit`.
/// Returns bytes written, `-(needed)` when `out_cap` is too small, `-1` for an invalid pointer, or
/// `-2` when PHP must format the value (non-plain float output).
///
/// # Safety
/// `out` must reference `out_cap` writable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_checkindex_format_bytes(
    bytes: i64,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    if out.is_null() && out_cap > 0 {
        return -1;
    }
    const UNITS: [&[u8]; 5] = [b"B", b"KB", b"MB", b"GB", b"TB"];
    let bytes = bytes.max(0);
    let pow = if bytes != 0 {
        ((bytes as f64).ln() / 1024f64.ln()).floor()
    } else {
        0.0
    };
    let pow = pow.min(4.0).max(0.0);
    let scaled = bytes as f64 / 1024f64.powf(pow);
    let rounded = w13_php_round2(scaled);
    if !(0.0..1e15).contains(&rounded) {
        return -2;
    }
    let text = format!("{rounded}");
    let mut cursor = OutCursor::new(out, out_cap);
    cursor.push_slice(text.as_bytes());
    cursor.push_byte(b' ');
    cursor.push_slice(UNITS[pow as usize]);
    cursor.finish()
}

/// `NntmuxCheckIndex` Manticore table code for an index option (0 = `releases`/default .. 9).
///
/// # Safety
/// `input` must reference `len` readable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_checkindex_table_code(input: *const u8, len: usize) -> i32 {
    let Some(input) = (unsafe { w12_input(input, len) }) else {
        return -1;
    };
    match input {
        b"predb" => 1,
        b"movies" => 2,
        b"tvshows" => 3,
        b"music" => 4,
        b"books" => 5,
        b"games" => 6,
        b"console" => 7,
        b"steam" => 8,
        b"anime" => 9,
        _ => 0,
    }
}

/// `ManticoreClientFactory::normalizeHosts()` for strings: `explode(',')`, `trim()`, then
/// `array_filter()` (drops `''` and `'0'`). Writes kept hosts joined by `,`; returns the byte length
/// (`0` for none), `-1` for invalid pointers, or `-(needed)` when `out_cap` is too small.
///
/// # Safety
/// `input` must reference `len` readable bytes and `out` must reference `out_cap` writable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_manticore_cfg_normalize_hosts(
    input: *const u8,
    len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(input) = (unsafe { w12_input(input, len) }) else {
        return -1;
    };
    let mut cursor = OutCursor::new(out, out_cap);
    if w13_trim(input).is_empty() {
        return cursor.finish();
    }
    let mut first = true;
    for piece in input.split(|&b| b == b',') {
        let host = w13_trim(piece);
        if host.is_empty() || host == b"0" {
            continue;
        }
        if !first {
            cursor.push_byte(b',');
        }
        first = false;
        cursor.push_slice(host);
    }
    cursor.finish()
}

/// Conservative `parse_url()` subset for `ManticoreClientFactory::parseHost()` strings:
/// `[scheme://]host[:port][/path]` with `host` in `[A-Za-z0-9._-]+`, a 1-5 digit port without a
/// leading zero (<= 65535) and a path in `[A-Za-z0-9._/-]*`. On success returns `1` and fills
/// `out[0..7]` = scheme off/len (len `-1` when absent), host off/len, port (`-1` when absent),
/// path off/len (len `-1` when absent). Returns `0` to defer to PHP and `-1` for invalid pointers.
///
/// # Safety
/// `input` must reference `len` readable bytes and `out` must reference 7 writable `int64_t`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_manticore_cfg_parse_host(
    input: *const u8,
    len: usize,
    out: *mut i64,
) -> i32 {
    let Some(input) = (unsafe { w12_input(input, len) }) else {
        return -1;
    };
    if out.is_null() {
        return -1;
    }
    let mut fields = [0i64, -1, 0, 0, -1, 0, -1];
    let mut pos = 0usize;
    if let Some(sep) = input.windows(3).position(|w| w == b"://") {
        let scheme = &input[..sep];
        let valid = scheme.first().is_some_and(u8::is_ascii_alphabetic)
            && scheme
                .iter()
                .all(|&b| b.is_ascii_alphanumeric() || matches!(b, b'+' | b'.' | b'-'));
        if !valid {
            return 0;
        }
        fields[0] = 0;
        fields[1] = sep as i64;
        pos = sep + 3;
    }
    let host_start = pos;
    while pos < input.len()
        && (input[pos].is_ascii_alphanumeric() || matches!(input[pos], b'.' | b'_' | b'-'))
    {
        pos += 1;
    }
    // `empty($parts['host'])` also rejects the host `"0"`.
    if pos == host_start || &input[host_start..pos] == b"0" {
        return 0;
    }
    fields[2] = host_start as i64;
    fields[3] = (pos - host_start) as i64;
    if pos < input.len() && input[pos] == b':' {
        pos += 1;
        let port_start = pos;
        while pos < input.len() && input[pos].is_ascii_digit() {
            pos += 1;
        }
        let digits = &input[port_start..pos];
        if digits.is_empty() || digits.len() > 5 || (digits.len() > 1 && digits[0] == b'0') {
            return 0;
        }
        let port = digits
            .iter()
            .fold(0i64, |acc, &d| acc * 10 + (d - b'0') as i64);
        if port > 65535 {
            return 0;
        }
        fields[4] = port;
    }
    if pos < input.len() {
        if input[pos] != b'/' {
            return 0;
        }
        let path = &input[pos..];
        if !path
            .iter()
            .all(|&b| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'/' | b'-'))
        {
            return 0;
        }
        // `//host//x` style paths and `:` in later segments are left to PHP's parser.
        if path.starts_with(b"//") {
            return 0;
        }
        fields[5] = pos as i64;
        fields[6] = path.len() as i64;
    }
    unsafe { std::ptr::copy_nonoverlapping(fields.as_ptr(), out, fields.len()) };
    1
}

#[cfg(test)]
mod wave13_rss_reconcile_tests {
    use super::*;

    fn buf_call(f: impl Fn(*mut u8, usize) -> isize) -> Result<Vec<u8>, isize> {
        let mut out = vec![0u8; 4096];
        let n = f(out.as_mut_ptr(), out.len());
        if n < 0 {
            return Err(n);
        }
        out.truncate(n as usize);
        Ok(out)
    }

    #[test]
    fn category_ids_filter_and_resize() {
        let parse = |s: &[u8]| {
            buf_call(|o, c| unsafe { nntmux_rss_parse_category_ids(s.as_ptr(), s.len(), o, c) })
        };
        assert_eq!(
            parse(b" 5000, -2 ,abc,,5040\n,\x0c7").unwrap(),
            b"5000,-2,5040"
        );
        assert_eq!(parse(b"").unwrap(), b"");
        assert_eq!(parse(b"-,--1,1-").unwrap(), b"");
        let mut tiny = [0u8; 2];
        let s = b"5000,5040";
        assert_eq!(
            unsafe { nntmux_rss_parse_category_ids(s.as_ptr(), s.len(), tiny.as_mut_ptr(), 2) },
            -9
        );
        assert_eq!(
            unsafe { nntmux_rss_parse_category_ids(std::ptr::null(), 3, tiny.as_mut_ptr(), 2) },
            -1
        );
    }

    #[test]
    fn numeric_classification() {
        let show = |s: &[u8]| {
            let mut v = 0i64;
            let rc = unsafe { nntmux_rss_show_id(s.as_ptr(), s.len(), &mut v) };
            (rc, v)
        };
        assert_eq!(show(b"42"), (1, 42));
        assert_eq!(show(b"-7"), (1, -7));
        assert_eq!(show(b"0"), (1, -1));
        assert_eq!(show(b"abc"), (1, -1));
        assert_eq!(show(b""), (1, -1));
        assert_eq!(show(b"1.5").0, 0);
        assert_eq!(show(b"5abc").0, 0);
        assert_eq!(show(b"99999999999999999999").0, 0);
        let abs = |s: &[u8]| {
            let mut v = 0i64;
            let rc = unsafe { nntmux_rss_abs_numeric_int(s.as_ptr(), s.len(), &mut v) };
            (rc, v)
        };
        assert_eq!(abs(b" -12 "), (1, 12));
        assert_eq!(abs(b"x"), (2, 0));
        assert_eq!(abs(b"1e3").0, 0);
        assert_eq!(abs(b"-9223372036854775808").0, 0);
        assert_eq!(abs(b"1e").0, 2);
    }

    #[test]
    fn since_window() {
        let since = |s: &[u8]| {
            let mut v = 0i64;
            let rc = unsafe { nntmux_reconcile_parse_since(s.as_ptr(), s.len(), &mut v) };
            (rc, v)
        };
        assert_eq!(since(b" 24h\n"), (1, 24));
        assert_eq!(since(b"7D"), (2, 7));
        assert_eq!(since(b"h"), (0, 0));
        assert_eq!(since(b"2w"), (0, 0));
        assert_eq!(since(b"99999999999999999999h"), (1, i64::MAX));
    }

    #[test]
    fn missing_ids_and_probe_sql() {
        let pack = |v: &[i64]| v.iter().flat_map(|x| x.to_ne_bytes()).collect::<Vec<u8>>();
        let batch = pack(&[5, 3, 9, 3, 1]);
        let indexed = pack(&[9, 1, 42]);
        let got = buf_call(|o, c| unsafe {
            nntmux_reconcile_missing_ids(batch.as_ptr(), 5, indexed.as_ptr(), 3, o, c)
        })
        .unwrap();
        assert_eq!(got, pack(&[5, 3, 3]));
        let ids = pack(&[1, 22, -3]);
        let sql = buf_call(|o, c| unsafe {
            nntmux_reconcile_probe_sql(b"rel`x".as_ptr(), 5, ids.as_ptr(), 3, o, c)
        })
        .unwrap();
        assert_eq!(
            sql,
            b"SELECT id FROM `rel``x` WHERE id IN (1,22,-3) LIMIT 3 OPTION max_matches=3"
        );
    }

    #[test]
    fn format_bytes_and_round() {
        let fmt = |b: i64| {
            String::from_utf8(
                buf_call(|o, c| unsafe { nntmux_checkindex_format_bytes(b, o, c) }).unwrap(),
            )
            .unwrap()
        };
        assert_eq!(fmt(0), "0 B");
        assert_eq!(fmt(-5), "0 B");
        assert_eq!(fmt(1536), "1.5 KB");
        assert_eq!(fmt(1024 * 1024), "1 MB");
        assert_eq!(w13_php_round2(1.005), 1.01);
        assert_eq!(w13_php_round2(0.285), 0.29);
    }

    #[test]
    fn hosts_and_parse_host() {
        let hosts = |s: &[u8]| {
            buf_call(|o, c| unsafe {
                nntmux_manticore_cfg_normalize_hosts(s.as_ptr(), s.len(), o, c)
            })
            .unwrap()
        };
        assert_eq!(hosts(b" a:1 , 0 ,,b "), b"a:1,b");
        assert_eq!(hosts(b"  "), b"");
        let parse = |s: &[u8]| {
            let mut out = [0i64; 7];
            let rc =
                unsafe { nntmux_manticore_cfg_parse_host(s.as_ptr(), s.len(), out.as_mut_ptr()) };
            (rc, out)
        };
        assert_eq!(
            parse(b"https://m.local:9443/api").1,
            [0, 5, 8, 7, 9443, 20, 4]
        );
        assert_eq!(parse(b"h").1, [0, -1, 0, 1, -1, 0, -1]);
        assert_eq!(parse(b"h:009").0, 0);
        assert_eq!(parse(b"h:65536").0, 0);
        assert_eq!(parse(b"[::1]:9308").0, 0);
        assert_eq!(parse(b"1x://h").0, 0);
        assert_eq!(parse(b"0:9308").0, 0);
    }
}

// Wave 14 diagnostics/log-viewer candidates. The PHP adapters retain the checked-in
// contracts (including keyed SQL rows, CRC32 composite keys and one-decimal sizes).
// Buffer results: -1 invalid input, -2 panic, -(required + 3) insufficient space.
unsafe fn w14_input<'a>(ptr: *const u8, len: usize) -> Option<&'a [u8]> {
    if len > isize::MAX as usize
        || (len != 0 && ptr.is_null())
        || (ptr as usize).checked_add(len).is_none()
    {
        return None;
    }
    Some(if len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(ptr, len) }
    })
}

fn w14_output(bytes: &[u8], out: *mut u8, cap: usize) -> isize {
    if cap > isize::MAX as usize
        || (out.is_null() && cap != 0)
        || (out as usize).checked_add(cap).is_none()
        || bytes.len() > isize::MAX as usize - 3
    {
        return -1;
    }
    if cap < bytes.len() {
        return -(bytes.len() as isize + 3);
    }
    if !bytes.is_empty() {
        // `ptr::copy` also permits callers to reuse the input allocation for output.
        unsafe { std::ptr::copy(bytes.as_ptr(), out, bytes.len()) };
    }
    bytes.len() as isize
}

// A dependency-free, depth-limited JSON walker. Raw spans preserve SQL row values
// and object insertion order; strings are decoded for diagnostic comparisons.
#[derive(Debug)]
struct W14Json<'a> {
    raw: &'a [u8],
    text: Option<String>,
    entries: Option<Vec<(String, W14Json<'a>)>>,
    object: bool,
}

impl<'a> W14Json<'a> {
    fn parse(s: &'a [u8]) -> Option<Self> {
        let mut p = 0;
        let value = Self::value(s, &mut p, 0)?;
        Self::ws(s, &mut p);
        (p == s.len()).then_some(value)
    }

    fn ws(s: &[u8], p: &mut usize) {
        while s
            .get(*p)
            .is_some_and(|b| matches!(b, b' ' | b'\t' | b'\r' | b'\n'))
        {
            *p += 1;
        }
    }

    fn string(s: &[u8], p: &mut usize) -> Option<String> {
        if s.get(*p) != Some(&b'"') {
            return None;
        }
        *p += 1;
        let mut bytes = Vec::new();
        loop {
            let b = *s.get(*p)?;
            *p += 1;
            match b {
                b'"' => return String::from_utf8(bytes).ok(),
                b'\\' => {
                    let escape = *s.get(*p)?;
                    *p += 1;
                    match escape {
                        b'"' | b'\\' | b'/' => bytes.push(escape),
                        b'b' => bytes.push(8),
                        b'f' => bytes.push(12),
                        b'n' => bytes.push(b'\n'),
                        b'r' => bytes.push(b'\r'),
                        b't' => bytes.push(b'\t'),
                        b'u' => {
                            let hex = |p: &mut usize| -> Option<u32> {
                                let mut n = 0;
                                for &d in s.get(*p..p.checked_add(4)?)? {
                                    n = n * 16 + (d as char).to_digit(16)?;
                                }
                                *p += 4;
                                Some(n)
                            };
                            let mut code = hex(p)?;
                            if (0xd800..=0xdbff).contains(&code) {
                                if s.get(*p..p.checked_add(2)?)? != b"\\u" {
                                    return None;
                                }
                                *p += 2;
                                let low = hex(p)?;
                                if !(0xdc00..=0xdfff).contains(&low) {
                                    return None;
                                }
                                code = 0x10000 + ((code - 0xd800) << 10) + low - 0xdc00;
                            }
                            let mut buf = [0; 4];
                            bytes.extend_from_slice(
                                char::from_u32(code)?.encode_utf8(&mut buf).as_bytes(),
                            );
                        }
                        _ => return None,
                    }
                }
                0..=31 => return None,
                _ => bytes.push(b),
            }
        }
    }

    fn value(s: &'a [u8], p: &mut usize, depth: usize) -> Option<Self> {
        if depth > 64 {
            return None;
        }
        Self::ws(s, p);
        let start = *p;
        let mut value = Self {
            raw: &[],
            text: None,
            entries: None,
            object: false,
        };
        match *s.get(*p)? {
            b'"' => value.text = Some(Self::string(s, p)?),
            open @ (b'[' | b'{') => {
                value.object = open == b'{';
                let close = if value.object { b'}' } else { b']' };
                *p += 1;
                Self::ws(s, p);
                let mut entries = Vec::new();
                if s.get(*p) != Some(&close) {
                    loop {
                        Self::ws(s, p);
                        let key = if value.object {
                            let key = Self::string(s, p)?;
                            Self::ws(s, p);
                            if s.get(*p) != Some(&b':') {
                                return None;
                            }
                            *p += 1;
                            key
                        } else {
                            entries.len().to_string()
                        };
                        entries.push((key, Self::value(s, p, depth + 1)?));
                        Self::ws(s, p);
                        if s.get(*p) != Some(&b',') {
                            break;
                        }
                        *p += 1;
                    }
                }
                if s.get(*p) != Some(&close) {
                    return None;
                }
                *p += 1;
                value.entries = Some(entries);
            }
            b'n' | b't' | b'f' => {
                let literal: &[u8] = match s[*p] {
                    b'n' => b"null",
                    b't' => b"true",
                    _ => b"false",
                };
                if s.get(*p..p.checked_add(literal.len())?)? != literal {
                    return None;
                }
                *p += literal.len();
            }
            b'-' | b'0'..=b'9' => {
                if s[*p] == b'-' {
                    *p += 1;
                }
                match s.get(*p)? {
                    b'0' => *p += 1,
                    b'1'..=b'9' => {
                        while s.get(*p).is_some_and(u8::is_ascii_digit) {
                            *p += 1;
                        }
                    }
                    _ => return None,
                }
                if s.get(*p) == Some(&b'.') {
                    *p += 1;
                    if !s.get(*p).is_some_and(u8::is_ascii_digit) {
                        return None;
                    }
                    while s.get(*p).is_some_and(u8::is_ascii_digit) {
                        *p += 1;
                    }
                }
                if matches!(s.get(*p), Some(b'e' | b'E')) {
                    *p += 1;
                    if matches!(s.get(*p), Some(b'+' | b'-')) {
                        *p += 1;
                    }
                    if !s.get(*p).is_some_and(u8::is_ascii_digit) {
                        return None;
                    }
                    while s.get(*p).is_some_and(u8::is_ascii_digit) {
                        *p += 1;
                    }
                }
            }
            _ => return None,
        }
        value.raw = &s[start..*p];
        Some(value)
    }

    fn get(&self, key: &str) -> Option<&Self> {
        self.entries
            .as_ref()?
            .iter()
            .rev()
            .find(|(k, _)| k == key)
            .map(|(_, v)| v)
    }

    fn integer(&self) -> Option<i64> {
        std::str::from_utf8(self.raw).ok()?.parse().ok()
    }
}

/// Compare normalized expected/index fields. JSON input is
/// [hidden, expected-string-fields, index-string-fields, dbPost, ixPost, dbAdd, ixAdd]
/// or null for a missing index row. Output is [status, mismatched-field-names].
/// # Safety
/// Input/output allocations must contain the declared readable/writable byte counts.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_diag_classify_json(
    input: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(bytes) = (unsafe { w14_input(input, len) }) else {
            return -1;
        };
        let Some(root) = W14Json::parse(bytes) else {
            return -1;
        };
        if root.raw == b"null" {
            return w14_output(b"[\"MISSING_IN_INDEX\",[]]", out, cap);
        }
        let Some(fields) = root.entries.as_ref() else {
            return -1;
        };
        if root.object || fields.len() != 7 {
            return -1;
        }
        let Some(hidden) = root.get("0") else {
            return -1;
        };
        if hidden.raw != b"true" && hidden.raw != b"false" {
            return -1;
        }
        let Some(expected) = root.get("1").and_then(|v| v.entries.as_ref()) else {
            return -1;
        };
        let Some(index) = root.get("2").and_then(|v| v.entries.as_ref()) else {
            return -1;
        };
        if expected.len() != 7 || index.len() != 7 {
            return -1;
        }
        let names = [
            "searchname",
            "name",
            "categories_id",
            "passwordstatus",
            "size",
            "groups_id",
            "nzbstatus",
            "postdate_ts",
            "adddate_ts",
        ];
        let mut mismatches = Vec::new();
        for i in 0..7 {
            let (Some(db), Some(ix)) = (&expected[i].1.text, &index[i].1.text) else {
                return -1;
            };
            if db != ix {
                mismatches.push(names[i]);
            }
        }
        for (a, b, name) in [("3", "4", names[7]), ("5", "6", names[8])] {
            let (Some(db), Some(ix)) = (
                root.get(a).and_then(W14Json::integer),
                root.get(b).and_then(W14Json::integer),
            ) else {
                return -1;
            };
            if (i128::from(db) - i128::from(ix)).abs() > 2 {
                mismatches.push(name);
            }
        }
        let status = if !mismatches.is_empty() {
            "STALE_INDEX"
        } else if hidden.raw == b"true" {
            "STATUS_HIDDEN"
        } else {
            "OK"
        };
        let quoted = mismatches
            .iter()
            .map(|s| format!("\"{s}\""))
            .collect::<Vec<_>>()
            .join(",");
        w14_output(format!("[\"{status}\",[{quoted}]]").as_bytes(), out, cap)
    })
    .unwrap_or(-2)
}

/// Reconstruct keyed raw Manticore SQL rows, preserving the existing data fallback.
/// # Safety
/// Input/output allocations must contain the declared readable/writable byte counts.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_diag_extract_sql_rows_json(
    input: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(bytes) = (unsafe { w14_input(input, len) }) else {
            return -1;
        };
        let Some(root) = W14Json::parse(bytes) else {
            return -1;
        };
        let mut rows = Vec::new();
        if let Some(entries) = &root.entries {
            for (key, value) in entries {
                // PHP integer keys include negatives, but numeric string keys only digits.
                let integer_key = key.parse::<i64>().ok().filter(|n| n.to_string() == *key);
                if integer_key.is_none()
                    && (key.is_empty() || !key.bytes().all(|b| b.is_ascii_digit()))
                {
                    continue;
                }
                let id = integer_key.unwrap_or_else(|| key.parse::<i64>().unwrap_or(i64::MAX));
                if value.entries.is_some() {
                    if value.get("id").is_some_and(|id| id.raw != b"null") {
                        rows.push(value.raw.to_vec());
                    } else if value.object {
                        // Replace a null id in-place as PHP's isset()/assignment does.
                        let mut row = value.raw[..value.raw.len() - 1].to_vec();
                        if value.entries.as_ref().is_some_and(|e| !e.is_empty()) {
                            row.push(b',');
                        }
                        row.extend_from_slice(format!("\"id\":{id}}}").as_bytes());
                        rows.push(row);
                    } else {
                        let mut row = Vec::from(&b"{"[..]);
                        for (i, (_, v)) in value.entries.as_ref().unwrap().iter().enumerate() {
                            if i != 0 {
                                row.push(b',');
                            }
                            row.extend_from_slice(format!("\"{i}\":").as_bytes());
                            row.extend_from_slice(v.raw);
                        }
                        if value.entries.as_ref().is_some_and(|e| !e.is_empty()) {
                            row.push(b',');
                        }
                        row.extend_from_slice(format!("\"id\":{id}}}").as_bytes());
                        rows.push(row);
                    }
                } else {
                    rows.push(format!("{{\"id\":{id}}}").into_bytes());
                }
            }
            if rows.is_empty() {
                if let Some(data) = root.get("data").and_then(|v| v.entries.as_ref()) {
                    rows.extend(data.iter().map(|(_, v)| v.raw.to_vec()));
                }
            }
        }
        let mut result = vec![b'['];
        for (i, row) in rows.iter().enumerate() {
            if i != 0 {
                result.push(b',');
            }
            result.extend_from_slice(row);
        }
        result.push(b']');
        w14_output(&result, out, cap)
    })
    .unwrap_or(-2)
}

/// Delay used by the existing repair command; the caller adds it to now().
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_search_repair_backoff(attempts: i64) -> f64 {
    std::panic::catch_unwind(|| {
        if attempts < -1074 {
            0.0
        } else {
            2.0_f64.powi(attempts.min(10) as i32)
        }
    })
    .unwrap_or(-1.0)
}

/// Resolve a physical table name. Configured aliases are handled by the PHP caller.
/// # Safety
/// Input/output allocations must contain the declared readable/writable byte counts.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_manticore_registry_logical_name(
    input: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(table) = (unsafe { w14_input(input, len) }) else {
            return -1;
        };
        let names: &[&[u8]] = &[
            b"releases",
            b"predb",
            b"movies",
            b"tvshows",
            b"music",
            b"books",
            b"games",
            b"console",
            b"steam",
            b"anime",
        ];
        let logical = table
            .strip_suffix(b"_rt")
            .filter(|s| names.contains(s))
            .unwrap_or(b"");
        w14_output(logical, out, cap)
    })
    .unwrap_or(-2)
}

/// CRC32 of PHP's composite `anidbid|type|lang|title` payload, as an unsigned ID.
/// # Safety
/// Input must contain `len` readable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_secondary_index_anime_doc_id(input: *const u8, len: usize) -> i64 {
    std::panic::catch_unwind(|| {
        let Some(bytes) = (unsafe { w14_input(input, len) }) else {
            return -1;
        };
        let mut crc = !0u32;
        for &b in bytes {
            crc ^= u32::from(b);
            for _ in 0..8 {
                crc = (crc >> 1) ^ (0xedb88320 & 0u32.wrapping_sub(crc & 1));
            }
        }
        i64::from(!crc)
    })
    .unwrap_or(-1)
}

/// LogFile's one-decimal number_format(), including grouping and negative byte counts.
/// # Safety
/// Output allocation must contain `cap` writable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_viewer_format_bytes(
    bytes: i64,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let text = if bytes < 1024 {
            format!("{bytes} B")
        } else {
            let units = ["KB", "MB", "GB", "TB"];
            let mut value = bytes as f64 / 1024.0;
            let mut unit = 0;
            while value >= 1024.0 && unit < 3 {
                value /= 1024.0;
                unit += 1;
            }
            // PHP 8.4+ compares the original value with the decimal half edge.
            // Adding an epsilon would incorrectly round values just below a tie.
            let mut integral = (value * 10.0).floor();
            if (integral + 1.0) / 10.0 == value {
                integral += 1.0;
            }
            if value >= (integral + 0.5) / 10.0 {
                integral += 1.0;
            }
            let rounded = integral / 10.0;
            let plain = format!("{rounded:.1}");
            let dot = plain.find('.').unwrap();
            let mut grouped = String::new();
            for (i, b) in plain.bytes().enumerate() {
                if i != 0 && i < dot && (dot - i).is_multiple_of(3) {
                    grouped.push(',');
                }
                grouped.push(b as char);
            }
            format!("{grouped} {}", units[unit])
        };
        w14_output(text.as_bytes(), out, cap)
    })
    .unwrap_or(-2)
}

/// `ltrim(str_replace('\\', '/', trim(path)), '/')`, byte-for-byte PHP trim semantics.
/// # Safety
/// Input/output allocations must contain the declared readable/writable byte counts.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_viewer_normalize_path(
    input: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(bytes) = (unsafe { w14_input(input, len) }) else {
            return -1;
        };
        let original = bytes;
        let trim = |b: &u8| matches!(*b, b' ' | b'\t' | b'\n' | b'\r' | 0 | 11);
        let start = original
            .iter()
            .position(|b| !trim(b))
            .unwrap_or(original.len());
        let end = original
            .iter()
            .rposition(|b| !trim(b))
            .map_or(start, |p| p + 1);
        let normalized: Vec<u8> = original[start..end]
            .iter()
            .map(|&b| if b == b'\\' { b'/' } else { b })
            .collect();
        let start = normalized
            .iter()
            .position(|&b| b != b'/')
            .unwrap_or(normalized.len());
        w14_output(&normalized[start..], out, cap)
    })
    .unwrap_or(-2)
}

/// Inspect an already-read (at most 8192-byte) sample using the existing Monolog parser.
/// # Safety
/// Input must contain `len` readable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_viewer_looks_structured(input: *const u8, len: usize) -> i32 {
    std::panic::catch_unwind(|| {
        let Some(sample) = (unsafe { w14_input(input, len) }) else {
            return -1;
        };
        let sample = &sample[..sample.len().min(8192)];
        for line in sample.split(|&b| b == b'\n') {
            let end = line.iter().rposition(|&b| b != b'\r').map_or(0, |p| p + 1);
            let line = &line[..end];
            let parsed =
                unsafe { crate::media::nntmux_log_parse_header_spans(line.as_ptr(), line.len()) };
            // -2 means recognition succeeded, but the spans exceed the packed ABI.
            // Sniffing also recognizes headers with long fractional timestamps.
            if parsed >= 0 || parsed == -2 {
                return 1;
            }
        }
        0
    })
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave14_search_diag_logviewer_tests {
    use super::*;

    fn text(f: impl FnOnce(*mut u8, usize) -> isize) -> String {
        let mut out = [0; 4096];
        let n = f(out.as_mut_ptr(), out.len());
        assert!(n >= 0, "unexpected ABI result {n}");
        String::from_utf8(out[..n as usize].to_vec()).unwrap()
    }

    #[test]
    fn errors_lengths_resize_and_empty_buffers() {
        unsafe {
            assert_eq!(
                nntmux_log_viewer_normalize_path(std::ptr::null(), 1, std::ptr::null_mut(), 0),
                -1
            );
            assert_eq!(
                nntmux_log_viewer_normalize_path(
                    b"a".as_ptr(),
                    usize::MAX,
                    std::ptr::null_mut(),
                    0
                ),
                -1
            );
            assert_eq!(
                nntmux_log_viewer_normalize_path(std::ptr::null(), 0, std::ptr::null_mut(), 0),
                0
            );
            assert_eq!(
                nntmux_log_viewer_normalize_path(b"a".as_ptr(), 1, std::ptr::null_mut(), 1),
                -1
            );
            assert_eq!(
                nntmux_log_viewer_normalize_path(b"abc".as_ptr(), 3, std::ptr::null_mut(), 0),
                -6
            );
            assert_eq!(
                nntmux_log_viewer_format_bytes(0, std::ptr::null_mut(), usize::MAX),
                -1
            );
            assert_eq!(nntmux_secondary_index_anime_doc_id(std::ptr::null(), 5), -1);
            assert_eq!(nntmux_log_viewer_looks_structured(std::ptr::null(), 3), -1);
            assert_eq!(
                nntmux_search_diag_classify_json(b"?".as_ptr(), 1, std::ptr::null_mut(), 0),
                -1
            );
            assert_eq!(
                nntmux_search_diag_extract_sql_rows_json(b"[".as_ptr(), 1, std::ptr::null_mut(), 0),
                -1
            );
        }
        for input in [
            "[1,]",
            "{\"x\":01}",
            "\"\\ud800\"",
            "\"\\udc00\"",
            "[true]x",
            "1e",
            "1.",
            "\"\n\"",
        ] {
            assert!(W14Json::parse(input.as_bytes()).is_none(), "{input}");
        }
        let deep = format!("{}0{}", "[".repeat(66), "]".repeat(66));
        assert!(W14Json::parse(deep.as_bytes()).is_none());
        assert_eq!(
            W14Json::parse(b"\"\\ud83d\\ude00\"")
                .unwrap()
                .text
                .as_deref(),
            Some("😀")
        );
    }

    #[test]
    fn keyed_sql_and_classify() {
        let input = br#"{"42":{"name":"Hello"},"3":3,"data":[{"id":9}]}"#;
        assert_eq!(
            text(|out, cap| unsafe {
                nntmux_search_diag_extract_sql_rows_json(input.as_ptr(), input.len(), out, cap)
            }),
            r#"[{"name":"Hello","id":42},{"id":3}]"#
        );
        let input = br#"{"data":{"x":{"id":9}}}"#;
        assert_eq!(
            text(|out, cap| unsafe {
                nntmux_search_diag_extract_sql_rows_json(input.as_ptr(), input.len(), out, cap)
            }),
            r#"[{"id":9}]"#
        );
        let input =
            br#"[false,["a","b","1","0","42","7","1"],["a","b","1","0","42","7","1"],1,3,2,2]"#;
        assert_eq!(
            text(|out, cap| unsafe {
                nntmux_search_diag_classify_json(input.as_ptr(), input.len(), out, cap)
            }),
            r#"["OK",[]]"#
        );
        let input =
            br#"[true,["a","b","1","0","42","7","1"],["z","b","1","0","42","7","1"],1,4,2,2]"#;
        assert_eq!(
            text(|out, cap| unsafe {
                nntmux_search_diag_classify_json(input.as_ptr(), input.len(), out, cap)
            }),
            r#"["STALE_INDEX",["searchname","postdate_ts"]]"#
        );
    }

    #[test]
    fn existing_php_scalar_contracts() {
        assert_eq!(nntmux_search_repair_backoff(-1), 0.5);
        assert_eq!(nntmux_search_repair_backoff(0), 1.0);
        assert_eq!(nntmux_search_repair_backoff(i64::MAX), 1024.0);
        assert_eq!(
            unsafe { nntmux_secondary_index_anime_doc_id(b"123456789".as_ptr(), 9) },
            0xcbf43926
        );
        assert_eq!(
            text(|out, cap| unsafe { nntmux_log_viewer_format_bytes(-1, out, cap) }),
            "-1 B"
        );
        assert_eq!(
            text(|out, cap| unsafe { nntmux_log_viewer_format_bytes(1536, out, cap) }),
            "1.5 KB"
        );
        assert_eq!(
            text(|out, cap| unsafe { nntmux_log_viewer_format_bytes(i64::MAX, out, cap) }),
            "8,388,608.0 TB"
        );
        assert_eq!(
            text(|out, cap| unsafe { nntmux_log_viewer_format_bytes(1125844931261235, out, cap) }),
            "1,023.9 TB"
        );
        let input = b" \t/\\foo\\bar.log\0\x0b";
        assert_eq!(
            text(|out, cap| unsafe {
                nntmux_log_viewer_normalize_path(input.as_ptr(), input.len(), out, cap)
            }),
            "foo/bar.log"
        );
        let input = b"\x0c/foo\x0c";
        assert_eq!(
            text(|out, cap| unsafe {
                nntmux_log_viewer_normalize_path(input.as_ptr(), input.len(), out, cap)
            }),
            "\x0c/foo\x0c"
        );
        let input = b"console\n[2026-10-24 12:00:00] local.INFO: yes\r\r\n";
        assert_eq!(
            unsafe { nntmux_log_viewer_looks_structured(input.as_ptr(), input.len()) },
            1
        );
        let fractional = format!(
            "[2026-10-24 10:00:00.{}] local.INFO: hello",
            "1".repeat(300)
        );
        assert_eq!(
            unsafe { nntmux_log_viewer_looks_structured(fractional.as_ptr(), fractional.len()) },
            1
        );
        let mut sample = vec![b'x'; 8192];
        sample.extend_from_slice(input);
        assert_eq!(
            unsafe { nntmux_log_viewer_looks_structured(sample.as_ptr(), sample.len()) },
            0
        );
    }
}

// Wave 15 cursor/log-index/suggest/list-request candidates. Every export validates
// raw pointers before building slices and catches panics. Status -1 means the input
// is outside the supported subset, so the PHP adapter falls back to its own path.
unsafe fn w15_slice<'a, T>(ptr: *const T, count: usize) -> Option<&'a [T]> {
    let bytes = count.checked_mul(size_of::<T>())?;
    if bytes > isize::MAX as usize
        || (count != 0 && (ptr.is_null() || !(ptr as usize).is_multiple_of(align_of::<T>())))
        || (ptr as usize).checked_add(bytes).is_none()
    {
        return None;
    }
    Some(if count == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(ptr, count) }
    })
}

unsafe fn w15_slice_mut<'a, T>(ptr: *mut T, count: usize) -> Option<&'a mut [T]> {
    let bytes = count.checked_mul(size_of::<T>())?;
    if bytes > isize::MAX as usize
        || (count != 0 && (ptr.is_null() || !(ptr as usize).is_multiple_of(align_of::<T>())))
        || (ptr as usize).checked_add(bytes).is_none()
    {
        return None;
    }
    Some(if count == 0 {
        &mut []
    } else {
        unsafe { slice::from_raw_parts_mut(ptr, count) }
    })
}

/// PHP `json_encode()` default string escaping; None for invalid UTF-8.
fn w15_json_string(bytes: &[u8], out: &mut Vec<u8>) -> Option<()> {
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let text = std::str::from_utf8(bytes).ok()?;
    out.push(b'"');
    let unit = |out: &mut Vec<u8>, u: u16| {
        out.extend_from_slice(b"\\u");
        for shift in [12, 8, 4, 0] {
            out.push(HEX[usize::from((u >> shift) & 15)]);
        }
    };
    for c in text.chars() {
        match c {
            '"' => out.extend_from_slice(b"\\\""),
            '\\' => out.extend_from_slice(b"\\\\"),
            '/' => out.extend_from_slice(b"\\/"),
            '\u{8}' => out.extend_from_slice(b"\\b"),
            '\u{c}' => out.extend_from_slice(b"\\f"),
            '\n' => out.extend_from_slice(b"\\n"),
            '\r' => out.extend_from_slice(b"\\r"),
            '\t' => out.extend_from_slice(b"\\t"),
            c if (c as u32) < 0x20 || (c as u32) > 0x7f => {
                let mut units = [0; 2];
                for &u in c.encode_utf16(&mut units).iter() {
                    unit(out, u);
                }
            }
            c => out.push(c as u8),
        }
    }
    out.push(b'"');
    Some(())
}

fn w15_take<'a>(s: &'a [u8], p: &mut usize, stop: u8) -> Option<&'a [u8]> {
    let rest = s.get(*p..)?;
    let at = rest.iter().position(|&b| b == stop)?;
    *p += at + 1;
    Some(&rest[..at])
}

fn w15_int(raw: &[u8]) -> Option<i64> {
    std::str::from_utf8(raw).ok()?.parse().ok()
}

/// Build the cursor JSON payload. Input is a packed record:
/// `total\nexpires\n` then query/driver/generation as `len\nbytes`, then each sort
/// value as `i<int>\n` or `s<len>\n<bytes>`.
/// # Safety
/// Input/output allocations must contain the declared readable/writable byte counts.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_cursor_encode_payload(
    input: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(s) = (unsafe { w14_input(input, len) }) else {
            return -1;
        };
        let encode = || -> Option<Vec<u8>> {
            let mut p = 0;
            let total = w15_int(w15_take(s, &mut p, b'\n')?)?;
            let expires = w15_int(w15_take(s, &mut p, b'\n')?)?;
            let mut strings = [&[][..]; 3];
            for slot in &mut strings {
                let n: usize = std::str::from_utf8(w15_take(s, &mut p, b'\n')?)
                    .ok()?
                    .parse()
                    .ok()?;
                *slot = s.get(p..p.checked_add(n)?)?;
                p += n;
            }
            let mut json = Vec::with_capacity(len + 96);
            json.extend_from_slice(b"{\"sort\":[");
            let mut first = true;
            while p < s.len() {
                if !first {
                    json.push(b',');
                }
                first = false;
                let tag = s[p];
                p += 1;
                match tag {
                    b'i' => {
                        let value = w15_int(w15_take(s, &mut p, b'\n')?)?;
                        json.extend_from_slice(value.to_string().as_bytes());
                    }
                    b's' => {
                        let n: usize = std::str::from_utf8(w15_take(s, &mut p, b'\n')?)
                            .ok()?
                            .parse()
                            .ok()?;
                        w15_json_string(s.get(p..p.checked_add(n)?)?, &mut json)?;
                        p += n;
                    }
                    _ => return None,
                }
            }
            json.extend_from_slice(b"],\"total\":");
            json.extend_from_slice(total.to_string().as_bytes());
            for (key, value) in [&b",\"query\":"[..], b",\"driver\":", b",\"generation\":"]
                .into_iter()
                .zip(strings)
            {
                json.extend_from_slice(key);
                w15_json_string(value, &mut json)?;
            }
            json.extend_from_slice(b",\"expires\":");
            json.extend_from_slice(expires.to_string().as_bytes());
            json.push(b'}');
            Some(json)
        };
        match encode() {
            Some(json) => w14_output(&json, out, cap),
            None => -1,
        }
    })
    .unwrap_or(-2)
}

/// Decode a decrypted cursor payload. Output uses the encode record layout
/// (`total\nexpires\n`, three `len\nbytes` strings, then typed sort values).
/// Returns -3 (never a short-buffer code) for a payload PHP rejects as invalid/expired,
/// and -1 for anything PHP would cast or reject differently (floats, bools, nested
/// sort values, non-object roots, malformed JSON); the adapter then runs PHP.
/// # Safety
/// Input/output allocations must contain the declared readable/writable byte counts.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_cursor_decode_payload(
    input: *const u8,
    len: usize,
    now: i64,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(s) = (unsafe { w14_input(input, len) }) else {
            return -1;
        };
        let Some(root) = W14Json::parse(s) else {
            return -1;
        };
        if !root.object {
            return -1;
        }
        let present = |key: &str| root.get(key).filter(|v| v.raw != b"null");
        let Some(sort) = present("sort") else {
            return -3;
        };
        let Some(values) = sort.entries.as_ref() else {
            // Scalars are not arrays in PHP either, so this is a deliberate rejection.
            return -3;
        };
        if sort.object {
            return -1;
        }
        let int_field = |key: &str| -> Option<i64> {
            match present(key) {
                None => Some(0),
                Some(v) if v.entries.is_none() && v.text.is_none() => v.integer(),
                Some(_) => None,
            }
        };
        let (Some(total), Some(expires)) = (int_field("total"), int_field("expires")) else {
            return -1;
        };
        if expires < now {
            return -3;
        }
        let mut record = Vec::with_capacity(len);
        record.extend_from_slice(format!("{total}\n{expires}\n").as_bytes());
        for key in ["query", "driver", "generation"] {
            let text = match present(key) {
                None => "",
                Some(v) => match &v.text {
                    Some(text) => text.as_str(),
                    None => return -1,
                },
            };
            record.extend_from_slice(format!("{}\n", text.len()).as_bytes());
            record.extend_from_slice(text.as_bytes());
        }
        for (_, value) in values {
            if let Some(text) = &value.text {
                record.extend_from_slice(format!("s{}\n", text.len()).as_bytes());
                record.extend_from_slice(text.as_bytes());
            } else if value.entries.is_none()
                && let Some(n) = value.integer()
            {
                record.extend_from_slice(format!("i{n}\n").as_bytes());
            } else {
                return -1;
            }
        }
        w14_output(&record, out, cap)
    })
    .unwrap_or(-2)
}

const W15_PROJECTION_MYSQL: [&str; 6] = [
    "COALESCE((SELECT GROUP_CONCAT(rf.name SEPARATOR ' ') FROM release_files rf WHERE rf.releases_id = r.id), '')",
    "COALESCE((SELECT GROUP_CONCAT(ad.audioformat SEPARATOR ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '')",
    "COALESCE((SELECT GROUP_CONCAT(ad.audiochannels SEPARATOR ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '')",
    "COALESCE((SELECT GROUP_CONCAT(ad.audiolanguage SEPARATOR ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '')",
    "COALESCE((SELECT GROUP_CONCAT(rs.subslanguage SEPARATOR ' ') FROM release_subtitles rs WHERE rs.releases_id = r.id), '')",
    "CONCAT(cp.title, ' > ', c.title)",
];

const W15_PROJECTION_SQLITE: [&str; 6] = [
    "COALESCE((SELECT GROUP_CONCAT(rf.name, ' ') FROM release_files rf WHERE rf.releases_id = r.id), '')",
    "COALESCE((SELECT GROUP_CONCAT(ad.audioformat, ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '')",
    "COALESCE((SELECT GROUP_CONCAT(ad.audiochannels, ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '')",
    "COALESCE((SELECT GROUP_CONCAT(ad.audiolanguage, ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '')",
    "COALESCE((SELECT GROUP_CONCAT(rs.subslanguage, ' ') FROM release_subtitles rs WHERE rs.releases_id = r.id), '')",
    "cp.title || ' > ' || c.title",
];

/// Release projection aggregate SQL (filename, audio format/channels/language,
/// subtitle language, category name), NUL-separated.
/// # Safety
/// The output allocation must contain `cap` writable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_projection_aggregates(
    sqlite: u32,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let parts = if sqlite != 0 {
            W15_PROJECTION_SQLITE
        } else {
            W15_PROJECTION_MYSQL
        };
        w14_output(parts.join("\0").as_bytes(), out, cap)
    })
    .unwrap_or(-2)
}

/// `strtotime()` for Monolog/plain log timestamps:
/// `YYYY-MM-DD[T ]HH:MM:SS[.fraction][Z|+HH:MM|+HHMM]`. Returns 1 and writes the
/// Unix seconds, or 0 when PHP must decide (no offset outside UTC, date overflow,
/// out-of-range fields or any other shape).
/// # Safety
/// Input must contain `len` readable bytes and `out` must point to one `int64_t`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_entry_logged_at(
    input: *const u8,
    len: usize,
    default_utc: u32,
    out: *mut i64,
) -> i32 {
    std::panic::catch_unwind(|| {
        let (Some(s), Some(target)) = (unsafe { w14_input(input, len) }, unsafe {
            w15_slice_mut(out, 1)
        }) else {
            return 0;
        };
        let parse = || -> Option<i64> {
            if s.len() < 19
                || s[4] != b'-'
                || s[7] != b'-'
                || !matches!(s[10], b' ' | b'T')
                || s[13] != b':'
                || s[16] != b':'
            {
                return None;
            }
            let year = parse_4digits(&s[0..4])?;
            let month = parse_2digits(&s[5..7])?;
            let day = parse_2digits(&s[8..10])?;
            let hour = i64::from(parse_2digits(&s[11..13])?);
            let minute = i64::from(parse_2digits(&s[14..16])?);
            let second = i64::from(parse_2digits(&s[17..19])?);
            if !(1..=12).contains(&month)
                || day == 0
                || day > days_in_month(year, month)
                || hour > 23
                || minute > 59
                || second > 59
            {
                return None;
            }
            let mut rest = &s[19..];
            if rest.first() == Some(&b'.') {
                let digits = rest[1..].iter().take_while(|b| b.is_ascii_digit()).count();
                if digits == 0 {
                    return None;
                }
                rest = &rest[1 + digits..];
            }
            let offset = match rest {
                [] if default_utc != 0 => 0,
                [b'Z'] => 0,
                [sign @ (b'+' | b'-'), h1, h2, b':', m1, m2]
                | [sign @ (b'+' | b'-'), h1, h2, m1, m2] => {
                    let h = i64::from(parse_2digits(&[*h1, *h2])?);
                    let m = i64::from(parse_2digits(&[*m1, *m2])?);
                    if h > 23 || m > 59 {
                        return None;
                    }
                    let seconds = h * 3600 + m * 60;
                    if *sign == b'+' { seconds } else { -seconds }
                }
                _ => return None,
            };
            Some(
                days_from_civil(year, month, day) * 86400 + hour * 3600 + minute * 60 + second
                    - offset,
            )
        };
        match parse() {
            Some(seconds) => {
                target[0] = seconds;
                1
            }
            None => 0,
        }
    })
    .unwrap_or(0)
}

/// The coordinator's response engine: the single distinct engine, otherwise `mixed`.
/// `engines` holds `count` NUL-separated names.
/// # Safety
/// Input/output allocations must contain the declared readable/writable byte counts.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_search_combined_engine(
    engines: *const u8,
    len: usize,
    count: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(s) = (unsafe { w14_input(engines, len) }) else {
            return -1;
        };
        if count == 0 {
            return w14_output(b"mixed", out, cap);
        }
        let mut parts = s.split(|&b| b == 0);
        let first = parts.next().unwrap_or_default();
        let mut seen = 1;
        let mut single = true;
        for part in parts {
            seen += 1;
            single &= part == first;
        }
        if seen != count {
            return -1;
        }
        w14_output(if single { first } else { b"mixed" }, out, cap)
    })
    .unwrap_or(-2)
}

/// Manticore hit position casts: `[byte_offset, byte_end ?? byte_offset, line_no]`,
/// with non-positive line numbers reported as 0 (PHP null).
/// # Safety
/// `out` must point to three writable, aligned `int64_t` values.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_hit_position(
    offset: i64,
    end: i64,
    has_end: u32,
    line: i64,
    out: *mut i64,
) -> i32 {
    std::panic::catch_unwind(|| {
        let Some(target) = (unsafe { w15_slice_mut(out, 3) }) else {
            return -1;
        };
        target[0] = offset;
        target[1] = if has_end != 0 { end } else { offset };
        target[2] = line.max(0);
        0
    })
    .unwrap_or(-2)
}

/// Stable descending order of suggestion document counts, matching PHP 8's stable
/// `usort($s, fn ($a, $b) => $b['docs'] - $a['docs'])`. Counts beyond +/-2^62 are
/// refused because PHP's subtraction would overflow into a float there.
/// # Safety
/// `docs` and `order` must each hold `count` aligned elements.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_suggest_rank(
    docs: *const i64,
    count: usize,
    order: *mut u32,
) -> i32 {
    std::panic::catch_unwind(|| {
        let (Some(docs), Some(order)) = (unsafe { w15_slice(docs, count) }, unsafe {
            w15_slice_mut(order, count)
        }) else {
            return -1;
        };
        const LIMIT: i64 = 1 << 62;
        if count > u32::MAX as usize || docs.iter().any(|d| !(-LIMIT..=LIMIT).contains(d)) {
            return -1;
        }
        for (i, slot) in order.iter_mut().enumerate() {
            *slot = i as u32;
        }
        order.sort_by(|&a, &b| docs[b as usize].cmp(&docs[a as usize]));
        0
    })
    .unwrap_or(-2)
}

fn w15_php_int_digits(token: &[u8]) -> Option<i64> {
    let (negative, digits) = match token.first()? {
        b'-' => (true, &token[1..]),
        _ => (false, token),
    };
    if digits.is_empty() || !digits.iter().all(u8::is_ascii_digit) {
        return None;
    }
    let mut value: i64 = 0;
    for &d in digits {
        let step = value
            .checked_mul(10)
            .and_then(|v| v.checked_add(i64::from(d - b'0')));
        // Saturate like PHP's numeric-string cast on overflow.
        value = match step {
            Some(v) => v,
            None => return Some(if negative { i64::MIN } else { i64::MAX }),
        };
    }
    Some(if negative { -value } else { value })
}

/// `SearchReleaseListRequest::categoryIds()` for a validated string `t`: `[-1]` for
/// empty/`0`/`-1`, otherwise first-seen unique `intval()` values of each CSV token.
/// Returns the id count; -1 for unsupported tokens; -(needed + 3) for short output.
/// # Safety
/// `input` holds `len` bytes; `out` holds `cap` aligned `int64_t` slots.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_list_category_ids(
    input: *const u8,
    len: usize,
    out: *mut i64,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let (Some(s), Some(out)) = (unsafe { w14_input(input, len) }, unsafe {
            w15_slice_mut(out, cap)
        }) else {
            return -1;
        };
        let mut ids: Vec<i64> = Vec::new();
        if matches!(s, b"" | b"0" | b"-1") {
            ids.push(-1);
        } else {
            for token in s.split(|&b| b == b',') {
                let Some(id) = w15_php_int_digits(token) else {
                    return -1;
                };
                if !ids.contains(&id) {
                    ids.push(id);
                }
            }
        }
        if ids.len() > isize::MAX as usize - 3 {
            return -1;
        }
        if ids.len() > out.len() {
            return -(ids.len() as isize + 3);
        }
        out[..ids.len()].copy_from_slice(&ids);
        ids.len() as isize
    })
    .unwrap_or(-2)
}

fn w15_decimal(s: &[u8]) -> Option<f64> {
    let mut p = usize::from(matches!(s.first(), Some(b'+' | b'-')));
    let int_digits = s[p..].iter().take_while(|b| b.is_ascii_digit()).count();
    p += int_digits;
    let mut frac_digits = 0;
    if s.get(p) == Some(&b'.') {
        p += 1;
        frac_digits = s[p..].iter().take_while(|b| b.is_ascii_digit()).count();
        p += frac_digits;
    }
    if int_digits + frac_digits == 0 {
        return None;
    }
    if matches!(s.get(p), Some(b'e' | b'E')) {
        p += 1;
        p += usize::from(matches!(s.get(p), Some(b'+' | b'-')));
        let exp_digits = s[p..].iter().take_while(|b| b.is_ascii_digit()).count();
        if exp_digits == 0 {
            return None;
        }
        p += exp_digits;
    }
    if p != s.len() {
        return None;
    }
    std::str::from_utf8(s).ok()?.parse().ok()
}

/// Numeric list-request ranges. Input holds NUL-terminated values for
/// `[minsize, maxsize, minfiles, maxfiles, mingrabs, maxgrabs, mincomments,
/// maxcomments]`; flags bit i marks value i as present. Sizes are MB converted with
/// `max(0, (int) round((float) $v * 1048576))`; the rest use `(int) $v` on plain
/// integer strings. Writes eight `int64_t` values; -1 means PHP must decide.
/// # Safety
/// `input` holds `len` bytes and `out` holds eight aligned `int64_t` slots.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_list_ranges(
    input: *const u8,
    len: usize,
    flags: u32,
    out: *mut i64,
) -> i32 {
    std::panic::catch_unwind(|| {
        let (Some(s), Some(out)) = (unsafe { w14_input(input, len) }, unsafe {
            w15_slice_mut(out, 8)
        }) else {
            return -1;
        };
        let mut values = [0i64; 8];
        let mut parts = s.split(|&b| b == 0);
        for (i, slot) in values.iter_mut().enumerate() {
            let Some(raw) = parts.next() else {
                return -1;
            };
            if flags & (1 << i) == 0 {
                continue;
            }
            if i < 2 {
                let Some(mb) = w15_decimal(raw) else {
                    return -1;
                };
                let bytes = mb * 1_048_576.0;
                // PHP's round() edge test is exact only below 2^52.
                if !bytes.is_finite() || bytes.abs() >= 4_503_599_627_370_496.0 {
                    return -1;
                }
                *slot = (bytes.round() as i64).max(0);
            } else {
                let token = raw.strip_prefix(b"+").unwrap_or(raw);
                if token.len() > 18 || (token.len() != raw.len() && token.starts_with(b"-")) {
                    return -1;
                }
                let Some(n) = w15_php_int_digits(token) else {
                    return -1;
                };
                *slot = n;
            }
        }
        out.copy_from_slice(&values);
        0
    })
    .unwrap_or(-1)
}

/// PHP `trim()` spans for a batch of request strings concatenated in `input`.
/// Writes `[start, length]` pairs; length -1 marks an empty trimmed value (null).
/// # Safety
/// `lens` holds `count` aligned `int64_t`; `out` holds `2 * count` aligned slots.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_search_list_trim_spans(
    input: *const u8,
    len: usize,
    lens: *const i64,
    count: usize,
    out: *mut i64,
) -> i32 {
    std::panic::catch_unwind(|| {
        let Some(pairs) = count.checked_mul(2) else {
            return -1;
        };
        let (Some(s), Some(lens), Some(out)) = (
            unsafe { w14_input(input, len) },
            unsafe { w15_slice(lens, count) },
            unsafe { w15_slice_mut(out, pairs) },
        ) else {
            return -1;
        };
        let mut start = 0usize;
        for (i, &n) in lens.iter().enumerate() {
            let Ok(n) = usize::try_from(n) else {
                return -1;
            };
            let Some(end) = start.checked_add(n).filter(|&e| e <= s.len()) else {
                return -1;
            };
            let field = &s[start..end];
            // PHP trim() defaults: space, \t, \n, \r, \0 and \x0B (not \x0C).
            let strip = |b: &u8| matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B);
            let lead = field.iter().take_while(|b| strip(b)).count();
            let trail = field[lead..].iter().rev().take_while(|b| strip(b)).count();
            let trimmed = &field[lead..field.len() - trail];
            if trimmed.is_empty() {
                out[2 * i] = start as i64;
                out[2 * i + 1] = -1;
            } else {
                out[2 * i] = (start + lead) as i64;
                out[2 * i + 1] = trimmed.len() as i64;
            }
            start = end;
        }
        if start != s.len() { -1 } else { 0 }
    })
    .unwrap_or(-2)
}

#[cfg(test)]
mod wave15_tests {
    use super::*;

    fn call(f: impl Fn(*mut u8, usize) -> isize) -> Result<String, isize> {
        let mut buf = vec![0u8; 4096];
        let n = f(buf.as_mut_ptr(), buf.len());
        if n < 0 {
            return Err(n);
        }
        buf.truncate(n as usize);
        Ok(String::from_utf8(buf).unwrap())
    }

    #[test]
    fn cursor_payload_round_trip_matches_php_json() {
        let packed = "250\n4102444800\n10\nquery-hash3\ndrv1\n7i1710000000\ns5\né/\"x";
        let json = call(|o, c| unsafe {
            nntmux_search_cursor_encode_payload(packed.as_ptr(), packed.len(), o, c)
        })
        .unwrap();
        assert_eq!(
            json,
            r#"{"sort":[1710000000,"\u00e9\/\"x"],"total":250,"query":"query-hash","driver":"drv","generation":"7","expires":4102444800}"#
        );
        let record = call(|o, c| unsafe {
            nntmux_search_cursor_decode_payload(json.as_ptr(), json.len(), 1, o, c)
        })
        .unwrap();
        assert_eq!(record, packed);
        let invalid = b"0\n0\n0\n0\n0\ns1\n\xff";
        assert_eq!(
            call(|o, c| unsafe {
                nntmux_search_cursor_encode_payload(invalid.as_ptr(), invalid.len(), o, c)
            }),
            Err(-1)
        );
        for (payload, status) in [
            (r#"{"sort":[1],"expires":5}"#, -3),
            (r#"{"sort":"x","expires":99}"#, -3),
            (r#"{"expires":99}"#, -3),
            (r#"{"sort":[1.5],"expires":99}"#, -1),
            (r#"[1]"#, -1),
            (r#"{"sort":[01],"expires":99}"#, -1),
        ] {
            assert_eq!(
                call(|o, c| unsafe {
                    nntmux_search_cursor_decode_payload(payload.as_ptr(), payload.len(), 10, o, c)
                }),
                Err(status),
                "{payload}"
            );
        }
    }

    #[test]
    fn logged_at_matches_strtotime_samples() {
        let at = |s: &str| {
            let mut out = 0i64;
            let ok = unsafe { nntmux_log_entry_logged_at(s.as_ptr(), s.len(), 1, &mut out) };
            (ok == 1).then_some(out)
        };
        assert_eq!(at("2026-10-24 10:00:00"), Some(1792836000));
        assert_eq!(at("2026-10-24T10:00:00.123+02:00"), Some(1792828800));
        assert_eq!(at("2026-10-24 10:00:00-1130"), Some(1792877400));
        assert_eq!(at("1969-12-31 23:59:59Z"), Some(-1));
        assert_eq!(at("0000-01-01 00:00:00"), Some(-62167219200));
        assert_eq!(at("2026-02-30 10:00:00"), None);
        assert_eq!(at("2026-10-24 24:00:00"), None);
        assert_eq!(at("2026-10-24 10:00:00+02:60"), None);
        let mut out = 0i64;
        let s = "2026-10-24 10:00:00";
        assert_eq!(
            unsafe { nntmux_log_entry_logged_at(s.as_ptr(), s.len(), 0, &mut out) },
            0
        );
        assert_eq!(
            unsafe { nntmux_log_entry_logged_at(s.as_ptr(), s.len(), 1, std::ptr::null_mut()) },
            0
        );
    }

    #[test]
    fn small_helpers_validate_and_match_php() {
        let engines = b"manticore\0manticore";
        assert_eq!(
            call(|o, c| unsafe {
                nntmux_log_search_combined_engine(engines.as_ptr(), 19, 2, o, c)
            }),
            Ok("manticore".into())
        );
        assert_eq!(
            call(|o, c| unsafe {
                nntmux_log_search_combined_engine(engines.as_ptr(), 19, 3, o, c)
            }),
            Err(-1)
        );
        assert_eq!(
            call(|o, c| unsafe { nntmux_log_search_combined_engine(std::ptr::null(), 0, 0, o, c) }),
            Ok("mixed".into())
        );

        let mut pos = [0i64; 3];
        assert_eq!(
            unsafe { nntmux_log_hit_position(5, 0, 0, -2, pos.as_mut_ptr()) },
            0
        );
        assert_eq!(pos, [5, 5, 0]);
        assert_eq!(
            unsafe { nntmux_log_hit_position(5, 0, 0, 1, std::ptr::null_mut()) },
            -1
        );

        let docs = [1i64, 9, 1, 9, 3];
        let mut order = [0u32; 5];
        assert_eq!(
            unsafe { nntmux_search_suggest_rank(docs.as_ptr(), 5, order.as_mut_ptr()) },
            0
        );
        assert_eq!(order, [1, 3, 4, 0, 2]);
        let huge = [i64::MAX, 0];
        assert_eq!(
            unsafe { nntmux_search_suggest_rank(huge.as_ptr(), 2, order.as_mut_ptr()) },
            -1
        );

        let mut ids = [0i64; 4];
        let csv = b"5030,007,7,99999999999999999999";
        assert_eq!(
            unsafe {
                nntmux_search_list_category_ids(csv.as_ptr(), csv.len(), ids.as_mut_ptr(), 4)
            },
            3
        );
        assert_eq!(&ids[..3], &[5030, 7, i64::MAX]);
        assert_eq!(
            unsafe {
                nntmux_search_list_category_ids(csv.as_ptr(), csv.len(), ids.as_mut_ptr(), 1)
            },
            -6
        );
        assert_eq!(
            unsafe { nntmux_search_list_category_ids(b"1,,2".as_ptr(), 4, ids.as_mut_ptr(), 4) },
            -1
        );

        let mut ranges = [0i64; 8];
        let values = b"1.5\0-0.4\0+7\0\0\0\0\0\0";
        assert_eq!(
            unsafe {
                nntmux_search_list_ranges(values.as_ptr(), values.len(), 0b111, ranges.as_mut_ptr())
            },
            0
        );
        assert_eq!(&ranges[..3], &[1572864, 0, 7]);
        let bad = b"inf\0\0\0\0\0\0\0\0";
        assert_eq!(
            unsafe { nntmux_search_list_ranges(bad.as_ptr(), bad.len(), 1, ranges.as_mut_ptr()) },
            -1
        );

        let joined = b" a \x0c\0\t";
        let lens = [3i64, 1, 2];
        let mut spans = [0i64; 6];
        assert_eq!(
            unsafe {
                nntmux_search_list_trim_spans(
                    joined.as_ptr(),
                    6,
                    lens.as_ptr(),
                    3,
                    spans.as_mut_ptr(),
                )
            },
            0
        );
        assert_eq!(spans, [1, 1, 3, 1, 4, -1]);

        let sql = call(|o, c| unsafe { nntmux_search_projection_aggregates(1, o, c) }).unwrap();
        assert_eq!(sql.rsplit('\0').next(), Some("cp.title || ' > ' || c.title"));
    }
}
