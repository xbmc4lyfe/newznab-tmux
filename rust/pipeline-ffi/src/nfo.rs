use std::slice;

use crate::OutCursor;

unsafe extern "C" {
    fn memchr(s: *const u8, c: i32, n: usize) -> *const u8;
}

/// Complete 128-entry CP437 high-byte (`0x80..=0xFF`) to UTF-8 table matching
/// `iconv('CP437', 'UTF-8//IGNORE//TRANSLIT', chr($b))`.
const CP437_HIGH_TABLE: [(u8, [u8; 3]); 128] = [
    (2, [0xc3, 0x87, 0x00]), // 0x80 Ç
    (2, [0xc3, 0xbc, 0x00]), // 0x81 ü
    (2, [0xc3, 0xa9, 0x00]), // 0x82 é
    (2, [0xc3, 0xa2, 0x00]), // 0x83 â
    (2, [0xc3, 0xa4, 0x00]), // 0x84 ä
    (2, [0xc3, 0xa0, 0x00]), // 0x85 à
    (2, [0xc3, 0xa5, 0x00]), // 0x86 å
    (2, [0xc3, 0xa7, 0x00]), // 0x87 ç
    (2, [0xc3, 0xaa, 0x00]), // 0x88 ê
    (2, [0xc3, 0xab, 0x00]), // 0x89 ë
    (2, [0xc3, 0xa8, 0x00]), // 0x8A è
    (2, [0xc3, 0xaf, 0x00]), // 0x8B ï
    (2, [0xc3, 0xae, 0x00]), // 0x8C î
    (2, [0xc3, 0xac, 0x00]), // 0x8D ì
    (2, [0xc3, 0x84, 0x00]), // 0x8E Ä
    (2, [0xc3, 0x85, 0x00]), // 0x8F Å
    (2, [0xc3, 0x89, 0x00]), // 0x90 É
    (2, [0xc3, 0xa6, 0x00]), // 0x91 æ
    (2, [0xc3, 0x86, 0x00]), // 0x92 Æ
    (2, [0xc3, 0xb4, 0x00]), // 0x93 ô
    (2, [0xc3, 0xb6, 0x00]), // 0x94 ö
    (2, [0xc3, 0xb2, 0x00]), // 0x95 ò
    (2, [0xc3, 0xbb, 0x00]), // 0x96 û
    (2, [0xc3, 0xb9, 0x00]), // 0x97 ù
    (2, [0xc3, 0xbf, 0x00]), // 0x98 ÿ
    (2, [0xc3, 0x96, 0x00]), // 0x99 Ö
    (2, [0xc3, 0x9c, 0x00]), // 0x9A Ü
    (2, [0xc2, 0xa2, 0x00]), // 0x9B ¢
    (2, [0xc2, 0xa3, 0x00]), // 0x9C £
    (2, [0xc2, 0xa5, 0x00]), // 0x9D ¥
    (3, [0xe2, 0x82, 0xa7]), // 0x9E ₧
    (2, [0xc6, 0x92, 0x00]), // 0x9F ƒ
    (2, [0xc3, 0xa1, 0x00]), // 0xA0 á
    (2, [0xc3, 0xad, 0x00]), // 0xA1 í
    (2, [0xc3, 0xb3, 0x00]), // 0xA2 ó
    (2, [0xc3, 0xba, 0x00]), // 0xA3 ú
    (2, [0xc3, 0xb1, 0x00]), // 0xA4 ñ
    (2, [0xc3, 0x91, 0x00]), // 0xA5 Ñ
    (2, [0xc2, 0xaa, 0x00]), // 0xA6 ª
    (2, [0xc2, 0xba, 0x00]), // 0xA7 º
    (2, [0xc2, 0xbf, 0x00]), // 0xA8 ¿
    (3, [0xe2, 0x8c, 0x90]), // 0xA9 ⌐
    (2, [0xc2, 0xac, 0x00]), // 0xAA ¬
    (2, [0xc2, 0xbd, 0x00]), // 0xAB ½
    (2, [0xc2, 0xbc, 0x00]), // 0xAC ¼
    (2, [0xc2, 0xa1, 0x00]), // 0xAD ¡
    (2, [0xc2, 0xab, 0x00]), // 0xAE «
    (2, [0xc2, 0xbb, 0x00]), // 0xAF »
    (3, [0xe2, 0x96, 0x91]), // 0xB0 ░
    (3, [0xe2, 0x96, 0x92]), // 0xB1 ▒
    (3, [0xe2, 0x96, 0x93]), // 0xB2 ▓
    (3, [0xe2, 0x94, 0x82]), // 0xB3 │
    (3, [0xe2, 0x94, 0xa4]), // 0xB4 ┤
    (3, [0xe2, 0x95, 0xa1]), // 0xB5 ╡
    (3, [0xe2, 0x95, 0xa2]), // 0xB6 ╢
    (3, [0xe2, 0x95, 0x96]), // 0xB7 ╖
    (3, [0xe2, 0x95, 0x95]), // 0xB8 ╕
    (3, [0xe2, 0x95, 0xa3]), // 0xB9 ╣
    (3, [0xe2, 0x95, 0x91]), // 0xBA ║
    (3, [0xe2, 0x95, 0x97]), // 0xBB ╗
    (3, [0xe2, 0x95, 0x9d]), // 0xBC ╝
    (3, [0xe2, 0x95, 0x9c]), // 0xBD ╜
    (3, [0xe2, 0x95, 0x9b]), // 0xBE ╛
    (3, [0xe2, 0x94, 0x90]), // 0xBF ┐
    (3, [0xe2, 0x94, 0x94]), // 0xC0 └
    (3, [0xe2, 0x94, 0xb4]), // 0xC1 ┴
    (3, [0xe2, 0x94, 0xac]), // 0xC2 ┬
    (3, [0xe2, 0x94, 0x9c]), // 0xC3 ├
    (3, [0xe2, 0x94, 0x80]), // 0xC4 ─
    (3, [0xe2, 0x94, 0xbc]), // 0xC5 ┼
    (3, [0xe2, 0x95, 0x9e]), // 0xC6 ╞
    (3, [0xe2, 0x95, 0x9f]), // 0xC7 ╟
    (3, [0xe2, 0x95, 0x9a]), // 0xC8 ╚
    (3, [0xe2, 0x95, 0x94]), // 0xC9 ╔
    (3, [0xe2, 0x95, 0xa9]), // 0xCA ╩
    (3, [0xe2, 0x95, 0xa6]), // 0xCB ╦
    (3, [0xe2, 0x95, 0xa0]), // 0xCC ╠
    (3, [0xe2, 0x95, 0x90]), // 0xCD ═
    (3, [0xe2, 0x95, 0xac]), // 0xCE ╬
    (3, [0xe2, 0x95, 0xa7]), // 0xCF ╧
    (3, [0xe2, 0x95, 0xa8]), // 0xD0 ╨
    (3, [0xe2, 0x95, 0xa4]), // 0xD1 ╤
    (3, [0xe2, 0x95, 0xa5]), // 0xD2 ╥
    (3, [0xe2, 0x95, 0x99]), // 0xD3 ╙
    (3, [0xe2, 0x95, 0x98]), // 0xD4 ╘
    (3, [0xe2, 0x95, 0x92]), // 0xD5 ╒
    (3, [0xe2, 0x95, 0x93]), // 0xD6 ╓
    (3, [0xe2, 0x95, 0xab]), // 0xD7 ╫
    (3, [0xe2, 0x95, 0xaa]), // 0xD8 ╪
    (3, [0xe2, 0x94, 0x98]), // 0xD9 ┘
    (3, [0xe2, 0x94, 0x8c]), // 0xDA ┌
    (3, [0xe2, 0x96, 0x88]), // 0xDB █
    (3, [0xe2, 0x96, 0x84]), // 0xDC ▄
    (3, [0xe2, 0x96, 0x8c]), // 0xDD ▌
    (3, [0xe2, 0x96, 0x90]), // 0xDE ▐
    (3, [0xe2, 0x96, 0x80]), // 0xDF ▀
    (2, [0xce, 0xb1, 0x00]), // 0xE0 α
    (2, [0xc3, 0x9f, 0x00]), // 0xE1 ß
    (2, [0xce, 0x93, 0x00]), // 0xE2 Γ
    (2, [0xcf, 0x80, 0x00]), // 0xE3 π
    (2, [0xce, 0xa3, 0x00]), // 0xE4 Σ
    (2, [0xcf, 0x83, 0x00]), // 0xE5 σ
    (2, [0xc2, 0xb5, 0x00]), // 0xE6 µ
    (2, [0xcf, 0x84, 0x00]), // 0xE7 τ
    (2, [0xce, 0xa6, 0x00]), // 0xE8 Φ
    (2, [0xce, 0x98, 0x00]), // 0xE9 Θ
    (2, [0xce, 0xa9, 0x00]), // 0xEA Ω
    (2, [0xce, 0xb4, 0x00]), // 0xEB δ
    (3, [0xe2, 0x88, 0x9e]), // 0xEC ∞
    (2, [0xcf, 0x86, 0x00]), // 0xED φ
    (2, [0xce, 0xb5, 0x00]), // 0xEE ε
    (3, [0xe2, 0x88, 0xa9]), // 0xEF ∩
    (3, [0xe2, 0x89, 0xa1]), // 0xF0 ≡
    (2, [0xc2, 0xb1, 0x00]), // 0xF1 ±
    (3, [0xe2, 0x89, 0xa5]), // 0xF2 ≥
    (3, [0xe2, 0x89, 0xa4]), // 0xF3 ≤
    (3, [0xe2, 0x8c, 0xa0]), // 0xF4 ⌠
    (3, [0xe2, 0x8c, 0xa1]), // 0xF5 ⌡
    (2, [0xc3, 0xb7, 0x00]), // 0xF6 ÷
    (3, [0xe2, 0x89, 0x88]), // 0xF7 ≈
    (2, [0xc2, 0xb0, 0x00]), // 0xF8 °
    (3, [0xe2, 0x88, 0x99]), // 0xF9 ∙
    (2, [0xc2, 0xb7, 0x00]), // 0xFA ·
    (3, [0xe2, 0x88, 0x9a]), // 0xFB √
    (3, [0xe2, 0x81, 0xbf]), // 0xFC ⁿ
    (2, [0xc2, 0xb2, 0x00]), // 0xFD ²
    (3, [0xe2, 0x96, 0xa0]), // 0xFE ■
    (2, [0xc2, 0xa0, 0x00]), // 0xFF NBSP
];

#[inline]
fn finish_cursor(out: OutCursor<'_>) -> isize {
    if out.pos == 0 {
        0
    } else if out.pos <= out.buf.len() {
        out.pos as isize
    } else {
        -(out.pos.max(4) as isize)
    }
}

#[inline]
fn cp437_to_utf8_into(src: &[u8], out: &mut OutCursor<'_>) {
    let mut run_start = 0usize;
    for (i, &b) in src.iter().enumerate() {
        if b >= 0x80 {
            if i > run_start {
                out.extend(&src[run_start..i]);
            }
            let (len, seq) = CP437_HIGH_TABLE[(b - 0x80) as usize];
            out.extend(&seq[..len as usize]);
            run_start = i + 1;
        }
    }
    if run_start < src.len() {
        out.extend(&src[run_start..]);
    }
}

#[inline]
fn is_php_line_trim_byte(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | 0x0B | 0x00)
}

#[inline]
fn is_pcre_space(b: u8) -> bool {
    b == b' ' || (0x09..=0x0D).contains(&b)
}

#[inline]
fn is_word_byte(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_'
}

/// Converts a CP437 byte slice to UTF-8 into `out`, or returns `-3` when the input is
/// already 100% ASCII (`0x00..=0x7F`) so the PHP caller can return the input string untouched.
pub(crate) fn cp437_to_utf8_or_passthrough(src: &[u8], out: &mut OutCursor<'_>) -> isize {
    if src.is_empty() {
        return 0;
    }
    if src.is_ascii() {
        return -3;
    }
    cp437_to_utf8_into(src, out);
    finish_cursor_ref(out)
}

#[inline]
fn finish_cursor_ref(out: &OutCursor<'_>) -> isize {
    if out.pos == 0 {
        0
    } else if out.pos <= out.buf.len() {
        out.pos as isize
    } else {
        -(out.pos.max(4) as isize)
    }
}

/// Normalizes NFO encoding matching `ReleaseFileManager::normalizeNfoEncoding`:
/// - Strips UTF-8 BOM (`\xEF\xBB\xBF`)
/// - Declines (`-2`) if UTF-16LE (`\xFF\xFE`) or UTF-16BE (`\xFE\xFF`) BOM is present
/// - Returns `-3` if input has no BOM and is already valid UTF-8 (caller returns `$data` untouched)
/// - Otherwise writes the normalized UTF-8 (or CP437-converted UTF-8) into `out`.
pub(crate) fn normalize_nfo_encoding_into(src: &[u8], out: &mut OutCursor<'_>) -> isize {
    let (body, had_utf8_bom) = if src.starts_with(b"\xEF\xBB\xBF") {
        (&src[3..], true)
    } else {
        (src, false)
    };

    if body.starts_with(b"\xFF\xFE") || body.starts_with(b"\xFE\xFF") {
        return -2;
    }

    if std::str::from_utf8(body).is_ok() {
        if !had_utf8_bom {
            return -3;
        }
        out.extend(body);
        return finish_cursor_ref(out);
    }

    cp437_to_utf8_into(body, out);
    finish_cursor_ref(out)
}

/// Single-pass implementation of `NfoService::cleanNfoContent`:
/// - Converts CP437 bytes to UTF-8
/// - Normalizes `\r\n` and `\r` to `\n`
/// - `rtrim`s non-empty lines and collapses `> 2` consecutive empty lines.
pub(crate) fn clean_nfo_content_into(src: &[u8], out: &mut OutCursor<'_>) {
    let len = src.len();
    let mut pos = 0usize;
    let mut empty_line_count = 0usize;
    let mut emitted_lines = 0usize;

    loop {
        let line_start = pos;
        while pos < len && src[pos] != b'\r' && src[pos] != b'\n' {
            pos += 1;
        }
        let line_end = pos;

        let mut trimmed_end = line_end;
        while trimmed_end > line_start && is_php_line_trim_byte(src[trimmed_end - 1]) {
            trimmed_end -= 1;
        }

        if trimmed_end == line_start {
            empty_line_count += 1;
            if empty_line_count <= 2 {
                if emitted_lines > 0 {
                    out.push(b'\n');
                }
                emitted_lines += 1;
            }
        } else {
            empty_line_count = 0;
            if emitted_lines > 0 {
                out.push(b'\n');
            }
            emitted_lines += 1;
            cp437_to_utf8_into(&src[line_start..trimmed_end], out);
        }

        if pos >= len {
            break;
        }
        if src[pos] == b'\r' {
            pos += 1;
            if pos < len && src[pos] == b'\n' {
                pos += 1;
            }
        } else {
            pos += 1;
        }
    }
}

#[inline]
fn is_source_header_line(line: &[u8]) -> bool {
    let mut i = 0usize;
    while i < line.len() && (line[i] == b' ' || line[i] == b'\t') {
        i += 1;
    }
    if line.len() - i < 7 {
        return false;
    }
    if !line[i..i + 6].eq_ignore_ascii_case(b"source") {
        return false;
    }
    i += 6;
    while i < line.len() && (line[i] == b' ' || line[i] == b'\t') {
        i += 1;
    }
    i < line.len() && line[i] == b':'
}

#[inline]
fn is_source_continuation_line(line: &[u8]) -> bool {
    // Must match `/^[ \t]+\S/`
    let mut i = 0usize;
    while i < line.len() && (line[i] == b' ' || line[i] == b'\t') {
        i += 1;
    }
    if i == 0 || i >= line.len() || is_pcre_space(line[i]) {
        return false;
    }

    // Check `~^[ \t]*[A-Za-z]:[\\\\/]~`
    if line[i].is_ascii_alphabetic()
        && i + 2 < line.len()
        && line[i + 1] == b':'
        && (line[i + 2] == b'\\' || line[i + 2] == b'/')
    {
        return true;
    }

    // Check if it matches `/^[ \t]*[A-Za-z][A-Za-z0-9 _-]*[ \t]*:/`
    if line[i].is_ascii_alphabetic() {
        let mut j = i + 1;
        while j < line.len()
            && (line[j].is_ascii_alphanumeric()
                || line[j] == b' '
                || line[j] == b'_'
                || line[j] == b'-')
        {
            j += 1;
        }
        while j < line.len() && (line[j] == b' ' || line[j] == b'\t') {
            j += 1;
        }
        if j < line.len() && line[j] == b':' {
            return false;
        }
    }

    true
}

/// Single-pass implementation of `NfoNameExtractor::identityText`:
/// Excludes `Source:` fields and their indented continuations while preserving line boundaries.
pub(crate) fn nfo_identity_text_into(src: &[u8], out: &mut OutCursor<'_>) {
    let len = src.len();
    let mut pos = 0usize;
    let mut source_field = false;
    let mut line_index = 0usize;

    loop {
        let line_start = pos;
        while pos < len && src[pos] != b'\r' && src[pos] != b'\n' {
            pos += 1;
        }
        let line = &src[line_start..pos];

        if line_index > 0 {
            out.push(b'\n');
        }
        line_index += 1;

        if is_source_header_line(line) {
            source_field = true;
        } else if source_field && is_source_continuation_line(line) {
            // Keep source_field = true and emit empty line
        } else {
            source_field = false;
            out.extend(line);
        }

        if pos >= len {
            break;
        }
        if src[pos] == b'\r' {
            pos += 1;
            if pos < len && src[pos] == b'\n' {
                pos += 1;
            }
        } else {
            pos += 1;
        }
    }
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

    // If needle contains a case-invariant byte (such as '/', '.', ':', '2', '3', '4', '5'),
    // use libc::memchr on that anchor byte to skip non-matching spans at SIMD speed.
    if let Some(anchor_idx) = needle_lower.iter().position(|&b| !b.is_ascii_alphabetic()) {
        let anchor_byte = needle_lower[anchor_idx];
        let search_end = haystack.len() - nlen + anchor_idx + 1;
        let mut cur = anchor_idx;
        while cur < search_end {
            let rem = search_end - cur;
            let found = unsafe { memchr(haystack.as_ptr().add(cur), anchor_byte as i32, rem) };
            if found.is_null() {
                return None;
            }
            let anchor_pos = unsafe { found.offset_from(haystack.as_ptr()) as usize };
            let start = anchor_pos - anchor_idx;
            if haystack[start..start + nlen].eq_ignore_ascii_case(needle_lower) {
                return Some(start);
            }
            cur = anchor_pos + 1;
        }
        return None;
    }

    let first_lo = needle_lower[0];
    let first_hi = first_lo.to_ascii_uppercase();
    let max_i = haystack.len() - nlen;
    let mut i = 0usize;
    while i <= max_i {
        let b = haystack[i];
        if b == first_lo || b == first_hi {
            if haystack[i + 1..i + nlen].eq_ignore_ascii_case(&needle_lower[1..]) {
                return Some(i);
            }
        }
        i += 1;
    }
    None
}

/// Checks `NfoService::detectBinaryFormat(string $data): bool`.
pub fn detect_binary_format(data: &[u8]) -> bool {
    if data.len() < 4 {
        return false;
    }
    const MAGIC_BYTES: &[&[u8]] = &[
        b"\x50\x4B\x03\x04", // ZIP
        b"\x50\x4B\x05\x06", // ZIP_EMPTY
        b"\x52\x61\x72\x21", // RAR
        b"\x37\x7A\xBC\xAF", // 7Z
        b"\x1F\x8B\x08",     // GZIP
        b"\x42\x5A\x68",     // BZIP2
        b"\xFD\x37\x7A\x58", // XZ
        b"\x89\x50\x4E\x47", // PNG
        b"\xFF\xD8\xFF",     // JPEG
        b"\x47\x49\x46\x38", // GIF
        b"\x25\x50\x44\x46", // PDF
        b"\x49\x44\x33",     // MP3_ID3
        b"\xFF\xFB",         // MP3
        b"\x4F\x67\x67\x53", // OGG
        b"\x66\x4C\x61\x43", // FLAC
        b"\x52\x49\x46\x46", // RIFF
        b"\x00\x00\x01\xBA", // MPEG
        b"\x00\x00\x01\xB3", // MPEG
        b"\x1A\x45\xDF\xA3", // MKV
        b"\x4D\x5A",         // EXE
        b"\x7F\x45\x4C\x46", // ELF
        b"\xCA\xFE\xBA\xBE", // JAVA
        b"\xD0\xCF\x11\xE0", // OLE
    ];
    for &magic in MAGIC_BYTES {
        if data.starts_with(magic) {
            return true;
        }
    }
    false
}

/// Matches `NfoService::$_nonNfoHeaderRegex`:
/// `/\A(\s*<\?xml|=newz\[NZB\]=|RIFF|\s*[RP]AR|.{0,10}(JFIF|matroska|ftyp|ID3)|PK\x03\x04|\x1f\x8b\x08|MZ|%PDF|GIF8[79]a|\x89PNG)|;\s*Generated\s*by.*SF\w/i`
pub fn matches_non_nfo_header(data: &[u8]) -> bool {
    let mut ws_end = 0usize;
    while ws_end < data.len() && is_pcre_space(data[ws_end]) {
        ws_end += 1;
    }
    let after_ws = &data[ws_end..];
    if after_ws.len() >= 5 && after_ws[..5].eq_ignore_ascii_case(b"<?xml") {
        return true;
    }
    if after_ws.len() >= 3
        && (after_ws[..3].eq_ignore_ascii_case(b"rar")
            || after_ws[..3].eq_ignore_ascii_case(b"par"))
    {
        return true;
    }

    if data.len() >= 11 && data[..11].eq_ignore_ascii_case(b"=newz[nzb]=") {
        return true;
    }
    if data.len() >= 4 && data[..4].eq_ignore_ascii_case(b"riff") {
        return true;
    }
    if data.len() >= 4
        && data[..2].eq_ignore_ascii_case(b"pk")
        && data[2] == 0x03
        && data[3] == 0x04
    {
        return true;
    }
    if data.starts_with(b"\x1f\x8b\x08") {
        return true;
    }
    if data.len() >= 2 && data[..2].eq_ignore_ascii_case(b"mz") {
        return true;
    }
    if data.len() >= 4 && data[..4].eq_ignore_ascii_case(b"%pdf") {
        return true;
    }
    if data.len() >= 6
        && data[..4].eq_ignore_ascii_case(b"gif8")
        && (data[4] == b'7' || data[4] == b'9')
        && (data[5] == b'a' || data[5] == b'A')
    {
        return true;
    }
    if data.len() >= 4 && data[0] == 0x89 && data[1..4].eq_ignore_ascii_case(b"png") {
        return true;
    }

    // `\A.{0,10}(JFIF|matroska|ftyp|ID3)/i` where `.` does not match `\n`
    let max_prefix = data.len().min(10);
    for k in 0..=max_prefix {
        if k > 0 && data[k - 1] == b'\n' {
            break;
        }
        let rest = &data[k..];
        if (rest.len() >= 4 && rest[..4].eq_ignore_ascii_case(b"jfif"))
            || (rest.len() >= 8 && rest[..8].eq_ignore_ascii_case(b"matroska"))
            || (rest.len() >= 4 && rest[..4].eq_ignore_ascii_case(b"ftyp"))
            || (rest.len() >= 3 && rest[..3].eq_ignore_ascii_case(b"id3"))
        {
            return true;
        }
    }

    // Unanchored branch: `;\s*Generated\s*by.*SF\w/i`
    let mut pos = 0usize;
    while pos < data.len() {
        let Some(semi_rel) = data[pos..].iter().position(|&b| b == b';') else {
            break;
        };
        let semi_pos = pos + semi_rel;
        let mut p = semi_pos + 1;
        while p < data.len() && is_pcre_space(data[p]) {
            p += 1;
        }
        if data.len() - p >= 9 && data[p..p + 9].eq_ignore_ascii_case(b"generated") {
            p += 9;
            while p < data.len() && is_pcre_space(data[p]) {
                p += 1;
            }
            if data.len() - p >= 2 && data[p..p + 2].eq_ignore_ascii_case(b"by") {
                p += 2;
                let mut line_end = p;
                while line_end < data.len() && data[line_end] != b'\n' {
                    line_end += 1;
                }
                let line_tail = &data[p..line_end];
                if line_tail.len() >= 3 {
                    for s in 0..=line_tail.len() - 3 {
                        if line_tail[s..s + 2].eq_ignore_ascii_case(b"sf")
                            && is_word_byte(line_tail[s + 2])
                        {
                            return true;
                        }
                    }
                }
            }
        }
        pos = semi_pos + 1;
    }

    false
}

/// Matches `NfoService::$_binaryCharsRegex`: `/[\x00-\x08\x0B\x0C\x0E-\x1F]/`
#[inline]
pub fn has_binary_control_chars(data: &[u8]) -> bool {
    for &b in data {
        if b < 0x20 && b != b'\t' && b != b'\n' && b != b'\r' {
            return true;
        }
    }
    false
}

const NFO_KEYWORDS: &[&[u8]] = &[
    b"release",
    b"group",
    b"date",
    b"size",
    b"format",
    b"source",
    b"genre",
    b"codec",
    b"bitrate",
    b"resolution",
    b"language",
    b"subtitle",
    b"ripped",
    b"cracked",
    b"keygen",
    b"serial",
    b"patch",
    b"trainer",
    b"install",
    b"notes",
    b"greets",
    b"nfo",
    b"ascii",
    b"artwork",
    b"presents",
    b"proudly",
    b"brings",
    b"another",
    b"scene",
    b"rls",
    b"nuked",
    b"proper",
    b"repack",
    b"internal",
    b"retail",
    b"webdl",
    b"webrip",
    b"bluray",
    b"bdrip",
    b"dvdrip",
    b"hdtv",
    b"pdtv",
    b"video",
    b"audio",
    b"duration",
    b"runtime",
    b"aspect",
    b"fps",
    b"channels",
    b"sample",
    b"encoder",
    b"x264",
    b"x265",
    b"hevc",
    b"avc",
    b"xvid",
    b"divx",
    b"aac",
    b"ac3",
    b"dts",
    b"truehd",
    b"atmos",
    b"flac",
    b"mp3",
    b"movie",
    b"film",
    b"episode",
    b"season",
    b"series",
    b"title",
    b"year",
    b"director",
    b"cast",
    b"actors",
    b"plot",
    b"synopsis",
    b"imdb",
    b"rating",
    b"crack",
    b"readme",
    b"setup",
    b"installer",
    b"license",
    b"registration",
    b"protection",
    b"requirements",
    b"platform",
    b"operating",
    b"system",
    b"contact",
    b"irc",
    b"www",
    b"http",
    b"ftp",
    b"email",
    b"apply",
    b"join",
];

