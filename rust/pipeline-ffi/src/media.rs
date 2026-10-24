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

// ---------------------------------------------------------------------------
// Image completeness, frame quality scoring & thumbnail frame selection
// (`ImageInspector`, `FrameQualityScorer`, `ThumbnailFrameSelector`)
// ---------------------------------------------------------------------------

const PHP_IMAGETYPE_JPEG: u32 = 2;
const PHP_IMAGETYPE_PNG: u32 = 3;
const PHP_IMAGETYPE_WEBP: u32 = 18;

#[inline]
fn image_is_complete_tail_slice(type_code: u32, file_size: u64, head_or_tail: &[u8]) -> i32 {
    if !matches!(
        type_code,
        PHP_IMAGETYPE_JPEG | PHP_IMAGETYPE_PNG | PHP_IMAGETYPE_WEBP
    ) {
        return -1;
    }
    if file_size < 16 {
        return 0;
    }

    if type_code == PHP_IMAGETYPE_WEBP {
        if head_or_tail.len() < 8 {
            return 0;
        }
        let declared = u32::from_le_bytes([
            head_or_tail[4],
            head_or_tail[5],
            head_or_tail[6],
            head_or_tail[7],
        ]) as u64;
        return i32::from(file_size >= declared + 8);
    }

    let tail_raw = if head_or_tail.len() > 4096 {
        &head_or_tail[head_or_tail.len() - 4096..]
    } else {
        head_or_tail
    };
    let mut end = tail_raw.len();
    while end > 0 && matches!(tail_raw[end - 1], 0 | b'\r' | b'\n' | b' ') {
        end -= 1;
    }
    let tail = &tail_raw[..end];

    if type_code == PHP_IMAGETYPE_PNG {
        let window = if tail.len() > 64 {
            &tail[tail.len() - 64..]
        } else {
            tail
        };
        i32::from(window.windows(4).any(|w| w == b"IEND"))
    } else {
        let window = if tail.len() > 1024 {
            &tail[tail.len() - 1024..]
        } else {
            tail
        };
        i32::from(window.windows(2).any(|w| w == b"\xFF\xD9"))
    }
}

/// Checks `ImageInspector::isComplete` given `type_code` (`2` = JPEG, `3` = PNG, `18` = WebP),
/// `file_size`, and either the first >=8 bytes (for WebP) or the last <=4096 bytes (for PNG/JPEG).
///
/// Returns `1` when complete, `0` when incomplete, or `-1` on invalid arguments / unsupported `type_code`.
///
/// # Safety
///
/// `head_or_tail_ptr` must be valid for reads of `head_or_tail_len` bytes when non-null and `head_or_tail_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_image_is_complete_tail(
    type_code: u32,
    file_size: u64,
    head_or_tail_ptr: *const u8,
    head_or_tail_len: usize,
) -> i32 {
    if head_or_tail_ptr.is_null() && head_or_tail_len > 0 {
        return -1;
    }
    let bytes = if head_or_tail_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(head_or_tail_ptr, head_or_tail_len) }
    };
    image_is_complete_tail_slice(type_code, file_size, bytes)
}

#[inline]
fn read_file_at(file: &mut std::fs::File, offset: u64, dst: &mut [u8]) -> std::io::Result<usize> {
    #[cfg(unix)]
    {
        use std::os::unix::fs::FileExt;
        let mut total = 0usize;
        while total < dst.len() {
            match file.read_at(&mut dst[total..], offset + total as u64) {
                Ok(0) => break,
                Ok(n) => total += n,
                Err(ref e) if e.kind() == std::io::ErrorKind::Interrupted => continue,
                Err(e) => return Err(e),
            }
        }
        Ok(total)
    }
    #[cfg(not(unix))]
    {
        use std::io::{Read, Seek, SeekFrom};
        file.seek(SeekFrom::Start(offset))?;
        let mut total = 0usize;
        while total < dst.len() {
            match file.read(&mut dst[total..]) {
                Ok(0) => break,
                Ok(n) => total += n,
                Err(ref e) if e.kind() == std::io::ErrorKind::Interrupted => continue,
                Err(e) => return Err(e),
            }
        }
        Ok(total)
    }
}

/// Checks `ImageInspector::isComplete` directly on `path_ptr[..path_len]` for `type_code`
/// (`2` = JPEG, `3` = PNG, `18` = WebP) without PHP stream overhead or intermediate string copies.
///
/// Returns `1` when complete, `0` when incomplete or unreadable/short, or `-1` on invalid arguments.
///
/// # Safety
///
/// `path_ptr` must be valid for reads of `path_len` bytes when non-null and `path_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_image_is_complete_file(
    path_ptr: *const u8,
    path_len: usize,
    type_code: u32,
) -> i32 {
    if path_ptr.is_null()
        || path_len == 0
        || !matches!(
            type_code,
            PHP_IMAGETYPE_JPEG | PHP_IMAGETYPE_PNG | PHP_IMAGETYPE_WEBP
        )
    {
        return -1;
    }
    let path_bytes = unsafe { slice::from_raw_parts(path_ptr, path_len) };
    if path_bytes.contains(&0) {
        return -1;
    }

    #[cfg(unix)]
    let path = {
        use std::os::unix::ffi::OsStrExt;
        std::path::Path::new(std::ffi::OsStr::from_bytes(path_bytes))
    };
    #[cfg(not(unix))]
    let path = {
        let Ok(s) = std::str::from_utf8(path_bytes) else {
            return -1;
        };
        std::path::Path::new(s)
    };

    let Ok(mut file) = std::fs::File::open(path) else {
        return 0;
    };
    let Ok(meta) = file.metadata() else {
        return 0;
    };
    if meta.is_dir() {
        return 0;
    }
    let size = meta.len();
    if size < 16 {
        return 0;
    }

    if type_code == PHP_IMAGETYPE_WEBP {
        let mut header = [0u8; 8];
        let Ok(n) = read_file_at(&mut file, 0, &mut header) else {
            return 0;
        };
        return image_is_complete_tail_slice(type_code, size, &header[..n]);
    }

    let mut tail = [0u8; 4096];
    let offset = size.saturating_sub(4096);
    let to_read = (size - offset) as usize;
    let Ok(n) = read_file_at(&mut file, offset, &mut tail[..to_read]) else {
        return 0;
    };
    image_is_complete_tail_slice(type_code, size, &tail[..n])
}

/// Computes `FrameQualityScorer::measure` BT.601 luma (`0.299*R + 0.587*G + 0.114*B`) mean and
/// standard deviation over `pixel_count` contiguous RGB triplets (`3 * pixel_count` bytes).
///
/// Returns `1` on success, or `-1` on invalid arguments.
///
/// # Safety
///
/// `rgb_bytes_ptr` must be valid for reads of `3 * pixel_count` bytes when non-null and `pixel_count > 0`.
/// `out_mean` and `out_stddev` must be valid for writes of one `f64` each.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_frame_luma_stats_rgb(
    rgb_bytes_ptr: *const u8,
    pixel_count: usize,
    out_mean: *mut f64,
    out_stddev: *mut f64,
) -> i32 {
    if rgb_bytes_ptr.is_null() || pixel_count == 0 || out_mean.is_null() || out_stddev.is_null() {
        return -1;
    }
    let Some(byte_len) = pixel_count.checked_mul(3) else {
        return -1;
    };
    let bytes = unsafe { slice::from_raw_parts(rgb_bytes_ptr, byte_len) };

    let mut sum = 0.0_f64;
    let mut sum_squares = 0.0_f64;
    for rgb in bytes.chunks_exact(3) {
        let luma = 0.299 * (rgb[0] as f64) + 0.587 * (rgb[1] as f64) + 0.114 * (rgb[2] as f64);
        sum += luma;
        sum_squares += luma * luma;
    }

    let pixels = (pixel_count.max(1)) as f64;
    let mean = sum / pixels;
    let variance = (sum_squares / pixels - mean * mean).max(0.0);
    unsafe {
        *out_mean = mean;
        *out_stddev = variance.sqrt();
    }
    1
}

/// Computes `FrameQualityScorer::measure` BT.601 luma mean and standard deviation directly from an
/// uncompressed 24-bit BMP buffer (such as `imagebmp($sample, $stream, false)`), iterating rows in
/// top-to-bottom `(y = 0..height, x = 0..width)` order so IEEE-754 summation is bit-identical to PHP's
/// nested `imagecolorat` loop.
///
/// Returns `1` on success, or `-1` on invalid or unsupported BMP data.
///
/// # Safety
///
/// `bmp_ptr` must be valid for reads of `bmp_len` bytes when non-null and `bmp_len > 0`.
/// `out_mean` and `out_stddev` must be valid for writes of one `f64` each.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_frame_luma_stats_bmp(
    bmp_ptr: *const u8,
    bmp_len: usize,
    out_mean: *mut f64,
    out_stddev: *mut f64,
) -> i32 {
    if bmp_ptr.is_null() || bmp_len < 54 || out_mean.is_null() || out_stddev.is_null() {
        return -1;
    }
    let bmp = unsafe { slice::from_raw_parts(bmp_ptr, bmp_len) };
    if &bmp[0..2] != b"BM" {
        return -1;
    }
    let data_offset = u32::from_le_bytes([bmp[10], bmp[11], bmp[12], bmp[13]]) as usize;
    let width_i32 = i32::from_le_bytes([bmp[18], bmp[19], bmp[20], bmp[21]]);
    let height_i32 = i32::from_le_bytes([bmp[22], bmp[23], bmp[24], bmp[25]]);
    let planes = u16::from_le_bytes([bmp[26], bmp[27]]);
    let bpp = u16::from_le_bytes([bmp[28], bmp[29]]);
    let compression = u32::from_le_bytes([bmp[30], bmp[31], bmp[32], bmp[33]]);

    if data_offset < 54
        || width_i32 <= 0
        || height_i32 == 0
        || height_i32 == i32::MIN
        || planes != 1
        || bpp != 24
        || compression != 0
    {
        return -1;
    }

    let width = width_i32 as usize;
    let height = height_i32.unsigned_abs() as usize;
    let Some(row_bytes) = width.checked_mul(3) else {
        return -1;
    };
    let stride = (row_bytes + 3) & !3;
    let Some(pixel_bytes) = stride.checked_mul(height) else {
        return -1;
    };
    let Some(required_len) = data_offset.checked_add(pixel_bytes) else {
        return -1;
    };
    if required_len > bmp_len {
        return -1;
    }

    let bottom_up = height_i32 > 0;
    let mut sum = 0.0_f64;
    let mut sum_squares = 0.0_f64;
    for y in 0..height {
        let bmp_row = if bottom_up { height - 1 - y } else { y };
        let row_start = data_offset + bmp_row * stride;
        let row = &bmp[row_start..row_start + row_bytes];
        for bgr in row.chunks_exact(3) {
            let luma = 0.299 * (bgr[2] as f64) + 0.587 * (bgr[1] as f64) + 0.114 * (bgr[0] as f64);
            sum += luma;
            sum_squares += luma * luma;
        }
    }

    let pixels = (width * height).max(1) as f64;
    let mean = sum / pixels;
    let variance = (sum_squares / pixels - mean * mean).max(0.0);
    unsafe {
        *out_mean = mean;
        *out_stddev = variance.sqrt();
    }
    1
}

/// Checks `ThumbnailFrameSelector::looksLikeText($head)`:
/// `ltrim($head, "\xEF\xBB\xBF \t\r\n")` followed by case-insensitive prefix `#EXTM3U` or `ffconcat`.
///
/// Returns `1` when `$head` looks like a playlist/ffconcat script, `0` otherwise, or `-1` on invalid arguments.
///
/// # Safety
///
/// `head_ptr` must be valid for reads of `head_len` bytes when non-null and `head_len > 0`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_thumbnail_looks_like_text(
    head_ptr: *const u8,
    head_len: usize,
) -> i32 {
    if head_ptr.is_null() && head_len > 0 {
        return -1;
    }
    let bytes = if head_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(head_ptr, head_len) }
    };

    let mut pos = 0usize;
    while pos < bytes.len()
        && matches!(
            bytes[pos],
            0xEF | 0xBB | 0xBF | b' ' | b'\t' | b'\r' | b'\n'
        )
    {
        pos += 1;
    }
    let start = &bytes[pos..];
    let matches_playlist = (start.len() >= 7 && start[..7].eq_ignore_ascii_case(b"#extm3u"))
        || (start.len() >= 8 && start[..8].eq_ignore_ascii_case(b"ffconcat"));
    i32::from(matches_playlist)
}

/// Computes `ThumbnailFrameSelector::window` (`[$from, $spacing]` in seconds, rounded to 3 decimal places).
///
/// - `position_is_mid != 0`: writes `(0.0, 0.0)`.
/// - `has_hints`: `0` when `$hints === null`, `1` for non-Complete `$hints`, `2` when `$hints->position === SourcePosition::Complete`.
///   (When `has_hints == 1` and `total_bytes > 0 && available_bytes > 0`, `fraction = min(1.0, available_bytes / total_bytes)`.)
///
/// Returns `1` on success, or `-1` on invalid arguments.
///
/// # Safety
///
/// `out_from` and `out_spacing` must be valid for writes of one `f64` each.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_thumbnail_window(
    position_is_mid: u32,
    duration: f64,
    has_duration: u32,
    bit_rate: i64,
    has_bit_rate: u32,
    available_bytes: i64,
    total_bytes: i64,
    has_hints: u32,
    attempts: i32,
    out_from: *mut f64,
    out_spacing: *mut f64,
) -> i32 {
    if out_from.is_null() || out_spacing.is_null() || attempts <= 0 {
        return -1;
    }
    if position_is_mid != 0 {
        unsafe {
            *out_from = 0.0;
            *out_spacing = 0.0;
        }
        return 1;
    }
    if (has_duration != 0 && !duration.is_finite()) || (has_bit_rate != 0 && bit_rate == 0) {
        return -1;
    }

    let fraction: Option<f64> = if has_hints == 2 {
        Some(1.0)
    } else if has_hints != 0 && total_bytes > 0 && available_bytes > 0 {
        Some(((available_bytes as f64) / (total_bytes as f64)).min(1.0))
    } else {
        None
    };

    let playable = if has_duration != 0
        && let Some(frac) = fraction
    {
        duration * frac * if frac < 1.0 { 0.85 } else { 1.0 }
    } else if has_hints != 0 && has_bit_rate != 0 && available_bytes > 0 {
        (available_bytes as f64) * 8.0 / (bit_rate as f64) * 0.85
    } else if has_duration != 0 {
        duration
    } else {
        0.0
    };

    let from = ((playable * 0.25) * 1000.0).round() / 1000.0 + 0.0;
    let spacing = (((playable - from) / (attempts as f64)) * 1000.0).round() / 1000.0 + 0.0;
    unsafe {
        *out_from = from;
        *out_spacing = spacing;
    }
    1
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

    #[test]
    fn test_image_is_complete_tail_and_file() {
        // WebP: RIFF + 4-byte declared size + WEBP...
        let mut webp = b"RIFF".to_vec();
        webp.extend_from_slice(&24u32.to_le_bytes());
        webp.extend_from_slice(b"WEBPVP8 ");
        webp.resize(32, b'x');
        assert_eq!(
            unsafe {
                nntmux_image_is_complete_tail(PHP_IMAGETYPE_WEBP, 32, webp.as_ptr(), webp.len())
            },
            1
        );
        assert_eq!(
            unsafe {
                nntmux_image_is_complete_tail(PHP_IMAGETYPE_WEBP, 31, webp.as_ptr(), webp.len())
            },
            0
        );
        assert_eq!(
            unsafe {
                nntmux_image_is_complete_tail(PHP_IMAGETYPE_WEBP, 15, webp.as_ptr(), webp.len())
            },
            0
        );

        // PNG: IEND in last 64 bytes after rtrim("\0\r\n ")
        let mut png_tail = vec![b'a'; 100];
        png_tail.extend_from_slice(b"IEND\xae\x42\x60\x82\0\r\n ");
        assert_eq!(
            unsafe {
                nntmux_image_is_complete_tail(PHP_IMAGETYPE_PNG, 200, png_tail.as_ptr(), png_tail.len())
            },
            1
        );
        let mut png_too_far = b"IEND".to_vec();
        png_too_far.extend(std::iter::repeat_n(b'x', 61));
        assert_eq!(
            unsafe {
                nntmux_image_is_complete_tail(
                    PHP_IMAGETYPE_PNG,
                    200,
                    png_too_far.as_ptr(),
                    png_too_far.len(),
                )
            },
            0
        );

        // JPEG: \xFF\xD9 in last 1024 bytes after rtrim("\0\r\n ")
        let mut jpg_tail = vec![b'z'; 500];
        jpg_tail.extend_from_slice(b"\xFF\xD9  \n\0");
        assert_eq!(
            unsafe {
                nntmux_image_is_complete_tail(PHP_IMAGETYPE_JPEG, 600, jpg_tail.as_ptr(), jpg_tail.len())
            },
            1
        );
        let mut jpg_too_far = b"\xFF\xD9".to_vec();
        jpg_too_far.extend(std::iter::repeat_n(b'z', 1023));
        assert_eq!(
            unsafe {
                nntmux_image_is_complete_tail(
                    PHP_IMAGETYPE_JPEG,
                    2000,
                    jpg_too_far.as_ptr(),
                    jpg_too_far.len(),
                )
            },
            0
        );

        // File test via temp file
        let tmp_dir = std::env::temp_dir();
        let tmp_file = tmp_dir.join(format!("nntmux_img_test_{}.jpg", std::process::id()));
        std::fs::write(&tmp_file, &jpg_tail).unwrap();
        let path_bytes = tmp_file.to_string_lossy().into_owned().into_bytes();
        assert_eq!(
            unsafe {
                nntmux_image_is_complete_file(
                    path_bytes.as_ptr(),
                    path_bytes.len(),
                    PHP_IMAGETYPE_JPEG,
                )
            },
            1
        );
        std::fs::remove_file(&tmp_file).ok();
    }

    #[test]
    fn test_frame_luma_stats_rgb_and_bmp() {
        let rgb = [0u8, 0, 0, 255, 255, 255];
        let mut mean = 0.0_f64;
        let mut stddev = 0.0_f64;
        assert_eq!(
            unsafe { nntmux_frame_luma_stats_rgb(rgb.as_ptr(), 2, &mut mean, &mut stddev) },
            1
        );
        assert!((mean - 127.5).abs() < 1e-9);
        assert!((stddev - 127.5).abs() < 1e-9);

        // Construct a 2x1 24-bit bottom-up BMP with pixels (0,0,0) and (255,255,255)
        let mut bmp = vec![0u8; 54 + 8];
        bmp[0] = b'B';
        bmp[1] = b'M';
        bmp[2..6].copy_from_slice(&62u32.to_le_bytes());
        bmp[10..14].copy_from_slice(&54u32.to_le_bytes());
        bmp[14..18].copy_from_slice(&40u32.to_le_bytes());
        bmp[18..22].copy_from_slice(&2i32.to_le_bytes());
        bmp[22..26].copy_from_slice(&1i32.to_le_bytes());
        bmp[26..28].copy_from_slice(&1u16.to_le_bytes());
        bmp[28..30].copy_from_slice(&24u16.to_le_bytes());
        bmp[57] = 255;
        bmp[58] = 255;
        bmp[59] = 255;
        let mut bmp_mean = 0.0_f64;
        let mut bmp_std = 0.0_f64;
        assert_eq!(
            unsafe {
                nntmux_frame_luma_stats_bmp(bmp.as_ptr(), bmp.len(), &mut bmp_mean, &mut bmp_std)
            },
            1
        );
        assert_eq!(mean.to_bits(), bmp_mean.to_bits());
        assert_eq!(stddev.to_bits(), bmp_std.to_bits());
    }

    #[test]
    fn test_thumbnail_looks_like_text_and_window() {
        let m3u = b"\xEF\xBB\xBF \t\r\n#extM3u\nfile:///etc/passwd";
        assert_eq!(
            unsafe { nntmux_thumbnail_looks_like_text(m3u.as_ptr(), m3u.len()) },
            1
        );
        let concat = b"  \nFFCONCAT version 1.0";
        assert_eq!(
            unsafe { nntmux_thumbnail_looks_like_text(concat.as_ptr(), concat.len()) },
            1
        );
        let mkv = b"\x1A\x45\xDF\xA3matroska";
        assert_eq!(
            unsafe { nntmux_thumbnail_looks_like_text(mkv.as_ptr(), mkv.len()) },
            0
        );

        let mut from = -1.0_f64;
        let mut spacing = -1.0_f64;
        // Complete: duration=100, bit_rate=8000, has_hints=2, attempts=4 -> [25.0, 18.75]
        assert_eq!(
            unsafe {
                nntmux_thumbnail_window(0, 100.0, 1, 8000, 1, 0, 0, 2, 4, &mut from, &mut spacing)
            },
            1
        );
        assert_eq!((from, spacing), (25.0, 18.75));

        // Partial head(10000, 1000): [2.125, 1.594]
        assert_eq!(
            unsafe {
                nntmux_thumbnail_window(
                    0, 100.0, 1, 8000, 1, 1000, 10000, 1, 4, &mut from, &mut spacing,
                )
            },
            1
        );
        assert_eq!((from, spacing), (2.125, 1.594));

        // Partial head(null, 1000): [0.213, 0.159]
        assert_eq!(
            unsafe {
                nntmux_thumbnail_window(
                    0, 100.0, 1, 8000, 1, 1000, 0, 1, 4, &mut from, &mut spacing,
                )
            },
            1
        );
        assert_eq!((from, spacing), (0.213, 0.159));

        // Mid: [0.0, 0.0]
        assert_eq!(
            unsafe {
                nntmux_thumbnail_window(
                    1, 100.0, 1, 8000, 1, 1000, 10000, 1, 4, &mut from, &mut spacing,
                )
            },
            1
        );
        assert_eq!((from, spacing), (0.0, 0.0));
    }
}


