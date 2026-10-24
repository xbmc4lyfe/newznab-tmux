use std::slice;

struct OutCursor {
    buf: *mut u8,
    cap: usize,
    len: usize,
}

impl OutCursor {
    #[inline]
    fn new(buf: *mut u8, cap: usize) -> Self {
        Self { buf, cap, len: 0 }
    }

    #[inline]
    fn push_byte(&mut self, b: u8) {
        if self.len < self.cap && !self.buf.is_null() {
            unsafe {
                *self.buf.add(self.len) = b;
            }
        }
        self.len += 1;
    }

    #[inline]
    fn push_slice(&mut self, bytes: &[u8]) {
        if bytes.is_empty() {
            return;
        }
        if self.len < self.cap && !self.buf.is_null() {
            let avail = self.cap - self.len;
            let to_copy = avail.min(bytes.len());
            unsafe {
                std::ptr::copy_nonoverlapping(bytes.as_ptr(), self.buf.add(self.len), to_copy);
            }
        }
        self.len += bytes.len();
    }

    #[inline]
    fn finish(self) -> isize {
        if self.len > self.cap {
            -(self.len.max(4) as isize)
        } else {
            self.len as isize
        }
    }
}

/// Stack-backed byte buffer avoiding heap allocations for release names <= 512 bytes.
struct SmallBytes {
    stack: [u8; 512],
    len: usize,
    heap: Vec<u8>,
}

impl SmallBytes {
    #[inline]
    fn new() -> Self {
        Self {
            stack: [0u8; 512],
            len: 0,
            heap: Vec::new(),
        }
    }

    #[inline]
    fn clear(&mut self) {
        self.len = 0;
        if !self.heap.is_empty() {
            self.heap.clear();
        }
    }

    #[inline]
    fn push(&mut self, b: u8) {
        if !self.heap.is_empty() {
            self.heap.push(b);
        } else if self.len < 512 {
            self.stack[self.len] = b;
            self.len += 1;
        } else {
            self.heap.reserve(1024);
            self.heap.extend_from_slice(&self.stack[..self.len]);
            self.heap.push(b);
        }
    }

    #[inline]
    fn extend_from_slice(&mut self, s: &[u8]) {
        if !self.heap.is_empty() {
            self.heap.extend_from_slice(s);
        } else if self.len + s.len() <= 512 {
            self.stack[self.len..self.len + s.len()].copy_from_slice(s);
            self.len += s.len();
        } else {
            self.heap.reserve((self.len + s.len()).max(1024));
            self.heap.extend_from_slice(&self.stack[..self.len]);
            self.heap.extend_from_slice(s);
        }
    }

    #[inline]
    fn truncate(&mut self, new_len: usize) {
        if !self.heap.is_empty() {
            self.heap.truncate(new_len);
        } else if new_len < self.len {
            self.len = new_len;
        }
    }

    #[inline]
    fn as_slice(&self) -> &[u8] {
        if !self.heap.is_empty() {
            &self.heap
        } else {
            &self.stack[..self.len]
        }
    }

    #[inline]
    fn as_mut_slice(&mut self) -> &mut [u8] {
        if !self.heap.is_empty() {
            &mut self.heap
        } else {
            &mut self.stack[..self.len]
        }
    }
}

#[inline]
fn is_printable_ascii_no_newline(bytes: &[u8]) -> bool {
    for &b in bytes {
        if b == 0 || b == b'\n' || b >= 0x80 {
            return false;
        }
    }
    true
}

#[inline]
fn is_word_byte(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_'
}

#[inline]
fn is_ascii_ws(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C)
}

#[inline]
fn is_php_trim_ws(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B)
}

#[inline]
fn trim_php_ws(mut s: &[u8]) -> &[u8] {
    while let Some(&b) = s.first() {
        if is_php_trim_ws(b) {
            s = &s[1..];
        } else {
            break;
        }
    }
    while let Some(&b) = s.last() {
        if is_php_trim_ws(b) {
            s = &s[..s.len() - 1];
        } else {
            break;
        }
    }
    s
}

#[inline]
fn collapse_multi_spaces_into(s: &[u8], out: &mut SmallBytes) {
    out.clear();
    let mut i = 0;
    while i < s.len() {
        if is_ascii_ws(s[i]) {
            let start = i;
            while i < s.len() && is_ascii_ws(s[i]) {
                i += 1;
            }
            if i - start >= 2 {
                out.push(b' ');
            } else {
                out.push(s[start]);
            }
        } else {
            out.push(s[i]);
            i += 1;
        }
    }
}

// ============================================================================
// AbstractTvProvider::parseInfo & helpers
// ============================================================================

/// Jump-table dispatched matcher for `AbstractTvProvider::parseName`'s `$following` core at `p`.
#[inline]
fn match_tv_following_core(s: &[u8], p: usize) -> Option<usize> {
    let rem = s.get(p..)?;
    let first = *rem.first()?;

    match first.to_ascii_lowercase() {
        b'0'..=b'9' => {
            // 1. \d\d-\d\d
            if rem.len() >= 5
                && rem[1].is_ascii_digit()
                && rem[2] == b'-'
                && rem[3].is_ascii_digit()
                && rem[4].is_ascii_digit()
            {
                return Some(p + 5);
            }
            // 2. \d{1,3}x\d{2,3}
            let mut d1 = 1;
            while d1 < 3 && d1 < rem.len() && rem[d1].is_ascii_digit() {
                d1 += 1;
            }
            if d1 < rem.len() && matches!(rem[d1], b'x' | b'X') {
                let mut d2 = 0;
                while d2 < 3 && (d1 + 1 + d2) < rem.len() && rem[d1 + 1 + d2].is_ascii_digit() {
                    d2 += 1;
                }
                if d2 >= 2 {
                    return Some(p + d1 + 1 + d2);
                }
            }
            // 3. (19|20)\d{2}\)?
            if rem.len() >= 4
                && ((rem[0] == b'1' && rem[1] == b'9') || (rem[0] == b'2' && rem[1] == b'0'))
                && rem[2].is_ascii_digit()
                && rem[3].is_ascii_digit()
            {
                let end = 4 + usize::from(rem.get(4) == Some(&b')'));
                return Some(p + end);
            }
            // 4. (480|720|1080|2160)[ip]
            for res in [&b"480"[..], b"720", b"1080", b"2160"] {
                if rem.len() > res.len()
                    && rem.starts_with(res)
                    && matches!(rem[res.len()], b'i' | b'I' | b'p' | b'P')
                {
                    return Some(p + res.len() + 1);
                }
            }
            None
        }
        b'(' => {
            // 3. \((19|20)\d{2}\)?
            if rem.len() >= 5
                && ((rem[1] == b'1' && rem[2] == b'9') || (rem[1] == b'2' && rem[2] == b'0'))
                && rem[3].is_ascii_digit()
                && rem[4].is_ascii_digit()
            {
                let end = 5 + usize::from(rem.get(5) == Some(&b')'));
                return Some(p + end);
            }
            None
        }
        b's' => {
            // 18. SDTV
            if rem.len() >= 4 && rem[1..4].eq_ignore_ascii_case(b"dtv") {
                return Some(p + 4);
            }
            // 21. Season
            if rem.len() >= 6 && rem[..6].eq_ignore_ascii_case(b"season") {
                return Some(p + 6);
            }
            // 23. S\d+[^a-z0-9]?((E\d+)[abr]?)*
            if rem.len() >= 2 && rem[1].is_ascii_digit() {
                let mut q = 2;
                while q < rem.len() && rem[q].is_ascii_digit() {
                    q += 1;
                }
                if q < rem.len() && !rem[q].is_ascii_alphanumeric() {
                    q += 1;
                }
                while q + 1 < rem.len()
                    && matches!(rem[q], b'e' | b'E')
                    && rem[q + 1].is_ascii_digit()
                {
                    q += 2;
                    while q < rem.len() && rem[q].is_ascii_digit() {
                        q += 1;
                    }
                    if q < rem.len() && matches!(rem[q], b'a' | b'A' | b'b' | b'B' | b'r' | b'R') {
                        q += 1;
                    }
                }
                return Some(p + q);
            }
            None
        }
        b'e' => {
            // 14. E\d{2,3}
            let mut d = 0;
            while d < 3 && (1 + d) < rem.len() && rem[1 + d].is_ascii_digit() {
                d += 1;
            }
            if d >= 2 {
                return Some(p + 1 + d);
            }
            // 22. Episode
            if rem.len() >= 7 && rem[..7].eq_ignore_ascii_case(b"episode") {
                return Some(p + 7);
            }
            None
        }
        b'a' => {
            // 5. AAC2?
            if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"aac") {
                return Some(p + 3 + usize::from(rem.get(3) == Some(&b'2')));
            }
            None
        }
        b'b' => {
            // 6. BD-?Rip
            if rem.len() >= 5 && rem[..2].eq_ignore_ascii_case(b"bd") {
                let dash = usize::from(rem[2] == b'-');
                if rem.len() >= 5 + dash && rem[2 + dash..5 + dash].eq_ignore_ascii_case(b"rip") {
                    return Some(p + 5 + dash);
                }
            }
            // 7. Blu-?Ray
            if rem.len() >= 6 && rem[..3].eq_ignore_ascii_case(b"blu") {
                let dash = usize::from(rem[3] == b'-');
                if rem.len() >= 6 + dash && rem[3 + dash..6 + dash].eq_ignore_ascii_case(b"ray") {
                    return Some(p + 6 + dash);
                }
            }
            None
        }
        b'd' => {
            // 8. D0?\d
            if rem.len() >= 3 && rem[1] == b'0' && rem[2].is_ascii_digit() {
                return Some(p + 3);
            }
            if rem.len() >= 2 && rem[1].is_ascii_digit() {
                return Some(p + 2);
            }
            // 9. DD5
            if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"dd5") {
                return Some(p + 3);
            }
            // 10. DiVX
            if rem.len() >= 4 && rem[..4].eq_ignore_ascii_case(b"divx") {
                return Some(p + 4);
            }
            // 11. DLMux
            if rem.len() >= 5 && rem[..5].eq_ignore_ascii_case(b"dlmux") {
                return Some(p + 5);
            }
            // 12. DTS
            if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"dts") {
                return Some(p + 3);
            }
            // 13. DVD(-?Rip)?
            if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"dvd") {
                if rem.len() >= 6 {
                    let dash = usize::from(rem[3] == b'-');
                    if rem.len() >= 6 + dash && rem[3 + dash..6 + dash].eq_ignore_ascii_case(b"rip")
                    {
                        return Some(p + 6 + dash);
                    }
                }
                return Some(p + 3);
            }
            None
        }
        b'h' => {
            // 15. H[\-_. ]?26[45]
            if rem.len() >= 4 {
                let sep = usize::from(matches!(rem[1], b'-' | b'_' | b'.' | b' '));
                if rem.len() >= 4 + sep
                    && rem[1 + sep] == b'2'
                    && rem[2 + sep] == b'6'
                    && matches!(rem[3 + sep], b'4' | b'5')
                {
                    return Some(p + 4 + sep);
                }
            }
            // 17. HEVC
            if rem.len() >= 4 && rem[..4].eq_ignore_ascii_case(b"hevc") {
                return Some(p + 4);
            }
            // 18. HDTV
            if rem.len() >= 4 && rem[1..4].eq_ignore_ascii_case(b"dtv") {
                return Some(p + 4);
            }
            None
        }
        b'x' => {
            // 15. X[\-_. ]?26[45]
            if rem.len() >= 4 {
                let sep = usize::from(matches!(rem[1], b'-' | b'_' | b'.' | b' '));
                if rem.len() >= 4 + sep
                    && rem[1 + sep] == b'2'
                    && rem[2 + sep] == b'6'
                    && matches!(rem[3 + sep], b'4' | b'5')
                {
                    return Some(p + 4 + sep);
                }
            }
            // 25. XViD
            if rem.len() >= 4 && rem[..4].eq_ignore_ascii_case(b"xvid") {
                return Some(p + 4);
            }
            None
        }
        b'i' => {
            // 16. ITA(-ENG)?
            if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"ita") {
                if rem.len() >= 7 && rem[3..7].eq_ignore_ascii_case(b"-eng") {
                    return Some(p + 7);
                }
                return Some(p + 3);
            }
            None
        }
        b'p' => {
            // 18. PDTV
            if rem.len() >= 4 && rem[1..4].eq_ignore_ascii_case(b"dtv") {
                return Some(p + 4);
            }
            // 19. PROPER
            if rem.len() >= 6 && rem[..6].eq_ignore_ascii_case(b"proper") {
                return Some(p + 6);
            }
            None
        }
        b'r' => {
            // 20. REPACK
            if rem.len() >= 6 && rem[..6].eq_ignore_ascii_case(b"repack") {
                return Some(p + 6);
            }
            None
        }
        b'w' => {
            // 24. WEB[\-_. ]?(DL|Rip)
            if rem.len() >= 5 && rem[..3].eq_ignore_ascii_case(b"web") {
                let sep = usize::from(matches!(rem[3], b'-' | b'_' | b'.' | b' '));
                let rest = &rem[3 + sep..];
                if rest.len() >= 2 && rest[..2].eq_ignore_ascii_case(b"dl") {
                    return Some(p + 3 + sep + 2);
                }
                if rest.len() >= 3 && rest[..3].eq_ignore_ascii_case(b"rip") {
                    return Some(p + 3 + sep + 3);
                }
            }
            None
        }
        _ => None,
    }
}

/// Matches `$following` starting at `pos` in `s`, returning `Some(end_index)`.
#[inline]
fn matches_tv_following_at(s: &[u8], pos: usize) -> Option<usize> {
    if pos >= s.len() || s[pos].is_ascii_alphanumeric() {
        return None;
    }
    let mut p = pos + 1;
    loop {
        if let Some(core_end) = match_tv_following_core(s, p) {
            let end = if core_end < s.len() && !s[core_end].is_ascii_alphanumeric() {
                core_end + 1
            } else {
                core_end
            };
            return Some(end);
        }
        if p >= s.len() || !matches!(s[p], b'(' | b'|' | b'[') {
            return None;
        }
        let mut q = p + 1;
        while q < s.len() && is_word_byte(s[q]) {
            q += 1;
        }
        if q == p + 1 || q + 1 >= s.len() {
            return None;
        }
        if !matches!(s[q], b')' | b'|' | b']') || !is_ascii_ws(s[q + 1]) {
            return None;
        }
        p = q + 2;
    }
}

#[inline]
fn is_tv_b1_name_char(b: u8) -> bool {
    is_word_byte(b) || matches!(b, b' ' | b'.' | b'-' | b'!' | b'&')
}

#[inline]
fn is_tv_b2_first_char(b: u8) -> bool {
    is_word_byte(b) || matches!(b, b'!' | b'&')
}

#[inline]
fn is_tv_b2_cont_char(b: u8) -> bool {
    is_word_byte(b) || is_ascii_ws(b) || matches!(b, b'\'' | b'.' | b'-' | b'!' | b'&')
}

/// Implements `AbstractTvProvider::convertAcronyms` into `out`.
fn convert_acronyms_into(s: &[u8], out: &mut SmallBytes) {
    out.clear();
    let mut i = 0;
    while i < s.len() {
        let prev_is_word = i > 0 && is_word_byte(s[i - 1]);
        if !prev_is_word && s[i].is_ascii_alphabetic() && i + 1 < s.len() && s[i + 1] == b'.' {
            let mut max_pairs = 0usize;
            while i + max_pairs * 2 + 1 < s.len()
                && s[i + max_pairs * 2].is_ascii_alphabetic()
                && s[i + max_pairs * 2 + 1] == b'.'
            {
                max_pairs += 1;
            }
            if max_pairs >= 2 {
                let mut matched_end: Option<usize> = None;
                'search: for pairs in (2..=max_pairs).rev() {
                    let base = i + pairs * 2;
                    let can_have_letter = base < s.len() && s[base].is_ascii_alphabetic();
                    if can_have_letter {
                        if base + 1 < s.len() && s[base + 1] == b'.' {
                            let end = base + 2;
                            let next_is_word = end < s.len() && is_word_byte(s[end]);
                            if next_is_word {
                                matched_end = Some(end);
                                break 'search;
                            }
                        }
                        {
                            let end = base + 1;
                            let next_is_word = end < s.len() && is_word_byte(s[end]);
                            if !next_is_word {
                                matched_end = Some(end);
                                break 'search;
                            }
                        }
                    }
                    {
                        let end = base;
                        let next_is_word = end < s.len() && is_word_byte(s[end]);
                        if next_is_word {
                            matched_end = Some(end);
                            break 'search;
                        }
                    }
                }
                if let Some(end) = matched_end {
                    for &b in &s[i..end] {
                        if b != b'.' {
                            out.push(b);
                        }
                    }
                    i = end;
                    continue;
                }
            }
        }
        out.push(s[i]);
        i += 1;
    }
}

