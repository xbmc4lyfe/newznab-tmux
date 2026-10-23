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

// ---------------------------------------------------------------------------------------------
// XML catalogue and RSS documents (`CompleteXmlCatalogueSource`, `DatsPcIsoSource`, `RssFeedSource`)
// ---------------------------------------------------------------------------------------------
//
// A strict well-formed XML 1.0 subset reader that mirrors what libxml2 + SimpleXML expose to the
// PHP parsers: elements, attributes, character data, CDATA, comments and processing instructions,
// the five predefined entities and numeric character references, and `\r\n`/`\r` line-end
// normalisation. Anything outside that subset (DOCTYPE/ENTITY markup, non-UTF-8 encodings, a BOM,
// non-ASCII names, literal whitespace inside attribute values, unknown entities, excessive depth)
// and every malformed or schema-invalid document makes the export return `-1`, so PHP repeats
// the exact `simplexml_load_string` path and raises its own `RuntimeException`. Successful
// results are produced only when PHP would succeed with the same output.

use std::borrow::Cow;
use std::collections::HashSet;

const XML_MAX_DEPTH: usize = 200;
const XML_MAX_NAME: usize = 1000;
const XML_NS_URI: &[u8] = b"http://www.w3.org/XML/1998/namespace";
const XMLNS_NS_URI: &[u8] = b"http://www.w3.org/2000/xmlns/";

#[derive(Clone, Copy, PartialEq, Eq)]
enum XmlNsMode {
    /// Any namespace declaration or prefixed name is out of scope (the catalogue parsers reject
    /// documents with namespaces, or only ever see unprefixed names).
    Strict,
    /// RSS feeds commonly declare `content:`, `dc:`, `atom:` prefixes. SimpleXML property access
    /// without a namespace argument only matches unprefixed elements, so prefixed elements are
    /// tracked for well-formedness and otherwise ignored.
    Rss,
}

enum XmlEvent<'a> {
    Start(&'a [u8]),
    End,
    Text(Cow<'a, [u8]>),
    Eof,
}

type XmlResult<T> = Result<T, ()>;

/// Rejects inputs PHP guards or libxml treats specially before any structure is read: invalid
/// UTF-8, a BOM, C0 controls other than tab/LF/CR and the non-characters U+FFFE/U+FFFF.
/// `<!DOCTYPE`/`<!ENTITY` markup is rejected by the reader wherever raw `<!` can occur.
fn xml_prescan(s: &[u8]) -> bool {
    if std::str::from_utf8(s).is_err() || s.starts_with(&[0xEF, 0xBB, 0xBF]) {
        return false;
    }
    let mut base = 0;
    for chunk in s.chunks(64) {
        let mut control = false;
        let mut lead = false;
        for &b in chunk {
            control |= (b < 0x20) & (b != b'\t') & (b != b'\n') & (b != b'\r');
            lead |= b == 0xEF;
        }
        if control {
            return false;
        }
        if lead {
            for i in base..base + chunk.len() {
                if s[i] == 0xEF
                    && s.get(i + 1) == Some(&0xBF)
                    && matches!(s.get(i + 2), Some(0xBE | 0xBF))
                {
                    return false;
                }
            }
        }
        base += chunk.len();
    }
    true
}

/// Word-at-a-time `memchr` from `from`.
#[inline]
fn xml_memchr(needle: u8, s: &[u8], from: usize) -> Option<usize> {
    const LO: u64 = 0x0101_0101_0101_0101;
    const HI: u64 = 0x8080_8080_8080_8080;
    let repeated = LO.wrapping_mul(u64::from(needle));
    let mut i = from;
    while i + 8 <= s.len() {
        let mut word = [0u8; 8];
        word.copy_from_slice(&s[i..i + 8]);
        let x = u64::from_le_bytes(word) ^ repeated;
        if x.wrapping_sub(LO) & !x & HI != 0 {
            break;
        }
        i += 8;
    }
    s[i.min(s.len())..]
        .iter()
        .position(|&b| b == needle)
        .map(|p| p + i)
}

/// `<!DOCTYPE`/`<!ENTITY` (PHP's declaration guard) inside comment, CDATA or PI content.
fn xml_has_declaration(s: &[u8]) -> bool {
    let mut from = 0;
    while let Some(i) = xml_memchr(b'<', s, from) {
        if s.get(i + 1) == Some(&b'!') {
            let rest = &s[i + 2..];
            if starts_with_ci(rest, b"doctype") || starts_with_ci(rest, b"entity") {
                return true;
            }
        }
        from = i + 1;
    }
    false
}

#[inline]
fn starts_with_ci(s: &[u8], prefix: &[u8]) -> bool {
    s.len() >= prefix.len() && s[..prefix.len()].eq_ignore_ascii_case(prefix)
}

#[inline]
fn is_xml_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r')
}

#[inline]
fn is_name_start(b: u8) -> bool {
    b.is_ascii_alphabetic() || b == b'_' || b == b':'
}

#[inline]
fn is_name_char(b: u8) -> bool {
    b.is_ascii_alphanumeric() || matches!(b, b'_' | b':' | b'.' | b'-')
}

#[inline]
fn is_xml_char(cp: u32) -> bool {
    matches!(cp, 0x9 | 0xA | 0xD | 0x20..=0xD7FF | 0xE000..=0xFFFD | 0x10000..=0x10FFFF)
}

fn push_utf8(out: &mut Vec<u8>, cp: u32) {
    let mut buf = [0u8; 4];
    // is_xml_char excludes surrogates, so the conversion cannot fail.
    if let Some(c) = char::from_u32(cp) {
        out.extend_from_slice(c.encode_utf8(&mut buf).as_bytes());
    }
}

/// Decodes one reference at `s[i] == b'&'`; returns the next index.
fn xml_reference(s: &[u8], i: usize, out: &mut Vec<u8>) -> XmlResult<usize> {
    let mut j = i + 1;
    if s.get(j) == Some(&b'#') {
        j += 1;
        let hex = s.get(j) == Some(&b'x');
        if hex {
            j += 1;
        }
        let start = j;
        let mut cp: u32 = 0;
        while j < s.len() && s[j] != b';' {
            let d = if hex {
                (s[j] as char).to_digit(16)
            } else {
                (s[j] as char).to_digit(10)
            }
            .ok_or(())?;
            cp = cp.checked_mul(if hex { 16 } else { 10 }).ok_or(())?;
            cp = cp.checked_add(d).ok_or(())?;
            if cp > 0x10FFFF {
                return Err(());
            }
            j += 1;
        }
        if j == start || j >= s.len() || !is_xml_char(cp) {
            return Err(());
        }
        push_utf8(out, cp);
        return Ok(j + 1);
    }
    let end = s[j..].iter().position(|&b| b == b';').ok_or(())? + j;
    let replacement = match &s[j..end] {
        b"lt" => b'<',
        b"gt" => b'>',
        b"amp" => b'&',
        b"quot" => b'"',
        b"apos" => b'\'',
        _ => return Err(()),
    };
    out.push(replacement);
    Ok(end + 1)
}

