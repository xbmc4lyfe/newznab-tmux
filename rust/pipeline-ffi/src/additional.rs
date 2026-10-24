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

