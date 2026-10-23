use std::slice;

use crate::OutCursor;

#[inline]
fn is_php_trim_char(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x00 | 0x0B)
}

#[inline]
fn is_pcre_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C)
}

#[inline]
fn is_pcre_word(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_'
}

#[inline]
fn php_trim_ascii(bytes: &[u8]) -> &[u8] {
    let mut start = 0usize;
    let mut end = bytes.len();
    while start < end && is_php_trim_char(bytes[start]) {
        start += 1;
    }
    while end > start && is_php_trim_char(bytes[end - 1]) {
        end -= 1;
    }
    &bytes[start..end]
}

#[inline]
fn find_nzbsplit_from(bytes: &[u8], start: usize) -> Option<usize> {
    if bytes.len() < 12 || start > bytes.len() - 12 {
        return None;
    }
    let mut i = start;
    while i + 12 <= bytes.len() {
        if bytes[i] == b'_'
            && bytes[i + 1] == b'_'
            && bytes[i..i + 12].eq_ignore_ascii_case(b"__nzbsplit__")
        {
            return Some(i);
        }
        i += 1;
    }
    None
}

/// Validates and locates the payload span of `NzbSplitUnwrapper::literalPayload(value)`.
#[inline]
pub fn nzbsplit_literal_payload_span(value: &[u8]) -> Option<&[u8]> {
    let first = find_nzbsplit_from(value, 0)?;
    let second = find_nzbsplit_from(value, first + 1)?;
    if find_nzbsplit_from(value, second + 1).is_some() {
        return None;
    }
    if second < first + 12 + 8 {
        return None;
    }
    let hex_slice = &value[first + 12..second];
    if !hex_slice.iter().all(|b| b.is_ascii_hexdigit()) {
        return None;
    }
    if second + 12 >= value.len() {
        return None;
    }
    Some(&value[second + 12..])
}

/// Writes `NzbSplitUnwrapper::literalPayload(value)` into `out`.
pub(crate) fn nzbsplit_literal_payload(value: &[u8], out: &mut OutCursor<'_>) -> bool {
    let Some(payload) = nzbsplit_literal_payload_span(value) else {
        return false;
    };
    for &b in payload {
        match b {
            b'_' => out.push(b'.'),
            b'+' => out.push(b' '),
            _ => out.push(b),
        }
    }
    true
}

#[inline]
fn matches_archive_suffix_pattern(filename: &[u8]) -> bool {
    if filename.ends_with(b"\n") {
        return false;
    }
    let Some(dot_pos) = filename.iter().rposition(|&b| b == b'.') else {
        return false;
    };
    let ext = &filename[dot_pos + 1..];
    if ext.is_empty() || ext.len() > 8 {
        return false;
    }
    // Check `\.7z\.\d{2,4}$`
    if (2..=4).contains(&ext.len())
        && ext.iter().all(|b| b.is_ascii_digit())
        && dot_pos >= 3
        && filename[dot_pos - 3..dot_pos].eq_ignore_ascii_case(b".7z")
    {
        return true;
    }
    // Check `\.\d{3}$`
    if ext.len() == 3 && ext.iter().all(|b| b.is_ascii_digit()) {
        return true;
    }
    // Check `\.r\d{2,4}$`
    if (3..=5).contains(&ext.len())
        && matches!(ext[0], b'r' | b'R')
        && ext[1..].iter().all(|b| b.is_ascii_digit())
    {
        return true;
    }
    // Check `\.z\d{2}$`
    if ext.len() == 3 && matches!(ext[0], b'z' | b'Z') && ext[1..].iter().all(|b| b.is_ascii_digit())
    {
        return true;
    }
    let mut lower_buf = [0u8; 8];
    for (i, &b) in ext.iter().enumerate() {
        lower_buf[i] = b.to_ascii_lowercase();
    }
    let lower = &lower_buf[..ext.len()];
    matches!(
        lower,
        b"7z"
            | b"rar"
            | b"zip"
            | b"par"
            | b"par2"
            | b"tar"
            | b"gz"
            | b"bz2"
            | b"xz"
            | b"lz"
            | b"lzma"
            | b"cab"
            | b"arj"
            | b"ace"
            | b"arc"
            | b"zst"
            | b"tgz"
            | b"lz4"
            | b"enc"
            | b"rev"
    )
}

#[inline]
fn is_positive_integer_digits(s: &[u8]) -> bool {
    !s.is_empty() && matches!(s[0], b'1'..=b'9') && s[1..].iter().all(|b| b.is_ascii_digit())
}

#[inline]
fn is_valid_yenc_counter_parens(s: &[u8]) -> bool {
    // Matches `\([1-9]\d*(?:\/[1-9]\d*)?\)`
    if s.len() < 3 || s[0] != b'(' || s[s.len() - 1] != b')' {
        return false;
    }
    let inner = &s[1..s.len() - 1];
    if let Some(slash) = inner.iter().position(|&b| b == b'/') {
        is_positive_integer_digits(&inner[..slash]) && is_positive_integer_digits(&inner[slash + 1..])
    } else {
        is_positive_integer_digits(inner)
    }
}

/// Computes the truncated prefix length for `NzbSplitUnwrapper::stripLiteralSubjectTrailer(value)`.
pub fn nzbsplit_strip_literal_trailer_len(value: &[u8]) -> usize {
    if nzbsplit_literal_payload_span(value).is_none() {
        return value.len();
    }
    // Match `/"? +yEnc(?: +\([1-9]\d*(?:\/[1-9]\d*)?\))?\z/iD`
    let mut yenc_end = value.len();
    if value.ends_with(b")") {
        let Some(open_paren) = value.iter().rposition(|&b| b == b'(') else {
            return value.len();
        };
        if !is_valid_yenc_counter_parens(&value[open_paren..]) {
            return value.len();
        }
        let mut p = open_paren;
        while p > 0 && value[p - 1] == b' ' {
            p -= 1;
        }
        if p == open_paren {
            return value.len();
        }
        yenc_end = p;
    }
    if yenc_end < 5 || !value[yenc_end - 4..yenc_end].eq_ignore_ascii_case(b"yenc") {
        return value.len();
    }
    let before_yenc = yenc_end - 4;
    let mut p = before_yenc;
    while p > 0 && value[p - 1] == b' ' {
        p -= 1;
    }
    if p == before_yenc {
        return value.len();
    }
    if p > 0 && value[p - 1] == b'"' {
        p -= 1;
    }
    let filename = &value[..p];
    if matches_archive_suffix_pattern(filename) {
        p
    } else {
        value.len()
    }
}