// IMDb search: -1 declines to PHP, <= -4 requests a larger caller-owned buffer.
// The strict subset deliberately leaves Unicode escapes, coercions and unusual HTML
// to json_decode / PCRE / DOM. Search rows retain the PHP id/title/year contract;
// qid and i.imageUrl are validated as JSON but do not filter or extend that contract.
fn imdb_trim(s: &[u8]) -> &[u8] {
    let ws = |b: &u8| matches!(*b, b' ' | b'\t' | b'\n' | b'\r' | 0 | 11);
    let start = s.iter().position(|b| !ws(b)).unwrap_or(s.len());
    let end = s.iter().rposition(|b| !ws(b)).map_or(start, |i| i + 1);
    &s[start..end]
}

fn imdb_year(s: &[u8]) -> &[u8] {
    s.windows(4).find(|w| (w.starts_with(b"19") || w.starts_with(b"20")) && w[2..].iter().all(u8::is_ascii_digit)).unwrap_or(b"")
}

/// CPU candidates share one ABI to keep scratch allocation and fallback identical.
/// Modes: year, image URL, slug, loose pattern, trailing year, and/&, er/re.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_imdb_search_text(
    input: *const u8, input_len: usize, mode: u32, out_buf: *mut u8, out_cap: usize,
) -> isize {
    if input.is_null() { return -1; }
    let s = unsafe { slice::from_raw_parts(input, input_len) };
    let mut out = OutCursor::new(out_buf, out_cap);
    match mode {
        0 => out.push_slice(imdb_year(s)),
        1 => {
            // PCRE dot excludes LF, and the final dot on each line wins greedily.
            let s = imdb_trim(s);
            let mut pos = 0;
            while pos < s.len() {
                if s[pos..].starts_with(b"._V1_") {
                    let end = s[pos + 5..].iter().position(|&b| b == b'\n').map_or(s.len(), |i| pos + 5 + i);
                    if let Some(dot) = s[pos + 5..end].iter().rposition(|&b| b == b'.') {
                        out.push_byte(b'.');
                        pos += 5 + dot + 1;
                        continue;
                    }
                }
                out.push_byte(s[pos]);
                pos += 1;
            }
        }
        2 => {
            if !s.is_ascii() { return -1; }
            let mut started = false;
            let mut pending_space = false;
            for &b in s {
                if b.is_ascii_alphanumeric() {
                    if started && pending_space {
                        out.push_byte(b'_');
                    }
                    out.push_byte(b.to_ascii_lowercase());
                    started = true;
                    pending_space = false;
                } else if b == b' ' && started {
                    pending_space = true;
                }
            }
        }
        3 => {
            out.push_byte(b'%');
            for &b in s {
                match b {
                    b' ' => out.push_byte(b'%'),
                    b'\'' | b'!' => (),
                    _ => out.push_byte(b),
                }
            }
            out.push_byte(b'%');
        }
        4 => {
            if s.contains(&b'\n') { return -1; }
            let mut result = s;
            if s.len() >= 7 && s.last() == Some(&b')') {
                let open = s.len() - 6;
                if s[open] == b'(' && s[open + 1..open + 5].iter().all(u8::is_ascii_digit) && open > 0 {
                    // Lazy (.+?) retains at least one byte; PCRE's following
                    // \s* consumes formfeed too, unlike PHP trim().
                    let mut capture_end = open;
                    while capture_end > 1 && matches!(s[capture_end - 1], b' ' | b'\t' | b'\r' | 11 | 12) {
                        capture_end -= 1;
                    }
                    result = imdb_trim(&s[..capture_end]);
                }
            }
            out.push_slice(result);
        }
        5 | 6 => {
            let (from, to): (&[u8], &[u8]) = if mode == 5 { (b" and ", b" & ") } else { (b"er", b"re") };
            let mut pos = 0;
            while pos < s.len() {
                if s.get(pos..pos + from.len()).is_some_and(|w| w.eq_ignore_ascii_case(from)) {
                    out.push_slice(to);
                    pos += from.len();
                } else {
                    out.push_byte(s[pos]);
                    pos += 1;
                }
            }
        }
        _ => return -1,
    }
    out.finish()
}

struct ImdbSearchJson<'a> { s: &'a [u8], pos: usize }

impl<'a> ImdbSearchJson<'a> {
    fn ws(&mut self) {
        while self.s.get(self.pos).is_some_and(|b| matches!(b, b' ' | b'\t' | b'\r' | b'\n')) { self.pos += 1; }
    }

    fn eat(&mut self, b: u8) -> bool {
        self.ws();
        if self.s.get(self.pos) == Some(&b) { self.pos += 1; true } else { false }
    }

    fn string(&mut self) -> Option<std::borrow::Cow<'a, [u8]>> {
        if !self.eat(b'"') { return None; }
        let start = self.pos;
        let mut decoded: Option<Vec<u8>> = None;
        loop {
            let b = *self.s.get(self.pos)?;
            self.pos += 1;
            match b {
                b'"' => return Some(decoded.map_or_else(|| std::borrow::Cow::Borrowed(&self.s[start..self.pos - 1]), std::borrow::Cow::Owned)),
                0..=31 => return None,
                b'\\' => {
                    let out = decoded.get_or_insert_with(|| self.s[start..self.pos - 1].to_vec());
                    let b = *self.s.get(self.pos)?;
                    self.pos += 1;
                    out.push(match b {
                        b'"' | b'\\' | b'/' => b,
                        b'b' => 8, b'f' => 12, b'n' => 10, b'r' => 13, b't' => 9,
                        _ => return None, // Unicode escapes (including NUL/surrogates) need PHP.
                    });
                }
                _ => if let Some(out) = &mut decoded { out.push(b); },
            }
        }
    }

    fn number(&mut self) -> Option<&'a [u8]> {
        self.ws();
        let start = self.pos;
        if self.s.get(self.pos) == Some(&b'-') { self.pos += 1; }
        match self.s.get(self.pos)? {
            b'0' => self.pos += 1,
            b'1'..=b'9' => while self.s.get(self.pos).is_some_and(u8::is_ascii_digit) { self.pos += 1; },
            _ => return None,
        }
        if self.s.get(self.pos) == Some(&b'.') {
            self.pos += 1;
            let from = self.pos;
            while self.s.get(self.pos).is_some_and(u8::is_ascii_digit) { self.pos += 1; }
            if self.pos == from { return None; }
        }
        if self.s.get(self.pos).is_some_and(|b| matches!(b, b'e' | b'E')) {
            self.pos += 1;
            if self.s.get(self.pos).is_some_and(|b| matches!(b, b'+' | b'-')) { self.pos += 1; }
            let from = self.pos;
            while self.s.get(self.pos).is_some_and(u8::is_ascii_digit) { self.pos += 1; }
            if self.pos == from { return None; }
        }
        Some(&self.s[start..self.pos])
    }

    fn literal(&mut self, value: &[u8]) -> Option<()> {
        self.ws();
        if !self.s[self.pos..].starts_with(value) { return None; }
        self.pos += value.len();
        Some(())
    }

    fn skip(&mut self, depth: usize) -> Option<()> {
        if depth > 128 { return None; }
        self.ws();
        match self.s.get(self.pos)? {
            b'"' => { self.string()?; }
            b'{' => {
                self.pos += 1;
                if self.eat(b'}') { return Some(()); }
                loop {
                    self.string()?;
                    if !self.eat(b':') { return None; }
                    self.skip(depth + 1)?;
                    if self.eat(b'}') { break; }
                    if !self.eat(b',') { return None; }
                }
            }
            b'[' => {
                self.pos += 1;
                if self.eat(b']') { return Some(()); }
                loop {
                    self.skip(depth + 1)?;
                    if self.eat(b']') { break; }
                    if !self.eat(b',') { return None; }
                }
            }
            b't' => self.literal(b"true")?, b'f' => self.literal(b"false")?, b'n' => self.literal(b"null")?,
            _ => { self.number()?; }
        }
        Some(())
    }

    fn row(&mut self, out: &mut OutCursor, count: &mut usize) -> Option<()> {
        if !self.eat(b'{') { return None; }
        let (mut id, mut title, mut year) = (None, None, None);
        let mut seen = 0;
        if !self.eat(b'}') {
            loop {
                let key = self.string()?;
                if !self.eat(b':') { return None; }
                let bit = match key.as_ref() { b"id" => 1, b"l" => 2, b"y" => 4, _ => 0 };
                if bit != 0 {
                    if seen & bit != 0 { return None; }
                    seen |= bit;
                    self.ws();
                    let value = match self.s.get(self.pos)? {
                        b'"' => self.string()?,
                        b'n' => { self.literal(b"null")?; std::borrow::Cow::Borrowed(&b""[..]) }
                        b'0'..=b'9' if bit == 4 => {
                            let n = self.number()?;
                            if n.len() > 15 || !n.iter().all(u8::is_ascii_digit) { return None; }
                            std::borrow::Cow::Borrowed(n)
                        }
                        _ => return None,
                    };
                    match bit { 1 => id = Some(value), 2 => title = Some(value), _ => year = Some(value) }
                } else { self.skip(2)?; }
                if self.eat(b'}') { break; }
                if !self.eat(b',') { return None; }
            }
        }
        if let (Some(id), Some(title)) = (id, title) {
            let title = imdb_trim(&title);
            if id.starts_with(b"tt") && !title.is_empty() && *count < 25 {
                for field in [&id[2..], title, imdb_trim(year.as_deref().unwrap_or(b""))] {
                    out.push_slice(field);
                    out.push_byte(0);
                }
                *count += 1;
            }
        }
        Some(())
    }

    fn suggestions(&mut self, out: &mut OutCursor) -> Option<()> {
        if !self.eat(b'{') { return None; }
        let mut seen = false;
        let mut count = 0;
        if !self.eat(b'}') {
            loop {
                let key = self.string()?;
                if !self.eat(b':') { return None; }
                if key.as_ref() == b"d" {
                    if seen || !self.eat(b'[') { return None; }
                    seen = true;
                    if !self.eat(b']') {
                        loop {
                            self.row(out, &mut count)?;
                            if self.eat(b']') { break; }
                            if !self.eat(b',') { return None; }
                        }
                    }
                } else { self.skip(1)?; }
                if self.eat(b'}') { break; }
                if !self.eat(b',') { return None; }
            }
        }
        self.ws();
        (self.pos == self.s.len()).then_some(())
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_imdb_suggestion_rows(
    input: *const u8, input_len: usize, out_buf: *mut u8, out_cap: usize,
) -> isize {
    if input.is_null() { return -1; }
    let s = unsafe { slice::from_raw_parts(input, input_len) };
    if std::str::from_utf8(s).is_err() { return -1; }
    let mut out = OutCursor::new(out_buf, out_cap);
    if (ImdbSearchJson { s, pos: 0 }).suggestions(&mut out).is_none() { return -1; }
    out.finish()
}

// A deliberately conservative strip_tags subset, including a tag truncated by
// PHP's 200-byte context window. Nested angle brackets defer to PHP.
fn imdb_search_plain_context(s: &[u8]) -> Option<Vec<u8>> {
    let mut out = Vec::with_capacity(s.len());
    let mut pos = 0;
    while pos < s.len() {
        if s[pos] == b'<' {
            let mut name = pos + 1;
            if s.get(name) == Some(&b'/') { name += 1; }
            if !s.get(name)?.is_ascii_alphabetic() { return None; }
            pos = name;
            let mut quote = 0;
            while pos < s.len() {
                let b = s[pos];
                if b == b'<' { return None; }
                if quote != 0 {
                    if b == quote { quote = 0; }
                } else if b == b'>' { break; }
                else if b == b'\'' || b == b'"' { quote = b; }
                pos += 1;
            }
            pos = (pos + 1).min(s.len());
        } else {
            out.push(s[pos]);
            pos += 1;
        }
    }
    Some(out)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_imdb_search_html_rows(
    input: *const u8, input_len: usize, out_buf: *mut u8, out_cap: usize,
) -> isize {
    if input.is_null() { return -1; }
    let s = unsafe { slice::from_raw_parts(input, input_len) };
    if s.contains(&0) || std::str::from_utf8(s).is_err() { return -1; }
    let mut out = OutCursor::new(out_buf, out_cap);
    let mut pos = 0;
    let mut count = 0;
    while pos + 2 <= s.len() && count < 25 {
        if !s[pos..pos + 2].eq_ignore_ascii_case(b"<a") { pos += 1; continue; }
        // PCRE also accepts tag-name prefixes; let it handle those and malformed attributes.
        if !s.get(pos + 2).is_some_and(u8::is_ascii_whitespace) { return -1; }
        let Some(end_rel) = s[pos..].iter().position(|&b| b == b'>') else { return -1; };
        let end = pos + end_rel;
        let attrs = &s[pos + 2..end];
        let hits: Vec<_> = attrs.windows(5).enumerate().filter(|(_, w)| w.eq_ignore_ascii_case(b"href=")).map(|(i, _)| i).collect();
        if hits.is_empty() { pos = end + 1; continue; }
        if hits.len() != 1 || attrs.contains(&b'<') { return -1; }
        let h = hits[0];
        if h == 0 || !attrs[h - 1].is_ascii_whitespace() { return -1; }
        let Some(&quote) = attrs.get(h + 5) else { return -1; };
        if quote != b'\'' && quote != b'"' { return -1; }
        let from = h + 6;
        let Some(close) = attrs[from..].iter().position(|&b| b == quote) else { return -1; };
        let href = &attrs[from..from + close];
        if href.contains(&b'&') { return -1; }
        if !href.get(..9).is_some_and(|w| w.eq_ignore_ascii_case(b"/title/tt")) { pos = end + 1; continue; }
        let id_end = href.len().saturating_sub(1);
        if !(15..=18).contains(&href.len()) || href.last() != Some(&b'/') || !href[9..id_end].iter().all(u8::is_ascii_digit) { return -1; }
        let title_start = end + 1;
        let Some(close) = s[title_start..].windows(4).position(|w| w.eq_ignore_ascii_case(b"</a>")) else { return -1; };
        let title_end = title_start + close;
        let title = &s[title_start..title_end];
        if title.contains(&b'<') || title.contains(&b'&') { return -1; }
        let title = imdb_trim(title);
        pos = title_end + 4;
        if title.is_empty() { continue; }
        let Some(context) = imdb_search_plain_context(&s[pos..(pos + 200).min(s.len())]) else { return -1; };
        for field in [&href[9..id_end], title, imdb_year(&context)] {
            out.push_slice(field);
            out.push_byte(0);
        }
        count += 1;
    }
    if count == 0 { return -1; } // The DOM fallback can still find looser hrefs.
    out.finish()
}


#[cfg(test)]
mod imdb_search_tests {
    use super::*;

    #[test]
    fn search_text_byte_semantics_and_size_contract() {
        let mut buf = [0u8; 128];
        for (mode, input, expected) in [
            (0, &b"x19001 2020"[..], &b"1900"[..]),
            (1, b" x._V1_a.b.jpg?x.y ", b"x.y"),
            (1, b"x._V1_a\nb.jpg", b"x._V1_a\nb.jpg"),
            (2, b" Tom & Jerry (2020) ", b"tom_jerry_2020"),
            (2, b"!!! ---", b""),
            (2, b"(500) Days of Summer", b"500_days_of_summer"),
            (3, b" Peter's  Show!\tX ", b"%%Peters%%Show\tX%%"),
            (4, b"The Show (2020)", b"The Show"),
            (4, b"(2020)", b"(2020)"),
            (4, b"A\x0c(2020)", b"A"),
            (4, b" \x0c(2020)", b""),
            (4, b"\x0c(2020)", b"\x0c"),
            (5, b"a AND b and c", b"a & b & c"),
            (6, b"Peter Theater", b"Petre Theatre"),
        ] {
            let n = unsafe { nntmux_imdb_search_text(input.as_ptr(), input.len(), mode, buf.as_mut_ptr(), buf.len()) };
            assert_eq!(&buf[..n as usize], expected);
            let n = unsafe { nntmux_imdb_search_text(input.as_ptr(), input.len(), mode, buf.as_mut_ptr(), 0) };
            if expected.is_empty() { assert_eq!(n, 0); } else { assert!(n <= -4); }
        }
        assert_eq!(unsafe { nntmux_imdb_search_text("É".as_ptr(), 2, 2, buf.as_mut_ptr(), buf.len()) }, -1);
        assert_eq!(unsafe { nntmux_imdb_search_text(std::ptr::null(), 0, 0, buf.as_mut_ptr(), buf.len()) }, -1);
    }

    #[test]
    fn suggestion_subset_validates_the_whole_document() {
        let mut buf = [0u8; 1024];
        let json = br#"{"d":[{"id":"tt01234567","l":" Name ","y":2024,"qid":"tvSeries","i":{"imageUrl":"https:\/\/x\/a.jpg"}}],"q":"Name"}"#;
        let n = unsafe { nntmux_imdb_suggestion_rows(json.as_ptr(), json.len(), buf.as_mut_ptr(), buf.len()) };
        assert_eq!(&buf[..n as usize], b"01234567\0Name\x002024\0");
        for bad in [
            &br#"{"d":[{"id":"tt1","l":"\u00e9"}]}"#[..],
            br#"{"d":[{"id":"tt1","l":"A"}],"x":01}"#,
            br#"{"d":[{"id":"tt1","l":"A"}],"x":[true,]}"#,
            br#"{"d":[{"id":"tt1","l":"A","y":1e3}]}"#,
            br#"{"d":[],"d":[]}"#,
            br#"{"d":[{"id":"tt1","l":"A"}]} garbage"#,
        ] {
            assert_eq!(unsafe { nntmux_imdb_suggestion_rows(bad.as_ptr(), bad.len(), buf.as_mut_ptr(), buf.len()) }, -1);
        }
    }

    #[test]
    fn html_subset_preserves_order_and_defers_complex_titles() {
        let mut buf = [0u8; 1024];
        let html = br#"<a href="/title/tt01234567/"> Name </a><span class="year">2024</span>"#;
        let n = unsafe { nntmux_imdb_search_html_rows(html.as_ptr(), html.len(), buf.as_mut_ptr(), buf.len()) };
        assert_eq!(&buf[..n as usize], b"01234567\0Name\x002024\0");
        for html in [
            &br#"<a href="/title/tt01234567/?ref=x"> Name </a>2024"#[..],
            br#"<a href="/title/tt01234567/">Tom &amp; Jerry</a>2024"#,
            br#"<a href="/title/tt01234567/"><b>Title</b></a>2024"#,
        ] {
            assert_eq!(unsafe { nntmux_imdb_search_html_rows(html.as_ptr(), html.len(), buf.as_mut_ptr(), buf.len()) }, -1);
        }
    }
}

// ============================================================================
// Wave 14 Slice 5: ReleaseImageService, ReleaseDuplicateFinder,
// ReleaseManagementService, CategorizationResult, and ApiReleaseRowCache
// ============================================================================

/// Validates a release image basename matching `ReleaseImageService::isValidBasename`
/// (`/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D`), rejecting empty strings, `.`, `..`, `/`, `\`, and NUL bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_image_is_valid_basename(
    input: *const u8,
    input_len: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if input.is_null() {
            return if input_len == 0 { 0 } else { -1 };
        }
        if input_len == 0 {
            return 0;
        }
        let bytes = unsafe { slice::from_raw_parts(input, input_len) };
        if bytes[0].is_ascii_alphanumeric()
            && bytes[1..]
                .iter()
                .all(|&b| b.is_ascii_alphanumeric() || b == b'_' || b == b'-')
        {
            1
        } else {
            0
        }
    }))
    .unwrap_or(-1)
}

#[inline]
fn write_invalid_url(mut out: OutCursor) -> isize {
    out.push_slice(b"[invalid-url]");
    out.finish()
}

#[inline]
fn push_url_sanitized_slice(out: &mut OutCursor, bytes: &[u8]) {
    for &b in bytes {
        out.push_byte(if b < 0x20 || b == 0x7f { b'_' } else { b });
    }
}

#[inline]
fn push_u16_decimal(out: &mut OutCursor, mut n: u16) {
    if n == 0 {
        out.push_byte(b'0');
        return;
    }
    let mut buf = [0u8; 5];
    let mut pos = 5;
    while n > 0 {
        pos -= 1;
        buf[pos] = b'0' + (n % 10) as u8;
        n /= 10;
    }
    out.push_slice(&buf[pos..]);
}

