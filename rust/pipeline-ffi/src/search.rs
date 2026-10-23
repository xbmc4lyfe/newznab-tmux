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

#[cfg(test)]
mod tests {
    use super::*;

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
}
