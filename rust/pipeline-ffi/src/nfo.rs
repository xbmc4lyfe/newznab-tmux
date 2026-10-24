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
        let pos = find_yenc_line_prefix(text, search_from)? + 1;
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

struct LocatedYencFrame {
    header: Vec<(Vec<u8>, Vec<u8>)>,
    part: Option<Vec<(Vec<u8>, Vec<u8>)>>,
    // [begin, header LF, part LF or usize::MAX, payload, trailer, trailer LF/EOF]
    spans: [usize; 6],
}

/// Find a line-start equals sign in bulk. Checking adjacent vectors avoids a
/// libc call for every ordinary payload line, while keeping every load inside
/// the input allocation (the second load is shifted by one readable byte).
fn find_yenc_line_prefix(text: &[u8], offset: usize) -> Option<usize> {
    let mut cursor = offset;
    #[cfg(target_arch = "aarch64")]
    unsafe {
        use std::arch::aarch64::*;
        let newline = vdupq_n_u8(b'\n');
        let equals = vdupq_n_u8(b'=');
        while text.len() - cursor >= 17 {
            let left = vld1q_u8(text.as_ptr().add(cursor));
            let right = vld1q_u8(text.as_ptr().add(cursor + 1));
            let matches = vandq_u8(vceqq_u8(left, newline), vceqq_u8(right, equals));
            if vmaxvq_u8(matches) != 0 {
                for lane in 0..16 {
                    if text[cursor + lane] == b'\n' && text[cursor + lane + 1] == b'=' {
                        return Some(cursor + lane);
                    }
                }
            }
            cursor += 16;
        }
    }
    #[cfg(target_arch = "x86_64")]
    unsafe {
        use std::arch::x86_64::*;
        // SSE2 is part of the x86_64 baseline; no runtime CPU dispatch is needed.
        let newline = _mm_set1_epi8(b'\n' as i8);
        let equals = _mm_set1_epi8(b'=' as i8);
        while text.len() - cursor >= 17 {
            let left = _mm_loadu_si128(text.as_ptr().add(cursor).cast());
            let right = _mm_loadu_si128(text.as_ptr().add(cursor + 1).cast());
            let matches =
                _mm_and_si128(_mm_cmpeq_epi8(left, newline), _mm_cmpeq_epi8(right, equals));
            let mask = _mm_movemask_epi8(matches) as u32;
            if mask != 0 {
                return Some(cursor + mask.trailing_zeros() as usize);
            }
            cursor += 16;
        }
    }
    while cursor + 1 < text.len() {
        if text[cursor] == b'\n' && text[cursor + 1] == b'=' {
            return Some(cursor);
        }
        cursor += 1;
    }
    None
}

/// Search both competing controls in one pass; scanning for a second header after
/// finding the trailer would otherwise revisit the complete encoded payload.
fn next_yenc_frame_control(text: &[u8], offset: usize) -> Option<(usize, bool)> {
    let mut cursor = offset.saturating_sub(1);
    while cursor < text.len() {
        let position = find_yenc_line_prefix(text, cursor)? + 1;
        cursor = position;
        let tail = &text[position..];
        if !tail.starts_with(b"=") || !tail.get(1).is_some_and(|b| b.eq_ignore_ascii_case(&b'y')) {
            continue;
        }
        for (control, is_begin) in [(b"=yend".as_slice(), false), (b"=ybegin".as_slice(), true)] {
            if tail.len() >= control.len()
                && tail[..control.len()].eq_ignore_ascii_case(control)
                && tail
                    .get(control.len())
                    .is_none_or(|b| matches!(b, b' ' | b'\t' | b'\r' | b'\n'))
            {
                return Some((position, is_begin));
            }
        }
    }
    None
}

fn yenc_line_end(text: &[u8], offset: usize) -> Option<usize> {
    let rem = &text[offset..];
    let newline = unsafe { memchr(rem.as_ptr(), b'\n' as i32, rem.len()) };
    if newline.is_null() {
        None
    } else {
        Some(offset + unsafe { newline.offset_from_unsigned(rem.as_ptr()) })
    }
}

/// Recognize the PHP field grammar without allocating keys or values. `name`
/// consumes the remainder, so required fields appearing inside it do not count.
fn yenc_header_has_required_fields(mut line: &[u8]) -> bool {
    while line.ends_with(b"\r") {
        line = &line[..line.len() - 1];
    }
    let mut required = 0u8;
    let mut pos = 0;
    while pos < line.len() {
        let start = if pos == 0 && line[0].is_ascii_alphabetic() {
            0
        } else if matches!(line[pos], b' ' | b'\t')
            && line.get(pos + 1).is_some_and(u8::is_ascii_alphabetic)
        {
            pos + 1
        } else {
            pos += 1;
            continue;
        };
        let mut end = start + 1;
        while end < line.len()
            && (line[end].is_ascii_alphanumeric() || matches!(line[end], b'_' | b'-'))
        {
            end += 1;
        }
        if line.get(end) != Some(&b'=') {
            pos = end;
            continue;
        }
        let key = &line[start..end];
        if key.eq_ignore_ascii_case(b"name") {
            return required == 3;
        }
        if key.eq_ignore_ascii_case(b"line") {
            required |= 1;
        } else if key.eq_ignore_ascii_case(b"size") {
            required |= 2;
        }
        pos = end + 1;
        while pos < line.len() && !matches!(line[pos], b' ' | b'\t') {
            pos += 1;
        }
    }
    false
}

fn locate_yenc_article_frame(text: &[u8], with_fields: bool) -> Option<LocatedYencFrame> {
    let mut offset = 0usize;
    while let Some(begin) = find_yenc_control_line(text, b"=ybegin", offset) {
        let line_end = yenc_line_end(text, begin)?;
        let header_line = &text[begin + 7..line_end];
        offset = line_end + 1;
        if !yenc_header_has_required_fields(header_line) {
            continue;
        }
        let header = if with_fields {
            parse_yenc_frame_fields(header_line, true)
        } else {
            Vec::new()
        };
        let mut part = None;
        let mut part_end = usize::MAX;
        if text
            .get(offset..offset.saturating_add(7))
            .is_some_and(|prefix| {
                prefix[..6].eq_ignore_ascii_case(b"=ypart") && matches!(prefix[6], b' ' | b'\t')
            })
        {
            part_end = yenc_line_end(text, offset)?;
            if with_fields {
                part = Some(parse_yenc_frame_fields(&text[offset + 6..part_end], false));
            }
            offset = part_end + 1;
        }
        let (end, is_begin) = next_yenc_frame_control(text, offset)?;
        if is_begin {
            offset = end;
            continue;
        }
        return Some(LocatedYencFrame {
            header,
            part,
            spans: [
                begin,
                line_end,
                part_end,
                offset,
                end,
                yenc_line_end(text, end).unwrap_or(text.len()),
            ],
        });
    }
    None
}

fn parse_yenc_article_frame(text: &[u8]) -> Option<ParsedYencFrame> {
    let frame = locate_yenc_article_frame(text, true)?;
    Some(ParsedYencFrame {
        header: frame.header,
        part: frame.part,
        trailer: parse_yenc_frame_fields(&text[frame.spans[4] + 5..frame.spans[5]], false),
        payload_offset: frame.spans[3],
        payload_length: frame.spans[4] - frame.spans[3],
    })
}

fn yenc_valid_range(address: usize, length: usize) -> bool {
    address != 0 && length <= isize::MAX as usize && address.checked_add(length).is_some()
}

fn yenc_ranges_overlap(a: usize, a_len: usize, b: usize, b_len: usize) -> bool {
    a_len != 0 && b_len != 0 && a < b + b_len && b < a + a_len
}

/// Encode exactly the PHP YencService wire format. Input and filename remain
/// read-only and must not overlap the caller-owned output. A null output with
/// zero capacity is a supported required-capacity query. Returns -1 for invalid
/// arguments, or the negated required capacity when the output is too small.
///
/// # Safety
/// Non-null pointers must address readable/writable allocations of the supplied
/// lengths, respectively, for the duration of this call.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_yenc_encode(
    input_ptr: *const u8,
    input_len: usize,
    filename_ptr: *const u8,
    filename_len: usize,
    line_len: usize,
    crc32_flag: u8,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if line_len == 0
        || !yenc_valid_range(input_ptr as usize, input_len)
        || !yenc_valid_range(filename_ptr as usize, filename_len)
        || (out_cap != 0 && !yenc_valid_range(out_ptr as usize, out_cap))
        || input_len
            .checked_mul(4)
            .and_then(|n| n.checked_add(filename_len))
            .and_then(|n| n.checked_add(128))
            .is_none_or(|n| n > isize::MAX as usize)
        || yenc_ranges_overlap(input_ptr as usize, input_len, out_ptr as usize, out_cap)
        || yenc_ranges_overlap(
            filename_ptr as usize,
            filename_len,
            out_ptr as usize,
            out_cap,
        )
    {
        return -1;
    }
    let input = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    let filename = unsafe { slice::from_raw_parts(filename_ptr, filename_len) };
    if filename.iter().any(|b| matches!(b, b'\r' | b'\n')) {
        return -1;
    }
    let line_len = line_len.min(254);
    let mut out = unsafe { OutCursor::new(out_ptr, out_cap) };
    out.extend(b"=ybegin line=");
    write_usize_decimal(&mut out, line_len);
    out.extend(b" size=");
    write_usize_decimal(&mut out, input_len);
    out.extend(b" name=");
    out.extend(php_trim(filename));
    out.extend(b"\r\n");
    let mut column = 0usize;
    for &raw in input {
        if column >= line_len {
            out.extend(b"\r\n");
            column = 0;
        }
        let byte = raw.wrapping_add(42);
        if matches!(byte, 0 | 9 | 10 | 13 | 32 | 46 | 61) {
            out.push(b'=');
            out.push(byte.wrapping_add(64));
            column += 2;
        } else {
            out.push(byte);
            column += 1;
        }
    }
    out.extend(b"\r\n=yend size=");
    write_usize_decimal(&mut out, input_len);
    if crc32_flag != 0 {
        out.extend(b" crc32=");
        let crc = crc32_ieee(input);
        for shift in (0..8).rev() {
            out.push(b"0123456789abcdef"[((crc >> (shift * 4)) & 15) as usize]);
        }
    }
    out.finish()
}

/// Locate a frame without copying its payload. Six output words contain the
/// begin control offset, header LF, part LF (usize::MAX when absent), payload
/// start, trailer control offset, and trailer LF (or EOF). Returns 1/0/-1 for
/// frame/no frame/invalid pointers, and leaves output untouched on 0 or -1.
///
/// # Safety
/// Input must be readable for input_len bytes and out_spans must address six
/// writable, aligned usize values in a disjoint allocation.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_yenc_frame_spans(
    input_ptr: *const u8,
    input_len: usize,
    out_spans: *mut usize,
) -> i32 {
    let out_len = 6 * size_of::<usize>();
    if !yenc_valid_range(input_ptr as usize, input_len)
        || !yenc_valid_range(out_spans as usize, out_len)
        || !out_spans.is_aligned()
        || yenc_ranges_overlap(input_ptr as usize, input_len, out_spans as usize, out_len)
    {
        return -1;
    }
    let input = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    let Some(frame) = locate_yenc_article_frame(input, false) else {
        return 0;
    };
    unsafe {
        std::ptr::copy_nonoverlapping(frame.spans.as_ptr(), out_spans, 6);
    }
    1
}

/// Visit raw matches of ArticleFrame::fields. Duplicate keys are deliberately
/// retained: PHP unserialize applies the same last-value/first-position array
/// semantics, avoiding an intermediate owned map or quadratic deduplication.
fn visit_yenc_frame_fields<'a>(
    mut line: &'a [u8],
    with_name: bool,
    mut visit: impl FnMut(&'a [u8], &'a [u8]),
) -> usize {
    while line.ends_with(b"\r") {
        line = &line[..line.len() - 1];
    }
    let mut count = 0;
    let mut pos = 0;
    while pos < line.len() {
        let start = if pos == 0 && line[0].is_ascii_alphabetic() {
            0
        } else if matches!(line[pos], b' ' | b'\t')
            && line.get(pos + 1).is_some_and(u8::is_ascii_alphabetic)
        {
            pos + 1
        } else {
            pos += 1;
            continue;
        };
        let mut end = start + 1;
        while end < line.len()
            && (line[end].is_ascii_alphanumeric() || matches!(line[end], b'_' | b'-'))
        {
            end += 1;
        }
        if line.get(end) != Some(&b'=') {
            pos = end;
            continue;
        }
        let key = &line[start..end];
        let value_start = end + 1;
        count += 1;
        if with_name && key.eq_ignore_ascii_case(b"name") {
            visit(key, &line[value_start..]);
            break;
        }
        let mut value_end = value_start;
        while value_end < line.len() && !matches!(line[value_end], b' ' | b'\t') {
            value_end += 1;
        }
        visit(key, &line[value_start..value_end]);
        pos = value_end;
    }
    count
}

fn write_yenc_serialized_string(out: &mut OutCursor<'_>, bytes: &[u8], lowercase: bool) {
    out.extend(b"s:");
    write_usize_decimal(out, bytes.len());
    out.extend(b":\"");
    if lowercase {
        for &byte in bytes {
            out.push(byte.to_ascii_lowercase());
        }
    } else {
        out.extend(bytes);
    }
    out.extend(b"\";");
}

fn write_yenc_serialized_key(out: &mut OutCursor<'_>, key: &[u8]) {
    // Canonical lowercase control keys are fixed byte strings. Unusual names
    // and case variations retain the complete normalization path.
    let encoded: &[u8] = match key {
        b"line" => b"s:4:\"line\";",
        b"size" => b"s:4:\"size\";",
        b"name" => b"s:4:\"name\";",
        b"part" => b"s:4:\"part\";",
        b"total" => b"s:5:\"total\";",
        b"begin" => b"s:5:\"begin\";",
        b"end" => b"s:3:\"end\";",
        b"crc32" => b"s:5:\"crc32\";",
        b"pcrc32" => b"s:6:\"pcrc32\";",
        _ => {
            write_yenc_serialized_string(out, key, true);
            return;
        }
    };
    out.extend(encoded);
}

fn write_yenc_serialized_fields(out: &mut OutCursor<'_>, line: &[u8], with_name: bool) {
    let mut small = [(&[][..], &[][..]); 8];
    let mut count = 0;
    visit_yenc_frame_fields(line, with_name, |key, value| {
        if count < small.len() {
            small[count] = (key, value);
        }
        count += 1;
    });
    out.extend(b"a:");
    write_usize_decimal(out, count);
    out.extend(b":{");
    let mut write_field = |key: &[u8], value: &[u8]| {
        write_yenc_serialized_key(out, key);
        write_yenc_serialized_string(out, value, false);
    };
    if count <= small.len() {
        for &(key, value) in &small[..count] {
            write_field(key, value);
        }
    } else {
        // Large or duplicate-rich metadata still has linear work and no heap
        // allocation; the usual small map requires only one grammar scan.
        visit_yenc_frame_fields(line, with_name, &mut write_field);
    }
    out.push(b'}');
}

/// Return metadata only as a PHP serialized App\Services\Yenc\ArticleFrame
/// object with header, part, trailer, payloadOffset and payloadLength. String byte
/// lengths protect binary metadata, including NULs and serialization syntax.
/// No payload bytes are copied, and no owned maps are built. A zero trailer flag
/// emits an empty trailer array. Returns 0 without touching output for no frame,
/// -1 for invalid arguments, or the negative required capacity when too small.
///
/// # Safety
/// Input and output must address readable/writable allocations of their supplied
/// lengths and must be disjoint. Null output is valid only with zero capacity.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_yenc_frame_record(
    input_ptr: *const u8,
    input_len: usize,
    parse_trailer_fields: u8,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if !yenc_valid_range(input_ptr as usize, input_len)
        || (out_cap != 0 && !yenc_valid_range(out_ptr as usize, out_cap))
        || input_len
            .checked_mul(64)
            .and_then(|length| length.checked_add(256))
            .is_none_or(|length| length > isize::MAX as usize)
        || yenc_ranges_overlap(input_ptr as usize, input_len, out_ptr as usize, out_cap)
    {
        return -1;
    }
    let input = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    let Some(frame) = locate_yenc_article_frame(input, false) else {
        return 0;
    };
    let [
        begin,
        header_end,
        part_end,
        payload_start,
        trailer_start,
        trailer_end,
    ] = frame.spans;
    let mut out = unsafe { OutCursor::new(out_ptr, out_cap) };
    let class = b"App\\Services\\Yenc\\ArticleFrame";
    out.extend(b"O:");
    write_usize_decimal(&mut out, class.len());
    out.extend(b":\"");
    out.extend(class);
    out.extend(b"\":5:{s:6:\"header\";");
    write_yenc_serialized_fields(&mut out, &input[begin + 7..header_end], true);
    out.extend(b"s:4:\"part\";");
    if part_end == usize::MAX {
        out.extend(b"N;");
    } else {
        write_yenc_serialized_fields(&mut out, &input[header_end + 7..part_end], false);
    }
    out.extend(b"s:7:\"trailer\";");
    if parse_trailer_fields != 0 {
        write_yenc_serialized_fields(&mut out, &input[trailer_start + 5..trailer_end], false);
    } else {
        out.extend(b"a:0:{}");
    }
    out.extend(b"s:13:\"payloadOffset\";i:");
    write_usize_decimal(&mut out, payload_start);
    out.extend(b";s:13:\"payloadLength\";i:");
    write_usize_decimal(&mut out, trailer_start - payload_start);
    out.extend(b";}");
    out.finish()
}

fn yenc_field<'a>(fields: &'a [(Vec<u8>, Vec<u8>)], key: &[u8]) -> Option<&'a [u8]> {
    fields
        .iter()
        .find(|(k, _)| k == key)
        .map(|(_, value)| value.as_slice())
}

