#![allow(clippy::missing_safety_doc)]

use std::borrow::Cow;
use std::slice;
use std::str;

use crate::OutCursor;
use crate::predb::similarity_percent;

const STEAM_SCENE_GROUPS: &[&str] = &[
    "CODEX",
    "PLAZA",
    "GOG",
    "CPY",
    "HOODLUM",
    "EMPRESS",
    "RUNE",
    "TENOKE",
    "FLT",
    "RELOADED",
    "SKIDROW",
    "PROPHET",
    "RAZOR1911",
    "CORE",
    "REFLEX",
    "P2P",
    "GOLDBERG",
    "DARKSIDERS",
    "TINYISO",
    "DOGE",
    "ANOMALY",
    "ELAMIGOS",
    "FITGIRL",
    "DODI",
    "XATAB",
    "CHRONOS",
    "FCKDRM",
    "I_KNOW",
    "KAOS",
    "SIMPLEX",
    "ALI213",
    "FLTDOX",
    "3DMGAME",
    "POSTMORTEM",
    "VACE",
    "ROGUE",
    "OUTLAWS",
    "DARKSIIDERS",
    "ONLINEFIX",
];

const STEAM_EDITION_TAGS: &[&str] = &[
    "GOTY",
    "GAME OF THE YEAR",
    "DEFINITIVE EDITION",
    "DELUXE EDITION",
    "ULTIMATE EDITION",
    "COMPLETE EDITION",
    "REMASTERED",
    "HD REMASTER",
    "DIRECTORS CUT",
    "ANNIVERSARY EDITION",
    "ENHANCED EDITION",
    "SPECIAL EDITION",
    "COLLECTORS EDITION",
    "GOLD EDITION",
    "PREMIUM EDITION",
    "LEGENDARY EDITION",
    "STANDARD EDITION",
    "DIGITAL EDITION",
];

const GAMES_NON_ALNUM_SCENE_GROUPS: &[&[u8]] = &[b"GOG-GAMES", b"I_KnoW"];

#[inline]
fn is_php_trim_byte(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | b'\0' | b'\x0B')
}

#[inline]
fn is_title_trim_byte(b: u8) -> bool {
    matches!(
        b,
        b' ' | b'\t' | b'\n' | b'\r' | b'\0' | b'\x0B' | b'-' | b'_'
    )
}

#[inline]
fn php_trim(s: &str) -> &str {
    let bytes = s.as_bytes();
    let mut start = 0;
    let mut end = bytes.len();
    while start < end && is_php_trim_byte(bytes[start]) {
        start += 1;
    }
    while end > start && is_php_trim_byte(bytes[end - 1]) {
        end -= 1;
    }
    &s[start..end]
}

#[inline]
fn php_trim_title(s: &str) -> &str {
    let bytes = s.as_bytes();
    let mut start = 0;
    let mut end = bytes.len();
    while start < end && is_title_trim_byte(bytes[start]) {
        start += 1;
    }
    while end > start && is_title_trim_byte(bytes[end - 1]) {
        end -= 1;
    }
    &s[start..end]
}

#[inline]
fn trim_title_in_place(s: &mut String) {
    let bytes = s.as_bytes();
    let mut start = 0;
    let mut end = bytes.len();
    while start < end && is_title_trim_byte(bytes[start]) {
        start += 1;
    }
    while end > start && is_title_trim_byte(bytes[end - 1]) {
        end -= 1;
    }
    if end < bytes.len() {
        s.truncate(end);
    }
    if start > 0 {
        s.drain(..start);
    }
}

#[inline]
fn count_digit_runs(bytes: &[u8]) -> usize {
    let mut runs = 0usize;
    let mut in_digits = false;
    for &b in bytes {
        if b.is_ascii_digit() {
            if !in_digits {
                runs += 1;
                in_digits = true;
            }
        } else {
            in_digits = false;
        }
    }
    runs
}

#[inline]
fn contains_ascii_ci(haystack: &[u8], needle_upper: &[u8]) -> bool {
    if needle_upper.is_empty() || haystack.len() < needle_upper.len() {
        return false;
    }
    let first_lo = needle_upper[0].to_ascii_lowercase();
    let first_hi = needle_upper[0].to_ascii_uppercase();
    let max_start = haystack.len() - needle_upper.len();
    let mut i = 0;
    while i <= max_start {
        let b = haystack[i];
        if (b == first_lo || b == first_hi)
            && haystack[i..i + needle_upper.len()].eq_ignore_ascii_case(needle_upper)
        {
            return true;
        }
        i += 1;
    }
    false
}

#[inline]
fn is_ascii_word(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_'
}

#[inline]
fn is_ascii_wb(bytes: &[u8], pos: usize) -> bool {
    let prev = pos > 0 && is_ascii_word(bytes[pos - 1]);
    let next = pos < bytes.len() && is_ascii_word(bytes[pos]);
    prev != next
}

#[inline]
fn is_uni_word(c: char) -> bool {
    c.is_alphanumeric() || c == '_'
}

#[inline]
fn is_uni_wb_end(s: &str, pos: usize) -> bool {
    !s[pos..].chars().next().is_some_and(is_uni_word)
}

#[inline]
fn php_mb_strtolower(input: &str) -> String {
    if input.is_ascii() {
        return input.to_ascii_lowercase();
    }
    let mut out = String::with_capacity(input.len());
    for ch in input.chars() {
        if ch == '\u{03A3}' {
            out.push('\u{03C3}');
        } else {
            for lower in ch.to_lowercase() {
                out.push(lower);
            }
        }
    }
    out
}

#[inline]
fn hex_val(b: u8) -> Option<u8> {
    match b {
        b'0'..=b'9' => Some(b - b'0'),
        b'a'..=b'f' => Some(b - b'a' + 10),
        b'A'..=b'F' => Some(b - b'A' + 10),
        _ => None,
    }
}

fn php_urldecode(input: &str) -> Option<Cow<'_, str>> {
    let bytes = input.as_bytes();
    if !bytes.contains(&b'%') && !bytes.contains(&b'+') {
        return Some(Cow::Borrowed(input));
    }
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] == b'+' {
            out.push(b' ');
            i += 1;
        } else if bytes[i] == b'%' && i + 2 < bytes.len() {
            if let (Some(hi), Some(lo)) = (hex_val(bytes[i + 1]), hex_val(bytes[i + 2])) {
                out.push((hi << 4) | lo);
                i += 3;
                continue;
            }
            out.push(b'%');
            i += 1;
        } else {
            out.push(bytes[i]);
            i += 1;
        }
    }
    String::from_utf8(out).ok().map(Cow::Owned)
}

fn strip_game_extension(s: &str) -> &str {
    const EXTS: &[&str] = &[
        ".zip", ".rar", ".7z", ".iso", ".nfo", ".sfv", ".exe", ".mkv", ".mp4", ".avi",
    ];
    let bytes = s.as_bytes();
    if bytes.len() < 4 {
        return s;
    }
    for ext in EXTS {
        let eb = ext.as_bytes();
        if bytes.len() >= eb.len() && bytes[bytes.len() - eb.len()..].eq_ignore_ascii_case(eb) {
            return &s[..bytes.len() - eb.len()];
        }
    }
    s
}

fn replace_brackets_with_space(s: &str) -> Cow<'_, str> {
    let bytes = s.as_bytes();
    if !bytes.iter().any(|&b| matches!(b, b'[' | b'(' | b'{')) {
        return Cow::Borrowed(s);
    }
    let len = bytes.len();
    let mut out = String::with_capacity(len);
    let mut pos = 0;
    while pos < len {
        let b = bytes[pos];
        let close = match b {
            b'[' => Some(b']'),
            b'(' => Some(b')'),
            b'{' => Some(b'}'),
            _ => None,
        };
        if let Some(cb) = close
            && let Some(rel) = bytes[pos + 1..].iter().position(|&x| x == cb)
        {
            out.push(' ');
            pos += 1 + rel + 1;
            continue;
        }
        let ch = s[pos..].chars().next().unwrap();
        out.push(ch);
        pos += ch.len_utf8();
    }
    Cow::Owned(out)
}

fn strip_steam_scene_group_end(s: &str) -> &str {
    let bytes = s.as_bytes();
    let mut end = bytes.len();
    while end > 0 && bytes[end - 1].is_ascii_whitespace() {
        end -= 1;
    }
    for g in STEAM_SCENE_GROUPS {
        let gb = g.as_bytes();
        if end >= gb.len() && bytes[end - gb.len()..end].eq_ignore_ascii_case(gb) {
            let mut p = end - gb.len();
            while p > 0 && bytes[p - 1].is_ascii_whitespace() {
                p -= 1;
            }
            if p > 0 && matches!(bytes[p - 1], b'-' | b'_') {
                p -= 1;
                while p > 0 && bytes[p - 1].is_ascii_whitespace() {
                    p -= 1;
                }
                return &s[..p];
            }
        }
    }
    s
}

#[inline]
fn replace_ascii_wb_in_place(s: &mut String, matcher: impl Fn(&[u8], usize) -> Option<usize>) {
    let bytes = s.as_bytes();
    let len = bytes.len();
    let mut pos = 0;
    let mut out: Option<String> = None;
    let mut last_copied = 0;

    while pos < len {
        if (pos == 0 || !is_ascii_word(bytes[pos - 1]))
            && is_ascii_word(bytes[pos])
            && let Some(end) = matcher(bytes, pos)
        {
            let buf = out.get_or_insert_with(|| String::with_capacity(len));
            buf.push_str(&s[last_copied..pos]);
            buf.push(' ');
            pos = end;
            last_copied = pos;
            continue;
        }
        pos += 1;
    }

    if let Some(mut buf) = out {
        buf.push_str(&s[last_copied..]);
        *s = buf;
    }
}

fn finish_edition_suffix(bytes: &[u8], core_end: usize) -> Option<usize> {
    let len = bytes.len();
    let mut sep_end = core_end;
    while sep_end < len
        && (bytes[sep_end].is_ascii_whitespace() || matches!(bytes[sep_end], b'.' | b'_' | b'-'))
    {
        sep_end += 1;
    }
    if sep_end + 7 <= len
        && bytes[sep_end..sep_end + 7].eq_ignore_ascii_case(b"edition")
        && is_ascii_wb(bytes, sep_end + 7)
    {
        return Some(sep_end + 7);
    }
    (core_end..=sep_end).rev().find(|&p| is_ascii_wb(bytes, p))
}

#[inline]
fn match_word_ci(bytes: &[u8], pos: usize, word: &[u8]) -> Option<usize> {
    let end = pos + word.len();
    if end <= bytes.len() && bytes[pos..end].eq_ignore_ascii_case(word) {
        Some(end)
    } else {
        None
    }
}

#[inline]
fn skip_ascii_ws1(bytes: &[u8], mut pos: usize) -> Option<usize> {
    if pos >= bytes.len() || !bytes[pos].is_ascii_whitespace() {
        return None;
    }
    while pos < bytes.len() && bytes[pos].is_ascii_whitespace() {
        pos += 1;
    }
    Some(pos)
}

#[inline]
fn skip_sep_star(bytes: &[u8], mut pos: usize) -> usize {
    while pos < bytes.len()
        && (bytes[pos].is_ascii_whitespace() || matches!(bytes[pos], b'.' | b'_' | b'-'))
    {
        pos += 1;
    }
    pos
}

fn match_goty_phrase(bytes: &[u8], pos: usize) -> Option<usize> {
    let p1 = match_word_ci(bytes, pos, b"game")?;
    let p2 = skip_ascii_ws1(bytes, p1)?;
    let p3 = match_word_ci(bytes, p2, b"of")?;
    let p4 = skip_ascii_ws1(bytes, p3)?;
    let p5 = match_word_ci(bytes, p4, b"the")?;
    let p6 = skip_ascii_ws1(bytes, p5)?;
    let p7 = match_word_ci(bytes, p6, b"year")?;
    finish_edition_suffix(bytes, p7)
}

fn match_goty_word(bytes: &[u8], pos: usize) -> Option<usize> {
    let p1 = match_word_ci(bytes, pos, b"goty")?;
    finish_edition_suffix(bytes, p1)
}

fn match_named_edition(bytes: &[u8], pos: usize) -> Option<usize> {
    let words: &[&[u8]] = match bytes.get(pos)?.to_ascii_lowercase() {
        b'd' => &[b"definitive", b"deluxe", b"digital"],
        b'u' => &[b"ultimate"],
        b'c' => &[b"complete", b"collectors", b"collector"],
        b'e' => &[b"enhanced"],
        b's' => &[b"special", b"standard"],
        b'g' => &[b"gold"],
        b'p' => &[b"premium"],
        b'l' => &[b"legendary"],
        _ => return None,
    };
    for w in words {
        if let Some(end) = match_word_ci(bytes, pos, w)
            && let Some(res) = finish_edition_suffix(bytes, end)
        {
            return Some(res);
        }
    }
    None
}

fn match_remaster_edition(bytes: &[u8], pos: usize) -> Option<usize> {
    match bytes.get(pos)?.to_ascii_lowercase() {
        b'r' => {
            if let Some(end) = match_word_ci(bytes, pos, b"remastered")
                && let Some(res) = finish_edition_suffix(bytes, end)
            {
                return Some(res);
            }
        }
        b'h' => {
            if let Some(p1) = match_word_ci(bytes, pos, b"hd") {
                let p2 = skip_sep_star(bytes, p1);
                if let Some(p3) = match_word_ci(bytes, p2, b"remaster")
                    && let Some(res) = finish_edition_suffix(bytes, p3)
                {
                    return Some(res);
                }
            }
        }
        b'd' => {
            for dir in [b"directors".as_slice(), b"director".as_slice()] {
                if let Some(p1) = match_word_ci(bytes, pos, dir) {
                    let p2 = skip_sep_star(bytes, p1);
                    if let Some(p3) = match_word_ci(bytes, p2, b"cut")
                        && let Some(res) = finish_edition_suffix(bytes, p3)
                    {
                        return Some(res);
                    }
                }
            }
        }
        b'a' => {
            if let Some(end) = match_word_ci(bytes, pos, b"anniversary")
                && let Some(res) = finish_edition_suffix(bytes, end)
            {
                return Some(res);
            }
        }
        _ => {}
    }
    None
}

fn match_standalone_edition_word(bytes: &[u8], pos: usize) -> Option<usize> {
    let end = match_word_ci(bytes, pos, b"edition")?;
    is_ascii_wb(bytes, end).then_some(end)
}

fn apply_steam_compound_editions(s: &mut String) {
    replace_ascii_wb_in_place(s, |bytes, pos| match bytes.get(pos)?.to_ascii_lowercase() {
        b'g' => match_goty_phrase(bytes, pos)
            .or_else(|| match_goty_word(bytes, pos))
            .or_else(|| match_named_edition(bytes, pos)),
        b'd' => match_named_edition(bytes, pos).or_else(|| match_remaster_edition(bytes, pos)),
        b'u' | b'c' | b'e' | b's' | b'p' | b'l' => match_named_edition(bytes, pos),
        b'r' | b'h' | b'a' => match_remaster_edition(bytes, pos),
        _ => None,
    });
    replace_ascii_wb_in_place(s, match_standalone_edition_word);
}

fn match_steam_standalone_edition_tag(bytes: &[u8], pos: usize) -> Option<usize> {
    let tags: &[&[u8]] = match bytes.get(pos)?.to_ascii_lowercase() {
        b'g' => &[b"goty", b"game of the year"],
        b'r' => &[b"remastered"],
        b'h' => &[b"hd remaster"],
        b'd' => &[b"directors cut"],
        _ => return None,
    };
    for tag in tags {
        if let Some(end) = match_word_ci(bytes, pos, tag)
            && is_ascii_wb(bytes, end)
        {
            return Some(end);
        }
    }
    None
}

fn match_steam_release_tag(bytes: &[u8], pos: usize) -> Option<usize> {
    // Extract word prefix of ASCII letters
    let mut alpha_end = pos;
    while alpha_end < bytes.len() && bytes[alpha_end].is_ascii_alphabetic() {
        alpha_end += 1;
    }
    let word_len = alpha_end - pos;
    if !(3..=8).contains(&word_len) {
        return None;
    }
    let mut buf = [0u8; 8];
    for (i, &b) in bytes[pos..alpha_end].iter().enumerate() {
        buf[i] = b.to_ascii_lowercase();
    }
    let is_tag = matches!(
        &buf[..word_len],
        b"repack"
            | b"rip"
            | b"iso"
            | b"proper"
            | b"update"
            | b"dlc"
            | b"incl"
            | b"multi"
            | b"crack"
            | b"crackfix"
            | b"fix"
            | b"patch"
            | b"hotfix"
            | b"beta"
            | b"alpha"
            | b"demo"
            | b"preorder"
    );
    if !is_tag {
        return None;
    }
    let mut end = alpha_end;
    while end < bytes.len() && bytes[end].is_ascii_digit() {
        end += 1;
    }
    is_ascii_wb(bytes, end).then_some(end)
}

fn match_dlcs_tag(bytes: &[u8], pos: usize) -> Option<usize> {
    for inc in [b"including".as_slice(), b"incl".as_slice()] {
        if let Some(mut p) = match_word_ci(bytes, pos, inc) {
            if p < bytes.len() && bytes[p] == b'.' {
                p += 1;
            }
            while p < bytes.len() && bytes[p].is_ascii_whitespace() {
                p += 1;
            }
            for dlc in [b"dlcs".as_slice(), b"dlc".as_slice()] {
                if let Some(end) = match_word_ci(bytes, p, dlc)
                    && is_ascii_wb(bytes, end)
                {
                    return Some(end);
                }
            }
        }
    }
    for dlc in [b"dlcs".as_slice(), b"dlc".as_slice()] {
        if let Some(end) = match_word_ci(bytes, pos, dlc)
            && is_ascii_wb(bytes, end)
        {
            return Some(end);
        }
    }
    None
}

fn match_dotted_version_2plus(bytes: &[u8], pos: usize) -> Option<usize> {
    let len = bytes.len();
    let mut p = pos;
    if p < len && matches!(bytes[p], b'v' | b'V') {
        p += 1;
    }
    if p >= len || !bytes[p].is_ascii_digit() {
        return None;
    }
    while p < len && bytes[p].is_ascii_digit() {
        p += 1;
    }
    let mut seg_ends = [0usize; 16];
    let mut seg_count = 0usize;
    while p < len && bytes[p] == b'.' {
        let mut q = p + 1;
        if q >= len || !bytes[q].is_ascii_digit() {
            break;
        }
        while q < len && bytes[q].is_ascii_digit() {
            q += 1;
        }
        if seg_count < seg_ends.len() {
            seg_ends[seg_count] = q;
            seg_count += 1;
        }
        p = q;
    }
    if seg_count < 2 {
        return None;
    }
    seg_ends[1..seg_count]
        .iter()
        .rev()
        .copied()
        .find(|&end| is_ascii_wb(bytes, end))
}

fn replace_separators_and_collapse_ws(s: &str, trim_title: bool) -> String {
    let mut out = String::with_capacity(s.len());
    let mut last_space = false;
    for ch in s.chars() {
        let is_sep = matches!(ch, '.' | '_' | '+' | '-') || ch.is_ascii_whitespace();
        if is_sep {
            if !last_space {
                out.push(' ');
                last_space = true;
            }
        } else {
            out.push(ch);
            last_space = false;
        }
    }
    if trim_title {
        php_trim_title(&out).to_owned()
    } else {
        php_trim(&out).to_owned()
    }
}

fn collapse_ascii_ws(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    let mut last_space = false;
    for ch in s.chars() {
        if ch.is_ascii_whitespace() {
            if !last_space {
                out.push(' ');
                last_space = true;
            }
        } else {
            out.push(ch);
            last_space = false;
        }
    }
    out
}

pub fn steam_clean_title(input: &str) -> Option<String> {
    let trimmed = php_trim(input);
    if trimmed.is_empty() {
        return Some(String::new());
    }
    let decoded = php_urldecode(trimmed)?;
    if decoded.contains('\n') || decoded.contains('\r') {
        return None;
    }

    let no_ext = strip_game_extension(&decoded);
    let no_brackets = replace_brackets_with_space(no_ext);
    let mut s = strip_steam_scene_group_end(&no_brackets).to_owned();
    apply_steam_compound_editions(&mut s);
    replace_ascii_wb_in_place(&mut s, match_steam_standalone_edition_tag);
    replace_ascii_wb_in_place(&mut s, match_steam_release_tag);
    replace_ascii_wb_in_place(&mut s, match_dlcs_tag);
    replace_ascii_wb_in_place(&mut s, match_dotted_version_2plus);

    Some(replace_separators_and_collapse_ws(&s, true))
}

#[inline]
fn roman_to_arabic_lower(word: &[u8]) -> Option<&'static str> {
    match word {
        b"xx" => Some("20"),
        b"xix" => Some("19"),
        b"xviii" => Some("18"),
        b"xvii" => Some("17"),
        b"xvi" => Some("16"),
        b"xv" => Some("15"),
        b"xiv" => Some("14"),
        b"xiii" => Some("13"),
        b"xii" => Some("12"),
        b"xi" => Some("11"),
        b"x" => Some("10"),
        b"ix" => Some("9"),
        b"viii" => Some("8"),
        b"vii" => Some("7"),
        b"vi" => Some("6"),
        b"v" => Some("5"),
        b"iv" => Some("4"),
        b"iii" => Some("3"),
        b"ii" => Some("2"),
        _ => None,
    }
}

