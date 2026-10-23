use std::slice;

use crate::OutCursor;

unsafe extern "C" {
    fn memchr(s: *const u8, c: i32, n: usize) -> *const u8;
}

#[inline]
fn is_pcre_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0C)
}

#[inline]
fn is_php_trim_byte(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x00)
}

#[inline]
fn is_word_char(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_'
}

#[inline]
fn trim_php(mut s: &[u8]) -> &[u8] {
    while let Some(&first) = s.first() {
        if is_php_trim_byte(first) {
            s = &s[1..];
        } else {
            break;
        }
    }
    while let Some(&last) = s.last() {
        if is_php_trim_byte(last) {
            s = &s[..s.len() - 1];
        } else {
            break;
        }
    }
    s
}

#[inline]
fn find_ascii_ci(haystack: &[u8], needle_lower: &[u8]) -> Option<usize> {
    let nlen = needle_lower.len();
    if nlen == 0 {
        return Some(0);
    }
    if haystack.len() < nlen {
        return None;
    }
    let end = haystack.len() - nlen + 1;
    let first = needle_lower[0];
    let mut i = 0;
    while i < end {
        if haystack[i].to_ascii_lowercase() == first {
            let mut matched = true;
            for j in 1..nlen {
                if haystack[i + j].to_ascii_lowercase() != needle_lower[j] {
                    matched = false;
                    break;
                }
            }
            if matched {
                return Some(i);
            }
        }
        i += 1;
    }
    None
}

// ---------------------------------------------------------------------------
// 1. VideoContainer::transportStreamOffset & VideoContainer::detect
// ---------------------------------------------------------------------------

pub(crate) fn transport_stream_offset(data: &[u8]) -> Option<usize> {
    for stride in [188usize, 192usize] {
        let min_len = stride * 4;
        if data.len() <= min_len {
            continue;
        }
        let limit = (data.len() - min_len).min(stride);
        let search_slice = &data[..limit];
        let mut cur = 0;
        while cur < limit {
            let ptr = unsafe { memchr(search_slice[cur..].as_ptr(), 0x47, limit - cur) };
            if ptr.is_null() {
                break;
            }
            let offset = cur + (ptr as usize - search_slice[cur..].as_ptr() as usize);
            if data[offset + stride] == 0x47
                && data[offset + stride * 2] == 0x47
                && data[offset + stride * 3] == 0x47
            {
                return Some(offset);
            }
            cur = offset + 1;
        }
    }
    None
}

/// Container code:
/// 0 = null, 1 = Matroska, 2 = Avi, 3 = MpegProgram, 4 = MpegTransport,
/// 5 = Mp4, 6 = Asf, 7 = Ogg
pub(crate) fn detect_video_container(head: &[u8]) -> i32 {
    if head.len() < 12 {
        return 0;
    }
    if head.starts_with(b"\x1A\x45\xDF\xA3") {
        return 1;
    }
    if head.starts_with(b"RIFF") && &head[8..12] == b"AVI " {
        return 2;
    }
    if head.starts_with(b"\x00\x00\x01\xBA") || head.starts_with(b"\x00\x00\x01\xB3") {
        return 3;
    }
    if &head[4..8] == b"ftyp" || &head[4..8] == b"moov" {
        return 5;
    }
    if head.starts_with(b"\x30\x26\xB2\x75\x8E\x66\xCF\x11") {
        return 6;
    }
    if head.starts_with(b"OggS") {
        return 7;
    }
    if transport_stream_offset(head).is_some() {
        return 4;
    }
    0
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_video_transport_stream_offset(
    ptr: *const u8,
    len: usize,
) -> isize {
    if ptr.is_null() && len > 0 {
        return -1;
    }
    let data = if len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(ptr, len) }
    };
    match transport_stream_offset(data) {
        Some(off) => off as isize,
        None => -1,
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_video_container_detect(ptr: *const u8, len: usize) -> i32 {
    if ptr.is_null() && len > 0 {
        return 0;
    }
    let head = if len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(ptr, len) }
    };
    detect_video_container(head)
}

// ---------------------------------------------------------------------------
// 2. ImageCandidateRanker::fileName & ImageCandidateRanker::rank
// ---------------------------------------------------------------------------

pub(crate) fn image_candidate_file_name(name: &[u8]) -> Option<&[u8]> {
    let mut selected = name;
    if let Some(q1) = name.iter().position(|&b| b == b'"') {
        if let Some(q2_rel) = name[q1 + 1..].iter().position(|&b| b == b'"') {
            if q2_rel > 0 {
                selected = &name[q1 + 1..q1 + 1 + q2_rel];
            }
        }
    }
    let trimmed = trim_php(selected);
    if trimmed.is_empty() {
        return Some(b"");
    }
    // Decline to PHP if non-ASCII or ends with slash/backslash so locale-aware basename is exact.
    if !trimmed.is_ascii() || matches!(trimmed.last(), Some(b'/' | b'\\')) {
        return None;
    }
    let last_sep = trimmed.iter().rposition(|&b| b == b'/' || b == b'\\');
    match last_sep {
        Some(idx) => Some(&trimmed[idx + 1..]),
        None => Some(trimmed),
    }
}

#[inline]
fn is_content_word(lower: &[u8]) -> bool {
    matches!(
        lower,
        b"screen"
            | b"screens"
            | b"screenshot"
            | b"screenshots"
            | b"screencap"
            | b"screencaps"
            | b"caps"
            | b"contactsheet"
            | b"contactsheets"
            | b"sheet"
            | b"thumb"
            | b"thumbs"
            | b"thumbnail"
            | b"thumbnails"
            | b"preview"
            | b"previews"
            | b"sample"
            | b"samples"
    )
}

#[inline]
fn is_least_word(lower: &[u8]) -> bool {
    matches!(
        lower,
        b"back"
            | b"rear"
            | b"cd"
            | b"dvd"
            | b"disc"
            | b"disk"
            | b"label"
            | b"inlay"
            | b"insert"
            | b"proof"
            | b"logo"
            | b"banner"
            | b"avatar"
            | b"sig"
            | b"signature"
            | b"spine"
    )
}

#[inline]
fn is_cover_word(lower: &[u8]) -> bool {
    matches!(
        lower,
        b"cover" | b"front" | b"poster" | b"folder" | b"box" | b"keyart"
    )
}

pub(crate) fn image_candidate_rank(name: &[u8]) -> Option<i32> {
    let base = image_candidate_file_name(name)?;
    let mut has_content = false;
    let mut has_least = false;
    let mut has_cover = false;

    let mut i = 0;
    let n = base.len();
    let mut lower_buf = [0u8; 32];
    while i < n {
        if !base[i].is_ascii_alphabetic() {
            i += 1;
            continue;
        }
        let start = i;
        while i < n && base[i].is_ascii_alphabetic() {
            i += 1;
        }
        let wlen = i - start;
        if wlen <= 32 {
            for k in 0..wlen {
                lower_buf[k] = base[start + k].to_ascii_lowercase();
            }
            let word = &lower_buf[..wlen];
            if is_content_word(word) {
                has_content = true;
                break;
            }
            if word == b"contact" && i < n && matches!(base[i], b' ' | b'.' | b'_' | b'-') {
                let next_start = i + 1;
                let mut next_end = next_start;
                while next_end < n && base[next_end].is_ascii_alphabetic() {
                    next_end += 1;
                }
                let next_word = &base[next_start..next_end];
                if next_word.eq_ignore_ascii_case(b"sheet")
                    || next_word.eq_ignore_ascii_case(b"sheets")
                {
                    has_content = true;
                    break;
                }
            }
            if !has_least && is_least_word(word) {
                has_least = true;
            }
            if !has_cover && is_cover_word(word) {
                has_cover = true;
            }
        }
    }

    if has_content {
        Some(0)
    } else if has_least {
        Some(3)
    } else if has_cover {
        Some(1)
    } else {
        Some(2)
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_image_candidate_rank(ptr: *const u8, len: usize) -> i32 {
    if ptr.is_null() && len > 0 {
        return -1;
    }
    let name = if len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(ptr, len) }
    };
    match image_candidate_rank(name) {
        Some(rank) => rank,
        None => -2,
    }
}

// ---------------------------------------------------------------------------
// 3. ArchiveCandidate::fileName & ArchiveCandidate::volumeSetKey
// ---------------------------------------------------------------------------

/// Matches `[\[(]\s*\d+\s*(?:\/|of)\s*\d+\s*[\])]` (case-insensitive) at the start of `s`.
fn match_post_counter(s: &[u8]) -> Option<usize> {
    if s.is_empty() || !matches!(s[0], b'[' | b'(') {
        return None;
    }
    let mut i = 1;
    while i < s.len() && is_pcre_space(s[i]) {
        i += 1;
    }
    let d1_start = i;
    while i < s.len() && s[i].is_ascii_digit() {
        i += 1;
    }
    if i == d1_start {
        return None;
    }
    while i < s.len() && is_pcre_space(s[i]) {
        i += 1;
    }
    if i < s.len() && s[i] == b'/' {
        i += 1;
    } else if i + 2 <= s.len() && s[i..i + 2].eq_ignore_ascii_case(b"of") {
        i += 2;
    } else {
        return None;
    }
    while i < s.len() && is_pcre_space(s[i]) {
        i += 1;
    }
    let d2_start = i;
    while i < s.len() && s[i].is_ascii_digit() {
        i += 1;
    }
    if i == d2_start {
        return None;
    }
    while i < s.len() && is_pcre_space(s[i]) {
        i += 1;
    }
    if i < s.len() && matches!(s[i], b']' | b')') {
        Some(i + 1)
    } else {
        None
    }
}

