use std::borrow::Cow;
use std::ffi::c_char;
use std::slice;

use crate::filename::{clean_for_matching_span, strip_known_file_suffixes_len};
use crate::{HEX_LOWER, OutCursor, find_nul};

const SHA256_K: [u32; 64] = [
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
];

/// Computes the 64-byte lowercase hex SHA-256 digest of `data` directly over 64-byte slices.
#[inline]
pub fn sha256_hex_slice(data: &[u8]) -> [u8; 64] {
    let mut state: [u32; 8] = [
        0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab,
        0x5be0cd19,
    ];

    #[inline(always)]
    fn compress_block(state: &mut [u32; 8], block: &[u8; 64]) {
        let mut w = [0u32; 64];
        for (i, &chunk) in block.as_chunks::<4>().0.iter().enumerate() {
            w[i] = u32::from_be_bytes(chunk);
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
            let temp1 = h
                .wrapping_add(s1)
                .wrapping_add(ch)
                .wrapping_add(SHA256_K[i])
                .wrapping_add(w[i]);
            let s0 = a.rotate_right(2) ^ a.rotate_right(13) ^ a.rotate_right(22);
            let maj = (a & b) ^ (a & c) ^ (b & c);
            let temp2 = s0.wrapping_add(maj);

            h = g;
            g = f;
            f = e;
            e = d.wrapping_add(temp1);
            d = c;
            c = b;
            b = a;
            a = temp1.wrapping_add(temp2);
        }

        state[0] = state[0].wrapping_add(a);
        state[1] = state[1].wrapping_add(b);
        state[2] = state[2].wrapping_add(c);
        state[3] = state[3].wrapping_add(d);
        state[4] = state[4].wrapping_add(e);
        state[5] = state[5].wrapping_add(f);
        state[6] = state[6].wrapping_add(g);
        state[7] = state[7].wrapping_add(h);
    }

    let (blocks, remainder) = data.as_chunks::<64>();
    for block in blocks {
        compress_block(&mut state, block);
    }

    let bit_len = (data.len() as u64).wrapping_mul(8);
    let mut tail = [0u8; 64];
    tail[..remainder.len()].copy_from_slice(remainder);
    tail[remainder.len()] = 0x80;
    if remainder.len() >= 56 {
        compress_block(&mut state, &tail);
        tail.fill(0);
    }
    tail[56..64].copy_from_slice(&bit_len.to_be_bytes());
    compress_block(&mut state, &tail);

    let mut hex = [0u8; 64];
    for (i, &word) in state.iter().enumerate() {
        for (j, &byte) in word.to_be_bytes().iter().enumerate() {
            let idx = (i * 4 + j) * 2;
            hex[idx] = HEX_LOWER[(byte >> 4) as usize];
            hex[idx + 1] = HEX_LOWER[(byte & 0x0f) as usize];
        }
    }
    hex
}

#[inline]
fn is_printable_ascii_no_nzbsplit(bytes: &[u8]) -> bool {
    for &b in bytes {
        if !(0x20..=0x7E).contains(&b) {
            return false;
        }
    }
    if bytes.len() >= 12 {
        let mut i = 0;
        while i + 12 <= bytes.len() {
            if bytes[i] == b'_'
                && bytes[i + 1] == b'_'
                && bytes[i..i + 12].eq_ignore_ascii_case(b"__nzbsplit__")
            {
                return false;
            }
            i += 1;
        }
    }
    true
}

#[inline]
fn is_valid_evidence_token(bytes: &[u8]) -> bool {
    !bytes.is_empty() && !bytes.iter().any(|&b| matches!(b, b'\\' | b'/' | b'|'))
}

/// Checks `preg_match('/^[\p{N}\p{P}\p{Z}\s]+$/uD', s) === 0` for printable ASCII `s`.
/// In ASCII `0x20..=0x7E`, every byte is in `\p{N}`, `\p{P}`, `\p{Z}`, or `\s` EXCEPT
/// ASCII letters (`A-Za-z`, `\p{L}`) and ASCII math/currency/modifier symbols (`$+<=>^`|~`, `\p{S}`).
#[inline]
fn has_non_numeric_punct_space_ascii(s: &[u8]) -> bool {
    s.iter().any(|&b| {
        b.is_ascii_alphabetic()
            || matches!(
                b,
                b'$' | b'+' | b'<' | b'=' | b'>' | b'^' | b'`' | b'|' | b'~'
            )
    })
}

#[inline]
fn accepts_canonical_ascii(basename: &[u8], title: &[u8]) -> bool {
    if title.is_empty() || basename.len() < title.len() {
        return false;
    }
    if !basename[..title.len()].eq_ignore_ascii_case(title) {
        return false;
    }
    let suffix = &basename[title.len()..];
    if suffix.is_empty() {
        return true;
    }
    strip_known_file_suffixes_len(suffix, true) == Some(0)
}

#[inline]
fn original_filename_accepts_ascii(basename: &[u8], title: &[u8], alias: &[u8]) -> bool {
    if !is_valid_evidence_token(basename) || !is_valid_evidence_token(title) {
        return false;
    }
    if accepts_canonical_ascii(basename, title) {
        return true;
    }
    if alias.is_empty() {
        return false;
    }
    let Some(stem_len) = strip_known_file_suffixes_len(basename, true) else {
        return false;
    };
    let stem = &basename[..stem_len];
    if !is_valid_evidence_token(stem) || !has_non_numeric_punct_space_ascii(stem) {
        return false;
    }

    let other_basename = match alias.iter().rposition(|&b| b == b'/' || b == b'\\') {
        Some(pos) => &alias[pos + 1..],
        None => alias,
    };
    if !is_valid_evidence_token(other_basename) {
        return false;
    }
    let Some(other_stem_len) = strip_known_file_suffixes_len(other_basename, true) else {
        return false;
    };
    let other_stem = &other_basename[..other_stem_len];

    for identity in [stem, other_stem] {
        if is_valid_evidence_token(identity)
            && has_non_numeric_punct_space_ascii(identity)
            && accepts_canonical_ascii(basename, identity)
            && accepts_canonical_ascii(other_basename, identity)
        {
            return true;
        }
    }
    false
}

/// Computes `OriginalFilenameEvidence::canonicalNominationKeys()` for an ASCII `basename` without `__NZBSPLIT__`.
/// Returns `Ok(Some(keys))` on valid nominations, `Ok(None)` when `canonicalNominationKeys()` returns `null`,
/// or `Err(())` when declined to PHP fallback.
pub fn compute_nomination_keys_ascii(basename: &[u8]) -> Result<Option<Vec<Vec<u8>>>, ()> {
    if !is_printable_ascii_no_nzbsplit(basename) {
        return Err(());
    }
    if !is_valid_evidence_token(basename) || basename.len() > 8192 {
        return Ok(None);
    }
    let dot_count = basename.iter().filter(|&&b| b == b'.').count();
    if dot_count + 1 > 128 {
        return Ok(None);
    }

    let mut keys: Vec<Vec<u8>> = Vec::with_capacity(4);
    for offset in 1..=basename.len() {
        if offset != basename.len() && basename[offset] != b'.' {
            continue;
        }
        let suffix = &basename[offset..];
        if suffix.is_empty() || strip_known_file_suffixes_len(suffix, true) == Some(0) {
            let mut key = Vec::with_capacity(offset);
            for &b in &basename[..offset] {
                key.push(b.to_ascii_lowercase());
            }
            if !keys.contains(&key) {
                keys.push(key);
            }
        }
    }

    Ok(Some(keys))
}

// ============================================================================
// PredbMatchSelector
// ============================================================================

#[inline]
fn php_similar_str(txt1: &[u8], txt2: &[u8]) -> (usize, usize, usize) {
    let mut max = 0usize;
    let mut pos1 = 0usize;
    let mut pos2 = 0usize;
    let l1 = txt1.len();
    let l2 = txt2.len();
    let mut p = 0;
    while p < l1 {
        if l1 - p <= max {
            break;
        }
        let mut q = 0;
        while q < l2 {
            if l2 - q <= max {
                break;
            }
            let mut l = 0;
            while p + l < l1 && q + l < l2 && txt1[p + l] == txt2[q + l] {
                l += 1;
            }
            if l > max {
                max = l;
                pos1 = p;
                pos2 = q;
            }
            q += 1;
        }
        p += 1;
    }
    (max, pos1, pos2)
}

fn php_similar_char(txt1: &[u8], txt2: &[u8]) -> usize {
    let (max, pos1, pos2) = php_similar_str(txt1, txt2);
    if max == 0 {
        return 0;
    }
    let mut sum = max;
    if pos1 > 0 && pos2 > 0 {
        sum += php_similar_char(&txt1[..pos1], &txt2[..pos2]);
    }
    if pos1 + max < txt1.len() && pos2 + max < txt2.len() {
        sum += php_similar_char(&txt1[pos1 + max..], &txt2[pos2 + max..]);
    }
    sum
}

/// Exact byte-level match of PHP's `PredbMatchSelector::similarityPercent($left, $right)`.
#[inline]
pub fn similarity_percent(left: &[u8], right: &[u8]) -> f64 {
    if !left.is_empty() && left == right {
        return 100.0;
    }
    if left.is_empty() && right.is_empty() {
        return 0.0;
    }
    let sim = php_similar_char(left, right);
    (sim as f64) * 200.0 / ((left.len() + right.len()) as f64)
}

#[inline]
fn strip_candidate_title_suffixes(mut t: &[u8]) -> &[u8] {
    while let Some(&first) = t.first() {
        if matches!(first, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B) {
            t = &t[1..];
        } else {
            break;
        }
    }
    while let Some(&last) = t.last() {
        if matches!(last, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B) {
            t = &t[..t.len() - 1];
        } else {
            break;
        }
    }

    // 1. `/\.(mkv|avi|mp4|m4v|mpg|mpeg|wmv|flv|mov|ts|vob|iso|divx)$/i`
    if let Some(dot) = t.iter().rposition(|&b| b == b'.') {
        let ext = &t[dot + 1..];
        if (2..=4).contains(&ext.len()) {
            let mut buf = [0u8; 4];
            for (i, &b) in ext.iter().enumerate() {
                buf[i] = b.to_ascii_lowercase();
            }
            if matches!(
                &buf[..ext.len()],
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
        if (2..=4).contains(&ext.len()) {
            let mut buf = [0u8; 4];
            for (i, &b) in ext.iter().enumerate() {
                buf[i] = b.to_ascii_lowercase();
            }
            let lower = &buf[..ext.len()];
            let matched = matches!(
                lower,
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
            ) || ((3..=4).contains(&lower.len())
                && lower[0] == b'r'
                && lower[1..].iter().all(|b| b.is_ascii_digit()))
                || (lower.len() == 3 && lower.iter().all(|b| b.is_ascii_digit()));
            if matched {
                t = &t[..dot];
            }
        }
    }

    // 3. `/[.\-_ ](?:part|vol|r)\d+(?:\+\d+)?$/i`
    {
        let mut p = t.len();
        let d2_end = p;
        while p > 0 && t[p - 1].is_ascii_digit() {
            p -= 1;
        }
        if p < d2_end {
            if p >= 2 && t[p - 1] == b'+' && t[p - 2].is_ascii_digit() {
                p -= 1;
                while p > 0 && t[p - 1].is_ascii_digit() {
                    p -= 1;
                }
            }
            let head = &t[..p];
            for word in [&b"part"[..], b"vol", b"r"] {
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

    t
}

/// Computes `PredbMatchSelector::normalizeForComparison` on ASCII `value`.
pub fn normalize_for_comparison_ascii(value: &[u8]) -> Vec<u8> {
    let t = strip_candidate_title_suffixes(value);
    let mut out = Vec::with_capacity(t.len());
    let mut in_sep = false;
    let mut started = false;
    for &b in t {
        if b.is_ascii_alphanumeric() {
            if in_sep {
                out.push(b' ');
                in_sep = false;
            }
            out.push(b.to_ascii_lowercase());
            started = true;
        } else if started {
            in_sep = true;
        }
    }
    out
}

#[inline]
fn normalize_predb_filename_ascii(filename: &[u8]) -> Option<Vec<u8>> {
    if filename.is_empty() {
        return Some(Vec::new());
    }
    let span = clean_for_matching_span(filename, true);
    if span == -2 {
        return None;
    }
    let val = if span >= 0 {
        let start = (span >> 32) as usize;
        let len = (span & 0xFFFF_FFFF) as usize;
        if len > 0 {
            &filename[start..start + len]
        } else {
            filename
        }
    } else {
        filename
    };
    Some(normalize_for_comparison_ascii(val))
}

#[inline]
fn is_noise_or_part_token(token: &[u8]) -> bool {
    if token.len() < 2 {
        return true;
    }
    if token.len() <= 2 && token.iter().all(|b| b.is_ascii_digit()) {
        return true;
    }
    for prefix in [&b"part"[..], b"disc", b"disk", b"vol", b"dvd", b"cd"] {
        if token.len() >= prefix.len()
            && &token[..prefix.len()] == prefix
            && token[prefix.len()..].iter().all(|b| b.is_ascii_digit())
        {
            return true;
        }
    }
    matches!(
        token,
        b"a" | b"an"
            | b"and"
            | b"audio"
            | b"audio_ts"
            | b"bup"
            | b"cd"
            | b"cover"
            | b"covers"
            | b"disc"
            | b"disk"
            | b"dvd"
            | b"file"
            | b"files"
            | b"folder"
            | b"ifo"
            | b"image"
            | b"images"
            | b"img"
            | b"jpg"
            | b"jpeg"
            | b"nfo"
            | b"of"
            | b"par2"
            | b"part"
            | b"pdf"
            | b"png"
            | b"proof"
            | b"rar"
            | b"sample"
            | b"screen"
            | b"screens"
            | b"sfv"
            | b"srr"
            | b"srs"
            | b"sub"
            | b"subs"
            | b"the"
            | b"thumb"
            | b"thumbs"
            | b"txt"
            | b"url"
            | b"video"
            | b"video_ts"
            | b"vob"
            | b"vts"
            | b"webp"
            | b"zip"
    )
}

fn extract_meaningful_tokens<'a>(normalized: &'a [u8]) -> Vec<&'a [u8]> {
    if normalized.is_empty() {
        return Vec::new();
    }
    let mut tokens: Vec<&'a [u8]> = Vec::with_capacity(12);
    for tok in normalized.split(|&b| b == b' ') {
        if is_noise_or_part_token(tok) {
            continue;
        }
        if !tokens.contains(&tok) {
            tokens.push(tok);
        }
    }
    tokens
}

fn is_generic_query(norm: &[u8]) -> bool {
    if norm.iter().all(|b| b.is_ascii_digit()) {
        return true;
    }
    if matches!(norm, b"audiots" | b"audio ts" | b"videots" | b"video ts") {
        return true;
    }
    if norm.starts_with(b"vts") {
        let mut p = 3;
        if p < norm.len() && norm[p] == b' ' {
            p += 1;
        }
        let d1 = p;
        while p < norm.len() && norm[p].is_ascii_digit() {
            p += 1;
        }
        if p > d1 {
            if p == norm.len() {
                return true;
            }
            if norm[p] == b' ' {
                p += 1;
                let d2 = p;
                while p < norm.len() && norm[p].is_ascii_digit() {
                    p += 1;
                }
                if p > d2 && p == norm.len() {
                    return true;
                }
            }
        }
    }
    for prefix in [
        &b"image"[..],
        b"img",
        b"part",
        b"disc",
        b"disk",
        b"vol",
        b"dvd",
        b"cd",
    ] {
        if norm.starts_with(prefix) && norm[prefix.len()..].iter().all(|b| b.is_ascii_digit()) {
            return true;
        }
    }
    false
}

fn score_against_field(
    query: &[u8],
    query_tokens: &[&[u8]],
    field: &[u8],
    field_tokens: &[&[u8]],
) -> f64 {
    if field.is_empty() {
        return 0.0;
    }
    let similarity = similarity_percent(query, field);
    let mut shared_count = 0usize;
    let mut longest_shared = 0usize;
    for &qt in query_tokens {
        if field_tokens.contains(&qt) {
            shared_count += 1;
            longest_shared = longest_shared.max(qt.len());
        }
    }
    let overlap_ratio = if query_tokens.is_empty() {
        0.0
    } else {
        (shared_count as f64) / (query_tokens.len() as f64)
    };

    let mut score = (similarity * 0.6) + (overlap_ratio * 45.0) + ((shared_count as f64) * 6.0);

    if field == query {
        score += 80.0;
    } else if query.len() >= 8
        && field.len() >= query.len()
        && field.windows(query.len()).any(|w| w == query)
    {
        score += 18.0;
    }

    if longest_shared >= 8 {
        score += 8.0;
    }

    if shared_count == 0 {
        score -= 15.0;
    }

    score
}

#[inline]
fn has_xxx_word(normalized: &[u8]) -> bool {
    !normalized.is_empty() && normalized.split(|&b| b == b' ').any(|w| w == b"xxx")
}

// ============================================================================
// C ABI Exports
// ============================================================================

/// Computes `OriginalFilenameEvidence::canonicalNominationKeys()` for an ASCII `basename` (`input[..input_len]`).
/// Writes `\n`-separated nomination keys into `out_buf` with zero heap allocations.
/// Returns:
/// - `> 0`: bytes written
/// - `-1`: invalid (`null`)
/// - `-2`: declined to PHP fallback (non-ASCII or `__NZBSPLIT__`)
/// - `< -2`: `-(required_len as isize)` if `out_cap` is too small
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_original_filename_nomination_keys(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() || input_len == 0 {
        return -1;
    }
    let basename = unsafe { slice::from_raw_parts(input, input_len) };
    if !is_printable_ascii_no_nzbsplit(basename) {
        return -2;
    }
    if !is_valid_evidence_token(basename) || basename.len() > 8192 {
        return -1;
    }
    let dot_count = basename.iter().filter(|&&b| b == b'.').count();
    if dot_count + 1 > 128 {
        return -1;
    }

    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    let mut first = true;
    for offset in 1..=basename.len() {
        if offset != basename.len() && basename[offset] != b'.' {
            continue;
        }
        let suffix = &basename[offset..];
        if suffix.is_empty() || strip_known_file_suffixes_len(suffix, true) == Some(0) {
            if !first {
                out.push(b'\n');
            }
            first = false;
            for &b in &basename[..offset] {
                out.push(b.to_ascii_lowercase());
            }
        }
    }
    let res = out.finish();
    if res == 0 {
        return -1;
    }
    if res == -1 || res == -2 {
        return -3;
    }
    res
}

/// Computes `PredbIdentityKeys::forRow` for already-validated row `id` and `(title, filename)`.
/// Writes `\n`-separated lines of `<1|2>:<64-hex-sha256>` (66 bytes per key) into `out_buf` with zero heap allocations.
/// Returns:
/// - `> 0`: bytes written
/// - `-1`: invalid row (`null`)
/// - `-2`: declined to PHP fallback (non-ASCII or `__NZBSPLIT__`)
/// - `< -2`: `-(required_len as isize)` if `out_cap` is too small
///
/// # Safety
///
/// `title` and `filename` must be valid for reads of their lengths when non-null.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_identity_keys_for_row(
    title: *const u8,
    title_len: usize,
    filename: *const u8,
    filename_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if title.is_null() || title_len == 0 {
        return -1;
    }
    let title_bytes = unsafe { slice::from_raw_parts(title, title_len) };
    let filename_bytes: &[u8] = if filename.is_null() || filename_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(filename, filename_len) }
    };

    if !is_printable_ascii_no_nzbsplit(title_bytes)
        || !is_printable_ascii_no_nzbsplit(filename_bytes)
    {
        return -2;
    }

    if title_bytes.len() > 255
        || filename_bytes.len() > 255
        || !is_valid_evidence_token(title_bytes)
    {
        return -1;
    }

    let mut canonical = [0u8; 256];
    for (i, &b) in title_bytes.iter().enumerate() {
        canonical[i] = b.to_ascii_lowercase();
    }
    let title_digest = sha256_hex_slice(&canonical[..title_bytes.len()]);

    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    out.extend(b"1:");
    out.extend(&title_digest);

    if filename_bytes.is_empty() {
        return out.finish();
    }

    let basename = match filename_bytes
        .iter()
        .rposition(|&b| b == b'/' || b == b'\\')
    {
        Some(pos) => &filename_bytes[pos + 1..],
        None => filename_bytes,
    };
    if !is_valid_evidence_token(basename) {
        return -1;
    }
    let dot_count = basename.iter().filter(|&&b| b == b'.').count();
    if dot_count + 1 > 128 {
        return -1;
    }

    let mut lower_base = [0u8; 256];
    for (i, &b) in basename.iter().enumerate() {
        lower_base[i] = b.to_ascii_lowercase();
    }

    for offset in 1..=basename.len() {
        if offset != basename.len() && basename[offset] != b'.' {
            continue;
        }
        let suffix = &basename[offset..];
        if suffix.is_empty() || strip_known_file_suffixes_len(suffix, true) == Some(0) {
            let digest = sha256_hex_slice(&lower_base[..offset]);
            out.extend(b"\n2:");
            out.extend(&digest);
        }
    }

    out.finish()
}

