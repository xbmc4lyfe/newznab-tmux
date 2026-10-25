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
pub unsafe extern "C" fn nntmux_video_transport_stream_offset(ptr: *const u8, len: usize) -> isize {
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
    if tail.len() >= 19 || !unsafe { memchr(tail.as_ptr(), b'"' as i32, tail.len()) }.is_null() {
        return None;
    }
    let q = &title[q1 + 1..q2];
    if q.len() < 20 || is_php_trim_byte(q[0]) || !q[q.len() - 4..].eq_ignore_ascii_case(b".rar") {
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
        || find_ascii_ci(prefix, b"sample").is_some()
    {
        return None;
    }
    if !tail.iter().all(|&b| {
        matches!(
            b,
            b' ' | b'\t' | b'(' | b')' | b'/' | b'0'
                ..=b'9'
                    | b'-'
                    | b'['
                    | b']'
                    | b'y'
                    | b'Y'
                    | b'e'
                    | b'E'
                    | b'n'
                    | b'N'
                    | b'c'
                    | b'C'
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
                    && title[tok_end - 3..tok_end]
                        .iter()
                        .all(|b| b.is_ascii_digit())
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
        2 => {
            ext.eq_ignore_ascii_case(b"qt")
                || ext.eq_ignore_ascii_case(b"rm")
                || ext.eq_ignore_ascii_case(b"ts")
        }
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
            && before_paren[i + 1..i + 33]
                .iter()
                .all(|b| b.is_ascii_hexdigit())
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
        let after_delim =
            if end_pos == title.len() || (end_pos + 1 == title.len() && title[end_pos] == b'\n') {
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

fn check_image_and_media_exts(title: &[u8]) -> (bool, bool, bool, bool, Option<&[u8]>, bool, bool) {
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
    out.push(if is_music_group_name(group) {
        b'1'
    } else {
        b'0'
    });

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
        if let (Some((stem, is_first)), Some((prev_stem, base_flags))) = (clean_rar, prev_clean_rar)
        {
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

        let is_archive_candidate =
            media_split.is_some_and(|v| v <= 1) || (media_split.is_none() && is_archive);
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
            Some(sk) => prev_set_key_len > 0 && sk == &prev_set_key_buf[..prev_set_key_len],
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
        if set_key.is_some() {
            flags |= 1 << 5;
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

// ---------------------------------------------------------------------------
// 6. Par2FileMapper (`map` & `candidateIndexes`)
// ---------------------------------------------------------------------------

const PAR2_MIN_SIZE_RATIO: f64 = 0.90;
const PAR2_MAX_SIZE_RATIO: f64 = 1.001;

#[derive(Clone, Copy)]
struct Par2SourceEntry {
    source_index: u32,
    posted_size: i64,
    segment_count: i64,
}

#[inline]
fn read_ne_i64(bytes: &[u8], offset: usize) -> i64 {
    let mut arr = [0u8; 8];
    arr.copy_from_slice(&bytes[offset..offset + 8]);
    i64::from_ne_bytes(arr)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_map_files(
    sources_ptr: *const u8,
    sources_len: usize,
    files_ptr: *const u8,
    files_len: usize,
    verified_ptr: *const u8,
    verified_len: usize,
    article_part_size: i64,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if (sources_ptr.is_null() && sources_len > 0)
        || (files_ptr.is_null() && files_len > 0)
        || (verified_ptr.is_null() && verified_len > 0)
        || (out_ptr.is_null() && out_cap > 0)
        || sources_len % 24 != 0
        || files_len % 8 != 0
        || verified_len % 8 != 0
    {
        return -1;
    }
    if sources_len == 0 || files_len == 0 {
        return 0;
    }

    let sources_bytes = unsafe { slice::from_raw_parts(sources_ptr, sources_len) };
    let files_bytes = unsafe { slice::from_raw_parts(files_ptr, files_len) };
    let verified_bytes = if verified_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(verified_ptr, verified_len) }
    };

    let verified_count = verified_len / 8;
    let mut verified_stack = [0u32; 32];
    let mut verified_vec = Vec::new();
    let verified_slice: &[u32] = if verified_count == 0 {
        &[]
    } else if verified_count <= 32 {
        for i in 0..verified_count {
            verified_stack[i] = read_ne_i64(verified_bytes, i * 8) as u32;
        }
        &verified_stack[..verified_count]
    } else {
        verified_vec.reserve(verified_count);
        for i in 0..verified_count {
            verified_vec.push(read_ne_i64(verified_bytes, i * 8) as u32);
        }
        &verified_vec
    };

    let raw_source_count = sources_len / 24;
    let mut sources: Vec<Par2SourceEntry> = Vec::with_capacity(raw_source_count);
    for i in 0..raw_source_count {
        let base = i * 24;
        let src_idx = read_ne_i64(sources_bytes, base) as u32;
        if !verified_slice.is_empty() && verified_slice.contains(&src_idx) {
            continue;
        }
        let posted_size = read_ne_i64(sources_bytes, base + 8);
        let segment_count = read_ne_i64(sources_bytes, base + 16);
        if posted_size > 0 && segment_count > 0 {
            sources.push(Par2SourceEntry {
                source_index: src_idx,
                posted_size,
                segment_count,
            });
        }
    }

    let entry_count = sources.len();
    if entry_count == 0 {
        return 0;
    }

    let check_segments = article_part_size > 0;
    if check_segments {
        sources.sort_unstable_by_key(|s| (s.segment_count, s.posted_size));
    } else {
        sources.sort_unstable_by_key(|s| s.posted_size);
    }

    let file_count = files_len / 8;
    let mut diff = vec![0i32; entry_count + 1];
    let mut unique_candidates: Vec<(u32, usize)> = Vec::with_capacity(file_count.min(entry_count));
    let part_size_f64 = article_part_size as f64;

    for file_index in 0..file_count {
        let file_size = read_ne_i64(files_bytes, file_index * 8);
        if file_size <= 0 {
            continue;
        }
        let fsize = file_size as f64;
        let (seg_start, seg_end) = if check_segments {
            let expected_seg = (fsize / part_size_f64).ceil() as i64;
            let s_start = sources.partition_point(|s| s.segment_count < expected_seg);
            let s_end =
                s_start + sources[s_start..].partition_point(|s| s.segment_count == expected_seg);
            (s_start, s_end)
        } else {
            (0, entry_count)
        };
        if seg_start == seg_end {
            continue;
        }

        let seg_slice = &sources[seg_start..seg_end];
        let start =
            seg_slice.partition_point(|s| fsize / (s.posted_size as f64) > PAR2_MAX_SIZE_RATIO);
        let end = start
            + seg_slice[start..]
                .partition_point(|s| fsize / (s.posted_size as f64) >= PAR2_MIN_SIZE_RATIO);
        let fit_count = end - start;
        if fit_count > 0 {
            let g_start = seg_start + start;
            let g_end = seg_start + end;
            diff[g_start] += 1;
            diff[g_end] -= 1;
            if fit_count == 1 {
                unique_candidates.push((file_index as u32, g_start));
            }
        }
    }

    if unique_candidates.is_empty() {
        return 0;
    }

    let mut running = 0i32;
    for d in &mut diff[..entry_count] {
        running += *d;
        *d = running;
    }

    let mut matched: Vec<(u32, u32)> = Vec::with_capacity(unique_candidates.len());
    for (file_index, pos) in unique_candidates {
        if diff[pos] == 1 {
            matched.push((sources[pos].source_index, file_index));
        }
    }

    if matched.is_empty() {
        return 0;
    }

    matched.sort_unstable_by_key(|&(src_idx, _)| src_idx);

    let mut out = unsafe { OutCursor::new(out_ptr, out_cap) };
    for (src_idx, file_idx) in matched {
        out.extend(&src_idx.to_ne_bytes());
        out.extend(&file_idx.to_ne_bytes());
    }
    if out.pos <= out.buf.len() {
        out.pos as isize
    } else {
        -(out.pos.max(8) as isize)
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_candidate_indexes(
    sources_ptr: *const u8,
    sources_len: usize,
    file_size: i64,
    article_part_size: i64,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if (sources_ptr.is_null() && sources_len > 0)
        || (out_ptr.is_null() && out_cap > 0)
        || sources_len % 24 != 0
    {
        return -1;
    }
    if file_size <= 0 || sources_len == 0 {
        return 0;
    }

    let sources_bytes = unsafe { slice::from_raw_parts(sources_ptr, sources_len) };
    let check_segments = article_part_size > 0;
    let fsize = file_size as f64;
    let expected_seg = if check_segments {
        (fsize / (article_part_size as f64)).ceil() as i64
    } else {
        0
    };

    let mut out = unsafe { OutCursor::new(out_ptr, out_cap) };
    let count = sources_len / 24;
    for i in 0..count {
        let base = i * 24;
        let posted_size = read_ne_i64(sources_bytes, base + 8);
        if posted_size <= 0 {
            continue;
        }
        let ratio = fsize / (posted_size as f64);
        if !(PAR2_MIN_SIZE_RATIO..=PAR2_MAX_SIZE_RATIO).contains(&ratio) {
            continue;
        }
        if check_segments {
            let segment_count = read_ne_i64(sources_bytes, base + 16);
            if expected_seg != segment_count {
                continue;
            }
        }
        let src_idx = read_ne_i64(sources_bytes, base) as u32;
        out.extend(&src_idx.to_ne_bytes());
    }

    if out.pos <= out.buf.len() {
        out.pos as isize
    } else {
        -(out.pos.max(4) as isize)
    }
}

// ---------------------------------------------------------------------------
// 7. NzbContentParser::extractMessageIDs batch classifier
// ---------------------------------------------------------------------------

fn matches_compressed_file_pattern(title: &[u8]) -> bool {
    // '/(\.(part\d+|[rz]\d+|rar|0+|0*10?|zipr\d{2,3}|zipx?)("|\s*\.rar)*($|[ ")]|-])|"[a-f0-9]{32}\.[1-9]\d{1,2}".*\(\d+\/\d{2,}\)$)/i'
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

#[inline]
fn ends_with_image_ext_pcre(title: &[u8]) -> bool {
    let trimmed_nl = if title.ends_with(b"\n") {
        &title[..title.len() - 1]
    } else {
        title
    };
    (trimmed_nl.len() >= 4
        && (trimmed_nl[trimmed_nl.len() - 4..].eq_ignore_ascii_case(b".jpg")
            || trimmed_nl[trimmed_nl.len() - 4..].eq_ignore_ascii_case(b".png")))
        || (trimmed_nl.len() >= 5
            && (trimmed_nl[trimmed_nl.len() - 5..].eq_ignore_ascii_case(b".jpeg")
                || trimmed_nl[trimmed_nl.len() - 5..].eq_ignore_ascii_case(b".webp")))
}

#[inline]
fn scan_media_brackets<'a>(
    title: &'a [u8],
    need_jpg: bool,
    need_video: bool,
    need_audio: bool,
) -> (bool, bool, Option<&'a [u8]>) {
    let mut has_jpg_bracket = false;
    let mut has_video_bracket = false;
    let mut audio_ext: Option<&'a [u8]> = None;
    let is_bracket_delim = |b: u8| matches!(b, b'.' | b' ' | b'"' | b')' | b']');

    let mut cur = 0;
    while cur < title.len() {
        let ptr = unsafe { memchr(title[cur..].as_ptr(), b'.' as i32, title.len() - cur) };
        if ptr.is_null() {
            break;
        }
        let i = cur + (ptr as usize - title[cur..].as_ptr() as usize);
        cur = i + 1;
        let rest = &title[i + 1..];

        if need_jpg && !has_jpg_bracket {
            if rest.len() > 3
                && (rest[..3].eq_ignore_ascii_case(b"jpg")
                    || rest[..3].eq_ignore_ascii_case(b"png"))
                && is_bracket_delim(rest[3])
            {
                has_jpg_bracket = true;
            } else if rest.len() > 4
                && (rest[..4].eq_ignore_ascii_case(b"jpeg")
                    || rest[..4].eq_ignore_ascii_case(b"webp"))
                && is_bracket_delim(rest[4])
            {
                has_jpg_bracket = true;
            }
        }

        if need_video && !has_video_bracket {
            for elen in [4usize, 3, 2] {
                if rest.len() > elen
                    && is_video_ext_ci(&rest[..elen])
                    && is_bracket_delim(rest[elen])
                {
                    has_video_bracket = true;
                    break;
                }
            }
        }

        if need_audio && audio_ext.is_none() {
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

        if (!need_jpg || has_jpg_bracket)
            && (!need_video || has_video_bracket)
            && (!need_audio || audio_ext.is_some())
        {
            break;
        }
    }

    (has_jpg_bracket, has_video_bracket, audio_ext)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_extract_message_ids_nul(
    group_ptr: *const u8,
    group_len: usize,
    titles_ptr: *const u8,
    titles_len: usize,
    seg_flags_ptr: *const u8,
    seg_flags_len: usize,
    count: usize,
    config_flags: u32,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if (group_ptr.is_null() && group_len > 0)
        || (titles_ptr.is_null() && titles_len > 0)
        || (seg_flags_ptr.is_null() && seg_flags_len > 0)
        || (out_ptr.is_null() && out_cap > 0)
        || (seg_flags_len != 0 && seg_flags_len != count)
    {
        return -1;
    }
    if out_cap < 36 {
        return -36;
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
    let seg_flags = if seg_flags_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(seg_flags_ptr, seg_flags_len) }
    };

    let want_thumbs = (config_flags & 1) != 0;
    let want_jpg = (config_flags & 2) != 0 && !is_music_group_name(group);
    let want_mediainfo = (config_flags & 4) != 0;
    let want_audio = (config_flags & 8) != 0;

    let mut has_compressed = 0u32;
    let mut book_count = 0u32;
    let mut sample_idx: i32 = -1;
    let mut jpg_idx: i32 = -1;
    let mut mediainfo_idx: i32 = -1;
    let mut audio_idx: i32 = -1;
    let mut audio_ext_slice: &[u8] = &[];

    if count > 0 {
        let mut idx = 0usize;
        let mut prev_clean_rar: Option<(&[u8], u32)> = None;
        for title in titles.split(|&b| b == 0) {
            if idx >= count {
                return -1;
            }
            let file_idx = idx;
            idx += 1;

            let has_segs = seg_flags.is_empty() || seg_flags[file_idx] != b'0';
            let clean_rar = if has_segs {
                try_match_clean_quoted_part_rar(title).filter(|(stem, _)| {
                    !stem.iter().any(|&b| matches!(b, b' ' | b')' | b']'))
                        && !title[..title.iter().position(|&b| b == b'"').unwrap_or(0)]
                            .iter()
                            .any(|b| b.is_ascii_alphabetic())
                })
            } else {
                None
            };
            if let (Some((stem, _)), Some((prev_stem, prev_book))) = (clean_rar, prev_clean_rar) {
                if stem == prev_stem {
                    book_count += prev_book;
                    continue;
                }
            }

            if is_support_file(title) {
                prev_clean_rar = None;
                continue;
            }

            if has_compressed == 0 && matches_compressed_file_pattern(title) {
                has_compressed = 1;
            }

            if has_segs {
                let need_sample = want_thumbs && sample_idx < 0;
                let need_mediainfo = want_mediainfo && mediainfo_idx < 0;
                let has_sample =
                    (need_sample || need_mediainfo) && find_ascii_ci(title, b"sample").is_some();

                if need_sample && has_sample && !ends_with_image_ext_pcre(title) {
                    sample_idx = file_idx as i32;
                }

                let need_jpg_here = want_jpg && jpg_idx < 0;
                let need_video_here = need_mediainfo && has_sample;
                let need_audio_here = want_audio && audio_idx < 0;

                if need_jpg_here || need_video_here || need_audio_here {
                    let (jpg_hit, video_hit, aext) =
                        scan_media_brackets(title, need_jpg_here, need_video_here, need_audio_here);
                    if jpg_hit {
                        jpg_idx = file_idx as i32;
                    }
                    if video_hit {
                        mediainfo_idx = file_idx as i32;
                    }
                    if let Some(ext) = aext {
                        audio_idx = file_idx as i32;
                        audio_ext_slice = ext;
                    }
                }
            }

            let is_book = u32::from(matches_ignore_book(title));
            book_count += is_book;
            prev_clean_rar = clean_rar.map(|(stem, _)| (stem, is_book));
        }
        if idx != count {
            return -1;
        }
    }

    let mut out = unsafe { OutCursor::new(out_ptr, out_cap) };
    out.extend(&has_compressed.to_ne_bytes());
    out.extend(&book_count.to_ne_bytes());
    out.extend(&sample_idx.to_ne_bytes());
    out.extend(&jpg_idx.to_ne_bytes());
    out.extend(&mediainfo_idx.to_ne_bytes());
    out.extend(&audio_idx.to_ne_bytes());
    out.extend(&(audio_ext_slice.len() as u32).to_ne_bytes());
    out.extend(audio_ext_slice);
    out.pos as isize
}

// ---------------------------------------------------------------------------
// 8. NzbContentParser::sourceFileInventory fast XML scanner
// ---------------------------------------------------------------------------

#[inline]
fn is_xml_ws(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\r' | b'\n')
}

#[inline]
fn skip_ws_xml(bytes: &[u8], mut pos: usize) -> usize {
    while pos < bytes.len() && is_xml_ws(bytes[pos]) {
        pos += 1;
    }
    pos
}

fn skip_ws_and_xml_comments(bytes: &[u8], mut pos: usize) -> Option<usize> {
    loop {
        pos = skip_ws_xml(bytes, pos);
        if bytes.get(pos..pos + 4) == Some(b"<!--") {
            pos += 4;
            let mut found = false;
            while pos + 2 <= bytes.len() {
                if &bytes[pos..pos + 2] == b"--" {
                    if bytes.get(pos + 2) == Some(&b'>') {
                        pos += 3;
                        found = true;
                        break;
                    }
                    return None;
                }
                pos += 1;
            }
            if !found {
                return None;
            }
        } else {
            return Some(pos);
        }
    }
}

fn unescape_or_validate_xml_ascii(raw: &[u8], mut dst: Option<&mut Vec<u8>>) -> bool {
    if raw
        .iter()
        .all(|&b| (0x20..0x7F).contains(&b) && b != b'<' && b != b'&')
    {
        if let Some(out) = dst {
            out.extend_from_slice(raw);
        }
        return true;
    }
    let mut i = 0;
    let n = raw.len();
    while i < n {
        let b = raw[i];
        if b == b'<' || !(0x20..0x7F).contains(&b) {
            return false;
        }
        if b != b'&' {
            if let Some(out) = dst.as_deref_mut() {
                out.push(b);
            }
            i += 1;
            continue;
        }
        let rest = &raw[i + 1..];
        let Some(semi) = rest.iter().take(12).position(|&c| c == b';') else {
            return false;
        };
        let ent = &rest[..semi];
        match ent {
            b"amp" => {
                if let Some(out) = dst.as_deref_mut() {
                    out.push(b'&');
                }
            }
            b"lt" => {
                if let Some(out) = dst.as_deref_mut() {
                    out.push(b'<');
                }
            }
            b"gt" => {
                if let Some(out) = dst.as_deref_mut() {
                    out.push(b'>');
                }
            }
            b"quot" => {
                if let Some(out) = dst.as_deref_mut() {
                    out.push(b'"');
                }
            }
            b"apos" => {
                if let Some(out) = dst.as_deref_mut() {
                    out.push(b'\'');
                }
            }
            _ if ent.starts_with(b"#x") || ent.starts_with(b"#X") => {
                let hex_digits = &ent[2..];
                if hex_digits.is_empty()
                    || hex_digits.len() > 6
                    || !hex_digits.iter().all(u8::is_ascii_hexdigit)
                {
                    return false;
                }
                let Ok(s) = std::str::from_utf8(hex_digits) else {
                    return false;
                };
                let Ok(code) = u32::from_str_radix(s, 16) else {
                    return false;
                };
                if !(0x20..0x7F).contains(&code) {
                    return false;
                }
                if let Some(out) = dst.as_deref_mut() {
                    out.push(code as u8);
                }
            }
            _ if ent.starts_with(b"#") => {
                let dec_digits = &ent[1..];
                if dec_digits.is_empty()
                    || dec_digits.len() > 7
                    || !dec_digits.iter().all(u8::is_ascii_digit)
                {
                    return false;
                }
                let Ok(s) = std::str::from_utf8(dec_digits) else {
                    return false;
                };
                let Ok(code) = s.parse::<u32>() else {
                    return false;
                };
                if !(0x20..0x7F).contains(&code) {
                    return false;
                }
                if let Some(out) = dst.as_deref_mut() {
                    out.push(code as u8);
                }
            }
            _ => return false,
        }
        i += 1 + semi + 1;
    }
    true
}

#[inline]
fn match_xml_tag(bytes: &[u8], pos: usize, tag_exact: &[u8]) -> Option<usize> {
    let end = pos + tag_exact.len();
    if bytes.get(pos..end) != Some(tag_exact) {
        return None;
    }
    let next = bytes.get(end).copied()?;
    if is_xml_ws(next) || next == b'>' || next == b'/' {
        Some(end)
    } else {
        None
    }
}

#[inline]
fn expect_xml_close(bytes: &[u8], pos: usize, close_exact: &[u8]) -> Option<usize> {
    let end = pos + close_exact.len();
    if bytes.get(pos..end) != Some(close_exact) {
        return None;
    }
    let after_ws = skip_ws_xml(bytes, end);
    if bytes.get(after_ws) == Some(&b'>') {
        Some(after_ws + 1)
    } else {
        None
    }
}

fn parse_attrs_xml<'a, F>(
    bytes: &'a [u8],
    mut pos: usize,
    allow_xmlns: bool,
    mut on_attr: F,
) -> Option<(usize, bool)>
where
    F: FnMut(&'a [u8], &'a [u8]) -> bool,
{
    let mut seen_names: [&[u8]; 8] = [&[]; 8];
    let mut seen_count = 0usize;

    loop {
        let prev_pos = pos;
        pos = skip_ws_xml(bytes, pos);
        let b = *bytes.get(pos)?;
        if b == b'>' {
            return Some((pos + 1, false));
        }
        if b == b'/' {
            if bytes.get(pos + 1) == Some(&b'>') {
                return Some((pos + 2, true));
            }
            return None;
        }
        if seen_count > 0 && pos == prev_pos {
            return None;
        }
        if !b.is_ascii_alphabetic() && b != b'_' {
            return None;
        }
        let name_start = pos;
        pos += 1;
        while pos < bytes.len() {
            let c = bytes[pos];
            if c == b':' {
                return None;
            }
            if c.is_ascii_alphanumeric() || matches!(c, b'_' | b'-' | b'.') {
                pos += 1;
            } else {
                break;
            }
        }
        let attr_name = &bytes[name_start..pos];
        if !allow_xmlns && attr_name == b"xmlns" {
            return None;
        }
        if seen_count >= seen_names.len() || seen_names[..seen_count].contains(&attr_name) {
            return None;
        }
        seen_names[seen_count] = attr_name;
        seen_count += 1;

        pos = skip_ws_xml(bytes, pos);
        if bytes.get(pos) != Some(&b'=') {
            return None;
        }
        pos = skip_ws_xml(bytes, pos + 1);
        let quote = *bytes.get(pos)?;
        if quote != b'"' && quote != b'\'' {
            return None;
        }
        pos += 1;
        let val_start = pos;
        while pos < bytes.len() && bytes[pos] != quote {
            pos += 1;
        }
        if pos >= bytes.len() {
            return None;
        }
        let attr_val = &bytes[val_start..pos];
        pos += 1;
        if !unescape_or_validate_xml_ascii(attr_val, None) || !on_attr(attr_name, attr_val) {
            return None;
        }
    }
}

fn parse_decl_xml(bytes: &[u8], mut pos: usize) -> Option<usize> {
    if bytes.get(pos..pos + 5) != Some(b"<?xml") {
        return Some(pos);
    }
    let after_kw = pos + 5;
    if !bytes.get(after_kw).copied().is_some_and(is_xml_ws) {
        return None;
    }
    pos = after_kw;
    let mut seen_version = false;
    let mut seen_encoding = false;
    let mut seen_standalone = false;

    loop {
        let prev = pos;
        pos = skip_ws_xml(bytes, pos);
        if bytes.get(pos..pos + 2) == Some(b"?>") {
            return if seen_version { Some(pos + 2) } else { None };
        }
        if pos == prev {
            return None;
        }
        let name_start = pos;
        while pos < bytes.len() && bytes[pos].is_ascii_alphabetic() {
            pos += 1;
        }
        let name = &bytes[name_start..pos];
        pos = skip_ws_xml(bytes, pos);
        if bytes.get(pos) != Some(&b'=') {
            return None;
        }
        pos = skip_ws_xml(bytes, pos + 1);
        let quote = *bytes.get(pos)?;
        if quote != b'"' && quote != b'\'' {
            return None;
        }
        pos += 1;
        let val_start = pos;
        while pos < bytes.len() && bytes[pos] != quote {
            pos += 1;
        }
        if pos >= bytes.len() {
            return None;
        }
        let val = &bytes[val_start..pos];
        pos += 1;

        match name {
            b"version" => {
                if seen_version || seen_encoding || seen_standalone || val != b"1.0" {
                    return None;
                }
                seen_version = true;
            }
            b"encoding" => {
                if !seen_version
                    || seen_encoding
                    || seen_standalone
                    || !val.eq_ignore_ascii_case(b"utf-8")
                {
                    return None;
                }
                seen_encoding = true;
            }
            b"standalone" => {
                if !seen_version || seen_standalone || (val != b"yes" && val != b"no") {
                    return None;
                }
                seen_standalone = true;
            }
            _ => return None,
        }
    }
}

/// Matches `^(?:\s+yEnc)?(?:\s+\(\d+\/(\d+)\))?\s*$` (case-insensitive, `/D`).
/// Returns `Some(Some(total_digits_slice))` if `(\d+/(\d+))` matched,
/// `Some(None)` if matched without `(\d+/(\d+))`, or `None` if no match.
fn match_inventory_subject_suffix(rem: &[u8]) -> Option<Option<&[u8]>> {
    fn match_paren_and_end(s: &[u8]) -> Option<&[u8]> {
        let mut p = 0;
        while p < s.len() && is_pcre_space(s[p]) {
            p += 1;
        }
        if p == 0 || p >= s.len() || s[p] != b'(' {
            return None;
        }
        p += 1;
        let d1 = p;
        while p < s.len() && s[p].is_ascii_digit() {
            p += 1;
        }
        if p == d1 || p >= s.len() || s[p] != b'/' {
            return None;
        }
        p += 1;
        let d2 = p;
        while p < s.len() && s[p].is_ascii_digit() {
            p += 1;
        }
        if p == d2 || p >= s.len() || s[p] != b')' {
            return None;
        }
        let digits2 = &s[d2..p];
        p += 1;
        while p < s.len() && is_pcre_space(s[p]) {
            p += 1;
        }
        if p == s.len() { Some(digits2) } else { None }
    }

    // Try with `\s+yEnc` first (greedy `(?:\s+yEnc)?`)
    let mut ws = 0;
    while ws < rem.len() && is_pcre_space(rem[ws]) {
        ws += 1;
    }
    if ws > 0 && ws + 4 <= rem.len() && rem[ws..ws + 4].eq_ignore_ascii_case(b"yenc") {
        let after_yenc = &rem[ws + 4..];
        if let Some(digits2) = match_paren_and_end(after_yenc) {
            return Some(Some(digits2));
        }
        if after_yenc.iter().all(|&b| is_pcre_space(b)) {
            return Some(None);
        }
    }

    // Try without `\s+yEnc`: first with `\s+\(\d+/(\d+)\)\s*$`, then `\s*$`
    if let Some(digits2) = match_paren_and_end(rem) {
        return Some(Some(digits2));
    }
    if rem.iter().all(|&b| is_pcre_space(b)) {
        return Some(None);
    }
    None
}

fn match_inventory_subject(subject: &[u8]) -> Option<(&[u8], Option<&[u8]>)> {
    if let Some(q1) = subject.iter().position(|&b| b == b'"') {
        if subject[..q1].iter().any(|&b| b == b'\r' || b == b'\n') {
            return None;
        }
        let after_q1 = &subject[q1 + 1..];
        let q2_rel = after_q1.iter().position(|&b| b == b'"')?;
        if q2_rel == 0 {
            return None;
        }
        let inner = &after_q1[..q2_rel];
        if inner.iter().any(|&b| b == b'\r' || b == b'\n') {
            return None;
        }
        let tail = &after_q1[q2_rel + 1..];
        let opt_parts = match_inventory_subject_suffix(tail)?;
        return Some((inner, opt_parts));
    }

    // Branch 2: `^([^"\r\n]+?)(?:\s+yEnc)?(?:\s+\(\d+\/(\d+)\))?\s*$`
    if subject.is_empty() || matches!(subject[0], b'\r' | b'\n') {
        return None;
    }
    let max_pos = subject
        .iter()
        .position(|&b| b == b'\r' || b == b'\n')
        .unwrap_or(subject.len());
    for pos in 1..=max_pos {
        if pos == subject.len() || is_pcre_space(subject[pos]) {
            if let Some(opt_parts) = match_inventory_subject_suffix(&subject[pos..]) {
                return Some((&subject[..pos], opt_parts));
            }
        }
    }
    None
}

fn parse_u64_ascii_digits(raw: &[u8]) -> Option<u64> {
    if raw.is_empty() || !raw.iter().all(|b| b.is_ascii_digit()) {
        return None;
    }
    let mut val = 0u64;
    for &b in raw {
        val = val.checked_mul(10)?.checked_add(u64::from(b - b'0'))?;
    }
    Some(val)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_source_file_inventory(
    xml_ptr: *const u8,
    xml_len: usize,
    expected_file_count: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if (xml_ptr.is_null() && xml_len > 0) || (out_ptr.is_null() && out_cap > 0) {
        return -1;
    }
    if expected_file_count == 0 || expected_file_count > 256 || xml_len == 0 {
        return -2;
    }

    let bytes = unsafe { slice::from_raw_parts(xml_ptr, xml_len) };
    if find_ascii_ci(bytes, b"<!entity").is_some() {
        return -2;
    }

    let mut pos = 0;
    if bytes.starts_with(b"\xEF\xBB\xBF") {
        pos = 3;
    }

    for &b in &bytes[pos..] {
        if (b < 0x20 && !matches!(b, b'\t' | b'\n' | b'\r')) || b >= 0x7F {
            return -1;
        }
    }

    let Some(after_decl) = parse_decl_xml(bytes, pos) else {
        return -1;
    };
    pos = after_decl;

    let mut seen_doctype = false;
    loop {
        let Some(next_pos) = skip_ws_and_xml_comments(bytes, pos) else {
            return -1;
        };
        pos = next_pos;
        if match_xml_tag(bytes, pos, b"<!DOCTYPE").is_some() {
            if seen_doctype {
                return -1;
            }
            seen_doctype = true;
            pos = skip_ws_xml(bytes, pos + 9);
            let Some(after_name) = match_xml_tag(bytes, pos, b"nzb") else {
                return -1;
            };
            pos = skip_ws_xml(bytes, after_name);
            if let Some(after_pub) = match_xml_tag(bytes, pos, b"PUBLIC") {
                pos = skip_ws_xml(bytes, after_pub);
                for qi in 0..2 {
                    if qi == 1 {
                        let prev = pos;
                        pos = skip_ws_xml(bytes, pos);
                        if pos == prev {
                            return -1;
                        }
                    }
                    let Some(&q) = bytes.get(pos) else {
                        return -1;
                    };
                    if q != b'"' && q != b'\'' {
                        return -1;
                    }
                    pos += 1;
                    while pos < bytes.len() && bytes[pos] != q {
                        if matches!(bytes[pos], b'<' | b'>' | b'[') {
                            return -1;
                        }
                        pos += 1;
                    }
                    if bytes.get(pos) != Some(&q) {
                        return -1;
                    }
                    pos += 1;
                }
                pos = skip_ws_xml(bytes, pos);
            } else if let Some(after_sys) = match_xml_tag(bytes, pos, b"SYSTEM") {
                pos = skip_ws_xml(bytes, after_sys);
                let Some(&q) = bytes.get(pos) else {
                    return -1;
                };
                if q != b'"' && q != b'\'' {
                    return -1;
                }
                pos += 1;
                while pos < bytes.len() && bytes[pos] != q {
                    if matches!(bytes[pos], b'<' | b'>' | b'[') {
                        return -1;
                    }
                    pos += 1;
                }
                if bytes.get(pos) != Some(&q) {
                    return -1;
                }
                pos = skip_ws_xml(bytes, pos + 1);
            }
            if bytes.get(pos) != Some(&b'>') {
                return -1;
            }
            pos += 1;
        } else {
            break;
        }
    }

    let Some(nzb_attr_start) = match_xml_tag(bytes, pos, b"<nzb") else {
        return -1;
    };
    let Some((after_nzb_open, nzb_self_closing)) =
        parse_attrs_xml(bytes, nzb_attr_start, true, |_k, _v| true)
    else {
        return -1;
    };
    pos = after_nzb_open;
    if nzb_self_closing {
        let Some(end_pos) = skip_ws_and_xml_comments(bytes, pos) else {
            return -1;
        };
        return if end_pos == bytes.len() { -2 } else { -1 };
    }

    let mut out = unsafe { OutCursor::new(out_ptr, out_cap) };
    let mut file_count = 0usize;
    let mut seen_head_block = false;
    let mut max_meta_file_count = 0u64;
    let mut meta_invalid = false;
    let mut inventory_invalid = false;
    let mut name_bytes_total = 0usize;

    let mut subject_buf = Vec::with_capacity(256);
    let mut meta_type_buf = Vec::with_capacity(64);
    let mut meta_val_buf = Vec::with_capacity(64);
    let mut seg_num_buf = Vec::with_capacity(16);
    let mut seg_text_buf = Vec::with_capacity(128);
    let mut first_seg_buf = Vec::with_capacity(128);
    let mut seen_first_ids: Vec<Vec<u8>> = Vec::with_capacity(expected_file_count);
    let mut overflow_seg_nums: Vec<u64> = Vec::new();

    loop {
        let Some(next_pos) = skip_ws_and_xml_comments(bytes, pos) else {
            return -1;
        };
        pos = next_pos;

        if let Some(after_nzb_close) = expect_xml_close(bytes, pos, b"</nzb") {
            let Some(end_pos) = skip_ws_and_xml_comments(bytes, after_nzb_close) else {
                return -1;
            };
            if end_pos != bytes.len() {
                return -1;
            }
            break;
        }

        if let Some(head_attr_start) = match_xml_tag(bytes, pos, b"<head") {
            if seen_head_block {
                return -1;
            }
            seen_head_block = true;
            let Some((after_head_open, head_self_closing)) =
                parse_attrs_xml(bytes, head_attr_start, false, |_k, _v| true)
            else {
                return -1;
            };
            pos = after_head_open;
            if !head_self_closing {
                loop {
                    let Some(p) = skip_ws_and_xml_comments(bytes, pos) else {
                        return -1;
                    };
                    pos = p;
                    if let Some(after_head_close) = expect_xml_close(bytes, pos, b"</head") {
                        pos = after_head_close;
                        break;
                    }
                    if let Some(meta_attr_start) = match_xml_tag(bytes, pos, b"<meta") {
                        meta_type_buf.clear();
                        let mut seen_type = false;
                        let Some((after_meta_open, meta_self_closing)) =
                            parse_attrs_xml(bytes, meta_attr_start, false, |k, v| {
                                if k == b"type" {
                                    if seen_type {
                                        return false;
                                    }
                                    seen_type = true;
                                    return unescape_or_validate_xml_ascii(
                                        v,
                                        Some(&mut meta_type_buf),
                                    );
                                }
                                true
                            })
                        else {
                            return -1;
                        };
                        pos = after_meta_open;
                        meta_val_buf.clear();
                        if !meta_self_closing {
                            let text_start = pos;
                            while pos < bytes.len() && bytes[pos] != b'<' {
                                pos += 1;
                            }
                            if !unescape_or_validate_xml_ascii(
                                &bytes[text_start..pos],
                                Some(&mut meta_val_buf),
                            ) {
                                return -1;
                            }
                            let Some(after_meta_close) = expect_xml_close(bytes, pos, b"</meta")
                            else {
                                return -1;
                            };
                            pos = after_meta_close;
                        }
                        if meta_type_buf == b"nntmux:declared-file-count"
                            || meta_type_buf == b"nntmux:verified-file-count"
                        {
                            match parse_u64_ascii_digits(&meta_val_buf) {
                                Some(cnt) => {
                                    if cnt > max_meta_file_count {
                                        max_meta_file_count = cnt;
                                    }
                                }
                                None => meta_invalid = true,
                            }
                        }
                    } else {
                        return -1;
                    }
                }
            }
            continue;
        }

        let Some(file_attr_start) = match_xml_tag(bytes, pos, b"<file") else {
            return -1;
        };
        subject_buf.clear();
        let mut seen_subject = false;
        let Some((after_file_open, file_self_closing)) =
            parse_attrs_xml(bytes, file_attr_start, false, |k, v| {
                if k == b"subject" {
                    if seen_subject {
                        return false;
                    }
                    seen_subject = true;
                    return unescape_or_validate_xml_ascii(v, Some(&mut subject_buf));
                }
                true
            })
        else {
            return -1;
        };
        pos = after_file_open;
        file_count += 1;

        let mut seen_groups_block = false;
        let mut seen_segments_block = false;
        let mut seg_count = 0usize;
        let mut max_seg_num = 0u64;
        let mut seg_bits = [0u64; 16];
        overflow_seg_nums.clear();
        first_seg_buf.clear();

        if !file_self_closing {
            loop {
                let Some(p) = skip_ws_and_xml_comments(bytes, pos) else {
                    return -1;
                };
                pos = p;
                if let Some(after_file_close) = expect_xml_close(bytes, pos, b"</file") {
                    pos = after_file_close;
                    break;
                }
                if let Some(groups_attr_start) = match_xml_tag(bytes, pos, b"<groups") {
                    if seen_groups_block {
                        return -1;
                    }
                    seen_groups_block = true;
                    let Some((after_groups_open, groups_self_closing)) =
                        parse_attrs_xml(bytes, groups_attr_start, false, |_k, _v| true)
                    else {
                        return -1;
                    };
                    pos = after_groups_open;
                    if !groups_self_closing {
                        loop {
                            let Some(gp) = skip_ws_and_xml_comments(bytes, pos) else {
                                return -1;
                            };
                            pos = gp;
                            if let Some(after_groups_close) =
                                expect_xml_close(bytes, pos, b"</groups")
                            {
                                pos = after_groups_close;
                                break;
                            }
                            let Some(group_attr_start) = match_xml_tag(bytes, pos, b"<group")
                            else {
                                return -1;
                            };
                            let Some((after_group_open, group_self_closing)) =
                                parse_attrs_xml(bytes, group_attr_start, false, |_k, _v| true)
                            else {
                                return -1;
                            };
                            pos = after_group_open;
                            if !group_self_closing {
                                let text_start = pos;
                                while pos < bytes.len() && bytes[pos] != b'<' {
                                    pos += 1;
                                }
                                if !unescape_or_validate_xml_ascii(&bytes[text_start..pos], None) {
                                    return -1;
                                }
                                let Some(after_group_close) =
                                    expect_xml_close(bytes, pos, b"</group")
                                else {
                                    return -1;
                                };
                                pos = after_group_close;
                            }
                        }
                    }
                } else if let Some(segs_attr_start) = match_xml_tag(bytes, pos, b"<segments") {
                    if seen_segments_block {
                        return -1;
                    }
                    seen_segments_block = true;
                    let Some((after_segs_open, segs_self_closing)) =
                        parse_attrs_xml(bytes, segs_attr_start, false, |_k, _v| true)
                    else {
                        return -1;
                    };
                    pos = after_segs_open;
                    if !segs_self_closing {
                        loop {
                            let Some(sp) = skip_ws_and_xml_comments(bytes, pos) else {
                                return -1;
                            };
                            pos = sp;
                            if let Some(after_segs_close) =
                                expect_xml_close(bytes, pos, b"</segments")
                            {
                                pos = after_segs_close;
                                break;
                            }
                            let Some(seg_attr_start) = match_xml_tag(bytes, pos, b"<segment")
                            else {
                                return -1;
                            };
                            seg_num_buf.clear();
                            let mut seen_num = false;
                            let Some((after_seg_open, seg_self_closing)) =
                                parse_attrs_xml(bytes, seg_attr_start, false, |k, v| {
                                    if k == b"number" {
                                        if seen_num {
                                            return false;
                                        }
                                        seen_num = true;
                                        return unescape_or_validate_xml_ascii(
                                            v,
                                            Some(&mut seg_num_buf),
                                        );
                                    }
                                    true
                                })
                            else {
                                return -1;
                            };
                            pos = after_seg_open;
                            if seg_self_closing {
                                inventory_invalid = true;
                                continue;
                            }
                            let text_start = pos;
                            while pos < bytes.len() && bytes[pos] != b'<' {
                                pos += 1;
                            }
                            seg_text_buf.clear();
                            if !unescape_or_validate_xml_ascii(
                                &bytes[text_start..pos],
                                Some(&mut seg_text_buf),
                            ) {
                                return -1;
                            }
                            let Some(after_seg_close) = expect_xml_close(bytes, pos, b"</segment")
                            else {
                                return -1;
                            };
                            pos = after_seg_close;

                            if inventory_invalid {
                                continue;
                            }
                            let Some(seg_num) = parse_u64_ascii_digits(&seg_num_buf) else {
                                inventory_invalid = true;
                                continue;
                            };
                            if seg_num < 1 || trim_php(&seg_text_buf).is_empty() {
                                inventory_invalid = true;
                                continue;
                            }
                            if seg_num <= 1024 {
                                let bit_idx = (seg_num - 1) as usize;
                                let word = bit_idx >> 6;
                                let mask = 1u64 << (bit_idx & 63);
                                if (seg_bits[word] & mask) != 0 {
                                    inventory_invalid = true;
                                    continue;
                                }
                                seg_bits[word] |= mask;
                            } else {
                                if overflow_seg_nums.contains(&seg_num) {
                                    inventory_invalid = true;
                                    continue;
                                }
                                overflow_seg_nums.push(seg_num);
                            }
                            seg_count += 1;
                            if seg_num > max_seg_num {
                                max_seg_num = seg_num;
                            }
                            if seg_num == 1 {
                                first_seg_buf.extend_from_slice(&seg_text_buf);
                            }
                        }
                    }
                } else {
                    return -1;
                }
            }
        }

        if inventory_invalid || file_count > 256 {
            inventory_invalid = true;
            continue;
        }

        let Some((raw_name, opt_parts)) = match_inventory_subject(&subject_buf) else {
            inventory_invalid = true;
            continue;
        };
        let name = trim_php(raw_name);
        name_bytes_total += name.len();
        if name.is_empty()
            || name.len() > 4096
            || name_bytes_total > 32768
            || name.iter().any(|&b| b <= 0x1F || b == 0x7F)
            || matches!(name[0], b'.' | b'-')
            || name.contains(&b'/')
            || name.contains(&b'\\')
        {
            inventory_invalid = true;
            continue;
        }

        if seg_count == 0 || max_seg_num != seg_count as u64 {
            inventory_invalid = true;
            continue;
        }
        if let Some(parts_digits) = opt_parts {
            match parse_u64_ascii_digits(parts_digits) {
                Some(expected_segs) if expected_segs == seg_count as u64 => {}
                _ => {
                    inventory_invalid = true;
                    continue;
                }
            }
        }
        if seen_first_ids
            .iter()
            .any(|existing| existing.as_slice() == first_seg_buf.as_slice())
        {
            inventory_invalid = true;
            continue;
        }
        seen_first_ids.push(first_seg_buf.clone());

        if file_count > 1 {
            out.push(0);
        }
        out.extend(name);
        out.push(0);
        out.extend(&first_seg_buf);
    }

    if meta_invalid
        || inventory_invalid
        || file_count == 0
        || file_count > 256
        || file_count != expected_file_count
        || max_meta_file_count > file_count as u64
    {
        return -2;
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

    #[test]
    fn test_par2_map_and_candidate_indexes() {
        let mut sources_buf = Vec::new();
        for &(idx, size, segs) in &[(0i64, 3000i64, 1i64), (1, 50000, 1), (2, 60000, 1)] {
            sources_buf.extend_from_slice(&idx.to_ne_bytes());
            sources_buf.extend_from_slice(&size.to_ne_bytes());
            sources_buf.extend_from_slice(&segs.to_ne_bytes());
        }
        let mut files_buf = Vec::new();
        for &fsize in &[2900i64, 48500] {
            files_buf.extend_from_slice(&fsize.to_ne_bytes());
        }
        let mut out = [0u8; 64];
        let written = unsafe {
            nntmux_par2_map_files(
                sources_buf.as_ptr(),
                sources_buf.len(),
                files_buf.as_ptr(),
                files_buf.len(),
                std::ptr::null(),
                0,
                0,
                out.as_mut_ptr(),
                out.len(),
            )
        };
        assert_eq!(written, 16);
        let s0 = u32::from_ne_bytes(out[0..4].try_into().unwrap());
        let f0 = u32::from_ne_bytes(out[4..8].try_into().unwrap());
        let s1 = u32::from_ne_bytes(out[8..12].try_into().unwrap());
        let f1 = u32::from_ne_bytes(out[12..16].try_into().unwrap());
        assert_eq!((s0, f0), (0, 0));
        assert_eq!((s1, f1), (1, 1));

        let cand_written = unsafe {
            nntmux_par2_candidate_indexes(
                sources_buf.as_ptr(),
                sources_buf.len(),
                2900,
                0,
                out.as_mut_ptr(),
                out.len(),
            )
        };
        assert_eq!(cand_written, 4);
        assert_eq!(u32::from_ne_bytes(out[0..4].try_into().unwrap()), 0);
    }

    #[test]
    fn test_nzb_source_file_inventory_and_extract_message_ids() {
        let xml = br#"<?xml version="1.0" encoding="UTF-8"?>
<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
  <head>
    <meta type="nntmux:declared-file-count">2</meta>
  </head>
  <file poster="p" date="1" subject="&quot;Movie.2026.mkv&quot; yEnc (1/2)">
    <groups><group>alt.binaries.movies</group></groups>
    <segments>
      <segment bytes="100" number="2">seg2@example.com</segment>
      <segment bytes="100" number="1">seg1@example.com</segment>
    </segments>
  </file>
  <file poster="p" date="1" subject="Sample.2026.mkv yEnc (1/1)">
    <groups><group>alt.binaries.movies</group></groups>
    <segments>
      <segment bytes="100" number="1">sample1@example.com</segment>
    </segments>
  </file>
</nzb>"#;
        let mut out = [0u8; 256];
        let written = unsafe {
            nntmux_nzb_source_file_inventory(
                xml.as_ptr(),
                xml.len(),
                2,
                out.as_mut_ptr(),
                out.len(),
            )
        };
        assert!(written > 0);
        let fields: Vec<&[u8]> = out[..written as usize].split(|&b| b == 0).collect();
        assert_eq!(
            fields,
            vec![
                b"Movie.2026.mkv".as_slice(),
                b"seg1@example.com".as_slice(),
                b"Sample.2026.mkv".as_slice(),
                b"sample1@example.com".as_slice()
            ]
        );

        let titles = b"\"Movie.part01.rar\" yEnc (1/10)\0\"Movie.sample.mkv\" yEnc (1/2)\0\"Movie.cover.jpg\" yEnc (1/1)\0\"Track.flac\" yEnc (1/1)";
        let group = b"alt.binaries.movies";
        let m_written = unsafe {
            nntmux_nzb_extract_message_ids_nul(
                group.as_ptr(),
                group.len(),
                titles.as_ptr(),
                titles.len(),
                std::ptr::null(),
                0,
                4,
                0x0F,
                out.as_mut_ptr(),
                out.len(),
            )
        };
        assert_eq!(m_written, 32);
        let has_comp = u32::from_ne_bytes(out[0..4].try_into().unwrap());
        let sample_idx = i32::from_ne_bytes(out[8..12].try_into().unwrap());
        let jpg_idx = i32::from_ne_bytes(out[12..16].try_into().unwrap());
        let mi_idx = i32::from_ne_bytes(out[16..20].try_into().unwrap());
        let audio_idx = i32::from_ne_bytes(out[20..24].try_into().unwrap());
        assert_eq!(has_comp, 1);
        assert_eq!(sample_idx, 1);
        assert_eq!(jpg_idx, 2);
        assert_eq!(mi_idx, 1);
        assert_eq!(audio_idx, 3);
        assert_eq!(&out[28..32], b"flac");
    }
}

// Coverage intentionally accepts a narrow XML subset. Anything whose namespace,
// entity or normalization semantics need libxml is declined to the PHP oracle.
fn coverage_decode(raw: &[u8]) -> Result<std::borrow::Cow<'_, [u8]>, isize> {
    if !raw
        .iter()
        .all(|&b| b.is_ascii() && (b >= 0x20 || is_xml_ws(b)))
    {
        return Err(-1);
    }
    if !raw.contains(&b'&') {
        return Ok(std::borrow::Cow::Borrowed(raw));
    }
    let mut decoded = Vec::with_capacity(raw.len());
    let mut pos = 0;
    while pos < raw.len() {
        if raw[pos] != b'&' {
            decoded.push(raw[pos]);
            pos += 1;
            continue;
        }
        let end = raw[pos + 1..]
            .iter()
            .position(|&b| b == b';')
            .ok_or(-1isize)?
            + pos
            + 1;
        let entity = &raw[pos + 1..end];
        let code = match entity {
            b"amp" => b'&',
            b"lt" => b'<',
            b"gt" => b'>',
            b"quot" => b'"',
            b"apos" => b'\'',
            _ => {
                let (digits, radix) = if entity.starts_with(b"#x") {
                    (&entity[2..], 16)
                } else if entity.starts_with(b"#") {
                    (&entity[1..], 10)
                } else {
                    return Err(-1);
                };
                if digits.is_empty()
                    || !digits.iter().all(|b| {
                        if radix == 16 {
                            b.is_ascii_hexdigit()
                        } else {
                            b.is_ascii_digit()
                        }
                    })
                {
                    return Err(-1);
                }
                let text = std::str::from_utf8(digits).map_err(|_| -1isize)?;
                let code = u32::from_str_radix(text, radix).map_err(|_| -1isize)?;
                if code > 127 || !(code >= 32 || matches!(code, 9 | 10 | 13)) {
                    return Err(-1);
                }
                code as u8
            }
        };
        decoded.push(code);
        pos = end + 1;
    }
    Ok(std::borrow::Cow::Owned(decoded))
}

#[derive(Clone, Copy)]
enum CoverageElement {
    Root,
    File,
    Segments,
    Ignored,
}

fn coverage_ids_xml(xml: &[u8]) -> Result<Vec<u8>, isize> {
    if xml.len() > 16_777_216 {
        return Err(-2);
    }
    let mut pos = skip_ws_xml(xml, 0);
    pos = parse_decl_xml(xml, pos).ok_or(-1isize)?;
    let mut stack: Vec<(&[u8], CoverageElement, usize)> = Vec::new();
    let mut root_seen = false;
    let mut files = 0;
    let mut segments = 0;
    let mut ids = std::collections::HashSet::<Vec<u8>>::new();
    let mut output = Vec::new();
    loop {
        let text_start = pos;
        while pos < xml.len() && xml[pos] != b'<' {
            pos += 1;
        }
        if stack.is_empty() && !xml[text_start..pos].iter().copied().all(is_xml_ws) {
            return Err(-1);
        }
        if pos == xml.len() {
            return if root_seen && stack.is_empty() && files > 0 {
                Ok(output)
            } else {
                Err(-2)
            };
        }
        if matches!(xml.get(pos + 1), Some(b'!' | b'?')) {
            return Err(-1);
        }
        if xml.get(pos + 1) == Some(&b'/') {
            let (name, kind, children) = stack.pop().ok_or(-1isize)?;
            pos = expect_xml_close(xml, pos, &[b"</", name].concat()).ok_or(-1isize)?;
            match kind {
                CoverageElement::File if children != 1 => return Err(-2),
                CoverageElement::Segments if children == 0 => return Err(-2),
                _ => {}
            }
            continue;
        }
        let start = pos + 1;
        pos = start;
        while pos < xml.len()
            && (xml[pos].is_ascii_alphanumeric() || matches!(xml[pos], b'_' | b'-' | b'.'))
        {
            pos += 1;
        }
        let name = &xml[start..pos];
        if name.is_empty() || xml.get(pos) == Some(&b':') {
            return Err(-1);
        }
        let is_root = stack.is_empty();
        let mut number = None;
        let mut bytes = None;
        let (after, empty) = parse_attrs_xml(xml, pos, is_root, |key, value| {
            match key {
                b"xmlns" => {
                    return is_root
                        && matches!(value, b"" | b"http://www.newzbin.com/DTD/2003/nzb");
                }
                b"number" => number = Some(value),
                b"bytes" => bytes = Some(value),
                _ => {}
            }
            true
        })
        .ok_or(-1isize)?;
        pos = after;
        let kind = match stack.last_mut() {
            None => {
                if root_seen || name != b"nzb" {
                    return Err(-1);
                }
                root_seen = true;
                CoverageElement::Root
            }
            Some((_, CoverageElement::Root, _)) => match name {
                b"file" => {
                    files += 1;
                    CoverageElement::File
                }
                b"head" => CoverageElement::Ignored,
                _ => return Err(-1), // PHP permits extra root children.
            },
            Some((_, CoverageElement::File, count)) => match name {
                b"segments" => {
                    *count += 1;
                    CoverageElement::Segments
                }
                b"groups" => CoverageElement::Ignored,
                _ => return Err(-1), // PHP permits extra file children.
            },
            Some((_, CoverageElement::Segments, count)) => {
                if name != b"segment" {
                    return Err(-2);
                }
                *count += 1;
                segments += 1;
                if segments > 100_000 || empty {
                    return Err(-2);
                }
                let number = coverage_decode(number.ok_or(-2isize)?)?;
                let bytes = coverage_decode(bytes.ok_or(-2isize)?)?;
                if number.is_empty()
                    || number[0] == b'0'
                    || !number.iter().all(u8::is_ascii_digit)
                    || bytes.is_empty()
                    || !bytes.iter().all(u8::is_ascii_digit)
                {
                    return Err(-2);
                }
                let end = xml[pos..].iter().position(|&b| b == b'<').ok_or(-1isize)? + pos;
                let decoded = coverage_decode(&xml[pos..end])?;
                pos = if let Some(after) = expect_xml_close(xml, end, b"</segment") {
                    after
                } else {
                    return Err(if matches!(xml.get(end + 1), Some(b'!' | b'?')) {
                        -1
                    } else {
                        -2
                    });
                };
                let mut id = trim_php(&decoded);
                // The current PHP oracle also strips quotes, permits inner angles,
                // and caps IDs at 255 bytes. Keep those edge cases in PHP.
                if matches!(id.first(), Some(b'\'' | b'"')) {
                    return Err(-1);
                }
                if id.starts_with(b"<") && id.ends_with(b">") {
                    id = trim_php(&id[1..id.len() - 1]);
                }
                if id.len() > 255 || id.contains(&b'<') || id.contains(&b'>') {
                    return Err(-1);
                }
                if id.is_empty() || id.iter().any(|&b| b <= 0x20 || b == 0x7f) {
                    return Err(-2);
                }
                if !ids.contains(id) {
                    if !output.is_empty() {
                        output.push(b'\n');
                    }
                    output.extend_from_slice(id);
                    ids.insert(id.to_vec());
                }
                continue;
            }
            Some((_, CoverageElement::Ignored, _)) => CoverageElement::Ignored,
        };
        if empty {
            if matches!(kind, CoverageElement::File | CoverageElement::Segments) {
                return Err(-2);
            }
        } else {
            if stack.len() >= 256 {
                return Err(-1);
            }
            stack.push((name, kind, 0));
        }
    }
}

/// Validated, unique newline-separated IDs in encounter order. -1 declines to
/// PHP, -2 is invalid, and -(needed + 16) requests a larger output buffer.
///
/// # Safety
/// Non-null input/output pointers must span their supplied lengths, must not
/// overlap, and output must be writable for out_cap bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_coverage_ids(
    xml: *const std::ffi::c_char,
    xml_len: usize,
    out_buf: *mut std::ffi::c_char,
    out_cap: usize,
) -> isize {
    if xml.is_null() || (out_buf.is_null() && out_cap != 0) || xml_len > 16_777_216 {
        return -1;
    }
    let input = unsafe { slice::from_raw_parts(xml.cast::<u8>(), xml_len) };
    match coverage_ids_xml(input) {
        Err(status) => status,
        Ok(ids) => {
            if out_cap < ids.len() {
                return -(ids.len() as isize + 16);
            }
            if !ids.is_empty() {
                unsafe {
                    std::ptr::copy_nonoverlapping(ids.as_ptr(), out_buf.cast::<u8>(), ids.len())
                };
            }
            ids.len() as isize
        }
    }
}

#[cfg(test)]
mod coverage_tests {
    use super::*;

    fn inventory(segments: &str) -> String {
        format!("<nzb><file><segments>{segments}</segments></file></nzb>")
    }

    const ONE: &str = r#"<segment number="1" bytes="0">one@test</segment>"#;

    #[test]
    fn unique_ids_keep_first_encounter_order_across_files() {
        let xml = format!(
            r#"<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><head><meta type="title">ignored</meta></head><file><groups><group>alt.test</group></groups><segments>{ONE}</segments></file><file><segments><segment bytes="99999999999999999999999" number="123456789012345678901234567890">two@test</segment>{ONE}</segments></file></nzb>"#
        );
        assert_eq!(
            coverage_ids_xml(xml.as_bytes()),
            Ok(b"one@test\ntwo@test".to_vec())
        );
    }

    #[test]
    fn decodes_entities_before_attributes_and_message_id_validation() {
        let xml = inventory(
            r#"<segment number="&#49;" bytes="&#x30;"> &#9;&lt;a&amp;b&#64;test&gt;&#10;</segment><segment number="2" bytes="00">two&#x40;test</segment>"#,
        );
        assert_eq!(
            coverage_ids_xml(xml.as_bytes()),
            Ok(b"a&b@test\ntwo@test".to_vec())
        );
        for text in ["bad id", "bad&#10;id", "bad&#127;id", "", "&lt;&gt;"] {
            let xml = inventory(&format!(
                r#"<segment number="1" bytes="1">{text}</segment>"#
            ));
            assert_eq!(coverage_ids_xml(xml.as_bytes()), Err(-2), "{text}");
        }
    }

    #[test]
    fn requires_positive_decimal_number_and_nonempty_digit_bytes() {
        for number in ["0", "01", "-1", "+1", "", "1.0", "1 "] {
            let xml = inventory(&format!(
                r#"<segment number="{number}" bytes="1">one@test</segment>"#
            ));
            assert_eq!(coverage_ids_xml(xml.as_bytes()), Err(-2), "{number}");
        }
        for bytes in ["", "-1", "+1", "1.0", "1 "] {
            let xml = inventory(&format!(
                r#"<segment number="1" bytes="{bytes}">one@test</segment>"#
            ));
            assert_eq!(coverage_ids_xml(xml.as_bytes()), Err(-2), "{bytes}");
        }
        assert_eq!(
            coverage_ids_xml(inventory("<segment>id</segment>").as_bytes()),
            Err(-2)
        );
    }

    #[test]
    fn requires_files_and_exactly_one_nonempty_segment_container() {
        for xml in [
            "<nzb/>",
            "<nzb><head/></nzb>",
            "<nzb><file/></nzb>",
            "<nzb><file><groups/></file></nzb>",
            "<nzb><file><segments/></file></nzb>",
            "<nzb><file><segments></segments></file></nzb>",
        ] {
            assert_eq!(coverage_ids_xml(xml.as_bytes()), Err(-2), "{xml}");
        }
        let multiple =
            format!("<nzb><file><segments>{ONE}</segments><segments>{ONE}</segments></file></nzb>");
        assert_eq!(coverage_ids_xml(multiple.as_bytes()), Err(-2));
        for child in [
            "<other/>",
            r#"<segment number="1" bytes="1">one<child/>@test</segment>"#,
        ] {
            assert_eq!(coverage_ids_xml(inventory(child).as_bytes()), Err(-2));
        }
    }

    #[test]
    fn declines_special_xml_and_namespace_semantics() {
        for xml in [
            inventory(&format!("<!-- comment -->{ONE}")),
            inventory(&format!("<?test value?>{ONE}")),
            inventory(r#"<segment number="1" bytes="1"><![CDATA[one@test]]></segment>"#),
            format!("<!DOCTYPE nzb SYSTEM \"urn:offline\">{}", inventory(ONE)),
            format!("<nzb xmlns=\"urn:other\"><file><segments>{ONE}</segments></file></nzb>"),
            format!("<nzb xmlns:x=\"urn:other\"><file><segments>{ONE}</segments></file></nzb>"),
            format!("<nzb><file xmlns=\"\"><segments>{ONE}</segments></file></nzb>"),
            "<n:nzb xmlns:n=\"http://www.newzbin.com/DTD/2003/nzb\"/>".to_string(),
            format!("<nzb><extra/><file><segments>{ONE}</segments></file></nzb>"),
            format!("<nzb><file><extra/><segments>{ONE}</segments></file></nzb>"),
        ] {
            assert_eq!(coverage_ids_xml(xml.as_bytes()), Err(-1), "{xml}");
        }
    }

    #[test]
    fn defers_php_normalization_edges_and_non_ascii() {
        for text in [
            "&quot;&lt;one@test&gt;&quot;".to_string(),
            "one&lt;x&gt;@test".to_string(),
            "é@test".to_string(),
            "a".repeat(256),
            "a".repeat(512),
        ] {
            let xml = inventory(&format!(
                r#"<segment number="1" bytes="1">{text}</segment>"#
            ));
            assert_eq!(coverage_ids_xml(xml.as_bytes()), Err(-1));
        }
        let xml = inventory(&format!(
            r#"<segment number="1" bytes="1">{}</segment>"#,
            "a".repeat(255)
        ));
        assert_eq!(coverage_ids_xml(xml.as_bytes()), Ok(vec![b'a'; 255]));
    }

    #[test]
    fn segment_count_is_not_unique_id_count() {
        let mut segments = ONE.repeat(100_000);
        assert_eq!(
            coverage_ids_xml(inventory(&segments).as_bytes()),
            Ok(b"one@test".to_vec())
        );
        segments.push_str(ONE);
        assert_eq!(coverage_ids_xml(inventory(&segments).as_bytes()), Err(-2));
    }

    #[test]
    fn ffi_capacity_statuses_and_output_bounds() {
        let xml = inventory(ONE);
        let mut buffer = [0x7fu8; 32];
        unsafe {
            assert_eq!(
                nntmux_nzb_coverage_ids(xml.as_ptr().cast(), xml.len(), std::ptr::null_mut(), 0),
                -24
            );
            assert_eq!(
                nntmux_nzb_coverage_ids(
                    xml.as_ptr().cast(),
                    xml.len(),
                    buffer.as_mut_ptr().cast(),
                    7
                ),
                -24
            );
            assert_eq!(buffer, [0x7fu8; 32]);
            assert_eq!(
                nntmux_nzb_coverage_ids(
                    xml.as_ptr().cast(),
                    xml.len(),
                    buffer.as_mut_ptr().cast(),
                    8
                ),
                8
            );
            assert_eq!(&buffer[..8], b"one@test");
            assert!(buffer[8..].iter().all(|&b| b == 0x7f));
            assert_eq!(
                nntmux_nzb_coverage_ids(std::ptr::null(), 1, buffer.as_mut_ptr().cast(), 32),
                -1
            );
            assert_eq!(
                nntmux_nzb_coverage_ids(xml.as_ptr().cast(), xml.len(), std::ptr::null_mut(), 1),
                -1
            );
        }
    }
}

// ---------------------------------------------------------------------------
// 10. ContainerSplicer::splice offset scanner
// ---------------------------------------------------------------------------

const MATROSKA_CLUSTER: [u8; 4] = [0x1F, 0x43, 0xB6, 0x75];
const MPEG_PACK_START: [u8; 4] = [0x00, 0x00, 0x01, 0xBA];

/// First offset of the 4-byte `needle` in `hay` (PHP `strpos`), prefiltered on the needle bytes at
/// `a` and `b`; pick rare ones (MPEG pack starts begin with two zero bytes).
fn find4(hay: &[u8], needle: &[u8; 4], a: usize, b: usize) -> Option<usize> {
    let last = hay.len().checked_sub(4)?;
    let (x, y) = (needle[a], needle[b]);
    let mut from = 0;
    while from <= last {
        let candidate = find_pair(hay, from, last, a, [x, x], b, [y, y])?;
        if hay[candidate..candidate + 4] == *needle {
            return Some(candidate);
        }
        from = candidate + 1;
    }
    None
}

/// First `p` in `from..=last` with `hay[p + a]` in `xs` and `hay[p + b]` in `ys`; `a, b < 4` and
/// `last + 4 <= hay.len()`.
fn find_pair(
    hay: &[u8],
    from: usize,
    last: usize,
    a: usize,
    xs: [u8; 2],
    b: usize,
    ys: [u8; 2],
) -> Option<usize> {
    let mut p = from;
    #[cfg(target_arch = "aarch64")]
    {
        use std::arch::aarch64::{
            uint8x16_t, vandq_u8, vceqq_u8, vdupq_n_u8, vld1q_u8, vmaxvq_u8, vorrq_u8,
        };
        // SAFETY: each iteration loads 16 bytes at `p + a`, `p + b`, `p + 16 + a` and `p + 16 + b`,
        // all ending by `p + 35 <= last + 3 < hay.len()`; NEON is baseline on aarch64.
        unsafe {
            let base = hay.as_ptr();
            let (x0, x1, y0, y1) = (
                vdupq_n_u8(xs[0]),
                vdupq_n_u8(xs[1]),
                vdupq_n_u8(ys[0]),
                vdupq_n_u8(ys[1]),
            );
            let hit = |at: usize| -> uint8x16_t {
                let (va, vb) = (vld1q_u8(base.add(at + a)), vld1q_u8(base.add(at + b)));
                vandq_u8(
                    vorrq_u8(vceqq_u8(va, x0), vceqq_u8(va, x1)),
                    vorrq_u8(vceqq_u8(vb, y0), vceqq_u8(vb, y1)),
                )
            };
            while p + 32 <= last {
                if vmaxvq_u8(vorrq_u8(hit(p), hit(p + 16))) != 0 {
                    break;
                }
                p += 32;
            }
        }
    }
    #[cfg(target_arch = "x86_64")]
    {
        use std::arch::x86_64::{
            __m128i, _mm_and_si128, _mm_cmpeq_epi8, _mm_loadu_si128, _mm_movemask_epi8,
            _mm_or_si128, _mm_set1_epi8,
        };
        // SAFETY: as above; SSE2 is baseline on x86_64 and the loads are unaligned.
        unsafe {
            let base = hay.as_ptr();
            let splat = |v: u8| _mm_set1_epi8(v as i8);
            let (x0, x1, y0, y1) = (splat(xs[0]), splat(xs[1]), splat(ys[0]), splat(ys[1]));
            let hit = |at: usize| -> __m128i {
                let va = _mm_loadu_si128(base.add(at + a).cast());
                let vb = _mm_loadu_si128(base.add(at + b).cast());
                _mm_and_si128(
                    _mm_or_si128(_mm_cmpeq_epi8(va, x0), _mm_cmpeq_epi8(va, x1)),
                    _mm_or_si128(_mm_cmpeq_epi8(vb, y0), _mm_cmpeq_epi8(vb, y1)),
                )
            };
            while p + 32 <= last {
                if _mm_movemask_epi8(_mm_or_si128(hit(p), hit(p + 16))) != 0 {
                    break;
                }
                p += 32;
            }
        }
    }
    (p..=last).find(|&q| xs.contains(&hay[q + a]) && ys.contains(&hay[q + b]))
}

/// Leftmost match of PCRE `/\d\d(?:dc|db|wb)/`: an AVI stream chunk id.
fn find_avi_chunk(mid: &[u8]) -> Option<usize> {
    let last = mid.len().checked_sub(4)?;
    let mut from = 0;
    while from <= last {
        let p = find_pair(mid, from, last, 2, *b"dw", 3, *b"cb")?;
        if mid[p].is_ascii_digit() && mid[p + 1].is_ascii_digit() && &mid[p + 2..p + 4] != b"wc" {
            return Some(p);
        }
        from = p + 1;
    }
    None
}

/// `ContainerSplicer::splice()` as offsets: the result is `head[..head_end] . mid[mid_start..]`.
/// Container codes follow `detect_video_container`; containers without a resume point give `None`.
pub(crate) fn container_splice(container: u8, head: &[u8], mid: &[u8]) -> Option<(usize, usize)> {
    match container {
        1 => Some((
            find4(head, &MATROSKA_CLUSTER, 0, 3)?,
            find4(mid, &MATROSKA_CLUSTER, 0, 3)?,
        )),
        2 => {
            let movi = find4(head, b"movi", 0, 3)?;
            if movi < 8 || &head[movi - 8..movi - 4] != b"LIST" {
                return None;
            }
            Some((movi + 4, find_avi_chunk(mid)?))
        }
        3 => Some((0, find4(mid, &MPEG_PACK_START, 2, 3)?)),
        4 => Some((0, transport_stream_offset(mid)?)),
        _ => None,
    }
}

/// Returns 1 with both offsets written, 0 when the chunk cannot be spliced and -1 on invalid input.
///
/// # Safety
/// `head_ptr`/`tail_ptr` must be readable for their lengths and the out pointers writable.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_container_splice(
    container_code: u8,
    head_ptr: *const u8,
    head_len: usize,
    tail_ptr: *const u8,
    tail_len: usize,
    out_head_end: *mut usize,
    out_tail_start: *mut usize,
) -> i32 {
    if (head_ptr.is_null() && head_len > 0)
        || (tail_ptr.is_null() && tail_len > 0)
        || out_head_end.is_null()
        || out_tail_start.is_null()
    {
        return -1;
    }
    let head = if head_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(head_ptr, head_len) }
    };
    let mid = if tail_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(tail_ptr, tail_len) }
    };
    match container_splice(container_code, head, mid) {
        Some((head_end, tail_start)) => {
            unsafe {
                *out_head_end = head_end;
                *out_tail_start = tail_start;
            }
            1
        }
        None => 0,
    }
}

#[cfg(test)]
mod container_splice_tests {
    use super::*;

    const CLUSTER: &[u8] = b"\x1F\x43\xB6\x75";

    fn naive_find(hay: &[u8], needle: &[u8]) -> Option<usize> {
        hay.windows(needle.len()).position(|w| w == needle)
    }

    fn naive_avi(mid: &[u8]) -> Option<usize> {
        mid.windows(4).position(|w| {
            w[0].is_ascii_digit()
                && w[1].is_ascii_digit()
                && matches!(&w[2..], b"dc" | b"db" | b"wb")
        })
    }

    fn noise(seed: u64, len: usize, alphabet: &[u8]) -> Vec<u8> {
        let mut state = seed | 1;
        (0..len)
            .map(|_| {
                state ^= state << 13;
                state ^= state >> 7;
                state ^= state << 17;
                alphabet[(state % alphabet.len() as u64) as usize]
            })
            .collect()
    }

    #[test]
    fn scanners_match_naive_search_at_every_alignment() {
        let alphabets: [&[u8]; 3] = [
            b"\x1F\x43\xB6\x75\x00\x01\xBAx",
            b"0123456789dcbw",
            b"\x00\x00\x01\xBA\x47",
        ];
        for (seed, alphabet) in (1..400u64).zip(alphabets.iter().cycle()) {
            let hay = noise(seed, (seed as usize * 7) % 300, alphabet);
            assert_eq!(
                find4(&hay, &MATROSKA_CLUSTER, 0, 3),
                naive_find(&hay, CLUSTER),
                "seed {seed}"
            );
            assert_eq!(
                find4(&hay, &MPEG_PACK_START, 2, 3),
                naive_find(&hay, &MPEG_PACK_START),
                "seed {seed}"
            );
            assert_eq!(
                find4(&hay, b"movi", 0, 3),
                naive_find(&hay, b"movi"),
                "seed {seed}"
            );
            assert_eq!(find_avi_chunk(&hay), naive_avi(&hay), "seed {seed}");
        }
        for at in 0..100 {
            let mut hay = vec![0u8; 140];
            hay[at..at + 4].copy_from_slice(CLUSTER);
            assert_eq!(find4(&hay, &MATROSKA_CLUSTER, 0, 3), Some(at));
            let mut avi = vec![b'x'; 140];
            avi[at..at + 4].copy_from_slice(b"01wb");
            assert_eq!(find_avi_chunk(&avi), Some(at));
        }
    }

    #[test]
    fn splice_offsets_follow_each_container() {
        let head = [&b"\x1A\x45\xDF\xA3HEADERS"[..], CLUSTER, b"OPENING"].concat();
        let mid = [&b"tail-of-a-block"[..], CLUSTER, b"MIDDLE"].concat();
        assert_eq!(container_splice(1, &head, &mid), Some((11, 15)));
        assert_eq!(container_splice(1, &head, b"no cluster here"), None);
        assert_eq!(container_splice(1, b"", &mid), None);

        let avi_head = b"RIFF\0\0\0\0AVI LIST\0\0\0\0hdrlLIST\0\0\0\0movi00dc";
        assert_eq!(
            container_splice(2, avi_head, b"junk1xdc01wb"),
            Some((36, 8))
        );
        assert_eq!(container_splice(2, b"RIFFmovi....", b"00dc"), None);
        assert_eq!(container_splice(2, b"xxxxJUNKxxxxmovi", b"00dc"), None);
        assert_eq!(container_splice(2, avi_head, b"0adc"), None);

        assert_eq!(
            container_splice(3, b"", b"\x00\x00\x00\x01\xBA"),
            Some((0, 1))
        );
        assert_eq!(container_splice(3, b"", b"\x00\x00\x01\xB3"), None);

        let mut packets = b"junk!".to_vec();
        for _ in 0..6 {
            packets.push(0x47);
            packets.extend([0x11; 187]);
        }
        assert_eq!(container_splice(4, b"", &packets), Some((0, 5)));
        assert_eq!(container_splice(5, &head, &mid), None);
        assert_eq!(container_splice(0, &head, &mid), None);
    }

    #[test]
    fn ffi_rejects_null_buffers_and_writes_offsets() {
        let (mut head_end, mut tail_start) = (usize::MAX, usize::MAX);
        unsafe {
            assert_eq!(
                nntmux_container_splice(
                    1,
                    std::ptr::null(),
                    1,
                    b"x".as_ptr(),
                    1,
                    &mut head_end,
                    &mut tail_start
                ),
                -1
            );
            assert_eq!(
                nntmux_container_splice(
                    1,
                    b"x".as_ptr(),
                    1,
                    b"x".as_ptr(),
                    1,
                    std::ptr::null_mut(),
                    &mut tail_start
                ),
                -1
            );
            assert_eq!(
                nntmux_container_splice(
                    3,
                    std::ptr::null(),
                    0,
                    b"\x00\x00\x01\xBA".as_ptr(),
                    4,
                    &mut head_end,
                    &mut tail_start
                ),
                1
            );
            assert_eq!((head_end, tail_start), (0, 0));
            assert_eq!(
                nntmux_container_splice(
                    5,
                    std::ptr::null(),
                    0,
                    std::ptr::null(),
                    0,
                    &mut head_end,
                    &mut tail_start
                ),
                0
            );
        }
    }
}

// ---------------------------------------------------------------------------
// 11. EpisodeHydrationService (`nntmux_episode_parse_searchname` & `nntmux_episode_hydrate_batch_nul`)
// ---------------------------------------------------------------------------

#[inline]
fn is_episode_sep(b: u8) -> bool {
    matches!(
        b,
        b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r' | b'.' | b'_' | b'-'
    )
}

#[inline]
fn parse_ascii_digits_i32(digits: &[u8]) -> i32 {
    let mut acc = 0i32;
    for &b in digits {
        acc = acc * 10 + (b - b'0') as i32;
    }
    acc
}

/// Parses `YYYY[-/]MM[-/]DD` (with matching `-` or `/` separators) and optional `[ T]HH:MM:SS`
/// when the calendar date is valid (`1000..=9999`, `01..=12`, `01..=days_in_month`).
/// Non-canonical strings return `None` so PHP falls back to `Carbon::parse`.
pub(crate) fn parse_canonical_firstaired(s: &[u8]) -> Option<(i32, i32)> {
    if s.len() != 10 && s.len() != 19 {
        return None;
    }
    let sep = s[4];
    if !matches!(sep, b'-' | b'/') || s[7] != sep {
        return None;
    }
    if !s[0..4].iter().all(u8::is_ascii_digit)
        || !s[5..7].iter().all(u8::is_ascii_digit)
        || !s[8..10].iter().all(u8::is_ascii_digit)
    {
        return None;
    }
    let year = parse_ascii_digits_i32(&s[0..4]);
    let month = parse_ascii_digits_i32(&s[5..7]);
    let day = parse_ascii_digits_i32(&s[8..10]);
    if !(1000..=9999).contains(&year) || !(1..=12).contains(&month) {
        return None;
    }
    let max_day = match month {
        1 | 3 | 5 | 7 | 8 | 10 | 12 => 31,
        4 | 6 | 9 | 11 => 30,
        2 => {
            if (year % 4 == 0 && year % 100 != 0) || (year % 400 == 0) {
                29
            } else {
                28
            }
        }
        _ => return None,
    };
    if !(1..=max_day).contains(&day) {
        return None;
    }
    if s.len() == 19 {
        if !matches!(s[10], b' ' | b'T' | b't') || s[13] != b':' || s[16] != b':' {
            return None;
        }
        if !s[11..13].iter().all(u8::is_ascii_digit)
            || !s[14..16].iter().all(u8::is_ascii_digit)
            || !s[17..19].iter().all(u8::is_ascii_digit)
        {
            return None;
        }
        let hh = parse_ascii_digits_i32(&s[11..13]);
        let mm = parse_ascii_digits_i32(&s[14..16]);
        let ss = parse_ascii_digits_i32(&s[17..19]);
        if hh > 23 || mm > 59 || ss > 59 {
            return None;
        }
    }
    Some((year, month * 100 + day))
}

/// Evaluates the 7 regexes of `EpisodeHydrationService::hydrateFromSearchname` in exact order.
/// Returns `(series_out, episode_out)` where `-1` means the field is left untouched.
pub(crate) fn episode_parse_searchname(
    searchname: &[u8],
    missing_series: bool,
    missing_episode: bool,
) -> (i32, i32) {
    if (!missing_series && !missing_episode) || searchname.is_empty() {
        return (-1, -1);
    }
    let n = searchname.len();

    // 1. `/\bS(\d{1,2})E(\d{1,3})\b/i`
    let mut i = 0;
    while i < n {
        if (i == 0 || !is_word_char(searchname[i - 1])) && matches!(searchname[i], b'S' | b's') {
            let d1_start = i + 1;
            let mut d1_end = d1_start;
            while d1_end < n && d1_end - d1_start < 2 && searchname[d1_end].is_ascii_digit() {
                d1_end += 1;
            }
            if d1_end > d1_start && matches!(searchname.get(d1_end), Some(b'E' | b'e')) {
                let d2_start = d1_end + 1;
                let mut d2_end = d2_start;
                while d2_end < n && d2_end - d2_start < 3 && searchname[d2_end].is_ascii_digit() {
                    d2_end += 1;
                }
                if d2_end > d2_start && (d2_end == n || !is_word_char(searchname[d2_end])) {
                    let s = if missing_series {
                        parse_ascii_digits_i32(&searchname[d1_start..d1_end])
                    } else {
                        -1
                    };
                    let e = if missing_episode {
                        parse_ascii_digits_i32(&searchname[d2_start..d2_end])
                    } else {
                        -1
                    };
                    return (s, e);
                }
            }
        }
        i += 1;
    }

    // 2. `/\b(\d{1,2})x(\d{1,3})\b/i`
    i = 0;
    while i < n {
        if (i == 0 || !is_word_char(searchname[i - 1])) && searchname[i].is_ascii_digit() {
            let d1_start = i;
            let mut d1_end = d1_start;
            while d1_end < n && d1_end - d1_start < 2 && searchname[d1_end].is_ascii_digit() {
                d1_end += 1;
            }
            if matches!(searchname.get(d1_end), Some(b'x' | b'X')) {
                let d2_start = d1_end + 1;
                let mut d2_end = d2_start;
                while d2_end < n && d2_end - d2_start < 3 && searchname[d2_end].is_ascii_digit() {
                    d2_end += 1;
                }
                if d2_end > d2_start && (d2_end == n || !is_word_char(searchname[d2_end])) {
                    let s = if missing_series {
                        parse_ascii_digits_i32(&searchname[d1_start..d1_end])
                    } else {
                        -1
                    };
                    let e = if missing_episode {
                        parse_ascii_digits_i32(&searchname[d2_start..d2_end])
                    } else {
                        -1
                    };
                    return (s, e);
                }
            }
        }
        i += 1;
    }

    // 3. `/\bSeason[\s._-]*(\d{1,2})[\s._-]*Episode[\s._-]*(\d{1,3})\b/i`
    i = 0;
    while i + 15 <= n {
        if (i == 0 || !is_word_char(searchname[i - 1]))
            && searchname[i..i + 6].eq_ignore_ascii_case(b"season")
        {
            let mut p = i + 6;
            while p < n && is_episode_sep(searchname[p]) {
                p += 1;
            }
            let d1_start = p;
            while p < n && p - d1_start < 2 && searchname[p].is_ascii_digit() {
                p += 1;
            }
            let d1_end = p;
            if d1_end > d1_start {
                while p < n && is_episode_sep(searchname[p]) {
                    p += 1;
                }
                if p + 7 <= n && searchname[p..p + 7].eq_ignore_ascii_case(b"episode") {
                    p += 7;
                    while p < n && is_episode_sep(searchname[p]) {
                        p += 1;
                    }
                    let d2_start = p;
                    while p < n && p - d2_start < 3 && searchname[p].is_ascii_digit() {
                        p += 1;
                    }
                    let d2_end = p;
                    if d2_end > d2_start && (d2_end == n || !is_word_char(searchname[d2_end])) {
                        let s = if missing_series {
                            parse_ascii_digits_i32(&searchname[d1_start..d1_end])
                        } else {
                            -1
                        };
                        let e = if missing_episode {
                            parse_ascii_digits_i32(&searchname[d2_start..d2_end])
                        } else {
                            -1
                        };
                        return (s, e);
                    }
                }
            }
        }
        i += 1;
    }

    // 4. Compact format: `/\b(\d)(0[1-9]|[1-9]\d)\b/` (only applies if both series and episode are missing)
    if missing_series && missing_episode {
        i = 0;
        while i + 3 <= n {
            if (i == 0 || !is_word_char(searchname[i - 1]))
                && (i + 3 == n || !is_word_char(searchname[i + 3]))
                && searchname[i].is_ascii_digit()
                && searchname[i + 1].is_ascii_digit()
                && searchname[i + 2].is_ascii_digit()
                && !(searchname[i + 1] == b'0' && searchname[i + 2] == b'0')
            {
                let s = (searchname[i] - b'0') as i32;
                let e = ((searchname[i + 1] - b'0') as i32) * 10 + (searchname[i + 2] - b'0') as i32;
                return (s, e);
            }
            i += 1;
        }
    }

    // 5. Date-based: `/\b(\d{4})[._-](\d{2})[._-](\d{2})\b/`
    i = 0;
    while i + 10 <= n {
        if (i == 0 || !is_word_char(searchname[i - 1]))
            && (i + 10 == n || !is_word_char(searchname[i + 10]))
            && matches!(searchname[i + 4], b'.' | b'_' | b'-')
            && matches!(searchname[i + 7], b'.' | b'_' | b'-')
            && searchname[i..i + 4].iter().all(u8::is_ascii_digit)
            && searchname[i + 5..i + 7].iter().all(u8::is_ascii_digit)
            && searchname[i + 8..i + 10].iter().all(u8::is_ascii_digit)
        {
            let s = if missing_series {
                parse_ascii_digits_i32(&searchname[i..i + 4])
            } else {
                -1
            };
            let e = if missing_episode {
                parse_ascii_digits_i32(&searchname[i + 5..i + 7]) * 100
                    + parse_ascii_digits_i32(&searchname[i + 8..i + 10])
            } else {
                -1
            };
            return (s, e);
        }
        i += 1;
    }

    if !missing_episode {
        return (-1, -1);
    }

    // 6. Part/Pt N: `/\b(?:Part|Pt)[\s._-]*(\d{1,3})\b/i`
    i = 0;
    while i + 3 <= n {
        if i == 0 || !is_word_char(searchname[i - 1]) {
            let kw_len = if i + 4 <= n && searchname[i..i + 4].eq_ignore_ascii_case(b"part") {
                4
            } else if i + 2 <= n && searchname[i..i + 2].eq_ignore_ascii_case(b"pt") {
                2
            } else {
                0
            };
            if kw_len > 0 {
                let mut p = i + kw_len;
                while p < n && is_episode_sep(searchname[p]) {
                    p += 1;
                }
                let d_start = p;
                while p < n && p - d_start < 3 && searchname[p].is_ascii_digit() {
                    p += 1;
                }
                let d_end = p;
                if d_end > d_start && (d_end == n || !is_word_char(searchname[d_end])) {
                    let e = parse_ascii_digits_i32(&searchname[d_start..d_end]);
                    let s = if missing_series { 1 } else { -1 };
                    return (s, e);
                }
            }
        }
        i += 1;
    }

    // 7. Ep/E N: `/\bEp?[\s._-]*(\d{1,3})\b/i`
    i = 0;
    while i + 2 <= n {
        if (i == 0 || !is_word_char(searchname[i - 1])) && matches!(searchname[i], b'E' | b'e') {
            let mut p = i + 1;
            if p < n && matches!(searchname[p], b'P' | b'p') {
                p += 1;
            }
            while p < n && is_episode_sep(searchname[p]) {
                p += 1;
            }
            let d_start = p;
            while p < n && p - d_start < 3 && searchname[p].is_ascii_digit() {
                p += 1;
            }
            let d_end = p;
            if d_end > d_start && (d_end == n || !is_word_char(searchname[d_end])) {
                let e = parse_ascii_digits_i32(&searchname[d_start..d_end]);
                let s = if missing_series { 1 } else { -1 };
                return (s, e);
            }
        }
        i += 1;
    }

    (-1, -1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_episode_parse_searchname(
    ptr: *const u8,
    len: usize,
    flags: u8,
    out_series: *mut i32,
    out_episode: *mut i32,
) -> i32 {
    if (ptr.is_null() && len > 0) || out_series.is_null() || out_episode.is_null() {
        return -1;
    }
    let searchname = if len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(ptr, len) }
    };
    let (s, e) = episode_parse_searchname(searchname, (flags & 1) != 0, (flags & 2) != 0);
    unsafe {
        *out_series = s;
        *out_episode = e;
    }
    if s >= 0 || e >= 0 { 1 } else { 0 }
}

/// Batch-parses `firstaired` and `searchname` across `count` releases in a single FFI call.
/// Writes `count` pairs of native-endian `i32` (`series_out, episode_out`, 8 bytes per release)
/// where `>= 0` updates the property, `-1` leaves it unchanged, and `-2` declines the item to PHP.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_episode_hydrate_batch_nul(
    searchnames_ptr: *const u8,
    searchnames_len: usize,
    firstaired_ptr: *const u8,
    firstaired_len: usize,
    flags_ptr: *const u8,
    flags_len: usize,
    count: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if count == 0 {
        return 0;
    }
    if (searchnames_ptr.is_null() && searchnames_len > 0)
        || (firstaired_ptr.is_null() && firstaired_len > 0)
        || (flags_ptr.is_null() && flags_len > 0)
        || (out_ptr.is_null() && out_cap > 0)
    {
        return -1;
    }
    if flags_len != 0 && flags_len != count {
        return -1;
    }
    let needed = match count.checked_mul(8) {
        Some(v) => v,
        None => return -1,
    };
    if out_cap < needed {
        return -((needed + 16) as isize);
    }

    let searchnames = if searchnames_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(searchnames_ptr, searchnames_len) }
    };
    let firstaired = if firstaired_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(firstaired_ptr, firstaired_len) }
    };
    let flags = if flags_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(flags_ptr, flags_len) }
    };

    let mut out = unsafe { OutCursor::new(out_ptr, out_cap) };
    let mut sn_iter = searchnames.split(|&b| b == 0);
    let mut fa_iter = if firstaired_len == 0 {
        None
    } else {
        Some(firstaired.split(|&b| b == 0))
    };

    for idx in 0..count {
        let sn = match sn_iter.next() {
            Some(v) => v,
            None => return -1,
        };
        let fa = match fa_iter.as_mut() {
            Some(iter) => match iter.next() {
                Some(v) => v,
                None => return -1,
            },
            None => &[][..],
        };
        let flag = if flags.is_empty() { 3u8 } else { flags[idx] & 3 };
        let mut missing_series = (flag & 1) != 0;
        let mut missing_episode = (flag & 2) != 0;
        let mut series_out = -1i32;
        let mut episode_out = -1i32;

        if (missing_series || missing_episode) && !fa.is_empty() {
            match parse_canonical_firstaired(fa) {
                Some((year, md)) => {
                    if missing_series {
                        series_out = year;
                        missing_series = false;
                    }
                    if missing_episode {
                        episode_out = md;
                        missing_episode = false;
                    }
                }
                None => {
                    out.extend(&(-2i32).to_ne_bytes());
                    out.extend(&(-2i32).to_ne_bytes());
                    continue;
                }
            }
        }

        if (missing_series || missing_episode) && !sn.is_empty() {
            let (s, e) = episode_parse_searchname(sn, missing_series, missing_episode);
            if s >= 0 {
                series_out = s;
            }
            if e >= 0 {
                episode_out = e;
            }
        }

        out.extend(&series_out.to_ne_bytes());
        out.extend(&episode_out.to_ne_bytes());
    }

    if sn_iter.next().is_some() || fa_iter.as_mut().and_then(|it| it.next()).is_some() {
        return -1;
    }

    out.pos as isize
}

// ---------------------------------------------------------------------------
// 12. ArchiveExtractionService (`withSevenZipEndHeader`, `isNfoFile`, `sortFilesWithNfoPriority`, `detectStandaloneVideo`)
// ---------------------------------------------------------------------------

const SEVENZIP_SIGNATURE: [u8; 6] = [b'7', b'z', 0xBC, 0xAF, 0x27, 0x1C];
const SEVENZIP_MAX_ARCHIVE_BYTES: u64 = 1u64 << 40;

#[cfg(target_arch = "aarch64")]
#[target_feature(enable = "crc")]
unsafe fn crc32_ieee_aarch64(data: &[u8]) -> u32 {
    use std::arch::aarch64::{__crc32b, __crc32d, __crc32h, __crc32w};
    let mut crc = 0xFFFF_FFFFu32;
    let (chunks, rem) = data.as_chunks::<8>();
    for chunk in chunks {
        crc = __crc32d(crc, u64::from_le_bytes(*chunk));
    }
    let mut rem = rem;
    if rem.len() >= 4 {
        let w = u32::from_le_bytes([rem[0], rem[1], rem[2], rem[3]]);
        crc = __crc32w(crc, w);
        rem = &rem[4..];
    }
    if rem.len() >= 2 {
        let h = u16::from_le_bytes([rem[0], rem[1]]);
        crc = __crc32h(crc, h);
        rem = &rem[2..];
    }
    if let Some(&b) = rem.first() {
        crc = __crc32b(crc, b);
    }
    !crc
}

#[inline]
fn crc32_fast(data: &[u8]) -> u32 {
    #[cfg(target_arch = "aarch64")]
    {
        if std::arch::is_aarch64_feature_detected!("crc") {
            return unsafe { crc32_ieee_aarch64(data) };
        }
    }
    crate::nfo::crc32_ieee(data)
}

pub(crate) fn validate_and_rebuild_7z_end_header<'a>(
    head: &'a [u8],
    tail: &'a [u8],
) -> Option<([u8; 32], &'a [u8])> {
    if head.len() < 32 || !head.starts_with(&SEVENZIP_SIGNATURE) {
        return None;
    }
    let next_offset = u64::from_le_bytes([
        head[12], head[13], head[14], head[15], head[16], head[17], head[18], head[19],
    ]);
    let next_size = u64::from_le_bytes([
        head[20], head[21], head[22], head[23], head[24], head[25], head[26], head[27],
    ]);
    let next_crc = u32::from_le_bytes([head[28], head[29], head[30], head[31]]);

    let max = SEVENZIP_MAX_ARCHIVE_BYTES - 32;
    if next_offset > max
        || next_size > max - next_offset
        || next_size == 0
        || (next_size as usize) > tail.len()
    {
        return None;
    }

    let end_header = &tail[tail.len() - (next_size as usize)..];
    if crc32_fast(end_header) != next_crc {
        return None;
    }

    let mut start_header = [0u8; 20];
    start_header[8..16].copy_from_slice(&next_size.to_le_bytes());
    start_header[16..20].copy_from_slice(&next_crc.to_le_bytes());
    let start_crc = crc32_fast(&start_header);

    let mut full_start = [0u8; 32];
    full_start[0..8].copy_from_slice(&head[0..8]);
    full_start[8..12].copy_from_slice(&start_crc.to_le_bytes());
    full_start[12..32].copy_from_slice(&start_header);
    Some((full_start, end_header))
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_archive_7z_validate_end_header(
    head_ptr: *const u8,
    head_len: usize,
    tail_ptr: *const u8,
    tail_len: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if (head_ptr.is_null() && head_len > 0)
        || (tail_ptr.is_null() && tail_len > 0)
        || (out_ptr.is_null() && out_cap > 0)
    {
        return -1;
    }
    let head = if head_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(head_ptr, head_len) }
    };
    let tail = if tail_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(tail_ptr, tail_len) }
    };
    let Some((full_start, end_header)) = validate_and_rebuild_7z_end_header(head, tail) else {
        return 0;
    };
    let needed = 32 + end_header.len();
    if out_cap < needed {
        return -((needed + 16) as isize);
    }
    unsafe {
        std::ptr::copy_nonoverlapping(full_start.as_ptr(), out_ptr, 32);
        std::ptr::copy_nonoverlapping(end_header.as_ptr(), out_ptr.add(32), end_header.len());
    }
    needed as isize
}

#[inline]
fn posix_basename(mut path: &[u8]) -> &[u8] {
    while path.last() == Some(&b'/') {
        path = &path[..path.len() - 1];
    }
    match path.iter().rposition(|&b| b == b'/') {
        Some(idx) => &path[idx + 1..],
        None => path,
    }
}

pub(crate) fn archive_is_nfo_file(filename: &[u8]) -> bool {
    let base = posix_basename(filename);
    if base.is_empty() {
        return false;
    }
    let b_no_nl = if base.last() == Some(&b'\n') {
        &base[..base.len() - 1]
    } else {
        base
    };

    // 1. `/\.(nfo|diz|inf)$/i`
    if b_no_nl.len() >= 4 && b_no_nl[b_no_nl.len() - 4] == b'.' {
        let ext = &b_no_nl[b_no_nl.len() - 3..];
        if ext.eq_ignore_ascii_case(b"nfo")
            || ext.eq_ignore_ascii_case(b"diz")
            || ext.eq_ignore_ascii_case(b"inf")
        {
            return true;
        }
    }

    // 2. Exact lowercased basename match against $nfoNames
    if base.len() <= 16 {
        let mut lower_buf = [0u8; 16];
        for (i, &b) in base.iter().enumerate() {
            lower_buf[i] = b.to_ascii_lowercase();
        }
        let lower = &lower_buf[..base.len()];
        if matches!(
            lower,
            b"file_id.diz"
                | b"fileid.diz"
                | b"file-id.diz"
                | b"readme.txt"
                | b"readme.1st"
                | b"read.me"
                | b"readmenow.txt"
                | b"info.txt"
                | b"information.txt"
                | b"about.txt"
                | b"notes.txt"
                | b"release.txt"
                | b"release.nfo"
        ) {
            return true;
        }
    }

    // 3. `/^(?:00?-[a-z0-9_-]+|[a-z0-9]+-[a-z0-9._-]+)\.(?:nfo|txt)$/i`
    if b_no_nl.len() >= 7 && b_no_nl[b_no_nl.len() - 4] == b'.' {
        let ext = &b_no_nl[b_no_nl.len() - 3..];
        if ext.eq_ignore_ascii_case(b"nfo") || ext.eq_ignore_ascii_case(b"txt") {
            let stem = &b_no_nl[..b_no_nl.len() - 4];
            if let Some(dash) = stem.iter().position(|&b| b == b'-') {
                if dash >= 1 && dash + 1 < stem.len() {
                    let prefix = &stem[..dash];
                    let suffix = &stem[dash + 1..];
                    if prefix.iter().all(u8::is_ascii_alphanumeric)
                        && suffix
                            .iter()
                            .all(|&b| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'-'))
                    {
                        return true;
                    }
                }
            }
        }
    }

    false
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_archive_is_nfo_file(ptr: *const u8, len: usize) -> i32 {
    if ptr.is_null() && len > 0 {
        return -1;
    }
    let filename = if len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(ptr, len) }
    };
    if archive_is_nfo_file(filename) { 1 } else { 0 }
}

/// Ranks `count` NUL-separated archive filenames with NFO priority (stable partition).
/// Returns `0` when the input is already in NFO-priority order, or `count * 4` with the
/// `u32` little-endian permutation indices written to `out_ptr`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_archive_rank_nfo_files_nul(
    names_ptr: *const u8,
    names_len: usize,
    count: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if count == 0 {
        return 0;
    }
    if (names_ptr.is_null() && names_len > 0) || (out_ptr.is_null() && out_cap > 0) {
        return -1;
    }
    let needed = match count.checked_mul(4) {
        Some(v) => v,
        None => return -1,
    };
    if out_cap < needed {
        return -((needed + 16) as isize);
    }
    let names = if names_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(names_ptr, names_len) }
    };

    let mut nfo_indices: Vec<u32> = Vec::new();
    let mut other_indices: Vec<u32> = Vec::with_capacity(count);
    let mut seen_non_nfo = false;
    let mut needs_reorder = false;

    let mut iter = names.split(|&b| b == 0);
    for idx in 0..count {
        let Some(name) = iter.next() else {
            return -1;
        };
        if archive_is_nfo_file(name) {
            if seen_non_nfo {
                needs_reorder = true;
            }
            nfo_indices.push(idx as u32);
        } else {
            seen_non_nfo = true;
            other_indices.push(idx as u32);
        }
    }
    if iter.next().is_some() {
        return -1;
    }
    if !needs_reorder {
        return 0;
    }

    let mut out = unsafe { OutCursor::new(out_ptr, out_cap) };
    for idx in nfo_indices {
        out.extend(&idx.to_le_bytes());
    }
    for idx in other_indices {
        out.extend(&idx.to_le_bytes());
    }
    out.pos as isize
}

/// Standalone video signature code:
/// 0 = null, 1 = avi, 2 = mkv, 3 = mpg, 4 = ts, 5 = mp4, 6 = wmv, 7 = flv, 8 = ogv
pub(crate) fn detect_standalone_video(data: &[u8]) -> i32 {
    if data.len() < 16 {
        return 0;
    }
    let sig4 = &data[0..4];
    if sig4 == b"RIFF" && &data[8..12] == b"AVI " {
        return 1;
    }
    if sig4 == b"\x1A\x45\xDF\xA3" {
        return 2;
    }
    if sig4 == b"\x00\x00\x01\xBA" || sig4 == b"\x00\x00\x01\xB3" {
        return 3;
    }
    if data[0] == 0x47 && data.get(188) == Some(&0x47) && data.get(376) == Some(&0x47) {
        return 4;
    }
    if &data[4..8] == b"ftyp" && data[8..12].iter().all(|&b| (0x20..=0x7E).contains(&b)) {
        return 5;
    }
    if data.starts_with(b"\x30\x26\xB2\x75\x8E\x66\xCF\x11") {
        return 6;
    }
    if sig4 == b"FLV\x01" {
        return 7;
    }
    if sig4 == b"OggS" {
        return 8;
    }
    0
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_archive_detect_standalone_video(
    ptr: *const u8,
    len: usize,
) -> i32 {
    if ptr.is_null() && len > 0 {
        return -1;
    }
    let data = if len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(ptr, len) }
    };
    detect_standalone_video(data)
}

// ---------------------------------------------------------------------------
// 13. MediaSourceResolver::wantedFiles (`nntmux_media_source_rank_wanted_files_nul`)
// ---------------------------------------------------------------------------

const DEFAULT_VIDEO_REGEX_BYTES: &[u8] =
    b"\\.(AVI|F4V|IFO|M1V|M2V|M4V|MKV|MOV|MP4|MPEG|MPG|MPGV|MPV|OGV|QT|RM|RMVB|TS|VOB|WMV)";

enum VideoExtConfig<'a> {
    Default,
    Custom(Vec<&'a [u8]>),
}

impl<'a> VideoExtConfig<'a> {
    fn parse(regex: &'a [u8]) -> Option<Self> {
        if regex == DEFAULT_VIDEO_REGEX_BYTES {
            return Some(Self::Default);
        }
        if !regex.starts_with(b"\\.(") || !regex.ends_with(b")") {
            return None;
        }
        let mut inner = &regex[3..regex.len() - 1];
        if inner.starts_with(b"?:") {
            inner = &inner[2..];
        }
        if inner.is_empty() {
            return None;
        }
        let mut exts = Vec::new();
        for part in inner.split(|&b| b == b'|') {
            if part.is_empty() || !part.iter().all(u8::is_ascii_alphanumeric) {
                return None;
            }
            exts.push(part);
        }
        Some(Self::Custom(exts))
    }

    #[inline]
    fn matches_end(&self, s: &[u8]) -> bool {
        let Some(dot_pos) = s.iter().rposition(|&b| b == b'.') else {
            return false;
        };
        let ext = &s[dot_pos + 1..];
        match self {
            Self::Default => {
                if !(2..=4).contains(&ext.len()) {
                    return false;
                }
                let mut lower = [0u8; 4];
                for (i, &b) in ext.iter().enumerate() {
                    lower[i] = b.to_ascii_lowercase();
                }
                matches!(
                    &lower[..ext.len()],
                    b"avi"
                        | b"f4v"
                        | b"ifo"
                        | b"m1v"
                        | b"m2v"
                        | b"m4v"
                        | b"mkv"
                        | b"mov"
                        | b"mp4"
                        | b"mpeg"
                        | b"mpg"
                        | b"mpgv"
                        | b"mpv"
                        | b"ogv"
                        | b"qt"
                        | b"rm"
                        | b"rmvb"
                        | b"ts"
                        | b"vob"
                        | b"wmv"
                )
            }
            Self::Custom(exts) => exts.iter().any(|cand| ext.eq_ignore_ascii_case(cand)),
        }
    }
}

#[inline]
fn is_zero_star_one(digits: &[u8]) -> bool {
    !digits.is_empty()
        && digits[digits.len() - 1] == b'1'
        && digits[..digits.len() - 1].iter().all(|&b| b == b'0')
}

#[inline]
fn matches_first_archive_volume(s: &[u8]) -> bool {
    // 1. `/\.(?:part0*1\.rar|rar|7z(?:\.0*1)?|zip|0*1)$/i`
    // 2. `&& preg_match('/\.part(?!0*1\.)\d+\.rar$/i', $file->name) !== 1`
    let Some(dot_pos) = s.iter().rposition(|&b| b == b'.') else {
        return false;
    };
    let ext = &s[dot_pos + 1..];
    if ext.eq_ignore_ascii_case(b"rar") {
        let stem = &s[..dot_pos];
        if let Some(prev_dot) = stem.iter().rposition(|&b| b == b'.') {
            let part_seg = &stem[prev_dot + 1..];
            if part_seg.len() > 4
                && part_seg[..4].eq_ignore_ascii_case(b"part")
                && part_seg[4..].iter().all(u8::is_ascii_digit)
            {
                return is_zero_star_one(&part_seg[4..]);
            }
        }
        return true;
    }
    if ext.eq_ignore_ascii_case(b"7z") || ext.eq_ignore_ascii_case(b"zip") {
        return true;
    }
    is_zero_star_one(ext)
}

#[inline]
fn is_sample_boundary_byte(b: u8) -> bool {
    matches!(b, b'\\' | b'/' | b'.' | b'_' | b' ' | b'-')
}

#[inline]
fn has_sample_token(name: &[u8]) -> bool {
    // `/(?:^|[\\\\\/._ -])sample(?:[\\\\\/._ -]|$)/i`
    if name.len() < 6 {
        return false;
    }
    let end = name.len() - 6;
    let mut i = 0;
    while i <= end {
        if (i == 0 || is_sample_boundary_byte(name[i - 1]))
            && name[i..i + 6].eq_ignore_ascii_case(b"sample")
        {
            let after = i + 6;
            if after == name.len()
                || (after + 1 == name.len() && name[after] == b'\n')
                || is_sample_boundary_byte(name[after])
            {
                return true;
            }
        }
        i += 1;
    }
    false
}

#[inline]
fn matches_image_ext(s: &[u8]) -> bool {
    // `/\.(?:jpe?g|png|webp)$/i`
    let Some(dot_pos) = s.iter().rposition(|&b| b == b'.') else {
        return false;
    };
    let ext = &s[dot_pos + 1..];
    ext.eq_ignore_ascii_case(b"jpg")
        || ext.eq_ignore_ascii_case(b"jpeg")
        || ext.eq_ignore_ascii_case(b"png")
        || ext.eq_ignore_ascii_case(b"webp")
}

fn classify_wanted_file(name: &[u8], video_cfg: &VideoExtConfig<'_>) -> Option<u8> {
    let s = if name.last() == Some(&b'\n') {
        &name[..name.len() - 1]
    } else {
        name
    };
    if matches_first_archive_volume(s) {
        return Some(0);
    }
    if video_cfg.matches_end(s) {
        return if has_sample_token(name) {
            Some(1)
        } else {
            Some(2)
        };
    }
    if matches_image_ext(s) {
        return Some(3);
    }
    None
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_media_source_rank_wanted_files_nul(
    names_ptr: *const u8,
    names_len: usize,
    sizes_ptr: *const u8,
    sizes_len: usize,
    video_regex_ptr: *const u8,
    video_regex_len: usize,
    count: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if count == 0 {
        return 0;
    }
    if (names_ptr.is_null() && names_len > 0)
        || sizes_ptr.is_null()
        || video_regex_ptr.is_null()
        || (out_ptr.is_null() && out_cap > 0)
    {
        return -1;
    }
    let Some(expected_sizes_len) = count.checked_mul(8) else {
        return -1;
    };
    if sizes_len != expected_sizes_len {
        return -1;
    }
    let names = if names_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(names_ptr, names_len) }
    };
    let sizes = unsafe { slice::from_raw_parts(sizes_ptr, sizes_len) };
    let video_regex = if video_regex_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(video_regex_ptr, video_regex_len) }
    };

    let Some(video_cfg) = VideoExtConfig::parse(video_regex) else {
        return -1;
    };

    let mut ranked: Vec<(u8, i64, u32)> = Vec::new();
    let mut iter = names.split(|&b| b == 0);
    for idx in 0..count {
        let Some(name) = iter.next() else {
            return -1;
        };
        if let Some(rank) = classify_wanted_file(name, &video_cfg) {
            let off = idx * 8;
            let size = i64::from_ne_bytes([
                sizes[off],
                sizes[off + 1],
                sizes[off + 2],
                sizes[off + 3],
                sizes[off + 4],
                sizes[off + 5],
                sizes[off + 6],
                sizes[off + 7],
            ]);
            ranked.push((rank, size, idx as u32));
        }
    }
    if iter.next().is_some() {
        return -1;
    }

    if ranked.is_empty() {
        return 0;
    }
    let needed = ranked.len() * 4;
    if out_cap < needed {
        return -((needed + 16) as isize);
    }

    ranked.sort_by(|a, b| {
        a.0.cmp(&b.0)
            .then_with(|| b.1.cmp(&a.1))
            .then_with(|| a.2.cmp(&b.2))
    });

    let mut out = unsafe { OutCursor::new(out_ptr, out_cap) };
    for &(_, _, idx) in &ranked {
        out.extend(&idx.to_le_bytes());
    }
    out.pos as isize
}

// ---------------------------------------------------------------------------
// 14. Wave 9 Slice 9.5: ReleaseProcessor helpers, ArchivedMediaSlicer range,
//     FfmpegFrameGrabber::clockSeconds, ReleaseExtraService::makeCodecPretty
// ---------------------------------------------------------------------------

const DEFAULT_SUPPORT_REGEX_BYTES: &[u8] =
    b"\\.(?:vol\\d{1,3}\\+\\d{1,3}|par2|srs|sfv|nzb)";

fn parse_release_files_into(raw: &[u8], out: &mut OutCursor<'_>) -> isize {
    let trimmed = trim_php(raw);
    if trimmed.is_empty() {
        return 0;
    }

    let mut wrote_any = false;
    for part in trimmed.split(|&b| b == b'|') {
        let entry = trim_php(part);
        if entry.is_empty() {
            continue;
        }
        let Some(star_pos) = entry.iter().rposition(|&b| b == b'*') else {
            continue;
        };
        let name = trim_php(&entry[..star_pos]);
        let size_bytes = &entry[star_pos + 1..];
        if name.is_empty() || size_bytes.is_empty() || !size_bytes.iter().all(u8::is_ascii_digit) {
            continue;
        }
        if name.contains(&0) {
            return -1;
        }

        let mut size_val: i64 = 0;
        for &b in size_bytes {
            let d = (b - b'0') as i64;
            let Some(next) = size_val.checked_mul(10).and_then(|v| v.checked_add(d)) else {
                return -1;
            };
            size_val = next;
        }

        if wrote_any {
            out.push(0);
        }
        out.extend(name);
        out.push(0);
        push_i64_dec(out, size_val);
        wrote_any = true;
    }

    if !wrote_any {
        return 0;
    }
    if out.pos <= out.buf.len() {
        out.pos as isize
    } else {
        -((out.pos + 16) as isize)
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_parse_release_files_nul(
    raw_ptr: *const u8,
    raw_len: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if (raw_ptr.is_null() && raw_len > 0) || (out_ptr.is_null() && out_cap > 0) {
        return -1;
    }
    let raw = if raw_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(raw_ptr, raw_len) }
    };
    let mut out = unsafe { OutCursor::new(out_ptr, out_cap) };
    parse_release_files_into(raw, &mut out)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_normalize_subject(
    ptr: *const u8,
    len: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if (ptr.is_null() && len > 0) || (out_ptr.is_null() && out_cap > 0) {
        return -1;
    }
    let subject = if len == 0 {
        &[][..]
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

fn subject_contains_video_pattern(subject: &[u8], video_cfg: &VideoExtConfig<'_>) -> bool {
    let is_bracket_or_end = |pos: usize| -> bool {
        pos == subject.len()
            || (pos + 1 == subject.len() && subject[pos] == b'\n')
            || matches!(subject[pos], b'.' | b' ' | b'"' | b')' | b']')
    };

    let mut cur = 0usize;
    while cur < subject.len() {
        let ptr = unsafe { memchr(subject[cur..].as_ptr(), b'.' as i32, subject.len() - cur) };
        if ptr.is_null() {
            break;
        }
        let i = cur + (ptr as usize - subject[cur..].as_ptr() as usize);
        cur = i + 1;
        let rest = &subject[i + 1..];
        match video_cfg {
            VideoExtConfig::Default => {
                for elen in [4usize, 3, 2] {
                    if rest.len() >= elen
                        && is_video_ext_ci(&rest[..elen])
                        && is_bracket_or_end(i + 1 + elen)
                    {
                        return true;
                    }
                }
            }
            VideoExtConfig::Custom(exts) => {
                for cand in exts {
                    let elen = cand.len();
                    if rest.len() >= elen
                        && rest[..elen].eq_ignore_ascii_case(cand)
                        && is_bracket_or_end(i + 1 + elen)
                    {
                        return true;
                    }
                }
            }
        }
    }
    false
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_contains_video_pattern(
    subject_ptr: *const u8,
    subject_len: usize,
    video_regex_ptr: *const u8,
    video_regex_len: usize,
) -> i32 {
    if (subject_ptr.is_null() && subject_len > 0)
        || (video_regex_ptr.is_null() && video_regex_len > 0)
    {
        return -1;
    }
    let subject = if subject_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(subject_ptr, subject_len) }
    };
    let video_regex = if video_regex_len == 0 {
        DEFAULT_VIDEO_REGEX_BYTES
    } else {
        unsafe { slice::from_raw_parts(video_regex_ptr, video_regex_len) }
    };
    let Some(video_cfg) = VideoExtConfig::parse(video_regex) else {
        return -1;
    };
    i32::from(subject_contains_video_pattern(subject, &video_cfg))
}

#[inline]
fn subject_has_usable_sample_filename(subject: &[u8]) -> bool {
    if find_ascii_ci(subject, b"sample").is_none() {
        return false;
    }
    let (_, has_image_ext_token, _, _, _, _, _) = check_image_and_media_exts(subject);
    if has_image_ext_token {
        return false;
    }
    !matches_archive_pattern(subject)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_has_usable_sample_filename(
    ptr: *const u8,
    len: usize,
) -> i32 {
    if ptr.is_null() && len > 0 {
        return -1;
    }
    let subject = if len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(ptr, len) }
    };
    i32::from(subject_has_usable_sample_filename(subject))
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_is_auxiliary_nzb_subject(
    subject_ptr: *const u8,
    subject_len: usize,
    support_regex_ptr: *const u8,
    support_regex_len: usize,
) -> i32 {
    if (subject_ptr.is_null() && subject_len > 0)
        || (support_regex_ptr.is_null() && support_regex_len > 0)
    {
        return -1;
    }
    let support_regex = if support_regex_len == 0 {
        DEFAULT_SUPPORT_REGEX_BYTES
    } else {
        unsafe { slice::from_raw_parts(support_regex_ptr, support_regex_len) }
    };
    if support_regex != DEFAULT_SUPPORT_REGEX_BYTES {
        return -1;
    }
    let subject = if subject_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(subject_ptr, subject_len) }
    };
    i32::from(is_support_file(subject))
}

fn collapse_alnum_dots_into(input: &[u8], out: &mut OutCursor<'_>) {
    let mut pending_dot = false;
    let mut has_any = false;
    for &b in input {
        if b.is_ascii_alphanumeric() {
            if pending_dot && has_any {
                out.push(b'.');
            }
            pending_dot = false;
            has_any = true;
            out.push(b.to_ascii_lowercase());
        } else {
            pending_dot = true;
        }
    }
}

fn subject_matches_release(subject: &[u8], release_name: &[u8]) -> bool {
    let mut norm_subject_raw = [0u8; 512];
    let subject_vec;
    let norm_subject: &[u8] = if subject.len() <= 512 {
        let mut cur = OutCursor {
            buf: &mut norm_subject_raw,
            pos: 0,
        };
        archive_candidate_file_name_into(subject, &mut cur);
        let len = cur.pos;
        &norm_subject_raw[..len]
    } else {
        subject_vec = archive_candidate_file_name_vec(subject);
        &subject_vec
    };

    let mut sub_stack = [0u8; 512];
    let mut sub_vec;
    let sub_tokens: &[u8] = if norm_subject.len() <= 512 {
        let mut cur = OutCursor {
            buf: &mut sub_stack,
            pos: 0,
        };
        collapse_alnum_dots_into(norm_subject, &mut cur);
        let len = cur.pos;
        &sub_stack[..len]
    } else {
        sub_vec = vec![0u8; norm_subject.len()];
        let mut cur = OutCursor {
            buf: &mut sub_vec,
            pos: 0,
        };
        collapse_alnum_dots_into(norm_subject, &mut cur);
        let len = cur.pos;
        &sub_vec[..len]
    };

    if sub_tokens.is_empty() {
        return false;
    }

    let mut rel_stack = [0u8; 512];
    let mut rel_vec;
    let rel_tokens: &[u8] = if release_name.len() <= 512 {
        let mut cur = OutCursor {
            buf: &mut rel_stack,
            pos: 0,
        };
        collapse_alnum_dots_into(release_name, &mut cur);
        let len = cur.pos;
        &rel_stack[..len]
    } else {
        rel_vec = vec![0u8; release_name.len()];
        let mut cur = OutCursor {
            buf: &mut rel_vec,
            pos: 0,
        };
        collapse_alnum_dots_into(release_name, &mut cur);
        let len = cur.pos;
        &rel_vec[..len]
    };

    if rel_tokens.is_empty() {
        return false;
    }

    find_ascii_ci(sub_tokens, rel_tokens).is_some()
        || find_ascii_ci(rel_tokens, sub_tokens).is_some()
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_subject_matches_release(
    subject_ptr: *const u8,
    subject_len: usize,
    release_ptr: *const u8,
    release_len: usize,
) -> i32 {
    if (subject_ptr.is_null() && subject_len > 0) || (release_ptr.is_null() && release_len > 0) {
        return -1;
    }
    let subject = if subject_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(subject_ptr, subject_len) }
    };
    let release_name = if release_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(release_ptr, release_len) }
    };
    i32::from(subject_matches_release(subject, release_name))
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_archived_media_range(
    range_ptr: *const u8,
    range_len: usize,
    name_ptr: *const u8,
    name_len: usize,
    file_size: i64,
    next_offset: i64,
    has_next_offset: u32,
    data_len: i64,
    max_sample_bytes: i64,
    min_main_video_bytes: i64,
    out_bounds: *mut i64,
) -> i32 {
    if (range_ptr.is_null() && range_len > 0)
        || (name_ptr.is_null() && name_len > 0)
        || out_bounds.is_null()
    {
        return -1;
    }
    let range_bytes = if range_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(range_ptr, range_len) }
    };
    let name_bytes = if name_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(name_ptr, name_len) }
    };

    let (start, end) = if let Some(dash) = range_bytes.iter().position(|&b| b == b'-') {
        (
            parse_php_int(&range_bytes[..dash]),
            parse_php_int(&range_bytes[dash + 1..]),
        )
    } else {
        (parse_php_int(range_bytes), -1)
    };

    let in_volume_end = if has_next_offset != 0 && next_offset > start {
        next_offset.saturating_sub(1)
    } else {
        start.saturating_add(file_size).saturating_sub(1)
    };
    let available = if data_len > 0 {
        in_volume_end
            .min(data_len - 1)
            .saturating_sub(start)
            .saturating_add(1)
    } else {
        0
    };
    let head_len = available.min(max_sample_bytes).max(0);
    let is_sample = (name_len > 0 && has_sample_token(name_bytes)) || file_size < min_main_video_bytes;

    unsafe {
        *out_bounds.add(0) = start;
        *out_bounds.add(1) = end;
        *out_bounds.add(2) = in_volume_end;
        *out_bounds.add(3) = available;
        *out_bounds.add(4) = head_len;
        *out_bounds.add(5) = i64::from(is_sample);
    }

    i32::from(start >= 0 && start < data_len)
}

fn parse_ffmpeg_clock_seconds(value: &[u8]) -> f64 {
    let s = if value.ends_with(b"\n") {
        &value[..value.len() - 1]
    } else {
        value
    };
    let Some(c1) = s.iter().position(|&b| b == b':') else {
        return -1.0;
    };
    let hours_bytes = &s[..c1];
    if hours_bytes.is_empty() || !hours_bytes.iter().all(u8::is_ascii_digit) {
        return -1.0;
    }
    if hours_bytes.len() > 15 {
        return -2.0;
    }
    let rest = &s[c1 + 1..];
    if rest.len() < 5
        || !rest[0].is_ascii_digit()
        || !rest[1].is_ascii_digit()
        || rest[2] != b':'
        || !rest[3].is_ascii_digit()
        || !rest[4].is_ascii_digit()
    {
        return -1.0;
    }
    let sec_bytes = &rest[3..];
    if sec_bytes.len() > 2 {
        if sec_bytes[2] != b'.'
            || sec_bytes.len() == 3
            || !sec_bytes[3..].iter().all(u8::is_ascii_digit)
        {
            return -1.0;
        }
    }

    let mut hours: i64 = 0;
    for &b in hours_bytes {
        hours = hours * 10 + (b - b'0') as i64;
    }
    let minutes: i64 = ((rest[0] - b'0') as i64) * 10 + ((rest[1] - b'0') as i64);
    let sec_str = unsafe { core::str::from_utf8_unchecked(sec_bytes) };
    let Ok(seconds) = sec_str.parse::<f64>() else {
        return -1.0;
    };
    let total = (hours * 3600 + minutes * 60) as f64 + seconds;
    if total.is_finite() && total > 0.0 {
        total
    } else {
        -1.0
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_ffmpeg_clock_seconds(ptr: *const u8, len: usize) -> f64 {
    if ptr.is_null() && len > 0 {
        return -2.0;
    }
    let value = if len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(ptr, len) }
    };
    parse_ffmpeg_clock_seconds(value)
}

/// Codec pretty-print code:
/// 0 = unchanged, 1 = HEVC, 2 = h.264, 3 = MPEG-4, 4 = MPEG-2,
/// 5 = MPEG-1, 6 = DivX, 7 = XviD, 8 = wmv
pub(crate) fn classify_pretty_codec(codec: &[u8]) -> i32 {
    let s = if codec.ends_with(b"\n") {
        &codec[..codec.len() - 1]
    } else {
        codec
    };
    if s == b"36" || find_ascii_ci(codec, b"hevc").is_some() {
        return 1;
    }
    if s == b"7"
        || s == b"27"
        || s.eq_ignore_ascii_case(b"h264")
        || find_ascii_ci(codec, b"avc").is_some()
    {
        return 2;
    }
    if s == b"20"
        || s.eq_ignore_ascii_case(b"fmp4")
        || s.eq_ignore_ascii_case(b"mp42")
        || s.eq_ignore_ascii_case(b"mp43")
        || s.eq_ignore_ascii_case(b"mpg4")
        || find_ascii_ci(codec, b"asp").is_some()
    {
        return 3;
    }
    if s == b"2" {
        return 4;
    }
    if codec == b"MPEG" {
        return 5;
    }
    if find_ascii_ci(codec, b"dx50").is_some()
        || find_ascii_ci(codec, b"divx").is_some()
        || find_ascii_ci(codec, b"div3").is_some()
    {
        return 6;
    }
    if find_ascii_ci(codec, b"xvid").is_some() {
        return 7;
    }
    if find_ascii_ci(codec, b"wmv").is_some() || find_ascii_ci(codec, b"wvc1").is_some() {
        return 8;
    }
    0
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_codec_make_pretty(ptr: *const u8, len: usize) -> i32 {
    if ptr.is_null() && len > 0 {
        return -1;
    }
    let codec = if len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(ptr, len) }
    };
    classify_pretty_codec(codec)
}

#[cfg(test)]
mod wave7_episode_archive_rank_tests {
    use super::*;

    #[test]
    fn episode_searchname_patterns_and_precedence() {
        assert_eq!(
            episode_parse_searchname(b"Show.Name.S02E14.1080p.WEB-DL", true, true),
            (2, 14)
        );
        assert_eq!(
            episode_parse_searchname(b"Show.Name.2x07.720p.HDTV", true, true),
            (2, 7)
        );
        assert_eq!(
            episode_parse_searchname(b"Show.Season_03_Episode-019.mkv", true, true),
            (3, 19)
        );
        assert_eq!(
            episode_parse_searchname(b"Show.Name.305.HDTV", true, true),
            (3, 5)
        );
        // Compact 3-digit format does not fire when only one of series/episode is missing
        assert_eq!(
            episode_parse_searchname(b"Show.Name.305.Part.9.HDTV", false, true),
            (-1, 9)
        );
        assert_eq!(
            episode_parse_searchname(b"Daily.Show.2024.01.15.1080p", true, true),
            (2024, 115)
        );
        assert_eq!(
            episode_parse_searchname(b"Mini.Series.Part.4.1080p", true, true),
            (1, 4)
        );
        assert_eq!(
            episode_parse_searchname(b"Anime.Title.Ep12.1080p", false, true),
            (-1, 12)
        );
    }

    #[test]
    fn canonical_firstaired_validation() {
        assert_eq!(parse_canonical_firstaired(b"2024-01-15"), Some((2024, 115)));
        assert_eq!(
            parse_canonical_firstaired(b"2024-02-29 23:59:59"),
            Some((2024, 229))
        );
        assert_eq!(parse_canonical_firstaired(b"2023-02-29"), None);
        assert_eq!(parse_canonical_firstaired(b"2024.01.15"), None);
    }

    #[test]
    fn archive_nfo_and_standalone_video_detection() {
        assert!(archive_is_nfo_file(b"dir/00-group.txt"));
        assert!(archive_is_nfo_file(b"dir/group-release.1080p.txt"));
        assert!(archive_is_nfo_file(b"dir/file_id.diz"));
        assert!(!archive_is_nfo_file(b"dir/notes_extra.txt"));

        assert_eq!(
            detect_standalone_video(b"RIFF\0\0\0\0AVI \0\0\0\0"),
            1
        );
        assert_eq!(
            detect_standalone_video(b"\0\0\0\x18ftypmp42\0\0\0\0"),
            5
        );
    }

    #[test]
    fn wanted_files_classification_and_ordering() {
        let cfg = VideoExtConfig::parse(DEFAULT_VIDEO_REGEX_BYTES).unwrap();
        assert_eq!(classify_wanted_file(b"Movie.part01.rar", &cfg), Some(0));
        assert_eq!(classify_wanted_file(b"Movie.part02.rar", &cfg), None);
        assert_eq!(classify_wanted_file(b"Movie.rar", &cfg), Some(0));
        assert_eq!(classify_wanted_file(b"Movie.7z.001", &cfg), Some(0));
        assert_eq!(classify_wanted_file(b"Movie.sample.mkv", &cfg), Some(1));
        assert_eq!(classify_wanted_file(b"Movie.1080p.mkv", &cfg), Some(2));
        assert_eq!(classify_wanted_file(b"poster.jpg", &cfg), Some(3));
    }

    #[test]
    fn wave9_release_and_media_helpers() {
        let mut buf = [0u8; 256];
        let mut cur = OutCursor {
            buf: &mut buf,
            pos: 0,
        };
        let written = parse_release_files_into(
            b"Movie.2026.1080p.mkv*1073741824|Sample/sample.mkv*52428800|invalid|bad*abc",
            &mut cur,
        );
        assert!(written > 0);
        let parts: Vec<&[u8]> = buf[..written as usize].split(|&b| b == 0).collect();
        assert_eq!(
            parts,
            vec![
                &b"Movie.2026.1080p.mkv"[..],
                &b"1073741824"[..],
                &b"Sample/sample.mkv"[..],
                &b"52428800"[..],
            ]
        );

        let cfg = VideoExtConfig::parse(DEFAULT_VIDEO_REGEX_BYTES).unwrap();
        assert!(subject_contains_video_pattern(
            b"[01/20] - \"Movie.2026.1080p.mkv\" yEnc (1/50)",
            &cfg
        ));
        assert!(!subject_contains_video_pattern(
            b"[01/20] - \"Movie.2026.1080p.rar\" yEnc (1/50)",
            &cfg
        ));

        assert!(subject_has_usable_sample_filename(
            b"\"Movie.2026.sample.mkv\" yEnc (1/10)"
        ));
        assert!(!subject_has_usable_sample_filename(
            b"\"Movie.2026.sample.jpg\""
        ));
        assert!(!subject_has_usable_sample_filename(
            b"\"Movie.2026.sample.rar\" yEnc (1/10)"
        ));

        assert!(subject_matches_release(
            b"[01/50] - \"The.Matrix.1999.1080p.BluRay.x264-GROUP.mkv\" yEnc (1/90)",
            b"The Matrix 1999 1080p BluRay x264-GROUP"
        ));
        assert!(!subject_matches_release(
            b"\"Unrelated.Show.S01E01.mkv\" yEnc (1/10)",
            b"The Matrix 1999 1080p BluRay x264-GROUP"
        ));

        let mut bounds = [0i64; 6];
        let status = unsafe {
            nntmux_archived_media_range(
                b"128-65663".as_ptr(),
                9,
                b"Sample/movie-sample.mkv".as_ptr(),
                23,
                65536,
                65664,
                1,
                32768,
                16384,
                1048576,
                bounds.as_mut_ptr(),
            )
        };
        assert_eq!(status, 1);
        assert_eq!(bounds, [128, 65663, 65663, 32640, 16384, 1]);

        assert!((parse_ffmpeg_clock_seconds(b"01:30:15.500") - 5415.5).abs() < 1e-6);
        assert_eq!(parse_ffmpeg_clock_seconds(b"00:00:00.000"), -1.0);
        assert_eq!(parse_ffmpeg_clock_seconds(b"invalid"), -1.0);

        assert_eq!(classify_pretty_codec(b"36"), 1);
        assert_eq!(classify_pretty_codec(b"V_MPEGH/ISO/HEVC"), 1);
        assert_eq!(classify_pretty_codec(b"V_MPEG4/ISO/AVC"), 2);
        assert_eq!(classify_pretty_codec(b"FMP4"), 3);
        assert_eq!(classify_pretty_codec(b"2"), 4);
        assert_eq!(classify_pretty_codec(b"MPEG"), 5);
        assert_eq!(classify_pretty_codec(b"DX50"), 6);
        assert_eq!(classify_pretty_codec(b"XVID"), 7);
        assert_eq!(classify_pretty_codec(b"WVC1"), 8);
        assert_eq!(classify_pretty_codec(b"AV1"), 0);
    }
}

// ---------------------------------------------------------------------------
// ReleaseFileManager / MediaExtractionService / UsenetDownloadService helpers
// ---------------------------------------------------------------------------

/// Upper bound for fused release-name extraction; longer inputs decline to PHP so the
/// memoized PREDB matcher never diverges from PCRE backtrack-limit behaviour.
const RFM_MAX_RELEASE_NAME_INPUT: usize = 1024;

/// Backtracking steps allowed for the PREDB matcher before declining to PHP. PCRE's
/// default `pcre.backtrack_limit` (1,000,000) is far above this, so any input the matcher
/// completes is one PCRE also completes without a match-limit error.
const RFM_PREDB_STEP_BUDGET: u32 = 20_000;

#[inline]
unsafe fn rfm_input<'a>(ptr: *const u8, len: usize) -> Option<&'a [u8]> {
    if len == 0 {
        return Some(&[]);
    }
    if ptr.is_null() {
        return None;
    }
    Some(unsafe { slice::from_raw_parts(ptr, len) })
}

/// Strip the single trailing `\n` that a non-`D` PCRE `$` may match before.
#[inline]
fn rfm_without_final_newline(s: &[u8]) -> &[u8] {
    match s.last() {
        Some(b'\n') => &s[..s.len() - 1],
        _ => s,
    }
}

#[inline]
fn rfm_ends_with_ci(s: &[u8], suffix_lower: &[u8]) -> bool {
    s.len() >= suffix_lower.len()
        && s[s.len() - suffix_lower.len()..].eq_ignore_ascii_case(suffix_lower)
}

/// `ReleaseFileManager::isNfoFilename()` for C/POSIX byte semantics.
pub(crate) fn rfm_is_nfo_filename(filename: &[u8]) -> bool {
    // `/\.(?:nfo|diz|info?)$/i` on the full filename.
    let full = rfm_without_final_newline(filename);
    if rfm_ends_with_ci(full, b".nfo")
        || rfm_ends_with_ci(full, b".diz")
        || rfm_ends_with_ci(full, b".inf")
        || rfm_ends_with_ci(full, b".info")
    {
        return true;
    }

    let base = rfm_without_final_newline(posix_basename(filename));

    // `/^(?:file[_-]?id|readme|release|info(?:rmation)?|about|notes?)\.(?:txt|diz)$/i`
    if base.len() >= 4 && base[base.len() - 4] == b'.' {
        let ext = &base[base.len() - 3..];
        if ext.eq_ignore_ascii_case(b"txt") || ext.eq_ignore_ascii_case(b"diz") {
            let stem = &base[..base.len() - 4];
            if stem.len() <= 11 {
                let mut buf = [0u8; 11];
                for (i, &b) in stem.iter().enumerate() {
                    buf[i] = b.to_ascii_lowercase();
                }
                if matches!(
                    &buf[..stem.len()],
                    b"fileid"
                        | b"file_id"
                        | b"file-id"
                        | b"readme"
                        | b"release"
                        | b"info"
                        | b"information"
                        | b"about"
                        | b"note"
                        | b"notes"
                ) {
                    return true;
                }
            }
        }
    }

    // `/^00-[a-z0-9_-]+\.nfo$/i`, `/^0+-[a-z0-9_-]+\.nfo$/i` and
    // `/^[a-z0-9_-]+-[a-z0-9_.-]+\.nfo$/i`; the first two are subsets of the third.
    if rfm_ends_with_ci(base, b".nfo") {
        let stem = &base[..base.len() - 4];
        if stem
            .iter()
            .all(|&b| b.is_ascii_alphanumeric() || matches!(b, b'_' | b'-' | b'.'))
        {
            let first_dot = stem.iter().position(|&b| b == b'.').unwrap_or(stem.len());
            let limit = first_dot.min(stem.len().saturating_sub(1));
            if (1..limit).any(|i| stem[i] == b'-') {
                return true;
            }
        }
    }

    // `/info\.txt$/i`
    rfm_ends_with_ci(base, b"info.txt")
}

/// # Safety
///
/// `ptr` must be valid for reads of `len` bytes when non-null and `len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rfm_is_nfo_filename(ptr: *const u8, len: usize) -> i32 {
    match unsafe { rfm_input(ptr, len) } {
        Some(filename) => i32::from(rfm_is_nfo_filename(filename)),
        None => -1,
    }
}

/// `/\.(mkv|avi|...|r\d{2,3}|pkg|exe|msi)$/i` removal. Extensions contain no dot, so a
/// match can only start at the final dot.
fn rfm_strip_release_extension(s: &[u8]) -> &[u8] {
    let Some(dot) = s.iter().rposition(|&b| b == b'.') else {
        return s;
    };
    let ext = &s[dot + 1..];
    if ext.is_empty() || ext.len() > 4 {
        return s;
    }
    let mut buf = [0u8; 4];
    for (i, &b) in ext.iter().enumerate() {
        buf[i] = b.to_ascii_lowercase();
    }
    let lower = &buf[..ext.len()];
    let known = matches!(
        lower,
        b"mkv"
            | b"avi"
            | b"mp4"
            | b"m4v"
            | b"mpg"
            | b"mpeg"
            | b"wmv"
            | b"flv"
            | b"mov"
            | b"ts"
            | b"vob"
            | b"iso"
            | b"divx"
            | b"par"
            | b"par2"
            | b"nfo"
            | b"sfv"
            | b"nzb"
            | b"rar"
            | b"zip"
            | b"pkg"
            | b"exe"
            | b"msi"
    ) || (lower[0] == b'r'
        && (lower.len() == 3 || lower.len() == 4)
        && lower[1..].iter().all(u8::is_ascii_digit));
    if known { &s[..dot] } else { s }
}

#[inline]
fn rfm_ucwords_into(s: &[u8], delimiters: &[u8], out: &mut Vec<u8>) {
    out.reserve(s.len());
    let mut upper_next = true;
    for &b in s {
        out.push(if upper_next {
            b.to_ascii_uppercase()
        } else {
            b
        });
        upper_next = delimiters.contains(&b);
    }
}

#[inline]
fn rfm_predb_t(b: u8) -> bool {
    is_word_char(b) || matches!(b, b'.' | b'\'' | b'(' | b')' | b'[' | b']' | b'-')
}

#[inline]
fn rfm_predb_s(b: u8) -> bool {
    matches!(
        b,
        b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r' | b'.' | b'_' | b'-'
    )
}

/// Leftmost-first span of `ReleaseUpdateService::PREDB_REGEX`
/// (`[\w.'()\[\]-]+(?:[\s._-]+[\w.'()\[\]-]+)+[-.][\w]+`) over ASCII input, explored in
/// PCRE's greedy backtracking order. `Err(())` means the step budget ran out.
struct RfmPredbMatcher<'a> {
    s: &'a [u8],
    steps: u32,
}

type RfmPredbStep = Result<Option<usize>, ()>;

impl RfmPredbMatcher<'_> {
    fn find(s: &[u8]) -> Result<Option<(usize, usize)>, ()> {
        let mut matcher = RfmPredbMatcher { s, steps: 0 };
        for (start, &b) in s.iter().enumerate() {
            if rfm_predb_t(b)
                && let Some(end) = matcher.a_loop(start + 1)?
            {
                return Ok(Some((start, end)));
            }
        }
        Ok(None)
    }

    #[inline]
    fn at(&self, pos: usize) -> Option<u8> {
        self.s.get(pos).copied()
    }

    #[inline]
    fn tick(&mut self) -> Result<(), ()> {
        self.steps += 1;
        if self.steps > RFM_PREDB_STEP_BUDGET {
            Err(())
        } else {
            Ok(())
        }
    }

    /// Inside the leading `T+`, at least one byte consumed.
    fn a_loop(&mut self, pos: usize) -> RfmPredbStep {
        self.tick()?;
        if self.at(pos).is_some_and(rfm_predb_t)
            && let Some(end) = self.a_loop(pos + 1)?
        {
            return Ok(Some(end));
        }
        self.s_first(pos)
    }

    /// Start of a `(?:S+ T+)` iteration.
    fn s_first(&mut self, pos: usize) -> RfmPredbStep {
        if self.at(pos).is_some_and(rfm_predb_s) {
            self.s_loop(pos + 1)
        } else {
            Ok(None)
        }
    }

    fn s_loop(&mut self, pos: usize) -> RfmPredbStep {
        self.tick()?;
        if self.at(pos).is_some_and(rfm_predb_s)
            && let Some(end) = self.s_loop(pos + 1)?
        {
            return Ok(Some(end));
        }
        if self.at(pos).is_some_and(rfm_predb_t) {
            return self.t_loop(pos + 1);
        }
        Ok(None)
    }

    /// Inside an iteration's `T+`; then another iteration, then `[-.]\w+`.
    fn t_loop(&mut self, pos: usize) -> RfmPredbStep {
        self.tick()?;
        if self.at(pos).is_some_and(rfm_predb_t)
            && let Some(end) = self.t_loop(pos + 1)?
        {
            return Ok(Some(end));
        }
        if let Some(end) = self.s_first(pos)? {
            return Ok(Some(end));
        }
        if matches!(self.at(pos), Some(b'-' | b'.')) && self.at(pos + 1).is_some_and(is_word_char) {
            let mut end = pos + 2;
            while self.at(end).is_some_and(is_word_char) {
                end += 1;
            }
            return Ok(Some(end));
        }
        Ok(None)
    }
}

/// `ReleaseFileManager::extractReleaseNameFromFile()` with the default `FileNameCleaner`.
/// Returns `None` to decline to PHP, `Some(false)` for a PHP `null`, or `Some(true)` with
/// the extracted name written into `out`.
pub(crate) fn rfm_extract_release_name(filename: &[u8], out: &mut Vec<u8>) -> Option<bool> {
    if filename.len() > RFM_MAX_RELEASE_NAME_INPUT
        || filename.iter().any(|&b| b >= 0x80 || b == b'\n' || b == 0)
    {
        return None;
    }
    let base = posix_basename(filename);

    if find_ascii_ci(base, b"__nzbsplit__").is_some() {
        match crate::cleaning::nzbsplit_unwrap_vec(base, out) {
            None => return None,
            Some(true) => return Some(true),
            Some(false) => out.clear(),
        }
    }

    let cleaned = rfm_strip_release_extension(base);

    // `/^(.+[-.][A-Za-z0-9_]{2,})$/i`
    let run = cleaned
        .iter()
        .rev()
        .take_while(|&&b| is_word_char(b))
        .count();
    if run >= 2
        && cleaned.len() >= run + 2
        && matches!(cleaned[cleaned.len() - run - 1], b'-' | b'.')
    {
        rfm_ucwords_into(cleaned, b".-_ ", out);
        return Some(true);
    }

    match RfmPredbMatcher::find(cleaned) {
        Ok(Some((start, end))) => {
            rfm_ucwords_into(&cleaned[start..end], b".", out);
            Some(true)
        }
        Ok(None) => Some(false),
        Err(()) => None,
    }
}

/// Returns the written length, `-3` for a PHP `null`, `-2` when declined to PHP, or
/// `-(required)` when `out_cap` is too small.
///
/// # Safety
///
/// `ptr` must be valid for reads of `len` bytes when non-null and `len > 0`; `out_ptr`
/// must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rfm_extract_release_name(
    ptr: *const u8,
    len: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(filename) = (unsafe { rfm_input(ptr, len) }) else {
        return -2;
    };
    let mut out = Vec::new();
    match rfm_extract_release_name(filename, &mut out) {
        None => -2,
        Some(false) => -3,
        Some(true) => {
            if out.len() > out_cap {
                return -((out.len().max(4)) as isize);
            }
            if !out.is_empty() {
                unsafe { std::ptr::copy_nonoverlapping(out.as_ptr(), out_ptr, out.len()) };
            }
            out.len() as isize
        }
    }
}

/// `ReleaseFileManager::normalizeFileSize()` for strings: `1` with `*out` set, `0` for
/// `null`, `-1` on invalid pointers.
///
/// # Safety
///
/// `ptr` must be valid for reads of `len` bytes when non-null and `len > 0`; `out` must be
/// valid for one `i64` write.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rfm_normalize_file_size(
    ptr: *const u8,
    len: usize,
    out: *mut i64,
) -> i32 {
    if out.is_null() {
        return -1;
    }
    let Some(size) = (unsafe { rfm_input(ptr, len) }) else {
        return -1;
    };
    if size.is_empty() {
        return 0;
    }
    let mut value: i64 = 0;
    for &b in size {
        if !b.is_ascii_digit() {
            return 0;
        }
        match value
            .checked_mul(10)
            .and_then(|v| v.checked_add(i64::from(b - b'0')))
        {
            Some(next) => value = next,
            None => return 0,
        }
    }
    unsafe { *out = value };
    1
}

/// `MediaExtractionService::audioTagText()` for strings: `1` with the trimmed span written
/// to `out[0..2]` (start, length), `0` for `null`, `-1` on invalid pointers.
///
/// # Safety
///
/// `ptr` must be valid for reads of `len` bytes when non-null and `len > 0`; `out` must be
/// valid for two `usize` writes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rfm_audio_tag_span(
    ptr: *const u8,
    len: usize,
    out: *mut usize,
) -> i32 {
    if out.is_null() {
        return -1;
    }
    let Some(value) = (unsafe { rfm_input(ptr, len) }) else {
        return -1;
    };
    let trimmed = trim_php(value);
    if trimmed.is_empty()
        || trimmed.iter().any(|&b| b < 0x20 || b == 0x7F)
        || std::str::from_utf8(trimmed).is_err()
    {
        return 0;
    }
    let start = trimmed.as_ptr() as usize - value.as_ptr() as usize;
    unsafe {
        *out = start;
        *out.add(1) = trimmed.len();
    }
    1
}

/// `MediaExtractionService::audioReleaseTitle()` over ASCII inputs. Returns the written
/// length, `-3` for a PHP `null`, `-2` when declined to PHP, or `-(required)` when
/// `out_cap` is too small.
///
/// # Safety
///
/// Each input pointer must be valid for reads of its length when non-null and the length
/// is positive; `out_ptr` must be valid for writes of `out_cap` bytes.
#[unsafe(no_mangle)]
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn nntmux_rfm_audio_release_title(
    performer_ptr: *const u8,
    performer_len: usize,
    album_ptr: *const u8,
    album_len: usize,
    date_ptr: *const u8,
    date_len: usize,
    has_date: i32,
    ext_ptr: *const u8,
    ext_len: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    let (Some(performer), Some(album), Some(date), Some(ext)) = (unsafe {
        (
            rfm_input(performer_ptr, performer_len),
            rfm_input(album_ptr, album_len),
            rfm_input(date_ptr, date_len),
            rfm_input(ext_ptr, ext_len),
        )
    }) else {
        return -2;
    };
    if !performer.is_ascii() || !album.is_ascii() || !date.is_ascii() || !ext.is_ascii() {
        return -2;
    }
    let mut title = Vec::with_capacity(performer.len() + album.len() + ext.len() + 12);
    title.extend_from_slice(performer);
    title.extend_from_slice(b" - ");
    title.extend_from_slice(album);
    if has_date != 0 {
        // `/(?:19|20)\d\d/`
        if let Some(year) = date.windows(4).find(|w| {
            (w[0..2] == *b"19" || w[0..2] == *b"20")
                && w[2].is_ascii_digit()
                && w[3].is_ascii_digit()
        }) {
            title.extend_from_slice(b" (");
            title.extend_from_slice(year);
            title.push(b')');
        }
    }
    title.push(b' ');
    title.extend(ext.iter().map(u8::to_ascii_uppercase));
    // ASCII `\p{C}` is exactly the Cc range.
    if title.len() > 255 || title.iter().any(|&b| b < 0x20 || b == 0x7F) {
        return -3;
    }
    if title.len() > out_cap {
        return -((title.len().max(4)) as isize);
    }
    unsafe { std::ptr::copy_nonoverlapping(title.as_ptr(), out_ptr, title.len()) };
    title.len() as isize
}

/// `UsenetDownloadService::isGroupUnavailableMessage()`.
///
/// # Safety
///
/// `ptr` must be valid for reads of `len` bytes when non-null and `len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rfm_is_group_unavailable(ptr: *const u8, len: usize) -> i32 {
    match unsafe { rfm_input(ptr, len) } {
        Some(message) => i32::from(
            find_ascii_ci(message, b"no such news group").is_some()
                || find_ascii_ci(message, b"group not found").is_some(),
        ),
        None => -1,
    }
}

/// `/\.(?:rar|7z(?:\.\d+)?|zip|\d{3})$/i`
pub(crate) fn rfm_is_mapped_archive_name(name: &[u8]) -> bool {
    let n = rfm_without_final_newline(name);
    if rfm_ends_with_ci(n, b".rar") || rfm_ends_with_ci(n, b".zip") || rfm_ends_with_ci(n, b".7z") {
        return true;
    }
    let digits = n.iter().rev().take_while(|b| b.is_ascii_digit()).count();
    if digits == 0 || n.len() <= digits || n[n.len() - digits - 1] != b'.' {
        return false;
    }
    if digits == 3 {
        return true;
    }
    rfm_ends_with_ci(&n[..n.len() - digits - 1], b".7z")
}

/// `stripos($name, 'sample') === false` and the default video-file regex anchored at `$`.
pub(crate) fn rfm_is_mapped_video_name(name: &[u8]) -> bool {
    if find_ascii_ci(name, b"sample").is_some() {
        return false;
    }
    let n = rfm_without_final_newline(name);
    let Some(dot) = n.iter().rposition(|&b| b == b'.') else {
        return false;
    };
    let ext = &n[dot + 1..];
    if ext.is_empty() || ext.len() > 4 {
        return false;
    }
    let mut buf = [0u8; 4];
    for (i, &b) in ext.iter().enumerate() {
        buf[i] = b.to_ascii_uppercase();
    }
    matches!(
        &buf[..ext.len()],
        b"AVI"
            | b"F4V"
            | b"IFO"
            | b"M1V"
            | b"M2V"
            | b"M4V"
            | b"MKV"
            | b"MOV"
            | b"MP4"
            | b"MPEG"
            | b"MPG"
            | b"MPGV"
            | b"MPV"
            | b"OGV"
            | b"QT"
            | b"RM"
            | b"RMVB"
            | b"TS"
            | b"VOB"
            | b"WMV"
    )
}

/// Bit `1`: mapped archive name; bit `2`: default-regex main-video name. `-1` on invalid
/// pointers.
///
/// # Safety
///
/// `ptr` must be valid for reads of `len` bytes when non-null and `len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rfm_mapped_article_kind(ptr: *const u8, len: usize) -> i32 {
    match unsafe { rfm_input(ptr, len) } {
        Some(name) => {
            i32::from(rfm_is_mapped_archive_name(name))
                | (i32::from(rfm_is_mapped_video_name(name)) << 1)
        }
        None => -1,
    }
}

#[cfg(test)]
mod wave10_release_file_manager_audio_tag_tests {
    use super::*;

    fn extract(s: &[u8]) -> Option<Option<Vec<u8>>> {
        let mut out = Vec::new();
        rfm_extract_release_name(s, &mut out).map(|hit| hit.then_some(out))
    }

    #[test]
    fn nfo_filename_patterns() {
        assert!(rfm_is_nfo_filename(b"Movie.2026-GRP.nfo"));
        assert!(rfm_is_nfo_filename(b"dir/FILE_ID.DIZ"));
        assert!(rfm_is_nfo_filename(b"x.info\n"));
        assert!(rfm_is_nfo_filename(b"a/00-grp.nfo/"));
        assert!(rfm_is_nfo_filename(b"README.txt"));
        assert!(rfm_is_nfo_filename(b"myinfo.txt"));
        assert!(!rfm_is_nfo_filename(b"a/grp.nfo/"));
        assert!(!rfm_is_nfo_filename(b"a/.x-y.nfo/"));
        assert!(!rfm_is_nfo_filename(b"movie.mkv"));
        assert!(!rfm_is_nfo_filename(b""));
    }

    #[test]
    fn release_name_extraction() {
        assert_eq!(
            extract(b"dir/movie.2026.1080p.bluray-grp.mkv"),
            Some(Some(b"Movie.2026.1080p.Bluray-Grp".to_vec()))
        );
        assert_eq!(
            extract(b"show.name.s01e01.720p-grp (1)"),
            Some(Some(b"Show.Name.S01e01.720p-grp".to_vec()))
        );
        assert_eq!(extract(b"track 01"), Some(None));
        assert_eq!(extract(b"x.r00"), Some(None));
        assert_eq!(extract("caf\u{e9}.mkv".as_bytes()), None);
        assert_eq!(extract(b"a\nb"), None);
        let mut pathological = b".".repeat(40);
        pathological.extend_from_slice(b"!ab.cd-ef (");
        assert_eq!(extract(&pathological), None);
    }

    #[test]
    fn mapped_article_and_group_helpers() {
        assert!(rfm_is_mapped_archive_name(b"a.part01.RAR"));
        assert!(rfm_is_mapped_archive_name(b"a.7z.0001"));
        assert!(rfm_is_mapped_archive_name(b"a.001\n"));
        assert!(!rfm_is_mapped_archive_name(b"a.0001"));
        assert!(rfm_is_mapped_video_name(b"Movie.MKV"));
        assert!(!rfm_is_mapped_video_name(b"Movie.Sample.mkv"));
        assert!(!rfm_is_mapped_video_name(b"Movie.mkv.par2"));
        let msg = b"411 NO SUCH NEWS GROUP";
        assert_eq!(
            unsafe { nntmux_rfm_is_group_unavailable(msg.as_ptr(), msg.len()) },
            1
        );
    }
}

// ---------------------------------------------------------------------------
// Wave 11: PartHandler, HeaderStorageService & Par2CandidateProgress FFI
// ---------------------------------------------------------------------------

#[inline]
fn is_pcre2_ascii_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C)
}

#[inline]
fn is_file_counter_start(b: u8) -> bool {
    matches!(b, b'[' | b'(') || is_pcre2_ascii_space(b)
}

#[inline]
fn is_file_counter_end(b: u8) -> bool {
    matches!(b, b']' | b')' | b'[' | b'$' | b':') || is_pcre2_ascii_space(b)
}

#[inline]
pub(crate) fn part_validation_error_code(part_number: i64, msg_id: &[u8]) -> i32 {
    if part_number <= 0 {
        return 1;
    }
    let trimmed = trim_php(msg_id);
    if trimmed.is_empty()
        || trimmed.len() > 255
        || !trimmed.iter().all(|&b| (0x20..=0x7E).contains(&b))
    {
        return 2;
    }
    0
}

#[inline]
pub(crate) fn part_should_prefer_tuple(
    cand_has_msg: bool,
    cand_size: i64,
    cand_num: i64,
    curr_has_msg: bool,
    curr_size: i64,
    curr_num: i64,
) -> bool {
    if cand_has_msg != curr_has_msg {
        return cand_has_msg;
    }
    if cand_size != curr_size {
        return cand_size > curr_size;
    }
    cand_num < curr_num
}

/// Validates a part header (`PartHandler::validationError`):
/// - `-1`: invalid pointer
/// - `0`: valid (`null`)
/// - `1`: `'Invalid part number'` (`part_number <= 0`)
/// - `2`: `'Invalid message ID'` (empty after PHP trim, `> 255` bytes, or outside `0x20..=0x7E`)
///
/// # Safety
///
/// `msg_id_ptr` must be valid for reads of `msg_id_len` bytes when non-null and `msg_id_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_part_validation_error(
    part_number: i64,
    msg_id_ptr: *const u8,
    msg_id_len: usize,
) -> i32 {
    if msg_id_ptr.is_null() && msg_id_len > 0 {
        return -1;
    }
    let msg_id = if msg_id_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(msg_id_ptr, msg_id_len) }
    };
    part_validation_error_code(part_number, msg_id)
}

/// Compares two duplicate part rows (`PartHandler::shouldPrefer`):
/// - `-1`: invalid pointer
/// - `1`: candidate is preferred
/// - `0`: current is kept
///
/// # Safety
///
/// `cand_msg_ptr` and `curr_msg_ptr` must be valid for reads of their respective lengths when non-null.
#[unsafe(no_mangle)]
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn nntmux_part_should_prefer(
    cand_msg_ptr: *const u8,
    cand_msg_len: usize,
    cand_size: i64,
    cand_num: i64,
    curr_msg_ptr: *const u8,
    curr_msg_len: usize,
    curr_size: i64,
    curr_num: i64,
) -> i32 {
    if (cand_msg_ptr.is_null() && cand_msg_len > 0) || (curr_msg_ptr.is_null() && curr_msg_len > 0)
    {
        return -1;
    }
    let cand_msg = if cand_msg_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(cand_msg_ptr, cand_msg_len) }
    };
    let curr_msg = if curr_msg_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(curr_msg_ptr, curr_msg_len) }
    };
    let cand_has_msg = !trim_php(cand_msg).is_empty();
    let curr_has_msg = !trim_php(curr_msg).is_empty();
    i32::from(part_should_prefer_tuple(
        cand_has_msg,
        cand_size,
        cand_num,
        curr_has_msg,
        curr_size,
        curr_num,
    ))
}

#[derive(Clone, Copy)]
struct PartWinner {
    index: u32,
    binaries_id: i64,
    partnumber: i64,
    has_msg: bool,
    size: i64,
    number: i64,
}

/// Batch deduplicates `count` encoded part records by `(binaries_id, partnumber)` using
/// `PartHandler::shouldPrefer`, optionally sorting the winning entries by `(binaries_id ASC, partnumber ASC)`
/// when `sort_by_pk != 0`.
///
/// Input format per record:
/// `[binaries_id: i64 NE][partnumber: i64 NE][size: i64 NE][number: i64 NE][msg_len: u32 NE][msg_bytes: msg_len]`
///
/// Output format:
/// `winners.len()` `u32` (native-endian) 0-based input indices (`winners.len() * 4` bytes).
///
/// # Safety
///
/// `input_ptr` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_ptr` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_part_deduplicate(
    input_ptr: *const u8,
    input_len: usize,
    count: usize,
    sort_by_pk: u32,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if (input_ptr.is_null() && input_len > 0) || (out_ptr.is_null() && out_cap > 0) {
        return -1;
    }
    if count == 0 {
        return if input_len == 0 { 0 } else { -1 };
    }
    if count > (u32::MAX as usize) {
        return -1;
    }
    let data = if input_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(input_ptr, input_len) }
    };

    let mut winners: Vec<PartWinner> = Vec::with_capacity(count);
    // Open-addressed hash table keyed by (binaries_id, partnumber) -> (winners_idx + 1).
    let table_cap = (count.saturating_mul(2)).next_power_of_two().max(16);
    let table_mask = table_cap - 1;
    let mut table_keys: Vec<(i64, i64)> = vec![(0, 0); table_cap];
    let mut table_vals: Vec<u32> = vec![0; table_cap];
    let mut off = 0usize;

    for idx in 0..count {
        if off + 36 > data.len() {
            return -1;
        }
        let binaries_id = i64::from_ne_bytes(data[off..off + 8].try_into().unwrap());
        let partnumber = i64::from_ne_bytes(data[off + 8..off + 16].try_into().unwrap());
        let size = i64::from_ne_bytes(data[off + 16..off + 24].try_into().unwrap());
        let number = i64::from_ne_bytes(data[off + 24..off + 32].try_into().unwrap());
        let msg_len = u32::from_ne_bytes(data[off + 32..off + 36].try_into().unwrap()) as usize;
        off += 36;
        if off + msg_len > data.len() {
            return -1;
        }
        let has_msg = !trim_php(&data[off..off + msg_len]).is_empty();
        off += msg_len;

        let h = ((binaries_id as u64).wrapping_mul(0x9E37_79B9_7F4A_7C15))
            ^ ((partnumber as u64).wrapping_mul(0xBF58_476D_1CE4_E5B9));
        let mut slot = ((h ^ (h >> 32)) as usize) & table_mask;
        loop {
            let entry = table_vals[slot];
            if entry == 0 {
                table_keys[slot] = (binaries_id, partnumber);
                table_vals[slot] = (winners.len() + 1) as u32;
                winners.push(PartWinner {
                    index: idx as u32,
                    binaries_id,
                    partnumber,
                    has_msg,
                    size,
                    number,
                });
                break;
            }
            if table_keys[slot] == (binaries_id, partnumber) {
                let pos = (entry - 1) as usize;
                let current = winners[pos];
                if part_should_prefer_tuple(
                    has_msg,
                    size,
                    number,
                    current.has_msg,
                    current.size,
                    current.number,
                ) {
                    winners[pos] = PartWinner {
                        index: idx as u32,
                        binaries_id,
                        partnumber,
                        has_msg,
                        size,
                        number,
                    };
                }
                break;
            }
            slot = (slot + 1) & table_mask;
        }
    }

    if off != data.len() {
        return -1;
    }

    if sort_by_pk != 0 {
        winners.sort_by_key(|w| (w.binaries_id, w.partnumber));
    }

    let needed = winners.len() * 4;
    if needed > out_cap {
        return -(needed.max(4) as isize);
    }
    let out = unsafe { slice::from_raw_parts_mut(out_ptr, needed) };
    for (i, w) in winners.iter().enumerate() {
        out[i * 4..i * 4 + 4].copy_from_slice(&w.index.to_ne_bytes());
    }
    needed as isize
}

/// Stably sorts `count` `(binaries_id: i64 NE, partnumber: i64 NE)` pairs (`16 * count` bytes)
/// by `(binaries_id ASC, partnumber ASC)` and writes `count` `u32` (native-endian) 0-based indices
/// to `out_ptr`.
///
/// # Safety
///
/// `input_ptr` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_ptr` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_part_sort_primary_key(
    input_ptr: *const u8,
    input_len: usize,
    count: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if (input_ptr.is_null() && input_len > 0) || (out_ptr.is_null() && out_cap > 0) {
        return -1;
    }
    if count == 0 {
        return if input_len == 0 { 0 } else { -1 };
    }
    if count > (u32::MAX as usize) || input_len != count.saturating_mul(16) {
        return -1;
    }
    let needed = count * 4;
    if needed > out_cap {
        return -(needed.max(4) as isize);
    }
    let data = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    let mut items: Vec<(i64, i64, u32)> = Vec::with_capacity(count);
    for idx in 0..count {
        let base = idx * 16;
        let bid = i64::from_ne_bytes(data[base..base + 8].try_into().unwrap());
        let pnum = i64::from_ne_bytes(data[base + 8..base + 16].try_into().unwrap());
        items.push((bid, pnum, idx as u32));
    }
    items.sort_by_key(|&(bid, pnum, _)| (bid, pnum));

    let out = unsafe { slice::from_raw_parts_mut(out_ptr, needed) };
    for (i, &(_, _, idx)) in items.iter().enumerate() {
        out[i * 4..i * 4 + 4].copy_from_slice(&idx.to_ne_bytes());
    }
    needed as isize
}

/// Matches `HeaderParser::FILE_COUNTER_PATTERN`:
/// `'/[[(\s](\d{1,5})(\/|[\s_]of[\s_]|-)(\d{1,5})[])[\s$:]/i'`
/// Returns `(start, d1_len, sep_len, d2_len, file_number, total_files)`.
pub(crate) fn match_header_file_counter(
    s: &[u8],
) -> Option<(usize, usize, usize, usize, u32, u32)> {
    if s.len() < 5 {
        return None;
    }
    let mut i = 0usize;
    let last_possible_start = s.len() - 5;
    while i <= last_possible_start {
        let d1_start = i + 1;
        if !s[d1_start].is_ascii_digit() {
            i += if is_file_counter_start(s[d1_start]) { 1 } else { 2 };
            continue;
        }
        if !is_file_counter_start(s[i]) {
            i += 1;
            continue;
        }
        let mut d1_end = d1_start + 1;
        while d1_end < s.len() && d1_end - d1_start < 6 && s[d1_end].is_ascii_digit() {
            d1_end += 1;
        }
        let d1_len = d1_end - d1_start;
        if d1_len > 5 || d1_end >= s.len() {
            // None of s[d1_start..d1_end] can be is_file_counter_start since all are digits.
            i = d1_end;
            continue;
        }
        let sep_len = if s[d1_end] == b'/' || s[d1_end] == b'-' {
            1usize
        } else if d1_end + 4 <= s.len()
            && (is_pcre2_ascii_space(s[d1_end]) || s[d1_end] == b'_')
            && s[d1_end + 1].eq_ignore_ascii_case(&b'o')
            && s[d1_end + 2].eq_ignore_ascii_case(&b'f')
            && (is_pcre2_ascii_space(s[d1_end + 3]) || s[d1_end + 3] == b'_')
        {
            4usize
        } else {
            i = d1_end;
            continue;
        };
        let d2_start = d1_end + sep_len;
        if d2_start >= s.len() || !s[d2_start].is_ascii_digit() {
            i = d1_end;
            continue;
        }
        let mut d2_end = d2_start + 1;
        while d2_end < s.len() && d2_end - d2_start < 6 && s[d2_end].is_ascii_digit() {
            d2_end += 1;
        }
        let d2_len = d2_end - d2_start;
        if d2_len > 5 || d2_end >= s.len() || !is_file_counter_end(s[d2_end]) {
            i = d1_end;
            continue;
        }
        let mut file_num = 0u32;
        for &b in &s[d1_start..d1_end] {
            file_num = file_num * 10 + u32::from(b - b'0');
        }
        let mut total_files = 0u32;
        for &b in &s[d2_start..d2_end] {
            total_files = total_files * 10 + u32::from(b - b'0');
        }
        return Some((i, d1_len, sep_len, d2_len, file_num, total_files));
    }
    None
}

/// Extracts `(file_number, total_files)` from a subject string (`HeaderStorageService::extractFileNumberAndTotal`):
/// - `-1`: invalid pointer
/// - `0`: no match (`[0, 0]`)
/// - `> 0`: `(1 << 40) | ((total_files as i64) << 20) | (file_number as i64)`
///
/// # Safety
///
/// `subject_ptr` must be valid for reads of `subject_len` bytes when non-null and `subject_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_header_extract_file_numbers(
    subject_ptr: *const u8,
    subject_len: usize,
) -> i64 {
    if subject_ptr.is_null() && subject_len > 0 {
        return -1;
    }
    let s = if subject_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(subject_ptr, subject_len) }
    };
    match match_header_file_counter(s) {
        Some((_, _, _, _, file_num, total_files)) => {
            (1i64 << 40) | (i64::from(total_files) << 20) | i64::from(file_num)
        }
        None => 0,
    }
}

/// Extracts the exact PCRE capture spans for `HeaderStorageService::getFileCount`:
/// - `-1`: invalid pointer
/// - `-2`: match offset exceeds `u32::MAX` (decline to PHP)
/// - `0`: no match (`[1 => 0, 3 => 0]`)
/// - `> 0`: `(1 << 48) | ((d2_len as i64) << 38) | ((sep_len as i64) << 35) | ((d1_len as i64) << 32) | (start as i64)`
///
/// # Safety
///
/// `subject_ptr` must be valid for reads of `subject_len` bytes when non-null and `subject_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_header_get_file_count_span(
    subject_ptr: *const u8,
    subject_len: usize,
) -> i64 {
    if subject_ptr.is_null() && subject_len > 0 {
        return -1;
    }
    let s = if subject_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(subject_ptr, subject_len) }
    };
    match match_header_file_counter(s) {
        Some((start, d1_len, sep_len, d2_len, _, _)) => {
            if start > u32::MAX as usize {
                return -2;
            }
            (1i64 << 48)
                | ((d2_len as i64) << 38)
                | ((sep_len as i64) << 35)
                | ((d1_len as i64) << 32)
                | (start as i64)
        }
        None => 0,
    }
}

/// Batch extracts `(file_number: u32 NE, total_files: u32 NE)` pairs for `count` NUL-separated subjects.
///
/// # Safety
///
/// `input_ptr` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_ptr` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_header_extract_file_numbers_batch_nul(
    input_ptr: *const u8,
    input_len: usize,
    count: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if (input_ptr.is_null() && input_len > 0) || (out_ptr.is_null() && out_cap > 0) {
        return -1;
    }
    if count == 0 {
        return if input_len == 0 { 0 } else { -1 };
    }
    let needed = match count.checked_mul(8) {
        Some(n) => n,
        None => return -1,
    };
    let data = if input_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(input_ptr, input_len) }
    };
    if needed > out_cap {
        if data.iter().filter(|&&b| b == 0).count() + 1 != count {
            return -1;
        }
        return -(needed.max(4) as isize);
    }
    let out = unsafe { slice::from_raw_parts_mut(out_ptr, needed) };
    let mut seen = 0usize;
    for seg in data.split(|&b| b == 0) {
        if seen >= count {
            return -1;
        }
        let (file_num, total_files) = match match_header_file_counter(seg) {
            Some((_, _, _, _, f, t)) => (f, t),
            None => (0u32, 0u32),
        };
        let base = seen * 8;
        out[base..base + 4].copy_from_slice(&file_num.to_ne_bytes());
        out[base + 4..base + 8].copy_from_slice(&total_files.to_ne_bytes());
        seen += 1;
    }
    if seen != count {
        return -1;
    }
    needed as isize
}

#[inline]
fn is_lower_hex_64(s: &[u8]) -> bool {
    s.len() == 64
        && s.iter()
            .all(|&b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}

static B64_DECODE_TABLE: [u8; 256] = {
    let mut t = [0xFFu8; 256];
    let mut c = b'A';
    while c <= b'Z' {
        t[c as usize] = c - b'A';
        c += 1;
    }
    c = b'a';
    while c <= b'z' {
        t[c as usize] = c - b'a' + 26;
        c += 1;
    }
    c = b'0';
    while c <= b'9' {
        t[c as usize] = c - b'0' + 52;
        c += 1;
    }
    t[b'+' as usize] = 62;
    t[b'/' as usize] = 63;
    t
};

static FORBIDDEN_PAR2_BYTE: [u8; 256] = {
    let mut t = [0u8; 256];
    t[0] = 1;
    t[b'\n' as usize] = 1;
    t[b'\r' as usize] = 1;
    t
};

#[inline]
fn b64_sextet(b: u8) -> u8 {
    B64_DECODE_TABLE[b as usize]
}

#[inline]
fn is_forbidden_par2_filename_byte(b: u8) -> bool {
    FORBIDDEN_PAR2_BYTE[b as usize] != 0
}

/// Validates that `b64` is the canonical `base64_encode` of a `1..=65536` byte string
/// containing no `\0`, `\n`, or `\r` bytes.
pub(crate) fn is_valid_par2_identity_base64(b64: &[u8]) -> bool {
    if b64.is_empty() || b64.len() > 87384 || !b64.len().is_multiple_of(4) {
        return false;
    }
    let num_quads = b64.len() / 4;
    let (quads, _) = b64[..(num_quads - 1) * 4].as_chunks::<4>();
    for &[q0, q1, q2, q3] in quads {
        let v0 = B64_DECODE_TABLE[q0 as usize];
        let v1 = B64_DECODE_TABLE[q1 as usize];
        let v2 = B64_DECODE_TABLE[q2 as usize];
        let v3 = B64_DECODE_TABLE[q3 as usize];
        let b0 = (v0 << 2) | (v1 >> 4);
        let b1 = (v1 << 4) | (v2 >> 2);
        let b2 = (v2 << 6) | v3;
        if (((v0 | v1 | v2 | v3) & 0xC0)
            | FORBIDDEN_PAR2_BYTE[b0 as usize]
            | FORBIDDEN_PAR2_BYTE[b1 as usize]
            | FORBIDDEN_PAR2_BYTE[b2 as usize])
            != 0
        {
            return false;
        }
    }

    let q = &b64[(num_quads - 1) * 4..];
    let v0 = b64_sextet(q[0]);
    let v1 = b64_sextet(q[1]);
    if ((v0 | v1) & 0xC0) != 0 {
        return false;
    }
    let b0 = (v0 << 2) | (v1 >> 4);
    if is_forbidden_par2_filename_byte(b0) {
        return false;
    }

    let decoded_len = if q[2] == b'=' {
        if q[3] != b'=' || (v1 & 0x0F) != 0 {
            return false;
        }
        (num_quads - 1) * 3 + 1
    } else if q[3] == b'=' {
        let v2 = b64_sextet(q[2]);
        if (v2 & 0xC0) != 0 || (v2 & 0x03) != 0 {
            return false;
        }
        let b1 = (v1 << 4) | (v2 >> 2);
        if is_forbidden_par2_filename_byte(b1) {
            return false;
        }
        (num_quads - 1) * 3 + 2
    } else {
        let v2 = b64_sextet(q[2]);
        let v3 = b64_sextet(q[3]);
        if ((v2 | v3) & 0xC0) != 0 {
            return false;
        }
        let b1 = (v1 << 4) | (v2 >> 2);
        let b2 = (v2 << 6) | v3;
        if is_forbidden_par2_filename_byte(b1) || is_forbidden_par2_filename_byte(b2) {
            return false;
        }
        num_quads * 3
    };

    (1..=65536).contains(&decoded_len)
}

/// Validates `Par2CandidateProgress::validateArguments`:
/// - `-1`: invalid pointer
/// - `1`: valid
/// - `0`: invalid
///
/// # Safety
///
/// `manifest_ptr` and `candidate_ptr` must be valid for reads of their respective lengths when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_checkpoint_validate_arguments(
    release_id: i64,
    manifest_ptr: *const u8,
    manifest_len: usize,
    candidate_ptr: *const u8,
    candidate_len: usize,
    segment_count: i64,
    max_state_bytes: i64,
) -> i32 {
    if (manifest_ptr.is_null() && manifest_len > 0)
        || (candidate_ptr.is_null() && candidate_len > 0)
    {
        return -1;
    }
    if release_id < 1
        || !(1..=1_000_000).contains(&segment_count)
        || !(1..=4_294_967_295).contains(&max_state_bytes)
        || manifest_len != 64
        || candidate_len != 64
    {
        return 0;
    }
    let manifest = unsafe { slice::from_raw_parts(manifest_ptr, 64) };
    let candidate = unsafe { slice::from_raw_parts(candidate_ptr, 64) };
    i32::from(is_lower_hex_64(manifest) && is_lower_hex_64(candidate))
}

/// Validates `Par2CandidateProgress::validateCursor` over `retries_count` native-endian `i64` retry segments:
/// - `-1`: invalid pointer or byte length
/// - `0`: valid
/// - `1`: `'Invalid PAR2 article cursor or retry list.'`
/// - `2`: `'Invalid or duplicate PAR2 retry segment.'`
///
/// # Safety
///
/// `retries_ptr` must be valid for reads of `retries_len` bytes when non-null and `retries_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_checkpoint_validate_cursor(
    next_segment: i64,
    segment_count: i64,
    retries_ptr: *const u8,
    retries_len: usize,
    retries_count: usize,
) -> i32 {
    if retries_ptr.is_null() && retries_len > 0 {
        return -1;
    }
    if next_segment < 0
        || next_segment > segment_count
        || (retries_count as i128) > i128::from(next_segment)
    {
        return 1;
    }
    if retries_len != retries_count.saturating_mul(8) {
        return -1;
    }
    if retries_count == 0 {
        return 0;
    }
    let data = unsafe { slice::from_raw_parts(retries_ptr, retries_len) };
    if retries_count <= 32 {
        let mut seen = [0i64; 32];
        for i in 0..retries_count {
            let val = i64::from_ne_bytes(data[i * 8..i * 8 + 8].try_into().unwrap());
            if val < 0 || val >= next_segment || seen[..i].contains(&val) {
                return 2;
            }
            seen[i] = val;
        }
    } else {
        let mut seen = std::collections::HashSet::with_capacity(retries_count);
        for i in 0..retries_count {
            let val = i64::from_ne_bytes(data[i * 8..i * 8 + 8].try_into().unwrap());
            if val < 0 || val >= next_segment || !seen.insert(val) {
                return 2;
            }
        }
    }
    0
}

/// Validates `Par2CandidateProgress::validateBlock`:
/// - `-1`: invalid pointer
/// - `1`: valid
/// - `0`: invalid
///
/// # Safety
///
/// `ptr` must be valid for reads of `len` bytes when non-null and `len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_checkpoint_validate_block(
    ptr: *const u8,
    len: usize,
    is_null: u32,
) -> i32 {
    if ptr.is_null() && len > 0 {
        return -1;
    }
    if is_null != 0 {
        return 1;
    }
    if len == 0 || len > 64 {
        return 0;
    }
    let s = unsafe { slice::from_raw_parts(ptr, len) };
    if !s[0].is_ascii_lowercase() {
        return 0;
    }
    i32::from(
        s[1..]
            .iter()
            .all(|&b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'_'),
    )
}

/// Validates `Par2CandidateProgress::canonicalIdentity`:
/// - `-1`: invalid pointer
/// - `0`: valid
/// - `1`: `'Invalid decoded article offset.'`
/// - `2`: `'Invalid article continuity identity.'`
/// - `3`: `'Invalid article filename encoding.'`
///
/// # Safety
///
/// `b64_ptr` must be valid for reads of `b64_len` bytes when non-null and `b64_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_checkpoint_validate_identity(
    b64_ptr: *const u8,
    b64_len: usize,
    size: i64,
    has_part: u32,
    part: i64,
    decoded_offset: i64,
    tombstone: u32,
) -> i32 {
    if b64_ptr.is_null() && b64_len > 0 {
        return -1;
    }
    if decoded_offset < 0 {
        return 1;
    }
    if tombstone != 0
        || b64_len > 87384
        || size < 1
        || decoded_offset > size
        || (has_part != 0 && !(1..=1_000_000).contains(&part))
    {
        return 2;
    }
    let b64 = if b64_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(b64_ptr, b64_len) }
    };
    if !is_valid_par2_identity_base64(b64) {
        return 3;
    }
    0
}

/// Parses and validates a decimal integer string for `Par2CandidateProgress::storedInteger`:
/// - `-1`: invalid pointer
/// - `-2`: invalid stored integer or exceeds `maximum`
/// - `>= 0`: parsed integer in `0..=maximum`
///
/// # Safety
///
/// `ptr` must be valid for reads of `len` bytes when non-null and `len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_checkpoint_stored_integer(
    ptr: *const u8,
    len: usize,
    maximum: i64,
) -> i64 {
    if ptr.is_null() && len > 0 {
        return -1;
    }
    if maximum < 0 || len == 0 || len > 19 {
        return -2;
    }
    let s = unsafe { slice::from_raw_parts(ptr, len) };
    if s == b"0" {
        return 0;
    }
    if !matches!(s[0], b'1'..=b'9') {
        return -2;
    }
    let mut acc = u64::from(s[0] - b'0');
    for &b in &s[1..] {
        if !b.is_ascii_digit() {
            return -2;
        }
        acc = match acc
            .checked_mul(10)
            .and_then(|v| v.checked_add(u64::from(b - b'0')))
        {
            Some(v) => v,
            None => return -2,
        };
    }
    if acc > maximum as u64 {
        return -2;
    }
    acc as i64
}

#[cfg(test)]
mod wave11_binaries_part_handler_par2_checkpoint_tests {
    use super::*;

    #[test]
    fn part_validation_and_preference() {
        assert_eq!(part_validation_error_code(0, b"<msg@example>"), 1);
        assert_eq!(part_validation_error_code(-5, b"<msg@example>"), 1);
        assert_eq!(part_validation_error_code(1, b"   \t\n "), 2);
        assert_eq!(part_validation_error_code(1, "café@example".as_bytes()), 2);
        assert_eq!(part_validation_error_code(1, &[b'a'; 256]), 2);
        assert_eq!(part_validation_error_code(1, &[b'a'; 255]), 0);
        assert_eq!(part_validation_error_code(1, b"  <valid@example>  "), 0);

        // Non-empty messageid wins over empty messageid
        assert!(part_should_prefer_tuple(true, 10, 999, false, 1000, 1));
        assert!(!part_should_prefer_tuple(false, 1000, 1, true, 10, 999));
        // Larger size wins when both have messageid
        assert!(part_should_prefer_tuple(true, 200, 901, true, 100, 900));
        assert!(!part_should_prefer_tuple(true, 100, 900, true, 200, 901));
        // Smaller article number wins on equal size
        assert!(part_should_prefer_tuple(true, 200, 899, true, 200, 900));
        assert!(!part_should_prefer_tuple(true, 200, 900, true, 200, 900));
    }

    #[test]
    fn part_deduplicate_and_sort_ffi() {
        let rows: &[(i64, i64, i64, i64, &[u8])] = &[
            (20, 2, 100, 500, b"<m1>"),
            (10, 1, 100, 501, b"<m2>"),
            (20, 2, 200, 502, b"<m3>"), // larger size -> wins for (20, 2) at index 2
            (10, 1, 100, 499, b"<m4>"), // smaller number -> wins for (10, 1) at index 3
            (10, 1, 999, 100, b"   "),  // empty trimmed messageid -> loses
        ];
        let mut buf = Vec::new();
        for &(bid, pnum, size, num, msg) in rows {
            buf.extend_from_slice(&bid.to_ne_bytes());
            buf.extend_from_slice(&pnum.to_ne_bytes());
            buf.extend_from_slice(&size.to_ne_bytes());
            buf.extend_from_slice(&num.to_ne_bytes());
            buf.extend_from_slice(&(msg.len() as u32).to_ne_bytes());
            buf.extend_from_slice(msg);
        }

        let mut out = [0u8; 32];
        let written_unsorted = unsafe {
            nntmux_part_deduplicate(buf.as_ptr(), buf.len(), rows.len(), 0, out.as_mut_ptr(), out.len())
        };
        assert_eq!(written_unsorted, 8);
        let idx0 = u32::from_ne_bytes(out[0..4].try_into().unwrap());
        let idx1 = u32::from_ne_bytes(out[4..8].try_into().unwrap());
        assert_eq!((idx0, idx1), (2, 3));

        let written_sorted = unsafe {
            nntmux_part_deduplicate(buf.as_ptr(), buf.len(), rows.len(), 1, out.as_mut_ptr(), out.len())
        };
        assert_eq!(written_sorted, 8);
        let s0 = u32::from_ne_bytes(out[0..4].try_into().unwrap());
        let s1 = u32::from_ne_bytes(out[4..8].try_into().unwrap());
        assert_eq!((s0, s1), (3, 2));
    }

    #[test]
    fn header_file_counter_matching() {
        assert_eq!(
            match_header_file_counter(b"Release.Name [03/62] \"file.rar\" yEnc"),
            Some((13, 2, 1, 2, 3, 62))
        );
        assert_eq!(
            match_header_file_counter(b"Release (3 of 62) file.rar"),
            Some((8, 1, 4, 2, 3, 62))
        );
        assert_eq!(
            match_header_file_counter(b"[1/2$"),
            Some((0, 1, 1, 1, 1, 2))
        );
        assert_eq!(match_header_file_counter(b"[1/2"), None);
        assert_eq!(match_header_file_counter(b"[123456/7]"), None);
    }

    #[test]
    fn par2_checkpoint_validators() {
        assert!(is_valid_par2_identity_base64(b"ZmlsZS5wYXIy")); // "file.par2"
        assert!(!is_valid_par2_identity_base64(b"eA")); // unpadded
        assert!(!is_valid_par2_identity_base64(b"eB==")); // non-canonical padding bits
        assert!(!is_valid_par2_identity_base64(b"AA==")); // decodes to "\0"
        assert!(!is_valid_par2_identity_base64(b"Cg==")); // decodes to "\n"
        assert!(!is_valid_par2_identity_base64(b"DQ==")); // decodes to "\r"

        let zero = b"0";
        assert_eq!(
            unsafe { nntmux_par2_checkpoint_stored_integer(zero.as_ptr(), zero.len(), 100) },
            0
        );
        let lead_zero = b"01";
        assert_eq!(
            unsafe { nntmux_par2_checkpoint_stored_integer(lead_zero.as_ptr(), lead_zero.len(), 100) },
            -2
        );
        let overflow = b"9223372036854775808";
        assert_eq!(
            unsafe {
                nntmux_par2_checkpoint_stored_integer(overflow.as_ptr(), overflow.len(), i64::MAX)
            },
            -2
        );
    }
}


// ---- Wave 12: NZB source custody, upload manifests, PAR2 recovery and password metadata ----

pub mod nzb_custody {
    use std::slice;
    use std::sync::OnceLock;

    /// Domain prefix hashed ahead of the decoded NZB by `NzbSourceGuard::digest`.
    pub const SOURCE_DIGEST_PREFIX: &[u8] = b"nntmux:nzb-archive-naming:1\0";

    const K256: [u32; 64] = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4,
        0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe,
        0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f,
        0x4a7484aa, 0x5cb0a9dc, 0x76f988da, 0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7,
        0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc,
        0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b,
        0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070, 0x19a4c116,
        0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7,
        0xc67178f2,
    ];

    const H256: [u32; 8] = [
        0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab,
        0x5be0cd19,
    ];

    type CompressFn = unsafe fn(&mut [u32; 8], &[[u8; 64]]);

    fn compress_soft(state: &mut [u32; 8], blocks: &[[u8; 64]]) {
        for block in blocks {
            let mut w = [0u32; 64];
            for (i, chunk) in block.as_chunks::<4>().0.iter().enumerate() {
                w[i] = u32::from_be_bytes(*chunk);
            }
            for i in 16..64 {
                let s0 = w[i - 15].rotate_right(7) ^ w[i - 15].rotate_right(18) ^ (w[i - 15] >> 3);
                let s1 = w[i - 2].rotate_right(17) ^ w[i - 2].rotate_right(19) ^ (w[i - 2] >> 10);
                w[i] = w[i - 16]
                    .wrapping_add(s0)
                    .wrapping_add(w[i - 7])
                    .wrapping_add(s1);
            }
            let [mut a, mut b, mut c, mut d, mut e, mut f, mut g, mut h] = *state;
            for i in 0..64 {
                let s1 = e.rotate_right(6) ^ e.rotate_right(11) ^ e.rotate_right(25);
                let ch = (e & f) ^ ((!e) & g);
                let t1 = h
                    .wrapping_add(s1)
                    .wrapping_add(ch)
                    .wrapping_add(K256[i])
                    .wrapping_add(w[i]);
                let s0 = a.rotate_right(2) ^ a.rotate_right(13) ^ a.rotate_right(22);
                let maj = (a & b) ^ (a & c) ^ (b & c);
                let t2 = s0.wrapping_add(maj);
                h = g;
                g = f;
                f = e;
                e = d.wrapping_add(t1);
                d = c;
                c = b;
                b = a;
                a = t1.wrapping_add(t2);
            }
            for (slot, value) in state.iter_mut().zip([a, b, c, d, e, f, g, h]) {
                *slot = slot.wrapping_add(value);
            }
        }
    }

    unsafe fn compress_soft_entry(state: &mut [u32; 8], blocks: &[[u8; 64]]) {
        compress_soft(state, blocks);
    }

    #[cfg(target_arch = "aarch64")]
    #[target_feature(enable = "sha2")]
    unsafe fn compress_arm(state: &mut [u32; 8], blocks: &[[u8; 64]]) {
        use std::arch::aarch64::*;
        unsafe {
            let mut abcd = vld1q_u32(state.as_ptr());
            let mut efgh = vld1q_u32(state.as_ptr().add(4));
            for block in blocks {
                let abcd_saved = abcd;
                let efgh_saved = efgh;
                let p = block.as_ptr();
                let mut m = [
                    vreinterpretq_u32_u8(vrev32q_u8(vld1q_u8(p))),
                    vreinterpretq_u32_u8(vrev32q_u8(vld1q_u8(p.add(16)))),
                    vreinterpretq_u32_u8(vrev32q_u8(vld1q_u8(p.add(32)))),
                    vreinterpretq_u32_u8(vrev32q_u8(vld1q_u8(p.add(48)))),
                ];
                for group in 0..16 {
                    let lane = group & 3;
                    let wk = vaddq_u32(m[lane], vld1q_u32(K256.as_ptr().add(group * 4)));
                    let abcd_prev = abcd;
                    abcd = vsha256hq_u32(abcd, efgh, wk);
                    efgh = vsha256h2q_u32(efgh, abcd_prev, wk);
                    if group < 12 {
                        m[lane] = vsha256su1q_u32(
                            vsha256su0q_u32(m[lane], m[(lane + 1) & 3]),
                            m[(lane + 2) & 3],
                            m[(lane + 3) & 3],
                        );
                    }
                }
                abcd = vaddq_u32(abcd, abcd_saved);
                efgh = vaddq_u32(efgh, efgh_saved);
            }
            vst1q_u32(state.as_mut_ptr(), abcd);
            vst1q_u32(state.as_mut_ptr().add(4), efgh);
        }
    }

    #[cfg(target_arch = "x86_64")]
    #[target_feature(enable = "sha,sse2,ssse3,sse4.1")]
    unsafe fn compress_x86(state: &mut [u32; 8], blocks: &[[u8; 64]]) {
        use std::arch::x86_64::*;
        unsafe {
            let mask = _mm_set_epi64x(
                0x0C0D_0E0F_0809_0A0B_u64 as i64,
                0x0405_0607_0001_0203_u64 as i64,
            );
            let state_ptr = state.as_ptr().cast::<__m128i>();
            let dcba = _mm_loadu_si128(state_ptr);
            let efgh = _mm_loadu_si128(state_ptr.add(1));
            let cdab = _mm_shuffle_epi32(dcba, 0xB1);
            let efgh = _mm_shuffle_epi32(efgh, 0x1B);
            let mut abef = _mm_alignr_epi8(cdab, efgh, 8);
            let mut cdgh = _mm_blend_epi16(efgh, cdab, 0xF0);
            for block in blocks {
                let abef_saved = abef;
                let cdgh_saved = cdgh;
                let data = block.as_ptr().cast::<__m128i>();
                let mut w = [
                    _mm_shuffle_epi8(_mm_loadu_si128(data), mask),
                    _mm_shuffle_epi8(_mm_loadu_si128(data.add(1)), mask),
                    _mm_shuffle_epi8(_mm_loadu_si128(data.add(2)), mask),
                    _mm_shuffle_epi8(_mm_loadu_si128(data.add(3)), mask),
                ];
                for group in 0..16 {
                    let lane = group & 3;
                    if group >= 4 {
                        let t1 = _mm_sha256msg1_epu32(w[lane], w[(lane + 1) & 3]);
                        let t2 = _mm_alignr_epi8(w[(lane + 3) & 3], w[(lane + 2) & 3], 4);
                        w[lane] = _mm_sha256msg2_epu32(_mm_add_epi32(t1, t2), w[(lane + 3) & 3]);
                    }
                    let k = _mm_loadu_si128(K256.as_ptr().add(group * 4).cast::<__m128i>());
                    let wk = _mm_add_epi32(w[lane], k);
                    cdgh = _mm_sha256rnds2_epu32(cdgh, abef, wk);
                    abef = _mm_sha256rnds2_epu32(abef, cdgh, _mm_shuffle_epi32(wk, 0x0E));
                }
                abef = _mm_add_epi32(abef, abef_saved);
                cdgh = _mm_add_epi32(cdgh, cdgh_saved);
            }
            let feba = _mm_shuffle_epi32(abef, 0x1B);
            let dchg = _mm_shuffle_epi32(cdgh, 0xB1);
            let dcba = _mm_blend_epi16(feba, dchg, 0xF0);
            let hgef = _mm_alignr_epi8(dchg, feba, 8);
            let out = state.as_mut_ptr().cast::<__m128i>();
            _mm_storeu_si128(out, dcba);
            _mm_storeu_si128(out.add(1), hgef);
        }
    }

    /// Streams `prefix || data` through `compress` without copying `data`.
    fn sha256_prefixed_with(compress: CompressFn, prefix: &[u8], data: &[u8]) -> [u8; 32] {
        debug_assert!(prefix.len() < 64);
        let mut state = H256;
        let mut head = [0u8; 64];
        head[..prefix.len()].copy_from_slice(prefix);
        let fill = 64 - prefix.len();
        let rest = if data.len() >= fill {
            head[prefix.len()..].copy_from_slice(&data[..fill]);
            unsafe { compress(&mut state, slice::from_ref(&head)) };
            let (blocks, rest) = data[fill..].as_chunks::<64>();
            unsafe { compress(&mut state, blocks) };
            rest
        } else {
            &[][..]
        };
        let mut tail = [[0u8; 64]; 2];
        let flat = tail.as_flattened_mut();
        let pending = if data.len() >= fill {
            flat[..rest.len()].copy_from_slice(rest);
            rest.len()
        } else {
            flat[..prefix.len()].copy_from_slice(prefix);
            flat[prefix.len()..prefix.len() + data.len()].copy_from_slice(data);
            prefix.len() + data.len()
        };
        flat[pending] = 0x80;
        let used = if pending < 56 { 1 } else { 2 };
        let total = (prefix.len() as u64).wrapping_add(data.len() as u64);
        flat[used * 64 - 8..used * 64].copy_from_slice(&total.wrapping_mul(8).to_be_bytes());
        unsafe { compress(&mut state, &tail[..used]) };
        let mut digest = [0u8; 32];
        for (out, word) in digest.as_chunks_mut::<4>().0.iter_mut().zip(state) {
            *out = word.to_be_bytes();
        }
        digest
    }

    fn accelerated() -> Option<CompressFn> {
        #[cfg(target_arch = "aarch64")]
        {
            if std::arch::is_aarch64_feature_detected!("sha2") {
                return Some(compress_arm as CompressFn);
            }
        }
        #[cfg(target_arch = "x86_64")]
        {
            if is_x86_feature_detected!("sha")
                && is_x86_feature_detected!("sse2")
                && is_x86_feature_detected!("ssse3")
                && is_x86_feature_detected!("sse4.1")
            {
                return Some(compress_x86 as CompressFn);
            }
        }
        None
    }

    /// The accelerated compressor is adopted only after it reproduces the portable digest.
    fn compressor() -> CompressFn {
        static SELECTED: OnceLock<CompressFn> = OnceLock::new();
        *SELECTED.get_or_init(|| {
            if let Some(fast) = accelerated() {
                let probe: Vec<u8> = (0..300u32)
                    .map(|i| (i.wrapping_mul(31) ^ 0x5a) as u8)
                    .collect();
                let agrees = (0..probe.len()).step_by(7).all(|len| {
                    sha256_prefixed_with(fast, SOURCE_DIGEST_PREFIX, &probe[..len])
                        == sha256_prefixed_with(compress_soft_entry, SOURCE_DIGEST_PREFIX, &probe[..len])
                });
                if agrees {
                    return fast;
                }
            }
            compress_soft_entry as CompressFn
        })
    }

    /// `hash('sha256', "nntmux:nzb-archive-naming:1\0" . $decoded)` as lowercase hex.
    pub fn source_digest_hex(decoded: &[u8]) -> [u8; 64] {
        const HEX: &[u8; 16] = b"0123456789abcdef";
        let digest = sha256_prefixed_with(compressor(), SOURCE_DIGEST_PREFIX, decoded);
        let mut out = [0u8; 64];
        for (i, byte) in digest.iter().enumerate() {
            out[i * 2] = HEX[(byte >> 4) as usize];
            out[i * 2 + 1] = HEX[(byte & 15) as usize];
        }
        out
    }

    /// PHP `basename()` for ASCII-compatible locales on POSIX.
    pub fn php_basename(s: &[u8]) -> &[u8] {
        let mut end = s.len();
        while end > 0 && s[end - 1] == b'/' {
            end -= 1;
        }
        if end == 0 {
            return &[];
        }
        let mut start = end;
        while start > 0 && s[start - 1] != b'/' {
            start -= 1;
        }
        &s[start..end]
    }

    /// PHP `dirname()` (one level) on POSIX.
    pub fn php_dirname(s: &[u8]) -> &[u8] {
        if s.is_empty() {
            return &[];
        }
        let mut end = s.len();
        while end > 0 && s[end - 1] == b'/' {
            end -= 1;
        }
        if end == 0 {
            return b"/";
        }
        while end > 0 && s[end - 1] != b'/' {
            end -= 1;
        }
        if end == 0 {
            return b".";
        }
        while end > 0 && s[end - 1] == b'/' {
            end -= 1;
        }
        if end == 0 {
            return b"/";
        }
        &s[..end]
    }

    #[inline]
    fn is_guid(guid: &[u8]) -> bool {
        (1..=40).contains(&guid.len())
            && guid
                .iter()
                .all(|&b| b.is_ascii_alphanumeric() || b == b'_' || b == b'-')
    }

    #[inline]
    fn receipt_field_ok(s: &[u8]) -> bool {
        !s.is_empty() && !s.contains(&0)
    }

    /// Mirrors the string checks of `NzbSourceGuard::validateAnchor` after the PHP side has
    /// confirmed the version, kind and string types:
    /// `0` valid, `1` malformed receipt, `2` receipt does not match its source.
    pub fn validate_anchor_code(
        absent: bool,
        guid: &[u8],
        path: &[u8],
        anchor: &[u8],
        dev: &[u8],
        ino: &[u8],
    ) -> i32 {
        if !receipt_field_ok(guid) || !receipt_field_ok(path) {
            return 1;
        }
        if !absent
            && (!receipt_field_ok(anchor) || !receipt_field_ok(dev) || !receipt_field_ok(ino))
        {
            return 1;
        }
        if !is_guid(guid) {
            return 2;
        }
        let base = php_basename(path);
        let matches_source = base.len() > guid.len()
            && base.starts_with(guid)
            && matches!(&base[guid.len()..], b".nzb.gz" | b".nzb");
        if !matches_source {
            return 2;
        }
        if absent {
            return 0;
        }
        if php_dirname(path) != php_dirname(anchor) {
            return 2;
        }
        // `/^\.archive-audit-<guid>\-[a-f0-9]{32}\.anchor$/D`
        let anchor_base = php_basename(anchor);
        let expected_len = 15 + guid.len() + 1 + 32 + 7;
        let anchor_ok = anchor_base.len() == expected_len
            && anchor_base.starts_with(b".archive-audit-")
            && &anchor_base[15..15 + guid.len()] == guid
            && anchor_base[15 + guid.len()] == b'-'
            && anchor_base[16 + guid.len()..48 + guid.len()]
                .iter()
                .all(|&b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
            && &anchor_base[48 + guid.len()..] == b".anchor";
        if !anchor_ok
            || !dev.iter().all(u8::is_ascii_digit)
            || !ino.iter().all(u8::is_ascii_digit)
        {
            return 2;
        }
        0
    }

    #[inline]
    fn decimal_equals(value: i64, digits: &[u8]) -> bool {
        let mut buf = [0u8; 20];
        let mut pos = buf.len();
        let negative = value < 0;
        let mut magnitude = value.unsigned_abs();
        loop {
            pos -= 1;
            buf[pos] = b'0' + (magnitude % 10) as u8;
            magnitude /= 10;
            if magnitude == 0 {
                break;
            }
        }
        if negative {
            match digits.split_first() {
                Some((b'-', rest)) => rest == &buf[pos..],
                _ => false,
            }
        } else {
            digits == &buf[pos..]
        }
    }

    /// `NzbSourceGuard::matchesAnchor` for an available stat record.
    pub fn matches_anchor(mode: i64, dev: i64, ino: i64, receipt_dev: &[u8], receipt_ino: &[u8]) -> bool {
        (mode & 0o170000) == 0o100000
            && decimal_equals(dev, receipt_dev)
            && decimal_equals(ino, receipt_ino)
    }

    /// `/^(.+)\.nzb\.gz\.c\d+-[0-9a-f]{16}\.next$/D`; returns the captured GUID length.
    pub fn staged_guid_len(name: &[u8]) -> Option<usize> {
        const SUFFIX: &[u8] = b".next";
        const MARK: &[u8] = b".nzb.gz.c";
        let n = name.len();
        if n < 1 + MARK.len() + 1 + 1 + 16 + SUFFIX.len() || !name.ends_with(SUFFIX) {
            return None;
        }
        let hex_end = n - SUFFIX.len();
        let hex_start = hex_end - 16;
        if !name[hex_start..hex_end]
            .iter()
            .all(|&b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
            || name[hex_start - 1] != b'-'
        {
            return None;
        }
        let digits_end = hex_start - 1;
        let mut digits_start = digits_end;
        while digits_start > 0 && name[digits_start - 1].is_ascii_digit() {
            digits_start -= 1;
        }
        if digits_start == digits_end || digits_start < MARK.len() + 1 {
            return None;
        }
        let guid_end = digits_start - MARK.len();
        if &name[guid_end..digits_start] != MARK || name[..guid_end].contains(&b'\n') {
            return None;
        }
        Some(guid_end)
    }

    #[inline]
    fn php_trim_is_empty(s: &[u8]) -> bool {
        s.iter()
            .all(|&b| matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B))
    }

    /// `NzbUploadManifestService::assertSafeFilename` without throwing: `true` when safe.
    pub fn safe_upload_filename(filename: &[u8], extension: &[u8]) -> bool {
        if filename.is_empty()
            || filename == b"."
            || filename == b".."
            || php_basename(filename) != filename
            || filename.contains(&0)
        {
            return false;
        }
        let (stem, ext) = match filename.iter().rposition(|&b| b == b'.') {
            Some(dot) => (&filename[..dot], &filename[dot + 1..]),
            None => (filename, &[][..]),
        };
        ext.len() == extension.len()
            && ext
                .iter()
                .zip(extension)
                .all(|(&a, &b)| a.to_ascii_lowercase() == b)
            && !php_trim_is_empty(stem)
    }

    #[inline]
    fn is_lower_hex(b: u8) -> bool {
        b.is_ascii_digit() || (b'a'..=b'f').contains(&b)
    }

    /// `NzbUploadManifestService::canReconcileCandidate`; `None` encodes a non-string value.
    pub fn can_reconcile_candidate(state: Option<&[u8]>, identity: Option<&[u8]>) -> bool {
        match state {
            Some(b"nzb_pending") => true,
            Some(b"metadata_review" | b"metadata_deferred") => {
                matches!(identity, Some(id) if id.len() == 40 && id.iter().copied().all(is_lower_hex))
            }
            _ => false,
        }
    }

    /// One password: at most 4096 bytes of valid UTF-8 limited to XML 1.0 characters.
    pub fn password_value_valid(value: &[u8]) -> bool {
        if value.len() > 4096 {
            return false;
        }
        if value.is_ascii() {
            return value
                .iter()
                .all(|&b| b >= 0x20 || matches!(b, b'\t' | b'\n' | b'\r'));
        }
        match std::str::from_utf8(value) {
            Ok(text) => text.chars().all(|c| {
                matches!(c as u32, 0x09 | 0x0A | 0x0D | 0x20..=0xD7FF | 0xE000..=0xFFFD | 0x10000..=0x10FFFF)
            }),
            Err(_) => false,
        }
    }

    pub(crate) fn base64_equals(raw: &[u8], encoded: &[u8]) -> bool {
        const TABLE: &[u8; 64] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
        if encoded.len() != raw.len().div_ceil(3) * 4 {
            return false;
        }
        let (chunks, rest) = raw.as_chunks::<3>();
        let (quads, _) = encoded.as_chunks::<4>();
        for (chunk, quad) in chunks.iter().zip(quads) {
            let v = (u32::from(chunk[0]) << 16) | (u32::from(chunk[1]) << 8) | u32::from(chunk[2]);
            if quad[0] != TABLE[(v >> 18) as usize & 63]
                || quad[1] != TABLE[(v >> 12) as usize & 63]
                || quad[2] != TABLE[(v >> 6) as usize & 63]
                || quad[3] != TABLE[v as usize & 63]
            {
                return false;
            }
        }
        if rest.is_empty() {
            return true;
        }
        let quad = &encoded[chunks.len() * 4..];
        let b0 = u32::from(rest[0]);
        let b1 = rest.get(1).copied().map_or(0, u32::from);
        let v = (b0 << 16) | (b1 << 8);
        let third = if rest.len() == 2 {
            TABLE[(v >> 6) as usize & 63]
        } else {
            b'='
        };
        quad[0] == TABLE[(v >> 18) as usize & 63]
            && quad[1] == TABLE[(v >> 12) as usize & 63]
            && quad[2] == third
            && quad[3] == b'='
    }

    /// Inputs to `Par2CandidateRecovery::validatePart` for one decoded yEnc article.
    pub struct YencPartView<'a> {
        pub name: &'a [u8],
        pub size: i64,
        pub part: Option<i64>,
        pub begin: Option<i64>,
        pub end: Option<i64>,
    }

    /// The prior article's continuity identity.
    pub struct PartIdentityView<'a> {
        pub name_base64: &'a [u8],
        pub size: i64,
        pub part: Option<i64>,
    }

    /// `0` no framing, `1` valid, `2` framing changed, `3` invalid identity, `4` identity changed,
    /// `5` missing multipart offsets, `6` discontinuous offsets.
    pub fn validate_part_code(
        part: Option<&YencPartView<'_>>,
        body_len: usize,
        segment_number: i64,
        segment_count: i64,
        offset: i64,
        identity: Option<&PartIdentityView<'_>>,
    ) -> i32 {
        let Some(part) = part else {
            return if identity.is_some() { 2 } else { 0 };
        };
        if part.name.is_empty()
            || part.name.len() > 65536
            || part.name.iter().any(|&b| matches!(b, 0 | b'\r' | b'\n'))
            || part.size < 1
            || (offset > 0 && identity.is_none())
            || matches!(part.part, Some(number) if number != segment_number)
        {
            return 3;
        }
        if let Some(identity) = identity {
            let part_breaks = match identity.part {
                None => false,
                Some(previous) => match (part.part, previous.checked_add(1)) {
                    (Some(number), Some(next)) => number != next,
                    _ => true,
                },
            };
            if !base64_equals(part.name, identity.name_base64) || part.size != identity.size || part_breaks {
                return 4;
            }
        }
        let body_len = body_len as i128;
        match (part.begin, part.end) {
            (Some(begin), Some(end)) => {
                if offset == i64::MAX
                    || begin != offset + 1
                    || end < begin
                    || end > part.size
                    || i128::from(end) - i128::from(begin) + 1 != body_len
                {
                    return 6;
                }
            }
            _ => {
                if segment_count != 1
                    || offset != 0
                    || part.begin.is_some()
                    || part.end.is_some()
                    || body_len != i128::from(part.size)
                {
                    return 5;
                }
            }
        }
        1
    }

    #[inline]
    pub(crate) unsafe fn bytes<'a>(ptr: *const u8, len: usize) -> Option<&'a [u8]> {
        if len == 0 {
            Some(&[])
        } else if ptr.is_null() {
            None
        } else {
            Some(unsafe { slice::from_raw_parts(ptr, len) })
        }
    }
}

/// Writes `NzbSourceGuard::digest($decoded)` as 64 lowercase hex bytes into `out`.
/// Returns `64`, `-1` for invalid pointers, or `-2` when `out_cap < 64`.
///
/// # Safety
///
/// `ptr` must be valid for `len` bytes when `len > 0`; `out` must be writable for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_source_digest(
    ptr: *const u8,
    len: usize,
    out: *mut u8,
    out_cap: usize,
) -> i32 {
    let Some(data) = (unsafe { nzb_custody::bytes(ptr, len) }) else {
        return -1;
    };
    if out.is_null() {
        return -1;
    }
    if out_cap < 64 {
        return -2;
    }
    let hex = nzb_custody::source_digest_hex(data);
    unsafe { std::ptr::copy_nonoverlapping(hex.as_ptr(), out, 64) };
    64
}

/// `NzbSourceGuard::validateAnchor` string checks: `0` valid, `1` malformed, `2` source mismatch,
/// `-1` invalid pointer. `anchor`, `dev` and `ino` are ignored when `absent != 0`.
///
/// # Safety
///
/// Each pointer must be valid for its length when that length is non-zero.
#[unsafe(no_mangle)]
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn nntmux_nzb_source_validate_anchor(
    absent: u32,
    guid_ptr: *const u8,
    guid_len: usize,
    path_ptr: *const u8,
    path_len: usize,
    anchor_ptr: *const u8,
    anchor_len: usize,
    dev_ptr: *const u8,
    dev_len: usize,
    ino_ptr: *const u8,
    ino_len: usize,
) -> i32 {
    let (Some(guid), Some(path), Some(anchor), Some(dev), Some(ino)) = (unsafe {
        (
            nzb_custody::bytes(guid_ptr, guid_len),
            nzb_custody::bytes(path_ptr, path_len),
            nzb_custody::bytes(anchor_ptr, anchor_len),
            nzb_custody::bytes(dev_ptr, dev_len),
            nzb_custody::bytes(ino_ptr, ino_len),
        )
    }) else {
        return -1;
    };
    nzb_custody::validate_anchor_code(absent != 0, guid, path, anchor, dev, ino)
}

/// `NzbSourceGuard::matchesAnchor` for a stat record: `1` match, `0` mismatch, `-1` invalid pointer.
///
/// # Safety
///
/// Each pointer must be valid for its length when that length is non-zero.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_source_matches_anchor(
    mode: i64,
    dev: i64,
    ino: i64,
    receipt_dev_ptr: *const u8,
    receipt_dev_len: usize,
    receipt_ino_ptr: *const u8,
    receipt_ino_len: usize,
) -> i32 {
    let (Some(receipt_dev), Some(receipt_ino)) = (unsafe {
        (
            nzb_custody::bytes(receipt_dev_ptr, receipt_dev_len),
            nzb_custody::bytes(receipt_ino_ptr, receipt_ino_len),
        )
    }) else {
        return -1;
    };
    i32::from(nzb_custody::matches_anchor(mode, dev, ino, receipt_dev, receipt_ino))
}

/// `NzbSourceGuard::STAGED_NAME`: returns the GUID prefix length, `-2` when the name does not
/// match, or `-1` for an invalid pointer.
///
/// # Safety
///
/// `ptr` must be valid for `len` bytes when `len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_source_staged_guid_len(ptr: *const u8, len: usize) -> i64 {
    let Some(name) = (unsafe { nzb_custody::bytes(ptr, len) }) else {
        return -1;
    };
    nzb_custody::staged_guid_len(name).map_or(-2, |n| n as i64)
}

/// `NzbUploadManifestService::assertSafeFilename`: `1` safe, `0` unsafe, `-1` invalid pointer.
///
/// # Safety
///
/// Each pointer must be valid for its length when that length is non-zero.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_upload_manifest_safe_filename(
    name_ptr: *const u8,
    name_len: usize,
    ext_ptr: *const u8,
    ext_len: usize,
) -> i32 {
    let (Some(name), Some(ext)) = (unsafe {
        (nzb_custody::bytes(name_ptr, name_len), nzb_custody::bytes(ext_ptr, ext_len))
    }) else {
        return -1;
    };
    i32::from(nzb_custody::safe_upload_filename(name, ext))
}

/// `NzbUploadManifestService::canReconcileCandidate`: `1` reconcilable, `0` not, `-1` invalid pointer.
/// A value that is not a PHP string is passed with its `*_is_string` flag cleared.
///
/// # Safety
///
/// Each pointer must be valid for its length when that length is non-zero.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_upload_manifest_can_reconcile(
    state_ptr: *const u8,
    state_len: usize,
    state_is_string: u32,
    identity_ptr: *const u8,
    identity_len: usize,
    identity_is_string: u32,
) -> i32 {
    let (Some(state), Some(identity)) = (unsafe {
        (
            nzb_custody::bytes(state_ptr, state_len),
            nzb_custody::bytes(identity_ptr, identity_len),
        )
    }) else {
        return -1;
    };
    i32::from(nzb_custody::can_reconcile_candidate(
        (state_is_string != 0).then_some(state),
        (identity_is_string != 0).then_some(identity),
    ))
}

/// `NzbPasswordMetadata::isValid` over `count` strings concatenated in `data`, with their byte
/// lengths packed as native `u64` values in `lens`: `1` valid, `0` invalid, `-1` invalid input.
///
/// # Safety
///
/// `data` must be valid for `data_len` bytes and `lens` for `count * 8` bytes when non-zero.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_password_metadata_valid(
    data: *const u8,
    data_len: usize,
    lens: *const u8,
    count: usize,
) -> i32 {
    if count > 64 {
        return 0;
    }
    let (Some(data), Some(lens)) = (unsafe {
        (nzb_custody::bytes(data, data_len), nzb_custody::bytes(lens, count * 8))
    }) else {
        return -1;
    };
    let mut offset = 0usize;
    for chunk in lens.as_chunks::<8>().0 {
        let len = u64::from_ne_bytes(*chunk);
        let Some(end) = usize::try_from(len).ok().and_then(|len| offset.checked_add(len)) else {
            return -1;
        };
        if end > data.len() {
            return -1;
        }
        if !nzb_custody::password_value_valid(&data[offset..end]) {
            return 0;
        }
        offset = end;
    }
    if offset != data.len() {
        return -1;
    }
    1
}

/// `Par2CandidateRecovery::validatePart` (see `nzb_custody::validate_part_code`), or `-1` for an
/// invalid pointer. Optional integers are passed with `has_*` flags.
///
/// # Safety
///
/// Each pointer must be valid for its length when that length is non-zero.
#[unsafe(no_mangle)]
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn nntmux_par2_recovery_validate_part(
    has_part: u32,
    name_ptr: *const u8,
    name_len: usize,
    size: i64,
    has_number: u32,
    number: i64,
    has_begin: u32,
    begin: i64,
    has_end: u32,
    end: i64,
    body_len: usize,
    segment_number: i64,
    segment_count: i64,
    offset: i64,
    has_identity: u32,
    identity_b64_ptr: *const u8,
    identity_b64_len: usize,
    identity_size: i64,
    identity_has_part: u32,
    identity_part: i64,
) -> i32 {
    let (Some(name), Some(identity_b64)) = (unsafe {
        (
            nzb_custody::bytes(name_ptr, name_len),
            nzb_custody::bytes(identity_b64_ptr, identity_b64_len),
        )
    }) else {
        return -1;
    };
    let part = nzb_custody::YencPartView {
        name,
        size,
        part: (has_number != 0).then_some(number),
        begin: (has_begin != 0).then_some(begin),
        end: (has_end != 0).then_some(end),
    };
    let identity = nzb_custody::PartIdentityView {
        name_base64: identity_b64,
        size: identity_size,
        part: (identity_has_part != 0).then_some(identity_part),
    };
    nzb_custody::validate_part_code(
        (has_part != 0).then_some(&part),
        body_len,
        segment_number,
        segment_count,
        offset,
        (has_identity != 0).then_some(&identity),
    )
}

#[cfg(test)]
mod wave12_nzb_custody_par2_recovery_tests {
    use super::nzb_custody::*;
    use super::*;

    fn digest_of(data: &[u8]) -> String {
        String::from_utf8(source_digest_hex(data).to_vec()).unwrap()
    }

    #[test]
    fn digest_matches_portable_and_known_vector() {
        // sha256("nntmux:nzb-archive-naming:1\0") computed independently.
        let mut prefixed = SOURCE_DIGEST_PREFIX.to_vec();
        for len in [0usize, 1, 35, 36, 37, 99, 100, 101, 163, 164, 1000, 4099] {
            let data: Vec<u8> = (0..len).map(|i| (i * 7 + 3) as u8).collect();
            prefixed.truncate(SOURCE_DIGEST_PREFIX.len());
            prefixed.extend_from_slice(&data);
            let expected = crate::metadata::candidate_inventory::sha256(&prefixed);
            let hex: String = expected.iter().map(|b| format!("{b:02x}")).collect();
            assert_eq!(digest_of(&data), hex, "length {len}");
        }
    }

    #[test]
    fn digest_ffi_contract() {
        let mut out = [0u8; 64];
        assert_eq!(unsafe { nntmux_nzb_source_digest(std::ptr::null(), 0, out.as_mut_ptr(), 64) }, 64);
        assert_eq!(unsafe { nntmux_nzb_source_digest(std::ptr::null(), 1, out.as_mut_ptr(), 64) }, -1);
        assert_eq!(unsafe { nntmux_nzb_source_digest(b"x".as_ptr(), 1, out.as_mut_ptr(), 63) }, -2);
    }

    #[test]
    fn basename_dirname_follow_php() {
        assert_eq!(php_basename(b"/a/b.nzb.gz"), b"b.nzb.gz");
        assert_eq!(php_basename(b"/a/b/"), b"b");
        assert_eq!(php_basename(b"///"), b"");
        assert_eq!(php_dirname(b"/a/b"), b"/a");
        assert_eq!(php_dirname(b"b"), b".");
        assert_eq!(php_dirname(b"/b"), b"/");
        assert_eq!(php_dirname(b"//"), b"/");
        assert_eq!(php_dirname(b"a//b//"), b"a");
    }

    #[test]
    fn anchor_validation() {
        let guid = b"0123456789abcdef0123456789abcdef";
        let anchor = b"/d/.archive-audit-0123456789abcdef0123456789abcdef-00112233445566778899aabbccddeeff.anchor";
        assert_eq!(validate_anchor_code(false, guid, b"/d/0123456789abcdef0123456789abcdef.nzb.gz", anchor, b"1", b"2"), 0);
        assert_eq!(validate_anchor_code(true, guid, b"/x/0123456789abcdef0123456789abcdef.nzb", b"", b"", b""), 0);
        assert_eq!(validate_anchor_code(false, guid, b"/e/0123456789abcdef0123456789abcdef.nzb.gz", anchor, b"1", b"2"), 2);
        assert_eq!(validate_anchor_code(false, guid, b"/d/0123456789abcdef0123456789abcdef.nzb.gz", anchor, b"1", b""), 1);
        assert_eq!(validate_anchor_code(false, guid, b"/d/0123456789abcdef0123456789abcdef.nzb.gz", anchor, b"-1", b"2"), 2);
        assert!(matches_anchor(0o100644, 5, -7, b"5", b"-7"));
        assert!(!matches_anchor(0o040755, 5, 7, b"5", b"7"));
        assert!(!matches_anchor(0o100644, 5, 7, b"05", b"7"));
    }

    #[test]
    fn staged_names() {
        assert_eq!(staged_guid_len(b"abc.nzb.gz.c12-0123456789abcdef.next"), Some(3));
        assert_eq!(staged_guid_len(b"a.nzb.gz.c1.nzb.gz.c2-0123456789abcdef.next"), Some(11));
        assert_eq!(staged_guid_len(b".nzb.gz.c2-0123456789abcdef.next"), None);
        assert_eq!(staged_guid_len(b"a.nzb.gz.c-0123456789abcdef.next"), None);
        assert_eq!(staged_guid_len(b"a.nzb.gz.c1-0123456789ABCDEF.next"), None);
        assert_eq!(staged_guid_len(b"a\n.nzb.gz.c1-0123456789abcdef.next"), None);
    }

    #[test]
    fn upload_manifest_rules() {
        assert!(safe_upload_filename(b"Show.NZB", b"nzb"));
        assert!(!safe_upload_filename(b" .nzb", b"nzb"));
        assert!(!safe_upload_filename(b"a/b.nzb", b"nzb"));
        assert!(!safe_upload_filename(b"nzb", b"nzb"));
        assert!(safe_upload_filename(b".x.nfo", b"nfo"));
        assert!(can_reconcile_candidate(Some(b"nzb_pending"), None));
        assert!(can_reconcile_candidate(Some(b"metadata_review"), Some(&[b'a'; 40])));
        assert!(!can_reconcile_candidate(Some(b"metadata_review"), Some(&[b'A'; 40])));
        assert!(!can_reconcile_candidate(None, None));
    }

    #[test]
    fn password_values() {
        assert!(password_value_valid("pässwörd\t\u{10FFFF}".as_bytes()));
        assert!(!password_value_valid(b"bad\x01"));
        assert!(!password_value_valid("\u{FFFE}".as_bytes()));
        assert!(!password_value_valid(b"\xC3"));
        assert!(!password_value_valid(&[b'a'; 4097]));
    }

    #[test]
    fn base64_and_parts() {
        for raw in [&b""[..], b"f", b"fo", b"foo", b"file.par2"] {
            let encoded: &[u8] = match raw {
                b"" => b"",
                b"f" => b"Zg==",
                b"fo" => b"Zm8=",
                b"foo" => b"Zm9v",
                _ => b"ZmlsZS5wYXIy",
            };
            assert!(base64_equals(raw, encoded));
        }
        let part = YencPartView { name: b"file.par2", size: 10, part: Some(2), begin: Some(6), end: Some(10) };
        let identity = PartIdentityView { name_base64: b"ZmlsZS5wYXIy", size: 10, part: Some(1) };
        assert_eq!(validate_part_code(Some(&part), 5, 2, 2, 5, Some(&identity)), 1);
        assert_eq!(validate_part_code(Some(&part), 4, 2, 2, 5, Some(&identity)), 6);
        assert_eq!(validate_part_code(None, 4, 2, 2, 5, Some(&identity)), 2);
        assert_eq!(validate_part_code(None, 4, 2, 2, 5, None), 0);
        let max = PartIdentityView { name_base64: b"ZmlsZS5wYXIy", size: 10, part: Some(i64::MAX) };
        assert_eq!(validate_part_code(Some(&part), 5, 2, 2, 5, Some(&max)), 4);
    }
}

// ---------------------------------------------------------------------------
// Par2FileList / Par2Processor / ReleaseFilesArchiveFallback / MediaSourceStage /
// AdditionalProcessing DTO helpers
// ---------------------------------------------------------------------------

const PAR2FL_MARKER: &[u8] = b"PAR2\0PKT";
const PAR2FL_MAIN: &[u8] = b"PAR 2.0\0Main\0\0\0\0";
const PAR2FL_FILEDESC: &[u8] = b"PAR 2.0\0FileDesc";
const PAR2FL_FILEVER: &[u8] = b"PAR 2.0\0IFSC\0\0\0\0";
const PAR2FL_RECOVERY: &[u8] = b"PAR 2.0\0RecvSlic";
const PAR2FL_CREATOR: &[u8] = b"PAR 2.0\0Creator\0";
/// `ArchiveReader::$maxFilenameLength`.
const PAR2FL_MAX_FILENAME: usize = 256;

#[derive(Clone, Copy)]
enum Par2FlLength {
    Int(i64),
    Float(f64),
}

struct Par2FlDesc<'a> {
    file_id: &'a [u8],
    hash: &'a [u8],
    hash16k: &'a [u8],
    length: Par2FlLength,
    name: &'a [u8],
    next_offset: u64,
}

enum Par2FlBody<'a> {
    Main(Vec<&'a [u8]>),
    Desc(Par2FlDesc<'a>),
    Ver(&'a [u8], usize),
    Other,
}

struct Par2FlPacket<'a> {
    set: &'a [u8],
    body: Par2FlBody<'a>,
}

#[inline]
fn par2fl_u32(data: &[u8], at: usize) -> u32 {
    u32::from_le_bytes([data[at], data[at + 1], data[at + 2], data[at + 3]])
}

#[inline]
fn par2fl_rtrim(mut s: &[u8]) -> &[u8] {
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
fn par2fl_truncated_name(name: &[u8]) -> &[u8] {
    &name[..name.len().min(PAR2FL_MAX_FILENAME)]
}

/// `Par2Info::setData()` packet analysis over already-truncated data. `None` is a parser
/// error (`$par2Info->error` set); a read past the data ends the packet list as PHP does.
fn par2fl_parse(data: &[u8]) -> Option<Vec<Par2FlPacket<'_>>> {
    if data.is_empty() {
        return None;
    }
    let length = data.len() as u64;
    let mut offset = data.windows(PAR2FL_MARKER.len()).position(|w| w == PAR2FL_MARKER)? as u64;
    let mut packets = Vec::new();

    'packets: while offset < length {
        if offset + 64 > length {
            break;
        }
        let at = offset as usize;
        let high = par2fl_u32(data, at + 12);
        if high > 0x7FFF_FFFF {
            // A float head_length can never be read back for verification.
            break;
        }
        let head_length = (u64::from(high) << 32) | u64::from(par2fl_u32(data, at + 8));
        if head_length < 32 {
            break;
        }
        let next = offset + head_length;
        if head_length > 32 && next > length {
            break;
        }
        let hashed = if head_length == 32 { &[][..] } else { &data[at + 32..next as usize] };
        if crate::md5_raw_slice(hashed)[..] != data[at + 16..at + 32] {
            return None;
        }
        let set = &data[at + 32..at + 48];
        let kind = &data[at + 48..at + 64];
        let mut cur = offset + 64;

        macro_rules! read {
            ($n:expr) => {{
                let n = $n as u64;
                if cur + n > length {
                    break 'packets;
                }
                let start = cur as usize;
                cur += n;
                &data[start..cur as usize]
            }};
        }

        let body = if kind == PAR2FL_MAIN {
            let head = read!(12);
            let count = par2fl_u32(head, 8);
            let mut ids = Vec::new();
            for _ in 0..count {
                ids.push(read!(16));
            }
            while cur < next {
                ids.push(read!(16));
            }
            Par2FlBody::Main(ids)
        } else if kind == PAR2FL_FILEDESC {
            let head = read!(56);
            let low = par2fl_u32(head, 48);
            let high = par2fl_u32(head, 52);
            let length_value = if high <= 0x7FFF_FFFF {
                Par2FlLength::Int(((u64::from(high) << 32) | u64::from(low)) as i64)
            } else {
                Par2FlLength::Float(f64::from(low) + f64::from(high) * 4_294_967_296.0)
            };
            if cur > next {
                break;
            }
            let name = if cur == next { &[][..] } else { read!(next - cur) };
            Par2FlBody::Desc(Par2FlDesc {
                file_id: &head[0..16],
                hash: &head[16..32],
                hash16k: &head[32..48],
                length: length_value,
                name: par2fl_rtrim(name),
                next_offset: next,
            })
        } else if kind == PAR2FL_FILEVER {
            let file_id = read!(16);
            let mut blocks = 0usize;
            while cur < next {
                let _ = read!(20);
                blocks += 1;
            }
            Par2FlBody::Ver(file_id, blocks)
        } else if kind == PAR2FL_RECOVERY {
            let _ = read!(4);
            Par2FlBody::Other
        } else if kind == PAR2FL_CREATOR {
            if cur > next {
                break;
            }
            if cur < next {
                let _ = read!(next - cur);
            }
            Par2FlBody::Other
        } else {
            Par2FlBody::Other
        };
        packets.push(Par2FlPacket { set, body });

        cur = next;
        if offset == cur {
            return None;
        }
        offset = cur;
    }

    if packets.is_empty() { None } else { Some(packets) }
}

struct Par2FlFile<'p, 'a> {
    file_id: &'a [u8],
    desc: Option<&'p Par2FlDesc<'a>>,
    blocks: usize,
}

/// `Par2FileList::getFiles()` entries in key order and `! hasUnresolvedDescriptors()`.
fn par2fl_files<'p, 'a>(packets: &'p [Par2FlPacket<'a>]) -> (Vec<Par2FlFile<'p, 'a>>, bool) {
    use std::collections::HashMap;

    let mut files: Vec<Par2FlFile<'p, 'a>> = Vec::new();
    let mut index: HashMap<&'a [u8], usize> = HashMap::new();
    let mut required: Vec<(&'a [u8], &'a [u8])> = Vec::new();
    let mut described: HashMap<(&'a [u8], &'a [u8]), bool> = HashMap::new();

    for packet in packets {
        match &packet.body {
            Par2FlBody::Main(ids) => {
                for &id in ids {
                    required.push((packet.set, id));
                }
            }
            Par2FlBody::Desc(desc) => {
                let slot = *index.entry(desc.file_id).or_insert_with(|| {
                    files.push(Par2FlFile { file_id: desc.file_id, desc: None, blocks: 0 });
                    files.len() - 1
                });
                if files[slot].desc.is_none() {
                    files[slot].desc = Some(desc);
                }
                required.push((packet.set, desc.file_id));
                described
                    .entry((packet.set, desc.file_id))
                    .or_insert_with(|| !trim_php(par2fl_truncated_name(desc.name)).is_empty());
            }
            Par2FlBody::Ver(file_id, blocks) => {
                let slot = *index.entry(file_id).or_insert_with(|| {
                    files.push(Par2FlFile { file_id, desc: None, blocks: 0 });
                    files.len() - 1
                });
                if files[slot].blocks == 0 {
                    files[slot].blocks = *blocks;
                }
                required.push((packet.set, file_id));
            }
            Par2FlBody::Other => {}
        }
    }

    let complete = !described.is_empty()
        && required.iter().all(|key| described.get(key).copied().unwrap_or(false));

    (files, complete)
}

#[inline]
fn par2fl_hex(out: &mut OutCursor<'_>, raw: &[u8]) {
    const HEX: &[u8; 16] = b"0123456789abcdef";
    for &b in raw {
        out.push(HEX[(b >> 4) as usize]);
        out.push(HEX[(b & 0x0f) as usize]);
    }
}

/// `Par2FileList::extractFiles()` over the first `len` bytes of PAR2 data.
///
/// Output: `[status u8][complete u8][count u32 LE]`, then per file
/// `[flags u8][file_id hex 32][blocks u64 LE]` and, when flag bit 0 is set,
/// `[size: i64 LE, or f64 LE when bit 1 is set][next_offset u64 LE][hash hex 32]`
/// `[hash_16K hex 32][name_len u32 LE][name]`. Status 0 is a parser error.
/// Returns the byte count, `-needed` when `out_cap` is too small, or `-1` on bad input.
///
/// # Safety
///
/// `data` must be valid for `len` bytes when `len > 0`; `out` must be valid for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2fl_extract_files(
    data: *const u8,
    len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(data) = (unsafe { rfm_input(data, len) }) else {
        return -1;
    };
    let mut cursor = unsafe { OutCursor::new(out, out_cap) };
    let Some(packets) = par2fl_parse(data) else {
        cursor.extend(&[0, 0, 0, 0, 0, 0]);
        return cursor.finish();
    };
    let (files, complete) = par2fl_files(&packets);
    cursor.push(1);
    cursor.push(u8::from(complete));
    cursor.extend(&(files.len() as u32).to_le_bytes());
    for file in &files {
        let flags = match file.desc {
            None => 0u8,
            Some(desc) => 1 | if matches!(desc.length, Par2FlLength::Float(_)) { 2 } else { 0 },
        };
        cursor.push(flags);
        par2fl_hex(&mut cursor, file.file_id);
        cursor.extend(&(file.blocks as u64).to_le_bytes());
        if let Some(desc) = file.desc {
            match desc.length {
                Par2FlLength::Int(v) => cursor.extend(&v.to_le_bytes()),
                Par2FlLength::Float(v) => cursor.extend(&v.to_le_bytes()),
            }
            cursor.extend(&desc.next_offset.to_le_bytes());
            par2fl_hex(&mut cursor, desc.hash);
            par2fl_hex(&mut cursor, desc.hash16k);
            let name = par2fl_truncated_name(desc.name);
            let name: &[u8] = if name.is_empty() || name == b"0" { b"Unknown" } else { name };
            cursor.extend(&(name.len() as u32).to_le_bytes());
            cursor.extend(name);
        }
    }
    cursor.finish()
}

/// `Par2FileHashEvidence::sizeDecimal()` validity followed by the `PHP_INT_MAX` bound of
/// `Par2Processor::setFitsPayloadBudget()`.
fn par2_budget_decimal(s: &[u8]) -> Option<u64> {
    if s.is_empty() || s.len() > 19 || !s.iter().all(u8::is_ascii_digit) || (s.len() > 1 && s[0] == b'0') {
        return None;
    }
    let mut value = 0u64;
    for &b in s {
        value = value * 10 + u64::from(b - b'0');
    }
    (value <= i64::MAX as u64).then_some(value)
}

/// `Par2Processor::setFitsPayloadBudget()` over `count` newline-joined size candidates.
/// Returns 1/0, or -1 on bad input.
///
/// # Safety
///
/// `sizes` must be valid for `len` bytes when `len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_set_fits_payload_budget(
    sizes: *const u8,
    len: usize,
    count: usize,
    max_payload_bytes: i64,
) -> i32 {
    let Some(sizes) = (unsafe { rfm_input(sizes, len) }) else {
        return -1;
    };
    if max_payload_bytes <= 0 {
        return 0;
    }
    if count == 0 {
        return i32::from(sizes.is_empty());
    }
    let mut remaining = max_payload_bytes as u64;
    let mut seen = 0usize;
    for line in sizes.split(|&b| b == b'\n') {
        seen += 1;
        let Some(bytes) = par2_budget_decimal(line) else {
            return 0;
        };
        if bytes > remaining {
            return 0;
        }
        remaining -= bytes;
    }
    // A candidate holding a newline is not a decimal size.
    i32::from(seen == count)
}

#[inline]
fn nat_at(s: &[u8], i: usize) -> u8 {
    s.get(i).copied().unwrap_or(0)
}

#[inline]
fn c_isspace(b: u8) -> bool {
    b == b' ' || (0x09..=0x0D).contains(&b)
}

fn nat_compare_right(a: &[u8], ap: &mut usize, b: &[u8], bp: &mut usize) -> i32 {
    let mut bias = 0;
    loop {
        let a_done = *ap == a.len() || !a[*ap].is_ascii_digit();
        let b_done = *bp == b.len() || !b[*bp].is_ascii_digit();
        if a_done && b_done {
            return bias;
        } else if a_done {
            return -1;
        } else if b_done {
            return 1;
        } else if a[*ap] < b[*bp] {
            if bias == 0 {
                bias = -1;
            }
        } else if a[*ap] > b[*bp] && bias == 0 {
            bias = 1;
        }
        *ap += 1;
        *bp += 1;
    }
}

fn nat_compare_left(a: &[u8], ap: &mut usize, b: &[u8], bp: &mut usize) -> i32 {
    loop {
        let a_done = *ap == a.len() || !a[*ap].is_ascii_digit();
        let b_done = *bp == b.len() || !b[*bp].is_ascii_digit();
        if a_done && b_done {
            return 0;
        } else if a_done {
            return -1;
        } else if b_done {
            return 1;
        } else if a[*ap] < b[*bp] {
            return -1;
        } else if a[*ap] > b[*bp] {
            return 1;
        }
        *ap += 1;
        *bp += 1;
    }
}

/// PHP `strnatcmp_ex()` under the C locale; bytes past the end read as the zend_string NUL.
pub(crate) fn php_strnatcmp(a: &[u8], b: &[u8], fold_case: bool) -> std::cmp::Ordering {
    use std::cmp::Ordering;

    if a.is_empty() || b.is_empty() {
        return a.len().cmp(&b.len());
    }
    let (aend, bend) = (a.len(), b.len());
    let (mut ap, mut bp) = (0usize, 0usize);
    let mut leading = true;
    loop {
        let mut ca = nat_at(a, ap);
        let mut cb = nat_at(b, bp);

        while leading && ca == b'0' && ap + 1 < aend && a[ap + 1].is_ascii_digit() {
            ap += 1;
            ca = a[ap];
        }
        while leading && cb == b'0' && bp + 1 < bend && b[bp + 1].is_ascii_digit() {
            bp += 1;
            cb = b[bp];
        }
        leading = false;

        while c_isspace(ca) {
            ap += 1;
            ca = nat_at(a, ap);
        }
        while c_isspace(cb) {
            bp += 1;
            cb = nat_at(b, bp);
        }

        if ca.is_ascii_digit() && cb.is_ascii_digit() {
            let result = if ca == b'0' || cb == b'0' {
                nat_compare_left(a, &mut ap, b, &mut bp)
            } else {
                nat_compare_right(a, &mut ap, b, &mut bp)
            };
            if result != 0 {
                return result.cmp(&0);
            } else if ap == aend && bp == bend {
                return Ordering::Equal;
            } else if ap == aend {
                return Ordering::Less;
            } else if bp == bend {
                return Ordering::Greater;
            }
            ca = a[ap];
            cb = b[bp];
        }

        if fold_case {
            ca = ca.to_ascii_uppercase();
            cb = cb.to_ascii_uppercase();
        }
        match ca.cmp(&cb) {
            Ordering::Equal => {}
            other => return other,
        }

        ap += 1;
        bp += 1;
        if ap >= aend && bp >= bend {
            return Ordering::Equal;
        } else if ap >= aend {
            return Ordering::Less;
        } else if bp >= bend {
            return Ordering::Greater;
        }
    }
}

/// `MediaSourceStage::middleVolumeIndex()`: input position of the middle title after a stable
/// `strnatcasecmp()` sort of `count` NUL-joined titles. Returns the index, `-2` when the
/// titles do not split into `count` parts (a title holds NUL), or `-1` on bad input.
///
/// # Safety
///
/// `titles` must be valid for `len` bytes when `len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_media_middle_volume_index(titles: *const u8, len: usize, count: usize) -> i64 {
    let Some(titles) = (unsafe { rfm_input(titles, len) }) else {
        return -1;
    };
    if count == 0 {
        return -1;
    }
    let parts: Vec<&[u8]> = titles.split(|&b| b == 0).collect();
    if parts.len() != count {
        return -2;
    }
    let mut order: Vec<usize> = (0..count).collect();
    order.sort_by(|&x, &y| php_strnatcmp(parts[x], parts[y], true));
    order[count / 2] as i64
}

/// `ReleaseFilesArchiveFallback::isArchiveImageName()`: `/\.(jpe?g|png|webp)$/i`.
pub(crate) fn rfaf_is_archive_image_name(name: &[u8]) -> bool {
    let name = rfm_without_final_newline(name);
    rfm_ends_with_ci(name, b".jpg")
        || rfm_ends_with_ci(name, b".jpeg")
        || rfm_ends_with_ci(name, b".png")
        || rfm_ends_with_ci(name, b".webp")
}

/// # Safety
///
/// `ptr` must be valid for reads of `len` bytes when non-null and `len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rfaf_is_archive_image_name(ptr: *const u8, len: usize) -> i32 {
    match unsafe { rfm_input(ptr, len) } {
        Some(name) => i32::from(rfaf_is_archive_image_name(name)),
        None => -1,
    }
}

/// `ReleaseFilesArchiveFallback::extractedFileSuffix()`: `'.'.strtolower(pathinfo(ext))` or `''`.
///
/// # Safety
///
/// `ptr` must be valid for `len` bytes when `len > 0`; `out` must be valid for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rfaf_extracted_file_suffix(
    ptr: *const u8,
    len: usize,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(name) = (unsafe { rfm_input(ptr, len) }) else {
        return -1;
    };
    let base = posix_basename(name);
    let Some(dot) = base.iter().rposition(|&b| b == b'.') else {
        return 0;
    };
    let ext = &base[dot + 1..];
    if ext.is_empty() {
        return 0;
    }
    let mut cursor = unsafe { OutCursor::new(out, out_cap) };
    cursor.push(b'.');
    for &b in ext {
        cursor.push(b.to_ascii_lowercase());
    }
    cursor.finish()
}

/// `ArchivedMediaEntry::continuationBytes()`. Returns the byte count, or `-1` when PHP
/// integer arithmetic would overflow and the caller must use the PHP path.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_media_continuation_bytes(
    range_start: i64,
    head_length: i64,
    volume_bytes_downloaded: i64,
    in_volume_end: i64,
    real_size: i64,
    target: i64,
) -> i64 {
    let Some(head_end) = range_start.checked_add(head_length) else {
        return -1;
    };
    if head_end != volume_bytes_downloaded {
        return 0;
    }
    let Some(wanted) = target.min(real_size).checked_sub(head_length) else {
        return -1;
    };
    let Some(left) = in_volume_end
        .checked_add(1)
        .and_then(|end| end.checked_sub(volume_bytes_downloaded))
    else {
        return -1;
    };
    wanted.min(left).max(0)
}

#[cfg(test)]
mod wave13_par2_archive_fallback_tests {
    use super::*;
    use std::cmp::Ordering;

    fn packet(kind: &[u8], set: &[u8; 16], body: &[u8]) -> Vec<u8> {
        let mut hashed = set.to_vec();
        hashed.extend_from_slice(kind);
        hashed.extend_from_slice(body);
        let mut out = PAR2FL_MARKER.to_vec();
        out.extend_from_slice(&((64 + body.len()) as u64).to_le_bytes());
        out.extend_from_slice(&crate::md5_raw_slice(&hashed));
        out.extend_from_slice(&hashed);
        out
    }

    fn desc_body(id: u8, name: &[u8]) -> Vec<u8> {
        let mut body = vec![id; 16];
        body.extend_from_slice(&[0xAB; 16]);
        body.extend_from_slice(&[0xCD; 16]);
        body.extend_from_slice(&1234u64.to_le_bytes());
        body.extend_from_slice(name);
        body
    }

    #[test]
    fn par2_files_follow_descriptor_and_verification_order() {
        let set = [7u8; 16];
        let mut ifsc = vec![2u8; 16];
        ifsc.extend_from_slice(&[0u8; 40]);
        let mut main = 0u64.to_le_bytes().to_vec();
        main.extend_from_slice(&2u32.to_le_bytes());
        main.extend_from_slice(&[1u8; 16]);
        main.extend_from_slice(&[2u8; 16]);
        let mut data = b"junk".to_vec();
        data.extend(packet(PAR2FL_MAIN, &set, &main));
        data.extend(packet(PAR2FL_FILEVER, &set, &ifsc));
        data.extend(packet(PAR2FL_FILEDESC, &set, &desc_body(1, b"a.mkv\0\0\0")));
        data.extend(packet(PAR2FL_FILEDESC, &set, &desc_body(2, b"0\0\0\0")));
        let packets = par2fl_parse(&data).expect("valid PAR2");
        let (files, complete) = par2fl_files(&packets);
        assert!(complete);
        assert_eq!(files.len(), 2);
        assert_eq!(files[0].file_id, &[2u8; 16]);
        assert_eq!(files[0].blocks, 2);
        assert_eq!(files[0].desc.map(|d| d.name), Some(&b"0"[..]));
        assert_eq!(files[1].desc.map(|d| d.name), Some(&b"a.mkv"[..]));

        let mut corrupt = data.clone();
        let last = corrupt.len() - 1;
        corrupt[last] ^= 1;
        assert!(par2fl_parse(&corrupt).is_none());
        assert!(par2fl_parse(b"no marker").is_none());
        assert!(par2fl_parse(b"").is_none());
    }

    #[test]
    fn payload_budget_decimals() {
        assert_eq!(par2_budget_decimal(b"0"), Some(0));
        assert_eq!(par2_budget_decimal(b"9223372036854775807"), Some(i64::MAX as u64));
        assert_eq!(par2_budget_decimal(b"9223372036854775808"), None);
        assert_eq!(par2_budget_decimal(b"01"), None);
        assert_eq!(par2_budget_decimal(b""), None);
        let sizes = b"10\n20";
        assert_eq!(unsafe { nntmux_par2_set_fits_payload_budget(sizes.as_ptr(), sizes.len(), 2, 30) }, 1);
        assert_eq!(unsafe { nntmux_par2_set_fits_payload_budget(sizes.as_ptr(), sizes.len(), 2, 29) }, 0);
        assert_eq!(unsafe { nntmux_par2_set_fits_payload_budget(sizes.as_ptr(), sizes.len(), 1, 30) }, 0);
    }

    #[test]
    fn natural_compare_matches_php() {
        assert_eq!(php_strnatcmp(b"img12.png", b"img10.png", true), Ordering::Greater);
        assert_eq!(php_strnatcmp(b"x01", b"x1", true), Ordering::Less);
        assert_eq!(php_strnatcmp(b"a b", b"ab", true), Ordering::Equal);
        assert_eq!(php_strnatcmp(b"a ", b"a", true), Ordering::Greater);
        assert_eq!(php_strnatcmp(b"007", b"7", true), Ordering::Equal);
        assert_eq!(php_strnatcmp(b"A", b"a", true), Ordering::Equal);
        assert_eq!(php_strnatcmp(b"", b"a", true), Ordering::Less);
        let titles = b"r.part3.rar\0r.part10.rar\0r.part1.rar";
        assert_eq!(unsafe { nntmux_media_middle_volume_index(titles.as_ptr(), titles.len(), 3) }, 0);
        assert_eq!(unsafe { nntmux_media_middle_volume_index(titles.as_ptr(), titles.len(), 2) }, -2);
    }

    #[test]
    fn archive_image_names_and_suffixes() {
        assert!(rfaf_is_archive_image_name(b"a/Cover.JPEG"));
        assert!(rfaf_is_archive_image_name(b"x.webp\n"));
        assert!(!rfaf_is_archive_image_name(b"x.jpg.nfo"));
        let mut out = [0u8; 8];
        let n = unsafe { nntmux_rfaf_extracted_file_suffix(b"dir.d/F.JPG".as_ptr(), 11, out.as_mut_ptr(), out.len()) };
        assert_eq!(&out[..n as usize], b".jpg");
        assert_eq!(unsafe { nntmux_rfaf_extracted_file_suffix(b"x.d/".as_ptr(), 4, out.as_mut_ptr(), out.len()) }, 2);
        assert_eq!(unsafe { nntmux_rfaf_extracted_file_suffix(b"noext".as_ptr(), 5, out.as_mut_ptr(), out.len()) }, 0);
    }

    #[test]
    fn continuation_bytes() {
        assert_eq!(nntmux_media_continuation_bytes(10, 90, 100, 999, 5000, 400), 310);
        assert_eq!(nntmux_media_continuation_bytes(10, 80, 100, 999, 5000, 400), 0);
        assert_eq!(nntmux_media_continuation_bytes(0, 100, 100, 149, 5000, 400), 50);
        assert_eq!(nntmux_media_continuation_bytes(i64::MAX, 1, 0, 0, 0, 0), -1);
    }
}

#[cfg(test)]
mod wave14_context_diagnostics_tests {
    use super::*;

    type JsonHelper = unsafe extern "C" fn(*const u8, usize, *mut u8, usize) -> isize;

    fn call(helper: JsonHelper, input: &str) -> String {
        let required = unsafe { helper(input.as_ptr(), input.len(), std::ptr::null_mut(), 0) };
        assert!(required <= -2, "unexpected sizing result {required}");
        let mut out = vec![0u8; (-required) as usize];
        let size = unsafe { helper(input.as_ptr(), input.len(), out.as_mut_ptr(), out.len()) };
        assert_eq!(size, out.len() as isize);
        String::from_utf8(out).unwrap()
    }

    #[test]
    fn naming_witness_utf8_and_exact_limits() {
        let name = "é";
        assert_eq!(
            unsafe {
                nntmux_additional_validate_naming_witness(name.as_ptr(), name.len(), 255, 32766)
            },
            1
        );
        assert_eq!(
            unsafe { nntmux_additional_validate_naming_witness(name.as_ptr(), name.len(), 256, 0) },
            0
        );
        assert_eq!(
            unsafe {
                nntmux_additional_validate_naming_witness(name.as_ptr(), name.len(), 0, usize::MAX)
            },
            0
        );
        assert_eq!(
            unsafe { nntmux_additional_validate_naming_witness([0xff].as_ptr(), 1, 0, 0) },
            0
        );
        assert_eq!(
            unsafe { nntmux_additional_validate_naming_witness(std::ptr::null(), 1, 0, 0) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_additional_validate_naming_witness(std::ptr::null(), 0, 0, 0) },
            0
        );
        assert_eq!(
            unsafe {
                nntmux_additional_validate_naming_witness(1usize as *const u8, usize::MAX, 0, 0)
            },
            -1
        );
    }

    #[test]
    fn json_abi_rejects_bad_pointers_and_short_output_is_untouched() {
        let input = br#"{"ids":[1],"driver":"mysql"}"#;
        let helper = nntmux_additional_id_order_expression_json;
        assert_eq!(
            unsafe { helper(std::ptr::null(), 1, std::ptr::null_mut(), 0) },
            -1
        );
        assert_eq!(
            unsafe { helper(1usize as *const u8, usize::MAX, std::ptr::null_mut(), 0) },
            -1
        );
        assert_eq!(
            unsafe { helper(input.as_ptr(), input.len(), std::ptr::null_mut(), 1) },
            -1
        );
        let mut out = [0xAAu8; 2];
        let required = unsafe { helper(input.as_ptr(), input.len(), out.as_mut_ptr(), 2) };
        assert!(required <= -2);
        assert_eq!(out, [0xAA; 2]);
        assert_eq!(
            unsafe { helper(input.as_ptr(), input.len(), out.as_mut_ptr(), usize::MAX) },
            -1
        );
    }

    #[test]
    fn id_order_is_json_and_preserves_signed_integer_extremes() {
        assert_eq!(
            call(
                nntmux_additional_id_order_expression_json,
                r#"{"ids":[1,-2,9223372036854775807,-9223372036854775808],"driver":"mysql"}"#
            ),
            r#""FIELD(id, 1,-2,9223372036854775807,-9223372036854775808)""#
        );
        assert_eq!(
            call(
                nntmux_additional_id_order_expression_json,
                r#"{"ids":[2,2,0],"driver":"sqlite"}"#
            ),
            r#""CASE id WHEN 2 THEN 0 WHEN 2 THEN 1 WHEN 0 THEN 2 ELSE 3 END""#
        );
        assert_eq!(
            call(
                nntmux_additional_id_order_expression_json,
                r#"{"ids":[],"driver":"sqlite"}"#
            ),
            r#""CASE id  ELSE 0 END""#
        );
    }

    #[test]
    fn strict_json_declines_malformed_or_unbounded_values() {
        for input in [
            "",
            "[",
            r#"{"ids":[01],"driver":"mysql"}"#,
            r#"{"ids":[1.0],"driver":"mysql"}"#,
            r#"{"ids":[9223372036854775808],"driver":"mysql"}"#,
            r#"{"ids":[],"driver":"\uD800"}"#,
            r#"{"ids":[],"driver":"\uDC00"}"#,
            r#"{"ids":[],"driver":"mysql","driver":"sqlite"}"#,
            r#"{"ids":[],"driver":"mysql"}x"#,
        ] {
            assert_eq!(
                unsafe {
                    nntmux_additional_id_order_expression_json(
                        input.as_ptr(),
                        input.len(),
                        std::ptr::null_mut(),
                        0,
                    )
                },
                -1,
                "{input}"
            );
        }
        let deep = format!("{}0{}", "[".repeat(70), "]".repeat(70));
        assert!(AdditionalJsonParser::parse(deep.as_bytes()).is_none());
        assert!(AdditionalJsonParser::parse(b"[1e999]").is_none());
        assert!(AdditionalJsonParser::parse(b"[\"\xff\"]").is_none());
    }

    #[test]
    fn compressed_media_uses_php_truthiness() {
        assert_eq!(
            call(nntmux_additional_compressed_files_are_media_json, "[]"),
            "false"
        );
        for value in ["false", "null", "0", "-0.0", "\"\"", "\"0\"", "[]"] {
            let input = format!("[{{\"is_media\":{value}}}]");
            assert_eq!(
                call(nntmux_additional_compressed_files_are_media_json, &input),
                "false"
            );
        }
        for input in [
            "[null]", "[false]", "[0]", "[\"x\"]", "[[]]", "[[1]]", "[{}]",
        ] {
            assert_eq!(
                call(nntmux_additional_compressed_files_are_media_json, input),
                "false"
            );
        }
        assert_eq!(
            call(
                nntmux_additional_compressed_files_are_media_json,
                r#"[{"is_media":{}}]"#
            ),
            "true"
        );
        assert_eq!(
            call(
                nntmux_additional_compressed_files_are_media_json,
                r#"[{"is_media":true},{"is_media":"false"},{"is_media":[0]}]"#
            ),
            "true"
        );
    }

    #[test]
    fn diagnostics_requires_exact_order_and_checked_products() {
        let input = r#"{"columns":["passwordstatus","haspreview","nzbstatus","leftguid","postdate","id","additional_pp_claimed_at"],"childTimeout":1800,"releaseTimeout":300,"batchSize":10,"threads":4}"#;
        assert_eq!(
            call(nntmux_additional_claim_index_matches_json, input),
            r#"{"matches":true,"maximumClaimLifetime":1800,"maxInFlight":40}"#
        );
        assert_eq!(
            call(
                nntmux_additional_claim_index_matches_json,
                r#"{"columns":[],"childTimeout":-1,"releaseTimeout":0,"batchSize":0,"threads":-1}"#
            ),
            r#"{"matches":false,"maximumClaimLifetime":1,"maxInFlight":1}"#
        );
        let overflow = r#"{"columns":[],"childTimeout":1,"releaseTimeout":9223372036854775807,"batchSize":2,"threads":1}"#;
        assert_eq!(
            unsafe {
                nntmux_additional_claim_index_matches_json(
                    overflow.as_ptr(),
                    overflow.len(),
                    std::ptr::null_mut(),
                    0,
                )
            },
            -1
        );
    }

    #[test]
    fn metrics_keep_map_order_unicode_and_ieee_bits() {
        let input = r#"[{"outcome":"ok","successful":true,"artifactsCreated":true,"unsupportedReasons":["\uD83D\uDE00","x","x"],"stageDurations":{"z":0.1,"a":-0.2},"downloadMetrics":{"logicalRequests":1,"networkRequests":2,"cacheHits":3,"bytesDownloaded":4,"bytesReused":5,"mediaBytesDownloaded":6},"persistenceMetrics":{"databaseStatements":2,"databaseMilliseconds":0.1,"searchSyncRequests":3,"searchSyncExecutions":4}},{"outcome":"bad","successful":true,"artifactsCreated":false,"unsupportedReasons":["x"],"stageDurations":{"z":0.2,"新":1},"downloadMetrics":null,"persistenceMetrics":null}]"#;
        let expected = format!(
            "{{\"outcomeCounts\":{{\"ok\":1,\"bad\":1}},\"unsupportedReasonCounts\":{{\"😀\":1,\"x\":3}},\"stageDurationTotals\":{{\"z\":\"{}\",\"a\":\"{}\",\"新\":\"{}\"}},\"downloadMetrics\":{{\"logicalRequests\":1,\"networkRequests\":2,\"cacheHits\":3,\"bytesDownloaded\":4,\"bytesReused\":5,\"mediaBytesDownloaded\":6}},\"persistenceMetrics\":{{\"databaseStatements\":2,\"databaseMilliseconds\":\"{}\",\"searchSyncRequests\":3,\"searchSyncExecutions\":4}},\"artifactYieldPercent\":\"{}\"}}",
            (0.1f64 + 0.2).to_bits() as i64,
            (-0.2f64).to_bits() as i64,
            1.0f64.to_bits() as i64,
            0.1f64.to_bits() as i64,
            50.0f64.to_bits() as i64
        );
        assert_eq!(
            call(nntmux_additional_batch_result_metrics_json, input),
            expected
        );
        let empty = call(nntmux_additional_batch_result_metrics_json, "[]");
        assert!(empty.contains(r#""stageDurationTotals":{}"#));
        assert!(empty.ends_with(r#""artifactYieldPercent":"0"}"#));
    }
}

// Wave 14: bounded JSON wire helpers. Objects retain source insertion order. The
// parser deliberately declines duplicate keys, excessive nesting and non-finite
// numbers so the caller can fall back without accepting ambiguous input.
const ADDITIONAL_JSON_MAX_BYTES: usize = 16 * 1024 * 1024;
const ADDITIONAL_JSON_MAX_VALUES: usize = 1_000_000;
const ADDITIONAL_JSON_MAX_DEPTH: usize = 64;

#[derive(Debug)]
enum AdditionalJson {
    Null,
    Bool(bool),
    Number(String),
    String(String),
    Array(Vec<AdditionalJson>),
    Object(Vec<(String, AdditionalJson)>),
}

impl AdditionalJson {
    fn field(&self, key: &str) -> Option<&Self> {
        self.object()?
            .iter()
            .find(|(name, _)| name == key)
            .map(|(_, value)| value)
    }

    fn object(&self) -> Option<&[(String, Self)]> {
        match self {
            Self::Object(value) => Some(value),
            _ => None,
        }
    }

    fn array(&self) -> Option<&[Self]> {
        match self {
            Self::Array(value) => Some(value),
            _ => None,
        }
    }

    fn string(&self) -> Option<&str> {
        match self {
            Self::String(value) => Some(value),
            _ => None,
        }
    }

    fn integer(&self) -> Option<i64> {
        match self {
            Self::Number(value) => value.parse().ok(),
            _ => None,
        }
    }

    fn number(&self) -> Option<f64> {
        match self {
            Self::Number(value) => value.parse::<f64>().ok().filter(|value| value.is_finite()),
            _ => None,
        }
    }

    fn boolean(&self) -> Option<bool> {
        match self {
            Self::Bool(value) => Some(*value),
            _ => None,
        }
    }

    // An is_media object represents a PHP object, which is always truthy.
    fn php_truthy(&self) -> bool {
        match self {
            Self::Null => false,
            Self::Bool(value) => *value,
            Self::Number(_) => self.number().is_some_and(|value| value != 0.0),
            Self::String(value) => !value.is_empty() && value != "0",
            Self::Array(value) => !value.is_empty(),
            Self::Object(_) => true,
        }
    }
}

struct AdditionalJsonParser<'a> {
    input: &'a [u8],
    position: usize,
    remaining: usize,
}

impl<'a> AdditionalJsonParser<'a> {
    fn parse(input: &'a [u8]) -> Option<AdditionalJson> {
        if input.len() > ADDITIONAL_JSON_MAX_BYTES || std::str::from_utf8(input).is_err() {
            return None;
        }
        let mut parser = Self {
            input,
            position: 0,
            remaining: ADDITIONAL_JSON_MAX_VALUES,
        };
        let result = parser.value(0)?;
        parser.space();
        (parser.position == input.len()).then_some(result)
    }

    fn space(&mut self) {
        while self
            .input
            .get(self.position)
            .is_some_and(|b| matches!(b, b' ' | b'\t' | b'\r' | b'\n'))
        {
            self.position += 1;
        }
    }

    fn take(&mut self, expected: u8) -> bool {
        if self.input.get(self.position) == Some(&expected) {
            self.position += 1;
            true
        } else {
            false
        }
    }

    fn literal(&mut self, text: &[u8]) -> Option<()> {
        if !self.input.get(self.position..)?.starts_with(text) {
            return None;
        }
        self.position += text.len();
        Some(())
    }

    fn value(&mut self, depth: usize) -> Option<AdditionalJson> {
        if depth > ADDITIONAL_JSON_MAX_DEPTH || self.remaining == 0 {
            return None;
        }
        self.remaining -= 1;
        self.space();
        match *self.input.get(self.position)? {
            b'n' => {
                self.literal(b"null")?;
                Some(AdditionalJson::Null)
            }
            b't' => {
                self.literal(b"true")?;
                Some(AdditionalJson::Bool(true))
            }
            b'f' => {
                self.literal(b"false")?;
                Some(AdditionalJson::Bool(false))
            }
            b'"' => self.text().map(AdditionalJson::String),
            b'[' => {
                self.position += 1;
                self.space();
                let mut values = Vec::new();
                if self.take(b']') {
                    return Some(AdditionalJson::Array(values));
                }
                loop {
                    values.push(self.value(depth + 1)?);
                    self.space();
                    if self.take(b']') {
                        break;
                    }
                    if !self.take(b',') {
                        return None;
                    }
                }
                Some(AdditionalJson::Array(values))
            }
            b'{' => {
                self.position += 1;
                self.space();
                let mut values = Vec::new();
                let mut keys = std::collections::HashSet::new();
                if self.take(b'}') {
                    return Some(AdditionalJson::Object(values));
                }
                loop {
                    self.space();
                    let key = self.text()?;
                    if !keys.insert(key.clone()) {
                        return None;
                    }
                    self.space();
                    if !self.take(b':') {
                        return None;
                    }
                    values.push((key, self.value(depth + 1)?));
                    self.space();
                    if self.take(b'}') {
                        break;
                    }
                    if !self.take(b',') {
                        return None;
                    }
                }
                Some(AdditionalJson::Object(values))
            }
            b'-' | b'0'..=b'9' => self.numeric().map(AdditionalJson::Number),
            _ => None,
        }
    }

    fn numeric(&mut self) -> Option<String> {
        let start = self.position;
        self.take(b'-');
        if !self.take(b'0') {
            if !self
                .input
                .get(self.position)
                .is_some_and(|b| matches!(b, b'1'..=b'9'))
            {
                return None;
            }
            self.position += 1;
            while self
                .input
                .get(self.position)
                .is_some_and(u8::is_ascii_digit)
            {
                self.position += 1;
            }
        }
        if self.take(b'.') {
            let digits = self.position;
            while self
                .input
                .get(self.position)
                .is_some_and(u8::is_ascii_digit)
            {
                self.position += 1;
            }
            if digits == self.position {
                return None;
            }
        }
        if self.take(b'e') || self.take(b'E') {
            if !self.take(b'+') {
                self.take(b'-');
            }
            let digits = self.position;
            while self
                .input
                .get(self.position)
                .is_some_and(u8::is_ascii_digit)
            {
                self.position += 1;
            }
            if digits == self.position {
                return None;
            }
        }
        let text = std::str::from_utf8(&self.input[start..self.position]).ok()?;
        if !text.parse::<f64>().ok()?.is_finite() {
            return None;
        }
        Some(text.to_owned())
    }

    fn hex4(&mut self) -> Option<u32> {
        let mut code = 0u32;
        for _ in 0..4 {
            let digit = (*self.input.get(self.position)? as char).to_digit(16)?;
            self.position += 1;
            code = code * 16 + digit;
        }
        Some(code)
    }

    fn text(&mut self) -> Option<String> {
        if !self.take(b'"') {
            return None;
        }
        let mut text = String::new();
        let mut start = self.position;
        loop {
            let byte = *self.input.get(self.position)?;
            if byte == b'"' || byte == b'\\' {
                text.push_str(std::str::from_utf8(&self.input[start..self.position]).ok()?);
                self.position += 1;
                if byte == b'"' {
                    return Some(text);
                }
                let escaped = *self.input.get(self.position)?;
                self.position += 1;
                match escaped {
                    b'"' => text.push('"'),
                    b'\\' => text.push('\\'),
                    b'/' => text.push('/'),
                    b'b' => text.push('\x08'),
                    b'f' => text.push('\x0c'),
                    b'n' => text.push('\n'),
                    b'r' => text.push('\r'),
                    b't' => text.push('\t'),
                    b'u' => {
                        let mut code = self.hex4()?;
                        if (0xd800..=0xdbff).contains(&code) {
                            self.literal(b"\\u")?;
                            let low = self.hex4()?;
                            if !(0xdc00..=0xdfff).contains(&low) {
                                return None;
                            }
                            code = 0x10000 + ((code - 0xd800) << 10) + low - 0xdc00;
                        }
                        text.push(char::from_u32(code)?);
                    }
                    _ => return None,
                }
                start = self.position;
            } else {
                if byte < 0x20 {
                    return None;
                }
                self.position += 1;
            }
        }
    }
}

fn additional_json_quote(out: &mut String, value: &str) {
    use std::fmt::Write;
    out.push('"');
    for ch in value.chars() {
        match ch {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            ch if ch < '\x20' => {
                let _ = write!(out, "\\u{:04x}", ch as u32);
            }
            ch => out.push(ch),
        }
    }
    out.push('"');
}

fn additional_pointer_valid(ptr: *const u8, len: usize) -> bool {
    len <= isize::MAX as usize
        && (len == 0 || (!ptr.is_null() && (ptr as usize).checked_add(len).is_some()))
}

/// # Safety
/// A non-null `ptr` must point to a readable allocation of at least `len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_additional_validate_naming_witness(
    ptr: *const u8,
    len: usize,
    count: usize,
    total: usize,
) -> i32 {
    std::panic::catch_unwind(|| {
        if !additional_pointer_valid(ptr, len) {
            return -1;
        }
        if len == 0 || len > 4096 || count >= 256 || total > 32768 - len {
            return 0;
        }
        let bytes = unsafe { slice::from_raw_parts(ptr, len) };
        i32::from(std::str::from_utf8(bytes).is_ok())
    })
    .unwrap_or(-1)
}

// Validate both pointer/length pairs before constructing a slice. No output is
// touched until parsing and computation succeed and the complete output fits.
unsafe fn additional_json_ffi(
    ptr: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
    operation: fn(&AdditionalJson) -> Option<String>,
) -> isize {
    std::panic::catch_unwind(|| {
        if !additional_pointer_valid(ptr, len)
            || !additional_pointer_valid(out.cast_const(), cap)
            || len > ADDITIONAL_JSON_MAX_BYTES
        {
            return -1;
        }
        let bytes = if len == 0 {
            &[]
        } else {
            unsafe { slice::from_raw_parts(ptr, len) }
        };
        let Some(value) = AdditionalJsonParser::parse(bytes) else {
            return -1;
        };
        let Some(result) = operation(&value) else {
            return -1;
        };
        if result.len() > isize::MAX as usize {
            return -1;
        }
        if result.len() > cap {
            return -(result.len().max(2) as isize);
        }
        if !result.is_empty() {
            unsafe {
                std::ptr::copy_nonoverlapping(result.as_ptr(), out, result.len());
            }
        }
        result.len() as isize
    })
    .unwrap_or(-1)
}

fn additional_compressed_media(value: &AdditionalJson) -> Option<String> {
    let entries = value.array()?;
    let mut all_media = !entries.is_empty();
    for entry in entries {
        if !entry
            .field("is_media")
            .is_some_and(AdditionalJson::php_truthy)
        {
            all_media = false;
        }
    }
    Some(if all_media { "true" } else { "false" }.to_owned())
}

fn additional_id_order(value: &AdditionalJson) -> Option<String> {
    use std::fmt::Write;
    let ids = value.field("ids")?.array()?;
    let sqlite = value.field("driver")?.string()? == "sqlite";
    let mut expression = if sqlite {
        "CASE id ".to_owned()
    } else {
        "FIELD(id, ".to_owned()
    };
    for (position, id) in ids.iter().enumerate() {
        let id = id.integer()?;
        if sqlite {
            if position != 0 {
                expression.push(' ');
            }
            write!(expression, "WHEN {id} THEN {position}").ok()?;
        } else {
            if position != 0 {
                expression.push(',');
            }
            write!(expression, "{id}").ok()?;
        }
    }
    if sqlite {
        write!(expression, " ELSE {} END", ids.len()).ok()?;
    } else {
        expression.push(')');
    }
    let mut out = String::new();
    additional_json_quote(&mut out, &expression);
    Some(out)
}

fn additional_claim_index(value: &AdditionalJson) -> Option<String> {
    const REQUIRED: [&str; 7] = [
        "passwordstatus",
        "haspreview",
        "nzbstatus",
        "leftguid",
        "postdate",
        "id",
        "additional_pp_claimed_at",
    ];
    let columns = value.field("columns")?.array()?;
    let column_names: Vec<&str> = columns
        .iter()
        .map(AdditionalJson::string)
        .collect::<Option<_>>()?;
    let matches = column_names == REQUIRED;
    let child = value.field("childTimeout")?.integer()?.max(1);
    let release = value.field("releaseTimeout")?.integer()?.max(1);
    let batch = value.field("batchSize")?.integer()?.max(1);
    let threads = value.field("threads")?.integer()?.max(1);
    let maximum_lifetime = child.min(release.checked_mul(batch)?);
    let in_flight = threads.checked_mul(batch)?;
    Some(format!(
        "{{\"matches\":{matches},\"maximumClaimLifetime\":{maximum_lifetime},\"maxInFlight\":{in_flight}}}"
    ))
}

#[derive(Default)]
struct AdditionalOrderedTotals<T> {
    values: Vec<(String, T)>,
    positions: std::collections::HashMap<String, usize>,
}

impl<T: Default + Copy> AdditionalOrderedTotals<T> {
    fn slot(&mut self, key: &str) -> &mut T {
        let position = *self.positions.entry(key.to_owned()).or_insert_with(|| {
            let position = self.values.len();
            self.values.push((key.to_owned(), T::default()));
            position
        });
        &mut self.values[position].1
    }
}

fn additional_integer_map(out: &mut String, values: &[(String, i64)]) {
    use std::fmt::Write;
    out.push('{');
    for (position, (name, value)) in values.iter().enumerate() {
        if position != 0 {
            out.push(',');
        }
        additional_json_quote(out, name);
        let _ = write!(out, ":{value}");
    }
    out.push('}');
}

fn additional_float_bits(out: &mut String, value: f64) {
    additional_json_quote(out, &(value.to_bits() as i64).to_string());
}

fn additional_add_integer(target: &mut i64, value: &AdditionalJson) -> Option<()> {
    *target = target.checked_add(value.integer()?)?;
    Some(())
}

fn additional_add_float(target: &mut f64, value: &AdditionalJson) -> Option<()> {
    *target += value.number()?;
    target.is_finite().then_some(())
}

fn additional_batch_metrics(value: &AdditionalJson) -> Option<String> {
    use std::fmt::Write;
    const DOWNLOAD: [&str; 6] = [
        "logicalRequests",
        "networkRequests",
        "cacheHits",
        "bytesDownloaded",
        "bytesReused",
        "mediaBytesDownloaded",
    ];
    const PERSISTENCE: [&str; 4] = [
        "databaseStatements",
        "databaseMilliseconds",
        "searchSyncRequests",
        "searchSyncExecutions",
    ];
    let mut outcomes = AdditionalOrderedTotals::<i64>::default();
    let mut unsupported = AdditionalOrderedTotals::<i64>::default();
    let mut stages = AdditionalOrderedTotals::<f64>::default();
    let mut download = [0i64; 6];
    let mut persistence = [0i64; 4];
    let mut milliseconds = 0.0f64;
    let mut successful = 0i64;
    let mut artifacts = 0i64;
    for row in value.array()? {
        let outcome = row.field("outcome")?.string()?;
        let count = outcomes.slot(outcome);
        *count = count.checked_add(1)?;
        if row.field("successful")?.boolean()? {
            successful = successful.checked_add(1)?;
            if row.field("artifactsCreated")?.boolean()? {
                artifacts = artifacts.checked_add(1)?;
            }
        } else {
            row.field("artifactsCreated")?.boolean()?;
        }
        for reason in row.field("unsupportedReasons")?.array()? {
            let count = unsupported.slot(reason.string()?);
            *count = count.checked_add(1)?;
        }
        let stage_values = row.field("stageDurations")?;
        if let Some(entries) = stage_values.object() {
            for (name, duration) in entries {
                additional_add_float(stages.slot(name), duration)?;
            }
        } else if !stage_values.array()?.is_empty() {
            return None;
        }
        let metric = row.field("downloadMetrics")?;
        if !matches!(metric, AdditionalJson::Null) {
            metric.object()?;
            for (position, name) in DOWNLOAD.iter().enumerate() {
                additional_add_integer(&mut download[position], metric.field(name)?)?;
            }
        }
        let metric = row.field("persistenceMetrics")?;
        if !matches!(metric, AdditionalJson::Null) {
            metric.object()?;
            for (position, name) in PERSISTENCE.iter().enumerate() {
                if position == 1 {
                    additional_add_float(&mut milliseconds, metric.field(name)?)?;
                } else {
                    additional_add_integer(&mut persistence[position], metric.field(name)?)?;
                }
            }
        }
    }
    let mut out = "{\"outcomeCounts\":".to_owned();
    additional_integer_map(&mut out, &outcomes.values);
    out.push_str(",\"unsupportedReasonCounts\":");
    additional_integer_map(&mut out, &unsupported.values);
    out.push_str(",\"stageDurationTotals\":{");
    for (position, (name, total)) in stages.values.iter().enumerate() {
        if position != 0 {
            out.push(',');
        }
        additional_json_quote(&mut out, name);
        out.push(':');
        additional_float_bits(&mut out, *total);
    }
    out.push_str("},\"downloadMetrics\":{");
    for (position, name) in DOWNLOAD.iter().enumerate() {
        if position != 0 {
            out.push(',');
        }
        additional_json_quote(&mut out, name);
        write!(out, ":{}", download[position]).ok()?;
    }
    out.push_str("},\"persistenceMetrics\":{");
    for (position, name) in PERSISTENCE.iter().enumerate() {
        if position != 0 {
            out.push(',');
        }
        additional_json_quote(&mut out, name);
        out.push(':');
        if position == 1 {
            additional_float_bits(&mut out, milliseconds);
        } else {
            write!(out, "{}", persistence[position]).ok()?;
        }
    }
    out.push_str("},\"artifactYieldPercent\":");
    additional_float_bits(
        &mut out,
        if successful == 0 {
            0.0
        } else {
            (artifacts as f64 / successful as f64) * 100.0
        },
    );
    out.push('}');
    Some(out)
}

/// JSON helpers return bytes written, `-1` on invalid input, or `-required`
/// (at most `-2`) when the output buffer is too small; they never partially write.
///
/// # Safety
/// Non-null input/output pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_additional_compressed_files_are_media_json(
    ptr: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    unsafe { additional_json_ffi(ptr, len, out, cap, additional_compressed_media) }
}

/// # Safety
/// Non-null input/output pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_additional_id_order_expression_json(
    ptr: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    unsafe { additional_json_ffi(ptr, len, out, cap, additional_id_order) }
}

/// Float outputs are signed-i64 decimal strings containing IEEE-754 bits.
///
/// # Safety
/// Non-null input/output pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_additional_batch_result_metrics_json(
    ptr: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    unsafe { additional_json_ffi(ptr, len, out, cap, additional_batch_metrics) }
}

/// # Safety
/// Non-null input/output pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_additional_claim_index_matches_json(
    ptr: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    unsafe { additional_json_ffi(ptr, len, out, cap, additional_claim_index) }
}
// ---------------------------------------------------------------------------
// Wave 15 Slice 4: collection batch state, header storage helpers, missed-part
// ranges, additional work plans, media source hints, console status labels and
// PAR2 rename progress payloads. Every export returns -1 (or a defer status) so
// the PHP caller falls back to its native implementation.
// ---------------------------------------------------------------------------

#[inline]
fn w15_input<'a>(ptr: *const u8, len: usize) -> Option<&'a [u8]> {
    if !additional_pointer_valid(ptr, len) {
        return None;
    }
    if len == 0 {
        return Some(&[]);
    }
    // SAFETY: the pointer is non-null, the range does not wrap, and the caller
    // promises `len` readable bytes.
    Some(unsafe { slice::from_raw_parts(ptr, len) })
}

#[inline]
fn w15_write(out: *mut u8, cap: usize, bytes: &[u8]) -> isize {
    if !additional_pointer_valid(out.cast_const(), cap) || bytes.len() > isize::MAX as usize {
        return -1;
    }
    if bytes.len() > cap {
        return -(bytes.len().max(2) as isize);
    }
    if !bytes.is_empty() {
        // SAFETY: `out` was validated for `cap >= bytes.len()` writable bytes.
        unsafe { std::ptr::copy_nonoverlapping(bytes.as_ptr(), out, bytes.len()) };
    }
    bytes.len() as isize
}

#[inline]
fn w15_read_i64(chunk: &[u8]) -> i64 {
    let mut raw = [0u8; 8];
    raw.copy_from_slice(chunk);
    i64::from_le_bytes(raw)
}

pub(crate) fn w15_unique_i64(packed: &[u8]) -> Option<Vec<u8>> {
    if !packed.len().is_multiple_of(8) {
        return None;
    }
    let mut seen = std::collections::HashSet::with_capacity(packed.len() / 8);
    let mut out = Vec::with_capacity(packed.len());
    for chunk in packed.as_chunks::<8>().0 {
        if seen.insert(i64::from_le_bytes(*chunk)) {
            out.extend_from_slice(chunk);
        }
    }
    Some(out)
}

pub(crate) fn w15_unique_records(packed: &[u8], width: usize) -> Option<Vec<u8>> {
    if width == 0 || !packed.len().is_multiple_of(width) {
        return None;
    }
    let mut seen: std::collections::HashSet<&[u8]> =
        std::collections::HashSet::with_capacity(packed.len() / width);
    let mut out = Vec::with_capacity(packed.len());
    for chunk in packed.chunks_exact(width) {
        if seen.insert(chunk) {
            out.extend_from_slice(chunk);
        }
    }
    Some(out)
}

/// `CollectionHandler::getAllIds`: order-preserving unique of packed little-endian i64 ids.
///
/// # Safety
/// Non-null input/output pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_collection_unique_ids(
    ids: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(input) = w15_input(ids, len) else {
            return -1;
        };
        match w15_unique_i64(input) {
            Some(result) => w15_write(out, cap, &result),
            None => -1,
        }
    })
    .unwrap_or(-1)
}

/// `CollectionHandler::getBatchHashes`: order-preserving unique of fixed-width records.
///
/// # Safety
/// Non-null input/output pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_collection_unique_hashes(
    packed: *const u8,
    len: usize,
    width: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(input) = w15_input(packed, len) else {
            return -1;
        };
        match w15_unique_records(input, width) {
            Some(result) => w15_write(out, cap, &result),
            None => -1,
        }
    })
    .unwrap_or(-1)
}

pub(crate) fn w15_hex_lower(bytes: &[u8]) -> Vec<u8> {
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let mut out = Vec::with_capacity(bytes.len() * 2);
    for &b in bytes {
        out.push(HEX[(b >> 4) as usize]);
        out.push(HEX[(b & 0x0F) as usize]);
    }
    out
}

/// `HeaderStorageTransaction` batch noise marker: `bin2hex($bytes)`.
///
/// # Safety
/// Non-null input/output pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_header_batch_noise_hex(
    bytes: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(input) = w15_input(bytes, len) else {
            return -1;
        };
        if input.len() > (isize::MAX as usize) / 2 {
            return -1;
        }
        w15_write(out, cap, &w15_hex_lower(input))
    })
    .unwrap_or(-1)
}

pub(crate) fn w15_cleanup_strategy(inserted: usize, all: usize, hashes: usize) -> i32 {
    if inserted > 0 {
        0
    } else if all > 0 {
        1
    } else if hashes > 0 {
        2
    } else {
        3
    }
}

/// `HeaderStorageTransaction` rollback cleanup selector:
/// 0 = inserted ids, 1 = all batch ids, 2 = collection hashes, 3 = noise marker.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_header_cleanup_strategy(
    inserted: usize,
    all: usize,
    hashes: usize,
) -> i32 {
    std::panic::catch_unwind(|| w15_cleanup_strategy(inserted, all, hashes)).unwrap_or(-1)
}

pub(crate) fn w15_range_count(first: i64, last: i64) -> u128 {
    if last < first {
        0
    } else {
        (i128::from(last) - i128::from(first) + 1) as u128
    }
}

pub(crate) fn w15_range_expand(first: i64, last: i64, max_items: usize) -> Option<Vec<u8>> {
    let count = w15_range_count(first, last);
    if count > max_items as u128 || count > (isize::MAX as u128) / 8 {
        return None;
    }
    let mut out = Vec::with_capacity(count as usize * 8);
    let mut number = first;
    for step in 0..count as usize {
        out.extend_from_slice(&number.to_le_bytes());
        if step + 1 < count as usize {
            number += 1;
        }
    }
    Some(out)
}

pub(crate) fn w15_range_chunks(
    first: i64,
    last: i64,
    chunk: i64,
    max_chunks: usize,
) -> Option<Vec<u8>> {
    if chunk < 1 {
        return None;
    }
    let count = w15_range_count(first, last);
    if count == 0 {
        return Some(Vec::new());
    }
    let chunks = count.div_ceil(chunk as u128);
    if chunks > max_chunks as u128 || chunks > (isize::MAX as u128) / 16 {
        return None;
    }
    let mut out = Vec::with_capacity(chunks as usize * 16);
    let mut start = i128::from(first);
    let last_wide = i128::from(last);
    loop {
        let end = (start + i128::from(chunk) - 1).min(last_wide);
        out.extend_from_slice(&(start as i64).to_le_bytes());
        out.extend_from_slice(&(end as i64).to_le_bytes());
        if end == last_wide {
            break;
        }
        start = end + 1;
    }
    Some(out)
}

/// `MissedPartHandler::addMissingRange` expansion: inclusive range as packed i64, or -1
/// when the range exceeds `max_items`.
///
/// # Safety
/// A non-null `out` must be writable for `cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_missed_range_expand(
    first: i64,
    last: i64,
    max_items: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| match w15_range_expand(first, last, max_items) {
        Some(result) => w15_write(out, cap, &result),
        None => -1,
    })
    .unwrap_or(-1)
}

/// `MissedPartHandler::deferRange` statement bounds: packed i64 `[start, end]` pairs.
///
/// # Safety
/// A non-null `out` must be writable for `cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_missed_range_chunks(
    first: i64,
    last: i64,
    chunk: i64,
    max_chunks: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| match w15_range_chunks(first, last, chunk, max_chunks) {
        Some(result) => w15_write(out, cap, &result),
        None => -1,
    })
    .unwrap_or(-1)
}

pub(crate) fn w15_set_key(key: &[u8], source_index: i64) -> Vec<u8> {
    if !key.is_empty() {
        return key.to_vec();
    }
    let mut out = b"src:".to_vec();
    out.extend_from_slice(source_index.to_string().as_bytes());
    out
}

pub(crate) fn w15_archive_set_count(
    keys: &[u8],
    lens: &[u8],
    indexes: &[u8],
    count: usize,
) -> Option<usize> {
    if lens.len() != count.checked_mul(4)? || indexes.len() != count.checked_mul(8)? {
        return None;
    }
    let mut seen: std::collections::HashSet<std::borrow::Cow<'_, [u8]>> =
        std::collections::HashSet::with_capacity(count);
    let mut offset = 0usize;
    for i in 0..count {
        let mut raw = [0u8; 4];
        raw.copy_from_slice(&lens[i * 4..i * 4 + 4]);
        let len = u32::from_le_bytes(raw) as usize;
        let end = offset.checked_add(len)?;
        if end > keys.len() {
            return None;
        }
        let key = &keys[offset..end];
        offset = end;
        if key.is_empty() {
            let index = w15_read_i64(&indexes[i * 8..i * 8 + 8]);
            seen.insert(std::borrow::Cow::Owned(w15_set_key(key, index)));
        } else {
            seen.insert(std::borrow::Cow::Borrowed(key));
        }
    }
    if offset != keys.len() {
        return None;
    }
    Some(seen.len())
}

/// `AdditionalWorkPlan::setKeyOf`.
///
/// # Safety
/// Non-null input/output pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_workplan_set_key(
    key: *const u8,
    key_len: usize,
    source_index: i64,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(input) = w15_input(key, key_len) else {
            return -1;
        };
        w15_write(out, cap, &w15_set_key(input, source_index))
    })
    .unwrap_or(-1)
}

/// `AdditionalWorkPlan::archiveSetCount`: `keys` is every candidate's `setKey` concatenated,
/// `lens` their u32 LE lengths and `indexes` their i64 LE source indexes.
///
/// # Safety
/// Non-null input pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_workplan_archive_set_count(
    keys: *const u8,
    keys_len: usize,
    lens: *const u8,
    lens_len: usize,
    indexes: *const u8,
    indexes_len: usize,
    count: usize,
) -> i64 {
    std::panic::catch_unwind(|| {
        let (Some(keys), Some(lens), Some(indexes)) = (
            w15_input(keys, keys_len),
            w15_input(lens, lens_len),
            w15_input(indexes, indexes_len),
        ) else {
            return -1;
        };
        w15_archive_set_count(keys, lens, indexes, count).map_or(-1, |n| n as i64)
    })
    .unwrap_or(-1)
}

pub(crate) fn w15_candidate_order(flags: &[u8], mode: u32) -> Option<Vec<u8>> {
    if flags.len() > u32::MAX as usize {
        return None;
    }
    let mut out = Vec::with_capacity(flags.len() * 4);
    match mode {
        0 => (0..flags.len()).for_each(|i| out.extend_from_slice(&(i as u32).to_le_bytes())),
        1 => (0..flags.len())
            .rev()
            .for_each(|i| out.extend_from_slice(&(i as u32).to_le_bytes())),
        2 => {
            for wanted in [true, false] {
                for (i, &flag) in flags.iter().enumerate() {
                    if (flag != 0) == wanted {
                        out.extend_from_slice(&(i as u32).to_le_bytes());
                    }
                }
            }
        }
        _ => return None,
    }
    Some(out)
}

/// `AdditionalWorkPlan` candidate ordering as u32 LE indexes. `flags[i] != 0` marks a
/// likely first volume. Mode 0 = posted order, 1 = reversed, 2 = first volumes first (stable).
///
/// # Safety
/// Non-null input/output pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_workplan_candidate_order(
    flags: *const u8,
    count: usize,
    mode: u32,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(input) = w15_input(flags, count) else {
            return -1;
        };
        match w15_candidate_order(input, mode) {
            Some(result) => w15_write(out, cap, &result),
            None => -1,
        }
    })
    .unwrap_or(-1)
}

/// PHP `$a / $b` for integers: exact quotients stay integral before widening.
fn w15_php_int_div(a: i64, b: i64) -> Option<f64> {
    if b == 0 {
        return None;
    }
    if b == -1 && a == i64::MIN {
        return Some(a as f64 / b as f64);
    }
    if a % b == 0 {
        Some((a / b) as f64)
    } else {
        Some(a as f64 / b as f64)
    }
}

/// Returns `(status, value)`: status 0 = null, 1 = value, -1 = defer to PHP.
pub(crate) fn w15_available_fraction(
    position: u32,
    has_real: bool,
    real: i64,
    available: i64,
) -> (i32, f64) {
    match position {
        0 => return (1, 1.0),
        1 | 2 => {}
        _ => return (-1, 0.0),
    }
    if !has_real || available <= 0 {
        return (0, 0.0);
    }
    match w15_php_int_div(available, real) {
        Some(fraction) => (1, if fraction < 1.0 { fraction } else { 1.0 }),
        None => (-1, 0.0),
    }
}

/// `MediaSourceHints::availableFraction`. Position 0 = complete, 1 = head, 2 = mid.
/// `*status` receives 0 for null, 1 for a value and -1 when PHP must evaluate it.
///
/// # Safety
/// `status` must be null or writable for one `int32_t`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_media_available_fraction(
    position: u32,
    has_real: u8,
    real: i64,
    available: i64,
    status: *mut i32,
) -> f64 {
    if status.is_null() {
        return f64::NAN;
    }
    let (code, value) = std::panic::catch_unwind(|| {
        w15_available_fraction(position, has_real != 0, real, available)
    })
    .unwrap_or((-1, 0.0));
    // SAFETY: `status` is non-null and the caller promises it is writable.
    unsafe { status.write_unaligned(code) };
    value
}

/// Returns 0 = complete (real size kept), 1 = head with real size, 2 = head without one.
pub(crate) fn w15_head_hint(has_real: bool, real: i64, available: i64) -> i32 {
    if has_real && real > 0 && available >= real {
        0
    } else if has_real && real > 0 {
        1
    } else {
        2
    }
}

/// `MediaSourceHints::head` evaluation; -1 for an invalid output pointer.
///
/// # Safety
/// `real_out` must be null or writable for one `int64_t`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_media_head_hint(
    has_real: u8,
    real: i64,
    available: i64,
    real_out: *mut i64,
) -> i32 {
    if real_out.is_null() {
        return -1;
    }
    let code =
        std::panic::catch_unwind(|| w15_head_hint(has_real != 0, real, available)).unwrap_or(-1);
    if code >= 0 {
        // SAFETY: `real_out` is non-null and the caller promises it is writable.
        unsafe { real_out.write_unaligned(if code == 2 { 0 } else { real }) };
    }
    code
}

/// `human_filesize($bytes, 1)` for integer input.
pub(crate) fn w15_human_filesize_1(bytes: i64) -> String {
    const UNITS: [&str; 9] = ["B", "kB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"];
    let digits = bytes.to_string();
    let factor = (digits.len() - 1) / 3;
    let divisor = 1024i64.pow(factor as u32);
    let value = w15_php_int_div(bytes, divisor).unwrap_or(0.0);
    let fixed = format!("{value:.1}");
    let parsed: f64 = fixed.parse().unwrap_or(value);
    format!("{parsed}{}", UNITS.get(factor).copied().unwrap_or(""))
}

pub(crate) fn w15_console_status(kind: u32, a: i64, b: i64, text: &[u8]) -> Option<Vec<u8>> {
    let mut out = Vec::with_capacity(48 + text.len());
    match kind {
        0 => {
            out.extend_from_slice(b"\n[");
            out.extend_from_slice(a.to_string().as_bytes());
            out.extend_from_slice(b"][");
            out.extend_from_slice(w15_human_filesize_1(b).as_bytes());
            out.push(b']');
        }
        1 => {
            out.extend_from_slice(b"fC");
            out.extend_from_slice(a.to_string().as_bytes());
        }
        2 => {
            out.extend_from_slice(b"(p2n:");
            out.extend_from_slice(a.to_string().as_bytes());
            out.push(b')');
        }
        3 => {
            out.extend_from_slice(b"(src:");
            out.extend_from_slice(text);
            out.push(b')');
        }
        4 => {
            out.extend_from_slice(b" Release ");
            out.extend_from_slice(a.to_string().as_bytes());
            out.extend_from_slice(b" timed out after ");
            out.extend_from_slice(b.to_string().as_bytes());
            out.push(b's');
        }
        5 => {
            out.extend_from_slice(b" Release ");
            out.extend_from_slice(a.to_string().as_bytes());
            out.extend_from_slice(b" deleted after ");
            out.extend_from_slice(b.to_string().as_bytes());
            out.extend_from_slice(b" timeout(s)");
        }
        6 => {
            out.extend_from_slice(b"Deleted broken release ID ");
            out.extend_from_slice(a.to_string().as_bytes());
        }
        _ => return None,
    }
    Some(out)
}

/// `ConsoleOutputService` status labels. Kinds: 0 release start, 1 compressed failure,
/// 2 PAR2 mapped, 3 media source, 4 timeout, 5 timeout deletion, 6 broken release deletion.
///
/// # Safety
/// Non-null input/output pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_console_status_format(
    kind: u32,
    a: i64,
    b: i64,
    text: *const u8,
    text_len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(input) = w15_input(text, text_len) else {
            return -1;
        };
        match w15_console_status(kind, a, b, input) {
            Some(result) => w15_write(out, cap, &result),
            None => -1,
        }
    })
    .unwrap_or(-1)
}

#[inline]
fn w15_json_ws(input: &[u8], mut pos: usize) -> usize {
    while pos < input.len() && matches!(input[pos], b' ' | b'\t' | b'\n' | b'\r') {
        pos += 1;
    }
    pos
}

/// Strict JSON integer at `pos`; `None` for floats, overflow or malformed numbers.
fn w15_json_int(input: &[u8], pos: usize) -> Option<(i64, usize)> {
    let mut i = pos;
    let negative = input.get(i) == Some(&b'-');
    if negative {
        i += 1;
    }
    let digits_start = i;
    match input.get(i) {
        Some(b'0') => i += 1,
        Some(b'1'..=b'9') => {
            while matches!(input.get(i), Some(b'0'..=b'9')) {
                i += 1;
            }
        }
        _ => return None,
    }
    if matches!(input.get(i), Some(b'.' | b'e' | b'E' | b'0'..=b'9')) {
        return None;
    }
    let mut value: i64 = 0;
    for &digit in &input[digits_start..i] {
        let d = i64::from(digit - b'0');
        value = if negative {
            value.checked_mul(10)?.checked_sub(d)?
        } else {
            value.checked_mul(10)?.checked_add(d)?
        };
    }
    Some((value, i))
}

/// `Par2RenameProgress` retry payload: the integers `json_decode` keeps after the
/// `is_int` filter, or `None` when PHP must decide (strings, floats, nesting, errors).
pub(crate) fn w15_normalize_retry(input: &[u8], max_items: usize) -> Option<Vec<u8>> {
    let mut pos = w15_json_ws(input, 0);
    let mut out = Vec::new();
    match input.get(pos) {
        Some(b'[') => {
            pos = w15_json_ws(input, pos + 1);
            if input.get(pos) == Some(&b']') {
                pos += 1;
            } else {
                loop {
                    let (value, next) = w15_json_int(input, pos)?;
                    if out.len() / 8 >= max_items {
                        return None;
                    }
                    out.extend_from_slice(&value.to_le_bytes());
                    pos = w15_json_ws(input, next);
                    match input.get(pos) {
                        Some(b',') => pos = w15_json_ws(input, pos + 1),
                        Some(b']') => {
                            pos += 1;
                            break;
                        }
                        _ => return None,
                    }
                }
            }
        }
        Some(b'n') if input[pos..].starts_with(b"null") => pos += 4,
        Some(b't') if input[pos..].starts_with(b"true") => pos += 4,
        Some(b'f') if input[pos..].starts_with(b"false") => pos += 5,
        Some(b'-' | b'0'..=b'9') => {
            let (_, next) = w15_json_int(input, pos)?;
            pos = next;
        }
        _ => return None,
    }
    if w15_json_ws(input, pos) != input.len() {
        return None;
    }
    Some(out)
}

/// `Par2RenameProgress::get` retry normalization into packed i64, or -1 to defer to PHP.
///
/// # Safety
/// Non-null input/output pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_progress_normalize_retry(
    json: *const u8,
    len: usize,
    max_items: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(input) = w15_input(json, len) else {
            return -1;
        };
        match w15_normalize_retry(input, max_items) {
            Some(result) => w15_write(out, cap, &result),
            None => -1,
        }
    })
    .unwrap_or(-1)
}

pub(crate) fn w15_encode_retry(packed: &[u8]) -> Option<Vec<u8>> {
    if !packed.len().is_multiple_of(8) {
        return None;
    }
    let mut out = Vec::with_capacity(2 + packed.len() / 8 * 8);
    out.push(b'[');
    for (i, chunk) in packed.as_chunks::<8>().0.iter().enumerate() {
        if i > 0 {
            out.push(b',');
        }
        out.extend_from_slice(i64::from_le_bytes(*chunk).to_string().as_bytes());
    }
    out.push(b']');
    Some(out)
}

/// `Par2RenameProgress::save` retry payload: `json_encode` of packed i64 values.
///
/// # Safety
/// Non-null input/output pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_progress_encode_retry(
    packed: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(input) = w15_input(packed, len) else {
            return -1;
        };
        match w15_encode_retry(input) {
            Some(result) => w15_write(out, cap, &result),
            None => -1,
        }
    })
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave15_binaries_collection_workplan_tests {
    use super::*;

    fn pack(values: &[i64]) -> Vec<u8> {
        values.iter().flat_map(|v| v.to_le_bytes()).collect()
    }

    fn unpack(bytes: &[u8]) -> Vec<i64> {
        bytes
            .as_chunks::<8>()
            .0
            .iter()
            .map(|c| i64::from_le_bytes(*c))
            .collect()
    }

    #[test]
    fn unique_ids_preserve_first_occurrence() {
        let input = pack(&[5, 3, 5, -1, 3, 9]);
        let mut out = [0u8; 64];
        let written = unsafe {
            nntmux_collection_unique_ids(input.as_ptr(), input.len(), out.as_mut_ptr(), out.len())
        };
        assert_eq!(unpack(&out[..written as usize]), vec![5, 3, -1, 9]);
        assert_eq!(
            unsafe { nntmux_collection_unique_ids(input.as_ptr(), 7, out.as_mut_ptr(), out.len()) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_collection_unique_ids(std::ptr::null(), 8, out.as_mut_ptr(), 8) },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_collection_unique_ids(input.as_ptr(), input.len(), out.as_mut_ptr(), 8)
            },
            -32
        );
        assert_eq!(
            unsafe { nntmux_collection_unique_ids(std::ptr::null(), 0, std::ptr::null_mut(), 0) },
            0
        );
    }

    #[test]
    fn unique_hashes_use_fixed_width_records() {
        let input = b"aabbaaccbb";
        let mut out = [0u8; 16];
        let written = unsafe {
            nntmux_collection_unique_hashes(
                input.as_ptr(),
                input.len(),
                2,
                out.as_mut_ptr(),
                out.len(),
            )
        };
        assert_eq!(&out[..written as usize], b"aabbcc");
        assert_eq!(
            unsafe {
                nntmux_collection_unique_hashes(
                    input.as_ptr(),
                    input.len(),
                    0,
                    out.as_mut_ptr(),
                    16,
                )
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_collection_unique_hashes(
                    input.as_ptr(),
                    input.len(),
                    3,
                    out.as_mut_ptr(),
                    16,
                )
            },
            -1
        );
    }

    #[test]
    fn header_noise_and_cleanup_strategy() {
        let mut out = [0u8; 16];
        let bytes = [0x00u8, 0xAB, 0x7F, 0xFF];
        let written = unsafe {
            nntmux_header_batch_noise_hex(bytes.as_ptr(), bytes.len(), out.as_mut_ptr(), out.len())
        };
        assert_eq!(&out[..written as usize], b"00ab7fff");
        assert_eq!(
            unsafe {
                nntmux_header_batch_noise_hex(bytes.as_ptr(), bytes.len(), out.as_mut_ptr(), 3)
            },
            -8
        );
        assert_eq!(nntmux_header_cleanup_strategy(1, 0, 0), 0);
        assert_eq!(nntmux_header_cleanup_strategy(0, 4, 9), 1);
        assert_eq!(nntmux_header_cleanup_strategy(0, 0, 9), 2);
        assert_eq!(nntmux_header_cleanup_strategy(0, 0, 0), 3);
    }

    #[test]
    fn range_expand_and_chunks_cover_bounds() {
        assert_eq!(
            unpack(&w15_range_expand(3, 6, 10).unwrap()),
            vec![3, 4, 5, 6]
        );
        assert!(w15_range_expand(6, 3, 10).unwrap().is_empty());
        assert!(w15_range_expand(0, 10, 10).is_none());
        assert_eq!(
            unpack(&w15_range_expand(i64::MAX - 1, i64::MAX, 4).unwrap()),
            vec![i64::MAX - 1, i64::MAX]
        );
        assert!(w15_range_expand(i64::MIN, i64::MAX, usize::MAX).is_none());
        assert_eq!(
            unpack(&w15_range_chunks(1001, 1052, 50, 10).unwrap()),
            vec![1001, 1050, 1051, 1052]
        );
        assert_eq!(
            unpack(&w15_range_chunks(1, 100, 50, 10).unwrap()),
            vec![1, 50, 51, 100]
        );
        assert_eq!(
            unpack(&w15_range_chunks(i64::MAX - 2, i64::MAX, 2, 10).unwrap()),
            vec![i64::MAX - 2, i64::MAX - 1, i64::MAX, i64::MAX]
        );
        assert!(w15_range_chunks(1, 0, 50, 10).unwrap().is_empty());
        assert!(w15_range_chunks(1, 10, 0, 10).is_none());
        assert!(w15_range_chunks(1, 1000, 1, 10).is_none());
        let mut out = [0u8; 8];
        assert_eq!(
            unsafe { nntmux_missed_range_expand(1, 4, 100, out.as_mut_ptr(), out.len()) },
            -32
        );
        assert_eq!(
            unsafe { nntmux_missed_range_chunks(1, 4, 50, 10, std::ptr::null_mut(), 16) },
            -1
        );
    }

    #[test]
    fn work_plan_keys_counts_and_order() {
        assert_eq!(w15_set_key(b"", -4), b"src:-4");
        assert_eq!(w15_set_key(b"rar:movie", 9), b"rar:movie");
        let keys = b"rar:asrc:1";
        let lens: Vec<u8> = [5u32, 0, 5, 0]
            .iter()
            .flat_map(|v| v.to_le_bytes())
            .collect();
        let indexes = pack(&[0, 1, 2, 7]);
        assert_eq!(w15_archive_set_count(keys, &lens, &indexes, 4), Some(3));
        assert_eq!(w15_archive_set_count(keys, &lens, &indexes, 3), None);
        assert_eq!(w15_archive_set_count(b"rar:", &lens, &indexes, 4), None);
        let order = |flags: &[u8], mode| -> Vec<u32> {
            w15_candidate_order(flags, mode)
                .unwrap()
                .as_chunks::<4>()
                .0
                .iter()
                .map(|c| u32::from_le_bytes(*c))
                .collect()
        };
        assert_eq!(order(&[0, 1, 0, 1], 0), vec![0, 1, 2, 3]);
        assert_eq!(order(&[0, 1, 0, 1], 1), vec![3, 2, 1, 0]);
        assert_eq!(order(&[0, 1, 0, 1], 2), vec![1, 3, 0, 2]);
        assert!(w15_candidate_order(&[0], 3).is_none());
        assert_eq!(
            unsafe {
                nntmux_workplan_archive_set_count(
                    std::ptr::null(),
                    4,
                    lens.as_ptr(),
                    16,
                    indexes.as_ptr(),
                    32,
                    4,
                )
            },
            -1
        );
    }

    #[test]
    fn media_hints_follow_php_arithmetic() {
        assert_eq!(w15_available_fraction(0, false, 0, 0), (1, 1.0));
        assert_eq!(w15_available_fraction(1, false, 0, 10).0, 0);
        assert_eq!(w15_available_fraction(1, true, 10, 0).0, 0);
        assert_eq!(w15_available_fraction(1, true, 10, 5), (1, 0.5));
        assert_eq!(w15_available_fraction(2, true, 10, 50), (1, 1.0));
        assert_eq!(w15_available_fraction(1, true, -4, 2), (1, -0.5));
        assert_eq!(w15_available_fraction(1, true, 0, 5).0, -1);
        assert_eq!(w15_available_fraction(9, true, 1, 5).0, -1);
        let mut status = 7i32;
        let value = unsafe { nntmux_media_available_fraction(1, 1, 4, 1, &mut status) };
        assert_eq!((status, value), (1, 0.25));
        assert!(
            unsafe { nntmux_media_available_fraction(1, 1, 4, 1, std::ptr::null_mut()) }.is_nan()
        );
        let mut real = 0i64;
        assert_eq!(unsafe { nntmux_media_head_hint(1, 100, 100, &mut real) }, 0);
        assert_eq!(real, 100);
        assert_eq!(unsafe { nntmux_media_head_hint(1, 100, 99, &mut real) }, 1);
        assert_eq!(unsafe { nntmux_media_head_hint(1, 0, 99, &mut real) }, 2);
        assert_eq!(
            unsafe { nntmux_media_head_hint(0, 0, 0, std::ptr::null_mut()) },
            -1
        );
    }

    #[test]
    fn console_labels_match_php_strings() {
        assert_eq!(w15_human_filesize_1(0), "0B");
        assert_eq!(w15_human_filesize_1(999), "999B");
        assert_eq!(w15_human_filesize_1(1000), "1kB");
        assert_eq!(w15_human_filesize_1(1536), "1.5kB");
        assert_eq!(w15_human_filesize_1(734_003_200), "700MB");
        assert_eq!(w15_human_filesize_1(-1000), "-1kB");
        assert_eq!(
            w15_console_status(0, 42, 1536, b"").unwrap(),
            b"\n[42][1.5kB]"
        );
        assert_eq!(
            w15_console_status(4, 7, 30, b"").unwrap(),
            b" Release 7 timed out after 30s"
        );
        assert_eq!(w15_console_status(3, 0, 0, b"head").unwrap(), b"(src:head)");
        assert!(w15_console_status(99, 0, 0, b"").is_none());
    }

    #[test]
    fn retry_payloads_round_trip_and_defer() {
        assert_eq!(
            unpack(&w15_normalize_retry(b" [1, -0 ,30]\n", 10).unwrap()),
            vec![1, 0, 30]
        );
        assert!(w15_normalize_retry(b"[]", 10).unwrap().is_empty());
        assert!(w15_normalize_retry(b"null", 10).unwrap().is_empty());
        assert!(w15_normalize_retry(b"5", 10).unwrap().is_empty());
        for deferred in [
            &b"[1.0]"[..],
            b"[\"1\"]",
            b"[01]",
            b"[1,]",
            b"[[1]]",
            b"",
            b"[1",
            b"{\"a\":1}",
            b"[99999999999999999999]",
            b"[1] x",
        ] {
            assert!(
                w15_normalize_retry(deferred, 10).is_none(),
                "{:?}",
                std::str::from_utf8(deferred)
            );
        }
        assert!(w15_normalize_retry(b"[1,2,3]", 2).is_none());
        assert_eq!(w15_encode_retry(&pack(&[])).unwrap(), b"[]");
        assert_eq!(
            w15_encode_retry(&pack(&[3, -1, i64::MIN])).unwrap(),
            format!("[3,-1,{}]", i64::MIN).as_bytes()
        );
        assert!(w15_encode_retry(b"abc").is_none());
    }
}

// ---------------------------------------------------------------------------
// Wave 16: orchestrator timing, media byte budget, processing metrics,
// media article fetcher and downloaded archive helpers
// ---------------------------------------------------------------------------

/// `(end - start) / 1_000_000_000` with PHP int division semantics; `None` on overflow.
pub(crate) fn w16_elapsed_seconds(start_ns: i64, end_ns: i64) -> Option<f64> {
    w15_php_int_div(end_ns.checked_sub(start_ns)?, 1_000_000_000)
}

/// `AdditionalProcessingOrchestrator::elapsedSecondsBetween`. `*status` receives 1 for a
/// value and -1 when PHP must evaluate it (overflowing subtraction).
///
/// # Safety
/// `status` must be null or writable for one `int32_t`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_aporch_elapsed_seconds(
    start_ns: i64,
    end_ns: i64,
    status: *mut i32,
) -> f64 {
    if status.is_null() {
        return f64::NAN;
    }
    let result = std::panic::catch_unwind(|| w16_elapsed_seconds(start_ns, end_ns)).unwrap_or(None);
    // SAFETY: `status` is non-null and the caller promises it is writable.
    unsafe { status.write_unaligned(if result.is_some() { 1 } else { -1 }) };
    result.unwrap_or(0.0)
}

/// `AdditionalProcessingOrchestrator::effectiveQueryLimit`.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_aporch_query_limit(limit: i64) -> i64 {
    if limit > 0 { limit } else { 25 }
}

/// `AdditionalProcessingOrchestrator::batchDisposition`: 0 setup failed, 1 empty, 2 process.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_aporch_batch_disposition(setup_ok: u8, total_releases: i64) -> i32 {
    if setup_ok == 0 {
        0
    } else if total_releases > 0 {
        2
    } else {
        1
    }
}

/// `AdditionalProcessingOrchestrator::shouldClearClaim`.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_aporch_should_clear_claim(token_len: usize, release_id: i64) -> i32 {
    i32::from(token_len > 0 && release_id > 0)
}

pub(crate) const W16_BUDGET_ALLOWS: u32 = 0;
pub(crate) const W16_BUDGET_SPEND: u32 = 1;
pub(crate) const W16_BUDGET_REMAINING: u32 = 2;
pub(crate) const W16_BUDGET_EXHAUSTED: u32 = 3;
pub(crate) const W16_BUDGET_CLAMP: u32 = 4;

/// `max(0, limit - spent)`; `None` when PHP would produce a float.
#[inline]
fn w16_budget_remaining(limit: i64, spent: i64) -> Option<i64> {
    let remaining = (i128::from(limit) - i128::from(spent)).max(0);
    i64::try_from(remaining).ok()
}

/// `MediaByteBudget` operations. `Ok(None)` defers to PHP (integer overflow), `Err` is an
/// unknown operation.
pub(crate) fn w16_budget_eval(
    op: u32,
    limit: i64,
    spent: i64,
    bytes: i64,
) -> Result<Option<i64>, ()> {
    Ok(match op {
        W16_BUDGET_ALLOWS => spent
            .checked_add(bytes.max(0))
            .map(|total| i64::from(total <= limit)),
        W16_BUDGET_SPEND => spent.checked_add(bytes.max(0)),
        W16_BUDGET_REMAINING => w16_budget_remaining(limit, spent),
        W16_BUDGET_EXHAUSTED => Some(i64::from(spent >= limit)),
        W16_BUDGET_CLAMP => {
            w16_budget_remaining(limit, spent).map(|remaining| bytes.min(remaining))
        }
        _ => return Err(()),
    })
}

/// `MediaByteBudget` operations (0 allows, 1 spend, 2 remaining, 3 exhausted, 4 clamp a
/// request). Returns 1 with `*out` set, 0 when PHP must evaluate it and -1 for invalid input.
///
/// # Safety
/// `out` must be null or writable for one `int64_t`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_budget_eval(
    op: u32,
    limit: i64,
    spent: i64,
    bytes: i64,
    out: *mut i64,
) -> i32 {
    if out.is_null() {
        return -1;
    }
    match std::panic::catch_unwind(|| w16_budget_eval(op, limit, spent, bytes)) {
        Ok(Ok(Some(value))) => {
            // SAFETY: `out` is non-null and the caller promises it is writable.
            unsafe { out.write_unaligned(value) };
            1
        }
        Ok(Ok(None)) => 0,
        _ => -1,
    }
}

/// `MediaByteBudget::utilization`: 1.0 for a non-positive limit, else `spent / limit` capped at 1.0.
pub(crate) fn w16_budget_utilization(limit: i64, spent: i64) -> Option<f64> {
    if limit <= 0 {
        return Some(1.0);
    }
    let ratio = w15_php_int_div(spent, limit)?;
    Some(if ratio >= 1.0 { 1.0 } else { ratio })
}

/// `MediaByteBudget::utilization`; `*status` receives 1 for a value and -1 otherwise.
///
/// # Safety
/// `status` must be null or writable for one `int32_t`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_budget_utilization(
    limit: i64,
    spent: i64,
    status: *mut i32,
) -> f64 {
    if status.is_null() {
        return f64::NAN;
    }
    let result = std::panic::catch_unwind(|| w16_budget_utilization(limit, spent)).unwrap_or(None);
    // SAFETY: `status` is non-null and the caller promises it is writable.
    unsafe { status.write_unaligned(if result.is_some() { 1 } else { -1 }) };
    result.unwrap_or(0.0)
}

/// Packed little-endian `int64` nanoseconds to packed little-endian `double` seconds.
pub(crate) fn w16_ns_to_seconds(packed: &[u8]) -> Option<Vec<u8>> {
    if !packed.len().is_multiple_of(8) {
        return None;
    }
    let mut out = Vec::with_capacity(packed.len());
    for chunk in packed.as_chunks::<8>().0 {
        let seconds = w15_php_int_div(i64::from_le_bytes(*chunk), 1_000_000_000)?;
        out.extend_from_slice(&seconds.to_le_bytes());
    }
    Some(out)
}

/// `ProcessingMetrics::secondsFromNanoseconds`. Returns bytes written, `-needed` for a short
/// buffer or -1 for invalid input.
///
/// # Safety
/// Non-null input/output pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_pmetrics_ns_to_seconds(
    packed: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(input) = w15_input(packed, len) else {
            return -1;
        };
        match w16_ns_to_seconds(input) {
            Some(result) => w15_write(out, cap, &result),
            None => -1,
        }
    })
    .unwrap_or(-1)
}

/// `ProcessingMetrics::rate`: `count / seconds` for positive seconds, else 0.0.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_pmetrics_rate(count: i64, seconds: f64) -> f64 {
    if seconds > 0.0 {
        count as f64 / seconds
    } else {
        0.0
    }
}

/// Entries packed as `[u32 LE key length][key][f64 LE seconds]` rendered as
/// `key=%.6Fs` joined by spaces. Non-finite values defer to PHP.
pub(crate) fn w16_format_summary(packed: &[u8]) -> Option<Vec<u8>> {
    use std::io::Write;

    let mut out = Vec::with_capacity(packed.len() + 16);
    let mut rest = packed;
    while !rest.is_empty() {
        let (len_bytes, tail) = rest.split_at_checked(4)?;
        let key_len = u32::from_le_bytes(len_bytes.try_into().ok()?) as usize;
        let (key, tail) = tail.split_at_checked(key_len)?;
        let (value_bytes, tail) = tail.split_at_checked(8)?;
        let seconds = f64::from_le_bytes(value_bytes.try_into().ok()?);
        if !seconds.is_finite() {
            return None;
        }
        // PHP's `%F` prints a sign only for values below zero, never for -0.0.
        let seconds = if seconds == 0.0 { 0.0 } else { seconds };
        if !out.is_empty() {
            out.push(b' ');
        }
        out.extend_from_slice(key);
        out.push(b'=');
        write!(out, "{seconds:.6}").ok()?;
        out.push(b's');
        rest = tail;
    }
    Some(out)
}

/// `ProcessingMetrics::formatStageSummary`. Returns bytes written, `-needed` for a short
/// buffer or -1 for invalid input.
///
/// # Safety
/// Non-null input/output pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_pmetrics_format_summary(
    packed: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(input) = w15_input(packed, len) else {
            return -1;
        };
        match w16_format_summary(input) {
            Some(result) => w15_write(out, cap, &result),
            None => -1,
        }
    })
    .unwrap_or(-1)
}

/// `MediaArticleFetcher::isSafeWitnessName`: a bare, printable UTF-8 file name.
pub(crate) fn w16_safe_witness_name(name: &[u8]) -> bool {
    !name.is_empty()
        && name.len() <= 4096
        && std::str::from_utf8(name).is_ok()
        && !name
            .iter()
            .any(|&b| b < 0x20 || b == 0x7f || b == b'/' || b == b'\\')
        && name[0] != b'.'
        && name[0] != b'-'
}

/// `MediaArticleFetcher::isSafeWitnessName`; -1 for an invalid pointer.
///
/// # Safety
/// A non-null `ptr` must point to at least `len` readable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_fetcher_safe_witness_name(ptr: *const u8, len: usize) -> i32 {
    std::panic::catch_unwind(|| match w15_input(ptr, len) {
        Some(name) => i32::from(w16_safe_witness_name(name)),
        None => -1,
    })
    .unwrap_or(-1)
}

/// Length of `substr($data, 0, $length)` for a `data_len`-byte string; `None` for a
/// negative data length.
pub(crate) fn w16_chunk_length(data_len: i64, length: i64) -> Option<i64> {
    if data_len < 0 {
        return None;
    }
    Some(if length >= 0 {
        length.min(data_len)
    } else {
        (i128::from(data_len) + i128::from(length)).max(0) as i64
    })
}

/// `MediaArticleFetcher::chunkLength`; -1 for invalid input.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_fetcher_chunk_length(data_len: i64, length: i64) -> i64 {
    w16_chunk_length(data_len, length).unwrap_or(-1)
}

pub(crate) const W16_ARCHIVE_IS_DIR: u8 = 1;
pub(crate) const W16_ARCHIVE_PASSWORDED: u8 = 2;

/// `[files, directories, bytes, passworded]`; `Ok(None)` defers an overflowing byte total
/// to PHP and `Err` rejects malformed input.
pub(crate) fn w16_archive_summary(flags: &[u8], sizes: &[u8]) -> Result<Option<[i64; 4]>, ()> {
    if flags.len().checked_mul(8) != Some(sizes.len()) {
        return Err(());
    }
    let (mut files, mut directories, mut bytes, mut passworded) = (0i64, 0i64, 0i64, 0i64);
    for (&flag, chunk) in flags.iter().zip(sizes.as_chunks::<8>().0) {
        if flag & !(W16_ARCHIVE_IS_DIR | W16_ARCHIVE_PASSWORDED) != 0 {
            return Err(());
        }
        if flag & W16_ARCHIVE_IS_DIR != 0 {
            directories += 1;
            continue;
        }
        files += 1;
        let Some(total) = bytes.checked_add(i64::from_le_bytes(*chunk)) else {
            return Ok(None);
        };
        bytes = total;
        if flag & W16_ARCHIVE_PASSWORDED != 0 {
            passworded = 1;
        }
    }
    Ok(Some([files, directories, bytes, passworded]))
}

/// `DownloadedArchive::fileSummary`. `flags` holds one byte per file (bit 0 directory,
/// bit 1 passworded) and `sizes` one little-endian `int64` per file. Returns 1 with
/// `out[0..4]` set, 0 when PHP must evaluate it and -1 for invalid input.
///
/// # Safety
/// Non-null pointers must be valid for their declared lengths; `out` must be writable for
/// `out_len` `int64_t` values.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_dlarchive_summary(
    flags: *const u8,
    count: usize,
    sizes: *const u8,
    sizes_len: usize,
    out: *mut i64,
    out_len: usize,
) -> i32 {
    std::panic::catch_unwind(|| {
        if out.is_null() || out_len < 4 {
            return -1;
        }
        let (Some(flag_bytes), Some(size_bytes)) =
            (w15_input(flags, count), w15_input(sizes, sizes_len))
        else {
            return -1;
        };
        match w16_archive_summary(flag_bytes, size_bytes) {
            Ok(Some(summary)) => {
                for (slot, value) in summary.into_iter().enumerate() {
                    // SAFETY: `out` is non-null and writable for `out_len >= 4` values.
                    unsafe { out.add(slot).write_unaligned(value) };
                }
                1
            }
            Ok(None) => 0,
            Err(()) => -1,
        }
    })
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave16_additional_budget_metrics_tests {
    use super::*;

    fn pack(values: &[i64]) -> Vec<u8> {
        values.iter().flat_map(|v| v.to_le_bytes()).collect()
    }

    fn entry(key: &str, seconds: f64) -> Vec<u8> {
        let mut out = (key.len() as u32).to_le_bytes().to_vec();
        out.extend_from_slice(key.as_bytes());
        out.extend_from_slice(&seconds.to_le_bytes());
        out
    }

    #[test]
    fn orchestrator_helpers() {
        assert_eq!(w16_elapsed_seconds(1_000_000_000, 3_000_000_000), Some(2.0));
        assert_eq!(w16_elapsed_seconds(0, 1_500_000_000), Some(1.5));
        assert_eq!(w16_elapsed_seconds(i64::MIN, i64::MAX), None);
        assert_eq!(nntmux_aporch_query_limit(0), 25);
        assert_eq!(nntmux_aporch_query_limit(-4), 25);
        assert_eq!(nntmux_aporch_query_limit(7), 7);
        assert_eq!(nntmux_aporch_batch_disposition(0, 9), 0);
        assert_eq!(nntmux_aporch_batch_disposition(1, 0), 1);
        assert_eq!(nntmux_aporch_batch_disposition(1, 3), 2);
        assert_eq!(nntmux_aporch_should_clear_claim(32, 1), 1);
        assert_eq!(nntmux_aporch_should_clear_claim(0, 1), 0);
        assert_eq!(nntmux_aporch_should_clear_claim(32, 0), 0);
        let mut status = 0;
        assert!(unsafe { nntmux_aporch_elapsed_seconds(0, 1, std::ptr::null_mut()) }.is_nan());
        assert_eq!(
            unsafe { nntmux_aporch_elapsed_seconds(0, 1_000, &mut status) },
            1e-6
        );
        assert_eq!(status, 1);
    }

    #[test]
    fn budget_operations_saturate_or_defer() {
        assert_eq!(w16_budget_eval(W16_BUDGET_ALLOWS, 10, 4, 6), Ok(Some(1)));
        assert_eq!(w16_budget_eval(W16_BUDGET_ALLOWS, 10, 4, 7), Ok(Some(0)));
        assert_eq!(w16_budget_eval(W16_BUDGET_ALLOWS, 10, 4, -50), Ok(Some(1)));
        assert_eq!(
            w16_budget_eval(W16_BUDGET_ALLOWS, i64::MAX, i64::MAX, 1),
            Ok(None)
        );
        assert_eq!(w16_budget_eval(W16_BUDGET_SPEND, 0, 4, -3), Ok(Some(4)));
        assert_eq!(w16_budget_eval(W16_BUDGET_SPEND, 0, i64::MAX, 1), Ok(None));
        assert_eq!(
            w16_budget_eval(W16_BUDGET_REMAINING, 10, 14, 0),
            Ok(Some(0))
        );
        assert_eq!(
            w16_budget_eval(W16_BUDGET_REMAINING, i64::MIN, i64::MAX, 0),
            Ok(Some(0))
        );
        assert_eq!(
            w16_budget_eval(W16_BUDGET_REMAINING, i64::MAX, -1, 0),
            Ok(None)
        );
        assert_eq!(
            w16_budget_eval(W16_BUDGET_EXHAUSTED, 10, 10, 0),
            Ok(Some(1))
        );
        assert_eq!(w16_budget_eval(W16_BUDGET_CLAMP, 10, 4, 100), Ok(Some(6)));
        assert_eq!(w16_budget_eval(W16_BUDGET_CLAMP, 10, 4, -3), Ok(Some(-3)));
        assert_eq!(w16_budget_eval(99, 0, 0, 0), Err(()));
        assert_eq!(w16_budget_utilization(0, 5), Some(1.0));
        assert_eq!(w16_budget_utilization(4, 1), Some(0.25));
        assert_eq!(w16_budget_utilization(4, 9), Some(1.0));
        let mut out = 0;
        assert_eq!(
            unsafe { nntmux_budget_eval(0, 1, 0, 0, std::ptr::null_mut()) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_budget_eval(1, 0, i64::MAX, 1, &mut out) },
            0
        );
        assert_eq!(unsafe { nntmux_budget_eval(2, 9, 2, 0, &mut out) }, 1);
        assert_eq!(out, 7);
    }

    #[test]
    fn metrics_convert_and_format() {
        let seconds = w16_ns_to_seconds(&pack(&[0, 1_500_000_000, 3_000_000_000, -7])).unwrap();
        let values: Vec<f64> = seconds
            .as_chunks::<8>()
            .0
            .iter()
            .map(|c| f64::from_le_bytes(*c))
            .collect();
        assert_eq!(values, vec![0.0, 1.5, 3.0, -7e-9]);
        assert!(w16_ns_to_seconds(b"abc").is_none());
        assert_eq!(nntmux_pmetrics_rate(10, 4.0), 2.5);
        assert_eq!(nntmux_pmetrics_rate(10, 0.0), 0.0);
        assert_eq!(nntmux_pmetrics_rate(10, f64::NAN), 0.0);
        let mut packed = entry("download", 1.25);
        packed.extend(entry("probe", 0.0000025));
        assert_eq!(
            w16_format_summary(&packed).unwrap(),
            b"download=1.250000s probe=0.000003s"
        );
        assert_eq!(w16_format_summary(b"").unwrap(), b"");
        assert_eq!(
            w16_format_summary(&entry("z", -0.0)).unwrap(),
            b"z=0.000000s"
        );
        assert_eq!(
            w16_format_summary(&entry("n", -1.5e-7)).unwrap(),
            b"n=-0.000000s"
        );
        assert!(w16_format_summary(&entry("x", f64::INFINITY)).is_none());
        assert!(w16_format_summary(&packed[..packed.len() - 1]).is_none());
        let mut tiny = [0u8; 2];
        let needed = unsafe {
            nntmux_pmetrics_format_summary(packed.as_ptr(), packed.len(), tiny.as_mut_ptr(), 2)
        };
        assert_eq!(needed, -34);
        assert_eq!(
            unsafe { nntmux_pmetrics_ns_to_seconds(std::ptr::null(), 8, tiny.as_mut_ptr(), 2) },
            -1
        );
    }

    #[test]
    fn fetcher_helpers() {
        assert!(w16_safe_witness_name(b"Movie.2024.mkv"));
        assert!(w16_safe_witness_name("Fïlm.mp4".as_bytes()));
        for bad in [
            &b""[..],
            b".hidden.mkv",
            b"-x.mkv",
            b"a/b.mkv",
            b"a\\b.mkv",
            b"a\x01.mkv",
            b"a\x7f",
            b"\xff.mkv",
        ] {
            assert!(!w16_safe_witness_name(bad), "{bad:?}");
        }
        assert!(!w16_safe_witness_name(&vec![b'a'; 4097]));
        assert!(w16_safe_witness_name(&vec![b'a'; 4096]));
        assert_eq!(w16_chunk_length(10, 4), Some(4));
        assert_eq!(w16_chunk_length(10, 40), Some(10));
        assert_eq!(w16_chunk_length(10, -3), Some(7));
        assert_eq!(w16_chunk_length(10, i64::MIN), Some(0));
        assert_eq!(nntmux_fetcher_chunk_length(-1, 3), -1);
        assert_eq!(
            unsafe { nntmux_fetcher_safe_witness_name(std::ptr::null(), 3) },
            -1
        );
    }

    #[test]
    fn archive_summary_counts_and_defers() {
        let flags = [0u8, W16_ARCHIVE_IS_DIR, W16_ARCHIVE_PASSWORDED, 0];
        let summary = w16_archive_summary(&flags, &pack(&[10, 999, 5, -2])).unwrap();
        assert_eq!(summary, Some([3, 1, 13, 1]));
        assert_eq!(w16_archive_summary(&[], &[]), Ok(Some([0, 0, 0, 0])));
        assert_eq!(
            w16_archive_summary(&[0, 0], &pack(&[i64::MAX, 1])),
            Ok(None)
        );
        assert_eq!(w16_archive_summary(&[0], &pack(&[1, 2])), Err(()));
        assert_eq!(w16_archive_summary(&[4], &pack(&[1])), Err(()));
        let mut out = [0i64; 4];
        assert_eq!(
            unsafe {
                nntmux_dlarchive_summary(
                    flags.as_ptr(),
                    4,
                    std::ptr::null(),
                    32,
                    out.as_mut_ptr(),
                    4,
                )
            },
            -1
        );
        let sizes = pack(&[1, 2, 3, 4]);
        assert_eq!(
            unsafe {
                nntmux_dlarchive_summary(flags.as_ptr(), 4, sizes.as_ptr(), 32, out.as_mut_ptr(), 3)
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_dlarchive_summary(flags.as_ptr(), 4, sizes.as_ptr(), 32, out.as_mut_ptr(), 4)
            },
            1
        );
        assert_eq!(out, [3, 1, 8, 1]);
    }
}

// ---------------------------------------------------------------------------
// Wave 17: ProcessingConfiguration, ReleaseProcessingResult, MediaToolFactory,
// ReleaseSearchSyncCoordinator and PersistenceMetricsCollector helpers.
// ---------------------------------------------------------------------------

/// Number of `ProcessingConfiguration` integer limits normalized by
/// `nntmux_pcfg_normalize_limits`.
pub(crate) const W17_LIMIT_COUNT: usize = 14;

#[inline]
fn w17_aligned<T>(ptr: *const T, count: usize) -> bool {
    !ptr.is_null()
        && (ptr as usize).is_multiple_of(std::mem::align_of::<T>())
        && count
            .checked_mul(std::mem::size_of::<T>())
            .is_some_and(|bytes| {
                bytes <= isize::MAX as usize && (ptr as usize).checked_add(bytes).is_some()
            })
}

/// PHP truthiness of a `string|false` value: false, `""` and `"0"` are falsy.
#[inline]
fn w17_php_string_truthy(present: bool, value: &[u8]) -> bool {
    present && !value.is_empty() && value != b"0"
}

/// `ProcessingConfiguration::getKillString`.
pub(crate) fn w17_kill_string(present: bool, path: &[u8], timeout_seconds: i64) -> Vec<u8> {
    if !w17_php_string_truthy(present, path) || timeout_seconds <= 0 {
        return b"\"".to_vec();
    }
    let mut out = Vec::with_capacity(path.len() + 56);
    out.push(b'"');
    out.extend_from_slice(path);
    out.extend_from_slice(b"\" --foreground --signal=KILL ");
    out.extend_from_slice(timeout_seconds.to_string().as_bytes());
    out.extend_from_slice(b" \"");
    out
}

/// PHP's `dirname()` for POSIX paths (`zend_dirname`).
pub(crate) fn w17_php_dirname(path: &[u8]) -> &[u8] {
    if path.is_empty() {
        return b"";
    }
    let mut end = path.len();
    while end > 0 && path[end - 1] == b'/' {
        end -= 1;
    }
    if end == 0 {
        return b"/";
    }
    while end > 0 && path[end - 1] != b'/' {
        end -= 1;
    }
    if end == 0 {
        return b".";
    }
    while end > 0 && path[end - 1] == b'/' {
        end -= 1;
    }
    if end == 0 {
        return b"/";
    }
    &path[..end]
}

/// `dirname($ffmpegPath).DIRECTORY_SEPARATOR.'ffprobe'`.
pub(crate) fn w17_ffprobe_sibling(ffmpeg_path: &[u8]) -> Vec<u8> {
    let dir = w17_php_dirname(ffmpeg_path);
    let mut out = Vec::with_capacity(dir.len() + 8);
    out.extend_from_slice(dir);
    out.extend_from_slice(b"/ffprobe");
    out
}

/// Clamp rules for the `ProcessingConfiguration` limits, in caller order:
/// archiveProbeFiles, seasonPackArchiveSets, seasonPackMaxReleaseFiles,
/// par2IndexMaxSegments, par2VerifyFiles, mediaMaxBytes, mediaSampleMaxBytes,
/// mediaMidBytes, mediaMainVideoMinBytes, mediaImageMaxBytes, mediaStageMaxSeconds,
/// mediaFfmpegTimeout, thumbnailFrameAttempts, maximumRarPasswordChecks.
const W17_LIMIT_RULES: [(i64, i64); W17_LIMIT_COUNT] = [
    (0, i64::MAX),
    (1, 50),
    (11, 200),
    (1, i64::MAX),
    (0, i64::MAX),
    (0, i64::MAX),
    (1_048_576, i64::MAX),
    (262_144, i64::MAX),
    (0, i64::MAX),
    (1_048_576, i64::MAX),
    (0, i64::MAX),
    (1, i64::MAX),
    (1, 10),
    (1, i64::MAX),
];

pub(crate) fn w17_normalize_limits(raw: &[i64; W17_LIMIT_COUNT]) -> [i64; W17_LIMIT_COUNT] {
    let mut out = [0i64; W17_LIMIT_COUNT];
    for (slot, (&value, &(min, max))) in out.iter_mut().zip(raw.iter().zip(W17_LIMIT_RULES.iter()))
    {
        *slot = value.max(min).min(max);
    }
    out
}

/// `ProcessingOutcome::isSuccessful` by backing value; `None` for an unknown value.
pub(crate) fn w17_outcome_successful(value: &[u8]) -> Option<bool> {
    match value {
        b"completed" | b"no-useful-artifacts" | b"passworded" => Some(true),
        b"group-unavailable"
        | b"temporary-workspace-unavailable"
        | b"timed-out"
        | b"deleted-after-timeout"
        | b"deleted-broken-nzb"
        | b"deleted-par2-only"
        | b"not-found"
        | b"awaiting-late-articles"
        | b"failed" => Some(false),
        _ => None,
    }
}

/// `ReleaseProcessingResult::durationSummary` over little-endian f64 stage durations:
/// `(stage total, slowest seconds, unaccounted seconds, slowest index + 1 or 0)`.
pub(crate) fn w17_duration_summary(packed: &[u8], elapsed: f64) -> Option<(f64, f64, f64, u64)> {
    if !packed.len().is_multiple_of(8) {
        return None;
    }
    let mut total = 0.0f64;
    let mut slowest = 0.0f64;
    let mut slowest_index = 0u64;
    for (index, chunk) in packed.as_chunks::<8>().0.iter().enumerate() {
        let seconds = f64::from_le_bytes(*chunk);
        total += seconds;
        if slowest_index == 0 || seconds > slowest {
            slowest = seconds;
            slowest_index = u64::try_from(index).ok()?.checked_add(1)?;
        }
    }
    let mut unaccounted = elapsed - total;
    if unaccounted < 0.0 {
        unaccounted = 0.0;
    }
    Some((total, slowest, unaccounted, slowest_index))
}

/// `MediaToolFactory::driverConfiguration` binary inclusion mask: bit 0 for ffmpeg,
/// bit 1 for ffprobe (each a non-empty string).
#[inline]
pub(crate) fn w17_driver_mask(
    ffmpeg_is_string: bool,
    ffmpeg_len: usize,
    ffprobe_is_string: bool,
    ffprobe_len: usize,
) -> i32 {
    i32::from(ffmpeg_is_string && ffmpeg_len > 0)
        | (i32::from(ffprobe_is_string && ffprobe_len > 0) << 1)
}

/// Search-sync coalescing state after one coordinator operation.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) struct W17SyncState {
    pub scope: Option<i64>,
    pub pending: bool,
    pub execute: Option<i64>,
}

/// `ReleaseSearchSyncCoordinator` transitions: 0 begin, 1 request, 2 discard, 3 finish.
pub(crate) fn w17_sync_decide(
    op: i32,
    coalesce: bool,
    scope: Option<i64>,
    pending: bool,
    arg: i64,
) -> Option<W17SyncState> {
    let unchanged = W17SyncState {
        scope,
        pending,
        execute: None,
    };
    Some(match op {
        0 if !coalesce => unchanged,
        0 => W17SyncState {
            scope: Some(arg),
            pending: false,
            execute: None,
        },
        1 if coalesce && scope == Some(arg) => W17SyncState {
            scope,
            pending: true,
            execute: None,
        },
        1 => W17SyncState {
            scope,
            pending,
            execute: Some(arg),
        },
        2 if coalesce && scope == Some(arg) => W17SyncState {
            scope,
            pending: false,
            execute: None,
        },
        2 => unchanged,
        3 if !coalesce => unchanged,
        3 => W17SyncState {
            scope: None,
            pending: false,
            execute: if pending { scope } else { None },
        },
        _ => return None,
    })
}

/// `PersistenceMetricsCollector` counters: statements, sync requests, sync executions.
#[derive(Debug, Clone, Copy, PartialEq)]
pub(crate) struct W17Metrics {
    pub statements: i64,
    pub milliseconds: f64,
    pub requests: i64,
    pub executions: i64,
}

/// Applies one collector event (0 statement, 1 sync request, 2 sync execution).
/// `Ok(true)` when counters changed, `Ok(false)` when out of scope, `Err` on an unknown
/// event or counter overflow.
pub(crate) fn w17_metrics_apply(
    event: i32,
    scope: Option<i64>,
    arg_id: i64,
    arg_ms: f64,
    metrics: &mut W17Metrics,
) -> Result<bool, ()> {
    match event {
        0 => {
            if scope.is_none() {
                return Ok(false);
            }
            metrics.statements = metrics.statements.checked_add(1).ok_or(())?;
            metrics.milliseconds += arg_ms;
            Ok(true)
        }
        1 | 2 => {
            if scope != Some(arg_id) {
                return Ok(false);
            }
            let counter = if event == 1 {
                &mut metrics.requests
            } else {
                &mut metrics.executions
            };
            *counter = counter.checked_add(1).ok_or(())?;
            Ok(true)
        }
        _ => Err(()),
    }
}

/// `ProcessingConfiguration::getKillString`. `path_present` is 0 for `false`.
/// Returns bytes written, `-needed` for a short buffer or -1 for invalid input.
///
/// # Safety
/// Non-null pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_pcfg_kill_string(
    path: *const u8,
    path_len: usize,
    path_present: i32,
    timeout_seconds: i64,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(path) = w15_input(path, path_len) else {
            return -1;
        };
        w15_write(
            out,
            cap,
            &w17_kill_string(path_present != 0, path, timeout_seconds),
        )
    })
    .unwrap_or(-1)
}

/// Sibling ffprobe candidate for an ffmpeg path. Returns bytes written, `-needed` for a
/// short buffer or -1 for invalid input.
///
/// # Safety
/// Non-null pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_pcfg_ffprobe_sibling(
    path: *const u8,
    path_len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let Some(path) = w15_input(path, path_len) else {
            return -1;
        };
        w15_write(out, cap, &w17_ffprobe_sibling(path))
    })
    .unwrap_or(-1)
}

/// Normalizes the 14 `ProcessingConfiguration` integer limits. Returns the number of
/// values written or -1 for invalid input (null/misaligned pointers, a wrong count or a
/// short output). `raw` and `out` may alias.
///
/// # Safety
/// `raw` must be readable for `count` and `out` writable for `out_count` `int64_t`s.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_pcfg_normalize_limits(
    raw: *const i64,
    count: usize,
    out: *mut i64,
    out_count: usize,
) -> i32 {
    std::panic::catch_unwind(|| {
        if count != W17_LIMIT_COUNT
            || out_count < W17_LIMIT_COUNT
            || !w17_aligned(raw, count)
            || !w17_aligned(out.cast_const(), out_count)
        {
            return -1;
        }
        let mut values = [0i64; W17_LIMIT_COUNT];
        // SAFETY: `raw` is non-null, aligned and readable for `W17_LIMIT_COUNT` values.
        unsafe { std::ptr::copy(raw, values.as_mut_ptr(), W17_LIMIT_COUNT) };
        let normalized = w17_normalize_limits(&values);
        // SAFETY: `out` is non-null, aligned and writable for `W17_LIMIT_COUNT` values.
        unsafe { std::ptr::copy(normalized.as_ptr(), out, W17_LIMIT_COUNT) };
        W17_LIMIT_COUNT as i32
    })
    .unwrap_or(-1)
}

/// `ProcessingOutcome::isSuccessful` by backing value: 1, 0 or -1 for an unknown value or
/// invalid input.
///
/// # Safety
/// A non-null `ptr` must be readable for `len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rpresult_outcome_successful(ptr: *const u8, len: usize) -> i32 {
    std::panic::catch_unwind(
        || match w15_input(ptr, len).and_then(w17_outcome_successful) {
            Some(successful) => i32::from(successful),
            None => -1,
        },
    )
    .unwrap_or(-1)
}

/// Stage-duration summary. Writes 32 bytes `[f64 total][f64 slowest][f64 unaccounted]
/// [u64 slowest index + 1]` (little-endian) and returns 32, or -1 for invalid input or an
/// output shorter than 32 bytes.
///
/// # Safety
/// Non-null pointers must be valid for their declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rpresult_duration_summary(
    packed: *const u8,
    len: usize,
    elapsed: f64,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        if cap < 32 {
            return -1;
        }
        let Some(input) = w15_input(packed, len) else {
            return -1;
        };
        let Some((total, slowest, unaccounted, index)) = w17_duration_summary(input, elapsed)
        else {
            return -1;
        };
        let mut bytes = [0u8; 32];
        bytes[0..8].copy_from_slice(&total.to_le_bytes());
        bytes[8..16].copy_from_slice(&slowest.to_le_bytes());
        bytes[16..24].copy_from_slice(&unaccounted.to_le_bytes());
        bytes[24..32].copy_from_slice(&index.to_le_bytes());
        w15_write(out, cap, &bytes)
    })
    .unwrap_or(-1)
}

/// `MediaToolFactory::driverConfiguration` binary mask (bit 0 ffmpeg, bit 1 ffprobe).
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_mtf_driver_mask(
    ffmpeg_is_string: i32,
    ffmpeg_len: usize,
    ffprobe_is_string: i32,
    ffprobe_len: usize,
) -> i32 {
    std::panic::catch_unwind(|| {
        w17_driver_mask(
            ffmpeg_is_string != 0,
            ffmpeg_len,
            ffprobe_is_string != 0,
            ffprobe_len,
        )
    })
    .unwrap_or(-1)
}

/// `ReleaseSearchSyncCoordinator` transition (0 begin, 1 request, 2 discard, 3 finish).
/// Writes `[has scope, scope id, pending, execute, execute id]` to `out` and returns 0,
/// or -1 for an unknown operation or invalid output.
///
/// # Safety
/// `out` must be writable for `out_count` `int64_t`s.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_rssc_decide(
    op: i32,
    coalesce: i32,
    has_scope: i32,
    scope_id: i64,
    pending: i32,
    arg_id: i64,
    out: *mut i64,
    out_count: usize,
) -> i32 {
    std::panic::catch_unwind(|| {
        if out_count < 5 || !w17_aligned(out.cast_const(), out_count) {
            return -1;
        }
        let scope = (has_scope != 0).then_some(scope_id);
        let Some(state) = w17_sync_decide(op, coalesce != 0, scope, pending != 0, arg_id) else {
            return -1;
        };
        let values = [
            i64::from(state.scope.is_some()),
            state.scope.unwrap_or(0),
            i64::from(state.pending),
            i64::from(state.execute.is_some()),
            state.execute.unwrap_or(0),
        ];
        // SAFETY: `out` is non-null, aligned and writable for at least five values.
        unsafe { std::ptr::copy_nonoverlapping(values.as_ptr(), out, 5) };
        0
    })
    .unwrap_or(-1)
}

/// `PersistenceMetricsCollector` event (0 statement, 1 sync request, 2 sync execution).
/// `counters` holds `[statements, requests, executions]` and `milliseconds` the latency
/// total; both are updated in place. Returns 1 when changed, 0 when out of scope and -1
/// for an unknown event, counter overflow or invalid pointers (leaving outputs untouched).
///
/// # Safety
/// `counters` must be valid for `counter_count` `int64_t`s and `milliseconds` for one
/// `double`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_pmc_apply(
    event: i32,
    has_scope: i32,
    scope_id: i64,
    arg_id: i64,
    arg_ms: f64,
    counters: *mut i64,
    counter_count: usize,
    milliseconds: *mut f64,
) -> i32 {
    std::panic::catch_unwind(|| {
        if counter_count < 3
            || !w17_aligned(counters.cast_const(), counter_count)
            || !w17_aligned(milliseconds.cast_const(), 1)
        {
            return -1;
        }
        // SAFETY: both pointers are non-null, aligned and valid for the declared counts.
        let (values, ms) = unsafe {
            (
                std::slice::from_raw_parts_mut(counters, 3),
                &mut *milliseconds,
            )
        };
        let mut metrics = W17Metrics {
            statements: values[0],
            milliseconds: *ms,
            requests: values[1],
            executions: values[2],
        };
        let scope = (has_scope != 0).then_some(scope_id);
        match w17_metrics_apply(event, scope, arg_id, arg_ms, &mut metrics) {
            Ok(true) => {
                values[0] = metrics.statements;
                values[1] = metrics.requests;
                values[2] = metrics.executions;
                *ms = metrics.milliseconds;
                1
            }
            Ok(false) => 0,
            Err(()) => -1,
        }
    })
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave17_additional_config_metrics_sync_tests {
    use super::*;

    #[test]
    fn kill_string_matches_php_truthiness() {
        assert_eq!(w17_kill_string(false, b"", 30), b"\"");
        assert_eq!(w17_kill_string(true, b"", 30), b"\"");
        assert_eq!(w17_kill_string(true, b"0", 30), b"\"");
        assert_eq!(w17_kill_string(true, b"/usr/bin/timeout", 0), b"\"");
        assert_eq!(w17_kill_string(true, b"/usr/bin/timeout", -5), b"\"");
        assert_eq!(
            w17_kill_string(true, b"/usr/bin/timeout", 60),
            b"\"/usr/bin/timeout\" --foreground --signal=KILL 60 \""
        );
        assert_eq!(
            w17_kill_string(true, b"00", i64::MAX),
            format!("\"00\" --foreground --signal=KILL {} \"", i64::MAX).as_bytes()
        );
        let mut tiny = [0u8; 4];
        let needed =
            unsafe { nntmux_pcfg_kill_string(b"/t".as_ptr(), 2, 1, 5, tiny.as_mut_ptr(), 4) };
        assert_eq!(needed, -35);
        assert_eq!(
            unsafe { nntmux_pcfg_kill_string(std::ptr::null(), 3, 1, 5, tiny.as_mut_ptr(), 4) },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_pcfg_kill_string(b"x".as_ptr(), usize::MAX, 1, 5, tiny.as_mut_ptr(), 4)
            },
            -1
        );
        assert_eq!(
            unsafe { nntmux_pcfg_kill_string(std::ptr::null(), 0, 0, 5, tiny.as_mut_ptr(), 4) },
            1
        );
        assert_eq!(tiny[0], b'"');
    }

    #[test]
    fn dirname_matches_php() {
        for (input, expected) in [
            (&b""[..], &b""[..]),
            (b"ffmpeg", b"."),
            (b"/", b"/"),
            (b"///", b"/"),
            (b"/ffmpeg", b"/"),
            (b"//ffmpeg", b"/"),
            (b"/usr/bin/ffmpeg", b"/usr/bin"),
            (b"/usr/bin//ffmpeg", b"/usr/bin"),
            (b"/usr/bin/", b"/usr"),
            (b"usr/bin", b"usr"),
            (b"///a//b//", b"///a"),
            (b"./ffmpeg", b"."),
            (b"../ffmpeg", b".."),
        ] {
            assert_eq!(w17_php_dirname(input), expected, "{input:?}");
        }
        assert_eq!(w17_ffprobe_sibling(b"/opt/bin/ffmpeg"), b"/opt/bin/ffprobe");
        assert_eq!(w17_ffprobe_sibling(b""), b"/ffprobe");
        assert_eq!(w17_ffprobe_sibling(b"ffmpeg"), b"./ffprobe");
    }

    #[test]
    fn normalize_limits_clamps_and_validates() {
        let raw = [-1, 0, 500, 0, -3, -9, 10, 10, -1, 0, -1, 0, 99, 0];
        assert_eq!(
            w17_normalize_limits(&raw),
            [
                0, 1, 200, 1, 0, 0, 1_048_576, 262_144, 0, 1_048_576, 0, 1, 10, 1
            ]
        );
        let mut buf = [i64::MIN; W17_LIMIT_COUNT];
        assert_eq!(
            unsafe {
                nntmux_pcfg_normalize_limits(
                    buf.as_ptr(),
                    W17_LIMIT_COUNT,
                    buf.as_mut_ptr(),
                    W17_LIMIT_COUNT,
                )
            },
            14
        );
        assert_eq!(buf[12], 1);
        assert_eq!(buf[2], 11);
        let mut out = [0i64; W17_LIMIT_COUNT];
        assert_eq!(
            unsafe { nntmux_pcfg_normalize_limits(buf.as_ptr(), 13, out.as_mut_ptr(), 14) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_pcfg_normalize_limits(buf.as_ptr(), 14, out.as_mut_ptr(), 13) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_pcfg_normalize_limits(std::ptr::null(), 14, out.as_mut_ptr(), 14) },
            -1
        );
        let bytes = [0u8; 8 * 15];
        let misaligned = unsafe { bytes.as_ptr().add(1) }.cast::<i64>();
        assert_eq!(
            unsafe { nntmux_pcfg_normalize_limits(misaligned, 14, out.as_mut_ptr(), 14) },
            -1
        );
    }

    #[test]
    fn outcome_and_duration_summary() {
        assert_eq!(w17_outcome_successful(b"completed"), Some(true));
        assert_eq!(w17_outcome_successful(b"passworded"), Some(true));
        assert_eq!(w17_outcome_successful(b"failed"), Some(false));
        assert_eq!(w17_outcome_successful(b"Completed"), None);
        assert_eq!(
            unsafe { nntmux_rpresult_outcome_successful(std::ptr::null(), 4) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_rpresult_outcome_successful(b"timed-out".as_ptr(), 9) },
            0
        );

        let pack = |values: &[f64]| {
            values
                .iter()
                .flat_map(|v| v.to_le_bytes())
                .collect::<Vec<u8>>()
        };
        assert_eq!(w17_duration_summary(&[], 1.5), Some((0.0, 0.0, 1.5, 0)));
        assert_eq!(
            w17_duration_summary(&pack(&[0.5, 2.0, 2.0, 1.0]), 4.0),
            Some((5.5, 2.0, 0.0, 2))
        );
        let (total, _, unaccounted, index) =
            w17_duration_summary(&pack(&[f64::NAN, 1.0]), 3.0).unwrap();
        assert!(total.is_nan() && unaccounted.is_nan());
        assert_eq!(index, 1);
        assert!(w17_duration_summary(&[0u8; 7], 0.0).is_none());
        let mut out = [0u8; 32];
        assert_eq!(
            unsafe {
                nntmux_rpresult_duration_summary(std::ptr::null(), 0, 1.0, out.as_mut_ptr(), 31)
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_rpresult_duration_summary(std::ptr::null(), 8, 1.0, out.as_mut_ptr(), 32)
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_rpresult_duration_summary(std::ptr::null(), 0, 1.0, out.as_mut_ptr(), 32)
            },
            32
        );
        assert_eq!(f64::from_le_bytes(out[16..24].try_into().unwrap()), 1.0);
    }

    #[test]
    fn driver_mask() {
        assert_eq!(nntmux_mtf_driver_mask(0, 0, 0, 0), 0);
        assert_eq!(nntmux_mtf_driver_mask(1, 0, 1, 0), 0);
        assert_eq!(nntmux_mtf_driver_mask(1, 5, 0, 5), 1);
        assert_eq!(nntmux_mtf_driver_mask(0, 5, 1, 5), 2);
        assert_eq!(nntmux_mtf_driver_mask(1, 5, 1, 5), 3);
    }

    #[test]
    fn sync_transitions() {
        let s = |scope, pending, execute| W17SyncState {
            scope,
            pending,
            execute,
        };
        assert_eq!(
            w17_sync_decide(0, true, None, true, 7),
            Some(s(Some(7), false, None))
        );
        assert_eq!(
            w17_sync_decide(0, false, Some(3), true, 7),
            Some(s(Some(3), true, None))
        );
        assert_eq!(
            w17_sync_decide(1, true, Some(7), false, 7),
            Some(s(Some(7), true, None))
        );
        assert_eq!(
            w17_sync_decide(1, true, Some(7), false, 8),
            Some(s(Some(7), false, Some(8)))
        );
        assert_eq!(
            w17_sync_decide(1, false, Some(7), false, 7),
            Some(s(Some(7), false, Some(7)))
        );
        assert_eq!(
            w17_sync_decide(2, true, Some(7), true, 7),
            Some(s(Some(7), false, None))
        );
        assert_eq!(
            w17_sync_decide(2, true, Some(7), true, 8),
            Some(s(Some(7), true, None))
        );
        assert_eq!(
            w17_sync_decide(3, true, Some(7), true, 0),
            Some(s(None, false, Some(7)))
        );
        assert_eq!(
            w17_sync_decide(3, true, Some(7), false, 0),
            Some(s(None, false, None))
        );
        assert_eq!(
            w17_sync_decide(3, false, Some(7), true, 0),
            Some(s(Some(7), true, None))
        );
        assert_eq!(w17_sync_decide(9, true, None, false, 0), None);
        let mut out = [0i64; 5];
        assert_eq!(
            unsafe { nntmux_rssc_decide(3, 1, 1, -4, 1, 0, out.as_mut_ptr(), 5) },
            0
        );
        assert_eq!(out, [0, 0, 0, 1, -4]);
        assert_eq!(
            unsafe { nntmux_rssc_decide(3, 1, 1, -4, 1, 0, out.as_mut_ptr(), 4) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_rssc_decide(3, 1, 1, -4, 1, 0, std::ptr::null_mut(), 5) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_rssc_decide(-1, 1, 1, -4, 1, 0, out.as_mut_ptr(), 5) },
            -1
        );
    }

    #[test]
    fn metrics_events() {
        let mut m = W17Metrics {
            statements: 0,
            milliseconds: 0.0,
            requests: 0,
            executions: 0,
        };
        assert_eq!(w17_metrics_apply(0, None, 0, 1.5, &mut m), Ok(false));
        assert_eq!(w17_metrics_apply(0, Some(4), 0, 1.5, &mut m), Ok(true));
        assert_eq!(w17_metrics_apply(1, Some(4), 4, 0.0, &mut m), Ok(true));
        assert_eq!(w17_metrics_apply(2, Some(4), 5, 0.0, &mut m), Ok(false));
        assert_eq!(w17_metrics_apply(2, None, 0, 0.0, &mut m), Ok(false));
        assert_eq!(
            m,
            W17Metrics {
                statements: 1,
                milliseconds: 1.5,
                requests: 1,
                executions: 0
            }
        );
        assert_eq!(w17_metrics_apply(7, Some(4), 4, 0.0, &mut m), Err(()));
        m.statements = i64::MAX;
        assert_eq!(w17_metrics_apply(0, Some(4), 0, 1.0, &mut m), Err(()));

        let mut counters = [i64::MAX, 0, 0];
        let mut ms = 2.0f64;
        assert_eq!(
            unsafe { nntmux_pmc_apply(0, 1, 1, 0, 1.0, counters.as_mut_ptr(), 3, &mut ms) },
            -1
        );
        assert_eq!((counters, ms), ([i64::MAX, 0, 0], 2.0));
        assert_eq!(
            unsafe { nntmux_pmc_apply(1, 1, 1, 1, 0.0, counters.as_mut_ptr(), 3, &mut ms) },
            1
        );
        assert_eq!(counters, [i64::MAX, 1, 0]);
        assert_eq!(
            unsafe { nntmux_pmc_apply(1, 1, 1, 1, 0.0, counters.as_mut_ptr(), 2, &mut ms) },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_pmc_apply(
                    1,
                    1,
                    1,
                    1,
                    0.0,
                    counters.as_mut_ptr(),
                    3,
                    std::ptr::null_mut(),
                )
            },
            -1
        );
    }
}
// ---------------------------------------------------------------------------
// Wave 18: ArchiveProcessingService, MediaSourcePlan, DownloadKind,
// ImageProcessingResult and IngestionConfigurationData helpers.
// ---------------------------------------------------------------------------

/// `ArchiveProcessingService::normalizeAnalysis` outcome codes.
pub(crate) const W18_ANALYSIS_OK: i32 = 0;
pub(crate) const W18_ANALYSIS_OK_ENCRYPTED: i32 = 1;
pub(crate) const W18_ANALYSIS_ARCHIVE_ERROR: i32 = 2;
pub(crate) const W18_ANALYSIS_UNKNOWN_ERROR: i32 = 3;

/// `ImageProcessingResult` field presence bits.
pub(crate) const W18_IPR_SUCCESS: i32 = 1;
pub(crate) const W18_IPR_PATH: i32 = 1 << 1;
pub(crate) const W18_IPR_WIDTH: i32 = 1 << 2;
pub(crate) const W18_IPR_HEIGHT: i32 = 1 << 3;
pub(crate) const W18_IPR_MIME: i32 = 1 << 4;
pub(crate) const W18_IPR_REASON: i32 = 1 << 5;

/// Number of non-string `IngestionConfigurationData` fields (declaration order, with
/// `safeBackfillDate` removed).
pub(crate) const W18_ICD_NUMERIC_COUNT: usize = 35;

/// Positions of the boolean fields within the numeric field list.
const W18_ICD_BOOL_SLOTS: [usize; 8] = [7, 23, 24, 25, 28, 29, 30, 31];

/// `IngestionConfigurationData::defaults()` numeric fields (booleans as 0/1).
const W18_ICD_DEFAULTS: [i64; W18_ICD_NUMERIC_COUNT] = [
    1, 1, 1, 2, 48, 2, 95, 1, 1_000_000, 20_000, 1000, 10, 4, 72, 0, 0, 0, 1, 0, 0, 0, 1, 100_000,
    0, 1, 0, 15_000, 3, 1, 0, 0, 0, 1, 2, 100_000,
];

/// `IngestionConfigurationData::defaults()->safeBackfillDate`.
const W18_ICD_DEFAULT_DATE: &[u8] = b"2012-06-24";

/// Non-null, `T`-aligned pointer whose `count` elements fit in `isize::MAX` bytes
/// without wrapping the address space.
#[inline]
fn w18_slice_ok<T>(ptr: *const T, count: usize) -> bool {
    !ptr.is_null()
        && ptr.is_aligned()
        && count
            .checked_mul(std::mem::size_of::<T>())
            .is_some_and(|bytes| {
                bytes <= isize::MAX as usize && (ptr as usize).checked_add(bytes).is_some()
            })
}

/// PHP truthiness of a string: `""` and `"0"` are falsy.
#[inline]
fn w18_php_string_truthy(value: &[u8]) -> bool {
    !value.is_empty() && value != b"0"
}

/// `ArchiveProcessingService::analyze` normalization: the archive error is a string,
/// `encrypted_prop` is `! empty($archiveInfo->isEncrypted)` and `summary_flag` is
/// `(int) $summary['is_encrypted']` when `summary_flag_present`.
pub(crate) fn w18_analysis_code(
    set_data_ok: bool,
    error: &[u8],
    encrypted_prop: bool,
    summary_flag_present: bool,
    summary_flag: i64,
) -> i32 {
    if !set_data_ok {
        return if w18_php_string_truthy(error) {
            W18_ANALYSIS_ARCHIVE_ERROR
        } else {
            W18_ANALYSIS_UNKNOWN_ERROR
        };
    }
    if !error.is_empty() {
        return W18_ANALYSIS_ARCHIVE_ERROR;
    }
    if encrypted_prop || (summary_flag_present && summary_flag != 0) {
        W18_ANALYSIS_OK_ENCRYPTED
    } else {
        W18_ANALYSIS_OK
    }
}

/// `MediaSourcePlan::realSizeOf` over little-endian `[i64 source index][i64 size]`
/// pairs: `Some(Some(size))` for the first matching index, `Some(None)` when absent and
/// `None` for a malformed buffer.
pub(crate) fn w18_real_size_of(packed: &[u8], source_index: i64) -> Option<Option<i64>> {
    if !packed.len().is_multiple_of(16) {
        return None;
    }
    for pair in packed.as_chunks::<16>().0 {
        if w15_read_i64(&pair[..8]) == source_index {
            return Some(Some(w15_read_i64(&pair[8..])));
        }
    }
    Some(None)
}

/// `DownloadKind::isPreviewSource` by backing value; `None` for an unknown value.
pub(crate) fn w18_download_kind_is_preview(value: &[u8]) -> Option<bool> {
    match value {
        b"par2-index" | b"media-source" | b"jpg" => Some(true),
        b"sample" | b"media-info" | b"audio" | b"compressed" => Some(false),
        _ => None,
    }
}

/// `ImageProcessingResult::success`/`failure` field presence mask and integer payload.
pub(crate) fn w18_image_result(success: bool, width: i64, height: i64) -> (i32, [i64; 2]) {
    if success {
        (
            W18_IPR_SUCCESS | W18_IPR_PATH | W18_IPR_WIDTH | W18_IPR_HEIGHT | W18_IPR_MIME,
            [width, height],
        )
    } else {
        (W18_IPR_REASON, [0, 0])
    }
}

/// `IngestionConfigurationData` numeric fields with boolean slots normalized to 0/1.
pub(crate) fn w18_icd_normalize(
    raw: &[i64; W18_ICD_NUMERIC_COUNT],
) -> [i64; W18_ICD_NUMERIC_COUNT] {
    let mut out = *raw;
    for slot in W18_ICD_BOOL_SLOTS {
        out[slot] = i64::from(out[slot] != 0);
    }
    out
}

/// `ArchiveProcessingService` analysis outcome: 0 ok, 1 ok and encrypted, 2 failed with
/// the archive error, 3 failed with `Unknown error`, or -1 for invalid input.
///
/// # Safety
/// A non-null `error` must be readable for `error_len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_aps_analysis_code(
    set_data_ok: i32,
    error: *const u8,
    error_len: usize,
    encrypted_prop: i32,
    summary_flag_present: i32,
    summary_flag: i64,
) -> i32 {
    std::panic::catch_unwind(|| {
        let Some(error) = w15_input(error, error_len) else {
            return -1;
        };
        w18_analysis_code(
            set_data_ok != 0,
            error,
            encrypted_prop != 0,
            summary_flag_present != 0,
            summary_flag,
        )
    })
    .unwrap_or(-1)
}

/// Mapped PAR2 size for an NZB source index. `packed` holds little-endian
/// `[i64 index][i64 size]` pairs. Returns 1 and writes `*out_size` when found, 0 when
/// absent, or -1 for invalid input (length not a multiple of 16, null/misaligned output).
///
/// # Safety
/// A non-null `packed` must be readable for `len` bytes and `out_size` writable.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_msp_real_size_of(
    packed: *const u8,
    len: usize,
    source_index: i64,
    out_size: *mut i64,
) -> i32 {
    std::panic::catch_unwind(|| {
        if !w18_slice_ok(out_size.cast_const(), 1) {
            return -1;
        }
        let Some(packed) = w15_input(packed, len) else {
            return -1;
        };
        match w18_real_size_of(packed, source_index) {
            Some(Some(size)) => {
                // SAFETY: `out_size` is non-null, aligned and writable for one value.
                unsafe { out_size.write(size) };
                1
            }
            Some(None) => 0,
            None => -1,
        }
    })
    .unwrap_or(-1)
}

/// `DownloadKind::isPreviewSource` by backing value: 1, 0 or -1 for an unknown value or
/// invalid input.
///
/// # Safety
/// A non-null `ptr` must be readable for `len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_dk_is_preview_source(ptr: *const u8, len: usize) -> i32 {
    std::panic::catch_unwind(
        || match w15_input(ptr, len).and_then(w18_download_kind_is_preview) {
            Some(preview) => i32::from(preview),
            None => -1,
        },
    )
    .unwrap_or(-1)
}

/// `ImageProcessingResult` construction (`kind` 1 success, 0 failure). Writes
/// `[width, height]` (zero on failure) and returns the field presence mask, or -1 for an
/// unknown kind or a null/misaligned/short output.
///
/// # Safety
/// A non-null `out` must be writable for `out_count` `int64_t`s.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_ipr_build(
    kind: i32,
    width: i64,
    height: i64,
    out: *mut i64,
    out_count: usize,
) -> i32 {
    std::panic::catch_unwind(|| {
        if !(kind == 0 || kind == 1) || out_count < 2 || !w18_slice_ok(out.cast_const(), out_count)
        {
            return -1;
        }
        let (mask, values) = w18_image_result(kind == 1, width, height);
        // SAFETY: `out` is non-null, aligned and writable for at least two values.
        unsafe { std::ptr::copy_nonoverlapping(values.as_ptr(), out, 2) };
        mask
    })
    .unwrap_or(-1)
}

/// `IngestionConfigurationData::defaults()`. Writes the 35 numeric fields to `out` and
/// the safe backfill date to `date`; returns the date length, `-needed` for a short date
/// buffer, or -1 for invalid input (null/misaligned/short `out`, invalid `date`).
///
/// # Safety
/// `out` must be writable for `out_count` `int64_t`s and a non-null `date` for
/// `date_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_icd_defaults(
    out: *mut i64,
    out_count: usize,
    date: *mut u8,
    date_cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        if out_count < W18_ICD_NUMERIC_COUNT || !w18_slice_ok(out.cast_const(), out_count) {
            return -1;
        }
        let written = w15_write(date, date_cap, W18_ICD_DEFAULT_DATE);
        if written < 0 {
            return written;
        }
        // SAFETY: `out` is non-null, aligned and writable for `W18_ICD_NUMERIC_COUNT`
        // values.
        unsafe {
            std::ptr::copy_nonoverlapping(W18_ICD_DEFAULTS.as_ptr(), out, W18_ICD_NUMERIC_COUNT)
        };
        written
    })
    .unwrap_or(-1)
}

/// Normalizes the 35 numeric `IngestionConfigurationData` fields (booleans to 0/1).
/// Returns the number of values written or -1 for invalid input. `raw` and `out` may
/// alias.
///
/// # Safety
/// `raw` must be readable for `count` and `out` writable for `out_count` `int64_t`s.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_icd_normalize(
    raw: *const i64,
    count: usize,
    out: *mut i64,
    out_count: usize,
) -> i32 {
    std::panic::catch_unwind(|| {
        if count != W18_ICD_NUMERIC_COUNT
            || out_count < W18_ICD_NUMERIC_COUNT
            || !w18_slice_ok(raw, count)
            || !w18_slice_ok(out.cast_const(), out_count)
        {
            return -1;
        }
        let mut values = [0i64; W18_ICD_NUMERIC_COUNT];
        // SAFETY: `raw` is non-null, aligned and readable for `W18_ICD_NUMERIC_COUNT`
        // values; `values` is a distinct local.
        unsafe { std::ptr::copy(raw, values.as_mut_ptr(), W18_ICD_NUMERIC_COUNT) };
        let normalized = w18_icd_normalize(&values);
        // SAFETY: `out` is non-null, aligned and writable for `W18_ICD_NUMERIC_COUNT`
        // values.
        unsafe { std::ptr::copy(normalized.as_ptr(), out, W18_ICD_NUMERIC_COUNT) };
        W18_ICD_NUMERIC_COUNT as i32
    })
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave18_additional_archive_media_ingestion_config_tests {
    use super::*;

    #[test]
    fn analysis_code_matches_php_branches() {
        assert_eq!(w18_analysis_code(false, b"Bad", false, false, 0), 2);
        assert_eq!(w18_analysis_code(false, b"", true, true, 1), 3);
        assert_eq!(w18_analysis_code(false, b"0", false, false, 0), 3);
        assert_eq!(w18_analysis_code(true, b"0", false, false, 0), 2);
        assert_eq!(w18_analysis_code(true, b"x", true, true, 1), 2);
        assert_eq!(w18_analysis_code(true, b"", false, false, 5), 0);
        assert_eq!(w18_analysis_code(true, b"", false, true, 0), 0);
        assert_eq!(w18_analysis_code(true, b"", false, true, -1), 1);
        assert_eq!(w18_analysis_code(true, b"", true, false, 0), 1);
        assert_eq!(
            unsafe { nntmux_aps_analysis_code(1, std::ptr::null(), 0, 0, 1, 1) },
            1
        );
        assert_eq!(
            unsafe { nntmux_aps_analysis_code(1, std::ptr::null(), 3, 0, 0, 0) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_aps_analysis_code(1, b"x".as_ptr(), usize::MAX, 0, 0, 0) },
            -1
        );
    }

    #[test]
    fn real_size_of_finds_first_pair_and_validates() {
        let mut packed = Vec::new();
        for (index, size) in [(3i64, 100i64), (-1, i64::MAX), (3, 7)] {
            packed.extend_from_slice(&index.to_le_bytes());
            packed.extend_from_slice(&size.to_le_bytes());
        }
        assert_eq!(w18_real_size_of(&packed, 3), Some(Some(100)));
        assert_eq!(w18_real_size_of(&packed, -1), Some(Some(i64::MAX)));
        assert_eq!(w18_real_size_of(&packed, 9), Some(None));
        assert_eq!(w18_real_size_of(&packed[..15], 3), None);
        assert_eq!(w18_real_size_of(b"", 0), Some(None));

        let mut out = 0i64;
        assert_eq!(
            unsafe { nntmux_msp_real_size_of(packed.as_ptr(), packed.len(), -1, &mut out) },
            1
        );
        assert_eq!(out, i64::MAX);
        assert_eq!(
            unsafe { nntmux_msp_real_size_of(packed.as_ptr(), packed.len(), 8, &mut out) },
            0
        );
        assert_eq!(
            unsafe { nntmux_msp_real_size_of(packed.as_ptr(), 17, 3, &mut out) },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_msp_real_size_of(packed.as_ptr(), packed.len(), 3, std::ptr::null_mut())
            },
            -1
        );
        let mut words = [0i64; 2];
        let misaligned = unsafe { words.as_mut_ptr().cast::<u8>().add(1) }.cast::<i64>();
        assert_eq!(
            unsafe { nntmux_msp_real_size_of(packed.as_ptr(), packed.len(), 3, misaligned) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_msp_real_size_of(std::ptr::null(), 16, 3, &mut out) },
            -1
        );
    }

    #[test]
    fn download_kind_preview_classification() {
        for value in [&b"par2-index"[..], b"media-source", b"jpg"] {
            assert_eq!(w18_download_kind_is_preview(value), Some(true));
        }
        for value in [&b"sample"[..], b"media-info", b"audio", b"compressed"] {
            assert_eq!(w18_download_kind_is_preview(value), Some(false));
        }
        assert_eq!(w18_download_kind_is_preview(b"JPG"), None);
        assert_eq!(w18_download_kind_is_preview(b""), None);
        assert_eq!(
            unsafe { nntmux_dk_is_preview_source(b"jpg".as_ptr(), 3) },
            1
        );
        assert_eq!(
            unsafe { nntmux_dk_is_preview_source(std::ptr::null(), 3) },
            -1
        );
    }

    #[test]
    fn image_result_mask_and_contract() {
        assert_eq!(w18_image_result(true, -5, i64::MAX), (31, [-5, i64::MAX]));
        assert_eq!(w18_image_result(false, 9, 9), (32, [0, 0]));
        let mut out = [7i64; 3];
        assert_eq!(
            unsafe { nntmux_ipr_build(1, 320, 240, out.as_mut_ptr(), 3) },
            31
        );
        assert_eq!(out, [320, 240, 7]);
        assert_eq!(
            unsafe { nntmux_ipr_build(0, 320, 240, out.as_mut_ptr(), 2) },
            32
        );
        assert_eq!(out, [0, 0, 7]);
        assert_eq!(
            unsafe { nntmux_ipr_build(2, 1, 1, out.as_mut_ptr(), 2) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_ipr_build(1, 1, 1, out.as_mut_ptr(), 1) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_ipr_build(1, 1, 1, std::ptr::null_mut(), 2) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_ipr_build(1, 1, 1, out.as_mut_ptr(), usize::MAX) },
            -1
        );
    }

    #[test]
    fn ingestion_defaults_and_normalize() {
        let mut out = [0i64; W18_ICD_NUMERIC_COUNT];
        let mut date = [0u8; 16];
        assert_eq!(
            unsafe {
                nntmux_icd_defaults(out.as_mut_ptr(), out.len(), date.as_mut_ptr(), date.len())
            },
            10
        );
        assert_eq!(out, W18_ICD_DEFAULTS);
        assert_eq!(&date[..10], b"2012-06-24");
        assert_eq!(
            unsafe { nntmux_icd_defaults(out.as_mut_ptr(), out.len(), date.as_mut_ptr(), 4) },
            -10
        );
        assert_eq!(
            unsafe { nntmux_icd_defaults(out.as_mut_ptr(), 34, date.as_mut_ptr(), 16) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_icd_defaults(out.as_mut_ptr(), 35, std::ptr::null_mut(), 16) },
            -1
        );

        let mut raw = [i64::MIN; W18_ICD_NUMERIC_COUNT];
        raw[7] = 0;
        let normalized = w18_icd_normalize(&raw);
        for (slot, value) in normalized.iter().enumerate() {
            let expected = if slot == 7 {
                0
            } else if W18_ICD_BOOL_SLOTS.contains(&slot) {
                1
            } else {
                i64::MIN
            };
            assert_eq!(*value, expected, "slot {slot}");
        }
        let ptr = raw.as_mut_ptr();
        assert_eq!(unsafe { nntmux_icd_normalize(ptr, 35, ptr, 35) }, 35);
        assert_eq!(raw, normalized);
        assert_eq!(unsafe { nntmux_icd_normalize(ptr, 34, ptr, 35) }, -1);
        assert_eq!(unsafe { nntmux_icd_normalize(ptr, 35, ptr, 34) }, -1);
        assert_eq!(
            unsafe { nntmux_icd_normalize(std::ptr::null(), 35, ptr, 35) },
            -1
        );
        let mut bytes = [0u8; 8 * 36 + 8];
        let base = bytes.as_mut_ptr();
        let offset = base.align_offset(8) + 1;
        let misaligned = unsafe { base.add(offset) }.cast::<i64>();
        assert_eq!(
            unsafe { nntmux_icd_normalize(misaligned, 35, out.as_mut_ptr(), 35) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_icd_normalize(out.as_ptr(), 35, misaligned, 35) },
            -1
        );
    }
}

// ---------------------------------------------------------------------------
// Wave 19: ProcessingOutcome, PostProcessingConfigurationData, FileCompletionStatus,
// ImageAssetProfile and BinariesConfig helpers.
// ---------------------------------------------------------------------------

/// Non-null, aligned typed slice of `count` elements whose byte span fits `isize`.
#[inline]
fn w19pc_slice_ok<T>(ptr: *const T, count: usize) -> bool {
    !ptr.is_null()
        && ptr.is_aligned()
        && count
            .checked_mul(std::mem::size_of::<T>())
            .is_some_and(|bytes| {
                bytes <= isize::MAX as usize && (ptr as usize).checked_add(bytes).is_some()
            })
}

/// Borrow `len` input bytes; a null pointer is only accepted for an empty input.
///
/// # Safety
/// A non-null `ptr` must be readable for `len` bytes.
#[inline]
unsafe fn w19pc_bytes<'a>(ptr: *const u8, len: usize) -> Option<&'a [u8]> {
    if len > isize::MAX as usize {
        return None;
    }
    if ptr.is_null() {
        return (len == 0).then_some(&[][..]);
    }
    (ptr as usize).checked_add(len)?;
    // SAFETY: `ptr` is non-null and the caller guarantees `len` readable bytes.
    Some(unsafe { std::slice::from_raw_parts(ptr, len) })
}

/// Copy `bytes` to `out` and return the length, `-(len)` when `cap` is too small
/// (`-1` stays reserved for invalid input), or -1 for a null/oversized output.
///
/// # Safety
/// A non-null `out` must be writable for `cap` bytes.
#[inline]
unsafe fn w19pc_write(out: *mut u8, cap: usize, bytes: &[u8]) -> isize {
    if out.is_null() || cap > isize::MAX as usize || (out as usize).checked_add(cap).is_none() {
        return -1;
    }
    if bytes.len() > cap {
        return -(bytes.len().max(2) as isize);
    }
    // SAFETY: `out` is non-null and writable for `cap >= bytes.len()` bytes; `bytes` is a
    // distinct Rust allocation or static.
    unsafe { std::slice::from_raw_parts_mut(out, bytes.len()) }.copy_from_slice(bytes);
    bytes.len() as isize
}

/// `ProcessingOutcome` backing values in declaration order.
const W19_OUTCOMES: [&[u8]; 12] = [
    b"completed",
    b"no-useful-artifacts",
    b"passworded",
    b"group-unavailable",
    b"temporary-workspace-unavailable",
    b"timed-out",
    b"deleted-after-timeout",
    b"deleted-broken-nzb",
    b"deleted-par2-only",
    b"not-found",
    b"awaiting-late-articles",
    b"failed",
];

/// Case index plus bit 8 (`isSuccessful`) and bit 9 (`isDeleted`), or `None` when unknown.
fn w19_outcome_classify(value: &[u8]) -> Option<i32> {
    let index = W19_OUTCOMES.iter().position(|case| *case == value)?;
    let successful = matches!(index, 0..=2);
    let deleted = matches!(index, 6..=8);
    Some(index as i32 | (i32::from(successful) << 8) | (i32::from(deleted) << 9))
}

/// `ProcessingOutcome::tryFrom` / `isSuccessful` / `isDeleted` in one call: the case index
/// (bits 0-7) with bit 8 set when successful and bit 9 when deleted; -1 for an unknown value
/// or invalid input.
///
/// # Safety
/// A non-null `ptr` must be readable for `len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_pout_classify(ptr: *const u8, len: usize) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        // SAFETY: forwarded caller contract.
        unsafe { w19pc_bytes(ptr, len) }
            .and_then(w19_outcome_classify)
            .unwrap_or(-1)
    }))
    .unwrap_or(-1)
}

/// `PostProcessingConfigurationData` numeric fields (everything but `innerFileBlacklist`).
const W19_PPCD_NUMERIC_COUNT: usize = 28;

const W19_PPCD_DEFAULT_BLACKLIST: &[u8] = b"/setup.exe|password.url/i";

/// `PostProcessingConfigurationData::defaults()` numeric values (bools as 0/1), skipping
/// the string at constructor position 16.
const W19_PPCD_DEFAULTS: [i64; W19_PPCD_NUMERIC_COUNT] = [
    1,
    1,
    1,
    1,
    1,
    60,
    120,
    3,
    25,
    3,
    1,
    10,
    3,
    0,
    2,
    5,
    0,
    0,
    0,
    0,
    1_048_576,
    107_374_182_400,
    1_048_576,
    107_374_182_400,
    100,
    5,
    1,
    0,
];

/// Whether numeric position `position` holds a PHP `bool` property (`extractUsingRarInfo`,
/// `processJpg`..`saveAudioPreview`, `lookupNfo`, `lookupPar2`).
fn w19_ppcd_is_bool(position: usize) -> bool {
    matches!(position, 13 | 16..=19 | 26 | 27)
}

/// Coerce bool positions to 0/1 (PHP `(bool)` of an int); integer positions pass through.
fn w19_ppcd_normalize(raw: &[i64; W19_PPCD_NUMERIC_COUNT]) -> [i64; W19_PPCD_NUMERIC_COUNT] {
    let mut out = *raw;
    for (position, value) in out.iter_mut().enumerate() {
        if w19_ppcd_is_bool(position) {
            *value = i64::from(*value != 0);
        }
    }
    out
}

/// Write the 28 default numeric values to `out` and the default blacklist to `blacklist`;
/// returns the blacklist length, `-(needed)` when `cap` is too small, or -1 for invalid input.
///
/// # Safety
/// A non-null `out` must be aligned and writable for `out_count` values; a non-null
/// `blacklist` must be writable for `cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_ppcd_defaults(
    out: *mut i64,
    out_count: usize,
    blacklist: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_count < W19_PPCD_NUMERIC_COUNT || !w19pc_slice_ok(out.cast_const(), out_count) {
            return -1;
        }
        // SAFETY: `blacklist` validity is checked by `w19pc_write`; the caller guarantees
        // `cap` writable bytes.
        let written = unsafe { w19pc_write(blacklist, cap, W19_PPCD_DEFAULT_BLACKLIST) };
        if written <= 0 {
            return written;
        }
        // SAFETY: `out` is non-null, aligned and writable for at least 28 values.
        unsafe {
            std::ptr::copy_nonoverlapping(W19_PPCD_DEFAULTS.as_ptr(), out, W19_PPCD_NUMERIC_COUNT)
        };
        written
    }))
    .unwrap_or(-1)
}

/// Normalize the 28 numeric `PostProcessingConfigurationData` values (bool coercion); `raw`
/// and `out` may alias. Returns 28 or -1 for invalid input.
///
/// # Safety
/// Non-null pointers must be aligned and valid for their declared counts.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_ppcd_normalize(
    raw: *const i64,
    count: usize,
    out: *mut i64,
    out_count: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if count != W19_PPCD_NUMERIC_COUNT
            || out_count < W19_PPCD_NUMERIC_COUNT
            || !w19pc_slice_ok(raw, count)
            || !w19pc_slice_ok(out.cast_const(), out_count)
        {
            return -1;
        }
        let mut values = [0i64; W19_PPCD_NUMERIC_COUNT];
        // SAFETY: `raw` is non-null, aligned and readable for 28 values; `values` is local.
        unsafe { std::ptr::copy(raw, values.as_mut_ptr(), W19_PPCD_NUMERIC_COUNT) };
        let normalized = w19_ppcd_normalize(&values);
        // SAFETY: `out` is non-null, aligned and writable for 28 values.
        unsafe { std::ptr::copy(normalized.as_ptr(), out, W19_PPCD_NUMERIC_COUNT) };
        W19_PPCD_NUMERIC_COUNT as i32
    }))
    .unwrap_or(-1)
}

/// `FileCompletionStatus::tryFrom`: 0 Incomplete, 1 Complete, -1 for an unknown value.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_fcs_classify(value: i64) -> i32 {
    std::panic::catch_unwind(|| match value {
        0 | 1 => value as i32,
        _ => -1,
    })
    .unwrap_or(-1)
}

/// `FileCompletionStatus::description` for `value`; returns the length, `-(needed)` when
/// `cap` is too small, or -1 for an unknown value or invalid output.
///
/// # Safety
/// A non-null `out` must be writable for `cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_fcs_description(value: i64, out: *mut u8, cap: usize) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let text: &[u8] = match value {
            0 => b"Incomplete",
            1 => b"Complete",
            _ => return -1,
        };
        // SAFETY: forwarded caller contract.
        unsafe { w19pc_write(out, cap, text) }
    }))
    .unwrap_or(-1)
}

/// `ImageAssetProfile` case names in declaration order with `[maxWidth, maxHeight]`
/// (`-1` for PHP `null`).
const W19_IAP_PROFILES: [(&[u8], [i64; 2]); 5] = [
    (b"Original", [-1, -1]),
    (b"MetadataCover", [250, 250]),
    (b"Backdrop", [1920, 1024]),
    (b"Preview", [800, 600]),
    (b"Sample", [650, 650]),
];

fn w19_iap_resolve(name: &[u8]) -> Option<(usize, [i64; 2])> {
    W19_IAP_PROFILES
        .iter()
        .position(|(case, _)| *case == name)
        .map(|index| (index, W19_IAP_PROFILES[index].1))
}

/// Resolve an `ImageAssetProfile` case name: writes `[maxWidth, maxHeight]` (`-1` for null)
/// to `out` and returns the case index, or -1 for an unknown name or invalid input.
///
/// # Safety
/// A non-null `name` must be readable for `len` bytes; a non-null `out` must be aligned and
/// writable for `out_count` values.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_iap_bounds(
    name: *const u8,
    len: usize,
    out: *mut i64,
    out_count: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_count < 2 || !w19pc_slice_ok(out.cast_const(), out_count) {
            return -1;
        }
        // SAFETY: forwarded caller contract.
        let Some((index, bounds)) = unsafe { w19pc_bytes(name, len) }.and_then(w19_iap_resolve)
        else {
            return -1;
        };
        // SAFETY: `out` is non-null, aligned and writable for at least 2 values.
        unsafe { std::ptr::copy_nonoverlapping(bounds.as_ptr(), out, 2) };
        index as i32
    }))
    .unwrap_or(-1)
}

/// `BinariesConfig::obfuscationMinArticles`.
fn w19_bcfg_obfuscation(configured: i64) -> i64 {
    if configured <= 0 {
        0
    } else {
        configured.max(8)
    }
}

/// `BinariesConfig` chunk settings in `normalizeChunkSettings` order.
const W19_BCFG_CHUNK_COUNT: usize = 7;

fn w19_bcfg_normalize(raw: &[i64; W19_BCFG_CHUNK_COUNT]) -> [i64; W19_BCFG_CHUNK_COUNT] {
    [
        raw[0].clamp(50, 2000),
        raw[1].clamp(50, 1000),
        raw[2].clamp(50, 2000),
        raw[3].clamp(500, 20000),
        raw[4].max(1),
        raw[5].max(1),
        w19_bcfg_obfuscation(raw[6]),
    ]
}

/// `BinariesConfig` constructor defaults in declaration order (bools as 0/1).
const W19_BCFG_FIELD_COUNT: usize = 16;

const W19_BCFG_DEFAULTS: [i64; W19_BCFG_FIELD_COUNT] = [
    20000, 1, 1, 0, 50000, 3, 15000, 3, 0, 500, 500, 500, 5000, 1000, 300, 16,
];

/// `BinariesConfig::obfuscationMinArticles`: 0 when `configured <= 0`, else at least 8.
/// Never negative, so -1 signals a caught panic.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_bcfg_obfuscation_min_articles(configured: i64) -> i64 {
    std::panic::catch_unwind(|| w19_bcfg_obfuscation(configured)).unwrap_or(-1)
}

/// Clamp the 7 `BinariesConfig` chunk settings; `raw` and `out` may alias. Returns 7 or -1
/// for invalid input.
///
/// # Safety
/// Non-null pointers must be aligned and valid for their declared counts.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_bcfg_normalize(
    raw: *const i64,
    count: usize,
    out: *mut i64,
    out_count: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if count != W19_BCFG_CHUNK_COUNT
            || out_count < W19_BCFG_CHUNK_COUNT
            || !w19pc_slice_ok(raw, count)
            || !w19pc_slice_ok(out.cast_const(), out_count)
        {
            return -1;
        }
        let mut values = [0i64; W19_BCFG_CHUNK_COUNT];
        // SAFETY: `raw` is non-null, aligned and readable for 7 values; `values` is local.
        unsafe { std::ptr::copy(raw, values.as_mut_ptr(), W19_BCFG_CHUNK_COUNT) };
        let normalized = w19_bcfg_normalize(&values);
        // SAFETY: `out` is non-null, aligned and writable for 7 values.
        unsafe { std::ptr::copy(normalized.as_ptr(), out, W19_BCFG_CHUNK_COUNT) };
        W19_BCFG_CHUNK_COUNT as i32
    }))
    .unwrap_or(-1)
}

/// Write the 16 `BinariesConfig` constructor defaults (bools as 0/1); returns 16 or -1.
///
/// # Safety
/// A non-null `out` must be aligned and writable for `out_count` values.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_bcfg_defaults(out: *mut i64, out_count: usize) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_count < W19_BCFG_FIELD_COUNT || !w19pc_slice_ok(out.cast_const(), out_count) {
            return -1;
        }
        // SAFETY: `out` is non-null, aligned and writable for at least 16 values.
        unsafe {
            std::ptr::copy_nonoverlapping(W19_BCFG_DEFAULTS.as_ptr(), out, W19_BCFG_FIELD_COUNT)
        };
        W19_BCFG_FIELD_COUNT as i32
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave19_postprocess_config_outcomes_assets_tests {
    use super::*;

    /// An `i64` pointer offset by one byte from an aligned buffer.
    fn misaligned_i64(buffer: &mut [i64]) -> *mut i64 {
        buffer
            .as_mut_ptr()
            .cast::<u8>()
            .wrapping_add(1)
            .cast::<i64>()
    }

    #[test]
    fn outcome_classify_matches_php_predicates() {
        for (index, value) in W19_OUTCOMES.iter().enumerate() {
            let code = unsafe { nntmux_pout_classify(value.as_ptr(), value.len()) };
            assert_eq!(code & 0xff, index as i32);
            assert_eq!(code & 0x100 != 0, index <= 2, "successful {index}");
            assert_eq!(
                code & 0x200 != 0,
                (6..=8).contains(&index),
                "deleted {index}"
            );
        }
        assert_eq!(
            unsafe { nntmux_pout_classify(b"Completed".as_ptr(), 9) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_pout_classify(b"completed ".as_ptr(), 10) },
            -1
        );
        assert_eq!(unsafe { nntmux_pout_classify(std::ptr::null(), 0) }, -1);
        assert_eq!(unsafe { nntmux_pout_classify(std::ptr::null(), 3) }, -1);
        assert_eq!(
            unsafe { nntmux_pout_classify(b"x".as_ptr(), isize::MAX as usize + 1) },
            -1
        );
    }

    #[test]
    fn ppcd_defaults_and_normalize() {
        let mut out = [0i64; W19_PPCD_NUMERIC_COUNT];
        let mut text = [0u8; 64];
        let written = unsafe {
            nntmux_ppcd_defaults(out.as_mut_ptr(), out.len(), text.as_mut_ptr(), text.len())
        };
        assert_eq!(written, W19_PPCD_DEFAULT_BLACKLIST.len() as isize);
        assert_eq!(&text[..written as usize], W19_PPCD_DEFAULT_BLACKLIST);
        assert_eq!(out, W19_PPCD_DEFAULTS);
        assert_eq!(
            unsafe { nntmux_ppcd_defaults(out.as_mut_ptr(), out.len(), text.as_mut_ptr(), 4) },
            -(W19_PPCD_DEFAULT_BLACKLIST.len() as isize)
        );
        assert_eq!(
            unsafe { nntmux_ppcd_defaults(out.as_mut_ptr(), 27, text.as_mut_ptr(), 64) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_ppcd_defaults(out.as_mut_ptr(), out.len(), std::ptr::null_mut(), 64) },
            -1
        );

        let mut raw = [7i64; W19_PPCD_NUMERIC_COUNT];
        raw[13] = -3;
        raw[16] = 0;
        let mut normalized = [0i64; W19_PPCD_NUMERIC_COUNT];
        assert_eq!(
            unsafe { nntmux_ppcd_normalize(raw.as_ptr(), raw.len(), normalized.as_mut_ptr(), 28) },
            28
        );
        for (position, value) in normalized.iter().enumerate() {
            let expected = match position {
                16 => 0,
                p if w19_ppcd_is_bool(p) => 1,
                _ => 7,
            };
            assert_eq!(*value, expected, "position {position}");
        }
        assert_eq!(
            (0..W19_PPCD_NUMERIC_COUNT)
                .filter(|p| w19_ppcd_is_bool(*p))
                .collect::<Vec<_>>(),
            [13, 16, 17, 18, 19, 26, 27]
        );
        let aliased = raw.as_mut_ptr();
        assert_eq!(
            unsafe { nntmux_ppcd_normalize(aliased, 28, aliased, 28) },
            28
        );
        assert_eq!(raw[13], 1);
        assert_eq!(
            unsafe { nntmux_ppcd_normalize(raw.as_ptr(), 27, normalized.as_mut_ptr(), 28) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_ppcd_normalize(std::ptr::null(), 28, normalized.as_mut_ptr(), 28) },
            -1
        );
    }

    #[test]
    fn file_completion_status_classify_and_description() {
        assert_eq!(nntmux_fcs_classify(0), 0);
        assert_eq!(nntmux_fcs_classify(1), 1);
        assert_eq!(nntmux_fcs_classify(2), -1);
        assert_eq!(nntmux_fcs_classify(-1), -1);
        assert_eq!(nntmux_fcs_classify(i64::MAX), -1);
        let mut out = [0u8; 16];
        let written = unsafe { nntmux_fcs_description(0, out.as_mut_ptr(), out.len()) };
        assert_eq!(&out[..written as usize], b"Incomplete");
        let written = unsafe { nntmux_fcs_description(1, out.as_mut_ptr(), out.len()) };
        assert_eq!(&out[..written as usize], b"Complete");
        assert_eq!(
            unsafe { nntmux_fcs_description(1, out.as_mut_ptr(), 3) },
            -8
        );
        assert_eq!(
            unsafe { nntmux_fcs_description(5, out.as_mut_ptr(), 16) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_fcs_description(0, std::ptr::null_mut(), 16) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_fcs_description(0, out.as_mut_ptr(), isize::MAX as usize + 1) },
            -1
        );
    }

    #[test]
    fn image_asset_profile_bounds() {
        let mut out = [0i64; 2];
        let expected: [(&[u8], i32, [i64; 2]); 5] = [
            (b"Original", 0, [-1, -1]),
            (b"MetadataCover", 1, [250, 250]),
            (b"Backdrop", 2, [1920, 1024]),
            (b"Preview", 3, [800, 600]),
            (b"Sample", 4, [650, 650]),
        ];
        for (name, index, bounds) in expected {
            assert_eq!(
                unsafe { nntmux_iap_bounds(name.as_ptr(), name.len(), out.as_mut_ptr(), 2) },
                index
            );
            assert_eq!(out, bounds);
        }
        assert_eq!(
            unsafe { nntmux_iap_bounds(b"sample".as_ptr(), 6, out.as_mut_ptr(), 2) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_iap_bounds(b"Sample".as_ptr(), 6, out.as_mut_ptr(), 1) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_iap_bounds(std::ptr::null(), 6, out.as_mut_ptr(), 2) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_iap_bounds(b"Sample".as_ptr(), 6, std::ptr::null_mut(), 2) },
            -1
        );
    }

    #[test]
    fn binaries_config_obfuscation_normalize_and_defaults() {
        for (configured, expected) in [
            (i64::MIN, 0),
            (-1, 0),
            (0, 0),
            (1, 8),
            (7, 8),
            (8, 8),
            (16, 16),
            (i64::MAX, i64::MAX),
        ] {
            assert_eq!(nntmux_bcfg_obfuscation_min_articles(configured), expected);
        }
        let raw = [0i64, 5000, i64::MAX, i64::MIN, 0, -5, 3];
        let mut out = [0i64; W19_BCFG_CHUNK_COUNT];
        assert_eq!(
            unsafe { nntmux_bcfg_normalize(raw.as_ptr(), 7, out.as_mut_ptr(), 7) },
            7
        );
        assert_eq!(out, [50, 1000, 2000, 500, 1, 1, 8]);
        let mut aliased = [500i64, 500, 500, 5000, 1000, 300, 16];
        let ptr = aliased.as_mut_ptr();
        assert_eq!(unsafe { nntmux_bcfg_normalize(ptr, 7, ptr, 7) }, 7);
        assert_eq!(aliased, [500, 500, 500, 5000, 1000, 300, 16]);
        assert_eq!(
            unsafe { nntmux_bcfg_normalize(raw.as_ptr(), 6, out.as_mut_ptr(), 7) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_bcfg_normalize(raw.as_ptr(), 7, std::ptr::null_mut(), 7) },
            -1
        );
        let mut defaults = [0i64; W19_BCFG_FIELD_COUNT];
        assert_eq!(
            unsafe { nntmux_bcfg_defaults(defaults.as_mut_ptr(), 16) },
            16
        );
        assert_eq!(defaults, W19_BCFG_DEFAULTS);
        assert_eq!(
            unsafe { nntmux_bcfg_defaults(defaults.as_mut_ptr(), 15) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_bcfg_defaults(std::ptr::null_mut(), 16) },
            -1
        );
        assert!(!w19pc_slice_ok(defaults.as_ptr(), usize::MAX / 4));
    }

    #[test]
    fn unaligned_pointers_are_rejected() {
        let mut buffer = [0i64; 40];
        let misaligned = misaligned_i64(&mut buffer);
        let mut text = [0u8; 64];
        assert_eq!(
            unsafe { nntmux_ppcd_defaults(misaligned, 28, text.as_mut_ptr(), 64) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_ppcd_normalize(misaligned.cast_const(), 28, buffer.as_mut_ptr(), 28) },
            -1
        );
        let mut aligned = [0i64; 28];
        assert_eq!(
            unsafe { nntmux_ppcd_normalize(aligned.as_ptr(), 28, misaligned, 28) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_iap_bounds(b"Sample".as_ptr(), 6, misaligned, 2) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_bcfg_normalize(misaligned.cast_const(), 7, aligned.as_mut_ptr(), 7) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_bcfg_normalize(aligned.as_ptr(), 7, misaligned, 7) },
            -1
        );
        assert_eq!(unsafe { nntmux_bcfg_defaults(misaligned, 16) }, -1);
        assert_eq!(aligned, [0i64; 28]);
    }
}
// ---------------------------------------------------------------------------
// Wave 20: ConfigurationDomain, ConfigurationProvider, DomainConfigurationRepository,
// ProcessingRuntimeStateRepository and PostProcessService helpers.
// ---------------------------------------------------------------------------

/// `ConfigurationDomain` backing values in declaration order.
const W20CD_DOMAINS: [&[u8]; 6] = [
    b"site",
    b"registration",
    b"ingestion",
    b"post-processing",
    b"metadata",
    b"tmux",
];

/// `ConfigurationDomain::label()` per case.
const W20CD_LABELS: [&[u8]; 6] = [
    b"Site",
    b"Registration",
    b"Ingestion",
    b"Post Processing",
    b"Metadata",
    b"Tmux",
];

/// `ConfigurationDomain::table()` per case.
const W20CD_TABLES: [&[u8]; 6] = [
    b"site_configurations",
    b"registration_configurations",
    b"ingestion_configurations",
    b"post_processing_configurations",
    b"metadata_configurations",
    b"tmux_configurations",
];

/// `DomainConfigurationRepository::modelClass()` per case.
const W20CD_MODELS: [&[u8]; 6] = [
    b"App\\Models\\SiteConfiguration",
    b"App\\Models\\RegistrationConfiguration",
    b"App\\Models\\IngestionConfiguration",
    b"App\\Models\\PostProcessingConfiguration",
    b"App\\Models\\MetadataConfiguration",
    b"App\\Models\\TmuxConfiguration",
];

/// `ConfigurationProvider::cacheKey()` prefix.
const W20CD_CACHE_PREFIX: &[u8] = b"configuration:";

/// Text field selectors for `nntmux_cfgd_text`.
const W20CD_FIELD_LABEL: i32 = 0;
const W20CD_FIELD_TABLE: i32 = 1;
const W20CD_FIELD_CACHE_KEY: i32 = 2;
const W20CD_FIELD_MODEL: i32 = 3;

/// Case index for a backing value, or `None` when it names no domain.
fn w20cd_index(value: &[u8]) -> Option<usize> {
    W20CD_DOMAINS.iter().position(|case| *case == value)
}

/// `ConfigurationDomain::tryFrom`: the case index, or -1 for an unknown value or invalid
/// input.
///
/// # Safety
/// A non-null `ptr` must be readable for `len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_cfgd_resolve(ptr: *const u8, len: usize) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        // SAFETY: forwarded caller contract.
        unsafe { w19pc_bytes(ptr, len) }
            .and_then(w20cd_index)
            .map_or(-1, |index| index as i32)
    }))
    .unwrap_or(-1)
}

/// Write `field` (0 label, 1 table, 2 cache key, 3 model class) of the domain whose backing
/// value is `ptr[..len]`; returns the length, `-(needed)` when `cap` is too small, or -1 for an
/// unknown value/field or invalid input.
///
/// # Safety
/// A non-null `ptr` must be readable for `len` bytes; a non-null `out` must be writable for
/// `cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_cfgd_text(
    ptr: *const u8,
    len: usize,
    field: i32,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        // SAFETY: forwarded caller contract.
        let Some(index) = (unsafe { w19pc_bytes(ptr, len) }).and_then(w20cd_index) else {
            return -1;
        };
        let mut key = [0u8; 64];
        let text: &[u8] = match field {
            W20CD_FIELD_LABEL => W20CD_LABELS[index],
            W20CD_FIELD_TABLE => W20CD_TABLES[index],
            W20CD_FIELD_MODEL => W20CD_MODELS[index],
            W20CD_FIELD_CACHE_KEY => {
                let value = W20CD_DOMAINS[index];
                let total = W20CD_CACHE_PREFIX.len() + value.len();
                key[..W20CD_CACHE_PREFIX.len()].copy_from_slice(W20CD_CACHE_PREFIX);
                key[W20CD_CACHE_PREFIX.len()..total].copy_from_slice(value);
                &key[..total]
            }
            _ => return -1,
        };
        // SAFETY: `out` validity is checked by `w19pc_write`; the caller guarantees `cap`
        // writable bytes.
        unsafe { w19pc_write(out, cap, text) }
    }))
    .unwrap_or(-1)
}

/// Positions of the first occurrence of each distinct entry (PHP `array_unique` with
/// `SORT_STRING` keeps the first of equal strings).
fn w20cd_unique_positions<T: std::hash::Hash + Eq>(items: &[T]) -> Vec<usize> {
    let mut seen = std::collections::HashSet::with_capacity(items.len());
    items
        .iter()
        .enumerate()
        .filter_map(|(position, item)| seen.insert(item).then_some(position))
        .collect()
}

/// Deduplicate `count` strings packed in `buf` with byte lengths `lens` (which must sum to
/// `buf_len`), writing the input index of each first occurrence to `out`. Returns the number
/// of unique entries or -1 for invalid input.
///
/// # Safety
/// A non-null `buf` must be readable for `buf_len` bytes; non-null `lens`/`out` must be
/// aligned and valid for `count`/`out_count` values.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_dcr_unique_strings(
    buf: *const u8,
    buf_len: usize,
    lens: *const i64,
    count: usize,
    out: *mut i64,
    out_count: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if count == 0
            || out_count < count
            || !w19pc_slice_ok(lens, count)
            || !w19pc_slice_ok(out.cast_const(), out_count)
        {
            return -1;
        }
        // SAFETY: forwarded caller contract.
        let Some(bytes) = (unsafe { w19pc_bytes(buf, buf_len) }) else {
            return -1;
        };
        // SAFETY: `lens` is non-null, aligned and readable for `count` values.
        let lens = unsafe { std::slice::from_raw_parts(lens, count) };
        let mut items = Vec::with_capacity(count);
        let mut offset = 0usize;
        for &length in lens {
            let Ok(length) = usize::try_from(length) else {
                return -1;
            };
            let Some(end) = offset.checked_add(length).filter(|end| *end <= bytes.len()) else {
                return -1;
            };
            items.push(&bytes[offset..end]);
            offset = end;
        }
        if offset != bytes.len() {
            return -1;
        }
        let unique = w20cd_unique_positions(&items);
        // SAFETY: `out` is non-null, aligned and writable for `out_count >= count` values.
        let out = unsafe { std::slice::from_raw_parts_mut(out, out_count) };
        for (slot, position) in out.iter_mut().zip(&unique) {
            *slot = *position as i64;
        }
        unique.len() as isize
    }))
    .unwrap_or(-1)
}

/// Deduplicate `count` integers in first-occurrence order into `out`; `values` and `out` may
/// alias. Returns the number of unique values or -1 for invalid input.
///
/// # Safety
/// Non-null pointers must be aligned and valid for their declared counts.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_dcr_unique_ints(
    values: *const i64,
    count: usize,
    out: *mut i64,
    out_count: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if count == 0
            || out_count < count
            || !w19pc_slice_ok(values, count)
            || !w19pc_slice_ok(out.cast_const(), out_count)
        {
            return -1;
        }
        let mut input = vec![0i64; count];
        // SAFETY: `values` is non-null, aligned and readable for `count` values; `input` is a
        // distinct allocation.
        unsafe { std::ptr::copy_nonoverlapping(values, input.as_mut_ptr(), count) };
        let unique = w20cd_unique_positions(&input);
        // SAFETY: `out` is non-null, aligned and writable for `out_count >= count` values;
        // `input` was copied out first so aliasing is harmless.
        let out = unsafe { std::slice::from_raw_parts_mut(out, out_count) };
        for (slot, position) in out.iter_mut().zip(&unique) {
            *slot = input[*position];
        }
        unique.len() as isize
    }))
    .unwrap_or(-1)
}

/// `ProcessingRuntimeStateRepository::monitorPaths()` presence mask: bit `i` is set when
/// `lens[i] >= 0` (-1 marks a null path). Requires exactly 3 lengths; -1 for invalid input.
///
/// # Safety
/// A non-null `lens` must be aligned and readable for `count` values.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_prs_monitor_mask(lens: *const i64, count: usize) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if count != 3 || !w19pc_slice_ok(lens, count) {
            return -1;
        }
        // SAFETY: `lens` is non-null, aligned and readable for 3 values.
        let lens = unsafe { std::slice::from_raw_parts(lens, count) };
        let mut mask = 0i32;
        for (bit, &length) in lens.iter().enumerate() {
            match length {
                -1 => {}
                0.. => mask |= 1 << bit,
                _ => return -1,
            }
        }
        mask
    }))
    .unwrap_or(-1)
}

/// Fallback runtime state integers: `[id, tmux_running, stop_requested]`.
const W20CD_RUNTIME_DEFAULTS: [i64; 3] = [1, 0, 0];

/// Write the fallback runtime state integers to `out`; returns 3 or -1 for invalid input.
///
/// # Safety
/// A non-null `out` must be aligned and writable for `out_count` values.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_prs_defaults(out: *mut i64, out_count: usize) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_count < W20CD_RUNTIME_DEFAULTS.len() || !w19pc_slice_ok(out.cast_const(), out_count)
        {
            return -1;
        }
        // SAFETY: `out` is non-null, aligned and writable for at least 3 values.
        unsafe {
            std::ptr::copy_nonoverlapping(
                W20CD_RUNTIME_DEFAULTS.as_ptr(),
                out,
                W20CD_RUNTIME_DEFAULTS.len(),
            )
        };
        W20CD_RUNTIME_DEFAULTS.len() as i32
    }))
    .unwrap_or(-1)
}

/// PHP whitespace accepted around numeric strings.
fn w20cd_is_php_space(byte: u8) -> bool {
    matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c)
}

/// PHP `is_numeric($string) ? (int) $string : null` for 64-bit PHP 8.
fn w20cd_php_numeric_int(value: &[u8]) -> Option<i64> {
    let start = value.iter().position(|byte| !w20cd_is_php_space(*byte))?;
    let end = value.iter().rposition(|byte| !w20cd_is_php_space(*byte))? + 1;
    let body = &value[start..end];
    let unsigned = match body.first() {
        Some(b'+' | b'-') => &body[1..],
        _ => body,
    };
    let int_digits = unsigned
        .iter()
        .take_while(|byte| byte.is_ascii_digit())
        .count();
    let mut cursor = int_digits;
    let mut fraction_digits = 0;
    let mut is_integer = true;
    if unsigned.get(cursor) == Some(&b'.') {
        is_integer = false;
        cursor += 1;
        fraction_digits = unsigned[cursor..]
            .iter()
            .take_while(|byte| byte.is_ascii_digit())
            .count();
        cursor += fraction_digits;
    }
    if int_digits == 0 && fraction_digits == 0 {
        return None;
    }
    if matches!(unsigned.get(cursor), Some(b'e' | b'E')) {
        is_integer = false;
        cursor += 1;
        if matches!(unsigned.get(cursor), Some(b'+' | b'-')) {
            cursor += 1;
        }
        let exponent_digits = unsigned[cursor..]
            .iter()
            .take_while(|byte| byte.is_ascii_digit())
            .count();
        if exponent_digits == 0 {
            return None;
        }
        cursor += exponent_digits;
    }
    if cursor != unsigned.len() {
        return None;
    }
    // The grammar above is ASCII-only, so the body is valid UTF-8.
    let text = std::str::from_utf8(body).ok()?;
    if is_integer && let Ok(parsed) = text.parse::<i64>() {
        return Some(parsed);
    }
    // Float strings and overflowing integers go through a double; `as` saturates and maps
    // NaN to 0 like `zend_dval_to_lval_cap`.
    text.parse::<f64>().ok().map(|parsed| parsed as i64)
}

/// `PostProcessService::processTv()` lookup mode: `kind` 0 = null, 1 = int (`int_value`),
/// 2 = string (`ptr[..len]`). A numeric input resolves to `(int) $processTV`; otherwise
/// `default_value` is used when `has_default != 0`. Writes the resolved mode to
/// `out_resolved` and returns bit 0 = renamed only (`resolved === 2`) and bit 1 = resolved
/// from the input; -2 when the default is needed but absent; -1 for invalid input.
///
/// # Safety
/// A non-null `ptr` must be readable for `len` bytes; a non-null `out_resolved` must be
/// aligned and writable.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_pps_tv_lookup(
    kind: i32,
    int_value: i64,
    ptr: *const u8,
    len: usize,
    default_value: i64,
    has_default: i32,
    out_resolved: *mut i64,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !w19pc_slice_ok(out_resolved.cast_const(), 1) {
            return -1;
        }
        let numeric = match kind {
            0 => None,
            1 => Some(int_value),
            2 => {
                // SAFETY: forwarded caller contract.
                let Some(bytes) = (unsafe { w19pc_bytes(ptr, len) }) else {
                    return -1;
                };
                w20cd_php_numeric_int(bytes)
            }
            _ => return -1,
        };
        let (resolved, from_input) = match numeric {
            Some(value) => (value, true),
            None if has_default != 0 => (default_value, false),
            None => return -2,
        };
        // SAFETY: `out_resolved` is non-null, aligned and writable for one value.
        unsafe { out_resolved.write(resolved) };
        i32::from(resolved == 2) | (i32::from(from_input) << 1)
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave20_additional_config_domain_runtime_postprocess_tests {
    use super::*;

    fn misaligned(buffer: &mut [i64]) -> *mut i64 {
        buffer
            .as_mut_ptr()
            .cast::<u8>()
            .wrapping_add(1)
            .cast::<i64>()
    }

    fn text(value: &[u8], field: i32) -> Option<String> {
        let mut out = [0u8; 64];
        let written =
            unsafe { nntmux_cfgd_text(value.as_ptr(), value.len(), field, out.as_mut_ptr(), 64) };
        (written > 0).then(|| String::from_utf8(out[..written as usize].to_vec()).unwrap())
    }

    #[test]
    fn domains_resolve_and_map_every_text_field() {
        for (index, value) in W20CD_DOMAINS.iter().enumerate() {
            assert_eq!(
                unsafe { nntmux_cfgd_resolve(value.as_ptr(), value.len()) },
                index as i32
            );
        }
        assert_eq!(text(b"site", 0).as_deref(), Some("Site"));
        assert_eq!(
            text(b"post-processing", 0).as_deref(),
            Some("Post Processing")
        );
        assert_eq!(
            text(b"post-processing", 1).as_deref(),
            Some("post_processing_configurations")
        );
        assert_eq!(text(b"tmux", 1).as_deref(), Some("tmux_configurations"));
        assert_eq!(
            text(b"metadata", 2).as_deref(),
            Some("configuration:metadata")
        );
        assert_eq!(
            text(b"registration", 3).as_deref(),
            Some("App\\Models\\RegistrationConfiguration")
        );
        for unknown in [&b""[..], b"Site", b"site ", b"post_processing", b"tmux\0"] {
            assert_eq!(
                unsafe { nntmux_cfgd_resolve(unknown.as_ptr(), unknown.len()) },
                -1
            );
            assert_eq!(text(unknown, 0), None);
        }
        assert_eq!(text(b"site", 4), None);
        assert_eq!(text(b"site", -1), None);
    }

    #[test]
    fn text_reports_needed_capacity_and_rejects_null() {
        let mut small = [0u8; 4];
        assert_eq!(
            unsafe { nntmux_cfgd_text(b"tmux".as_ptr(), 4, 1, small.as_mut_ptr(), 4) },
            -19
        );
        assert_eq!(
            unsafe { nntmux_cfgd_text(std::ptr::null(), 4, 0, small.as_mut_ptr(), 4) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_cfgd_text(b"tmux".as_ptr(), 4, 0, std::ptr::null_mut(), 4) },
            -1
        );
        assert_eq!(unsafe { nntmux_cfgd_resolve(std::ptr::null(), 0) }, -1);
        assert_eq!(
            unsafe { nntmux_cfgd_resolve(b"site".as_ptr(), isize::MAX as usize + 1) },
            -1
        );
    }

    #[test]
    fn unique_strings_keep_first_occurrence() {
        let parts: [&[u8]; 6] = [b"a", b"bb", b"a", b"", b"bb", b""];
        let buf: Vec<u8> = parts.concat();
        let lens: Vec<i64> = parts.iter().map(|part| part.len() as i64).collect();
        let mut out = [0i64; 6];
        let unique = unsafe {
            nntmux_dcr_unique_strings(
                buf.as_ptr(),
                buf.len(),
                lens.as_ptr(),
                6,
                out.as_mut_ptr(),
                6,
            )
        };
        assert_eq!(unique, 3);
        assert_eq!(&out[..3], &[0, 1, 3]);
        // Lengths must sum to the buffer length and be non-negative.
        let bad = [1i64, 2, 1, 0, 2, 1];
        assert_eq!(
            unsafe {
                nntmux_dcr_unique_strings(
                    buf.as_ptr(),
                    buf.len(),
                    bad.as_ptr(),
                    6,
                    out.as_mut_ptr(),
                    6,
                )
            },
            -1
        );
        let negative = [-1i64, 7, 0, 0, 0, 0];
        assert_eq!(
            unsafe {
                nntmux_dcr_unique_strings(
                    buf.as_ptr(),
                    buf.len(),
                    negative.as_ptr(),
                    6,
                    out.as_mut_ptr(),
                    6,
                )
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_dcr_unique_strings(
                    buf.as_ptr(),
                    buf.len(),
                    lens.as_ptr(),
                    6,
                    out.as_mut_ptr(),
                    5,
                )
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_dcr_unique_strings(
                    buf.as_ptr(),
                    buf.len(),
                    lens.as_ptr(),
                    0,
                    out.as_mut_ptr(),
                    6,
                )
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_dcr_unique_strings(
                    std::ptr::null(),
                    buf.len(),
                    lens.as_ptr(),
                    6,
                    out.as_mut_ptr(),
                    6,
                )
            },
            -1
        );
    }

    #[test]
    fn unique_ints_dedupe_in_place() {
        let mut values = [3i64, 1, 3, -7, 1, i64::MIN, -7];
        let pointer = values.as_mut_ptr();
        let unique = unsafe { nntmux_dcr_unique_ints(pointer.cast_const(), 7, pointer, 7) };
        assert_eq!(unique, 4);
        assert_eq!(&values[..4], &[3, 1, -7, i64::MIN]);
        assert_eq!(
            unsafe { nntmux_dcr_unique_ints(std::ptr::null(), 1, pointer, 7) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_dcr_unique_ints(pointer.cast_const(), 7, pointer, 6) },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_dcr_unique_ints(
                    pointer.cast_const(),
                    usize::MAX / 4,
                    pointer,
                    usize::MAX / 4,
                )
            },
            -1
        );
    }

    #[test]
    fn runtime_state_mask_and_defaults() {
        let lens = [-1i64, 0, 12];
        assert_eq!(unsafe { nntmux_prs_monitor_mask(lens.as_ptr(), 3) }, 0b110);
        assert_eq!(
            unsafe { nntmux_prs_monitor_mask([-1i64; 3].as_ptr(), 3) },
            0
        );
        assert_eq!(unsafe { nntmux_prs_monitor_mask(lens.as_ptr(), 2) }, -1);
        assert_eq!(
            unsafe { nntmux_prs_monitor_mask([-2i64, 0, 0].as_ptr(), 3) },
            -1
        );
        assert_eq!(unsafe { nntmux_prs_monitor_mask(std::ptr::null(), 3) }, -1);
        let mut out = [9i64; 3];
        assert_eq!(unsafe { nntmux_prs_defaults(out.as_mut_ptr(), 3) }, 3);
        assert_eq!(out, [1, 0, 0]);
        assert_eq!(unsafe { nntmux_prs_defaults(out.as_mut_ptr(), 2) }, -1);
        assert_eq!(unsafe { nntmux_prs_defaults(std::ptr::null_mut(), 3) }, -1);
    }

    fn tv(kind: i32, int_value: i64, value: &[u8], default: Option<i64>) -> (i32, i64) {
        let mut resolved = i64::MIN + 7;
        let flags = unsafe {
            nntmux_pps_tv_lookup(
                kind,
                int_value,
                value.as_ptr(),
                value.len(),
                default.unwrap_or(0),
                i32::from(default.is_some()),
                &mut resolved,
            )
        };
        (flags, resolved)
    }

    #[test]
    fn tv_lookup_matches_php_is_numeric_and_int_cast() {
        let cases: [(&[u8], Option<i64>); 34] = [
            (b"2", Some(2)),
            (b" 2", Some(2)),
            (b"2 ", Some(2)),
            (b"\n2\t", Some(2)),
            (b"\x0b2\x0c", Some(2)),
            (b"+2", Some(2)),
            (b"-2", Some(-2)),
            (b"2.9", Some(2)),
            (b"2.9999999999999999999", Some(3)),
            (b"2e0", Some(2)),
            (b"0.2e1", Some(2)),
            (b".2e1", Some(2)),
            (b"2.", Some(2)),
            (b"200E-2", Some(2)),
            (b"+.5", Some(0)),
            (b"00002", Some(2)),
            (b"-0", Some(0)),
            (b"1e100", Some(i64::MAX)),
            (b"-1e100", Some(i64::MIN)),
            (b"99999999999999999999", Some(i64::MAX)),
            (b"9223372036854775807", Some(i64::MAX)),
            (b"-9223372036854775809", Some(i64::MIN)),
            (b"1e-400", Some(0)),
            (b"", None),
            (b" ", None),
            (b".", None),
            (b"1e", None),
            (b"1e+", None),
            (b"-.e1", None),
            (b"0x2", None),
            (b"2abc", None),
            (b"5 .", None),
            (b"NAN", None),
            (b"INF", None),
        ];
        for (value, expected) in cases {
            assert_eq!(w20cd_php_numeric_int(value), expected, "{value:?}");
        }
        for value in [
            &b"1_0"[..],
            b"2\0",
            "\u{0662}".as_bytes(),
            b"inf",
            b"infinity",
        ] {
            assert_eq!(w20cd_php_numeric_int(value), None, "{value:?}");
        }
    }

    #[test]
    fn tv_lookup_flags_and_default() {
        assert_eq!(tv(1, 2, b"", None), (0b11, 2));
        assert_eq!(tv(1, 1, b"", Some(2)), (0b10, 1));
        assert_eq!(tv(2, 0, b" 2.5 ", None), (0b11, 2));
        assert_eq!(tv(2, 0, b"abc", Some(2)), (0b01, 2));
        assert_eq!(tv(0, 0, b"", Some(1)), (0b00, 1));
        assert_eq!(tv(0, 0, b"", None).0, -2);
        assert_eq!(tv(2, 0, b"", None).0, -2);
        assert_eq!(tv(3, 0, b"", Some(1)).0, -1);
        let mut resolved = 0i64;
        assert_eq!(
            unsafe { nntmux_pps_tv_lookup(2, 0, std::ptr::null(), 3, 1, 1, &mut resolved) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_pps_tv_lookup(1, 2, std::ptr::null(), 0, 1, 1, std::ptr::null_mut()) },
            -1
        );
    }

    #[test]
    fn unaligned_pointers_are_rejected() {
        let mut buffer = [0i64; 16];
        let bad = misaligned(&mut buffer);
        let mut aligned = [0i64; 8];
        let buf = b"ab";
        assert_eq!(
            unsafe {
                nntmux_dcr_unique_strings(
                    buf.as_ptr(),
                    2,
                    bad.cast_const(),
                    2,
                    aligned.as_mut_ptr(),
                    2,
                )
            },
            -1
        );
        let lens = [1i64, 1];
        assert_eq!(
            unsafe { nntmux_dcr_unique_strings(buf.as_ptr(), 2, lens.as_ptr(), 2, bad, 2) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_dcr_unique_ints(bad.cast_const(), 2, aligned.as_mut_ptr(), 2) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_dcr_unique_ints(aligned.as_ptr(), 2, bad, 2) },
            -1
        );
        assert_eq!(unsafe { nntmux_prs_monitor_mask(bad.cast_const(), 3) }, -1);
        assert_eq!(unsafe { nntmux_prs_defaults(bad, 3) }, -1);
        assert_eq!(
            unsafe { nntmux_pps_tv_lookup(1, 2, std::ptr::null(), 0, 1, 1, bad) },
            -1
        );
        assert_eq!(aligned, [0i64; 8]);
    }
}

// ---------------------------------------------------------------------------
// Wave 21: ForkingService, MediaInfo, MappingConfidence, ProcessingStage and
// SourcePosition helpers.
// ---------------------------------------------------------------------------

/// `NfoService::NFO_UNPROC`.
const W21_NFO_UNPROC: i64 = -1;

/// Lowest retry marker `ForkingService` accepts.
const W21_MIN_RETRIES: i64 = -8;

/// `ForkingService::maxRetriesFor`: `-(retries + 1)` for a non-negative setting, else
/// `NFO_UNPROC`, never below -8. Writes the marker to `out`; returns 0 or -1 for invalid input.
///
/// # Safety
/// A non-null `out` must be aligned and writable for one value.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_fork_max_retries(max_nfo_retries: i64, out: *mut i64) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !w19pc_slice_ok(out.cast_const(), 1) {
            return -1;
        }
        let marker = if max_nfo_retries >= 0 {
            // PHP turns `-(PHP_INT_MAX + 1)` into a float that still clamps to -8.
            max_nfo_retries
                .checked_add(1)
                .map_or(W21_MIN_RETRIES, |next| (-next).max(W21_MIN_RETRIES))
        } else {
            W21_NFO_UNPROC.max(W21_MIN_RETRIES)
        };
        // SAFETY: `out` is non-null, aligned and writable for one value.
        unsafe { out.write(marker) };
        0
    }))
    .unwrap_or(-1)
}

/// `ForkingService::WORK_TYPES` in declaration order.
const W21_WORK_TYPES: [&[u8]; 18] = [
    b"backfill",
    b"binaries",
    b"fixRelNames_standard",
    b"fixRelNames_predbft",
    b"releases",
    b"postProcess_add",
    b"postProcess_ani",
    b"postProcess_ama",
    b"postProcess_boo",
    b"postProcess_con",
    b"postProcess_gam",
    b"postProcess_mov",
    b"postProcess_mus",
    b"postProcess_nfo",
    b"postProcess_tv",
    b"safe_backfill",
    b"safe_binaries",
    b"update_per_group",
];

/// `ForkingService::workTypeIndex`: the exact (case-sensitive) work type index, -2 for an
/// unknown type, or -1 for invalid input.
///
/// # Safety
/// A non-null `ptr` must be readable for `len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_fork_work_type(ptr: *const u8, len: usize) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        // SAFETY: forwarded caller contract.
        let Some(value) = (unsafe { w19pc_bytes(ptr, len) }) else {
            return -1;
        };
        W21_WORK_TYPES
            .iter()
            .position(|work_type| *work_type == value)
            .map_or(-2, |index| index as i32)
    }))
    .unwrap_or(-1)
}

/// `ForkingService::timingHeader` text.
fn w21_timing_header(task: &[u8], elapsed: i64, date: &[u8]) -> Vec<u8> {
    const PREFIX: &[u8] = b"Multi-processing for ";
    const MIDDLE: &[u8] = b" finished in ";
    const SUFFIX: &[u8] = b" seconds at ";
    let elapsed = elapsed.to_string();
    let mut text = Vec::with_capacity(
        PREFIX.len() + task.len() + MIDDLE.len() + elapsed.len() + SUFFIX.len() + date.len() + 2,
    );
    text.extend_from_slice(PREFIX);
    text.extend_from_slice(task);
    text.extend_from_slice(MIDDLE);
    text.extend_from_slice(elapsed.as_bytes());
    text.extend_from_slice(SUFFIX);
    text.extend_from_slice(date);
    text.extend_from_slice(b".\n");
    text
}

/// Write `Multi-processing for {task} finished in {elapsed} seconds at {date}.\n` to `out`;
/// returns the length, `-(needed)` when `cap` is too small (nothing is written), or -1 for
/// invalid input.
///
/// # Safety
/// Non-null `task`/`date` must be readable for their lengths; a non-null `out` must be
/// writable for `cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_fork_timing_header(
    task: *const u8,
    task_len: usize,
    elapsed: i64,
    date: *const u8,
    date_len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        // SAFETY: forwarded caller contract.
        let (Some(task), Some(date)) = (unsafe { w19pc_bytes(task, task_len) }, unsafe {
            w19pc_bytes(date, date_len)
        }) else {
            return -1;
        };
        let text = w21_timing_header(task, elapsed, date);
        // SAFETY: `out` validity is checked by `w19pc_write`; the caller guarantees `cap`
        // writable bytes.
        unsafe { w19pc_write(out, cap, &text) }
    }))
    .unwrap_or(-1)
}

/// `MediaInfo::normalizeUniqueId` value tags.
const W21_MI_NULL: i32 = 0;
const W21_MI_INT: i32 = 1;
const W21_MI_STRING: i32 = 2;
const W21_MI_OTHER: i32 = 3;

/// Whether a MediaInfo `unique_id` is a placeholder (null, `0`, `'0'` or `'0x0'`) that must not
/// be stored: 1 placeholder, 0 keep, -1 for an unknown tag or invalid input.
///
/// # Safety
/// A non-null `ptr` must be readable for `len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_mi_unique_id_placeholder(
    tag: i32,
    int_value: i64,
    ptr: *const u8,
    len: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        // SAFETY: forwarded caller contract.
        let Some(bytes) = (unsafe { w19pc_bytes(ptr, len) }) else {
            return -1;
        };
        match tag {
            W21_MI_NULL => 1,
            W21_MI_INT => i32::from(int_value == 0),
            W21_MI_STRING => i32::from(bytes == b"0" || bytes == b"0x0"),
            W21_MI_OTHER => 0,
            _ => -1,
        }
    }))
    .unwrap_or(-1)
}

/// Enum selectors for the `nntmux_aenum_*` exports.
const W21_ENUM_MAPPING_CONFIDENCE: i32 = 0;
const W21_ENUM_PROCESSING_STAGE: i32 = 1;
const W21_ENUM_SOURCE_POSITION: i32 = 2;

/// Backing values, labels and ranks of one enum, in declaration order.
struct W21EnumTable {
    values: &'static [&'static [u8]],
    labels: &'static [&'static [u8]],
    ranks: &'static [i32],
}

const W21_MAPPING_CONFIDENCE: W21EnumTable = W21EnumTable {
    values: &[b"verified", b"unique-size"],
    labels: &[b"Verified", b"Unique size"],
    ranks: &[2, 1],
};

const W21_PROCESSING_STAGE: W21EnumTable = W21EnumTable {
    values: &[
        b"workspace-preparation",
        b"nzb-parsing",
        b"release-initialization",
        b"message-id-selection",
        b"media-source-planning",
        b"direct-downloads",
        b"archive-downloads",
        b"extracted-files",
        b"media-sources",
        b"archive-fallbacks",
        b"timeout-handling",
        b"finalization",
        b"workspace-cleanup",
    ],
    labels: &[
        b"Workspace preparation",
        b"NZB parsing",
        b"Release initialization",
        b"Message-ID selection",
        b"Media source planning",
        b"Direct downloads",
        b"Archive downloads",
        b"Extracted files",
        b"Media sources",
        b"Archive fallbacks",
        b"Timeout handling",
        b"Finalization",
        b"Workspace cleanup",
    ],
    ranks: &[0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
};

const W21_SOURCE_POSITION: W21EnumTable = W21EnumTable {
    values: &[b"complete", b"head", b"mid"],
    labels: &[b"Complete file", b"File head", b"Mid-file"],
    ranks: &[2, 1, 0],
};

fn w21_enum_table(kind: i32) -> Option<&'static W21EnumTable> {
    match kind {
        W21_ENUM_MAPPING_CONFIDENCE => Some(&W21_MAPPING_CONFIDENCE),
        W21_ENUM_PROCESSING_STAGE => Some(&W21_PROCESSING_STAGE),
        W21_ENUM_SOURCE_POSITION => Some(&W21_SOURCE_POSITION),
        _ => None,
    }
}

/// PHP `trim()` default characters.
fn w21_is_trim_byte(byte: u8) -> bool {
    matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | b'\0' | 0x0b)
}

/// Case index for `str_replace('_', '-', strtolower(trim($value)))`, or `None`.
fn w21_enum_resolve(table: &W21EnumTable, value: &[u8]) -> Option<usize> {
    let start = value.iter().position(|byte| !w21_is_trim_byte(*byte))?;
    let end = value.iter().rposition(|byte| !w21_is_trim_byte(*byte))? + 1;
    let body = &value[start..end];
    table.values.iter().position(|case| {
        case.len() == body.len()
            && case.iter().zip(body).all(|(expected, byte)| {
                let normalized = match byte.to_ascii_lowercase() {
                    b'_' => b'-',
                    other => other,
                };
                *expected == normalized
            })
    })
}

/// `tryFromNormalized` for enum `kind`: the case index, -2 when no case matches, or -1 for an
/// unknown kind or invalid input.
///
/// # Safety
/// A non-null `ptr` must be readable for `len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_aenum_resolve(kind: i32, ptr: *const u8, len: usize) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(table) = w21_enum_table(kind) else {
            return -1;
        };
        // SAFETY: forwarded caller contract.
        let Some(value) = (unsafe { w19pc_bytes(ptr, len) }) else {
            return -1;
        };
        w21_enum_resolve(table, value).map_or(-2, |index| index as i32)
    }))
    .unwrap_or(-1)
}

/// Write the label of case `index` of enum `kind` to `out`; returns the length, `-(needed)`
/// when `cap` is too small (nothing is written), or -1 for an unknown kind/index or invalid
/// input.
///
/// # Safety
/// A non-null `out` must be writable for `cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_aenum_label(
    kind: i32,
    index: i64,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(label) = w21_enum_table(kind).and_then(|table| {
            usize::try_from(index)
                .ok()
                .and_then(|index| table.labels.get(index))
        }) else {
            return -1;
        };
        // SAFETY: `out` validity is checked by `w19pc_write`; the caller guarantees `cap`
        // writable bytes.
        unsafe { w19pc_write(out, cap, label) }
    }))
    .unwrap_or(-1)
}

/// Rank of case `index` of enum `kind` (confidence strength, pipeline order or source
/// coverage), or -1 for an unknown kind/index.
///
/// # Safety
/// Takes no pointers; any argument values are accepted.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_aenum_rank(kind: i32, index: i64) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        w21_enum_table(kind)
            .and_then(|table| {
                usize::try_from(index)
                    .ok()
                    .and_then(|index| table.ranks.get(index))
            })
            .copied()
            .unwrap_or(-1)
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave21_forking_mediainfo_enum_tests {
    use super::*;

    fn max_retries(value: i64) -> i64 {
        let mut out = i64::MIN;
        assert_eq!(unsafe { nntmux_fork_max_retries(value, &mut out) }, 0);
        out
    }

    #[test]
    fn max_retries_matches_php_clamp() {
        for (value, expected) in [
            (0, -1),
            (1, -2),
            (6, -7),
            (7, -8),
            (8, -8),
            (i64::MAX, -8),
            (-1, -1),
            (-5, -1),
            (i64::MIN, -1),
        ] {
            assert_eq!(max_retries(value), expected, "{value}");
        }
        assert_eq!(
            unsafe { nntmux_fork_max_retries(3, std::ptr::null_mut()) },
            -1
        );
        let mut buffer = [0i64; 4];
        let bad = unsafe { buffer.as_mut_ptr().cast::<u8>().add(1) }.cast::<i64>();
        assert_eq!(unsafe { nntmux_fork_max_retries(3, bad) }, -1);
        assert_eq!(buffer, [0; 4]);
    }

    #[test]
    fn work_type_lookup_is_exact() {
        for (index, work_type) in W21_WORK_TYPES.iter().enumerate() {
            assert_eq!(
                unsafe { nntmux_fork_work_type(work_type.as_ptr(), work_type.len()) },
                index as i32
            );
        }
        for value in [
            &b""[..],
            b"Backfill",
            b" backfill",
            b"backfill\0",
            b"postProcess_",
        ] {
            assert_eq!(
                unsafe { nntmux_fork_work_type(value.as_ptr(), value.len()) },
                -2,
                "{value:?}"
            );
        }
        assert_eq!(unsafe { nntmux_fork_work_type(std::ptr::null(), 0) }, -2);
        assert_eq!(unsafe { nntmux_fork_work_type(std::ptr::null(), 3) }, -1);
        assert_eq!(
            unsafe { nntmux_fork_work_type(b"x".as_ptr(), usize::MAX) },
            -1
        );
    }

    #[test]
    fn timing_header_formats_and_never_writes_partially() {
        let task = b"postProcess_tv";
        let date = b"Sat, 24 Oct 2026 19:06:17 +0200";
        let expected = b"Multi-processing for postProcess_tv finished in -3 seconds at Sat, 24 Oct 2026 19:06:17 +0200.\n";
        let mut out = [0u8; 256];
        let written = unsafe {
            nntmux_fork_timing_header(
                task.as_ptr(),
                task.len(),
                -3,
                date.as_ptr(),
                date.len(),
                out.as_mut_ptr(),
                out.len(),
            )
        };
        assert_eq!(written, expected.len() as isize);
        assert_eq!(&out[..expected.len()], expected);
        let mut small = [7u8; 8];
        assert_eq!(
            unsafe {
                nntmux_fork_timing_header(
                    task.as_ptr(),
                    task.len(),
                    -3,
                    date.as_ptr(),
                    date.len(),
                    small.as_mut_ptr(),
                    small.len(),
                )
            },
            -(expected.len() as isize)
        );
        assert_eq!(small, [7u8; 8]);
        let empty = unsafe {
            nntmux_fork_timing_header(
                std::ptr::null(),
                0,
                i64::MIN,
                std::ptr::null(),
                0,
                out.as_mut_ptr(),
                out.len(),
            )
        };
        let min = b"Multi-processing for  finished in -9223372036854775808 seconds at .\n";
        assert_eq!(empty, min.len() as isize);
        assert_eq!(&out[..min.len()], min);
        assert_eq!(
            unsafe {
                nntmux_fork_timing_header(
                    std::ptr::null(),
                    1,
                    0,
                    date.as_ptr(),
                    date.len(),
                    out.as_mut_ptr(),
                    out.len(),
                )
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_fork_timing_header(
                    task.as_ptr(),
                    task.len(),
                    0,
                    date.as_ptr(),
                    date.len(),
                    std::ptr::null_mut(),
                    64,
                )
            },
            -1
        );
    }

    #[test]
    fn unique_id_placeholders() {
        let check = |tag: i32, int_value: i64, bytes: &[u8]| unsafe {
            nntmux_mi_unique_id_placeholder(tag, int_value, bytes.as_ptr(), bytes.len())
        };
        assert_eq!(check(W21_MI_NULL, 0, b""), 1);
        assert_eq!(check(W21_MI_INT, 0, b""), 1);
        assert_eq!(check(W21_MI_INT, 1, b""), 0);
        assert_eq!(check(W21_MI_INT, -1, b""), 0);
        assert_eq!(check(W21_MI_STRING, 0, b"0"), 1);
        assert_eq!(check(W21_MI_STRING, 0, b"0x0"), 1);
        for value in [&b""[..], b"00", b" 0", b"0X0", b"0x00", b"0\0", b"123"] {
            assert_eq!(check(W21_MI_STRING, 0, value), 0, "{value:?}");
        }
        assert_eq!(check(W21_MI_OTHER, 0, b""), 0);
        assert_eq!(check(4, 0, b""), -1);
        assert_eq!(
            unsafe { nntmux_mi_unique_id_placeholder(W21_MI_STRING, 0, std::ptr::null(), 1) },
            -1
        );
    }

    #[test]
    fn enum_resolve_normalizes_like_php() {
        for (kind, table) in [
            (W21_ENUM_MAPPING_CONFIDENCE, &W21_MAPPING_CONFIDENCE),
            (W21_ENUM_PROCESSING_STAGE, &W21_PROCESSING_STAGE),
            (W21_ENUM_SOURCE_POSITION, &W21_SOURCE_POSITION),
        ] {
            assert_eq!(table.values.len(), table.labels.len());
            assert_eq!(table.values.len(), table.ranks.len());
            for (index, value) in table.values.iter().enumerate() {
                assert_eq!(
                    unsafe { nntmux_aenum_resolve(kind, value.as_ptr(), value.len()) },
                    index as i32
                );
            }
        }
        let resolve = |kind: i32, value: &[u8]| unsafe {
            nntmux_aenum_resolve(kind, value.as_ptr(), value.len())
        };
        assert_eq!(resolve(0, b" \t\nUNIQUE_SIZE\0\x0b"), 1);
        assert_eq!(resolve(1, b"Nzb_Parsing"), 1);
        assert_eq!(resolve(2, b"MID\r"), 2);
        assert_eq!(resolve(2, b"\x0cmid"), -2);
        assert_eq!(resolve(2, b"m id"), -2);
        assert_eq!(resolve(2, "h\u{e9}ad".as_bytes()), -2);
        assert_eq!(resolve(2, b""), -2);
        assert_eq!(resolve(2, b"   "), -2);
        assert_eq!(resolve(3, b"mid"), -1);
        assert_eq!(unsafe { nntmux_aenum_resolve(2, std::ptr::null(), 0) }, -2);
        assert_eq!(unsafe { nntmux_aenum_resolve(2, std::ptr::null(), 3) }, -1);
    }

    #[test]
    fn enum_label_and_rank() {
        let mut out = [0u8; 64];
        let written = unsafe { nntmux_aenum_label(1, 3, out.as_mut_ptr(), out.len()) };
        assert_eq!(&out[..written as usize], b"Message-ID selection");
        let mut small = [9u8; 4];
        assert_eq!(
            unsafe { nntmux_aenum_label(0, 1, small.as_mut_ptr(), small.len()) },
            -11
        );
        assert_eq!(small, [9u8; 4]);
        assert_eq!(
            unsafe { nntmux_aenum_label(0, 2, out.as_mut_ptr(), out.len()) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_aenum_label(0, -1, out.as_mut_ptr(), out.len()) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_aenum_label(5, 0, out.as_mut_ptr(), out.len()) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_aenum_label(0, 0, std::ptr::null_mut(), 64) },
            -1
        );
        assert_eq!(unsafe { nntmux_aenum_rank(0, 0) }, 2);
        assert_eq!(unsafe { nntmux_aenum_rank(1, 12) }, 12);
        assert_eq!(unsafe { nntmux_aenum_rank(2, 2) }, 0);
        assert_eq!(unsafe { nntmux_aenum_rank(2, 3) }, -1);
        assert_eq!(unsafe { nntmux_aenum_rank(2, i64::MIN) }, -1);
        assert_eq!(unsafe { nntmux_aenum_rank(-1, 0) }, -1);
    }
}
// ---------------------------------------------------------------------------
// Wave 22: UpdateDomainConfigurationRequest, RegistrationStatusService,
// RegistrationPeriod, Content and SiteLogoService helpers.
// ---------------------------------------------------------------------------

/// `preg_split('/[\s,]+/')` separators: PCRE2 `\s` (including VT) and comma.
#[inline]
fn w22_is_color_separator(byte: u8) -> bool {
    matches!(byte, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r' | b',')
}

/// `preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY)` tokens.
fn w22_color_tokens(raw: &[u8]) -> impl Iterator<Item = &[u8]> {
    trim_php(raw)
        .split(|&byte| w22_is_color_separator(byte))
        .filter(|token| !token.is_empty())
}

/// PHP `(int) $token` for a separator-free token, or `None` when the token has a fraction or
/// exponent part (PHP would parse it as a float; the caller falls back to PHP).
fn w22_php_int(token: &[u8]) -> Option<i64> {
    let (negative, digits) = match token.first() {
        Some(b'-') => (true, &token[1..]),
        Some(b'+') => (false, &token[1..]),
        _ => (false, token),
    };
    let count = digits
        .iter()
        .take_while(|byte| byte.is_ascii_digit())
        .count();
    if matches!(digits.get(count), Some(b'.' | b'e' | b'E')) {
        return None;
    }
    let mut value: i64 = 0;
    for &byte in &digits[..count] {
        let digit = i64::from(byte - b'0');
        // Out-of-range integers become floats that `(int)` caps at the integer bounds.
        value = match value.checked_mul(10).and_then(|scaled| {
            if negative {
                scaled.checked_sub(digit)
            } else {
                scaled.checked_add(digit)
            }
        }) {
            Some(next) => next,
            None => return Some(if negative { i64::MIN } else { i64::MAX }),
        };
    }
    Some(value)
}

/// `UpdateDomainConfigurationRequest::colorExclusions`: the unique `(int)` values in first-seen
/// order. Returns the count written to `out`, -2 when a token needs PHP float parsing, -3 when
/// `cap` is too small (nothing is written), or -1 for invalid input.
///
/// # Safety
/// A non-null `raw` must be readable for `raw_len` bytes; a non-null `out` must be aligned
/// and writable for `cap` values.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_domcfg_color_exclusions(
    raw: *const u8,
    raw_len: usize,
    out: *mut i64,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        // SAFETY: forwarded caller contract.
        let Some(raw) = (unsafe { w19pc_bytes(raw, raw_len) }) else {
            return -1;
        };
        if !w19pc_slice_ok(out.cast_const(), cap) {
            return -1;
        }
        let mut values: Vec<i64> = Vec::new();
        let mut seen: Option<std::collections::HashSet<i64>> = None;
        for token in w22_color_tokens(raw) {
            let Some(value) = w22_php_int(token) else {
                return -2;
            };
            let duplicate = match seen.as_mut() {
                Some(set) => !set.insert(value),
                None => values.contains(&value),
            };
            if duplicate {
                continue;
            }
            values.push(value);
            if seen.is_none() && values.len() > 32 {
                seen = Some(values.iter().copied().collect());
            }
        }
        if values.len() > cap {
            return -3;
        }
        // SAFETY: `out` is non-null, aligned and writable for `cap >= values.len()` values.
        unsafe { std::slice::from_raw_parts_mut(out, values.len()) }.copy_from_slice(&values);
        values.len() as isize
    }))
    .unwrap_or(-1)
}

/// Tmux `color_exclusions` validation messages joined by `\n` (tokens never contain one).
fn w22_color_exclusion_errors(raw: &[u8], start: i64, end: i64) -> Vec<u8> {
    let mut text = Vec::new();
    for token in w22_color_tokens(raw) {
        let digits_only = token.iter().all(u8::is_ascii_digit);
        let color = if digits_only {
            w22_php_int(token)
        } else {
            None
        };
        if color.is_some_and(|color| color <= 255 && color >= start && color <= end) {
            continue;
        }
        if !text.is_empty() {
            text.push(b'\n');
        }
        text.extend_from_slice(b"Excluded color ");
        match color {
            Some(color) if color <= 255 => {
                text.extend_from_slice(color.to_string().as_bytes());
                text.extend_from_slice(b" must be inside the configured color range.");
            }
            _ => {
                text.extend_from_slice(token);
                text.extend_from_slice(b" must be an integer from 0 through 255.");
            }
        }
    }
    text
}

/// `UpdateDomainConfigurationRequest::colorExclusionErrors`: every `color_exclusions` message
/// in order, joined by `\n`. Returns the length (0 when valid), `-(needed)` when `cap` is too
/// small (nothing is written), or -1 for invalid input.
///
/// # Safety
/// A non-null `raw` must be readable for `raw_len` bytes; a non-null `out` must be writable
/// for `cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_domcfg_color_exclusion_errors(
    raw: *const u8,
    raw_len: usize,
    start: i64,
    end: i64,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        // SAFETY: forwarded caller contract.
        let Some(raw) = (unsafe { w19pc_bytes(raw, raw_len) }) else {
            return -1;
        };
        let text = w22_color_exclusion_errors(raw, start, end);
        // SAFETY: `out` validity is checked by `w19pc_write`; the caller guarantees `cap`
        // writable bytes.
        unsafe { w19pc_write(out, cap, &text) }
    }))
    .unwrap_or(-1)
}

/// `UpdateDomainConfigurationRequest` range checks: kind 0 is the tmux color range (`a` start,
/// `b` end; invalid when `start >= end`), kind 1 a byte size pair (`a` minimum, `b` maximum;
/// invalid when the maximum is positive and below the minimum). Returns 1 when invalid, 0
/// when valid, or -1 for an unknown kind.
///
/// # Safety
/// Takes no pointers; any argument values are accepted.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_domcfg_range_invalid(kind: i32, a: i64, b: i64) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| match kind {
        0 => i32::from(a >= b),
        1 => i32::from(b > 0 && a > b),
        _ => -1,
    }))
    .unwrap_or(-1)
}

/// `RegistrationStatus::label()` by backing value, `Unknown` otherwise.
fn w22_registration_label(status: i64) -> &'static [u8] {
    match status {
        0 => b"Open",
        1 => b"Invite",
        2 => b"Closed",
        _ => b"Unknown",
    }
}

/// `RegistrationStatusService` decision bits written to `out[1]`.
const W22_REG_OVERRIDE: i64 = 1;
const W22_REG_AVAILABLE: i64 = 2;
const W22_REG_OPEN: i64 = 4;
const W22_REG_INVITE: i64 = 8;
const W22_REG_CLOSED: i64 = 16;

/// `RegistrationStatusService::decide`: writes `[effective status, flag bits, reason index]`
/// to `out` (reasons: scheduled_open_period, manual_open, manual_invite, manual_closed).
/// Returns 0, or -1 for invalid input.
///
/// # Safety
/// A non-null `out` must be aligned and writable for three values.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_regstatus_decide(
    manual_status: i64,
    has_active_period: i32,
    out: *mut i64,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !w19pc_slice_ok(out.cast_const(), 3) {
            return -1;
        }
        let active = has_active_period != 0;
        let scheduled_override = active && manual_status != 0;
        let effective = if active { 0 } else { manual_status };
        let reason = match effective {
            _ if scheduled_override => 0,
            0 => 1,
            1 => 2,
            _ => 3,
        };
        let mut flags = 0;
        if scheduled_override {
            flags |= W22_REG_OVERRIDE;
        }
        if effective != 2 {
            flags |= W22_REG_AVAILABLE;
        }
        flags |= match effective {
            0 => W22_REG_OPEN,
            1 => W22_REG_INVITE,
            2 => W22_REG_CLOSED,
            _ => 0,
        };
        // SAFETY: `out` is non-null, aligned and writable for three values.
        unsafe { std::slice::from_raw_parts_mut(out, 3) }
            .copy_from_slice(&[effective, flags, reason]);
        0
    }))
    .unwrap_or(-1)
}

/// `RegistrationStatusService::normalizeManualStatus`: a known status, otherwise Open (0).
/// Returns -1 only on an internal failure.
///
/// # Safety
/// Takes no pointers; any argument values are accepted.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_regstatus_normalize_manual(status: i64) -> i64 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if (0..=2).contains(&status) { status } else { 0 }
    }))
    .unwrap_or(-1)
}

/// `RegistrationStatusService` text kinds for `nntmux_regstatus_text`.
const W22_REG_TEXT_LABEL: i32 = 0;
const W22_REG_TEXT_MESSAGE: i32 = 1;
const W22_REG_TEXT_MANUAL_CHANGE: i32 = 2;
const W22_REG_TEXT_PERIOD: i32 = 3;

/// Scheduled period audit description suffixes, indexed by `RegistrationStatusService`
/// period event.
const W22_PERIOD_EVENTS: [&[u8]; 6] = [
    b"\" created.",
    b"\" updated.",
    b"\" enabled.",
    b"\" disabled.",
    b"\" completed after reaching its end time.",
    b"\" deleted.",
];

fn w22_registration_text(
    kind: i32,
    a: i64,
    b: i64,
    first: &[u8],
    second: &[u8],
) -> Option<Vec<u8>> {
    let mut text = Vec::with_capacity(96 + first.len() + second.len());
    match kind {
        W22_REG_TEXT_LABEL => text.extend_from_slice(w22_registration_label(a)),
        W22_REG_TEXT_MESSAGE => {
            if b != 0 {
                text.extend_from_slice(b"Registrations are temporarily open until ");
                text.extend_from_slice(first);
                text.extend_from_slice(b" because of the scheduled period \"");
                text.extend_from_slice(second);
                text.extend_from_slice(b"\".");
            } else {
                text.extend_from_slice(match a {
                    0 => b"Registrations are currently open.".as_slice(),
                    1 => b"Registrations are currently invite only.",
                    _ => b"Registrations are currently closed.",
                });
            }
        }
        W22_REG_TEXT_MANUAL_CHANGE => {
            if a == b {
                text.extend_from_slice(
                    b"Manual registration status note added while status remained ",
                );
                text.extend_from_slice(w22_registration_label(b));
            } else {
                text.extend_from_slice(b"Manual registration status changed from ");
                text.extend_from_slice(w22_registration_label(a));
                text.extend_from_slice(b" to ");
                text.extend_from_slice(w22_registration_label(b));
            }
            text.push(b'.');
        }
        W22_REG_TEXT_PERIOD => {
            let suffix = W22_PERIOD_EVENTS.get(usize::try_from(a).ok()?)?;
            text.extend_from_slice(b"Scheduled open period \"");
            text.extend_from_slice(first);
            text.extend_from_slice(suffix);
        }
        _ => return None,
    }
    Some(text)
}

/// `RegistrationStatusService` status text. Kinds: 0 `statusLabel(a)`; 1 `statusMessage`
/// (`a` effective status, `b` non-zero for a scheduled override, `first` the formatted end
/// time, `second` the period name); 2 manual status audit description (`a` old, `b` new);
/// 3 period audit description (`a` event index, `first` the period name). Returns the
/// length, `-(needed)` when `cap` is too small (nothing is written), or -1 for invalid input.
///
/// # Safety
/// Non-null `first`/`second` must be readable for their lengths; a non-null `out` must be
/// writable for `cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_regstatus_text(
    kind: i32,
    a: i64,
    b: i64,
    first: *const u8,
    first_len: usize,
    second: *const u8,
    second_len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        // SAFETY: forwarded caller contract.
        let (Some(first), Some(second)) = (unsafe { w19pc_bytes(first, first_len) }, unsafe {
            w19pc_bytes(second, second_len)
        }) else {
            return -1;
        };
        let Some(text) = w22_registration_text(kind, a, b, first, second) else {
            return -1;
        };
        // SAFETY: `out` validity is checked by `w19pc_write`; the caller guarantees `cap`
        // writable bytes.
        unsafe { w19pc_write(out, cap, &text) }
    }))
    .unwrap_or(-1)
}

/// `RegistrationPeriod` window bits: 1 when active at `at`, 2 when ended before `at`.
/// Instants are epoch microseconds; `has_start`/`has_end` are zero for a null bound.
/// Returns the bits, or -1 only on an internal failure.
///
/// # Safety
/// Takes no pointers; any argument values are accepted.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_regperiod_window(
    enabled: i32,
    has_start: i32,
    starts_at: i64,
    has_end: i32,
    ends_at: i64,
    at: i64,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let has_end = has_end != 0;
        let active = enabled != 0 && has_start != 0 && has_end && starts_at <= at && ends_at >= at;
        let ended = has_end && ends_at < at;
        i32::from(active) | (i32::from(ended) << 1)
    }))
    .unwrap_or(-1)
}

/// `Content` label text: kind 0 content type, kind 1 role; `has_value` zero for null.
fn w22_content_label(kind: i32, has_value: bool, value: i64) -> Option<&'static [u8]> {
    let value = has_value.then_some(value);
    Some(match (kind, value) {
        (0, Some(1)) => b"Useful Link",
        (0, Some(3)) => b"Homepage",
        (1, Some(0)) => b"Everyone",
        (1, Some(1)) => b"Logged in Users",
        (1, Some(2)) => b"Admins",
        (0 | 1, _) => b"Unknown",
        _ => return None,
    })
}

/// `Content::contentTypeLabelFor` (kind 0) / `roleLabelFor` (kind 1). Returns the length,
/// `-(needed)` when `cap` is too small (nothing is written), or -1 for invalid input.
///
/// # Safety
/// A non-null `out` must be writable for `cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_content_label(
    kind: i32,
    has_value: i32,
    value: i64,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(label) = w22_content_label(kind, has_value != 0, value) else {
            return -1;
        };
        // SAFETY: `out` validity is checked by `w19pc_write`; the caller guarantees `cap`
        // writable bytes.
        unsafe { w19pc_write(out, cap, label) }
    }))
    .unwrap_or(-1)
}

/// `/^[a-zA-Z0-9][a-zA-Z0-9-]*\.[a-zA-Z]{2,}/` prefix match. The run class excludes `.`, so
/// the dot must directly follow the longest run and backtracking cannot find another match.
fn w22_bare_domain(url: &[u8]) -> bool {
    if !url.first().is_some_and(u8::is_ascii_alphanumeric) {
        return false;
    }
    let run = 1 + url[1..]
        .iter()
        .take_while(|&&byte| byte.is_ascii_alphanumeric() || byte == b'-')
        .count();
    url.get(run) == Some(&b'.')
        && url.len() >= run + 3
        && url[run + 1..run + 3].iter().all(u8::is_ascii_alphabetic)
}

/// `Content::classifyUrl`: 0 empty (`empty()`), 1 absolute http(s), 2 bare domain, 3 internal
/// path; -1 for invalid input.
///
/// # Safety
/// A non-null `ptr` must be readable for `len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_content_url_kind(ptr: *const u8, len: usize) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        // SAFETY: forwarded caller contract.
        let Some(url) = (unsafe { w19pc_bytes(ptr, len) }) else {
            return -1;
        };
        if url.is_empty() || url == b"0" {
            0
        } else if url.starts_with(b"http://") || url.starts_with(b"https://") {
            1
        } else if !url.starts_with(b"/") && w22_bare_domain(url) {
            2
        } else {
            3
        }
    }))
    .unwrap_or(-1)
}

/// `SiteLogoService::DIRECTORY` plus its separator.
const W22_LOGO_PREFIX: &[u8] = b"site-logos/";

/// `SiteLogoService::isManagedPath`.
fn w22_logo_managed(path: &[u8]) -> bool {
    path.starts_with(W22_LOGO_PREFIX) && !path.windows(2).any(|pair| pair == b"..")
}

/// `SiteLogoService::urlFor`: writes `/storage/{path}` for a managed path. Returns the length,
/// 0 when the path is not managed, `-(needed)` when `cap` is too small (nothing is written),
/// or -1 for invalid input.
///
/// # Safety
/// A non-null `ptr` must be readable for `len` bytes; a non-null `out` must be writable for
/// `cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_site_logo_url(
    ptr: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        // SAFETY: forwarded caller contract.
        let Some(path) = (unsafe { w19pc_bytes(ptr, len) }) else {
            return -1;
        };
        if !w22_logo_managed(path) {
            return 0;
        }
        let mut url = Vec::with_capacity(9 + path.len());
        url.extend_from_slice(b"/storage/");
        url.extend_from_slice(path);
        // SAFETY: `out` validity is checked by `w19pc_write`; the caller guarantees `cap`
        // writable bytes.
        unsafe { w19pc_write(out, cap, &url) }
    }))
    .unwrap_or(-1)
}

/// `SiteLogoService::isManagedPath`: 1 managed, 0 not managed, -1 for invalid input.
///
/// # Safety
/// A non-null `ptr` must be readable for `len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_site_logo_is_managed(ptr: *const u8, len: usize) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        // SAFETY: forwarded caller contract.
        unsafe { w19pc_bytes(ptr, len) }.map_or(-1, |path| i32::from(w22_logo_managed(path)))
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave22_domain_config_registration_content_tests {
    use super::*;

    fn exclusions(raw: &[u8]) -> Result<Vec<i64>, isize> {
        let mut out = [i64::MIN; 64];
        let code = unsafe {
            nntmux_domcfg_color_exclusions(raw.as_ptr(), raw.len(), out.as_mut_ptr(), out.len())
        };
        if code < 0 {
            return Err(code);
        }
        Ok(out[..code as usize].to_vec())
    }

    fn errors(raw: &[u8], start: i64, end: i64) -> Vec<u8> {
        let mut out = [0u8; 1024];
        let code = unsafe {
            nntmux_domcfg_color_exclusion_errors(
                raw.as_ptr(),
                raw.len(),
                start,
                end,
                out.as_mut_ptr(),
                out.len(),
            )
        };
        assert!(code >= 0, "{code}");
        out[..code as usize].to_vec()
    }

    fn text(kind: i32, a: i64, b: i64, first: &[u8], second: &[u8]) -> isize {
        let mut out = [0u8; 256];
        unsafe {
            nntmux_regstatus_text(
                kind,
                a,
                b,
                first.as_ptr(),
                first.len(),
                second.as_ptr(),
                second.len(),
                out.as_mut_ptr(),
                out.len(),
            )
        }
    }

    fn text_bytes(kind: i32, a: i64, b: i64, first: &[u8], second: &[u8]) -> Vec<u8> {
        w22_registration_text(kind, a, b, first, second).unwrap()
    }

    #[test]
    fn color_exclusions_match_php_intval_and_unique() {
        assert_eq!(
            exclusions(b" 3, 1 3,,2\x0b4\x0c5\r\n1 "),
            Ok(vec![3, 1, 2, 4, 5])
        );
        assert_eq!(exclusions(b"\0 7 \0"), Ok(vec![7]));
        assert_eq!(
            exclusions(b"12abc abc +5 -0 0x1A 00300 5\0"),
            Ok(vec![12, 0, 5, 300])
        );
        assert_eq!(
            exclusions(b"99999999999999999999 -99999999999999999999x 9223372036854775807"),
            Ok(vec![i64::MAX, i64::MIN])
        );
        assert_eq!(exclusions(b""), Ok(vec![]));
        assert_eq!(exclusions(b" , \t"), Ok(vec![]));
        for float in [&b"1e3"[..], b"1.9", b".5", b"5E1", b"-.5"] {
            assert_eq!(exclusions(float), Err(-2), "{float:?}");
        }
        let many: Vec<u8> = (0..100)
            .flat_map(|n| format!("{} ", n % 50).into_bytes())
            .collect();
        assert_eq!(exclusions(&many), Ok((0..50).collect()));

        let mut small = [9i64; 2];
        assert_eq!(
            unsafe { nntmux_domcfg_color_exclusions(b"1 2 3".as_ptr(), 5, small.as_mut_ptr(), 2) },
            -3
        );
        assert_eq!(small, [9, 9]);
        let mut buffer = [0i64; 4];
        let bad = unsafe { buffer.as_mut_ptr().cast::<u8>().add(1) }.cast::<i64>();
        assert_eq!(
            unsafe { nntmux_domcfg_color_exclusions(b"1".as_ptr(), 1, bad, 2) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_domcfg_color_exclusions(b"1".as_ptr(), 1, std::ptr::null_mut(), 2) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_domcfg_color_exclusions(std::ptr::null(), 1, buffer.as_mut_ptr(), 4) },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_domcfg_color_exclusions(b"1".as_ptr(), usize::MAX, buffer.as_mut_ptr(), 4)
            },
            -1
        );
        assert_eq!(buffer, [0; 4]);
    }

    #[test]
    fn color_exclusion_errors_match_php_messages() {
        assert_eq!(errors(b"1 2 3", 0, 255), b"");
        assert_eq!(
            errors(b"300 abc 0 1e3 -1 12\0 0007 999999999999999999999", 5, 10),
            b"Excluded color 300 must be an integer from 0 through 255.\n\
Excluded color abc must be an integer from 0 through 255.\n\
Excluded color 0 must be inside the configured color range.\n\
Excluded color 1e3 must be an integer from 0 through 255.\n\
Excluded color -1 must be an integer from 0 through 255.\n\
Excluded color 12\0 must be an integer from 0 through 255.\n\
Excluded color 999999999999999999999 must be an integer from 0 through 255."
                .as_slice()
        );
        assert_eq!(
            errors(b"0255", 0, 100),
            b"Excluded color 255 must be inside the configured color range."
        );
        let raw = b"300";
        let mut small = [7u8; 8];
        let code = unsafe {
            nntmux_domcfg_color_exclusion_errors(
                raw.as_ptr(),
                raw.len(),
                0,
                1,
                small.as_mut_ptr(),
                small.len(),
            )
        };
        assert_eq!(code, -57);
        assert_eq!(small, [7u8; 8]);
        assert_eq!(
            unsafe {
                nntmux_domcfg_color_exclusion_errors(raw.as_ptr(), 3, 0, 1, std::ptr::null_mut(), 8)
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_domcfg_color_exclusion_errors(
                    std::ptr::null(),
                    3,
                    0,
                    1,
                    small.as_mut_ptr(),
                    8,
                )
            },
            -1
        );
    }

    #[test]
    fn range_checks() {
        assert_eq!(unsafe { nntmux_domcfg_range_invalid(0, 5, 5) }, 1);
        assert_eq!(unsafe { nntmux_domcfg_range_invalid(0, 6, 5) }, 1);
        assert_eq!(unsafe { nntmux_domcfg_range_invalid(0, 4, 5) }, 0);
        assert_eq!(unsafe { nntmux_domcfg_range_invalid(1, 10, 0) }, 0);
        assert_eq!(unsafe { nntmux_domcfg_range_invalid(1, 10, 5) }, 1);
        assert_eq!(unsafe { nntmux_domcfg_range_invalid(1, 5, 5) }, 0);
        assert_eq!(unsafe { nntmux_domcfg_range_invalid(1, 5, -1) }, 0);
        assert_eq!(unsafe { nntmux_domcfg_range_invalid(2, 0, 0) }, -1);
    }

    #[test]
    fn registration_decision_and_text() {
        let decide = |manual: i64, active: i32| {
            let mut out = [0i64; 3];
            assert_eq!(
                unsafe { nntmux_regstatus_decide(manual, active, out.as_mut_ptr()) },
                0
            );
            out
        };
        assert_eq!(decide(0, 0), [0, W22_REG_AVAILABLE | W22_REG_OPEN, 1]);
        assert_eq!(decide(1, 0), [1, W22_REG_AVAILABLE | W22_REG_INVITE, 2]);
        assert_eq!(decide(2, 0), [2, W22_REG_CLOSED, 3]);
        assert_eq!(decide(0, 1), [0, W22_REG_AVAILABLE | W22_REG_OPEN, 1]);
        assert_eq!(
            decide(2, 1),
            [0, W22_REG_OVERRIDE | W22_REG_AVAILABLE | W22_REG_OPEN, 0]
        );
        assert_eq!(decide(99, 0), [99, W22_REG_AVAILABLE, 3]);
        let mut buffer = [0i64; 4];
        let bad = unsafe { buffer.as_mut_ptr().cast::<u8>().add(1) }.cast::<i64>();
        assert_eq!(unsafe { nntmux_regstatus_decide(0, 0, bad) }, -1);
        assert_eq!(
            unsafe { nntmux_regstatus_decide(0, 0, std::ptr::null_mut()) },
            -1
        );
        assert_eq!(buffer, [0; 4]);

        assert_eq!(unsafe { nntmux_regstatus_normalize_manual(2) }, 2);
        assert_eq!(unsafe { nntmux_regstatus_normalize_manual(3) }, 0);
        assert_eq!(unsafe { nntmux_regstatus_normalize_manual(-1) }, 0);

        assert_eq!(text_bytes(0, 1, 0, b"", b""), b"Invite");
        assert_eq!(text_bytes(0, -7, 0, b"", b""), b"Unknown");
        assert_eq!(
            text_bytes(1, 2, 0, b"", b""),
            b"Registrations are currently closed."
        );
        assert_eq!(
            text_bytes(1, 9, 0, b"", b""),
            b"Registrations are currently closed."
        );
        assert_eq!(
            text_bytes(1, 0, 1, b"2026-10-24 12:00", b"Launch"),
            b"Registrations are temporarily open until 2026-10-24 12:00 because of the scheduled period \"Launch\"."
        );
        assert_eq!(
            text_bytes(2, 1, 1, b"", b""),
            b"Manual registration status note added while status remained Invite."
        );
        assert_eq!(
            text_bytes(2, 0, 7, b"", b""),
            b"Manual registration status changed from Open to Unknown."
        );
        assert_eq!(
            text_bytes(3, 4, 0, b"Q", b""),
            b"Scheduled open period \"Q\" completed after reaching its end time."
        );
        assert_eq!(text(3, 6, 0, b"Q", b""), -1);
        assert_eq!(text(3, -1, 0, b"Q", b""), -1);
        assert_eq!(text(4, 0, 0, b"", b""), -1);
        assert_eq!(text(0, 0, 0, b"", b""), 4);
        let mut small = [5u8; 3];
        assert_eq!(
            unsafe {
                nntmux_regstatus_text(
                    0,
                    2,
                    0,
                    std::ptr::null(),
                    0,
                    std::ptr::null(),
                    0,
                    small.as_mut_ptr(),
                    3,
                )
            },
            -6
        );
        assert_eq!(small, [5u8; 3]);
        assert_eq!(
            unsafe {
                nntmux_regstatus_text(
                    3,
                    0,
                    0,
                    std::ptr::null(),
                    2,
                    std::ptr::null(),
                    0,
                    small.as_mut_ptr(),
                    3,
                )
            },
            -1
        );
    }

    #[test]
    fn registration_period_window() {
        let window = |enabled, has_start, start, has_end, end, at| unsafe {
            nntmux_regperiod_window(enabled, has_start, start, has_end, end, at)
        };
        assert_eq!(window(1, 1, 10, 1, 20, 10), 1);
        assert_eq!(window(1, 1, 10, 1, 20, 20), 1);
        assert_eq!(window(1, 1, 10, 1, 20, 21), 2);
        assert_eq!(window(1, 1, 10, 1, 20, 9), 0);
        assert_eq!(window(0, 1, 10, 1, 20, 15), 0);
        assert_eq!(window(0, 1, 10, 1, 20, 25), 2);
        assert_eq!(window(1, 0, 10, 1, 20, 15), 0);
        assert_eq!(window(1, 1, 10, 0, 20, 25), 0);
        assert_eq!(window(1, 1, i64::MIN, 1, i64::MAX, 0), 1);
    }

    #[test]
    fn content_labels_and_url_kind() {
        let label = |kind, has, value| w22_content_label(kind, has, value);
        assert_eq!(label(0, true, 1), Some(&b"Useful Link"[..]));
        assert_eq!(label(0, true, 3), Some(&b"Homepage"[..]));
        assert_eq!(label(0, true, 2), Some(&b"Unknown"[..]));
        assert_eq!(label(0, false, 1), Some(&b"Unknown"[..]));
        assert_eq!(label(1, true, 0), Some(&b"Everyone"[..]));
        assert_eq!(label(1, true, 1), Some(&b"Logged in Users"[..]));
        assert_eq!(label(1, true, 2), Some(&b"Admins"[..]));
        assert_eq!(label(1, false, 0), Some(&b"Unknown"[..]));
        assert_eq!(label(2, true, 0), None);
        let mut small = [1u8; 2];
        assert_eq!(
            unsafe { nntmux_content_label(1, 1, 2, small.as_mut_ptr(), 2) },
            -6
        );
        assert_eq!(small, [1u8; 2]);
        assert_eq!(
            unsafe { nntmux_content_label(5, 1, 2, small.as_mut_ptr(), 2) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_content_label(0, 1, 1, std::ptr::null_mut(), 32) },
            -1
        );

        let kind = |url: &[u8]| unsafe { nntmux_content_url_kind(url.as_ptr(), url.len()) };
        for (url, expected) in [
            (&b""[..], 0),
            (b"0", 0),
            (b"00", 3),
            (b"http://x", 1),
            (b"https://x", 1),
            (b"HTTP://x", 3),
            (b"google.com", 2),
            (b"a-.co/path", 2),
            (b"-a.co", 3),
            (b"a.c", 3),
            (b"a.c1", 3),
            (b"a..com", 3),
            (b"a_b.com", 3),
            (b"/google.com", 3),
            (b"contact-us", 3),
            (b"sub.example.org", 2),
        ] {
            assert_eq!(kind(url), expected, "{url:?}");
        }
        assert_eq!(unsafe { nntmux_content_url_kind(std::ptr::null(), 0) }, 0);
        assert_eq!(unsafe { nntmux_content_url_kind(std::ptr::null(), 2) }, -1);
    }

    #[test]
    fn site_logo_paths() {
        let mut out = [0u8; 64];
        let url = |path: &[u8], out: &mut [u8]| unsafe {
            nntmux_site_logo_url(path.as_ptr(), path.len(), out.as_mut_ptr(), out.len())
        };
        let written = url(b"site-logos/a.png", &mut out);
        assert_eq!(&out[..written as usize], b"/storage/site-logos/a.png");
        for path in [
            &b""[..],
            b"site-logos",
            b"site-logos/../x",
            b"x/site-logos/a",
            b"Site-logos/a",
            b"site-logos/a..b",
        ] {
            assert_eq!(url(path, &mut out), 0, "{path:?}");
            assert_eq!(
                unsafe { nntmux_site_logo_is_managed(path.as_ptr(), path.len()) },
                0
            );
        }
        assert_eq!(
            unsafe { nntmux_site_logo_is_managed(b"site-logos/".as_ptr(), 11) },
            1
        );
        let mut small = [3u8; 4];
        assert_eq!(url(b"site-logos/a.png", &mut small), -25);
        assert_eq!(small, [3u8; 4]);
        assert_eq!(
            unsafe { nntmux_site_logo_url(std::ptr::null(), 3, out.as_mut_ptr(), 64) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_site_logo_url(b"site-logos/a".as_ptr(), 12, std::ptr::null_mut(), 64) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_site_logo_is_managed(std::ptr::null(), 1) },
            -1
        );
    }
}


// ---------------------------------------------------------------------------
// Wave 23 Slice 23.6: UserActivity, UserActivityObserver, AnidbInfoObserver,
// SteamAppObserver, and AdminDashboardSnapshotService pure-CPU helpers
// ---------------------------------------------------------------------------

const ADD23_MAX_SEG_LEN: usize = (isize::MAX as usize) / 8;

#[inline]
unsafe fn add23_valid_slice<'a>(
    ptr: *const u8,
    len: usize,
    max_len: usize,
) -> Option<&'a [u8]> {
    if ptr.is_null()
        || len > max_len
        || len > isize::MAX as usize
        || (ptr as usize).checked_add(len).is_none()
    {
        return None;
    }
    if len == 0 {
        return Some(&[]);
    }
    Some(unsafe { std::slice::from_raw_parts(ptr, len) })
}

#[inline]
fn add23_valid_out_buf(out_buf: *mut u8, out_cap: usize) -> bool {
    !out_buf.is_null()
        && out_cap <= isize::MAX as usize
        && (out_buf as usize).checked_add(out_cap).is_some()
}

#[inline]
unsafe fn add23_write_slices(
    out_buf: *mut u8,
    out_cap: usize,
    parts: &[&[u8]],
    min_neg: usize,
) -> isize {
    if !add23_valid_out_buf(out_buf, out_cap) {
        return -1;
    }
    let mut needed = 0usize;
    for part in parts {
        let Some(next) = needed.checked_add(part.len()) else {
            return -1;
        };
        if next > isize::MAX as usize {
            return -1;
        }
        needed = next;
    }
    if needed > out_cap {
        return -(needed.max(min_neg) as isize);
    }
    let mut offset = 0usize;
    for part in parts {
        if !part.is_empty() {
            unsafe {
                std::ptr::copy_nonoverlapping(part.as_ptr(), out_buf.add(offset), part.len());
            }
            offset += part.len();
        }
    }
    needed as isize
}

/// Resolves the FontAwesome icon slug for `UserActivity::getIconAttribute()`:
/// - `'registered'` -> `'user-plus'`
/// - `'deleted'` -> `'user-times'`
/// - `'role_updated'` -> `'user-shield'`
/// - default -> `'info-circle'`
///
/// Returns written byte length (`> 0`), `-(needed as isize)` when `out_cap` is too small,
/// or `-1` on invalid pointer/length arguments.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_user_activity_resolve_icon(
    type_ptr: *const u8,
    type_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(activity_type) =
            (unsafe { add23_valid_slice(type_ptr, type_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        let icon: &[u8] = match activity_type {
            b"registered" => b"user-plus",
            b"deleted" => b"user-times",
            b"role_updated" => b"user-shield",
            _ => b"info-circle",
        };
        unsafe { add23_write_slices(out_buf, out_cap, &[icon], 2) }
    }))
    .unwrap_or(-1)
}

/// Resolves the Tailwind color token for `UserActivity::getColorClassAttribute()`:
/// - `'registered'` -> `'green'`
/// - `'deleted'` -> `'red'`
/// - `'role_updated'` -> `'blue'`
/// - default -> `'gray'`
///
/// Returns written byte length (`> 0`), `-(needed as isize)` when `out_cap` is too small,
/// or `-1` on invalid pointer/length arguments.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_user_activity_resolve_color_class(
    type_ptr: *const u8,
    type_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(activity_type) =
            (unsafe { add23_valid_slice(type_ptr, type_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        let color: &[u8] = match activity_type {
            b"registered" => b"green",
            b"deleted" => b"red",
            b"role_updated" => b"blue",
            _ => b"gray",
        };
        unsafe { add23_write_slices(out_buf, out_cap, &[color], 2) }
    }))
    .unwrap_or(-1)
}

/// Resolves the actor label (`'Self'`, authenticated username, or `'System'`) for
/// `UserActivityObserver`:
/// - When `check_self == 1 && has_auth_id == 1 && auth_id == target_user_id`: writes `"Self"`.
/// - Otherwise when `has_auth_username == 1`: writes `auth_username`.
/// - Otherwise: writes `"System"`.
///
/// Returns written byte length (`>= 0`), `-(needed as isize)` when `out_cap` is too small,
/// or `-1` on invalid flags or pointer/length arguments.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_user_activity_observer_resolve_actor(
    has_auth_username: u8,
    auth_username_ptr: *const u8,
    auth_username_len: usize,
    has_auth_id: u8,
    auth_id: i64,
    target_user_id: i64,
    check_self: u8,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if has_auth_username > 1 || has_auth_id > 1 || check_self > 1 {
            return -1;
        }
        let Some(auth_username) = (unsafe {
            add23_valid_slice(auth_username_ptr, auth_username_len, ADD23_MAX_SEG_LEN)
        }) else {
            return -1;
        };
        let label: &[u8] = if check_self == 1 && has_auth_id == 1 && auth_id == target_user_id {
            b"Self"
        } else if has_auth_username == 1 {
            auth_username
        } else {
            b"System"
        };
        unsafe { add23_write_slices(out_buf, out_cap, &[label], 2) }
    }))
    .unwrap_or(-1)
}

/// Formats the fallback role name for `UserActivityObserver::getRoleName()`:
/// - `'None'` when `has_role_id == 0 || role_id == 0`
/// - `"Role #{role_id}"` otherwise
///
/// Returns written byte length (`> 0`), `-(needed as isize)` when `out_cap` is too small,
/// or `-1` on invalid flags or pointer/length arguments.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_user_activity_observer_fallback_role_name(
    has_role_id: u8,
    role_id: i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if has_role_id > 1 {
            return -1;
        }
        if has_role_id == 0 || role_id == 0 {
            return unsafe { add23_write_slices(out_buf, out_cap, &[b"None"], 2) };
        }
        let id_str = role_id.to_string();
        unsafe { add23_write_slices(out_buf, out_cap, &[b"Role #", id_str.as_bytes()], 2) }
    }))
    .unwrap_or(-1)
}

/// Formats NUL-separated `activity_type\0description` and sets `*out_event_code` for
/// `UserActivityObserver::buildActivityPayload()`:
/// - `'created'` (`0`): `"registered\0New user registered: {username}"`
/// - `'updated'` / `'role_updated'` (`1`): `"role_updated\0User role updated: {username} → {new_role_name}"`
/// - `'deleted'` (`2`): `"deleted\0User deleted: {username}"`
/// - `'restored'` (`3`): `"registered\0User restored: {username}"`
/// - `'force_deleted'` (`4`): `"deleted\0User permanently deleted: {username}"`
///
/// Returns written byte length (`> 0`), `-2` on unknown event or embedded NUL in strings,
/// `-(needed as isize)` (`< -2`) when `out_cap` is too small, or `-1` on invalid pointers.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_user_activity_observer_format_event(
    event_ptr: *const u8,
    event_len: usize,
    username_ptr: *const u8,
    username_len: usize,
    new_role_name_ptr: *const u8,
    new_role_name_len: usize,
    out_event_code: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_event_code.is_null() || !out_event_code.is_aligned() {
            return -1;
        }
        let (Some(event), Some(username), Some(new_role_name)) = (
            unsafe { add23_valid_slice(event_ptr, event_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(username_ptr, username_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(new_role_name_ptr, new_role_name_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };
        if username.contains(&0) || new_role_name.contains(&0) {
            return -2;
        }

        let (code, written): (i32, isize) = match event {
            b"created" => (
                0,
                unsafe {
                    add23_write_slices(
                        out_buf,
                        out_cap,
                        &[b"registered\0New user registered: ", username],
                        3,
                    )
                },
            ),
            b"updated" | b"role_updated" => (
                1,
                unsafe {
                    add23_write_slices(
                        out_buf,
                        out_cap,
                        &[
                            b"role_updated\0User role updated: ",
                            username,
                            " \u{2192} ".as_bytes(),
                            new_role_name,
                        ],
                        3,
                    )
                },
            ),
            b"deleted" => (
                2,
                unsafe {
                    add23_write_slices(out_buf, out_cap, &[b"deleted\0User deleted: ", username], 3)
                },
            ),
            b"restored" => (
                3,
                unsafe {
                    add23_write_slices(
                        out_buf,
                        out_cap,
                        &[b"registered\0User restored: ", username],
                        3,
                    )
                },
            ),
            b"force_deleted" => (
                4,
                unsafe {
                    add23_write_slices(
                        out_buf,
                        out_cap,
                        &[b"deleted\0User permanently deleted: ", username],
                        3,
                    )
                },
            ),
            _ => return -2,
        };

        if written < 0 {
            return written;
        }
        unsafe {
            *out_event_code = code;
        }
        written
    }))
    .unwrap_or(-1)
}

#[inline]
fn add23_crc32_update(mut crc: u32, bytes: &[u8]) -> u32 {
    for &b in bytes {
        crc ^= u32::from(b);
        for _ in 0..8 {
            crc = (crc >> 1) ^ (0xedb8_8320 & 0u32.wrapping_sub(crc & 1));
        }
    }
    crc
}

/// Validates and computes the unsigned CRC32 secondary search index document ID for an
/// `AnidbTitle` row in `AnidbInfoObserver::normalizeAnidbTitleRow()`.
///
/// Writes:
/// - `*out_doc_id`: unsigned IEEE CRC32 of `"{anidb_id}|{type}|{lang}|{title}"` widened to `i64`
/// - `*out_valid`: `1` when `anidb_id > 0 && !title.is_empty()`, else `0`
///
/// Returns `1` on success, or `-1` on null/unaligned pointers or invalid slice lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_anidb_info_observer_normalize_title_row(
    anidb_id: i64,
    type_ptr: *const u8,
    type_len: usize,
    lang_ptr: *const u8,
    lang_len: usize,
    title_ptr: *const u8,
    title_len: usize,
    out_doc_id: *mut i64,
    out_valid: *mut i32,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_doc_id.is_null()
            || !out_doc_id.is_aligned()
            || out_valid.is_null()
            || !out_valid.is_aligned()
        {
            return -1;
        }
        let (Some(title_type), Some(lang), Some(title)) = (
            unsafe { add23_valid_slice(type_ptr, type_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(lang_ptr, lang_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(title_ptr, title_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };

        let id_str = anidb_id.to_string();
        let mut crc = !0u32;
        crc = add23_crc32_update(crc, id_str.as_bytes());
        crc = add23_crc32_update(crc, b"|");
        crc = add23_crc32_update(crc, title_type);
        crc = add23_crc32_update(crc, b"|");
        crc = add23_crc32_update(crc, lang);
        crc = add23_crc32_update(crc, b"|");
        crc = add23_crc32_update(crc, title);
        let doc_id = i64::from(!crc);
        let valid = i32::from(anidb_id > 0 && !title.is_empty());

        unsafe {
            *out_doc_id = doc_id;
            *out_valid = valid;
        }
        1
    }))
    .unwrap_or(-1)
}

/// Extracts and validates a positive Steam app ID (`(int) ($rawId ?? 0) > 0`) for
/// `SteamAppObserver::deleted()` and `SteamAppObserver::sync()`.
///
/// `kind`:
/// - `0`: null / false (`*out_id = 0`, returns `0`)
/// - `1`: integer `int_val` (`*out_id = int_val.max(0)`, returns `1` if `int_val > 0`, else `0`)
/// - `2`: string `str_ptr[..str_len]` parsed with PHP `(int)` rules (`-2` if scientific/float notation)
///
/// Returns `1` when eligible (`*out_id > 0`), `0` when ineligible (`*out_id == 0`),
/// `-2` when a string requires PHP float coercion, or `-1` on invalid arguments.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_steam_app_observer_resolve_id(
    kind: u8,
    int_val: i64,
    str_ptr: *const u8,
    str_len: usize,
    out_id: *mut i64,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_id.is_null() || !out_id.is_aligned() {
            return -1;
        }
        let Some(raw_str) = (unsafe { add23_valid_slice(str_ptr, str_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };

        let parsed: i64 = match kind {
            0 => 0,
            1 => int_val,
            2 => {
                let trimmed_start = raw_str
                    .iter()
                    .position(|&b| !matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C))
                    .map_or(&[][..], |idx| &raw_str[idx..]);
                let Some(val) = w22_php_int(trimmed_start) else {
                    return -2;
                };
                val
            }
            _ => return -1,
        };

        if parsed > 0 {
            unsafe {
                *out_id = parsed;
            }
            1
        } else {
            unsafe {
                *out_id = 0;
            }
            0
        }
    }))
    .unwrap_or(-1)
}

/// Formats NUL-separated `icon_bg\0icon_color` Tailwind badge classes for
/// `AdminDashboardSnapshotService::buildRecentActivity()`:
/// - `icon_bg`: `"bg-{color}-100 dark:bg-{color}-900"`
/// - `icon_color`: `"text-{color}-600 dark:text-{color}-400"`
///
/// Returns written byte length (`> 0`), `-2` when `color` contains a NUL byte,
/// `-(needed as isize)` when `out_cap` is too small, or `-1` on invalid arguments.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_admin_dashboard_activity_badge_classes_nul(
    color_ptr: *const u8,
    color_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(color) = (unsafe { add23_valid_slice(color_ptr, color_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        if color.contains(&0) {
            return -2;
        }
        unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    b"bg-",
                    color,
                    b"-100 dark:bg-",
                    color,
                    b"-900\0text-",
                    color,
                    b"-600 dark:text-",
                    color,
                    b"-400",
                ],
                3,
            )
        }
    }))
    .unwrap_or(-1)
}

/// Normalizes a recent payment row for `AdminDashboardSnapshotService::buildRecentPayments()`.
/// Writes NUL-separated `username\0email\0item_description\0invoice_amount\0payment_method\0payment_status\0invoice_status\0order_id`
/// into `out_buf` (defaulting `invoice_status` to `"Pending"` when `has_invoice_status == 0`)
/// and stores `raw_id` in `*out_id`.
///
/// Returns written byte length (`>= 7`), `-2` if any input field contains a NUL byte,
/// `-(needed as isize)` when `out_cap` is too small, or `-1` on invalid arguments.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_admin_dashboard_normalize_payment_row_nul(
    raw_id: i64,
    username_ptr: *const u8,
    username_len: usize,
    email_ptr: *const u8,
    email_len: usize,
    item_desc_ptr: *const u8,
    item_desc_len: usize,
    invoice_amount_ptr: *const u8,
    invoice_amount_len: usize,
    payment_method_ptr: *const u8,
    payment_method_len: usize,
    payment_status_ptr: *const u8,
    payment_status_len: usize,
    has_invoice_status: u8,
    invoice_status_ptr: *const u8,
    invoice_status_len: usize,
    order_id_ptr: *const u8,
    order_id_len: usize,
    out_id: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if has_invoice_status > 1 || out_id.is_null() || !out_id.is_aligned() {
            return -1;
        }
        let (
            Some(username),
            Some(email),
            Some(item_desc),
            Some(invoice_amount),
            Some(payment_method),
            Some(payment_status),
            Some(invoice_status_in),
            Some(order_id),
        ) = (
            unsafe { add23_valid_slice(username_ptr, username_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(email_ptr, email_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(item_desc_ptr, item_desc_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(invoice_amount_ptr, invoice_amount_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(payment_method_ptr, payment_method_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(payment_status_ptr, payment_status_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(invoice_status_ptr, invoice_status_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(order_id_ptr, order_id_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };

        let invoice_status: &[u8] = if has_invoice_status == 1 {
            invoice_status_in
        } else {
            b"Pending"
        };

        if username.contains(&0)
            || email.contains(&0)
            || item_desc.contains(&0)
            || invoice_amount.contains(&0)
            || payment_method.contains(&0)
            || payment_status.contains(&0)
            || invoice_status.contains(&0)
            || order_id.contains(&0)
        {
            return -2;
        }

        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    username,
                    b"\0",
                    email,
                    b"\0",
                    item_desc,
                    b"\0",
                    invoice_amount,
                    b"\0",
                    payment_method,
                    b"\0",
                    payment_status,
                    b"\0",
                    invoice_status,
                    b"\0",
                    order_id,
                ],
                3,
            )
        };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_id = raw_id;
        }
        written
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave23_dashboard_activity_observer_tests {
    use super::*;

    #[test]
    fn user_activity_and_observer_helpers_match_php_contracts() {
        let mut buf = [0u8; 256];

        let n = unsafe {
            nntmux_user_activity_resolve_icon(b"registered".as_ptr(), 10, buf.as_mut_ptr(), buf.len())
        } as usize;
        assert_eq!(&buf[..n], b"user-plus");

        let n = unsafe {
            nntmux_user_activity_resolve_color_class(
                b"role_updated".as_ptr(),
                12,
                buf.as_mut_ptr(),
                buf.len(),
            )
        } as usize;
        assert_eq!(&buf[..n], b"blue");

        let n = unsafe {
            nntmux_user_activity_observer_resolve_actor(
                1,
                b"alice".as_ptr(),
                5,
                1,
                7,
                7,
                1,
                buf.as_mut_ptr(),
                buf.len(),
            )
        } as usize;
        assert_eq!(&buf[..n], b"Self");

        let n = unsafe {
            nntmux_user_activity_observer_fallback_role_name(1, 5, buf.as_mut_ptr(), buf.len())
        } as usize;
        assert_eq!(&buf[..n], b"Role #5");

        let mut code = -1i32;
        let n = unsafe {
            nntmux_user_activity_observer_format_event(
                b"updated".as_ptr(),
                7,
                b"alice".as_ptr(),
                5,
                b"Admin".as_ptr(),
                5,
                &mut code,
                buf.as_mut_ptr(),
                buf.len(),
            )
        } as usize;
        assert_eq!(code, 1);
        assert_eq!(
            &buf[..n],
            "role_updated\0User role updated: alice \u{2192} Admin".as_bytes()
        );
    }

    #[test]
    fn anidb_steam_and_dashboard_snapshot_helpers_match_php_contracts() {
        let mut doc_id = -1i64;
        let mut valid = -1i32;
        assert_eq!(
            unsafe {
                nntmux_anidb_info_observer_normalize_title_row(
                    42,
                    b"main".as_ptr(),
                    4,
                    b"en".as_ptr(),
                    2,
                    b"Cowboy Bebop".as_ptr(),
                    12,
                    &mut doc_id,
                    &mut valid,
                )
            },
            1
        );
        assert_eq!(valid, 1);
        assert_eq!(
            doc_id,
            i64::from(!add23_crc32_update(!0u32, b"42|main|en|Cowboy Bebop"))
        );

        let mut steam_id = -1i64;
        assert_eq!(
            unsafe {
                nntmux_steam_app_observer_resolve_id(2, 0, b"  570abc".as_ptr(), 8, &mut steam_id)
            },
            1
        );
        assert_eq!(steam_id, 570);

        let mut buf = [0u8; 256];
        let n = unsafe {
            nntmux_admin_dashboard_activity_badge_classes_nul(
                b"green".as_ptr(),
                5,
                buf.as_mut_ptr(),
                buf.len(),
            )
        } as usize;
        assert_eq!(
            &buf[..n],
            b"bg-green-100 dark:bg-green-900\0text-green-600 dark:text-green-400"
        );

        let mut pay_id = -1i64;
        let n = unsafe {
            nntmux_admin_dashboard_normalize_payment_row_nul(
                99,
                b"alice".as_ptr(),
                5,
                b"a@b.c".as_ptr(),
                5,
                b"VIP".as_ptr(),
                3,
                b"10.00".as_ptr(),
                5,
                b"BTC".as_ptr(),
                3,
                b"Settled".as_ptr(),
                7,
                0,
                b"".as_ptr(),
                0,
                b"ord-1".as_ptr(),
                5,
                &mut pay_id,
                buf.as_mut_ptr(),
                buf.len(),
            )
        } as usize;
        assert_eq!(pay_id, 99);
        assert_eq!(
            &buf[..n],
            b"alice\0a@b.c\0VIP\010.00\0BTC\0Settled\0Pending\0ord-1"
        );
    }

    #[test]
    fn wave23_slice6_entrypoints_reject_null_unaligned_and_undersized_buffers() {
        let mut raw = [0u8; 64];
        let unaligned_i64 = unsafe { raw.as_mut_ptr().add(1).cast::<i64>() };
        let unaligned_i32 = unsafe { raw.as_mut_ptr().add(1).cast::<i32>() };
        let mut small = [7u8; 2];

        assert_eq!(
            unsafe { nntmux_user_activity_resolve_icon(b"registered".as_ptr(), 10, small.as_mut_ptr(), 2) },
            -9
        );
        assert_eq!(small, [7u8; 2]);
        assert_eq!(
            unsafe { nntmux_user_activity_resolve_color_class(b"deleted".as_ptr(), 7, small.as_mut_ptr(), 2) },
            -3
        );
        assert_eq!(small, [7u8; 2]);
        assert_eq!(
            unsafe {
                nntmux_user_activity_observer_format_event(
                    b"created".as_ptr(),
                    7,
                    b"u".as_ptr(),
                    1,
                    b"".as_ptr(),
                    0,
                    unaligned_i32,
                    raw.as_mut_ptr(),
                    32,
                )
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_anidb_info_observer_normalize_title_row(
                    1,
                    b"a".as_ptr(),
                    1,
                    b"b".as_ptr(),
                    1,
                    b"c".as_ptr(),
                    1,
                    unaligned_i64,
                    &mut 0,
                )
            },
            -1
        );
        assert_eq!(
            unsafe { nntmux_steam_app_observer_resolve_id(1, 5, b"".as_ptr(), 0, unaligned_i64) },
            -1
        );
    }
}

// ---------------------------------------------------------------------------
// Wave 24 Slice 24.6: UserDownload, UserRequest, UserMovie, UserSerie, and
// UsersRelease pure-CPU helpers
// ---------------------------------------------------------------------------

#[inline]
unsafe fn add24_valid_i64_slice<'a>(
    ptr: *const i64,
    len: usize,
    max_len: usize,
) -> Option<&'a [i64]> {
    if ptr.is_null()
        || !ptr.is_aligned()
        || len > max_len
        || len > (isize::MAX as usize) / std::mem::size_of::<i64>()
    {
        return None;
    }
    let byte_len = len.checked_mul(std::mem::size_of::<i64>())?;
    if (ptr as usize).checked_add(byte_len).is_none() {
        return None;
    }
    if len == 0 {
        return Some(&[]);
    }
    Some(unsafe { std::slice::from_raw_parts(ptr, len) })
}

#[inline]
fn add24_valid_i64_out(out_ptr: *mut i64, out_cap: usize, max_len: usize) -> bool {
    if out_ptr.is_null()
        || !out_ptr.is_aligned()
        || out_cap > max_len
        || out_cap > (isize::MAX as usize) / std::mem::size_of::<i64>()
    {
        return false;
    }
    let Some(byte_len) = out_cap.checked_mul(std::mem::size_of::<i64>()) else {
        return false;
    };
    (out_ptr as usize).checked_add(byte_len).is_some()
}

#[inline]
fn add24_split_nul_exact<'a>(bytes: &'a [u8], expected_count: usize) -> Option<Vec<&'a [u8]>> {
    if expected_count == 0 {
        return if bytes.is_empty() {
            Some(Vec::new())
        } else {
            None
        };
    }
    let parts: Vec<&'a [u8]> = bytes.split(|&b| b == 0).collect();
    if parts.len() != expected_count {
        return None;
    }
    Some(parts)
}

#[inline]
fn add24_trim_php_ascii(bytes: &[u8]) -> &[u8] {
    let is_ws = |b: u8| matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C);
    let Some(start) = bytes.iter().position(|&b| !is_ws(b)) else {
        return &[];
    };
    let end = bytes.iter().rposition(|&b| !is_ws(b)).map_or(start, |i| i + 1);
    &bytes[start..end]
}

unsafe fn add24_aggregate_hourly_buckets_nul(
    hour_keys_ptr: *const u8,
    hour_keys_len: usize,
    hour_keys_count: usize,
    event_keys_ptr: *const u8,
    event_keys_len: usize,
    event_keys_count: usize,
    out_counts: *mut i64,
    out_cap: usize,
) -> isize {
    if hour_keys_count > ADD23_MAX_SEG_LEN
        || event_keys_count > ADD23_MAX_SEG_LEN
        || !add24_valid_i64_out(out_counts, out_cap, ADD23_MAX_SEG_LEN)
    {
        return -1;
    }
    let (Some(hour_bytes), Some(event_bytes)) = (
        unsafe { add23_valid_slice(hour_keys_ptr, hour_keys_len, ADD23_MAX_SEG_LEN) },
        unsafe { add23_valid_slice(event_keys_ptr, event_keys_len, ADD23_MAX_SEG_LEN) },
    ) else {
        return -1;
    };
    let (Some(hour_parts), Some(event_parts)) = (
        add24_split_nul_exact(hour_bytes, hour_keys_count),
        add24_split_nul_exact(event_bytes, event_keys_count),
    ) else {
        return -1;
    };

    let mut unique_keys: Vec<&[u8]> = Vec::with_capacity(hour_parts.len());
    for key in hour_parts {
        if !unique_keys.contains(&key) {
            unique_keys.push(key);
        }
    }

    let needed = unique_keys.len();
    if needed > out_cap {
        return -(needed.max(2) as isize);
    }

    let mut counts = vec![0i64; needed];
    for ev in event_parts {
        if let Some(pos) = unique_keys.iter().position(|&k| k == ev) {
            counts[pos] = counts[pos].saturating_add(1);
        }
    }

    for (i, &c) in counts.iter().enumerate() {
        unsafe {
            *out_counts.add(i) = c;
        }
    }
    needed as isize
}

/// Aggregates 24-hour download bucket counts for `UserDownload::aggregateHourlyBuckets()`.
///
/// `hour_keys_ptr[..hour_keys_len]` contains `hour_keys_count` NUL-separated bucket keys,
/// and `event_keys_ptr[..event_keys_len]` contains `event_keys_count` NUL-separated event
/// hour keys. Writes the count for each unique bucket key (in first-seen order) into
/// `out_counts[..unique_count]`.
///
/// Returns `unique_count` (`>= 0`), `-(needed as isize)` (`<= -2`) when `out_cap` is too
/// small, or `-1` on invalid arguments.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_user_download_aggregate_hourly_buckets_nul(
    hour_keys_ptr: *const u8,
    hour_keys_len: usize,
    hour_keys_count: usize,
    event_keys_ptr: *const u8,
    event_keys_len: usize,
    event_keys_count: usize,
    out_counts: *mut i64,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        add24_aggregate_hourly_buckets_nul(
            hour_keys_ptr,
            hour_keys_len,
            hour_keys_count,
            event_keys_ptr,
            event_keys_len,
            event_keys_count,
            out_counts,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

/// Expands `user_id` and `release_ids_ptr[..release_ids_len]` into interleaved
/// `[user_id, release_id]` `i64` pairs in `out_pairs` for
/// `UserDownload::buildDownloadBatchRows()`.
///
/// Returns `release_ids_len as isize` (`>= 0`), `-(needed_i64s as isize)` (`<= -2`) when
/// `out_pairs_cap < release_ids_len * 2`, or `-1` on null/unaligned pointers.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_user_download_normalize_batch_ids(
    user_id: i64,
    release_ids_ptr: *const i64,
    release_ids_len: usize,
    out_pairs: *mut i64,
    out_pairs_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !add24_valid_i64_out(out_pairs, out_pairs_cap, ADD23_MAX_SEG_LEN) {
            return -1;
        }
        let Some(release_ids) =
            (unsafe { add24_valid_i64_slice(release_ids_ptr, release_ids_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        let Some(needed_i64s) = release_ids.len().checked_mul(2) else {
            return -1;
        };
        if needed_i64s > isize::MAX as usize {
            return -1;
        }
        if needed_i64s > out_pairs_cap {
            return -(needed_i64s.max(2) as isize);
        }
        for (i, &rel_id) in release_ids.iter().enumerate() {
            unsafe {
                *out_pairs.add(i * 2) = user_id;
                *out_pairs.add(i * 2 + 1) = rel_id;
            }
        }
        release_ids.len() as isize
    }))
    .unwrap_or(-1)
}

/// Aggregates 24-hour API request bucket counts for `UserRequest::aggregateHourlyRequestBuckets()`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_user_request_aggregate_hourly_buckets_nul(
    hour_keys_ptr: *const u8,
    hour_keys_len: usize,
    hour_keys_count: usize,
    event_keys_ptr: *const u8,
    event_keys_len: usize,
    event_keys_count: usize,
    out_counts: *mut i64,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        add24_aggregate_hourly_buckets_nul(
            hour_keys_ptr,
            hour_keys_len,
            hour_keys_count,
            event_keys_ptr,
            event_keys_len,
            event_keys_count,
            out_counts,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

/// Classifies whether `$tokenOrUserId` in `UserRequest::addApiRequest()` is a direct integer
/// user ID (`value_kind == 1` -> `1`) vs an API token string or non-int (`value_kind == 0` -> `0`).
/// Returns `-1` when `value_kind > 1`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_user_request_is_direct_user_id(
    value_kind: u8,
    _int_val: i64,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| match value_kind {
        0 => 0,
        1 => 1,
        _ => -1,
    }))
    .unwrap_or(-1)
}

unsafe fn add24_serialize_category_ids_nul(
    cats_ptr: *const u8,
    cats_len: usize,
    cats_count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if cats_count > ADD23_MAX_SEG_LEN || !add23_valid_out_buf(out_buf, out_cap) {
        return -1;
    }
    let Some(cats_bytes) = (unsafe { add23_valid_slice(cats_ptr, cats_len, ADD23_MAX_SEG_LEN) })
    else {
        return -1;
    };
    let Some(parts) = add24_split_nul_exact(cats_bytes, cats_count) else {
        return -1;
    };
    if parts.is_empty() {
        return unsafe { add23_write_slices(out_buf, out_cap, &[b"NULL"], 2) };
    }

    let mut needed = 0usize;
    for (idx, part) in parts.iter().enumerate() {
        if idx > 0 {
            needed = match needed.checked_add(1) {
                Some(n) => n,
                None => return -1,
            };
        }
        needed = match needed.checked_add(part.len()) {
            Some(n) => n,
            None => return -1,
        };
    }
    if needed > isize::MAX as usize {
        return -1;
    }
    if needed > out_cap {
        return -(needed.max(2) as isize);
    }

    let mut offset = 0usize;
    for (idx, part) in parts.iter().enumerate() {
        if idx > 0 {
            unsafe {
                *out_buf.add(offset) = b'|';
            }
            offset += 1;
        }
        if !part.is_empty() {
            unsafe {
                std::ptr::copy_nonoverlapping(part.as_ptr(), out_buf.add(offset), part.len());
            }
            offset += part.len();
        }
    }
    needed as isize
}

/// Serializes category IDs as pipe-delimited string (`!empty($catID) ? implode('|', $catID) : 'NULL'`)
/// for `UserMovie::serializeCategoryIds()`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_user_movie_serialize_category_ids_nul(
    cats_ptr: *const u8,
    cats_len: usize,
    cats_count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        add24_serialize_category_ids_nul(cats_ptr, cats_len, cats_count, out_buf, out_cap)
    }))
    .unwrap_or(-1)
}

/// Serializes TV series category IDs as pipe-delimited string (`!empty($catID) ? implode('|', $catID) : 'NULL'`)
/// for `UserSerie::serializeSeriesCategoryIds()`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_user_serie_serialize_category_ids_nul(
    cats_ptr: *const u8,
    cats_len: usize,
    cats_count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        add24_serialize_category_ids_nul(cats_ptr, cats_len, cats_count, out_buf, out_cap)
    }))
    .unwrap_or(-1)
}

/// Trims, filters out PHP-falsy strings (`""` and `"0"`), and deduplicates cart GUIDs in
/// first-seen order for `UsersRelease::normalizeCartGuids()`.
///
/// Writes NUL-separated normalized GUIDs into `out_buf` and stores the normalized GUID count
/// into `*out_count`.
/// Returns written byte length (`>= 0`), `-(needed as isize)` (`<= -2`) when `out_cap` is too
/// small, or `-1` on invalid arguments.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_users_release_normalize_cart_guids_nul(
    guids_ptr: *const u8,
    guids_len: usize,
    guids_count: usize,
    out_count: *mut usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if guids_count > ADD23_MAX_SEG_LEN
            || out_count.is_null()
            || !out_count.is_aligned()
            || !add23_valid_out_buf(out_buf, out_cap)
        {
            return -1;
        }
        let Some(guids_bytes) =
            (unsafe { add23_valid_slice(guids_ptr, guids_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        let Some(parts) = add24_split_nul_exact(guids_bytes, guids_count) else {
            return -1;
        };

        let mut unique: Vec<&[u8]> = Vec::with_capacity(parts.len());
        for raw in parts {
            let trimmed = add24_trim_php_ascii(raw);
            if trimmed.is_empty() || trimmed == b"0" {
                continue;
            }
            if !unique.contains(&trimmed) {
                unique.push(trimmed);
            }
        }

        if unique.is_empty() {
            unsafe {
                *out_count = 0;
            }
            return 0;
        }

        let mut needed = 0usize;
        for (idx, item) in unique.iter().enumerate() {
            if idx > 0 {
                needed = match needed.checked_add(1) {
                    Some(n) => n,
                    None => return -1,
                };
            }
            needed = match needed.checked_add(item.len()) {
                Some(n) => n,
                None => return -1,
            };
        }
        if needed > isize::MAX as usize {
            return -1;
        }
        if needed > out_cap {
            return -(needed.max(2) as isize);
        }

        let mut offset = 0usize;
        for (idx, item) in unique.iter().enumerate() {
            if idx > 0 {
                unsafe {
                    *out_buf.add(offset) = 0;
                }
                offset += 1;
            }
            unsafe {
                std::ptr::copy_nonoverlapping(item.as_ptr(), out_buf.add(offset), item.len());
            }
            offset += item.len();
        }
        unsafe {
            *out_count = unique.len();
        }
        needed as isize
    }))
    .unwrap_or(-1)
}

/// Deduplicates `release_ids_ptr[..release_ids_len]` in first-seen order for
/// `UsersRelease::deduplicateReleaseIds()`.
///
/// Returns the number of unique IDs written (`>= 0`), `-(needed as isize)` (`<= -2`) when
/// `out_cap` is too small, or `-1` on null/unaligned pointers.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_users_release_deduplicate_release_ids(
    release_ids_ptr: *const i64,
    release_ids_len: usize,
    out_ids: *mut i64,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !add24_valid_i64_out(out_ids, out_cap, ADD23_MAX_SEG_LEN) {
            return -1;
        }
        let Some(release_ids) =
            (unsafe { add24_valid_i64_slice(release_ids_ptr, release_ids_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };

        let mut unique: Vec<i64> = Vec::with_capacity(release_ids.len());
        for &id in release_ids {
            if !unique.contains(&id) {
                unique.push(id);
            }
        }

        let needed = unique.len();
        if needed > out_cap {
            return -(needed.max(2) as isize);
        }
        for (i, &id) in unique.iter().enumerate() {
            unsafe {
                *out_ids.add(i) = id;
            }
        }
        needed as isize
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave24_user_watchlist_cart_activity_tests {
    use super::*;

    #[test]
    fn hourly_bucket_aggregation_and_batch_pairs_match_php_contracts() {
        let hour_keys = b"00:00\001:00\002:00";
        let event_keys = b"01:00\099:00\001:00\000:00";
        let mut counts = [0i64; 4];

        let n = unsafe {
            nntmux_user_download_aggregate_hourly_buckets_nul(
                hour_keys.as_ptr(),
                hour_keys.len(),
                3,
                event_keys.as_ptr(),
                event_keys.len(),
                4,
                counts.as_mut_ptr(),
                counts.len(),
            )
        };
        assert_eq!(n, 3);
        assert_eq!(&counts[..3], &[1, 2, 0]);

        let n_req = unsafe {
            nntmux_user_request_aggregate_hourly_buckets_nul(
                hour_keys.as_ptr(),
                hour_keys.len(),
                3,
                b"".as_ptr(),
                0,
                0,
                counts.as_mut_ptr(),
                counts.len(),
            )
        };
        assert_eq!(n_req, 3);
        assert_eq!(&counts[..3], &[0, 0, 0]);

        let rel_ids = [101i64, 202, 303];
        let mut pairs = [0i64; 6];
        let pair_rows = unsafe {
            nntmux_user_download_normalize_batch_ids(
                77,
                rel_ids.as_ptr(),
                rel_ids.len(),
                pairs.as_mut_ptr(),
                pairs.len(),
            )
        };
        assert_eq!(pair_rows, 3);
        assert_eq!(pairs, [77, 101, 77, 202, 77, 303]);

        assert_eq!(unsafe { nntmux_user_request_is_direct_user_id(1, 42) }, 1);
        assert_eq!(unsafe { nntmux_user_request_is_direct_user_id(0, 0) }, 0);
        assert_eq!(unsafe { nntmux_user_request_is_direct_user_id(2, 0) }, -1);
    }

    #[test]
    fn category_serialization_cart_guid_normalization_and_release_dedup_match_php() {
        let mut buf = [0u8; 64];

        let n_empty = unsafe {
            nntmux_user_movie_serialize_category_ids_nul(
                b"".as_ptr(),
                0,
                0,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(n_empty, 4);
        assert_eq!(&buf[..4], b"NULL");

        let cats = b"2030\02040\02050";
        let n_cats = unsafe {
            nntmux_user_serie_serialize_category_ids_nul(
                cats.as_ptr(),
                cats.len(),
                3,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(n_cats, 14);
        assert_eq!(&buf[..14], b"2030|2040|2050");

        let guids = b"  abc \0\0   \00\0def\0abc\0\tdef\n";
        let mut out_count = 0usize;
        let written = unsafe {
            nntmux_users_release_normalize_cart_guids_nul(
                guids.as_ptr(),
                guids.len(),
                7,
                &mut out_count,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(out_count, 2);
        assert_eq!(written, 7);
        assert_eq!(&buf[..7], b"abc\0def");

        let ids = [10i64, 20, 10, 30, 20, 40];
        let mut out_ids = [0i64; 6];
        let uniq = unsafe {
            nntmux_users_release_deduplicate_release_ids(
                ids.as_ptr(),
                ids.len(),
                out_ids.as_mut_ptr(),
                out_ids.len(),
            )
        };
        assert_eq!(uniq, 4);
        assert_eq!(&out_ids[..4], &[10, 20, 30, 40]);
    }

    #[test]
    fn wave24_slice6_entrypoints_reject_null_unaligned_and_undersized_buffers() {
        let mut raw = [0xA5u8; 64];
        let unaligned_i64 = unsafe { raw.as_mut_ptr().add(1).cast::<i64>() };
        let unaligned_usize = unsafe { raw.as_mut_ptr().add(1).cast::<usize>() };

        assert_eq!(
            unsafe {
                nntmux_user_download_aggregate_hourly_buckets_nul(
                    b"00:00".as_ptr(),
                    5,
                    1,
                    b"".as_ptr(),
                    0,
                    0,
                    unaligned_i64,
                    4,
                )
            },
            -1
        );

        let mut small_i64 = [99i64; 1];
        assert_eq!(
            unsafe {
                nntmux_user_download_aggregate_hourly_buckets_nul(
                    b"00:00\001:00".as_ptr(),
                    11,
                    2,
                    b"".as_ptr(),
                    0,
                    0,
                    small_i64.as_mut_ptr(),
                    1,
                )
            },
            -2
        );
        assert_eq!(small_i64[0], 99);

        let mut small_u8 = [0x5Au8; 3];
        assert_eq!(
            unsafe {
                nntmux_user_movie_serialize_category_ids_nul(
                    b"".as_ptr(),
                    0,
                    0,
                    small_u8.as_mut_ptr(),
                    3,
                )
            },
            -4
        );
        assert_eq!(small_u8, [0x5Au8; 3]);

        assert_eq!(
            unsafe {
                nntmux_users_release_normalize_cart_guids_nul(
                    b"abc".as_ptr(),
                    3,
                    1,
                    unaligned_usize,
                    raw.as_mut_ptr(),
                    32,
                )
            },
            -1
        );

        let ids = [1i64, 2, 3];
        assert_eq!(
            unsafe {
                nntmux_users_release_deduplicate_release_ids(
                    ids.as_ptr(),
                    ids.len(),
                    small_i64.as_mut_ptr(),
                    1,
                )
            },
            -3
        );
        assert_eq!(small_i64[0], 99);
    }
}

// ---------------------------------------------------------------------------
// Wave 25 Slice 25.6: GdprRetentionService, GdprAuditService,
// ReviewNntmuxChangePrompt, RolePromotionObserver, and UserServiceObserver
// pure-CPU helpers
// ---------------------------------------------------------------------------

pub mod gdpr_retention_prompt_observer {
    use super::{ADD23_MAX_SEG_LEN, add23_valid_out_buf, add23_valid_slice, add23_write_slices};

    pub const POLICY_EXPORT_FILES: &[u8] = b"7 days after generation, then safe to purge.";
    pub const POLICY_GDPR_REQUESTS: &[u8] = b"Retained as an accountability record.";
    pub const POLICY_PAYMENTS: &[u8] = b"Retained for accounting, tax, fraud prevention, dispute handling, and legal obligations with direct account identifiers anonymized during erasure where practical.";
    pub const POLICY_AUDIT: &[u8] =
        b"Retained in anonymized or minimized form for security and administrative accountability.";
    pub const POLICY_COOKIES: &[u8] = b"Essential cookies/storage only; no marketing or analytics cookies are set by GDPR functionality.";

    pub const REVIEW_PROMPT_ASSISTANT: &[u8] = b"Review the selected NNTmux change as a repository specialist. Inspect scoped diffs and dependants, verify applicable AGENTS.md and .ai/rules guidance, identify behavior and failure-mode risks, check test adequacy, then run only the targeted allowlisted verification that the paths require. Report findings by severity with concrete file evidence, followed by verification results and residual risk.";
    pub const REVIEW_PROMPT_DEFAULT_PATHS: &[u8] = b"Derive them from the current scoped diff.";

    #[inline]
    pub unsafe fn policy_strings_nul(out_buf: *mut u8, out_cap: usize) -> isize {
        unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    POLICY_EXPORT_FILES,
                    b"\0",
                    POLICY_GDPR_REQUESTS,
                    b"\0",
                    POLICY_PAYMENTS,
                    b"\0",
                    POLICY_AUDIT,
                    b"\0",
                    POLICY_COOKIES,
                ],
                2,
            )
        }
    }

    #[inline]
    pub unsafe fn resolve_purge_target(
        has_disk: u8,
        disk_ptr: *const u8,
        disk_len: usize,
        has_path: u8,
        path_ptr: *const u8,
        path_len: usize,
        has_expires_at: u8,
        expires_at_epoch: i64,
        now_epoch: i64,
        out_should_purge: *mut i32,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if has_disk > 1
            || has_path > 1
            || has_expires_at > 1
            || out_should_purge.is_null()
            || !out_should_purge.is_aligned()
        {
            return -1;
        }
        let (Some(disk_in), Some(path_in)) = (
            unsafe { add23_valid_slice(disk_ptr, disk_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(path_ptr, path_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };
        if disk_in.contains(&0) || path_in.contains(&0) {
            return -2;
        }

        let effective_disk: &[u8] = if has_disk == 1 && !disk_in.is_empty() && disk_in != b"0" {
            disk_in
        } else {
            b"local"
        };
        let effective_path: &[u8] = if has_path == 1 { path_in } else { b"" };
        let should_purge = i32::from(
            has_path == 1
                && !effective_path.is_empty()
                && has_expires_at == 1
                && expires_at_epoch < now_epoch,
        );

        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[effective_disk, b"\0", effective_path],
                3,
            )
        };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_should_purge = should_purge;
        }
        written
    }

    #[inline]
    pub unsafe fn normalize_audit_payload_nul(
        event_ptr: *const u8,
        event_len: usize,
        desc_ptr: *const u8,
        desc_len: usize,
        has_subject_id: u8,
        subject_id: i64,
        has_actor_id: u8,
        actor_id: i64,
        has_request_id: u8,
        request_id: i64,
        metadata_count: usize,
        out_scalars: *mut i64,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if has_subject_id > 1
            || has_actor_id > 1
            || has_request_id > 1
            || metadata_count > ADD23_MAX_SEG_LEN
            || out_scalars.is_null()
            || !out_scalars.is_aligned()
            || (out_scalars as usize)
                .checked_add(4 * std::mem::size_of::<i64>())
                .is_none()
            || !add23_valid_out_buf(out_buf, out_cap)
        {
            return -1;
        }
        let (Some(event), Some(desc)) = (
            unsafe { add23_valid_slice(event_ptr, event_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(desc_ptr, desc_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };
        if event.contains(&0) || desc.contains(&0) {
            return -2;
        }

        let written = unsafe { add23_write_slices(out_buf, out_cap, &[event, b"\0", desc], 3) };
        if written < 0 {
            return written;
        }

        let mut flags = 0i64;
        if has_request_id == 1 {
            flags |= 1;
        }
        if has_subject_id == 1 {
            flags |= 2;
        }
        if has_actor_id == 1 {
            flags |= 4;
        }
        if metadata_count > 0 {
            flags |= 8;
        }

        unsafe {
            *out_scalars.add(0) = flags;
            *out_scalars.add(1) = if has_request_id == 1 { request_id } else { 0 };
            *out_scalars.add(2) = if has_subject_id == 1 { subject_id } else { 0 };
            *out_scalars.add(3) = if has_actor_id == 1 { actor_id } else { 0 };
        }
        written
    }

    #[inline]
    pub unsafe fn format_review_prompt_messages_nul(
        goal_ptr: *const u8,
        goal_len: usize,
        has_paths: u8,
        paths_ptr: *const u8,
        paths_len: usize,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if has_paths > 1 {
            return -1;
        }
        let (Some(goal), Some(paths_in)) = (
            unsafe { add23_valid_slice(goal_ptr, goal_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(paths_ptr, paths_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };
        if goal.contains(&0) || (has_paths == 1 && paths_in.contains(&0)) {
            return -2;
        }
        let effective_paths: &[u8] = if has_paths == 1 {
            paths_in
        } else {
            REVIEW_PROMPT_DEFAULT_PATHS
        };
        unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    REVIEW_PROMPT_ASSISTANT,
                    b"\0Change goal:\n",
                    goal,
                    b"\n\nChanged paths:\n",
                    effective_paths,
                ],
                3,
            )
        }
    }

    #[inline]
    pub unsafe fn evaluate_role_promotion_expiry(
        is_active: u8,
        has_end_date: u8,
        end_timestamp_us: i64,
        now_timestamp_us: i64,
        out_flags: *mut i32,
    ) -> i32 {
        if is_active > 1 || has_end_date > 1 || out_flags.is_null() || !out_flags.is_aligned() {
            return -1;
        }
        let is_expired = has_end_date == 1 && now_timestamp_us > end_timestamp_us;
        let next_is_active = if is_expired { false } else { is_active == 1 };
        let should_disable_on_saving = is_expired;
        let should_persist_quietly_on_retrieved = is_active == 1 && is_expired;

        let mut mask = 0i32;
        if is_expired {
            mask |= 1;
        }
        if next_is_active {
            mask |= 2;
        }
        if should_disable_on_saving {
            mask |= 4;
        }
        if should_persist_quietly_on_retrieved {
            mask |= 8;
        }

        unsafe {
            *out_flags = mask;
        }
        1
    }

    #[inline]
    pub unsafe fn evaluate_user_service_registration(
        has_mail_from: u8,
        mail_from_ptr: *const u8,
        mail_from_len: usize,
        has_install_lock: u8,
        base_path_ptr: *const u8,
        base_path_len: usize,
        out_should_dispatch: *mut i32,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if has_mail_from > 1
            || has_install_lock > 1
            || out_should_dispatch.is_null()
            || !out_should_dispatch.is_aligned()
        {
            return -1;
        }
        let (Some(mail_from), Some(base_path)) = (
            unsafe { add23_valid_slice(mail_from_ptr, mail_from_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(base_path_ptr, base_path_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };
        if base_path.contains(&0) {
            return -2;
        }

        let mail_from_non_empty =
            has_mail_from == 1 && !mail_from.is_empty() && mail_from != b"0";
        let should_dispatch = i32::from(mail_from_non_empty && has_install_lock == 1);

        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    base_path,
                    b"/_install/install.lock\0newreg\0welcomeemails",
                ],
                3,
            )
        };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_should_dispatch = should_dispatch;
        }
        written
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_gdpr_retention_policy_strings_nul(
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        gdpr_retention_prompt_observer::policy_strings_nul(out_buf, out_cap)
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_gdpr_retention_resolve_purge_target(
    has_disk: u8,
    disk_ptr: *const u8,
    disk_len: usize,
    has_path: u8,
    path_ptr: *const u8,
    path_len: usize,
    has_expires_at: u8,
    expires_at_epoch: i64,
    now_epoch: i64,
    out_should_purge: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        gdpr_retention_prompt_observer::resolve_purge_target(
            has_disk,
            disk_ptr,
            disk_len,
            has_path,
            path_ptr,
            path_len,
            has_expires_at,
            expires_at_epoch,
            now_epoch,
            out_should_purge,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_gdpr_audit_normalize_payload_nul(
    event_ptr: *const u8,
    event_len: usize,
    desc_ptr: *const u8,
    desc_len: usize,
    has_subject_id: u8,
    subject_id: i64,
    has_actor_id: u8,
    actor_id: i64,
    has_request_id: u8,
    request_id: i64,
    metadata_count: usize,
    out_scalars: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        gdpr_retention_prompt_observer::normalize_audit_payload_nul(
            event_ptr,
            event_len,
            desc_ptr,
            desc_len,
            has_subject_id,
            subject_id,
            has_actor_id,
            actor_id,
            has_request_id,
            request_id,
            metadata_count,
            out_scalars,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_mcp_review_change_prompt_messages_nul(
    goal_ptr: *const u8,
    goal_len: usize,
    has_paths: u8,
    paths_ptr: *const u8,
    paths_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        gdpr_retention_prompt_observer::format_review_prompt_messages_nul(
            goal_ptr,
            goal_len,
            has_paths,
            paths_ptr,
            paths_len,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_role_promotion_observer_evaluate(
    is_active: u8,
    has_end_date: u8,
    end_timestamp_us: i64,
    now_timestamp_us: i64,
    out_flags: *mut i32,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        gdpr_retention_prompt_observer::evaluate_role_promotion_expiry(
            is_active,
            has_end_date,
            end_timestamp_us,
            now_timestamp_us,
            out_flags,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_user_service_observer_evaluate_registration(
    has_mail_from: u8,
    mail_from_ptr: *const u8,
    mail_from_len: usize,
    has_install_lock: u8,
    base_path_ptr: *const u8,
    base_path_len: usize,
    out_should_dispatch: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        gdpr_retention_prompt_observer::evaluate_user_service_registration(
            has_mail_from,
            mail_from_ptr,
            mail_from_len,
            has_install_lock,
            base_path_ptr,
            base_path_len,
            out_should_dispatch,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave25_gdpr_retention_prompt_observer_tests {
    use super::*;

    #[test]
    fn gdpr_retention_and_audit_helpers_match_php_contracts() {
        let mut buf = [0u8; 512];
        let written =
            unsafe { nntmux_gdpr_retention_policy_strings_nul(buf.as_mut_ptr(), buf.len()) };
        assert!(written > 0);
        let parts: Vec<&[u8]> = buf[..written as usize].split(|&b| b == 0).collect();
        assert_eq!(parts.len(), 5);
        assert_eq!(parts[0], b"7 days after generation, then safe to purge.");

        let mut should_purge = 0i32;
        let t_written = unsafe {
            nntmux_gdpr_retention_resolve_purge_target(
                0,
                b"".as_ptr(),
                0,
                1,
                b"gdpr/export-1.zip".as_ptr(),
                17,
                1,
                1000,
                2000,
                &mut should_purge,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(should_purge, 1);
        assert_eq!(&buf[..t_written as usize], b"local\0gdpr/export-1.zip");

        let mut scalars = [0i64; 4];
        let a_written = unsafe {
            nntmux_gdpr_audit_normalize_payload_nul(
                b"erasure.completed".as_ptr(),
                17,
                b"Completed erasure".as_ptr(),
                17,
                1,
                10,
                0,
                0,
                1,
                55,
                2,
                scalars.as_mut_ptr(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(a_written, 35);
        assert_eq!(&buf[..35], b"erasure.completed\0Completed erasure");
        assert_eq!(scalars, [1 | 2 | 8, 55, 10, 0]);
    }

    #[test]
    fn review_prompt_and_observers_match_php_contracts() {
        let mut buf = [0u8; 1024];
        let p_written = unsafe {
            nntmux_mcp_review_change_prompt_messages_nul(
                b"Fix retention".as_ptr(),
                13,
                0,
                b"".as_ptr(),
                0,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(p_written > 0);
        let prompt_parts: Vec<&[u8]> = buf[..p_written as usize].split(|&b| b == 0).collect();
        assert_eq!(prompt_parts.len(), 2);
        assert_eq!(
            prompt_parts[1],
            b"Change goal:\nFix retention\n\nChanged paths:\nDerive them from the current scoped diff."
        );

        let mut flags = 0i32;
        assert_eq!(
            unsafe { nntmux_role_promotion_observer_evaluate(1, 1, 1000, 2000, &mut flags) },
            1
        );
        assert_eq!(flags, 1 | 4 | 8);

        assert_eq!(
            unsafe { nntmux_role_promotion_observer_evaluate(1, 1, 3000, 2000, &mut flags) },
            1
        );
        assert_eq!(flags, 2);

        let mut dispatch = 0i32;
        let u_written = unsafe {
            nntmux_user_service_observer_evaluate_registration(
                1,
                b"noreply@example.com".as_ptr(),
                19,
                1,
                b"/var/www/nntmux".as_ptr(),
                15,
                &mut dispatch,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(dispatch, 1);
        assert_eq!(
            &buf[..u_written as usize],
            b"/var/www/nntmux/_install/install.lock\0newreg\0welcomeemails"
        );
    }

    #[test]
    fn wave25_slice6_entrypoints_reject_null_unaligned_and_undersized_buffers() {
        let mut raw = [0xA5u8; 64];
        let unaligned_i32 = unsafe { raw.as_mut_ptr().add(1).cast::<i32>() };
        let unaligned_i64 = unsafe { raw.as_mut_ptr().add(1).cast::<i64>() };

        let mut small = [0x5Au8; 8];
        assert!(
            unsafe { nntmux_gdpr_retention_policy_strings_nul(small.as_mut_ptr(), small.len()) }
                < -8
        );
        assert_eq!(small, [0x5Au8; 8]);

        assert_eq!(
            unsafe {
                nntmux_gdpr_retention_resolve_purge_target(
                    0,
                    b"".as_ptr(),
                    0,
                    1,
                    b"a".as_ptr(),
                    1,
                    1,
                    10,
                    20,
                    unaligned_i32,
                    raw.as_mut_ptr(),
                    32,
                )
            },
            -1
        );

        assert_eq!(
            unsafe {
                nntmux_gdpr_audit_normalize_payload_nul(
                    b"ev".as_ptr(),
                    2,
                    b"desc".as_ptr(),
                    4,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    unaligned_i64,
                    raw.as_mut_ptr(),
                    32,
                )
            },
            -1
        );

        assert_eq!(
            unsafe {
                nntmux_mcp_review_change_prompt_messages_nul(
                    b"bad\0goal".as_ptr(),
                    8,
                    0,
                    b"".as_ptr(),
                    0,
                    raw.as_mut_ptr(),
                    64,
                )
            },
            -2
        );

        assert_eq!(
            unsafe { nntmux_role_promotion_observer_evaluate(1, 1, 10, 20, unaligned_i32) },
            -1
        );

        assert_eq!(
            unsafe {
                nntmux_user_service_observer_evaluate_registration(
                    1,
                    b"a@b.c".as_ptr(),
                    5,
                    1,
                    b"/app".as_ptr(),
                    4,
                    unaligned_i32,
                    raw.as_mut_ptr(),
                    64,
                )
            },
            -1
        );
    }
}

// ---------------------------------------------------------------------------
// Wave 26 Slice 26.6: AuthenticatesUsers, RegistersUsers,
// HasCompositePrimaryKey, ReleaseUnique, and LogIndexFile pure-CPU helpers
// ---------------------------------------------------------------------------

pub mod auth_traits_composite_key_models {
    use super::{ADD23_MAX_SEG_LEN, add23_valid_out_buf, add23_valid_slice, add23_write_slices};

    pub const LOGIN_RULE_REQUIRED_STRING: &[u8] = b"required|string";
    pub const LOGIN_PASSWORD_KEY: &[u8] = b"password";
    pub const REGISTER_VIEW_NAME: &[u8] = b"auth.register";
    pub const RELEASE_UNIQUE_COMPOSITE_KEYS_NUL: &[u8] = b"releases_id\0uniqueid";
    pub const LOG_INDEX_RESET_KEYS_NUL: &[u8] =
        b"indexed_offset\0indexed_line\0indexed_size\0indexed_at";

    #[inline]
    pub unsafe fn build_login_spec_nul(
        username_ptr: *const u8,
        username_len: usize,
        has_custom_response: u8,
        wants_json: u8,
        redirect_ptr: *const u8,
        redirect_len: usize,
        out_status: *mut i32,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if has_custom_response > 1
            || wants_json > 1
            || out_status.is_null()
            || !out_status.is_aligned()
        {
            return -1;
        }
        let (Some(username), Some(redirect_path)) = (
            unsafe { add23_valid_slice(username_ptr, username_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(redirect_ptr, redirect_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };
        if username.is_empty() || username.contains(&0) || redirect_path.contains(&0) {
            return -2;
        }

        let (action, status): (&[u8], i32) = if has_custom_response == 1 {
            (b"custom", 200)
        } else if wants_json == 1 {
            (b"json", 204)
        } else {
            (b"redirect_intended", 302)
        };

        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    username,
                    b"\0",
                    LOGIN_RULE_REQUIRED_STRING,
                    b"\0",
                    LOGIN_PASSWORD_KEY,
                    b"\0",
                    LOGIN_RULE_REQUIRED_STRING,
                    b"\0",
                    action,
                    b"\0",
                    redirect_path,
                ],
                3,
            )
        };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_status = status;
        }
        written
    }

    #[inline]
    pub unsafe fn resolve_register_outcome_nul(
        has_custom_response: u8,
        wants_json: u8,
        redirect_ptr: *const u8,
        redirect_len: usize,
        out_flags_and_status: *mut i32,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if has_custom_response > 1
            || wants_json > 1
            || out_flags_and_status.is_null()
            || !out_flags_and_status.is_aligned()
            || (out_flags_and_status as usize)
                .checked_add(2 * std::mem::size_of::<i32>())
                .is_none()
        {
            return -1;
        }
        let Some(redirect_path) =
            (unsafe { add23_valid_slice(redirect_ptr, redirect_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        if redirect_path.contains(&0) {
            return -2;
        }

        let (action, is_json, status): (&[u8], i32, i32) = if has_custom_response == 1 {
            (b"custom", 0, 200)
        } else if wants_json == 1 {
            (b"json", 1, 201)
        } else {
            (b"redirect", 0, 302)
        };

        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[action, b"\0", redirect_path, b"\0", REGISTER_VIEW_NAME],
                3,
            )
        };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_flags_and_status.add(0) = is_json;
            *out_flags_and_status.add(1) = status;
        }
        written
    }

    #[inline]
    pub unsafe fn format_missing_composite_key_message(
        col_ptr: *const u8,
        col_len: usize,
        class_ptr: *const u8,
        class_len: usize,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        let (Some(col), Some(class_name)) = (
            unsafe { add23_valid_slice(col_ptr, col_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(class_ptr, class_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };
        if col.is_empty() || class_name.is_empty() || col.contains(&0) || class_name.contains(&0) {
            return -2;
        }

        unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    b"Composite key column [",
                    col,
                    b"] is missing on [",
                    class_name,
                    b"].",
                ],
                3,
            )
        }
    }

    #[inline]
    pub unsafe fn select_composite_key_sources(
        count: usize,
        in_original_mask: *const u8,
        in_attr_mask: *const u8,
        out_source_codes: *mut u8,
        out_missing_index: *mut isize,
    ) -> i32 {
        if count == 0
            || count > ADD23_MAX_SEG_LEN
            || out_missing_index.is_null()
            || !out_missing_index.is_aligned()
            || !add23_valid_out_buf(out_source_codes, count)
        {
            return -1;
        }
        let (Some(orig_mask), Some(attr_mask)) = (
            unsafe { add23_valid_slice(in_original_mask, count, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(in_attr_mask, count, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };

        for i in 0..count {
            if orig_mask[i] > 1 || attr_mask[i] > 1 {
                return -1;
            }
        }

        for i in 0..count {
            if orig_mask[i] == 1 {
                unsafe {
                    *out_source_codes.add(i) = 1;
                }
            } else if attr_mask[i] == 1 {
                unsafe {
                    *out_source_codes.add(i) = 2;
                }
            } else {
                unsafe {
                    *out_missing_index = i as isize;
                }
                return 0;
            }
        }

        unsafe {
            *out_missing_index = -1;
        }
        1
    }

    #[inline]
    pub unsafe fn release_unique_composite_key_columns_nul(
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        unsafe { add23_write_slices(out_buf, out_cap, &[RELEASE_UNIQUE_COMPOSITE_KEYS_NUL], 2) }
    }

    #[inline]
    pub unsafe fn log_index_file_reset_progress_spec(
        out_scalars: *mut i64,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if out_scalars.is_null()
            || !out_scalars.is_aligned()
            || (out_scalars as usize)
                .checked_add(4 * std::mem::size_of::<i64>())
                .is_none()
        {
            return -1;
        }
        let written = unsafe { add23_write_slices(out_buf, out_cap, &[LOG_INDEX_RESET_KEYS_NUL], 2) };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_scalars.add(0) = 0;
            *out_scalars.add(1) = 0;
            *out_scalars.add(2) = 0;
            *out_scalars.add(3) = 0;
        }
        written
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_auth_login_spec_nul(
    username_ptr: *const u8,
    username_len: usize,
    has_custom_response: u8,
    wants_json: u8,
    redirect_ptr: *const u8,
    redirect_len: usize,
    out_status: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_traits_composite_key_models::build_login_spec_nul(
            username_ptr,
            username_len,
            has_custom_response,
            wants_json,
            redirect_ptr,
            redirect_len,
            out_status,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_auth_register_outcome_nul(
    has_custom_response: u8,
    wants_json: u8,
    redirect_ptr: *const u8,
    redirect_len: usize,
    out_flags_and_status: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_traits_composite_key_models::resolve_register_outcome_nul(
            has_custom_response,
            wants_json,
            redirect_ptr,
            redirect_len,
            out_flags_and_status,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_composite_key_format_missing_message(
    col_ptr: *const u8,
    col_len: usize,
    class_ptr: *const u8,
    class_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_traits_composite_key_models::format_missing_composite_key_message(
            col_ptr, col_len, class_ptr, class_len, out_buf, out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_composite_key_select_sources(
    count: usize,
    in_original_mask: *const u8,
    in_attr_mask: *const u8,
    out_source_codes: *mut u8,
    out_missing_index: *mut isize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_traits_composite_key_models::select_composite_key_sources(
            count,
            in_original_mask,
            in_attr_mask,
            out_source_codes,
            out_missing_index,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_unique_composite_key_columns_nul(
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_traits_composite_key_models::release_unique_composite_key_columns_nul(out_buf, out_cap)
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_index_file_reset_progress_spec(
    out_scalars: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_traits_composite_key_models::log_index_file_reset_progress_spec(
            out_scalars,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave26_auth_traits_composite_key_models_tests {
    use super::*;

    #[test]
    fn auth_login_and_register_helpers_match_php_contracts() {
        let mut buf = [0u8; 256];
        let mut status = 0i32;
        let written = unsafe {
            nntmux_auth_login_spec_nul(
                b"email".as_ptr(),
                5,
                0,
                1,
                b"/dashboard".as_ptr(),
                10,
                &mut status,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(written > 0);
        assert_eq!(status, 204);
        let parts: Vec<&[u8]> = buf[..written as usize].split(|&b| b == 0).collect();
        assert_eq!(
            parts,
            vec![
                &b"email"[..],
                &b"required|string"[..],
                &b"password"[..],
                &b"required|string"[..],
                &b"json"[..],
                &b"/dashboard"[..],
            ]
        );

        let mut flags_status = [0i32; 2];
        let r_written = unsafe {
            nntmux_auth_register_outcome_nul(
                0,
                0,
                b"/home".as_ptr(),
                5,
                flags_status.as_mut_ptr(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(r_written > 0);
        assert_eq!(flags_status, [0, 302]);
        assert_eq!(&buf[..r_written as usize], b"redirect\0/home\0auth.register");
    }

    #[test]
    fn composite_key_release_unique_and_log_index_file_match_php_contracts() {
        let mut buf = [0u8; 256];
        let msg_len = unsafe {
            nntmux_composite_key_format_missing_message(
                b"uniqueid".as_ptr(),
                8,
                b"App\\Models\\ReleaseUnique".as_ptr(),
                24,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(msg_len > 0);
        assert_eq!(
            &buf[..msg_len as usize],
            b"Composite key column [uniqueid] is missing on [App\\Models\\ReleaseUnique]."
        );

        let orig = [1u8, 0u8];
        let attr = [1u8, 1u8];
        let mut codes = [0u8; 2];
        let mut missing_idx = -99isize;
        let rc = unsafe {
            nntmux_composite_key_select_sources(
                2,
                orig.as_ptr(),
                attr.as_ptr(),
                codes.as_mut_ptr(),
                &mut missing_idx,
            )
        };
        assert_eq!(rc, 1);
        assert_eq!(missing_idx, -1);
        assert_eq!(codes, [1, 2]);

        let attr_missing = [1u8, 0u8];
        let rc2 = unsafe {
            nntmux_composite_key_select_sources(
                2,
                orig.as_ptr(),
                attr_missing.as_ptr(),
                codes.as_mut_ptr(),
                &mut missing_idx,
            )
        };
        assert_eq!(rc2, 0);
        assert_eq!(missing_idx, 1);

        let ru_len =
            unsafe { nntmux_release_unique_composite_key_columns_nul(buf.as_mut_ptr(), buf.len()) };
        assert_eq!(ru_len, 20);
        assert_eq!(&buf[..20], b"releases_id\0uniqueid");

        let mut scalars = [99i64; 4];
        let li_len = unsafe {
            nntmux_log_index_file_reset_progress_spec(
                scalars.as_mut_ptr(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(li_len, 51);
        assert_eq!(scalars, [0, 0, 0, 0]);
    }

    #[test]
    fn wave26_slice6_entrypoints_reject_null_unaligned_and_undersized_buffers() {
        let mut raw = [0xA5u8; 64];
        let unaligned_i32 = unsafe { raw.as_mut_ptr().add(1).cast::<i32>() };
        let unaligned_i64 = unsafe { raw.as_mut_ptr().add(1).cast::<i64>() };
        let unaligned_isize = unsafe { raw.as_mut_ptr().add(1).cast::<isize>() };

        assert_eq!(
            unsafe {
                nntmux_auth_login_spec_nul(
                    b"email".as_ptr(),
                    5,
                    0,
                    0,
                    b"/".as_ptr(),
                    1,
                    unaligned_i32,
                    raw.as_mut_ptr(),
                    32,
                )
            },
            -1
        );

        assert_eq!(
            unsafe {
                nntmux_auth_register_outcome_nul(
                    0,
                    0,
                    b"/".as_ptr(),
                    1,
                    unaligned_i32,
                    raw.as_mut_ptr(),
                    32,
                )
            },
            -1
        );

        let mut small = [0x5Au8; 8];
        assert!(
            unsafe {
                nntmux_composite_key_format_missing_message(
                    b"id".as_ptr(),
                    2,
                    b"Model".as_ptr(),
                    5,
                    small.as_mut_ptr(),
                    small.len(),
                )
            } < -8
        );
        assert_eq!(small, [0x5Au8; 8]);

        let mask = [1u8, 1u8];
        let mut codes = [0u8; 2];
        assert_eq!(
            unsafe {
                nntmux_composite_key_select_sources(
                    2,
                    mask.as_ptr(),
                    mask.as_ptr(),
                    codes.as_mut_ptr(),
                    unaligned_isize,
                )
            },
            -1
        );

        assert!(
            unsafe {
                nntmux_release_unique_composite_key_columns_nul(small.as_mut_ptr(), small.len())
            } < -8
        );
        assert_eq!(small, [0x5Au8; 8]);

        assert_eq!(
            unsafe {
                nntmux_log_index_file_reset_progress_spec(unaligned_i64, raw.as_mut_ptr(), 64)
            },
            -1
        );
    }
}

// ---------------------------------------------------------------------------
// Wave 27 Slice 27.6: PasskeyLoginController, PasskeyManagementController,
// EmailVerificationController, ForgotPasswordController, and
// ResetPasswordController pure-CPU helpers
// ---------------------------------------------------------------------------

pub mod auth_passkey_password_controllers {
    use super::{ADD23_MAX_SEG_LEN, add23_valid_out_buf, add23_valid_slice, add23_write_slices};

    pub const PASSKEY_MISSING_OPTIONS_MSG: &[u8] = b"Your passkey sign-in session expired or was interrupted. Please try signing in with a passkey again.";
    pub const PASSKEY_MISSING_OPTIONS_REASON: &[u8] = b"missing_auth_options";
    pub const PASSKEY_LOGIN_FLASH_KEY: &[u8] = b"info";
    pub const PASSKEY_LOGIN_FLASH_MSG: &[u8] = b"You have been logged in";

    #[inline]
    fn is_php_trim_ws(b: u8) -> bool {
        matches!(b, b' ' | b'\t' | b'\n' | b'\r' | b'\0' | 0x0B)
    }

    #[inline]
    fn trim_php_bytes(s: &[u8]) -> &[u8] {
        let mut start = 0usize;
        let mut end = s.len();
        while start < end && is_php_trim_ws(s[start]) {
            start += 1;
        }
        while end > start && is_php_trim_ws(s[end - 1]) {
            end -= 1;
        }
        &s[start..end]
    }

    #[inline]
    pub fn sha1_hex_lower(input: &[u8]) -> [u8; 40] {
        let mut h0: u32 = 0x6745_2301;
        let mut h1: u32 = 0xEFCD_AB89;
        let mut h2: u32 = 0x98BA_DCFE;
        let mut h3: u32 = 0x1032_5476;
        let mut h4: u32 = 0xC3D2_E1F0;

        let process_block = |block: &[u8; 64],
                             h0: &mut u32,
                             h1: &mut u32,
                             h2: &mut u32,
                             h3: &mut u32,
                             h4: &mut u32| {
            let mut w = [0u32; 80];
            for i in 0..16 {
                let off = i * 4;
                w[i] = u32::from_be_bytes([
                    block[off],
                    block[off + 1],
                    block[off + 2],
                    block[off + 3],
                ]);
            }
            for i in 16..80 {
                w[i] = (w[i - 3] ^ w[i - 8] ^ w[i - 14] ^ w[i - 16]).rotate_left(1);
            }

            let mut a = *h0;
            let mut b = *h1;
            let mut c = *h2;
            let mut d = *h3;
            let mut e = *h4;

            for (i, &wi) in w.iter().enumerate() {
                let (f, k) = match i {
                    0..=19 => ((b & c) | ((!b) & d), 0x5A82_7999u32),
                    20..=39 => (b ^ c ^ d, 0x6ED9_EBA1u32),
                    40..=59 => ((b & c) | (b & d) | (c & d), 0x8F1B_BCDCu32),
                    _ => (b ^ c ^ d, 0xCA62_C1D6u32),
                };
                let temp = a
                    .rotate_left(5)
                    .wrapping_add(f)
                    .wrapping_add(e)
                    .wrapping_add(k)
                    .wrapping_add(wi);
                e = d;
                d = c;
                c = b.rotate_left(30);
                b = a;
                a = temp;
            }

            *h0 = h0.wrapping_add(a);
            *h1 = h1.wrapping_add(b);
            *h2 = h2.wrapping_add(c);
            *h3 = h3.wrapping_add(d);
            *h4 = h4.wrapping_add(e);
        };

        let mut chunks = input.chunks_exact(64);
        for chunk in &mut chunks {
            let block: &[u8; 64] = chunk.try_into().unwrap();
            process_block(block, &mut h0, &mut h1, &mut h2, &mut h3, &mut h4);
        }

        let rem = chunks.remainder();
        let mut last = [0u8; 128];
        last[..rem.len()].copy_from_slice(rem);
        last[rem.len()] = 0x80;

        let bit_len = (input.len() as u64).wrapping_mul(8);
        let total_blocks = if rem.len() < 56 { 1usize } else { 2usize };
        let len_offset = total_blocks * 64 - 8;
        last[len_offset..len_offset + 8].copy_from_slice(&bit_len.to_be_bytes());

        for b_idx in 0..total_blocks {
            let off = b_idx * 64;
            let block: &[u8; 64] = last[off..off + 64].try_into().unwrap();
            process_block(block, &mut h0, &mut h1, &mut h2, &mut h3, &mut h4);
        }

        let mut digest = [0u8; 20];
        digest[0..4].copy_from_slice(&h0.to_be_bytes());
        digest[4..8].copy_from_slice(&h1.to_be_bytes());
        digest[8..12].copy_from_slice(&h2.to_be_bytes());
        digest[12..16].copy_from_slice(&h3.to_be_bytes());
        digest[16..20].copy_from_slice(&h4.to_be_bytes());

        const HEX: &[u8; 16] = b"0123456789abcdef";
        let mut out = [0u8; 40];
        for (i, &byte) in digest.iter().enumerate() {
            out[i * 2] = HEX[(byte >> 4) as usize];
            out[i * 2 + 1] = HEX[(byte & 0x0F) as usize];
        }
        out
    }

    #[inline]
    pub unsafe fn passkey_login_preflight_nul(
        has_captcha_rules: u8,
        has_captcha_val: u8,
        captcha_ptr: *const u8,
        captcha_len: usize,
        has_options: u8,
        options_len: usize,
        out_flags: *mut i32,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if has_captcha_rules > 1
            || has_captcha_val > 1
            || has_options > 1
            || options_len > ADD23_MAX_SEG_LEN
            || out_flags.is_null()
            || !out_flags.is_aligned()
        {
            return -1;
        }
        let Some(captcha_val) =
            (unsafe { add23_valid_slice(captcha_ptr, captcha_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };

        let should_validate_captcha = has_captcha_rules == 1
            && has_captcha_val == 1
            && !trim_php_bytes(captcha_val).is_empty();
        let has_valid_options = has_options == 1 && options_len > 0;

        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    PASSKEY_MISSING_OPTIONS_MSG,
                    b"\0",
                    PASSKEY_MISSING_OPTIONS_REASON,
                ],
                2,
            )
        };
        if written < 0 {
            return written;
        }

        let mut flags = 0i32;
        if should_validate_captcha {
            flags |= 1;
        }
        if has_valid_options {
            flags |= 2;
        }
        unsafe {
            *out_flags = flags;
        }
        written
    }

    #[inline]
    pub unsafe fn passkey_login_resolve_redirect_nul(
        store_user_ip: u8,
        has_req_ip: u8,
        req_ip_ptr: *const u8,
        req_ip_len: usize,
        has_client_ip: u8,
        client_ip_ptr: *const u8,
        client_ip_len: usize,
        has_sess_redirect: u8,
        sess_redirect_ptr: *const u8,
        sess_redirect_len: usize,
        has_cfg_redirect: u8,
        cfg_redirect_ptr: *const u8,
        cfg_redirect_len: usize,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if store_user_ip > 1 || has_req_ip > 1 || has_client_ip > 1 || has_sess_redirect > 1 || has_cfg_redirect > 1 {
            return -1;
        }
        let (Some(req_ip), Some(client_ip), Some(sess_redir), Some(cfg_redir)) = (
            unsafe { add23_valid_slice(req_ip_ptr, req_ip_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(client_ip_ptr, client_ip_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(sess_redirect_ptr, sess_redirect_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(cfg_redirect_ptr, cfg_redirect_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };

        let user_ip: &[u8] = if store_user_ip == 1 {
            if has_req_ip == 1 {
                req_ip
            } else if has_client_ip == 1 {
                client_ip
            } else {
                b""
            }
        } else {
            b""
        };

        let redirect_url: &[u8] = if has_sess_redirect == 1 {
            sess_redir
        } else if has_cfg_redirect == 1 {
            cfg_redir
        } else {
            b"/"
        };

        if user_ip.contains(&0) || redirect_url.contains(&0) {
            return -2;
        }

        unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    user_ip,
                    b"\0",
                    redirect_url,
                    b"\0",
                    PASSKEY_LOGIN_FLASH_KEY,
                    b"\0",
                    PASSKEY_LOGIN_FLASH_MSG,
                ],
                3,
            )
        }
    }

    #[inline]
    pub unsafe fn passkey_management_normalize_metadata_nul(
        has_id: u8,
        id: i64,
        has_name: u8,
        name_ptr: *const u8,
        name_len: usize,
        has_last_used: u8,
        last_used_ptr: *const u8,
        last_used_len: usize,
        has_created: u8,
        created_ptr: *const u8,
        created_len: usize,
        deleted_count: i64,
        out_scalars: *mut i64,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if has_id > 1
            || has_name > 1
            || has_last_used > 1
            || has_created > 1
            || out_scalars.is_null()
            || !out_scalars.is_aligned()
            || (out_scalars as usize)
                .checked_add(3 * std::mem::size_of::<i64>())
                .is_none()
        {
            return -1;
        }
        let (Some(name), Some(last_used), Some(created)) = (
            unsafe { add23_valid_slice(name_ptr, name_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(last_used_ptr, last_used_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(created_ptr, created_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };

        let eff_name: &[u8] = if has_name == 1 { name } else { b"" };
        let eff_last_used: &[u8] = if has_last_used == 1 { last_used } else { b"" };
        let eff_created: &[u8] = if has_created == 1 { created } else { b"" };

        if eff_name.contains(&0) || eff_last_used.contains(&0) || eff_created.contains(&0) {
            return -2;
        }

        let destroy_ok = deleted_count > 0;
        let (flash_key, flash_msg): (&[u8], &[u8]) = if destroy_ok {
            (b"success", b"Passkey deleted.")
        } else {
            (b"error", b"Passkey not found.")
        };

        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    eff_name,
                    b"\0",
                    eff_last_used,
                    b"\0",
                    eff_created,
                    b"\0",
                    flash_key,
                    b"\0",
                    flash_msg,
                ],
                3,
            )
        };
        if written < 0 {
            return written;
        }

        let mut mask = 0i64;
        if has_id == 1 {
            mask |= 1;
        }
        if has_name == 1 {
            mask |= 2;
        }
        if has_last_used == 1 {
            mask |= 4;
        }
        if has_created == 1 {
            mask |= 8;
        }

        unsafe {
            *out_scalars.add(0) = mask;
            *out_scalars.add(1) = if has_id == 1 { id } else { 0 };
            *out_scalars.add(2) = i64::from(destroy_ok);
        }
        written
    }

    #[inline]
    pub unsafe fn email_verification_verify_sha1(
        hash_ptr: *const u8,
        hash_len: usize,
        email_ptr: *const u8,
        email_len: usize,
        out_matches: *mut i32,
        out_hex40: *mut u8,
        out_cap: usize,
    ) -> isize {
        if out_matches.is_null()
            || !out_matches.is_aligned()
            || !add23_valid_out_buf(out_hex40, out_cap)
        {
            return -1;
        }
        let (Some(provided_hash), Some(email)) = (
            unsafe { add23_valid_slice(hash_ptr, hash_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(email_ptr, email_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };

        let digest_hex = sha1_hex_lower(email);
        if out_cap < 40 {
            return -40;
        }

        let matches = if provided_hash.len() == 40 {
            let mut diff = 0u8;
            for i in 0..40 {
                diff |= provided_hash[i] ^ digest_hex[i];
            }
            i32::from(diff == 0)
        } else {
            0
        };

        unsafe {
            std::ptr::copy_nonoverlapping(digest_hex.as_ptr(), out_hex40, 40);
            *out_matches = matches;
        }
        40
    }

    #[inline]
    pub unsafe fn forgot_password_evaluate_nul(
        email_ptr: *const u8,
        email_len: usize,
        captcha_enabled: u8,
        captcha_failed: u8,
        user_exists: u8,
        user_trashed: u8,
        out_flags: *mut i32,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if captcha_enabled > 1
            || captcha_failed > 1
            || user_exists > 1
            || user_trashed > 1
            || out_flags.is_null()
            || !out_flags.is_aligned()
        {
            return -1;
        }
        let Some(email) = (unsafe { add23_valid_slice(email_ptr, email_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };

        let (status, err_field, err_msg, success_msg, flags): (
            &[u8],
            &[u8],
            &[u8],
            &[u8],
            i32,
        ) = if email.is_empty() {
            (
                b"missing_email",
                b"email",
                b"Please enter your email address to send a password reset link.",
                b"",
                2,
            )
        } else if captcha_enabled == 1 && captcha_failed == 1 {
            (
                b"captcha_failed",
                b"error",
                b"Captcha validation failed.",
                b"",
                2,
            )
        } else if user_exists == 0 || user_trashed == 1 {
            (
                b"enumerated_noop",
                b"",
                b"",
                b"Password reset email has been sent!",
                4,
            )
        } else {
            (
                b"send_reset",
                b"",
                b"",
                b"Password reset email has been sent!",
                1 | 4,
            )
        };

        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    status,
                    b"\0forgottenpassword\0",
                    err_field,
                    b"\0",
                    err_msg,
                    b"\0",
                    success_msg,
                ],
                2,
            )
        };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_flags = flags;
        }
        written
    }

    #[inline]
    pub unsafe fn reset_password_evaluate_legacy_guid_nul(
        has_guid_param: u8,
        user_found: u8,
        user_trashed: u8,
        out_is_valid: *mut i32,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if has_guid_param > 1
            || user_found > 1
            || user_trashed > 1
            || out_is_valid.is_null()
            || !out_is_valid.is_aligned()
        {
            return -1;
        }

        let (is_valid, status, route, err_msg): (i32, &[u8], &[u8], &[u8]) =
            if has_guid_param == 0 {
                (
                    0,
                    b"missing_guid",
                    b"password.request",
                    b"No reset code provided.",
                )
            } else if user_found == 0 {
                (
                    0,
                    b"bad_guid",
                    b"password.request",
                    b"Bad reset code provided.",
                )
            } else if user_trashed == 1 {
                (
                    0,
                    b"deactivated",
                    b"password.request",
                    b"This account has been deactivated.",
                )
            } else {
                (1, b"issue_token", b"password.reset", b"")
            };

        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[status, b"\0", route, b"\0", err_msg],
                2,
            )
        };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_is_valid = is_valid;
        }
        written
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_passkey_login_preflight_nul(
    has_captcha_rules: u8,
    has_captcha_val: u8,
    captcha_ptr: *const u8,
    captcha_len: usize,
    has_options: u8,
    options_len: usize,
    out_flags: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_passkey_password_controllers::passkey_login_preflight_nul(
            has_captcha_rules,
            has_captcha_val,
            captcha_ptr,
            captcha_len,
            has_options,
            options_len,
            out_flags,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_passkey_login_resolve_redirect_nul(
    store_user_ip: u8,
    has_req_ip: u8,
    req_ip_ptr: *const u8,
    req_ip_len: usize,
    has_client_ip: u8,
    client_ip_ptr: *const u8,
    client_ip_len: usize,
    has_sess_redirect: u8,
    sess_redirect_ptr: *const u8,
    sess_redirect_len: usize,
    has_cfg_redirect: u8,
    cfg_redirect_ptr: *const u8,
    cfg_redirect_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_passkey_password_controllers::passkey_login_resolve_redirect_nul(
            store_user_ip,
            has_req_ip,
            req_ip_ptr,
            req_ip_len,
            has_client_ip,
            client_ip_ptr,
            client_ip_len,
            has_sess_redirect,
            sess_redirect_ptr,
            sess_redirect_len,
            has_cfg_redirect,
            cfg_redirect_ptr,
            cfg_redirect_len,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_passkey_management_normalize_metadata_nul(
    has_id: u8,
    id: i64,
    has_name: u8,
    name_ptr: *const u8,
    name_len: usize,
    has_last_used: u8,
    last_used_ptr: *const u8,
    last_used_len: usize,
    has_created: u8,
    created_ptr: *const u8,
    created_len: usize,
    deleted_count: i64,
    out_scalars: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_passkey_password_controllers::passkey_management_normalize_metadata_nul(
            has_id,
            id,
            has_name,
            name_ptr,
            name_len,
            has_last_used,
            last_used_ptr,
            last_used_len,
            has_created,
            created_ptr,
            created_len,
            deleted_count,
            out_scalars,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_email_verification_verify_sha1(
    hash_ptr: *const u8,
    hash_len: usize,
    email_ptr: *const u8,
    email_len: usize,
    out_matches: *mut i32,
    out_hex40: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_passkey_password_controllers::email_verification_verify_sha1(
            hash_ptr,
            hash_len,
            email_ptr,
            email_len,
            out_matches,
            out_hex40,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_forgot_password_evaluate_nul(
    email_ptr: *const u8,
    email_len: usize,
    captcha_enabled: u8,
    captcha_failed: u8,
    user_exists: u8,
    user_trashed: u8,
    out_flags: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_passkey_password_controllers::forgot_password_evaluate_nul(
            email_ptr,
            email_len,
            captcha_enabled,
            captcha_failed,
            user_exists,
            user_trashed,
            out_flags,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_reset_password_evaluate_legacy_guid_nul(
    has_guid_param: u8,
    user_found: u8,
    user_trashed: u8,
    out_is_valid: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_passkey_password_controllers::reset_password_evaluate_legacy_guid_nul(
            has_guid_param,
            user_found,
            user_trashed,
            out_is_valid,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave27_auth_passkey_password_controllers_tests {
    use super::*;

    #[test]
    fn passkey_login_and_management_helpers_match_php_contracts() {
        let mut buf = [0u8; 256];
        let mut flags = 0i32;
        let written = unsafe {
            nntmux_passkey_login_preflight_nul(
                1,
                1,
                b"  tok-123  ".as_ptr(),
                11,
                1,
                64,
                &mut flags,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(written > 0);
        assert_eq!(flags, 3);

        let r_written = unsafe {
            nntmux_passkey_login_resolve_redirect_nul(
                1,
                0,
                b"".as_ptr(),
                0,
                1,
                b"10.0.0.5".as_ptr(),
                8,
                1,
                b"/profile".as_ptr(),
                8,
                1,
                b"/".as_ptr(),
                1,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(r_written > 0);
        assert_eq!(
            &buf[..r_written as usize],
            b"10.0.0.5\0/profile\0info\0You have been logged in"
        );

        let mut scalars = [0i64; 3];
        let m_written = unsafe {
            nntmux_passkey_management_normalize_metadata_nul(
                1,
                77,
                1,
                b"MacBook TouchID".as_ptr(),
                15,
                0,
                b"".as_ptr(),
                0,
                1,
                b"2026-10-24T12:00:00+00:00".as_ptr(),
                25,
                1,
                scalars.as_mut_ptr(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(m_written > 0);
        assert_eq!(scalars, [1 | 2 | 8, 77, 1]);
        assert_eq!(
            &buf[..m_written as usize],
            b"MacBook TouchID\0\02026-10-24T12:00:00+00:00\0success\0Passkey deleted."
        );
    }

    #[test]
    fn email_verification_sha1_and_password_controllers_match_php_contracts() {
        assert_eq!(
            &auth_passkey_password_controllers::sha1_hex_lower(b""),
            b"da39a3ee5e6b4b0d3255bfef95601890afd80709"
        );
        assert_eq!(
            &auth_passkey_password_controllers::sha1_hex_lower(b"abc"),
            b"a9993e364706816aba3e25717850c26c9cd0d89d"
        );
        assert_eq!(
            &auth_passkey_password_controllers::sha1_hex_lower(
                b"abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq"
            ),
            b"84983e441c3bd26ebaae4aa1f95129e5e54670f1"
        );

        let mut hex_out = [0u8; 40];
        let mut matches = 0i32;
        let s_len = unsafe {
            nntmux_email_verification_verify_sha1(
                b"a9993e364706816aba3e25717850c26c9cd0d89d".as_ptr(),
                40,
                b"abc".as_ptr(),
                3,
                &mut matches,
                hex_out.as_mut_ptr(),
                40,
            )
        };
        assert_eq!(s_len, 40);
        assert_eq!(matches, 1);

        let mut buf = [0u8; 256];
        let mut fp_flags = 0i32;
        let fp_len = unsafe {
            nntmux_forgot_password_evaluate_nul(
                b"user@example.com".as_ptr(),
                16,
                1,
                0,
                1,
                0,
                &mut fp_flags,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(fp_len > 0);
        assert_eq!(fp_flags, 5);
        assert_eq!(
            &buf[..fp_len as usize],
            b"send_reset\0forgottenpassword\0\0\0Password reset email has been sent!"
        );

        let mut is_valid = 0i32;
        let rp_len = unsafe {
            nntmux_reset_password_evaluate_legacy_guid_nul(
                1,
                1,
                1,
                &mut is_valid,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(rp_len > 0);
        assert_eq!(is_valid, 0);
        assert_eq!(
            &buf[..rp_len as usize],
            b"deactivated\0password.request\0This account has been deactivated."
        );
    }

    #[test]
    fn wave27_slice6_entrypoints_reject_null_unaligned_and_undersized_buffers() {
        let mut raw = [0xA5u8; 64];
        let unaligned_i32 = unsafe { raw.as_mut_ptr().add(1).cast::<i32>() };
        let unaligned_i64 = unsafe { raw.as_mut_ptr().add(1).cast::<i64>() };

        assert_eq!(
            unsafe {
                nntmux_passkey_login_preflight_nul(
                    1,
                    1,
                    b"tok".as_ptr(),
                    3,
                    1,
                    10,
                    unaligned_i32,
                    raw.as_mut_ptr(),
                    64,
                )
            },
            -1
        );

        assert_eq!(
            unsafe {
                nntmux_passkey_login_resolve_redirect_nul(
                    1,
                    1,
                    b"1.2\0.3.4".as_ptr(),
                    8,
                    0,
                    b"".as_ptr(),
                    0,
                    0,
                    b"".as_ptr(),
                    0,
                    1,
                    b"/".as_ptr(),
                    1,
                    raw.as_mut_ptr(),
                    64,
                )
            },
            -2
        );

        assert_eq!(
            unsafe {
                nntmux_passkey_management_normalize_metadata_nul(
                    1,
                    1,
                    1,
                    b"Key".as_ptr(),
                    3,
                    0,
                    b"".as_ptr(),
                    0,
                    0,
                    b"".as_ptr(),
                    0,
                    1,
                    unaligned_i64,
                    raw.as_mut_ptr(),
                    64,
                )
            },
            -1
        );

        let mut small = [0x5Au8; 8];
        let mut matches = 99i32;
        assert_eq!(
            unsafe {
                nntmux_email_verification_verify_sha1(
                    b"a9993e364706816aba3e25717850c26c9cd0d89d".as_ptr(),
                    40,
                    b"abc".as_ptr(),
                    3,
                    &mut matches,
                    small.as_mut_ptr(),
                    small.len(),
                )
            },
            -40
        );
        assert_eq!(small, [0x5Au8; 8]);
        assert_eq!(matches, 99);

        assert_eq!(
            unsafe {
                nntmux_forgot_password_evaluate_nul(
                    b"a@b.c".as_ptr(),
                    5,
                    0,
                    0,
                    1,
                    0,
                    unaligned_i32,
                    raw.as_mut_ptr(),
                    64,
                )
            },
            -1
        );

        assert_eq!(
            unsafe {
                nntmux_reset_password_evaluate_legacy_guid_nul(
                    1,
                    1,
                    0,
                    unaligned_i32,
                    raw.as_mut_ptr(),
                    64,
                )
            },
            -1
        );
    }
}

// ---------------------------------------------------------------------------
// Wave 28 Slice 28.6: LoginController, RegisterController, AdminUserController,
// AdminRoleController, and DeletedUsersController pure-CPU helpers
// ---------------------------------------------------------------------------

pub mod auth_admin_user_role_controllers {
    use super::{ADD23_MAX_SEG_LEN, add23_valid_out_buf, add23_valid_slice, add23_write_slices};

    #[inline]
    fn is_php_trim_ws(b: u8) -> bool {
        matches!(b, b' ' | b'\t' | b'\n' | b'\r' | b'\0' | 0x0B)
    }

    #[inline]
    fn trim_php_bytes(s: &[u8]) -> &[u8] {
        let mut start = 0usize;
        let mut end = s.len();
        while start < end && is_php_trim_ws(s[start]) {
            start += 1;
        }
        while end > start && is_php_trim_ws(s[end - 1]) {
            end -= 1;
        }
        &s[start..end]
    }

    #[inline]
    fn is_rfc5322_atext(b: u8) -> bool {
        b.is_ascii_alphanumeric()
            || matches!(
                b,
                b'!' | b'#'
                    | b'$'
                    | b'%'
                    | b'&'
                    | b'\''
                    | b'*'
                    | b'+'
                    | b'-'
                    | b'/'
                    | b'='
                    | b'?'
                    | b'^'
                    | b'_'
                    | b'`'
                    | b'{'
                    | b'|'
                    | b'}'
                    | b'~'
            )
    }

    #[inline]
    fn validate_ascii_email(input: &[u8]) -> Option<bool> {
        if input.is_empty() || input.len() > 320 {
            return Some(false);
        }
        for &b in input {
            if b == b'"' || b == b'[' || b == b']' || b == b'\\' || b >= 0x80 {
                return None;
            }
        }
        let mut at_pos = None;
        for (idx, &b) in input.iter().enumerate() {
            if b == b'@' {
                if at_pos.is_some() {
                    return Some(false);
                }
                at_pos = Some(idx);
            }
        }
        let Some(at_idx) = at_pos else {
            return Some(false);
        };
        let local = &input[..at_idx];
        let domain = &input[at_idx + 1..];
        if local.is_empty() || local.len() > 64 || domain.is_empty() || domain.len() > 255 {
            return Some(false);
        }
        if local[0] == b'.' || local[local.len() - 1] == b'.' {
            return Some(false);
        }
        let mut prev_dot = false;
        for &b in local {
            if b == b'.' {
                if prev_dot {
                    return Some(false);
                }
                prev_dot = true;
            } else {
                if !is_rfc5322_atext(b) {
                    return Some(false);
                }
                prev_dot = false;
            }
        }

        if domain[0] == b'.' || domain[domain.len() - 1] == b'.' {
            return Some(false);
        }
        let mut label_count = 0usize;
        let mut last_label: &[u8] = b"";
        for label in domain.split(|&b| b == b'.') {
            if label.is_empty() || label.len() > 63 {
                return Some(false);
            }
            if !label[0].is_ascii_alphanumeric() || !label[label.len() - 1].is_ascii_alphanumeric()
            {
                return Some(false);
            }
            for &b in label {
                if !b.is_ascii_alphanumeric() && b != b'-' {
                    return Some(false);
                }
            }
            label_count += 1;
            last_label = label;
        }
        if label_count < 2 || !last_label[0].is_ascii_alphabetic() {
            return Some(false);
        }
        Some(true)
    }

    #[inline]
    pub unsafe fn login_detect_field_type_nul(
        is_string_input: u8,
        input_ptr: *const u8,
        input_len: usize,
        out_is_email: *mut i32,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if is_string_input > 1 || out_is_email.is_null() || !out_is_email.is_aligned() {
            return -1;
        }
        let Some(input) = (unsafe { add23_valid_slice(input_ptr, input_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };

        let is_email = if is_string_input == 1 {
            let Some(valid) = validate_ascii_email(input) else {
                return -2;
            };
            valid
        } else {
            false
        };

        let field: &[u8] = if is_email { b"email" } else { b"username" };
        let written = unsafe { add23_write_slices(out_buf, out_cap, &[field], 1) };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_is_email = i32::from(is_email);
        }
        written
    }

    #[inline]
    pub unsafe fn login_trusted_device_remaining(
        expires_at: i64,
        now_ts: i64,
        out_scalars: *mut i64,
    ) -> i32 {
        if out_scalars.is_null()
            || !out_scalars.is_aligned()
            || (out_scalars as usize)
                .checked_add(3 * std::mem::size_of::<i64>())
                .is_none()
        {
            return -1;
        }
        let remaining_seconds = if expires_at > now_ts {
            expires_at.saturating_sub(now_ts)
        } else {
            0
        };
        let remaining_minutes = if remaining_seconds == 0 {
            0
        } else {
            1 + (remaining_seconds - 1) / 60
        };
        let should_preserve = i64::from(remaining_minutes > 0);
        unsafe {
            *out_scalars.add(0) = remaining_seconds;
            *out_scalars.add(1) = remaining_minutes;
            *out_scalars.add(2) = should_preserve;
        }
        1
    }

    #[inline]
    pub unsafe fn login_requires_two_factor(
        has_password_security: u8,
        google2fa_enable_true: u8,
        secret_is_string: u8,
        secret_ptr: *const u8,
        secret_len: usize,
        out_requires: *mut i32,
    ) -> i32 {
        if has_password_security > 1
            || google2fa_enable_true > 1
            || secret_is_string > 1
            || out_requires.is_null()
            || !out_requires.is_aligned()
        {
            return -1;
        }
        let Some(secret) =
            (unsafe { add23_valid_slice(secret_ptr, secret_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        let requires = has_password_security == 1
            && google2fa_enable_true == 1
            && secret_is_string == 1
            && !secret.is_empty();
        unsafe {
            *out_requires = i32::from(requires);
        }
        1
    }

    #[inline]
    pub unsafe fn register_extract_invite_code_nul(
        has_invitecode: u8,
        invitecode_ptr: *const u8,
        invitecode_len: usize,
        has_token: u8,
        token_ptr: *const u8,
        token_len: usize,
        has_invitation: u8,
        invitation_ptr: *const u8,
        invitation_len: usize,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if has_invitecode > 1 || has_token > 1 || has_invitation > 1 {
            return -1;
        }
        let (Some(invitecode), Some(token), Some(invitation)) = (
            unsafe { add23_valid_slice(invitecode_ptr, invitecode_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(token_ptr, token_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(invitation_ptr, invitation_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };

        let (param, code): (&[u8], &[u8]) =
            if has_invitecode == 1 && !trim_php_bytes(invitecode).is_empty() {
                (b"invitecode", invitecode)
            } else if has_token == 1 && !trim_php_bytes(token).is_empty() {
                (b"token", token)
            } else if has_invitation == 1 && !trim_php_bytes(invitation).is_empty() {
                (b"invitation", invitation)
            } else {
                (b"", b"")
            };

        if code.contains(&0) {
            return -2;
        }

        if param.is_empty() {
            unsafe { add23_write_slices(out_buf, out_cap, &[b"\0\0"], 2) }
        } else {
            unsafe {
                add23_write_slices(
                    out_buf,
                    out_cap,
                    &[code, b"\0&", param, b"=", code, b"\0", param],
                    2,
                )
            }
        }
    }

    #[inline]
    pub unsafe fn register_emails_match(
        first_ptr: *const u8,
        first_len: usize,
        second_ptr: *const u8,
        second_len: usize,
        out_matches: *mut i32,
    ) -> i32 {
        if out_matches.is_null() || !out_matches.is_aligned() {
            return -1;
        }
        let (Some(first), Some(second)) = (
            unsafe { add23_valid_slice(first_ptr, first_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(second_ptr, second_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };
        let matches = trim_php_bytes(first).eq_ignore_ascii_case(trim_php_bytes(second));
        unsafe {
            *out_matches = i32::from(matches);
        }
        1
    }

    #[inline]
    pub unsafe fn register_evaluate_form_state_nul(
        is_open: u8,
        is_invite_only: u8,
        scheduled_override_active: u8,
        status_msg_ptr: *const u8,
        status_msg_len: usize,
        invite_code_ptr: *const u8,
        invite_code_len: usize,
        invitation_token_valid: u8,
        has_invitation_email: u8,
        invitation_email_ptr: *const u8,
        invitation_email_len: usize,
        initial_error_ptr: *const u8,
        initial_error_len: usize,
        initial_show_register: i32,
        out_show_register: *mut i32,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if is_open > 1
            || is_invite_only > 1
            || scheduled_override_active > 1
            || invitation_token_valid > 1
            || has_invitation_email > 1
            || out_show_register.is_null()
            || !out_show_register.is_aligned()
        {
            return -1;
        }
        let (Some(status_msg), Some(invite_code), Some(invitation_email), Some(initial_error)) = (
            unsafe { add23_valid_slice(status_msg_ptr, status_msg_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(invite_code_ptr, invite_code_len, ADD23_MAX_SEG_LEN) },
            unsafe {
                add23_valid_slice(
                    invitation_email_ptr,
                    invitation_email_len,
                    ADD23_MAX_SEG_LEN,
                )
            },
            unsafe { add23_valid_slice(initial_error_ptr, initial_error_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };

        if status_msg.contains(&0) || invitation_email.contains(&0) || initial_error.contains(&0) {
            return -2;
        }

        let has_invite = !invite_code.is_empty() && invite_code != b"0";
        let has_inv_email = has_invitation_email == 1
            && !invitation_email.is_empty()
            && invitation_email != b"0";

        let _ = initial_show_register;
        let show_register: i32;
        let mut error: &[u8] = initial_error;
        let mut notice: &[u8] = b"";
        let mut email_from_invite: &[u8] = b"";

        if is_open == 1 {
            show_register = 1;
            if has_invite {
                if invitation_token_valid == 1 && has_inv_email {
                    email_from_invite = invitation_email;
                } else if invitation_token_valid == 0 {
                    notice = b"Registration is currently open, so you can register without a valid invitation.";
                }
            }
            if scheduled_override_active == 1 {
                notice = status_msg;
            }
        } else if is_invite_only == 1 {
            if has_invite && invitation_token_valid == 1 {
                show_register = 1;
                if has_inv_email {
                    email_from_invite = invitation_email;
                }
            } else if has_invite {
                error = b"Invalid or expired invitation token!";
                show_register = 0;
            } else {
                error = status_msg;
                show_register = 0;
            }
        } else {
            error = status_msg;
            show_register = 0;
        }

        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[error, b"\0", notice, b"\0", email_from_invite],
                2,
            )
        };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_show_register = show_register;
        }
        written
    }

    #[inline]
    pub unsafe fn admin_user_signup_error_message(
        code: i64,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        let msg: &[u8] = match code {
            -1 => b"Bad username. Try a better one.",
            -2 => b"Bad password. Try a longer one.",
            -3 => b"Bad email.",
            -4 => b"Username in use.",
            -5 => b"Email in use.",
            _ => b"Unknown save error.",
        };
        unsafe { add23_write_slices(out_buf, out_cap, &[msg], 1) }
    }

    #[inline]
    pub unsafe fn admin_user_build_order_urls_nul(
        order_types_nul_ptr: *const u8,
        order_types_nul_len: usize,
        count: usize,
        base_url_ptr: *const u8,
        base_url_len: usize,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if count > ADD23_MAX_SEG_LEN || !add23_valid_out_buf(out_buf, out_cap) {
            return -1;
        }
        let (Some(order_types_nul), Some(base_url)) = (
            unsafe {
                add23_valid_slice(order_types_nul_ptr, order_types_nul_len, ADD23_MAX_SEG_LEN)
            },
            unsafe { add23_valid_slice(base_url_ptr, base_url_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };
        if base_url.contains(&0) {
            return -2;
        }
        if count == 0 {
            return 0;
        }

        let segments: Vec<&[u8]> = order_types_nul.split(|&b| b == 0).collect();
        if segments.len() != count {
            return -1;
        }

        let mut needed = 0usize;
        for (idx, seg) in segments.iter().enumerate() {
            // "orderby" (7) + seg + "\0" (1) + base_url + "?ob=" (4) + seg + optional "\0"
            let pair_len = 7usize
                .checked_add(seg.len())
                .and_then(|v| v.checked_add(1))
                .and_then(|v| v.checked_add(base_url.len()))
                .and_then(|v| v.checked_add(4))
                .and_then(|v| v.checked_add(seg.len()))
                .and_then(|v| v.checked_add(usize::from(idx + 1 < count)));
            let Some(p_len) = pair_len else {
                return -1;
            };
            let Some(next) = needed.checked_add(p_len) else {
                return -1;
            };
            if next > isize::MAX as usize {
                return -1;
            }
            needed = next;
        }

        if needed > out_cap {
            return -(needed.max(1) as isize);
        }

        let mut offset = 0usize;
        let mut push = |bytes: &[u8]| {
            if !bytes.is_empty() {
                unsafe {
                    std::ptr::copy_nonoverlapping(bytes.as_ptr(), out_buf.add(offset), bytes.len());
                }
                offset += bytes.len();
            }
        };

        for (idx, seg) in segments.iter().enumerate() {
            if idx > 0 {
                push(b"\0");
            }
            push(b"orderby");
            push(seg);
            push(b"\0");
            push(base_url);
            push(b"?ob=");
            push(seg);
        }

        needed as isize
    }

    #[inline]
    pub unsafe fn admin_user_normalize_bulk_ids(
        in_ids: *const i64,
        count: usize,
        out_ids: *mut i64,
        out_cap: usize,
        out_unique_count: *mut usize,
    ) -> i32 {
        if count > ADD23_MAX_SEG_LEN
            || out_cap > ADD23_MAX_SEG_LEN
            || out_unique_count.is_null()
            || !out_unique_count.is_aligned()
        {
            return -1;
        }
        if count > 0 {
            let Some(in_bytes) = count.checked_mul(std::mem::size_of::<i64>()) else {
                return -1;
            };
            if in_ids.is_null()
                || !in_ids.is_aligned()
                || (in_ids as usize).checked_add(in_bytes).is_none()
            {
                return -1;
            }
        }
        if out_cap > 0 {
            let Some(out_bytes) = out_cap.checked_mul(std::mem::size_of::<i64>()) else {
                return -1;
            };
            if out_ids.is_null()
                || !out_ids.is_aligned()
                || (out_ids as usize).checked_add(out_bytes).is_none()
            {
                return -1;
            }
        } else if out_ids.is_null() || !out_ids.is_aligned() {
            return -1;
        }

        let slice = if count == 0 {
            &[][..]
        } else {
            unsafe { std::slice::from_raw_parts(in_ids, count) }
        };

        let mut unique: Vec<i64> = Vec::with_capacity(count);
        for &id in slice {
            if !unique.contains(&id) {
                unique.push(id);
            }
        }

        if unique.len() > out_cap {
            return -(unique.len().min(i32::MAX as usize) as i32);
        }

        for (i, &id) in unique.iter().enumerate() {
            unsafe {
                *out_ids.add(i) = id;
            }
        }
        unsafe {
            *out_unique_count = unique.len();
        }
        1
    }

    #[inline]
    pub unsafe fn admin_user_format_passkey_wipe_nul(
        target_user_id: i64,
        target_username_ptr: *const u8,
        target_username_len: usize,
        wiped_count: i64,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        let Some(username) = (unsafe {
            add23_valid_slice(
                target_username_ptr,
                target_username_len,
                ADD23_MAX_SEG_LEN,
            )
        }) else {
            return -1;
        };
        if username.contains(&0) {
            return -2;
        }
        let count_str = wiped_count.to_string();
        let id_str = target_user_id.to_string();
        unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    b"Admin wiped user passkeys\0",
                    count_str.as_bytes(),
                    b" passkeys removed from ",
                    username,
                    b". They can now regain access with their password or an admin-issued reset.\0admin/user-edit?id=",
                    id_str.as_bytes(),
                ],
                2,
            )
        }
    }

    #[inline]
    pub unsafe fn admin_role_normalize_state_nul(
        mode_ptr: *const u8,
        mode_len: usize,
        action_ptr: *const u8,
        action_len: usize,
        has_id: u8,
        has_donation: u8,
        donation_val: i64,
        has_addyears: u8,
        addyears_val: i64,
        out_scalars: *mut i64,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if has_id > 1
            || has_donation > 1
            || has_addyears > 1
            || out_scalars.is_null()
            || !out_scalars.is_aligned()
            || (out_scalars as usize)
                .checked_add(4 * std::mem::size_of::<i64>())
                .is_none()
        {
            return -1;
        }
        let (Some(mode), Some(action)) = (
            unsafe { add23_valid_slice(mode_ptr, mode_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(action_ptr, action_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };

        let is_submit = action == b"submit";
        let (title, should_load_role): (&[u8], bool) = if mode == b"index" {
            (b"User Role List", false)
        } else if mode == b"create" {
            (b"Add User Role", false)
        } else if is_submit {
            (b"Update User Role", false)
        } else if has_id == 1 {
            (b"User Roles Edit", true)
        } else {
            (b"User Roles", false)
        };

        let coerced_donation = if has_donation == 1 { donation_val } else { 0 };
        let coerced_addyears = if has_addyears == 1 { addyears_val } else { 0 };

        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[title, b"\0", title, b"\0admin/role-list"],
                2,
            )
        };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_scalars.add(0) = i64::from(is_submit);
            *out_scalars.add(1) = i64::from(should_load_role);
            *out_scalars.add(2) = coerced_donation;
            *out_scalars.add(3) = coerced_addyears;
        }
        written
    }

    #[inline]
    pub unsafe fn deleted_users_resolve_sort_order_nul(
        order_by_ptr: *const u8,
        order_by_len: usize,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        let Some(raw) =
            (unsafe { add23_valid_slice(order_by_ptr, order_by_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        let trimmed = trim_php_bytes(raw);
        let mut order_field: &[u8] = b"users.deleted_at";
        let mut order_sort: &[u8] = b"desc";

        if !trimmed.is_empty()
            && trimmed
                .iter()
                .all(|&b| b.is_ascii_alphanumeric() || b == b'_')
        {
            let lower: Vec<u8> = trimmed.iter().map(|b| b.to_ascii_lowercase()).collect();
            let (field_slice, dir_opt): (&[u8], Option<&[u8]>) =
                if lower.len() > 4 && lower.ends_with(b"_asc") {
                    (&lower[..lower.len() - 4], Some(b"asc"))
                } else if lower.len() > 5 && lower.ends_with(b"_desc") {
                    (&lower[..lower.len() - 5], Some(b"desc"))
                } else {
                    (&lower[..], None)
                };

            let normalized: Vec<u8> = field_slice
                .iter()
                .copied()
                .filter(|&b| b != b'_' && b != b'-')
                .collect();

            order_field = match normalized.as_slice() {
                b"username" => b"users.username",
                b"email" => b"users.email",
                b"host" => b"users.host",
                b"createdat" => b"users.created_at",
                b"deletedat" => b"users.deleted_at",
                b"lastlogin" => b"users.lastlogin",
                b"apiaccess" => b"users.apiaccess",
                b"grabs" => b"users.grabs",
                b"role" | b"rolename" => b"rolename",
                _ => b"users.deleted_at",
            };
            if let Some(d) = dir_opt {
                order_sort = d;
            }
        }

        unsafe { add23_write_slices(out_buf, out_cap, &[order_field, b"\0", order_sort], 1) }
    }

    #[inline]
    fn rfc1738_encode_append(dst: &mut Vec<u8>, bytes: &[u8]) {
        const HEX: &[u8; 16] = b"0123456789ABCDEF";
        for &b in bytes {
            if b.is_ascii_alphanumeric() || b == b'_' || b == b'-' || b == b'.' {
                dst.push(b);
            } else if b == b' ' {
                dst.push(b'+');
            } else {
                dst.push(b'%');
                dst.push(HEX[(b >> 4) as usize]);
                dst.push(HEX[(b & 0x0F) as usize]);
            }
        }
    }

    #[inline]
    pub unsafe fn deleted_users_filter_query_string(
        keys_nul_ptr: *const u8,
        keys_nul_len: usize,
        vals_nul_ptr: *const u8,
        vals_nul_len: usize,
        null_mask_ptr: *const u8,
        count: usize,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if count > ADD23_MAX_SEG_LEN || !add23_valid_out_buf(out_buf, out_cap) {
            return -1;
        }
        let (Some(keys_nul), Some(vals_nul), Some(null_mask)) = (
            unsafe { add23_valid_slice(keys_nul_ptr, keys_nul_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(vals_nul_ptr, vals_nul_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(null_mask_ptr, count, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };
        if count == 0 {
            return 0;
        }

        let keys: Vec<&[u8]> = keys_nul.split(|&b| b == 0).collect();
        let vals: Vec<&[u8]> = vals_nul.split(|&b| b == 0).collect();
        if keys.len() != count || vals.len() != count {
            return -1;
        }

        let mut encoded = Vec::with_capacity(keys_nul_len + vals_nul_len);
        for i in 0..count {
            if null_mask[i] > 1 {
                return -1;
            }
            let k = keys[i];
            let v = vals[i];
            if k == b"ob" || k == b"page" || null_mask[i] == 1 || v.is_empty() {
                continue;
            }
            if !encoded.is_empty() {
                encoded.push(b'&');
            }
            rfc1738_encode_append(&mut encoded, k);
            encoded.push(b'=');
            rfc1738_encode_append(&mut encoded, v);
        }

        unsafe { add23_write_slices(out_buf, out_cap, &[&encoded], 1) }
    }

    #[inline]
    pub unsafe fn deleted_users_validate_bulk_action_nul(
        action_is_string: u8,
        action_ptr: *const u8,
        action_len: usize,
        ids_is_array: u8,
        in_ids_ptr: *const i64,
        ids_count: usize,
        out_valid_ids_ptr: *mut i64,
        out_ids_cap: usize,
        out_scalars: *mut i64,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if action_is_string > 1
            || ids_is_array > 1
            || ids_count > ADD23_MAX_SEG_LEN
            || out_ids_cap > ADD23_MAX_SEG_LEN
            || out_scalars.is_null()
            || !out_scalars.is_aligned()
            || (out_scalars as usize)
                .checked_add(2 * std::mem::size_of::<i64>())
                .is_none()
        {
            return -1;
        }
        if ids_count > 0 {
            let Some(in_bytes) = ids_count.checked_mul(std::mem::size_of::<i64>()) else {
                return -1;
            };
            if in_ids_ptr.is_null()
                || !in_ids_ptr.is_aligned()
                || (in_ids_ptr as usize).checked_add(in_bytes).is_none()
            {
                return -1;
            }
        }
        if out_ids_cap > 0 {
            let Some(out_bytes) = out_ids_cap.checked_mul(std::mem::size_of::<i64>()) else {
                return -1;
            };
            if out_valid_ids_ptr.is_null()
                || !out_valid_ids_ptr.is_aligned()
                || (out_valid_ids_ptr as usize).checked_add(out_bytes).is_none()
            {
                return -1;
            }
        } else if out_valid_ids_ptr.is_null() || !out_valid_ids_ptr.is_aligned() {
            return -1;
        }

        let Some(action) =
            (unsafe { add23_valid_slice(action_ptr, action_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        let eff_action: &[u8] = if action_is_string == 1 { action } else { b"" };
        if eff_action.contains(&0) {
            return -2;
        }

        let ids_slice = if ids_count == 0 {
            &[][..]
        } else {
            unsafe { std::slice::from_raw_parts(in_ids_ptr, ids_count) }
        };

        let mut valid_ids: Vec<i64> = Vec::new();
        let (is_valid, err_msg): (i64, &[u8]) =
            if action_is_string == 0 || (eff_action != b"restore" && eff_action != b"delete") {
                (0, b"Please select a valid action.")
            } else if ids_is_array == 0 {
                (0, b"Invalid user selection format.")
            } else {
                for &id in ids_slice {
                    if id > 0 {
                        valid_ids.push(id);
                    }
                }
                if valid_ids.is_empty() {
                    (0, b"Please select at least one user.")
                } else {
                    (1, b"")
                }
            };

        if valid_ids.len() > out_ids_cap {
            return -(valid_ids.len().max(1) as isize);
        }

        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[eff_action, b"\0admin.deleted.users.index\0", err_msg],
                2,
            )
        };
        if written < 0 {
            return written;
        }

        for (i, &id) in valid_ids.iter().enumerate() {
            unsafe {
                *out_valid_ids_ptr.add(i) = id;
            }
        }
        unsafe {
            *out_scalars.add(0) = is_valid;
            *out_scalars.add(1) = valid_ids.len() as i64;
        }
        written
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_login_detect_field_type_nul(
    is_string_input: u8,
    input_ptr: *const u8,
    input_len: usize,
    out_is_email: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_admin_user_role_controllers::login_detect_field_type_nul(
            is_string_input,
            input_ptr,
            input_len,
            out_is_email,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_login_trusted_device_remaining(
    expires_at: i64,
    now_ts: i64,
    out_scalars: *mut i64,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_admin_user_role_controllers::login_trusted_device_remaining(
            expires_at,
            now_ts,
            out_scalars,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_login_requires_two_factor(
    has_password_security: u8,
    google2fa_enable_true: u8,
    secret_is_string: u8,
    secret_ptr: *const u8,
    secret_len: usize,
    out_requires: *mut i32,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_admin_user_role_controllers::login_requires_two_factor(
            has_password_security,
            google2fa_enable_true,
            secret_is_string,
            secret_ptr,
            secret_len,
            out_requires,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_register_extract_invite_code_nul(
    has_invitecode: u8,
    invitecode_ptr: *const u8,
    invitecode_len: usize,
    has_token: u8,
    token_ptr: *const u8,
    token_len: usize,
    has_invitation: u8,
    invitation_ptr: *const u8,
    invitation_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_admin_user_role_controllers::register_extract_invite_code_nul(
            has_invitecode,
            invitecode_ptr,
            invitecode_len,
            has_token,
            token_ptr,
            token_len,
            has_invitation,
            invitation_ptr,
            invitation_len,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_register_emails_match(
    first_ptr: *const u8,
    first_len: usize,
    second_ptr: *const u8,
    second_len: usize,
    out_matches: *mut i32,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_admin_user_role_controllers::register_emails_match(
            first_ptr,
            first_len,
            second_ptr,
            second_len,
            out_matches,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_register_evaluate_form_state_nul(
    is_open: u8,
    is_invite_only: u8,
    scheduled_override_active: u8,
    status_msg_ptr: *const u8,
    status_msg_len: usize,
    invite_code_ptr: *const u8,
    invite_code_len: usize,
    invitation_token_valid: u8,
    has_invitation_email: u8,
    invitation_email_ptr: *const u8,
    invitation_email_len: usize,
    initial_error_ptr: *const u8,
    initial_error_len: usize,
    initial_show_register: i32,
    out_show_register: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_admin_user_role_controllers::register_evaluate_form_state_nul(
            is_open,
            is_invite_only,
            scheduled_override_active,
            status_msg_ptr,
            status_msg_len,
            invite_code_ptr,
            invite_code_len,
            invitation_token_valid,
            has_invitation_email,
            invitation_email_ptr,
            invitation_email_len,
            initial_error_ptr,
            initial_error_len,
            initial_show_register,
            out_show_register,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_admin_user_signup_error_message(
    code: i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_admin_user_role_controllers::admin_user_signup_error_message(code, out_buf, out_cap)
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_admin_user_build_order_urls_nul(
    order_types_nul_ptr: *const u8,
    order_types_nul_len: usize,
    count: usize,
    base_url_ptr: *const u8,
    base_url_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_admin_user_role_controllers::admin_user_build_order_urls_nul(
            order_types_nul_ptr,
            order_types_nul_len,
            count,
            base_url_ptr,
            base_url_len,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_admin_user_normalize_bulk_ids(
    in_ids: *const i64,
    count: usize,
    out_ids: *mut i64,
    out_cap: usize,
    out_unique_count: *mut usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_admin_user_role_controllers::admin_user_normalize_bulk_ids(
            in_ids,
            count,
            out_ids,
            out_cap,
            out_unique_count,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_admin_user_format_passkey_wipe_nul(
    target_user_id: i64,
    target_username_ptr: *const u8,
    target_username_len: usize,
    wiped_count: i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_admin_user_role_controllers::admin_user_format_passkey_wipe_nul(
            target_user_id,
            target_username_ptr,
            target_username_len,
            wiped_count,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_admin_role_normalize_state_nul(
    mode_ptr: *const u8,
    mode_len: usize,
    action_ptr: *const u8,
    action_len: usize,
    has_id: u8,
    has_donation: u8,
    donation_val: i64,
    has_addyears: u8,
    addyears_val: i64,
    out_scalars: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_admin_user_role_controllers::admin_role_normalize_state_nul(
            mode_ptr,
            mode_len,
            action_ptr,
            action_len,
            has_id,
            has_donation,
            donation_val,
            has_addyears,
            addyears_val,
            out_scalars,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_deleted_users_resolve_sort_order_nul(
    order_by_ptr: *const u8,
    order_by_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_admin_user_role_controllers::deleted_users_resolve_sort_order_nul(
            order_by_ptr,
            order_by_len,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_deleted_users_filter_query_string(
    keys_nul_ptr: *const u8,
    keys_nul_len: usize,
    vals_nul_ptr: *const u8,
    vals_nul_len: usize,
    null_mask_ptr: *const u8,
    count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_admin_user_role_controllers::deleted_users_filter_query_string(
            keys_nul_ptr,
            keys_nul_len,
            vals_nul_ptr,
            vals_nul_len,
            null_mask_ptr,
            count,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_deleted_users_validate_bulk_action_nul(
    action_is_string: u8,
    action_ptr: *const u8,
    action_len: usize,
    ids_is_array: u8,
    in_ids_ptr: *const i64,
    ids_count: usize,
    out_valid_ids_ptr: *mut i64,
    out_ids_cap: usize,
    out_scalars: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        auth_admin_user_role_controllers::deleted_users_validate_bulk_action_nul(
            action_is_string,
            action_ptr,
            action_len,
            ids_is_array,
            in_ids_ptr,
            ids_count,
            out_valid_ids_ptr,
            out_ids_cap,
            out_scalars,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave28_auth_admin_user_role_controllers_tests {
    use super::*;

    #[test]
    fn login_and_register_controller_helpers_match_php_contracts() {
        let mut buf = [0u8; 256];
        let mut is_email = 0i32;

        let w1 = unsafe {
            nntmux_login_detect_field_type_nul(
                1,
                b"user@example.com".as_ptr(),
                16,
                &mut is_email,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(w1, 5);
        assert_eq!(is_email, 1);
        assert_eq!(&buf[..w1 as usize], b"email");

        let w2 = unsafe {
            nntmux_login_detect_field_type_nul(
                1,
                b"plain_user".as_ptr(),
                10,
                &mut is_email,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(w2, 8);
        assert_eq!(is_email, 0);
        assert_eq!(&buf[..w2 as usize], b"username");

        let mut scalars = [0i64; 3];
        assert_eq!(
            unsafe { nntmux_login_trusted_device_remaining(1061, 1000, scalars.as_mut_ptr()) },
            1
        );
        assert_eq!(scalars, [61, 2, 1]);

        let mut req_2fa = 0i32;
        assert_eq!(
            unsafe {
                nntmux_login_requires_two_factor(
                    1,
                    1,
                    1,
                    b"JBSWY3DPEHPK3PXP".as_ptr(),
                    16,
                    &mut req_2fa,
                )
            },
            1
        );
        assert_eq!(req_2fa, 1);

        let inv_w = unsafe {
            nntmux_register_extract_invite_code_nul(
                0,
                b"".as_ptr(),
                0,
                1,
                b"tok-abc".as_ptr(),
                7,
                1,
                b"inv-xyz".as_ptr(),
                7,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(inv_w > 0);
        assert_eq!(&buf[..inv_w as usize], b"tok-abc\0&token=tok-abc\0token");

        let mut em_match = 0i32;
        assert_eq!(
            unsafe {
                nntmux_register_emails_match(
                    b"  User@Example.COM \n".as_ptr(),
                    20,
                    b"user@example.com".as_ptr(),
                    16,
                    &mut em_match,
                )
            },
            1
        );
        assert_eq!(em_match, 1);

        let mut show_reg = 0i32;
        let state_w = unsafe {
            nntmux_register_evaluate_form_state_nul(
                0,
                1,
                0,
                b"Invite only".as_ptr(),
                11,
                b"bad-tok".as_ptr(),
                7,
                0,
                0,
                b"".as_ptr(),
                0,
                b"".as_ptr(),
                0,
                1,
                &mut show_reg,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(state_w > 0);
        assert_eq!(show_reg, 0);
        assert_eq!(
            &buf[..state_w as usize],
            b"Invalid or expired invitation token!\0\0"
        );
    }

    #[test]
    fn admin_user_role_and_deleted_users_helpers_match_php_contracts() {
        let mut buf = [0u8; 256];
        let err_w =
            unsafe { nntmux_admin_user_signup_error_message(-4, buf.as_mut_ptr(), buf.len()) };
        assert_eq!(&buf[..err_w as usize], b"Username in use.");

        let urls_w = unsafe {
            nntmux_admin_user_build_order_urls_nul(
                b"username_asc\0email_desc".as_ptr(),
                23,
                2,
                b"http://localhost/admin/user-list".as_ptr(),
                32,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(urls_w > 0);
        assert_eq!(
            &buf[..urls_w as usize],
            b"orderbyusername_asc\0http://localhost/admin/user-list?ob=username_asc\0orderbyemail_desc\0http://localhost/admin/user-list?ob=email_desc"
        );

        let in_ids = [7i64, 3, 7, 9, 3];
        let mut out_ids = [0i64; 5];
        let mut unique_count = 0usize;
        assert_eq!(
            unsafe {
                nntmux_admin_user_normalize_bulk_ids(
                    in_ids.as_ptr(),
                    in_ids.len(),
                    out_ids.as_mut_ptr(),
                    out_ids.len(),
                    &mut unique_count,
                )
            },
            1
        );
        assert_eq!(unique_count, 3);
        assert_eq!(&out_ids[..3], &[7, 3, 9]);

        let wipe_w = unsafe {
            nntmux_admin_user_format_passkey_wipe_nul(
                42,
                b"alice".as_ptr(),
                5,
                3,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(wipe_w > 0);
        assert_eq!(
            &buf[..wipe_w as usize],
            b"Admin wiped user passkeys\03 passkeys removed from alice. They can now regain access with their password or an admin-issued reset.\0admin/user-edit?id=42"
        );

        let mut role_scalars = [0i64; 4];
        let role_w = unsafe {
            nntmux_admin_role_normalize_state_nul(
                b"create".as_ptr(),
                6,
                b"submit".as_ptr(),
                6,
                0,
                0,
                0,
                0,
                0,
                role_scalars.as_mut_ptr(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(role_w > 0);
        assert_eq!(role_scalars, [1, 0, 0, 0]);
        assert_eq!(
            &buf[..role_w as usize],
            b"Add User Role\0Add User Role\0admin/role-list"
        );

        let sort_w = unsafe {
            nntmux_deleted_users_resolve_sort_order_nul(
                b"  Created_At_ASC ".as_ptr(),
                17,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(sort_w > 0);
        assert_eq!(&buf[..sort_w as usize], b"users.created_at\0asc");

        let qs_w = unsafe {
            nntmux_deleted_users_filter_query_string(
                b"username\0ob\0empty\0host".as_ptr(),
                22,
                b"john doe\0username_asc\0\010.0.0.1".as_ptr(),
                31,
                [0u8, 0, 0, 0].as_ptr(),
                4,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(qs_w > 0);
        assert_eq!(&buf[..qs_w as usize], b"username=john+doe&host=10.0.0.1");

        let bulk_in = [0i64, -2, 5, 11];
        let mut bulk_out = [0i64; 4];
        let mut bulk_scalars = [0i64; 2];
        let bulk_w = unsafe {
            nntmux_deleted_users_validate_bulk_action_nul(
                1,
                b"restore".as_ptr(),
                7,
                1,
                bulk_in.as_ptr(),
                bulk_in.len(),
                bulk_out.as_mut_ptr(),
                bulk_out.len(),
                bulk_scalars.as_mut_ptr(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(bulk_w > 0);
        assert_eq!(bulk_scalars, [1, 2]);
        assert_eq!(&bulk_out[..2], &[5, 11]);
    }

    #[test]
    fn wave28_slice6_entrypoints_reject_null_unaligned_and_undersized_buffers() {
        let mut raw = [0xA5u8; 64];
        let unaligned_i32 = unsafe { raw.as_mut_ptr().add(1).cast::<i32>() };
        let unaligned_i64 = unsafe { raw.as_mut_ptr().add(1).cast::<i64>() };
        let unaligned_usize = unsafe { raw.as_mut_ptr().add(1).cast::<usize>() };

        assert_eq!(
            unsafe {
                nntmux_login_detect_field_type_nul(
                    1,
                    b"a@b.c".as_ptr(),
                    5,
                    unaligned_i32,
                    raw.as_mut_ptr(),
                    64,
                )
            },
            -1
        );

        assert_eq!(
            unsafe { nntmux_login_trusted_device_remaining(100, 50, unaligned_i64) },
            -1
        );

        assert_eq!(
            unsafe {
                nntmux_login_requires_two_factor(1, 1, 1, b"sec".as_ptr(), 3, unaligned_i32)
            },
            -1
        );

        let mut small = [0x5Au8; 4];
        assert!(
            unsafe {
                nntmux_register_extract_invite_code_nul(
                    1,
                    b"code123".as_ptr(),
                    7,
                    0,
                    b"".as_ptr(),
                    0,
                    0,
                    b"".as_ptr(),
                    0,
                    small.as_mut_ptr(),
                    small.len(),
                )
            } < -4
        );
        assert_eq!(small, [0x5Au8; 4]);

        assert_eq!(
            unsafe {
                nntmux_register_emails_match(
                    b"a@b.c".as_ptr(),
                    5,
                    b"a@b.c".as_ptr(),
                    5,
                    unaligned_i32,
                )
            },
            -1
        );

        assert_eq!(
            unsafe {
                nntmux_admin_user_normalize_bulk_ids(
                    [1i64].as_ptr(),
                    1,
                    unaligned_i64,
                    1,
                    unaligned_usize,
                )
            },
            -1
        );

        assert_eq!(
            unsafe {
                nntmux_admin_role_normalize_state_nul(
                    b"create".as_ptr(),
                    6,
                    b"view".as_ptr(),
                    4,
                    0,
                    0,
                    0,
                    0,
                    0,
                    unaligned_i64,
                    raw.as_mut_ptr(),
                    64,
                )
            },
            -1
        );

        assert_eq!(
            unsafe {
                nntmux_deleted_users_validate_bulk_action_nul(
                    1,
                    b"restore".as_ptr(),
                    7,
                    1,
                    [1i64].as_ptr(),
                    1,
                    unaligned_i64,
                    1,
                    unaligned_i64,
                    raw.as_mut_ptr(),
                    64,
                )
            },
            -1
        );
    }
}

// ---------------------------------------------------------------------------
// Wave 29 Slice 29.6: TmuxHealthCheck, TmuxMonitor, TmuxStart,
// ManageRoleStacking, and MailPreview console command pure-CPU helpers
// ---------------------------------------------------------------------------

pub mod tmux_role_stacking_mail_preview_commands {
    use super::{ADD23_MAX_SEG_LEN, add23_valid_out_buf, add23_valid_slice, add23_write_slices};

    #[inline]
    fn is_php_numeric_ws(b: u8) -> bool {
        matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C)
    }

    #[inline]
    fn is_php_numeric_bytes(raw: &[u8]) -> Option<bool> {
        let mut start = 0usize;
        let mut end = raw.len();
        while start < end && is_php_numeric_ws(raw[start]) {
            start += 1;
        }
        while end > start && is_php_numeric_ws(raw[end - 1]) {
            end -= 1;
        }
        let s = &raw[start..end];
        if s.is_empty() {
            return Some(false);
        }
        let mut idx = 0usize;
        if s[idx] == b'+' || s[idx] == b'-' {
            idx += 1;
            if idx == s.len() {
                return Some(false);
            }
        }
        let mut int_digits = 0usize;
        while idx < s.len() && s[idx].is_ascii_digit() {
            int_digits += 1;
            idx += 1;
        }
        let mut frac_digits = 0usize;
        if idx < s.len() && s[idx] == b'.' {
            idx += 1;
            while idx < s.len() && s[idx].is_ascii_digit() {
                frac_digits += 1;
                idx += 1;
            }
        }
        if int_digits == 0 && frac_digits == 0 {
            return Some(false);
        }
        if idx < s.len() && (s[idx] == b'e' || s[idx] == b'E') {
            idx += 1;
            if idx < s.len() && (s[idx] == b'+' || s[idx] == b'-') {
                idx += 1;
            }
            let mut exp_digits = 0usize;
            while idx < s.len() && s[idx].is_ascii_digit() {
                exp_digits += 1;
                idx += 1;
            }
            if exp_digits == 0 {
                return Some(false);
            }
        }
        if idx != s.len() {
            return Some(false);
        }
        if s.len() > 64 {
            return None;
        }
        Some(true)
    }

    #[inline]
    fn format_i64_with_commas(n: i64) -> String {
        let neg = n < 0;
        let digits = n.unsigned_abs().to_string();
        let bytes = digits.as_bytes();
        let len = bytes.len();
        let mut out = String::with_capacity(len + len / 3 + usize::from(neg));
        if neg {
            out.push('-');
        }
        for (i, &b) in bytes.iter().enumerate() {
            if i > 0 && (len - i) % 3 == 0 {
                out.push(',');
            }
            out.push(b as char);
        }
        out
    }

    #[inline]
    pub unsafe fn tmux_health_check_evaluate_heartbeat(
        has_heartbeat: u8,
        heartbeat: i64,
        now_ts: i64,
        heartbeat_max_age: i64,
        delay: i64,
        out_scalars: *mut i64,
    ) -> i32 {
        if has_heartbeat > 1
            || out_scalars.is_null()
            || !out_scalars.is_aligned()
            || (out_scalars as usize)
                .checked_add(2 * std::mem::size_of::<i64>())
                .is_none()
        {
            return -1;
        }
        let Some(delay_plus_15) = delay.checked_add(15) else {
            return -2;
        };
        let max_age = 1i64.max(heartbeat_max_age).max(delay_plus_15);
        let is_stale = if has_heartbeat == 1 {
            let Some(age) = now_ts.checked_sub(heartbeat) else {
                return -2;
            };
            age > max_age
        } else {
            false
        };
        unsafe {
            *out_scalars.add(0) = max_age;
            *out_scalars.add(1) = i64::from(is_stale);
        }
        1
    }

    #[inline]
    pub unsafe fn tmux_health_check_resolve_log_context_nul(
        level_ptr: *const u8,
        level_len: usize,
        outcome_ptr: *const u8,
        outcome_len: usize,
        session_ptr: *const u8,
        session_len: usize,
        auto_restart: u8,
        should_be_running: u8,
        has_exit_code: u8,
        exit_code: i64,
        out_scalars: *mut i64,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if auto_restart > 1
            || should_be_running > 1
            || has_exit_code > 1
            || out_scalars.is_null()
            || !out_scalars.is_aligned()
            || (out_scalars as usize)
                .checked_add(4 * std::mem::size_of::<i64>())
                .is_none()
        {
            return -1;
        }
        let (Some(level), Some(outcome), Some(session)) = (
            unsafe { add23_valid_slice(level_ptr, level_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(outcome_ptr, outcome_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(session_ptr, session_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };
        if level.contains(&0) || outcome.contains(&0) || session.contains(&0) {
            return -2;
        }
        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    level,
                    b"\0Tmux health check result\0",
                    session,
                    b"\0",
                    outcome,
                ],
                3,
            )
        };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_scalars.add(0) = i64::from(auto_restart);
            *out_scalars.add(1) = i64::from(should_be_running);
            *out_scalars.add(2) = i64::from(has_exit_code);
            *out_scalars.add(3) = if has_exit_code == 1 { exit_code } else { 0 };
        }
        written
    }

    #[inline]
    pub unsafe fn tmux_monitor_format_compensation_message(
        count: i64,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if count > 0 {
            let count_str = count.to_string();
            unsafe {
                add23_write_slices(
                    out_buf,
                    out_cap,
                    &[
                        "✅ Gave ".as_bytes(),
                        count_str.as_bytes(),
                        b" waiting collections back the time lost while the indexer was down",
                    ],
                    1,
                )
            }
        } else {
            unsafe {
                add23_write_slices(
                    out_buf,
                    out_cap,
                    &["✅ No collections needed more time".as_bytes()],
                    1,
                )
            }
        }
    }

    #[inline]
    pub unsafe fn tmux_monitor_evaluate_loop_step(
        delay: i64,
        iteration: i64,
        out_scalars: *mut i64,
    ) -> i32 {
        if out_scalars.is_null()
            || !out_scalars.is_aligned()
            || (out_scalars as usize)
                .checked_add(2 * std::mem::size_of::<i64>())
                .is_none()
        {
            return -1;
        }
        let clamped_delay = delay.max(1);
        let should_log_idle = i64::from(iteration.wrapping_rem(60) == 0);
        unsafe {
            *out_scalars.add(0) = clamped_delay;
            *out_scalars.add(1) = should_log_idle;
        }
        1
    }

    #[inline]
    pub unsafe fn tmux_start_verify_which_output(
        successful: u8,
        output_ptr: *const u8,
        output_len: usize,
        out_installed: *mut i32,
    ) -> i32 {
        if successful > 1
            || out_installed.is_null()
            || !out_installed.is_aligned()
            || (out_installed as usize)
                .checked_add(std::mem::size_of::<i32>())
                .is_none()
        {
            return -1;
        }
        let Some(output) =
            (unsafe { add23_valid_slice(output_ptr, output_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        let installed = successful == 1 && output.windows(4).any(|w| w == b"tmux");
        unsafe {
            *out_installed = i32::from(installed);
        }
        1
    }

    #[inline]
    pub unsafe fn tmux_start_format_ready_channel(
        hex_ptr: *const u8,
        hex_len: usize,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        let Some(hex) = (unsafe { add23_valid_slice(hex_ptr, hex_len, ADD23_MAX_SEG_LEN) }) else {
            return -1;
        };
        unsafe { add23_write_slices(out_buf, out_cap, &[b"nntmux-ready-", hex], 1) }
    }

    #[inline]
    pub unsafe fn tmux_start_format_attach_hint(
        session_ptr: *const u8,
        session_len: usize,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if !add23_valid_out_buf(out_buf, out_cap) {
            return -1;
        }
        let Some(session) =
            (unsafe { add23_valid_slice(session_ptr, session_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        if session.contains(&0) {
            return -2;
        }
        let prefix = "💡 To attach, run: php artisan tmux:attach --session='".as_bytes();
        let suffix = b"'";
        let mut escaped = Vec::with_capacity(session.len() + 8);
        for &b in session {
            if b == b'\'' {
                escaped.extend_from_slice(b"'\\''");
            } else {
                escaped.push(b);
            }
        }
        unsafe { add23_write_slices(out_buf, out_cap, &[prefix, &escaped, suffix], 1) }
    }

    #[inline]
    pub unsafe fn role_stacking_resolve_action_nul(
        action_ptr: *const u8,
        action_len: usize,
        out_is_valid: *mut i32,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if out_is_valid.is_null()
            || !out_is_valid.is_aligned()
            || (out_is_valid as usize)
                .checked_add(std::mem::size_of::<i32>())
                .is_none()
        {
            return -1;
        }
        let Some(action) =
            (unsafe { add23_valid_slice(action_ptr, action_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        if action.contains(&0) {
            return -2;
        }
        let (is_valid, written): (i32, isize) = match action {
            b"list-pending" | b"cancel-pending" | b"history" | b"activate-pending" => {
                let w = unsafe { add23_write_slices(out_buf, out_cap, &[action, b"\0"], 1) };
                (1, w)
            }
            _ => {
                let w = unsafe {
                    add23_write_slices(
                        out_buf,
                        out_cap,
                        &[b"unknown\0Unknown action: ", action],
                        1,
                    )
                };
                (0, w)
            }
        };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_is_valid = is_valid;
        }
        written
    }

    #[inline]
    pub unsafe fn role_stacking_format_pending_row_nul(
        user_id: i64,
        username_ptr: *const u8,
        username_len: usize,
        has_current_role: u8,
        current_role_ptr: *const u8,
        current_role_len: usize,
        has_pending_role: u8,
        pending_role_ptr: *const u8,
        pending_role_len: usize,
        formatted_date_ptr: *const u8,
        formatted_date_len: usize,
        time_until_ptr: *const u8,
        time_until_len: usize,
        out_user_id: *mut i64,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if has_current_role > 1
            || has_pending_role > 1
            || out_user_id.is_null()
            || !out_user_id.is_aligned()
            || (out_user_id as usize)
                .checked_add(std::mem::size_of::<i64>())
                .is_none()
        {
            return -1;
        }
        let (
            Some(username),
            Some(current_role),
            Some(pending_role),
            Some(formatted_date),
            Some(time_until),
        ) = (
            unsafe { add23_valid_slice(username_ptr, username_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(current_role_ptr, current_role_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(pending_role_ptr, pending_role_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(formatted_date_ptr, formatted_date_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(time_until_ptr, time_until_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };
        let curr: &[u8] = if has_current_role == 1 {
            current_role
        } else {
            b"None"
        };
        let pend: &[u8] = if has_pending_role == 1 {
            pending_role
        } else {
            b"Unknown"
        };
        if username.contains(&0)
            || curr.contains(&0)
            || pend.contains(&0)
            || formatted_date.contains(&0)
            || time_until.contains(&0)
        {
            return -2;
        }
        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    username,
                    b"\0",
                    curr,
                    b"\0",
                    pend,
                    b"\0",
                    formatted_date,
                    b"\0",
                    time_until,
                ],
                4,
            )
        };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_user_id = user_id;
        }
        written
    }

    #[inline]
    pub unsafe fn role_stacking_format_history_row_nul(
        formatted_date_ptr: *const u8,
        formatted_date_len: usize,
        has_old_role: u8,
        old_role_ptr: *const u8,
        old_role_len: usize,
        has_new_role: u8,
        new_role_ptr: *const u8,
        new_role_len: usize,
        is_stacked: u8,
        has_change_reason: u8,
        change_reason_ptr: *const u8,
        change_reason_len: usize,
        has_changed_by: u8,
        changed_by_ptr: *const u8,
        changed_by_len: usize,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if has_old_role > 1
            || has_new_role > 1
            || is_stacked > 1
            || has_change_reason > 1
            || has_changed_by > 1
        {
            return -1;
        }
        let (
            Some(formatted_date),
            Some(old_role),
            Some(new_role),
            Some(change_reason),
            Some(changed_by),
        ) = (
            unsafe { add23_valid_slice(formatted_date_ptr, formatted_date_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(old_role_ptr, old_role_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(new_role_ptr, new_role_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(change_reason_ptr, change_reason_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(changed_by_ptr, changed_by_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };
        let old_r: &[u8] = if has_old_role == 1 { old_role } else { b"None" };
        let new_r: &[u8] = if has_new_role == 1 {
            new_role
        } else {
            b"Unknown"
        };
        let change_type: &[u8] = if is_stacked == 1 {
            b"Stacked"
        } else {
            b"Immediate"
        };
        let reason: &[u8] = if has_change_reason == 1 {
            change_reason
        } else {
            b"N/A"
        };
        let by: &[u8] = if has_changed_by == 1 {
            changed_by
        } else {
            b"System"
        };
        if formatted_date.contains(&0)
            || old_r.contains(&0)
            || new_r.contains(&0)
            || reason.contains(&0)
            || by.contains(&0)
        {
            return -2;
        }
        unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    formatted_date,
                    b"\0",
                    old_r,
                    b"\0",
                    new_r,
                    b"\0",
                    change_type,
                    b"\0",
                    reason,
                    b"\0",
                    by,
                ],
                5,
            )
        }
    }

    #[inline]
    pub unsafe fn role_stacking_classify_identifier_nul(
        is_int_input: u8,
        int_val: i64,
        str_ptr: *const u8,
        str_len: usize,
        out_is_numeric: *mut i32,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if is_int_input > 1
            || out_is_numeric.is_null()
            || !out_is_numeric.is_aligned()
            || (out_is_numeric as usize)
                .checked_add(std::mem::size_of::<i32>())
                .is_none()
        {
            return -1;
        }
        let Some(str_slice) =
            (unsafe { add23_valid_slice(str_ptr, str_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        if is_int_input == 1 {
            let id_str = int_val.to_string();
            let written = unsafe {
                add23_write_slices(
                    out_buf,
                    out_cap,
                    &[b"id\0User not found: ", id_str.as_bytes()],
                    1,
                )
            };
            if written < 0 {
                return written;
            }
            unsafe {
                *out_is_numeric = 1;
            }
            return written;
        }

        if str_slice.contains(&0) {
            return -2;
        }
        let Some(is_num) = is_php_numeric_bytes(str_slice) else {
            return -2;
        };
        let lookup_field: &[u8] = if is_num { b"id" } else { b"username" };
        let written = unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[lookup_field, b"\0User not found: ", str_slice],
                1,
            )
        };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_is_numeric = i32::from(is_num);
        }
        written
    }

    #[inline]
    pub unsafe fn mail_preview_resolve_output_dir(
        has_out: u8,
        out_ptr: *const u8,
        out_len: usize,
        default_ptr: *const u8,
        default_len: usize,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if has_out > 1 {
            return -1;
        }
        let (Some(out_dir), Some(default_dir)) = (
            unsafe { add23_valid_slice(out_ptr, out_len, ADD23_MAX_SEG_LEN) },
            unsafe { add23_valid_slice(default_ptr, default_len, ADD23_MAX_SEG_LEN) },
        ) else {
            return -1;
        };
        let chosen = if has_out == 1 && !out_dir.is_empty() && out_dir != b"0" {
            out_dir
        } else {
            default_dir
        };
        unsafe { add23_write_slices(out_buf, out_cap, &[chosen], 1) }
    }

    #[inline]
    pub unsafe fn mail_preview_format_byte_summary(
        name_ptr: *const u8,
        name_len: usize,
        byte_count: i64,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        let Some(name) = (unsafe { add23_valid_slice(name_ptr, name_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        if byte_count.unsigned_abs() >= (1u64 << 53) {
            return -2;
        }
        let formatted = format_i64_with_commas(byte_count);
        unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    b"Wrote ",
                    name,
                    b".html (",
                    formatted.as_bytes(),
                    b" bytes)",
                ],
                1,
            )
        }
    }

    #[inline]
    pub unsafe fn mail_preview_fixture_attributes_nul(
        out_scalars: *mut i64,
        out_buf: *mut u8,
        out_cap: usize,
    ) -> isize {
        if out_scalars.is_null()
            || !out_scalars.is_aligned()
            || (out_scalars as usize)
                .checked_add(5 * std::mem::size_of::<i64>())
                .is_none()
        {
            return -1;
        }
        const PAYLOAD: &[u8] = b"tester\0tester@example.test\0Silver\0tester\0tester@example.test\0mod_alice\0preview-token-abc123\0newuser@example.test\0API latency spike\0p99 latency exceeded thresholds.\nFollow status page for updates.\0NZB Search\0API";
        let written = unsafe { add23_write_slices(out_buf, out_cap, &[PAYLOAD], 11) };
        if written < 0 {
            return written;
        }
        unsafe {
            *out_scalars.add(0) = 99;
            *out_scalars.add(1) = 7;
            *out_scalars.add(2) = 1;
            *out_scalars.add(3) = 7;
            *out_scalars.add(4) = 15;
        }
        written
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_health_check_evaluate_heartbeat(
    has_heartbeat: u8,
    heartbeat: i64,
    now_ts: i64,
    heartbeat_max_age: i64,
    delay: i64,
    out_scalars: *mut i64,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        tmux_role_stacking_mail_preview_commands::tmux_health_check_evaluate_heartbeat(
            has_heartbeat,
            heartbeat,
            now_ts,
            heartbeat_max_age,
            delay,
            out_scalars,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_health_check_resolve_log_context_nul(
    level_ptr: *const u8,
    level_len: usize,
    outcome_ptr: *const u8,
    outcome_len: usize,
    session_ptr: *const u8,
    session_len: usize,
    auto_restart: u8,
    should_be_running: u8,
    has_exit_code: u8,
    exit_code: i64,
    out_scalars: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        tmux_role_stacking_mail_preview_commands::tmux_health_check_resolve_log_context_nul(
            level_ptr,
            level_len,
            outcome_ptr,
            outcome_len,
            session_ptr,
            session_len,
            auto_restart,
            should_be_running,
            has_exit_code,
            exit_code,
            out_scalars,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_monitor_format_compensation_message(
    count: i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        tmux_role_stacking_mail_preview_commands::tmux_monitor_format_compensation_message(
            count, out_buf, out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_monitor_evaluate_loop_step(
    delay: i64,
    iteration: i64,
    out_scalars: *mut i64,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        tmux_role_stacking_mail_preview_commands::tmux_monitor_evaluate_loop_step(
            delay,
            iteration,
            out_scalars,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_start_verify_which_output(
    successful: u8,
    output_ptr: *const u8,
    output_len: usize,
    out_installed: *mut i32,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        tmux_role_stacking_mail_preview_commands::tmux_start_verify_which_output(
            successful,
            output_ptr,
            output_len,
            out_installed,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_start_format_ready_channel(
    hex_ptr: *const u8,
    hex_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        tmux_role_stacking_mail_preview_commands::tmux_start_format_ready_channel(
            hex_ptr, hex_len, out_buf, out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_start_format_attach_hint(
    session_ptr: *const u8,
    session_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        tmux_role_stacking_mail_preview_commands::tmux_start_format_attach_hint(
            session_ptr,
            session_len,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_role_stacking_resolve_action_nul(
    action_ptr: *const u8,
    action_len: usize,
    out_is_valid: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        tmux_role_stacking_mail_preview_commands::role_stacking_resolve_action_nul(
            action_ptr,
            action_len,
            out_is_valid,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_role_stacking_format_pending_row_nul(
    user_id: i64,
    username_ptr: *const u8,
    username_len: usize,
    has_current_role: u8,
    current_role_ptr: *const u8,
    current_role_len: usize,
    has_pending_role: u8,
    pending_role_ptr: *const u8,
    pending_role_len: usize,
    formatted_date_ptr: *const u8,
    formatted_date_len: usize,
    time_until_ptr: *const u8,
    time_until_len: usize,
    out_user_id: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        tmux_role_stacking_mail_preview_commands::role_stacking_format_pending_row_nul(
            user_id,
            username_ptr,
            username_len,
            has_current_role,
            current_role_ptr,
            current_role_len,
            has_pending_role,
            pending_role_ptr,
            pending_role_len,
            formatted_date_ptr,
            formatted_date_len,
            time_until_ptr,
            time_until_len,
            out_user_id,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_role_stacking_format_history_row_nul(
    formatted_date_ptr: *const u8,
    formatted_date_len: usize,
    has_old_role: u8,
    old_role_ptr: *const u8,
    old_role_len: usize,
    has_new_role: u8,
    new_role_ptr: *const u8,
    new_role_len: usize,
    is_stacked: u8,
    has_change_reason: u8,
    change_reason_ptr: *const u8,
    change_reason_len: usize,
    has_changed_by: u8,
    changed_by_ptr: *const u8,
    changed_by_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        tmux_role_stacking_mail_preview_commands::role_stacking_format_history_row_nul(
            formatted_date_ptr,
            formatted_date_len,
            has_old_role,
            old_role_ptr,
            old_role_len,
            has_new_role,
            new_role_ptr,
            new_role_len,
            is_stacked,
            has_change_reason,
            change_reason_ptr,
            change_reason_len,
            has_changed_by,
            changed_by_ptr,
            changed_by_len,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_role_stacking_classify_identifier_nul(
    is_int_input: u8,
    int_val: i64,
    str_ptr: *const u8,
    str_len: usize,
    out_is_numeric: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        tmux_role_stacking_mail_preview_commands::role_stacking_classify_identifier_nul(
            is_int_input,
            int_val,
            str_ptr,
            str_len,
            out_is_numeric,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_mail_preview_resolve_output_dir(
    has_out: u8,
    out_ptr: *const u8,
    out_len: usize,
    default_ptr: *const u8,
    default_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        tmux_role_stacking_mail_preview_commands::mail_preview_resolve_output_dir(
            has_out,
            out_ptr,
            out_len,
            default_ptr,
            default_len,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_mail_preview_format_byte_summary(
    name_ptr: *const u8,
    name_len: usize,
    byte_count: i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        tmux_role_stacking_mail_preview_commands::mail_preview_format_byte_summary(
            name_ptr, name_len, byte_count, out_buf, out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_mail_preview_fixture_attributes_nul(
    out_scalars: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        tmux_role_stacking_mail_preview_commands::mail_preview_fixture_attributes_nul(
            out_scalars,
            out_buf,
            out_cap,
        )
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave29_tmux_role_stacking_mail_preview_commands_tests {
    use super::*;

    #[test]
    fn tmux_health_check_monitor_and_start_helpers_match_php_contracts() {
        let mut scalars = [0i64; 4];
        assert_eq!(
            unsafe {
                nntmux_tmux_health_check_evaluate_heartbeat(
                    1,
                    1000,
                    1200,
                    180,
                    10,
                    scalars.as_mut_ptr(),
                )
            },
            1
        );
        assert_eq!(&scalars[..2], &[180, 1]);

        let mut buf = [0u8; 256];
        let log_w = unsafe {
            nntmux_tmux_health_check_resolve_log_context_nul(
                b"warning".as_ptr(),
                7,
                b"monitor_heartbeat_stale".as_ptr(),
                23,
                b"nntmux".as_ptr(),
                6,
                1,
                1,
                0,
                0,
                scalars.as_mut_ptr(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(log_w > 0);
        assert_eq!(scalars, [1, 1, 0, 0]);
        assert_eq!(
            &buf[..log_w as usize],
            b"warning\0Tmux health check result\0nntmux\0monitor_heartbeat_stale"
        );

        let comp_w = unsafe {
            nntmux_tmux_monitor_format_compensation_message(5, buf.as_mut_ptr(), buf.len())
        };
        assert!(comp_w > 0);
        assert_eq!(
            &buf[..comp_w as usize],
            "✅ Gave 5 waiting collections back the time lost while the indexer was down"
                .as_bytes()
        );

        assert_eq!(
            unsafe { nntmux_tmux_monitor_evaluate_loop_step(-5, 120, scalars.as_mut_ptr()) },
            1
        );
        assert_eq!(&scalars[..2], &[1, 1]);

        let mut installed = 0i32;
        assert_eq!(
            unsafe {
                nntmux_tmux_start_verify_which_output(
                    1,
                    b"/opt/homebrew/bin/tmux\n".as_ptr(),
                    23,
                    &mut installed,
                )
            },
            1
        );
        assert_eq!(installed, 1);

        let chan_w = unsafe {
            nntmux_tmux_start_format_ready_channel(
                b"deadbeef1234".as_ptr(),
                12,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(&buf[..chan_w as usize], b"nntmux-ready-deadbeef1234");

        let hint_w = unsafe {
            nntmux_tmux_start_format_attach_hint(
                b"my'sess".as_ptr(),
                7,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(
            &buf[..hint_w as usize],
            "💡 To attach, run: php artisan tmux:attach --session='my'\\''sess'".as_bytes()
        );
    }

    #[test]
    fn role_stacking_and_mail_preview_helpers_match_php_contracts() {
        let mut buf = [0u8; 512];
        let mut valid = 0i32;
        let act_w = unsafe {
            nntmux_role_stacking_resolve_action_nul(
                b"list-pending".as_ptr(),
                12,
                &mut valid,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(valid, 1);
        assert_eq!(&buf[..act_w as usize], b"list-pending\0");

        let mut out_uid = 0i64;
        let pend_w = unsafe {
            nntmux_role_stacking_format_pending_row_nul(
                42,
                b"alice".as_ptr(),
                5,
                0,
                b"".as_ptr(),
                0,
                1,
                b"VIP".as_ptr(),
                3,
                b"2026-10-25 12:00".as_ptr(),
                16,
                b"in 1 day".as_ptr(),
                8,
                &mut out_uid,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(out_uid, 42);
        assert_eq!(
            &buf[..pend_w as usize],
            b"alice\0None\0VIP\02026-10-25 12:00\0in 1 day"
        );

        let hist_w = unsafe {
            nntmux_role_stacking_format_history_row_nul(
                b"2026-10-24 08:00".as_ptr(),
                16,
                1,
                b"User".as_ptr(),
                4,
                1,
                b"Gold".as_ptr(),
                4,
                1,
                0,
                b"".as_ptr(),
                0,
                0,
                b"".as_ptr(),
                0,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(
            &buf[..hist_w as usize],
            b"2026-10-24 08:00\0User\0Gold\0Stacked\0N/A\0System"
        );

        let mut is_num = 0i32;
        let cls_w = unsafe {
            nntmux_role_stacking_classify_identifier_nul(
                0,
                0,
                b"12345".as_ptr(),
                5,
                &mut is_num,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(is_num, 1);
        assert_eq!(&buf[..cls_w as usize], b"id\0User not found: 12345");

        let dir_w = unsafe {
            nntmux_mail_preview_resolve_output_dir(
                0,
                b"".as_ptr(),
                0,
                b"/var/mail-previews".as_ptr(),
                18,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(&buf[..dir_w as usize], b"/var/mail-previews");

        let sum_w = unsafe {
            nntmux_mail_preview_format_byte_summary(
                b"welcome".as_ptr(),
                7,
                12345,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(
            &buf[..sum_w as usize],
            b"Wrote welcome.html (12,345 bytes)"
        );

        let mut f_scalars = [0i64; 5];
        let fix_w = unsafe {
            nntmux_mail_preview_fixture_attributes_nul(
                f_scalars.as_mut_ptr(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(fix_w > 0);
        assert_eq!(f_scalars, [99, 7, 1, 7, 15]);
    }

    #[test]
    fn wave29_slice6_entrypoints_reject_null_unaligned_and_undersized_buffers() {
        let mut raw = [0xA5u8; 64];
        let unaligned_i32 = unsafe { raw.as_mut_ptr().add(1).cast::<i32>() };
        let unaligned_i64 = unsafe { raw.as_mut_ptr().add(1).cast::<i64>() };

        assert_eq!(
            unsafe {
                nntmux_tmux_health_check_evaluate_heartbeat(1, 10, 20, 180, 10, unaligned_i64)
            },
            -1
        );

        assert_eq!(
            unsafe {
                nntmux_tmux_health_check_resolve_log_context_nul(
                    b"info".as_ptr(),
                    4,
                    b"healthy".as_ptr(),
                    7,
                    b"nntmux".as_ptr(),
                    6,
                    0,
                    1,
                    0,
                    0,
                    unaligned_i64,
                    raw.as_mut_ptr(),
                    64,
                )
            },
            -1
        );

        assert_eq!(
            unsafe { nntmux_tmux_monitor_evaluate_loop_step(10, 1, unaligned_i64) },
            -1
        );

        assert_eq!(
            unsafe {
                nntmux_tmux_start_verify_which_output(1, b"tmux".as_ptr(), 4, unaligned_i32)
            },
            -1
        );

        let mut small = [0x5Au8; 4];
        assert!(
            unsafe {
                nntmux_tmux_start_format_ready_channel(
                    b"abcdef".as_ptr(),
                    6,
                    small.as_mut_ptr(),
                    small.len(),
                )
            } < -4
        );
        assert_eq!(small, [0x5Au8; 4]);

        assert_eq!(
            unsafe {
                nntmux_role_stacking_resolve_action_nul(
                    b"history".as_ptr(),
                    7,
                    unaligned_i32,
                    raw.as_mut_ptr(),
                    64,
                )
            },
            -1
        );

        assert_eq!(
            unsafe {
                nntmux_role_stacking_classify_identifier_nul(
                    0,
                    0,
                    b"alice".as_ptr(),
                    5,
                    unaligned_i32,
                    raw.as_mut_ptr(),
                    64,
                )
            },
            -1
        );

        assert_eq!(
            unsafe {
                nntmux_mail_preview_fixture_attributes_nul(unaligned_i64, raw.as_mut_ptr(), 64)
            },
            -1
        );
    }
}
// ---------------------------------------------------------------------------
// Wave 30 Slice 30.6: AdditionalProcessingDiagnose, ProcessAdditionalGuid,
// PostProcessGuid, FetchSamples, and CheckServiceHealth command helpers
// ---------------------------------------------------------------------------

const W306_DIAGNOSE_ROW_SEGMENTS: usize = 8;
const W306_HEALTH_ROW_SEGMENTS: usize = 5;
const W306_SUMMARY_ROW_SEGMENTS: usize = 4;
const W306_IDENTIFIER_SCALARS: usize = 4;
const W306_RESET_FLAG_SCALARS: usize = 5;

const W306_HEX_CHARS: &[u8] = b"abcdef0123456789";
const W306_POSTPROCESS_TYPES: [&[u8]; 9] = [
    b"additional",
    b"nfo",
    b"movie",
    b"tv",
    b"anime",
    b"books",
    b"music",
    b"console",
    b"games",
];

const W306_ERR_MISSING_IDENTIFIER: &[u8] =
    b"You must supply either a GUID argument or --id=<release id>.";
const W306_ERR_CONFLICTING_IDENTIFIERS: &[u8] =
    b"Provide only one identifier: GUID or --id, not both.";
const W306_ERR_NON_NUMERIC_ID: &[u8] = b"Release ID must be numeric.";

#[inline]
fn w306_valid_i64_out(ptr: *mut i64, count: usize) -> bool {
    !ptr.is_null()
        && ptr.is_aligned()
        && count
            .checked_mul(std::mem::size_of::<i64>())
            .and_then(|bytes| (ptr as usize).checked_add(bytes))
            .is_some()
}

#[inline]
unsafe fn w306_valid_i64_in<'a>(ptr: *const i64, count: usize) -> Option<&'a [i64]> {
    if ptr.is_null()
        || !ptr.is_aligned()
        || count > (isize::MAX as usize) / std::mem::size_of::<i64>()
        || (ptr as usize)
            .checked_add(count * std::mem::size_of::<i64>())
            .is_none()
    {
        return None;
    }
    if count == 0 {
        return Some(&[]);
    }
    Some(unsafe { std::slice::from_raw_parts(ptr, count) })
}

/// Writes `parts` back to back into `out_buf` and each part's byte length into `out_lens`, so
/// segments may contain NUL bytes. Returns the total byte length, `-(needed)` when `out_cap` is
/// too small (nothing written), or `-1` on invalid pointers.
#[inline]
unsafe fn w306_write_segments(
    out_buf: *mut u8,
    out_cap: usize,
    out_lens: *mut i64,
    parts: &[&[u8]],
) -> isize {
    if !add23_valid_out_buf(out_buf, out_cap) || !w306_valid_i64_out(out_lens, parts.len()) {
        return -1;
    }
    let mut needed = 0usize;
    for part in parts {
        let Some(next) = needed.checked_add(part.len()) else {
            return -1;
        };
        if next > isize::MAX as usize {
            return -1;
        }
        needed = next;
    }
    if needed > out_cap {
        return -(needed.max(1) as isize);
    }
    let mut offset = 0usize;
    for (index, part) in parts.iter().enumerate() {
        if !part.is_empty() {
            unsafe {
                std::ptr::copy_nonoverlapping(part.as_ptr(), out_buf.add(offset), part.len());
            }
            offset += part.len();
        }
        unsafe {
            *out_lens.add(index) = part.len() as i64;
        }
    }
    needed as isize
}

#[inline]
fn w306_is_php_trim_byte(byte: u8) -> bool {
    matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0x00 | 0x0B)
}

/// PHP `trim()` with its default character list.
#[inline]
fn w306_php_trim(bytes: &[u8]) -> &[u8] {
    let mut start = 0usize;
    let mut end = bytes.len();
    while start < end && w306_is_php_trim_byte(bytes[start]) {
        start += 1;
    }
    while end > start && w306_is_php_trim_byte(bytes[end - 1]) {
        end -= 1;
    }
    &bytes[start..end]
}

/// PHP `ctype_digit()` on a string: non-empty and every byte is `0-9`.
#[inline]
fn w306_ctype_digit(bytes: &[u8]) -> bool {
    !bytes.is_empty() && bytes.iter().all(u8::is_ascii_digit)
}

/// PHP `(int)` of a `ctype_digit()` string, saturating at `PHP_INT_MAX` like PHP does.
#[inline]
fn w306_digits_to_i64(bytes: &[u8]) -> i64 {
    let mut value = 0i64;
    for &byte in bytes {
        match value
            .checked_mul(10)
            .and_then(|v| v.checked_add(i64::from(byte - b'0')))
        {
            Some(next) => value = next,
            None => return i64::MAX,
        }
    }
    value
}

#[inline]
fn w306_group_thousands(digits: &[u8], out: &mut Vec<u8>) {
    let len = digits.len();
    for (index, &byte) in digits.iter().enumerate() {
        if index > 0 && (len - index).is_multiple_of(3) {
            out.push(b',');
        }
        out.push(byte);
    }
}

/// PHP `number_format($value, 2)`; `None` for non-finite, large, or near-tie values so the caller
/// keeps PHP's exact rounding for those.
fn w306_number_format_2(value: f64) -> Option<Vec<u8>> {
    if !value.is_finite() || value.abs() >= 1e12 {
        return None;
    }
    let scaled = value * 100.0;
    let frac = (scaled - scaled.trunc()).abs();
    if (frac - 0.5).abs() <= 1e-6 {
        return None;
    }
    let cents = scaled.round() as i64;
    let magnitude = cents.unsigned_abs();
    let mut out = Vec::with_capacity(24);
    if cents < 0 {
        out.push(b'-');
    }
    w306_group_thousands((magnitude / 100).to_string().as_bytes(), &mut out);
    out.push(b'.');
    let fraction = (magnitude % 100) as u8;
    out.push(b'0' + fraction / 10);
    out.push(b'0' + fraction % 10);
    Some(out)
}

/// `AdditionalProcessingDiagnose::projectBacklogRows()`: label/value cells for the backlog table.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_additional_diagnose_backlog_rows(
    total: i64,
    available: i64,
    active_claims: i64,
    stale_claims: i64,
    out_buf: *mut u8,
    out_cap: usize,
    out_lens: *mut i64,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let total = total.to_string();
        let available = available.to_string();
        let active_claims = active_claims.to_string();
        let stale_claims = stale_claims.to_string();
        let parts: [&[u8]; W306_DIAGNOSE_ROW_SEGMENTS] = [
            b"Total",
            total.as_bytes(),
            b"Available",
            available.as_bytes(),
            b"Active claims",
            active_claims.as_bytes(),
            b"Stale claims",
            stale_claims.as_bytes(),
        ];
        unsafe { w306_write_segments(out_buf, out_cap, out_lens, &parts) }
    }))
    .unwrap_or(-1)
}

/// `AdditionalProcessingDiagnose::projectPreflightRows()`: label/value cells for the preflight
/// table; a missing claim queue index renders as `missing`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_additional_diagnose_preflight_rows(
    driver_ptr: *const u8,
    driver_len: usize,
    has_index: u8,
    index_ptr: *const u8,
    index_len: usize,
    path_ptr: *const u8,
    path_len: usize,
    writable: u8,
    out_buf: *mut u8,
    out_cap: usize,
    out_lens: *mut i64,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if has_index > 1 || writable > 1 {
            return -1;
        }
        let (Some(driver), Some(index), Some(path)) = (unsafe {
            (
                add23_valid_slice(driver_ptr, driver_len, ADD23_MAX_SEG_LEN),
                add23_valid_slice(index_ptr, index_len, ADD23_MAX_SEG_LEN),
                add23_valid_slice(path_ptr, path_len, ADD23_MAX_SEG_LEN),
            )
        }) else {
            return -1;
        };
        let parts: [&[u8]; W306_DIAGNOSE_ROW_SEGMENTS] = [
            b"Database driver",
            driver,
            b"Claim queue index",
            if has_index == 1 { index } else { b"missing" },
            b"Temp path",
            path,
            b"Temp path writable",
            if writable == 1 { b"yes" } else { b"no" },
        ];
        unsafe { w306_write_segments(out_buf, out_cap, out_lens, &parts) }
    }))
    .unwrap_or(-1)
}

/// `AdditionalProcessingDiagnose::formatWarningLine()`: `"[{code}] {message}"`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_additional_diagnose_format_warning(
    code_ptr: *const u8,
    code_len: usize,
    message_ptr: *const u8,
    message_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let (Some(code), Some(message)) = (unsafe {
            (
                add23_valid_slice(code_ptr, code_len, ADD23_MAX_SEG_LEN),
                add23_valid_slice(message_ptr, message_len, ADD23_MAX_SEG_LEN),
            )
        }) else {
            return -1;
        };
        unsafe { add23_write_slices(out_buf, out_cap, &[b"[", code, b"] ", message], 1) }
    }))
    .unwrap_or(-1)
}

/// `ProcessAdditionalGuid::validateIdentifierInput()`. Writes `[valid, mode, id, exit_code]`
/// (`mode`: 0 none, 1 id, 2 guid) to `out_scalars` and the trimmed GUID (guid mode) or the error
/// message (invalid) to `out_buf`. Returns the written byte length or a negative error.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_process_additional_validate_identifier(
    has_guid: u8,
    guid_ptr: *const u8,
    guid_len: usize,
    has_id: u8,
    id_ptr: *const u8,
    id_len: usize,
    out_scalars: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if has_guid > 1 || has_id > 1 || !w306_valid_i64_out(out_scalars, W306_IDENTIFIER_SCALARS) {
            return -1;
        }
        let (Some(guid_raw), Some(id_raw)) = (unsafe {
            (
                add23_valid_slice(guid_ptr, guid_len, ADD23_MAX_SEG_LEN),
                add23_valid_slice(id_ptr, id_len, ADD23_MAX_SEG_LEN),
            )
        }) else {
            return -1;
        };
        let guid: &[u8] = if has_guid == 1 {
            w306_php_trim(guid_raw)
        } else {
            b""
        };
        let id: &[u8] = if has_id == 1 { id_raw } else { b"" };

        let (scalars, text): ([i64; W306_IDENTIFIER_SCALARS], &[u8]) =
            if guid.is_empty() && id.is_empty() {
                ([0, 0, 0, 1], W306_ERR_MISSING_IDENTIFIER)
            } else if !guid.is_empty() && !id.is_empty() {
                ([0, 0, 0, 4], W306_ERR_CONFLICTING_IDENTIFIERS)
            } else if !id.is_empty() {
                if w306_ctype_digit(id) {
                    ([1, 1, w306_digits_to_i64(id), 0], b"")
                } else {
                    ([0, 0, 0, 5], W306_ERR_NON_NUMERIC_ID)
                }
            } else {
                ([1, 2, 0, 0], guid)
            };

        let written = unsafe { add23_write_slices(out_buf, out_cap, &[text], 1) };
        if written < 0 {
            return written;
        }
        for (index, value) in scalars.iter().enumerate() {
            unsafe {
                *out_scalars.add(index) = *value;
            }
        }
        written
    }))
    .unwrap_or(-1)
}

/// `ProcessAdditionalGuid::buildResetFlagsPayload()` integer flags in key order:
/// `[passwordstatus, haspreview, jpgstatus, videostatus, nfostatus]` (`archive_retry_at` is null).
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_process_additional_reset_flags(out_scalars: *mut i64) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !w306_valid_i64_out(out_scalars, W306_RESET_FLAG_SCALARS) {
            return -1;
        }
        for (index, value) in [-1i64, -1, 0, 0, -1].iter().enumerate() {
            unsafe {
                *out_scalars.add(index) = *value;
            }
        }
        W306_RESET_FLAG_SCALARS as i32
    }))
    .unwrap_or(-1)
}

/// `PostProcessGuid::isValidHexChar()`: 1 for a single `a-f`/`0-9` byte, 0 otherwise, -1 invalid.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_postprocess_guid_is_valid_hex_char(
    value_ptr: *const u8,
    value_len: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(value) = (unsafe { add23_valid_slice(value_ptr, value_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        i32::from(value.len() == 1 && W306_HEX_CHARS.contains(&value[0]))
    }))
    .unwrap_or(-1)
}

/// `PostProcessGuid::isValidPostProcessType()`: 1 for a supported type, 0 otherwise, -1 invalid.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_postprocess_guid_is_valid_type(
    value_ptr: *const u8,
    value_len: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(value) = (unsafe { add23_valid_slice(value_ptr, value_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        i32::from(W306_POSTPROCESS_TYPES.contains(&value))
    }))
    .unwrap_or(-1)
}

/// `PostProcessGuid::computeEffectiveBatchTimeout()`: `max(1, $childTimeout - 30)`.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_postprocess_guid_effective_batch_timeout(child_timeout: i64) -> i64 {
    child_timeout.saturating_sub(30).max(1)
}

/// `PostProcessGuid::normalizeWorkerReleaseIds()`: positive IDs, first occurrence order. Returns
/// the number of IDs written, `-(needed)` when `out_cap` is too small, or `-1` on invalid input.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_postprocess_guid_normalize_release_ids(
    ids_ptr: *const i64,
    ids_count: usize,
    out_ids: *mut i64,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(ids) = (unsafe { w306_valid_i64_in(ids_ptr, ids_count) }) else {
            return -1;
        };
        if !w306_valid_i64_out(out_ids, out_cap) {
            return -1;
        }
        let mut seen = std::collections::HashSet::with_capacity(ids.len());
        let normalized: Vec<i64> = ids
            .iter()
            .copied()
            .filter(|&id| id > 0 && seen.insert(id))
            .collect();
        if normalized.len() > out_cap {
            return -(normalized.len() as isize);
        }
        for (index, id) in normalized.iter().enumerate() {
            unsafe {
                *out_ids.add(index) = *id;
            }
        }
        normalized.len() as isize
    }))
    .unwrap_or(-1)
}

/// `FetchSamples::parseCategoryIdsOption()`: split the trimmed option on `[\s,]+`, keep trimmed
/// `ctype_digit` tokens as integers, first occurrence order. Returns the number of IDs written,
/// `-(needed)` when `out_cap` is too small, or `-1` on invalid input.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_fetch_samples_parse_category_ids(
    has_option: u8,
    option_ptr: *const u8,
    option_len: usize,
    out_ids: *mut i64,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if has_option > 1 || !w306_valid_i64_out(out_ids, out_cap) {
            return -1;
        }
        let Some(option) =
            (unsafe { add23_valid_slice(option_ptr, option_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        if has_option == 0 {
            return 0;
        }
        let mut seen = std::collections::HashSet::new();
        let ids: Vec<i64> = w306_php_trim(option)
            .split(|&byte| matches!(byte, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r' | b','))
            .filter(|token| !token.is_empty())
            .map(w306_php_trim)
            .filter(|token| w306_ctype_digit(token))
            .map(w306_digits_to_i64)
            .filter(|&id| seen.insert(id))
            .collect();
        if ids.len() > out_cap {
            return -(ids.len() as isize);
        }
        for (index, id) in ids.iter().enumerate() {
            unsafe {
                *out_ids.add(index) = *id;
            }
        }
        ids.len() as isize
    }))
    .unwrap_or(-1)
}

/// `FetchSamples::computeEffectiveTotal()`: `$limit > 0 ? min($limit, $totalAll) : $totalAll`.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_fetch_samples_effective_total(limit: i64, total_all: i64) -> i64 {
    if limit > 0 {
        limit.min(total_all)
    } else {
        total_all
    }
}

/// `FetchSamples::formatCompletionSummary()`: `"Processing complete. Success: {s}, Failed: {f}."`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_fetch_samples_format_completion_summary(
    succeeded: i64,
    failed: i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let succeeded = succeeded.to_string();
        let failed = failed.to_string();
        unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[
                    b"Processing complete. Success: ",
                    succeeded.as_bytes(),
                    b", Failed: ",
                    failed.as_bytes(),
                    b".",
                ],
                1,
            )
        }
    }))
    .unwrap_or(-1)
}

/// `CheckServiceHealth::isValidAppUrl()`: `rtrim($url, '/')` is non-empty and starts with `http`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_check_service_health_is_valid_app_url(
    url_ptr: *const u8,
    url_len: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(url) = (unsafe { add23_valid_slice(url_ptr, url_len, ADD23_MAX_SEG_LEN) }) else {
            return -1;
        };
        let mut end = url.len();
        while end > 0 && url[end - 1] == b'/' {
            end -= 1;
        }
        let trimmed = &url[..end];
        i32::from(!trimmed.is_empty() && trimmed.starts_with(b"http"))
    }))
    .unwrap_or(-1)
}

/// `CheckServiceHealth::projectHealthResultRow()`: `[name, TYPE, status|—, {ms}ms|—, Healthy|reason]`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_check_service_health_result_row(
    name_ptr: *const u8,
    name_len: usize,
    type_ptr: *const u8,
    type_len: usize,
    status_code: i64,
    response_time_ms: i64,
    ok: u8,
    reason_ptr: *const u8,
    reason_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
    out_lens: *mut i64,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if ok > 1 {
            return -1;
        }
        let (Some(name), Some(check_type), Some(reason)) = (unsafe {
            (
                add23_valid_slice(name_ptr, name_len, ADD23_MAX_SEG_LEN),
                add23_valid_slice(type_ptr, type_len, ADD23_MAX_SEG_LEN),
                add23_valid_slice(reason_ptr, reason_len, ADD23_MAX_SEG_LEN),
            )
        }) else {
            return -1;
        };
        let upper_type = check_type.to_ascii_uppercase();
        let status = if status_code != 0 {
            status_code.to_string().into_bytes()
        } else {
            "—".as_bytes().to_vec()
        };
        let response = if response_time_ms != 0 {
            format!("{response_time_ms}ms").into_bytes()
        } else {
            "—".as_bytes().to_vec()
        };
        let parts: [&[u8]; W306_HEALTH_ROW_SEGMENTS] = [
            name,
            &upper_type,
            &status,
            &response,
            if ok == 1 { b"Healthy" } else { reason },
        ];
        unsafe { w306_write_segments(out_buf, out_cap, out_lens, &parts) }
    }))
    .unwrap_or(-1)
}

/// `CheckServiceHealth::projectServiceSummaryRow()`:
/// `[name, label, number_format(uptime, 2).'%', lastChecked ?? 'never']`. Returns `-2` for uptime
/// values whose `number_format()` result is left to PHP (non-finite, large, near a rounding tie).
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_check_service_health_summary_row(
    name_ptr: *const u8,
    name_len: usize,
    label_ptr: *const u8,
    label_len: usize,
    uptime_percentage: f64,
    has_last_checked: u8,
    checked_ptr: *const u8,
    checked_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
    out_lens: *mut i64,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if has_last_checked > 1 {
            return -1;
        }
        let (Some(name), Some(label), Some(checked)) = (unsafe {
            (
                add23_valid_slice(name_ptr, name_len, ADD23_MAX_SEG_LEN),
                add23_valid_slice(label_ptr, label_len, ADD23_MAX_SEG_LEN),
                add23_valid_slice(checked_ptr, checked_len, ADD23_MAX_SEG_LEN),
            )
        }) else {
            return -1;
        };
        let Some(mut uptime) = w306_number_format_2(uptime_percentage) else {
            return -2;
        };
        uptime.push(b'%');
        let parts: [&[u8]; W306_SUMMARY_ROW_SEGMENTS] = [
            name,
            label,
            &uptime,
            if has_last_checked == 1 {
                checked
            } else {
                b"never"
            },
        ];
        unsafe { w306_write_segments(out_buf, out_cap, out_lens, &parts) }
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave30_additional_postprocess_health_commands_tests {
    use super::*;

    fn segments(buf: &[u8], lens: &[i64]) -> Vec<Vec<u8>> {
        let mut offset = 0usize;
        lens.iter()
            .map(|&len| {
                let part = buf[offset..offset + len as usize].to_vec();
                offset += len as usize;
                part
            })
            .collect()
    }

    #[test]
    fn diagnose_rows_and_warning_match_php_contracts() {
        let mut buf = [0u8; 256];
        let mut lens = [0i64; 8];
        let written = unsafe {
            nntmux_additional_diagnose_backlog_rows(
                12,
                0,
                -3,
                i64::MAX,
                buf.as_mut_ptr(),
                buf.len(),
                lens.as_mut_ptr(),
            )
        };
        assert!(written > 0);
        let parts = segments(&buf, &lens);
        assert_eq!(parts[0], b"Total");
        assert_eq!(parts[1], b"12");
        assert_eq!(parts[5], b"-3");
        assert_eq!(parts[7], b"9223372036854775807");

        let written = unsafe {
            nntmux_additional_diagnose_preflight_rows(
                b"mysql".as_ptr(),
                5,
                0,
                b"".as_ptr(),
                0,
                b"/tmp/a\0b".as_ptr(),
                8,
                0,
                buf.as_mut_ptr(),
                buf.len(),
                lens.as_mut_ptr(),
            )
        };
        assert!(written > 0);
        let parts = segments(&buf, &lens);
        assert_eq!(parts[1], b"mysql");
        assert_eq!(parts[3], b"missing");
        assert_eq!(parts[5], b"/tmp/a\0b");
        assert_eq!(parts[7], b"no");

        let written = unsafe {
            nntmux_additional_diagnose_format_warning(
                b"stale".as_ptr(),
                5,
                b"msg".as_ptr(),
                3,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(&buf[..written as usize], b"[stale] msg");
    }

    #[test]
    fn identifier_validation_matches_php_exit_codes() {
        let mut buf = [0u8; 128];
        let mut scalars = [9i64; 4];
        let run =
            |guid: Option<&[u8]>, id: Option<&[u8]>, buf: &mut [u8], scalars: &mut [i64; 4]| {
                let g = guid.unwrap_or(b"");
                let i = id.unwrap_or(b"");
                let written = unsafe {
                    nntmux_process_additional_validate_identifier(
                        u8::from(guid.is_some()),
                        g.as_ptr(),
                        g.len(),
                        u8::from(id.is_some()),
                        i.as_ptr(),
                        i.len(),
                        scalars.as_mut_ptr(),
                        buf.as_mut_ptr(),
                        buf.len(),
                    )
                };
                buf[..written as usize].to_vec()
            };
        assert_eq!(
            run(None, None, &mut buf, &mut scalars),
            W306_ERR_MISSING_IDENTIFIER
        );
        assert_eq!(scalars, [0, 0, 0, 1]);
        assert_eq!(
            run(Some(b" \0 "), Some(b""), &mut buf, &mut scalars),
            W306_ERR_MISSING_IDENTIFIER
        );
        assert_eq!(
            run(Some(b"abc"), Some(b"1"), &mut buf, &mut scalars),
            W306_ERR_CONFLICTING_IDENTIFIERS
        );
        assert_eq!(scalars[3], 4);
        assert_eq!(
            run(None, Some(b"-1"), &mut buf, &mut scalars),
            W306_ERR_NON_NUMERIC_ID
        );
        assert_eq!(scalars[3], 5);
        assert_eq!(
            run(None, Some(b"99999999999999999999"), &mut buf, &mut scalars),
            b""
        );
        assert_eq!(scalars, [1, 1, i64::MAX, 0]);
        assert_eq!(run(Some(b"\tabc\n"), None, &mut buf, &mut scalars), b"abc");
        assert_eq!(scalars, [1, 2, 0, 0]);

        let mut flags = [0i64; 5];
        assert_eq!(
            unsafe { nntmux_process_additional_reset_flags(flags.as_mut_ptr()) },
            5
        );
        assert_eq!(flags, [-1, -1, 0, 0, -1]);
    }

    #[test]
    fn postprocess_guid_helpers_match_php_contracts() {
        for (value, expected) in [
            (&b"a"[..], 1),
            (b"f", 1),
            (b"9", 1),
            (b"g", 0),
            (b"A", 0),
            (b"ab", 0),
            (b"", 0),
        ] {
            assert_eq!(
                unsafe { nntmux_postprocess_guid_is_valid_hex_char(value.as_ptr(), value.len()) },
                expected
            );
        }
        for (value, expected) in [
            (&b"games"[..], 1),
            (b"additional", 1),
            (b"Games", 0),
            (b"tv ", 0),
            (b"", 0),
        ] {
            assert_eq!(
                unsafe { nntmux_postprocess_guid_is_valid_type(value.as_ptr(), value.len()) },
                expected
            );
        }
        assert_eq!(nntmux_postprocess_guid_effective_batch_timeout(1800), 1770);
        assert_eq!(nntmux_postprocess_guid_effective_batch_timeout(31), 1);
        assert_eq!(nntmux_postprocess_guid_effective_batch_timeout(i64::MIN), 1);
        assert_eq!(
            nntmux_postprocess_guid_effective_batch_timeout(i64::MAX),
            i64::MAX - 30
        );

        let ids = [5i64, 0, 3, 5, -2, 3, 7];
        let mut out = [0i64; 7];
        let count = unsafe {
            nntmux_postprocess_guid_normalize_release_ids(
                ids.as_ptr(),
                ids.len(),
                out.as_mut_ptr(),
                out.len(),
            )
        };
        assert_eq!(&out[..count as usize], &[5, 3, 7]);
    }

    #[test]
    fn fetch_samples_and_health_helpers_match_php_contracts() {
        let option = b" 2010, 2020\x0b2010\x0c\x00 abc 2030x 0007 ";
        let mut out = [0i64; 64];
        let count = unsafe {
            nntmux_fetch_samples_parse_category_ids(
                1,
                option.as_ptr(),
                option.len(),
                out.as_mut_ptr(),
                out.len(),
            )
        };
        assert_eq!(&out[..count as usize], &[2010, 2020, 7]);
        assert_eq!(nntmux_fetch_samples_effective_total(0, 10), 10);
        assert_eq!(nntmux_fetch_samples_effective_total(5, 10), 5);
        assert_eq!(nntmux_fetch_samples_effective_total(-5, 10), 10);

        let mut buf = [0u8; 256];
        let written = unsafe {
            nntmux_fetch_samples_format_completion_summary(3, -1, buf.as_mut_ptr(), buf.len())
        };
        assert_eq!(
            &buf[..written as usize],
            b"Processing complete. Success: 3, Failed: -1."
        );

        for (url, expected) in [
            (&b"https://x/"[..], 1),
            (b"http", 1),
            (b"///", 0),
            (b"ftp://x", 0),
            (b"", 0),
        ] {
            assert_eq!(
                unsafe { nntmux_check_service_health_is_valid_app_url(url.as_ptr(), url.len()) },
                expected
            );
        }

        let mut lens = [0i64; 5];
        let written = unsafe {
            nntmux_check_service_health_result_row(
                b"API".as_ptr(),
                3,
                b"http".as_ptr(),
                4,
                0,
                125,
                0,
                b"Down".as_ptr(),
                4,
                buf.as_mut_ptr(),
                buf.len(),
                lens.as_mut_ptr(),
            )
        };
        assert!(written > 0);
        let parts = segments(&buf, &lens);
        assert_eq!(parts[1], b"HTTP");
        assert_eq!(parts[2], "—".as_bytes());
        assert_eq!(parts[3], b"125ms");
        assert_eq!(parts[4], b"Down");

        let mut lens = [0i64; 4];
        let written = unsafe {
            nntmux_check_service_health_summary_row(
                b"API".as_ptr(),
                3,
                b"Operational".as_ptr(),
                11,
                1234.5678,
                0,
                b"".as_ptr(),
                0,
                buf.as_mut_ptr(),
                buf.len(),
                lens.as_mut_ptr(),
            )
        };
        assert!(written > 0);
        let parts = segments(&buf, &lens);
        assert_eq!(parts[2], b"1,234.57%");
        assert_eq!(parts[3], b"never");
        for tie in [0.125, 1.005, f64::NAN, f64::INFINITY, 1e13] {
            assert_eq!(
                unsafe {
                    nntmux_check_service_health_summary_row(
                        b"".as_ptr(),
                        0,
                        b"".as_ptr(),
                        0,
                        tie,
                        0,
                        b"".as_ptr(),
                        0,
                        buf.as_mut_ptr(),
                        buf.len(),
                        lens.as_mut_ptr(),
                    )
                },
                -2
            );
        }
        assert_eq!(w306_number_format_2(-0.004).unwrap(), b"0.00");
        assert_eq!(w306_number_format_2(-12.346).unwrap(), b"-12.35");
    }

    #[test]
    fn entrypoints_reject_null_unaligned_and_undersized_buffers() {
        let mut raw = [0xA5u8; 64];
        let unaligned = unsafe { raw.as_mut_ptr().add(1).cast::<i64>() };
        let mut buf = [0u8; 64];
        assert_eq!(
            unsafe {
                nntmux_additional_diagnose_backlog_rows(1, 2, 3, 4, buf.as_mut_ptr(), 64, unaligned)
            },
            -1
        );
        assert_eq!(
            unsafe { nntmux_process_additional_reset_flags(unaligned) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_postprocess_guid_is_valid_hex_char(std::ptr::null(), 0) },
            -1
        );
        let mut lens = [0i64; 8];
        let mut small = [0x5Au8; 4];
        assert!(
            unsafe {
                nntmux_additional_diagnose_backlog_rows(
                    1,
                    2,
                    3,
                    4,
                    small.as_mut_ptr(),
                    4,
                    lens.as_mut_ptr(),
                )
            } < -4
        );
        assert_eq!(small, [0x5Au8; 4]);
        let ids = [1i64, 2, 3];
        let mut out = [0i64; 2];
        assert_eq!(
            unsafe {
                nntmux_postprocess_guid_normalize_release_ids(ids.as_ptr(), 3, out.as_mut_ptr(), 2)
            },
            -3
        );
    }
}
// ---------------------------------------------------------------------------
// Wave 31 Slice 31.6: InstallMonitoring, CollectSystemMetrics,
// ExportMonitoringMetrics, DeployInitialize, and TmuxAttach command helpers
// ---------------------------------------------------------------------------

const W316_WEB_SERVERS: [&[u8]; 2] = [b"nginx", b"apache"];
const W316_SUPPORTED_UBUNTU: [&[u8]; 2] = [b"22.04", b"24.04"];
const W316_SEARCH_DRIVERS: [&[u8]; 2] = [b"manticore", b"elasticsearch"];
const W316_APP_KEY_BASE64_PREFIX: &[u8] = b"base64:";

const W316_EXPORT_MODE_PRINT: i32 = 0;
const W316_EXPORT_MODE_DISABLED: i32 = 1;
const W316_EXPORT_MODE_PUSH: i32 = 2;
const W316_EXPORT_MODE_PUSH_WITH_ALARM: i32 = 3;

const W316_SEARCH_MANTICORE: i32 = 0;
const W316_SEARCH_MANTICORE_DROP: i32 = 1;
const W316_SEARCH_ES_CREATE_MISSING: i32 = 2;
const W316_SEARCH_ES_RECREATE: i32 = 3;

/// Runs `body` with a validated input slice, returning `-1` for invalid pointers or panics.
#[inline]
unsafe fn w316_with_slice<T>(
    ptr: *const u8,
    len: usize,
    invalid: T,
    body: impl FnOnce(&[u8]) -> T,
) -> T {
    match unsafe { add23_valid_slice(ptr, len, ADD23_MAX_SEG_LEN) } {
        Some(bytes) => body(bytes),
        None => invalid,
    }
}

#[inline]
unsafe fn w316_membership(ptr: *const u8, len: usize, set: &[&[u8]]) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        w316_with_slice(ptr, len, -1, |value| i32::from(set.contains(&value)))
    }))
    .unwrap_or(-1)
}

#[inline]
unsafe fn w316_prefixed_message(
    prefix: &[u8],
    value_ptr: *const u8,
    value_len: usize,
    suffix: &[u8],
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        w316_with_slice(value_ptr, value_len, -1, |value| {
            add23_write_slices(out_buf, out_cap, &[prefix, value, suffix], 1)
        })
    }))
    .unwrap_or(-1)
}

/// PHP's strict `base64_decode($value, true)`: skips `\t \n \r` and spaces, fails on any other
/// non-alphabet byte, data after padding, a dangling single sextet, or a wrong padding length.
/// Trailing bits of a partial group are discarded without validation, as in PHP.
fn w316_php_base64_decode_strict(input: &[u8]) -> Option<Vec<u8>> {
    let mut out = Vec::with_capacity(input.len() / 4 * 3 + 3);
    let mut padding = 0usize;
    let mut sextets = 0usize;
    let mut pending = 0u8;
    for &byte in input {
        if byte == b'=' {
            padding += 1;
            continue;
        }
        let value = match byte {
            b'A'..=b'Z' => byte - b'A',
            b'a'..=b'z' => byte - b'a' + 26,
            b'0'..=b'9' => byte - b'0' + 52,
            b'+' => 62,
            b'/' => 63,
            b'\t' | b'\n' | b'\r' | b' ' => continue,
            _ => return None,
        };
        if padding > 0 {
            return None;
        }
        match sextets % 4 {
            0 => pending = value << 2,
            1 => {
                out.push(pending | (value >> 4));
                pending = (value & 0x0f) << 4;
            }
            2 => {
                out.push(pending | (value >> 2));
                pending = (value & 0x03) << 6;
            }
            _ => out.push(pending | value),
        }
        sextets += 1;
    }
    if sextets % 4 == 1 {
        return None;
    }
    if padding > 0 && (padding > 2 || !(sextets + padding).is_multiple_of(4)) {
        return None;
    }
    Some(out)
}

/// `InstallMonitoring::isValidWebServer()`: `in_array($webServer, ['nginx', 'apache'], true)`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_install_monitoring_is_valid_web_server(
    value_ptr: *const u8,
    value_len: usize,
) -> i32 {
    unsafe { w316_membership(value_ptr, value_len, &W316_WEB_SERVERS) }
}

/// `InstallMonitoring::isSupportedUbuntuVersion()`: `in_array($version, SUPPORTED_UBUNTU, true)`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_install_monitoring_is_supported_ubuntu_version(
    value_ptr: *const u8,
    value_len: usize,
) -> i32 {
    unsafe { w316_membership(value_ptr, value_len, &W316_SUPPORTED_UBUNTU) }
}

/// `InstallMonitoring::formatProjectRelativePath()`: strips `rtrim($basePath, '/').'/'` from the
/// start of `$path` when present, otherwise returns `$path` unchanged.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_install_monitoring_project_relative_path(
    base_ptr: *const u8,
    base_len: usize,
    path_ptr: *const u8,
    path_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let (Some(base), Some(path)) = (unsafe {
            (
                add23_valid_slice(base_ptr, base_len, ADD23_MAX_SEG_LEN),
                add23_valid_slice(path_ptr, path_len, ADD23_MAX_SEG_LEN),
            )
        }) else {
            return -1;
        };
        let mut end = base.len();
        while end > 0 && base[end - 1] == b'/' {
            end -= 1;
        }
        let trimmed = &base[..end];
        let relative = if path.len() > trimmed.len()
            && path.starts_with(trimmed)
            && path[trimmed.len()] == b'/'
        {
            &path[trimmed.len() + 1..]
        } else {
            path
        };
        unsafe { add23_write_slices(out_buf, out_cap, &[relative], 1) }
    }))
    .unwrap_or(-1)
}

/// `CollectSystemMetrics::formatDeletedMetricsMessage()`: `"Deleted {$deleted} old metric records."`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_collect_system_metrics_format_deleted_message(
    deleted: i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let deleted = deleted.to_string();
        unsafe {
            add23_write_slices(
                out_buf,
                out_cap,
                &[b"Deleted ", deleted.as_bytes(), b" old metric records."],
                1,
            )
        }
    }))
    .unwrap_or(-1)
}

/// `CollectSystemMetrics::formatErrorMessage()`: `"Failed to collect system metrics: {$message}"`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_collect_system_metrics_format_error_message(
    message_ptr: *const u8,
    message_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    unsafe {
        w316_prefixed_message(
            b"Failed to collect system metrics: ",
            message_ptr,
            message_len,
            b"",
            out_buf,
            out_cap,
        )
    }
}

/// `ExportMonitoringMetrics::resolveExportMode()`: `0` print, `1` disabled (monitoring off or a
/// PHP-`trim()`-blank Pushgateway URL), `2` push, `3` push with the PCNTL alarm; `-1` on error.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_export_monitoring_metrics_resolve_export_mode(
    print_only: u8,
    enabled: u8,
    url_ptr: *const u8,
    url_len: usize,
    has_pcntl: u8,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        w316_with_slice(url_ptr, url_len, -1, |url| {
            if print_only != 0 {
                W316_EXPORT_MODE_PRINT
            } else if enabled == 0 || w306_php_trim(url).is_empty() {
                W316_EXPORT_MODE_DISABLED
            } else if has_pcntl != 0 {
                W316_EXPORT_MODE_PUSH_WITH_ALARM
            } else {
                W316_EXPORT_MODE_PUSH
            }
        })
    }))
    .unwrap_or(-1)
}

/// `ExportMonitoringMetrics::formatExportFailureMessage()`: `"Monitoring metrics export failed: {$message}"`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_export_monitoring_metrics_format_failure_message(
    message_ptr: *const u8,
    message_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    unsafe {
        w316_prefixed_message(
            b"Monitoring metrics export failed: ",
            message_ptr,
            message_len,
            b"",
            out_buf,
            out_cap,
        )
    }
}

/// `DeployInitialize::isValidSearchDriver()`: `in_array($driver, ['manticore', 'elasticsearch'], true)`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_deploy_initialize_is_valid_search_driver(
    driver_ptr: *const u8,
    driver_len: usize,
) -> i32 {
    unsafe { w316_membership(driver_ptr, driver_len, &W316_SEARCH_DRIVERS) }
}

/// `DeployInitialize::decodeAppKeyValue()`: strict base64 decoding of a `base64:` key (an invalid
/// payload yields an empty string, like `(string) false`); other keys are returned unchanged.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_deploy_initialize_decode_app_key(
    key_ptr: *const u8,
    key_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        w316_with_slice(key_ptr, key_len, -1, |key| {
            match key.strip_prefix(W316_APP_KEY_BASE64_PREFIX) {
                Some(payload) => {
                    let decoded = w316_php_base64_decode_strict(payload).unwrap_or_default();
                    add23_write_slices(out_buf, out_cap, &[&decoded], 1)
                }
                None => add23_write_slices(out_buf, out_cap, &[key], 1),
            }
        })
    }))
    .unwrap_or(-1)
}

/// `DeployInitialize::resolveSearchIndexCommand()`: `0` manticore, `1` manticore with `--drop`,
/// `2` Elasticsearch with `--create-missing`, `3` Elasticsearch recreate; `-1` on error.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_deploy_initialize_search_index_command(
    driver_ptr: *const u8,
    driver_len: usize,
    recreate: u8,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| unsafe {
        w316_with_slice(driver_ptr, driver_len, -1, |driver| {
            match (driver == b"elasticsearch", recreate != 0) {
                (true, false) => W316_SEARCH_ES_CREATE_MISSING,
                (true, true) => W316_SEARCH_ES_RECREATE,
                (false, false) => W316_SEARCH_MANTICORE,
                (false, true) => W316_SEARCH_MANTICORE_DROP,
            }
        })
    }))
    .unwrap_or(-1)
}

/// `TmuxAttach::resolveSessionName()`: `$optionSession ?? $defaultSession`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_attach_resolve_session_name(
    has_option: u8,
    option_ptr: *const u8,
    option_len: usize,
    default_ptr: *const u8,
    default_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let (ptr, len) = if has_option != 0 {
            (option_ptr, option_len)
        } else {
            (default_ptr, default_len)
        };
        unsafe {
            w316_with_slice(ptr, len, -1, |name| {
                add23_write_slices(out_buf, out_cap, &[name], 1)
            })
        }
    }))
    .unwrap_or(-1)
}

/// `TmuxAttach::formatAttachingMessage()`: `"Attaching to tmux session: {$sessionName}"`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_attach_format_attaching_message(
    name_ptr: *const u8,
    name_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    unsafe {
        w316_prefixed_message(
            b"Attaching to tmux session: ",
            name_ptr,
            name_len,
            b"",
            out_buf,
            out_cap,
        )
    }
}

/// `TmuxAttach::formatSessionNotFoundMessage()`: `"Tmux session \"{$sessionName}\" does not exist."`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_attach_format_session_not_found_message(
    name_ptr: *const u8,
    name_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    unsafe {
        w316_prefixed_message(
            b"Tmux session \"",
            name_ptr,
            name_len,
            b"\" does not exist.",
            out_buf,
            out_cap,
        )
    }
}

#[cfg(test)]
mod wave31_monitoring_metrics_deploy_tmux_attach_commands_tests {
    use super::*;

    fn call_out(f: impl FnOnce(*mut u8, usize) -> isize) -> Option<Vec<u8>> {
        let mut buf = vec![0u8; 512];
        let written = f(buf.as_mut_ptr(), buf.len());
        (written >= 0).then(|| buf[..written as usize].to_vec())
    }

    #[test]
    fn install_monitoring_helpers_match_php_contracts() {
        for (value, web, ubuntu) in [
            (&b"nginx"[..], 1, 0),
            (b"apache", 1, 0),
            (b"Nginx", 0, 0),
            (b"nginx ", 0, 0),
            (b"nginx\0", 0, 0),
            (b"22.04", 0, 1),
            (b"24.04", 0, 1),
            (b"20.04", 0, 0),
            (b"", 0, 0),
        ] {
            assert_eq!(
                unsafe {
                    nntmux_install_monitoring_is_valid_web_server(value.as_ptr(), value.len())
                },
                web
            );
            assert_eq!(
                unsafe {
                    nntmux_install_monitoring_is_supported_ubuntu_version(
                        value.as_ptr(),
                        value.len(),
                    )
                },
                ubuntu
            );
        }
        for (base, path, expected) in [
            (&b"/app"[..], &b"/app/storage/key"[..], &b"storage/key"[..]),
            (b"/app///", b"/app/storage", b"storage"),
            (b"/app", b"/app/", b""),
            (b"/app", b"/app", b"/app"),
            (b"/app", b"/application/x", b"/application/x"),
            (b"", b"/abs", b"abs"),
            (b"/", b"rel", b"rel"),
            ("/é\0".as_bytes(), "/é\0/ü".as_bytes(), "ü".as_bytes()),
        ] {
            let got = call_out(|out, cap| unsafe {
                nntmux_install_monitoring_project_relative_path(
                    base.as_ptr(),
                    base.len(),
                    path.as_ptr(),
                    path.len(),
                    out,
                    cap,
                )
            });
            assert_eq!(got.as_deref(), Some(expected));
        }
    }

    #[test]
    fn metrics_and_export_helpers_match_php_contracts() {
        for (deleted, expected) in [
            (0i64, &b"Deleted 0 old metric records."[..]),
            (-3, b"Deleted -3 old metric records."),
            (
                i64::MIN,
                b"Deleted -9223372036854775808 old metric records.",
            ),
        ] {
            let got = call_out(|out, cap| unsafe {
                nntmux_collect_system_metrics_format_deleted_message(deleted, out, cap)
            });
            assert_eq!(got.as_deref(), Some(expected));
        }
        let message = "é\0x".as_bytes();
        let got = call_out(|out, cap| unsafe {
            nntmux_collect_system_metrics_format_error_message(
                message.as_ptr(),
                message.len(),
                out,
                cap,
            )
        });
        assert_eq!(
            got.as_deref(),
            Some("Failed to collect system metrics: é\0x".as_bytes())
        );
        let got = call_out(|out, cap| unsafe {
            nntmux_export_monitoring_metrics_format_failure_message(b"".as_ptr(), 0, out, cap)
        });
        assert_eq!(
            got.as_deref(),
            Some(&b"Monitoring metrics export failed: "[..])
        );

        for (print, enabled, url, pcntl, expected) in [
            (1u8, 0u8, &b""[..], 0u8, W316_EXPORT_MODE_PRINT),
            (0, 0, b"http://x", 1, W316_EXPORT_MODE_DISABLED),
            (0, 1, b" \t\n\r\0\x0b", 1, W316_EXPORT_MODE_DISABLED),
            (0, 1, b"\x0c", 0, W316_EXPORT_MODE_PUSH),
            (0, 1, b"http://x", 0, W316_EXPORT_MODE_PUSH),
            (0, 1, b"http://x", 1, W316_EXPORT_MODE_PUSH_WITH_ALARM),
        ] {
            assert_eq!(
                unsafe {
                    nntmux_export_monitoring_metrics_resolve_export_mode(
                        print,
                        enabled,
                        url.as_ptr(),
                        url.len(),
                        pcntl,
                    )
                },
                expected
            );
        }
    }

    #[test]
    fn deploy_initialize_helpers_match_php_contracts() {
        for (driver, valid, plain, recreate) in [
            (
                &b"manticore"[..],
                1,
                W316_SEARCH_MANTICORE,
                W316_SEARCH_MANTICORE_DROP,
            ),
            (
                b"elasticsearch",
                1,
                W316_SEARCH_ES_CREATE_MISSING,
                W316_SEARCH_ES_RECREATE,
            ),
            (
                b"Elasticsearch",
                0,
                W316_SEARCH_MANTICORE,
                W316_SEARCH_MANTICORE_DROP,
            ),
            (b"", 0, W316_SEARCH_MANTICORE, W316_SEARCH_MANTICORE_DROP),
        ] {
            assert_eq!(
                unsafe {
                    nntmux_deploy_initialize_is_valid_search_driver(driver.as_ptr(), driver.len())
                },
                valid
            );
            for (flag, expected) in [(0u8, plain), (1u8, recreate)] {
                assert_eq!(
                    unsafe {
                        nntmux_deploy_initialize_search_index_command(
                            driver.as_ptr(),
                            driver.len(),
                            flag,
                        )
                    },
                    expected
                );
            }
        }

        for (key, expected) in [
            (&b"plain-key"[..], &b"plain-key"[..]),
            (b"base64:", b""),
            (b"base64:QUJD", b"ABC"),
            (b"base64:QUI=", b"AB"),
            (b"base64:QQ==", b"A"),
            (b"base64:QQ", b"A"),
            (b"base64:QR==", b"A"),
            (b"base64: Q Q\t=\n=\r", b"A"),
            (b"base64:QQ==QQ", b""),
            (b"base64:QQ=", b""),
            (b"base64:Q", b""),
            (b"base64:QQ===", b""),
            (b"base64:=", b""),
            (b"base64:QQ\x0b", b""),
            (b"base64:QQ-_", b""),
            (b"base64:AA==", b"\0"),
            (b"Base64:QUJD", b"Base64:QUJD"),
        ] {
            let got = call_out(|out, cap| unsafe {
                nntmux_deploy_initialize_decode_app_key(key.as_ptr(), key.len(), out, cap)
            });
            assert_eq!(got.as_deref(), Some(expected), "{key:?}");
        }
    }

    #[test]
    fn tmux_attach_helpers_match_php_contracts() {
        let option = "sé\0s".as_bytes();
        let default = b"nntmux";
        for (has_option, expected) in [(1u8, option), (0u8, &default[..])] {
            let got = call_out(|out, cap| unsafe {
                nntmux_tmux_attach_resolve_session_name(
                    has_option,
                    option.as_ptr(),
                    option.len(),
                    default.as_ptr(),
                    default.len(),
                    out,
                    cap,
                )
            });
            assert_eq!(got.as_deref(), Some(expected));
        }
        let got = call_out(|out, cap| unsafe {
            nntmux_tmux_attach_resolve_session_name(
                1,
                b"".as_ptr(),
                0,
                std::ptr::null(),
                0,
                out,
                cap,
            )
        });
        assert_eq!(got.as_deref(), Some(&b""[..]));
        let got = call_out(|out, cap| unsafe {
            nntmux_tmux_attach_format_attaching_message(option.as_ptr(), option.len(), out, cap)
        });
        assert_eq!(
            got.as_deref(),
            Some("Attaching to tmux session: sé\0s".as_bytes())
        );
        let got = call_out(|out, cap| unsafe {
            nntmux_tmux_attach_format_session_not_found_message(b"a\"b".as_ptr(), 3, out, cap)
        });
        assert_eq!(
            got.as_deref(),
            Some(&b"Tmux session \"a\"b\" does not exist."[..])
        );
    }

    #[test]
    fn entrypoints_reject_null_and_undersized_buffers() {
        assert_eq!(
            unsafe { nntmux_install_monitoring_is_valid_web_server(std::ptr::null(), 0) },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_export_monitoring_metrics_resolve_export_mode(1, 1, std::ptr::null(), 0, 1)
            },
            -1
        );
        assert_eq!(
            unsafe { nntmux_deploy_initialize_search_index_command(std::ptr::null(), 0, 0) },
            -1
        );
        let mut small = [0x5Au8; 4];
        assert_eq!(
            unsafe {
                nntmux_tmux_attach_format_attaching_message(
                    b"x".as_ptr(),
                    1,
                    small.as_mut_ptr(),
                    small.len(),
                )
            },
            -28
        );
        assert_eq!(small, [0x5Au8; 4]);
        assert_eq!(
            unsafe {
                nntmux_collect_system_metrics_format_deleted_message(1, std::ptr::null_mut(), 64)
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_deploy_initialize_decode_app_key(b"k".as_ptr(), 1, std::ptr::null_mut(), 8)
            },
            -1
        );
    }
}

// Wave 32 Slice 32.6: installer/updater command planning. All strings are PHP
// byte strings; no UTF-8 decoding, trimming, or new branch policy is applied.

/// Echo five big-endian u32-length-prefixed database fields after validating
/// the complete frame. The caller retains the original integer/string port type.
/// Returns -1 for invalid input, -needed without writing for insufficient space.
/// # Safety
/// Non-null pointers must name readable/writable allocations of the given sizes.
/// Input and output allocations must not overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_installer_database_config(
    config: *const u8,
    config_len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(bytes) = (unsafe { add23_valid_slice(config, config_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        let mut offset = 0usize;
        for _ in 0..5 {
            let Some(header) = bytes.get(offset..).and_then(|tail| tail.get(..4)) else {
                return -1;
            };
            let length = u32::from_be_bytes(header.try_into().unwrap()) as usize;
            let Some(end) = offset
                .checked_add(4)
                .and_then(|start| start.checked_add(length))
            else {
                return -1;
            };
            if end > bytes.len() {
                return -1;
            }
            offset = end;
        }
        if offset != bytes.len() {
            return -1;
        }
        unsafe { add23_write_slices(out, cap, &[bytes], 1) }
    }))
    .unwrap_or(-1)
}

/// Plan only the named update subcommands. The caller retains skip options,
/// process execution, npm operations, error handling, and maintenance ownership.
/// # Safety
/// `out` must name a writable allocation of `cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_installer_update_sequence(
    maintenance_down: u8,
    seed: u8,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if maintenance_down > 1 || seed > 1 {
            return -1;
        }
        let prefix: &[u8] = if maintenance_down == 0 {
            b"down\n"
        } else {
            b""
        };
        let suffix: &[u8] = if maintenance_down == 0 { b"\nup" } else { b"" };
        let seed_command: &[u8] = if seed == 1 { b"\ndb:seed" } else { b"" };
        unsafe {
            add23_write_slices(
                out,
                cap,
                &[
                    prefix,
                    b"nntmux:git\nnntmux:composer\nnntmux:db",
                    seed_command,
                    suffix,
                ],
                1,
            )
        }
    }))
    .unwrap_or(-1)
}

/// Build the existing install command, preserving command bytes verbatim.
/// # Safety
/// Input/output allocations must be valid for their lengths and not overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_installer_composer_command(
    env: *const u8,
    env_len: usize,
    command: *const u8,
    command_len: usize,
    out: *mut u8,
    cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(env) = (unsafe { add23_valid_slice(env, env_len, ADD23_MAX_SEG_LEN) }) else {
            return -1;
        };
        let Some(command) = (unsafe { add23_valid_slice(command, command_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        let optimization: &[u8] = if env == b"production" {
            b" --optimize-autoloader --classmap-authoritative"
        } else {
            b""
        };
        unsafe {
            add23_write_slices(
                out,
                cap,
                &[
                    command,
                    b" install --no-interaction --no-progress",
                    optimization,
                ],
                1,
            )
        }
    }))
    .unwrap_or(-1)
}

/// Bit 0 means force migration/seeding, bit 1 means seed was requested.
/// # Safety
/// `env` must name a readable allocation of `env_len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_installer_migration_mode(
    env: *const u8,
    env_len: usize,
    seed: u8,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(env) = (unsafe { add23_valid_slice(env, env_len, ADD23_MAX_SEG_LEN) }) else {
            return -1;
        };
        if seed > 1 {
            return -1;
        }
        i32::from(env == b"production") | (i32::from(seed) << 1)
    }))
    .unwrap_or(-1)
}

/// The updater accepts arbitrary nonempty branches; Git validates ref syntax.
/// # Safety
/// `branch` must name a readable allocation of `branch_len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_installer_managed_branch(
    branch: *const u8,
    branch_len: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(branch) = (unsafe { add23_valid_slice(branch, branch_len, ADD23_MAX_SEG_LEN) })
        else {
            return -1;
        };
        i32::from(!branch.is_empty())
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod w326_installer_tests {
    use super::*;

    fn frame(fields: &[&[u8]]) -> Vec<u8> {
        let mut result = Vec::new();
        for field in fields {
            result.extend_from_slice(&(field.len() as u32).to_be_bytes());
            result.extend_from_slice(field);
        }
        result
    }

    #[test]
    fn database_frames_preserve_bytes_and_reject_malformed_input() {
        for fields in [
            [&b"mysql"[..], b"localhost", b"3306", b"nntmux", b"root"],
            [b"", b"\xff\0", b"03306", b" a\n", b""],
        ] {
            let input = frame(&fields);
            let mut out = vec![0x5a; input.len()];
            assert_eq!(
                unsafe {
                    nntmux_installer_database_config(
                        input.as_ptr(),
                        input.len(),
                        out.as_mut_ptr(),
                        out.len(),
                    )
                },
                input.len() as isize
            );
            assert_eq!(out, input);
            out.fill(0x5a);
            assert_eq!(
                unsafe {
                    nntmux_installer_database_config(
                        input.as_ptr(),
                        input.len(),
                        out.as_mut_ptr(),
                        input.len() - 1,
                    )
                },
                -(input.len() as isize)
            );
            assert!(out.iter().all(|&byte| byte == 0x5a));
            for length in 0..input.len() {
                assert_eq!(
                    unsafe {
                        nntmux_installer_database_config(
                            input.as_ptr(),
                            length,
                            out.as_mut_ptr(),
                            out.len(),
                        )
                    },
                    -1
                );
            }
            let mut trailing = input.clone();
            trailing.push(0);
            assert_eq!(
                unsafe {
                    nntmux_installer_database_config(
                        trailing.as_ptr(),
                        trailing.len(),
                        out.as_mut_ptr(),
                        out.len(),
                    )
                },
                -1
            );
        }
        let huge_field = [0xff; 20];
        let mut out = [0x5a; 128];
        assert_eq!(
            unsafe {
                nntmux_installer_database_config(
                    huge_field.as_ptr(),
                    huge_field.len(),
                    out.as_mut_ptr(),
                    out.len(),
                )
            },
            -1
        );
        assert_eq!(out, [0x5a; 128]);
    }

    #[test]
    fn commands_and_modes_match_php() {
        let mut out = [0u8; 256];
        for (env, flags) in [
            (&b"production"[..], 1),
            (b"Production", 0),
            (b"production\0", 0),
            (b"\xff", 0),
            (b"", 0),
        ] {
            for seed in 0..=1 {
                assert_eq!(
                    unsafe { nntmux_installer_migration_mode(env.as_ptr(), env.len(), seed) },
                    flags | (i32::from(seed) << 1)
                );
            }
            let command = b"php composer.phar\0";
            let written = unsafe {
                nntmux_installer_composer_command(
                    env.as_ptr(),
                    env.len(),
                    command.as_ptr(),
                    command.len(),
                    out.as_mut_ptr(),
                    out.len(),
                )
            };
            let mut expected = command.to_vec();
            expected.extend_from_slice(b" install --no-interaction --no-progress");
            if flags == 1 {
                expected.extend_from_slice(b" --optimize-autoloader --classmap-authoritative");
            }
            assert_eq!(&out[..written as usize], expected);
        }
        for down in 0..=1 {
            for seed in 0..=1 {
                let written = unsafe {
                    nntmux_installer_update_sequence(down, seed, out.as_mut_ptr(), out.len())
                };
                let mut expected = Vec::new();
                if down == 0 {
                    expected.extend_from_slice(b"down\n");
                }
                expected.extend_from_slice(b"nntmux:git\nnntmux:composer\nnntmux:db");
                if seed == 1 {
                    expected.extend_from_slice(b"\ndb:seed");
                }
                if down == 0 {
                    expected.extend_from_slice(b"\nup");
                }
                assert_eq!(&out[..written as usize], expected);
            }
        }
        for branch in [&b""[..], b"master", b"feature/x", b" ", b"\xff\0"] {
            assert_eq!(
                unsafe { nntmux_installer_managed_branch(branch.as_ptr(), branch.len()) },
                i32::from(!branch.is_empty())
            );
        }
    }

    #[test]
    fn invalid_ffi_arguments_and_short_buffers_do_not_write() {
        let null = std::ptr::null();
        let null_out = std::ptr::null_mut();
        let empty = b"".as_ptr();
        let mut out = [0x5a; 128];
        assert_eq!(
            unsafe { nntmux_installer_database_config(null, 0, out.as_mut_ptr(), out.len()) },
            -1
        );
        let valid = frame(&[b"", b"", b"", b"", b""]);
        assert_eq!(
            unsafe { nntmux_installer_database_config(valid.as_ptr(), valid.len(), null_out, 128) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_installer_update_sequence(0, 0, null_out, 128) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_installer_update_sequence(2, 0, out.as_mut_ptr(), out.len()) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_installer_update_sequence(0, 2, out.as_mut_ptr(), out.len()) },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_installer_composer_command(null, 0, empty, 0, out.as_mut_ptr(), out.len())
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_installer_composer_command(empty, 0, null, 0, out.as_mut_ptr(), out.len())
            },
            -1
        );
        assert_eq!(
            unsafe { nntmux_installer_composer_command(empty, 0, empty, 0, null_out, 0) },
            -1
        );
        assert_eq!(unsafe { nntmux_installer_migration_mode(null, 0, 0) }, -1);
        assert_eq!(unsafe { nntmux_installer_migration_mode(empty, 0, 2) }, -1);
        assert_eq!(unsafe { nntmux_installer_managed_branch(null, 0) }, -1);
        assert_eq!(
            unsafe { nntmux_installer_managed_branch(empty, usize::MAX) },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_installer_database_config(empty, usize::MAX, out.as_mut_ptr(), out.len())
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_installer_composer_command(
                    empty,
                    usize::MAX,
                    empty,
                    0,
                    out.as_mut_ptr(),
                    out.len(),
                )
            },
            -1
        );
        assert_eq!(
            unsafe { nntmux_installer_migration_mode(empty, usize::MAX, 0) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_installer_update_sequence(0, 0, out.as_mut_ptr(), usize::MAX) },
            -1
        );
        assert_eq!(
            unsafe { nntmux_installer_update_sequence(0, 0, out.as_mut_ptr(), 1) },
            -44
        );
        assert_eq!(
            unsafe { nntmux_installer_composer_command(empty, 0, empty, 0, out.as_mut_ptr(), 1) },
            -39
        );
        assert_eq!(out, [0x5a; 128]);
    }
}

// Wave 33 Slice 33.6: verification lock and uptime CPU candidates.
// The frame is a sequence of big-endian u32 lengths and uninterpreted byte strings.
// Operation-specific field counts are strict; output is committed only after capacity checks.
const W336_MAX_INPUT: usize = 64 * 1024 * 1024;

fn w336_fields(mut input: &[u8], count: usize) -> Option<Vec<&[u8]>> {
    let mut fields = Vec::with_capacity(count);
    for _ in 0..count {
        let header: [u8; 4] = input.get(..4)?.try_into().ok()?;
        let length = u32::from_be_bytes(header) as usize;
        input = &input[4..];
        fields.push(input.get(..length)?);
        input = &input[length..];
    }
    input.is_empty().then_some(fields)
}

fn w336_frame(fields: &[&[u8]]) -> Option<Vec<u8>> {
    let mut output = Vec::new();
    for field in fields {
        let length = u32::try_from(field.len()).ok()?;
        output.extend_from_slice(&length.to_be_bytes());
        output.extend_from_slice(field);
    }
    Some(output)
}

fn w336_compute(
    operation: u8,
    input: &[u8],
    first: i64,
    second: i64,
    value: f64,
    flag: u8,
) -> Option<Vec<u8>> {
    if flag > 1 {
        return None;
    }
    match operation {
        1 => {
            let fields = w336_fields(input, 2)?;
            let digest = crate::metadata::candidate_inventory::sha256(fields[1]);
            let mut path = fields[0].to_vec();
            path.push(b'/');
            const HEX: &[u8; 16] = b"0123456789abcdef";
            for byte in digest {
                path.push(HEX[(byte >> 4) as usize]);
                path.push(HEX[(byte & 15) as usize]);
            }
            path.extend_from_slice(b".lock");
            w336_frame(&[&path])
        }
        2 => {
            w336_fields(input, 0)?;
            // Reject PHP's float-promoting overflow rather than silently saturating.
            let offset = second.max(0).checked_mul(1_000_000_000)?;
            let deadline = first.checked_add(offset)?.to_string();
            w336_frame(&[deadline.as_bytes()])
        }
        3 => {
            let fields = w336_fields(input, 1)?;
            let timeout = first.max(0).to_string();
            w336_frame(&[fields[0], timeout.as_bytes()])
        }
        4 => {
            w336_fields(input, 0)?;
            let depth = first.max(0);
            let joined = depth > 0 || flag == 1;
            let next = if joined { depth.checked_add(1)? } else { 0 }.to_string();
            w336_frame(&[
                if joined { b"1" } else { b"0" },
                next.as_bytes(),
                if depth == 0 { b"1" } else { b"0" },
            ])
        }
        5 => {
            let fields = w336_fields(input, 1)?;
            // PHP/libc retains responsibility for non-finite, huge and tie rounding.
            let scaled = value * 10.0;
            if !value.is_finite()
                || value.abs() >= 1e12
                || ((scaled - scaled.trunc()).abs() - 0.5).abs() <= 1e-6
            {
                return None;
            }
            let mut message = b"Waiting for ".to_vec();
            message.extend_from_slice(fields[0]);
            // PHP sprintf drops the sign of an exact negative zero, but retains
            // the sign when a small negative nonzero value rounds to zero.
            let value = if value == 0.0 { 0.0 } else { value };
            message.extend_from_slice(format!(" ({value:.1}s elapsed)...").as_bytes());
            w336_frame(&[&message])
        }
        6 => {
            let fields = w336_fields(input, 3)?;
            let mut percentage = w306_number_format_2(value)?;
            percentage.push(b'%');
            w336_frame(&[
                fields[0],
                fields[1],
                &percentage,
                if flag == 1 { fields[2] } else { b"never" },
            ])
        }
        _ => None,
    }
}

/// Pure helpers only: no filesystem operations, clock reads or lock ownership changes.
/// Returns the framed byte count, `-needed` on insufficient capacity, or `-1` on
/// invalid/unsupported input. No output bytes are written on any negative return.
///
/// # Safety
/// Non-null input/output pointers must address readable/writable allocations of
/// their declared lengths. The caller owns both allocations for the whole call.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w336_locks_uptime(
    operation: u8,
    input: *const u8,
    input_len: usize,
    first: i64,
    second: i64,
    value: f64,
    flag: u8,
    out: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !add23_valid_out_buf(out, out_cap) {
            return -1;
        }
        let Some(input) = (unsafe { add23_valid_slice(input, input_len, W336_MAX_INPUT) }) else {
            return -1;
        };
        let Some(result) = w336_compute(operation, input, first, second, value, flag) else {
            return -1;
        };
        unsafe { add23_write_slices(out, out_cap, &[&result], 1) }
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod w336_locks_uptime_tests {
    use super::*;

    #[test]
    fn contracts_keep_binary_fields_and_numeric_boundaries() {
        let input = w336_frame(&[b"/locks", b"worker"]).unwrap();
        let output = w336_compute(1, &input, 0, 0, 0.0, 0).unwrap();
        let fields = w336_fields(&output, 1).unwrap();
        assert_eq!(fields[0].len(), 76);
        assert!(fields[0].starts_with(b"/locks/"));
        assert!(fields[0].ends_with(b".lock"));
        for (now, seconds, expected) in [
            (100, 2, "2000000100"),
            (-100, -1, "-100"),
            (i64::MIN, 1, "-9223372035854775808"),
            (i64::MAX, 0, "9223372036854775807"),
        ] {
            let output = w336_compute(2, b"", now, seconds, 0.0, 0).unwrap();
            assert_eq!(w336_fields(&output, 1).unwrap(), [expected.as_bytes()]);
        }
        assert!(w336_compute(2, b"", i64::MAX, 1, 0.0, 0).is_none());
        assert!(w336_compute(2, b"", 0, i64::MAX, 0.0, 0).is_none());
        for timeout in [i64::MIN, -1, 0, 10, i64::MAX] {
            let input = w336_frame(&[b" \0\xff"]).unwrap();
            let output = w336_compute(3, &input, timeout, 0, 0.0, 0).unwrap();
            assert_eq!(
                w336_fields(&output, 2).unwrap(),
                [b" \0\xff".as_slice(), timeout.max(0).to_string().as_bytes()]
            );
        }
        for (depth, acquired, expected) in [
            (0, 0, ["0", "0", "1"]),
            (-1, 1, ["1", "1", "1"]),
            (2, 0, ["1", "3", "0"]),
        ] {
            let output = w336_compute(4, b"", depth, 0, 0.0, acquired).unwrap();
            assert_eq!(
                w336_fields(&output, 3).unwrap(),
                expected.map(str::as_bytes)
            );
        }
        assert!(w336_compute(4, b"", i64::MAX, 0, 0.0, 1).is_none());
        let input = w336_frame(&[b"git\0\xff"]).unwrap();
        let output = w336_compute(5, &input, 0, 0, 1.24, 0).unwrap();
        assert_eq!(
            w336_fields(&output, 1).unwrap(),
            [b"Waiting for git\0\xff (1.2s elapsed)...".as_slice()]
        );
        for (value, expected) in [(-0.0, "0.0"), (-0.04, "-0.0")] {
            let output = w336_compute(5, &input, 0, 0, value, 0).unwrap();
            let mut expected_message = b"Waiting for git\0\xff".to_vec();
            expected_message.extend_from_slice(format!(" ({expected}s elapsed)...").as_bytes());
            assert_eq!(w336_fields(&output, 1).unwrap(), [&expected_message[..]]);
        }
        let input = w336_frame(&[b"db\xff", b"up", b""]).unwrap();
        for (value, flag, percentage, checked) in [
            (1234.567, 0, "1,234.57%", "never"),
            (-0.004, 1, "0.00%", ""),
            (-12.346, 0, "-12.35%", "never"),
        ] {
            let output = w336_compute(6, &input, 0, 0, value, flag).unwrap();
            assert_eq!(
                w336_fields(&output, 4).unwrap(),
                [
                    b"db\xff".as_slice(),
                    b"up",
                    percentage.as_bytes(),
                    checked.as_bytes()
                ]
            );
        }
        for value in [f64::NAN, f64::INFINITY, f64::NEG_INFINITY, f64::MAX, 1.005] {
            assert!(w336_compute(6, &input, 0, 0, value, 0).is_none());
        }
    }

    #[test]
    fn ffi_rejects_invalid_input_and_never_partially_writes() {
        let mut output = [0x5a; 256];
        let input = w336_frame(&[b"worker"]).unwrap();
        let call = |op, ptr, len, flag, out, cap| unsafe {
            nntmux_w336_locks_uptime(op, ptr, len, 10, 0, 99.99, flag, out, cap)
        };
        assert_eq!(
            call(3, std::ptr::null(), 0, 0, output.as_mut_ptr(), 256),
            -1
        );
        assert_eq!(
            call(3, input.as_ptr(), usize::MAX, 0, output.as_mut_ptr(), 256),
            -1
        );
        assert_eq!(
            call(3, input.as_ptr(), input.len(), 0, std::ptr::null_mut(), 256),
            -1
        );
        assert_eq!(
            call(
                3,
                input.as_ptr(),
                input.len(),
                0,
                output.as_mut_ptr(),
                usize::MAX
            ),
            -1
        );
        assert_eq!(
            call(3, input.as_ptr(), input.len(), 2, output.as_mut_ptr(), 256),
            -1
        );
        for len in 0..input.len() {
            assert_eq!(
                call(3, input.as_ptr(), len, 0, output.as_mut_ptr(), 256),
                -1
            );
        }
        let mut trailing = input.clone();
        trailing.push(0);
        assert_eq!(
            call(
                3,
                trailing.as_ptr(),
                trailing.len(),
                0,
                output.as_mut_ptr(),
                256
            ),
            -1
        );
        for op in 0..=7 {
            let fields: Vec<&[u8]> = match op {
                1 => vec![b"/tmp", b"worker"],
                3 | 5 => vec![b"worker"],
                6 => vec![b"db", b"up", b""],
                _ => vec![],
            };
            let input = w336_frame(&fields).unwrap();
            let expected = w336_compute(op, &input, 10, 0, 99.99, 0);
            if let Some(expected) = expected {
                for cap in [0, 1, expected.len() - 1] {
                    assert_eq!(
                        call(op, input.as_ptr(), input.len(), 0, output.as_mut_ptr(), cap),
                        -(expected.len() as isize)
                    );
                    assert_eq!(output, [0x5a; 256]);
                }
                assert_eq!(
                    call(
                        op,
                        input.as_ptr(),
                        input.len(),
                        0,
                        output.as_mut_ptr(),
                        expected.len()
                    ),
                    expected.len() as isize
                );
                assert_eq!(&output[..expected.len()], expected);
                output.fill(0x5a);
            } else {
                assert_eq!(
                    call(op, input.as_ptr(), input.len(), 0, output.as_mut_ptr(), 256),
                    -1
                );
            }
        }
        assert_eq!(output, [0x5a; 256]);
    }
}

// Wave 34.6: pure configuration, runtime flag, service transition and probe summaries.
// Counts and capacities are in i64 elements, never bytes. No persistence or clock reads.
fn w346_compute(operation: u8, input: &[i64]) -> Option<([i64; 5], usize)> {
    let mut result = [0; 5];
    let size = match (operation, input) {
        (1, [threads, timeout, retries]) => {
            result[..3].copy_from_slice(&[(*threads).max(1), (*timeout).max(0), (*retries).max(0)]);
            3
        }
        (2, [sequential, backfill, post, amazon, non]) => {
            result[0] = if (0..=2).contains(sequential) {
                *sequential
            } else {
                0
            };
            result[1] = if matches!(backfill, 0 | 1 | 4) {
                *backfill
            } else {
                0
            };
            for (index, mode) in [post, amazon, non].into_iter().enumerate() {
                result[index + 2] = if (0..=3).contains(mode) { *mode } else { 0 };
            }
            5
        }
        (3, [running, stop, running_update, stop_update]) => {
            if !(0..=1).contains(running)
                || !(0..=1).contains(stop)
                || !(-1..=1).contains(running_update)
                || !(-1..=1).contains(stop_update)
            {
                return None;
            }
            let running = if *running_update == -1 {
                *running
            } else {
                *running_update
            };
            let stop = if *stop_update == -1 {
                *stop
            } else {
                *stop_update
            };
            result[..3].copy_from_slice(&[running, stop, i64::from(running == 1 && stop == 0)]);
            3
        }
        (4, [current, ok, impact]) => {
            if !(0..=4).contains(current) || !(0..=1).contains(ok) || !(-1..=3).contains(impact) {
                return None;
            }
            // Status: operational, degraded, partial outage, major outage, maintenance.
            // Impact: null=-1, none=0, minor=1, major=2, critical=3.
            let status = if *ok == 1 {
                0
            } else {
                match impact {
                    0 | 1 => 1,
                    -1 | 2 => 2,
                    3 => 3,
                    _ => return None,
                }
            };
            result[..2].copy_from_slice(&[status, i64::from(status != *current)]);
            2
        }
        (5, [ok, latency, impact]) => {
            if !(0..=1).contains(ok) || !(-1..=3).contains(impact) {
                return None;
            }
            result[..3].copy_from_slice(&[*ok, (*latency).max(0), *impact]);
            3
        }
        _ => return None,
    };
    Some((result, size))
}

/// Returns elements written, -needed for insufficient output capacity, or -1 for
/// invalid input. Every negative result leaves the caller's output unchanged.
///
/// # Safety
/// Pointers must reference live readable/writable allocations of the declared
/// element counts, aligned for i64. Null and overlapping buffers are rejected.
/// No allocation ownership crosses the ABI; both buffers remain caller-owned.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w346_configuration_status(
    operation: u8,
    input: *const i64,
    input_count: usize,
    output: *mut i64,
    output_capacity: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        let expected = match operation {
            1 | 4 | 5 => 3,
            2 => 5,
            3 => 4,
            _ => return -1,
        };
        if input_count != expected
            || output_capacity > 16
            || input.is_null()
            || output.is_null()
            || (input as usize) % std::mem::align_of::<i64>() != 0
            || (output as usize) % std::mem::align_of::<i64>() != 0
        {
            return -1;
        }
        // Bounded counts make multiplication safe; checked addresses also reject wraparound.
        let Some(input_end) = (input as usize).checked_add(input_count * 8) else {
            return -1;
        };
        let Some(output_end) = (output as usize).checked_add(output_capacity * 8) else {
            return -1;
        };
        if output_capacity != 0 && (input as usize) < output_end && (output as usize) < input_end {
            return -1;
        }
        let values = unsafe { std::slice::from_raw_parts(input, input_count) };
        let Some((result, count)) = w346_compute(operation, values) else {
            return -1;
        };
        if output_capacity < count {
            return -(count as isize);
        }
        unsafe { std::ptr::copy_nonoverlapping(result.as_ptr(), output, count) };
        count as isize
    })
    .unwrap_or(-1)
}

#[cfg(test)]
mod w346_configuration_status_tests {
    use super::*;

    #[test]
    fn pure_computation_boundaries() {
        assert_eq!(
            w346_compute(1, &[i64::MIN, -1, i64::MAX]),
            Some(([1, 0, i64::MAX, 0, 0], 3))
        );
        assert_eq!(
            w346_compute(2, &[3, 2, -1, 3, 2]),
            Some(([0, 0, 0, 3, 2], 5))
        );
        assert_eq!(
            w346_compute(2, &[2, 4, 1, 2, 3]),
            Some(([2, 4, 1, 2, 3], 5))
        );
        for running in 0..=1 {
            for stop in 0..=1 {
                for update in -1..=1 {
                    let actual = w346_compute(3, &[running, stop, update, -1]).unwrap().0;
                    let next = if update == -1 { running } else { update };
                    assert_eq!(
                        &actual[..3],
                        &[next, stop, i64::from(next == 1 && stop == 0)]
                    );
                }
            }
        }
        for current in 0..=4 {
            for (impact, status) in [(-1, 2), (0, 1), (1, 1), (2, 2), (3, 3)] {
                assert_eq!(
                    w346_compute(4, &[current, 0, impact]).unwrap().0[..2],
                    [status, i64::from(current != status)]
                );
                assert_eq!(
                    w346_compute(4, &[current, 1, impact]).unwrap().0[..2],
                    [0, i64::from(current != 0)]
                );
            }
        }
        assert_eq!(
            w346_compute(5, &[0, i64::MIN, -1]),
            Some(([0, 0, -1, 0, 0], 3))
        );
        assert_eq!(
            w346_compute(5, &[1, i64::MAX, 3]),
            Some(([1, i64::MAX, 3, 0, 0], 3))
        );
        for (op, input) in [
            (3, vec![2, 0, -1, -1]),
            (3, vec![1, 0, -2, -1]),
            (4, vec![5, 0, 0]),
            (4, vec![0, 2, 0]),
            (4, vec![0, 0, 4]),
            (5, vec![0, 0, -2]),
        ] {
            assert!(w346_compute(op, &input).is_none());
        }
    }

    #[test]
    fn abi_checks_before_dereference_and_never_partially_writes() {
        let mut input = [1_i64, 0, -1, -1, 3];
        let mut output = [90_i64; 8];
        let call = |op, ptr, len, out, cap| unsafe {
            nntmux_w346_configuration_status(op, ptr, len, out, cap)
        };
        for (op, count, needed) in [(1, 3, 3), (2, 5, 5), (3, 4, 3), (4, 3, 2), (5, 3, 3)] {
            assert_eq!(
                call(op, input.as_ptr(), count, output.as_mut_ptr(), 0),
                -needed
            );
            assert_eq!(
                call(
                    op,
                    input.as_ptr(),
                    count,
                    output.as_mut_ptr(),
                    needed as usize - 1
                ),
                -needed
            );
            assert_eq!(
                call(op, std::ptr::null(), count, output.as_mut_ptr(), 8),
                -1
            );
            assert_eq!(call(op, input.as_ptr(), count, std::ptr::null_mut(), 8), -1);
            assert_eq!(
                call(op, input.as_ptr(), count - 1, output.as_mut_ptr(), 8),
                -1
            );
            assert_eq!(
                call(op, input.as_ptr(), usize::MAX, output.as_mut_ptr(), 8),
                -1
            );
            assert_eq!(
                call(op, input.as_ptr(), count, output.as_mut_ptr(), usize::MAX),
                -1
            );
            assert_eq!(
                call(op, input.as_ptr(), count, input.as_mut_ptr(), count),
                -1
            );
            let unaligned = unsafe { input.as_ptr().cast::<u8>().add(1) }.cast::<i64>();
            assert_eq!(call(op, unaligned, count, output.as_mut_ptr(), 8), -1);
            assert_eq!(call(op, input.as_ptr(), count, unaligned.cast_mut(), 8), -1);
            let wraps = (usize::MAX - 7) as *const i64;
            assert_eq!(call(op, wraps, count, output.as_mut_ptr(), 8), -1);
            assert_eq!(call(op, input.as_ptr(), count, wraps.cast_mut(), 8), -1);
            assert_eq!(output, [90; 8]);
            let expected = w346_compute(op, &input[..count]).unwrap();
            assert_eq!(
                call(op, input.as_ptr(), count, output.as_mut_ptr(), 8),
                needed
            );
            assert_eq!(&output[..needed as usize], &expected.0[..needed as usize]);
            assert_eq!(&output[needed as usize..], &vec![90; 8 - needed as usize]);
            output.fill(90);
        }
        assert_eq!(call(0, input.as_ptr(), 3, output.as_mut_ptr(), 8), -1);
        input[0] = 2;
        assert_eq!(call(3, input.as_ptr(), 4, output.as_mut_ptr(), 8), -1);
        assert_eq!(output, [90; 8]);
    }
}

// Wave 35.6: additional-processing DTO summaries and query-duration normalization.
// Numeric fields use native-endian i64/f64 (PHP pack q/d); byte strings use a
// little-endian u32 length followed by arbitrary bytes, including embedded NULs.
const W356_MAX_BYTES: usize = 16_777_216;

struct W356Reader<'a> {
    bytes: &'a [u8],
    offset: usize,
}

impl<'a> W356Reader<'a> {
    fn take(&mut self, count: usize) -> Option<&'a [u8]> {
        let end = self.offset.checked_add(count)?;
        let value = self.bytes.get(self.offset..end)?;
        self.offset = end;
        Some(value)
    }

    fn integer(&mut self) -> Option<i64> {
        Some(i64::from_ne_bytes(self.take(8)?.try_into().ok()?))
    }

    fn float(&mut self) -> Option<f64> {
        Some(f64::from_ne_bytes(self.take(8)?.try_into().ok()?))
    }

    fn count(&mut self) -> Option<usize> {
        Some(u32::from_le_bytes(self.take(4)?.try_into().ok()?) as usize)
    }

    fn string(&mut self) -> Option<&'a [u8]> {
        let size = self.count()?;
        self.take(size)
    }
}

fn w356_string(output: &mut Vec<u8>, bytes: &[u8]) {
    output.extend_from_slice(&(bytes.len() as u32).to_le_bytes());
    output.extend_from_slice(bytes);
}

fn w356_duration(value: f64) -> f64 {
    if value.is_finite() && value > 0.0 {
        value
    } else {
        0.0
    }
}

fn w356_compute(operation: u8, bytes: &[u8]) -> Option<Vec<u8>> {
    let mut reader = W356Reader { bytes, offset: 0 };
    let mut output = Vec::new();
    match operation {
        1 => {
            // Six normalized counters, then cache-hit, byte-reuse and media-byte ratios.
            let mut values = [0_i64; 6];
            for value in &mut values {
                *value = reader.integer()?.max(0);
                output.extend_from_slice(&value.to_ne_bytes());
            }
            let ratio = |n: i64, d: i64| {
                if d > 0 {
                    (n as f64 / d as f64).min(1.0)
                } else {
                    0.0
                }
            };
            let total = values[3] as f64 + values[4] as f64;
            for value in [
                ratio(values[2], values[0]),
                if total > 0.0 {
                    values[4] as f64 / total
                } else {
                    0.0
                },
                ratio(values[5], values[3]),
            ] {
                output.extend_from_slice(&value.to_ne_bytes());
            }
        }
        2 => {
            // Source, posted size, title, count, message IDs; first-seen unique IDs.
            let source = reader.integer()?.max(0);
            let size = reader.integer()?.max(0);
            let title = trim_php(reader.string()?);
            let count = reader.count()?;
            if count > (bytes.len() - reader.offset) / 4 {
                return None;
            }
            let mut seen = std::collections::HashSet::new();
            let mut ids = Vec::new();
            for _ in 0..count {
                let id = trim_php(reader.string()?);
                if !id.is_empty() && seen.insert(id) {
                    ids.push(id);
                }
            }
            output.extend_from_slice(&source.to_ne_bytes());
            output.extend_from_slice(&size.to_ne_bytes());
            w356_string(&mut output, title);
            output.extend_from_slice(&(ids.len() as u32).to_le_bytes());
            for id in ids {
                w356_string(&mut output, id);
            }
        }
        3 => {
            // Source, size, filename, confidence -> source, size, rank, filename, value.
            let source = reader.integer()?.max(0);
            let size = reader.integer()?.max(0);
            let filename = trim_php(reader.string()?);
            let normalized: Vec<u8> = trim_php(reader.string()?)
                .iter()
                .map(|b| {
                    if *b == b'_' {
                        b'-'
                    } else {
                        b.to_ascii_lowercase()
                    }
                })
                .collect();
            let (confidence, rank): (&[u8], i64) = match normalized.as_slice() {
                b"verified" => (b"verified", 2),
                b"unique-size" => (b"unique-size", 1),
                _ => (b"unknown", 0),
            };
            for value in [source, size, rank] {
                output.extend_from_slice(&value.to_ne_bytes());
            }
            w356_string(&mut output, filename);
            w356_string(&mut output, confidence);
        }
        4 => {
            // Statements, milliseconds, sync requests/executions, mean ms, saved requests.
            let statements = reader.integer()?.max(0);
            let ms = w356_duration(reader.float()?);
            let requests = reader.integer()?.max(0);
            let executions = reader.integer()?.max(0);
            output.extend_from_slice(&statements.to_ne_bytes());
            output.extend_from_slice(&ms.to_ne_bytes());
            output.extend_from_slice(&requests.to_ne_bytes());
            output.extend_from_slice(&executions.to_ne_bytes());
            let average = if statements > 0 {
                ms / statements as f64
            } else {
                0.0
            };
            output.extend_from_slice(&average.to_ne_bytes());
            output.extend_from_slice(&(requests - executions).max(0).to_ne_bytes());
        }
        5 => output.extend_from_slice(&w356_duration(reader.float()?).to_ne_bytes()),
        _ => return None,
    }
    if reader.offset != bytes.len() || output.len() > W356_MAX_BYTES {
        return None;
    }
    Some(output)
}

/// Returns bytes written, -needed for insufficient capacity, or -1 for malformed
/// input, invalid pointers/ranges, unsupported operations or a caught panic.
/// Negative returns never modify output. There is no terminator or Rust-owned buffer.
///
/// # Safety
/// Input/output must point to live readable/writable allocations of their declared
/// byte counts. Both pointers must be nonnull, with nonoverlapping ranges of at
/// most 16 MiB. Byte alignment suffices; length-prefix and numeric reads are copied.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w356_dto_provider(
    operation: u8,
    input: *const u8,
    input_len: usize,
    output: *mut u8,
    output_capacity: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        if !(1..=5).contains(&operation)
            || input.is_null()
            || output.is_null()
            || input_len > W356_MAX_BYTES
            || output_capacity > W356_MAX_BYTES
        {
            return -1;
        }
        let Some(input_end) = (input as usize).checked_add(input_len) else {
            return -1;
        };
        let Some(output_end) = (output as usize).checked_add(output_capacity) else {
            return -1;
        };
        if input_len != 0
            && output_capacity != 0
            && (input as usize) < output_end
            && (output as usize) < input_end
        {
            return -1;
        }
        let bytes = unsafe { std::slice::from_raw_parts(input, input_len) };
        let Some(result) = w356_compute(operation, bytes) else {
            return -1;
        };
        if output_capacity < result.len() {
            return -(result.len() as isize);
        }
        unsafe { std::ptr::copy_nonoverlapping(result.as_ptr(), output, result.len()) };
        result.len() as isize
    })
    .unwrap_or(-1)
}

#[cfg(test)]
mod w356_dto_provider_tests {
    use super::*;

    #[test]
    fn malformed_frames_and_nonfinite_durations() {
        for operation in 0..=6 {
            assert!(w356_compute(operation, &[]).is_none());
        }
        for value in [f64::NAN, f64::INFINITY, f64::NEG_INFINITY, -0.0, -1.0] {
            assert_eq!(
                w356_compute(5, &value.to_ne_bytes()),
                Some(0.0_f64.to_ne_bytes().to_vec())
            );
        }
        assert_eq!(
            w356_compute(5, &1.25_f64.to_ne_bytes()),
            Some(1.25_f64.to_ne_bytes().to_vec())
        );
        let mut invalid = vec![0; 16];
        invalid.extend_from_slice(&u32::MAX.to_le_bytes());
        assert!(w356_compute(2, &invalid).is_none());
        assert!(w356_compute(3, &invalid).is_none());
        invalid[16..20].copy_from_slice(&0_u32.to_le_bytes());
        invalid.extend_from_slice(&u32::MAX.to_le_bytes());
        assert!(w356_compute(2, &invalid).is_none());
    }

    #[test]
    fn boundaries_and_no_partial_output() {
        let input: Vec<u8> = [i64::MIN, i64::MAX, i64::MAX, i64::MAX, i64::MAX, i64::MAX]
            .iter()
            .flat_map(|v| v.to_ne_bytes())
            .collect();
        let result = w356_compute(1, &input).unwrap();
        assert_eq!(&result[48..56], &0.0_f64.to_ne_bytes());
        assert_eq!(&result[56..64], &0.5_f64.to_ne_bytes());
        assert_eq!(&result[64..72], &1.0_f64.to_ne_bytes());
        let mut output = [90_u8; 80];
        let call =
            |op, ptr, len, out, cap| unsafe { nntmux_w356_dto_provider(op, ptr, len, out, cap) };
        assert_eq!(
            call(1, input.as_ptr(), input.len(), output.as_mut_ptr(), 71),
            -72
        );
        assert_eq!(
            call(1, std::ptr::null(), input.len(), output.as_mut_ptr(), 80),
            -1
        );
        assert_eq!(
            call(1, input.as_ptr(), input.len(), std::ptr::null_mut(), 80),
            -1
        );
        assert_eq!(
            call(1, input.as_ptr(), usize::MAX, output.as_mut_ptr(), 80),
            -1
        );
        assert_eq!(
            call(
                1,
                input.as_ptr(),
                input.len(),
                output.as_mut_ptr(),
                usize::MAX
            ),
            -1
        );
        assert_eq!(
            call(
                1,
                usize::MAX as *const u8,
                input.len(),
                output.as_mut_ptr(),
                80
            ),
            -1
        );
        assert_eq!(
            call(1, input.as_ptr(), input.len(), usize::MAX as *mut u8, 80),
            -1
        );
        assert_eq!(
            call(1, input.as_ptr(), input.len() - 1, output.as_mut_ptr(), 80),
            -1
        );
        assert_eq!(output, [90; 80]);
        let mut overlapping = input.clone();
        assert_eq!(
            call(1, overlapping.as_ptr(), 48, overlapping.as_mut_ptr(), 48),
            -1
        );
        assert_eq!(overlapping, input);
        assert_eq!(call(1, input.as_ptr(), 48, output.as_mut_ptr(), 80), 72);
        assert_eq!(&output[..72], result.as_slice());
        assert_eq!(&output[72..], &[90; 8]);
    }
}

// Wave 36.6: tmux enum values, Horizon access, and failed-job count summaries.
// Integers are native-endian i64 (PHP pack q); strings are u32 LE + raw bytes.
// Enum input tags: 0 unsupported PHP type, 1 integer, 2 byte string. An enum
// output is i64 case index (-1 for an unknown role) + one validity byte.
const W366_MAX_BYTES: usize = 16_777_216;
const W366_PANE_ROLES: [&[u8]; 20] = [
    b"monitor",
    b"binaries",
    b"backfill",
    b"releases",
    b"sequential",
    b"fix_names",
    b"remove_crap",
    b"post_additional",
    b"post_tv",
    b"post_metadata",
    b"post_movies",
    b"irc_scraper",
    b"htop",
    b"nmon",
    b"vnstat",
    b"tcptrack",
    b"bandwidth_monitor",
    b"mytop",
    b"redis",
    b"console",
];

fn w366_compute(operation: u8, bytes: &[u8]) -> Option<Vec<u8>> {
    let mut reader = W356Reader { bytes, offset: 0 };
    let mut output = Vec::with_capacity(25);
    match operation {
        1..=3 => {
            let tag = *reader.take(1)?.first()?;
            let mut index = None;
            match tag {
                0 => {}
                1 => {
                    let value = reader.integer()?;
                    if operation != 1 && (0..=if operation == 2 { 3 } else { 2 }).contains(&value) {
                        index = Some(value);
                    }
                }
                2 => {
                    let value = reader.string()?;
                    index = if operation == 1 {
                        W366_PANE_ROLES
                            .iter()
                            .position(|role| *role == value)
                            .map(|i| i as i64)
                    } else if value.len() == 1
                        && (b'0'..=if operation == 2 { b'3' } else { b'2' }).contains(&value[0])
                    {
                        Some((value[0] - b'0') as i64)
                    } else {
                        None
                    };
                }
                _ => return None,
            }
            output.extend_from_slice(
                &index
                    .unwrap_or(if operation == 1 { -1 } else { 0 })
                    .to_ne_bytes(),
            );
            output.push(u8::from(index.is_some()));
        }
        4 => {
            let authenticated = *reader.take(1)?.first()?;
            let admin = *reader.take(1)?.first()?;
            if authenticated > 1 || admin > 1 {
                return None;
            }
            let _user_id = reader.integer()?;
            let local = u8::from(reader.string()? == b"local");
            let gate = authenticated & admin;
            output.extend_from_slice(&[authenticated, admin, local, gate, gate | local]);
        }
        5 => {
            let total = reader.integer()?.max(0);
            let hour = reader.integer()?.max(0);
            let day = reader.integer()?.max(0);
            for value in [total, hour, day] {
                output.extend_from_slice(&value.to_ne_bytes());
            }
            output.push(u8::from(total > 0));
        }
        _ => return None,
    }
    (reader.offset == bytes.len()).then_some(output)
}

/// Returns bytes written, -required for insufficient capacity, or -1 for invalid
/// operation/frame/range or a caught panic. Every negative return preserves all
/// output bytes. The caller owns the output allocation; no terminator is written.
///
/// # Safety
/// Both pointers must reference live readable/writable allocations of the declared
/// lengths, at most 16 MiB each. They must be nonnull and their ranges must not
/// overlap. Byte alignment suffices; numeric fields are copied before decoding.
/// Pointer arithmetic validation cannot establish allocation ownership/liveness.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w366_tmux_horizon_failed_jobs(
    operation: u8,
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        if !(1..=5).contains(&operation)
            || input.is_null()
            || out_buf.is_null()
            || input_len > W366_MAX_BYTES
            || out_cap > W366_MAX_BYTES
        {
            return -1;
        }
        let Some(input_end) = (input as usize).checked_add(input_len) else {
            return -1;
        };
        let Some(output_end) = (out_buf as usize).checked_add(out_cap) else {
            return -1;
        };
        if input_len != 0
            && out_cap != 0
            && (input as usize) < output_end
            && (out_buf as usize) < input_end
        {
            return -1;
        }
        let bytes = unsafe { std::slice::from_raw_parts(input, input_len) };
        let Some(result) = w366_compute(operation, bytes) else {
            return -1;
        };
        if out_cap < result.len() {
            return -(result.len() as isize);
        }
        unsafe { std::ptr::copy_nonoverlapping(result.as_ptr(), out_buf, result.len()) };
        result.len() as isize
    })
    .unwrap_or(-1)
}

#[cfg(test)]
mod w366_tests {
    use super::*;

    fn string_frame(value: &[u8]) -> Vec<u8> {
        let mut bytes = vec![2];
        w356_string(&mut bytes, value);
        bytes
    }

    #[test]
    fn exact_enum_values_and_integer_boundaries() {
        for (i, role) in W366_PANE_ROLES.iter().enumerate() {
            let output = w366_compute(1, &string_frame(role)).unwrap();
            assert_eq!(&output[..8], &(i as i64).to_ne_bytes());
            assert_eq!(output[8], 1);
        }
        for operation in 2..=3 {
            for value in [i64::MIN, -1, 0, 1, 2, 3, 4, i64::MAX] {
                let mut bytes = vec![1];
                bytes.extend_from_slice(&value.to_ne_bytes());
                let output = w366_compute(operation, &bytes).unwrap();
                let valid = (0..=if operation == 2 { 3 } else { 2 }).contains(&value);
                assert_eq!(output[8], u8::from(valid));
                assert_eq!(
                    &output[..8],
                    &if valid { value } else { 0_i64 }.to_ne_bytes()
                );
            }
            for value in [b"00".as_slice(), b" 1 ", b"+1", b"1\0", b"\xff", b""] {
                assert_eq!(w366_compute(operation, &string_frame(value)).unwrap()[8], 0);
            }
        }
        assert_eq!(w366_compute(1, &string_frame(b"MONITOR")).unwrap()[8], 0);
        assert_eq!(w366_compute(1, &[0]).unwrap()[..8], (-1_i64).to_ne_bytes());
    }

    #[test]
    fn access_and_counts_do_not_coerce_or_overflow() {
        for authenticated in 0..=1 {
            for admin in 0..=1 {
                for environment in [b"local".as_slice(), b"LOCAL", b"local\0", b""] {
                    let mut input = vec![authenticated, admin];
                    input.extend_from_slice(&i64::MIN.to_ne_bytes());
                    w356_string(&mut input, environment);
                    let local = u8::from(environment == b"local");
                    let gate = authenticated & admin;
                    assert_eq!(
                        w366_compute(4, &input).unwrap(),
                        [authenticated, admin, local, gate, gate | local]
                    );
                }
            }
        }
        let input: Vec<u8> = [i64::MIN, i64::MAX, -1]
            .iter()
            .flat_map(|v| v.to_ne_bytes())
            .collect();
        let output = w366_compute(5, &input).unwrap();
        assert_eq!(&output[..8], &0_i64.to_ne_bytes());
        assert_eq!(&output[8..16], &i64::MAX.to_ne_bytes());
        assert_eq!(&output[16..24], &0_i64.to_ne_bytes());
        assert_eq!(output[24], 0);
    }

    #[test]
    fn invalid_abi_and_short_buffers_never_write() {
        let mut horizon = vec![1, 1];
        horizon.extend_from_slice(&0_i64.to_ne_bytes());
        w356_string(&mut horizon, b"local");
        let mut count = Vec::new();
        for value in [i64::MAX, i64::MIN, 3] {
            count.extend_from_slice(&value.to_ne_bytes());
        }
        for (op, input) in [
            (1, string_frame(b"monitor")),
            (2, string_frame(b"3")),
            (3, string_frame(b"2")),
            (4, horizon),
            (5, count),
        ] {
            let expected = w366_compute(op, &input).unwrap();
            let mut output = [90_u8; 64];
            let call = |ptr, len, out, cap| unsafe {
                nntmux_w366_tmux_horizon_failed_jobs(op, ptr, len, out, cap)
            };
            assert_eq!(
                call(std::ptr::null(), input.len(), output.as_mut_ptr(), 64),
                -1
            );
            assert_eq!(
                call(input.as_ptr(), input.len(), std::ptr::null_mut(), 64),
                -1
            );
            assert_eq!(
                call(input.as_ptr(), usize::MAX, output.as_mut_ptr(), 64),
                -1
            );
            assert_eq!(
                call(input.as_ptr(), W366_MAX_BYTES + 1, output.as_mut_ptr(), 64),
                -1
            );
            assert_eq!(
                call(input.as_ptr(), input.len(), output.as_mut_ptr(), usize::MAX),
                -1
            );
            assert_eq!(
                call(
                    input.as_ptr(),
                    input.len(),
                    output.as_mut_ptr(),
                    W366_MAX_BYTES + 1
                ),
                -1
            );
            assert_eq!(
                call(
                    usize::MAX as *const u8,
                    input.len(),
                    output.as_mut_ptr(),
                    64
                ),
                -1
            );
            assert_eq!(
                call(input.as_ptr(), input.len(), usize::MAX as *mut u8, 64),
                -1
            );
            assert_eq!(
                call(input.as_ptr(), input.len(), output.as_mut_ptr(), 0),
                -(expected.len() as isize)
            );
            assert_eq!(
                call(
                    input.as_ptr(),
                    input.len(),
                    output.as_mut_ptr(),
                    expected.len() - 1
                ),
                -(expected.len() as isize)
            );
            for len in 0..input.len() {
                assert_eq!(call(input.as_ptr(), len, output.as_mut_ptr(), 64), -1);
            }
            let mut extra = input.clone();
            extra.push(0);
            assert_eq!(
                call(extra.as_ptr(), extra.len(), output.as_mut_ptr(), 64),
                -1
            );
            assert_eq!(output, [90; 64]);
            let mut overlapping = input.clone();
            assert_eq!(
                call(
                    overlapping.as_ptr(),
                    input.len(),
                    overlapping.as_mut_ptr(),
                    input.len()
                ),
                -1
            );
            assert_eq!(overlapping, input);
            assert_eq!(
                call(
                    input.as_ptr(),
                    input.len(),
                    output.as_mut_ptr(),
                    expected.len()
                ),
                expected.len() as isize
            );
            assert_eq!(&output[..expected.len()], &expected);
            assert!(output[expected.len()..].iter().all(|b| *b == 90));
        }
        for (op, input) in [
            (0, vec![]),
            (6, vec![]),
            (1, vec![3]),
            (1, vec![2, 255, 255, 255, 255]),
            (4, vec![2; 14]),
        ] {
            let mut out = [90_u8; 64];
            assert_eq!(
                unsafe {
                    nntmux_w366_tmux_horizon_failed_jobs(
                        op,
                        input.as_ptr(),
                        input.len(),
                        out.as_mut_ptr(),
                        64,
                    )
                },
                -1
            );
            assert_eq!(out, [90; 64]);
        }
    }
}

// Wave 37.6: byte-oriented, deterministic summaries. Frames use u32 LE string
// lengths and native-endian i64 counts, matching PHP pack('V') / pack('q').
const W376_MAX_INPUT: usize = 16_777_216;

fn w376_flag(reader: &mut W356Reader<'_>) -> Option<bool> {
    match reader.take(1)?[0] {
        0 => Some(false),
        1 => Some(true),
        _ => None,
    }
}

fn w376_compute(operation: u8, input: &[u8]) -> Option<Vec<u8>> {
    let mut reader = W356Reader {
        bytes: input,
        offset: 0,
    };
    let mut output = Vec::new();
    match operation {
        1 => {
            let console = w376_flag(&mut reader)?;
            let enabled = w376_flag(&mut reader)?;
            let command = reader.string()?;
            let prefixes: [&[u8]; 10] = [
                b"tmux:",
                b"multiprocessing:",
                b"postprocess:",
                b"update:",
                b"releases:",
                b"binaries:",
                b"articles:",
                b"backfill:",
                b"group:",
                b"predb:",
            ];
            // Only the ten prefixes used by AppServiceProvider::register qualify.
            let matches = prefixes.iter().any(|prefix| command.starts_with(prefix));
            output.push(u8::from(console && !enabled && matches));
        }
        2 => output.push(u8::from(w376_flag(&mut reader)?)),
        3 => {
            let sensitive = w376_flag(&mut reader)?;
            let rules = reader.integer()?.max(0);
            let options = reader.integer()?.max(0);
            let control = reader.string()?;
            output.extend_from_slice(&rules.to_ne_bytes());
            output.extend_from_slice(&options.to_ne_bytes());
            output.extend_from_slice(&[u8::from(sensitive), u8::from(options > 0)]);
            w356_string(&mut output, control);
        }
        4 => {
            let has_method = w376_flag(&mut reader)?;
            let has_property = w376_flag(&mut reader)?;
            let method = reader.string()?;
            let property = reader.string()?;
            if !has_property && !property.is_empty() {
                return None;
            }
            w356_string(
                &mut output,
                if has_method {
                    method
                } else if has_property {
                    property
                } else {
                    b"/home"
                },
            );
        }
        5 => {
            let present = w376_flag(&mut reader)?;
            let view = reader.string()?;
            if !present && !view.is_empty() {
                return None;
            }
            let view = if present { view } else { b"layouts.app" };
            output.push(u8::from(view == b"layouts.app"));
            w356_string(&mut output, view);
        }
        _ => return None,
    }
    (reader.offset == input.len() && output.len() <= W376_MAX_INPUT).then_some(output)
}

/// Returns bytes written, -required for a short output, or -1 for invalid input.
/// Negative returns never write output; no NUL terminator is appended.
///
/// # Safety
/// Pointers must designate live allocations of the declared readable/writable
/// sizes, nonnull and nonoverlapping, at most MAX_INPUT each. Range checks cannot
/// establish allocation ownership or liveness. Byte alignment is sufficient.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w376_app_rss_config_auth(
    operation: u8,
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !(1..=5).contains(&operation)
            || input.is_null()
            || out_buf.is_null()
            || input_len > W376_MAX_INPUT
            || out_cap > W376_MAX_INPUT
        {
            return -1;
        }
        let Some(input_end) = (input as usize).checked_add(input_len) else {
            return -1;
        };
        let Some(output_end) = (out_buf as usize).checked_add(out_cap) else {
            return -1;
        };
        if input_len != 0
            && out_cap != 0
            && (input as usize) < output_end
            && (out_buf as usize) < input_end
        {
            return -1;
        }
        let bytes = unsafe { std::slice::from_raw_parts(input, input_len) };
        let Some(result) = w376_compute(operation, bytes) else {
            return -1;
        };
        if out_cap < result.len() {
            return -(result.len() as isize);
        }
        unsafe { std::ptr::copy_nonoverlapping(result.as_ptr(), out_buf, result.len()) };
        result.len() as isize
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod w376_tests {
    use super::*;

    fn string(bytes: &[u8]) -> Vec<u8> {
        let mut out = Vec::new();
        w356_string(&mut out, bytes);
        out
    }

    fn fixtures() -> Vec<(u8, Vec<u8>, Vec<u8>)> {
        let mut pulse = vec![1, 0];
        pulse.extend(string(b"tmux:start"));
        let mut field = vec![1];
        field.extend_from_slice(&i64::MIN.to_ne_bytes());
        field.extend_from_slice(&i64::MAX.to_ne_bytes());
        field.extend(string(b"enum\0\xff"));
        let mut field_out = 0_i64.to_ne_bytes().to_vec();
        field_out.extend_from_slice(&i64::MAX.to_ne_bytes());
        field_out.extend_from_slice(&[1, 1]);
        field_out.extend(string(b"enum\0\xff"));
        let mut redirect = vec![0, 0];
        redirect.extend(string(b"unused"));
        redirect.extend(string(b""));
        let mut layout = vec![0];
        layout.extend(string(b""));
        let mut layout_out = vec![1];
        layout_out.extend(string(b"layouts.app"));
        vec![
            (1, pulse, vec![1]),
            (2, vec![1], vec![1]),
            (3, field, field_out),
            (4, redirect, string(b"/home")),
            (5, layout, layout_out),
        ]
    }

    #[test]
    fn w376_literal_contracts() {
        for (op, input, expected) in fixtures() {
            assert_eq!(w376_compute(op, &input), Some(expected));
        }
        for prefix in [
            "tmux",
            "multiprocessing",
            "postprocess",
            "update",
            "releases",
            "binaries",
            "articles",
            "backfill",
            "group",
            "predb",
        ] {
            let mut frame = vec![1, 0];
            frame.extend(string(format!("{prefix}:").as_bytes()));
            assert_eq!(w376_compute(1, &frame), Some(vec![1]));
            frame[0] = 0;
            assert_eq!(w376_compute(1, &frame), Some(vec![0]));
            frame[0] = 1;
            frame[1] = 1;
            assert_eq!(w376_compute(1, &frame), Some(vec![0]));
        }
        for command in [
            b"TMUX:".as_slice(),
            b" tmux:",
            b"xtmux:",
            b"tmux",
            b"\xff:",
            b"",
        ] {
            let mut frame = vec![1, 0];
            frame.extend(string(command));
            assert_eq!(w376_compute(1, &frame), Some(vec![0]));
        }
        let mut redirect = vec![1, 1];
        redirect.extend(string(b""));
        redirect.extend(string(b"property"));
        assert_eq!(w376_compute(4, &redirect), Some(string(b"")));
    }

    #[test]
    fn w376_invalid_and_short_frames_preserve_output() {
        for (op, input, expected) in fixtures() {
            let mut out = [90_u8; 128];
            let call = |p, n, o, c| unsafe { nntmux_w376_app_rss_config_auth(op, p, n, o, c) };
            for (p, n, o, c) in [
                (std::ptr::null(), input.len(), out.as_mut_ptr(), 128),
                (input.as_ptr(), input.len(), std::ptr::null_mut(), 128),
                (usize::MAX as *const u8, input.len(), out.as_mut_ptr(), 128),
                (input.as_ptr(), input.len(), usize::MAX as *mut u8, 128),
                (input.as_ptr(), usize::MAX, out.as_mut_ptr(), 128),
                (input.as_ptr(), input.len(), out.as_mut_ptr(), usize::MAX),
                (input.as_ptr(), W376_MAX_INPUT + 1, out.as_mut_ptr(), 128),
                (
                    input.as_ptr(),
                    input.len(),
                    out.as_mut_ptr(),
                    W376_MAX_INPUT + 1,
                ),
            ] {
                assert_eq!(call(p, n, o, c), -1);
            }
            for len in 0..input.len() {
                assert_eq!(call(input.as_ptr(), len, out.as_mut_ptr(), 128), -1);
            }
            let mut extra = input.clone();
            extra.push(0);
            assert_eq!(call(extra.as_ptr(), extra.len(), out.as_mut_ptr(), 128), -1);
            let mut bad = input.clone();
            bad[0] = 2;
            assert_eq!(call(bad.as_ptr(), bad.len(), out.as_mut_ptr(), 128), -1);
            assert_eq!(
                call(input.as_ptr(), input.len(), out.as_mut_ptr(), 0),
                -(expected.len() as isize)
            );
            assert_eq!(
                call(
                    input.as_ptr(),
                    input.len(),
                    out.as_mut_ptr(),
                    expected.len() - 1
                ),
                -(expected.len() as isize)
            );
            assert_eq!(out, [90; 128]);
            let mut alias = input.clone();
            assert_eq!(
                call(alias.as_ptr(), alias.len(), alias.as_mut_ptr(), alias.len()),
                -1
            );
            assert_eq!(alias, input);
            assert_eq!(
                call(
                    input.as_ptr(),
                    input.len(),
                    out.as_mut_ptr(),
                    expected.len()
                ),
                expected.len() as isize
            );
            assert_eq!(&out[..expected.len()], expected);
            assert!(out[expected.len()..].iter().all(|v| *v == 90));
        }
        for op in [0, 6, 255] {
            let mut out = [90_u8; 128];
            assert_eq!(
                unsafe {
                    nntmux_w376_app_rss_config_auth(op, b"".as_ptr(), 0, out.as_mut_ptr(), 128)
                },
                -1
            );
            assert_eq!(out, [90; 128]);
        }
        for (op, input) in [
            (1, vec![1, 2, 0, 0, 0, 0]),
            (4, vec![0, 2, 0, 0, 0, 0, 0, 0, 0, 0]),
            (5, vec![1, 255, 255, 255, 255]),
        ] {
            assert_eq!(w376_compute(op, &input), None);
        }
    }
}

// Wave 38.6: pure plans only; strings are byte-preserving u32-LE frames,
// integers are native-endian i64, and flags are restricted to op 2's wait bit.
const W386_MAX_INPUT: usize = 16_777_216;

fn w386_compute(operation: u8, flags: u8, input: &[u8]) -> Option<Vec<u8>> {
    if !(1..=5).contains(&operation) || flags > 1 || (operation != 2 && flags != 0) {
        return None;
    }
    let mut reader = W356Reader {
        bytes: input,
        offset: 0,
    };
    let mut output = Vec::new();
    match operation {
        1 => {
            let token = reader.string()?;
            let valid = (1..=128).contains(&token.len())
                && token
                    .iter()
                    .all(|b| b.is_ascii_alphanumeric() || *b == b'_' || *b == b'-');
            output.extend_from_slice(&[u8::from(!token.is_empty()), u8::from(valid)]);
        }
        2 | 4 => {
            let count = reader.count()?;
            // Each string needs at least its four-byte length; reject huge counts
            // before allocation or iteration. The input itself is already bounded.
            if count > input.len().checked_sub(reader.offset)? / 4 {
                return None;
            }
            output.extend_from_slice(&(count as u32).to_le_bytes());
            for _ in 0..count {
                let value = reader.string()?;
                if operation == 2 {
                    output.push(u8::from(
                        flags == 0 || value == b"monitor" || value == b"irc_scraper",
                    ));
                } else {
                    w356_string(&mut output, &value[..value.len().min(1)]);
                }
            }
        }
        3 => {
            let limit = reader.integer()?;
            let queued = reader.integer()?.max(0);
            let chunk = if limit <= 0 {
                1000
            } else if queued >= limit {
                0
            } else {
                (limit - queued).min(1000)
            };
            output.extend_from_slice(&chunk.to_ne_bytes());
        }
        5 => {
            let count = reader.count()?;
            if count > input.len().checked_sub(reader.offset)? / 8 {
                return None;
            }
            let mut seen = std::collections::HashSet::new();
            output.extend_from_slice(&0_u32.to_le_bytes());
            for _ in 0..count {
                let id = reader.integer()?;
                if seen.insert(id) {
                    output.extend_from_slice(&id.to_ne_bytes());
                }
            }
            output[..4].copy_from_slice(&(seen.len() as u32).to_le_bytes());
        }
        _ => return None,
    }
    (reader.offset == input.len() && output.len() <= W386_MAX_INPUT).then_some(output)
}

/// Returns bytes written, -required for a short buffer, or -1 for invalid input.
/// All valid outputs have at least two bytes, keeping -1 unambiguous. No negative
/// return writes output and no NUL terminator is appended.
///
/// # Safety
/// Both pointers must be nonnull and designate live readable/writable allocations
/// of the declared sizes. Ranges must not overlap and sizes must be <= MAX_INPUT.
/// The checks cannot establish allocation ownership or liveness. Alignment is one.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_w386_tmux_drain_stop_runners(
    operation: u8,
    flags: u8,
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !(1..=5).contains(&operation)
            || flags > 1
            || (operation != 2 && flags != 0)
            || input.is_null()
            || out_buf.is_null()
            || input_len > W386_MAX_INPUT
            || out_cap > W386_MAX_INPUT
        {
            return -1;
        }
        let Some(input_end) = (input as usize).checked_add(input_len) else {
            return -1;
        };
        let Some(output_end) = (out_buf as usize).checked_add(out_cap) else {
            return -1;
        };
        if input_len != 0
            && out_cap != 0
            && (input as usize) < output_end
            && (out_buf as usize) < input_end
        {
            return -1;
        }
        let bytes = unsafe { std::slice::from_raw_parts(input, input_len) };
        let Some(result) = w386_compute(operation, flags, bytes) else {
            return -1;
        };
        if out_cap < result.len() {
            return -(result.len() as isize);
        }
        unsafe { std::ptr::copy_nonoverlapping(result.as_ptr(), out_buf, result.len()) };
        result.len() as isize
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod w386_tests {
    use super::*;

    fn strings(values: &[&[u8]]) -> Vec<u8> {
        let mut frame = (values.len() as u32).to_le_bytes().to_vec();
        for value in values {
            w356_string(&mut frame, value);
        }
        frame
    }

    fn fixtures() -> Vec<(u8, u8, Vec<u8>, Vec<u8>)> {
        let mut token = Vec::new();
        w356_string(&mut token, b"AZ09_-");
        let mut groups = 4_u32.to_le_bytes().to_vec();
        let mut unique = 3_u32.to_le_bytes().to_vec();
        for id in [i64::MIN, 0, i64::MIN, i64::MAX] {
            groups.extend_from_slice(&id.to_ne_bytes());
        }
        for id in [i64::MIN, 0, i64::MAX] {
            unique.extend_from_slice(&id.to_ne_bytes());
        }
        vec![
            (1, 0, token, vec![1, 1]),
            (
                2,
                1,
                strings(&[b"monitor", b"irc_scraper", b"releases", b"MONITOR"]),
                vec![4, 0, 0, 0, 1, 1, 0, 0],
            ),
            (
                3,
                0,
                [10_i64.to_ne_bytes(), 7_i64.to_ne_bytes()].concat(),
                3_i64.to_ne_bytes().to_vec(),
            ),
            (
                4,
                0,
                strings(&[b"", b"abc", b"\0abc", b"\xffx", "日本語".as_bytes()]),
                strings(&[b"", b"a", b"\0", b"\xff", b"\xe6"]),
            ),
            (5, 0, groups, unique),
        ]
    }

    #[test]
    fn w386_literal_contracts() {
        for (op, flags, input, output) in fixtures() {
            assert_eq!(w386_compute(op, flags, &input), Some(output));
        }
        for token in [
            b"".as_slice(),
            b"a b",
            b"a\n",
            b"a\0",
            b"\xff",
            &[b'a'; 129],
        ] {
            let mut input = Vec::new();
            w356_string(&mut input, token);
            assert_eq!(
                w386_compute(1, 0, &input),
                Some(vec![u8::from(!token.is_empty()), 0])
            );
        }
        let mut token = Vec::new();
        w356_string(&mut token, &[b'a'; 128]);
        assert_eq!(w386_compute(1, 0, &token), Some(vec![1, 1]));
        for (limit, queued, expected) in [
            (0_i64, i64::MAX, 1000_i64),
            (-1, -1, 1000),
            (10, 11, 0),
            (i64::MAX, i64::MIN, 1000),
            (i64::MAX, i64::MAX, 0),
            (10, 0, 10),
        ] {
            let input = [limit.to_ne_bytes(), queued.to_ne_bytes()].concat();
            assert_eq!(
                w386_compute(3, 0, &input),
                Some(expected.to_ne_bytes().to_vec())
            );
        }
        for op in [2, 4, 5] {
            assert_eq!(
                w386_compute(op, 0, &0_u32.to_le_bytes()),
                Some(0_u32.to_le_bytes().to_vec())
            );
        }
    }

    #[test]
    fn w386_ffi_validation_and_retry_never_write_on_negative() {
        for (op, flags, input, expected) in fixtures() {
            let mut out = [90_u8; 256];
            let call = |p, len, o, cap, flag| unsafe {
                nntmux_w386_tmux_drain_stop_runners(op, flag, p, len, o, cap)
            };
            let invalid = [
                (std::ptr::null(), input.len(), out.as_mut_ptr(), 256),
                (input.as_ptr(), input.len(), std::ptr::null_mut(), 256),
                (input.as_ptr(), W386_MAX_INPUT + 1, out.as_mut_ptr(), 256),
                (
                    input.as_ptr(),
                    input.len(),
                    out.as_mut_ptr(),
                    W386_MAX_INPUT + 1,
                ),
                (usize::MAX as *const u8, input.len(), out.as_mut_ptr(), 256),
                (input.as_ptr(), input.len(), usize::MAX as *mut u8, 256),
                (out.as_ptr(), input.len(), out.as_mut_ptr(), 256),
            ];
            for (p, len, o, cap) in invalid {
                assert_eq!(call(p, len, o, cap, flags), -1);
                assert_eq!(out, [90; 256]);
            }
            for len in 0..input.len() {
                assert_eq!(call(input.as_ptr(), len, out.as_mut_ptr(), 256, flags), -1);
                assert_eq!(out, [90; 256]);
            }
            let mut trailing = input.clone();
            trailing.push(0);
            assert_eq!(
                call(
                    trailing.as_ptr(),
                    trailing.len(),
                    out.as_mut_ptr(),
                    256,
                    flags
                ),
                -1
            );
            for flag in 0..=255 {
                if flag > 1 || (op != 2 && flag != 0) {
                    assert_eq!(
                        call(input.as_ptr(), input.len(), out.as_mut_ptr(), 256, flag),
                        -1
                    );
                    assert_eq!(out, [90; 256]);
                }
            }
            for cap in [0, 1, expected.len() - 1] {
                assert_eq!(
                    call(input.as_ptr(), input.len(), out.as_mut_ptr(), cap, flags),
                    -(expected.len() as isize)
                );
                assert_eq!(out, [90; 256]);
            }
            assert_eq!(
                call(
                    input.as_ptr(),
                    input.len(),
                    out.as_mut_ptr(),
                    expected.len(),
                    flags
                ),
                expected.len() as isize
            );
            assert_eq!(&out[..expected.len()], expected.as_slice());
            assert!(out[expected.len()..].iter().all(|b| *b == 90));
        }
        for op in [0, 6, 255] {
            assert_eq!(w386_compute(op, 0, b""), None);
        }
        for op in [1, 2, 4, 5] {
            assert_eq!(w386_compute(op, 0, &u32::MAX.to_le_bytes()), None);
        }
    }
}