#[inline]
fn rtrim_subject_tail(mut s: &[u8]) -> &[u8] {
    while let Some(&last) = s.last() {
        if is_php_trim_char(last) || matches!(last, b'"' | b'\'') {
            s = &s[..s.len() - 1];
        } else {
            break;
        }
    }
    s
}

#[inline]
fn matches_yenc_optional_parens_to_end(s: &[u8], yenc_pos: usize) -> bool {
    if yenc_pos + 4 > s.len() || !s[yenc_pos..yenc_pos + 4].eq_ignore_ascii_case(b"yenc") {
        return false;
    }
    let mut p = yenc_pos + 4;
    if p == s.len() {
        return true;
    }
    while p < s.len() && is_pcre_space(s[p]) {
        p += 1;
    }
    if p + 2 <= s.len() && s[p] == b'(' && s[s.len() - 1] == b')' {
        return !s[p + 1..s.len() - 1].contains(&b')');
    }
    false
}

#[inline]
fn strip_trailing_subject_pattern_1(s: &[u8]) -> Option<usize> {
    // `/"\s*yEnc(?:\s*\([^)]*\))?$/i`
    let mut i = 0usize;
    while i < s.len() {
        if s[i] == b'"' {
            let mut p = i + 1;
            while p < s.len() && is_pcre_space(s[p]) {
                p += 1;
            }
            if matches_yenc_optional_parens_to_end(s, p) {
                return Some(i);
            }
        }
        i += 1;
    }
    None
}

#[inline]
fn strip_trailing_subject_pattern_2(s: &[u8]) -> Option<usize> {
    // `/\s+yEnc(?:\s*\([^)]*\))?$/i`
    let mut i = 0usize;
    while i < s.len() {
        if is_pcre_space(s[i]) {
            let start = i;
            while i < s.len() && is_pcre_space(s[i]) {
                i += 1;
            }
            if matches_yenc_optional_parens_to_end(s, i) {
                return Some(start);
            }
        } else {
            i += 1;
        }
    }
    None
}

#[inline]
fn strip_trailing_subject_pattern_3(s: &[u8]) -> Option<usize> {
    // `/"\s*yEnc.*$/i`
    let mut i = 0usize;
    while i < s.len() {
        if s[i] == b'"' {
            let mut p = i + 1;
            while p < s.len() && is_pcre_space(s[p]) {
                p += 1;
            }
            if p + 4 <= s.len() && s[p..p + 4].eq_ignore_ascii_case(b"yenc") {
                return Some(i);
            }
        }
        i += 1;
    }
    None
}

#[inline]
fn strip_trailing_subject_pattern_4(s: &[u8]) -> Option<usize> {
    // `/\s+yEnc.*$/i`
    let mut i = 0usize;
    while i < s.len() {
        if is_pcre_space(s[i]) {
            let start = i;
            while i < s.len() && is_pcre_space(s[i]) {
                i += 1;
            }
            if i + 4 <= s.len() && s[i..i + 4].eq_ignore_ascii_case(b"yenc") {
                return Some(start);
            }
        } else {
            i += 1;
        }
    }
    None
}

#[inline]
fn strip_trailing_archive_patterns<'a>(mut title: &'a [u8], did_strip: &mut bool) -> &'a [u8] {
    // 1. `/\.part\d+\.rar$/i`
    if title.len() >= 10 && title[title.len() - 4..].eq_ignore_ascii_case(b".rar") {
        let mut p = title.len() - 4;
        while p > 0 && title[p - 1].is_ascii_digit() {
            p -= 1;
        }
        if p < title.len() - 4 && p >= 5 && title[p - 5..p].eq_ignore_ascii_case(b".part") {
            title = &title[..p - 5];
            *did_strip = true;
        }
    }
    // 2. `/\.r\d{2,4}$/i`
    if let Some(dot) = title.iter().rposition(|&b| b == b'.') {
        let ext = &title[dot + 1..];
        if (3..=5).contains(&ext.len())
            && matches!(ext[0], b'r' | b'R')
            && ext[1..].iter().all(|b| b.is_ascii_digit())
        {
            title = &title[..dot];
            *did_strip = true;
        }
    }
    // 3. `/\.7z\.\d{2,4}$/i`
    if let Some(dot) = title.iter().rposition(|&b| b == b'.') {
        let ext = &title[dot + 1..];
        if (2..=4).contains(&ext.len())
            && ext.iter().all(|b| b.is_ascii_digit())
            && dot >= 3
            && title[dot - 3..dot].eq_ignore_ascii_case(b".7z")
        {
            title = &title[..dot - 3];
            *did_strip = true;
        }
    }
    // 4. `/\.7z$/i`
    if title.len() >= 3 && title[title.len() - 3..].eq_ignore_ascii_case(b".7z") {
        title = &title[..title.len() - 3];
        *did_strip = true;
    }
    // 5. `/\.rar$/i`
    if title.len() >= 4 && title[title.len() - 4..].eq_ignore_ascii_case(b".rar") {
        title = &title[..title.len() - 4];
        *did_strip = true;
    }
    // 6. `/\.par2?$/i`
    if title.len() >= 5 && title[title.len() - 5..].eq_ignore_ascii_case(b".par2") {
        title = &title[..title.len() - 5];
        *did_strip = true;
    } else if title.len() >= 4 && title[title.len() - 4..].eq_ignore_ascii_case(b".par") {
        title = &title[..title.len() - 4];
        *did_strip = true;
    }
    // 7. `/\.vol\d+[+\-]\d+\.par2?$/i`
    let par_end = if title.len() >= 5 && title[title.len() - 5..].eq_ignore_ascii_case(b".par2") {
        Some(title.len() - 5)
    } else if title.len() >= 4 && title[title.len() - 4..].eq_ignore_ascii_case(b".par") {
        Some(title.len() - 4)
    } else {
        None
    };
    if let Some(end) = par_end {
        let mut p = end;
        while p > 0 && title[p - 1].is_ascii_digit() {
            p -= 1;
        }
        if p < end && p > 0 && matches!(title[p - 1], b'+' | b'-') {
            let sep = p - 1;
            p = sep;
            while p > 0 && title[p - 1].is_ascii_digit() {
                p -= 1;
            }
            if p < sep && p >= 4 && title[p - 4..p].eq_ignore_ascii_case(b".vol") {
                title = &title[..p - 4];
                *did_strip = true;
            }
        }
    }
    // 8. `/\.\d{3}$/`
    if title.len() >= 4
        && title[title.len() - 4] == b'.'
        && title[title.len() - 3..].iter().all(|b| b.is_ascii_digit())
    {
        title = &title[..title.len() - 4];
        *did_strip = true;
    }
    title
}