/// Implements `AbstractTvProvider::parseName($relname)` into `out_name` without heap allocations.
fn tv_parse_name_into(relname: &[u8], out_name: &mut SmallBytes, scratch: &mut SmallBytes) {
    out_name.clear();
    let mut clean_rel = relname;
    if clean_rel.first() == Some(&b'[') {
        if let Some(close_idx) = clean_rel[1..].iter().position(|&b| b == b']') {
            let close_pos = 1 + close_idx;
            if close_pos > 1 {
                let mut p = close_pos + 1;
                while p < clean_rel.len() && (clean_rel[p] == b'_' || is_ascii_ws(clean_rel[p])) {
                    p += 1;
                }
                clean_rel = &clean_rel[p..];
            }
        }
    }

    let mut show_name: Option<&[u8]> = None;

    // Branch 1: `/^([^a-z0-9]{2,}|(sample|proof|repost)-)(?P<name>[\w .\-!&]+?)'.$following.'/i`
    {
        let mut non_alnum_len = 0usize;
        while non_alnum_len < clean_rel.len() && !clean_rel[non_alnum_len].is_ascii_alphanumeric() {
            non_alnum_len += 1;
        }
        if non_alnum_len >= 2 {
            'b1_non_alnum: for name_start in (2..=non_alnum_len).rev() {
                let mut name_end = name_start;
                while name_end < clean_rel.len() && is_tv_b1_name_char(clean_rel[name_end]) {
                    name_end += 1;
                    if matches_tv_following_at(clean_rel, name_end).is_some() {
                        show_name = Some(&clean_rel[name_start..name_end]);
                        break 'b1_non_alnum;
                    }
                }
            }
        }
        if show_name.is_none()
            && clean_rel.len() > 6
            && matches!(clean_rel[0].to_ascii_lowercase(), b's' | b'p' | b'r')
        {
            for prefix in [&b"sample-"[..], b"proof-", b"repost-"] {
                if clean_rel.len() > prefix.len()
                    && clean_rel[..prefix.len()].eq_ignore_ascii_case(prefix)
                {
                    let name_start = prefix.len();
                    let mut name_end = name_start;
                    while name_end < clean_rel.len() && is_tv_b1_name_char(clean_rel[name_end]) {
                        name_end += 1;
                        if matches_tv_following_at(clean_rel, name_end).is_some() {
                            show_name = Some(&clean_rel[name_start..name_end]);
                            break;
                        }
                    }
                    break;
                }
            }
        }
    }

    // Branch 2: `elseif (preg_match('/^(?P<name>[\w!&][\s\w\'._\-!&]*?)'.$following.'/i', $cleanRelname, $hits))`
    if show_name.is_none() && !clean_rel.is_empty() && is_tv_b2_first_char(clean_rel[0]) {
        let mut name_end = 1usize;
        loop {
            if matches_tv_following_at(clean_rel, name_end).is_some() {
                show_name = Some(&clean_rel[..name_end]);
                break;
            }
            if name_end < clean_rel.len() && is_tv_b2_cont_char(clean_rel[name_end]) {
                name_end += 1;
            } else {
                break;
            }
        }
    }

    // Fallbacks if still empty
    if show_name.is_none_or(|s| s.is_empty()) {
        // Fallback 1: `/^(?P<name>.+?)[_\s]*[-_\s]+S\d{1,2}E\d{1,3}/i`
        'fb1: for i in 1..clean_rel.len() {
            let mut p = i;
            while p < clean_rel.len()
                && (clean_rel[p] == b'-' || clean_rel[p] == b'_' || is_ascii_ws(clean_rel[p]))
            {
                p += 1;
                if p < clean_rel.len() && matches!(clean_rel[p], b's' | b'S') {
                    let mut q = p + 1;
                    let mut s_digits = 0;
                    while s_digits < 2 && q < clean_rel.len() && clean_rel[q].is_ascii_digit() {
                        s_digits += 1;
                        q += 1;
                    }
                    if s_digits >= 1
                        && q + 1 < clean_rel.len()
                        && matches!(clean_rel[q], b'e' | b'E')
                        && clean_rel[q + 1].is_ascii_digit()
                    {
                        show_name = Some(&clean_rel[..i]);
                        break 'fb1;
                    }
                }
            }
        }
    }

    if show_name.is_none_or(|s| s.is_empty()) {
        // Fallback 2: `/^(?P<name>.+?)[_\s]+[-_]+[_\s]*\d{1,3}[_\s]*[\[(]/i`
        'fb2: for i in 1..clean_rel.len() {
            if !(clean_rel[i] == b'_' || is_ascii_ws(clean_rel[i])) {
                continue;
            }
            let mut p = i + 1;
            while p < clean_rel.len() && (clean_rel[p] == b'_' || is_ascii_ws(clean_rel[p])) {
                p += 1;
            }
            for sep_start in (i + 1..=p).rev() {
                let mut q = sep_start;
                while q < clean_rel.len() && matches!(clean_rel[q], b'-' | b'_') {
                    q += 1;
                }
                if q == sep_start {
                    continue;
                }
                while q < clean_rel.len() && (clean_rel[q] == b'_' || is_ascii_ws(clean_rel[q])) {
                    q += 1;
                }
                let mut digits = 0;
                while digits < 3 && q < clean_rel.len() && clean_rel[q].is_ascii_digit() {
                    digits += 1;
                    q += 1;
                }
                if digits >= 1 {
                    while q < clean_rel.len() && (clean_rel[q] == b'_' || is_ascii_ws(clean_rel[q]))
                    {
                        q += 1;
                    }
                    if q < clean_rel.len() && matches!(clean_rel[q], b'[' | b'(') {
                        show_name = Some(&clean_rel[..i]);
                        break 'fb2;
                    }
                }
            }
        }
    }

    if show_name.is_none_or(|s| s.is_empty()) {
        // Fallback 3 & 4 (only when `_-_` is present)
        if let Some(first_sep) = clean_rel.windows(3).position(|w| w == b"_-_") {
            if first_sep >= 1 {
                let after_first = first_sep + 3;
                for second_rel in 1..clean_rel[after_first..].len().saturating_sub(4) {
                    let second_sep = after_first + second_rel;
                    if clean_rel.get(second_sep..second_sep + 3) == Some(b"_-_") {
                        let mut q = second_sep + 3;
                        let mut d = 0;
                        while d < 3 && q < clean_rel.len() && clean_rel[q].is_ascii_digit() {
                            d += 1;
                            q += 1;
                        }
                        if d >= 1
                            && q + 1 < clean_rel.len()
                            && clean_rel[q] == b'_'
                            && matches!(clean_rel[q + 1], b'[' | b'(')
                        {
                            show_name = Some(&clean_rel[..first_sep]);
                            break;
                        }
                    }
                }
            }
            if show_name.is_none_or(|s| s.is_empty())
                && clean_rel.first().is_some_and(|&b| b != b'_')
            {
                let candidate = &clean_rel[..first_sep];
                let mut valid = !candidate.is_empty() && candidate.last() != Some(&b'_');
                for w in candidate.windows(2) {
                    if w == b"__" || w == b"_-" {
                        valid = false;
                        break;
                    }
                }
                if valid {
                    show_name = Some(candidate);
                }
            }
        }
    }

    let raw_name = show_name.unwrap_or(&[]);
    if raw_name.is_empty() {
        return;
    }

    // `$showName = preg_replace('/'.$following.'/i', ' ', $showName);`
    scratch.clear();
    let mut i = 0;
    while i < raw_name.len() {
        if let Some(end) = matches_tv_following_at(raw_name, i) {
            scratch.push(b' ');
            i = end;
        } else {
            scratch.push(raw_name[i]);
            i += 1;
        }
    }

    // `$showName = preg_replace('/^\d{6}/', '', $showName);`
    let s1 = scratch.as_slice();
    let s2 = if s1.len() >= 6 && s1[..6].iter().all(|b| b.is_ascii_digit()) {
        &s1[6..]
    } else {
        s1
    };

    // `$showName = $this->convertAcronyms($showName);`
    convert_acronyms_into(s2, out_name);

    // `$showName = preg_replace('/\(.*?\)|[._]/i', ' ', $showName);`
    scratch.clear();
    let s3 = out_name.as_slice();
    let mut j = 0;
    while j < s3.len() {
        if s3[j] == b'(' {
            if let Some(close_rel) = s3[j + 1..].iter().position(|&b| b == b')') {
                scratch.push(b' ');
                j += close_rel + 2;
                continue;
            }
        }
        if matches!(s3[j], b'.' | b'_') {
            scratch.push(b' ');
        } else {
            scratch.push(s3[j]);
        }
        j += 1;
    }

    // `$showName = trim(preg_replace('/\s{2,}/', ' ', $showName));`
    collapse_multi_spaces_into(trim_php_ws(scratch.as_slice()), out_name);
}

/// Implements `AbstractTvProvider::parseCountry($showName)`.
#[inline]
fn tv_parse_country(show_name: &[u8]) -> &'static [u8] {
    for i in 0..show_name.len() {
        if show_name[i].is_ascii_alphanumeric() {
            continue;
        }
        let rem = &show_name[i + 1..];
        if rem.len() >= 2 {
            let c2 = [rem[0].to_ascii_uppercase(), rem[1].to_ascii_uppercase()];
            match &c2 {
                b"US" => return b"US",
                b"UK" => return b"UK",
                b"AU" => return b"AU",
                b"NZ" => return b"NZ",
                b"CA" => return b"CA",
                b"NL" => return b"NL",
                _ => {}
            }
            if rem.len() >= 6 && rem[..6].eq_ignore_ascii_case(b"canada") {
                return b"CA";
            }
            if rem.len() >= 9 && rem[..9].eq_ignore_ascii_case(b"australia") {
                return b"AU";
            }
            if rem.len() >= 7 && rem[..7].eq_ignore_ascii_case(b"america") {
                return b"US";
            }
            if rem.len() >= 13
                && rem[..6].eq_ignore_ascii_case(b"united")
                && !rem[6].is_ascii_alphanumeric()
            {
                if rem[7..13].eq_ignore_ascii_case(b"states") {
                    return b"US";
                }
                if rem.len() >= 14 && rem[7..14].eq_ignore_ascii_case(b"kingdom") {
                    return b"UK";
                }
            }
        }
    }
    b""
}

#[inline]
fn is_tv_language_tag(word: &[u8]) -> bool {
    if !(2..=10).contains(&word.len()) {
        return false;
    }
    let mut lower = [0u8; 10];
    for (i, &b) in word.iter().enumerate() {
        lower[i] = b.to_ascii_lowercase();
    }
    matches!(
        &lower[..word.len()],
        b"german"
            | b"french"
            | b"spanish"
            | b"italian"
            | b"dutch"
            | b"russian"
            | b"japanese"
            | b"korean"
            | b"chinese"
            | b"portuguese"
            | b"swedish"
            | b"norwegian"
            | b"danish"
            | b"finnish"
            | b"polish"
            | b"czech"
            | b"hungarian"
            | b"romanian"
            | b"greek"
            | b"turkish"
            | b"arabic"
            | b"hebrew"
            | b"hindi"
            | b"thai"
            | b"vietnamese"
            | b"indonesian"
            | b"malay"
            | b"tagalog"
            | b"dl"
            | b"dubbed"
            | b"subbed"
            | b"multi"
    )
}

/// Implements `AbstractTvProvider::cleanName($str)` into `out` without heap allocations.
fn tv_clean_name_into(input: &[u8], out: &mut SmallBytes, scratch: &mut SmallBytes) -> bool {
    scratch.clear();
    let mut i = 0;
    while i < input.len() {
        let b = input[i];
        if b == 0 || b == b'\n' {
            return false;
        }
        if b < 0x80 {
            if matches!(b, b'.' | b'_') {
                scratch.push(b' ');
            } else if b == b'&' {
                scratch.extend_from_slice(b"and");
            } else {
                scratch.push(b);
            }
            i += 1;
            continue;
        }
        if i + 1 >= input.len() {
            return false;
        }
        let b2 = input[i + 1];
        match (b, b2) {
            (0xC3, 0xA0..=0xA4 | 0xA6 | 0x80..=0x84) => scratch.push(b'a'),
            (0xC3, 0xA7 | 0x87) => scratch.push(b'c'),
            (0xCE, 0xA3) | (0xC3, 0xA8..=0xAB | 0x88..=0x8B) => scratch.push(b'e'),
            (0xC3, 0xAC..=0xAF | 0x8C..=0x8F) => scratch.push(b'i'),
            (0xC3, 0xB2..=0xB6 | 0x92..=0x96) => scratch.push(b'o'),
            (0xC3, 0xB9..=0xBC | 0x9A..=0x9C) | (0xC5, 0xAA | 0xAB) => scratch.push(b'u'),
            (0xC3, 0x9F) => scratch.extend_from_slice(b"ss"),
            _ => return false,
        }
        i += 2;
    }

    let s1 = scratch.as_slice();
    let s2: &[u8] = if s1.len() >= 15 && s1[..15].eq_ignore_ascii_case(b"history channel") {
        &s1[15..]
    } else if s1.len() >= 17 && s1[..17].eq_ignore_ascii_case(b"discovery channel") {
        &s1[17..]
    } else {
        s1
    };

    out.clear();
    for &b in s2 {
        match b {
            b'\'' | b':' | b'!' | b'"' | b'#' | b'*' | b',' | b'(' | b')' | b'?' => {}
            b'$' => out.push(b's'),
            _ => out.push(b),
        }
    }

    // Remove language tags `/\b(German|French|...)\b/i`
    scratch.clear();
    let s3 = out.as_slice();
    let mut p = 0;
    while p < s3.len() {
        if is_word_byte(s3[p]) {
            let start = p;
            while p < s3.len() && is_word_byte(s3[p]) {
                p += 1;
            }
            if !is_tv_language_tag(&s3[start..p]) {
                scratch.extend_from_slice(&s3[start..p]);
            }
        } else {
            scratch.push(s3[p]);
            p += 1;
        }
    }

    // `$str = preg_replace('/\s+-\s+.*(wo andere|where others|Arbeiten|Working).*$/i', '', $str);`
    {
        let s4 = scratch.as_slice();
        let mut trunc_pos: Option<usize> = None;
        let mut q = 0;
        while q < s4.len() {
            if is_ascii_ws(s4[q]) {
                let ws_start = q;
                while q < s4.len() && is_ascii_ws(s4[q]) {
                    q += 1;
                }
                if q < s4.len() && s4[q] == b'-' && q + 1 < s4.len() && is_ascii_ws(s4[q + 1]) {
                    let mut after_ws = q + 2;
                    while after_ws < s4.len() && is_ascii_ws(s4[after_ws]) {
                        after_ws += 1;
                    }
                    let rest = &s4[after_ws..];
                    let has_marker = rest
                        .windows(9)
                        .any(|w| w.eq_ignore_ascii_case(b"wo andere"))
                        || rest
                            .windows(12)
                            .any(|w| w.eq_ignore_ascii_case(b"where others"))
                        || rest.windows(8).any(|w| w.eq_ignore_ascii_case(b"arbeiten"))
                        || rest.windows(7).any(|w| w.eq_ignore_ascii_case(b"working"));
                    if has_marker {
                        trunc_pos = Some(ws_start);
                        break;
                    }
                }
            } else {
                q += 1;
            }
        }
        if let Some(tp) = trunc_pos {
            scratch.truncate(tp);
        }
    }

    // `$str = preg_replace('/\s*-\s*$/', '', $str);`
    {
        let s4 = scratch.as_slice();
        let mut end = s4.len();
        while end > 0 && is_ascii_ws(s4[end - 1]) {
            end -= 1;
        }
        if end > 0 && s4[end - 1] == b'-' {
            end -= 1;
            while end > 0 && is_ascii_ws(s4[end - 1]) {
                end -= 1;
            }
            scratch.truncate(end);
        }
    }

    // `$str = preg_replace('/\s+\d{1,3}\s*$/', '', $str);`
    {
        let s4 = scratch.as_slice();
        let mut end = s4.len();
        while end > 0 && is_ascii_ws(s4[end - 1]) {
            end -= 1;
        }
        let digit_end = end;
        while end > 0 && s4[end - 1].is_ascii_digit() {
            end -= 1;
        }
        let digit_count = digit_end - end;
        if (1..=3).contains(&digit_count) && end > 0 && is_ascii_ws(s4[end - 1]) {
            while end > 0 && is_ascii_ws(s4[end - 1]) {
                end -= 1;
            }
            scratch.truncate(end);
        }
    }

    // `$str = preg_replace('/\s+-$/', '', $str);`
    {
        let s4 = scratch.as_slice();
        if s4.last() == Some(&b'-') && s4.len() >= 2 && is_ascii_ws(s4[s4.len() - 2]) {
            let mut end = s4.len() - 1;
            while end > 0 && is_ascii_ws(s4[end - 1]) {
                end -= 1;
            }
            scratch.truncate(end);
        }
    }

    collapse_multi_spaces_into(scratch.as_slice(), out);

    // `$str = trim($str, '"-'); return trim($str);`
    let mut slice = out.as_slice();
    while let Some(&b) = slice.first() {
        if matches!(b, b'"' | b'-') {
            slice = &slice[1..];
        } else {
            break;
        }
    }
    while let Some(&b) = slice.last() {
        if matches!(b, b'"' | b'-') {
            slice = &slice[..slice.len() - 1];
        } else {
            break;
        }
    }
    let final_slice = trim_php_ws(slice);
    scratch.clear();
    scratch.extend_from_slice(final_slice);
    out.clear();
    out.extend_from_slice(scratch.as_slice());
    true
}