/// Redacts a remote image URL matching `ReleaseImageService::redactedUrl`.
/// Returns bytes written (`> 0`), `-(required_len)` if `out_cap` is too small, or `-1` when
/// declined to PHP `parse_url` (e.g. non-standard port suffix or malformed IPv6 bracket).
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_image_redact_url(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if (input.is_null() && input_len > 0) || (out_buf.is_null() && out_cap > 0) {
            return -1;
        }
        let bytes: &[u8] = if input.is_null() || input_len == 0 {
            &[]
        } else {
            unsafe { slice::from_raw_parts(input, input_len) }
        };
        let mut out = OutCursor::new(out_buf, if out_buf.is_null() { 0 } else { out_cap });

        let Some(colon_pos) = bytes
            .iter()
            .position(|&b| !(b.is_ascii_alphanumeric() || matches!(b, b'+' | b'-' | b'.')))
        else {
            return write_invalid_url(out);
        };
        if colon_pos == 0 || bytes.get(colon_pos..colon_pos + 3) != Some(b"://") {
            return write_invalid_url(out);
        }

        let after_scheme = colon_pos + 3;
        let auth_end = bytes[after_scheme..]
            .iter()
            .position(|&b| matches!(b, b'/' | b'?' | b'#'))
            .map_or(bytes.len(), |i| after_scheme + i);
        let auth = &bytes[after_scheme..auth_end];
        if auth.is_empty() {
            return write_invalid_url(out);
        }

        let host_port = match auth.iter().rposition(|&b| b == b'@') {
            Some(at) => &auth[at + 1..],
            None => auth,
        };
        if host_port.is_empty() {
            return write_invalid_url(out);
        }

        let (host, port_opt): (&[u8], Option<&[u8]>) = if host_port[0] == b'[' {
            let Some(rel_close) = host_port[1..].iter().position(|&b| b == b']') else {
                return -1;
            };
            let close = 1 + rel_close;
            let rest = &host_port[close + 1..];
            if rest.is_empty() {
                (&host_port[..=close], None)
            } else if rest[0] == b':' {
                (&host_port[..=close], Some(&rest[1..]))
            } else {
                return -1;
            }
        } else if let Some(c) = host_port.iter().rposition(|&b| b == b':') {
            (&host_port[..c], Some(&host_port[c + 1..]))
        } else {
            (host_port, None)
        };

        if host.is_empty() {
            return write_invalid_url(out);
        }

        let parsed_port: Option<u16> = match port_opt {
            None | Some(b"") => None,
            Some(p) => {
                if p.len() > 5 {
                    return write_invalid_url(out);
                }
                if !p.iter().all(u8::is_ascii_digit) {
                    if !p[0].is_ascii_digit() && p[0] != b'+' && !p[0].is_ascii_whitespace() {
                        return write_invalid_url(out);
                    }
                    return -1;
                }
                let mut port_val = 0u32;
                for &d in p {
                    port_val = port_val * 10 + u32::from(d - b'0');
                }
                if port_val > 65535 {
                    return write_invalid_url(out);
                }
                Some(port_val as u16)
            }
        };

        let path: &[u8] = if auth_end < bytes.len() && bytes[auth_end] == b'/' {
            let path_end = bytes[auth_end..]
                .iter()
                .position(|&b| matches!(b, b'?' | b'#'))
                .map_or(bytes.len(), |i| auth_end + i);
            &bytes[auth_end..path_end]
        } else {
            b"/"
        };

        for &b in &bytes[..colon_pos] {
            out.push_byte(b.to_ascii_lowercase());
        }
        out.push_slice(b"://");
        push_url_sanitized_slice(&mut out, host);
        if let Some(port) = parsed_port {
            out.push_byte(b':');
            push_u16_decimal(&mut out, port);
        }
        push_url_sanitized_slice(&mut out, path);
        out.finish()
    }))
    .unwrap_or(-1)
}

/// Computes proportional downscaled dimensions (`(new_w << 32) | new_h`, or `0` if no resize is needed)
/// and optionally classifies the output format (`1` = jpg/jpeg, `2` = png, `0` = webp/default)
/// for `ReleaseImageService`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_image_scale_dimensions(
    width: i64,
    height: i64,
    max_width: i64,
    max_height: i64,
    format: *const u8,
    format_len: usize,
    out_format: *mut i32,
) -> i64 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if format.is_null() && format_len > 0 {
            return -1;
        }
        if !out_format.is_null() {
            let fmt_bytes: &[u8] = if format.is_null() || format_len == 0 {
                &[]
            } else {
                unsafe { slice::from_raw_parts(format, format_len) }
            };
            let code = if fmt_bytes.eq_ignore_ascii_case(b"jpg")
                || fmt_bytes.eq_ignore_ascii_case(b"jpeg")
            {
                1
            } else if fmt_bytes.eq_ignore_ascii_case(b"png") {
                2
            } else {
                0
            };
            unsafe {
                *out_format = code;
            }
        }

        if width <= 0 || height <= 0 || max_width <= 0 || max_height <= 0 {
            return 0;
        }

        let w = width as f64;
        let h = height as f64;
        let mw = max_width as f64;
        let mh = max_height as f64;
        let ratio = (mh / h).min(mw / w).min(1.0);
        let new_w = (ratio * w).floor() as i64;
        let new_h = (ratio * h).floor() as i64;

        if ratio < 1.0
            && new_w > 10
            && new_h > 10
            && new_w <= i32::MAX as i64
            && new_h <= i32::MAX as i64
        {
            (((new_w as u64) << 32) | (new_h as u32 as u64)) as i64
        } else {
            0
        }
    }))
    .unwrap_or(-1)
}

/// Resolves the duplicate match reason code for `ReleaseDuplicateFinder::resolveReason`:
/// - `1` = `predb_id_match`
/// - `2` = `searchname_match`
/// - `0` = `name_match_fallback`
/// - `-1` = invalid pointer/length arguments
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_duplicate_resolve_reason(
    dup_predb_id: i64,
    predb_id: i64,
    dup_search_name: *const u8,
    dup_search_name_len: usize,
    search_name: *const u8,
    search_name_len: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if (dup_search_name.is_null() && dup_search_name_len > 0)
            || (search_name.is_null() && search_name_len > 0)
        {
            return -1;
        }
        if predb_id > 0 && dup_predb_id == predb_id {
            return 1;
        }
        if search_name_len > 0 {
            let dup_bytes: &[u8] = if dup_search_name.is_null() || dup_search_name_len == 0 {
                &[]
            } else {
                unsafe { slice::from_raw_parts(dup_search_name, dup_search_name_len) }
            };
            let search_bytes = unsafe { slice::from_raw_parts(search_name, search_name_len) };
            if dup_bytes == search_bytes {
                return 2;
            }
        }
        0
    }))
    .unwrap_or(-1)
}

/// Computes `[low_size, high_size]` tolerance bounds for `ReleaseDuplicateFinder`:
/// `low = floor(filesize * (1.0 - tolerance))`, `high = ceil(filesize * (1.0 + tolerance))`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_duplicate_size_bounds(
    filesize: i64,
    tolerance: f64,
    out_low: *mut i64,
    out_high: *mut i64,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_low.is_null() || out_high.is_null() || !tolerance.is_finite() {
            return -1;
        }
        let f = filesize as f64;
        let low_f = (f * (1.0 - tolerance)).floor();
        let high_f = (f * (1.0 + tolerance)).ceil();
        if !low_f.is_finite()
            || !high_f.is_finite()
            || low_f < (i64::MIN as f64)
            || low_f >= (i64::MAX as f64)
            || high_f < (i64::MIN as f64)
            || high_f >= (i64::MAX as f64)
        {
            return -1;
        }
        unsafe {
            *out_low = low_f as i64;
            *out_high = high_f as i64;
        }
        0
    }))
    .unwrap_or(-1)
}

/// Converts a `DD/MM/YYYY` (or 3-part `/`-delimited) export filter date into
/// `part[2]-part[1]-part[0]00:00:00` (`is_end_of_day == 0`) or `23:59:59` (`is_end_of_day != 0`),
/// matching `ReleaseManagementService::getForExport`. Returns `0` when the input does not have
/// exactly 3 `/`-separated parts.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_release_management_export_date(
    input: *const u8,
    input_len: usize,
    is_end_of_day: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if (input.is_null() && input_len > 0) || (out_buf.is_null() && out_cap > 0) {
            return -1;
        }
        if input.is_null() || input_len == 0 {
            return 0;
        }
        let bytes = unsafe { slice::from_raw_parts(input, input_len) };
        let Some(s1) = bytes.iter().position(|&b| b == b'/') else {
            return 0;
        };
        let Some(s2_rel) = bytes[s1 + 1..].iter().position(|&b| b == b'/') else {
            return 0;
        };
        let s2 = s1 + 1 + s2_rel;
        if bytes[s2 + 1..].contains(&b'/') {
            return 0;
        }

        let mut out = OutCursor::new(out_buf, if out_buf.is_null() { 0 } else { out_cap });
        out.push_slice(&bytes[s2 + 1..]);
        out.push_byte(b'-');
        out.push_slice(&bytes[s1 + 1..s2]);
        out.push_byte(b'-');
        out.push_slice(&bytes[..s1]);
        out.push_slice(if is_end_of_day != 0 {
            b"23:59:59"
        } else {
            b"00:00:00"
        });
        out.finish()
    }))
    .unwrap_or(-1)
}

const CAT_OTHER_MISC: i64 = 10;
const CAT_OTHER_HASHED: i64 = 20;

#[inline]
fn is_protected_misc_inner(category_id: i64, matched_by: &[u8]) -> bool {
    if category_id == CAT_OTHER_HASHED {
        return true;
    }
    if category_id != CAT_OTHER_MISC {
        return false;
    }
    matched_by.starts_with(b"hash_")
        || matched_by.starts_with(b"obfuscated_")
        || matched_by.starts_with(b"gibberish_")
        || matched_by == b"group_only_low_signal"
}

/// Matches `CategorizationResult::isSuccessful`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_categorization_result_is_successful(
    category_id: i64,
    confidence: f64,
    matched_by: *const u8,
    matched_by_len: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if matched_by.is_null() && matched_by_len > 0 {
            return -1;
        }
        if confidence <= 0.0 {
            return 0;
        }
        let mb: &[u8] = if matched_by.is_null() || matched_by_len == 0 {
            &[]
        } else {
            unsafe { slice::from_raw_parts(matched_by, matched_by_len) }
        };
        if category_id == CAT_OTHER_MISC && mb == b"no_match" {
            return 0;
        }
        1
    }))
    .unwrap_or(-1)
}

/// Matches `CategorizationResult::isProtectedMiscResult`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_categorization_result_is_protected_misc(
    category_id: i64,
    matched_by: *const u8,
    matched_by_len: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if matched_by.is_null() && matched_by_len > 0 {
            return -1;
        }
        let mb: &[u8] = if matched_by.is_null() || matched_by_len == 0 {
            &[]
        } else {
            unsafe { slice::from_raw_parts(matched_by, matched_by_len) }
        };
        i32::from(is_protected_misc_inner(category_id, mb))
    }))
    .unwrap_or(-1)
}

/// Matches `CategorizationResult::shouldOverride`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_categorization_result_should_override(
    self_category_id: i64,
    self_confidence: f64,
    self_matched_by: *const u8,
    self_matched_by_len: usize,
    other_category_id: i64,
    other_confidence: f64,
    other_matched_by: *const u8,
    other_matched_by_len: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if (self_matched_by.is_null() && self_matched_by_len > 0)
            || (other_matched_by.is_null() && other_matched_by_len > 0)
        {
            return -1;
        }
        let other_mb: &[u8] = if other_matched_by.is_null() || other_matched_by_len == 0 {
            &[]
        } else {
            unsafe { slice::from_raw_parts(other_matched_by, other_matched_by_len) }
        };

        if self_category_id != CAT_OTHER_MISC
            && self_category_id != CAT_OTHER_HASHED
            && is_protected_misc_inner(other_category_id, other_mb)
            && self_confidence < 0.6
        {
            return 0;
        }
        if self_confidence > other_confidence {
            return 1;
        }
        if self_confidence == other_confidence {
            return i32::from(
                self_category_id != CAT_OTHER_MISC && other_category_id == CAT_OTHER_MISC,
            );
        }
        0
    }))
    .unwrap_or(-1)
}

#[inline]
fn might_be_php_numeric(s: &[u8]) -> bool {
    let mut i = 0;
    while i < s.len() && matches!(s[i], b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c) {
        i += 1;
    }
    if i < s.len() && matches!(s[i], b'+' | b'-') {
        i += 1;
    }
    if i < s.len() && s[i] == b'.' {
        i += 1;
    }
    i < s.len() && s[i].is_ascii_digit()
}

enum ApiCacheJsonVal {
    Str(Vec<u8>),
    Int(i64),
    Bool(bool),
    Null,
    EmptyArr,
    IntArr(Vec<i64>),
    StrArr(Vec<Vec<u8>>),
    Obj(Vec<(Vec<u8>, ApiCacheJsonVal)>),
}

struct ApiCacheJsonParser<'a> {
    s: &'a [u8],
    pos: usize,
}

impl<'a> ApiCacheJsonParser<'a> {
    #[inline]
    fn ws(&mut self) {
        while self
            .s
            .get(self.pos)
            .is_some_and(|b| matches!(b, b' ' | b'\t' | b'\r' | b'\n'))
        {
            self.pos += 1;
        }
    }

    #[inline]
    fn eat(&mut self, b: u8) -> bool {
        self.ws();
        if self.s.get(self.pos) == Some(&b) {
            self.pos += 1;
            true
        } else {
            false
        }
    }

    fn parse_string(&mut self) -> Option<Vec<u8>> {
        if !self.eat(b'"') {
            return None;
        }
        let mut out = Vec::new();
        loop {
            let b = *self.s.get(self.pos)?;
            self.pos += 1;
            match b {
                b'"' => return Some(out),
                0..=0x1f => return None,
                b'\\' => {
                    let esc = *self.s.get(self.pos)?;
                    self.pos += 1;
                    match esc {
                        b'"' | b'\\' | b'/' => out.push(esc),
                        b'b' => out.push(0x08),
                        b'f' => out.push(0x0c),
                        b'n' => out.push(0x0a),
                        b'r' => out.push(0x0d),
                        b't' => out.push(0x09),
                        b'u' => {
                            let hex = self.s.get(self.pos..self.pos + 4)?;
                            self.pos += 4;
                            let mut code = 0u16;
                            for &h in hex {
                                let d = match h {
                                    b'0'..=b'9' => h - b'0',
                                    b'a'..=b'f' => h - b'a' + 10,
                                    b'A'..=b'F' => h - b'A' + 10,
                                    _ => return None,
                                };
                                code = (code << 4) | u16::from(d);
                            }
                            if code > 0x7f {
                                return None;
                            }
                            out.push(code as u8);
                        }
                        _ => return None,
                    }
                }
                _ => out.push(b),
            }
        }
    }

    fn parse_int(&mut self) -> Option<i64> {
        self.ws();
        let start = self.pos;
        if self.s.get(self.pos) == Some(&b'-') {
            self.pos += 1;
        }
        match self.s.get(self.pos)? {
            b'0' => {
                if start + 1 == self.pos {
                    // Reject `-0`
                    return None;
                }
                self.pos += 1;
                if self.s.get(self.pos).is_some_and(u8::is_ascii_digit) {
                    return None;
                }
            }
            b'1'..=b'9' => {
                while self.s.get(self.pos).is_some_and(u8::is_ascii_digit) {
                    self.pos += 1;
                }
            }
            _ => return None,
        }
        if self
            .s
            .get(self.pos)
            .is_some_and(|b| matches!(b, b'.' | b'e' | b'E'))
        {
            return None;
        }
        std::str::from_utf8(&self.s[start..self.pos])
            .ok()?
            .parse::<i64>()
            .ok()
    }

    fn parse_literal(&mut self, lit: &[u8]) -> Option<()> {
        self.ws();
        if !self.s.get(self.pos..)?.starts_with(lit) {
            return None;
        }
        self.pos += lit.len();
        Some(())
    }

    fn parse_list(&mut self) -> Option<ApiCacheJsonVal> {
        if !self.eat(b'[') {
            return None;
        }
        if self.eat(b']') {
            return Some(ApiCacheJsonVal::EmptyArr);
        }
        self.ws();
        let first_byte = *self.s.get(self.pos)?;
        if first_byte == b'"' {
            let mut items = Vec::new();
            loop {
                let s = self.parse_string()?;
                if might_be_php_numeric(&s) {
                    return None;
                }
                items.push(s);
                if self.eat(b']') {
                    break;
                }
                if !self.eat(b',') {
                    return None;
                }
                self.ws();
                if self.s.get(self.pos) != Some(&b'"') {
                    return None;
                }
            }
            items.sort();
            items.dedup();
            Some(ApiCacheJsonVal::StrArr(items))
        } else if first_byte == b'-' || first_byte.is_ascii_digit() {
            let mut items = Vec::new();
            loop {
                let n = self.parse_int()?;
                items.push(n);
                if self.eat(b']') {
                    break;
                }
                if !self.eat(b',') {
                    return None;
                }
                self.ws();
                let next = *self.s.get(self.pos)?;
                if next != b'-' && !next.is_ascii_digit() {
                    return None;
                }
            }
            items.sort_unstable();
            items.dedup();
            Some(ApiCacheJsonVal::IntArr(items))
        } else {
            None
        }
    }

    fn parse_object(&mut self, depth: usize) -> Option<ApiCacheJsonVal> {
        if depth > 64 || !self.eat(b'{') {
            return None;
        }
        let mut entries = Vec::new();
        if !self.eat(b'}') {
            loop {
                let key = self.parse_string()?;
                if might_be_php_numeric(&key) {
                    return None;
                }
                if !self.eat(b':') {
                    return None;
                }
                self.ws();
                let val = match *self.s.get(self.pos)? {
                    b'"' => {
                        let raw = self.parse_string()?;
                        ApiCacheJsonVal::Str(trim_php_ws(&raw).to_vec())
                    }
                    b'{' => self.parse_object(depth + 1)?,
                    b'[' => self.parse_list()?,
                    b't' => {
                        self.parse_literal(b"true")?;
                        ApiCacheJsonVal::Bool(true)
                    }
                    b'f' => {
                        self.parse_literal(b"false")?;
                        ApiCacheJsonVal::Bool(false)
                    }
                    b'n' => {
                        self.parse_literal(b"null")?;
                        ApiCacheJsonVal::Null
                    }
                    b'-' | b'0'..=b'9' => ApiCacheJsonVal::Int(self.parse_int()?),
                    _ => return None,
                };
                entries.push((key, val));
                if self.eat(b'}') {
                    break;
                }
                if !self.eat(b',') {
                    return None;
                }
            }
        }
        entries.sort_by(|a, b| a.0.cmp(&b.0));
        if entries.windows(2).any(|w| w[0].0 == w[1].0) {
            return None;
        }
        Some(ApiCacheJsonVal::Obj(entries))
    }
}

fn write_json_string(out: &mut OutCursor, s: &[u8]) {
    out.push_byte(b'"');
    for &b in s {
        match b {
            b'"' => out.push_slice(b"\\\""),
            b'\\' => out.push_slice(b"\\\\"),
            0x08 => out.push_slice(b"\\b"),
            0x09 => out.push_slice(b"\\t"),
            0x0a => out.push_slice(b"\\n"),
            0x0c => out.push_slice(b"\\f"),
            0x0d => out.push_slice(b"\\r"),
            0x00..=0x1f => {
                let hex = crate::HEX_LOWER;
                out.push_slice(&[b'\\', b'u', b'0', b'0', hex[(b >> 4) as usize], hex[(b & 0x0f) as usize]]);
            }
            _ => out.push_byte(b),
        }
    }
    out.push_byte(b'"');
}

fn write_i64_decimal(out: &mut OutCursor, n: i64) {
    if n == 0 {
        out.push_byte(b'0');
        return;
    }
    let neg = n < 0;
    let mut u = n.unsigned_abs();
    let mut buf = [0u8; 20];
    let mut pos = 20;
    while u > 0 {
        pos -= 1;
        buf[pos] = b'0' + (u % 10) as u8;
        u /= 10;
    }
    if neg {
        out.push_byte(b'-');
    }
    out.push_slice(&buf[pos..]);
}

fn write_json_val(out: &mut OutCursor, val: &ApiCacheJsonVal) {
    match val {
        ApiCacheJsonVal::Str(s) => write_json_string(out, s),
        ApiCacheJsonVal::Int(n) => write_i64_decimal(out, *n),
        ApiCacheJsonVal::Bool(true) => out.push_slice(b"true"),
        ApiCacheJsonVal::Bool(false) => out.push_slice(b"false"),
        ApiCacheJsonVal::Null => out.push_slice(b"null"),
        ApiCacheJsonVal::EmptyArr => out.push_slice(b"[]"),
        ApiCacheJsonVal::IntArr(items) => {
            out.push_byte(b'[');
            for (i, &n) in items.iter().enumerate() {
                if i > 0 {
                    out.push_byte(b',');
                }
                write_i64_decimal(out, n);
            }
            out.push_byte(b']');
        }
        ApiCacheJsonVal::StrArr(items) => {
            out.push_byte(b'[');
            for (i, s) in items.iter().enumerate() {
                if i > 0 {
                    out.push_byte(b',');
                }
                write_json_string(out, s);
            }
            out.push_byte(b']');
        }
        ApiCacheJsonVal::Obj(entries) => {
            out.push_byte(b'{');
            for (i, (k, v)) in entries.iter().enumerate() {
                if i > 0 {
                    out.push_byte(b',');
                }
                write_json_string(out, k);
                out.push_byte(b':');
                write_json_val(out, v);
            }
            out.push_byte(b'}');
        }
    }
}

/// Normalizes a JSON-encoded parameter map for `ApiReleaseRowCache::normalize`:
/// recursively sorts associative keys (`ksort`), trims string values, and deduplicates/sorts
/// list arrays. Returns `-1` when declined to PHP fallback.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_api_release_cache_normalize_json(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if input.is_null() || input_len == 0 || (out_buf.is_null() && out_cap > 0) {
            return -1;
        }
        let s = unsafe { slice::from_raw_parts(input, input_len) };
        if std::str::from_utf8(s).is_err() {
            return -1;
        }
        let mut parser = ApiCacheJsonParser { s, pos: 0 };
        parser.ws();
        let root = match parser.s.get(parser.pos) {
            Some(&b'{') => parser.parse_object(0),
            Some(&b'[') => {
                parser.pos += 1;
                if parser.eat(b']') {
                    Some(ApiCacheJsonVal::EmptyArr)
                } else {
                    None
                }
            }
            _ => None,
        };
        let Some(val) = root else {
            return -1;
        };
        parser.ws();
        if parser.pos != parser.s.len() {
            return -1;
        }

        let mut out = OutCursor::new(out_buf, if out_buf.is_null() { 0 } else { out_cap });
        write_json_val(&mut out, &val);
        out.finish()
    }))
    .unwrap_or(-1)
}

// ============================================================================
// Wave 16 Slice 6: TmuxMetricsSnapshot, GrafanaEmbedService, GrafanaJwtIssuer,
// ContentSecurityPolicy, and MeasureApiPerformance helpers
// ============================================================================