/// Computes `PredbMatchSelector::similarityPercent(left, right)`.
///
/// # Safety
///
/// `left` and `right` must be valid for reads of their lengths when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_similarity_percent(
    left: *const u8,
    left_len: usize,
    right: *const u8,
    right_len: usize,
) -> f64 {
    let l_bytes: &[u8] = if left.is_null() || left_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(left, left_len) }
    };
    let r_bytes: &[u8] = if right.is_null() || right_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(right, right_len) }
    };
    similarity_percent(l_bytes, r_bytes)
}

/// Selects the best matching hit index (`0..count`) for `PredbMatchSelector::selectWithinLookup`.
/// `hits` contains `2 * count` NUL-separated slices `(title_0, filename_0, title_1, filename_1, ...)`.
/// Returns:
/// - `0..count as i32`: index of best matching hit
/// - `-1`: no match (`null`)
/// - `-2`: declined to PHP fallback (non-ASCII, control bytes, `__NZBSPLIT__`, etc.)
///
/// # Safety
///
/// `query`, `orig_basename`, and `hits` must be valid for reads of their respective lengths when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_select_best_match_index(
    query: *const u8,
    query_len: usize,
    has_orig: u32,
    orig_basename: *const u8,
    orig_len: usize,
    hits: *const u8,
    hits_len: usize,
    count: usize,
) -> i32 {
    if count == 0 {
        return -1;
    }
    if count > 50 || query.is_null() || query_len == 0 {
        return -2;
    }
    let query_bytes = unsafe { slice::from_raw_parts(query, query_len) };
    if !is_printable_ascii_no_nzbsplit(query_bytes) {
        return -2;
    }

    let orig_bytes: &[u8] = if has_orig != 0 && !orig_basename.is_null() && orig_len > 0 {
        let ob = unsafe { slice::from_raw_parts(orig_basename, orig_len) };
        if !is_printable_ascii_no_nzbsplit(ob) {
            return -2;
        }
        ob
    } else {
        &[]
    };

    let hits_bytes: &[u8] = if hits.is_null() || hits_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(hits, hits_len) }
    };

    let mut pairs: Vec<(&[u8], &[u8])> = Vec::with_capacity(count);
    let mut rem = hits_bytes;
    for idx in 0..count {
        let Some(first_nul) = find_nul(rem) else {
            return -2;
        };
        let t = &rem[..first_nul];
        rem = &rem[first_nul + 1..];

        let f = if idx + 1 < count {
            let Some(second_nul) = find_nul(rem) else {
                return -2;
            };
            let s = &rem[..second_nul];
            rem = &rem[second_nul + 1..];
            s
        } else {
            if find_nul(rem).is_some() {
                return -2;
            }
            let s = rem;
            rem = &[];
            s
        };

        if !is_printable_ascii_no_nzbsplit(t)
            || !is_printable_ascii_no_nzbsplit(f)
            || matches!(f.last(), Some(b'/' | b'\\'))
        {
            return -2;
        }
        pairs.push((t, f));
    }

    let normalized_query = normalize_for_comparison_ascii(query_bytes);
    if normalized_query.len() < 3 || is_generic_query(&normalized_query) {
        return -1;
    }
    let query_tokens = extract_meaningful_tokens(&normalized_query);
    if query_tokens.is_empty() || (query_tokens.len() == 1 && query_tokens[0].len() < 5) {
        return -1;
    }

    let mut literal_titles: Vec<usize> = Vec::new();
    let mut literal_filenames: Vec<usize> = Vec::new();
    let mut filename_titles: Vec<Vec<u8>> = Vec::new();

    for (idx, &(title, filename)) in pairs.iter().enumerate() {
        if title.is_empty() {
            continue;
        }
        if title.eq_ignore_ascii_case(query_bytes) {
            literal_titles.push(idx);
            continue;
        }
        let matched_file = if has_orig != 0 {
            original_filename_accepts_ascii(orig_bytes, title, filename)
        } else {
            let span = clean_for_matching_span(filename, true);
            if span == -2 {
                return -2;
            }
            if span >= 0 {
                let start = (span >> 32) as usize;
                let len = (span & 0xFFFF_FFFF) as usize;
                let cleaned = &filename[start..start + len];
                has_non_numeric_punct_space_ascii(cleaned)
                    && cleaned.eq_ignore_ascii_case(query_bytes)
            } else {
                false
            }
        };
        if matched_file {
            literal_filenames.push(idx);
            let mut t_lower = Vec::with_capacity(title.len());
            for &b in title {
                t_lower.push(b.to_ascii_lowercase());
            }
            if !filename_titles.contains(&t_lower) {
                filename_titles.push(t_lower);
            }
        }
    }

    if literal_titles.is_empty() && filename_titles.len() != 1 {
        return -1;
    }

    let candidates = if !literal_titles.is_empty() {
        &literal_titles
    } else {
        &literal_filenames
    };

    let query_has_xxx = has_xxx_word(&normalized_query);
    let mut best_idx: Option<usize> = None;
    let mut best_score = 0.0f64;

    for &idx in candidates {
        let (raw_title, raw_filename) = pairs[idx];
        let norm_title = normalize_for_comparison_ascii(raw_title);
        let Some(norm_filename) = normalize_predb_filename_ascii(raw_filename) else {
            return -2;
        };
        if norm_title.is_empty() && norm_filename.is_empty() {
            continue;
        }
        let title_tokens = extract_meaningful_tokens(&norm_title);
        let filename_tokens = extract_meaningful_tokens(&norm_filename);

        let s_title =
            score_against_field(&normalized_query, &query_tokens, &norm_title, &title_tokens);
        let s_file = score_against_field(
            &normalized_query,
            &query_tokens,
            &norm_filename,
            &filename_tokens,
        ) + if !norm_filename.is_empty() { 5.0 } else { 0.0 };
        let mut score = s_title.max(s_file);

        let cand_has_xxx = has_xxx_word(&norm_title) || has_xxx_word(&norm_filename);
        if query_has_xxx ^ cand_has_xxx {
            score -= 30.0;
        }
        score = score.max(0.0);

        if score > best_score {
            best_score = score;
            best_idx = Some(idx);
        }
    }

    if best_score < 60.0 {
        return -1;
    }
    match best_idx {
        Some(idx) => idx as i32,
        None => -1,
    }
}

// ============================================================================
// NZB article fingerprint, Message-ID normalization and password metadata
// ============================================================================

/// PHP `trim()` default character set: space, `\t`, `\n`, `\r`, NUL and `\x0B`.
#[inline]
fn is_php_trim_byte(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B)
}

#[inline]
fn php_trim(mut s: &[u8]) -> &[u8] {
    while let [first, rest @ ..] = s {
        if !is_php_trim_byte(*first) {
            break;
        }
        s = rest;
    }
    while let [rest @ .., last] = s {
        if !is_php_trim_byte(*last) {
            break;
        }
        s = rest;
    }
    s
}

/// `NzbArticleFingerprint::normalizeMessageId()`: trim, strip one pair of matching outer quotes (then trim),
/// strip one pair of outer angle brackets, trim.
#[inline]
fn normalize_message_id(id: &[u8]) -> &[u8] {
    let mut id = php_trim(id);
    if id.len() >= 2 {
        let (first, last) = (id[0], id[id.len() - 1]);
        if (first == b'"' && last == b'"') || (first == b'\'' && last == b'\'') {
            id = php_trim(&id[1..id.len() - 1]);
        }
    }
    if id.len() >= 2 && id[0] == b'<' && id[id.len() - 1] == b'>' {
        id = &id[1..id.len() - 1];
    }
    php_trim(id)
}

/// `NzbArticleFingerprint::validatedMessageId()`: normalized, non-empty, at most 255 bytes, no `0x00..=0x20` or `0x7F`.
#[inline]
fn validated_message_id(id: &[u8]) -> Option<&[u8]> {
    let id = normalize_message_id(id);
    if id.is_empty() || id.len() > 255 || id.iter().any(|&b| b <= 0x20 || b == 0x7F) {
        None
    } else {
        Some(id)
    }
}