/// Checks if `s` matches `/^(?:\s*(?:-|COUNTER))+\s*$/i`.
fn matches_trailing_counter_or_dash_seq(s: &[u8]) -> bool {
    let mut i = 0;
    let mut count = 0usize;
    while i < s.len() {
        while i < s.len() && is_pcre_space(s[i]) {
            i += 1;
        }
        if i == s.len() {
            break;
        }
        if s[i] == b'-' {
            i += 1;
            count += 1;
        } else if let Some(clen) = match_post_counter(&s[i..]) {
            i += clen;
            count += 1;
        } else {
            return false;
        }
    }
    count > 0
}

fn first_quoted_non_crlf(subject: &[u8]) -> Option<&[u8]> {
    let mut i = 0;
    while i < subject.len() {
        if subject[i] == b'"' {
            let start = i + 1;
            let mut j = start;
            while j < subject.len() && !matches!(subject[j], b'"' | b'\r' | b'\n') {
                j += 1;
            }
            if j < subject.len() && subject[j] == b'"' && j > start {
                return Some(&subject[start..j]);
            }
        }
        i += 1;
    }
    None
}

pub(crate) fn archive_candidate_file_name_into(subject: &[u8], out: &mut OutCursor<'_>) {
    if let Some(quoted) = first_quoted_non_crlf(subject) {
        let trimmed = trim_php(quoted);
        for &b in trimmed {
            out.push(b.to_ascii_lowercase());
        }
        return;
    }

    // 1. preg_replace('/(?:^|\s)yEnc\b.*$/is', '', $subject)
    let mut name = subject;
    let mut i = 0;
    while i + 4 <= name.len() {
        if (i == 0 || is_pcre_space(name[i - 1]))
            && name[i..i + 4].eq_ignore_ascii_case(b"yenc")
            && (i + 4 == name.len() || !is_word_char(name[i + 4]))
        {
            let cut = if i == 0 { 0 } else { i - 1 };
            name = &name[..cut];
            break;
        }
        i += 1;
    }

    // 2. preg_replace('/(?:\s*(?:-|'.$counter.'))+\s*$/i', '', $name)
    for pos in 0..name.len() {
        if (is_pcre_space(name[pos]) || matches!(name[pos], b'-' | b'[' | b'('))
            && matches_trailing_counter_or_dash_seq(&name[pos..])
        {
            name = &name[..pos];
            break;
        }
    }

    // 3. preg_replace('/^(?:\s*(?:-(?=\s)|'.$counter.'))+\s*/i', '', $name)
    let mut lead_pos = 0;
    let mut matched_lead = false;
    loop {
        let mut p = lead_pos;
        while p < name.len() && is_pcre_space(name[p]) {
            p += 1;
        }
        if p < name.len() {
            if name[p] == b'-' && p + 1 < name.len() && is_pcre_space(name[p + 1]) {
                lead_pos = p + 1;
                matched_lead = true;
                continue;
            }
            if let Some(clen) = match_post_counter(&name[p..]) {
                lead_pos = p + clen;
                matched_lead = true;
                continue;
            }
        }
        if matched_lead {
            lead_pos = p;
        }
        break;
    }
    name = &name[lead_pos..];

    // 4. preg_replace('/\s+'.$counter.'\s+-\s+/i', ' ', $name) + trim + lowercase
    let mut stack_buf = [0u8; 512];
    let mut vec_buf = Vec::new();
    let use_stack = name.len() <= 512;
    let mut rlen = 0usize;

    let mut cur = 0;
    while cur < name.len() {
        if is_pcre_space(name[cur]) {
            let mut s1_end = cur;
            while s1_end < name.len() && is_pcre_space(name[s1_end]) {
                s1_end += 1;
            }
            if let Some(clen) = match_post_counter(&name[s1_end..]) {
                let after_counter = s1_end + clen;
                let mut s2_end = after_counter;
                while s2_end < name.len() && is_pcre_space(name[s2_end]) {
                    s2_end += 1;
                }
                if s2_end > after_counter && s2_end < name.len() && name[s2_end] == b'-' {
                    let after_dash = s2_end + 1;
                    let mut s3_end = after_dash;
                    while s3_end < name.len() && is_pcre_space(name[s3_end]) {
                        s3_end += 1;
                    }
                    if s3_end > after_dash {
                        if use_stack {
                            stack_buf[rlen] = b' ';
                        } else {
                            vec_buf.push(b' ');
                        }
                        rlen += 1;
                        cur = s3_end;
                        continue;
                    }
                }
            }
        }
        let b = name[cur].to_ascii_lowercase();
        if use_stack {
            stack_buf[rlen] = b;
        } else {
            vec_buf.push(b);
        }
        rlen += 1;
        cur += 1;
    }

    let replaced = if use_stack {
        &stack_buf[..rlen]
    } else {
        &vec_buf[..]
    };
    let trimmed = trim_php(replaced);
    out.extend(trimmed);
}

pub(crate) fn archive_candidate_file_name_vec(subject: &[u8]) -> Vec<u8> {
    let mut buf = vec![0u8; subject.len().max(64)];
    let mut cur = OutCursor {
        buf: &mut buf,
        pos: 0,
    };
    archive_candidate_file_name_into(subject, &mut cur);
    let len = cur.pos;
    buf.truncate(len);
    buf
}

fn match_rar_family_suffix(name: &[u8]) -> Option<&[u8]> {
    // '/^(.+?)\.(?:part\d+\.rar|rar|r\d{2,3})$/'
    if name.contains(&b'\n') {
        return None;
    }
    for i in 1..name.len() {
        if name[i] != b'.' {
            continue;
        }
        let rest = &name[i + 1..];
        if rest == b"rar" {
            return Some(&name[..i]);
        }
        if rest.starts_with(b"part") && rest.ends_with(b".rar") && rest.len() > 8 {
            let digits = &rest[4..rest.len() - 4];
            if digits.iter().all(|b| b.is_ascii_digit()) {
                return Some(&name[..i]);
            }
        }
        if rest.starts_with(b"r")
            && (rest.len() == 3 || rest.len() == 4)
            && rest[1..].iter().all(|b| b.is_ascii_digit())
        {
            return Some(&name[..i]);
        }
    }
    None
}

fn match_7z_family_suffix(name: &[u8]) -> Option<&[u8]> {
    // '/^(.+?)\.7z(?:\.\d{3})?$/'
    if name.contains(&b'\n') {
        return None;
    }
    for i in 1..name.len() {
        if name[i] != b'.' {
            continue;
        }
        let rest = &name[i + 1..];
        if rest == b"7z" {
            return Some(&name[..i]);
        }
        if rest.len() == 6
            && rest.starts_with(b"7z.")
            && rest[3..].iter().all(|b| b.is_ascii_digit())
        {
            return Some(&name[..i]);
        }
    }
    None
}

fn match_zip_family_suffix(name: &[u8]) -> Option<&[u8]> {
    // '/^(.+?)\.(?:zipx?|zipr\d{2,3}|z\d{2,3})$/'
    if name.contains(&b'\n') {
        return None;
    }
    for i in 1..name.len() {
        if name[i] != b'.' {
            continue;
        }
        let rest = &name[i + 1..];
        if rest == b"zip" || rest == b"zipx" {
            return Some(&name[..i]);
        }
        if rest.starts_with(b"zipr")
            && (rest.len() == 6 || rest.len() == 7)
            && rest[4..].iter().all(|b| b.is_ascii_digit())
        {
            return Some(&name[..i]);
        }
        if rest.starts_with(b"z")
            && (rest.len() == 3 || rest.len() == 4)
            && rest[1..].iter().all(|b| b.is_ascii_digit())
        {
            return Some(&name[..i]);
        }
    }
    None
}

fn match_split_family_suffix(name: &[u8]) -> Option<&[u8]> {
    // '/^([a-f0-9]{32})\.[1-9]\d{1,2}$|^(.+?)\.\d{3}$/'
    if name.contains(&b'\n') {
        return None;
    }
    if (name.len() == 35 || name.len() == 36)
        && name[32] == b'.'
        && name[..32]
            .iter()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(b))
        && (b'1'..=b'9').contains(&name[33])
        && name[34..].iter().all(|b| b.is_ascii_digit())
    {
        return Some(&name[..32]);
    }
    if name.len() > 4
        && name[name.len() - 4] == b'.'
        && name[name.len() - 3..].iter().all(|b| b.is_ascii_digit())
    {
        return Some(&name[..name.len() - 4]);
    }
    None
}

