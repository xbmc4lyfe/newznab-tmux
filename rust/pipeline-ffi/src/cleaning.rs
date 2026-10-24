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
