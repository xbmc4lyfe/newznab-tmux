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
}