/// Raw SHA-256 digest of `data` as eight big-endian words.
fn sha256_words(data: &[u8]) -> [u32; 8] {
    #[inline(always)]
    fn compress(state: &mut [u32; 8], block: &[u8; 64]) {
        let mut w = [0u32; 64];
        for (i, &chunk) in block.as_chunks::<4>().0.iter().enumerate() {
            w[i] = u32::from_be_bytes(chunk);
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
                .wrapping_add(SHA256_K[i])
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
        for (s, v) in state.iter_mut().zip([a, b, c, d, e, f, g, h]) {
            *s = s.wrapping_add(v);
        }
    }

    let mut state: [u32; 8] = [
        0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab,
        0x5be0cd19,
    ];
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
    state
}

/// FIPS 180-4 SHA-1 of `data`, as a 40-character lowercase hex string.
fn sha1_hex(data: &[u8]) -> [u8; 40] {
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

/// Normalizes one Message-ID as `NzbArticleFingerprint::normalizeMessageId()` does. The result is never longer
/// than the input.
/// Returns:
/// - `>= 0`: bytes written to `out_buf`
/// - `< 0`: `-(required_len as isize)` if `out_cap` is too small, or `isize::MIN` for a null input with a length
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_normalize_message_id(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let bytes: &[u8] = if input_len == 0 {
        &[]
    } else if input.is_null() {
        return isize::MIN;
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };
    let normalized = normalize_message_id(bytes);
    if normalized.len() > out_cap || (!normalized.is_empty() && out_buf.is_null()) {
        return -(normalized.len() as isize);
    }
    if !normalized.is_empty() {
        unsafe { std::ptr::copy_nonoverlapping(normalized.as_ptr(), out_buf, normalized.len()) };
    }
    normalized.len() as isize
}

/// Validates one Message-ID as `NzbArticleFingerprint::validatedMessageId()` does and writes the normalized ID.
/// Returns:
/// - `> 0`: bytes written to `out_buf` (the validated, normalized ID)
/// - `0`: the ID is unsupported (`null`)
/// - `< 0`: `-(required_len as isize)` if `out_cap` is too small (at most 255 bytes are ever required)
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_validate_message_id(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() || input_len == 0 {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    let Some(id) = validated_message_id(bytes) else {
        return 0;
    };
    if id.len() > out_cap || out_buf.is_null() {
        return -(id.len() as isize);
    }
    unsafe { std::ptr::copy_nonoverlapping(id.as_ptr(), out_buf, id.len()) };
    id.len() as isize
}

/// Computes `NzbArticleFingerprint`'s digest over `count` NUL-separated Message-IDs in `buf[..buf_len]`:
/// `sha1(pack('J', unique) . pack('N8', lane sums of sha256(id)))` over the sorted unique normalized IDs.
/// When `already_validated` is zero each ID is normalized and validated first.
/// Writes the 40-character lowercase hex digest to `out_hex_40` and returns `40`. Returns `0` when `count` is zero,
/// the buffer does not hold exactly `count` NUL-separated IDs, or any ID is unsupported, so the caller keeps the
/// PHP behavior.
///
/// # Safety
///
/// `buf_ptr` must be valid for reads of `buf_len` bytes when non-null and `buf_len > 0`.
/// `out_hex_40` must be valid for writes of 40 bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_article_fingerprint_batch(
    buf_ptr: *const u8,
    buf_len: usize,
    count: u32,
    already_validated: u32,
    out_hex_40: *mut u8,
) -> isize {
    if count == 0 || out_hex_40.is_null() || (buf_ptr.is_null() && buf_len > 0) {
        return 0;
    }
    let buf: &[u8] = if buf_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(buf_ptr, buf_len) }
    };

    let mut ids: Vec<&[u8]> = Vec::with_capacity(count as usize);
    for raw in buf.split(|&b| b == 0) {
        let id = if already_validated != 0 {
            if raw.is_empty() {
                return 0;
            }
            raw
        } else {
            match validated_message_id(raw) {
                Some(id) => id,
                None => return 0,
            }
        };
        if ids.len() == count as usize {
            return 0;
        }
        ids.push(id);
    }
    if ids.len() != count as usize {
        return 0;
    }

    ids.sort_unstable();
    ids.dedup();

    let mut lanes = [0u32; 8];
    for id in &ids {
        for (lane, word) in lanes.iter_mut().zip(sha256_words(id)) {
            *lane = lane.wrapping_add(word);
        }
    }

    let mut payload = [0u8; 40];
    payload[..8].copy_from_slice(&(ids.len() as u64).to_be_bytes());
    for (i, lane) in lanes.iter().enumerate() {
        payload[8 + i * 4..12 + i * 4].copy_from_slice(&lane.to_be_bytes());
    }
    let hex = sha1_hex(&payload);
    unsafe { std::ptr::copy_nonoverlapping(hex.as_ptr(), out_hex_40, 40) };
    40
}

#[inline]
fn is_valid_password(value: &[u8]) -> bool {
    if value.len() > 4096 {
        return false;
    }
    let Ok(text) = std::str::from_utf8(value) else {
        return false;
    };
    text.chars().all(|c| {
        matches!(
            c as u32,
            0x09 | 0x0A | 0x0D | 0x20..=0xD7FF | 0xE000..=0xFFFD | 0x10000..=0x10FFFF
        )
    })
}

/// Validates `NzbPasswordMetadata::isValid()` for a list of `count` NUL-separated strings in `buf[..buf_len]`:
/// at most 64 strings of at most 4096 bytes, valid UTF-8, every scalar in the XML 1.0 `Char` range without
/// `U+FFFE`/`U+FFFF`. A NUL inside a value is invalid, so callers frame by joining with NUL and a buffer that
/// does not split into exactly `count` strings is rejected.
/// Returns `1` when valid and `0` otherwise (a zero `count` with an empty buffer is valid).
///
/// # Safety
///
/// `buf_ptr` must be valid for reads of `buf_len` bytes when non-null and `buf_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_nzb_validate_passwords(
    buf_ptr: *const u8,
    buf_len: usize,
    count: u32,
) -> u32 {
    if count > 64 {
        return 0;
    }
    if count == 0 {
        return u32::from(buf_len == 0);
    }
    if buf_ptr.is_null() && buf_len > 0 {
        return 0;
    }
    let buf: &[u8] = if buf_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(buf_ptr, buf_len) }
    };
    let mut seen = 0u32;
    for value in buf.split(|&b| b == 0) {
        seen += 1;
        if seen > count || !is_valid_password(value) {
            return 0;
        }
    }
    u32::from(seen == count)
}

// ============================================================================
// Par2FileHashEvidence, ReleaseArchiveNamingEvidence, Utf8, IrcChannelList
// ============================================================================

/// Validates whether `bytes` is a canonical unsigned 64-bit base-10 integer string
/// (`0` or `[1-9][0-9]{0,19}` <= `18446744073709551615`), matching `Par2FileHashEvidence::sizeDecimal`.
#[inline]
pub fn par2_size_decimal_valid(bytes: &[u8]) -> bool {
    match bytes.len() {
        0 => false,
        1 => bytes[0].is_ascii_digit(),
        2..=19 => {
            (b'1'..=b'9').contains(&bytes[0]) && bytes[1..].iter().all(|b| b.is_ascii_digit())
        }
        20 => {
            (b'1'..=b'9').contains(&bytes[0])
                && bytes[1..].iter().all(|b| b.is_ascii_digit())
                && bytes <= b"18446744073709551615"
        }
        _ => false,
    }
}

/// Validates a string candidate for `Par2FileHashEvidence::sizeDecimal`.
/// Returns `1` when `s[..len]` is a valid base-10 `u64` string, `0` otherwise.
///
/// # Safety
///
/// `s` must be valid for reads of `len` bytes when non-null and `len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_size_decimal_valid(s: *const u8, len: usize) -> i32 {
    if s.is_null() || len == 0 || len > 20 {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(s, len) };
    i32::from(par2_size_decimal_valid(bytes))
}

/// Converts 8 little-endian bytes (`u64::from_le_bytes`) into a base-10 ASCII string in `out_buf`,
/// matching `Par2FileHashEvidence::littleEndianSize`.
/// Returns:
/// - `1..=20`: number of ASCII bytes written to `out_buf`
/// - `0`: invalid input (`bytes` is null or `len != 8`)
/// - `< 0`: `-(required_len as isize)` if `out_cap` is too small or `out_buf` is null
///
/// # Safety
///
/// `bytes` must be valid for reads of `len` bytes when non-null and `len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_le_u64_to_decimal(
    bytes: *const u8,
    len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if bytes.is_null() || len != 8 {
        return 0;
    }
    let raw = unsafe { slice::from_raw_parts(bytes, 8) };
    let mut val = u64::from_le_bytes([
        raw[0], raw[1], raw[2], raw[3], raw[4], raw[5], raw[6], raw[7],
    ]);
    let mut tmp = [0u8; 20];
    let mut pos = 20usize;
    if val == 0 {
        pos = 19;
        tmp[19] = b'0';
    } else {
        while val > 0 {
            pos -= 1;
            tmp[pos] = b'0' + (val % 10) as u8;
            val /= 10;
        }
    }
    let digits = &tmp[pos..];
    if digits.len() > out_cap || out_buf.is_null() {
        return -(digits.len() as isize);
    }
    unsafe { std::ptr::copy_nonoverlapping(digits.as_ptr(), out_buf, digits.len()) };
    digits.len() as isize
}

#[inline]
fn fxhash_bytes(bytes: &[u8]) -> usize {
    let mut h = 0xcbf29ce484222325u64;
    let (chunks, rem) = bytes.as_chunks::<8>();
    for &chunk in chunks {
        h = (h ^ u64::from_le_bytes(chunk)).wrapping_mul(0x100000001b3);
    }
    for &b in rem {
        h = (h ^ (b as u64)).wrapping_mul(0x100000001b3);
    }
    (h ^ (h >> 32)) as usize
}

/// Deduplicates and bounds `count` NUL-separated archive witness filenames in `input[..input_len]`,
/// matching `ReleaseArchiveNamingEvidence::boundedNames`.
/// Sets `*overflow_out` to `1` if any unique entry was invalid (`""`, `> 4096` bytes, or invalid UTF-8)
/// or exceeded `MAX_WITNESSES = 256` or `32768` total cumulative bytes, else `0`.
/// Writes kept names separated by NUL (`\0`) into `out_buf`.
/// Returns:
/// - `>= 0`: bytes written to `out_buf` (`0` when the bounded list is empty)
/// - `-1`: declined to PHP fallback (e.g. embedded NUL causing slice count != `count`)
/// - `< -1`: `-(required_len as isize)` if `out_cap` is too small
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `overflow_out` must be valid for writes of 1 byte when non-null.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_archive_witness_bounded_names_nul(
    input: *const u8,
    input_len: usize,
    count: usize,
    overflow_out: *mut u8,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if count == 0 {
        if input_len != 0 {
            return -1;
        }
        if !overflow_out.is_null() {
            unsafe { *overflow_out = 0 };
        }
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

    let all_utf8 = std::str::from_utf8(bytes).is_ok();
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };

    let mut seen_stack: [std::mem::MaybeUninit<&[u8]>; 512] =
        [const { std::mem::MaybeUninit::uninit() }; 512];
    let mut seen_len = 0usize;
    let use_ht = count > 24 && count <= 512;
    let mut ht = [0u16; 1024];
    let mut seen_fallback: Option<std::collections::HashSet<&[u8]>> = if count > 512 {
        Some(std::collections::HashSet::with_capacity(count))
    } else {
        None
    };

    let mut seen_slices = 0usize;
    let mut bounded_count = 0usize;
    let mut total_bytes = 0usize;
    let mut overflow = false;

    for name in bytes.split(|&b| b == 0) {
        seen_slices += 1;
        if seen_slices > count {
            return -1;
        }

        let is_new = if let Some(ref mut set) = seen_fallback {
            set.insert(name)
        } else if use_ht {
            let mut slot = fxhash_bytes(name) & 1023;
            let mut duplicate = false;
            loop {
                let entry = ht[slot];
                if entry == 0 {
                    seen_stack[seen_len].write(name);
                    seen_len += 1;
                    ht[slot] = seen_len as u16;
                    break;
                }
                let prev = unsafe { seen_stack[(entry - 1) as usize].assume_init() };
                if prev == name {
                    duplicate = true;
                    break;
                }
                slot = (slot + 1) & 1023;
            }
            !duplicate
        } else {
            let mut duplicate = false;
            for item in &seen_stack[..seen_len] {
                let prev = unsafe { item.assume_init() };
                if prev == name {
                    duplicate = true;
                    break;
                }
            }
            if !duplicate {
                seen_stack[seen_len].write(name);
                seen_len += 1;
            }
            !duplicate
        };

        if !is_new {
            continue;
        }

        if name.is_empty()
            || name.len() > 4096
            || (!all_utf8 && std::str::from_utf8(name).is_err())
        {
            overflow = true;
            continue;
        }

        total_bytes = total_bytes.saturating_add(name.len());
        if bounded_count >= 256 || total_bytes > 32768 {
            overflow = true;
            continue;
        }

        if bounded_count > 0 {
            out.push(0);
        }
        out.extend(name);
        bounded_count += 1;
    }

    if seen_slices != count {
        return -1;
    }

    if !overflow_out.is_null() {
        unsafe { *overflow_out = u8::from(overflow) };
    }

    if bounded_count == 0 {
        return 0;
    }
    let res = out.finish();
    if res < 0 {
        res.min(-2)
    } else {
        res
    }
}

#[inline]
fn skip_json_ws(bytes: &[u8], mut pos: usize) -> usize {
    while pos < bytes.len() && matches!(bytes[pos], b' ' | b'\t' | b'\n' | b'\r') {
        pos += 1;
    }
    pos
}

/// Decodes and validates a JSON list of archive witness filenames in `json[..json_len]`,
/// matching `ReleaseArchiveNamingEvidence::decodeNames`.
/// Declines (`-1`) if the JSON contains `\uXXXX` escapes or fails validation/bounding so the
/// PHP caller can either decode `\uXXXX` or raise the exact domain exception.
/// Writes validated names separated by NUL (`\0`) into `out_buf`.
/// Returns:
/// - `>= 0`: bytes written to `out_buf` (`0` for an empty JSON array `[]`)
/// - `-1`: declined or invalid/unbounded JSON
/// - `< -1`: `-(required_len as isize)` if `out_cap` is too small
///
/// # Safety
///
/// `json` must be valid for reads of `json_len` bytes when non-null and `json_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_archive_witness_decode_names_json(
    json: *const u8,
    json_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if json.is_null() || json_len == 0 {
        return -1;
    }
    let bytes = unsafe { slice::from_raw_parts(json, json_len) };
    let mut pos = skip_json_ws(bytes, 0);
    if pos >= bytes.len() || bytes[pos] != b'[' {
        return -1;
    }
    pos = skip_json_ws(bytes, pos + 1);
    if pos >= bytes.len() {
        return -1;
    }
    if bytes[pos] == b']' {
        pos = skip_json_ws(bytes, pos + 1);
        return if pos == bytes.len() { 0 } else { -1 };
    }

    if std::str::from_utf8(bytes).is_err() {
        return -1;
    }

    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    let mut seen_spans: [std::mem::MaybeUninit<(usize, usize, bool)>; 256] =
        [const { std::mem::MaybeUninit::uninit() }; 256];
    let mut seen_count = 0usize;
    let mut ht = [0u16; 512];
    let mut ht_active = false;
    let mut escaped_pool: Vec<u8> = Vec::new();
    let mut total_bytes = 0usize;

    loop {
        if pos >= bytes.len() || bytes[pos] != b'"' {
            return -1;
        }
        pos += 1;
        let str_start = pos;

        while pos < bytes.len() {
            let b = bytes[pos];
            if b == b'"' || b == b'\\' || b < 0x20 {
                break;
            }
            pos += 1;
        }
        if pos >= bytes.len() {
            return -1;
        }

        let (item_start, item_len, has_escape) = if bytes[pos] == b'"' {
            let len = pos - str_start;
            pos += 1;
            (str_start, len, false)
        } else if bytes[pos] == b'\\' {
            let prefix_len = pos - str_start;
            if prefix_len > 4096 {
                return -1;
            }
            let pool_start = escaped_pool.len();
            escaped_pool.extend_from_slice(&bytes[str_start..pos]);
            let mut s_len = prefix_len;
            loop {
                if pos >= bytes.len() {
                    return -1;
                }
                let b = bytes[pos];
                if b == b'"' {
                    pos += 1;
                    break;
                }
                if b == b'\\' {
                    pos += 1;
                    if pos >= bytes.len() {
                        return -1;
                    }
                    let esc = match bytes[pos] {
                        b'"' => b'"',
                        b'\\' => b'\\',
                        b'/' => b'/',
                        b'b' => 0x08,
                        b'f' => 0x0C,
                        b'n' => b'\n',
                        b'r' => b'\r',
                        b't' => b'\t',
                        _ => return -1,
                    };
                    if s_len >= 4096 {
                        return -1;
                    }
                    escaped_pool.push(esc);
                    s_len += 1;
                    pos += 1;
                    continue;
                }
                if b < 0x20 || s_len >= 4096 {
                    return -1;
                }
                escaped_pool.push(b);
                s_len += 1;
                pos += 1;
            }
            (pool_start, s_len, true)
        } else {
            return -1;
        };

        if item_len == 0 || item_len > 4096 {
            return -1;
        }

        total_bytes = total_bytes.saturating_add(item_len);
        if seen_count >= 256 || total_bytes > 32768 {
            return -1;
        }

        let item_slice: &[u8] = if has_escape {
            &escaped_pool[item_start..item_start + item_len]
        } else {
            &bytes[item_start..item_start + item_len]
        };

        if !ht_active && seen_count == 16 {
            ht_active = true;
            for idx in 0..seen_count {
                let (p_start, p_len, p_esc) = unsafe { seen_spans[idx].assume_init() };
                let prev = if p_esc {
                    &escaped_pool[p_start..p_start + p_len]
                } else {
                    &bytes[p_start..p_start + p_len]
                };
                let mut s = fxhash_bytes(prev) & 511;
                while ht[s] != 0 {
                    s = (s + 1) & 511;
                }
                ht[s] = (idx + 1) as u16;
            }
        }

        if ht_active {
            let mut slot = fxhash_bytes(item_slice) & 511;
            loop {
                let entry = ht[slot];
                if entry == 0 {
                    seen_spans[seen_count].write((item_start, item_len, has_escape));
                    seen_count += 1;
                    ht[slot] = seen_count as u16;
                    break;
                }
                let (p_start, p_len, p_esc) =
                    unsafe { seen_spans[(entry - 1) as usize].assume_init() };
                if p_len == item_len {
                    let prev_slice = if p_esc {
                        &escaped_pool[p_start..p_start + p_len]
                    } else {
                        &bytes[p_start..p_start + p_len]
                    };
                    if prev_slice == item_slice {
                        return -1;
                    }
                }
                slot = (slot + 1) & 511;
            }
        } else {
            for item in &seen_spans[..seen_count] {
                let (p_start, p_len, p_esc) = unsafe { item.assume_init() };
                if p_len == item_len {
                    let prev_slice = if p_esc {
                        &escaped_pool[p_start..p_start + p_len]
                    } else {
                        &bytes[p_start..p_start + p_len]
                    };
                    if prev_slice == item_slice {
                        return -1;
                    }
                }
            }
            seen_spans[seen_count].write((item_start, item_len, has_escape));
            seen_count += 1;
        }

        if seen_count > 1 {
            out.push(0);
        }
        out.extend(item_slice);

        pos = skip_json_ws(bytes, pos);
        if pos >= bytes.len() {
            return -1;
        }
        if bytes[pos] == b',' {
            pos = skip_json_ws(bytes, pos + 1);
            continue;
        }
        if bytes[pos] == b']' {
            pos = skip_json_ws(bytes, pos + 1);
            if pos != bytes.len() {
                return -1;
            }
            let res = out.finish();
            return if res < 0 { res.min(-2) } else { res };
        }
        return -1;
    }
}