pub(crate) fn archive_candidate_volume_set_key_into(name: &[u8], out: &mut OutCursor<'_>) {
    if let Some(stem) = match_rar_family_suffix(name) {
        out.extend(b"rar:");
        out.extend(stem);
        return;
    }
    if let Some(stem) = match_7z_family_suffix(name) {
        out.extend(b"7z:");
        out.extend(stem);
        return;
    }
    if let Some(stem) = match_zip_family_suffix(name) {
        out.extend(b"zip:");
        out.extend(stem);
        return;
    }
    if let Some(stem) = match_split_family_suffix(name) {
        out.extend(b"split:");
        out.extend(stem);
        return;
    }
    out.extend(b"file:");
    out.extend(name);
}

pub(crate) fn archive_candidate_volume_set_key_from_filename(name: &[u8]) -> Vec<u8> {
    let mut buf = vec![0u8; name.len() + 8];
    let mut cur = OutCursor {
        buf: &mut buf,
        pos: 0,
    };
    archive_candidate_volume_set_key_into(name, &mut cur);
    let len = cur.pos;
    buf.truncate(len);
    buf
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_archive_candidate_file_name(
    ptr: *const u8,
    len: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if (ptr.is_null() && len > 0) || (out_ptr.is_null() && out_cap > 0) {
        return -1;
    }
    let subject = if len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(ptr, len) }
    };
    let mut out = unsafe { OutCursor::new(out_ptr, out_cap) };
    archive_candidate_file_name_into(subject, &mut out);
    if out.pos <= out.buf.len() {
        out.pos as isize
    } else {
        -(out.pos.max(4) as isize)
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_archive_candidate_volume_set_key(
    ptr: *const u8,
    len: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if (ptr.is_null() && len > 0) || (out_ptr.is_null() && out_cap > 0) {
        return -1;
    }
    let subject = if len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(ptr, len) }
    };
    let mut out = unsafe { OutCursor::new(out_ptr, out_cap) };
    if subject.len() <= 512 {
        let mut stack_buf = [0u8; 512];
        let mut fname_cur = OutCursor {
            buf: &mut stack_buf,
            pos: 0,
        };
        archive_candidate_file_name_into(subject, &mut fname_cur);
        let flen = fname_cur.pos;
        archive_candidate_volume_set_key_into(&stack_buf[..flen], &mut out);
    } else {
        let fname = archive_candidate_file_name_vec(subject);
        archive_candidate_volume_set_key_into(&fname, &mut out);
    }
    if out.pos <= out.buf.len() {
        out.pos as isize
    } else {
        -(out.pos.max(4) as isize)
    }
}

// ---------------------------------------------------------------------------
// 4. SevenZipListingParser (`7z l -slt -ba` streaming & batch parser)
// ---------------------------------------------------------------------------

#[inline]
fn is_valid_7z_key(key: &[u8]) -> bool {
    if key.is_empty() || !key[0].is_ascii_alphabetic() {
        return false;
    }
    key[1..]
        .iter()
        .all(|&b| b.is_ascii_alphanumeric() || matches!(b, b' ' | b'(' | b')' | b'.' | b'_' | b'-'))
}

#[inline]
fn has_7z_path_control_chars(path: &[u8]) -> bool {
    let mut i = 0;
    while i < path.len() {
        let b = path[i];
        if b <= 0x1F || b == 0x7F {
            return true;
        }
        if b == 0xC2 && i + 1 < path.len() && (0x80..=0x9F).contains(&path[i + 1]) {
            return true;
        }
        i += 1;
    }
    false
}

#[inline]
fn parse_php_int(s: &[u8]) -> i64 {
    let mut i = 0;
    while i < s.len() && matches!(s[i], b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C) {
        i += 1;
    }
    let mut neg = false;
    if i < s.len() {
        if s[i] == b'-' {
            neg = true;
            i += 1;
        } else if s[i] == b'+' {
            i += 1;
        }
    }
    let mut acc: i64 = 0;
    while i < s.len() && s[i].is_ascii_digit() {
        let d = (s[i] - b'0') as i64;
        acc = if neg {
            acc.saturating_mul(10).saturating_sub(d)
        } else {
            acc.saturating_mul(10).saturating_add(d)
        };
        i += 1;
    }
    acc
}

fn push_i64_dec(out: &mut OutCursor<'_>, val: i64) {
    let mut buf = [0u8; 24];
    let mut idx = 24;
    let neg = val < 0;
    let mut n: u64 = if neg {
        val.wrapping_neg() as u64
    } else {
        val as u64
    };
    if n == 0 {
        out.push(b'0');
        return;
    }
    while n > 0 {
        idx -= 1;
        buf[idx] = b'0' + (n % 10) as u8;
        n /= 10;
    }
    if neg {
        idx -= 1;
        buf[idx] = b'-';
    }
    out.extend(&buf[idx..]);
}

fn push_u32_hex_lower(out: &mut OutCursor<'_>, mut val: u32) {
    if val == 0 {
        out.push(b'0');
        return;
    }
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let mut buf = [0u8; 8];
    let mut idx = 8;
    while val > 0 {
        idx -= 1;
        buf[idx] = HEX[(val & 0xF) as usize];
        val >>= 4;
    }
    out.extend(&buf[idx..]);
}

struct SevenZipRecordState<'a> {
    seen_keys: Vec<&'a [u8]>,
    path: Option<&'a [u8]>,
    size: Option<&'a [u8]>,
    folder: Option<&'a [u8]>,
    attributes: Option<&'a [u8]>,
    modified: Option<&'a [u8]>,
    encrypted: Option<&'a [u8]>,
    method: Option<&'a [u8]>,
    crc: Option<&'a [u8]>,
    malformed: bool,
}

impl<'a> SevenZipRecordState<'a> {
    fn new() -> Self {
        Self {
            seen_keys: Vec::with_capacity(16),
            path: None,
            size: None,
            folder: None,
            attributes: None,
            modified: None,
            encrypted: None,
            method: None,
            crc: None,
            malformed: false,
        }
    }

    fn reset(&mut self) {
        self.seen_keys.clear();
        self.path = None;
        self.size = None;
        self.folder = None;
        self.attributes = None;
        self.modified = None;
        self.encrypted = None;
        self.method = None;
        self.crc = None;
        self.malformed = false;
    }

    fn feed_line(&mut self, mut line: &'a [u8], out: &mut OutCursor<'_>, reliable: &mut bool) {
        if line.ends_with(b"\r") {
            line = &line[..line.len() - 1];
        }
        if line.iter().all(|&b| b == b' ' || b == b'\t') {
            self.end_record(out, reliable);
            return;
        }
        if self.malformed {
            return;
        }
        let (key, value) = if let Some(sep) = line.windows(3).position(|w| w == b" = ") {
            (&line[..sep], &line[sep + 3..])
        } else if line.ends_with(b" =") {
            (&line[..line.len() - 2], &[][..])
        } else {
            self.malformed = true;
            return;
        };

        if !is_valid_7z_key(key) || self.seen_keys.contains(&key) {
            self.malformed = true;
            return;
        }
        self.seen_keys.push(key);
        match key {
            b"Path" => self.path = Some(value),
            b"Size" => self.size = Some(value),
            b"Folder" => self.folder = Some(value),
            b"Attributes" => self.attributes = Some(value),
            b"Modified" => self.modified = Some(value),
            b"Encrypted" => self.encrypted = Some(value),
            b"Method" => self.method = Some(value),
            b"CRC" => self.crc = Some(value),
            _ => {}
        }
    }

    fn end_record(&mut self, out: &mut OutCursor<'_>, reliable: &mut bool) {
        if self.seen_keys.is_empty() && !self.malformed {
            self.reset();
            return;
        }
        let path = self.path.unwrap_or(b"");
        if self.malformed
            || path.is_empty()
            || self.size.is_none()
            || has_7z_path_control_chars(path)
        {
            *reliable = false;
            self.reset();
            return;
        }
        if self.folder == Some(b"+") || self.attributes.is_some_and(|a| a.starts_with(b"D")) {
            self.reset();
            return;
        }

        // Emit 5 NUL-delimited fields:
        // 1. name (Path)
        out.extend(path);
        out.push(0);
        // 2. size (decimal)
        let size_val = parse_php_int(self.size.unwrap_or(b""));
        push_i64_dec(out, size_val);
        out.push(0);
        // 3. modified[..min(19)]
        let mod_slice = self.modified.unwrap_or(b"");
        out.extend(&mod_slice[..mod_slice.len().min(19)]);
        out.push(0);
        // 4. flags_char: '0' + pass + (compressed * 2)
        let pass = u8::from(self.encrypted == Some(b"+"));
        let method = self.method.unwrap_or(b"");
        let compressed = u8::from(!method.is_empty() && method != b"Copy");
        out.push(b'0' + pass + (compressed << 1));
        out.push(0);
        // 5. crc32 (hex lowercase without leading zeros, or empty if absent/invalid)
        let crc_slice = self.crc.unwrap_or(b"");
        if (1..=8).contains(&crc_slice.len()) && crc_slice.iter().all(|b| b.is_ascii_hexdigit()) {
            let mut crc_val: u32 = 0;
            for &b in crc_slice {
                let d = match b {
                    b'0'..=b'9' => (b - b'0') as u32,
                    b'a'..=b'f' => (b - b'a' + 10) as u32,
                    b'A'..=b'F' => (b - b'A' + 10) as u32,
                    _ => 0,
                };
                crc_val = (crc_val << 4) | d;
            }
            push_u32_hex_lower(out, crc_val);
        }
        out.push(0);

        self.reset();
    }
}

