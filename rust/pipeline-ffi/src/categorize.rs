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
fn is_vowel(b: u8) -> bool {
    matches!(b.to_ascii_lowercase(), b'a' | b'e' | b'i' | b'o' | b'u')
}

#[inline]
fn is_consonant(b: u8) -> bool {
    b.is_ascii_alphabetic() && !is_vowel(b)
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
fn strip_trailing_pcre_newline(bytes: &[u8]) -> &[u8] {
    bytes.strip_suffix(b"\n").unwrap_or(bytes)
}

#[inline]
fn is_analysis_extension(ext: &[u8]) -> bool {
    if ext.is_empty() || ext.len() > 10 {
        return false;
    }
    // Check r\d{2,3}
    if (ext.len() == 3 || ext.len() == 4)
        && (ext[0] == b'r' || ext[0] == b'R')
        && ext[1..].iter().all(|b| b.is_ascii_digit())
    {
        return true;
    }
    let mut lower = [0u8; 10];
    for (i, &b) in ext.iter().enumerate() {
        lower[i] = b.to_ascii_lowercase();
    }
    matches!(
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
            | b"par"
            | b"par2"
            | b"nfo"
            | b"sfv"
            | b"nzb"
            | b"rar"
            | b"zip"
            | b"7z"
            | b"gz"
            | b"tar"
            | b"001"
            | b"msix"
            | b"msixbundle"
            | b"appx"
            | b"appxbundle"
            | b"apk"
            | b"xap"
            | b"ipa"
            | b"deb"
            | b"rpm"
            | b"pkg"
            | b"dmg"
            | b"exe"
            | b"msi"
    )
}

#[inline]
pub fn strip_extensions_for_analysis(name: &[u8]) -> &[u8] {
    let trimmed = php_trim_ascii(name);
    if let Some(dot_pos) = trimmed.iter().rposition(|&b| b == b'.') {
        let ext = &trimmed[dot_pos + 1..];
        if is_analysis_extension(ext) {
            return &trimmed[..dot_pos];
        }
    }
    trimmed
}

pub struct CoreNameBuf {
    stack: [u8; 512],
    len: usize,
    overflow: Vec<u8>,
}

impl CoreNameBuf {
    #[inline]
    pub fn from_cleaned(cleaned: &[u8]) -> Self {
        if cleaned.len() <= 512 {
            let mut stack = [0u8; 512];
            let mut len = 0usize;
            for &b in cleaned {
                if !(b == b'.' || b == b'-' || b == b'_' || is_pcre_space(b)) {
                    stack[len] = b;
                    len += 1;
                }
            }
            Self {
                stack,
                len,
                overflow: Vec::new(),
            }
        } else {
            let mut overflow = Vec::with_capacity(cleaned.len());
            for &b in cleaned {
                if !(b == b'.' || b == b'-' || b == b'_' || is_pcre_space(b)) {
                    overflow.push(b);
                }
            }
            Self {
                stack: [0u8; 512],
                len: 0,
                overflow,
            }
        }
    }

    #[inline]
    pub fn as_slice(&self) -> &[u8] {
        if self.overflow.is_empty() {
            &self.stack[..self.len]
        } else {
            &self.overflow
        }
    }
}

#[inline]
pub fn count_character_transitions(s: &[u8]) -> usize {
    if s.len() < 2 {
        return 0;
    }
    let mut transitions = 0usize;
    let mut prev_digit = s[0].is_ascii_digit();
    let mut prev_upper = s[0].is_ascii_uppercase();
    for &curr in &s[1..] {
        let curr_digit = curr.is_ascii_digit();
        let curr_upper = curr.is_ascii_uppercase();
        if prev_digit != curr_digit || (!prev_digit && !curr_digit && prev_upper != curr_upper) {
            transitions += 1;
        }
        prev_digit = curr_digit;
        prev_upper = curr_upper;
    }
    transitions
}

#[inline]
pub fn get_max_consecutive_letters(s: &[u8]) -> usize {
    let mut max_consecutive = 0usize;
    let mut current_consecutive = 0usize;
    for &b in s {
        if b.is_ascii_alphabetic() {
            current_consecutive += 1;
            if current_consecutive > max_consecutive {
                max_consecutive = current_consecutive;
            }
        } else {
            current_consecutive = 0;
        }
    }
    max_consecutive
}

#[inline]
fn has_bounded_year(s: &[u8]) -> bool {
    if s.len() < 4 {
        return false;
    }
    for i in 0..=s.len() - 4 {
        if ((s[i] == b'1' && s[i + 1] == b'9') || (s[i] == b'2' && s[i + 1] == b'0'))
            && s[i + 2].is_ascii_digit()
            && s[i + 3].is_ascii_digit()
        {
            let left_ok = i == 0 || !is_pcre_word(s[i - 1]);
            let right_ok = i + 4 == s.len() || !is_pcre_word(s[i + 4]);
            if left_ok && right_ok {
                return true;
            }
        }
    }
    false
}

#[inline]
pub fn looks_like_random_string(s: &[u8]) -> bool {
    let body = strip_trailing_pcre_newline(s);
    if !body.is_empty()
        && (body.iter().all(|b| b.is_ascii_uppercase())
            || body.iter().all(|b| b.is_ascii_lowercase()))
    {
        return s.len() >= 12;
    }

    let len = s.len();
    let transitions = count_character_transitions(s);
    let denom = if len > 1 { (len - 1) as f64 } else { 1.0 };
    let transition_ratio = (transitions as f64) / denom;

    // /[bcdfghjklmnpqrstvwxyz]{1,2}[aeiou][bcdfghjklmnpqrstvwxyz]{1,2}[aeiou]/i
    let mut has_word_pattern = false;
    if len >= 4 {
        for i in 1..len {
            if is_vowel(s[i]) && is_consonant(s[i - 1]) {
                if i + 2 < len && is_consonant(s[i + 1]) && is_vowel(s[i + 2]) {
                    has_word_pattern = true;
                    break;
                }
                if i + 3 < len
                    && is_consonant(s[i + 1])
                    && is_consonant(s[i + 2])
                    && is_vowel(s[i + 3])
                {
                    has_word_pattern = true;
                    break;
                }
            }
        }
    }

    if transition_ratio > 0.3 && !has_word_pattern {
        return true;
    }

    let mut consec_consonants = 0usize;
    let mut has_upper = false;
    let mut has_lower = false;
    let mut has_digit = false;
    for &b in s {
        if is_consonant(b) {
            consec_consonants += 1;
            if consec_consonants >= 5 {
                return true;
            }
        } else {
            consec_consonants = 0;
        }
        if b.is_ascii_uppercase() {
            has_upper = true;
        } else if b.is_ascii_lowercase() {
            has_lower = true;
        } else if b.is_ascii_digit() {
            has_digit = true;
        }
    }

    has_upper && has_lower && has_digit
}

#[inline]
fn is_hash_left_boundary(b: u8) -> bool {
    matches!(b, b'"' | b'\'' | b'[' | b']' | b'/' | b'-') || is_pcre_space(b)
}

#[inline]
fn is_hash_right_boundary(b: u8) -> bool {
    is_hash_left_boundary(b) || b == b'.'
}

#[inline]
pub fn bounded_hex_hash_mask(name: &[u8]) -> u8 {
    let mut mask = 0u8;
    let mut i = 0usize;
    let len = name.len();
    while i < len {
        if name[i].is_ascii_hexdigit() {
            let start = i;
            i += 1;
            while i < len && name[i].is_ascii_hexdigit() {
                i += 1;
            }
            let run_len = i - start;
            let bit = match run_len {
                32 => 1u8,
                40 => 2u8,
                64 => 4u8,
                _ => 0u8,
            };
            if bit != 0 {
                let left_ok = start == 0 || is_hash_left_boundary(name[start - 1]);
                let right_ok = i == len || is_hash_right_boundary(name[i]);
                if left_ok && right_ok {
                    mask |= bit;
                }
            }
        } else {
            i += 1;
        }
    }
    mask
}

#[inline]
pub fn is_bounded_generic_hash(name: &[u8]) -> bool {
    let mut i = 0usize;
    let len = name.len();
    while i < len {
        if name[i].is_ascii_alphanumeric() {
            let start = i;
            i += 1;
            while i < len && name[i].is_ascii_alphanumeric() {
                i += 1;
            }
            let run_len = i - start;
            if (24..=128).contains(&run_len) {
                let left_ok = start == 0 || is_hash_left_boundary(name[start - 1]);
                let right_ok = i == len || is_hash_right_boundary(name[i]);
                if left_ok && right_ok {
                    let token = &name[start..i];
                    if has_bounded_year(token) {
                        return false;
                    }
                    return token.iter().any(|b| b.is_ascii_digit())
                        && looks_like_random_string(token);
                }
            }
        } else {
            i += 1;
        }
    }
    false
}

#[inline]
fn is_b64_token_char(b: u8) -> bool {
    b.is_ascii_alphanumeric() || matches!(b, b'+' | b'/' | b'_' | b'=' | b'-')
}

#[inline]
fn try_match_b64_at(name: &[u8], g_start: usize) -> Option<&[u8]> {
    let len = name.len();
    if g_start >= len {
        return None;
    }
    let mut max_end = g_start;
    while max_end < len && is_b64_token_char(name[max_end]) {
        max_end += 1;
    }
    if max_end - g_start < 24 {
        return None;
    }
    let mut end = max_end;
    while end >= g_start + 24 {
        if end == len || is_hash_right_boundary(name[end]) {
            return Some(&name[g_start..end]);
        }
        end -= 1;
    }
    None
}

#[inline]
pub fn is_base64_like_token(name: &[u8]) -> bool {
    let len = name.len();
    let mut matched_token: Option<&[u8]> = None;

    for pos in 0..len {
        if pos == 0
            && let Some(tok) = try_match_b64_at(name, 0)
        {
            matched_token = Some(tok);
            break;
        }
        if is_hash_left_boundary(name[pos])
            && let Some(tok) = try_match_b64_at(name, pos + 1)
        {
            matched_token = Some(tok);
            break;
        }
    }

    let Some(raw_token) = matched_token else {
        return false;
    };

    let mut end = raw_token.len();
    while end > 0 && raw_token[end - 1] == b'=' {
        end -= 1;
    }
    let token = &raw_token[..end];
    if token.len() < 24 || has_bounded_year(token) {
        return false;
    }
    let has_upper = token.iter().any(|b| b.is_ascii_uppercase());
    let has_lower = token.iter().any(|b| b.is_ascii_lowercase());
    let has_digit = token.iter().any(|b| b.is_ascii_digit());
    if !has_upper || !has_lower || !has_digit {
        return false;
    }
    looks_like_random_string(token)
}

#[inline]
pub fn is_uuid_pattern(core_name: &[u8]) -> bool {
    let s = strip_trailing_pcre_newline(core_name);
    if s.len() < 32 || s.len() > 36 {
        return false;
    }
    let groups = [8usize, 4, 4, 4, 12];
    let mut pos = 0usize;
    for (idx, &g_len) in groups.iter().enumerate() {
        if pos + g_len > s.len() {
            return false;
        }
        if !s[pos..pos + g_len].iter().all(|b| b.is_ascii_hexdigit()) {
            return false;
        }
        pos += g_len;
        if idx < 4 && pos < s.len() && s[pos] == b'-' {
            pos += 1;
        }
    }
    pos == s.len()
}

#[inline]
pub fn is_pure_hex_string(core_name: &[u8], min_length: usize) -> bool {
    let s = strip_trailing_pcre_newline(core_name);
    s.len() >= min_length && s.iter().all(|b| b.is_ascii_hexdigit())
}

#[inline]
pub fn is_obfuscated_uppercase_string(name: &[u8]) -> bool {
    let s = strip_trailing_pcre_newline(name);
    s.len() >= 15
        && s.iter()
            .all(|b| b.is_ascii_uppercase() || b.is_ascii_digit())
}

#[inline]
fn is_camel_case_words(s: &[u8]) -> bool {
    // /^[A-Z][a-z]+([A-Z][a-z]+)+$/
    if s.len() < 4 || !s[0].is_ascii_uppercase() {
        return false;
    }
    let mut i = 0usize;
    let mut word_count = 0usize;
    while i < s.len() {
        if !s[i].is_ascii_uppercase() {
            return false;
        }
        i += 1;
        let lower_start = i;
        while i < s.len() && s[i].is_ascii_lowercase() {
            i += 1;
        }
        if i == lower_start {
            return false;
        }
        word_count += 1;
    }
    word_count >= 2
}

#[inline]
pub fn is_obfuscated_mixed_alphanumeric(name: &[u8]) -> bool {
    let s = strip_trailing_pcre_newline(name);
    if s.len() < 15 || !s.iter().all(|b| b.is_ascii_alphanumeric()) {
        return false;
    }
    !has_bounded_year(name) && !is_camel_case_words(s)
}

#[inline]
fn match_usenet_archive_suffix(s: &[u8]) -> Option<usize> {
    // Matches (part\d+\.rar|7z\.\d{3}|rar|zip|vol\d+\+\d+\.par2|par2)" at start of s (case-insensitive)
    if s.len() >= 4 && s[..3].eq_ignore_ascii_case(b"rar") && s[3] == b'"' {
        return Some(4);
    }
    if s.len() >= 4 && s[..3].eq_ignore_ascii_case(b"zip") && s[3] == b'"' {
        return Some(4);
    }
    if s.len() >= 5 && s[..4].eq_ignore_ascii_case(b"par2") && s[4] == b'"' {
        return Some(5);
    }
    if s.len() >= 7
        && s[..3].eq_ignore_ascii_case(b"7z.")
        && s[3].is_ascii_digit()
        && s[4].is_ascii_digit()
        && s[5].is_ascii_digit()
        && s[6] == b'"'
    {
        return Some(7);
    }
    if s.len() >= 10 && s[..4].eq_ignore_ascii_case(b"part") {
        let mut i = 4usize;
        while i < s.len() && s[i].is_ascii_digit() {
            i += 1;
        }
        if i > 4
            && i + 5 <= s.len()
            && s[i..i + 4].eq_ignore_ascii_case(b".rar")
            && s[i + 4] == b'"'
        {
            return Some(i + 5);
        }
    }
    if s.len() >= 11 && s[..3].eq_ignore_ascii_case(b"vol") {
        let mut i = 3usize;
        while i < s.len() && s[i].is_ascii_digit() {
            i += 1;
        }
        if i > 3 && i < s.len() && s[i] == b'+' {
            i += 1;
            let d2_start = i;
            while i < s.len() && s[i].is_ascii_digit() {
                i += 1;
            }
            if i > d2_start
                && i + 6 <= s.len()
                && s[i..i + 5].eq_ignore_ascii_case(b".par2")
                && s[i + 5] == b'"'
            {
                return Some(i + 6);
            }
        }
    }
    None
}

#[inline]
pub fn is_obfuscated_usenet_filename(name: &[u8]) -> bool {
    // /\[\d+\/\d+]\s*-\s*"([a-zA-Z0-9]{12,})\.(part\d+\.rar|7z\.\d{3}|rar|zip|vol\d+\+\d+\.par2|par2)"/i
    let len = name.len();
    let mut i = 0usize;
    while i < len {
        if name[i] == b'[' {
            let mut p = i + 1;
            let d1_start = p;
            while p < len && name[p].is_ascii_digit() {
                p += 1;
            }
            if p > d1_start && p < len && name[p] == b'/' {
                p += 1;
                let d2_start = p;
                while p < len && name[p].is_ascii_digit() {
                    p += 1;
                }
                if p > d2_start && p < len && name[p] == b']' {
                    p += 1;
                    while p < len && is_pcre_space(name[p]) {
                        p += 1;
                    }
                    if p < len && name[p] == b'-' {
                        p += 1;
                        while p < len && is_pcre_space(name[p]) {
                            p += 1;
                        }
                        if p < len && name[p] == b'"' {
                            p += 1;
                            let fn_start = p;
                            while p < len && name[p].is_ascii_alphanumeric() {
                                p += 1;
                            }
                            let fn_len = p - fn_start;
                            if fn_len >= 12
                                && p < len
                                && name[p] == b'.'
                                && match_usenet_archive_suffix(&name[p + 1..]).is_some()
                            {
                                let filename = &name[fn_start..p];
                                return !has_bounded_year(filename)
                                    && looks_like_random_string(filename);
                            }
                        }
                    }
                }
            }
        }
        i += 1;
    }
    false
}

#[inline]
fn is_punct_mid_char(b: u8) -> bool {
    b.is_ascii_uppercase() || b.is_ascii_digit() || matches!(b, b'.' | b'_' | b'-')
}

#[inline]
pub fn is_obfuscated_punctuation(name: &[u8]) -> bool {
    // /^[^a-zA-Z]*[A-Z0-9._\-]{5,}[^a-zA-Z]*$/ && !/\.(mkv|avi|mp4|mp3|flac|pdf|epub|exe|iso)$/i
    let s = strip_trailing_pcre_newline(name);
    if s.iter().any(|b| b.is_ascii_lowercase()) {
        return false;
    }

    let first_upper = s.iter().position(|b| b.is_ascii_uppercase());
    let matched_first = if let Some(first_u) = first_upper {
        let last_u = s.iter().rposition(|b| b.is_ascii_uppercase()).unwrap();
        if !s[first_u..=last_u].iter().all(|&b| is_punct_mid_char(b)) {
            false
        } else {
            let mut start = first_u;
            while start > 0 && is_punct_mid_char(s[start - 1]) {
                start -= 1;
            }
            let mut end = last_u + 1;
            while end < s.len() && is_punct_mid_char(s[end]) {
                end += 1;
            }
            end - start >= 5
        }
    } else {
        let mut run = 0usize;
        let mut found = false;
        for &b in s {
            if is_punct_mid_char(b) {
                run += 1;
                if run >= 5 {
                    found = true;
                    break;
                }
            } else {
                run = 0;
            }
        }
        found
    };

    if !matched_first {
        return false;
    }

    if let Some(dot_pos) = s.iter().rposition(|&b| b == b'.') {
        let ext = &s[dot_pos + 1..];
        if ext.eq_ignore_ascii_case(b"mkv")
            || ext.eq_ignore_ascii_case(b"avi")
            || ext.eq_ignore_ascii_case(b"mp4")
            || ext.eq_ignore_ascii_case(b"mp3")
            || ext.eq_ignore_ascii_case(b"flac")
            || ext.eq_ignore_ascii_case(b"pdf")
            || ext.eq_ignore_ascii_case(b"epub")
            || ext.eq_ignore_ascii_case(b"exe")
            || ext.eq_ignore_ascii_case(b"iso")
        {
            return false;
        }
    }

    true
}

#[inline]
fn is_media_keyword(word: &[u8]) -> bool {
    if word.len() < 2 || word.len() > 8 {
        return false;
    }
    let mut lower = [0u8; 8];
    for (i, &b) in word.iter().enumerate() {
        lower[i] = b.to_ascii_lowercase();
    }
    matches!(
        &lower[..word.len()],
        b"movie"
            | b"film"
            | b"series"
            | b"episode"
            | b"season"
            | b"show"
            | b"video"
            | b"audio"
            | b"music"
            | b"album"
            | b"dvd"
            | b"bluray"
            | b"hdtv"
            | b"webrip"
            | b"xvid"
            | b"x264"
            | b"x265"
            | b"hevc"
            | b"aac"
            | b"mp3"
            | b"flac"
            | b"720p"
            | b"1080p"
            | b"2160p"
            | b"4k"
            | b"complete"
            | b"proper"
            | b"repack"
            | b"dubbed"
            | b"subbed"
            | b"english"
            | b"french"
            | b"german"
            | b"spanish"
            | b"italian"
            | b"rip"
            | b"web"
            | b"hdr"
            | b"remux"
            | b"disc"
            | b"internal"
            | b"retail"
    )
}

#[inline]
fn contains_media_keyword(name: &[u8]) -> bool {
    let mut i = 0usize;
    let len = name.len();
    while i < len {
        if is_pcre_word(name[i]) {
            let start = i;
            i += 1;
            while i < len && is_pcre_word(name[i]) {
                i += 1;
            }
            if is_media_keyword(&name[start..i]) {
                return true;
            }
        } else {
            i += 1;
        }
    }
    false
}

#[inline]
pub fn is_random_by_character_analysis(core_name: &[u8], original_name: &[u8]) -> bool {
    let core_len = core_name.len();
    let s = strip_trailing_pcre_newline(core_name);
    if core_len < 16 || s.is_empty() || !s.iter().all(|b| b.is_ascii_alphanumeric()) {
        return false;
    }
    let transitions = count_character_transitions(core_name);
    let transition_rate = (transitions as f64) / ((core_len - 1) as f64);
    if transition_rate > 0.35 {
        let name_to_check = if !original_name.is_empty() {
            original_name
        } else {
            core_name
        };
        return !contains_media_keyword(name_to_check);
    }
    false
}

#[inline]
fn is_letters_plus_1_to_4_digits(s: &[u8]) -> bool {
    let mut i = 0usize;
    while i < s.len() && s[i].is_ascii_alphabetic() {
        i += 1;
    }
    if i == 0 {
        return false;
    }
    let digits = s.len() - i;
    (1..=4).contains(&digits) && s[i..].iter().all(|b| b.is_ascii_digit())
}

#[inline]
pub fn has_insufficient_word_structure(core_name: &[u8]) -> bool {
    let core_len = core_name.len();
    let s = strip_trailing_pcre_newline(core_name);
    if core_len < 20 || s.is_empty() || !s.iter().all(|b| b.is_ascii_alphanumeric()) {
        return false;
    }
    get_max_consecutive_letters(core_name) < 5 && !is_letters_plus_1_to_4_digits(s)
}

#[inline]
pub fn is_random_digit_pattern(core_name: &[u8]) -> bool {
    // /^[a-zA-Z]{1,3}\d{6,}[a-zA-Z]*$/i || /^[a-zA-Z0-9]{2,4}\d{8,}$/i
    let s = strip_trailing_pcre_newline(core_name);
    if s.is_empty() || !s.iter().all(|b| b.is_ascii_alphanumeric()) {
        return false;
    }
    let mut leading_letters = 0usize;
    while leading_letters < s.len() && s[leading_letters].is_ascii_alphabetic() {
        leading_letters += 1;
    }
    if (1..=3).contains(&leading_letters) {
        let mut p = leading_letters;
        while p < s.len() && s[p].is_ascii_digit() {
            p += 1;
        }
        let digit_len = p - leading_letters;
        if digit_len >= 6 && s[p..].iter().all(|b| b.is_ascii_alphabetic()) {
            return true;
        }
    }

    if s.len() >= 10 {
        let mut trailing_digits = 0usize;
        while trailing_digits < s.len() && s[s.len() - 1 - trailing_digits].is_ascii_digit() {
            trailing_digits += 1;
        }
        if trailing_digits >= 8 && s.len() - trailing_digits <= 4 {
            return true;
        }
    }

    false
}

#[inline]
pub fn is_zero_vowel_long_token(core_name: &[u8], min_length: usize) -> bool {
    let s = strip_trailing_pcre_newline(core_name);
    if core_name.len() < min_length || s.is_empty() || !s.iter().all(|b| b.is_ascii_alphanumeric())
    {
        return false;
    }
    if has_bounded_year(core_name) {
        return false;
    }
    let mut letter_count = 0usize;
    for &b in core_name {
        if b.is_ascii_alphabetic() {
            if is_vowel(b) {
                return false;
            }
            letter_count += 1;
        }
    }
    letter_count >= 10
}

#[inline]
pub fn has_normal_vowel_ratio(s: &[u8], min: f64) -> bool {
    let mut letter_count = 0usize;
    let mut vowel_count = 0usize;
    for &b in s {
        if b.is_ascii_alphabetic() {
            letter_count += 1;
            if is_vowel(b) {
                vowel_count += 1;
            }
        }
    }
    if letter_count < 5 {
        return false;
    }
    (vowel_count as f64) / (letter_count as f64) >= min
}

#[inline]
pub fn count_alphabetic_word_tokens(name: &[u8]) -> usize {
    let stripped = strip_extensions_for_analysis(name);
    let mut count = 0usize;
    let mut i = 0usize;
    let len = stripped.len();
    while i < len {
        if stripped[i] == b'.'
            || stripped[i] == b'_'
            || stripped[i] == b'-'
            || is_pcre_space(stripped[i])
        {
            i += 1;
            continue;
        }
        let mut consec_alpha = 0usize;
        let mut has_three_alpha = false;
        while i < len
            && stripped[i] != b'.'
            && stripped[i] != b'_'
            && stripped[i] != b'-'
            && !is_pcre_space(stripped[i])
        {
            if stripped[i].is_ascii_alphabetic() {
                consec_alpha += 1;
                if consec_alpha >= 3 {
                    has_three_alpha = true;
                }
            } else {
                consec_alpha = 0;
            }
            i += 1;
        }
        if has_three_alpha {
            count += 1;
        }
    }
    count
}

#[inline]
pub fn has_strong_word_structure(name: &[u8], core_name: &[u8]) -> bool {
    get_max_consecutive_letters(core_name) >= 5
        && has_normal_vowel_ratio(core_name, 0.2)
        && count_alphabetic_word_tokens(name) >= 2
}

#[inline]
pub fn is_hashed_or_gibberish_bytes(name: &[u8]) -> bool {
    let cleaned = strip_extensions_for_analysis(name);
    let core_buf = CoreNameBuf::from_cleaned(cleaned);
    let core_name = core_buf.as_slice();

    is_uuid_pattern(core_name)
        || is_pure_hex_string(core_name, 16)
        || bounded_hex_hash_mask(name) != 0
        || is_bounded_generic_hash(name)
        || is_base64_like_token(name)
        || is_obfuscated_uppercase_string(name)
        || is_obfuscated_mixed_alphanumeric(name)
        || is_obfuscated_usenet_filename(name)
        || is_random_by_character_analysis(core_name, name)
        || has_insufficient_word_structure(core_name)
        || is_zero_vowel_long_token(core_name, 20)
        || is_random_digit_pattern(core_name)
}

#[inline]
fn is_season_token(tok: &[u8]) -> bool {
    (2..=4).contains(&tok.len())
        && (tok[0] == b's' || tok[0] == b'S')
        && tok[1..].iter().all(|b| b.is_ascii_digit())
}

#[inline]
fn is_episode_token(tok: &[u8]) -> bool {
    (2..=5).contains(&tok.len())
        && (tok[0] == b'e' || tok[0] == b'E')
        && tok[1..].iter().all(|b| b.is_ascii_digit())
}

#[inline]
fn is_season_episode_single_token(tok: &[u8]) -> bool {
    // Matches ^S\d{1,3}_?E\d{1,4}$ case-insensitively
    if tok.len() < 4 || tok.len() > 10 || (tok[0] != b's' && tok[0] != b'S') {
        return false;
    }
    let mut i = 1usize;
    while i < tok.len() && tok[i].is_ascii_digit() {
        i += 1;
    }
    let s_digits = i - 1;
    if !(1..=3).contains(&s_digits) {
        return false;
    }
    if i < tok.len() && tok[i] == b'_' {
        i += 1;
    }
    if i >= tok.len() || (tok[i] != b'e' && tok[i] != b'E') {
        return false;
    }
    i += 1;
    let e_digits = tok.len() - i;
    (1..=4).contains(&e_digits) && tok[i..].iter().all(|b| b.is_ascii_digit())
}

#[inline]
pub fn compute_signal_markers_mask(name: &[u8]) -> u16 {
    let mut mask = 0u16;
    let mut i = 0usize;
    let len = name.len();
    let mut prev_end: Option<usize> = None;
    let mut prev_was_s = false;
    let mut prev_was_h = false;
    let mut prev_was_web = false;

    while i < len {
        if is_pcre_word(name[i]) {
            let start = i;
            i += 1;
            while i < len && is_pcre_word(name[i]) {
                i += 1;
            }
            let tok = &name[start..i];
            let sep_byte = match prev_end {
                Some(pe) if pe + 1 == start => Some(name[pe]),
                _ => None,
            };

            // 0: season_episode (/\bS\d{1,3}[._ -]?E\d{1,4}\b/i)
            if is_season_episode_single_token(tok)
                || (prev_was_s
                    && matches!(sep_byte, Some(b'.' | b' ' | b'-'))
                    && is_episode_token(tok))
            {
                mask |= 1 << 0;
            }

            // 1: season_pack (/\bS\d{1,3}\b/i)
            let curr_is_s = is_season_token(tok);
            if curr_is_s {
                mask |= 1 << 1;
            }

            if tok.len() <= 8 {
                let mut lower = [0u8; 8];
                for (k, &b) in tok.iter().enumerate() {
                    lower[k] = b.to_ascii_lowercase();
                }
                let lw = &lower[..tok.len()];

                // 2: resolution (/\b(480p|576p|720p|1080[pi]?|2160p|4k|uhd)\b/i)
                if matches!(
                    lw,
                    b"480p"
                        | b"576p"
                        | b"720p"
                        | b"1080"
                        | b"1080p"
                        | b"1080i"
                        | b"2160p"
                        | b"4k"
                        | b"uhd"
                ) {
                    mask |= 1 << 2;
                }

                // 3: codec (/\b(x264|x265|h\.?264|h\.?265|hevc|xvid|av1)\b/i)
                if matches!(
                    lw,
                    b"x264" | b"x265" | b"h264" | b"h265" | b"hevc" | b"xvid" | b"av1"
                ) || (prev_was_h && sep_byte == Some(b'.') && matches!(lw, b"264" | b"265"))
                {
                    mask |= 1 << 3;
                }

                // 4: source (/\b(bluray|bdrip|brrip|hdtv|web[._ -]?dl|web[._ -]?rip|dvdrip|remux)\b/i)
                if matches!(
                    lw,
                    b"bluray"
                        | b"bdrip"
                        | b"brrip"
                        | b"hdtv"
                        | b"webdl"
                        | b"web_dl"
                        | b"webrip"
                        | b"web_rip"
                        | b"dvdrip"
                        | b"remux"
                ) || (prev_was_web
                    && matches!(sep_byte, Some(b'.' | b' ' | b'-'))
                    && matches!(lw, b"dl" | b"rip"))
                {
                    mask |= 1 << 4;
                }

                // 5: audio (/\b(aac|ac3|ddp|dts|flac|mp3)\b/i)
                if matches!(lw, b"aac" | b"ac3" | b"ddp" | b"dts" | b"flac" | b"mp3") {
                    mask |= 1 << 5;
                }

                // 6: scene_tag (/\b(proper|repack|internal|limited|complete|dubbed|subbed|readnfo)\b/i)
                if matches!(
                    lw,
                    b"proper"
                        | b"repack"
                        | b"internal"
                        | b"limited"
                        | b"complete"
                        | b"dubbed"
                        | b"subbed"
                        | b"readnfo"
                ) {
                    mask |= 1 << 6;
                }

                // 7: year (/\b(19|20)\d{2}\b/)
                if lw.len() == 4
                    && ((lw[0] == b'1' && lw[1] == b'9') || (lw[0] == b'2' && lw[1] == b'0'))
                    && lw[2].is_ascii_digit()
                    && lw[3].is_ascii_digit()
                {
                    mask |= 1 << 7;
                }

                prev_was_h = lw == b"h";
                prev_was_web = lw == b"web";
            } else {
                prev_was_h = false;
                prev_was_web = false;
            }

            prev_was_s = curr_is_s;
            prev_end = Some(i);
        } else {
            i += 1;
        }
    }

    let s = strip_trailing_pcre_newline(name);

    // 8: release_group (/-[A-Za-z0-9][A-Za-z0-9._-]{1,20}$/)
    if s.len() >= 3 {
        let min_dash = s.len().saturating_sub(22);
        let max_dash = s.len() - 3;
        for dash_idx in min_dash..=max_dash {
            if s[dash_idx] == b'-'
                && s[dash_idx + 1].is_ascii_alphanumeric()
                && s[dash_idx + 2..]
                    .iter()
                    .all(|&b| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'-'))
            {
                mask |= 1 << 8;
                break;
            }
        }
    }

    // 9: known_extension (/\.(mkv|avi|mp4|mp3|flac|iso|epub|pdf|exe|nzb|rar|7z)$/i)
    if let Some(dot_pos) = s.iter().rposition(|&b| b == b'.') {
        let ext = &s[dot_pos + 1..];
        if ext.len() >= 2 && ext.len() <= 4 {
            let mut lower = [0u8; 4];
            for (k, &b) in ext.iter().enumerate() {
                lower[k] = b.to_ascii_lowercase();
            }
            if matches!(
                &lower[..ext.len()],
                b"mkv"
                    | b"avi"
                    | b"mp4"
                    | b"mp3"
                    | b"flac"
                    | b"iso"
                    | b"epub"
                    | b"pdf"
                    | b"exe"
                    | b"nzb"
                    | b"rar"
                    | b"7z"
            ) {
                mask |= 1 << 9;
            }
        }
    }

    mask
}