/// Metadata is advisory: prefer a valid trailer size, then a valid 1-based part
/// range, then a valid header size; use -1 when none is available. Multipart
/// frames select pcrc32, others crc32. Invalid/missing CRC metadata has length 0;
/// valid 1..8 hex digits are lowercase and left-padded to eight bytes. Metadata
/// errors never reject an otherwise complete frame.
///
/// # Safety
/// All outputs must point to writable, aligned values (CRC: eight bytes), be
/// pairwise disjoint, and not overlap the readable input allocation.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_yenc_parse_frame(
    input_ptr: *const u8,
    input_len: usize,
    out_payload_start: *mut usize,
    out_payload_len: *mut usize,
    out_expected_size: *mut i64,
    out_expected_crc: *mut u8,
    out_crc_len: *mut usize,
) -> i32 {
    let outputs = [
        (out_payload_start as usize, size_of::<usize>()),
        (out_payload_len as usize, size_of::<usize>()),
        (out_expected_size as usize, size_of::<i64>()),
        (out_expected_crc as usize, 8),
        (out_crc_len as usize, size_of::<usize>()),
    ];
    if !yenc_valid_range(input_ptr as usize, input_len)
        || !out_payload_start.is_aligned()
        || !out_payload_len.is_aligned()
        || !out_expected_size.is_aligned()
        || !out_crc_len.is_aligned()
        || outputs
            .iter()
            .any(|&(address, length)| !yenc_valid_range(address, length))
    {
        return -1;
    }
    for (i, &(address, length)) in outputs.iter().enumerate() {
        if yenc_ranges_overlap(input_ptr as usize, input_len, address, length)
            || outputs[..i]
                .iter()
                .any(|&(other, other_len)| yenc_ranges_overlap(address, length, other, other_len))
        {
            return -1;
        }
    }
    let input = unsafe { slice::from_raw_parts(input_ptr, input_len) };
    let Some(frame) = parse_yenc_article_frame(input) else {
        return 0;
    };
    let number = |fields: &[(Vec<u8>, Vec<u8>)], key: &[u8]| {
        yenc_field(fields, key).and_then(nzb_metadata_number)
    };
    let part_size = frame.part.as_ref().and_then(|part| {
        let begin = number(part, b"begin")?;
        let end = number(part, b"end")?;
        (begin >= 1 && end >= begin).then(|| end - begin + 1)
    });
    let size = number(&frame.trailer, b"size")
        .or(part_size)
        .or_else(|| number(&frame.header, b"size"))
        .unwrap_or(-1);
    let multipart = frame.part.is_some()
        || yenc_field(&frame.header, b"part").is_some()
        || yenc_field(&frame.trailer, b"part").is_some();
    let mut crc = [0u8; 8];
    let mut crc_len = 0;
    if let Some(raw) = yenc_field(&frame.trailer, if multipart { b"pcrc32" } else { b"crc32" }) {
        if !raw.is_empty() && raw.len() <= 8 && raw.iter().all(u8::is_ascii_hexdigit) {
            crc.fill(b'0');
            for (target, source) in crc[8 - raw.len()..].iter_mut().zip(raw) {
                *target = source.to_ascii_lowercase();
            }
            crc_len = 8;
        }
    }
    unsafe {
        out_payload_start.write(frame.payload_offset);
        out_payload_len.write(frame.payload_length);
        out_expected_size.write(size);
        std::ptr::copy_nonoverlapping(crc.as_ptr(), out_expected_crc, 8);
        out_crc_len.write(crc_len);
    }
    1
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

#[cfg(test)]
mod yenc_ffi_tests {
    use super::*;

    fn encode(data: &[u8], filename: &[u8], line: usize, crc: bool) -> Vec<u8> {
        let required = unsafe {
            nntmux_yenc_encode(
                data.as_ptr(),
                data.len(),
                filename.as_ptr(),
                filename.len(),
                line,
                u8::from(crc),
                std::ptr::null_mut(),
                0,
            )
        };
        assert!(required < -1);
        let mut output = vec![0; (-required) as usize];
        let written = unsafe {
            nntmux_yenc_encode(
                data.as_ptr(),
                data.len(),
                filename.as_ptr(),
                filename.len(),
                line,
                u8::from(crc),
                output.as_mut_ptr(),
                output.len(),
            )
        };
        assert_eq!(written, output.len() as isize);
        output
    }

    fn parse(data: &[u8]) -> (i32, usize, usize, i64, [u8; 8], usize) {
        let (mut start, mut length, mut size, mut crc, mut crc_len) = (99, 99, 99, [b'?'; 8], 99);
        let status = unsafe {
            nntmux_yenc_parse_frame(
                data.as_ptr(),
                data.len(),
                &mut start,
                &mut length,
                &mut size,
                crc.as_mut_ptr(),
                &mut crc_len,
            )
        };
        (status, start, length, size, crc, crc_len)
    }

    #[test]
    fn encoder_exact_php_wire_format_and_trim() {
        assert_eq!(
            encode(b"", b" \t\0\x0bname\0 ", usize::MAX, true),
            b"=ybegin line=254 size=0 name=name\r\n\r\n=yend size=0 crc32=00000000"
        );
        assert_eq!(
            encode(b"123456789", b"test.bin", 128, true),
            b"=ybegin line=128 size=9 name=test.bin\r\n[\\]^_`abc\r\n=yend size=9 crc32=cbf43926"
        );
        // Each special transformed byte is escaped, including dots away from
        // line starts; an escape can cross the line limit before the next byte.
        let input: Vec<u8> = [0u8, 9, 10, 13, 32, 46, 61]
            .iter()
            .map(|b| b.wrapping_sub(42))
            .collect();
        assert_eq!(
            encode(&input, b"x", 3, false),
            b"=ybegin line=3 size=7 name=x\r\n=@=I\r\n=J=M\r\n=`=n\r\n=}\r\n=yend size=7"
        );
        assert_eq!(
            encode(b"\x00\x01", b"x", 1, false),
            b"=ybegin line=1 size=2 name=x\r\n*\r\n+\r\n=yend size=2"
        );
    }

    #[test]
    fn encoder_buffers_and_invalid_arguments() {
        let mut output = [0x7f; 3];
        let data = b"data";
        let filename = b"x";
        let result = unsafe {
            nntmux_yenc_encode(
                data.as_ptr(),
                data.len(),
                filename.as_ptr(),
                1,
                128,
                0,
                output.as_mut_ptr(),
                output.len(),
            )
        };
        assert_eq!((-result) as usize, encode(data, filename, 128, false).len());
        for bad_filename in [b"x\r".as_slice(), b"x\n".as_slice()] {
            assert_eq!(
                unsafe {
                    nntmux_yenc_encode(
                        data.as_ptr(),
                        4,
                        bad_filename.as_ptr(),
                        bad_filename.len(),
                        128,
                        1,
                        output.as_mut_ptr(),
                        3,
                    )
                },
                -1
            );
        }
        assert_eq!(
            unsafe {
                nntmux_yenc_encode(
                    data.as_ptr(),
                    4,
                    filename.as_ptr(),
                    1,
                    0,
                    0,
                    output.as_mut_ptr(),
                    3,
                )
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_yenc_encode(
                    std::ptr::null(),
                    0,
                    filename.as_ptr(),
                    1,
                    128,
                    0,
                    output.as_mut_ptr(),
                    3,
                )
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_yenc_encode(
                    data.as_ptr(),
                    4,
                    filename.as_ptr(),
                    1,
                    128,
                    0,
                    std::ptr::null_mut(),
                    3,
                )
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_yenc_encode(
                    output.as_ptr(),
                    3,
                    filename.as_ptr(),
                    1,
                    128,
                    0,
                    output.as_mut_ptr(),
                    3,
                )
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_yenc_encode(
                    data.as_ptr(),
                    usize::MAX,
                    filename.as_ptr(),
                    1,
                    128,
                    0,
                    output.as_mut_ptr(),
                    3,
                )
            },
            -1
        );
    }

    fn record(input: &[u8], trailer: bool) -> Vec<u8> {
        let needed = unsafe {
            nntmux_yenc_frame_record(
                input.as_ptr(),
                input.len(),
                u8::from(trailer),
                std::ptr::null_mut(),
                0,
            )
        };
        assert!(needed < -1);
        let mut output = vec![0; (-needed) as usize];
        assert_eq!(
            unsafe {
                nntmux_yenc_frame_record(
                    input.as_ptr(),
                    input.len(),
                    u8::from(trailer),
                    output.as_mut_ptr(),
                    output.len(),
                )
            },
            output.len() as isize
        );
        output
    }

    #[test]
    fn record_streams_duplicate_and_binary_metadata_with_exact_byte_lengths() {
        let article = b"=ybegin LINE=1 size=3 line=2 name=x\0\xff\r\r\n=ypart begin=1 end=3 BEGIN=2\r\n*+-\r\n=yend size=3 crc32=A crc32=B\r\r";
        let parsed = parse_yenc_article_frame(article).unwrap();
        let mut expected = b"O:30:\"App\\Services\\Yenc\\ArticleFrame\":5:{s:6:\"header\";a:4:{s:4:\"line\";s:1:\"1\";s:4:\"size\";s:1:\"3\";s:4:\"line\";s:1:\"2\";s:4:\"name\";s:3:\"x\0\xff\";}s:4:\"part\";a:3:{s:5:\"begin\";s:1:\"1\";s:3:\"end\";s:1:\"3\";s:5:\"begin\";s:1:\"2\";}s:7:\"trailer\";a:3:{s:4:\"size\";s:1:\"3\";s:5:\"crc32\";s:1:\"A\";s:5:\"crc32\";s:1:\"B\";}".to_vec();
        expected.extend(
            format!(
                "s:13:\"payloadOffset\";i:{};s:13:\"payloadLength\";i:{};}}",
                parsed.payload_offset, parsed.payload_length
            )
            .as_bytes(),
        );
        assert_eq!(record(article, true), expected);
        let without_trailer = record(article, false);
        assert!(
            without_trailer
                .windows(b"s:7:\"trailer\";a:0:{}s:13:\"payloadOffset\";".len())
                .any(|window| window == b"s:7:\"trailer\";a:0:{}s:13:\"payloadOffset\";")
        );
        let plain = record(
            b"=ybegin line=1 size=0 name=\";}i:99;N;\0\n\n=yend size=0",
            true,
        );
        assert!(
            plain
                .windows(b"s:4:\"part\";N;s:7:\"trailer\";".len())
                .any(|window| window == b"s:4:\"part\";N;s:7:\"trailer\";")
        );
    }

    #[test]
    fn record_small_stack_and_large_fallback_match_generic_serialization() {
        for line in [
            b"".as_slice(),
            b"line=128 size=4 name=x",
            b"part=1 total=2 line=128 size=4 name=x",
            b"line=1 size=0 a=1 b=2 c=3 d=4 e=5 f=6 g=7 name=\0\xff",
            b"crc32=A pcrc32=B CRC32=C begin=1 end=2 strange-key=v",
        ] {
            for with_name in [false, true] {
                let mut expected = [0; 2048];
                let mut actual = [0; 2048];
                let mut reference =
                    unsafe { OutCursor::new(expected.as_mut_ptr(), expected.len()) };
                reference.extend(b"a:");
                write_usize_decimal(
                    &mut reference,
                    visit_yenc_frame_fields(line, with_name, |_, _| {}),
                );
                reference.extend(b":{");
                visit_yenc_frame_fields(line, with_name, |key, value| {
                    write_yenc_serialized_string(&mut reference, key, true);
                    write_yenc_serialized_string(&mut reference, value, false);
                });
                reference.push(b'}');
                let expected_len = reference.finish() as usize;
                let mut output = unsafe { OutCursor::new(actual.as_mut_ptr(), actual.len()) };
                write_yenc_serialized_fields(&mut output, line, with_name);
                let actual_len = output.finish() as usize;
                assert_eq!(&actual[..actual_len], &expected[..expected_len]);
            }
        }
    }

    #[test]
    fn record_buffer_no_frame_and_invalid_pointer_contracts() {
        let article = b"=ybegin line=128 size=1 name=x\n*\n=yend size=1";
        let expected = record(article, true);
        let mut output = [0x7f; 3];
        assert_eq!(
            unsafe {
                nntmux_yenc_frame_record(
                    article.as_ptr(),
                    article.len(),
                    1,
                    output.as_mut_ptr(),
                    output.len(),
                )
            },
            -(expected.len() as isize)
        );
        output.fill(0x7f);
        assert_eq!(
            unsafe {
                nntmux_yenc_frame_record(b"plain text".as_ptr(), 10, 1, output.as_mut_ptr(), 3)
            },
            0
        );
        assert_eq!(output, [0x7f; 3]);
        assert_eq!(
            unsafe { nntmux_yenc_frame_record(std::ptr::null(), 0, 1, output.as_mut_ptr(), 3) },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_yenc_frame_record(
                    article.as_ptr(),
                    article.len(),
                    1,
                    std::ptr::null_mut(),
                    3,
                )
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_yenc_frame_record(article.as_ptr(), usize::MAX, 1, output.as_mut_ptr(), 3)
            },
            -1
        );
        assert_eq!(
            unsafe { nntmux_yenc_frame_record(output.as_ptr(), 3, 1, output.as_mut_ptr(), 3) },
            -1
        );
    }

    #[test]
    fn initial_control_search_matches_scalar_with_dense_newlines() {
        let cases: &[&[u8]] = &[
            b"",
            b"\n\n\n",
            b"=ybegin",
            b"=YBEGIN ",
            b"=ybeginx\n=yend",
            b"prefix\n=YBEGIN\tline=128",
            b"\n\n=ypart\n=ybeginx\n=yBEGIN\r\n=yend\n",
        ];
        let mut dense = b"ordinary text\n=not a control\n\n".repeat(256);
        dense.extend(b"=ybeginx\n=yendx\n=YBEGIN size=0 line=128 name=x\n\n=yend size=0");
        for text in cases
            .iter()
            .copied()
            .chain(std::iter::once(dense.as_slice()))
        {
            for control in [b"=ybegin".as_slice(), b"=yend"] {
                for offset in 0..=text.len() {
                    let expected = (offset..text.len()).find(|&pos| {
                        (pos == 0 || text[pos - 1] == b'\n')
                            && text
                                .get(pos..pos + control.len())
                                .is_some_and(|candidate| candidate.eq_ignore_ascii_case(control))
                            && text
                                .get(pos + control.len())
                                .is_none_or(|b| matches!(b, b' ' | b'\t' | b'\r' | b'\n'))
                    });
                    assert_eq!(
                        find_yenc_control_line(text, control, offset),
                        expected,
                        "offset={offset} control={control:?}"
                    );
                }
            }
        }
        assert_eq!(parse(&b"ordinary text\n".repeat(1024)).0, 0);
    }

    #[test]
    fn vector_line_prefix_matches_scalar_at_all_boundaries() {
        for length in 0..160 {
            for prefix in 0..length {
                let mut storage = vec![b'x'; length + 32];
                for alignment in 0..16 {
                    storage.fill(b'x');
                    let text = &mut storage[alignment..alignment + length];
                    text[prefix] = b'\n';
                    if prefix + 1 < length {
                        text[prefix + 1] = b'=';
                    }
                    for offset in [
                        0,
                        prefix.saturating_sub(1),
                        prefix,
                        (prefix + 1).min(length),
                        length,
                    ] {
                        let expected = text[offset..]
                            .windows(2)
                            .position(|pair| pair == b"\n=")
                            .map(|position| offset + position);
                        assert_eq!(
                            find_yenc_line_prefix(text, offset),
                            expected,
                            "len={length} prefix={prefix} alignment={alignment} offset={offset}"
                        );
                    }
                }
            }
        }
        for length in 0..160 {
            let text: Vec<u8> = (0..length)
                .map(|i| [b'\n', b'x', b'=', b'\n', b'='][i % 5])
                .collect();
            for offset in 0..=length {
                let expected = text[offset..]
                    .windows(2)
                    .position(|pair| pair == b"\n=")
                    .map(|position| offset + position);
                assert_eq!(find_yenc_line_prefix(&text, offset), expected);
            }
        }
    }

    #[test]
    fn allocation_free_header_validation_matches_full_fields_parser() {
        let chunks: &[&[u8]] = &[
            b"line=128",
            b"size=",
            b"name=x size=4",
            b"LiNe=",
            b"SIZE=23",
            b"NAME=",
            b"bad",
            b"!name=x",
            b"x=",
            b"\t",
            b" ",
            b"\r",
            b"\0",
            b"a-b_4=5",
            b"name\n=x",
        ];
        let mut state = 0x9e3779b9u32;
        for _ in 0..4096 {
            let mut line = Vec::new();
            for _ in 0..12 {
                state = state.wrapping_mul(1664525).wrapping_add(1013904223);
                line.extend(chunks[state as usize % chunks.len()]);
            }
            let fields = parse_yenc_frame_fields(&line, true);
            let expected = [b"line".as_slice(), b"size", b"name"]
                .iter()
                .all(|key| fields.iter().any(|(k, _)| k == key));
            assert_eq!(yenc_header_has_required_fields(&line), expected, "{line:?}");
        }
    }

    #[test]
    fn parser_offsets_spans_case_and_nested_begin() {
        let article = b"preamble\n=ybegin line=128 size=99 name=old\nignored\n=YBEGIN line=128 size=4 name=test.bin\r\n=YPART begin=3 end=6\r\n*+,-\r\n=YEND size=4 pcrc32=ABc\npostamble";
        let (status, start, length, size, crc, crc_len) = parse(article);
        assert_eq!(status, 1);
        assert_eq!(&article[start..start + length], b"*+,-\r\n");
        assert_eq!((size, crc, crc_len), (4, *b"00000abc", 8));
        let mut spans = [0; 6];
        assert_eq!(
            unsafe { nntmux_yenc_frame_spans(article.as_ptr(), article.len(), spans.as_mut_ptr()) },
            1
        );
        assert_eq!(
            &article[spans[0]..spans[1]],
            b"=YBEGIN line=128 size=4 name=test.bin\r"
        );
        assert_eq!(&article[spans[1] + 1..spans[2]], b"=YPART begin=3 end=6\r");
        assert_eq!(spans[3], start);
        assert_eq!(spans[4] - spans[3], length);
        assert_eq!(&article[spans[4]..spans[5]], b"=YEND size=4 pcrc32=ABc");
    }

    #[test]
    fn parser_metadata_is_advisory_and_crc_selection_is_multipart_aware() {
        let (status, _, _, size, crc, crc_len) = parse(b"=ybegin line=0 size=999 name=x\n=ypart begin=4 end=8\npayload\n=yend size=bad crc32=ffffffff pcrc32=G");
        assert_eq!((status, size, crc, crc_len), (1, 5, [0; 8], 0));
        assert_eq!(
            parse(b"=ybegin line=bad size=007 name=x\npayload\n=yend crc32=F").3,
            7
        );
        assert_eq!(
            parse(b"=ybegin line=bad size=999999999999999999999 name=x\npayload\n=yend size=-1").3,
            -1
        );
        assert_eq!(
            parse(b"=ybegin line=128 size=3 part=1 name=x\npayload\n=yend crc32=f").5,
            0
        );
        assert_eq!(
            parse(b"=ybegin line=128 size=3 name=x\npayload\n=yend part=1 crc32=f pcrc32=Ab").4,
            *b"000000ab"
        );
        let article = b"=ybegin size=3 line=128 name=x\npayload\n=yend size=0 crc32=f";
        assert_eq!(parse(article).3, 0);
        let mut spans = [0; 6];
        assert_eq!(
            unsafe { nntmux_yenc_frame_spans(article.as_ptr(), article.len(), spans.as_mut_ptr()) },
            1
        );
        assert_eq!(spans[2], usize::MAX);
        assert_eq!(spans[5], article.len());
    }

    #[test]
    fn parser_no_frame_invalid_boundaries_and_invalid_pointers() {
        for data in [
            b"".as_slice(),
            b"=ybegin line=128 size=1 name=x",
            b"=ybegin line=128 name=x\n*=yend size=1",
            b"=ybeginx line=128 size=1 name=x\n*\n=yend size=1",
            b"=ybegin line=128 size=1 name=x\n=ypart begin=1 end=1",
            b"=ybegin line=128 size=1 name=x\n*\n=yendx size=1",
        ] {
            assert_eq!(parse(data), (0, 99, 99, 99, [b'?'; 8], 99));
        }
        let article = b"=ybegin line=128 size=1 name=x\n*\n=yend size=1";
        let mut spans = [7; 6];
        assert_eq!(
            unsafe { nntmux_yenc_frame_spans(std::ptr::null(), 0, spans.as_mut_ptr()) },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_yenc_frame_spans(article.as_ptr(), article.len(), std::ptr::null_mut())
            },
            -1
        );
        assert_eq!(spans, [7; 6]);
        let (mut start, mut length, mut size, mut crc, mut crc_len) = (0, 0, 0, [0; 8], 0);
        assert_eq!(
            unsafe {
                nntmux_yenc_parse_frame(
                    article.as_ptr(),
                    article.len(),
                    &mut start,
                    &mut length,
                    &mut size,
                    std::ptr::null_mut(),
                    &mut crc_len,
                )
            },
            -1
        );
        assert_eq!(
            unsafe {
                nntmux_yenc_parse_frame(
                    article.as_ptr(),
                    article.len(),
                    &mut start,
                    &mut length,
                    &mut size,
                    crc.as_mut_ptr(),
                    &mut start,
                )
            },
            -1
        );
        assert_eq!(
            unsafe { nntmux_yenc_frame_spans(article.as_ptr(), usize::MAX, spans.as_mut_ptr()) },
            -1
        );
    }
}