pub fn steam_replace_roman_numerals(input: &str) -> String {
    let bytes = input.as_bytes();
    let len = bytes.len();
    let mut out: Option<String> = None;
    let mut last_copied = 0;
    let mut pos = 0;
    let mut prev_is_word = false;

    while pos < len {
        let ch = input[pos..].chars().next().unwrap();
        let ch_len = ch.len_utf8();
        let curr_is_word = is_uni_word(ch);

        if !prev_is_word
            && curr_is_word
            && matches!(bytes[pos], b'i' | b'I' | b'v' | b'V' | b'x' | b'X')
        {
            let mut w_end = pos + 1;
            while w_end < len && matches!(bytes[w_end], b'i' | b'I' | b'v' | b'V' | b'x' | b'X') {
                w_end += 1;
            }
            let w_len = w_end - pos;
            if (1..=5).contains(&w_len) && is_uni_wb_end(input, w_end) {
                let mut low = [0u8; 5];
                for (i, &b) in bytes[pos..w_end].iter().enumerate() {
                    low[i] = b.to_ascii_lowercase();
                }
                if let Some(arab) = roman_to_arabic_lower(&low[..w_len]) {
                    let buf = out.get_or_insert_with(|| String::with_capacity(len));
                    buf.push_str(&input[last_copied..pos]);
                    buf.push_str(arab);
                    pos = w_end;
                    last_copied = pos;
                    prev_is_word = false;
                    continue;
                }
            }
        }

        prev_is_word = curr_is_word;
        pos += ch_len;
    }

    if let Some(mut buf) = out {
        buf.push_str(&input[last_copied..]);
        buf
    } else {
        input.to_owned()
    }
}

#[inline]
fn is_steam_single_noise_word(w: &[u8]) -> bool {
    matches!(
        w,
        b"codex"
            | b"plaza"
            | b"gog"
            | b"cpy"
            | b"hoodlum"
            | b"empress"
            | b"rune"
            | b"tenoke"
            | b"flt"
            | b"reloaded"
            | b"skidrow"
            | b"prophet"
            | b"razor1911"
            | b"core"
            | b"reflex"
            | b"p2p"
            | b"goldberg"
            | b"darksiders"
            | b"tinyiso"
            | b"doge"
            | b"anomaly"
            | b"elamigos"
            | b"fitgirl"
            | b"dodi"
            | b"xatab"
            | b"chronos"
            | b"fckdrm"
            | b"i_know"
            | b"kaos"
            | b"simplex"
            | b"ali213"
            | b"fltdox"
            | b"3dmgame"
            | b"postmortem"
            | b"vace"
            | b"rogue"
            | b"outlaws"
            | b"darksiiders"
            | b"onlinefix"
            | b"goty"
            | b"remastered"
            | b"repack"
            | b"rip"
            | b"iso"
            | b"proper"
            | b"update"
            | b"dlc"
            | b"incl"
            | b"multi"
            | b"crack"
            | b"crackfix"
            | b"fix"
            | b"patch"
            | b"hotfix"
            | b"beta"
            | b"alpha"
            | b"demo"
            | b"preorder"
            | b"pc"
            | b"win"
            | b"windows"
            | b"x86"
            | b"x64"
            | b"x32"
    )
}

fn match_steam_noise_at(s: &str, bytes: &[u8], pos: usize) -> Option<usize> {
    let len = bytes.len();
    let mut w_end = pos;
    while w_end < len && is_ascii_word(bytes[w_end]) {
        w_end += 1;
    }
    if w_end == pos || !is_uni_wb_end(s, w_end) {
        return None;
    }
    let word = &bytes[pos..w_end];
    if is_steam_single_noise_word(word) {
        return Some(w_end);
    }
    // Check multi-word EDITION_TAGS in lowercase
    match word {
        b"game" => {
            if pos + 16 <= len
                && &bytes[pos..pos + 16] == b"game of the year"
                && is_uni_wb_end(s, pos + 16)
            {
                return Some(pos + 16);
            }
        }
        b"hd" => {
            if pos + 11 <= len
                && &bytes[pos..pos + 11] == b"hd remaster"
                && is_uni_wb_end(s, pos + 11)
            {
                return Some(pos + 11);
            }
        }
        b"directors" => {
            if pos + 13 <= len
                && &bytes[pos..pos + 13] == b"directors cut"
                && is_uni_wb_end(s, pos + 13)
            {
                return Some(pos + 13);
            }
        }
        b"definitive" | b"deluxe" | b"ultimate" | b"complete" | b"anniversary" | b"enhanced"
        | b"special" | b"collectors" | b"gold" | b"premium" | b"legendary" | b"standard"
        | b"digital" => {
            let ed_end = w_end + 8;
            if ed_end <= len && &bytes[w_end..ed_end] == b" edition" && is_uni_wb_end(s, ed_end) {
                return Some(ed_end);
            }
        }
        _ => {}
    }
    None
}

pub fn steam_normalize_title(input: &str) -> String {
    let lower = php_mb_strtolower(input);
    let mut sep_norm = String::with_capacity(lower.len());
    let mut in_sep = false;
    for ch in lower.chars() {
        if matches!(ch, '.' | '_' | '-' | '+') {
            if !in_sep {
                sep_norm.push(' ');
                in_sep = true;
            }
        } else {
            sep_norm.push(ch);
            in_sep = false;
        }
    }

    let roman = steam_replace_roman_numerals(&sep_norm);

    // Single pass: remove noise words, strip non-alphanumeric, collapse spaces
    let r_bytes = roman.as_bytes();
    let r_len = r_bytes.len();
    let mut cleaned = String::with_capacity(r_len);
    let mut pos = 0;
    let mut prev_is_word = false;
    let mut last_ws = false;

    while pos < r_len {
        let ch = roman[pos..].chars().next().unwrap();
        let ch_len = ch.len_utf8();
        let curr_is_word = is_uni_word(ch);

        if !prev_is_word
            && curr_is_word
            && let Some(end) = match_steam_noise_at(&roman, r_bytes, pos)
        {
            if !last_ws {
                cleaned.push(' ');
                last_ws = true;
            }
            pos = end;
            prev_is_word = false;
            continue;
        }

        if ch.is_alphanumeric() {
            cleaned.push(ch);
            last_ws = false;
        } else if !last_ws {
            cleaned.push(' ');
            last_ws = true;
        }

        prev_is_word = curr_is_word;
        pos += ch_len;
    }

    let trimmed = php_trim(&cleaned);
    for art in ["the ", "a ", "an "] {
        if let Some(rest) = trimmed.strip_prefix(art) {
            return rest.trim_start_matches(' ').to_owned();
        }
    }
    trimmed.to_owned()
}

pub fn steam_strip_edition_tags(input: &str) -> String {
    let mut s = input.to_owned();
    apply_steam_compound_editions(&mut s);
    let has_residual_edition_substr = {
        let sb = s.as_bytes();
        contains_ascii_ci(sb, b"EDITION")
            || contains_ascii_ci(sb, b"REMASTER")
            || contains_ascii_ci(sb, b"GOTY")
            || contains_ascii_ci(sb, b"GAME OF THE YEAR")
            || contains_ascii_ci(sb, b"DIRECTORS CUT")
    };
    if has_residual_edition_substr {
        for tag in STEAM_EDITION_TAGS {
            let tb = tag.as_bytes();
            let bytes = s.as_bytes();
            if !contains_ascii_ci(bytes, tb) {
                continue;
            }
            let len = bytes.len();
            let mut out: Option<String> = None;
            let mut last_copied = 0;
            let mut pos = 0;
            while pos < len {
                let mut p = pos;
                while p < len && bytes[p].is_ascii_whitespace() {
                    p += 1;
                }
                if p < len && matches!(bytes[p], b'-' | b'_') {
                    p += 1;
                }
                while p < len && bytes[p].is_ascii_whitespace() {
                    p += 1;
                }
                if p + tb.len() <= len && bytes[p..p + tb.len()].eq_ignore_ascii_case(tb) {
                    let mut end = p + tb.len();
                    while end < len && bytes[end].is_ascii_whitespace() {
                        end += 1;
                    }
                    let buf = out.get_or_insert_with(|| String::with_capacity(len));
                    buf.push_str(&s[last_copied..pos]);
                    buf.push(' ');
                    pos = end;
                    last_copied = pos;
                    continue;
                }
                pos += 1;
            }
            if let Some(mut buf) = out {
                buf.push_str(&s[last_copied..]);
                s = buf;
            }
        }
    }
    let collapsed = collapse_ascii_ws(&s);
    php_trim(&collapsed).to_owned()
}

fn steam_tokenize(normalized: &str) -> Vec<&str> {
    let mut out: Vec<&str> = Vec::with_capacity(8);
    for part in normalized.split_whitespace() {
        let p = php_trim(part);
        if !p.is_empty() && !out.contains(&p) {
            out.push(p);
        }
    }
    out
}

fn php_byte_levenshtein(a: &[u8], b: &[u8]) -> isize {
    if a == b {
        return 0;
    }
    if a.is_empty() {
        return b.len() as isize;
    }
    if b.is_empty() {
        return a.len() as isize;
    }
    if b.len() > 255 {
        let mut prev: Vec<usize> = (0..=b.len()).collect();
        let mut curr = vec![0usize; b.len() + 1];
        for (i, &ca) in a.iter().enumerate() {
            curr[0] = i + 1;
            for (j, &cb) in b.iter().enumerate() {
                let cost = usize::from(ca != cb);
                curr[j + 1] = (prev[j + 1] + 1).min(curr[j] + 1).min(prev[j] + cost);
            }
            prev.copy_from_slice(&curr);
        }
        return prev[b.len()] as isize;
    }
    let mut prev = [0usize; 256];
    let mut curr = [0usize; 256];
    for (j, slot) in prev.iter_mut().enumerate().take(b.len() + 1) {
        *slot = j;
    }
    for (i, &ca) in a.iter().enumerate() {
        curr[0] = i + 1;
        for (j, &cb) in b.iter().enumerate() {
            let cost = usize::from(ca != cb);
            curr[j + 1] = (prev[j + 1] + 1).min(curr[j] + 1).min(prev[j] + cost);
        }
        prev[..=b.len()].copy_from_slice(&curr[..=b.len()]);
    }
    prev[b.len()] as isize
}

pub fn steam_score_title(candidate: &str, original: &str) -> f64 {
    let norm_cand = steam_normalize_title(candidate);
    let norm_orig = steam_normalize_title(original);
    if norm_cand.is_empty() || norm_orig.is_empty() {
        return 0.0;
    }
    if norm_cand == norm_orig {
        return 100.0;
    }

    let tokens_cand = steam_tokenize(&norm_cand);
    let tokens_orig = steam_tokenize(&norm_orig);

    let mut intersect = 0usize;
    for tc in &tokens_cand {
        if tokens_orig.contains(tc) {
            intersect += 1;
        }
    }
    let union = tokens_cand.len() + tokens_orig.len() - intersect;
    let jaccard = if union > 0 {
        intersect as f64 / union as f64
    } else {
        0.0
    };

    let lev = php_byte_levenshtein(norm_cand.as_bytes(), norm_orig.as_bytes());
    let max_len = norm_cand.len().max(norm_orig.len());
    let lev_sim = if max_len > 0 {
        1.0 - (lev as f64 / max_len as f64)
    } else {
        0.0
    };

    let prefix_boost = if norm_cand.starts_with(&norm_orig) || norm_orig.starts_with(&norm_cand) {
        0.15
    } else {
        0.0
    };

    let cand_in_orig = intersect == tokens_cand.len();
    let orig_in_cand = intersect == tokens_orig.len();
    if cand_in_orig || orig_in_cand {
        let short_count = tokens_cand.len().min(tokens_orig.len());
        let long_count = tokens_cand.len().max(tokens_orig.len());
        let coverage = if long_count > 0 {
            short_count as f64 / long_count as f64
        } else {
            0.0
        };
        if coverage >= 0.8 {
            return 100.0;
        }
    }

    let score = (jaccard * 0.6 + lev_sim * 0.4 + prefix_boost) * 100.0;
    score.clamp(0.0, 100.0)
}

// ============================================================================
// GamesTitleParser
// ============================================================================

#[inline]
fn is_ver_sep(b: u8, allow_punct: bool) -> bool {
    b.is_ascii_whitespace() || (allow_punct && matches!(b, b'.' | b'_' | b'-'))
}

fn try_match_version_run_body(
    bytes: &[u8],
    body_start: usize,
    require_v: bool,
    allow_punct: bool,
) -> Option<usize> {
    let len = bytes.len();
    let mut p = body_start;
    if require_v {
        if p >= len || !matches!(bytes[p], b'v' | b'V') {
            return None;
        }
        p += 1;
    }
    if p >= len || !bytes[p].is_ascii_digit() {
        return None;
    }
    while p < len && bytes[p].is_ascii_digit() {
        p += 1;
    }

    let mut seg_ends = [0usize; 16];
    let mut seg_count = 0usize;
    while p < len {
        let mut q = p;
        if allow_punct {
            if !is_ver_sep(bytes[q], true) {
                break;
            }
            q += 1;
        } else {
            if !bytes[q].is_ascii_whitespace() {
                break;
            }
            while q < len && bytes[q].is_ascii_whitespace() {
                q += 1;
            }
        }
        if q >= len || !bytes[q].is_ascii_digit() {
            break;
        }
        while q < len && bytes[q].is_ascii_digit() {
            q += 1;
        }
        if seg_count < seg_ends.len() {
            seg_ends[seg_count] = q;
            seg_count += 1;
        }
        p = q;
    }

    let min_segs = if require_v { 1 } else { 2 };
    if seg_count < min_segs {
        return None;
    }
    seg_ends[(min_segs - 1)..seg_count]
        .iter()
        .rev()
        .copied()
        .find(|&end| end == len || is_ver_sep(bytes[end], allow_punct))
}

fn replace_version_runs_in_place(s: &mut String, require_v: bool, allow_punct: bool) {
    let bytes = s.as_bytes();
    if !bytes.iter().any(|b| b.is_ascii_digit()) {
        return;
    }
    let len = bytes.len();
    let mut out: Option<String> = None;
    let mut last_copied = 0;
    let mut pos = 0;

    while pos < len {
        let matched_end = if pos == 0 {
            try_match_version_run_body(bytes, 0, require_v, allow_punct).or_else(|| {
                if is_ver_sep(bytes[0], allow_punct) {
                    try_match_version_run_body(bytes, 1, require_v, allow_punct)
                } else {
                    None
                }
            })
        } else if is_ver_sep(bytes[pos], allow_punct) {
            try_match_version_run_body(bytes, pos + 1, require_v, allow_punct)
        } else {
            None
        };

        if let Some(end) = matched_end {
            let buf = out.get_or_insert_with(|| String::with_capacity(len));
            buf.push_str(&s[last_copied..pos]);
            buf.push(' ');
            pos = end;
            last_copied = pos;
        } else {
            pos += 1;
        }
    }

    if let Some(mut buf) = out {
        buf.push_str(&s[last_copied..]);
        *s = buf;
    }
}

fn match_games_edition_phrase(bytes: &[u8], pos: usize) -> Option<usize> {
    let first = bytes.get(pos)?.to_ascii_lowercase();
    if first == b'g' {
        if let Some(p1) = match_word_ci(bytes, pos, b"game")
            && let Some(p2) = skip_ascii_ws1(bytes, p1)
            && let Some(p3) = match_word_ci(bytes, p2, b"of")
            && let Some(p4) = skip_ascii_ws1(bytes, p3)
            && let Some(p5) = match_word_ci(bytes, p4, b"the")
            && let Some(p6) = skip_ascii_ws1(bytes, p5)
            && let Some(p7) = match_word_ci(bytes, p6, b"year")
            && is_ascii_wb(bytes, p7)
        {
            return Some(p7);
        }
        if let Some(end) = match_word_ci(bytes, pos, b"goty")
            && is_ascii_wb(bytes, end)
        {
            return Some(end);
        }
        return None;
    }
    let phrases: &[&[u8]] = match first {
        b'd' => &[
            b"definitive edition",
            b"deluxe edition",
            b"directors cut",
            b"director cut",
        ],
        b'u' => &[b"ultimate edition"],
        b'c' => &[b"complete edition"],
        b'r' => &[b"remastered"],
        b'h' => &[b"hd remaster"],
        b'a' => &[b"anniversary edition"],
        _ => return None,
    };
    for phrase in phrases {
        if let Some(end) = match_word_ci(bytes, pos, phrase)
            && is_ascii_wb(bytes, end)
        {
            return Some(end);
        }
    }
    None
}

fn match_games_update_version(bytes: &[u8], pos: usize) -> Option<usize> {
    let w: &[u8] = match bytes.get(pos)?.to_ascii_lowercase() {
        b'u' => b"update",
        b'p' => b"patch",
        b'h' => b"hotfix",
        _ => return None,
    };
    let end = match_word_ci(bytes, pos, w)?;
    if !is_ascii_wb(bytes, end) {
        return None;
    }
    let mut p = skip_sep_star(bytes, end);
    if p < bytes.len() && matches!(bytes[p], b'v' | b'V') {
        p += 1;
    }
    if p >= bytes.len() || !bytes[p].is_ascii_digit() {
        return None;
    }
    while p < bytes.len() && bytes[p].is_ascii_digit() {
        p += 1;
    }
    let mut seg_ends = [0usize; 16];
    let mut seg_count = 0usize;
    while p < bytes.len() && bytes[p] == b'.' {
        let mut q = p + 1;
        if q >= bytes.len() || !bytes[q].is_ascii_digit() {
            break;
        }
        while q < bytes.len() && bytes[q].is_ascii_digit() {
            q += 1;
        }
        if seg_count < seg_ends.len() {
            seg_ends[seg_count] = q;
            seg_count += 1;
        }
        p = q;
    }
    seg_ends[..seg_count]
        .iter()
        .rev()
        .copied()
        .find(|&end| is_ascii_wb(bytes, end))
}

fn match_games_release_or_update_tags(bytes: &[u8], pos: usize) -> Option<usize> {
    let first = bytes.get(pos)?.to_ascii_lowercase();
    if first == b'i' {
        for inc in [b"including".as_slice(), b"incl".as_slice()] {
            if let Some(p1) = match_word_ci(bytes, pos, inc)
                && let Some(p2) = skip_ascii_ws1(bytes, p1)
            {
                for dlc in [b"dlcs".as_slice(), b"dlc".as_slice()] {
                    if let Some(end) = match_word_ci(bytes, p2, dlc)
                        && is_ascii_wb(bytes, end)
                    {
                        return Some(end);
                    }
                }
            }
        }
        if let Some(end) = match_word_ci(bytes, pos, b"iso")
            && is_ascii_wb(bytes, end)
        {
            return Some(end);
        }
        return None;
    }
    let words: &[&[u8]] = match first {
        b'd' => &[b"dlcs", b"dlc"],
        b'p' => &[b"proper", b"patch"],
        b'r' => &[b"repack", b"rip"],
        b'c' => &[b"crackfix", b"crack"],
        b'b' => &[b"beta"],
        b'a' => &[b"alpha"],
        b'u' => &[b"update"],
        b'h' => &[b"hotfix"],
        _ => return None,
    };
    for w in words {
        if let Some(end) = match_word_ci(bytes, pos, w)
            && is_ascii_wb(bytes, end)
        {
            return Some(end);
        }
    }
    None
}

fn strip_games_scene_group_end(s: &str) -> &str {
    let bytes = s.as_bytes();
    let mut end = bytes.len();
    while end > 0 && bytes[end - 1].is_ascii_whitespace() {
        end -= 1;
    }
    for gb in GAMES_NON_ALNUM_SCENE_GROUPS {
        if end >= gb.len() && bytes[end - gb.len()..end].eq_ignore_ascii_case(gb) {
            let mut p = end - gb.len();
            while p > 0 && bytes[p - 1].is_ascii_whitespace() {
                p -= 1;
            }
            if p > 0 && bytes[p - 1] == b'-' {
                p -= 1;
                while p > 0 && bytes[p - 1].is_ascii_whitespace() {
                    p -= 1;
                }
                return &s[..p];
            }
        }
    }
    let mut tok_start = end;
    while tok_start > 0 && bytes[tok_start - 1].is_ascii_alphanumeric() {
        tok_start -= 1;
    }
    if end - tok_start >= 2 {
        let mut p = tok_start;
        while p > 0 && bytes[p - 1].is_ascii_whitespace() {
            p -= 1;
        }
        if p > 0 && bytes[p - 1] == b'-' {
            p -= 1;
            while p > 0 && bytes[p - 1].is_ascii_whitespace() {
                p -= 1;
            }
            return &s[..p];
        }
    }
    s
}