/// Find the byte offset right after the last blank line (`\n` where the line content after
/// stripping one optional `\r` is only spaces/tabs).
fn find_last_blank_line_end(input: &[u8]) -> usize {
    let mut last_blank_end = 0usize;
    let mut start = 0usize;
    while start < input.len() {
        let ptr = unsafe { memchr(input[start..].as_ptr(), b'\n' as i32, input.len() - start) };
        if ptr.is_null() {
            break;
        }
        let end = start + (ptr as usize - input[start..].as_ptr() as usize);
        let mut line = &input[start..end];
        if line.ends_with(b"\r") {
            line = &line[..line.len() - 1];
        }
        if line.iter().all(|&b| b == b' ' || b == b'\t') {
            last_blank_end = end + 1;
        }
        start = end + 1;
    }
    last_blank_end
}

pub(crate) fn parse_sevenzip_listing_into(
    input: &[u8],
    flush: bool,
    out: &mut OutCursor<'_>,
) -> isize {
    let parse_len = if flush {
        input.len()
    } else {
        find_last_blank_line_end(input)
    };
    if parse_len == 0 {
        return 0;
    }

    let slice = &input[..parse_len];
    let mut state = SevenZipRecordState::new();
    let mut reliable = true;

    // Reserve header: consumed_dec \0 reliable_char \0
    push_i64_dec(out, parse_len as i64);
    out.push(0);
    let reliable_pos = out.pos;
    out.push(b'1');
    out.push(0);

    let mut start = 0usize;
    while start < slice.len() {
        let ptr = unsafe { memchr(slice[start..].as_ptr(), b'\n' as i32, slice.len() - start) };
        if ptr.is_null() {
            state.feed_line(&slice[start..], out, &mut reliable);
            break;
        }
        let end = start + (ptr as usize - slice[start..].as_ptr() as usize);
        state.feed_line(&slice[start..end], out, &mut reliable);
        start = end + 1;
    }
    if flush {
        state.end_record(out, &mut reliable);
    }
    if !reliable && reliable_pos < out.buf.len() {
        out.buf[reliable_pos] = b'0';
    }
    if out.pos > out.buf.len() {
        -(out.pos.max(4) as isize)
    } else {
        out.pos as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_sevenzip_parse_listing(
    ptr: *const u8,
    len: usize,
    flush: u8,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if (ptr.is_null() && len > 0) || (out_ptr.is_null() && out_cap > 0) {
        return -1;
    }
    let input = if len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(ptr, len) }
    };
    let mut out = unsafe { OutCursor::new(out_ptr, out_cap) };
    parse_sevenzip_listing_into(input, flush != 0, &mut out)
}

// ---------------------------------------------------------------------------
// 5. AdditionalWorkPlanner batch title classification
// ---------------------------------------------------------------------------

#[inline]
fn is_music_group_name(group: &[u8]) -> bool {
    // '/flac|lossless|mp3|music|inner-sanctum|sound/i'
    find_ascii_ci(group, b"flac").is_some()
        || find_ascii_ci(group, b"lossless").is_some()
        || find_ascii_ci(group, b"mp3").is_some()
        || find_ascii_ci(group, b"music").is_some()
        || find_ascii_ci(group, b"inner-sanctum").is_some()
        || find_ascii_ci(group, b"sound").is_some()
}

#[inline]
fn has_dot_three_digits(s: &[u8]) -> bool {
    let mut i = 0;
    while i + 4 <= s.len() {
        if s[i] == b'.'
            && s[i + 1].is_ascii_digit()
            && s[i + 2].is_ascii_digit()
            && s[i + 3].is_ascii_digit()
        {
            return true;
        }
        i += 1;
    }
    false
}

fn has_book_word_in_slice(line: &[u8]) -> bool {
    let mut i = 0;
    while i < line.len() {
        if !is_word_char(line[i]) {
            i += 1;
            continue;
        }
        let start = i;
        while i < line.len() && is_word_char(line[i]) {
            i += 1;
        }
        let w = &line[start..i];
        let matched = match w.len() {
            3 => w.eq_ignore_ascii_case(b"lit") || w.eq_ignore_ascii_case(b"pdf"),
            4 => {
                w.eq_ignore_ascii_case(b"epub")
                    || w.eq_ignore_ascii_case(b"mobi")
                    || w.eq_ignore_ascii_case(b"html")
            }
            5 => w.eq_ignore_ascii_case(b"sipdf"),
            _ => false,
        };
        if matched {
            return true;
        }
    }
    false
}

fn matches_ignore_book(title: &[u8]) -> bool {
    // '/\b(epub|lit|mobi|pdf|sipdf|html)\b.*\.rar(?!.{20,})/i'
    if !title.contains(&b'\n') {
        let check_from = title.len().saturating_sub(23);
        let Some(rel_rar) = find_ascii_ci(&title[check_from..], b".rar") else {
            return false;
        };
        let last_valid_rar = check_from + rel_rar;
        return has_book_word_in_slice(&title[..last_valid_rar]);
    }
    for line in title.split(|&b| b == b'\n') {
        let check_from = line.len().saturating_sub(23);
        let Some(rel_rar) = find_ascii_ci(&line[check_from..], b".rar") else {
            continue;
        };
        let last_valid_rar = check_from + rel_rar;
        if has_book_word_in_slice(&line[..last_valid_rar]) {
            return true;
        }
    }
    false
}

#[inline]
fn try_match_clean_quoted_part_rar(title: &[u8]) -> Option<(&[u8], bool)> {
    let q1_ptr = unsafe { memchr(title.as_ptr(), b'"' as i32, title.len()) };
    if q1_ptr.is_null() {
        return None;
    }
    let q1 = q1_ptr as usize - title.as_ptr() as usize;
    let rem1 = &title[q1 + 1..];
    let q2_ptr = unsafe { memchr(rem1.as_ptr(), b'"' as i32, rem1.len()) };
    if q2_ptr.is_null() {
        return None;
    }
    let q2 = q1 + 1 + (q2_ptr as usize - rem1.as_ptr() as usize);
    let tail = &title[q2 + 1..];
    if tail.len() >= 19 || ! unsafe { memchr(tail.as_ptr(), b'"' as i32, tail.len()) }.is_null() {
        return None;
    }
    let q = &title[q1 + 1..q2];
    if q.len() < 16
        || is_php_trim_byte(q[0])
        || !q[q.len() - 4..].eq_ignore_ascii_case(b".rar")
    {
        return None;
    }
    let d_end = q.len() - 4;
    let mut d_start = d_end;
    while d_start > 0 && q[d_start - 1].is_ascii_digit() {
        d_start -= 1;
    }
    if d_end == d_start
        || d_end - d_start > 5
        || d_start < 6
        || !q[d_start - 5..d_start].eq_ignore_ascii_case(b".part")
    {
        return None;
    }
    let p = d_start - 5;
    let prefix = &title[..q1];
    if prefix
        .iter()
        .any(|&b| b == b'.' || b == b'\n' || b == b'\r')
        || has_book_word_in_slice(prefix)
    {
        return None;
    }
    if !tail.iter().all(|&b| {
        matches!(
            b,
            b' ' | b'\t' | b'(' | b')' | b'/' | b'0'..=b'9' | b'-' | b'[' | b']'
                | b'y' | b'Y' | b'e' | b'E' | b'n' | b'N' | b'c' | b'C'
        )
    }) {
        return None;
    }
    let mut nz = d_start;
    while nz < d_end && q[nz] == b'0' {
        nz += 1;
    }
    let is_first = &q[nz..d_end] == b"1";
    Some((&q[..p], is_first))
}

#[inline]
fn is_pcre_dollar_or_delim(s: &[u8], pos: usize, allow_dash_char: bool) -> bool {
    if pos == s.len() || (pos + 1 == s.len() && s[pos] == b'\n') {
        return true;
    }
    if matches!(s[pos], b' ' | b'"' | b')') {
        return true;
    }
    if allow_dash_char {
        s[pos] == b'-'
    } else {
        s[pos..].starts_with(b"-]")
    }
}

fn parse_u32_digits(s: &[u8]) -> Option<u32> {
    if s.is_empty() || !s.iter().all(|b| b.is_ascii_digit()) {
        return None;
    }
    let mut val = 0u32;
    for &b in s {
        val = val.checked_mul(10)?.checked_add((b - b'0') as u32)?;
    }
    Some(val)
}