const BASE64URL_ALPHABET: &[u8; 64] =
    b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_";
const HEX_UPPER_CHARS: &[u8; 16] = b"0123456789ABCDEF";
const JWT_RS256_HEADER_B64: &[u8] = b"eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9";

#[inline]
unsafe fn valid_slice_bounded<'a>(ptr: *const u8, len: usize, max_len: usize) -> Option<&'a [u8]> {
    if len > max_len || len > isize::MAX as usize {
        return None;
    }
    if len == 0 {
        return Some(&[]);
    }
    if ptr.is_null() {
        return None;
    }
    Some(unsafe { slice::from_raw_parts(ptr, len) })
}

#[inline]
fn valid_out_cursor_checked(out_buf: *mut u8, out_cap: usize) -> Option<OutCursor> {
    if out_cap > isize::MAX as usize || (out_buf.is_null() && out_cap > 0) {
        return None;
    }
    Some(OutCursor::new(
        out_buf,
        if out_buf.is_null() { 0 } else { out_cap },
    ))
}

#[inline]
fn finish_out_cursor_checked(out: OutCursor) -> isize {
    if out.len > isize::MAX as usize {
        return -1;
    }
    out.finish()
}

#[inline]
fn is_canonical_php_int_key(key: &[u8]) -> bool {
    if key.is_empty() || key.len() > 20 {
        return false;
    }
    if key == b"0" {
        return true;
    }
    let digits = if key[0] == b'-' { &key[1..] } else { key };
    if digits.is_empty() || digits[0] == b'0' || !digits.iter().all(u8::is_ascii_digit) {
        return false;
    }
    std::str::from_utf8(key)
        .ok()
        .and_then(|s| s.parse::<i64>().ok())
        .is_some()
}

#[inline]
fn trim_php_numeric_ws(mut s: &[u8]) -> &[u8] {
    while let Some(&b) = s.first() {
        if is_ascii_ws(b) {
            s = &s[1..];
        } else {
            break;
        }
    }
    while let Some(&b) = s.last() {
        if is_ascii_ws(b) {
            s = &s[..s.len() - 1];
        } else {
            break;
        }
    }
    s
}

enum ParsedSnapshotNum {
    NotNumeric,
    Decline,
    Int(i64),
    FloatToken(Vec<u8>),
}

fn classify_php_numeric_string(raw: &[u8]) -> ParsedSnapshotNum {
    let trimmed = trim_php_numeric_ws(raw);
    if trimmed.is_empty() {
        return ParsedSnapshotNum::NotNumeric;
    }
    let (sign_neg, body) = match trimmed[0] {
        b'+' => (false, &trimmed[1..]),
        b'-' => (true, &trimmed[1..]),
        _ => (false, trimmed),
    };
    if body.is_empty() {
        return ParsedSnapshotNum::NotNumeric;
    }

    let mut i = 0usize;
    let mut int_digits = 0usize;
    while i < body.len() && body[i].is_ascii_digit() {
        int_digits += 1;
        i += 1;
    }
    let mut has_dot = false;
    let mut frac_digits = 0usize;
    if i < body.len() && body[i] == b'.' {
        has_dot = true;
        i += 1;
        while i < body.len() && body[i].is_ascii_digit() {
            frac_digits += 1;
            i += 1;
        }
    }
    if int_digits == 0 && frac_digits == 0 {
        return ParsedSnapshotNum::NotNumeric;
    }
    let mut has_exp = false;
    if i < body.len() && matches!(body[i], b'e' | b'E') {
        has_exp = true;
        i += 1;
        if i < body.len() && matches!(body[i], b'+' | b'-') {
            i += 1;
        }
        let exp_start = i;
        while i < body.len() && body[i].is_ascii_digit() {
            i += 1;
        }
        if i == exp_start {
            return ParsedSnapshotNum::NotNumeric;
        }
    }
    if i != body.len() {
        return ParsedSnapshotNum::NotNumeric;
    }

    if !has_dot && !has_exp {
        let mut parse_buf = Vec::with_capacity(body.len() + 1);
        if sign_neg {
            parse_buf.push(b'-');
        }
        parse_buf.extend_from_slice(body);
        if let Ok(s) = std::str::from_utf8(&parse_buf) {
            if let Ok(v) = s.parse::<i64>() {
                return ParsedSnapshotNum::Int(v);
            }
        }
        return ParsedSnapshotNum::Decline;
    }

    if has_exp || int_digits == 0 || frac_digits == 0 {
        return ParsedSnapshotNum::Decline;
    }
    let mut parse_buf = Vec::with_capacity(body.len() + 1);
    if sign_neg {
        parse_buf.push(b'-');
    }
    parse_buf.extend_from_slice(body);
    let Ok(s) = std::str::from_utf8(&parse_buf) else {
        return ParsedSnapshotNum::Decline;
    };
    let Ok(f) = s.parse::<f64>() else {
        return ParsedSnapshotNum::Decline;
    };
    if !f.is_finite() {
        return ParsedSnapshotNum::Decline;
    }
    let mut formatted = format!("{f}").into_bytes();
    if !formatted.contains(&b'.') && !formatted.contains(&b'e') && !formatted.contains(&b'E') {
        formatted.extend_from_slice(b".0");
    }
    ParsedSnapshotNum::FloatToken(formatted)
}

impl<'a> ApiCacheJsonParser<'a> {
    fn skip_any_value(&mut self, depth: usize) -> Option<()> {
        if depth > 64 {
            return None;
        }
        self.ws();
        match *self.s.get(self.pos)? {
            b'"' => {
                self.parse_string()?;
                Some(())
            }
            b't' => self.parse_literal(b"true"),
            b'f' => self.parse_literal(b"false"),
            b'n' => self.parse_literal(b"null"),
            b'[' => {
                self.pos += 1;
                if self.eat(b']') {
                    return Some(());
                }
                loop {
                    self.skip_any_value(depth + 1)?;
                    if self.eat(b']') {
                        return Some(());
                    }
                    if !self.eat(b',') {
                        return None;
                    }
                }
            }
            b'{' => {
                self.pos += 1;
                if self.eat(b'}') {
                    return Some(());
                }
                loop {
                    self.parse_string()?;
                    if !self.eat(b':') {
                        return None;
                    }
                    self.skip_any_value(depth + 1)?;
                    if self.eat(b'}') {
                        return Some(());
                    }
                    if !self.eat(b',') {
                        return None;
                    }
                }
            }
            b'-' | b'0'..=b'9' => {
                let _ = self.parse_raw_number_token()?;
                Some(())
            }
            _ => None,
        }
    }

    fn parse_raw_number_token(&mut self) -> Option<(&'a [u8], bool)> {
        self.ws();
        let start = self.pos;
        if self.s.get(self.pos) == Some(&b'-') {
            self.pos += 1;
        }
        match *self.s.get(self.pos)? {
            b'0' => {
                self.pos += 1;
                if self.s.get(self.pos).is_some_and(u8::is_ascii_digit) {
                    return None;
                }
            }
            b'1'..=b'9' => {
                self.pos += 1;
                while self.s.get(self.pos).is_some_and(u8::is_ascii_digit) {
                    self.pos += 1;
                }
            }
            _ => return None,
        }
        let mut is_float = false;
        if self.s.get(self.pos) == Some(&b'.') {
            is_float = true;
            self.pos += 1;
            let frac_start = self.pos;
            while self.s.get(self.pos).is_some_and(u8::is_ascii_digit) {
                self.pos += 1;
            }
            if self.pos == frac_start {
                return None;
            }
        }
        if self
            .s
            .get(self.pos)
            .is_some_and(|b| matches!(b, b'e' | b'E'))
        {
            is_float = true;
            self.pos += 1;
            if self
                .s
                .get(self.pos)
                .is_some_and(|b| matches!(b, b'+' | b'-'))
            {
                self.pos += 1;
            }
            let exp_start = self.pos;
            while self.s.get(self.pos).is_some_and(u8::is_ascii_digit) {
                self.pos += 1;
            }
            if self.pos == exp_start {
                return None;
            }
        }
        Some((&self.s[start..self.pos], is_float))
    }

    fn parse_php_int_cast(&mut self) -> Option<i64> {
        self.ws();
        match *self.s.get(self.pos)? {
            b'n' => {
                self.parse_literal(b"null")?;
                Some(0)
            }
            b'f' => {
                self.parse_literal(b"false")?;
                Some(0)
            }
            b't' => {
                self.parse_literal(b"true")?;
                Some(1)
            }
            b'-' | b'0'..=b'9' => {
                let (tok, is_float) = self.parse_raw_number_token()?;
                if !is_float {
                    std::str::from_utf8(tok).ok()?.parse::<i64>().ok()
                } else {
                    let f = std::str::from_utf8(tok).ok()?.parse::<f64>().ok()?;
                    if !f.is_finite() || f <= (i64::MIN as f64) || f >= (i64::MAX as f64) {
                        return None;
                    }
                    Some(f.trunc() as i64)
                }
            }
            b'"' => {
                let raw = self.parse_string()?;
                match classify_php_numeric_string(&raw) {
                    ParsedSnapshotNum::NotNumeric => Some(0),
                    ParsedSnapshotNum::Int(v) => Some(v),
                    ParsedSnapshotNum::FloatToken(_) | ParsedSnapshotNum::Decline => None,
                }
            }
            _ => None,
        }
    }
}

/// Computes tmux snapshot age in seconds (`max(0, now_ts - collected_at)`) and writes
/// `1` to `*out_is_stale` when `age > max(0, max_age_seconds)` (`0` otherwise).
/// Returns `-1` on null `out_is_stale` or integer overflow.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_snapshot_age_and_stale(
    collected_at: i64,
    now_ts: i64,
    max_age_seconds: i64,
    out_is_stale: *mut i32,
) -> i64 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_is_stale.is_null() {
            return -1;
        }
        let age = if now_ts <= collected_at {
            0i64
        } else {
            let Some(diff) = now_ts.checked_sub(collected_at) else {
                return -1;
            };
            diff.max(0)
        };
        let threshold = max_age_seconds.max(0);
        unsafe {
            *out_is_stale = i32::from(age > threshold);
        }
        age
    }))
    .unwrap_or(-1)
}

/// Normalizes a JSON-encoded map of tmux snapshot metrics (`TmuxMetricsSnapshot::numericValues`),
/// retaining only string-keyed numeric entries (`int`, `float`, or `is_numeric` string) and
/// optionally coercing all outputs to JSON floats (`force_float != 0` for `query_timers`).
/// Returns `-1` when declined to PHP fallback.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_snapshot_normalize_numeric_json(
    input: *const u8,
    input_len: usize,
    force_float: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if input.is_null() || input_len == 0 {
            return -1;
        }
        let Some(s) = (unsafe { valid_slice_bounded(input, input_len, (isize::MAX as usize) / 4) })
        else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };
        if std::str::from_utf8(s).is_err() {
            return -1;
        }

        let mut parser = ApiCacheJsonParser { s, pos: 0 };
        parser.ws();
        match parser.s.get(parser.pos) {
            Some(&b'[') => {
                parser.skip_any_value(0);
                parser.ws();
                if parser.pos != parser.s.len() {
                    return -1;
                }
                out.push_slice(b"[]");
                return finish_out_cursor_checked(out);
            }
            Some(&b'{') => {
                parser.pos += 1;
            }
            _ => return -1,
        }

        let mut kept: Vec<(Vec<u8>, Vec<u8>)> = Vec::new();
        if !parser.eat(b'}') {
            loop {
                let Some(key) = parser.parse_string() else {
                    return -1;
                };
                if !parser.eat(b':') {
                    return -1;
                }
                if is_canonical_php_int_key(&key) {
                    if parser.skip_any_value(1).is_none() {
                        return -1;
                    }
                } else {
                    parser.ws();
                    match parser.s.get(parser.pos) {
                        Some(&b'-') | Some(b'0'..=b'9') => {
                            let Some((tok, is_float)) = parser.parse_raw_number_token() else {
                                return -1;
                            };
                            if !is_float {
                                let Some(n) = std::str::from_utf8(tok)
                                    .ok()
                                    .and_then(|t| t.parse::<i64>().ok())
                                else {
                                    return -1;
                                };
                                let mut vbuf = Vec::with_capacity(tok.len() + 2);
                                vbuf.extend_from_slice(n.to_string().as_bytes());
                                if force_float != 0 {
                                    vbuf.extend_from_slice(b".0");
                                }
                                kept.push((key, vbuf));
                            } else {
                                kept.push((key, tok.to_vec()));
                            }
                        }
                        Some(&b'"') => {
                            let Some(raw_str) = parser.parse_string() else {
                                return -1;
                            };
                            match classify_php_numeric_string(&raw_str) {
                                ParsedSnapshotNum::NotNumeric => {}
                                ParsedSnapshotNum::Decline => return -1,
                                ParsedSnapshotNum::Int(n) => {
                                    let mut vbuf = n.to_string().into_bytes();
                                    if force_float != 0 {
                                        vbuf.extend_from_slice(b".0");
                                    }
                                    kept.push((key, vbuf));
                                }
                                ParsedSnapshotNum::FloatToken(ftok) => {
                                    kept.push((key, ftok));
                                }
                            }
                        }
                        Some(_) => {
                            if parser.skip_any_value(1).is_none() {
                                return -1;
                            }
                        }
                        None => return -1,
                    }
                }
                if parser.eat(b'}') {
                    break;
                }
                if !parser.eat(b',') {
                    return -1;
                }
            }
        }
        parser.ws();
        if parser.pos != parser.s.len() {
            return -1;
        }

        if kept.is_empty() {
            out.push_slice(b"[]");
            return finish_out_cursor_checked(out);
        }

        out.push_byte(b'{');
        for (idx, (k, raw_num)) in kept.iter().enumerate() {
            if idx > 0 {
                out.push_byte(b',');
            }
            write_json_string(&mut out, k);
            out.push_byte(b':');
            out.push_slice(raw_num);
        }
        out.push_byte(b'}');
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Normalizes a JSON-encoded map of per-server NNTP connection counts
/// (`TmuxMetricsSnapshot::connections`) into `{"server":{"active":int,"total":int}}`
/// (or `[]` when empty). Returns `-1` when declined to PHP fallback.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_snapshot_normalize_connections_json(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if input.is_null() || input_len == 0 {
            return -1;
        }
        let Some(s) = (unsafe { valid_slice_bounded(input, input_len, (isize::MAX as usize) / 4) })
        else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };
        if std::str::from_utf8(s).is_err() {
            return -1;
        }

        let mut parser = ApiCacheJsonParser { s, pos: 0 };
        parser.ws();
        match parser.s.get(parser.pos) {
            Some(&b'[') => {
                if parser.skip_any_value(0).is_none() {
                    return -1;
                }
                parser.ws();
                if parser.pos != parser.s.len() {
                    return -1;
                }
                out.push_slice(b"[]");
                return finish_out_cursor_checked(out);
            }
            Some(&b'{') => {
                parser.pos += 1;
            }
            _ => return -1,
        }

        let mut servers: Vec<(Vec<u8>, i64, i64)> = Vec::new();
        if !parser.eat(b'}') {
            loop {
                let Some(server) = parser.parse_string() else {
                    return -1;
                };
                if !parser.eat(b':') {
                    return -1;
                }
                if is_canonical_php_int_key(&server) {
                    if parser.skip_any_value(1).is_none() {
                        return -1;
                    }
                } else {
                    parser.ws();
                    match parser.s.get(parser.pos) {
                        Some(&b'[') => {
                            if parser.skip_any_value(1).is_none() {
                                return -1;
                            }
                            servers.push((server, 0, 0));
                        }
                        Some(&b'{') => {
                            parser.pos += 1;
                            let mut active = 0i64;
                            let mut total = 0i64;
                            if !parser.eat(b'}') {
                                loop {
                                    let Some(field) = parser.parse_string() else {
                                        return -1;
                                    };
                                    if !parser.eat(b':') {
                                        return -1;
                                    }
                                    if field == b"active" {
                                        let Some(v) = parser.parse_php_int_cast() else {
                                            return -1;
                                        };
                                        active = v;
                                    } else if field == b"total" {
                                        let Some(v) = parser.parse_php_int_cast() else {
                                            return -1;
                                        };
                                        total = v;
                                    } else if parser.skip_any_value(2).is_none() {
                                        return -1;
                                    }
                                    if parser.eat(b'}') {
                                        break;
                                    }
                                    if !parser.eat(b',') {
                                        return -1;
                                    }
                                }
                            }
                            servers.push((server, active, total));
                        }
                        Some(_) => {
                            if parser.skip_any_value(1).is_none() {
                                return -1;
                            }
                        }
                        None => return -1,
                    }
                }
                if parser.eat(b'}') {
                    break;
                }
                if !parser.eat(b',') {
                    return -1;
                }
            }
        }
        parser.ws();
        if parser.pos != parser.s.len() {
            return -1;
        }

        if servers.is_empty() {
            out.push_slice(b"[]");
            return finish_out_cursor_checked(out);
        }

        out.push_byte(b'{');
        for (idx, (server, active, total)) in servers.iter().enumerate() {
            if idx > 0 {
                out.push_byte(b',');
            }
            write_json_string(&mut out, server);
            out.push_slice(b":{\"active\":");
            write_i64_decimal(&mut out, *active);
            out.push_slice(b",\"total\":");
            write_i64_decimal(&mut out, *total);
            out.push_byte(b'}');
        }
        out.push_byte(b'}');
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

#[inline]
fn push_rawurlencoded(out: &mut OutCursor, bytes: &[u8]) {
    for &b in bytes {
        if b.is_ascii_alphanumeric() || matches!(b, b'-' | b'_' | b'.' | b'~') {
            out.push_byte(b);
        } else {
            out.push_slice(&[
                b'%',
                HEX_UPPER_CHARS[(b >> 4) as usize],
                HEX_UPPER_CHARS[(b & 0x0f) as usize],
            ]);
        }
    }
}

#[inline]
fn push_urlencoded_rfc1738(out: &mut OutCursor, bytes: &[u8]) {
    for &b in bytes {
        if b.is_ascii_alphanumeric() || matches!(b, b'-' | b'_' | b'.') {
            out.push_byte(b);
        } else if b == b' ' {
            out.push_byte(b'+');
        } else {
            out.push_slice(&[
                b'%',
                HEX_UPPER_CHARS[(b >> 4) as usize],
                HEX_UPPER_CHARS[(b & 0x0f) as usize],
            ]);
        }
    }
}

#[inline]
fn rtrim_slashes(mut s: &[u8]) -> &[u8] {
    while s.last() == Some(&b'/') {
        s = &s[..s.len() - 1];
    }
    s
}

/// Builds a Grafana dashboard (`/d/...`) or solo panel (`/d-solo/...`) embed URL
/// matching `GrafanaEmbedService::dashboardUrl` and `GrafanaEmbedService::panelUrl`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_grafana_embed_url(
    base_url: *const u8,
    base_url_len: usize,
    uid: *const u8,
    uid_len: usize,
    slug: *const u8,
    slug_len: usize,
    org_id: i64,
    from_range: *const u8,
    from_range_len: usize,
    refresh: *const u8,
    refresh_len: usize,
    is_panel: u32,
    kiosk: u32,
    panel_id: i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let max_seg = (isize::MAX as usize) / 16;
        let (Some(base), Some(uid_s), Some(slug_s), Some(from_s), Some(refresh_s)) = (
            unsafe { valid_slice_bounded(base_url, base_url_len, max_seg) },
            unsafe { valid_slice_bounded(uid, uid_len, max_seg) },
            unsafe { valid_slice_bounded(slug, slug_len, max_seg) },
            unsafe { valid_slice_bounded(from_range, from_range_len, max_seg) },
            unsafe { valid_slice_bounded(refresh, refresh_len, max_seg) },
        ) else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };

        out.push_slice(rtrim_slashes(base));
        out.push_slice(if is_panel != 0 { b"/d-solo/" } else { b"/d/" });
        push_rawurlencoded(&mut out, uid_s);
        out.push_byte(b'/');
        push_rawurlencoded(&mut out, slug_s);
        out.push_slice(b"?orgId=");
        write_i64_decimal(&mut out, org_id);
        out.push_slice(b"&from=");
        push_urlencoded_rfc1738(&mut out, from_s);
        out.push_slice(b"&to=now&refresh=");
        push_urlencoded_rfc1738(&mut out, refresh_s);
        if is_panel != 0 {
            out.push_slice(b"&panelId=");
            write_i64_decimal(&mut out, panel_id);
        } else if kiosk != 0 {
            out.push_slice(b"&kiosk");
        }
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

#[derive(Clone, Copy)]
struct ParsedUrlOrigin<'a> {
    scheme: &'a [u8],
    host: &'a [u8],
    port: Option<u16>,
    path: &'a [u8],
}

