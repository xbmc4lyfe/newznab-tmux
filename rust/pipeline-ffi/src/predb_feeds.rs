//! PreDB feed dump (CSV/TSV/SQL/JSON) and IRC announce parsers for `libnntmux_pipeline`.
//!
//! Byte-exact replacements for the PCRE patterns in `PreAnnounceParser`, the MySQL OUTFILE record
//! reader in `NzedbArchiveSource` and the duplicate-member scan in `KnabenSource`. Every entry point
//! returns `-1` (or an equivalent "unsupported" value) when the input falls outside the subset whose
//! PHP semantics are reproduced exactly, so callers fall back to the PHP implementation.
//!
//! Adoption status: the nZEDb archive reader and Knaben duplicate-member scan are wired into PHP
//! (5-8x on parseCsv/parseJson, >20x on the isolated scan). The IRC announce matcher is exported and
//! parity-tested but deliberately not wired into `PreAnnounceParser`: PCRE's JIT already matches a
//! short announce in under a microsecond, so end-to-end `parse()` measured only 1.0-1.2x.
use crate::OutCursor;
use std::slice;

// ---------------------------------------------------------------------------------------------
// Shared byte helpers
// ---------------------------------------------------------------------------------------------

/// PCRE `\s` without `/u`: space, `\t`, `\n`, `\v`, `\f`, `\r`.
#[inline]
fn is_ws(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0b | 0x0c | b'\r')
}

/// PHP `trim()` default character set: space, `\t`, `\n`, `\r`, NUL and `\v`.
#[inline]
fn is_php_trim(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b)
}

fn php_trim(s: &[u8]) -> &[u8] {
    let mut start = 0;
    let mut end = s.len();
    while start < end && is_php_trim(s[start]) {
        start += 1;
    }
    while end > start && is_php_trim(s[end - 1]) {
        end -= 1;
    }
    &s[start..end]
}

#[inline]
fn skip_ws(s: &[u8], mut i: usize) -> usize {
    while i < s.len() && is_ws(s[i]) {
        i += 1;
    }
    i
}

#[inline]
fn skip_digits(s: &[u8], mut i: usize) -> usize {
    while i < s.len() && s[i].is_ascii_digit() {
        i += 1;
    }
    i
}

/// End of a maximal run of non-whitespace bytes (`\S+` when greater than `i`).
#[inline]
fn token_end(s: &[u8], mut i: usize) -> usize {
    while i < s.len() && !is_ws(s[i]) {
        i += 1;
    }
    i
}

/// `\d+(?:\.\d+)?` starting at `i`; returns the end index.
#[inline]
fn size_end(s: &[u8], i: usize) -> Option<usize> {
    let d = skip_digits(s, i);
    if d == i {
        return None;
    }
    if d < s.len() && s[d] == b'.' {
        let f = skip_digits(s, d + 1);
        if f > d + 1 {
            return Some(f);
        }
    }
    Some(d)
}

/// Case-sensitive literal at `i`; returns the index after it.
#[inline]
fn lit(s: &[u8], i: usize, l: &[u8]) -> Option<usize> {
    if s.len() >= i + l.len() && &s[i..i + l.len()] == l {
        Some(i + l.len())
    } else {
        None
    }
}

/// ASCII case-insensitive literal at `i`; returns the index after it.
#[inline]
fn lit_ci(s: &[u8], i: usize, l: &[u8]) -> Option<usize> {
    if s.len() >= i + l.len() && s[i..i + l.len()].eq_ignore_ascii_case(l) {
        Some(i + l.len())
    } else {
        None
    }
}

#[inline]
fn byte_at(s: &[u8], i: usize, b: u8) -> Option<usize> {
    if i < s.len() && s[i] == b {
        Some(i + 1)
    } else {
        None
    }
}

// ---------------------------------------------------------------------------------------------
// IRC announce parser
// ---------------------------------------------------------------------------------------------

const FORMAT_CORRUPTNET: i32 = 0;
const FORMAT_ZENET: i32 = 1;
const FORMAT_PREDATABASE: i32 = 2;
const FORMAT_NGP: i32 = 3;
const FORMAT_RIZON: i32 = 4;
const FORMAT_OPENTRACKERS: i32 = 5;

const NUKE_TYPES: [&[u8]; 5] = [b"NUKE", b"UNNUKE", b"MODNUKE", b"RENUKE", b"OLDNUKE"];
const KNOWN_TYPES: [&[u8]; 8] = [
    b"PRE", b"P2P", b"INFO", b"NUKE", b"UNNUKE", b"MODNUKE", b"RENUKE", b"OLDNUKE",
];

/// Named groups of one successful pattern match.
#[derive(Clone, Copy)]
struct Groups<'a> {
    ty: &'a [u8],
    section: Option<&'a [u8]>,
    title: &'a [u8],
    reason: Option<&'a [u8]>,
    files: Option<&'a [u8]>,
    size: Option<&'a [u8]>,
}

impl<'a> Groups<'a> {
    fn new(ty: &'a [u8], title: &'a [u8]) -> Self {
        Self {
            ty,
            section: None,
            title,
            reason: None,
            files: None,
            size: None,
        }
    }
}

type Pattern = for<'a> fn(&'a [u8]) -> Option<Groups<'a>>;

/// `\S+` that must run to the end of `s` starting at `i`.
fn tail_token(s: &[u8], i: usize) -> Option<&[u8]> {
    if i < s.len() && token_end(s, i) == s.len() {
        Some(&s[i..])
    } else {
        None
    }
}

/// Matches one of `types` (case-sensitive) at `i` and requires `close` right after it.
fn nuke_type(s: &[u8], i: usize, close: u8, ci: bool) -> Option<(&[u8], usize)> {
    for t in NUKE_TYPES {
        let end = if ci { lit_ci(s, i, t) } else { lit(s, i, t) };
        if let Some(end) = end
            && byte_at(s, end, close).is_some()
        {
            return Some((&s[i..end], end + 1));
        }
    }
    None
}

// corruptnet ---------------------------------------------------------------------------------

fn corruptnet_pre(s: &[u8]) -> Option<Groups<'_>> {
    let i = lit(s, 0, b"PRE:")?;
    let i = byte_at(s, skip_ws(s, i), b'[')?;
    let mut j = i;
    while j < s.len() && s[j] != b']' {
        j += 1;
    }
    if j == i || j >= s.len() {
        return None;
    }
    let section = &s[i..j];
    let k = skip_ws(s, j + 1);
    if k == j + 1 {
        return None;
    }
    let title = tail_token(s, k)?;
    let mut g = Groups::new(&s[0..3], title);
    g.section = Some(section);
    Some(g)
}

fn corruptnet_info(s: &[u8]) -> Option<Groups<'_>> {
    let i = lit(s, 0, b"INFO:")?;
    let ts = skip_ws(s, i);
    let te = token_end(s, ts);
    if te == ts {
        return None;
    }
    let j = skip_ws(s, te);
    if j == te {
        return None;
    }
    let j = byte_at(s, j, b'[')?;
    let fe = skip_digits(s, j);
    if fe == j {
        return None;
    }
    let files = &s[j..fe];
    let k = byte_at(s, fe, b'F')?;
    let m = skip_ws(s, k);
    if m == k {
        return None;
    }
    let se = size_end(s, m)?;
    let size = &s[m..se];
    let end = lit(s, se, b"MB]")?;
    if end != s.len() {
        return None;
    }
    let mut g = Groups::new(&s[0..4], &s[ts..te]);
    g.files = Some(files);
    g.size = Some(size);
    Some(g)
}

/// `\s+\[(?<reason>[^\]]*)\](?:\s+\[NUKENET\])?\z` starting at `i`, after a token ending at `te`.
fn bracket_reason(s: &[u8], te: usize) -> Option<&[u8]> {
    let j = skip_ws(s, te);
    if j == te {
        return None;
    }
    let j = byte_at(s, j, b'[')?;
    let mut k = j;
    while k < s.len() && s[k] != b']' {
        k += 1;
    }
    if k >= s.len() {
        return None;
    }
    let reason = &s[j..k];
    let after = k + 1;
    if after == s.len() {
        return Some(reason);
    }
    let w = skip_ws(s, after);
    if w == after {
        return None;
    }
    let end = lit(s, w, b"[NUKENET]")?;
    if end == s.len() { Some(reason) } else { None }
}

fn corruptnet_nuke(s: &[u8]) -> Option<Groups<'_>> {
    let (ty, i) = nuke_type(s, 0, b':', false)?;
    let ts = skip_ws(s, i);
    let te = token_end(s, ts);
    if te == ts {
        return None;
    }
    let reason = bracket_reason(s, te)?;
    let mut g = Groups::new(ty, &s[ts..te]);
    g.reason = Some(reason);
    Some(g)
}

// zenet --------------------------------------------------------------------------------------

fn zenet_pre(s: &[u8]) -> Option<Groups<'_>> {
    let i = lit_ci(s, 0, b"(PRE)")?;
    let i = byte_at(s, skip_ws(s, i), b'(')?;
    let mut j = i;
    while j < s.len() && s[j] != b')' {
        j += 1;
    }
    if j == i || j >= s.len() {
        return None;
    }
    let section = &s[i..j];
    let k = byte_at(s, skip_ws(s, j + 1), b'(')?;
    if s.len() < k + 2 || s[s.len() - 1] != b')' {
        return None;
    }
    let title = &s[k..s.len() - 1];
    if title.is_empty() || title.iter().any(|&b| is_ws(b)) {
        return None;
    }
    let mut g = Groups::new(&s[1..4], title);
    g.section = Some(section);
    Some(g)
}