// Wave 8 metadata candidates. PHP already requires PCRE2; these candidates reuse
// only symbols exported by the current process. They never load a shared library
// or add a system dependency. A standalone host without these symbols declines
// to PHP. The caller also guards PHP's PCRE limits/JIT/locale defaults.
mod nfo_metadata_pcre {
    use std::ffi::{c_char, c_void};
    use std::sync::OnceLock;

    unsafe extern "C" {
        fn dlsym(handle: *mut c_void, symbol: *const c_char) -> *mut c_void;
    }
    type Compile = unsafe extern "C" fn(
        *const u8,
        usize,
        u32,
        *mut i32,
        *mut usize,
        *mut c_void,
    ) -> *mut c_void;
    type Match = unsafe extern "C" fn(
        *const c_void,
        *const u8,
        usize,
        usize,
        u32,
        *mut c_void,
        *mut c_void,
    ) -> i32;
    type Create = unsafe extern "C" fn(u32, *mut c_void) -> *mut c_void;
    type Free = unsafe extern "C" fn(*mut c_void);
    type Ovector = unsafe extern "C" fn(*mut c_void) -> *const usize;
    type Jit = unsafe extern "C" fn(*mut c_void, u32) -> i32;
    type Context = unsafe extern "C" fn(*mut c_void) -> *mut c_void;
    type Limit = unsafe extern "C" fn(*mut c_void, u32) -> i32;
    type StackCreate = unsafe extern "C" fn(usize, usize, *mut c_void) -> *mut c_void;
    type StackAssign = unsafe extern "C" fn(*mut c_void, *mut c_void, *mut c_void);
    pub(super) struct Api {
        compile: Compile,
        run: Match,
        create: Create,
        free: Free,
        ovector: Ovector,
        jit: Jit,
        context: Context,
        context_free: Free,
        match_limit: Limit,
        depth_limit: Limit,
        stack_create: StackCreate,
        stack_assign: StackAssign,
        stack_free: Free,
        code_free: Free,
    }
    fn api() -> Option<&'static Api> {
        static API: OnceLock<Option<Api>> = OnceLock::new();
        API.get_or_init(|| {
            #[cfg(target_os = "macos")]
            let handle = (-2isize) as *mut c_void; // RTLD_DEFAULT
            #[cfg(not(target_os = "macos"))]
            let handle = std::ptr::null_mut(); // RTLD_DEFAULT on Linux
            macro_rules! symbol {
                ($name:literal, $ty:ty) => {{
                    let ptr = unsafe { dlsym(handle, concat!($name, "\0").as_ptr().cast()) };
                    if ptr.is_null() {
                        return None;
                    }
                    unsafe { std::mem::transmute::<*mut c_void, $ty>(ptr) }
                }};
            }
            Some(Api {
                compile: symbol!("pcre2_compile_8", Compile),
                run: symbol!("pcre2_match_8", Match),
                create: symbol!("pcre2_match_data_create_8", Create),
                free: symbol!("pcre2_match_data_free_8", Free),
                ovector: symbol!("pcre2_get_ovector_pointer_8", Ovector),
                jit: symbol!("pcre2_jit_compile_8", Jit),
                context: symbol!("pcre2_match_context_create_8", Context),
                context_free: symbol!("pcre2_match_context_free_8", Free),
                match_limit: symbol!("pcre2_set_match_limit_8", Limit),
                depth_limit: symbol!("pcre2_set_depth_limit_8", Limit),
                stack_create: symbol!("pcre2_jit_stack_create_8", StackCreate),
                stack_assign: symbol!("pcre2_jit_stack_assign_8", StackAssign),
                stack_free: symbol!("pcre2_jit_stack_free_8", Free),
                code_free: symbol!("pcre2_code_free_8", Free),
            })
        })
        .as_ref()
    }
    pub(super) struct Matcher {
        api: &'static Api,
        codes: &'static [usize],
        data: *mut c_void,
        context: *mut c_void,
        stack: *mut c_void,
        match_failed: bool,
        evidence: Option<Evidence>,
    }
    struct Evidence {
        text: usize,
        len: usize,
        first: Vec<Option<[usize; 8]>>,
        all: Vec<Vec<[usize; 8]>>,
        counts: Vec<usize>,
    }
    impl Matcher {
        pub(super) fn new() -> Option<Self> {
            let api = api()?;
            static CODES: OnceLock<Option<Vec<usize>>> = OnceLock::new();
            let codes = CODES
                .get_or_init(|| {
                    let mut codes = Vec::with_capacity(super::NFO_METADATA_PATTERNS.len());
                    for &(pattern, options) in super::NFO_METADATA_PATTERNS {
                        let (mut error, mut offset) = (0, 0);
                        let code = unsafe {
                            (api.compile)(
                                pattern.as_ptr(),
                                pattern.len(),
                                options,
                                &mut error,
                                &mut offset,
                                std::ptr::null_mut(),
                            )
                        };
                        if code.is_null() {
                            for code in codes {
                                unsafe {
                                    (api.code_free)(code as *mut c_void);
                                }
                            }
                            return None;
                        }
                        // The fixed code objects live for the process lifetime. JIT is
                        // optional in PCRE2 builds; interpretation remains correct.
                        unsafe {
                            (api.jit)(code, 1);
                        }
                        codes.push(code as usize);
                    }
                    Some(codes)
                })
                .as_ref()?;
            let data = unsafe { (api.create)(32, std::ptr::null_mut()) };
            if data.is_null() {
                return None;
            }
            let context = unsafe { (api.context)(std::ptr::null_mut()) };
            if context.is_null() {
                unsafe {
                    (api.free)(data);
                }
                return None;
            }
            // Match PHP's PCRE_JIT_STACK_MIN/MAX_SIZE (ext/pcre/php_pcre.c).
            // PCRE2's unassigned default is only 32 KiB and can reject matches
            // that PHP accepts with its expandable 192 KiB stack.
            let stack = unsafe { (api.stack_create)(32 * 1024, 192 * 1024, std::ptr::null_mut()) };
            if stack.is_null() {
                unsafe {
                    (api.context_free)(context);
                    (api.free)(data);
                }
                return None;
            }
            unsafe {
                (api.stack_assign)(context, std::ptr::null_mut(), stack);
                (api.match_limit)(context, 1_000_000);
                (api.depth_limit)(context, 100_000);
            }
            Some(Self {
                api,
                codes,
                data,
                context,
                stack,
                match_failed: false,
                evidence: None,
            })
        }
        pub(super) fn captures<'a>(
            &mut self,
            id: usize,
            text: &'a [u8],
            offset: usize,
        ) -> Result<Option<Captures<'a>>, ()> {
            if let Some(evidence) = &self.evidence {
                if evidence.text == text.as_ptr() as usize
                    && evidence.len == text.len()
                    && offset == 0
                {
                    return Ok(evidence.first[id].map(|offsets| Captures { text, offsets }));
                }
                return Err(());
            }
            self.captures_at(id, text, offset, 0)
        }
        fn captures_at<'a>(
            &mut self,
            id: usize,
            text: &'a [u8],
            offset: usize,
            options: u32,
        ) -> Result<Option<Captures<'a>>, ()> {
            self.match_failed = false;
            let rc = unsafe {
                (self.api.run)(
                    self.codes[id] as *const c_void,
                    text.as_ptr(),
                    text.len(),
                    offset,
                    options,
                    self.data,
                    self.context,
                )
            };
            if rc == -1 {
                return Ok(None);
            }
            // PHP's callers use preg_match(_all) as a condition. Resource-limit
            // errors are false, so the next pattern is still tried. Keep this
            // separate from malformed/internal engine failures, which decline.
            if matches!(rc, -46 | -47 | -53 | -63) {
                self.match_failed = true;
                return Ok(None);
            }
            if rc <= 0 || rc > 4 {
                return Err(());
            }
            let offsets = unsafe {
                std::slice::from_raw_parts((self.api.ovector)(self.data), rc as usize * 2)
            };
            let mut copied = [usize::MAX; 8];
            copied[..offsets.len()].copy_from_slice(offsets);
            Ok(Some(Captures {
                text,
                offsets: copied,
            }))
        }
        pub(super) fn prepare_quality(&mut self, text: &[u8]) -> Result<(usize, usize, usize), ()> {
            self.evidence = None;
            const IDS: &[usize] = &[
                0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 24,
                25, 26, 27, 28, 29, 30, 39, 40, 42, 43, 44, 54, 55, 56, 57, 58, 59, 60, 61, 62,
            ];
            static DISPATCH: OnceLock<[Vec<usize>; 256]> = OnceLock::new();
            let dispatch = DISPATCH.get_or_init(|| {
                std::array::from_fn(|byte| {
                    IDS.iter()
                        .copied()
                        .filter(|id| super::nfo_quality_first_byte(*id, byte as u8))
                        .collect()
                })
            });
            let mut evidence = Evidence {
                text: text.as_ptr() as usize,
                len: text.len(),
                first: vec![None; 63],
                all: (0..63).map(|_| Vec::new()).collect(),
                counts: vec![0; 63],
            };
            let mut next = [0usize; 63];
            let mut attempts = 0usize;
            let metrics = super::nfo_quality_scan_with(text, |pos, byte| {
                for &id in &dispatch[byte as usize] {
                    let all = matches!(id,0|1|24..=30|59..=62);
                    if (!all && evidence.first[id].is_some())
                        || (all && pos < next[id])
                        || !super::nfo_quality_prefix(id, text, pos)
                    {
                        continue;
                    }
                    // Overlapping wildcard prefixes can make thousands of
                    // individually bounded anchored attempts quadratic. Decline
                    // before attempting long ambiguous prefixes, and bound the
                    // total request work independently of per-call PCRE limits.
                    let ambiguous_prefix = match id {
                        2 | 5 => text[pos..]
                            .iter()
                            .take(257)
                            .take_while(|b| super::is_pcre_space(**b))
                            .count(),
                        8 => text[pos..]
                            .iter()
                            .take(257)
                            .take_while(|b| matches!(b, b'-' | b'='))
                            .count(),
                        11 => text[pos..]
                            .iter()
                            .take(257)
                            .take_while(|b| !b.is_ascii_alphanumeric())
                            .count(),
                        _ => 0,
                    };
                    if ambiguous_prefix > 256 {
                        return Err(());
                    }
                    attempts += 1;
                    if attempts > 8192 {
                        return Err(());
                    }
                    // Anchoring keeps the original full subject: lookbehind,
                    // word boundaries, ^/$ and cross-line greediness retain
                    // the original PCRE semantics at this candidate position.
                    let captured = self.captures_at(id, text, pos, 0x8000_0000)?;
                    if self.match_failed {
                        return Err(());
                    }
                    if let Some(captured) = captured {
                        if evidence.first[id].is_none() {
                            evidence.first[id] = Some(captured.offsets);
                        }
                        evidence.counts[id] += 1;
                        next[id] = captured.offsets[1];
                        if matches!(id, 0 | 1) {
                            evidence.all[id].push(captured.offsets);
                        }
                    }
                }
                Ok(())
            })?;
            self.evidence = Some(evidence);
            Ok(metrics)
        }
        pub(super) fn clear_quality(&mut self) {
            self.evidence = None;
        }
        pub(super) fn count(&mut self, id: usize, text: &[u8]) -> Result<usize, ()> {
            if let Some(evidence) = &self.evidence {
                if evidence.text == text.as_ptr() as usize && evidence.len == text.len() {
                    return Ok(evidence.counts[id]);
                }
                return Err(());
            }
            let (mut count, mut offset) = (0, 0);
            while let Some(capture) = self.captures(id, text, offset)? {
                let end = capture.offsets[1];
                if end <= offset {
                    return Err(());
                }
                offset = end;
                count += 1;
            }
            Ok(if self.match_failed { 0 } else { count })
        }
        pub(super) fn all<'a>(
            &mut self,
            id: usize,
            text: &'a [u8],
        ) -> Result<Vec<Captures<'a>>, ()> {
            if let Some(evidence) = &self.evidence {
                if evidence.text == text.as_ptr() as usize && evidence.len == text.len() {
                    return Ok(evidence.all[id]
                        .iter()
                        .map(|offsets| Captures {
                            text,
                            offsets: *offsets,
                        })
                        .collect());
                }
                return Err(());
            }
            let mut matches = Vec::new();
            let mut offset = 0;
            while let Some(capture) = self.captures(id, text, offset)? {
                let end = capture.offsets[1];
                if end <= offset {
                    return Err(());
                } // None of the fixed patterns match empty text.
                offset = end;
                matches.push(capture);
            }
            if self.match_failed {
                matches.clear();
            }
            Ok(matches)
        }
    }
    impl Drop for Matcher {
        fn drop(&mut self) {
            unsafe {
                (self.api.free)(self.data);
                (self.api.context_free)(self.context);
                (self.api.stack_free)(self.stack);
            }
        }
    }
    pub(super) fn with_matcher<T>(
        run: impl FnOnce(&mut Matcher) -> Result<T, ()>,
    ) -> Result<T, ()> {
        thread_local! { static MATCHER: std::cell::RefCell<Option<Matcher>> = const { std::cell::RefCell::new(None) }; }
        MATCHER.with(|slot| {
            let mut slot = slot.try_borrow_mut().map_err(|_| ())?;
            if slot.is_none() {
                *slot = Some(Matcher::new().ok_or(())?);
            }
            let matcher = slot.as_mut().ok_or(())?;
            matcher.clear_quality();
            // The RefCell borrow pins this Matcher until after the guard drops.
            // Clearing on unwind prevents retaining evidence for an input whose
            // address/length may later be reused for different PHP bytes.
            struct ResetEvidence(*mut Matcher);
            impl Drop for ResetEvidence {
                fn drop(&mut self) {
                    unsafe {
                        (*self.0).clear_quality();
                    }
                }
            }
            let _reset = ResetEvidence(matcher as *mut Matcher);
            run(matcher)
        })
    }
    pub(super) struct Captures<'a> {
        text: &'a [u8],
        offsets: [usize; 8],
    }
    impl<'a> Captures<'a> {
        pub(super) fn get(&self, index: usize) -> &'a [u8] {
            let start = self.offsets.get(index * 2).copied().unwrap_or(usize::MAX);
            let end = self
                .offsets
                .get(index * 2 + 1)
                .copied()
                .unwrap_or(usize::MAX);
            self.text.get(start..end).unwrap_or(b"")
        }
    }
}
const NFO_METADATA_PATTERNS: &[(&str, u32)] = &[
    (r###"https?:\/\/[^\s<>"']+"###, 8),
    (r###"(?<![\/\.])\bwww\.[a-z0-9][-a-z0-9]*\.[^\s<>"']+"###, 8),
    (
        r###"(?:^|\n)\s*[-=*]{3,}.*?([A-Z0-9]{2,15})\s*[-=*]{3,}"###,
        8,
    ),
    (
        r###"(?:presents?|brought\s+(?:to\s+)?(?:you\s+)?by|from)\s*[:\-]?\s*([A-Z][A-Z0-9]{1,14})"###,
        8,
    ),
    (
        r###"(?:greets?\s+(?:go(?:es)?\s+)?(?:out\s+)?to|respect\s+to)\s*[:\-]?\s*([\w,\s&]+)"###,
        8,
    ),
    (
        r###"(?:^|\n)\s*([A-Z][A-Z0-9]{1,14})\s+(?:nfo|info|release)\s*(?:$|\n)"###,
        8,
    ),
    (
        r###"(?:released\s+by|rls\s+by)\s*[:\-]?\s*([A-Z][A-Z0-9]{1,14})"###,
        8,
    ),
    (
        r###"\b([A-Z][A-Z0-9]{1,14})\s+(?:presents?|brings?\s+you)"###,
        8,
    ),
    (r###"[-=]{2,}\s*([A-Z][A-Z0-9]{1,14})\s*[-=]{2,}$"###, 1032),
    (r###"irc\.[a-z0-9.-]+\s+#([A-Z][A-Z0-9]{1,14})"###, 8),
    (r###"www\.([a-z][a-z0-9]{1,14})\.(?:com|org|net|info)"###, 8),
    (
        r###"^\s*[^a-zA-Z0-9]*([A-Z][A-Z0-9]{2,14})[^a-zA-Z0-9]*\s*$"###,
        1032,
    ),
    (
        r###"(?:date|released?|rls)\s*[:\-]?\s*(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})"###,
        8,
    ),
    (
        r###"(?:date|released?|rls)\s*[:\-]?\s*(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})"###,
        8,
    ),
    (
        r###"(?:date|released?|rls)\s*[:\-]?\s*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+(\d{1,2}),?\s+(\d{4})"###,
        8,
    ),
    (
        r###"(?:video|codec)\s*[:\-]?\s*(x264|x265|hevc|h\.?264|h\.?265|xvid|divx|av1|vp9|mpeg[24]?)"###,
        8,
    ),
    (
        r###"\b(x264|x265|HEVC|H\.?264|H\.?265|XviD|DivX|AV1|VP9)\b"###,
        8,
    ),
    (
        r###"(?:audio|sound)\s*[:\-]?\s*(aac|ac3|dts(?:-(?:hd|ma|x))?|truehd|atmos|flac|mp3|eac3|dd[+p]?|dolby)"###,
        8,
    ),
    (
        r###"\b(AAC|AC3|DTS(?:-(?:HD|MA|X))?|TrueHD|Atmos|FLAC|EAC3|DD[+P]?)\b"###,
        8,
    ),
    (
        r###"(?:resolution|quality)\s*[:\-]?\s*(\d{3,4}[xX×]\d{3,4}|\d{3,4}p|[48]K|UHD|FHD|HD)"###,
        8,
    ),
    (r###"\b(2160p|1080p|720p|480p|4K|UHD|FHD|HD)\b"###, 8),
    (r###"\b(\d{3,4})\s*[xX×]\s*(\d{3,4})\b"###, 0),
    (
        r###"(?:size|file\s*size)\s*[:\-]?\s*(\d+(?:[.,]\d+)?)\s*(bytes?|[KMGTP]B|[KMGTP]iB)"###,
        8,
    ),
    (r###"\b(\d+(?:[.,]\d+)?)\s*(GB|GiB|MB|MiB|TB|TiB)\b"###, 8),
    (r###"imdb\.com\/title\/(tt\d{7,})"###, 8),
    (
        r###"thetvdb\.com\/(?:\?tab=series&id=|series\/)(\d{1,8})"###,
        8,
    ),
    (r###"themoviedb\.org\/movie\/(\d{1,8})"###, 8),
    (r###"themoviedb\.org\/tv\/(\d{1,8})"###, 8),
    (r###"tvmaze\.com\/shows\/(\d{1,6})"###, 8),
    (
        r###"anidb\.net\/(?:perl-bin\/animedb\.pl\?show=anime&aid=|anime\/)(\d{1,6})"###,
        8,
    ),
    (r###"myanimelist\.net\/anime\/(\d{1,6})"###, 8),
    (r###"tvmaze\.com\/shows\/(\d{1,6})"###, 8),
    (r###"imdb\.com\/title\/(tt\d{7,})"###, 8),
    (r###"thetvdb\.com\/\?tab=series&id=(\d{1,8})"###, 8),
    (r###"thetvdb\.com\/series\/(\d{1,8}|[\w-]+)"###, 8),
    (r###"themoviedb\.org\/movie\/(\d{1,8})"###, 8),
    (r###"themoviedb\.org\/tv\/(\d{1,8})"###, 8),
    (
        r###"anidb\.net\/(?:perl-bin\/animedb\.pl\?show=anime&aid=|anime\/)(\d{1,6})"###,
        8,
    ),
    (r###"trakt\.tv\/(?:shows|movies)\/([\w-]+)"###, 8),
    (
        r###"(?:language|audio|spoken?|dialogue)\s*[:\-]?\s*([A-Za-z]+(?:\s*[,\/&]\s*[A-Za-z]+)*)"###,
        8,
    ),
    (
        r###"(?:subs?|subtitles?)\s*[:\-]?\s*([A-Za-z]+(?:\s*[,\/&]\s*[A-Za-z]+)*)"###,
        8,
    ),
    (r###"[\s,\/&]+"###, 0),
    (
        r###"(?:runtime|duration|length|playtime)\s*[:\-]?\s*(?:(\d{1,2})\s*h(?:ours?)?\s*)?(\d{1,3})\s*m(?:in(?:utes?)?)?"###,
        8,
    ),
    (
        r###"(?:runtime|duration|length|playtime)\s*[:\-]?\s*(\d{1,2}):(\d{2})(?::(\d{2}))?"###,
        8,
    ),
    (r###"\b(\d{2,3})\s*(?:min(?:utes?)?|mins)\b"###, 8),
    (r###"(?:genre|category|type)\s*[:\-]?\s*([^\n\r]+)"###, 8),
    (r###"[\s,\/&|]+"###, 0),
    (
        r###"(?:platform|os|system|requires?)\s*[:\-]?\s*(windows?|linux|mac(?:os)?|unix|android|ios)"###,
        8,
    ),
    (
        r###"(?:version|ver|v)\s*[:\-]?\s*(\d+(?:\.\d+)*(?:\s*(?:build|b)\s*\d+)?)"###,
        8,
    ),
    (
        r###"(?:protection|drm|copy[ -]?protection)\s*[:\-]?\s*([^\n\r]+)"###,
        8,
    ),
    (
        r###"\b(cracked|keygen|serial|patch|loader|activator)\b"###,
        8,
    ),
    (
        r###"(?:title|release|name)\s*[:\-]?\s*([^\n\r]{5,100})"###,
        8,
    ),
    (
        r###"(?:^|\n)\s*(?:[\-=*~]{3,}\s*)?([A-Za-z0-9][\w.\-\s]{10,80}?)(?:\s*[\-=*~]{3,})?\s*(?:\n|$)"###,
        1024,
    ),
    (
        r###"^(?:date|size|codec|format|video|audio|language|runtime|genre)\s*:"###,
        8,
    ),
    (r###"[-=*~#@]{5,}"###, 0),
    (r###"[┌┐└┘├┤┬┴┼│─╔╗╚╝║═]{3,}"###, 0),
    (r###"[░▒▓█▄▀■□▪▫]{3,}"###, 0),
    (r###"[\/\|_]{3,}.*[\/\|_]{3,}"###, 0),
    (r###"(\S)\1{4,}"###, 0),
    (r###"^[ \t]*[-=*]{2,}.*[-=*]{2,}[ \t]*$"###, 1024),
    (r###"^[ \t]*\[.*\][ \t]*$"###, 1024),
    (r###"^[ \t]*<.*>[ \t]*$"###, 1024),
    (r###"^[ \t]*[A-Za-z][A-Za-z\s]{2,20}\s*[:\.].*$"###, 1024),
];
const NFO_EXTRACT_URLS: &[usize] = &[0, 1];
const NFO_EXTRACT_GROUP_NAME: &[usize] = &[2, 3, 4, 5, 6, 7, 8, 9, 10, 11];
const NFO_EXTRACT_RELEASE_DATE: &[usize] = &[12, 13, 14];
const NFO_EXTRACT_CODEC_INFO: &[usize] = &[15, 16, 17, 18, 19, 20, 21];
const NFO_EXTRACT_FILE_SIZE: &[usize] = &[22, 23];
const NFO_EXTRACT_ALL_MEDIA_IDS: &[usize] = &[24, 25, 26, 27, 28, 29, 30];
const NFO_PARSE_SHOW_ID: &[usize] = &[31, 32, 33, 34, 35, 36, 37, 38];
const NFO_EXTRACT_LANGUAGE: &[usize] = &[39, 40, 41];
const NFO_EXTRACT_RUNTIME: &[usize] = &[42, 43, 44];
const NFO_EXTRACT_GENRE: &[usize] = &[45, 46];
const NFO_EXTRACT_SOFTWARE_INFO: &[usize] = &[47, 48, 49, 50];
const NFO_EXTRACT_RELEASE_TITLE: &[usize] = &[51, 52, 53];
const NFO_HAS_ASCII_ART: &[usize] = &[54, 55, 56, 57, 58];
const NFO_ANALYZE_STRUCTURE: &[usize] = &[59, 60, 61, 62];

#[derive(Debug, PartialEq)]
enum NfoMetaValue {
    Null,
    Bool(bool),
    Int(i64),
    Bytes(Vec<u8>),
    Array(Vec<(NfoMetaKey, NfoMetaValue)>),
}
#[derive(Debug, PartialEq)]
enum NfoMetaKey {
    Int(usize),
    Bytes(&'static str),
}
impl NfoMetaValue {
    fn bytes(bytes: &[u8]) -> Self {
        Self::Bytes(bytes.to_vec())
    }
    fn list(values: impl IntoIterator<Item = Self>) -> Self {
        Self::Array(
            values
                .into_iter()
                .enumerate()
                .map(|(i, v)| (NfoMetaKey::Int(i), v))
                .collect(),
        )
    }
    fn map(values: impl IntoIterator<Item = (&'static str, Self)>) -> Self {
        Self::Array(
            values
                .into_iter()
                .map(|(k, v)| (NfoMetaKey::Bytes(k), v))
                .collect(),
        )
    }
    fn count(&self) -> usize {
        if let Self::Array(a) = self {
            a.len()
        } else {
            0
        }
    }
    fn serialize(&self, out: &mut Vec<u8>) {
        fn string(out: &mut Vec<u8>, bytes: &[u8]) {
            out.extend_from_slice(format!("s:{}:\"", bytes.len()).as_bytes());
            out.extend_from_slice(bytes);
            out.extend_from_slice(b"\";");
        }
        match self {
            Self::Null => out.extend_from_slice(b"N;"),
            Self::Bool(v) => out.extend_from_slice(if *v { b"b:1;" } else { b"b:0;" }),
            Self::Int(v) => out.extend_from_slice(format!("i:{v};").as_bytes()),
            Self::Bytes(b) => string(out, b),
            Self::Array(entries) => {
                out.extend_from_slice(format!("a:{}:{{", entries.len()).as_bytes());
                for (key, value) in entries {
                    match key {
                        NfoMetaKey::Int(i) => out.extend_from_slice(format!("i:{i};").as_bytes()),
                        NfoMetaKey::Bytes(k) => string(out, k.as_bytes()),
                    }
                    value.serialize(out);
                }
                out.push(b'}');
            }
        }
    }
}
fn nfo_meta_trim(mut text: &[u8]) -> &[u8] {
    let trim = |b: u8| matches!(b, b' ' | b'\t' | b'\r' | b'\n' | 0 | 11);
    while text.first().is_some_and(|b| trim(*b)) {
        text = &text[1..];
    }
    while text.last().is_some_and(|b| trim(*b)) {
        text = &text[..text.len() - 1];
    }
    text
}
fn nfo_meta_ucfirst(bytes: &[u8]) -> Vec<u8> {
    let mut bytes = bytes.to_ascii_lowercase();
    if let Some(first) = bytes.first_mut() {
        first.make_ascii_uppercase();
    }
    bytes
}
fn nfo_meta_int(bytes: &[u8]) -> i64 {
    bytes.iter().fold(0i64, |n, b| {
        n.saturating_mul(10).saturating_add((b - b'0') as i64)
    })
}
fn nfo_meta_urls(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<NfoMetaValue, ()> {
    let mut urls: Vec<Vec<u8>> = m
        .all(NFO_EXTRACT_URLS[0], text)?
        .iter()
        .map(|c| c.get(0).to_vec())
        .collect();
    for c in m.all(NFO_EXTRACT_URLS[1], text)? {
        let mut url = b"http://".to_vec();
        url.extend_from_slice(c.get(0));
        urls.push(url);
    }
    let mut seen = std::collections::HashSet::new();
    Ok(NfoMetaValue::Array(
        urls.into_iter()
            .enumerate()
            .filter_map(|(i, url)| {
                if !url.is_empty() && url != b"0" && seen.insert(url.clone()) {
                    Some((NfoMetaKey::Int(i), NfoMetaValue::Bytes(url)))
                } else {
                    None
                }
            })
            .collect(),
    ))
}
fn nfo_meta_group(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<NfoMetaValue, ()> {
    const FALSE: &[&[u8]] = &[
        b"THE",
        b"AND",
        b"FOR",
        b"NFO",
        b"INFO",
        b"DVD",
        b"BLU",
        b"RAY",
        b"WEB",
        b"HDTV",
        b"RELEASE",
        b"GROUP",
        b"DATE",
        b"SIZE",
        b"CODEC",
        b"VIDEO",
        b"AUDIO",
        b"FORMAT",
        b"NOTES",
        b"INSTALL",
        b"GREETS",
        b"PRESENTS",
        b"TEAM",
        b"SCENE",
        b"FILE",
        b"FILES",
    ];
    for (i, &id) in NFO_EXTRACT_GROUP_NAME.iter().enumerate() {
        if let Some(c) = m.captures(id, text, 0)? {
            let group = nfo_meta_trim(c.get(1));
            let upper = group.to_ascii_uppercase();
            if (2..=20).contains(&group.len()) && !FALSE.contains(&upper.as_slice()) {
                return Ok(if i < 5 {
                    NfoMetaValue::bytes(group)
                } else {
                    NfoMetaValue::Bytes(upper)
                });
            }
        }
    }
    Ok(NfoMetaValue::Null)
}
fn nfo_meta_date(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<NfoMetaValue, ()> {
    for (i, &id) in NFO_EXTRACT_RELEASE_DATE.iter().enumerate() {
        if let Some(c) = m.captures(id, text, 0)? {
            let (year, month, day) = match i {
                0 => (
                    nfo_meta_int(c.get(3)) + if c.get(3).len() == 2 { 2000 } else { 0 },
                    nfo_meta_int(c.get(2)),
                    nfo_meta_int(c.get(1)),
                ),
                1 => (
                    nfo_meta_int(c.get(1)),
                    nfo_meta_int(c.get(2)),
                    nfo_meta_int(c.get(3)),
                ),
                _ => {
                    let month = [
                        b"jan", b"feb", b"mar", b"apr", b"may", b"jun", b"jul", b"aug", b"sep",
                        b"oct", b"nov", b"dec",
                    ]
                    .iter()
                    .position(|v| c.get(1).eq_ignore_ascii_case(*v))
                    .unwrap_or(0)
                        + 1;
                    (nfo_meta_int(c.get(3)), month as i64, nfo_meta_int(c.get(2)))
                }
            };
            return Ok(NfoMetaValue::Bytes(
                format!("{year:04}-{month:02}-{day:02}").into_bytes(),
            ));
        }
    }
    Ok(NfoMetaValue::Null)
}
fn nfo_meta_codecs(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<NfoMetaValue, ()> {
    let mut values = Vec::new();
    for (key, range) in [("video", 0..2), ("audio", 2..4), ("resolution", 4..7)] {
        for i in range {
            if let Some(c) = m.captures(NFO_EXTRACT_CODEC_INFO[i], text, 0)? {
                let mut bytes = c.get(1).to_ascii_uppercase();
                if i < 2 {
                    bytes.retain(|b| *b != b'.');
                }
                if i == 6 {
                    bytes.push(b'x');
                    bytes.extend_from_slice(c.get(2));
                }
                values.push((key, NfoMetaValue::Bytes(bytes)));
                break;
            }
        }
    }
    Ok(NfoMetaValue::map(values))
}
// Zend casts finite doubles modulo 2^64, unlike Rust's saturating `as i64`.
#[cfg(test)]
fn nfo_meta_php_float_to_int(value: f64) -> i64 {
    if !value.is_finite() {
        return 0;
    }
    let value = value.trunc();
    if value >= i64::MIN as f64 && value < 9_223_372_036_854_775_808.0 {
        return value as i64;
    }
    let modulus = 18_446_744_073_709_551_616.0;
    let mut value = value % modulus;
    if value < 0.0 {
        value += modulus;
    }
    if value >= 9_223_372_036_854_775_808.0 {
        (value - modulus) as i64
    } else {
        value as i64
    }
}
fn nfo_meta_size(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<NfoMetaValue, ()> {
    for &id in NFO_EXTRACT_FILE_SIZE {
        if let Some(c) = m.captures(id, text, 0)? {
            let number = c
                .get(1)
                .iter()
                .map(|&b| if b == b',' { b'.' } else { b })
                .collect::<Vec<_>>();
            let value: f64 = std::str::from_utf8(&number)
                .map_err(|_| ())?
                .parse()
                .map_err(|_| ())?;
            let unit = c.get(2).to_ascii_uppercase();
            let power = match unit.as_slice() {
                b"B" | b"BYTE" | b"BYTES" => 0,
                b"KB" | b"KIB" => 1,
                b"MB" | b"MIB" => 2,
                b"GB" | b"GIB" => 3,
                b"TB" | b"TIB" => 4,
                b"PB" | b"PIB" => 5,
                _ => continue,
            };
            let bytes = value * (1024u64.pow(power) as f64);
            // PHP 8.5 warns for a non-finite/out-of-range float-to-int cast.
            // Let the native path emit that exact warning and wrapped value.
            if !bytes.is_finite() || bytes >= 9_223_372_036_854_775_808.0 {
                return Err(());
            }
            return Ok(NfoMetaValue::Int(bytes as i64));
        }
    }
    Ok(NfoMetaValue::Null)
}
fn nfo_meta_ids(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<NfoMetaValue, ()> {
    let mut ids = Vec::new();
    for (&id, source) in NFO_EXTRACT_ALL_MEDIA_IDS.iter().zip([
        "imdb",
        "thetvdb",
        "tmdb_movie",
        "tmdb_tv",
        "tvmaze",
        "anidb",
        "mal",
    ]) {
        for c in m.all(id, text)? {
            ids.push(NfoMetaValue::map([
                ("id", NfoMetaValue::bytes(c.get(1))),
                ("source", NfoMetaValue::bytes(source.as_bytes())),
            ]));
        }
    }
    Ok(NfoMetaValue::list(ids))
}
fn nfo_meta_show(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<NfoMetaValue, ()> {
    for (&id, source) in NFO_PARSE_SHOW_ID.iter().zip([
        "tvmaze",
        "imdb",
        "thetvdb",
        "thetvdb",
        "tmdb_movie",
        "tmdb_tv",
        "anidb",
        "trakt",
    ]) {
        if let Some(c) = m.captures(id, text, 0)? {
            return Ok(NfoMetaValue::map([
                ("showid", NfoMetaValue::bytes(nfo_meta_trim(c.get(1)))),
                ("site", NfoMetaValue::bytes(source.as_bytes())),
            ]));
        }
    }
    Ok(NfoMetaValue::Bool(false))
}
fn nfo_meta_language(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<NfoMetaValue, ()> {
    const KNOWN: &[&[u8]] = &[
        b"english",
        b"german",
        b"french",
        b"spanish",
        b"italian",
        b"dutch",
        b"portuguese",
        b"russian",
        b"japanese",
        b"korean",
        b"chinese",
        b"mandarin",
        b"cantonese",
        b"swedish",
        b"norwegian",
        b"danish",
        b"finnish",
        b"polish",
        b"czech",
        b"hungarian",
        b"turkish",
        b"arabic",
        b"hindi",
        b"thai",
        b"vietnamese",
        b"indonesian",
        b"malay",
        b"multi",
        b"dual",
        b"english/german",
        b"eng",
        b"ger",
        b"fre",
        b"spa",
        b"ita",
    ];
    let mut languages = Vec::new();
    for &id in &NFO_EXTRACT_LANGUAGE[..2] {
        if let Some(c) = m.captures(id, text, 0)? {
            for language in c.get(1).split(|b| is_pcre_space(*b) || b",/&".contains(b)) {
                let lower = nfo_meta_trim(language).to_ascii_lowercase();
                // PHP compares lowercase candidates with already-ucfirst output,
                // so repeated languages (including across fields) are preserved.
                if KNOWN.contains(&lower.as_slice()) {
                    languages.push(NfoMetaValue::Bytes(nfo_meta_ucfirst(&lower)));
                }
            }
        }
    }
    Ok(NfoMetaValue::list(languages))
}
fn nfo_meta_runtime(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<NfoMetaValue, ()> {
    for (i, &id) in NFO_EXTRACT_RUNTIME.iter().enumerate() {
        if let Some(c) = m.captures(id, text, 0)? {
            return Ok(NfoMetaValue::Int(if i == 2 {
                nfo_meta_int(c.get(1))
            } else {
                nfo_meta_int(c.get(1)) * 60 + nfo_meta_int(c.get(2))
            }));
        }
    }
    Ok(NfoMetaValue::Null)
}
fn nfo_meta_genre(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<NfoMetaValue, ()> {
    const VALID: &[&[u8]] = &[
        b"action",
        b"adventure",
        b"animation",
        b"biography",
        b"comedy",
        b"crime",
        b"documentary",
        b"drama",
        b"family",
        b"fantasy",
        b"history",
        b"horror",
        b"music",
        b"musical",
        b"mystery",
        b"romance",
        b"sci-fi",
        b"scifi",
        b"sport",
        b"thriller",
        b"war",
        b"western",
        b"adult",
        b"xxx",
        b"erotic",
        b"anime",
        b"rpg",
        b"fps",
        b"strategy",
        b"simulation",
        b"puzzle",
        b"racing",
        b"sports",
        b"rock",
        b"pop",
        b"electronic",
        b"hip-hop",
        b"rap",
        b"classical",
        b"jazz",
    ];
    let mut genres = Vec::new();
    if let Some(c) = m.captures(NFO_EXTRACT_GENRE[0], text, 0)? {
        for part in c.get(1).split(|b| is_pcre_space(*b) || b",/&|".contains(b)) {
            let lower = nfo_meta_trim(part).to_ascii_lowercase();
            let value = NfoMetaValue::Bytes(nfo_meta_ucfirst(&lower));
            if VALID.contains(&lower.as_slice()) && !genres.contains(&value) {
                genres.push(value);
            }
        }
    }
    Ok(NfoMetaValue::list(genres))
}
fn nfo_meta_software(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<NfoMetaValue, ()> {
    let mut info = Vec::new();
    if let Some(c) = m.captures(NFO_EXTRACT_SOFTWARE_INFO[0], text, 0)? {
        info.push(("platform", NfoMetaValue::Bytes(nfo_meta_ucfirst(c.get(1)))));
    }
    if let Some(c) = m.captures(NFO_EXTRACT_SOFTWARE_INFO[1], text, 0)? {
        info.push(("version", NfoMetaValue::bytes(nfo_meta_trim(c.get(1)))));
    }
    if let Some(c) = m.captures(NFO_EXTRACT_SOFTWARE_INFO[2], text, 0)? {
        let protection = nfo_meta_trim(c.get(1));
        if protection.len() > 2 && protection.len() < 50 {
            info.push(("protection", NfoMetaValue::bytes(protection)));
        }
    }
    if m.captures(NFO_EXTRACT_SOFTWARE_INFO[3], text, 0)?.is_some() {
        info.push(("has_crack", NfoMetaValue::Bool(true)));
    }
    Ok(NfoMetaValue::map(info))
}
fn nfo_meta_title(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<NfoMetaValue, ()> {
    for &id in &NFO_EXTRACT_RELEASE_TITLE[..2] {
        if let Some(c) = m.captures(id, text, 0)? {
            let title = nfo_meta_trim(c.get(1));
            if (5..=100).contains(&title.len())
                && m.captures(NFO_EXTRACT_RELEASE_TITLE[2], title, 0)?
                    .is_none()
            {
                return Ok(NfoMetaValue::bytes(title));
            }
        }
    }
    Ok(NfoMetaValue::Null)
}
fn nfo_meta_art(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<i32, ()> {
    for &id in NFO_HAS_ASCII_ART {
        if m.captures(id, text, 0)?.is_some() {
            return Ok(1);
        }
    }
    Ok(0)
}
fn nfo_meta_structure(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<i32, ()> {
    let lines = text
        .split(|b| *b == b'\n')
        .filter(|line| !nfo_meta_trim(line).is_empty())
        .take(10)
        .count();
    nfo_meta_structure_lines(m, text, lines)
}
fn nfo_meta_structure_lines(
    m: &mut nfo_metadata_pcre::Matcher,
    text: &[u8],
    lines: usize,
) -> Result<i32, ()> {
    let mut doubled = 0;
    for &id in &NFO_ANALYZE_STRUCTURE[..3] {
        doubled += m.count(id, text)?.min(3) * 2;
    }
    doubled += m.count(NFO_ANALYZE_STRUCTURE[3], text)?.min(10);
    if lines >= 10 {
        doubled += 4;
    }
    Ok((doubled / 2).min(15) as i32)
}
// The substring keyword scores, binary penalties, and line occupancy are
// collected in one byte traversal. A tiny immutable Aho-Corasick automaton
// preserves overlapping keywords (e.g. "install" and "installer"). Metadata
// captures still use PHP's exact ordered PCRE rules; the complete quality
// calculation is fused into one ABI call, not a one-scan regex replacement.
const NFO_QUALITY_KEYWORDS: &[&[u8]] = &[
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
struct NfoQualityTrie {
    next: Vec<[u16; 36]>,
    outputs: Vec<u128>,
}
fn nfo_quality_symbol(byte: u8) -> Option<usize> {
    let byte = byte.to_ascii_lowercase();
    if byte.is_ascii_lowercase() {
        Some((byte - b'a') as usize)
    } else if byte.is_ascii_digit() {
        Some(26 + (byte - b'0') as usize)
    } else {
        None
    }
}
fn nfo_quality_scan(text: &[u8]) -> (usize, usize, usize) {
    nfo_quality_scan_with(text, |_, _| Ok(())).expect("infallible lexical scan")
}
fn nfo_quality_scan_with(
    text: &[u8],
    mut visit: impl FnMut(usize, u8) -> Result<(), ()>,
) -> Result<(usize, usize, usize), ()> {
    static TRIE: std::sync::OnceLock<NfoQualityTrie> = std::sync::OnceLock::new();
    let trie = TRIE.get_or_init(|| {
        let mut next = vec![[0; 36]];
        let mut outputs = vec![0u128];
        for (i, keyword) in NFO_QUALITY_KEYWORDS.iter().enumerate() {
            let mut node = 0;
            for &byte in *keyword {
                let symbol = nfo_quality_symbol(byte).unwrap();
                if next[node][symbol] == 0 {
                    next[node][symbol] = next.len() as u16;
                    next.push([0; 36]);
                    outputs.push(0);
                }
                node = next[node][symbol] as usize;
            }
            outputs[node] |= 1u128 << i;
        }
        let mut fail = vec![0usize; next.len()];
        let mut queue = std::collections::VecDeque::new();
        for &node in &next[0] {
            if node != 0 {
                queue.push_back(node as usize);
            }
        }
        while let Some(node) = queue.pop_front() {
            for symbol in 0..36 {
                let child = next[node][symbol] as usize;
                if child != 0 {
                    fail[child] = next[fail[node]][symbol] as usize;
                    outputs[child] |= outputs[fail[child]];
                    queue.push_back(child);
                } else {
                    next[node][symbol] = next[fail[node]][symbol];
                }
            }
        }
        NfoQualityTrie { next, outputs }
    });
    let (mut state, mut found, mut binary, mut lines, mut occupied) =
        (0usize, 0u128, 0usize, 0usize, false);
    for (pos, &byte) in text.iter().enumerate() {
        visit(pos, byte)?;
        if found.count_ones() < 10 {
            state = nfo_quality_symbol(byte).map_or(0, |symbol| trie.next[state][symbol] as usize);
            found |= trie.outputs[state];
        }
        if binary < 4 && (byte <= 8 || byte == 11 || byte == 12 || (14..=31).contains(&byte)) {
            binary += 1;
        }
        if byte == b'\n' {
            if occupied {
                lines += 1;
                occupied = false;
            }
        } else if !matches!(byte, b' ' | b'\t' | b'\r' | 0 | 11) {
            occupied = true;
        }
    }
    if occupied {
        lines += 1;
    }
    Ok((found.count_ones().min(10) as usize, binary, lines))
}
fn nfo_meta_quality_fused(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<i32, ()> {
    nfo_meta_quality_score(m, text, nfo_quality_scan(text))
}
fn nfo_meta_quality(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<i32, ()> {
    let metrics = m.prepare_quality(text)?;
    let result = nfo_meta_quality_score(m, text, metrics);
    m.clear_quality();
    result
}
fn nfo_meta_quality_score(
    m: &mut nfo_metadata_pcre::Matcher,
    text: &[u8],
    metrics: (usize, usize, usize),
) -> Result<i32, ()> {
    let mut score = 50;
    score += if text.len() < 100 {
        -20
    } else if text.len() > 500 && text.len() < 20_000 {
        15
    } else if text.len() >= 20_000 {
        5
    } else {
        0
    };

    let (keyword_count, binary_count, line_count) = metrics;
    score += (keyword_count * 2) as i32;
    let saturated = |score: i32| score >= 100 + (binary_count * 5) as i32;
    let mut media_count = 0;
    for &id in NFO_EXTRACT_ALL_MEDIA_IDS {
        media_count += m.count(id, text)?;
        if media_count >= 3 {
            break;
        }
    }
    score += (media_count * 5).min(15) as i32;
    if saturated(score) {
        return Ok(100);
    }
    score += (nfo_meta_urls(m, text)?.count() * 2).min(10) as i32;
    if saturated(score) {
        return Ok(100);
    }
    for range in [0..2, 2..4, 4..7] {
        for i in range {
            if m.captures(NFO_EXTRACT_CODEC_INFO[i], text, 0)?.is_some() {
                score += 3;
                break;
            }
        }
    }
    if saturated(score) {
        return Ok(100);
    }
    score += nfo_meta_art(m, text)? * 10 + nfo_meta_structure_lines(m, text, line_count)?;
    if saturated(score) {
        return Ok(100);
    }
    if nfo_meta_group(m, text)? != NfoMetaValue::Null {
        score += 8;
    }
    if saturated(score) {
        return Ok(100);
    }
    for &id in NFO_EXTRACT_RELEASE_DATE {
        if m.captures(id, text, 0)?.is_some() {
            score += 5;
            break;
        }
    }
    score += (nfo_meta_language(m, text)?.count() * 2).min(6) as i32;
    if saturated(score) {
        return Ok(100);
    }
    for &id in NFO_EXTRACT_RUNTIME {
        if m.captures(id, text, 0)?.is_some() {
            score += 4;
            break;
        }
    }
    score -= (binary_count * 5) as i32;
    Ok(score.clamp(0, 100))
}
fn nfo_meta_parse(m: &mut nfo_metadata_pcre::Matcher, text: &[u8]) -> Result<NfoMetaValue, ()> {
    Ok(NfoMetaValue::map([
        ("urls", nfo_meta_urls(m, text)?),
        ("group", nfo_meta_group(m, text)?),
        ("release_date", nfo_meta_date(m, text)?),
        ("codec_info", nfo_meta_codecs(m, text)?),
        ("file_size", nfo_meta_size(m, text)?),
        ("media_ids", nfo_meta_ids(m, text)?),
        ("show_id", nfo_meta_show(m, text)?),
        ("language", nfo_meta_language(m, text)?),
        ("runtime", nfo_meta_runtime(m, text)?),
        ("genre", nfo_meta_genre(m, text)?),
        ("software_info", nfo_meta_software(m, text)?),
        ("release_title", nfo_meta_title(m, text)?),
    ]))
}
fn nfo_meta_range(ptr: *const u8, len: usize) -> Option<(usize, usize)> {
    if (ptr.is_null() && len != 0) || len > isize::MAX as usize {
        return None;
    }
    let start = ptr as usize;
    Some((start, start.checked_add(len)?))
}
fn nfo_meta_overlaps(a: (usize, usize), b: (usize, usize)) -> bool {
    a.0 < b.1 && b.0 < a.1 && a.0 != a.1 && b.0 != b.1
}
unsafe fn nfo_meta_slice<'a>(ptr: *const u8, len: usize) -> Option<&'a [u8]> {
    nfo_meta_range(ptr, len)?;
    Some(if len == 0 {
        b""
    } else {
        unsafe { std::slice::from_raw_parts(ptr, len) }
    })
}
unsafe fn nfo_meta_output(bytes: &[u8], out: *mut u8, cap: usize) -> isize {
    if cap < bytes.len() {
        return -(bytes.len().max(2) as isize);
    }
    if !bytes.is_empty() {
        unsafe {
            std::ptr::copy_nonoverlapping(bytes.as_ptr(), out, bytes.len());
        }
    }
    bytes.len() as isize
}
macro_rules! nfo_metadata_export {
    ($export:ident,$implementation:ident) => {
        /// PHP-serialized byte-preserving metadata; -1 declines, a smaller
        /// negative result requests that many output bytes. No partial writes.
        /// # Safety
        /// Input/output must be valid for their declared lengths and disjoint.
        #[unsafe(no_mangle)]
        pub unsafe extern "C" fn $export(
            text: *const u8,
            len: usize,
            out: *mut u8,
            cap: usize,
        ) -> isize {
            let Some(input) = nfo_meta_range(text, len) else {
                return -1;
            };
            let Some(output) = nfo_meta_range(out.cast_const(), cap) else {
                return -1;
            };
            if nfo_meta_overlaps(input, output) {
                return -1;
            }
            std::panic::catch_unwind(|| {
                let bytes = unsafe { nfo_meta_slice(text, len) }.ok_or(())?;
                let value =
                    nfo_metadata_pcre::with_matcher(|matcher| $implementation(matcher, bytes))?;
                let mut serialized = Vec::new();
                value.serialize(&mut serialized);
                Ok::<isize, ()>(unsafe { nfo_meta_output(&serialized, out, cap) })
            })
            .ok()
            .and_then(Result::ok)
            .unwrap_or(-1)
        }
    };
}
nfo_metadata_export!(nntmux_nfo_extract_urls, nfo_meta_urls);
nfo_metadata_export!(nntmux_nfo_extract_group_name, nfo_meta_group);
nfo_metadata_export!(nntmux_nfo_extract_release_date, nfo_meta_date);
nfo_metadata_export!(nntmux_nfo_extract_codec_info, nfo_meta_codecs);
nfo_metadata_export!(nntmux_nfo_extract_file_size, nfo_meta_size);
nfo_metadata_export!(nntmux_nfo_extract_all_media_ids, nfo_meta_ids);
nfo_metadata_export!(nntmux_nfo_parse_show_id, nfo_meta_show);
nfo_metadata_export!(nntmux_nfo_extract_language, nfo_meta_language);
nfo_metadata_export!(nntmux_nfo_extract_runtime, nfo_meta_runtime);
nfo_metadata_export!(nntmux_nfo_extract_genre, nfo_meta_genre);
nfo_metadata_export!(nntmux_nfo_extract_software_info, nfo_meta_software);
nfo_metadata_export!(nntmux_nfo_extract_release_title, nfo_meta_title);
nfo_metadata_export!(nntmux_nfo_parse_metadata, nfo_meta_parse);
macro_rules! nfo_scalar_export {
    ($export:ident,$implementation:ident) => {
        /// Scalar analysis, or -1 when unavailable/invalid.
        /// # Safety
        /// Input must be readable for its declared length.
        #[unsafe(no_mangle)]
        pub unsafe extern "C" fn $export(text: *const u8, len: usize) -> i32 {
            std::panic::catch_unwind(|| {
                let bytes = unsafe { nfo_meta_slice(text, len) }.ok_or(())?;
                nfo_metadata_pcre::with_matcher(|matcher| $implementation(matcher, bytes))
            })
            .ok()
            .and_then(Result::ok)
            .unwrap_or(-1)
        }
    };
}
nfo_scalar_export!(nntmux_nfo_calculate_quality, nfo_meta_quality);
nfo_scalar_export!(nntmux_nfo_calculate_quality_fused, nfo_meta_quality_fused);
nfo_scalar_export!(nntmux_nfo_has_ascii_art, nfo_meta_art);
nfo_scalar_export!(nntmux_nfo_analyze_structure, nfo_meta_structure);

unsafe fn nfo_append_tags(
    name: *const u8,
    name_len: usize,
    text: *const u8,
    len: usize,
    out: *mut u8,
    cap: usize,
    mode: u8,
) -> isize {
    let Some(name_range) = nfo_meta_range(name, name_len) else {
        return -1;
    };
    let Some(input_range) = nfo_meta_range(text, len) else {
        return -1;
    };
    let Some(output_range) = nfo_meta_range(out.cast_const(), cap) else {
        return -1;
    };
    if nfo_meta_overlaps(name_range, output_range) || nfo_meta_overlaps(input_range, output_range) {
        return -1;
    }
    std::panic::catch_unwind(|| {
        let name = unsafe { nfo_meta_slice(name, name_len) }.ok_or(())?;
        let text = unsafe { nfo_meta_slice(text, len) }.ok_or(())?;
        let mut bytes = name.to_vec();
        for i in 1..=4 {
            if mode != 0 && mode != i {
                continue;
            }
            let value = match i {
                1 => extract_language_from_nfo(text),
                2 => extract_resolution_from_nfo(text),
                3 => extract_source_from_nfo(text),
                _ => extract_audio_from_nfo(text),
            };
            if let Some(value) = value {
                bytes.push(b'.');
                bytes.extend_from_slice(value);
            }
        }
        Ok::<isize, ()>(unsafe { nfo_meta_output(&bytes, out, cap) })
    })
    .ok()
    .and_then(Result::ok)
    .unwrap_or(-1)
}
macro_rules! nfo_append_export {
    ($export:ident,$mode:expr) => {
        /// Appends the selected original NFO tag(s) to raw name bytes.
        /// # Safety
        /// Inputs and output must be valid for their lengths; output must not overlap either input.
        #[unsafe(no_mangle)]
        pub unsafe extern "C" fn $export(
            name: *const u8,
            name_len: usize,
            text: *const u8,
            len: usize,
            out: *mut u8,
            cap: usize,
        ) -> isize {
            unsafe { nfo_append_tags(name, name_len, text, len, out, cap, $mode) }
        }
    };
}
nfo_append_export!(nntmux_nfo_append_title_year_tags, 0);
nfo_append_export!(nntmux_nfo_append_language, 1);
nfo_append_export!(nntmux_nfo_append_resolution, 2);
nfo_append_export!(nntmux_nfo_append_source, 3);
nfo_append_export!(nntmux_nfo_append_audio_codec, 4);

#[cfg(test)]
mod wave8_metadata_tests {
    use super::*;

    #[test]
    fn serialized_values_preserve_bytes_and_sparse_keys() {
        let value = NfoMetaValue::Array(vec![
            (NfoMetaKey::Int(0), NfoMetaValue::bytes(b"a\xff\0")),
            (NfoMetaKey::Int(2), NfoMetaValue::Null),
        ]);
        let mut out = Vec::new();
        value.serialize(&mut out);
        assert_eq!(out, b"a:2:{i:0;s:3:\"a\xff\0\";i:2;N;}");
    }

    #[test]
    fn one_scan_quality_metrics_match_php_byte_rules() {
        let mut corpus = vec![
            Vec::new(),
            b"INSTALLER installer crack cracked x264 x265 AVC".to_vec(),
            b"\0\t \r\n\x0c\nword\n".to_vec(),
        ];
        let mut seed = 471u64;
        for length in [1, 31, 100, 1000, 65535] {
            let mut text = Vec::new();
            for _ in 0..length {
                seed = seed.wrapping_mul(6364136223846793005).wrapping_add(1);
                text.push((seed >> 32) as u8);
            }
            text.extend_from_slice(
                b"INSTALLER release x264 language source cracked nfo scene art notes http www",
            );
            corpus.push(text);
        }
        for text in corpus {
            let expected_keywords = NFO_QUALITY_KEYWORDS
                .iter()
                .filter(|keyword| find_ascii_ci(&text, keyword).is_some())
                .count()
                .min(10);
            let expected_binary = text
                .iter()
                .filter(|&&b| b <= 8 || b == 11 || b == 12 || (14..=31).contains(&b))
                .count()
                .min(4);
            let expected_lines = text
                .split(|b| *b == b'\n')
                .filter(|line| !nfo_meta_trim(line).is_empty())
                .count();
            assert_eq!(
                nfo_quality_scan(&text),
                (expected_keywords, expected_binary, expected_lines)
            );
        }
    }

    #[test]
    fn php_float_cast_wraps_at_integer_boundaries() {
        assert_eq!(nfo_meta_php_float_to_int(1.99), 1);
        assert_eq!(nfo_meta_php_float_to_int(f64::INFINITY), 0);
        assert_eq!(
            nfo_meta_php_float_to_int(9_223_372_036_854_775_808.0),
            i64::MIN
        );
        assert_eq!(nfo_meta_php_float_to_int(18_446_744_073_709_551_616.0), 0);
        assert_eq!(
            nfo_meta_php_float_to_int(18_446_744_073_709_555_712.0),
            4096
        );
    }

    #[test]
    fn metadata_abi_rejects_invalid_lengths_nulls_and_overlap() {
        let mut bytes = *b"0123456789abcdef";
        let ptr = bytes.as_mut_ptr();
        unsafe {
            assert_eq!(nntmux_nfo_extract_urls(std::ptr::null(), 1, ptr, 16), -1);
            assert_eq!(
                nntmux_nfo_extract_urls(ptr, 16, std::ptr::null_mut(), 1),
                -1
            );
            assert_eq!(nntmux_nfo_extract_urls(ptr, usize::MAX, ptr, 16), -1);
            assert_eq!(nntmux_nfo_extract_urls(ptr, 8, ptr.add(7), 8), -1);
            assert_eq!(nntmux_nfo_calculate_quality(std::ptr::null(), 1), -1);
            assert_eq!(nntmux_nfo_has_ascii_art(ptr, usize::MAX), -1);
            assert_eq!(nntmux_nfo_analyze_structure(ptr, usize::MAX), -1);
        }
        assert_eq!(&bytes, b"0123456789abcdef");
    }

    #[test]
    fn append_tags_have_atomic_buffers_and_preserve_binary_name() {
        let name = b"Movie\xff\0";
        let nfo = b"Language: DE\nVideo resolution 1280\nSource: BD\nAudio: AC-3";
        let expected = b"Movie\xff\0.GERMAN.720p.Bluray.x264.AC3";
        let mut small = [b'?'; 4];
        unsafe {
            assert_eq!(
                nntmux_nfo_append_title_year_tags(
                    name.as_ptr(),
                    name.len(),
                    nfo.as_ptr(),
                    nfo.len(),
                    small.as_mut_ptr(),
                    4
                ),
                -(expected.len() as isize)
            );
            assert_eq!(small, [b'?'; 4]);
            let mut out = vec![0; expected.len()];
            assert_eq!(
                nntmux_nfo_append_title_year_tags(
                    name.as_ptr(),
                    name.len(),
                    nfo.as_ptr(),
                    nfo.len(),
                    out.as_mut_ptr(),
                    out.len()
                ),
                expected.len() as isize
            );
            assert_eq!(out, expected);
            assert_eq!(
                nntmux_nfo_append_language(
                    std::ptr::null(),
                    0,
                    std::ptr::null(),
                    0,
                    std::ptr::null_mut(),
                    0
                ),
                0
            );
            assert_eq!(
                nntmux_nfo_append_language(
                    name.as_ptr(),
                    name.len(),
                    std::ptr::null(),
                    1,
                    small.as_mut_ptr(),
                    4
                ),
                -1
            );
            assert_eq!(
                nntmux_nfo_append_language(
                    small.as_ptr(),
                    4,
                    nfo.as_ptr(),
                    nfo.len(),
                    small.as_mut_ptr(),
                    4
                ),
                -1
            );
            assert_eq!(
                nntmux_nfo_append_language(
                    name.as_ptr(),
                    name.len(),
                    small.as_ptr(),
                    4,
                    small.as_mut_ptr(),
                    4
                ),
                -1
            );
        }
    }

    #[test]
    fn quality_prefix_filters_keep_source_match_candidates() {
        for (id, text, pos) in [
            (0, b"http://example.org".as_slice(), 0),
            (1, b"www.example.org".as_slice(), 0),
            (2, b"x\n---TEST---".as_slice(), 1),
            (5, b"x\nTest release\n".as_slice(), 1),
            (7, b"Xy presents".as_slice(), 0),
            (11, b"x\nTEST".as_slice(), 2),
            (12, b"released 1/2/2024".as_slice(), 0),
            (16, b"H.264".as_slice(), 0),
            (39, b"spoke English".as_slice(), 0),
            (39, b"spoken English".as_slice(), 0),
            (55, b"\x94\x8c\xe2".as_slice(), 0),
            (56, b"\x91\x96\xe2".as_slice(), 0),
            (58, b"\0\0\0\0\0".as_slice(), 0),
            (59, b"x\n  === border ===".as_slice(), 2),
            (62, b"x\nField: value".as_slice(), 2),
            (62, b"x\nAb : value".as_slice(), 2),
            (62, b"x\nField\nName: value".as_slice(), 2),
        ] {
            assert!(nfo_quality_first_byte(id, text[pos]), "dispatch {id}");
            assert!(nfo_quality_prefix(id, text, pos), "prefix {id}");
        }
    }

    #[test]
    fn optional_onepass_quality_matches_fused_evidence() {
        let Some(mut matcher) = nfo_metadata_pcre::Matcher::new() else {
            return;
        };
        let mut corpus = vec![b"".to_vec(),b"x".repeat(19999),b"Title: Film (2024)\n--- TEST ---\nvideo: x265 audio: AAC 1080p\nimdb.com/title/tt1234567\nDate: 1/2/24\nGenre: Drama\nLanguage: English,English".to_vec()];
        let mut seed = 173u64;
        for length in [13, 128, 1024, 65535] {
            let mut text = Vec::with_capacity(length);
            for _ in 0..length {
                seed = seed.wrapping_mul(6364136223846793005).wrapping_add(1);
                text.push((seed >> 32) as u8);
            }
            corpus.push(text);
        }
        for text in corpus {
            let fused = nfo_meta_quality_fused(&mut matcher, &text).unwrap();
            assert_eq!(nfo_meta_quality(&mut matcher, &text).unwrap(), fused);
        }
    }

    #[test]
    fn optional_quality_declines_ambiguous_prefix_work() {
        let Some(mut matcher) = nfo_metadata_pcre::Matcher::new() else {
            return;
        };
        for text in [
            [b" \n".repeat(5000), b"Actual presents".to_vec()].concat(),
            [
                b"-".repeat(1000),
                b"AB".to_vec(),
                b" ".repeat(1000),
                b"!\nActual presents".to_vec(),
            ]
            .concat(),
        ] {
            assert_eq!(nfo_meta_quality(&mut matcher, &text), Err(()));
        }
    }

    #[test]
    fn optional_quality_evidence_does_not_survive_input_reuse_or_unwind() {
        if nfo_metadata_pcre::Matcher::new().is_none() {
            return;
        }
        let mut buffer = [b'q'; 10];
        for text in [b"video x264", b"qqqqqqqqqq", b"audio aac ", b"qqqqqqqqqq"]
            .into_iter()
            .cycle()
            .take(100)
        {
            buffer.copy_from_slice(text);
            let fused =
                unsafe { nntmux_nfo_calculate_quality_fused(buffer.as_ptr(), buffer.len()) };
            assert_eq!(
                unsafe { nntmux_nfo_calculate_quality(buffer.as_ptr(), buffer.len()) },
                fused
            );
        }
        let _ = std::panic::catch_unwind(|| {
            let _: Result<(), ()> = nfo_metadata_pcre::with_matcher(|matcher| {
                matcher.prepare_quality(&buffer)?;
                panic!("test request unwind");
            });
        });
        buffer.copy_from_slice(b"video x265");
        assert_eq!(
            unsafe { nntmux_nfo_calculate_quality(buffer.as_ptr(), buffer.len()) },
            unsafe { nntmux_nfo_calculate_quality_fused(buffer.as_ptr(), buffer.len()) }
        );
    }

    #[test]
    fn optional_pcre_payload_semantics_and_buffers() {
        // `cargo test` has no PHP process and may have no PCRE2 symbols.
        // PHP's integration suite exercises every export with the real engine.
        let Some(mut matcher) = nfo_metadata_pcre::Matcher::new() else {
            return;
        };
        let text = b"http://x.com http://x.com http://y.com www.z.com\nLanguage: English,English\nSubs: English\nDate: 33/44/99\nDuration: 1:30:59\nGenre: Drama Drama\nVideo: x265\nAudio: AAC\nResolution: 1920x1080\nimdb.com/title/tt12345678\ntvmaze.com/shows/123\n";
        let urls = nfo_meta_urls(&mut matcher, text).unwrap();
        let mut serialized = Vec::new();
        urls.serialize(&mut serialized);
        assert_eq!(serialized,b"a:3:{i:0;s:12:\"http://x.com\";i:2;s:12:\"http://y.com\";i:3;s:16:\"http://www.z.com\";}");
        assert_eq!(
            nfo_meta_date(&mut matcher, text).unwrap(),
            NfoMetaValue::bytes(b"2099-44-33")
        );
        assert_eq!(nfo_meta_language(&mut matcher, text).unwrap().count(), 3);
        assert_eq!(
            nfo_meta_runtime(&mut matcher, text).unwrap(),
            NfoMetaValue::Int(90)
        );
        assert_eq!(nfo_meta_genre(&mut matcher, text).unwrap().count(), 1);
        let mut short = [b'?'; 1];
        unsafe {
            assert_eq!(
                nntmux_nfo_extract_urls(text.as_ptr(), text.len(), short.as_mut_ptr(), 1),
                -(serialized.len() as isize)
            );
            assert_eq!(short, [b'?']);
            let mut out = vec![0; serialized.len()];
            assert_eq!(
                nntmux_nfo_extract_urls(text.as_ptr(), text.len(), out.as_mut_ptr(), out.len()),
                serialized.len() as isize
            );
            assert_eq!(out, serialized);
        }
    }
}

fn nfo_quality_prefix(id: usize, text: &[u8], pos: usize) -> bool {
    let b = text[pos];
    let wb = pos == 0 || !is_word_byte(text[pos - 1]);
    let line_start = pos == 0 || matches!(text[pos - 1], b'\n' | b'\r' | 11 | 12 | 0x85 | 0);
    let starts = |words: &[&[u8]]| {
        words.iter().any(|word| {
            text.get(pos..pos + word.len())
                .is_some_and(|s| s.eq_ignore_ascii_case(word))
        })
    };
    match id {
        0 => starts(&[b"http://", b"https://"]),
        1 => wb && starts(&[b"www."]),
        2 | 5 => pos == 0 || b == b'\n',
        3 => starts(&[b"present", b"brought", b"from"]),
        4 => starts(&[b"greet", b"respect"]),
        6 => starts(&[b"released", b"rls"]),
        7 => {
            if !wb || !b.is_ascii_alphabetic() {
                return false;
            }
            let mut end = pos + 1;
            while end < text.len() && end - pos < 15 && text[end].is_ascii_alphanumeric() {
                end += 1;
            }
            if end - pos < 2 || !text.get(end).is_some_and(|b| is_pcre_space(*b)) {
                return false;
            }
            while text.get(end).is_some_and(|b| is_pcre_space(*b)) {
                end += 1;
            }
            [b"present".as_slice(), b"bring"].iter().any(|word| {
                text.get(end..end + word.len())
                    .is_some_and(|s| s.eq_ignore_ascii_case(word))
            })
        }
        8 => {
            matches!(b, b'-' | b'=') && text.get(pos + 1).is_some_and(|b| matches!(b, b'-' | b'='))
        }
        9 => starts(&[b"irc."]),
        10 => starts(&[b"www."]),
        11 => line_start,
        12..=14 => starts(&[b"date", b"release", b"rls"]),
        15 => starts(&[b"video", b"codec"]),
        16 => {
            wb && starts(&[
                b"x264", b"x265", b"hevc", b"h264", b"h.264", b"h265", b"h.265", b"xvid", b"divx",
                b"av1", b"vp9",
            ])
        }
        17 => starts(&[b"audio", b"sound"]),
        18 => {
            wb && starts(&[
                b"aac", b"ac3", b"dts", b"truehd", b"atmos", b"flac", b"eac3", b"dd",
            ])
        }
        19 => starts(&[b"resolution", b"quality"]),
        20 => {
            wb && starts(&[
                b"2160p", b"1080p", b"720p", b"480p", b"4k", b"uhd", b"fhd", b"hd",
            ])
        }
        21 => {
            wb && b.is_ascii_digit()
                && text.get(pos + 1).is_some_and(u8::is_ascii_digit)
                && text.get(pos + 2).is_some_and(u8::is_ascii_digit)
        }
        24 => starts(&[b"imdb.com/title/"]),
        25 => starts(&[b"thetvdb.com/"]),
        26 => starts(&[b"themoviedb.org/movie/"]),
        27 => starts(&[b"themoviedb.org/tv/"]),
        28 => starts(&[b"tvmaze.com/shows/"]),
        29 => starts(&[b"anidb.net/"]),
        30 => starts(&[b"myanimelist.net/anime/"]),
        39 => starts(&[b"language", b"audio", b"spoken", b"spoke", b"dialogue"]),
        40 => starts(&[b"sub"]),
        42 | 43 => starts(&[b"runtime", b"duration", b"length", b"playtime"]),
        44 => wb && b.is_ascii_digit() && text.get(pos + 1).is_some_and(u8::is_ascii_digit),
        54 => {
            b"-=*~#@".contains(&b)
                && text
                    .get(pos..pos + 5)
                    .is_some_and(|s| s.iter().all(|b| b"-=*~#@".contains(b)))
        }
        55 => "┌┐└┘├┤┬┴┼│─╔╗╚╝║═".as_bytes().contains(&b),
        56 => "░▒▓█▄▀■□▪▫".as_bytes().contains(&b),
        57 => {
            b"/|_".contains(&b)
                && text
                    .get(pos..pos + 3)
                    .is_some_and(|s| s.iter().all(|b| b"/|_".contains(b)))
        }
        58 => {
            !is_pcre_space(b)
                && text
                    .get(pos..pos + 5)
                    .is_some_and(|s| s.iter().all(|v| *v == b))
        }
        59..=62 => {
            if !line_start {
                return false;
            }
            let mut start = pos;
            while text.get(start).is_some_and(|b| matches!(b, b' ' | b'\t')) {
                start += 1;
            }
            let Some(&first) = text.get(start) else {
                return false;
            };
            match id {
                59 => {
                    b"-=*".contains(&first)
                        && text.get(start + 1).is_some_and(|b| b"-=*".contains(b))
                }
                60 => first == b'[',
                61 => first == b'<',
                _ => {
                    if !first.is_ascii_alphabetic() {
                        return false;
                    }
                    for end in start + 1..=start + 21 {
                        if end >= start + 3 {
                            let mut separator = end;
                            while text.get(separator).is_some_and(|b| is_pcre_space(*b)) {
                                separator += 1;
                            }
                            if text
                                .get(separator)
                                .is_some_and(|b| matches!(b, b':' | b'.'))
                            {
                                return true;
                            }
                        }
                        if !text
                            .get(end)
                            .is_some_and(|b| b.is_ascii_alphabetic() || is_pcre_space(*b))
                        {
                            break;
                        }
                    }
                    false
                }
            }
        }
        _ => false,
    }
}
// Necessary initial bytes are only a dispatch accelerator. The full prefix
// predicate and the original anchored PCRE still decide every potential match.
fn nfo_quality_first_byte(id: usize, byte: u8) -> bool {
    let lo = byte.to_ascii_lowercase();
    match id {
        0 => lo == b'h',
        1 | 10 => lo == b'w',
        2 | 5 | 11 | 59..=62 => true,
        3 => matches!(lo, b'p' | b'b' | b'f'),
        4 => matches!(lo, b'g' | b'r'),
        6 => lo == b'r',
        7 => byte.is_ascii_alphabetic(),
        8 => matches!(byte, b'-' | b'='),
        9 => lo == b'i',
        12..=14 => matches!(lo, b'd' | b'r'),
        15 => matches!(lo, b'v' | b'c'),
        16 => matches!(lo, b'x' | b'h' | b'd' | b'a' | b'v'),
        17 => matches!(lo, b'a' | b's'),
        18 => matches!(lo, b'a' | b'd' | b't' | b'f' | b'e'),
        19 => matches!(lo, b'r' | b'q'),
        20 => matches!(lo, b'2' | b'1' | b'7' | b'4' | b'u' | b'f' | b'h'),
        21 | 44 => byte.is_ascii_digit(),
        24 => lo == b'i',
        25..=28 => lo == b't',
        29 => lo == b'a',
        30 => lo == b'm',
        39 => matches!(lo, b'l' | b'a' | b's' | b'd'),
        40 => lo == b's',
        42 | 43 => matches!(lo, b'r' | b'd' | b'l' | b'p'),
        54 => b"-=*~#@".contains(&byte),
        55 => "┌┐└┘├┤┬┴┼│─╔╗╚╝║═".as_bytes().contains(&byte),
        56 => "░▒▓█▄▀■□▪▫".as_bytes().contains(&byte),
        57 => b"/|_".contains(&byte),
        58 => !is_pcre_space(byte),
        _ => false,
    }
}

// ============================================================================
// NZB NFO / PAR2 candidate scanner (`NzbParserService` detectors and the
// `NzbContentsService::parseNzb` classification loop)
// ============================================================================
//
// Every matcher reproduces one PCRE pattern in the C locale (ASCII case folding, `\s` =
// `[\t\n\v\f\r ]`, `\w` = `[A-Za-z0-9_]`, `.` excludes `\n`, `$` matches at the end or before
// a final `\n`). PHP routes here only under a C/POSIX `LC_CTYPE`.

/// PCRE `$` without `D`: for patterns whose byte before `$` cannot be `\n`, matching the
/// subject minus one trailing `\n` is exact.
#[inline]
fn nzbc_dollar(s: &[u8]) -> &[u8] {
    match s.last() {
        Some(b'\n') => &s[..s.len() - 1],
        _ => s,
    }
}

#[inline]
fn nzbc_find(s: &[u8], from: usize, needle: u8) -> Option<usize> {
    if from >= s.len() {
        return None;
    }
    let found = unsafe { memchr(s.as_ptr().add(from), needle as i32, s.len() - from) };
    if found.is_null() {
        None
    } else {
        Some(unsafe { found.offset_from(s.as_ptr()) } as usize)
    }
}

#[inline]
fn nzbc_at_ci(s: &[u8], at: usize, lower: &[u8]) -> bool {
    s.get(at..at + lower.len())
        .is_some_and(|w| w.eq_ignore_ascii_case(lower))
}

#[inline]
fn nzbc_quote_or_space(b: u8) -> bool {
    b == b'"' || is_pcre_space(b)
}

/// `(?:^|["\s])` before `pos`.
#[inline]
fn nzbc_anchor(s: &[u8], pos: usize) -> bool {
    pos == 0 || nzbc_quote_or_space(s[pos - 1])
}

/// `(?:["\s]|$)` at `pos` (`$` before a final `\n` is covered by `\s`).
#[inline]
fn nzbc_follow(s: &[u8], pos: usize) -> bool {
    s.get(pos).is_none_or(|&b| nzbc_quote_or_space(b))
}

/// `/\.\b(nfo|diz|info?)\b(?![.-])/i`
fn nzbc_nfo_extension(s: &[u8]) -> bool {
    let ends = |e: usize| {
        s.get(e)
            .is_none_or(|&c| !is_word_byte(c) && c != b'.' && c != b'-')
    };
    let mut from = 0;
    while let Some(p) = nzbc_find(s, from, b'.') {
        let t = p + 1;
        if (nzbc_at_ci(s, t, b"nfo") || nzbc_at_ci(s, t, b"diz")) && ends(t + 3) {
            return true;
        }
        if nzbc_at_ci(s, t, b"info") && ends(t + 4) {
            return true;
        }
        if nzbc_at_ci(s, t, b"inf") && ends(t + 3) {
            return true;
        }
        from = t;
    }
    false
}

/// Shortest keyword of the named-NFO alternation at `q`
/// (`file(?:_?id)?|readme|release|info(?:rmation)?|about|desc(?:ription)?|notes?|read\.?me|00-|000-|0-|_-_`).
#[inline]
fn nzbc_nfo_keyword_len(s: &[u8], q: usize) -> Option<usize> {
    let words: &[&[u8]] = match s[q].to_ascii_lowercase() {
        b'f' => &[b"file"],
        b'r' => &[b"readme", b"release", b"read.me"],
        b'i' => &[b"info"],
        b'a' => &[b"about"],
        b'd' => &[b"desc"],
        b'n' => &[b"note"],
        b'0' => &[b"0-", b"00-", b"000-"],
        b'_' => &[b"_-_"],
        _ => return None,
    };
    words.iter().find(|w| nzbc_at_ci(s, q, w)).map(|w| w.len())
}

/// `/(?:^|["\s])(?:<keywords>).*?\.(?:txt|nfo|diz)(?:["\s]|$)/i`. A keyword can contain neither a
/// quote nor whitespace, so the first anchored keyword of a line dominates every later one.
fn nzbc_nfo_named(s: &[u8]) -> bool {
    let mut start: Option<usize> = None;
    for (i, &b) in s.iter().enumerate() {
        if b == b'\n' {
            start = None;
            continue;
        }
        match start {
            None => {
                if nzbc_anchor(s, i) {
                    start = nzbc_nfo_keyword_len(s, i).map(|k| i + k);
                }
            }
            Some(from) => {
                if b == b'.'
                    && i >= from
                    && (nzbc_at_ci(s, i + 1, b"txt")
                        || nzbc_at_ci(s, i + 1, b"nfo")
                        || nzbc_at_ci(s, i + 1, b"diz"))
                    && nzbc_follow(s, i + 4)
                {
                    return true;
                }
            }
        }
    }
    false
}

/// `NzbParserService::detectNfoFile` priority: `1`, `2`, or `0` (not an NFO).
pub fn nzb_nfo_priority(s: &[u8]) -> u8 {
    if nzbc_nfo_extension(s) {
        1
    } else if nzbc_nfo_named(s) {
        2
    } else {
        0
    }
}

/// `/(?:^|["\s])(?:nfo|info|readme|release|file_?id|about)(?:["\s]|$)/i`
fn nzbc_hidden_named(s: &[u8]) -> bool {
    const WORDS: [&[u8]; 7] = [
        b"nfo", b"info", b"readme", b"release", b"fileid", b"file_id", b"about",
    ];
    (0..s.len()).any(|q| {
        nzbc_anchor(s, q)
            && WORDS
                .iter()
                .any(|w| nzbc_at_ci(s, q, w) && nzbc_follow(s, q + w.len()))
    })
}

/// `/^[a-z0-9]+[._-][a-z0-9._-]+["\s]*\(1\/1\)/i` (which implies the outer
/// `/^[a-z0-9._-]+["\s]*\(1\/1\)/i`). The class run must end at a byte outside the class.
fn nzbc_hidden_scene(s: &[u8]) -> bool {
    let run = s
        .iter()
        .position(|&b| !(b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'-')))
        .unwrap_or(s.len());
    match s[..run].iter().position(|b| !b.is_ascii_alphanumeric()) {
        Some(i) if i >= 1 && run >= i + 2 => {}
        _ => return false,
    }
    let mut p = run;
    while p < s.len() && nzbc_quote_or_space(s[p]) {
        p += 1;
    }
    s[p..].starts_with(b"(1/1)")
}

/// `/yEnc\s*\(\d+\)\s*\[1\/1\]/i`
fn nzbc_hidden_yenc(s: &[u8]) -> bool {
    let mut from = 0;
    while from < s.len() {
        let Some(off) = find_ascii_ci(&s[from..], b"yenc") else {
            return false;
        };
        let mut q = from + off + 4;
        while q < s.len() && is_pcre_space(s[q]) {
            q += 1;
        }
        if s.get(q) == Some(&b'(') {
            let d = q + 1;
            q = d;
            while q < s.len() && s[q].is_ascii_digit() {
                q += 1;
            }
            if q > d && s.get(q) == Some(&b')') {
                q += 1;
                while q < s.len() && is_pcre_space(s[q]) {
                    q += 1;
                }
                if s[q..].starts_with(b"[1/1]") {
                    return true;
                }
            }
        }
        from += off + 1;
    }
    false
}

/// Lowercase extensions of the hidden-NFO exclusion alternation, other than `[rst]\d{2}`.
#[inline]
fn nzbc_excluded_word(w: &[u8]) -> bool {
    if w.len() == 3
        && matches!(w[0], b'r' | b's' | b't')
        && w[1].is_ascii_digit()
        && w[2].is_ascii_digit()
    {
        return true;
    }
    matches!(
        w,
        b"exe"
            | b"com"
            | b"bat"
            | b"cmd"
            | b"scr"
            | b"dll"
            | b"msi"
            | b"pkg"
            | b"deb"
            | b"rpm"
            | b"apk"
            | b"ipa"
            | b"app"
            | b"zip"
            | b"rar"
            | b"7z"
            | b"ace"
            | b"tar"
            | b"gz"
            | b"bz2"
            | b"xz"
            | b"lzma"
            | b"cab"
            | b"iso"
            | b"bin"
            | b"cue"
            | b"img"
            | b"mdf"
            | b"nrg"
            | b"dmg"
            | b"vhd"
            | b"mp3"
            | b"flac"
            | b"ogg"
            | b"aac"
            | b"wav"
            | b"wma"
            | b"m4a"
            | b"opus"
            | b"ape"
            | b"wv"
            | b"mpc"
            | b"avi"
            | b"mkv"
            | b"mp4"
            | b"mov"
            | b"wmv"
            | b"mpg"
            | b"mpeg"
            | b"ts"
            | b"vob"
            | b"m2ts"
            | b"webm"
            | b"flv"
            | b"ogv"
            | b"divx"
            | b"xvid"
            | b"jpg"
            | b"jpeg"
            | b"png"
            | b"gif"
            | b"bmp"
            | b"tif"
            | b"tiff"
            | b"psd"
            | b"webp"
            | b"svg"
            | b"ico"
            | b"raw"
            | b"cr2"
            | b"nef"
            | b"pdf"
            | b"doc"
            | b"docx"
            | b"xls"
            | b"xlsx"
            | b"ppt"
            | b"pptx"
            | b"odt"
            | b"ods"
            | b"odp"
            | b"rtf"
            | b"epub"
            | b"mobi"
            | b"azw"
            | b"html"
            | b"htm"
            | b"css"
            | b"js"
            | b"php"
            | b"py"
            | b"java"
            | b"c"
            | b"cpp"
            | b"h"
            | b"cs"
            | b"sql"
            | b"json"
            | b"xml"
            | b"yml"
            | b"yaml"
            | b"db"
            | b"dbf"
            | b"mdb"
            | b"accdb"
            | b"sqlite"
            | b"csv"
            | b"par"
            | b"par2"
            | b"sfv"
            | b"md5"
            | b"sha1"
            | b"sha256"
            | b"sha512"
            | b"crc"
            | b"url"
            | b"lnk"
            | b"cfg"
            | b"ini"
            | b"inf"
            | b"sys"
            | b"tmp"
            | b"bak"
            | b"log"
            | b"srt"
            | b"sub"
            | b"idx"
            | b"ass"
            | b"ssa"
            | b"vtt"
    )
}

/// `/\.(?:<extensions>)\b/i`: every alternative is all word bytes, so it must equal the whole
/// word run after the dot.
fn nzbc_hidden_excluded(s: &[u8]) -> bool {
    let mut from = 0;
    while let Some(p) = nzbc_find(s, from, b'.') {
        let mut e = p + 1;
        while e < s.len() && is_word_byte(s[e]) {
            e += 1;
        }
        let len = e - p - 1;
        if (1..=6).contains(&len) {
            let mut lower = [0u8; 6];
            for (dst, src) in lower.iter_mut().zip(&s[p + 1..e]) {
                *dst = src.to_ascii_lowercase();
            }
            if nzbc_excluded_word(&lower[..len]) {
                return true;
            }
        }
        from = p + 1;
    }
    false
}

/// Pattern bits of `NzbParserService::detectHiddenNfoFile`: `1` = `\(1\/1\)$`, `2` = named,
/// `4` = scene style, `8` = yEnc `[1/1]`. The segment-count conditions are applied by the caller.
pub fn nzb_hidden_nfo_patterns(s: &[u8]) -> u32 {
    u32::from(nzbc_dollar(s).ends_with(b"(1/1)"))
        | (u32::from(nzbc_hidden_named(s)) << 1)
        | (u32::from(nzbc_hidden_scene(s)) << 2)
        | (u32::from(nzbc_hidden_yenc(s)) << 3)
}

/// `NzbParserService::detectHiddenNfoFile($subject, $segmentCount) !== false`.
pub fn nzb_is_hidden_nfo(s: &[u8], segments: u64) -> bool {
    let p = nzb_hidden_nfo_patterns(s);
    let candidate = (segments == 1 && p & (1 | 4 | 8) != 0) || (segments <= 2 && p & 2 != 0);
    candidate && !nzbc_hidden_excluded(s)
}

/// `/\.par2$/i`
pub fn nzb_is_par2_index(s: &[u8]) -> bool {
    let d = nzbc_dollar(s);
    d.len() >= 5 && d[d.len() - 5..].eq_ignore_ascii_case(b".par2")
}

/// `/\.par2(?:["\s]|$)/i`
fn nzbc_is_explicit_par2(s: &[u8]) -> bool {
    let mut from = 0;
    while let Some(p) = nzbc_find(s, from, b'.') {
        if nzbc_at_ci(s, p + 1, b"par2") && nzbc_follow(s, p + 5) {
            return true;
        }
        from = p + 1;
    }
    false
}

#[inline]
fn nzbc_digits(d: &[u8]) -> Option<i64> {
    d.iter().try_fold(0i64, |v, &c| {
        v.checked_mul(10)?.checked_add(i64::from(c - b'0'))
    })
}

/// `NzbParserService::extractPartsTotal`, or `None` when PHP's integer cast would saturate.
///
/// `/(?:[(\[])?(\d+)[\/)\]](\d+)[)\]]?$/` captures the maximal digit run ending before an optional
/// final `)`/`]`; `/(\d+)\)$/` the maximal digit run before a final `)`.
pub fn nzb_parts_total(s: &[u8]) -> Option<i64> {
    let t = nzbc_dollar(s);
    let Some(&last) = t.last() else {
        return Some(0);
    };
    let end = if last == b')' || last == b']' {
        t.len() - 1
    } else if last.is_ascii_digit() {
        t.len()
    } else {
        return Some(0);
    };
    let mut start = end;
    while start > 0 && t[start - 1].is_ascii_digit() {
        start -= 1;
    }
    if start == end {
        return Some(0);
    }
    if start >= 2 && matches!(t[start - 1], b'/' | b')' | b']') && t[start - 2].is_ascii_digit() {
        let total = nzbc_digits(&t[start..end])?;
        if total > 0 {
            return Some(total);
        }
    }
    if last == b')' {
        return nzbc_digits(&t[start..end]);
    }
    Some(0)
}

/// `NzbContentsService::calculateCompletion` (PHP integer division stays integral when exact).
pub fn nzb_completion(actual: u64, artificial: i64) -> f64 {
    if artificial <= 0 {
        return 0.0;
    }
    let a = actual as i64;
    if a % artificial == 0 {
        return if a == 0 { 0.0 } else { 100.0 };
    }
    let ratio = (a as f64 / artificial as f64) * 100.0;
    if ratio < 100.0 { ratio } else { 100.0 }
}

const NZBC_NFO_CHECK: u32 = 1;
const NZBC_PAR2: u32 = 2;

/// Running state of the `parseNzb` classification loop.
struct NzbcScan {
    flags: u32,
    files: u64,
    actual: u64,
    artificial: i64,
    has_non_par2: bool,
    nfo_kind: u8,
    nfo_priority: u8,
    nfo_index: u32,
    nfo_id: Vec<u8>,
    hidden_found: bool,
    par2: Vec<(u32, usize, usize)>,
    par2_ids: Vec<u8>,
}

impl NzbcScan {
    fn new(flags: u32) -> Self {
        Self {
            flags,
            files: 0,
            actual: 0,
            artificial: 0,
            has_non_par2: false,
            nfo_kind: 0,
            nfo_priority: 0,
            nfo_index: 0,
            nfo_id: Vec::new(),
            hidden_found: false,
            par2: Vec::new(),
            par2_ids: Vec::new(),
        }
    }

    /// One `<file>`; `first_id` is the first segment's text when the file has segments.
    /// Returns `false` when PHP must decide (integer overflow).
    fn file(&mut self, subject: &[u8], segments: u64, first_id: Option<&[u8]>) -> bool {
        let Ok(index) = u32::try_from(self.files) else {
            return false;
        };
        self.files += 1;
        self.actual += segments;
        let Some(total) = nzb_parts_total(subject) else {
            return false;
        };
        let Some(sum) = self.artificial.checked_add(total) else {
            return false;
        };
        self.artificial = sum;
        if !self.has_non_par2 && !nzbc_is_explicit_par2(subject) {
            self.has_non_par2 = true;
        }
        if segments == 0 {
            return true;
        }
        let id = first_id.unwrap_or(&[]);
        if self.flags & NZBC_NFO_CHECK != 0 && self.nfo_kind != 1 {
            let priority = nzb_nfo_priority(subject);
            if priority != 0 {
                self.set_nfo(1, priority, index, id);
            } else if !self.hidden_found && nzb_is_hidden_nfo(subject, segments) {
                self.set_nfo(2, 10, index, id);
                self.hidden_found = true;
            }
        }
        if self.flags & NZBC_PAR2 != 0 && nzb_is_par2_index(subject) {
            let start = self.par2_ids.len();
            self.par2_ids.extend_from_slice(id);
            self.par2.push((index, start, self.par2_ids.len()));
        }
        true
    }

    fn set_nfo(&mut self, kind: u8, priority: u8, index: u32, id: &[u8]) {
        self.nfo_kind = kind;
        self.nfo_priority = priority;
        self.nfo_index = index;
        self.nfo_id.clear();
        self.nfo_id.extend_from_slice(id);
    }

    /// Little-endian record: `u64 files, u64 actual, i64 artificial, f64 completion, u8 nfo kind
    /// (0 none, 1 explicit, 2 hidden), u8 priority, u32 file index, u32 id length, id, u32 PAR2
    /// count, then per PAR2 index candidate (only when a non-PAR2 file exists) `u32 file index,
    /// u32 id length, id`.
    fn write(&self, out: &mut OutCursor<'_>) -> isize {
        out.extend(&self.files.to_le_bytes());
        out.extend(&self.actual.to_le_bytes());
        out.extend(&self.artificial.to_le_bytes());
        out.extend(&nzb_completion(self.actual, self.artificial).to_le_bytes());
        out.push(self.nfo_kind);
        out.push(self.nfo_priority);
        out.extend(&self.nfo_index.to_le_bytes());
        out.extend(&(self.nfo_id.len() as u32).to_le_bytes());
        out.extend(&self.nfo_id);
        let par2: &[(u32, usize, usize)] = if self.has_non_par2 { &self.par2 } else { &[] };
        out.extend(&(par2.len() as u32).to_le_bytes());
        for &(index, start, end) in par2 {
            out.extend(&index.to_le_bytes());
            out.extend(&((end - start) as u32).to_le_bytes());
            out.extend(&self.par2_ids[start..end]);
        }
        finish_cursor_min_ref(out, 16)
    }
}

/// Values, comments and whitespace runs libxml rejects without `XML_PARSE_HUGE` (and anything
/// near that size) stay in PHP.
const NZBC_MAX_VALUE: usize = 1_000_000;

/// Bytes needing attention in a value, folded without early exit so the loop vectorizes:
/// `1` control byte (only `\t\n\r` survive the document check), `2` `<`, `4` `&`, `8` `]`, `16` `\r`.
#[inline]
fn nzbc_value_flags(raw: &[u8]) -> u8 {
    raw.iter().fold(0u8, |acc, &b| {
        acc | u8::from(b < 0x20)
            | (u8::from(b == b'<') << 1)
            | (u8::from(b == b'&') << 2)
            | (u8::from(b == b']') << 3)
            | (u8::from(b == b'\r') << 4)
    })
}

/// U+FFFE / U+FFFF are not XML characters (libxml rejects them, literal or referenced).
#[inline]
fn nzbc_has_nonchar(bytes: &[u8], from: usize) -> bool {
    let mut from = from;
    while let Some(p) = nzbc_find(bytes, from, 0xEF) {
        if bytes.get(p + 1) == Some(&0xBF) && matches!(bytes.get(p + 2), Some(0xBE | 0xBF)) {
            return true;
        }
        from = p + 1;
    }
    false
}

/// Control bytes other than `\t\n\r`, and DEL; branch-free per 64-byte block so it vectorizes.
fn nzbc_has_invalid_control(bytes: &[u8]) -> bool {
    let bad = |b: u8| {
        (u8::from(b < 0x20) & u8::from(b != b'\t') & u8::from(b != b'\n') & u8::from(b != b'\r'))
            | u8::from(b == 0x7F)
    };
    let (blocks, remainder) = bytes.as_chunks::<64>();
    blocks
        .iter()
        .any(|block| block.iter().fold(0u8, |acc, &b| acc | bad(b)) != 0)
        || remainder.iter().any(|&b| bad(b) != 0)
}

/// Decodes references in a validated value (rare: only values containing `&`).
#[inline]
fn nzbc_decode(raw: &[u8], is_attr: bool, out: &mut Vec<u8>) -> bool {
    out.clear();
    unescape_nzb_xml_utf8(raw, is_attr, out) && !nzbc_has_nonchar(out, 0)
}

/// Strict single-pass reader over the subset of NZB documents libxml provably parses the same way.
struct NzbcReader<'a> {
    bytes: &'a [u8],
    pos: usize,
    tmp: Vec<u8>,
}

impl<'a> NzbcReader<'a> {
    /// Whitespace and comments (each bounded) between markup.
    fn skip_misc(&mut self) -> bool {
        loop {
            let start = self.pos;
            self.pos = skip_nzb_xml_ws(self.bytes, self.pos);
            if self.pos - start > NZBC_MAX_VALUE {
                return false;
            }
            if self.bytes.get(self.pos..self.pos + 4) != Some(b"<!--") {
                return true;
            }
            let Some(end) = skip_nzb_xml_ws_and_comments(self.bytes, self.pos) else {
                return false;
            };
            if end - self.pos > NZBC_MAX_VALUE {
                return false;
            }
            self.pos = end;
        }
    }

    /// Attribute value: literal tab/newline/CR (normalized by libxml) and `<` decline. With
    /// `keep`, the decoded value is left in `dst`.
    #[inline]
    fn attr_value(raw: &[u8], keep: Option<&mut Vec<u8>>, tmp: &mut Vec<u8>) -> bool {
        if raw.len() > NZBC_MAX_VALUE {
            return false;
        }
        let flags = nzbc_value_flags(raw);
        if flags & 0b11 != 0 {
            return false;
        }
        if flags & 0b100 != 0 {
            return nzbc_decode(raw, true, keep.unwrap_or(tmp));
        }
        if let Some(dst) = keep {
            dst.clear();
            dst.extend_from_slice(raw);
        }
        true
    }

    /// Start tag `open` with validated attributes (`subject` kept in `subject` when given);
    /// returns whether it was self-closing.
    #[inline]
    fn start_tag(
        &mut self,
        open: &[u8],
        allow_xmlns: bool,
        mut subject: Option<&mut Vec<u8>>,
    ) -> Option<bool> {
        let attr_start = match_nzb_tag_exact(self.bytes, self.pos, open)?;
        let tmp = &mut self.tmp;
        let (after, self_closing) =
            parse_nzb_xml_attributes(self.bytes, attr_start, allow_xmlns, |k, v| {
                if k.len() > 1000 {
                    return false;
                }
                match subject.as_deref_mut() {
                    Some(dst) if k == b"subject" => Self::attr_value(v, Some(dst), tmp),
                    _ => Self::attr_value(v, None, tmp),
                }
            })?;
        self.pos = after;
        Some(self_closing)
    }

    /// Text content up to the closing tag; `\r` (normalized by libxml) declines. With `keep`,
    /// the decoded text is left in `dst`.
    #[inline]
    fn text(&mut self, close: &[u8], keep: Option<&mut Vec<u8>>) -> bool {
        let start = self.pos;
        let Some(end) = nzbc_find(self.bytes, start, b'<') else {
            return false;
        };
        let raw = &self.bytes[start..end];
        if raw.len() > NZBC_MAX_VALUE {
            return false;
        }
        let flags = nzbc_value_flags(raw);
        if flags & 0b10000 != 0 || (flags & 0b1000 != 0 && raw.windows(3).any(|w| w == b"]]>")) {
            return false;
        }
        if flags & 0b100 != 0 {
            if !nzbc_decode(raw, false, keep.unwrap_or(&mut self.tmp)) {
                return false;
            }
        } else if let Some(dst) = keep {
            dst.clear();
            dst.extend_from_slice(raw);
        }
        match expect_nzb_closing_tag(self.bytes, end, close) {
            Some(after) => {
                self.pos = after;
                true
            }
            None => false,
        }
    }

    /// `<child>text</child>` elements until `close`; `on_child(index)` returns whether to keep
    /// that child's text in `dst`. Returns the child count.
    #[inline]
    fn text_children(
        &mut self,
        close: &[u8],
        child: (&[u8], &[u8]),
        mut dst: Option<&mut Vec<u8>>,
    ) -> Option<u64> {
        let mut count = 0u64;
        loop {
            if !self.skip_misc() {
                return None;
            }
            if let Some(after) = expect_nzb_closing_tag(self.bytes, self.pos, close) {
                self.pos = after;
                return Some(count);
            }
            let self_closing = self.start_tag(child.0, false, None)?;
            let keep = if count == 0 { dst.take() } else { None };
            if self_closing {
                if let Some(d) = keep {
                    d.clear();
                }
            } else if !self.text(child.1, keep) {
                return None;
            }
            count += 1;
        }
    }

    /// Container element (`<head>`, `<groups>`, `<segments>`) of text children; returns the count.
    #[inline]
    fn container(
        &mut self,
        open: &[u8],
        close: &[u8],
        child: (&[u8], &[u8]),
        first: Option<&mut Vec<u8>>,
    ) -> Option<u64> {
        if self.start_tag(open, false, None)? {
            return Some(0);
        }
        self.text_children(close, child, first)
    }
}

/// Scans the document the way `parseNzb` walks `SimpleXML` (`$nzb->file`, the first
/// `->segments->segment` list, `(string)` subjects and segment text), declining anything outside
/// the strict subset libxml provably parses the same way, and files without `<segments>` (PHP
/// iterates `null` and warns).
fn nzbc_scan_document(bytes: &[u8], scan: &mut NzbcScan) -> bool {
    if bytes.is_empty() || bytes.len() > 33_554_432 {
        return false;
    }
    let start = if bytes.starts_with(b"\xEF\xBB\xBF") {
        3
    } else {
        0
    };
    let body = &bytes[start..];
    if std::str::from_utf8(body).is_err()
        || nzbc_has_invalid_control(body)
        || nzbc_has_nonchar(bytes, start)
    {
        return false;
    }
    let Some(after_decl) = parse_nzb_xml_decl(bytes, start) else {
        return false;
    };
    let mut r = NzbcReader {
        bytes,
        pos: after_decl,
        tmp: Vec::with_capacity(256),
    };
    let mut doctype = false;
    loop {
        if !r.skip_misc() {
            return false;
        }
        if !bytes
            .get(r.pos..r.pos + 9)
            .is_some_and(|s| s.eq_ignore_ascii_case(b"<!doctype"))
        {
            break;
        }
        if doctype {
            return false;
        }
        let Some(after) = match_standard_nzb_doctype(bytes, r.pos) else {
            return false;
        };
        doctype = true;
        r.pos = after;
    }
    if r.start_tag(b"<nzb", true, None) != Some(false) {
        return false;
    }
    let mut subject = Vec::with_capacity(256);
    let mut first_id = Vec::with_capacity(128);
    loop {
        if !r.skip_misc() {
            return false;
        }
        if let Some(after) = expect_nzb_closing_tag(bytes, r.pos, b"</nzb") {
            r.pos = after;
            return r.skip_misc() && r.pos == bytes.len();
        }
        if match_nzb_tag_exact(bytes, r.pos, b"<head").is_some() {
            if r.container(b"<head", b"</head", (b"<meta", b"</meta"), None)
                .is_none()
            {
                return false;
            }
            continue;
        }
        subject.clear();
        let Some(file_self_closing) = r.start_tag(b"<file", false, Some(&mut subject)) else {
            return false;
        };
        // A self-closing file has no `<segments>`.
        if file_self_closing {
            return false;
        }
        let mut segments: Option<u64> = None;
        loop {
            if !r.skip_misc() {
                return false;
            }
            if let Some(after) = expect_nzb_closing_tag(bytes, r.pos, b"</file") {
                r.pos = after;
                break;
            }
            if match_nzb_tag_exact(bytes, r.pos, b"<groups").is_some() {
                if r.container(b"<groups", b"</groups", (b"<group", b"</group"), None)
                    .is_none()
                {
                    return false;
                }
                continue;
            }
            // `->segments` selects the first list only; a second one stays in PHP.
            if segments.is_some() {
                return false;
            }
            let Some(count) = r.container(
                b"<segments",
                b"</segments",
                (b"<segment", b"</segment"),
                Some(&mut first_id),
            ) else {
                return false;
            };
            segments = Some(count);
        }
        let Some(count) = segments else {
            return false;
        };
        if !scan.file(&subject, count, (count > 0).then_some(first_id.as_slice())) {
            return false;
        }
    }
}

#[inline]
unsafe fn nzbc_input<'a>(ptr: *const u8, len: usize) -> Option<&'a [u8]> {
    if ptr.is_null() {
        if len > 0 { None } else { Some(&[]) }
    } else if len == 0 {
        Some(&[])
    } else {
        Some(unsafe { slice::from_raw_parts(ptr, len) })
    }
}

/// `NzbParserService::detectNfoFile`: `0` when not an NFO, `1` with the priority byte (`1`/`2`)
/// in `out_buf[0]`, or `-1` on invalid pointers or `out_cap == 0`.
///
/// # Safety
///
/// `title` must be valid for reads of `len` bytes and `out_buf` for writes of `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_detect_nfo_file(
    title: *const u8,
    len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(s) = (unsafe { nzbc_input(title, len) }) else {
        return -1;
    };
    if out_buf.is_null() || out_cap == 0 {
        return -1;
    }
    let priority = nzb_nfo_priority(s);
    if priority == 0 {
        return 0;
    }
    unsafe { *out_buf = priority };
    1
}

/// Pattern bits of `NzbParserService::detectHiddenNfoFile` (see [`nzb_hidden_nfo_patterns`]) plus
/// `16` when the exclusion pattern matches; `-1` on invalid pointers.
///
/// # Safety
///
/// `title` must be valid for reads of `len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_detect_hidden_nfo_file(title: *const u8, len: usize) -> i32 {
    let Some(s) = (unsafe { nzbc_input(title, len) }) else {
        return -1;
    };
    (nzb_hidden_nfo_patterns(s) | (u32::from(nzbc_hidden_excluded(s)) << 4)) as i32
}

/// `NzbParserService::detectPar2IndexFile`: `1`/`0`, or `-1` on invalid pointers.
///
/// # Safety
///
/// `title` must be valid for reads of `len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_detect_par2_index_file(title: *const u8, len: usize) -> i32 {
    let Some(s) = (unsafe { nzbc_input(title, len) }) else {
        return -1;
    };
    i32::from(nzb_is_par2_index(s))
}

/// `NzbParserService::extractPartsTotal`: `0..=i32::MAX`, `-1` on invalid pointers, or `-2`
/// when the total exceeds `i32::MAX` (PHP decides).
///
/// # Safety
///
/// `title` must be valid for reads of `len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_extract_parts_total(title: *const u8, len: usize) -> i32 {
    let Some(s) = (unsafe { nzbc_input(title, len) }) else {
        return -1;
    };
    nzb_parts_total(s)
        .and_then(|v| i32::try_from(v).ok())
        .unwrap_or(-2)
}

/// Classifies `subject\0segment_count\0` records (the `parseNzb` loop without XML access).
/// `flags`: `1` = NFO check, `2` = PAR2 index candidates. Writes the [`NzbcScan::write`] record
/// with empty ids; returns its length, `-1` on invalid input, or `-(required)` when `out_cap`
/// is too small.
///
/// # Safety
///
/// `records` must be valid for reads of `records_len` bytes and `out_buf` for writes of `out_cap`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_classify_nfo_par2_batch_nul(
    records: *const u8,
    records_len: usize,
    flags: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(input) = (unsafe { nzbc_input(records, records_len) }) else {
        return -1;
    };
    if out_buf.is_null() && out_cap > 0 {
        return -1;
    }
    let mut scan = NzbcScan::new(flags);
    let mut pos = 0;
    while pos < input.len() {
        let Some(subject_end) = nzbc_find(input, pos, 0) else {
            return -1;
        };
        let Some(count_end) = nzbc_find(input, subject_end + 1, 0) else {
            return -1;
        };
        let digits = &input[subject_end + 1..count_end];
        if digits.is_empty() || digits.len() > 18 || !digits.iter().all(u8::is_ascii_digit) {
            return -1;
        }
        let segments = nzbc_digits(digits).unwrap_or(0) as u64;
        if !scan.file(&input[pos..subject_end], segments, Some(&[])) {
            return -1;
        }
        pos = count_end + 1;
    }
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    scan.write(&mut out)
}

/// One-call `parseNzb` classification over the NZB source: file and segment totals, completion,
/// the selected NFO candidate and the PAR2 index candidates with first-segment message ids (see
/// [`NzbcScan::write`]). Returns the record length, `-1` when PHP must decide (invalid pointers,
/// documents outside the strict subset, integer overflow), or `-(required)` for a short buffer.
///
/// # Safety
///
/// `xml` must be valid for reads of `xml_len` bytes and `out_buf` for writes of `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_classify_nfo_par2_packed(
    xml: *const u8,
    xml_len: usize,
    flags: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(bytes) = (unsafe { nzbc_input(xml, xml_len) }) else {
        return -1;
    };
    if out_buf.is_null() && out_cap > 0 {
        return -1;
    }
    let mut scan = NzbcScan::new(flags);
    if !nzbc_scan_document(bytes, &mut scan) {
        return -1;
    }
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    scan.write(&mut out)
}

#[cfg(test)]
mod nzb_nfo_candidate_tests {
    use super::*;

    fn scan(xml: &str, flags: u32) -> Option<NzbcScan> {
        let mut scan = NzbcScan::new(flags);
        nzbc_scan_document(xml.as_bytes(), &mut scan).then_some(scan)
    }

    #[test]
    fn subject_detectors_follow_the_pcre_patterns() {
        assert_eq!(nzb_nfo_priority(b"\"a.nfo\" yEnc (1/1)"), 1);
        assert_eq!(nzb_nfo_priority(b"a.info"), 1);
        assert_eq!(nzb_nfo_priority(b"a.infoo"), 0);
        assert_eq!(nzb_nfo_priority(b"a.nfo-x"), 0);
        assert_eq!(nzb_nfo_priority(b"readme x.txt"), 2);
        assert_eq!(nzb_nfo_priority(b"readme\nx.txt"), 0);
        assert_eq!(nzb_nfo_priority(b"xreadme.txt"), 0);
        assert!(nzb_is_hidden_nfo(b"abc-def (1/1)", 1));
        assert!(!nzb_is_hidden_nfo(b"abc-def (1/1)", 2));
        assert!(nzb_is_hidden_nfo(b"x release", 2));
        assert!(!nzb_is_hidden_nfo(b"a.rar (1/1)", 1));
        assert!(nzb_is_hidden_nfo(b"name yEnc (12) [1/1] tail", 1));
        assert!(nzb_is_par2_index(b"A.PAR2\n"));
        assert!(!nzb_is_par2_index(b"a.par2\n\n"));
    }

    #[test]
    fn parts_total_matches_php_including_saturation() {
        assert_eq!(nzb_parts_total(b"A (15/20)"), Some(20));
        assert_eq!(nzb_parts_total(b"A [15/20]\n"), Some(20));
        assert_eq!(nzb_parts_total(b"A (1/0)"), Some(0));
        assert_eq!(nzb_parts_total(b"A 12)"), Some(12));
        assert_eq!(nzb_parts_total(b"A 1)2)"), Some(2));
        assert_eq!(nzb_parts_total(b"A (3/4)\n\n"), Some(0));
        assert_eq!(nzb_parts_total(b"A (1/99999999999999999999)"), None);
    }

    #[test]
    fn completion_keeps_php_integer_division() {
        assert_eq!(nzb_completion(0, 5), 0.0);
        assert_eq!(nzb_completion(10, 5), 100.0);
        assert_eq!(nzb_completion(1, 3), (1.0f64 / 3.0) * 100.0);
        assert_eq!(nzb_completion(4, 3), 100.0);
        assert_eq!(nzb_completion(7, 0), 0.0);
    }

    #[test]
    fn document_scan_selects_candidates_and_declines_outside_subset() {
        let doc = "<nzb><file subject=\"abc-def (1/1)\"><segments><segment>h@x</segment></segments></file>\
            <file subject=\"r.nfo (1/2)\"><groups><group>g</group></groups><segments><segment>n &amp; m</segment><segment>2</segment></segments></file>\
            <file subject=\"r.par2\"><segments><segment>p@x</segment></segments></file></nzb>";
        let s = scan(doc, 3).expect("accepted");
        assert_eq!((s.files, s.actual, s.artificial), (3, 4, 3));
        assert_eq!(
            (s.nfo_kind, s.nfo_priority, s.nfo_index, s.nfo_id.as_slice()),
            (1, 1, 1, &b"n & m"[..])
        );
        assert!(s.has_non_par2);
        assert_eq!(s.par2.len(), 1);
        assert!(scan(&doc.replace("n &amp; m", "<![CDATA[x]]>"), 3).is_none());
        assert!(
            scan(
                &doc.replace("<segments><segment>p@x</segment></segments>", ""),
                3
            )
            .is_none()
        );
        assert!(scan(&doc.replace("<groups>", "<x/><groups>"), 3).is_none());
        assert!(scan(&doc.replace("h@x", "h@x\r"), 3).is_none());
    }

    #[test]
    fn packed_export_reports_required_capacity() {
        let doc = b"<nzb><file subject=\"a.nfo\"><segments><segment>id@x</segment></segments></file></nzb>";
        let mut small = [0u8; 8];
        let required = unsafe {
            nntmux_nzb_classify_nfo_par2_packed(
                doc.as_ptr(),
                doc.len(),
                1,
                small.as_mut_ptr(),
                small.len(),
            )
        };
        assert!(required < -1);
        let mut buf = vec![0u8; (-required) as usize];
        let written = unsafe {
            nntmux_nzb_classify_nfo_par2_packed(
                doc.as_ptr(),
                doc.len(),
                1,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(written, -required);
        assert_eq!(&buf[38..42], &4u32.to_le_bytes());
        assert_eq!(&buf[42..46], b"id@x");
    }
}