/// Implements `AbstractTvProvider::normalizeShowTitle($cleanName)`.
#[inline]
fn tv_normalize_show_title(clean_name: &[u8]) -> Option<&'static [u8]> {
    let trimmed = trim_php_ws(clean_name);
    if trimmed.is_empty() || trimmed.len() > 48 {
        return None;
    }
    let mut lower = [0u8; 48];
    for (i, &b) in trimmed.iter().enumerate() {
        lower[i] = b.to_ascii_lowercase();
    }
    match &lower[..trimmed.len()] {
        b"grits" => Some(b"Girls Raised in the South"),
        b"shield" => Some(b"Agents of S.H.I.E.L.D."),
        b"stephen colbert"
        | b"late show with stephen colbert"
        | b"late show stephen colbert"
        | b"the late show stephen colbert"
        | b"colbert" => Some(b"The Late Show with Stephen Colbert"),
        b"daily show" | b"the daily show" => Some(b"The Daily Show"),
        b"daily show with jon stewart" | b"the daily show with jon stewart" => {
            Some(b"The Daily Show with Jon Stewart")
        }
        b"daily show with trevor noah" | b"the daily show with trevor noah" => {
            Some(b"The Daily Show with Trevor Noah")
        }
        b"seth meyers" | b"late night with seth meyers" | b"late night seth meyers" => {
            Some(b"Late Night with Seth Meyers")
        }
        b"jimmy kimmel" | b"jimmy kimmel live" => Some(b"Jimmy Kimmel Live!"),
        b"the late late show with james corden"
        | b"late late show with james corden"
        | b"late show with james corden"
        | b"james corden"
        | b"late late show james corden" => Some(b"The Late Late Show with James Corden"),
        b"jimmy fallon"
        | b"the tonight show starring jimmy fallon"
        | b"tonight show with jimmy fallon"
        | b"tonight show jimmy fallon" => Some(b"The Tonight Show Starring Jimmy Fallon"),
        b"oak island fluch und legende"
        | b"oak island - fluch und legende"
        | b"die schatzsucher von oak island" => Some(b"The Curse of Oak Island"),
        b"tierische freundschaften" => Some(b"Animal Odd Couples"),
        b"zwischen meer und maloche" => Some(b"Zwischen Meer und Maloche"),
        b"game of thrones das lied von eis und feuer" => Some(b"Game of Thrones"),
        b"die simpsons" => Some(b"The Simpsons"),
        b"die wilden siebziger" => Some(b"That 70s Show"),
        b"how i met your mother" => Some(b"How I Met Your Mother"),
        b"scrubs die anfanger" | b"scrubs die anfaenger" => Some(b"Scrubs"),
        b"der prinz von bel-air" | b"prinz von bel-air" | b"der prinz von bel air" => {
            Some(b"The Fresh Prince of Bel-Air")
        }
        b"alarm fur cobra 11" | b"alarm fuer cobra 11" => Some(b"Alarm for Cobra 11"),
        b"soko" => Some(b"SOKO"),
        b"der bergdoktor" => Some(b"Der Bergdoktor"),
        b"das boot" => Some(b"Das Boot"),
        b"babylon berlin" => Some(b"Babylon Berlin"),
        b"dark" => Some(b"Dark"),
        b"tatort" => Some(b"Tatort"),
        b"polizeiruf 110" => Some(b"Polizeiruf 110"),
        _ => None,
    }
}

#[inline]
fn is_valid_date(year: u32, month: u32, day: u32) -> bool {
    if !(1..=12).contains(&month) || !(1..=32767).contains(&year) || day == 0 {
        return false;
    }
    let max_day = match month {
        1 | 3 | 5 | 7 | 8 | 10 | 12 => 31,
        4 | 6 | 9 | 11 => 30,
        2 => {
            if (year.is_multiple_of(4) && !year.is_multiple_of(100)) || year.is_multiple_of(400) {
                29
            } else {
                28
            }
        }
        _ => 0,
    };
    day <= max_day
}

#[inline]
fn format_airdate_bytes(year: u32, month: u32, day: u32) -> Option<[u8; 10]> {
    if !is_valid_date(year, month, day) {
        return None;
    }
    Some([
        b'0' + ((year / 1000) % 10) as u8,
        b'0' + ((year / 100) % 10) as u8,
        b'0' + ((year / 10) % 10) as u8,
        b'0' + (year % 10) as u8,
        b'-',
        b'0' + ((month / 10) % 10) as u8,
        b'0' + (month % 10) as u8,
        b'-',
        b'0' + ((day / 10) % 10) as u8,
        b'0' + (day % 10) as u8,
    ])
}

#[inline]
fn day_month_airdate_bytes(first: u32, second: u32, year: u32, sep: u8) -> Option<[u8; 10]> {
    let (day, month) = if first > 12 {
        (first, second)
    } else if second > 12 {
        (second, first)
    } else if sep == b'.' {
        (first, second)
    } else {
        (second, first)
    };
    format_airdate_bytes(year, month, day)
}

#[derive(Clone, Copy)]
struct ParsedSeasonEp {
    season: u16,
    episode: u16, // 0xFFFF = 'all'
    has_airdate: bool,
    airdate: Option<[u8; 10]>,
}

#[inline]
fn parse_digits(s: &[u8], pos: usize, min_len: usize, max_len: usize) -> Option<(u32, usize)> {
    let mut val = 0u32;
    let mut count = 0usize;
    while count < max_len && pos + count < s.len() && s[pos + count].is_ascii_digit() {
        val = val * 10 + u32::from(s[pos + count] - b'0');
        count += 1;
    }
    if count >= min_len {
        Some((val, pos + count))
    } else {
        None
    }
}

/// Implements `AbstractTvProvider::parseSeasonEp($relname)` using a single-pass separator index.
fn tv_parse_season_ep(relname: &[u8], max_two_digit_year: u32) -> Option<ParsedSeasonEp> {
    let n = relname.len();
    if n < 3 {
        return None;
    }

    // Single pass to collect separator indices (`!relname[i].is_ascii_alphanumeric()`)
    // partitioned by the character immediately following the separator.
    let mut s_digit_pos = [0usize; 16];
    let mut s_digit_len = 0usize;
    let mut digit_pos = [0usize; 32];
    let mut digit_len = 0usize;
    let mut other_sep_pos = [0usize; 32];
    let mut other_sep_len = 0usize;

    for i in 0..n - 1 {
        if relname[i].is_ascii_alphanumeric() {
            continue;
        }
        let next = relname[i + 1];
        if matches!(next, b's' | b'S') && i + 2 < n && relname[i + 2].is_ascii_digit() {
            if s_digit_len < s_digit_pos.len() {
                s_digit_pos[s_digit_len] = i;
                s_digit_len += 1;
            }
        } else if next.is_ascii_digit() {
            if digit_len < digit_pos.len() {
                digit_pos[digit_len] = i;
                digit_len += 1;
            }
        } else if matches!(next.to_ascii_lowercase(), b'p' | b'e' | b's')
            && other_sep_len < other_sep_pos.len()
        {
            other_sep_pos[other_sep_len] = i;
            other_sep_len += 1;
        }
    }

    let s_indices = &s_digit_pos[..s_digit_len];
    if !s_indices.is_empty() {
        // Branch 1: `/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]?e(\d{1,3})[e-](\d{1,3})[^a-z0-9]?/i`
        for &i in s_indices {
            if let Some((season, p1)) = parse_digits(relname, i + 2, 1, 2) {
                let p2 = if p1 < n && !relname[p1].is_ascii_alphanumeric() {
                    p1 + 1
                } else {
                    p1
                };
                if p2 < n && matches!(relname[p2], b'e' | b'E') {
                    if let Some((ep1, p3)) = parse_digits(relname, p2 + 1, 1, 3) {
                        if p3 < n
                            && matches!(relname[p3], b'e' | b'E' | b'-')
                            && parse_digits(relname, p3 + 1, 1, 3).is_some()
                        {
                            return Some(ParsedSeasonEp {
                                season: season as u16,
                                episode: ep1 as u16,
                                has_airdate: false,
                                airdate: None,
                            });
                        }
                    }
                }
            }
        }

        // Branch 2: `/^(.*?)[^a-z0-9]s(\d{2})[^a-z0-9]?e(\d{2})e?(\d{2})[^a-z0-9]?/i`
        for &i in s_indices {
            if i + 3 < n && relname[i + 2].is_ascii_digit() && relname[i + 3].is_ascii_digit() {
                let season =
                    u32::from(relname[i + 2] - b'0') * 10 + u32::from(relname[i + 3] - b'0');
                let p2 = if i + 4 < n && !relname[i + 4].is_ascii_alphanumeric() {
                    i + 5
                } else {
                    i + 4
                };
                if p2 + 2 < n
                    && matches!(relname[p2], b'e' | b'E')
                    && relname[p2 + 1].is_ascii_digit()
                    && relname[p2 + 2].is_ascii_digit()
                {
                    let ep1 =
                        u32::from(relname[p2 + 1] - b'0') * 10 + u32::from(relname[p2 + 2] - b'0');
                    let p3 = if p2 + 3 < n && matches!(relname[p2 + 3], b'e' | b'E') {
                        p2 + 4
                    } else {
                        p2 + 3
                    };
                    if p3 + 1 < n
                        && relname[p3].is_ascii_digit()
                        && relname[p3 + 1].is_ascii_digit()
                    {
                        return Some(ParsedSeasonEp {
                            season: season as u16,
                            episode: ep1 as u16,
                            has_airdate: false,
                            airdate: None,
                        });
                    }
                }
            }
        }

        // Branch 3: `/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]?e(\d{1,3})[abr]?[^a-z0-9]?/i`
        for &i in s_indices {
            if let Some((season, p1)) = parse_digits(relname, i + 2, 1, 2) {
                let p2 = if p1 < n && !relname[p1].is_ascii_alphanumeric() {
                    p1 + 1
                } else {
                    p1
                };
                if p2 < n && matches!(relname[p2], b'e' | b'E') {
                    if let Some((ep, _)) = parse_digits(relname, p2 + 1, 1, 3) {
                        return Some(ParsedSeasonEp {
                            season: season as u16,
                            episode: ep as u16,
                            has_airdate: false,
                            airdate: None,
                        });
                    }
                }
            }
        }

        // Branch 4: `/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]?d\d[^a-z0-9]?/i`
        for &i in s_indices {
            if let Some((season, p1)) = parse_digits(relname, i + 2, 1, 2) {
                let p2 = if p1 < n && !relname[p1].is_ascii_alphanumeric() {
                    p1 + 1
                } else {
                    p1
                };
                if p2 + 1 < n
                    && matches!(relname[p2], b'd' | b'D')
                    && relname[p2 + 1].is_ascii_digit()
                {
                    return Some(ParsedSeasonEp {
                        season: season as u16,
                        episode: 0xFFFF,
                        has_airdate: false,
                        airdate: None,
                    });
                }
            }
        }

        // Branch 5: `/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]?/i`
        let i = s_indices[0];
        if let Some((season, _)) = parse_digits(relname, i + 2, 1, 2) {
            return Some(ParsedSeasonEp {
                season: season as u16,
                episode: 0xFFFF,
                has_airdate: false,
                airdate: None,
            });
        }
    }

    let d_indices = &digit_pos[..digit_len];
    if !d_indices.is_empty() {
        // Branch 6: `/^(.*?)[^a-z0-9](\d{1,2})x(\d{1,3})[^a-z0-9]?/i`
        for &i in d_indices {
            if let Some((season, p1)) = parse_digits(relname, i + 1, 1, 2) {
                if p1 < n && matches!(relname[p1], b'x' | b'X') {
                    if let Some((ep, _)) = parse_digits(relname, p1 + 1, 1, 3) {
                        return Some(ParsedSeasonEp {
                            season: season as u16,
                            episode: ep as u16,
                            has_airdate: false,
                            airdate: None,
                        });
                    }
                }
            }
        }

        // Branch 7: `/^(.*?)[^a-z0-9](?P<year>(19|20)\d{2})[.\/-](?P<month>\d{2})[.\/-](?P<day>\d{2})[^a-z0-9]?/i`
        for &i in d_indices {
            if i + 10 >= n {
                continue;
            }
            let s = &relname[i + 1..i + 11];
            if ((s[0] == b'1' && s[1] == b'9') || (s[0] == b'2' && s[1] == b'0'))
                && s[2].is_ascii_digit()
                && s[3].is_ascii_digit()
                && matches!(s[4], b'.' | b'/' | b'-')
                && s[5].is_ascii_digit()
                && s[6].is_ascii_digit()
                && matches!(s[7], b'.' | b'/' | b'-')
                && s[8].is_ascii_digit()
                && s[9].is_ascii_digit()
            {
                let year = u32::from(s[0] - b'0') * 1000
                    + u32::from(s[1] - b'0') * 100
                    + u32::from(s[2] - b'0') * 10
                    + u32::from(s[3] - b'0');
                let month = u32::from(s[5] - b'0') * 10 + u32::from(s[6] - b'0');
                let day = u32::from(s[8] - b'0') * 10 + u32::from(s[9] - b'0');
                return Some(ParsedSeasonEp {
                    season: 0,
                    episode: 0,
                    has_airdate: true,
                    airdate: format_airdate_bytes(year, month, day),
                });
            }
        }

        // Branch 8: `/^(.*?)[^a-z0-9](?P<first>\d{2})(?P<sep>[.\/-])(?P<second>\d{2})(?P=sep)(?P<year>(19|20)\d{2})[^a-z0-9]?/i`
        for &i in d_indices {
            if i + 10 >= n {
                continue;
            }
            let s = &relname[i + 1..i + 11];
            if s[0].is_ascii_digit()
                && s[1].is_ascii_digit()
                && matches!(s[2], b'.' | b'/' | b'-')
                && s[3].is_ascii_digit()
                && s[4].is_ascii_digit()
                && s[5] == s[2]
                && ((s[6] == b'1' && s[7] == b'9') || (s[6] == b'2' && s[7] == b'0'))
                && s[8].is_ascii_digit()
                && s[9].is_ascii_digit()
            {
                let first = u32::from(s[0] - b'0') * 10 + u32::from(s[1] - b'0');
                let second = u32::from(s[3] - b'0') * 10 + u32::from(s[4] - b'0');
                let year = u32::from(s[6] - b'0') * 1000
                    + u32::from(s[7] - b'0') * 100
                    + u32::from(s[8] - b'0') * 10
                    + u32::from(s[9] - b'0');
                return Some(ParsedSeasonEp {
                    season: 0,
                    episode: 0,
                    has_airdate: true,
                    airdate: day_month_airdate_bytes(first, second, year, s[2]),
                });
            }
        }

        // Branch 9: `/^(.*?)[^a-z0-9](?P<first>\d{2})(?P<sep>[.\/-])(?P<second>\d{2})(?P=sep)(?P<year>\d{2})[^a-z0-9]?/i`
        for &i in d_indices {
            if i + 8 >= n {
                continue;
            }
            let s = &relname[i + 1..i + 9];
            if s[0].is_ascii_digit()
                && s[1].is_ascii_digit()
                && matches!(s[2], b'.' | b'/' | b'-')
                && s[3].is_ascii_digit()
                && s[4].is_ascii_digit()
                && s[5] == s[2]
                && s[6].is_ascii_digit()
                && s[7].is_ascii_digit()
            {
                let first = u32::from(s[0] - b'0') * 10 + u32::from(s[1] - b'0');
                let second = u32::from(s[3] - b'0') * 10 + u32::from(s[4] - b'0');
                let two_digit_year = u32::from(s[6] - b'0') * 10 + u32::from(s[7] - b'0');
                let year = if two_digit_year <= max_two_digit_year {
                    2000 + two_digit_year
                } else {
                    1900 + two_digit_year
                };
                return Some(ParsedSeasonEp {
                    season: 0,
                    episode: 0,
                    has_airdate: true,
                    airdate: day_month_airdate_bytes(first, second, year, s[2]),
                });
            }
        }

        // Branch 10: `/^(.*?)[^a-z0-9](20\d{2})[^a-z0-9]E(\d{1,3})(?!\d)/i`
        for &i in d_indices {
            if i + 6 >= n {
                continue;
            }
            if relname[i + 1] == b'2'
                && relname[i + 2] == b'0'
                && relname[i + 3].is_ascii_digit()
                && relname[i + 4].is_ascii_digit()
                && !relname[i + 5].is_ascii_alphanumeric()
                && matches!(relname[i + 6], b'e' | b'E')
            {
                if let Some((ep, p_end)) = parse_digits(relname, i + 7, 1, 3) {
                    if p_end >= n || !relname[p_end].is_ascii_digit() {
                        let season = 2000
                            + u32::from(relname[i + 3] - b'0') * 10
                            + u32::from(relname[i + 4] - b'0');
                        return Some(ParsedSeasonEp {
                            season: season as u16,
                            episode: ep as u16,
                            has_airdate: false,
                            airdate: None,
                        });
                    }
                }
            }
        }

        // Branch 11: `/^(.*?)[^a-z0-9](19|20)(\d{2})[^a-z0-9]Part(\d{1,2})[^a-z0-9]?/i`
        for &i in d_indices {
            if i + 9 >= n {
                continue;
            }
            if ((relname[i + 1] == b'1' && relname[i + 2] == b'9')
                || (relname[i + 1] == b'2' && relname[i + 2] == b'0'))
                && relname[i + 3].is_ascii_digit()
                && relname[i + 4].is_ascii_digit()
                && !relname[i + 5].is_ascii_alphanumeric()
                && relname[i + 6..i + 10].eq_ignore_ascii_case(b"part")
            {
                if let Some((ep, _)) = parse_digits(relname, i + 10, 1, 2) {
                    let season = u32::from(relname[i + 1] - b'0') * 1000
                        + u32::from(relname[i + 2] - b'0') * 100
                        + u32::from(relname[i + 3] - b'0') * 10
                        + u32::from(relname[i + 4] - b'0');
                    return Some(ParsedSeasonEp {
                        season: season as u16,
                        episode: ep as u16,
                        has_airdate: false,
                        airdate: None,
                    });
                }
            }
        }
    }

    let o_indices = &other_sep_pos[..other_sep_len];
    if !o_indices.is_empty() {
        // Branch 12: `/^(.*?)[^a-z0-9](?:Part|Pt)[^a-z0-9](\d{1,2})[^a-z0-9]?/i`
        for &i in o_indices {
            let rem = &relname[i + 1..];
            let kw_len = if rem.len() >= 4 && rem[..4].eq_ignore_ascii_case(b"part") {
                Some(4)
            } else if rem.len() >= 2 && rem[..2].eq_ignore_ascii_case(b"pt") {
                Some(2)
            } else {
                None
            };
            if let Some(kl) = kw_len {
                let p = i + 1 + kl;
                if p < n && !relname[p].is_ascii_alphanumeric() {
                    if let Some((ep, _)) = parse_digits(relname, p + 1, 1, 2) {
                        return Some(ParsedSeasonEp {
                            season: 1,
                            episode: ep as u16,
                            has_airdate: false,
                            airdate: None,
                        });
                    }
                }
            }
        }

        // Branch 13: `/^(.*?)[^a-z0-9]EP?[^a-z0-9]?(\d{1,3})/i`
        for &i in o_indices {
            if matches!(relname[i + 1], b'e' | b'E') {
                let mut p = i + 2;
                if p < n && matches!(relname[p], b'p' | b'P') {
                    p += 1;
                }
                if p < n && !relname[p].is_ascii_alphanumeric() {
                    p += 1;
                }
                if let Some((ep, _)) = parse_digits(relname, p, 1, 3) {
                    return Some(ParsedSeasonEp {
                        season: 1,
                        episode: ep as u16,
                        has_airdate: false,
                        airdate: None,
                    });
                }
            }
        }
    }

    // Branch 14: `/[_\s][-_][_\s](\d{1,3})[_\s]*[\[(]/i`
    for i in 0..n.saturating_sub(4) {
        if (relname[i] == b'_' || is_ascii_ws(relname[i]))
            && matches!(relname[i + 1], b'-' | b'_')
            && (relname[i + 2] == b'_' || is_ascii_ws(relname[i + 2]))
        {
            if let Some((ep, mut p)) = parse_digits(relname, i + 3, 1, 3) {
                while p < n && (relname[p] == b'_' || is_ascii_ws(relname[p])) {
                    p += 1;
                }
                if p < n && matches!(relname[p], b'[' | b'(') {
                    return Some(ParsedSeasonEp {
                        season: 1,
                        episode: ep as u16,
                        has_airdate: false,
                        airdate: None,
                    });
                }
            }
        }
    }

    // Branch 15: `/^(.*?)[^a-z0-9]Seasons?[^a-z0-9]?(\d{1,2})/i`
    for &i in o_indices {
        if i + 7 <= n && relname[i + 1..i + 7].eq_ignore_ascii_case(b"season") {
            let mut p = i + 7;
            if p < n && matches!(relname[p], b's' | b'S') {
                p += 1;
            }
            if p < n && !relname[p].is_ascii_alphanumeric() {
                p += 1;
            }
            if let Some((season, _)) = parse_digits(relname, p, 1, 2) {
                return Some(ParsedSeasonEp {
                    season: season as u16,
                    episode: 0xFFFF,
                    has_airdate: false,
                    airdate: None,
                });
            }
        }
    }

    None
}