/// Checks whether `input[..len]` is valid UTF-8, matching `mb_check_encoding($value, 'UTF-8')`
/// in `Utf8::clean`.
/// Returns `1` if valid UTF-8, `0` otherwise.
///
/// # Safety
///
/// `input` must be valid for reads of `len` bytes when non-null and `len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_utf8_is_valid(input: *const u8, len: usize) -> i32 {
    if len == 0 {
        return 1;
    }
    if input.is_null() {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, len) };
    i32::from(std::str::from_utf8(bytes).is_ok())
}

/// Parses an IRC channel list string (`"#chan1,#chan2:password"`) matching `IrcChannelList::parse`.
/// Writes `2 * k` NUL-separated slices (`channel_0 \0 password_0 \0 channel_1 \0 password_1 ...`)
/// into `out_buf`, where an empty `password_i` slice denotes `null`.
/// Returns:
/// - `0`: no valid channels parsed (caller returns `[$default => null]`)
/// - `> 0`: bytes written to `out_buf`
/// - `-1`: declined to PHP fallback (e.g. embedded NUL inside a trimmed channel or password)
/// - `< -1`: `-(required_len as isize)` if `out_cap` is too small
///
/// # Safety
///
/// `input` must be valid for reads of `len` bytes when non-null and `len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_irc_channel_list_parse(
    input: *const u8,
    len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if len == 0 {
        return 0;
    }
    if input.is_null() {
        return -1;
    }
    let bytes = unsafe { slice::from_raw_parts(input, len) };

    // Preserve PHP associative array insertion order when a channel key is overwritten.
    // Store `(has_hash_prefix, raw_name_without_leading_hash_if_added, password_slice)`.
    let mut entries: Vec<(bool, &[u8], &[u8])> = Vec::with_capacity(8);

    for raw_item in bytes.split(|&b| b == b',') {
        let item = php_trim(raw_item);
        if item.is_empty() {
            continue;
        }
        let (raw_name, raw_pass) = match item.iter().position(|&b| b == b':') {
            Some(colon) => (&item[..colon], Some(&item[colon + 1..])),
            None => (item, None),
        };
        let name = php_trim(raw_name);
        if name.is_empty() || name == b"#" {
            continue;
        }
        let pass: &[u8] = match raw_pass {
            Some(p) => php_trim(p),
            None => &[],
        };
        if name.contains(&0) || pass.contains(&0) {
            return -1;
        }
        let has_hash = name[0] == b'#';
        let bare = if has_hash { &name[1..] } else { name };

        // Check if this normalized channel key (`#` + `bare`) already exists.
        if let Some(existing) = entries.iter_mut().find(|(_, b, _)| *b == bare) {
            existing.2 = pass;
        } else {
            entries.push((has_hash, bare, pass));
        }
    }

    if entries.is_empty() {
        return 0;
    }

    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    for (idx, &(_has_hash, bare, pass)) in entries.iter().enumerate() {
        if idx > 0 {
            out.push(0);
        }
        out.push(b'#');
        out.extend(bare);
        out.push(0);
        out.extend(pass);
    }

    let res = out.finish();
    if res < 0 {
        res.min(-2)
    } else {
        res
    }
}

// ============================================================================
// MusicService / ConsoleService / IGDBService title parsing and matching
// ============================================================================

/// PCRE `\w` in non-UTF mode with the default C tables: `[A-Za-z0-9_]`.
#[inline]
fn mci_is_word(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_'
}

/// PCRE2 `\s` in non-UTF mode: space, `\t`, `\n`, `\x0B`, `\f`, `\r`.
#[inline]
fn mci_is_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')
}

/// The `[ |-]` separator class used throughout `MusicService::parseArtist()`.
#[inline]
fn mci_is_music_sep(b: u8) -> bool {
    matches!(b, b' ' | b'|' | b'-')
}

/// The `[\.\-_ ]` separator class used by `ConsoleService::parseTitle()`.
#[inline]
fn mci_is_console_sep(b: u8) -> bool {
    matches!(b, b'.' | b'-' | b'_' | b' ')
}

#[inline]
fn mci_byte(s: &[u8], i: usize) -> Option<u8> {
    s.get(i).copied()
}

/// ASCII case-insensitive literal match of lowercase `needle` at `s[pos..]` (PCRE `/i` without UTF).
#[inline]
fn mci_ci_at(s: &[u8], pos: usize, needle: &[u8]) -> bool {
    match s.get(pos..pos + needle.len()) {
        Some(window) => window
            .iter()
            .zip(needle)
            .all(|(&a, &b)| a.to_ascii_lowercase() == b),
        None => false,
    }
}

/// Start of the last case-insensitive occurrence of lowercase `needle` beginning at or after `from`.
#[inline]
fn mci_ci_rfind_from(s: &[u8], from: usize, needle: &[u8]) -> Option<usize> {
    if s.len() < needle.len() || from > s.len() - needle.len() {
        return None;
    }
    (from..=s.len() - needle.len())
        .rev()
        .find(|&p| mci_ci_at(s, p, needle))
}

/// PHP `stripos($haystack, $needle) !== false` for a non-empty ASCII lowercase needle.
#[inline]
fn mci_ci_contains(s: &[u8], needle: &[u8]) -> bool {
    let (lower, upper) = (needle[0], needle[0].to_ascii_uppercase());
    let mut from = 0;
    while s.len() >= from + needle.len() {
        let Some(offset) = s[from..=s.len() - needle.len()]
            .iter()
            .position(|&b| b == lower || b == upper)
        else {
            return false;
        };
        if mci_ci_at(s, from + offset + 1, &needle[1..]) {
            return true;
        }
        from += offset + 1;
    }
    false
}

#[inline]
fn mci_digit_run(s: &[u8], from: usize) -> usize {
    s.get(from..).map_or(0, |rest| {
        rest.iter().take_while(|b| b.is_ascii_digit()).count()
    })
}

#[inline]
fn mci_alpha_run(s: &[u8], from: usize) -> usize {
    s.get(from..).map_or(0, |rest| {
        rest.iter().take_while(|b| b.is_ascii_alphabetic()).count()
    })
}

/// Builds a 256-entry byte-class table from the listed bytes (and optionally ASCII digits).
const fn mci_byte_table(bytes: &[u8], digits: bool) -> [bool; 256] {
    let mut table = [false; 256];
    let mut i = 0;
    while i < bytes.len() {
        table[bytes[i] as usize] = true;
        i += 1;
    }
    if digits {
        let mut d = b'0';
        while d <= b'9' {
            table[d as usize] = true;
            d += 1;
        }
    }
    table
}

/// Bytes where the third `parseArtist()` cleanup regex can start.
const MCI_MUSIC_TAG_TABLE: [bool; 256] = mci_byte_table(b" |-(", true);
/// PCRE `\s` bytes.
const MCI_SPACE_TABLE: [bool; 256] = mci_byte_table(b" \t\n\x0B\x0C\r", false);
/// Year scan starts: digits and `(`.
const MCI_YEAR_START_TABLE: [bool; 256] = mci_byte_table(b"(", true);

/// Offset of the first byte of `s` equal to any of `targets`, scanning eight bytes at a time.
///
/// Each target's SWAR zero-byte mask can only report spurious bytes above a genuine match, so the
/// lowest set bit across all masks is always the first matching byte.
#[inline(always)]
fn mci_find_any<const N: usize>(s: &[u8], targets: [u8; N]) -> Option<usize> {
    const LOW: u64 = 0x0101_0101_0101_0101;
    const HIGH: u64 = 0x8080_8080_8080_8080;
    let (chunks, remainder) = s.as_chunks::<8>();
    let mut base = 0;
    for &chunk in chunks {
        let word = u64::from_le_bytes(chunk);
        let mut mask = 0;
        for target in targets {
            let x = word ^ (LOW * u64::from(target));
            mask |= x.wrapping_sub(LOW) & !x & HIGH;
        }
        if mask != 0 {
            return Some(base + (mask.trailing_zeros() / 8) as usize);
        }
        base += 8;
    }
    remainder
        .iter()
        .position(|b| targets.contains(b))
        .map(|p| base + p)
}

/// Whether `s` contains any of `targets`; a full (non-short-circuit) fold that vectorizes.
#[inline]
fn mci_contains_any<const N: usize>(s: &[u8], targets: [u8; N]) -> bool {
    s.iter().fold(false, |acc, b| {
        targets.iter().fold(acc, |acc, t| acc | (b == t))
    })
}

/// Generic `preg_replace` driver for a pattern without empty matches: `matcher(s, i)` returns the
/// end of the leftmost-first match starting at `i` (only tried where `find` reports a candidate
/// start offset), and scanning resumes at that end. Borrows the input when nothing matches.
fn mci_replace_all<'a>(
    s: &'a [u8],
    repl: &[u8],
    find: impl Fn(&[u8]) -> Option<usize>,
    matcher: impl Fn(&[u8], usize) -> Option<usize>,
) -> Cow<'a, [u8]> {
    let mut out: Option<Vec<u8>> = None;
    let mut copied = 0;
    let mut i = 0;
    while let Some(offset) = find(&s[i..]) {
        i += offset;
        if let Some(end) = matcher(s, i) {
            let buf = out.get_or_insert_with(|| Vec::with_capacity(s.len() + 16));
            buf.extend_from_slice(&s[copied..i]);
            buf.extend_from_slice(repl);
            i = end;
            copied = end;
        } else {
            i += 1;
        }
        if i >= s.len() {
            break;
        }
    }
    match out {
        Some(mut buf) => {
            buf.extend_from_slice(&s[copied..]);
            Cow::Owned(buf)
        }
        None => Cow::Borrowed(s),
    }
}

/// `[ |-]` candidate starts.
#[inline]
fn mci_find_music_sep(s: &[u8]) -> Option<usize> {
    mci_find_any(s, *b" |-")
}

/// PCRE `\s` candidate starts.
#[inline]
fn mci_find_space(s: &[u8]) -> Option<usize> {
    mci_find_any(s, *b" \t\n\x0B\x0C\r")
}

/// Candidate starts for a byte-class table.
#[inline]
fn mci_find_in(table: &[bool; 256]) -> impl Fn(&[u8]) -> Option<usize> + '_ {
    move |s: &[u8]| s.iter().position(|&b| table[b as usize])
}

/// The optional `(\d{1,2} \d{1,2} )` group at `p`; greedy `\d{1,2}` can only succeed one way.
#[inline]
fn mci_dd_group(s: &[u8], p: usize) -> Option<usize> {
    let d1 = mci_digit_run(s, p);
    if d1 == 0 || d1 > 2 || mci_byte(s, p + d1) != Some(b' ') {
        return None;
    }
    let q = p + d1 + 1;
    let d2 = mci_digit_run(s, q);
    if d2 == 0 || d2 > 2 || mci_byte(s, q + d2) != Some(b' ') {
        return None;
    }
    Some(q + d2 + 1)
}

// ---------------------------------------------------------------------------
// IGDBService::normalizeForMatch / extractBaseTitle / computeSimilarity
// ---------------------------------------------------------------------------

/// Length of the first `normalizeForMatch()` edition phrase (in PCRE alternation order) at the start
/// of `rest`, followed by a `\b`. `rest` starts at a word boundary.
#[inline]
fn igdb_edition_phrase_len(rest: &[u8]) -> Option<usize> {
    let candidates: &[&[u8]] = match rest[0].to_ascii_lowercase() {
        b'g' => &[b"game of the year", b"goty"],
        b'd' => &[
            b"definitive edition",
            b"deluxe edition",
            b"directors cut",
            b"director cut",
            b"dlcs",
            b"dlc",
        ],
        b'u' => &[b"ultimate edition", b"update"],
        b'c' => &[b"complete edition", b"crackfix", b"crack"],
        b'r' => &[b"remastered", b"repack", b"rip"],
        b'h' => &[b"hd remaster", b"hotfix"],
        b'a' => &[b"anniversary edition", b"alpha"],
        b'p' => &[b"patch"],
        b'i' => &[
            b"including dlcs",
            b"including dlc",
            b"incl dlcs",
            b"incl dlc",
            b"iso",
        ],
        b'b' => &[b"beta"],
        _ => return None,
    };
    candidates.iter().find_map(|phrase| {
        (mci_ci_at(rest, 0, phrase) && rest.get(phrase.len()).is_none_or(|&b| !mci_is_word(b)))
            .then_some(phrase.len())
    })
}