/// Character data (`attr == false`) or an attribute value (`attr == true`).
fn xml_decode(raw: &[u8], attr: bool) -> XmlResult<Cow<'_, [u8]>> {
    if !attr {
        let mut special = false;
        for chunk in raw.chunks(64) {
            for &b in chunk {
                special |= (b == b'&') | (b == b'\r') | (b == b']');
            }
            if special {
                break;
            }
        }
        if !special {
            return Ok(Cow::Borrowed(raw));
        }
    }
    let mut decode = false;
    for (i, &b) in raw.iter().enumerate() {
        match b {
            b'&' => decode = true,
            b'\r' => {
                if attr {
                    return Err(());
                }
                decode = true;
            }
            b'\t' | b'\n' | b'<' if attr => return Err(()),
            b']' if !attr && raw[i..].starts_with(b"]]>") => return Err(()),
            _ => {}
        }
    }
    if !decode {
        return Ok(Cow::Borrowed(raw));
    }
    let mut out = Vec::with_capacity(raw.len());
    let mut i = 0;
    while i < raw.len() {
        match raw[i] {
            b'&' => i = xml_reference(raw, i, &mut out)?,
            b'\r' => {
                out.push(b'\n');
                i += if raw.get(i + 1) == Some(&b'\n') { 2 } else { 1 };
            }
            b => {
                out.push(b);
                i += 1;
            }
        }
    }
    Ok(Cow::Owned(out))
}

/// CDATA content only needs line-end normalisation.
fn xml_normalize_newlines(raw: &[u8]) -> Cow<'_, [u8]> {
    if !raw.contains(&b'\r') {
        return Cow::Borrowed(raw);
    }
    let mut out = Vec::with_capacity(raw.len());
    let mut i = 0;
    while i < raw.len() {
        if raw[i] == b'\r' {
            out.push(b'\n');
            i += if raw.get(i + 1) == Some(&b'\n') { 2 } else { 1 };
        } else {
            out.push(raw[i]);
            i += 1;
        }
    }
    Cow::Owned(out)
}

fn find(s: &[u8], mut from: usize, needle: &[u8]) -> Option<usize> {
    while let Some(i) = xml_memchr(needle[0], s, from) {
        if s[i..].starts_with(needle) {
            return Some(i);
        }
        from = i + 1;
    }
    None
}

struct XmlReader<'a> {
    s: &'a [u8],
    pos: usize,
    ns: XmlNsMode,
    stack: Vec<&'a [u8]>,
    ns_scope: Vec<(usize, &'a [u8])>,
    attrs: Vec<(&'a [u8], Cow<'a, [u8]>)>,
    pending_end: bool,
    seen_root: bool,
}

impl<'a> XmlReader<'a> {
    fn new(s: &'a [u8], ns: XmlNsMode) -> XmlResult<Self> {
        let mut reader = Self {
            s,
            pos: 0,
            ns,
            stack: Vec::with_capacity(16),
            ns_scope: Vec::new(),
            attrs: Vec::with_capacity(8),
            pending_end: false,
            seen_root: false,
        };
        if s.starts_with(b"<?xml") && s.len() > 5 && is_xml_space(s[5]) {
            reader.xml_declaration()?;
        }
        Ok(reader)
    }

    fn depth(&self) -> usize {
        self.stack.len()
    }

    fn skip_space(&mut self) -> bool {
        let start = self.pos;
        while self.pos < self.s.len() && is_xml_space(self.s[self.pos]) {
            self.pos += 1;
        }
        self.pos > start
    }

    fn expect(&mut self, token: &[u8]) -> XmlResult<()> {
        if self.s[self.pos..].starts_with(token) {
            self.pos += token.len();
            Ok(())
        } else {
            Err(())
        }
    }

