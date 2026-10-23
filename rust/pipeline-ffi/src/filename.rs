use std::slice;

use crate::{OutCursor, find_nul};

#[inline]
fn eq_ci(a: &[u8], b: &[u8]) -> bool {
    a.eq_ignore_ascii_case(b)
}

#[inline]
fn starts_with_ci(haystack: &[u8], prefix: &[u8]) -> bool {
    haystack.len() >= prefix.len() && haystack[..prefix.len()].eq_ignore_ascii_case(prefix)
}

#[inline]
fn contains_nzbsplit(bytes: &[u8]) -> bool {
    if bytes.len() < 12 {
        return false;
    }
    let mut i = 0;
    while i + 12 <= bytes.len() {
        if bytes[i] == b'_'
            && bytes[i + 1] == b'_'
            && bytes[i..i + 12].eq_ignore_ascii_case(b"__nzbsplit__")
        {
            return true;
        }
        i += 1;
    }
    false
}

#[inline]
fn is_vol_range(ext: &[u8]) -> bool {
    if ext.len() < 6 || !ext[..3].eq_ignore_ascii_case(b"vol") {
        return false;
    }
    let mut i = 3;
    while i < ext.len() && ext[i].is_ascii_digit() {
        i += 1;
    }
    if i == 3 || i >= ext.len() || !matches!(ext[i], b'+' | b'-') {
        return false;
    }
    i += 1;
    let second_start = i;
    while i < ext.len() && ext[i].is_ascii_digit() {
        i += 1;
    }
    i > second_start && i == ext.len()
}

#[inline]
fn is_single_dot_wrapper_ext(ext: &[u8]) -> bool {
    if ext.is_empty() {
        return false;
    }
    let mut lower_buf = [0u8; 8];
    if ext.len() <= 8 {
        for (i, &b) in ext.iter().enumerate() {
            lower_buf[i] = b.to_ascii_lowercase();
        }
        let lower = &lower_buf[..ext.len()];
        if matches!(
            lower,
            b"rar"
                | b"zip"
                | b"7z"
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
                | b"nfo"
                | b"sfv"
                | b"nzb"
                | b"srr"
                | b"srs"
        ) {
            return true;
        }
        if (3..=5).contains(&lower.len())
            && lower[0] == b'r'
            && lower[1..].iter().all(|b| b.is_ascii_digit())
        {
            return true;
        }
        if lower.len() == 3 && lower[0] == b'z' && lower[1..].iter().all(|b| b.is_ascii_digit()) {
            return true;
        }
        if lower.len() == 3 && lower.iter().all(|b| b.is_ascii_digit()) {
            return true;
        }
    }
    if ext.len() >= 4
        && ext[..4].eq_ignore_ascii_case(b"part")
        && ext[4..].iter().all(|b| b.is_ascii_digit())
    {
        return true;
    }
    is_vol_range(ext)
}

#[inline]
fn is_video_ext(lower: &[u8]) -> bool {
    matches!(
        lower,
        b"mkv"
            | b"avi"
            | b"mp4"
            | b"m4v"
            | b"wmv"
            | b"mpg"
            | b"mpeg"
            | b"mov"
            | b"ts"
            | b"m2ts"
            | b"vob"
            | b"divx"
            | b"flv"
            | b"webm"
            | b"ogv"
            | b"3gp"
            | b"asf"
            | b"rm"
            | b"rmvb"
            | b"f4v"
    )
}

#[inline]
fn is_audio_ext(lower: &[u8]) -> bool {
    matches!(
        lower,
        b"mp3"
            | b"flac"
            | b"m4a"
            | b"aac"
            | b"ogg"
            | b"wav"
            | b"wma"
            | b"ape"
            | b"opus"
            | b"mka"
            | b"ac3"
            | b"dts"
            | b"eac3"
            | b"truehd"
            | b"mpc"
            | b"shn"
            | b"tak"
            | b"tta"
            | b"wv"
    )
}

#[inline]
fn is_image_ext(lower: &[u8]) -> bool {
    matches!(
        lower,
        b"nfo"
            | b"sfv"
            | b"nzb"
            | b"srr"
            | b"srs"
            | b"jpg"
            | b"jpeg"
            | b"png"
            | b"gif"
            | b"bmp"
            | b"tif"
            | b"tiff"
            | b"webp"
            | b"pdf"
            | b"txt"
            | b"diz"
            | b"md5"
            | b"sha1"
            | b"cue"
            | b"log"
    )
}

#[inline]
fn is_ebook_ext(lower: &[u8]) -> bool {
    matches!(
        lower,
        b"epub"
            | b"mobi"
            | b"azw"
            | b"azw3"
            | b"pdf"
            | b"djvu"
            | b"cbr"
            | b"cbz"
            | b"fb2"
            | b"lit"
            | b"prc"
            | b"opf"
    )
}

#[inline]
fn is_gameapp_ext(lower: &[u8]) -> bool {
    matches!(
        lower,
        b"iso"
            | b"bin"
            | b"cue"
            | b"mdf"
            | b"mds"
            | b"nrg"
            | b"img"
            | b"ccd"
            | b"sub"
            | b"exe"
            | b"msi"
            | b"dmg"
            | b"pkg"
            | b"apk"
            | b"xap"
            | b"appx"
            | b"deb"
            | b"rpm"
    )
}

#[inline]
fn is_subtitle_ext(lower: &[u8]) -> bool {
    matches!(
        lower,
        b"srt" | b"sub" | b"idx" | b"ass" | b"ssa" | b"vtt" | b"sup"
    )
}

#[inline]
fn is_extra_payload_ext(lower: &[u8]) -> bool {
    matches!(
        lower,
        b"doc"
            | b"docx"
            | b"rtf"
            | b"xls"
            | b"xlsx"
            | b"ppt"
            | b"pptx"
            | b"odt"
            | b"m3u"
            | b"m3u8"
            | b"txt"
            | b"m4b"
            | b"aif"
            | b"aiff"
            | b"amr"
            | b"m2v"
            | b"m1v"
            | b"mpv"
            | b"mpgv"
            | b"qt"
            | b"mp2"
            | b"ra"
            | b"w64"
            | b"ifo"
            | b"mks"
            | b"ogm"
    )
}

#[inline]
fn is_preserved_codec_ext(lower: &[u8]) -> bool {
    matches!(
        lower,
        b"ts"
            | b"divx"
            | b"wmv"
            | b"mpg"
            | b"mpeg"
            | b"rm"
            | b"rmvb"
            | b"m1v"
            | b"m2v"
            | b"mpv"
            | b"mpgv"
            | b"mp2"
            | b"m4b"
            | b"aif"
            | b"aiff"
            | b"amr"
            | b"ra"
            | b"w64"
    )
}

#[inline]
fn lower_ext<'a>(ext: &[u8], buf: &'a mut [u8; 8]) -> Option<&'a [u8]> {
    if ext.is_empty() || ext.len() > 8 {
        return None;
    }
    for (i, &b) in ext.iter().enumerate() {
        buf[i] = b.to_ascii_lowercase();
    }
    Some(&buf[..ext.len()])
}

/// Computes the truncated byte length for `FileNameCleaner::stripKnownFileSuffixes`.
/// Returns `None` if `bytes` ends with `\n` (where PCRE `$` without `/D` could match before `\n`).
pub fn strip_known_file_suffixes_len(
    bytes: &[u8],
    preserve_codec_after_wrapper: bool,
) -> Option<usize> {
    if bytes.ends_with(b"\n") {
        return None;
    }

    let mut end = bytes.len();
    loop {
        let s = &bytes[..end];
        let Some(dot_pos) = s.iter().rposition(|&b| b == b'.') else {
            break;
        };
        let ext = &s[dot_pos + 1..];

        // Check `/\.7z\.\d{2,4}$/i`
        if (2..=4).contains(&ext.len())
            && ext.iter().all(|b| b.is_ascii_digit())
            && dot_pos >= 3
            && s[dot_pos - 3..dot_pos].eq_ignore_ascii_case(b".7z")
        {
            end = dot_pos - 3;
            continue;
        }

        if is_single_dot_wrapper_ext(ext) {
            end = dot_pos;
            continue;
        }

        break;
    }

    let s = &bytes[..end];
    let Some(dot_pos) = s.iter().rposition(|&b| b == b'.') else {
        return Some(end);
    };
    let mut buf = [0u8; 8];
    let Some(lower) = lower_ext(&s[dot_pos + 1..], &mut buf) else {
        return Some(end);
    };

    if preserve_codec_after_wrapper
        && end != bytes.len()
        && (is_audio_ext(lower) || is_preserved_codec_ext(lower))
    {
        return Some(end);
    }

    if is_video_ext(lower)
        || is_audio_ext(lower)
        || is_image_ext(lower)
        || is_ebook_ext(lower)
        || is_gameapp_ext(lower)
        || is_subtitle_ext(lower)
        || is_extra_payload_ext(lower)
    {
        return Some(dot_pos);
    }

    Some(end)
}