/// Whether a lowercase `\w+` run is one of the second `normalizeForMatch()` alternation's tokens.
#[inline]
fn igdb_is_platform_lang_token(run: &[u8]) -> bool {
    if run.len() >= 4 && mci_ci_at(run, 0, b"mult") {
        return igdb_is_mult_token(run);
    }
    if run.len() > 10 {
        return false;
    }
    let mut buf = [0u8; 10];
    for (slot, &b) in buf.iter_mut().zip(run) {
        *slot = b.to_ascii_lowercase();
    }
    matches!(
        &buf[..run.len()],
        b"pc"
            | b"gog"
            | b"steam"
            | b"x64"
            | b"x86"
            | b"win64"
            | b"win32"
            | b"eng"
            | b"english"
            | b"fr"
            | b"french"
            | b"de"
            | b"german"
            | b"es"
            | b"spanish"
            | b"it"
            | b"italian"
            | b"pt"
            | b"ptbr"
            | b"portuguese"
            | b"ru"
            | b"russian"
            | b"pl"
            | b"polish"
            | b"tr"
            | b"turkish"
            | b"nl"
            | b"dutch"
            | b"se"
            | b"swedish"
            | b"no"
            | b"norwegian"
            | b"da"
            | b"danish"
            | b"fi"
            | b"finnish"
            | b"jp"
            | b"japanese"
            | b"cn"
            | b"chs"
            | b"cht"
            | b"ko"
            | b"korean"
    )
}

/// `IGDBService::normalizeForMatch()`.
///
/// Returns `None` (PHP fallback) for invalid UTF-8 and for the only code points whose
/// `mb_strtolower()` mapping yields ASCII (U+0130 and U+212A). Every other non-ASCII byte is a
/// non-word, non-alphanumeric separator for the byte-mode regexes.
///
/// Both keyword replacements produce a non-word space at `\b` boundaries, so the second pass sees
/// exactly the `\w+` runs the first pass left intact; `[^a-z0-9]+` -> ' ', the `\s{2,}` collapse and
/// trim then leave the surviving alphanumeric segments joined by single spaces.
pub fn igdb_normalize_for_match(title: &[u8]) -> Option<Vec<u8>> {
    let mut out = Vec::with_capacity(title.len());
    igdb_normalize_for_match_into(title, &mut out).then_some(out)
}

/// [`igdb_normalize_for_match`] appending to `out`; returns `false` to decline.
pub fn igdb_normalize_for_match_into(title: &[u8], out: &mut Vec<u8>) -> bool {
    if !title.is_ascii() {
        let Ok(text) = std::str::from_utf8(title) else {
            return false;
        };
        if text.contains('\u{0130}') || text.contains('\u{212A}') {
            return false;
        }
    }
    let n = title.len();
    let base = out.len();
    let mut i = 0;
    while i < n {
        if !mci_is_word(title[i]) {
            i += 1;
            continue;
        }
        if let Some(len) = igdb_edition_phrase_len(&title[i..]) {
            i += len;
            continue;
        }
        let start = i;
        while i < n && mci_is_word(title[i]) {
            i += 1;
        }
        let run = &title[start..i];
        if igdb_is_platform_lang_token(run) {
            continue;
        }
        for segment in run.split(|&b| b == b'_').filter(|seg| !seg.is_empty()) {
            if out.len() > base {
                out.push(b' ');
            }
            out.extend(segment.iter().map(u8::to_ascii_lowercase));
        }
    }
    true
}

/// `mult[iy]?\d*` (case-insensitive) matching a whole `\w+` run.
#[inline]
fn igdb_is_mult_token(run: &[u8]) -> bool {
    if !mci_ci_at(run, 0, b"mult") {
        return false;
    }
    let rest = &run[4..];
    let rest = match rest.first() {
        Some(b'i' | b'I' | b'y' | b'Y') => &rest[1..],
        _ => rest,
    };
    rest.iter().all(u8::is_ascii_digit)
}

/// First bytes of the `Episode|Chapter|Part` and `Vol` keywords in `extractBaseTitle()`.
const MCI_BASE_TITLE_KEYWORD_TABLE: [bool; 256] = mci_byte_table(b"eEcCpPvV", false);

/// `IGDBService::extractBaseTitle()` as a `(start, len)` window of the input.
///
/// Returns `None` (PHP fallback) when the title contains `\n` or `\r`, where `.` and `$` would
/// depend on the PCRE newline convention.
pub fn igdb_extract_base_title_span(title: &[u8]) -> Option<(usize, usize)> {
    if mci_contains_any(title, *b"\n\r") {
        return None;
    }
    let mut s = title;

    // `/\s*[-:]\s+.*$/`: the first `[-:]` followed by whitespace, cut before its leading whitespace.
    let mut from = 0;
    while let Some(offset) = mci_find_any(&s[from..], *b"-:") {
        let p = from + offset;
        if s.get(p + 1).is_some_and(|&b| MCI_SPACE_TABLE[b as usize]) {
            let mut cut = p;
            while cut > 0 && MCI_SPACE_TABLE[s[cut - 1] as usize] {
                cut -= 1;
            }
            s = &s[..cut];
            break;
        }
        from = p + 1;
    }

    // `/\s+(?:Episode|Chapter|Part)\s+\d+.*/i` then `/\s+(?:Vol(?:ume)?\.?\s*\d+).*/i`. Both cut at
    // the start of the whitespace run before their keyword, so the first run that satisfies either
    // pattern is the final cut: a later match of one cannot survive the other's earlier cut, and
    // neither pattern's required span can contain the other's keyword run.
    let mut i = 0;
    while let Some(offset) = s[i..].iter().position(|&b| MCI_SPACE_TABLE[b as usize]) {
        let run_start = i + offset;
        let mut r = run_start + 1;
        while r < s.len() && MCI_SPACE_TABLE[s[r] as usize] {
            r += 1;
        }
        if r < s.len()
            && MCI_BASE_TITLE_KEYWORD_TABLE[s[r] as usize]
            && igdb_base_title_keyword_at(s, r)
        {
            s = &s[..run_start];
            break;
        }
        i = r;
    }

    // `/\s+\d+$/`
    let digits = s.iter().rev().take_while(|b| b.is_ascii_digit()).count();
    if digits > 0 {
        let run_end = s.len() - digits;
        let mut cut = run_end;
        while cut > 0 && MCI_SPACE_TABLE[s[cut - 1] as usize] {
            cut -= 1;
        }
        if cut < run_end {
            s = &s[..cut];
        }
    }

    let trimmed = php_trim(s);
    let start = trimmed.as_ptr() as usize - title.as_ptr() as usize;
    Some((start, trimmed.len()))
}

/// Whether `extractBaseTitle()`'s episode or volume pattern continues from keyword position `r`.
#[inline]
fn igdb_base_title_keyword_at(s: &[u8], r: usize) -> bool {
    let digit_after_spaces = |q: usize, min_spaces: usize| {
        let ws = s.get(q..).map_or(0, |rest| {
            rest.iter()
                .take_while(|&&b| MCI_SPACE_TABLE[b as usize])
                .count()
        });
        ws >= min_spaces && s.get(q + ws).is_some_and(u8::is_ascii_digit)
    };
    for kw in [b"episode".as_slice(), b"chapter", b"part"] {
        if mci_ci_at(s, r, kw) && digit_after_spaces(r + kw.len(), 1) {
            return true;
        }
    }
    if !mci_ci_at(s, r, b"vol") {
        return false;
    }
    let tail = |mut q: usize| {
        if s.get(q) == Some(&b'.') {
            q += 1;
        }
        digit_after_spaces(q, 0)
    };
    (mci_ci_at(s, r + 3, b"ume") && tail(r + 6)) || tail(r + 3)
}

/// PHP 8 `levenshtein($a, $b)` with unit costs via Hyyrö's bit-parallel algorithm over the shorter
/// string (one 64-bit word, or blocks with horizontal carries for longer patterns).
pub fn php_levenshtein(a: &[u8], b: &[u8]) -> usize {
    let (pattern, text) = if a.len() <= b.len() { (a, b) } else { (b, a) };
    if pattern.is_empty() {
        return text.len();
    }
    if pattern.len() <= 64 {
        let mut peq = [0u64; 256];
        for (i, &c) in pattern.iter().enumerate() {
            peq[c as usize] |= 1u64 << i;
        }
        let last = 1u64 << (pattern.len() - 1);
        let mut pv = u64::MAX;
        let mut mv = 0u64;
        let mut score = pattern.len();
        for &c in text {
            let eq = peq[c as usize];
            let xv = eq | mv;
            let xh = ((eq & pv).wrapping_add(pv) ^ pv) | eq;
            let mut ph = mv | !(xh | pv);
            let mut mh = pv & xh;
            if ph & last != 0 {
                score += 1;
            } else if mh & last != 0 {
                score -= 1;
            }
            ph = (ph << 1) | 1;
            mh <<= 1;
            pv = mh | !(xv | ph);
            mv = ph & xv;
        }
        return score;
    }
    php_levenshtein_blocks(pattern, text)
}

/// Multi-word Hyyrö (2003) Levenshtein distance for patterns longer than 64 bytes.
fn php_levenshtein_blocks(pattern: &[u8], text: &[u8]) -> usize {
    let words = pattern.len().div_ceil(64);
    let mut peq = vec![0u64; words * 256];
    for (i, &c) in pattern.iter().enumerate() {
        peq[(c as usize) * words + i / 64] |= 1u64 << (i % 64);
    }
    let mut vp = vec![u64::MAX; words];
    let mut vn = vec![0u64; words];
    let last = 1u64 << ((pattern.len() - 1) % 64);
    let mut score = pattern.len();
    for &c in text {
        let eq_row = &peq[(c as usize) * words..(c as usize + 1) * words];
        let mut hp_carry = 1u64;
        let mut hn_carry = 0u64;
        for w in 0..words {
            let x = eq_row[w] | hn_carry;
            let d0 = ((x & vp[w]).wrapping_add(vp[w]) ^ vp[w]) | x | vn[w];
            let mut hp = vn[w] | !(d0 | vp[w]);
            let mut hn = d0 & vp[w];
            if w == words - 1 {
                if hp & last != 0 {
                    score += 1;
                } else if hn & last != 0 {
                    score -= 1;
                }
            }
            let hp_in = hp_carry;
            let hn_in = hn_carry;
            hp_carry = hp >> 63;
            hn_carry = hn >> 63;
            hp = (hp << 1) | hp_in;
            hn = (hn << 1) | hn_in;
            vp[w] = hn | !(d0 | hp);
            vn[w] = hp & d0;
        }
    }
    score
}

/// `IGDBService::computeSimilarity()`: `max(similar_text percent, Levenshtein percent)`.
pub fn igdb_compute_similarity(a: &[u8], b: &[u8]) -> f64 {
    if a == b {
        return 100.0;
    }
    let percent = similarity_percent(a, b);
    let len = a.len().max(b.len());
    let lev_score = if len > 0 {
        (1.0 - (php_levenshtein(a, b) as f64 / len as f64)) * 100.0
    } else {
        0.0
    };
    if lev_score > percent {
        lev_score
    } else {
        percent
    }
}

#[inline]
unsafe fn mci_input<'a>(ptr: *const u8, len: usize) -> Option<&'a [u8]> {
    if len == 0 {
        Some(&[])
    } else if ptr.is_null() {
        None
    } else {
        Some(unsafe { slice::from_raw_parts(ptr, len) })
    }
}

thread_local! {
    static MCI_OUT: std::cell::RefCell<Vec<u8>> = std::cell::RefCell::new(Vec::with_capacity(1024));
}

/// Runs `write` into the thread-local output buffer and returns it NUL-terminated (PHP FFI converts
/// a `const char *` return into a string), or NULL when `write` declines. The pointer stays valid
/// until the next call on the same thread; `write` must not emit NUL bytes.
fn mci_cstr_result(write: impl FnOnce(&mut Vec<u8>) -> bool) -> *const c_char {
    MCI_OUT.with(|cell| {
        let mut out = cell.borrow_mut();
        out.clear();
        if !write(&mut out) {
            return std::ptr::null();
        }
        out.push(0);
        out.as_ptr().cast()
    })
}

/// Maximum title length `nntmux_igdb_normalize_for_match` handles natively.
const MCI_MAX_TITLE: usize = 4096;

/// `IGDBService::normalizeForMatch()` as a NUL-terminated thread-local string, or NULL to decline.
///
/// # Safety
///
/// `input` must be valid for `input_len` reads when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_igdb_normalize_for_match(
    input: *const u8,
    input_len: usize,
) -> *const c_char {
    let Some(title) = (unsafe { mci_input(input, input_len) }) else {
        return std::ptr::null();
    };
    if title.len() > MCI_MAX_TITLE {
        return std::ptr::null();
    }
    mci_cstr_result(|out| igdb_normalize_for_match_into(title, out))
}

/// `IGDBService::extractBaseTitle()` as a NUL-terminated thread-local string, or NULL to decline
/// (including titles containing NUL, which the C-string result cannot carry).
///
/// # Safety
///
/// `input` must be valid for `input_len` reads when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_igdb_extract_base_title(
    input: *const u8,
    input_len: usize,
) -> *const c_char {
    let Some(title) = (unsafe { mci_input(input, input_len) }) else {
        return std::ptr::null();
    };
    if title.len() > MCI_MAX_TITLE || mci_contains_any(title, [0]) {
        return std::ptr::null();
    }
    mci_cstr_result(|out| match igdb_extract_base_title_span(title) {
        Some((start, len)) => {
            out.extend_from_slice(&title[start..start + len]);
            true
        }
        None => false,
    })
}

/// `IGDBService::computeSimilarity()`; returns `-1.0` for an invalid pointer.
///
/// # Safety
///
/// `a` and `b` must be valid for reads of their lengths when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_igdb_compute_similarity(
    a: *const u8,
    a_len: usize,
    b: *const u8,
    b_len: usize,
) -> f64 {
    match unsafe { (mci_input(a, a_len), mci_input(b, b_len)) } {
        (Some(a), Some(b)) => igdb_compute_similarity(a, b),
        _ => -1.0,
    }
}

/// Scores `count` NUL-separated candidate names against `query` for `IGDBService::findBestMatch()`:
/// `out_scores[i] = computeSimilarity(normalizeForMatch(query), normalizeForMatch(candidate_i))`.
///
/// Returns `count`, or `-1` to decline to PHP (segment count mismatch or a normalization fallback).
///
/// # Safety
///
/// `query` and `candidates` must be valid for reads of their lengths when non-null, and `out_scores`
/// must be valid for `count` `f64` writes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_igdb_score_candidates_batch_nul(
    query: *const u8,
    query_len: usize,
    candidates: *const u8,
    candidates_len: usize,
    count: usize,
    out_scores: *mut f64,
) -> isize {
    if count == 0 || out_scores.is_null() || count > isize::MAX as usize {
        return -1;
    }
    let (Some(query), Some(candidates)) = (unsafe {
        (
            mci_input(query, query_len),
            mci_input(candidates, candidates_len),
        )
    }) else {
        return -1;
    };
    let Some(normalized_query) = igdb_normalize_for_match(query) else {
        return -1;
    };
    if candidates.iter().filter(|&&b| b == 0).count() != count - 1 {
        return -1;
    }
    let scores = unsafe { slice::from_raw_parts_mut(out_scores, count) };
    for (slot, name) in scores.iter_mut().zip(candidates.split(|&b| b == 0)) {
        let Some(normalized) = igdb_normalize_for_match(name) else {
            return -1;
        };
        *slot = igdb_compute_similarity(&normalized_query, &normalized);
    }
    count as isize
}

// ---------------------------------------------------------------------------
// MusicService::parseArtist
// ---------------------------------------------------------------------------

/// Maximum input length handled natively; longer inputs keep PHP and its PCRE limits.
const MCI_MAX_RELEASE_NAME: usize = 1024;