fn seven_zip_volume(title: &[u8]) -> Option<(&[u8], u32)> {
    // 1. '/"([^"\r\n]+)\.7z(?:\.(\d{3}))?"/i'
    let mut i = 0;
    while i < title.len() {
        if title[i] == b'"' {
            let start = i + 1;
            let mut j = start;
            while j < title.len() && !matches!(title[j], b'"' | b'\r' | b'\n') {
                j += 1;
            }
            if j < title.len() && title[j] == b'"' && j > start {
                let inner = &title[start..j];
                if inner.len() > 7
                    && inner[inner.len() - 7..inner.len() - 4].eq_ignore_ascii_case(b".7z")
                    && inner[inner.len() - 4] == b'.'
                    && inner[inner.len() - 3..].iter().all(|b| b.is_ascii_digit())
                {
                    let stem = &inner[..inner.len() - 7];
                    let vol = parse_u32_digits(&inner[inner.len() - 3..]).unwrap_or(0);
                    return Some((stem, vol));
                }
                if inner.len() > 3 && inner[inner.len() - 3..].eq_ignore_ascii_case(b".7z") {
                    let stem = &inner[..inner.len() - 3];
                    return Some((stem, 0));
                }
            }
        }
        i += 1;
    }

    // 2. '/([^"\s\/]+)\.7z(?:\.(\d{3}))?($|[ ")]|-)/i'
    let mut idx = 0;
    while idx < title.len() {
        if title[idx] == b'"' || title[idx] == b'/' || is_pcre_space(title[idx]) {
            idx += 1;
            continue;
        }
        let tok_start = idx;
        while idx < title.len()
            && title[idx] != b'"'
            && title[idx] != b'/'
            && !is_pcre_space(title[idx])
        {
            idx += 1;
        }
        let tok_end = idx;
        if tok_end - tok_start > 3 {
            for p in (tok_start + 1..=tok_end - 3).rev() {
                if !title[p..p + 3].eq_ignore_ascii_case(b".7z") {
                    continue;
                }
                if p + 7 <= tok_end
                    && title[p + 3] == b'.'
                    && title[p + 4..p + 7].iter().all(|b| b.is_ascii_digit())
                    && is_pcre_dollar_or_delim(title, p + 7, true)
                {
                    let stem = &title[tok_start..p];
                    let vol = parse_u32_digits(&title[p + 4..p + 7]).unwrap_or(0);
                    return Some((stem, vol));
                }
                if is_pcre_dollar_or_delim(title, p + 3, true) {
                    let stem = &title[tok_start..p];
                    return Some((stem, 0));
                }
            }
        }
    }
    None
}

fn numbered_volume(title: &[u8]) -> Option<(&[u8], u32)> {
    // '/"([^"\r\n]+)\.(\d{3})"|(?:^|\s)([^"\s\/]+)\.(\d{3})(?=$|\s+(?:yEnc\b|\(\d+\/\d+\)))/i'
    let mut i = 0;
    while i < title.len() {
        // Branch 1 at offset i:
        if title[i] == b'"' {
            let start = i + 1;
            let mut j = start;
            while j < title.len() && !matches!(title[j], b'"' | b'\r' | b'\n') {
                j += 1;
            }
            if j < title.len() && title[j] == b'"' && j - start >= 5 {
                let inner = &title[start..j];
                if inner[inner.len() - 4] == b'.'
                    && inner[inner.len() - 3..].iter().all(|b| b.is_ascii_digit())
                {
                    let stem = &inner[..inner.len() - 4];
                    let vol = parse_u32_digits(&inner[inner.len() - 3..]).unwrap_or(0);
                    return Some((stem, vol));
                }
            }
        }

        // Branch 2 at offset i:
        if i == 0 || is_pcre_space(title[i]) {
            let tok_start = if i == 0 && !is_pcre_space(title[0]) {
                0
            } else {
                i + 1
            };
            if tok_start < title.len()
                && title[tok_start] != b'"'
                && title[tok_start] != b'/'
                && !is_pcre_space(title[tok_start])
            {
                let mut tok_end = tok_start;
                while tok_end < title.len()
                    && title[tok_end] != b'"'
                    && title[tok_end] != b'/'
                    && !is_pcre_space(title[tok_end])
                {
                    tok_end += 1;
                }
                if tok_end - tok_start >= 5
                    && title[tok_end - 4] == b'.'
                    && title[tok_end - 3..tok_end].iter().all(|b| b.is_ascii_digit())
                {
                    // Check lookahead (?=$|\s+(?:yEnc\b|\(\d+\/\d+\))) at tok_end
                    let mut lookahead_ok = tok_end == title.len()
                        || (tok_end + 1 == title.len() && title[tok_end] == b'\n');
                    if !lookahead_ok && tok_end < title.len() && is_pcre_space(title[tok_end]) {
                        let mut ws = tok_end;
                        while ws < title.len() && is_pcre_space(title[ws]) {
                            ws += 1;
                        }
                        if ws + 4 <= title.len()
                            && title[ws..ws + 4].eq_ignore_ascii_case(b"yenc")
                            && (ws + 4 == title.len() || !is_word_char(title[ws + 4]))
                        {
                            lookahead_ok = true;
                        } else if ws < title.len() && title[ws] == b'(' {
                            let mut p = ws + 1;
                            let d1 = p;
                            while p < title.len() && title[p].is_ascii_digit() {
                                p += 1;
                            }
                            if p > d1 && p < title.len() && title[p] == b'/' {
                                p += 1;
                                let d2 = p;
                                while p < title.len() && title[p].is_ascii_digit() {
                                    p += 1;
                                }
                                if p > d2 && p < title.len() && title[p] == b')' {
                                    lookahead_ok = true;
                                }
                            }
                        }
                    }
                    if lookahead_ok {
                        let stem = &title[tok_start..tok_end - 4];
                        let vol = parse_u32_digits(&title[tok_end - 3..tok_end]).unwrap_or(0);
                        return Some((stem, vol));
                    }
                }
            }
        }
        i += 1;
    }
    None
}

#[inline]
fn is_video_ext_ci(ext: &[u8]) -> bool {
    // AVI|F4V|IFO|M1V|M2V|M4V|MKV|MOV|MP4|MPEG|MPG|MPGV|MPV|OGV|QT|RM|RMVB|TS|VOB|WMV
    match ext.len() {
        2 => ext.eq_ignore_ascii_case(b"qt") || ext.eq_ignore_ascii_case(b"rm") || ext.eq_ignore_ascii_case(b"ts"),
        3 => {
            ext.eq_ignore_ascii_case(b"avi")
                || ext.eq_ignore_ascii_case(b"f4v")
                || ext.eq_ignore_ascii_case(b"ifo")
                || ext.eq_ignore_ascii_case(b"m1v")
                || ext.eq_ignore_ascii_case(b"m2v")
                || ext.eq_ignore_ascii_case(b"m4v")
                || ext.eq_ignore_ascii_case(b"mkv")
                || ext.eq_ignore_ascii_case(b"mov")
                || ext.eq_ignore_ascii_case(b"mp4")
                || ext.eq_ignore_ascii_case(b"mpg")
                || ext.eq_ignore_ascii_case(b"mpv")
                || ext.eq_ignore_ascii_case(b"ogv")
                || ext.eq_ignore_ascii_case(b"vob")
                || ext.eq_ignore_ascii_case(b"wmv")
        }
        4 => {
            ext.eq_ignore_ascii_case(b"mpeg")
                || ext.eq_ignore_ascii_case(b"mpgv")
                || ext.eq_ignore_ascii_case(b"rmvb")
        }
        _ => false,
    }
}

#[inline]
fn is_audio_ext_ci(ext: &[u8]) -> bool {
    // AAC|AIFF|APE|AC3|ASF|DTS|FLAC|MKA|MKS|MP2|MP3|RA|OGG|OGM|W64|WAV|WMA
    match ext.len() {
        2 => ext.eq_ignore_ascii_case(b"ra"),
        3 => {
            ext.eq_ignore_ascii_case(b"aac")
                || ext.eq_ignore_ascii_case(b"ape")
                || ext.eq_ignore_ascii_case(b"ac3")
                || ext.eq_ignore_ascii_case(b"asf")
                || ext.eq_ignore_ascii_case(b"dts")
                || ext.eq_ignore_ascii_case(b"mka")
                || ext.eq_ignore_ascii_case(b"mks")
                || ext.eq_ignore_ascii_case(b"mp2")
                || ext.eq_ignore_ascii_case(b"mp3")
                || ext.eq_ignore_ascii_case(b"ogg")
                || ext.eq_ignore_ascii_case(b"ogm")
                || ext.eq_ignore_ascii_case(b"w64")
                || ext.eq_ignore_ascii_case(b"wav")
                || ext.eq_ignore_ascii_case(b"wma")
        }
        4 => ext.eq_ignore_ascii_case(b"aiff") || ext.eq_ignore_ascii_case(b"flac"),
        _ => false,
    }
}