/// `\s*\((?<files>\d+) Files?\)\s*\((?<size>\d+(?:\.\d+)?) MB\)\z`
fn zenet_info_tail(s: &[u8]) -> Option<(&[u8], &[u8])> {
    let i = byte_at(s, skip_ws(s, 0), b'(')?;
    let fe = skip_digits(s, i);
    if fe == i {
        return None;
    }
    let files = &s[i..fe];
    let mut k = byte_at(s, fe, b' ')?;
    k = lit_ci(s, k, b"File")?;
    if k < s.len() && (s[k] == b's' || s[k] == b'S') {
        k += 1;
    }
    k = byte_at(s, k, b')')?;
    k = byte_at(s, skip_ws(s, k), b'(')?;
    let se = size_end(s, k)?;
    let size = &s[k..se];
    let mut e = byte_at(s, se, b' ')?;
    e = lit_ci(s, e, b"MB")?;
    e = byte_at(s, e, b')')?;
    if e == s.len() {
        Some((files, size))
    } else {
        None
    }
}

fn zenet_info(s: &[u8]) -> Option<Groups<'_>> {
    let i = lit_ci(s, 0, b"(INFO)")?;
    let ts = byte_at(s, skip_ws(s, i), b'(')?;
    for e in ts + 1..s.len() {
        if is_ws(s[e - 1]) {
            break;
        }
        if s[e] == b')'
            && let Some((files, size)) = zenet_info_tail(&s[e + 1..])
        {
            let mut g = Groups::new(&s[1..5], &s[ts..e]);
            g.files = Some(files);
            g.size = Some(size);
            return Some(g);
        }
    }
    None
}

/// `\s*\((?<reason>[^)]*)\)\z`
fn zenet_nuke_tail(s: &[u8]) -> Option<&[u8]> {
    let i = byte_at(s, skip_ws(s, 0), b'(')?;
    let mut k = i;
    while k < s.len() && s[k] != b')' {
        k += 1;
    }
    if k + 1 == s.len() {
        Some(&s[i..k])
    } else {
        None
    }
}

fn zenet_nuke(s: &[u8]) -> Option<Groups<'_>> {
    let open = byte_at(s, 0, b'(')?;
    let (ty, i) = nuke_type(s, open, b')', true)?;
    let ts = byte_at(s, skip_ws(s, i), b'(')?;
    for e in ts + 1..s.len() {
        if is_ws(s[e - 1]) {
            break;
        }
        if s[e] == b')'
            && let Some(reason) = zenet_nuke_tail(&s[e + 1..])
        {
            let mut g = Groups::new(ty, &s[ts..e]);
            g.reason = Some(reason);
            return Some(g);
        }
    }
    None
}

// predatabase --------------------------------------------------------------------------------

fn predatabase_pre(s: &[u8]) -> Option<Groups<'_>> {
    let i = lit_ci(s, 0, b"pre")?;
    let bar = byte_at(s, skip_ws(s, i), b'|')?;
    let a = skip_ws(s, bar);
    let mut p2 = a;
    while p2 < s.len() && s[p2] != b'|' {
        p2 += 1;
    }
    if p2 >= s.len() {
        return None;
    }
    // `[^|]+?` is lazy: an all-whitespace gap makes `\s*` give one byte back to the section.
    let start = if a < p2 {
        a
    } else if a > bar {
        a - 1
    } else {
        return None;
    };
    let mut b = p2;
    while b > start + 1 && is_ws(s[b - 1]) {
        b -= 1;
    }
    let title = tail_token(s, skip_ws(s, p2 + 1))?;
    let mut g = Groups::new(&s[0..3], title);
    g.section = Some(&s[start..b]);
    Some(g)
}

fn predatabase_info(s: &[u8]) -> Option<Groups<'_>> {
    let i = lit_ci(s, 0, b"info")?;
    let bar = byte_at(s, skip_ws(s, i), b'|')?;
    let ts = skip_ws(s, bar);
    let te = token_end(s, ts);
    if te == ts {
        return None;
    }
    let mut k = skip_ws(s, te);
    if k == te {
        return None;
    }
    k = byte_at(s, k, b'-')?;
    let w = skip_ws(s, k);
    if w == k {
        return None;
    }
    let fe = skip_digits(s, w);
    if fe == w {
        return None;
    }
    let files = &s[w..fe];
    let w2 = skip_ws(s, fe);
    if w2 == fe {
        return None;
    }
    let k = lit_ci(s, w2, b"F")?;
    let w3 = skip_ws(s, k);
    if w3 == k {
        return None;
    }
    let k = byte_at(s, w3, b'&')?;
    let w4 = skip_ws(s, k);
    if w4 == k {
        return None;
    }
    let se = size_end(s, w4)?;
    let size = &s[w4..se];
    let w5 = skip_ws(s, se);
    if w5 == se {
        return None;
    }
    let end = lit_ci(s, w5, b"MB")?;
    if end != s.len() {
        return None;
    }
    let mut g = Groups::new(&s[0..4], &s[ts..te]);
    g.files = Some(files);
    g.size = Some(size);
    Some(g)
}

/// `(?<title>\S+?),\s*WIN!\z` starting at `t0`.
fn p2p_title(s: &[u8], t0: usize) -> Option<(usize, usize)> {
    for e in t0 + 1..s.len() {
        if is_ws(s[e - 1]) {
            break;
        }
        if s[e] == b',' {
            let m = skip_ws(s, e + 1);
            if s[m..].eq_ignore_ascii_case(b"WIN!") {
                return Some((t0, e));
            }
        }
    }
    None
}

fn predatabase_p2p(s: &[u8]) -> Option<Groups<'_>> {
    let i = lit_ci(s, 0, b"p2p")?;
    let i = byte_at(s, skip_ws(s, i), b'|')?;
    let i = lit(s, skip_ws(s, i), b"#1")?;
    let i = byte_at(s, skip_ws(s, i), b'|')?;
    let q = skip_ws(s, i);
    let run_end = token_end(s, q);
    if run_end == q {
        return None;
    }
    // `\S+` is greedy and may contain `|`, so try every end from the longest run down.
    for e in (q + 1..=run_end).rev() {
        let Some(k) = byte_at(s, skip_ws(s, e), b'|') else {
            continue;
        };
        let t0 = skip_ws(s, k);
        if let Some((a, b)) = p2p_title(s, t0) {
            return Some(Groups::new(&s[0..3], &s[a..b]));
        }
    }
    None
}

// ngp ----------------------------------------------------------------------------------------

fn ngp_bracketed(s: &[u8]) -> Option<Groups<'_>> {
    let i = lit(s, 0, b".:::")?;
    let ts = skip_ws(s, i);
    let mut te = ts;
    while te < s.len() && s[te].is_ascii_uppercase() {
        te += 1;
    }
    if te == ts {
        return None;
    }
    let ty = &s[ts..te];
    let i = lit(s, skip_ws(s, te), b":::")?;
    let q = skip_ws(s, i);
    let run_end = token_end(s, q);
    if run_end == q {
        return None;
    }
    for e in (q + 1..=run_end).rev() {
        let Some(k) = lit(s, skip_ws(s, e), b":::") else {
            continue;
        };
        let j = skip_ws(s, k);
        let run2 = token_end(s, j);
        if run2 == j {
            continue;
        }
        for e2 in (j + 1..=run2).rev() {
            if let Some(end) = lit(s, skip_ws(s, e2), b":::.")
                && end == s.len()
            {
                let mut g = Groups::new(ty, &s[j..e2]);
                g.section = Some(&s[q..e]);
                return Some(g);
            }
        }
    }
    None
}

fn ngp_info(s: &[u8]) -> Option<Groups<'_>> {
    let i = lit(s, 0, b"[INFO]")?;
    let i = lit(s, skip_ws(s, i), b"FOR")?;
    let ts = skip_ws(s, i);
    if ts == i {
        return None;
    }
    let te = token_end(s, ts);
    if te == ts {
        return None;
    }
    let w = skip_ws(s, te);
    if w == te {
        return None;
    }
    let i = lit(s, w, b"with")?;
    let w = skip_ws(s, i);
    if w == i {
        return None;
    }
    let fe = skip_digits(s, w);
    if fe == w {
        return None;
    }
    let files = &s[w..fe];
    let k = byte_at(s, fe, b'F')?;
    let w2 = skip_ws(s, k);
    if w2 == k {
        return None;
    }
    let se = size_end(s, w2)?;
    let size = &s[w2..se];
    let mut e = lit(s, se, b"MB added")?;
    if e < s.len() && s[e] == b'.' {
        e += 1;
    }
    if e != s.len() {
        return None;
    }
    let mut g = Groups::new(&s[1..5], &s[ts..te]);
    g.files = Some(files);
    g.size = Some(size);
    Some(g)
}

// rizon --------------------------------------------------------------------------------------

fn rizon_pre(s: &[u8]) -> Option<Groups<'_>> {
    let i = lit(s, 0, b"[PRE]")?;
    let i = byte_at(s, skip_ws(s, i), b'[')?;
    let mut j = i;
    while j < s.len() && s[j] != b']' {
        j += 1;
    }
    if j == i || j >= s.len() {
        return None;
    }
    let title = tail_token(s, skip_ws(s, j + 1))?;
    let mut g = Groups::new(&s[1..4], title);
    g.section = Some(&s[i..j]);
    Some(g)
}

fn rizon_nuke(s: &[u8]) -> Option<Groups<'_>> {
    let open = byte_at(s, 0, b'[')?;
    let (ty, i) = nuke_type(s, open, b']', false)?;
    let ts = skip_ws(s, i);
    if ts == i {
        return None;
    }
    let te = token_end(s, ts);
    if te == ts {
        return None;
    }
    let reason = bracket_reason(s, te)?;
    let mut g = Groups::new(ty, &s[ts..te]);
    g.reason = Some(reason);
    Some(g)
}