// ============================================================================
// MovieService::parseMovieSearchName & helpers
// ============================================================================

/// Jump-table dispatched matcher for `MovieService::parseMovieSearchName`'s `$followingList` at `pos`.
#[inline]
fn matches_movie_following_at(s: &[u8], pos: usize) -> Option<usize> {
    if pos + 2 >= s.len() || is_word_byte(s[pos]) {
        return None;
    }
    let rem = &s[pos + 1..];
    let first = *rem.first()?;
    let check_end = |kw_len: usize| -> Option<usize> {
        if kw_len < rem.len() && !is_word_byte(rem[kw_len]) {
            Some(pos + 1 + kw_len + 1)
        } else {
            None
        }
    };

    match first.to_ascii_lowercase() {
        b'1' | b'4' | b'7' | b'2' => {
            // 1. (1080|480|720|2160)p
            for res in [&b"1080"[..], b"480", b"720", b"2160"] {
                if rem.len() > res.len()
                    && rem.starts_with(res)
                    && matches!(rem[res.len()], b'p' | b'P')
                {
                    if let Some(end) = check_end(res.len() + 1) {
                        return Some(end);
                    }
                }
            }
            None
        }
        b'a' => {
            // 2. AC3D
            if rem.len() >= 4 && rem[..4].eq_ignore_ascii_case(b"ac3d") {
                if let Some(end) = check_end(4) {
                    return Some(end);
                }
            }
            // 19. AAC
            if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"aac") {
                if let Some(end) = check_end(3) {
                    return Some(end);
                }
            }
            None
        }
        b'd' => {
            // 3. Directors([^\w]CUT)?
            if rem.len() >= 9 && rem[..9].eq_ignore_ascii_case(b"directors") {
                if rem.len() >= 13
                    && !is_word_byte(rem[9])
                    && rem[10..13].eq_ignore_ascii_case(b"cut")
                {
                    if let Some(end) = check_end(13) {
                        return Some(end);
                    }
                }
                if let Some(end) = check_end(9) {
                    return Some(end);
                }
            }
            // 4. DD5\.1
            if rem.len() >= 5 && rem[..5].eq_ignore_ascii_case(b"dd5.1") {
                if let Some(end) = check_end(5) {
                    return Some(end);
                }
            }
            // 5. DVD(Rip)?
            if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"dvd") {
                if rem.len() >= 6 && rem[3..6].eq_ignore_ascii_case(b"rip") {
                    if let Some(end) = check_end(6) {
                        return Some(end);
                    }
                }
                if let Some(end) = check_end(3) {
                    return Some(end);
                }
            }
            // 7. divx
            if rem.len() >= 4 && rem[..4].eq_ignore_ascii_case(b"divx") {
                if let Some(end) = check_end(4) {
                    return Some(end);
                }
            }
            None
        }
        b'b' => {
            // 5. (BD|BR)(Rip)?
            for disk in [&b"bd"[..], b"br"] {
                if rem.len() >= 2 && rem[..2].eq_ignore_ascii_case(disk) {
                    if rem.len() >= 5 && rem[2..5].eq_ignore_ascii_case(b"rip") {
                        if let Some(end) = check_end(5) {
                            return Some(end);
                        }
                    }
                    if let Some(end) = check_end(2) {
                        return Some(end);
                    }
                }
            }
            // 6. BluRay
            if rem.len() >= 6 && rem[..6].eq_ignore_ascii_case(b"bluray") {
                if let Some(end) = check_end(6) {
                    return Some(end);
                }
            }
            None
        }
        b'u' => {
            // 5. UHD(Rip)?
            if rem.len() >= 3 && rem[..3].eq_ignore_ascii_case(b"uhd") {
                if rem.len() >= 6 && rem[3..6].eq_ignore_ascii_case(b"rip") {
                    if let Some(end) = check_end(6) {
                        return Some(end);
                    }
                }
                if let Some(end) = check_end(3) {
                    return Some(end);
                }
            }
            // 14. Unrated
            if rem.len() >= 7 && rem[..7].eq_ignore_ascii_case(b"unrated") {
                if let Some(end) = check_end(7) {
                    return Some(end);
                }
            }
            None
        }
        b'h' => {
            // 8. HDTV
            if rem.len() >= 4 && rem[..4].eq_ignore_ascii_case(b"hdtv") {
                if let Some(end) = check_end(4) {
                    return Some(end);
                }
            }
            // 17. (H|HEVC)[ ._-]?26[45]
            for codec in [&b"h"[..], b"hevc"] {
                if rem.len() >= codec.len() + 3 && rem[..codec.len()].eq_ignore_ascii_case(codec) {
                    let sep = usize::from(matches!(rem[codec.len()], b' ' | b'.' | b'_' | b'-'));
                    let p = codec.len() + sep;
                    if rem.len() >= p + 3
                        && rem[p] == b'2'
                        && rem[p + 1] == b'6'
                        && matches!(rem[p + 2], b'4' | b'5')
                    {
                        if let Some(end) = check_end(p + 3) {
                            return Some(end);
                        }
                    }
                }
            }
            None
        }
        b'i' => {
            // 9. iNTERNAL
            if rem.len() >= 8 && rem[..8].eq_ignore_ascii_case(b"internal") {
                if let Some(end) = check_end(8) {
                    return Some(end);
                }
            }
            None
        }
        b'l' => {
            // 10. LiMiTED
            if rem.len() >= 7 && rem[..7].eq_ignore_ascii_case(b"limited") {
                if let Some(end) = check_end(7) {
                    return Some(end);
                }
            }
            None
        }
        b'r' => {
            // 11. Real\.PROPER
            if rem.len() >= 11 && rem[..11].eq_ignore_ascii_case(b"real.proper") {
                if let Some(end) = check_end(11) {
                    return Some(end);
                }
            }
            // 12. RE(pack|Rip)
            if rem.len() >= 6 && rem[..6].eq_ignore_ascii_case(b"repack") {
                if let Some(end) = check_end(6) {
                    return Some(end);
                }
            }
            if rem.len() >= 5 && rem[..5].eq_ignore_ascii_case(b"rerip") {
                if let Some(end) = check_end(5) {
                    return Some(end);
                }
            }
            // 20. REMUX
            if rem.len() >= 5 && rem[..5].eq_ignore_ascii_case(b"remux") {
                if let Some(end) = check_end(5) {
                    return Some(end);
                }
            }
            None
        }
        b'p' => {
            // 11. PROPER
            if rem.len() >= 6 && rem[..6].eq_ignore_ascii_case(b"proper") {
                if let Some(end) = check_end(6) {
                    return Some(end);
                }
            }
            None
        }
        b's' => {
            // 13. Sub\.?(fix|pack)
            if rem.len() >= 6 && rem[..3].eq_ignore_ascii_case(b"sub") {
                let dot = usize::from(rem[3] == b'.');
                let rest = &rem[3 + dot..];
                if rest.len() >= 3 && rest[..3].eq_ignore_ascii_case(b"fix") {
                    if let Some(end) = check_end(3 + dot + 3) {
                        return Some(end);
                    }
                }
                if rest.len() >= 4 && rest[..4].eq_ignore_ascii_case(b"pack") {
                    if let Some(end) = check_end(3 + dot + 4) {
                        return Some(end);
                    }
                }
            }
            None
        }
        b'w' => {
            // 15. WEB-?DL
            if rem.len() >= 5 && rem[..3].eq_ignore_ascii_case(b"web") {
                let dash = usize::from(rem[3] == b'-');
                if rem.len() >= 5 + dash && rem[3 + dash..5 + dash].eq_ignore_ascii_case(b"dl") {
                    if let Some(end) = check_end(5 + dash) {
                        return Some(end);
                    }
                }
            }
            // 16. WEBRip
            if rem.len() >= 6 && rem[..6].eq_ignore_ascii_case(b"webrip") {
                if let Some(end) = check_end(6) {
                    return Some(end);
                }
            }
            None
        }
        b'x' => {
            // 17. x[ ._-]?26[45]
            if rem.len() >= 4 {
                let sep = usize::from(matches!(rem[1], b' ' | b'.' | b'_' | b'-'));
                let p = 1 + sep;
                if rem.len() >= p + 3
                    && rem[p] == b'2'
                    && rem[p + 1] == b'6'
                    && matches!(rem[p + 2], b'4' | b'5')
                {
                    if let Some(end) = check_end(p + 3) {
                        return Some(end);
                    }
                }
            }
            // 18. xvid
            if rem.len() >= 4 && rem[..4].eq_ignore_ascii_case(b"xvid") {
                if let Some(end) = check_end(4) {
                    return Some(end);
                }
            }
            None
        }
        _ => None,
    }
}

#[inline]
fn is_movie_name_char(b: u8) -> bool {
    is_word_byte(b) || matches!(b, b' ' | b'.' | b'-')
}

/// Implements `MovieService::cleanReleaseNameForMovieLookup` + `MovieService::parseMovieSearchName`
/// without heap allocations.
fn movie_parse_search_name_into(
    input: &[u8],
    out_title: &mut SmallBytes,
    out_year: &mut [u8; 4],
    has_year: &mut bool,
    scratch: &mut SmallBytes,
) -> bool {
    out_title.clear();
    *has_year = false;

    // 1. `cleanReleaseNameForMovieLookup`
    scratch.clear();
    let mut i = 0;
    while i < input.len() {
        let b = if matches!(input[i], b'.' | b'_') {
            b' '
        } else {
            input[i]
        };
        if is_ascii_ws(b) {
            let start = i;
            i += 1;
            while i < input.len() && (matches!(input[i], b'.' | b'_') || is_ascii_ws(input[i])) {
                i += 1;
            }
            if i - start >= 2 {
                scratch.push(b' ');
            } else {
                scratch.push(b);
            }
        } else {
            scratch.push(b);
            i += 1;
        }
    }
    let rel_slice = trim_php_ws(scratch.as_slice());
    if rel_slice.is_empty() || rel_slice == b"0" {
        return false;
    }

    let mut extracted_range: Option<(usize, usize)> = None;

    // Branch 1: `/(?P<name>[\w. -]+)[^\w](?P<year>(19|20)\d\d)/i`
    {
        let mut start = 0usize;
        while start < rel_slice.len() {
            if !is_movie_name_char(rel_slice[start]) {
                start += 1;
                continue;
            }
            let mut run_end = start + 1;
            while run_end < rel_slice.len() && is_movie_name_char(rel_slice[run_end]) {
                run_end += 1;
            }
            for name_end in (start + 1..=run_end).rev() {
                if name_end + 5 <= rel_slice.len()
                    && !is_word_byte(rel_slice[name_end])
                    && ((rel_slice[name_end + 1] == b'1' && rel_slice[name_end + 2] == b'9')
                        || (rel_slice[name_end + 1] == b'2' && rel_slice[name_end + 2] == b'0'))
                    && rel_slice[name_end + 3].is_ascii_digit()
                    && rel_slice[name_end + 4].is_ascii_digit()
                {
                    extracted_range = Some((start, name_end));
                    out_year.copy_from_slice(&rel_slice[name_end + 1..name_end + 5]);
                    *has_year = true;
                    break;
                }
            }
            if extracted_range.is_some() {
                break;
            }
            start = run_end;
        }
    }

    // Branch 2 & 3: `/([^\w]{2,})?(?P<name>[\w .-]+?)'.$followingList.'/i`
    if extracted_range.is_none() {
        'b2: for pos in 0..rel_slice.len() {
            let mut non_word_len = 0usize;
            while pos + non_word_len < rel_slice.len()
                && !is_word_byte(rel_slice[pos + non_word_len])
            {
                non_word_len += 1;
            }
            if non_word_len >= 2 {
                for k in (2..=non_word_len).rev() {
                    let name_start = pos + k;
                    let mut name_end = name_start;
                    while name_end < rel_slice.len() && is_movie_name_char(rel_slice[name_end]) {
                        name_end += 1;
                        if matches_movie_following_at(rel_slice, name_end).is_some() {
                            extracted_range = Some((name_start, name_end));
                            break 'b2;
                        }
                    }
                }
            }
            let name_start = pos;
            let mut name_end = name_start;
            while name_end < rel_slice.len() && is_movie_name_char(rel_slice[name_end]) {
                name_end += 1;
                if matches_movie_following_at(rel_slice, name_end).is_some() {
                    extracted_range = Some((name_start, name_end));
                    break 'b2;
                }
            }
        }
    }

    if extracted_range.is_none() && rel_slice.len() <= 100 {
        extracted_range = Some((0, rel_slice.len()));
    }

    let Some((r_start, r_end)) = extracted_range else {
        return false;
    };
    let raw_name = &rel_slice[r_start..r_end];
    if raw_name.is_empty() || raw_name == b"0" {
        return false;
    }

    // Post-processing on `$name`:
    // 1. `$name = preg_replace('/'.$followingList.'/i', ' ', $name);`
    let mut s1 = SmallBytes::new();
    let mut k = 0;
    while k < raw_name.len() {
        if let Some(end) = matches_movie_following_at(raw_name, k) {
            s1.push(b' ');
            k = end;
        } else {
            s1.push(raw_name[k]);
            k += 1;
        }
    }

    // 2. `$name = preg_replace('/\([^)]*\)/i', ' ', $name);`
    out_title.clear();
    let s1_slice = s1.as_slice();
    let mut j = 0;
    while j < s1_slice.len() {
        if s1_slice[j] == b'(' {
            if let Some(close_rel) = s1_slice[j + 1..].iter().position(|&b| b == b')') {
                out_title.push(b' ');
                j += close_rel + 2;
                continue;
            }
        }
        out_title.push(s1_slice[j]);
        j += 1;
    }

    // 3. `while (($openPos = strpos($name, '[')) !== false && ($closePos = strpos($name, ']', $openPos)) !== false)`
    loop {
        let cur = out_title.as_slice();
        let Some(open_pos) = cur.iter().position(|&b| b == b'[') else {
            break;
        };
        let Some(close_rel) = cur[open_pos..].iter().position(|&b| b == b']') else {
            break;
        };
        let close_pos = open_pos + close_rel;
        s1.clear();
        s1.extend_from_slice(&cur[..open_pos]);
        s1.push(b' ');
        s1.extend_from_slice(&cur[close_pos + 1..]);
        out_title.clear();
        out_title.extend_from_slice(s1.as_slice());
    }

    // 4. `$name = str_replace(['.', '_'], ' ', $name);`
    for b in out_title.as_mut_slice() {
        if matches!(*b, b'.' | b'_') {
            *b = b' ';
        }
    }

    // 5. `$name = preg_replace('/-[A-Z0-9].*$/i', '', $name);`
    {
        let cur = out_title.as_slice();
        let mut trunc_idx: Option<usize> = None;
        for idx in 0..cur.len().saturating_sub(1) {
            if cur[idx] == b'-' && cur[idx + 1].is_ascii_alphanumeric() {
                trunc_idx = Some(idx);
                break;
            }
        }
        if let Some(ti) = trunc_idx {
            out_title.truncate(ti);
        }
    }

    // 6. `$name = trim(preg_replace('/\s{2,}/', ' ', $name));`
    collapse_multi_spaces_into(trim_php_ws(out_title.as_slice()), &mut s1);
    out_title.clear();
    out_title.extend_from_slice(trim_php_ws(s1.as_slice()));

    let final_slice = out_title.as_slice();
    final_slice.len() > 2 && !final_slice.iter().all(|b| b.is_ascii_digit())
}

// ============================================================================
// C ABI Exports
// ============================================================================