/// Evaluates `NzbSplitUnwrapper::unwrap(value)` into `out_vec`.
/// Returns `None` if `value` contains `\n` or `\r` (declined to PHP),
/// `Some(false)` if not matched or unwrapped result is empty,
/// or `Some(true)` with the unwrapped title written into `out_vec`.
pub fn nzbsplit_unwrap_vec(value: &[u8], out_vec: &mut Vec<u8>) -> Option<bool> {
    if value.contains(&b'\n') || value.contains(&b'\r') {
        return None;
    }
    // Find first `/__NZBSPLIT__([a-f0-9]{8,})__NZBSPLIT__(.+)$/i`
    let mut search_from = 0usize;
    let mut matched_payload: Option<&[u8]> = None;
    while let Some(first) = find_nzbsplit_from(value, search_from) {
        let hex_start = first + 12;
        let mut hex_end = hex_start;
        while hex_end < value.len() && value[hex_end].is_ascii_hexdigit() {
            hex_end += 1;
        }
        if hex_end - hex_start >= 8
            && hex_end + 12 < value.len()
            && value[hex_end..hex_end + 12].eq_ignore_ascii_case(b"__nzbsplit__")
        {
            matched_payload = Some(&value[hex_end + 12..]);
            break;
        }
        search_from = first + 1;
    }
    let mut title = matched_payload?;

    let mut did_strip = true;
    while did_strip {
        did_strip = false;
        if let Some(pos) = strip_trailing_subject_pattern_1(title) {
            title = rtrim_subject_tail(&title[..pos]);
            did_strip = true;
        }
        if let Some(pos) = strip_trailing_subject_pattern_2(title) {
            title = rtrim_subject_tail(&title[..pos]);
            did_strip = true;
        }
        if let Some(pos) = strip_trailing_subject_pattern_3(title) {
            title = rtrim_subject_tail(&title[..pos]);
            did_strip = true;
        }
        if let Some(pos) = strip_trailing_subject_pattern_4(title) {
            title = rtrim_subject_tail(&title[..pos]);
            did_strip = true;
        }
        title = strip_trailing_archive_patterns(title, &mut did_strip);
    }

    // 1. `str_replace(['_', '+'], ['.', ' '], $title)` + 2. `/\.{2,}/` -> `.`
    let mut step1 = Vec::with_capacity(title.len());
    let mut prev_dot = false;
    for &b in title {
        let mapped = match b {
            b'_' => b'.',
            b'+' => b' ',
            _ => b,
        };
        if mapped == b'.' {
            if !prev_dot {
                step1.push(b'.');
                prev_dot = true;
            }
        } else {
            prev_dot = false;
            step1.push(mapped);
        }
    }

    // 3. `/\s{2,}/` -> `' '`
    let mut step2 = Vec::with_capacity(step1.len());
    let mut i = 0usize;
    while i < step1.len() {
        if is_pcre_space(step1[i]) {
            let start = i;
            while i < step1.len() && is_pcre_space(step1[i]) {
                i += 1;
            }
            if i - start >= 2 {
                step2.push(b' ');
            } else {
                step2.push(step1[start]);
            }
        } else {
            step2.push(step1[i]);
            i += 1;
        }
    }

    // 4. `/\.\s+/` -> `.`
    let mut step3 = Vec::with_capacity(step2.len());
    let mut i = 0usize;
    while i < step2.len() {
        if step2[i] == b'.' {
            step3.push(b'.');
            i += 1;
            while i < step2.len() && is_pcre_space(step2[i]) {
                i += 1;
            }
        } else {
            step3.push(step2[i]);
            i += 1;
        }
    }

    // 5. `/\s+\./` -> `.`
    let mut step4 = Vec::with_capacity(step3.len());
    let mut i = 0usize;
    while i < step3.len() {
        if is_pcre_space(step3[i]) {
            let start = i;
            while i < step3.len() && is_pcre_space(step3[i]) {
                i += 1;
            }
            if i < step3.len() && step3[i] == b'.' {
                step4.push(b'.');
                i += 1;
            } else {
                step4.extend_from_slice(&step3[start..i]);
            }
        } else {
            step4.push(step3[i]);
            i += 1;
        }
    }

    // 6. `trim($title, " \t\n\r\0\x0B.-_")`
    let mut start = 0usize;
    let mut end = step4.len();
    while start < end && (is_php_trim_char(step4[start]) || matches!(step4[start], b'.' | b'-' | b'_'))
    {
        start += 1;
    }
    while end > start
        && (is_php_trim_char(step4[end - 1]) || matches!(step4[end - 1], b'.' | b'-' | b'_'))
    {
        end -= 1;
    }
    if start == end {
        return Some(false);
    }
    out_vec.clear();
    out_vec.extend_from_slice(&step4[start..end]);
    Some(true)
}

#[inline]
fn strip_sample_proof_thumbs_suffix(s: &[u8]) -> &[u8] {
    // `/[.\-_](sample|proof|thumbs?)$/i`
    for word in [&b"sample"[..], b"thumbs", b"thumb", b"proof"] {
        if s.len() > word.len()
            && matches!(s[s.len() - word.len() - 1], b'.' | b'-' | b'_')
            && s[s.len() - word.len()..].eq_ignore_ascii_case(word)
        {
            return &s[..s.len() - word.len() - 1];
        }
    }
    s
}

#[inline]
fn strip_fixer_archive_ext(s: &[u8]) -> &[u8] {
    // `/\.(par2?|nfo|sfv|nzb|rar|r\d{2,3}|zip)$/i`
    let Some(dot) = s.iter().rposition(|&b| b == b'.') else {
        return s;
    };
    let ext = &s[dot + 1..];
    if (3..=4).contains(&ext.len())
        && matches!(ext[0], b'r' | b'R')
        && ext[1..].iter().all(|b| b.is_ascii_digit())
    {
        return &s[..dot];
    }
    for word in [
        &b"par2"[..],
        b"par",
        b"nfo",
        b"sfv",
        b"nzb",
        b"rar",
        b"zip",
    ] {
        if ext.eq_ignore_ascii_case(word) {
            return &s[..dot];
        }
    }
    s
}