#[inline]
fn is_archive_extension(name: &[u8]) -> bool {
    // /\.(zip|rar|7z|tar|gz|bz2|xz|tgz|tbz2|cab|iso|img|dmg|pkg|archive)$/i
    let s = strip_trailing_pcre_newline(name);
    let Some(dot_pos) = s.iter().rposition(|&b| b == b'.') else {
        return false;
    };
    let ext = &s[dot_pos + 1..];
    if ext.len() < 2 || ext.len() > 7 {
        return false;
    }
    let mut lower = [0u8; 7];
    for (i, &b) in ext.iter().enumerate() {
        lower[i] = b.to_ascii_lowercase();
    }
    matches!(
        &lower[..ext.len()],
        b"zip"
            | b"rar"
            | b"7z"
            | b"tar"
            | b"gz"
            | b"bz2"
            | b"xz"
            | b"tgz"
            | b"tbz2"
            | b"cab"
            | b"iso"
            | b"img"
            | b"dmg"
            | b"pkg"
            | b"archive"
    )
}

#[inline]
fn check_dataset_code(name: &[u8]) -> u8 {
    let mut has_dataset_kw = false;
    let mut has_leak_kw = false;
    let mut has_dump_kw = false;
    let mut has_media_kw = false;

    let mut i = 0usize;
    let len = name.len();
    while i < len {
        if is_pcre_word(name[i]) {
            let start = i;
            i += 1;
            while i < len && is_pcre_word(name[i]) {
                i += 1;
            }
            let tok = &name[start..i];
            if tok.len() >= 2 && tok.len() <= 10 {
                let mut lower = [0u8; 10];
                for (k, &b) in tok.iter().enumerate() {
                    lower[k] = b.to_ascii_lowercase();
                }
                let lw = &lower[..tok.len()];
                if matches!(
                    lw,
                    b"sql" | b"csv" | b"dump" | b"backup" | b"dataset" | b"collection"
                ) {
                    has_dataset_kw = true;
                }
                if matches!(lw, b"leak" | b"breach" | b"data" | b"database") {
                    has_leak_kw = true;
                }
                if matches!(lw, b"dump" | b"export" | b"backup") {
                    has_dump_kw = true;
                }
                if matches!(
                    lw,
                    b"movie" | b"tv" | b"show" | b"audio" | b"video" | b"book" | b"game"
                ) {
                    has_media_kw = true;
                }
            }
        } else {
            i += 1;
        }
    }

    if !has_media_kw {
        if has_dataset_kw {
            return 18; // dataset
        }
        if has_leak_kw && has_dump_kw {
            return 19; // data_dump
        }
    }
    0
}