#[inline]
fn is_trim_char(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B | b'.' | b'-' | b'_')
}

#[inline]
fn looks_like_structural_junk(mut s: &[u8]) -> bool {
    while let Some(&first) = s.first() {
        if is_trim_char(first) {
            s = &s[1..];
        } else {
            break;
        }
    }
    while let Some(&last) = s.last() {
        if is_trim_char(last) {
            s = &s[..s.len() - 1];
        } else {
            break;
        }
    }
    if s.is_empty() {
        return true;
    }
    // `/^\d{1,3}$/`
    if (1..=3).contains(&s.len()) && s.iter().all(|b| b.is_ascii_digit()) {
        return true;
    }
    // `/^(?:audio|video)[._-]?ts$/i`
    if (s.len() == 7 || s.len() == 8)
        && (starts_with_ci(s, b"audio") || starts_with_ci(s, b"video"))
        && eq_ci(&s[s.len() - 2..], b"ts")
        && (s.len() == 7 || matches!(s[5], b'.' | b'_' | b'-'))
    {
        return true;
    }
    // `/^vts[._-]?\d{1,2}[._-]?\d{1,2}$/i`
    if s.len() >= 5 && s.len() <= 9 && starts_with_ci(s, b"vts") {
        let mut p = 3;
        if p < s.len() && matches!(s[p], b'.' | b'_' | b'-') {
            p += 1;
        }
        let d1_start = p;
        while p < s.len() && s[p].is_ascii_digit() {
            p += 1;
        }
        let d1_len = p - d1_start;
        if (1..=2).contains(&d1_len) {
            if p < s.len() && matches!(s[p], b'.' | b'_' | b'-') {
                p += 1;
            }
            let d2_start = p;
            while p < s.len() && s[p].is_ascii_digit() {
                p += 1;
            }
            let d2_len = p - d2_start;
            if (1..=2).contains(&d2_len) && p == s.len() {
                return true;
            }
        }
    }
    false
}

#[inline]
fn strip_sample_proof_indicator(s: &[u8]) -> usize {
    // `/[\.\-_](sample|proof|subs?|thumbs?|cover|screens?)[\.\-_]?$/i`
    if s.len() < 4 {
        return s.len();
    }
    let check_end = if matches!(s[s.len() - 1], b'.' | b'-' | b'_') {
        s.len() - 1
    } else {
        s.len()
    };
    let head = &s[..check_end];
    for word in [
        &b"screens"[..],
        b"screen",
        b"sample",
        b"thumbs",
        b"thumb",
        b"proof",
        b"cover",
        b"subs",
        b"sub",
    ] {
        if head.len() > word.len()
            && matches!(head[head.len() - word.len() - 1], b'.' | b'-' | b'_')
            && head[head.len() - word.len()..].eq_ignore_ascii_case(word)
        {
            return head.len() - word.len() - 1;
        }
    }
    s.len()
}

#[inline]
fn strip_archive_and_media_extensions(bytes: &[u8], start: usize, mut end: usize) -> usize {
    if !bytes[start..end].contains(&b'.') {
        return end;
    }

    // 1. `/\.part\d{1,4}\.rar$/i`
    {
        let s = &bytes[start..end];
        if s.len() >= 10 && s[s.len() - 4..].eq_ignore_ascii_case(b".rar") {
            let before_rar = &s[..s.len() - 4];
            if let Some(dot) = before_rar.iter().rposition(|&b| b == b'.') {
                let seg = &before_rar[dot + 1..];
                if (5..=8).contains(&seg.len())
                    && seg[..4].eq_ignore_ascii_case(b"part")
                    && seg[4..].iter().all(|b| b.is_ascii_digit())
                {
                    end = start + dot;
                }
            }
        }
    }
    // 2. `/\.r\d{2,4}$/i`
    {
        let s = &bytes[start..end];
        if let Some(dot) = s.iter().rposition(|&b| b == b'.') {
            let ext = &s[dot + 1..];
            if (3..=5).contains(&ext.len())
                && matches!(ext[0], b'r' | b'R')
                && ext[1..].iter().all(|b| b.is_ascii_digit())
            {
                end = start + dot;
            }
        }
    }
    // 3. `/\.rar$/i`
    if end - start >= 4 && bytes[end - 4..end].eq_ignore_ascii_case(b".rar") {
        end -= 4;
    }
    // 4. `/\.z\d{2}$/i`
    if end - start >= 4
        && bytes[end - 4] == b'.'
        && matches!(bytes[end - 3], b'z' | b'Z')
        && bytes[end - 2].is_ascii_digit()
        && bytes[end - 1].is_ascii_digit()
    {
        end -= 4;
    }
    // 5. `/\.zip$/i`
    if end - start >= 4 && bytes[end - 4..end].eq_ignore_ascii_case(b".zip") {
        end -= 4;
    }
    // 6. `/\.7z\.\d{3}$/i`
    if end - start >= 7
        && bytes[end - 7..end - 3].eq_ignore_ascii_case(b".7z.")
        && bytes[end - 3..end].iter().all(|b| b.is_ascii_digit())
    {
        end -= 7;
    }
    // 7. `/\.7z$/i`
    if end - start >= 3 && bytes[end - 3..end].eq_ignore_ascii_case(b".7z") {
        end -= 3;
    }
    // 8. `/\.vol\d+[\+\-]\d+\.par2?$/i`
    {
        let s = &bytes[start..end];
        let par_len = if s.len() >= 5 && s[s.len() - 5..].eq_ignore_ascii_case(b".par2") {
            5
        } else if s.len() >= 4 && s[s.len() - 4..].eq_ignore_ascii_case(b".par") {
            4
        } else {
            0
        };
        if par_len > 0 {
            let before_par = &s[..s.len() - par_len];
            if let Some(dot) = before_par.iter().rposition(|&b| b == b'.')
                && is_vol_range(&before_par[dot + 1..])
            {
                end = start + dot;
            }
        }
    }
    // 9. `/\.par2?$/i`
    if end - start >= 5 && bytes[end - 5..end].eq_ignore_ascii_case(b".par2") {
        end -= 5;
    } else if end - start >= 4 && bytes[end - 4..end].eq_ignore_ascii_case(b".par") {
        end -= 4;
    }
    // 10. `/\.(tar|gz|bz2|xz|lz|lzma|cab|arj|ace|arc)$/i`
    {
        let s = &bytes[start..end];
        if let Some(dot) = s.iter().rposition(|&b| b == b'.') {
            let mut buf = [0u8; 8];
            if let Some(lower) = lower_ext(&s[dot + 1..], &mut buf)
                && matches!(
                    lower,
                    b"tar"
                        | b"gz"
                        | b"bz2"
                        | b"xz"
                        | b"lz"
                        | b"lzma"
                        | b"cab"
                        | b"arj"
                        | b"ace"
                        | b"arc"
                )
            {
                end = start + dot;
            }
        }
    }
    // 11. `/\.\d{3}$/i`
    if end - start >= 4
        && bytes[end - 4] == b'.'
        && bytes[end - 3..end].iter().all(|b| b.is_ascii_digit())
    {
        end -= 4;
    }

    // Media extensions in exact order: VIDEO, AUDIO, IMAGE, EBOOK, GAMEAPP, SUBTITLE
    for kind in 0..6u8 {
        let s = &bytes[start..end];
        let Some(dot) = s.iter().rposition(|&b| b == b'.') else {
            break;
        };
        let mut buf = [0u8; 8];
        let Some(lower) = lower_ext(&s[dot + 1..], &mut buf) else {
            continue;
        };
        let matched = match kind {
            0 => is_video_ext(lower),
            1 => is_audio_ext(lower),
            2 => is_image_ext(lower),
            3 => is_ebook_ext(lower),
            4 => is_gameapp_ext(lower),
            5 => is_subtitle_ext(lower),
            _ => unreachable!(),
        };
        if matched {
            end = start + dot;
        }
    }

    end
}