fn media_split_volume_from_filename(fname: &[u8]) -> Option<u32> {
    // '/\.(AVI|...|WMV)\.(?<volume>\d{3})$/i'
    if fname.len() < 7
        || fname[fname.len() - 4] != b'.'
        || !fname[fname.len() - 3..].iter().all(|b| b.is_ascii_digit())
    {
        return None;
    }
    let before_vol = &fname[..fname.len() - 4];
    let dot_pos = before_vol.iter().rposition(|&b| b == b'.')?;
    let ext = &before_vol[dot_pos + 1..];
    if is_video_ext_ci(ext) {
        parse_u32_digits(&fname[fname.len() - 3..])
    } else {
        None
    }
}

fn matches_archive_tail(title: &[u8], pos: usize) -> bool {
    // '("|\s*\.rar)*($|[ ")]|-])'
    if is_pcre_dollar_or_delim(title, pos, false) {
        return true;
    }
    if pos < title.len() && title[pos] == b'"' && matches_archive_tail(title, pos + 1) {
        return true;
    }
    let mut ws = pos;
    while ws < title.len() && is_pcre_space(title[ws]) {
        ws += 1;
    }
    if ws + 4 <= title.len()
        && title[ws..ws + 4].eq_ignore_ascii_case(b".rar")
        && matches_archive_tail(title, ws + 4)
    {
        return true;
    }
    false
}

fn match_hashed_archive_position(title: &[u8]) -> Option<u32> {
    // '"[a-f0-9]{32}\.[1-9]\d{1,2}".*\((\d+)\/\d{2,}\)$' (case-insensitive, . != \n)
    let trimmed_end = if title.ends_with(b"\n") {
        &title[..title.len() - 1]
    } else {
        title
    };
    if !trimmed_end.ends_with(b")") {
        return None;
    }
    let open_paren = trimmed_end.iter().rposition(|&b| b == b'(')?;
    let inside = &trimmed_end[open_paren + 1..trimmed_end.len() - 1];
    let slash = inside.iter().position(|&b| b == b'/')?;
    let num_part = &inside[..slash];
    let total_part = &inside[slash + 1..];
    if num_part.is_empty()
        || total_part.len() < 2
        || !num_part.iter().all(|b| b.is_ascii_digit())
        || !total_part.iter().all(|b| b.is_ascii_digit())
    {
        return None;
    }
    let pos_num = parse_u32_digits(num_part)?;
    let before_paren = &trimmed_end[..open_paren];
    let mut i = 0;
    while i + 37 <= before_paren.len() {
        if before_paren[i] == b'"'
            && before_paren[i + 1..i + 33].iter().all(|b| b.is_ascii_hexdigit())
            && before_paren[i + 33] == b'.'
            && (b'1'..=b'9').contains(&before_paren[i + 34])
        {
            let mut d_end = i + 35;
            if d_end < before_paren.len() && before_paren[d_end].is_ascii_digit() {
                d_end += 1;
            }
            if d_end < before_paren.len() && before_paren[d_end].is_ascii_digit() {
                d_end += 1;
            }
            if d_end <= i + 37
                && d_end > i + 35
                && d_end < before_paren.len()
                && before_paren[d_end] == b'"'
                && !before_paren[d_end + 1..].contains(&b'\n')
            {
                return Some(pos_num);
            }
        }
        i += 1;
    }
    None
}

fn matches_archive_pattern(title: &[u8]) -> bool {
    // '/(\.(part\d+|[rz]\d+|rar|7z|0+|0*10?|zipr\d{2,3}|zipx?)("|\s*\.rar)*($|[ ")]|-])|"[a-f0-9]{32}\.[1-9]\d{1,2}".*\(\d+\/\d{2,}\)$)/i'
    let mut cur = 0;
    while cur < title.len() {
        let ptr = unsafe { memchr(title[cur..].as_ptr(), b'.' as i32, title.len() - cur) };
        if ptr.is_null() {
            break;
        }
        let i = cur + (ptr as usize - title[cur..].as_ptr() as usize);
        cur = i + 1;
        let rest = &title[i + 1..];
        if rest.is_empty() {
            break;
        }
        let b0 = rest[0].to_ascii_lowercase();
        match b0 {
            b'p' => {
                if rest.len() >= 5
                    && rest[..4].eq_ignore_ascii_case(b"part")
                    && rest[4].is_ascii_digit()
                {
                    let mut e = 5;
                    while e < rest.len() && rest[e].is_ascii_digit() {
                        e += 1;
                    }
                    if matches_archive_tail(title, i + 1 + e) {
                        return true;
                    }
                }
            }
            b'r' => {
                if rest.len() >= 2 && rest[1].is_ascii_digit() {
                    let mut e = 2;
                    while e < rest.len() && rest[e].is_ascii_digit() {
                        e += 1;
                    }
                    if matches_archive_tail(title, i + 1 + e) {
                        return true;
                    }
                } else if rest.len() >= 3
                    && rest[..3].eq_ignore_ascii_case(b"rar")
                    && matches_archive_tail(title, i + 4)
                {
                    return true;
                }
            }
            b'z' => {
                if rest.len() >= 2 && rest[1].is_ascii_digit() {
                    let mut e = 2;
                    while e < rest.len() && rest[e].is_ascii_digit() {
                        e += 1;
                    }
                    if matches_archive_tail(title, i + 1 + e) {
                        return true;
                    }
                } else if rest.len() >= 6
                    && rest[..4].eq_ignore_ascii_case(b"zipr")
                    && rest[4].is_ascii_digit()
                    && rest[5].is_ascii_digit()
                {
                    if rest.len() >= 7
                        && rest[6].is_ascii_digit()
                        && matches_archive_tail(title, i + 1 + 7)
                    {
                        return true;
                    }
                    if matches_archive_tail(title, i + 1 + 6) {
                        return true;
                    }
                } else if rest.len() >= 4
                    && rest[..4].eq_ignore_ascii_case(b"zipx")
                    && matches_archive_tail(title, i + 5)
                {
                    return true;
                } else if rest.len() >= 3
                    && rest[..3].eq_ignore_ascii_case(b"zip")
                    && matches_archive_tail(title, i + 4)
                {
                    return true;
                }
            }
            b'7' => {
                if rest.len() >= 2
                    && rest[..2].eq_ignore_ascii_case(b"7z")
                    && matches_archive_tail(title, i + 3)
                {
                    return true;
                }
            }
            b'0' | b'1' => {
                let mut z = 0;
                while z < rest.len() && rest[z] == b'0' {
                    z += 1;
                }
                if z > 0 && matches_archive_tail(title, i + 1 + z) {
                    return true;
                }
                if z < rest.len() && rest[z] == b'1' {
                    let e1 = z + 1;
                    if e1 < rest.len()
                        && rest[e1] == b'0'
                        && matches_archive_tail(title, i + 1 + e1 + 1)
                    {
                        return true;
                    }
                    if matches_archive_tail(title, i + 1 + e1) {
                        return true;
                    }
                }
            }
            _ => {}
        }
    }

    match_hashed_archive_position(title).is_some()
}

fn is_likely_first_volume(
    title: &[u8],
    seven_zip: Option<&(&[u8], u32)>,
    numbered: Option<&(&[u8], u32)>,
) -> bool {
    // 1. '/\.part0*(\d+)/i'
    let mut i = 0;
    while i + 6 <= title.len() {
        if title[i..i + 5].eq_ignore_ascii_case(b".part") && title[i + 5].is_ascii_digit() {
            let mut p = i + 5;
            while p < title.len() && title[p] == b'0' {
                p += 1;
            }
            let d_start = p;
            while p < title.len() && title[p].is_ascii_digit() {
                p += 1;
            }
            // If all digits were '0', $part[1] is '0' -> (int) '0' === 0 !== 1.
            // It equals 1 iff the non-zero suffix is exactly "1".
            return &title[d_start..p] == b"1";
        }
        i += 1;
    }

    // 2. '/"[a-f0-9]{32}\.[1-9]\d{1,2}".*\((\d+)\/\d{2,}\)$/i'
    if let Some(pos_num) = match_hashed_archive_position(title) {
        return pos_num == 1;
    }

    // 3. sevenZipVolume <= 1
    if let Some((_, vol)) = seven_zip {
        return *vol <= 1;
    }

    // 4. '/\.(rar|zip)($|[ ")]|-])/i'
    let mut j = 0;
    while j + 4 <= title.len() {
        if title[j] == b'.'
            && (title[j + 1..j + 4].eq_ignore_ascii_case(b"rar")
                || title[j + 1..j + 4].eq_ignore_ascii_case(b"zip"))
            && is_pcre_dollar_or_delim(title, j + 4, false)
        {
            return true;
        }
        j += 1;
    }

    // 5. numberedVolume <= 1
    if let Some((_, vol)) = numbered {
        return *vol <= 1;
    }
    false
}