/// `(.+?)(\d{1,2} \d{1,2} )?\(?(19\d{2}|20[0-1][\d])\b`: returns `(name1_end, year_start)`.
fn music_year_match(s: &[u8]) -> Option<(usize, usize)> {
    let year_at = |q: usize| -> bool {
        let Some(y) = s.get(q..q + 4) else {
            return false;
        };
        let year = (y[0] == b'1' && y[1] == b'9' && y[2].is_ascii_digit() && y[3].is_ascii_digit())
            || (y[0] == b'2'
                && y[1] == b'0'
                && matches!(y[2], b'0' | b'1')
                && y[3].is_ascii_digit());
        year && s.get(q + 4).is_none_or(|&b| !mci_is_word(b))
    };
    let mut k = 0;
    while let Some(offset) = s
        .get(k + 1..)?
        .iter()
        .position(|&b| MCI_YEAR_START_TABLE[b as usize])
    {
        k += 1 + offset;
        for p in [mci_dd_group(s, k), Some(k)].into_iter().flatten() {
            if s[p..].first() == Some(&b'(') && year_at(p + 1) {
                return Some((k, p + 1));
            }
            if year_at(p) {
                return Some((k, p));
            }
        }
    }
    None
}

/// `.+X` greedy tail: end of the last lowercase `needle` occurrence starting at or after `from`.
#[inline]
fn mci_greedy_until(s: &[u8], from: usize, needle: &[u8]) -> Option<usize> {
    mci_ci_rfind_from(s, from, needle).map(|p| p + needle.len())
}

/// Keyword alternation of the first `parseArtist()` cleanup regex at `q`, dispatched on the first
/// byte (alternatives with different first bytes cannot both match, so PCRE order is kept per byte).
fn music_edition_keyword(s: &[u8], q: usize) -> Option<usize> {
    let lit = |kws: &[&[u8]]| {
        kws.iter()
            .find(|kw| mci_ci_at(s, q, kw))
            .map(|kw| q + kw.len())
    };
    let greedy = |head: &[u8], tail: &[u8]| {
        if mci_ci_at(s, q, head) {
            mci_greedy_until(s, q + head.len() + 1, tail)
        } else {
            None
        }
    };
    match s.get(q)?.to_ascii_lowercase() {
        b'b' => lit(&[b"bootleg", b"boxset"]),
        b'c' => greedy(b"clean", b"version")
            .or_else(|| (mci_ci_at(s, q, b"compiled by") && q + 11 < s.len()).then_some(s.len())),
        b'0'..=b'9' => mci_ci_at(s, q + 1, b"cd").then_some(q + 3),
        b'd' => lit(&[b"digipak", b"dirfix", b"dvbs"])
            .or_else(|| greedy(b"deluxe", b"edition"))
            .or_else(|| greedy(b"deluxe", b"version")),
        b'f' => lit(&[b"flac"]),
        b'l' => {
            if mci_ci_at(s, q, b"ltd ") {
                let r = q + 4;
                for kw in [b"deluxe".as_slice(), b"limited", b"special"] {
                    if mci_ci_at(s, r, kw)
                        && let Some(end) = mci_greedy_until(s, r + kw.len() + 1, b"edition")
                    {
                        return Some(end);
                    }
                }
            }
            greedy(b"limited", b"edition")
        }
        b's' => greedy(b"special", b"edition")
            .or_else(|| lit(&[b"sacd", b"sampler", b"sat"]))
            .or_else(|| greedy(b"summer", b"mag")),
        b'p' => lit(&[b"promo", b"proof"]),
        b'r' => lit(&[b"reissue", b"remastered", b"repack"]).or_else(|| {
            mci_ci_at(s, q, b"retail").then(|| mci_greedy_until(s, q + 7, b"uk").unwrap_or(q + 6))
        }),
        b'u' => greedy(b"uk", b"import"),
        b'v' => lit(&[b"vinyl"]),
        b'w' => lit(&[b"web"]),
        _ => None,
    }
}

/// Keyword alternation of the second `parseArtist()` cleanup regex at `p`.
fn music_catalog_keyword(s: &[u8], p: usize) -> Option<usize> {
    let n = s.len();
    // `[a-z]+[\d]+[a-z]+[\d]+.+`
    let l1 = mci_alpha_run(s, p);
    if l1 > 0 {
        let d1 = mci_digit_run(s, p + l1);
        if d1 > 0 {
            let l2 = mci_alpha_run(s, p + l1 + d1);
            let q3 = p + l1 + d1 + l2;
            if l2 > 0 && s.get(q3).is_some_and(u8::is_ascii_digit) && n - q3 >= 2 {
                return Some(n);
            }
        }
    }
    // `[a-z]{2,}[\d]{2,}?.+`
    if l1 >= 2 {
        let q = p + l1;
        if mci_digit_run(s, q) >= 2 && q + 2 < n {
            return Some(n);
        }
    }
    let first = s.get(p)?.to_ascii_lowercase();
    let literals: &[&[u8]] = match first {
        b'3' => &[b"3fm"],
        b'b' => {
            if mci_ci_at(s, p, b"b00") {
                let run = s[p + 3..]
                    .iter()
                    .take_while(|b| b.is_ascii_alphanumeric())
                    .count();
                if run > 0 {
                    return Some(p + 3 + run);
                }
            }
            &[b"brc482012"]
        }
        b'h' => &[b"h056"],
        b'u' => &[b"uxm1dw086"],
        _ => &[],
    };
    if let Some(kw) = literals.iter().find(|kw| mci_ci_at(s, p, kw)) {
        return Some(p + kw.len());
    }
    let labels: &[&[u8]] = match first {
        b'4' => &[b"4wcd"],
        b'a' => &[b"atl"],
        b'b' => &[b"bigfm"],
        b'c' => &[b"cdp"],
        b'd' => &[b"dst"],
        b'e' => &[b"ere"],
        b'f' => &[b"fim"],
        b'm' => &[b"mbzz", b"msone", b"mvrd"],
        b'q' => &[b"qedcd"],
        b'r' => &[b"rnb"],
        b's' => &[b"sbd", b"sft"],
        b'z' => &[b"zyx"],
        _ => return None,
    };
    for kw in labels {
        let r = p + kw.len();
        if mci_ci_at(s, p, kw)
            && s.get(r).is_some_and(|&b| mci_is_music_sep(b))
            && s.get(r + 1).is_some_and(u8::is_ascii_digit)
            && r + 2 < n
        {
            return Some(n);
        }
    }
    None
}

/// Third `parseArtist()` cleanup regex (five top-level branches) at `i`.
fn music_tag_branch(s: &[u8], i: usize) -> Option<usize> {
    let n = s.len();
    let c = s[i];
    if mci_is_music_sep(c) {
        // `([ |-])(\d{1,2} \d{1,2} )?([A-Z])( ?$)`
        for start in [mci_dd_group(s, i + 1), Some(i + 1)].into_iter().flatten() {
            if s.get(start).is_some_and(u8::is_ascii_alphabetic) {
                let r = start + 1;
                if r == n || (s[r] == b' ' && r + 1 == n) {
                    return Some(n);
                }
            }
        }
    }
    // `\(?[\d]{8,}\)?`
    let digits_from = if c == b'(' { i + 1 } else { i };
    let d = mci_digit_run(s, digits_from);
    if d >= 8 {
        let end = digits_from + d;
        return Some(if s.get(end) == Some(&b')') {
            end + 1
        } else {
            end
        });
    }
    if mci_is_music_sep(c) {
        for kw in [
            b"cable".as_slice(),
            b"freeweb",
            b"line",
            b"mag",
            b"mcd",
            b"ymrsmiles",
        ] {
            if mci_ci_at(s, i + 1, kw) {
                return Some(i + 1 + kw.len());
            }
        }
    }
    if c == b'(' {
        let l = mci_alpha_run(s, i + 1);
        if l >= 2 {
            let dd = mci_digit_run(s, i + 1 + l);
            let end = i + 1 + l + dd;
            if dd >= 2 && s.get(end) == Some(&b')') {
                return Some(end + 1);
            }
        }
        if mci_ci_at(s, i + 1, b"ost") && s.get(i + 4) == Some(&b')') {
            return Some(i + 5);
        }
    }
    if mci_ci_at(s, i, b"-web-") {
        return Some(i + 5);
    }
    None
}

/// `([ |-])(\d{1,2} \d{1,2} )?(KEYWORDS)([ |-])` for the two trailing-separator cleanup regexes;
/// `keywords_for` returns the alternatives (in PCRE order) that start with a lowercase byte.
fn music_sep_keyword_sep(
    s: &[u8],
    i: usize,
    keywords_for: fn(u8) -> &'static [&'static [u8]],
) -> Option<usize> {
    for start in [mci_dd_group(s, i + 1), Some(i + 1)].into_iter().flatten() {
        let Some(&first) = s.get(start) else {
            continue;
        };
        for kw in keywords_for(first.to_ascii_lowercase()) {
            let r = start + kw.len();
            if mci_ci_at(s, start, kw) && s.get(r).is_some_and(|&b| mci_is_music_sep(b)) {
                return Some(r + 1);
            }
        }
    }
    None
}

/// `(DAB|DE|DVBC|EP|FIX|IT|Jap|NL|PL|(Pure )?FM|SSL|VLS)` by first byte.
fn music_broadcast_keywords(first: u8) -> &'static [&'static [u8]] {
    match first {
        b'd' => &[b"dab", b"de", b"dvbc"],
        b'e' => &[b"ep"],
        b'f' => &[b"fix", b"fm"],
        b'i' => &[b"it"],
        b'j' => &[b"jap"],
        b'n' => &[b"nl"],
        b'p' => &[b"pl", b"pure fm"],
        b's' => &[b"ssl"],
        b'v' => &[b"vls"],
        _ => &[],
    }
}

/// `(CABLE|CD(A|EP|M|R|S)?|QEDCD|SAT|SBD)` by first byte.
fn music_media_keywords(first: u8) -> &'static [&'static [u8]] {
    match first {
        b'c' => &[b"cable", b"cda", b"cdep", b"cdm", b"cdr", b"cds", b"cd"],
        b'q' => &[b"qedcd"],
        b's' => &[b"sat", b"sbd"],
        _ => &[],
    }
}

/// `(year, name)` from [`music_parse_artist`].
pub type MusicArtistParts = (Vec<u8>, Vec<u8>);

/// `MusicService::parseArtist()`: `Ok(Some((year, name)))`, `Ok(None)` for `false`, `Err(())` to decline.
#[allow(clippy::result_unit_err)]
pub fn music_parse_artist(release_name: &[u8]) -> Result<Option<MusicArtistParts>, ()> {
    let mut out = Vec::new();
    Ok(music_parse_artist_into(release_name, &mut out)?
        .then(|| (out[..4].to_vec(), out[4..].to_vec())))
}

/// [`music_parse_artist`] appending `year . name` to `out`: `Ok(false)` for PHP `false`, `Err(())` to
/// decline (including NUL bytes, which the C-string result cannot carry).
#[allow(clippy::result_unit_err)]
pub fn music_parse_artist_into(release_name: &[u8], out: &mut Vec<u8>) -> Result<bool, ()> {
    if release_name.len() > MCI_MAX_RELEASE_NAME || mci_contains_any(release_name, *b"\n\r\0") {
        return Err(());
    }
    let Some((name_end, year_start)) = music_year_match(release_name) else {
        return Ok(false);
    };
    let year = &release_name[year_start..year_start + 4];
    let name = &release_name[..name_end];

    let a = mci_replace_all(name, b" ", mci_find_music_sep, |s, i| {
        [mci_dd_group(s, i + 1), Some(i + 1)]
            .into_iter()
            .flatten()
            .find_map(|start| music_edition_keyword(s, start))
    });
    let b = mci_replace_all(&a, b" ", mci_find_music_sep, |s, i| {
        music_catalog_keyword(s, i + 1)
    });
    let c = mci_replace_all(
        &b,
        b" ",
        mci_find_in(&MCI_MUSIC_TAG_TABLE),
        music_tag_branch,
    );
    let d = mci_replace_all(
        &c,
        b"Various Artists ",
        |s| mci_find_any(s, *b"V"),
        |s, i| {
            (s[i] == b'V'
                && s.get(i + 1) == Some(&b'A')
                && s.get(i + 2).is_some_and(|&b| mci_is_music_sep(b)))
            .then_some(i + 3)
        },
    );
    let e = mci_replace_all(&d, b" ", mci_find_music_sep, |s, i| {
        music_sep_keyword_sep(s, i, music_broadcast_keywords)
    });
    let f = mci_replace_all(&e, b" ", mci_find_music_sep, |s, i| {
        music_sep_keyword_sep(s, i, music_media_keywords)
    });

    // `str_replace(['_', '-'], ' ')`, `/\s\s+/` -> ' ', trim.
    let mut h = Vec::with_capacity(f.len());
    let mut i = 0;
    while i < f.len() {
        let Some(offset) = mci_find_any(&f[i..], *b" \t\n\x0B\x0C\r_-") else {
            h.extend_from_slice(&f[i..]);
            break;
        };
        h.extend_from_slice(&f[i..i + offset]);
        i += offset;
        let start = i;
        while i < f.len() && (mci_is_space(f[i]) || f[i] == b'_' || f[i] == b'-') {
            i += 1;
        }
        h.push(if i - start >= 2 || matches!(f[start], b'_' | b'-') {
            b' '
        } else {
            f[start]
        });
    }
    let h = php_trim(&h);

    // `/ [a-z]{2}$| [a-z]{3} \d{2,}$|\d{5,} \d{5,}$|-WEB$/i` -> '', trim.
    let n = h.len();
    let suffix_at = |s: usize| -> bool {
        match h[s] {
            b' ' => {
                (n - s == 3 && mci_alpha_run(h, s + 1) >= 2)
                    || (n - s >= 7
                        && h[s + 1..s + 4].iter().all(u8::is_ascii_alphabetic)
                        && h[s + 4] == b' '
                        && h[s + 5..].iter().all(u8::is_ascii_digit))
            }
            b'0'..=b'9' => {
                let d1 = mci_digit_run(h, s);
                d1 >= 5
                    && h.get(s + d1) == Some(&b' ')
                    && h.len() - (s + d1 + 1) >= 5
                    && h[s + d1 + 1..].iter().all(u8::is_ascii_digit)
            }
            b'-' => n - s == 4 && mci_ci_at(h, s, b"-web"),
            _ => false,
        }
    };
    let cut = (0..n)
        .find(|&s| matches!(h[s], b' ' | b'0'..=b'9' | b'-') && suffix_at(s))
        .unwrap_or(n);
    let newname = php_trim(&h[..cut]);

    if newname.len() > 10 && !newname.iter().all(u8::is_ascii_alphanumeric) {
        out.extend_from_slice(year);
        out.extend_from_slice(newname);
        Ok(true)
    } else {
        Ok(false)
    }
}

/// `MusicService::parseArtist()` as a NUL-terminated thread-local `year (4 bytes) . name` string:
/// an empty string for PHP `false`, NULL to decline.
///
/// # Safety
///
/// `input` must be valid for `input_len` reads when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_music_parse_artist(
    input: *const u8,
    input_len: usize,
) -> *const c_char {
    let Some(release_name) = (unsafe { mci_input(input, input_len) }) else {
        return std::ptr::null();
    };
    mci_cstr_result(|out| music_parse_artist_into(release_name, out).is_ok())
}

// ---------------------------------------------------------------------------
// ConsoleService::parseTitle / replacePlatform
// ---------------------------------------------------------------------------