    fn name(&mut self) -> XmlResult<&'a [u8]> {
        let start = self.pos;
        if self.pos >= self.s.len() || !is_name_start(self.s[self.pos]) {
            return Err(());
        }
        self.pos += 1;
        while self.pos < self.s.len() && is_name_char(self.s[self.pos]) {
            self.pos += 1;
        }
        if self.pos - start > XML_MAX_NAME {
            return Err(());
        }
        Ok(&self.s[start..self.pos])
    }

    fn quoted(&mut self) -> XmlResult<&'a [u8]> {
        let quote = *self.s.get(self.pos).ok_or(())?;
        if quote != b'"' && quote != b'\'' {
            return Err(());
        }
        let start = self.pos + 1;
        let end = self.s[start..].iter().position(|&b| b == quote).ok_or(())? + start;
        self.pos = end + 1;
        Ok(&self.s[start..end])
    }

    fn eq(&mut self) -> XmlResult<()> {
        self.skip_space();
        self.expect(b"=")?;
        self.skip_space();
        Ok(())
    }

    /// `<?xml version="1.0" (encoding="UTF-8")? (standalone="yes|no")? ?>`; anything else
    /// (other versions or encodings) is left to libxml.
    fn xml_declaration(&mut self) -> XmlResult<()> {
        self.pos = 5;
        self.skip_space();
        self.expect(b"version")?;
        self.eq()?;
        if self.quoted()? != b"1.0" {
            return Err(());
        }
        let mut stage = 0;
        loop {
            let spaced = self.skip_space();
            if self.s[self.pos..].starts_with(b"?>") {
                self.pos += 2;
                return Ok(());
            }
            if !spaced {
                return Err(());
            }
            if stage < 1 && self.s[self.pos..].starts_with(b"encoding") {
                self.pos += 8;
                self.eq()?;
                if !self.quoted()?.eq_ignore_ascii_case(b"utf-8") {
                    return Err(());
                }
                stage = 1;
            } else if stage < 2 && self.s[self.pos..].starts_with(b"standalone") {
                self.pos += 10;
                self.eq()?;
                if !matches!(self.quoted()?, b"yes" | b"no") {
                    return Err(());
                }
                stage = 2;
            } else {
                return Err(());
            }
        }
    }

    fn processing_instruction(&mut self) -> XmlResult<()> {
        self.pos += 2;
        let target = self.name()?;
        if target.contains(&b':') || target.eq_ignore_ascii_case(b"xml") {
            return Err(());
        }
        if self.s[self.pos..].starts_with(b"?>") {
            self.pos += 2;
            return Ok(());
        }
        if !self.skip_space() {
            return Err(());
        }
        let end = find(self.s, self.pos, b"?>").ok_or(())?;
        if xml_has_declaration(&self.s[self.pos..end]) {
            return Err(());
        }
        self.pos = end + 2;
        Ok(())
    }

    fn comment(&mut self) -> XmlResult<()> {
        let end = find(self.s, self.pos + 4, b"--").ok_or(())?;
        if self.s.get(end + 2) != Some(&b'>') || xml_has_declaration(&self.s[self.pos + 4..end]) {
            return Err(());
        }
        self.pos = end + 3;
        Ok(())
    }

    fn split_qname(&self, name: &'a [u8]) -> XmlResult<(Option<&'a [u8]>, &'a [u8])> {
        match name.iter().position(|&b| b == b':') {
            None => Ok((None, name)),
            Some(_) if self.ns == XmlNsMode::Strict => Err(()),
            Some(c) => {
                let (prefix, local) = (&name[..c], &name[c + 1..]);
                if prefix.is_empty() || local.is_empty() || local.contains(&b':') {
                    return Err(());
                }
                Ok((Some(prefix), local))
            }
        }
    }

    fn declared(&self, prefix: &[u8]) -> bool {
        self.ns_scope.iter().any(|&(_, p)| p == prefix)
    }

    fn start_tag(&mut self) -> XmlResult<XmlEvent<'a>> {
        if self.seen_root && self.stack.is_empty() {
            return Err(());
        }
        self.pos += 1;
        let name = self.name()?;
        self.attrs.clear();
        let empty;
        loop {
            let spaced = self.skip_space();
            match self.s.get(self.pos) {
                Some(b'>') => {
                    self.pos += 1;
                    empty = false;
                    break;
                }
                Some(b'/') => {
                    self.expect(b"/>")?;
                    empty = true;
                    break;
                }
                None => return Err(()),
                _ => {}
            }
            if !spaced {
                return Err(());
            }
            let attr = self.name()?;
            self.eq()?;
            let value = xml_decode(self.quoted()?, true)?;
            if self.attrs.iter().any(|(n, _)| *n == attr) {
                return Err(());
            }
            self.attrs.push((attr, value));
        }

        let depth = self.stack.len() + 1;
        if depth > XML_MAX_DEPTH {
            return Err(());
        }
        let (prefix, _) = self.split_qname(name)?;
        for index in 0..self.attrs.len() {
            let (attr, value) = (self.attrs[index].0, &self.attrs[index].1);
            if attr == b"xmlns" {
                if self.ns == XmlNsMode::Strict
                    || &**value == XML_NS_URI
                    || &**value == XMLNS_NS_URI
                {
                    return Err(());
                }
                continue;
            }
            let (attr_prefix, local) = self.split_qname(attr)?;
            if attr_prefix == Some(b"xmlns") {
                if local == b"xml"
                    || local == b"xmlns"
                    || value.is_empty()
                    || &**value == XML_NS_URI
                    || &**value == XMLNS_NS_URI
                {
                    return Err(());
                }
                self.ns_scope.push((depth, local));
            }
        }
        if let Some(p) = prefix
            && (p == b"xml" || p == b"xmlns" || !self.declared(p))
        {
            return Err(());
        }
        if self.ns == XmlNsMode::Rss {
            let mut locals: Vec<&[u8]> = Vec::with_capacity(self.attrs.len());
            for (attr, _) in &self.attrs {
                let (attr_prefix, local) = self.split_qname(attr)?;
                match attr_prefix {
                    Some(b"xmlns") => continue,
                    None if *attr == b"xmlns" => continue,
                    Some(b"xml") | None => {}
                    Some(p) if self.declared(p) => {}
                    Some(_) => return Err(()),
                }
                if locals.contains(&local) {
                    return Err(());
                }
                locals.push(local);
            }
        }
        self.stack.push(name);
        self.seen_root = true;
        self.pending_end = empty;
        Ok(XmlEvent::Start(name))
    }

    fn close(&mut self) {
        self.stack.pop();
        let depth = self.stack.len();
        while self.ns_scope.last().is_some_and(|&(d, _)| d > depth) {
            self.ns_scope.pop();
        }
    }

    fn next(&mut self) -> XmlResult<XmlEvent<'a>> {
        if self.pending_end {
            self.pending_end = false;
            self.close();
            return Ok(XmlEvent::End);
        }
        loop {
            if self.pos >= self.s.len() {
                return if self.seen_root && self.stack.is_empty() {
                    Ok(XmlEvent::Eof)
                } else {
                    Err(())
                };
            }
            if self.s[self.pos] != b'<' {
                let start = self.pos;
                let end = xml_memchr(b'<', self.s, start).unwrap_or(self.s.len());
                self.pos = end;
                let raw = &self.s[start..end];
                if self.stack.is_empty() {
                    if !raw.iter().all(|&b| is_xml_space(b)) {
                        return Err(());
                    }
                    continue;
                }
                return Ok(XmlEvent::Text(xml_decode(raw, false)?));
            }
            match self.s.get(self.pos + 1) {
                Some(b'/') => {
                    self.pos += 2;
                    let name = self.name()?;
                    self.skip_space();
                    self.expect(b">")?;
                    if self.stack.last() != Some(&name) {
                        return Err(());
                    }
                    self.close();
                    return Ok(XmlEvent::End);
                }
                Some(b'?') => self.processing_instruction()?,
                Some(b'!') => {
                    if self.s[self.pos..].starts_with(b"<!--") {
                        self.comment()?;
                    } else if self.s[self.pos..].starts_with(b"<![CDATA[") && !self.stack.is_empty()
                    {
                        let start = self.pos + 9;
                        let end = find(self.s, start, b"]]>").ok_or(())?;
                        if xml_has_declaration(&self.s[start..end]) {
                            return Err(());
                        }
                        self.pos = end + 3;
                        return Ok(XmlEvent::Text(xml_normalize_newlines(&self.s[start..end])));
                    } else {
                        return Err(());
                    }
                }
                _ => return self.start_tag(),
            }
        }
    }
}

/// Non-ASCII characters whose Unicode class (letters/punctuation, never `\s`, `\p{Z}` or
/// `\p{C}`) and full lowercase mapping are stable across the PCRE2/mbstring and Rust tables.
/// Anything else declines to PHP so `preg_match`/`mb_strtolower` keep deciding.
#[inline]
fn xml_literal_non_ascii(c: char) -> bool {
    matches!(c as u32,
        0x00A1..=0x00AC | 0x00AE..=0x017F | 0x2010..=0x2027 | 0x3041..=0x3096
        | 0x30A1..=0x30FA | 0x4E00..=0x9FA5 | 0xAC00..=0xD7A3)
}

/// Literal-title check shared by the catalogue parsers: `None` when a character needs PHP,
/// otherwise `(has \s/\p{Z}/\p{C} character, character count)`.
fn xml_classify(title: &[u8]) -> Option<(bool, usize)> {
    let text = std::str::from_utf8(title).ok()?;
    let mut nonliteral = false;
    let mut chars = 0;
    for c in text.chars() {
        chars += 1;
        if c.is_ascii() {
            if (c as u32) <= 0x20 || c as u32 == 0x7F {
                nonliteral = true;
            }
        } else if !xml_literal_non_ascii(c) {
            return None;
        }
    }
    Some((nonliteral, chars))
}

/// `mb_strtolower($s, 'UTF-8')` for strings accepted by [`xml_classify`]'s character set.
fn xml_lower(s: &[u8]) -> Option<Vec<u8>> {
    if s.is_ascii() {
        return Some(s.to_ascii_lowercase());
    }
    let text = std::str::from_utf8(s).ok()?;
    let mut out = String::with_capacity(s.len());
    for c in text.chars() {
        if c.is_ascii() {
            out.push(c.to_ascii_lowercase());
        } else if xml_literal_non_ascii(c) {
            out.extend(c.to_lowercase());
        } else {
            return None;
        }
    }
    Some(out.into_bytes())
}

fn xml_out_finish(out: OutCursor<'_>) -> isize {
    let n = out.finish();
    // A decline is always -1; a too-small buffer reports at least -2.
    if n == -1 { -2 } else { n }
}

// --- CompleteXmlCatalogueSource ------------------------------------------------------------