fn clean_version_tokens(name: &str) -> String {
    let trimmed = php_trim(name);
    if trimmed.is_empty() {
        return String::new();
    }
    let mut tokens = [&[][..]; 64];
    let mut tcount = 0usize;
    let mut overflow: Option<Vec<&str>> = None;
    for tok in trimmed.split_ascii_whitespace() {
        if let Some(ref mut v) = overflow {
            v.push(tok);
        } else if tcount < tokens.len() {
            tokens[tcount] = tok.as_bytes();
            tcount += 1;
        } else {
            let mut v = Vec::with_capacity(128);
            for &b in &tokens[..tcount] {
                v.push(unsafe { str::from_utf8_unchecked(b) });
            }
            v.push(tok);
            overflow = Some(v);
        }
    }

    if let Some(v) = overflow {
        let mut filtered: Vec<&str> = Vec::with_capacity(v.len());
        let mut i = 0;
        while i < v.len() {
            let tb = v[i].as_bytes();
            let starts_with_v = tb.len() >= 2
                && matches!(tb[0], b'v' | b'V')
                && tb[1..].iter().all(|b| b.is_ascii_digit());
            let is_pure_digits = !tb.is_empty() && tb.iter().all(|b| b.is_ascii_digit());
            if starts_with_v || is_pure_digits {
                let mut j = i + 1;
                let mut num_run = 0usize;
                while j < v.len()
                    && !v[j].is_empty()
                    && v[j].as_bytes().iter().all(|b| b.is_ascii_digit())
                {
                    num_run += 1;
                    j += 1;
                }
                if (starts_with_v && num_run >= 1) || (!starts_with_v && num_run >= 2) {
                    i = j;
                    continue;
                }
            }
            filtered.push(v[i]);
            i += 1;
        }
        return filtered.join(" ");
    }

    let mut out = String::with_capacity(trimmed.len());
    let mut i = 0;
    while i < tcount {
        let tb = tokens[i];
        let starts_with_v = tb.len() >= 2
            && matches!(tb[0], b'v' | b'V')
            && tb[1..].iter().all(|b| b.is_ascii_digit());
        let is_pure_digits = !tb.is_empty() && tb.iter().all(|b| b.is_ascii_digit());

        if starts_with_v || is_pure_digits {
            let mut j = i + 1;
            let mut num_run = 0usize;
            while j < tcount {
                let jb = tokens[j];
                if !jb.is_empty() && jb.iter().all(|b| b.is_ascii_digit()) {
                    num_run += 1;
                    j += 1;
                } else {
                    break;
                }
            }
            if (starts_with_v && num_run >= 1) || (!starts_with_v && num_run >= 2) {
                i = j;
                continue;
            }
        }
        if !out.is_empty() {
            out.push(' ');
        }
        out.push_str(unsafe { str::from_utf8_unchecked(tb) });
        i += 1;
    }

    out
}

fn match_wb_version_run(bytes: &[u8], pos: usize, require_v: bool) -> Option<usize> {
    let len = bytes.len();
    let mut p = pos;
    if require_v {
        if p >= len || !matches!(bytes[p], b'v' | b'V') {
            return None;
        }
        p += 1;
    }
    if p >= len || !bytes[p].is_ascii_digit() {
        return None;
    }
    while p < len && bytes[p].is_ascii_digit() {
        p += 1;
    }
    let mut seg_ends = [0usize; 16];
    let mut seg_count = 0usize;
    while p < len && matches!(bytes[p], b' ' | b'.' | b'_' | b'-') {
        let mut q = p + 1;
        if q >= len || !bytes[q].is_ascii_digit() {
            break;
        }
        while q < len && bytes[q].is_ascii_digit() {
            q += 1;
        }
        if seg_count < seg_ends.len() {
            seg_ends[seg_count] = q;
            seg_count += 1;
        }
        p = q;
    }
    let min_segs = if require_v { 1 } else { 2 };
    if seg_count < min_segs {
        return None;
    }
    seg_ends[(min_segs - 1)..seg_count]
        .iter()
        .rev()
        .copied()
        .find(|&end| is_ascii_wb(bytes, end))
}

fn collapse_multi_spaces_in_place(s: &mut String) {
    let bytes = s.as_bytes();
    let len = bytes.len();
    let mut has_multi = false;
    for i in 0..len.saturating_sub(1) {
        if bytes[i].is_ascii_whitespace() && bytes[i + 1].is_ascii_whitespace() {
            has_multi = true;
            break;
        }
    }
    if !has_multi {
        return;
    }
    let mut out = String::with_capacity(len);
    let mut pos = 0;
    while pos < len {
        if bytes[pos].is_ascii_whitespace() {
            let mut end = pos + 1;
            while end < len && bytes[end].is_ascii_whitespace() {
                end += 1;
            }
            if end - pos >= 2 {
                out.push(' ');
            } else {
                out.push(bytes[pos] as char);
            }
            pos = end;
        } else {
            out.push(bytes[pos] as char);
            pos += 1;
        }
    }
    *s = out;
}

pub fn games_clean_release_name(input: &str) -> Option<String> {
    if !input.is_ascii() || input.contains('\n') || input.contains('\r') {
        return None;
    }
    let mut name = strip_game_extension(input).to_owned();
    if name.contains("%20") {
        name = name.replace("%20", " ");
    }

    // Remove leading bracketed tag like `^\[[^\]]+\]\s*`
    if name.starts_with('[')
        && let Some(close) = name[1..].find(']')
        && close >= 1
    {
        let after = 1 + close + 1;
        let rest = name[after..].trim_start_matches(|c: char| c.is_ascii_whitespace());
        name = rest.to_owned();
    }

    // Remove `^(PC\s*ISO\)\s*\()/i`
    {
        let nb = name.as_bytes();
        if nb.len() >= 6 && nb[..2].eq_ignore_ascii_case(b"pc") {
            let mut p = 2;
            while p < nb.len() && nb[p].is_ascii_whitespace() {
                p += 1;
            }
            if p + 4 <= nb.len() && nb[p..p + 4].eq_ignore_ascii_case(b"iso)") {
                p += 4;
                while p < nb.len() && nb[p].is_ascii_whitespace() {
                    p += 1;
                }
                if p < nb.len() && nb[p] == b'(' {
                    name = name[p + 1..].to_owned();
                }
            }
        }
    }

    // Remove `\[[^\]]{1,80}\]|\([^)]{1,80}\)`
    if name.as_bytes().iter().any(|&b| matches!(b, b'[' | b'(')) {
        let nb = name.as_bytes();
        let len = nb.len();
        let mut next_name = String::with_capacity(len);
        let mut pos = 0;
        while pos < len {
            let close = match nb[pos] {
                b'[' => Some(b']'),
                b'(' => Some(b')'),
                _ => None,
            };
            if let Some(cb) = close
                && let Some(rel) = nb[pos + 1..].iter().position(|&x| x == cb)
                && (1..=80).contains(&rel)
            {
                next_name.push(' ');
                pos += 1 + rel + 1;
                continue;
            }
            next_name.push(nb[pos] as char);
            pos += 1;
        }
        name = next_name;
    }

    replace_ascii_wb_in_place(&mut name, |bytes, pos| {
        match_games_edition_phrase(bytes, pos).or_else(|| match_games_update_version(bytes, pos))
    });

    // Remove `\bMULTI\d+|MULTi\d+\b`
    if contains_ascii_ci(name.as_bytes(), b"MULTI") {
        let nb = name.as_bytes();
        let len = nb.len();
        let mut out: Option<String> = None;
        let mut last_copied = 0;
        let mut pos = 0;
        while pos + 6 <= len {
            if nb[pos..pos + 5].eq_ignore_ascii_case(b"multi") && nb[pos + 5].is_ascii_digit() {
                let mut end = pos + 6;
                while end < len && nb[end].is_ascii_digit() {
                    end += 1;
                }
                let left_wb = pos == 0 || !is_ascii_word(nb[pos - 1]);
                let right_wb = is_ascii_wb(nb, end);
                if left_wb || right_wb {
                    let buf = out.get_or_insert_with(|| String::with_capacity(len));
                    buf.push_str(&name[last_copied..pos]);
                    buf.push(' ');
                    pos = end;
                    last_copied = pos;
                    continue;
                }
            }
            pos += 1;
        }
        if let Some(mut buf) = out {
            buf.push_str(&name[last_copied..]);
            name = buf;
        }
    }

    if count_digit_runs(name.as_bytes()) >= 2 {
        replace_version_runs_in_place(&mut name, true, true);
        replace_version_runs_in_place(&mut name, false, true);
    }
    replace_ascii_wb_in_place(&mut name, match_games_release_or_update_tags);

    let stripped_len = strip_games_scene_group_end(&name).len();
    if stripped_len < name.len() {
        name.truncate(stripped_len);
    }

    // Replace `[._+]+` with `' '`
    if name
        .as_bytes()
        .iter()
        .any(|&b| matches!(b, b'.' | b'_' | b'+'))
    {
        let mut next_name = String::with_capacity(name.len());
        let mut in_sep = false;
        for &b in name.as_bytes() {
            if matches!(b, b'.' | b'_' | b'+') {
                if !in_sep {
                    next_name.push(' ');
                    in_sep = true;
                }
            } else {
                next_name.push(b as char);
                in_sep = false;
            }
        }
        name = next_name;
    }

    replace_ascii_wb_in_place(&mut name, |bytes, pos| {
        if let Some(end) = match_games_edition_phrase(bytes, pos) {
            return Some(end);
        }
        let words: &[&[u8]] = match bytes.get(pos)?.to_ascii_lowercase() {
            b'i' => &[b"including", b"incl"],
            b'd' => &[b"dlcs", b"dlc"],
            _ => return None,
        };
        for w in words {
            if let Some(end) = match_word_ci(bytes, pos, w)
                && is_ascii_wb(bytes, end)
            {
                return Some(end);
            }
        }
        None
    });

    name = clean_version_tokens(&name);
    if count_digit_runs(name.as_bytes()) >= 2 {
        replace_version_runs_in_place(&mut name, true, false);
        replace_version_runs_in_place(&mut name, false, false);
    }
    collapse_multi_spaces_in_place(&mut name);
    trim_title_in_place(&mut name);
    if name.contains(" RF ") {
        name = name.replace(" RF ", " ");
    }

    for _ in 0..2 {
        if count_digit_runs(name.as_bytes()) >= 2 {
            replace_version_runs_in_place(&mut name, true, false);
            replace_version_runs_in_place(&mut name, false, false);
            replace_ascii_wb_in_place(&mut name, |bytes, pos| {
                match_wb_version_run(bytes, pos, true)
            });
            replace_ascii_wb_in_place(&mut name, |bytes, pos| {
                match_wb_version_run(bytes, pos, false)
            });
        }
        collapse_multi_spaces_in_place(&mut name);
        trim_title_in_place(&mut name);
    }

    Some(name)
}

#[inline]
fn is_games_norm_single_noise(word: &[u8]) -> bool {
    matches!(
        word,
        b"goty"
            | b"remastered"
            | b"update"
            | b"patch"
            | b"hotfix"
            | b"dlcs"
            | b"dlc"
            | b"repack"
            | b"rip"
            | b"iso"
            | b"crackfix"
            | b"crack"
            | b"beta"
            | b"alpha"
            | b"pc"
            | b"gog"
            | b"steam"
            | b"x64"
            | b"x86"
            | b"win64"
            | b"win32"
            | b"english"
            | b"eng"
            | b"french"
            | b"fr"
            | b"german"
            | b"de"
            | b"spanish"
            | b"es"
            | b"italian"
            | b"it"
            | b"portuguese"
            | b"ptbr"
            | b"pt"
            | b"russian"
            | b"ru"
            | b"polish"
            | b"pl"
            | b"turkish"
            | b"tr"
            | b"dutch"
            | b"nl"
            | b"swedish"
            | b"se"
            | b"norwegian"
            | b"no"
            | b"danish"
            | b"da"
            | b"finnish"
            | b"fi"
            | b"japanese"
            | b"jp"
            | b"chs"
            | b"cht"
            | b"cn"
            | b"korean"
            | b"ko"
    )
}

pub fn games_normalize_for_match(input: &str) -> Option<String> {
    if input.contains('\n') || input.contains('\r') {
        return None;
    }
    let lower = php_mb_strtolower(input);
    let mut t = strip_games_scene_group_end(&lower).to_owned();

    replace_ascii_wb_in_place(&mut t, |bytes, pos| {
        let len = bytes.len();
        let mut w_end = pos;
        while w_end < len && is_ascii_word(bytes[w_end]) {
            w_end += 1;
        }
        if w_end == pos {
            return None;
        }
        let word = &bytes[pos..w_end];
        if is_games_norm_single_noise(word) {
            return Some(w_end);
        }
        if word.starts_with(b"mult") {
            let mut p = pos + 4;
            if p < len && matches!(bytes[p], b'i' | b'y') {
                p += 1;
            }
            while p < len && bytes[p].is_ascii_digit() {
                p += 1;
            }
            if p == w_end {
                return Some(w_end);
            }
        }
        match word {
            b"game" => {
                if pos + 16 <= len
                    && &bytes[pos..pos + 16] == b"game of the year"
                    && is_ascii_wb(bytes, pos + 16)
                {
                    return Some(pos + 16);
                }
            }
            b"hd" => {
                if pos + 11 <= len
                    && &bytes[pos..pos + 11] == b"hd remaster"
                    && is_ascii_wb(bytes, pos + 11)
                {
                    return Some(pos + 11);
                }
            }
            b"directors" | b"director" => {
                let cut_end = w_end + 4;
                if cut_end <= len
                    && &bytes[w_end..cut_end] == b" cut"
                    && is_ascii_wb(bytes, cut_end)
                {
                    return Some(cut_end);
                }
            }
            b"definitive" | b"deluxe" | b"ultimate" | b"complete" | b"anniversary" => {
                let ed_end = w_end + 8;
                if ed_end <= len
                    && &bytes[w_end..ed_end] == b" edition"
                    && is_ascii_wb(bytes, ed_end)
                {
                    return Some(ed_end);
                }
            }
            b"including" | b"incl" => {
                for dlc in [b" dlcs".as_slice(), b" dlc".as_slice()] {
                    let end = w_end + dlc.len();
                    if end <= len && &bytes[w_end..end] == dlc && is_ascii_wb(bytes, end) {
                        return Some(end);
                    }
                }
            }
            _ => {}
        }
        None
    });

    // Second pass for languages that might follow edition removal (identical to 2-step PHP)
    let mut out = String::with_capacity(t.len());
    let mut in_non_alnum = false;
    for &b in t.as_bytes() {
        if b.is_ascii_alphanumeric() {
            out.push(b as char);
            in_non_alnum = false;
        } else if !in_non_alnum {
            out.push(' ');
            in_non_alnum = true;
        }
    }
    Some(php_trim(&out).to_owned())
}

// ============================================================================
// BookMatchScorer & BookIsbn
// ============================================================================

pub fn book_isbn_normalize(value: &str) -> Option<String> {
    if value.is_empty() {
        return None;
    }
    let mut isbn = Vec::with_capacity(13);
    for &b in value.as_bytes() {
        if b.is_ascii_digit() {
            isbn.push(b);
        } else if matches!(b, b'x' | b'X') {
            isbn.push(b'X');
        }
    }
    match isbn.len() {
        10 => {
            if !isbn[..9].iter().all(|b| b.is_ascii_digit())
                || !(isbn[9].is_ascii_digit() || isbn[9] == b'X')
            {
                return None;
            }
            let mut sum = 0u32;
            for (idx, &b) in isbn.iter().enumerate() {
                let digit = if b == b'X' { 10 } else { u32::from(b - b'0') };
                sum += digit * (10 - idx as u32);
            }
            if sum.is_multiple_of(11) {
                String::from_utf8(isbn).ok()
            } else {
                None
            }
        }
        13 => {
            if !(isbn.starts_with(b"978") || isbn.starts_with(b"979"))
                || !isbn.iter().all(|b| b.is_ascii_digit())
            {
                return None;
            }
            let mut sum = 0u32;
            for (idx, &b) in isbn.iter().enumerate() {
                let digit = u32::from(b - b'0');
                let weight = if idx % 2 == 0 { 1 } else { 3 };
                sum += digit * weight;
            }
            if sum.is_multiple_of(10) {
                String::from_utf8(isbn).ok()
            } else {
                None
            }
        }
        _ => None,
    }
}

fn book_isbn_to_13(isbn10: &str) -> Option<String> {
    if isbn10.len() != 10 {
        return None;
    }
    let mut body = String::with_capacity(13);
    body.push_str("978");
    body.push_str(&isbn10[..9]);
    let mut sum = 0u32;
    for (idx, b) in body.bytes().enumerate() {
        let digit = u32::from(b - b'0');
        sum += digit * if idx % 2 == 0 { 1 } else { 3 };
    }
    let check = (10 - (sum % 10)) % 10;
    body.push(char::from(b'0' + check as u8));
    Some(body)
}

fn book_isbn_equivalent_norm(left_norm: &str, right_norm: &str) -> bool {
    if left_norm == right_norm {
        return true;
    }
    if left_norm.len() == 10
        && let Some(l13) = book_isbn_to_13(left_norm)
        && l13 == right_norm
    {
        return true;
    }
    if right_norm.len() == 10
        && let Some(r13) = book_isbn_to_13(right_norm)
        && r13 == left_norm
    {
        return true;
    }
    false
}

fn book_normalize_text(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    let mut last_ws = false;
    if value.is_ascii() {
        for &b in value.as_bytes() {
            if b.is_ascii_alphanumeric() {
                out.push(b.to_ascii_lowercase() as char);
                last_ws = false;
            } else if !last_ws && !out.is_empty() {
                out.push(' ');
                last_ws = true;
            }
        }
    } else {
        for ch in value.chars() {
            if ch.is_alphanumeric() {
                if ch == '\u{03A3}' {
                    out.push('\u{03C3}');
                } else {
                    for lower in ch.to_lowercase() {
                        out.push(lower);
                    }
                }
                last_ws = false;
            } else if !last_ws && !out.is_empty() {
                out.push(' ');
                last_ws = true;
            }
        }
    }
    if out.ends_with(' ') {
        out.pop();
    }
    out
}

fn find_subtitle_split_pos(value: &str) -> Option<usize> {
    if !value.contains(':') && !value.contains('-') {
        return None;
    }
    let mut char_indices = value.char_indices().peekable();
    while let Some((idx, ch)) = char_indices.next() {
        if ch == ':' {
            return Some(idx);
        }
        if ch.is_whitespace() {
            let ws_start = idx;
            let mut sep_pos = None;
            while let Some(&(next_idx, next_ch)) = char_indices.peek() {
                if next_ch.is_whitespace() {
                    char_indices.next();
                } else {
                    sep_pos = Some((next_idx, next_ch));
                    break;
                }
            }
            if let Some((_, sep_ch)) = sep_pos
                && matches!(sep_ch, ':' | '-')
            {
                char_indices.next();
                if sep_ch == ':' {
                    return Some(ws_start);
                }
                if let Some(&(_, after_ch)) = char_indices.peek()
                    && after_ch.is_whitespace()
                {
                    return Some(ws_start);
                }
            }
        }
    }
    None
}

fn book_title_variants(value: &str) -> (String, Option<String>) {
    let norm = book_normalize_text(value);
    let sub = if let Some(split_pos) = find_subtitle_split_pos(value) {
        let left_norm = book_normalize_text(&value[..split_pos]);
        if !left_norm.is_empty() && left_norm != "0" && left_norm != norm {
            Some(left_norm)
        } else {
            None
        }
    } else {
        None
    };
    (norm, sub)
}

pub fn book_title_score(left: &str, right: &str) -> f64 {
    let (norm_left, sub_left) = book_title_variants(left);
    let (norm_right, sub_right) = book_title_variants(right);

    let l_first_valid = !norm_left.is_empty() && norm_left != "0";
    let r_first_valid = !norm_right.is_empty() && norm_right != "0";

    if l_first_valid && r_first_valid && norm_left == norm_right {
        return 1.0;
    }
    if l_first_valid && sub_right.as_deref() == Some(norm_left.as_str()) {
        return 0.97;
    }
    if r_first_valid && sub_left.as_deref() == Some(norm_right.as_str()) {
        return 0.97;
    }
    if let (Some(sl), Some(sr)) = (&sub_left, &sub_right)
        && sl == sr
    {
        return 0.97;
    }

    if norm_left.is_empty() || norm_right.is_empty() {
        return 0.0;
    }
    if norm_left == norm_right {
        return 1.0;
    }

    let mut left_unique: Vec<&str> = Vec::with_capacity(8);
    for w in norm_left.split(' ') {
        if w.len() > 1 && (w.is_ascii() || w.chars().count() > 1) && !left_unique.contains(&w) {
            left_unique.push(w);
        }
    }
    let mut right_unique: Vec<&str> = Vec::with_capacity(8);
    for w in norm_right.split(' ') {
        if w.len() > 1 && (w.is_ascii() || w.chars().count() > 1) && !right_unique.contains(&w) {
            right_unique.push(w);
        }
    }

    if left_unique.is_empty() || right_unique.is_empty() {
        return 0.0;
    }

    let mut shared = 0usize;
    for lw in &left_unique {
        if right_unique.contains(lw) {
            shared += 1;
        }
    }
    let token_score = (2.0 * shared as f64) / ((left_unique.len() + right_unique.len()) as f64);

    let percent = similarity_percent(norm_left.as_bytes(), norm_right.as_bytes());
    let sim_text_score = (percent / 100.0).clamp(0.0, 1.0);

    token_score.max(sim_text_score).min(1.0)
}

