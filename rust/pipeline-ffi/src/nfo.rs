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
}