#[inline]
fn strip_part_volume_indicator(bytes: &[u8], start: usize, end: usize) -> usize {
    // `/[\.\-_]?(part|vol|cd|dvd|disc|disk)\d*$/i`
    let s = &bytes[start..end];
    let mut w_end = s.len();
    while w_end > 0 && s[w_end - 1].is_ascii_digit() {
        w_end -= 1;
    }
    let head = &s[..w_end];
    for word in [&b"part"[..], b"disc", b"disk", b"vol", b"dvd", b"cd"] {
        if head.len() >= word.len() && head[head.len() - word.len()..].eq_ignore_ascii_case(word) {
            let mut w_start = head.len() - word.len();
            if w_start > 0 && matches!(head[w_start - 1], b'.' | b'-' | b'_') {
                w_start -= 1;
            }
            return start + w_start;
        }
    }
    end
}

/// Computes `FileNameCleaner::computeForMatching` as a packed `(start << 32) | len` span into `bytes`.
/// Returns:
/// - `>= 0`: packed `(start << 32) | len`
/// - `-1`: invalid (`false`)
/// - `-2`: declined to PHP fallback (non-ASCII, control chars, `__NZBSPLIT__`, or trailing slash)
pub fn clean_for_matching_span(bytes: &[u8], preserve_leading_number: bool) -> i64 {
    if bytes.is_empty() {
        return -1;
    }
    for &b in bytes {
        if !(0x20..=0x7E).contains(&b) {
            return -2;
        }
    }
    if bytes[0] == b'.' {
        return -1;
    }
    if contains_nzbsplit(bytes) {
        return -2;
    }
    if matches!(bytes.last(), Some(b'/' | b'\\')) {
        return -2;
    }

    let mut start = match bytes.iter().rposition(|&b| b == b'/' || b == b'\\') {
        Some(pos) => pos + 1,
        None => 0,
    };
    let mut end = bytes.len();

    // `isIgnorableForMatching`
    if end - start >= 4 && bytes[end - 4..end].eq_ignore_ascii_case(b".url") {
        return -1;
    }
    let stem_end = match bytes[start..end].iter().rposition(|&b| b == b'.') {
        Some(pos) => start + pos,
        None => end,
    };
    if looks_like_structural_junk(&bytes[start..stem_end]) {
        return -1;
    }

    // Remove sample/proof indicators
    end = start + strip_sample_proof_indicator(&bytes[start..end]);

    // Remove archive and media extensions
    end = strip_archive_and_media_extensions(bytes, start, end);

    // Remove part/volume indicators
    end = strip_part_volume_indicator(bytes, start, end);

    // Remove leading track numbers: `/^\d{1,3}[\.\-_\s]+(?=[A-Za-z])/`
    if !preserve_leading_number {
        let s = &bytes[start..end];
        let mut d = 0;
        while d < s.len() && s[d].is_ascii_digit() {
            d += 1;
        }
        if (1..=3).contains(&d) {
            let mut p = d;
            while p < s.len()
                && matches!(
                    s[p],
                    b'.' | b'-' | b'_' | b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C
                )
            {
                p += 1;
            }
            if p > d && p < s.len() && s[p].is_ascii_alphabetic() {
                start += p;
            }
        }
    }

    // Trim `" \t\n\r\0\x0B.-_"`
    while start < end && is_trim_char(bytes[start]) {
        start += 1;
    }
    while end > start && is_trim_char(bytes[end - 1]) {
        end -= 1;
    }

    if start == end || looks_like_structural_junk(&bytes[start..end]) {
        return -1;
    }

    ((start as i64) << 32) | ((end - start) as i64)
}

// ============================================================================
// SeasonPackDetector
// ============================================================================

#[inline]
fn is_season_left_sep(b: u8) -> bool {
    matches!(b, b'.' | b'_' | b' ' | b'-' | b'[' | b'(')
}

#[inline]
fn is_season_right_sep(b: u8) -> bool {
    matches!(b, b'.' | b'_' | b' ' | b'-' | b']' | b')')
}

#[inline]
fn matches_disc_tail_and_right_sep(bytes: &[u8], pos: usize) -> bool {
    if pos == bytes.len() || is_season_right_sep(bytes[pos]) {
        return true;
    }
    // `(?:[._ ]?D(?:is[ck])?[._ ]?\d{1,2})?(?=$|[._ \-\])])`
    let mut p = pos;
    if matches!(bytes[p], b'.' | b'_' | b' ') {
        p += 1;
    }
    if p >= bytes.len() || !matches!(bytes[p], b'd' | b'D') {
        return false;
    }
    p += 1;
    if p + 3 <= bytes.len()
        && (bytes[p..p + 3].eq_ignore_ascii_case(b"isc")
            || bytes[p..p + 3].eq_ignore_ascii_case(b"isk"))
    {
        p += 3;
    }
    if p < bytes.len() && matches!(bytes[p], b'.' | b'_' | b' ') {
        p += 1;
    }
    let d_start = p;
    while p < bytes.len() && bytes[p].is_ascii_digit() && p - d_start < 2 {
        p += 1;
    }
    for d_len in (1..=(p - d_start)).rev() {
        let end = d_start + d_len;
        if end == bytes.len() || is_season_right_sep(bytes[end]) {
            return true;
        }
    }
    false
}

/// Matches `SeasonPackDetector::SEASON_TOKEN_REGEX`:
/// `/(?:^|[._ \-\[(])S\d{1,4}(?:[._ ]?D(?:is[ck])?[._ ]?\d{1,2})?(?=$|[._ \-\])])/i`
pub fn matches_season_token(bytes: &[u8]) -> bool {
    for i in 0..bytes.len() {
        if !matches!(bytes[i], b's' | b'S') {
            continue;
        }
        if i > 0 && !is_season_left_sep(bytes[i - 1]) {
            continue;
        }
        let d_start = i + 1;
        let mut d_end = d_start;
        while d_end < bytes.len() && bytes[d_end].is_ascii_digit() && d_end - d_start < 4 {
            d_end += 1;
        }
        for d_len in (1..=(d_end - d_start)).rev() {
            if matches_disc_tail_and_right_sep(bytes, d_start + d_len) {
                return true;
            }
        }
    }
    false
}

/// Matches `SeasonPackDetector::ADMISSION_SEASON_TOKEN_REGEX`:
/// `/(?:^|[._ \-\[(])S\d{1,4}(?!\d)(?![._ ]?E\d)(?:[._ ]?D(?:is[ck])?[._ ]?\d{1,2})?(?=$|[._ \-\])])/i`
pub fn matches_admission_season_token(bytes: &[u8]) -> bool {
    for i in 0..bytes.len() {
        if !matches!(bytes[i], b's' | b'S') {
            continue;
        }
        if i > 0 && !is_season_left_sep(bytes[i - 1]) {
            continue;
        }
        let d_start = i + 1;
        let mut d_end = d_start;
        while d_end < bytes.len() && bytes[d_end].is_ascii_digit() {
            d_end += 1;
        }
        let d_len = d_end - d_start;
        if !(1..=4).contains(&d_len) {
            continue;
        }
        // `(?![._ ]?E\d)`
        if d_end + 1 < bytes.len()
            && matches!(bytes[d_end], b'e' | b'E')
            && bytes[d_end + 1].is_ascii_digit()
        {
            continue;
        }
        if d_end + 2 < bytes.len()
            && matches!(bytes[d_end], b'.' | b'_' | b' ')
            && matches!(bytes[d_end + 1], b'e' | b'E')
            && bytes[d_end + 2].is_ascii_digit()
        {
            continue;
        }
        if matches_disc_tail_and_right_sep(bytes, d_end) {
            return true;
        }
    }
    false
}

/// Matches `SeasonPackDetector::SEASON_WORD_REGEX`:
/// `/(?:^|[._ \-\[(])Seasons?[._ ]?\d{1,2}(?=$|[._ \-\])])/i`
pub fn matches_season_word(bytes: &[u8]) -> bool {
    if bytes.len() < 7 {
        return false;
    }
    for i in 0..=bytes.len() - 7 {
        if i > 0 && !is_season_left_sep(bytes[i - 1]) {
            continue;
        }
        if !bytes[i..i + 6].eq_ignore_ascii_case(b"season") {
            continue;
        }
        let mut p = i + 6;
        if p < bytes.len() && matches!(bytes[p], b's' | b'S') {
            p += 1;
        }
        if p < bytes.len() && matches!(bytes[p], b'.' | b'_' | b' ') {
            p += 1;
        }
        let d_start = p;
        while p < bytes.len() && bytes[p].is_ascii_digit() && p - d_start < 2 {
            p += 1;
        }
        for d_len in (1..=(p - d_start)).rev() {
            let end = d_start + d_len;
            if end == bytes.len() || is_season_right_sep(bytes[end]) {
                return true;
            }
        }
    }
    false
}