#[derive(Default)]
struct CatalogueRecord {
    ids: usize,
    id_nested: bool,
    id: Vec<u8>,
    names: usize,
    name_nested: bool,
    name: Vec<u8>,
}

const CATALOGUE_MISSING: &str = "missing_or_ambiguous_title";
const CATALOGUE_NONLITERAL: &str = "nonliteral_title";
const CATALOGUE_OVERLENGTH: &str = "overlength_title";

struct CatalogueResult {
    total: usize,
    normalized: usize,
    rejections: Vec<(&'static str, usize)>,
    titles: Vec<Vec<u8>>,
}

fn count_reason(rejections: &mut Vec<(&'static str, usize)>, reason: &'static str) {
    match rejections.iter_mut().find(|(r, _)| *r == reason) {
        Some(entry) => entry.1 += 1,
        None => rejections.push((reason, 1)),
    }
}

fn complete_catalogue(xml: &[u8], max_records: usize) -> XmlResult<CatalogueResult> {
    let mut reader = XmlReader::new(xml, XmlNsMode::Strict)?;
    let mut total = 0usize;
    let mut record = CatalogueRecord::default();
    // Field being captured at depth 3: 1 = first id, 2 = first releasename, 0 = other.
    let mut field = 0u8;
    let mut ids: HashSet<i64> = HashSet::new();
    let mut accepted: Vec<(i64, Vec<u8>, bool)> = Vec::new();
    let mut rejections: Vec<(&'static str, usize)> = Vec::new();
    loop {
        match reader.next()? {
            XmlEvent::Start(name) => match reader.depth() {
                1 if name != b"releases" => return Err(()),
                1 => {}
                2 => {
                    if name != b"release" {
                        return Err(());
                    }
                    total += 1;
                    if total > max_records {
                        return Err(());
                    }
                    record = CatalogueRecord::default();
                }
                3 => {
                    field = 0;
                    if name == b"id" {
                        record.ids += 1;
                        if record.ids == 1 {
                            field = 1;
                        }
                    } else if name == b"releasename" {
                        record.names += 1;
                        if record.names == 1 {
                            field = 2;
                        }
                    }
                }
                4 => match field {
                    1 => record.id_nested = true,
                    2 => record.name_nested = true,
                    _ => {}
                },
                _ => {}
            },
            XmlEvent::Text(text) => match reader.depth() {
                1 if !text.iter().all(|&b| is_xml_space(b)) => return Err(()),
                3 if field == 1 => record.id.extend_from_slice(&text),
                3 if field == 2 => record.name.extend_from_slice(&text),
                _ => {}
            },
            XmlEvent::End => match reader.depth() {
                2 => field = 0,
                1 => {
                    let rec = std::mem::take(&mut record);
                    if rec.ids != 1 || rec.id_nested {
                        return Err(());
                    }
                    let id = &rec.id;
                    if id.is_empty() || id[0] == b'0' || !id.iter().all(u8::is_ascii_digit) {
                        return Err(());
                    }
                    let id: i64 = std::str::from_utf8(id)
                        .map_err(|_| ())?
                        .parse()
                        .map_err(|_| ())?;
                    if !ids.insert(id) {
                        return Err(());
                    }
                    let title = php_trim(&rec.name);
                    let reason = if rec.names != 1 || rec.name_nested || title.is_empty() {
                        Some(CATALOGUE_MISSING)
                    } else {
                        let (nonliteral, chars) = xml_classify(title).ok_or(())?;
                        if nonliteral {
                            Some(CATALOGUE_NONLITERAL)
                        } else if chars > 255 {
                            Some(CATALOGUE_OVERLENGTH)
                        } else {
                            None
                        }
                    };
                    match reason {
                        Some(r) => count_reason(&mut rejections, r),
                        None => {
                            let normalized = title.len() != rec.name.len();
                            accepted.push((id, title.to_vec(), normalized));
                        }
                    }
                }
                _ => {}
            },
            XmlEvent::Eof => break,
        }
    }
    if total < 1 {
        return Err(());
    }
    accepted.sort_unstable_by_key(|a| std::cmp::Reverse(a.0));
    let mut seen: HashSet<Vec<u8>> = HashSet::with_capacity(accepted.len());
    let mut titles = Vec::with_capacity(accepted.len());
    let mut normalized = 0usize;
    let mut duplicates = 0usize;
    for (_, title, was_normalized) in accepted {
        if !seen.insert(xml_lower(&title).ok_or(())?) {
            duplicates += 1;
            continue;
        }
        normalized += usize::from(was_normalized);
        titles.push(title);
    }
    if duplicates > 0 {
        rejections.push(("duplicate_title", duplicates));
    }
    Ok(CatalogueResult {
        total,
        normalized,
        rejections,
        titles,
    })
}

/// Parses a `CompleteXmlCatalogueSource` export (`<releases><release><id/><releasename/>`).
///
/// Output on success: `K<total>\0<boundary normalized>\0<reason count>(\0<reason>\0<count>)*`
/// followed by `\0<title>` per accepted, deduplicated title in descending catalogue-ID order.
/// Rejection reasons appear in PHP's first-occurrence order with `duplicate_title` last.
///
/// Returns the output length, `-1` when PHP must parse the document itself (including every
/// invalid export), or `-(needed)` (at most `-2`) when `out_cap` is too small.
///
/// # Safety
/// `xml` must be readable for `len` bytes and `out_buf` writable for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_xml_complete_catalogue(
    xml: *const u8,
    len: usize,
    max_records: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if xml.is_null() || len == 0 {
        return -1;
    }
    let xml = unsafe { slice::from_raw_parts(xml, len) };
    if !xml_prescan(xml) {
        return -1;
    }
    let Ok(result) = complete_catalogue(xml, max_records) else {
        return -1;
    };
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    out.push(b'K');
    out.extend(result.total.to_string().as_bytes());
    out.push(0);
    out.extend(result.normalized.to_string().as_bytes());
    out.push(0);
    out.extend(result.rejections.len().to_string().as_bytes());
    for (reason, count) in &result.rejections {
        out.push(0);
        out.extend(reason.as_bytes());
        out.push(0);
        out.extend(count.to_string().as_bytes());
    }
    for title in &result.titles {
        out.push(0);
        out.extend(title);
    }
    xml_out_finish(out)
}

// --- DatsPcIsoSource -----------------------------------------------------------------------

struct XmlNode<'a> {
    name: &'a [u8],
    attrs: Vec<(&'a [u8], Vec<u8>)>,
    text: Vec<u8>,
    children: Vec<usize>,
}

impl XmlNode<'_> {
    fn attr(&self, name: &[u8]) -> Option<&[u8]> {
        self.attrs
            .iter()
            .find(|(n, _)| *n == name)
            .map(|(_, v)| v.as_slice())
    }

    fn has_attributes(&self, expected: &[&[u8]]) -> bool {
        self.attrs.len() == expected.len() && expected.iter().all(|e| self.attr(e).is_some())
    }
}

fn xml_tree<'a>(xml: &'a [u8], ns: XmlNsMode) -> XmlResult<Vec<XmlNode<'a>>> {
    let mut reader = XmlReader::new(xml, ns)?;
    let mut nodes: Vec<XmlNode<'a>> = Vec::new();
    let mut open: Vec<usize> = Vec::new();
    loop {
        match reader.next()? {
            XmlEvent::Start(name) => {
                let index = nodes.len();
                nodes.push(XmlNode {
                    name,
                    attrs: reader.attrs.iter().map(|(n, v)| (*n, v.to_vec())).collect(),
                    text: Vec::new(),
                    children: Vec::new(),
                });
                if let Some(&parent) = open.last() {
                    nodes[parent].children.push(index);
                }
                open.push(index);
            }
            XmlEvent::Text(text) => {
                if let Some(&current) = open.last() {
                    nodes[current].text.extend_from_slice(&text);
                }
            }
            XmlEvent::End => {
                open.pop();
            }
            XmlEvent::Eof => return Ok(nodes),
        }
    }
}

