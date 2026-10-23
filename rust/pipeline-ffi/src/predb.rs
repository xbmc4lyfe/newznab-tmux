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
}