/// Matches `SeasonPackDetector::COMPLETE_REGEX`:
/// `/(?:^|[._ \-\[(])Complete[._ ](?:Series|Seasons?)(?=$|[._ \-\])])/i`
pub fn matches_complete_pack(bytes: &[u8]) -> bool {
    if bytes.len() < 15 {
        return false;
    }
    for i in 0..=bytes.len() - 15 {
        if i > 0 && !is_season_left_sep(bytes[i - 1]) {
            continue;
        }
        if !bytes[i..i + 8].eq_ignore_ascii_case(b"complete") {
            continue;
        }
        if !matches!(bytes[i + 8], b'.' | b'_' | b' ') {
            continue;
        }
        let tail = &bytes[i + 9..];
        for word in [&b"seasons"[..], b"season", b"series"] {
            if tail.len() >= word.len() && tail[..word.len()].eq_ignore_ascii_case(word) {
                let end = i + 9 + word.len();
                if end == bytes.len() || is_season_right_sep(bytes[end]) {
                    return true;
                }
            }
        }
    }
    false
}

/// Matches `SeasonPackDetector::EPISODE_REGEX`:
/// `/(?:^|[^a-z0-9])(?:S\d+[._ ]?E\d+|\d{1,2}x\d{2,}(?=$|[^a-z0-9]|[exs]\d)|Episode[._ ]?\d{1,4}(?!\d)|EP?\d{2,3}(?![0-9a-z]))/i`
pub fn is_episode_name(bytes: &[u8]) -> bool {
    for i in 0..bytes.len() {
        if i > 0 && bytes[i - 1].is_ascii_alphanumeric() {
            continue;
        }
        let b = bytes[i];
        // 1. `S\d+[._ ]?E\d+`
        if matches!(b, b's' | b'S') {
            let mut p = i + 1;
            while p < bytes.len() && bytes[p].is_ascii_digit() {
                p += 1;
            }
            if p > i + 1 {
                if p < bytes.len() && matches!(bytes[p], b'.' | b'_' | b' ') {
                    p += 1;
                }
                if p + 1 < bytes.len()
                    && matches!(bytes[p], b'e' | b'E')
                    && bytes[p + 1].is_ascii_digit()
                {
                    return true;
                }
            }
        }
        // 2. `\d{1,2}x\d{2,}(?=$|[^a-z0-9]|[exs]\d)`
        if b.is_ascii_digit() {
            let mut p = i + 1;
            while p < bytes.len() && bytes[p].is_ascii_digit() {
                p += 1;
            }
            let d1_len = p - i;
            if (1..=2).contains(&d1_len) && p < bytes.len() && matches!(bytes[p], b'x' | b'X') {
                p += 1;
                let d2_start = p;
                while p < bytes.len() && bytes[p].is_ascii_digit() {
                    p += 1;
                }
                if p - d2_start >= 2 {
                    let ok = p == bytes.len()
                        || !bytes[p].is_ascii_alphanumeric()
                        || (matches!(bytes[p], b'e' | b'E' | b'x' | b'X' | b's' | b'S')
                            && bytes.get(p + 1).is_some_and(|c| c.is_ascii_digit()));
                    if ok {
                        return true;
                    }
                }
            }
        }
        // 3. `Episode[._ ]?\d{1,4}(?!\d)`
        // 4. `EP?\d{2,3}(?![0-9a-z])`
        if matches!(b, b'e' | b'E') {
            if bytes[i..].len() >= 8 && bytes[i..i + 7].eq_ignore_ascii_case(b"episode") {
                let mut p = i + 7;
                if p < bytes.len() && matches!(bytes[p], b'.' | b'_' | b' ') {
                    p += 1;
                }
                let d_start = p;
                while p < bytes.len() && bytes[p].is_ascii_digit() {
                    p += 1;
                }
                if (1..=4).contains(&(p - d_start)) {
                    return true;
                }
            }
            let mut p = i + 1;
            if p < bytes.len() && matches!(bytes[p], b'p' | b'P') {
                p += 1;
            }
            let d_start = p;
            while p < bytes.len() && bytes[p].is_ascii_digit() {
                p += 1;
            }
            if (2..=3).contains(&(p - d_start))
                && (p == bytes.len() || !bytes[p].is_ascii_alphanumeric())
            {
                return true;
            }
        }
    }
    false
}

/// Matches `SeasonPackDetector::isSeasonPack`.
#[inline]
pub fn is_season_pack(bytes: &[u8]) -> bool {
    if is_episode_name(bytes) {
        return false;
    }
    matches_season_token(bytes) || matches_season_word(bytes) || matches_complete_pack(bytes)
}

#[inline]
fn has_explicit_pack_marker(base: &[u8]) -> bool {
    matches_admission_season_token(base)
        || matches_season_word(base)
        || matches_complete_pack(base)
}

/// Matches `SeasonPackDetector::SAMPLE_REGEX`:
/// `/(?:^|[._ \-])(?:sample|proof)(?:$|[._ \-])/i`
pub fn matches_sample_regex(bytes: &[u8]) -> bool {
    for i in 0..bytes.len() {
        if i > 0 && !matches!(bytes[i - 1], b'.' | b'_' | b' ' | b'-') {
            continue;
        }
        let tail = &bytes[i..];
        for word in [&b"sample"[..], b"proof"] {
            if tail.len() >= word.len() && tail[..word.len()].eq_ignore_ascii_case(word) {
                let end = i + word.len();
                if end == bytes.len() || matches!(bytes[end], b'.' | b'_' | b' ' | b'-') {
                    return true;
                }
            }
        }
    }
    false
}

#[inline]
fn strip_season_pack_extension_once(base: &[u8]) -> usize {
    // `/(?:\.part\d+)?\.(?:rar|r\d{2,3}|zip|7z|\d{3}|par2|vol\d+[+-]\d+|mkv|mp4|avi|m4v|mov|wmv|mpg|mpeg|ts|m2ts|vob|webm|flv|divx|nfo|sfv|srt|sub|idx|ass|ssa|jpg|png)$/i`
    let Some(dot) = base.iter().rposition(|&b| b == b'.') else {
        return base.len();
    };
    let ext = &base[dot + 1..];
    let mut matched = is_vol_range(ext);
    if !matched {
        let mut buf = [0u8; 8];
        if let Some(lower) = lower_ext(ext, &mut buf) {
            matched = matches!(
                lower,
                b"rar"
                    | b"zip"
                    | b"7z"
                    | b"par2"
                    | b"mkv"
                    | b"mp4"
                    | b"avi"
                    | b"m4v"
                    | b"mov"
                    | b"wmv"
                    | b"mpg"
                    | b"mpeg"
                    | b"ts"
                    | b"m2ts"
                    | b"vob"
                    | b"webm"
                    | b"flv"
                    | b"divx"
                    | b"nfo"
                    | b"sfv"
                    | b"srt"
                    | b"sub"
                    | b"idx"
                    | b"ass"
                    | b"ssa"
                    | b"jpg"
                    | b"png"
            ) || ((3..=4).contains(&lower.len())
                && lower[0] == b'r'
                && lower[1..].iter().all(|b| b.is_ascii_digit()))
                || (lower.len() == 3 && lower.iter().all(|b| b.is_ascii_digit()));
        }
    }
    if !matched {
        return base.len();
    }
    // Check optional `(?:\.part\d+)?` before `dot`
    let head = &base[..dot];
    if let Some(part_dot) = head.iter().rposition(|&b| b == b'.') {
        let part_seg = &head[part_dot + 1..];
        if part_seg.len() >= 5
            && part_seg[..4].eq_ignore_ascii_case(b"part")
            && part_seg[4..].iter().all(|b| b.is_ascii_digit())
        {
            return part_dot;
        }
    }
    dot
}