fn parse_simple_url_for_origin(bytes: &[u8]) -> Result<Option<ParsedUrlOrigin<'_>>, ()> {
    if bytes.is_empty() {
        return Ok(None);
    }
    if bytes.iter().any(|&b| b <= 0x1f || b == 0x7f) {
        return Err(());
    }
    let Some(colon_pos) = bytes
        .iter()
        .position(|&b| !(b.is_ascii_alphanumeric() || matches!(b, b'+' | b'-' | b'.')))
    else {
        return Ok(None);
    };
    if colon_pos == 0
        || !bytes[0].is_ascii_alphabetic()
        || bytes.get(colon_pos..colon_pos + 3) != Some(b"://")
    {
        if bytes[colon_pos] == b':' {
            return Err(());
        }
        return Ok(None);
    }

    let scheme = &bytes[..colon_pos];
    let after_scheme = colon_pos + 3;
    let auth_end = bytes[after_scheme..]
        .iter()
        .position(|&b| matches!(b, b'/' | b'?' | b'#'))
        .map_or(bytes.len(), |i| after_scheme + i);
    let auth = &bytes[after_scheme..auth_end];
    if auth.is_empty() {
        return Ok(None);
    }

    let host_port = match auth.iter().rposition(|&b| b == b'@') {
        Some(at) => &auth[at + 1..],
        None => auth,
    };
    if host_port.is_empty() {
        return Ok(None);
    }

    let (host, port_opt): (&[u8], Option<&[u8]>) = if host_port[0] == b'[' {
        let Some(rel_close) = host_port[1..].iter().position(|&b| b == b']') else {
            return Err(());
        };
        let close = 1 + rel_close;
        if close == 1 {
            return Err(());
        }
        let rest = &host_port[close + 1..];
        if rest.is_empty() {
            (&host_port[..=close], None)
        } else if rest[0] == b':' {
            (&host_port[..=close], Some(&rest[1..]))
        } else {
            return Err(());
        }
    } else if let Some(c) = host_port.iter().rposition(|&b| b == b':') {
        (&host_port[..c], Some(&host_port[c + 1..]))
    } else {
        (host_port, None)
    };

    if host.is_empty() {
        return Ok(None);
    }

    let parsed_port: Option<u16> = match port_opt {
        None | Some(b"") => None,
        Some(p) => {
            if p.len() > 5 || !p.iter().all(u8::is_ascii_digit) {
                return Err(());
            }
            let mut port_val = 0u32;
            for &d in p {
                port_val = port_val * 10 + u32::from(d - b'0');
            }
            if port_val == 0 || port_val > 65535 {
                return Err(());
            }
            Some(port_val as u16)
        }
    };

    let path: &[u8] = if auth_end < bytes.len() && bytes[auth_end] == b'/' {
        let path_end = bytes[auth_end..]
            .iter()
            .position(|&b| matches!(b, b'?' | b'#'))
            .map_or(bytes.len(), |i| auth_end + i);
        &bytes[auth_end..path_end]
    } else {
        b""
    };

    Ok(Some(ParsedUrlOrigin {
        scheme,
        host,
        port: parsed_port,
        path,
    }))
}

#[inline]
fn origins_match_ignore_ascii_case(a: &ParsedUrlOrigin<'_>, b: &ParsedUrlOrigin<'_>) -> bool {
    a.scheme.eq_ignore_ascii_case(b.scheme)
        && a.host.eq_ignore_ascii_case(b.host)
        && a.port == b.port
}

/// Computes `GrafanaEmbedService::frameOrigin($grafanaUrl, $appUrl)`:
/// returns `0` when `null` (relative URL, missing scheme/host, or same origin as `app_url`),
/// `> 0` bytes written when cross-origin, `-(required_len)` if `out_cap` is too small,
/// or `-1` when declined to PHP `parse_url`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_grafana_frame_origin(
    grafana_url: *const u8,
    grafana_url_len: usize,
    app_url: *const u8,
    app_url_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let max_len = (isize::MAX as usize) / 4;
        let (Some(g_bytes), Some(a_bytes)) = (
            unsafe { valid_slice_bounded(grafana_url, grafana_url_len, max_len) },
            unsafe { valid_slice_bounded(app_url, app_url_len, max_len) },
        ) else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };

        let g_parsed = match parse_simple_url_for_origin(g_bytes) {
            Ok(Some(p)) => p,
            Ok(None) => return 0,
            Err(()) => return -1,
        };

        if !a_bytes.is_empty() {
            match parse_simple_url_for_origin(a_bytes) {
                Ok(Some(a_parsed)) => {
                    if origins_match_ignore_ascii_case(&g_parsed, &a_parsed) {
                        return 0;
                    }
                }
                Ok(None) => {}
                Err(()) => return -1,
            }
        }

        for &b in g_parsed.scheme {
            out.push_byte(b.to_ascii_lowercase());
        }
        out.push_slice(b"://");
        for &b in g_parsed.host {
            out.push_byte(b.to_ascii_lowercase());
        }
        if let Some(port) = g_parsed.port {
            out.push_byte(b':');
            push_u16_decimal(&mut out, port);
        }
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Computes `GrafanaEmbedService::cookiePath` for a given `base_url`:
/// extracts `parse_url(rtrim(base_url, '/'), PHP_URL_PATH)` and formats `rtrim(path, '/') . '/'`.
/// Returns `-1` when declined to PHP `parse_url`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_grafana_cookie_path(
    base_url: *const u8,
    base_url_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(raw) =
            (unsafe { valid_slice_bounded(base_url, base_url_len, (isize::MAX as usize) / 4) })
        else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };

        let trimmed_base = rtrim_slashes(raw);
        if trimmed_base.is_empty() {
            out.push_byte(b'/');
            return finish_out_cursor_checked(out);
        }
        if trimmed_base
            .iter()
            .any(|&b| b <= 0x1f || b == 0x7f || b == b'?' || b == b'#')
        {
            return -1;
        }

        let path: &[u8] = if trimmed_base.starts_with(b"//") {
            return -1;
        } else if trimmed_base[0] == b'/' {
            trimmed_base
        } else if trimmed_base.contains(&b':') {
            match parse_simple_url_for_origin(trimmed_base) {
                Ok(Some(parsed)) => parsed.path,
                Ok(None) | Err(()) => return -1,
            }
        } else {
            trimmed_base
        };

        let clean_path = rtrim_slashes(path);
        out.push_slice(clean_path);
        out.push_byte(b'/');
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

#[inline]
fn push_base64url_encoded(out: &mut OutCursor, bytes: &[u8]) {
    let mut chunks = bytes.chunks_exact(3);
    for chunk in &mut chunks {
        let b0 = u32::from(chunk[0]);
        let b1 = u32::from(chunk[1]);
        let b2 = u32::from(chunk[2]);
        let n = (b0 << 16) | (b1 << 8) | b2;
        out.push_slice(&[
            BASE64URL_ALPHABET[((n >> 18) & 0x3f) as usize],
            BASE64URL_ALPHABET[((n >> 12) & 0x3f) as usize],
            BASE64URL_ALPHABET[((n >> 6) & 0x3f) as usize],
            BASE64URL_ALPHABET[(n & 0x3f) as usize],
        ]);
    }
    let rem = chunks.remainder();
    if rem.len() == 1 {
        let b0 = u32::from(rem[0]);
        out.push_slice(&[
            BASE64URL_ALPHABET[((b0 >> 2) & 0x3f) as usize],
            BASE64URL_ALPHABET[((b0 << 4) & 0x3f) as usize],
        ]);
    } else if rem.len() == 2 {
        let b0 = u32::from(rem[0]);
        let b1 = u32::from(rem[1]);
        let n = (b0 << 8) | b1;
        out.push_slice(&[
            BASE64URL_ALPHABET[((n >> 10) & 0x3f) as usize],
            BASE64URL_ALPHABET[((n >> 4) & 0x3f) as usize],
            BASE64URL_ALPHABET[((n << 2) & 0x3f) as usize],
        ]);
    }
}

/// Encodes `input` using URL-safe base64 (`-`, `_`) without `=` padding,
/// matching `GrafanaJwtIssuer::base64Url`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_grafana_jwt_base64url_encode(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(bytes) =
            (unsafe { valid_slice_bounded(input, input_len, (isize::MAX as usize) / 2) })
        else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };
        push_base64url_encoded(&mut out, bytes);
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

#[inline]
fn push_vec_json_string_ascii(dst: &mut Vec<u8>, s: &[u8]) {
    dst.push(b'"');
    for &b in s {
        match b {
            b'"' => dst.extend_from_slice(b"\\\""),
            b'\\' => dst.extend_from_slice(b"\\\\"),
            0x08 => dst.extend_from_slice(b"\\b"),
            0x09 => dst.extend_from_slice(b"\\t"),
            0x0a => dst.extend_from_slice(b"\\n"),
            0x0c => dst.extend_from_slice(b"\\f"),
            0x0d => dst.extend_from_slice(b"\\r"),
            0x00..=0x1f => {
                let hex = crate::HEX_LOWER;
                dst.extend_from_slice(&[
                    b'\\',
                    b'u',
                    b'0',
                    b'0',
                    hex[(b >> 4) as usize],
                    hex[(b & 0x0f) as usize],
                ]);
            }
            _ => dst.push(b),
        }
    }
    dst.push(b'"');
}

#[inline]
fn push_vec_i64_decimal(dst: &mut Vec<u8>, n: i64) {
    if n == 0 {
        dst.push(b'0');
        return;
    }
    let neg = n < 0;
    let mut u = n.unsigned_abs();
    let mut buf = [0u8; 20];
    let mut pos = 20;
    while u > 0 {
        pos -= 1;
        buf[pos] = b'0' + (u % 10) as u8;
        u /= 10;
    }
    if neg {
        dst.push(b'-');
    }
    dst.extend_from_slice(&buf[pos..]);
}

/// Formats the Grafana JWT claims JSON (`raw_claims_only != 0`) or the complete
/// `<base64url(header)>.<base64url(claims)>` RS256 signing input (`raw_claims_only == 0`)
/// for `GrafanaJwtIssuer`. Returns `-1` if any string contains non-ASCII bytes (`>= 0x80`)
/// or if `now_ts - 30` overflows `i64`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_grafana_jwt_signing_input(
    username: *const u8,
    username_len: usize,
    email: *const u8,
    email_len: usize,
    role: *const u8,
    role_len: usize,
    issuer: *const u8,
    issuer_len: usize,
    audience: *const u8,
    audience_len: usize,
    now_ts: i64,
    expires_at: i64,
    jti: *const u8,
    jti_len: usize,
    raw_claims_only: u32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let max_seg = (isize::MAX as usize) / 16;
        let (Some(user_s), Some(email_s), Some(role_s), Some(iss_s), Some(aud_s), Some(jti_s)) = (
            unsafe { valid_slice_bounded(username, username_len, max_seg) },
            unsafe { valid_slice_bounded(email, email_len, max_seg) },
            unsafe { valid_slice_bounded(role, role_len, max_seg) },
            unsafe { valid_slice_bounded(issuer, issuer_len, max_seg) },
            unsafe { valid_slice_bounded(audience, audience_len, max_seg) },
            unsafe { valid_slice_bounded(jti, jti_len, max_seg) },
        ) else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };
        let Some(nbf) = now_ts.checked_sub(30) else {
            return -1;
        };

        for slice_item in [user_s, email_s, role_s, iss_s, aud_s, jti_s] {
            if slice_item.iter().any(|&b| b >= 0x80) {
                return -1;
            }
        }

        let mut claims_json = Vec::with_capacity(
            128 + user_s.len() * 2
                + email_s.len()
                + role_s.len()
                + iss_s.len()
                + aud_s.len()
                + jti_s.len(),
        );
        claims_json.extend_from_slice(b"{\"sub\":");
        push_vec_json_string_ascii(&mut claims_json, user_s);
        claims_json.extend_from_slice(b",\"email\":");
        push_vec_json_string_ascii(&mut claims_json, email_s);
        claims_json.extend_from_slice(b",\"name\":");
        push_vec_json_string_ascii(&mut claims_json, user_s);
        claims_json.extend_from_slice(b",\"role\":");
        push_vec_json_string_ascii(&mut claims_json, role_s);
        claims_json.extend_from_slice(b",\"iss\":");
        push_vec_json_string_ascii(&mut claims_json, iss_s);
        claims_json.extend_from_slice(b",\"aud\":");
        push_vec_json_string_ascii(&mut claims_json, aud_s);
        claims_json.extend_from_slice(b",\"iat\":");
        push_vec_i64_decimal(&mut claims_json, now_ts);
        claims_json.extend_from_slice(b",\"nbf\":");
        push_vec_i64_decimal(&mut claims_json, nbf);
        claims_json.extend_from_slice(b",\"exp\":");
        push_vec_i64_decimal(&mut claims_json, expires_at);
        claims_json.extend_from_slice(b",\"jti\":");
        push_vec_json_string_ascii(&mut claims_json, jti_s);
        claims_json.push(b'}');

        if raw_claims_only != 0 {
            out.push_slice(&claims_json);
            return finish_out_cursor_checked(out);
        }

        out.push_slice(JWT_RS256_HEADER_B64);
        out.push_byte(b'.');
        push_base64url_encoded(&mut out, &claims_json);
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

const CSP_SCRIPT_HOSTS: &[u8] = b" https://challenges.cloudflare.com https://cdn.tiny.cloud https://cdn.jsdelivr.net/ https://static.cloudflareinsights.com/ https://cdnjs.cloudflare.com/ https://unpkg.com/ https://cdn.tailwindcss.com/ https://code.jquery.com https://apis.google.com https://www.google.com https://www.gstatic.com https://ajax.cloudflare.com";
const CSP_STYLE_PREFIX: &[u8] = b"style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net https://cdn.jsdelivr.net/ https://cdnjs.cloudflare.com/ https://cdn.tiny.cloud";
const CSP_STYLE_ELEM_PREFIX: &[u8] = b"; style-src-elem 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net https://cdn.jsdelivr.net/ https://cdnjs.cloudflare.com/ https://cdn.tiny.cloud";
const CSP_FONT_PREFIX: &[u8] = b"; font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net https://cdnjs.cloudflare.com/ https://cdn.tiny.cloud data:";
const CSP_IMG_PREFIX: &[u8] = b"; img-src 'self' data: https: blob:";
const CSP_CONNECT_PREFIX: &[u8] =
    b"; connect-src 'self' https://www.google.com https://cdn.tiny.cloud https://sp.tinymce.com";
const CSP_FRAME_PREFIX: &[u8] = b"; frame-src 'self' https://www.google.com https://www.gstatic.com https://challenges.cloudflare.com https://cdn.tiny.cloud data: blob:";
const CSP_CHILD_PREFIX: &[u8] =
    b"; child-src 'self' https://www.google.com https://challenges.cloudflare.com https://cdn.tiny.cloud blob:";
const CSP_TAIL: &[u8] =
    b"; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

/// Assembles the complete `Content-Security-Policy` header string for `ContentSecurityPolicy`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_csp_build_header(
    nonce: *const u8,
    nonce_len: usize,
    vite_asset: *const u8,
    vite_asset_len: usize,
    vite_ws: *const u8,
    vite_ws_len: usize,
    grafana_origin: *const u8,
    grafana_origin_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let max_seg = (isize::MAX as usize) / 16;
        let (Some(nonce_s), Some(asset_s), Some(ws_s), Some(grafana_s)) = (
            unsafe { valid_slice_bounded(nonce, nonce_len, max_seg) },
            unsafe { valid_slice_bounded(vite_asset, vite_asset_len, max_seg) },
            unsafe { valid_slice_bounded(vite_ws, vite_ws_len, max_seg) },
            unsafe { valid_slice_bounded(grafana_origin, grafana_origin_len, max_seg) },
        ) else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };

        let push_vite_asset = |cursor: &mut OutCursor| {
            if !asset_s.is_empty() {
                cursor.push_byte(b' ');
                cursor.push_slice(asset_s);
            }
        };

        out.push_slice(b"default-src 'self'; script-src 'self' 'nonce-");
        out.push_slice(nonce_s);
        out.push_slice(b"' 'unsafe-eval' 'strict-dynamic'");
        out.push_slice(CSP_SCRIPT_HOSTS);
        out.push_slice(b" blob:");
        push_vite_asset(&mut out);

        out.push_slice(b"; script-src-elem 'self' 'nonce-");
        out.push_slice(nonce_s);
        out.push_byte(b'\'');
        out.push_slice(CSP_SCRIPT_HOSTS);
        push_vite_asset(&mut out);

        out.push_slice(b"; script-src-attr 'none'; ");
        out.push_slice(CSP_STYLE_PREFIX);
        push_vite_asset(&mut out);

        out.push_slice(CSP_STYLE_ELEM_PREFIX);
        push_vite_asset(&mut out);

        out.push_slice(CSP_FONT_PREFIX);
        push_vite_asset(&mut out);

        out.push_slice(CSP_IMG_PREFIX);
        push_vite_asset(&mut out);

        out.push_slice(CSP_CONNECT_PREFIX);
        if !asset_s.is_empty() && !ws_s.is_empty() {
            out.push_byte(b' ');
            out.push_slice(asset_s);
            out.push_byte(b' ');
            out.push_slice(ws_s);
        }

        out.push_slice(CSP_FRAME_PREFIX);
        if !grafana_s.is_empty() {
            out.push_byte(b' ');
            out.push_slice(grafana_s);
        }

        out.push_slice(CSP_CHILD_PREFIX);
        if !grafana_s.is_empty() {
            out.push_byte(b' ');
            out.push_slice(grafana_s);
        }

        out.push_slice(CSP_TAIL);
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Parses a raw Vite hot-file URL for `ContentSecurityPolicy::viteDevServerSources`:
/// writes `{asset_origin}\t{websocket_origin}` when the URL uses `http` or `https` and a
/// loopback host (`localhost`, `127.0.0.1`, `[::1]`), `0` when rejected, or `-1` when
/// declined to PHP `parse_url`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_csp_parse_vite_hot_url(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(raw) =
            (unsafe { valid_slice_bounded(input, input_len, (isize::MAX as usize) / 4) })
        else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };

        let trimmed = trim_php_ws(raw);
        if trimmed.is_empty() {
            return 0;
        }
        let parsed = match parse_simple_url_for_origin(trimmed) {
            Ok(Some(p)) => p,
            Ok(None) => return 0,
            Err(()) => return -1,
        };

        let is_https = if parsed.scheme == b"http" {
            false
        } else if parsed.scheme == b"https" {
            true
        } else {
            return 0;
        };

        let url_host: &[u8] = if parsed.host == b"localhost"
            || parsed.host == b"127.0.0.1"
            || parsed.host == b"[::1]"
        {
            parsed.host
        } else {
            return 0;
        };

        out.push_slice(parsed.scheme);
        out.push_slice(b"://");
        out.push_slice(url_host);
        if let Some(port) = parsed.port {
            out.push_byte(b':');
            push_u16_decimal(&mut out, port);
        }
        out.push_byte(b'\t');
        out.push_slice(if is_https { b"wss://" } else { b"ws://" });
        out.push_slice(url_host);
        if let Some(port) = parsed.port {
            out.push_byte(b':');
            push_u16_decimal(&mut out, port);
        }
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Computes elapsed milliseconds (`(finished_at_ns - started_at_ns) / 1_000_000`) and its
/// 2-decimal rounded value (`round($durationMs, 2)`) for `MeasureApiPerformance`.
/// Returns `0` on success or `-1` on null output pointers or integer overflow.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_api_perf_elapsed_ms(
    started_at_ns: i64,
    finished_at_ns: i64,
    out_raw_ms: *mut f64,
    out_rounded_ms: *mut f64,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_raw_ms.is_null() || out_rounded_ms.is_null() {
            return -1;
        }
        let Some(diff) = finished_at_ns.checked_sub(started_at_ns) else {
            return -1;
        };
        let raw_ms = (diff as f64) / 1_000_000.0;
        let rounded_ms = if diff >= 0 {
            let Some(shifted) = diff.checked_add(5_000) else {
                return -1;
            };
            ((shifted / 10_000) as f64) / 100.0
        } else {
            let abs_diff = diff.unsigned_abs();
            let Some(shifted) = abs_diff.checked_add(5_000) else {
                return -1;
            };
            -(((shifted / 10_000) as f64) / 100.0)
        };
        unsafe {
            *out_raw_ms = raw_ms;
            *out_rounded_ms = rounded_ms;
        }
        0
    }))
    .unwrap_or(-1)
}

/// Formats the `Server-Timing` response header (`sprintf('app;dur=%.2f, db;dur=%.2f;desc="%d queries"', ...)`)
/// for `MeasureApiPerformance`. Returns `-1` if `duration_ms` or `query_ms` is non-finite.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_api_perf_format_server_timing(
    duration_ms: f64,
    query_ms: f64,
    query_count: i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !duration_ms.is_finite() || !query_ms.is_finite() {
            return -1;
        }
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };
        let app_dur = if duration_ms == 0.0 { 0.0 } else { duration_ms };
        let db_dur = if query_ms == 0.0 { 0.0 } else { query_ms };
        let formatted = format!(
            "app;dur={app_dur:.2}, db;dur={db_dur:.2};desc=\"{query_count} queries\""
        );
        out.push_slice(formatted.as_bytes());
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Classifies API request latency against slow-request thresholds (`0` = normal, `1` = slow app,
/// `2` = slow db, `3` = both) and optionally computes the clamped `1..1_000_000` sample threshold
/// (`(int) (min(1.0, max(0.0, sample_rate)) * 1_000_000)`) into `*out_sample_threshold`.
/// Returns `-1` when any duration/threshold parameter is non-finite.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_api_perf_classify_slow_request(
    duration_ms: f64,
    query_ms: f64,
    slow_duration_threshold_ms: f64,
    slow_query_threshold_ms: f64,
    sample_rate: f64,
    out_sample_threshold: *mut i64,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if !out_sample_threshold.is_null() {
            let clamped = if sample_rate.is_nan() {
                0.0
            } else {
                sample_rate.clamp(0.0, 1.0)
            };
            unsafe {
                *out_sample_threshold = (clamped * 1_000_000.0) as i64;
            }
        }
        if !duration_ms.is_finite()
            || !query_ms.is_finite()
            || !slow_duration_threshold_ms.is_finite()
            || !slow_query_threshold_ms.is_finite()
        {
            return -1;
        }
        let slow_app = slow_duration_threshold_ms > 0.0 && duration_ms >= slow_duration_threshold_ms;
        let slow_db = slow_query_threshold_ms > 0.0 && query_ms >= slow_query_threshold_ms;
        (i32::from(slow_app)) | (i32::from(slow_db) << 1)
    }))
    .unwrap_or(-1)
}

// ============================================================================
// Wave 18 Worker 6: AbstractCategorizer, IrcNetworks, TmuxCommand,
// SettingsPageCatalog, and ClearanceMiddleware helpers
// ============================================================================