/// Accelerates `AbstractTvProvider::parseInfo(string $relname)`.
/// Writes `"{name}\0{country}\0{cleanname}\0{airdate}"` into `out_buf`.
/// Returns a 64-bit signed integer:
/// - `> 0`: `written | (season << 16) | (episode << 32) | (year << 48)`
/// - `-1`: invalid pointer
/// - `-2`: declined to PHP fallback (non-ASCII or newline/NUL)
/// - `-3`: `parseInfo` returned `false`
/// - `<= -4`: `-(required_len as i64)` if `out_cap` is too small
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tv_parse_info(
    input: *const u8,
    input_len: usize,
    max_two_digit_year: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> i64 {
    if input.is_null() {
        return -1;
    }
    if input_len == 0 || input_len > 16384 {
        return if input_len == 0 { -3 } else { -2 };
    }
    let relname = unsafe { slice::from_raw_parts(input, input_len) };
    if !is_printable_ascii_no_newline(relname) {
        return -2;
    }

    let mut name = SmallBytes::new();
    let mut scratch = SmallBytes::new();
    tv_parse_name_into(relname, &mut name, &mut scratch);
    let name_slice = name.as_slice();
    if name_slice.is_empty() || name_slice == b"0" {
        return -3;
    }

    let Some(season_ep) = tv_parse_season_ep(relname, max_two_digit_year) else {
        return -3;
    };

    let country = tv_parse_country(name_slice);
    let mut clean_name = SmallBytes::new();
    if !tv_clean_name_into(name_slice, &mut clean_name, &mut scratch) {
        return -2;
    }

    // `$showInfo['cleanname'] = preg_replace('/ - \d+$/i', '', $this->cleanName($showInfo['name']));`
    {
        let cn = clean_name.as_slice();
        let mut p = cn.len();
        while p > 0 && cn[p - 1].is_ascii_digit() {
            p -= 1;
        }
        if p < cn.len() && p >= 3 && &cn[p - 3..p] == b" - " {
            clean_name.truncate(p - 3);
        }
    }

    // `$showInfo['cleanname'] = $this->normalizeShowTitle($showInfo['cleanname']);`
    if let Some(alias) = tv_normalize_show_title(clean_name.as_slice()) {
        clean_name.clear();
        clean_name.extend_from_slice(alias);
    }

    let mut year: u16 = 0;
    let airdate_slice: &[u8] = if season_ep.has_airdate {
        match &season_ep.airdate {
            Some(ad) => ad,
            None => b"",
        }
    } else {
        for i in 0..relname.len().saturating_sub(5) {
            if !relname[i].is_ascii_alphanumeric()
                && ((relname[i + 1] == b'1' && relname[i + 2] == b'9')
                    || (relname[i + 1] == b'2' && relname[i + 2] == b'0'))
                && relname[i + 3].is_ascii_digit()
                && relname[i + 4].is_ascii_digit()
                && !relname[i + 5].is_ascii_alphanumeric()
            {
                let y = u16::from(relname[i + 1] - b'0') * 1000
                    + u16::from(relname[i + 2] - b'0') * 100
                    + u16::from(relname[i + 3] - b'0') * 10
                    + u16::from(relname[i + 4] - b'0');
                year = y;
                clean_name.extend_from_slice(b" (");
                clean_name.extend_from_slice(&relname[i + 1..i + 5]);
                clean_name.push(b')');
                break;
            }
        }
        b""
    };

    let mut cur = OutCursor::new(out_buf, out_cap);
    cur.push_slice(name.as_slice());
    cur.push_byte(0);
    cur.push_slice(country);
    cur.push_byte(0);
    cur.push_slice(clean_name.as_slice());
    cur.push_byte(0);
    cur.push_slice(airdate_slice);
    let written = cur.finish();
    if written < 0 {
        return written as i64;
    }
    if written > 0xFFFF {
        return -2;
    }

    (written as i64)
        | ((season_ep.season as i64) << 16)
        | ((season_ep.episode as i64) << 32)
        | ((year as i64) << 48)
}

/// Accelerates `AbstractTvProvider::cleanName(string $str)`.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tv_clean_name(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() {
        return -1;
    }
    if input_len == 0 {
        return 0;
    }
    let slice = unsafe { slice::from_raw_parts(input, input_len) };
    let mut cleaned = SmallBytes::new();
    let mut scratch = SmallBytes::new();
    if !tv_clean_name_into(slice, &mut cleaned, &mut scratch) {
        return -2;
    }
    let mut cur = OutCursor::new(out_buf, out_cap);
    cur.push_slice(cleaned.as_slice());
    cur.finish()
}

/// Accelerates `MovieService::parseMovieSearchName`.
/// Writes only `title` into `out_buf` and returns `(year << 16) | title_len` when matched.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_movie_parse_search_name(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() {
        return -1;
    }
    if input_len == 0 || input_len > 16384 {
        return if input_len == 0 { -3 } else { -2 };
    }
    let slice = unsafe { slice::from_raw_parts(input, input_len) };
    if !is_printable_ascii_no_newline(slice) {
        return -2;
    }
    let mut title = SmallBytes::new();
    let mut year = [0u8; 4];
    let mut has_year = false;
    let mut scratch = SmallBytes::new();
    if !movie_parse_search_name_into(slice, &mut title, &mut year, &mut has_year, &mut scratch) {
        return -3;
    }
    let mut cur = OutCursor::new(out_buf, out_cap);
    cur.push_slice(title.as_slice());
    let written = cur.finish();
    if written < 0 {
        return written;
    }
    if written > 0xFFFF {
        return -2;
    }
    let year_int = if has_year {
        isize::from(year[0] - b'0') * 1000
            + isize::from(year[1] - b'0') * 100
            + isize::from(year[2] - b'0') * 10
            + isize::from(year[3] - b'0')
    } else {
        0
    };
    (year_int << 16) | written
}

// ============================================================================
// LogEntryParser, DonorMatchSelector, and BookIsbn Acceleration
// ============================================================================

#[derive(Clone, Copy)]
struct MonologHeaderSpans {
    ts_len: usize,
    chan_start: usize,
    chan_len: usize,
    msg_start: usize,
    level_code: u8,
}

#[inline]
fn is_base_timestamp_19(s: &[u8]) -> bool {
    s.len() >= 19
        && s[0].is_ascii_digit()
        && s[1].is_ascii_digit()
        && s[2].is_ascii_digit()
        && s[3].is_ascii_digit()
        && s[4] == b'-'
        && s[5].is_ascii_digit()
        && s[6].is_ascii_digit()
        && s[7] == b'-'
        && s[8].is_ascii_digit()
        && s[9].is_ascii_digit()
        && matches!(s[10], b'T' | b' ')
        && s[11].is_ascii_digit()
        && s[12].is_ascii_digit()
        && s[13] == b':'
        && s[14].is_ascii_digit()
        && s[15].is_ascii_digit()
        && s[16] == b':'
        && s[17].is_ascii_digit()
        && s[18].is_ascii_digit()
}

#[inline]
fn level_bytes_to_code(level_raw: &[u8]) -> u8 {
    match level_raw {
        b"DEBUG" => 0,
        b"INFO" => 1,
        b"NOTICE" => 2,
        b"WARNING" => 3,
        b"ERROR" => 4,
        b"CRITICAL" => 5,
        b"ALERT" => 6,
        b"EMERGENCY" => 7,
        _ => 8,
    }
}

#[inline]
fn level_code_to_bytes(code: u8) -> &'static [u8] {
    match code {
        0 => b"debug",
        1 => b"info",
        2 => b"notice",
        3 => b"warning",
        4 => b"error",
        5 => b"critical",
        6 => b"alert",
        7 => b"emergency",
        _ => b"",
    }
}

#[inline]
fn parse_monolog_header_spans(line: &[u8]) -> Option<MonologHeaderSpans> {
    if line.len() < 26 || line[0] != b'[' || !is_base_timestamp_19(&line[1..]) {
        return None;
    }

    let mut p = 20usize;
    if p + 1 < line.len() && line[p] == b'.' && line[p + 1].is_ascii_digit() {
        p += 2;
        while p < line.len() && line[p].is_ascii_digit() {
            p += 1;
        }
    }

    if p < line.len() {
        if line[p] == b'Z' {
            p += 1;
        } else if matches!(line[p], b'+' | b'-')
            && p + 4 < line.len()
            && line[p + 1].is_ascii_digit()
            && line[p + 2].is_ascii_digit()
        {
            if line[p + 3] == b':' {
                if p + 5 < line.len()
                    && line[p + 4].is_ascii_digit()
                    && line[p + 5].is_ascii_digit()
                {
                    p += 6;
                }
            } else if line[p + 3].is_ascii_digit() && line[p + 4].is_ascii_digit() {
                p += 5;
            }
        }
    }

    if line.get(p) != Some(&b']') {
        return None;
    }
    let ts_len = p - 1;
    p += 1;

    let ws_start = p;
    while p < line.len() && is_ascii_ws(line[p]) {
        p += 1;
    }
    if p == ws_start {
        return None;
    }

    let chan_start = p;
    while p < line.len() && (is_word_byte(line[p]) || matches!(line[p], b'.' | b'-')) {
        p += 1;
    }
    if p >= line.len() || line[p] != b':' {
        return None;
    }

    let token = &line[chan_start..p];
    let last_dot = token.iter().rposition(|&b| b == b'.')?;
    if last_dot == 0 || last_dot + 1 >= token.len() {
        return None;
    }
    let level_raw = &token[last_dot + 1..];
    if !level_raw.iter().all(|b| b.is_ascii_uppercase()) {
        return None;
    }

    let mut msg_start = p + 1;
    if msg_start < line.len() && is_ascii_ws(line[msg_start]) {
        msg_start += 1;
    }

    Some(MonologHeaderSpans {
        ts_len,
        chan_start,
        chan_len: last_dot,
        msg_start,
        level_code: level_bytes_to_code(level_raw),
    })
}

#[inline]
fn parse_plain_timestamp_offset(line: &[u8]) -> Option<usize> {
    let mut p = 0usize;
    while p < line.len() && is_ascii_ws(line[p]) {
        p += 1;
    }
    if p < line.len() && line[p] == b'[' {
        p += 1;
    }
    if is_base_timestamp_19(line.get(p..)?) {
        Some(p)
    } else {
        None
    }
}

/// Parses a single Monolog header line and returns packed span offsets:
/// `ts_len | (chan_start << 8) | (chan_len << 24) | (msg_start << 40) | (level_code << 56)`
/// where `level_code` in `0..=7` maps to `LogEntryParser::LEVELS[level_code]` and `8` maps to `null`.
/// Returns `-1` when the line is not a Monolog header, or `-2` when span offsets exceed 16-bit packing.
///
/// # Safety
///
/// `line` must be valid for reads of `line_len` bytes when non-null and `line_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_parse_header_spans(
    line: *const u8,
    line_len: usize,
) -> i64 {
    if line.is_null() || line_len < 26 {
        return -1;
    }
    let bytes = unsafe { slice::from_raw_parts(line, line_len) };
    let Some(spans) = parse_monolog_header_spans(bytes) else {
        return -1;
    };
    if spans.ts_len > 255
        || spans.chan_start > 0xFFFF
        || spans.chan_len > 0xFFFF
        || spans.msg_start > 0xFFFF
    {
        return -2;
    }
    (spans.ts_len as i64)
        | ((spans.chan_start as i64) << 8)
        | ((spans.chan_len as i64) << 24)
        | ((spans.msg_start as i64) << 40)
        | ((spans.level_code as i64) << 56)
}

/// Extracts the byte start offset of a 19-byte plain timestamp (`YYYY-MM-DD[T ]HH:MM:SS`)
/// matching `LogEntryParser::PLAIN_TIMESTAMP_PATTERN`, or `-1` when not matched.
///
/// # Safety
///
/// `line` must be valid for reads of `line_len` bytes when non-null and `line_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_plain_timestamp_span(
    line: *const u8,
    line_len: usize,
) -> i64 {
    if line.is_null() || line_len < 19 {
        return -1;
    }
    let bytes = unsafe { slice::from_raw_parts(line, line_len) };
    match parse_plain_timestamp_offset(bytes) {
        Some(off) => off as i64,
        None => -1,
    }
}

/// Batch-checks `count` NUL-separated log lines for `LogEntryParser::isHeadersBatch`.
/// Writes `'1'` or `'0'` (1 byte per line) into `out_buf` and returns `count`.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_is_header_batch_nul(
    input: *const u8,
    input_len: usize,
    count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if count == 0 {
        return 0;
    }
    if input.is_null() && input_len > 0 {
        return -1;
    }
    let bytes = if input_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };

    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut seen = 0usize;
    for seg in bytes.split(|&b| b == 0) {
        seen += 1;
        if seen > count {
            return -1;
        }
        cur.push_byte(if parse_monolog_header_spans(seg).is_some() {
            b'1'
        } else {
            b'0'
        });
    }
    if seen != count {
        return -1;
    }
    cur.finish()
}

/// Batch-parses `count` NUL-separated log lines for `LogEntryParser`.
/// For each matched header line, writes `{timestamp}\0{channel}\0{level_or_empty}\0{message}\0`.
/// For each non-header line, writes `\0` (a single empty token).
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_parse_headers_batch_nul(
    input: *const u8,
    input_len: usize,
    count: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if count == 0 {
        return 0;
    }
    if input.is_null() && input_len > 0 {
        return -1;
    }
    let bytes = if input_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };

    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut seen = 0usize;
    for seg in bytes.split(|&b| b == 0) {
        seen += 1;
        if seen > count {
            return -1;
        }
        if let Some(spans) = parse_monolog_header_spans(seg) {
            cur.push_slice(&seg[1..1 + spans.ts_len]);
            cur.push_byte(0);
            cur.push_slice(&seg[spans.chan_start..spans.chan_start + spans.chan_len]);
            cur.push_byte(0);
            cur.push_slice(level_code_to_bytes(spans.level_code));
            cur.push_byte(0);
            cur.push_slice(&seg[spans.msg_start..]);
            cur.push_byte(0);
        } else {
            cur.push_byte(0);
        }
    }
    if seen != count {
        return -1;
    }
    cur.finish()
}

struct PendingLogBlockState {
    entry_idx: usize,
    offset: usize,
    line_no: usize,
    level_code: u8,
    body_bytes: usize,
    truncated: bool,
}

#[inline]
fn begin_log_block_entry(
    cur: &mut OutCursor,
    entry_idx: usize,
    first_line: &[u8],
    header_spans: Option<MonologHeaderSpans>,
) -> u8 {
    if entry_idx > 0 {
        cur.push_byte(0);
    }

    if let Some(spans) = header_spans {
        cur.push_slice(&first_line[1..1 + spans.ts_len]);
        cur.push_byte(0);
        cur.push_slice(&first_line[spans.chan_start..spans.chan_start + spans.chan_len]);
        cur.push_byte(0);
        cur.push_slice(&first_line[spans.msg_start..]);
        cur.push_byte(0);
        spans.level_code
    } else {
        if let Some(ts_off) = parse_plain_timestamp_offset(first_line) {
            cur.push_slice(&first_line[ts_off..ts_off + 19]);
        }
        cur.push_byte(0);
        cur.push_byte(0);
        cur.push_slice(first_line);
        cur.push_byte(0);
        8
    }
}

#[inline]
unsafe fn write_log_entry_meta(
    meta_out: *mut i64,
    meta_cap: usize,
    entry_idx: usize,
    offset: usize,
    end: usize,
    line_no: usize,
    level_code: u8,
    truncated: bool,
) -> bool {
    let base = entry_idx * 2;
    if meta_out.is_null() || base + 1 >= meta_cap {
        return false;
    }
    let w0 = (offset as i64) | ((end as i64) << 32);
    let w1 = (line_no as i64) | ((i64::from(level_code)) << 32) | ((i64::from(truncated)) << 40);
    unsafe {
        *meta_out.add(base) = w0;
        *meta_out.add(base + 1) = w1;
    }
    true
}