fn php_trim_empty(s: &[u8]) -> bool {
    php_trim(s).is_empty()
}

fn all_hex(s: &[u8], len: usize) -> bool {
    s.len() == len && s.iter().all(u8::is_ascii_hexdigit)
}

struct DatsResult {
    unsupported: usize,
    titles: Vec<Vec<u8>>,
}

fn dats_header(nodes: &[XmlNode<'_>], header: &XmlNode<'_>) -> XmlResult<()> {
    if !header.has_attributes(&[]) || !php_trim_empty(&header.text) {
        return Err(());
    }
    const FIELDS: [&[u8]; 6] = [
        b"name",
        b"description",
        b"category",
        b"version",
        b"date",
        b"clrmamepro",
    ];
    if header.children.len() != FIELDS.len() {
        return Err(());
    }
    for (&child, expected) in header.children.iter().zip(FIELDS) {
        let field = &nodes[child];
        let attrs: &[&[u8]] = if field.name == b"clrmamepro" {
            &[b"forcepacking"]
        } else {
            &[]
        };
        if field.name != expected || !field.children.is_empty() || !field.has_attributes(attrs) {
            return Err(());
        }
    }
    let text = |i: usize| nodes[header.children[i]].text.as_slice();
    let clrmamepro = &nodes[header.children[5]];
    if text(0) != b"PC iSO Games Scene 1998"
        || text(1) != text(0)
        || text(2) != b"Scene"
        || text(3).is_empty()
        || text(4).is_empty()
        || clrmamepro.attr(b"forcepacking") != Some(b"unzip")
        || !clrmamepro.text.is_empty()
    {
        return Err(());
    }
    Ok(())
}

fn dats_pciso(xml: &[u8], max_records: usize) -> XmlResult<DatsResult> {
    let nodes = xml_tree(xml, XmlNsMode::Strict)?;
    let root = nodes.first().ok_or(())?;
    if root.name != b"datafile" || !root.has_attributes(&[]) || !php_trim_empty(&root.text) {
        return Err(());
    }
    let named = |parent: &XmlNode<'_>, name: &[u8]| -> Vec<usize> {
        parent
            .children
            .iter()
            .copied()
            .filter(|&c| nodes[c].name == name)
            .collect()
    };
    let headers = named(root, b"header");
    let game_count = named(root, b"game").len();
    if headers.len() != 1 || game_count < 1 || game_count > max_records {
        return Err(());
    }
    dats_header(&nodes, &nodes[headers[0]])?;

    let mut games: HashSet<Vec<u8>> = HashSet::new();
    let mut titles: HashSet<Vec<u8>> = HashSet::new();
    let mut result = DatsResult {
        unsupported: 0,
        titles: Vec::new(),
    };
    for &child in &root.children {
        let game = &nodes[child];
        if game.name == b"header" {
            continue;
        }
        let descriptions = named(game, b"description");
        let roms = named(game, b"rom");
        if game.name != b"game"
            || !game.has_attributes(&[b"name"])
            || descriptions.len() != 1
            || !nodes[descriptions[0]].children.is_empty()
            || !nodes[descriptions[0]].has_attributes(&[])
            || roms.is_empty()
            || !php_trim_empty(&game.text)
        {
            return Err(());
        }
        let nuked = named(game, b"nukedreason");
        if nuked.len() > 1
            || nuked.first().is_some_and(|&n| {
                !nodes[n].children.is_empty()
                    || !nodes[n].has_attributes(&[])
                    || nodes[n].text.is_empty()
            })
        {
            return Err(());
        }
        let game_name = game.attr(b"name").unwrap_or_default();
        if game_name.is_empty() || nodes[descriptions[0]].text != game_name {
            return Err(());
        }
        if !games.insert(xml_lower(game_name).ok_or(())?) {
            return Err(());
        }
        let mut rom_names: HashSet<Vec<u8>> = HashSet::new();
        let mut mia: Vec<&[u8]> = Vec::new();
        for &r in &game.children {
            let rom = &nodes[r];
            if rom.name == b"description" || rom.name == b"nukedreason" {
                continue;
            }
            let name = rom.attr(b"name").unwrap_or_default();
            if rom.name != b"rom"
                || !rom.children.is_empty()
                || !rom.text.is_empty()
                || name.is_empty()
            {
                return Err(());
            }
            // `\p{C}` alone: spaces are allowed in archive member names.
            let text = std::str::from_utf8(name).map_err(|_| ())?;
            let mut chars = 0;
            for c in text.chars() {
                chars += 1;
                if c.is_ascii() {
                    if (c as u32) < 0x20 || c as u32 == 0x7F {
                        return Err(());
                    }
                } else if !xml_literal_non_ascii(c) {
                    return Err(());
                }
            }
            if chars > 255 || !rom_names.insert(xml_lower(name).ok_or(())?) {
                return Err(());
            }
            if rom.attr(b"mia").is_some() {
                if !rom.has_attributes(&[b"name", b"mia"]) || rom.attr(b"mia") != Some(b"yes") {
                    return Err(());
                }
                mia.push(name);
            } else {
                let size = rom.attr(b"size").unwrap_or_default();
                if !rom.has_attributes(&[b"name", b"size", b"crc", b"md5", b"sha1"])
                    || size.is_empty()
                    || !size.iter().all(u8::is_ascii_digit)
                    || (size.len() > 1 && size[0] == b'0')
                    || !all_hex(rom.attr(b"crc").unwrap_or_default(), 8)
                    || !all_hex(rom.attr(b"md5").unwrap_or_default(), 32)
                    || !all_hex(rom.attr(b"sha1").unwrap_or_default(), 40)
                {
                    return Err(());
                }
            }
        }
        if mia.is_empty() {
            result.unsupported += 1;
            continue;
        }
        if mia.len() != 1 || roms.len() != 1 {
            return Err(());
        }
        let title = mia[0];
        let (nonliteral, _) = xml_classify(title).ok_or(())?;
        let mut suffix = Vec::with_capacity(title.len() + 1);
        suffix.push(b'-');
        suffix.extend_from_slice(title);
        if nonliteral
            || title.contains(&b'/')
            || title.contains(&b'\\')
            || !game_name.ends_with(&suffix)
            || !titles.insert(xml_lower(title).ok_or(())?)
        {
            return Err(());
        }
        result.titles.push(title.to_vec());
    }
    Ok(result)
}