#[inline]
fn strip_fixer_part_marker(s: &[u8]) -> &[u8] {
    // `/\.part\d+(\.rar)?$/i`
    let check_end = if s.len() >= 4 && s[s.len() - 4..].eq_ignore_ascii_case(b".rar") {
        s.len() - 4
    } else {
        s.len()
    };
    let mut p = check_end;
    while p > 0 && s[p - 1].is_ascii_digit() {
        p -= 1;
    }
    if p < check_end && p >= 5 && s[p - 5..p].eq_ignore_ascii_case(b".part") {
        return &s[..p - 5];
    }
    s
}

#[inline]
fn strip_fixer_vol_par2(s: &[u8]) -> &[u8] {
    // `/\.vol\d+\+\d+\.par2$/i`
    if s.len() < 12 || !s[s.len() - 5..].eq_ignore_ascii_case(b".par2") {
        return s;
    }
    let end = s.len() - 5;
    let mut p = end;
    while p > 0 && s[p - 1].is_ascii_digit() {
        p -= 1;
    }
    if p < end && p > 0 && s[p - 1] == b'+' {
        let plus = p - 1;
        p = plus;
        while p > 0 && s[p - 1].is_ascii_digit() {
            p -= 1;
        }
        if p < plus && p >= 4 && s[p - 4..p].eq_ignore_ascii_case(b".vol") {
            return &s[..p - 4];
        }
    }
    s
}

#[inline]
fn strip_fixer_bare_vol(s: &[u8]) -> &[u8] {
    // `/\.vol\d+[+-]\d+$/i`
    let mut p = s.len();
    while p > 0 && s[p - 1].is_ascii_digit() {
        p -= 1;
    }
    if p < s.len() && p > 0 && matches!(s[p - 1], b'+' | b'-') {
        let sep = p - 1;
        p = sep;
        while p > 0 && s[p - 1].is_ascii_digit() {
            p -= 1;
        }
        if p < sep && p >= 4 && s[p - 4..p].eq_ignore_ascii_case(b".vol") {
            return &s[..p - 4];
        }
    }
    s
}

#[inline]
fn strip_fixer_rev(s: &[u8]) -> &[u8] {
    // `/\d{1,3}\.rev"?$/i`
    let rev_end = if s.ends_with(b"\"") {
        s.len() - 1
    } else {
        s.len()
    };
    if rev_end < 5 || !s[rev_end - 4..rev_end].eq_ignore_ascii_case(b".rev") {
        return s;
    }
    let digits_end = rev_end - 4;
    let mut p = digits_end;
    while p > 0 && digits_end - p < 3 && s[p - 1].is_ascii_digit() {
        p -= 1;
    }
    if p < digits_end {
        return &s[..p];
    }
    s
}

#[inline]
fn strip_fixer_prefix(s: &[u8]) -> &[u8] {
    // `/^(Release Name|sample[-_])/i`
    if s.len() >= 12 && s[..12].eq_ignore_ascii_case(b"release name") {
        return &s[12..];
    }
    if s.len() >= 7
        && s[..6].eq_ignore_ascii_case(b"sample")
        && matches!(s[6], b'-' | b'_')
    {
        return &s[7..];
    }
    s
}

/// Evaluates `ReleaseCleaningService::fixerCleaner(name)` into `out`.
/// Returns `false` if declined to PHP (`\n` or `\r` in `name`).
pub(crate) fn release_fixer_clean(name: &[u8], out: &mut OutCursor<'_>) -> bool {
    if name.contains(&b'\n') || name.contains(&b'\r') {
        return false;
    }
    let mut unwrapped_buf = Vec::new();
    let mut s: &[u8] = if find_nzbsplit_from(name, 0).is_some() {
        match nzbsplit_unwrap_vec(name, &mut unwrapped_buf) {
            Some(true) => unwrapped_buf.as_slice(),
            Some(false) => name,
            None => return false,
        }
    } else {
        name
    };

    s = strip_sample_proof_thumbs_suffix(s);
    s = strip_fixer_archive_ext(s);
    s = strip_fixer_part_marker(s);
    s = strip_fixer_vol_par2(s);
    s = strip_fixer_bare_vol(s);
    s = strip_fixer_rev(s);
    s = strip_fixer_prefix(s);

    // Collapse `/\s\s+/` -> `' '` and filter `/[^(\x20-\x7F)]*/` -> `''`
    let mut ascii_buf = Vec::with_capacity(s.len());
    let mut i = 0usize;
    while i < s.len() {
        if is_pcre_space(s[i]) {
            let start = i;
            while i < s.len() && is_pcre_space(s[i]) {
                i += 1;
            }
            if i - start >= 2 {
                ascii_buf.push(b' ');
            } else if (0x20..=0x7F).contains(&s[start]) {
                ascii_buf.push(s[start]);
            }
        } else {
            if (0x20..=0x7F).contains(&s[i]) {
                ascii_buf.push(s[i]);
            }
            i += 1;
        }
    }

    let trimmed = php_trim_ascii(&ascii_buf);
    out.extend(trimmed);
    true
}

#[inline]
pub fn extract_filename_from_path(path: &[u8]) -> &[u8] {
    if let Some(pos) = path.iter().rposition(|&b| b == b'/' || b == b'\\') {
        if pos + 1 < path.len() {
            return &path[pos + 1..];
        }
    }
    path
}

#[inline]
fn ends_with_segment_marker(t: &[u8]) -> bool {
    // `/(?:^|[.\-_ ])(?:part|vol|r)\d+(?:\+\d+)?$/i`
    let mut end = t.len();
    let mut p = end;
    while p > 0 && t[p - 1].is_ascii_digit() {
        p -= 1;
    }
    if p == end {
        return false;
    }
    if p > 0 && t[p - 1] == b'+' {
        end = p - 1;
        p = end;
        while p > 0 && t[p - 1].is_ascii_digit() {
            p -= 1;
        }
        if p == end {
            return false;
        }
    }
    for prefix in [&b"part"[..], b"vol", b"r"] {
        if p >= prefix.len() && t[p - prefix.len()..p].eq_ignore_ascii_case(prefix) {
            let before = p - prefix.len();
            if before == 0 || matches!(t[before - 1], b'.' | b'-' | b'_' | b' ') {
                return true;
            }
        }
    }
    false
}

#[inline]
fn starts_with_generic_installer(t: &[u8]) -> bool {
    // `/^(setup|install|installer|patch|update|crack|keygen)\d*[\s._-]/i`
    for word in [
        &b"installer"[..],
        b"install",
        b"keygen",
        b"update",
        b"setup",
        b"patch",
        b"crack",
    ] {
        if t.len() > word.len() && t[..word.len()].eq_ignore_ascii_case(word) {
            let mut p = word.len();
            while p < t.len() && t[p].is_ascii_digit() {
                p += 1;
            }
            if p < t.len() && (is_pcre_space(t[p]) || matches!(t[p], b'.' | b'_' | b'-')) {
                return true;
            }
        }
    }
    false
}