/// Validates NFO content matching `NfoService::validateNfoContent`:
/// Returns `1` (valid), `0` (invalid), or `-2` (decline to PHP when `indicators < 2` and
/// multiline `Key: Value` regex is needed to decide).
pub fn validate_nfo_content(content: &[u8]) -> i32 {
    let length = content.len();
    if length < 50 {
        return 0;
    }

    // Fast branchless auto-vectorized printable ASCII count
    let printable_count: usize = content
        .iter()
        .map(|&b| (b.wrapping_sub(0x20) < 0x5F) as usize)
        .sum();
    let printable_ratio = (printable_count as f64) / (length as f64);
    if printable_ratio < 0.7 {
        return 0;
    }

    // Count words up to 5 (early exit as soon as 5 words are seen)
    let mut word_count = 0usize;
    let mut alpha_run = 0usize;
    for &b in content {
        if b.is_ascii_alphabetic() {
            alpha_run += 1;
            if alpha_run == 2 {
                word_count += 1;
                if word_count >= 5 {
                    break;
                }
            }
        } else {
            alpha_run = 0;
        }
    }
    if word_count < 5 {
        return 0;
    }

    let mut indicators = 0usize;
    for &kw in NFO_KEYWORDS {
        if find_ascii_ci(content, kw).is_some() {
            indicators += 1;
            if indicators >= 3 {
                return 1;
            }
        }
    }

    let mut border_run = 0usize;
    let mut has_border_5 = false;
    let mut has_colon_or_dot = false;
    for &b in content {
        if b == b'-' || b == b'=' || b == b'*' {
            border_run += 1;
            if border_run >= 5 {
                has_border_5 = true;
            }
        } else {
            border_run = 0;
        }
        if b == b':' || b == b'.' {
            has_colon_or_dot = true;
        }
    }

    if has_border_5 {
        indicators += 1;
    }

    if find_ascii_ci(content, b"http://").is_some()
        || find_ascii_ci(content, b"https://").is_some()
        || find_ascii_ci(content, b"www.").is_some()
    {
        indicators += 1;
    }

    if find_ascii_ci(content, b"imdb.com").is_some()
        || find_ascii_ci(content, b"thetvdb.com").is_some()
        || find_ascii_ci(content, b"themoviedb.org").is_some()
        || find_ascii_ci(content, b"anidb.net").is_some()
    {
        indicators += 2;
    }

    if indicators >= 2 {
        return 1;
    }

    if !has_colon_or_dot {
        return 0;
    }

    -2
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_cp437_to_utf8(
    input_ptr: *const u8,
    input_len: usize,
    out_ptr: *mut u8,
    out_capacity: usize,
) -> isize {
    if input_ptr.is_null() {
        return -1;
    }
    let src = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    let mut out = unsafe { OutCursor::new(out_ptr, out_capacity) };
    cp437_to_utf8_or_passthrough(src, &mut out)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_normalize_nfo_encoding(
    input_ptr: *const u8,
    input_len: usize,
    out_ptr: *mut u8,
    out_capacity: usize,
) -> isize {
    if input_ptr.is_null() {
        return -1;
    }
    let src = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    let mut out = unsafe { OutCursor::new(out_ptr, out_capacity) };
    normalize_nfo_encoding_into(src, &mut out)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nfo_clean_content(
    input_ptr: *const u8,
    input_len: usize,
    out_ptr: *mut u8,
    out_capacity: usize,
) -> isize {
    if input_ptr.is_null() {
        return -1;
    }
    if input_len == 0 {
        return 0;
    }
    let src = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    let mut out = unsafe { OutCursor::new(out_ptr, out_capacity) };
    clean_nfo_content_into(src, &mut out);
    finish_cursor(out)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nfo_identity_text(
    input_ptr: *const u8,
    input_len: usize,
    out_ptr: *mut u8,
    out_capacity: usize,
) -> isize {
    if input_ptr.is_null() {
        return -1;
    }
    if input_len == 0 {
        return 0;
    }
    let src = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    let mut out = unsafe { OutCursor::new(out_ptr, out_capacity) };
    nfo_identity_text_into(src, &mut out);
    finish_cursor(out)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nfo_check_preconditions(
    input_ptr: *const u8,
    input_len: usize,
) -> i32 {
    if input_ptr.is_null() {
        return -1;
    }
    let src = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    let mut flags = 0i32;
    if matches_non_nfo_header(src) {
        flags |= 1;
    }
    if detect_binary_format(src) {
        flags |= 2;
    }
    if has_binary_control_chars(src) {
        flags |= 4;
    }
    flags
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nfo_validate_content(
    input_ptr: *const u8,
    input_len: usize,
) -> i32 {
    if input_ptr.is_null() {
        return -1;
    }
    let src = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    validate_nfo_content(src)
}

// ============================================================================
// NfoNameExtractor & FileNameExtractor Accelerators
// ============================================================================

#[inline]
fn is_php_trim_byte(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x00 | 0x0B)
}

#[inline]
fn php_trim(mut s: &[u8]) -> &[u8] {
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

/// Checks whether `src` consists solely of printable ASCII (`0x20..=0x7E`),
/// standard whitespace (`\t`, `\n`, `\r`), and valid 3-byte UTF-8 box-drawing/block
/// characters (`0xE2 0x94..=0x96 0x80..=0xBF`).
#[inline]
fn is_supported_nfo_bytes(src: &[u8]) -> bool {
    let mut i = 0usize;
    while i < src.len() {
        let b = src[i];
        if b < 0x80 {
            if (0x20..=0x7E).contains(&b) || matches!(b, b'\t' | b'\n' | b'\r') {
                i += 1;
            } else {
                return false;
            }
        } else if b == 0xE2
            && i + 2 < src.len()
            && (0x94..=0x96).contains(&src[i + 1])
            && (0x80..=0xBF).contains(&src[i + 2])
        {
            i += 3;
        } else {
            return false;
        }
    }
    true
}

const PREDB_WORD: u8 = 1;
const PREDB_T: u8 = 2;
const PREDB_S: u8 = 4;
const PREDB_U: u8 = 8;

const PREDB_BYTE_FLAGS: [u8; 256] = {
    let mut table = [0u8; 256];
    let mut b = 0usize;
    while b < 256 {
        let c = b as u8;
        let is_w = c.is_ascii_alphanumeric() || c == b'_';
        let is_t = is_w
            || c == b'.'
            || c == b'\''
            || c == b'('
            || c == b')'
            || c == b'['
            || c == b']'
            || c == b'-';
        let is_sp = c == b' ' || c == b'\t' || c == b'\n' || c == b'\r' || c == 0x0B || c == 0x0C;
        let is_s = is_sp || c == b'.' || c == b'_' || c == b'-';
        let is_u = is_t || is_sp;
        let mut flags = 0u8;
        if is_w {
            flags |= PREDB_WORD;
        }
        if is_t {
            flags |= PREDB_T;
        }
        if is_s {
            flags |= PREDB_S;
        }
        if is_u {
            flags |= PREDB_U;
        }
        table[b] = flags;
        b += 1;
    }
    table
};

#[inline]
fn is_predb_t_byte(b: u8) -> bool {
    (PREDB_BYTE_FLAGS[b as usize] & PREDB_T) != 0
}

#[inline]
fn is_predb_s_byte(b: u8) -> bool {
    (PREDB_BYTE_FLAGS[b as usize] & PREDB_S) != 0
}

#[inline]
fn is_predb_u_byte(b: u8) -> bool {
    (PREDB_BYTE_FLAGS[b as usize] & PREDB_U) != 0
}

/// Evaluates `preg_match(ReleaseUpdateService::PREDB_REGEX, input)` in O(N) time.
#[inline]
fn matches_predb_regex(bytes: &[u8]) -> bool {
    let n = bytes.len();
    let mut i = 0usize;
    while i < n {
        if !is_predb_t_byte(bytes[i]) {
            i += 1;
            continue;
        }
        let mut u_end = i + 1;
        let mut first_s = usize::MAX;
        while u_end < n && is_predb_u_byte(bytes[u_end]) {
            if first_s == usize::MAX && is_predb_s_byte(bytes[u_end]) {
                first_s = u_end;
            }
            u_end += 1;
        }
        if u_end - i >= 5 && first_s != usize::MAX {
            let min_d = (i + 3).max(first_s + 2);
            if u_end >= min_d + 2 {
                let mut d = u_end - 2;
                loop {
                    if matches!(bytes[d], b'-' | b'.')
                        && is_word_byte(bytes[d + 1])
                        && is_predb_t_byte(bytes[d - 1])
                    {
                        return true;
                    }
                    if d <= min_d {
                        break;
                    }
                    d -= 1;
                }
            }
        }
        i = u_end;
    }
    false
}

/// Iterates all `preg_match_all(ReleaseUpdateService::PREDB_REGEX, identity)` matches in O(N) time.
fn for_each_predb_regex_match<'a>(identity: &'a [u8], mut f: impl FnMut(&'a [u8]) -> bool) {
    let n = identity.len();
    let mut i = 0usize;
    while i < n {
        if !is_predb_t_byte(identity[i]) {
            i += 1;
            continue;
        }
        let mut u_end = i + 1;
        let mut first_s = usize::MAX;
        while u_end < n && is_predb_u_byte(identity[u_end]) {
            if first_s == usize::MAX && is_predb_s_byte(identity[u_end]) {
                first_s = u_end;
            }
            u_end += 1;
        }
        if u_end - i >= 5 && first_s != usize::MAX {
            let min_d = (i + 3).max(first_s + 2);
            if u_end >= min_d + 2 {
                let mut d = u_end - 2;
                loop {
                    if matches!(identity[d], b'-' | b'.')
                        && (PREDB_BYTE_FLAGS[identity[d + 1] as usize] & PREDB_WORD) != 0
                        && is_predb_t_byte(identity[d - 1])
                    {
                        let mut end = d + 2;
                        while end < u_end
                            && (PREDB_BYTE_FLAGS[identity[end] as usize] & PREDB_WORD) != 0
                        {
                            end += 1;
                        }
                        if !f(&identity[i..end]) {
                            return;
                        }
                        break;
                    }
                    if d <= min_d {
                        break;
                    }
                    d -= 1;
                }
            }
        }
        i = u_end;
    }
}

/// Matches `/^[ \t]*(?:Filename|File[ \t]+Name|Complete[ \t]+Name|Release(?:[ \t]+Name)?)[ \t]*[:=][ \t]*([^\r\n]+)$/i`
/// on a single line and returns `trim($fields[1])`.
#[inline]
fn extract_literal_name_field(line: &[u8]) -> Option<&[u8]> {
    let mut p = 0usize;
    while p < line.len() && matches!(line[p], b' ' | b'\t') {
        p += 1;
    }
    let rem = &line[p..];
    let after_label = if rem.len() >= 8 && rem[..8].eq_ignore_ascii_case(b"filename") {
        p + 8
    } else if rem.len() >= 4 && rem[..4].eq_ignore_ascii_case(b"file") {
        let mut q = p + 4;
        while q < line.len() && matches!(line[q], b' ' | b'\t') {
            q += 1;
        }
        if q == p + 4 || line.len() - q < 4 || !line[q..q + 4].eq_ignore_ascii_case(b"name") {
            return None;
        }
        q + 4
    } else if rem.len() >= 8 && rem[..8].eq_ignore_ascii_case(b"complete") {
        let mut q = p + 8;
        while q < line.len() && matches!(line[q], b' ' | b'\t') {
            q += 1;
        }
        if q == p + 8 || line.len() - q < 4 || !line[q..q + 4].eq_ignore_ascii_case(b"name") {
            return None;
        }
        q + 4
    } else if rem.len() >= 7 && rem[..7].eq_ignore_ascii_case(b"release") {
        let mut q = p + 7;
        let ws_start = q;
        while q < line.len() && matches!(line[q], b' ' | b'\t') {
            q += 1;
        }
        if q > ws_start && line.len() - q >= 4 && line[q..q + 4].eq_ignore_ascii_case(b"name") {
            let mut r = q + 4;
            while r < line.len() && matches!(line[r], b' ' | b'\t') {
                r += 1;
            }
            if r < line.len() && matches!(line[r], b':' | b'=') {
                q + 4
            } else {
                p + 7
            }
        } else {
            p + 7
        }
    } else {
        return None;
    };

    let mut q = after_label;
    while q < line.len() && matches!(line[q], b' ' | b'\t') {
        q += 1;
    }
    if q >= line.len() || !matches!(line[q], b':' | b'=') {
        return None;
    }
    q += 1;
    // `[^\r\n]+$` requires at least 1 byte after `[:=]`
    if q >= line.len() {
        return None;
    }
    Some(php_trim(&line[q..]))
}

/// Evaluates `FileNameCleaner::looksLikeSceneRelease` on an ASCII title that does not contain `__NZBSPLIT__`.
#[inline]
fn looks_like_scene_release_ascii(title: &[u8]) -> bool {
    let base = if let Some(dot) = title.iter().rposition(|&b| b == b'.') {
        let ext = &title[dot + 1..];
        if (2..=4).contains(&ext.len()) && ext.iter().all(|b| b.is_ascii_alphanumeric()) {
            &title[..dot]
        } else {
            title
        }
    } else {
        title
    };

    let Some(dash) = base.iter().rposition(|&b| b == b'-') else {
        return false;
    };
    let group = &base[dash + 1..];
    if !(2..=15).contains(&group.len()) || !group.iter().all(|b| b.is_ascii_alphanumeric()) {
        return false;
    }
    if !base.iter().any(|&b| matches!(b, b'.' | b'_' | b'-')) {
        return false;
    }

    const SCENE_TAGS: &[&[u8]] = &[
        b"720p",
        b"1080p",
        b"2160p",
        b"4k",
        b"x264",
        b"x265",
        b"hevc",
        b"xvid",
        b"divx",
        b"bluray",
        b"bdrip",
        b"dvdrip",
        b"hdtv",
        b"webrip",
        b"web-dl",
        b"webdl",
        b"aac",
        b"ac3",
        b"dts",
        b"flac",
        b"mp3",
        b"proper",
        b"repack",
        b"internal",
        b"retail",
        b"pal",
        b"ntsc",
        b"multi",
        b"dual",
    ];
    for &tag in SCENE_TAGS {
        if find_ascii_ci(base, tag).is_some() {
            return true;
        }
    }

    // Check `/s\d{1,2}e\d{1,3}/i`
    for i in 0..base.len() {
        if matches!(base[i], b's' | b'S') {
            let mut p = i + 1;
            while p < base.len() && base[p].is_ascii_digit() {
                p += 1;
            }
            if (1..=2).contains(&(p - (i + 1))) && p < base.len() && matches!(base[p], b'e' | b'E')
            {
                let e_start = p + 1;
                if e_start < base.len() && base[e_start].is_ascii_digit() {
                    return true;
                }
            }
        }
    }

    // Check `/[._-](19|20)\d{2}[._-]/i`
    if base.len() >= 6 {
        for i in 0..=base.len() - 6 {
            if matches!(base[i], b'.' | b'_' | b'-')
                && (&base[i + 1..i + 3] == b"19" || &base[i + 1..i + 3] == b"20")
                && base[i + 3].is_ascii_digit()
                && base[i + 4].is_ascii_digit()
                && matches!(base[i + 5], b'.' | b'_' | b'-')
            {
                return true;
            }
        }
    }

    false
}

/// Checks `/(:?^|[._-])(480p|576p|720p|1080[pi]|2160p|4320p|[48]k|web[._-]?dl|webrip|bluray|bdrip|brrip|dvdrip|hdtv|sdtv|x264|x265|h[._]?26[45]|hevc|avc|xvid|divx|aac|ac3|eac3|ddp?[0-9](?:[._][0-9])?|dts(?:[._-]?hd)?|flac|mp3|truehd|pal|ntsc)(?=$|[._-])/i`
fn stem_has_technical_token(stem: &[u8]) -> bool {
    let is_sep = |b: u8| matches!(b, b'.' | b'_' | b'-');
    let is_end = |pos: usize| pos == stem.len() || is_sep(stem[pos]);

    for i in 0..stem.len() {
        if i > 0 && !is_sep(stem[i - 1]) {
            continue;
        }
        let rem = &stem[i..];
        for &word in &[
            &b"480p"[..],
            b"576p",
            b"720p",
            b"1080p",
            b"1080i",
            b"2160p",
            b"4320p",
            b"4k",
            b"8k",
            b"webdl",
            b"webrip",
            b"bluray",
            b"bdrip",
            b"brrip",
            b"dvdrip",
            b"hdtv",
            b"sdtv",
            b"x264",
            b"x265",
            b"h264",
            b"h265",
            b"hevc",
            b"avc",
            b"xvid",
            b"divx",
            b"aac",
            b"ac3",
            b"eac3",
            b"dtshd",
            b"dts",
            b"flac",
            b"mp3",
            b"truehd",
            b"pal",
            b"ntsc",
        ] {
            if rem.len() >= word.len()
                && rem[..word.len()].eq_ignore_ascii_case(word)
                && is_end(i + word.len())
            {
                return true;
            }
        }
        // `web[._-]dl`
        if rem.len() >= 6
            && rem[..3].eq_ignore_ascii_case(b"web")
            && is_sep(rem[3])
            && rem[4..6].eq_ignore_ascii_case(b"dl")
            && is_end(i + 6)
        {
            return true;
        }
        // `h[._]26[45]`
        if rem.len() >= 5
            && matches!(rem[0], b'h' | b'H')
            && matches!(rem[1], b'.' | b'_')
            && &rem[2..4] == b"26"
            && matches!(rem[4], b'4' | b'5')
            && is_end(i + 5)
        {
            return true;
        }
        // `dts[._-]hd`
        if rem.len() >= 6
            && rem[..3].eq_ignore_ascii_case(b"dts")
            && is_sep(rem[3])
            && rem[4..6].eq_ignore_ascii_case(b"hd")
            && is_end(i + 6)
        {
            return true;
        }
        // `ddp?[0-9](?:[._][0-9])?`
        if rem.len() >= 3 && rem[..2].eq_ignore_ascii_case(b"dd") {
            let mut p = 2usize;
            if p < rem.len() && matches!(rem[p], b'p' | b'P') {
                p += 1;
            }
            if p < rem.len() && rem[p].is_ascii_digit() {
                p += 1;
                if is_end(i + p) {
                    return true;
                }
                if p + 1 < rem.len() && matches!(rem[p], b'.' | b'_') && rem[p + 1].is_ascii_digit()
                {
                    if is_end(i + p + 2) {
                        return true;
                    }
                }
            }
        }
    }
    false
}

/// Evaluates `FileNameCleaner::isLiteralTechnicalSceneTitle` for ASCII input.
fn is_literal_technical_scene_title_ascii(title: &[u8]) -> Option<bool> {
    if title.is_empty() || title.len() > 255 {
        return Some(false);
    }
    if title.iter().any(|&b| !(0x20..=0x7E).contains(&b)) {
        return None;
    }
    if crate::cleaning::nzbsplit_literal_payload_span(title).is_some()
        || find_ascii_ci(title, b"__nzbsplit__").is_some()
    {
        return None;
    }
    let Some(dash_pos) = title.iter().rposition(|&b| b == b'-') else {
        return Some(false);
    };
    let stem = &title[..dash_pos];
    let group = &title[dash_pos + 1..];
    if !(2..=15).contains(&group.len()) || !group.iter().all(|b| b.is_ascii_alphanumeric()) {
        return Some(false);
    }
    if stem.is_empty()
        || !stem[0].is_ascii_alphanumeric()
        || !stem
            .iter()
            .all(|&b| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'-'))
    {
        return Some(false);
    }

    // Reject `/[._-](?:sample|proof|thumbs?|part\d+|vol\d+(?:[+-]\d+)?)\z/i`
    if group.eq_ignore_ascii_case(b"sample")
        || group.eq_ignore_ascii_case(b"proof")
        || group.eq_ignore_ascii_case(b"thumb")
        || group.eq_ignore_ascii_case(b"thumbs")
        || (group.len() >= 5
            && group[..4].eq_ignore_ascii_case(b"part")
            && group[4..].iter().all(|b| b.is_ascii_digit()))
        || (group.len() >= 4
            && group[..3].eq_ignore_ascii_case(b"vol")
            && group[3..].iter().all(|b| b.is_ascii_digit()))
    {
        return Some(false);
    }
    if group.iter().all(|b| b.is_ascii_digit()) {
        // Check if `stem` ends with `[._-]vol\d+` (so `title` ends with `[._-]vol\d+-\d+`)
        let mut p = stem.len();
        while p > 0 && stem[p - 1].is_ascii_digit() {
            p -= 1;
        }
        if p < stem.len()
            && p >= 4
            && matches!(stem[p - 4], b'.' | b'_' | b'-')
            && stem[p - 3..p].eq_ignore_ascii_case(b"vol")
        {
            return Some(false);
        }
    }

    if !stem_has_technical_token(stem) || !looks_like_scene_release_ascii(title) {
        return Some(false);
    }

    crate::cleaning::is_plausible_release_title(title)
}

/// Core implementation of `NfoNameExtractor::predbCandidates(string $text)`.
/// Returns:
/// - `>= 0`: NUL-delimited candidate titles written to `out` (`complete = true`)
/// - `-2`: declined to PHP fallback
/// - `-4`: overflow (`> 100` unique candidates -> `['titles' => [], 'complete' => false]`)
/// - `<= -16`: `out` buffer too small (`-(required_len as isize)`)
pub(crate) fn nfo_predb_candidates_into(src: &[u8], out: &mut OutCursor<'_>) -> isize {
    if src.is_empty() {
        return 0;
    }
    if !is_supported_nfo_bytes(src) {
        return -2;
    }

    let needs_identity_copy = unsafe {
        !memchr(src.as_ptr(), b'\r' as i32, src.len()).is_null() || {
            let mut pos = 0usize;
            let mut found_source = false;
            while pos < src.len() {
                let p = memchr(src.as_ptr().add(pos), b'\n' as i32, src.len() - pos);
                let line_end = if p.is_null() {
                    src.len()
                } else {
                    p.offset_from(src.as_ptr()) as usize
                };
                if is_source_header_line(&src[pos..line_end]) {
                    found_source = true;
                    break;
                }
                pos = line_end + 1;
            }
            found_source
        }
    };

    let mut stack_ident = std::mem::MaybeUninit::<[u8; 16384]>::uninit();
    let mut heap_ident: Vec<u8> = Vec::new();
    let ident_slice: &[u8] = if !needs_identity_copy {
        src
    } else if src.len() <= 16384 {
        let ptr = stack_ident.as_mut_ptr().cast::<u8>();
        let mut cur = unsafe { OutCursor::new(ptr, 16384) };
        nfo_identity_text_into(src, &mut cur);
        unsafe { slice::from_raw_parts(ptr, cur.pos) }
    } else {
        heap_ident.resize(src.len(), 0);
        let mut cur = unsafe { OutCursor::new(heap_ident.as_mut_ptr(), heap_ident.len()) };
        nfo_identity_text_into(src, &mut cur);
        &heap_ident[..cur.pos]
    };

    struct CandidateSet<'i> {
        titles: [&'i [u8]; 100],
        len: usize,
        overflow: bool,
    }

    impl<'i> CandidateSet<'i> {
        #[inline]
        fn insert(&mut self, cand: &'i [u8]) -> bool {
            if cand.is_empty() || cand.len() > 255 {
                return true;
            }
            if cand.iter().any(|&b| b < 0x20 || b == 0x7F) {
                return true;
            }
            for existing in self.titles.iter_mut().take(self.len) {
                if existing.eq_ignore_ascii_case(cand) {
                    *existing = cand;
                    return true;
                }
            }
            if self.len >= 100 {
                self.overflow = true;
                return false;
            }
            self.titles[self.len] = cand;
            self.len += 1;
            true
        }
    }

    let mut cands = CandidateSet {
        titles: [&[]; 100],
        len: 0,
        overflow: false,
    };

    // 1. `literalNameFields($identity)` -> `cleanForMatching($field)`
    let mut lpos = 0usize;
    while lpos < ident_slice.len() {
        let line_end = unsafe {
            let p = memchr(
                ident_slice.as_ptr().add(lpos),
                b'\n' as i32,
                ident_slice.len() - lpos,
            );
            if p.is_null() {
                ident_slice.len()
            } else {
                p.offset_from(ident_slice.as_ptr()) as usize
            }
        };
        let line = &ident_slice[lpos..line_end];
        lpos = line_end + 1;
        if line.len() >= 9 {
            let mut ws = 0usize;
            while ws < line.len() && matches!(line[ws], b' ' | b'\t') {
                ws += 1;
            }
            if ws < line.len()
                && matches!(line[ws], b'f' | b'F' | b'c' | b'C' | b'r' | b'R')
                && let Some(field) = extract_literal_name_field(line)
            {
                let span = crate::filename::clean_for_matching_span(field, false);
                if span == -2 {
                    return -2;
                }
                if span >= 0 {
                    let start = (span >> 32) as usize;
                    let len = (span & 0xFFFF_FFFF) as usize;
                    let cleaned = &field[start..start + len];
                    if !cands.insert(cleaned) {
                        return -4;
                    }
                }
            }
        }
    }

    // 2. `preg_match_all(ReleaseUpdateService::PREDB_REGEX, $identity, $matches)`
    for_each_predb_regex_match(ident_slice, |matched| {
        let trimmed = php_trim(matched);
        cands.insert(trimmed)
    });

    if cands.overflow {
        return -4;
    }
    if cands.len == 0 {
        return 0;
    }

    for (idx, &title) in cands.titles.iter().take(cands.len).enumerate() {
        if idx > 0 {
            out.push(0);
        }
        out.extend(title);
    }

    finish_cursor_min_ref(out, 16)
}

#[inline]
fn is_pcre_dot_to_underscore_or_space_ci(b: u8) -> bool {
    b == b' ' || (0x2E..=0x5F).contains(&b) || b.is_ascii_lowercase()
}

#[inline]
fn is_sep_dot_dash_under_space(b: u8) -> bool {
    matches!(b, b'.' | b'-' | b'_' | b' ')
}

#[inline]
fn is_year_19_20(s: &[u8], pos: usize) -> bool {
    pos + 4 <= s.len()
        && (&s[pos..pos + 2] == b"19" || &s[pos..pos + 2] == b"20")
        && s[pos + 2].is_ascii_digit()
        && s[pos + 3].is_ascii_digit()
}

#[inline]
fn has_nfo_end_delim(line: &[u8], pos: usize, has_trailing_newline: bool) -> bool {
    if pos == line.len() {
        has_trailing_newline
    } else if is_pcre_space(line[pos]) {
        if pos + 1 < line.len() {
            is_pcre_space(line[pos + 1])
        } else {
            has_trailing_newline
        }
    } else {
        false
    }
}

#[inline]
fn write_result_pair(out: &mut OutCursor<'_>, new_name: &[u8], method: &[u8]) {
    out.extend(new_name);
    out.push(0);
    out.extend(method);
}

#[inline]
fn write_replaced_result_pair(
    out: &mut OutCursor<'_>,
    haystack: &[u8],
    needle: &[u8],
    replacement: &[u8],
    method: &[u8],
) {
    if needle.is_empty() || haystack.len() < needle.len() {
        out.extend(haystack);
    } else {
        let mut i = 0usize;
        while i + needle.len() <= haystack.len() {
            if &haystack[i..i + needle.len()] == needle {
                out.extend(replacement);
                i += needle.len();
            } else {
                out.push(haystack[i]);
                i += 1;
            }
        }
        if i < haystack.len() {
            out.extend(&haystack[i..]);
        }
    }
    out.push(0);
    out.extend(method);
}

/// Evaluates `NfoNameExtractor::checkForTv` on `identity`.
fn check_for_tv_into(identity: &[u8], out: &mut OutCursor<'_>) -> bool {
    if identity.len() < 6
        || !(0..identity.len() - 1)
            .any(|k| matches!(identity[k], b's' | b'S') && identity[k + 1].is_ascii_digit())
    {
        return false;
    }
    // 1. Generic TV 1: `/:\s*.*[\\\\\/]([A-Z0-9].+?S\d+[.-_ ]?[ED]\d+.+?)\.\w{2,}\s+/i`
    let mut pos = 0usize;
    let mut carry_colon_ws = false;
    while pos < identity.len() {
        let line_start = pos;
        while pos < identity.len() && identity[pos] != b'\n' {
            pos += 1;
        }
        let line = &identity[line_start..pos];
        let has_nl = pos < identity.len();
        if has_nl {
            pos += 1;
        }

        let earliest_start = if carry_colon_ws {
            Some(0usize)
        } else {
            line.iter().position(|&b| b == b':').map(|c| c + 1)
        };

        if let Some(start_pos) = earliest_start {
            for slash_pos in (start_pos..line.len()).rev() {
                if !matches!(line[slash_pos], b'\\' | b'/') {
                    continue;
                }
                let g1_start = slash_pos + 1;
                if g1_start >= line.len() || !line[g1_start].is_ascii_alphanumeric() {
                    continue;
                }
                for s_pos in (g1_start + 1)..line.len() {
                    if !matches!(line[s_pos], b's' | b'S') {
                        continue;
                    }
                    let mut d1_end = s_pos + 1;
                    while d1_end < line.len() && line[d1_end].is_ascii_digit() {
                        d1_end += 1;
                    }
                    for d1_len in (1..=(d1_end - (s_pos + 1))).rev() {
                        let after_s = s_pos + 1 + d1_len;
                        for sep_len in [1usize, 0usize] {
                            if sep_len == 1
                                && (after_s >= line.len()
                                    || !is_pcre_dot_to_underscore_or_space_ci(line[after_s]))
                            {
                                continue;
                            }
                            let ed_pos = after_s + sep_len;
                            if ed_pos >= line.len()
                                || !matches!(line[ed_pos], b'e' | b'E' | b'd' | b'D')
                            {
                                continue;
                            }
                            let mut d2_end = ed_pos + 1;
                            while d2_end < line.len() && line[d2_end].is_ascii_digit() {
                                d2_end += 1;
                            }
                            for d2_len in (1..=(d2_end - (ed_pos + 1))).rev() {
                                let sed_end = ed_pos + 1 + d2_len;
                                for dot_pos in (sed_end + 1)..line.len() {
                                    if line[dot_pos] != b'.' {
                                        continue;
                                    }
                                    let mut w_end = dot_pos + 1;
                                    while w_end < line.len() && is_word_byte(line[w_end]) {
                                        w_end += 1;
                                    }
                                    if w_end - (dot_pos + 1) >= 2
                                        && ((w_end < line.len() && is_pcre_space(line[w_end]))
                                            || (w_end == line.len() && has_nl))
                                    {
                                        write_result_pair(
                                            out,
                                            &line[g1_start..dot_pos],
                                            b"Generic TV 1",
                                        );
                                        return true;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if has_nl {
            if let Some(last_colon) = line.iter().rposition(|&b| b == b':') {
                carry_colon_ws = line[last_colon + 1..].iter().all(|&b| is_pcre_space(b));
            } else if carry_colon_ws {
                carry_colon_ws = line.iter().all(|&b| is_pcre_space(b));
            }
        } else {
            carry_colon_ws = false;
        }
    }

    // 2. Generic TV 2: `/(?:(\:\s{1,}))(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+?)(\s{2,}|\r|\n)/i`
    for colon_pos in 0..identity.len() {
        if identity[colon_pos] != b':' {
            continue;
        }
        let mut ws_end = colon_pos + 1;
        while ws_end < identity.len() && is_pcre_space(identity[ws_end]) {
            ws_end += 1;
        }
        if ws_end == colon_pos + 1 {
            continue;
        }
        let mut ws_line_start = ws_end;
        while ws_line_start > colon_pos + 1 && identity[ws_line_start - 1] != b'\n' {
            ws_line_start -= 1;
        }
        let min_g2 = ws_line_start.max(colon_pos + 2);
        let mut line_end = ws_end;
        while line_end < identity.len() && identity[line_end] != b'\n' {
            line_end += 1;
        }
        let full_line = &identity[min_g2..line_end];
        if full_line.len() < 6
            || !(1..full_line.len() - 1)
                .any(|k| matches!(full_line[k], b's' | b'S') && full_line[k + 1].is_ascii_digit())
        {
            continue;
        }
        let has_nl = line_end < identity.len();
        for g2_start in (min_g2..=ws_end).rev() {
            let line = &identity[g2_start..line_end];
            if line.len() < 6 {
                continue;
            }
            for s_pos in 1..line.len() {
                if !matches!(line[s_pos], b's' | b'S') {
                    continue;
                }
                let mut d1_end = s_pos + 1;
                while d1_end < line.len()
                    && line[d1_end].is_ascii_digit()
                    && d1_end - (s_pos + 1) < 3
                {
                    d1_end += 1;
                }
                for d1_len in (1..=(d1_end - (s_pos + 1))).rev() {
                    let after_s = s_pos + 1 + d1_len;
                    for sep_len in [1usize, 0usize] {
                        if sep_len == 1
                            && (after_s >= line.len()
                                || !is_pcre_dot_to_underscore_or_space_ci(line[after_s]))
                        {
                            continue;
                        }
                        let ed_pos = after_s + sep_len;
                        if ed_pos >= line.len()
                            || !matches!(line[ed_pos], b'e' | b'E' | b'd' | b'D')
                        {
                            continue;
                        }
                        let mut d2_end = ed_pos + 1;
                        while d2_end < line.len()
                            && line[d2_end].is_ascii_digit()
                            && d2_end - (ed_pos + 1) < 3
                        {
                            d2_end += 1;
                        }
                        for d2_len in (1..=(d2_end - (ed_pos + 1))).rev() {
                            let sed_end = ed_pos + 1 + d2_len;
                            for g2_end in (sed_end + 1)..=line.len() {
                                if has_nfo_end_delim(line, g2_end, has_nl) {
                                    write_result_pair(out, &line[..g2_end], b"Generic TV 2");
                                    return true;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    false
}

#[inline]
fn try_movie_source_tag(line: &[u8], pos: usize, mut f: impl FnMut(usize) -> bool) -> bool {
    let rem = &line[pos..];
    // `(BDRip|bluray|DVD(R|Rip)?|XVID)`
    if rem.len() >= 5 && rem[..5].eq_ignore_ascii_case(b"bdrip") && f(pos + 5) {
        return true;
    }
    if rem.len() >= 6 && rem[..6].eq_ignore_ascii_case(b"bluray") && f(pos + 6) {
        return true;
    }
    if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"dvd") {
        if rem.len() >= 4 && matches!(rem[3], b'r' | b'R') && f(pos + 4) {
            return true;
        }
        if rem.len() >= 6 && rem[3..6].eq_ignore_ascii_case(b"rip") && f(pos + 6) {
            return true;
        }
        if f(pos + 3) {
            return true;
        }
    }
    if rem.len() >= 4 && rem[..4].eq_ignore_ascii_case(b"xvid") && f(pos + 4) {
        return true;
    }
    false
}

/// Evaluates `NfoNameExtractor::checkForMovie` on `identity`.
fn check_for_movie_into(identity: &[u8], out: &mut OutCursor<'_>) -> bool {
    let has_year_and_source = identity.len() >= 10
        && (0..=identity.len() - 4).any(|k| is_year_19_20(identity, k))
        && (find_ascii_ci(identity, b"bdrip").is_some()
            || find_ascii_ci(identity, b"bluray").is_some()
            || find_ascii_ci(identity, b"dvd").is_some()
            || find_ascii_ci(identity, b"xvid").is_some());

    // 1. Generic Movies 1: `/(?:((?!Source\s)\:\s{1,}))(.+?(19|20)\d\d.+?(BDRip|bluray|DVD(R|Rip)?|XVID).+?)(\s{2,}|\r|\n)/i`
    if has_year_and_source {
        for colon_pos in 0..identity.len() {
            if identity[colon_pos] != b':' {
                continue;
            }
            let mut ws_end = colon_pos + 1;
            while ws_end < identity.len() && is_pcre_space(identity[ws_end]) {
                ws_end += 1;
            }
            if ws_end == colon_pos + 1 {
                continue;
            }
            let mut ws_line_start = ws_end;
            while ws_line_start > colon_pos + 1 && identity[ws_line_start - 1] != b'\n' {
                ws_line_start -= 1;
            }
            let min_g2 = ws_line_start.max(colon_pos + 2);
            let mut line_end = ws_end;
            while line_end < identity.len() && identity[line_end] != b'\n' {
                line_end += 1;
            }
            let full_line = &identity[min_g2..line_end];
            if full_line.len() < 10
                || !(0..=full_line.len() - 4).any(|k| is_year_19_20(full_line, k))
            {
                continue;
            }
            let has_nl = line_end < identity.len();
            for g2_start in (min_g2..=ws_end).rev() {
                let line = &identity[g2_start..line_end];
                if line.len() < 10 {
                    continue;
                }
                for y_pos in 1..line.len() {
                    if !is_year_19_20(line, y_pos) {
                        continue;
                    }
                    let y_end = y_pos + 4;
                    for m_pos in (y_end + 1)..line.len() {
                        let mut matched_end = None;
                        try_movie_source_tag(line, m_pos, |m_end| {
                            for g2_end in (m_end + 1)..=line.len() {
                                if has_nfo_end_delim(line, g2_end, has_nl) {
                                    matched_end = Some(g2_end);
                                    return true;
                                }
                            }
                            false
                        });
                        if let Some(g2_end) = matched_end {
                            write_result_pair(out, &line[..g2_end], b"Generic Movies 1");
                            return true;
                        }
                    }
                }
            }
        }

        // 2. Generic Movies 2: `/(?:(\s{2,}))((?!Source).+?[\.\-_ ](19|20)\d\d.+?(BDRip|bluray|DVD(R|Rip)?|XVID).+?)(\s{2,}|\r|\n)/i`
        let mut i = 0usize;
        while i < identity.len() {
            if !is_pcre_space(identity[i]) {
                i += 1;
                continue;
            }
            let ws_start = i;
            while i < identity.len() && is_pcre_space(identity[i]) {
                i += 1;
            }
            let ws_end = i;
            if ws_end - ws_start < 2 {
                continue;
            }
            let mut ws_line_start = ws_end;
            while ws_line_start > ws_start && identity[ws_line_start - 1] != b'\n' {
                ws_line_start -= 1;
            }
            let min_g2 = ws_line_start.max(ws_start + 2);
            let mut line_end = ws_end;
            while line_end < identity.len() && identity[line_end] != b'\n' {
                line_end += 1;
            }
            let full_line = &identity[min_g2..line_end];
            if full_line.len() < 11
                || !(0..=full_line.len() - 4).any(|k| is_year_19_20(full_line, k))
            {
                continue;
            }
            let has_nl = line_end < identity.len();
            for g2_start in (min_g2..=ws_end).rev() {
                let line = &identity[g2_start..line_end];
                if line.len() < 11 || (line.len() >= 6 && line[..6].eq_ignore_ascii_case(b"source"))
                {
                    continue;
                }
                for sep_pos in 1..line.len() {
                    if !is_sep_dot_dash_under_space(line[sep_pos])
                        || !is_year_19_20(line, sep_pos + 1)
                    {
                        continue;
                    }
                    let y_end = sep_pos + 5;
                    for m_pos in (y_end + 1)..line.len() {
                        let mut matched_end = None;
                        try_movie_source_tag(line, m_pos, |m_end| {
                            for g2_end in (m_end + 1)..=line.len() {
                                if has_nfo_end_delim(line, g2_end, has_nl) {
                                    matched_end = Some(g2_end);
                                    return true;
                                }
                            }
                            false
                        });
                        if let Some(g2_end) = matched_end {
                            write_result_pair(out, &line[..g2_end], b"Generic Movies 2");
                            return true;
                        }
                    }
                }
            }
        }
    }

    // 3. Generic Movies 3: `/(?:(\s{2,}))(.+?[\.\-_ ](NTSC|MULTi).+?(MULTi|DVDR)[\.\-_ ].+?)(\s{2,}|\r|\n)/i`
    if find_ascii_ci(identity, b"ntsc").is_none() && find_ascii_ci(identity, b"multi").is_none() {
        return false;
    }
    let mut i = 0usize;
    while i < identity.len() {
        if !is_pcre_space(identity[i]) {
            i += 1;
            continue;
        }
        let ws_start = i;
        while i < identity.len() && is_pcre_space(identity[i]) {
            i += 1;
        }
        let ws_end = i;
        if ws_end - ws_start < 2 {
            continue;
        }
        let mut ws_line_start = ws_end;
        while ws_line_start > ws_start && identity[ws_line_start - 1] != b'\n' {
            ws_line_start -= 1;
        }
        let min_g2 = ws_line_start.max(ws_start + 2);
        let mut line_end = ws_end;
        while line_end < identity.len() && identity[line_end] != b'\n' {
            line_end += 1;
        }
        let full_line = &identity[min_g2..line_end];
        if full_line.len() < 12
            || (find_ascii_ci(full_line, b"ntsc").is_none()
                && find_ascii_ci(full_line, b"multi").is_none())
        {
            continue;
        }
        let has_nl = line_end < identity.len();
        for g2_start in (min_g2..=ws_end).rev() {
            let line = &identity[g2_start..line_end];
            if line.len() < 12 {
                continue;
            }
            for sep1 in 1..line.len() {
                if !is_sep_dot_dash_under_space(line[sep1]) {
                    continue;
                }
                let rem1 = &line[sep1 + 1..];
                let t1_len = if rem1.len() >= 4 && rem1[..4].eq_ignore_ascii_case(b"ntsc") {
                    4
                } else if rem1.len() >= 5 && rem1[..5].eq_ignore_ascii_case(b"multi") {
                    5
                } else {
                    continue;
                };
                let t1_end = sep1 + 1 + t1_len;
                for t2_pos in (t1_end + 1)..line.len() {
                    let rem2 = &line[t2_pos..];
                    for &t2_word in &[&b"multi"[..], b"dvdr"] {
                        if rem2.len() > t2_word.len()
                            && rem2[..t2_word.len()].eq_ignore_ascii_case(t2_word)
                            && is_sep_dot_dash_under_space(rem2[t2_word.len()])
                        {
                            let after_sep2 = t2_pos + t2_word.len() + 1;
                            for g2_end in (after_sep2 + 1)..=line.len() {
                                if has_nfo_end_delim(line, g2_end, has_nl) {
                                    write_result_pair(out, &line[..g2_end], b"Generic Movies 3");
                                    return true;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    false
}

/// Evaluates `NfoNameExtractor::checkForMusic` on `identity`.
fn check_for_music_into(identity: &[u8], out: &mut OutCursor<'_>) -> bool {
    if find_ascii_ci(identity, b"-fm-").is_none() {
        return false;
    }
    // `/(?:\s{2,})(.+?-FM-\d{2}-\d{2})/i`
    let mut i = 0usize;
    while i < identity.len() {
        if !is_pcre_space(identity[i]) {
            i += 1;
            continue;
        }
        let ws_start = i;
        while i < identity.len() && is_pcre_space(identity[i]) {
            i += 1;
        }
        let ws_end = i;
        if ws_end - ws_start < 2 {
            continue;
        }
        let mut ws_line_start = ws_end;
        while ws_line_start > ws_start && identity[ws_line_start - 1] != b'\n' {
            ws_line_start -= 1;
        }
        let min_g1 = ws_line_start.max(ws_start + 2);
        let mut line_end = ws_end;
        while line_end < identity.len() && identity[line_end] != b'\n' {
            line_end += 1;
        }
        let full_line = &identity[min_g1..line_end];
        if full_line.len() < 10 || find_ascii_ci(full_line, b"-fm-").is_none() {
            continue;
        }
        for g1_start in (min_g1..=ws_end).rev() {
            let line = &identity[g1_start..line_end];
            if line.len() < 10 {
                continue;
            }
            for fm_pos in 1..=(line.len() - 9) {
                if line[fm_pos..fm_pos + 4].eq_ignore_ascii_case(b"-fm-")
                    && line[fm_pos + 4].is_ascii_digit()
                    && line[fm_pos + 5].is_ascii_digit()
                    && line[fm_pos + 6] == b'-'
                    && line[fm_pos + 7].is_ascii_digit()
                    && line[fm_pos + 8].is_ascii_digit()
                {
                    let matched = &line[..fm_pos + 9];
                    write_replaced_result_pair(
                        out,
                        matched,
                        b"-FM-",
                        b"-FM-Radio-MP3-",
                        b"Music FM RADIO",
                    );
                    return true;
                }
            }
        }
    }
    false
}

#[inline]
fn is_wb_at(line: &[u8], pos: usize) -> bool {
    let left = pos > 0 && is_word_byte(line[pos - 1]);
    let right = pos < line.len() && is_word_byte(line[pos]);
    left != right
}

#[inline]
fn is_ty_char(b: u8) -> bool {
    (0x20..=0x7E).contains(&b) && b != b':' && b != b'\\'
}

#[inline]
fn is_ig_char(b: u8) -> bool {
    (0x20..=0x7E).contains(&b) && b != b'\\'
}

fn find_title_year_match(identity: &[u8]) -> Option<&[u8]> {
    // `/(\w[\-\w`~!@#$%^&*()_+={}|"<>?\[\]\\;\',.\/ ]+\s?\((19|20)\d\d\))/i`
    if find_ascii_ci(identity, b"(19").is_none() && find_ascii_ci(identity, b"(20").is_none() {
        return None;
    }
    let n = identity.len();
    let mut i = 0usize;
    while i < n {
        if !is_word_byte(identity[i]) {
            i += 1;
            continue;
        }
        let mut run_end = i + 1;
        while run_end < n && is_ty_char(identity[run_end]) {
            run_end += 1;
        }
        let check_end = (run_end + 2).min(n);
        if check_end > i + 2 {
            let span = &identity[i + 2..check_end];
            let has_paren = unsafe { !memchr(span.as_ptr(), b'(' as i32, span.len()).is_null() };
            if has_paren {
                // Try `is_ty_char+` stopping at `p` from `run_end` down to `i + 2`
                for p in ((i + 2)..=run_end).rev() {
                    // Try `\s?` with 1 byte, then 0 bytes
                    for ws_len in [1usize, 0usize] {
                        if ws_len == 1 && (p >= n || !is_pcre_space(identity[p])) {
                            continue;
                        }
                        let paren_pos = p + ws_len;
                        if paren_pos + 6 <= n
                            && identity[paren_pos] == b'('
                            && is_year_19_20(identity, paren_pos + 1)
                            && identity[paren_pos + 5] == b')'
                        {
                            return Some(&identity[i..paren_pos + 6]);
                        }
                    }
                }
            }
        }
        i = run_end;
    }
    None
}

fn extract_language_from_nfo<'a>(metadata: &'a [u8]) -> Option<&'a [u8]> {
    const LANG_KEYWORDS: &[&[u8]] = &[b"idiomas", b"lang", b"language", b"langue", b"sprache"];
    const LANG_NAMES: &[&[u8]] = &[
        b"brazilian",
        b"chinese",
        b"croatian",
        b"danish",
        b"de",
        b"deutsch",
        b"dutch",
        b"estonian",
        b"es",
        b"english",
        b"englisch",
        b"finnish",
        b"flemish",
        b"francais",
        b"french",
        b"fr",
        b"german",
        b"greek",
        b"hebrew",
        b"icelandic",
        b"italian",
        b"japenese",
        b"japan",
        b"japanese",
        b"korean",
        b"latin",
        b"nordic",
        b"norwegian",
        b"polish",
        b"portuguese",
        b"russian",
        b"serbian",
        b"slovenian",
        b"swedish",
        b"spanisch",
        b"spanish",
        b"thai",
        b"turkish",
    ];

    for line in metadata.split(|&b| b == b'\n') {
        if line.len() < 4 {
            continue;
        }
        'line_scan: for p in 0..=line.len() - 4 {
            let b0 = line[p].to_ascii_lowercase();
            if !matches!(b0, b'i' | b'l' | b's') {
                continue;
            }
            let b1 = line[p + 1].to_ascii_lowercase();
            if !matches!((b0, b1), (b'i', b'd') | (b'l', b'a') | (b's', b'p')) {
                continue;
            }
            for &kw in LANG_KEYWORDS {
                if line.len() - p >= kw.len() && line[p..p + kw.len()].eq_ignore_ascii_case(kw) {
                    for q in (p + kw.len())..line.len() {
                        if !is_wb_at(line, q) {
                            continue;
                        }
                        let q_lo = line[q].to_ascii_lowercase();
                        for &lang in LANG_NAMES {
                            if lang[0] == q_lo
                                && line.len() - q >= lang.len()
                                && line[q + 1..q + lang.len()].eq_ignore_ascii_case(&lang[1..])
                                && is_wb_at(line, q + lang.len())
                            {
                                let captured = &line[q..q + lang.len()];
                                return Some(match captured {
                                    b"DE" => b"GERMAN",
                                    b"Englisch" => b"ENGLISH",
                                    b"FR" => b"FRENCH",
                                    b"ES" => b"SPANISH",
                                    _ => captured,
                                });
                            }
                        }
                    }
                    break 'line_scan;
                }
            }
        }
    }
    None
}

fn extract_resolution_from_nfo<'a>(metadata: &'a [u8]) -> Option<&'a [u8]> {
    // 1. `/(frame size|(video )?res(olution)?|video).*?(?P<res>(272|336|480|494|528|608|\(?640|688|704|720x480|810|816|820|1 ?080|1280( \@)?|1 ?920(x1080)?))/i`
    const RES1_KW: &[&[u8]] = &[
        b"frame size",
        b"video resolution",
        b"video res",
        b"resolution",
        b"res",
        b"video",
    ];
    const RES1_VALS: &[&[u8]] = &[
        b"272",
        b"336",
        b"480",
        b"494",
        b"528",
        b"608",
        b"(640",
        b"640",
        b"688",
        b"704",
        b"720x480",
        b"810",
        b"816",
        b"820",
        b"1 080",
        b"1080",
        b"1280 @",
        b"1280",
        b"1 920x1080",
        b"1 920",
        b"1920x1080",
        b"1920",
    ];

    for line in metadata.split(|&b| b == b'\n') {
        if line.len() < 3 {
            continue;
        }
        'line_scan: for p in 0..=line.len() - 3 {
            let b0 = line[p].to_ascii_lowercase();
            if !matches!(b0, b'f' | b'v' | b'r') {
                continue;
            }
            let b1 = line[p + 1].to_ascii_lowercase();
            if !matches!((b0, b1), (b'f', b'r') | (b'v', b'i') | (b'r', b'e')) {
                continue;
            }
            for &kw in RES1_KW {
                if line.len() - p >= kw.len() && line[p..p + kw.len()].eq_ignore_ascii_case(kw) {
                    for q in (p + kw.len())..line.len() {
                        let bq = line[q];
                        if !matches!(bq, b'(' | b'1'..=b'8') {
                            continue;
                        }
                        for &val in RES1_VALS {
                            if val[0] == bq
                                && line.len() - q >= val.len()
                                && line[q + 1..q + val.len()].eq_ignore_ascii_case(&val[1..])
                            {
                                let captured = &line[q..q + val.len()];
                                return Some(match captured {
                                    b"272" | b"336" | b"480" | b"494" | b"608" | b"640"
                                    | b"(640" | b"688" | b"704" | b"720x480" => b"480p",
                                    b"1280x720" | b"1280" | b"1280 @" => b"720p",
                                    b"810" | b"816" | b"820" | b"1920" | b"1 920" | b"1080"
                                    | b"1 080" | b"1920x1080" => b"1080p",
                                    b"2160" => b"2160p",
                                    _ => captured,
                                });
                            }
                        }
                    }
                    break 'line_scan;
                }
            }
        }
    }

    // 2. `/(largeur|width).*?(?P<res>(\(?640|688|704|720|1280( \@)?|1 ?920))/i`
    const RES2_KW: &[&[u8]] = &[b"largeur", b"width"];
    const RES2_VALS: &[&[u8]] = &[
        b"(640", b"640", b"688", b"704", b"720", b"1280 @", b"1280", b"1 920", b"1920",
    ];

    for line in metadata.split(|&b| b == b'\n') {
        if line.len() < 5 {
            continue;
        }
        'line_scan: for p in 0..=line.len() - 5 {
            let b0 = line[p].to_ascii_lowercase();
            if !matches!(b0, b'l' | b'w') {
                continue;
            }
            let b1 = line[p + 1].to_ascii_lowercase();
            if !matches!((b0, b1), (b'l', b'a') | (b'w', b'i')) {
                continue;
            }
            for &kw in RES2_KW {
                if line.len() - p >= kw.len() && line[p..p + kw.len()].eq_ignore_ascii_case(kw) {
                    for q in (p + kw.len())..line.len() {
                        let bq = line[q];
                        if !matches!(bq, b'(' | b'1' | b'6' | b'7') {
                            continue;
                        }
                        for &val in RES2_VALS {
                            if val[0] == bq
                                && line.len() - q >= val.len()
                                && line[q + 1..q + val.len()].eq_ignore_ascii_case(&val[1..])
                            {
                                let captured = &line[q..q + val.len()];
                                return Some(match captured {
                                    b"640" | b"(640" | b"688" | b"704" | b"720" => b"480p",
                                    b"1280 @" | b"1280" => b"720p",
                                    b"1920" | b"1 920" => b"1080p",
                                    b"2160" => b"2160p",
                                    _ => captured,
                                });
                            }
                        }
                    }
                    break 'line_scan;
                }
            }
        }
    }

    None
}

fn extract_source_from_nfo<'a>(metadata: &'a [u8]) -> Option<&'a [u8]> {
    // 1. `/source.*?\b(?P<source>BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)\b/i`
    const SRC_VALS: &[&[u8]] = &[
        b"bd-25",
        b"bd-50",
        b"bd-rip",
        b"bd25",
        b"bd50",
        b"bdrip",
        b"bd",
        b"blu-ray 3d",
        b"blu-ray3d",
        b"blu-ray ",
        b"blu-ray",
        b"bluray 3d",
        b"bluray3d",
        b"bluray ",
        b"bluray",
        b"brrip",
        b"camrip",
        b"cam",
        b"dbrip",
        b"dtv",
        b"dvd-5",
        b"dvd-9",
        b"dvd-rip",
        b"dvd-r",
        b"dvd-screener",
        b"dvd-scr",
        b"dvd5",
        b"dvd9",
        b"dvdrip",
        b"dvdr",
        b"dvdscreener",
        b"dvdscr",
        b"dvd",
        b"hdrip",
        b"hdtvrip",
        b"hdtv",
        b"hd",
        b"hrip",
        b"htvrip",
        b"htv",
        b"h",
        b"pdrip",
        b"pdtvrip",
        b"pdtv",
        b"pd",
        b"prip",
        b"ptvrip",
        b"ptv",
        b"p",
        b"sdrip",
        b"sdtvrip",
        b"sdtv",
        b"sd",
        b"srip",
        b"stvrip",
        b"stv",
        b"s",
        b"ntsc",
        b"pal",
        b"r5",
        b"ripped ",
        b"svcd",
        b"vcd",
        b"screener",
        b"scr",
        b"satrip",
        b"sat",
        b"ts",
        b"vhsrip",
        b"vhs",
        b"vod",
        b"web-dl",
    ];

    for line in metadata.split(|&b| b == b'\n') {
        if line.len() < 6 {
            continue;
        }
        for p in 0..=line.len() - 6 {
            if matches!(line[p], b's' | b'S')
                && matches!(line[p + 1], b'o' | b'O')
                && line[p + 2..p + 6].eq_ignore_ascii_case(b"urce")
            {
                for q in (p + 6)..line.len() {
                    if !is_wb_at(line, q) {
                        continue;
                    }
                    let q_lo = line[q].to_ascii_lowercase();
                    for &val in SRC_VALS {
                        if val[0] == q_lo
                            && line.len() - q >= val.len()
                            && line[q + 1..q + val.len()].eq_ignore_ascii_case(&val[1..])
                            && is_wb_at(line, q + val.len())
                        {
                            let captured = &line[q..q + val.len()];
                            return Some(match captured {
                                b"BD" => b"Bluray.x264",
                                b"CAMRIP" => b"CAM",
                                b"DBrip" => b"BDRIP",
                                b"DVD R1" | b"NTSC" | b"PAL" | b"VOD" => b"DVD",
                                b"HD" => b"HDTV",
                                b"Ripped " => b"DVDRIP",
                                _ => captured,
                            });
                        }
                    }
                }
                break;
            }
        }
    }

    // 2. `/(codec( (name|code))?|(original )?format|res(olution)|video( (codec|format|res))?|tv system|type|writing library).*?\b(?P<video>AVC|AVI|DBrip|DIVX|\(Divx|DVD|[HX][._ -]?264|MPEG-4 Visual|NTSC|PAL|WMV|XVID)\b/i`
    const VID_KW: &[&[u8]] = &[
        b"codec name",
        b"codec code",
        b"codec",
        b"original format",
        b"format",
        b"resolution",
        b"video codec",
        b"video format",
        b"video res",
        b"video",
        b"tv system",
        b"type",
        b"writing library",
    ];
    const VID_VALS: &[&[u8]] = &[
        b"avc",
        b"avi",
        b"dbrip",
        b"divx",
        b"(divx",
        b"dvd",
        b"h.264",
        b"h_264",
        b"h 264",
        b"h-264",
        b"h264",
        b"x.264",
        b"x_264",
        b"x 264",
        b"x-264",
        b"x264",
        b"mpeg-4 visual",
        b"ntsc",
        b"pal",
        b"wmv",
        b"xvid",
    ];

    for line in metadata.split(|&b| b == b'\n') {
        if line.len() < 4 {
            continue;
        }
        'line_scan: for p in 0..=line.len() - 4 {
            let b0 = line[p].to_ascii_lowercase();
            if !matches!(b0, b'c' | b'o' | b'f' | b'r' | b'v' | b't' | b'w') {
                continue;
            }
            let b1 = line[p + 1].to_ascii_lowercase();
            if !matches!(
                (b0, b1),
                (b'c', b'o')
                    | (b'o', b'r')
                    | (b'f', b'o')
                    | (b'r', b'e')
                    | (b'v', b'i')
                    | (b't', b'v')
                    | (b't', b'y')
                    | (b'w', b'r')
            ) {
                continue;
            }
            for &kw in VID_KW {
                if line.len() - p >= kw.len() && line[p..p + kw.len()].eq_ignore_ascii_case(kw) {
                    for q in (p + kw.len())..line.len() {
                        if !is_wb_at(line, q) {
                            continue;
                        }
                        let q_lo = line[q].to_ascii_lowercase();
                        for &val in VID_VALS {
                            if val[0] == q_lo
                                && line.len() - q >= val.len()
                                && line[q + 1..q + val.len()].eq_ignore_ascii_case(&val[1..])
                                && is_wb_at(line, q + val.len())
                            {
                                let captured = &line[q..q + val.len()];
                                return Some(match captured {
                                    b"AVI" => b"DVDRIP",
                                    b"DBrip" => b"BDRIP",
                                    b"(Divx" => b"DIVX",
                                    b"h264" | b"h-264" | b"h.264" => b"H264",
                                    b"MPEG-4 Visual" | b"x264" | b"x-264" | b"x.264" => b"x264",
                                    b"NTSC" | b"PAL" => b"DVD",
                                    _ => captured,
                                });
                            }
                        }
                    }
                    break 'line_scan;
                }
            }
        }
    }

    None
}

fn match_audio_token_at(line: &[u8], q: usize) -> Option<&[u8]> {
    // `\b(?P<audio>0x0055 MPEG-1 Layer 3|AAC( LC)?|AC-?3|\(AC3|DD5(.1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby(\s?TrueHD)?|TrueHD|FLAC|MP3)\b`
    if !is_wb_at(line, q)
        || !matches!(
            line[q].to_ascii_lowercase(),
            b'0' | b'a' | b'(' | b'd' | b't' | b'f' | b'm'
        )
    {
        return None;
    }
    let rem = &line[q..];
    for &lit in &[
        &b"0x0055 mpeg-1 layer 3"[..],
        b"aac lc",
        b"aac",
        b"ac-3",
        b"ac3",
        b"(ac3",
    ] {
        if rem.len() >= lit.len()
            && rem[..lit.len()].eq_ignore_ascii_case(lit)
            && is_wb_at(line, q + lit.len())
        {
            return Some(&rem[..lit.len()]);
        }
    }
    // `DD5(.1)?`
    if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"dd5") {
        if rem.len() >= 5 && rem[4] == b'1' && is_wb_at(line, q + 5) {
            return Some(&rem[..5]);
        }
        if is_wb_at(line, q + 3) {
            return Some(&rem[..3]);
        }
    }
    // `(A_)?DTS-?(HD)?(-?MA)?`
    for &dts_val in &[
        &b"a_dts-hd-ma"[..],
        b"a_dts-hdma",
        b"a_dts-hd",
        b"a_dts-ma",
        b"a_dts-",
        b"a_dtshd-ma",
        b"a_dtshdma",
        b"a_dtshd",
        b"a_dtsma",
        b"a_dts",
        b"dts-hd-ma",
        b"dts-hdma",
        b"dts-hd",
        b"dts-ma",
        b"dts-",
        b"dtshd-ma",
        b"dtshdma",
        b"dtshd",
        b"dtsma",
        b"dts",
    ] {
        if rem.len() >= dts_val.len()
            && rem[..dts_val.len()].eq_ignore_ascii_case(dts_val)
            && is_wb_at(line, q + dts_val.len())
        {
            return Some(&rem[..dts_val.len()]);
        }
    }
    // `Dolby(\s?TrueHD)?`
    if rem.len() >= 5 && rem[..5].eq_ignore_ascii_case(b"dolby") {
        if rem.len() >= 12
            && is_pcre_space(rem[5])
            && rem[6..12].eq_ignore_ascii_case(b"truehd")
            && is_wb_at(line, q + 12)
        {
            return Some(&rem[..12]);
        }
        if rem.len() >= 11 && rem[5..11].eq_ignore_ascii_case(b"truehd") && is_wb_at(line, q + 11) {
            return Some(&rem[..11]);
        }
        if is_wb_at(line, q + 5) {
            return Some(&rem[..5]);
        }
    }
    for &lit in &[&b"truehd"[..], b"flac", b"mp3"] {
        if rem.len() >= lit.len()
            && rem[..lit.len()].eq_ignore_ascii_case(lit)
            && is_wb_at(line, q + lit.len())
        {
            return Some(&rem[..lit.len()]);
        }
    }
    None
}

fn extract_audio_from_nfo<'a>(metadata: &'a [u8]) -> Option<&'a [u8]> {
    const AUD_KW: &[&[u8]] = &[
        b"audio format",
        b"audio",
        b"codec name",
        b"codec",
        b"format",
    ];
    for line in metadata.split(|&b| b == b'\n') {
        if line.len() < 5 {
            continue;
        }
        'line_scan: for p in 0..=line.len() - 5 {
            let b0 = line[p].to_ascii_lowercase();
            if !matches!(b0, b'a' | b'c' | b'f') {
                continue;
            }
            let b1 = line[p + 1].to_ascii_lowercase();
            if !matches!((b0, b1), (b'a', b'u') | (b'c', b'o') | (b'f', b'o')) {
                continue;
            }
            for &kw in AUD_KW {
                if line.len() - p >= kw.len() && line[p..p + kw.len()].eq_ignore_ascii_case(kw) {
                    for q in (p + kw.len())..line.len() {
                        if let Some(captured) = match_audio_token_at(line, q) {
                            return Some(match captured {
                                b"0x0055 MPEG-1 Layer 3" => b"MP3",
                                b"AC-3" | b"(AC3" => b"AC3",
                                b"AAC LC" => b"AAC",
                                b"A_DTS" | b"DTS-HD" | b"DTSHD" => b"DTS",
                                _ => captured,
                            });
                        }
                    }
                    break 'line_scan;
                }
            }
        }
    }
    None
}

/// Evaluates `NfoNameExtractor::checkForTitleYear`.
fn check_for_title_year_into(identity: &[u8], metadata: &[u8], out: &mut OutCursor<'_>) -> bool {
    // Skip PDFs and audiobooks: `/\.pdf|Audio ?Book/i`
    if find_ascii_ci(identity, b".pdf").is_some()
        || find_ascii_ci(identity, b"audiobook").is_some()
        || find_ascii_ci(identity, b"audio book").is_some()
    {
        return false;
    }

    let Some(base_title) = find_title_year_match(identity) else {
        return false;
    };

    out.extend(base_title);
    if let Some(lang) = extract_language_from_nfo(metadata) {
        out.push(b'.');
        out.extend(lang);
    }
    if let Some(res) = extract_resolution_from_nfo(metadata) {
        out.push(b'.');
        out.extend(res);
    }
    if let Some(src) = extract_source_from_nfo(metadata) {
        out.push(b'.');
        out.extend(src);
    }
    if let Some(aud) = extract_audio_from_nfo(metadata) {
        out.push(b'.');
        out.extend(aud);
    }
    out.extend(b"-NoGroup");
    out.push(0);
    out.extend(b"Title (Year)");
    true
}

#[inline]
fn is_game_prefix_char(b: u8) -> bool {
    is_word_byte(b)
        || matches!(
            b,
            b'.' | b'+'
                | b'&'
                | b'*'
                | b'/'
                | b'('
                | b')'
                | b'\''
                | b','
                | b';'
                | b':'
                | b' '
                | b'-'
        )
}

#[inline]
fn is_game_suffix_char(b: u8) -> bool {
    is_word_byte(b) || matches!(b, b'-' | b'.' | b'\'' | b',' | b';' | b'&' | b' ')
}

/// Evaluates `NfoNameExtractor::checkForGame` on `identity`.
fn check_for_game_into(identity: &[u8], out: &mut OutCursor<'_>) -> bool {
    let has_c = find_ascii_ci(identity, b"(c)").is_some();
    let has_iso = find_ascii_ci(identity, b"*iso*").is_some();
    if !has_c && !has_iso {
        return false;
    }
    const GAME_GROUPS: &[&[u8]] = &[
        b"alias",
        b"bat-team",
        b"fairlight",
        b"game type",
        b"glamoury",
        b"hi2u",
        b"itwins",
        b"jaguar",
        b"largeiso",
        b"mediumiso",
        b"maze",
        b"nerv",
        b"prophet",
        b"profit",
        b"procyon",
        b"reloaded",
        b"revolver",
        b"rogue",
        b"vitality",
    ];
    if !GAME_GROUPS
        .iter()
        .any(|&grp| find_ascii_ci(identity, grp).is_some())
    {
        return false;
    }

    // 1. (c) pattern: `/\w[\w.+&*\/\()\',;: -]+\(c\)[\-\w.\',;& ]+\w/i`
    if has_c {
        let mut i = 0usize;
        while i < identity.len() {
            if !is_word_byte(identity[i]) {
                i += 1;
                continue;
            }
            let mut p_end = i + 1;
            while p_end < identity.len() && is_game_prefix_char(identity[p_end]) {
                p_end += 1;
            }
            // Look for rightmost `(c)` at `c_pos` in `(i + 2)..=(p_end.saturating_sub(3))`
            if p_end >= i + 5 {
                for c_pos in ((i + 2)..=(p_end - 3)).rev() {
                    if identity[c_pos..c_pos + 3].eq_ignore_ascii_case(b"(c)") {
                        let s_start = c_pos + 3;
                        let mut s_end = s_start;
                        while s_end < identity.len() && is_game_suffix_char(identity[s_end]) {
                            s_end += 1;
                        }
                        if s_end >= s_start + 2 {
                            for w_end in ((s_start + 1)..s_end).rev() {
                                if is_word_byte(identity[w_end]) {
                                    let matched = &identity[i..=w_end];
                                    // `str_replace(['(c)', '(C)'], '(GAMES) (c)', $result[0])`
                                    let mut k = 0usize;
                                    while k < matched.len() {
                                        if k + 3 <= matched.len()
                                            && matched[k..k + 3].eq_ignore_ascii_case(b"(c)")
                                        {
                                            out.extend(b"(GAMES) (c)");
                                            k += 3;
                                        } else {
                                            out.push(matched[k]);
                                            k += 1;
                                        }
                                    }
                                    out.push(0);
                                    out.extend(b"PC Games (c)");
                                    return true;
                                }
                            }
                        }
                    }
                }
            }
            i = p_end;
        }
    }

    // 2. *ISO* pattern: `/\w[\w.+&*\/()\',;: -]+\*ISO\*/i`
    if has_iso {
        let mut i = 0usize;
        while i < identity.len() {
            if !is_word_byte(identity[i]) {
                i += 1;
                continue;
            }
            let mut p_end = i + 1;
            while p_end < identity.len() && is_game_prefix_char(identity[p_end]) {
                p_end += 1;
            }
            if p_end >= i + 7 {
                for iso_pos in ((i + 2)..=(p_end - 5)).rev() {
                    if identity[iso_pos..iso_pos + 5].eq_ignore_ascii_case(b"*iso*") {
                        let matched = &identity[i..iso_pos + 5];
                        write_replaced_result_pair(
                            out,
                            matched,
                            b"*ISO*",
                            b"*ISO* (PC GAMES)",
                            b"PC Games *ISO*",
                        );
                        return true;
                    }
                }
            }
            i = p_end;
        }
    }

    false
}

/// Evaluates `NfoNameExtractor::checkForIguana` on `identity`.
/// Returns `Some(true)` on match, `Some(false)` on no match, or `None` when declined to PHP.
fn check_for_iguana_into(identity: &[u8], out: &mut OutCursor<'_>) -> Option<bool> {
    if find_ascii_ci(identity, b"iguana").is_none() {
        return Some(false);
    }
    // Check `/Supplier.+?IGUANA/i` on any line
    let mut has_supplier_iguana = false;
    for line in identity.split(|&b| b == b'\n') {
        if let Some(sup_pos) = find_ascii_ci(line, b"supplier") {
            if sup_pos + 9 <= line.len() && find_ascii_ci(&line[sup_pos + 9..], b"iguana").is_some()
            {
                has_supplier_iguana = true;
                break;
            }
        }
    }
    if !has_supplier_iguana {
        return Some(false);
    }

    let mut release_name: Vec<u8> = Vec::new();

    // 1. `/\w[\-\w`~!@#$%^&*()+={}|:"<>?\[\]\\;\',.\/ ]+\s\((19|20)\d\d\)/i`
    let n = identity.len();
    let mut i = 0usize;
    while i < n {
        if !is_word_byte(identity[i]) {
            i += 1;
            continue;
        }
        let mut run_end = i + 1;
        while run_end < n && is_ig_char(identity[run_end]) {
            run_end += 1;
        }
        let mut found_ty = None;
        for p in ((i + 2)..=run_end).rev() {
            if p < n
                && is_pcre_space(identity[p])
                && p + 7 <= n
                && identity[p + 1] == b'('
                && is_year_19_20(identity, p + 2)
                && identity[p + 6] == b')'
            {
                found_ty = Some(&identity[i..p + 7]);
                break;
            }
        }
        if let Some(matched) = found_ty {
            release_name.extend_from_slice(matched);
            break;
        }
        i = run_end;
    }

    // 2. `/\s\[\*\] (English|Dutch|French|German|Spanish)\b/i`
    for pos in 0..n {
        if is_pcre_space(identity[pos]) && pos + 5 <= n && &identity[pos + 1..pos + 5] == b"[*] " {
            let q = pos + 5;
            let mut matched_lang = None;
            for &lang in &[&b"english"[..], b"dutch", b"french", b"german", b"spanish"] {
                if n - q >= lang.len()
                    && identity[q..q + lang.len()].eq_ignore_ascii_case(lang)
                    && is_wb_at(identity, q + lang.len())
                {
                    matched_lang = Some(&identity[q..q + lang.len()]);
                    break;
                }
            }
            if let Some(lang) = matched_lang {
                release_name.push(b'.');
                release_name.extend_from_slice(lang);
                break;
            }
        }
    }

    // 3. `/\s\[\*\] (DT?S [2567][._ -][0-2]( MONO)?)\b/i`
    for pos in 0..n {
        if is_pcre_space(identity[pos]) && pos + 5 <= n && &identity[pos + 1..pos + 5] == b"[*] " {
            let q = pos + 5;
            let rem = &identity[q..];
            let dts_len = if rem.len() >= 4 && rem[..4].eq_ignore_ascii_case(b"dts ") {
                4
            } else if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"ds ") {
                3
            } else {
                0
            };
            if dts_len > 0 && rem.len() >= dts_len + 3 {
                let c1 = rem[dts_len];
                let sep = rem[dts_len + 1];
                let c2 = rem[dts_len + 2];
                if matches!(c1, b'2' | b'5' | b'6' | b'7')
                    && matches!(sep, b'.' | b'_' | b' ' | b'-')
                    && matches!(c2, b'0'..=b'2')
                {
                    let after_ch = q + dts_len + 3;
                    if n - after_ch >= 5
                        && identity[after_ch..after_ch + 5].eq_ignore_ascii_case(b" mono")
                        && is_wb_at(identity, after_ch + 5)
                    {
                        release_name.push(b'.');
                        release_name.extend_from_slice(&identity[after_ch..after_ch + 5]);
                        break;
                    } else if is_wb_at(identity, after_ch) {
                        // In PHP, `$result[2]` is undefined when `( MONO)?` is absent; decline to PHP.
                        return None;
                    }
                }
            }
        }
    }

    // 4. `/Format.+(DVD([59R])?|[HX][._ -]?264)\b/i`
    for line in identity.split(|&b| b == b'\n') {
        if let Some(fmt_pos) = find_ascii_ci(line, b"format") {
            let min_q = fmt_pos + 7;
            let mut matched_fmt = None;
            if line.len() > min_q {
                for q in (min_q..line.len()).rev() {
                    let rem = &line[q..];
                    for &pat in &[
                        &b"dvd5"[..],
                        b"dvd9",
                        b"dvdr",
                        b"dvd",
                        b"h.264",
                        b"h_264",
                        b"h 264",
                        b"h-264",
                        b"h264",
                        b"x.264",
                        b"x_264",
                        b"x 264",
                        b"x-264",
                        b"x264",
                    ] {
                        if rem.len() >= pat.len()
                            && rem[..pat.len()].eq_ignore_ascii_case(pat)
                            && is_wb_at(line, q + pat.len())
                        {
                            matched_fmt = Some(&rem[..pat.len()]);
                            break;
                        }
                    }
                    if matched_fmt.is_some() {
                        break;
                    }
                }
            }
            if let Some(fmt) = matched_fmt {
                release_name.push(b'.');
                release_name.extend_from_slice(fmt);
                break;
            }
        }
    }

    // 5. `/\[(640x.+|1280x.+|1920x.+)\] Resolution\b/i`
    for line in identity.split(|&b| b == b'\n') {
        let mut found_res = None;
        for b_pos in 0..line.len() {
            if line[b_pos] != b'[' {
                continue;
            }
            let inner = &line[b_pos + 1..];
            let pfx_ok = (inner.len() >= 5 && inner[..4].eq_ignore_ascii_case(b"640x"))
                || (inner.len() >= 6 && inner[..5].eq_ignore_ascii_case(b"1280x"))
                || (inner.len() >= 6 && inner[..5].eq_ignore_ascii_case(b"1920x"));
            if !pfx_ok {
                continue;
            }
            let min_close = b_pos
                + 1
                + if inner[..4].eq_ignore_ascii_case(b"640x") {
                    5
                } else {
                    6
                };
            for close_pos in (min_close..line.len()).rev() {
                if line[close_pos] == b']'
                    && line.len() - close_pos >= 12
                    && line[close_pos + 1..close_pos + 12].eq_ignore_ascii_case(b" resolution")
                    && is_wb_at(line, close_pos + 12)
                {
                    found_res = Some(&line[b_pos + 1..close_pos]);
                    break;
                }
            }
            if found_res.is_some() {
                break;
            }
        }
        if let Some(res) = found_res {
            let mapped = match res {
                b"640x.+" => &b"480p"[..],
                b"1280x.+" => &b"720p"[..],
                b"1920x.+" => &b"1080p"[..],
                _ => res,
            };
            release_name.push(b'.');
            release_name.extend_from_slice(mapped);
            break;
        }
    }

    if !release_name.is_empty() {
        release_name.extend_from_slice(b".IGUANA");
        write_result_pair(out, &release_name, b"IGUANA");
        return Some(true);
    }

    Some(false)
}

/// Evaluates `NfoNameExtractor` pattern matching into `out`.
/// - `mode == 0`: `extractFromNfo` (all 7 stages)
/// - `mode == 1`: `checkForTv`
/// - `mode == 2`: `checkForMovie`
/// - `mode == 3`: `checkForMusic`
/// - `mode == 4`: `checkForTitleYear`
/// - `mode == 5`: `checkForGame`
/// - `mode == 6`: `checkForIguana`
pub(crate) fn nfo_extract_pattern_mode_into(
    src: &[u8],
    mode: u32,
    out: &mut OutCursor<'_>,
) -> isize {
    if src.is_empty() {
        return 0;
    }
    if !is_supported_nfo_bytes(src) {
        return -2;
    }

    let needs_identity_copy = unsafe {
        !memchr(src.as_ptr(), b'\r' as i32, src.len()).is_null() || {
            let mut pos = 0usize;
            let mut found_source = false;
            while pos < src.len() {
                let p = memchr(src.as_ptr().add(pos), b'\n' as i32, src.len() - pos);
                let line_end = if p.is_null() {
                    src.len()
                } else {
                    p.offset_from(src.as_ptr()) as usize
                };
                if is_source_header_line(&src[pos..line_end]) {
                    found_source = true;
                    break;
                }
                pos = line_end + 1;
            }
            found_source
        }
    };

    let mut stack_ident = std::mem::MaybeUninit::<[u8; 16384]>::uninit();
    let mut heap_ident: Vec<u8> = Vec::new();
    let ident_slice: &[u8] = if !needs_identity_copy {
        src
    } else if src.len() <= 16384 {
        let ptr = stack_ident.as_mut_ptr().cast::<u8>();
        let mut cur = unsafe { OutCursor::new(ptr, 16384) };
        nfo_identity_text_into(src, &mut cur);
        unsafe { slice::from_raw_parts(ptr, cur.pos) }
    } else {
        heap_ident.resize(src.len(), 0);
        let mut cur = unsafe { OutCursor::new(heap_ident.as_mut_ptr(), heap_ident.len()) };
        nfo_identity_text_into(src, &mut cur);
        &heap_ident[..cur.pos]
    };

    if mode == 0 {
        for line in ident_slice.split(|&b| b == b'\n') {
            if let Some(field) = extract_literal_name_field(line) {
                let span = crate::filename::clean_for_matching_span(field, false);
                if span == -2 {
                    return -2;
                }
                if span >= 0 {
                    let start = (span >> 32) as usize;
                    let len = (span & 0xFFFF_FFFF) as usize;
                    let title = &field[start..start + len];
                    match is_literal_technical_scene_title_ascii(title) {
                        Some(true) => {
                            write_result_pair(out, title, b"Complete literal NFO field");
                            return finish_cursor_min_ref(out, 16);
                        }
                        Some(false) => {}
                        None => return -2,
                    }
                }
            }
        }
    }

    if (mode == 0 || mode == 1) && check_for_tv_into(ident_slice, out) {
        return finish_cursor_min_ref(out, 16);
    }
    if (mode == 0 || mode == 2) && check_for_movie_into(ident_slice, out) {
        return finish_cursor_min_ref(out, 16);
    }
    if (mode == 0 || mode == 3) && check_for_music_into(ident_slice, out) {
        return finish_cursor_min_ref(out, 16);
    }
    if (mode == 0 || mode == 4) && check_for_title_year_into(ident_slice, src, out) {
        return finish_cursor_min_ref(out, 16);
    }
    if (mode == 0 || mode == 5) && check_for_game_into(ident_slice, out) {
        return finish_cursor_min_ref(out, 16);
    }
    if mode == 0 || mode == 6 {
        match check_for_iguana_into(ident_slice, out) {
            Some(true) => return finish_cursor_min_ref(out, 16),
            Some(false) => {}
            None => return -2,
        }
    }

    0
}

#[inline]
fn finish_cursor_min_ref(out: &OutCursor<'_>, min_neg: usize) -> isize {
    if out.pos == 0 {
        0
    } else if out.pos <= out.buf.len() {
        out.pos as isize
    } else {
        -(out.pos.max(min_neg) as isize)
    }
}

// ============================================================================
// FileNameExtractor::extractFromFile
// ============================================================================

#[inline]
fn matches_literal_basename_ext(basename: &[u8]) -> bool {
    let Some(dot_pos) = basename.iter().rposition(|&b| b == b'.') else {
        return false;
    };
    let ext = &basename[dot_pos + 1..];
    if ext.is_empty() || ext.len() > 8 {
        return false;
    }
    if (3..=5).contains(&ext.len())
        && matches!(ext[0], b'r' | b'R')
        && ext[1..].iter().all(|b| b.is_ascii_digit())
    {
        return true;
    }
    if ext.len() == 3
        && matches!(ext[0], b'z' | b'Z')
        && ext[1..].iter().all(|b| b.is_ascii_digit())
    {
        return true;
    }
    if ext.len() == 3 && ext.iter().all(|b| b.is_ascii_digit()) {
        return true;
    }
    let mut lower = [0u8; 8];
    for (i, &b) in ext.iter().enumerate() {
        lower[i] = b.to_ascii_lowercase();
    }
    matches!(
        &lower[..ext.len()],
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
            | b"mp3"
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
            | b"rar"
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
            | b"zst"
            | b"tgz"
            | b"lz4"
            | b"rev"
            | b"enc"
    )
}

/// Enumerates candidate start indices of Group 4 after `^(\\\\|\/)?(.+(\\\\|\/))*` in PCRE backtracking order.
fn for_each_path_prefix_end(filename: &[u8], mut f: impl FnMut(usize) -> bool) -> bool {
    let is_slash = |b: u8| matches!(b, b'\\' | b'/');
    if filename.is_empty() {
        return false;
    }
    let first_is_slash = is_slash(filename[0]);
    if first_is_slash {
        for idx in (2..filename.len()).rev() {
            if is_slash(filename[idx]) && f(idx + 1) {
                return true;
            }
        }
        if f(1) {
            return true;
        }
        if filename.len() > 1 && is_slash(filename[1]) && f(2) {
            return true;
        }
        f(0)
    } else {
        for idx in (1..filename.len()).rev() {
            if is_slash(filename[idx]) && f(idx + 1) {
                return true;
            }
        }
        f(0)
    }
}

#[inline]
fn first_dot_with_tail(s: &[u8], min_dot: usize) -> Option<usize> {
    if s.len() < 2 || min_dot >= s.len() - 1 {
        return None;
    }
    for i in min_dot..s.len() - 1 {
        if s[i] == b'.' {
            return Some(i);
        }
    }
    None
}

#[inline]
fn last_dot_with_tail(s: &[u8], min_dot: usize) -> Option<usize> {
    if s.len() < 2 || min_dot >= s.len() - 1 {
        return None;
    }
    for i in (min_dot..s.len() - 1).rev() {
        if s[i] == b'.' {
            return Some(i);
        }
    }
    None
}

/// Matches `(?:720p|1080p|2160p|4K|HDTV|WEB-?DL|WEB-?RIP|BluRay|AMZN|HMAX|NF|DSNP)` at `s[pos..]`.
#[inline]
fn match_file_tv_quality_at(s: &[u8], pos: usize) -> Option<usize> {
    let rem = &s[pos..];
    for &tok in &[
        &b"720p"[..],
        b"1080p",
        b"2160p",
        b"4k",
        b"hdtv",
        b"web-dl",
        b"webdl",
        b"web-rip",
        b"webrip",
        b"bluray",
        b"amzn",
        b"hmax",
        b"nf",
        b"dsnp",
    ] {
        if rem.len() >= tok.len() && rem[..tok.len()].eq_ignore_ascii_case(tok) {
            return Some(pos + tok.len());
        }
    }
    None
}

/// Matches `(HDR10?\+?|DV|Dolby[\.\-_ ]?Vision)` at `s[pos..]`.
#[inline]
fn match_file_hdr_token_at(s: &[u8], pos: usize) -> Option<usize> {
    let rem = &s[pos..];
    if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"hdr") {
        let mut len = 3usize;
        if rem.len() >= 4 && rem[3] == b'1' {
            len = 4;
            if rem.len() >= 5 && rem[4] == b'0' {
                len = 5;
            }
        }
        if rem.len() > len && rem[len] == b'+' {
            len += 1;
        }
        return Some(pos + len);
    }
    if rem.len() >= 2 && rem[..2].eq_ignore_ascii_case(b"dv") {
        return Some(pos + 2);
    }
    if rem.len() >= 11 && rem[..5].eq_ignore_ascii_case(b"dolby") {
        if matches!(rem[5], b'.' | b'-' | b'_' | b' ')
            && rem.len() >= 12
            && rem[6..12].eq_ignore_ascii_case(b"vision")
        {
            return Some(pos + 12);
        }
        if rem[5..11].eq_ignore_ascii_case(b"vision") {
            return Some(pos + 11);
        }
    }
    None
}

/// Matches `(HEVC|x265|H\.?265)` at `s[pos..]`.
#[inline]
fn match_file_hevc_codec_at(s: &[u8], pos: usize) -> Option<usize> {
    let rem = &s[pos..];
    if rem.len() >= 4
        && (rem[..4].eq_ignore_ascii_case(b"hevc")
            || rem[..4].eq_ignore_ascii_case(b"x265")
            || rem[..4].eq_ignore_ascii_case(b"h265"))
    {
        return Some(pos + 4);
    }
    if rem.len() >= 5 && rem[..5].eq_ignore_ascii_case(b"h.265") {
        return Some(pos + 5);
    }
    None
}

/// Matches `(x264|x265|HEVC|H\.?264|H\.?265|AVC)` at `s[pos..]`.
#[inline]
fn match_file_hd_codec_at(s: &[u8], pos: usize) -> Option<usize> {
    let rem = &s[pos..];
    if rem.len() >= 4
        && (rem[..4].eq_ignore_ascii_case(b"x264")
            || rem[..4].eq_ignore_ascii_case(b"x265")
            || rem[..4].eq_ignore_ascii_case(b"hevc")
            || rem[..4].eq_ignore_ascii_case(b"h264")
            || rem[..4].eq_ignore_ascii_case(b"h265"))
    {
        return Some(pos + 4);
    }
    if rem.len() >= 5
        && (rem[..5].eq_ignore_ascii_case(b"h.264") || rem[..5].eq_ignore_ascii_case(b"h.265"))
    {
        return Some(pos + 5);
    }
    if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"avc") {
        return Some(pos + 3);
    }
    None
}

/// Evaluates `FileNameCleaner::cleanForTitleMatch` on an ASCII filename without `__NZBSPLIT__`.
fn clean_for_title_match_slice(filename: &[u8]) -> &[u8] {
    let mut end = filename.len();
    // 1. `/\.(mkv|avi|mp4|m4v|wmv|mpg|mpeg|mov|ts|m2ts|vob|divx|flv|nfo|sfv|nzb|srr|srs|rar|r\d{2,4}|zip|7z|par2?|vol\d+[\+\-]\d+|\d{3})$/i`
    if let Some(dot) = filename[..end].iter().rposition(|&b| b == b'.') {
        let ext = &filename[dot + 1..end];
        let mut matched = false;
        if (3..=5).contains(&ext.len())
            && matches!(ext[0], b'r' | b'R')
            && ext[1..].iter().all(|b| b.is_ascii_digit())
        {
            matched = true;
        } else if ext.len() == 3 && ext.iter().all(|b| b.is_ascii_digit()) {
            matched = true;
        } else if ext.len() >= 6 && ext[..3].eq_ignore_ascii_case(b"vol") {
            let mut p = 3usize;
            while p < ext.len() && ext[p].is_ascii_digit() {
                p += 1;
            }
            if p > 3 && p < ext.len() && matches!(ext[p], b'+' | b'-') {
                p += 1;
                let d2 = p;
                while p < ext.len() && ext[p].is_ascii_digit() {
                    p += 1;
                }
                if p > d2 && p == ext.len() {
                    matched = true;
                }
            }
        } else if !ext.is_empty() && ext.len() <= 4 {
            let mut lower = [0u8; 4];
            for (i, &b) in ext.iter().enumerate() {
                lower[i] = b.to_ascii_lowercase();
            }
            matched = matches!(
                &lower[..ext.len()],
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
                    | b"nfo"
                    | b"sfv"
                    | b"nzb"
                    | b"srr"
                    | b"srs"
                    | b"rar"
                    | b"zip"
                    | b"7z"
                    | b"par"
                    | b"par2"
            );
        }
        if matched {
            end = dot;
        }
    }

    // 2. `/[\.\-_]?(part|vol|cd|dvd|disc|disk)\d+$/i`
    {
        let s = &filename[..end];
        let mut p = s.len();
        while p > 0 && s[p - 1].is_ascii_digit() {
            p -= 1;
        }
        if p < s.len() {
            let head = &s[..p];
            for &word in &[&b"part"[..], b"vol", b"cd", b"dvd", b"disc", b"disk"] {
                if head.len() >= word.len()
                    && head[head.len() - word.len()..].eq_ignore_ascii_case(word)
                {
                    let mut w_start = head.len() - word.len();
                    if w_start > 0 && matches!(head[w_start - 1], b'.' | b'-' | b'_') {
                        w_start -= 1;
                    }
                    end = w_start;
                    break;
                }
            }
        }
    }

    // 3. `/[\.\-_](sample|proof|subs?)$/i`
    {
        let s = &filename[..end];
        for &word in &[&b"sample"[..], b"proof", b"subs", b"sub"] {
            if s.len() > word.len()
                && matches!(s[s.len() - word.len() - 1], b'.' | b'-' | b'_')
                && s[s.len() - word.len()..].eq_ignore_ascii_case(word)
            {
                end = s.len() - word.len() - 1;
                break;
            }
        }
    }

    // 4. `trim($clean, " \t\n\r\0\x0B.-_")`
    let mut start = 0usize;
    while start < end
        && (is_php_trim_byte(filename[start]) || matches!(filename[start], b'.' | b'-' | b'_'))
    {
        start += 1;
    }
    while end > start
        && (is_php_trim_byte(filename[end - 1]) || matches!(filename[end - 1], b'.' | b'-' | b'_'))
    {
        end -= 1;
    }
    &filename[start..end]
}

#[inline]
fn php_basename_norm_slashes(path: &[u8]) -> &[u8] {
    if path.is_empty() {
        return b"";
    }
    let mut end = path.len();
    while end > 0 && matches!(path[end - 1], b'/' | b'\\') {
        end -= 1;
    }
    if end == 0 {
        return &path[..1];
    }
    if let Some(pos) = path[..end].iter().rposition(|&b| matches!(b, b'/' | b'\\')) {
        &path[pos + 1..end]
    } else {
        &path[..end]
    }
}

#[inline]
fn php_parent_basename_norm_slashes(path: &[u8]) -> &[u8] {
    if path.is_empty() {
        return b"";
    }
    let mut end = path.len();
    while end > 0 && matches!(path[end - 1], b'/' | b'\\') {
        end -= 1;
    }
    if end == 0 {
        return b"/";
    }
    let Some(slash) = path[..end].iter().rposition(|&b| matches!(b, b'/' | b'\\')) else {
        return b".";
    };
    let mut dir_end = slash;
    while dir_end > 0 && matches!(path[dir_end - 1], b'/' | b'\\') {
        dir_end -= 1;
    }
    if dir_end == 0 {
        return b"/";
    }
    php_basename_norm_slashes(&path[..dir_end])
}

fn normalize_candidate_title_ascii(title: &[u8], out: &mut Vec<u8>) {
    let mut t = php_trim(title);
    // 1. `/\.(mkv|avi|mp4|m4v|mpg|mpeg|wmv|flv|mov|ts|vob|iso|divx)$/i`
    if let Some(dot) = t.iter().rposition(|&b| b == b'.') {
        let ext = &t[dot + 1..];
        if (2..=4).contains(&ext.len()) {
            let mut lower = [0u8; 4];
            for (i, &b) in ext.iter().enumerate() {
                lower[i] = b.to_ascii_lowercase();
            }
            if matches!(
                &lower[..ext.len()],
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
            ) {
                t = &t[..dot];
            }
        }
    }
    // 2. `/\.(par2?|nfo|sfv|nzb|rar|zip|7z|gz|tar|bz2|xz|r\d{2,3}|\d{3}|pkg|exe|msi|jpe?g|png|gif|bmp|pdf|epub|mobi|azw3?|djvu|cbr|cbz|fb2|lit|prc|opf|txt|log)$/i`
    if let Some(dot) = t.iter().rposition(|&b| b == b'.') {
        let ext = &t[dot + 1..];
        let mut matched = false;
        if (3..=4).contains(&ext.len())
            && matches!(ext[0], b'r' | b'R')
            && ext[1..].iter().all(|b| b.is_ascii_digit())
        {
            matched = true;
        } else if ext.len() == 3 && ext.iter().all(|b| b.is_ascii_digit()) {
            matched = true;
        } else if !ext.is_empty() && ext.len() <= 4 {
            let mut lower = [0u8; 4];
            for (i, &b) in ext.iter().enumerate() {
                lower[i] = b.to_ascii_lowercase();
            }
            matched = matches!(
                &lower[..ext.len()],
                b"par"
                    | b"par2"
                    | b"nfo"
                    | b"sfv"
                    | b"nzb"
                    | b"rar"
                    | b"zip"
                    | b"7z"
                    | b"gz"
                    | b"tar"
                    | b"bz2"
                    | b"xz"
                    | b"pkg"
                    | b"exe"
                    | b"msi"
                    | b"jpg"
                    | b"jpeg"
                    | b"png"
                    | b"gif"
                    | b"bmp"
                    | b"pdf"
                    | b"epub"
                    | b"mobi"
                    | b"azw"
                    | b"azw3"
                    | b"djvu"
                    | b"cbr"
                    | b"cbz"
                    | b"fb2"
                    | b"lit"
                    | b"prc"
                    | b"opf"
                    | b"txt"
                    | b"log"
            );
        }
        if matched {
            t = &t[..dot];
        }
    }
    // 3. `/[.\-_ ](?:part|vol|r)\d+(?:\+\d+)?$/i`
    {
        let mut p = t.len();
        while p > 0 && t[p - 1].is_ascii_digit() {
            p -= 1;
        }
        if p < t.len() {
            if p > 0 && t[p - 1] == b'+' {
                let plus = p - 1;
                p = plus;
                while p > 0 && t[p - 1].is_ascii_digit() {
                    p -= 1;
                }
                if p == plus {
                    p = t.len();
                }
            }
            if p < t.len() {
                let head = &t[..p];
                for &word in &[&b"part"[..], b"vol", b"r"] {
                    if head.len() > word.len()
                        && matches!(head[head.len() - word.len() - 1], b'.' | b'-' | b'_' | b' ')
                        && head[head.len() - word.len()..].eq_ignore_ascii_case(word)
                    {
                        t = &head[..head.len() - word.len() - 1];
                        break;
                    }
                }
            }
        }
    }
    // 4. Collapse `/[\s_]+/` -> `' '` and `trim($t, " .-_\t\r\n")`
    out.clear();
    let mut in_space = false;
    for &b in t {
        if is_pcre_space(b) || b == b'_' {
            if !in_space {
                out.push(b' ');
                in_space = true;
            }
        } else {
            in_space = false;
            out.push(b);
        }
    }
    while out
        .last()
        .is_some_and(|&b| matches!(b, b' ' | b'.' | b'-' | b'_' | b'\t' | b'\r' | b'\n'))
    {
        out.pop();
    }
    let mut lead = 0usize;
    while lead < out.len() && matches!(out[lead], b' ' | b'.' | b'-' | b'_' | b'\t' | b'\r' | b'\n')
    {
        lead += 1;
    }
    if lead > 0 {
        out.drain(..lead);
    }
}

#[inline]
fn candidate_has_real_title_prefix_before_year(candidate: &[u8]) -> bool {
    // `/^(.+?)[ ._(\[](?:19|20)\d{2}(?=$|[ ._)\]-])/u`
    // and `/[A-Za-z]{3}/` on `preg_replace('/^(?:\[[^\]]+\]\s*)+/', '', $result[1])`
    if candidate.len() < 6 {
        return false;
    }
    let mut r1: Option<&[u8]> = None;
    for sep_pos in 1..=candidate.len() - 5 {
        if matches!(candidate[sep_pos], b' ' | b'.' | b'_' | b'(' | b'[')
            && (&candidate[sep_pos + 1..sep_pos + 3] == b"19"
                || &candidate[sep_pos + 1..sep_pos + 3] == b"20")
            && candidate[sep_pos + 3].is_ascii_digit()
            && candidate[sep_pos + 4].is_ascii_digit()
            && (sep_pos + 5 == candidate.len()
                || matches!(
                    candidate[sep_pos + 5],
                    b' ' | b'.' | b'_' | b')' | b']' | b'-'
                ))
        {
            r1 = Some(&candidate[..sep_pos]);
            break;
        }
    }
    let Some(mut prefix) = r1 else {
        return false;
    };
    while prefix.starts_with(b"[") {
        let Some(rel_close) = prefix[1..].iter().position(|&b| b == b']') else {
            break;
        };
        if rel_close == 0 {
            break;
        }
        let mut p = 1 + rel_close + 1;
        while p < prefix.len() && is_pcre_space(prefix[p]) {
            p += 1;
        }
        prefix = &prefix[p..];
    }
    prefix.windows(3).any(|w| {
        w[0].is_ascii_alphabetic() && w[1].is_ascii_alphabetic() && w[2].is_ascii_alphabetic()
    })
}

#[inline]
fn candidate_has_bounded_resolution(candidate: &[u8]) -> bool {
    // `/(?<![a-z0-9])(?:720p|1080p|2160p)(?![a-z0-9])/i`
    for i in 0..candidate.len() {
        if i > 0 && candidate[i - 1].is_ascii_alphanumeric() {
            continue;
        }
        let rem = &candidate[i..];
        for &tok in &[&b"720p"[..], b"1080p", b"2160p"] {
            if rem.len() >= tok.len()
                && rem[..tok.len()].eq_ignore_ascii_case(tok)
                && (i + tok.len() == candidate.len()
                    || !candidate[i + tok.len()].is_ascii_alphanumeric())
            {
                return true;
            }
        }
    }
    false
}

#[inline]
fn candidate_has_bounded_source(candidate: &[u8]) -> bool {
    // `/(?<![a-z0-9])(?:HDTV(?:Light)?|BluRay|BDRip|WEB[- .]?DL|WEBRip|BD)(?![a-z0-9])/i`
    for i in 0..candidate.len() {
        if i > 0 && candidate[i - 1].is_ascii_alphanumeric() {
            continue;
        }
        let rem = &candidate[i..];
        for &tok in &[
            &b"hdtvlight"[..],
            b"hdtv",
            b"bluray",
            b"bdrip",
            b"web-dl",
            b"web.dl",
            b"web dl",
            b"webdl",
            b"webrip",
            b"bd",
        ] {
            if rem.len() >= tok.len()
                && rem[..tok.len()].eq_ignore_ascii_case(tok)
                && (i + tok.len() == candidate.len()
                    || !candidate[i + tok.len()].is_ascii_alphanumeric())
            {
                return true;
            }
        }
    }
    false
}

#[inline]
fn candidate_has_bounded_codec(candidate: &[u8]) -> bool {
    // `/(?<![a-z0-9])(?:x264|x265|HEVC|H[ .]?264|H[ .]?265|XviD)(?![a-z0-9])/i`
    for i in 0..candidate.len() {
        if i > 0 && candidate[i - 1].is_ascii_alphanumeric() {
            continue;
        }
        let rem = &candidate[i..];
        for &tok in &[
            &b"x264"[..],
            b"x265",
            b"hevc",
            b"h.264",
            b"h 264",
            b"h264",
            b"h.265",
            b"h 265",
            b"h265",
            b"xvid",
        ] {
            if rem.len() >= tok.len()
                && rem[..tok.len()].eq_ignore_ascii_case(tok)
                && (i + tok.len() == candidate.len()
                    || !candidate[i + tok.len()].is_ascii_alphanumeric())
            {
                return true;
            }
        }
    }
    false
}

/// Evaluates `FileNameExtractor::extractFromFile(string $filename)` into `out`.
/// Returns:
/// - `> 0`: bytes written to `out` (`newName\0method`)
/// - `0`: no match (`null`)
/// - `-2`: declined to PHP fallback
/// - `<= -16`: `-(required_len as isize)` when `out` capacity was exceeded
pub(crate) fn filename_extract_candidate_into(filename: &[u8], out: &mut OutCursor<'_>) -> isize {
    if filename.is_empty() {
        return 0;
    }
    if filename.iter().any(|&b| !(0x20..=0x7E).contains(&b)) {
        return -2;
    }

    // 1. `NZBSPLIT wrapper`
    if find_ascii_ci(filename, b"__nzbsplit__").is_some() {
        if filename.windows(12).any(|w| w == b"__NZBSPLIT__") {
            let mut unwrapped = Vec::new();
            match crate::cleaning::nzbsplit_unwrap_vec(filename, &mut unwrapped) {
                Some(true) => match crate::cleaning::is_plausible_release_title(&unwrapped) {
                    Some(true) => {
                        write_result_pair(out, &unwrapped, b"NZBSPLIT wrapper");
                        return finish_cursor_min_ref(out, 16);
                    }
                    Some(false) => return 0,
                    None => return -2,
                },
                Some(false) => return 0,
                None => return -2,
            }
        }
        return -2;
    }

    // 2. `Complete literal basename`
    let basename = crate::cleaning::extract_filename_from_path(filename);
    let Some(stripped_len) = crate::filename::strip_known_file_suffixes_len(basename, true) else {
        return -2;
    };
    let literal_title = &basename[..stripped_len];
    if literal_title.len() != basename.len() && matches_literal_basename_ext(basename) {
        match is_literal_technical_scene_title_ascii(literal_title) {
            Some(true) => {
                write_result_pair(out, literal_title, b"Complete literal basename");
                return finish_cursor_min_ref(out, 16);
            }
            Some(false) => {}
            None => return -2,
        }
    }

    // 3. `Scene release with group`: `/^(.+?(x264|x265|HEVC|XviD|H\.?264|H\.?265)\-[A-Za-z0-9]+)\\\\/i`
    if filename.contains(&b'\\') {
        for c_pos in 1..filename.len() {
            let rem = &filename[c_pos..];
            for &codec in &[
                &b"x264"[..],
                b"x265",
                b"hevc",
                b"xvid",
                b"h.264",
                b"h264",
                b"h.265",
                b"h265",
            ] {
                if rem.len() > codec.len() + 2
                    && rem[..codec.len()].eq_ignore_ascii_case(codec)
                    && rem[codec.len()] == b'-'
                {
                    let grp_start = c_pos + codec.len() + 1;
                    let mut p = grp_start;
                    while p < filename.len() && filename[p].is_ascii_alphanumeric() {
                        p += 1;
                    }
                    if p > grp_start && p < filename.len() && filename[p] == b'\\' {
                        write_result_pair(out, &filename[..p], b"Scene release with group");
                        return finish_cursor_min_ref(out, 16);
                    }
                }
            }
        }

        // 4. `TVP`: `/^(.+?(x264|XviD)\-TVP)\\\\/i`
        if filename.len() >= 10 {
            for c_pos in 1..=filename.len() - 9 {
                if filename[c_pos..c_pos + 9].eq_ignore_ascii_case(b"x264-tvp\\")
                    || filename[c_pos..c_pos + 9].eq_ignore_ascii_case(b"xvid-tvp\\")
                {
                    write_result_pair(out, &filename[..c_pos + 8], b"TVP");
                    return finish_cursor_min_ref(out, 16);
                }
            }
        }
    }

    // 5. `TV SxxExx with quality`:
    // `/^(\\\\|\/)?(.+(\\\\|\/))*(.+?S\d{1,3}[.-_ ]?E\d{1,3}(?:[.-_ ]?E\d{1,3})?[.-_ ].+?(?:720p|1080p|2160p|4K|HDTV|WEB-?DL|WEB-?RIP|BluRay|AMZN|HMAX|NF|DSNP).+?)\.(.+)$/iu`
    if for_each_path_prefix_end(filename, |g4_start| {
        let s = &filename[g4_start..];
        if s.len() < 10 {
            return false;
        }
        for s_pos in 1..s.len() {
            if !matches!(s[s_pos], b's' | b'S') {
                continue;
            }
            let d1_start = s_pos + 1;
            let mut d1_max = d1_start;
            while d1_max < s.len() && s[d1_max].is_ascii_digit() && d1_max - d1_start < 3 {
                d1_max += 1;
            }
            for d1_end in (d1_start + 1..=d1_max).rev() {
                for sep1 in [1usize, 0] {
                    if sep1 == 1
                        && (d1_end >= s.len() || !is_pcre_dot_to_underscore_or_space_ci(s[d1_end]))
                    {
                        continue;
                    }
                    let e1_pos = d1_end + sep1;
                    if e1_pos >= s.len() || !matches!(s[e1_pos], b'e' | b'E') {
                        continue;
                    }
                    let e1_start = e1_pos + 1;
                    let mut e1_max = e1_start;
                    while e1_max < s.len() && s[e1_max].is_ascii_digit() && e1_max - e1_start < 3 {
                        e1_max += 1;
                    }
                    for e1_end in (e1_start + 1..=e1_max).rev() {
                        // Try optional `(?:[.-_ ]?E\d{1,3})?` greedy (present first, then absent)
                        let mut after_ep_candidates = [usize::MAX; 8];
                        let mut cand_len = 0usize;
                        for sep2 in [1usize, 0] {
                            if sep2 == 1
                                && (e1_end >= s.len()
                                    || !is_pcre_dot_to_underscore_or_space_ci(s[e1_end]))
                            {
                                continue;
                            }
                            let e2_pos = e1_end + sep2;
                            if e2_pos < s.len() && matches!(s[e2_pos], b'e' | b'E') {
                                let e2_start = e2_pos + 1;
                                let mut e2_max = e2_start;
                                while e2_max < s.len()
                                    && s[e2_max].is_ascii_digit()
                                    && e2_max - e2_start < 3
                                {
                                    e2_max += 1;
                                }
                                for e2_end in (e2_start + 1..=e2_max).rev() {
                                    if cand_len < after_ep_candidates.len() {
                                        after_ep_candidates[cand_len] = e2_end;
                                        cand_len += 1;
                                    }
                                }
                            }
                        }
                        after_ep_candidates[cand_len] = e1_end;
                        cand_len += 1;

                        for &ep_end in &after_ep_candidates[..cand_len] {
                            if ep_end >= s.len()
                                || !is_pcre_dot_to_underscore_or_space_ci(s[ep_end])
                            {
                                continue;
                            }
                            let after_sep = ep_end + 1;
                            for q_pos in (after_sep + 1)..s.len() {
                                if let Some(q_end) = match_file_tv_quality_at(s, q_pos) {
                                    if let Some(dot_pos) = first_dot_with_tail(s, q_end + 1) {
                                        write_result_pair(
                                            out,
                                            &s[..dot_pos],
                                            b"TV SxxExx with quality",
                                        );
                                        return true;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        false
    }) {
        return finish_cursor_min_ref(out, 16);
    }

    // 6. `Generic TV`:
    // `/^(\\\\|\/)?(.+(\\\\|\/))*(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+)\.(.+)$/iu`
    if for_each_path_prefix_end(filename, |g4_start| {
        let s = &filename[g4_start..];
        let Some(last_dot) = last_dot_with_tail(s, 5) else {
            return false;
        };
        for s_pos in 1..last_dot {
            if !matches!(s[s_pos], b's' | b'S') {
                continue;
            }
            let d1_start = s_pos + 1;
            let mut d1_max = d1_start;
            while d1_max < last_dot && s[d1_max].is_ascii_digit() && d1_max - d1_start < 3 {
                d1_max += 1;
            }
            for d1_end in (d1_start + 1..=d1_max).rev() {
                for sep in [1usize, 0] {
                    if sep == 1
                        && (d1_end >= last_dot || !is_pcre_dot_to_underscore_or_space_ci(s[d1_end]))
                    {
                        continue;
                    }
                    let ed_pos = d1_end + sep;
                    if ed_pos >= last_dot || !matches!(s[ed_pos], b'e' | b'E' | b'd' | b'D') {
                        continue;
                    }
                    let d2_start = ed_pos + 1;
                    let mut d2_max = d2_start;
                    while d2_max < last_dot && s[d2_max].is_ascii_digit() && d2_max - d2_start < 3 {
                        d2_max += 1;
                    }
                    for d2_end in (d2_start + 1..=d2_max).rev() {
                        if let Some(dot_pos) = last_dot_with_tail(s, d2_end + 1) {
                            write_result_pair(out, &s[..dot_pos], b"Generic TV");
                            return true;
                        }
                    }
                }
            }
        }
        false
    }) {
        return finish_cursor_min_ref(out, 16);
    }

    // 7. `4K/UHD Movie`:
    // `/^(\\\\|\/)?(.+(\\\\|\/))*(.+?[\.\-_ ](19|20)\d\d[\.\-_ ].+?(2160p|4K|UHD).+?(HDR10?\+?|DV|Dolby[\.\-_ ]?Vision)?.+?(HEVC|x265|H\.?265).+?)\.(.+)$/iu`
    if for_each_path_prefix_end(filename, |g4_start| {
        let s = &filename[g4_start..];
        if s.len() < 16 {
            return false;
        }
        for sep_pos in 1..s.len() - 6 {
            if !matches!(s[sep_pos], b'.' | b'-' | b'_' | b' ')
                || !(&s[sep_pos + 1..sep_pos + 3] == b"19" || &s[sep_pos + 1..sep_pos + 3] == b"20")
                || !s[sep_pos + 3].is_ascii_digit()
                || !s[sep_pos + 4].is_ascii_digit()
                || !matches!(s[sep_pos + 5], b'.' | b'-' | b'_' | b' ')
            {
                continue;
            }
            let after_yr = sep_pos + 6;
            for u_pos in (after_yr + 1)..s.len() {
                let rem = &s[u_pos..];
                let u_len = if rem.len() >= 5 && rem[..5].eq_ignore_ascii_case(b"2160p") {
                    5
                } else if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"uhd") {
                    3
                } else if rem.len() >= 2 && rem[..2].eq_ignore_ascii_case(b"4k") {
                    2
                } else {
                    continue;
                };
                let u_end = u_pos + u_len;
                for p in (u_end + 1)..s.len() {
                    if let Some(hdr_end) = match_file_hdr_token_at(s, p) {
                        for c_pos in (hdr_end + 1)..s.len() {
                            if let Some(c_end) = match_file_hevc_codec_at(s, c_pos) {
                                if let Some(dot_pos) = first_dot_with_tail(s, c_end + 1) {
                                    write_result_pair(out, &s[..dot_pos], b"4K/UHD Movie");
                                    return true;
                                }
                            }
                        }
                    }
                }
                for c_pos in (u_end + 2)..s.len() {
                    if let Some(c_end) = match_file_hevc_codec_at(s, c_pos) {
                        if let Some(dot_pos) = first_dot_with_tail(s, c_end + 1) {
                            write_result_pair(out, &s[..dot_pos], b"4K/UHD Movie");
                            return true;
                        }
                    }
                }
            }
        }
        false
    }) {
        return finish_cursor_min_ref(out, 16);
    }

    // 8. `HD Movie modern codec`:
    // `/^(\\\\|\/)?(.+(\\\\|\/))*(.+?[\.\-_ ](19|20)\d\d[\.\-_ ].+?(720p|1080p).+?(BluRay|WEB-?DL|WEB-?RIP|BDRip|REMUX).+?(x264|x265|HEVC|H\.?264|H\.?265|AVC).+?)\.(.+)$/iu`
    if for_each_path_prefix_end(filename, |g4_start| {
        let s = &filename[g4_start..];
        if s.len() < 18 {
            return false;
        }
        for sep_pos in 1..s.len() - 6 {
            if !matches!(s[sep_pos], b'.' | b'-' | b'_' | b' ')
                || !(&s[sep_pos + 1..sep_pos + 3] == b"19" || &s[sep_pos + 1..sep_pos + 3] == b"20")
                || !s[sep_pos + 3].is_ascii_digit()
                || !s[sep_pos + 4].is_ascii_digit()
                || !matches!(s[sep_pos + 5], b'.' | b'-' | b'_' | b' ')
            {
                continue;
            }
            let after_yr = sep_pos + 6;
            for r_pos in (after_yr + 1)..s.len() {
                let rem = &s[r_pos..];
                let r_len = if rem.len() >= 5 && rem[..5].eq_ignore_ascii_case(b"1080p") {
                    5
                } else if rem.len() >= 4 && rem[..4].eq_ignore_ascii_case(b"720p") {
                    4
                } else {
                    continue;
                };
                let r_end = r_pos + r_len;
                for src_pos in (r_end + 1)..s.len() {
                    let srem = &s[src_pos..];
                    let mut matched_src_len = 0usize;
                    for &tok in &[
                        &b"bluray"[..],
                        b"web-dl",
                        b"webdl",
                        b"web-rip",
                        b"webrip",
                        b"bdrip",
                        b"remux",
                    ] {
                        if srem.len() >= tok.len() && srem[..tok.len()].eq_ignore_ascii_case(tok) {
                            matched_src_len = tok.len();
                            break;
                        }
                    }
                    if matched_src_len == 0 {
                        continue;
                    }
                    let src_end = src_pos + matched_src_len;
                    for c_pos in (src_end + 1)..s.len() {
                        if let Some(c_end) = match_file_hd_codec_at(s, c_pos) {
                            if let Some(dot_pos) = first_dot_with_tail(s, c_end + 1) {
                                write_result_pair(out, &s[..dot_pos], b"HD Movie modern codec");
                                return true;
                            }
                        }
                    }
                }
            }
        }
        false
    }) {
        return finish_cursor_min_ref(out, 16);
    }

    // 9. `Generic movie 1`:
    // `/^(\\\\|\/)?(.+(\\\\|\/))*(.+?([\.\-_ ]\d{4}[\.\-_ ].+?(BDRip|bluray|DVDRip|XVID|WEB-?DL|HDTV)).+)\.(.+)$/iu`
    if for_each_path_prefix_end(filename, |g4_start| {
        let s = &filename[g4_start..];
        let Some(last_dot) = last_dot_with_tail(s, 10) else {
            return false;
        };
        for sep_pos in 1..last_dot.saturating_sub(6) {
            if !matches!(s[sep_pos], b'.' | b'-' | b'_' | b' ')
                || !s[sep_pos + 1].is_ascii_digit()
                || !s[sep_pos + 2].is_ascii_digit()
                || !s[sep_pos + 3].is_ascii_digit()
                || !s[sep_pos + 4].is_ascii_digit()
                || !matches!(s[sep_pos + 5], b'.' | b'-' | b'_' | b' ')
            {
                continue;
            }
            let after_yr = sep_pos + 6;
            for src_pos in (after_yr + 1)..last_dot {
                let srem = &s[src_pos..];
                for &tok in &[
                    &b"bdrip"[..],
                    b"bluray",
                    b"dvdrip",
                    b"xvid",
                    b"web-dl",
                    b"webdl",
                    b"hdtv",
                ] {
                    if srem.len() >= tok.len() && srem[..tok.len()].eq_ignore_ascii_case(tok) {
                        if let Some(dot_pos) = last_dot_with_tail(s, src_pos + tok.len() + 1) {
                            write_result_pair(out, &s[..dot_pos], b"Generic movie 1");
                            return true;
                        }
                    }
                }
            }
        }
        false
    }) {
        return finish_cursor_min_ref(out, 16);
    }

    // 10. `Generic movie 2`:
    // `/^([a-z0-9\.\-_]+(19|20)\d\d[a-z0-9\.\-_]+[\.\-_ ](720p|1080p|2160p|4K|BDRip|bluray|DVDRip|x264|x265|XviD|HEVC)[a-z0-9\.\-_]+)\.[a-z]{2,}$/i`
    if let Some(last_dot) = filename.iter().rposition(|&b| b == b'.') {
        let stem = &filename[..last_dot];
        let ext = &filename[last_dot + 1..];
        if ext.len() >= 2 && ext.iter().all(|b| b.is_ascii_alphabetic()) && stem.len() >= 10 {
            let is_m2_char = |b: u8| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'-' | b'_');
            let mut matched_m2 = false;
            for yr_pos in (1..=stem.len() - 6).rev() {
                if !(&stem[yr_pos..yr_pos + 2] == b"19" || &stem[yr_pos..yr_pos + 2] == b"20")
                    || !stem[yr_pos + 2].is_ascii_digit()
                    || !stem[yr_pos + 3].is_ascii_digit()
                    || !stem[..yr_pos].iter().all(|&b| is_m2_char(b))
                {
                    continue;
                }
                for sep_pos in (yr_pos + 5..stem.len()).rev() {
                    if !matches!(stem[sep_pos], b'.' | b'-' | b'_' | b' ')
                        || !stem[yr_pos + 4..sep_pos].iter().all(|&b| is_m2_char(b))
                    {
                        continue;
                    }
                    let rem = &stem[sep_pos + 1..];
                    for &tok in &[
                        &b"720p"[..],
                        b"1080p",
                        b"2160p",
                        b"4k",
                        b"bdrip",
                        b"bluray",
                        b"dvdrip",
                        b"x264",
                        b"x265",
                        b"xvid",
                        b"hevc",
                    ] {
                        if rem.len() > tok.len()
                            && rem[..tok.len()].eq_ignore_ascii_case(tok)
                            && rem[tok.len()..].iter().all(|&b| is_m2_char(b))
                        {
                            matched_m2 = true;
                            break;
                        }
                    }
                    if matched_m2 {
                        break;
                    }
                }
                if matched_m2 {
                    break;
                }
            }
            if matched_m2 {
                write_result_pair(out, stem, b"Generic movie 2");
                return finish_cursor_min_ref(out, 16);
            }
        }
    }

    // 11. `Streaming service release`:
    // `/^([A-Za-z0-9\.\-_]+[\.\-_ ](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|TVNZ|VUDU)[\.\-_ ].+?(WEB-?DL|WEB-?RIP).+?)\.(.+)$/i`
    {
        let mut max_prefix = 0usize;
        while max_prefix < filename.len()
            && (filename[max_prefix].is_ascii_alphanumeric()
                || matches!(filename[max_prefix], b'.' | b'-' | b'_'))
        {
            max_prefix += 1;
        }
        for sep1_pos in (1..=max_prefix.min(filename.len().saturating_sub(10))).rev() {
            if !matches!(filename[sep1_pos], b'.' | b'-' | b'_' | b' ') {
                continue;
            }
            let rem = &filename[sep1_pos + 1..];
            for &svc in &[
                &b"amzn"[..],
                b"atvp",
                b"dsnp",
                b"hmax",
                b"hulu",
                b"it",
                b"nf",
                b"pmtp",
                b"pcok",
                b"roku",
                b"stan",
                b"tvnz",
                b"vudu",
            ] {
                if rem.len() > svc.len() + 1
                    && rem[..svc.len()].eq_ignore_ascii_case(svc)
                    && matches!(rem[svc.len()], b'.' | b'-' | b'_' | b' ')
                {
                    let after_svc = sep1_pos + 1 + svc.len() + 1;
                    for w_pos in (after_svc + 1)..filename.len() {
                        let wrem = &filename[w_pos..];
                        for &wtok in &[&b"web-dl"[..], b"webdl", b"web-rip", b"webrip"] {
                            if wrem.len() >= wtok.len()
                                && wrem[..wtok.len()].eq_ignore_ascii_case(wtok)
                            {
                                if let Some(dot_pos) =
                                    first_dot_with_tail(filename, w_pos + wtok.len() + 1)
                                {
                                    write_result_pair(
                                        out,
                                        &filename[..dot_pos],
                                        b"Streaming service release",
                                    );
                                    return finish_cursor_min_ref(out, 16);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // 12. `Generic music` & 13. `music groups`
    if filename.contains(&b'\\') {
        // `/(.+?([\.\-_ ](CD|FM)|[\.\-_ ]\dCD|CDR|FLAC|SAT|WEB).+?(19|20)\d\d.+?)\\\\.+/i`
        for tok_pos in 1..filename.len() {
            let rem = &filename[tok_pos..];
            let mut lens = [0usize; 3];
            let mut lcount = 0usize;
            if rem.len() >= 3
                && matches!(rem[0], b'.' | b'-' | b'_' | b' ')
                && (rem[1..3].eq_ignore_ascii_case(b"cd") || rem[1..3].eq_ignore_ascii_case(b"fm"))
            {
                lens[lcount] = 3;
                lcount += 1;
            }
            if rem.len() >= 4
                && matches!(rem[0], b'.' | b'-' | b'_' | b' ')
                && rem[1].is_ascii_digit()
                && rem[2..4].eq_ignore_ascii_case(b"cd")
            {
                lens[lcount] = 4;
                lcount += 1;
            }
            for &word in &[&b"cdr"[..], b"flac", b"sat", b"web"] {
                if rem.len() >= word.len() && rem[..word.len()].eq_ignore_ascii_case(word) {
                    lens[lcount] = word.len();
                    lcount += 1;
                    break;
                }
            }
            for &tlen in &lens[..lcount] {
                let after_tok = tok_pos + tlen;
                for yr_pos in (after_tok + 1)..filename.len().saturating_sub(4) {
                    if (&filename[yr_pos..yr_pos + 2] == b"19"
                        || &filename[yr_pos..yr_pos + 2] == b"20")
                        && filename[yr_pos + 2].is_ascii_digit()
                        && filename[yr_pos + 3].is_ascii_digit()
                    {
                        for bs_pos in (yr_pos + 5)..filename.len() - 1 {
                            if filename[bs_pos] == b'\\' {
                                write_result_pair(out, &filename[..bs_pos], b"Generic music");
                                return finish_cursor_min_ref(out, 16);
                            }
                        }
                    }
                }
            }
        }

        // `/^(.+?(19|20)\d\d\-([a-z0-9]{3}|[a-z]{2,}|C4))\\\\/i`
        if filename.len() >= 9 {
            for yr_pos in 1..=filename.len() - 8 {
                if (&filename[yr_pos..yr_pos + 2] == b"19"
                    || &filename[yr_pos..yr_pos + 2] == b"20")
                    && filename[yr_pos + 2].is_ascii_digit()
                    && filename[yr_pos + 3].is_ascii_digit()
                    && filename[yr_pos + 4] == b'-'
                {
                    let grp_start = yr_pos + 5;
                    let mut p = grp_start;
                    while p < filename.len() && filename[p].is_ascii_alphanumeric() {
                        p += 1;
                    }
                    if p < filename.len() && filename[p] == b'\\' {
                        let grp = &filename[grp_start..p];
                        if grp.len() == 3
                            || (grp.len() >= 2 && grp.iter().all(|b| b.is_ascii_alphabetic()))
                            || grp.eq_ignore_ascii_case(b"c4")
                        {
                            write_result_pair(out, &filename[..p], b"music groups");
                            return finish_cursor_min_ref(out, 16);
                        }
                    }
                }
            }
        }
    }

    // 14. `Music with codec`:
    // `/^(.+?[\.\-_ ](FLAC|MP3|AAC|OGG)[\.\-_ ].+?[\.\-_ ]\d{4}[\.\-_ ].+?\-[A-Za-z0-9]+)[\\\\\/.]/i`
    if filename.len() >= 16 {
        for sep1 in 1..filename.len() - 12 {
            if !matches!(filename[sep1], b'.' | b'-' | b'_' | b' ') {
                continue;
            }
            let rem = &filename[sep1 + 1..];
            let mut clen = 0usize;
            for &codec in &[&b"flac"[..], b"mp3", b"aac", b"ogg"] {
                if rem.len() > codec.len()
                    && rem[..codec.len()].eq_ignore_ascii_case(codec)
                    && matches!(rem[codec.len()], b'.' | b'-' | b'_' | b' ')
                {
                    clen = codec.len();
                    break;
                }
            }
            if clen == 0 {
                continue;
            }
            let after_codec = sep1 + 1 + clen + 1;
            for sep2 in (after_codec + 1)..filename.len().saturating_sub(8) {
                if !matches!(filename[sep2], b'.' | b'-' | b'_' | b' ')
                    || !filename[sep2 + 1].is_ascii_digit()
                    || !filename[sep2 + 2].is_ascii_digit()
                    || !filename[sep2 + 3].is_ascii_digit()
                    || !filename[sep2 + 4].is_ascii_digit()
                    || !matches!(filename[sep2 + 5], b'.' | b'-' | b'_' | b' ')
                {
                    continue;
                }
                let after_yr = sep2 + 6;
                for dash in (after_yr + 1)..filename.len() - 2 {
                    if filename[dash] != b'-' {
                        continue;
                    }
                    let mut p = dash + 1;
                    while p < filename.len() && filename[p].is_ascii_alphanumeric() {
                        p += 1;
                    }
                    for end_p in ((dash + 2)..=p).rev() {
                        if end_p < filename.len() && matches!(filename[end_p], b'\\' | b'/' | b'.')
                        {
                            write_result_pair(out, &filename[..end_p], b"Music with codec");
                            return finish_cursor_min_ref(out, 16);
                        }
                    }
                }
            }
        }
    }

    // 15, 16, 17: Movie (year) avi / iso / mkv/mp4
    if filename.contains(&b'\\') && filename.len() >= 13 {
        let check_paren_year = |pos: usize| -> bool {
            pos + 6 <= filename.len()
                && filename[pos] == b'('
                && (&filename[pos + 1..pos + 3] == b"19" || &filename[pos + 1..pos + 3] == b"20")
                && filename[pos + 3].is_ascii_digit()
                && filename[pos + 4].is_ascii_digit()
                && filename[pos + 5] == b')'
        };
        let paren_pos = filename.len() - 10;
        if check_paren_year(paren_pos) {
            let ext = &filename[filename.len() - 4..];
            if ext.eq_ignore_ascii_case(b".avi") {
                if let Some(bs) = filename[1..paren_pos - 1].iter().rposition(|&b| b == b'\\') {
                    let r1 = &filename[1 + bs + 1..];
                    let mut new_name = Vec::with_capacity(r1.len() + 20);
                    let mut i = 0usize;
                    while i < r1.len() {
                        if i + 4 <= r1.len() && &r1[i..i + 4] == b".avi" {
                            new_name.extend_from_slice(b" DVDRip XVID NoGroup");
                            i += 4;
                        } else {
                            new_name.push(r1[i]);
                            i += 1;
                        }
                    }
                    write_result_pair(out, &new_name, b"Movie (year) avi");
                    return finish_cursor_min_ref(out, 16);
                }
            } else if ext.eq_ignore_ascii_case(b".iso") {
                if let Some(bs) = filename[1..paren_pos - 1].iter().rposition(|&b| b == b'\\') {
                    let r1 = &filename[1 + bs + 1..];
                    let mut new_name = Vec::with_capacity(r1.len() + 12);
                    let mut i = 0usize;
                    while i < r1.len() {
                        if i + 4 <= r1.len() && &r1[i..i + 4] == b".iso" {
                            new_name.extend_from_slice(b" DVD NoGroup");
                            i += 4;
                        } else {
                            new_name.push(r1[i]);
                            i += 1;
                        }
                    }
                    write_result_pair(out, &new_name, b"Movie (year) iso");
                    return finish_cursor_min_ref(out, 16);
                }
            } else if ext.eq_ignore_ascii_case(b".mkv")
                || ext.eq_ignore_ascii_case(b".mp4")
                || ext.eq_ignore_ascii_case(b".m4v")
            {
                if let Some(bs) = filename[1..paren_pos - 1].iter().rposition(|&b| b == b'\\') {
                    let r1 = &filename[1 + bs + 1..filename.len() - 4];
                    let mut new_name = Vec::with_capacity(r1.len() + 19);
                    new_name.extend_from_slice(r1);
                    new_name.extend_from_slice(b" BDRip x264 NoGroup");
                    write_result_pair(out, &new_name, b"Movie (year) mkv/mp4");
                    return finish_cursor_min_ref(out, 16);
                }
            }
        }
    }

    // 18. `RAR archive path` & 19. `Scene RAR release`
    if let Some(first_slash) = filename.iter().position(|&b| matches!(b, b'\\' | b'/')) {
        if let Some(dot_pos) = filename.iter().rposition(|&b| b == b'.') {
            if dot_pos > first_slash + 1 {
                let ext = &filename[dot_pos + 1..];
                let is_rar_or_r = ext.eq_ignore_ascii_case(b"rar")
                    || ((3..=5).contains(&ext.len())
                        && matches!(ext[0], b'r' | b'R')
                        && ext[1..].iter().all(|b| b.is_ascii_digit()));
                let is_rar_zip_7z = is_rar_or_r
                    || ext.eq_ignore_ascii_case(b"zip")
                    || ext.eq_ignore_ascii_case(b"7z");
                let g1 = &filename[..first_slash];
                if is_rar_zip_7z
                    && g1.len() >= 4
                    && g1[0].is_ascii_alphanumeric()
                    && g1[1] != b' '
                    && g1[g1.len() - 1] != b' '
                    && g1
                        .iter()
                        .all(|&b| is_word_byte(b) || matches!(b, b'.' | b'-' | b' '))
                    && !g1.windows(2).any(|w| w == b"  ")
                    && g1[2..g1.len() - 1]
                        .iter()
                        .any(|&b| matches!(b, b'.' | b'-' | b'_' | b' '))
                {
                    write_result_pair(out, g1, b"RAR archive path");
                    return finish_cursor_min_ref(out, 16);
                }
                if is_rar_or_r && g1.len() >= 4 && g1[0].is_ascii_alphanumeric() {
                    if let Some(dash) = g1.iter().rposition(|&b| b == b'-') {
                        if dash >= 2
                            && dash + 1 < g1.len()
                            && g1[1..dash]
                                .iter()
                                .all(|&b| is_word_byte(b) || matches!(b, b'.' | b'-'))
                            && g1[dash + 1..].iter().all(|b| b.is_ascii_alphanumeric())
                        {
                            write_result_pair(out, g1, b"Scene RAR release");
                            return finish_cursor_min_ref(out, 16);
                        }
                    }
                }
            }
        }
    }

    // 20–28: Rare scene/group-specific patterns (`IMAGESET`, `VIDEOOT-`, `SDPORN`, `R&C`, `NhaNc3`, `tvp-`, `hdtv-lol`, `-XVID-DL.avi`, ` - s\d{2}[ex]\d{2} - `)
    if find_ascii_ci(filename, b"imageset").is_some()
        || filename.starts_with(b"VIDEOOT-")
        || find_ascii_ci(filename, b"sdporn").is_some()
        || find_ascii_ci(filename, b"mpeg2-r&c").is_some()
        || find_ascii_ci(filename, b"nhanc3").is_some()
        || find_ascii_ci(filename, b"tvp-").is_some()
        || find_ascii_ci(filename, b".hdtv-lol.").is_some()
        || find_ascii_ci(filename, b"-xvid-dl.avi").is_some()
        || find_ascii_ci(filename, b" - s").is_some()
        || find_ascii_ci(filename, b").nds").is_some()
        || find_ascii_ci(filename, b".3ds").is_some()
        || find_ascii_ci(filename, b"audiobook").is_some()
    {
        return -2;
    }

    // 31. `Nintendo Switch`: `/^(.+?)\[[\w]+\]\.(?:nsp|xci|nsz)$/i`
    if filename.len() >= 8 {
        let ext = &filename[filename.len() - 4..];
        if ext.eq_ignore_ascii_case(b".nsp")
            || ext.eq_ignore_ascii_case(b".xci")
            || ext.eq_ignore_ascii_case(b".nsz")
        {
            let before_ext = &filename[..filename.len() - 4];
            if before_ext.ends_with(b"]") {
                if let Some(open_b) = before_ext.iter().rposition(|&b| b == b'[') {
                    if open_b >= 1
                        && open_b + 1 < before_ext.len() - 1
                        && before_ext[open_b + 1..before_ext.len() - 1]
                            .iter()
                            .all(|&b| is_word_byte(b))
                    {
                        let r1 = php_trim(&before_ext[..open_b]);
                        let mut new_name = Vec::with_capacity(r1.len() + 7);
                        new_name.extend_from_slice(r1);
                        new_name.extend_from_slice(b" Switch");
                        write_result_pair(out, &new_name, b"Nintendo Switch");
                        return finish_cursor_min_ref(out, 16);
                    }
                }
            }
        }
    }

    // 32. `Console game release`:
    // `/^(.+?[\.\-_ ](PS[345P]|PSV|XBOX360|XBOXONE|NSW)[\.\-_ ].+?\-[A-Za-z0-9]+)[\\\\\/.]/i`
    for sep1 in 1..filename.len().saturating_sub(6) {
        if !matches!(filename[sep1], b'.' | b'-' | b'_' | b' ') {
            continue;
        }
        let rem = &filename[sep1 + 1..];
        let mut clen = 0usize;
        for &plat in &[
            &b"ps3"[..],
            b"ps4",
            b"ps5",
            b"psp",
            b"psv",
            b"xbox360",
            b"xboxone",
            b"nsw",
        ] {
            if rem.len() > plat.len()
                && rem[..plat.len()].eq_ignore_ascii_case(plat)
                && matches!(rem[plat.len()], b'.' | b'-' | b'_' | b' ')
            {
                clen = plat.len();
                break;
            }
        }
        if clen == 0 {
            continue;
        }
        let after_plat = sep1 + 1 + clen + 1;
        for dash in (after_plat + 1)..filename.len().saturating_sub(2) {
            if filename[dash] != b'-' {
                continue;
            }
            let mut p = dash + 1;
            while p < filename.len() && filename[p].is_ascii_alphanumeric() {
                p += 1;
            }
            for end_p in ((dash + 2)..=p).rev() {
                if end_p < filename.len() && matches!(filename[end_p], b'\\' | b'/' | b'.') {
                    write_result_pair(out, &filename[..end_p], b"Console game release");
                    return finish_cursor_min_ref(out, 16);
                }
            }
        }
    }

    // 33. `EBook`: `/\w.+?\.(epub|mobi|azw3?|opf|fb2|prc|djvu|cb[rz])/i`
    if let Some(w_start) = filename.iter().position(|&b| is_word_byte(b)) {
        for dot in (w_start + 2)..filename.len() {
            if filename[dot] != b'.' {
                continue;
            }
            let rem = &filename[dot + 1..];
            for &eb in &[
                &b"epub"[..],
                b"mobi",
                b"azw3",
                b"azw",
                b"opf",
                b"fb2",
                b"prc",
                b"djvu",
                b"cbr",
                b"cbz",
            ] {
                if rem.len() >= eb.len() && rem[..eb.len()].eq_ignore_ascii_case(eb) {
                    let r0 = &filename[w_start..dot + 1 + eb.len()];
                    let r1 = &filename[dot + 1..dot + 1 + eb.len()];
                    let mut needle = Vec::with_capacity(1 + r1.len());
                    needle.push(b'.');
                    needle.extend_from_slice(r1);
                    let mut repl = Vec::with_capacity(3 + r1.len());
                    repl.extend_from_slice(b" (");
                    repl.extend_from_slice(r1);
                    repl.push(b')');
                    let mut new_res = Vec::with_capacity(r0.len() + 3);
                    let mut i = 0usize;
                    while i < r0.len() {
                        if i + needle.len() <= r0.len()
                            && &r0[i..i + needle.len()] == needle.as_slice()
                        {
                            new_res.extend_from_slice(&repl);
                            i += needle.len();
                        } else {
                            new_res.push(r0[i]);
                            i += 1;
                        }
                    }
                    write_result_pair(out, &new_res, b"EBook");
                    return finish_cursor_min_ref(out, 16);
                }
            }
        }
    }

    // 35. `Cleaned scene name`:
    // `if (preg_match('/^([A-Za-z0-9][\w.\-]+\-[A-Za-z0-9]{2,15})$/i', $cleanedFilename, $result) && preg_match(self::PREDB_REGEX, $cleanedFilename))`
    let cleaned_filename = clean_for_title_match_slice(filename);
    if cleaned_filename.len() >= 5 && cleaned_filename[0].is_ascii_alphanumeric() {
        if let Some(dash) = cleaned_filename.iter().rposition(|&b| b == b'-') {
            let grp = &cleaned_filename[dash + 1..];
            if dash >= 2
                && (2..=15).contains(&grp.len())
                && grp.iter().all(|b| b.is_ascii_alphanumeric())
                && cleaned_filename[1..dash]
                    .iter()
                    .all(|&b| is_word_byte(b) || matches!(b, b'.' | b'-'))
                && matches_predb_regex(cleaned_filename)
            {
                write_result_pair(out, cleaned_filename, b"Cleaned scene name");
                return finish_cursor_min_ref(out, 16);
            }
        }
    }

    // 36. `Technical video basename` & 37. `Matching video parent basename`
    let norm_basename = php_basename_norm_slashes(filename);
    let Some(cand_len) = crate::filename::strip_known_file_suffixes_len(norm_basename, true) else {
        return -2;
    };
    let candidate = &norm_basename[..cand_len];
    let candidate_chars_ok = !candidate.is_empty()
        && candidate.iter().all(|&b| {
            b.is_ascii_alphanumeric()
                || matches!(
                    b,
                    b' ' | b'.'
                        | b'_'
                        | b'('
                        | b')'
                        | b'['
                        | b']'
                        | b'@'
                        | b'+'
                        | b','
                        | b'!'
                        | b'\''
                        | b'&'
                        | b'-'
                )
        });

    if candidate.len() != norm_basename.len() && candidate_chars_ok {
        let has_tech_ext = if let Some(dot) = norm_basename.iter().rposition(|&b| b == b'.') {
            let ext = &norm_basename[dot + 1..];
            if (3..=5).contains(&ext.len())
                && matches!(ext[0], b'r' | b'R')
                && ext[1..].iter().all(|b| b.is_ascii_digit())
            {
                true
            } else if !ext.is_empty() && ext.len() <= 4 {
                let mut lower = [0u8; 4];
                for (i, &b) in ext.iter().enumerate() {
                    lower[i] = b.to_ascii_lowercase();
                }
                matches!(
                    &lower[..ext.len()],
                    b"mkv"
                        | b"avi"
                        | b"mp4"
                        | b"m4v"
                        | b"ts"
                        | b"m2ts"
                        | b"rar"
                        | b"7z"
                        | b"par2"
                        | b"nfo"
                        | b"sfv"
                        | b"md5"
                        | b"sha1"
                )
            } else {
                false
            }
        } else {
            false
        };

        if has_tech_ext
            && candidate_has_real_title_prefix_before_year(candidate)
            && candidate_has_bounded_resolution(candidate)
            && candidate_has_bounded_source(candidate)
            && candidate_has_bounded_codec(candidate)
        {
            let mut norm_title = Vec::with_capacity(candidate.len());
            normalize_candidate_title_ascii(candidate, &mut norm_title);
            match crate::cleaning::is_plausible_release_title(&norm_title) {
                Some(true) => {
                    write_result_pair(out, candidate, b"Technical video basename");
                    return finish_cursor_min_ref(out, 16);
                }
                Some(false) => {}
                None => return -2,
            }
        }
    }

    let parent = php_parent_basename_norm_slashes(filename);
    if parent == candidate && candidate_chars_ok && norm_basename.len() >= 5 {
        let ext = &norm_basename[norm_basename.len() - 4..];
        let is_video4 = ext.eq_ignore_ascii_case(b".mkv")
            || ext.eq_ignore_ascii_case(b".avi")
            || ext.eq_ignore_ascii_case(b".mp4")
            || ext.eq_ignore_ascii_case(b".m4v");
        if is_video4
            && candidate_has_real_title_prefix_before_year(candidate)
            && candidate_has_bounded_resolution(candidate)
        {
            let mut norm_title = Vec::with_capacity(candidate.len());
            normalize_candidate_title_ascii(candidate, &mut norm_title);
            match crate::cleaning::is_plausible_release_title(&norm_title) {
                Some(true) => {
                    write_result_pair(out, candidate, b"Matching video parent basename");
                    return finish_cursor_min_ref(out, 16);
                }
                Some(false) => {}
                None => return -2,
            }
        }
    }

    // 38. `Folder name`:
    // `if (preg_match('/\w+[\-\w.\',;& ]+$/i', $filename, $result) && preg_match(self::PREDB_REGEX, $filename))`
    {
        let is_folder_tail =
            |b: u8| is_word_byte(b) || matches!(b, b'-' | b'.' | b'\'' | b',' | b';' | b'&' | b' ');
        let mut run_start = filename.len();
        while run_start > 0 && is_folder_tail(filename[run_start - 1]) {
            run_start -= 1;
        }
        while run_start < filename.len() && !is_word_byte(filename[run_start]) {
            run_start += 1;
        }
        if run_start + 1 < filename.len() && matches_predb_regex(filename) {
            write_result_pair(out, &filename[run_start..], b"Folder name");
            return finish_cursor_min_ref(out, 16);
        }
    }

    0
}

// ============================================================================
// C ABI Exports: NfoNameExtractor & FileNameExtractor
// ============================================================================

/// Extracts PreDB query candidate titles from `text[..text_len]` into `out_buf` as NUL-separated strings.
/// Returns:
/// - `>= 0`: total bytes written to `out_buf` (`complete = true`, `0` means `titles = []`)
/// - `-1`: invalid pointer arguments
/// - `-2`: declined to PHP fallback
/// - `-4`: candidate overflow (`> 100` unique candidates -> `['titles' => [], 'complete' => false]`)
/// - `<= -16`: `-(required_len as isize)` when `out_cap` is too small
///
/// # Safety
///
/// `text` must be valid for reads of `text_len` bytes when non-null and `text_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nfo_predb_candidates(
    text: *const u8,
    text_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if text_len == 0 {
        return 0;
    }
    if text.is_null() || (out_buf.is_null() && out_cap > 0) {
        return -1;
    }
    let src = unsafe { slice::from_raw_parts(text, text_len) };
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    nfo_predb_candidates_into(src, &mut cursor)
}

/// Evaluates `NfoNameExtractor::extractFromNfo` pattern matching on `text[..text_len]`, writing `newName\0method` into `out_buf`.
/// Returns:
/// - `> 0`: bytes written to `out_buf`
/// - `0`: no match (`null`)
/// - `-1`: invalid pointer arguments
/// - `-2`: declined to PHP fallback
/// - `<= -16`: `-(required_len as isize)` when `out_cap` is too small
///
/// # Safety
///
/// `text` must be valid for reads of `text_len` bytes when non-null and `text_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nfo_extract_pattern_match(
    text: *const u8,
    text_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if text_len == 0 {
        return 0;
    }
    if text.is_null() || (out_buf.is_null() && out_cap > 0) {
        return -1;
    }
    let src = unsafe { slice::from_raw_parts(text, text_len) };
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    nfo_extract_pattern_mode_into(src, 0, &mut cursor)
}

/// Evaluates `NfoNameExtractor` pattern matching for a specific `mode` (`0` = `extractFromNfo`, `1..=6` = individual `checkFor*` methods).
///
/// # Safety
///
/// `text` must be valid for reads of `text_len` bytes when non-null and `text_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nfo_extract_pattern_mode(
    text: *const u8,
    text_len: usize,
    mode: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if text_len == 0 {
        return 0;
    }
    if text.is_null() || (out_buf.is_null() && out_cap > 0) {
        return -1;
    }
    let src = unsafe { slice::from_raw_parts(text, text_len) };
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    nfo_extract_pattern_mode_into(src, mode, &mut cursor)
}

/// Evaluates `FileNameExtractor::extractFromFile` on `filename[..filename_len]`, writing `newName\0method` into `out_buf`.
/// Returns:
/// - `> 0`: bytes written to `out_buf`
/// - `0`: no match (`null`)
/// - `-1`: invalid pointer arguments
/// - `-2`: declined to PHP fallback
/// - `<= -16`: `-(required_len as isize)` when `out_cap` is too small
///
/// # Safety
///
/// `filename` must be valid for reads of `filename_len` bytes when non-null and `filename_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_filename_extract_candidate(
    filename: *const u8,
    filename_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if filename_len == 0 {
        return 0;
    }
    if filename.is_null() || (out_buf.is_null() && out_cap > 0) {
        return -1;
    }
    let src = unsafe { slice::from_raw_parts(filename, filename_len) };
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    filename_extract_candidate_into(src, &mut cursor)
}

/// Alias for `nntmux_filename_extract_candidate`.
///
/// # Safety
///
/// Same safety requirements as [`nntmux_filename_extract_candidate`].
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_file_extract_candidate(
    filename: *const u8,
    filename_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    unsafe { nntmux_filename_extract_candidate(filename, filename_len, out_buf, out_cap) }
}

// ============================================================================
// Wave 4 Slice 4: NzbFingerprintAnalyzer Accelerators
// ============================================================================

const CRC32_SLICE8_TABLE: [[u32; 256]; 8] = {
    let mut table = [[0u32; 256]; 8];
    let mut i = 0usize;
    while i < 256 {
        let mut crc = i as u32;
        let mut j = 0;
        while j < 8 {
            crc = if (crc & 1) != 0 {
                (crc >> 1) ^ 0xEDB8_8320
            } else {
                crc >> 1
            };
            j += 1;
        }
        table[0][i] = crc;
        i += 1;
    }
    let mut i = 0usize;
    while i < 256 {
        let mut crc = table[0][i];
        let mut k = 1usize;
        while k < 8 {
            crc = (crc >> 8) ^ table[0][(crc & 0xFF) as usize];
            table[k][i] = crc;
            k += 1;
        }
        i += 1;
    }
    table
};

#[inline]
pub fn crc32_ieee(data: &[u8]) -> u32 {
    let mut crc = 0xFFFF_FFFFu32;
    let (chunks, rem) = data.as_chunks::<8>();
    for chunk in chunks {
        let lo = u32::from_le_bytes([chunk[0], chunk[1], chunk[2], chunk[3]]) ^ crc;
        let hi = u32::from_le_bytes([chunk[4], chunk[5], chunk[6], chunk[7]]);
        crc = CRC32_SLICE8_TABLE[7][(lo & 0xFF) as usize]
            ^ CRC32_SLICE8_TABLE[6][((lo >> 8) & 0xFF) as usize]
            ^ CRC32_SLICE8_TABLE[5][((lo >> 16) & 0xFF) as usize]
            ^ CRC32_SLICE8_TABLE[4][(lo >> 24) as usize]
            ^ CRC32_SLICE8_TABLE[3][(hi & 0xFF) as usize]
            ^ CRC32_SLICE8_TABLE[2][((hi >> 8) & 0xFF) as usize]
            ^ CRC32_SLICE8_TABLE[1][((hi >> 16) & 0xFF) as usize]
            ^ CRC32_SLICE8_TABLE[0][(hi >> 24) as usize];
    }
    for &b in rem {
        crc = (crc >> 8) ^ CRC32_SLICE8_TABLE[0][((crc as u8) ^ b) as usize];
    }
    !crc
}

#[cfg(target_arch = "aarch64")]
const SHA256_K_AARCH64: [u32; 64] = [
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
];

#[cfg(target_arch = "aarch64")]
#[target_feature(enable = "neon,sha2")]
unsafe fn sha256_compress_blocks_aarch64(state: &mut [u32; 8], blocks: &[[u8; 64]]) {
    use std::arch::aarch64::{
        uint32x4_t, vaddq_u32, vld1q_u8, vld1q_u32, vreinterpretq_u32_u8, vrev32q_u8,
        vsha256h2q_u32, vsha256hq_u32, vsha256su0q_u32, vsha256su1q_u32, vst1q_u32,
    };

    let mut abcd: uint32x4_t = unsafe { vld1q_u32(state.as_ptr()) };
    let mut efgh: uint32x4_t = unsafe { vld1q_u32(state.as_ptr().add(4)) };

    for block in blocks {
        let abcd_save = abcd;
        let efgh_save = efgh;

        let mut s0 = vreinterpretq_u32_u8(vrev32q_u8(unsafe { vld1q_u8(block.as_ptr()) }));
        let mut s1 = vreinterpretq_u32_u8(vrev32q_u8(unsafe { vld1q_u8(block.as_ptr().add(16)) }));
        let mut s2 = vreinterpretq_u32_u8(vrev32q_u8(unsafe { vld1q_u8(block.as_ptr().add(32)) }));
        let mut s3 = vreinterpretq_u32_u8(vrev32q_u8(unsafe { vld1q_u8(block.as_ptr().add(48)) }));

        macro_rules! round4 {
            ($k_offset:expr, $w0:ident, $w1:ident, $w2:ident, $w3:ident, update) => {{
                let k = unsafe { vld1q_u32(SHA256_K_AARCH64.as_ptr().add($k_offset)) };
                let wk = vaddq_u32($w0, k);
                let tmp = abcd;
                abcd = vsha256hq_u32(abcd, efgh, wk);
                efgh = vsha256h2q_u32(efgh, tmp, wk);
                $w0 = vsha256su1q_u32(vsha256su0q_u32($w0, $w1), $w2, $w3);
            }};
            ($k_offset:expr, $w0:ident) => {{
                let k = unsafe { vld1q_u32(SHA256_K_AARCH64.as_ptr().add($k_offset)) };
                let wk = vaddq_u32($w0, k);
                let tmp = abcd;
                abcd = vsha256hq_u32(abcd, efgh, wk);
                efgh = vsha256h2q_u32(efgh, tmp, wk);
            }};
        }

        round4!(0, s0, s1, s2, s3, update);
        round4!(4, s1, s2, s3, s0, update);
        round4!(8, s2, s3, s0, s1, update);
        round4!(12, s3, s0, s1, s2, update);
        round4!(16, s0, s1, s2, s3, update);
        round4!(20, s1, s2, s3, s0, update);
        round4!(24, s2, s3, s0, s1, update);
        round4!(28, s3, s0, s1, s2, update);
        round4!(32, s0, s1, s2, s3, update);
        round4!(36, s1, s2, s3, s0, update);
        round4!(40, s2, s3, s0, s1, update);
        round4!(44, s3, s0, s1, s2, update);
        round4!(48, s0);
        round4!(52, s1);
        round4!(56, s2);
        round4!(60, s3);

        abcd = vaddq_u32(abcd, abcd_save);
        efgh = vaddq_u32(efgh, efgh_save);
    }

    unsafe {
        vst1q_u32(state.as_mut_ptr(), abcd);
        vst1q_u32(state.as_mut_ptr().add(4), efgh);
    }
}

#[inline]
pub fn sha256_fast_hex(data: &[u8]) -> [u8; 64] {
    #[cfg(target_arch = "aarch64")]
    {
        if std::arch::is_aarch64_feature_detected!("sha2") {
            let mut state: [u32; 8] = [
                0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab,
                0x5be0cd19,
            ];
            let (blocks, remainder) = data.as_chunks::<64>();
            if !blocks.is_empty() {
                unsafe { sha256_compress_blocks_aarch64(&mut state, blocks) };
            }
            let bit_len = (data.len() as u64).wrapping_mul(8);
            let mut tail = [0u8; 64];
            tail[..remainder.len()].copy_from_slice(remainder);
            tail[remainder.len()] = 0x80;
            if remainder.len() >= 56 {
                unsafe { sha256_compress_blocks_aarch64(&mut state, std::slice::from_ref(&tail)) };
                tail.fill(0);
            }
            tail[56..64].copy_from_slice(&bit_len.to_be_bytes());
            unsafe { sha256_compress_blocks_aarch64(&mut state, std::slice::from_ref(&tail)) };

            let mut hex = [0u8; 64];
            for (i, &word) in state.iter().enumerate() {
                for (j, &byte) in word.to_be_bytes().iter().enumerate() {
                    let idx = (i * 4 + j) * 2;
                    hex[idx] = crate::HEX_LOWER[(byte >> 4) as usize];
                    hex[idx + 1] = crate::HEX_LOWER[(byte & 0x0f) as usize];
                }
            }
            return hex;
        }
    }
    crate::predb::sha256_hex_slice(data)
}

/// Matches `NzbFingerprintAnalyzer::fileKind(string $subject)`:
/// - `2` => `"par2"` (`/\.par2\b/i`)
/// - `1` => `"rar"` (`/\.(?:part\d+\.rar|rar|r\d{2,})\b/i`)
/// - `0` => `"other"`
pub fn nzb_file_kind_code(subject: &[u8]) -> u8 {
    let n = subject.len();
    let mut has_rar = false;
    let mut i = 0usize;
    while i < n {
        if subject[i] == b'.' {
            let rest = &subject[i + 1..];
            if rest.len() >= 4
                && rest[..4].eq_ignore_ascii_case(b"par2")
                && rest.get(4).is_none_or(|&b| !is_word_byte(b))
            {
                return 2;
            }
            if !has_rar {
                if rest.len() >= 3
                    && rest[..3].eq_ignore_ascii_case(b"rar")
                    && rest.get(3).is_none_or(|&b| !is_word_byte(b))
                {
                    has_rar = true;
                } else if rest.len() >= 3 && matches!(rest[0], b'r' | b'R') {
                    let mut d = 1usize;
                    while d < rest.len() && rest[d].is_ascii_digit() {
                        d += 1;
                    }
                    if d >= 3 && rest.get(d).is_none_or(|&b| !is_word_byte(b)) {
                        has_rar = true;
                    }
                }
            }
        }
        i += 1;
    }
    if has_rar { 1 } else { 0 }
}

/// Matches `NzbFingerprintAnalyzer::formatHint(string $subject)`:
/// `/\.([a-z0-9]{1,8})\b/i`
pub fn nzb_format_hint_slice(subject: &[u8], out_hint: &mut [u8; 8]) -> usize {
    let n = subject.len();
    let mut i = 0usize;
    while i < n {
        if subject[i] == b'.' {
            let start = i + 1;
            let mut end = start;
            while end < n && subject[end].is_ascii_alphanumeric() {
                end += 1;
            }
            let len = end - start;
            if (1..=8).contains(&len) && subject.get(end) != Some(&b'_') {
                for (dst, &src) in out_hint[..len].iter_mut().zip(&subject[start..end]) {
                    *dst = src.to_ascii_lowercase();
                }
                return len;
            }
            i = end;
            continue;
        }
        i += 1;
    }
    0
}

/// Matches `NzbFingerprintAnalyzer::detectFormatSignatures(string $bytes)`:
/// Returns a 7-bit bitmask of matched format signatures:
/// - bit 0 (`1`): `RAR 5`
/// - bit 1 (`2`): `RAR 4`
/// - bit 2 (`4`): `7-Zip`
/// - bit 3 (`8`): `ZIP`
/// - bit 4 (`16`): `gzip`
/// - bit 5 (`32`): `XZ`
/// - bit 6 (`64`): `ISO 9660 descriptor` (offset 32769)
#[inline]
pub fn detect_format_signatures_mask(bytes: &[u8]) -> u32 {
    let mut mask = 0u32;
    if bytes.starts_with(b"Rar!\x1A\x07\x01\x00") {
        mask |= 1;
    }
    if bytes.starts_with(b"Rar!\x1A\x07\x00") {
        mask |= 2;
    }
    if bytes.starts_with(b"7z\xBC\xAF\x27\x1C") {
        mask |= 4;
    }
    if bytes.starts_with(b"PK\x03\x04") {
        mask |= 8;
    }
    if bytes.starts_with(b"\x1F\x8B\x08") {
        mask |= 16;
    }
    if bytes.starts_with(b"\xFD7zXZ\x00") {
        mask |= 32;
    }
    if bytes.get(32_769..32_774) == Some(b"CD001") {
        mask |= 64;
    }
    mask
}

#[inline]
fn nzb_metadata_number(raw: &[u8]) -> Option<i64> {
    if raw.is_empty() || !raw.iter().all(|b| b.is_ascii_digit()) {
        return None;
    }
    let mut val = 0i64;
    for &b in raw {
        val = val.checked_mul(10)?.checked_add(i64::from(b - b'0'))?;
    }
    Some(val)
}

#[inline]
fn write_usize_decimal(out: &mut OutCursor<'_>, n: usize) {
    write_u64_decimal(out, n as u64);
}

#[inline]
fn write_u64_decimal(out: &mut OutCursor<'_>, mut n: u64) {
    if n == 0 {
        out.push(b'0');
        return;
    }
    let mut buf = [0u8; 20];
    let mut pos = buf.len();
    while n > 0 {
        pos -= 1;
        buf[pos] = b'0' + (n % 10) as u8;
        n /= 10;
    }
    out.extend(&buf[pos..]);
}

#[inline]
fn is_nzb_xml_ws(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\r' | b'\n')
}

#[inline]
fn skip_nzb_xml_ws(bytes: &[u8], mut pos: usize) -> usize {
    while pos < bytes.len() && is_nzb_xml_ws(bytes[pos]) {
        pos += 1;
    }
    pos
}

fn skip_nzb_xml_ws_and_comments(bytes: &[u8], mut pos: usize) -> Option<usize> {
    loop {
        pos = skip_nzb_xml_ws(bytes, pos);
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

fn unescape_nzb_xml_utf8(raw: &[u8], is_attr: bool, dst: &mut Vec<u8>) -> bool {
    if std::str::from_utf8(raw).is_err()
        || raw.iter().any(|&b| {
            (b < 0x20 && !matches!(b, b'\t' | b'\r' | b'\n'))
                || (is_attr && matches!(b, b'\t' | b'\r' | b'\n'))
        })
        || (!is_attr && php_trim(raw).contains(&b'\r'))
        || (!is_attr && raw.windows(3).any(|w| w == b"]]>"))
    {
        return false;
    }
    if !raw.iter().any(|&b| b == b'&' || b == b'<') {
        dst.extend_from_slice(raw);
        return true;
    }
    let mut i = 0usize;
    let n = raw.len();
    while i < n {
        let b = raw[i];
        if b == b'<' {
            return false;
        }
        if b != b'&' {
            dst.push(b);
            i += 1;
            continue;
        }
        let rest = &raw[i + 1..];
        let Some(semi) = rest.iter().take(12).position(|&c| c == b';') else {
            return false;
        };
        let ent = &rest[..semi];
        match ent {
            b"amp" => dst.push(b'&'),
            b"lt" => dst.push(b'<'),
            b"gt" => dst.push(b'>'),
            b"quot" => dst.push(b'"'),
            b"apos" => dst.push(b'\''),
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
                if code < 0x20 || code == 0x7F {
                    return false;
                }
                let Some(ch) = char::from_u32(code) else {
                    return false;
                };
                let mut u8_buf = [0u8; 4];
                dst.extend_from_slice(ch.encode_utf8(&mut u8_buf).as_bytes());
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
                if code < 0x20 || code == 0x7F {
                    return false;
                }
                let Some(ch) = char::from_u32(code) else {
                    return false;
                };
                let mut u8_buf = [0u8; 4];
                dst.extend_from_slice(ch.encode_utf8(&mut u8_buf).as_bytes());
            }
            _ => return false,
        }
        i += 1 + semi + 1;
    }
    true
}

#[inline]
fn match_nzb_tag_exact(bytes: &[u8], pos: usize, tag_exact: &[u8]) -> Option<usize> {
    let end = pos + tag_exact.len();
    if bytes.get(pos..end) != Some(tag_exact) {
        return None;
    }
    let next = bytes.get(end).copied()?;
    if is_nzb_xml_ws(next) || next == b'>' || next == b'/' {
        Some(end)
    } else {
        None
    }
}

#[inline]
fn expect_nzb_closing_tag(bytes: &[u8], pos: usize, close_exact: &[u8]) -> Option<usize> {
    let end = pos + close_exact.len();
    if bytes.get(pos..end) != Some(close_exact) {
        return None;
    }
    let after_ws = skip_nzb_xml_ws(bytes, end);
    if bytes.get(after_ws) == Some(&b'>') {
        Some(after_ws + 1)
    } else {
        None
    }
}

fn parse_nzb_xml_attributes<F>(
    bytes: &[u8],
    mut pos: usize,
    allow_xmlns: bool,
    mut on_attr: F,
) -> Option<(usize, bool)>
where
    F: FnMut(&[u8], &[u8]) -> bool,
{
    let mut seen_names: [&[u8]; 12] = [&[]; 12];
    let mut seen_count = 0usize;

    loop {
        let prev_pos = pos;
        pos = skip_nzb_xml_ws(bytes, pos);
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

        pos = skip_nzb_xml_ws(bytes, pos);
        if bytes.get(pos) != Some(&b'=') {
            return None;
        }
        pos = skip_nzb_xml_ws(bytes, pos + 1);
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
        if !on_attr(attr_name, attr_val) {
            return None;
        }
    }
}

fn parse_nzb_xml_decl(bytes: &[u8], mut pos: usize) -> Option<usize> {
    if bytes.get(pos..pos + 5) != Some(b"<?xml") {
        return Some(pos);
    }
    let after_kw = pos + 5;
    if !bytes.get(after_kw).copied().is_some_and(is_nzb_xml_ws) {
        return None;
    }
    pos = after_kw;
    let mut seen_version = false;
    let mut seen_encoding = false;
    let mut seen_standalone = false;

    loop {
        let prev = pos;
        pos = skip_nzb_xml_ws(bytes, pos);
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
        pos = skip_nzb_xml_ws(bytes, pos);
        if bytes.get(pos) != Some(&b'=') {
            return None;
        }
        pos = skip_nzb_xml_ws(bytes, pos + 1);
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

/// Matches the standard external DOCTYPE stripped by `NzbFingerprintAnalyzer::analyzeManifest`:
/// `/<!DOCTYPE\s+nzb\s+PUBLIC\s+(["\'])-\/\/newzBin\/\/DTD NZB 1\.1\/\/EN\1\s+(["\'])http:\/\/www\.newzbin\.com\/DTD\/nzb\/nzb-1\.1\.dtd\2\s*>/i`
fn match_standard_nzb_doctype(bytes: &[u8], mut pos: usize) -> Option<usize> {
    if bytes.len() - pos < 90 || !bytes[pos..pos + 9].eq_ignore_ascii_case(b"<!doctype") {
        return None;
    }
    pos += 9;
    let ws1 = pos;
    while pos < bytes.len() && is_pcre_space(bytes[pos]) {
        pos += 1;
    }
    if pos == ws1 || bytes.len() - pos < 3 || !bytes[pos..pos + 3].eq_ignore_ascii_case(b"nzb") {
        return None;
    }
    pos += 3;
    let ws2 = pos;
    while pos < bytes.len() && is_pcre_space(bytes[pos]) {
        pos += 1;
    }
    if pos == ws2 || bytes.len() - pos < 6 || !bytes[pos..pos + 6].eq_ignore_ascii_case(b"public") {
        return None;
    }
    pos += 6;
    let ws3 = pos;
    while pos < bytes.len() && is_pcre_space(bytes[pos]) {
        pos += 1;
    }
    if pos == ws3 {
        return None;
    }
    let q1 = *bytes.get(pos)?;
    if q1 != b'"' && q1 != b'\'' {
        return None;
    }
    pos += 1;
    const PUB_ID: &[u8] = b"-//newzbin//dtd nzb 1.1//en";
    if bytes.len() - pos < PUB_ID.len() + 1
        || !bytes[pos..pos + PUB_ID.len()].eq_ignore_ascii_case(PUB_ID)
    {
        return None;
    }
    pos += PUB_ID.len();
    if bytes.get(pos) != Some(&q1) {
        return None;
    }
    pos += 1;
    let ws4 = pos;
    while pos < bytes.len() && is_pcre_space(bytes[pos]) {
        pos += 1;
    }
    if pos == ws4 {
        return None;
    }
    let q2 = *bytes.get(pos)?;
    if q2 != b'"' && q2 != b'\'' {
        return None;
    }
    pos += 1;
    const SYS_ID: &[u8] = b"http://www.newzbin.com/dtd/nzb/nzb-1.1.dtd";
    if bytes.len() - pos < SYS_ID.len() + 1
        || !bytes[pos..pos + SYS_ID.len()].eq_ignore_ascii_case(SYS_ID)
    {
        return None;
    }
    pos += SYS_ID.len();
    if bytes.get(pos) != Some(&q2) {
        return None;
    }
    pos += 1;
    while pos < bytes.len() && is_pcre_space(bytes[pos]) {
        pos += 1;
    }
    if bytes.get(pos) == Some(&b'>') {
        Some(pos + 1)
    } else {
        None
    }
}

struct ParsedManifestSegment {
    number: i64,
    bytes: Option<i64>,
    src_num_range: (usize, usize),
    src_bytes_range: (usize, usize),
    msg_id_range: (usize, usize),
}

struct ParsedManifestFile {
    kind_code: u8,
    format_hint: [u8; 8],
    format_hint_len: usize,
    subject: Vec<u8>,
    poster: Vec<u8>,
    date: Option<i64>,
    source_date: Vec<u8>,
    groups: Vec<(usize, usize)>,
    group_arena: Vec<u8>,
    source_segment_record_count: usize,
    invalid_segment_record_count: usize,
    invalid_segment_metadata_count: usize,
    duplicate_message_id_count: usize,
    duplicate_segment_number_count: usize,
    segment_number_sequence_valid: bool,
    advertised_bytes: Option<i64>,
    advertised_bytes_overflow: bool,
    segments: Vec<ParsedManifestSegment>,
    seg_arena: Vec<u8>,
}

pub(crate) fn nzb_analyze_manifest_into(bytes: &[u8], out: &mut OutCursor<'_>) -> isize {
    if bytes.is_empty() || bytes.len() > 33_554_432 {
        return -1;
    }
    if find_ascii_ci(bytes, b"<!entity").is_some() {
        return -1;
    }

    let mut pos = 0usize;
    if bytes.starts_with(b"\xEF\xBB\xBF") {
        pos = 3;
    }
    if std::str::from_utf8(&bytes[pos..]).is_err() {
        return -1;
    }
    for &b in &bytes[pos..] {
        if (b < 0x20 && !matches!(b, b'\t' | b'\n' | b'\r')) || b == 0x7F {
            return -1;
        }
    }

    let Some(after_decl) = parse_nzb_xml_decl(bytes, pos) else {
        return -1;
    };
    pos = after_decl;

    let mut doctype_span: Option<(usize, usize)> = None;
    loop {
        let Some(next_pos) = skip_nzb_xml_ws_and_comments(bytes, pos) else {
            return -1;
        };
        pos = next_pos;
        if bytes.get(pos..pos + 9).is_some_and(|s| s.eq_ignore_ascii_case(b"<!doctype")) {
            if doctype_span.is_some() {
                return -1;
            }
            let Some(after_dt) = match_standard_nzb_doctype(bytes, pos) else {
                return -1;
            };
            doctype_span = Some((pos, after_dt));
            pos = after_dt;
        } else {
            break;
        }
    }

    match doctype_span {
        Some((dt_start, dt_end)) => {
            if find_ascii_ci(&bytes[..dt_start], b"<!doctype").is_some()
                || find_ascii_ci(&bytes[dt_end..], b"<!doctype").is_some()
            {
                return -1;
            }
        }
        None => {
            if find_ascii_ci(bytes, b"<!doctype").is_some() {
                return -1;
            }
        }
    }

    let Some(nzb_attr_start) = match_nzb_tag_exact(bytes, pos, b"<nzb") else {
        return -1;
    };
    let mut tmp_attr = Vec::with_capacity(128);
    let Some((after_nzb_open, nzb_self_closing)) =
        parse_nzb_xml_attributes(bytes, nzb_attr_start, true, |_k, v| {
            tmp_attr.clear();
            unescape_nzb_xml_utf8(v, true, &mut tmp_attr)
        })
    else {
        return -1;
    };
    if nzb_self_closing {
        return -1;
    }
    pos = after_nzb_open;

    let mut meta_groups: Vec<(Vec<u8>, Vec<Vec<u8>>)> = Vec::new();
    let mut files: Vec<ParsedManifestFile> = Vec::new();
    let mut total_file_count = [0usize; 3]; // 0 = other, 1 = rar, 2 = par2
    let mut total_seg_count = [0usize; 3];
    let mut total_adv_bytes: [Option<i64>; 3] = [Some(0), Some(0), Some(0)];
    let mut total_adv_overflow = [false; 3];

    let mut attr_buf1 = Vec::with_capacity(256);
    let mut attr_buf2 = Vec::with_capacity(256);
    let mut attr_buf3 = Vec::with_capacity(64);
    let mut text_buf = Vec::with_capacity(256);

    loop {
        let Some(next_pos) = skip_nzb_xml_ws_and_comments(bytes, pos) else {
            return -1;
        };
        pos = next_pos;

        if let Some(after_nzb_close) = expect_nzb_closing_tag(bytes, pos, b"</nzb") {
            let Some(end_pos) = skip_nzb_xml_ws_and_comments(bytes, after_nzb_close) else {
                return -1;
            };
            if end_pos != bytes.len() {
                return -1;
            }
            break;
        }

        if let Some(head_attr_start) = match_nzb_tag_exact(bytes, pos, b"<head") {
            let Some((after_head_open, head_self_closing)) =
                parse_nzb_xml_attributes(bytes, head_attr_start, false, |_k, v| {
                    tmp_attr.clear();
                    unescape_nzb_xml_utf8(v, true, &mut tmp_attr)
                })
            else {
                return -1;
            };
            pos = after_head_open;
            if !head_self_closing {
                loop {
                    let Some(p) = skip_nzb_xml_ws_and_comments(bytes, pos) else {
                        return -1;
                    };
                    pos = p;
                    if let Some(after_head_close) = expect_nzb_closing_tag(bytes, pos, b"</head") {
                        pos = after_head_close;
                        break;
                    }
                    let Some(meta_attr_start) = match_nzb_tag_exact(bytes, pos, b"<meta") else {
                        return -1;
                    };
                    attr_buf1.clear();
                    let Some((after_meta_open, meta_self_closing)) =
                        parse_nzb_xml_attributes(bytes, meta_attr_start, false, |k, v| {
                            if k == b"type" {
                                attr_buf1.clear();
                                unescape_nzb_xml_utf8(v, true, &mut attr_buf1)
                            } else {
                                tmp_attr.clear();
                                unescape_nzb_xml_utf8(v, true, &mut tmp_attr)
                            }
                        })
                    else {
                        return -1;
                    };
                    pos = after_meta_open;
                    text_buf.clear();
                    if !meta_self_closing {
                        let text_start = pos;
                        while pos < bytes.len() && bytes[pos] != b'<' {
                            pos += 1;
                        }
                        if !unescape_nzb_xml_utf8(&bytes[text_start..pos], false, &mut text_buf) {
                            return -1;
                        }
                        let Some(after_meta_close) = expect_nzb_closing_tag(bytes, pos, b"</meta")
                        else {
                            return -1;
                        };
                        pos = after_meta_close;
                    }
                    let trimmed_type = php_trim(&attr_buf1);
                    if !trimmed_type.is_empty() {
                        let trimmed_val = php_trim(&text_buf).to_vec();
                        if let Some(existing) = meta_groups
                            .iter_mut()
                            .find(|(t, _)| t.as_slice() == trimmed_type)
                        {
                            existing.1.push(trimmed_val);
                        } else {
                            meta_groups.push((trimmed_type.to_vec(), vec![trimmed_val]));
                        }
                    }
                }
            }
            continue;
        }

        let Some(file_attr_start) = match_nzb_tag_exact(bytes, pos, b"<file") else {
            return -1;
        };
        attr_buf1.clear(); // subject
        attr_buf2.clear(); // poster
        attr_buf3.clear(); // date
        let Some((after_file_open, file_self_closing)) =
            parse_nzb_xml_attributes(bytes, file_attr_start, false, |k, v| match k {
                b"subject" => {
                    attr_buf1.clear();
                    unescape_nzb_xml_utf8(v, true, &mut attr_buf1)
                }
                b"poster" => {
                    attr_buf2.clear();
                    unescape_nzb_xml_utf8(v, true, &mut attr_buf2)
                }
                b"date" => {
                    attr_buf3.clear();
                    unescape_nzb_xml_utf8(v, true, &mut attr_buf3)
                }
                _ => {
                    tmp_attr.clear();
                    unescape_nzb_xml_utf8(v, true, &mut tmp_attr)
                }
            })
        else {
            return -1;
        };
        pos = after_file_open;

        let subject_trimmed = php_trim(&attr_buf1).to_vec();
        let kind_code = nzb_file_kind_code(&subject_trimmed);
        let mut format_hint = [0u8; 8];
        let format_hint_len = nzb_format_hint_slice(&subject_trimmed, &mut format_hint);
        let poster_vec = attr_buf2.clone();
        let source_date_vec = attr_buf3.clone();
        let date_parsed = nzb_metadata_number(php_trim(&attr_buf3));

        let mut group_arena = Vec::with_capacity(128);
        let mut groups: Vec<(usize, usize)> = Vec::new();
        let mut seg_arena = Vec::with_capacity(1024);
        let mut segments: Vec<ParsedManifestSegment> = Vec::new();
        let mut source_segment_record_count = 0usize;
        let mut invalid_segment_record_count = 0usize;
        let mut invalid_segment_metadata_count = 0usize;
        let mut advertised_bytes: Option<i64> = Some(0);
        let mut advertised_bytes_overflow = false;

        if !file_self_closing {
            loop {
                let Some(p) = skip_nzb_xml_ws_and_comments(bytes, pos) else {
                    return -1;
                };
                pos = p;
                if let Some(after_file_close) = expect_nzb_closing_tag(bytes, pos, b"</file") {
                    pos = after_file_close;
                    break;
                }
                if let Some(groups_attr_start) = match_nzb_tag_exact(bytes, pos, b"<groups") {
                    let Some((after_groups_open, groups_self_closing)) =
                        parse_nzb_xml_attributes(bytes, groups_attr_start, false, |_k, v| {
                            tmp_attr.clear();
                            unescape_nzb_xml_utf8(v, true, &mut tmp_attr)
                        })
                    else {
                        return -1;
                    };
                    pos = after_groups_open;
                    if !groups_self_closing {
                        loop {
                            let Some(gp) = skip_nzb_xml_ws_and_comments(bytes, pos) else {
                                return -1;
                            };
                            pos = gp;
                            if let Some(after_groups_close) =
                                expect_nzb_closing_tag(bytes, pos, b"</groups")
                            {
                                pos = after_groups_close;
                                break;
                            }
                            let Some(group_attr_start) = match_nzb_tag_exact(bytes, pos, b"<group")
                            else {
                                return -1;
                            };
                            let Some((after_group_open, group_self_closing)) =
                                parse_nzb_xml_attributes(bytes, group_attr_start, false, |_k, v| {
                                    tmp_attr.clear();
                                    unescape_nzb_xml_utf8(v, true, &mut tmp_attr)
                                })
                            else {
                                return -1;
                            };
                            pos = after_group_open;
                            text_buf.clear();
                            if !group_self_closing {
                                let text_start = pos;
                                while pos < bytes.len() && bytes[pos] != b'<' {
                                    pos += 1;
                                }
                                if !unescape_nzb_xml_utf8(
                                    &bytes[text_start..pos],
                                    false,
                                    &mut text_buf,
                                ) {
                                    return -1;
                                }
                                let Some(after_group_close) =
                                    expect_nzb_closing_tag(bytes, pos, b"</group")
                                else {
                                    return -1;
                                };
                                pos = after_group_close;
                            }
                            let g_trimmed = php_trim(&text_buf);
                            let gs = group_arena.len();
                            group_arena.extend_from_slice(g_trimmed);
                            groups.push((gs, group_arena.len()));
                        }
                    }
                } else if let Some(segs_attr_start) = match_nzb_tag_exact(bytes, pos, b"<segments")
                {
                    let Some((after_segs_open, segs_self_closing)) =
                        parse_nzb_xml_attributes(bytes, segs_attr_start, false, |_k, v| {
                            tmp_attr.clear();
                            unescape_nzb_xml_utf8(v, true, &mut tmp_attr)
                        })
                    else {
                        return -1;
                    };
                    pos = after_segs_open;
                    if !segs_self_closing {
                        loop {
                            let Some(sp) = skip_nzb_xml_ws_and_comments(bytes, pos) else {
                                return -1;
                            };
                            pos = sp;
                            if let Some(after_segs_close) =
                                expect_nzb_closing_tag(bytes, pos, b"</segments")
                            {
                                pos = after_segs_close;
                                break;
                            }
                            let Some(seg_attr_start) = match_nzb_tag_exact(bytes, pos, b"<segment")
                            else {
                                return -1;
                            };
                            attr_buf1.clear(); // number
                            attr_buf2.clear(); // bytes
                            let Some((after_seg_open, seg_self_closing)) =
                                parse_nzb_xml_attributes(
                                    bytes,
                                    seg_attr_start,
                                    false,
                                    |k, v| match k {
                                        b"number" => {
                                            attr_buf1.clear();
                                            unescape_nzb_xml_utf8(v, true, &mut attr_buf1)
                                        }
                                        b"bytes" => {
                                            attr_buf2.clear();
                                            unescape_nzb_xml_utf8(v, true, &mut attr_buf2)
                                        }
                                        _ => {
                                            tmp_attr.clear();
                                            unescape_nzb_xml_utf8(v, true, &mut tmp_attr)
                                        }
                                    },
                                )
                            else {
                                return -1;
                            };
                            pos = after_seg_open;
                            text_buf.clear();
                            if !seg_self_closing {
                                let text_start = pos;
                                while pos < bytes.len() && bytes[pos] != b'<' {
                                    pos += 1;
                                }
                                if !unescape_nzb_xml_utf8(
                                    &bytes[text_start..pos],
                                    false,
                                    &mut text_buf,
                                ) {
                                    return -1;
                                }
                                let Some(after_seg_close) =
                                    expect_nzb_closing_tag(bytes, pos, b"</segment")
                                else {
                                    return -1;
                                };
                                pos = after_seg_close;
                            }

                            source_segment_record_count += 1;
                            let msg_trimmed = php_trim(&text_buf);
                            let mut msg_stripped = msg_trimmed;
                            while let Some(&first) = msg_stripped.first() {
                                if first == b'<' || first == b'>' {
                                    msg_stripped = &msg_stripped[1..];
                                } else {
                                    break;
                                }
                            }
                            while let Some(&last) = msg_stripped.last() {
                                if last == b'<' || last == b'>' {
                                    msg_stripped = &msg_stripped[..msg_stripped.len() - 1];
                                } else {
                                    break;
                                }
                            }
                            if msg_trimmed.is_empty()
                                || msg_stripped.is_empty()
                                || !msg_stripped
                                    .iter()
                                    .all(|&b| b > 0x20 && b != b'<' && b != b'>' && b != 0x7F)
                            {
                                invalid_segment_record_count += 1;
                                continue;
                            }

                            let seg_num_trimmed = php_trim(&attr_buf1);
                            let seg_bytes_trimmed = php_trim(&attr_buf2);
                            let parsed_num = nzb_metadata_number(seg_num_trimmed);
                            let parsed_bytes = nzb_metadata_number(seg_bytes_trimmed);
                            if parsed_num.is_none_or(|n| n < 1) || parsed_bytes.is_none() {
                                invalid_segment_metadata_count += 1;
                            }
                            let bytes_is_digits = !seg_bytes_trimmed.is_empty()
                                && seg_bytes_trimmed.iter().all(|b| b.is_ascii_digit());
                            if (parsed_bytes.is_none() && bytes_is_digits)
                                || (advertised_bytes.is_some()
                                    && parsed_bytes.is_some()
                                    && parsed_bytes.unwrap()
                                        > i64::MAX - advertised_bytes.unwrap())
                            {
                                advertised_bytes_overflow = true;
                            }
                            advertised_bytes = match (advertised_bytes, parsed_bytes) {
                                (Some(a), Some(b)) => a.checked_add(b),
                                _ => None,
                            };

                            let ns = seg_arena.len();
                            seg_arena.extend_from_slice(seg_num_trimmed);
                            let ne = seg_arena.len();
                            seg_arena.extend_from_slice(seg_bytes_trimmed);
                            let be = seg_arena.len();
                            seg_arena.extend_from_slice(msg_stripped);
                            let me = seg_arena.len();

                            segments.push(ParsedManifestSegment {
                                number: parsed_num.unwrap_or(0),
                                bytes: parsed_bytes,
                                src_num_range: (ns, ne),
                                src_bytes_range: (ne, be),
                                msg_id_range: (be, me),
                            });
                        }
                    }
                } else {
                    return -1;
                }
            }
        }

        if segments.len() > 1 {
            segments.sort_by_key(|s| s.number);
        }

        let segment_number_sequence_valid = !segments.is_empty()
            && segments
                .iter()
                .enumerate()
                .all(|(idx, s)| s.number == (idx as i64) + 1);

        let duplicate_segment_number_count = segments
            .windows(2)
            .filter(|w| w[0].number == w[1].number)
            .count();

        let duplicate_message_id_count = if segments.len() <= 1 {
            0
        } else {
            let mut msg_slices: Vec<&[u8]> = segments
                .iter()
                .map(|s| &seg_arena[s.msg_id_range.0..s.msg_id_range.1])
                .collect();
            msg_slices.sort_unstable();
            msg_slices.windows(2).filter(|w| w[0] == w[1]).count()
        };

        let k_idx = kind_code as usize;
        total_file_count[k_idx] += 1;
        total_seg_count[k_idx] += segments.len();
        if advertised_bytes_overflow
            || (total_adv_bytes[k_idx].is_some()
                && advertised_bytes.is_some()
                && advertised_bytes.unwrap() > i64::MAX - total_adv_bytes[k_idx].unwrap())
        {
            total_adv_overflow[k_idx] = true;
        }
        total_adv_bytes[k_idx] = match (total_adv_bytes[k_idx], advertised_bytes) {
            (Some(a), Some(b)) => a.checked_add(b),
            _ => None,
        };

        files.push(ParsedManifestFile {
            kind_code,
            format_hint,
            format_hint_len,
            subject: subject_trimmed,
            poster: poster_vec,
            date: date_parsed,
            source_date: source_date_vec,
            groups,
            group_arena,
            source_segment_record_count,
            invalid_segment_record_count,
            invalid_segment_metadata_count,
            duplicate_message_id_count,
            duplicate_segment_number_count,
            segment_number_sequence_valid,
            advertised_bytes,
            advertised_bytes_overflow,
            segments,
            seg_arena,
        });
    }

    if files.is_empty() {
        return -1;
    }

    // Emit NUL-separated manifest wire format:
    // meta_group_count \0 (type \0 val_count \0 val_0 \0 ...)*
    // file_count \0
    // rar_fc \0 rar_sc \0 rar_ab \0 rar_abo \0
    // par2_fc \0 par2_sc \0 par2_ab \0 par2_abo \0
    // other_fc \0 other_sc \0 other_ab \0 other_abo \0
    // advertised_byte_totals_valid \0
    // For each file:
    //   kind \0 format_hint \0 subject \0 poster \0 date \0 source_date \0
    //   group_count \0 (group \0)*
    //   seg_count \0 src_seg_count \0 inv_seg_count \0 inv_meta_count \0
    //   dup_msgid_count \0 dup_segnum_count \0 seq_valid \0 adv_bytes \0 adv_overflow \0
    //   For each segment:
    //     number \0 bytes \0 source_number \0 source_bytes \0 messageid (NUL-separated)
    write_usize_decimal(out, meta_groups.len());
    for (m_type, m_vals) in &meta_groups {
        out.push(0);
        out.extend(m_type);
        out.push(0);
        write_usize_decimal(out, m_vals.len());
        for v in m_vals {
            out.push(0);
            out.extend(v);
        }
    }

    out.push(0);
    write_usize_decimal(out, files.len());

    // Totals in exact order: rar (1), par2 (2), other (0)
    for &k_idx in &[1usize, 2, 0] {
        out.push(0);
        write_usize_decimal(out, total_file_count[k_idx]);
        out.push(0);
        write_usize_decimal(out, total_seg_count[k_idx]);
        out.push(0);
        if let Some(ab) = total_adv_bytes[k_idx] {
            write_u64_decimal(out, ab as u64);
        }
        out.push(0);
        out.push(if total_adv_overflow[k_idx] { b'1' } else { b'0' });
    }

    let adv_totals_valid =
        total_adv_bytes[0].is_some() && total_adv_bytes[1].is_some() && total_adv_bytes[2].is_some();
    out.push(0);
    out.push(if adv_totals_valid { b'1' } else { b'0' });

    for f in &files {
        out.push(0);
        match f.kind_code {
            2 => out.extend(b"par2"),
            1 => out.extend(b"rar"),
            _ => out.extend(b"other"),
        }
        out.push(0);
        if f.format_hint_len == 0 {
            out.extend(b"unknown");
        } else {
            out.extend(&f.format_hint[..f.format_hint_len]);
        }
        out.push(0);
        out.extend(&f.subject);
        out.push(0);
        out.extend(&f.poster);
        out.push(0);
        if let Some(d) = f.date {
            write_u64_decimal(out, d as u64);
        }
        out.push(0);
        out.extend(&f.source_date);
        out.push(0);
        write_usize_decimal(out, f.groups.len());
        for &(gs, ge) in &f.groups {
            out.push(0);
            out.extend(&f.group_arena[gs..ge]);
        }
        out.push(0);
        write_usize_decimal(out, f.segments.len());
        out.push(0);
        write_usize_decimal(out, f.source_segment_record_count);
        out.push(0);
        write_usize_decimal(out, f.invalid_segment_record_count);
        out.push(0);
        write_usize_decimal(out, f.invalid_segment_metadata_count);
        out.push(0);
        write_usize_decimal(out, f.duplicate_message_id_count);
        out.push(0);
        write_usize_decimal(out, f.duplicate_segment_number_count);
        out.push(0);
        out.push(if f.segment_number_sequence_valid {
            b'1'
        } else {
            b'0'
        });
        out.push(0);
        if let Some(ab) = f.advertised_bytes {
            write_u64_decimal(out, ab as u64);
        }
        out.push(0);
        out.push(if f.advertised_bytes_overflow {
            b'1'
        } else {
            b'0'
        });
        for seg in &f.segments {
            out.push(0);
            write_u64_decimal(out, seg.number as u64);
            out.push(0);
            if let Some(bval) = seg.bytes {
                write_u64_decimal(out, bval as u64);
            }
            out.push(0);
            out.extend(&f.seg_arena[seg.src_num_range.0..seg.src_num_range.1]);
            out.push(0);
            out.extend(&f.seg_arena[seg.src_bytes_range.0..seg.src_bytes_range.1]);
            out.push(0);
            out.extend(&f.seg_arena[seg.msg_id_range.0..seg.msg_id_range.1]);
        }
    }

    finish_cursor_min_ref(out, 16)
}

pub(crate) fn nzb_unfold_headers_into(
    input: &[u8],
    count: usize,
    out: &mut OutCursor<'_>,
) -> isize {
    if count == 0 {
        return 0;
    }
    let mut raw_header_bytes = 0usize;
    let mut ordered_names: Vec<&[u8]> = Vec::with_capacity(count);
    let mut values: Vec<(Vec<u8>, Vec<u8>)> = Vec::with_capacity(count);
    let mut previous_idx: Option<usize> = None;
    let mut seen_lines = 0usize;

    for raw_line in input.split(|&b| b == 0) {
        seen_lines += 1;
        raw_header_bytes += if raw_line.ends_with(b"\n") {
            raw_line.len()
        } else {
            raw_line.len() + 2
        };
        let mut content = raw_line;
        while let Some(&last) = content.last() {
            if last == b'\r' || last == b'\n' {
                content = &content[..content.len() - 1];
            } else {
                break;
            }
        }
        if matches!(content.first(), Some(b' ' | b'\t')) {
            if let Some(prev_idx) = previous_idx {
                let prev_val = &mut values[prev_idx].1;
                while let Some(&last) = prev_val.last() {
                    if is_php_trim_byte(last) {
                        prev_val.pop();
                    } else {
                        break;
                    }
                }
                prev_val.push(b' ');
                prev_val.extend_from_slice(php_trim(content));
            }
            continue;
        }
        let Some(colon) = content.iter().position(|&b| b == b':') else {
            continue;
        };
        let name_trimmed = php_trim(&content[..colon]);
        if name_trimmed.is_empty() {
            continue;
        }
        let mut norm_name = Vec::with_capacity(name_trimmed.len());
        for &b in name_trimmed {
            norm_name.push(b.to_ascii_lowercase());
        }
        ordered_names.push(name_trimmed);
        let val_trimmed = php_trim(&content[colon + 1..]);
        if let Some(idx) = values
            .iter()
            .position(|(k, _)| k.as_slice() == norm_name.as_slice())
        {
            previous_idx = Some(idx);
            values[idx].1.extend_from_slice(b", ");
            values[idx].1.extend_from_slice(val_trimmed);
        } else {
            let idx = values.len();
            previous_idx = Some(idx);
            values.push((norm_name, val_trimmed.to_vec()));
        }
    }

    if seen_lines != count {
        return -1;
    }

    let mut software: Vec<(&[u8], &[u8], &[u8])> = Vec::with_capacity(3);
    for &hdr_name in &[&b"user-agent"[..], b"x-newsreader", b"x-poster"] {
        let val: &[u8] = values
            .iter()
            .find(|(k, _)| k.as_slice() == hdr_name)
            .map(|(_, v)| v.as_slice())
            .unwrap_or(&[]);
        if val.is_empty() {
            continue;
        }
        for &(cand, cand_lower) in &[
            (&b"Nyuu"[..], &b"nyuu"[..]),
            (&b"ngPost"[..], &b"ngpost"[..]),
            (&b"Postie"[..], &b"postie"[..]),
        ] {
            if find_ascii_ci(val, cand_lower).is_some() {
                if let Some(entry) = software.iter_mut().find(|(c, _, _)| *c == cand) {
                    *entry = (cand, hdr_name, val);
                } else {
                    software.push((cand, hdr_name, val));
                }
            }
        }
    }

    write_usize_decimal(out, raw_header_bytes);
    out.push(0);
    write_usize_decimal(out, ordered_names.len());
    for &name in &ordered_names {
        out.push(0);
        out.extend(name);
    }
    out.push(0);
    write_usize_decimal(out, values.len());
    for (k, v) in &values {
        out.push(0);
        out.extend(k);
        out.push(0);
        out.extend(v);
    }
    out.push(0);
    write_usize_decimal(out, software.len());
    for &(cand, hdr, val) in &software {
        out.push(0);
        out.extend(cand);
        out.push(0);
        out.extend(hdr);
        out.push(0);
        out.extend(val);
    }

    finish_cursor_min_ref(out, 16)
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum YencControlKind {
    Ybegin,
    Ypart,
    Yend,
}

fn check_fallback_yenc_control_line(
    kind: YencControlKind,
    line_raw: &[u8],
    counts: &mut [usize; 3],
) -> bool {
    let k_idx = match kind {
        YencControlKind::Ybegin => 0,
        YencControlKind::Ypart => 1,
        YencControlKind::Yend => 2,
    };
    counts[k_idx] += 1;
    if counts[k_idx] > 1 || line_raw.len() > 16_384 {
        return true;
    }
    if (kind == YencControlKind::Ypart && (counts[0] != 1 || counts[2] != 0))
        || (kind == YencControlKind::Yend && counts[0] != 1)
    {
        return true;
    }
    let kw_len = match kind {
        YencControlKind::Ybegin => 6,
        YencControlKind::Ypart => 5,
        YencControlKind::Yend => 4,
    };
    let mut line = if line_raw.len() > kw_len + 1 {
        &line_raw[kw_len + 1..]
    } else {
        &[]
    };
    while line.ends_with(b"\r") {
        line = &line[..line.len() - 1];
    }

    let critical: &[&[u8]] = match kind {
        YencControlKind::Ybegin => &[b"name", b"line", b"size", b"part", b"total"],
        YencControlKind::Ypart => &[b"begin", b"end"],
        YencControlKind::Yend => &[b"size", b"part", b"total", b"pcrc32", b"crc32"],
    };

    let mut seen_critical: Vec<([u8; 8], usize, &[u8])> = Vec::with_capacity(6);
    let mut pos = 0usize;
    let mut field_count = 0usize;
    while pos < line.len() {
        let k_start = if pos == 0 && line[0].is_ascii_alphabetic() {
            0
        } else if matches!(line[pos], b' ' | b'\t')
            && line.get(pos + 1).is_some_and(|b| b.is_ascii_alphabetic())
        {
            pos + 1
        } else {
            pos += 1;
            continue;
        };
        let mut k_end = k_start + 1;
        while k_end < line.len()
            && (line[k_end].is_ascii_alphanumeric() || matches!(line[k_end], b'_' | b'-'))
        {
            k_end += 1;
        }
        if line.get(k_end) != Some(&b'=') {
            pos = k_end;
            continue;
        }
        field_count += 1;
        let k_len = k_end - k_start;
        if field_count > 64 || k_len > 128 {
            return true;
        }
        let v_start = k_end + 1;
        let mut v_end = v_start;
        while v_end < line.len() && !matches!(line[v_end], b' ' | b'\t') {
            v_end += 1;
        }
        let val = &line[v_start..v_end];

        if k_len <= 8 {
            let mut key_lower = [0u8; 8];
            for (dst, &src) in key_lower[..k_len].iter_mut().zip(&line[k_start..k_end]) {
                *dst = src.to_ascii_lowercase();
            }
            let name = &key_lower[..k_len];
            if kind == YencControlKind::Ybegin && name == b"name" {
                break;
            }
            if critical.contains(&name) {
                if let Some(&(_, _, prev_val)) = seen_critical
                    .iter()
                    .find(|&&(k, kl, _)| &k[..kl] == name)
                {
                    if prev_val != val {
                        return true;
                    }
                } else {
                    seen_critical.push((key_lower, k_len, val));
                }
            }
        }
        pos = v_end;
    }

    false
}

fn find_yenc_control_line(text: &[u8], control_lower: &[u8], offset: usize) -> Option<usize> {
    let clen = control_lower.len();
    if offset == 0
        && text.len() >= clen
        && text[..clen].eq_ignore_ascii_case(control_lower)
        && text
            .get(clen)
            .is_none_or(|&b| matches!(b, b' ' | b'\t' | b'\r' | b'\n'))
    {
        return Some(0);
    }
    let mut search_from = offset.saturating_sub(1);
    while search_from + 1 + clen <= text.len() {
        let rem = &text[search_from..];
        let nl_rel = unsafe {
            let p = memchr(rem.as_ptr(), b'\n' as i32, rem.len());
            if p.is_null() {
                return None;
            }
            p.offset_from_unsigned(rem.as_ptr())
        };
        let pos = search_from + nl_rel + 1;
        let after = pos + clen;
        if after <= text.len()
            && text[pos..after].eq_ignore_ascii_case(control_lower)
            && text
                .get(after)
                .is_none_or(|&b| matches!(b, b' ' | b'\t' | b'\r' | b'\n'))
        {
            return Some(pos);
        }
        search_from = pos;
    }
    None
}

fn parse_yenc_frame_fields(mut line: &[u8], with_name: bool) -> Vec<(Vec<u8>, Vec<u8>)> {
    while line.ends_with(b"\r") {
        line = &line[..line.len() - 1];
    }
    let mut fields: Vec<(Vec<u8>, Vec<u8>)> = Vec::with_capacity(8);
    let mut pos = 0usize;
    while pos < line.len() {
        let k_start = if pos == 0 && line[0].is_ascii_alphabetic() {
            0
        } else if matches!(line[pos], b' ' | b'\t')
            && line.get(pos + 1).is_some_and(|b| b.is_ascii_alphabetic())
        {
            pos + 1
        } else {
            pos += 1;
            continue;
        };
        let mut k_end = k_start + 1;
        while k_end < line.len()
            && (line[k_end].is_ascii_alphanumeric() || matches!(line[k_end], b'_' | b'-'))
        {
            k_end += 1;
        }
        if line.get(k_end) != Some(&b'=') {
            pos = k_end;
            continue;
        }
        let raw_key = &line[k_start..k_end];
        let mut key_lower = Vec::with_capacity(raw_key.len());
        for &b in raw_key {
            key_lower.push(b.to_ascii_lowercase());
        }
        let v_start = k_end + 1;
        if with_name && key_lower == b"name" {
            let val = line[v_start..].to_vec();
            if let Some(existing) = fields.iter_mut().find(|(k, _)| k == &key_lower) {
                existing.1 = val;
            } else {
                fields.push((key_lower, val));
            }
            break;
        }
        let mut v_end = v_start;
        while v_end < line.len() && !matches!(line[v_end], b' ' | b'\t') {
            v_end += 1;
        }
        let val = line[v_start..v_end].to_vec();
        if let Some(existing) = fields.iter_mut().find(|(k, _)| k == &key_lower) {
            existing.1 = val;
        } else {
            fields.push((key_lower, val));
        }
        pos = v_end;
    }
    fields
}

struct ParsedYencFrame {
    header: Vec<(Vec<u8>, Vec<u8>)>,
    part: Option<Vec<(Vec<u8>, Vec<u8>)>>,
    trailer: Vec<(Vec<u8>, Vec<u8>)>,
    payload_offset: usize,
    payload_length: usize,
}

fn parse_yenc_article_frame(text: &[u8]) -> Option<ParsedYencFrame> {
    let mut offset = 0usize;
    while let Some(begin) = find_yenc_control_line(text, b"=ybegin", offset) {
        let rem = &text[begin..];
        let line_end_rel = unsafe {
            let p = memchr(rem.as_ptr(), b'\n' as i32, rem.len());
            if p.is_null() {
                return None;
            }
            p.offset_from_unsigned(rem.as_ptr())
        };
        let line_end = begin + line_end_rel;
        let header = parse_yenc_frame_fields(&text[begin + 7..line_end], true);
        offset = line_end + 1;
        let has_line = header.iter().any(|(k, _)| k == b"line");
        let has_size = header.iter().any(|(k, _)| k == b"size");
        let has_name = header.iter().any(|(k, _)| k == b"name");
        if !has_line || !has_size || !has_name {
            continue;
        }

        let mut part = None;
        if text.len() >= offset + 7
            && text[offset..offset + 6].eq_ignore_ascii_case(b"=ypart")
            && matches!(text[offset + 6], b' ' | b'\t')
        {
            let prem = &text[offset..];
            let part_end_rel = unsafe {
                let p = memchr(prem.as_ptr(), b'\n' as i32, prem.len());
                if p.is_null() {
                    return None;
                }
                p.offset_from_unsigned(prem.as_ptr())
            };
            let part_end = offset + part_end_rel;
            part = Some(parse_yenc_frame_fields(&text[offset + 6..part_end], false));
            offset = part_end + 1;
        }

        let end = find_yenc_control_line(text, b"=yend", offset)?;
        if let Some(next_begin) = find_yenc_control_line(text, b"=ybegin", offset) {
            if next_begin < end {
                offset = next_begin;
                continue;
            }
        }

        let trem = &text[end..];
        let trailer_end = unsafe {
            let p = memchr(trem.as_ptr(), b'\n' as i32, trem.len());
            if p.is_null() {
                text.len()
            } else {
                end + p.offset_from_unsigned(trem.as_ptr())
            }
        };
        let trailer = parse_yenc_frame_fields(&text[end + 5..trailer_end], false);

        return Some(ParsedYencFrame {
            header,
            part,
            trailer,
            payload_offset: offset,
            payload_length: end - offset,
        });
    }
    None
}

pub(crate) fn nzb_analyze_article_body_into(
    body: &[u8],
    flags: u32,
    out: &mut OutCursor<'_>,
) -> isize {
    // Reserve 76-byte binary header (19 * u32 LE)
    for _ in 0..76 {
        out.push(0);
    }

    let body_len = body.len();
    let need_body_meta = (flags & 1) != 0;
    let need_framing_check = (flags & 2) != 0;
    let need_frame = (flags & 4) != 0;
    let need_decode = (flags & 8) != 0;

    let mut crlf_count = 0usize;
    let mut lf_count = 0usize;
    let mut bare_cr_count = 0usize;
    let mut nul_count = 0usize;
    let mut last_lf: Option<usize> = None;

    let mut framing_incomplete = body_len > 2_000_000;
    let mut framing_counts = [0usize; 3];
    let mut control_lines: Vec<(usize, usize)> = Vec::new();

    if need_body_meta {
        let mut nul_off = 0usize;
        while nul_off < body_len {
            let rem = &body[nul_off..];
            let p = unsafe { memchr(rem.as_ptr(), 0, rem.len()) };
            if p.is_null() {
                break;
            }
            nul_count += 1;
            let rel = unsafe { p.offset_from_unsigned(rem.as_ptr()) };
            nul_off += rel + 1;
        }
    }

    if need_body_meta || need_framing_check || need_frame {
        let mut offset = 0usize;
        while offset < body_len {
            let rem = &body[offset..];
            let nl_rel = unsafe {
                let p = memchr(rem.as_ptr(), b'\n' as i32, rem.len());
                if p.is_null() {
                    None
                } else {
                    Some(p.offset_from_unsigned(rem.as_ptr()))
                }
            };
            let end = match nl_rel {
                Some(rel) => {
                    let e = offset + rel;
                    lf_count += 1;
                    last_lf = Some(e);
                    if e > offset && body[e - 1] == b'\r' {
                        crlf_count += 1;
                    }
                    e
                }
                None => body_len,
            };
            let line_raw = &body[offset..end];
            if need_body_meta {
                let check_slice = if end < body_len && end > offset && body[end - 1] == b'\r' {
                    &body[offset..end - 1]
                } else {
                    line_raw
                };
                if !check_slice.is_empty() {
                    let mut cr_off = 0usize;
                    while cr_off < check_slice.len() {
                        let sub = &check_slice[cr_off..];
                        let p = unsafe { memchr(sub.as_ptr(), b'\r' as i32, sub.len()) };
                        if p.is_null() {
                            break;
                        }
                        bare_cr_count += 1;
                        let rel = unsafe { p.offset_from_unsigned(sub.as_ptr()) };
                        cr_off += rel + 1;
                    }
                }
            }

            if (need_framing_check || need_frame)
                && line_raw.first() == Some(&b'=')
                && line_raw.get(1).is_some_and(|&b| b == b'y' || b == b'Y')
            {
                let ctrl_kind_and_len = if line_raw.len() >= 7
                    && line_raw[..7].eq_ignore_ascii_case(b"=ybegin")
                {
                    Some((YencControlKind::Ybegin, 7usize))
                } else if line_raw.len() >= 6 && line_raw[..6].eq_ignore_ascii_case(b"=ypart") {
                    Some((YencControlKind::Ypart, 6usize))
                } else if line_raw.len() >= 5 && line_raw[..5].eq_ignore_ascii_case(b"=yend") {
                    Some((YencControlKind::Yend, 5usize))
                } else {
                    None
                };

                if let Some((kind, kw_len)) = ctrl_kind_and_len {
                    if need_frame {
                        let cr_pos = line_raw
                            .iter()
                            .position(|&b| b == b'\r')
                            .unwrap_or(line_raw.len());
                        control_lines.push((offset, offset + cr_pos));
                    }
                    if need_framing_check
                        && !framing_incomplete
                        && line_raw
                            .get(kw_len)
                            .is_none_or(|&b| matches!(b, b' ' | b'\t' | b'\r'))
                    {
                        if check_fallback_yenc_control_line(kind, line_raw, &mut framing_counts) {
                            framing_incomplete = true;
                        }
                    }
                }
            }

            offset = match nl_rel {
                Some(_) => end + 1,
                None => body_len,
            };
        }
    }

    if need_framing_check && (framing_counts[0] != 1 || framing_counts[2] != 1) {
        framing_incomplete = true;
    }

    let bare_lf_count = lf_count - crlf_count;
    let final_line_terminated = body.is_empty() || body.ends_with(b"\r\n");
    let unterminated_tail_byte_count = match last_lf {
        Some(idx) => body_len - idx - 1,
        None => body_len,
    };
    let observed_lf_delimited_line_count =
        lf_count + usize::from(!body.is_empty() && !body.ends_with(b"\n"));
    let body_status: u32 = if body.is_empty() {
        0 // empty
    } else if bare_lf_count > 0 || bare_cr_count > 0 {
        2 // non_crlf_line_endings
    } else if !final_line_terminated {
        3 // unterminated_final_line
    } else {
        1 // complete_crlf
    };

    let mut flags_out = 0u32;
    if final_line_terminated {
        flags_out |= 1;
    }
    if framing_incomplete {
        flags_out |= 2;
    }

    let mut payload_offset = 0u32;
    let mut payload_length = 0u32;
    let mut escape_sequence_count = 0u32;
    let mut payload_line_count = 0u32;
    let mut payload_line_min = 0u32;
    let mut payload_line_max = 0u32;
    let mut format_signatures_mask = 0u32;
    let mut decoded_crc32 = 0u32;
    let mut meta_section_len = 0u32;
    let mut decoded_bytes_len = 0u32;

    if need_frame {
        let meta_start = out.pos;
        let body_sha256 = sha256_fast_hex(body);
        out.extend(&body_sha256);

        if let Some(frame) = parse_yenc_article_frame(body) {
            flags_out |= 4; // has_frame
            if frame.part.is_some() {
                flags_out |= 8; // has_part
            }
            payload_offset = frame.payload_offset as u32;
            payload_length = frame.payload_length as u32;

            let payload =
                &body[frame.payload_offset..frame.payload_offset + frame.payload_length];
            let payload_sha256 = sha256_fast_hex(payload);
            out.push(b'\n');
            out.extend(&payload_sha256);
            out.push(b'\n');
            let decoded_sha_pos = out.pos;
            if need_decode {
                out.extend(&[b'0'; 64]);
            }

            // Emit `\n`-separated frame fields + control lines
            out.push(b'\n');
            write_usize_decimal(out, frame.header.len());
            for (k, v) in &frame.header {
                out.push(b'\n');
                out.extend(k);
                out.push(b'\n');
                out.extend(v);
            }
            if let Some(part_fields) = &frame.part {
                out.push(b'\n');
                write_usize_decimal(out, part_fields.len());
                for (k, v) in part_fields {
                    out.push(b'\n');
                    out.extend(k);
                    out.push(b'\n');
                    out.extend(v);
                }
            }
            out.push(b'\n');
            write_usize_decimal(out, frame.trailer.len());
            for (k, v) in &frame.trailer {
                out.push(b'\n');
                out.extend(k);
                out.push(b'\n');
                out.extend(v);
            }
            out.push(b'\n');
            write_usize_decimal(out, control_lines.len());
            for &(cs, ce) in &control_lines {
                out.push(b'\n');
                out.extend(&body[cs..ce]);
            }
            meta_section_len = (out.pos - meta_start) as u32;

            let mut trailing_eq = 0usize;
            for &b in payload.iter().rev() {
                if b == b'=' {
                    trailing_eq += 1;
                } else if b != b'\r' && b != b'\n' {
                    break;
                }
            }
            if (trailing_eq & 1) == 0 {
                flags_out |= 16; // payload_escape_state_valid
            }

            let mut trimmed_payload = payload;
            while let Some(&last) = trimmed_payload.last() {
                if last == b'\r' || last == b'\n' {
                    trimmed_payload = &trimmed_payload[..trimmed_payload.len() - 1];
                } else {
                    break;
                }
            }
            let mut p_min = u32::MAX;
            let mut p_max = 0u32;
            let mut p_off = 0usize;
            while p_off < trimmed_payload.len() {
                let rem = &trimmed_payload[p_off..];
                let nl_rel = unsafe {
                    let p = memchr(rem.as_ptr(), b'\n' as i32, rem.len());
                    if p.is_null() {
                        None
                    } else {
                        Some(p.offset_from_unsigned(rem.as_ptr()))
                    }
                };
                match nl_rel {
                    Some(rel) => {
                        let line_len = if rel > 0 && rem[rel - 1] == b'\r' {
                            rel - 1
                        } else {
                            rel
                        } as u32;
                        payload_line_count += 1;
                        p_min = p_min.min(line_len);
                        p_max = p_max.max(line_len);
                        p_off += rel + 1;
                    }
                    None => break,
                }
            }
            let tail_len = (trimmed_payload.len() - p_off) as u32;
            payload_line_count += 1;
            p_min = p_min.min(tail_len);
            p_max = p_max.max(tail_len);
            payload_line_min = p_min;
            payload_line_max = p_max;

            let decoded_start = out.pos;
            if need_decode {
                flags_out |= 32; // has_decoded_bytes
                let mut escaped = false;
                if out.pos.saturating_add(payload.len()) <= out.buf.len() {
                    let mut write_pos = out.pos;
                    let mut i = 0usize;
                    let plen = payload.len();
                    while i < plen {
                        if !escaped {
                            while i + 16 <= plen {
                                let chunk = &payload[i..i + 16];
                                if chunk
                                    .iter()
                                    .all(|&b| b != b'\r' && b != b'\n' && b != b'=')
                                {
                                    let dst = &mut out.buf[write_pos..write_pos + 16];
                                    for k in 0..16 {
                                        dst[k] = chunk[k].wrapping_sub(42);
                                    }
                                    write_pos += 16;
                                    i += 16;
                                } else {
                                    break;
                                }
                            }
                            while i < plen {
                                let b = payload[i];
                                if b != b'\r' && b != b'\n' && b != b'=' {
                                    out.buf[write_pos] = b.wrapping_sub(42);
                                    write_pos += 1;
                                    i += 1;
                                } else {
                                    break;
                                }
                            }
                            if i >= plen {
                                break;
                            }
                        }
                        let b = payload[i];
                        i += 1;
                        if b == b'\r' || b == b'\n' {
                            continue;
                        }
                        if b == b'=' {
                            escape_sequence_count += 1;
                        }
                        if escaped {
                            out.buf[write_pos] = b.wrapping_sub(106);
                            write_pos += 1;
                            escaped = false;
                        } else if b == b'=' {
                            escaped = true;
                        } else {
                            out.buf[write_pos] = b.wrapping_sub(42);
                            write_pos += 1;
                        }
                    }
                    out.pos = write_pos;
                } else {
                    for &b in payload {
                        if b == b'\r' || b == b'\n' {
                            continue;
                        }
                        if b == b'=' {
                            escape_sequence_count += 1;
                        }
                        if escaped {
                            out.push(b.wrapping_sub(106));
                            escaped = false;
                        } else if b == b'=' {
                            escaped = true;
                        } else {
                            out.push(b.wrapping_sub(42));
                        }
                    }
                }
                decoded_bytes_len = (out.pos - decoded_start) as u32;
                if out.pos <= out.buf.len() {
                    let decoded_slice = &out.buf[decoded_start..out.pos];
                    decoded_crc32 = crc32_ieee(decoded_slice);
                    let prefix_end = decoded_slice.len().min(65_536);
                    format_signatures_mask =
                        detect_format_signatures_mask(&decoded_slice[..prefix_end]);
                    let decoded_sha256 = sha256_fast_hex(decoded_slice);
                    if decoded_sha_pos + 64 <= out.buf.len() {
                        out.buf[decoded_sha_pos..decoded_sha_pos + 64]
                            .copy_from_slice(&decoded_sha256);
                    }
                }
            } else {
                for &b in payload {
                    if b == b'=' {
                        escape_sequence_count += 1;
                    }
                }
            }
        } else {
            meta_section_len = (out.pos - meta_start) as u32;
        }
    }

    if out.buf.len() >= 76 {
        let words: [u32; 19] = [
            body_status,
            crlf_count as u32,
            lf_count as u32,
            bare_lf_count as u32,
            bare_cr_count as u32,
            nul_count as u32,
            observed_lf_delimited_line_count as u32,
            unterminated_tail_byte_count as u32,
            flags_out,
            payload_offset,
            payload_length,
            escape_sequence_count,
            payload_line_count,
            payload_line_min,
            payload_line_max,
            format_signatures_mask,
            decoded_crc32,
            meta_section_len,
            decoded_bytes_len,
        ];
        for (idx, w) in words.iter().enumerate() {
            out.buf[idx * 4..idx * 4 + 4].copy_from_slice(&w.to_le_bytes());
        }
    }

    if out.pos <= out.buf.len() {
        out.pos as isize
    } else {
        -(out.pos.max(80) as isize)
    }
}

pub(crate) fn nzb_select_article_samples_into(
    input: &[u8],
    file_count: usize,
    max_samples: usize,
    out: &mut OutCursor<'_>,
) -> isize {
    let maximum_samples = max_samples.clamp(1, 128);
    let mut tokens = input.split(|&b| b == 0);
    let mut seen_keys: Vec<(usize, &[u8])> = Vec::with_capacity(maximum_samples * 2);
    let mut samples: Vec<(&[u8], &[u8], &[u8], &[u8], &[u8], &[u8], &[u8])> =
        Vec::with_capacity(maximum_samples);
    let mut omitted_count = 0usize;

    for _ in 0..file_count {
        let Some(file_idx_raw) = tokens.next() else {
            return -1;
        };
        let Some(file_idx_num) = nzb_metadata_number(file_idx_raw) else {
            return -1;
        };
        let file_idx = file_idx_num as usize;
        let Some(kind) = tokens.next() else {
            return -1;
        };
        let Some(subject) = tokens.next() else {
            return -1;
        };
        let Some(seg_count_raw) = tokens.next() else {
            return -1;
        };
        let Some(seg_count_i64) = nzb_metadata_number(seg_count_raw) else {
            return -1;
        };
        let seg_count = seg_count_i64 as usize;

        if seg_count == 0 {
            continue;
        }

        if kind == b"par2" {
            for _ in 0..seg_count {
                let Some(seg_num) = tokens.next() else {
                    return -1;
                };
                let Some(seg_bytes) = tokens.next() else {
                    return -1;
                };
                let Some(msg_id) = tokens.next() else {
                    return -1;
                };
                if msg_id.is_empty() || seen_keys.contains(&(file_idx, msg_id)) {
                    continue;
                }
                seen_keys.push((file_idx, msg_id));
                if samples.len() >= maximum_samples {
                    omitted_count += 1;
                } else {
                    samples.push((
                        file_idx_raw,
                        kind,
                        subject,
                        seg_num,
                        msg_id,
                        seg_bytes,
                        b"all_par2_segments",
                    ));
                }
            }
        } else {
            let last = seg_count - 1;
            let mid = if last > 1 { Some(last / 2) } else { None };
            let mut first_seg = None;
            let mut mid_seg = None;
            let mut last_seg = None;
            for s_idx in 0..seg_count {
                let Some(seg_num) = tokens.next() else {
                    return -1;
                };
                let Some(seg_bytes) = tokens.next() else {
                    return -1;
                };
                let Some(msg_id) = tokens.next() else {
                    return -1;
                };
                if s_idx == 0 {
                    first_seg = Some((seg_num, seg_bytes, msg_id));
                }
                if mid == Some(s_idx) {
                    mid_seg = Some((seg_num, seg_bytes, msg_id));
                }
                if last > 0 && s_idx == last {
                    last_seg = Some((seg_num, seg_bytes, msg_id));
                }
            }

            let mut candidates: [(Option<(&[u8], &[u8], &[u8])>, &[u8]); 3] = [
                (first_seg, b"first"),
                (mid_seg, b"middle"),
                (last_seg, b"last"),
            ];
            for (cand_opt, role) in &mut candidates {
                let Some((seg_num, seg_bytes, msg_id)) = *cand_opt else {
                    continue;
                };
                if msg_id.is_empty() || seen_keys.contains(&(file_idx, msg_id)) {
                    continue;
                }
                seen_keys.push((file_idx, msg_id));
                if samples.len() >= maximum_samples {
                    omitted_count += 1;
                } else {
                    samples.push((file_idx_raw, kind, subject, seg_num, msg_id, seg_bytes, role));
                }
            }
        }
    }

    write_usize_decimal(out, omitted_count);
    out.push(0);
    write_usize_decimal(out, samples.len());
    for &(f_idx, kind, subj, s_num, m_id, s_bytes, role) in &samples {
        out.push(0);
        out.extend(f_idx);
        out.push(0);
        out.extend(kind);
        out.push(0);
        out.extend(subj);
        out.push(0);
        out.extend(s_num);
        out.push(0);
        out.extend(m_id);
        out.push(0);
        out.extend(s_bytes);
        out.push(0);
        out.extend(role);
    }

    finish_cursor_min_ref(out, 16)
}

/// Classifies an NZB file subject into `0` (`"other"`), `1` (`"rar"`), or `2` (`"par2"`).
///
/// # Safety
///
/// `subject` must be valid for reads of `subject_len` bytes when non-null and `subject_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_file_kind(subject: *const u8, subject_len: usize) -> i32 {
    if subject.is_null() && subject_len > 0 {
        return -1;
    }
    let bytes = if subject_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(subject, subject_len) }
    };
    i32::from(nzb_file_kind_code(bytes))
}

/// Extracts `NzbFingerprintAnalyzer::formatHint` into `out_buf`.
/// Returns `1..=8` on match, `0` when `"unknown"`, `-1` on null pointers, or `-8` if `out_cap < 8`.
///
/// # Safety
///
/// `subject` must be valid for reads of `subject_len` bytes when non-null and `subject_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_format_hint(
    subject: *const u8,
    subject_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if (subject.is_null() && subject_len > 0) || (out_buf.is_null() && out_cap > 0) {
        return -1;
    }
    if out_cap < 8 {
        return -8;
    }
    let bytes = if subject_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(subject, subject_len) }
    };
    let mut hint = [0u8; 8];
    let len = nzb_format_hint_slice(bytes, &mut hint);
    if len > 0 {
        unsafe {
            std::ptr::copy_nonoverlapping(hint.as_ptr(), out_buf, len);
        }
    }
    len as isize
}

/// Detects format signatures on a decoded article prefix (`NzbFingerprintAnalyzer::detectFormatSignatures`).
///
/// # Safety
///
/// `bytes` must be valid for reads of `len` bytes when non-null and `len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_detect_format_signatures(
    bytes: *const u8,
    len: usize,
) -> i32 {
    if bytes.is_null() && len > 0 {
        return -1;
    }
    let slice = if len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(bytes, len) }
    };
    detect_format_signatures_mask(slice) as i32
}

/// Single-pass NZB XML manifest analyzer (`NzbFingerprintAnalyzer::analyzeManifest`).
///
/// # Safety
///
/// `xml` must be valid for reads of `xml_len` bytes when non-null and `xml_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_analyze_manifest(
    xml: *const u8,
    xml_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if xml_len == 0 || xml.is_null() || (out_buf.is_null() && out_cap > 0) {
        return -1;
    }
    let src = unsafe { slice::from_raw_parts(xml, xml_len) };
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    nzb_analyze_manifest_into(src, &mut cursor)
}

/// Unfolds RFC 5322 article headers and detects uploader software candidates.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_unfold_headers(
    input: *const u8,
    input_len: usize,
    count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if count == 0 {
        return 0;
    }
    if (input.is_null() && input_len > 0) || (out_buf.is_null() && out_cap > 0) {
        return -1;
    }
    let src = if input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    nzb_unfold_headers_into(src, count, &mut cursor)
}

/// Analyzes NNTP article body framing, yEnc control lines, payload line statistics, and optional yEnc payload decoding.
///
/// # Safety
///
/// `body` must be valid for reads of `body_len` bytes when non-null and `body_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_analyze_article_body(
    body: *const u8,
    body_len: usize,
    flags: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if (body.is_null() && body_len > 0) || (out_buf.is_null() && out_cap > 0) {
        return -1;
    }
    let src = if body_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(body, body_len) }
    };
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    nzb_analyze_article_body_into(src, flags, &mut cursor)
}

/// Selects bounded article samples from packed NZB manifest file records (`NzbFingerprintAnalyzer::selectArticleSamples`).
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_select_article_samples(
    input: *const u8,
    input_len: usize,
    file_count: usize,
    max_samples: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if (input.is_null() && input_len > 0) || (out_buf.is_null() && out_cap > 0) {
        return -1;
    }
    let src = if input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    nzb_select_article_samples_into(src, file_count, max_samples, &mut cursor)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_cp437_box_drawing_and_clean_content() {
        // 0xDA (┌), 0xC4 (─), 0xBF (┐)
        let raw = b"\xDA\xC4\xBF   \r\n\r\n\r\n\r\nRelease: Test.Movie.2026.1080p.BluRay.x264-GRP  \t \rLine 2";
        let mut buf = [0u8; 256];
        let mut out = unsafe { OutCursor::new(buf.as_mut_ptr(), buf.len()) };
        clean_nfo_content_into(raw, &mut out);
        let len = finish_cursor(out) as usize;
        let cleaned = std::str::from_utf8(&buf[..len]).unwrap();
        assert_eq!(
            cleaned,
            "┌─┐\n\n\nRelease: Test.Movie.2026.1080p.BluRay.x264-GRP\nLine 2"
        );
    }

    #[test]
    fn test_identity_text_excludes_source_and_continuations() {
        let nfo = b"Source :\r\n    C:\\incoming\\Example.Show.S01E02.1080p.HDTV.x264-SOURCE.mkv\r\n    Filename : D:\\output\\Example.Show.S01E02.1080p.HDTV.x264-TARGET.mkv\n";
        let mut buf = [0u8; 256];
        let mut out = unsafe { OutCursor::new(buf.as_mut_ptr(), buf.len()) };
        nfo_identity_text_into(nfo, &mut out);
        let len = finish_cursor(out) as usize;
        let text = std::str::from_utf8(&buf[..len]).unwrap();
        assert_eq!(
            text,
            "\n\n    Filename : D:\\output\\Example.Show.S01E02.1080p.HDTV.x264-TARGET.mkv\n"
        );
    }

    #[test]
    fn test_validate_and_preconditions() {
        let valid_nfo = b"==================================================\nRelease: Test.Movie.2026.1080p.BluRay.x264-GRP\nDate: 2026-10-23\nVideo: x264 1080p\nAudio: DTS\nhttps://www.imdb.com/title/tt1234567/";
        assert_eq!(validate_nfo_content(valid_nfo), 1);
        assert_eq!(matches_non_nfo_header(valid_nfo), false);
        assert_eq!(detect_binary_format(valid_nfo), false);
        assert_eq!(has_binary_control_chars(valid_nfo), false);

        let sfv = b"; Generated by QuickSFV v2.36\r\ntest.rar 12345678\r\n";
        assert!(matches_non_nfo_header(sfv));
    }

    #[test]
    fn test_nfo_predb_candidates_and_pattern_match() {
        let nfo = b"Source: Fake.Show.S01E01.1080p.WEB-DL.x264-FAKE\r\nRelease Name: Real.Show.S01E01.1080p.WEB-DL.DDP5.1.H.264-REAL\r\n";
        let mut buf = [0u8; 512];
        let mut out = unsafe { OutCursor::new(buf.as_mut_ptr(), buf.len()) };
        let len = nfo_predb_candidates_into(nfo, &mut out);
        assert!(len > 0);
        let cands: Vec<&str> = buf[..len as usize]
            .split(|&b| b == 0)
            .map(|s| std::str::from_utf8(s).unwrap())
            .collect();
        assert_eq!(
            cands,
            vec!["Real.Show.S01E01.1080p.WEB-DL.DDP5.1.H.264-REAL"]
        );

        let mut out2 = unsafe { OutCursor::new(buf.as_mut_ptr(), buf.len()) };
        let len2 = nfo_extract_pattern_mode_into(nfo, 0, &mut out2);
        assert!(len2 > 0);
        assert_eq!(
            &buf[..len2 as usize],
            b"Real.Show.S01E01.1080p.WEB-DL.DDP5.1.H.264-REAL\0Complete literal NFO field"
        );
    }

    #[test]
    fn test_filename_extract_candidate() {
        let mut buf = [0u8; 512];
        let mut out = unsafe { OutCursor::new(buf.as_mut_ptr(), buf.len()) };
        let len = filename_extract_candidate_into(
            b"The.West.Wing.S07E01.The.Ticket.1080p.BluRay.10bit.EAC3.2.0.x265-iVy.mkv",
            &mut out,
        );
        assert!(len > 0);
        assert_eq!(
            &buf[..len as usize],
            b"The.West.Wing.S07E01.The.Ticket.1080p.BluRay.10bit.EAC3.2.0.x265-iVy\0Complete literal basename"
        );

        let mut out2 = unsafe { OutCursor::new(buf.as_mut_ptr(), buf.len()) };
        let len2 = filename_extract_candidate_into(
            b"Ordinary World (2016) 1080p\\Ordinary World (2016) 1080p.mkv",
            &mut out2,
        );
        assert!(len2 > 0);
        assert_eq!(
            &buf[..len2 as usize],
            b"Ordinary World (2016) 1080p\0Matching video parent basename"
        );
    }

    #[test]
    fn test_nzb_file_kind_and_format_hint() {
        assert_eq!(
            nzb_file_kind_code(b"\"Release.vol01+02.PAR2\" yEnc (1/1)"),
            2
        );
        assert_eq!(nzb_file_kind_code(b"\"Release.part01.rar\" yEnc (1/5)"), 1);
        assert_eq!(nzb_file_kind_code(b"\"Release.r00\" yEnc (1/5)"), 1);
        assert_eq!(nzb_file_kind_code(b"\"Release.s99\" yEnc (1/5)"), 0);
        assert_eq!(nzb_file_kind_code(b"\"Release.001\" yEnc (1/5)"), 0);
        assert_eq!(nzb_file_kind_code(b"\"Release.nfo\" yEnc (1/1)"), 0);

        let mut h1 = [0u8; 8];
        let l1 = nzb_format_hint_slice(b"\"Release.7z.001\" yEnc (1/1)", &mut h1);
        assert_eq!(&h1[..l1], b"7z");
        let mut h2 = [0u8; 8];
        let l2 = nzb_format_hint_slice(b"\"Release_r12.RAR\" yEnc (1/1)", &mut h2);
        assert_eq!(&h2[..l2], b"rar");
        let mut h3 = [0u8; 8];
        let l3 = nzb_format_hint_slice(b"no extension at all", &mut h3);
        assert_eq!(l3, 0);
    }

    #[test]
    fn test_nzb_detect_format_signatures() {
        assert_eq!(detect_format_signatures_mask(b"Rar!\x1a\x07\x01\x00extra"), 1);
        assert_eq!(detect_format_signatures_mask(b"Rar!\x1a\x07\x00"), 2);
        assert_eq!(detect_format_signatures_mask(b"7z\xbc\xaf\x27\x1c"), 4);
        assert_eq!(detect_format_signatures_mask(b"PK\x03\x04"), 8);
        assert_eq!(detect_format_signatures_mask(b"\x1f\x8b\x08"), 16);
        assert_eq!(detect_format_signatures_mask(b"\xfd7zXZ\x00"), 32);
        let mut iso = vec![0u8; 0x8006];
        iso[0x8001..0x8006].copy_from_slice(b"CD001");
        assert_eq!(detect_format_signatures_mask(&iso), 64);
    }

    #[test]
    fn test_nzb_analyze_manifest_and_samples() {
        let xml = br#"<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd">
<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
  <head>
    <meta type="title">Example &amp; Release</meta>
    <meta type="category">TV &gt; HD</meta>
  </head>
  <file poster="poster@example.com" date="1700000000" subject="&quot;Example.part01.rar&quot; yEnc (1/2)">
    <groups>
      <group>alt.binaries.test</group>
    </groups>
    <segments>
      <segment bytes="200" number="2">&lt;seg2@example.com&gt;</segment>
      <segment bytes="100" number="1">seg1@example.com</segment>
    </segments>
  </file>
</nzb>"#;
        let mut buf = vec![0u8; 4096];
        let mut out = unsafe { OutCursor::new(buf.as_mut_ptr(), buf.len()) };
        let written = nzb_analyze_manifest_into(xml, &mut out);
        assert!(written > 0);
        let tokens: Vec<&str> = buf[..written as usize]
            .split(|&b| b == 0)
            .map(|s| std::str::from_utf8(s).unwrap())
            .collect();
        assert_eq!(tokens[0], "2"); // 2 meta types
        assert_eq!(tokens[1], "title");
        assert_eq!(tokens[2], "1");
        assert_eq!(tokens[3], "Example & Release");
    }

    #[test]
    fn test_nzb_unfold_headers_and_article_body() {
        let headers = b"Subject: \"Example.rar\"\0\t yEnc (1/1)\0X-Newsreader: Nyuu/0.4.1\0From: tester@example.com";
        let mut buf = vec![0u8; 2048];
        let mut out = unsafe { OutCursor::new(buf.as_mut_ptr(), buf.len()) };
        let written = nzb_unfold_headers_into(headers, 4, &mut out);
        assert!(written > 0);

        let body = b"=ybegin line=128 size=4 name=test.bin\r\n*+,-\r\n=yend size=4 crc32=9b0d08f1\r\n";
        let mut body_buf = vec![0u8; 2048];
        let mut body_out = unsafe { OutCursor::new(body_buf.as_mut_ptr(), body_buf.len()) };
        let body_written = nzb_analyze_article_body_into(body, 0x0F, &mut body_out);
        assert!(body_written >= 76);
        let body_status = u32::from_le_bytes(body_buf[0..4].try_into().unwrap());
        assert_eq!(body_status, 1); // complete_crlf
        let decoded_len = u32::from_le_bytes(body_buf[72..76].try_into().unwrap()) as usize;
        assert_eq!(decoded_len, 4);
        let meta_len = u32::from_le_bytes(body_buf[68..72].try_into().unwrap()) as usize;
        assert_eq!(&body_buf[76 + meta_len..76 + meta_len + 4], &[0, 1, 2, 3]);

        for len in [0usize, 1, 3, 55, 56, 63, 64, 65, 128, 1024] {
            let sample: Vec<u8> = (0..len).map(|i| (i * 31 + 7) as u8).collect();
            assert_eq!(
                sha256_fast_hex(&sample),
                crate::predb::sha256_hex_slice(&sample),
                "sha256 mismatch at len={len}"
            );
        }
        assert_eq!(crc32_ieee(b"123456789"), 0xCBF4_3926);
    }
}