fn is_support_file(title: &[u8]) -> bool {
    // '/(?:\.(?:vol\d{1,3}\+\d{1,3}|par2|srs|sfv|nzb)|nfo\b|inf\b|ofn\b)($|[ ")]|-])(?!.{20,})/i'
    let check_tail = |end_pos: usize| -> bool {
        let after_delim = if end_pos == title.len()
            || (end_pos + 1 == title.len() && title[end_pos] == b'\n')
        {
            end_pos
        } else if matches!(title[end_pos], b' ' | b'"' | b')') {
            end_pos + 1
        } else if title[end_pos..].starts_with(b"-]") {
            end_pos + 2
        } else {
            return false;
        };
        let rem = &title[after_delim..];
        let line_rem = match rem.iter().position(|&b| b == b'\n') {
            Some(nl) => nl,
            None => rem.len(),
        };
        line_rem < 20
    };

    // Fast path when title has no newline: only the last 36 bytes of `title` can satisfy (?!.{20,})!
    let start_scan = if !title.contains(&b'\n') {
        title.len().saturating_sub(36)
    } else {
        0
    };

    let mut i = start_scan;
    while i < title.len() {
        let b = title[i];
        if b == b'.' {
            let rest = &title[i + 1..];
            if rest.len() >= 4 && rest[..4].eq_ignore_ascii_case(b"par2") && check_tail(i + 5) {
                return true;
            }
            if rest.len() >= 3
                && (rest[..3].eq_ignore_ascii_case(b"srs")
                    || rest[..3].eq_ignore_ascii_case(b"sfv")
                    || rest[..3].eq_ignore_ascii_case(b"nzb"))
                && check_tail(i + 4)
            {
                return true;
            }
            if rest.len() >= 6 && rest[..3].eq_ignore_ascii_case(b"vol") {
                let mut p = 3;
                while p < rest.len() && p < 6 && rest[p].is_ascii_digit() {
                    p += 1;
                }
                if p > 3 && p < rest.len() && rest[p] == b'+' {
                    p += 1;
                    let d2 = p;
                    while p < rest.len() && p - d2 < 3 && rest[p].is_ascii_digit() {
                        p += 1;
                    }
                    if p > d2 && check_tail(i + 1 + p) {
                        return true;
                    }
                }
            }
        } else if matches!(b, b'n' | b'N' | b'i' | b'I' | b'o' | b'O')
            && i + 3 <= title.len()
            && (title[i..i + 3].eq_ignore_ascii_case(b"nfo")
                || title[i..i + 3].eq_ignore_ascii_case(b"inf")
                || title[i..i + 3].eq_ignore_ascii_case(b"ofn"))
            && (i + 3 == title.len() || !is_word_char(title[i + 3]))
            && check_tail(i + 3)
        {
            return true;
        }
        i += 1;
    }
    false
}

fn has_file_extension(title: &[u8]) -> bool {
    let mut selected = title;
    if let Some(q1) = title.iter().position(|&b| b == b'"') {
        if let Some(q2_rel) = title[q1 + 1..].iter().position(|&b| b == b'"') {
            if q2_rel > 0 {
                selected = &title[q1 + 1..q1 + 1 + q2_rel];
            }
        }
    }
    let trimmed = trim_php(selected);
    let trimmed = if trimmed.ends_with(b"\n") {
        &trimmed[..trimmed.len() - 1]
    } else {
        trimmed
    };
    for ext_len in 2..=4 {
        if trimmed.len() > ext_len
            && trimmed[trimmed.len() - ext_len - 1] == b'.'
            && trimmed[trimmed.len() - ext_len..]
                .iter()
                .all(|b| b.is_ascii_alphanumeric())
        {
            return true;
        }
    }
    false
}