/// Resolves the default priority (`AbstractCategorizer::getPriority`) into `*out_priority`
/// and returns the default skip decision (`0` = `false` for `AbstractCategorizer::shouldSkip`),
/// or `-1` on invalid/unaligned pointers or out-of-bounds lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_abstract_categorizer_priority_and_skip(
    priority: i64,
    release_name: *const u8,
    release_name_len: usize,
    out_priority: *mut i64,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_priority.is_null() || !out_priority.is_aligned() {
            return -1;
        }
        if (unsafe { valid_slice_bounded(release_name, release_name_len, (isize::MAX as usize) / 4) })
            .is_none()
        {
            return -1;
        }
        unsafe {
            *out_priority = priority;
        }
        0
    }))
    .unwrap_or(-1)
}

/// Formats `CategorizationResult` scalar metadata for `AbstractCategorizer::matched`
/// (`is_no_match == 0`) and `AbstractCategorizer::noMatch` (`is_no_match != 0`).
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_abstract_categorizer_format_result_meta(
    is_no_match: u32,
    category_id: i64,
    confidence: f64,
    matched_by_ptr: *const u8,
    matched_by_len: usize,
    out_category_id: *mut i64,
    out_confidence: *mut f64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_category_id.is_null()
            || !out_category_id.is_aligned()
            || out_confidence.is_null()
            || !out_confidence.is_aligned()
        {
            return -1;
        }
        let Some(matched_by) = (unsafe {
            valid_slice_bounded(matched_by_ptr, matched_by_len, (isize::MAX as usize) / 4)
        }) else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };

        if is_no_match != 0 {
            unsafe {
                *out_category_id = CAT_OTHER_MISC;
                *out_confidence = 0.0;
            }
            out.push_slice(b"no_match");
            return finish_out_cursor_checked(out);
        }

        if !confidence.is_finite() {
            return -1;
        }
        unsafe {
            *out_category_id = category_id;
            *out_confidence = confidence;
        }
        out.push_slice(matched_by);
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Filters NUL-separated IRC network keys (`IrcNetworks::enabled`) using a parallel `flags`
/// byte array (`b'1'` = enabled, `b'0'` = disabled), writing the enabled keys separated by NUL
/// into `out_buf` and storing the number of enabled keys in `*out_count`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_irc_networks_filter_enabled_nul(
    keys_nul: *const u8,
    keys_len: usize,
    flags: *const u8,
    count: usize,
    out_count: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_count.is_null() || !out_count.is_aligned() {
            return -1;
        }
        let max_seg = (isize::MAX as usize) / 4;
        if count > max_seg {
            return -1;
        }
        let (Some(keys_s), Some(flags_s)) = (
            unsafe { valid_slice_bounded(keys_nul, keys_len, max_seg) },
            unsafe { valid_slice_bounded(flags, count, max_seg) },
        ) else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };

        if count == 0 {
            if keys_len != 0 {
                return -1;
            }
            unsafe {
                *out_count = 0;
            }
            return 0;
        }

        let nul_count = keys_s.iter().filter(|&&b| b == 0).count();
        if nul_count + 1 != count {
            return -1;
        }

        let mut kept: i64 = 0;
        for (idx, seg) in keys_s.split(|&b| b == 0).enumerate() {
            let flag = *flags_s.get(idx).unwrap_or(&b'0');
            if flag != b'0' {
                if kept > 0 {
                    out.push_byte(0);
                }
                out.push_slice(seg);
                kept += 1;
            }
        }
        unsafe {
            *out_count = kept;
        }
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

fn parse_php_serialized_uint(s: &[u8], pos: &mut usize) -> Option<usize> {
    let start = *pos;
    while *pos < s.len() && s[*pos].is_ascii_digit() {
        *pos += 1;
    }
    if *pos == start {
        return None;
    }
    let digits = &s[start..*pos];
    if digits.len() > 1 && digits[0] == b'0' {
        return None;
    }
    std::str::from_utf8(digits).ok()?.parse::<usize>().ok()
}

fn parse_php_serialized_string<'a>(s: &'a [u8], pos: &mut usize) -> Option<&'a [u8]> {
    if s.get(*pos..*pos + 2) != Some(b"s:") {
        return None;
    }
    *pos += 2;
    let len = parse_php_serialized_uint(s, pos)?;
    if s.get(*pos..*pos + 2) != Some(b":\"") {
        return None;
    }
    *pos += 2;
    let end = (*pos).checked_add(len)?;
    let bytes = s.get(*pos..end)?;
    if bytes.contains(&0) {
        return None;
    }
    *pos = end;
    if s.get(*pos..*pos + 2) != Some(b"\";") {
        return None;
    }
    *pos += 2;
    Some(bytes)
}

fn parse_php_serialized_channel_map(raw: &[u8]) -> Option<Vec<(Vec<u8>, Option<Vec<u8>>)>> {
    if raw.is_empty() || raw == b"0" {
        return Some(vec![(b"#PreNNTmux".to_vec(), None)]);
    }
    if raw.get(..2) != Some(b"a:") {
        return None;
    }
    let mut pos = 2usize;
    let count = parse_php_serialized_uint(raw, &mut pos)?;
    if count > 4096 || raw.get(pos..pos + 2) != Some(b":{") {
        return None;
    }
    pos += 2;
    if count == 0 {
        if raw.get(pos..) == Some(b"}") {
            return Some(vec![(b"#PreNNTmux".to_vec(), None)]);
        }
        return None;
    }

    let mut entries: Vec<(Vec<u8>, Option<Vec<u8>>)> = Vec::with_capacity(count);
    for _ in 0..count {
        let key = parse_php_serialized_string(raw, &mut pos)?;
        if is_canonical_php_int_key(key) {
            return None;
        }
        let val = if raw.get(pos..pos + 2) == Some(b"N;") {
            pos += 2;
            None
        } else {
            let v = parse_php_serialized_string(raw, &mut pos)?;
            Some(v.to_vec())
        };
        if let Some(existing) = entries.iter_mut().find(|(k, _)| k.as_slice() == key) {
            existing.1 = val;
        } else {
            entries.push((key.to_vec(), val));
        }
    }

    if raw.get(pos..) != Some(b"}") {
        return None;
    }
    if entries.is_empty() {
        return Some(vec![(b"#PreNNTmux".to_vec(), None)]);
    }
    Some(entries)
}

fn write_channel_pairs_nul(out: &mut OutCursor, entries: &[(Vec<u8>, Option<Vec<u8>>)]) {
    for (idx, (ch, pw)) in entries.iter().enumerate() {
        if idx > 0 {
            out.push_byte(0);
        }
        out.push_slice(ch);
        out.push_byte(0);
        match pw {
            None => out.push_byte(b'0'),
            Some(p) => {
                out.push_byte(b'1');
                out.push_slice(p);
            }
        }
    }
}

/// Parses `irc_settings.scrape_irc_channels` (`IrcNetworks::legacyChannels`) into NUL-separated
/// `(channel, '0' | '1'.password)` pairs and writes the pair count to `*out_count`.
/// Returns `-1` when declined to PHP `unserialize`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_irc_networks_parse_legacy_channels(
    input: *const u8,
    input_len: usize,
    out_count: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_count.is_null() || !out_count.is_aligned() {
            return -1;
        }
        let Some(raw) =
            (unsafe { valid_slice_bounded(input, input_len, (isize::MAX as usize) / 4) })
        else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };

        let Some(entries) = parse_php_serialized_channel_map(raw) else {
            return -1;
        };
        unsafe {
            *out_count = entries.len() as i64;
        }
        write_channel_pairs_nul(&mut out, &entries);
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Resolves an IRC or WebSocket network profile (`IrcNetworks::resolve`) for either the legacy
/// `synirc` network or a modern `irc_settings.networks.<key>` profile.
///
/// Writes:
/// - `*out_port`: resolved integer port
/// - `*out_flags`: bitmask (`bit 0` = `tls`, `bits 1..2` = `tls_verify_peer_name + 1` where
///   `0 = null, 1 = false, 2 = true`, `bit 3` = `has_source`, `bit 4` = `has_url`)
/// - `*out_channel_count`: number of channel entries
/// - `out_buf`: NUL-separated `name \0 type \0 format \0 source \0 server \0 url [ \0 ch \0 ('0'|'1'.pw) ... ]`
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_irc_networks_resolve_profile(
    key: *const u8,
    key_len: usize,
    has_type: u32,
    net_type: *const u8,
    net_type_len: usize,
    has_format: u32,
    format: *const u8,
    format_len: usize,
    has_source: u32,
    source: *const u8,
    source_len: usize,
    has_server: u32,
    server: *const u8,
    server_len: usize,
    has_port: u32,
    port: i64,
    has_tls: u32,
    tls: u32,
    has_tls_verify_peer_name: u32,
    tls_verify_peer_name: u32,
    has_url: u32,
    url: *const u8,
    url_len: usize,
    channels_raw: *const u8,
    channels_len: usize,
    channels_count: usize,
    out_port: *mut i64,
    out_flags: *mut i32,
    out_channel_count: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_port.is_null()
            || !out_port.is_aligned()
            || out_flags.is_null()
            || !out_flags.is_aligned()
            || out_channel_count.is_null()
            || !out_channel_count.is_aligned()
        {
            return -1;
        }
        let max_seg = (isize::MAX as usize) / 16;
        if channels_count > max_seg {
            return -1;
        }
        let (
            Some(key_s),
            Some(type_s),
            Some(format_s),
            Some(source_s),
            Some(server_s),
            Some(url_s),
            Some(chan_s),
        ) = (
            unsafe { valid_slice_bounded(key, key_len, max_seg) },
            unsafe { valid_slice_bounded(net_type, net_type_len, max_seg) },
            unsafe { valid_slice_bounded(format, format_len, max_seg) },
            unsafe { valid_slice_bounded(source, source_len, max_seg) },
            unsafe { valid_slice_bounded(server, server_len, max_seg) },
            unsafe { valid_slice_bounded(url, url_len, max_seg) },
            unsafe { valid_slice_bounded(channels_raw, channels_len, max_seg) },
        ) else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };
        if key_s.is_empty()
            || key_s.contains(&0)
            || type_s.contains(&0)
            || format_s.contains(&0)
            || source_s.contains(&0)
            || server_s.contains(&0)
            || url_s.contains(&0)
        {
            return -1;
        }

        if key_s == b"synirc" {
            let Some(channels) = parse_php_serialized_channel_map(chan_s) else {
                return -1;
            };
            let resolved_port = if has_port != 0 { port } else { 0 };
            let resolved_tls = i32::from(has_tls != 0 && tls != 0);
            unsafe {
                *out_port = resolved_port;
                *out_flags = resolved_tls;
                *out_channel_count = channels.len() as i64;
            }
            out.push_slice(key_s);
            out.push_byte(0);
            out.push_slice(b"irc\0nntmux\0\0");
            out.push_slice(if has_server != 0 { server_s } else { b"" });
            out.push_slice(b"\0");
            if !channels.is_empty() {
                out.push_byte(0);
                write_channel_pairs_nul(&mut out, &channels);
            }
            return finish_out_cursor_checked(out);
        }

        let mut channels: Vec<(Vec<u8>, Option<Vec<u8>>)> = Vec::with_capacity(channels_count);
        if channels_count == 0 {
            if !chan_s.is_empty() {
                return -1;
            }
        } else {
            let nul_count = chan_s.iter().filter(|&&b| b == 0).count();
            let Some(expected_parts) = channels_count.checked_mul(2) else {
                return -1;
            };
            if nul_count + 1 != expected_parts {
                return -1;
            }
            let mut iter = chan_s.split(|&b| b == 0);
            for _ in 0..channels_count {
                let (Some(ch), Some(pw)) = (iter.next(), iter.next()) else {
                    return -1;
                };
                if is_canonical_php_int_key(ch) {
                    return -1;
                }
                let norm_pw = if pw.is_empty() {
                    None
                } else {
                    Some(pw.to_vec())
                };
                channels.push((ch.to_vec(), norm_pw));
            }
        }

        let resolved_type: &[u8] = if has_type != 0 { type_s } else { b"irc" };
        let resolved_format: &[u8] = if has_format != 0 { format_s } else { b"" };
        let resolved_source: &[u8] = if has_source != 0 { source_s } else { key_s };
        let resolved_server: &[u8] = if has_server != 0 { server_s } else { b"" };
        let resolved_port: i64 = if has_port != 0 { port } else { 6667 };
        let resolved_tls = i32::from(has_tls != 0 && tls != 0);
        let tls_verify_state: i32 = if has_tls_verify_peer_name == 0 {
            0
        } else if tls_verify_peer_name != 0 {
            2
        } else {
            1
        };
        let has_url_bit = i32::from(has_url != 0);
        let resolved_url: &[u8] = if has_url != 0 { url_s } else { b"" };

        unsafe {
            *out_port = resolved_port;
            *out_flags = resolved_tls | (tls_verify_state << 1) | (1 << 3) | (has_url_bit << 4);
            *out_channel_count = channels.len() as i64;
        }

        out.push_slice(key_s);
        out.push_byte(0);
        out.push_slice(resolved_type);
        out.push_byte(0);
        out.push_slice(resolved_format);
        out.push_byte(0);
        out.push_slice(resolved_source);
        out.push_byte(0);
        out.push_slice(resolved_server);
        out.push_byte(0);
        out.push_slice(resolved_url);
        if !channels.is_empty() {
            out.push_byte(0);
            write_channel_pairs_nul(&mut out, &channels);
        }
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Builds the NUL-separated `TmuxCommand::arguments` argv list:
/// `['tmux', '-u', ...($socket === '' ? [] : ['-L', $socket]), ...$arguments]`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_command_arguments_nul(
    socket: *const u8,
    socket_len: usize,
    args_nul: *const u8,
    args_len: usize,
    args_count: usize,
    out_count: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_count.is_null() || !out_count.is_aligned() {
            return -1;
        }
        let max_seg = (isize::MAX as usize) / 4;
        if args_count > max_seg {
            return -1;
        }
        let (Some(socket_s), Some(args_s)) = (
            unsafe { valid_slice_bounded(socket, socket_len, max_seg) },
            unsafe { valid_slice_bounded(args_nul, args_len, max_seg) },
        ) else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };
        if socket_s.contains(&0) {
            return -1;
        }
        if args_count == 0 {
            if !args_s.is_empty() {
                return -1;
            }
        } else {
            let nul_count = args_s.iter().filter(|&&b| b == 0).count();
            if nul_count + 1 != args_count {
                return -1;
            }
        }

        let base_count = if socket_s.is_empty() { 2usize } else { 4usize };
        let Some(total_count) = base_count.checked_add(args_count) else {
            return -1;
        };
        if total_count > i64::MAX as usize {
            return -1;
        }

        out.push_slice(b"tmux\0-u");
        if !socket_s.is_empty() {
            out.push_slice(b"\0-L\0");
            out.push_slice(socket_s);
        }
        if args_count > 0 {
            out.push_byte(0);
            out.push_slice(args_s);
        }
        unsafe {
            *out_count = total_count as i64;
        }
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Builds the NUL-separated 4-element `TmuxCommand::monitor` argv list:
/// `[PHP_BINARY, base_path('artisan'), 'tmux:monitor', '--session='.$session]`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_command_monitor_nul(
    php_binary: *const u8,
    php_binary_len: usize,
    artisan_path: *const u8,
    artisan_path_len: usize,
    session: *const u8,
    session_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let max_seg = (isize::MAX as usize) / 8;
        let (Some(php_s), Some(artisan_s), Some(session_s)) = (
            unsafe { valid_slice_bounded(php_binary, php_binary_len, max_seg) },
            unsafe { valid_slice_bounded(artisan_path, artisan_path_len, max_seg) },
            unsafe { valid_slice_bounded(session, session_len, max_seg) },
        ) else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };
        if php_s.contains(&0) || artisan_s.contains(&0) || session_s.contains(&0) {
            return -1;
        }

        out.push_slice(php_s);
        out.push_byte(0);
        out.push_slice(artisan_s);
        out.push_slice(b"\0tmux:monitor\0--session=");
        out.push_slice(session_s);
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

const SETTINGS_SITE_COLS: &[&[u8]] = &[
    b"title",
    b"home_link",
    b"site_logo",
    b"strapline",
    b"meta_title",
    b"meta_description",
    b"meta_keywords",
    b"footer",
    b"dereferrer_link",
    b"terms",
    b"trailers_display",
    b"trailers_size_x",
    b"trailers_size_y",
];

const SETTINGS_REGISTRATION_COLS: &[&[u8]] = &[b"status"];

const SETTINGS_INGESTION_COLS: &[&[u8]] = &[
    b"binary_threads",
    b"backfill_threads",
    b"release_threads",
    b"collection_delay_hours",
    b"collection_timeout_hours",
    b"cross_post_hours",
    b"completion_percent",
    b"grab_status",
    b"max_headers_per_iteration",
    b"max_messages",
    b"max_releases_created",
    b"nntp_retries",
    b"nzb_split_level",
    b"part_retention_hours",
    b"release_retention_days",
    b"misc_other_retention_hours",
    b"misc_hashed_retention_hours",
    b"min_files_to_form_release",
    b"min_size_to_form_release",
    b"max_size_to_form_release",
    b"new_group_scan_method",
    b"new_group_days_to_scan",
    b"new_group_messages_to_scan",
    b"safe_backfill_date",
    b"disable_backfill_group",
    b"part_repair",
    b"safe_part_repair",
    b"max_part_repair",
    b"part_repair_max_tries",
    b"categorize_foreign",
    b"categorize_web_dl",
    b"show_passworded_releases",
    b"delete_passworded_releases",
    b"backfill_days_mode",
    b"backfill_order",
    b"backfill_quantity",
];

const SETTINGS_POST_PROCESSING_COLS: &[&[u8]] = &[
    b"post_threads",
    b"post_threads_amazon",
    b"post_threads_non",
    b"nfo_threads",
    b"fix_name_threads",
    b"timeout_seconds",
    b"release_timeout_seconds",
    b"max_timeout_count",
    b"max_additional_processed",
    b"max_parts_processed",
    b"password_check_attempts",
    b"fix_names_per_run",
    b"max_nested_levels",
    b"extract_using_rar_info",
    b"segments_to_download",
    b"ffmpeg_duration",
    b"inner_file_blacklist",
    b"process_jpg",
    b"process_thumbnails",
    b"process_videos",
    b"save_audio_preview",
    b"min_size_to_post_process",
    b"max_size_to_post_process",
    b"min_size_to_process_nfo",
    b"max_size_to_process_nfo",
    b"max_nfo_processed",
    b"max_nfo_retries",
    b"lookup_nfo",
    b"lookup_par2",
];

const SETTINGS_METADATA_COLS: &[&[u8]] = &[
    b"anime_lookup",
    b"book_lookup",
    b"game_lookup",
    b"movie_lookup",
    b"music_lookup",
    b"tv_lookup",
    b"movie_language",
    b"imdb_alternate_url",
    b"max_anime_processed",
    b"max_books_processed",
    b"max_games_processed",
    b"max_movies_processed",
    b"max_music_processed",
    b"max_tv_processed",
    b"amazon_sleep_milliseconds",
];

const SETTINGS_TMUX_COLS: &[&[u8]] = &[
    b"session_name",
    b"monitor_delay",
    b"niceness",
    b"sequential_mode",
    b"sequential_timer",
    b"binaries_enabled",
    b"binaries_timer",
    b"binaries_kill_timer",
    b"backfill_mode",
    b"backfill_groups",
    b"backfill_timer",
    b"progressive_backfill",
    b"releases_enabled",
    b"release_timer",
    b"post_mode",
    b"post_timer",
    b"post_kill_timer",
    b"post_amazon_mode",
    b"post_amazon_timer",
    b"post_non_mode",
    b"post_non_timer",
    b"fix_names_enabled",
    b"fix_timer",
    b"cleanup_mode",
    b"cleanup_timer",
    b"run_irc_scraper",
    b"console_enabled",
    b"htop_enabled",
    b"mytop_enabled",
    b"nmon_enabled",
    b"vnstat_enabled",
    b"vnstat_args",
    b"tcp_track_enabled",
    b"tcp_track_args",
    b"bwmng_enabled",
    b"redis_enabled",
    b"redis_args",
    b"write_logs",
    b"collections_kill_threshold",
    b"post_process_kill_threshold",
    b"colors_start",
    b"colors_end",
    b"cleanup_rules",
    b"color_exclusions",
];

const SETTINGS_BOOLEANS: &[&[u8]] = &[
    b"trailers_display",
    b"grab_status",
    b"disable_backfill_group",
    b"part_repair",
    b"safe_part_repair",
    b"categorize_foreign",
    b"categorize_web_dl",
    b"show_passworded_releases",
    b"delete_passworded_releases",
    b"extract_using_rar_info",
    b"process_jpg",
    b"process_thumbnails",
    b"process_videos",
    b"save_audio_preview",
    b"lookup_nfo",
    b"lookup_par2",
    b"imdb_alternate_url",
    b"binaries_enabled",
    b"progressive_backfill",
    b"releases_enabled",
    b"fix_names_enabled",
    b"run_irc_scraper",
    b"console_enabled",
    b"htop_enabled",
    b"mytop_enabled",
    b"nmon_enabled",
    b"vnstat_enabled",
    b"tcp_track_enabled",
    b"bwmng_enabled",
    b"redis_enabled",
    b"write_logs",
];

const SETTINGS_BYTES: &[&[u8]] = &[
    b"min_size_to_form_release",
    b"max_size_to_form_release",
    b"min_size_to_post_process",
    b"max_size_to_post_process",
    b"min_size_to_process_nfo",
    b"max_size_to_process_nfo",
];

const SETTINGS_TEXTAREAS: &[&[u8]] = &[
    b"meta_description",
    b"meta_keywords",
    b"footer",
    b"terms",
];

const SETTINGS_NULLABLE: &[&[u8]] = &[
    b"site_logo",
    b"vnstat_args",
    b"tcp_track_args",
    b"redis_args",
];