/// Splits and parses a raw multi-line log block into `LogEntry` records without PCRE overhead.
/// Writes 2 `i64` words per entry into `meta_out`:
/// - `meta_out[e * 2] = offset | (end << 32)`
/// - `meta_out[e * 2 + 1] = line_no | (level_code << 32) | (truncated << 40)`
/// and streams 4 NUL-separated strings per entry into `out_buf`:
/// `{ts}\0{chan}\0{msg}\0{body}` (separated by `\0` between entries).
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `meta_out` must be valid for writes of `meta_cap` `i64` elements when non-null and `meta_cap > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_parse_entries_block(
    input: *const u8,
    input_len: usize,
    structured: u32,
    max_entry_bytes: usize,
    meta_out: *mut i64,
    meta_cap: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input_len == 0 {
        return 0;
    }
    if input.is_null() || meta_out.is_null() || max_entry_bytes == 0 || input_len > 0x7FFF_FFFF {
        return -1;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    if bytes.contains(&0) {
        return -1;
    }

    let is_structured = structured != 0;
    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut pos = 0usize;
    let mut line_no = 0usize;
    let mut entry_count = 0usize;
    let mut pending: Option<PendingLogBlockState> = None;

    while pos < bytes.len() {
        let line_offset = pos;
        let rem = &bytes[pos..];
        let (raw_line, next_pos) = match rem.iter().position(|&b| b == b'\n') {
            Some(idx) => (&rem[..idx], pos + idx + 1),
            None => (rem, bytes.len()),
        };
        let mut text = raw_line;
        while text.last() == Some(&b'\r') {
            text = &text[..text.len() - 1];
        }

        let header_check = if is_structured {
            parse_monolog_header_spans(text)
        } else {
            None
        };
        if !is_structured || header_check.is_some() {
            if let Some(prev) = pending.take() {
                if !unsafe {
                    write_log_entry_meta(
                        meta_out,
                        meta_cap,
                        prev.entry_idx,
                        prev.offset,
                        line_offset,
                        prev.line_no,
                        prev.level_code,
                        prev.truncated,
                    )
                } {
                    return -1;
                }
            }
        }

        line_no += 1;
        let capped_len = text.len().min(max_entry_bytes);
        let first_capped = &text[..capped_len];
        let line_truncated = text.len() > max_entry_bytes;

        if !is_structured {
            if !trim_php_ws(text).is_empty() {
                let lvl = begin_log_block_entry(&mut cur, entry_count, first_capped, None);
                if !unsafe {
                    write_log_entry_meta(
                        meta_out,
                        meta_cap,
                        entry_count,
                        line_offset,
                        next_pos,
                        line_no,
                        lvl,
                        line_truncated,
                    )
                } {
                    return -1;
                }
                entry_count += 1;
            }
        } else if let Some(ref mut p_entry) = pending {
            if p_entry.body_bytes + first_capped.len() + 1 > max_entry_bytes {
                p_entry.truncated = true;
            } else {
                if p_entry.body_bytes > 0 {
                    cur.push_byte(b'\n');
                }
                cur.push_slice(first_capped);
                p_entry.body_bytes += first_capped.len() + 1;
                p_entry.truncated = p_entry.truncated || line_truncated;
            }
        } else {
            let capped_spans = if header_check.is_some() && line_truncated {
                parse_monolog_header_spans(first_capped)
            } else {
                header_check
            };
            let lvl = begin_log_block_entry(&mut cur, entry_count, first_capped, capped_spans);
            pending = Some(PendingLogBlockState {
                entry_idx: entry_count,
                offset: line_offset,
                line_no,
                level_code: lvl,
                body_bytes: 0,
                truncated: line_truncated,
            });
            entry_count += 1;
        }

        pos = next_pos;
    }

    if let Some(prev) = pending.take() {
        if !unsafe {
            write_log_entry_meta(
                meta_out,
                meta_cap,
                prev.entry_idx,
                prev.offset,
                bytes.len(),
                prev.line_no,
                prev.level_code,
                prev.truncated,
            )
        } {
            return -1;
        }
    }

    cur.finish()
}

#[inline]
fn is_known_unicode_c_char(c: char) -> bool {
    let u = c as u32;
    if u <= 0x001F || (0x007F..=0x009F).contains(&u) {
        return true;
    }
    if (0xE000..=0xF8FF).contains(&u)
        || (0xF0000..=0xFFFFD).contains(&u)
        || (0x100000..=0x10FFFD).contains(&u)
    {
        return true;
    }
    if (0xFDD0..=0xFDEF).contains(&u) || (u & 0xFFFE) == 0xFFFE {
        return true;
    }
    matches!(
        u,
        0x00AD
            | 0x0600..=0x0605
            | 0x061C
            | 0x06DD
            | 0x070F
            | 0x0890..=0x0891
            | 0x08E2
            | 0x180E
            | 0x200B..=0x200F
            | 0x202A..=0x202E
            | 0x2060..=0x2064
            | 0x2066..=0x206F
            | 0xFEFF
            | 0xFFF9..=0xFFFB
            | 0x110BD
            | 0x110CD
            | 0x13430..=0x1343F
            | 0x1BCA0..=0x1BCA3
            | 0x1D173..=0x1D17A
            | 0xE0001
            | 0xE0020..=0xE007F
    )
}

#[inline]
fn is_known_non_c_unicode_char(c: char) -> bool {
    let u = c as u32;
    (0x00A0..=0x024F).contains(&u)
        || (0x0400..=0x04FF).contains(&u)
        || (0x4E00..=0x9FFF).contains(&u)
        || u == 0x1F3B5
}

#[inline]
fn validate_canonical_donor_pair(
    predb_id: i64,
    title: &[u8],
    search_name: &[u8],
) -> Option<bool> {
    if predb_id <= 0 || trim_php_ws(title).is_empty() || title.len() > 255 * 4 {
        return Some(false);
    }

    if title.is_ascii() && search_name.is_ascii() {
        if title.len() > 255 || title.len() != search_name.len() {
            return Some(false);
        }
        for (&tb, &sb) in title.iter().zip(search_name.iter()) {
            if tb < 0x20 || tb == 0x7F || !tb.eq_ignore_ascii_case(&sb) {
                return Some(false);
            }
        }
        return Some(true);
    }

    let Ok(title_str) = std::str::from_utf8(title) else {
        return Some(false);
    };
    let Ok(_search_str) = std::str::from_utf8(search_name) else {
        return Some(false);
    };

    let mut chars = 0usize;
    let mut needs_pcre = false;
    for c in title_str.chars() {
        chars += 1;
        if chars > 255 || is_known_unicode_c_char(c) {
            return Some(false);
        }
        if !c.is_ascii() && !is_known_non_c_unicode_char(c) {
            needs_pcre = true;
        }
    }

    if !needs_pcre && title == search_name {
        Some(true)
    } else {
        None
    }
}

#[inline]
fn parse_i64_bytes(bytes: &[u8]) -> Option<i64> {
    if bytes.is_empty() {
        return None;
    }
    let (neg, digits) = if bytes[0] == b'-' {
        (true, &bytes[1..])
    } else if bytes[0] == b'+' {
        (false, &bytes[1..])
    } else {
        (false, bytes)
    };
    if digits.is_empty() {
        return None;
    }
    let mut acc: i64 = 0;
    for &b in digits {
        if !b.is_ascii_digit() {
            return None;
        }
        let d = i64::from(b - b'0');
        acc = if neg {
            acc.checked_mul(10)?.checked_sub(d)?
        } else {
            acc.checked_mul(10)?.checked_add(d)?
        };
    }
    Some(acc)
}

/// Validates a single donor candidate for `DonorMatchSelector::selectCanonical`.
/// Returns `1` when valid, `0` when invalid (`null`), `-1` on null pointers, or `-2` when
/// non-ASCII case folding / `\p{C}` checks should fall back to PHP.
///
/// # Safety
///
/// `title` and `search_name` must be valid for reads of `title_len` and `search_name_len` bytes when non-empty.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_donor_validate_canonical_single(
    predb_id: i64,
    title: *const u8,
    title_len: usize,
    search_name: *const u8,
    search_name_len: usize,
) -> i32 {
    if (title_len > 0 && title.is_null()) || (search_name_len > 0 && search_name.is_null()) {
        return -1;
    }
    let t_slice = if title_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(title, title_len) }
    };
    let s_slice = if search_name_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(search_name, search_name_len) }
    };

    match validate_canonical_donor_pair(predb_id, t_slice, s_slice) {
        Some(true) => 1,
        Some(false) => 0,
        None => -2,
    }
}

/// Selects the winning canonical donor index (`0..count`) for `DonorMatchSelector::selectCanonical`
/// from `count` NUL-packed donor records (`predb_id\0releases_id\0canonical_title\0searchname`).
/// Returns `-1` when rejected (`null`), or `-2` when declined to PHP fallback.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_donor_select_canonical_batch_nul(
    input: *const u8,
    input_len: usize,
    count: usize,
) -> i32 {
    if count == 0 || count > 256 {
        return -1;
    }
    if input.is_null() {
        return -2;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };

    let mut iter = bytes.split(|&b| b == 0);
    let mut first_predb_id: i64 = 0;
    let mut first_title: &[u8] = &[];
    let mut best_idx: i32 = -1;
    let mut best_releases_id: i64 = i64::MAX;
    let mut needs_php = false;

    for idx in 0..count {
        let Some(predb_raw) = iter.next() else {
            return -2;
        };
        let Some(rel_raw) = iter.next() else {
            return -2;
        };
        let Some(title) = iter.next() else {
            return -2;
        };
        let Some(search_name) = iter.next() else {
            return -2;
        };

        let Some(predb_id) = parse_i64_bytes(predb_raw) else {
            return -2;
        };
        let Some(releases_id) = parse_i64_bytes(rel_raw) else {
            return -2;
        };

        match validate_canonical_donor_pair(predb_id, title, search_name) {
            Some(false) => return -1,
            None => {
                needs_php = true;
            }
            Some(true) => {}
        }

        if idx == 0 {
            first_predb_id = predb_id;
            first_title = title;
        } else {
            if predb_id != first_predb_id {
                return -1;
            }
            if first_title.is_ascii() && title.is_ascii() {
                if !first_title.eq_ignore_ascii_case(title) {
                    return -1;
                }
            } else if first_title != title {
                needs_php = true;
            }
        }

        if best_idx < 0 || releases_id < best_releases_id {
            best_idx = idx as i32;
            best_releases_id = releases_id;
        }
    }

    if iter.next().is_some() {
        return -2;
    }
    if needs_php {
        return -2;
    }
    best_idx
}

/// Selects the best donor index (`0..count`) for `DonorMatchSelector::select` from `count` pairs
/// of `(donor_size, donor_id)` in `pairs` (`count * 2` `i64` elements), or `-1` if none match.
///
/// # Safety
///
/// `pairs` must be valid for reads of `count * 2` `i64` elements when `count > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_donor_select_best_index(
    pairs: *const i64,
    count: usize,
    target_size: i64,
    tolerance_percent: i64,
) -> i32 {
    if target_size <= 0 || tolerance_percent < 0 || count == 0 || pairs.is_null() {
        return -1;
    }
    let slice = unsafe { slice::from_raw_parts(pairs, count * 2) };
    let tol = tolerance_percent as f64;

    let mut best_idx: i32 = -1;
    let mut best_diff: i64 = i64::MAX;
    let mut best_id: i64 = i64::MAX;

    for i in 0..count {
        let donor_size = slice[i * 2];
        if donor_size <= 0 {
            continue;
        }
        let donor_id = slice[i * 2 + 1];
        let diff = (donor_size - target_size).abs();
        let diff_pct = ((diff as f64) / (donor_size as f64)) * 100.0;
        if diff_pct <= tol
            && (best_idx < 0 || diff < best_diff || (diff == best_diff && donor_id < best_id))
        {
            best_idx = i as i32;
            best_diff = diff;
            best_id = donor_id;
        }
    }

    best_idx
}

/// Selects the best donor index (`0..count`) for `DonorMatchSelector::select` from `count` NUL-packed
/// pairs `"size_0\0id_0\0size_1\0id_1..."`, or `-1` if none match / `-2` on malformed input.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_donor_select_best_nul(
    input: *const u8,
    input_len: usize,
    count: usize,
    target_size: i64,
    tolerance_percent: i64,
) -> i32 {
    if target_size <= 0 || tolerance_percent < 0 || count == 0 {
        return -1;
    }
    if input.is_null() {
        return -2;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    let tol = tolerance_percent as f64;

    let mut iter = bytes.split(|&b| b == 0);
    let mut best_idx: i32 = -1;
    let mut best_diff: i64 = i64::MAX;
    let mut best_id: i64 = i64::MAX;

    for i in 0..count {
        let Some(size_raw) = iter.next() else {
            return -2;
        };
        let Some(id_raw) = iter.next() else {
            return -2;
        };
        let Some(donor_size) = parse_i64_bytes(size_raw) else {
            return -2;
        };
        let Some(donor_id) = parse_i64_bytes(id_raw) else {
            return -2;
        };
        if donor_size <= 0 {
            continue;
        }
        let diff = (donor_size - target_size).abs();
        let diff_pct = ((diff as f64) / (donor_size as f64)) * 100.0;
        if diff_pct <= tol
            && (best_idx < 0 || diff < best_diff || (diff == best_diff && donor_id < best_id))
        {
            best_idx = i as i32;
            best_diff = diff;
            best_id = donor_id;
        }
    }
    if iter.next().is_some() {
        return -2;
    }
    best_idx
}

#[inline]
fn isbn_normalize_stack(bytes: &[u8]) -> Option<([u8; 13], usize)> {
    let mut buf = [0u8; 13];
    let mut len = 0usize;
    for &b in bytes {
        if b.is_ascii_digit() {
            if len == 13 {
                return None;
            }
            buf[len] = b;
            len += 1;
        } else if matches!(b, b'x' | b'X') {
            if len == 13 {
                return None;
            }
            buf[len] = b'X';
            len += 1;
        }
    }

    match len {
        10 => {
            if !buf[..9].iter().all(|b| b.is_ascii_digit()) {
                return None;
            }
            let mut sum = 0u32;
            for (idx, &b) in buf[..10].iter().enumerate() {
                let digit = if b == b'X' { 10 } else { u32::from(b - b'0') };
                sum += digit * (10 - idx as u32);
            }
            if sum.is_multiple_of(11) {
                Some((buf, 10))
            } else {
                None
            }
        }
        13 => {
            if !(buf.starts_with(b"978") || buf.starts_with(b"979"))
                || !buf.iter().all(|b| b.is_ascii_digit())
            {
                return None;
            }
            let mut sum = 0u32;
            for (idx, &b) in buf.iter().enumerate() {
                let digit = u32::from(b - b'0');
                sum += digit * if idx % 2 == 0 { 1 } else { 3 };
            }
            if sum.is_multiple_of(10) {
                Some((buf, 13))
            } else {
                None
            }
        }
        _ => None,
    }
}

#[inline]
fn isbn10_to_13_stack(isbn10: &[u8]) -> [u8; 13] {
    let mut out = [0u8; 13];
    out[0] = b'9';
    out[1] = b'7';
    out[2] = b'8';
    out[3..12].copy_from_slice(&isbn10[..9]);
    let mut sum = 0u32;
    for (idx, &b) in out[..12].iter().enumerate() {
        let digit = u32::from(b - b'0');
        sum += digit * if idx % 2 == 0 { 1 } else { 3 };
    }
    let check = (10 - (sum % 10)) % 10;
    out[12] = b'0' + check as u8;
    out
}

#[inline]
fn isbn13_to_10_stack(isbn13: &[u8]) -> Option<[u8; 10]> {
    if !isbn13.starts_with(b"978") {
        return None;
    }
    let mut out = [0u8; 10];
    out[..9].copy_from_slice(&isbn13[3..12]);
    let mut sum = 0u32;
    for (idx, &b) in out[..9].iter().enumerate() {
        let digit = u32::from(b - b'0');
        sum += digit * (10 - idx as u32);
    }
    let check = (11 - (sum % 11)) % 11;
    out[9] = if check == 10 {
        b'X'
    } else {
        b'0' + check as u8
    };
    Some(out)
}

/// Normalizes or converts an ISBN string on the stack without heap allocations.
/// `mode`:
/// - `0`: `BookIsbn::normalize` (writes 10 or 13 bytes, or 0 if invalid)
/// - `1`: `BookIsbn::toIsbn13` (writes 13 bytes, or 0 if not a valid ISBN-10)
/// - `2`: `BookIsbn::toIsbn10` (writes 10 bytes, or 0 if not a valid `978` ISBN-13)
/// - `3`: `BookIsbn::equivalents` (writes sorted NUL-separated identifiers, or 0 if invalid)
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_book_isbn_normalize(
    input: *const u8,
    input_len: usize,
    mode: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input.is_null() && input_len > 0 {
        return -1;
    }
    if input_len == 0 {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(input, input_len) };
    let Some((norm, norm_len)) = isbn_normalize_stack(bytes) else {
        return 0;
    };

    let mut cur = OutCursor::new(out_buf, out_cap);
    match mode {
        0 => {
            cur.push_slice(&norm[..norm_len]);
        }
        1 => {
            if norm_len != 10 {
                return 0;
            }
            let isbn13 = isbn10_to_13_stack(&norm[..10]);
            cur.push_slice(&isbn13);
        }
        2 => {
            if norm_len != 13 {
                return 0;
            }
            let Some(isbn10) = isbn13_to_10_stack(&norm) else {
                return 0;
            };
            cur.push_slice(&isbn10);
        }
        3 => {
            if norm_len == 10 {
                let isbn10 = &norm[..10];
                let isbn13 = isbn10_to_13_stack(isbn10);
                let isbn10_first = isbn10[9] != b'X' || isbn10 <= &isbn13[..];
                if isbn10_first {
                    cur.push_slice(isbn10);
                    cur.push_byte(0);
                    cur.push_slice(&isbn13);
                } else {
                    cur.push_slice(&isbn13);
                    cur.push_byte(0);
                    cur.push_slice(isbn10);
                }
            } else if let Some(isbn10) = isbn13_to_10_stack(&norm) {
                let isbn10_first = isbn10[9] != b'X' || &isbn10[..] <= &norm[..];
                if isbn10_first {
                    cur.push_slice(&isbn10);
                    cur.push_byte(0);
                    cur.push_slice(&norm);
                } else {
                    cur.push_slice(&norm);
                    cur.push_byte(0);
                    cur.push_slice(&isbn10);
                }
            } else {
                cur.push_slice(&norm);
            }
        }
        _ => return -1,
    }
    cur.finish()
}

/// Compares two ISBN strings for equivalence (`BookIsbn::equivalent`) in a single zero-allocation call.
/// Returns `1` when equivalent, `0` when not equivalent, or `-1` on null pointers with non-zero length.
///
/// # Safety
///
/// `left` and `right` must be valid for reads of `left_len` and `right_len` bytes when non-empty.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_book_isbn_equivalent(
    left: *const u8,
    left_len: usize,
    right: *const u8,
    right_len: usize,
) -> i32 {
    if (left_len > 0 && left.is_null()) || (right_len > 0 && right.is_null()) {
        return -1;
    }
    if left_len == 0 || right_len == 0 {
        return 0;
    }
    let l_bytes = unsafe { slice::from_raw_parts(left, left_len) };
    let r_bytes = unsafe { slice::from_raw_parts(right, right_len) };

    let Some((l_norm, l_len)) = isbn_normalize_stack(l_bytes) else {
        return 0;
    };
    let Some((r_norm, r_len)) = isbn_normalize_stack(r_bytes) else {
        return 0;
    };

    if l_len == r_len && l_norm[..l_len] == r_norm[..r_len] {
        return 1;
    }
    if l_len == 10 && r_len == 13 {
        let l13 = isbn10_to_13_stack(&l_norm[..10]);
        return i32::from(l13 == r_norm);
    }
    if l_len == 13 && r_len == 10 {
        let r13 = isbn10_to_13_stack(&r_norm[..10]);
        return i32::from(l_norm == r13);
    }
    0
}

/// Batch normalizes or converts `count` NUL-separated ISBN strings into NUL-separated results.
/// `mode`: `0` = normalize, `1` = toIsbn13, `2` = toIsbn10.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes when non-null and `input_len > 0`.
/// `out_buf` must be valid for writes of `out_cap` bytes when non-null and `out_cap > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_book_isbn_normalize_batch_nul(
    input: *const u8,
    input_len: usize,
    count: usize,
    mode: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if count == 0 {
        return 0;
    }
    if (input.is_null() && input_len > 0) || mode > 2 {
        return -1;
    }
    let bytes = if input_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };

    let mut cur = OutCursor::new(out_buf, out_cap);
    let mut seen = 0usize;
    for (idx, seg) in bytes.split(|&b| b == 0).enumerate() {
        seen += 1;
        if seen > count {
            return -1;
        }
        if idx > 0 {
            cur.push_byte(0);
        }
        if let Some((norm, norm_len)) = isbn_normalize_stack(seg) {
            match mode {
                0 => cur.push_slice(&norm[..norm_len]),
                1 => {
                    if norm_len == 10 {
                        let isbn13 = isbn10_to_13_stack(&norm[..10]);
                        cur.push_slice(&isbn13);
                    }
                }
                2 => {
                    if norm_len == 13
                        && let Some(isbn10) = isbn13_to_10_stack(&norm)
                    {
                        cur.push_slice(&isbn10);
                    }
                }
                _ => {}
            }
        }
    }
    if seen != count {
        return -1;
    }
    cur.finish()
}

