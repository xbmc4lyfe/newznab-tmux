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
    if ext.len() == 3
        && matches!(ext[0], b'z' | b'Z')
        && ext[1..].iter().all(|b| b.is_ascii_digit())
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
        is_positive_integer_digits(&inner[..slash])
            && is_positive_integer_digits(&inner[slash + 1..])
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
    while start < end
        && (is_php_trim_char(step4[start]) || matches!(step4[start], b'.' | b'-' | b'_'))
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
    for word in [&b"par2"[..], b"par", b"nfo", b"sfv", b"nzb", b"rar", b"zip"] {
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
    if s.len() >= 7 && s[..6].eq_ignore_ascii_case(b"sample") && matches!(s[6], b'-' | b'_') {
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
    if !(1..=2).contains(&s_digits)
        || p >= word.len()
        || !matches!(word[p], b'e' | b'E' | b'x' | b'X')
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
    if bytes.iter().any(|&b| b >= 0x80 || b == b'\n' || b == b'\r') {
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

#[inline]
fn find_quoted_3_to_240(s: &[u8], quote: u8) -> Option<&[u8]> {
    let mut prev: Option<usize> = None;
    for (idx, &b) in s.iter().enumerate() {
        if b == quote {
            if let Some(q1) = prev {
                let inner_len = idx - q1 - 1;
                if (3..=240).contains(&inner_len) {
                    return Some(&s[q1 + 1..idx]);
                }
            }
            prev = Some(idx);
        }
    }
    None
}

#[inline]
fn is_obfuscated_trim_char(b: u8) -> bool {
    is_php_trim_char(b) || matches!(b, b'"' | b'\'' | b'`' | b'-' | b'_' | b'.')
}

/// Evaluates `ObfuscatedSubjectExtractor::extract(string $value)`.
/// Returns:
/// - `>= 0`: bytes written to `out_buf`
/// - `-3`: `null`
/// - `-2`: declined to PHP (non-ASCII or multiline input)
/// - `-1`: null pointer error
/// - `< -3`: `-(required_len as isize)` when `out_cap` is too small
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_extract_obfuscated_subject(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input_len == 0 {
        return -3;
    }
    if input.is_null() {
        return -1;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    if bytes.iter().any(|&b| b >= 0x80 || b == b'\n' || b == b'\r') {
        return -2;
    }

    let original = php_trim_ascii(bytes);
    if original.is_empty() {
        return -3;
    }

    let mut norm = original;
    let mut looks_obfuscated = false;

    // Prefix 1: `/^\s*N:\/NZB\s*\[\d+\/\d+\]\s*-\s*/i`
    {
        let mut p = 0usize;
        while p < norm.len() && is_pcre_space(norm[p]) {
            p += 1;
        }
        if norm[p..].len() >= 6 && norm[p..p + 6].eq_ignore_ascii_case(b"n:/nzb") {
            p += 6;
            while p < norm.len() && is_pcre_space(norm[p]) {
                p += 1;
            }
            if norm.get(p) == Some(&b'[') {
                p += 1;
                let d1 = p;
                while p < norm.len() && norm[p].is_ascii_digit() {
                    p += 1;
                }
                if p > d1 && norm.get(p) == Some(&b'/') {
                    p += 1;
                    let d2 = p;
                    while p < norm.len() && norm[p].is_ascii_digit() {
                        p += 1;
                    }
                    if p > d2 && norm.get(p) == Some(&b']') {
                        p += 1;
                        while p < norm.len() && is_pcre_space(norm[p]) {
                            p += 1;
                        }
                        if norm.get(p) == Some(&b'-') {
                            p += 1;
                            while p < norm.len() && is_pcre_space(norm[p]) {
                                p += 1;
                            }
                            if p > 0 {
                                norm = &norm[p..];
                                looks_obfuscated = true;
                            }
                        }
                    }
                }
            }
        }
    }

    // Prefix 2: `/^\s*N[\s._:-]*NZB[\s._-]*\[\d+(?:[\/_]\d+)?\][\s._-]*-\s*/i`
    {
        let mut p = 0usize;
        while p < norm.len() && is_pcre_space(norm[p]) {
            p += 1;
        }
        if norm.get(p).is_some_and(|&b| b.eq_ignore_ascii_case(&b'n')) {
            p += 1;
            while p < norm.len()
                && (is_pcre_space(norm[p]) || matches!(norm[p], b'.' | b'_' | b':' | b'-'))
            {
                p += 1;
            }
            if norm[p..].len() >= 3 && norm[p..p + 3].eq_ignore_ascii_case(b"nzb") {
                p += 3;
                while p < norm.len()
                    && (is_pcre_space(norm[p]) || matches!(norm[p], b'.' | b'_' | b'-'))
                {
                    p += 1;
                }
                if norm.get(p) == Some(&b'[') {
                    p += 1;
                    let d1 = p;
                    while p < norm.len() && norm[p].is_ascii_digit() {
                        p += 1;
                    }
                    if p > d1 {
                        if norm.get(p).is_some_and(|&c| c == b'/' || c == b'_') {
                            let mut p2 = p + 1;
                            let d2 = p2;
                            while p2 < norm.len() && norm[p2].is_ascii_digit() {
                                p2 += 1;
                            }
                            if p2 > d2 {
                                p = p2;
                            }
                        }
                        if norm.get(p) == Some(&b']') {
                            p += 1;
                            let mut run_end = p;
                            let mut last_dash = None;
                            while run_end < norm.len()
                                && (is_pcre_space(norm[run_end])
                                    || matches!(norm[run_end], b'.' | b'_' | b'-'))
                            {
                                if norm[run_end] == b'-' {
                                    last_dash = Some(run_end);
                                }
                                run_end += 1;
                            }
                            if let Some(dash_idx) = last_dash {
                                let mut after = dash_idx + 1;
                                while after < norm.len() && is_pcre_space(norm[after]) {
                                    after += 1;
                                }
                                if after > 0 {
                                    norm = &norm[after..];
                                    looks_obfuscated = true;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // Prefix 3: `/^\s*\[[^\]]{2,80}\]\s*/`
    {
        let mut p = 0usize;
        while p < norm.len() && is_pcre_space(norm[p]) {
            p += 1;
        }
        if norm.get(p) == Some(&b'[')
            && let Some(rel_close) = norm[p + 1..].iter().position(|&b| b == b']')
            && (2..=80).contains(&rel_close)
        {
            let mut after = p + 1 + rel_close + 1;
            while after < norm.len() && is_pcre_space(norm[after]) {
                after += 1;
            }
            if after > 0 {
                norm = &norm[after..];
                looks_obfuscated = true;
            }
        }
    }

    // Prefix 4: `/^\s*(?:re\s*)?posted\s+by\s+[^-]{2,120}\s*-\s*/i`
    {
        let mut p0 = 0usize;
        while p0 < norm.len() && is_pcre_space(norm[p0]) {
            p0 += 1;
        }
        let mut after_posted = None;
        if norm[p0..].len() >= 6 && norm[p0..p0 + 6].eq_ignore_ascii_case(b"posted") {
            after_posted = Some(p0 + 6);
        } else if norm[p0..].len() >= 8 && norm[p0..p0 + 2].eq_ignore_ascii_case(b"re") {
            let mut p_re = p0 + 2;
            while p_re < norm.len() && is_pcre_space(norm[p_re]) {
                p_re += 1;
            }
            if norm[p_re..].len() >= 6 && norm[p_re..p_re + 6].eq_ignore_ascii_case(b"posted") {
                after_posted = Some(p_re + 6);
            }
        }
        if let Some(ap) = after_posted {
            let mut p_by = ap;
            while p_by < norm.len() && is_pcre_space(norm[p_by]) {
                p_by += 1;
            }
            if p_by > ap
                && norm[p_by..].len() >= 2
                && norm[p_by..p_by + 2].eq_ignore_ascii_case(b"by")
            {
                let after_by = p_by + 2;
                if after_by < norm.len() && is_pcre_space(norm[after_by]) {
                    let span_start = after_by + 1;
                    if let Some(rel_dash) = norm[span_start..].iter().position(|&b| b == b'-') {
                        let dash_pos = span_start + rel_dash;
                        let span = &norm[span_start..dash_pos];
                        let mut c_start = 0usize;
                        while c_start < span.len() && is_pcre_space(span[c_start]) {
                            c_start += 1;
                        }
                        let mut c_end = span.len();
                        while c_end > c_start && is_pcre_space(span[c_end - 1]) {
                            c_end -= 1;
                        }
                        if span.len() >= 2 && (c_end - c_start) <= 120 {
                            let mut after = dash_pos + 1;
                            while after < norm.len() && is_pcre_space(norm[after]) {
                                after += 1;
                            }
                            if after > 0 {
                                norm = &norm[after..];
                                looks_obfuscated = true;
                            }
                        }
                    }
                }
            }
        }
    }

    // Quoted extraction
    if let Some(q) = find_quoted_3_to_240(norm, b'"') {
        norm = q;
        looks_obfuscated = true;
    } else if let Some(q) = find_quoted_3_to_240(norm, b'\'') {
        norm = q;
        looks_obfuscated = true;
    }

    // Trailing archive patterns
    if looks_obfuscated {
        // 1. `/\.part\d+\.rar$/i`
        if norm.len() >= 10 && norm[norm.len() - 4..].eq_ignore_ascii_case(b".rar") {
            let mut p = norm.len() - 4;
            let d_end = p;
            while p > 0 && norm[p - 1].is_ascii_digit() {
                p -= 1;
            }
            if p < d_end && p >= 5 && norm[p - 5..p].eq_ignore_ascii_case(b".part") {
                norm = &norm[..p - 5];
            }
        }
        // 2. `/\.r\d{2,4}$/i`
        {
            let mut p = norm.len();
            while p > 0 && norm[p - 1].is_ascii_digit() {
                p -= 1;
            }
            let digits = norm.len() - p;
            if (2..=4).contains(&digits)
                && p >= 2
                && norm[p - 2] == b'.'
                && norm[p - 1].eq_ignore_ascii_case(&b'r')
            {
                norm = &norm[..p - 2];
            }
        }
        // 3. `/\.7z\.\d{2,4}$/i`
        {
            let mut p = norm.len();
            while p > 0 && norm[p - 1].is_ascii_digit() {
                p -= 1;
            }
            let digits = norm.len() - p;
            if (2..=4).contains(&digits) && p >= 4 && norm[p - 4..p].eq_ignore_ascii_case(b".7z.") {
                norm = &norm[..p - 4];
            }
        }
        // 4. `/\.7z$/i`
        if norm.len() >= 3 && norm[norm.len() - 3..].eq_ignore_ascii_case(b".7z") {
            norm = &norm[..norm.len() - 3];
        }
        // 5. `/\.rar$/i`
        if norm.len() >= 4 && norm[norm.len() - 4..].eq_ignore_ascii_case(b".rar") {
            norm = &norm[..norm.len() - 4];
        }
        // 6. `/\.par2?$/i`
        if norm.len() >= 5 && norm[norm.len() - 5..].eq_ignore_ascii_case(b".par2") {
            norm = &norm[..norm.len() - 5];
        } else if norm.len() >= 4 && norm[norm.len() - 4..].eq_ignore_ascii_case(b".par") {
            norm = &norm[..norm.len() - 4];
        }
        // 7. `/\.part$/i`
        if norm.len() >= 5 && norm[norm.len() - 5..].eq_ignore_ascii_case(b".part") {
            norm = &norm[..norm.len() - 5];
        }
        // 8. `/\.vol\d+[+\-]\d+\.par2?$/i`
        {
            let par_trim =
                if norm.len() >= 5 && norm[norm.len() - 5..].eq_ignore_ascii_case(b".par2") {
                    Some(norm.len() - 5)
                } else if norm.len() >= 4 && norm[norm.len() - 4..].eq_ignore_ascii_case(b".par") {
                    Some(norm.len() - 4)
                } else {
                    None
                };
            if let Some(mut p) = par_trim {
                let d2_end = p;
                while p > 0 && norm[p - 1].is_ascii_digit() {
                    p -= 1;
                }
                if p < d2_end && p > 0 && matches!(norm[p - 1], b'+' | b'-') {
                    p -= 1;
                    let d1_end = p;
                    while p > 0 && norm[p - 1].is_ascii_digit() {
                        p -= 1;
                    }
                    if p < d1_end && p >= 4 && norm[p - 4..p].eq_ignore_ascii_case(b".vol") {
                        norm = &norm[..p - 4];
                    }
                }
            }
        }
        // 9. `/\.\d{3}$/`
        if norm.len() >= 4
            && norm[norm.len() - 4] == b'.'
            && norm[norm.len() - 3..].iter().all(|b| b.is_ascii_digit())
        {
            norm = &norm[..norm.len() - 4];
        }
    }

    // Transform '_', '+', '.(?=[A-Za-z0-9])' -> ' ', then collapse `\s{2,}` -> ' '
    let mut stack_buf = [0u8; 512];
    let mut heap_buf = Vec::new();
    let use_heap = norm.len() > stack_buf.len();
    if use_heap {
        heap_buf.resize(norm.len(), 0);
    }
    let stage1: &mut [u8] = if use_heap {
        &mut heap_buf[..]
    } else {
        &mut stack_buf[..norm.len()]
    };
    for i in 0..norm.len() {
        let b = norm[i];
        stage1[i] = if b == b'_'
            || b == b'+'
            || (b == b'.' && norm.get(i + 1).is_some_and(|c| c.is_ascii_alphanumeric()))
        {
            b' '
        } else {
            b
        };
    }

    // Collapse `\s{2,}` in-place inside `stage1`
    let mut w = 0usize;
    let mut r = 0usize;
    while r < stage1.len() {
        if is_pcre_space(stage1[r]) {
            let mut r_end = r + 1;
            while r_end < stage1.len() && is_pcre_space(stage1[r_end]) {
                r_end += 1;
            }
            if r_end - r >= 2 {
                stage1[w] = b' ';
            } else {
                stage1[w] = stage1[r];
            }
            w += 1;
            r = r_end;
        } else {
            stage1[w] = stage1[r];
            w += 1;
            r += 1;
        }
    }

    // Trim `" \t\n\r\0\x0B\"'`-_."`
    let mut t_start = 0usize;
    let mut t_end = w;
    while t_start < t_end && is_obfuscated_trim_char(stage1[t_start]) {
        t_start += 1;
    }
    while t_end > t_start && is_obfuscated_trim_char(stage1[t_end - 1]) {
        t_end -= 1;
    }
    let trimmed = &mut stage1[t_start..t_end];

    // `toReadableTitle`: if no uppercase ASCII letter, apply MB_CASE_TITLE
    if !trimmed.iter().any(|&b| b.is_ascii_uppercase()) {
        let mut in_word = false;
        for b in trimmed.iter_mut() {
            if b.is_ascii_alphabetic() {
                if !in_word {
                    *b = b.to_ascii_uppercase();
                    in_word = true;
                } else {
                    *b = b.to_ascii_lowercase();
                }
            } else if !matches!(*b, b'\'' | b'.' | b':' | b'^' | b'`') {
                in_word = false;
            }
        }
    }

    if trimmed.is_empty() || trimmed == original {
        return -3;
    }

    let mut cur = unsafe { OutCursor::new(out_buf, out_cap) };
    cur.extend(trimmed);
    cur.finish()
}

// libc's bounded byte search is vectorized on supported Linux/macOS hosts.
unsafe extern "C" {
    fn memchr(input: *const u8, byte: std::ffi::c_int, len: usize) -> *const u8;
    fn memmem(input: *const u8, len: usize, needle: *const u8, needle_len: usize) -> *const u8;
}

#[inline]
fn header_find_byte(bytes: &[u8], byte: u8) -> Option<usize> {
    let found = unsafe { memchr(bytes.as_ptr(), byte.into(), bytes.len()) };
    if found.is_null() {
        None
    } else {
        Some(unsafe { found.offset_from(bytes.as_ptr()) } as usize)
    }
}

fn header_has_yenc(subject: &[u8]) -> bool {
    for byte in [b'y', b'Y'] {
        let mut remaining = subject;
        while let Some(pos) = header_find_byte(remaining, byte) {
            if remaining
                .get(pos..pos + 4)
                .is_some_and(|s| s.eq_ignore_ascii_case(b"yenc"))
            {
                return true;
            }
            remaining = &remaining[pos + 1..];
        }
    }
    false
}

// HeaderParser's byte-oriented PCRE captures. A positive word packs name start (8 bits),
// name length (16), whitespace length (8), part/total digit lengths (8 each), and
// pattern type (1 = append yEnc, 2 = already present). Zero is no match; -1 declines.
// Digits remain spans, preserving leading zeros and values beyond PHP_INT_MAX.
fn header_subject(subject: &[u8]) -> i64 {
    // Bound work and decline spans that cannot be represented. PHP retains PCRE limits/errors.
    if subject.len() > 2048 {
        return -1;
    }
    let prefix = subject.iter().take_while(|&&b| is_pcre_space(b)).count();
    if prefix > 255 {
        return -1;
    }
    for start in (0..=prefix).rev() {
        if subject[start..].starts_with(b"Usenet Index Post") {
            continue;
        }
        if start == subject.len() || subject[start] == b'\n' {
            continue;
        }
        let mut search = start + 2;
        while search < subject.len() {
            let Some(relative) = header_find_byte(&subject[search..], b'(') else {
                break;
            };
            let pos = search + relative;
            search = pos + 1;
            if !is_pcre_space(subject[pos - 1]) {
                continue;
            }
            let mut name_end = pos - 1;
            while name_end > start + 1 && is_pcre_space(subject[name_end - 1]) {
                name_end -= 1;
            }
            if header_find_byte(&subject[start..name_end], b'\n').is_some() {
                break;
            }
            if pos - name_end > 255 {
                return -1;
            }
            let part_start = pos + 1;
            let mut end = part_start;
            while subject.get(end).is_some_and(u8::is_ascii_digit) {
                end += 1;
            }
            let part_len = end - part_start;
            if part_len > 0 && subject.get(end) == Some(&b'/') {
                let total_start = end + 1;
                end = total_start;
                while subject.get(end).is_some_and(u8::is_ascii_digit) {
                    end += 1;
                }
                let total_len = end - total_start;
                if total_len > 0 && subject.get(end) == Some(&b')') {
                    if part_len > 255 || total_len > 255 {
                        return -1;
                    }
                    let kind = if header_has_yenc(subject) { 2i64 } else { 1i64 };
                    return start as i64
                        | ((name_end - start) as i64) << 8
                        | ((pos - name_end) as i64) << 24
                        | (part_len as i64) << 32
                        | (total_len as i64) << 40
                        | kind << 48;
                }
            }
        }
    }
    0
}

/// Packed captures for HeaderParser's subject pattern; zero for no match, -1 for PHP fallback.
///
/// # Safety
/// `input` must be readable for `input_len` bytes when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_header_parse_subject(input: *const u8, input_len: usize) -> i64 {
    if input.is_null() {
        return -1;
    }
    header_subject(unsafe { slice::from_raw_parts(input, input_len) })
}

/// Batch subject captures: input is repeated (u32 little-endian length, subject bytes).
/// Output is one native-endian i64 packed result per record, without alignment requirements.
/// Returns output bytes, -1 for malformed framing, or negative required capacity.
///
/// # Safety
/// `input` must be readable for `input_len` bytes; `out_buf` writable for `out_cap` bytes.
/// Input and output must not overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_header_parse_batch(
    input: *const u8,
    input_len: usize,
    count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> i64 {
    let Some(needed) = count.checked_mul(8).filter(|&n| n <= i64::MAX as usize) else {
        return -1;
    };
    if input.is_null() || out_buf.is_null() {
        return -1;
    }
    if out_cap < needed {
        return -(needed as i64);
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    let mut pos = 0usize;
    for index in 0..count {
        if bytes.len().saturating_sub(pos) < 4 {
            return -1;
        }
        let len = u32::from_le_bytes(bytes[pos..pos + 4].try_into().unwrap()) as usize;
        pos += 4;
        if len > bytes.len() - pos {
            return -1;
        }
        let packed = header_subject(&bytes[pos..pos + len]);
        unsafe { std::ptr::write_unaligned(out_buf.add(index * 8).cast::<i64>(), packed) };
        pos += len;
    }
    if pos != bytes.len() {
        -1
    } else {
        needed as i64
    }
}

// Deliberately a small PCRE subset: ASCII literals with optional ^/$ anchors and
// escaped punctuation. Backreferences, character classes, modifiers, Unicode,
// alternations and every other PCRE construct are declined to preg_match.
fn blacklist_literal(pattern: &[u8]) -> Option<(u8, Vec<u8>)> {
    let mut flags = 0u8;
    let mut pos = 0;
    if pattern.first() == Some(&b'^') {
        flags |= 1;
        pos += 1;
    }
    let mut literal = Vec::with_capacity(pattern.len());
    while pos < pattern.len() {
        let b = pattern[pos];
        if b == b'$' && pos + 1 == pattern.len() {
            flags |= 2;
        } else if b == b'\\' {
            pos += 1;
            let &escaped = pattern.get(pos)?;
            if !escaped.is_ascii_punctuation() {
                return None;
            }
            literal.push(escaped.to_ascii_lowercase());
        } else if !b.is_ascii()
            || matches!(
                b,
                b'.' | b'^'
                    | b'$'
                    | b'|'
                    | b'('
                    | b')'
                    | b'['
                    | b']'
                    | b'{'
                    | b'}'
                    | b'*'
                    | b'+'
                    | b'?'
                    | b'/'
            )
        {
            return None;
        } else {
            literal.push(b.to_ascii_lowercase());
        }
        pos += 1;
    }
    Some((flags, literal))
}

/// Compile (field byte, u32 LE regex length, regex) records to literal matching records.
/// Output records are (field byte, anchor flags byte, u16 LE literal length, literal).
/// Returns -1 when any pattern needs PCRE; otherwise output length/negative required capacity.
///
/// # Safety
/// `input` must be readable for `input_len` bytes, `out_buf` writable for `out_cap` bytes.
/// Input and output must not overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_blacklist_compile(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> i64 {
    if input.is_null() {
        return -1;
    }
    let mut bytes = unsafe { slice::from_raw_parts(input, input_len) };
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    while !bytes.is_empty() {
        if bytes.len() < 5 || !(1..=3).contains(&bytes[0]) {
            return -1;
        }
        let field = bytes[0];
        let len = u32::from_le_bytes(bytes[1..5].try_into().unwrap()) as usize;
        bytes = &bytes[5..];
        if len > bytes.len() || len > u16::MAX as usize {
            return -1;
        }
        let Some((flags, literal)) = blacklist_literal(&bytes[..len]) else {
            return -1;
        };
        out.push(field);
        out.push(flags);
        out.extend(&(literal.len() as u16).to_le_bytes());
        out.extend(&literal);
        bytes = &bytes[len..];
    }
    out.finish() as i64
}

fn blacklist_contains(value: &[u8], literal: &[u8], flags: u8) -> bool {
    if literal.len() > value.len() {
        return false;
    }
    if flags & 1 != 0 {
        return value.starts_with(literal)
            && (flags & 2 == 0
                || literal.len() == value.len()
                || (literal.len() + 1 == value.len() && value.last() == Some(&b'\n')));
    }
    if flags & 2 != 0 {
        return value.ends_with(literal)
            || (value.last() == Some(&b'\n') && value[..value.len() - 1].ends_with(literal));
    }
    literal.is_empty()
        || !unsafe { memmem(value.as_ptr(), value.len(), literal.as_ptr(), literal.len()) }
            .is_null()
}

/// Return the first matched rule's 1-based ordinal, zero for none, -1 for invalid records.
/// All fields are length-delimited, so embedded NUL/invalid UTF-8 remain ordinary bytes.
///
/// # Safety
/// All input pointers must be readable for their corresponding lengths, and non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_blacklist_match(
    rules: *const u8,
    rules_len: usize,
    subject: *const u8,
    subject_len: usize,
    poster: *const u8,
    poster_len: usize,
    message_id: *const u8,
    message_id_len: usize,
) -> i32 {
    if rules.is_null() || subject.is_null() || poster.is_null() || message_id.is_null() {
        return -1;
    }
    let mut rules = unsafe { slice::from_raw_parts(rules, rules_len) };
    let fields = unsafe {
        [
            slice::from_raw_parts(subject, subject_len),
            slice::from_raw_parts(poster, poster_len),
            slice::from_raw_parts(message_id, message_id_len),
        ]
    };
    let mut lowered: [Option<Vec<u8>>; 3] = [None, None, None];
    // Probe each distinct literal byte at most once per field. Shared absent bytes
    // reject entire rule families before libc scans a long or repetitive subject.
    let mut byte_presence = [[0u8; 128]; 3];
    let mut index = 0i32;
    while !rules.is_empty() {
        if rules.len() < 4 || !(1..=3).contains(&rules[0]) || rules[1] > 3 {
            return -1;
        }
        let field = (rules[0] - 1) as usize;
        let flags = rules[1];
        let len = u16::from_le_bytes([rules[2], rules[3]]) as usize;
        rules = &rules[4..];
        if len > rules.len() || index == i32::MAX {
            return -1;
        }
        index += 1;
        let value = lowered[field].get_or_insert_with(|| fields[field].to_ascii_lowercase());
        if flags == 0 && len > 0 {
            let mut possible = true;
            for &byte in &rules[..len] {
                if byte >= 128 {
                    return -1;
                }
                let presence = &mut byte_presence[field][byte as usize];
                if *presence == 0 {
                    *presence = if header_find_byte(value, byte).is_some() {
                        1
                    } else {
                        2
                    };
                }
                if *presence == 2 {
                    possible = false;
                    break;
                }
            }
            if !possible {
                rules = &rules[len..];
                continue;
            }
        }
        if blacklist_contains(value, &rules[..len], flags) {
            return index;
        }
        rules = &rules[len..];
    }
    0
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn header_subject_packed_spans_and_binary_inputs() {
        let packed = header_subject(b"\tfile\0name yEnc (001/999)");
        assert_eq!(packed & 255, 1);
        assert_eq!((packed >> 8) & 65535, 14);
        assert_eq!((packed >> 24) & 255, 1);
        assert_eq!((packed >> 32) & 255, 3);
        assert_eq!((packed >> 40) & 255, 3);
        assert_eq!(packed >> 48, 2);
        assert_eq!(header_subject(b"Usenet Index Post file (1/2)"), 0);
        assert!(header_subject(b" Usenet Index Post file (1/2)") > 0);
        assert!(header_subject(b" \n(1/2)") > 0);
        assert_eq!(header_subject(b"file\nother (1/2)"), 0);
        assert_eq!(header_subject(&vec![b'x'; 2049]), -1);
        assert_eq!(header_subject(b"file [1/2]"), 0);
        assert_eq!(header_subject(b"file (1/2)") >> 48, 1);
        assert_eq!(
            unsafe { nntmux_header_parse_subject(std::ptr::null(), 0) },
            -1
        );
    }

    #[test]
    fn header_batch_checks_lengths_and_unaligned_output() {
        let mut input = Vec::new();
        for subject in [
            b"file (01/02)".as_slice(),
            b"no counter",
            b"a\0b yEnc (2/3)",
        ] {
            input.extend_from_slice(&(subject.len() as u32).to_le_bytes());
            input.extend_from_slice(subject);
        }
        let mut output = [0u8; 25];
        assert_eq!(
            unsafe {
                nntmux_header_parse_batch(
                    input.as_ptr(),
                    input.len(),
                    3,
                    output.as_mut_ptr().add(1),
                    24,
                )
            },
            24
        );
        assert_eq!(i64::from_ne_bytes(output[9..17].try_into().unwrap()), 0);
        assert_eq!(
            unsafe {
                nntmux_header_parse_batch(input.as_ptr(), input.len(), 3, output.as_mut_ptr(), 8)
            },
            -24
        );
        assert_eq!(
            unsafe {
                nntmux_header_parse_batch(
                    input.as_ptr(),
                    input.len() - 1,
                    3,
                    output.as_mut_ptr(),
                    24,
                )
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_header_parse_batch(input.as_ptr(), input.len(), 2, output.as_mut_ptr(), 24)
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_header_parse_batch(
                    input.as_ptr(),
                    input.len(),
                    usize::MAX,
                    output.as_mut_ptr(),
                    24,
                )
            },
            -1
        );
    }

    #[test]
    fn blacklist_literal_subset_and_first_match() {
        assert_eq!(blacklist_literal(br"^a\.b$"), Some((3, b"a.b".to_vec())));
        for pattern in [
            br"(a)\1".as_slice(),
            br"\w+",
            br"(?=x)",
            br"a/b",
            br"[ab]",
            br"a|b",
            br"a\",
            b"\xff",
        ] {
            assert!(blacklist_literal(pattern).is_none());
        }
        assert!(blacklist_contains(b"needle\n", b"needle", 3));
        assert!(!blacklist_contains(b"needle\r\n", b"needle", 3));
        assert!(blacklist_contains(b"a\0b", b"\0", 0));
        assert!(blacklist_contains(b"anything", b"", 0));
        let mut input = Vec::new();
        for (field, pattern) in [(1, "absent"), (2, "^poster$"), (3, "id")] {
            input.push(field);
            input.extend_from_slice(&(pattern.len() as u32).to_le_bytes());
            input.extend_from_slice(pattern.as_bytes());
        }
        let mut out = [0u8; 128];
        let len = unsafe {
            nntmux_blacklist_compile(input.as_ptr(), input.len(), out.as_mut_ptr(), out.len())
        };
        assert!(len > 0);
        assert_eq!(
            unsafe { nntmux_blacklist_compile(input.as_ptr(), input.len(), out.as_mut_ptr(), 1) },
            -len
        );
        assert_eq!(
            unsafe {
                nntmux_blacklist_match(
                    out.as_ptr(),
                    len as usize,
                    b"clean".as_ptr(),
                    5,
                    b"POSTER\n".as_ptr(),
                    7,
                    b"<ID>".as_ptr(),
                    4,
                )
            },
            2
        );
        assert_eq!(
            unsafe {
                nntmux_blacklist_match(
                    out.as_ptr(),
                    len as usize,
                    b"clean".as_ptr(),
                    5,
                    b"other".as_ptr(),
                    5,
                    b"<ID>".as_ptr(),
                    4,
                )
            },
            3
        );
        assert_eq!(
            unsafe {
                nntmux_blacklist_compile(
                    input.as_ptr(),
                    input.len() - 1,
                    out.as_mut_ptr(),
                    out.len(),
                )
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_blacklist_match(
                    out.as_ptr(),
                    1,
                    b"".as_ptr(),
                    0,
                    b"".as_ptr(),
                    0,
                    b"".as_ptr(),
                    0,
                )
            },
            -1
        );
    }

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

    #[test]
    fn test_extract_obfuscated_subject() {
        let mut buf = [0u8; 256];
        let input =
            b"N:/NZB [01/25] - \"the.matrix.1999.1080p.bluray.x264-group.part01.rar\" yEnc (1/50)";
        let len = unsafe {
            nntmux_extract_obfuscated_subject(
                input.as_ptr(),
                input.len(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(len > 0);
        assert_eq!(
            &buf[..len as usize],
            b"The Matrix 1999 1080P Bluray X264-Group"
        );
    }
}

// Collections use byte-mode PCRE, followed by PHP trim and encoding detection. This
// fast path accepts a certified grammar rather than approximating arbitrary regexes.
// Everything outside it is returned to PHP, including subjects over this stack bound.
const COLLECTIONS_MAX_SUBJECT: usize = 4096;

fn collections_counter(bytes: &[u8], max_digits: usize) -> Option<usize> {
    let close = match bytes.first()? {
        b'[' => b']',
        b'(' => b')',
        _ => return None,
    };
    let mut pos = 1;
    let first = pos;
    while bytes.get(pos).is_some_and(u8::is_ascii_digit) {
        pos += 1;
    }
    if !(1..=max_digits).contains(&(pos - first)) || bytes.get(pos) != Some(&b'/') {
        return None;
    }
    pos += 1;
    let second = pos;
    while bytes.get(pos).is_some_and(u8::is_ascii_digit) {
        pos += 1;
    }
    if !(1..=max_digits).contains(&(pos - second)) || bytes.get(pos) != Some(&close) {
        return None;
    }
    Some(pos + 1)
}

fn collections_has_ci(bytes: &[u8], needle: &[u8]) -> bool {
    bytes
        .windows(needle.len())
        .any(|v| v.eq_ignore_ascii_case(needle))
}

// Certify that none of the five replacement stages can alter this scene-style
// name. Prefix checks intentionally decline more than the PHP patterns match.
fn collections_safe_title(title: &[u8]) -> bool {
    if title.is_empty() || title[0].is_ascii_digit() {
        return false;
    }
    for (pos, &byte) in title.iter().enumerate() {
        if !byte.is_ascii_alphanumeric() && !matches!(byte, b'.' | b'_' | b'-') {
            return false;
        }
        let tail = &title[pos..];
        let forbidden = match byte.to_ascii_lowercase() {
            b'p' => tail
                .get(..4)
                .is_some_and(|v| v.eq_ignore_ascii_case(b"part")),
            b'v' => tail
                .get(..3)
                .is_some_and(|v| v.eq_ignore_ascii_case(b"vol")),
            b'a' => {
                tail.get(..10)
                    .is_some_and(|v| v.eq_ignore_ascii_case(b"autorarpar"))
                    || tail
                        .get(..7)
                        .is_some_and(|v| v.eq_ignore_ascii_case(b"amateur"))
            }
            b'c' => tail
                .get(..7)
                .is_some_and(|v| v.eq_ignore_ascii_case(b"classic")),
            b'-' => tail.get(1).is_some_and(u8::is_ascii_digit),
            b'0'..=b'9' => tail
                .get(1..4)
                .is_some_and(|v| v[..2].eq_ignore_ascii_case(b"of") && v[2].is_ascii_digit()),
            _ => false,
        };
        if forbidden {
            return false;
        }
        if byte != b'.' {
            continue;
        }
        let tail = &title[pos + 1..];
        if tail.len() == 3 && tail.iter().all(u8::is_ascii_digit) {
            return false;
        }
        if tail
            .get(..2)
            .is_some_and(|v| v.eq_ignore_ascii_case(b"7z") || v.eq_ignore_ascii_case(b"zz"))
        {
            return false;
        }
        let Some(prefix) = tail.get(..3) else {
            continue;
        };
        let prefix = [
            prefix[0].to_ascii_lowercase(),
            prefix[1].to_ascii_lowercase(),
            prefix[2].to_ascii_lowercase(),
        ];
        if matches!(
            &prefix,
            b"avi"
                | b"diz"
                | b"doc"
                | b"idx"
                | b"iso"
                | b"jpg"
                | b"m3u"
                | b"m4a"
                | b"mds"
                | b"mkv"
                | b"mp4"
                | b"mu3"
                | b"nfo"
                | b"nzb"
                | b"par"
                | b"pdf"
                | b"rar"
                | b"rev"
                | b"rtf"
                | b"sfv"
                | b"srs"
                | b"srr"
                | b"sub"
                | b"txt"
                | b"xls"
                | b"zip"
        ) || prefix[0] == b'r' && prefix[1].is_ascii_digit() && prefix[2].is_ascii_digit()
            || prefix == *b"epu" && tail.get(3).is_some_and(|v| v.eq_ignore_ascii_case(&b'b'))
            || prefix == *b"mob" && tail.get(3).is_some_and(|v| v.eq_ignore_ascii_case(&b'i'))
        {
            return false;
        }
    }
    true
}

fn collections_normalize(input: &[u8], output: &mut [u8]) -> Option<usize> {
    let mut written = 0;
    let mut pos = 0;
    // Delay trim bytes: trailing PHP trim characters must disappear, but a
    // single form feed survives while a run of form feeds becomes one space.
    let mut kept = 0;
    while pos < input.len() {
        let mut byte = input[pos];
        pos += 1;
        if is_pcre_space(byte) && input.get(pos).is_some_and(|b| is_pcre_space(*b)) {
            while input.get(pos).is_some_and(|b| is_pcre_space(*b)) {
                pos += 1;
            }
            byte = b' ';
        }
        if written == 0 && is_php_trim_char(byte) {
            continue;
        }
        *output.get_mut(written)? = byte;
        written += 1;
        if !is_php_trim_char(byte) {
            kept = written;
        }
    }
    Some(kept)
}

fn collections_pre_normalize(subject: &[u8], music: bool, output: &mut [u8]) -> Option<usize> {
    if subject.len() > COLLECTIONS_MAX_SUBJECT {
        return None;
    }
    let mut start = 0;
    let mut end = subject.len();
    while start < end && (is_pcre_space(subject[start]) || subject[start] == 0) {
        start += 1;
    }
    while end > start && (is_pcre_space(subject[end - 1]) || subject[end - 1] == 0) {
        end -= 1;
    }
    let mut core = &subject[start..end];
    let mut stage = [0u8; COLLECTIONS_MAX_SUBJECT];
    let mut cursor = 0;
    stage[..start].copy_from_slice(&subject[..start]);
    cursor += start;
    let max_digits = if music { 4 } else { 5 };
    if let Some(count) = collections_counter(core, max_digits) {
        stage[cursor] = b' ';
        cursor += 1;
        core = &core[count..];
        if core.starts_with(b" - ") {
            stage[cursor..cursor + 3].copy_from_slice(b" - ");
            cursor += 3;
            core = &core[3..];
        } else if core.starts_with(b" ") {
            core = &core[1..];
        } else if !core.is_empty() {
            return None;
        }
    }
    let mut trailing_counter = false;
    if core.ends_with(b")") || core.ends_with(b"]") {
        if let Some(pos) = core.iter().rposition(|&b| b == b'(' || b == b'[') {
            if pos > 0
                && core[pos - 1] == b' '
                && collections_counter(&core[pos..], max_digits) == Some(core.len() - pos)
            {
                trailing_counter = true;
                core = &core[..pos - 1];
            }
        }
    }
    let mut yenc = &b""[..];
    if core.len() >= 5
        && core[core.len() - 5] == b' '
        && core[core.len() - 4..].eq_ignore_ascii_case(b"yenc")
    {
        yenc = &core[core.len() - 5..];
        core = &core[..core.len() - 5];
    }
    let mut title_prefix = &b""[..];
    if let Some(quote) = core.iter().position(|&b| b == b'"') {
        if quote > 0 {
            if core[quote - 1] != b' ' {
                return None;
            }
            let prefix = &core[..quote - 1];
            if !collections_safe_title(prefix) || prefix.first().is_some_and(u8::is_ascii_digit) {
                return None;
            }
            title_prefix = &core[..quote];
            core = &core[quote..];
        }
    }
    let quoted = core.starts_with(b"\"");
    if quoted {
        if core.len() < 2 || !core.ends_with(b"\"") {
            return None;
        }
        core = &core[1..core.len() - 1];
    }
    if music && quoted {
        // The numbering stage cannot consume a quote or use its greedy .+
        // branch without whitespace inside this token. Its local replacements
        // therefore leave both quotes for the next stage's greedy quote removal.
        if core.is_empty() || core.iter().any(|&b| is_pcre_space(b) || b == b'"') {
            return None;
        }
        stage[cursor..cursor + title_prefix.len()].copy_from_slice(title_prefix);
        cursor += title_prefix.len();
        stage[cursor] = b' ';
        cursor += 1;
        stage[cursor..cursor + yenc.len()].copy_from_slice(yenc);
        cursor += yenc.len();
        if trailing_counter {
            stage[cursor] = b' ';
            cursor += 1;
        }
        stage[cursor..cursor + subject.len() - end].copy_from_slice(&subject[end..]);
        cursor += subject.len() - end;
        return collections_normalize(&stage[..cursor], output);
    }
    let mut title = core;
    let mut archive = false;
    let mut part_archive = false;
    if core.len() >= 4 && core[core.len() - 4..].eq_ignore_ascii_case(b".rar") {
        archive = true;
        title = &core[..core.len() - 4];
        let mut digit = title.len();
        while digit > 0 && title[digit - 1].is_ascii_digit() {
            digit -= 1;
        }
        if digit < title.len()
            && digit >= 5
            && title[digit - 5..digit].eq_ignore_ascii_case(b".part")
        {
            if title.len() - digit > 5 {
                return None;
            }
            part_archive = true;
            title = &title[..digit - 5];
        }
        if part_archive
            && title.len() >= 4
            && title[title.len() - 4] == b'.'
            && title[title.len() - 3..].iter().all(u8::is_ascii_digit)
        {
            return None;
        }
        // Certify before suffix digit removal: otherwise it can erase the
        // final digit in a numbering pattern such as A.12341of2.rar, hiding
        // an earlier PHP replacement stage from the final-title guard.
        if !collections_safe_title(title) {
            return None;
        }
        // Stage four consumes up to five title digits even across the space
        // inserted when stage two replaced .partNN. Preserve this PHP quirk.
        let mut digit = title.len();
        let trim_digits = if music && part_archive { 3 } else { 5 };
        while digit > 0 && title[digit - 1].is_ascii_digit() && title.len() - digit < trim_digits {
            digit -= 1;
        }
        title = &title[..digit];
        // Decline optional sample suffixes; music and generic replacement order differs.
        if collections_has_ci(title, b"sample") {
            return None;
        }
    }
    if !collections_safe_title(title) && !(core.is_empty() && !quoted) {
        return None;
    }
    if quoted && !archive {
        return None;
    }
    if !archive {
        if let Some(dot) = title.iter().rposition(|&b| b == b'.') {
            if title.len() - dot == 4
                && title[dot + 1..].iter().all(u8::is_ascii_digit)
                && (!yenc.is_empty() || trailing_counter)
            {
                return None;
            }
        }
    }
    stage[cursor..cursor + title_prefix.len()].copy_from_slice(title_prefix);
    cursor += title_prefix.len();
    if quoted {
        stage[cursor] = b' ';
        cursor += 1;
    }
    stage[cursor..cursor + title.len()].copy_from_slice(title);
    cursor += title.len();
    if archive {
        stage[cursor] = b' ';
        cursor += 1;
    }
    stage[cursor..cursor + yenc.len()].copy_from_slice(yenc);
    cursor += yenc.len();
    if trailing_counter {
        stage[cursor] = b' ';
        cursor += 1;
    }
    stage[cursor..cursor + subject.len() - end].copy_from_slice(&subject[end..]);
    cursor += subject.len() - end;
    collections_normalize(&stage[..cursor], output)
}

fn collections_special_music_capture(subject: &[u8]) -> Option<&[u8]> {
    let stripped = subject.strip_suffix(b"\n").unwrap_or(subject);
    let mut pos = 0;
    while stripped.get(pos).is_some_and(|&b| is_pcre_word(b)) {
        pos += 1;
    }
    if pos < 10 || stripped.get(pos) != Some(&b'-') {
        return None;
    }
    pos += 1;
    let album = pos;
    while stripped.get(pos).is_some_and(u8::is_ascii_alphanumeric) {
        pos += 1;
    }
    if pos == album || !stripped[pos..].starts_with(b" \"") {
        return None;
    }
    pos += 2;
    let capture_end = pos;
    if !stripped.get(pos).is_some_and(u8::is_ascii_digit)
        || !stripped.get(pos + 1).is_some_and(u8::is_ascii_digit)
        || stripped.get(pos + 2) != Some(&b'-')
    {
        return None;
    }
    pos += 3;
    if stripped.len() >= pos + 7
        && stripped.ends_with(b"\" yEnc")
        && !stripped[pos..stripped.len() - 6].contains(&b'\n')
    {
        Some(&stripped[..capture_end])
    } else {
        None
    }
}

fn collections_special_music(subject: &[u8]) -> bool {
    collections_special_music_capture(subject).is_some()
}

fn collections_is_music_group_bytes(group: &[u8]) -> bool {
    [
        b".flac".as_slice(),
        b".lossless",
        b".mp3",
        b".music",
        b".sounds",
    ]
    .iter()
    .any(|needle| group.windows(needle.len()).any(|v| v == *needle))
}

/// Check whether `group` matches `/\.(flac|lossless|mp3|music|sounds)/`.
/// Returns 1 on match, 0 on non-match, or -1 on invalid pointer/length.
///
/// # Safety
/// Non-null `group` must be readable for `group_len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_collections_is_music_group(
    group: *const u8,
    group_len: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if group_len > isize::MAX as usize || (group.is_null() && group_len != 0) {
            return -1;
        }
        let group_slice = if group_len == 0 {
            &[][..]
        } else {
            unsafe { slice::from_raw_parts(group, group_len) }
        };
        i32::from(collections_is_music_group_bytes(group_slice))
    }))
    .unwrap_or(-1)
}

/// Extract `$hit[1]` for `CollectionsCleaningService::musicSubject()`
/// (`/^(\w{10,}-[a-zA-Z0-9]+ ")\d\d-.+?" yEnc$/`).
/// Returns bytes written (`>= 14`) on match, `0` when unmatched (`false`),
/// `-(needed as isize)` when `out_cap` is too small, or `-1` on invalid arguments.
///
/// # Safety
/// Non-null pointers must be valid for their stated lengths and must not overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_collections_extract_music_subject(
    input: *const u8,
    input_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if input_len > isize::MAX as usize
            || out_cap > isize::MAX as usize
            || (input.is_null() && input_len != 0)
            || (out.is_null() && out_cap != 0)
        {
            return -1;
        }
        let subject = if input_len == 0 {
            &[][..]
        } else {
            unsafe { slice::from_raw_parts(input, input_len) }
        };
        let Some(captured) = collections_special_music_capture(subject) else {
            return 0;
        };
        if captured.len() > out_cap {
            return -(captured.len() as isize);
        }
        let output = unsafe { slice::from_raw_parts_mut(out, out_cap) };
        output[..captured.len()].copy_from_slice(captured);
        captured.len() as isize
    }))
    .unwrap_or(-1)
}

/// Return collapsed and PHP-trimmed bytes before mbstring encoding detection,
/// or -1 when any replacement is outside the certified byte grammar.
///
/// # Safety
/// Input must be readable for its length and output writable for its capacity.
/// Buffers must not overlap. Null pointers are always declined, including empty inputs.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_collections_clean_generic_pre_normalize(
    input: *const u8,
    input_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if input.is_null()
            || out.is_null()
            || out_cap > isize::MAX as usize
            || input_len > COLLECTIONS_MAX_SUBJECT
        {
            return -1;
        }
        collections_pre_normalize(
            unsafe { slice::from_raw_parts(input, input_len) },
            false,
            unsafe { slice::from_raw_parts_mut(out, out_cap) },
        )
        .map_or(-1, |len| len as isize)
    }))
    .unwrap_or(-1)
}

/// Music route: -2 means a nonmusic group, -3 delegates the exact musicSubject
/// special pattern, -1 delegates generic music cleaning; otherwise bytes before encoding.
///
/// # Safety
/// Pointers must be non-null and readable/writable for their respective lengths.
/// Input, group and output buffers must not overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_collections_music_subject(
    input: *const u8,
    input_len: usize,
    group: *const u8,
    group_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if input.is_null()
            || group.is_null()
            || out.is_null()
            || input_len > COLLECTIONS_MAX_SUBJECT
            || group_len > isize::MAX as usize
            || out_cap > isize::MAX as usize
        {
            return -1;
        }
        let group = unsafe { slice::from_raw_parts(group, group_len) };
        if !collections_is_music_group_bytes(group) {
            return -2;
        }
        let subject = unsafe { slice::from_raw_parts(input, input_len) };
        if collections_special_music(subject) {
            return -3;
        }
        collections_pre_normalize(subject, true, unsafe {
            slice::from_raw_parts_mut(out, out_cap)
        })
        .map_or(-1, |len| len as isize)
    }))
    .unwrap_or(-1)
}

/// Exact short-name enhancement for no-LF subjects with balanced, nonempty quote
/// pairs. Return -1 for ambiguous pairing/extension interactions or insufficient output.
///
/// # Safety
/// Pointers must be non-null and valid for their lengths. Buffers must not overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_collections_enhance_short_subject(
    input: *const u8,
    input_len: usize,
    clean: *const u8,
    clean_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if input.is_null()
            || clean.is_null()
            || out.is_null()
            || input_len > COLLECTIONS_MAX_SUBJECT
            || clean_len > isize::MAX as usize
            || out_cap > isize::MAX as usize
        {
            return -1;
        }
        let subject = unsafe { slice::from_raw_parts(input, input_len) };
        let clean = unsafe { slice::from_raw_parts(clean, clean_len) };
        if subject.contains(&b'\n') {
            return -1;
        }
        let mut inside = false;
        let mut open = 0;
        let mut capture = None;
        let mut first_non_alnum = [0u8; 3];
        let mut first_count = 0;
        let mut punctuation = [0u8; COLLECTIONS_MAX_SUBJECT];
        let mut punctuation_len = 0;
        for (pos, &byte) in subject.iter().enumerate() {
            if !byte.is_ascii_alphanumeric() && first_count < 3 {
                first_non_alnum[first_count] = byte;
                first_count += 1;
            }
            if byte == b'"' {
                if inside {
                    if pos == open + 1 {
                        return -1;
                    }
                    inside = false;
                } else {
                    inside = true;
                    open = pos;
                }
                if subject.get(pos + 1).is_some_and(u8::is_ascii_alphanumeric)
                    && subject[pos + 1..].contains(&b'"')
                {
                    let mut end = pos + 1;
                    while subject.get(end).is_some_and(u8::is_ascii_alphanumeric) {
                        end += 1;
                    }
                    capture = Some(&subject[pos..end]);
                }
            } else if !inside && !byte.is_ascii_alphanumeric() {
                punctuation[punctuation_len] = byte;
                punctuation_len += 1;
            }
        }
        if inside {
            return -1;
        }
        let capture = capture.unwrap_or(b"");
        let Some(needed) = clean
            .len()
            .checked_add(punctuation_len)
            .and_then(|n| n.checked_add(capture.len()))
            .and_then(|n| n.checked_add(first_count))
        else {
            return -1;
        };
        if needed > out_cap || needed > isize::MAX as usize {
            return -1;
        }
        let output = unsafe { slice::from_raw_parts_mut(out, out_cap) };
        let mut pos = 0;
        for piece in [
            clean,
            &punctuation[..punctuation_len],
            capture,
            &first_non_alnum[..first_count],
        ] {
            output[pos..pos + piece.len()].copy_from_slice(piece);
            pos += piece.len();
        }
        needed as isize
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod collections_tests {
    use super::*;

    fn generic(subject: &[u8]) -> Result<Vec<u8>, isize> {
        let mut out = [0u8; 4096];
        let len = unsafe {
            nntmux_collections_clean_generic_pre_normalize(
                subject.as_ptr(),
                subject.len(),
                out.as_mut_ptr(),
                out.len(),
            )
        };
        if len < 0 {
            Err(len)
        } else {
            Ok(out[..len as usize].to_vec())
        }
    }
    fn music(subject: &[u8], group: &[u8]) -> Result<Vec<u8>, isize> {
        let mut out = [0u8; 4096];
        let len = unsafe {
            nntmux_collections_music_subject(
                subject.as_ptr(),
                subject.len(),
                group.as_ptr(),
                group.len(),
                out.as_mut_ptr(),
                out.len(),
            )
        };
        if len < 0 {
            Err(len)
        } else {
            Ok(out[..len as usize].to_vec())
        }
    }
    fn enhance(subject: &[u8], clean: &[u8]) -> Result<Vec<u8>, isize> {
        let mut out = [0u8; 4096];
        let len = unsafe {
            nntmux_collections_enhance_short_subject(
                subject.as_ptr(),
                subject.len(),
                clean.as_ptr(),
                clean.len(),
                out.as_mut_ptr(),
                out.len(),
            )
        };
        if len < 0 {
            Err(len)
        } else {
            Ok(out[..len as usize].to_vec())
        }
    }

    #[test]
    fn collections_common_archive_preserves_yenc_and_punctuation() {
        assert_eq!(
            generic(br#"[01/25] - "Movie.Title.2026-GRP.part01.rar" yEnc (1/50)"#),
            Ok(b"- Movie.Title.2026-GRP yEnc".to_vec())
        );
        assert_eq!(
            generic(br#""Movie.Title-GRP.rar" yEnc"#),
            Ok(b"Movie.Title-GRP yEnc".to_vec())
        );
        assert_eq!(
            generic(br#"Movie.Title.2026.part001.rar"#),
            Ok(b"Movie.Title.".to_vec())
        );
        assert_eq!(
            generic(
                br#"[1/50] Some.Show.S01E02.1080p.WEB-DL-GROUP "release.part001.rar" yEnc (1/50)"#
            ),
            Ok(b"Some.Show.S01E02.1080p.WEB-DL-GROUP release yEnc".to_vec())
        );
        assert_eq!(generic(b"Plain.Title-GRP"), Ok(b"Plain.Title-GRP".to_vec()));
    }

    #[test]
    fn collections_whitespace_uses_pcre_runs_and_php_trim() {
        assert_eq!(
            generic(b"\0\tPlain.Title-GRP\r\0"),
            Ok(b"Plain.Title-GRP".to_vec())
        );
        assert_eq!(
            generic(b"\x0cPlain.Title-GRP\x0c"),
            Ok(b"\x0cPlain.Title-GRP\x0c".to_vec())
        );
        assert_eq!(
            generic(b"\x0c\x0cPlain.Title-GRP\x0c\x0c"),
            Ok(b"Plain.Title-GRP".to_vec())
        );
        assert_eq!(
            generic(b"\x0c\0\t\"Title.Name-GRP.rar\" (1/50)"),
            Ok(b"\x0c\0 Title.Name-GRP".to_vec())
        );
        assert_eq!(generic(b""), Ok(Vec::new()));
    }

    #[test]
    fn collections_music_routes_groups_and_special_pattern() {
        assert_eq!(
            music(b"Movie.2026.part001.rar", b"alt.music"),
            Ok(b"Movie.2".to_vec())
        );
        assert_eq!(music(b"Plain.Title-GRP", b"alt.binaries.Music"), Err(-2));
        assert_eq!(
            music(b"Plain.Title-GRP", b"alt.binaries.music"),
            Ok(b"Plain.Title-GRP".to_vec())
        );
        assert_eq!(
            music(
                br#"[01/25] - "Album.Title-GRP.part01.rar" yEnc (1/50)"#,
                b"alt.binaries.flac"
            ),
            Ok(b"- yEnc".to_vec())
        );
        assert_eq!(
            music(
                br#"[1/50] Artist_Album_2026_GRP "release.part001.rar" yEnc (1/50)"#,
                b"alt.binaries.music"
            ),
            Ok(b"Artist_Album_2026_GRP yEnc".to_vec())
        );
        assert_eq!(
            music(
                br#"Broderick_Smith-Unknown "00-broderick.sfv" yEnc"#,
                b"alt.music"
            ),
            Err(-3)
        );
    }

    #[test]
    fn collections_complex_branches_decline_instead_of_approximating() {
        for subject in [
            b"Movie 365.15 KB yEnc".as_slice(),
            b"Title1of2.Name.rar",
            b"A.12341of2.rar",
            b" 1024KB ",
            b"[1/5] foo.mu3",
            b"Title.123.part01.rar",
            br#"[1/2] "Title.123.part01.rar" yEnc"#,
            b"Title1OF2.Name.rar",
            b"AutoRarPar123 Movie",
            b"AutoRarPar1",
            b"22Amateur",
            b"Movie.vol01+03.par2",
            b"Album 01. artist.mp3",
            b"abce-12-34.rar",
            b"Movie.jpg",
            b"test.foo\"",
            b"a\nb",
            b"\xffMovie.rar",
            b"Movie.part123456.rar",
            b"Movie - sample.rar",
        ] {
            assert_eq!(generic(subject), Err(-1), "{:?}", subject);
        }
    }

    #[test]
    fn collections_enhancement_preserves_byte_append_order() {
        assert_eq!(enhance(b"a-b_c.d", b"short"), Ok(b"short-_.-_.".to_vec()));
        assert_eq!(
            enhance(br#""ABC.def" yEnc (1/2)"#, b"yEnc"),
            Ok(br##"yEnc  (/)"ABC".""##.to_vec())
        );
        assert_eq!(enhance(b"\xffa\0b!", b"x"), Ok(b"x\xff\0!\xff\0!".to_vec()));
        assert_eq!(enhance(br#"unmatched "foo.rar"#, b"x"), Err(-1));
        assert_eq!(enhance(b"a\nb", b"x"), Err(-1));
    }

    #[test]
    fn collections_abi_rejects_null_and_insufficient_capacity() {
        let mut out = [0x55; 2];
        unsafe {
            assert_eq!(
                nntmux_collections_clean_generic_pre_normalize(
                    std::ptr::null(),
                    0,
                    out.as_mut_ptr(),
                    2
                ),
                -1
            );
            assert_eq!(
                nntmux_collections_clean_generic_pre_normalize(
                    b"Title".as_ptr(),
                    5,
                    out.as_mut_ptr(),
                    2
                ),
                -1
            );
            assert_eq!(
                nntmux_collections_clean_generic_pre_normalize(
                    b"".as_ptr(),
                    0,
                    std::ptr::null_mut(),
                    0
                ),
                -1
            );
            assert_eq!(
                nntmux_collections_music_subject(
                    b"Title".as_ptr(),
                    5,
                    std::ptr::null(),
                    0,
                    out.as_mut_ptr(),
                    2
                ),
                -1
            );
            assert_eq!(
                nntmux_collections_enhance_short_subject(
                    b"".as_ptr(),
                    0,
                    std::ptr::null(),
                    0,
                    out.as_mut_ptr(),
                    2
                ),
                -1
            );
        }
    }
}

// ============================================================================
// Wave 7 Slice 7.5: MCP SensitiveValueRedactor & RepositoryContextCollector
// ============================================================================

#[inline]
fn find_byte(haystack: &[u8], byte: u8) -> Option<usize> {
    if haystack.is_empty() {
        return None;
    }
    let ptr = unsafe { crate::memchr(haystack.as_ptr(), i32::from(byte), haystack.len()) };
    if ptr.is_null() {
        None
    } else {
        Some(unsafe { ptr.offset_from_unsigned(haystack.as_ptr()) })
    }
}

#[inline]
fn find_subslice(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    if needle.is_empty() {
        return Some(0);
    }
    if haystack.len() < needle.len() {
        return None;
    }
    let first = needle[0];
    let max_start = haystack.len() - needle.len();
    let mut pos = 0usize;
    while pos <= max_start {
        let rel = find_byte(&haystack[pos..=max_start], first)?;
        let idx = pos + rel;
        if &haystack[idx..idx + needle.len()] == needle {
            return Some(idx);
        }
        pos = idx + 1;
    }
    None
}

/// Evaluates `SensitiveValueRedactor::isSensitiveKey(key)` with exact PCRE `/i` semantics:
/// `/(?:password|passwd|token|secret|api[_-]?key|authorization|credential)/i`
#[inline]
pub fn mcp_is_sensitive_key_slice(key: &[u8]) -> bool {
    if key.len() < 5 {
        return false;
    }
    let mut i = 0usize;
    while i + 5 <= key.len() {
        let rem = &key[i..];
        match rem[0] | 0x20 {
            b'p' => {
                if rem.len() >= 6 && rem[..6].eq_ignore_ascii_case(b"passwd") {
                    return true;
                }
                if rem.len() >= 8 && rem[..8].eq_ignore_ascii_case(b"password") {
                    return true;
                }
            }
            b't' => {
                if rem[..5].eq_ignore_ascii_case(b"token") {
                    return true;
                }
            }
            b's' => {
                if rem.len() >= 6 && rem[..6].eq_ignore_ascii_case(b"secret") {
                    return true;
                }
            }
            b'a' => {
                if rem.len() >= 6 && rem[..3].eq_ignore_ascii_case(b"api") {
                    if rem[3..6].eq_ignore_ascii_case(b"key") {
                        return true;
                    }
                    if rem.len() >= 7
                        && matches!(rem[3], b'_' | b'-')
                        && rem[4..7].eq_ignore_ascii_case(b"key")
                    {
                        return true;
                    }
                }
                if rem.len() >= 13 && rem[..13].eq_ignore_ascii_case(b"authorization") {
                    return true;
                }
            }
            b'c' => {
                if rem.len() >= 10 && rem[..10].eq_ignore_ascii_case(b"credential") {
                    return true;
                }
            }
            _ => {}
        }
        i += 1;
    }
    false
}

const SWAR_01: u64 = 0x0101_0101_0101_0101;
const SWAR_20: u64 = 0x2020_2020_2020_2020;
const SWAR_80: u64 = 0x8080_8080_8080_8080;

#[inline(always)]
fn swar_eq_mask(word: u64, repeated_byte: u64) -> u64 {
    let x = word ^ repeated_byte;
    x.wrapping_sub(SWAR_01) & !x & SWAR_80
}

#[inline]
fn mcp_redact_pass1_pem(s: &[u8], out: &mut Vec<u8>) -> bool {
    // `/-----BEGIN [^-\r\n]*PRIVATE KEY-----.*?-----END [^-\r\n]*PRIVATE KEY-----/s`
    if s.len() < 52 {
        return false;
    }
    let mut matched_any = false;
    let mut last_emit = 0usize;
    let mut scan_pos = 0usize;

    while scan_pos + 52 <= s.len() {
        let Some(rel_b) = find_subslice(&s[scan_pos + 5..], b"BEGIN ") else {
            break;
        };
        let b_pos = scan_pos + 5 + rel_b;
        let begin_pos = b_pos - 5;
        if &s[begin_pos..b_pos] != b"-----" {
            scan_pos = b_pos - 4;
            continue;
        }
        let label_start = begin_pos + 11;
        let mut k = label_start;
        while k < s.len() && !matches!(s[k], b'-' | b'\r' | b'\n') {
            k += 1;
        }
        if k < label_start + 11
            || &s[k - 11..k] != b"PRIVATE KEY"
            || !s[k..].starts_with(b"-----")
        {
            scan_pos = label_start;
            continue;
        }

        let body_start = k + 5;
        let mut end_search = body_start;
        let mut matched_end: Option<usize> = None;
        while end_search + 25 <= s.len() {
            let Some(rel_end_e) = find_subslice(&s[end_search + 5..], b"END ") else {
                break;
            };
            let e_pos = end_search + 5 + rel_end_e;
            let end_pos = e_pos - 5;
            if &s[end_pos..e_pos] != b"-----" {
                end_search = e_pos - 4;
                continue;
            }
            let end_label_start = end_pos + 9;
            let mut m = end_label_start;
            while m < s.len() && !matches!(s[m], b'-' | b'\r' | b'\n') {
                m += 1;
            }
            if m >= end_label_start + 11
                && &s[m - 11..m] == b"PRIVATE KEY"
                && s[m..].starts_with(b"-----")
            {
                matched_end = Some(m + 5);
                break;
            }
            end_search = end_label_start;
        }

        let Some(block_end) = matched_end else {
            break;
        };

        if !matched_any {
            matched_any = true;
            out.clear();
            out.reserve(s.len());
        }
        out.extend_from_slice(&s[last_emit..begin_pos]);
        out.extend_from_slice(b"[REDACTED PRIVATE KEY]");
        last_emit = block_end;
        scan_pos = block_end;
    }

    if matched_any {
        out.extend_from_slice(&s[last_emit..]);
    }
    matched_any
}

#[inline]
fn is_bearer_token_char(b: u8) -> bool {
    b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'~' | b'+' | b'/' | b'-')
}

#[inline]
fn mcp_redact_pass2_bearer(s: &[u8], out: &mut Vec<u8>) -> bool {
    // `/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i`
    if s.len() < 8 {
        return false;
    }
    let mut matched_any = false;
    let mut last_emit = 0usize;
    let max_start = s.len() - 8;
    let mut i = 0usize;

    while i + 8 <= max_start + 1 {
        let w0 = u64::from_le_bytes(s[i..i + 8].try_into().unwrap());
        let mb = swar_eq_mask(w0 | SWAR_20, 0x6262_6262_6262_6262);
        if mb == 0 {
            i += 8;
            continue;
        }
        let w5 = u64::from_le_bytes(s[i + 5..i + 13].try_into().unwrap());
        let mr = swar_eq_mask(w5 | SWAR_20, 0x7272_7272_7272_7272);
        let mut hits = mb & mr;
        if hits == 0 {
            i += 8;
            continue;
        }
        let base = i;
        let mut jumped = false;
        while hits != 0 {
            let lane = (hits.trailing_zeros() >> 3) as usize;
            hits &= hits - 1;
            let idx = base + lane;
            if is_pcre_space(s[idx + 6])
                && (idx == 0 || !is_pcre_word(s[idx - 1]))
                && s[idx..idx + 6].eq_ignore_ascii_case(b"bearer")
            {
                let mut p = idx + 7;
                while p < s.len() && is_pcre_space(s[p]) {
                    p += 1;
                }
                let tok_start = p;
                while p < s.len() && is_bearer_token_char(s[p]) {
                    p += 1;
                }
                if p > tok_start {
                    while p < s.len() && s[p] == b'=' {
                        p += 1;
                    }
                    if !matched_any {
                        matched_any = true;
                        out.clear();
                        out.reserve(s.len());
                    }
                    out.extend_from_slice(&s[last_emit..idx]);
                    out.extend_from_slice(b"Bearer [REDACTED]");
                    last_emit = p;
                    i = p;
                    jumped = true;
                    break;
                }
            }
        }
        if !jumped {
            i = base + 8;
        }
    }

    while i <= max_start {
        if matches!(s[i], b'B' | b'b')
            && is_pcre_space(s[i + 6])
            && (i == 0 || !is_pcre_word(s[i - 1]))
            && s[i..i + 6].eq_ignore_ascii_case(b"bearer")
        {
            let mut p = i + 7;
            while p < s.len() && is_pcre_space(s[p]) {
                p += 1;
            }
            let tok_start = p;
            while p < s.len() && is_bearer_token_char(s[p]) {
                p += 1;
            }
            if p > tok_start {
                while p < s.len() && s[p] == b'=' {
                    p += 1;
                }
                if !matched_any {
                    matched_any = true;
                    out.clear();
                    out.reserve(s.len());
                }
                out.extend_from_slice(&s[last_emit..i]);
                out.extend_from_slice(b"Bearer [REDACTED]");
                last_emit = p;
                i = p;
                continue;
            }
            i += 6;
            continue;
        }
        i += 1;
    }

    if matched_any {
        out.extend_from_slice(&s[last_emit..]);
    }
    matched_any
}

#[inline]
fn is_scheme_char(b: u8) -> bool {
    b.is_ascii_alphanumeric() || matches!(b, b'+' | b'.' | b'-')
}

#[inline]
fn mcp_redact_pass3_url(s: &[u8], out: &mut Vec<u8>) -> bool {
    // `#\b([a-z][a-z0-9+.-]*://)[^\s/:@]+:[^\s/@]+@#i`
    if s.len() < 8 {
        return false;
    }
    let mut matched_any = false;
    let mut last_emit = 0usize;
    let mut scan_pos = 1usize;

    while scan_pos + 6 <= s.len() {
        let Some(rel_colon) = find_subslice(&s[scan_pos..], b"://") else {
            break;
        };
        let colon_pos = scan_pos + rel_colon;
        let scheme_end = colon_pos + 3;

        let mut sc = colon_pos;
        while sc > last_emit && is_scheme_char(s[sc - 1]) {
            sc -= 1;
        }
        let mut valid_scheme = false;
        let mut k = sc;
        while k < colon_pos {
            if s[k].is_ascii_alphabetic() && (k == 0 || !is_pcre_word(s[k - 1])) {
                valid_scheme = true;
                break;
            }
            k += 1;
        }

        if valid_scheme {
            let mut u_end = scheme_end;
            while u_end < s.len()
                && !is_pcre_space(s[u_end])
                && !matches!(s[u_end], b'/' | b':' | b'@')
            {
                u_end += 1;
            }
            if u_end > scheme_end && s.get(u_end) == Some(&b':') {
                let pw_start = u_end + 1;
                let mut pw_end = pw_start;
                while pw_end < s.len()
                    && !is_pcre_space(s[pw_end])
                    && !matches!(s[pw_end], b'/' | b'@')
                {
                    pw_end += 1;
                }
                if pw_end > pw_start && s.get(pw_end) == Some(&b'@') {
                    let match_end = pw_end + 1;
                    if !matched_any {
                        matched_any = true;
                        out.clear();
                        out.reserve(s.len());
                    }
                    out.extend_from_slice(&s[last_emit..scheme_end]);
                    out.extend_from_slice(b"[REDACTED]@");
                    last_emit = match_end;
                    scan_pos = match_end + 1;
                    continue;
                }
            }
        }
        scan_pos = scheme_end;
    }

    if matched_any {
        out.extend_from_slice(&s[last_emit..]);
    }
    matched_any
}

#[inline]
fn is_pass4_val_byte(b: u8) -> bool {
    !is_pcre_space(b) && !matches!(b, b',' | b'"' | b'\'' | b'}')
}

#[inline]
fn ends_with_pass4_keyword_at_boundary(s: &[u8], min_start: usize, kw_end: usize) -> bool {
    // `(password|passwd|token|secret|api[_-]?key|authorization)` with `\b` before keyword
    if kw_end < min_start + 5 {
        return false;
    }
    let last_lower = s[kw_end - 1] | 0x20;
    if !matches!(last_lower, b'd' | b'n' | b't' | b'y') {
        return false;
    }
    let slice = &s[..kw_end];
    let kw_len = match last_lower {
        b'd' => {
            if kw_end >= min_start + 8 && slice[kw_end - 8..].eq_ignore_ascii_case(b"password") {
                8
            } else if kw_end >= min_start + 6 && slice[kw_end - 6..].eq_ignore_ascii_case(b"passwd")
            {
                6
            } else {
                return false;
            }
        }
        b'n' => {
            if kw_end >= min_start + 5 && slice[kw_end - 5..].eq_ignore_ascii_case(b"token") {
                5
            } else if kw_end >= min_start + 13
                && slice[kw_end - 13..].eq_ignore_ascii_case(b"authorization")
            {
                13
            } else {
                return false;
            }
        }
        b't' => {
            if kw_end >= min_start + 6 && slice[kw_end - 6..].eq_ignore_ascii_case(b"secret") {
                6
            } else {
                return false;
            }
        }
        b'y' => {
            if kw_end >= min_start + 6 && slice[kw_end - 3..].eq_ignore_ascii_case(b"key") {
                if slice[kw_end - 6..kw_end - 3].eq_ignore_ascii_case(b"api") {
                    6
                } else if kw_end >= min_start + 7
                    && matches!(slice[kw_end - 4], b'_' | b'-')
                    && slice[kw_end - 7..kw_end - 4].eq_ignore_ascii_case(b"api")
                {
                    7
                } else {
                    return false;
                }
            } else {
                return false;
            }
        }
        _ => return false,
    };

    let kw_start = kw_end - kw_len;
    kw_start == 0 || !is_pcre_word(s[kw_start - 1])
}

#[inline]
fn try_redact_pass4_at(
    s: &[u8],
    op_pos: usize,
    last_emit: &mut usize,
    matched_any: &mut bool,
    out: &mut Vec<u8>,
) -> Option<usize> {
    let b = s[op_pos];
    if b != b'=' && b != b':' {
        return None;
    }
    let prev = s[op_pos - 1];
    if !is_pcre_space(prev)
        && !matches!(prev, b'"' | b'\'')
        && !matches!(prev | 0x20, b'd' | b'n' | b't' | b'y')
    {
        return None;
    }

    // Walk backwards from `op_pos` over `\s*["\']?\s*`
    let mut p = op_pos;
    while p > *last_emit && is_pcre_space(s[p - 1]) {
        p -= 1;
    }
    if p > *last_emit && matches!(s[p - 1], b'"' | b'\'') {
        p -= 1;
    }
    while p > *last_emit && is_pcre_space(s[p - 1]) {
        p -= 1;
    }

    if !ends_with_pass4_keyword_at_boundary(s, *last_emit, p) {
        return None;
    }

    if b == b'=' && s.get(op_pos + 1) == Some(&b'>') {
        let mut q = op_pos + 2;
        while q < s.len() && is_pcre_space(s[q]) {
            q += 1;
        }
        if q < s.len() && matches!(s[q], b'"' | b'\'') {
            q += 1;
        }
        if q < s.len() && is_pass4_val_byte(s[q]) {
            let mut val_end = q + 1;
            while val_end < s.len() && is_pass4_val_byte(s[val_end]) {
                val_end += 1;
            }
            if !*matched_any {
                *matched_any = true;
                out.clear();
                out.reserve(s.len());
            }
            out.extend_from_slice(&s[*last_emit..q]);
            out.extend_from_slice(b"[REDACTED]");
            *last_emit = val_end;
            return Some(val_end + 5);
        }
        // Backtrack `(?:=>|=|:)` from `=>` to `=`: `>` at `op_pos + 1` becomes `$3`
        let q = op_pos + 1;
        let mut val_end = op_pos + 2;
        while val_end < s.len() && is_pass4_val_byte(s[val_end]) {
            val_end += 1;
        }
        if !*matched_any {
            *matched_any = true;
            out.clear();
            out.reserve(s.len());
        }
        out.extend_from_slice(&s[*last_emit..q]);
        out.extend_from_slice(b"[REDACTED]");
        *last_emit = val_end;
        return Some(val_end + 5);
    }

    let mut q = op_pos + 1;
    while q < s.len() && is_pcre_space(s[q]) {
        q += 1;
    }
    if q < s.len() && matches!(s[q], b'"' | b'\'') {
        q += 1;
    }
    if q < s.len() && is_pass4_val_byte(s[q]) {
        let mut val_end = q + 1;
        while val_end < s.len() && is_pass4_val_byte(s[val_end]) {
            val_end += 1;
        }
        if !*matched_any {
            *matched_any = true;
            out.clear();
            out.reserve(s.len());
        }
        out.extend_from_slice(&s[*last_emit..q]);
        out.extend_from_slice(b"[REDACTED]");
        *last_emit = val_end;
        return Some(val_end + 5);
    }

    None
}

#[inline]
fn mcp_redact_pass4_kv(s: &[u8], out: &mut Vec<u8>) -> bool {
    // `/\b(password|passwd|token|secret|api[_-]?key|authorization)(\s*["\']?\s*(?:=>|=|:)\s*["\']?)([^\s,"\'}]+)/i`
    if s.len() < 7 {
        return false;
    }
    let mut matched_any = false;
    let mut last_emit = 0usize;
    let mut op_pos = 5usize;
    let limit = s.len() - 1;

    while op_pos + 8 <= limit {
        let w = u64::from_le_bytes(s[op_pos..op_pos + 8].try_into().unwrap());
        let mut hits = swar_eq_mask(w, 0x3D3D_3D3D_3D3D_3D3D)
            | swar_eq_mask(w, 0x3A3A_3A3A_3A3A_3A3A);
        if hits == 0 {
            op_pos += 8;
            continue;
        }
        let base = op_pos;
        let mut jumped = false;
        while hits != 0 {
            let lane = (hits.trailing_zeros() >> 3) as usize;
            hits &= hits - 1;
            let idx = base + lane;
            if let Some(next_pos) =
                try_redact_pass4_at(s, idx, &mut last_emit, &mut matched_any, out)
            {
                op_pos = next_pos;
                jumped = true;
                break;
            }
        }
        if !jumped {
            op_pos = base + 8;
        }
    }

    while op_pos < limit {
        if let Some(next_pos) =
            try_redact_pass4_at(s, op_pos, &mut last_emit, &mut matched_any, out)
        {
            op_pos = next_pos;
        } else {
            op_pos += 1;
        }
    }

    if matched_any {
        out.extend_from_slice(&s[last_emit..]);
    }
    matched_any
}

#[inline]
fn ends_with_pass5_env_suffix(after_first_char: &[u8]) -> bool {
    let Some(&last) = after_first_char.last() else {
        return false;
    };
    if !matches!(last, b'Y' | b'N' | b'T' | b'D') {
        return false;
    }
    after_first_char.ends_with(b"KEY")
        || after_first_char.ends_with(b"TOKEN")
        || after_first_char.ends_with(b"SECRET")
        || after_first_char.ends_with(b"PASSWORD")
        || after_first_char.ends_with(b"PASSWD")
}

#[inline]
fn mcp_redact_pass5_env(s: &[u8], out: &mut Vec<u8>) -> bool {
    // `/\b([A-Z][A-Z0-9_]*(?:KEY|TOKEN|SECRET|PASSWORD|PASSWD))=([^\s]+)/`
    if s.len() < 6 {
        return false;
    }
    let mut matched_any = false;
    let mut last_emit = 0usize;
    let mut eq_pos = 4usize;
    let limit = s.len() - 1;

    while eq_pos + 8 <= limit {
        let w = u64::from_le_bytes(s[eq_pos..eq_pos + 8].try_into().unwrap());
        let mut hits = swar_eq_mask(w, 0x3D3D_3D3D_3D3D_3D3D);
        if hits == 0 {
            eq_pos += 8;
            continue;
        }
        let base = eq_pos;
        let mut jumped = false;
        while hits != 0 {
            let lane = (hits.trailing_zeros() >> 3) as usize;
            hits &= hits - 1;
            let idx = base + lane;
            if s[idx] == b'='
                && matches!(s[idx - 1], b'Y' | b'N' | b'T' | b'D')
                && !is_pcre_space(s[idx + 1])
            {
                let mut k = idx;
                while k > last_emit
                    && (s[k - 1].is_ascii_uppercase()
                        || s[k - 1].is_ascii_digit()
                        || s[k - 1] == b'_')
                {
                    k -= 1;
                }
                if idx >= k + 4
                    && s[k].is_ascii_uppercase()
                    && (k == 0 || !is_pcre_word(s[k - 1]))
                    && ends_with_pass5_env_suffix(&s[k + 1..idx])
                {
                    let eq_after = idx + 1;
                    let mut val_end = eq_after + 1;
                    while val_end < s.len() && !is_pcre_space(s[val_end]) {
                        val_end += 1;
                    }
                    if !matched_any {
                        matched_any = true;
                        out.clear();
                        out.reserve(s.len());
                    }
                    out.extend_from_slice(&s[last_emit..eq_after]);
                    out.extend_from_slice(b"[REDACTED]");
                    last_emit = val_end;
                    eq_pos = val_end + 4;
                    jumped = true;
                    break;
                }
            }
        }
        if !jumped {
            eq_pos = base + 8;
        }
    }

    while eq_pos < limit {
        if s[eq_pos] == b'='
            && matches!(s[eq_pos - 1], b'Y' | b'N' | b'T' | b'D')
            && !is_pcre_space(s[eq_pos + 1])
        {
            let mut k = eq_pos;
            while k > last_emit
                && (s[k - 1].is_ascii_uppercase() || s[k - 1].is_ascii_digit() || s[k - 1] == b'_')
            {
                k -= 1;
            }
            if eq_pos >= k + 4
                && s[k].is_ascii_uppercase()
                && (k == 0 || !is_pcre_word(s[k - 1]))
                && ends_with_pass5_env_suffix(&s[k + 1..eq_pos])
            {
                let eq_after = eq_pos + 1;
                let mut val_end = eq_after + 1;
                while val_end < s.len() && !is_pcre_space(s[val_end]) {
                    val_end += 1;
                }
                if !matched_any {
                    matched_any = true;
                    out.clear();
                    out.reserve(s.len());
                }
                out.extend_from_slice(&s[last_emit..eq_after]);
                out.extend_from_slice(b"[REDACTED]");
                last_emit = val_end;
                eq_pos = val_end + 4;
                continue;
            }
        }
        eq_pos += 1;
    }

    if matched_any {
        out.extend_from_slice(&s[last_emit..]);
    }
    matched_any
}

/// Evaluates `SensitiveValueRedactor::redact(value)`.
/// Returns `None` if `value` is unchanged (fast path with zero heap allocations).
pub fn mcp_redact_bytes(input: &[u8]) -> Option<Vec<u8>> {
    if input.len() < 6 {
        return None;
    }

    let mut buf_a = Vec::new();
    let mut buf_b = Vec::new();
    let mut state = 0u8; // 0 = input, 1 = buf_a, 2 = buf_b

    if mcp_redact_pass1_pem(input, &mut buf_a) {
        state = 1;
    }

    {
        let (src, dst) = match state {
            0 => (input, &mut buf_a),
            1 => (buf_a.as_slice(), &mut buf_b),
            _ => (buf_b.as_slice(), &mut buf_a),
        };
        if mcp_redact_pass2_bearer(src, dst) {
            state = if state == 1 { 2 } else { 1 };
        }
    }

    {
        let (src, dst) = match state {
            0 => (input, &mut buf_a),
            1 => (buf_a.as_slice(), &mut buf_b),
            _ => (buf_b.as_slice(), &mut buf_a),
        };
        if mcp_redact_pass3_url(src, dst) {
            state = if state == 1 { 2 } else { 1 };
        }
    }

    {
        let (src, dst) = match state {
            0 => (input, &mut buf_a),
            1 => (buf_a.as_slice(), &mut buf_b),
            _ => (buf_b.as_slice(), &mut buf_a),
        };
        if mcp_redact_pass4_kv(src, dst) {
            state = if state == 1 { 2 } else { 1 };
        }
    }

    {
        let (src, dst) = match state {
            0 => (input, &mut buf_a),
            1 => (buf_a.as_slice(), &mut buf_b),
            _ => (buf_b.as_slice(), &mut buf_a),
        };
        if mcp_redact_pass5_env(src, dst) {
            state = if state == 1 { 2 } else { 1 };
        }
    }

    match state {
        0 => None,
        1 => Some(buf_a),
        _ => Some(buf_b),
    }
}

/// C-ABI export for `SensitiveValueRedactor::redact(value)`.
///
/// Returns:
/// - `0` when `input` requires no redaction (caller returns original string without copying/allocating)
/// - `> 0` bytes written to `out_buf` when redaction occurred
/// - `< -1` (`-(required_len as isize)`) when `out_cap` is insufficient
/// - `-1` on invalid arguments
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_mcp_redact_string(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() && input_len > 0 {
        return -1;
    }
    if input_len == 0 {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    let Some(redacted) = mcp_redact_bytes(bytes) else {
        return 0;
    };
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    out.extend(&redacted);
    out.finish()
}

/// C-ABI export for `SensitiveValueRedactor::isSensitiveKey(key)`.
///
/// Returns `1` if sensitive, `0` if not sensitive, or `-1` on invalid arguments.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_mcp_is_sensitive_key(
    input: *const u8,
    input_len: usize,
) -> i32 {
    if input.is_null() && input_len > 0 {
        return -1;
    }
    if input_len < 5 {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    i32::from(mcp_is_sensitive_key_slice(bytes))
}

#[inline]
fn is_pcre_r_single_byte(b: u8) -> bool {
    matches!(b, b'\n' | 0x0B | 0x0C | b'\r' | 0x85)
}

/// Splits `bytes` using PCRE `/\R/` byte-mode semantics (`\r\n` or `[\n\x0b\x0c\r\x85]`),
/// invoking `f(line)` for each line until `f` returns `false`.
#[inline]
fn for_each_pcre_r_line(bytes: &[u8], mut f: impl FnMut(&[u8]) -> bool) {
    if find_byte(bytes, b'\r').is_none()
        && find_byte(bytes, 0x0B).is_none()
        && find_byte(bytes, 0x0C).is_none()
        && find_byte(bytes, 0x85).is_none()
    {
        let mut start = 0usize;
        while let Some(rel) = find_byte(&bytes[start..], b'\n') {
            let end = start + rel;
            if !f(&bytes[start..end]) {
                return;
            }
            start = end + 1;
        }
        let _ = f(&bytes[start..]);
        return;
    }

    let mut start = 0usize;
    let mut i = 0usize;
    while i < bytes.len() {
        if is_pcre_r_single_byte(bytes[i]) {
            if !f(&bytes[start..i]) {
                return;
            }
            if bytes[i] == b'\r' && bytes.get(i + 1) == Some(&b'\n') {
                i += 2;
            } else {
                i += 1;
            }
            start = i;
        } else {
            i += 1;
        }
    }
    let _ = f(&bytes[start..]);
}

/// C-ABI export for `RepositoryContextCollector::search()` ripgrep line parsing + excerpt redaction.
///
/// Parses up to `max_results` lines matching `/^([^:]+):(\d+):(.*)$/` from `input[..input_len]`
/// split on `/\R/`, trims `$3` with PHP `trim()` semantics, redacts sensitive values, and writes
/// `path:line:redacted_excerpt` records separated by `\n` into `out_buf`.
///
/// Returns `0` when 0 lines matched, `> 0` bytes written, `< -1` when `out_cap` is too small,
/// or `-1` on invalid arguments.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_mcp_parse_and_redact_rg_output(
    input: *const u8,
    input_len: usize,
    max_results: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() && input_len > 0 {
        return -1;
    }
    if input_len == 0 || max_results == 0 {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    let mut count = 0usize;

    for_each_pcre_r_line(bytes, |line| {
        if line.len() < 4 {
            return true;
        }
        let Some(colon1) = find_byte(line, b':') else {
            return true;
        };
        if colon1 == 0 || colon1 + 2 >= line.len() {
            return true;
        }
        let after_colon1 = &line[colon1 + 1..];
        let Some(rel_colon2) = find_byte(after_colon1, b':') else {
            return true;
        };
        if rel_colon2 == 0 {
            return true;
        }
        let digits = &after_colon1[..rel_colon2];
        if !digits.iter().all(u8::is_ascii_digit) {
            return true;
        }
        let path = &line[..colon1];
        let excerpt = php_trim_ascii(&after_colon1[rel_colon2 + 1..]);

        if count > 0 {
            out.push(b'\n');
        }
        out.extend(path);
        out.push(b':');
        out.extend(digits);
        out.push(b':');
        if let Some(redacted) = mcp_redact_bytes(excerpt) {
            out.extend(&redacted);
        } else {
            out.extend(excerpt);
        }
        count += 1;
        count < max_results
    });

    out.finish()
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum GlobToken {
    Literal(u8),
    AnyExceptSlash,
    AnyExceptLf,
}

#[inline]
fn epsilon_close_glob(state: &mut [bool], tokens: &[GlobToken]) {
    for (k, &tok) in tokens.iter().enumerate() {
        if state[k] && matches!(tok, GlobToken::AnyExceptSlash | GlobToken::AnyExceptLf) {
            state[k + 1] = true;
        }
    }
}

#[inline]
fn run_glob_nfa(tokens: &[GlobToken], path: &[u8], curr: &mut [bool], next: &mut [bool]) -> bool {
    let n = tokens.len();
    curr.fill(false);
    curr[0] = true;
    epsilon_close_glob(curr, tokens);

    for (idx, &b) in path.iter().enumerate() {
        if idx + 1 == path.len() && b == b'\n' && curr[n] {
            return true;
        }
        next.fill(false);
        for k in 0..n {
            if curr[k] {
                match tokens[k] {
                    GlobToken::Literal(c) => {
                        if b == c {
                            next[k + 1] = true;
                        }
                    }
                    GlobToken::AnyExceptSlash => {
                        if b != b'/' {
                            next[k] = true;
                        }
                    }
                    GlobToken::AnyExceptLf => {
                        if b != b'\n' {
                            next[k] = true;
                        }
                    }
                }
            }
        }
        epsilon_close_glob(next, tokens);
        curr.copy_from_slice(next);
        if !curr.iter().any(|&v| v) {
            return false;
        }
    }

    curr[n]
}

/// Evaluates `RepositoryContextCollector::globMatches(glob, path)` with exact PCRE semantics.
pub fn mcp_glob_matches_slice(glob: &[u8], path: &[u8]) -> bool {
    if glob == b"**" {
        return true;
    }

    // Fast path when `path` contains no `\n`: strip literal prefix before first `*`
    // and literal suffix after last `*`, then evaluate only `(mid_glob, mid_path)`.
    let (eval_glob, eval_path) = if find_byte(path, b'\n').is_none() {
        let Some(first_star) = find_byte(glob, b'*') else {
            return glob == path;
        };
        if !path.starts_with(&glob[..first_star]) {
            return false;
        }
        let last_star = glob.iter().rposition(|&b| b == b'*').unwrap_or(first_star);
        let suffix = &glob[last_star + 1..];
        if path.len() < first_star + suffix.len() || !path.ends_with(suffix) {
            return false;
        }
        let mid_glob = &glob[first_star..=last_star];
        let mid_path = &path[first_star..path.len() - suffix.len()];
        if mid_glob == b"*" {
            return find_byte(mid_path, b'/').is_none();
        }
        if mid_glob == b"**" {
            return true;
        }
        if mid_glob == b"**/*" {
            return find_byte(mid_path, b'/').is_some();
        }
        (mid_glob, mid_path)
    } else {
        (glob, path)
    };

    if eval_glob.len() <= 62 {
        let mut tok_buf = [GlobToken::Literal(0); 62];
        let mut n = 0usize;
        let mut star_mask = 0u64;
        let mut slash_star_mask = 0u64;
        let mut lf_star_mask = 0u64;
        let mut i = 0usize;
        while i < eval_glob.len() {
            if eval_glob[i..].starts_with(b"**") {
                if n == 0 || tok_buf[n - 1] != GlobToken::AnyExceptLf {
                    tok_buf[n] = GlobToken::AnyExceptLf;
                    let bit = 1u64 << n;
                    star_mask |= bit;
                    lf_star_mask |= bit;
                    n += 1;
                }
                i += 2;
            } else if eval_glob[i] == b'*' {
                if n == 0 || tok_buf[n - 1] != GlobToken::AnyExceptSlash {
                    tok_buf[n] = GlobToken::AnyExceptSlash;
                    let bit = 1u64 << n;
                    star_mask |= bit;
                    slash_star_mask |= bit;
                    n += 1;
                }
                i += 1;
            } else {
                tok_buf[n] = GlobToken::Literal(eval_glob[i]);
                n += 1;
                i += 1;
            }
        }

        let goal_bit = 1u64 << n;
        let mut state = 1u64;
        state |= (state & star_mask) << 1;
        state |= (state & star_mask) << 1;

        for (idx, &b) in eval_path.iter().enumerate() {
            if idx + 1 == eval_path.len() && b == b'\n' && (state & goal_bit) != 0 {
                return true;
            }
            let mut next = 0u64;
            if b != b'/' {
                next |= state & slash_star_mask;
            }
            if b != b'\n' {
                next |= state & lf_star_mask;
            }
            let mut lit_states = state & !star_mask & (goal_bit - 1);
            while lit_states != 0 {
                let k = lit_states.trailing_zeros() as usize;
                if tok_buf[k] == GlobToken::Literal(b) {
                    next |= 1u64 << (k + 1);
                }
                lit_states &= lit_states - 1;
            }
            next |= (next & star_mask) << 1;
            next |= (next & star_mask) << 1;
            state = next;
            if state == 0 {
                return false;
            }
        }

        return (state & goal_bit) != 0;
    }

    if eval_glob.len() <= 256 {
        let mut tok_buf = [GlobToken::Literal(0); 256];
        let mut n = 0usize;
        let mut i = 0usize;
        while i < eval_glob.len() {
            if eval_glob[i..].starts_with(b"**") {
                if n == 0 || tok_buf[n - 1] != GlobToken::AnyExceptLf {
                    tok_buf[n] = GlobToken::AnyExceptLf;
                    n += 1;
                }
                i += 2;
            } else if eval_glob[i] == b'*' {
                if n == 0 || tok_buf[n - 1] != GlobToken::AnyExceptSlash {
                    tok_buf[n] = GlobToken::AnyExceptSlash;
                    n += 1;
                }
                i += 1;
            } else {
                tok_buf[n] = GlobToken::Literal(eval_glob[i]);
                n += 1;
                i += 1;
            }
        }
        let mut curr_buf = [false; 257];
        let mut next_buf = [false; 257];
        return run_glob_nfa(
            &tok_buf[..n],
            eval_path,
            &mut curr_buf[..=n],
            &mut next_buf[..=n],
        );
    }

    let mut tokens = Vec::with_capacity(eval_glob.len());
    let mut i = 0usize;
    while i < eval_glob.len() {
        if eval_glob[i..].starts_with(b"**") {
            if tokens.last() != Some(&GlobToken::AnyExceptLf) {
                tokens.push(GlobToken::AnyExceptLf);
            }
            i += 2;
        } else if eval_glob[i] == b'*' {
            if tokens.last() != Some(&GlobToken::AnyExceptSlash) {
                tokens.push(GlobToken::AnyExceptSlash);
            }
            i += 1;
        } else {
            tokens.push(GlobToken::Literal(eval_glob[i]));
            i += 1;
        }
    }

    let n = tokens.len();
    let mut curr = vec![false; n + 1];
    let mut next = vec![false; n + 1];
    run_glob_nfa(&tokens, eval_path, &mut curr, &mut next)
}

/// C-ABI export for `RepositoryContextCollector::globMatches(glob, path)`.
///
/// Returns `1` on match, `0` on non-match, or `-1` on invalid arguments.
///
/// # Safety
///
/// `glob` and `path` must be valid for reads of their respective lengths when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_mcp_glob_matches(
    glob: *const u8,
    glob_len: usize,
    path: *const u8,
    path_len: usize,
) -> i32 {
    if (glob.is_null() && glob_len > 0) || (path.is_null() && path_len > 0) {
        return -1;
    }
    let glob_bytes: &[u8] = if glob_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(glob, glob_len) }
    };
    let path_bytes: &[u8] = if path_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(path, path_len) }
    };
    i32::from(mcp_glob_matches_slice(glob_bytes, path_bytes))
}

/// C-ABI export for `RepositoryContextCollector::configurationKeys` regex extraction:
/// `/\b(?:config|env)\(\s*["\']([^"\']+)["\']/`
///
/// Writes all matched `$1` configuration/env keys separated by `\n` into `out_buf`.
/// Returns `0` when no keys matched, `> 0` bytes written, `-2` when declined (key contains `\n`),
/// `< -2` when `out_cap` is too small, or `-1` on invalid arguments.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_mcp_extract_config_keys(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() && input_len > 0 {
        return -1;
    }
    if input_len < 7 {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    let mut paren_pos = 3usize;
    let end_limit = bytes.len() - 3;
    let mut first = true;

    while paren_pos + 32 <= end_limit {
        let chunk: &[u8; 32] = bytes[paren_pos..paren_pos + 32].try_into().unwrap();
        let prev_chunk: &[u8; 32] = bytes[paren_pos - 1..paren_pos + 31].try_into().unwrap();
        let mut any = 0u8;
        for k in 0..32 {
            let is_paren = u8::from(chunk[k] == b'(');
            let is_gv = u8::from(prev_chunk[k] == b'g') | u8::from(prev_chunk[k] == b'v');
            any |= is_paren & is_gv;
        }
        if any == 0 {
            paren_pos += 32;
            continue;
        }
        let chunk_end = paren_pos + 32;
        while paren_pos < chunk_end && paren_pos < end_limit {
            if bytes[paren_pos] != b'(' {
                paren_pos += 1;
                continue;
            }
            let prev = bytes[paren_pos - 1];
            if prev != b'g' && prev != b'v' {
                paren_pos += 1;
                continue;
            }
            let kw_ok = if prev == b'g' {
                paren_pos >= 6
                    && &bytes[paren_pos - 6..paren_pos] == b"config"
                    && (paren_pos == 6 || !is_pcre_word(bytes[paren_pos - 7]))
            } else {
                &bytes[paren_pos - 3..paren_pos] == b"env"
                    && (paren_pos == 3 || !is_pcre_word(bytes[paren_pos - 4]))
            };

            if !kw_ok {
                paren_pos += 1;
                continue;
            }

            let mut p = paren_pos + 1;
            while p < bytes.len() && is_pcre_space(bytes[p]) {
                p += 1;
            }
            if p >= bytes.len() || !matches!(bytes[p], b'"' | b'\'') {
                paren_pos += 1;
                continue;
            }

            let key_start = p + 1;
            let mut q = key_start;
            let mut has_lf = false;
            while q < bytes.len() && !matches!(bytes[q], b'"' | b'\'') {
                if bytes[q] == b'\n' {
                    has_lf = true;
                }
                q += 1;
            }
            if q >= bytes.len() {
                let res = out.finish();
                return if res == -1 { -2 } else { res };
            }
            if q == key_start {
                paren_pos = q + 1;
                continue;
            }
            if has_lf {
                return -2;
            }

            let key_slice = &bytes[key_start..q];
            if !first {
                out.push(b'\n');
            }
            first = false;
            out.extend(key_slice);
            paren_pos = q + 1;
        }
    }

    while paren_pos < end_limit {
        if bytes[paren_pos] != b'(' {
            paren_pos += 1;
            continue;
        }
        let prev = bytes[paren_pos - 1];
        if prev != b'g' && prev != b'v' {
            paren_pos += 1;
            continue;
        }
        let kw_ok = if prev == b'g' {
            paren_pos >= 6
                && &bytes[paren_pos - 6..paren_pos] == b"config"
                && (paren_pos == 6 || !is_pcre_word(bytes[paren_pos - 7]))
        } else {
            &bytes[paren_pos - 3..paren_pos] == b"env"
                && (paren_pos == 3 || !is_pcre_word(bytes[paren_pos - 4]))
        };

        if !kw_ok {
            paren_pos += 1;
            continue;
        }

        let mut p = paren_pos + 1;
        while p < bytes.len() && is_pcre_space(bytes[p]) {
            p += 1;
        }
        if p >= bytes.len() || !matches!(bytes[p], b'"' | b'\'') {
            paren_pos += 1;
            continue;
        }

        let key_start = p + 1;
        let mut q = key_start;
        let mut has_lf = false;
        while q < bytes.len() && !matches!(bytes[q], b'"' | b'\'') {
            if bytes[q] == b'\n' {
                has_lf = true;
            }
            q += 1;
        }
        if q >= bytes.len() {
            break;
        }
        if q == key_start {
            paren_pos = q + 1;
            continue;
        }
        if has_lf {
            return -2;
        }

        let key_slice = &bytes[key_start..q];
        if !first {
            out.push(b'\n');
        }
        first = false;
        out.extend(key_slice);
        paren_pos = q + 1;
    }

    let res = out.finish();
    if res == -1 {
        -2
    } else {
        res
    }
}

#[inline]
fn ends_with_mcp_symbol_suffix(s: &[u8]) -> bool {
    s.ends_with(b"Exception")
        || s.ends_with(b"Service")
        || s.ends_with(b"Controller")
        || s.ends_with(b"Command")
        || s.ends_with(b"Runner")
        || s.ends_with(b"Probe")
}

#[inline]
fn normalize_mcp_search_term(raw: &[u8]) -> &[u8] {
    let mut trimmed = raw;
    while let Some(&last) = trimmed.last() {
        if last == b'/' || last == b'\\' {
            trimmed = &trimmed[..trimmed.len() - 1];
        } else {
            break;
        }
    }
    let base = match trimmed.iter().rposition(|&b| b == b'/' || b == b'\\') {
        Some(pos) => &trimmed[pos + 1..],
        None => trimmed,
    };
    if base.len() > 4 && base.ends_with(b".php") {
        &base[..base.len() - 4]
    } else {
        base
    }
}

/// C-ABI export for `RepositoryContextCollector::searchTerms` error/symbol extraction:
/// `/(?:App\\[A-Za-z0-9_\\]+|app\/[A-Za-z0-9_\/.\-]+\.php|[A-Z][A-Za-z0-9_]+(?:Exception|Service|Controller|Command|Runner|Probe))/`
///
/// Writes up to 5 deduplicated normalized terms separated by `\n` into `out_buf`.
/// Returns `0` when 0 terms matched (caller falls back to `[mb_substr(trim($query), 0, 120)]`),
/// `> 0` bytes written, `< -1` when `out_cap` is too small, or `-1` on invalid arguments.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_mcp_search_terms(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() && input_len > 0 {
        return -1;
    }
    if input_len < 5 {
        return 0;
    }
    let s = unsafe { slice::from_raw_parts(input, input_len) };
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    let mut seen: [&[u8]; 5] = [&[]; 5];
    let mut seen_len = 0usize;
    let mut i = 0usize;

    while i + 5 <= s.len() {
        let mut matched: Option<&[u8]> = None;

        if s[i..].starts_with(b"App\\")
            && s.get(i + 4)
                .is_some_and(|&b| is_pcre_word(b) || b == b'\\')
        {
            let mut end = i + 5;
            while end < s.len() && (is_pcre_word(s[end]) || s[end] == b'\\') {
                end += 1;
            }
            matched = Some(&s[i..end]);
            i = end;
        } else if s[i..].starts_with(b"app/") {
            let mut max_end = i + 4;
            while max_end < s.len()
                && (is_pcre_word(s[max_end]) || matches!(s[max_end], b'/' | b'.' | b'-'))
            {
                max_end += 1;
            }
            let mut found_end: Option<usize> = None;
            let mut end = max_end;
            while end >= i + 9 {
                if &s[end - 4..end] == b".php" {
                    found_end = Some(end);
                    break;
                }
                end -= 1;
            }
            if let Some(end) = found_end {
                matched = Some(&s[i..end]);
                i = end;
            } else {
                i += 1;
            }
        } else if s[i].is_ascii_uppercase() {
            let mut max_end = i + 1;
            while max_end < s.len() && is_pcre_word(s[max_end]) {
                max_end += 1;
            }
            let mut found_end: Option<usize> = None;
            let mut end = max_end;
            while end >= i + 7 {
                if ends_with_mcp_symbol_suffix(&s[i + 2..end]) {
                    found_end = Some(end);
                    break;
                }
                end -= 1;
            }
            if let Some(end) = found_end {
                matched = Some(&s[i..end]);
                i = end;
            } else if max_end > i + 3
                && ((&s[max_end - 3..max_end] == b"App" && s.get(max_end) == Some(&b'\\'))
                    || (&s[max_end - 3..max_end] == b"app" && s.get(max_end) == Some(&b'/')))
            {
                i = max_end - 3;
            } else {
                i = max_end;
            }
        } else {
            i += 1;
        }

        if let Some(raw) = matched {
            let norm = normalize_mcp_search_term(raw);
            if !norm.is_empty() && norm != b"0" && !seen[..seen_len].contains(&norm) {
                if seen_len > 0 {
                    out.push(b'\n');
                }
                out.extend(norm);
                seen[seen_len] = norm;
                seen_len += 1;
                if seen_len == 5 {
                    break;
                }
            }
        }
    }

    out.finish()
}

#[cfg(test)]
mod mcp_tests {
    use super::*;

    #[test]
    fn mcp_redact_and_sensitive_key_parity() {
        assert_eq!(mcp_redact_bytes(b"clean string without secrets"), None);
        assert_eq!(
            mcp_redact_bytes(
                b"mysql://alice:secret@db Bearer abc.def= API_TOKEN=token-value password: hunter2"
            ),
            Some(
                b"mysql://[REDACTED]@db Bearer [REDACTED] API_TOKEN=[REDACTED] password: [REDACTED]"
                    .to_vec()
            )
        );
        assert_eq!(
            mcp_redact_bytes(
                b"-----BEGIN RSA PRIVATE KEY-----\nabc\n-----END RSA PRIVATE KEY-----"
            ),
            Some(b"[REDACTED PRIVATE KEY]".to_vec())
        );
        assert!(mcp_is_sensitive_key_slice(b"api_token"));
        assert!(mcp_is_sensitive_key_slice(b"X-API-Key"));
        assert!(mcp_is_sensitive_key_slice(b"user_credentials"));
        assert!(!mcp_is_sensitive_key_slice(b"safe_paths"));
    }

    #[test]
    fn mcp_glob_config_rg_and_search_terms() {
        assert!(mcp_glob_matches_slice(b"**", b"a\nb"));
        assert!(!mcp_glob_matches_slice(b"a**b", b"a\nb"));
        assert!(mcp_glob_matches_slice(b"a*b", b"a\nb"));
        assert!(!mcp_glob_matches_slice(b"a*b", b"a/b"));
        assert!(mcp_glob_matches_slice(b"app/**/*.php", b"app/Services/Foo.php"));

        let mut out = [0u8; 512];
        let rg = b"app/Foo.php:12:  $dsn = 'mysql://u:p@db';  \nnot-a-match\napp/Bar.php:34:API_KEY=xyz";
        let len = unsafe {
            nntmux_mcp_parse_and_redact_rg_output(
                rg.as_ptr(),
                rg.len(),
                10,
                out.as_mut_ptr(),
                out.len(),
            )
        };
        assert!(len > 0);
        assert_eq!(
            &out[..len as usize],
            b"app/Foo.php:12:$dsn = 'mysql://[REDACTED]@db';\napp/Bar.php:34:API_KEY=[REDACTED]"
        );

        let php_src = b"$a = config('app.name'); $b = env(\"DB_HOST\", 'localhost');";
        let klen = unsafe {
            nntmux_mcp_extract_config_keys(
                php_src.as_ptr(),
                php_src.len(),
                out.as_mut_ptr(),
                out.len(),
            )
        };
        assert!(klen > 0);
        assert_eq!(&out[..klen as usize], b"app.name\nDB_HOST");

        let err = b"RuntimeException in App\\Services\\Mcp\\RepositoryContextCollector:42 at app/Services/Mcp/ProcessRunner.php:18";
        let tlen = unsafe {
            nntmux_mcp_search_terms(err.as_ptr(), err.len(), out.as_mut_ptr(), out.len())
        };
        assert!(tlen > 0);
        assert_eq!(
            &out[..tlen as usize],
            b"RuntimeException\nRepositoryContextCollector\nProcessRunner"
        );

        let app_edge = b"App failed to boot in MyApp and FooApp\\Services\\Bar with Fooapp/Bar.php";
        let edge_len = unsafe {
            nntmux_mcp_search_terms(
                app_edge.as_ptr(),
                app_edge.len(),
                out.as_mut_ptr(),
                out.len(),
            )
        };
        assert!(edge_len > 0);
        assert_eq!(&out[..edge_len as usize], b"Bar");
    }
}


// Wave 8 town patterns deliberately retain PHP branch order, captures and modifiers.
mod release_town {
    use std::sync::LazyLock;

    const E0: &str = r##"([\-_](proof|sample|thumbs?))*(\.part\d*(\.rar)?|\.rar|\.7z|\.par2)?(\d{1,3}\.rev"|\.vol\d+\+\d+.+?"|\.[A-Za-z0-9]{2,4}"|")"##;
    const END: &str = r"[\-_\s]{0,3}yEnc$";
    const SIZE: &str = r"[\-_\s]{0,3}\d+([.,]\d+)? [kKmMgG][bB][\-_\s]{0,3}";
    const PATTERNS: &[(&str, usize, bool, bool)] = &[
        (
            r##"^<TOWN>.+?town\.ag.+?(www\..+?|News)\.[iI]nfo.+? \[\d+\/\d+\]( -)? "(.+?)(-sample)?{e0} - \d+[.,]\d+ [kKmMgG][bB]M? yEnc$"##,
            3,
            false,
            false,
        ),
        (
            r##"^\[\s*TOWN\s*\][\-_\s]{0,3}\[\s*www\.town\.ag\s*\][\-_\s]{0,3}\[\s*partner of www\.ssl-news\.info\s*\][\-_\s]{0,3}\[\s* .*\s*\][\-_\s]{0,3}\[\d+\/\d+\][\-_\s]{0,4}"([\w\säöüÄÖÜß+¤¶!.,&_()\[\]'\`{}#-]{8,}?\b.?){e2}"##,
            1,
            true,
            true,
        ),
        (
            r##"^<TOWN>.+?town\.ag.+?(www\..+?|News)\.[iI]nfo.+? \[\d+\/\d+\]( -)? "(.+?)(-sample)?{e1}"##,
            3,
            true,
            true,
        ),
        (
            r##"^\(\d+\/\d+\) -<TOWN><www\.town\.ag >\s+<partner.+> - ("|#34;)([\w. ()-]{8,}?\b)(\.par2|-\.part\d+\.rar|\.nfo)("|#34;) - \d+[.,]\d+ [kKmMgG][bB]( -)? yEnc$"##,
            2,
            false,
            false,
        ),
        (
            r##"^\[ TOWN \][ _-]{0,3}\[ www\.town\.ag \][ _-]{0,3}\[ partner of www\.ssl-news\.info \][ _-]{0,3}\[ .* \] \[\d+\/\d+\][ _-]{0,3}("|#34;)(.+)((\.part\d+\.rar)|(\.vol\d+\+\d+\.par2))("|#34;)[ _-]{0,3}\d+[.,]\d+ [kKmMgG][bB][ _-]{0,3}yEnc$"##,
            2,
            true,
            false,
        ),
        (
            r##"^\[ TOWN \][ _-]{0,3}\[ www\.town\.ag \][ _-]{0,3}\[ partner of www\.ssl-news\.info \][ _-]{0,3}\[ .* \] \[\d+\/\d+\][ _-]{0,3}("|#34;)(.+)\.(par2|rar|nfo|nzb)("|#34;)[ _-]{0,3}\d+[.,]\d+ [kKmMgG][bB][ _-]{0,3}yEnc$"##,
            2,
            true,
            false,
        ),
        (
            r##"^\[ TOWN \][ _-]{0,3}\[ www\.town\.ag \][ _-]{0,3}\[ partner of www\.ssl-news\.info \][ _-]{0,3}(\[ TV \] )?\[\d+\/\d+\][ _-]{0,3}("|#34;)(.+)((\.part\d+\.rar)|(\.vol\d+\+\d+\.par2)|\.nfo|\.vol\d+\+\.par2)("|#34;)[ _-]{0,3}\d+[.,]\d+ [kKmMgG][bB][ _-]{0,3}yEnc$"##,
            3,
            true,
            false,
        ),
        (
            r##"^\[ TOWN \][ _-]{0,3}\[ www\.town\.ag \][ _-]{0,3}\[ partner of www\.ssl-news\.info \][ _-]{0,3}(\[ TV \] )?\[\d+\/\d+\][ _-]{0,3}("|#34;)(.+)\.par2("|#34;)[ _-]{0,3}\d+[.,]\d+ [kKmMgG][bB][ _-]{0,3}yEnc$"##,
            3,
            true,
            false,
        ),
        (
            r##"^\d+\.-\.(.+) <TOWN><www\.town\.ag >\s+<partner .+>\s+<.+>\s+\[\d+\/\d+\] - ("|#34;).+("|#34;).+yEnc$"##,
            1,
            false,
            false,
        ),
        (
            r##"^\d+\.-\.(.+) <TOWN><www\.town\.ag >\s+<partner .+>\s+<.+>\s+\[\d+\/\d+\] - ("|#34;).+"##,
            1,
            false,
            false,
        ),
        (
            r##"^(\w.+) <TOWN><www\.town\.ag >\s+<partner.+>\s+\[\d+\/\d+\] - ("|#34;).+("|#34;).+yEnc$"##,
            1,
            false,
            false,
        ),
        (
            r##"^(\w.+) <TOWN><www\.town\.ag >\s+<partner.+>\s+\[\d+\/\d+\] - ("|#34;).+"##,
            1,
            false,
            false,
        ),
        (
            r##"^<TOWN><www\.town\.ag >\s+<partner .+>\s+(.+)\s+\[\d+\/\d+\] - ("|#34;).+("|#34;).+yEnc$"##,
            1,
            false,
            false,
        ),
        (
            r##"^<TOWN><www\.town\.ag >\s+<partner .+>\s+(.+)\s+\[\d+\/\d+\] - ("|#34;).+"##,
            1,
            false,
            false,
        ),
        (
            r##"^[ <\[]{0,2}TOWN[ >\]]{0,2}[ _-]{0,3}[ <\[]{0,2}www\.town\.ag[ >\]]{0,2}[ _-]{0,3}[ <\[]{0,2}partner of www.ssl-news\.info[ >\]]{0,2}[ _-]{0,3}\[\d+\/\d+\][ _-]{0,3}("|#34;)(.+)\.(par|vol|rar|nfo).*?("|#34;).+?yEnc$"##,
            2,
            true,
            false,
        ),
        (
            r##"^<TOWN> www\.town\.ag > sponsored by www\.ssl-news\.info > \(\d+\/\d+\) "([\w\säöüÄÖÜß+¤¶!.,&_()\[\]'\`{}#-]{8,}?\b.?){e0} - \d+[,.]\d+ [mMkKgG][bB] - yEnc$"##,
            1,
            false,
            false,
        ),
        (
            r##"^\(\d+\/\d+\).+?www\.town\.ag.+?sponsored by (www\.)?ssl-news\.info<+?.+? "([\w\säöüÄÖÜß+¤¶!.,&_()\[\]'\`{}#-]{8,}?\b.?){e1}"##,
            2,
            true,
            true,
        ),
        (
            r##"^\[ TOWN \][ _-]{0,3}\[ www\.town\.ag \][ _-]{0,3}\[ (.+?) \][ _-]{0,3}\[ partner of www\.ssl-news\.info \][ _-]{0,3}\[\d+\/(\d+\])[ _-]{0,3}"(.+)(\.part\d*|\.rar)?(\.vol.+ \(\d+\/\d+\) "|\.[A-Za-z0-9]{2,4}")[ _-]{0,3}yEnc$"##,
            1,
            true,
            false,
        ),
        (
            r##"^\(TOWN\)\(www\.town\.ag \)[ _-]{0,3}\(partner of www\.ssl-news\.info \)[ _-]{0,3} (.+?) \[\d+\/(\d+\][ _-]{0,3}("|#34;).+?)\.(par2|rar|nfo|nzb)("|#34;)[ _-]{0,3}\d+[.,]\d+ [kKmMgG][bB][ _-]{0,3}yEnc$"##,
            1,
            false,
            false,
        ),
        (
            r##"^<TOWN><www\.town\.ag > <partner of www\.ssl-news\.info > (.+) \[\d+\/\d+\][ _-]{0,3}("|#34;).+?("|#34;).+?yEnc$"##,
            1,
            true,
            false,
        ),
        (
            r##"^\[ TOWN \][ _-]{0,3}\[ www\.town\.ag \][ _-]{0,3}\[ .* \][ _-]{0,3}\[\d+\/\d+\][ _-]{0,3}"([\w\säöüÄÖÜß+¤¶!.,&_()\[\]'\`{}#-]{8,}?\b.?){e2}"##,
            1,
            true,
            true,
        ),
    ];

    // A small, bounded backtracking VM for this fixed pattern set. No external regex
    // dependency or locale is introduced. Alternatives, greedy/lazy repeats, saves
    // and assertions execute in PCRE order rather than choosing a longest match.
    #[derive(Clone, Default)]
    struct Set {
        ranges: Vec<(u32, u32)>,
        word: bool,
        space: bool,
        digit: bool,
        any: bool,
    }

    impl Set {
        fn literal(c: u32) -> Self {
            Self {
                ranges: vec![(c, c)],
                ..Self::default()
            }
        }

        fn accepts(&self, c: u32, insensitive: bool, utf: bool) -> bool {
            let word = |c: u32| {
                if utf {
                    c == 95 || char::from_u32(c).is_some_and(char::is_alphanumeric)
                } else {
                    c < 128 && super::is_pcre_word(c as u8)
                }
            };
            if self.any && c != 10
                || self.word && word(c)
                || self.space
                    && if utf {
                        char::from_u32(c).is_some_and(char::is_whitespace)
                    } else {
                        c < 128 && super::is_pcre_space(c as u8)
                    }
                || self.digit && (48..=57).contains(&c)
            {
                return true;
            }
            let in_ranges = |c| self.ranges.iter().any(|&(a, b)| a <= c && c <= b);
            if in_ranges(c) {
                return true;
            }
            if insensitive {
                if utf {
                    // The admitted UTF grammar is Latin-1. All case partners outside
                    // it (e.g. Kelvin sign/long s) decline before executing this VM.
                    if let Some(ch) = char::from_u32(c) {
                        let mut lower = ch.to_lowercase();
                        let lower_first = lower.next().unwrap();
                        let mut upper = ch.to_uppercase();
                        let upper_first = upper.next().unwrap();
                        return lower.next().is_none() && in_ranges(lower_first as u32)
                            || upper.next().is_none() && in_ranges(upper_first as u32);
                    }
                } else if c < 128 {
                    return in_ranges((c as u8).to_ascii_lowercase() as u32)
                        || in_ranges((c as u8).to_ascii_uppercase() as u32);
                }
            }
            false
        }
    }

    enum Node {
        Char(Set),
        Start,
        End,
        Boundary,
        Sequence(Vec<Node>),
        Either(Vec<Node>),
        Capture(usize, Box<Node>),
        Repeat(Box<Node>, usize, Option<usize>, bool),
    }

    struct Parser<'a> {
        bytes: &'a [u8],
        pos: usize,
        captures: usize,
        utf: bool,
    }

    impl Parser<'_> {
        fn next(&mut self) -> u32 {
            let c = if self.utf {
                std::str::from_utf8(&self.bytes[self.pos..])
                    .unwrap()
                    .chars()
                    .next()
                    .unwrap()
            } else {
                self.bytes[self.pos] as char
            };
            self.pos += if self.utf { c.len_utf8() } else { 1 };
            c as u32
        }

        fn escaped(&mut self) -> Set {
            match self.next() {
                119 => Set {
                    word: true,
                    ..Set::default()
                },
                115 => Set {
                    space: true,
                    ..Set::default()
                },
                100 => Set {
                    digit: true,
                    ..Set::default()
                },
                c => Set::literal(c),
            }
        }

        fn number(&mut self) -> usize {
            let mut n = 0;
            while let Some(b'0'..=b'9') = self.bytes.get(self.pos) {
                n = n * 10 + (self.bytes[self.pos] - b'0') as usize;
                self.pos += 1;
            }
            n
        }

        fn expression(&mut self) -> Node {
            let mut branches = vec![self.sequence()];
            while self.bytes.get(self.pos) == Some(&b'|') {
                self.pos += 1;
                branches.push(self.sequence());
            }
            if branches.len() == 1 {
                branches.pop().unwrap()
            } else {
                Node::Either(branches)
            }
        }

        fn sequence(&mut self) -> Node {
            let mut nodes = Vec::new();
            while self.pos < self.bytes.len() && !matches!(self.bytes[self.pos], b')' | b'|') {
                let mut node = match self.next() {
                    94 => Node::Start,
                    36 => Node::End,
                    46 => Node::Char(Set {
                        any: true,
                        ..Set::default()
                    }),
                    40 => {
                        self.captures += 1;
                        let capture = self.captures;
                        let child = self.expression();
                        assert_eq!(self.next(), 41);
                        Node::Capture(capture, Box::new(child))
                    }
                    92 => {
                        if self.bytes.get(self.pos) == Some(&b'b') {
                            self.pos += 1;
                            Node::Boundary
                        } else {
                            Node::Char(self.escaped())
                        }
                    }
                    91 => {
                        let mut set = Set::default();
                        while self.bytes[self.pos] != b']' {
                            let first = if self.bytes[self.pos] == b'\\' {
                                self.pos += 1;
                                self.escaped()
                            } else {
                                Set::literal(self.next())
                            };
                            if self.bytes.get(self.pos) == Some(&b'-')
                                && self.bytes.get(self.pos + 1) != Some(&b']')
                            {
                                self.pos += 1;
                                let end = self.next();
                                assert_eq!(first.ranges.len(), 1);
                                set.ranges.push((first.ranges[0].0, end));
                            } else {
                                set.ranges.extend(first.ranges);
                                set.word |= first.word;
                                set.space |= first.space;
                                set.digit |= first.digit;
                            }
                        }
                        self.pos += 1;
                        Node::Char(set)
                    }
                    c => Node::Char(Set::literal(c)),
                };
                let repeat = match self.bytes.get(self.pos) {
                    Some(b'*') => Some((0, None)),
                    Some(b'+') => Some((1, None)),
                    Some(b'?') => Some((0, Some(1))),
                    Some(b'{') => {
                        self.pos += 1;
                        let min = self.number();
                        let max = if self.bytes.get(self.pos) == Some(&b',') {
                            self.pos += 1;
                            if self.bytes.get(self.pos) == Some(&b'}') {
                                None
                            } else {
                                Some(self.number())
                            }
                        } else {
                            Some(min)
                        };
                        assert_eq!(self.bytes.get(self.pos), Some(&b'}'));
                        Some((min, max))
                    }
                    _ => None,
                };
                if let Some((min, max)) = repeat {
                    self.pos += 1;
                    let lazy = self.bytes.get(self.pos) == Some(&b'?');
                    if lazy {
                        self.pos += 1;
                    }
                    node = Node::Repeat(Box::new(node), min, max, lazy);
                }
                nodes.push(node);
            }
            Node::Sequence(nodes)
        }
    }

    enum Op {
        Char(Set),
        Start,
        End,
        Boundary,
        Save(usize),
        Split(usize, usize),
        Jump(usize),
        Match,
    }

    fn compile(node: &Node, ops: &mut Vec<Op>) {
        match node {
            Node::Char(set) => ops.push(Op::Char(set.clone())),
            Node::Start => ops.push(Op::Start),
            Node::End => ops.push(Op::End),
            Node::Boundary => ops.push(Op::Boundary),
            Node::Sequence(nodes) => nodes.iter().for_each(|n| compile(n, ops)),
            Node::Capture(index, child) => {
                ops.push(Op::Save(index * 2));
                compile(child, ops);
                ops.push(Op::Save(index * 2 + 1));
            }
            Node::Either(nodes) => {
                let mut exits = Vec::new();
                for (i, child) in nodes.iter().enumerate() {
                    if i + 1 == nodes.len() {
                        compile(child, ops);
                        break;
                    }
                    let split = ops.len();
                    ops.push(Op::Split(0, 0));
                    compile(child, ops);
                    exits.push(ops.len());
                    ops.push(Op::Jump(0));
                    ops[split] = Op::Split(split + 1, ops.len());
                }
                let end = ops.len();
                for exit in exits {
                    ops[exit] = Op::Jump(end);
                }
            }
            Node::Repeat(child, min, max, lazy) => {
                for _ in 0..*min {
                    compile(child, ops);
                }
                let repeats = max.map_or(1, |m| m - min);
                for _ in 0..repeats {
                    let split = ops.len();
                    ops.push(Op::Split(0, 0));
                    compile(child, ops);
                    if max.is_none() {
                        ops.push(Op::Jump(split));
                    }
                    let end = ops.len();
                    ops[split] = if *lazy {
                        Op::Split(end, split + 1)
                    } else {
                        Op::Split(split + 1, end)
                    };
                }
            }
        }
    }

    struct Pattern {
        ops: Vec<Op>,
        capture: usize,
        insensitive: bool,
        utf: bool,
    }

    static PROGRAMS: LazyLock<Vec<Pattern>> = LazyLock::new(|| {
        PATTERNS
            .iter()
            .map(|&(source, capture, insensitive, utf)| {
                let source = source
                    .replace("{e2}", &format!("{E0}{SIZE}{END}"))
                    .replace("{e1}", &format!("{E0}{END}"))
                    .replace("{e0}", E0);
                let mut parser = Parser {
                    bytes: source.as_bytes(),
                    pos: 0,
                    captures: 0,
                    utf,
                };
                let tree = parser.expression();
                assert_eq!(parser.pos, source.len());
                assert!(parser.captures < 16);
                let mut ops = Vec::new();
                compile(&tree, &mut ops);
                ops.push(Op::Match);
                Pattern {
                    ops,
                    capture,
                    insensitive,
                    utf,
                }
            })
            .collect()
    });

    impl Pattern {
        fn run(&self, subject: &[u8], budget: &mut usize) -> Result<Option<(usize, usize)>, ()> {
            let mut stack = Vec::new();
            let (mut pc, mut pos, mut captures) = (0, 0, [0usize; 32]);
            loop {
                if *budget == 0 || stack.len() > 8192 {
                    return Err(());
                }
                *budget -= 1;
                let valid = match &self.ops[pc] {
                    Op::Char(set) => {
                        if pos == subject.len() {
                            false
                        } else {
                            let (c, len) = if self.utf {
                                let c = std::str::from_utf8(&subject[pos..])
                                    .unwrap()
                                    .chars()
                                    .next()
                                    .unwrap();
                                (c as u32, c.len_utf8())
                            } else {
                                (subject[pos] as u32, 1)
                            };
                            if set.accepts(c, self.insensitive, self.utf) {
                                pos += len;
                                true
                            } else {
                                false
                            }
                        }
                    }
                    Op::Start => pos == 0,
                    Op::End => {
                        pos == subject.len() || pos + 1 == subject.len() && subject[pos] == b'\n'
                    }
                    Op::Boundary => {
                        let word = |c: char| c == '_' || c.is_alphanumeric();
                        let (left, right) = if self.utf {
                            (
                                std::str::from_utf8(&subject[..pos])
                                    .unwrap()
                                    .chars()
                                    .next_back()
                                    .is_some_and(word),
                                std::str::from_utf8(&subject[pos..])
                                    .unwrap()
                                    .chars()
                                    .next()
                                    .is_some_and(word),
                            )
                        } else {
                            (
                                pos > 0 && super::is_pcre_word(subject[pos - 1]),
                                pos < subject.len() && super::is_pcre_word(subject[pos]),
                            )
                        };
                        left != right
                    }
                    Op::Save(slot) => {
                        captures[*slot] = pos;
                        true
                    }
                    Op::Split(first, second) => {
                        stack.push((*second, pos, captures));
                        pc = *first;
                        continue;
                    }
                    Op::Jump(target) => {
                        pc = *target;
                        continue;
                    }
                    Op::Match => {
                        return Ok(Some((
                            captures[self.capture * 2],
                            captures[self.capture * 2 + 1],
                        )));
                    }
                };
                if valid {
                    pc += 1;
                } else if let Some(state) = stack.pop() {
                    (pc, pos, captures) = state;
                } else {
                    return Ok(None);
                }
            }
        }
    }

    pub(super) fn capture(subject: &[u8]) -> Result<Option<&[u8]>, ()> {
        if subject.len() > 16_384 {
            return Err(());
        }
        let utf = std::str::from_utf8(subject);
        let mut budget = 200_000;
        for pattern in PROGRAMS.iter() {
            if pattern.utf {
                // PCRE /u rejects the whole subject, even when the invalid byte is
                // outside the captured name. Other branches still use raw bytes.
                let Ok(text) = utf else {
                    continue;
                };
                // Keep unsupported Unicode properties/case folds on PCRE instead
                // of approximating them with Rust's different Unicode version.
                if text.chars().any(|c| c as u32 > 255) {
                    return Err(());
                }
            }
            if let Some((start, end)) = pattern.run(subject, &mut budget)? {
                return Ok(Some(&subject[start..end]));
            }
        }
        Ok(None)
    }
}

/// Ordered town cleaner: >=0 is the matched string's byte length; -3 requests
/// the PHP fallback ARRAY (`releaseCleanerHelper`, properlynamed=false); -2 is
/// unsupported/over budget; -1 is invalid arguments; <=-4 encodes -(needed+4).
/// Fallback cleaning stays in PHP so overridden releaseCleanerHelper is honored.
///
/// # Safety
/// Non-null subject/out_buf must be readable/writable for subject_len/out_cap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_generic_town(
    subject: *const u8,
    subject_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if subject_len > isize::MAX as usize
        || subject.is_null() && subject_len != 0
        || out_buf.is_null() && out_cap != 0
        || out_cap > isize::MAX as usize
    {
        return -1;
    }
    let subject = if subject_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(subject, subject_len) }
    };
    match release_town::capture(subject) {
        Ok(Some(name)) => {
            let length = name.len();
            if length > out_cap {
                return -(length as isize) - 4;
            }
            if length != 0 {
                // memmove semantics also permit a C caller's in-place output.
                unsafe {
                    std::ptr::copy(name.as_ptr(), out_buf, length);
                }
            }
            length as isize
        }
        Ok(None) => -3,
        Err(()) => -2,
    }
}

#[cfg(test)]
mod release_town_tests {
    use super::{nntmux_release_generic_town, release_town};

    #[test]
    fn town_order_laziness_greediness_and_byte_class() {
        let cases: &[(&[u8], &[u8])] = &[
            (b"<TOWN><www.town.ag > <partner of www.ssl-news.info > [1/3] - \"Release.Name-GRP-sample.part01.rar\" - 7,87 GB yEnc", b"Release.Name-GRP"),
            (b"<town><www.town.ag > <partner of www.ssl-news.info > [1/3] - \"Release.Name-GRP.part01.rar\" - 7,87 GB yEnc", b"Release.Name-GRP.part01"),
            (b"<TOWN> www.town.ag > sponsored by www.ssl-news.info > (1/3) \"Byte\xb6\xc3Name-GRP.par2\" - 43,89 MB - yEnc", b"Byte\xb6\xc3Name-GRP"),
            (b"<TOWN><www.town.ag > <partner of www.ssl-news.info > Title-GRP  [1/3] - #34;file.par2#34; - 1,23 MB - yEnc", b"Title-GRP "),
        ];
        for &(subject, expected) in cases {
            assert_eq!(release_town::capture(subject), Ok(Some(expected)));
        }
    }

    #[test]
    fn town_abi_statuses_exact_capacity_and_declines() {
        let mut buf = [b'Z'; 32];
        let subject = b"<TOWN><www.town.ag > <partner of www.ssl-news.info > [1/3] - \"Release-GRP.rar\" yEnc";
        unsafe {
            assert_eq!(
                nntmux_release_generic_town(std::ptr::null(), 1, buf.as_mut_ptr(), buf.len()),
                -1
            );
            assert_eq!(
                nntmux_release_generic_town(std::ptr::null(), 0, buf.as_mut_ptr(), buf.len()),
                -3
            );
            assert_eq!(
                nntmux_release_generic_town(
                    subject.as_ptr(),
                    subject.len(),
                    std::ptr::null_mut(),
                    1
                ),
                -1
            );
            assert_eq!(
                nntmux_release_generic_town(
                    subject.as_ptr(),
                    subject.len(),
                    std::ptr::null_mut(),
                    0
                ),
                -15
            );
            assert_eq!(
                nntmux_release_generic_town(subject.as_ptr(), subject.len(), buf.as_mut_ptr(), 1),
                -15
            );
            assert_eq!(buf, [b'Z'; 32]);
            assert_eq!(
                nntmux_release_generic_town(subject.as_ptr(), subject.len(), buf.as_mut_ptr(), 11),
                11
            );
            assert_eq!(&buf[..12], b"Release-GRPZ");
        }
        assert_eq!(release_town::capture(&vec![b'x'; 16_385]), Err(()));
        assert_eq!(release_town::capture(b"unmatched subject"), Ok(None));
        assert_eq!(release_town::capture("Unicode\u{212a}".as_bytes()), Err(()));
    }
}

// Season detection uses PHP's byte patterns and ASCII strtolower, including for
// Unicode/raw filename bytes. String normalizers retain PHP/ICU Unicode fallback.
unsafe fn wave9_input<'a>(input: *const u8, len: usize) -> Option<&'a [u8]> {
    if len > isize::MAX as usize || input.is_null() && len != 0 {
        None
    } else if len == 0 {
        Some(&[])
    } else {
        Some(unsafe { slice::from_raw_parts(input, len) })
    }
}

#[inline]
fn wave9_episode_separator(byte: u8) -> bool {
    matches!(byte, b'.' | b'_' | b' ' | b'-')
}

/// Only the fields needed by `parseEpisode` identity consumers. The S-pattern
/// takes precedence over the x-pattern, even when the x-marker occurs first.
fn wave9_episode_identity(name: &[u8]) -> Option<(Vec<u8>, u32, u32)> {
    let base = crate::filename::season_pack_base_name(name);
    if base.is_empty() || crate::filename::matches_sample_regex(base) {
        return None;
    }
    let mut matched = None;
    for x_pattern in [false, true] {
        for marker in 2..base.len() {
            if !wave9_episode_separator(base[marker - 1]) {
                continue;
            }
            let mut season_start = marker;
            if !x_pattern {
                if !matches!(base[marker], b's' | b'S') {
                    continue;
                }
                season_start += 1;
            }
            let mut season_end = season_start;
            while season_end < base.len() && base[season_end].is_ascii_digit() {
                season_end += 1;
            }
            if !(1..=if x_pattern { 2 } else { 4 }).contains(&(season_end - season_start)) {
                continue;
            }
            let mut delimiter = season_end;
            if !x_pattern
                && base
                    .get(delimiter)
                    .is_some_and(|b| matches!(b, b'.' | b'_' | b' '))
            {
                delimiter += 1;
            }
            if !base.get(delimiter).is_some_and(|b| {
                if x_pattern {
                    matches!(b, b'x' | b'X')
                } else {
                    matches!(b, b'e' | b'E')
                }
            }) {
                continue;
            }
            let episode_start = delimiter + 1;
            let mut episode_end = episode_start;
            while episode_end < base.len() && base[episode_end].is_ascii_digit() {
                episode_end += 1;
            }
            if !(if x_pattern { 2 } else { 1 }..=if x_pattern { 3 } else { 4 })
                .contains(&(episode_end - episode_start))
                || x_pattern && base.get(episode_end).is_some_and(u8::is_ascii_alphanumeric)
            {
                continue;
            }
            // `.+?` must consume at least one byte, while the following
            // separator run is greedy. Compound S episodes affect only `rest`,
            // which is not used by either identity consumer.
            let mut prefix_end = marker - 1;
            while prefix_end > 1 && wave9_episode_separator(base[prefix_end - 1]) {
                prefix_end -= 1;
            }
            matched = Some((
                &base[..prefix_end],
                &base[season_start..season_end],
                &base[episode_start..episode_end],
            ));
            break;
        }
        if matched.is_some() {
            break;
        }
    }
    let (prefix, season, episode) = matched?;
    let prefix = php_trim_ascii(prefix);
    if prefix.is_empty() {
        return None;
    }
    let mut key = Vec::with_capacity(prefix.len());
    let mut separator = false;
    for &byte in prefix {
        if wave9_episode_separator(byte) {
            separator = true;
        } else {
            if separator && !key.is_empty() {
                key.push(b'.');
            }
            key.push(byte.to_ascii_lowercase());
            separator = false;
        }
    }
    let number = |digits: &[u8]| {
        digits
            .iter()
            .fold(0u32, |n, &b| n * 10 + u32::from(b - b'0'))
    };
    Some((key, number(season), number(episode)))
}

fn wave9_basename(name: &[u8]) -> &[u8] {
    let mut base = php_trim_ascii(name);
    while base.last().is_some_and(|b| matches!(b, b'/' | b'\\')) {
        base = &base[..base.len() - 1];
    }
    match base.iter().rposition(|b| matches!(b, b'/' | b'\\')) {
        Some(slash) => &base[slash + 1..],
        None => base,
    }
}

fn wave9_ancillary(name: &[u8]) -> bool {
    if crate::filename::matches_sample_regex(crate::filename::season_pack_base_name(name)) {
        return true;
    }
    let base = wave9_basename(name);
    let Some(dot) = base.iter().rposition(|&b| b == b'.') else {
        return false;
    };
    // The basename was trimmed before splitting, but may have a final LF when
    // followed by a slash; PCRE's `$` also accepts immediately before that LF.
    let extension = base[dot + 1..]
        .strip_suffix(b"\n")
        .unwrap_or(&base[dot + 1..]);
    [
        &b"nfo"[..],
        b"sfv",
        b"nzb",
        b"srr",
        b"srs",
        b"par2",
        b"jpg",
        b"jpeg",
        b"png",
        b"gif",
        b"bmp",
        b"tif",
        b"tiff",
        b"webp",
        b"txt",
        b"diz",
        b"md5",
        b"sha1",
        b"cue",
        b"log",
        b"srt",
        b"sub",
        b"idx",
        b"ass",
        b"ssa",
        b"vtt",
        b"sup",
    ]
    .iter()
    .any(|candidate| extension.eq_ignore_ascii_case(candidate))
}

/// `-1` requests PHP fallback; otherwise returns the exact Boolean result.
///
/// # Safety
/// Non-null input must be readable for input_len bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_season_pack_is_unclassified_episode(
    input: *const u8,
    input_len: usize,
) -> i32 {
    let Some(bytes) = (unsafe { wave9_input(input, input_len) }) else {
        return -1;
    };
    if bytes.contains(&b'\n') {
        return -1;
    }
    let base = crate::filename::season_pack_base_name(bytes);
    i32::from(
        !crate::filename::matches_sample_regex(base)
            && crate::filename::is_episode_name(base)
            && wave9_episode_identity(bytes).is_none(),
    )
}

/// NUL-separated names; empty input means no names and a final delimiter is
/// optional. Bit zero is distinct identity; remaining bits count ancillary
/// entries, including duplicates and entries after an identity distinction.
/// `-1` requests fallback for invalid/unsupported input or count overflow.
///
/// # Safety
/// Non-null input must be readable for input_len bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_season_pack_has_distinct_identities_nul(
    input: *const u8,
    input_len: usize,
) -> i32 {
    let Some(bytes) = (unsafe { wave9_input(input, input_len) }) else {
        return -1;
    };
    if bytes.contains(&b'\n') {
        return -1;
    }
    if bytes.is_empty() {
        return 0;
    }
    let mut first = None;
    let mut distinct = false;
    let mut ancillary = 0i32;
    for name in bytes.split(|&b| b == 0) {
        if wave9_ancillary(name) {
            let Some(count) = ancillary
                .checked_add(1)
                .filter(|&count| count <= i32::MAX >> 1)
            else {
                return -1;
            };
            ancillary = count;
        }
        if !distinct {
            if let Some(identity) = wave9_episode_identity(name) {
                if let Some(previous) = &first {
                    distinct = previous != &identity;
                } else {
                    first = Some(identity);
                }
            }
        }
    }
    (ancillary << 1) | i32::from(distinct)
}

/// `-1` requests PHP fallback; otherwise returns the exact Boolean result.
///
/// # Safety
/// Non-null input must be readable for input_len bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_season_pack_is_ancillary_name(
    input: *const u8,
    input_len: usize,
) -> i32 {
    let Some(bytes) = (unsafe { wave9_input(input, input_len) }) else {
        return -1;
    };
    i32::from(wave9_ancillary(bytes))
}

/// Returns the first season folder's raw segment offset, `-2` for no folder,
/// or `-1` for invalid/unsupported input. The last segment is the file name.
///
/// # Safety
/// Non-null input must be readable for input_len bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_season_pack_season_folder(
    input: *const u8,
    input_len: usize,
) -> i32 {
    let Some(bytes) = (unsafe { wave9_input(input, input_len) }) else {
        return -1;
    };
    if bytes.contains(&b'\n') {
        return -1;
    }
    let mut start = 0;
    for (end, &byte) in bytes.iter().enumerate() {
        if matches!(byte, b'/' | b'\\') {
            let segment = php_trim_ascii(&bytes[start..end]);
            if !segment.is_empty() && crate::filename::is_season_pack(segment) {
                return i32::try_from(start).unwrap_or(-1);
            }
            start = end + 1;
        }
    }
    -2
}

fn wave9_anime_encoding(input: &[u8]) -> Option<Vec<u8>> {
    let text = std::str::from_utf8(input).ok()?;
    // Unicode whitespace survives later stages or needs ICU/PCRE property
    // agreement in the first pattern, so let the existing PHP implementation
    // handle it. No HTML entity decoding occurs in the PHP method.
    if text.chars().any(|c| !c.is_ascii() && c.is_whitespace()) {
        return None;
    }
    let mut stage = String::with_capacity(input.len());
    let mut chars = text.chars().peekable();
    while let Some(c) = chars.next() {
        if c == 'Ã' {
            let mut lookahead = chars.clone();
            if lookahead.next() == Some('¢') && lookahead.next() == Some('Â') {
                chars = lookahead;
                while chars.peek().is_some_and(|c| {
                    *c == '_' || *c == 'Â' || c.is_ascii() && is_pcre_space(*c as u8)
                }) {
                    chars.next();
                }
                continue;
            }
        }
        stage.push(c);
    }
    let mut second = String::with_capacity(stage.len());
    let mut chars = stage.chars().peekable();
    while let Some(c) = chars.next() {
        if c == 'Ã'
            && chars
                .peek()
                .is_some_and(|c| matches!(c, '¢' | 'Â' | '©' | '€' | '£'))
        {
            chars.next();
        } else if c != 'Â' {
            second.push(c);
        }
    }
    let mut output = Vec::with_capacity(second.len());
    let mut chars = second.chars().peekable();
    while let Some(c) = chars.next() {
        if c == 'Ã' {
            while chars
                .peek()
                .is_some_and(|c| !c.is_ascii() || !is_pcre_space(*c as u8))
            {
                chars.next();
            }
            continue;
        }
        if c.is_ascii() {
            let byte = c as u8;
            if !matches!(byte, 0..=8 | 11..=12 | 14..=31 | 127) {
                output.push(byte);
            }
        } else if !matches!(c, '\u{00c0}'..='\u{00c5}') {
            // NFC cannot be implemented exactly using std. All supported
            // survivors before the final artifact removal are ASCII or the
            // already-normalized U+00C0..U+00C5 characters removed by PHP.
            return None;
        }
    }
    Some(output)
}

unsafe fn wave9_write_output(output: &[u8], out: *mut u8, cap: usize) -> isize {
    let Some(required) = isize::try_from(output.len())
        .ok()
        .filter(|&n| n <= isize::MAX - 3)
    else {
        return -1;
    };
    if output.len() > cap {
        return -required - 3;
    }
    if !output.is_empty() {
        unsafe {
            std::ptr::copy(output.as_ptr(), out, output.len());
        }
    }
    required
}

/// Exact encoding cleanup when the result is ASCII. `-1` requests fallback;
/// `-(required+3)` requests a larger buffer, without writing any output.
///
/// # Safety
/// Non-null input/out must be readable/writable for input_len/out_cap bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_anime_fix_encoding(
    input: *const u8,
    input_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    if out_cap > isize::MAX as usize || out.is_null() && out_cap != 0 {
        return -1;
    }
    let Some(bytes) = (unsafe { wave9_input(input, input_len) }) else {
        return -1;
    };
    let Some(output) = wave9_anime_encoding(bytes) else {
        return -1;
    };
    unsafe { wave9_write_output(&output, out, out_cap) }
}

/// ASCII PreDB identity normalization. `-2` is semantic null (empty input,
/// more than 512 bytes, invalid UTF-8), `-1` requests Unicode/PHP fallback, and
/// `-(required+3)` requests a larger buffer without writing any output.
///
/// # Safety
/// Non-null input/out must be readable/writable for input_len/out_cap bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_identity_key_normalize(
    input: *const u8,
    input_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    if out_cap > isize::MAX as usize || out.is_null() && out_cap != 0 {
        return -1;
    }
    let Some(bytes) = (unsafe { wave9_input(input, input_len) }) else {
        return -1;
    };
    if bytes.is_empty() || bytes.len() > 512 || std::str::from_utf8(bytes).is_err() {
        return -2;
    }
    if !bytes.is_ascii() {
        return -1;
    }
    let Some(output) = normalize_predb_key_ascii(bytes) else {
        return -2;
    };
    unsafe { wave9_write_output(&output, out, out_cap) }
}

#[cfg(test)]
mod wave9_cleaning_tests {
    use super::*;

    type StringExport = unsafe extern "C" fn(*const u8, usize, *mut u8, usize) -> isize;

    fn result(export: StringExport, input: &[u8]) -> Result<Vec<u8>, isize> {
        let mut output = vec![0; input.len() + 8];
        let len = unsafe {
            export(
                input.as_ptr(),
                input.len(),
                output.as_mut_ptr(),
                output.len(),
            )
        };
        if len < 0 {
            Err(len)
        } else {
            output.truncate(len as usize);
            Ok(output)
        }
    }

    #[test]
    fn unclassified_episode_requires_marker_without_parseable_identity() {
        for (input, expected) in [
            (&b"Show.Episode.5.mkv"[..], 1),
            (b"Show.E05.mkv", 1),
            (b"Show.S12345E01.mkv", 1),
            (b"S01E01.mkv", 1),
            (b"Show.1x01e2.mkv", 1),
            (b"Show.S01E01E02.mkv", 0),
            (b"Show.1x01.mkv", 0),
            (b"Show.S01E01.sample.mkv", 0),
            (b"Show.S01.1080p", 0),
            (b"", 0),
        ] {
            assert_eq!(
                unsafe { nntmux_season_pack_is_unclassified_episode(input.as_ptr(), input.len()) },
                expected,
                "{input:?}"
            );
        }
    }

    #[test]
    fn distinct_identity_batch_counts_every_ancillary_entry_after_distinction() {
        for (input, expected) in [
            (&b""[..], 0),
            (b"Show.S01E01.720p.mkv\0show_s1e1.1080p.mkv", 0),
            (
                b"Show.S01E01.mkv\0Show.S01E02.mkv\0cover.jpg\0notes.nfo\0Show.E03.sample.mkv",
                7,
            ),
            (b"Show.S01E01.mkv\0Other.S01E01.mkv", 1),
            (b"Show.1x01.mkv\0Show.S01E01E02.mkv", 0),
            (b"Show.S01E01.mkv\0Show.S01E01.nfo", 2),
            (b"Show.1x01e2.mkv\0Show.E03.mkv", 0),
            (b"cover.jpg\0cover.jpg\0", 4),
        ] {
            assert_eq!(
                unsafe {
                    nntmux_season_pack_has_distinct_identities_nul(input.as_ptr(), input.len())
                },
                expected,
                "{input:?}"
            );
        }
    }

    #[test]
    fn ancillary_detection_keeps_unknown_payloads_and_uses_sample_boundaries() {
        for (input, expected) in [
            (&b" folder\\Show.S01E01.srt "[..], 1),
            (b"folder/image.JPEG/", 1),
            (b"Show.proof.mkv", 1),
            (b"Show.sample.part01.rar", 1),
            (b"Show.sampled.mkv", 0),
            (b"Show.7z", 0),
            (b"Show.pdf", 0),
            (b"Show", 0),
        ] {
            assert_eq!(
                unsafe { nntmux_season_pack_is_ancillary_name(input.as_ptr(), input.len()) },
                expected,
                "{input:?}"
            );
        }
    }

    #[test]
    fn season_folder_returns_raw_outermost_segment_offset_and_ignores_filename() {
        for (input, expected) in [
            (&b"root/ Show.S01 /Show.S02/episode.mkv"[..], 5),
            (b"\\root\\Show.Complete.Series\\episode.mkv", 6),
            (b"Show.S01/episode.mkv", 0),
            (b"Show.S01", -2),
            (b"root/Show.S01E01/file.mkv", -2),
            (b"root/Show.S01/", 5),
        ] {
            assert_eq!(
                unsafe { nntmux_season_pack_season_folder(input.as_ptr(), input.len()) },
                expected,
                "{input:?}"
            );
        }
    }

    #[test]
    fn season_byte_patterns_preserve_unicode_and_invalid_utf8_prefixes() {
        unsafe {
            for name in ["進撃の巨人.E05.mkv".as_bytes(), &b"Show\xff.E05.mkv"[..]] {
                assert_eq!(
                    nntmux_season_pack_is_unclassified_episode(name.as_ptr(), name.len()),
                    1
                );
            }
            for name in [
                "進撃の巨人.S01E01.mkv".as_bytes(),
                &b"Show\xff.S01E01.mkv"[..],
            ] {
                assert_eq!(
                    nntmux_season_pack_is_unclassified_episode(name.as_ptr(), name.len()),
                    0
                );
            }
            for name in ["進撃の巨人.jpg".as_bytes(), &b"Show\xff.nfo"[..]] {
                assert_eq!(
                    nntmux_season_pack_is_ancillary_name(name.as_ptr(), name.len()),
                    1
                );
            }
            for path in [
                "root/進撃の巨人.S01/episode.mkv".as_bytes(),
                &b"root/Show\xff.S01/episode.mkv"[..],
            ] {
                assert_eq!(
                    nntmux_season_pack_season_folder(path.as_ptr(), path.len()),
                    5
                );
            }
            let same = "進撃の巨人.S01E01.mkv\0進撃の巨人_s1e1.nfo".as_bytes();
            assert_eq!(
                nntmux_season_pack_has_distinct_identities_nul(same.as_ptr(), same.len()),
                2
            );
            let different = b"Show\xff.S01E01.mkv\0Show\xfe.S01E01.mkv";
            assert_eq!(
                nntmux_season_pack_has_distinct_identities_nul(different.as_ptr(), different.len()),
                1
            );
            let unicode_case = "École.S01E01.mkv\0école.S01E01.mkv".as_bytes();
            assert_eq!(
                nntmux_season_pack_has_distinct_identities_nul(
                    unicode_case.as_ptr(),
                    unicode_case.len()
                ),
                1
            );
        }
    }

    #[test]
    fn anime_encoding_removes_artifacts_controls_and_preserves_entities() {
        for (input, expected) in [
            (
                &b"Title &amp; &#169; &#xC3; &quot;"[..],
                &b"Title &amp; &#169; &#xC3; &quot;"[..],
            ),
            (b"A\0\x01\tB\n\rC\x0b\x0c\x7f", b"A\tB\n\rC"),
            ("Title Ã¢Â_Â Test".as_bytes(), b"Title Test"),
            ("Title Ã©English End".as_bytes(), b"Title English End"),
            ("Title Ãxyz End".as_bytes(), b"Title  End"),
            ("Title ÂÂ End".as_bytes(), b"Title  End"),
            ("ÀÁÂÃÄÅ".as_bytes(), b""),
        ] {
            assert_eq!(
                result(nntmux_anime_fix_encoding, input),
                Ok(expected.to_vec()),
                "{input:?}"
            );
        }
        assert_eq!(
            result(nntmux_anime_fix_encoding, "Café".as_bytes()),
            Err(-1)
        );
        assert_eq!(result(nntmux_anime_fix_encoding, b"\xff"), Err(-1));
    }

    #[test]
    fn predb_normalize_distinguishes_semantic_null_and_unsupported_unicode() {
        assert_eq!(
            result(
                nntmux_predb_identity_key_normalize,
                b" \tShow__Name . WEB-DL\r\n"
            ),
            Ok(b"show.name.web-dl".to_vec())
        );
        assert_eq!(
            result(nntmux_predb_identity_key_normalize, b"\0\x0b  \r\n"),
            Ok(Vec::new())
        );
        assert_eq!(
            result(nntmux_predb_identity_key_normalize, b"A\0B\x0cC"),
            Ok(b"a\0b.c".to_vec())
        );
        assert_eq!(result(nntmux_predb_identity_key_normalize, b""), Err(-2));
        assert_eq!(
            result(nntmux_predb_identity_key_normalize, &vec![b'a'; 513]),
            Err(-2)
        );
        assert_eq!(
            result(nntmux_predb_identity_key_normalize, b"\xff"),
            Err(-2)
        );
        assert_eq!(
            result(nntmux_predb_identity_key_normalize, "École".as_bytes()),
            Err(-1)
        );
    }

    #[test]
    fn wave9_abi_checks_lengths_nulls_short_buffers_and_declines() {
        unsafe {
            for export in [
                nntmux_season_pack_is_unclassified_episode,
                nntmux_season_pack_has_distinct_identities_nul,
                nntmux_season_pack_is_ancillary_name,
                nntmux_season_pack_season_folder,
            ] {
                assert_eq!(export(std::ptr::null(), 1), -1);
                assert_eq!(export(b"x".as_ptr(), usize::MAX), -1);
            }
            assert_eq!(
                nntmux_season_pack_is_unclassified_episode(std::ptr::null(), 0),
                0
            );
            assert_eq!(
                nntmux_season_pack_has_distinct_identities_nul(std::ptr::null(), 0),
                0
            );
            assert_eq!(nntmux_season_pack_is_ancillary_name(std::ptr::null(), 0), 0);
            assert_eq!(nntmux_season_pack_season_folder(std::ptr::null(), 0), -2);
            for export in [
                nntmux_anime_fix_encoding as StringExport,
                nntmux_predb_identity_key_normalize,
            ] {
                let mut out = [b'Z'; 8];
                assert_eq!(export(std::ptr::null(), 1, out.as_mut_ptr(), 8), -1);
                assert_eq!(export(b"test".as_ptr(), 4, std::ptr::null_mut(), 1), -1);
                assert_eq!(
                    export(b"test".as_ptr(), usize::MAX, out.as_mut_ptr(), 8),
                    -1
                );
                assert_eq!(
                    export(b"test".as_ptr(), 4, out.as_mut_ptr(), usize::MAX),
                    -1
                );
                assert_eq!(export(b"test".as_ptr(), 4, out.as_mut_ptr(), 3), -7);
                assert_eq!(out, [b'Z'; 8]);
                assert_eq!(export(b"test".as_ptr(), 4, std::ptr::null_mut(), 0), -7);
                assert_eq!(export(b"test".as_ptr(), 4, out.as_mut_ptr(), 4), 4);
                assert_eq!(&out[..5], b"testZ");
            }
            assert_eq!(
                nntmux_anime_fix_encoding(std::ptr::null(), 0, std::ptr::null_mut(), 0),
                0
            );
            assert_eq!(
                nntmux_predb_identity_key_normalize(std::ptr::null(), 0, std::ptr::null_mut(), 0),
                -2
            );
        }
    }
}

const DEFAULT_MCP_ALLOWED_ROOT_FILES: &[&[u8]] = &[
    b".env.example",
    b"AGENTS.md",
    b"README.md",
    b"composer.json",
    b"composer.lock",
    b"package.json",
    b"package-lock.json",
    b"phpstan.neon",
    b"phpunit.xml",
    b"pint.json",
    b"vite.config.js",
];

const DEFAULT_MCP_ALLOWED_ROOTS: &[&[u8]] = &[
    b"app",
    b"bootstrap",
    b"config",
    b"database",
    b"resources",
    b"routes",
    b"tests",
];

const DEFAULT_MCP_DENIED_SEGMENTS: &[&[u8]] = &[
    b".git",
    b"node_modules",
    b"public/build",
    b"storage",
    b"storage-runtime",
    b"vendor",
];

const DEFAULT_BLOCKED_USER_AGENTS: &[&[u8]] = &[b"aiostreams", b"usenetstreamer", b"stremio"];

#[inline]
fn contains_ignore_ascii_case(haystack: &[u8], needle: &[u8]) -> bool {
    if needle.is_empty() {
        return true;
    }
    if haystack.len() < needle.len() {
        return false;
    }
    let first_lower = needle[0].to_ascii_lowercase();
    let first_upper = needle[0].to_ascii_uppercase();
    let max_i = haystack.len() - needle.len();
    let mut i = 0usize;
    while i <= max_i {
        let b = haystack[i];
        if (b == first_lower || b == first_upper)
            && haystack[i + 1..i + needle.len()].eq_ignore_ascii_case(&needle[1..])
        {
            return true;
        }
        i += 1;
    }
    false
}

#[inline]
fn mcp_segment_is_credential_or_private_key(segment: &[u8]) -> bool {
    // Matches `/(?:credential|private[_-]?key|id_rsa|id_ed25519|\.pem$|\.p12$|\.pfx$)/i`
    let stripped_lf = segment.strip_suffix(b"\n").unwrap_or(segment);
    if stripped_lf.len() >= 4 {
        let tail = &stripped_lf[stripped_lf.len() - 4..];
        if tail.eq_ignore_ascii_case(b".pem")
            || tail.eq_ignore_ascii_case(b".p12")
            || tail.eq_ignore_ascii_case(b".pfx")
        {
            return true;
        }
    }
    if segment.len() < 6 {
        return false;
    }
    if contains_ignore_ascii_case(segment, b"credential")
        || contains_ignore_ascii_case(segment, b"id_rsa")
        || contains_ignore_ascii_case(segment, b"id_ed25519")
    {
        return true;
    }
    // Check `private[_-]?key`
    if segment.len() >= 10 {
        let mut i = 0usize;
        while i + 10 <= segment.len() {
            if matches!(segment[i], b'p' | b'P')
                && segment[i..i + 7].eq_ignore_ascii_case(b"private")
            {
                let rem = &segment[i + 7..];
                if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"key") {
                    return true;
                }
                if rem.len() >= 4
                    && matches!(rem[0], b'_' | b'-')
                    && rem[1..4].eq_ignore_ascii_case(b"key")
                {
                    return true;
                }
            }
            i += 1;
        }
    }
    false
}

#[inline]
fn mcp_check_not_sensitive_bytes(path: &[u8]) -> i32 {
    for segment in path.split(|&b| b == b'/') {
        if segment.eq_ignore_ascii_case(b".env")
            || (segment.len() >= 5
                && segment[..5].eq_ignore_ascii_case(b".env.")
                && !segment.eq_ignore_ascii_case(b".env.example"))
        {
            return 1;
        }
        if mcp_segment_is_credential_or_private_key(segment) {
            return 2;
        }
    }
    0
}

#[inline]
fn path_matches_denied_segment(path: &[u8], denied: &[u8]) -> bool {
    // Matches `$path === $denied || str_starts_with($path, $denied.'/') || str_contains($path, '/'.$denied.'/')`
    if path == denied {
        return true;
    }
    if path.len() > denied.len() && path.starts_with(denied) && path[denied.len()] == b'/' {
        return true;
    }
    let needle_len = denied.len() + 2;
    if path.len() < needle_len {
        return false;
    }
    let mut i = 0usize;
    while i + needle_len <= path.len() {
        if path[i] == b'/'
            && path[i + 1 + denied.len()] == b'/'
            && &path[i + 1..i + 1 + denied.len()] == denied
        {
            return true;
        }
        i += 1;
    }
    false
}

#[inline]
fn mcp_check_allowed_location_bytes(
    path: &[u8],
    use_defaults: bool,
    root_files_nul: Option<&[u8]>,
    allowed_roots_nul: Option<&[u8]>,
    denied_segments_nul: Option<&[u8]>,
) -> i32 {
    if use_defaults {
        if DEFAULT_MCP_ALLOWED_ROOT_FILES.contains(&path) {
            return 0;
        }
        let root = match path.iter().position(|&b| b == b'/') {
            Some(pos) => &path[..pos],
            None => path,
        };
        if !DEFAULT_MCP_ALLOWED_ROOTS.contains(&root) {
            return 1;
        }
        for &denied in DEFAULT_MCP_DENIED_SEGMENTS {
            if path_matches_denied_segment(path, denied) {
                return 2;
            }
        }
        return 0;
    }

    if let Some(rf) = root_files_nul {
        if !rf.is_empty() && rf.split(|&b| b == 0).any(|item| item == path) {
            return 0;
        }
    }
    let root = match path.iter().position(|&b| b == b'/') {
        Some(pos) => &path[..pos],
        None => path,
    };
    let root_allowed = match allowed_roots_nul {
        Some(ar) if !ar.is_empty() => ar.split(|&b| b == 0).any(|item| item == root),
        _ => false,
    };
    if !root_allowed {
        return 1;
    }
    if let Some(ds) = denied_segments_nul {
        if !ds.is_empty() {
            for denied in ds.split(|&b| b == 0) {
                if path_matches_denied_segment(path, denied) {
                    return 2;
                }
            }
        }
    }
    0
}

/// Checks `ProjectPathGuard::assertNotSensitive(path)`.
/// Returns:
/// - `0` when valid (not sensitive)
/// - `1` when an `.env` file other than `.env.example` is detected
/// - `2` when a credential or private-key segment is detected
/// - `-1` on invalid arguments
///
/// # Safety
/// `path` must be readable for `path_len` bytes when non-null and `path_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_mcp_check_not_sensitive(path: *const u8, path_len: usize) -> i32 {
    let Some(bytes) = (unsafe { wave9_input(path, path_len) }) else {
        return -1;
    };
    mcp_check_not_sensitive_bytes(bytes)
}

/// Checks `ProjectPathGuard::assertAllowedLocation(path)`.
/// Returns:
/// - `0` when allowed
/// - `1` when outside allowed source roots
/// - `2` when matching a denied segment
/// - `-1` on invalid arguments
///
/// # Safety
/// All non-null pointers must be readable for their respective lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_mcp_check_allowed_location(
    path: *const u8,
    path_len: usize,
    use_defaults: u32,
    root_files_nul: *const u8,
    root_files_len: usize,
    allowed_roots_nul: *const u8,
    allowed_roots_len: usize,
    denied_segments_nul: *const u8,
    denied_segments_len: usize,
) -> i32 {
    let Some(bytes) = (unsafe { wave9_input(path, path_len) }) else {
        return -1;
    };
    if use_defaults != 0 {
        return mcp_check_allowed_location_bytes(bytes, true, None, None, None);
    }
    let Some(rf) = (unsafe { wave9_input(root_files_nul, root_files_len) }) else {
        return -1;
    };
    let Some(ar) = (unsafe { wave9_input(allowed_roots_nul, allowed_roots_len) }) else {
        return -1;
    };
    let Some(ds) = (unsafe { wave9_input(denied_segments_nul, denied_segments_len) }) else {
        return -1;
    };
    mcp_check_allowed_location_bytes(bytes, false, Some(rf), Some(ar), Some(ds))
}

/// Normalizes and validates a relative path for `ProjectPathGuard::normalize(path)` up to filesystem resolution.
///
/// Writes `out_status`:
/// - `0`: valid; normalized path written to `out_buf` (returns byte length `> 0`)
/// - `1`: empty or interior NUL (`'A non-empty repository-relative path is required.'`)
/// - `2`: absolute path (`'Absolute paths are not allowed.'`)
/// - `3`: `.` or `..` traversal segment (`'Path traversal is not allowed.'`)
/// - `4`: outside allowed source roots (normalized path written to `out_buf`, returns its byte length)
/// - `5`: denied segment (normalized path written to `out_buf`, returns its byte length)
/// - `6`: environment file violation (`'Environment files other than .env.example are not available through MCP.'`)
/// - `7`: credential/private-key violation (`'Credential and private-key files are not available through MCP.'`)
///
/// Returns `< -1` (`-(required_len as isize)`) if `out_cap` is insufficient, or `-1` on invalid arguments.
///
/// # Safety
/// All non-null pointers must be valid for reads/writes of their respective lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_mcp_normalize_relative_path(
    path: *const u8,
    path_len: usize,
    use_defaults: u32,
    root_files_nul: *const u8,
    root_files_len: usize,
    allowed_roots_nul: *const u8,
    allowed_roots_len: usize,
    denied_segments_nul: *const u8,
    denied_segments_len: usize,
    out_status: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if out_status.is_null() || out_cap > isize::MAX as usize || (out_buf.is_null() && out_cap > 0) {
        return -1;
    }
    let Some(bytes) = (unsafe { wave9_input(path, path_len) }) else {
        return -1;
    };
    let (rf, ar, ds) = if use_defaults != 0 {
        (None, None, None)
    } else {
        let Some(rf) = (unsafe { wave9_input(root_files_nul, root_files_len) }) else {
            return -1;
        };
        let Some(ar) = (unsafe { wave9_input(allowed_roots_nul, allowed_roots_len) }) else {
            return -1;
        };
        let Some(ds) = (unsafe { wave9_input(denied_segments_nul, denied_segments_len) }) else {
            return -1;
        };
        (Some(rf), Some(ar), Some(ds))
    };

    // 1. `$path = trim(str_replace('\\', '/', $path));`
    let trimmed = php_trim_ascii(bytes);
    if trimmed.is_empty() || trimmed.contains(&0) {
        unsafe { *out_status = 1 };
        return 0;
    }
    // 2. `if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1)`
    if matches!(trimmed[0], b'/' | b'\\')
        || (trimmed.len() >= 3
            && trimmed[0].is_ascii_alphabetic()
            && trimmed[1] == b':'
            && matches!(trimmed[2], b'/' | b'\\'))
    {
        unsafe { *out_status = 2 };
        return 0;
    }

    // 3. `$path = preg_replace('#/+#', '/', $path)` + replace `\` with `/`
    let mut normalized = Vec::with_capacity(trimmed.len());
    for &b in trimmed {
        let mapped = if b == b'\\' { b'/' } else { b };
        if mapped == b'/' && normalized.last() == Some(&b'/') {
            continue;
        }
        normalized.push(mapped);
    }

    // 4. `if (in_array('..', $segments, true) || in_array('.', $segments, true))`
    for seg in normalized.split(|&b| b == b'/') {
        if seg == b"." || seg == b".." {
            unsafe { *out_status = 3 };
            return 0;
        }
    }

    // Ensure `out_buf` can hold `normalized` (needed for status 0, 4, 5).
    if normalized.len() > out_cap {
        return -(normalized.len() as isize);
    }
    unsafe {
        std::ptr::copy_nonoverlapping(normalized.as_ptr(), out_buf, normalized.len());
    }

    // 5. `assertAllowedLocation`
    let loc_status = mcp_check_allowed_location_bytes(&normalized, use_defaults != 0, rf, ar, ds);
    if loc_status == 1 {
        unsafe { *out_status = 4 };
        return normalized.len() as isize;
    }
    if loc_status == 2 {
        unsafe { *out_status = 5 };
        return normalized.len() as isize;
    }

    // 6. `assertNotSensitive`
    let sens_status = mcp_check_not_sensitive_bytes(&normalized);
    if sens_status == 1 {
        unsafe { *out_status = 6 };
        return 0;
    }
    if sens_status == 2 {
        unsafe { *out_status = 7 };
        return 0;
    }

    unsafe { *out_status = 0 };
    normalized.len() as isize
}

#[inline]
fn http_is_json_media_type_bytes(bytes: &[u8]) -> bool {
    let mut parts = bytes.split(|&b| b == b';');
    let Some(first) = parts.next() else {
        return false;
    };
    if !php_trim_ascii(first).eq_ignore_ascii_case(b"application/json") {
        return false;
    }
    for raw_param in parts {
        let param = php_trim_ascii(raw_param);
        if param.is_empty() {
            continue;
        }
        let Some(eq_pos) = param.iter().position(|&b| b == b'=') else {
            return false;
        };
        let name = php_trim_ascii(&param[..eq_pos]);
        if !name.eq_ignore_ascii_case(b"charset") {
            return false;
        }
        let mut val = php_trim_ascii(&param[eq_pos + 1..]);
        while let Some(&b'"') = val.first() {
            val = &val[1..];
        }
        while let Some(&b'"') = val.last() {
            val = &val[..val.len() - 1];
        }
        if !val.eq_ignore_ascii_case(b"utf-8") {
            return false;
        }
    }
    true
}

/// Checks `ValidateHttpQueryInput::isJsonMediaType(contentType)`.
/// Returns `1` if valid JSON media type, `0` if invalid, or `-1` on invalid arguments.
///
/// # Safety
/// `input` must be readable for `input_len` bytes when non-null and `input_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_http_is_json_media_type(input: *const u8, input_len: usize) -> i32 {
    let Some(bytes) = (unsafe { wave9_input(input, input_len) }) else {
        return -1;
    };
    i32::from(http_is_json_media_type_bytes(bytes))
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum QueryBodyValKind {
    Scalar,
    ScalarList,
    Unsupported,
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum JsonParseOutcome {
    ValidObject,
    ValidNonObject,
    InvalidOrTooDeep,
    Decline,
}

#[inline]
fn skip_json_ws(bytes: &[u8], mut i: usize) -> usize {
    while i < bytes.len() && matches!(bytes[i], b' ' | b'\t' | b'\n' | b'\r') {
        i += 1;
    }
    i
}

/// Parses a JSON string literal starting at `bytes[i] == b'"'`.
/// Returns `Ok((next_index, raw_inner_slice, has_backslash))` or `Err(JsonParseOutcome)`.
fn parse_json_string<'a>(
    bytes: &'a [u8],
    i: usize,
) -> Result<(usize, &'a [u8], bool), JsonParseOutcome> {
    if i >= bytes.len() || bytes[i] != b'"' {
        return Err(JsonParseOutcome::InvalidOrTooDeep);
    }
    let start = i + 1;
    let mut p = start;
    let mut has_backslash = false;
    while p < bytes.len() {
        let b = bytes[p];
        if b == b'"' {
            let inner = &bytes[start..p];
            if !inner.is_ascii() && std::str::from_utf8(inner).is_err() {
                return Err(JsonParseOutcome::InvalidOrTooDeep);
            }
            return Ok((p + 1, inner, has_backslash));
        }
        if b < 0x20 {
            return Err(JsonParseOutcome::InvalidOrTooDeep);
        }
        if b == b'\\' {
            has_backslash = true;
            if p + 1 >= bytes.len() {
                return Err(JsonParseOutcome::InvalidOrTooDeep);
            }
            let esc = bytes[p + 1];
            match esc {
                b'"' | b'\\' | b'/' | b'b' | b'f' | b'n' | b'r' | b't' => {
                    p += 2;
                }
                b'u' => {
                    return Err(JsonParseOutcome::Decline);
                }
                _ => {
                    return Err(JsonParseOutcome::InvalidOrTooDeep);
                }
            }
        } else {
            p += 1;
        }
    }
    Err(JsonParseOutcome::InvalidOrTooDeep)
}

/// Parses a JSON number starting at `bytes[i]`.
fn parse_json_number(bytes: &[u8], mut i: usize) -> Result<usize, JsonParseOutcome> {
    let start = i;
    if i < bytes.len() && bytes[i] == b'-' {
        i += 1;
    }
    if i >= bytes.len() {
        return Err(JsonParseOutcome::InvalidOrTooDeep);
    }
    if bytes[i] == b'0' {
        i += 1;
        if i < bytes.len() && bytes[i].is_ascii_digit() {
            return Err(JsonParseOutcome::InvalidOrTooDeep);
        }
    } else if matches!(bytes[i], b'1'..=b'9') {
        i += 1;
        while i < bytes.len() && bytes[i].is_ascii_digit() {
            i += 1;
        }
    } else {
        return Err(JsonParseOutcome::InvalidOrTooDeep);
    }
    if i < bytes.len() && bytes[i] == b'.' {
        i += 1;
        let frac_start = i;
        while i < bytes.len() && bytes[i].is_ascii_digit() {
            i += 1;
        }
        if i == frac_start {
            return Err(JsonParseOutcome::InvalidOrTooDeep);
        }
    }
    if i < bytes.len() && matches!(bytes[i], b'e' | b'E') {
        return Err(JsonParseOutcome::Decline);
    }
    if i - start > 18 {
        return Err(JsonParseOutcome::Decline);
    }
    Ok(i)
}

/// Parses any JSON value with PHP `json_decode(..., depth: 3)` container depth rules (`max_container_depth = 2`).
fn parse_json_value(
    bytes: &[u8],
    mut i: usize,
    container_depth: usize,
) -> Result<(usize, QueryBodyValKind), JsonParseOutcome> {
    i = skip_json_ws(bytes, i);
    if i >= bytes.len() {
        return Err(JsonParseOutcome::InvalidOrTooDeep);
    }
    match bytes[i] {
        b'"' => {
            let (next, _, _) = parse_json_string(bytes, i)?;
            Ok((next, QueryBodyValKind::Scalar))
        }
        b'-' | b'0'..=b'9' => {
            let next = parse_json_number(bytes, i)?;
            Ok((next, QueryBodyValKind::Scalar))
        }
        b't' => {
            if bytes[i..].starts_with(b"true") {
                Ok((i + 4, QueryBodyValKind::Unsupported))
            } else {
                Err(JsonParseOutcome::InvalidOrTooDeep)
            }
        }
        b'f' => {
            if bytes[i..].starts_with(b"false") {
                Ok((i + 5, QueryBodyValKind::Unsupported))
            } else {
                Err(JsonParseOutcome::InvalidOrTooDeep)
            }
        }
        b'n' => {
            if bytes[i..].starts_with(b"null") {
                Ok((i + 4, QueryBodyValKind::Unsupported))
            } else {
                Err(JsonParseOutcome::InvalidOrTooDeep)
            }
        }
        b'[' => {
            if container_depth >= 2 {
                return Err(JsonParseOutcome::InvalidOrTooDeep);
            }
            i = skip_json_ws(bytes, i + 1);
            if i >= bytes.len() {
                return Err(JsonParseOutcome::InvalidOrTooDeep);
            }
            if bytes[i] == b']' {
                return Ok((i + 1, QueryBodyValKind::ScalarList));
            }
            let mut all_scalar = true;
            loop {
                let (next, elem_kind) = parse_json_value(bytes, i, container_depth + 1)?;
                if elem_kind != QueryBodyValKind::Scalar {
                    all_scalar = false;
                }
                i = skip_json_ws(bytes, next);
                if i >= bytes.len() {
                    return Err(JsonParseOutcome::InvalidOrTooDeep);
                }
                if bytes[i] == b']' {
                    let kind = if all_scalar {
                        QueryBodyValKind::ScalarList
                    } else {
                        QueryBodyValKind::Unsupported
                    };
                    return Ok((i + 1, kind));
                }
                if bytes[i] != b',' {
                    return Err(JsonParseOutcome::InvalidOrTooDeep);
                }
                i += 1;
            }
        }
        b'{' => {
            if container_depth >= 2 {
                return Err(JsonParseOutcome::InvalidOrTooDeep);
            }
            i = skip_json_ws(bytes, i + 1);
            if i >= bytes.len() {
                return Err(JsonParseOutcome::InvalidOrTooDeep);
            }
            if bytes[i] == b'}' {
                return Ok((i + 1, QueryBodyValKind::Unsupported));
            }
            loop {
                i = skip_json_ws(bytes, i);
                let (after_key, _, has_bs) = parse_json_string(bytes, i)?;
                if has_bs {
                    return Err(JsonParseOutcome::Decline);
                }
                i = skip_json_ws(bytes, after_key);
                if i >= bytes.len() || bytes[i] != b':' {
                    return Err(JsonParseOutcome::InvalidOrTooDeep);
                }
                let (after_val, _) = parse_json_value(bytes, i + 1, container_depth + 1)?;
                i = skip_json_ws(bytes, after_val);
                if i >= bytes.len() {
                    return Err(JsonParseOutcome::InvalidOrTooDeep);
                }
                if bytes[i] == b'}' {
                    return Ok((i + 1, QueryBodyValKind::Unsupported));
                }
                if bytes[i] != b',' {
                    return Err(JsonParseOutcome::InvalidOrTooDeep);
                }
                i += 1;
            }
        }
        _ => Err(JsonParseOutcome::InvalidOrTooDeep),
    }
}

fn parse_top_level_query_json<'a>(
    bytes: &'a [u8],
    entries: &mut [(&'a [u8], QueryBodyValKind); 66],
    entries_len: &mut usize,
) -> JsonParseOutcome {
    *entries_len = 0;
    let mut i = skip_json_ws(bytes, 0);
    if i >= bytes.len() {
        return JsonParseOutcome::InvalidOrTooDeep;
    }
    if bytes[i] != b'{' {
        return match parse_json_value(bytes, i, 0) {
            Ok((next, _)) => {
                if skip_json_ws(bytes, next) == bytes.len() {
                    JsonParseOutcome::ValidNonObject
                } else {
                    JsonParseOutcome::InvalidOrTooDeep
                }
            }
            Err(outcome) => outcome,
        };
    }

    i = skip_json_ws(bytes, i + 1);
    if i >= bytes.len() {
        return JsonParseOutcome::InvalidOrTooDeep;
    }
    if bytes[i] == b'}' {
        return if skip_json_ws(bytes, i + 1) == bytes.len() {
            JsonParseOutcome::ValidObject
        } else {
            JsonParseOutcome::InvalidOrTooDeep
        };
    }

    loop {
        i = skip_json_ws(bytes, i);
        let (after_key, key_slice, has_bs) = match parse_json_string(bytes, i) {
            Ok(v) => v,
            Err(outcome) => return outcome,
        };
        if has_bs {
            // Decline only if the escaped key might contain `\n` or match KEY_PATTERN;
            // if the first byte is already outside `[A-Za-z0-9_]` (like `<a\"\r\n>`), it is
            // guaranteed to fail KEY_PATTERN once the rest of the JSON syntax succeeds.
            if key_slice
                .first()
                .is_none_or(|b| b.is_ascii_alphanumeric() || *b == b'_')
            {
                return JsonParseOutcome::Decline;
            }
        }
        i = skip_json_ws(bytes, after_key);
        if i >= bytes.len() || bytes[i] != b':' {
            return JsonParseOutcome::InvalidOrTooDeep;
        }
        let (after_val, val_kind) = match parse_json_value(bytes, i + 1, 1) {
            Ok(v) => v,
            Err(outcome) => return outcome,
        };
        if entries[..*entries_len]
            .iter()
            .any(|(existing, _)| *existing == key_slice)
        {
            return JsonParseOutcome::Decline;
        }
        if *entries_len >= 66 {
            return JsonParseOutcome::Decline;
        }
        entries[*entries_len] = (key_slice, val_kind);
        *entries_len += 1;
        i = skip_json_ws(bytes, after_val);
        if i >= bytes.len() {
            return JsonParseOutcome::InvalidOrTooDeep;
        }
        if bytes[i] == b'}' {
            return if skip_json_ws(bytes, i + 1) == bytes.len() {
                JsonParseOutcome::ValidObject
            } else {
                JsonParseOutcome::InvalidOrTooDeep
            };
        }
        if bytes[i] != b',' {
            return JsonParseOutcome::InvalidOrTooDeep;
        }
        i += 1;
    }
}

#[inline]
fn is_valid_query_key_pattern(key: &[u8]) -> bool {
    (1..=32).contains(&key.len()) && key.iter().all(|&b| b.is_ascii_alphanumeric() || b == b'_')
}

/// Validates HTTP QUERY input (`ValidateHttpQueryInput::validate`).
///
/// Returns:
/// - `0` when valid (`*out_status = 0`)
/// - `> 0` bytes written to `out_buf` (error message) when invalid (`*out_status` set to `400`, `413`, or `415`)
/// - `< -1` (`-(required_len as isize)`) when `out_cap` is too small for the error message
/// - `-1` when declined to PHP fallback or on invalid arguments
///
/// # Safety
/// All non-null pointers must be valid for reads/writes of their respective lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_http_validate_query(
    content_type: *const u8,
    content_type_len: usize,
    has_content_type: u32,
    raw_body: *const u8,
    raw_body_len: usize,
    max_body_bytes: usize,
    query_keys_nul: *const u8,
    query_keys_len: usize,
    query_keys_count: usize,
    out_status: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if out_status.is_null() || out_cap > isize::MAX as usize || (out_buf.is_null() && out_cap > 0) {
        return -1;
    }
    if query_keys_count > 66 {
        return -1;
    }
    let Some(ct_bytes) = (unsafe { wave9_input(content_type, content_type_len) }) else {
        return -1;
    };
    let Some(body_bytes) = (unsafe { wave9_input(raw_body, raw_body_len) }) else {
        return -1;
    };
    let Some(qk_bytes) = (unsafe { wave9_input(query_keys_nul, query_keys_len) }) else {
        return -1;
    };

    let write_err = |status: i32, msg: &[u8]| -> isize {
        unsafe { *out_status = status };
        let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
        out.extend(msg);
        out.finish()
    };

    // 1. Content-Type check
    if has_content_type == 0 || !http_is_json_media_type_bytes(ct_bytes) {
        return write_err(
            415,
            b"QUERY requests must use Content-Type: application/json",
        );
    }

    // 2. Max body size check
    if raw_body_len > max_body_bytes {
        return write_err(413, b"QUERY body exceeds the maximum size");
    }

    // 3. Parse JSON body with depth 3 using stack storage
    let mut body_entries: [(&[u8], QueryBodyValKind); 66] = [(&[], QueryBodyValKind::Scalar); 66];
    let mut body_len = 0usize;
    match parse_top_level_query_json(body_bytes, &mut body_entries, &mut body_len) {
        JsonParseOutcome::ValidObject => {}
        JsonParseOutcome::ValidNonObject => {
            return write_err(400, b"QUERY body must be a JSON object");
        }
        JsonParseOutcome::InvalidOrTooDeep => {
            return write_err(400, b"QUERY body must be valid JSON without nested values");
        }
        JsonParseOutcome::Decline => {
            return -1;
        }
    }

    // Collect query keys on stack
    let mut query_keys: [&[u8]; 66] = [&[]; 66];
    let mut qk_len = 0usize;
    if query_keys_count > 0 {
        for qk in qk_bytes.split(|&b| b == 0) {
            if qk.contains(&b'\n') || qk_len >= 66 {
                return -1;
            }
            query_keys[qk_len] = qk;
            qk_len += 1;
        }
        if qk_len != query_keys_count {
            return -1;
        }
    }

    let qk_slice = &query_keys[..qk_len];
    let body_slice = &body_entries[..body_len];

    // 4. Validate KEY_PATTERN on all keys
    for &qk in qk_slice {
        if !is_valid_query_key_pattern(qk) {
            return write_err(
                400,
                b"Parameter names may only contain letters, digits and underscores (max 32)",
            );
        }
    }
    for &(bk, _) in body_slice {
        if !is_valid_query_key_pattern(bk) {
            return write_err(
                400,
                b"Parameter names may only contain letters, digits and underscores (max 32)",
            );
        }
    }

    // 5. Count unique keys (`count($keys) > 64`) and find first duplicate in `$query` order
    let mut first_dup: Option<&[u8]> = None;
    let mut overlap_count = 0usize;
    for &qk in qk_slice {
        if body_slice.iter().any(|&(bk, _)| bk == qk) {
            overlap_count += 1;
            if first_dup.is_none() {
                first_dup = Some(qk);
            }
        }
    }
    let unique_keys = qk_slice.len() + body_slice.len() - overlap_count;
    if unique_keys > 64 {
        return write_err(400, b"QUERY requests accept at most 64 parameters");
    }

    // 6. Duplicate parameter in both URL and body
    if let Some(dup) = first_dup {
        unsafe { *out_status = 400 };
        let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
        out.extend(b"Parameter ");
        out.extend(dup);
        out.extend(b" must not be sent in both the URL and the body");
        return out.finish();
    }

    // 7. Validate body values
    for &(bk, kind) in body_slice {
        let valid = match kind {
            QueryBodyValKind::Scalar => true,
            QueryBodyValKind::ScalarList => bk == b"cat",
            QueryBodyValKind::Unsupported => false,
        };
        if !valid {
            unsafe { *out_status = 400 };
            let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
            out.extend(b"Parameter ");
            out.extend(bk);
            out.extend(b" has an unsupported type");
            return out.finish();
        }
    }

    unsafe { *out_status = 0 };
    0
}

/// Checks `BlockAbusiveServices::isBlockedUserAgent` and `matchesAnyUserAgentPattern`.
/// When `use_default_blocked != 0`, checks against `DEFAULT_BLOCKED_USER_AGENTS` (`aiostreams`, `usenetstreamer`, `stremio`).
/// Otherwise checks `patterns_count` NUL-separated patterns in `patterns_nul[..patterns_len]`.
/// Returns `1` if matched, `0` if not matched, or `-1` on invalid arguments.
///
/// # Safety
/// Non-null pointers must be readable for their respective lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_http_matches_user_agent_patterns_nul(
    user_agent: *const u8,
    user_agent_len: usize,
    use_default_blocked: u32,
    patterns_nul: *const u8,
    patterns_len: usize,
    patterns_count: usize,
) -> i32 {
    let Some(ua) = (unsafe { wave9_input(user_agent, user_agent_len) }) else {
        return -1;
    };
    if use_default_blocked != 0 {
        for &pat in DEFAULT_BLOCKED_USER_AGENTS {
            if contains_ignore_ascii_case(ua, pat) {
                return 1;
            }
        }
        return 0;
    }
    if patterns_count == 0 {
        return 0;
    }
    let Some(pats) = (unsafe { wave9_input(patterns_nul, patterns_len) }) else {
        return -1;
    };
    let mut seen = 0usize;
    let mut matched = false;
    for pat in pats.split(|&b| b == 0) {
        seen += 1;
        if !matched && contains_ignore_ascii_case(ua, pat) {
            matched = true;
        }
    }
    if seen != patterns_count {
        return -1;
    }
    i32::from(matched)
}

#[inline]
fn is_sensitive_http_query_param(key: &[u8]) -> bool {
    key.eq_ignore_ascii_case(b"apikey")
        || key.eq_ignore_ascii_case(b"api_token")
        || key.eq_ignore_ascii_case(b"token")
        || key.eq_ignore_ascii_case(b"passkey")
}

/// Classifies `keys_count` NUL-separated query parameter keys for `BlockAbusiveServices::redactSensitiveQueryParameters`.
/// Writes `b'1'` (sensitive) or `b'0'` (not sensitive) per key into `out_buf`.
///
/// # Safety
/// Non-null pointers must be valid for reads/writes of their respective lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_http_redact_sensitive_query_keys_nul(
    keys_nul: *const u8,
    keys_len: usize,
    keys_count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if out_cap > isize::MAX as usize || (out_buf.is_null() && out_cap > 0) {
        return -1;
    }
    if keys_count == 0 {
        return 0;
    }
    let Some(bytes) = (unsafe { wave9_input(keys_nul, keys_len) }) else {
        return -1;
    };
    if keys_count > out_cap {
        return -(keys_count as isize);
    }
    let mut idx = 0usize;
    for key in bytes.split(|&b| b == 0) {
        if idx >= keys_count {
            return -1;
        }
        let flag = if is_sensitive_http_query_param(key) {
            b'1'
        } else {
            b'0'
        };
        unsafe {
            *out_buf.add(idx) = flag;
        }
        idx += 1;
    }
    if idx != keys_count {
        return -1;
    }
    keys_count as isize
}

#[inline]
fn unix_basename_span(path: &[u8]) -> (usize, usize) {
    let mut end = path.len();
    while end > 0 && path[end - 1] == b'/' {
        end -= 1;
    }
    if end == 0 {
        return (0, 0);
    }
    let start = match path[..end].iter().rposition(|&b| b == b'/') {
        Some(slash) => slash + 1,
        None => 0,
    };
    (start, end)
}

#[inline]
fn matches_known_media_container_ext(ext: &[u8]) -> bool {
    // `/\.(mkv|mp4|avi|mov|wmv|flv|m4v|ts|webm|mpg|mpeg|iso|m2ts|ogm|divx|vob)$/i`
    if !(2..=4).contains(&ext.len()) {
        return false;
    }
    let mut lower = [0u8; 4];
    for (i, &b) in ext.iter().enumerate() {
        lower[i] = b.to_ascii_lowercase();
    }
    matches!(
        &lower[..ext.len()],
        b"mkv"
            | b"mp4"
            | b"avi"
            | b"mov"
            | b"wmv"
            | b"flv"
            | b"m4v"
            | b"ts"
            | b"webm"
            | b"mpg"
            | b"mpeg"
            | b"iso"
            | b"m2ts"
            | b"ogm"
            | b"divx"
            | b"vob"
    )
}

/// Computes `NzbImportService::deriveReleaseNameFromNzbPath(path)` as a packed `(start << 32) | len` span (`>= 0`),
/// or `-1` on invalid arguments / declined input containing `\n`.
///
/// # Safety
/// `path` must be readable for `path_len` bytes when non-null and `path_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_derive_release_name_span(
    path: *const u8,
    path_len: usize,
) -> i64 {
    if path_len > u32::MAX as usize {
        return -1;
    }
    let Some(bytes) = (unsafe { wave9_input(path, path_len) }) else {
        return -1;
    };
    if bytes.contains(&b'\n') {
        return -1;
    }
    let (start, mut end) = unix_basename_span(bytes);
    let base = &bytes[start..end];

    // 1. Strip trailing `.nzb` or `.nzb.gz` (case-insensitive)
    if base.len() >= 7 && base[base.len() - 7..].eq_ignore_ascii_case(b".nzb.gz") {
        end -= 7;
    } else if base.len() >= 4 && base[base.len() - 4..].eq_ignore_ascii_case(b".nzb") {
        end -= 4;
    }

    // 2. Strip one trailing known media/container extension
    let after_nzb = &bytes[start..end];
    if let Some(dot) = after_nzb.iter().rposition(|&b| b == b'.') {
        if matches_known_media_container_ext(&after_nzb[dot + 1..]) {
            end = start + dot;
        }
    }

    // 3. `rtrim($name, ". \t\n\r\0\x0B")`
    while end > start && (bytes[end - 1] == b'.' || is_php_trim_char(bytes[end - 1])) {
        end -= 1;
    }

    let len = end - start;
    ((start as i64) << 32) | (len as i64)
}

#[inline]
fn strip_trailing_paren_part_counters(mut s: &[u8]) -> &[u8] {
    // Strips all trailing `(\d+/\d+)` groups matching `/(\(\d+\/\d+\))*$/`
    while s.ends_with(b")") {
        let mut p = s.len() - 1;
        let digits2_end = p;
        while p > 0 && s[p - 1].is_ascii_digit() {
            p -= 1;
        }
        if p == digits2_end || p == 0 || s[p - 1] != b'/' {
            break;
        }
        p -= 1; // skip '/'
        let digits1_end = p;
        while p > 0 && s[p - 1].is_ascii_digit() {
            p -= 1;
        }
        if p == digits1_end || p == 0 || s[p - 1] != b'(' {
            break;
        }
        s = &s[..p - 1];
    }
    s
}

#[inline]
fn is_utf7_base64_byte(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'+' || b == b'/'
}

#[inline]
fn has_ambiguous_mb_list_encodings_ascii(s: &[u8]) -> bool {
    for (i, &b) in s.iter().enumerate() {
        if !(0x20..=0x7E).contains(&b) || b == b'&' {
            return true;
        }
        if b == b'+' {
            if i + 3 >= s.len() || s.get(i + 1) == Some(&b'-') {
                return true;
            }
            // UTF-7 requires >= 3 base64 chars after '+' to form at least one 16-bit UTF-16 code unit
            if s.get(i + 1).is_some_and(|&c1| is_utf7_base64_byte(c1))
                && s.get(i + 2).is_some_and(|&c2| is_utf7_base64_byte(c2))
                && s.get(i + 3).is_some_and(|&c3| is_utf7_base64_byte(c3))
            {
                return true;
            }
        }
    }
    false
}

/// Computes `NzbImportService::normalizeImportedSubject(subject)`.
/// Returns:
/// - `>= 0`: normalized byte length written to `out_buf`
/// - `< -1` (`-(required_len as isize)`): when `out_cap` is too small
/// - `-1`: when declined to PHP fallback (`mb_convert_encoding` needed) or invalid arguments
///
/// # Safety
/// Non-null pointers must be valid for reads/writes of their respective lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_normalize_imported_subject(
    subject: *const u8,
    subject_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if out_cap > isize::MAX as usize || (out_buf.is_null() && out_cap > 0) {
        return -1;
    }
    let Some(bytes) = (unsafe { wave9_input(subject, subject_len) }) else {
        return -1;
    };
    if bytes.contains(&b'\n') || bytes.contains(&b'\r') {
        return -1;
    }

    let stripped = strip_trailing_paren_part_counters(bytes);

    // Find first case-insensitive `yenc` in `stripped`
    let mut prefix_end = stripped.len();
    if stripped.len() >= 4 {
        let mut i = 0usize;
        while i + 4 <= stripped.len() {
            if matches!(stripped[i], b'y' | b'Y')
                && stripped[i + 1..i + 4].eq_ignore_ascii_case(b"enc")
            {
                prefix_end = i;
                break;
            }
            i += 1;
        }
    }

    // The pre-trim result is `stripped[..prefix_end] + "yEnc"`.
    // Because `"yEnc"` ends with `'c'` (not a trim char), `trim(stripped[..prefix_end] + "yEnc")`
    // only trims leading PHP trim chars from `stripped[..prefix_end]`.
    let raw_prefix = &stripped[..prefix_end];
    let mut start = 0usize;
    while start < raw_prefix.len() && is_php_trim_char(raw_prefix[start]) {
        start += 1;
    }
    let trimmed_prefix = &raw_prefix[start..];

    if has_ambiguous_mb_list_encodings_ascii(trimmed_prefix) {
        return -1;
    }

    let total_len = trimmed_prefix.len() + 4;
    if total_len > out_cap {
        return -(total_len as isize);
    }
    unsafe {
        if !trimmed_prefix.is_empty() {
            std::ptr::copy_nonoverlapping(trimmed_prefix.as_ptr(), out_buf, trimmed_prefix.len());
        }
        std::ptr::copy_nonoverlapping(b"yEnc".as_ptr(), out_buf.add(trimmed_prefix.len()), 4);
    }
    total_len as isize
}

/// Computes `NzbService::groupsFromXref(xref)` as `\n`-separated unique group names in `out_buf`.
/// Returns `0` when no groups match, `> 0` bytes written, `< -1` when `out_cap` is too small,
/// or `-1` on invalid arguments.
///
/// # Safety
/// Non-null pointers must be valid for reads/writes of their respective lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_groups_from_xref(
    xref: *const u8,
    xref_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if out_cap > isize::MAX as usize || (out_buf.is_null() && out_cap > 0) {
        return -1;
    }
    let Some(bytes) = (unsafe { wave9_input(xref, xref_len) }) else {
        return -1;
    };
    if bytes.is_empty() {
        return 0;
    }

    // Collect `\S+` tokens
    let mut tokens: Vec<&[u8]> = Vec::new();
    let mut colon_groups: Vec<&[u8]> = Vec::new();
    let mut i = 0usize;
    while i < bytes.len() {
        while i < bytes.len() && is_pcre_space(bytes[i]) {
            i += 1;
        }
        if i >= bytes.len() {
            break;
        }
        let start = i;
        while i < bytes.len() && !is_pcre_space(bytes[i]) {
            i += 1;
        }
        let tok = &bytes[start..i];
        tokens.push(tok);
        if tok.len() >= 3 {
            if let Some(rel_colon) = tok[1..tok.len() - 1].iter().rposition(|&b| b == b':') {
                let grp = &tok[..1 + rel_colon];
                if !colon_groups.contains(&grp) {
                    colon_groups.push(grp);
                }
            }
        }
    }

    if tokens.is_empty() {
        return 0;
    }

    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    if !colon_groups.is_empty() {
        for (idx, grp) in colon_groups.iter().enumerate() {
            if idx > 0 {
                out.push(b'\n');
            }
            out.extend(grp);
        }
    } else {
        let mut unique_tokens: Vec<&[u8]> = Vec::with_capacity(tokens.len());
        for tok in tokens {
            if !unique_tokens.contains(&tok) {
                unique_tokens.push(tok);
            }
        }
        for (idx, tok) in unique_tokens.iter().enumerate() {
            if idx > 0 {
                out.push(b'\n');
            }
            out.extend(tok);
        }
    }
    out.finish()
}

/// Checks `NzbService::isTemporaryNzbPathName(path)` (`/\.nzb\.gz\.tmp\.\d+\.[0-9a-f]{12}$/` on `basename($path)`).
/// Returns `1` if matched, `0` if not matched, or `-1` on invalid arguments / declined input.
///
/// # Safety
/// `path` must be readable for `path_len` bytes when non-null and `path_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_is_temporary_path_name(
    path: *const u8,
    path_len: usize,
) -> i32 {
    let Some(bytes) = (unsafe { wave9_input(path, path_len) }) else {
        return -1;
    };
    if bytes.contains(&b'\n') {
        return -1;
    }
    let (start, end) = unix_basename_span(bytes);
    let base = &bytes[start..end];
    // Minimum length: `.nzb.gz.tmp.` (12) + `1` (1) + `.` (1) + 12 hex (12) = 26
    if base.len() < 26 {
        return 0;
    }
    let hex_part = &base[base.len() - 12..];
    if !hex_part
        .iter()
        .all(|&b| b.is_ascii_digit() || matches!(b, b'a'..=b'f'))
    {
        return 0;
    }
    if base[base.len() - 13] != b'.' {
        return 0;
    }
    let digits_end = base.len() - 13;
    let mut p = digits_end;
    while p > 0 && base[p - 1].is_ascii_digit() {
        p -= 1;
    }
    if p == digits_end || p < 12 {
        return 0;
    }
    i32::from(&base[p - 12..p] == b".nzb.gz.tmp.")
}

#[cfg(test)]
mod wave10_worker6_tests {
    use super::*;

    #[test]
    fn mcp_path_guard_exports_validate_and_normalize() {
        unsafe {
            assert_eq!(nntmux_mcp_check_not_sensitive(std::ptr::null(), 1), -1);
            assert_eq!(nntmux_mcp_check_not_sensitive(b".env".as_ptr(), 4), 1);
            assert_eq!(
                nntmux_mcp_check_not_sensitive(b"app/.env.local".as_ptr(), 14),
                1
            );
            assert_eq!(
                nntmux_mcp_check_not_sensitive(b".env.example".as_ptr(), 12),
                0
            );
            assert_eq!(
                nntmux_mcp_check_not_sensitive(b"tests/id_rsa".as_ptr(), 12),
                2
            );
            assert_eq!(
                nntmux_mcp_check_not_sensitive(b"app/cert.PEM".as_ptr(), 12),
                2
            );
            assert_eq!(
                nntmux_mcp_check_not_sensitive(b"app/Models/Release.php".as_ptr(), 22),
                0
            );

            assert_eq!(
                nntmux_mcp_check_allowed_location(
                    b".env.example".as_ptr(),
                    12,
                    1,
                    std::ptr::null(),
                    0,
                    std::ptr::null(),
                    0,
                    std::ptr::null(),
                    0,
                ),
                0
            );
            assert_eq!(
                nntmux_mcp_check_allowed_location(
                    b"vendor/autoload.php".as_ptr(),
                    19,
                    1,
                    std::ptr::null(),
                    0,
                    std::ptr::null(),
                    0,
                    std::ptr::null(),
                    0,
                ),
                1
            );
            assert_eq!(
                nntmux_mcp_check_allowed_location(
                    b"app/vendor/test.php".as_ptr(),
                    19,
                    1,
                    std::ptr::null(),
                    0,
                    std::ptr::null(),
                    0,
                    std::ptr::null(),
                    0,
                ),
                2
            );

            let mut status = -1i32;
            let mut buf = [0u8; 64];
            let raw = b"  app\\\\Services//Mcp/ProjectPathGuard.php  ";
            let written = nntmux_mcp_normalize_relative_path(
                raw.as_ptr(),
                raw.len(),
                1,
                std::ptr::null(),
                0,
                std::ptr::null(),
                0,
                std::ptr::null(),
                0,
                &mut status,
                buf.as_mut_ptr(),
                buf.len(),
            );
            assert_eq!(status, 0);
            assert_eq!(
                &buf[..written as usize],
                b"app/Services/Mcp/ProjectPathGuard.php"
            );
        }
    }

    #[test]
    fn http_query_validator_and_block_abusive_exports_work() {
        unsafe {
            let ct = b"Application/JSON; charset=\"utf-8\"";
            assert_eq!(nntmux_http_is_json_media_type(ct.as_ptr(), ct.len()), 1);
            let bad_ct = b"application/json; charset=latin1";
            assert_eq!(
                nntmux_http_is_json_media_type(bad_ct.as_ptr(), bad_ct.len()),
                0
            );

            let mut status = -1i32;
            let mut buf = [0u8; 128];
            let body = br#"{"api_token":"abc","id":"ubuntu","cat":[2000,5030]}"#;
            let qk = b"maxage\0minsize";
            let res = nntmux_http_validate_query(
                ct.as_ptr(),
                ct.len(),
                1,
                body.as_ptr(),
                body.len(),
                8192,
                qk.as_ptr(),
                qk.len(),
                2,
                &mut status,
                buf.as_mut_ptr(),
                buf.len(),
            );
            assert_eq!(res, 0);
            assert_eq!(status, 0);

            // Duplicate key in URL and body
            let dup_qk = b"id\0api_token";
            let res_dup = nntmux_http_validate_query(
                ct.as_ptr(),
                ct.len(),
                1,
                body.as_ptr(),
                body.len(),
                8192,
                dup_qk.as_ptr(),
                dup_qk.len(),
                2,
                &mut status,
                buf.as_mut_ptr(),
                buf.len(),
            );
            assert!(res_dup > 0);
            assert_eq!(status, 400);
            assert_eq!(
                &buf[..res_dup as usize],
                b"Parameter id must not be sent in both the URL and the body"
            );

            // User-Agent matching
            let ua = b"Mozilla/5.0 AIOStreams/1.2";
            assert_eq!(
                nntmux_http_matches_user_agent_patterns_nul(
                    ua.as_ptr(),
                    ua.len(),
                    1,
                    std::ptr::null(),
                    0,
                    0
                ),
                1
            );
            let keys = b"t\0ApIkEy\0id\0api_token";
            let mut flags = [0u8; 8];
            let n = nntmux_http_redact_sensitive_query_keys_nul(
                keys.as_ptr(),
                keys.len(),
                4,
                flags.as_mut_ptr(),
                flags.len(),
            );
            assert_eq!(n, 4);
            assert_eq!(&flags[..4], b"0101");
        }
    }

    #[test]
    fn nzb_import_and_service_helpers_match_php() {
        unsafe {
            let path = b"/tmp/nested/Show - S01E07 [1080p].mkv.. .nzb.gz";
            let span = nntmux_nzb_derive_release_name_span(path.as_ptr(), path.len());
            assert!(span >= 0);
            let start = (span >> 32) as usize;
            let len = (span & 0xFFFF_FFFF) as usize;
            assert_eq!(&path[start..start + len], b"Show - S01E07 [1080p].mkv");

            let path2 = b"/tmp/nested/Show - S01E07 [1080p].MKV.NZB.GZ";
            let span2 = nntmux_nzb_derive_release_name_span(path2.as_ptr(), path2.len());
            let start2 = (span2 >> 32) as usize;
            let len2 = (span2 & 0xFFFF_FFFF) as usize;
            assert_eq!(&path2[start2..start2 + len2], b"Show - S01E07 [1080p]");

            let subj = b"  Show.Name.S01E01.1080p-GRP (1/10)(2/10)";
            let mut out = [0u8; 64];
            let w = nntmux_nzb_normalize_imported_subject(
                subj.as_ptr(),
                subj.len(),
                out.as_mut_ptr(),
                out.len(),
            );
            assert_eq!(&out[..w as usize], b"Show.Name.S01E01.1080p-GRP yEnc");

            let xref = b"alt.binaries.tv:123 alt.binaries.hd:456 alt.binaries.tv:789";
            let wx =
                nntmux_nzb_groups_from_xref(xref.as_ptr(), xref.len(), out.as_mut_ptr(), out.len());
            assert_eq!(&out[..wx as usize], b"alt.binaries.tv\nalt.binaries.hd");

            let tmp_path = b"/var/nzb/a/b/guid123.nzb.gz.tmp.48219.0123456789ab";
            assert_eq!(
                nntmux_nzb_is_temporary_path_name(tmp_path.as_ptr(), tmp_path.len()),
                1
            );
            let upper_tmp = b"/var/nzb/a/b/guid123.nzb.gz.tmp.48219.0123456789AB";
            assert_eq!(
                nntmux_nzb_is_temporary_path_name(upper_tmp.as_ptr(), upper_tmp.len()),
                0
            );
        }
    }
}

// Release-removal helpers use PHP's byte semantics. Database quoting/lookups
// remain in PHP; the D tag carries the exact arguments for that boundary.
fn remover_clean_spaces(input: &[u8]) -> Vec<u8> {
    let mut output = Vec::with_capacity(input.len());
    let mut p = 0;
    while p < input.len() {
        if is_pcre_space(input[p]) {
            let start = p;
            p += 1;
            while p < input.len() && is_pcre_space(input[p]) {
                p += 1;
            }
            output.push(if p - start > 1 { b' ' } else { input[start] });
        } else {
            output.push(input[p]);
            p += 1;
        }
    }
    let trimmed = php_trim_ascii(&output);
    let start = trimmed.as_ptr() as usize - output.as_ptr() as usize;
    let end = start + trimmed.len();
    output.truncate(end);
    output.drain(..start);
    output
}

fn remover_format_like(value: &[u8], column: &[u8], out: &mut Vec<u8>) {
    out.extend_from_slice(b" LIKE '%");
    if value.contains(&b' ') {
        let mut seen = std::collections::HashSet::new();
        let mut first = true;
        for word in value.split(|&b| b == b' ') {
            if seen.insert(word) {
                if !first {
                    out.extend_from_slice(b"%' AND ");
                    out.extend_from_slice(column);
                    out.extend_from_slice(b" LIKE '%");
                }
                out.extend_from_slice(word);
                first = false;
            }
        }
    } else {
        out.extend_from_slice(value);
    }
    out.extend_from_slice(b"%' ");
}

/// PHP 8 is_numeric string grammar, without float conversion or range limits.
fn remover_is_numeric(mut value: &[u8]) -> bool {
    while value.first().is_some_and(|b| is_pcre_space(*b)) {
        value = &value[1..];
    }
    while value.last().is_some_and(|b| is_pcre_space(*b)) {
        value = &value[..value.len() - 1];
    }
    let mut p = usize::from(matches!(value.first(), Some(b'+' | b'-')));
    let start = p;
    while p < value.len() && value[p].is_ascii_digit() {
        p += 1;
    }
    let mut digits = p - start;
    if value.get(p) == Some(&b'.') {
        p += 1;
        let start = p;
        while p < value.len() && value[p].is_ascii_digit() {
            p += 1;
        }
        digits += p - start;
    }
    if digits == 0 {
        return false;
    }
    if matches!(value.get(p), Some(b'e' | b'E')) {
        p += 1;
        if matches!(value.get(p), Some(b'+' | b'-')) {
            p += 1;
        }
        let start = p;
        while p < value.len() && value[p].is_ascii_digit() {
            p += 1;
        }
        if start == p {
            return false;
        }
    }
    p == value.len()
}

fn remover_numeric(column: &[u8], modifier: &[u8], value: &[u8], out: &mut Vec<u8>) {
    let operator: &[u8] = match modifier {
        b"equals" => b" = ",
        b"bigger" => b" > ",
        b"smaller" => b" < ",
        _ => {
            out.push(b'F');
            return;
        }
    };
    out.extend_from_slice(b"S AND ");
    out.extend_from_slice(column);
    out.extend_from_slice(operator);
    out.extend_from_slice(value);
}

fn remover_imdb(modifier: &[u8], value: &[u8], out: &mut Vec<u8>) {
    if modifier != b"equals" {
        out.push(b'F');
    } else if value == b"NULL" {
        out.extend_from_slice(b"S AND imdbid IS NULL ");
    } else {
        out.extend_from_slice(b"S AND imdbid = ");
        out.extend_from_slice(value);
    }
}

fn remover_completion(modifier: &[u8], value: &[u8], out: &mut Vec<u8>) {
    if modifier == b"smaller" && remover_is_numeric(value) {
        out.extend_from_slice(b"S AND completion > 0 AND completion < ");
        out.extend_from_slice(value);
    } else {
        out.push(b'F');
    }
}

fn remover_delegate(column: &[u8], modifier: &[u8], value: &[u8], out: &mut Vec<u8>) -> bool {
    if column.contains(&0) || modifier.contains(&0) || value.contains(&0) {
        return false;
    }
    out.push(b'D');
    out.extend_from_slice(column);
    out.push(0);
    out.extend_from_slice(modifier);
    out.push(0);
    out.extend_from_slice(value);
    true
}

fn remover_criteria(column: &[u8], modifier: &[u8], value: &[u8], out: &mut Vec<u8>) -> bool {
    match column {
        b"categories_id" | b"nzbstatus" | b"videos_id" if modifier == b"equals" => {
            remover_numeric(column, modifier, value, out);
        }
        b"totalpart" => remover_numeric(column, modifier, value, out),
        b"size" if remover_is_numeric(value) => remover_numeric(column, modifier, value, out),
        b"adddate" | b"postdate" if remover_is_numeric(value) => {
            let operator: &[u8] = match modifier {
                b"bigger" => b" < NOW() - INTERVAL ",
                b"smaller" => b" > NOW() - INTERVAL ",
                _ => {
                    out.push(b'F');
                    return true;
                }
            };
            out.extend_from_slice(b"S AND ");
            out.extend_from_slice(column);
            out.extend_from_slice(operator);
            out.extend_from_slice(value);
            out.extend_from_slice(b" HOUR");
        }
        b"fromname" | b"name" | b"searchname" if modifier == b"like" => {
            out.extend_from_slice(b"S AND ");
            out.extend_from_slice(column);
            out.push(b' ');
            remover_format_like(value, column, out);
        }
        b"fromname" | b"name" | b"searchname" | b"guid" if modifier == b"equals" => {
            return remover_delegate(column, modifier, value, out);
        }
        b"groupname" => return remover_delegate(column, modifier, value, out),
        b"imdbid" => remover_imdb(modifier, value, out),
        b"completion" => remover_completion(modifier, value, out),
        _ => out.push(b'F'),
    }
    true
}

/// Release-remover transforms. Modes 0/1/7 return raw bytes. Modes 2/3/4/5/6
/// return S+SQL or F; mode 2 additionally returns I for exact `ignore`.
/// Modes 2/3 return D+column+NUL+modifier+NUL+value for PHP database work.
/// `-1` declines invalid pointers, modes or unsupported conversions/encoding;
/// `-(required+3)` requests capacity without writing any output.
///
/// # Safety
/// Non-null pointers must cover their specified lengths, and output must not
/// overlap input. Null is valid only with length/capacity zero.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_remover_transform(
    input: *const u8,
    input_len: usize,
    second: *const u8,
    second_len: usize,
    third: *const u8,
    third_len: usize,
    mode: u32,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    if out_cap > isize::MAX as usize || out.is_null() && out_cap != 0 {
        return -1;
    }
    let (Some(input), Some(second), Some(third)) = (unsafe {
        (
            wave9_input(input, input_len),
            wave9_input(second, second_len),
            wave9_input(third, third_len),
        )
    }) else {
        return -1;
    };
    let mut output = Vec::new();
    match mode {
        0 => output = remover_clean_spaces(input),
        1 => remover_format_like(input, second, &mut output),
        2 => {
            if input.contains(&0) {
                return -1;
            }
            if input == b"ignore" {
                output.push(b'I');
            } else {
                let mut args = input.split(|&b| b == b'=');
                let (Some(column), Some(modifier), Some(value), None) =
                    (args.next(), args.next(), args.next(), args.next())
                else {
                    return unsafe { wave9_write_output(b"F", out, out_cap) };
                };
                if !remover_criteria(
                    &remover_clean_spaces(column),
                    &remover_clean_spaces(modifier),
                    &remover_clean_spaces(value),
                    &mut output,
                ) {
                    return -1;
                }
            }
        }
        3 => {
            if !remover_criteria(input, second, third, &mut output) {
                return -1;
            }
        }
        4 => remover_numeric(input, second, third, &mut output),
        5 => remover_imdb(input, second, &mut output),
        6 => remover_completion(input, second, &mut output),
        7 => {
            if !input.is_empty() && remover_is_numeric(input) {
                let mut value = input;
                while value.first().is_some_and(|b| is_pcre_space(*b)) {
                    value = &value[1..];
                }
                while value.last().is_some_and(|b| is_pcre_space(*b)) {
                    value = &value[..value.len() - 1];
                }
                // Floating, exponential and overflowing numeric strings retain
                // PHP sprintf's integer conversion through the caller fallback.
                let Ok(value) = std::str::from_utf8(value).unwrap_or("").parse::<i64>() else {
                    return -1;
                };
                output.extend_from_slice(b"AND id = ");
                output.extend_from_slice(value.to_string().as_bytes());
            }
        }
        _ => return -1,
    }
    unsafe { wave9_write_output(&output, out, out_cap) }
}

/// Returns whether cleanName may use this candidate: nonempty printable ASCII
/// without an anchored [part/total] prefix. A literal `0` remains valid.
///
/// # Safety
/// Non-null input must cover input_len bytes; null is valid with length zero.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_subject_candidate(input: *const u8, input_len: usize) -> i32 {
    let Some(input) = (unsafe { wave9_input(input, input_len) }) else {
        return -1;
    };
    if input.is_empty() || input.iter().any(|b| !(0x20..=0x7e).contains(b)) {
        return 0;
    }
    if input.first() == Some(&b'[') {
        let mut p = 1;
        while p < input.len() && is_pcre_space(input[p]) {
            p += 1;
        }
        let start = p;
        while p < input.len() && input[p].is_ascii_digit() {
            p += 1;
        }
        if p > start {
            while p < input.len() && is_pcre_space(input[p]) {
                p += 1;
            }
            if input.get(p) == Some(&b'/') {
                p += 1;
                while p < input.len() && is_pcre_space(input[p]) {
                    p += 1;
                }
                let start = p;
                while p < input.len() && input[p].is_ascii_digit() {
                    p += 1;
                }
                if p > start {
                    while p < input.len() && is_pcre_space(input[p]) {
                        p += 1;
                    }
                    if input.get(p) == Some(&b']') {
                        return 0;
                    }
                }
            }
        }
    }
    1
}

fn guid_classification(input: &[u8]) -> u8 {
    let unsupported =
        !(1..=40).contains(&input.len()) || input.iter().any(|b| !(0x20..=0x7e).contains(b));
    let hex = (32..=40).contains(&input.len()) && input.iter().all(u8::is_ascii_hexdigit);
    u8::from(unsupported) | (u8::from(hex) << 1)
}

/// Bit 0 means unsupported (not 1..40 printable ASCII bytes), bit 1 means the
/// entire input is 32..40 ASCII hexadecimal bytes. Invalid pointers return -1.
///
/// # Safety
/// Non-null input must cover input_len bytes; null is valid with length zero.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_guid_classify(input: *const u8, input_len: usize) -> i32 {
    let Some(input) = (unsafe { wave9_input(input, input_len) }) else {
        return -1;
    };
    i32::from(guid_classification(input))
}

/// Classifies count records encoded as u32 little-endian length followed by
/// those bytes. Returns count bytes, -1 for malformed/trailing input or invalid
/// pointers, or -(count+3) for insufficient capacity without writing any output.
///
/// # Safety
/// Non-null pointers must cover their lengths. Output must not overlap input.
/// Null is valid only with length/capacity zero.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_guid_classify_batch(
    input: *const u8,
    input_len: usize,
    count: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    if out_cap > isize::MAX as usize || out.is_null() && out_cap != 0 || count > input_len / 4 {
        return -1;
    }
    let Some(mut input) = (unsafe { wave9_input(input, input_len) }) else {
        return -1;
    };
    let mut output = Vec::with_capacity(count);
    for _ in 0..count {
        let Some(header) = input.get(..4) else {
            return -1;
        };
        let len = u32::from_le_bytes(header.try_into().unwrap()) as usize;
        input = &input[4..];
        let Some(value) = input.get(..len) else {
            return -1;
        };
        output.push(guid_classification(value));
        input = &input[len..];
    }
    if !input.is_empty() {
        return -1;
    }
    unsafe { wave9_write_output(&output, out, out_cap) }
}

#[cfg(test)]
mod wave11_remover_tests {
    use super::*;

    fn transform(mode: u32, input: &[u8], second: &[u8], third: &[u8]) -> Result<Vec<u8>, isize> {
        let mut out = vec![0; 1024];
        let len = unsafe {
            nntmux_remover_transform(
                input.as_ptr(),
                input.len(),
                second.as_ptr(),
                second.len(),
                third.as_ptr(),
                third.len(),
                mode,
                out.as_mut_ptr(),
                out.len(),
            )
        };
        if len < 0 {
            Err(len)
        } else {
            out.truncate(len as usize);
            Ok(out)
        }
    }

    #[test]
    fn spaces_and_like_preserve_php_byte_behavior() {
        for (input, expected) in [
            (&b"\0 \tA\tB\nC\x0c"[..], &b"A\tB\nC\x0c"[..]),
            (b"A\x0c\x0cB", b"A B"),
            (b"\x0c", b"\x0c"),
            (b"\x0b", b""),
            (b"A\0B", b"A\0B"),
            (b"\xff A", b"\xff A"),
            (b"", b""),
        ] {
            assert_eq!(transform(0, input, b"", b"").unwrap(), expected);
        }
        assert_eq!(
            transform(1, b" x x  y ", b"name", b"").unwrap(),
            b" LIKE '%%' AND name LIKE '%x%' AND name LIKE '%y%' "
        );
        assert_eq!(
            transform(1, b"0 00 0", b"name", b"").unwrap(),
            b" LIKE '%0%' AND name LIKE '%00%' "
        );
        assert_eq!(
            transform(1, b"a\0b", b"n\0m", b"").unwrap(),
            b" LIKE '%a\0b%' "
        );
    }

    #[test]
    fn numeric_grammar_does_not_depend_on_float_range() {
        for value in [
            "0",
            "+1",
            "-2",
            ".5",
            "1.",
            "1.e2",
            "-.1E-5",
            "1e99999",
            " \t1\n\x0c",
            "9223372036854775808",
        ] {
            assert!(remover_is_numeric(value.as_bytes()), "{value:?}");
        }
        for value in [
            "", " ", "+", ".", "1e", "0x10", "NaN", "INF", "1\0", "1_0", "1 2", "\u{a0}1",
        ] {
            assert!(!remover_is_numeric(value.as_bytes()), "{value:?}");
        }
    }

    #[test]
    fn criteria_tags_and_exact_sql_match_native_helpers() {
        for (mode, input, second, third, expected) in [
            (2, &b"ignore"[..], &b""[..], &b""[..], &b"I"[..]),
            (2, b" ignore ", b"", b"", b"F"),
            (2, b"x=equals=a=b", b"", b"", b"F"),
            (
                2,
                b" totalpart = bigger = thing ",
                b"",
                b"",
                b"S AND totalpart > thing",
            ),
            (
                2,
                b" name = equals = hello  world ",
                b"",
                b"",
                b"Dname\0equals\0hello world",
            ),
            (
                3,
                b"name",
                b"like",
                b"a a b",
                b"S AND name  LIKE '%a%' AND name LIKE '%b%' ",
            ),
            (
                3,
                b"categories_id",
                b"equals",
                b"any\0bytes",
                b"S AND categories_id = any\0bytes",
            ),
            (3, b"size", b"bigger", b"no", b"F"),
            (3, b"size", b"smaller", b"1e99999", b"S AND size < 1e99999"),
            (
                3,
                b"postdate",
                b"bigger",
                b".5",
                b"S AND postdate < NOW() - INTERVAL .5 HOUR",
            ),
            (
                3,
                b"adddate",
                b"smaller",
                b"2",
                b"S AND adddate > NOW() - INTERVAL 2 HOUR",
            ),
            (3, b"adddate", b"equals", b"2", b"F"),
            (3, b"guid", b"equals", b"abc", b"Dguid\0equals\0abc"),
            (3, b"groupname", b"bogus", b"a", b"Dgroupname\0bogus\0a"),
            (4, b"arbitrary", b"equals", b"no", b"S AND arbitrary = no"),
            (5, b"equals", b"NULL", b"", b"S AND imdbid IS NULL "),
            (5, b"equals", b"null", b"", b"S AND imdbid = null"),
            (5, b"like", b"1", b"", b"F"),
            (
                6,
                b"smaller",
                b"1e2",
                b"",
                b"S AND completion > 0 AND completion < 1e2",
            ),
            (6, b"smaller", b"a", b"", b"F"),
            (6, b"equals", b"1", b"", b"F"),
        ] {
            assert_eq!(transform(mode, input, second, third).unwrap(), expected);
        }
        assert_eq!(transform(2, b"name=equals=a\0b", b"", b""), Err(-1));
        assert_eq!(transform(3, b"name", b"equals", b"a\0b"), Err(-1));
        assert_eq!(transform(3, b"groupname", b"x\0y", b"a"), Err(-1));
    }

    #[test]
    fn blacklist_preserves_exact_integers_and_declines_php_float_conversion() {
        for (value, expected) in [
            ("", ""),
            ("oops", ""),
            ("0", "AND id = 0"),
            (" \t+00042\n", "AND id = 42"),
            ("-0", "AND id = 0"),
            ("-9223372036854775808", "AND id = -9223372036854775808"),
            ("9223372036854775807", "AND id = 9223372036854775807"),
        ] {
            assert_eq!(
                transform(7, value.as_bytes(), b"", b"").unwrap(),
                expected.as_bytes()
            );
        }
        for value in [
            "1e2",
            "1.",
            ".0",
            "9223372036854775808",
            "-9223372036854775809",
        ] {
            assert_eq!(transform(7, value.as_bytes(), b"", b""), Err(-1));
        }
    }

    #[test]
    fn subject_and_guid_flags_match_anchored_ascii_contract() {
        for (input, expected) in [
            (&b""[..], 0),
            (b"0", 1),
            (b" ", 1),
            (b"[ 1 / 20 ]name", 0),
            (b"x[1/2]", 1),
            (b"[1/2", 1),
            (b"[a/2]", 1),
            (b"[1/2a]", 1),
            (b"a\n", 0),
            (b"a\0", 0),
            (b"a\x7f", 0),
            (b"\xff", 0),
        ] {
            assert_eq!(
                unsafe { nntmux_subject_candidate(input.as_ptr(), input.len()) },
                expected
            );
        }
        for (input, expected) in [
            (&b""[..], 1),
            (b" ", 0),
            (b"a\n", 1),
            (b"0123456789abcdef0123456789ABCDEF", 2),
            (b"0123456789abcdef0123456789ABCDEF01234567", 2),
            (b"0123456789abcdef0123456789ABCDEF012345678", 1),
        ] {
            assert_eq!(
                unsafe { nntmux_guid_classify(input.as_ptr(), input.len()) },
                expected
            );
        }
    }

    #[test]
    fn abi_rejects_malformed_pointers_and_preserves_short_buffers() {
        unsafe {
            let null = std::ptr::null();
            let mut out = [b'!'; 8];
            assert_eq!(
                nntmux_remover_transform(null, 1, null, 0, null, 0, 0, out.as_mut_ptr(), 8),
                -1
            );
            assert_eq!(
                nntmux_remover_transform(null, 0, null, 1, null, 0, 0, out.as_mut_ptr(), 8),
                -1
            );
            assert_eq!(
                nntmux_remover_transform(null, 0, null, 0, null, 1, 0, out.as_mut_ptr(), 8),
                -1
            );
            assert_eq!(
                nntmux_remover_transform(null, 0, null, 0, null, 0, 0, std::ptr::null_mut(), 1),
                -1
            );
            assert_eq!(
                nntmux_remover_transform(null, 0, null, 0, null, 0, 99, out.as_mut_ptr(), 8),
                -1
            );
            assert_eq!(
                nntmux_remover_transform(
                    b"abc".as_ptr(),
                    3,
                    null,
                    0,
                    null,
                    0,
                    0,
                    out.as_mut_ptr(),
                    2
                ),
                -6
            );
            assert_eq!(out, [b'!'; 8]);
            assert_eq!(
                nntmux_remover_transform(null, 0, null, 0, null, 0, 2, std::ptr::null_mut(), 0),
                -4
            );
            assert_eq!(
                nntmux_remover_transform(null, 0, null, 0, null, 0, 0, std::ptr::null_mut(), 0),
                0
            );
            assert_eq!(nntmux_subject_candidate(null, 1), -1);
            assert_eq!(nntmux_subject_candidate(null, 0), 0);
            assert_eq!(nntmux_guid_classify(null, 1), -1);
            assert_eq!(nntmux_guid_classify(null, 0), 1);
        }
    }

    #[test]
    fn guid_batch_requires_exact_framing_and_capacity() {
        let mut bytes = Vec::new();
        for value in [
            &b""[..],
            &b"ab"[..],
            &b"0123456789abcdef0123456789ABCDEF"[..],
        ] {
            bytes.extend_from_slice(&(value.len() as u32).to_le_bytes());
            bytes.extend_from_slice(value);
        }
        unsafe {
            let mut out = [b'!'; 3];
            assert_eq!(
                nntmux_guid_classify_batch(bytes.as_ptr(), bytes.len(), 3, out.as_mut_ptr(), 3),
                3
            );
            assert_eq!(out, [1, 0, 2]);
            out.fill(b'!');
            assert_eq!(
                nntmux_guid_classify_batch(bytes.as_ptr(), bytes.len(), 3, out.as_mut_ptr(), 2),
                -6
            );
            assert_eq!(out, [b'!'; 3]);
            for (len, count) in [(bytes.len() - 1, 3), (bytes.len(), 2), (2, 1), (0, 1)] {
                assert_eq!(
                    nntmux_guid_classify_batch(bytes.as_ptr(), len, count, out.as_mut_ptr(), 3),
                    -1
                );
                assert_eq!(out, [b'!'; 3]);
            }
            assert_eq!(
                nntmux_guid_classify_batch(std::ptr::null(), 0, 0, std::ptr::null_mut(), 0),
                0
            );
            assert_eq!(
                nntmux_guid_classify_batch(std::ptr::null(), 1, 0, out.as_mut_ptr(), 3),
                -1
            );
            assert_eq!(
                nntmux_guid_classify_batch(bytes.as_ptr(), bytes.len(), 3, std::ptr::null_mut(), 1),
                -1
            );
            assert_eq!(
                nntmux_guid_classify_batch(bytes.as_ptr(), bytes.len(), 3, std::ptr::null_mut(), 0),
                -6
            );
            let malformed = [0xff; 4];
            assert_eq!(
                nntmux_guid_classify_batch(malformed.as_ptr(), 4, 1, out.as_mut_ptr(), 3),
                -1
            );
        }
    }
}

// Wave 12: byte-preserving candidates. Non-ASCII PCRE inputs request PHP's
// locale-aware fallback instead of guessing its character tables.
fn regex_release_count(input: &[u8]) -> &[u8] {
    for i in 0..input.len() {
        if !matches!(input[i], b'[' | b'(') && !is_pcre_space(input[i]) {
            continue;
        }
        let mut p = i + 1;
        let start = p;
        while p < input.len() && p - start < 5 && input[p].is_ascii_digit() {
            p += 1;
        }
        if p == start {
            continue;
        }
        if matches!(input.get(p), Some(b'/' | b'-')) {
            p += 1;
        } else if p + 3 < input.len()
            && (is_pcre_space(input[p]) || input[p] == b'_')
            && input[p + 1..p + 3].eq_ignore_ascii_case(b"of")
            && (is_pcre_space(input[p + 3]) || input[p + 3] == b'_')
        {
            p += 4;
        } else {
            continue;
        }
        let start = p;
        while p < input.len() && p - start < 5 && input[p].is_ascii_digit() {
            p += 1;
        }
        if p > start
            && input
                .get(p)
                .is_some_and(|b| matches!(b, b']' | b')' | b'$' | b':') || is_pcre_space(*b))
        {
            return &input[start..p];
        }
    }
    b"0"
}

fn regex_release_display(input: &[u8]) -> &[u8] {
    fn space(s: &[u8], p: &mut usize) {
        while *p < s.len() && is_pcre_space(s[*p]) {
            *p += 1;
        }
    }
    fn digits(s: &[u8], p: &mut usize) -> bool {
        let start = *p;
        while *p < s.len() && s[*p].is_ascii_digit() {
            *p += 1;
        }
        *p > start
    }
    fn take(s: &[u8], p: &mut usize, b: u8) -> bool {
        if s.get(*p) == Some(&b) {
            *p += 1;
            true
        } else {
            false
        }
    }
    let mut p = 0;
    space(input, &mut p);
    if !take(input, &mut p, b'[') {
        return input;
    }
    space(input, &mut p);
    if !digits(input, &mut p) {
        return input;
    }
    space(input, &mut p);
    if !take(input, &mut p, b'/') {
        return input;
    }
    space(input, &mut p);
    if !digits(input, &mut p) {
        return input;
    }
    space(input, &mut p);
    if !take(input, &mut p, b']') {
        return input;
    }
    space(input, &mut p);
    take(input, &mut p, b'-');
    space(input, &mut p);
    let Some(quote) = [b"\"".as_slice(), b"#34;", b"&#34;", b"&quot;"]
        .into_iter()
        .find(|q| {
            input
                .get(p..p + q.len())
                .is_some_and(|s| s.eq_ignore_ascii_case(q))
        })
    else {
        return input;
    };
    p += quote.len();
    let start = p;
    while p < input.len()
        && !input
            .get(p..p + quote.len())
            .is_some_and(|s| s.eq_ignore_ascii_case(quote))
        && !matches!(input[p], b'\r' | b'\n')
    {
        p += 1;
    }
    if p == start
        || !input
            .get(p..p + quote.len())
            .is_some_and(|s| s.eq_ignore_ascii_case(quote))
    {
        return input;
    }
    let file = php_trim_ascii(&input[start..p]);
    p += quote.len();
    space(input, &mut p);
    if p < input.len() {
        take(input, &mut p, b'-');
        space(input, &mut p);
        if !input
            .get(p..p + 4)
            .is_some_and(|s| s.eq_ignore_ascii_case(b"yenc"))
        {
            return input;
        }
        p += 4;
        let before = p;
        space(input, &mut p);
        if p > before && input.get(p) == Some(&b'(') {
            p += 1;
            if !digits(input, &mut p)
                || !take(input, &mut p, b'/')
                || !digits(input, &mut p)
                || !take(input, &mut p, b')')
            {
                return input;
            }
        }
        space(input, &mut p);
    }
    if p == input.len() && !file.is_empty() {
        file
    } else {
        input
    }
}

fn regex_release_archive(input: &[u8]) -> &'static [u8] {
    // Priority is by archive family, not the first extension in the subject.
    for family in 0..3 {
        for i in 0..input.len() {
            if input[i] != b'.' {
                continue;
            }
            let s = &input[i + 1..];
            let boundary = |n: usize| !s.get(n).is_some_and(|b| is_pcre_word(*b));
            match family {
                0 if s.len() >= 2 && s[..2].eq_ignore_ascii_case(b"7z") => {
                    if boundary(2)
                        || s.len() >= 6
                            && s[2] == b'.'
                            && s[3..6].iter().all(u8::is_ascii_digit)
                            && boundary(6)
                    {
                        return b"7z";
                    }
                }
                1 => {
                    if s.len() >= 3 && s[..3].eq_ignore_ascii_case(b"rar") && boundary(3) {
                        return b"rar";
                    }
                    if s.first().is_some_and(|b| b.eq_ignore_ascii_case(&b'r')) {
                        for n in [4, 3] {
                            if s.len() >= n && s[1..n].iter().all(u8::is_ascii_digit) && boundary(n)
                            {
                                return b"rar";
                            }
                        }
                    }
                    if s.len() >= 5 && s[..4].eq_ignore_ascii_case(b"part") {
                        let n = 4 + s[4..].iter().take_while(|b| b.is_ascii_digit()).count();
                        if n > 4 && boundary(n) {
                            return b"rar";
                        }
                    }
                }
                2 if s.len() >= 3 && s[..3].eq_ignore_ascii_case(b"zip") => {
                    if boundary(3)
                        || s.get(3).is_some_and(|b| b.eq_ignore_ascii_case(&b'x')) && boundary(4)
                    {
                        return b"zip";
                    }
                }
                _ => {}
            }
        }
    }
    b"unknown"
}

fn regex_release_domain(input: &[u8]) -> Vec<u8> {
    let mut output = Vec::with_capacity(input.len());
    let mut i = 0;
    while i < input.len() {
        if input
            .get(i..i + 4)
            .is_some_and(|s| s.eq_ignore_ascii_case(b"www."))
        {
            let end = i
                + 4
                + input[i + 4..]
                    .iter()
                    .take_while(|b| !is_pcre_space(**b))
                    .count();
            let mut matched = None;
            for dot in (i + 5..end).rev() {
                if input[dot] == b'.' {
                    let n = input[dot + 1..end]
                        .iter()
                        .take(4)
                        .take_while(|b| b.is_ascii_alphabetic())
                        .count();
                    if n >= 2 {
                        matched = Some(dot + 1 + n);
                        break;
                    }
                }
            }
            if let Some(end) = matched {
                i = end;
                continue;
            }
        }
        output.push(input[i]);
        i += 1;
    }
    output
}

fn regex_release_frames(mut input: &[u8]) -> Option<Vec<&[u8]>> {
    let mut values = Vec::new();
    while !input.is_empty() {
        let len = u32::from_le_bytes(input.get(..4)?.try_into().ok()?) as usize;
        input = &input[4..];
        values.push(input.get(..len)?);
        input = &input[len..];
    }
    Some(values)
}

fn regex_release_transform(input: &[u8], mode: u32) -> Option<Vec<u8>> {
    // Keep pathological subjects on PHP's PCRE path, including its resource
    // failures. Batch limits apply per frame, not to the aggregate transport.
    if (mode != 0 && mode != 8 && input.len() > 8192) || (mode == 3 && input.len() > 4096) {
        return None;
    }
    // Conservative locale fallback. Raw framing bytes are not character data.
    if matches!(mode, 0 | 1 | 3 | 4 | 5) && !input.is_ascii() {
        return None;
    }
    Some(match mode {
        0 => {
            if input.is_empty() || input == b"0" {
                Vec::new()
            } else {
                [b"%", input, b"%"].concat()
            }
        }
        1 => regex_release_count(input).to_vec(),
        2 => {
            let values = regex_release_frames(input)?;
            if values.len() != 4 || !values[0].is_ascii() {
                return None;
            }
            let mut payload = [values[1], values[2], values[3]].concat();
            payload.extend_from_slice(regex_release_count(values[0]));
            regex_release_sha1_hex(&payload).to_vec()
        }
        3 => regex_release_display(input).to_vec(),
        4 => regex_release_archive(input).to_vec(),
        5 => regex_release_domain(input),
        6 | 7 => {
            let values = regex_release_frames(input)?;
            let allowed: &[&[u8]] = &[
                b"music", b"console", b"movie", b"game", b"tv", b"adult", b"book", b"misc",
            ];
            let mut result: Vec<Vec<u8>> = Vec::new();
            for value in values {
                if !value.is_ascii() || value.contains(&0) {
                    return None;
                }
                if mode == 7 {
                    if value != b"all" && !allowed.contains(&value) {
                        result.push(value.to_vec());
                    }
                    continue;
                }
                for token in value
                    .split(|b| is_pcre_space(*b) || *b == b',')
                    .filter(|v| !v.is_empty())
                {
                    let value = php_trim_ascii(token.rsplit(|b| *b == b'=').next()?);
                    let value: Vec<u8> = value.iter().map(u8::to_ascii_lowercase).collect();
                    let singular = value.as_slice();
                    let mut end = singular.len();
                    while end > 0 && singular[end - 1] == b's' {
                        end -= 1;
                    }
                    let normalized = if allowed.contains(&&singular[..end]) {
                        &singular[..end]
                    } else {
                        &value
                    };
                    // Collection::unique uses loose numeric comparison. Decline numeric
                    // tokens rather than reimplementing PHP float/coercion semantics.
                    if normalized.iter().any(u8::is_ascii_digit) {
                        return None;
                    }
                    if !normalized.is_empty()
                        && normalized != b"0"
                        && !result.iter().any(|v| v == normalized)
                    {
                        result.push(normalized.to_vec());
                    }
                }
            }
            // An empty invalid token cannot be represented by a NUL-separated result.
            if mode == 7 && result.iter().any(Vec::is_empty) {
                return None;
            }
            result.join(&0)
        }
        8 => {
            let mut output = Vec::new();
            for value in regex_release_frames(input)? {
                if !value.is_ascii() || value.len() > 4096 {
                    return None;
                }
                let value = regex_release_display(value);
                output.extend_from_slice(&u32::try_from(value.len()).ok()?.to_le_bytes());
                output.extend_from_slice(value);
            }
            output
        }
        _ => return None,
    })
}

/// Returns bytes written, -1 to request PHP fallback, or -(required+3) for
/// insufficient output capacity, without modifying the buffer on errors.
/// Modes: 0 group LIKE payload, 1 collection count, 2 framed collection hash,
/// 3 display wrapper, 4 archive family, 5 domain removal, 6 framed raw categories,
/// 7 framed normalized categories, 8 framed display batch. Frames use u32 LE
/// length plus bytes. Categories return NUL-separated tokens; display batches
/// return length-prefixed frames. Unsupported locale-sensitive bytes decline.
///
/// # Safety
/// Pointers must cover their stated lengths; output must not overlap input.
/// Null pointers are accepted only for zero length/capacity.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_regex_release_cleaning(
    input: *const u8,
    len: usize,
    mode: u32,
    out: *mut u8,
    cap: usize,
) -> isize {
    if cap > isize::MAX as usize || out.is_null() && cap != 0 {
        return -1;
    }
    let Some(input) = (unsafe { wave9_input(input, len) }) else {
        return -1;
    };
    let Some(output) = regex_release_transform(input, mode) else {
        return -1;
    };
    unsafe { wave9_write_output(&output, out, cap) }
}

#[cfg(test)]
mod wave12_regex_release_tests {
    use super::*;
    #[test]
    fn literal_contracts() {
        assert_eq!(regex_release_count(b"title [1/00012]"), b"00012");
        assert_eq!(regex_release_count(b"title (2_of_19)"), b"19");
        assert_eq!(regex_release_count(b"title (2/123456)"), b"0");
        assert_eq!(
            regex_release_display(b"[1/12] - \"file.mkv\" yEnc (1/50)"),
            b"file.mkv"
        );
        assert_eq!(regex_release_archive(b"a.zip b.RAR c.7z"), b"7z");
        assert_eq!(regex_release_domain(b"a www.example.com z"), b"a  z");
    }
    #[test]
    fn framed_categories_hash_and_batch_contracts() {
        fn frames(values: &[&[u8]]) -> Vec<u8> {
            let mut out = Vec::new();
            for value in values {
                out.extend_from_slice(&(value.len() as u32).to_le_bytes());
                out.extend_from_slice(value);
            }
            out
        }
        assert_eq!(
            regex_release_sha1_hex(b"abc").as_slice(),
            b"a9993e364706816aba3e25717850c26c9cd0d89d"
        );
        assert_eq!(
            regex_release_transform(&frames(&[b"TV,Movies", b"category=tv unknown"]), 6).unwrap(),
            b"tv\0movie\0unknown"
        );
        assert_eq!(
            regex_release_transform(&frames(&[b"tv", b"all", b"bad", b"bad"]), 7).unwrap(),
            b"bad\0bad"
        );
        assert!(regex_release_transform(&frames(&[b"00", b"0.0"]), 6).is_none());
        assert!(regex_release_transform(&frames(&[b""]), 7).is_none());
        assert_eq!(
            regex_release_transform(&frames(&[b"[1/2] \"a\"", b"b"]), 8).unwrap(),
            frames(&[b"a", b"b"])
        );
        assert!(regex_release_transform(&frames(&[b"short"]), 2).is_none());
        for mode in [6, 7, 8] {
            assert!(regex_release_transform(&[255, 255, 255, 255], mode).is_none());
        }
    }

    #[test]
    fn malformed_abi_and_short_buffers_never_write() {
        unsafe {
            let null = std::ptr::null();
            let mut out = [b'!'; 2];
            assert_eq!(
                nntmux_regex_release_cleaning(null, 1, 3, out.as_mut_ptr(), 2),
                -1
            );
            assert_eq!(
                nntmux_regex_release_cleaning(null, 0, 4, out.as_mut_ptr(), 2),
                -10
            );
            assert_eq!(
                nntmux_regex_release_cleaning(null, 0, 8, std::ptr::null_mut(), 0),
                0
            );
            assert_eq!(
                nntmux_regex_release_cleaning(b"abc".as_ptr(), 3, 6, out.as_mut_ptr(), 2),
                -1
            );
            assert_eq!(out, [b'!'; 2]);
        }
    }
}

fn regex_release_sha1_hex(data: &[u8]) -> [u8; 40] {
    const HEX_LOWER: &[u8; 16] = b"0123456789abcdef";
    #[inline(always)]
    fn compress(state: &mut [u32; 5], block: &[u8; 64]) {
        let mut w = [0u32; 80];
        for (i, &chunk) in block.as_chunks::<4>().0.iter().enumerate() {
            w[i] = u32::from_be_bytes(chunk);
        }
        for i in 16..80 {
            w[i] = (w[i - 3] ^ w[i - 8] ^ w[i - 14] ^ w[i - 16]).rotate_left(1);
        }
        let [mut a, mut b, mut c, mut d, mut e] = *state;
        for (i, &word) in w.iter().enumerate() {
            let (f, k) = match i {
                0..=19 => ((b & c) | ((!b) & d), 0x5a827999),
                20..=39 => (b ^ c ^ d, 0x6ed9eba1),
                40..=59 => ((b & c) | (b & d) | (c & d), 0x8f1bbcdc),
                _ => (b ^ c ^ d, 0xca62c1d6u32),
            };
            let temp = a
                .rotate_left(5)
                .wrapping_add(f)
                .wrapping_add(e)
                .wrapping_add(k)
                .wrapping_add(word);
            e = d;
            d = c;
            c = b.rotate_left(30);
            b = a;
            a = temp;
        }
        for (s, v) in state.iter_mut().zip([a, b, c, d, e]) {
            *s = s.wrapping_add(v);
        }
    }

    let mut state: [u32; 5] = [0x67452301, 0xefcdab89, 0x98badcfe, 0x10325476, 0xc3d2e1f0];
    let (blocks, remainder) = data.as_chunks::<64>();
    for block in blocks {
        compress(&mut state, block);
    }
    let mut tail = [0u8; 64];
    tail[..remainder.len()].copy_from_slice(remainder);
    tail[remainder.len()] = 0x80;
    if remainder.len() >= 56 {
        compress(&mut state, &tail);
        tail.fill(0);
    }
    tail[56..64].copy_from_slice(&((data.len() as u64).wrapping_mul(8)).to_be_bytes());
    compress(&mut state, &tail);

    let mut hex = [0u8; 40];
    for (i, &word) in state.iter().enumerate() {
        for (j, &byte) in word.to_be_bytes().iter().enumerate() {
            let idx = (i * 4 + j) * 2;
            hex[idx] = HEX_LOWER[(byte >> 4) as usize];
            hex[idx + 1] = HEX_LOWER[(byte & 0x0f) as usize];
        }
    }
    hex
}

#[cfg(test)]
mod wave13_maintenance_verification_tests {
    use super::*;

    fn frames(values: &[&[u8]]) -> Vec<u8> {
        let mut out = Vec::new();
        for value in values {
            out.extend_from_slice(&(value.len() as u32).to_le_bytes());
            out.extend_from_slice(value);
        }
        out
    }

    #[test]
    fn whitespace_and_criteria_keep_php_trim_boundaries() {
        assert_eq!(
            maintenance_verification_transform(b"\0 \ta\x0c\x0b b\r\n", 0),
            Ok(b"a b".to_vec())
        );
        assert_eq!(
            maintenance_verification_transform(b"\x0c x\x0c", 0),
            Ok(b" x ".to_vec())
        );
        assert_eq!(maintenance_verification_transform(b"\xff", 0), Err(-1));
        assert_eq!(
            maintenance_verification_transform(b"\0 column\t= equals =\"' x '\"", 1),
            Ok(frames(&[b"column", b"equals", b" x "]))
        );
        assert_eq!(maintenance_verification_transform(b"a=b=c=d", 1), Err(-3));
        assert_eq!(
            maintenance_verification_transform(b"==", 1),
            Ok(frames(&[b"", b"", b""]))
        );
    }

    #[test]
    fn byte_format_and_integer_limits() {
        for (input, expected) in [
            (b"-1".as_slice(), b"-1 B".as_slice()),
            (b"1023", b"1023 B"),
            (b"1024", b"1.00 KiB"),
            (b"1536", b"1.50 KiB"),
            (b"1099511627776", b"1.00 TiB"),
            (b"9223372036854775807", b"8388608.00 TiB"),
        ] {
            assert_eq!(
                maintenance_verification_transform(input, 2),
                Ok(expected.to_vec())
            );
        }
        assert_eq!(
            maintenance_verification_transform(b"9223372036854775808", 2),
            Err(-1)
        );
        for (input, expected) in [
            (b" -1 \n".as_slice(), b"-1".as_slice()),
            (b"+002K", b"2048"),
            (b"-2m", b"-2097152"),
            (b"8G", b"8589934592"),
            (b"-9223372036854775808", b"-9223372036854775808"),
        ] {
            assert_eq!(
                maintenance_verification_transform(input, 11),
                Ok(expected.to_vec())
            );
        }
        for input in [
            b"".as_slice(),
            b"1.5G",
            b"1e3",
            b"2MB",
            b"9223372036854775807k",
            b"9223372036854775808",
            b"k",
        ] {
            assert_eq!(maintenance_verification_transform(input, 11), Err(-1));
        }
    }

    #[test]
    fn list_operations_preserve_left_order_duplicates_and_strict_bytes() {
        let members = [
            b"intersect".as_slice(),
            b"4",
            b"b",
            b"a",
            b"b",
            b"01",
            b"b",
            b"1",
        ];
        assert_eq!(
            maintenance_verification_transform(&frames(&members), 3),
            Ok(frames(&[b"b", b"b"]))
        );
        let mut diff = members;
        diff[0] = b"diff";
        assert_eq!(
            maintenance_verification_transform(&frames(&diff), 3),
            Ok(frames(&[b"a", b"01"]))
        );
        assert_eq!(
            maintenance_verification_transform(
                &frames(&[b"4", b"php", b"js", b"php", b"PHP", b"php", b"js"]),
                8
            ),
            Ok(frames(&[b"php", b"js"]))
        );
    }

    #[test]
    fn path_classification_and_stems_match_literal_and_pcre_suffixes() {
        assert_eq!(
            maintenance_verification_transform(
                &frames(&[
                    b"resources/views/x.blade.php",
                    b"app/Mcp/A.php",
                    b"tests/FooTest.php",
                    b"config/nntmux-mcp.php",
                    b"vite.config.js",
                    b"foo.PHP",
                    b"tests/fixture.txt",
                    b"outside/ExampleTest.php"
                ]),
                4
            ),
            Ok(vec![2, 5, 9, 5, 2, 0, 0, 1])
        );
        assert_eq!(
            maintenance_verification_transform(
                &frames(&[
                    b"tests/FooTest.php",
                    b"other/Foo.php",
                    b"x/ZeroTest.php\n",
                    b"x/Test.php",
                    b"x/0.php",
                    b"bar/Baz.php///",
                    b"Foo.php\r\n",
                    b"a\\BTest.php"
                ]),
                5
            ),
            Ok(frames(&[
                b"Foo",
                b"Zero\n",
                b"Baz",
                b"Foo.php\r\n",
                b"a\\B"
            ]))
        );
        assert_eq!(
            maintenance_verification_transform(&frames(&[b"/vendor/bin///", b"phpunit"]), 6),
            Ok(b"/vendor/bin/phpunit".to_vec())
        );
    }

    #[test]
    fn filter_accepts_one_final_newline_and_workspace_prefers_group() {
        for input in [b"Foo\\Bar::test-a_1.php".as_slice(), b"x\n"] {
            assert_eq!(
                maintenance_verification_transform(input, 7),
                Ok(b"1".to_vec())
            );
        }
        for input in [b"".as_slice(), b"\n", b"x\n\n", b"x y", b"x\r\n", b"\xff"] {
            assert_eq!(
                maintenance_verification_transform(input, 7),
                Ok(b"0".to_vec())
            );
        }
        assert_eq!(
            maintenance_verification_transform(&frames(&[b"/tmp", b"a", b"12", b"abc"]), 9),
            Ok(
                b"/tmp/12/ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad/"
                    .to_vec()
            )
        );
        assert_eq!(
            maintenance_verification_transform(&frames(&[b"C:\\tmp\\", b"0", b"", b""]), 9),
            Ok(b"C:\\tmp\\0/".to_vec())
        );
    }

    #[test]
    fn meminfo_first_valid_unanchored_capture_and_overflow_fallback() {
        assert_eq!(
            maintenance_verification_transform(
                b"xMemTotal:no MemTotal:\x0c 000123 kB\nMemAvailable:\n42\nMemTotal: 999",
                10
            ),
            Ok(frames(&[b"123", b"42"]))
        );
        assert_eq!(
            maintenance_verification_transform(b"MemTotal: 42", 10),
            Err(-3)
        );
        assert_eq!(
            maintenance_verification_transform(b"MemTotal: 0\nMemAvailable: 1", 10),
            Err(-1)
        );
        assert_eq!(
            maintenance_verification_transform(
                b"MemTotal: 9223372036854775808\nMemAvailable: 1",
                10
            ),
            Err(-1)
        );
    }

    #[test]
    fn rejects_malformed_frames_limits_and_unknown_modes() {
        for mode in [3, 4, 5, 6, 8, 9] {
            assert_eq!(
                maintenance_verification_transform(b"\x01\0\0\0", mode),
                Err(-1)
            );
            assert_eq!(
                maintenance_verification_transform(&vec![0; 4 * 4097], mode),
                Err(-1)
            );
        }
        assert_eq!(
            maintenance_verification_transform(&frames(&[b"diff", b"9", b"x"]), 3),
            Err(-1)
        );
        assert_eq!(
            maintenance_verification_transform(&frames(&[b"9000000000000000000000"]), 8),
            Err(-1)
        );
        assert_eq!(maintenance_verification_transform(b"", 12), Err(-1));
        assert_eq!(
            maintenance_verification_transform(&vec![b'a'; 1_048_577], 0),
            Err(-1)
        );
    }

    #[test]
    fn ffi_sentinels_null_pointers_and_capacity_do_not_write() {
        unsafe {
            let mut out = [b'!'; 32];
            assert_eq!(
                nntmux_maintenance_verification(std::ptr::null(), 1, 0, out.as_mut_ptr(), 32),
                -1
            );
            assert_eq!(
                nntmux_maintenance_verification(b"abc".as_ptr(), 3, 0, std::ptr::null_mut(), 1),
                -1
            );
            assert_eq!(
                nntmux_maintenance_verification(b"abc".as_ptr(), 3, 0, out.as_mut_ptr(), 2),
                -6
            );
            assert_eq!(
                nntmux_maintenance_verification(b"bad".as_ptr(), 3, 1, out.as_mut_ptr(), 32),
                -3
            );
            assert_eq!(out, [b'!'; 32]);
            assert_eq!(
                nntmux_maintenance_verification(std::ptr::null(), 0, 0, std::ptr::null_mut(), 0),
                0
            );
            assert_eq!(
                nntmux_maintenance_verification(b"abc".as_ptr(), 3, 0, out.as_mut_ptr(), 32),
                3
            );
            assert_eq!(&out[..4], b"abc!");
        }
    }
}

// Wave 13 transports are bounded independently of the caller's output buffer.
const MAINTENANCE_VERIFICATION_MAX_INPUT: usize = 1_048_576;
const MAINTENANCE_VERIFICATION_MAX_FRAMES: usize = 4096;

fn maintenance_verification_frames(input: &[u8]) -> Result<Vec<&[u8]>, isize> {
    // Check the frame count before allocating the shared frame decoder's vector.
    let mut remaining = input;
    let mut count = 0;
    while !remaining.is_empty() {
        count += 1;
        if count > MAINTENANCE_VERIFICATION_MAX_FRAMES {
            return Err(-1);
        }
        let header = remaining.get(..4).ok_or(-1isize)?;
        let len = u32::from_le_bytes(header.try_into().map_err(|_| -1isize)?) as usize;
        remaining = remaining
            .get(4..)
            .and_then(|v| v.get(len..))
            .ok_or(-1isize)?;
    }
    regex_release_frames(input).ok_or(-1)
}

fn maintenance_verification_push_frame(output: &mut Vec<u8>, value: &[u8]) {
    // Every source frame is bounded by the 1 MiB aggregate input limit.
    output.extend_from_slice(&(value.len() as u32).to_le_bytes());
    output.extend_from_slice(value);
}

fn maintenance_verification_integer(input: &[u8]) -> Result<i64, isize> {
    let digits = if matches!(input.first(), Some(b'+' | b'-')) {
        &input[1..]
    } else {
        input
    };
    if digits.is_empty() || !digits.iter().all(u8::is_ascii_digit) {
        return Err(-1);
    }
    std::str::from_utf8(input)
        .map_err(|_| -1isize)?
        .parse()
        .map_err(|_| -1)
}

fn maintenance_verification_count(input: &[u8]) -> Result<usize, isize> {
    if input.is_empty() || !input.iter().all(u8::is_ascii_digit) {
        return Err(-1);
    }
    std::str::from_utf8(input)
        .map_err(|_| -1isize)?
        .parse()
        .map_err(|_| -1)
}

fn maintenance_verification_meminfo_capture<'a>(
    input: &'a [u8],
    prefix: &[u8],
) -> Option<&'a [u8]> {
    for (start, _) in input
        .windows(prefix.len())
        .enumerate()
        .filter(|(_, v)| *v == prefix)
    {
        let rest = &input[start + prefix.len()..];
        let spaces = rest.iter().take_while(|b| is_pcre_space(**b)).count();
        if spaces == 0 {
            continue;
        }
        let digits = rest[spaces..]
            .iter()
            .take_while(|b| b.is_ascii_digit())
            .count();
        if digits != 0 {
            return Some(&rest[spaces..spaces + digits]);
        }
    }
    None
}

fn maintenance_verification_transform(input: &[u8], mode: u32) -> Result<Vec<u8>, isize> {
    if input.len() > MAINTENANCE_VERIFICATION_MAX_INPUT {
        return Err(-1);
    }
    let mut output = Vec::new();
    match mode {
        0 => {
            if !input.is_ascii() {
                return Err(-1);
            }
            let mut in_space = false;
            for &byte in php_trim_ascii(input) {
                if is_pcre_space(byte) {
                    if !in_space {
                        output.push(b' ');
                    }
                    in_space = true;
                } else {
                    output.push(byte);
                    in_space = false;
                }
            }
        }
        1 => {
            let mut parts = input.split(|&b| b == b'=');
            let column = parts.next().ok_or(-3isize)?;
            let modifier = parts.next().ok_or(-3isize)?;
            let value = parts.next().ok_or(-3isize)?;
            if parts.next().is_some() {
                return Err(-3);
            }
            maintenance_verification_push_frame(&mut output, php_trim_ascii(column));
            maintenance_verification_push_frame(&mut output, php_trim_ascii(modifier));
            // PHP's explicit quote charlist does not trim whitespace.
            let start = value
                .iter()
                .take_while(|&&b| matches!(b, b'\'' | b'"'))
                .count();
            let end = value[start..]
                .iter()
                .rposition(|&b| !matches!(b, b'\'' | b'"'))
                .map_or(start, |p| start + p + 1);
            maintenance_verification_push_frame(&mut output, &value[start..end]);
        }
        2 => {
            let bytes = maintenance_verification_integer(input)?;
            let mut value = bytes as f64;
            let units = ["B", "KiB", "MiB", "GiB", "TiB"];
            let mut unit = 0;
            while value >= 1024.0 && unit < units.len() - 1 {
                value /= 1024.0;
                unit += 1;
            }
            output = if unit == 0 {
                format!("{bytes} B").into_bytes()
            } else {
                format!("{value:.2} {}", units[unit]).into_bytes()
            };
        }
        3 | 8 => {
            let values = maintenance_verification_frames(input)?;
            let offset = if mode == 3 { 2 } else { 1 };
            if values.len() < offset {
                return Err(-1);
            }
            let intersect = if mode == 3 {
                match values[0] {
                    b"intersect" => true,
                    b"diff" => false,
                    _ => return Err(-1),
                }
            } else {
                true
            };
            let count = maintenance_verification_count(values[offset - 1])?;
            if count > values.len() - offset {
                return Err(-1);
            }
            let left = &values[offset..offset + count];
            let right: std::collections::HashSet<&[u8]> =
                values[offset + count..].iter().copied().collect();
            let mut seen = std::collections::HashSet::new();
            for &value in left {
                if right.contains(value) == intersect && (mode == 3 || seen.insert(value)) {
                    maintenance_verification_push_frame(&mut output, value);
                }
            }
        }
        4 => {
            for path in maintenance_verification_frames(input)? {
                let php = path.ends_with(b".php") && !path.ends_with(b".blade.php");
                let frontend = [
                    b"resources/views/".as_slice(),
                    b"resources/js/",
                    b"resources/css/",
                ]
                .iter()
                .any(|prefix| path.starts_with(prefix))
                    || matches!(path, b"package.json" | b"vite.config.js");
                let mcp = path.starts_with(b"app/Mcp/")
                    || path.starts_with(b"app/Services/Mcp/")
                    || matches!(path, b"config/nntmux-mcp.php" | b"routes/ai.php");
                let tests = path.starts_with(b"tests/") && path.ends_with(b"Test.php");
                output.push(
                    u8::from(php)
                        | (u8::from(frontend) << 1)
                        | (u8::from(mcp) << 2)
                        | (u8::from(tests) << 3),
                );
            }
        }
        5 => {
            let mut seen = std::collections::HashSet::new();
            for path in maintenance_verification_frames(input)? {
                // basename is locale-sensitive for multibyte bytes on PHP.
                if !path.is_ascii() {
                    return Err(-1);
                }
                let end = path.iter().rposition(|&b| b != b'/').map_or(0, |i| i + 1);
                let basename = path[..end]
                    .rsplit(|&b| b == b'/')
                    .next()
                    .unwrap_or_default();
                let suffix_end = basename.len() - usize::from(basename.ends_with(b"\n"));
                let before_newline = &basename[..suffix_end];
                let strip = if before_newline.ends_with(b"Test.php") {
                    8
                } else if before_newline.ends_with(b".php") {
                    4
                } else {
                    0
                };
                let stem = if strip != 0 {
                    [&basename[..suffix_end - strip], &basename[suffix_end..]].concat()
                } else {
                    basename.to_vec()
                };
                if !stem.is_empty() && stem != b"0" && seen.insert(stem.clone()) {
                    maintenance_verification_push_frame(&mut output, &stem);
                }
            }
        }
        6 => {
            let values = maintenance_verification_frames(input)?;
            if values.len() != 2 {
                return Err(-1);
            }
            let end = values[0]
                .iter()
                .rposition(|&b| b != b'/')
                .map_or(0, |i| i + 1);
            output.extend_from_slice(&values[0][..end]);
            output.push(b'/');
            output.extend_from_slice(values[1]);
        }
        7 => {
            let input = input.strip_suffix(b"\n").unwrap_or(input);
            let valid = !input.is_empty()
                && input.iter().all(|b| {
                    b.is_ascii_alphanumeric() || matches!(b, b'_' | b'.' | b':' | b'-' | b'\\')
                });
            output.push(if valid { b'1' } else { b'0' });
        }
        9 => {
            let values = maintenance_verification_frames(input)?;
            if values.len() != 4 {
                return Err(-1);
            }
            output.extend_from_slice(values[0]);
            if !matches!(output.last(), Some(b'/' | b'\\')) {
                output.push(b'/');
            }
            let namespace = if values[2].is_empty() {
                values[1]
            } else {
                values[2]
            };
            if !namespace.is_empty() {
                output.extend_from_slice(namespace);
                output.push(b'/');
            }
            if !values[3].is_empty() {
                output.extend_from_slice(&crate::predb::sha256_hex_slice(values[3]));
                output.push(b'/');
            }
        }
        10 => {
            if !input.is_ascii() {
                return Err(-1);
            }
            let total = maintenance_verification_meminfo_capture(input, b"MemTotal:");
            let available = maintenance_verification_meminfo_capture(input, b"MemAvailable:");
            let (Some(total), Some(available)) = (total, available) else {
                return Err(-3);
            };
            let total = maintenance_verification_integer(total)?;
            let available = maintenance_verification_integer(available)?;
            if total == 0 {
                return Err(-1);
            }
            maintenance_verification_push_frame(&mut output, total.to_string().as_bytes());
            maintenance_verification_push_frame(&mut output, available.to_string().as_bytes());
        }
        11 => {
            let input = php_trim_ascii(input);
            let (integer, multiplier) = match input.last() {
                Some(b'k' | b'K') => (&input[..input.len() - 1], 1024i64),
                Some(b'm' | b'M') => (&input[..input.len() - 1], 1024i64.pow(2)),
                Some(b'g' | b'G') => (&input[..input.len() - 1], 1024i64.pow(3)),
                _ => (input, 1),
            };
            let value = maintenance_verification_integer(integer)?
                .checked_mul(multiplier)
                .ok_or(-1isize)?;
            output.extend_from_slice(value.to_string().as_bytes());
        }
        _ => return Err(-1),
    }
    Ok(output)
}

/// Maintenance and verification byte transforms. Returns bytes written, -1 to
/// request PHP fallback, -3 for malformed criteria or missing meminfo fields,
/// or -(required+3) for insufficient capacity. Errors never modify output.
/// Modes 0..11 cover whitespace, criteria, byte formatting, index comparison,
/// path classification, test stems, vendor binary paths, test filters, targeted
/// checks, workspace paths, meminfo fields, and PHP memory limits respectively.
/// Lists use u32 little-endian byte lengths; input is limited to 1 MiB and 4096
/// frames. Locale-sensitive or numerically unsupported inputs request fallback.
///
/// # Safety
/// Pointers must cover their stated lengths. Null pointers require zero lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_maintenance_verification(
    input: *const u8,
    len: usize,
    mode: u32,
    out: *mut u8,
    cap: usize,
) -> isize {
    if len > MAINTENANCE_VERIFICATION_MAX_INPUT
        || cap > isize::MAX as usize
        || out.is_null() && cap != 0
    {
        return -1;
    }
    let Some(input) = (unsafe { wave9_input(input, len) }) else {
        return -1;
    };
    let output = match maintenance_verification_transform(input, mode) {
        Ok(output) => output,
        Err(code) => return code,
    };
    unsafe { wave9_write_output(&output, out, cap) }
}

// ============================================================================
// Wave 14 Slice 6: Fingerprint Breaker, Verification/Forwarder Diagnostics,
// SizeUnit, SqlError, and Passkey RelyingPartyIdResolver
// ============================================================================

#[inline]
fn php_round_half_up_to_int(value: f64) -> f64 {
    if value >= 0.0 {
        (value + 0.5).floor()
    } else {
        (value - 0.5).ceil()
    }
}

/// Exact implementation of PHP's `_php_math_round(value, places, PHP_ROUND_HALF_UP)`.
#[inline]
fn php_math_round(value: f64, places: i32) -> f64 {
    if !value.is_finite() || value == 0.0 {
        return value;
    }
    let places = places.clamp(-308, 308);
    let f1 = 10f64.powi(places.abs());
    let mut tmp = if places >= 0 { value * f1 } else { value / f1 };
    let precision_places = 14 - (value.abs().log10().floor() as i32);
    if precision_places > places && precision_places - 15 < places {
        let f2 = 10f64.powi((precision_places - places).abs());
        tmp = if precision_places >= places {
            tmp * f2
        } else {
            tmp / f2
        };
        tmp = php_round_half_up_to_int(tmp);
        tmp = if precision_places >= places {
            tmp / f2
        } else {
            tmp * f2
        };
    }
    tmp = php_round_half_up_to_int(tmp);
    if tmp.abs() < 1e300 {
        if places >= 0 { tmp / f1 } else { tmp * f1 }
    } else {
        value
    }
}

/// Format `FingerprintProviderBreaker::key`:
/// - `tls == 0` / `tls == 1`: `'release-fingerprint:provider-breaker:'.sha1(strtolower($name).'|'.$port.'|'.($tls ? 'tls' : 'plain')).':'.$state.($generation === '' ? '' : ':'.$generation)`
/// - `tls == 2`: `'releases:fingerprint:provider:'.sha1($name).':'.$state.($generation === '' ? '' : ':'.$generation)`
///
/// Returns bytes written, `-1` on invalid arguments, or `-(required + 3)` when `out_cap` is too small.
///
/// # Safety
/// Non-null pointers must be valid for their stated lengths and must not overlap `out`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_fingerprint_breaker_key(
    name: *const u8,
    name_len: usize,
    port: *const u8,
    port_len: usize,
    tls: u8,
    state: *const u8,
    state_len: usize,
    generation: *const u8,
    generation_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_cap > isize::MAX as usize || (out.is_null() && out_cap != 0) || tls > 2 {
            return -1;
        }
        let (Some(name), Some(port), Some(state), Some(generation)) = (unsafe {
            (
                wave9_input(name, name_len),
                wave9_input(port, port_len),
                wave9_input(state, state_len),
                wave9_input(generation, generation_len),
            )
        }) else {
            return -1;
        };
        let (prefix, digest) = if tls == 2 {
            (
                b"releases:fingerprint:provider:".as_slice(),
                regex_release_sha1_hex(name),
            )
        } else {
            let tls_label: &[u8] = if tls != 0 { b"tls" } else { b"plain" };
            let mut raw = Vec::with_capacity(name.len() + 1 + port.len() + 1 + tls_label.len());
            raw.extend(name.iter().map(u8::to_ascii_lowercase));
            raw.push(b'|');
            raw.extend_from_slice(port);
            raw.push(b'|');
            raw.extend_from_slice(tls_label);
            (
                b"release-fingerprint:provider-breaker:".as_slice(),
                regex_release_sha1_hex(&raw),
            )
        };
        let extra_gen = if generation.is_empty() {
            0
        } else {
            1 + generation.len()
        };
        let total_len = prefix.len() + 40 + 1 + state.len() + extra_gen;
        let mut output = Vec::with_capacity(total_len);
        output.extend_from_slice(prefix);
        output.extend_from_slice(&digest);
        output.push(b':');
        output.extend_from_slice(state);
        if !generation.is_empty() {
            output.push(b':');
            output.extend_from_slice(generation);
        }
        unsafe { wave9_write_output(&output, out, out_cap) }
    }))
    .unwrap_or(-1)
}

/// Compute `FingerprintProviderBreaker::suspectSeconds`:
/// - When `cooldown_seconds <= 0`: `max(3600, window_seconds * 4)`
/// - When `cooldown_seconds > 0`: `min(cooldown_seconds, max(30, window_seconds))`
///
/// Returns `-1` on arithmetic overflow.
///
/// # Safety
/// Safe for any integer arguments.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_fingerprint_breaker_suspect_seconds(
    window_seconds: i64,
    cooldown_seconds: i64,
) -> i64 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if cooldown_seconds > 0 {
            cooldown_seconds.min(window_seconds.max(30))
        } else {
            window_seconds
                .checked_mul(4)
                .map_or(-1, |scaled| scaled.max(3600))
        }
    }))
    .unwrap_or(-1)
}

/// Evaluate `VerificationRunDiagnostics::stop(reason, stopped)`.
/// Returns a packed bitmask (`>= 0`):
/// - bits `0..=3`: canonical reason index (`0..=8`)
/// - bit `4` (`0x10`): `limitReached` (`claims_admitted >= limit`)
/// - bit `5` (`0x20`): `deadlineReached` (`has_loop_started != 0 && max_seconds > 0 && (stopped - loop_started) / 1e9 >= max_seconds`)
///
/// Returns `-1` on invalid pointer/length.
///
/// # Safety
/// Non-null `reason` must be readable for `reason_len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_verification_diagnostics_stop(
    reason: *const u8,
    reason_len: usize,
    stopped: i64,
    claims_admitted: i64,
    limit: i64,
    has_loop_started: u8,
    loop_started: i64,
    max_seconds: i64,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(reason_bytes) = (unsafe { wave9_input(reason, reason_len) }) else {
            return -1;
        };
        let reason_idx: i32 = match reason_bytes {
            b"limit" => 0,
            b"deadline" => 1,
            b"no_claim" => 2,
            b"draining" => 3,
            b"worker_unavailable" => 4,
            b"initialization_unavailable" => 5,
            b"initialization_error" => 6,
            b"unsafe_scratch_root" => 7,
            b"error" => 8,
            _ => 8,
        };
        let limit_reached = claims_admitted >= limit;
        let deadline_reached = has_loop_started != 0
            && max_seconds > 0
            && ((stopped.wrapping_sub(loop_started) as f64) / 1e9) >= (max_seconds as f64);
        reason_idx | (i32::from(limit_reached) << 4) | (i32::from(deadline_reached) << 5)
    }))
    .unwrap_or(-1)
}

/// Compute `VerificationRunDiagnostics::report` durations and 3-decimal rounded phase milliseconds.
/// `input` must be 80 bytes (10 native-endian `f64` values: `outbox_wait_ms` followed by 9 phase `ms` values).
/// Writes 96 bytes (12 native-endian `f64` values: `rounded_outbox_wait_ms`, `total_ms`, `claim_loop_ms`,
/// and 9 rounded phase `ms` values) into `out`.
///
/// # Safety
/// `input` must be readable for `input_len` bytes and `out` writable for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_verification_diagnostics_round_metrics(
    input: *const u8,
    input_len: usize,
    started: i64,
    finished: i64,
    has_loop_started: u8,
    loop_started: i64,
    has_stopped: u8,
    stopped: i64,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if input_len != 80 || out_cap > isize::MAX as usize || (out.is_null() && out_cap != 0) {
            return -1;
        }
        let Some(in_bytes) = (unsafe { wave9_input(input, input_len) }) else {
            return -1;
        };
        let mut vals = [0.0f64; 10];
        for (i, &chunk) in in_bytes.as_chunks::<8>().0.iter().enumerate() {
            vals[i] = f64::from_ne_bytes(chunk);
        }
        let end_tick = if has_stopped != 0 { stopped } else { finished };
        let claim_loop_ms = if has_loop_started != 0 {
            (end_tick.wrapping_sub(loop_started) as f64) / 1_000_000.0
        } else {
            0.0
        };
        let mut out_vals = [0.0f64; 12];
        out_vals[0] = php_math_round(vals[0], 3);
        out_vals[1] = (finished.wrapping_sub(started) as f64) / 1_000_000.0;
        out_vals[2] = claim_loop_ms;
        for i in 0..9 {
            out_vals[3 + i] = php_math_round(vals[1 + i], 3);
        }
        let mut out_bytes = [0u8; 96];
        for (i, v) in out_vals.iter().enumerate() {
            out_bytes[i * 8..(i + 1) * 8].copy_from_slice(&v.to_ne_bytes());
        }
        unsafe { wave9_write_output(&out_bytes, out, out_cap) }
    }))
    .unwrap_or(-1)
}

/// Classify `PublicationForwarderDiagnostics::stop` (`mode == 0`) or `outcome` (`mode == 1`).
/// Returns canonical index (`0..=7` for `mode == 0`, `0..=11` for `mode == 1`), or `-1` on invalid input.
///
/// # Safety
/// Non-null `label` must be readable for `label_len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_publication_forwarder_classify(
    label: *const u8,
    label_len: usize,
    mode: u32,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(bytes) = (unsafe { wave9_input(label, label_len) }) else {
            return -1;
        };
        match mode {
            0 => match bytes {
                b"deadline" => 0,
                b"passes" => 1,
                b"terminated" => 2,
                b"locks_lost" => 3,
                b"worker_unavailable" => 4,
                b"disabled" => 5,
                b"slot_unavailable" => 6,
                b"error" => 7,
                _ => 7,
            },
            1 => match bytes {
                b"published" => 0,
                b"filtered" => 1,
                b"metadata_review" => 2,
                b"deferred" => 3,
                b"stale" => 4,
                b"invalid" => 5,
                b"error" => 6,
                b"paused" => 7,
                b"retry" => 8,
                b"transport" => 9,
                b"no_release" => 10,
                _ => 11,
            },
            _ => -1,
        }
    }))
    .unwrap_or(-1)
}

/// Compute `PublicationForwarderDiagnostics::report` rounded float metrics.
/// Writes 48 bytes (6 native-endian `f64` values: `items_per_batch_mean`, `linger_total_ms`,
/// `round_trip_total_ms`, `round_trip_max_ms`, `round_trip_mean_ms`, `duration_ms`) into `out`.
///
/// # Safety
/// `out` must be writable for `out_cap` bytes when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_publication_forwarder_metrics(
    batches: i64,
    items: i64,
    linger_ms: f64,
    round_trip_ms: f64,
    slowest_round_trip_ms: f64,
    started: i64,
    finished: i64,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_cap > isize::MAX as usize || (out.is_null() && out_cap != 0) {
            return -1;
        }
        let items_mean = if batches == 0 {
            0.0
        } else {
            php_math_round((items as f64) / (batches as f64), 2)
        };
        let rt_mean = if batches == 0 {
            0.0
        } else {
            php_math_round(round_trip_ms / (batches as f64), 3)
        };
        let vals = [
            items_mean,
            php_math_round(linger_ms, 3),
            php_math_round(round_trip_ms, 3),
            php_math_round(slowest_round_trip_ms, 3),
            rt_mean,
            php_math_round((finished.wrapping_sub(started) as f64) / 1_000_000.0, 3),
        ];
        let mut out_bytes = [0u8; 48];
        for (i, v) in vals.iter().enumerate() {
            out_bytes[i * 8..(i + 1) * 8].copy_from_slice(&v.to_ne_bytes());
        }
        unsafe { wave9_write_output(&out_bytes, out, out_cap) }
    }))
    .unwrap_or(-1)
}

fn size_unit_multiplier(unit: &[u8], allow_extended: bool) -> Option<i64> {
    if unit.is_empty() || unit.len() > 2 {
        return None;
    }
    let mut upper = [0u8; 2];
    for (i, &b) in unit.iter().enumerate() {
        upper[i] = b.to_ascii_uppercase();
    }
    match &upper[..unit.len()] {
        b"MB" => Some(1_048_576),
        b"GB" => Some(1_073_741_824),
        b"B" if allow_extended => Some(1),
        b"K" | b"KB" if allow_extended => Some(1_024),
        b"M" if allow_extended => Some(1_048_576),
        b"G" if allow_extended => Some(1_073_741_824),
        b"T" | b"TB" if allow_extended => Some(1_099_511_627_776),
        _ => None,
    }
}

fn parse_trimmed_ascii_f64(mut value: &[u8]) -> Option<f64> {
    while value.first().is_some_and(|b| is_pcre_space(*b)) {
        value = &value[1..];
    }
    while value.last().is_some_and(|b| is_pcre_space(*b)) {
        value = &value[..value.len() - 1];
    }
    let s = std::str::from_utf8(value).ok()?;
    let parsed = s.parse::<f64>().ok()?;
    if parsed.is_finite() {
        Some(parsed)
    } else {
        None
    }
}

/// Binary power-of-1024 conversions for `SizeUnit`:
/// - `mode == 0`: `SizeUnit::toBytes` (`MB` / `GB` strict; returns `-2` for unsupported units, decimal bytes in `out`)
/// - `mode == 1`: `SizeUnit::fromBytes` (writes `"I:<int>:<unit>"` or `"F:<8-byte-f64>:MB"` into `out`)
/// - `mode == 2`: Extended binary unit `toBytes` for `B|K|KB|M|MB|G|GB|T|TB` (returns `-2` for unsupported units)
///
/// Returns bytes written (`> 0`), `-2` for unsupported unit, `-1` to decline to PHP, or `-(required + 3)` for capacity.
///
/// # Safety
/// Non-null pointers must cover their stated lengths and must not overlap `out`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_size_unit_convert(
    value_str: *const u8,
    value_len: usize,
    unit_str: *const u8,
    unit_len: usize,
    mode: u32,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_cap > isize::MAX as usize || (out.is_null() && out_cap != 0) {
            return -1;
        }
        let (Some(value), Some(unit)) = (unsafe {
            (
                wave9_input(value_str, value_len),
                wave9_input(unit_str, unit_len),
            )
        }) else {
            return -1;
        };
        match mode {
            0 | 2 => {
                let Some(multiplier) = size_unit_multiplier(unit, mode == 2) else {
                    return -2;
                };
                if value.is_empty() || !remover_is_numeric(value) {
                    return unsafe { wave9_write_output(b"0", out, out_cap) };
                }
                let Some(num) = parse_trimmed_ascii_f64(value) else {
                    return -1;
                };
                let rounded = php_math_round(num * (multiplier as f64), 0);
                if !rounded.is_finite() || rounded >= (i64::MAX as f64) {
                    return -1;
                }
                let bytes = if rounded <= 0.0 { 0i64 } else { rounded as i64 };
                let formatted = bytes.to_string();
                unsafe { wave9_write_output(formatted.as_bytes(), out, out_cap) }
            }
            1 => {
                if value.is_empty() || !remover_is_numeric(value) {
                    return unsafe { wave9_write_output(b"I:0:MB", out, out_cap) };
                }
                let mut trimmed = value;
                while trimmed.first().is_some_and(|b| is_pcre_space(*b)) {
                    trimmed = &trimmed[1..];
                }
                while trimmed.last().is_some_and(|b| is_pcre_space(*b)) {
                    trimmed = &trimmed[..trimmed.len() - 1];
                }
                let bytes: i64 =
                    if let Ok(exact) = std::str::from_utf8(trimmed).unwrap_or("").parse::<i64>() {
                        exact
                    } else {
                        let Some(num) = parse_trimmed_ascii_f64(trimmed) else {
                            return -1;
                        };
                        if num >= (i64::MAX as f64) || num < (i64::MIN as f64) {
                            return -1;
                        }
                        num.trunc() as i64
                    };
                if bytes <= 0 {
                    return unsafe { wave9_write_output(b"I:0:MB", out, out_cap) };
                }
                if bytes % 1_073_741_824 == 0 {
                    let s = format!("I:{}:GB", bytes / 1_073_741_824);
                    return unsafe { wave9_write_output(s.as_bytes(), out, out_cap) };
                }
                if bytes % 1_048_576 == 0 {
                    let s = format!("I:{}:MB", bytes / 1_048_576);
                    return unsafe { wave9_write_output(s.as_bytes(), out, out_cap) };
                }
                let mb_float = php_math_round((bytes as f64) / 1_048_576.0, 2);
                let mut buf = [0u8; 13];
                buf[0] = b'F';
                buf[1] = b':';
                buf[2..10].copy_from_slice(&mb_float.to_ne_bytes());
                buf[10] = b':';
                buf[11] = b'M';
                buf[12] = b'B';
                unsafe { wave9_write_output(&buf, out, out_cap) }
            }
            _ => -1,
        }
    }))
    .unwrap_or(-1)
}

fn sql_error_truncate_utf8(message: &[u8], out: &mut Vec<u8>) -> bool {
    let Ok(s) = std::str::from_utf8(message) else {
        return false;
    };
    let mut char_count = 0usize;
    let mut cut_byte = s.len();
    for (byte_idx, _) in s.char_indices() {
        if char_count == 500 {
            cut_byte = byte_idx;
        }
        char_count += 1;
        if char_count > 500 {
            break;
        }
    }
    if char_count > 500 {
        out.extend_from_slice(&message[..cut_byte]);
        out.extend_from_slice("…".as_bytes());
    } else {
        out.extend_from_slice(message);
    }
    true
}

/// Classify transient SQL locks and format concise error descriptions matching `SqlError::describe`
/// and `SqlError::isTransientLock`.
/// - `is_query_exception == 0` / `1`: `SqlError::describe` (strips `' (Connection:'`, truncates > 500 UTF-8 chars with `'…'`)
/// - `is_query_exception == 2`: Formats `sqlstate=<state> driver_code=<code>: <single-line collapsed message>`
///
/// Writes `1` or `0` to `*is_transient_out` when non-null.
/// If `out_buf` is null and `out_cap == 0` while `is_transient_out` is non-null, returns `0` after setting `*is_transient_out`.
///
/// # Safety
/// Non-null pointers must be valid for their stated lengths and must not overlap `out_buf`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_sql_error_describe(
    is_query_exception: u8,
    sqlstate: *const u8,
    sqlstate_len: usize,
    driver_code: i64,
    message: *const u8,
    message_len: usize,
    is_transient_out: *mut u8,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_cap > isize::MAX as usize
            || (out_buf.is_null() && out_cap != 0)
            || is_query_exception > 2
        {
            return -1;
        }
        let (Some(sqlstate), Some(message)) = (unsafe {
            (
                wave9_input(sqlstate, sqlstate_len),
                wave9_input(message, message_len),
            )
        }) else {
            return -1;
        };
        let is_transient = (is_query_exception != 0
            && (sqlstate == b"40001" || matches!(driver_code, 1020 | 1205 | 1213)))
            || find_subslice(message, b"Deadlock found").is_some()
            || find_subslice(message, b"Lock wait timeout exceeded").is_some()
            || find_subslice(message, b"Record has changed since last read").is_some()
            || find_subslice(message, b"database is locked").is_some();
        if !is_transient_out.is_null() {
            unsafe {
                *is_transient_out = u8::from(is_transient);
            }
        }
        if out_buf.is_null() && out_cap == 0 && !is_transient_out.is_null() {
            return 0;
        }
        let stripped = match find_subslice(message, b" (Connection:") {
            Some(pos) => &message[..pos],
            None => message,
        };
        let mut output = Vec::new();
        if is_query_exception == 2 {
            let mut collapsed = Vec::with_capacity(stripped.len());
            let mut in_space = false;
            for &b in php_trim_ascii(stripped) {
                if is_pcre_space(b) {
                    if !in_space {
                        collapsed.push(b' ');
                        in_space = true;
                    }
                } else {
                    collapsed.push(b);
                    in_space = false;
                }
            }
            output.extend_from_slice(b"sqlstate=");
            output.extend_from_slice(sqlstate);
            output.extend_from_slice(b" driver_code=");
            output.extend_from_slice(driver_code.to_string().as_bytes());
            output.extend_from_slice(b": ");
            if !sql_error_truncate_utf8(&collapsed, &mut output) {
                return -1;
            }
        } else if !sql_error_truncate_utf8(stripped, &mut output) {
            return -1;
        }
        unsafe { wave9_write_output(&output, out_buf, out_cap) }
    }))
    .unwrap_or(-1)
}

fn passkey_first_host(value: &[u8]) -> &[u8] {
    let first = value.split(|&b| b == b',').next().unwrap_or(b"");
    let host = php_trim_ascii(first);
    if host.is_empty() {
        return b"";
    }
    let mut end = host.len();
    while end > 0 && host[end - 1].is_ascii_digit() {
        end -= 1;
    }
    let stripped = if end < host.len() && end > 0 && host[end - 1] == b':' {
        &host[..end - 1]
    } else {
        host
    };
    php_trim_ascii(stripped)
}

fn passkey_is_valid_rp_id(value: &[u8]) -> bool {
    let trimmed = php_trim_ascii(value);
    if trimmed.is_empty() {
        return false;
    }
    if std::str::from_utf8(trimmed)
        .ok()
        .and_then(|s| s.parse::<std::net::IpAddr>().ok())
        .is_some()
    {
        return false;
    }
    if trimmed == b"localhost" {
        return true;
    }
    trimmed.contains(&b'.')
}

fn passkey_is_strict_rfc1123_multi_label(host: &[u8]) -> bool {
    if host.is_empty() || host.len() > 253 || host.eq_ignore_ascii_case(b"localhost") {
        return false;
    }
    if host.starts_with(b"[") || host.ends_with(b"]") || host.contains(&b':') {
        return false;
    }
    if std::str::from_utf8(host)
        .ok()
        .and_then(|s| s.parse::<std::net::IpAddr>().ok())
        .is_some()
    {
        return false;
    }
    let mut label_count = 0usize;
    let mut all_numeric = true;
    for label in host.split(|&b| b == b'.') {
        if label.is_empty() || label.len() > 63 {
            return false;
        }
        if label.first() == Some(&b'-') || label.last() == Some(&b'-') {
            return false;
        }
        for &b in label {
            let lower = b.to_ascii_lowercase();
            if !lower.is_ascii_lowercase() && !lower.is_ascii_digit() && lower != b'-' {
                return false;
            }
            if !lower.is_ascii_digit() {
                all_numeric = false;
            }
        }
        label_count += 1;
    }
    label_count >= 2 && !all_numeric
}

/// Resolve and validate Passkey relying party hostnames:
/// - `mode == 0`: `RelyingPartyIdResolver::firstHost` (writes extracted host into `out_buf`, returns length `>= 0`)
/// - `mode == 1`: `RelyingPartyIdResolver::isValidRelyingPartyId` (returns `1` when valid, `0` when invalid)
/// - `mode == 2`: `RelyingPartyIdResolver::resolve` over 4 u32-LE length-prefixed frames `[x_forwarded_host, x_original_host, config_id, request_host]`
/// - `mode == 3`: Strict RFC 1123 multi-label hostname resolver (extracts first host, strips `:port`, rejects IPs/`localhost`, validates RFC 1123 `<= 253` chars & labels `1..63` chars `[a-z0-9-]`)
///
/// # Safety
/// Non-null pointers must be valid for their stated lengths and must not overlap `out_buf`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_passkey_resolve_rp_host(
    input: *const u8,
    input_len: usize,
    mode: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_cap > isize::MAX as usize || (out_buf.is_null() && out_cap != 0) {
            return -1;
        }
        let Some(bytes) = (unsafe { wave9_input(input, input_len) }) else {
            return -1;
        };
        match mode {
            0 => {
                let host = passkey_first_host(bytes);
                unsafe { wave9_write_output(host, out_buf, out_cap) }
            }
            1 => isize::from(passkey_is_valid_rp_id(bytes)),
            2 => {
                let Some(frames) = regex_release_frames(bytes) else {
                    return -1;
                };
                if frames.len() != 4 {
                    return -1;
                }
                let candidates = [
                    passkey_first_host(frames[0]),
                    passkey_first_host(frames[1]),
                    frames[2],
                    frames[3],
                ];
                let mut resolved: &[u8] = b"localhost";
                for cand in candidates {
                    if passkey_is_valid_rp_id(cand) {
                        resolved = cand;
                        break;
                    }
                }
                unsafe { wave9_write_output(resolved, out_buf, out_cap) }
            }
            3 => {
                let host = passkey_first_host(bytes);
                if !passkey_is_strict_rfc1123_multi_label(host) {
                    return 0;
                }
                let lowered: Vec<u8> = host.iter().map(u8::to_ascii_lowercase).collect();
                unsafe { wave9_write_output(&lowered, out_buf, out_cap) }
            }
            _ => -1,
        }
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave14_slice6_support_tests {
    use super::*;

    #[test]
    fn collections_is_music_group_and_extract_music_subject() {
        unsafe {
            assert_eq!(
                nntmux_collections_is_music_group(b"alt.binaries.flac".as_ptr(), 17),
                1
            );
            assert_eq!(
                nntmux_collections_is_music_group(b"alt.binaries.FLAC".as_ptr(), 17),
                0
            );
            assert_eq!(nntmux_collections_is_music_group(std::ptr::null(), 1), -1);

            let subject = br#"Broderick_Smith-Unknown "00-broderick.sfv" yEnc"#;
            let mut out = [0u8; 64];
            let n = nntmux_collections_extract_music_subject(
                subject.as_ptr(),
                subject.len(),
                out.as_mut_ptr(),
                out.len(),
            );
            assert_eq!(n, 25);
            assert_eq!(&out[..n as usize], br#"Broderick_Smith-Unknown ""#);

            let non_match = b"Plain subject";
            assert_eq!(
                nntmux_collections_extract_music_subject(
                    non_match.as_ptr(),
                    non_match.len(),
                    out.as_mut_ptr(),
                    out.len(),
                ),
                0
            );
        }
    }

    #[test]
    fn fingerprint_breaker_key_and_suspect_seconds() {
        unsafe {
            let mut out = [0u8; 256];
            let len = nntmux_fingerprint_breaker_key(
                b"News.Alternate.Invalid".as_ptr(),
                22,
                b"".as_ptr(),
                0,
                0,
                b"generation".as_ptr(),
                10,
                b"".as_ptr(),
                0,
                out.as_mut_ptr(),
                out.len(),
            );
            assert!(len > 0);
            let s = std::str::from_utf8(&out[..len as usize]).unwrap();
            assert!(s.starts_with("release-fingerprint:provider-breaker:"));
            assert!(s.ends_with(":generation"));

            assert_eq!(nntmux_fingerprint_breaker_suspect_seconds(900, 0), 3600);
            assert_eq!(nntmux_fingerprint_breaker_suspect_seconds(1200, 0), 4800);
            assert_eq!(nntmux_fingerprint_breaker_suspect_seconds(10, 120), 30);
            assert_eq!(nntmux_fingerprint_breaker_suspect_seconds(60, 120), 60);
        }
    }

    #[test]
    fn verification_and_forwarder_diagnostics() {
        unsafe {
            let flags = nntmux_verification_diagnostics_stop(
                b"limit".as_ptr(),
                5,
                7_100_000_000,
                1,
                1,
                1,
                6_000_000_000,
                1,
            );
            assert_eq!(flags & 0x0f, 0);
            assert_ne!(flags & 0x10, 0);
            assert_ne!(flags & 0x20, 0);

            assert_eq!(
                nntmux_publication_forwarder_classify(b"published".as_ptr(), 9, 1),
                0
            );
            assert_eq!(
                nntmux_publication_forwarder_classify(b"unknown_status".as_ptr(), 14, 1),
                11
            );
        }
    }

    #[test]
    fn size_unit_sql_error_and_passkey_resolver() {
        unsafe {
            let mut out = [0u8; 256];
            let n = nntmux_size_unit_convert(
                b"1.5".as_ptr(),
                3,
                b"GB".as_ptr(),
                2,
                0,
                out.as_mut_ptr(),
                out.len(),
            );
            assert!(n > 0);
            assert_eq!(&out[..n as usize], b"1610612736");
            assert_eq!(
                nntmux_size_unit_convert(
                    b"1".as_ptr(),
                    1,
                    b"TB".as_ptr(),
                    2,
                    0,
                    out.as_mut_ptr(),
                    out.len(),
                ),
                -2
            );
            let n_tb = nntmux_size_unit_convert(
                b"1".as_ptr(),
                1,
                b"TB".as_ptr(),
                2,
                2,
                out.as_mut_ptr(),
                out.len(),
            );
            assert!(n_tb > 0);
            assert_eq!(&out[..n_tb as usize], b"1099511627776");

            let mut is_transient = 0u8;
            let msg = b"SQLSTATE[40001]: Deadlock found (Connection: mariadb, SQL: INSERT ...)";
            let desc_len = nntmux_sql_error_describe(
                1,
                b"40001".as_ptr(),
                5,
                1213,
                msg.as_ptr(),
                msg.len(),
                &mut is_transient,
                out.as_mut_ptr(),
                out.len(),
            );
            assert_eq!(is_transient, 1);
            assert_eq!(
                &out[..desc_len as usize],
                b"SQLSTATE[40001]: Deadlock found"
            );

            let host_in = b" auth.example.com:8443 , proxy.example.com ";
            let host_len = nntmux_passkey_resolve_rp_host(
                host_in.as_ptr(),
                host_in.len(),
                0,
                out.as_mut_ptr(),
                out.len(),
            );
            assert_eq!(&out[..host_len as usize], b"auth.example.com");
            assert_eq!(
                nntmux_passkey_resolve_rp_host(
                    b"127.0.0.1".as_ptr(),
                    9,
                    1,
                    std::ptr::null_mut(),
                    0
                ),
                0
            );
            assert_eq!(
                nntmux_passkey_resolve_rp_host(
                    b"localhost".as_ptr(),
                    9,
                    1,
                    std::ptr::null_mut(),
                    0
                ),
                1
            );
        }
    }
}


// Wave 16: pure arithmetic/reporting candidates. PHP owns NNTP, clocks and database effects.
// Status: nonnegative = elements/bytes written; -1 = invalid argument, -2 = capacity,
// -3 = unsupported/overflow, -4 = caught panic. Failures never partially write output.
fn wave16_region<T>(ptr: *const T, len: usize) -> bool {
    let Some(bytes) = len.checked_mul(std::mem::size_of::<T>()) else {
        return false;
    };
    bytes <= isize::MAX as usize
        && (len == 0
            || (!ptr.is_null() && ptr.is_aligned() && (ptr as usize).checked_add(bytes).is_some()))
}

fn wave16_sum(values: &[i128]) -> Result<i128, isize> {
    let mut total = 0i64;
    for &value in values {
        let value = i64::try_from(value).map_err(|_| -3isize)?;
        total = total.checked_add(value).ok_or(-3isize)?;
    }
    Ok(i128::from(total))
}

fn wave16_values(domain: u32, mode: u32, a: &[i64]) -> Result<Vec<i64>, isize> {
    let a: Vec<i128> = a.iter().map(|&v| i128::from(v)).collect();
    let values = match (domain, mode) {
        // Existing group header range: PHP intentionally excludes the local watermark.
        (0, 0) => {
            let [first, newest, buffer, limit] = a[..] else {
                return Err(-1);
            };
            if buffer <= 0 {
                return Err(-3);
            }
            let count = newest - first;
            // PHP's round(count / 2, HALF_DOWN) uses a double. Decline outside its exact range.
            if count.abs() > (1i128 << 53) {
                return Err(-3);
            }
            let leave = if count > buffer * 2 {
                count % buffer + buffer
            } else {
                count / 2
            };
            let mut last = (newest - leave).max(first);
            if limit > 0 && limit < last - first {
                last = first + limit;
            }
            vec![first, last, last, last - first, count, leave]
        }
        (0, 1) => {
            let [oldest, newest, messages, buffer, by_date, date_first] = a[..] else {
                return Err(-1);
            };
            if !(0..=1).contains(&by_date) {
                return Err(-1);
            }
            let span = i64::try_from(messages + buffer).map_err(|_| -3isize)?;
            let from_end = i64::try_from(newest - i128::from(span)).map_err(|_| -3isize)?;
            let first = if by_date == 1 {
                date_first
            } else {
                oldest.max(i128::from(from_end))
            };
            let last = (newest - buffer).max(first);
            vec![first, last, last, last - first, newest - first, buffer]
        }
        (0, 2) => {
            let [first, last, group_last, total, buffer] = a[..] else {
                return Err(-1);
            };
            let end = if total > buffer {
                i128::from(i64::try_from(first + buffer).map_err(|_| -3isize)?).min(group_last)
            } else {
                last
            };
            vec![first + 1, end]
        }
        (0, 3) => {
            let [minimum, maximum] = a[..] else {
                return Err(-1);
            };
            if minimum < 0 || maximum < minimum {
                return Err(-3);
            }
            let distance = maximum - minimum;
            vec![minimum + distance / 2 + distance % 2]
        }
        (0, 4) => {
            let [current, first, last, factor] = a[..] else {
                return Err(-1);
            };
            if !(1005..=1012).contains(&factor)
                || first < 0
                || current < first
                || current > last
                || last > (1i128 << 52)
            {
                return Err(-3);
            }
            let ratio = factor as f64 / 1000.0;
            let downward = (current - first).abs() > (last - current).abs();
            let raw = if downward {
                current as f64 / ratio
            } else {
                ratio * current as f64
            };
            // PHP corrects near-half floating point products before rounding. Decline ambiguous ties.
            if (raw.fract() - 0.5).abs() <= 4.0 * f64::EPSILON * raw.abs().max(1.0) {
                return Err(-3);
            }
            let probe = if downward {
                (raw.round() as i128).max(first)
            } else {
                (raw.round() as i128).min(last)
            };
            vec![probe]
        }
        (1, 0) => {
            let [first, articles, oldest] = a[..] else {
                return Err(-1);
            };
            let target = first - articles;
            // The PHP baseline calls round() even for integral inputs.
            if target.abs() > (1i128 << 53) {
                return Err(-3);
            }
            vec![target.max(oldest)]
        }
        (1, 1) => {
            let [target, oldest] = a[..] else {
                return Err(-1);
            };
            vec![target.max(oldest)]
        }
        (1, 2) => {
            let [previous_first, target, buffer] = a[..] else {
                return Err(-1);
            };
            let last = i128::from(i64::try_from(previous_first - 1).map_err(|_| -3isize)?);
            let start = i128::from(i64::try_from(last - buffer).map_err(|_| -3isize)?) + 1;
            vec![start.max(target), last]
        }
        (2, 0) => {
            let [timestamp, hours] = a[..] else {
                return Err(-1);
            };
            let offset = i64::try_from(hours * 3600).map_err(|_| -3isize)?;
            vec![timestamp - i128::from(offset)]
        }
        (2, 1) => {
            let [cursor, window, maximum] = a[..] else {
                return Err(-1);
            };
            let sum = i64::try_from(cursor + window).map_err(|_| -3isize)?;
            vec![i128::from(sum).min(maximum)]
        }
        (2, 2) => {
            let [value, minimum, maximum] = a[..] else {
                return Err(-1);
            };
            vec![value.min(maximum).max(minimum)]
        }
        (2, 3) => vec![wave16_sum(&a)?],
        (3, 0) => {
            if a.len() % 2 != 0 {
                return Err(-1);
            }
            let n = a.len() / 2;
            a[..n].iter().zip(&a[n..]).map(|(a, b)| a + b).collect()
        }
        (3, 1) => vec![wave16_sum(&a)?],
        (4, 0) => a[..9].iter().zip(&a[9..]).map(|(a, b)| a + b).collect(),
        (4, 1) => vec![wave16_sum(&a)?],
        (4, 2) => {
            let mut v = a.clone();
            v.push(wave16_sum(&a)?);
            v
        }
        (4, 3) => vec![i128::from(wave16_sum(&a)? > 0)],
        _ => return Err(-1),
    };
    values
        .into_iter()
        .map(|v| i64::try_from(v).map_err(|_| -3))
        .collect()
}

unsafe fn wave16_math(
    domain: u32,
    mode: u32,
    input: *const i64,
    len: usize,
    out: *mut i64,
    cap: usize,
) -> isize {
    let (expected, needed) = match (domain, mode) {
        (0, 0) => (4, 6),
        (0, 1) => (6, 6),
        (0, 2) => (5, 2),
        (0, 3) => (2, 1),
        (0, 4) => (4, 1),
        (1, 0) => (3, 1),
        (1, 1) => (2, 1),
        (1, 2) => (3, 2),
        (2, 0) => (2, 1),
        (2, 1) => (3, 1),
        (2, 2) => (3, 1),
        (2, 3) | (3, 1) if len <= 1024 => (len, 1),
        (3, 0) => (8, 4),
        (4, 0) => (18, 9),
        (4, 1) | (4, 3) => (9, 1),
        (4, 2) => (9, 10),
        _ => return -1,
    };
    if len != expected || !wave16_region(input, len) || !wave16_region(out, cap) {
        return -1;
    }
    if cap < needed {
        return -2;
    }
    let input = if len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input, len) }
    };
    let values = match wave16_values(domain, mode, input) {
        Ok(v) => v,
        Err(e) => return e,
    };
    // All input reads finish before writing; source is owned, so callers may reuse an input buffer.
    unsafe {
        std::ptr::copy_nonoverlapping(values.as_ptr(), out, values.len());
    }
    values.len() as isize
}

/// # Safety
/// Nonempty pointers must name aligned readable/writable regions of the declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_binaries_calculate(
    mode: u32,
    input: *const i64,
    len: usize,
    out: *mut i64,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| unsafe { wave16_math(0, mode, input, len, out, cap) }).unwrap_or(-4)
}
/// # Safety
/// Nonempty pointers must name aligned readable/writable regions of the declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_backfill_calculate(
    mode: u32,
    input: *const i64,
    len: usize,
    out: *mut i64,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| unsafe { wave16_math(1, mode, input, len, out, cap) }).unwrap_or(-4)
}
/// # Safety
/// Nonempty pointers must name aligned readable/writable regions of the declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_cleanup_calculate(
    mode: u32,
    input: *const i64,
    len: usize,
    out: *mut i64,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| unsafe { wave16_math(2, mode, input, len, out, cap) }).unwrap_or(-4)
}
/// # Safety
/// Nonempty pointers must name aligned readable/writable regions of the declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_counts(
    mode: u32,
    input: *const i64,
    len: usize,
    out: *mut i64,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| unsafe { wave16_math(3, mode, input, len, out, cap) }).unwrap_or(-4)
}
/// # Safety
/// Nonempty pointers must name aligned readable/writable regions of the declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_delete_stats(
    mode: u32,
    input: *const i64,
    len: usize,
    out: *mut i64,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| unsafe { wave16_math(4, mode, input, len, out, cap) }).unwrap_or(-4)
}

fn wave16_grouped(value: i64) -> Result<String, isize> {
    if value.unsigned_abs() > (1u64 << 53) {
        return Err(-3);
    }
    let raw = value.unsigned_abs().to_string();
    let mut out = String::new();
    if value < 0 {
        out.push('-');
    }
    for (index, c) in raw.chars().enumerate() {
        if index > 0 && (raw.len() - index) % 3 == 0 {
            out.push(',');
        }
        out.push(c);
    }
    Ok(out)
}

unsafe fn wave16_text_write(value: &[u8], out: *mut u8, cap: usize) -> isize {
    if value.len() > cap {
        return -2;
    }
    if !value.is_empty() {
        unsafe {
            std::ptr::copy_nonoverlapping(value.as_ptr(), out, value.len());
        }
    }
    value.len() as isize
}

/// Formats the scan chunk diagnostic. Output is raw bytes, without a trailing NUL.
/// # Safety
/// Input/output pointers must name readable/writable regions of the declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_scan_diagnostic(
    first: i64,
    last: i64,
    group_last: i64,
    name: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        if !wave16_region(name, len) || !wave16_region(out, cap) || len > 1_048_576 {
            return -1;
        }
        // Output always includes the complete name, so this is also a pre-slice capacity check.
        if cap < len {
            return -2;
        }
        let build = || -> Result<Vec<u8>, isize> {
            let count =
                i64::try_from(i128::from(last) - i128::from(first) + 1).map_err(|_| -3isize)?;
            let queued = group_last.checked_sub(last).ok_or(-3isize)?;
            let mut bytes = format!(
                "\nGetting {} articles ({} to {}) from ",
                wave16_grouped(count)?,
                wave16_grouped(first)?,
                wave16_grouped(last)?
            )
            .into_bytes();
            if len > 0 {
                bytes.extend_from_slice(unsafe { slice::from_raw_parts(name, len) });
            }
            bytes.extend_from_slice(
                format!(" - ({} articles in queue).", wave16_grouped(queued)?).as_bytes(),
            );
            Ok(bytes)
        };
        match build() {
            Ok(v) => unsafe { wave16_text_write(&v, out, cap) },
            Err(e) => e,
        }
    })
    .unwrap_or(-4)
}

fn wave16_elapsed(seconds: f64) -> Result<String, isize> {
    if !seconds.is_finite() || seconds.abs() > 9_000_000_000_000.0 {
        return Err(-3);
    }
    Ok(if seconds < 1.0 {
        format!("{}ms", (seconds * 1000.0) as i64)
    } else if seconds < 60.0 {
        if ((seconds * 10.0).fract().abs() - 0.5).abs() <= 1e-9 {
            return Err(-3);
        }
        format!("{seconds:.1}s")
    } else {
        let minutes = (seconds / 60.0).floor() as i64;
        if minutes < 60 {
            format!("{}m {}s", minutes, seconds as i64 % 60)
        } else {
            format!("{}h {}m", minutes / 60, minutes % 60)
        }
    })
}

/// # Safety
/// Output must name a writable region of cap bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_elapsed(seconds: f64, out: *mut u8, cap: usize) -> isize {
    std::panic::catch_unwind(|| {
        if !wave16_region(out, cap) {
            return -1;
        }
        match wave16_elapsed(seconds) {
            Ok(v) => unsafe { wave16_text_write(v.as_bytes(), out, cap) },
            Err(e) => e,
        }
    })
    .unwrap_or(-4)
}

/// mode 0: four NUL-separated release summary lines; mode 1: nonzero deletion fields.
/// # Safety
/// Input/output pointers must name aligned readable/writable regions of the declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_summary(
    mode: u32,
    input: *const i64,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let expected = match mode {
            0 => 4,
            1 => 9,
            _ => return -1,
        };
        if len != expected || !wave16_region(input, len) || !wave16_region(out, cap) {
            return -1;
        }
        if cap == 0 {
            return -2;
        }
        let input = unsafe { slice::from_raw_parts(input, len) };
        let build = || -> Result<String, isize> {
            if mode == 0 {
                let duplicates = if input[2] > 0 {
                    format!("  Duplicates skipped: {}", wave16_grouped(input[2])?)
                } else {
                    String::new()
                };
                Ok(format!(
                    "  Releases added: {}\0  NZBs created: {}\0{}\0  Processing cycles: {}",
                    wave16_grouped(input[0])?,
                    wave16_grouped(input[1])?,
                    duplicates,
                    wave16_grouped(input[3])?
                ))
            } else {
                let keys = [
                    "retention",
                    "password",
                    "duplicate",
                    "completion",
                    "disabledCategory",
                    "categoryMinSize",
                    "disabledGenre",
                    "miscOther",
                    "miscHashed",
                ];
                let fields: Vec<String> = input
                    .iter()
                    .zip(keys)
                    .filter(|(v, _)| **v != 0)
                    .map(|(v, k)| format!("{k}: {v}"))
                    .collect();
                Ok(if fields.is_empty() {
                    "No releases deleted.".to_owned()
                } else {
                    fields.join(", ")
                })
            }
        };
        match build() {
            Ok(v) => unsafe { wave16_text_write(v.as_bytes(), out, cap) },
            Err(e) => e,
        }
    })
    .unwrap_or(-4)
}

#[cfg(test)]
mod wave16_tests {
    use super::*;

    #[test]
    fn arithmetic_contract_and_overflow() {
        unsafe {
            let mut out = [777i64; 10];
            let input = [100, 1000, 100, 250];
            assert_eq!(
                nntmux_binaries_calculate(0, input.as_ptr(), 4, out.as_mut_ptr(), 6),
                6
            );
            assert_eq!(&out[..6], &[100, 350, 350, 250, 900, 100]);
            out.fill(777);
            assert_eq!(
                nntmux_binaries_calculate(0, input.as_ptr(), 4, out.as_mut_ptr(), 5),
                -2
            );
            assert!(out.iter().all(|v| *v == 777));
            assert_eq!(
                nntmux_binaries_calculate(0, std::ptr::null(), 4, out.as_mut_ptr(), 6),
                -1
            );
            assert_eq!(
                nntmux_binaries_calculate(0, input.as_ptr(), usize::MAX, out.as_mut_ptr(), 6),
                -1
            );
            assert_eq!(
                nntmux_binaries_calculate(0, input.as_ptr(), 4, out.as_mut_ptr(), usize::MAX),
                -1
            );
            let overflow = [i64::MAX; 18];
            assert_eq!(
                nntmux_release_delete_stats(0, overflow.as_ptr(), 18, out.as_mut_ptr(), 9),
                -3
            );
            let cutoff = [i64::MIN, 1];
            assert_eq!(
                nntmux_cleanup_calculate(0, cutoff.as_ptr(), 2, out.as_mut_ptr(), 1),
                -3
            );
            let midpoint = [1, i64::MAX];
            assert_eq!(
                nntmux_binaries_calculate(3, midpoint.as_ptr(), 2, out.as_mut_ptr(), 1),
                1
            );
            assert_eq!(out[0], 4611686018427387904);
        }
    }

    #[test]
    fn text_contracts() {
        unsafe {
            let mut out = [42u8; 256];
            assert_eq!(nntmux_release_elapsed(60.5, out.as_mut_ptr(), 256), 5);
            assert_eq!(&out[..5], b"1m 0s");
        }
    }
}

#[cfg(test)]
mod wave17_query_pass_tests {
    use super::*;

    fn fields(values: &[&[u8]]) -> Vec<u8> {
        let mut bytes = Vec::new();
        for value in values {
            bytes.extend_from_slice(&(value.len() as u32).to_le_bytes());
            bytes.extend_from_slice(value);
        }
        bytes
    }

    fn invoke(mode: u32, input: &[u8], first: i64, second: i64) -> (isize, Vec<u8>) {
        let mut out = vec![0xa5; 8192];
        let result = unsafe {
            nntmux_name_fixing_query_pass(
                mode,
                input.as_ptr(),
                input.len(),
                first,
                second,
                out.as_mut_ptr(),
                out.len(),
            )
        };
        if result >= 0 {
            out.truncate(result as usize);
        }
        (result, out)
    }

    #[test]
    fn raw_operations_and_declines() {
        assert_eq!(invoke(0, b"Show.S01", 0, 0).1, b"1");
        assert_eq!(invoke(0, b"Show.S01\n", 0, 0).0, -2);
        assert_eq!(invoke(1, b"nfo", 0, 0).1, b"nfo");
        assert!(invoke(1, b"bad\x7f", 0, 0).1.is_empty());
        assert_eq!(invoke(1, b"\xff", 0, 0).0, -2);
        assert_eq!(invoke(2, b"", 3, 0).1, b"?,?,?");
        assert!(invoke(2, b"", 0, 0).1.is_empty());
        assert_eq!(invoke(2, b"", -1, 0).0, -2);
        assert_eq!(invoke(3, b"a\\b%c_d", 0, 0).1, b"a\\\\b\\%c\\_d");
        assert_eq!(invoke(4, b"HELLO_\xff", 0, 0).1, b"hello \xff");
        assert_eq!(invoke(9, &[1, 3], 0, 0).1, b"1");
        assert_eq!(invoke(9, &[1, 2], 0, 0).1, b"0");
        assert_eq!(invoke(10, &[1, 3], 0, 0).1, b"1");
        assert_eq!(invoke(10, &[], 0, 0).1, b"0");
        assert_eq!(invoke(9, &[], 0, 0).1, b"1");
    }

    #[test]
    fn field_operations_and_order() {
        assert_eq!(
            invoke(5, &fields(&[b"unknown", b"tItLe MaTcH source"]), 0, 0).1,
            b"1"
        );
        assert_eq!(invoke(5, &fields(&[b"UID, ", b""]), 0, 0).1, b"1");
        assert_eq!(invoke(5, &fields(&[b"UID,", b""]), 0, 0).1, b"0");
        assert_eq!(
            invoke(6, b"NFO, ", 0, 0).1,
            b"isrenamed\0iscategorized\0proc_nfo"
        );
        assert_eq!(invoke(6, b"sorter, ", 0, 0).1, b"isrenamed\0iscategorized");
        assert!(invoke(6, b"unknown", 0, 0).1.is_empty());
        assert_eq!(
            invoke(7, &fields(&[b"Show.S01", b"Show.S01E01"]), 0, 0).1,
            b"1"
        );
        assert_eq!(
            invoke(8, &fields(&[b"12", b"guid", b"", b"old", b"new"]), 7, 7).1,
            fields(&[b"12", b"guid", b"1", b"", b"0", b"new"])
        );
        assert!(
            invoke(8, &fields(&[b"12", b"guid", b"", b"old", b"new"]), 3, 0)
                .1
                .is_empty()
        );
        assert_eq!(
            invoke(
                11,
                &fields(&[b"1", b"7:title", b"1", b"", b"1", b"7:title"]),
                0,
                0
            )
            .1,
            b"0,2"
        );
        assert!(
            invoke(11, &fields(&[b"1", b"7:title", b"0", b"7:title"]), 0, 0)
                .1
                .is_empty()
        );
        assert!(
            invoke(11, &fields(&[b"1", b"7:title", b"1", b"8:title"]), 0, 0)
                .1
                .is_empty()
        );
        assert_eq!(
            invoke(12, &fields(&[b"a", b"b", b"a", b"", b""]), 0, 0).1,
            b"0,1,0,3,3"
        );
        assert_eq!(invoke(15, b"Mediainfo, ", 0, 0).1, b"proc_uid");
        assert!(invoke(15, b"Filenames, ", 0, 0).1.is_empty());
        assert_eq!(
            invoke(18, &fields(&[b"a", b"", b"a", b"b", b"\xff"]), 0, 0).1,
            fields(&[b"a", b"b", b"\xff"])
        );
    }

    #[test]
    fn page_validation_and_literal_regex() {
        assert!(invoke(16, &fields(&[b"11", b"12"]), 10, 0).1.is_empty());
        assert_eq!(invoke(16, &fields(&[b"11", b"11"]), 10, 0).1, b"order");
        assert_eq!(invoke(16, &fields(&[b"11"]), 10, 1).1, b"boundaries");
        assert_eq!(invoke(16, &fields(&[b"not-int"]), 0, 0).0, -2);
        assert_eq!(
            invoke(17, &fields(&[b"/hello/i", b"well HELLO!"]), 0, 0).1,
            fields(&[b"1", b"HELLO"])
        );
        assert_eq!(
            invoke(17, &fields(&[b"/^hello$/", b"hello"]), 0, 0).1,
            fields(&[b"1", b"hello"])
        );
        assert_eq!(
            invoke(17, &fields(&[b"/literal/", b"literal bytes"]), 0, 0).1,
            fields(&[b"1", b"literal"])
        );
        assert_eq!(
            invoke(17, &fields(&[b"/^hello$/", b"well hello"]), 0, 0).1,
            fields(&[b"0", b""])
        );
        assert_eq!(
            invoke(17, &fields(&[b"/a\\.b/", b"a.b"]), 0, 0).1,
            fields(&[b"1", b"a.b"])
        );
        for pattern in [
            b"/h.llo/".as_slice(),
            b"/[a]/",
            b"/hello/u",
            b"//",
            b"/\\d/",
            b"/(hello)/",
            b"/hello/iD",
            b"#hello#",
        ] {
            assert_eq!(
                invoke(17, &fields(&[pattern, b"hello"]), 0, 0).0,
                -2,
                "{pattern:?}"
            );
        }
        assert_eq!(invoke(17, &fields(&[b"/hello$/", b"hello\n"]), 0, 0).0, -2);
    }

    #[test]
    fn abi_rejects_bad_contracts_without_partial_writes() {
        unsafe {
            let mut out = [0xa5; 16];
            let ptr = out.as_mut_ptr();
            assert_eq!(
                nntmux_name_fixing_query_pass(2, std::ptr::null(), 0, 9, 0, ptr, 16),
                -3
            );
            assert_eq!(out, [0xa5; 16]);
            assert_eq!(
                nntmux_name_fixing_query_pass(2, std::ptr::null(), 0, i64::MAX, 0, ptr, 16),
                -1
            );
            assert_eq!(
                nntmux_name_fixing_query_pass(
                    2,
                    std::ptr::null(),
                    0,
                    0,
                    0,
                    std::ptr::null_mut(),
                    0
                ),
                0
            );
            assert_eq!(
                nntmux_name_fixing_query_pass(0, std::ptr::null(), 1, 0, 0, ptr, 16),
                -1
            );
            assert_eq!(
                nntmux_name_fixing_query_pass(0, b"".as_ptr(), 0, 0, 0, std::ptr::null_mut(), 1),
                -1
            );
            assert_eq!(
                nntmux_name_fixing_query_pass(0, usize::MAX as *const u8, 1, 0, 0, ptr, 16),
                -1
            );
            assert_eq!(
                nntmux_name_fixing_query_pass(0, b"".as_ptr(), 0, 0, 0, usize::MAX as *mut u8, 1),
                -1
            );
            assert_eq!(
                nntmux_name_fixing_query_pass(
                    0,
                    b"".as_ptr(),
                    isize::MAX as usize + 1,
                    0,
                    0,
                    ptr,
                    16
                ),
                -1
            );
            assert_eq!(invoke(99, b"", 0, 0).0, -2);
            assert_eq!(invoke(5, &[1, 0], 0, 0).0, -1);
            assert_eq!(invoke(5, &fields(&[b"only one"]), 0, 0).0, -1);
            assert_eq!(
                nntmux_name_fixing_query_pass(3, b"%_".as_ptr(), 2, 0, 0, ptr, 3),
                -3
            );
            assert_eq!(out, [0xa5; 16]);
        }
    }

    #[test]
    fn sql_matches_native_whitespace_and_branch_order() {
        let candidate = fields(&[
            b"hash",
            b"proc_hash16k",
            b"HASH_EXISTS",
            b"7010",
            b"7020",
            b"7010,7020",
            b"SETTLED",
        ]);
        let expected = b"r.predb_id = 0 AND (HASH_EXISTS) AND (r.isrenamed = 0 OR r.categories_id IN (?, ?)) AND r.proc_hash16k = 0 AND r.adddate > ? AND r.categories_id IN (7010,7020) AND SETTLED AND NOT EXISTS (SELECT 1 FROM par2_hash_retry hr WHERE hr.releases_id = r.id\n                AND HEX(hr.release_guid) = HEX(r.guid) AND hr.retry_at > ?)";
        assert_eq!(invoke(13, &candidate, 1, 1).1, expected);
        assert_eq!(
            invoke(
                13,
                &fields(&[
                    b"nfo",
                    b"proc_nfo",
                    b"NFO_EXISTS",
                    b"7010",
                    b"7020",
                    b"7010,7020",
                    b"SETTLED"
                ]),
                0,
                3
            )
            .1,
            b"r.predb_id = 0 AND (NFO_EXISTS) AND SETTLED"
        );
        assert_eq!(
            invoke(
                13,
                &fields(&[b"unknown", b"column", b"exists", b"", b"", b"", b"settled"]),
                0,
                0
            )
            .0,
            -2
        );
        let pending = fields(&[
            b"7010",
            b"7020",
            b"7010,7020",
            b"SETTLED",
            b"CUTOFF",
            b"nfo",
            b"proc_nfo",
            b"NFO_EXISTS",
            b"files",
            b"proc_files",
            b"FILES_EXISTS",
            b"srr",
            b"proc_srr",
            b"SRR_EXISTS",
            b"crc",
            b"proc_crc32",
            b"CRC_EXISTS",
            b"uid",
            b"proc_uid",
            b"UID_EXISTS",
            b"hash",
            b"proc_hash16k",
            b"HASH_EXISTS",
            b"xxx",
            b"proc_files",
            b"XXX_EXISTS",
            b"media_movie",
            b"proc_uid",
            b"MOVIE_EXISTS",
        ]);
        let expected = b"r.predb_id = 0 AND (r.isrenamed = 0 OR r.categories_id IN (7010, 7020)) AND r.categories_id IN (7010,7020) AND (CASE WHEN r.proc_par2 = 0 AND (r.nzbstatus = 1) AND SETTLED THEN 1 WHEN (r.proc_nfo = 0 AND (NFO_EXISTS)) OR (r.proc_files = 0 AND (FILES_EXISTS)) OR (r.proc_srr = 0 AND (SRR_EXISTS)) OR (r.proc_crc32 = 0 AND (CRC_EXISTS)) OR (r.proc_uid = 0 AND (UID_EXISTS)) OR (r.proc_hash16k = 0 AND (HASH_EXISTS AND NOT EXISTS (SELECT 1 FROM par2_hash_retry hr WHERE hr.releases_id = r.id AND HEX(hr.release_guid) = HEX(r.guid) AND hr.retry_at > CURRENT_TIMESTAMP))) OR (r.proc_files = 0 AND (XXX_EXISTS)) OR (r.proc_uid = 0 AND (MOVIE_EXISTS)) THEN 1 ELSE 0 END = 1) AND SETTLED AND r.adddate > CUTOFF";
        assert_eq!(invoke(14, &pending, 0, 0).1, expected);
        let all_backlog = invoke(14, &pending, 1, 1).1;
        assert!(
            !all_backlog
                .windows(b"AND r.categories_id IN".len())
                .any(|w| w == b"AND r.categories_id IN")
        );
        assert!(all_backlog.ends_with(b"AND SETTLED"));
    }

    #[test]
    fn exact_capacity_and_all_mode_errors_are_atomic() {
        let cases = [
            (0, b"show".to_vec(), 0, 0),
            (2, vec![], 5, 0),
            (3, b"a_b%".to_vec(), 0, 0),
            (
                8,
                fields(&[b"7", b"guid", b"name", b"search", b"new"]),
                7,
                0,
            ),
            (12, fields(&[b"a", b"b", b"a"]), 0, 0),
            (17, fields(&[b"/literal/", b"literal"]), 0, 0),
            (18, fields(&[b"a", b"b", b"a"]), 0, 0),
        ];
        for (mode, input, first, second) in cases {
            let (size, expected) = invoke(mode, &input, first, second);
            assert!(size > 0);
            let mut out = vec![0xa5; size as usize];
            unsafe {
                assert_eq!(
                    nntmux_name_fixing_query_pass(
                        mode,
                        input.as_ptr(),
                        input.len(),
                        first,
                        second,
                        out.as_mut_ptr(),
                        out.len() - 1
                    ),
                    -3
                );
                assert!(out.iter().all(|&b| b == 0xa5));
                assert_eq!(
                    nntmux_name_fixing_query_pass(
                        mode,
                        input.as_ptr(),
                        input.len(),
                        first,
                        second,
                        out.as_mut_ptr(),
                        out.len()
                    ),
                    size
                );
            }
            assert_eq!(out, expected);
        }
        let ids: Vec<Vec<u8>> = (1..=21).map(|i| i.to_string().into_bytes()).collect();
        let ids: Vec<&[u8]> = ids.iter().map(Vec::as_slice).collect();
        assert!(invoke(16, &fields(&ids), 0, 1).1.is_empty());
        assert_eq!(invoke(16, &fields(&ids), 1, 1).1, b"order");
        assert_eq!(invoke(17, &fields(&[b"/hello/", b"h\xffllo"]), 0, 0).0, -2);
    }
}

// Wave 17 name-fixing query/pass helpers. Frames are repeated LE-u32 byte lengths
// followed by their payloads, with no leading field count.
fn name_query_region<T>(ptr: *const T, len: usize) -> bool {
    len <= isize::MAX as usize
        && (len == 0 || !ptr.is_null())
        && (ptr as usize).checked_add(len).is_some()
}

fn name_query_fields(mut input: &[u8]) -> Result<Vec<&[u8]>, isize> {
    let mut fields = Vec::new();
    while !input.is_empty() {
        let prefix = input.get(..4).ok_or(-1isize)?;
        let len = u32::from_le_bytes(prefix.try_into().map_err(|_| -1isize)?) as usize;
        input = &input[4..];
        let field = input.get(..len).ok_or(-1isize)?;
        fields.push(field);
        input = &input[len..];
    }
    Ok(fields)
}

struct NameQueryOutput {
    bytes: Vec<u8>,
    cap: usize,
}

impl NameQueryOutput {
    fn new(cap: usize) -> Self {
        Self {
            bytes: Vec::new(),
            cap,
        }
    }

    fn extend(&mut self, value: &[u8]) -> Result<(), isize> {
        let total = self.bytes.len().checked_add(value.len()).ok_or(-3isize)?;
        if total > self.cap || total > isize::MAX as usize {
            return Err(-3);
        }
        self.bytes.try_reserve(value.len()).map_err(|_| -3isize)?;
        self.bytes.extend_from_slice(value);
        Ok(())
    }

    fn field(&mut self, value: &[u8]) -> Result<(), isize> {
        let len = u32::try_from(value.len()).map_err(|_| -1isize)?;
        self.extend(&len.to_le_bytes())?;
        self.extend(value)
    }

    fn index(&mut self, value: usize, comma: bool) -> Result<(), isize> {
        if comma {
            self.extend(b",")?;
        }
        self.extend(value.to_string().as_bytes())
    }
}

fn name_query_processing_column(kind: &[u8]) -> Option<&'static [u8]> {
    match kind {
        b"NFO, " => Some(b"proc_nfo"),
        b"PAR2, " => Some(b"proc_par2"),
        b"PAR2 hash, " => Some(b"proc_hash16k"),
        b"SRR, " => Some(b"proc_srr"),
        b"UID, " | b"Mediainfo, " => Some(b"proc_uid"),
        b"CRC32, " => Some(b"proc_crc32"),
        _ => None,
    }
}

fn name_query_source(source: &[u8]) -> Option<usize> {
    [
        b"nfo".as_slice(),
        b"files",
        b"srr",
        b"crc",
        b"uid",
        b"hash",
        b"par2",
        b"xxx",
        b"media_movie",
    ]
    .iter()
    .position(|s| *s == source)
}

fn name_query_contains_ascii(value: &[u8], needle: &[u8]) -> bool {
    value
        .windows(needle.len())
        .any(|window| window.eq_ignore_ascii_case(needle))
}

fn name_query_literal_match<'a>(
    pattern: &[u8],
    subject: &'a [u8],
) -> Result<Option<&'a [u8]>, isize> {
    if !pattern.is_ascii()
        || !subject.is_ascii()
        || subject.contains(&b'\n')
        || pattern.len() < 3
        || pattern[0] != b'/'
        || pattern.contains(&0)
    {
        return Err(-2);
    }
    let end = pattern.iter().rposition(|&b| b == b'/').ok_or(-2isize)?;
    if end == 0 {
        return Err(-2);
    }
    let insensitive = match &pattern[end + 1..] {
        b"" => false,
        b"i" => true,
        _ => return Err(-2),
    };
    let mut body = &pattern[1..end];
    let anchored_start = body.first() == Some(&b'^');
    if anchored_start {
        body = &body[1..];
    }
    // An escaped final dollar is a literal, not an anchor.
    let slash_run = body
        .iter()
        .rev()
        .skip(1)
        .take_while(|&&b| b == b'\\')
        .count();
    let anchored_end = body.last() == Some(&b'$') && slash_run % 2 == 0;
    if anchored_end {
        body = &body[..body.len() - 1];
    }
    let mut literal = Vec::with_capacity(body.len());
    let mut cursor = 0;
    while cursor < body.len() {
        let b = body[cursor];
        if b == b'\\' {
            cursor += 1;
            let escaped = *body.get(cursor).ok_or(-2isize)?;
            if !escaped.is_ascii_punctuation() {
                return Err(-2);
            }
            literal.push(escaped);
        } else {
            if matches!(
                b,
                b'.' | b'+'
                    | b'*'
                    | b'?'
                    | b'['
                    | b']'
                    | b'('
                    | b')'
                    | b'{'
                    | b'}'
                    | b'|'
                    | b'^'
                    | b'$'
                    | b'/'
            ) {
                return Err(-2);
            }
            literal.push(b);
        }
        cursor += 1;
    }
    if literal.is_empty() {
        return Err(-2);
    }
    let matches = |bytes: &[u8]| {
        if insensitive {
            bytes.eq_ignore_ascii_case(&literal)
        } else {
            bytes == literal
        }
    };
    if subject.len() < literal.len() {
        return Ok(None);
    }
    let index = if anchored_start {
        if (!anchored_end || subject.len() == literal.len()) && matches(&subject[..literal.len()]) {
            Some(0)
        } else {
            None
        }
    } else if anchored_end {
        let start = subject.len() - literal.len();
        if matches(&subject[start..]) {
            Some(start)
        } else {
            None
        }
    } else {
        subject.windows(literal.len()).position(matches)
    };
    Ok(index.map(|start| &subject[start..start + literal.len()]))
}

fn name_query_build(
    mode: u32,
    input: &[u8],
    first: i64,
    second: i64,
    cap: usize,
) -> Result<Vec<u8>, isize> {
    let mut out = NameQueryOutput::new(cap);
    match mode {
        0 => {
            if input.contains(&b'\n') {
                return Err(-2);
            }
            out.extend(if crate::filename::is_season_pack_bytes(input) {
                b"1"
            } else {
                b"0"
            })?;
        }
        1 => {
            if !input.is_ascii() {
                return Err(-2);
            }
            if input.len() <= 50 && !input.iter().any(u8::is_ascii_control) {
                out.extend(input)?;
            }
        }
        2 => {
            if first < 0 {
                return Err(-2);
            }
            let count = usize::try_from(first).map_err(|_| -1isize)?;
            let required = if count == 0 {
                0
            } else {
                count
                    .checked_mul(2)
                    .and_then(|n| n.checked_sub(1))
                    .ok_or(-1isize)?
            };
            if required > isize::MAX as usize {
                return Err(-1);
            }
            if required > cap {
                return Err(-3);
            }
            out.bytes.try_reserve(required).map_err(|_| -3isize)?;
            for i in 0..required {
                out.bytes.push(if i % 2 == 0 { b'?' } else { b',' });
            }
        }
        3 => {
            for &b in input {
                if matches!(b, b'\\' | b'%' | b'_') {
                    out.extend(b"\\")?;
                }
                out.extend(&[b])?;
            }
        }
        4 => {
            for &b in input {
                out.extend(&[if b == b'_' {
                    b' '
                } else {
                    b.to_ascii_lowercase()
                }])?;
            }
        }
        5 => {
            let fields = name_query_fields(input)?;
            if fields.len() != 2 {
                return Err(-1);
            }
            let trusted = first > 0
                || fields[0].starts_with(b"PreDB")
                || fields[0].starts_with(b"PreDb")
                || matches!(fields[0], b"UID, " | b"PAR2 hash, " | b"CRC32, " | b"SRR, ")
                || [b"Title Match".as_slice(), b"file matched source", b"PreDb"]
                    .iter()
                    .any(|needle| name_query_contains_ascii(fields[1], needle));
            out.extend(if trusted { b"1" } else { b"0" })?;
        }
        6 => {
            let proc = match input {
                b"Filenames, " | b"file matched source: " => Some(b"proc_files".as_slice()),
                _ => name_query_processing_column(input),
            };
            if proc.is_some() || matches!(input, b"PreDB FT Exact, " | b"sorter, ") {
                out.extend(b"isrenamed\0iscategorized")?;
                if let Some(proc) = proc {
                    out.extend(b"\0")?;
                    out.extend(proc)?;
                }
            }
        }
        7 => {
            let fields = name_query_fields(input)?;
            if fields.len() != 2 {
                return Err(-1);
            }
            if fields.iter().any(|f| f.contains(&b'\n')) {
                return Err(-2);
            }
            let is_episode = crate::filename::is_season_pack_bytes(fields[0])
                && crate::filename::is_episode_name(fields[1])
                && !crate::filename::is_season_pack_bytes(fields[1]);
            out.extend(if is_episode { b"1" } else { b"0" })?;
        }
        8 => {
            let fields = name_query_fields(input)?;
            if fields.len() != 5 {
                return Err(-1);
            }
            if first == 7 {
                out.field(fields[0])?;
                out.field(fields[1])?;
                out.field(if second & 1 != 0 { b"1" } else { b"0" })?;
                out.field(fields[2])?;
                out.field(if second & 2 != 0 && second & 4 == 0 {
                    b"1"
                } else {
                    b"0"
                })?;
                out.field(if second & 4 != 0 {
                    fields[4]
                } else {
                    fields[3]
                })?;
            }
        }
        9 | 10 => {
            if input.iter().any(|&b| b > 3) {
                return Err(-1);
            }
            let value = if mode == 9 {
                input.iter().all(|b| b & 1 != 0)
            } else {
                input.iter().any(|b| b & 2 != 0)
            };
            out.extend(if value { b"1" } else { b"0" })?;
        }
        11 => {
            let fields = name_query_fields(input)?;
            if fields.len() % 2 != 0 {
                return Err(-1);
            }
            if fields
                .chunks_exact(2)
                .any(|row| !matches!(row[0], b"0" | b"1"))
            {
                return Err(-1);
            }
            if fields.chunks_exact(2).any(|row| row[0] == b"0") {
                return Ok(out.bytes);
            }
            let mut identity = None;
            for row in fields.chunks_exact(2) {
                if !row[1].is_empty() {
                    if identity.is_some_and(|value| value != row[1]) {
                        return Ok(out.bytes);
                    }
                    identity = Some(row[1]);
                }
            }
            let mut comma = false;
            for (i, row) in fields.chunks_exact(2).enumerate() {
                if !row[1].is_empty() {
                    out.index(i, comma)?;
                    comma = true;
                }
            }
        }
        12 => {
            let fields = name_query_fields(input)?;
            let mut first_seen = std::collections::HashMap::new();
            for (i, field) in fields.into_iter().enumerate() {
                let original = *first_seen.entry(field).or_insert(i);
                out.index(original, i != 0)?;
            }
        }
        13 => {
            let f = name_query_fields(input)?;
            if f.len() != 7 {
                return Err(-1);
            }
            let source = name_query_source(f[0]).ok_or(-2isize)?;
            out.extend(b"r.predb_id = 0 AND (")?;
            out.extend(f[2])?;
            out.extend(b")")?;
            if second != 3 {
                out.extend(b" AND (r.isrenamed = 0 OR r.categories_id IN (?, ?)) AND r.")?;
                out.extend(f[1])?;
                out.extend(b" = 0")?;
            }
            if first == 1 {
                out.extend(b" AND r.adddate > ?")?;
            }
            if second == 1 {
                out.extend(b" AND r.categories_id IN (")?;
                out.extend(f[5])?;
                out.extend(b")")?;
            }
            out.extend(b" AND ")?;
            out.extend(f[6])?;
            if source == 5 {
                out.extend(b" AND NOT EXISTS (SELECT 1 FROM par2_hash_retry hr WHERE hr.releases_id = r.id\n                AND HEX(hr.release_guid) = HEX(r.guid) AND hr.retry_at > ?)")?;
            }
        }
        14 => {
            let f = name_query_fields(input)?;
            if f.len() != 29 {
                return Err(-1);
            }
            let mut seen = [false; 9];
            for tuple in f[5..].chunks_exact(3) {
                let source = name_query_source(tuple[0]).ok_or(-2isize)?;
                if source == 6 || seen[source] {
                    return Err(-2);
                }
                seen[source] = true;
            }
            out.extend(b"r.predb_id = 0 AND (r.isrenamed = 0 OR r.categories_id IN (")?;
            out.extend(f[0])?;
            out.extend(b", ")?;
            out.extend(f[1])?;
            out.extend(b"))")?;
            if first == 0 {
                out.extend(b" AND r.categories_id IN (")?;
                out.extend(f[2])?;
                out.extend(b")")?;
            }
            out.extend(b" AND (CASE WHEN r.proc_par2 = 0 AND (r.nzbstatus = 1) AND ")?;
            out.extend(f[3])?;
            out.extend(b" THEN 1 WHEN ")?;
            for (i, tuple) in f[5..].chunks_exact(3).enumerate() {
                if i != 0 {
                    out.extend(b" OR ")?;
                }
                out.extend(b"(r.")?;
                out.extend(tuple[1])?;
                out.extend(b" = 0 AND (")?;
                out.extend(tuple[2])?;
                if tuple[0] == b"hash" {
                    out.extend(b" AND NOT EXISTS (SELECT 1 FROM par2_hash_retry hr WHERE hr.releases_id = r.id AND HEX(hr.release_guid) = HEX(r.guid) AND hr.retry_at > CURRENT_TIMESTAMP)")?;
                }
                out.extend(b"))")?;
            }
            out.extend(b" THEN 1 ELSE 0 END = 1) AND ")?;
            out.extend(f[3])?;
            if second == 0 {
                out.extend(b" AND r.adddate > ")?;
                out.extend(f[4])?;
            }
        }
        15 => {
            if let Some(proc) = name_query_processing_column(input) {
                out.extend(proc)?;
            }
        }
        16 => {
            let fields = name_query_fields(input)?;
            let mut ids = Vec::with_capacity(fields.len());
            for field in fields {
                ids.push(
                    std::str::from_utf8(field)
                        .ok()
                        .and_then(|s| s.parse::<i64>().ok())
                        .ok_or(-2isize)?,
                );
            }
            if ids.len() > 21 || second != 0 && ids.len() < 21 {
                out.extend(b"boundaries")?;
            } else {
                let mut previous = first;
                for id in ids {
                    if id <= previous {
                        out.extend(b"order")?;
                        break;
                    }
                    previous = id;
                }
            }
        }
        17 => {
            let fields = name_query_fields(input)?;
            if fields.len() != 2 {
                return Err(-1);
            }
            if let Some(matched) = name_query_literal_match(fields[0], fields[1])? {
                out.field(b"1")?;
                out.field(matched)?;
            } else {
                out.field(b"0")?;
                out.field(b"")?;
            }
        }
        18 => {
            let fields = name_query_fields(input)?;
            let mut seen = std::collections::HashSet::new();
            for field in fields {
                if !field.is_empty() && seen.insert(field) {
                    out.field(field)?;
                }
            }
        }
        _ => return Err(-2),
    }
    Ok(out.bytes)
}

/// Name-fixing helpers with an atomic output contract. Returns bytes written,
/// -1 for an invalid frame/region, -2 to decline to native PHP, -3 when capacity
/// is insufficient, and -4 after a caught panic. No error writes output bytes.
///
/// # Safety
/// Nonempty input/output regions must be valid for reads/writes respectively.
/// Null is accepted only for a zero-length region. Regions must fit in isize and
/// their addresses must not wrap. No output slice is formed before validation.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_name_fixing_query_pass(
    mode: u32,
    input: *const u8,
    len: usize,
    first: i64,
    second: i64,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        if !name_query_region(input, len) || !name_query_region(out, cap) {
            return -1;
        }
        let input = if len == 0 {
            &[]
        } else {
            unsafe { slice::from_raw_parts(input, len) }
        };
        match name_query_build(mode, input, first, second, cap) {
            Ok(bytes) => {
                if !bytes.is_empty() {
                    unsafe {
                        std::ptr::copy_nonoverlapping(bytes.as_ptr(), out, bytes.len());
                    }
                }
                bytes.len() as isize
            }
            Err(code) => code,
        }
    })
    .unwrap_or(-4)
}

// Wave 18 runner/release candidates. PHP retains database/NNTP effects and UTF-8 conversion.
// Atomic status contract: >=0 written; -1 invalid frame/region; -2 capacity;
// -3 unsupported arithmetic; -4 caught panic. No error changes the caller's output.
struct RunnerReleaseOutput {
    bytes: Vec<u8>,
    cap: usize,
}

impl RunnerReleaseOutput {
    fn field(&mut self, value: &[u8]) -> Result<(), isize> {
        let len = u32::try_from(value.len()).map_err(|_| -3isize)?;
        let required = self
            .bytes
            .len()
            .checked_add(4)
            .and_then(|n| n.checked_add(value.len()))
            .ok_or(-3isize)?;
        if required > self.cap {
            return Err(-2);
        }
        self.bytes
            .try_reserve(required - self.bytes.len())
            .map_err(|_| -3isize)?;
        self.bytes.extend_from_slice(&len.to_le_bytes());
        self.bytes.extend_from_slice(value);
        Ok(())
    }
    fn number(&mut self, n: i64) -> Result<(), isize> {
        self.field(n.to_string().as_bytes())
    }
    fn command(&mut self, id: i64, command: &[u8], options: &[u8]) -> Result<(), isize> {
        self.number(id)?;
        self.field(command)?;
        self.field(options)
    }
}

fn runner_release_int(value: &[u8]) -> Result<i64, isize> {
    std::str::from_utf8(value)
        .map_err(|_| -1isize)?
        .parse()
        .map_err(|_| -3isize)
}
fn runner_release_count(value: &[u8]) -> Result<usize, isize> {
    usize::try_from(runner_release_int(value)?).map_err(|_| -1isize)
}
// Restrict arithmetic involving PHP doubles to exact integers. Declined cases execute PHP.
fn runner_release_exact(value: i128) -> Result<i64, isize> {
    if value.abs() > (1i128 << 53) {
        return Err(-3);
    }
    i64::try_from(value).map_err(|_| -3isize)
}
fn runner_release_build(mode: u32, input: &[u8], cap: usize) -> Result<Vec<u8>, isize> {
    let fields = name_query_fields(input)?;
    let mut out = RunnerReleaseOutput {
        bytes: Vec::new(),
        cap,
    };
    match mode {
        0 => {
            if fields.len() < 3 || (fields.len() - 3) % 5 != 0 {
                return Err(-1);
            }
            let repair = runner_release_int(fields[0])?;
            if !(0..=1).contains(&repair) {
                return Err(-1);
            }
            let headers = runner_release_int(fields[1])?;
            let messages = runner_release_int(fields[2])?;
            let messages = if messages < 1 { 20000 } else { messages };
            for row in fields[3..].chunks_exact(5) {
                let id = runner_release_int(row[0])?;
                let name = String::from_utf8_lossy(row[1]);
                // Group names are binary-safe, so decline non-UTF8 instead of replacing bytes.
                if std::str::from_utf8(row[1]).is_err() {
                    return Err(-3);
                }
                let ours = runner_release_int(row[2])?;
                let theirs = runner_release_int(row[3])?;
                let total = runner_release_int(row[4])?;
                let count = i128::from(theirs) - i128::from(ours) - 20000;
                if ours == 0 || count <= i128::from(messages) * 2 {
                    out.command(
                        id,
                        format!("update_group_headers  {name}").as_bytes(),
                        format!("--max={messages}").as_bytes(),
                    )?;
                } else {
                    let end = i64::try_from(i128::from(ours) + count.min(i128::from(headers)))
                        .map_err(|_| -3isize)?;
                    let mut first = ours.checked_add(1).ok_or(-3isize)?;
                    let first_range = first;
                    while first <= end {
                        let last = i64::try_from(
                            (i128::from(first) + i128::from(messages) - 1).min(i128::from(end)),
                        )
                        .map_err(|_| -3isize)?;
                        out.command(
                            id,
                            format!("get_range  binaries  {name}  {first}  {last}").as_bytes(),
                            if first == first_range {
                                b"--contiguous\0--repair"
                            } else {
                                b"--contiguous"
                            },
                        )?;
                        first = last.checked_add(1).ok_or(-3isize)?;
                    }
                }
                if repair == 1 && total > 0 {
                    out.command(id, format!("part_repair  {name}").as_bytes(), b"")?;
                }
            }
        }
        1 => {
            if fields.len() % 2 != 0 {
                return Err(-1);
            }
            let mut groups = Vec::new();
            let mut total = 0usize;
            for pair in fields.chunks_exact(2) {
                let id = runner_release_int(pair[0])?;
                let count = runner_release_count(pair[1])?;
                total = total.checked_add(count).ok_or(-3isize)?;
                // Every command emits at least ten bytes. Bound work by output capacity.
                if total > cap / 10 {
                    return Err(-2);
                }
                groups.push((id, count));
            }
            let rounds = groups.iter().map(|(_, count)| *count).max().unwrap_or(0);
            for round in 0..rounds {
                for &(id, count) in &groups {
                    if round < count {
                        out.number(id)?;
                        out.number(i64::try_from(round).map_err(|_| -3isize)?)?;
                    }
                }
            }
        }
        2 => {
            if fields.len() != 1 {
                return Err(-1);
            }
            out.field(match runner_release_int(fields[0])? {
                1 => b"ORDER BY first_record_postdate DESC",
                2 => b"ORDER BY first_record_postdate ASC",
                3 => b"ORDER BY name ASC",
                4 => b"ORDER BY name DESC",
                5 => b"ORDER BY a.last_record DESC",
                _ => b"ORDER BY a.last_record ASC",
            })?;
        }
        3 => {
            if fields.len() != 2 {
                return Err(-1);
            }
            out.field(match runner_release_int(fields[0])? {
                1 => b"g.backfill_target",
                2 => fields[1],
                _ => b"0",
            })?;
        }
        4 => {
            if fields.len() != 6 {
                return Err(-1);
            }
            let name = std::str::from_utf8(fields[0]).map_err(|_| -3isize)?;
            let ours = i128::from(runner_release_int(fields[1])?);
            let theirs = i128::from(runner_release_int(fields[2])?);
            let quantity = i128::from(runner_release_int(fields[3])?);
            let threads = i128::from(runner_release_int(fields[4])?);
            let messages = i128::from(runner_release_int(fields[5])?);
            if ours <= theirs {
                return Ok(out.bytes);
            }
            if messages <= 0 {
                return Err(-3);
            }
            let target = i128::from(runner_release_exact(
                (ours - theirs).min(quantity * threads),
            )?);
            if target <= 0 {
                return Ok(out.bytes);
            }
            let batches = (target + messages - 1) / messages;
            if batches > (cap / 4) as i128 {
                return Err(-2);
            }
            for i in 0..batches {
                let first = runner_release_exact(ours - i * messages - messages)?;
                let last = runner_release_exact(ours - i * messages - 1)?;
                out.field(
                    format!("get_range  backfill  {name}  {first}  {last}  {}", i + 1).as_bytes(),
                )?;
            }
        }
        5 => {
            if fields.len() != 2 {
                return Err(-1);
            }
            // PHP str_replace processes needles sequentially, including newly
            // formed multibyte characters after earlier byte removals.
            let mut subject = fields[0].to_vec();
            for needle in [
                b"#".as_slice(),
                b"@",
                b"$",
                b"%",
                b"^",
                "§".as_bytes(),
                "¨".as_bytes(),
                "©".as_bytes(),
                "Ö".as_bytes(),
            ] {
                let mut read = 0usize;
                let mut write = 0usize;
                while read < subject.len() {
                    if subject[read..].starts_with(needle) {
                        read += needle.len();
                    } else {
                        subject[write] = subject[read];
                        write += 1;
                        read += 1;
                    }
                }
                subject.truncate(write);
            }
            out.field(&subject)?;
            let mut poster = fields[1];
            while poster.first() == Some(&b'\'') {
                poster = &poster[1..];
            }
            while poster.last() == Some(&b'\'') {
                poster = &poster[..poster.len() - 1];
            }
            out.field(poster)?;
        }
        7 => {
            if fields.is_empty() {
                return Err(-1);
            }
            let count = runner_release_count(fields[0])?;
            let dictionary_end = count
                .checked_mul(2)
                .and_then(|n| n.checked_add(1))
                .ok_or(-1isize)?;
            if dictionary_end > fields.len() {
                return Err(-1);
            }
            let mut dictionary = std::collections::HashMap::new();
            for pair in fields[1..dictionary_end].chunks_exact(2) {
                dictionary.insert(pair[0], runner_release_int(pair[1])?);
            }
            let mut offset = dictionary_end;
            while offset < fields.len() {
                let id = runner_release_int(fields[offset])?;
                let count = runner_release_count(*fields.get(offset + 1).ok_or(-1isize)?)?;
                offset += 2;
                let end = offset.checked_add(count).ok_or(-1isize)?;
                let names = fields.get(offset..end).ok_or(-1isize)?;
                let mut seen = std::collections::HashSet::new();
                for name in names {
                    if let Some(&group_id) = dictionary.get(name) {
                        if seen.insert(group_id) {
                            out.number(id)?;
                            out.number(group_id)?;
                        }
                    }
                }
                offset = end;
            }
        }
        8 => {
            if fields.len() != 3 {
                return Err(-1);
            }
            match runner_release_int(fields[0])? {
                0 => {
                    out.number(0)?;
                    out.number(0)?;
                    out.number(0)?;
                }
                1 => {
                    out.number(runner_release_int(fields[1])?)?;
                    out.number(runner_release_int(fields[2])?.max(0))?;
                    out.number(1)?;
                }
                _ => return Err(-1),
            }
        }
        9 => {
            if fields.is_empty() || fields.len() % 2 != 1 {
                return Err(-1);
            }
            for pair in fields[1..].chunks_exact(2) {
                if pair[0] == fields[0] {
                    out.number(runner_release_int(pair[1])?)?;
                    break;
                }
            }
        }
        _ => return Err(-1),
    }
    Ok(out.bytes)
}

/// Pure runner/release planner with length-prefixed little-endian byte fields.
/// # Safety
/// Nonempty regions must be valid for reads/writes. Null is accepted only at
/// length zero. Lengths, alignment, address arithmetic and capacity are checked
/// before creating slices; callers remain responsible for allocation validity.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_runner_release_transform(
    mode: u32,
    input: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        if !wave16_region(input, len) || !wave16_region(out, cap) {
            return -1;
        }
        let input = if len == 0 {
            &[]
        } else {
            unsafe { slice::from_raw_parts(input, len) }
        };
        match runner_release_build(mode, input, cap) {
            Ok(bytes) => {
                if !bytes.is_empty() {
                    unsafe {
                        std::ptr::copy_nonoverlapping(bytes.as_ptr(), out, bytes.len());
                    }
                }
                bytes.len() as isize
            }
            Err(code) => code,
        }
    })
    .unwrap_or(-4)
}

/// Integer DTO normalization. Input is copied before output, permitting overlap.
/// # Safety
/// Nonempty regions must be valid, aligned i64 allocations. Counts are element
/// counts; byte extents must fit isize and address arithmetic must not wrap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_creation_values(
    mode: u32,
    input: *const i64,
    len: usize,
    out: *mut i64,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        if !wave16_region(input, len) || !wave16_region(out, cap) {
            return -1;
        }
        let required = match mode {
            0 => 5,
            1 => 4,
            _ => return -1,
        };
        if len != (if mode == 0 { 4 } else { 2 }) {
            return -1;
        }
        if cap < required {
            return -2;
        }
        let values = unsafe { slice::from_raw_parts(input, len) };
        let result: Vec<i64> = if mode == 0 {
            if !(0..=1).contains(&values[3]) {
                return -1;
            }
            vec![
                values[0].min(100),
                i64::from((0..=100).contains(&values[0])),
                i64::from(values[1] > 0),
                i64::from(values[2] > 0 && values[3] == 1),
                i64::from(values[0] > 0),
            ]
        } else {
            let Some(total) = values[0].checked_add(values[1]) else {
                return -3;
            };
            vec![total, i64::from(values[0] > 0), values[0], values[1]]
        };
        unsafe {
            std::ptr::copy_nonoverlapping(result.as_ptr(), out, result.len());
        }
        result.len() as isize
    })
    .unwrap_or(-4)
}

#[cfg(test)]
mod wave18_runner_release_tests {
    use super::*;
    fn frame(fields: &[&[u8]]) -> Vec<u8> {
        let mut output = RunnerReleaseOutput {
            bytes: vec![],
            cap: 65536,
        };
        for field in fields {
            output.field(field).unwrap();
        }
        output.bytes
    }
    #[test]
    fn cleaning_wave18_plans_and_edges() {
        let input = frame(&[
            b"0",
            b"50000",
            b"20000",
            b"1",
            b"alt.test",
            b"100",
            b"200000",
            b"0",
        ]);
        let result = runner_release_build(0, &input, 65536).unwrap();
        let fields = name_query_fields(&result).unwrap();
        assert_eq!(fields.len(), 9);
        assert_eq!(fields[1], b"get_range  binaries  alt.test  101  20100");
        assert_eq!(fields[2], b"--contiguous\0--repair");
        assert_eq!(fields[7], b"get_range  binaries  alt.test  40101  50100");
        let input = frame(&[b"1", b"2", b"9", b"1"]);
        let result = runner_release_build(1, &input, 65536).unwrap();
        assert_eq!(
            name_query_fields(&result).unwrap(),
            vec![b"1".as_slice(), b"0", b"9", b"0", b"1", b"1"]
        );
        let input = frame(&[b"alt.test", b"100", b"1", b"50", b"2", b"30"]);
        let result = runner_release_build(4, &input, 65536).unwrap();
        assert_eq!(name_query_fields(&result).unwrap().len(), 4);
    }
    #[test]
    fn cleaning_wave18_abi_atomic_errors() {
        unsafe {
            let mut out = [77i64; 5];
            let input = [101i64, 1, 2, 1];
            assert_eq!(
                nntmux_release_creation_values(0, input.as_ptr(), 4, out.as_mut_ptr(), 4),
                -2
            );
            assert_eq!(out, [77; 5]);
            assert_eq!(
                nntmux_release_creation_values(0, input.as_ptr(), 4, out.as_mut_ptr(), 5),
                5
            );
            assert_eq!(out, [100, 0, 1, 1, 1]);
            assert_eq!(
                nntmux_release_creation_values(1, [i64::MAX, 1].as_ptr(), 2, out.as_mut_ptr(), 5),
                -3
            );
            assert_eq!(
                nntmux_release_creation_values(0, std::ptr::null(), 4, out.as_mut_ptr(), 5),
                -1
            );
            let unaligned = (input.as_ptr() as *const u8).add(1) as *const i64;
            assert_eq!(
                nntmux_release_creation_values(0, unaligned, 4, out.as_mut_ptr(), 5),
                -1
            );
            assert_eq!(
                nntmux_release_creation_values(0, input.as_ptr(), usize::MAX, out.as_mut_ptr(), 5),
                -1
            );
            let input = frame(&[b"1"]);
            let mut bytes = [77u8; 4];
            assert_eq!(
                nntmux_runner_release_transform(
                    2,
                    input.as_ptr(),
                    input.len(),
                    bytes.as_mut_ptr(),
                    4
                ),
                -2
            );
            assert_eq!(bytes, [77; 4]);
            assert_eq!(
                nntmux_runner_release_transform(2, std::ptr::null(), 1, bytes.as_mut_ptr(), 4),
                -1
            );
            assert_eq!(
                nntmux_runner_release_transform(
                    2,
                    input.as_ptr(),
                    input.len(),
                    std::ptr::null_mut(),
                    1
                ),
                -1
            );
        }
    }
}

/// Reject malformed regions before creating slices or dereferencing scalar outputs.
/// Allocation validity and aliasing remain the unsafe caller's responsibility.
#[inline]
fn cleaning_ffi_region_valid<T>(ptr: *const T, len: usize) -> bool {
    let Some(bytes) = len.checked_mul(std::mem::size_of::<T>()) else {
        return false;
    };
    !ptr.is_null()
        && ptr.is_aligned()
        && bytes <= isize::MAX as usize
        && (ptr as usize).checked_add(bytes).is_some()
}

// Wave 19.1: pattern catalogs and NameFixResult pure helpers.
const NAME_FIX_APP_PATTERNS: &[(&[u8], &[u8])] = &[
    (b"WITH_KEYGEN", br#"/\w[\-\w.',;& ]+(\d{1,10}|v\d+[\.\d]*|Linux|UNIX|MacOS)[._ -](RPM|DEB)?[._ -]?(X64|X86|ARM64)?[._ -]?(Incl|With)?[._ -]?(Keygen|Patch|Crack|Serial|License)[\-\w.',;& ]+\w/i"#),
    (b"WINDOWS", br#"/\w[\-\w.',;& ]+\d{1,8}[._ -](winall|win32|win64|x64|x86)[._ -]?(freeware|portable|repack)?[\-\w.',;& ]+\w/i"#),
    (b"MACOS", br#"/\w[\-\w.',;& ]+(MacOS|Mac[._ -]?OS[._ -]?X|OSX)[._ -][\-\w.',;& ]+\w/i"#),
    (b"LINUX", br#"/\w[\-\w.',;& ]+(Linux|Ubuntu|Debian|CentOS|RHEL|Fedora)[._ -](x64|x86|arm64)?[\-\w.',;& ]+\w/i"#),
    (b"ADOBE", br#"/\w[\-\w.',;& ]*(Adobe|Photoshop|Illustrator|Premiere|After[._ -]?Effects|InDesign|Lightroom)[._ -][\-\w.',;& ]+\w/i"#),
    (b"MICROSOFT", br#"/\w[\-\w.',;& ]*(Microsoft|Office|Windows|Visual[._ -]?Studio)[._ -]\d{2,4}[._ -][\-\w.',;& ]+\w/i"#),
    (b"WITH_VERSION", br#"/\w[\-\w.',;& ]+[._ -]v?\d+[\.\d]+[._ -](Multilingual|MULTi|Portable|Repack|Cracked)[\-\w.',;& ]+\w/i"#),
];

const NAME_FIX_GAME_PATTERNS: &[(&[u8], &[u8])] = &[
    (b"MODERN_CONSOLE", br#"/\w[\-\w.',;& ]+(NSW|PS[345P]|PSV|XBSX|XSX|XBOX[._ -]?SERIES[._ -]?[XS]|XBOX[._ -]?ONE|XBOX360?|WiiU?|Switch)[._ -](INTERNAL|PROPER|READNFO|READ[._ -]?NFO|MULTI\d{1,2})?[._ -]?[\-\w.',;& ]+\-[A-Za-z0-9]+$/i"#),
    (b"REGION_CONSOLE", br#"/\w[\-\w.',;& ]+(ASIA|DLC|EUR|GOTY|JPN|KOR|MULTI\d{1}|NTSCU?|PAL|RF|Region[._ -]?Free|USA|XBLA)[._ -](DLC[._ -]Complete|FRENCH|GERMAN|MULTI\d{1}|PROPER|PSN|READ[._ -]?NFO|UMD)?[._ -]?(GC|NDS|NGC|PS[345P]|PSP|PSV|Switch|NSW|Wii(U)?|XBOX(360|ONE|SERIES)?|XBSX)[\-\w.',;& ]+\w/i"#),
    (b"CONSOLE_SCENE_GROUP", br#"/\w[\-\w.',;& ]+(GC|NDS|NGC|PS[345P]|Switch|NSW|Wii(U)?|XBOX(360|ONE|SERIES)?|XBSX)[._ -](CODEX|DUPLEX|PLAZA|SKIDROW|RELOADED|CPY|EMPRESS|RAZOR1911|HOODLUM|DARKSiDERS|FLT|TiNYiSO|ANOMALY|iNSOMNi|OneUp|STRANGE|SWAG|SKY|SUXXORS)[\-\w.',;& ]+\w/i"#),
    (b"PC_SCENE_GROUP", br#"/\w[\-\w.',;& ]+(PC|WIN(32|64)?|MAC(OSX?)?|LINUX)[._ -]?(CODEX|SKIDROW|RELOADED|CPY|EMPRESS|RAZOR1911|HOODLUM|DARKSiDERS|FLT|GOG|PROPHET|TiNYiSO|PLAZA|P2P|SiMPLEX|rG)[\-\w.',;& ]+\w/i"#),
    (b"DLC_UPDATE", br#"/\w[\-\w.',;& ]+(DLC|Update|Patch|Hotfix)[._ -](v?\d+[\.\d]*)?[._ -]?(CODEX|SKIDROW|RELOADED|PLAZA|EMPRESS|FLT|GOG|P2P|TiNYiSO)[\-\w.',;& ]+\w/i"#),
    (b"OUTLAWS", br#"/\w[\w.',;-].+-OUTLAWS/i"#),
    (b"ALIAS", br#"/\w[\w.',;-].+\-ALiAS/i"#),
    (b"GOG", br#"/\w[\-\w.',;& ]+[._ -]GOG[._ -]?(Classic|Galaxy)?[\-\w.',;& ]+\w/i"#),
    (b"REPACK", br#"/\w[\-\w.',;& ]+[._ -](REPACK|RIP)[._ -](FitGirl|DODI|xatab|R\.G\.|Mechanics)[\-\w.',;& ]+\w/i"#),
];

const NAME_FIX_MOVIE_PATTERNS: &[(&[u8], &[u8])] = &[
    (b"UHD_HDR", br#"/\w[\-\w.',;& ]+((19|20)\d\d)[._ -](2160p|4K|UHD)[._ -](HDR10\+?|DV|Dolby[._ -]?Vision|HLG)?[._ -]?(REMUX|BluRay|WEB-?DL|WEB-?RIP|UHD[._ -]?BluRay)[._ -](HEVC|x265|H\.?265|AV1)[._ -]?(Atmos|DTS[._ -]?(HD)?|TrueHD)?[\-\w.',;& ]+\w/i"#),
    (b"UHD_REMUX", br#"/\w[\-\w.',;& ]+((19|20)\d\d)[._ -](2160p|4K)[._ -](REMUX|Complete[._ -]?UHD)[._ -](HEVC|x265|H\.?265)[\-\w.',;& ]+\w/i"#),
    (b"STREAMING_4K", br#"/\w[\-\w.',;& ]+((19|20)\d\d)[._ -](2160p|4K)[._ -](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|VUDU)[._ -](WEB-?DL|WEB-?RIP)[._ -](HDR10\+?|DV)?[._ -]?(HEVC|x265|H\.?265)[\-\w.',;& ]+\w/i"#),
    (b"STREAMING_HD", br#"/\w[\-\w.',;& ]+((19|20)\d\d)[._ -](1080p|720p)[._ -](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|VUDU)[._ -](WEB-?DL|WEB-?RIP)[._ -](x264|x265|H\.?264|H\.?265|HEVC)[\-\w.',;& ]+\w/i"#),
    (b"YEAR_RES_VCODEC", br#"/\w[\-\w.',;& ]+((19|20)\d\d)[\-\w.',;& ]+(480|720|1080|2160)[ip]?[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.',;& ]+\w/i"#),
    (b"YEAR_SOURCE_VCODEC_RES", br#"/\w[\-\w.',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[._ -](480|720|1080|2160)[ip]?[\-\w.',;& ]+\w/i"#),
    (b"YEAR_SOURCE_VCODEC_ACODEC", br#"/\w[\-\w.',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3|FLAC)[\-\w.',;& ]+\w/i"#),
    (b"RES_SOURCE_ACODEC_VCODEC", br#"/\w[\-\w.',;& ]+(480|720|1080|2160)[ip]?[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3|FLAC)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.',;& ]+\w/i"#),
    (b"RES_ACODEC_SOURCE_YEAR", br#"/\w[\-\w.',;& ]+(480|720|1080|2160)[ip]?[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3|FLAC)[\-\w.',;& ]+(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -]((19|20)\d\d)[\-\w.',;& ]+\w/i"#),
    (b"MULTI_LANGUAGE", br#"/\w[\-\w.',;& ]+(Brazilian|Chinese|Croatian|Danish|Deutsch|Dutch|Estonian|English|Finnish|Flemish|Francais|French|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish|MULTi)[._ -]((19|20)\d\d)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3|FLAC)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[\-\w.',;& ]+\w/i"#),
    (b"GENERIC", br#"/\w[\-\w.',;& ]+((19|20)\d\d)[\-\w.',;& ]+(480|720|1080|2160)[ip]?[\-\w.',;& ]+(BluRay|BDRip|DVDRip|HDTV|WEB-?DL|WEB-?RIP)[\-\w.',;& ]+\w/i"#),
];

const NAME_FIX_TV_PATTERNS: &[(&[u8], &[u8])] = &[
    (b"STREAMING_4K_HDR", br#"/\w[\-\w.',;& ]+((s\d{1,2}[._ -]?e\d{1,3}(-?e\d{1,3})?)|(?<!\d)[S|]\d{1,2}[E|x]\d{1,}(?!\d)|ep[._ -]?\d{2,3})[._ -](2160p|4K|UHD)[._ -](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|VUDU)[._ -](WEB-?DL|WEB-?RIP)[._ -](HDR10?\+?|DV|Dolby[._ -]?Vision)?[._ -]?(HEVC|x265|H\.?265)[\-\w.',;& ]+\w/i"#),
    (b"STREAMING_HD", br#"/\w[\-\w.',;& ]+((s\d{1,2}[._ -]?e\d{1,3}(-?e\d{1,3})?)|(?<!\d)[S|]\d{1,2}[E|x]\d{1,}(?!\d)|ep[._ -]?\d{2,3})[._ -](1080p|720p)[._ -](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|VUDU)[._ -](WEB-?DL|WEB-?RIP)[\-\w.',;& ]+\w/i"#),
    (b"TV_RES_SOURCE_VCODEC", br#"/\w[\-\w.',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2,3})[\-\w.',;& ]+(480|720|1080|2160)[ip]?[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.',;& ]+\w/i"#),
    (b"TV_AUDIO_SOURCE_RES_VCODEC", br#"/\w[\-\w.',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2})[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](480|720|1080|2160)[ip]?[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.',;& ]+\w/i"#),
    (b"TV_SOURCE_VCODEC", br#"/\w[\-\w.',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2})[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.',;& ]+\w/i"#),
    (b"TV_SOURCE_GROUP", br#"/\w[\-\w.',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)[S|]\d{1,2}[E|x]\d{1,}(?!\d)|ep[._ -]?\d{2})[\-\w.',;.()]+(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -][\-\w.',;& ]+\w/i"#),
    (b"TV_YEAR_SEASON", br#"/\w[\-\w.',;& ]+((19|20)\d\d)[._ -]((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2})[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[\-\w.',;& ]+\w/i"#),
    (b"TV_WITH_YEAR", br#"/\w[\-\w.',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2})[\-\w.',;& ]+((19|20)\d\d)[\-\w.',;& ]+\w/i"#),
    (b"DAILY_SHOW", br#"/\w[\-\w.',;& ]+(19|20)\d\d[._ -]\d{2}[._ -]\d{2}[._ -](720p|1080p|2160p|HDTV|WEB-?DL|WEB-?RIP)[\-\w.',;& ]+\w/i"#),
    (b"SPORTS", br#"/\w(19|20)\d\d[._ -]\d{2}[._ -]\d{2}[._ -](IndyCar|F1|Formula[._ -]?1|MotoGP|NBA|NCW([TY])S|NNS|NSCS?|NFL|NHL|MLB|UFC|WWE|Boxing)([._ -](19|20)\d\d)?[\-\w.',;& ]+\w/i"#),
    (b"COMPLETE_SEASON", br#"/\w[\-\w.',;& ]+[._ -]S\d{1,2}[._ -](COMPLETE|FULL)[._ -](720p|1080p|2160p|HDTV|WEB-?DL|WEB-?RIP|BluRay)[\-\w.',;& ]+\w/i"#),
    (b"ANIME", br#"/\w[\-\w.',;& ]+[._ -](\d{2,4})[._ -](480p|720p|1080p|2160p)[._ -](HEVC|x265|x264|H\.?264)[._ -](10bit)?[\-\w.',;& ]+\w/i"#),
];

fn name_fix_patterns(kind: u32) -> Option<&'static [(&'static [u8], &'static [u8])]> {
    match kind {
        0 => Some(NAME_FIX_APP_PATTERNS),
        1 => Some(NAME_FIX_GAME_PATTERNS),
        2 => Some(NAME_FIX_MOVIE_PATTERNS),
        3 => Some(NAME_FIX_TV_PATTERNS),
        _ => None,
    }
}

fn name_fix_copy(value: &[u8], out: *mut u8, cap: usize) -> isize {
    if value.len() > cap {
        return -2;
    }
    // All callers have validated the output allocation and byte extent. The
    // value is separately allocated or static, so it cannot overlap `out`.
    if !value.is_empty() {
        unsafe { std::ptr::copy_nonoverlapping(value.as_ptr(), out, value.len()) };
    }
    value.len() as isize
}

/// Catalog modes: 0 = alternating key/regex fields; 1 = priority key fields;
/// 2 = count (return value); 3 = exact case-sensitive regex lookup;
/// 4 = zero-based priority key lookup. Fields are u32 LE length + raw bytes.
/// Returns bytes/count, -1 for malformed arguments/panic, -2 for capacity,
/// or -3 for unknown key/index. Errors do not modify the caller's output.
///
/// # Safety
/// Every pointer must be non-null/aligned and valid for its stated byte extent,
/// even for zero lengths. Allocation validity remains the caller's obligation.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_name_fixing_patterns(
    kind: u32,
    mode: u32,
    key: *const u8,
    key_len: usize,
    index: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(key, key_len) || !cleaning_ffi_region_valid(out, cap) {
            return -1;
        }
        let Some(patterns) = name_fix_patterns(kind) else {
            return -1;
        };
        let key = unsafe { slice::from_raw_parts(key, key_len) };
        match mode {
            0 | 1 => {
                let mut output = RunnerReleaseOutput {
                    bytes: Vec::new(),
                    cap,
                };
                for &(name, pattern) in patterns {
                    if let Err(code) = output.field(name) {
                        return code;
                    }
                    if mode == 0 {
                        if let Err(code) = output.field(pattern) {
                            return code;
                        }
                    }
                }
                name_fix_copy(&output.bytes, out, cap)
            }
            2 => patterns.len() as isize,
            3 => patterns
                .iter()
                .find(|&&(name, _)| name == key)
                .map_or(-3, |&(_, pattern)| name_fix_copy(pattern, out, cap)),
            4 => patterns
                .get(index)
                .map_or(-3, |&(name, _)| name_fix_copy(name, out, cap)),
            _ => -1,
        }
    }))
    .unwrap_or(-1)
}

fn name_fix_result_build(
    mode: u32,
    input: &[u8],
    predb_id: i64,
    matched: u32,
    cap: usize,
) -> Result<Vec<u8>, isize> {
    let fields = name_query_fields(input)?;
    let mut output = RunnerReleaseOutput {
        bytes: Vec::new(),
        cap,
    };
    match mode {
        0 | 2 => {
            if fields.len() != 3 {
                return Err(-1);
            }
            let has_match = mode == 0 || matched == 1;
            output.field(if has_match { fields[0] } else { b"" })?;
            output.field(if has_match { fields[1] } else { b"" })?;
            output.field(fields[2])?;
            output.number(if has_match { predb_id } else { 0 })?;
        }
        1 => {
            if fields.len() != 1 {
                return Err(-1);
            }
            for value in [b"".as_slice(), b"", fields[0], b"0"] {
                output.field(value)?;
            }
        }
        4 => {
            if fields.len() % 2 != 0 {
                return Err(-1);
            }
            // Preserve raw string keys/values; PHP owns non-string values and
            // Spatie transformation contexts. Re-emit validated framed pairs.
            for field in fields {
                output.field(field)?;
            }
        }
        5 => {
            if fields.len() != 3 {
                return Err(-1);
            }
            let values = if fields[0].is_empty() {
                vec![fields[2], b": no match".as_slice()]
            } else {
                vec![fields[2], b": ", fields[1], b" => ", fields[0]]
            };
            let size = values
                .iter()
                .try_fold(0usize, |size, value| size.checked_add(value.len()))
                .ok_or(-1isize)?;
            if size > cap {
                return Err(-2);
            }
            output.bytes.try_reserve(size).map_err(|_| -3isize)?;
            for value in values {
                output.bytes.extend_from_slice(value);
            }
        }
        _ => return Err(-1),
    }
    Ok(output.bytes)
}

/// NameFixResult modes: 0 = matched; 1 = unmatched; 2 = nullable check result;
/// 3 = nonempty raw name predicate (return 0/1); 4 = string-pair array projection;
/// 5 = summary. Factory inputs/outputs are framed as documented above. Floating
/// confidence, metadata, object identity and Spatie contexts remain owned by PHP.
/// Errors (-1 malformed/panic, -2 capacity, -3 allocation) never modify output.
///
/// # Safety
/// Every pointer must be non-null/aligned and valid for its stated byte extent,
/// including zero lengths. Input and output may overlap: output is built first.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_name_fix_result(
    mode: u32,
    input: *const u8,
    len: usize,
    predb_id: i64,
    matched: u32,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(input, len)
            || !cleaning_ffi_region_valid(out, cap)
            || matched > 1
        {
            return -1;
        }
        let input = unsafe { slice::from_raw_parts(input, len) };
        if mode == 3 {
            return isize::from(!input.is_empty());
        }
        match name_fix_result_build(mode, input, predb_id, matched, cap) {
            Ok(bytes) => name_fix_copy(&bytes, out, cap),
            Err(code) => code,
        }
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod cleaning_wave19_name_fix_tests {
    use super::*;

    fn frames(fields: &[&[u8]]) -> Vec<u8> {
        let mut output = RunnerReleaseOutput {
            bytes: Vec::new(),
            cap: 65536,
        };
        for field in fields {
            output.field(field).unwrap();
        }
        output.bytes
    }

    #[test]
    fn catalog_preserves_priority_count_bytes_and_lookup_boundaries() {
        for (kind, count, first, last) in [
            (0, 7, b"WITH_KEYGEN".as_slice(), b"WITH_VERSION".as_slice()),
            (1, 9, b"MODERN_CONSOLE", b"REPACK"),
            (2, 11, b"UHD_HDR", b"GENERIC"),
            (3, 12, b"STREAMING_4K_HDR", b"ANIME"),
        ] {
            let patterns = name_fix_patterns(kind).unwrap();
            assert_eq!(patterns.len(), count);
            assert_eq!(patterns[0].0, first);
            assert_eq!(patterns[count - 1].0, last);
            let mut out = [0u8; 32768];
            unsafe {
                let size = nntmux_name_fixing_patterns(
                    kind,
                    0,
                    b"".as_ptr(),
                    0,
                    0,
                    out.as_mut_ptr(),
                    out.len(),
                );
                assert!(size > 0);
                let catalog = out[..size as usize].to_vec();
                let fields = name_query_fields(&catalog).unwrap();
                for (index, &(name, pattern)) in patterns.iter().enumerate() {
                    assert_eq!(fields[index * 2], name);
                    assert_eq!(fields[index * 2 + 1], pattern);
                    let size = nntmux_name_fixing_patterns(
                        kind,
                        3,
                        name.as_ptr(),
                        name.len(),
                        0,
                        out.as_mut_ptr(),
                        out.len(),
                    );
                    assert_eq!(&out[..size as usize], pattern);
                }
                assert_eq!(
                    nntmux_name_fixing_patterns(kind, 2, b"".as_ptr(), 0, 0, out.as_mut_ptr(), 0),
                    count as isize
                );
                assert_eq!(
                    nntmux_name_fixing_patterns(
                        kind,
                        3,
                        b"unknown\0\xff".as_ptr(),
                        9,
                        0,
                        out.as_mut_ptr(),
                        out.len()
                    ),
                    -3
                );
                assert_eq!(
                    nntmux_name_fixing_patterns(
                        kind,
                        4,
                        b"".as_ptr(),
                        0,
                        count,
                        out.as_mut_ptr(),
                        out.len()
                    ),
                    -3
                );
            }
        }
    }

    #[test]
    fn factories_and_summaries_preserve_binary_empty_names_and_signed_ids() {
        let input = frames(&[b"0\0\xff", b"method\n", "番組".as_bytes()]);
        for id in [i64::MIN, -1, 0, 1, i64::MAX] {
            let result = name_fix_result_build(0, &input, id, 1, 65536).unwrap();
            let fields = name_query_fields(&result).unwrap();
            assert_eq!(
                fields,
                vec![
                    b"0\0\xff".as_slice(),
                    b"method\n",
                    "番組".as_bytes(),
                    id.to_string().as_bytes()
                ]
            );
            assert_eq!(
                name_fix_result_build(2, &input, id, 1, 65536).unwrap(),
                result
            );
        }
        assert_eq!(
            name_fix_result_build(5, &input, 0, 0, 65536).unwrap(),
            "番組: method\n => 0\0"
                .as_bytes()
                .iter()
                .copied()
                .chain([0xff])
                .collect::<Vec<_>>()
        );
        let unmatched = name_fix_result_build(1, &frames(&[b"checker"]), 123, 1, 65536).unwrap();
        assert_eq!(
            name_query_fields(&unmatched).unwrap(),
            vec![b"".as_slice(), b"", b"checker", b"0"]
        );
        assert_eq!(
            name_fix_result_build(2, &frames(&[b"name", b"method", b"checker"]), 123, 0, 65536)
                .unwrap(),
            unmatched
        );
        assert_eq!(
            name_fix_result_build(5, &frames(&[b"", b"method", b"checker"]), 0, 0, 65536).unwrap(),
            b"checker: no match"
        );
        let pairs = frames(&[b"newName", b"0\0\xff", b"checkerName", "番組".as_bytes()]);
        assert_eq!(
            name_fix_result_build(4, &pairs, 0, 0, pairs.len()).unwrap(),
            pairs
        );
        assert_eq!(name_fix_result_build(4, &[], 0, 0, 0).unwrap(), b"");
    }

    #[test]
    fn abi_rejects_bad_regions_frames_flags_and_preserves_output_on_failure() {
        let mut out = [0x5au8; 32];
        let input = frames(&[b"name", b"method", b"checker"]);
        unsafe {
            for mode in 0..=5 {
                assert_eq!(
                    nntmux_name_fix_result(
                        mode,
                        std::ptr::null(),
                        0,
                        0,
                        0,
                        out.as_mut_ptr(),
                        out.len()
                    ),
                    -1
                );
                assert_eq!(
                    nntmux_name_fix_result(
                        mode,
                        input.as_ptr(),
                        usize::MAX,
                        0,
                        0,
                        out.as_mut_ptr(),
                        out.len()
                    ),
                    -1
                );
                assert_eq!(
                    nntmux_name_fix_result(
                        mode,
                        input.as_ptr(),
                        input.len(),
                        0,
                        0,
                        std::ptr::null_mut(),
                        0
                    ),
                    -1
                );
                assert_eq!(
                    nntmux_name_fix_result(
                        mode,
                        input.as_ptr(),
                        input.len(),
                        0,
                        2,
                        out.as_mut_ptr(),
                        out.len()
                    ),
                    -1
                );
            }
            for mode in [0, 1, 2, 4, 5] {
                assert_eq!(
                    nntmux_name_fix_result(
                        mode,
                        b"\xff\xff\xff\xff".as_ptr(),
                        4,
                        0,
                        0,
                        out.as_mut_ptr(),
                        out.len()
                    ),
                    -1
                );
            }
            assert_eq!(
                nntmux_name_fix_result(5, input.as_ptr(), input.len(), 0, 1, out.as_mut_ptr(), 1),
                -2
            );
            assert_eq!(
                nntmux_name_fix_result(3, b"".as_ptr(), 0, 0, 0, out.as_mut_ptr(), 0),
                0
            );
            assert_eq!(
                nntmux_name_fix_result(3, b"0".as_ptr(), 1, 0, 0, out.as_mut_ptr(), 0),
                1
            );
            assert_eq!(
                nntmux_name_fixing_patterns(0, 0, b"".as_ptr(), 0, 0, out.as_mut_ptr(), 1),
                -2
            );
            assert_eq!(
                nntmux_name_fixing_patterns(0, 0, b"".as_ptr(), 0, 0, out.as_mut_ptr(), usize::MAX),
                -1
            );
            assert_eq!(
                nntmux_name_fixing_patterns(
                    0,
                    0,
                    std::ptr::null(),
                    0,
                    0,
                    out.as_mut_ptr(),
                    out.len()
                ),
                -1
            );
        }
        assert_eq!(out, [0x5a; 32]);
        // u8 pointers have alignment 1; typed ABI rejection is covered by the
        // actual i64/i32 exports in strict_cleaning_abi_tests.
    }

    #[test]
    fn result_abi_accepts_exact_capacity_and_overlapping_input_output() {
        let mut input = frames(&[b"newName", b"bytes\0\xff"]);
        let expected = input.clone();
        let pointer = input.as_mut_ptr();
        unsafe {
            assert_eq!(
                nntmux_name_fix_result(4, pointer, input.len(), 0, 0, pointer, input.len()),
                input.len() as isize
            );
        }
        assert_eq!(input, expected);
        for mode in [0, 1, 2, 5] {
            let input = if mode == 1 {
                frames(&[b"checker"])
            } else {
                frames(&[b"name", b"method", b"checker"])
            };
            let expected = name_fix_result_build(mode, &input, i64::MIN, 1, 65536).unwrap();
            let mut out = vec![0x5a; expected.len()];
            unsafe {
                assert_eq!(
                    nntmux_name_fix_result(
                        mode,
                        input.as_ptr(),
                        input.len(),
                        i64::MIN,
                        1,
                        out.as_mut_ptr(),
                        out.len() - 1
                    ),
                    -2
                );
                assert!(out.iter().all(|&b| b == 0x5a));
                assert_eq!(
                    nntmux_name_fix_result(
                        mode,
                        input.as_ptr(),
                        input.len(),
                        i64::MIN,
                        1,
                        out.as_mut_ptr(),
                        out.len()
                    ),
                    expected.len() as isize
                );
            }
            assert_eq!(out, expected);
        }
    }
}

#[cfg(test)]
mod wave20_tests {
    use super::*;

    type Export = unsafe extern "C" fn(u32, *const u8, usize, *mut u8, usize) -> isize;

    fn frames(values: &[&[u8]]) -> Vec<u8> {
        let mut bytes = Vec::new();
        for value in values {
            bytes.extend_from_slice(&(value.len() as u32).to_le_bytes());
            bytes.extend_from_slice(value);
        }
        bytes
    }

    fn check(export: Export, mode: u32, input: &[u8], expected: &[u8]) {
        let mut output = vec![0x5a; expected.len() + 1];
        unsafe {
            if !expected.is_empty() {
                assert_eq!(
                    export(
                        mode,
                        input.as_ptr(),
                        input.len(),
                        output.as_mut_ptr(),
                        expected.len() - 1
                    ),
                    -2
                );
                assert!(output.iter().all(|&byte| byte == 0x5a));
            }
            assert_eq!(
                export(
                    mode,
                    input.as_ptr(),
                    input.len(),
                    output.as_mut_ptr(),
                    expected.len()
                ),
                expected.len() as isize
            );
        }
        assert_eq!(&output[..expected.len()], expected);
        assert_eq!(output[expected.len()], 0x5a);
        // Shift both byte pointers by one: alignment 1 accepts these addresses.
        let mut shared = vec![0x5a; input.len().max(expected.len()) + 2];
        shared[1..input.len() + 1].copy_from_slice(input);
        let pointer = unsafe { shared.as_mut_ptr().add(1) };
        unsafe {
            assert_eq!(
                export(mode, pointer, input.len(), pointer, expected.len()),
                expected.len() as isize
            );
        }
        assert_eq!(&shared[1..expected.len() + 1], expected);
    }

    fn reject(export: Export, mode: u32, input: &[u8]) {
        let mut output = [0x5a; 256];
        unsafe {
            assert_eq!(
                export(
                    mode,
                    input.as_ptr(),
                    input.len(),
                    output.as_mut_ptr(),
                    output.len()
                ),
                -1
            );
        }
        assert_eq!(output, [0x5a; 256]);
    }

    #[test]
    fn backfill_modes_preserve_flags_date_and_independent_repair() {
        check(nntmux_wave20_backfill, 0, b"0", b"backfill");
        check(nntmux_wave20_backfill, 0, b"1", b"update");
        for repair in [b"0".as_slice(), b"1"] {
            for disable in [b"0".as_slice(), b"1"] {
                let date = b"2026-10-24\0\xff";
                let input = frames(&[b"1", b"0", date, repair, disable]);
                let resolved: &[u8] = if repair == b"1" {
                    b"update"
                } else {
                    b"backfill"
                };
                check(
                    nntmux_wave20_backfill,
                    1,
                    &input,
                    &frames(&[b"1", b"0", date, resolved, disable]),
                );
            }
        }
        for flag_index in [0, 1, 3, 4] {
            let mut values: [&[u8]; 5] = [b"0", b"0", b"", b"0", b"0"];
            values[flag_index] = b"2";
            reject(nntmux_wave20_backfill, 1, &frames(&values));
        }
        reject(nntmux_wave20_backfill, 0, b"");
        reject(nntmux_wave20_backfill, 0, b"true");
        reject(nntmux_wave20_backfill, 1, &frames(&[b"0"]));
    }

    #[test]
    fn group_modes_dedupe_byte_names_and_build_inactive_defaults() {
        let max = vec![b'x'; 255];
        let too_long = vec![b'x'; 256];
        let input = frames(&[b"a", b"a", b"A", b"", b"a\0\xff", &max, &too_long, b""]);
        check(
            nntmux_wave20_group,
            0,
            &input,
            &frames(&[b"a", b"A", b"", b"a\0\xff", &max]),
        );
        check(nntmux_wave20_group, 0, b"", b"");
        for name in [b"".as_slice(), b"a\0\xff"] {
            check(
                nntmux_wave20_group,
                1,
                name,
                &frames(&[
                    name,
                    b"Added by Release processing",
                    b"1",
                    b"0",
                    b"0",
                    b"0",
                    b"0",
                    b"",
                    b"",
                ]),
            );
        }
    }

    #[test]
    fn recat_modes_match_snapshot_hex_and_category_predicate() {
        check(nntmux_wave20_recat, 0, b"", b"1");
        check(
            nntmux_wave20_recat,
            0,
            &frames(&[b"-1", b"a\0\xff", b"-1", b"a\0\xff"]),
            b"1",
        );
        check(
            nntmux_wave20_recat,
            0,
            &frames(&[b"1", b"a", b"2", b"a"]),
            b"0",
        );
        check(
            nntmux_wave20_recat,
            0,
            &frames(&[b"1", b"a", b"1", b"A"]),
            b"0",
        );
        check(nntmux_wave20_recat, 1, b"\0\xffAz", b"00FF417A");
        check(nntmux_wave20_recat, 1, b"", b"");
        for (current, new, categorized, result) in [
            (
                b"1".as_slice(),
                b"1".as_slice(),
                b"1".as_slice(),
                b"1".as_slice(),
            ),
            (b"1", b"1", b"0", b"0"),
            (b"1", b"2", b"1", b"0"),
            (b"-1", b"-1", b"2", b"0"),
        ] {
            check(
                nntmux_wave20_recat,
                2,
                &frames(&[current, new, categorized]),
                result,
            );
        }
        for invalid in [
            b"01".as_slice(),
            b"-0",
            b"+1",
            b"",
            b"9223372036854775808",
            b"-9223372036854775809",
            b"1\0",
        ] {
            reject(
                nntmux_wave20_recat,
                0,
                &frames(&[invalid, b"a", b"1", b"a"]),
            );
            reject(nntmux_wave20_recat, 2, &frames(&[b"1", invalid, b"0"]));
        }
        check(
            nntmux_wave20_recat,
            2,
            &frames(&[b"-9223372036854775808", b"-9223372036854775808", b"1"]),
            b"1",
        );
        reject(nntmux_wave20_recat, 0, &frames(&[b"1"]));
        reject(nntmux_wave20_recat, 2, b"");
    }

    #[test]
    fn report_reasons_match_php_trim_empty_ucfirst_and_label_deduplication() {
        check(nntmux_wave20_report, 0, b"", b"Unknown");
        check(nntmux_wave20_report, 0, b"0", b"Unknown");
        check(nntmux_wave20_report, 0, b"duplicate,fake,password,incomplete,wrong_category,spam,other", b"Duplicate Release, Fake/Malicious Content, Password Protected, Incomplete/Corrupted, Wrong Category, Spam/Advertisement, Other");
        check(
            nntmux_wave20_report,
            0,
            b" \t\n\r\0\x0bspam\0,spam,other,Other,unknown,Unknown",
            b"Spam/Advertisement, Other, Unknown",
        );
        check(nntmux_wave20_report, 0, b" ,,, ", b"");
        check(
            nntmux_wave20_report,
            0,
            b"\x0cspam\x0c,\xfffoo,a\0b,0",
            b"\x0cspam\x0c, \xfffoo, A\0b, 0",
        );
    }

    #[test]
    fn report_stats_include_unknown_statuses_and_checked_nonnegative_counts() {
        check(
            nntmux_wave20_report,
            1,
            b"",
            &frames(&[b"0", b"0", b"0", b"0", b"0"]),
        );
        check(
            nntmux_wave20_report,
            1,
            &frames(&[b"pending", b"2", b"resolved", b"3", b"other\0\xff", b"4"]),
            &frames(&[b"2", b"0", b"3", b"0", b"9"]),
        );
        check(
            nntmux_wave20_report,
            1,
            &frames(&[b"reviewed", b"0", b"dismissed", b"9223372036854775807"]),
            &frames(&[
                b"0",
                b"0",
                b"0",
                b"9223372036854775807",
                b"9223372036854775807",
            ]),
        );
        for invalid in [
            b"-1".as_slice(),
            b"01",
            b"+1",
            b"",
            b"9223372036854775808",
            b"1\0",
        ] {
            reject(nntmux_wave20_report, 1, &frames(&[b"pending", invalid]));
        }
        reject(nntmux_wave20_report, 1, &frames(&[b"pending"]));
        reject(
            nntmux_wave20_report,
            1,
            &frames(&[b"pending", b"9223372036854775807", b"other", b"1"]),
        );
    }

    #[test]
    fn file_modes_validate_hash_and_snapshot_eligibility() {
        check(
            nntmux_wave20_file,
            0,
            b"0123456789ABCDEFabcdef0123456789",
            b"0123456789abcdefabcdef0123456789",
        );
        for hash in [
            b"".as_slice(),
            b"0123456789ABCDEFabcdef0123456789A",
            b"g123456789ABCDEFabcdef0123456789AB",
            b"\0\xff",
        ] {
            check(nntmux_wave20_file, 0, hash, b"");
        }
        for (has, predb, renamed, expected) in [
            (
                b"1".as_slice(),
                b"0".as_slice(),
                b"0".as_slice(),
                b"1".as_slice(),
            ),
            (b"1", b"0", b"1", b"1"),
            (b"0", b"0", b"0", b"0"),
            (b"1", b"1", b"0", b"0"),
            (b"1", b"0", b"2", b"0"),
            (b"1", b"-1", b"0", b"0"),
        ] {
            check(
                nntmux_wave20_file,
                1,
                &frames(&[has, predb, renamed]),
                expected,
            );
        }
        reject(nntmux_wave20_file, 1, &frames(&[b"2", b"0", b"0"]));
        reject(nntmux_wave20_file, 1, &frames(&[b"1", b"00", b"0"]));
        reject(nntmux_wave20_file, 1, &frames(&[b"1", b"0", b"01"]));
    }

    #[test]
    fn all_exports_reject_invalid_regions_modes_and_malformed_frames_without_writes() {
        let exports: [Export; 5] = [
            nntmux_wave20_backfill,
            nntmux_wave20_group,
            nntmux_wave20_recat,
            nntmux_wave20_report,
            nntmux_wave20_file,
        ];
        for export in exports {
            let mut output = [0x5a; 16];
            unsafe {
                assert_eq!(export(0, std::ptr::null(), 0, output.as_mut_ptr(), 16), -1);
                assert_eq!(export(0, b"".as_ptr(), 0, std::ptr::null_mut(), 0), -1);
                assert_eq!(
                    export(0, b"".as_ptr(), usize::MAX, output.as_mut_ptr(), 16),
                    -1
                );
                assert_eq!(
                    export(0, b"".as_ptr(), 0, output.as_mut_ptr(), usize::MAX),
                    -1
                );
            }
            assert_eq!(output, [0x5a; 16]);
            reject(export, u32::MAX, b"");
        }
        for (export, mode) in [
            (nntmux_wave20_backfill as Export, 1),
            (nntmux_wave20_group as Export, 0),
            (nntmux_wave20_recat as Export, 0),
            (nntmux_wave20_recat as Export, 2),
            (nntmux_wave20_report as Export, 1),
            (nntmux_wave20_file as Export, 1),
        ] {
            for malformed in [
                b"\0".as_slice(),
                b"\0\0\0",
                b"\x01\0\0\0",
                b"\xff\xff\xff\xff",
                b"\0\0\0\0\0",
            ] {
                reject(export, mode, malformed);
            }
        }
    }
}

fn wave20_frames(mut bytes: &[u8]) -> Result<Vec<&[u8]>, isize> {
    let mut fields = Vec::new();
    while !bytes.is_empty() {
        let prefix = bytes.get(..4).ok_or(-1isize)?;
        let length = u32::from_le_bytes([prefix[0], prefix[1], prefix[2], prefix[3]]) as usize;
        bytes = &bytes[4..];
        fields.push(bytes.get(..length).ok_or(-1isize)?);
        bytes = &bytes[length..];
    }
    Ok(fields)
}

fn wave20_encode(fields: &[&[u8]]) -> Result<Vec<u8>, isize> {
    let mut result = Vec::new();
    for field in fields {
        let length = u32::try_from(field.len()).map_err(|_| -1isize)?;
        let size = result
            .len()
            .checked_add(4)
            .and_then(|size| size.checked_add(field.len()))
            .ok_or(-1isize)?;
        if size > isize::MAX as usize {
            return Err(-1);
        }
        result.extend_from_slice(&length.to_le_bytes());
        result.extend_from_slice(field);
    }
    Ok(result)
}

fn wave20_decimal(bytes: &[u8]) -> Result<i64, isize> {
    let text = std::str::from_utf8(bytes).map_err(|_| -1isize)?;
    let value = text.parse::<i64>().map_err(|_| -1isize)?;
    if value.to_string().as_bytes() != bytes {
        return Err(-1);
    }
    Ok(value)
}

fn wave20_flag(bytes: &[u8]) -> Result<bool, isize> {
    match bytes {
        b"0" => Ok(false),
        b"1" => Ok(true),
        _ => Err(-1),
    }
}

fn wave20_bool(value: bool) -> Vec<u8> {
    if value { b"1".to_vec() } else { b"0".to_vec() }
}

fn wave20_backfill(mode: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
    match mode {
        0 => Ok(if wave20_flag(input)? {
            b"update".to_vec()
        } else {
            b"backfill".to_vec()
        }),
        1 => {
            let fields = wave20_frames(input)?;
            if fields.len() != 5 {
                return Err(-1);
            }
            wave20_flag(fields[0])?;
            wave20_flag(fields[1])?;
            let repair = wave20_flag(fields[3])?;
            wave20_flag(fields[4])?;
            let literal: &[u8] = if repair { b"update" } else { b"backfill" };
            wave20_encode(&[fields[0], fields[1], fields[2], literal, fields[4]])
        }
        _ => Err(-1),
    }
}

fn wave20_group(mode: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
    match mode {
        0 => {
            let fields = wave20_frames(input)?;
            let mut seen = std::collections::HashSet::new();
            let names: Vec<&[u8]> = fields
                .into_iter()
                .filter(|name| name.len() <= 255 && seen.insert(*name))
                .collect();
            wave20_encode(&names)
        }
        1 => wave20_encode(&[
            input,
            b"Added by Release processing",
            b"1",
            b"0",
            b"0",
            b"0",
            b"0",
            b"",
            b"",
        ]),
        _ => Err(-1),
    }
}

fn wave20_recat(mode: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
    match mode {
        0 => {
            let fields = wave20_frames(input)?;
            if fields.is_empty() {
                return Ok(wave20_bool(true));
            }
            if fields.len() != 4 {
                return Err(-1);
            }
            wave20_decimal(fields[0])?;
            wave20_decimal(fields[2])?;
            Ok(wave20_bool(
                fields[0] == fields[2] && fields[1] == fields[3],
            ))
        }
        1 => {
            let size = input
                .len()
                .checked_mul(2)
                .filter(|&size| size <= isize::MAX as usize)
                .ok_or(-1isize)?;
            let mut result = Vec::with_capacity(size);
            const HEX: &[u8; 16] = b"0123456789ABCDEF";
            for &byte in input {
                result.push(HEX[(byte >> 4) as usize]);
                result.push(HEX[(byte & 15) as usize]);
            }
            Ok(result)
        }
        2 => {
            let fields = wave20_frames(input)?;
            if fields.len() != 3 {
                return Err(-1);
            }
            for field in &fields {
                wave20_decimal(field)?;
            }
            Ok(wave20_bool(fields[0] == fields[1] && fields[2] == b"1"))
        }
        _ => Err(-1),
    }
}

fn wave20_report(mode: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
    match mode {
        0 => {
            if input.is_empty() || input == b"0" {
                return Ok(b"Unknown".to_vec());
            }
            let mut labels = Vec::new();
            let mut seen = std::collections::HashSet::new();
            for key in input.split(|&byte| byte == b',') {
                let key = php_trim_ascii(key);
                let label = match key {
                    b"duplicate" => b"Duplicate Release".to_vec(),
                    b"fake" => b"Fake/Malicious Content".to_vec(),
                    b"password" => b"Password Protected".to_vec(),
                    b"incomplete" => b"Incomplete/Corrupted".to_vec(),
                    b"wrong_category" => b"Wrong Category".to_vec(),
                    b"spam" => b"Spam/Advertisement".to_vec(),
                    b"other" => b"Other".to_vec(),
                    _ => {
                        let mut label = key.to_vec();
                        if let Some(first) = label.first_mut() {
                            first.make_ascii_uppercase();
                        }
                        label
                    }
                };
                if seen.insert(label.clone()) {
                    labels.push(label);
                }
            }
            Ok(labels.join(b", ".as_slice()))
        }
        1 => {
            let fields = wave20_frames(input)?;
            if fields.len() % 2 != 0 {
                return Err(-1);
            }
            let mut counts: [&[u8]; 4] = [b"0"; 4];
            let mut total = 0i64;
            for pair in fields.chunks_exact(2) {
                let count = wave20_decimal(pair[1])?;
                if count < 0 {
                    return Err(-1);
                }
                total = total.checked_add(count).ok_or(-1isize)?;
                let index = match pair[0] {
                    b"pending" => Some(0),
                    b"reviewed" => Some(1),
                    b"resolved" => Some(2),
                    b"dismissed" => Some(3),
                    _ => None,
                };
                if let Some(index) = index {
                    counts[index] = pair[1];
                }
            }
            wave20_encode(&[
                counts[0],
                counts[1],
                counts[2],
                counts[3],
                total.to_string().as_bytes(),
            ])
        }
        _ => Err(-1),
    }
}

fn wave20_file(mode: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
    match mode {
        0 => Ok(
            if input.len() == 32 && input.iter().all(u8::is_ascii_hexdigit) {
                input.to_ascii_lowercase()
            } else {
                Vec::new()
            },
        ),
        1 => {
            let fields = wave20_frames(input)?;
            if fields.len() != 3 {
                return Err(-1);
            }
            let has_hash = wave20_flag(fields[0])?;
            wave20_decimal(fields[1])?;
            wave20_decimal(fields[2])?;
            Ok(wave20_bool(
                has_hash && fields[1] == b"0" && matches!(fields[2], b"0" | b"1"),
            ))
        }
        _ => Err(-1),
    }
}

unsafe fn wave20_dispatch(
    build: fn(u32, &[u8]) -> Result<Vec<u8>, isize>,
    mode: u32,
    input: *const u8,
    input_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    if !cleaning_ffi_region_valid(input, input_len) || !cleaning_ffi_region_valid(out, out_cap) {
        return -1;
    }
    // The shared region validator checks non-null pointers, alignment and byte
    // extents before any slice is created. u8 requires only alignment 1.
    let result = {
        let input = unsafe { slice::from_raw_parts(input, input_len) };
        match build(mode, input) {
            Ok(result) => result,
            Err(code) => return code,
        }
    };
    if result.len() > isize::MAX as usize {
        return -1;
    }
    if result.len() > out_cap {
        return -2;
    }
    // The result owns its allocation, and the input borrow has ended. This
    // permits callers to alias their input and output byte regions.
    if !result.is_empty() {
        unsafe { std::ptr::copy_nonoverlapping(result.as_ptr(), out, result.len()) };
    }
    result.len() as isize
}

/// Backfill modes: 0 = repair literal; 1 = five configuration frames.
/// Returns output bytes, -1 for invalid input/panic, or -2 for short capacity.
/// Errors leave output unchanged.
///
/// # Safety
/// Input and output must be non-null and valid for their stated byte extents,
/// including zero extents. Regions may overlap; both lengths must fit isize.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_wave20_backfill(
    mode: u32,
    input: *const u8,
    input_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        wave20_dispatch(wave20_backfill, mode, input, input_len, out, out_cap)
    }))
    .unwrap_or(-1)
}

/// Group modes: 0 = unique name frames of at most 255 bytes; 1 = defaults.
/// Returns output bytes, -1 for invalid input/panic, or -2 for short capacity.
///
/// # Safety
/// Pointers must be non-null and valid for their byte extents, even when zero;
/// regions may overlap and each extent must be at most isize::MAX.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_wave20_group(
    mode: u32,
    input: *const u8,
    input_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        wave20_dispatch(wave20_group, mode, input, input_len, out, out_cap)
    }))
    .unwrap_or(-1)
}

/// Recat modes: 0 = snapshot match; 1 = uppercase hex; 2 = category predicate.
/// Returns output bytes, -1 for invalid input/panic, or -2 for short capacity.
///
/// # Safety
/// Pointers must be non-null and valid for their byte extents, even when zero;
/// regions may overlap and each extent must be at most isize::MAX.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_wave20_recat(
    mode: u32,
    input: *const u8,
    input_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        wave20_dispatch(wave20_recat, mode, input, input_len, out, out_cap)
    }))
    .unwrap_or(-1)
}

/// Report modes: 0 = reason labels; 1 = four status counts and total frames.
/// Returns output bytes, -1 for invalid input/panic, or -2 for short capacity.
///
/// # Safety
/// Pointers must be non-null and valid for their byte extents, even when zero;
/// regions may overlap and each extent must be at most isize::MAX.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_wave20_report(
    mode: u32,
    input: *const u8,
    input_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        wave20_dispatch(wave20_report, mode, input, input_len, out, out_cap)
    }))
    .unwrap_or(-1)
}

/// File modes: 0 = normalized valid hash; 1 = snapshot eligibility predicate.
/// Returns output bytes, -1 for invalid input/panic, or -2 for short capacity.
///
/// # Safety
/// Pointers must be non-null and valid for their byte extents, even when zero;
/// regions may overlap and each extent must be at most isize::MAX.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_wave20_file(
    mode: u32,
    input: *const u8,
    input_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        wave20_dispatch(wave20_file, mode, input, input_len, out, out_cap)
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave20_overlap_tests {
    use super::*;

    #[test]
    fn partial_overlaps_in_both_directions_preserve_owned_results() {
        type Export = unsafe extern "C" fn(u32, *const u8, usize, *mut u8, usize) -> isize;
        let fixtures: [(Export, u32, &[u8], &[u8]); 5] = [
            (nntmux_wave20_backfill, 0, b"1", b"update"),
            (
                nntmux_wave20_group,
                0,
                b"\x01\0\0\0a\x01\0\0\0a",
                b"\x01\0\0\0a",
            ),
            (nntmux_wave20_recat, 1, b"\0\xff", b"00FF"),
            (nntmux_wave20_report, 0, b"spam,spam", b"Spam/Advertisement"),
            (
                nntmux_wave20_file,
                0,
                b"0123456789ABCDEF0123456789ABCDEF",
                b"0123456789abcdef0123456789abcdef",
            ),
        ];
        for (export, mode, input, expected) in fixtures {
            for (input_start, output_start) in [(1usize, 2usize), (2, 1)] {
                let mut buffer = vec![0x5a; input.len().max(expected.len()) + 4];
                buffer[input_start..input_start + input.len()].copy_from_slice(input);
                let base = buffer.as_mut_ptr();
                unsafe {
                    assert_eq!(
                        export(
                            mode,
                            base.add(input_start),
                            input.len(),
                            base.add(output_start),
                            expected.len()
                        ),
                        expected.len() as isize
                    );
                }
                assert_eq!(
                    &buffer[output_start..output_start + expected.len()],
                    expected
                );
            }
        }
    }

    #[test]
    fn address_overflow_is_rejected_before_slice_creation() {
        let exports = [
            nntmux_wave20_backfill,
            nntmux_wave20_group,
            nntmux_wave20_recat,
            nntmux_wave20_report,
            nntmux_wave20_file,
        ];
        for export in exports {
            let mut output = [0x5a; 8];
            unsafe {
                assert_eq!(
                    export(0, usize::MAX as *const u8, 1, output.as_mut_ptr(), 8),
                    -1
                );
                assert_eq!(export(0, b"".as_ptr(), 0, usize::MAX as *mut u8, 1), -1);
            }
            assert_eq!(output, [0x5a; 8]);
        }
    }
}

// Wave 21.1: PreDB names, comment hosts, DNZB counts, blacklist constants and feed keys.
const WAVE21_FEED_KEYS: &[&[u8]] = &[
    b"predb_club",
    b"predb_net",
    b"predb_me",
    b"predb_org",
    b"predatabase",
    b"srrdb",
    b"xrel",
    b"xrel_p2p",
    b"scnsrc_me",
    b"eztv",
    b"predb_ovh",
    b"apibay",
    b"nfodb",
    b"one_techno",
    b"one_gabba",
    b"ddlvalley",
    b"hdencode",
    b"hdencode_tv",
    b"mp3scene",
    b"nima4k",
    b"gload",
    b"hdworld",
    b"g4u",
    b"nswdb",
    b"3dsdb",
    b"gabber",
    b"movieblog",
    b"scnlog",
    b"knaben",
    b"crowdnfo",
    b"seriousaboutsound",
    b"dats_pciso_1998",
    b"nzedb_archive",
    b"scene_archive_sql",
];

fn wave21_predb_exact_name(_mode: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
    if !input.is_ascii() {
        return Err(-1);
    }
    Ok(input.to_ascii_lowercase())
}

fn wave21_comment_host(mode: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
    match mode {
        0 => Ok(vec![1]),
        1 => {
            let len = input.len().checked_add(1).ok_or(-1isize)?;
            if len > isize::MAX as usize {
                return Err(-1);
            }
            let mut result = Vec::with_capacity(len);
            result.push(1);
            result.extend_from_slice(input);
            Ok(result)
        }
        2 if input.is_empty() => Ok(vec![0]),
        _ => Err(-1),
    }
}

fn wave21_feed_keys(_mode: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
    if !input.is_empty() {
        return Err(-1);
    }
    Ok(WAVE21_FEED_KEYS.join(&0))
}

unsafe fn wave21_dispatch(
    build: fn(u32, &[u8]) -> Result<Vec<u8>, isize>,
    mode: u32,
    input: *const u8,
    input_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    if !cleaning_ffi_region_valid(input, input_len) || !cleaning_ffi_region_valid(out, out_cap) {
        return -1;
    }
    let result = {
        let input = unsafe { slice::from_raw_parts(input, input_len) };
        match build(mode, input) {
            Ok(result) => result,
            Err(code) => return code,
        }
    };
    if result.len() > isize::MAX as usize {
        return -1;
    }
    if result.len() > out_cap {
        return -2;
    }
    // The input borrow has ended; only the owned result is read during copying.
    if !result.is_empty() {
        unsafe { std::ptr::copy_nonoverlapping(result.as_ptr(), out, result.len()) };
    }
    result.len() as isize
}

/// Lowercases ASCII PreDB names without treating embedded NUL as a terminator.
/// Returns bytes written, -1 for invalid/non-ASCII input, or -2 for short output.
/// Errors leave output unchanged.
///
/// # Safety
/// Pointers must be non-null and valid for their stated byte extents, even when
/// empty. Extents must fit isize; input and output regions may overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_wave21_predb_exact_name(
    input: *const u8,
    input_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        wave21_dispatch(wave21_predb_exact_name, 0, input, input_len, out, out_cap)
    }))
    .unwrap_or(-1)
}

/// Encodes comment host presence: mode 0 = empty string, 1 = supplied bytes,
/// 2 = null (requires empty input). The first byte is 1 for strings, 0 for null.
/// Returns bytes written, -1 for invalid input/mode, or -2 for short output.
/// Errors leave output unchanged.
///
/// # Safety
/// Pointers must be non-null and valid for their stated byte extents, even when
/// empty. Extents must fit isize; input and output regions may overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_wave21_comment_host(
    mode: u32,
    input: *const u8,
    input_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        wave21_dispatch(wave21_comment_host, mode, input, input_len, out, out_cap)
    }))
    .unwrap_or(-1)
}

/// Returns 0 for PHP-false counts and 1 otherwise. Mode 0 accepts only canonical
/// signed i64 decimal integers; mode 1 accepts arbitrary PHP string bytes.
/// Returns -1 for invalid input/mode or panic.
///
/// # Safety
/// Input must be non-null and valid for its byte extent, even when empty.
/// The extent must fit isize and must not overflow the pointer address.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_wave21_dnzb_failed_count(
    mode: u32,
    input: *const u8,
    input_len: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(input, input_len) {
            return -1;
        }
        let input = unsafe { slice::from_raw_parts(input, input_len) };
        match mode {
            0 => {
                let negative = input.first() == Some(&b'-');
                let digits = if negative { &input[1..] } else { input };
                if digits.is_empty()
                    || !digits.iter().all(u8::is_ascii_digit)
                    || (digits[0] == b'0' && (digits.len() != 1 || negative))
                {
                    return -1;
                }
                let Ok(text) = std::str::from_utf8(input) else {
                    return -1;
                };
                match text.parse::<i64>() {
                    Ok(value) => isize::from(value != 0),
                    Err(_) => -1,
                }
            }
            1 => isize::from(!input.is_empty() && input != b"0"),
            _ => -1,
        }
    }))
    .unwrap_or(-1)
}

/// Resolves exact blacklist constant names; unknown ASCII names return 0.
/// Returns -1 for invalid/non-ASCII input or panic.
///
/// # Safety
/// Input must be non-null and valid for its byte extent, even when empty.
/// The extent must fit isize and must not overflow the pointer address.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_wave21_blacklist_constant(
    input: *const u8,
    input_len: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(input, input_len) {
            return -1;
        }
        let input = unsafe { slice::from_raw_parts(input, input_len) };
        if !input.is_ascii() {
            return -1;
        }
        match input {
            b"OPTYPE_BLACKLIST" | b"BLACKLIST_ENABLED" | b"BLACKLIST_FIELD_SUBJECT" => 1,
            b"OPTYPE_WHITELIST" | b"BLACKLIST_FIELD_FROM" => 2,
            b"BLACKLIST_FIELD_MESSAGEID" => 3,
            _ => 0,
        }
    }))
    .unwrap_or(-1)
}

/// Returns factory feed keys in source order, joined by NUL with no trailing NUL.
/// Input must be empty. Returns bytes written, -1 for invalid input, or -2 for
/// short output. Errors leave output unchanged.
///
/// # Safety
/// Pointers must be non-null and valid for their stated byte extents, even when
/// empty. Extents must fit isize; input and output regions may overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_wave21_feed_keys(
    input: *const u8,
    input_len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        wave21_dispatch(wave21_feed_keys, 0, input, input_len, out, out_cap)
    }))
    .unwrap_or(-1)
}

/// Returns an exact feed key's zero-based source-order index, -2 for an unknown
/// ASCII key, or -1 for invalid/non-ASCII input or panic.
///
/// # Safety
/// Input must be non-null and valid for its byte extent, even when empty.
/// The extent must fit isize and must not overflow the pointer address.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_wave21_feed_key_index(input: *const u8, input_len: usize) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(input, input_len) {
            return -1;
        }
        let input = unsafe { slice::from_raw_parts(input, input_len) };
        if !input.is_ascii() {
            return -1;
        }
        WAVE21_FEED_KEYS
            .iter()
            .position(|key| *key == input)
            .map_or(-2, |index| index as isize)
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave21_tests {
    use super::*;

    type OutputExport = unsafe fn(u32, *const u8, usize, *mut u8, usize) -> isize;
    type ScalarExport = unsafe fn(u32, *const u8, usize) -> isize;

    unsafe fn predb(_: u32, input: *const u8, len: usize, out: *mut u8, cap: usize) -> isize {
        unsafe { nntmux_wave21_predb_exact_name(input, len, out, cap) }
    }

    unsafe fn comment(mode: u32, input: *const u8, len: usize, out: *mut u8, cap: usize) -> isize {
        unsafe { nntmux_wave21_comment_host(mode, input, len, out, cap) }
    }

    unsafe fn feeds(_: u32, input: *const u8, len: usize, out: *mut u8, cap: usize) -> isize {
        unsafe { nntmux_wave21_feed_keys(input, len, out, cap) }
    }

    unsafe fn count(mode: u32, input: *const u8, len: usize) -> isize {
        unsafe { nntmux_wave21_dnzb_failed_count(mode, input, len) }
    }

    unsafe fn constant(_: u32, input: *const u8, len: usize) -> isize {
        unsafe { nntmux_wave21_blacklist_constant(input, len) }
    }

    unsafe fn feed_index(_: u32, input: *const u8, len: usize) -> isize {
        unsafe { nntmux_wave21_feed_key_index(input, len) }
    }

    fn assert_output(export: OutputExport, mode: u32, input: &[u8], expected: &[u8]) {
        let mut output = vec![0x5a; expected.len() + 1];
        let written = unsafe {
            export(
                mode,
                input.as_ptr(),
                input.len(),
                output.as_mut_ptr(),
                expected.len(),
            )
        };
        assert_eq!(written, expected.len() as isize);
        assert_eq!(&output[..expected.len()], expected);
        assert_eq!(output[expected.len()], 0x5a);
    }

    #[test]
    fn predb_lowercases_ascii_and_preserves_binary_nul() {
        assert_output(predb, 0, b"AbC\0Z-123_\x7f", b"abc\0z-123_\x7f");
        assert_output(predb, 0, b"", b"");
        let mut output = [0x5a; 8];
        for input in [b"\x80".as_slice(), b"\xc3\x89"] {
            assert_eq!(
                unsafe {
                    nntmux_wave21_predb_exact_name(
                        input.as_ptr(),
                        input.len(),
                        output.as_mut_ptr(),
                        8,
                    )
                },
                -1
            );
            assert_eq!(output, [0x5a; 8]);
        }
    }

    #[test]
    fn comment_modes_preserve_host_presence_and_arbitrary_bytes() {
        assert_output(comment, 0, b"discarded\0\xff", b"\x01");
        assert_output(comment, 1, b"host\0\xff", b"\x01host\0\xff");
        assert_output(comment, 1, b"", b"\x01");
        assert_output(comment, 2, b"", b"\0");
        let mut output = [0x5a; 8];
        for (mode, input) in [(2, b"host".as_slice()), (3, b""), (u32::MAX, b"")] {
            assert_eq!(
                unsafe {
                    nntmux_wave21_comment_host(
                        mode,
                        input.as_ptr(),
                        input.len(),
                        output.as_mut_ptr(),
                        8,
                    )
                },
                -1
            );
            assert_eq!(output, [0x5a; 8]);
        }
    }

    #[test]
    fn dnzb_accepts_only_canonical_i64_or_php_string_truthiness() {
        for (input, expected) in [
            (b"0".as_slice(), 0),
            (b"1", 1),
            (b"-1", 1),
            (b"9223372036854775807", 1),
            (b"-9223372036854775808", 1),
        ] {
            assert_eq!(
                unsafe { nntmux_wave21_dnzb_failed_count(0, input.as_ptr(), input.len()) },
                expected
            );
        }
        for input in [
            b"".as_slice(),
            b"+1",
            b"+0",
            b"-0",
            b"00",
            b"01",
            b"-01",
            b"-",
            b"1.0",
            b"1e0",
            b" 1",
            b"1\n",
            b"1\0",
            b"\xff",
            b"9223372036854775808",
            b"-9223372036854775809",
        ] {
            assert_eq!(
                unsafe { nntmux_wave21_dnzb_failed_count(0, input.as_ptr(), input.len()) },
                -1,
                "{input:?}"
            );
        }
        for (input, expected) in [
            (b"".as_slice(), 0),
            (b"0", 0),
            (b"00", 1),
            (b"-0", 1),
            (b"false", 1),
            (b"\0", 1),
            (b"\xff", 1),
        ] {
            assert_eq!(
                unsafe { nntmux_wave21_dnzb_failed_count(1, input.as_ptr(), input.len()) },
                expected
            );
        }
        for mode in [2, u32::MAX] {
            assert_eq!(
                unsafe { nntmux_wave21_dnzb_failed_count(mode, b"0".as_ptr(), 1) },
                -1
            );
        }
    }

    #[test]
    fn blacklist_names_are_exact_ascii() {
        for (input, expected) in [
            (b"OPTYPE_BLACKLIST".as_slice(), 1),
            (b"OPTYPE_WHITELIST", 2),
            (b"BLACKLIST_ENABLED", 1),
            (b"BLACKLIST_FIELD_SUBJECT", 1),
            (b"BLACKLIST_FIELD_FROM", 2),
            (b"BLACKLIST_FIELD_MESSAGEID", 3),
            (b"", 0),
            (b"UNKNOWN", 0),
            (b"optype_blacklist", 0),
            (b"OPTYPE_BLACKLIST\0", 0),
            (b"\xff", -1),
        ] {
            assert_eq!(
                unsafe { nntmux_wave21_blacklist_constant(input.as_ptr(), input.len()) },
                expected
            );
        }
    }

    #[test]
    fn feed_keys_and_indices_match_factory_order() {
        let expected = b"predb_club\0predb_net\0predb_me\0predb_org\0predatabase\0srrdb\0xrel\0xrel_p2p\0scnsrc_me\0eztv\0predb_ovh\0apibay\0nfodb\0one_techno\0one_gabba\0ddlvalley\0hdencode\0hdencode_tv\0mp3scene\0nima4k\0gload\0hdworld\0g4u\0nswdb\03dsdb\0gabber\0movieblog\0scnlog\0knaben\0crowdnfo\0seriousaboutsound\0dats_pciso_1998\0nzedb_archive\0scene_archive_sql";
        assert_output(feeds, 0, b"", expected);
        assert_eq!(expected.split(|byte| *byte == 0).count(), 34);
        for (index, key) in expected.split(|byte| *byte == 0).enumerate() {
            assert_eq!(
                unsafe { nntmux_wave21_feed_key_index(key.as_ptr(), key.len()) },
                index as isize
            );
        }
        for input in [b"".as_slice(), b"unknown", b"PREDB_CLUB", b"predb_club\0"] {
            assert_eq!(
                unsafe { nntmux_wave21_feed_key_index(input.as_ptr(), input.len()) },
                -2
            );
        }
        assert_eq!(
            unsafe { nntmux_wave21_feed_key_index(b"\xff".as_ptr(), 1) },
            -1
        );
        let mut output = [0x5a; 8];
        assert_eq!(
            unsafe { nntmux_wave21_feed_keys(b"x".as_ptr(), 1, output.as_mut_ptr(), 8) },
            -1
        );
        assert_eq!(output, [0x5a; 8]);
    }

    #[test]
    fn byte_exports_reject_invalid_regions_before_access() {
        let exports: [OutputExport; 3] = [predb, comment, feeds];
        for export in exports {
            let mut output = [0x5a; 8];
            let too_long = isize::MAX as usize + 1;
            unsafe {
                assert_eq!(export(0, std::ptr::null(), 0, output.as_mut_ptr(), 8), -1);
                assert_eq!(export(0, b"".as_ptr(), 0, std::ptr::null_mut(), 0), -1);
                assert_eq!(
                    export(0, b"".as_ptr(), too_long, output.as_mut_ptr(), 8),
                    -1
                );
                assert_eq!(
                    export(0, b"".as_ptr(), 0, output.as_mut_ptr(), too_long),
                    -1
                );
                assert_eq!(
                    export(0, usize::MAX as *const u8, 1, output.as_mut_ptr(), 8),
                    -1
                );
                assert_eq!(export(0, b"".as_ptr(), 0, usize::MAX as *mut u8, 1), -1);
            }
            assert_eq!(output, [0x5a; 8]);
        }
        // Byte pointers have alignment 1; the shared validator also rejects
        // misaligned typed regions before they can be used by other exports.
        assert!(!cleaning_ffi_region_valid(1usize as *const u32, 0));
    }

    #[test]
    fn scalar_exports_reject_invalid_regions_before_access() {
        let exports: [ScalarExport; 3] = [count, constant, feed_index];
        for export in exports {
            unsafe {
                assert_eq!(export(0, std::ptr::null(), 0), -1);
                assert_eq!(export(0, b"".as_ptr(), isize::MAX as usize + 1), -1);
                assert_eq!(export(0, usize::MAX as *const u8, 1), -1);
            }
        }
    }

    #[test]
    fn short_output_leaves_every_byte_unchanged() {
        let fixtures: [(OutputExport, u32, &[u8]); 3] =
            [(predb, 0, b"ABC"), (comment, 1, b"host"), (feeds, 0, b"")];
        for (export, mode, input) in fixtures {
            let mut output = [0x5a; 8];
            assert_eq!(
                unsafe { export(mode, input.as_ptr(), input.len(), output.as_mut_ptr(), 1) },
                -2
            );
            assert_eq!(output, [0x5a; 8]);
        }
        let mut output = [0x5a; 8];
        for mode in [0, 2] {
            assert_eq!(
                unsafe { comment(mode, b"".as_ptr(), 0, output.as_mut_ptr(), 0) },
                -2
            );
            assert_eq!(output, [0x5a; 8]);
        }
    }

    #[test]
    fn byte_exports_support_exact_and_partial_aliasing() {
        let feed_output = WAVE21_FEED_KEYS.join(&0);
        let fixtures: [(OutputExport, u32, &[u8], &[u8]); 3] = [
            (predb, 0, b"Ab\0Z", b"ab\0z"),
            (comment, 1, b"host\0\xff", b"\x01host\0\xff"),
            (feeds, 0, b"", &feed_output),
        ];
        for (export, mode, input, expected) in fixtures {
            for (input_start, output_start) in [(1usize, 1usize), (1, 2), (2, 1)] {
                let mut buffer = vec![0x5a; input.len().max(expected.len()) + 4];
                buffer[input_start..input_start + input.len()].copy_from_slice(input);
                let base = buffer.as_mut_ptr();
                assert_eq!(
                    unsafe {
                        export(
                            mode,
                            base.add(input_start),
                            input.len(),
                            base.add(output_start),
                            expected.len(),
                        )
                    },
                    expected.len() as isize
                );
                assert_eq!(
                    &buffer[output_start..output_start + expected.len()],
                    expected
                );
            }
        }
    }
}

#[cfg(test)]
mod wave22_admin_request_tests {
    use super::*;

    fn frames(values: &[Option<&[u8]>]) -> Vec<u8> {
        let mut result = Vec::new();
        for value in values {
            match value {
                Some(value) => {
                    result.extend_from_slice(&(value.len() as u32).to_le_bytes());
                    result.extend_from_slice(value);
                }
                None => result.extend_from_slice(&u32::MAX.to_le_bytes()),
            }
        }
        result
    }

    fn assert_output(mode: u32, input: &[u8], expected: &[u8]) {
        let mut output = vec![0x5a; expected.len() + 8];
        assert_eq!(
            unsafe {
                nntmux_wave22_admin_request(
                    mode,
                    input.as_ptr(),
                    input.len(),
                    output.as_mut_ptr(),
                    expected.len(),
                )
            },
            expected.len() as isize,
            "mode {mode}, input {input:?}"
        );
        assert_eq!(&output[..expected.len()], expected);
        assert_eq!(&output[expected.len()..], &[0x5a; 8]);
    }

    fn assert_declined(mode: u32, input: &[u8]) {
        let mut output = [0x5a; 128];
        assert_eq!(
            unsafe {
                nntmux_wave22_admin_request(
                    mode,
                    input.as_ptr(),
                    input.len(),
                    output.as_mut_ptr(),
                    output.len(),
                )
            },
            -1,
            "mode {mode}, input {input:?}"
        );
        assert_eq!(output, [0x5a; 128]);
    }

    #[test]
    fn filter_frames_trim_php_bytes_and_encode_empty_as_null() {
        assert_output(
            0,
            &frames(&[Some(b" \t\n\r\0\x0b"), Some(b" \xff\0x\t"), Some(b"\x0c")]),
            &frames(&[None, Some(b"\xff\0x"), Some(b"\x0c")]),
        );
        assert_output(0, b"", b"");
    }

    #[test]
    fn raw_names_search_and_actions_preserve_arbitrary_bytes() {
        for mode in [1, 4, 7] {
            for input in [b"".as_slice(), b" \xff\0\x0b\x0c\tname "] {
                assert_output(mode, input, input);
            }
        }
    }

    #[test]
    fn group_keys_remove_only_excluded_names_and_absent_values_in_order() {
        assert_output(
            2,
            &frames(&[
                Some(b"groupname"),
                Some(b"x"),
                Some(b"page"),
                Some(b"1"),
                Some(b"null"),
                None,
                Some(b"empty"),
                Some(b""),
                Some(b"zero"),
                Some(b"0"),
                Some(b"space"),
                Some(b" "),
                Some(b"Page"),
                Some(b"1"),
                Some(b"\xff\0"),
                Some(b"x"),
                Some(b"zero"),
                Some(b"2"),
                Some(b""),
                Some(b"x"),
            ]),
            &frames(&[
                Some(b"zero"),
                Some(b"space"),
                Some(b"Page"),
                Some(b"\xff\0"),
                Some(b"zero"),
                Some(b""),
            ]),
        );
        assert_output(2, b"", b"");
        assert_declined(2, &frames(&[None, Some(b"x")]));
        assert_declined(2, &frames(&[Some(b"key")]));
    }

    #[test]
    fn release_name_trims_php_bytes_but_not_form_feed() {
        assert_output(3, b" \t\n\r\0\x0b\xff\0x\x0b\0\r\n\t ", b"\xff\0x");
        assert_output(3, b" \t\n\r\0\x0b", b"");
        assert_output(3, b" \x0c ", b"\x0c");
    }

    #[test]
    fn category_requires_canonical_i64_and_maps_minus_one_to_empty() {
        for input in [
            b"0".as_slice(),
            b"1",
            b"-2",
            b"9223372036854775807",
            b"-9223372036854775808",
        ] {
            assert_output(5, input, input);
        }
        assert_output(5, b"-1", b"");
        for input in [
            b"".as_slice(),
            b"+1",
            b"01",
            b"00",
            b"-0",
            b"-01",
            b" 1",
            b"1 ",
            b"1.0",
            b"1e0",
            b"1\0",
            b"\xff",
            b"9223372036854775808",
            b"-9223372036854775809",
        ] {
            assert_declined(5, input);
            assert_declined(6, &frames(&[Some(input)]));
        }
    }

    #[test]
    fn id_frames_deduplicate_integer_values_preserving_first_occurrence() {
        assert_output(
            6,
            &frames(&[
                Some(b"2"),
                Some(b"-1"),
                Some(b"2"),
                Some(b"0"),
                Some(b"-1"),
                Some(b"9223372036854775807"),
                Some(b"-9223372036854775808"),
                Some(b"0"),
            ]),
            &frames(&[
                Some(b"2"),
                Some(b"-1"),
                Some(b"0"),
                Some(b"9223372036854775807"),
                Some(b"-9223372036854775808"),
            ]),
        );
        assert_output(6, b"", b"");
    }

    #[test]
    fn status_defaults_only_the_empty_string() {
        assert_output(8, b"", b"pending");
        for input in [b"0".as_slice(), b" ", b"\xff\0", b"resolved"] {
            assert_output(8, input, input);
        }
    }

    #[test]
    fn seven_user_filters_trim_without_null_encoding() {
        assert_output(
            9,
            &frames(&[
                Some(b" a "),
                Some(b"\0"),
                Some(b""),
                Some(b" \xff\t"),
                Some(b"\x0c"),
                Some(b"\x0b0\n"),
                Some(b"x\0y"),
            ]),
            &frames(&[
                Some(b"a"),
                Some(b""),
                Some(b""),
                Some(b"\xff"),
                Some(b"\x0c"),
                Some(b"0"),
                Some(b"x\0y"),
            ]),
        );
        assert_declined(
            9,
            &frames(&[
                Some(b""),
                Some(b""),
                Some(b""),
                None,
                Some(b""),
                Some(b""),
                Some(b""),
            ]),
        );
        for count in [0, 1, 6, 8] {
            assert_declined(9, &frames(&vec![Some(b"".as_slice()); count]));
        }
    }

    #[test]
    fn order_by_trims_only_candidate_and_requires_exact_allowed_match() {
        assert_output(
            10,
            &frames(&[Some(b" \xff\0x\t"), Some(b"x"), Some(b"\xff\0x")]),
            b"\xff\0x",
        );
        assert_output(10, &frames(&[Some(b" name "), Some(b" name ")]), b"");
        assert_output(10, &frames(&[Some(b"Name"), Some(b"name")]), b"");
        assert_output(10, &frames(&[Some(b"name")]), b"");
        assert_output(10, &frames(&[Some(b" \x0c "), Some(b"\x0c")]), b"\x0c");
        assert_declined(10, b"");
    }

    #[test]
    fn framed_modes_reject_nulls_truncation_and_oversize_lengths() {
        for mode in [0, 2, 6, 9, 10] {
            for input in [
                b"\0".as_slice(),
                b"\0\0\0",
                b"\x01\0\0\0",
                b"\xfe\xff\xff\xffx",
            ] {
                assert_declined(mode, input);
            }
        }
        for mode in [0, 6, 9, 10] {
            assert_declined(mode, &frames(&[None]));
            assert_declined(mode, &frames(&[Some(b"1"), None]));
        }
        assert_declined(11, b"");
        assert_declined(u32::MAX, b"");
    }

    #[test]
    fn invalid_pointer_regions_decline_before_any_access() {
        let mut output = [0x5a; 8];
        let too_long = isize::MAX as usize + 1;
        for mode in 0..=10 {
            unsafe {
                assert_eq!(
                    nntmux_wave22_admin_request(mode, std::ptr::null(), 0, output.as_mut_ptr(), 8),
                    -1
                );
                assert_eq!(
                    nntmux_wave22_admin_request(mode, b"".as_ptr(), 0, std::ptr::null_mut(), 0),
                    -1
                );
                assert_eq!(
                    nntmux_wave22_admin_request(
                        mode,
                        b"".as_ptr(),
                        too_long,
                        output.as_mut_ptr(),
                        8
                    ),
                    -1
                );
                assert_eq!(
                    nntmux_wave22_admin_request(
                        mode,
                        b"".as_ptr(),
                        0,
                        output.as_mut_ptr(),
                        too_long
                    ),
                    -1
                );
                assert_eq!(
                    nntmux_wave22_admin_request(
                        mode,
                        usize::MAX as *const u8,
                        1,
                        output.as_mut_ptr(),
                        8
                    ),
                    -1
                );
                assert_eq!(
                    nntmux_wave22_admin_request(mode, b"".as_ptr(), 0, usize::MAX as *mut u8, 1),
                    -1
                );
            }
        }
        assert_eq!(output, [0x5a; 8]);
    }

    #[test]
    fn insufficient_capacity_never_writes_partial_output() {
        for (mode, input) in [
            (0, frames(&[Some(b"x")])),
            (1, b"x".to_vec()),
            (2, frames(&[Some(b"k"), Some(b"v")])),
            (3, b" x ".to_vec()),
            (4, b"x".to_vec()),
            (5, b"1".to_vec()),
            (6, frames(&[Some(b"1")])),
            (7, b"x".to_vec()),
            (8, b"".to_vec()),
            (9, frames(&[Some(b"".as_slice()); 7])),
            (10, frames(&[Some(b"x"), Some(b"x")])),
        ] {
            let mut output = [0x5a; 64];
            assert_eq!(
                unsafe {
                    nntmux_wave22_admin_request(
                        mode,
                        input.as_ptr(),
                        input.len(),
                        output.as_mut_ptr(),
                        0,
                    )
                },
                -2
            );
            assert_eq!(output, [0x5a; 64]);
        }
    }

    #[test]
    fn owned_output_supports_exact_and_partial_aliasing() {
        for (mode, input, expected) in [
            (
                0,
                frames(&[Some(b" x "), Some(b" ")]),
                frames(&[Some(b"x"), None]),
            ),
            (1, b"\xff\0x".to_vec(), b"\xff\0x".to_vec()),
            (2, frames(&[Some(b"k"), Some(b"v")]), frames(&[Some(b"k")])),
            (3, b" x ".to_vec(), b"x".to_vec()),
            (4, b"\xff\0".to_vec(), b"\xff\0".to_vec()),
            (5, b"-2".to_vec(), b"-2".to_vec()),
            (6, frames(&[Some(b"2"), Some(b"2")]), frames(&[Some(b"2")])),
            (7, b"\xff\0".to_vec(), b"\xff\0".to_vec()),
            (8, b"".to_vec(), b"pending".to_vec()),
            (
                9,
                frames(&[Some(b" x ".as_slice()); 7]),
                frames(&[Some(b"x".as_slice()); 7]),
            ),
            (10, frames(&[Some(b" x "), Some(b"x")]), b"x".to_vec()),
        ] {
            for (input_start, output_start) in [(1, 1), (1, 2), (2, 1)] {
                let mut buffer = vec![0x5a; input.len().max(expected.len()) + 4];
                buffer[input_start..input_start + input.len()].copy_from_slice(&input);
                let base = buffer.as_mut_ptr();
                assert_eq!(
                    unsafe {
                        nntmux_wave22_admin_request(
                            mode,
                            base.add(input_start),
                            input.len(),
                            base.add(output_start),
                            expected.len(),
                        )
                    },
                    expected.len() as isize
                );
                assert_eq!(
                    &buffer[output_start..output_start + expected.len()],
                    expected
                );
            }
        }
    }
}

// Wave 22.1: binary-safe admin request normalization over one framed byte ABI.
fn wave22_php_trim(input: &[u8]) -> &[u8] {
    fn is_trim(byte: u8) -> bool {
        matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b)
    }
    let start = input
        .iter()
        .position(|byte| !is_trim(*byte))
        .unwrap_or(input.len());
    let end = input
        .iter()
        .rposition(|byte| !is_trim(*byte))
        .map_or(start, |index| index + 1);
    &input[start..end]
}

fn wave22_frames(mut input: &[u8]) -> Result<Vec<Option<&[u8]>>, isize> {
    let mut frames = Vec::new();
    while !input.is_empty() {
        let header = input.get(..4).ok_or(-1isize)?;
        let len = u32::from_le_bytes(header.try_into().map_err(|_| -1isize)?);
        input = &input[4..];
        if len == u32::MAX {
            frames.push(None);
        } else {
            let len = usize::try_from(len).map_err(|_| -1isize)?;
            let value = input.get(..len).ok_or(-1isize)?;
            frames.push(Some(value));
            input = &input[len..];
        }
    }
    Ok(frames)
}

fn wave22_append_frame(output: &mut Vec<u8>, value: Option<&[u8]>) -> Result<(), isize> {
    match value {
        None => output.extend_from_slice(&u32::MAX.to_le_bytes()),
        Some(value) => {
            let len = u32::try_from(value.len()).map_err(|_| -1isize)?;
            if len == u32::MAX {
                return Err(-1);
            }
            output.extend_from_slice(&len.to_le_bytes());
            output.extend_from_slice(value);
        }
    }
    Ok(())
}

fn wave22_canonical_i64(input: &[u8]) -> Result<i64, isize> {
    let text = std::str::from_utf8(input).map_err(|_| -1isize)?;
    let value = text.parse::<i64>().map_err(|_| -1isize)?;
    if value.to_string() != text {
        return Err(-1);
    }
    Ok(value)
}

fn wave22_admin_request_output(mode: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
    let mut output = Vec::new();
    match mode {
        0 => {
            for value in wave22_frames(input)? {
                let value = wave22_php_trim(value.ok_or(-1isize)?);
                wave22_append_frame(
                    &mut output,
                    if value.is_empty() { None } else { Some(value) },
                )?;
            }
        }
        1 | 4 | 7 => output.extend_from_slice(input),
        2 => {
            let frames = wave22_frames(input)?;
            let mut pairs = frames.chunks_exact(2);
            for pair in &mut pairs {
                let key = pair[0].ok_or(-1isize)?;
                if key == b"groupname" || key == b"page" {
                    continue;
                }
                if pair[1].is_some_and(|value| !value.is_empty()) {
                    wave22_append_frame(&mut output, Some(key))?;
                }
            }
            if !pairs.remainder().is_empty() {
                return Err(-1);
            }
        }
        3 => output.extend_from_slice(wave22_php_trim(input)),
        5 => {
            if wave22_canonical_i64(input)? != -1 {
                output.extend_from_slice(input);
            }
        }
        6 => {
            let mut seen = std::collections::HashSet::new();
            for value in wave22_frames(input)? {
                let value = value.ok_or(-1isize)?;
                if seen.insert(wave22_canonical_i64(value)?) {
                    wave22_append_frame(&mut output, Some(value))?;
                }
            }
        }
        8 => output.extend_from_slice(if input.is_empty() { b"pending" } else { input }),
        9 => {
            let frames = wave22_frames(input)?;
            if frames.len() != 7 {
                return Err(-1);
            }
            for value in frames {
                wave22_append_frame(&mut output, Some(wave22_php_trim(value.ok_or(-1isize)?)))?;
            }
        }
        10 => {
            let frames = wave22_frames(input)?;
            let (order_by, allowed) = frames.split_first().ok_or(-1isize)?;
            let order_by = wave22_php_trim(order_by.ok_or(-1isize)?);
            let mut matched = false;
            for value in allowed {
                if value.ok_or(-1isize)? == order_by {
                    matched = true;
                }
            }
            if matched {
                output.extend_from_slice(order_by);
            }
        }
        _ => return Err(-1),
    }
    Ok(output)
}

/// Normalizes binary admin request values according to modes 0 through 10.
/// Framed modes use little-endian u32 byte lengths; u32::MAX denotes null.
/// Returns bytes written, -1 for malformed input/regions, or -2 for short output.
/// Errors leave the output unchanged.
///
/// # Safety
/// Both pointers must be non-null and valid for their stated byte extents, even
/// when empty. Extents must fit isize. Input and output may overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_wave22_admin_request(
    mode: u32,
    input: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(input, len) || !cleaning_ffi_region_valid(out, cap) {
            return -1;
        }
        let output = {
            let input = unsafe { slice::from_raw_parts(input, len) };
            match wave22_admin_request_output(mode, input) {
                Ok(output) => output,
                Err(code) => return code,
            }
        };
        if output.len() > isize::MAX as usize {
            return -1;
        }
        if output.len() > cap {
            return -2;
        }
        // No borrowed input survives construction of the owned result.
        if !output.is_empty() {
            unsafe { std::ptr::copy_nonoverlapping(output.as_ptr(), out, output.len()) };
        }
        output.len() as isize
    }))
    .unwrap_or(-1)
}

// Wave 23.1: GDPR inventory, export and mail pure helpers. Append-only slice.
const W23_GDPR_INVENTORIES: [&[u8]; 5] = [
    br###"[{"table":"payments","reason":"Accounting, fraud prevention, dispute handling, and legal/tax obligations; direct account identifiers are anonymized while transaction details are retained.","erasure_action":"retained_anonymized"},{"table":"paypal_payments","reason":"Legacy payment audit data retained for accounting and dispute handling; linked account identity resolves only to the anonymized/deleted account record.","erasure_action":"retained_minimized"},{"table":"user_activities","reason":"Security and administrative audit trail.","erasure_action":"retained_minimized"},{"table":"gdpr_audit_logs","reason":"GDPR accountability record for data subject requests.","erasure_action":"retained_minimized"},{"table":"user_role_history","reason":"Administrative audit trail for role changes, upgrades, expirations, and account security reviews.","erasure_action":"retained_minimized"}]"###,
    br###"{"user_requests":{"user_column":"users_id"},"user_downloads":{"user_column":"users_id"},"dnzb_failures":{"user_column":"users_id"},"users_releases":{"user_column":"users_id"},"user_series":{"user_column":"users_id"},"user_movies":{"user_column":"users_id"},"user_excluded_categories":{"user_column":"users_id"},"user_invitations":{"user_column":"user_id"},"trusted_devices":{"user_column":"user_id"},"password_securities":{"user_column":"user_id"},"passkeys":{"user_column":"authenticatable_id","extra":{"authenticatable_type":"App\\Models\\User"}},"model_has_permissions":{"user_column":"model_id","extra":{"model_type":"App\\Models\\User"}},"model_has_roles":{"user_column":"model_id","extra":{"model_type":"App\\Models\\User"}},"role_promotion_stats":{"user_column":"user_id"}}"###,
    br###"{"release_comments":{"user_column":"users_id","updates":{"username":"Deleted user","host":null}},"release_reports":{"user_column":"users_id","updates":{"description":"[Removed during account erasure]"}}}"###,
    br###"{"user_requests":{"user_column":"users_id","order_by":"timestamp"},"user_downloads":{"user_column":"users_id","order_by":"timestamp"},"dnzb_failures":{"user_column":"users_id"},"users_releases":{"user_column":"users_id","order_by":"created_at"},"user_series":{"user_column":"users_id"},"user_movies":{"user_column":"users_id"},"user_excluded_categories":{"user_column":"users_id"},"user_invitations":{"user_column":"user_id","order_by":"created_at"},"release_comments":{"user_column":"users_id","order_by":"created_at"},"release_reports":{"user_column":"users_id","order_by":"created_at"},"invitations_sent":{"table":"invitations","user_column":"invited_by","order_by":"created_at"},"invitations_used":{"table":"invitations","user_column":"used_by","order_by":"used_at"},"trusted_devices":{"user_column":"user_id","order_by":"created_at"},"role_history":{"table":"user_role_history","user_column":"user_id","order_by":"created_at"},"promotion_stats":{"table":"role_promotion_stats","user_column":"user_id","order_by":"created_at"},"gdpr_requests":{"user_column":"user_id","order_by":"created_at"},"gdpr_consents":{"user_column":"user_id","order_by":"created_at"},"gdpr_audit_logs":{"user_column":"user_id","order_by":"created_at"}}"###,
    br###"[{"name":"session","purpose":"Keeps you signed in and stores security/session state.","type":"essential"},{"name":"XSRF-TOKEN","purpose":"Protects forms and authenticated requests from cross-site request forgery.","type":"essential"},{"name":"2fa_trusted_device","purpose":"Optional security cookie used only when a user chooses to trust a device for two-factor authentication.","type":"essential_security"},{"name":"theme/color local storage","purpose":"Stores display preferences for guests; authenticated preferences are stored on the account.","type":"essential_preference"}]"###,
];

const W23_REDACTED_USER_COLUMNS: &[&[u8]] = &[
    b"password",
    b"remember_token",
    b"session_token",
    b"api_token",
    b"resetguid",
    b"verification_token",
    b"sabapikey",
    b"nzbgetpassword",
    b"nzbvortex_api_key",
    b"cp_api",
];

fn w23_frame<'a>(frames: &[Option<&'a [u8]>], index: usize) -> Result<&'a [u8], isize> {
    frames.get(index).copied().flatten().ok_or(-1)
}

fn w23_i64(value: &[u8]) -> Result<i64, isize> {
    let text = std::str::from_utf8(value).map_err(|_| -1isize)?;
    let parsed = text.parse::<i64>().map_err(|_| -1isize)?;
    if parsed.to_string().as_bytes() != value {
        return Err(-1);
    }
    Ok(parsed)
}

// PHP FILTER_VALIDATE_EMAIL is the final authority for quoted local parts and
// address literals: decline those forms rather than approximate their grammar.
// Ordinary ASCII dot-atoms and DNS domains are evaluated entirely in Rust.
fn w23_email(input: &[u8]) -> Result<Vec<u8>, isize> {
    let email = wave22_php_trim(input);
    if email.contains(&b'"') || email.contains(&b'[') || email.contains(&b']') {
        return Err(-1);
    }
    if email.len() > 254 || !email.is_ascii() {
        return Ok(Vec::new());
    }
    let parts: Vec<_> = email.split(|byte| *byte == b'@').collect();
    if parts.len() != 2 {
        return Ok(Vec::new());
    }
    let (local, domain) = (parts[0], parts[1]);
    if local.is_empty() || local.len() > 64 || domain.is_empty() || domain.len() > 253 {
        return Ok(Vec::new());
    }
    for atom in local.split(|byte| *byte == b'.') {
        if atom.is_empty()
            || !atom
                .iter()
                .all(|b| b.is_ascii_alphanumeric() || b"!#$%&'*+-/=?^_`{|}~".contains(b))
        {
            return Ok(Vec::new());
        }
    }
    let labels: Vec<_> = domain.split(|byte| *byte == b'.').collect();
    if labels.len() < 2
        || !labels
            .last()
            .is_some_and(|label| label.first().is_some_and(u8::is_ascii_alphabetic))
    {
        return Ok(Vec::new());
    }
    for label in labels {
        if label.is_empty()
            || label.len() > 63
            || !label[0].is_ascii_alphanumeric()
            || !label[label.len() - 1].is_ascii_alphanumeric()
            || !label
                .iter()
                .all(|b| b.is_ascii_alphanumeric() || *b == b'-')
        {
            return Ok(Vec::new());
        }
    }
    Ok(email.to_vec())
}

fn w23_gdpr_output(operation: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
    if operation <= 4 {
        if !input.is_empty() {
            return Err(-1);
        }
        return Ok(W23_GDPR_INVENTORIES[operation as usize].to_vec());
    }
    let frames = wave22_frames(input)?;
    let expected_count = match operation {
        5 => Some(3),
        8 | 11 => Some(1),
        9 | 12 => Some(2),
        10 => Some(5),
        13 => Some(4),
        6 | 7 => None,
        _ => return Err(-1),
    };
    if expected_count.is_some_and(|count| frames.len() != count) {
        return Err(-1);
    }
    let mut output = Vec::new();
    match operation {
        5 => {
            w23_i64(w23_frame(&frames, 0)?)?;
            output.extend_from_slice(b"gdpr/exports/user-");
            output.extend_from_slice(w23_frame(&frames, 0)?);
            output.push(b'-');
            output.extend_from_slice(w23_frame(&frames, 1)?);
            output.push(b'-');
            output.extend_from_slice(w23_frame(&frames, 2)?);
            output.extend_from_slice(b".json");
        }
        6 => {
            for key in frames {
                let key = key.ok_or(-1isize)?;
                output.push(if W23_REDACTED_USER_COLUMNS.contains(&key) {
                    b'1'
                } else {
                    b'0'
                });
            }
        }
        7 => {
            let count = usize::try_from(w23_i64(w23_frame(&frames, 0)?)?).map_err(|_| -1isize)?;
            let boundary = count.checked_add(1).ok_or(-1isize)?;
            if boundary > frames.len() || frames.iter().any(Option::is_none) {
                return Err(-1);
            }
            let columns = &frames[boundary..];
            for key in &frames[1..boundary] {
                output.push(if columns.contains(key) { b'1' } else { b'0' });
            }
        }
        8 => return w23_email(w23_frame(&frames, 0)?),
        9 => {
            let request_type = w23_frame(&frames, 0)?;
            let event = w23_frame(&frames, 1)?;
            let admin = request_type == b"erasure" && event == b"submitted";
            output.push(if admin { b'1' } else { b'0' });
        }
        10 => {
            let request_type = w23_frame(&frames, 0)?;
            let status = w23_frame(&frames, 1)?;
            let now = w23_i64(w23_frame(&frames, 4)?)?;
            let future = match frames[3] {
                None => true,
                Some(expiry) => w23_i64(expiry)? > now,
            };
            let downloadable = request_type == b"export"
                && status == b"completed"
                && frames[2].is_some()
                && future;
            output.push(if downloadable { b'1' } else { b'0' });
        }
        11 => {
            output = w23_frame(&frames, 0)?
                .iter()
                .map(|b| if *b == b'_' { b' ' } else { *b })
                .collect();
            if let Some(first) = output.first_mut() {
                first.make_ascii_uppercase();
            }
        }
        12 => {
            let username = frames[0]
                .filter(|v| !v.is_empty() && *v != b"0")
                .unwrap_or(b"Unknown user");
            let email = frames[1]
                .filter(|v| !v.is_empty() && *v != b"0")
                .unwrap_or(b"unknown email");
            output.extend_from_slice(username);
            output.extend_from_slice(b" <");
            output.extend_from_slice(email);
            output.push(b'>');
            output = wave22_php_trim(&output).to_vec();
        }
        13 => {
            let request_type = w23_frame(&frames, 0)?;
            let event = w23_frame(&frames, 1)?;
            let admin = match w23_frame(&frames, 2)? {
                b"1" => true,
                b"0" => false,
                _ => return Err(-1),
            };
            // Owned bytes preserve binary request labels without a UTF-8/JSON round trip.
            let interpolate = |prefix: &[u8], suffix: &[u8]| -> Vec<u8> {
                [prefix, request_type, suffix].concat()
            };
            let (subject, heading, body, action): (Vec<u8>, &[u8], Vec<u8>, Option<&[u8]>) =
                if admin {
                    (
                        interpolate(b"New GDPR ", b" request"),
                        b"New GDPR request submitted",
                        interpolate(
                            b"A GDPR ",
                            b" request was submitted and may require administrator review.",
                        ),
                        Some(b"Review request"),
                    )
                } else {
                    match event {
                    b"submitted" => (interpolate(b"GDPR ", b" request received"), b"We received your GDPR request",
                        interpolate(b"Your GDPR ", b" request has been received and recorded."), Some(b"Open Privacy Center")),
                    b"export_ready" => (b"Your GDPR data export is ready".to_vec(), b"Your data export is ready",
                        b"Your GDPR data export has been generated and is available for download until its listed expiry time.".to_vec(), Some(b"Download export")),
                    b"erasure_completed" => (b"Your GDPR erasure request is complete".to_vec(), b"Your account erasure is complete",
                        b"Your GDPR erasure request has been completed. Account data was removed or anonymized, and retained payment/audit records were anonymized or minimized where practical.".to_vec(), None),
                    b"rejected" => (b"Your GDPR request was rejected".to_vec(), b"Your GDPR request was rejected",
                        interpolate(b"Your GDPR ", b" request was reviewed and rejected. See the notes below for the reason provided by the administrator."), Some(b"Open Privacy Center")),
                    _ => (b"GDPR request update".to_vec(), b"GDPR request update",
                        interpolate(b"There has been an update to your GDPR ", b" request."), Some(b"Open Privacy Center")),
                }
                };
            for field in [
                Some(subject.as_slice()),
                Some(heading),
                Some(body.as_slice()),
                action,
                if action.is_some() { frames[3] } else { None },
            ] {
                wave22_append_frame(&mut output, field)?;
            }
        }
        _ => return Err(-1),
    }
    Ok(output)
}

/// Pure GDPR helpers. Input/output byte regions must be non-null (also when
/// empty), allocated for their stated extents, and fit isize without address
/// overflow. Byte alignment is sufficient; there are no typed output pointers.
/// Returns bytes written, -1 on invalid/declined input or panic, or the negated
/// required length on insufficient capacity. Errors never modify output.
/// Input and output may overlap: all input is consumed into owned output first.
///
/// # Safety
/// Caller guarantees allocation validity for every region that passes the
/// structural checks; arbitrary non-null addresses cannot be proven allocated.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w23_gdpr_export_mail(
    operation: u32,
    input: *const u8,
    len: usize,
    out: *mut u8,
    out_cap: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(input, len) || !cleaning_ffi_region_valid(out, out_cap) {
            return -1;
        }
        let output = {
            let input = unsafe { slice::from_raw_parts(input, len) };
            match w23_gdpr_output(operation, input) {
                Ok(output) => output,
                Err(_) => return -1,
            }
        };
        let Ok(needed) = i32::try_from(output.len()) else {
            return -1;
        };
        if output.len() > out_cap {
            return -needed;
        }
        if !output.is_empty() {
            unsafe { std::ptr::copy_nonoverlapping(output.as_ptr(), out, output.len()) };
        }
        needed
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod w23_gdpr_tests {
    use super::*;

    fn wire(values: &[Option<&[u8]>]) -> Vec<u8> {
        let mut output = Vec::new();
        for value in values {
            wave22_append_frame(&mut output, *value).unwrap();
        }
        output
    }

    #[test]
    fn gdpr_helpers_preserve_binary_and_boundary_semantics() {
        assert_eq!(
            w23_gdpr_output(
                5,
                &wire(&[
                    Some(b"-9223372036854775808"),
                    Some(b"\0\xff"),
                    Some(b"random")
                ])
            )
            .unwrap(),
            b"gdpr/exports/user--9223372036854775808-\0\xff-random.json"
        );
        assert_eq!(
            w23_gdpr_output(
                6,
                &wire(&[Some(b"password"), Some(b"Password"), Some(b"api_token")])
            )
            .unwrap(),
            b"101"
        );
        assert_eq!(
            w23_gdpr_output(
                7,
                &wire(&[Some(b"2"), Some(b"0"), Some(b"data"), Some(b"data")])
            )
            .unwrap(),
            b"01"
        );
        assert_eq!(
            w23_gdpr_output(11, &wire(&[Some(b"export_ready\xff")])).unwrap(),
            b"Export ready\xff"
        );
        assert_eq!(
            w23_gdpr_output(12, &wire(&[Some(b"0"), None])).unwrap(),
            b"Unknown user <unknown email>"
        );
        for (expires, now, expected) in [
            (b"1".as_slice(), b"1".as_slice(), b"0".as_slice()),
            (b"9223372036854775807", b"-9223372036854775808", b"1"),
        ] {
            assert_eq!(
                w23_gdpr_output(
                    10,
                    &wire(&[
                        Some(b"export"),
                        Some(b"completed"),
                        Some(b""),
                        Some(expires),
                        Some(now)
                    ])
                )
                .unwrap(),
                expected
            );
        }
        assert_eq!(
            w23_gdpr_output(8, &wire(&[Some(b" \tAlice+tag@Example.com\r\n")])).unwrap(),
            b"Alice+tag@Example.com"
        );
        assert!(
            w23_gdpr_output(8, &wire(&[Some(b"a..b@example.com")]))
                .unwrap()
                .is_empty()
        );
        assert!(w23_gdpr_output(8, &wire(&[Some(b"\"quoted\"@example.com")])).is_err());
        for op in 0..5 {
            assert!(!w23_gdpr_output(op, b"").unwrap().is_empty());
        }
        assert!(w23_gdpr_output(99, b"").is_err());
        assert!(w23_gdpr_output(11, &[1, 0, 0, 0]).is_err());
        assert!(w23_gdpr_output(7, &wire(&[Some(b"9223372036854775807")])).is_err());
    }

    #[test]
    fn gdpr_abi_checks_regions_and_reports_required_capacity_without_writes() {
        let input = wire(&[Some(b"export_ready")]);
        let mut output = [0x7bu8; 32];
        unsafe {
            assert_eq!(
                nntmux_w23_gdpr_export_mail(
                    11,
                    input.as_ptr(),
                    input.len(),
                    output.as_mut_ptr(),
                    1
                ),
                -12
            );
            assert_eq!(output, [0x7b; 32]);
            assert_eq!(
                nntmux_w23_gdpr_export_mail(11, std::ptr::null(), 0, output.as_mut_ptr(), 32),
                -1
            );
            assert_eq!(
                nntmux_w23_gdpr_export_mail(
                    11,
                    input.as_ptr(),
                    input.len(),
                    std::ptr::null_mut(),
                    0
                ),
                -1
            );
            assert_eq!(
                nntmux_w23_gdpr_export_mail(
                    11,
                    usize::MAX as *const u8,
                    1,
                    output.as_mut_ptr(),
                    32
                ),
                -1
            );
            assert_eq!(
                nntmux_w23_gdpr_export_mail(
                    11,
                    input.as_ptr(),
                    input.len(),
                    usize::MAX as *mut u8,
                    1
                ),
                -1
            );
            assert_eq!(
                nntmux_w23_gdpr_export_mail(
                    11,
                    input.as_ptr(),
                    usize::MAX,
                    output.as_mut_ptr(),
                    32
                ),
                -1
            );
            assert_eq!(
                nntmux_w23_gdpr_export_mail(
                    11,
                    input.as_ptr(),
                    input.len(),
                    output.as_mut_ptr(),
                    usize::MAX
                ),
                -1
            );
            assert_eq!(
                nntmux_w23_gdpr_export_mail(
                    11,
                    input.as_ptr(),
                    input.len(),
                    output.as_mut_ptr().add(1),
                    12
                ),
                12
            );
            assert_eq!(&output[1..13], b"Export ready");
            let mut overlap = input;
            assert_eq!(
                nntmux_w23_gdpr_export_mail(
                    11,
                    overlap.as_ptr(),
                    overlap.len(),
                    overlap.as_mut_ptr(),
                    overlap.len()
                ),
                12
            );
            assert_eq!(&overlap[..12], b"Export ready");
        }
    }
}
// Wave 24.4: transactional mail copy and verification URL pure helpers. Append-only slice.
// Input and output reuse the Wave 22 LE-u32 frames (u32::MAX = PHP null). PHP owns
// models, configuration, routing, clocks and the final mail objects.
const W24_MAIL_OPERATIONS: u32 = 7;

fn w24_flag(value: &[u8]) -> Result<bool, isize> {
    match value {
        b"1" => Ok(true),
        b"0" => Ok(false),
        _ => Err(-1),
    }
}

/// PHP 8.2+ `ucfirst()`: locale-insensitive ASCII uppercasing of the first byte.
fn w24_ucfirst(value: &[u8]) -> Vec<u8> {
    let mut output = value.to_vec();
    if let Some(first) = output.first_mut() {
        first.make_ascii_uppercase();
    }
    output
}

fn w24_hmac_sha256_hex(key: &[u8], message: &[u8]) -> [u8; 64] {
    use crate::metadata::candidate_inventory::sha256;
    let mut block = [0u8; 64];
    if key.len() > 64 {
        block[..32].copy_from_slice(&sha256(key));
    } else {
        block[..key.len()].copy_from_slice(key);
    }
    let mut inner = Vec::with_capacity(64 + message.len());
    inner.extend(block.iter().map(|byte| byte ^ 0x36));
    inner.extend_from_slice(message);
    let inner_hash = sha256(&inner);
    let mut outer = [0u8; 96];
    for (slot, byte) in outer.iter_mut().zip(block) {
        *slot = byte ^ 0x5c;
    }
    outer[64..].copy_from_slice(&inner_hash);
    let digest = sha256(&outer);
    let mut hex = [0u8; 64];
    for (index, byte) in digest.iter().enumerate() {
        hex[index * 2] = b"0123456789abcdef"[usize::from(byte >> 4)];
        hex[index * 2 + 1] = b"0123456789abcdef"[usize::from(byte & 15)];
    }
    hex
}

fn w24_mail_output(operation: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
    if operation >= W24_MAIL_OPERATIONS {
        return Err(-1);
    }
    let frames = wave22_frames(input)?;
    let expected = match operation {
        0 => 5,
        3 => 3,
        5 => 1,
        _ => 2,
    };
    if frames.len() != expected {
        return Err(-1);
    }
    let mut output = Vec::new();
    match operation {
        // AccountWillExpire: [account, days, flag, pendingName?, pendingStart?]
        // -> [hasPending, pendingName?, pendingStart?, preheader].
        0 => {
            let account = w23_frame(&frames, 0)?;
            let days = w23_frame(&frames, 1)?;
            w23_i64(days)?;
            let flag = w24_flag(w23_frame(&frames, 2)?)?;
            let pending = flag && frames[3].is_some() && frames[4].is_some();
            let mut preheader = [
                b"Your ".as_slice(),
                account,
                b" role expires in ",
                days,
                b" day(s)",
            ]
            .concat();
            if pending {
                preheader.extend_from_slice(b", then ");
                preheader.extend_from_slice(w23_frame(&frames, 3)?);
                preheader.extend_from_slice(b" is scheduled");
            }
            preheader.push(b'.');
            wave22_append_frame(&mut output, Some(if pending { b"1" } else { b"0" }))?;
            wave22_append_frame(&mut output, if pending { frames[3] } else { None })?;
            wave22_append_frame(&mut output, if pending { frames[4] } else { None })?;
            wave22_append_frame(&mut output, Some(&preheader))?;
        }
        // InvitationMail subject: prefix . "You're invited to join " . site (raw bytes).
        1 => {
            output = [
                w23_frame(&frames, 0)?,
                b"You're invited to join ",
                w23_frame(&frames, 1)?,
            ]
            .concat();
        }
        // InvitationMail content: [inviter?, site] -> [invitedByName, preheader].
        2 => {
            let name = frames[0].unwrap_or(b"A member");
            let site = w23_frame(&frames, 1)?;
            let preheader = [name, b" has invited you to join ", site, b"."].concat();
            wave22_append_frame(&mut output, Some(name))?;
            wave22_append_frame(&mut output, Some(&preheader))?;
        }
        // IncidentDetected: [resolved, impact, services] -> [status, impact, subject, preheader].
        3 => {
            let resolved = w24_flag(w23_frame(&frames, 0)?)?;
            let impact = w24_ucfirst(w23_frame(&frames, 1)?);
            let services = w23_frame(&frames, 2)?;
            let status: &[u8] = if resolved { b"Resolved" } else { b"Detected" };
            let subject = [b"Service incident ".as_slice(), status, b": ", services].concat();
            let preheader = if resolved {
                [
                    impact.as_slice(),
                    b" incident on ",
                    services,
                    b" has been resolved.",
                ]
                .concat()
            } else {
                [
                    impact.as_slice(),
                    b" impact incident detected on ",
                    services,
                    b".",
                ]
                .concat()
            };
            for field in [
                status,
                impact.as_slice(),
                subject.as_slice(),
                preheader.as_slice(),
            ] {
                wave22_append_frame(&mut output, Some(field))?;
            }
        }
        // AccountChange: [site, role?] -> [account, preheader].
        4 => {
            let site = w23_frame(&frames, 0)?;
            let account = frames[1].unwrap_or(b"User");
            let preheader = [
                b"Your ".as_slice(),
                site,
                b" account level was updated to ",
                account,
                b".",
            ]
            .concat();
            wave22_append_frame(&mut output, Some(account))?;
            wave22_append_frame(&mut output, Some(&preheader))?;
        }
        // VerifyEmailBranded origin: trim, null when empty, default https://, rtrim '/'.
        5 => {
            let url = wave22_php_trim(w23_frame(&frames, 0)?);
            if url.is_empty() {
                wave22_append_frame(&mut output, None)?;
            } else {
                let lower: Vec<u8> = url.iter().take(8).map(u8::to_ascii_lowercase).collect();
                let mut origin = if lower.starts_with(b"http://") || lower.starts_with(b"https://")
                {
                    url.to_vec()
                } else {
                    [b"https://".as_slice(), url].concat()
                };
                let end = origin
                    .iter()
                    .rposition(|byte| *byte != b'/')
                    .map_or(0, |index| index + 1);
                origin.truncate(end);
                wave22_append_frame(&mut output, Some(&origin))?;
            }
        }
        // VerifyEmailBranded signature: url . ('?'|'&') . "signature=" . hmac_sha256_hex(url, key).
        6 => {
            let url = w23_frame(&frames, 0)?;
            let key = w23_frame(&frames, 1)?;
            output.reserve(url.len() + 75);
            output.extend_from_slice(url);
            output.push(if url.contains(&b'?') { b'&' } else { b'?' });
            output.extend_from_slice(b"signature=");
            output.extend_from_slice(&w24_hmac_sha256_hex(key, url));
        }
        _ => return Err(-1),
    }
    Ok(output)
}

/// Transactional mail and verification URL helpers. Input/output byte regions
/// must be non-null (also when empty), allocated for their stated extents, and
/// fit isize without address overflow. Byte alignment is sufficient; there are
/// no typed output pointers. Returns bytes written, -1 on invalid input or
/// panic, or the negated required length on insufficient capacity. Errors never
/// modify output. Input and output may overlap: output is built before copying.
///
/// # Safety
/// Caller guarantees allocation validity for every region that passes the
/// structural checks; arbitrary non-null addresses cannot be proven allocated.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w24_transactional_mail_verify(
    operation: u32,
    input: *const u8,
    len: usize,
    out: *mut u8,
    out_cap: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(input, len) || !cleaning_ffi_region_valid(out, out_cap) {
            return -1;
        }
        let output = {
            let input = unsafe { slice::from_raw_parts(input, len) };
            match w24_mail_output(operation, input) {
                Ok(output) => output,
                Err(_) => return -1,
            }
        };
        let Ok(needed) = i32::try_from(output.len()) else {
            return -1;
        };
        if output.len() > out_cap {
            return -needed;
        }
        if !output.is_empty() {
            unsafe { std::ptr::copy_nonoverlapping(output.as_ptr(), out, output.len()) };
        }
        needed
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod w24_transactional_mail_tests {
    use super::*;

    fn wire(values: &[Option<&[u8]>]) -> Vec<u8> {
        let mut output = Vec::new();
        for value in values {
            wave22_append_frame(&mut output, *value).unwrap();
        }
        output
    }

    #[test]
    fn mail_copy_matches_php_interpolation_and_null_semantics() {
        assert_eq!(
            w24_mail_output(
                0,
                &wire(&[
                    Some(b"Gold"),
                    Some(b"-3"),
                    Some(b"1"),
                    Some(b"VIP"),
                    Some(b"Oct 1, 2026")
                ])
            )
            .unwrap(),
            wire(&[
                Some(b"1"),
                Some(b"VIP"),
                Some(b"Oct 1, 2026"),
                Some(b"Your Gold role expires in -3 day(s), then VIP is scheduled.")
            ])
        );
        assert_eq!(
            w24_mail_output(
                0,
                &wire(&[Some(b"\0\xff"), Some(b"5"), Some(b"1"), Some(b"VIP"), None])
            )
            .unwrap(),
            wire(&[
                Some(b"0"),
                None,
                None,
                Some(b"Your \0\xff role expires in 5 day(s).")
            ])
        );
        assert_eq!(
            w24_mail_output(1, &wire(&[Some(b"[S] "), Some(b"")])).unwrap(),
            b"[S] You're invited to join "
        );
        assert_eq!(
            w24_mail_output(2, &wire(&[None, Some(b"Site")])).unwrap(),
            wire(&[
                Some(b"A member"),
                Some(b"A member has invited you to join Site.")
            ])
        );
        assert_eq!(
            w24_mail_output(2, &wire(&[Some(b""), Some(b"Site")])).unwrap(),
            wire(&[Some(b""), Some(b" has invited you to join Site.")])
        );
        assert_eq!(
            w24_mail_output(3, &wire(&[Some(b"1"), Some(b"major"), Some(b"Web, API")])).unwrap(),
            wire(&[
                Some(b"Resolved"),
                Some(b"Major"),
                Some(b"Service incident Resolved: Web, API"),
                Some(b"Major incident on Web, API has been resolved.")
            ])
        );
        assert_eq!(
            w24_mail_output(3, &wire(&[Some(b"0"), Some(b"\xe9x"), Some(b"")])).unwrap(),
            wire(&[
                Some(b"Detected"),
                Some(b"\xe9x"),
                Some(b"Service incident Detected: "),
                Some(b"\xe9x impact incident detected on .")
            ])
        );
        assert_eq!(
            w24_mail_output(4, &wire(&[Some(b"Site"), None])).unwrap(),
            wire(&[
                Some(b"User"),
                Some(b"Your Site account level was updated to User.")
            ])
        );
        for (raw, expected) in [
            (b"".as_slice(), None),
            (b" \t\0\x0b\r\n", None),
            (
                b" HTTPS://Example.test/// ",
                Some(b"HTTPS://Example.test".as_slice()),
            ),
            (b"example.test/", Some(b"https://example.test")),
            (b"/", Some(b"https:")),
            (b"ftp://x", Some(b"https://ftp://x")),
            (b"http:/x", Some(b"https://http:/x")),
        ] {
            assert_eq!(
                w24_mail_output(5, &wire(&[Some(raw)])).unwrap(),
                wire(&[expected])
            );
        }
        // RFC 4231 test case 2.
        assert_eq!(
            &w24_hmac_sha256_hex(b"Jefe", b"what do ya want for nothing?"),
            b"5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843"
        );
        let long_key = [0xaau8; 131];
        assert_eq!(
            &w24_hmac_sha256_hex(
                &long_key,
                b"Test Using Larger Than Block-Size Key - Hash Key First"
            ),
            b"60e431591ee0b67f0d8a26aacbf5b77f8e0bc6213728c5140546040f0ee37f54"
        );
        let signed = w24_mail_output(6, &wire(&[Some(b"https://x/v?a=1"), Some(b"k")])).unwrap();
        assert!(signed.starts_with(b"https://x/v?a=1&signature="));
        assert_eq!(signed.len(), 15 + 11 + 64);
        assert!(
            w24_mail_output(6, &wire(&[Some(b"https://x/v"), Some(b"")]))
                .unwrap()
                .starts_with(b"https://x/v?signature=")
        );
    }

    #[test]
    fn mail_copy_rejects_malformed_frames() {
        for (operation, input) in [
            (7, wire(&[Some(b"x")])),
            (0, wire(&[Some(b"a"), Some(b"05"), Some(b"0"), None, None])),
            (0, wire(&[Some(b"a"), Some(b"5"), Some(b"2"), None, None])),
            (0, wire(&[None, Some(b"5"), Some(b"0"), None, None])),
            (0, wire(&[Some(b"a"), Some(b"5"), Some(b"0"), None])),
            (1, wire(&[None, Some(b"s")])),
            (2, wire(&[Some(b"a"), None])),
            (3, wire(&[Some(b"true"), Some(b"minor"), Some(b"s")])),
            (4, wire(&[None, Some(b"r")])),
            (5, wire(&[None])),
            (5, Vec::new()),
            (6, wire(&[Some(b"u"), None])),
            (6, vec![1, 0, 0]),
        ] {
            assert!(
                w24_mail_output(operation, &input).is_err(),
                "operation {operation}"
            );
        }
    }

    #[test]
    fn mail_abi_checks_regions_and_reports_required_capacity_without_writes() {
        let input = wire(&[Some(b"P "), Some(b"S")]);
        let expected = b"P You're invited to join S";
        let mut output = [0x7bu8; 64];
        unsafe {
            assert_eq!(
                nntmux_w24_transactional_mail_verify(
                    1,
                    input.as_ptr(),
                    input.len(),
                    output.as_mut_ptr(),
                    3
                ),
                -(expected.len() as i32)
            );
            assert_eq!(output, [0x7b; 64]);
            assert_eq!(
                nntmux_w24_transactional_mail_verify(
                    1,
                    std::ptr::null(),
                    0,
                    output.as_mut_ptr(),
                    64
                ),
                -1
            );
            assert_eq!(
                nntmux_w24_transactional_mail_verify(
                    1,
                    input.as_ptr(),
                    input.len(),
                    std::ptr::null_mut(),
                    0
                ),
                -1
            );
            assert_eq!(
                nntmux_w24_transactional_mail_verify(
                    1,
                    usize::MAX as *const u8,
                    1,
                    output.as_mut_ptr(),
                    64
                ),
                -1
            );
            assert_eq!(
                nntmux_w24_transactional_mail_verify(
                    1,
                    input.as_ptr(),
                    input.len(),
                    usize::MAX as *mut u8,
                    1
                ),
                -1
            );
            assert_eq!(
                nntmux_w24_transactional_mail_verify(
                    1,
                    input.as_ptr(),
                    usize::MAX,
                    output.as_mut_ptr(),
                    64
                ),
                -1
            );
            assert_eq!(
                nntmux_w24_transactional_mail_verify(
                    1,
                    input.as_ptr(),
                    input.len(),
                    output.as_mut_ptr(),
                    usize::MAX
                ),
                -1
            );
            assert_eq!(output, [0x7b; 64]);
            assert_eq!(
                nntmux_w24_transactional_mail_verify(
                    1,
                    input.as_ptr(),
                    input.len(),
                    output.as_mut_ptr().add(1),
                    expected.len()
                ),
                expected.len() as i32
            );
            assert_eq!(&output[1..=expected.len()], expected);
            assert_eq!(output[0], 0x7b);
            assert_eq!(output[expected.len() + 1], 0x7b);
            let mut overlap = [input.clone(), vec![0; 32]].concat();
            assert_eq!(
                nntmux_w24_transactional_mail_verify(
                    1,
                    overlap.as_ptr(),
                    input.len(),
                    overlap.as_mut_ptr(),
                    overlap.len()
                ),
                expected.len() as i32
            );
            assert_eq!(&overlap[..expected.len()], expected);
        }
    }
}

// Wave 25.1: binary-safe account/authentication mail content.
pub mod wave25_account_auth_mail {
    use super::{w23_frame, wave22_append_frame, wave22_frames};

    pub(super) fn output(operation: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
        if operation > 4 {
            return Err(-1);
        }
        let frames = wave22_frames(input)?;
        if frames.len() != if operation == 1 { 3 } else { 2 } {
            return Err(-1);
        }
        let first = w23_frame(&frames, 0)?;
        let site = w23_frame(&frames, 1)?;
        let role = if operation == 1 {
            frames[2].unwrap_or(b"User")
        } else {
            b"User"
        };
        let (subject, preheader): (Vec<u8>, Vec<u8>) = match operation {
            0 => (
                b"User account deleted".to_vec(),
                [
                    b"User ".as_slice(),
                    first,
                    b" deleted their ",
                    site,
                    b" account.",
                ]
                .concat(),
            ),
            1 => (
                b"Your account has expired".to_vec(),
                [
                    b"Your ".as_slice(),
                    site,
                    " subscription has expired — account downgraded to ".as_bytes(),
                    role,
                    b".",
                ]
                .concat(),
            ),
            2 => (
                b"Reset your password".to_vec(),
                b"Reset your password using the secure link inside.".to_vec(),
            ),
            3 => (
                b"New account registered".to_vec(),
                [b"New user registered on ".as_slice(), site, b"."].concat(),
            ),
            4 => (
                [b"Welcome to ".as_slice(), site].concat(),
                [
                    b"Welcome to ".as_slice(),
                    site,
                    " — your account is ready.".as_bytes(),
                ]
                .concat(),
            ),
            _ => return Err(-1),
        };
        let mut output = Vec::new();
        for value in [subject.as_slice(), preheader.as_slice(), first, site] {
            wave22_append_frame(&mut output, Some(value))?;
        }
        if operation == 1 {
            wave22_append_frame(&mut output, Some(role))?;
        }
        Ok(output)
    }

    #[cfg(test)]
    mod tests {
        use super::output;
        use crate::cleaning::nntmux_w25_account_auth_mail;

        // Independent encoder keeps expected payloads separate from production framing.
        fn wire(values: &[Option<&[u8]>]) -> Vec<u8> {
            let mut result = Vec::new();
            for value in values {
                match value {
                    Some(bytes) => {
                        result.extend_from_slice(&(bytes.len() as u32).to_le_bytes());
                        result.extend_from_slice(bytes);
                    }
                    None => result.extend_from_slice(&[255; 4]),
                }
            }
            result
        }

        #[test]
        fn w25_account_auth_mail_literal_content_and_order() {
            let cases: &[(u32, &[Option<&[u8]>], &[Option<&[u8]>])] = &[
                (
                    0,
                    &[Some(b"Alice"), Some(b"Example")],
                    &[
                        Some(b"User account deleted"),
                        Some(b"User Alice deleted their Example account."),
                        Some(b"Alice"),
                        Some(b"Example"),
                    ],
                ),
                (
                    1,
                    &[Some(b"Alice"), Some(b"Example"), Some(b"VIP")],
                    &[
                        Some(b"Your account has expired"),
                        Some(
                            "Your Example subscription has expired — account downgraded to VIP."
                                .as_bytes(),
                        ),
                        Some(b"Alice"),
                        Some(b"Example"),
                        Some(b"VIP"),
                    ],
                ),
                (
                    2,
                    &[Some(b"https://x/reset?a=1&b=2"), Some(b"Example")],
                    &[
                        Some(b"Reset your password"),
                        Some(b"Reset your password using the secure link inside."),
                        Some(b"https://x/reset?a=1&b=2"),
                        Some(b"Example"),
                    ],
                ),
                (
                    3,
                    &[Some(b"Alice"), Some(b"Example")],
                    &[
                        Some(b"New account registered"),
                        Some(b"New user registered on Example."),
                        Some(b"Alice"),
                        Some(b"Example"),
                    ],
                ),
                (
                    4,
                    &[Some(b"Alice"), Some(b"Example")],
                    &[
                        Some(b"Welcome to Example"),
                        Some("Welcome to Example — your account is ready.".as_bytes()),
                        Some(b"Alice"),
                        Some(b"Example"),
                    ],
                ),
            ];
            for (operation, input, expected) in cases {
                assert_eq!(output(*operation, &wire(input)).unwrap(), wire(expected));
            }
        }

        #[test]
        fn w25_account_auth_mail_preserves_bytes_empty_and_nullable_role() {
            assert_eq!(
                output(0, &wire(&[Some(b"\0\xff"), Some(b"\xfe\0")])).unwrap(),
                wire(&[
                    Some(b"User account deleted"),
                    Some(b"User \0\xff deleted their \xfe\0 account."),
                    Some(b"\0\xff"),
                    Some(b"\xfe\0"),
                ])
            );
            for (role, expected_role, preheader) in [
                (
                    None,
                    b"User".as_slice(),
                    "Your  subscription has expired — account downgraded to User.".as_bytes(),
                ),
                (
                    Some(b"".as_slice()),
                    b"".as_slice(),
                    "Your  subscription has expired — account downgraded to .".as_bytes(),
                ),
                (
                    Some(b"\xff\0".as_slice()),
                    b"\xff\0".as_slice(),
                    b"Your  subscription has expired \xe2\x80\x94 account downgraded to \xff\0."
                        .as_slice(),
                ),
            ] {
                assert_eq!(
                    output(1, &wire(&[Some(b""), Some(b""), role])).unwrap(),
                    wire(&[
                        Some(b"Your account has expired"),
                        Some(preheader),
                        Some(b""),
                        Some(b""),
                        Some(expected_role)
                    ])
                );
            }
            for operation in 2..=4 {
                let first = b"\0\xff";
                let site = b"\xfe\0";
                let actual = output(operation, &wire(&[Some(first), Some(site)])).unwrap();
                let frames = super::super::wave22_frames(&actual).unwrap();
                assert_eq!(frames[2], Some(first.as_slice()));
                assert_eq!(frames[3], Some(site.as_slice()));
                assert!(output(operation, &wire(&[Some(b""), Some(b"")])).is_ok());
            }
        }

        #[test]
        fn w25_account_auth_mail_rejects_malformed_and_null_frames_without_writes() {
            let mut cases = vec![(5, wire(&[Some(b"u"), Some(b"s")]))];
            for operation in 0..=4 {
                let mut valid = vec![Some(b"u".as_slice()), Some(b"s".as_slice())];
                if operation == 1 {
                    valid.push(None);
                }
                cases.push((operation, Vec::new()));
                cases.push((operation, vec![1, 0, 0]));
                cases.push((operation, vec![2, 0, 0, 0, b'x']));
                cases.push((operation, vec![254, 255, 255, 255]));
                cases.push((operation, wire(&valid[..valid.len() - 1])));
                let mut excess = valid.clone();
                excess.push(Some(b""));
                cases.push((operation, wire(&excess)));
                for index in 0..2 {
                    let mut null = valid.clone();
                    null[index] = None;
                    cases.push((operation, wire(&null)));
                }
                let mut truncated = wire(&valid);
                truncated.pop();
                cases.push((operation, truncated));
            }
            for (operation, input) in cases {
                assert!(output(operation, &input).is_err());
                let mut destination = [0xabu8; 256];
                assert_eq!(
                    unsafe {
                        nntmux_w25_account_auth_mail(
                            operation,
                            input.as_ptr(),
                            input.len(),
                            destination.as_mut_ptr(),
                            destination.len(),
                        )
                    },
                    -1
                );
                assert_eq!(destination, [0xab; 256]);
            }
        }

        #[test]
        fn w25_account_auth_mail_checks_regions_capacity_and_unaligned_buffers() {
            let input = wire(&[Some(b"u"), Some(b"s")]);
            let expected = wire(&[
                Some(b"User account deleted"),
                Some(b"User u deleted their s account."),
                Some(b"u"),
                Some(b"s"),
            ]);
            let mut destination = vec![0xabu8; expected.len() + 2];
            unsafe {
                for capacity in [0, 1, expected.len() - 1] {
                    assert_eq!(
                        nntmux_w25_account_auth_mail(
                            0,
                            input.as_ptr(),
                            input.len(),
                            destination.as_mut_ptr(),
                            capacity
                        ),
                        -(expected.len() as i32)
                    );
                    assert!(destination.iter().all(|byte| *byte == 0xab));
                }
                for (source, length, target, capacity) in [
                    (
                        std::ptr::null(),
                        0,
                        destination.as_mut_ptr(),
                        destination.len(),
                    ),
                    (input.as_ptr(), input.len(), std::ptr::null_mut(), 0),
                    (
                        usize::MAX as *const u8,
                        1,
                        destination.as_mut_ptr(),
                        destination.len(),
                    ),
                    (input.as_ptr(), input.len(), usize::MAX as *mut u8, 1),
                    (
                        input.as_ptr(),
                        usize::MAX,
                        destination.as_mut_ptr(),
                        destination.len(),
                    ),
                    (
                        input.as_ptr(),
                        input.len(),
                        destination.as_mut_ptr(),
                        usize::MAX,
                    ),
                    (
                        input.as_ptr(),
                        isize::MAX as usize + 1,
                        destination.as_mut_ptr(),
                        destination.len(),
                    ),
                    (
                        input.as_ptr(),
                        input.len(),
                        destination.as_mut_ptr(),
                        isize::MAX as usize + 1,
                    ),
                ] {
                    assert_eq!(
                        nntmux_w25_account_auth_mail(0, source, length, target, capacity),
                        -1
                    );
                    assert!(destination.iter().all(|byte| *byte == 0xab));
                }
                let unaligned_input = [vec![0xab], input.clone()].concat();
                assert_eq!(
                    nntmux_w25_account_auth_mail(
                        0,
                        unaligned_input.as_ptr().add(1),
                        input.len(),
                        destination.as_mut_ptr().add(1),
                        expected.len()
                    ),
                    expected.len() as i32
                );
                assert_eq!(&destination[1..1 + expected.len()], expected);
                assert_eq!(destination[0], 0xab);
                assert_eq!(destination[expected.len() + 1], 0xab);
            }
        }

        #[test]
        fn w25_account_auth_mail_supports_exact_and_partial_overlap() {
            let input = wire(&[Some(b"u"), Some(b"s")]);
            let expected = wire(&[
                Some(b"Welcome to s"),
                Some("Welcome to s — your account is ready.".as_bytes()),
                Some(b"u"),
                Some(b"s"),
            ]);
            for (source_start, target_start) in [(0, 0), (0, 1), (3, 0)] {
                let mut buffer = vec![0xabu8; expected.len() + input.len() + 8];
                buffer[source_start..source_start + input.len()].copy_from_slice(&input);
                let capacity = buffer.len() - target_start;
                assert_eq!(
                    unsafe {
                        nntmux_w25_account_auth_mail(
                            4,
                            buffer.as_ptr().add(source_start),
                            input.len(),
                            buffer.as_mut_ptr().add(target_start),
                            capacity,
                        )
                    },
                    expected.len() as i32
                );
                assert_eq!(
                    &buffer[target_start..target_start + expected.len()],
                    expected
                );
            }
        }
    }
}

/// Render account/authentication mail fields using length-prefixed byte frames.
/// Returns bytes written, -1 for invalid input or panic, or the negated required
/// length when capacity is insufficient. Errors leave output untouched. Input
/// and output may overlap; rendering finishes before output is copied.
///
/// # Safety
/// Both regions must be non-null, allocated for the stated extents, and fit
/// isize without address overflow. Only byte alignment is required. The caller
/// guarantees allocation validity for structurally valid non-null regions.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w25_account_auth_mail(
    operation: u32,
    input: *const u8,
    len: usize,
    out: *mut u8,
    out_cap: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(input, len) || !cleaning_ffi_region_valid(out, out_cap) {
            return -1;
        }
        let output = {
            let input = unsafe { slice::from_raw_parts(input, len) };
            match wave25_account_auth_mail::output(operation, input) {
                Ok(output) => output,
                Err(_) => return -1,
            }
        };
        let Ok(needed) = i32::try_from(output.len()) else {
            return -1;
        };
        if output.len() > out_cap {
            return -needed;
        }
        unsafe { std::ptr::copy_nonoverlapping(output.as_ptr(), out, output.len()) };
        needed
    }))
    .unwrap_or(-1)
}

// Wave 26.1: contact copy, branded subjects and scalar email-job dispatch fields.
pub mod wave26_email_dispatch_jobs_mail {
    use super::{w23_frame, wave22_append_frame, wave22_frames};

    pub(super) fn output(operation: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
        let count = match operation {
            0 | 1 | 4 => 3,
            2 => 1,
            3 => 2,
            _ => return Err(-1),
        };
        let frames = wave22_frames(input)?;
        if frames.len() != count {
            return Err(-1);
        }
        let mut output = Vec::new();
        match operation {
            0 => {
                let from = w23_frame(&frames, 0)?;
                let body = w23_frame(&frames, 1)?;
                let site = w23_frame(&frames, 2)?;
                let preheader =
                    [b"New contact form submission from ".as_slice(), from, b"."].concat();
                for value in [
                    b"Contact form submitted".as_slice(),
                    preheader.as_slice(),
                    body,
                    from,
                    site,
                ] {
                    wave22_append_frame(&mut output, Some(value))?;
                }
            }
            1 => {
                let subject = w23_frame(&frames, 0)?;
                let prefix = w23_frame(&frames, 1)?;
                let enabled = w23_frame(&frames, 2)?;
                let subject = match enabled {
                    b"0" => subject.to_vec(),
                    b"1" => [prefix, subject].concat(),
                    _ => return Err(-1),
                };
                wave22_append_frame(&mut output, Some(&subject))?;
            }
            2 => wave22_append_frame(&mut output, frames[0])?,
            3 => {
                let days = w23_frame(&frames, 1)?;
                let days_text = std::str::from_utf8(days).map_err(|_| -1isize)?;
                let days_value = days_text.parse::<i64>().map_err(|_| -1isize)?;
                if days_value.to_string().as_bytes() != days {
                    return Err(-1);
                }
                wave22_append_frame(&mut output, frames[0])?;
                wave22_append_frame(&mut output, Some(days))?;
            }
            4 => {
                for index in 0..3 {
                    wave22_append_frame(&mut output, Some(w23_frame(&frames, index)?))?;
                }
            }
            _ => return Err(-1),
        }
        Ok(output)
    }

    #[cfg(test)]
    mod tests {
        use super::output;
        use crate::cleaning::nntmux_w26_email_dispatch_jobs_mail;

        fn wire(values: &[Option<&[u8]>]) -> Vec<u8> {
            let mut result = Vec::new();
            for value in values {
                match value {
                    Some(bytes) => {
                        result.extend_from_slice(&(bytes.len() as u32).to_le_bytes());
                        result.extend_from_slice(bytes);
                    }
                    None => result.extend_from_slice(&[255; 4]),
                }
            }
            result
        }

        #[test]
        fn w26_email_dispatch_jobs_mail_literals_bytes_nulls_and_integer_limits() {
            assert_eq!(
                output(
                    0,
                    &wire(&[Some(b"alice"), Some(b"<body>\0\xff"), Some(b"Site")])
                )
                .unwrap(),
                wire(&[
                    Some(b"Contact form submitted"),
                    Some(b"New contact form submission from alice."),
                    Some(b"<body>\0\xff"),
                    Some(b"alice"),
                    Some(b"Site"),
                ])
            );
            for subject in [b"".as_slice(), b"0", b"\xff\0", "é漢字".as_bytes()] {
                for flag in [b"0".as_slice(), b"1"] {
                    let expected = if flag == b"1" {
                        [b"[Site] ".as_slice(), subject].concat()
                    } else {
                        subject.to_vec()
                    };
                    assert_eq!(
                        output(1, &wire(&[Some(subject), Some(b"[Site] "), Some(flag)])).unwrap(),
                        wire(&[Some(&expected)])
                    );
                }
                assert_eq!(
                    output(0, &wire(&[Some(subject), Some(subject), Some(subject)])).unwrap(),
                    wire(&[
                        Some(b"Contact form submitted"),
                        Some(
                            &[
                                b"New contact form submission from ".as_slice(),
                                subject,
                                b"."
                            ]
                            .concat()
                        ),
                        Some(subject),
                        Some(subject),
                        Some(subject),
                    ])
                );
                assert_eq!(
                    output(4, &wire(&[Some(subject), Some(subject), Some(subject)])).unwrap(),
                    wire(&[Some(subject), Some(subject), Some(subject)])
                );
            }
            for recipient in [None, Some(b"".as_slice()), Some(b"0"), Some(b"\xff\0")] {
                assert_eq!(output(2, &wire(&[recipient])).unwrap(), wire(&[recipient]));
                for days in [
                    b"-9223372036854775808".as_slice(),
                    b"9223372036854775807",
                    b"-1",
                    b"0",
                    b"1",
                ] {
                    assert_eq!(
                        output(3, &wire(&[recipient, Some(days)])).unwrap(),
                        wire(&[recipient, Some(days)])
                    );
                }
            }
        }

        #[test]
        fn w26_email_dispatch_jobs_mail_rejects_invalid_frames_without_writes() {
            for operation in 0..=4 {
                let valid = match operation {
                    1 => wire(&[Some(b"s"), Some(b"p"), Some(b"1")]),
                    2 => wire(&[None]),
                    3 => wire(&[None, Some(b"-1")]),
                    _ => wire(&[Some(b"a"), Some(b"b"), Some(b"c")]),
                };
                let mut invalid = vec![
                    Vec::new(),
                    vec![1, 0, 0],
                    vec![2, 0, 0, 0, b'x'],
                    vec![254, 255, 255, 255],
                    valid[..valid.len() - 1].to_vec(),
                    [valid.clone(), wire(&[Some(b"extra")])].concat(),
                ];
                if operation == 1 {
                    for flag in [None, Some(b"".as_slice()), Some(b"true"), Some(b"2")] {
                        invalid.push(wire(&[Some(b"s"), Some(b"p"), flag]));
                    }
                }
                if operation == 3 {
                    for days in [
                        None,
                        Some(b"".as_slice()),
                        Some(b"01"),
                        Some(b"-0"),
                        Some(b"+1"),
                        Some(b"1.0"),
                        Some(b" 1"),
                        Some(b"9223372036854775808"),
                        Some(b"-9223372036854775809"),
                        Some(b"\xff"),
                    ] {
                        invalid.push(wire(&[None, days]));
                    }
                }
                let count = if operation == 2 {
                    1
                } else if operation == 3 {
                    2
                } else {
                    3
                };
                for index in 0..count {
                    if (operation == 2 || operation == 3) && index == 0 {
                        continue;
                    }
                    let mut fields = vec![Some(b"1".as_slice()); count];
                    fields[index] = None;
                    invalid.push(wire(&fields));
                }
                for input in invalid {
                    assert!(output(operation, &input).is_err());
                    let mut out = [0xabu8; 256];
                    assert_eq!(
                        unsafe {
                            nntmux_w26_email_dispatch_jobs_mail(
                                operation,
                                input.as_ptr(),
                                input.len(),
                                out.as_mut_ptr(),
                                out.len(),
                            )
                        },
                        -1
                    );
                    assert_eq!(out, [0xab; 256]);
                }
            }
            assert!(output(5, &[]).is_err());
        }

        #[test]
        fn w26_email_dispatch_jobs_mail_regions_capacity_unaligned_and_overlap() {
            for operation in 0..=4 {
                let input = match operation {
                    1 => wire(&[Some(b"s"), Some(b"p"), Some(b"1")]),
                    2 => wire(&[None]),
                    3 => wire(&[None, Some(b"-1")]),
                    _ => wire(&[Some(b"a"), Some(b"b"), Some(b"c")]),
                };
                let expected = output(operation, &input).unwrap();
                let mut out = [0xabu8; 256];
                unsafe {
                    for capacity in [0, 1, expected.len() - 1] {
                        assert_eq!(
                            nntmux_w26_email_dispatch_jobs_mail(
                                operation,
                                input.as_ptr(),
                                input.len(),
                                out.as_mut_ptr(),
                                capacity
                            ),
                            -(expected.len() as i32)
                        );
                        assert_eq!(out, [0xab; 256]);
                    }
                    for (source, length, target, capacity) in [
                        (std::ptr::null(), 0, out.as_mut_ptr(), out.len()),
                        (input.as_ptr(), input.len(), std::ptr::null_mut(), 0),
                        (usize::MAX as *const u8, 1, out.as_mut_ptr(), out.len()),
                        (input.as_ptr(), input.len(), usize::MAX as *mut u8, 1),
                        (input.as_ptr(), usize::MAX, out.as_mut_ptr(), out.len()),
                        (input.as_ptr(), input.len(), out.as_mut_ptr(), usize::MAX),
                        (
                            input.as_ptr(),
                            isize::MAX as usize + 1,
                            out.as_mut_ptr(),
                            out.len(),
                        ),
                        (
                            input.as_ptr(),
                            input.len(),
                            out.as_mut_ptr(),
                            isize::MAX as usize + 1,
                        ),
                    ] {
                        assert_eq!(
                            nntmux_w26_email_dispatch_jobs_mail(
                                operation, source, length, target, capacity
                            ),
                            -1
                        );
                        assert_eq!(out, [0xab; 256]);
                    }
                    let unaligned = [vec![0xab], input.clone()].concat();
                    assert_eq!(
                        nntmux_w26_email_dispatch_jobs_mail(
                            operation,
                            unaligned.as_ptr().add(1),
                            input.len(),
                            out.as_mut_ptr().add(1),
                            expected.len()
                        ),
                        expected.len() as i32
                    );
                    assert_eq!(&out[1..=expected.len()], expected);
                    assert_eq!(out[0], 0xab);
                    assert_eq!(out[expected.len() + 1], 0xab);
                    for (source_offset, target_offset) in [(0, 0), (0, 1), (3, 0)] {
                        let mut buffer = [0xabu8; 256];
                        buffer[source_offset..source_offset + input.len()].copy_from_slice(&input);
                        let before = buffer;
                        assert_eq!(
                            nntmux_w26_email_dispatch_jobs_mail(
                                operation,
                                buffer.as_ptr().add(source_offset),
                                input.len(),
                                buffer.as_mut_ptr().add(target_offset),
                                expected.len()
                            ),
                            expected.len() as i32
                        );
                        assert_eq!(
                            &buffer[target_offset..target_offset + expected.len()],
                            expected
                        );
                        assert_eq!(&buffer[..target_offset], &before[..target_offset]);
                        assert_eq!(
                            &buffer[target_offset + expected.len()..],
                            &before[target_offset + expected.len()..]
                        );
                    }
                }
            }
        }
    }
}

/// Render contact mail, branded subject and email-job dispatch byte frames.
/// Returns bytes written, -1 on invalid input/panic, or negated required size.
/// Errors leave output untouched; input and output may overlap.
///
/// # Safety
/// Both regions must be non-null and allocated for their stated extents, with
/// lengths fitting isize and no address overflow. Only byte alignment is needed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w26_email_dispatch_jobs_mail(
    operation: u32,
    input: *const u8,
    len: usize,
    out: *mut u8,
    out_cap: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(input, len) || !cleaning_ffi_region_valid(out, out_cap) {
            return -1;
        }
        let output = {
            let input = unsafe { slice::from_raw_parts(input, len) };
            match wave26_email_dispatch_jobs_mail::output(operation, input) {
                Ok(output) => output,
                Err(_) => return -1,
            }
        };
        let Ok(needed) = i32::try_from(output.len()) else {
            return -1;
        };
        if output.len() > out_cap {
            return -needed;
        }
        unsafe { std::ptr::copy_nonoverlapping(output.as_ptr(), out, output.len()) };
        needed
    }))
    .unwrap_or(-1)
}

pub mod wave27_account_mail_dispatch_jobs {
    use super::{wave22_append_frame, wave22_frames};

    pub(super) fn output(operation: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
        let count = match operation {
            0 | 1 | 2 | 4 => 1,
            3 => 2,
            _ => return Err(-1),
        };
        let frames = wave22_frames(input)?;
        if frames.len() != count || (operation == 3 && frames[1].is_none()) {
            return Err(-1);
        }
        let mut output = Vec::new();
        for frame in frames {
            wave22_append_frame(&mut output, frame)?;
        }
        // The original account-mail handlers always dispatch, even for null recipients.
        wave22_append_frame(&mut output, Some(b"1"))?;
        Ok(output)
    }

    #[cfg(test)]
    mod tests {
        use super::output;
        use crate::cleaning::nntmux_w27_account_mail_dispatch_jobs;

        fn wire(values: &[Option<&[u8]>]) -> Vec<u8> {
            let mut result = Vec::new();
            for value in values {
                match value {
                    Some(bytes) => {
                        result.extend_from_slice(&(bytes.len() as u32).to_le_bytes());
                        result.extend_from_slice(bytes);
                    }
                    None => result.extend_from_slice(&u32::MAX.to_le_bytes()),
                }
            }
            result
        }

        #[test]
        fn w27_account_mail_dispatch_jobs_protocol_literals_bytes_null_and_large() {
            let large = vec![0xfe; 131_073];
            let values: [Option<&[u8]>; 7] = [
                None,
                Some(b""),
                Some(b"0"),
                Some(b"user@example.test"),
                Some(b"\0\xff\x80\r\n"),
                Some(b" null \0"),
                Some(&large),
            ];
            for operation in 0..=4 {
                for recipient in values {
                    let links: &[&[u8]] = if operation == 3 {
                        &[b"", b"0", b"\0\xff\x80", &large]
                    } else {
                        &[b"unused"]
                    };
                    for link in links {
                        let fields = if operation == 3 {
                            vec![recipient, Some(*link)]
                        } else {
                            vec![recipient]
                        };
                        let input = wire(&fields);
                        let mut expected = input.clone();
                        expected.extend_from_slice(&[1, 0, 0, 0, b'1']);
                        assert_eq!(output(operation, &input).unwrap(), expected);
                        let mut target = vec![0xab; expected.len() + 1];
                        assert_eq!(
                            unsafe {
                                nntmux_w27_account_mail_dispatch_jobs(
                                    operation,
                                    input.as_ptr(),
                                    input.len(),
                                    target.as_mut_ptr(),
                                    expected.len(),
                                )
                            },
                            expected.len() as i32
                        );
                        assert_eq!(&target[..expected.len()], expected);
                        assert_eq!(target[expected.len()], 0xab);
                    }
                }
            }
            assert_eq!(
                output(0, &[255, 255, 255, 255]).unwrap(),
                [255, 255, 255, 255, 1, 0, 0, 0, b'1']
            );
            assert_eq!(
                output(3, &[0, 0, 0, 0, 0, 0, 0, 0]).unwrap(),
                [0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, b'1']
            );
        }

        #[test]
        fn w27_account_mail_dispatch_jobs_rejects_frames_and_unknown_operations() {
            for operation in 0..=4 {
                let valid = if operation == 3 {
                    wire(&[None, Some(b"reset")])
                } else {
                    wire(&[None])
                };
                let mut invalid = vec![
                    Vec::new(),
                    vec![0],
                    vec![0, 0, 0],
                    vec![2, 0, 0, 0, b'x'],
                    vec![254, 255, 255, 255],
                ];
                for end in 0..valid.len() {
                    invalid.push(valid[..end].to_vec());
                }
                invalid.push([valid.clone(), wire(&[None])].concat());
                invalid.push([valid.clone(), vec![0]].concat());
                if operation == 3 {
                    invalid.push(wire(&[None, None]));
                    invalid.push(wire(&[Some(b"x"), None]));
                }
                for input in invalid {
                    assert!(output(operation, &input).is_err());
                    let mut target = [0xab; 64];
                    assert_eq!(
                        unsafe {
                            nntmux_w27_account_mail_dispatch_jobs(
                                operation,
                                input.as_ptr(),
                                input.len(),
                                target.as_mut_ptr(),
                                target.len(),
                            )
                        },
                        -1
                    );
                    assert_eq!(target, [0xab; 64]);
                }
            }
            for operation in [5, 6, u32::MAX] {
                let input = wire(&[None]);
                assert!(output(operation, &input).is_err());
                let mut target = [0xab; 64];
                assert_eq!(
                    unsafe {
                        nntmux_w27_account_mail_dispatch_jobs(
                            operation,
                            input.as_ptr(),
                            input.len(),
                            target.as_mut_ptr(),
                            target.len(),
                        )
                    },
                    -1
                );
                assert_eq!(target, [0xab; 64]);
            }
        }

        #[test]
        fn w27_account_mail_dispatch_jobs_regions_capacity_unaligned_and_overlap() {
            for operation in 0..=4 {
                let input = if operation == 3 {
                    wire(&[None, Some(b"reset\0\xff")])
                } else {
                    wire(&[Some(b"0\0\xff")])
                };
                let expected = output(operation, &input).unwrap();
                let mut target = [0xab; 128];
                unsafe {
                    for capacity in [0, 1, expected.len() - 1] {
                        assert_eq!(
                            nntmux_w27_account_mail_dispatch_jobs(
                                operation,
                                input.as_ptr(),
                                input.len(),
                                target.as_mut_ptr(),
                                capacity
                            ),
                            -(expected.len() as i32)
                        );
                        assert_eq!(target, [0xab; 128]);
                    }
                    for (source, length, out, capacity) in [
                        (std::ptr::null(), 0, target.as_mut_ptr(), target.len()),
                        (
                            std::ptr::null(),
                            input.len(),
                            target.as_mut_ptr(),
                            target.len(),
                        ),
                        (input.as_ptr(), input.len(), std::ptr::null_mut(), 0),
                        (input.as_ptr(), input.len(), std::ptr::null_mut(), 10),
                        (
                            usize::MAX as *const u8,
                            1,
                            target.as_mut_ptr(),
                            target.len(),
                        ),
                        (input.as_ptr(), input.len(), usize::MAX as *mut u8, 1),
                        (
                            input.as_ptr(),
                            usize::MAX,
                            target.as_mut_ptr(),
                            target.len(),
                        ),
                        (input.as_ptr(), input.len(), target.as_mut_ptr(), usize::MAX),
                        (
                            input.as_ptr(),
                            isize::MAX as usize + 1,
                            target.as_mut_ptr(),
                            target.len(),
                        ),
                        (
                            input.as_ptr(),
                            input.len(),
                            target.as_mut_ptr(),
                            isize::MAX as usize + 1,
                        ),
                    ] {
                        assert_eq!(
                            nntmux_w27_account_mail_dispatch_jobs(
                                operation, source, length, out, capacity
                            ),
                            -1
                        );
                        assert_eq!(target, [0xab; 128]);
                    }
                    let unaligned = [vec![0xab], input.clone()].concat();
                    assert_eq!(
                        nntmux_w27_account_mail_dispatch_jobs(
                            operation,
                            unaligned.as_ptr().add(1),
                            input.len(),
                            target.as_mut_ptr().add(1),
                            expected.len()
                        ),
                        expected.len() as i32
                    );
                    assert_eq!(&target[1..1 + expected.len()], expected);
                    assert_eq!(target[0], 0xab);
                    assert!(
                        target[1 + expected.len()..]
                            .iter()
                            .all(|byte| *byte == 0xab)
                    );
                    for (source_offset, target_offset) in [(0, 0), (0, 1), (3, 0)] {
                        for capacity in [0, 1, expected.len() - 1, expected.len()] {
                            let mut buffer = [0xab; 128];
                            buffer[source_offset..source_offset + input.len()]
                                .copy_from_slice(&input);
                            let before = buffer;
                            let actual = nntmux_w27_account_mail_dispatch_jobs(
                                operation,
                                buffer.as_ptr().add(source_offset),
                                input.len(),
                                buffer.as_mut_ptr().add(target_offset),
                                capacity,
                            );
                            if capacity < expected.len() {
                                assert_eq!(actual, -(expected.len() as i32));
                                assert_eq!(buffer, before);
                            } else {
                                assert_eq!(actual, expected.len() as i32);
                                assert_eq!(
                                    &buffer[target_offset..target_offset + expected.len()],
                                    expected
                                );
                                assert_eq!(&buffer[..target_offset], &before[..target_offset]);
                                assert_eq!(
                                    &buffer[target_offset + expected.len()..],
                                    &before[target_offset + expected.len()..]
                                );
                            }
                        }
                    }
                }
            }
        }
    }
}

/// Render account-mail dispatch frames, preserving every recipient and reset-link byte.
/// Returns bytes written, -1 on invalid input/panic, or negated required size.
/// Errors leave output untouched; input and output may overlap.
///
/// # Safety
/// Both regions must be non-null and allocated for their stated extents, with
/// lengths fitting isize and no address overflow. Only byte alignment is needed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w27_account_mail_dispatch_jobs(
    operation: u32,
    input: *const u8,
    len: usize,
    out: *mut u8,
    out_cap: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(input, len) || !cleaning_ffi_region_valid(out, out_cap) {
            return -1;
        }
        let output = {
            let input = unsafe { slice::from_raw_parts(input, len) };
            match wave27_account_mail_dispatch_jobs::output(operation, input) {
                Ok(output) => output,
                Err(_) => return -1,
            }
        };
        let Ok(needed) = i32::try_from(output.len()) else {
            return -1;
        };
        if output.len() > out_cap {
            return -needed;
        }
        unsafe { std::ptr::copy_nonoverlapping(output.as_ptr(), out, output.len()) };
        needed
    }))
    .unwrap_or(-1)
}
/// Pure decisions used by the Wave 28.1 admin regex and group controllers.
pub mod wave28_admin_regex_group_controllers {
    use super::{remover_is_numeric, wave22_append_frame, wave22_canonical_i64, wave22_frames};
    use std::collections::HashSet;

    #[derive(Clone, Debug, PartialEq, Eq, Hash)]
    enum Key {
        Int(i64),
        String(Vec<u8>),
    }

    #[derive(Clone, Debug, PartialEq, Eq)]
    enum Node {
        Null,
        Bool(bool),
        Int(i64),
        Float(Vec<u8>),
        String(Vec<u8>),
        Array(Vec<(Key, Node)>),
    }

    fn string(value: impl AsRef<[u8]>) -> Node {
        Node::String(value.as_ref().to_vec())
    }

    fn map(values: Vec<(&str, Node)>) -> Node {
        Node::Array(
            values
                .into_iter()
                .map(|(key, value)| (Key::String(key.as_bytes().to_vec()), value))
                .collect(),
        )
    }

    fn list(values: Vec<Node>) -> Node {
        Node::Array(
            values
                .into_iter()
                .enumerate()
                .map(|(key, value)| (Key::Int(key as i64), value))
                .collect(),
        )
    }

    fn array(node: &Node) -> Result<&[(Key, Node)], isize> {
        match node {
            Node::Array(values) => Ok(values),
            _ => Err(-1),
        }
    }

    fn field<'a>(values: &'a [(Key, Node)], name: &str) -> &'a Node {
        values
            .iter()
            .find_map(|(key, value)| match key {
                Key::String(key) if key == name.as_bytes() => Some(value),
                _ => None,
            })
            .unwrap_or(&Node::Null)
    }

    fn has(values: &[(Key, Node)], name: &str) -> bool {
        values
            .iter()
            .any(|(key, _)| matches!(key, Key::String(key) if key == name.as_bytes()))
    }

    fn set(values: &mut Vec<(Key, Node)>, name: &str, value: Node) {
        if let Some((_, current)) = values
            .iter_mut()
            .find(|(key, _)| matches!(key, Key::String(key) if key == name.as_bytes()))
        {
            *current = value;
        } else {
            values.push((Key::String(name.as_bytes().to_vec()), value));
        }
    }

    fn bytes(node: &Node) -> Result<&[u8], isize> {
        match node {
            Node::String(value) => Ok(value),
            _ => Err(-1),
        }
    }

    fn action(values: &[(Key, Node)]) -> Result<&[u8], isize> {
        match field(values, "action") {
            Node::Null => Ok(b""),
            value => bytes(value),
        }
    }

    fn request(values: &[(Key, Node)]) -> Result<Vec<(Key, Node)>, isize> {
        match field(values, "input") {
            Node::Null => Ok(Vec::new()),
            value => Ok(array(value)?.to_vec()),
        }
    }

    fn canonical_count(value: &[u8]) -> Result<usize, isize> {
        let text = std::str::from_utf8(value).map_err(|_| -1isize)?;
        let count = text.parse::<usize>().map_err(|_| -1isize)?;
        if count.to_string().as_bytes() != value {
            return Err(-1);
        }
        Ok(count)
    }

    fn key_identity(key: &Key) -> Key {
        match key {
            Key::String(value) => wave22_canonical_i64(value)
                .map(Key::Int)
                .unwrap_or_else(|_| key.clone()),
            _ => key.clone(),
        }
    }

    fn parse_node(
        frames: &[Option<&[u8]>],
        cursor: &mut usize,
        depth: usize,
    ) -> Result<Node, isize> {
        if depth > 64 {
            return Err(-1);
        }
        let frame = frames
            .get(*cursor)
            .and_then(|value| *value)
            .ok_or(-1isize)?;
        *cursor += 1;
        let (&kind, value) = frame.split_first().ok_or(-1isize)?;
        match kind {
            b'n' if value.is_empty() => Ok(Node::Null),
            b'b' if value == b"0" => Ok(Node::Bool(false)),
            b'b' if value == b"1" => Ok(Node::Bool(true)),
            b'i' => Ok(Node::Int(wave22_canonical_i64(value)?)),
            b'f' if !value.is_empty()
                && remover_is_numeric(value)
                && !value
                    .iter()
                    .any(|byte| byte.is_ascii_whitespace() || *byte == 0x0b) =>
            {
                let text = std::str::from_utf8(value).map_err(|_| -1isize)?;
                // Non-finite values use the PHP fallback, including overflowing decimals.
                if !text.parse::<f64>().map_err(|_| -1isize)?.is_finite() {
                    return Err(-1);
                }
                Ok(Node::Float(value.to_vec()))
            }
            b's' => Ok(Node::String(value.to_vec())),
            b'a' => {
                let count = canonical_count(value)?;
                if count > frames.len().saturating_sub(*cursor) / 2 {
                    return Err(-1);
                }
                let mut values = Vec::with_capacity(count);
                let mut keys = HashSet::with_capacity(count);
                for _ in 0..count {
                    let key = match parse_node(frames, cursor, depth + 1)? {
                        Node::Int(value) => Key::Int(value),
                        Node::String(value) => Key::String(value),
                        _ => return Err(-1),
                    };
                    if !keys.insert(key_identity(&key)) {
                        return Err(-1);
                    }
                    let value = parse_node(frames, cursor, depth + 1)?;
                    values.push((key, value));
                }
                Ok(Node::Array(values))
            }
            _ => Err(-1),
        }
    }

    fn decode(input: &[u8]) -> Result<Node, isize> {
        let frames = wave22_frames(input)?;
        if frames.iter().any(Option::is_none) {
            return Err(-1);
        }
        let mut cursor = 0;
        let root = parse_node(&frames, &mut cursor, 0)?;
        array(&root)?;
        if cursor != frames.len() {
            return Err(-1);
        }
        Ok(root)
    }

    fn encode_node(node: &Node, output: &mut Vec<u8>) -> Result<(), isize> {
        let frame = match node {
            Node::Null => b"n".to_vec(),
            Node::Bool(false) => b"b0".to_vec(),
            Node::Bool(true) => b"b1".to_vec(),
            Node::Int(value) => [b"i".as_slice(), value.to_string().as_bytes()].concat(),
            Node::Float(value) => [b"f".as_slice(), value].concat(),
            Node::String(value) => [b"s".as_slice(), value].concat(),
            Node::Array(values) => [b"a".as_slice(), values.len().to_string().as_bytes()].concat(),
        };
        wave22_append_frame(output, Some(&frame))?;
        if let Node::Array(values) = node {
            for (key, value) in values {
                match key {
                    Key::Int(value) => encode_node(&Node::Int(*value), output)?,
                    Key::String(value) => encode_node(&Node::String(value.clone()), output)?,
                }
                encode_node(value, output)?;
            }
        }
        Ok(())
    }

    fn empty(node: &Node) -> bool {
        match node {
            Node::Null | Node::Bool(false) | Node::Int(0) => true,
            Node::Float(value) => std::str::from_utf8(value)
                .ok()
                .and_then(|text| text.parse::<f64>().ok())
                .is_some_and(|value| value == 0.0),
            Node::String(value) => value.is_empty() || value == b"0",
            Node::Array(value) => value.is_empty(),
            _ => false,
        }
    }

    fn numeric(node: &Node) -> bool {
        match node {
            Node::Int(_) | Node::Float(_) => true,
            Node::String(value) => remover_is_numeric(value),
            _ => false,
        }
    }

    fn trim_numeric(value: &[u8]) -> &[u8] {
        let is_space = |byte: &u8| matches!(byte, b' ' | b'\t' | b'\n' | 0x0b | 0x0c | b'\r');
        let start = value
            .iter()
            .position(|byte| !is_space(byte))
            .unwrap_or(value.len());
        let end = value
            .iter()
            .rposition(|byte| !is_space(byte))
            .map_or(start, |index| index + 1);
        &value[start..end]
    }

    fn nonnegative_numeric(node: &Node) -> bool {
        match node {
            Node::Int(value) => *value >= 0,
            Node::Float(value) | Node::String(value) if numeric(node) => {
                let trimmed = trim_numeric(value);
                std::str::from_utf8(trimmed)
                    .ok()
                    .and_then(|text| text.parse::<f64>().ok())
                    .is_some_and(|value| value >= 0.0)
            }
            _ => false,
        }
    }

    fn validate_id(node: &Node) -> Result<Node, isize> {
        let parsed = match node {
            Node::Bool(true) => Some(1),
            Node::Int(value) => Some(*value),
            Node::Float(value) => {
                let value = std::str::from_utf8(value)
                    .map_err(|_| -1isize)?
                    .parse::<f64>()
                    .map_err(|_| -1isize)?;
                // PHP's float string cast uses configured precision. Decline cases
                // where rounding or scientific notation can affect FILTER_VALIDATE_INT.
                if value.fract() != 0.0 || value.abs() >= 1e14 {
                    return Err(-1);
                }
                Some(value as i64)
            }
            Node::String(value) => {
                // FILTER_VALIDATE_INT trims SP/HT/LF/CR/VT, but not form feed.
                if value.contains(&0x0c) {
                    return Ok(Node::Bool(false));
                }
                let trimmed = trim_numeric(value);
                let value = trimmed.strip_prefix(b"+").unwrap_or(trimmed);
                wave22_canonical_i64(value).ok()
            }
            _ => None,
        };
        Ok(parsed
            .filter(|value| *value >= 1)
            .map(Node::Int)
            .unwrap_or(Node::Bool(false)))
    }

    fn blacklist_defaults() -> Node {
        map(vec![
            ("id", string(b"")),
            ("groupname", string(b"")),
            ("regex", string(b"")),
            ("description", string(b"")),
            ("msgcol", Node::Int(1)),
            ("status", Node::Int(1)),
            ("optype", Node::Int(1)),
        ])
    }

    fn blacklist(values: &[(Key, Node)]) -> Result<Node, isize> {
        let request = request(values)?;
        let action = action(values)?;
        let mut regex = blacklist_defaults();
        let mut error = b"".as_slice();
        let mut title = b"Binary Black/White list".as_slice();
        let mut save = Node::Null;
        let mut load = false;
        match action {
            b"submit" => {
                if matches!(field(&request, "groupname"), Node::String(value) if value.is_empty()) {
                    error = b"Group must be a valid usenet group";
                } else if matches!(field(&request, "regex"), Node::String(value) if value.is_empty())
                {
                    error = b"Regex cannot be empty";
                } else {
                    save = string(if empty(field(&request, "id")) {
                        b"add".as_slice()
                    } else {
                        b"update".as_slice()
                    });
                }
            }
            b"addtest" => {
                if has(&request, "regex") && has(&request, "groupname") {
                    if let Node::Array(values) = &mut regex {
                        set(values, "ordinal", Node::Int(1));
                    }
                }
            }
            _ if has(&request, "id") => {
                title = b"Binary Black/Whitelist Edit";
                load = true;
            }
            _ => title = b"Binary Black/Whitelist Add",
        }
        Ok(map(vec![
            ("error", string(error)),
            ("regex", regex),
            ("title", string(title)),
            ("meta_title", string(b"Binary Black/White list")),
            ("save", save),
            ("load", Node::Bool(load)),
        ]))
    }

    fn metadata() -> Node {
        map(vec![
            ("status_ids", list(vec![Node::Int(1), Node::Int(0)])),
            ("status_names", list(vec![string(b"Yes"), string(b"No")])),
            ("optype_ids", list(vec![Node::Int(1), Node::Int(2)])),
            (
                "optype_names",
                list(vec![string(b"Black"), string(b"White")]),
            ),
            (
                "msgcol_ids",
                list(vec![Node::Int(1), Node::Int(2), Node::Int(3)]),
            ),
            (
                "msgcol_names",
                list(vec![
                    string(b"Subject"),
                    string(b"Poster"),
                    string(b"MessageId"),
                ]),
            ),
        ])
    }

    fn validate(values: &[(Key, Node)], normalize_description: bool) -> Result<Node, isize> {
        let mut request = request(values)?;
        let mut error = b"".as_slice();
        let mut id = Node::Null;
        if action(values)? == b"submit" {
            if empty(field(&request, "group_regex")) {
                error = b"Group regex must not be empty!";
            } else if empty(field(&request, "regex")) {
                error = b"Regex cannot be empty";
            } else {
                if normalize_description && empty(field(&request, "description")) {
                    set(&mut request, "description", string(b""));
                }
                if !nonnegative_numeric(field(&request, "ordinal")) {
                    error = b"Ordinal must be a number, 0 or higher.";
                }
            }
        } else if has(&request, "id") {
            id = validate_id(field(&request, "id"))?;
        }
        Ok(map(vec![
            ("error", string(error)),
            ("input", Node::Array(request)),
            ("id", id),
        ]))
    }

    fn category_labels(values: &[(Key, Node)]) -> Result<Node, isize> {
        let rows = match field(values, "categories") {
            Node::Null => &[][..],
            value => array(value)?,
        };
        let mut ids = Vec::with_capacity(rows.len());
        let mut names = Vec::with_capacity(rows.len());
        for (position, (key, row)) in rows.iter().enumerate() {
            if key != &Key::Int(position as i64) {
                return Err(-1);
            }
            let row = array(row)?;
            let id = field(row, "id");
            let id_text = match id {
                Node::Int(value) => value.to_string().into_bytes(),
                Node::String(value) => value.clone(),
                _ => return Err(-1),
            };
            let name = [
                bytes(field(row, "parent_title"))?,
                b" ",
                bytes(field(row, "title"))?,
                b": ",
                &id_text,
            ]
            .concat();
            ids.push(id.clone());
            names.push(string(name));
        }
        Ok(map(vec![
            ("category_ids", list(ids)),
            ("category_names", list(names)),
        ]))
    }

    fn collection_defaults() -> Node {
        map(vec![
            ("id", string(b"")),
            ("regex", string(b"")),
            ("description", string(b"")),
            ("group_regex", string(b"")),
            ("ordinal", string(b"")),
            ("status", Node::Int(1)),
        ])
    }

    fn trim_parameter(value: &Node) -> Result<Node, isize> {
        if empty(value) {
            return Ok(string(b""));
        }
        let value = match value {
            Node::String(value) => value.clone(),
            // The controllers use strict_types: nonempty non-strings must let
            // PHP raise trim()'s TypeError instead of coercing the value.
            _ => return Err(-1),
        };
        let is_trim = |byte: &u8| matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b);
        let start = value
            .iter()
            .position(|byte| !is_trim(byte))
            .unwrap_or(value.len());
        let end = value
            .iter()
            .rposition(|byte| !is_trim(byte))
            .map_or(start, |index| index + 1);
        Ok(string(&value[start..end]))
    }

    fn parameters(values: &[(Key, Node)], release: bool) -> Result<Node, isize> {
        let request = request(values)?;
        let mut result = vec![
            ("group", trim_parameter(field(&request, "group"))?),
            ("regex", trim_parameter(field(&request, "regex"))?),
        ];
        for (name, default) in if release {
            vec![("showlimit", 250), ("querylimit", 100000)]
        } else {
            vec![("limit", 50)]
        } {
            let value = field(&request, name);
            result.push((
                name,
                if numeric(value) {
                    value.clone()
                } else {
                    Node::Int(default)
                },
            ));
        }
        Ok(map(result))
    }

    fn group_defaults() -> Node {
        map(vec![
            ("id", string(b"")),
            ("name", string(b"")),
            ("description", string(b"")),
            ("minfilestoformrelease", Node::Int(0)),
            ("active", Node::Int(0)),
            ("backfill", Node::Int(0)),
            ("minsizetoformrelease", Node::Int(0)),
            ("first_record", Node::Int(0)),
            ("last_record", Node::Int(0)),
            ("backfill_target", Node::Int(0)),
        ])
    }

    fn group_query(values: &[(Key, Node)]) -> Result<Node, isize> {
        let group = match field(values, "groupname") {
            Node::Null => string(b""),
            value => {
                bytes(value)?;
                value.clone()
            }
        };
        let mut filters = match field(values, "filters") {
            Node::Null => Vec::new(),
            value => array(value)?.to_vec(),
        };
        match field(values, "active") {
            Node::Null => {}
            Node::Bool(value) => set(
                &mut filters,
                "active",
                string(if *value { b"1" } else { b"0" }),
            ),
            _ => return Err(-1),
        }
        let mut query = vec![(Key::String(b"groupname".to_vec()), group)];
        query.extend(
            filters
                .iter()
                .filter(|(key, _)| key != &Key::String(b"groupname".to_vec()))
                .cloned(),
        );
        Ok(map(vec![
            ("filters", Node::Array(filters)),
            ("listQuery", Node::Array(query)),
        ]))
    }

    fn group_title(values: &[(Key, Node)]) -> Result<Node, isize> {
        let has_id = match field(values, "has_id") {
            Node::Null => false,
            Node::Bool(value) => *value,
            _ => return Err(-1),
        };
        Ok(map(vec![(
            "title",
            string(if action(values)? == b"submit" {
                b"Group Edit".as_slice()
            } else if has_id {
                b"Newsgroup Edit".as_slice()
            } else {
                b"Newsgroup Add".as_slice()
            }),
        )]))
    }

    pub(super) fn output(operation: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
        let root = decode(input)?;
        let values = array(&root)?;
        let result = match operation {
            0 => blacklist(values)?,
            1 => metadata(),
            2 => validate(values, false)?,
            3 => category_labels(values)?,
            4 | 7 => validate(values, true)?,
            5 => collection_defaults(),
            6 => parameters(values, false)?,
            8 => parameters(values, true)?,
            9 => group_defaults(),
            10 => group_query(values)?,
            11 => group_title(values)?,
            _ => return Err(-1),
        };
        let mut output = Vec::new();
        encode_node(&result, &mut output)?;
        Ok(output)
    }
    #[cfg(test)]
    mod tests {
        use super::*;
        use crate::cleaning::nntmux_w28_admin_regex_group_controllers;

        fn wire(node: &Node) -> Vec<u8> {
            let mut input = Vec::new();
            encode_node(node, &mut input).unwrap();
            input
        }

        fn frames(values: &[Option<&[u8]>]) -> Vec<u8> {
            let mut input = Vec::new();
            for value in values {
                wave22_append_frame(&mut input, *value).unwrap();
            }
            input
        }

        fn run(operation: u32, input: Node) -> Node {
            decode(&output(operation, &wire(&input)).unwrap()).unwrap()
        }

        fn get<'a>(node: &'a Node, name: &str) -> &'a Node {
            field(array(node).unwrap(), name)
        }

        fn submit(input: Node) -> Node {
            map(vec![("action", string(b"submit")), ("input", input)])
        }

        #[test]
        fn w28_admin_regex_group_controllers_blacklist_actions_and_metadata() {
            let defaults = blacklist_defaults();
            let result = run(0, map(Vec::new()));
            assert_eq!(
                get(&result, "title"),
                &string(b"Binary Black/Whitelist Add")
            );
            assert_eq!(get(&result, "regex"), &defaults);
            assert_eq!(
                get(&result, "meta_title"),
                &string(b"Binary Black/White list")
            );
            assert_eq!(get(&result, "load"), &Node::Bool(false));
            let result = run(0, map(vec![("input", map(vec![("id", Node::Null)]))]));
            assert_eq!(get(&result, "load"), &Node::Bool(true));
            assert_eq!(
                get(&result, "title"),
                &string(b"Binary Black/Whitelist Edit")
            );
            for (input, error, save) in [
                (
                    map(vec![("groupname", string(b"")), ("regex", string(b""))]),
                    string(b"Group must be a valid usenet group"),
                    Node::Null,
                ),
                (
                    map(vec![("groupname", string(b"0")), ("regex", string(b""))]),
                    string(b"Regex cannot be empty"),
                    Node::Null,
                ),
                (map(Vec::new()), string(b""), string(b"add")),
                (map(vec![("id", string(b"0"))]), string(b""), string(b"add")),
                (
                    map(vec![("id", string(b"5"))]),
                    string(b""),
                    string(b"update"),
                ),
            ] {
                let result = run(0, submit(input));
                assert_eq!(get(&result, "error"), &error);
                assert_eq!(get(&result, "save"), &save);
                assert_eq!(get(&result, "title"), &string(b"Binary Black/White list"));
            }
            let result = run(
                0,
                map(vec![
                    ("action", string(b"addtest")),
                    (
                        "input",
                        map(vec![
                            ("groupname", string(b"real.group")),
                            ("regex", string(b"pattern")),
                        ]),
                    ),
                ]),
            );
            let mut expected = array(&defaults).unwrap().to_vec();
            set(&mut expected, "ordinal", Node::Int(1));
            assert_eq!(get(&result, "regex"), &Node::Array(expected));
            let result = run(
                0,
                map(vec![
                    ("action", string(b"addtest")),
                    ("input", map(vec![("regex", string(b"pattern"))])),
                ]),
            );
            assert_eq!(get(&result, "regex"), &defaults);
            let result = run(1, map(Vec::new()));
            assert_eq!(
                get(&result, "status_ids"),
                &list(vec![Node::Int(1), Node::Int(0)])
            );
            assert_eq!(
                get(&result, "optype_ids"),
                &list(vec![Node::Int(1), Node::Int(2)])
            );
            assert_eq!(
                get(&result, "msgcol_ids"),
                &list(vec![Node::Int(1), Node::Int(2), Node::Int(3)])
            );
            assert_eq!(
                get(&result, "status_names"),
                &list(vec![string(b"Yes"), string(b"No")])
            );
            assert_eq!(
                get(&result, "optype_names"),
                &list(vec![string(b"Black"), string(b"White")])
            );
            assert_eq!(
                get(&result, "msgcol_names"),
                &list(vec![
                    string(b"Subject"),
                    string(b"Poster"),
                    string(b"MessageId")
                ])
            );
        }

        #[test]
        fn w28_admin_regex_group_controllers_form_validation_order_and_php_numeric() {
            for operation in [2, 4, 7] {
                for empty_value in [
                    Node::Null,
                    Node::Bool(false),
                    Node::Int(0),
                    Node::Float(b"-0.0".to_vec()),
                    string(b""),
                    string(b"0"),
                    list(Vec::new()),
                ] {
                    let result = run(
                        operation,
                        submit(map(vec![
                            ("group_regex", empty_value.clone()),
                            ("regex", string(b"")),
                        ])),
                    );
                    assert_eq!(
                        get(&result, "error"),
                        &string(b"Group regex must not be empty!")
                    );
                    let result = run(
                        operation,
                        submit(map(vec![
                            ("group_regex", string(b"g")),
                            ("regex", empty_value),
                        ])),
                    );
                    assert_eq!(get(&result, "error"), &string(b"Regex cannot be empty"));
                }
                for (ordinal, accepted) in [
                    (Node::Int(0), true),
                    (Node::Int(i64::MAX), true),
                    (Node::Int(-1), false),
                    (Node::Float(b"0.5".to_vec()), true),
                    (Node::Float(b"-0.0".to_vec()), true),
                    (string(b"\t\n\x0b\x0c\r +.5e2 \t"), true),
                    (string(b"-1e-999"), true),
                    (string(b"1e999"), true),
                    (string(b"-1e999"), false),
                    (string(b"-0.0001"), false),
                    (string(b"1e"), false),
                    (string(b"0x10"), false),
                    (string(b"\0 1"), false),
                    (Node::Bool(true), false),
                    (Node::Null, false),
                    (list(Vec::new()), false),
                ] {
                    let input = map(vec![
                        ("extra", string(b"\0\xff")),
                        ("group_regex", string(b"g")),
                        ("regex", string(b"r")),
                        ("description", string(b"0")),
                        ("ordinal", ordinal),
                    ]);
                    let result = run(operation, submit(input.clone()));
                    assert_eq!(
                        get(&result, "error"),
                        &string(if accepted {
                            b"".as_slice()
                        } else {
                            b"Ordinal must be a number, 0 or higher.".as_slice()
                        })
                    );
                    assert_eq!(get(&result, "id"), &Node::Null);
                    let mut expected = array(&input).unwrap().to_vec();
                    if operation != 2 {
                        set(&mut expected, "description", string(b""));
                    }
                    assert_eq!(get(&result, "input"), &Node::Array(expected));
                }
                let result = run(
                    operation,
                    submit(map(vec![
                        ("group_regex", string(b"g")),
                        ("regex", string(b"r")),
                        ("ordinal", Node::Bool(false)),
                    ])),
                );
                assert_eq!(
                    has(array(get(&result, "input")).unwrap(), "description"),
                    operation != 2
                );
            }
        }

        #[test]
        fn w28_admin_regex_group_controllers_filter_validate_int() {
            for operation in [2, 4, 7] {
                for (id, expected) in [
                    (Node::Int(1), Node::Int(1)),
                    (Node::Int(i64::MAX), Node::Int(i64::MAX)),
                    (Node::Bool(true), Node::Int(1)),
                    (Node::Float(b"12.0".to_vec()), Node::Int(12)),
                    (string(b" \t\n\x0b\r+123 \t"), Node::Int(123)),
                    (string(b"\x0c1\x0c"), Node::Bool(false)),
                    (string(b"9223372036854775807"), Node::Int(i64::MAX)),
                    (string(b"9223372036854775808"), Node::Bool(false)),
                    (string(b"01"), Node::Bool(false)),
                    (string(b"+01"), Node::Bool(false)),
                    (string(b"1.0"), Node::Bool(false)),
                    (string(b"1e2"), Node::Bool(false)),
                    (string(b"\0"), Node::Bool(false)),
                    (string(b"0"), Node::Bool(false)),
                    (string(b"-1"), Node::Bool(false)),
                    (Node::Bool(false), Node::Bool(false)),
                    (Node::Null, Node::Bool(false)),
                    (list(Vec::new()), Node::Bool(false)),
                ] {
                    let input = map(vec![("id", id)]);
                    let result = run(operation, map(vec![("input", input.clone())]));
                    assert_eq!(get(&result, "id"), &expected);
                    assert_eq!(get(&result, "input"), &input);
                }
                assert_eq!(get(&run(operation, map(Vec::new())), "id"), &Node::Null);
                for value in [b"1.5".as_slice(), b"100000000000000.0"] {
                    assert!(
                        output(
                            operation,
                            &wire(&map(vec![(
                                "input",
                                map(vec![("id", Node::Float(value.to_vec()))])
                            )]))
                        )
                        .is_err()
                    );
                }
            }
        }

        #[test]
        fn w28_admin_regex_group_controllers_labels_defaults_and_test_parameters() {
            let ids = vec![Node::Int(123), string(b"004\0\xff")];
            let result = run(
                3,
                map(vec![(
                    "categories",
                    list(vec![
                        map(vec![
                            ("id", ids[0].clone()),
                            ("parent_title", string(b"Parent")),
                            ("title", string(b"Title")),
                        ]),
                        map(vec![
                            ("id", ids[1].clone()),
                            ("parent_title", string(b"\xff\0")),
                            ("title", string(b"")),
                        ]),
                    ]),
                )]),
            );
            assert_eq!(get(&result, "category_ids"), &list(ids));
            assert_eq!(
                get(&result, "category_names"),
                &list(vec![
                    string(b"Parent Title: 123"),
                    string(b"\xff\0 : 004\0\xff")
                ])
            );
            assert_eq!(
                run(5, map(Vec::new())),
                map(vec![
                    ("id", string(b"")),
                    ("regex", string(b"")),
                    ("description", string(b"")),
                    ("group_regex", string(b"")),
                    ("ordinal", string(b"")),
                    ("status", Node::Int(1))
                ])
            );
            assert_eq!(run(9, map(Vec::new())), group_defaults());
            for operation in [6, 8] {
                let result = run(
                    operation,
                    map(vec![(
                        "input",
                        map(vec![
                            ("group", string(b"\0 \t\x0bgroup\r\n")),
                            ("regex", string(b" \x0cpattern\x0c ")),
                        ]),
                    )]),
                );
                assert_eq!(get(&result, "group"), &string(b"group"));
                assert_eq!(get(&result, "regex"), &string(b"\x0cpattern\x0c"));
                for value in [
                    Node::Null,
                    Node::Bool(false),
                    Node::Int(0),
                    string(b"0"),
                    list(Vec::new()),
                ] {
                    let result = run(
                        operation,
                        map(vec![(
                            "input",
                            map(vec![("group", value.clone()), ("regex", value)]),
                        )]),
                    );
                    assert_eq!(get(&result, "group"), &string(b""));
                    assert_eq!(get(&result, "regex"), &string(b""));
                }
            }
            for operation in [6, 8] {
                for value in [Node::Bool(true), Node::Int(1), Node::Float(b"1.5".to_vec())] {
                    assert!(
                        output(
                            operation,
                            &wire(&map(vec![("input", map(vec![("group", value)]))]))
                        )
                        .is_err()
                    );
                }
            }
            for value in [
                Node::Int(-1),
                Node::Float(b"1.5".to_vec()),
                string(b" 1e999\t"),
                string(b"0"),
            ] {
                let result = run(6, map(vec![("input", map(vec![("limit", value.clone())]))]));
                assert_eq!(get(&result, "limit"), &value);
                let result = run(
                    8,
                    map(vec![(
                        "input",
                        map(vec![
                            ("showlimit", value.clone()),
                            ("querylimit", value.clone()),
                        ]),
                    )]),
                );
                assert_eq!(get(&result, "showlimit"), &value);
                assert_eq!(get(&result, "querylimit"), &value);
            }
            assert_eq!(get(&run(6, map(Vec::new())), "limit"), &Node::Int(50));
            assert_eq!(get(&run(8, map(Vec::new())), "showlimit"), &Node::Int(250));
            assert_eq!(
                get(&run(8, map(Vec::new())), "querylimit"),
                &Node::Int(100000)
            );
            for value in [
                Node::Bool(true),
                Node::Null,
                list(Vec::new()),
                string(b"NaN"),
            ] {
                assert_eq!(
                    get(
                        &run(6, map(vec![("input", map(vec![("limit", value)]))])),
                        "limit"
                    ),
                    &Node::Int(50)
                );
            }
        }

        #[test]
        fn w28_admin_regex_group_controllers_group_query_union_and_titles() {
            let filters = Node::Array(vec![
                (Key::Int(7), string(b"numeric")),
                (Key::String(b"groupname".to_vec()), string(b"ignored")),
                (Key::String(b"active".to_vec()), Node::Int(4)),
                (Key::String(b"tail".to_vec()), Node::Null),
            ]);
            for active in [Node::Null, Node::Bool(true), Node::Bool(false)] {
                let result = run(
                    10,
                    map(vec![
                        ("groupname", string(b"group\0\xff")),
                        ("filters", filters.clone()),
                        ("active", active.clone()),
                    ]),
                );
                let mut expected = array(&filters).unwrap().to_vec();
                if let Node::Bool(active) = active {
                    set(
                        &mut expected,
                        "active",
                        string(if active { b"1" } else { b"0" }),
                    );
                }
                assert_eq!(get(&result, "filters"), &Node::Array(expected.clone()));
                let mut query = vec![(Key::String(b"groupname".to_vec()), string(b"group\0\xff"))];
                query.extend(
                    expected
                        .into_iter()
                        .filter(|(key, _)| key != &Key::String(b"groupname".to_vec())),
                );
                assert_eq!(get(&result, "listQuery"), &Node::Array(query));
            }
            let result = run(
                10,
                map(vec![
                    ("filters", map(vec![("x", Node::Int(1))])),
                    ("active", Node::Bool(false)),
                ]),
            );
            assert_eq!(
                get(&result, "filters"),
                &map(vec![("x", Node::Int(1)), ("active", string(b"0"))])
            );
            for (action, has_id, title) in [
                (b"submit".as_slice(), false, b"Group Edit".as_slice()),
                (b"submit", true, b"Group Edit"),
                (b"view", true, b"Newsgroup Edit"),
                (b"", false, b"Newsgroup Add"),
            ] {
                assert_eq!(
                    run(
                        11,
                        map(vec![
                            ("action", string(action)),
                            ("has_id", Node::Bool(has_id))
                        ])
                    ),
                    map(vec![("title", string(title))])
                );
            }
        }

        #[test]
        fn w28_admin_regex_group_controllers_malformed_types_framing_and_depth() {
            let mut invalid = vec![Vec::new(), vec![0], vec![0; 4], vec![4, 0, 0, 0, b'a']];
            for raw in [
                b"".as_slice(),
                b"n0",
                b"b2",
                b"b",
                b"i01",
                b"i+1",
                b"i-0",
                b"i9223372036854775808",
                b"f",
                b"fNaN",
                b"fINF",
                b"f1e999",
                b"f 1",
                b"f\x0b1",
                b"a01",
                b"a-1",
                b"a18446744073709551616",
                b"a2",
                b"x",
            ] {
                invalid.push(frames(&[Some(raw)]));
            }
            invalid.extend([
                frames(&[None]),
                frames(&[Some(b"a0"), None]),
                frames(&[Some(b"a0"), Some(b"n")]),
                frames(&[Some(b"n")]),
                frames(&[Some(b"sroot")]),
                frames(&[Some(b"a1"), Some(b"n"), Some(b"n")]),
                frames(&[Some(b"a1"), Some(b"b1"), Some(b"n")]),
                frames(&[Some(b"a1"), Some(b"fx"), Some(b"n")]),
                frames(&[
                    Some(b"a2"),
                    Some(b"sx"),
                    Some(b"n"),
                    Some(b"sx"),
                    Some(b"n"),
                ]),
                frames(&[
                    Some(b"a2"),
                    Some(b"i1"),
                    Some(b"n"),
                    Some(b"s1"),
                    Some(b"n"),
                ]),
            ]);
            let mut deep = map(Vec::new());
            for _ in 0..65 {
                deep = map(vec![("deep", deep)]);
            }
            invalid.push(wire(&deep));
            for input in invalid {
                assert!(output(1, &input).is_err(), "accepted malformed {input:?}");
                let mut target = [0xab; 32];
                assert_eq!(
                    unsafe {
                        nntmux_w28_admin_regex_group_controllers(
                            1,
                            input.as_ptr(),
                            input.len(),
                            target.as_mut_ptr(),
                            target.len(),
                        )
                    },
                    -1
                );
                assert_eq!(target, [0xab; 32]);
            }
            let valid = wire(&map(Vec::new()));
            for operation in [12, 100, u32::MAX] {
                assert!(output(operation, &valid).is_err());
            }
            for (operation, value) in [
                (0, map(vec![("action", Node::Int(1))])),
                (2, map(vec![("input", string(b"bad"))])),
                (
                    3,
                    map(vec![(
                        "categories",
                        list(vec![map(vec![("id", Node::Null)])]),
                    )]),
                ),
                (
                    6,
                    map(vec![(
                        "input",
                        map(vec![("group", list(vec![Node::Int(1)]))]),
                    )]),
                ),
                (
                    8,
                    map(vec![(
                        "input",
                        map(vec![("regex", Node::Float(b"1.5".to_vec()))]),
                    )]),
                ),
                (10, map(vec![("groupname", Node::Bool(true))])),
                (10, map(vec![("filters", Node::Int(0))])),
                (10, map(vec![("active", string(b"1"))])),
                (11, map(vec![("has_id", Node::Int(1))])),
            ] {
                assert!(output(operation, &wire(&value)).is_err());
            }
            let mut accepted_depth = map(Vec::new());
            for _ in 0..64 {
                accepted_depth = map(vec![("deep", accepted_depth)]);
            }
            assert!(output(1, &wire(&accepted_depth)).is_ok());
        }

        #[test]
        fn w28_admin_regex_group_controllers_regions_capacities_unaligned_and_aliasing() {
            for operation in 0..=11 {
                let input = wire(&map(Vec::new()));
                let expected = output(operation, &input).unwrap();
                let mut target = vec![0xab; expected.len() + 8];
                unsafe {
                    for capacity in [0, 1, expected.len() - 1] {
                        assert_eq!(
                            nntmux_w28_admin_regex_group_controllers(
                                operation,
                                input.as_ptr(),
                                input.len(),
                                target.as_mut_ptr(),
                                capacity
                            ),
                            -(expected.len() as i32)
                        );
                        assert!(target.iter().all(|byte| *byte == 0xab));
                    }
                    for (source, length, out, capacity) in [
                        (std::ptr::null(), 0, target.as_mut_ptr(), target.len()),
                        (
                            std::ptr::null(),
                            input.len(),
                            target.as_mut_ptr(),
                            target.len(),
                        ),
                        (input.as_ptr(), input.len(), std::ptr::null_mut(), 0),
                        (input.as_ptr(), input.len(), std::ptr::null_mut(), 10),
                        (
                            usize::MAX as *const u8,
                            1,
                            target.as_mut_ptr(),
                            target.len(),
                        ),
                        (input.as_ptr(), input.len(), usize::MAX as *mut u8, 1),
                        (
                            input.as_ptr(),
                            usize::MAX,
                            target.as_mut_ptr(),
                            target.len(),
                        ),
                        (input.as_ptr(), input.len(), target.as_mut_ptr(), usize::MAX),
                        (
                            input.as_ptr(),
                            isize::MAX as usize + 1,
                            target.as_mut_ptr(),
                            target.len(),
                        ),
                        (
                            input.as_ptr(),
                            input.len(),
                            target.as_mut_ptr(),
                            isize::MAX as usize + 1,
                        ),
                    ] {
                        assert_eq!(
                            nntmux_w28_admin_regex_group_controllers(
                                operation, source, length, out, capacity
                            ),
                            -1
                        );
                        assert!(target.iter().all(|byte| *byte == 0xab));
                    }
                    let unaligned = [vec![0xab], input.clone()].concat();
                    assert_eq!(
                        nntmux_w28_admin_regex_group_controllers(
                            operation,
                            unaligned.as_ptr().add(1),
                            input.len(),
                            target.as_mut_ptr().add(1),
                            expected.len()
                        ),
                        expected.len() as i32
                    );
                    assert_eq!(&target[1..1 + expected.len()], expected);
                    assert_eq!(target[0], 0xab);
                    assert!(
                        target[1 + expected.len()..]
                            .iter()
                            .all(|byte| *byte == 0xab)
                    );
                    for (source_offset, target_offset) in [(0, 0), (0, 1), (3, 0)] {
                        for capacity in
                            [0, 1, expected.len() - 1, expected.len(), expected.len() + 1]
                        {
                            let mut buffer = vec![0xab; expected.len() + input.len() + 16];
                            buffer[source_offset..source_offset + input.len()]
                                .copy_from_slice(&input);
                            let before = buffer.clone();
                            let actual = nntmux_w28_admin_regex_group_controllers(
                                operation,
                                buffer.as_ptr().add(source_offset),
                                input.len(),
                                buffer.as_mut_ptr().add(target_offset),
                                capacity,
                            );
                            if capacity < expected.len() {
                                assert_eq!(actual, -(expected.len() as i32));
                                assert_eq!(buffer, before);
                            } else {
                                assert_eq!(actual, expected.len() as i32);
                                assert_eq!(
                                    &buffer[target_offset..target_offset + expected.len()],
                                    expected
                                );
                                assert_eq!(&buffer[..target_offset], &before[..target_offset]);
                                assert_eq!(
                                    &buffer[target_offset + expected.len()..],
                                    &before[target_offset + expected.len()..]
                                );
                            }
                        }
                    }
                }
            }
        }
    }
}

/// Render typed admin regex/group decision frames without partially writing output.
/// Returns bytes written, -1 on invalid input/panic, or negated required size.
/// Input and output may overlap; every output is built before touching the caller buffer.
///
/// # Safety
/// Both regions must be non-null and allocated for their stated extents, with
/// lengths fitting isize and no address overflow. Only byte alignment is needed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w28_admin_regex_group_controllers(
    operation: u32,
    input: *const u8,
    len: usize,
    out: *mut u8,
    out_cap: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(input, len) || !cleaning_ffi_region_valid(out, out_cap) {
            return -1;
        }
        let output = {
            let input = unsafe { slice::from_raw_parts(input, len) };
            match wave28_admin_regex_group_controllers::output(operation, input) {
                Ok(output) => output,
                Err(_) => return -1,
            }
        };
        let Ok(needed) = i32::try_from(output.len()) else {
            return -1;
        };
        if output.len() > out_cap {
            return -needed;
        }
        unsafe { std::ptr::copy_nonoverlapping(output.as_ptr(), out, output.len()) };
        needed
    }))
    .unwrap_or(-1)
}

pub mod wave29_admin_gdpr_reg_promo_invite_history {
    use super::{remover_is_numeric, wave22_append_frame, wave22_canonical_i64, wave22_frames};
    use std::collections::HashSet;

    #[derive(Clone, Debug, PartialEq, Eq, Hash)]
    enum Key {
        Int(i64),
        String(Vec<u8>),
    }

    #[derive(Clone, Debug, PartialEq, Eq)]
    enum Node {
        Null,
        Bool(bool),
        Int(i64),
        Float(Vec<u8>),
        String(Vec<u8>),
        Array(Vec<(Key, Node)>),
    }

    fn string(value: impl AsRef<[u8]>) -> Node {
        Node::String(value.as_ref().to_vec())
    }

    fn map(values: Vec<(&str, Node)>) -> Node {
        Node::Array(
            values
                .into_iter()
                .map(|(key, value)| (Key::String(key.as_bytes().to_vec()), value))
                .collect(),
        )
    }

    fn list(values: Vec<Node>) -> Node {
        Node::Array(
            values
                .into_iter()
                .enumerate()
                .map(|(key, value)| (Key::Int(key as i64), value))
                .collect(),
        )
    }

    fn array(node: &Node) -> Result<&[(Key, Node)], isize> {
        match node {
            Node::Array(values) => Ok(values),
            _ => Err(-1),
        }
    }

    fn field<'a>(values: &'a [(Key, Node)], name: &str) -> &'a Node {
        values
            .iter()
            .find_map(|(key, value)| match key {
                Key::String(key) if key == name.as_bytes() => Some(value),
                _ => None,
            })
            .unwrap_or(&Node::Null)
    }

    fn has(values: &[(Key, Node)], name: &str) -> bool {
        values
            .iter()
            .any(|(key, _)| matches!(key, Key::String(key) if key == name.as_bytes()))
    }

    fn set(values: &mut Vec<(Key, Node)>, name: &str, value: Node) {
        if let Some((_, current)) = values
            .iter_mut()
            .find(|(key, _)| matches!(key, Key::String(key) if key == name.as_bytes()))
        {
            *current = value;
        } else {
            values.push((Key::String(name.as_bytes().to_vec()), value));
        }
    }

    fn bytes(node: &Node) -> Result<&[u8], isize> {
        match node {
            Node::String(value) => Ok(value),
            _ => Err(-1),
        }
    }

    fn canonical_count(value: &[u8]) -> Result<usize, isize> {
        let text = std::str::from_utf8(value).map_err(|_| -1isize)?;
        let count = text.parse::<usize>().map_err(|_| -1isize)?;
        if count.to_string().as_bytes() != value {
            return Err(-1);
        }
        Ok(count)
    }

    fn key_identity(key: &Key) -> Key {
        match key {
            Key::String(value) => wave22_canonical_i64(value)
                .map(Key::Int)
                .unwrap_or_else(|_| key.clone()),
            _ => key.clone(),
        }
    }

    fn parse_node(
        frames: &[Option<&[u8]>],
        cursor: &mut usize,
        depth: usize,
    ) -> Result<Node, isize> {
        if depth > 64 {
            return Err(-1);
        }
        let frame = frames
            .get(*cursor)
            .and_then(|value| *value)
            .ok_or(-1isize)?;
        *cursor += 1;
        let (&kind, value) = frame.split_first().ok_or(-1isize)?;
        match kind {
            b'n' if value.is_empty() => Ok(Node::Null),
            b'b' if value == b"0" => Ok(Node::Bool(false)),
            b'b' if value == b"1" => Ok(Node::Bool(true)),
            b'i' => Ok(Node::Int(wave22_canonical_i64(value)?)),
            b'f' if !value.is_empty()
                && remover_is_numeric(value)
                && !value
                    .iter()
                    .any(|byte| byte.is_ascii_whitespace() || *byte == 0x0b) =>
            {
                let text = std::str::from_utf8(value).map_err(|_| -1isize)?;
                // Non-finite values use the PHP fallback, including overflowing decimals.
                if !text.parse::<f64>().map_err(|_| -1isize)?.is_finite() {
                    return Err(-1);
                }
                Ok(Node::Float(value.to_vec()))
            }
            b's' => Ok(Node::String(value.to_vec())),
            b'a' => {
                let count = canonical_count(value)?;
                if count > frames.len().saturating_sub(*cursor) / 2 {
                    return Err(-1);
                }
                let mut values = Vec::with_capacity(count);
                let mut keys = HashSet::with_capacity(count);
                for _ in 0..count {
                    let key = match parse_node(frames, cursor, depth + 1)? {
                        Node::Int(value) => Key::Int(value),
                        Node::String(value) => Key::String(value),
                        _ => return Err(-1),
                    };
                    if !keys.insert(key_identity(&key)) {
                        return Err(-1);
                    }
                    let value = parse_node(frames, cursor, depth + 1)?;
                    values.push((key, value));
                }
                Ok(Node::Array(values))
            }
            _ => Err(-1),
        }
    }

    fn decode(input: &[u8]) -> Result<Node, isize> {
        let frames = wave22_frames(input)?;
        if frames.iter().any(Option::is_none) {
            return Err(-1);
        }
        let mut cursor = 0;
        let root = parse_node(&frames, &mut cursor, 0)?;
        array(&root)?;
        if cursor != frames.len() {
            return Err(-1);
        }
        Ok(root)
    }

    fn encode_node(node: &Node, output: &mut Vec<u8>) -> Result<(), isize> {
        let frame = match node {
            Node::Null => b"n".to_vec(),
            Node::Bool(false) => b"b0".to_vec(),
            Node::Bool(true) => b"b1".to_vec(),
            Node::Int(value) => [b"i".as_slice(), value.to_string().as_bytes()].concat(),
            Node::Float(value) => [b"f".as_slice(), value].concat(),
            Node::String(value) => [b"s".as_slice(), value].concat(),
            Node::Array(values) => [b"a".as_slice(), values.len().to_string().as_bytes()].concat(),
        };
        wave22_append_frame(output, Some(&frame))?;
        if let Node::Array(values) = node {
            for (key, value) in values {
                match key {
                    Key::Int(value) => encode_node(&Node::Int(*value), output)?,
                    Key::String(value) => encode_node(&Node::String(value.clone()), output)?,
                }
                encode_node(value, output)?;
            }
        }
        Ok(())
    }

    fn empty(node: &Node) -> bool {
        match node {
            Node::Null | Node::Bool(false) | Node::Int(0) => true,
            Node::Float(value) => std::str::from_utf8(value)
                .ok()
                .and_then(|text| text.parse::<f64>().ok())
                .is_some_and(|value| value == 0.0),
            Node::String(value) => value.is_empty() || value == b"0",
            Node::Array(value) => value.is_empty(),
            _ => false,
        }
    }

    fn boolean(node: &Node) -> Result<bool, isize> {
        match node {
            Node::Bool(value) => Ok(*value),
            _ => Err(-1),
        }
    }

    fn integer(node: &Node) -> Result<i64, isize> {
        match node {
            Node::Int(value) => Ok(*value),
            _ => Err(-1),
        }
    }

    fn optional_integer(node: &Node) -> Result<Option<i64>, isize> {
        match node {
            Node::Null => Ok(None),
            _ => integer(node).map(Some),
        }
    }

    fn sequence(node: &Node) -> Result<Vec<&Node>, isize> {
        let values = array(node)?;
        values
            .iter()
            .enumerate()
            .map(|(position, (key, value))| {
                if key == &Key::Int(position as i64) {
                    Ok(value)
                } else {
                    Err(-1)
                }
            })
            .collect()
    }

    fn trim(value: &[u8]) -> &[u8] {
        let is_trim = |byte: &u8| matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b);
        let start = value
            .iter()
            .position(|byte| !is_trim(byte))
            .unwrap_or(value.len());
        let end = value
            .iter()
            .rposition(|byte| !is_trim(byte))
            .map_or(start, |index| index + 1);
        &value[start..end]
    }

    fn scalar(values: &[(Key, Node)], name: &str, default: &[u8]) -> Result<Node, isize> {
        Ok(match field(values, name) {
            Node::String(value) => string(value),
            Node::Int(value) => string(value.to_string()),
            Node::Bool(value) => string(if *value { b"1".as_slice() } else { b"" }),
            Node::Null | Node::Array(_) => string(default),
            // PHP casts floats using its configurable precision; retain its fallback.
            Node::Float(_) => return Err(-1),
        })
    }

    fn status_label(node: &Node) -> Result<Node, isize> {
        Ok(match optional_integer(node)? {
            Some(0) => string(b"Open"),
            Some(1) => string(b"Invite"),
            Some(2) => string(b"Closed"),
            Some(_) => string(b"Unknown"),
            None => Node::Null,
        })
    }

    pub(super) fn output(operation: u32, input: &[u8]) -> Result<Vec<u8>, isize> {
        let root = decode(input)?;
        let values = array(&root)?;
        let result = match operation {
            0 => {
                let kind = trim(bytes(field(values, "type"))?);
                let status = trim(bytes(field(values, "status"))?);
                map(vec![
                    (
                        "type",
                        string(
                            if [
                                b"export".as_slice(),
                                b"erasure",
                                b"rectification",
                                b"restriction",
                            ]
                            .contains(&kind)
                            {
                                kind
                            } else {
                                b""
                            },
                        ),
                    ),
                    (
                        "status",
                        string(
                            if [
                                b"pending".as_slice(),
                                b"processing",
                                b"completed",
                                b"rejected",
                                b"cancelled",
                            ]
                            .contains(&status)
                            {
                                status
                            } else {
                                b""
                            },
                        ),
                    ),
                ])
            }
            1 => map(vec![(
                "open",
                Node::Bool(matches!(
                    bytes(field(values, "status"))?,
                    b"pending" | b"processing"
                )),
            )]),
            2 => {
                let kind = bytes(field(values, "type"))?;
                let status = bytes(field(values, "status"))?;
                let action = bytes(field(values, "action"))?;
                let subject_exists = boolean(field(values, "subject_exists"))?;
                let is_admin = boolean(field(values, "is_admin"))?;
                let invalid = match action {
                    b"export" => b"This request cannot be exported.".as_slice(),
                    b"erasure" => b"This erasure request cannot be completed.".as_slice(),
                    _ => return Err(-1),
                };
                let error = if kind != action || !matches!(status, b"pending" | b"processing") {
                    string(invalid)
                } else if !subject_exists {
                    string(b"The subject user could not be found.")
                } else if action == b"erasure" && is_admin {
                    string(b"Admin accounts cannot be erased from the GDPR request queue.")
                } else {
                    Node::Null
                };
                map(vec![("error", error)])
            }
            3 => {
                if !has(values, "reason") {
                    return Err(-1);
                }
                map(vec![
                    ("rejected", Node::Bool(true)),
                    ("reason", field(values, "reason").clone()),
                ])
            }
            4 => {
                let mut current = Vec::new();
                let mut past = Vec::new();
                for (index, row) in sequence(field(values, "periods"))?.into_iter().enumerate() {
                    let row = array(row)?;
                    if !has(row, "starts_at") || !has(row, "ends_at") {
                        return Err(-1);
                    }
                    let starts = optional_integer(field(row, "starts_at"))?.unwrap_or(i64::MAX);
                    let ends = optional_integer(field(row, "ends_at"))?.unwrap_or(0);
                    let index = i64::try_from(index).map_err(|_| -1isize)?;
                    if boolean(field(row, "ended"))? {
                        past.push((ends, index));
                    } else {
                        current.push((starts, index));
                    }
                }
                current.sort_by_key(|(date, _)| *date);
                past.sort_by(|left, right| right.0.cmp(&left.0));
                map(vec![
                    (
                        "current",
                        list(
                            current
                                .into_iter()
                                .map(|(_, index)| Node::Int(index))
                                .collect(),
                        ),
                    ),
                    (
                        "past",
                        list(
                            past.into_iter()
                                .map(|(_, index)| Node::Int(index))
                                .collect(),
                        ),
                    ),
                ])
            }
            5 => {
                if !has(values, "metadata") {
                    return Err(-1);
                }
                let valid = if let Node::Array(metadata) = field(values, "metadata") {
                    !matches!(field(metadata, "email"), Node::Null)
                        && !matches!(field(metadata, "ip_address"), Node::Null)
                } else {
                    false
                };
                map(vec![("valid", Node::Bool(valid))])
            }
            6 => {
                let mut entry = array(field(values, "entry"))?.to_vec();
                let statuses = sequence(field(values, "statuses"))?;
                if statuses.len() != 2 {
                    return Err(-1);
                }
                set(
                    &mut entry,
                    "registration_status_label",
                    status_label(statuses[0])?,
                );
                set(
                    &mut entry,
                    "manual_registration_status_label",
                    status_label(statuses[1])?,
                );
                Node::Array(entry)
            }
            7 => map(vec![(
                "message",
                string(
                    if integer(field(values, "old_status"))?
                        == integer(field(values, "new_status"))?
                    {
                        b"Registration status note saved without changing the manual mode."
                            .as_slice()
                    } else {
                        b"Manual registration status updated successfully."
                    },
                ),
            )]),
            8 => {
                let input = array(field(values, "input"))?;
                let mut fields: Vec<(&str, Node)> = [
                    "name",
                    "description",
                    "applicable_roles",
                    "additional_days",
                    "start_date",
                    "end_date",
                ]
                .into_iter()
                .map(|name| {
                    (
                        name,
                        if name == "applicable_roles" && !has(input, name) {
                            list(Vec::new())
                        } else {
                            field(input, name).clone()
                        },
                    )
                })
                .collect();
                fields.push((
                    "is_active",
                    Node::Bool(boolean(field(values, "is_active"))?),
                ));
                map(fields)
            }
            9 => map(vec![(
                "label",
                string(if boolean(field(values, "active"))? {
                    b"activated".as_slice()
                } else {
                    b"deactivated"
                }),
            )]),
            10 => {
                let promotions = sequence(field(values, "promotions"))?;
                let total = i64::try_from(promotions.len()).map_err(|_| -1isize)?;
                let mut active = 0i64;
                let mut applications = 0i64;
                for row in promotions {
                    let row = array(row)?;
                    active = active
                        .checked_add(i64::from(boolean(field(row, "is_active"))?))
                        .ok_or(-1isize)?;
                    applications = applications
                        .checked_add(integer(field(row, "statistics_count"))?)
                        .ok_or(-1isize)?;
                }
                map(vec![
                    ("total_promotions", Node::Int(total)),
                    ("active_promotions", Node::Int(active)),
                    ("total_applications", Node::Int(applications)),
                ])
            }
            11 => {
                let input = array(field(values, "input"))?;
                let order = scalar(input, "ob", b"created_at_desc")?;
                let (column, direction) = match bytes(&order)? {
                    b"created_at_asc" => (b"created_at".as_slice(), b"asc".as_slice()),
                    b"expires_at_asc" => (b"expires_at".as_slice(), b"asc".as_slice()),
                    b"expires_at_desc" => (b"expires_at".as_slice(), b"desc".as_slice()),
                    b"email_asc" => (b"email".as_slice(), b"asc".as_slice()),
                    b"email_desc" => (b"email".as_slice(), b"desc".as_slice()),
                    _ => (b"created_at".as_slice(), b"desc".as_slice()),
                };
                // Preserve the requested scalar order key; only SQL ordering defaults.
                map(vec![
                    ("status", scalar(input, "status", b"")?),
                    ("invited_by", scalar(input, "invited_by", b"")?),
                    ("email", scalar(input, "email", b"")?),
                    ("orderBy", order),
                    ("column", string(column)),
                    ("direction", string(direction)),
                ])
            }
            12 => map(vec![
                ("", string(b"All")),
                ("pending", string(b"Pending")),
                ("used", string(b"Used")),
                ("expired", string(b"Expired")),
                ("cancelled", string(b"Cancelled")),
            ]),
            13 => {
                if !has(values, "ids") || !has(values, "action") {
                    return Err(-1);
                }
                let count = integer(field(values, "count"))?;
                let (error, message) = if empty(field(values, "ids")) {
                    (string(b"No invitations selected"), Node::Null)
                } else {
                    let verb = match bytes(field(values, "action"))? {
                        b"cancel" => Some("Cancelled"),
                        b"resend" => Some("Resent"),
                        b"delete" => Some("Deleted"),
                        _ => None,
                    };
                    match verb {
                        Some(verb) => (Node::Null, string(format!("{verb} {count} invitations"))),
                        None => (string(b"Invalid bulk action"), Node::Null),
                    }
                };
                map(vec![("error", error), ("message", message)])
            }
            14 => {
                let input = array(field(values, "input"))?;
                map([
                    "user_id",
                    "username",
                    "role_id",
                    "change_reason",
                    "date_from",
                    "date_to",
                ]
                .into_iter()
                .map(|name| Ok((name, scalar(input, name, b"")?)))
                .collect::<Result<Vec<_>, isize>>()?)
            }
            15 => {
                let from = bytes(field(values, "date_from"))?;
                let to = bytes(field(values, "date_to"))?;
                map(vec![
                    (
                        "date_from",
                        if from.is_empty() {
                            Node::Null
                        } else {
                            string([from, b" 00:00:00"].concat())
                        },
                    ),
                    (
                        "date_to",
                        if to.is_empty() {
                            Node::Null
                        } else {
                            string([to, b" 23:59:59"].concat())
                        },
                    ),
                ])
            }
            _ => return Err(-1),
        };
        let mut output = Vec::new();
        encode_node(&result, &mut output)?;
        Ok(output)
    }
    #[cfg(test)]
    mod tests {
        use super::*;
        use crate::cleaning::nntmux_w29_admin_gdpr_reg_promo_invite_history as ffi;

        fn wire(node: &Node) -> Vec<u8> {
            let mut result = Vec::new();
            encode_node(node, &mut result).unwrap();
            result
        }
        fn run(operation: u32, node: Node) -> Node {
            decode(&output(operation, &wire(&node)).unwrap()).unwrap()
        }
        fn get<'a>(node: &'a Node, name: &str) -> &'a Node {
            field(array(node).unwrap(), name)
        }
        fn fixture(operation: u32) -> Node {
            match operation {
                0 => map(vec![
                    ("type", string(b"export")),
                    ("status", string(b"pending")),
                ]),
                1 => map(vec![("status", string(b"pending"))]),
                2 => map(vec![
                    ("type", string(b"export")),
                    ("status", string(b"pending")),
                    ("action", string(b"export")),
                    ("subject_exists", Node::Bool(true)),
                    ("is_admin", Node::Bool(false)),
                ]),
                3 => map(vec![("reason", Node::Null)]),
                4 => map(vec![("periods", list(Vec::new()))]),
                5 => map(vec![("metadata", Node::Null)]),
                6 => map(vec![
                    ("entry", map(Vec::new())),
                    ("statuses", list(vec![Node::Int(0), Node::Null])),
                ]),
                7 => map(vec![
                    ("old_status", Node::Int(0)),
                    ("new_status", Node::Int(1)),
                ]),
                8 => map(vec![
                    ("input", map(Vec::new())),
                    ("is_active", Node::Bool(true)),
                ]),
                9 => map(vec![("active", Node::Bool(false))]),
                10 => map(vec![("promotions", list(Vec::new()))]),
                11 | 14 => map(vec![("input", map(Vec::new()))]),
                12 => map(Vec::new()),
                13 => map(vec![
                    ("action", string(b"cancel")),
                    ("ids", list(vec![Node::Int(1)])),
                    ("count", Node::Int(1)),
                ]),
                15 => map(vec![
                    ("date_from", string(b"")),
                    ("date_to", string(b"2026-10-24")),
                ]),
                _ => unreachable!(),
            }
        }

        #[test]
        fn w29_admin_gdpr_reg_promo_invite_history_gdpr_decisions() {
            assert_eq!(
                run(
                    0,
                    map(vec![
                        ("type", string(b" \t\0export\r\n")),
                        ("status", string(b" processing\x0b"))
                    ])
                ),
                map(vec![
                    ("type", string(b"export")),
                    ("status", string(b"processing"))
                ])
            );
            assert_eq!(
                run(
                    0,
                    map(vec![
                        ("type", string(b"\x0cexport")),
                        ("status", string(b"Pending"))
                    ])
                ),
                map(vec![("type", string(b"")), ("status", string(b""))])
            );
            for kind in [
                b"export".as_slice(),
                b"erasure",
                b"rectification",
                b"restriction",
            ] {
                assert_eq!(
                    get(
                        &run(
                            0,
                            map(vec![
                                ("type", string(kind)),
                                ("status", string(b"cancelled"))
                            ])
                        ),
                        "type"
                    ),
                    &string(kind)
                );
            }
            for status in [
                b"pending".as_slice(),
                b"processing",
                b"completed",
                b"rejected",
                b"cancelled",
                b"",
            ] {
                assert_eq!(
                    get(&run(1, map(vec![("status", string(status))])), "open"),
                    &Node::Bool(matches!(status, b"pending" | b"processing"))
                );
            }
            for action in [b"export".as_slice(), b"erasure"] {
                for (kind, status, subject, admin, expected) in [
                    (
                        b"other".as_slice(),
                        b"pending".as_slice(),
                        false,
                        true,
                        Some(if action == b"export" {
                            b"This request cannot be exported.".as_slice()
                        } else {
                            b"This erasure request cannot be completed."
                        }),
                    ),
                    (
                        action,
                        b"completed",
                        false,
                        true,
                        Some(if action == b"export" {
                            b"This request cannot be exported.".as_slice()
                        } else {
                            b"This erasure request cannot be completed."
                        }),
                    ),
                    (
                        action,
                        b"processing",
                        false,
                        true,
                        Some(b"The subject user could not be found.".as_slice()),
                    ),
                    (
                        action,
                        b"pending",
                        true,
                        true,
                        if action == b"erasure" {
                            Some(
                                b"Admin accounts cannot be erased from the GDPR request queue."
                                    .as_slice(),
                            )
                        } else {
                            None
                        },
                    ),
                    (action, b"pending", true, false, None),
                ] {
                    let result = run(
                        2,
                        map(vec![
                            ("type", string(kind)),
                            ("status", string(status)),
                            ("action", string(action)),
                            ("subject_exists", Node::Bool(subject)),
                            ("is_admin", Node::Bool(admin)),
                        ]),
                    );
                    assert_eq!(
                        get(&result, "error"),
                        &expected.map(string).unwrap_or(Node::Null)
                    );
                }
            }
            let reason = Node::Array(vec![(
                Key::Int(-2),
                list(vec![Node::Float(b"1.25".to_vec()), string(b"\0\xff")]),
            )]);
            assert_eq!(
                run(3, map(vec![("reason", reason.clone())])),
                map(vec![("rejected", Node::Bool(true)), ("reason", reason)])
            );
        }

        #[test]
        fn w29_admin_gdpr_reg_promo_invite_history_registration_and_promotions() {
            let period = |ended, starts, ends| {
                map(vec![
                    ("ended", Node::Bool(ended)),
                    ("starts_at", starts),
                    ("ends_at", ends),
                ])
            };
            let result = run(
                4,
                map(vec![(
                    "periods",
                    list(vec![
                        period(false, Node::Null, Node::Null),
                        period(false, Node::Int(3), Node::Null),
                        period(false, Node::Int(3), Node::Null),
                        period(false, Node::Int(-1), Node::Null),
                        period(true, Node::Null, Node::Null),
                        period(true, Node::Null, Node::Int(-1)),
                        period(true, Node::Null, Node::Int(8)),
                        period(true, Node::Null, Node::Int(8)),
                    ]),
                )]),
            );
            assert_eq!(
                get(&result, "current"),
                &list(vec![Node::Int(3), Node::Int(1), Node::Int(2), Node::Int(0)])
            );
            assert_eq!(
                get(&result, "past"),
                &list(vec![Node::Int(6), Node::Int(7), Node::Int(4), Node::Int(5)])
            );
            for (metadata, valid) in [
                (Node::Null, false),
                (string(b"x"), false),
                (
                    map(vec![("email", Node::Null), ("ip_address", string(b""))]),
                    false,
                ),
                (
                    map(vec![
                        ("email", Node::Bool(false)),
                        ("ip_address", list(Vec::new())),
                    ]),
                    true,
                ),
            ] {
                assert_eq!(
                    run(5, map(vec![("metadata", metadata)])),
                    map(vec![("valid", Node::Bool(valid))])
                );
            }
            for (status, label) in [
                (Node::Int(0), string(b"Open")),
                (Node::Int(1), string(b"Invite")),
                (Node::Int(2), string(b"Closed")),
                (Node::Int(-1), string(b"Unknown")),
                (Node::Null, Node::Null),
            ] {
                let entry = Node::Array(vec![
                    (Key::Int(5), string(b"\0\xff")),
                    (
                        Key::String(b"registration_status_label".to_vec()),
                        Node::Bool(false),
                    ),
                ]);
                let result = run(
                    6,
                    map(vec![
                        ("entry", entry.clone()),
                        ("statuses", list(vec![status.clone(), status])),
                    ]),
                );
                let mut expected = array(&entry).unwrap().to_vec();
                set(&mut expected, "registration_status_label", label.clone());
                set(&mut expected, "manual_registration_status_label", label);
                assert_eq!(result, Node::Array(expected));
            }
            assert_eq!(
                get(
                    &run(
                        7,
                        map(vec![
                            ("old_status", Node::Int(1)),
                            ("new_status", Node::Int(1))
                        ])
                    ),
                    "message"
                ),
                &string(b"Registration status note saved without changing the manual mode.")
            );
            assert_eq!(
                get(&run(7, fixture(7)), "message"),
                &string(b"Manual registration status updated successfully.")
            );
            assert_eq!(
                run(8, fixture(8)),
                map(vec![
                    ("name", Node::Null),
                    ("description", Node::Null),
                    ("applicable_roles", list(Vec::new())),
                    ("additional_days", Node::Null),
                    ("start_date", Node::Null),
                    ("end_date", Node::Null),
                    ("is_active", Node::Bool(true))
                ])
            );
            let result = run(
                8,
                map(vec![
                    (
                        "input",
                        map(vec![
                            ("applicable_roles", Node::Null),
                            ("name", Node::Bool(false)),
                            ("additional_days", Node::Int(0)),
                        ]),
                    ),
                    ("is_active", Node::Bool(false)),
                ]),
            );
            assert_eq!(get(&result, "applicable_roles"), &Node::Null);
            assert_eq!(get(&result, "name"), &Node::Bool(false));
            assert_eq!(get(&result, "additional_days"), &Node::Int(0));
            for active in [true, false] {
                assert_eq!(
                    get(&run(9, map(vec![("active", Node::Bool(active))])), "label"),
                    &string(if active {
                        b"activated".as_slice()
                    } else {
                        b"deactivated"
                    })
                );
            }
            assert_eq!(
                run(
                    10,
                    map(vec![(
                        "promotions",
                        list(vec![
                            map(vec![
                                ("is_active", Node::Bool(true)),
                                ("statistics_count", Node::Int(3))
                            ]),
                            map(vec![
                                ("is_active", Node::Bool(false)),
                                ("statistics_count", Node::Int(4))
                            ])
                        ])
                    )])
                ),
                map(vec![
                    ("total_promotions", Node::Int(2)),
                    ("active_promotions", Node::Int(1)),
                    ("total_applications", Node::Int(7))
                ])
            );
            let overflow = map(vec![(
                "promotions",
                list(vec![
                    map(vec![
                        ("is_active", Node::Bool(true)),
                        ("statistics_count", Node::Int(i64::MAX)),
                    ]),
                    map(vec![
                        ("is_active", Node::Bool(false)),
                        ("statistics_count", Node::Int(1)),
                    ]),
                ]),
            )]);
            assert!(output(10, &wire(&overflow)).is_err());
        }

        #[test]
        fn w29_admin_gdpr_reg_promo_invite_history_invitation_and_history() {
            for (order, column, direction) in [
                ("created_at_asc", "created_at", "asc"),
                ("created_at_desc", "created_at", "desc"),
                ("expires_at_asc", "expires_at", "asc"),
                ("expires_at_desc", "expires_at", "desc"),
                ("email_asc", "email", "asc"),
                ("email_desc", "email", "desc"),
                ("bad", "created_at", "desc"),
            ] {
                let result = run(
                    11,
                    map(vec![(
                        "input",
                        map(vec![
                            ("ob", string(order)),
                            ("status", Node::Bool(true)),
                            ("invited_by", Node::Int(i64::MIN)),
                            ("email", list(Vec::new())),
                        ]),
                    )]),
                );
                assert_eq!(get(&result, "orderBy"), &string(order));
                assert_eq!(get(&result, "column"), &string(column));
                assert_eq!(get(&result, "direction"), &string(direction));
                assert_eq!(get(&result, "status"), &string(b"1"));
                assert_eq!(get(&result, "invited_by"), &string(i64::MIN.to_string()));
                assert_eq!(get(&result, "email"), &string(b""));
            }
            assert_eq!(
                get(&run(11, fixture(11)), "orderBy"),
                &string(b"created_at_desc")
            );
            assert_eq!(
                run(12, fixture(12)),
                map(vec![
                    ("", string(b"All")),
                    ("pending", string(b"Pending")),
                    ("used", string(b"Used")),
                    ("expired", string(b"Expired")),
                    ("cancelled", string(b"Cancelled"))
                ])
            );
            for (action, verb) in [
                ("cancel", "Cancelled"),
                ("resend", "Resent"),
                ("delete", "Deleted"),
            ] {
                assert_eq!(
                    run(
                        13,
                        map(vec![
                            ("action", string(action)),
                            ("ids", list(vec![Node::Int(1)])),
                            ("count", Node::Int(3))
                        ])
                    ),
                    map(vec![
                        ("error", Node::Null),
                        ("message", string(format!("{verb} 3 invitations")))
                    ])
                );
            }
            for ids in [
                Node::Null,
                Node::Bool(false),
                Node::Int(0),
                Node::Float(b"0.0".to_vec()),
                string(b"0"),
                list(Vec::new()),
            ] {
                assert_eq!(
                    get(
                        &run(
                            13,
                            map(vec![
                                ("action", Node::Bool(true)),
                                ("ids", ids),
                                ("count", Node::Int(0))
                            ])
                        ),
                        "error"
                    ),
                    &string(b"No invitations selected")
                );
            }
            assert_eq!(
                get(
                    &run(
                        13,
                        map(vec![
                            ("action", string(b"invalid")),
                            ("ids", string(b"ids")),
                            ("count", Node::Int(0))
                        ])
                    ),
                    "error"
                ),
                &string(b"Invalid bulk action")
            );
            assert!(
                output(
                    13,
                    &wire(&map(vec![
                        ("action", Node::Bool(true)),
                        ("ids", string(b"ids")),
                        ("count", Node::Int(0))
                    ]))
                )
                .is_err()
            );
            assert_eq!(
                run(
                    14,
                    map(vec![(
                        "input",
                        map(vec![
                            ("user_id", Node::Int(1)),
                            ("username", Node::Bool(false)),
                            ("role_id", Node::Bool(true)),
                            ("change_reason", string(b"\0\xff")),
                            ("date_from", Node::Null),
                            ("date_to", list(vec![Node::Int(1)]))
                        ])
                    )])
                ),
                map(vec![
                    ("user_id", string(b"1")),
                    ("username", string(b"")),
                    ("role_id", string(b"1")),
                    ("change_reason", string(b"\0\xff")),
                    ("date_from", string(b"")),
                    ("date_to", string(b""))
                ])
            );
            assert_eq!(
                run(15, fixture(15)),
                map(vec![
                    ("date_from", Node::Null),
                    ("date_to", string(b"2026-10-24 23:59:59"))
                ])
            );
            assert_eq!(
                get(
                    &run(
                        15,
                        map(vec![
                            ("date_from", string(b"\0\xff")),
                            ("date_to", string(b""))
                        ])
                    ),
                    "date_from"
                ),
                &string(b"\0\xff 00:00:00")
            );
        }
        #[test]
        fn w29_admin_gdpr_reg_promo_invite_history_malformed_frames_and_types() {
            let mut invalid = vec![Vec::new(), vec![0], vec![0; 4], vec![4, 0, 0, 0, b'a']];
            for raw in [
                b"".as_slice(),
                b"n0",
                b"b2",
                b"i01",
                b"i+1",
                b"i-0",
                b"i9223372036854775808",
                b"fNaN",
                b"fINF",
                b"f1e999",
                b"f 1",
                b"a01",
                b"a-1",
                b"a18446744073709551616",
                b"a2",
                b"x",
            ] {
                let mut frame = Vec::new();
                wave22_append_frame(&mut frame, Some(raw)).unwrap();
                invalid.push(frame);
            }
            for frames in [
                vec![None],
                vec![Some(b"a0".as_slice()), None],
                vec![Some(b"a0".as_slice()), Some(b"n".as_slice())],
                vec![
                    Some(b"a1".as_slice()),
                    Some(b"n".as_slice()),
                    Some(b"n".as_slice()),
                ],
                vec![
                    Some(b"a2".as_slice()),
                    Some(b"i1".as_slice()),
                    Some(b"n".as_slice()),
                    Some(b"s1".as_slice()),
                    Some(b"n".as_slice()),
                ],
            ] {
                let mut input = Vec::new();
                for frame in frames {
                    wave22_append_frame(&mut input, frame).unwrap();
                }
                invalid.push(input);
            }
            let mut deep = map(Vec::new());
            for _ in 0..65 {
                deep = map(vec![("deep", deep)]);
            }
            invalid.push(wire(&deep));
            for input in invalid {
                for operation in 0..=15 {
                    assert!(output(operation, &input).is_err());
                    let mut target = [0xab; 128];
                    assert_eq!(
                        unsafe {
                            ffi(
                                operation,
                                input.as_ptr(),
                                input.len(),
                                target.as_mut_ptr(),
                                target.len(),
                            )
                        },
                        -1
                    );
                    assert_eq!(target, [0xab; 128]);
                }
            }
            for operation in [16, 100, u32::MAX] {
                assert!(output(operation, &wire(&fixture(12))).is_err());
            }
            for (operation, name, value) in [
                (0, "type", Node::Int(1)),
                (1, "status", Node::Null),
                (2, "subject_exists", Node::Int(1)),
                (2, "is_admin", Node::Null),
                (2, "action", string(b"other")),
                (4, "periods", map(vec![("x", Node::Null)])),
                (6, "entry", Node::Null),
                (6, "statuses", list(vec![string(b"0"), Node::Null])),
                (7, "new_status", Node::Bool(true)),
                (8, "input", Node::Null),
                (8, "is_active", Node::Int(1)),
                (9, "active", Node::Null),
                (10, "promotions", list(vec![Node::Null])),
                (
                    11,
                    "input",
                    map(vec![("status", Node::Float(b"1.5".to_vec()))]),
                ),
                (13, "count", string(b"1")),
                (
                    14,
                    "input",
                    map(vec![("user_id", Node::Float(b"1.5".to_vec()))]),
                ),
                (15, "date_to", Node::Null),
            ] {
                let mut input = array(&fixture(operation)).unwrap().to_vec();
                set(&mut input, name, value);
                assert!(
                    output(operation, &wire(&Node::Array(input))).is_err(),
                    "accepted operation {operation} field {name}"
                );
            }
        }
        #[test]
        fn w29_admin_gdpr_reg_promo_invite_history_regions_capacities_unaligned_and_aliasing() {
            for operation in 0..=15 {
                let input = wire(&fixture(operation));
                let expected = output(operation, &input).unwrap();
                let mut target = vec![0xab; expected.len() + 8];
                unsafe {
                    for capacity in [0, 1, expected.len() - 1] {
                        assert_eq!(
                            ffi(
                                operation,
                                input.as_ptr(),
                                input.len(),
                                target.as_mut_ptr(),
                                capacity
                            ),
                            -(expected.len() as i32)
                        );
                        assert!(target.iter().all(|byte| *byte == 0xab));
                    }
                    for (source, length, out, capacity) in [
                        (std::ptr::null(), 0, target.as_mut_ptr(), target.len()),
                        (
                            std::ptr::null(),
                            input.len(),
                            target.as_mut_ptr(),
                            target.len(),
                        ),
                        (input.as_ptr(), input.len(), std::ptr::null_mut(), 0),
                        (input.as_ptr(), input.len(), std::ptr::null_mut(), 10),
                        (
                            usize::MAX as *const u8,
                            1,
                            target.as_mut_ptr(),
                            target.len(),
                        ),
                        (input.as_ptr(), input.len(), usize::MAX as *mut u8, 1),
                        (
                            input.as_ptr(),
                            usize::MAX,
                            target.as_mut_ptr(),
                            target.len(),
                        ),
                        (input.as_ptr(), input.len(), target.as_mut_ptr(), usize::MAX),
                        (
                            input.as_ptr(),
                            isize::MAX as usize + 1,
                            target.as_mut_ptr(),
                            target.len(),
                        ),
                        (
                            input.as_ptr(),
                            input.len(),
                            target.as_mut_ptr(),
                            isize::MAX as usize + 1,
                        ),
                    ] {
                        assert_eq!(ffi(operation, source, length, out, capacity), -1);
                        assert!(target.iter().all(|byte| *byte == 0xab));
                    }
                    let unaligned = [vec![0xab], input.clone()].concat();
                    assert_eq!(
                        ffi(
                            operation,
                            unaligned.as_ptr().add(1),
                            input.len(),
                            target.as_mut_ptr().add(1),
                            expected.len()
                        ),
                        expected.len() as i32
                    );
                    assert_eq!(&target[1..1 + expected.len()], expected);
                    assert_eq!(target[0], 0xab);
                    assert!(
                        target[1 + expected.len()..]
                            .iter()
                            .all(|byte| *byte == 0xab)
                    );
                    for (source_offset, target_offset) in [(0, 0), (0, 1), (3, 0)] {
                        for capacity in
                            [0, 1, expected.len() - 1, expected.len(), expected.len() + 1]
                        {
                            let mut buffer = vec![0xab; expected.len() + input.len() + 16];
                            buffer[source_offset..source_offset + input.len()]
                                .copy_from_slice(&input);
                            let before = buffer.clone();
                            let actual = ffi(
                                operation,
                                buffer.as_ptr().add(source_offset),
                                input.len(),
                                buffer.as_mut_ptr().add(target_offset),
                                capacity,
                            );
                            if capacity < expected.len() {
                                assert_eq!(actual, -(expected.len() as i32));
                                assert_eq!(buffer, before);
                            } else {
                                assert_eq!(actual, expected.len() as i32);
                                assert_eq!(
                                    &buffer[target_offset..target_offset + expected.len()],
                                    expected
                                );
                                assert_eq!(&buffer[..target_offset], &before[..target_offset]);
                                assert_eq!(
                                    &buffer[target_offset + expected.len()..],
                                    &before[target_offset + expected.len()..]
                                );
                            }
                        }
                    }
                }
            }
        }
    }
}

/// Render typed admin GDPR, registration, promotion, invitation and role-history decision frames without partially writing output.
/// Returns bytes written, -1 on invalid input/panic, or negated required size.
/// Input and output may overlap; every output is built before touching the caller buffer.
///
/// # Safety
/// Both regions must be non-null and allocated for their stated extents, with
/// lengths fitting isize and no address overflow. Only byte alignment is needed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w29_admin_gdpr_reg_promo_invite_history(
    operation: u32,
    input: *const u8,
    len: usize,
    out: *mut u8,
    out_cap: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(input, len) || !cleaning_ffi_region_valid(out, out_cap) {
            return -1;
        }
        let output = {
            let input = unsafe { slice::from_raw_parts(input, len) };
            match wave29_admin_gdpr_reg_promo_invite_history::output(operation, input) {
                Ok(output) => output,
                Err(_) => return -1,
            }
        };
        let Ok(needed) = i32::try_from(output.len()) else {
            return -1;
        };
        if output.len() > out_cap {
            return -needed;
        }
        unsafe { std::ptr::copy_nonoverlapping(output.as_ptr(), out, output.len()) };
        needed
    }))
    .unwrap_or(-1)
}

// ---------------------------------------------------------------------------
// Wave 30 Slice 30.1: CleanNZB, FixNzbSplitNames, RemoveCrapReleases,
// NntmuxRemoveBadReleases and ReclassifyHeaderRejections command helpers
// ---------------------------------------------------------------------------

pub mod wave30_nzb_release_cleanup_commands {
    //! Request: a JSON array `[operation, ...arguments]`. Response: a JSON array `[result]`.
    //! Inputs whose PHP semantics are not reproduced exactly are declined with `Err`, so the
    //! PHP caller falls back to its native implementation.

    use std::collections::HashSet;

    #[derive(Debug, Clone, PartialEq)]
    pub enum Value {
        Null,
        Bool(bool),
        Int(i64),
        Float(f64),
        Str(Vec<u8>),
        List(Vec<Value>),
        Map(Vec<(Vec<u8>, Value)>),
    }

    const MAX_DEPTH: usize = 4;
    const MAX_INPUT_LEN: usize = 16 * 1024 * 1024;

    struct Parser<'a> {
        bytes: &'a [u8],
        pos: usize,
    }

    impl Parser<'_> {
        fn skip_ws(&mut self) {
            while matches!(self.bytes.get(self.pos), Some(b' ' | b'\t' | b'\n' | b'\r')) {
                self.pos += 1;
            }
        }

        fn literal(&mut self, text: &[u8], value: Value) -> Result<Value, ()> {
            if self.bytes[self.pos..].starts_with(text) {
                self.pos += text.len();
                Ok(value)
            } else {
                Err(())
            }
        }

        fn value(&mut self, depth: usize) -> Result<Value, ()> {
            self.skip_ws();
            match *self.bytes.get(self.pos).ok_or(())? {
                b'n' => self.literal(b"null", Value::Null),
                b't' => self.literal(b"true", Value::Bool(true)),
                b'f' => self.literal(b"false", Value::Bool(false)),
                b'"' => self.string().map(Value::Str),
                b'-' | b'0'..=b'9' => self.number(),
                b'[' => {
                    if depth >= MAX_DEPTH {
                        return Err(());
                    }
                    self.pos += 1;
                    let mut items = Vec::new();
                    self.skip_ws();
                    if self.bytes.get(self.pos) == Some(&b']') {
                        self.pos += 1;
                        return Ok(Value::List(items));
                    }
                    loop {
                        items.push(self.value(depth + 1)?);
                        self.skip_ws();
                        match self.bytes.get(self.pos) {
                            Some(b',') => self.pos += 1,
                            Some(b']') => {
                                self.pos += 1;
                                return Ok(Value::List(items));
                            }
                            _ => return Err(()),
                        }
                    }
                }
                b'{' => {
                    if depth >= MAX_DEPTH {
                        return Err(());
                    }
                    self.pos += 1;
                    let mut entries = Vec::new();
                    self.skip_ws();
                    if self.bytes.get(self.pos) == Some(&b'}') {
                        self.pos += 1;
                        return Ok(Value::Map(entries));
                    }
                    loop {
                        self.skip_ws();
                        if self.bytes.get(self.pos) != Some(&b'"') {
                            return Err(());
                        }
                        let key = self.string()?;
                        self.skip_ws();
                        if self.bytes.get(self.pos) != Some(&b':') {
                            return Err(());
                        }
                        self.pos += 1;
                        entries.push((key, self.value(depth + 1)?));
                        self.skip_ws();
                        match self.bytes.get(self.pos) {
                            Some(b',') => self.pos += 1,
                            Some(b'}') => {
                                self.pos += 1;
                                return Ok(Value::Map(entries));
                            }
                            _ => return Err(()),
                        }
                    }
                }
                _ => Err(()),
            }
        }

        fn digits(&mut self) -> usize {
            let start = self.pos;
            while self.bytes.get(self.pos).is_some_and(u8::is_ascii_digit) {
                self.pos += 1;
            }
            self.pos - start
        }

        fn number(&mut self) -> Result<Value, ()> {
            let start = self.pos;
            if self.bytes.get(self.pos) == Some(&b'-') {
                self.pos += 1;
            }
            match self.bytes.get(self.pos) {
                Some(b'0') => self.pos += 1,
                Some(b'1'..=b'9') => {
                    self.digits();
                }
                _ => return Err(()),
            }
            let mut float = false;
            if self.bytes.get(self.pos) == Some(&b'.') {
                float = true;
                self.pos += 1;
                if self.digits() == 0 {
                    return Err(());
                }
            }
            if matches!(self.bytes.get(self.pos), Some(b'e' | b'E')) {
                float = true;
                self.pos += 1;
                if matches!(self.bytes.get(self.pos), Some(b'+' | b'-')) {
                    self.pos += 1;
                }
                if self.digits() == 0 {
                    return Err(());
                }
            }
            let text = std::str::from_utf8(&self.bytes[start..self.pos]).map_err(|_| ())?;
            if float {
                let value: f64 = text.parse().map_err(|_| ())?;
                if !value.is_finite() {
                    return Err(());
                }
                Ok(Value::Float(value))
            } else {
                text.parse::<i64>().map(Value::Int).map_err(|_| ())
            }
        }

        fn hex4(&mut self) -> Result<u32, ()> {
            let digits = self.bytes.get(self.pos..self.pos + 4).ok_or(())?;
            let mut value = 0u32;
            for &digit in digits {
                value = value * 16 + char::from(digit).to_digit(16).ok_or(())?;
            }
            self.pos += 4;
            Ok(value)
        }

        fn string(&mut self) -> Result<Vec<u8>, ()> {
            self.pos += 1;
            let mut out = Vec::new();
            loop {
                let byte = *self.bytes.get(self.pos).ok_or(())?;
                self.pos += 1;
                match byte {
                    b'"' => return Ok(out),
                    b'\\' => {
                        let escape = *self.bytes.get(self.pos).ok_or(())?;
                        self.pos += 1;
                        match escape {
                            b'"' | b'\\' | b'/' => out.push(escape),
                            b'b' => out.push(0x08),
                            b'f' => out.push(0x0c),
                            b'n' => out.push(b'\n'),
                            b'r' => out.push(b'\r'),
                            b't' => out.push(b'\t'),
                            b'u' => {
                                let mut code = self.hex4()?;
                                if (0xD800..0xDC00).contains(&code) {
                                    if self.bytes.get(self.pos..self.pos + 2) != Some(b"\\u") {
                                        return Err(());
                                    }
                                    self.pos += 2;
                                    let low = self.hex4()?;
                                    if !(0xDC00..0xE000).contains(&low) {
                                        return Err(());
                                    }
                                    code = 0x10000 + ((code - 0xD800) << 10) + (low - 0xDC00);
                                }
                                let ch = char::from_u32(code).ok_or(())?;
                                let mut buf = [0u8; 4];
                                out.extend_from_slice(ch.encode_utf8(&mut buf).as_bytes());
                            }
                            _ => return Err(()),
                        }
                    }
                    0x00..=0x1f => return Err(()),
                    _ => out.push(byte),
                }
            }
        }
    }

    pub fn parse(input: &[u8]) -> Result<Value, ()> {
        if input.len() > MAX_INPUT_LEN || std::str::from_utf8(input).is_err() {
            return Err(());
        }
        let mut parser = Parser {
            bytes: input,
            pos: 0,
        };
        let value = parser.value(0)?;
        parser.skip_ws();
        if parser.pos != input.len() {
            return Err(());
        }
        Ok(value)
    }

    fn push_string(out: &mut Vec<u8>, text: &[u8]) {
        out.push(b'"');
        for &byte in text {
            match byte {
                b'"' => out.extend_from_slice(b"\\\""),
                b'\\' => out.extend_from_slice(b"\\\\"),
                b'\n' => out.extend_from_slice(b"\\n"),
                b'\r' => out.extend_from_slice(b"\\r"),
                b'\t' => out.extend_from_slice(b"\\t"),
                0x00..=0x1f => {
                    const HEX: &[u8; 16] = b"0123456789abcdef";
                    out.extend_from_slice(b"\\u00");
                    out.push(HEX[usize::from(byte >> 4)]);
                    out.push(HEX[usize::from(byte & 0x0f)]);
                }
                _ => out.push(byte),
            }
        }
        out.push(b'"');
    }

    pub fn encode(out: &mut Vec<u8>, value: &Value) -> Result<(), ()> {
        match value {
            Value::Null => out.extend_from_slice(b"null"),
            Value::Bool(true) => out.extend_from_slice(b"true"),
            Value::Bool(false) => out.extend_from_slice(b"false"),
            Value::Int(number) => out.extend_from_slice(number.to_string().as_bytes()),
            // Responses never carry floats; PHP's float rendering is not reproduced here.
            Value::Float(_) => return Err(()),
            Value::Str(text) => push_string(out, text),
            Value::List(items) => {
                out.push(b'[');
                for (index, item) in items.iter().enumerate() {
                    if index > 0 {
                        out.push(b',');
                    }
                    encode(out, item)?;
                }
                out.push(b']');
            }
            Value::Map(entries) => {
                out.push(b'{');
                for (index, (key, item)) in entries.iter().enumerate() {
                    if index > 0 {
                        out.push(b',');
                    }
                    push_string(out, key);
                    out.push(b':');
                    encode(out, item)?;
                }
                out.push(b'}');
            }
        }
        Ok(())
    }

    fn int(value: &Value) -> Result<i64, ()> {
        match value {
            Value::Int(number) => Ok(*number),
            _ => Err(()),
        }
    }

    fn boolean(value: &Value) -> Result<bool, ()> {
        match value {
            Value::Bool(flag) => Ok(*flag),
            _ => Err(()),
        }
    }

    fn string(value: &Value) -> Result<&[u8], ()> {
        match value {
            Value::Str(text) => Ok(text),
            _ => Err(()),
        }
    }

    /// PHP `(int)` for plain decimal strings; any other string shape is declined.
    fn php_int_cast_str(text: &[u8]) -> Result<i64, ()> {
        if text.is_empty() {
            return Ok(0);
        }
        let negative = text[0] == b'-';
        let digits = if matches!(text[0], b'+' | b'-') {
            &text[1..]
        } else {
            text
        };
        if digits.is_empty() || digits.len() > 18 || !digits.iter().all(u8::is_ascii_digit) {
            return Err(());
        }
        let magnitude = digits
            .iter()
            .fold(0i64, |acc, digit| acc * 10 + i64::from(digit - b'0'));
        Ok(if negative { -magnitude } else { magnitude })
    }

    /// PHP `(int)` for scalars and null; arrays, maps and non-representable floats are declined.
    fn php_int_cast(value: &Value) -> Result<i64, ()> {
        match value {
            Value::Null => Ok(0),
            Value::Bool(flag) => Ok(i64::from(*flag)),
            Value::Int(number) => Ok(*number),
            Value::Float(number) => {
                if number.is_finite()
                    && *number >= -9_223_372_036_854_775_808.0
                    && *number < 9_223_372_036_854_775_808.0
                {
                    Ok(number.trunc() as i64)
                } else {
                    Err(())
                }
            }
            Value::Str(text) => php_int_cast_str(text),
            Value::List(_) | Value::Map(_) => Err(()),
        }
    }

    /// PHP `(string)` for strings, ints, booleans and null; floats and containers are declined.
    fn php_string_cast(value: &Value) -> Result<Vec<u8>, ()> {
        match value {
            Value::Null | Value::Bool(false) => Ok(Vec::new()),
            Value::Bool(true) => Ok(b"1".to_vec()),
            Value::Int(number) => Ok(number.to_string().into_bytes()),
            Value::Str(text) => Ok(text.clone()),
            Value::Float(_) | Value::List(_) | Value::Map(_) => Err(()),
        }
    }

    /// PHP `$value ?? $default` followed by `(string)`.
    fn coalesce_string(value: &Value, default: &[u8]) -> Result<Value, ()> {
        match value {
            Value::Null => Ok(Value::Str(default.to_vec())),
            other => php_string_cast(other).map(Value::Str),
        }
    }

    fn text(parts: &[&[u8]]) -> Value {
        Value::Str(parts.concat())
    }

    fn entry(key: &str, value: Value) -> (Vec<u8>, Value) {
        (key.as_bytes().to_vec(), value)
    }

    pub fn evaluate(operation: &[u8], args: &[Value]) -> Result<Value, ()> {
        Ok(match (operation, args) {
            (b"clean_nzb.normalize_temp_age_seconds", [hours]) => {
                Value::Int(php_int_cast(hours)?.max(1).checked_mul(3600).ok_or(())?)
            }
            (b"clean_nzb.extract_guid_from_nzb_gz_filename", [filename]) => {
                let filename = string(filename)?;
                match filename.strip_suffix(b".nzb.gz") {
                    Some(guid) => Value::Str(guid.to_vec()),
                    None => Value::Null,
                }
            }
            (b"clean_nzb.format_staged_outcome_line", [delete, applied, deleted, kept]) => {
                Value::Str(
                    format!(
                        "{} stale temporary NZBs: {} deleted, {} applied, {} kept.",
                        if boolean(delete)? { "Cleaned" } else { "Checked (dry run)" },
                        int(deleted)?,
                        int(applied)?,
                        int(kept)?
                    )
                    .into_bytes(),
                )
            }
            (b"clean_nzb.format_temp_cleanup_line", [delete, stale]) => {
                let stale = int(stale)?;
                Value::Str(
                    if boolean(delete)? {
                        format!("Cleaning {stale} stale temporary NZB paths.")
                    } else {
                        format!("Found {stale} stale temporary NZB paths (dry run).")
                    }
                    .into_bytes(),
                )
            }
            (b"fix_nzb_split.format_dry_run_diff_line", [id, old_name, new_name, old_cat, new_cat]) => {
                text(&[
                    b"[dry-run] #",
                    int(id)?.to_string().as_bytes(),
                    b" ",
                    string(old_name)?,
                    b" -> ",
                    string(new_name)?,
                    b" (",
                    int(old_cat)?.to_string().as_bytes(),
                    b" -> ",
                    int(new_cat)?.to_string().as_bytes(),
                    b")",
                ])
            }
            (b"fix_nzb_split.build_reset_update_payload", [new_name, category]) => {
                let new_name = string(new_name)?;
                Value::Map(vec![
                    entry("name", Value::Str(new_name.to_vec())),
                    entry("searchname", Value::Str(new_name.to_vec())),
                    entry("categories_id", Value::Int(int(category)?)),
                    entry("isrenamed", Value::Int(1)),
                    entry("iscategorized", Value::Int(1)),
                    entry("videos_id", Value::Int(0)),
                    entry("tv_episodes_id", Value::Int(0)),
                    entry("imdbid", Value::Null),
                    entry("musicinfo_id", Value::Null),
                    entry("consoleinfo_id", Value::Null),
                    entry("gamesinfo_id", Value::Int(0)),
                    entry("bookinfo_id", Value::Null),
                    entry("anidbid", Value::Null),
                    entry("movieinfo_id", Value::Null),
                ])
            }
            (b"fix_nzb_split.format_completion_summary", [dry_run, fixed, checked]) => {
                Value::Str(
                    if boolean(dry_run)? {
                        format!("Dry run complete. {} NZBSPLIT-wrapped releases inspected.", int(checked)?)
                    } else {
                        format!("Updated {} NZBSPLIT-wrapped releases.", int(fixed)?)
                    }
                    .into_bytes(),
                )
            }
            (b"remove_crap.normalize_options", [kind, time, blacklist_id, delete]) => Value::Map(vec![
                entry("type", coalesce_string(kind, b"")?),
                entry("time", coalesce_string(time, b"full")?),
                entry("blacklist_id", coalesce_string(blacklist_id, b"")?),
                entry("delete", Value::Bool(boolean(delete)?)),
            ]),
            (b"remove_crap.format_summary_message", [result, delete]) => {
                let delete = boolean(delete)?;
                let (status, message) = match result {
                    Value::Bool(true) if delete => ("info", b"Crap release removal complete.".to_vec()),
                    Value::Bool(true) => ("info", b"Dry run complete. Use --delete to remove the matched releases.".to_vec()),
                    Value::Str(message) if !message.is_empty() => ("warn", message.clone()),
                    _ => ("warn", b"Crap release removal failed.".to_vec()),
                };
                Value::Map(vec![
                    entry("status", Value::Str(status.as_bytes().to_vec())),
                    entry("message", Value::Str(message)),
                ])
            }
            (b"remove_bad.is_bad_password_status", [status]) => Value::Bool(int(status)? <= -2),
            (b"remove_bad.normalize_release_id_list", [Value::List(values)]) => {
                let mut seen = HashSet::with_capacity(values.len());
                let mut ids = Vec::with_capacity(values.len());
                for value in values {
                    let id = match value {
                        Value::List(_) | Value::Map(_) => 0,
                        scalar => php_int_cast(scalar)?,
                    };
                    if id > 0 && seen.insert(id) {
                        ids.push(Value::Int(id));
                    }
                }
                Value::List(ids)
            }
            (b"remove_bad.format_updated_bad_releases_message", [count]) => {
                Value::Str(format!("Updated {} bad releases", int(count)?).into_bytes())
            }
            (b"reclassify.normalize_chunk_window", [chunk, after]) => {
                let chunk = int(chunk)?.max(1);
                let after = int(after)?.max(0);
                Value::Map(vec![
                    entry("chunk", Value::Int(chunk)),
                    entry("after", Value::Int(after)),
                    entry("through", Value::Int(after.checked_add(chunk).ok_or(())?)),
                ])
            }
            (b"reclassify.has_exceeded_max_seconds", [max_seconds, elapsed]) => {
                let max_seconds = int(max_seconds)?;
                Value::Bool(
                    max_seconds > 0
                        && match elapsed {
                            Value::Int(elapsed) => *elapsed >= max_seconds,
                            // PHP compares an int with a float as `(double) int`.
                            Value::Float(elapsed) => *elapsed >= max_seconds as f64,
                            _ => return Err(()),
                        },
                )
            }
            (b"reclassify.format_summary_line", [scanned, reclassified, deleted, last_id]) => Value::Str(
                format!(
                    "Reclassified {} candidates with header defects (scanned {} ids through {}, deleted {}).",
                    int(reclassified)?,
                    int(scanned)?,
                    int(last_id)?,
                    int(deleted)?
                )
                .into_bytes(),
            ),
            _ => return Err(()),
        })
    }

    /// Evaluates one JSON request and returns its JSON response bytes.
    pub fn output(input: &[u8]) -> Result<Vec<u8>, ()> {
        let Value::List(items) = parse(input)? else {
            return Err(());
        };
        let Some((Value::Str(operation), args)) = items.split_first() else {
            return Err(());
        };
        let result = evaluate(operation, args)?;
        let mut out = Vec::with_capacity(64);
        out.push(b'[');
        encode(&mut out, &result)?;
        out.push(b']');
        Ok(out)
    }
}

/// Evaluate one JSON-dispatched CleanNZB, FixNzbSplitNames, RemoveCrapReleases, NntmuxRemoveBadReleases or
/// ReclassifyHeaderRejections helper request without partially writing output.
/// Returns bytes written, -1 on invalid or declined input/panic, or the negated required size (always <= -3).
///
/// # Safety
/// Both regions must be non-null and allocated for their stated extents, with
/// lengths fitting isize and no address overflow. Only byte alignment is needed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w30_nzb_release_cleanup_commands(
    input: *const u8,
    len: usize,
    out: *mut u8,
    out_cap: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(input, len) || !cleaning_ffi_region_valid(out, out_cap) {
            return -1;
        }
        let output = {
            let input = unsafe { std::slice::from_raw_parts(input, len) };
            match wave30_nzb_release_cleanup_commands::output(input) {
                Ok(output) => output,
                Err(()) => return -1,
            }
        };
        let Ok(needed) = i32::try_from(output.len()) else {
            return -1;
        };
        if output.len() > out_cap {
            return -needed;
        }
        unsafe { std::ptr::copy_nonoverlapping(output.as_ptr(), out, output.len()) };
        needed
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave30_nzb_release_cleanup_commands_tests {
    use super::nntmux_w30_nzb_release_cleanup_commands as ffi;
    use super::wave30_nzb_release_cleanup_commands::output;

    fn run(request: &str) -> Option<String> {
        output(request.as_bytes())
            .ok()
            .map(|bytes| String::from_utf8(bytes).unwrap())
    }

    fn ok(request: &str, expected: &str) {
        assert_eq!(run(request).as_deref(), Some(expected), "request {request}");
    }

    fn declined(request: &str) {
        assert_eq!(run(request), None, "request {request}");
    }

    #[test]
    fn clean_nzb_helpers_match_php() {
        ok(
            r#"["clean_nzb.normalize_temp_age_seconds","24"]"#,
            "[86400]",
        );
        ok(r#"["clean_nzb.normalize_temp_age_seconds",null]"#, "[3600]");
        ok(r#"["clean_nzb.normalize_temp_age_seconds",""]"#, "[3600]");
        ok(r#"["clean_nzb.normalize_temp_age_seconds","-5"]"#, "[3600]");
        ok(r#"["clean_nzb.normalize_temp_age_seconds","+2"]"#, "[7200]");
        ok(r#"["clean_nzb.normalize_temp_age_seconds",true]"#, "[3600]");
        ok(r#"["clean_nzb.normalize_temp_age_seconds",2.9]"#, "[7200]");
        ok(r#"["clean_nzb.normalize_temp_age_seconds",48]"#, "[172800]");
        declined(r#"["clean_nzb.normalize_temp_age_seconds","12abc"]"#);
        declined(r#"["clean_nzb.normalize_temp_age_seconds"," 12"]"#);
        declined(r#"["clean_nzb.normalize_temp_age_seconds",9223372036854775807]"#);
        declined(r#"["clean_nzb.normalize_temp_age_seconds",1.0e300]"#);
        declined(r#"["clean_nzb.normalize_temp_age_seconds",[1]]"#);

        ok(
            r#"["clean_nzb.extract_guid_from_nzb_gz_filename","abc.nzb.gz"]"#,
            r#"["abc"]"#,
        );
        ok(
            r#"["clean_nzb.extract_guid_from_nzb_gz_filename",".nzb.gz"]"#,
            r#"[""]"#,
        );
        ok(
            r#"["clean_nzb.extract_guid_from_nzb_gz_filename","abc.NZB.GZ"]"#,
            "[null]",
        );
        ok(
            r#"["clean_nzb.extract_guid_from_nzb_gz_filename","a\u0000b.nzb.gz"]"#,
            r#"["a\u0000b"]"#,
        );
        ok(
            r#"["clean_nzb.extract_guid_from_nzb_gz_filename","é😀.nzb.gz"]"#,
            r#"["é😀"]"#,
        );
        ok(
            r#"["clean_nzb.extract_guid_from_nzb_gz_filename","\ud83d\ude00.nzb.gz"]"#,
            r#"["😀"]"#,
        );
        declined(r#"["clean_nzb.extract_guid_from_nzb_gz_filename",5]"#);

        ok(
            r#"["clean_nzb.format_staged_outcome_line",false,1,2,0]"#,
            r#"["Checked (dry run) stale temporary NZBs: 2 deleted, 1 applied, 0 kept."]"#,
        );
        ok(
            r#"["clean_nzb.format_staged_outcome_line",true,0,-3,1]"#,
            r#"["Cleaned stale temporary NZBs: -3 deleted, 0 applied, 1 kept."]"#,
        );
        ok(
            r#"["clean_nzb.format_temp_cleanup_line",true,4]"#,
            r#"["Cleaning 4 stale temporary NZB paths."]"#,
        );
        ok(
            r#"["clean_nzb.format_temp_cleanup_line",false,0]"#,
            r#"["Found 0 stale temporary NZB paths (dry run)."]"#,
        );
        declined(r#"["clean_nzb.format_temp_cleanup_line",1,0]"#);
    }

    #[test]
    fn fix_nzb_split_helpers_match_php() {
        ok(
            r#"["fix_nzb_split.format_dry_run_diff_line",7,"Old \"x\"\\y","Néw\n",-1,5050]"#,
            r#"["[dry-run] #7 Old \"x\"\\y -> Néw\n (-1 -> 5050)"]"#,
        );
        ok(
            r#"["fix_nzb_split.build_reset_update_payload","T",2030]"#,
            r#"[{"name":"T","searchname":"T","categories_id":2030,"isrenamed":1,"iscategorized":1,"videos_id":0,"tv_episodes_id":0,"imdbid":null,"musicinfo_id":null,"consoleinfo_id":null,"gamesinfo_id":0,"bookinfo_id":null,"anidbid":null,"movieinfo_id":null}]"#,
        );
        ok(
            r#"["fix_nzb_split.format_completion_summary",true,0,9]"#,
            r#"["Dry run complete. 9 NZBSPLIT-wrapped releases inspected."]"#,
        );
        ok(
            r#"["fix_nzb_split.format_completion_summary",false,3,9]"#,
            r#"["Updated 3 NZBSPLIT-wrapped releases."]"#,
        );
        declined(r#"["fix_nzb_split.format_completion_summary",false,3]"#);
    }

    #[test]
    fn remove_crap_and_bad_release_helpers_match_php() {
        ok(
            r#"["remove_crap.normalize_options",null,null,null,false]"#,
            r#"[{"type":"","time":"full","blacklist_id":"","delete":false}]"#,
        );
        ok(
            r#"["remove_crap.normalize_options","0","0",0,true]"#,
            r#"[{"type":"0","time":"0","blacklist_id":"0","delete":true}]"#,
        );
        ok(
            r#"["remove_crap.normalize_options",false,true,"",false]"#,
            r#"[{"type":"","time":"1","blacklist_id":"","delete":false}]"#,
        );
        declined(r#"["remove_crap.normalize_options",1.5,null,null,false]"#);
        ok(
            r#"["remove_crap.format_summary_message",true,true]"#,
            r#"[{"status":"info","message":"Crap release removal complete."}]"#,
        );
        ok(
            r#"["remove_crap.format_summary_message","Wrong type: x",false]"#,
            r#"[{"status":"warn","message":"Wrong type: x"}]"#,
        );
        ok(
            r#"["remove_crap.format_summary_message","",false]"#,
            r#"[{"status":"warn","message":"Crap release removal failed."}]"#,
        );
        ok(
            r#"["remove_crap.format_summary_message",1,false]"#,
            r#"[{"status":"warn","message":"Crap release removal failed."}]"#,
        );

        ok(r#"["remove_bad.is_bad_password_status",-2]"#, "[true]");
        ok(r#"["remove_bad.is_bad_password_status",-1]"#, "[false]");
        ok(
            r#"["remove_bad.normalize_release_id_list",[3,"3","7",0,-1,null,true,[9],{"a":1},"",2.5]]"#,
            "[[3,7,1,2]]",
        );
        ok(r#"["remove_bad.normalize_release_id_list",[]]"#, "[[]]");
        declined(r#"["remove_bad.normalize_release_id_list",["x"]]"#);
        declined(r#"["remove_bad.normalize_release_id_list",5]"#);
        ok(
            r#"["remove_bad.format_updated_bad_releases_message",12]"#,
            r#"["Updated 12 bad releases"]"#,
        );
    }

    #[test]
    fn reclassify_helpers_match_php() {
        ok(
            r#"["reclassify.normalize_chunk_window",0,-5]"#,
            r#"[{"chunk":1,"after":0,"through":1}]"#,
        );
        ok(
            r#"["reclassify.normalize_chunk_window",5000,100]"#,
            r#"[{"chunk":5000,"after":100,"through":5100}]"#,
        );
        declined(r#"["reclassify.normalize_chunk_window",2,9223372036854775807]"#);
        ok(
            r#"["reclassify.has_exceeded_max_seconds",0,100]"#,
            "[false]",
        );
        ok(r#"["reclassify.has_exceeded_max_seconds",10,10]"#, "[true]");
        ok(
            r#"["reclassify.has_exceeded_max_seconds",10,9.999]"#,
            "[false]",
        );
        ok(
            r#"["reclassify.has_exceeded_max_seconds",10,10.0]"#,
            "[true]",
        );
        ok(
            r#"["reclassify.has_exceeded_max_seconds",-1,10.0]"#,
            "[false]",
        );
        declined(r#"["reclassify.has_exceeded_max_seconds",10,"10"]"#);
        ok(
            r#"["reclassify.format_summary_line",10,2,0,110]"#,
            r#"["Reclassified 2 candidates with header defects (scanned 10 ids through 110, deleted 0)."]"#,
        );
    }

    #[test]
    fn malformed_requests_are_declined() {
        for request in [
            "",
            "[]",
            "{}",
            "[1]",
            r#"["unknown"]"#,
            r#"["remove_bad.is_bad_password_status",-2"#,
            r#"["remove_bad.is_bad_password_status",-2] x"#,
            r#"["remove_bad.is_bad_password_status",01]"#,
            r#"["remove_bad.is_bad_password_status",1.]"#,
            r#"["remove_bad.is_bad_password_status",9223372036854775808]"#,
            r#"["clean_nzb.extract_guid_from_nzb_gz_filename","\ud800.nzb.gz"]"#,
            r#"["clean_nzb.extract_guid_from_nzb_gz_filename","\x"]"#,
            "[\"clean_nzb.extract_guid_from_nzb_gz_filename\",\"a\tb\"]",
            r#"["remove_bad.normalize_release_id_list",[[[[1]]]]]"#,
        ] {
            declined(request);
        }
        assert!(
            output(b"[\"clean_nzb.extract_guid_from_nzb_gz_filename\",\"\xff.nzb.gz\"]").is_err()
        );
    }

    #[test]
    fn ffi_rejects_bad_regions_and_leaves_short_output_untouched() {
        let input = br#"["remove_bad.format_updated_bad_releases_message",12]"#;
        let expected = br#"["Updated 12 bad releases"]"#;
        let mut target = vec![0xab_u8; expected.len() + 8];
        unsafe {
            for capacity in [0, 1, expected.len() - 1] {
                assert_eq!(
                    ffi(input.as_ptr(), input.len(), target.as_mut_ptr(), capacity),
                    -(expected.len() as i32)
                );
                assert!(target.iter().all(|byte| *byte == 0xab));
            }
            assert_eq!(
                ffi(std::ptr::null(), 0, target.as_mut_ptr(), target.len()),
                -1
            );
            assert_eq!(
                ffi(input.as_ptr(), input.len(), std::ptr::null_mut(), 0),
                -1
            );
            assert_eq!(
                ffi(
                    input.as_ptr(),
                    usize::MAX,
                    target.as_mut_ptr(),
                    target.len()
                ),
                -1
            );
            assert_eq!(
                ffi(
                    input.as_ptr(),
                    input.len(),
                    target.as_mut_ptr(),
                    isize::MAX as usize + 1
                ),
                -1
            );
            let bad = br#"["unknown"]"#;
            assert_eq!(
                ffi(bad.as_ptr(), bad.len(), target.as_mut_ptr(), target.len()),
                -1
            );
            assert!(target.iter().all(|byte| *byte == 0xab));
            assert_eq!(
                ffi(
                    input.as_ptr(),
                    input.len(),
                    target.as_mut_ptr().add(1),
                    expected.len()
                ),
                expected.len() as i32
            );
            assert_eq!(&target[1..1 + expected.len()], expected);
            assert_eq!(target[0], 0xab);
            assert!(
                target[1 + expected.len()..]
                    .iter()
                    .all(|byte| *byte == 0xab)
            );
        }
    }
}

// ---------------------------------------------------------------------------
// Wave 31 Slice 31.1: BenchmarkRename, PartRepair, UpdateBinaries,
// OptimizeCbpStorage and NntmuxResetTruncate command helpers
// ---------------------------------------------------------------------------

pub mod wave31_benchmark_binaries_cbp_reset_commands {
    //! Request: a JSON array `[operation, ...arguments]`. Response: a JSON array `[result]`.
    //! Inputs whose PHP semantics are not reproduced exactly are declined with `Err`, so the
    //! PHP caller falls back to its native implementation.

    use super::wave30_nzb_release_cleanup_commands::{Value, parse};

    unsafe extern "C" {
        /// The platform libm `pow`, which PHP's `**` operator calls for float operands.
        safe fn pow(base: f64, exponent: f64) -> f64;
    }

    /// Characters PHP's `/\s/u` (PCRE2 UTF + UCP) matches.
    fn is_pcre_unicode_space(ch: char) -> bool {
        matches!(
            ch,
            '\u{9}'..='\u{d}'
                | ' '
                | '\u{85}'
                | '\u{a0}'
                | '\u{1680}'
                | '\u{180e}'
                | '\u{2000}'..='\u{200a}'
                | '\u{2028}'
                | '\u{2029}'
                | '\u{202f}'
                | '\u{205f}'
                | '\u{3000}'
        )
    }

    /// Bytes PHP's default `trim()` strips.
    fn is_php_trim_byte(byte: u8) -> bool {
        matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b)
    }

    fn php_trim(text: &[u8]) -> &[u8] {
        let start = text
            .iter()
            .position(|byte| !is_php_trim_byte(*byte))
            .unwrap_or(text.len());
        let end = text
            .iter()
            .rposition(|byte| !is_php_trim_byte(*byte))
            .map_or(start, |index| index + 1);
        &text[start..end]
    }

    const POW10: [f64; 23] = [
        1e0, 1e1, 1e2, 1e3, 1e4, 1e5, 1e6, 1e7, 1e8, 1e9, 1e10, 1e11, 1e12, 1e13, 1e14, 1e15, 1e16,
        1e17, 1e18, 1e19, 1e20, 1e21, 1e22,
    ];

    /// PHP 8.4+ `round($value, $places)` (`PHP_ROUND_HALF_UP`) for `1 <= $places <= 22`.
    pub fn php_round(value: f64, places: usize) -> f64 {
        if !value.is_finite() || value == 0.0 {
            return value;
        }
        let exponent = POW10[places];
        let scaled = value * exponent;
        let (mut integral, next) = if value >= 0.0 {
            let floor = scaled.floor();
            (floor, floor + 1.0)
        } else {
            let ceil = scaled.ceil();
            (ceil, ceil - 1.0)
        };
        if next / exponent == value {
            integral = next;
        }
        if integral.abs() >= 1e16 {
            return value;
        }
        let edge_case = ((integral + 0.5f64.copysign(integral)) / exponent).abs();
        if value.abs() >= edge_case {
            integral += 1.0f64.copysign(integral);
        }
        integral / exponent
    }

    /// PHP `number_format($value, $decimals)` with the default separators; magnitudes whose
    /// fixed-point rendering could reach a decimal tie are declined.
    pub fn php_number_format(value: f64, decimals: usize) -> Result<Vec<u8>, ()> {
        if !value.is_finite() || value.abs() >= 1e11 || !(1..=6).contains(&decimals) {
            return Err(());
        }
        let negative = value < 0.0;
        let rounded = php_round(value.abs(), decimals);
        let fixed = format!("{rounded:.decimals$}");
        let (integer, fraction) = fixed.split_once('.').ok_or(())?;
        let mut out = Vec::with_capacity(fixed.len() + integer.len() / 3 + 1);
        if negative && rounded != 0.0 {
            out.push(b'-');
        }
        for (index, digit) in integer.bytes().enumerate() {
            if index > 0 && (integer.len() - index) % 3 == 0 {
                out.push(b',');
            }
            out.push(digit);
        }
        out.push(b'.');
        out.extend_from_slice(fraction.as_bytes());
        Ok(out)
    }

    /// PHP `(int)` of an in-range float.
    fn php_float_to_int(value: f64) -> Result<i64, ()> {
        if value.is_finite()
            && (-9_223_372_036_854_775_808.0..9_223_372_036_854_775_808.0).contains(&value)
        {
            Ok(value.trunc() as i64)
        } else {
            Err(())
        }
    }

    fn int(value: &Value) -> Result<i64, ()> {
        match value {
            Value::Int(number) => Ok(*number),
            _ => Err(()),
        }
    }

    fn float(value: &Value) -> Result<f64, ()> {
        match value {
            Value::Float(number) => Ok(*number),
            _ => Err(()),
        }
    }

    fn boolean(value: &Value) -> Result<bool, ()> {
        match value {
            Value::Bool(flag) => Ok(*flag),
            _ => Err(()),
        }
    }

    fn string(value: &Value) -> Result<&[u8], ()> {
        match value {
            Value::Str(text) => Ok(text),
            _ => Err(()),
        }
    }

    /// The last value stored under `key`, like PHP's `json_decode`.
    fn field<'a>(value: &'a Value, key: &str) -> Result<&'a Value, ()> {
        match value {
            Value::Map(entries) => entries
                .iter()
                .rev()
                .find(|(name, _)| name == key.as_bytes())
                .map(|(_, item)| item)
                .ok_or(()),
            _ => Err(()),
        }
    }

    fn entry(key: &str, value: Value) -> (Vec<u8>, Value) {
        (key.as_bytes().to_vec(), value)
    }

    fn text(parts: &[&[u8]]) -> Value {
        Value::Str(parts.concat())
    }

    /// `BenchmarkRename::computeStatistics()`; integer sums that PHP would promote to float are declined.
    fn compute_statistics(durations: &Value, items_per_sample: &Value) -> Result<Value, ()> {
        let Value::List(durations) = durations else {
            return Err(());
        };
        let items_per_sample = int(items_per_sample)?;
        let count = durations.len();
        if count == 0 {
            let mut entries = vec![entry("count", Value::Int(0))];
            for key in [
                "min_ms",
                "p50_ms",
                "p95_ms",
                "p99_ms",
                "max_ms",
                "mean_ms",
                "stddev_ms",
                "iqr_ms",
                "ops_per_sec",
            ] {
                entries.push(entry(key, Value::Float(0.0)));
            }
            return Ok(Value::Map(entries));
        }
        let durations = durations.iter().map(int).collect::<Result<Vec<_>, _>>()?;
        let count_int = i64::try_from(count).map_err(|_| ())?;
        let total_ns = durations
            .iter()
            .try_fold(0i64, |sum, ns| sum.checked_add(*ns))
            .ok_or(())?;
        let total_ops = items_per_sample.checked_mul(count_int).ok_or(())?;

        let mut times: Vec<f64> = durations
            .iter()
            .map(|ns| *ns as f64 / 1_000_000.0)
            .collect();
        times.sort_by(f64::total_cmp);
        let count_float = count as f64;
        let mean = times.iter().fold(0.0, |sum, time| sum + time) / count_float;
        let p50 = if count % 2 == 0 {
            (times[count / 2 - 1] + times[count / 2]) / 2.0
        } else {
            times[count / 2]
        };
        let percentile = |fraction: f64| -> Result<f64, ()> {
            let rank = php_float_to_int((fraction * count_float).ceil())? - 1;
            let index = rank.min(count_int - 1);
            times
                .get(usize::try_from(index).map_err(|_| ())?)
                .copied()
                .ok_or(())
        };
        let p95 = percentile(0.95)?;
        let p99 = percentile(0.99)?;
        let iqr = percentile(0.75)? - percentile(0.25)?;
        let variance = if count > 1 {
            let exponent = std::hint::black_box(2.0);
            times
                .iter()
                .fold(0.0, |sum, time| sum + pow(time - mean, exponent))
                / (count - 1) as f64
        } else {
            0.0
        };
        let ops_per_sec = if total_ns > 0 {
            (total_ops as f64 * 1_000_000_000.0) / total_ns as f64
        } else {
            0.0
        };

        Ok(Value::Map(vec![
            entry("count", Value::Int(count_int)),
            entry("min_ms", Value::Float(php_round(times[0], 6))),
            entry("p50_ms", Value::Float(php_round(p50, 6))),
            entry("p95_ms", Value::Float(php_round(p95, 6))),
            entry("p99_ms", Value::Float(php_round(p99, 6))),
            entry("max_ms", Value::Float(php_round(times[count - 1], 6))),
            entry("mean_ms", Value::Float(php_round(mean, 6))),
            entry("stddev_ms", Value::Float(php_round(variance.sqrt(), 6))),
            entry("iqr_ms", Value::Float(php_round(iqr, 6))),
            entry("ops_per_sec", Value::Float(php_round(ops_per_sec, 2))),
        ]))
    }

    fn is_production_target(target: &[u8]) -> bool {
        let target = php_trim(target).to_ascii_lowercase();
        let stripped = target
            .split(|byte| *byte == b':')
            .next()
            .unwrap_or_default();
        let contains = |needle: &[u8]| target.windows(needle.len()).any(|window| window == needle);
        stripped == b"192.168.1.110" || contains(b"192.168.1.110") || contains(b"m5stephen")
    }

    /// `/\s+/u` collapsed to one space, `trim()`, then `mb_strtolower()`; strings containing a
    /// capital sigma (context-dependent lowercasing) are declined.
    pub fn normalize_identity(value: &[u8]) -> Result<Vec<u8>, ()> {
        let value = std::str::from_utf8(value).map_err(|_| ())?;
        let mut collapsed = String::with_capacity(value.len());
        let mut in_space = false;
        for ch in value.chars() {
            if ch == '\u{3a3}' {
                return Err(());
            }
            if is_pcre_unicode_space(ch) {
                if !in_space {
                    collapsed.push(' ');
                    in_space = true;
                }
            } else {
                collapsed.push(ch);
                in_space = false;
            }
        }
        let trimmed = std::str::from_utf8(php_trim(collapsed.as_bytes())).map_err(|_| ())?;
        Ok(trimmed.to_lowercase().into_bytes())
    }

    fn compute_binary_cbp_hash(
        file_number: i64,
        subject: &[u8],
        from_name: &[u8],
    ) -> Result<Vec<u8>, ()> {
        let digest = if file_number > 0 {
            crate::md5_raw_slice(format!("file:{file_number}").as_bytes())
        } else {
            let mut input = b"subject:".to_vec();
            input.extend_from_slice(&normalize_identity(subject)?);
            input.extend_from_slice(b"\0poster:");
            input.extend_from_slice(&normalize_identity(from_name)?);
            crate::md5_raw_slice(&input)
        };
        const HEX: &[u8; 16] = b"0123456789abcdef";
        Ok(digest
            .iter()
            .flat_map(|byte| [HEX[usize::from(byte >> 4)], HEX[usize::from(byte & 0x0f)]])
            .collect())
    }

    /// `is_numeric($max) && $max > 0 ? (int) $max : null` for the shapes reproduced exactly.
    fn positive_numeric_int(value: &Value) -> Result<Option<i64>, ()> {
        match value {
            Value::Int(number) => Ok((*number > 0).then_some(*number)),
            Value::Float(number) => {
                if *number > 0.0 {
                    php_float_to_int(*number).map(Some)
                } else {
                    Ok(None)
                }
            }
            Value::Str(text) => {
                if !text.iter().any(u8::is_ascii_digit) {
                    return Ok(None);
                }
                let is_space =
                    |byte: &u8| matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c);
                let start = text.iter().position(|byte| !is_space(byte)).ok_or(())?;
                let end = text.iter().rposition(|byte| !is_space(byte)).ok_or(())? + 1;
                let body = &text[start..end];
                let (negative, digits) = match body.first() {
                    Some(b'-') => (true, &body[1..]),
                    Some(b'+') => (false, &body[1..]),
                    _ => (false, body),
                };
                if digits.is_empty() || digits.len() > 18 || !digits.iter().all(u8::is_ascii_digit)
                {
                    return Err(());
                }
                let magnitude = digits
                    .iter()
                    .fold(0i64, |acc, digit| acc * 10 + i64::from(digit - b'0'));
                Ok((!negative && magnitude > 0).then_some(magnitude))
            }
            Value::Null | Value::Bool(_) | Value::List(_) | Value::Map(_) => Ok(None),
        }
    }

    /// `UpdateBinaries::formatElapsedTime()`; fractional minute values (a PHP deprecation) are declined.
    fn format_elapsed_time(seconds: f64) -> Result<String, ()> {
        if !seconds.is_finite() {
            return Err(());
        }
        if seconds < 1.0 {
            return Ok(format!("{}ms", php_float_to_int(seconds * 1000.0)?));
        }
        if seconds < 60.0 {
            return Ok(format!("{seconds:.1}s"));
        }
        if seconds.fract() != 0.0 || seconds >= 9_007_199_254_740_992.0 {
            return Err(());
        }
        let minutes = (seconds / 60.0).floor();
        let remaining_seconds = php_float_to_int(seconds)? % 60;
        if minutes < 60.0 {
            return Ok(format!(
                "{}m {}s",
                php_float_to_int(minutes)?,
                remaining_seconds
            ));
        }
        let hours = (minutes / 60.0).floor();
        Ok(format!(
            "{}h {}m",
            php_float_to_int(hours)?,
            php_float_to_int(minutes)? % 60
        ))
    }

    fn project_benchmark_table_row(method: &[u8], result: &Value) -> Result<Value, ()> {
        let items = int(field(result, "items")?)?;
        let stats = field(result, "stats")?;
        let verified = boolean(field(result, "accuracy_verified")?)?;
        let mut row = vec![Value::Str(method.to_vec()), Value::Int(items)];
        row.push(Value::Str(php_number_format(
            float(field(stats, "ops_per_sec")?)?,
            1,
        )?));
        for key in ["min_ms", "p50_ms", "p95_ms", "p99_ms", "max_ms"] {
            row.push(Value::Str(php_number_format(
                float(field(stats, key)?)?,
                4,
            )?));
        }
        row.push(Value::Str(if verified {
            b"PASS".to_vec()
        } else {
            b"FAIL".to_vec()
        }));
        Ok(Value::List(row))
    }

    pub fn evaluate(operation: &[u8], args: &[Value]) -> Result<Value, ()> {
        Ok(match (operation, args) {
            (b"benchmark_rename.compute_statistics", [durations, items_per_sample]) => {
                compute_statistics(durations, items_per_sample)?
            }
            (
                b"benchmark_rename.is_blocked_production_context",
                [app_env, db_host, db_database],
            ) => {
                let env = php_trim(string(app_env)?).to_ascii_lowercase();
                Value::Bool(
                    env == b"production"
                        || env == b"prod"
                        || is_production_target(string(db_host)?)
                        || is_production_target(string(db_database)?),
                )
            }
            (b"benchmark_rename.project_table_row", [method, result]) => {
                project_benchmark_table_row(string(method)?, result)?
            }
            (b"part_repair.format_group_not_found_message", [group]) => {
                text(&[b"Group not found: ", string(group)?])
            }
            (b"part_repair.format_nntp_error_message", [message]) => {
                text(&[b"Unable to connect to usenet. Error: ", string(message)?])
            }
            (b"update_binaries.resolve_max_headers", [max, configured]) => {
                let configured = int(configured)?;
                Value::Int(match positive_numeric_int(max)? {
                    Some(max) => max,
                    None if configured != 0 => configured,
                    None => 1_000_000,
                })
            }
            (b"update_binaries.format_elapsed_time", [seconds]) => {
                Value::Str(format_elapsed_time(float(seconds)?)?.into_bytes())
            }
            (b"update_binaries.format_section_header_lines", [title]) => {
                let title = string(title)?;
                Value::List(vec![
                    Value::Str(title.to_ascii_uppercase()),
                    Value::Str(vec![b'-'; title.len()]),
                ])
            }
            (b"optimize_cbp.normalize_identity", [value]) => {
                Value::Str(normalize_identity(string(value)?)?)
            }
            (b"optimize_cbp.compute_binary_cbp_hash", [file_number, subject, from_name]) => {
                Value::Str(compute_binary_cbp_hash(
                    int(file_number)?,
                    string(subject)?,
                    string(from_name)?,
                )?)
            }
            (b"optimize_cbp.clamp_batch_size", [batch]) => Value::Int(int(batch)?.clamp(1, 5000)),
            (b"reset_truncate.build_group_reset_payload", []) => Value::Map(vec![
                entry("first_record", Value::Int(0)),
                entry("first_record_postdate", Value::Null),
                entry("last_record", Value::Int(0)),
                entry("last_record_postdate", Value::Null),
                entry("last_updated", Value::Null),
            ]),
            (b"reset_truncate.tables_to_truncate", []) => Value::List(
                ["parts", "missed_parts", "binaries", "collections"]
                    .iter()
                    .map(|table| Value::Str(table.as_bytes().to_vec()))
                    .collect(),
            ),
            (b"reset_truncate.format_truncated_success_message", [deleted]) => text(&[
                int(deleted)?.to_string().as_bytes(),
                b" releases had no nzb, deleted.",
            ]),
            _ => return Err(()),
        })
    }

    /// JSON encoding with shortest round-trip floats, which PHP's `json_decode` reads back exactly.
    pub fn encode(out: &mut Vec<u8>, value: &Value) -> Result<(), ()> {
        match value {
            Value::Float(number) => {
                if !number.is_finite() {
                    return Err(());
                }
                out.extend_from_slice(format!("{number:?}").as_bytes());
            }
            Value::List(items) => {
                out.push(b'[');
                for (index, item) in items.iter().enumerate() {
                    if index > 0 {
                        out.push(b',');
                    }
                    encode(out, item)?;
                }
                out.push(b']');
            }
            Value::Map(entries) => {
                out.push(b'{');
                for (index, (key, item)) in entries.iter().enumerate() {
                    if index > 0 {
                        out.push(b',');
                    }
                    encode(out, &Value::Str(key.clone()))?;
                    out.push(b':');
                    encode(out, item)?;
                }
                out.push(b'}');
            }
            scalar => super::wave30_nzb_release_cleanup_commands::encode(out, scalar)?,
        }
        Ok(())
    }

    /// Evaluates one JSON request and returns its JSON response bytes.
    pub fn output(input: &[u8]) -> Result<Vec<u8>, ()> {
        let Value::List(items) = parse(input)? else {
            return Err(());
        };
        let Some((Value::Str(operation), args)) = items.split_first() else {
            return Err(());
        };
        let result = evaluate(operation, args)?;
        let mut out = Vec::with_capacity(64);
        out.push(b'[');
        encode(&mut out, &result)?;
        out.push(b']');
        Ok(out)
    }
}

/// Evaluate one JSON-dispatched BenchmarkRename, PartRepair, UpdateBinaries, OptimizeCbpStorage or
/// NntmuxResetTruncate helper request without partially writing output.
/// Returns bytes written, -1 on invalid or declined input/panic, or the negated required size (always <= -3).
///
/// # Safety
/// Both regions must be non-null and allocated for their stated extents, with
/// lengths fitting isize and no address overflow. Only byte alignment is needed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w31_benchmark_binaries_cbp_reset_commands(
    input: *const u8,
    len: usize,
    out: *mut u8,
    out_cap: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !cleaning_ffi_region_valid(input, len) || !cleaning_ffi_region_valid(out, out_cap) {
            return -1;
        }
        let output = {
            let input = unsafe { std::slice::from_raw_parts(input, len) };
            match wave31_benchmark_binaries_cbp_reset_commands::output(input) {
                Ok(output) => output,
                Err(()) => return -1,
            }
        };
        let Ok(needed) = i32::try_from(output.len()) else {
            return -1;
        };
        if output.len() > out_cap {
            return -needed;
        }
        unsafe { std::ptr::copy_nonoverlapping(output.as_ptr(), out, output.len()) };
        needed
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave31_benchmark_binaries_cbp_reset_commands_tests {
    use super::nntmux_w31_benchmark_binaries_cbp_reset_commands as ffi;
    use super::wave31_benchmark_binaries_cbp_reset_commands::{
        normalize_identity, output, php_number_format, php_round,
    };

    fn run(request: &str) -> Option<String> {
        output(request.as_bytes())
            .ok()
            .map(|bytes| String::from_utf8(bytes).unwrap())
    }

    fn ok(request: &str, expected: &str) {
        assert_eq!(run(request).as_deref(), Some(expected), "request {request}");
    }

    fn declined(request: &str) {
        assert_eq!(run(request), None, "request {request}");
    }

    #[test]
    fn php_round_and_number_format_match_php() {
        // Expected values from PHP 8.5 `round()` / `number_format()`.
        assert_eq!(php_round(1.005, 2), 1.01);
        assert_eq!(php_round(0.285, 2), 0.29);
        assert_eq!(php_round(466_548_724_782.908_6, 4), 466_548_724_782.908_7);
        assert_eq!(php_round(-5_012_712_164.322_246, 6), -5_012_712_164.322_247);
        assert!(php_round(-0.000_000_1, 6).is_sign_negative());
        assert_eq!(php_round(0.0, 6), 0.0);
        assert_eq!(php_number_format(1.005, 2).unwrap(), b"1.01");
        assert_eq!(php_number_format(0.25, 1).unwrap(), b"0.3");
        assert_eq!(
            php_number_format(1_234_567.891_25, 4).unwrap(),
            b"1,234,567.8913"
        );
        assert_eq!(php_number_format(-0.000_01, 4).unwrap(), b"0.0000");
        assert_eq!(php_number_format(-1234.5, 1).unwrap(), b"-1,234.5");
        assert!(php_number_format(1e11, 4).is_err());
    }

    #[test]
    fn benchmark_rename_helpers_match_php() {
        ok(
            r#"["benchmark_rename.compute_statistics",[],5]"#,
            r#"[{"count":0,"min_ms":0.0,"p50_ms":0.0,"p95_ms":0.0,"p99_ms":0.0,"max_ms":0.0,"mean_ms":0.0,"stddev_ms":0.0,"iqr_ms":0.0,"ops_per_sec":0.0}]"#,
        );
        ok(
            r#"["benchmark_rename.compute_statistics",[3000000,1000000,2000000,4000000],10]"#,
            r#"[{"count":4,"min_ms":1.0,"p50_ms":2.5,"p95_ms":4.0,"p99_ms":4.0,"max_ms":4.0,"mean_ms":2.5,"stddev_ms":1.290994,"iqr_ms":2.0,"ops_per_sec":4000.0}]"#,
        );
        ok(
            r#"["benchmark_rename.compute_statistics",[-5],1]"#,
            r#"[{"count":1,"min_ms":-5e-6,"p50_ms":-5e-6,"p95_ms":-5e-6,"p99_ms":-5e-6,"max_ms":-5e-6,"mean_ms":-5e-6,"stddev_ms":0.0,"iqr_ms":0.0,"ops_per_sec":0.0}]"#,
        );
        declined(r#"["benchmark_rename.compute_statistics",[1.5],1]"#);
        declined(r#"["benchmark_rename.compute_statistics",[9223372036854775807,1],1]"#);
        declined(r#"["benchmark_rename.compute_statistics",[1],9223372036854775807,1]"#);
        declined(r#"["benchmark_rename.compute_statistics",{"a":1},1]"#);

        ok(
            r#"["benchmark_rename.is_blocked_production_context"," PROD\n","",""]"#,
            "[true]",
        );
        ok(
            r#"["benchmark_rename.is_blocked_production_context","local","192.168.1.110:3306",""]"#,
            "[true]",
        );
        ok(
            r#"["benchmark_rename.is_blocked_production_context","","","mysql://M5Stephen/db"]"#,
            "[true]",
        );
        ok(
            r#"["benchmark_rename.is_blocked_production_context","production-like","192.168.1.111","nntmux"]"#,
            "[false]",
        );

        ok(
            r#"["benchmark_rename.project_table_row","Clean",{"items":20,"stats":{"ops_per_sec":12345.67,"min_ms":0.00005,"p50_ms":1.5,"p95_ms":2.0,"p99_ms":3.25,"max_ms":1234.56789},"accuracy_verified":false}]"#,
            r#"[["Clean",20,"12,345.7","0.0001","1.5000","2.0000","3.2500","1,234.5679","FAIL"]]"#,
        );
        declined(
            r#"["benchmark_rename.project_table_row","Clean",{"items":20,"stats":{"ops_per_sec":1,"min_ms":0.0,"p50_ms":0.0,"p95_ms":0.0,"p99_ms":0.0,"max_ms":0.0},"accuracy_verified":true}]"#,
        );
        declined(r#"["benchmark_rename.project_table_row","Clean",{"items":20}]"#);
    }

    #[test]
    fn part_repair_and_update_binaries_helpers_match_php() {
        ok(
            r#"["part_repair.format_group_not_found_message","alt.bin\u0000é"]"#,
            r#"["Group not found: alt.bin\u0000é"]"#,
        );
        ok(
            r#"["part_repair.format_nntp_error_message",""]"#,
            r#"["Unable to connect to usenet. Error: "]"#,
        );

        for (max, expected) in [
            ("5", 5),
            ("\" 12 \"", 12),
            ("\"+7\"", 7),
            ("\"-7\"", 77),
            ("\"0\"", 77),
            ("\"abc\"", 77),
            ("\"\"", 77),
            ("null", 77),
            ("true", 77),
            ("[5]", 77),
            ("2.9", 2),
            ("-2.9", 77),
        ] {
            ok(
                &format!(r#"["update_binaries.resolve_max_headers",{max},77]"#),
                &format!("[{expected}]"),
            );
        }
        ok(
            r#"["update_binaries.resolve_max_headers",null,0]"#,
            "[1000000]",
        );
        declined(r#"["update_binaries.resolve_max_headers","1e3",0]"#);
        declined(r#"["update_binaries.resolve_max_headers","12abc",0]"#);
        declined(r#"["update_binaries.resolve_max_headers",1e300,0]"#);

        for (seconds, expected) in [
            ("0.0", "0ms"),
            ("0.9999", "999ms"),
            ("-2.5", "-2500ms"),
            ("1.0", "1.0s"),
            ("1.25", "1.2s"),
            ("59.96", "60.0s"),
            ("60.0", "1m 0s"),
            ("3599.0", "59m 59s"),
            ("3600.0", "1h 0m"),
            ("90061.0", "25h 1m"),
        ] {
            ok(
                &format!(r#"["update_binaries.format_elapsed_time",{seconds}]"#),
                &format!(r#"["{expected}"]"#),
            );
        }
        declined(r#"["update_binaries.format_elapsed_time",60.5]"#);
        declined(r#"["update_binaries.format_elapsed_time",-1e300]"#);
        declined(r#"["update_binaries.format_elapsed_time",60]"#);

        ok(
            r#"["update_binaries.format_section_header_lines","Updating é Groups"]"#,
            r#"[["UPDATING é GROUPS","------------------"]]"#,
        );
    }

    #[test]
    fn optimize_cbp_and_reset_helpers_match_php() {
        assert_eq!(
            normalize_identity("\0 A\u{3000}\u{180e}B\t\u{85}İ \0".as_bytes()).unwrap(),
            "a b i\u{307}".as_bytes()
        );
        assert!(normalize_identity("ΑΣ".as_bytes()).is_err());
        ok(
            r#"["optimize_cbp.normalize_identity","  Foo  BAR\n"]"#,
            r#"["foo bar"]"#,
        );
        ok(
            r#"["optimize_cbp.compute_binary_cbp_hash",12,"x","y"]"#,
            r#"["667617304c9dbe586bbaccb2ed72821c"]"#,
        );
        ok(
            r#"["optimize_cbp.compute_binary_cbp_hash",0," My  Subject ","Poster"]"#,
            r#"["29dc978a268189c82c0eab0ad62a9893"]"#,
        );
        ok(
            r#"["optimize_cbp.compute_binary_cbp_hash",3,"Σ",""]"#,
            r#"["e45f45ea93a0b75468fe337f69cb526a"]"#,
        );
        declined(r#"["optimize_cbp.compute_binary_cbp_hash",0,"Σ",""]"#);
        for (batch, expected) in [
            ("-5", "1"),
            ("0", "1"),
            ("1", "1"),
            ("500", "500"),
            ("5001", "5000"),
        ] {
            ok(
                &format!(r#"["optimize_cbp.clamp_batch_size",{batch}]"#),
                &format!("[{expected}]"),
            );
        }

        ok(
            r#"["reset_truncate.build_group_reset_payload"]"#,
            r#"[{"first_record":0,"first_record_postdate":null,"last_record":0,"last_record_postdate":null,"last_updated":null}]"#,
        );
        ok(
            r#"["reset_truncate.tables_to_truncate"]"#,
            r#"[["parts","missed_parts","binaries","collections"]]"#,
        );
        ok(
            r#"["reset_truncate.format_truncated_success_message",-3]"#,
            r#"["-3 releases had no nzb, deleted."]"#,
        );
        declined(r#"["reset_truncate.tables_to_truncate",1]"#);
    }

    #[test]
    fn malformed_requests_are_declined() {
        for request in [
            "",
            "[]",
            "{}",
            "[1]",
            r#"["unknown"]"#,
            r#"["optimize_cbp.clamp_batch_size",5"#,
            r#"["optimize_cbp.clamp_batch_size",5] x"#,
            r#"["optimize_cbp.clamp_batch_size",5.0]"#,
            r#"["optimize_cbp.clamp_batch_size",9223372036854775808]"#,
            r#"["optimize_cbp.normalize_identity","\ud800"]"#,
            r#"["part_repair.format_group_not_found_message",1]"#,
        ] {
            declined(request);
        }
        assert!(output(b"[\"optimize_cbp.normalize_identity\",\"\xff\"]").is_err());
    }

    #[test]
    fn ffi_rejects_bad_regions_and_leaves_short_output_untouched() {
        let input = br#"["reset_truncate.format_truncated_success_message",12]"#;
        let expected = br#"["12 releases had no nzb, deleted."]"#;
        let mut target = vec![0xab_u8; expected.len() + 8];
        unsafe {
            for capacity in [0, 1, expected.len() - 1] {
                assert_eq!(
                    ffi(input.as_ptr(), input.len(), target.as_mut_ptr(), capacity),
                    -(expected.len() as i32)
                );
                assert!(target.iter().all(|byte| *byte == 0xab));
            }
            assert_eq!(
                ffi(std::ptr::null(), 0, target.as_mut_ptr(), target.len()),
                -1
            );
            assert_eq!(
                ffi(input.as_ptr(), input.len(), std::ptr::null_mut(), 0),
                -1
            );
            assert_eq!(
                ffi(
                    input.as_ptr(),
                    usize::MAX,
                    target.as_mut_ptr(),
                    target.len()
                ),
                -1
            );
            let bad = br#"["unknown"]"#;
            assert_eq!(
                ffi(bad.as_ptr(), bad.len(), target.as_mut_ptr(), target.len()),
                -1
            );
            assert!(target.iter().all(|byte| *byte == 0xab));
            assert_eq!(
                ffi(
                    input.as_ptr(),
                    input.len(),
                    target.as_mut_ptr().add(1),
                    expected.len()
                ),
                expected.len() as i32
            );
            assert_eq!(&target[1..1 + expected.len()], expected);
            assert_eq!(target[0], 0xab);
        }
    }
}