const SETTINGS_POSITIVE_INTEGERS: &[&[u8]] = &[
    b"trailers_size_x",
    b"trailers_size_y",
    b"binary_threads",
    b"backfill_threads",
    b"release_threads",
    b"collection_timeout_hours",
    b"max_headers_per_iteration",
    b"max_messages",
    b"max_releases_created",
    b"backfill_quantity",
    b"post_threads",
    b"post_threads_amazon",
    b"post_threads_non",
    b"nfo_threads",
    b"fix_name_threads",
    b"release_timeout_seconds",
    b"max_timeout_count",
    b"max_additional_processed",
    b"max_parts_processed",
    b"password_check_attempts",
    b"fix_names_per_run",
    b"max_nested_levels",
    b"segments_to_download",
    b"ffmpeg_duration",
    b"max_nfo_processed",
    b"max_anime_processed",
    b"max_books_processed",
    b"max_games_processed",
    b"max_movies_processed",
    b"max_music_processed",
    b"max_tv_processed",
    b"monitor_delay",
    b"sequential_timer",
    b"binaries_timer",
    b"backfill_groups",
    b"backfill_timer",
    b"release_timer",
    b"post_timer",
    b"post_amazon_timer",
    b"post_non_timer",
    b"fix_timer",
    b"cleanup_timer",
];

const SETTINGS_CLEANUP_RULES: &[(&[u8], &[u8])] = &[
    (b"blacklist", b"Blacklist"),
    (b"blfiles", b"Blfiles"),
    (b"executable", b"Executable"),
    (b"gibberish", b"Gibberish"),
    (b"hashed", b"Hashed"),
    (b"installbin", b"Installbin"),
    (b"passworded", b"Passworded"),
    (b"passwordurl", b"Passwordurl"),
    (b"sample", b"Sample"),
    (b"scr", b"Scr"),
    (b"short", b"Short"),
    (b"size", b"Size"),
    (b"nzb", b"Nzb"),
    (b"codec", b"Codec"),
];

#[inline]
fn settings_domain_columns(domain: &[u8]) -> Option<&'static [&'static [u8]]> {
    match domain {
        b"site" => Some(SETTINGS_SITE_COLS),
        b"registration" => Some(SETTINGS_REGISTRATION_COLS),
        b"ingestion" => Some(SETTINGS_INGESTION_COLS),
        b"post-processing" => Some(SETTINGS_POST_PROCESSING_COLS),
        b"metadata" => Some(SETTINGS_METADATA_COLS),
        b"tmux" => Some(SETTINGS_TMUX_COLS),
        _ => None,
    }
}

#[inline]
fn settings_domain_guidance(domain: &[u8]) -> Option<&'static [u8]> {
    match domain {
        b"site" | b"registration" | b"metadata" => Some(b"Takes effect immediately."),
        b"tmux" => Some(
            b"The monitor reloads this value on its next cycle; layout changes require a tmux restart.",
        ),
        b"ingestion" | b"post-processing" => {
            Some(b"Restart active workers after changing this value.")
        }
        _ => None,
    }
}

#[inline]
fn settings_column_options(column: &[u8]) -> &'static [(&'static [u8], &'static [u8])] {
    if column == b"status" {
        return &[
            (b"0", b"Open"),
            (b"1", b"Invite"),
            (b"2", b"Closed"),
        ];
    }
    if column.ends_with(b"_lookup") {
        return &[
            (b"0", b"Disabled"),
            (b"1", b"All releases"),
            (b"2", b"Renamed releases only"),
        ];
    }
    match column {
        b"new_group_scan_method" => &[(b"0", b"Posts"), (b"1", b"Days")],
        b"backfill_days_mode" => &[
            (b"1", b"Days per group"),
            (b"2", b"Safe backfill date"),
        ],
        b"backfill_order" => &[
            (b"1", b"Newest"),
            (b"2", b"Oldest"),
            (b"3", b"Alphabetical"),
            (b"4", b"Alphabetical reverse"),
            (b"5", b"Most posts"),
            (b"6", b"Fewest posts"),
        ],
        b"sequential_mode" => &[
            (b"0", b"Full"),
            (b"1", b"Basic"),
            (b"2", b"Stripped"),
        ],
        b"backfill_mode" => &[
            (b"0", b"Disabled"),
            (b"1", b"All"),
            (b"4", b"Safe"),
        ],
        b"post_non_mode" | b"post_amazon_mode" => &[(b"0", b"Disabled"), (b"1", b"Enabled")],
        b"post_mode" => &[
            (b"0", b"Disabled"),
            (b"1", b"Additional"),
            (b"2", b"NFO"),
            (b"3", b"All"),
        ],
        _ => &[],
    }
}

#[inline]
fn settings_column_unit(column: &[u8]) -> Option<&'static [u8]> {
    if column.ends_with(b"_seconds") {
        Some(b"seconds")
    } else if column.ends_with(b"_hours") {
        Some(b"hours")
    } else if column.ends_with(b"_days") {
        Some(b"days")
    } else if column.ends_with(b"_milliseconds") {
        Some(b"milliseconds")
    } else if column.ends_with(b"_timer") || column == b"monitor_delay" {
        Some(b"seconds")
    } else {
        None
    }
}

#[inline]
fn slice_in_table(needle: &[u8], table: &[&[u8]]) -> bool {
    table.iter().any(|&item| item == needle)
}

#[inline]
fn settings_column_is_integer(column: &[u8]) -> bool {
    if slice_in_table(column, SETTINGS_BOOLEANS)
        || slice_in_table(column, SETTINGS_BYTES)
        || slice_in_table(column, SETTINGS_TEXTAREAS)
        || slice_in_table(column, SETTINGS_NULLABLE)
        || matches!(
            column,
            b"safe_backfill_date"
                | b"inner_file_blacklist"
                | b"title"
                | b"home_link"
                | b"strapline"
                | b"meta_title"
                | b"dereferrer_link"
                | b"movie_language"
                | b"session_name"
                | b"cleanup_mode"
        )
    {
        return false;
    }
    settings_column_options(column).is_empty()
}

fn titlecase_ascii_into(part: &[u8], out: &mut Vec<u8>) {
    let mut prev_alpha = false;
    for &b in part {
        if b.is_ascii_alphabetic() {
            if prev_alpha {
                out.push(b.to_ascii_lowercase());
            } else {
                out.push(b.to_ascii_uppercase());
            }
            prev_alpha = true;
        } else {
            out.push(b);
            prev_alpha = false;
        }
    }
}

/// Computes `Illuminate\Support\Str::headline($value)` for ASCII identifier/whitespace strings,
/// or returns `None` when non-ASCII or punctuation bytes are present so PHP can run `Str::headline`.
fn str_headline_ascii(value: &[u8]) -> Option<Vec<u8>> {
    if !value.iter().all(|&b| {
        b.is_ascii_alphanumeric()
            || matches!(b, b'_' | b'-' | b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c)
    }) {
        return None;
    }

    let mut ws_parts: Vec<&[u8]> = Vec::new();
    let mut i = 0usize;
    while i < value.len() {
        while i < value.len() && is_ascii_ws(value[i]) {
            i += 1;
        }
        let start = i;
        while i < value.len() && !is_ascii_ws(value[i]) {
            i += 1;
        }
        if start < i {
            ws_parts.push(&value[start..i]);
        }
    }

    let mut joined = Vec::with_capacity(value.len() + 8);
    if ws_parts.len() > 1 {
        for (idx, part) in ws_parts.iter().enumerate() {
            if idx > 0 {
                joined.push(b'_');
            }
            titlecase_ascii_into(part, &mut joined);
        }
    } else if let Some(&single) = ws_parts.first() {
        let mut piece_start = 0usize;
        let mut first_piece = true;
        for idx in 1..single.len() {
            if single[idx].is_ascii_uppercase() {
                if !first_piece {
                    joined.push(b'_');
                }
                titlecase_ascii_into(&single[piece_start..idx], &mut joined);
                first_piece = false;
                piece_start = idx;
            }
        }
        if piece_start < single.len() {
            if !first_piece {
                joined.push(b'_');
            }
            titlecase_ascii_into(&single[piece_start..], &mut joined);
        }
    }

    for b in &mut joined {
        if *b == b'-' || *b == b' ' {
            *b = b'_';
        }
    }

    let mut out = Vec::with_capacity(joined.len());
    for seg in joined.split(|&b| b == b'_') {
        if seg.is_empty() || seg == b"0" {
            continue;
        }
        if !out.is_empty() {
            out.push(b' ');
        }
        out.extend_from_slice(seg);
    }
    Some(out)
}

fn settings_column_help(column: &[u8]) -> Option<Vec<u8>> {
    match column {
        b"home_link" => Some(b"Home path or absolute URL used by site navigation.".to_vec()),
        b"dereferrer_link" => {
            Some(b"Optional URL prefix placed before outbound metadata links.".to_vec())
        }
        b"completion_percent" => {
            Some(b"Minimum completion percentage retained during release processing.".to_vec())
        }
        b"monitor_delay" => Some(b"Seconds between tmux monitor refresh cycles.".to_vec()),
        b"movie_language" => Some(b"ISO language code used for movie metadata lookup.".to_vec()),
        b"movie_lookup" => {
            Some(b"Choose which releases receive movie metadata processing.".to_vec())
        }
        b"max_movies_processed" => Some(b"Maximum movies processed per run.".to_vec()),
        b"post_non_mode" => Some(
            b"Enable the tmux TV, anime and movie panes. Movie Lookup must also be enabled for the Movies pane to process releases."
                .to_vec(),
        ),
        b"post_amazon_mode" => Some(
            b"Enable the tmux book, music, console and game panes. Each metadata lookup must also be enabled."
                .to_vec(),
        ),
        b"extract_using_rar_info" => Some(
            b"Use the built-in PHP RAR/ZIP header parser only. Lists inner files without running unrar/7z, which disables media extraction (mediainfo, samples, and previews)."
                .to_vec(),
        ),
        _ => {
            let mut headline = str_headline_ascii(column)?;
            for b in &mut headline {
                *b = b.to_ascii_lowercase();
            }
            let mut help = Vec::with_capacity(10 + headline.len());
            help.extend_from_slice(b"Controls ");
            help.extend_from_slice(&headline);
            help.push(b'.');
            Some(help)
        }
    }
}

fn settings_column_label(column: &[u8]) -> Option<Vec<u8>> {
    match column {
        b"movie_lookup" => Some(b"Process Movies".to_vec()),
        b"post_non_mode" => Some(b"TV, Anime and Movie Panes".to_vec()),
        b"post_amazon_mode" => Some(b"Book, Music, Console and Game Panes".to_vec()),
        b"extract_using_rar_info" => {
            Some(b"List Archives Without Extracting (Disables Media Extraction)".to_vec())
        }
        _ => str_headline_ascii(column),
    }
}

/// Writes the NUL-separated writable columns for a `ConfigurationDomain`
/// (`SettingsPageCatalog::writableColumns`) into `out_buf` and stores the count in `*out_count`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_settings_catalog_writable_columns_nul(
    domain: *const u8,
    domain_len: usize,
    out_count: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_count.is_null() || !out_count.is_aligned() {
            return -1;
        }
        let Some(domain_s) =
            (unsafe { valid_slice_bounded(domain, domain_len, (isize::MAX as usize) / 4) })
        else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };
        let Some(cols) = settings_domain_columns(domain_s) else {
            return -1;
        };

        let mut count = 0i64;
        for &col in cols {
            if col == b"cleanup_rules" || col == b"color_exclusions" {
                continue;
            }
            if count > 0 {
                out.push_byte(0);
            }
            out.push_slice(col);
            count += 1;
        }
        unsafe {
            *out_count = count;
        }
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Writes the NUL-separated `SettingsPageCatalog::cleanupRules()` list into `out_buf`
/// and stores the rule count (`14`) in `*out_count`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_settings_catalog_cleanup_rules_nul(
    out_count: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_count.is_null() || !out_count.is_aligned() {
            return -1;
        }
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };
        for (idx, (rule, _)) in SETTINGS_CLEANUP_RULES.iter().enumerate() {
            if idx > 0 {
                out.push_byte(0);
            }
            out.push_slice(rule);
        }
        unsafe {
            *out_count = SETTINGS_CLEANUP_RULES.len() as i64;
        }
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Resolves `SettingsPageCatalog::unit(string $column)` into `out_buf` (or returns `0` for `null`).
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_settings_catalog_unit(
    column: *const u8,
    column_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(col_s) =
            (unsafe { valid_slice_bounded(column, column_len, (isize::MAX as usize) / 4) })
        else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };
        let Some(unit) = settings_column_unit(col_s) else {
            return 0;
        };
        out.push_slice(unit);
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Resolves `SettingsPageCatalog::options(string $column)` as NUL-separated `key \0 label` pairs
/// and writes the pair count to `*out_count` (`0` when the column has no enum options).
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_settings_catalog_options_nul(
    column: *const u8,
    column_len: usize,
    out_count: *mut i64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_count.is_null() || !out_count.is_aligned() {
            return -1;
        }
        let Some(col_s) =
            (unsafe { valid_slice_bounded(column, column_len, (isize::MAX as usize) / 4) })
        else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };
        let opts = settings_column_options(col_s);
        unsafe {
            *out_count = opts.len() as i64;
        }
        if opts.is_empty() {
            return 0;
        }
        for (idx, (k, v)) in opts.iter().enumerate() {
            if idx > 0 {
                out.push_byte(0);
            }
            out.push_slice(k);
            out.push_byte(0);
            out.push_slice(v);
        }
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Evaluates `SettingsPageCatalog::isInteger(ConfigurationDomain $domain, string $column)`.
/// Returns `1` (`true`), `0` (`false`), or `-1` on invalid arguments.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_settings_catalog_is_integer(
    domain: *const u8,
    domain_len: usize,
    column: *const u8,
    column_len: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let max_seg = (isize::MAX as usize) / 4;
        let (Some(domain_s), Some(col_s)) = (
            unsafe { valid_slice_bounded(domain, domain_len, max_seg) },
            unsafe { valid_slice_bounded(column, column_len, max_seg) },
        ) else {
            return -1;
        };
        if settings_domain_columns(domain_s).is_none() {
            return -1;
        }
        i32::from(settings_column_is_integer(col_s))
    }))
    .unwrap_or(-1)
}

/// Resolves `SettingsPageCatalog::help(string $column)` into `out_buf`, or returns `-1` when
/// declined to PHP `Str::headline`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_settings_catalog_help(
    column: *const u8,
    column_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(col_s) =
            (unsafe { valid_slice_bounded(column, column_len, (isize::MAX as usize) / 4) })
        else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };
        let Some(help) = settings_column_help(col_s) else {
            return -1;
        };
        out.push_slice(&help);
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Resolves the complete `ConfigurationField` metadata for `SettingsPageCatalog::field($domain, $column)`.
///
/// Writes:
/// - `*out_rules_count`: number of validation rule strings `R`
/// - `*out_options_count`: number of `(key, label)` option pairs `M`
/// - `*out_options_int_keys`: `1` when option keys are `int`, `0` when option keys are `string`
/// - `out_buf`: NUL-separated `label \0 help \0 control \0 unit \0 guidance [ \0 rule_i ... ] [ \0 opt_k \0 opt_v ... ]`
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_settings_catalog_field_nul(
    domain: *const u8,
    domain_len: usize,
    column: *const u8,
    column_len: usize,
    out_rules_count: *mut i64,
    out_options_count: *mut i64,
    out_options_int_keys: *mut i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        if out_rules_count.is_null()
            || !out_rules_count.is_aligned()
            || out_options_count.is_null()
            || !out_options_count.is_aligned()
            || out_options_int_keys.is_null()
            || !out_options_int_keys.is_aligned()
        {
            return -1;
        }
        let max_seg = (isize::MAX as usize) / 8;
        let (Some(domain_s), Some(col_s)) = (
            unsafe { valid_slice_bounded(domain, domain_len, max_seg) },
            unsafe { valid_slice_bounded(column, column_len, max_seg) },
        ) else {
            return -1;
        };
        if col_s.contains(&0) {
            return -1;
        }
        let Some(guidance) = settings_domain_guidance(domain_s) else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };

        let emit_field = |out: &mut OutCursor,
                          label: &[u8],
                          help: &[u8],
                          control: &[u8],
                          unit: &[u8],
                          guidance: &[u8],
                          rules: &[&[u8]],
                          options: &[(&[u8], &[u8])],
                          int_keys: i32| {
            unsafe {
                *out_rules_count = rules.len() as i64;
                *out_options_count = options.len() as i64;
                *out_options_int_keys = int_keys;
            }
            out.push_slice(label);
            out.push_byte(0);
            out.push_slice(help);
            out.push_byte(0);
            out.push_slice(control);
            out.push_byte(0);
            out.push_slice(unit);
            out.push_byte(0);
            out.push_slice(guidance);
            for &r in rules {
                out.push_byte(0);
                out.push_slice(r);
            }
            for &(k, v) in options {
                out.push_byte(0);
                out.push_slice(k);
                out.push_byte(0);
                out.push_slice(v);
            }
        };

        if col_s == b"site_logo" {
            let rules: &[&[u8]] = &[
                b"nullable",
                b"image",
                b"mimes:png,jpg,jpeg,webp",
                b"max:2048",
            ];
            emit_field(
                &mut out,
                b"Site Logo",
                b"PNG, JPEG, or WebP image, up to 2 MB.",
                b"upload",
                b"",
                guidance,
                rules,
                &[],
                0,
            );
            return finish_out_cursor_checked(out);
        }

        if col_s == b"cleanup_rules" {
            let rules: &[&[u8]] = &[b"nullable", b"array"];
            emit_field(
                &mut out,
                b"Cleanup Rules",
                b"Select release cleanup checks to run.",
                b"multiselect",
                b"",
                guidance,
                rules,
                SETTINGS_CLEANUP_RULES,
                0,
            );
            return finish_out_cursor_checked(out);
        }

        if col_s == b"color_exclusions" {
            let rules: &[&[u8]] = &[b"nullable", b"string"];
            emit_field(
                &mut out,
                b"Color Exclusions",
                b"Comma-separated tmux color indexes from 0 through 255.",
                b"number-list",
                b"",
                guidance,
                rules,
                &[],
                0,
            );
            return finish_out_cursor_checked(out);
        }

        let Some(label) = settings_column_label(col_s) else {
            return -1;
        };

        let opts = settings_column_options(col_s);
        if !opts.is_empty() {
            let Some(help) = settings_column_help(col_s) else {
                return -1;
            };
            let mut in_rule = b"in:".to_vec();
            for (idx, (k, _)) in opts.iter().enumerate() {
                if idx > 0 {
                    in_rule.push(b',');
                }
                in_rule.extend_from_slice(k);
            }
            let rules: &[&[u8]] = &[b"required", b"integer", in_rule.as_slice()];
            emit_field(
                &mut out,
                &label,
                &help,
                b"enum",
                b"",
                guidance,
                rules,
                opts,
                1,
            );
            return finish_out_cursor_checked(out);
        }

        if slice_in_table(col_s, SETTINGS_BOOLEANS) {
            let Some(help) = settings_column_help(col_s) else {
                return -1;
            };
            let rules: &[&[u8]] = &[b"required", b"boolean"];
            let bool_opts: &[(&[u8], &[u8])] = &[(b"1", b"Enabled"), (b"0", b"Disabled")];
            emit_field(
                &mut out,
                &label,
                &help,
                b"boolean",
                b"",
                guidance,
                rules,
                bool_opts,
                1,
            );
            return finish_out_cursor_checked(out);
        }

        if slice_in_table(col_s, SETTINGS_BYTES) {
            let Some(help) = settings_column_help(col_s) else {
                return -1;
            };
            let rules: &[&[u8]] = &[b"required", b"numeric", b"min:0"];
            emit_field(
                &mut out,
                &label,
                &help,
                b"bytes",
                b"MB/GB",
                guidance,
                rules,
                &[],
                0,
            );
            return finish_out_cursor_checked(out);
        }

        if col_s == b"safe_backfill_date" {
            let rules: &[&[u8]] = &[b"required", b"date_format:Y-m-d"];
            emit_field(
                &mut out,
                &label,
                b"Oldest date allowed for safe backfill.",
                b"date",
                b"",
                guidance,
                rules,
                &[],
                0,
            );
            return finish_out_cursor_checked(out);
        }

        if col_s == b"inner_file_blacklist" {
            let rules: &[&[u8]] = &[b"required", b"string", b"max:2000"];
            emit_field(
                &mut out,
                &label,
                b"Regular expression used to reject unsafe inner filenames.",
                b"regex",
                b"",
                guidance,
                rules,
                &[],
                0,
            );
            return finish_out_cursor_checked(out);
        }

        if slice_in_table(col_s, SETTINGS_TEXTAREAS) {
            let Some(help) = settings_column_help(col_s) else {
                return -1;
            };
            let (control, rules): (&[u8], &[&[u8]]) = if col_s == b"terms" {
                (b"rich-text", &[b"nullable", b"string", b"max:65000"])
            } else {
                (b"textarea", &[b"required", b"string", b"max:65000"])
            };
            emit_field(
                &mut out,
                &label,
                &help,
                control,
                b"",
                guidance,
                rules,
                &[],
                0,
            );
            return finish_out_cursor_checked(out);
        }

        if settings_column_is_integer(col_s) {
            let Some(help) = settings_column_help(col_s) else {
                return -1;
            };
            let min_rule: &[u8] = if col_s == b"niceness" {
                b"min:-20"
            } else if slice_in_table(col_s, SETTINGS_POSITIVE_INTEGERS) {
                b"min:1"
            } else {
                b"min:0"
            };
            let max_rule: Option<&[u8]> = match col_s {
                b"completion_percent" => Some(b"max:100"),
                b"niceness" => Some(b"max:19"),
                b"colors_start" | b"colors_end" => Some(b"max:255"),
                _ => None,
            };
            let unit = settings_column_unit(col_s).unwrap_or(b"");
            if let Some(mx) = max_rule {
                let rules: &[&[u8]] = &[b"required", b"integer", min_rule, mx];
                emit_field(
                    &mut out,
                    &label,
                    &help,
                    b"integer",
                    unit,
                    guidance,
                    rules,
                    &[],
                    0,
                );
            } else {
                let rules: &[&[u8]] = &[b"required", b"integer", min_rule];
                emit_field(
                    &mut out,
                    &label,
                    &help,
                    b"integer",
                    unit,
                    guidance,
                    rules,
                    &[],
                    0,
                );
            }
            return finish_out_cursor_checked(out);
        }

        let Some(help) = settings_column_help(col_s) else {
            return -1;
        };
        let is_nullable = slice_in_table(col_s, SETTINGS_NULLABLE) || col_s == b"dereferrer_link";
        let rules: &[&[u8]] = if is_nullable {
            &[b"nullable", b"string", b"max:1000"]
        } else {
            &[b"required", b"string", b"max:1000"]
        };
        let control: &[u8] = if col_s == b"dereferrer_link" {
            b"url"
        } else {
            b"text"
        };
        emit_field(
            &mut out,
            &label,
            &help,
            control,
            b"",
            guidance,
            rules,
            &[],
            0,
        );
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