/// Whether the release-tag alternation of the `parseTitle()` title regex matches at `q`.
fn console_tag_at(s: &[u8], q: usize) -> bool {
    let Some(&c) = s.get(q) else {
        return false;
    };
    let tags: &[&[u8]] = match c.to_ascii_lowercase() {
        b'v' => {
            let mut r = q + 1;
            if s.get(r) == Some(&b'.') {
                r += 1;
            }
            return s.get(r).is_some_and(u8::is_ascii_digit)
                && s.get(r + 1) == Some(&b'.')
                && s.get(r + 2).is_some_and(u8::is_ascii_digit);
        }
        b'.' | b' ' => return mci_ci_at(s, q + 1, b"rf"),
        b'p' => &[
            b"pal", b"patched", b"proper", b"psx", b"ps2", b"ps3", b"ps4", b"psp", b"psvita",
        ],
        b'n' => &[b"ntsc", b"nfofix", b"nds", b"n64", b"ngc"],
        b'e' => &[b"eur"],
        b'u' => &[b"usa"],
        b'j' => &[b"jp", b"jap"],
        b'a' => &[b"asia", b"aus"],
        b'm' => &[b"multi"],
        b'f' => &[b"fulldvd"],
        b'd' => &[b"dvd5", b"dvd9", b"dvdrip", b"demo", b"distribution"],
        b'r' => &[b"repack", b"retail", b"regionfree", b"readnfo", b"read.nfo"],
        b'w' => &[b"wii"],
        b'x' => &[b"x-box", b"xbox", b"xbla", b"x360"],
        b'3' => &[b"3ds"],
        _ => return false,
    };
    tags.iter().any(|tag| mci_ci_at(s, q, tag))
}

/// `(?P<title>.*?)[\.\-_ ](TAGS)` from title start `ts`: returns the title end.
#[inline]
fn console_title_end(s: &[u8], ts: usize) -> Option<usize> {
    (ts..s.len()).find(|&p| mci_is_console_sep(s[p]) && console_tag_at(s, p + 1))
}

/// First bytes of the `parseTitle()` site-prefix alternatives.
const MCI_CONSOLE_PREFIX_TABLE: [bool; 256] = mci_byte_table(b"aAeEfFiIpPuU(", false);

/// Ends of the optional group's site-prefix alternation at `e`, in PCRE backtracking order,
/// dispatched on the first byte (alternatives with different first bytes cannot both match).
fn console_prefix_ends(s: &[u8], e: usize, mut visit: impl FnMut(usize) -> bool) -> bool {
    let ws_at = |p: usize| s.get(p).is_some_and(|&b| mci_is_space(b));
    // One `(abgx360EFNet|EFNet\sFULL|FULL\sabgxEFNet|abgx\sFULL|abgxbox360EFNet)\s` alternative.
    let spaced_end = |head: &[u8], tail: &[u8]| -> Option<usize> {
        if !mci_ci_at(s, e, head) {
            return None;
        }
        let mut r = e + head.len();
        if !tail.is_empty() {
            if !ws_at(r) || !mci_ci_at(s, r + 1, tail) {
                return None;
            }
            r += 1 + tail.len();
        }
        ws_at(r).then_some(r + 1)
    };
    let Some(&first) = s.get(e) else {
        return false;
    };
    match first.to_ascii_lowercase() {
        b'a' => {
            if !mci_ci_at(s, e, b"abgx") {
                return false;
            }
            let alternatives: [(&[u8], &[u8]); 3] = [
                (b"abgx360efnet", b""),
                (b"abgx", b"full"),
                (b"abgxbox360efnet", b""),
            ];
            alternatives
                .iter()
                .any(|(head, tail)| spaced_end(head, tail).is_some_and(&mut visit))
        }
        b'e' => spaced_end(b"efnet", b"full").is_some_and(visit),
        b'f' => spaced_end(b"full", b"abgxefnet").is_some_and(visit),
        // `illuminatenboard\sorg`
        b'i' => {
            mci_ci_at(s, e, b"illuminatenboard")
                && ws_at(e + 16)
                && mci_ci_at(s, e + 17, b"org")
                && visit(e + 20)
        }
        // `Place2(hom|us)e.net`
        b'p' => {
            mci_ci_at(s, e, b"place2")
                && [b"home".as_slice(), b"use"].iter().any(|mid| {
                    let r = e + 6 + mid.len();
                    mci_ci_at(s, e + 6, mid)
                        && r < s.len()
                        && mci_ci_at(s, r + 1, b"net")
                        && visit(r + 4)
                })
        }
        // `united-forums? co uk`
        b'u' => {
            if !mci_ci_at(s, e, b"united-forum") {
                return false;
            }
            let r = e + 12;
            (s.get(r).is_some_and(|b| b.eq_ignore_ascii_case(&b's'))
                && mci_ci_at(s, r + 1, b" co uk")
                && visit(r + 7))
                || (mci_ci_at(s, r, b" co uk") && visit(r + 6))
        }
        // `\(\d+\)`
        b'(' => {
            let d = mci_digit_run(s, e + 1);
            d > 0 && s.get(e + 1 + d) == Some(&b')') && visit(e + 2 + d)
        }
        _ => false,
    }
}

/// Platform alternation of `parseTitle()` at `q`, in PCRE order: returns the match end.
fn console_platform_at(s: &[u8], q: usize) -> Option<usize> {
    let candidates: &[&[u8]] = match s.get(q)?.to_ascii_lowercase() {
        b'x' => &[b"xbla", b"xbox360", b"xboxone", b"x-box", b"xbox", b"x360"],
        b'w' => &[b"wiiware", b"wiiu", b"wii"],
        b'n' => &[b"n64", b"nes", b"nds", b"ngc"],
        b's' => &[b"snes"],
        b'p' => &[b"ps2", b"ps3", b"ps4", b"ps 3", b"psx2psp", b"psx", b"psp"],
        b'3' => &[b"3ds"],
        b'g' => &[b"gc"],
        _ => return None,
    };
    candidates
        .iter()
        .find(|kw| mci_ci_at(s, q, kw))
        .map(|kw| q + kw.len())
}

/// Sequential PHP `str_replace($search, ' ', $subject)` for one literal; borrows when absent.
fn mci_str_replace_space<'a>(s: &'a [u8], search: &[u8]) -> Cow<'a, [u8]> {
    let Some(first) = s.windows(search.len()).position(|w| w == search) else {
        return Cow::Borrowed(s);
    };
    let mut out = Vec::with_capacity(s.len());
    out.extend_from_slice(&s[..first]);
    let mut i = first;
    while i < s.len() {
        if s[i..].starts_with(search) {
            out.push(b' ');
            i += search.len();
        } else {
            out.push(s[i]);
            i += 1;
        }
    }
    Cow::Owned(out)
}

/// Parsed `ConsoleService::parseTitle()` result.
pub struct ConsoleParsedTitle {
    pub title: Vec<u8>,
    pub dlc: bool,
    pub platform: Vec<u8>,
    pub release: Vec<u8>,
}

/// `ConsoleService::parseTitle()`: `Ok(None)` for `false`, `Err(())` to decline.
#[allow(clippy::result_unit_err)]
pub fn console_parse_title(release_name: &[u8]) -> Result<Option<ConsoleParsedTitle>, ()> {
    let mut out = Vec::new();
    if !console_parse_title_into(release_name, &mut out)? {
        return Ok(None);
    }
    let mut parts = out[1..].split(|&b| b == CONSOLE_FIELD_SEP);
    let (Some(title), Some(platform), Some(release)) = (parts.next(), parts.next(), parts.next())
    else {
        return Err(());
    };
    Ok(Some(ConsoleParsedTitle {
        title: title.to_vec(),
        dlc: out[0] == b'1',
        platform: platform.to_vec(),
        release: release.to_vec(),
    }))
}

/// Field separator in the `nntmux_console_parse_title` result; inputs containing it are declined.
const CONSOLE_FIELD_SEP: u8 = 0x1F;

/// [`console_parse_title`] appending `dlc ('0'|'1') . title . "\x1F" . platform . "\x1F" . release`
/// to `out`: `Ok(false)` for PHP `false`, `Err(())` to decline.
#[allow(clippy::result_unit_err)]
pub fn console_parse_title_into(release_name: &[u8], out: &mut Vec<u8>) -> Result<bool, ()> {
    if release_name.len() > MCI_MAX_RELEASE_NAME
        || mci_contains_any(release_name, [b'\n', b'\r', 0, CONSOLE_FIELD_SEP])
    {
        return Err(());
    }
    // `/\sMulti\d?\s/i` -> ''
    let rel = mci_replace_all(release_name, b"", mci_find_space, |s, i| {
        if !mci_ci_at(s, i + 1, b"multi") {
            return None;
        }
        let r = i + 6;
        if s.get(r).is_some_and(u8::is_ascii_digit)
            && s.get(r + 1).is_some_and(|&b| mci_is_space(b))
        {
            return Some(r + 2);
        }
        s.get(r).is_some_and(|&b| mci_is_space(b)).then_some(r + 1)
    });
    let s: &[u8] = &rel;

    // `^(.+(PREFIXES))?(?P<title>.*?)[\.\-_ ](TAGS)`
    let mut span = None;
    for e in (1..s.len()).rev() {
        if MCI_CONSOLE_PREFIX_TABLE[s[e] as usize]
            && console_prefix_ends(s, e, |ts| {
                span = console_title_end(s, ts).map(|te| (ts, te));
                span.is_some()
            })
        {
            break;
        }
    }
    let span = span.or_else(|| console_title_end(s, 0).map(|te| (0, te)));
    let Some((ts, te)) = span else {
        return Ok(false);
    };
    let raw_title = &s[ts..te];

    // `str_replace(['.', '_', '%20', '[', ']'], ' ')`
    let mut t = Vec::with_capacity(raw_title.len());
    let mut i = 0;
    while i < raw_title.len() {
        if raw_title[i..].starts_with(b"%20") {
            t.push(b' ');
            i += 3;
            continue;
        }
        t.push(match raw_title[i] {
            b'.' | b'_' | b'[' | b']' => b' ',
            b => b,
        });
        i += 1;
    }
    // `str_replace([' RF ', '.RF.', '-RF-', '_RF_'], ' ')`; dots and underscores are already gone.
    let t = mci_str_replace_space(&t, b" RF ");
    let t = mci_str_replace_space(&t, b"-RF-");
    // `/PAL|MULTI(\d)?|NTSC-?J?|\(JAPAN\)/i` -> '', trim
    let t = mci_replace_all(
        &t,
        b"",
        |s| mci_find_any(s, *b"pPmMnN("),
        |s, i| {
            if mci_ci_at(s, i, b"pal") {
                return Some(i + 3);
            }
            if mci_ci_at(s, i, b"multi") {
                return Some(i + 5 + usize::from(s.get(i + 5).is_some_and(u8::is_ascii_digit)));
            }
            if mci_ci_at(s, i, b"ntsc") {
                let mut r = i + 4;
                if s.get(r) == Some(&b'-') {
                    r += 1;
                }
                if s.get(r).is_some_and(|b| b.eq_ignore_ascii_case(&b'j')) {
                    r += 1;
                }
                return Some(r);
            }
            mci_ci_at(s, i, b"(japan)").then_some(i + 7)
        },
    );
    let t = php_trim(&t);
    // `/Dis[ck] \d.*$/i` -> '', trim
    let disc = (0..t.len()).find(|&i| {
        mci_ci_at(t, i, b"dis")
            && t.get(i + 3)
                .is_some_and(|b| matches!(b.to_ascii_lowercase(), b'c' | b'k'))
            && t.get(i + 4) == Some(&b' ')
            && t.get(i + 5).is_some_and(u8::is_ascii_digit)
    });
    let mut title: &[u8] = php_trim(&t[..disc.unwrap_or(t.len())]);

    let dlc = mci_ci_contains(title, b"dlc");
    if dlc {
        if mci_ci_contains(title, b"rock band network") {
            title = b"Rock Band";
        } else if let Some(dash) = title.iter().position(|&b| b == b'-') {
            title = &title[..dash];
        } else if let Some(first) = title.iter().position(|&b| b == b' ')
            && let Some(second) = title[first + 1..].iter().position(|&b| b == b' ')
        {
            title = &title[..first + 1 + second + 1];
        }
    }

    // `/[\.\-_ ](?P<platform>...)/i`
    let platform_span = (0..s.len()).find_map(|i| {
        if mci_is_console_sep(s[i]) {
            console_platform_at(s, i + 1).map(|end| (i + 1, end))
        } else {
            None
        }
    });
    let Some((ps, pe)) = platform_span else {
        return Ok(false);
    };
    let mut platform: &[u8] = &s[ps..pe];
    if platform.eq_ignore_ascii_case(b"ngc") || platform.eq_ignore_ascii_case(b"gc") {
        platform = b"NGC";
    }
    if mci_ci_at(platform, 0, b"psx2psp") {
        platform = b"PSX";
    }
    if !raw_title.is_empty()
        && raw_title != b"0"
        && mci_ci_at(platform, 0, b"xbla")
        && mci_ci_contains(raw_title, b"dlc")
    {
        platform = b"XBOX360";
    }

    let title = php_trim(title);
    if title.is_empty() || title == b"0" {
        return Ok(false);
    }
    out.push(if dlc { b'1' } else { b'0' });
    out.extend_from_slice(title);
    out.push(CONSOLE_FIELD_SEP);
    out.extend_from_slice(php_trim(platform));
    out.push(CONSOLE_FIELD_SEP);
    out.extend_from_slice(php_trim(s));
    Ok(true)
}

/// `ConsoleService::parseTitle()` as a NUL-terminated thread-local string (see
/// [`console_parse_title_into`]): an empty string for PHP `false`, NULL to decline.
///
/// # Safety
///
/// `input` must be valid for `input_len` reads when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_console_parse_title(
    input: *const u8,
    input_len: usize,
) -> *const c_char {
    let Some(release_name) = (unsafe { mci_input(input, input_len) }) else {
        return std::ptr::null();
    };
    mci_cstr_result(|out| console_parse_title_into(release_name, out).is_ok())
}

/// `ConsoleService::replacePlatform()` lookup table, indexed by the FFI return code.
pub const CONSOLE_PLATFORM_NAMES: [&str; 16] = [
    "Xbox 360",
    "Xbox One",
    "Nintendo DS",
    "Nintendo 3DS",
    "PlayStation2",
    "PlayStation 3",
    "PlayStation 4",
    "Sony PSP",
    "PlayStation Vita",
    "PlayStation",
    "Nintendo Wii U",
    "Nintendo Wii",
    "GameCube",
    "Nintendo 64",
    "Nintendo NES",
    "SNES",
];

/// `ConsoleService::replacePlatform()` as an index into [`CONSOLE_PLATFORM_NAMES`], or `None` for the default arm.
pub fn console_replace_platform_index(platform: &[u8]) -> Option<usize> {
    if platform.len() > 18 {
        return None;
    }
    let mut buf = [0u8; 18];
    for (slot, &b) in buf.iter_mut().zip(platform) {
        *slot = b.to_ascii_uppercase();
    }
    Some(match &buf[..platform.len()] {
        b"X360" | b"XBOX360" => 0,
        b"XBOXONE" | b"XBOX ONE" => 1,
        b"DSI" | b"NDS" => 2,
        b"3DS" => 3,
        b"PS2" => 4,
        b"PS3" => 5,
        b"PS4" => 6,
        b"PSP" => 7,
        b"PSVITA" => 8,
        b"PSX" | b"PSX2PSP" => 9,
        b"WIIU" => 10,
        b"WII" => 11,
        b"NGC" => 12,
        b"N64" => 13,
        b"NES" => 14,
        b"SUPER NINTENDO" | b"NINTENDO SUPER NES" | b"SNES" => 15,
        _ => return None,
    })
}