#[inline]
fn is_plausible_quality_word(word: &[u8]) -> bool {
    if word.len() < 2 || word.len() > 6 {
        return false;
    }
    let mut buf = [0u8; 6];
    for (i, &b) in word.iter().enumerate() {
        buf[i] = b.to_ascii_lowercase();
    }
    matches!(
        &buf[..word.len()],
        b"480p"
            | b"720p"
            | b"1080p"
            | b"2160p"
            | b"4k"
            | b"webrip"
            | b"webdl"
            | b"bluray"
            | b"bdrip"
            | b"dvdrip"
            | b"hdtv"
            | b"hdrip"
            | b"xvid"
            | b"x264"
            | b"x265"
            | b"hevc"
            | b"h264"
            | b"ts"
            | b"cam"
            | b"r5"
            | b"proper"
            | b"repack"
    )
}

#[inline]
fn is_tv_episode_word(word: &[u8]) -> bool {
    // `^S\d{1,2}[Eex]\d{1,3}$` case-insensitive
    if word.len() < 4 || word.len() > 7 || !matches!(word[0], b's' | b'S') {
        return false;
    }
    let mut p = 1usize;
    while p < word.len() && word[p].is_ascii_digit() {
        p += 1;
    }
    let s_digits = p - 1;
    if !(1..=2).contains(&s_digits) || p >= word.len() || !matches!(word[p], b'e' | b'E' | b'x' | b'X')
    {
        return false;
    }
    p += 1;
    let e_digits = word.len() - p;
    (1..=3).contains(&e_digits) && word[p..].iter().all(|b| b.is_ascii_digit())
}

/// Evaluates `FileNameCleaner::isPlausibleReleaseTitle(title)`.
/// Returns `None` if `title` contains `\n` (declined to PHP).
pub fn is_plausible_release_title(title: &[u8]) -> Option<bool> {
    if title.contains(&b'\n') {
        return None;
    }
    let t = php_trim_ascii(title);
    if t.len() < 12 {
        return Some(false);
    }

    // Count `/[A-Za-z0-9]{3,}/`
    let mut word_count = 0usize;
    let mut run = 0usize;
    for &b in t {
        if b.is_ascii_alphanumeric() {
            run += 1;
            if run == 3 {
                word_count += 1;
            }
        } else {
            run = 0;
        }
    }
    if word_count < 2 {
        return Some(false);
    }

    if crate::categorize::is_hashed_or_gibberish_bytes(t) {
        return Some(false);
    }

    if ends_with_segment_marker(t) {
        return Some(false);
    }

    if starts_with_generic_installer(t) {
        return Some(false);
    }

    // `/[-.][A-Za-z0-9]{2,}$/`
    let mut trailing_alnum = 0usize;
    while trailing_alnum < t.len() && t[t.len() - 1 - trailing_alnum].is_ascii_alphanumeric() {
        trailing_alnum += 1;
    }
    if trailing_alnum >= 2
        && trailing_alnum < t.len()
        && matches!(t[t.len() - 1 - trailing_alnum], b'-' | b'.')
    {
        return Some(true);
    }

    let mut has_tv = crate::filename::is_season_pack_bytes(t);
    let mut has_quality = false;
    let mut has_xxx = false;

    let mut i = 0usize;
    while i < t.len() {
        if is_pcre_word(t[i]) {
            let start = i;
            i += 1;
            while i < t.len() && is_pcre_word(t[i]) {
                i += 1;
            }
            let word = &t[start..i];
            if !has_tv && is_tv_episode_word(word) {
                has_tv = true;
            }
            if !has_xxx && word.eq_ignore_ascii_case(b"xxx") {
                has_xxx = true;
            }
            if !has_quality {
                if is_plausible_quality_word(word) {
                    has_quality = true;
                } else if word.eq_ignore_ascii_case(b"web")
                    && i + 3 <= t.len()
                    && matches!(t[i], b' ' | b'.' | b'-')
                    && t[i + 1..i + 3].eq_ignore_ascii_case(b"dl")
                    && (i + 3 == t.len() || !is_pcre_word(t[i + 3]))
                {
                    has_quality = true;
                } else if word.eq_ignore_ascii_case(b"h")
                    && i + 4 <= t.len()
                    && t[i] == b'.'
                    && &t[i + 1..i + 4] == b"264"
                    && (i + 4 == t.len() || !is_pcre_word(t[i + 4]))
                {
                    has_quality = true;
                }
            }
        } else {
            i += 1;
        }
    }

    Some(has_tv || has_quality || has_xxx)
}

#[inline]
fn is_safe_utf8_no_unicode_space(bytes: &[u8]) -> bool {
    let mut i = 0usize;
    while i < bytes.len() {
        let b = bytes[i];
        if b < 0x80 {
            i += 1;
        } else if (0xC2..=0xDF).contains(&b) {
            if i + 1 >= bytes.len() {
                return false;
            }
            let b2 = bytes[i + 1];
            if !(0x80..=0xBF).contains(&b2) {
                return false;
            }
            // Reject U+0085 (0xC2 0x85) and U+00A0 (0xC2 0xA0) which match PCRE `/\s/u`
            if b == 0xC2 && (b2 == 0x85 || b2 == 0xA0) {
                return false;
            }
            i += 2;
        } else {
            return false;
        }
    }
    true
}