/// Evaluates `ClearanceMiddleware::matchesCategoryPath(string $path, string $category)`
/// case-insensitively against `<cat>`, `<cat>/*`, `browse/<cat>`, and `browse/<cat>/*`.
/// Returns `1` on match, `0` on non-match, or `-1` on invalid pointer/length arguments.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_clearance_matches_category_path(
    path: *const u8,
    path_len: usize,
    category: *const u8,
    category_len: usize,
) -> i32 {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let max_seg = (isize::MAX as usize) / 4;
        let (Some(path_s), Some(cat_s)) = (
            unsafe { valid_slice_bounded(path, path_len, max_seg) },
            unsafe { valid_slice_bounded(category, category_len, max_seg) },
        ) else {
            return -1;
        };

        let clen = cat_s.len();
        let plen = path_s.len();

        let direct_match = (plen == clen && path_s.eq_ignore_ascii_case(cat_s))
            || (plen > clen
                && path_s[clen] == b'/'
                && path_s[..clen].eq_ignore_ascii_case(cat_s));
        if direct_match {
            return 1;
        }

        let browse_cat_len = match 7usize.checked_add(clen) {
            Some(v) => v,
            None => return 0,
        };
        let browse_match = plen >= browse_cat_len
            && path_s[..7].eq_ignore_ascii_case(b"browse/")
            && path_s[7..browse_cat_len].eq_ignore_ascii_case(cat_s)
            && (plen == browse_cat_len || path_s[browse_cat_len] == b'/');

        i32::from(browse_match)
    }))
    .unwrap_or(-1)
}

/// Resolves the required permission and blocked category display name for
/// `ClearanceMiddleware::checkMainCategoryPermission` as `"<permission>\0<display_name>"`,
/// or returns `0` when the category has no main-category permission mapping.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_clearance_resolve_main_category(
    parent_category: *const u8,
    parent_category_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        let Some(cat_s) = (unsafe {
            valid_slice_bounded(parent_category, parent_category_len, (isize::MAX as usize) / 4)
        }) else {
            return -1;
        };
        let Some(mut out) = valid_out_cursor_checked(out_buf, out_cap) else {
            return -1;
        };

        let resolved: Option<&[u8]> = if cat_s.eq_ignore_ascii_case(b"movies") {
            Some(b"view movies\0Movies")
        } else if cat_s.eq_ignore_ascii_case(b"console") {
            Some(b"view console\0Console")
        } else if cat_s.eq_ignore_ascii_case(b"books") {
            Some(b"view books\0Books")
        } else if cat_s.eq_ignore_ascii_case(b"audio") {
            Some(b"view audio\0Audio")
        } else if cat_s.eq_ignore_ascii_case(b"xxx") {
            Some(b"view adult\0Adult")
        } else if cat_s.eq_ignore_ascii_case(b"games") || cat_s.eq_ignore_ascii_case(b"pc") {
            Some(b"view pc\0PC")
        } else if cat_s.eq_ignore_ascii_case(b"tv") {
            Some(b"view tv\0TV")
        } else {
            None
        };

        let Some(pair) = resolved else {
            return 0;
        };
        out.push_slice(pair);
        finish_out_cursor_checked(out)
    }))
    .unwrap_or(-1)
}

#[cfg(test)]
mod wave18_worker6_tests {
    use super::*;

    #[test]
    fn abstract_categorizer_and_clearance_helpers() {
        let mut prio = 0i64;
        assert_eq!(
            unsafe {
                nntmux_abstract_categorizer_priority_and_skip(50, b"Ubuntu.ISO".as_ptr(), 10, &mut prio)
            },
            0
        );
        assert_eq!(prio, 50);
        assert_eq!(
            unsafe {
                nntmux_abstract_categorizer_priority_and_skip(50, std::ptr::null(), 5, &mut prio)
            },
            -1
        );

        let mut cat = 0i64;
        let mut conf = 0.0f64;
        let mut buf = [0u8; 64];
        let n = unsafe {
            nntmux_abstract_categorizer_format_result_meta(
                0,
                2040,
                0.85,
                b"movie_uhd".as_ptr(),
                9,
                &mut cat,
                &mut conf,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(cat, 2040);
        assert!((conf - 0.85).abs() < 1e-12);
        assert_eq!(&buf[..n as usize], b"movie_uhd");

        let n_no = unsafe {
            nntmux_abstract_categorizer_format_result_meta(
                1,
                9999,
                0.99,
                b"ignored".as_ptr(),
                7,
                &mut cat,
                &mut conf,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(cat, 10);
        assert_eq!(conf, 0.0);
        assert_eq!(&buf[..n_no as usize], b"no_match");

        assert_eq!(
            unsafe { nntmux_clearance_matches_category_path(b"Movies/HD".as_ptr(), 9, b"movies".as_ptr(), 6) },
            1
        );
        assert_eq!(
            unsafe { nntmux_clearance_matches_category_path(b"browse/TV/SD".as_ptr(), 12, b"tv".as_ptr(), 2) },
            1
        );
        assert_eq!(
            unsafe { nntmux_clearance_matches_category_path(b"movies_extra".as_ptr(), 12, b"movies".as_ptr(), 6) },
            0
        );

        let nc = unsafe {
            nntmux_clearance_resolve_main_category(b"XXX".as_ptr(), 3, buf.as_mut_ptr(), buf.len())
        };
        assert_eq!(&buf[..nc as usize], b"view adult\0Adult");
        assert_eq!(
            unsafe { nntmux_clearance_resolve_main_category(b"other".as_ptr(), 5, buf.as_mut_ptr(), buf.len()) },
            0
        );
    }

    #[test]
    fn irc_networks_tmux_command_and_settings_catalog_helpers() {
        let mut buf = [0u8; 2048];
        let mut count = 0i64;
        let keys = b"synirc\0corruptnet\0zenet";
        let flags = b"110";
        let ne = unsafe {
            nntmux_irc_networks_filter_enabled_nul(
                keys.as_ptr(),
                keys.len(),
                flags.as_ptr(),
                3,
                &mut count,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(count, 2);
        assert_eq!(&buf[..ne as usize], b"synirc\0corruptnet");

        let ser = br##"a:2:{s:10:"#PreNNTmux";N;s:8:"#private";s:2:"pw";}"##;
        let nl = unsafe {
            nntmux_irc_networks_parse_legacy_channels(
                ser.as_ptr(),
                ser.len(),
                &mut count,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(count, 2);
        assert_eq!(&buf[..nl as usize], b"#PreNNTmux\00\0#private\01pw");

        let nt = unsafe {
            nntmux_tmux_command_arguments_nul(
                b"nntmux".as_ptr(),
                6,
                b"has-session\0-t\0main".as_ptr(),
                19,
                3,
                &mut count,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(count, 7);
        assert_eq!(&buf[..nt as usize], b"tmux\0-u\0-L\0nntmux\0has-session\0-t\0main");

        let nm = unsafe {
            nntmux_tmux_command_monitor_nul(
                b"/usr/bin/php".as_ptr(),
                12,
                b"/app/artisan".as_ptr(),
                12,
                b"nntmux".as_ptr(),
                6,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(
            &buf[..nm as usize],
            b"/usr/bin/php\0/app/artisan\0tmux:monitor\0--session=nntmux"
        );

        let mut rules_count = 0i64;
        let mut opts_count = 0i64;
        let mut int_keys = 0i32;
        let nf = unsafe {
            nntmux_settings_catalog_field_nul(
                b"ingestion".as_ptr(),
                9,
                b"completion_percent".as_ptr(),
                18,
                &mut rules_count,
                &mut opts_count,
                &mut int_keys,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(nf > 0);
        assert_eq!(rules_count, 4);
        assert_eq!(opts_count, 0);
    }
}

#[cfg(test)]
mod wave16_slice6_tests {
    use super::*;

    #[test]
    fn tmux_snapshot_age_and_json_normalizers() {
        let mut stale = -1i32;
        assert_eq!(
            unsafe { nntmux_tmux_snapshot_age_and_stale(1_700_000_000, 1_700_000_120, 900, &mut stale) },
            120
        );
        assert_eq!(stale, 0);
        assert_eq!(
            unsafe { nntmux_tmux_snapshot_age_and_stale(1_700_000_000, 1_700_001_000, 900, &mut stale) },
            1000
        );
        assert_eq!(stale, 1);
        assert_eq!(
            unsafe { nntmux_tmux_snapshot_age_and_stale(1_700_000_500, 1_700_000_000, 900, &mut stale) },
            0
        );
        assert_eq!(stale, 0);
        assert_eq!(
            unsafe { nntmux_tmux_snapshot_age_and_stale(0, 10, 5, std::ptr::null_mut()) },
            -1
        );

        let mut buf = [0u8; 256];
        let counts_json = br#"{"processnfo":12,"releases":"345","label":"not a number","0":99}"#;
        let n = unsafe {
            nntmux_tmux_snapshot_normalize_numeric_json(
                counts_json.as_ptr(),
                counts_json.len(),
                0,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(&buf[..n as usize], br#"{"processnfo":12,"releases":345}"#);

        let timers_json = br#"{"proc1_time":0.5,"proc2_time":2}"#;
        let nt = unsafe {
            nntmux_tmux_snapshot_normalize_numeric_json(
                timers_json.as_ptr(),
                timers_json.len(),
                1,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(&buf[..nt as usize], br#"{"proc1_time":0.5,"proc2_time":2.0}"#);

        let conn_json = br#"{"primary":{"active":4,"total":"20"},"empty":[],"bad":"x"}"#;
        let nc = unsafe {
            nntmux_tmux_snapshot_normalize_connections_json(
                conn_json.as_ptr(),
                conn_json.len(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(
            &buf[..nc as usize],
            br#"{"primary":{"active":4,"total":20},"empty":{"active":0,"total":0}}"#
        );
    }

    #[test]
    fn grafana_embed_urls_frame_origin_cookie_path_and_jwt() {
        let mut buf = [0u8; 512];
        let n1 = unsafe {
            nntmux_grafana_embed_url(
                b"/grafana/".as_ptr(),
                9,
                b"nntmux-host".as_ptr(),
                11,
                b"nntmux-host".as_ptr(),
                11,
                1,
                b"now-6h".as_ptr(),
                6,
                b"1m".as_ptr(),
                2,
                0,
                1,
                0,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(
            &buf[..n1 as usize],
            b"/grafana/d/nntmux-host/nntmux-host?orgId=1&from=now-6h&to=now&refresh=1m&kiosk"
        );

        let n2 = unsafe {
            nntmux_grafana_embed_url(
                b"/grafana".as_ptr(),
                8,
                b"nntmux-processing".as_ptr(),
                17,
                b"nntmux-processing".as_ptr(),
                17,
                1,
                b"now-6h".as_ptr(),
                6,
                b"1m".as_ptr(),
                2,
                1,
                0,
                2,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(
            &buf[..n2 as usize],
            b"/grafana/d-solo/nntmux-processing/nntmux-processing?orgId=1&from=now-6h&to=now&refresh=1m&panelId=2"
        );

        let n_origin = unsafe {
            nntmux_grafana_frame_origin(
                b"https://Grafana.Example:3000/grafana".as_ptr(),
                36,
                b"https://nntmux.example".as_ptr(),
                22,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(&buf[..n_origin as usize], b"https://grafana.example:3000");

        let n_same = unsafe {
            nntmux_grafana_frame_origin(
                b"https://nntmux.example/grafana".as_ptr(),
                30,
                b"https://nntmux.example".as_ptr(),
                22,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(n_same, 0);

        let n_cookie = unsafe {
            nntmux_grafana_cookie_path(
                b"https://grafana.example:3000/grafana/".as_ptr(),
                37,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(&buf[..n_cookie as usize], b"/grafana/");

        let n_b64 = unsafe {
            nntmux_grafana_jwt_base64url_encode(
                b"{\"alg\":\"RS256\",\"typ\":\"JWT\"}".as_ptr(),
                27,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(&buf[..n_b64 as usize], JWT_RS256_HEADER_B64);

        let n_jwt = unsafe {
            nntmux_grafana_jwt_signing_input(
                b"admin_user".as_ptr(),
                10,
                b"admin@example.test".as_ptr(),
                18,
                b"Viewer".as_ptr(),
                6,
                b"nntmux".as_ptr(),
                6,
                b"grafana".as_ptr(),
                7,
                1_700_000_000,
                1_700_000_600,
                b"00000000-0000-4000-8000-000000000001".as_ptr(),
                36,
                1,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(
            &buf[..n_jwt as usize],
            br#"{"sub":"admin_user","email":"admin@example.test","name":"admin_user","role":"Viewer","iss":"nntmux","aud":"grafana","iat":1700000000,"nbf":1699999970,"exp":1700000600,"jti":"00000000-0000-4000-8000-000000000001"}"#
        );
    }

    #[test]
    fn csp_header_vite_hot_url_and_api_performance_helpers() {
        let mut buf = [0u8; 2048];
        let nh = unsafe {
            nntmux_csp_parse_vite_hot_url(
                b"  http://localhost:5173 \n".as_ptr(),
                25,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert_eq!(
            &buf[..nh as usize],
            b"http://localhost:5173\tws://localhost:5173"
        );
        assert_eq!(
            unsafe {
                nntmux_csp_parse_vite_hot_url(
                    b"https://assets.example.com:5173".as_ptr(),
                    31,
                    buf.as_mut_ptr(),
                    buf.len(),
                )
            },
            0
        );

        let ncsp = unsafe {
            nntmux_csp_build_header(
                b"nonce123".as_ptr(),
                8,
                b"http://localhost:5173".as_ptr(),
                21,
                b"ws://localhost:5173".as_ptr(),
                19,
                b"https://grafana.example:3000".as_ptr(),
                28,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        let csp_str = std::str::from_utf8(&buf[..ncsp as usize]).unwrap();
        assert!(csp_str.contains("script-src 'self' 'nonce-nonce123'"));
        assert!(csp_str.contains("https://sp.tinymce.com http://localhost:5173 ws://localhost:5173;"));
        assert!(csp_str.contains("data: blob: https://grafana.example:3000;"));
        assert!(csp_str.ends_with("frame-ancestors 'self'"));

        let (mut raw_ms, mut rounded_ms) = (0.0f64, 0.0f64);
        assert_eq!(
            unsafe {
                nntmux_api_perf_elapsed_ms(1_000_000_000, 1_012_345_678, &mut raw_ms, &mut rounded_ms)
            },
            0
        );
        assert!((raw_ms - 12.345678).abs() < 1e-9);
        assert_eq!(rounded_ms, 12.35);

        let nst = unsafe {
            nntmux_api_perf_format_server_timing(12.345, 3.4, 5, buf.as_mut_ptr(), buf.len())
        };
        assert_eq!(
            &buf[..nst as usize],
            b"app;dur=12.35, db;dur=3.40;desc=\"5 queries\""
        );

        let mut threshold = 0i64;
        let cls = unsafe {
            nntmux_api_perf_classify_slow_request(600.0, 100.0, 500.0, 250.0, 0.01, &mut threshold)
        };
        assert_eq!(cls, 1);
        assert_eq!(threshold, 10_000);
    }
}

#[cfg(test)]
mod wave14_slice5_tests {
    use super::*;

    #[test]
    fn release_image_basename_and_redact_url_and_scale() {
        assert_eq!(unsafe { nntmux_release_image_is_valid_basename(b"cover_1-a".as_ptr(), 9) }, 1);
        assert_eq!(unsafe { nntmux_release_image_is_valid_basename(b"".as_ptr(), 0) }, 0);
        assert_eq!(unsafe { nntmux_release_image_is_valid_basename(b".".as_ptr(), 1) }, 0);
        assert_eq!(unsafe { nntmux_release_image_is_valid_basename(b"..".as_ptr(), 2) }, 0);
        assert_eq!(unsafe { nntmux_release_image_is_valid_basename(b"../cover".as_ptr(), 8) }, 0);
        assert_eq!(unsafe { nntmux_release_image_is_valid_basename(b"a/b".as_ptr(), 3) }, 0);
        assert_eq!(unsafe { nntmux_release_image_is_valid_basename(b"a\\b".as_ptr(), 3) }, 0);
        assert_eq!(unsafe { nntmux_release_image_is_valid_basename(b"a\0b".as_ptr(), 3) }, 0);
        assert_eq!(unsafe { nntmux_release_image_is_valid_basename(std::ptr::null(), 2) }, -1);

        let mut buf = [0u8; 256];
        let u1 = b"HTTPS://user:pass@Images.Example.Test:8443/covers/a.jpg?token=secret#frag";
        let n1 = unsafe { nntmux_release_image_redact_url(u1.as_ptr(), u1.len(), buf.as_mut_ptr(), buf.len()) };
        assert_eq!(&buf[..n1 as usize], b"https://Images.Example.Test:8443/covers/a.jpg");

        let u2 = b"not-a-valid-url";
        let n2 = unsafe { nntmux_release_image_redact_url(u2.as_ptr(), u2.len(), buf.as_mut_ptr(), buf.len()) };
        assert_eq!(&buf[..n2 as usize], b"[invalid-url]");

        let mut fmt = -1i32;
        let packed = unsafe {
            nntmux_release_image_scale_dimensions(400, 200, 250, 250, b"JPG".as_ptr(), 3, &mut fmt)
        };
        assert_eq!(fmt, 1);
        assert_eq!(packed >> 32, 250);
        assert_eq!(packed & 0xFFFF_FFFF, 125);
        assert_eq!(
            unsafe { nntmux_release_image_scale_dimensions(40, 20, 250, 250, b"webp".as_ptr(), 4, &mut fmt) },
            0
        );
        assert_eq!(fmt, 0);
    }

    #[test]
    fn duplicate_reason_size_bounds_export_date_and_categorization() {
        assert_eq!(
            unsafe { nntmux_release_duplicate_resolve_reason(42, 42, b"a".as_ptr(), 1, b"b".as_ptr(), 1) },
            1
        );
        assert_eq!(
            unsafe { nntmux_release_duplicate_resolve_reason(0, 0, b"Ubuntu".as_ptr(), 6, b"Ubuntu".as_ptr(), 6) },
            2
        );
        assert_eq!(
            unsafe { nntmux_release_duplicate_resolve_reason(0, 0, b"Ubuntu".as_ptr(), 6, b"Other".as_ptr(), 5) },
            0
        );

        let (mut low, mut high) = (0i64, 0i64);
        assert_eq!(
            unsafe { nntmux_release_duplicate_size_bounds(1000, 0.05, &mut low, &mut high) },
            0
        );
        assert_eq!((low, high), (950, 1050));

        let mut buf = [0u8; 64];
        let d = b"15/04/2026";
        let n = unsafe { nntmux_release_management_export_date(d.as_ptr(), d.len(), 0, buf.as_mut_ptr(), buf.len()) };
        assert_eq!(&buf[..n as usize], b"2026-04-1500:00:00");
        let n2 = unsafe { nntmux_release_management_export_date(d.as_ptr(), d.len(), 1, buf.as_mut_ptr(), buf.len()) };
        assert_eq!(&buf[..n2 as usize], b"2026-04-1523:59:59");
        assert_eq!(
            unsafe { nntmux_release_management_export_date(b"bad".as_ptr(), 3, 0, buf.as_mut_ptr(), buf.len()) },
            0
        );

        assert_eq!(
            unsafe { nntmux_categorization_result_is_successful(10, 0.0, b"no_match".as_ptr(), 8) },
            0
        );
        assert_eq!(
            unsafe { nntmux_categorization_result_is_successful(10, 0.5, b"obfuscated_pattern".as_ptr(), 18) },
            1
        );
        assert_eq!(
            unsafe { nntmux_categorization_result_is_protected_misc(10, b"hash_md5".as_ptr(), 8) },
            1
        );
        assert_eq!(
            unsafe {
                nntmux_categorization_result_should_override(
                    2000,
                    0.5,
                    b"group".as_ptr(),
                    5,
                    10,
                    0.4,
                    b"hash_md5".as_ptr(),
                    8,
                )
            },
            0
        );
    }

    #[test]
    fn api_release_cache_json_normalization() {
        let mut buf = [0u8; 256];
        let json = br#"{"id":" ubuntu ","category":[5030,5040,5030],"nested":{"z":" b ","a":["x","a","x"]}}"#;
        let n = unsafe {
            nntmux_api_release_cache_normalize_json(json.as_ptr(), json.len(), buf.as_mut_ptr(), buf.len())
        };
        assert_eq!(
            &buf[..n as usize],
            br#"{"category":[5030,5040],"id":"ubuntu","nested":{"a":["a","x"],"z":"b"}}"#
        );
    }
}