/// `ConsoleService::replacePlatform()`: returns the mapped index (`0..16`) or `-2` to keep the input.
/// Returns `-1` for an invalid pointer.
///
/// # Safety
///
/// `input` must be valid for `input_len` reads when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_console_replace_platform(
    input: *const u8,
    input_len: usize,
) -> i32 {
    let Some(platform) = (unsafe { mci_input(input, input_len) }) else {
        return -1;
    };
    console_replace_platform_index(platform).map_or(-2, |idx| idx as i32)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn sha256_matches_standard_vectors() {
        assert_eq!(
            &sha256_hex_slice(b""),
            b"e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
        );
        assert_eq!(
            &sha256_hex_slice(b"abc"),
            b"ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
        );
    }

    #[test]
    fn similarity_percent_matches_php_similar_text() {
        assert_eq!(similarity_percent(b"", b""), 0.0);
        assert_eq!(similarity_percent(b"0", b"0"), 100.0);
        assert_eq!(similarity_percent(b"abcXYZ", b"XYZabc"), 50.0);
    }

    #[test]
    fn nomination_keys_match_original_filename_evidence() {
        let keys = compute_nomination_keys_ascii(b"Synthetic.Alias-GRP.rar.par2")
            .unwrap()
            .unwrap();
        assert_eq!(
            keys,
            vec![
                b"synthetic.alias-grp".to_vec(),
                b"synthetic.alias-grp.rar".to_vec(),
                b"synthetic.alias-grp.rar.par2".to_vec(),
            ]
        );
    }

    fn fingerprint(ids: &[&[u8]], validated: u32) -> Option<String> {
        let buf = ids.join(&0u8);
        let mut out = [0u8; 40];
        let rc = unsafe {
            nntmux_nzb_article_fingerprint_batch(
                buf.as_ptr(),
                buf.len(),
                ids.len() as u32,
                validated,
                out.as_mut_ptr(),
            )
        };
        (rc == 40).then(|| String::from_utf8(out.to_vec()).unwrap())
    }

    #[test]
    fn sha1_matches_standard_vectors() {
        assert_eq!(&sha1_hex(b""), b"da39a3ee5e6b4b0d3255bfef95601890afd80709");
        assert_eq!(
            &sha1_hex(b"abc"),
            b"a9993e364706816aba3e25717850c26c9cd0d89d"
        );
        assert_eq!(
            &sha1_hex(&[b'a'; 1000]),
            b"291e9a6c66994949b57ba5e650361e98fc36b1ba"
        );
    }

    #[test]
    fn message_id_normalization_matches_php() {
        assert_eq!(normalize_message_id(b"  <a@b>  "), b"a@b");
        assert_eq!(normalize_message_id(b"\" <a@b> \""), b"a@b");
        assert_eq!(normalize_message_id(b"'<a@b>'"), b"a@b");
        assert_eq!(normalize_message_id(b"<<a@b>>"), b"<a@b>");
        assert_eq!(normalize_message_id(b"\"a@b'"), b"\"a@b'");
        assert_eq!(normalize_message_id(b"\""), b"\"");
        assert_eq!(normalize_message_id(b"<>"), b"");
        assert_eq!(normalize_message_id(b"\0\x0b a \x0c"), b"a \x0c");
        assert_eq!(validated_message_id(b"<a b>"), None);
        assert_eq!(validated_message_id(b"<a\x7f>"), None);
        assert_eq!(validated_message_id(b"<>"), None);
        assert_eq!(validated_message_id(&[b'x'; 255]), Some(&[b'x'; 255][..]));
        assert_eq!(validated_message_id(&[b'x'; 256]), None);
    }

    #[test]
    fn fingerprint_is_order_independent_and_unique() {
        let a = fingerprint(&[b"<one@x>", b"two@x", b"\"three@x\""], 0).unwrap();
        let b = fingerprint(&[b"three@x", b"one@x", b"two@x", b"one@x"], 0).unwrap();
        assert_eq!(a, b);
        assert_eq!(fingerprint(&[b"one@x", b"bad id"], 0), None);
        assert_eq!(fingerprint(&[b"one@x", b""], 1), None);
        assert_eq!(fingerprint(&[b"one@x", b"two@x"], 1).is_some(), true);
        let mut out = [0u8; 40];
        assert_eq!(
            unsafe {
                nntmux_nzb_article_fingerprint_batch(b"a".as_ptr(), 1, 2, 1, out.as_mut_ptr())
            },
            0
        );
    }

    #[test]
    fn password_validation_matches_php_ranges() {
        let check = |values: &[&[u8]]| {
            let buf = values.join(&0u8);
            unsafe { nntmux_nzb_validate_passwords(buf.as_ptr(), buf.len(), values.len() as u32) }
        };
        assert_eq!(check(&[]), 1);
        assert_eq!(check(&[b"", b"pass\tword\n"]), 1);
        assert_eq!(check(&["\u{10000}\u{E000}\u{FFFD}".as_bytes()]), 1);
        assert_eq!(check(&[b"bad\x01"]), 0);
        assert_eq!(check(&["\u{FFFE}".as_bytes()]), 0);
        assert_eq!(check(&["\u{FFFF}".as_bytes()]), 0);
        assert_eq!(check(&[b"\xff"]), 0);
        assert_eq!(check(&[b"\xed\xa0\x80"]), 0);
        assert_eq!(check(&[&[b'a'; 4097]]), 0);
        assert_eq!(check(&[&[b'a'; 4096]]), 1);
        let many = vec![&b"x"[..]; 65];
        assert_eq!(check(&many), 0);
        assert_eq!(
            unsafe { nntmux_nzb_validate_passwords(b"a\0b".as_ptr(), 3, 1) },
            0
        );
    }

    #[test]
    fn ffi_normalize_reports_required_capacity() {
        let mut out = [0u8; 2];
        let rc = unsafe {
            nntmux_nzb_normalize_message_id(b"<abcd>".as_ptr(), 6, out.as_mut_ptr(), out.len())
        };
        assert_eq!(rc, -4);
        let mut out = [0u8; 8];
        let rc = unsafe {
            nntmux_nzb_validate_message_id(b" <abcd> ".as_ptr(), 8, out.as_mut_ptr(), out.len())
        };
        assert_eq!(rc, 4);
        assert_eq!(&out[..4], b"abcd");
    }

    #[test]
    fn par2_size_decimal_and_le_u64_match_php() {
        for valid in [
            &b"0"[..],
            b"1",
            b"42",
            b"4294967296",
            b"9223372036854775807",
            b"9223372036854775808",
            b"18446744073709551615",
        ] {
            assert_eq!(
                unsafe { nntmux_par2_size_decimal_valid(valid.as_ptr(), valid.len()) },
                1
            );
        }
        for invalid in [
            &b""[..],
            b"00",
            b"01",
            b"-1",
            b"+1",
            b"123\n",
            b" 123",
            b"18446744073709551616",
            b"99999999999999999999",
            b"100000000000000000000",
        ] {
            assert_eq!(
                unsafe { nntmux_par2_size_decimal_valid(invalid.as_ptr(), invalid.len()) },
                0
            );
        }

        let cases: [(u64, &[u8]); 5] = [
            (0, b"0"),
            (1, b"1"),
            (u32::MAX as u64, b"4294967295"),
            (i64::MAX as u64 + 1, b"9223372036854775808"),
            (u64::MAX, b"18446744073709551615"),
        ];
        for (val, expected) in cases {
            let le = val.to_le_bytes();
            let mut out = [0u8; 24];
            let n = unsafe {
                nntmux_par2_le_u64_to_decimal(le.as_ptr(), le.len(), out.as_mut_ptr(), out.len())
            };
            assert_eq!(n, expected.len() as isize);
            assert_eq!(&out[..n as usize], expected);
        }
        let mut tiny = [0u8; 2];
        assert_eq!(
            unsafe {
                nntmux_par2_le_u64_to_decimal(
                    1000u64.to_le_bytes().as_ptr(),
                    8,
                    tiny.as_mut_ptr(),
                    tiny.len(),
                )
            },
            -4
        );
        assert_eq!(
            unsafe { nntmux_par2_le_u64_to_decimal(b"short".as_ptr(), 5, tiny.as_mut_ptr(), 2) },
            0
        );
    }

    #[test]
    fn archive_witness_bounded_names_and_decode_json_match_php() {
        let mut overflow = 9u8;
        let mut out = [0u8; 256];
        let input = b"Ep1.mkv\0Ep2.mkv\0Ep1.mkv";
        let rc = unsafe {
            nntmux_archive_witness_bounded_names_nul(
                input.as_ptr(),
                input.len(),
                3,
                &mut overflow,
                out.as_mut_ptr(),
                out.len(),
            )
        };
        assert_eq!(overflow, 0);
        assert_eq!(&out[..rc as usize], b"Ep1.mkv\0Ep2.mkv");

        let json = br#"["Show.S01E01.mkv", "Show\\Sub\/Ep\"2\".nfo"]"#;
        let rc = unsafe {
            nntmux_archive_witness_decode_names_json(
                json.as_ptr(),
                json.len(),
                out.as_mut_ptr(),
                out.len(),
            )
        };
        assert!(rc > 0);
        assert_eq!(
            &out[..rc as usize],
            b"Show.S01E01.mkv\0Show\\Sub/Ep\"2\".nfo"
        );

        let dup_json = br#"["a.mkv", "a.mkv"]"#;
        assert_eq!(
            unsafe {
                nntmux_archive_witness_decode_names_json(
                    dup_json.as_ptr(),
                    dup_json.len(),
                    out.as_mut_ptr(),
                    out.len(),
                )
            },
            -1
        );
    }

    #[test]
    fn utf8_and_irc_channel_list_match_php() {
        assert_eq!(unsafe { nntmux_utf8_is_valid(b"".as_ptr(), 0) }, 1);
        assert_eq!(
            unsafe { nntmux_utf8_is_valid("MÖVIE-映画".as_ptr(), "MÖVIE-映画".len()) },
            1
        );
        assert_eq!(unsafe { nntmux_utf8_is_valid(b"\xff\xfe".as_ptr(), 2) }, 0);

        let mut out = [0u8; 256];
        let raw = b" #chan1 , chan2 : secret , # , :nopass , chan1 : override ";
        let rc = unsafe {
            nntmux_irc_channel_list_parse(raw.as_ptr(), raw.len(), out.as_mut_ptr(), out.len())
        };
        assert!(rc > 0);
        assert_eq!(&out[..rc as usize], b"#chan1\0override\0#chan2\0secret");
    }

    fn reference_levenshtein(a: &[u8], b: &[u8]) -> usize {
        let mut prev: Vec<usize> = (0..=b.len()).collect();
        for (i, &ca) in a.iter().enumerate() {
            let mut curr = vec![i + 1; b.len() + 1];
            for (j, &cb) in b.iter().enumerate() {
                curr[j + 1] = (prev[j] + usize::from(ca != cb))
                    .min(prev[j + 1] + 1)
                    .min(curr[j] + 1);
            }
            prev = curr;
        }
        prev[b.len()]
    }

    #[test]
    fn php_levenshtein_bit_parallel_matches_reference_dp() {
        let mut state = 0x2026_1024_u64;
        let mut next = || {
            state ^= state << 13;
            state ^= state >> 7;
            state ^= state << 17;
            state
        };
        for _ in 0..4000 {
            let la = (next() % 200) as usize;
            let lb = (next() % 200) as usize;
            let a: Vec<u8> = (0..la).map(|_| b"abcde "[(next() % 6) as usize]).collect();
            let b: Vec<u8> = (0..lb).map(|_| b"abcde "[(next() % 6) as usize]).collect();
            assert_eq!(
                php_levenshtein(&a, &b),
                reference_levenshtein(&a, &b),
                "{a:?} {b:?}"
            );
        }
        assert_eq!(php_levenshtein(b"", b"abc"), 3);
        assert_eq!(php_levenshtein(b"kitten", b"sitting"), 3);
    }

    #[test]
    fn igdb_normalize_and_similarity_match_php() {
        let norm =
            |s: &str| String::from_utf8(igdb_normalize_for_match(s.as_bytes()).unwrap()).unwrap();
        assert_eq!(
            norm("The Witcher 3: Wild Hunt - Game of the Year Edition"),
            "the witcher 3 wild hunt edition"
        );
        assert_eq!(
            norm("DOOM.Eternal.Deluxe.Edition-MULTi12_x64"),
            "doom eternal deluxe edition multi12 x64"
        );
        assert_eq!(
            norm("DOOM Eternal Deluxe Edition MULTi12 x64"),
            "doom eternal"
        );
        assert_eq!(norm("Pokémon Sword"), "pok mon sword");
        assert_eq!(norm("snake_case_title GOTY"), "snake case title");
        assert!(igdb_normalize_for_match("\u{0130}stanbul".as_bytes()).is_none());
        assert!(igdb_normalize_for_match(b"bad \xff utf8").is_none());
        assert_eq!(igdb_compute_similarity(b"", b""), 100.0);
        assert_eq!(igdb_compute_similarity(b"abc", b""), 0.0);
        assert_eq!(igdb_compute_similarity(b"halo", b"halo 2"), 80.0);
    }

    #[test]
    fn igdb_extract_base_title_matches_php() {
        let base = |s: &str| {
            let (start, len) = igdb_extract_base_title_span(s.as_bytes()).unwrap();
            s[start..start + len].to_string()
        };
        assert_eq!(base("Batman: Arkham City"), "Batman");
        assert_eq!(
            base("Tales from the Borderlands Episode 2 Atlas Mugged"),
            "Tales from the Borderlands"
        );
        assert_eq!(
            base("Naruto Ultimate Ninja Vol. 3 Extra"),
            "Naruto Ultimate Ninja"
        );
        assert_eq!(base("Halo 2"), "Halo");
        assert_eq!(base("  Half-Life  "), "Half-Life");
        assert!(igdb_extract_base_title_span(b"Line\nBreak").is_none());
    }

    #[test]
    fn music_parse_artist_matches_php_examples() {
        let parse = |s: &str| {
            music_parse_artist(s.as_bytes())
                .unwrap()
                .map(|(y, n)| (String::from_utf8(y).unwrap(), String::from_utf8(n).unwrap()))
        };
        assert_eq!(
            parse("Pink_Floyd-The_Dark_Side_Of_The_Moon-Remastered-1973-GRP"),
            Some(("1973".into(), "Pink Floyd The Dark Side Of The Moon".into()))
        );
        assert_eq!(
            parse("VA-Summer_Hits_2015-2CD-2015-GRP"),
            Some(("2015".into(), "Various Artists Summer Hits".into()))
        );
        assert_eq!(parse("Short-2010-GRP"), None);
        assert_eq!(parse("No year here at all"), None);
        assert!(music_parse_artist(b"Line\nBreak 1999").is_err());
    }

    #[test]
    fn console_parse_title_and_platform_match_php_examples() {
        let parsed = console_parse_title(b"Halo.3.PAL.XBOX360-GRP")
            .unwrap()
            .unwrap();
        assert_eq!(parsed.title, b"Halo 3");
        assert_eq!(parsed.platform, b"XBOX360");
        assert!(!parsed.dlc);
        let parsed = console_parse_title(b"Rock.Band.DLC.Pack.XBLA.X360-GRP")
            .unwrap()
            .unwrap();
        assert_eq!(parsed.title, b"Rock Band");
        assert_eq!(parsed.platform, b"XBOX360");
        assert!(parsed.dlc);
        assert!(console_parse_title(b"Nothing.To.See").unwrap().is_none());
        assert_eq!(console_replace_platform_index(b"xbox one"), Some(1));
        assert_eq!(console_replace_platform_index(b"Atari"), None);
    }
}