/// Evaluates `ReleaseCleaningService::releaseNameFromFile(file)` into `out`.
/// Returns `1` on match (written to `out`), `0` when `null`, or `-2` when declined to PHP.
pub(crate) fn release_name_from_file(file: &[u8], out: &mut OutCursor<'_>) -> i32 {
    if file.contains(&b'\n') || file.contains(&b'\r') || !is_safe_utf8_no_unicode_space(file) {
        return -2;
    }
    let extracted = extract_filename_from_path(file);
    let Some(stripped_len) = crate::filename::strip_known_file_suffixes_len(extracted, true) else {
        return -2;
    };
    let name = strip_sample_proof_thumbs_suffix(&extracted[..stripped_len]);

    // `trim(preg_replace('/\s\s+/u', ' ', $name) ?? $name, " .-_\t")`
    let mut collapsed = Vec::with_capacity(name.len());
    let mut i = 0usize;
    while i < name.len() {
        if is_pcre_space(name[i]) {
            let start = i;
            while i < name.len() && is_pcre_space(name[i]) {
                i += 1;
            }
            if i - start >= 2 {
                collapsed.push(b' ');
            } else {
                collapsed.push(name[start]);
            }
        } else {
            collapsed.push(name[i]);
            i += 1;
        }
    }

    let mut start = 0usize;
    let mut end = collapsed.len();
    while start < end && matches!(collapsed[start], b' ' | b'.' | b'-' | b'_' | b'\t') {
        start += 1;
    }
    while end > start && matches!(collapsed[end - 1], b' ' | b'.' | b'-' | b'_' | b'\t') {
        end -= 1;
    }
    let trimmed = &collapsed[start..end];

    // `$withoutDottedSuffix = preg_replace('/\.([^.\-]+)$/', ' $1', $name) ?? $name;`
    let mut check_buf = trimmed.to_vec();
    if let Some(dot_pos) = check_buf.iter().rposition(|&b| b == b'.') {
        if dot_pos + 1 < check_buf.len() && !check_buf[dot_pos + 1..].contains(&b'-') {
            check_buf[dot_pos] = b' ';
        }
    }

    match is_plausible_release_title(&check_buf) {
        Some(true) => {
            out.extend(trimmed);
            1
        }
        Some(false) => 0,
        None => -2,
    }
}

/// Evaluates `ReleaseCleaningService::releaseCleanerHelper(subject)` into `out`.
pub(crate) fn release_cleaner_helper(subject: &[u8], out: &mut OutCursor<'_>) {
    // `$cleanerName = preg_replace('/(\- )?yEnc$/', '', $subject);`
    let stripped = if subject.ends_with(b"- yEnc") {
        &subject[..subject.len() - 6]
    } else if subject.ends_with(b"yEnc") {
        &subject[..subject.len() - 4]
    } else {
        subject
    };

    // `return trim(preg_replace('/\s\s+/', ' ', $cleanerName));`
    let mut collapsed = Vec::with_capacity(stripped.len());
    let mut i = 0usize;
    while i < stripped.len() {
        if is_pcre_space(stripped[i]) {
            let start = i;
            while i < stripped.len() && is_pcre_space(stripped[i]) {
                i += 1;
            }
            if i - start >= 2 {
                collapsed.push(b' ');
            } else {
                collapsed.push(stripped[start]);
            }
        } else {
            collapsed.push(stripped[i]);
            i += 1;
        }
    }
    let trimmed = php_trim_ascii(&collapsed);
    out.extend(trimmed);
}

#[inline]
fn contains_word_lrap(s: &[u8]) -> bool {
    let mut i = 0usize;
    while i < s.len() {
        if is_pcre_word(s[i]) {
            let start = i;
            i += 1;
            while i < s.len() && is_pcre_word(s[i]) {
                i += 1;
            }
            if s[start..i].eq_ignore_ascii_case(b"lrap") {
                return true;
            }
        } else {
            i += 1;
        }
    }
    false
}

/// Matches `ReleaseCleaningService::COUNTER_FILE_SUBJECT`:
/// `/^\[\s*\d+\s*\/\s*\d+\s*\]\s*-?\s*(?:"|#34;)(?P<file>(?:(?!"|#34;).){4,})(?:"|#34;)/`
/// Returns `Some((file_slice, tail_slice))` when matched.
pub fn match_counter_file_subject(subject: &[u8]) -> Option<(&[u8], &[u8])> {
    if subject.first() != Some(&b'[') {
        return None;
    }
    let mut p = 1usize;
    while p < subject.len() && is_pcre_space(subject[p]) {
        p += 1;
    }
    let d1_start = p;
    while p < subject.len() && subject[p].is_ascii_digit() {
        p += 1;
    }
    if p == d1_start {
        return None;
    }
    while p < subject.len() && is_pcre_space(subject[p]) {
        p += 1;
    }
    if p >= subject.len() || subject[p] != b'/' {
        return None;
    }
    p += 1;
    while p < subject.len() && is_pcre_space(subject[p]) {
        p += 1;
    }
    let d2_start = p;
    while p < subject.len() && subject[p].is_ascii_digit() {
        p += 1;
    }
    if p == d2_start {
        return None;
    }
    while p < subject.len() && is_pcre_space(subject[p]) {
        p += 1;
    }
    if p >= subject.len() || subject[p] != b']' {
        return None;
    }
    p += 1;
    while p < subject.len() && is_pcre_space(subject[p]) {
        p += 1;
    }
    if p < subject.len() && subject[p] == b'-' {
        p += 1;
    }
    while p < subject.len() && is_pcre_space(subject[p]) {
        p += 1;
    }
    let file_start = if p < subject.len() && subject[p] == b'"' {
        p + 1
    } else if p + 4 <= subject.len() && &subject[p..p + 4] == b"#34;" {
        p + 4
    } else {
        return None;
    };

    // Scan until next `"` or `#34;`
    let mut q = file_start;
    while q < subject.len() {
        if subject[q] == b'"' {
            if q - file_start < 4 {
                return None;
            }
            return Some((&subject[file_start..q], &subject[q + 1..]));
        }
        if q + 4 <= subject.len() && &subject[q..q + 4] == b"#34;" {
            if q - file_start < 4 {
                return None;
            }
            return Some((&subject[file_start..q], &subject[q + 4..]));
        }
        q += 1;
    }
    None
}

#[inline]
fn starts_with_bracket_digits_close(subject: &[u8]) -> bool {
    if subject.first() != Some(&b'[') {
        return false;
    }
    let mut p = 1usize;
    while p < subject.len() && subject[p].is_ascii_digit() {
        p += 1;
    }
    p > 1 && p < subject.len() && subject[p] == b']'
}

#[inline]
fn contains_subslice(haystack: &[u8], needle: &[u8]) -> bool {
    if needle.is_empty() || haystack.len() < needle.len() {
        return false;
    }
    haystack.windows(needle.len()).any(|w| w == needle)
}

#[inline]
fn is_payload_sample_delim(b: u8) -> bool {
    is_pcre_space(b) || matches!(b, b'.' | b'_' | b'-')
}