fn collect_unique_words(norm: &str) -> Vec<&str> {
    if norm.is_empty() {
        return Vec::new();
    }
    let mut out: Vec<&str> = Vec::with_capacity(6);
    for part in norm.split(' ') {
        if !out.contains(&part) {
            out.push(part);
        }
    }
    out
}

fn author_token_coverage(needles: &[&str], haystack: &[&str]) -> f64 {
    let mut matched = 0usize;
    for &needle in needles {
        let needle_chars = if needle.is_ascii() {
            needle.len()
        } else {
            needle.chars().count()
        };
        for &cand in haystack {
            let cand_chars = if cand.is_ascii() {
                cand.len()
            } else {
                cand.chars().count()
            };
            if needle == cand
                || (needle_chars == 1 && cand.starts_with(needle))
                || (cand_chars == 1 && needle.starts_with(cand))
            {
                matched += 1;
                break;
            }
        }
    }
    matched as f64 / needles.len() as f64
}

pub fn book_author_score(left: &str, right: &str) -> f64 {
    let norm_left = book_normalize_text(left);
    let norm_right = book_normalize_text(right);
    if norm_left.is_empty() || norm_right.is_empty() {
        return 0.0;
    }
    let left_tokens = collect_unique_words(&norm_left);
    let right_tokens = collect_unique_words(&norm_right);
    if left_tokens.is_empty() || right_tokens.is_empty() {
        return 0.0;
    }
    let left_cov = author_token_coverage(&left_tokens, &right_tokens);
    let right_cov = author_token_coverage(&right_tokens, &left_tokens);
    (left_cov + right_cov) / 2.0
}

fn extract_volume_marker(value: &str) -> Option<i32> {
    let bytes = value.as_bytes();
    let len = bytes.len();
    for pos in 0..len {
        if pos > 0 && is_ascii_word(bytes[pos - 1]) {
            continue;
        }
        let mut kw_end = None;
        for kw in [
            b"volume".as_slice(),
            b"series".as_slice(),
            b"book".as_slice(),
            b"vol".as_slice(),
        ] {
            if pos + kw.len() <= len && bytes[pos..pos + kw.len()].eq_ignore_ascii_case(kw) {
                kw_end = Some(pos + kw.len());
                break;
            }
        }
        let Some(mut p) = kw_end else {
            continue;
        };
        if p < len && bytes[p] == b'.' {
            p += 1;
        }
        while p < len && bytes[p].is_ascii_whitespace() {
            p += 1;
        }
        if p < len && bytes[p] == b'#' {
            p += 1;
            while p < len && bytes[p].is_ascii_whitespace() {
                p += 1;
            }
        }
        let num_start = p;
        while p < len && bytes[p].is_ascii_digit() && p - num_start < 4 {
            p += 1;
        }
        let d_len = p - num_start;
        if (1..=3).contains(&d_len)
            && is_ascii_wb(bytes, p)
            && let Ok(s) = str::from_utf8(&bytes[num_start..p])
            && let Ok(v) = s.parse::<i32>()
        {
            return Some(v);
        }
    }
    None
}

fn extract_edition_marker(value: &str) -> Option<i32> {
    let bytes = value.as_bytes();
    let len = bytes.len();
    for pos in 0..len {
        if (pos > 0 && is_ascii_word(bytes[pos - 1])) || !bytes[pos].is_ascii_digit() {
            continue;
        }
        let mut p = pos;
        while p < len && bytes[p].is_ascii_digit() && p - pos < 4 {
            p += 1;
        }
        let d_len = p - pos;
        if !(1..=3).contains(&d_len) {
            continue;
        }
        let num_end = p;
        for suf in [
            b"st".as_slice(),
            b"nd".as_slice(),
            b"rd".as_slice(),
            b"th".as_slice(),
        ] {
            if p + 2 <= len && bytes[p..p + 2].eq_ignore_ascii_case(suf) {
                p += 2;
                break;
            }
        }
        let Some(mut q) = skip_ascii_ws1(bytes, p) else {
            continue;
        };
        let mut matched_ed = false;
        if q + 7 <= len
            && bytes[q..q + 7].eq_ignore_ascii_case(b"edition")
            && is_ascii_wb(bytes, q + 7)
        {
            matched_ed = true;
        } else if q + 2 <= len && bytes[q..q + 2].eq_ignore_ascii_case(b"ed") {
            q += 2;
            let ed_word_end = q;
            if q < len && bytes[q] == b'.' {
                q += 1;
            }
            if is_ascii_wb(bytes, q) || (q > ed_word_end && is_ascii_wb(bytes, ed_word_end)) {
                matched_ed = true;
            }
        }
        if matched_ed
            && let Ok(s) = str::from_utf8(&bytes[pos..num_end])
            && let Ok(v) = s.parse::<i32>()
        {
            return Some(v);
        }
    }
    None
}

pub fn book_has_structural_conflict(
    parsed_raw: &str,
    parsed_title: &str,
    cand_title: &str,
) -> bool {
    if !cand_title.as_bytes().iter().any(|b| b.is_ascii_digit()) {
        return false;
    }
    if !parsed_raw.as_bytes().iter().any(|b| b.is_ascii_digit())
        && !parsed_title.as_bytes().iter().any(|b| b.is_ascii_digit())
    {
        return false;
    }
    let c_vol = extract_volume_marker(cand_title);
    let c_ed = extract_edition_marker(cand_title);
    if c_vol.is_none() && c_ed.is_none() {
        return false;
    }

    if let Some(cv) = c_vol {
        let p_vol =
            extract_volume_marker(parsed_raw).or_else(|| extract_volume_marker(parsed_title));
        if let Some(pv) = p_vol
            && pv != cv
        {
            return true;
        }
    }
    if let Some(ce) = c_ed {
        let p_ed =
            extract_edition_marker(parsed_raw).or_else(|| extract_edition_marker(parsed_title));
        if let Some(pe) = p_ed
            && pe != ce
        {
            return true;
        }
    }
    false
}

#[allow(clippy::too_many_arguments)]
pub fn book_score_candidate(
    parsed_raw: &str,
    parsed_title: &str,
    parsed_author: Option<&str>,
    parsed_isbn: Option<&str>,
    cand_title: &str,
    cand_author: &str,
    cand_isbn: Option<&str>,
    cand_ean: Option<&str>,
) -> f64 {
    if let Some(p_raw_isbn) = parsed_isbn
        && (cand_isbn.is_some() || cand_ean.is_some())
        && let Some(p_isbn) = book_isbn_normalize(p_raw_isbn)
    {
        let mut any_cand_id = false;
        let mut first_id: Option<String> = None;
        if let Some(isbn) = cand_isbn.and_then(book_isbn_normalize) {
            any_cand_id = true;
            if !book_isbn_equivalent_norm(&p_isbn, &isbn) {
                return 0.0;
            }
            first_id = Some(isbn);
        }
        if let Some(ean) = cand_ean.and_then(book_isbn_normalize)
            && first_id.as_ref() != Some(&ean)
        {
            any_cand_id = true;
            if !book_isbn_equivalent_norm(&p_isbn, &ean) {
                return 0.0;
            }
        }
        if any_cand_id {
            return 1.0;
        }
    }

    if book_has_structural_conflict(parsed_raw, parsed_title, cand_title) {
        return 0.0;
    }

    let t_score = book_title_score(parsed_title, cand_title);
    let has_author = parsed_author.is_some_and(|a| !a.is_empty());
    if has_author {
        let a_score = book_author_score(parsed_author.unwrap_or(""), cand_author);
        (0.65 * t_score) + (0.35 * a_score)
    } else {
        t_score
    }
}

// ============================================================================
// 4. AnimeProcessor & RefreshAnimeData Title Extraction / Cleaning
// ============================================================================

#[inline]
fn is_pcre_ascii_ws(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\x0B' | b'\x0C' | b'\r')
}

#[inline]
fn is_pcre_utf8_ws_fast(c: char) -> bool {
    matches!(
        c,
        ' ' | '\t' | '\n' | '\x0B' | '\x0C' | '\r' | '\u{0085}' | '\u{00A0}'
    )
}

#[inline]
fn is_anime_control_byte(b: u8) -> bool {
    matches!(b, 0x00..=0x08 | 0x0B..=0x0C | 0x0E..=0x1F | 0x7F)
}

#[inline]
fn is_anime_lang_code(word: &[u8]) -> bool {
    matches!(word.len(), 3 | 5 | 7)
        && (word.eq_ignore_ascii_case(b"ENG")
            || word.eq_ignore_ascii_case(b"JAP")
            || word.eq_ignore_ascii_case(b"JPN")
            || word.eq_ignore_ascii_case(b"SUB")
            || word.eq_ignore_ascii_case(b"DUB")
            || word.eq_ignore_ascii_case(b"RAW")
            || word.eq_ignore_ascii_case(b"ITA")
            || word.eq_ignore_ascii_case(b"SPA")
            || word.eq_ignore_ascii_case(b"FRE")
            || word.eq_ignore_ascii_case(b"GER")
            || word.eq_ignore_ascii_case(b"RUS")
            || word.eq_ignore_ascii_case(b"CHI")
            || word.eq_ignore_ascii_case(b"KOR")
            || word.eq_ignore_ascii_case(b"MULTI")
            || word.eq_ignore_ascii_case(b"HARDSUB")
            || word.eq_ignore_ascii_case(b"SOFTSUB")
            || word.eq_ignore_ascii_case(b"HARDDUB")
            || word.eq_ignore_ascii_case(b"SOFTDUB"))
}

#[inline]
fn is_anime_meta_word(word: &[u8]) -> bool {
    word.eq_ignore_ascii_case(b"JAV")
        || word.eq_ignore_ascii_case(b"Uncensored")
        || word.eq_ignore_ascii_case(b"Censored")
        || word.eq_ignore_ascii_case(b"Mosaic")
        || word.eq_ignore_ascii_case(b"HD")
        || word.eq_ignore_ascii_case(b"SD")
        || word.eq_ignore_ascii_case(b"FHD")
        || word.eq_ignore_ascii_case(b"UHD")
}

#[inline]
fn is_anime_quality_word(word: &[u8]) -> bool {
    word.eq_ignore_ascii_case(b"480p")
        || word.eq_ignore_ascii_case(b"720p")
        || word.eq_ignore_ascii_case(b"1080p")
        || word.eq_ignore_ascii_case(b"2160p")
        || word.eq_ignore_ascii_case(b"4K")
        || word.eq_ignore_ascii_case(b"BD")
        || word.eq_ignore_ascii_case(b"BDRip")
        || word.eq_ignore_ascii_case(b"BluRay")
        || word.eq_ignore_ascii_case(b"HEVC")
        || word.eq_ignore_ascii_case(b"x264")
        || word.eq_ignore_ascii_case(b"x265")
        || word.eq_ignore_ascii_case(b"H264")
        || word.eq_ignore_ascii_case(b"H265")
        || word.eq_ignore_ascii_case(b"WEB")
        || word.eq_ignore_ascii_case(b"WEBRip")
        || word.eq_ignore_ascii_case(b"DVDRip")
        || word.eq_ignore_ascii_case(b"TVRip")
}

#[inline]
fn is_anime_release_tag_word(word: &[u8]) -> bool {
    word.eq_ignore_ascii_case(b"PROPER")
        || word.eq_ignore_ascii_case(b"REPACK")
        || word.eq_ignore_ascii_case(b"RIP")
        || word.eq_ignore_ascii_case(b"ISO")
        || word.eq_ignore_ascii_case(b"CRACK")
        || word.eq_ignore_ascii_case(b"BETA")
        || word.eq_ignore_ascii_case(b"ALPHA")
        || word.eq_ignore_ascii_case(b"FINAL")
        || word.eq_ignore_ascii_case(b"COMPLETE")
        || word.eq_ignore_ascii_case(b"FULL")
}

#[inline]
fn is_anime_bd_bracket_inner(inner: &[u8]) -> bool {
    if inner.eq_ignore_ascii_case(b"BD")
        || inner.eq_ignore_ascii_case(b"BDRip")
        || inner.eq_ignore_ascii_case(b"BluRay")
        || inner.eq_ignore_ascii_case(b"Blu-Ray")
        || inner.eq_ignore_ascii_case(b"HEVC")
        || inner.eq_ignore_ascii_case(b"x264")
        || inner.eq_ignore_ascii_case(b"x265")
        || inner.eq_ignore_ascii_case(b"H264")
        || inner.eq_ignore_ascii_case(b"H265")
    {
        return true;
    }
    if (inner.len() == 4 || inner.len() == 5)
        && matches!(
            inner[inner.len() - 1],
            b'i' | b'I' | b'p' | b'P' | b'x' | b'X'
        )
        && inner[..inner.len() - 1].iter().all(|b| b.is_ascii_digit())
    {
        return true;
    }
    false
}

pub fn anime_fix_encoding(input: &str) -> Option<Cow<'_, str>> {
    if input.is_ascii() {
        let bytes = input.as_bytes();
        if !bytes.iter().any(|&b| is_anime_control_byte(b)) {
            return Some(Cow::Borrowed(input));
        }
        let mut out = Vec::with_capacity(bytes.len());
        for &b in bytes {
            if !is_anime_control_byte(b) {
                out.push(b);
            }
        }
        return String::from_utf8(out).ok().map(Cow::Owned);
    }

    // Decline codepoints >= U+0300 (other than U+20AC '€') so PHP handles NFC normalization.
    let mut has_c0_c5 = false;
    let mut has_ctrl = false;
    for c in input.chars() {
        let u = c as u32;
        if u >= 0x0300 && c != '\u{20AC}' {
            return None;
        }
        if matches!(c, '\u{00C0}'..='\u{00C5}') {
            has_c0_c5 = true;
        } else if u < 0x80 && is_anime_control_byte(u as u8) {
            has_ctrl = true;
        }
    }

    if !has_c0_c5 {
        if !has_ctrl {
            return Some(Cow::Borrowed(input));
        }
        let mut out = String::with_capacity(input.len());
        for c in input.chars() {
            if (c as u32) >= 0x80 || !is_anime_control_byte(c as u8) {
                out.push(c);
            }
        }
        return Some(Cow::Owned(out));
    }

    // Pass 1: preg_replace('/Ã¢Â[_\sÂ]*/u', '', $text)
    let s1: Cow<'_, str> = if input.contains("Ã¢Â") {
        let mut buf = String::with_capacity(input.len());
        let mut rest = input;
        while !rest.is_empty() {
            if let Some(after) = rest.strip_prefix("Ã¢Â") {
                let mut consumed = 0usize;
                for c in after.chars() {
                    if c == '_' || c == 'Â' || is_pcre_utf8_ws_fast(c) {
                        consumed += c.len_utf8();
                    } else {
                        break;
                    }
                }
                rest = &after[consumed..];
            } else {
                let c = rest.chars().next().unwrap();
                buf.push(c);
                rest = &rest[c.len_utf8()..];
            }
        }
        Cow::Owned(buf)
    } else {
        Cow::Borrowed(input)
    };

    // Pass 2..6 combined into a single allocation
    let mut out = String::with_capacity(s1.len());
    let mut rest = s1.as_ref();
    while !rest.is_empty() {
        if let Some(after) = rest
            .strip_prefix("Ã¢")
            .or_else(|| rest.strip_prefix("ÃÂ"))
            .or_else(|| rest.strip_prefix("Ã©"))
            .or_else(|| rest.strip_prefix("Ã€"))
            .or_else(|| rest.strip_prefix("Ã£"))
        {
            rest = after;
            continue;
        }
        let c = rest.chars().next().unwrap();
        rest = &rest[c.len_utf8()..];
        if c == 'Â' {
            continue;
        }
        if c == 'Ã' {
            let mut consumed = 0usize;
            for next_c in rest.chars() {
                if !is_pcre_utf8_ws_fast(next_c) {
                    consumed += next_c.len_utf8();
                } else {
                    break;
                }
            }
            rest = &rest[consumed..];
            continue;
        }
        if (c as u32) < 0x80 && is_anime_control_byte(c as u8) {
            continue;
        }
        if matches!(c, '\u{00C0}'..='\u{00C5}') {
            continue;
        }
        out.push(c);
    }

    Some(Cow::Owned(out))
}