const PATTERNS_CORRUPTNET: &[Pattern] = &[corruptnet_pre, corruptnet_info, corruptnet_nuke];
const PATTERNS_ZENET: &[Pattern] = &[zenet_pre, zenet_info, zenet_nuke];
const PATTERNS_PREDATABASE: &[Pattern] = &[predatabase_pre, predatabase_info, predatabase_p2p];
const PATTERNS_NGP: &[Pattern] = &[ngp_bracketed, ngp_info];
const PATTERNS_RIZON: &[Pattern] = &[rizon_pre, rizon_nuke];

fn patterns_for(format: i32) -> Option<&'static [Pattern]> {
    match format {
        FORMAT_CORRUPTNET => Some(PATTERNS_CORRUPTNET),
        FORMAT_ZENET => Some(PATTERNS_ZENET),
        FORMAT_PREDATABASE => Some(PATTERNS_PREDATABASE),
        FORMAT_NGP => Some(PATTERNS_NGP),
        FORMAT_RIZON => Some(PATTERNS_RIZON),
        _ => None,
    }
}

const RELAY_FORMATS: [i32; 4] = [
    FORMAT_CORRUPTNET,
    FORMAT_ZENET,
    FORMAT_PREDATABASE,
    FORMAT_RIZON,
];

#[derive(PartialEq)]
enum Release {
    Valid,
    Invalid,
    /// Non-ASCII title: `\w` under `/u` + UCP needs Unicode tables, so PHP decides.
    Unsupported,
}

#[inline]
fn is_title_class(b: u8) -> bool {
    b.is_ascii_alphanumeric()
        || matches!(
            b,
            b'_' | b'.' | b'(' | b')' | b'&' | b'\'' | b'+' | b',' | b'-'
        )
}

/// `strlen <= 255 && preg_match('/^[\w.()&\'+,-]+-[\w]+\z/u')`
fn is_release_name(title: &[u8]) -> Release {
    if title.len() > 255 {
        return Release::Invalid;
    }
    let mut non_ascii = false;
    for &b in title {
        if b >= 0x80 {
            non_ascii = true;
        } else if !is_title_class(b) {
            return Release::Invalid;
        }
    }
    if non_ascii {
        return Release::Unsupported;
    }
    let Some(dash) = title.iter().rposition(|&b| b == b'-') else {
        return Release::Invalid;
    };
    if dash == 0 || dash + 1 == title.len() {
        return Release::Invalid;
    }
    if title[dash + 1..]
        .iter()
        .all(|&b| b.is_ascii_alphanumeric() || b == b'_')
    {
        Release::Valid
    } else {
        Release::Invalid
    }
}

/// A validated entry, mirroring the checks in `PreAnnounceParser::entry`.
struct Entry<'a> {
    ty: [u8; 8],
    ty_len: usize,
    title: &'a [u8],
    section: &'a [u8],
    reason: &'a [u8],
    files: &'a [u8],
    size: &'a [u8],
}

enum Outcome<'a> {
    NoMatch,
    Unsupported,
    Entry(Entry<'a>),
}

fn validate_entry<'a>(g: &Groups<'a>) -> Outcome<'a> {
    let mut ty = [0u8; 8];
    if g.ty.is_empty() || g.ty.len() > ty.len() {
        return Outcome::NoMatch;
    }
    for (d, s) in ty.iter_mut().zip(g.ty) {
        *d = s.to_ascii_uppercase();
    }
    let upper = &ty[..g.ty.len()];
    if !KNOWN_TYPES.contains(&upper) {
        return Outcome::NoMatch;
    }
    match is_release_name(g.title) {
        Release::Invalid => return Outcome::NoMatch,
        Release::Unsupported => return Outcome::Unsupported,
        Release::Valid => {}
    }
    let section = php_trim(g.section.unwrap_or(b""));
    let reason = php_trim(g.reason.unwrap_or(b""));
    let files = g.files.unwrap_or(b"");
    let size = g.size.unwrap_or(b"");
    if upper == b"PRE" && section.is_empty() {
        return Outcome::NoMatch;
    }
    if upper == b"INFO" && (files.is_empty() || size.is_empty()) {
        return Outcome::NoMatch;
    }
    Outcome::Entry(Entry {
        ty,
        ty_len: g.ty.len(),
        title: g.title,
        section,
        reason,
        files,
        size,
    })
}

/// Mirrors `PreAnnounceParser::parse` for an already CR/LF-checked, untrimmed message.
fn parse_announce(message: &[u8], format: i32) -> Outcome<'_> {
    if message.iter().any(|&b| b == b'\r' || b == b'\n') {
        return Outcome::NoMatch;
    }
    let s = php_trim(message);

    if format == FORMAT_OPENTRACKERS {
        let mut found: Option<Entry<'_>> = None;
        for f in RELAY_FORMATS {
            let Some(patterns) = patterns_for(f) else {
                continue;
            };
            for p in patterns {
                let Some(g) = p(s) else { continue };
                if g.ty.eq_ignore_ascii_case(b"PRE") && g.section.is_none() {
                    continue;
                }
                match validate_entry(&g) {
                    Outcome::Unsupported => return Outcome::Unsupported,
                    Outcome::NoMatch => {}
                    Outcome::Entry(e) => {
                        if found.is_some() {
                            return Outcome::NoMatch;
                        }
                        found = Some(e);
                    }
                }
            }
        }
        return found.map_or(Outcome::NoMatch, Outcome::Entry);
    }

    let Some(patterns) = patterns_for(format) else {
        return Outcome::Unsupported;
    };
    for p in patterns {
        if let Some(g) = p(s) {
            return validate_entry(&g);
        }
    }
    Outcome::NoMatch
}

/// `type\0title\0section\0reason\0files\0size`
fn write_entry(out: &mut OutCursor<'_>, e: &Entry<'_>) {
    out.extend(&e.ty[..e.ty_len]);
    out.push(0);
    out.extend(e.title);
    out.push(0);
    out.extend(e.section);
    out.push(0);
    out.extend(e.reason);
    out.push(0);
    out.extend(e.files);
    out.push(0);
    out.extend(e.size);
}

/// Parses one IRC announce for `format` (0 corruptnet, 1 zenet, 2 predatabase, 3 ngp, 4 rizon,
/// 5 opentrackers). Returns the byte length of `type\0title\0section\0reason\0files\0size`, `0`
/// when the line is not a valid announce, `-(needed)` when `out_cap` is too small, and `-1` when
/// the caller must use the PHP implementation.
///
/// # Safety
/// `line` must be readable for `line_len` bytes and `out_buf` writable for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_parse_irc_announce(
    line: *const u8,
    line_len: usize,
    format: i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let message = if line.is_null() || line_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(line, line_len) }
    };
    if message.contains(&0) {
        return -1;
    }
    match parse_announce(message, format) {
        Outcome::NoMatch => 0,
        Outcome::Unsupported => -1,
        Outcome::Entry(e) => {
            let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
            write_entry(&mut out, &e);
            out.finish()
        }
    }
}

/// Batch variant: `lines` holds NUL-separated announce lines (a trailing NUL is optional).
/// Writes one `\n`-terminated record per line: the entry fields exactly as the single-line
/// function emits them, an empty record for no match, or `?` when PHP must parse that line.
/// Returns the byte length, `-(needed)` when `out_cap` is too small, or `-1` for an invalid format.
///
/// # Safety
/// `lines` must be readable for `lines_len` bytes and `out_buf` writable for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_parse_irc_announce_batch_nul(
    lines: *const u8,
    lines_len: usize,
    format: i32,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if format != FORMAT_OPENTRACKERS && patterns_for(format).is_none() {
        return -1;
    }
    let data = if lines.is_null() || lines_len == 0 {
        &[][..]
    } else {
        unsafe { slice::from_raw_parts(lines, lines_len) }
    };
    let data = data.strip_suffix(&[0]).unwrap_or(data);
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    if lines_len == 0 {
        return 0;
    }
    for line in data.split(|&b| b == 0) {
        match parse_announce(line, format) {
            Outcome::NoMatch => {}
            Outcome::Unsupported => out.push(b'?'),
            Outcome::Entry(e) => write_entry(&mut out, &e),
        }
        out.push(b'\n');
    }
    out.finish()
}

// ---------------------------------------------------------------------------------------------
// nZEDb archive (MySQL OUTFILE) parser
// ---------------------------------------------------------------------------------------------

const ERR_RECORD_LIMIT: u8 = b'1';
const ERR_TRUNCATED_RECORD: u8 = b'2';
const ERR_INVALID_FIELD: u8 = b'3';
const ERR_TRUNCATED_ESCAPE: u8 = b'4';
const ERR_INVALID_ESCAPE: u8 = b'5';
const ERR_UNTERMINATED: u8 = b'6';

const REJECT_EMPTY: u8 = b'1';
const REJECT_OUTER_WHITESPACE: u8 = b'2';
const REJECT_OVERLENGTH: u8 = b'3';
const REJECT_CONTROL: u8 = b'4';

const FIELD_COUNT: usize = 12;
const CATEGORY_COLUMN: usize = 7;

#[inline]
fn outfile_escape(b: u8) -> Option<u8> {
    match b {
        b'0' => Some(0),
        b'b' => Some(0x08),
        b'n' => Some(b'\n'),
        b'r' => Some(b'\r'),
        b't' => Some(b'\t'),
        b'Z' => Some(0x1a),
        b'\'' => Some(b'\''),
        b'"' => Some(b'"'),
        b'\\' => Some(b'\\'),
        _ => None,
    }
}