#[inline]
fn has_payload_sample_marker(filename: &[u8]) -> bool {
    // `/(?:^|[._\-\s])(?:sample|proof|thumbs?|subs?|subtitles)(?:[._\-\s]|$)/i`
    let mut i = 0usize;
    while i < filename.len() {
        if is_payload_sample_delim(filename[i]) {
            i += 1;
            continue;
        }
        let start = i;
        while i < filename.len() && !is_payload_sample_delim(filename[i]) {
            i += 1;
        }
        let tok = &filename[start..i];
        if tok.eq_ignore_ascii_case(b"sample")
            || tok.eq_ignore_ascii_case(b"proof")
            || tok.eq_ignore_ascii_case(b"thumb")
            || tok.eq_ignore_ascii_case(b"thumbs")
            || tok.eq_ignore_ascii_case(b"sub")
            || tok.eq_ignore_ascii_case(b"subs")
            || tok.eq_ignore_ascii_case(b"subtitles")
        {
            return true;
        }
    }
    false
}

#[inline]
fn is_payload_signal_word(word: &[u8]) -> bool {
    // `s\d{1,3}e\d{1,3}|(?:19|20)\d{2}|720p|1080p|2160p|flac|mp3|\d{2,3}kbps`
    if word.eq_ignore_ascii_case(b"720p")
        || word.eq_ignore_ascii_case(b"1080p")
        || word.eq_ignore_ascii_case(b"2160p")
        || word.eq_ignore_ascii_case(b"flac")
        || word.eq_ignore_ascii_case(b"mp3")
    {
        return true;
    }
    if word.len() == 4
        && (&word[..2] == b"19" || &word[..2] == b"20")
        && word[2].is_ascii_digit()
        && word[3].is_ascii_digit()
    {
        return true;
    }
    if (6..=7).contains(&word.len())
        && word[word.len() - 4..].eq_ignore_ascii_case(b"kbps")
        && word[..word.len() - 4].iter().all(|b| b.is_ascii_digit())
    {
        return true;
    }
    if (4..=8).contains(&word.len()) && matches!(word[0], b's' | b'S') {
        let mut p = 1usize;
        while p < word.len() && word[p].is_ascii_digit() {
            p += 1;
        }
        let s_digits = p - 1;
        if (1..=3).contains(&s_digits) && p < word.len() && matches!(word[p], b'e' | b'E') {
            p += 1;
            let e_digits = word.len() - p;
            if (1..=3).contains(&e_digits) && word[p..].iter().all(|b| b.is_ascii_digit()) {
                return true;
            }
        }
    }
    false
}

#[inline]
fn has_payload_release_signal(name: &[u8]) -> bool {
    // `/\b(?:s\d{1,3}e\d{1,3}|(?:19|20)\d{2}|720p|1080p|2160p|flac|mp3|\d{2,3}kbps|v\d+\.\d+)\b/i`
    let mut i = 0usize;
    while i < name.len() {
        if is_pcre_word(name[i]) {
            let start = i;
            i += 1;
            while i < name.len() && is_pcre_word(name[i]) {
                i += 1;
            }
            let word = &name[start..i];
            if is_payload_signal_word(word) {
                return true;
            }
            // Check `v\d+\.\d+\b`
            if word.len() >= 2
                && matches!(word[0], b'v' | b'V')
                && word[1..].iter().all(|b| b.is_ascii_digit())
                && i + 1 < name.len()
                && name[i] == b'.'
                && name[i + 1].is_ascii_digit()
            {
                let mut q = i + 1;
                while q < name.len() && name[q].is_ascii_digit() {
                    q += 1;
                }
                if q == name.len() || !is_pcre_word(name[q]) {
                    return true;
                }
            }
        } else {
            i += 1;
        }
    }
    false
}

#[inline]
fn normalize_predb_key_ascii(name: &[u8]) -> Option<Vec<u8>> {
    if name.is_empty() || name.len() > 512 {
        return None;
    }
    let trimmed = php_trim_ascii(name);
    let mut out = Vec::with_capacity(trimmed.len());
    let mut in_sep = false;
    for &b in trimmed {
        if b == b'.' || b == b'_' || is_pcre_space(b) {
            if !in_sep {
                out.push(b'.');
                in_sep = true;
            }
        } else {
            in_sep = false;
            out.push(b.to_ascii_lowercase());
        }
    }
    Some(out)
}

// ============================================================================
// C ABI Exports
// ============================================================================

/// Evaluates `NzbSplitUnwrapper::literalPayload(input)` into `out_buf`.
/// Returns `>= 0` (byte length) on match, `-3` when `null`, or `-(required_len as isize)` when `out_cap` is too small.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzbsplit_literal_payload(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() || input_len == 0 {
        return -3;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    if nzbsplit_literal_payload(bytes, &mut cursor) {
        cursor.finish()
    } else {
        -3
    }
}

/// Returns the truncated byte length for `NzbSplitUnwrapper::stripLiteralSubjectTrailer(input)`.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzbsplit_strip_literal_trailer_len(
    input: *const u8,
    input_len: usize,
) -> usize {
    if input.is_null() || input_len == 0 {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    nzbsplit_strip_literal_trailer_len(bytes)
}

/// Evaluates `NzbSplitUnwrapper::unwrap(input)` into `out_buf`.
/// Returns `>= 0` (byte length) on match, `-3` when `null`, `-2` when declined to PHP,
/// or `-(required_len as isize)` when `out_cap` is too small.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzbsplit_unwrap(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() || input_len == 0 {
        return -3;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    if find_nzbsplit_from(bytes, 0).is_none() {
        return -3;
    }
    let mut vec = Vec::new();
    match nzbsplit_unwrap_vec(bytes, &mut vec) {
        Some(true) => {
            let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
            cursor.extend(&vec);
            cursor.finish()
        }
        Some(false) => -3,
        None => -2,
    }
}

/// Evaluates `FileNameCleaner::isPlausibleReleaseTitle(input)`.
/// Returns `1` for `true`, `0` for `false`, or `-1` when declined to PHP.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_is_plausible_release_title(
    input: *const u8,
    input_len: usize,
) -> i32 {
    if input.is_null() || input_len == 0 {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    match is_plausible_release_title(bytes) {
        Some(true) => 1,
        Some(false) => 0,
        None => -1,
    }
}

/// Evaluates `ReleaseCleaningService::fixerCleaner(input)` into `out_buf`.
/// Returns `>= 0` (byte length), `-2` when declined to PHP, or `-(required_len as isize)` on buffer overflow.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_fixer_clean(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() || input_len == 0 {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    if release_fixer_clean(bytes, &mut cursor) {
        cursor.finish()
    } else {
        -2
    }
}