fn anime_clean_title_in_place(buf: &mut Vec<u8>, tmp: &mut Vec<u8>) {
    tmp.clear();

    // 1) Remove all bracketed tags: /\[[^\]]+\]/ -> ' '
    if buf.contains(&b'[') {
        let mut i = 0;
        while i < buf.len() {
            if buf[i] == b'['
                && let Some(rel) = buf[i + 1..].iter().position(|&b| b == b']')
                && rel >= 1
            {
                tmp.push(b' ');
                i += rel + 2;
                continue;
            }
            tmp.push(buf[i]);
            i += 1;
        }
        std::mem::swap(buf, tmp);
        tmp.clear();
    }

    // 2) Remove all parenthesized tags: /\([^)]+\)/ -> ' '
    if buf.contains(&b'(') {
        let mut i = 0;
        while i < buf.len() {
            if buf[i] == b'('
                && let Some(rel) = buf[i + 1..].iter().position(|&b| b == b')')
                && rel >= 1
            {
                tmp.push(b' ');
                i += rel + 2;
                continue;
            }
            tmp.push(buf[i]);
            i += 1;
        }
        std::mem::swap(buf, tmp);
        tmp.clear();
    }

    // 3, 4, 5) Replace standalone language codes, metadata words, and 6-digit dates with ' '.
    {
        let mut i = 0;
        while i < buf.len() {
            if is_ascii_word(buf[i]) {
                let start = i;
                while i < buf.len() && is_ascii_word(buf[i]) {
                    i += 1;
                }
                let word = &buf[start..i];
                let is_six_digits = word.len() == 6 && word.iter().all(|b| b.is_ascii_digit());
                if is_anime_lang_code(word) || is_anime_meta_word(word) || is_six_digits {
                    tmp.push(b' ');
                } else {
                    tmp.extend_from_slice(word);
                }
            } else {
                tmp.push(buf[i]);
                i += 1;
            }
        }
        std::mem::swap(buf, tmp);
        tmp.clear();
    }

    // 6) preg_replace('/[-_]\s*\d{1,4}\s*$/i', '', $title)
    {
        let mut p = buf.len();
        while p > 0 && is_pcre_ascii_ws(buf[p - 1]) {
            p -= 1;
        }
        let d_end = p;
        while p > 0 && buf[p - 1].is_ascii_digit() {
            p -= 1;
        }
        let d_len = d_end - p;
        if (1..=4).contains(&d_len) {
            while p > 0 && is_pcre_ascii_ws(buf[p - 1]) {
                p -= 1;
            }
            if p > 0 && (buf[p - 1] == b'-' || buf[p - 1] == b'_') {
                buf.truncate(p - 1);
            }
        }
    }

    // 7) preg_replace('/\s+\d{1,4}\s*$/i', '', $title)
    {
        let mut p = buf.len();
        while p > 0 && is_pcre_ascii_ws(buf[p - 1]) {
            p -= 1;
        }
        let d_end = p;
        while p > 0 && buf[p - 1].is_ascii_digit() {
            p -= 1;
        }
        let d_len = d_end - p;
        if (1..=4).contains(&d_len) {
            let ws_end = p;
            while p > 0 && is_pcre_ascii_ws(buf[p - 1]) {
                p -= 1;
            }
            if p < ws_end {
                buf.truncate(p);
            }
        }
    }

    let has_dash = buf.contains(&b'-');

    // 8) preg_replace('/\s*-\s*\d+x\d+.*$/i', '', $title)
    if has_dash {
        for dash in 0..buf.len() {
            if buf[dash] != b'-' {
                continue;
            }
            let mut p = dash + 1;
            while p < buf.len() && is_pcre_ascii_ws(buf[p]) {
                p += 1;
            }
            let d1_start = p;
            while p < buf.len() && buf[p].is_ascii_digit() {
                p += 1;
            }
            if p > d1_start
                && p < buf.len()
                && buf[p].eq_ignore_ascii_case(&b'x')
                && p + 1 < buf.len()
                && buf[p + 1].is_ascii_digit()
            {
                let mut cut = dash;
                while cut > 0 && is_pcre_ascii_ws(buf[cut - 1]) {
                    cut -= 1;
                }
                buf.truncate(cut);
                break;
            }
        }
    }

    // 9) preg_replace('/\s+S\d+E\d+.*$/i', '', $title)
    for pos in 1..buf.len() {
        if !buf[pos].eq_ignore_ascii_case(&b's') || !is_pcre_ascii_ws(buf[pos - 1]) {
            continue;
        }
        let mut p = pos + 1;
        let d1_start = p;
        while p < buf.len() && buf[p].is_ascii_digit() {
            p += 1;
        }
        if p > d1_start
            && p < buf.len()
            && buf[p].eq_ignore_ascii_case(&b'e')
            && p + 1 < buf.len()
            && buf[p + 1].is_ascii_digit()
        {
            let mut cut = pos;
            while cut > 0 && is_pcre_ascii_ws(buf[cut - 1]) {
                cut -= 1;
            }
            buf.truncate(cut);
            break;
        }
    }

    // 10) preg_replace('/\s*-\s*\d{1,4}(?:\s*-\s*.*)?\s*$/i', '', $title)
    if has_dash {
        for dash in 0..buf.len() {
            if buf[dash] != b'-' {
                continue;
            }
            let mut p = dash + 1;
            while p < buf.len() && is_pcre_ascii_ws(buf[p]) {
                p += 1;
            }
            let d_start = p;
            while p < buf.len() && buf[p].is_ascii_digit() {
                p += 1;
            }
            let d_len = p - d_start;
            if !(1..=4).contains(&d_len) {
                continue;
            }
            while p < buf.len() && is_pcre_ascii_ws(buf[p]) {
                p += 1;
            }
            if p == buf.len() || buf[p] == b'-' {
                let mut cut = dash;
                while cut > 0 && is_pcre_ascii_ws(buf[cut - 1]) {
                    cut -= 1;
                }
                buf.truncate(cut);
                break;
            }
        }

        // 11) preg_replace('/\s*-\s*$/i', '', $title)
        let mut p = buf.len();
        while p > 0 && is_pcre_ascii_ws(buf[p - 1]) {
            p -= 1;
        }
        if p > 0 && buf[p - 1] == b'-' {
            let mut cut = p - 1;
            while cut > 0 && is_pcre_ascii_ws(buf[cut - 1]) {
                cut -= 1;
            }
            buf.truncate(cut);
        }
    }

    // 12) preg_replace('/\s+E(?:p(?:isode)?)?\s*0*\d{1,4}\s*$/i', '', $title)
    for pos in 1..buf.len() {
        if !buf[pos].eq_ignore_ascii_case(&b'e') || !is_pcre_ascii_ws(buf[pos - 1]) {
            continue;
        }
        let mut p = pos + 1;
        if p < buf.len() && buf[p].eq_ignore_ascii_case(&b'p') {
            p += 1;
            if p + 5 <= buf.len() && buf[p..p + 5].eq_ignore_ascii_case(b"isode") {
                p += 5;
            }
        }
        while p < buf.len() && is_pcre_ascii_ws(buf[p]) {
            p += 1;
        }
        let d_start = p;
        let mut zeros = 0usize;
        while p < buf.len() && buf[p] == b'0' {
            zeros += 1;
            p += 1;
        }
        while p < buf.len() && buf[p].is_ascii_digit() {
            p += 1;
        }
        let total_digits = p - d_start;
        if total_digits >= 1 && total_digits - zeros <= 4 {
            while p < buf.len() && is_pcre_ascii_ws(buf[p]) {
                p += 1;
            }
            if p == buf.len() {
                let mut cut = pos;
                while cut > 0 && is_pcre_ascii_ws(buf[cut - 1]) {
                    cut -= 1;
                }
                buf.truncate(cut);
                break;
            }
        }
    }

    // 13 & 14) Quality/resolution tags & common release tags -> ' '
    {
        let mut i = 0;
        while i < buf.len() {
            if is_ascii_word(buf[i]) {
                if i + 7 <= buf.len()
                    && buf[i..i + 7].eq_ignore_ascii_case(b"blu-ray")
                    && is_ascii_wb(buf, i + 7)
                {
                    tmp.push(b' ');
                    i += 7;
                    continue;
                }
                let start = i;
                while i < buf.len() && is_ascii_word(buf[i]) {
                    i += 1;
                }
                let word = &buf[start..i];
                if is_anime_quality_word(word) || is_anime_release_tag_word(word) {
                    tmp.push(b' ');
                } else {
                    tmp.extend_from_slice(word);
                }
            } else {
                tmp.push(buf[i]);
                i += 1;
            }
        }
        std::mem::swap(buf, tmp);
        tmp.clear();
    }

    // 15) preg_replace('/\s+Vol\.?\s*\d*\s*$/i', '', $title)
    for pos in 1..buf.len() {
        if !is_pcre_ascii_ws(buf[pos - 1])
            || pos + 3 > buf.len()
            || !buf[pos..pos + 3].eq_ignore_ascii_case(b"vol")
        {
            continue;
        }
        let mut p = pos + 3;
        if p < buf.len() && buf[p] == b'.' {
            p += 1;
        }
        while p < buf.len() && is_pcre_ascii_ws(buf[p]) {
            p += 1;
        }
        while p < buf.len() && buf[p].is_ascii_digit() {
            p += 1;
        }
        while p < buf.len() && is_pcre_ascii_ws(buf[p]) {
            p += 1;
        }
        if p == buf.len() {
            let mut cut = pos;
            while cut > 0 && is_pcre_ascii_ws(buf[cut - 1]) {
                cut -= 1;
            }
            buf.truncate(cut);
            break;
        }
    }

    // 16) preg_replace('/\s+Ch\.?\s*\d*\s*$/i', '', $title)
    for pos in 1..buf.len() {
        if !is_pcre_ascii_ws(buf[pos - 1])
            || pos + 2 > buf.len()
            || !buf[pos..pos + 2].eq_ignore_ascii_case(b"ch")
        {
            continue;
        }
        let mut p = pos + 2;
        if p < buf.len() && buf[p] == b'.' {
            p += 1;
        }
        while p < buf.len() && is_pcre_ascii_ws(buf[p]) {
            p += 1;
        }
        while p < buf.len() && buf[p].is_ascii_digit() {
            p += 1;
        }
        while p < buf.len() && is_pcre_ascii_ws(buf[p]) {
            p += 1;
        }
        if p == buf.len() {
            let mut cut = pos;
            while cut > 0 && is_pcre_ascii_ws(buf[cut - 1]) {
                cut -= 1;
            }
            buf.truncate(cut);
            break;
        }
    }

    // 17) preg_replace('/\s*[-_]\s*$/', '', $title)
    {
        let mut p = buf.len();
        while p > 0 && is_pcre_ascii_ws(buf[p - 1]) {
            p -= 1;
        }
        if p > 0 && (buf[p - 1] == b'-' || buf[p - 1] == b'_') {
            let mut cut = p - 1;
            while cut > 0 && is_pcre_ascii_ws(buf[cut - 1]) {
                cut -= 1;
            }
            buf.truncate(cut);
        }
    }

    // 18) Normalize whitespace and trim into `tmp`, then swap into `buf`.
    let mut in_ws = false;
    for &b in buf.iter() {
        if is_pcre_ascii_ws(b) {
            if !tmp.is_empty() {
                in_ws = true;
            }
        } else {
            if in_ws {
                tmp.push(b' ');
                in_ws = false;
            }
            tmp.push(b);
        }
    }
    while let Some(&b) = tmp.last() {
        if is_php_trim_byte(b) {
            tmp.pop();
        } else {
            break;
        }
    }
    let mut start = 0;
    while start < tmp.len() && is_php_trim_byte(tmp[start]) {
        start += 1;
    }
    if start > 0 {
        tmp.drain(0..start);
    }
    std::mem::swap(buf, tmp);
}

pub fn anime_clean_title(input: &str) -> Option<String> {
    let fixed = anime_fix_encoding(input)?;
    if fixed.as_bytes().contains(&b'\n') {
        return None;
    }
    let mut buf = fixed.into_owned().into_bytes();
    let mut tmp = Vec::with_capacity(buf.len());
    anime_clean_title_in_place(&mut buf, &mut tmp);
    String::from_utf8(buf).ok()
}

fn find_anime_episode_truncation(s: &[u8]) -> usize {
    let len = s.len();

    // 1) /\sS\d+E\d+/i
    for i in 0..len {
        if !is_pcre_ascii_ws(s[i]) || i + 1 >= len || !s[i + 1].eq_ignore_ascii_case(&b's') {
            continue;
        }
        let mut p = i + 2;
        let d1_start = p;
        while p < len && s[p].is_ascii_digit() {
            p += 1;
        }
        if p > d1_start
            && p < len
            && s[p].eq_ignore_ascii_case(&b'e')
            && p + 1 < len
            && s[p + 1].is_ascii_digit()
        {
            return i;
        }
    }

    // 2) /\s\d+x\d+/i
    for i in 0..len {
        if !is_pcre_ascii_ws(s[i]) {
            continue;
        }
        let mut p = i + 1;
        let d1_start = p;
        while p < len && s[p].is_ascii_digit() {
            p += 1;
        }
        if p > d1_start
            && p < len
            && s[p].eq_ignore_ascii_case(&b'x')
            && p + 1 < len
            && s[p + 1].is_ascii_digit()
        {
            return i;
        }
    }

    // 3) /\s-\s*(\d{1,3})\b/
    for i in 0..len {
        if !is_pcre_ascii_ws(s[i]) || i + 1 >= len || s[i + 1] != b'-' {
            continue;
        }
        let mut p = i + 2;
        while p < len && is_pcre_ascii_ws(s[p]) {
            p += 1;
        }
        let d_start = p;
        while p < len && s[p].is_ascii_digit() && p - d_start < 4 {
            p += 1;
        }
        let d_len = p - d_start;
        if (1..=3).contains(&d_len) && is_ascii_wb(s, p) {
            return i;
        }
    }

    // 4) /\sE(?:p(?:isode)?)?\s*0*(\d{1,3})\b/i
    for i in 0..len {
        if !is_pcre_ascii_ws(s[i]) || i + 1 >= len || !s[i + 1].eq_ignore_ascii_case(&b'e') {
            continue;
        }
        let mut p = i + 2;
        if p < len && s[p].eq_ignore_ascii_case(&b'p') {
            p += 1;
            if p + 5 <= len && s[p..p + 5].eq_ignore_ascii_case(b"isode") {
                p += 5;
            }
        }
        while p < len && is_pcre_ascii_ws(s[p]) {
            p += 1;
        }
        let d_start = p;
        let mut zeros = 0usize;
        while p < len && s[p] == b'0' {
            zeros += 1;
            p += 1;
        }
        while p < len && s[p].is_ascii_digit() {
            p += 1;
        }
        let total_digits = p - d_start;
        if total_digits >= 1 && total_digits - zeros <= 3 && is_ascii_wb(s, p) {
            return i;
        }
    }

    // 5) /\b(Movie|OVA|Complete Series|Complete|Full Series)\b/i
    for i in 0..len {
        if i > 0 && is_ascii_word(s[i - 1]) {
            continue;
        }
        for kw in [
            b"movie".as_slice(),
            b"ova".as_slice(),
            b"complete".as_slice(),
            b"full series".as_slice(),
        ] {
            if i + kw.len() <= len
                && s[i..i + kw.len()].eq_ignore_ascii_case(kw)
                && is_ascii_wb(s, i + kw.len())
            {
                return i;
            }
        }
    }

    // 6) /\[(?:BD|BDRip|BluRay|Blu-Ray|\d{3,4}[ipx]|HEVC|x264|x265|H264|H265)\]/i
    for i in 0..len {
        if s[i] != b'[' {
            continue;
        }
        if let Some(rel) = s[i + 1..].iter().position(|&b| b == b']')
            && is_anime_bd_bracket_inner(&s[i + 1..i + 1 + rel])
        {
            return i;
        }
    }

    len
}

fn anime_extract_title_into(input: &str, buf: &mut Vec<u8>, tmp: &mut Vec<u8>) -> bool {
    let Some(fixed) = anime_fix_encoding(input) else {
        return false;
    };
    let fixed_bytes = fixed.as_bytes();

    buf.clear();
    tmp.clear();

    // Normalize common separators ('_', '.') and whitespace ('\s+'), then trim.
    let mut in_ws = false;
    for &b in fixed_bytes {
        if b == b'_' || b == b'.' || is_pcre_ascii_ws(b) {
            if !buf.is_empty() {
                in_ws = true;
            }
        } else {
            if in_ws {
                buf.push(b' ');
                in_ws = false;
            }
            buf.push(b);
        }
    }

    // Strip leading group tags like [Group]: /^(?:\[[^\]]+\]\s*)+/ and trim.
    let mut start = 0usize;
    while start < buf.len() && buf[start] == b'[' {
        if let Some(rel) = buf[start + 1..].iter().position(|&b| b == b']')
            && rel >= 1
        {
            start += rel + 2;
            while start < buf.len() && is_pcre_ascii_ws(buf[start]) {
                start += 1;
            }
        } else {
            break;
        }
    }
    let mut end = buf.len();
    while start < end && is_php_trim_byte(buf[start]) {
        start += 1;
    }
    while end > start && is_php_trim_byte(buf[end - 1]) {
        end -= 1;
    }
    let stripped = &buf[start..end];

    // Remove bracketed and parenthesized language tags: [LANG] and (LANG) -> ' '
    if stripped.contains(&b'[') || stripped.contains(&b'(') {
        let mut i = 0usize;
        while i < stripped.len() {
            if stripped[i] == b'['
                && let Some(rel) = stripped[i + 1..].iter().position(|&b| b == b']')
                && is_anime_lang_code(&stripped[i + 1..i + 1 + rel])
            {
                tmp.push(b' ');
                i += rel + 2;
                continue;
            }
            if stripped[i] == b'('
                && let Some(rel) = stripped[i + 1..].iter().position(|&b| b == b')')
                && is_anime_lang_code(&stripped[i + 1..i + 1 + rel])
            {
                tmp.push(b' ');
                i += rel + 2;
                continue;
            }
            tmp.push(stripped[i]);
            i += 1;
        }
        let cut = find_anime_episode_truncation(tmp);
        tmp.truncate(cut);
        std::mem::swap(buf, tmp);
        anime_clean_title_in_place(buf, tmp);
    } else {
        if start > 0 || end < buf.len() {
            buf.copy_within(start..end, 0);
            buf.truncate(end - start);
        }
        let cut = find_anime_episode_truncation(buf);
        buf.truncate(cut);
        anime_clean_title_in_place(buf, tmp);
    }

    true
}

pub fn anime_extract_title(input: &str) -> Option<String> {
    let mut buf = Vec::with_capacity(input.len());
    let mut tmp = Vec::with_capacity(input.len());
    if !anime_extract_title_into(input, &mut buf, &mut tmp) {
        return None;
    }
    String::from_utf8(buf).ok()
}

// ============================================================================
// C-ABI Exports
// ============================================================================

