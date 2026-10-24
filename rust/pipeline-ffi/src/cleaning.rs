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

fn collections_special_music(subject: &[u8]) -> bool {
    let subject = subject.strip_suffix(b"\n").unwrap_or(subject);
    let mut pos = 0;
    while subject.get(pos).is_some_and(|&b| is_pcre_word(b)) {
        pos += 1;
    }
    if pos < 10 || subject.get(pos) != Some(&b'-') {
        return false;
    }
    pos += 1;
    let album = pos;
    while subject.get(pos).is_some_and(u8::is_ascii_alphanumeric) {
        pos += 1;
    }
    if pos == album || !subject[pos..].starts_with(b" \"") {
        return false;
    }
    pos += 2;
    if !subject.get(pos).is_some_and(u8::is_ascii_digit)
        || !subject.get(pos + 1).is_some_and(u8::is_ascii_digit)
        || subject.get(pos + 2) != Some(&b'-')
    {
        return false;
    }
    pos += 3;
    subject.len() >= pos + 7
        && subject.ends_with(b"\" yEnc")
        && !subject[pos..subject.len() - 6].contains(&b'\n')
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
    if ![
        b".flac".as_slice(),
        b".lossless",
        b".mp3",
        b".music",
        b".sounds",
    ]
    .iter()
    .any(|needle| group.windows(needle.len()).any(|v| v == *needle))
    {
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