/// Computes `SeasonPackDetector::baseName(name)` as a subslice of `name`.
pub fn season_pack_base_name(mut s: &[u8]) -> &[u8] {
    while let Some(&first) = s.first() {
        if matches!(first, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B) {
            s = &s[1..];
        } else {
            break;
        }
    }
    while let Some(&last) = s.last() {
        if matches!(last, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B) {
            s = &s[..s.len() - 1];
        } else {
            break;
        }
    }
    // PHP `basename(str_replace('\\', '/', $s))` strips trailing slashes (unless all slashes)
    while s.len() > 1 && matches!(s[s.len() - 1], b'/' | b'\\') {
        s = &s[..s.len() - 1];
    }
    if let Some(slash) = s.iter().rposition(|&b| b == b'/' || b == b'\\') {
        s = &s[slash + 1..];
    }
    for _ in 0..3 {
        let new_len = strip_season_pack_extension_once(s);
        if new_len == s.len() {
            break;
        }
        s = &s[..new_len];
    }
    while let Some(&first) = s.first() {
        if matches!(first, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B | b'.') {
            s = &s[1..];
        } else {
            break;
        }
    }
    while let Some(&last) = s.last() {
        if matches!(last, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B | b'.') {
            s = &s[..s.len() - 1];
        } else {
            break;
        }
    }
    s
}

/// Matches `SeasonPackDetector::RELEASE_INFO_TOKEN_REGEX`.
pub fn is_release_info_token(token: &[u8]) -> bool {
    if token.len() < 2 || token.len() > 12 {
        return false;
    }
    let mut buf = [0u8; 12];
    for (i, &b) in token.iter().enumerate() {
        buf[i] = b.to_ascii_lowercase();
    }
    let lower = &buf[..token.len()];

    // `\d{3,4}[pi]`
    if (4..=5).contains(&lower.len())
        && matches!(lower[lower.len() - 1], b'p' | b'i')
        && lower[..lower.len() - 1].iter().all(|b| b.is_ascii_digit())
    {
        return true;
    }
    // `aac\d*`, `ddp?\d*`, `repack\d*`
    if lower.starts_with(b"aac") && lower[3..].iter().all(|b| b.is_ascii_digit()) {
        return true;
    }
    if lower.starts_with(b"ddp") && lower[3..].iter().all(|b| b.is_ascii_digit()) {
        return true;
    }
    if lower.starts_with(b"dd") && lower[2..].iter().all(|b| b.is_ascii_digit()) {
        return true;
    }
    if lower.starts_with(b"repack") && lower[6..].iter().all(|b| b.is_ascii_digit()) {
        return true;
    }

    matches!(
        lower,
        b"4k"
            | b"uhd"
            | b"hdr"
            | b"hdr10"
            | b"hdrplus"
            | b"hdr10plus"
            | b"dv"
            | b"sdr"
            | b"web"
            | b"webrip"
            | b"webdl"
            | b"bluray"
            | b"blu"
            | b"bd"
            | b"bdrip"
            | b"bdremux"
            | b"brrip"
            | b"remux"
            | b"dvd"
            | b"dvdrip"
            | b"dvdr"
            | b"dvd5"
            | b"dvd9"
            | b"hdtv"
            | b"pdtv"
            | b"sdtv"
            | b"hdrip"
            | b"dsr"
            | b"amzn"
            | b"nf"
            | b"dsnp"
            | b"hmax"
            | b"atvp"
            | b"hulu"
            | b"pcok"
            | b"pmtp"
            | b"stan"
            | b"crav"
            | b"x264"
            | b"x265"
            | b"h264"
            | b"h265"
            | b"hevc"
            | b"avc"
            | b"xvid"
            | b"divx"
            | b"10bit"
            | b"8bit"
            | b"ac3"
            | b"eac3"
            | b"dts"
            | b"truehd"
            | b"atmos"
            | b"flac"
            | b"proper"
            | b"rerip"
            | b"internal"
            | b"multi"
            | b"dual"
            | b"subbed"
            | b"dubbed"
            | b"extended"
            | b"uncut"
            | b"german"
            | b"french"
            | b"spanish"
            | b"italian"
            | b"dutch"
            | b"nordic"
            | b"swedish"
            | b"danish"
            | b"finnish"
            | b"norwegian"
            | b"polish"
            | b"russian"
            | b"hindi"
            | b"japanese"
            | b"korean"
    )
}

#[inline]
fn has_x_episode_continuation(rest: &[u8]) -> bool {
    // `/(?:^|[^a-z0-9])(x\d+[^._ \-\[\](){}]*)/i`
    let mut i = 0;
    while i < rest.len() {
        if (i == 0 || !rest[i - 1].is_ascii_alphanumeric())
            && matches!(rest[i], b'x' | b'X')
            && rest.get(i + 1).is_some_and(|b| b.is_ascii_digit())
        {
            let mut end = i + 2;
            while end < rest.len()
                && !matches!(
                    rest[end],
                    b'.' | b'_' | b' ' | b'-' | b'[' | b']' | b'(' | b')' | b'{' | b'}'
                )
            {
                end += 1;
            }
            if !is_release_info_token(&rest[i..end]) {
                return true;
            }
            i = end;
        } else {
            i += 1;
        }
    }
    false
}

#[inline]
fn has_extra_e_marker(rest: &[u8]) -> bool {
    // `/(?:^|[^a-z0-9])(?:S\d+[._ ]?E\d+|E(?:pisode|P)?[._ ]?\d+)/i`
    for i in 0..rest.len() {
        if i > 0 && rest[i - 1].is_ascii_alphanumeric() {
            continue;
        }
        if matches!(rest[i], b's' | b'S') {
            let mut p = i + 1;
            while p < rest.len() && rest[p].is_ascii_digit() {
                p += 1;
            }
            if p > i + 1 {
                if p < rest.len() && matches!(rest[p], b'.' | b'_' | b' ') {
                    p += 1;
                }
                if p + 1 < rest.len()
                    && matches!(rest[p], b'e' | b'E')
                    && rest[p + 1].is_ascii_digit()
                {
                    return true;
                }
            }
        }
        if matches!(rest[i], b'e' | b'E') {
            let mut p = i + 1;
            if rest[p..].len() >= 6 && rest[p..p + 6].eq_ignore_ascii_case(b"pisode") {
                p += 6;
            } else if p < rest.len() && matches!(rest[p], b'p' | b'P') {
                p += 1;
            }
            if p < rest.len() && matches!(rest[p], b'.' | b'_' | b' ') {
                p += 1;
            }
            if p < rest.len() && rest[p].is_ascii_digit() {
                return true;
            }
        }
    }
    false
}

#[inline]
fn has_range_continuation(rest: &[u8]) -> bool {
    // `/^[._ ]*(?:[-+&,]|–|—|(?:to|through)[._ ]+)[._ ]*\d+(?=$|[^a-z0-9])/i`
    let mut p = 0;
    while p < rest.len() && matches!(rest[p], b'.' | b'_' | b' ') {
        p += 1;
    }
    if p >= rest.len() {
        return false;
    }
    if matches!(rest[p], b'-' | b'+' | b'&' | b',') {
        p += 1;
    } else if rest[p..].starts_with("\u{2013}".as_bytes())
        || rest[p..].starts_with("\u{2014}".as_bytes())
    {
        p += 3;
    } else if starts_with_ci(&rest[p..], b"through")
        && rest
            .get(p + 7)
            .is_some_and(|&b| matches!(b, b'.' | b'_' | b' '))
    {
        p += 8;
    } else if starts_with_ci(&rest[p..], b"to")
        && rest
            .get(p + 2)
            .is_some_and(|&b| matches!(b, b'.' | b'_' | b' '))
    {
        p += 3;
    } else {
        return false;
    }
    while p < rest.len() && matches!(rest[p], b'.' | b'_' | b' ') {
        p += 1;
    }
    let d_start = p;
    while p < rest.len() && rest[p].is_ascii_digit() {
        p += 1;
    }
    p > d_start && (p == rest.len() || !rest[p].is_ascii_alphanumeric())
}

#[inline]
fn parse_u32_ascii(digits: &[u8]) -> u32 {
    let mut val = 0u32;
    for &b in digits {
        val = val.wrapping_mul(10).wrapping_add((b - b'0') as u32);
    }
    val
}