#[inline]
unsafe fn slice_as_str<'a>(ptr: *const u8, len: usize) -> Option<&'a str> {
    if len == 0 {
        return Some("");
    }
    if ptr.is_null() {
        return None;
    }
    let bytes = unsafe { slice::from_raw_parts(ptr, len) };
    str::from_utf8(bytes).ok()
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_steam_clean_title(
    input_ptr: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(input) = (unsafe { slice_as_str(input_ptr, input_len) }) else {
        return -1;
    };
    let Some(cleaned) = steam_clean_title(input) else {
        return -1;
    };
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    cursor.extend(cleaned.as_bytes());
    cursor.finish()
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_steam_normalize_title(
    input_ptr: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(input) = (unsafe { slice_as_str(input_ptr, input_len) }) else {
        return -1;
    };
    if input.contains('\n') || input.contains('\r') {
        return -1;
    }
    let norm = steam_normalize_title(input);
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    cursor.extend(norm.as_bytes());
    cursor.finish()
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_steam_replace_roman_numerals(
    input_ptr: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(input) = (unsafe { slice_as_str(input_ptr, input_len) }) else {
        return -1;
    };
    let replaced = steam_replace_roman_numerals(input);
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    cursor.extend(replaced.as_bytes());
    cursor.finish()
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_steam_strip_edition_tags(
    input_ptr: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(input) = (unsafe { slice_as_str(input_ptr, input_len) }) else {
        return -1;
    };
    if input.contains('\n') || input.contains('\r') {
        return -1;
    }
    let stripped = steam_strip_edition_tags(input);
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    cursor.extend(stripped.as_bytes());
    cursor.finish()
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_steam_score_title(
    cand_ptr: *const u8,
    cand_len: usize,
    orig_ptr: *const u8,
    orig_len: usize,
) -> f64 {
    let Some(cand) = (unsafe { slice_as_str(cand_ptr, cand_len) }) else {
        return -1.0;
    };
    let Some(orig) = (unsafe { slice_as_str(orig_ptr, orig_len) }) else {
        return -1.0;
    };
    if cand.contains('\n') || cand.contains('\r') || orig.contains('\n') || orig.contains('\r') {
        return -1.0;
    }
    steam_score_title(cand, orig)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_games_clean_release_name(
    input_ptr: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(input) = (unsafe { slice_as_str(input_ptr, input_len) }) else {
        return -1;
    };
    let Some(cleaned) = games_clean_release_name(input) else {
        return -1;
    };
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    cursor.extend(cleaned.as_bytes());
    cursor.finish()
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_games_normalize_for_match(
    input_ptr: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(input) = (unsafe { slice_as_str(input_ptr, input_len) }) else {
        return -1;
    };
    let Some(norm) = games_normalize_for_match(input) else {
        return -1;
    };
    let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
    cursor.extend(norm.as_bytes());
    cursor.finish()
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_book_title_score(
    left_ptr: *const u8,
    left_len: usize,
    right_ptr: *const u8,
    right_len: usize,
) -> f64 {
    let Some(left) = (unsafe { slice_as_str(left_ptr, left_len) }) else {
        return -1.0;
    };
    let Some(right) = (unsafe { slice_as_str(right_ptr, right_len) }) else {
        return -1.0;
    };
    book_title_score(left, right)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_book_author_score(
    left_ptr: *const u8,
    left_len: usize,
    right_ptr: *const u8,
    right_len: usize,
) -> f64 {
    let Some(left) = (unsafe { slice_as_str(left_ptr, left_len) }) else {
        return -1.0;
    };
    let Some(right) = (unsafe { slice_as_str(right_ptr, right_len) }) else {
        return -1.0;
    };
    book_author_score(left, right)
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_book_score(
    raw_ptr: *const u8,
    raw_len: usize,
    title_ptr: *const u8,
    title_len: usize,
    has_author: u32,
    author_ptr: *const u8,
    author_len: usize,
    has_isbn: u32,
    isbn_ptr: *const u8,
    isbn_len: usize,
    cand_title_ptr: *const u8,
    cand_title_len: usize,
    cand_author_ptr: *const u8,
    cand_author_len: usize,
    has_cand_isbn: u32,
    cand_isbn_ptr: *const u8,
    cand_isbn_len: usize,
    has_cand_ean: u32,
    cand_ean_ptr: *const u8,
    cand_ean_len: usize,
) -> f64 {
    let Some(parsed_raw) = (unsafe { slice_as_str(raw_ptr, raw_len) }) else {
        return -1.0;
    };
    let Some(parsed_title) = (unsafe { slice_as_str(title_ptr, title_len) }) else {
        return -1.0;
    };
    let parsed_author = if has_author != 0 {
        let Some(a) = (unsafe { slice_as_str(author_ptr, author_len) }) else {
            return -1.0;
        };
        Some(a)
    } else {
        None
    };
    let parsed_isbn = if has_isbn != 0 {
        let Some(i) = (unsafe { slice_as_str(isbn_ptr, isbn_len) }) else {
            return -1.0;
        };
        Some(i)
    } else {
        None
    };
    let Some(cand_title) = (unsafe { slice_as_str(cand_title_ptr, cand_title_len) }) else {
        return -1.0;
    };
    let Some(cand_author) = (unsafe { slice_as_str(cand_author_ptr, cand_author_len) }) else {
        return -1.0;
    };
    let cand_isbn = if has_cand_isbn != 0 {
        let Some(i) = (unsafe { slice_as_str(cand_isbn_ptr, cand_isbn_len) }) else {
            return -1.0;
        };
        Some(i)
    } else {
        None
    };
    let cand_ean = if has_cand_ean != 0 {
        let Some(e) = (unsafe { slice_as_str(cand_ean_ptr, cand_ean_len) }) else {
            return -1.0;
        };
        Some(e)
    } else {
        None
    };

    book_score_candidate(
        parsed_raw,
        parsed_title,
        parsed_author,
        parsed_isbn,
        cand_title,
        cand_author,
        cand_isbn,
        cand_ean,
    )
}

thread_local! {
    static ANIME_SCRATCH: std::cell::RefCell<(Vec<u8>, Vec<u8>)> =
        std::cell::RefCell::new((Vec::with_capacity(256), Vec::with_capacity(256)));
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_anime_extract_title(
    input_ptr: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(input) = (unsafe { slice_as_str(input_ptr, input_len) }) else {
        return -2;
    };
    ANIME_SCRATCH.with(|cell| {
        let (buf, tmp) = &mut *cell.borrow_mut();
        if !anime_extract_title_into(input, buf, tmp) {
            return -2;
        }
        let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
        cursor.extend(buf);
        cursor.finish()
    })
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_anime_clean_title(
    input_ptr: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(input) = (unsafe { slice_as_str(input_ptr, input_len) }) else {
        return -2;
    };
    let Some(fixed) = anime_fix_encoding(input) else {
        return -2;
    };
    if fixed.as_bytes().contains(&b'\n') {
        return -2;
    }
    ANIME_SCRATCH.with(|cell| {
        let (buf, tmp) = &mut *cell.borrow_mut();
        buf.clear();
        buf.extend_from_slice(fixed.as_bytes());
        anime_clean_title_in_place(buf, tmp);
        let mut cursor = unsafe { OutCursor::new(out_buf, out_cap) };
        cursor.extend(buf);
        cursor.finish()
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_steam_clean_and_normalize_and_score() {
        assert_eq!(
            steam_clean_title("Cyberpunk.2077-GOG").as_deref(),
            Some("Cyberpunk 2077")
        );
        assert_eq!(
            steam_clean_title("ELDEN RING [v1.08 + DLCs] - EMPRESS").as_deref(),
            Some("ELDEN RING")
        );
        assert_eq!(
            steam_clean_title("[FitGirl] Red.Dead.Redemption.2.v1.0.1436.28.Repack").as_deref(),
            Some("Red Dead Redemption 2")
        );
        assert_eq!(
            steam_normalize_title("The.Witcher.III.Wild.Hunt.GOTY-FLT"),
            "witcher 3 wild hunt"
        );
        assert_eq!(
            steam_score_title(
                "The Witcher 3: Wild Hunt",
                "The.Witcher.III.Wild.Hunt.GOTY-FLT"
            ),
            100.0
        );
    }

    #[test]
    fn test_games_clean_and_normalize() {
        assert_eq!(
            games_clean_release_name("ELDEN RING - Deluxe Edition [v1.08 + DLCs] - EMPRESS")
                .as_deref(),
            Some("ELDEN RING")
        );
        assert_eq!(
            games_clean_release_name("Starfield.Update.1.7.29.Patch-FLT").as_deref(),
            Some("Starfield")
        );
        assert_eq!(
            games_clean_release_name("Forza.Horizon.5.Incl.DLCs.MULTi10-ElAmigos").as_deref(),
            Some("Forza Horizon 5")
        );
        assert_eq!(
            games_normalize_for_match("Cyberpunk.2077.GOTY.MULTi12-CODEX").as_deref(),
            Some("cyberpunk 2077")
        );
    }

    #[test]
    fn test_book_score() {
        let s = book_score_candidate(
            "Clean Code 9780132350884",
            "Clean Code",
            None,
            Some("9780132350884"),
            "Clean Code",
            "Robert C. Martin",
            None,
            Some("0132350882"),
        );
        assert_eq!(s, 1.0);

        let s2 = book_score_candidate(
            "Dune Book 2",
            "Dune Book 2",
            Some("Frank Herbert"),
            None,
            "Dune Book 3",
            "Frank Herbert",
            None,
            None,
        );
        assert_eq!(s2, 0.0);
    }

    #[test]
    fn test_anime_extract_and_clean() {
        assert_eq!(
            anime_extract_title("[HorribleSubs] My Hero Academia - 12 [1080p].mkv").as_deref(),
            Some("My Hero Academia")
        );
        assert_eq!(
            anime_extract_title("[Group] Neon_Genesis.Evangelion E01 [720p]").as_deref(),
            Some("Neon Genesis Evangelion")
        );
        assert_eq!(
            anime_extract_title("[SomeGroup] Cowboy Bebop [BD][1080p]").as_deref(),
            Some("Cowboy Bebop")
        );
        assert_eq!(
            anime_extract_title("Attack on Titan - Complete Series [BD]").as_deref(),
            Some("Attack on Titan")
        );
    }
}

// ---------------------------------------------------------------------------
// PAR2 metadata / proof identity accelerators.
//
// PHP passes `serialize()`d inputs and receives `serialize()`-format output, so both
// marshaling directions run in PHP's C runtime. Any shape whose PHP semantics are not
// reproduced exactly (references, objects, warnings, numeric-string keys, float identity)
// returns `PAR2_FALLBACK` and the caller runs the native PHP implementation.
// ---------------------------------------------------------------------------

const PAR2_FALLBACK: isize = -1;
const PHP_SER_MAX_DEPTH: usize = 128;

#[derive(Clone, Copy, PartialEq, Eq)]
enum PhpKey<'a> {
    Int(i64),
    Str(&'a [u8]),
}

enum PhpVal<'a> {
    Null,
    Bool(bool),
    Int(i64),
    Float,
    Str(&'a [u8]),
    Arr(Vec<(PhpKey<'a>, PhpNode<'a>)>),
}

struct PhpNode<'a> {
    val: PhpVal<'a>,
    raw: &'a [u8],
    has_float: bool,
}

const PHP_NULL_RAW: &[u8] = b"N;";
const PHP_EMPTY_ARR_RAW: &[u8] = b"a:0:{}";

impl<'a> PhpNode<'a> {
    #[inline]
    fn get(&self, key: &[u8]) -> Option<&PhpNode<'a>> {
        match &self.val {
            PhpVal::Arr(items) => items.iter().find_map(|(k, v)| match k {
                PhpKey::Str(s) if *s == key => Some(v),
                _ => None,
            }),
            _ => None,
        }
    }

    #[inline]
    fn get_int_key(&self, key: i64) -> Option<&PhpNode<'a>> {
        match &self.val {
            PhpVal::Arr(items) => items.iter().find_map(|(k, v)| match k {
                PhpKey::Int(i) if *i == key => Some(v),
                _ => None,
            }),
            _ => None,
        }
    }

    /// `$arr[$key] ?? null` with a missing key or `null` value collapsed to `None`.
    #[inline]
    fn coalesce(&self, key: &[u8]) -> Option<&PhpNode<'a>> {
        self.get(key).filter(|n| !matches!(n.val, PhpVal::Null))
    }

    #[inline]
    fn as_str(&self) -> Option<&'a [u8]> {
        match self.val {
            PhpVal::Str(s) => Some(s),
            _ => None,
        }
    }

    #[inline]
    fn as_int(&self) -> Option<i64> {
        match self.val {
            PhpVal::Int(i) => Some(i),
            _ => None,
        }
    }

    #[inline]
    fn is_true(&self) -> bool {
        matches!(self.val, PhpVal::Bool(true))
    }

    #[inline]
    fn is_false(&self) -> bool {
        matches!(self.val, PhpVal::Bool(false))
    }

    #[inline]
    fn is_arr(&self) -> bool {
        matches!(self.val, PhpVal::Arr(_))
    }

    #[inline]
    fn items(&self) -> &[(PhpKey<'a>, PhpNode<'a>)] {
        match &self.val {
            PhpVal::Arr(items) => items,
            _ => &[],
        }
    }
}

#[inline]
fn opt_raw<'a>(node: Option<&PhpNode<'a>>) -> &'a [u8] {
    node.map_or(PHP_NULL_RAW, |n| n.raw)
}

#[inline]
fn opt_is_true(node: Option<&PhpNode<'_>>) -> bool {
    node.is_some_and(PhpNode::is_true)
}

#[inline]
fn opt_is_false(node: Option<&PhpNode<'_>>) -> bool {
    node.is_some_and(PhpNode::is_false)
}

#[inline]
fn opt_str<'a>(node: Option<&PhpNode<'a>>) -> Option<&'a [u8]> {
    node.and_then(PhpNode::as_str)
}

#[inline]
fn opt_int(node: Option<&PhpNode<'_>>) -> Option<i64> {
    node.and_then(PhpNode::as_int)
}

/// `($a ?? null) === ($b ?? null)`; `None` when float identity would be required.
#[inline]
fn php_identical(a: Option<&PhpNode<'_>>, b: Option<&PhpNode<'_>>) -> Option<bool> {
    if a.is_some_and(|n| n.has_float) || b.is_some_and(|n| n.has_float) {
        return None;
    }
    Some(opt_raw(a) == opt_raw(b))
}

struct PhpSerParser<'a> {
    data: &'a [u8],
    pos: usize,
}

impl<'a> PhpSerParser<'a> {
    fn parse_document(data: &'a [u8]) -> Option<PhpNode<'a>> {
        let mut parser = Self { data, pos: 0 };
        let node = parser.value(0)?;
        (parser.pos == data.len()).then_some(node)
    }

    #[inline]
    fn expect(&mut self, byte: u8) -> Option<()> {
        (self.data.get(self.pos) == Some(&byte)).then(|| self.pos += 1)
    }

    fn int_until(&mut self, term: u8) -> Option<i64> {
        let start = self.pos;
        let mut neg = false;
        if self.data.get(self.pos) == Some(&b'-') {
            neg = true;
            self.pos += 1;
        }
        let digits_start = self.pos;
        let mut value: i64 = 0;
        while let Some(&b) = self.data.get(self.pos) {
            if !b.is_ascii_digit() {
                break;
            }
            let digit = i64::from(b - b'0');
            value = if neg {
                value.checked_mul(10)?.checked_sub(digit)?
            } else {
                value.checked_mul(10)?.checked_add(digit)?
            };
            self.pos += 1;
        }
        if self.pos == digits_start || self.pos - start > 21 {
            return None;
        }
        self.expect(term)?;
        Some(value)
    }

    fn string_body(&mut self) -> Option<&'a [u8]> {
        let len = usize::try_from(self.int_until(b':')?).ok()?;
        self.expect(b'"')?;
        let end = self.pos.checked_add(len)?;
        let s = self.data.get(self.pos..end)?;
        self.pos = end;
        self.expect(b'"')?;
        self.expect(b';')?;
        Some(s)
    }

    fn value(&mut self, depth: usize) -> Option<PhpNode<'a>> {
        if depth > PHP_SER_MAX_DEPTH {
            return None;
        }
        let start = self.pos;
        let tag = *self.data.get(self.pos)?;
        self.pos += 1;
        let (val, has_float) = match tag {
            b'N' => {
                self.expect(b';')?;
                (PhpVal::Null, false)
            }
            b'b' => {
                self.expect(b':')?;
                let v = match self.data.get(self.pos)? {
                    b'0' => false,
                    b'1' => true,
                    _ => return None,
                };
                self.pos += 1;
                self.expect(b';')?;
                (PhpVal::Bool(v), false)
            }
            b'i' => {
                self.expect(b':')?;
                (PhpVal::Int(self.int_until(b';')?), false)
            }
            b'd' => {
                self.expect(b':')?;
                let rel = self.data[self.pos..].iter().position(|&b| b == b';')?;
                self.pos += rel + 1;
                (PhpVal::Float, true)
            }
            b's' => {
                self.expect(b':')?;
                (PhpVal::Str(self.string_body()?), false)
            }
            b'a' => {
                self.expect(b':')?;
                let count = usize::try_from(self.int_until(b':')?).ok()?;
                self.expect(b'{')?;
                let mut items = Vec::with_capacity(count.min(4096));
                let mut has_float = false;
                for _ in 0..count {
                    let key_tag = *self.data.get(self.pos)?;
                    self.pos += 1;
                    self.expect(b':')?;
                    let key = match key_tag {
                        b'i' => PhpKey::Int(self.int_until(b';')?),
                        b's' => PhpKey::Str(self.string_body()?),
                        _ => return None,
                    };
                    let child = self.value(depth + 1)?;
                    has_float |= child.has_float;
                    items.push((key, child));
                }
                self.expect(b'}')?;
                (PhpVal::Arr(items), has_float)
            }
            // Objects, enums, custom serializers and references are not reproduced.
            _ => return None,
        };
        Some(PhpNode {
            val,
            raw: &self.data[start..self.pos],
            has_float,
        })
    }
}

/// Builds PHP `serialize()` output.
struct PhpSerWriter {
    buf: Vec<u8>,
}

impl PhpSerWriter {
    #[inline]
    fn new() -> Self {
        Self {
            buf: Vec::with_capacity(1024),
        }
    }

    #[inline]
    fn usize_dec(&mut self, mut n: usize) {
        let mut tmp = [0u8; 20];
        let mut i = tmp.len();
        loop {
            i -= 1;
            tmp[i] = b'0' + (n % 10) as u8;
            n /= 10;
            if n == 0 {
                break;
            }
        }
        self.buf.extend_from_slice(&tmp[i..]);
    }

    #[inline]
    fn int(&mut self, v: i64) {
        self.buf.extend_from_slice(b"i:");
        if v < 0 {
            self.buf.push(b'-');
        }
        self.usize_dec(v.unsigned_abs() as usize);
        self.buf.push(b';');
    }

    #[inline]
    fn opt_int(&mut self, v: Option<i64>) {
        match v {
            Some(v) => self.int(v),
            None => self.null(),
        }
    }

    #[inline]
    fn null(&mut self) {
        self.buf.extend_from_slice(PHP_NULL_RAW);
    }

    #[inline]
    fn bool(&mut self, v: bool) {
        self.buf
            .extend_from_slice(if v { b"b:1;" } else { b"b:0;" });
    }

    #[inline]
    fn str(&mut self, s: &[u8]) {
        self.buf.extend_from_slice(b"s:");
        self.usize_dec(s.len());
        self.buf.extend_from_slice(b":\"");
        self.buf.extend_from_slice(s);
        self.buf.extend_from_slice(b"\";");
    }

    #[inline]
    fn lower_str(&mut self, s: &[u8]) {
        self.buf.extend_from_slice(b"s:");
        self.usize_dec(s.len());
        self.buf.extend_from_slice(b":\"");
        self.buf.extend(s.iter().map(u8::to_ascii_lowercase));
        self.buf.extend_from_slice(b"\";");
    }

    #[inline]
    fn opt_str(&mut self, s: Option<&[u8]>) {
        match s {
            Some(s) => self.str(s),
            None => self.null(),
        }
    }

    #[inline]
    fn raw(&mut self, raw: &[u8]) {
        self.buf.extend_from_slice(raw);
    }

    #[inline]
    fn arr_open(&mut self, count: usize) {
        self.buf.extend_from_slice(b"a:");
        self.usize_dec(count);
        self.buf.extend_from_slice(b":{");
    }

    #[inline]
    fn arr_close(&mut self) {
        self.buf.push(b'}');
    }

    #[inline]
    fn key(&mut self, k: &[u8]) {
        self.str(k);
    }

    #[inline]
    fn idx(&mut self, i: usize) {
        self.buf.extend_from_slice(b"i:");
        self.usize_dec(i);
        self.buf.push(b';');
    }

    fn str_list(&mut self, items: &[Vec<u8>]) {
        self.arr_open(items.len());
        for (i, item) in items.iter().enumerate() {
            self.idx(i);
            self.str(item);
        }
        self.arr_close();
    }
}

#[inline]
fn is_hex_len(s: &[u8], len: usize) -> bool {
    s.len() == len && s.iter().all(u8::is_ascii_hexdigit)
}

/// Conservative superset of PHP numeric strings (leading/trailing whitespace, sign, digits,
/// decimal point, exponent). Used to bail out wherever PHP would coerce or compare numerically.
#[inline]
fn php_maybe_numeric(s: &[u8]) -> bool {
    s.iter().any(u8::is_ascii_digit)
        && s.iter().all(|&b| {
            b.is_ascii_digit()
                || matches!(
                    b,
                    b'.' | b'e' | b'E' | b'+' | b'-' | b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C
                )
        })
}

/// `Par2MetadataParser::boundedNonNegativeInt`.
fn php_bounded_non_negative_int(node: Option<&PhpNode<'_>>) -> Option<i64> {
    let node = node?;
    match node.val {
        PhpVal::Int(i) => (i >= 0).then_some(i),
        PhpVal::Str(s) => {
            if s.is_empty() || !s.iter().all(u8::is_ascii_digit) {
                return None;
            }
            let trimmed = &s[s.iter().position(|&b| b != b'0').unwrap_or(s.len())..];
            if trimmed.is_empty() {
                return Some(0);
            }
            if trimmed.len() > 19 {
                return None;
            }
            let mut value: i64 = 0;
            for &b in trimmed {
                value = value.checked_mul(10)?.checked_add(i64::from(b - b'0'))?;
            }
            Some(value)
        }
        _ => None,
    }
}

/// Removes duplicates (first occurrence wins) and sorts with PHP `sort()` semantics.
/// Returns `None` when two entries could compare numerically.
fn php_unique_sorted(items: Vec<Vec<u8>>) -> Option<Vec<Vec<u8>>> {
    let mut unique: Vec<Vec<u8>> = Vec::with_capacity(items.len());
    for item in items {
        if !unique.contains(&item) {
            unique.push(item);
        }
    }
    if unique.iter().filter(|s| php_maybe_numeric(s)).count() > 1 {
        return None;
    }
    unique.sort_unstable();
    Some(unique)
}

#[inline]
unsafe fn par2_input<'a>(input: *const u8, input_len: usize) -> Option<&'a [u8]> {
    if input.is_null() || input_len == 0 {
        return None;
    }
    Some(unsafe { slice::from_raw_parts(input, input_len) })
}

#[inline]
unsafe fn par2_emit(out_buf: *mut u8, out_cap: usize, bytes: &[u8]) -> isize {
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    out.extend(bytes);
    out.finish()
}

// ---- Par2ProofIdentity::fromPackets ----------------------------------------

const PAR2_HEAD_MAIN: &[u8] = b"PAR 2.0\x00Main\x00\x00\x00\x00";
const PAR2_HEAD_FILEDESC: &[u8] = b"PAR 2.0\x00FileDesc";
const PAR2_HEAD_FILEVER: &[u8] = b"PAR 2.0\x00IFSC\x00\x00\x00\x00";
const PAR2_HEAD_RECOVERY: &[u8] = b"PAR 2.0\x00RecvSlic";
const PAR2_HEAD_CREATOR: &[u8] = b"PAR 2.0\x00Creator\x00";

/// Canonical `[file ID => serialized value]` entries in first-seen order.
type ProofEntries<'a> = Vec<(&'a [u8], Vec<u8>)>;

struct ProofMain<'n, 'a> {
    raw: Vec<u8>,
    block_size: &'n PhpNode<'a>,
    rec_count: &'n PhpNode<'a>,
    rec_ids: &'n PhpNode<'a>,
    other: Option<&'n PhpNode<'a>>,
}

enum ProofOutcome {
    Identity([u8; 64]),
    Ineligible,
    Fallback,
}

/// String array key usable verbatim (PHP would not coerce it to an integer key).
#[inline]
fn proof_string_key<'a>(node: &PhpNode<'a>) -> Option<&'a [u8]> {
    node.as_str().filter(|s| !php_maybe_numeric(s))
}

fn write_value_list(w: &mut PhpSerWriter, values: &[&[u8]]) {
    w.arr_open(values.len());
    for (i, v) in values.iter().enumerate() {
        w.idx(i);
        w.raw(v);
    }
    w.arr_close();
}