/// Parses the DATS.SITE PC ISO 1998 export with `DatsPcIsoSource` semantics.
///
/// Output on success: `K<archive-file games>` followed by `\0<title>` per accepted MIA title in
/// document order. Returns `-1` when PHP must parse (including every invalid export) or
/// `-(needed)` (at most `-2`) when `out_cap` is too small.
///
/// # Safety
/// `xml` must be readable for `len` bytes and `out_buf` writable for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_xml_dats_pciso(
    xml: *const u8,
    len: usize,
    max_records: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if xml.is_null() || len == 0 {
        return -1;
    }
    let xml = unsafe { slice::from_raw_parts(xml, len) };
    if !xml_prescan(xml) {
        return -1;
    }
    let Ok(result) = dats_pciso(xml, max_records) else {
        return -1;
    };
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    out.push(b'K');
    out.extend(result.unsupported.to_string().as_bytes());
    for title in &result.titles {
        out.push(0);
        out.extend(title);
    }
    xml_out_finish(out)
}

// --- RssFeedSource -------------------------------------------------------------------------

const RSS_MAXIMUM_ITEMS: usize = 5000;
const RSS_SPACED_TITLES: u32 = 1;
const RSS_STRIP_SIZE_SUFFIX: u32 = 2;
const RSS_MAP_CATEGORY: u32 = 4;

/// `preg_replace('/ \x{2013} [0-9]+(?:\.[0-9]+)? (?:MB|GB)$/u', '', $title)` on a trimmed title.
fn rss_strip_size_suffix(t: &[u8]) -> &[u8] {
    if !(t.ends_with(b" MB") || t.ends_with(b" GB")) {
        return t;
    }
    let end = t.len() - 3;
    let mut start = end;
    while start > 0 && (t[start - 1].is_ascii_digit() || t[start - 1] == b'.') {
        start -= 1;
    }
    let number = &t[start..end];
    let valid = match number.iter().position(|&b| b == b'.') {
        None => !number.is_empty(),
        Some(dot) => {
            dot > 0 && dot + 1 < number.len() && number[dot + 1..].iter().all(u8::is_ascii_digit)
        }
    };
    const DASH: &[u8] = " \u{2013} ".as_bytes();
    if valid && t[..start].ends_with(DASH) {
        &t[..start - DASH.len()]
    } else {
        t
    }
}

/// `RssFeedSource::dotted()`.
fn rss_dotted(title: &[u8]) -> Vec<u8> {
    let mut t = php_trim(title);
    if t.len() >= 4 && t[t.len() - 4..].eq_ignore_ascii_case(b"eztv") {
        let mut k = t.len() - 4;
        while k > 0 && is_ws(t[k - 1]) {
            k -= 1;
        }
        if k < t.len() - 4 {
            t = &t[..k];
        }
    }
    let mut out = Vec::with_capacity(t.len());
    let mut i = 0;
    while i < t.len() {
        if is_ws(t[i]) {
            while i < t.len() && is_ws(t[i]) {
                i += 1;
            }
            out.push(b'.');
        } else {
            out.push(t[i]);
            i += 1;
        }
    }
    out
}

fn days_from_civil(y: i64, m: i64, d: i64) -> i64 {
    let y = if m <= 2 { y - 1 } else { y };
    let era = y.div_euclid(400);
    let yoe = y - era * 400;
    let doy = (153 * (m + if m > 2 { -3 } else { 9 }) + 2) / 5 + d - 1;
    let doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    era * 146_097 + doe - 719_468
}

fn civil_from_days(z: i64) -> (i64, i64, i64) {
    let z = z + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146_096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    (yoe + era * 400 + i64::from(m <= 2), m, d)
}

fn two_digits(s: &[u8]) -> Option<i64> {
    match s {
        [a, b] if a.is_ascii_digit() && b.is_ascii_digit() => {
            Some(i64::from((a - b'0') * 10 + (b - b'0')))
        }
        _ => None,
    }
}