fn write_normalized_prefix_key(prefix: &[u8], out: &mut OutCursor<'_>) -> bool {
    // `strtolower(trim((string) preg_replace('/[._ \-]+/', '.', $prefix), '.'))`
    let mut s = prefix;
    while let Some(&first) = s.first() {
        if matches!(first, b'.' | b'_' | b' ' | b'-') {
            s = &s[1..];
        } else {
            break;
        }
    }
    while let Some(&last) = s.last() {
        if matches!(last, b'.' | b'_' | b' ' | b'-') {
            s = &s[..s.len() - 1];
        } else {
            break;
        }
    }
    if s.is_empty() {
        return false;
    }
    let mut in_sep = false;
    for &b in s {
        if matches!(b, b'.' | b'_' | b' ' | b'-') {
            in_sep = true;
        } else {
            if in_sep {
                out.push(b'.');
                in_sep = false;
            }
            out.push(b.to_ascii_lowercase());
        }
    }
    true
}

fn write_u32_decimal(mut n: u32, out: &mut OutCursor<'_>) {
    if n == 0 {
        out.push(b'0');
        return;
    }
    let mut buf = [0u8; 10];
    let mut pos = 10;
    while n > 0 {
        pos -= 1;
        buf[pos] = b'0' + (n % 10) as u8;
        n /= 10;
    }
    out.extend(&buf[pos..]);
}

/// Computes `SeasonPackDetector::episodeIdentity(name)` into `out`.
/// Returns `true` if an identity was written, `false` if `episodeIdentity` is `null`.
pub(crate) fn write_episode_identity(name: &[u8], out: &mut OutCursor<'_>) -> bool {
    let base = season_pack_base_name(name);
    if base.is_empty() || matches_sample_regex(base) || has_explicit_pack_marker(base) {
        return false;
    }

    // Match `SINGLE_EPISODE_SPLIT_REGEX`:
    // `/^(?<prefix>.+?)[._ \-]+(?:S(?<season>\d{1,4})[._ ]?E(?<episode>\d{1,4})|(?<xseason>\d{1,2})x(?<xepisode>\d{2,3}))(?!\d)(?<rest>.*)$/i`
    let mut found: Option<(&[u8], &[u8], &[u8], &[u8])> = None;
    let mut i = 1;
    while i < base.len() {
        if !matches!(base[i], b'.' | b'_' | b' ' | b'-') {
            i += 1;
            continue;
        }
        let sep_start = i;
        while i < base.len() && matches!(base[i], b'.' | b'_' | b' ' | b'-') {
            i += 1;
        }
        let sep_end = i;
        if sep_end >= base.len() {
            break;
        }
        // Try `S(?<season>\d{1,4})[._ ]?E(?<episode>\d{1,4})(?!\d)`
        if matches!(base[sep_end], b's' | b'S') {
            let s_start = sep_end + 1;
            let mut s_end = s_start;
            while s_end < base.len() && base[s_end].is_ascii_digit() {
                s_end += 1;
            }
            if (1..=4).contains(&(s_end - s_start)) {
                let mut e_pos = s_end;
                if e_pos < base.len() && matches!(base[e_pos], b'.' | b'_' | b' ') {
                    e_pos += 1;
                }
                if e_pos < base.len() && matches!(base[e_pos], b'e' | b'E') {
                    let ep_start = e_pos + 1;
                    let mut ep_end = ep_start;
                    while ep_end < base.len() && base[ep_end].is_ascii_digit() {
                        ep_end += 1;
                    }
                    if (1..=4).contains(&(ep_end - ep_start)) {
                        found = Some((
                            &base[..sep_start],
                            &base[s_start..s_end],
                            &base[ep_start..ep_end],
                            &base[ep_end..],
                        ));
                        break;
                    }
                }
            }
        }
        // Try `(?<xseason>\d{1,2})x(?<xepisode>\d{2,3})(?!\d)`
        if base[sep_end].is_ascii_digit() {
            let s_start = sep_end;
            let mut s_end = s_start;
            while s_end < base.len() && base[s_end].is_ascii_digit() {
                s_end += 1;
            }
            if (1..=2).contains(&(s_end - s_start))
                && s_end < base.len()
                && matches!(base[s_end], b'x' | b'X')
            {
                let ep_start = s_end + 1;
                let mut ep_end = ep_start;
                while ep_end < base.len() && base[ep_end].is_ascii_digit() {
                    ep_end += 1;
                }
                if (2..=3).contains(&(ep_end - ep_start)) {
                    found = Some((
                        &base[..sep_start],
                        &base[s_start..s_end],
                        &base[ep_start..ep_end],
                        &base[ep_end..],
                    ));
                    break;
                }
            }
        }
    }

    let Some((prefix, season_digits, ep_digits, rest)) = found else {
        return false;
    };

    if is_episode_name(prefix)
        || is_episode_name(rest)
        || rest.first().is_some_and(|b| b.is_ascii_alphanumeric())
        || has_extra_e_marker(rest)
        || has_x_episode_continuation(rest)
        || has_range_continuation(rest)
    {
        return false;
    }

    if !write_normalized_prefix_key(prefix, out) {
        return false;
    }
    out.push(b':');
    write_u32_decimal(parse_u32_ascii(season_digits), out);
    out.push(b':');
    write_u32_decimal(parse_u32_ascii(ep_digits), out);
    true
}

struct ParsedEpisode<'a> {
    base: &'a [u8],
    prefix: &'a [u8],
    sep: &'a [u8],
    key: [u8; 256],
    key_len: usize,
    season: u32,
    season_digits_len: usize,
    episode: u32,
    rest: &'a [u8],
}

fn parse_episode<'a>(name: &'a [u8]) -> Option<ParsedEpisode<'a>> {
    let base = season_pack_base_name(name);
    if base.is_empty() || matches_sample_regex(base) {
        return None;
    }

    let mut matched: Option<(&'a [u8], &'a [u8], &'a [u8], &'a [u8], &'a [u8])> = None;

    // 1. Try `EPISODE_SPLIT_REGEX`:
    // `/^(?<prefix>.+?)(?<sep>[._ \-]+)S(?<season>\d{1,4})[._ ]?E(?<episode>\d{1,4})(?!\d)(?:[._\-]?E\d{1,4}|-\d{1,4}(?=$|[._ ]))*(?<rest>.*)$/i`
    let mut i = 1;
    while i < base.len() {
        if !matches!(base[i], b'.' | b'_' | b' ' | b'-') {
            i += 1;
            continue;
        }
        let sep_start = i;
        while i < base.len() && matches!(base[i], b'.' | b'_' | b' ' | b'-') {
            i += 1;
        }
        let sep_end = i;
        if sep_end < base.len() && matches!(base[sep_end], b's' | b'S') {
            let s_start = sep_end + 1;
            let mut s_end = s_start;
            while s_end < base.len() && base[s_end].is_ascii_digit() {
                s_end += 1;
            }
            if (1..=4).contains(&(s_end - s_start)) {
                let mut e_pos = s_end;
                if e_pos < base.len() && matches!(base[e_pos], b'.' | b'_' | b' ') {
                    e_pos += 1;
                }
                if e_pos < base.len() && matches!(base[e_pos], b'e' | b'E') {
                    let ep_start = e_pos + 1;
                    let mut ep_end = ep_start;
                    while ep_end < base.len() && base[ep_end].is_ascii_digit() {
                        ep_end += 1;
                    }
                    if (1..=4).contains(&(ep_end - ep_start)) {
                        let mut cur = ep_end;
                        loop {
                            // Try `[._\-]?E\d{1,4}`
                            let mut p = cur;
                            if p < base.len() && matches!(base[p], b'.' | b'_' | b'-') {
                                p += 1;
                            }
                            if p < base.len() && matches!(base[p], b'e' | b'E') {
                                p += 1;
                                let d_start = p;
                                while p < base.len()
                                    && base[p].is_ascii_digit()
                                    && p - d_start < 4
                                {
                                    p += 1;
                                }
                                if p > d_start {
                                    cur = p;
                                    continue;
                                }
                            }
                            // Try `-\d{1,4}(?=$|[._ ])`
                            if cur < base.len() && base[cur] == b'-' {
                                let d_start = cur + 1;
                                let mut d_end = d_start;
                                while d_end < base.len()
                                    && base[d_end].is_ascii_digit()
                                    && d_end - d_start < 4
                                {
                                    d_end += 1;
                                }
                                let mut found_dash = false;
                                for len in (1..=(d_end - d_start)).rev() {
                                    let end_pos = d_start + len;
                                    if end_pos == base.len()
                                        || matches!(base[end_pos], b'.' | b'_' | b' ')
                                    {
                                        cur = end_pos;
                                        found_dash = true;
                                        break;
                                    }
                                }
                                if found_dash {
                                    continue;
                                }
                            }
                            break;
                        }
                        matched = Some((
                            &base[..sep_start],
                            &base[sep_start..sep_end],
                            &base[s_start..s_end],
                            &base[ep_start..ep_end],
                            &base[cur..],
                        ));
                        break;
                    }
                }
            }
        }
    }

    // 2. Fallback to `X_EPISODE_SPLIT_REGEX`:
    // `/^(?<prefix>.+?)(?<sep>[._ \-]+)(?<season>\d{1,2})x(?<episode>\d{2,3})(?![0-9a-z])(?<rest>.*)$/i`
    if matched.is_none() {
        let mut i = 1;
        while i < base.len() {
            if !matches!(base[i], b'.' | b'_' | b' ' | b'-') {
                i += 1;
                continue;
            }
            let sep_start = i;
            while i < base.len() && matches!(base[i], b'.' | b'_' | b' ' | b'-') {
                i += 1;
            }
            let sep_end = i;
            if sep_end < base.len() && base[sep_end].is_ascii_digit() {
                let s_start = sep_end;
                let mut s_end = s_start;
                while s_end < base.len() && base[s_end].is_ascii_digit() {
                    s_end += 1;
                }
                if (1..=2).contains(&(s_end - s_start))
                    && s_end < base.len()
                    && matches!(base[s_end], b'x' | b'X')
                {
                    let ep_start = s_end + 1;
                    let mut ep_end = ep_start;
                    while ep_end < base.len() && base[ep_end].is_ascii_digit() {
                        ep_end += 1;
                    }
                    if (2..=3).contains(&(ep_end - ep_start))
                        && (ep_end == base.len() || !base[ep_end].is_ascii_alphanumeric())
                    {
                        matched = Some((
                            &base[..sep_start],
                            &base[sep_start..sep_end],
                            &base[s_start..s_end],
                            &base[ep_start..ep_end],
                            &base[ep_end..],
                        ));
                        break;
                    }
                }
            }
        }
    }

    let (mut prefix, sep, season_digits, ep_digits, rest) = matched?;
    while let Some(&first) = prefix.first() {
        if matches!(first, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B) {
            prefix = &prefix[1..];
        } else {
            break;
        }
    }
    while let Some(&last) = prefix.last() {
        if matches!(last, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B) {
            prefix = &prefix[..prefix.len() - 1];
        } else {
            break;
        }
    }
    if prefix.is_empty() {
        return None;
    }

    let mut key = [0u8; 256];
    let mut key_out = unsafe { OutCursor::new(key.as_mut_ptr(), key.len()) };
    if !write_normalized_prefix_key(prefix, &mut key_out) {
        return None;
    }
    let key_len = key_out.finish();
    if key_len <= 0 {
        return None;
    }

    Some(ParsedEpisode {
        base,
        prefix,
        sep,
        key,
        key_len: key_len as usize,
        season: parse_u32_ascii(season_digits),
        season_digits_len: season_digits.len(),
        episode: parse_u32_ascii(ep_digits),
        rest,
    })
}