pub fn misc_inspect_and_categorize(name: &[u8]) -> (u16, u8, u8, u16, bool, CoreNameBuf) {
    let cleaned = strip_extensions_for_analysis(name);
    let core_buf = CoreNameBuf::from_cleaned(cleaned);
    let core_name = core_buf.as_slice();
    let markers_mask = compute_signal_markers_mask(name);
    let signal_score = markers_mask.count_ones();
    let core_s = strip_trailing_pcre_newline(core_name);
    let is_core_token = !core_s.is_empty()
        && core_s
            .iter()
            .all(|&b| b.is_ascii_alphanumeric() || matches!(b, b'+' | b'/' | b'_' | b'=' | b'-'));
    let strong_words = has_strong_word_structure(name, core_name);
    let low_signal = signal_score == 0 && is_core_token && core_name.len() >= 12 && !strong_words;

    // 1. checkHash($name)
    let hex_mask = bounded_hex_hash_mask(name);
    if (hex_mask & 1) != 0 {
        return (20, 95, 1, markers_mask, low_signal, core_buf);
    }
    if (hex_mask & 2) != 0 {
        return (20, 95, 2, markers_mask, low_signal, core_buf);
    }
    if (hex_mask & 4) != 0 {
        return (20, 95, 3, markers_mask, low_signal, core_buf);
    }
    if is_bounded_generic_hash(name) {
        return (20, 95, 4, markers_mask, low_signal, core_buf);
    }
    if is_base64_like_token(name) {
        return (20, 90, 5, markers_mask, low_signal, core_buf);
    }
    if is_uuid_pattern(core_name) {
        return (20, 95, 6, markers_mask, low_signal, core_buf);
    }
    if is_pure_hex_string(core_name, 16) {
        return (20, 95, 7, markers_mask, low_signal, core_buf);
    }

    // 2. Zero-vowel check on analysis['coreName']
    if is_zero_vowel_long_token(core_name, 20) {
        return (20, 78, 8, markers_mask, low_signal, core_buf);
    }

    // 3. checkObfuscated($name)
    if is_obfuscated_uppercase_string(name) {
        return (20, 70, 9, markers_mask, low_signal, core_buf);
    }
    if is_obfuscated_mixed_alphanumeric(name) {
        return (20, 70, 10, markers_mask, low_signal, core_buf);
    }
    if is_obfuscated_usenet_filename(name) {
        return (20, 85, 11, markers_mask, low_signal, core_buf);
    }
    if is_obfuscated_punctuation(name) {
        let hash_like = is_zero_vowel_long_token(core_name, 12) || low_signal;
        return (
            if hash_like { 20 } else { 10 },
            if hash_like { 75 } else { 50 },
            12,
            markers_mask,
            low_signal,
            core_buf,
        );
    }

    // 4. checkLowSignal($name)
    if !strong_words && low_signal && core_name.len() >= 20 {
        return (20, 80, 13, markers_mask, low_signal, core_buf);
    }

    // 5. checkGibberish($name)
    if is_random_by_character_analysis(core_name, name) {
        return (20, 75, 14, markers_mask, low_signal, core_buf);
    }
    if has_insufficient_word_structure(core_name) {
        return (20, 75, 15, markers_mask, low_signal, core_buf);
    }
    if is_random_digit_pattern(core_name) {
        return (20, 70, 16, markers_mask, low_signal, core_buf);
    }

    // 6. checkArchive($name)
    if is_archive_extension(name) {
        return (10, 50, 17, markers_mask, low_signal, core_buf);
    }

    // 7. checkDataset($name)
    let dataset_code = check_dataset_code(name);
    if dataset_code != 0 {
        return (10, 60, dataset_code, markers_mask, low_signal, core_buf);
    }

    (10, 0, 0, markers_mask, low_signal, core_buf)
}