/// Raw bytes between the quotes of one field (`None` for the `\N` sentinel).
type RawField<'a> = Option<&'a [u8]>;

/// Mirrors `NzedbArchiveSource::readField` without decoding; returns the raw field and the next offset.
fn scan_field(csv: &[u8], offset: usize) -> Result<(RawField<'_>, usize), u8> {
    if csv.len() >= offset + 2 && csv[offset] == b'\\' && csv[offset + 1] == b'N' {
        return Ok((None, offset + 2));
    }
    if csv.get(offset) != Some(&b'\'') {
        return Err(ERR_INVALID_FIELD);
    }
    let start = offset + 1;
    let mut i = start;
    while i < csv.len() {
        match csv[i] {
            b'\'' => return Ok((Some(&csv[start..i]), i + 1)),
            b'\\' => {
                let Some(&e) = csv.get(i + 1) else {
                    return Err(ERR_TRUNCATED_ESCAPE);
                };
                if outfile_escape(e).is_none() {
                    return Err(ERR_INVALID_ESCAPE);
                }
                i += 2;
            }
            _ => i += 1,
        }
    }
    Err(ERR_UNTERMINATED)
}

/// Decodes the validated escapes of `raw`, borrowing it unchanged when there are none.
fn decode_field<'a>(raw: &'a [u8], scratch: &'a mut Vec<u8>) -> &'a [u8] {
    if !raw.contains(&b'\\') {
        return raw;
    }
    scratch.clear();
    let mut i = 0;
    while i < raw.len() {
        if raw[i] == b'\\' {
            scratch.push(outfile_escape(raw[i + 1]).unwrap_or(raw[i + 1]));
            i += 2;
        } else {
            scratch.push(raw[i]);
            i += 1;
        }
    }
    scratch
}

/// Code point count of valid UTF-8 (`mb_strlen($s, 'UTF-8')`).
#[inline]
fn utf8_chars(s: &[u8]) -> usize {
    s.iter().filter(|&&b| (b & 0xC0) != 0x80).count()
}

/// `preg_match('/\p{Cc}/u', $s)` for valid UTF-8: C0 controls, DEL and U+0080..U+009F.
fn has_control(s: &[u8]) -> bool {
    let mut i = 0;
    while i < s.len() {
        let b = s[i];
        if b < 0x20 || b == 0x7f {
            return true;
        }
        if b == 0xC2 && i + 1 < s.len() && (0x80..=0x9f).contains(&s[i + 1]) {
            return true;
        }
        i += 1;
    }
    false
}

/// `NzedbArchiveSource::titleRejection`; `None` means accepted.
fn title_rejection(title: Option<&[u8]>) -> Option<u8> {
    let Some(title) = title else {
        return Some(REJECT_EMPTY);
    };
    let trimmed = php_trim(title);
    if trimmed.is_empty() {
        return Some(REJECT_EMPTY);
    }
    if trimmed.len() != title.len() {
        return Some(REJECT_OUTER_WHITESPACE);
    }
    if utf8_chars(title) > 255 {
        return Some(REJECT_OVERLENGTH);
    }
    if has_control(title) {
        return Some(REJECT_CONTROL);
    }
    None
}

/// Parses a complete MySQL OUTFILE export (12 single-quoted fields per record, `\t\t` between
/// fields, `\r\n` after the last, `\N` for NULL).
///
/// Output starts with `K<records>\n` followed by one line per record: `A<title>\x1f<category>\n`
/// for an accepted title (empty category means NULL) or `R<code>\n` for a rejected one
/// (1 empty, 2 outer whitespace, 3 overlength, 4 control characters). A parse failure instead
/// yields the two bytes `E<code>` (1 record limit, 2 truncated record, 3 invalid field,
/// 4 truncated escape, 5 invalid escape, 6 unterminated field).
///
/// Returns the output length, `-(needed)` when `out_cap` is too small, or `-1` when the input is
/// not valid UTF-8 / contains NUL so PHP must produce its own error.
///
/// # Safety
/// `csv` must be readable for `csv_len` bytes and `out_buf` writable for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_nzedb_parse_csv(
    csv: *const u8,
    csv_len: usize,
    max_records: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if csv.is_null() || csv_len == 0 {
        return -1;
    }
    let csv = unsafe { slice::from_raw_parts(csv, csv_len) };
    if csv.contains(&0) || std::str::from_utf8(csv).is_err() {
        return -1;
    }
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };

    // The header carries the record count, so the body is staged before the header is written.
    let mut body: Vec<u8> = Vec::with_capacity(csv.len() / 4 + 64);
    let mut offset = 0usize;
    let mut records = 0usize;
    let mut title_scratch: Vec<u8> = Vec::new();
    let mut category_scratch: Vec<u8> = Vec::new();
    while offset < csv.len() {
        records += 1;
        if records > max_records {
            out.push(b'E');
            out.push(ERR_RECORD_LIMIT);
            return out.finish();
        }
        let mut title_raw: RawField<'_> = None;
        let mut category_raw: RawField<'_> = None;
        for column in 0..FIELD_COUNT {
            match scan_field(csv, offset) {
                Ok((field, next)) => {
                    if column == 0 {
                        title_raw = field;
                    } else if column == CATEGORY_COLUMN {
                        category_raw = field;
                    }
                    offset = next;
                }
                Err(code) => {
                    out.push(b'E');
                    out.push(code);
                    return out.finish();
                }
            }
            let separator: &[u8] = if column == FIELD_COUNT - 1 {
                b"\r\n"
            } else {
                b"\t\t"
            };
            if csv.get(offset..offset + 2) != Some(separator) {
                out.push(b'E');
                out.push(ERR_TRUNCATED_RECORD);
                return out.finish();
            }
            offset += 2;
        }
        let title = title_raw.map(|raw| decode_field(raw, &mut title_scratch));
        if let Some(code) = title_rejection(title) {
            body.push(b'R');
            body.push(code);
            body.push(b'\n');
            continue;
        }
        body.push(b'A');
        body.extend_from_slice(title.unwrap_or(b""));
        body.push(0x1f);
        if let Some(raw) = category_raw {
            let category = decode_field(raw, &mut category_scratch);
            if !category.is_empty() && utf8_chars(category) <= 255 && !has_control(category) {
                body.extend_from_slice(category);
            }
        }
        body.push(b'\n');
    }

    out.push(b'K');
    let mut digits = [0u8; 20];
    let mut n = records;
    let mut pos = digits.len();
    loop {
        pos -= 1;
        digits[pos] = b'0' + (n % 10) as u8;
        n /= 10;
        if n == 0 {
            break;
        }
    }
    out.extend(&digits[pos..]);
    out.push(b'\n');
    out.extend(&body);
    out.finish()
}

// ---------------------------------------------------------------------------------------------
// Knaben duplicate-member scan
// ---------------------------------------------------------------------------------------------

const MAX_JSON_DEPTH: usize = 128;

enum ScanError {
    Duplicate,
    Malformed,
}

/// One object member key: FNV-1a hash of the decoded bytes and the raw (still escaped) token.
#[derive(Clone, Copy)]
struct KeyRef {
    hash: u64,
    start: u32,
    end: u32,
}

struct KeyScanner<'a> {
    s: &'a [u8],
    i: usize,
    keys: Vec<KeyRef>,
    cmp_a: Vec<u8>,
    cmp_b: Vec<u8>,
}

#[inline]
fn hex4(s: &[u8]) -> Option<u32> {
    if s.len() < 4 {
        return None;
    }
    let mut v = 0u32;
    for &b in &s[..4] {
        v = (v << 4) | (b as char).to_digit(16)?;
    }
    Some(v)
}

/// Feeds the UTF-8 bytes of the JSON string `tok` (content between the quotes) to `f`.
/// Returns `false` for escapes `json_decode` would not have accepted.
fn decode_json_key(tok: &[u8], f: &mut impl FnMut(u8)) -> bool {
    let mut i = 0;
    while i < tok.len() {
        let b = tok[i];
        if b != b'\\' {
            f(b);
            i += 1;
            continue;
        }
        let Some(&e) = tok.get(i + 1) else {
            return false;
        };
        i += 2;
        match e {
            b'"' => f(b'"'),
            b'\\' => f(b'\\'),
            b'/' => f(b'/'),
            b'b' => f(0x08),
            b'f' => f(0x0c),
            b'n' => f(b'\n'),
            b'r' => f(b'\r'),
            b't' => f(b'\t'),
            b'u' => {
                let Some(hi) = hex4(&tok[i..]) else {
                    return false;
                };
                i += 4;
                let cp = if (0xD800..0xDC00).contains(&hi) {
                    if tok.get(i) != Some(&b'\\') || tok.get(i + 1) != Some(&b'u') {
                        return false;
                    }
                    let Some(lo) = hex4(&tok[i + 2..]) else {
                        return false;
                    };
                    if !(0xDC00..0xE000).contains(&lo) {
                        return false;
                    }
                    i += 6;
                    0x10000 + ((hi - 0xD800) << 10) + (lo - 0xDC00)
                } else if (0xDC00..0xE000).contains(&hi) {
                    return false;
                } else {
                    hi
                };
                let Some(ch) = char::from_u32(cp) else {
                    return false;
                };
                let mut buf = [0u8; 4];
                for &byte in ch.encode_utf8(&mut buf).as_bytes() {
                    f(byte);
                }
            }
            _ => return false,
        }
    }
    true
}

impl<'a> KeyScanner<'a> {
    #[inline]
    fn ws(&mut self) {
        while self.i < self.s.len() && matches!(self.s[self.i], b' ' | b'\t' | b'\r' | b'\n') {
            self.i += 1;
        }
    }