fn tokenize_rest(rest: &[u8]) -> Vec<(&[u8], &[u8])> {
    let mut tokens = Vec::with_capacity(12);
    let mut i = 0;
    while i < rest.len() {
        let sep_start = i;
        while i < rest.len() && matches!(rest[i], b'.' | b'_' | b' ' | b'-') {
            i += 1;
        }
        let sep = if sep_start == 0 && i == 0 {
            &b"."[..]
        } else {
            &rest[sep_start..i]
        };
        let tok_start = i;
        while i < rest.len() && !matches!(rest[i], b'.' | b'_' | b' ' | b'-') {
            i += 1;
        }
        if i > tok_start {
            tokens.push((sep, &rest[tok_start..i]));
        }
    }
    tokens
}

fn trim_to_release_info<'a>(tokens: &'a [(&'a [u8], &'a [u8])]) -> Option<&'a [(&'a [u8], &'a [u8])]> {
    for (idx, &(_, tok)) in tokens.iter().enumerate() {
        if is_release_info_token(tok) {
            return Some(&tokens[idx..]);
        }
    }
    None
}

fn write_padded_season(season: u32, width: usize, out: &mut OutCursor<'_>) {
    let mut buf = [0u8; 10];
    let mut pos = 10;
    let mut n = season;
    if n == 0 {
        pos -= 1;
        buf[pos] = b'0';
    } else {
        while n > 0 {
            pos -= 1;
            buf[pos] = b'0' + (n % 10) as u8;
            n /= 10;
        }
    }
    let digits = &buf[pos..];
    for _ in digits.len()..width {
        out.push(b'0');
    }
    out.extend(digits);
}

/// Computes `SeasonPackDetector::seasonNameFromEpisode(name)` into `out`.
pub(crate) fn write_season_name_from_episode(name: &[u8], out: &mut OutCursor<'_>) -> bool {
    let Some(ep) = parse_episode(name) else {
        return false;
    };
    let tokens = tokenize_rest(ep.rest);
    let Some(rel_info) = trim_to_release_info(&tokens) else {
        return false;
    };
    let width = ep.season_digits_len.max(2);
    out.extend(ep.prefix);
    out.extend(ep.sep);
    out.push(b'S');
    write_padded_season(ep.season, width, out);
    for &(sep, tok) in rel_info {
        out.extend(sep);
        out.extend(tok);
    }
    true
}

/// Computes `SeasonPackDetector::seasonNameFromEpisodes(names)` into `out`.
/// Returns `0` when `parseEpisodeSet` is `null`, `1` when `parseEpisodeSet` is non-null but `seasonNameFromEpisodes` is `null`,
/// or `2` when `seasonNameFromEpisodes` wrote a season name into `out`.
pub(crate) fn write_season_name_from_episodes(names: &[&[u8]], out: &mut OutCursor<'_>) -> u8 {
    let mut episodes: Vec<ParsedEpisode<'_>> = Vec::with_capacity(names.len());
    for &name in names {
        let Some(ep) = parse_episode(name) else {
            continue;
        };
        if episodes
            .iter()
            .any(|existing| existing.base.eq_ignore_ascii_case(ep.base))
        {
            continue;
        }
        episodes.push(ep);
    }
    if episodes.len() < 2 {
        return 0;
    }

    let first_key = &episodes[0].key[..episodes[0].key_len];
    if episodes
        .iter()
        .skip(1)
        .any(|ep| &ep.key[..ep.key_len] != first_key)
    {
        return 0;
    }

    // Must have at least 2 distinct `(season, episode)` pairs
    let first_pair = (episodes[0].season, episodes[0].episode);
    if !episodes
        .iter()
        .skip(1)
        .any(|ep| (ep.season, ep.episode) != first_pair)
    {
        return 0;
    }

    let mut min_season = episodes[0].season;
    let mut max_season = episodes[0].season;
    for ep in &episodes[1..] {
        min_season = min_season.min(ep.season);
        max_season = max_season.max(ep.season);
    }

    let token_lists: Vec<Vec<(&[u8], &[u8])>> =
        episodes.iter().map(|ep| tokenize_rest(ep.rest)).collect();
    let min_tok_len = token_lists.iter().map(Vec::len).min().unwrap_or(0);
    let first_tokens = &token_lists[0];
    let mut common_count = 0;
    while common_count < min_tok_len {
        let candidate = first_tokens[first_tokens.len() - 1 - common_count].1;
        if token_lists[1..]
            .iter()
            .all(|list| list[list.len() - 1 - common_count].1.eq_ignore_ascii_case(candidate))
        {
            common_count += 1;
        } else {
            break;
        }
    }

    let common_slice = &first_tokens[first_tokens.len() - common_count..];
    let Some(rel_info) = trim_to_release_info(common_slice) else {
        if min_season != max_season {
            return 1;
        }
        return if write_season_name_from_episode(episodes[0].base, out) {
            2
        } else {
            1
        };
    };

    let width = episodes[0].season_digits_len.max(2);
    out.extend(episodes[0].prefix);
    out.extend(episodes[0].sep);
    out.push(b'S');
    write_padded_season(min_season, width, out);
    if min_season != max_season {
        out.extend(b"-S");
        write_padded_season(max_season, width, out);
    }
    for &(sep, tok) in rel_info {
        out.extend(sep);
        out.extend(tok);
    }
    2
}

// ============================================================================
// C ABI Exports
// ============================================================================