/// Exact RFC 2822 `pubDate` (`Sun, 11 Oct 2026 14:56:46 +0000` or `GMT`) with a weekday that
/// matches the date, so `CarbonImmutable::parse($value)->utc()` equals the UTC wall time.
/// Writes `\x01Y-m-d H:i:s` (UTC); anything else (other formats, PHP's weekday/overflow
/// adjustments, zone-less dates) is left for PHP to parse.
fn rss_fast_date(raw: &[u8], out: &mut Vec<u8>) -> bool {
    const DAYS: [&[u8]; 7] = [b"Sun", b"Mon", b"Tue", b"Wed", b"Thu", b"Fri", b"Sat"];
    const MONTHS: [&[u8]; 12] = [
        b"Jan", b"Feb", b"Mar", b"Apr", b"May", b"Jun", b"Jul", b"Aug", b"Sep", b"Oct", b"Nov",
        b"Dec",
    ];
    let parse = || -> Option<i64> {
        let weekday = DAYS.iter().position(|d| raw.get(..3) == Some(*d))? as i64;
        if raw.get(3..5)? != b", " {
            return None;
        }
        let day_len = if raw.get(6)? == &b' ' { 1 } else { 2 };
        let day_digits = raw.get(5..5 + day_len)?;
        if !day_digits.iter().all(u8::is_ascii_digit) || day_digits[0] == b'0' && day_len == 1 {
            return None;
        }
        let day = std::str::from_utf8(day_digits).ok()?.parse::<i64>().ok()?;
        let rest = raw.get(5 + day_len..)?;
        // " Mmm YYYY HH:MM:SS " then the zone.
        if rest.len() < 22
            || rest[0] != b' '
            || rest[4] != b' '
            || rest[9] != b' '
            || rest[12] != b':'
            || rest[15] != b':'
            || rest[18] != b' '
        {
            return None;
        }
        let month = MONTHS.iter().position(|m| &rest[1..4] == *m)? as i64 + 1;
        let year_digits = &rest[5..9];
        if !year_digits.iter().all(u8::is_ascii_digit) {
            return None;
        }
        let year = std::str::from_utf8(year_digits).ok()?.parse::<i64>().ok()?;
        let (hour, minute, second) = (
            two_digits(&rest[10..12])?,
            two_digits(&rest[13..15])?,
            two_digits(&rest[16..18])?,
        );
        let offset = match &rest[19..] {
            b"GMT" => 0,
            [sign @ (b'+' | b'-'), zone @ ..] if zone.len() == 4 => {
                let (oh, om) = (two_digits(&zone[..2])?, two_digits(&zone[2..])?);
                if oh > 14 || om > 59 {
                    return None;
                }
                (oh * 3600 + om * 60) * if *sign == b'-' { -1 } else { 1 }
            }
            _ => return None,
        };
        let leap = year % 4 == 0 && (year % 100 != 0 || year % 400 == 0);
        let month_days = [
            31,
            if leap { 29 } else { 28 },
            31,
            30,
            31,
            30,
            31,
            31,
            30,
            31,
            30,
            31,
        ];
        if !(1971..=9998).contains(&year)
            || day < 1
            || day > month_days[month as usize - 1]
            || hour > 23
            || minute > 59
            || second > 59
        {
            return None;
        }
        let days = days_from_civil(year, month, day);
        if (days + 4).rem_euclid(7) != weekday {
            return None;
        }
        Some(days * 86_400 + hour * 3600 + minute * 60 + second - offset)
    };
    let Some(timestamp) = parse() else {
        return false;
    };
    let (y, m, d) = civil_from_days(timestamp.div_euclid(86_400));
    let secs = timestamp.rem_euclid(86_400);
    out.push(0x01);
    out.extend_from_slice(
        format!(
            "{y:04}-{m:02}-{d:02} {:02}:{:02}:{:02}",
            secs / 3600,
            secs % 3600 / 60,
            secs % 60
        )
        .as_bytes(),
    );
    true
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum RssRole {
    Other,
    Root,
    Channel,
    Item,
    Title,
    Category,
    PubDate,
}

#[derive(Default)]
struct RssItem {
    title: Option<Vec<u8>>,
    category: Option<Vec<u8>>,
    pub_date: Option<Vec<u8>>,
}

fn rss_feed(xml: &[u8], flags: u32, page_size: usize, out: &mut Vec<u8>) -> XmlResult<()> {
    let mut reader = XmlReader::new(xml, XmlNsMode::Rss)?;
    let mut roles: Vec<RssRole> = Vec::with_capacity(16);
    let mut channel_seen = false;
    let mut items = 0usize;
    let mut emitted = 0usize;
    let mut item = RssItem::default();
    let mut capture: Vec<u8> = Vec::new();
    loop {
        match reader.next()? {
            XmlEvent::Start(name) => {
                let unprefixed = !name.contains(&b':');
                let parent = roles.last().copied();
                let role = match parent {
                    None if unprefixed && name.eq_ignore_ascii_case(b"rss") => RssRole::Root,
                    None => return Err(()),
                    Some(RssRole::Root) if unprefixed && name == b"channel" && !channel_seen => {
                        channel_seen = true;
                        RssRole::Channel
                    }
                    Some(RssRole::Channel) if unprefixed && name == b"item" => {
                        items += 1;
                        if items > RSS_MAXIMUM_ITEMS {
                            return Err(());
                        }
                        item = RssItem::default();
                        RssRole::Item
                    }
                    Some(RssRole::Item) if unprefixed && emitted < page_size => match name {
                        b"title" if item.title.is_none() => RssRole::Title,
                        b"category" if item.category.is_none() && flags & RSS_MAP_CATEGORY != 0 => {
                            RssRole::Category
                        }
                        b"pubDate" if item.pub_date.is_none() => RssRole::PubDate,
                        _ => RssRole::Other,
                    },
                    _ => RssRole::Other,
                };
                if matches!(role, RssRole::Title | RssRole::Category | RssRole::PubDate) {
                    capture.clear();
                }
                roles.push(role);
            }
            XmlEvent::Text(text) => {
                if matches!(
                    roles.last(),
                    Some(RssRole::Title | RssRole::Category | RssRole::PubDate)
                ) {
                    capture.extend_from_slice(&text);
                }
            }
            XmlEvent::End => match roles.pop() {
                Some(RssRole::Title) => item.title = Some(std::mem::take(&mut capture)),
                Some(RssRole::Category) => item.category = Some(std::mem::take(&mut capture)),
                Some(RssRole::PubDate) => item.pub_date = Some(std::mem::take(&mut capture)),
                Some(RssRole::Item) if emitted < page_size => {
                    let raw = item.title.take().unwrap_or_default();
                    let mut title = php_trim(&raw);
                    if flags & RSS_STRIP_SIZE_SUFFIX != 0 {
                        title = rss_strip_size_suffix(title);
                    }
                    let dotted;
                    if flags & RSS_SPACED_TITLES != 0 {
                        dotted = rss_dotted(title);
                        title = &dotted;
                    }
                    if title.is_empty() || title.contains(&b' ') {
                        continue;
                    }
                    out.extend_from_slice(title);
                    out.push(0);
                    if let Some(category) = &item.category {
                        out.extend_from_slice(php_trim(category));
                    }
                    out.push(0);
                    if let Some(date) = &item.pub_date
                        && !rss_fast_date(date, out)
                    {
                        out.extend_from_slice(date);
                    }
                    out.push(0);
                    emitted += 1;
                }
                _ => {}
            },
            XmlEvent::Eof => break,
        }
    }
    if !channel_seen {
        return Err(());
    }
    Ok(())
}

/// Parses an RSS 2.0 document with `RssFeedSource::parseXml` semantics up to the PHP-side date
/// handling. `flags`: 1 spaced titles, 2 strip display size suffix, 4 map category.
///
/// Output on success: `K` followed by `<title>\0<trimmed category>\0<pubDate>\0` for each of
/// the first `page_size` accepted items of the first channel (empty strings when absent). The
/// date is the raw `pubDate` text, or `\x01Y-m-d H:i:s` in UTC for exact RFC 2822 dates. Returns
/// `-1` when PHP must parse (including every invalid document or the item limit) or `-(needed)`
/// (at most `-2`) when `out_cap` is too small.
///
/// # Safety
/// `xml` must be readable for `len` bytes and `out_buf` writable for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_xml_rss(
    xml: *const u8,
    len: usize,
    flags: u32,
    page_size: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if xml.is_null() || len == 0 || flags > 7 {
        return -1;
    }
    let mut xml = unsafe { slice::from_raw_parts(xml, len) };
    // preg_replace('/\A[ \t\r\n]+(?=<\?xml[ \t\r\n])/', '', $xml)
    let lead = xml.iter().take_while(|&&b| is_xml_space(b)).count();
    if lead > 0
        && xml[lead..].starts_with(b"<?xml")
        && xml.get(lead + 5).is_some_and(|&b| is_xml_space(b))
    {
        xml = &xml[lead..];
    }
    if !xml_prescan(xml) {
        return -1;
    }
    let mut body = Vec::new();
    if rss_feed(xml, flags, page_size, &mut body).is_err() {
        return -1;
    }
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    out.push(b'K');
    out.extend(&body);
    xml_out_finish(out)
}

#[cfg(test)]
mod xml_tests {
    use super::*;

    fn call(
        f: unsafe extern "C" fn(*const u8, usize, usize, *mut u8, usize) -> isize,
        xml: &str,
        max: usize,
    ) -> Option<Vec<u8>> {
        let mut buf = vec![0u8; xml.len() + 256];
        let n = unsafe { f(xml.as_ptr(), xml.len(), max, buf.as_mut_ptr(), buf.len()) };
        assert!(n == -1 || n > 0, "unexpected status {n}");
        (n > 0).then(|| buf[..n as usize].to_vec())
    }