    /// Consumes a string token starting at the opening quote; returns the content range.
    fn string_token(&mut self) -> Result<(usize, usize), ScanError> {
        if self.s.get(self.i) != Some(&b'"') {
            return Err(ScanError::Malformed);
        }
        self.i += 1;
        let start = self.i;
        while self.i < self.s.len() {
            match self.s[self.i] {
                b'"' => {
                    let end = self.i;
                    self.i += 1;
                    return Ok((start, end));
                }
                b'\\' => self.i += 2,
                _ => self.i += 1,
            }
        }
        Err(ScanError::Malformed)
    }

    fn key_hash(&self, start: usize, end: usize) -> Result<u64, ScanError> {
        let mut h: u64 = 0xcbf2_9ce4_8422_2325;
        let ok = decode_json_key(&self.s[start..end], &mut |b| {
            h ^= u64::from(b);
            h = h.wrapping_mul(0x0000_0100_0000_01b3);
        });
        if !ok {
            return Err(ScanError::Malformed);
        }
        h ^= h >> 32;
        Ok(h)
    }

    fn keys_equal(&mut self, a: KeyRef, b: KeyRef) -> bool {
        self.cmp_a.clear();
        self.cmp_b.clear();
        let s = self.s;
        let (sa, sb) = (
            &s[a.start as usize..a.end as usize],
            &s[b.start as usize..b.end as usize],
        );
        let (ca, cb) = (&mut self.cmp_a, &mut self.cmp_b);
        decode_json_key(sa, &mut |x| ca.push(x));
        decode_json_key(sb, &mut |x| cb.push(x));
        self.cmp_a == self.cmp_b
    }

    fn check_duplicates(&mut self, base: usize) -> Result<(), ScanError> {
        let n = self.keys.len() - base;
        if n > 1 {
            if n <= 24 {
                for x in base..self.keys.len() {
                    for y in x + 1..self.keys.len() {
                        if self.keys[x].hash == self.keys[y].hash
                            && self.keys_equal(self.keys[x], self.keys[y])
                        {
                            return Err(ScanError::Duplicate);
                        }
                    }
                }
            } else {
                self.keys[base..].sort_unstable_by_key(|k| k.hash);
                let mut x = base;
                while x < self.keys.len() {
                    let mut y = x + 1;
                    while y < self.keys.len() && self.keys[y].hash == self.keys[x].hash {
                        y += 1;
                    }
                    for p in x..y {
                        for q in p + 1..y {
                            if self.keys_equal(self.keys[p], self.keys[q]) {
                                return Err(ScanError::Duplicate);
                            }
                        }
                    }
                    x = y;
                }
            }
        }
        self.keys.truncate(base);
        Ok(())
    }

    fn value(&mut self, depth: usize) -> Result<(), ScanError> {
        if depth > MAX_JSON_DEPTH {
            return Err(ScanError::Malformed);
        }
        self.ws();
        match self.s.get(self.i) {
            None => Err(ScanError::Malformed),
            Some(b'{') => {
                self.i += 1;
                let base = self.keys.len();
                self.ws();
                if self.s.get(self.i) == Some(&b'}') {
                    self.i += 1;
                    return Ok(());
                }
                loop {
                    self.ws();
                    let (start, end) = self.string_token()?;
                    let hash = self.key_hash(start, end)?;
                    if end > u32::MAX as usize {
                        return Err(ScanError::Malformed);
                    }
                    self.keys.push(KeyRef {
                        hash,
                        start: start as u32,
                        end: end as u32,
                    });
                    self.ws();
                    if self.s.get(self.i) != Some(&b':') {
                        return Err(ScanError::Malformed);
                    }
                    self.i += 1;
                    self.value(depth + 1)?;
                    self.ws();
                    match self.s.get(self.i) {
                        Some(b',') => self.i += 1,
                        Some(b'}') => {
                            self.i += 1;
                            return self.check_duplicates(base);
                        }
                        _ => return Err(ScanError::Malformed),
                    }
                }
            }
            Some(b'[') => {
                self.i += 1;
                self.ws();
                if self.s.get(self.i) == Some(&b']') {
                    self.i += 1;
                    return Ok(());
                }
                loop {
                    self.value(depth + 1)?;
                    self.ws();
                    match self.s.get(self.i) {
                        Some(b',') => self.i += 1,
                        Some(b']') => {
                            self.i += 1;
                            return Ok(());
                        }
                        _ => return Err(ScanError::Malformed),
                    }
                }
            }
            Some(b'"') => self.string_token().map(|_| ()),
            Some(_) => {
                let start = self.i;
                while self.i < self.s.len()
                    && !matches!(
                        self.s[self.i],
                        b',' | b']' | b'}' | b' ' | b'\t' | b'\r' | b'\n'
                    )
                {
                    self.i += 1;
                }
                if self.i == start {
                    Err(ScanError::Malformed)
                } else {
                    Ok(())
                }
            }
        }
    }
}