/// Returns the truncated prefix length of `input[..input_len]` after `FileNameCleaner::stripKnownFileSuffixes`,
/// or `-1` if declined (`\n` at end of input).
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_strip_known_file_suffixes(
    input: *const u8,
    input_len: usize,
    preserve_codec: u32,
) -> isize {
    if input.is_null() || input_len == 0 {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    match strip_known_file_suffixes_len(bytes, preserve_codec != 0) {
        Some(len) => len as isize,
        None => -1,
    }
}

/// Computes `FileNameCleaner::computeForMatching` as a packed `(start << 32) | len` span into `input`.
/// Returns `>= 0` on match, `-1` for `false`, or `-2` when declined to PHP fallback.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_clean_for_matching_span(
    input: *const u8,
    input_len: usize,
    preserve_leading_number: u32,
) -> i64 {
    if input.is_null() || input_len == 0 {
        return -1;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    clean_for_matching_span(bytes, preserve_leading_number != 0)
}

/// Computes `SeasonPackDetector` boolean flags for `input[..input_len]`:
/// - Bit 0 (`1`): `isEpisodeName`
/// - Bit 1 (`2`): `isSeasonPack`
/// Returns `-1` if `input` contains `\n`.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_season_pack_flags(input: *const u8, input_len: usize) -> i32 {
    if input.is_null() || input_len == 0 {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    if bytes.contains(&b'\n') {
        return -1;
    }
    if is_episode_name(bytes) {
        1
    } else if matches_season_token(bytes)
        || matches_season_word(bytes)
        || matches_complete_pack(bytes)
    {
        2
    } else {
        0
    }
}

/// Computes `SeasonPackDetector::episodeIdentity(name)` into `out_buf`.
/// Returns `0` for `null`, `> 0` bytes written, `-1` if declined (`\n`), or `-(required_len as isize)` if `out_cap` is too small.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_season_pack_episode_identity(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() || input_len == 0 {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    if bytes.contains(&b'\n') {
        return -1;
    }
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    if !write_episode_identity(bytes, &mut out) {
        return 0;
    }
    out.finish()
}

/// Batch checks `SeasonPackDetector::hasNonSingleEpisodeEvidence(names)` for `count` NUL-separated strings.
/// Returns `1` (`true`), `0` (`false`), or `-1` if declined.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_has_non_single_episode_evidence_nul(
    input: *const u8,
    input_len: usize,
    count: usize,
) -> i32 {
    if count == 0 {
        return 0;
    }
    if input.is_null() && input_len > 0 {
        return -1;
    }
    let bytes: &[u8] = if input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };
    if bytes.contains(&b'\n') {
        return -1;
    }

    let mut rem = bytes;
    let mut first_id_buf = [0u8; 512];
    let mut first_id_len: Option<usize> = None;
    let mut cur_id_buf = [0u8; 512];

    for idx in 0..count {
        let item = if idx + 1 < count {
            let Some(nul) = find_nul(rem) else {
                return -1;
            };
            let s = &rem[..nul];
            rem = &rem[nul + 1..];
            s
        } else {
            if find_nul(rem).is_some() {
                return -1;
            }
            let s = rem;
            rem = &[];
            s
        };

        let base = season_pack_base_name(item);
        if matches_sample_regex(base) {
            continue;
        }
        if has_explicit_pack_marker(base) {
            return 1;
        }
        let mut out = unsafe { OutCursor::new(cur_id_buf.as_mut_ptr(), cur_id_buf.len()) };
        let has_id = write_episode_identity(item, &mut out);
        let id_len = out.finish();
        if id_len < 0 {
            return -1;
        }
        if !has_id || id_len == 0 {
            if is_episode_name(base) {
                return 1;
            }
            continue;
        }
        let id_slice = &cur_id_buf[..id_len as usize];
        match first_id_len {
            Some(flen) => {
                if &first_id_buf[..flen] != id_slice {
                    return 1;
                }
            }
            None => {
                first_id_buf[..id_len as usize].copy_from_slice(id_slice);
                first_id_len = Some(id_len as usize);
            }
        }
    }

    0
}

/// Computes `SeasonPackDetector::seasonNameFromEpisode(name)` into `out_buf`.
/// Returns `0` for `null`, `> 0` bytes written, `-1` if declined (`\n`), or `-(required_len as isize)` if `out_cap` is too small.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_season_name_from_episode(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() || input_len == 0 {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    if bytes.contains(&b'\n') {
        return -1;
    }
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    if !write_season_name_from_episode(bytes, &mut out) {
        return 0;
    }
    out.finish()
}

/// Computes `SeasonPackDetector::seasonNameFromEpisodes(names)` for `count` NUL-separated strings into `out_buf`.
/// Returns `0` for `null`, `> 0` bytes written, `-1` if declined, or `-(required_len as isize)` if `out_cap` is too small.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_season_name_from_episodes_nul(
    input: *const u8,
    input_len: usize,
    count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if count < 2 {
        return 0;
    }
    if input.is_null() && input_len > 0 {
        return -1;
    }
    let bytes: &[u8] = if input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };
    if bytes.contains(&b'\n') {
        return -1;
    }

    let mut items = Vec::with_capacity(count);
    let mut rem = bytes;
    for idx in 0..count {
        let item = if idx + 1 < count {
            let Some(nul) = find_nul(rem) else {
                return -1;
            };
            let s = &rem[..nul];
            rem = &rem[nul + 1..];
            s
        } else {
            if find_nul(rem).is_some() {
                return -1;
            }
            let s = rem;
            rem = &[];
            s
        };
        items.push(item);
    }

    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    match write_season_name_from_episodes(&items, &mut out) {
        0 => 0,
        1 => -2,
        _ => out.finish(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn strip_suffixes_matches_filename_cleaner() {
        let s = b"Exact.Release.2026-GRP.part12345.rar";
        assert_eq!(strip_known_file_suffixes_len(s, true), Some(22));
        let dts = b"Exact.Release.2026-GRP.DTS.part12345.rar";
        assert_eq!(strip_known_file_suffixes_len(dts, true), Some(26));
        assert_eq!(strip_known_file_suffixes_len(dts, false), Some(22));
        let flac_mkv = b"Exact.Release.2026-GRP.flac.mkv";
        assert_eq!(strip_known_file_suffixes_len(flac_mkv, true), Some(27));
    }

    #[test]
    fn clean_for_matching_span_matches_php() {
        let s = b"Folder/01.Artist.Track.flac";
        let span = clean_for_matching_span(s, false);
        assert!(span >= 0);
        let start = (span >> 32) as usize;
        let len = (span & 0xFFFF_FFFF) as usize;
        assert_eq!(&s[start..start + len], b"Artist.Track");

        let span_preserve = clean_for_matching_span(s, true);
        let start2 = (span_preserve >> 32) as usize;
        let len2 = (span_preserve & 0xFFFF_FFFF) as usize;
        assert_eq!(&s[start2..start2 + len2], b"01.Artist.Track");

        assert_eq!(clean_for_matching_span(b"VIDEO_TS/VTS_01_1.VOB", false), -1);
        assert_eq!(clean_for_matching_span(b"Film ;-)/1.jpg", false), -1);
        assert_eq!(
            clean_for_matching_span(b"Film ;-)/Extreem Online Contact.url", false),
            -1
        );
    }

    #[test]
    fn season_pack_and_episode_identity_match_php() {
        assert!(is_season_pack(b"Show.S01.1080p.WEB-DL.DDP5.1.H.264-GRP"));
        assert!(is_season_pack(b"Show.S01.Disc.2.DVDR-GRP"));
        assert!(!is_season_pack(b"Show.S01E01.1080p.WEB-DL-GRP"));
        assert!(!is_season_pack(b"Movie.2020.1080p.BluRay.DTS5.1-GRP"));

        let mut buf = [0u8; 64];
        let mut out = unsafe { OutCursor::new(buf.as_mut_ptr(), buf.len()) };
        assert!(write_episode_identity(
            b"Show.S01E01.1080p.WEB.x264-GRP.mkv",
            &mut out
        ));
        let len = out.finish() as usize;
        assert_eq!(&buf[..len], b"show:1:1");

        let mut out2 = unsafe { OutCursor::new(buf.as_mut_ptr(), buf.len()) };
        assert!(!write_episode_identity(
            b"Show.S01E01.1080p.WEB.x02-GRP.mkv",
            &mut out2
        ));
    }
}