/// Checks whether a single release or file name is hashed or gibberish.
///
/// # Safety
/// `input_ptr` must be valid for `input_len` bytes when non-null and `input_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_is_hashed_or_gibberish(
    input_ptr: *const u8,
    input_len: usize,
) -> i32 {
    let input = if input_ptr.is_null() || input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input_ptr, input_len) }
    };
    if is_hashed_or_gibberish_bytes(input) {
        1
    } else {
        0
    }
}

/// Checks a NUL-separated batch of release/file names for hashed/gibberish patterns.
///
/// # Safety
/// `input_ptr` must be valid for `input_len` bytes and `out_ptr` must be writable for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_is_hashed_or_gibberish_batch_nul(
    input_ptr: *const u8,
    input_len: usize,
    count: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    if count == 0 {
        return 0;
    }
    let input = if input_ptr.is_null() || input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input_ptr, input_len) }
    };
    let mut cursor = unsafe { OutCursor::new(out_ptr, out_cap) };
    let mut start = 0usize;
    let mut processed = 0usize;

    while processed < count {
        let rel_end = crate::find_nul(&input[start..]);
        let end = match rel_end {
            Some(offset) => start + offset,
            None => input.len(),
        };
        let slice = &input[start..end];
        cursor.push(if is_hashed_or_gibberish_bytes(slice) {
            b'1'
        } else {
            b'0'
        });
        processed += 1;
        start = if end < input.len() {
            end + 1
        } else {
            input.len()
        };
    }

    cursor.finish()
}