/// Evaluates `ReleaseCleaningService::releaseNameFromFile(input)` into `out_buf`.
/// Returns `>= 0` (byte length) on match, `-3` when `null`, `-2` when declined to PHP,
/// or `-(required_len as isize)` on buffer overflow.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_name_from_file(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() || input_len == 0 {
        return -3;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    match release_name_from_file(bytes, &mut cursor) {
        1 => cursor.finish(),
        0 => -3,
        _ => -2,
    }
}

/// Evaluates `PayloadIdentityResolver::resolve` over `count` NUL-delimited hint filenames.
/// Returns `>= 0` (byte length of resolved name), `-3` when `null`, `-2` when declined to PHP,
/// or `-(required_len as isize)` on buffer overflow.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_payload_identity_resolve_nul(
    input: *const u8,
    input_len: usize,
    count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if count == 0 {
        return -3;
    }
    if input.is_null() {
        return -2;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    if bytes
        .iter()
        .any(|&b| b >= 0x80 || b == b'\n' || b == b'\r')
    {
        return -2;
    }

    let mut first_key: Option<Vec<u8>> = None;
    let mut first_name: Option<Vec<u8>> = None;
    let mut spaced = Vec::with_capacity(256);
    let mut name_buf = [0u8; 1024];

    let mut pos = 0usize;
    for idx in 0..count {
        let slice_end = if idx + 1 < count {
            let Some(rel) = crate::find_nul(&bytes[pos..]) else {
                return -2;
            };
            pos + rel
        } else {
            if crate::find_nul(&bytes[pos..]).is_some() {
                return -2;
            }
            bytes.len()
        };
        let filename = &bytes[pos..slice_end];
        pos = slice_end + 1;

        if has_payload_sample_marker(filename) {
            continue;
        }

        spaced.clear();
        for &b in filename {
            spaced.push(if b == b'_' { b' ' } else { b });
        }

        let mut cursor = unsafe { OutCursor::new(name_buf.as_mut_ptr(), name_buf.len()) };
        match release_name_from_file(&spaced, &mut cursor) {
            1 => {
                let written = cursor.finish();
                if written < 0 {
                    return -2;
                }
                let name = &name_buf[..written as usize];
                if !has_payload_release_signal(name) {
                    continue;
                }
                let Some(key) = normalize_predb_key_ascii(name) else {
                    continue;
                };
                if let Some(ref existing_key) = first_key {
                    if existing_key.as_slice() != key.as_slice() {
                        return -3;
                    }
                } else {
                    first_key = Some(key);
                    first_name = Some(name.to_vec());
                }
            }
            0 => continue,
            _ => return -2,
        }
    }

    let Some(winner) = first_name else {
        return -3;
    };
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    out.extend(&winner);
    out.finish()
}

/// Evaluates `ReleaseCleaningService` generic subject cleaning into `out_buf`:
/// - `mode == 0`: `releaseCleanerHelper(input)`
/// - `mode == 1`: `counterFileSubjectName(input)` (returns `-3` when `null`)
/// - `mode == 2`: `generic(input)`
/// - `mode == 3`: `teevee(input)`
/// Returns `>= 0` (byte length), `-3` when `null` (`mode == 1`), `-2` when declined to PHP,
/// or `-(required_len as isize)` on buffer overflow.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_generic_clean(
    input: *const u8,
    input_len: usize,
    mode: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() || input_len == 0 {
        return if mode == 1 { -3 } else { 0 };
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    if bytes.contains(&b'\n') || bytes.contains(&b'\r') {
        return -2;
    }
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    match mode {
        0 => {
            release_cleaner_helper(bytes, &mut cursor);
            cursor.finish()
        }
        1 => {
            if let Some((file, tail)) = match_counter_file_subject(bytes) {
                if contains_word_lrap(tail) {
                    return -3;
                }
                match release_name_from_file(file, &mut cursor) {
                    1 => cursor.finish(),
                    0 => -3,
                    _ => -2,
                }
            } else {
                -3
            }
        }
        2 => {
            if let Some((file, tail)) = match_counter_file_subject(bytes) {
                if !contains_word_lrap(tail) {
                    match release_name_from_file(file, &mut cursor) {
                        1 => return cursor.finish(),
                        0 => {}
                        _ => return -2,
                    }
                }
            }
            if starts_with_bracket_digits_close(bytes) {
                return -2;
            }
            release_cleaner_helper(bytes, &mut cursor);
            cursor.finish()
        }
        3 => {
            if contains_subslice(bytes, b"]-[") {
                return -2;
            }
            if let Some((file, tail)) = match_counter_file_subject(bytes) {
                if !contains_word_lrap(tail) {
                    match release_name_from_file(file, &mut cursor) {
                        1 => return cursor.finish(),
                        0 => {}
                        _ => return -2,
                    }
                }
            }
            release_cleaner_helper(bytes, &mut cursor);
            cursor.finish()
        }
        _ => -2,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_nzbsplit_unwrap_and_literal_payload() {
        let mut vec = Vec::new();
        assert_eq!(
            nzbsplit_unwrap_vec(
                b"WinterOlympics2026__NZBSPLIT__0456f274737cea074abd86a89144cc7b__NZBSPLIT__Winter_Olympic_Games_Milano_Cortina_2026_Closing_Ceremony_1080p25_WEB-DL_(MultiAudio).7z.065\" yEnc(1",
                &mut vec
            ),
            Some(true)
        );
        assert_eq!(
            std::str::from_utf8(&vec).unwrap(),
            "Winter.Olympic.Games.Milano.Cortina.2026.Closing.Ceremony.1080p25.WEB-DL.(MultiAudio)"
        );

        let wrapped =
            b"Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRP.7z.073\" yEnc (1/42)";
        assert_eq!(nzbsplit_strip_literal_trailer_len(wrapped), 74);
    }

    #[test]
    fn test_fixer_cleaner_and_release_name_from_file() {
        let mut buf = [0u8; 256];
        let len = unsafe {
            nntmux_release_fixer_clean(
                b"Some.Movie-GRP.vol01-03.par2".as_ptr(),
                28,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(&buf[..len as usize], b"Some.Movie-GRP");

        let len = unsafe {
            nntmux_release_generic_clean(
                b"[1/9] - \"Shes.the.Man.2006.BluRay.1080p.DTS-HD.MA.5.1.AVC.REMUX-FraMeSToR.vol01-03.par2\" yEnc".as_ptr(),
                93,
                2,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(
            &buf[..len as usize],
            b"Shes.the.Man.2006.BluRay.1080p.DTS-HD.MA.5.1.AVC.REMUX-FraMeSToR"
        );
    }
}