// ---------------------------------------------------------------------------
// Log viewer search (`LogSearcher`, `SearchQuery`, `LogIndexQuery`)
// ---------------------------------------------------------------------------

const LOG_SEARCH_DECLINED: isize = -2;

/// Lines whose level-header token is longer than this are handed back to PHP so PCRE's
/// backtrack-limit error (reported to the user) stays authoritative for pathological lines.
const LOG_LEVEL_TOKEN_DEFER_BYTES: usize = 4096;

const LOG_SCAN_CASE_SENSITIVE: u32 = 0;
const LOG_SCAN_CASE_INSENSITIVE: u32 = 1;
const LOG_SCAN_LEVEL_HEADER: u32 = 2;

/// Upper-case Monolog level names, indexed like `LogEntryParser::LEVELS` (bit `i` of a level mask).
const LOG_LEVEL_NAMES: [&[u8]; 8] = [
    b"DEBUG",
    b"INFO",
    b"NOTICE",
    b"WARNING",
    b"ERROR",
    b"CRITICAL",
    b"ALERT",
    b"EMERGENCY",
];

const SWAR_LO: u64 = 0x0101_0101_0101_0101;
const SWAR_HI: u64 = 0x8080_8080_8080_8080;

/// Index of the first byte equal to `needle` in `hay[from..]`; with `fold`, `needle` must be an ASCII
/// lower-case letter and the upper-case letter matches too (`b | 0x20` only maps that pair onto it).
#[inline]
fn log_find_byte(hay: &[u8], from: usize, needle: u8, fold: bool) -> Option<usize> {
    let mask = if fold { 0x2020_2020_2020_2020u64 } else { 0 };
    let pattern = SWAR_LO.wrapping_mul(needle as u64);
    let mut i = from;
    while i + 8 <= hay.len() {
        let word = u64::from_le_bytes(hay[i..i + 8].try_into().unwrap()) | mask;
        let x = word ^ pattern;
        let t = x.wrapping_sub(SWAR_LO) & !x & SWAR_HI;
        if t != 0 {
            return Some(i + (t.trailing_zeros() / 8) as usize);
        }
        i += 8;
    }
    while i < hay.len() {
        let b = if fold { hay[i] | 0x20 } else { hay[i] };
        if b == needle {
            return Some(i);
        }
        i += 1;
    }
    None
}

#[inline]
fn log_count_newlines(bytes: &[u8]) -> usize {
    bytes.iter().filter(|&&b| b == b'\n').count()
}

/// Byte-wise substring finder matching PHP `strpos` (exact) or `stripos` (ASCII case folding).
struct LogTermFinder<'a> {
    term: &'a [u8],
    folded: Vec<u8>,
    insensitive: bool,
    first: u8,
    first_fold: bool,
}

impl<'a> LogTermFinder<'a> {
    fn new(term: &'a [u8], insensitive: bool) -> Self {
        let folded: Vec<u8> = if insensitive {
            term.iter().map(|b| b.to_ascii_lowercase()).collect()
        } else {
            Vec::new()
        };
        let first = if insensitive { folded[0] } else { term[0] };
        Self {
            term,
            insensitive,
            first,
            first_fold: insensitive && first.is_ascii_lowercase(),
            folded,
        }
    }

    #[inline]
    fn matches_at(&self, hay: &[u8], at: usize) -> bool {
        let candidate = &hay[at..at + self.term.len()];
        if self.insensitive {
            candidate
                .iter()
                .zip(self.folded.iter())
                .all(|(&h, &n)| h.to_ascii_lowercase() == n)
        } else {
            candidate == self.term
        }
    }

    /// First match starting in `hay[from..end]` and ending at or before `end`.
    #[inline]
    fn find(&self, hay: &[u8], from: usize, end: usize) -> Option<usize> {
        let n = self.term.len();
        if end < n {
            return None;
        }
        let last_start = end - n;
        let window = &hay[..end];
        let mut at = from;
        while at <= last_start {
            let candidate = log_find_byte(&window[..last_start + 1], at, self.first, self.first_fold)?;
            if self.matches_at(hay, candidate) {
                return Some(candidate);
            }
            at = candidate + 1;
        }
        None
    }
}

/// Header slots of a hit window: oldest kept slot, kept hits, total hits, eligible hits.
const LOG_WINDOW_HEADER: usize = 4;

/// The caller-owned hit window `int64_t[4 + 2 * keep]` that persists across chunk calls, mirroring
/// `LogSearcher::searchFile`: hits at or past the `before` cursor (`i64::MAX` for none) only count toward the total,
/// and only the newest `keep` eligible `[offset, line]` hits are kept (a ring starting at slot `header[0]`).
struct LogHitRing<'a> {
    slots: &'a mut [i64],
    keep: usize,
    before: i64,
}

impl<'a> LogHitRing<'a> {
    /// # Safety
    ///
    /// `window` must be valid for reads and writes of `4 + 2 * keep` `int64_t` values.
    unsafe fn from_raw(window: *mut i64, keep: usize, before: i64) -> Option<Self> {
        if window.is_null() {
            return None;
        }
        let len = keep.checked_mul(2)?.checked_add(LOG_WINDOW_HEADER)?;
        let slots = unsafe { slice::from_raw_parts_mut(window, len) };
        let (head, kept) = (slots[0], slots[1]);
        if head < 0 || kept < 0 || kept as usize > keep || (kept as usize) < keep && head != 0 || head as usize >= keep.max(1) {
            return None;
        }
        Some(Self { slots, keep, before })
    }

    #[inline]
    fn push(&mut self, offset: i64, line: i64) {
        self.slots[2] += 1;
        if offset >= self.before {
            return;
        }
        self.slots[3] += 1;
        self.keep_hit(offset, line);
    }

    #[inline]
    fn keep_hit(&mut self, offset: i64, line: i64) {
        if self.keep == 0 {
            return;
        }
        let kept = self.slots[1] as usize;
        let slot = if kept < self.keep {
            self.slots[1] += 1;
            kept
        } else {
            let head = self.slots[0] as usize;
            self.slots[0] = ((head + 1) % self.keep) as i64;
            head
        };
        let at = LOG_WINDOW_HEADER + slot * 2;
        self.slots[at] = offset;
        self.slots[at + 1] = line;
    }
}

/// Parses a `grep -b -n` field: 1..=18 ASCII digits, so PHP's `(int)` cast agrees without overflow.
#[inline]
fn log_parse_grep_field(field: &[u8]) -> Option<i64> {
    if field.is_empty() || field.len() > 18 {
        return None;
    }
    let mut value = 0i64;
    for &b in field {
        if !b.is_ascii_digit() {
            return None;
        }
        value = value * 10 + (b - b'0') as i64;
    }
    Some(value)
}

/// Whether `content` matches `SearchQuery::LEVEL_HEADER_PCRE` (`^\[[^\]]*\]\s+[\w.\-]+\.(?:LEVELS):`)
/// for the levels in `mask`; `None` defers the line to PHP.
#[inline]
fn log_level_header_matches(content: &[u8], mask: u32) -> Option<bool> {
    if content.first() != Some(&b'[') {
        return Some(false);
    }
    let Some(close) = content[1..].iter().position(|&b| b == b']') else {
        return Some(false);
    };
    let mut p = close + 2;
    let ws_start = p;
    while p < content.len() && is_ascii_ws(content[p]) {
        p += 1;
    }
    if p == ws_start {
        return Some(false);
    }
    let token_start = p;
    while p < content.len() && (is_word_byte(content[p]) || matches!(content[p], b'.' | b'-')) {
        p += 1;
    }
    if p - token_start > LOG_LEVEL_TOKEN_DEFER_BYTES {
        return None;
    }
    if content.get(p) != Some(&b':') {
        return Some(false);
    }
    let token = &content[token_start..p];
    for (index, level) in LOG_LEVEL_NAMES.iter().enumerate() {
        if mask & (1 << index) == 0 || token.len() < level.len() + 2 {
            continue;
        }
        let dot = token.len() - level.len() - 1;
        if token[dot] == b'.' && &token[dot + 1..] == *level {
            return Some(true);
        }
    }
    Some(false)
}

/// `rtrim($line, "\r\n")` for a line already cut at its `\n`.
#[inline]
fn log_trim_cr(line: &[u8]) -> &[u8] {
    let mut end = line.len();
    while end > 0 && matches!(line[end - 1], b'\r' | b'\n') {
        end -= 1;
    }
    &line[..end]
}

/// Parses `grep -a -b -n` output exactly like `LogSearcher::parseGrepLines`: every `\n`-separated line with
/// two colons is a hit `LINE:OFFSET:text`, collected into the caller's hit window (see `LogHitRing`).
///
/// Returns the number of hits parsed, `-1` for invalid arguments, or `-2` when a field is not a plain digit
/// run (PHP's `(int)` cast then decides); the window is left untouched on any negative return.
///
/// # Safety
///
/// `input` must be valid for reads of `input_len` bytes and `window` for reads and writes of `4 + 2 * keep`
/// `int64_t` values.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_parse_grep_lines(
    input: *const u8,
    input_len: usize,
    before: i64,
    keep: usize,
    window: *mut i64,
) -> isize {
    if input.is_null() && input_len > 0 {
        return -1;
    }
    let Some(mut ring) = (unsafe { LogHitRing::from_raw(window, keep, before) }) else {
        return -1;
    };
    let bytes = if input_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };

    // Hits are staged so a declined field leaves the window as it was.
    let mut staged: Vec<[i64; 2]> = Vec::with_capacity(input_len / 64 + 1);
    let mut pos = 0usize;
    while pos < bytes.len() {
        let end = log_find_byte(bytes, pos, b'\n', false).unwrap_or(bytes.len());
        let line = &bytes[pos..end];
        pos = end + 1;
        let Some(first) = line.iter().position(|&b| b == b':') else {
            continue;
        };
        let Some(second) = line[first + 1..].iter().position(|&b| b == b':') else {
            continue;
        };
        let (Some(line_number), Some(offset)) = (
            log_parse_grep_field(&line[..first]),
            log_parse_grep_field(&line[first + 1..first + 1 + second]),
        ) else {
            return LOG_SEARCH_DECLINED;
        };
        staged.push([offset, line_number]);
    }

    for &[offset, line_number] in &staged {
        ring.push(offset, line_number);
    }
    staged.len() as isize
}

/// Scans a chunk of a log file for `LogSearcher::phpHits` without splitting it into PHP strings.
///
/// `chunk[start..]` is scanned line by line (`\n`-terminated, trailing `\r`/`\n` trimmed as `rtrim($line,
/// "\r\n")`); without `is_final` the trailing partial line is left unconsumed for the next read. `mode` selects
/// a case-sensitive (`0`, `str_contains`) or ASCII case-insensitive (`1`, `stripos`) term, or the level header
/// pattern (`2`) for the levels in `level_mask` (bit `i` = `LogEntryParser::LEVELS[i]`). Hit offsets are
/// `base_offset` plus the line start; `line_number` counts the lines before `chunk[start]`.
///
/// Hits are collected into the caller's hit window (see `LogHitRing`). `state_out[0..3]` receives the chunk
/// position where scanning stopped, the line count before it, and `1` when the line at that position must be
/// evaluated by PHP (`0` otherwise).
///
/// Returns the number of hits found, `-1` for invalid arguments, or `-2` when the term cannot be matched
/// line-wise in Rust (empty, or containing `\r`/`\n`).
///
/// # Safety
///
/// `chunk` must be valid for reads of `chunk_len` bytes, `term` for `term_len` bytes, `window` for reads and
/// writes of `4 + 2 * keep` `int64_t` values, and `state_out` for writes of three `int64_t` values.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_scan_chunk_hits(
    chunk: *const u8,
    chunk_len: usize,
    start: usize,
    is_final: u32,
    mode: u32,
    term: *const u8,
    term_len: usize,
    level_mask: u32,
    base_offset: i64,
    line_number: i64,
    before: i64,
    keep: usize,
    window: *mut i64,
    state_out: *mut i64,
) -> isize {
    if state_out.is_null()
        || (chunk.is_null() && chunk_len > 0)
        || (term.is_null() && term_len > 0)
        || start > chunk_len
        || mode > LOG_SCAN_LEVEL_HEADER
    {
        return -1;
    }
    let bytes = if chunk_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(chunk, chunk_len) }
    };
    let term = if term_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(term, term_len) }
    };
    if mode != LOG_SCAN_LEVEL_HEADER && (term.is_empty() || term.iter().any(|&b| b == b'\n' || b == b'\r')) {
        return LOG_SEARCH_DECLINED;
    }

    // Complete lines end at `limit`; a final chunk also owns its unterminated last line.
    let limit = if is_final != 0 {
        bytes.len()
    } else {
        bytes[start..]
            .iter()
            .rposition(|&b| b == b'\n')
            .map_or(start, |p| start + p + 1)
    };

    let Some(mut window) = (unsafe { LogHitRing::from_raw(window, keep, before) }) else {
        return -1;
    };
    let hits_before = window.slots[2];
    let mut lines = line_number;
    let mut stop = limit;
    let mut deferred = 0i64;

    if mode == LOG_SCAN_LEVEL_HEADER {
        let mut pos = start;
        while pos < limit {
            let end = log_find_byte(&bytes[..limit], pos, b'\n', false).unwrap_or(limit);
            match log_level_header_matches(log_trim_cr(&bytes[pos..end]), level_mask) {
                None => {
                    stop = pos;
                    deferred = 1;
                    break;
                }
                Some(true) => window.push(base_offset + pos as i64, lines + 1),
                Some(false) => {}
            }
            lines += 1;
            pos = end + 1;
        }
    } else {
        let insensitive = match mode {
            LOG_SCAN_CASE_SENSITIVE => false,
            LOG_SCAN_CASE_INSENSITIVE => true,
            _ => return -1,
        };
        let finder = LogTermFinder::new(term, insensitive);
        let mut line_start = start;
        let mut cursor = start;
        while let Some(hit) = finder.find(bytes, cursor, limit) {
            if let Some(last) = bytes[line_start..hit].iter().rposition(|&b| b == b'\n') {
                lines += log_count_newlines(&bytes[line_start..line_start + last + 1]) as i64;
                line_start += last + 1;
            }
            window.push(base_offset + line_start as i64, lines + 1);
            let end = log_find_byte(&bytes[..limit], hit, b'\n', false).unwrap_or(limit);
            lines += 1;
            line_start = (end + 1).min(limit);
            cursor = line_start;
            if end >= limit {
                break;
            }
        }
        if line_start < limit {
            let rest = &bytes[line_start..limit];
            lines += log_count_newlines(rest) as i64;
            if rest.last() != Some(&b'\n') {
                lines += 1;
            }
        }
    }

    unsafe {
        *state_out = stop as i64;
        *state_out.add(1) = lines;
        *state_out.add(2) = deferred;
    }
    (window.slots[2] - hits_before) as isize
}

/// `SearchQuery::plainRanges`: up to `max_ranges` non-overlapping `[start, length]` matches of `term` in `text`
/// (`strpos` or ASCII `stripos`), written as native-endian `int64` pairs.
///
/// Returns the bytes written, `-needed` when `out_buf` is too small, or `-1` for invalid arguments.
///
/// # Safety
///
/// `text` must be valid for reads of `text_len` bytes, `term` for `term_len` bytes, and `out_buf` for writes
/// of `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_plain_ranges(
    text: *const u8,
    text_len: usize,
    term: *const u8,
    term_len: usize,
    case_sensitive: u32,
    max_ranges: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if term.is_null() || term_len == 0 || (text.is_null() && text_len > 0) {
        return -1;
    }
    let text = if text_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(text, text_len) }
    };
    let term = unsafe { slice::from_raw_parts(term, term_len) };
    let finder = LogTermFinder::new(term, case_sensitive == 0);
    let mut out = OutCursor::new(out_buf, out_cap);
    let mut cursor = 0usize;
    let mut found = 0usize;
    while found < max_ranges {
        let Some(hit) = finder.find(text, cursor, text.len()) else {
            break;
        };
        out.push_slice(&(hit as i64).to_ne_bytes());
        out.push_slice(&(term_len as i64).to_ne_bytes());
        found += 1;
        cursor = hit + term_len;
    }
    out.finish()
}