fn check_image_and_media_exts(
    title: &[u8],
) -> (bool, bool, bool, bool, Option<&[u8]>, bool, bool) {
    // Returns:
    // (ends_with_image_ext, has_image_ext_token, has_video_ext_bracket, has_video_ext_or_end, audio_ext, has_par2, has_par2_vol)
    let trimmed_nl = if title.ends_with(b"\n") {
        &title[..title.len() - 1]
    } else {
        title
    };
    let ends_with_image_ext = (trimmed_nl.len() >= 4
        && (trimmed_nl[trimmed_nl.len() - 4..].eq_ignore_ascii_case(b".jpg")
            || trimmed_nl[trimmed_nl.len() - 4..].eq_ignore_ascii_case(b".png")))
        || (trimmed_nl.len() >= 5
            && (trimmed_nl[trimmed_nl.len() - 5..].eq_ignore_ascii_case(b".jpeg")
                || trimmed_nl[trimmed_nl.len() - 5..].eq_ignore_ascii_case(b".webp")));

    let mut has_image_ext_token = false;
    let mut has_video_ext_bracket = false;
    let mut has_video_ext_or_end = false;
    let mut audio_ext: Option<&[u8]> = None;
    let mut has_par2 = false;
    let mut has_par2_vol = false;

    let is_bracket_delim = |b: u8| matches!(b, b'.' | b' ' | b'"' | b')' | b']');
    let is_bracket_or_end = |pos: usize| -> bool {
        pos == title.len()
            || (pos + 1 == title.len() && title[pos] == b'\n')
            || is_bracket_delim(title[pos])
    };

    let mut cur = 0;
    while cur < title.len() {
        let ptr = unsafe { memchr(title[cur..].as_ptr(), b'.' as i32, title.len() - cur) };
        if ptr.is_null() {
            break;
        }
        let i = cur + (ptr as usize - title[cur..].as_ptr() as usize);
        cur = i + 1;
        let rest = &title[i + 1..];
        // Image token: /\.(?:jpe?g|png|webp)(?:[. ")\]]|$)/i
        if !has_image_ext_token {
            if rest.len() >= 3
                && (rest[..3].eq_ignore_ascii_case(b"jpg")
                    || rest[..3].eq_ignore_ascii_case(b"png"))
                && is_bracket_or_end(i + 4)
            {
                has_image_ext_token = true;
            } else if rest.len() >= 4
                && (rest[..4].eq_ignore_ascii_case(b"jpeg")
                    || rest[..4].eq_ignore_ascii_case(b"webp"))
                && is_bracket_or_end(i + 5)
            {
                has_image_ext_token = true;
            }
        }

        // Video ext: 2..=4 chars
        for elen in [4usize, 3, 2] {
            if rest.len() >= elen && is_video_ext_ci(&rest[..elen]) {
                let after = i + 1 + elen;
                if after < title.len() && is_bracket_delim(title[after]) {
                    has_video_ext_bracket = true;
                    has_video_ext_or_end = true;
                } else if is_bracket_or_end(after) {
                    has_video_ext_or_end = true;
                }
            }
        }

        // Audio ext: first match of '/\.(AAC|...)[. ")\]]/i'
        if audio_ext.is_none() {
            for elen in [4usize, 3, 2] {
                if rest.len() > elen
                    && is_audio_ext_ci(&rest[..elen])
                    && is_bracket_delim(rest[elen])
                {
                    audio_ext = Some(&rest[..elen]);
                    break;
                }
            }
        }

        // PAR2: /\.par2\b/i and /\.vol\d+[+-]\d+\.par2\b/i
        if rest.len() >= 4
            && rest[..4].eq_ignore_ascii_case(b"par2")
            && (rest.len() == 4 || !is_word_char(rest[4]))
        {
            has_par2 = true;
        }
        if !has_par2_vol && rest.len() >= 11 && rest[..3].eq_ignore_ascii_case(b"vol") {
            let mut p = 3;
            while p < rest.len() && rest[p].is_ascii_digit() {
                p += 1;
            }
            if p > 3 && p < rest.len() && matches!(rest[p], b'+' | b'-') {
                p += 1;
                let d2 = p;
                while p < rest.len() && rest[p].is_ascii_digit() {
                    p += 1;
                }
                if p > d2
                    && rest.len() >= p + 5
                    && rest[p..p + 5].eq_ignore_ascii_case(b".par2")
                    && (rest.len() == p + 5 || !is_word_char(rest[p + 5]))
                {
                    has_par2_vol = true;
                }
            }
        }
    }

    (
        ends_with_image_ext,
        has_image_ext_token,
        has_video_ext_bracket,
        has_video_ext_or_end,
        audio_ext,
        has_par2,
        has_par2_vol,
    )
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_additional_classify_titles_nul(
    group_ptr: *const u8,
    group_len: usize,
    titles_ptr: *const u8,
    titles_len: usize,
    count: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if (group_ptr.is_null() && group_len > 0)
        || (titles_ptr.is_null() && titles_len > 0)
        || (out_ptr.is_null() && out_cap > 0)
    {
        return -1;
    }
    let group = if group_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(group_ptr, group_len) }
    };
    let titles = if titles_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(titles_ptr, titles_len) }
    };

    let mut out = unsafe { OutCursor::new(out_ptr, out_cap) };
    out.push(if is_music_group_name(group) { b'1' } else { b'0' });

    if count == 0 {
        return out.finish();
    }

    let mut seen = 0usize;
    let mut prev_set_key_buf = [0u8; 512];
    let mut prev_set_key_len = 0usize;
    let mut prev_clean_rar: Option<(&[u8], u64)> = None;
    let mut fname_stack = [0u8; 512];
    let mut sk_stack = [0u8; 520];

    for title in titles.split(|&b| b == 0) {
        seen += 1;
        out.push(0);

        let clean_rar = try_match_clean_quoted_part_rar(title);
        if let (Some((stem, is_first)), Some((prev_stem, base_flags))) = (clean_rar, prev_clean_rar) {
            if stem == prev_stem {
                let mut flags = base_flags | (1 << 58);
                if is_first {
                    flags |= 1 << 4;
                }
                push_i64_dec(&mut out, flags as i64);
                continue;
            }
        }

        let is_ignore_book = matches_ignore_book(title);
        let has_7z_sub = find_ascii_ci(title, b".7z").is_some();
        let seven_zip = if has_7z_sub {
            seven_zip_volume(title)
        } else {
            None
        };
        let has_dot3 = has_dot_three_digits(title);
        let numbered = if seven_zip.is_none() && has_dot3 {
            numbered_volume(title)
        } else {
            None
        };
        let split_vol = seven_zip.as_ref().or(numbered.as_ref());

        let fname_vec;
        let fname: &[u8] = if title.len() <= 512 {
            let mut fc = OutCursor {
                buf: &mut fname_stack,
                pos: 0,
            };
            archive_candidate_file_name_into(title, &mut fc);
            let flen = fc.pos;
            &fname_stack[..flen]
        } else {
            fname_vec = archive_candidate_file_name_vec(title);
            &fname_vec
        };

        let media_split = if has_dot3 {
            media_split_volume_from_filename(fname)
        } else {
            None
        };
        let is_archive = matches_archive_pattern(title);
        let likely_first = if media_split.is_none() && is_archive {
            let num_for_first = if seven_zip.is_none() {
                numbered.as_ref()
            } else {
                None
            };
            is_likely_first_volume(title, seven_zip.as_ref(), num_for_first)
        } else {
            false
        };

        let is_archive_candidate = media_split.is_some_and(|v| v <= 1)
            || (media_split.is_none() && is_archive);
        let sk_vec;
        let set_key: Option<&[u8]> = if is_archive_candidate {
            if fname.len() <= 512 {
                let mut sc = OutCursor {
                    buf: &mut sk_stack,
                    pos: 0,
                };
                archive_candidate_volume_set_key_into(fname, &mut sc);
                let slen = sc.pos;
                Some(&sk_stack[..slen])
            } else {
                sk_vec = archive_candidate_volume_set_key_from_filename(fname);
                Some(&sk_vec)
            }
        } else {
            None
        };
        let same_set_key_as_prev = match set_key {
            Some(sk) => {
                prev_set_key_len > 0 && sk == &prev_set_key_buf[..prev_set_key_len]
            }
            None => false,
        };

        let support = is_support_file(title);
        let has_sample = find_ascii_ci(title, b"sample").is_some();
        let (
            ends_with_image_ext,
            has_image_ext_token,
            has_video_ext_bracket,
            has_video_ext_or_end,
            audio_ext,
            has_par2,
            has_par2_vol,
        ) = check_image_and_media_exts(title);

        let is_sample_candidate = has_sample && !ends_with_image_ext && !is_archive;
        let is_jpg_candidate = has_image_ext_token;
        let img_rank = if is_jpg_candidate {
            match image_candidate_rank(title) {
                Some(r) => r as u64,
                None => return -2,
            }
        } else {
            2u64
        };
        let is_mediainfo_candidate = has_sample && has_video_ext_bracket;
        let has_ext = has_file_extension(title);
        let is_main_video = !has_sample && !is_archive && has_video_ext_or_end;

        let split_vol_num = split_vol.map(|(_, v)| *v).unwrap_or(0);
        let media_split_num = media_split.unwrap_or(0);
        if split_vol_num > 2047 || media_split_num > 2047 {
            return -2;
        }

        let mut flags: u64 = 0;
        if is_ignore_book {
            flags |= 1 << 0;
        }
        if split_vol.is_some() {
            flags |= 1 << 1;
        }
        if media_split.is_some() {
            flags |= 1 << 2;
        }
        if is_archive {
            flags |= 1 << 3;
        }
        if likely_first {
            flags |= 1 << 4;
        }
        if support {
            flags |= 1 << 6;
        }
        if is_sample_candidate {
            flags |= 1 << 7;
        }
        if is_jpg_candidate {
            flags |= 1 << 8;
        }
        if is_mediainfo_candidate {
            flags |= 1 << 9;
        }
        if audio_ext.is_some() {
            flags |= 1 << 10;
        }
        if has_ext {
            flags |= 1 << 11;
        }
        if has_par2 {
            flags |= 1 << 12;
        }
        if has_par2_vol {
            flags |= 1 << 13;
        }
        if is_main_video {
            flags |= 1 << 14;
        }
        flags |= (img_rank & 3) << 15;
        flags |= (split_vol_num as u64) << 17;
        flags |= (media_split_num as u64) << 28;
        if same_set_key_as_prev {
            flags |= 1 << 58;
        }

        push_i64_dec(&mut out, flags as i64);

        if let Some((split_set, _)) = split_vol {
            out.push(0);
            if seven_zip.is_none() {
                out.push(b'#');
            }
            for &b in *split_set {
                out.push(b.to_ascii_lowercase());
            }
        }
        if let Some(sk) = set_key {
            if !same_set_key_as_prev {
                out.push(0);
                out.extend(sk);
                if sk.len() <= 512 {
                    prev_set_key_buf[..sk.len()].copy_from_slice(sk);
                    prev_set_key_len = sk.len();
                } else {
                    prev_set_key_len = 0;
                }
            }
            if let Some((stem, _)) = clean_rar {
                if !stem.contains(&b'\n')
                    && !stem.contains(&b'\r')
                    && find_ascii_ci(stem, b".part").is_none()
                    && split_vol.is_none()
                    && media_split.is_none()
                    && is_archive
                    && !support
                    && audio_ext.is_none()
                    && sk.len() == 4 + stem.len()
                    && sk.starts_with(b"rar:")
                {
                    prev_clean_rar = Some((stem, flags & !((1 << 4) | (1 << 58))));
                } else {
                    prev_clean_rar = None;
                }
            } else {
                prev_clean_rar = None;
            }
        }
        if let Some(aext) = audio_ext {
            out.push(0);
            out.extend(aext);
        }
    }

    if seen != count {
        return -1;
    }

    if out.pos <= out.buf.len() {
        out.pos as isize
    } else {
        -(out.pos.max(4) as isize)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_video_container_detect_and_ts_offset() {
        assert_eq!(detect_video_container(b"\x1A\x45\xDF\xA3000000000000"), 1);
        assert_eq!(detect_video_container(b"RIFF\0\0\0\0AVI LIST"), 2);
        assert_eq!(detect_video_container(b"\0\0\0\x20ftypisom\0\0"), 5);
        let mut ts = Vec::new();
        ts.extend_from_slice(b"junk!");
        for _ in 0..5 {
            ts.push(0x47);
            ts.extend_from_slice(&[0x11; 187]);
        }
        assert_eq!(transport_stream_offset(&ts), Some(5));
        assert_eq!(detect_video_container(&ts), 4);
    }

    #[test]
    fn test_image_candidate_rank() {
        assert_eq!(
            image_candidate_rank(b"\"Movie.XXX.CD1.screens.jpg\" yEnc (1/3)"),
            Some(0)
        );
        assert_eq!(
            image_candidate_rank(b"\"Movie.XXX.cover.jpg\" yEnc (1/3)"),
            Some(1)
        );
        assert_eq!(
            image_candidate_rank(b"\"Movie.XXX.jpg\" yEnc (1/3)"),
            Some(2)
        );
        assert_eq!(
            image_candidate_rank(b"\"Movie.XXX.back.jpg\" yEnc (1/3)"),
            Some(3)
        );
        assert_eq!(image_candidate_rank(b"Movie/proof.jpg"), Some(3));
    }

    #[test]
    fn test_archive_candidate_filename_and_volume_set_key() {
        for subject in [
            b"\"Movie.2026.part01.rar\" yEnc (1/50)".as_slice(),
            b"Movie.2026.part01.rar yEnc (1/50)".as_slice(),
            b"Movie.2026.part01.rar (1/50)".as_slice(),
            b"MOVIE.2026.PART01.RAR - yEnc (01/50)".as_slice(),
            b"[01/10] - Movie.2026.part01.rar yEnc".as_slice(),
            b"[01/10] - \"Movie.2026.part01.rar\" yEnc (1/50)".as_slice(),
        ] {
            let fname = archive_candidate_file_name_vec(subject);
            assert_eq!(fname, b"movie.2026.part01.rar");
            let key = archive_candidate_volume_set_key_from_filename(&fname);
            assert_eq!(key, b"rar:movie.2026");
        }
    }
}