/// Scans an already `json_decode`-validated document for duplicate object member names at any
/// depth, comparing keys after unescaping. Returns `1` when every object has unique members,
/// `0` when a duplicate exists and `-1` when the document is malformed or uses an escape this
/// scanner does not reproduce (the caller then runs the PHP scan).
///
/// # Safety
/// `json` must be readable for `json_len` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_knaben_validate_unique_keys(
    json: *const u8,
    json_len: usize,
) -> i32 {
    if json.is_null() || json_len == 0 {
        return -1;
    }
    let s = unsafe { slice::from_raw_parts(json, json_len) };
    let mut scanner = KeyScanner {
        s,
        i: 0,
        keys: Vec::with_capacity(32),
        cmp_a: Vec::new(),
        cmp_b: Vec::new(),
    };
    match scanner.value(0) {
        Ok(()) => {
            scanner.ws();
            if scanner.i == s.len() { 1 } else { -1 }
        }
        Err(ScanError::Duplicate) => 0,
        Err(ScanError::Malformed) => -1,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn announce(format: i32, line: &str) -> Option<Vec<String>> {
        let mut buf = vec![0u8; 1024];
        let n = unsafe {
            nntmux_predb_parse_irc_announce(
                line.as_ptr(),
                line.len(),
                format,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(n >= 0, "unexpected status {n} for {line:?}");
        if n == 0 {
            return None;
        }
        Some(
            buf[..n as usize]
                .split(|&b| b == 0)
                .map(|p| String::from_utf8_lossy(p).into_owned())
                .collect(),
        )
    }

    #[test]
    fn corruptnet_lines() {
        let f = FORMAT_CORRUPTNET;
        assert_eq!(
            announce(
                f,
                "PRE: [TV-X264] MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG"
            )
            .unwrap(),
            [
                "PRE",
                "MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG",
                "TV-X264",
                "",
                "",
                ""
            ]
        );
        assert_eq!(
            announce(f, "INFO: Quo.Vadis.v1.0.85-BLiZZARD [14F 67MB]").unwrap(),
            ["INFO", "Quo.Vadis.v1.0.85-BLiZZARD", "", "", "14", "67"]
        );
        assert_eq!(
            announce(f, "NUKE: Some.Release.2026-GRP [bad.audio] [NUKENET]").unwrap(),
            ["NUKE", "Some.Release.2026-GRP", "", "bad.audio", "", ""]
        );
        assert!(announce(f, "PRE: [] Some.Release.2026-GRP").is_none());
        assert!(announce(f, "PRE: [X] nodash").is_none());
        assert!(announce(f, "PRE: [X] Name-GRP\n").is_none());
    }

    #[test]
    fn zenet_lines() {
        let f = FORMAT_ZENET;
        assert_eq!(
            announce(
                f,
                "(PRE) (MP3-WEB) (Slow_AF-Exceptions-(FGA103)-WEB-2026-PTC)"
            )
            .unwrap()[1],
            "Slow_AF-Exceptions-(FGA103)-WEB-2026-PTC"
        );
        assert_eq!(
            announce(
                f,
                "(info) (Some.Release.2026.1080p.WEB.h264-GRP) (2 files) (38.5 mb)"
            )
            .unwrap(),
            [
                "INFO",
                "Some.Release.2026.1080p.WEB.h264-GRP",
                "",
                "",
                "2",
                "38.5"
            ]
        );
        assert_eq!(
            announce(f, "(NUKE) (Some.Release-GRP) (dupe)").unwrap()[3],
            "dupe"
        );
    }

    #[test]
    fn predatabase_lines() {
        let f = FORMAT_PREDATABASE;
        assert_eq!(
            announce(f, "pre | TV-HD-CZ | MASH.S02E12-OMG").unwrap(),
            ["PRE", "MASH.S02E12-OMG", "TV-HD-CZ", "", "", ""]
        );
        assert_eq!(
            announce(f, "info | Quo.Vadis-BLiZZARD - 14 F & 67 MB").unwrap(),
            ["INFO", "Quo.Vadis-BLiZZARD", "", "", "14", "67"]
        );
        assert_eq!(
            announce(f, "p2p | #1 | SP | The.Kardashians.S08E01-Kitsune, WIN!").unwrap()[1],
            "The.Kardashians.S08E01-Kitsune"
        );
        assert!(announce(f, "p2p | #3 | IPT | Impact.S05E02-RAWR, HDT won 2h ago").is_none());
        // An all-whitespace section gives one byte back to the lazy group, then fails PRE validation.
        assert!(announce(f, "pre |  | Some.Release-GRP").is_none());
    }

    #[test]
    fn ngp_and_rizon_lines() {
        assert_eq!(
            announce(
                FORMAT_NGP,
                ".::: PRE ::: TV-X264-HD ::: MASH.S02E12-OMG :::."
            )
            .unwrap(),
            ["PRE", "MASH.S02E12-OMG", "TV-X264-HD", "", "", ""]
        );
        assert_eq!(
            announce(
                FORMAT_NGP,
                "[INFO] FOR Coss-Ginkgo-(METANOIA064)-24BIT-WEB-FLAC-2022-BABAS with 5F 158.3MB added."
            )
            .unwrap(),
            [
                "INFO",
                "Coss-Ginkgo-(METANOIA064)-24BIT-WEB-FLAC-2022-BABAS",
                "",
                "",
                "5",
                "158.3"
            ]
        );
        // A generic ngp type that is not a known announce type is rejected, as is INFO without sizes.
        assert!(announce(FORMAT_NGP, ".::: XYZ ::: SEC ::: Some.Release-GRP :::.").is_none());
        assert!(announce(FORMAT_NGP, ".::: INFO ::: SEC ::: Some.Release-GRP :::.").is_none());
        assert_eq!(
            announce(FORMAT_RIZON, "[PRE]  [TV-HD]  MASH.S02E12-OMG").unwrap(),
            ["PRE", "MASH.S02E12-OMG", "TV-HD", "", "", ""]
        );
        assert_eq!(
            announce(FORMAT_RIZON, "[NUKE] Some.Release-GRP [bad]").unwrap()[3],
            "bad"
        );
    }

    #[test]
    fn relay_requires_a_single_match_and_unicode_titles_defer_to_php() {
        let f = FORMAT_OPENTRACKERS;
        assert!(announce(f, "PRE: [TV] Some.Release.2026-GRP").is_some());
        assert!(announce(f, "gibberish").is_none());
        let mut buf = [0u8; 64];
        let line = "PRE: [TV] Séance.2026-GRP";
        let n = unsafe {
            nntmux_predb_parse_irc_announce(
                line.as_ptr(),
                line.len(),
                FORMAT_CORRUPTNET,
                buf.as_mut_ptr(),
                64,
            )
        };
        assert_eq!(n, -1);
        let nul = b"PRE: [TV] A\0B-GRP";
        let n = unsafe {
            nntmux_predb_parse_irc_announce(
                nul.as_ptr(),
                nul.len(),
                FORMAT_CORRUPTNET,
                buf.as_mut_ptr(),
                64,
            )
        };
        assert_eq!(n, -1);
    }

    #[test]
    fn batch_matches_single() {
        let input = b"PRE: [TV] Some.Release.2026-GRP\0nonsense\0PRE: [TV] Caf\xc3\xa9.2026-GRP\0";
        let mut buf = vec![0u8; 512];
        let n = unsafe {
            nntmux_predb_parse_irc_announce_batch_nul(
                input.as_ptr(),
                input.len(),
                FORMAT_CORRUPTNET,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(n > 0);
        let out = &buf[..n as usize];
        let records: Vec<&[u8]> = out.split(|&b| b == b'\n').collect();
        assert_eq!(records[0], b"PRE\0Some.Release.2026-GRP\0TV\0\0\0");
        assert_eq!(records[1], b"");
        assert_eq!(records[2], b"?");
        // Too small output buffer reports the exact size.
        let need = unsafe {
            nntmux_predb_parse_irc_announce_batch_nul(
                input.as_ptr(),
                input.len(),
                FORMAT_CORRUPTNET,
                std::ptr::null_mut(),
                0,
            )
        };
        assert_eq!(need, -(n));
    }

    fn csv_record(title: &str, category: &str) -> String {
        format!(
            "'{title}'\t\t'a'\t\t'b'\t\t'c'\t\t'd'\t\t'e'\t\t'f'\t\t{category}\t\t'h'\t\t'i'\t\t'j'\t\t'k'\r\n"
        )
    }

    fn nzedb(csv: &[u8], max: usize) -> Out {
        let mut buf = vec![0u8; csv.len() + 256];
        let n = unsafe {
            nntmux_predb_nzedb_parse_csv(csv.as_ptr(), csv.len(), max, buf.as_mut_ptr(), buf.len())
        };
        if n < 0 {
            return Out::Status(n);
        }
        Out::Text(String::from_utf8_lossy(&buf[..n as usize]).into_owned())
    }

    #[derive(Debug, PartialEq)]
    enum Out {
        Status(isize),
        Text(String),
    }

    #[test]
    fn nzedb_records() {
        let csv = [
            csv_record("Some.Release-GRP", "'Movies'"),
            csv_record(" lead", "'x'"),
            csv_record("a\\nb", "'x'"),
            csv_record("Nul\\0", "'x'"),
            csv_record("Plain.Title-GRP", "\\N"),
            csv_record("Cat.Empty-GRP", "''"),
            csv_record("Cat.Ctrl-GRP", "'a\\nb'"),
        ]
        .concat();
        let Out::Text(t) = nzedb(csv.as_bytes(), 100) else {
            panic!("parse failed");
        };
        assert_eq!(
            t,
            "K7\nASome.Release-GRP\x1fMovies\nR2\nR4\nR2\nAPlain.Title-GRP\x1f\nACat.Empty-GRP\x1f\nACat.Ctrl-GRP\x1f\n"
        );
    }

    #[test]
    fn nzedb_errors_and_limits() {
        let ok = csv_record("A.B-GRP", "'c'");
        assert_eq!(nzedb(ok.as_bytes(), 0), Out::Text("E1".into()));
        assert_eq!(nzedb(b"x", 5), Out::Text("E3".into()));
        assert_eq!(nzedb(b"'abc", 5), Out::Text("E6".into()));
        assert_eq!(nzedb(b"'ab\\", 5), Out::Text("E4".into()));
        assert_eq!(nzedb(b"'ab\\q'", 5), Out::Text("E5".into()));
        assert_eq!(nzedb(b"'ab'\tx", 5), Out::Text("E2".into()));
        assert_eq!(nzedb(b"'\xff'", 5), Out::Status(-1));
        assert_eq!(nzedb(b"'a\x00'", 5), Out::Status(-1));
        // The record limit trips on the record after the last allowed one, before a later syntax error.
        let two = format!("{ok}{ok}broken");
        assert_eq!(nzedb(two.as_bytes(), 3), Out::Text("E3".into()));
        assert_eq!(nzedb(two.as_bytes(), 2), Out::Text("E1".into()));
    }

    fn knaben(json: &str) -> i32 {
        unsafe { nntmux_predb_knaben_validate_unique_keys(json.as_ptr(), json.len()) }
    }

    #[test]
    fn knaben_duplicate_keys() {
        assert_eq!(knaben(r#"{"a":1,"b":{"a":2},"c":[{"a":1},{"a":2}]}"#), 1);
        assert_eq!(knaben(r#"{"a":1,"a":2}"#), 0);
        assert_eq!(knaben(r#"{"a":1,"a":2}"#), 0);
        assert_eq!(knaben(r#"{"x":[{"k":1,"k":2}]}"#), 0);
        assert_eq!(knaben(r#"{"😀":1,"😀":2}"#), 0);
        assert_eq!(knaben(r#"{"a/b":1,"a\/b":2}"#), 0);
        assert_eq!(knaben(r#"{"a":1,"A":2}"#), 1);
        assert_eq!(knaben(r#"{"title":"a\"b","t":{}}"#), 1);
        assert_eq!(knaben(r#"[1,2,{"a":[],"b":{}}]"#), 1);
        assert_eq!(knaben(r#"{"a":1"#), -1);
        assert_eq!(knaben(r#"{"a" 1}"#), -1);
        assert_eq!(knaben(r#"{"\ud800":1}"#), -1);
        assert_eq!(knaben(""), -1);
    }

    #[test]
    fn knaben_large_object_uses_sorted_path() {
        let mut json = String::from("{");
        for i in 0..200 {
            json.push_str(&format!("\"key{i}\":{i},"));
        }
        json.push_str("\"last\":1}");
        assert_eq!(knaben(&json), 1);
        let dup = json.replace("\"last\"", "\"key77\"");
        assert_eq!(knaben(&dup), 0);
    }

    #[test]
    fn knaben_depth_guard() {
        let deep = format!("{}{}", "[".repeat(200), "]".repeat(200));
        assert_eq!(knaben(&deep), -1);
    }
}

#[cfg(test)]
mod sql_html_tests {
    use super::*;

    #[test]
    fn sql_scan_carries_quotes_and_rejects_binary_tail() {
        unsafe {
            let s = b"'a;\\";
            assert_eq!(
                nntmux_predb_scene_sql_scan_statement(s.as_ptr(), s.len(), 0, 0),
                -4
            );
            let s = b"';x';tail";
            assert_eq!(
                nntmux_predb_scene_sql_scan_statement(s.as_ptr(), s.len(), 1, 1),
                4
            );
            assert_eq!(
                nntmux_predb_scene_sql_scan_statement(b";\0".as_ptr(), 2, 0, 0),
                -5
            );
        }
    }

    #[test]
    fn sql_insert_hashes_and_errors() {
        let s = b"INSERT INTO `predb` VALUES (1,'TV','A.B-GRP','grp',0,NULL,0,NULL,1);";
        let mut out = [0u8; 512];
        unsafe {
            assert_eq!(
                nntmux_predb_scene_sql_parse_insert(
                    s.as_ptr(),
                    s.len(),
                    0,
                    out.as_mut_ptr(),
                    out.len()
                ),
                88
            );
            assert_eq!(&out[..8], &[1, 0, 0, 0, 1, 0, 0, 0]);
            assert_eq!(&out[24..56], &scene_digest(b"id\0", b"1"));
            assert_eq!(&out[56..88], &scene_digest(b"title\0", b"a.b-grp"));
            assert_eq!(
                nntmux_predb_scene_sql_parse_insert(
                    s.as_ptr(),
                    s.len(),
                    1,
                    out.as_mut_ptr(),
                    out.len()
                ),
                8
            );
            assert_eq!(&out[..8], b"A.B-GRP\0");
            assert_eq!(
                nntmux_predb_scene_sql_parse_insert(s.as_ptr(), s.len(), 0, out.as_mut_ptr(), 1),
                -100
            );
            let s = b"INSERT INTO `predb` VALUES (0,'TV','A-GRP','grp',0,NULL,0,NULL,1);";
            assert_eq!(
                nntmux_predb_scene_sql_parse_insert(
                    s.as_ptr(),
                    s.len(),
                    0,
                    out.as_mut_ptr(),
                    out.len()
                ),
                -11
            );
        }
    }
}

// SQL return codes: -1 prefix, -2 boundary, -3 arity, -4 trailing data,
// -5 truncated escape, -6 unknown escape, -7 unterminated string, -8 scalar,
// -9 string schema, -10 numeric schema, -11 ID, -100 output capacity, -101 ABI.
// Validation output: six LE u32s (rows, eligible, noncanonical, group conflict,
// first noncanonical row, first conflicting row), then two raw SHA-256s per row.
// No input-sized allocations: decoded fields and digest preimages live on the stack.

/// Returns a boundary offset, or -(1 + quoted + 2*escaped), or -5 for NUL.
/// The entire chunk is checked for NUL, including bytes following a boundary.
/// # Safety
/// `input` must point to `len` readable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_scene_sql_scan_statement(
    input: *const u8,
    len: usize,
    quoted: u8,
    escaped: u8,
) -> i64 {
    if input.is_null() || quoted > 1 || escaped > 1 {
        return -6;
    }
    let bytes = unsafe { slice::from_raw_parts(input, len) };
    if bytes.contains(&0) {
        return -5;
    }
    let mut q = quoted != 0;
    let mut e = escaped != 0;
    for (i, &b) in bytes.iter().enumerate() {
        if e {
            e = false;
        } else if q && b == b'\\' {
            e = true;
        } else if b == b'\'' {
            q = !q;
        } else if !q && b == b';' {
            return i as i64;
        }
    }
    -(1 + i64::from(q) + 2 * i64::from(e))
}

#[derive(Clone, Copy)]
struct SceneScalar {
    bytes: [u8; 255],
    len: usize,
    null: bool,
}
impl SceneScalar {
    fn push(&mut self, b: u8) {
        if self.len < self.bytes.len() {
            self.bytes[self.len] = b;
        }
        self.len += 1;
    }
    fn value(&self) -> &[u8] {
        &self.bytes[..self.len.min(255)]
    }
}
fn scene_integer(s: &[u8]) -> bool {
    let digits = s.strip_prefix(b"-").unwrap_or(s);
    !digits.is_empty() && digits.iter().all(u8::is_ascii_digit)
}
fn scene_scalar(s: &[u8], p: &mut usize) -> Result<SceneScalar, isize> {
    let mut v = SceneScalar {
        bytes: [0; 255],
        len: 0,
        null: false,
    };
    if s.get(*p) == Some(&b'\'') {
        *p += 1;
        while let Some(&b) = s.get(*p) {
            *p += 1;
            match b {
                b'\'' => {
                    if s.get(*p) != Some(&b'\'') {
                        return Ok(v);
                    }
                    *p += 1;
                    v.push(b'\'');
                }
                b'\\' => {
                    let b = *s.get(*p).ok_or(-5isize)?;
                    *p += 1;
                    v.push(match b {
                        b'0' => 0,
                        b'b' => 8,
                        b'n' => 10,
                        b'r' => 13,
                        b't' => 9,
                        b'Z' => 26,
                        b'\'' | b'"' | b'\\' => b,
                        _ => return Err(-6),
                    });
                }
                _ => v.push(b),
            }
        }
        return Err(-7);
    }
    let start = *p;
    while *p < s.len() && !matches!(s[*p], b',' | b')') {
        *p += 1;
    }
    let raw = &s[start..*p];
    if raw == b"NULL" {
        v.null = true;
    } else {
        if !scene_integer(raw) {
            return Err(-8);
        }
        for &b in raw {
            v.push(b);
        }
    }
    Ok(v)
}
fn scene_validate(row: &[SceneScalar; 9]) -> Result<(), isize> {
    for (column, max) in [(1, 20), (2, 255), (3, 50), (5, 255)] {
        if (row[column].null && column != 5) || (!row[column].null && row[column].len > max) {
            return Err(-9);
        }
    }
    for column in [0, 4, 6, 7, 8] {
        let v = &row[column];
        if v.null && matches!(column, 7 | 8) {
            continue;
        }
        if v.null || v.len > 11 || !scene_integer(v.value()) {
            return Err(-10);
        }
    }
    let id = row[0].value();
    if id.first().is_none_or(|b| !matches!(b, b'1'..=b'9'))
        || !id.iter().all(u8::is_ascii_digit)
        || id.iter().fold(0u64, |n, b| n * 10 + u64::from(b - b'0')) > 2147483647
    {
        return Err(-11);
    }
    Ok(())
}
fn scene_reason(row: &[SceneScalar; 9]) -> usize {
    let title = row[2].value();
    let Some(hyphen) = title.iter().rposition(|&b| b == b'-') else {
        return 2;
    };
    if hyphen == 0
        || hyphen + 1 == title.len()
        || !title[..hyphen]
            .iter()
            .all(|b| b.is_ascii_alphanumeric() || b"_.()&'+,-".contains(b))
        || !title[hyphen + 1..]
            .iter()
            .all(|b| b.is_ascii_alphanumeric() || *b == b'_')
    {
        return 2;
    }
    let group = row[3].value();
    if group.is_empty()
        || title.len() <= group.len()
        || title[title.len() - group.len() - 1] != b'-'
        || !title[title.len() - group.len()..].eq_ignore_ascii_case(group)
    {
        return 3;
    }
    1
}
fn scene_digest(prefix: &[u8], value: &[u8]) -> [u8; 32] {
    // This base exposes sha256_hex_slice, not sha256_bytes. Reuse its implementation
    // without changing the shared module; the conversion is bounded and allocation-free.
    let mut preimage = [0u8; 261];
    preimage[..prefix.len()].copy_from_slice(prefix);
    for (out, b) in preimage[prefix.len()..].iter_mut().zip(value) {
        *out = b.to_ascii_lowercase();
    }
    let hex = crate::predb::sha256_hex_slice(&preimage[..prefix.len() + value.len()]);
    let mut digest = [0u8; 32];
    for (i, pair) in hex.chunks_exact(2).enumerate() {
        fn nibble(b: u8) -> u8 {
            if b <= b'9' { b - b'0' } else { b - b'a' + 10 }
        }
        digest[i] = nibble(pair[0]) * 16 + nibble(pair[1]);
    }
    digest
}
fn scene_insert(s: &[u8], mode: u32, out: &mut [u8]) -> Result<usize, isize> {
    let prefix = b"INSERT INTO `predb` VALUES ";
    if !s.starts_with(prefix) {
        return Err(-1);
    }
    let mut p = prefix.len();
    let mut counts = [0u32; 6];
    let mut written: usize = if mode == 0 { 24 } else { 0 };
    loop {
        if s.get(p) != Some(&b'(') {
            return Err(-2);
        }
        p += 1;
        let mut row = [SceneScalar {
            bytes: [0; 255],
            len: 0,
            null: false,
        }; 9];
        let mut columns = 0;
        loop {
            let v = scene_scalar(s, &mut p)?;
            if columns < 9 {
                row[columns] = v;
            }
            columns += 1;
            let delimiter = s.get(p).copied();
            p += 1;
            if delimiter == Some(b',') {
                continue;
            }
            if delimiter != Some(b')') || columns != 9 {
                return Err(-3);
            }
            break;
        }
        scene_validate(&row)?;
        counts[0] += 1;
        let reason = scene_reason(&row);
        counts[reason] += 1;
        if reason >= 2 && counts[reason + 2] == 0 {
            counts[reason + 2] = counts[0];
        }
        if mode == 0 {
            if written + 64 <= out.len() {
                out[written..written + 32].copy_from_slice(&scene_digest(b"id\0", row[0].value()));
                out[written + 32..written + 64]
                    .copy_from_slice(&scene_digest(b"title\0", row[2].value()));
            }
            written += 64;
        } else if reason == 1 {
            let title = row[2].value();
            if written + title.len() + 1 <= out.len() {
                out[written..written + title.len()].copy_from_slice(title);
                out[written + title.len()] = 0;
            }
            written += title.len() + 1;
        }
        if s.get(p) == Some(&b',') {
            p += 1;
            continue;
        }
        if s.get(p) != Some(&b';') || p + 1 != s.len() {
            return Err(-4);
        }
        if written > out.len() {
            return Err(-100);
        }
        if mode == 0 {
            for (i, n) in counts.iter().enumerate() {
                out[i * 4..i * 4 + 4].copy_from_slice(&n.to_le_bytes());
            }
        }
        return Ok(written);
    }
}
/// # Safety
/// Input/output must be valid non-overlapping buffers of the declared lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_scene_sql_parse_insert(
    input: *const u8,
    len: usize,
    mode: u32,
    output: *mut u8,
    cap: usize,
) -> isize {
    if input.is_null() || output.is_null() || mode > 1 {
        return -101;
    }
    match scene_insert(unsafe { slice::from_raw_parts(input, len) }, mode, unsafe {
        slice::from_raw_parts_mut(output, cap)
    }) {
        Ok(n) => n as isize,
        Err(e) => e,
    }
}

#[cfg(test)]
mod html_validator_tests {
    use super::*;
    fn refs(s: &str, mode: u32) -> i32 {
        unsafe { nntmux_predb_html_validate_numeric_refs(s.as_ptr(), s.len(), mode) }
    }
    fn scope(s: &str, mode: u32) -> i32 {
        unsafe { nntmux_predb_html_validate_listing_scope(s.as_ptr(), s.len(), mode) }
    }
    #[test]
    fn html_numeric_reference_modes_and_overflow() {
        for s in [
            "plain",
            "&#9;&#10;&#13;&#32;",
            "&#x10ffff",
            "&#x;",
            "&#;",
            "&#00000000000000000000000065",
            "&#x000000000000000041",
        ] {
            assert_eq!(refs(s, 0), 1, "{s}");
        }
        for s in [
            "&#0",
            "&#8;",
            "&#11",
            "&#xD800",
            "&#1114112",
            "&#999999999999999999999999",
            "&#xFFFFFFFFFFFFFFFF",
        ] {
            assert_eq!(refs(s, 0), 0, "{s}");
        }
        assert_eq!(refs("&#65534;&#xFFFF", 0), 1);
        assert_eq!(refs("&#65534;&#xFFFF", 1), 0);
        assert_eq!(refs("&#128512;", 1), 1);
    }
    #[test]
    fn html_scope_structure_and_error_priority() {
        assert_eq!(
            scope("<html><body><div title='>'></div></body></html>\n", 0),
            1
        );
        assert_eq!(
            scope("<HTML><BODY><div-x></div ignored='>'></BODY></HTML >", 0),
            1
        );
        assert_eq!(scope("<html><body><div/></body></html>", 0), -2);
        assert_eq!(scope("<html><body><div></body>", 0), 0);
        assert_eq!(scope("<html><body><div></body></html>", 0), -2);
        assert_eq!(scope("<html><body><li></body></html>", 0), 1);
        assert_eq!(scope("<html><body><li></body></html>", 1), -2);
        assert_eq!(
            scope("<html><body></body></html><html><body></body></html>", 1),
            -2
        );
        assert_eq!(scope("<html><body></body></html bogus>", 0), 0);
        assert_eq!(
            scope(
                "<html><body><div>&#1114111;&#1114111</div></body></html</html>",
                0
            ),
            -1
        );
    }
    #[test]
    fn html_scope_stripping_and_fallback() {
        assert_eq!(
            scope(
                "<!--x--><html><body><script>x<div></script><style>bad</style></body></html><!--tail-->",
                0
            ),
            1
        );
        assert_eq!(scope("<html><body></body></html><!--unterminated", 0), 1);
        assert_eq!(scope("<html><body><script>unterminated", 0), 0);
        assert_eq!(scope("<ht<!--join-->ml><body></body></html>", 0), -1);
        assert_eq!(
            scope("<html><body><div x='<!--x-->'></div></body></html>", 0),
            -1
        );
        let deep = format!(
            "<html><body>{}{}</body></html>",
            "<div>".repeat(300),
            "</div>".repeat(300)
        );
        assert_eq!(scope(&deep, 0), -1);
    }
}

/// Validate numeric references before libxml can discard their characters.
/// # Safety
/// `input` must point to `len` readable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_html_validate_numeric_refs(
    input: *const u8,
    len: usize,
    mode: u32,
) -> i32 {
    if input.is_null() || mode > 1 {
        return -1;
    }
    let s = unsafe { slice::from_raw_parts(input, len) };
    let mut p = 0;
    while p + 2 < s.len() {
        let found = unsafe { crate::memchr(s[p..].as_ptr(), i32::from(b'&'), s.len() - p) };
        if found.is_null() {
            break;
        }
        p = unsafe { found.offset_from_unsigned(s.as_ptr()) };
        if s.get(p + 1) != Some(&b'#') {
            p += 1;
            continue;
        }
        p += 2;
        let base = if s.get(p).is_some_and(|b| b.eq_ignore_ascii_case(&b'x')) {
            p += 1;
            16
        } else {
            10
        };
        let start = p;
        let mut value = 0u32;
        while let Some(&b) = s.get(p) {
            let digit = match b {
                b'0'..=b'9' => u32::from(b - b'0'),
                b'a'..=b'f' if base == 16 => u32::from(b - b'a' + 10),
                b'A'..=b'F' if base == 16 => u32::from(b - b'A' + 10),
                _ => break,
            };
            value = value * base + digit;
            if value > 0x10ffff {
                return 0;
            }
            p += 1;
        }
        if p != start
            && ((value < 32 && !matches!(value, 9 | 10 | 13))
                || (0xd800..=0xdfff).contains(&value)
                || (mode == 1 && matches!(value, 0xfffe | 0xffff)))
        {
            return 0;
        }
    }
    1
}

fn html_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c)
}
fn html_word(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_'
}
fn html_prefix(s: &[u8], p: usize, name: &[u8]) -> bool {
    s.get(p..p + name.len())
        .is_some_and(|v| v.eq_ignore_ascii_case(name))
}
// Mirrors the stripping regex, including its quote-insensitive script/style opener.
fn html_removal(s: &[u8], p: usize) -> Option<usize> {
    if s[p..].starts_with(b"<!--") {
        return Some(
            s[p + 4..]
                .windows(3)
                .position(|v| v == b"-->")
                .map_or(s.len(), |i| p + 4 + i + 3),
        );
    }
    for name in [b"script".as_slice(), b"style".as_slice()] {
        let end = p + 1 + name.len();
        if !html_prefix(s, p + 1, name) || s.get(end).is_some_and(|b| html_word(*b)) {
            continue;
        }
        let Some(open_end) = s[end..].iter().position(|&b| b == b'>') else {
            continue;
        };
        let mut q = end + open_end + 1;
        while q < s.len() {
            if s[q] == b'<' && s.get(q + 1) == Some(&b'/') && html_prefix(s, q + 2, name) {
                let mut close = q + 2 + name.len();
                while s.get(close).is_some_and(|b| html_space(*b)) {
                    close += 1;
                }
                if s.get(close) == Some(&b'>') {
                    return Some(close + 1);
                }
            }
            q += 1;
        }
        return Some(s.len());
    }
    None
}

fn html_listing_scope(s: &[u8], mode: u32) -> i32 {
    let mut stack = [0u8; 256];
    let (mut depth, mut documents, mut bodies) = (0usize, 0usize, 0usize);
    let mut bad_scope = false;
    let mut trailing_html = false;
    let mut p = 0;
    while p < s.len() {
        if s[p] != b'<' {
            let found = unsafe { crate::memchr(s[p..].as_ptr(), i32::from(b'<'), s.len() - p) };
            let end = if found.is_null() {
                s.len()
            } else {
                unsafe { found.offset_from_unsigned(s.as_ptr()) }
            };
            if trailing_html && !s[p..end].iter().all(|b| html_space(*b)) {
                trailing_html = false;
            }
            p = end;
            continue;
        }
        if let Some(end) = html_removal(s, p) {
            // Removing bytes within partial tokens can synthesize a new regex match.
            // Such uncommon joins use PHP instead of allocating a cleaned document.
            if (p > 0 && s[p - 1] != b'>' && !html_space(s[p - 1]))
                || (end < s.len() && s[end] != b'<' && !html_space(s[end]))
            {
                return -1;
            }
            p = end;
            continue;
        }
        trailing_html = false;
        let closing = s.get(p + 1) == Some(&b'/');
        let start = p + 1 + usize::from(closing);
        let names: &[&[u8]] = if mode == 0 {
            &[b"html", b"body", b"div", b"h2", b"h5", b"a"]
        } else {
            &[
                b"html", b"body", b"ol", b"ul", b"li", b"div", b"h4", b"a", b"span",
            ]
        };
        let Some((id, name)) = names.iter().enumerate().find(|(_, name)| {
            html_prefix(s, start, name) && s.get(start + name.len()).is_none_or(|b| !html_word(*b))
        }) else {
            p += 1;
            continue;
        };
        let mut q = start + name.len();
        let mut quote = 0u8;
        let mut only_space = true;
        while q < s.len() {
            let b = s[q];
            // The trailing-document regex scans independently of matched tags.
            // A nested '<' can expose its suffix even when the tag regex consumes it.
            if b == b'<' {
                return -1;
            }
            if quote != 0 {
                if b == quote {
                    quote = 0;
                }
            } else if matches!(b, b'\'' | b'"') {
                quote = b;
            } else if b == b'>' {
                break;
            }
            only_space &= html_space(b);
            q += 1;
        }
        if q == s.len() {
            return -1;
        }
        trailing_html = closing && id == 0 && only_space;
        if closing {
            if depth == 0 {
                bad_scope = true;
            } else {
                depth -= 1;
                if stack[depth] != id as u8 {
                    bad_scope = true;
                }
            }
        } else {
            documents += usize::from(id == 0);
            bodies += usize::from(id == 1);
            if depth == stack.len() {
                return -1;
            }
            stack[depth] = id as u8;
            depth += 1;
        }
        p = q + 1;
    }
    if !trailing_html {
        0
    } else if bad_scope || depth != 0 || documents != 1 || bodies != 1 {
        -2
    } else {
        1
    }
}

/// Validate explicitly closed listing boundaries; -1 requests PHP fallback.
/// # Safety
/// `input` must point to `len` readable bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_html_validate_listing_scope(
    input: *const u8,
    len: usize,
    mode: u32,
) -> i32 {
    if input.is_null() || mode > 1 {
        return -1;
    }
    html_listing_scope(unsafe { slice::from_raw_parts(input, len) }, mode)
}