/// `LogIndexQuery::matchExpression` for ASCII terms: word tokens (`[A-Za-z0-9_]+`) lowered, edge tokens that
/// may be cut-off words get infix wildcards (or are dropped below `min_infix` bytes), and several tokens form a
/// quoted phrase.
///
/// Returns the bytes written (`0` for no expression, PHP `null`), `-needed` when `out_buf` is too small, `-1`
/// for invalid arguments, or `-2` for a non-ASCII term (Unicode classes and `mb_strtolower` stay in PHP).
///
/// # Safety
///
/// `term` must be valid for reads of `term_len` bytes and `out_buf` for writes of `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_log_index_match_expression(
    term: *const u8,
    term_len: usize,
    min_infix: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if term.is_null() && term_len > 0 {
        return -1;
    }
    let term = if term_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(term, term_len) }
    };
    if !term.is_ascii() {
        return LOG_SEARCH_DECLINED;
    }
    let tokens: Vec<&[u8]> = term
        .split(|&b| !is_word_byte(b))
        .filter(|token| !token.is_empty())
        .collect();
    if tokens.is_empty() {
        return 0;
    }
    let starts_in_word = is_word_byte(term[0]);
    let ends_in_word = is_word_byte(term[term.len() - 1]);
    let last = tokens.len() - 1;
    let mut parts: Vec<(bool, &[u8], bool)> = Vec::with_capacity(tokens.len());
    for (index, token) in tokens.iter().enumerate() {
        let prefix = index == 0 && starts_in_word;
        let suffix = index == last && ends_in_word;
        if (prefix || suffix) && token.len() < min_infix {
            continue;
        }
        parts.push((prefix, token, suffix));
    }
    if parts.is_empty() {
        return 0;
    }

    let mut out = OutCursor::new(out_buf, out_cap);
    let quoted = parts.len() > 1;
    if quoted {
        out.push_byte(b'"');
    }
    for (index, (prefix, token, suffix)) in parts.iter().enumerate() {
        if index > 0 {
            out.push_byte(b' ');
        }
        if *prefix {
            out.push_byte(b'*');
        }
        for &b in token.iter() {
            out.push_byte(b.to_ascii_lowercase());
        }
        if *suffix {
            out.push_byte(b'*');
        }
    }
    if quoted {
        out.push_byte(b'"');
    }
    out.finish()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_tv_parse_info_standard_and_edge_cases() {
        let mut buf = [0u8; 512];
        let input = b"Doctor.Who.2005.S01E01.720p.BluRay.x264-GRP";
        let packed = unsafe {
            nntmux_tv_parse_info(input.as_ptr(), input.len(), 27, buf.as_mut_ptr(), buf.len())
        };
        assert!(packed > 0);
        let written = (packed & 0xFFFF) as usize;
        let parts: Vec<&[u8]> = buf[..written].split(|&b| b == 0).collect();
        assert_eq!(parts[0], b"Doctor Who");
        assert_eq!(parts[1], b"");
        assert_eq!(parts[2], b"Doctor Who (2005)");
        assert_eq!(parts[3], b"");
        assert_eq!((packed >> 16) & 0xFFFF, 1);
        assert_eq!((packed >> 32) & 0xFFFF, 1);
        assert_eq!((packed >> 48) & 0xFFFF, 2005);
    }

    #[test]
    fn test_movie_parse_search_name_basic() {
        let mut buf = [0u8; 256];
        let input = b"The.Free.Fall.2022.1080p.AMZN.WEB-DL.DDP5.1.H.264-GPRS";
        let packed = unsafe {
            nntmux_movie_parse_search_name(input.as_ptr(), input.len(), buf.as_mut_ptr(), buf.len())
        };
        assert!(packed > 0);
        let written = (packed & 0xFFFF) as usize;
        let year = packed >> 16;
        assert_eq!(&buf[..written], b"The Free Fall");
        assert_eq!(year, 2022);
    }

    #[test]
    fn test_log_header_and_plain_timestamp_spans() {
        let line = b"[2026-10-06T18:11:29.123456+02:00] app.jobs.WARNING: slow {\"id\":1}";
        let spans = unsafe { nntmux_log_parse_header_spans(line.as_ptr(), line.len()) };
        assert!(spans > 0);
        let ts_len = (spans & 0xFF) as usize;
        let chan_start = ((spans >> 8) & 0xFFFF) as usize;
        let chan_len = ((spans >> 24) & 0xFFFF) as usize;
        let msg_start = ((spans >> 40) & 0xFFFF) as usize;
        let lvl = ((spans >> 56) & 0xFF) as u8;
        assert_eq!(&line[1..1 + ts_len], b"2026-10-06T18:11:29.123456+02:00");
        assert_eq!(&line[chan_start..chan_start + chan_len], b"app.jobs");
        assert_eq!(lvl, 3);
        assert_eq!(&line[msg_start..], b"slow {\"id\":1}");

        let plain = b"  2026-10-05 21:53:21 App\\Jobs\\ReindexReleaseJob ... DONE";
        let ts_off = unsafe { nntmux_log_plain_timestamp_span(plain.as_ptr(), plain.len()) };
        assert_eq!(ts_off, 2);
    }

    #[test]
    fn test_donor_selection_and_canonical_validation() {
        let pairs: [i64; 6] = [950, 20, 1050, 10, 900, 30];
        let winner = unsafe { nntmux_donor_select_best_index(pairs.as_ptr(), 3, 1000, 10) };
        assert_eq!(winner, 1);

        let title = b"Valid.Scene.Release.2026-GROUP";
        let sname = b"valid.scene.release.2026-group";
        let ok = unsafe {
            nntmux_donor_validate_canonical_single(
                42,
                title.as_ptr(),
                title.len(),
                sname.as_ptr(),
                sname.len(),
            )
        };
        assert_eq!(ok, 1);
    }

    #[test]
    fn test_book_isbn_normalize_and_equivalent() {
        let mut buf = [0u8; 64];
        let raw10 = b"0-13-235088-2";
        let n10 = unsafe {
            nntmux_book_isbn_normalize(raw10.as_ptr(), raw10.len(), 0, buf.as_mut_ptr(), buf.len())
        };
        assert_eq!(n10, 10);
        assert_eq!(&buf[..10], b"0132350882");

        let n13 = unsafe {
            nntmux_book_isbn_normalize(raw10.as_ptr(), raw10.len(), 1, buf.as_mut_ptr(), buf.len())
        };
        assert_eq!(n13, 13);
        assert_eq!(&buf[..13], b"9780132350884");

        let raw13 = b"978-0-13-235088-4";
        let eq = unsafe {
            nntmux_book_isbn_equivalent(raw10.as_ptr(), raw10.len(), raw13.as_ptr(), raw13.len())
        };
        assert_eq!(eq, 1);
    }

    fn log_decode_pairs(buf: &[u8], written: isize) -> Vec<[i64; 2]> {
        assert!(written >= 0 && written % 16 == 0, "written {written}");
        buf[..written as usize]
            .chunks(16)
            .map(|c| {
                [
                    i64::from_ne_bytes(c[..8].try_into().unwrap()),
                    i64::from_ne_bytes(c[8..].try_into().unwrap()),
                ]
            })
            .collect()
    }

    /// Kept hits of a `LogHitRing` window, oldest first, plus the total and eligible counts.
    fn log_window_hits(window: &[i64], keep: usize) -> (Vec<[i64; 2]>, i64, i64) {
        let (head, kept) = (window[0] as usize, window[1] as usize);
        let hits = (0..kept)
            .map(|i| {
                let at = LOG_WINDOW_HEADER + ((head + i) % keep.max(1)) * 2;
                [window[at], window[at + 1]]
            })
            .collect();
        (hits, window[2], window[3])
    }

    struct LogLcg(u64);

    impl LogLcg {
        fn next(&mut self, bound: usize) -> usize {
            self.0 = self.0.wrapping_mul(6364136223846793005).wrapping_add(1442695040888963407);
            ((self.0 >> 33) as usize) % bound
        }
    }

    /// Reference for `LogSearcher::phpHits`: fgets lines, `rtrim("\r\n")`, then the line predicate.
    fn log_reference_hits(data: &[u8], matches: &dyn Fn(&[u8]) -> bool) -> (Vec<[i64; 2]>, i64) {
        let mut hits = Vec::new();
        let mut offset = 0usize;
        let mut line = 0i64;
        while offset < data.len() {
            let end = data[offset..].iter().position(|&b| b == b'\n').map_or(data.len(), |p| offset + p + 1);
            line += 1;
            let mut content = &data[offset..end];
            while let Some((&last, rest)) = content.split_last() {
                if last == b'\r' || last == b'\n' {
                    content = rest;
                } else {
                    break;
                }
            }
            if matches(content) {
                hits.push([offset as i64, line]);
            }
            offset = end;
        }
        (hits, line)
    }

    /// Brute-force `^\[[^\]]*\]\s+[\w.\-]+\.(?:LEVELS):` trying every split, independent of the fast path.
    fn log_reference_level(content: &[u8], mask: u32) -> bool {
        if content.first() != Some(&b'[') {
            return false;
        }
        let Some(close) = content.iter().position(|&b| b == b']') else {
            return false;
        };
        let mut p = close + 1;
        let ws = p;
        while p < content.len() && is_ascii_ws(content[p]) {
            p += 1;
        }
        if p == ws {
            return false;
        }
        for split in p + 1..content.len() {
            if !content[p..split].iter().all(|&b| is_word_byte(b) || b == b'.' || b == b'-') {
                break;
            }
            if content[split] != b'.' {
                continue;
            }
            for (i, level) in LOG_LEVEL_NAMES.iter().enumerate() {
                let end = split + 1 + level.len();
                if mask & (1 << i) != 0 && content.get(split + 1..end) == Some(level) && content.get(end) == Some(&b':') {
                    return true;
                }
            }
        }
        false
    }

    /// Drives `nntmux_log_scan_chunk_hits` like `LogSearcher::nativeHits`, with random chunk sizes.
    fn log_scan_all(data: &[u8], mode: u32, term: &[u8], mask: u32, rng: &mut LogLcg) -> (Vec<[i64; 2]>, i64) {
        let mut hits = Vec::new();
        let mut offset = 0usize;
        let mut line = 0i64;
        let keep = 1 << 14;
        let mut window = vec![0i64; LOG_WINDOW_HEADER + 2 * keep];
        let mut state = [0i64; 3];
        let mut read = 1 + rng.next(64);
        loop {
            let chunk = &data[offset..(offset + read).min(data.len())];
            if chunk.is_empty() {
                break;
            }
            let is_final = chunk.len() < read;
            let mut start = 0usize;
            let stop;
            loop {
                let found = unsafe {
                    nntmux_log_scan_chunk_hits(
                        chunk.as_ptr(), chunk.len(), start, is_final as u32, mode, term.as_ptr(), term.len(),
                        mask, offset as i64, line, i64::MAX, keep, window.as_mut_ptr(), state.as_mut_ptr(),
                    )
                };
                assert!(found >= 0);
                // Hits PHP collects itself (deferred lines) follow the native ones, as `LogSearcher` flushes first.
                let (kept, total, eligible) = log_window_hits(&window, keep);
                assert_eq!((total, eligible), (kept.len() as i64, kept.len() as i64));
                hits.extend(kept);
                window.fill(0);
                line = state[1];
                if state[2] != 1 {
                    stop = state[0] as usize;
                    break;
                }
                let at = state[0] as usize;
                let end = chunk[at..].iter().position(|&b| b == b'\n').map_or(chunk.len(), |p| at + p + 1);
                line += 1;
                if log_reference_level(log_trim_cr(&chunk[at..end]), mask) {
                    hits.push([(offset + at) as i64, line]);
                }
                start = end;
            }
            if is_final {
                break;
            }
            if stop == 0 {
                read *= 2;
                continue;
            }
            offset += stop;
            read = 1 + rng.next(64);
        }
        (hits, line)
    }

    #[test]
    fn test_log_parse_grep_lines_window_cursor_and_decline() {
        let input = b"3:120:needle one\n\n7:400:a:b\nnoise\n9:512:x\n12:900:tail";
        let mut window = vec![0i64; LOG_WINDOW_HEADER + 2 * 16];
        let parsed = unsafe { nntmux_log_parse_grep_lines(input.as_ptr(), input.len(), i64::MAX, 16, window.as_mut_ptr()) };
        assert_eq!(parsed, 4);
        assert_eq!(log_window_hits(&window, 16), (vec![[120, 3], [400, 7], [512, 9], [900, 12]], 4, 4));

        // The window persists across calls: the cursor drops 900, and only the newest two eligible hits stay.
        let mut window = vec![0i64; LOG_WINDOW_HEADER + 2 * 2];
        for _ in 0..2 {
            let parsed = unsafe { nntmux_log_parse_grep_lines(input.as_ptr(), input.len(), 600, 2, window.as_mut_ptr()) };
            assert_eq!(parsed, 4);
        }
        assert_eq!(log_window_hits(&window, 2), (vec![[400, 7], [512, 9]], 8, 6));

        let snapshot = window.clone();
        for odd in [&b" 3:120:x"[..], b"3:1e3:x", b":5:x", b"3:-1:x", b"1234567890123456789:1:x", b"1:2:ok\n3:x:no"] {
            let parsed = unsafe { nntmux_log_parse_grep_lines(odd.as_ptr(), odd.len(), i64::MAX, 2, window.as_mut_ptr()) };
            assert_eq!(parsed, LOG_SEARCH_DECLINED);
            assert_eq!(window, snapshot);
        }

        let mut corrupt = vec![0i64, 3, 0, 0, 0, 0, 0, 0];
        let parsed = unsafe { nntmux_log_parse_grep_lines(input.as_ptr(), input.len(), i64::MAX, 2, corrupt.as_mut_ptr()) };
        assert_eq!(parsed, -1);
    }

    #[test]
    fn test_log_scan_chunk_hits_matches_line_reference() {
        let pieces: [&[u8]; 14] = [
            b"[2026-10-01 10:00:00] local.INFO: needle one",
            b"[2026-10-01 10:01:00] app.jobs.ERROR: Boom NEEDLE",
            b"#0 needle in trace\r",
            b"[x]\t\x0blocal.WARNING:",
            b"[x] .WARNING:",
            b"[x] local.WARNINGS:",
            b"[x]local.INFO:",
            b"",
            b"\r\r",
            b"nee\rdle",
            b"[] a-b.c.DEBUG: x",
            b"\xc3\xa9needle\xff",
            b"[x] local.EMERGENCY: neeDle",
            b"needl",
        ];
        let mut rng = LogLcg(20261024);
        for round in 0..400 {
            let mut data = Vec::new();
            for _ in 0..rng.next(30) {
                data.extend_from_slice(pieces[rng.next(pieces.len())]);
                if rng.next(10) > 0 {
                    data.push(b'\n');
                }
            }
            let mask = if round % 3 == 0 { 0xFF } else { rng.next(256) as u32 };
            let level = log_reference_hits(&data, &|c| log_reference_level(c, mask));
            assert_eq!(log_scan_all(&data, LOG_SCAN_LEVEL_HEADER, b"", mask, &mut rng), level, "level {mask}");

            for term in [&b"needle"[..], b"NEEDLE", b"e", b"ne", b"\xc3\xa9N", b"]", b"needle\xff"] {
                let exact = log_reference_hits(&data, &|c| c.windows(term.len()).any(|w| w == term));
                assert_eq!(log_scan_all(&data, LOG_SCAN_CASE_SENSITIVE, term, 0, &mut rng), exact);
                let folded = log_reference_hits(&data, &|c| c.windows(term.len()).any(|w| w.eq_ignore_ascii_case(term)));
                assert_eq!(log_scan_all(&data, LOG_SCAN_CASE_INSENSITIVE, term, 0, &mut rng), folded);
            }
        }
    }

    #[test]
    fn test_log_scan_chunk_hits_defers_long_level_tokens_and_rejects_line_breaking_terms() {
        let mut data = b"[x] ".to_vec();
        data.extend(std::iter::repeat_n(b'a', LOG_LEVEL_TOKEN_DEFER_BYTES + 1));
        data.extend_from_slice(b".INFO: x\n[x] local.INFO: y\n");
        let mut window = vec![0i64; LOG_WINDOW_HEADER + 2 * 8];
        let mut state = [0i64; 3];
        let found = unsafe {
            nntmux_log_scan_chunk_hits(
                data.as_ptr(), data.len(), 0, 1, LOG_SCAN_LEVEL_HEADER, std::ptr::null(), 0, 0xFF, 0, 0, i64::MAX, 8,
                window.as_mut_ptr(), state.as_mut_ptr(),
            )
        };
        assert_eq!(found, 0);
        assert_eq!(state, [0, 0, 1]);

        let resume = data.iter().position(|&b| b == b'\n').unwrap() + 1;
        let found = unsafe {
            nntmux_log_scan_chunk_hits(
                data.as_ptr(), data.len(), resume, 1, LOG_SCAN_LEVEL_HEADER, std::ptr::null(), 0, 0xFF, 1000, 1,
                i64::MAX, 8, window.as_mut_ptr(), state.as_mut_ptr(),
            )
        };
        assert_eq!(found, 1);
        assert_eq!(state, [data.len() as i64, 2, 0]);
        assert_eq!(log_window_hits(&window, 8), (vec![[1000 + resume as i64, 2]], 1, 1));

        for term in [&b"a\nb"[..], b"a\r", b""] {
            let found = unsafe {
                nntmux_log_scan_chunk_hits(
                    data.as_ptr(), data.len(), 0, 1, LOG_SCAN_CASE_SENSITIVE, term.as_ptr(), term.len(), 0, 0, 0,
                    i64::MAX, 8, window.as_mut_ptr(), state.as_mut_ptr(),
                )
            };
            assert_eq!(found, LOG_SEARCH_DECLINED);
        }
    }

    #[test]
    fn test_log_plain_ranges_and_index_match_expression() {
        let text = b"Needle needleneedle NEEDLE";
        let mut out = [0u8; 256];
        let written = unsafe {
            nntmux_log_plain_ranges(text.as_ptr(), text.len(), b"needle".as_ptr(), 6, 0, 3, out.as_mut_ptr(), out.len())
        };
        assert_eq!(log_decode_pairs(&out, written), vec![[0, 6], [7, 6], [13, 6]]);
        let written = unsafe {
            nntmux_log_plain_ranges(text.as_ptr(), text.len(), b"aa".as_ptr(), 2, 1, 3, out.as_mut_ptr(), out.len())
        };
        assert_eq!(written, 0);

        let cases: [(&[u8], &[u8]); 6] = [
            (b"Jobs\\ReindexRel", b"\"*jobs reindexrel*\""),
            (b"Error", b"*error*"),
            (b" ab cd ", b"\"ab cd\""),
            (b"ab:", b""),
            (b"!!", b""),
            (b"x.ReindexRel", b"reindexrel*"),
        ];
        for (term, expected) in cases {
            let written = unsafe {
                nntmux_log_index_match_expression(term.as_ptr(), term.len(), 3, out.as_mut_ptr(), out.len())
            };
            assert_eq!(&out[..written as usize], expected);
        }
        let written = unsafe {
            nntmux_log_index_match_expression("é".as_ptr(), 2, 3, out.as_mut_ptr(), out.len())
        };
        assert_eq!(written, LOG_SEARCH_DECLINED);
    }
}