fn par2_proof_identity(doc: &PhpNode<'_>) -> ProofOutcome {
    use ProofOutcome::{Fallback, Identity, Ineligible};
    let PhpVal::Arr(packets) = &doc.val else {
        return Fallback;
    };
    let mut set: Option<&PhpNode<'_>> = None;
    let mut main: Option<ProofMain<'_, '_>> = None;
    let mut descriptors: ProofEntries<'_> = Vec::new();
    let mut checksums: ProofEntries<'_> = Vec::new();

    for (_, packet) in packets {
        if !packet.is_arr() {
            return Fallback;
        }
        let Some(packet_set) = packet.get(b"head_set_id") else {
            return Fallback;
        };
        if let Some(previous) = set {
            match php_identical(Some(previous), Some(packet_set)) {
                None => return Fallback,
                Some(false) => return Ineligible,
                Some(true) => {}
            }
        }
        set = Some(packet_set);
        let Some(head_type) = packet.get(b"head_type").and_then(PhpNode::as_str) else {
            return Fallback;
        };
        let fields: &[&[u8]] = match head_type {
            PAR2_HEAD_MAIN => &[b"block_size", b"rec_file_count", b"rec_file_ids"],
            PAR2_HEAD_FILEDESC => &[
                b"file_id",
                b"file_name",
                b"file_length",
                b"file_hash",
                b"file_hash_16K",
                b"head_hash",
            ],
            PAR2_HEAD_FILEVER => &[b"file_id", b"block_checksums", b"head_hash"],
            PAR2_HEAD_RECOVERY | PAR2_HEAD_CREATOR => continue,
            _ => return Ineligible,
        };
        let mut nodes: Vec<&PhpNode<'_>> = Vec::with_capacity(fields.len());
        for field in fields {
            let Some(node) = packet.get(field) else {
                return Fallback;
            };
            if node.has_float {
                return Fallback;
            }
            nodes.push(node);
        }
        let mut w = PhpSerWriter::new();
        if head_type == PAR2_HEAD_MAIN {
            let other = packet.get(b"other_file_ids");
            let Some(head_hash) = packet.get(b"head_hash") else {
                return Fallback;
            };
            if other.is_some_and(|n| n.has_float) || head_hash.has_float {
                return Fallback;
            }
            let other_raw = other
                .filter(|n| !matches!(n.val, PhpVal::Null))
                .map_or(PHP_EMPTY_ARR_RAW, |n| n.raw);
            write_value_list(
                &mut w,
                &[
                    nodes[0].raw,
                    nodes[1].raw,
                    nodes[2].raw,
                    other_raw,
                    head_hash.raw,
                ],
            );
            match &main {
                Some(previous) if previous.raw != w.buf => return Ineligible,
                Some(_) => {}
                None => {
                    main = Some(ProofMain {
                        raw: w.buf,
                        block_size: nodes[0],
                        rec_count: nodes[1],
                        rec_ids: nodes[2],
                        other: other.filter(|n| !matches!(n.val, PhpVal::Null)),
                    });
                }
            }
            continue;
        }
        let Some(id) = proof_string_key(nodes[0]) else {
            return Fallback;
        };
        let (target, values): (&mut ProofEntries<'_>, &[&[u8]]) = if head_type == PAR2_HEAD_FILEDESC
        {
            (
                &mut descriptors,
                &[
                    nodes[1].raw,
                    nodes[2].raw,
                    nodes[3].raw,
                    nodes[4].raw,
                    nodes[5].raw,
                ],
            )
        } else {
            (&mut checksums, &[nodes[1].raw, nodes[2].raw])
        };
        write_value_list(&mut w, values);
        match target.iter().find(|(k, _)| *k == id) {
            Some((_, existing)) if *existing != w.buf => return Ineligible,
            Some(_) => {}
            None => target.push((id, w.buf)),
        }
    }

    let Some(ProofMain {
        raw: main_raw,
        block_size,
        rec_count,
        rec_ids,
        other,
    }) = main
    else {
        return Ineligible;
    };
    let Some(block_size) = block_size.as_int() else {
        return Fallback;
    };
    if block_size < 1 {
        return Ineligible;
    }
    let Some(rec_count) = rec_count.as_int() else {
        return Fallback;
    };
    if rec_count < 1 {
        return Ineligible;
    }
    if !rec_ids.is_arr() {
        return Fallback;
    }
    if rec_ids.items().len() as i64 != rec_count {
        return Ineligible;
    }
    let other_items: &[(PhpKey<'_>, PhpNode<'_>)] = match other {
        None => &[],
        Some(node) if node.is_arr() => node.items(),
        Some(_) => return Fallback,
    };
    let mut ids: Vec<&[u8]> = Vec::with_capacity(rec_ids.items().len() + other_items.len());
    for (_, entry) in rec_ids.items().iter().chain(other_items.iter()) {
        if !entry.is_arr() {
            return Fallback;
        }
        let Some(id) = entry.get_int_key(1).and_then(proof_string_key) else {
            return Fallback;
        };
        if ids.contains(&id) {
            return Ineligible;
        }
        ids.push(id);
    }
    ids.sort_unstable();
    descriptors.sort_unstable_by(|a, b| a.0.cmp(b.0));
    checksums.sort_unstable_by(|a, b| a.0.cmp(b.0));
    if ids.len() != descriptors.len()
        || ids.len() != checksums.len()
        || ids.iter().zip(&descriptors).any(|(a, (b, _))| a != b)
        || ids.iter().zip(&checksums).any(|(a, (b, _))| a != b)
    {
        return Ineligible;
    }

    let mut w = PhpSerWriter::new();
    w.arr_open(5);
    w.idx(0);
    w.str(b"par2-source-proof-v1");
    w.idx(1);
    w.raw(opt_raw(set));
    w.idx(2);
    w.raw(&main_raw);
    for (index, map) in [(3usize, &descriptors), (4, &checksums)] {
        w.idx(index);
        w.arr_open(map.len());
        for (key, value) in map {
            w.key(key);
            w.raw(value);
        }
        w.arr_close();
    }
    w.arr_close();
    Identity(crate::predb::sha256_hex_slice(&w.buf))
}

/// Accelerates `Par2ProofIdentity::fromPackets` over `serialize($packets)`.
///
/// Returns `64` with the lowercase hex SHA-256 identity written to `out_buf`, `0` when the
/// metadata is ineligible (`null`), or `-1` when PHP must evaluate the input itself.
///
/// # Safety
///
/// `input` must be valid for `input_len` bytes; `out_buf` must be valid for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_proof_identity(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(data) = (unsafe { par2_input(input, input_len) }) else {
        return PAR2_FALLBACK;
    };
    let Some(doc) = PhpSerParser::parse_document(data) else {
        return PAR2_FALLBACK;
    };
    match par2_proof_identity(&doc) {
        ProofOutcome::Identity(hex) => {
            if out_cap < 64 || out_buf.is_null() {
                return PAR2_FALLBACK;
            }
            unsafe { par2_emit(out_buf, out_cap, &hex) }
        }
        ProofOutcome::Ineligible => 0,
        ProofOutcome::Fallback => PAR2_FALLBACK,
    }
}

// ---- Par2MetadataParser::findCrossVolumeRecoveryConflicts -------------------

fn par2_cross_volume_recovery_conflicts(doc: &PhpNode<'_>) -> Option<Vec<u8>> {
    struct First<'n, 'a> {
        set_id: &'a [u8],
        exponent: i64,
        volume: &'n PhpNode<'a>,
        packet: &'n PhpNode<'a>,
    }
    if !doc.is_arr() {
        return None;
    }
    let mut firsts: Vec<First<'_, '_>> = Vec::new();
    let mut index: std::collections::HashMap<(&[u8], i64), usize> =
        std::collections::HashMap::new();
    let mut conflicting: std::collections::HashSet<(&[u8], i64)> = std::collections::HashSet::new();
    let mut body = PhpSerWriter::new();
    let mut count = 0usize;

    for (_, volume) in doc.items() {
        if !volume.is_arr() {
            continue;
        }
        let packets = match volume.coalesce(b"recovery_packets") {
            None => continue,
            Some(node) if node.is_arr() => node,
            Some(_) => return None,
        };
        for (_, packet) in packets.items() {
            if !packet.is_arr() {
                continue;
            }
            let Some(set_id) = opt_str(packet.get(b"recovery_set_id")) else {
                continue;
            };
            let Some(exponent) = opt_int(packet.get(b"exponent")) else {
                continue;
            };
            let Some(slice_hash) = opt_str(packet.get(b"slice_sha256")) else {
                continue;
            };
            let Some(offset) = packet.get(b"packet_offset") else {
                continue;
            };
            let Some(offset_value) = offset.as_int() else {
                continue;
            };
            if !(0..=65535).contains(&exponent) || !is_hex_len(slice_hash, 64) || offset_value < 0 {
                continue;
            }
            let exponent = if exponent == 65535 { 0 } else { exponent };
            let key = (set_id, exponent);
            let Some(&first_index) = index.get(&key) else {
                index.insert(key, firsts.len());
                firsts.push(First {
                    set_id,
                    exponent,
                    volume,
                    packet,
                });
                continue;
            };
            let first = &firsts[first_index];
            let first_file_index = first.volume.get(b"file_index");
            let file_index = volume.get(b"file_index");
            if php_identical(first_file_index, file_index)? {
                continue;
            }
            let first_hash = opt_str(first.packet.get(b"slice_sha256")).unwrap_or_default();
            if first_hash.eq_ignore_ascii_case(slice_hash) || conflicting.contains(&key) {
                continue;
            }
            conflicting.insert(key);
            let segments_raw = |node: &PhpNode<'_>| -> Vec<u8> {
                node.coalesce(b"article_segments")
                    .map_or(PHP_EMPTY_ARR_RAW, |n| n.raw)
                    .to_vec()
            };
            body.idx(count);
            body.arr_open(12);
            body.key(b"recovery_set_id");
            body.str(first.set_id);
            body.key(b"exponent");
            body.int(first.exponent);
            body.key(b"first_file_index");
            body.raw(opt_raw(
                first_file_index.filter(|n| !matches!(n.val, PhpVal::Null)),
            ));
            body.key(b"first_subject");
            body.raw(opt_raw(first.volume.coalesce(b"subject")));
            body.key(b"first_packet_offset");
            body.raw(opt_raw(first.packet.get(b"packet_offset")));
            body.key(b"first_slice_sha256");
            body.str(first_hash);
            body.key(b"first_article_segments");
            body.raw(&segments_raw(first.packet));
            body.key(b"conflicting_file_index");
            body.raw(opt_raw(
                file_index.filter(|n| !matches!(n.val, PhpVal::Null)),
            ));
            body.key(b"conflicting_subject");
            body.raw(opt_raw(volume.coalesce(b"subject")));
            body.key(b"conflicting_packet_offset");
            body.raw(offset.raw);
            body.key(b"conflicting_slice_sha256");
            body.str(slice_hash);
            body.key(b"conflicting_article_segments");
            body.raw(&segments_raw(packet));
            body.arr_close();
            count += 1;
        }
    }

    let mut w = PhpSerWriter::new();
    w.arr_open(count);
    w.raw(&body.buf);
    w.arr_close();
    Some(w.buf)
}

/// Accelerates `Par2MetadataParser::findCrossVolumeRecoveryConflicts` over `serialize($volumes)`.
///
/// Returns the serialized conflict list length, `-(required)` when `out_cap` is too small,
/// or `-1` when PHP must evaluate the input itself.
///
/// # Safety
///
/// `input` must be valid for `input_len` bytes; `out_buf` must be valid for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_cross_volume_recovery_conflicts(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(data) = (unsafe { par2_input(input, input_len) }) else {
        return PAR2_FALLBACK;
    };
    let Some(out) = PhpSerParser::parse_document(data)
        .as_ref()
        .and_then(par2_cross_volume_recovery_conflicts)
    else {
        return PAR2_FALLBACK;
    };
    unsafe { par2_emit(out_buf, out_cap, &out) }
}

// ---- Par2MetadataParser::validateCrossVolumeIfscRelationships ---------------

fn par2_validate_cross_volume_ifsc(doc: &PhpNode<'_>) -> Option<Vec<u8>> {
    let [
        (_, main_ids),
        (_, sources),
        (_, block_size),
        (_, checksums),
        (_, main_present),
    ] = doc.items()
    else {
        return None;
    };
    if !main_ids.is_arr() || !sources.is_arr() || !checksums.is_arr() {
        return None;
    }
    let main_present = main_present.is_true();
    let main_ids: Vec<&[u8]> = main_ids
        .items()
        .iter()
        .filter_map(|(_, n)| n.as_str())
        .collect();
    let block_size = block_size.as_int().filter(|&b| b > 0);

    let mut seen: std::collections::HashMap<Vec<u8>, (Vec<u8>, i64)> =
        std::collections::HashMap::new();
    let mut conflicting = Vec::new();
    let mut membership = Vec::new();
    let mut slice_mismatch = Vec::new();
    let mut invalid = Vec::new();
    let mut unresolved = Vec::new();

    for (_, checksum) in checksums.items() {
        let file_id = checksum.get(b"file_id").and_then(PhpNode::as_str);
        let slice_count = opt_int(checksum.get(b"slice_count")).filter(|&c| c >= 0);
        let records_hash = opt_str(checksum.get(b"checksum_records_sha256"));
        let (Some(file_id), Some(slice_count), Some(records_hash)) = (
            file_id.filter(|id| is_hex_len(id, 32)),
            slice_count,
            records_hash.filter(|h| is_hex_len(h, 64)),
        ) else {
            invalid.push(file_id.unwrap_or_default().to_vec());
            continue;
        };
        let file_id = file_id.to_ascii_lowercase();
        let records_hash = records_hash.to_ascii_lowercase();
        if opt_is_true(checksum.get(b"duplicate_conflict")) {
            conflicting.push(file_id.clone());
        }
        match seen.get(&file_id) {
            Some((hash, count)) if *hash != records_hash || *count != slice_count => {
                conflicting.push(file_id.clone());
            }
            _ => {
                seen.insert(file_id.clone(), (records_hash, slice_count));
            }
        }
        if main_present && !main_ids.contains(&file_id.as_slice()) {
            membership.push(file_id.clone());
        }
        let source = sources.coalesce(&file_id);
        let file_length = source
            .and_then(|s| opt_int(s.get(b"file_length")))
            .filter(|&l| l >= 0);
        match (source.filter(|s| s.is_arr()), file_length, block_size) {
            (Some(_), Some(length), Some(block)) => {
                let expected = length / block + i64::from(length % block != 0);
                if expected != slice_count
                    || opt_is_false(checksum.get(b"slice_count_matches_file_length"))
                {
                    slice_mismatch.push(file_id);
                }
            }
            _ => unresolved.push(file_id),
        }
    }

    let conflicting = php_unique_sorted(conflicting)?;
    let membership = php_unique_sorted(membership)?;
    let slice_mismatch = php_unique_sorted(slice_mismatch)?;
    let invalid = php_unique_sorted(invalid)?;
    let unresolved = php_unique_sorted(unresolved)?;
    let valid = conflicting.is_empty()
        && membership.is_empty()
        && slice_mismatch.is_empty()
        && invalid.is_empty();
    let status: &[u8] = if !valid {
        b"invalid"
    } else if checksums.items().is_empty() {
        b"no_ifsc_packets"
    } else if !unresolved.is_empty() {
        b"pending_source_metadata"
    } else {
        b"valid"
    };

    let mut w = PhpSerWriter::new();
    w.arr_open(7);
    w.key(b"valid");
    w.bool(valid);
    w.key(b"status");
    w.str(status);
    for (key, list) in [
        (&b"conflicting_checksum_file_ids"[..], &conflicting),
        (b"main_membership_mismatch_file_ids", &membership),
        (b"slice_count_mismatch_file_ids", &slice_mismatch),
        (b"invalid_file_ids", &invalid),
        (b"unresolved_file_ids", &unresolved),
    ] {
        w.key(key);
        w.str_list(list);
    }
    w.arr_close();
    Some(w.buf)
}

/// Accelerates `Par2MetadataParser::validateCrossVolumeIfscRelationships` over
/// `serialize([$mainFileIds, $sourceFiles, $blockSize, $checksums, $mainPacket !== null])`.
///
/// Returns the serialized result length, `-(required)` when `out_cap` is too small,
/// or `-1` when PHP must evaluate the input itself.
///
/// # Safety
///
/// `input` must be valid for `input_len` bytes; `out_buf` must be valid for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_validate_cross_volume_ifsc(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(data) = (unsafe { par2_input(input, input_len) }) else {
        return PAR2_FALLBACK;
    };
    let Some(out) = PhpSerParser::parse_document(data)
        .as_ref()
        .and_then(par2_validate_cross_volume_ifsc)
    else {
        return PAR2_FALLBACK;
    };
    unsafe { par2_emit(out_buf, out_cap, &out) }
}

// ---- Par2MetadataParser::relateNzbFilePrefixesToSources ---------------------

const PAR2_RELATE_COMPARISON_BASIS: &[u8] = b"A primary-provider first article whose decoded yEnc range begins at byte 1 and passes size, segment, and CRC checks; exact decoded-prefix length and MD5 are compared with each non-conflicting PAR2 source descriptor file length and md5_first_16k.";

struct VerifiedPrefix {
    file_length: i64,
    bytes_hashed: i64,
    md5: Vec<u8>,
}

/// `Par2MetadataParser::declaredYencCrc32Valid`.
fn par2_declared_yenc_crc32_valid(yenc: &PhpNode<'_>) -> bool {
    let basis = yenc.coalesce(b"segment_crc32_basis");
    let basis_str = opt_str(basis);
    if matches!(
        basis_str,
        Some(b"unavailable-conflicting-yenc-field" | b"unavailable-multipart-structure")
    ) {
        return false;
    }
    let field: Option<&[u8]> = match basis_str {
        Some(b @ (b"crc32" | b"pcrc32")) => Some(b),
        _ if yenc.coalesce(b"crc32").is_some() => Some(b"crc32"),
        _ if yenc.coalesce(b"pcrc32").is_some() => Some(b"pcrc32"),
        _ => None,
    };
    let Some(field) = field else {
        return basis.is_none() || matches!(basis_str, Some(b"missing-crc32" | b"missing-pcrc32"));
    };
    opt_str(yenc.get(field))
        .is_some_and(|d| (1..=8).contains(&d.len()) && d.iter().all(u8::is_ascii_hexdigit))
        && opt_is_true(yenc.get(b"declared_crc32_is_hex"))
        && opt_is_true(yenc.get(b"declared_crc32_matches"))
}

/// `Par2MetadataParser::bodyResponseIdentityVerified`.
fn par2_body_response_identity_verified(metadata: &PhpNode<'_>) -> bool {
    let identity = metadata.get(b"article_identity").filter(|n| n.is_arr());
    let relations = identity
        .and_then(|i| i.get(b"relations"))
        .filter(|n| n.is_arr());
    identity.is_some_and(|i| {
        opt_str(i.get(b"parser_engine")) == Some(b"nntmux-rust-article-identity")
            && opt_int(i.get(b"schema_version")) == Some(3)
    }) && relations.is_some_and(|r| opt_is_true(r.get(b"body_response_matches_request")))
}

/// `Par2MetadataParser::verifiedFirstSegmentPrefix`; `Err(())` requests PHP fallback.
fn par2_verified_first_segment_prefix(
    file: &PhpNode<'_>,
    sample: Option<&PhpNode<'_>>,
) -> Result<Result<VerifiedPrefix, &'static [u8]>, ()> {
    let zero = |key: &[u8]| opt_int(file.get(key)) == Some(0);
    if !opt_is_true(file.get(b"segment_number_sequence_valid"))
        || !zero(b"invalid_segment_record_count")
        || !zero(b"invalid_segment_metadata_count")
        || !zero(b"duplicate_message_id_count")
        || !zero(b"duplicate_segment_number_count")
    {
        return Ok(Err(b"nzb_file_segment_inventory_invalid"));
    }
    let Some(sample) = sample else {
        return Ok(Err(b"first_primary_sample_missing_or_ambiguous"));
    };
    let metadata = sample.get(b"metadata").filter(|n| n.is_arr());
    let yenc = metadata.and_then(|m| m.get(b"yenc")).filter(|n| n.is_arr());
    let identity = metadata
        .and_then(|m| m.get(b"decoded_format_metadata"))
        .filter(|n| n.is_arr())
        .and_then(|d| d.get(b"sample_prefix_identity"))
        .filter(|n| n.is_arr());
    let (Some(metadata), Some(yenc), Some(identity)) = (metadata, yenc, identity) else {
        return Ok(Err(b"prefix_or_yenc_metadata_unavailable"));
    };
    if !par2_body_response_identity_verified(metadata) {
        return Ok(Err(b"article_response_identity_unverified"));
    }
    if !opt_is_true(yenc.get(b"header_numeric_fields_valid"))
        || !opt_is_false(yenc.get(b"integrity_checks_ambiguous"))
        || !opt_is_true(yenc.get(b"decoded_size_matches_yenc"))
        || !opt_is_true(yenc.get(b"decoded_size_matches_yend_size"))
    {
        return Ok(Err(b"yenc_integrity_failed"));
    }
    let file_length = match php_bounded_non_negative_int(yenc.get(b"file_size")) {
        None | Some(0) => return Ok(Err(b"yenc_file_length_unavailable")),
        Some(length) => length,
    };
    let expected = file_length.min(16_384);
    let bytes_hashed = php_bounded_non_negative_int(identity.get(b"bytes_hashed"));
    let prefix_md5 = opt_str(identity.get(b"md5"));
    let file_offset_known = identity.coalesce(b"file_offset_known");
    if bytes_hashed != Some(expected)
        || !prefix_md5.is_some_and(|m| is_hex_len(m, 32))
        || !opt_is_true(identity.get(b"parser_input_offset_zero"))
        || !file_offset_known.is_some_and(PhpNode::is_false)
    {
        return Ok(Err(b"decoded_prefix_identity_incomplete"));
    }
    let decoded_size = php_bounded_non_negative_int(yenc.get(b"decoded_segment_size"));
    let Some(decoded_size) = decoded_size.filter(|&d| d >= expected) else {
        return Ok(Err(b"decoded_prefix_too_short"));
    };

    let multipart = yenc.coalesce(b"multipart");
    if multipart.is_some_and(PhpNode::is_true) {
        let part = php_bounded_non_negative_int(yenc.get(b"part"));
        let total_parts = php_bounded_non_negative_int(yenc.get(b"total_parts"));
        let begin = php_bounded_non_negative_int(yenc.get(b"begin"));
        let end = php_bounded_non_negative_int(yenc.get(b"end"));
        let crc_valid = opt_str(yenc.get(b"pcrc32")).is_some()
            && opt_is_true(yenc.get(b"declared_crc32_is_hex"))
            && opt_is_true(yenc.get(b"declared_crc32_matches"));
        let trailer = yenc.get(b"trailer_fields").filter(|n| n.is_arr());
        let trailer_part = php_bounded_non_negative_int(trailer.and_then(|t| t.get(b"part")));
        let trailer_total = php_bounded_non_negative_int(trailer.and_then(|t| t.get(b"total")));
        let framing = metadata
            .get(b"yenc_framing_metadata")
            .filter(|n| n.is_arr());
        let frames = framing
            .and_then(|f| f.get(b"frames"))
            .filter(|n| n.is_arr());
        let frame = match frames {
            Some(frames) if frames.items().len() == 1 => {
                // `$nativeFrames[0]` on a missing key raises a warning in PHP.
                let first = frames.get_int_key(0).ok_or(())?;
                first.is_arr().then_some(first)
            }
            _ => None,
        };
        let contradicts = framing.is_some_and(|f| {
            opt_is_true(f.get(b"frames_truncated"))
                || opt_int(f.get(b"frame_count")) != Some(1)
                || frame.is_none_or(|fr| {
                    opt_is_false(fr.get(b"part_number_matches"))
                        || opt_is_false(fr.get(b"total_parts_match"))
                })
        });
        if part != Some(1)
            || total_parts.is_none_or(|t| t < 1)
            || trailer_part != part
            || trailer_total.is_some_and(|t| Some(t) != total_parts)
            || contradicts
            || !opt_is_true(metadata.get(b"yenc_nzb_part_number_matches"))
            || !opt_is_true(metadata.get(b"yenc_nzb_total_parts_matches"))
        {
            return Ok(Err(b"yenc_integrity_failed"));
        }
        if begin != Some(1) {
            return Ok(Err(b"yenc_start_offset_not_zero"));
        }
        if !opt_is_true(yenc.get(b"ypart_range_valid"))
            || !opt_is_true(yenc.get(b"decoded_size_matches_ypart_range"))
            || end.is_none_or(|e| e < expected || e > file_length)
            || !crc_valid
        {
            return Ok(Err(b"yenc_integrity_failed"));
        }
    } else if multipart.is_some_and(PhpNode::is_false) {
        if yenc.coalesce(b"part").is_some()
            || decoded_size != file_length
            || !opt_is_true(yenc.get(b"decoded_size_matches_header_size"))
        {
            return Ok(Err(b"yenc_file_coverage_unverified"));
        }
        if !par2_declared_yenc_crc32_valid(yenc) {
            return Ok(Err(b"yenc_integrity_failed"));
        }
    } else {
        return Ok(Err(b"yenc_multipart_status_unavailable"));
    }

    Ok(Ok(VerifiedPrefix {
        file_length,
        bytes_hashed: expected,
        md5: prefix_md5.unwrap_or_default().to_ascii_lowercase(),
    }))
}