    fn rss(xml: &str, flags: u32, page: usize) -> Option<Vec<u8>> {
        let mut buf = vec![0u8; xml.len() + 256];
        let n = unsafe {
            nntmux_predb_xml_rss(
                xml.as_ptr(),
                xml.len(),
                flags,
                page,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        (n > 0).then(|| buf[..n as usize].to_vec())
    }

    fn record(id: &str, title: &str) -> String {
        format!("<release><id>{id}</id><releasename>{title}</releasename></release>")
    }

    #[test]
    fn complete_catalogue_sorts_dedupes_and_counts_in_php_order() {
        let xml = format!(
            "<?xml version='1.0' encoding='utf-8'?>\r\n<releases><!-- c -->{}{}{}{}{}</releases>\n",
            record("1", "exact.title-grp"),
            record("9", " Exact.Title-GRP\t"),
            record("3", "Bad Title"),
            record("4", "L&#233;on-GRP"),
            record("5", ""),
        );
        let out = call(nntmux_predb_xml_complete_catalogue, &xml, 5000).unwrap();
        assert_eq!(
            out,
            "K5\x001\x003\x00nonliteral_title\x001\x00missing_or_ambiguous_title\x001\x00duplicate_title\x001\x00Exact.Title-GRP\x00Léon-GRP".as_bytes()
        );
    }

    #[test]
    fn complete_catalogue_declines_invalid_or_out_of_subset_documents() {
        let rec = record("1", "A-GRP");
        for xml in [
            format!("<releases>{rec}"),
            format!("<releases>{rec}{rec}</releases>"),
            format!("<releases xmlns=\"u\">{rec}</releases>"),
            format!("<!DOCTYPE releases><releases>{rec}</releases>"),
            format!("<?xml version=\"1.0\" encoding=\"latin1\"?><releases>{rec}</releases>"),
            format!("<releases>{}</releases>", record("01", "A")),
            format!("<releases>{}</releases>", record("1", "&nbsp;")),
            format!("<releases>{}</releases>", record("1", "Ω-GRP")),
            format!("<releases>{}</releases>", record("1", "A]]>B")),
            format!("<releases>{rec}<other/></releases>"),
            "<releases/>".to_string(),
        ] {
            assert_eq!(
                call(nntmux_predb_xml_complete_catalogue, &xml, 5000),
                None,
                "{xml}"
            );
        }
        let two = format!("<releases>{rec}{}</releases>", record("2", "B"));
        assert_eq!(call(nntmux_predb_xml_complete_catalogue, &two, 1), None);
    }

    #[test]
    fn small_buffers_report_the_needed_size() {
        let xml = format!("<releases>{}</releases>", record("1", "A-GRP"));
        let mut buf = [0u8; 2];
        let n = unsafe {
            nntmux_predb_xml_complete_catalogue(
                xml.as_ptr(),
                xml.len(),
                10,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(n <= -2);
        assert_eq!(-n as usize, "K1\x000\x000\x00A-GRP".len());
    }

    #[test]
    fn dats_accepts_only_independent_mia_titles() {
        let header = "<header><name>PC iSO Games Scene 1998</name><description>PC iSO Games Scene 1998</description><category>Scene</category><version>1</version><date>2026</date><clrmamepro forcepacking=\"unzip\"/></header>";
        let mia = "<game name=\"1998-01-01-A.Game-GRP\"><description>1998-01-01-A.Game-GRP</description><rom name=\"A.Game-GRP\" mia=\"yes\"/></game>";
        let file = format!(
            "<game name=\"1998-01-02-B-GRP\"><description>1998-01-02-B-GRP</description><rom name=\"b file.nfo\" size=\"10\" crc=\"2a869538\" md5=\"{}\" sha1=\"{}\"/></game>",
            "a".repeat(32),
            "B".repeat(40)
        );
        let xml = format!("<datafile>{header}{mia}{file}</datafile>");
        assert_eq!(
            call(nntmux_predb_xml_dats_pciso, &xml, 5000).unwrap(),
            b"K1\x00A.Game-GRP"
        );
        assert_eq!(call(nntmux_predb_xml_dats_pciso, &xml, 1), None);
        assert_eq!(
            call(
                nntmux_predb_xml_dats_pciso,
                &xml.replace("mia=\"yes\"", "mia=\"no\""),
                5000
            ),
            None
        );
        assert_eq!(
            call(
                nntmux_predb_xml_dats_pciso,
                &xml.replace("2a869538", "zz869538"),
                5000
            ),
            None
        );
    }

    #[test]
    fn rss_reads_unprefixed_fields_of_the_first_channel() {
        let xml = "\n <?xml version=\"1.0\"?><rss xmlns:dc=\"http://purl.org/dc/elements/1.1/\"><channel><item><dc:title>No</dc:title><title> A.B-GRP </title><category> Cat </category><pubDate>Sun, 11 Oct 2026 14:56:46 +0000</pubDate></item><item><title>has space</title></item></channel><channel><item><title>Second-GRP</title></item></channel></rss>";
        assert_eq!(
            rss(xml, 4, 100).unwrap(),
            b"KA.B-GRP\x00Cat\x00\x012026-10-11 14:56:46\x00"
        );
        assert_eq!(
            rss(xml, 0, 100).unwrap(),
            b"KA.B-GRP\x00\x00\x012026-10-11 14:56:46\x00"
        );
        assert_eq!(
            rss(
                "<rss><channel><item><x:title>A</x:title></item></channel></rss>",
                0,
                100
            ),
            None
        );
        assert_eq!(rss("<rss/>", 0, 100), None);
        assert_eq!(rss("<rss><channel/></rss>", 0, 100).unwrap(), b"K");
    }

    #[test]
    fn rss_fast_dates_convert_only_exact_rfc2822_values() {
        let fast = |raw: &str| {
            let mut out = Vec::new();
            rss_fast_date(raw.as_bytes(), &mut out).then(|| String::from_utf8(out).unwrap())
        };
        assert_eq!(
            fast("Sun, 11 Oct 2026 23:30:00 -0130").as_deref(),
            Some("\x012026-10-12 01:00:00")
        );
        assert_eq!(
            fast("Thu, 1 Jan 2026 00:10:00 +0100").as_deref(),
            Some("\x012025-12-31 23:10:00")
        );
        assert_eq!(
            fast("Tue, 29 Feb 2028 12:00:00 GMT").as_deref(),
            Some("\x012028-02-29 12:00:00")
        );
        for raw in [
            "Mon, 11 Oct 2026 14:56:46 +0000",
            "Sat, 31 Feb 2026 10:00:00 +0000",
            "Sun, 11 Oct 2026 14:56:60 +0000",
            "Sun, 11 Oct 2026 14:56:46 UTC",
            "Sun, 11 Oct 2026 14:56:46 +1500",
            "Sun, 11 oct 2026 14:56:46 +0000",
            " Sun, 11 Oct 2026 14:56:46 +0000",
            "2026-10-11 04:14:20",
        ] {
            assert_eq!(fast(raw), None, "{raw}");
        }
    }

    #[test]
    fn rss_title_rewrites_match_php_patterns() {
        assert_eq!(
            rss_strip_size_suffix("A-GRP \u{2013} 3.1 GB".as_bytes()),
            b"A-GRP"
        );
        assert_eq!(
            rss_strip_size_suffix("A-GRP \u{2013} 3. GB".as_bytes()),
            "A-GRP \u{2013} 3. GB".as_bytes()
        );
        assert_eq!(
            rss_strip_size_suffix("A \u{2013} 1.2.3 MB".as_bytes()),
            "A \u{2013} 1.2.3 MB".as_bytes()
        );
        assert_eq!(
            rss_dotted(b" Show S01E01 720p\t\tWEB-GRP eztv "),
            b"Show.S01E01.720p.WEB-GRP"
        );
        assert_eq!(rss_dotted(b"EZTV"), b"EZTV");
    }
}