/// Inspects signal markers and categorizes a release name for `MiscCategorizer` / `MiscPipe`.
///
/// # Safety
/// `input_ptr` must be valid for `input_len` bytes and `out_ptr` must be writable for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_misc_inspect_and_categorize(
    input_ptr: *const u8,
    input_len: usize,
    out_ptr: *mut u8,
    out_cap: usize,
) -> isize {
    let input = if input_ptr.is_null() || input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input_ptr, input_len) }
    };
    let (category_id, confidence_hundredths, matched_by_code, markers_mask, low_signal, core_name) =
        misc_inspect_and_categorize(input);

    let mut cursor = unsafe { OutCursor::new(out_ptr, out_cap) };
    cursor.extend(&category_id.to_le_bytes());
    cursor.push(confidence_hundredths);
    cursor.push(matched_by_code);
    cursor.extend(&markers_mask.to_le_bytes());
    cursor.push(if low_signal { 1 } else { 0 });
    cursor.extend(core_name.as_slice());
    cursor.finish()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_hashed_names_detection() {
        let cases = [
            (b"d41d8cd98f00b204e9800998ecf8427e".as_slice(), 1u8),
            (b"\"d41d8cd98f00b204e9800998ecf8427e\"".as_slice(), 1u8),
            (b"da39a3ee5e6b4b0d3255bfef95601890afd80709".as_slice(), 2u8),
            (
                b"e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855".as_slice(),
                3u8,
            ),
            (b"550e8400-e29b-41d4-a716-446655440000".as_slice(), 6u8),
            (b"aabbccdd0011223344ff".as_slice(), 7u8),
            (b"ABCDEFGH1234567890XY".as_slice(), 9u8),
            (b"AA7Jl2toE8Q53yNZmQ5R6G".as_slice(), 10u8),
            (
                b"[01/10] - \"xK9mR2pL4qW7nT3vB.part01.rar\"".as_slice(),
                11u8,
            ),
            (b"VGhpc0lzTm90QVNob3dOYW1lMTIzNDU2Nzg5MA==".as_slice(), 5u8),
            (
                b"VGhpc0lzTm90QVNob3dOYW1lMTIzNDU2Nzg5MA==-foo:bar".as_slice(),
                5u8,
            ),
            (b"aB3c.D4eF.5gH6i.J7kL8m".as_slice(), 14u8),
            (b"xyz.1234.wvut.5678.srqp.9012.rar".as_slice(), 15u8),
            (b"xz.123456789012".as_slice(), 16u8),
            (b"a1.2345.6789".as_slice(), 16u8),
            (b"xkcdqwrtypsdfghjklmnbvcxz".as_slice(), 8u8),
            (b"A1b2.C3d4.E5f6.G7h8.I9j0".as_slice(), 13u8),
        ];

        for (input, expected_code) in cases {
            let (cat, _, code, _, _, _) = misc_inspect_and_categorize(input);
            assert_eq!(
                code,
                expected_code,
                "failed for {:?}",
                String::from_utf8_lossy(input)
            );
            assert_eq!(cat, 20);
        }
        assert!(is_hashed_or_gibberish_bytes(b"123456789012"));
    }

    #[test]
    fn test_legitimate_names_not_hashed() {
        let cases = [
            b"Some.Movie.2024.1080p.BluRay.x264-GROUP".as_slice(),
            b"Show.Name.S03E05.720p.HDTV.x264-GROUP".as_slice(),
            b"The.Daily.Show.20260419.mkv".as_slice(),
            b"Artist.Name-Album.Title-2024-FLAC-GROUP".as_slice(),
            b"Starfield-RUNE".as_slice(),
            b"Microsoft Office Suite Installer".as_slice(),
            b"Adobe Express Photos.Msixbundle".as_slice(),
        ];

        for input in cases {
            let (_, _, code, _, low_signal, _) = misc_inspect_and_categorize(input);
            assert_eq!(
                code,
                0,
                "unexpected match for {:?}",
                String::from_utf8_lossy(input)
            );
            assert!(
                !low_signal,
                "unexpected low_signal for {:?}",
                String::from_utf8_lossy(input)
            );
            assert!(!is_hashed_or_gibberish_bytes(input));
        }
    }
}