fn par2_relate_nzb_file_prefixes(doc: &PhpNode<'_>) -> Option<Vec<u8>> {
    let [
        (_, nzb_files),
        (_, observations),
        (_, sources),
        (_, conflict_keys),
    ] = doc.items()
    else {
        return None;
    };
    if !nzb_files.is_arr() || !observations.is_arr() || !sources.is_arr() || !conflict_keys.is_arr()
    {
        return None;
    }
    // `array_fill_keys()` coerces numeric strings and non-strings into other key types.
    let mut conflicts: Vec<&[u8]> = Vec::new();
    for (_, key) in conflict_keys.items() {
        let key = key.as_str().filter(|k| !php_maybe_numeric(k))?;
        if !conflicts.contains(&key) {
            conflicts.push(key);
        }
    }
    let conflict_set: std::collections::HashSet<&[u8]> = conflicts.iter().copied().collect();

    let mut eligible: Vec<&PhpNode<'_>> = Vec::with_capacity(nzb_files.items().len());
    for (_, file) in nzb_files.items() {
        if !file.is_arr() {
            return None;
        }
        if opt_str(file.get(b"kind")) != Some(b"par2") {
            eligible.push(file);
        }
    }
    let carrier_count = nzb_files.items().len() - eligible.len();

    // Pre-bucket first/primary segment-one observations by their `file_index` identity.
    let mut first_samples: std::collections::HashMap<&[u8], Vec<&PhpNode<'_>>> =
        std::collections::HashMap::new();
    if !eligible.is_empty() {
        for (_, observation) in observations.items() {
            if !observation.is_arr() {
                return None;
            }
            if opt_int(observation.get(b"segment_number")) != Some(1)
                || opt_str(observation.get(b"sample_role")) != Some(b"first")
                || opt_str(observation.get(b"server_role")) != Some(b"primary")
            {
                continue;
            }
            let index = observation.coalesce(b"file_index");
            if index.is_some_and(|n| n.has_float) {
                return None;
            }
            first_samples
                .entry(opt_raw(index))
                .or_default()
                .push(observation);
        }
    }

    struct SourceView<'a> {
        node_set: Option<&'a [u8]>,
        file_id: Option<&'a [u8]>,
        length: Option<i64>,
        filename_raw: &'a [u8],
        filename_str: Option<&'a [u8]>,
        md5_16k: Option<&'a [u8]>,
    }
    let mut source_views: Vec<SourceView<'_>> = Vec::new();

    let mut qualified = 0usize;
    let mut candidate_matches = 0usize;
    let mut ambiguous = 0usize;
    let mut no_match = 0usize;
    let mut insufficient = 0usize;
    let mut conflicting_definitions = 0usize;
    let mut files = PhpSerWriter::new();

    for (position, file) in eligible.iter().enumerate() {
        let file_index = file.coalesce(b"index");
        if file_index.is_some_and(|n| n.has_float) {
            return None;
        }
        let sample = first_samples
            .get(opt_raw(file_index))
            .filter(|bucket| bucket.len() == 1)
            .map(|bucket| bucket[0]);
        let verdict = par2_verified_first_segment_prefix(file, sample).ok()?;
        let prefix = verdict.as_ref().ok();

        let mut matches = PhpSerWriter::new();
        let mut match_count = 0usize;
        let mut conflicted = PhpSerWriter::new();
        let mut conflicted_count = 0usize;
        if let Some(prefix) = prefix {
            qualified += 1;
            if source_views.is_empty() && !sources.items().is_empty() {
                for (_, source) in sources.items() {
                    if !source.is_arr() {
                        return None;
                    }
                    source_views.push(SourceView {
                        node_set: opt_str(source.get(b"recovery_set_id")),
                        file_id: opt_str(source.get(b"file_id")),
                        length: php_bounded_non_negative_int(source.get(b"file_length")),
                        filename_raw: opt_raw(source.coalesce(b"filename")),
                        filename_str: opt_str(source.get(b"filename")),
                        md5_16k: opt_str(source.get(b"md5_first_16k")),
                    });
                }
            }
            for view in &source_views {
                if view.length != Some(prefix.file_length) {
                    continue;
                }
                let length = prefix.file_length;
                if let (Some(set), Some(file_id)) = (view.node_set, view.file_id) {
                    let mut key = set.to_ascii_lowercase();
                    key.push(b':');
                    let set_len = key.len();
                    key.extend(file_id.iter().map(u8::to_ascii_lowercase));
                    let hit = conflict_set.contains(key.as_slice()) || {
                        key.truncate(set_len);
                        key.push(b'*');
                        conflict_set.contains(key.as_slice())
                    };
                    if hit {
                        conflicted.idx(conflicted_count);
                        conflicted.arr_open(5);
                        conflicted.key(b"recovery_set_id");
                        conflicted.str(set);
                        conflicted.key(b"file_id");
                        conflicted.lower_str(file_id);
                        conflicted.key(b"par2_filename");
                        conflicted.raw(view.filename_raw);
                        conflicted.key(b"par2_file_length");
                        conflicted.int(length);
                        conflicted.key(b"comparison_status");
                        conflicted.str(b"source_definition_conflict");
                        conflicted.arr_close();
                        conflicted_count += 1;
                        continue;
                    }
                }
                let (Some(hash), Some(set), Some(file_id)) =
                    (view.md5_16k, view.node_set, view.file_id)
                else {
                    continue;
                };
                if !is_hex_len(hash, 32) || !is_hex_len(file_id, 32) {
                    continue;
                }
                if !hash.eq_ignore_ascii_case(&prefix.md5) {
                    continue;
                }
                matches.idx(match_count);
                matches.arr_open(9);
                matches.key(b"recovery_set_id");
                matches.lower_str(set);
                matches.key(b"file_id");
                matches.lower_str(file_id);
                matches.key(b"par2_filename");
                matches.opt_str(view.filename_str);
                matches.key(b"par2_file_length");
                matches.int(length);
                matches.key(b"par2_md5_first_16k");
                matches.lower_str(hash);
                matches.key(b"file_length_matches");
                matches.bool(true);
                matches.key(b"prefix_md5_matches");
                matches.bool(true);
                matches.key(b"match_basis");
                matches.str(b"exact_file_length_and_par2_md5_first_16k");
                matches.key(b"whole_file_identity_established");
                matches.bool(false);
                matches.arr_close();
                match_count += 1;
            }
        }

        let status: &[u8] = if prefix.is_none() {
            insufficient += 1;
            b"insufficient_evidence"
        } else if match_count + conflicted_count > 1 {
            ambiguous += 1;
            b"ambiguous"
        } else if conflicted_count > 0 {
            conflicting_definitions += 1;
            b"conflicting_source_definition"
        } else if match_count == 1 {
            candidate_matches += 1;
            b"candidate_match"
        } else {
            no_match += 1;
            b"no_match"
        };

        files.idx(position);
        files.arr_open(9);
        files.key(b"file_index");
        files.opt_int(file_index.and_then(PhpNode::as_int));
        files.key(b"kind");
        files.opt_str(opt_str(file.get(b"kind")));
        files.key(b"nzb_subject");
        files.opt_str(opt_str(file.get(b"subject")));
        files.key(b"status");
        files.str(status);
        files.key(b"evidence_status");
        match &verdict {
            Ok(_) => files.str(b"verified_yenc_prefix"),
            Err(evidence) => files.str(evidence),
        }
        files.key(b"sample");
        match sample {
            None => files.null(),
            Some(sample) => {
                files.arr_open(12);
                for key in [&b"message_id"[..], b"server", b"provider_label"] {
                    files.key(key);
                    files.opt_str(opt_str(sample.get(key)));
                }
                files.key(b"server_role");
                files.raw(opt_raw(sample.get(b"server_role")));
                files.key(b"server_port");
                files.opt_int(php_bounded_non_negative_int(sample.get(b"server_port")));
                files.key(b"server_tls");
                match sample.get(b"server_tls").map(|n| &n.val) {
                    Some(PhpVal::Bool(b)) => files.bool(*b),
                    _ => files.null(),
                }
                files.key(b"queried_at_utc");
                files.opt_str(opt_str(sample.get(b"queried_at_utc")));
                files.key(b"segment_number");
                files.int(1);
                files.key(b"file_offset_start");
                files.int(0);
                files.key(b"file_length");
                files.opt_int(prefix.map(|p| p.file_length));
                files.key(b"prefix_bytes_hashed");
                files.opt_int(prefix.map(|p| p.bytes_hashed));
                files.key(b"prefix_md5");
                files.opt_str(prefix.map(|p| p.md5.as_slice()));
                files.arr_close();
            }
        }
        files.key(b"candidate_count");
        files.int((match_count + conflicted_count) as i64);
        files.key(b"matches");
        files.arr_open(match_count);
        files.raw(&matches.buf);
        files.arr_close();
        files.key(b"conflicted_candidates");
        files.arr_open(conflicted_count);
        files.raw(&conflicted.buf);
        files.arr_close();
        files.arr_close();
    }

    let status: &[u8] = if sources.items().is_empty() {
        b"no_par2_sources"
    } else if !conflicts.is_empty() || insufficient > 0 {
        b"partial"
    } else {
        b"complete"
    };
    let mut w = PhpSerWriter::new();
    w.buf.reserve(files.buf.len() + 1024);
    w.arr_open(15);
    w.key(b"status");
    w.str(status);
    w.key(b"comparison_basis");
    w.str(PAR2_RELATE_COMPARISON_BASIS);
    w.key(b"prefix_limit_bytes");
    w.int(16_384);
    w.key(b"whole_file_identity_established");
    w.bool(false);
    for (key, value) in [
        (&b"nzb_file_count"[..], nzb_files.items().len()),
        (b"source_file_count", eligible.len()),
        (b"par2_carrier_file_count", carrier_count),
        (b"qualified_prefix_count", qualified),
        (b"candidate_match_count", candidate_matches),
        (b"ambiguous_count", ambiguous),
        (b"no_match_count", no_match),
        (b"insufficient_evidence_count", insufficient),
        (
            b"conflicting_source_definition_count",
            conflicting_definitions,
        ),
    ] {
        w.key(key);
        w.int(value as i64);
    }
    w.key(b"conflicting_source_keys");
    w.arr_open(conflicts.len());
    for (i, key) in conflicts.iter().enumerate() {
        w.idx(i);
        w.str(key);
    }
    w.arr_close();
    w.key(b"files");
    w.arr_open(eligible.len());
    w.raw(&files.buf);
    w.arr_close();
    w.arr_close();
    Some(w.buf)
}

/// Accelerates `Par2MetadataParser::relateNzbFilePrefixesToSources` over
/// `serialize([$nzbFiles, $observations, $sources, $conflictingSourceKeys])`.
///
/// Returns the serialized result length, `-(required)` when `out_cap` is too small,
/// or `-1` when PHP must evaluate the input itself.
///
/// # Safety
///
/// `input` must be valid for `input_len` bytes; `out_buf` must be valid for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_par2_relate_nzb_file_prefixes(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let Some(data) = (unsafe { par2_input(input, input_len) }) else {
        return PAR2_FALLBACK;
    };
    let Some(out) = PhpSerParser::parse_document(data)
        .as_ref()
        .and_then(par2_relate_nzb_file_prefixes)
    else {
        return PAR2_FALLBACK;
    };
    unsafe { par2_emit(out_buf, out_cap, &out) }
}

#[cfg(test)]
mod par2_metadata_proof_tests {
    use super::*;

    fn s(bytes: &[u8]) -> Vec<u8> {
        let mut w = PhpSerWriter::new();
        w.str(bytes);
        w.buf
    }

    fn proof_fixture(other_ids: &[u8]) -> Vec<u8> {
        let set = s(&[b'a'; 32]);
        let id = s(&[b'b'; 32]);
        let mut out = b"a:3:{i:0;a:7:{s:9:\"head_type\";".to_vec();
        out.extend(s(PAR2_HEAD_MAIN));
        out.extend(b"s:11:\"head_set_id\";");
        out.extend(&set);
        out.extend(b"s:9:\"head_hash\";");
        out.extend(s(&[b'1'; 32]));
        out.extend(b"s:10:\"block_size\";i:64;s:14:\"rec_file_count\";i:1;s:12:\"rec_file_ids\";a:1:{i:0;a:1:{i:1;");
        out.extend(&id);
        out.extend(b"}}s:14:\"other_file_ids\";");
        out.extend(other_ids);
        out.extend(b"}i:1;a:8:{s:9:\"head_type\";");
        out.extend(s(PAR2_HEAD_FILEDESC));
        out.extend(b"s:11:\"head_set_id\";");
        out.extend(&set);
        out.extend(b"s:9:\"head_hash\";");
        out.extend(s(&[b'2'; 32]));
        out.extend(b"s:7:\"file_id\";");
        out.extend(&id);
        out.extend(
            b"s:9:\"file_name\";s:11:\"payload.bin\";s:11:\"file_length\";i:64;s:9:\"file_hash\";",
        );
        out.extend(s(&[b'c'; 32]));
        out.extend(b"s:13:\"file_hash_16K\";");
        out.extend(s(&[b'c'; 32]));
        out.extend(b"}i:2;a:5:{s:9:\"head_type\";");
        out.extend(s(PAR2_HEAD_FILEVER));
        out.extend(b"s:11:\"head_set_id\";");
        out.extend(&set);
        out.extend(b"s:9:\"head_hash\";");
        out.extend(s(&[b'3'; 32]));
        out.extend(b"s:7:\"file_id\";");
        out.extend(&id);
        out.extend(b"s:15:\"block_checksums\";a:1:{i:0;a:2:{s:3:\"md5\";");
        out.extend(s(&[b'c'; 32]));
        out.extend(b"s:5:\"crc32\";i:123;}}}}");
        out
    }

    fn proof(input: &[u8]) -> (isize, Vec<u8>) {
        let mut out = [0u8; 64];
        let rc = unsafe {
            nntmux_par2_proof_identity(input.as_ptr(), input.len(), out.as_mut_ptr(), out.len())
        };
        (rc, out.to_vec())
    }

    #[test]
    fn php_serialize_parser_accepts_scalars_and_rejects_objects() {
        let doc =
            PhpSerParser::parse_document(b"a:4:{i:0;N;s:1:\"k\";b:1;i:-5;d:0.5;i:7;s:3:\"a\"b\";}")
                .expect("valid document");
        assert_eq!(doc.items().len(), 4);
        assert!(doc.has_float);
        assert_eq!(doc.get(b"k").map(PhpNode::is_true), Some(true));
        assert_eq!(opt_str(doc.get_int_key(7)), Some(&b"a\"b"[..]));
        assert!(PhpSerParser::parse_document(b"O:8:\"stdClass\":0:{}").is_none());
        assert!(PhpSerParser::parse_document(b"a:1:{i:0;R:1;}").is_none());
        assert!(PhpSerParser::parse_document(b"s:5:\"abc\";").is_none());
        assert!(PhpSerParser::parse_document(b"i:1;trailing").is_none());
    }

    #[test]
    fn proof_identity_matches_php_vector() {
        // PHP: Par2ProofIdentity::fromPackets() for the Par2ProofIdentityTest fixture.
        let (rc, hex) = proof(&proof_fixture(b"N;"));
        assert_eq!(rc, 64);
        assert_eq!(
            hex,
            b"1d88979305e21fc576ccb1fcced3947606a8214efd52588afba857939b8fc83c".to_vec()
        );
        let (rc_empty_other, hex_empty_other) = proof(&proof_fixture(b"a:0:{}"));
        assert_eq!((rc_empty_other, hex_empty_other), (rc, hex));
    }

    #[test]
    fn proof_identity_defers_unsupported_shapes() {
        assert_eq!(proof(b"a:0:{}").0, 0);
        assert_eq!(proof(&proof_fixture(b"d:1.5;")).0, PAR2_FALLBACK);
        assert_eq!(proof(b"a:1:{i:0;a:0:{}}").0, PAR2_FALLBACK);
        assert_eq!(proof(b"not serialized").0, PAR2_FALLBACK);
    }

    #[test]
    fn cross_volume_conflicts_match_php_vector() {
        let a = [b'a'; 64];
        let b = [b'b'; 64];
        let mut input = b"a:2:{i:0;a:3:{s:10:\"file_index\";i:0;s:7:\"subject\";s:1:\"a\";s:16:\"recovery_packets\";a:1:{i:0;a:4:{s:15:\"recovery_set_id\";s:1:\"s\";s:8:\"exponent\";i:65535;s:12:\"slice_sha256\";".to_vec();
        input.extend(s(&a));
        input.extend(b"s:13:\"packet_offset\";i:0;}}}i:1;a:3:{s:10:\"file_index\";i:1;s:7:\"subject\";s:1:\"b\";s:16:\"recovery_packets\";a:1:{i:0;a:4:{s:15:\"recovery_set_id\";s:1:\"s\";s:8:\"exponent\";i:0;s:12:\"slice_sha256\";");
        input.extend(s(&b));
        input.extend(b"s:13:\"packet_offset\";i:8;}}}}");
        let doc = PhpSerParser::parse_document(&input).expect("valid document");
        let mut expected = b"a:1:{i:0;a:12:{s:15:\"recovery_set_id\";s:1:\"s\";s:8:\"exponent\";i:0;s:16:\"first_file_index\";i:0;s:13:\"first_subject\";s:1:\"a\";s:19:\"first_packet_offset\";i:0;s:18:\"first_slice_sha256\";".to_vec();
        expected.extend(s(&a));
        expected.extend(b"s:22:\"first_article_segments\";a:0:{}s:22:\"conflicting_file_index\";i:1;s:19:\"conflicting_subject\";s:1:\"b\";s:25:\"conflicting_packet_offset\";i:8;s:24:\"conflicting_slice_sha256\";");
        expected.extend(s(&b));
        expected.extend(b"s:28:\"conflicting_article_segments\";a:0:{}}}");
        assert_eq!(par2_cross_volume_recovery_conflicts(&doc), Some(expected));

        let mut small = [0u8; 8];
        let rc = unsafe {
            nntmux_par2_cross_volume_recovery_conflicts(
                input.as_ptr(),
                input.len(),
                small.as_mut_ptr(),
                small.len(),
            )
        };
        assert!(rc < -8);
    }

    #[test]
    fn ifsc_validation_defers_numeric_sort_and_reports_status() {
        let doc =
            PhpSerParser::parse_document(b"a:5:{i:0;a:0:{}i:1;a:0:{}i:2;N;i:3;a:0:{}i:4;b:0;}")
                .expect("valid document");
        let out = par2_validate_cross_volume_ifsc(&doc).expect("handled");
        assert!(
            out.starts_with(b"a:7:{s:5:\"valid\";b:1;s:6:\"status\";s:15:\"no_ifsc_packets\";")
        );

        let numeric = b"a:5:{i:0;a:0:{}i:1;a:0:{}i:2;N;i:3;a:2:{i:0;a:1:{s:7:\"file_id\";s:2:\"12\";}i:1;a:1:{s:7:\"file_id\";s:1:\"9\";}}i:4;b:0;}";
        let doc = PhpSerParser::parse_document(numeric).expect("valid document");
        assert!(par2_validate_cross_volume_ifsc(&doc).is_none());
    }

    #[test]
    fn bounded_non_negative_int_matches_php() {
        let parse = |raw: &[u8]| {
            let doc = PhpSerParser::parse_document(raw).expect("valid");
            php_bounded_non_negative_int(Some(&doc))
        };
        assert_eq!(parse(b"i:5;"), Some(5));
        assert_eq!(parse(b"i:-1;"), None);
        assert_eq!(parse(b"s:4:\"0012\";"), Some(12));
        assert_eq!(parse(b"s:3:\"000\";"), Some(0));
        assert_eq!(parse(b"s:19:\"9223372036854775807\";"), Some(i64::MAX));
        assert_eq!(parse(b"s:19:\"9223372036854775808\";"), None);
        assert_eq!(parse(b"s:2:\"1a\";"), None);
        assert_eq!(parse(b"s:0:\"\";"), None);
        assert_eq!(parse(b"b:1;"), None);
    }
}
