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
//! short announce in under a microsecond, so end-to-end `parse()` measured only 1.0-1.2x. The JSON
//! feed sources (predataba.se, xREL, srrDB, predb.ovh/club/net/org, apibay) decode their response
//! bodies through `nntmux_predb_json_feed_parse` instead of `json_decode` plus per-row PHP.
//! `Nima4kSource::parseHtml` takes `nntmux_predb_nima4k_parse_html` for strict, unambiguous listings
//! (40-70x on 25/100-card pages) and keeps its dual-DOM PHP parser for everything else.
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

// ---------------------------------------------------------------------------------------------
// JSON PreDB feed sources (predataba.se, xREL, srrDB, predb.ovh/club/net/org, apibay)
// ---------------------------------------------------------------------------------------------
//
// One strict validating pass reproduces `json_decode($body, true)` acceptance (RFC 8259 grammar,
// UTF-8, escapes, depth); extraction then walks the validated bytes without re-validating.
// Object lookups keep the last duplicate member, as PHP arrays do. Every value whose PHP
// conversion is not reproduced exactly (numeric strings other than plain integers, array-to-string
// casts, out-of-range floats, error envelopes, object-shaped lists) declines the whole document.

const JSON_PREDATABASE: u32 = 1;
const JSON_XREL: u32 = 2;
const JSON_XREL_P2P: u32 = 3;
const JSON_SRRDB: u32 = 4;
const JSON_PREDB_OVH: u32 = 5;
const JSON_PREDB_CLUB: u32 = 6;
const JSON_PREDB_NET: u32 = 7;
const JSON_PREDB_ORG: u32 = 8;
const JSON_APIBAY: u32 = 9;

const REC_SEP: u8 = 0x1e;
/// Separates header fields and precedes every record field.
const FIELD_SEP: u8 = 0x1f;

const PRE_NONUKE: u8 = 0;
const PRE_UNNUKED: u8 = 1;
const PRE_NUKED: u8 = 2;
const PRE_MODNUKE: u8 = 3;
const PRE_RENUKED: u8 = 4;
const PRE_OLDNUKE: u8 = 5;

#[inline]
fn json_ws(s: &[u8], mut p: usize) -> usize {
    while p < s.len() && matches!(s[p], b' ' | b'\t' | b'\r' | b'\n') {
        p += 1;
    }
    p
}

/// First index at or after `p` holding `"`, `\\` or a control byte (eight bytes per step).
#[inline]
fn json_string_stop(s: &[u8], mut p: usize) -> usize {
    const LO: u64 = 0x0101_0101_0101_0101;
    const HI: u64 = 0x8080_8080_8080_8080;
    while p + 8 <= s.len() {
        let x = u64::from_le_bytes(s[p..p + 8].try_into().unwrap());
        let quote = x ^ (LO * u64::from(b'"'));
        let slash = x ^ (LO * u64::from(b'\\'));
        // The lowest flagged byte of each zero/less-than test is exact.
        let hit = ((quote.wrapping_sub(LO) & !quote)
            | (slash.wrapping_sub(LO) & !slash)
            | (x.wrapping_sub(LO * 0x20) & !x))
            & HI;
        if hit != 0 {
            return p + (hit.trailing_zeros() / 8) as usize;
        }
        p += 8;
    }
    while p < s.len() && !matches!(s[p], b'"' | b'\\' | 0..=0x1f) {
        p += 1;
    }
    p
}

#[inline]
fn hex_digit(b: u8) -> Option<u32> {
    match b {
        b'0'..=b'9' => Some(u32::from(b - b'0')),
        b'a'..=b'f' => Some(u32::from(b - b'a' + 10)),
        b'A'..=b'F' => Some(u32::from(b - b'A' + 10)),
        _ => None,
    }
}

#[inline]
fn json_hex4(s: &[u8]) -> Option<u32> {
    let h = s.get(..4)?;
    Some(hex_digit(h[0])? << 12 | hex_digit(h[1])? << 8 | hex_digit(h[2])? << 4 | hex_digit(h[3])?)
}

/// `decode_json_key()` copying unescaped runs at once: appends the decoded bytes to `out` when
/// given and returns `false` for escapes `json_decode` rejects.
fn json_unescape(raw: &[u8], mut out: Option<&mut Vec<u8>>) -> bool {
    let mut i = 0;
    while let Some(run) = raw[i..].iter().position(|&b| b == b'\\') {
        if let Some(o) = out.as_deref_mut() {
            o.extend_from_slice(&raw[i..i + run]);
        }
        i += run;
        let Some(&e) = raw.get(i + 1) else {
            return false;
        };
        i += 2;
        let byte = match e {
            b'"' => b'"',
            b'\\' => b'\\',
            b'/' => b'/',
            b'b' => 0x08,
            b'f' => 0x0c,
            b'n' => b'\n',
            b'r' => b'\r',
            b't' => b'\t',
            b'u' => {
                let Some(hi) = json_hex4(&raw[i..]) else {
                    return false;
                };
                i += 4;
                let cp = if (0xD800..0xDC00).contains(&hi) {
                    if raw.get(i) != Some(&b'\\') || raw.get(i + 1) != Some(&b'u') {
                        return false;
                    }
                    let Some(lo) = json_hex4(&raw[i + 2..]) else {
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
                if let Some(o) = out.as_deref_mut() {
                    let Some(ch) = char::from_u32(cp) else {
                        return false;
                    };
                    let mut buf = [0u8; 4];
                    o.extend_from_slice(ch.encode_utf8(&mut buf).as_bytes());
                }
                continue;
            }
            _ => return false,
        };
        if let Some(o) = out.as_deref_mut() {
            o.push(byte);
        }
    }
    if let Some(o) = out {
        o.extend_from_slice(&raw[i..]);
    }
    true
}

/// Validates one JSON string starting at the opening quote (escapes as `json_unescape()` accepts
/// them); returns the index after the closing quote.
fn jv_string(s: &[u8], mut p: usize) -> Option<usize> {
    p += 1;
    loop {
        p = json_string_stop(s, p);
        match *s.get(p)? {
            b'"' => return Some(p + 1),
            b'\\' => match *s.get(p + 1)? {
                b'"' | b'\\' | b'/' | b'b' | b'f' | b'n' | b'r' | b't' => p += 2,
                b'u' => {
                    let unit = json_hex4(s.get(p + 2..)?)?;
                    p += 6;
                    if (0xD800..0xDC00).contains(&unit) {
                        if s.get(p) != Some(&b'\\') || s.get(p + 1) != Some(&b'u') {
                            return None;
                        }
                        let low = json_hex4(s.get(p + 2..)?)?;
                        if !(0xDC00..0xE000).contains(&low) {
                            return None;
                        }
                        p += 6;
                    } else if (0xDC00..0xE000).contains(&unit) {
                        return None;
                    }
                }
                _ => return None,
            },
            _ => return None,
        }
    }
}

fn jv_number(s: &[u8], mut p: usize) -> Option<usize> {
    if s.get(p) == Some(&b'-') {
        p += 1;
    }
    match s.get(p) {
        Some(b'0') => p += 1,
        Some(b'1'..=b'9') => p = skip_digits(s, p),
        _ => return None,
    }
    if s.get(p) == Some(&b'.') {
        let q = skip_digits(s, p + 1);
        if q == p + 1 {
            return None;
        }
        p = q;
    }
    if matches!(s.get(p), Some(b'e' | b'E')) {
        p += 1;
        if matches!(s.get(p), Some(b'+' | b'-')) {
            p += 1;
        }
        let q = skip_digits(s, p);
        if q == p {
            return None;
        }
        p = q;
    }
    Some(p)
}

fn jv_value(s: &[u8], p: usize, depth: usize, ends: &mut Vec<(u32, u32)>) -> Option<usize> {
    let mut p = json_ws(s, p);
    match *s.get(p)? {
        b'{' => {
            if depth >= MAX_JSON_DEPTH {
                return None;
            }
            let slot = ends.len();
            ends.push((p as u32, 0));
            p = json_ws(s, p + 1);
            if s.get(p) == Some(&b'}') {
                ends[slot].1 = (p + 1) as u32;
                return Some(p + 1);
            }
            loop {
                if s.get(p) != Some(&b'"') {
                    return None;
                }
                p = json_ws(s, jv_string(s, p)?);
                if s.get(p) != Some(&b':') {
                    return None;
                }
                p = json_ws(s, jv_value(s, p + 1, depth + 1, ends)?);
                match s.get(p) {
                    Some(b',') => p = json_ws(s, p + 1),
                    Some(b'}') => {
                        ends[slot].1 = (p + 1) as u32;
                        return Some(p + 1);
                    }
                    _ => return None,
                }
            }
        }
        b'[' => {
            if depth >= MAX_JSON_DEPTH {
                return None;
            }
            let slot = ends.len();
            ends.push((p as u32, 0));
            p = json_ws(s, p + 1);
            if s.get(p) == Some(&b']') {
                ends[slot].1 = (p + 1) as u32;
                return Some(p + 1);
            }
            loop {
                p = json_ws(s, jv_value(s, p, depth + 1, ends)?);
                match s.get(p) {
                    Some(b',') => p += 1,
                    Some(b']') => {
                        ends[slot].1 = (p + 1) as u32;
                        return Some(p + 1);
                    }
                    _ => return None,
                }
            }
        }
        b'"' => jv_string(s, p),
        b't' => lit(s, p, b"true"),
        b'f' => lit(s, p, b"false"),
        b'n' => lit(s, p, b"null"),
        _ => jv_number(s, p),
    }
}

/// The validated document and the `(start, end)` offsets of every container in document order.
struct JDoc<'a> {
    s: &'a [u8],
    ends: Vec<(u32, u32)>,
}

impl JDoc<'_> {
    /// End (exclusive) of the container opening at `p` within `span`, a slice of this document.
    #[inline]
    fn container_end(&self, span: &[u8], p: usize) -> usize {
        let base = span.as_ptr() as usize - self.s.as_ptr() as usize;
        let at = (base + p) as u32;
        let slot = self
            .ends
            .binary_search_by_key(&at, |e| e.0)
            .expect("validated container");
        self.ends[slot].1 as usize - base
    }
}

/// `json_decode($s, true)` succeeds (within the depth this reader supports).
fn json_document(s: &[u8]) -> Option<JDoc<'_>> {
    if s.len() >= u32::MAX as usize || std::str::from_utf8(s).is_err() {
        return None;
    }
    let mut ends = Vec::with_capacity(s.len() / 64);
    let end = jv_value(s, 0, 0, &mut ends)?;
    (json_ws(s, end) == s.len()).then_some(JDoc { s, ends })
}

#[cfg(test)]
fn json_document_valid(s: &[u8]) -> bool {
    json_document(s).is_some()
}

/// A value inside an already validated document.
#[derive(Clone, Copy)]
enum JVal<'a> {
    Null,
    Bool(bool),
    Num(&'a [u8]),
    /// Raw string content between the quotes, escapes still encoded.
    Str(&'a [u8]),
    /// Whole span including the brackets.
    Arr(&'a [u8]),
    Obj(&'a [u8]),
}

#[derive(Clone, Copy)]
enum Num {
    I(i64),
    F(f64),
}

#[inline]
fn jskip_string(s: &[u8], mut p: usize) -> usize {
    loop {
        p = json_string_stop(s, p);
        if s[p] == b'"' {
            return p;
        }
        p += 2;
    }
}

/// Reads the value at `*p` (no leading whitespace) of a validated document and advances past it.
fn jread<'a>(d: &JDoc<'_>, s: &'a [u8], p: &mut usize) -> JVal<'a> {
    let start = *p;
    match s[start] {
        b'"' => {
            let end = jskip_string(s, start + 1);
            *p = end + 1;
            JVal::Str(&s[start + 1..end])
        }
        b'{' => {
            *p = d.container_end(s, start);
            JVal::Obj(&s[start..*p])
        }
        b'[' => {
            *p = d.container_end(s, start);
            JVal::Arr(&s[start..*p])
        }
        b't' => {
            *p += 4;
            JVal::Bool(true)
        }
        b'f' => {
            *p += 5;
            JVal::Bool(false)
        }
        b'n' => {
            *p += 4;
            JVal::Null
        }
        _ => {
            let mut end = start;
            while end < s.len() && matches!(s[end], b'0'..=b'9' | b'-' | b'+' | b'.' | b'e' | b'E')
            {
                end += 1;
            }
            *p = end;
            JVal::Num(&s[start..end])
        }
    }
}

/// Calls `f` for each element of a validated array span.
fn jelements<'a>(
    d: &JDoc<'_>,
    span: &'a [u8],
    mut f: impl FnMut(JVal<'a>) -> Option<bool>,
) -> Option<()> {
    let mut p = json_ws(span, 1);
    if span[p] == b']' {
        return Some(());
    }
    loop {
        let v = jread(d, span, &mut p);
        if !f(v)? {
            return Some(());
        }
        p = json_ws(span, p);
        if span[p] == b']' {
            return Some(());
        }
        p = json_ws(span, p + 1);
    }
}

#[inline]
fn container_empty(span: &[u8]) -> bool {
    json_ws(span, 1) == span.len() - 1
}

/// The decoded bytes of a raw JSON string token.
fn jdecode(raw: &[u8]) -> std::borrow::Cow<'_, [u8]> {
    if raw.contains(&b'\\') {
        let mut out = Vec::with_capacity(raw.len());
        json_unescape(raw, Some(&mut out));
        std::borrow::Cow::Owned(out)
    } else {
        std::borrow::Cow::Borrowed(raw)
    }
}

/// Last-wins lookups (PHP array semantics) of `names` in a validated object span.
fn jlookup<'a, const N: usize>(
    d: &JDoc<'_>,
    span: &'a [u8],
    names: [&[u8]; N],
) -> [Option<JVal<'a>>; N] {
    let mut found = [None; N];
    let mut p = json_ws(span, 1);
    if span[p] == b'}' {
        return found;
    }
    loop {
        let key_end = jskip_string(span, p + 1);
        let raw_key = &span[p + 1..key_end];
        p = json_ws(span, key_end + 1);
        p = json_ws(span, p + 1);
        let v = jread(d, span, &mut p);
        // Unescaped keys (the norm) compare raw; length and first byte reject most members.
        let slot = if raw_key.contains(&b'\\') {
            let key = jdecode(raw_key);
            names.iter().position(|n| *n == &key[..])
        } else {
            names.iter().position(|n| {
                n.len() == raw_key.len() && n.first() == raw_key.first() && *n == raw_key
            })
        };
        if let Some(k) = slot {
            found[k] = Some(v);
        }
        p = json_ws(span, p);
        if span[p] == b'}' {
            return found;
        }
        p = json_ws(span, p + 1);
    }
}

#[inline]
fn jobject(v: Option<JVal<'_>>) -> Option<&[u8]> {
    match v {
        Some(JVal::Obj(span)) => Some(span),
        _ => None,
    }
}

/// `is_string($v) && $v !== ''` → the decoded string.
fn jnonempty_string(v: Option<JVal<'_>>) -> Option<std::borrow::Cow<'_, [u8]>> {
    match v {
        Some(JVal::Str(raw)) if !raw.is_empty() => Some(jdecode(raw)),
        _ => None,
    }
}

fn plain_integer(s: &[u8]) -> bool {
    let digits = s.strip_prefix(b"-").unwrap_or(s);
    !digits.is_empty() && digits.iter().all(u8::is_ascii_digit)
}

fn parse_i64(s: &[u8]) -> Option<i64> {
    std::str::from_utf8(s).ok()?.parse().ok()
}

/// `is_numeric($v) ? <number> : null`; `Err` declines (numeric strings other than plain integers).
fn jnumeric(v: Option<JVal<'_>>) -> Result<Option<Num>, ()> {
    match v {
        Some(JVal::Num(raw)) => {
            if !raw.iter().any(|b| matches!(b, b'.' | b'e' | b'E'))
                && let Some(i) = parse_i64(raw)
            {
                return Ok(Some(Num::I(i)));
            }
            let f: f64 = std::str::from_utf8(raw)
                .ok()
                .and_then(|t| t.parse().ok())
                .ok_or(())?;
            Ok(Some(Num::F(f)))
        }
        Some(JVal::Str(raw)) => {
            let s = jdecode(raw);
            if plain_integer(&s) {
                parse_i64(&s).map(|i| Some(Num::I(i))).ok_or(())
            } else if s.iter().any(u8::is_ascii_digit) {
                Err(())
            } else {
                Ok(None)
            }
        }
        _ => Ok(None),
    }
}

/// `(float) $numeric`.
#[inline]
fn num_float(n: Num) -> f64 {
    match n {
        Num::I(i) => i as f64,
        Num::F(f) => f,
    }
}

/// `(int) $numeric`; `Err` declines floats PHP cannot narrow exactly.
fn num_int(n: Num) -> Result<i64, ()> {
    match n {
        Num::I(i) => Ok(i),
        Num::F(f) if f.is_finite() && f > -9.2e18 && f < 9.2e18 => Ok(f.trunc() as i64),
        Num::F(_) => Err(()),
    }
}

/// `(int) ($v ?? 0)` for any JSON value.
fn jint_cast(v: Option<JVal<'_>>) -> Result<i64, ()> {
    match v {
        None | Some(JVal::Null) | Some(JVal::Bool(false)) => Ok(0),
        Some(JVal::Bool(true)) => Ok(1),
        Some(JVal::Arr(span) | JVal::Obj(span)) => Ok(i64::from(!container_empty(span))),
        Some(JVal::Num(_)) => num_int(jnumeric(v)?.ok_or(())?),
        Some(JVal::Str(raw)) => {
            let s = jdecode(raw);
            if plain_integer(&s) {
                parse_i64(&s).ok_or(())
            } else if s.iter().any(u8::is_ascii_digit) {
                Err(())
            } else {
                Ok(0)
            }
        }
    }
}

/// `! empty($v)`.
fn jtruthy(v: Option<JVal<'_>>) -> Result<bool, ()> {
    Ok(match v {
        None | Some(JVal::Null) => false,
        Some(JVal::Bool(b)) => b,
        Some(JVal::Num(_)) => match jnumeric(v)?.ok_or(())? {
            Num::I(i) => i != 0,
            Num::F(f) => f != 0.0,
        },
        Some(JVal::Str(raw)) => {
            let s = jdecode(raw);
            !(s.is_empty() || &s[..] == b"0")
        }
        Some(JVal::Arr(span) | JVal::Obj(span)) => !container_empty(span),
    })
}

/// `(string) $v` for a scalar, `None` meaning "not a string" for non-string scalars that can
/// never equal the compared keywords; `Err` for arrays (PHP warns on array-to-string).
fn jscalar_string(v: JVal<'_>) -> Result<Option<std::borrow::Cow<'_, [u8]>>, ()> {
    match v {
        JVal::Str(raw) => Ok(Some(jdecode(raw))),
        JVal::Arr(_) | JVal::Obj(_) => Err(()),
        _ => Ok(None),
    }
}

/// Appends the decimal form of `v`.
fn push_i64(out: &mut Vec<u8>, v: i64) {
    let mut buf = [0u8; 20];
    let mut n = v.unsigned_abs();
    let mut at = buf.len();
    loop {
        at -= 1;
        buf[at] = b'0' + (n % 10) as u8;
        n /= 10;
        if n == 0 {
            break;
        }
    }
    if v < 0 {
        out.push(b'-');
    }
    out.extend_from_slice(&buf[at..]);
}

/// Appends decoded field bytes; `None` when they contain a record or field separator.
fn push_field(out: &mut Vec<u8>, bytes: &[u8]) -> Option<()> {
    if bytes.iter().any(|&b| b == REC_SEP || b == FIELD_SEP) {
        return None;
    }
    out.extend_from_slice(bytes);
    Some(())
}

/// `PredbFeedEntry::sizeFromMegabytes($mb)`. Values that are not clearly away from a rounding
/// tie (or large) are written as `~<float>` so PHP formats them; non-finite values decline.
fn push_size(out: &mut Vec<u8>, mb: f64) -> Option<()> {
    if !mb.is_finite() {
        return None;
    }
    if mb <= 0.0 {
        return Some(());
    }
    let (value, unit): (f64, &[u8]) = if mb < 1.0 {
        (mb * 1024.0, b"KB")
    } else {
        (mb, b"MB")
    };
    let scaled = value * 100.0;
    let frac = scaled - scaled.floor();
    if value < 1e6 && (frac - 0.5).abs() > 1e-6 {
        // rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.')
        let cents = scaled.round() as i64;
        push_i64(out, cents / 100);
        let fraction = cents % 100;
        if fraction != 0 {
            out.push(b'.');
            out.push(b'0' + (fraction / 10) as u8);
            if fraction % 10 != 0 {
                out.push(b'0' + (fraction % 10) as u8);
            }
        }
        out.extend_from_slice(unit);
    } else {
        out.push(b'~');
        out.extend_from_slice(format!("{mb:?}").as_bytes());
    }
    Some(())
}

/// `PredbFeedEntry::filesFromCount(is_numeric($v) ? (int) $v : null)`.
fn push_files(out: &mut Vec<u8>, v: Option<JVal<'_>>) -> Option<()> {
    if let Some(n) = jnumeric(v).ok()? {
        let count = num_int(n).ok()?;
        if count > 0 {
            push_i64(out, count);
        }
    }
    Some(())
}

/// `is_numeric($v) ? (float) $v * $scale : null` passed to `sizeFromMegabytes`.
fn push_numeric_size(out: &mut Vec<u8>, v: Option<JVal<'_>>, divisor: f64) -> Option<()> {
    match jnumeric(v).ok()? {
        Some(n) => push_size(out, num_float(n) / divisor),
        None => Some(()),
    }
}

/// `is_numeric($v) ? (int) $v : null` as a decimal timestamp field.
fn push_timestamp(out: &mut Vec<u8>, v: Option<JVal<'_>>) -> Option<()> {
    if let Some(n) = jnumeric(v).ok()? {
        push_i64(out, num_int(n).ok()?);
    }
    Some(())
}

/// The trimmed, non-empty title from `is_string($v) && trim($v) !== ''`, or `None` to skip the row.
fn row_title(v: Option<JVal<'_>>) -> Option<std::borrow::Cow<'_, [u8]>> {
    let JVal::Str(raw) = v? else {
        return None;
    };
    let title = match jdecode(raw) {
        std::borrow::Cow::Borrowed(b) => std::borrow::Cow::Borrowed(php_trim(b)),
        std::borrow::Cow::Owned(mut o) => {
            let end = o.len() - o.iter().rev().take_while(|&&b| is_php_trim(b)).count();
            o.truncate(end);
            let start = o.iter().take_while(|&&b| is_php_trim(b)).count();
            o.drain(..start);
            std::borrow::Cow::Owned(o)
        }
    };
    (!title.is_empty()).then_some(title)
}

/// Starts a record: title and category fields.
fn begin_record(out: &mut Vec<u8>, title: &[u8], category: Option<&[u8]>) -> Option<()> {
    out.push(FIELD_SEP);
    push_field(out, title)?;
    out.push(FIELD_SEP);
    if let Some(c) = category {
        push_field(out, c)?;
    }
    out.push(FIELD_SEP);
    Some(())
}

/// Ends a record after its timestamp field: nuke status and reason.
fn end_record(out: &mut Vec<u8>, nuked: u8, reason: Option<&[u8]>) -> Option<()> {
    out.push(FIELD_SEP);
    out.push(b'0' + nuked);
    out.push(FIELD_SEP);
    if let Some(r) = reason {
        push_field(out, r)?;
    }
    Some(())
}

/// `PredbOvhSource::preId()`: canonical positive uint64 string.
fn ovh_pre_id(v: Option<JVal<'_>>) -> Result<Option<Vec<u8>>, ()> {
    match v {
        Some(JVal::Num(_)) => match jnumeric(v)? {
            Some(Num::I(i)) => Ok((i > 0).then(|| i.to_string().into_bytes())),
            _ => Ok(None),
        },
        Some(JVal::Str(raw)) => {
            let s = jdecode(raw);
            if s.is_empty() || !s.iter().all(u8::is_ascii_digit) {
                return Ok(None);
            }
            let start = s.iter().position(|&b| b != b'0').unwrap_or(s.len());
            let id = &s[start..];
            if id.is_empty()
                || id.len() > 20
                || (id.len() == 20 && id > &b"18446744073709551615"[..])
            {
                return Ok(None);
            }
            Ok(Some(id.to_vec()))
        }
        _ => Ok(None),
    }
}

/// The envelope check shared by the predb.ovh-style APIs: `status === 'error'` declines.
fn status_is_error(d: &JDoc<'_>, top: &[u8]) -> bool {
    let [status] = jlookup(d, top, [b"status"]);
    matches!(status, Some(JVal::Str(raw)) if &jdecode(raw)[..] == b"error")
}

fn json_rows<'a>(d: &JDoc<'_>, top: Option<&'a [u8]>, key: &[u8]) -> Option<&'a [u8]> {
    let [rows] = jlookup(d, top?, [key]);
    match rows {
        Some(JVal::Arr(span)) => Some(span),
        _ => None,
    }
}

fn json_feed(d: &JDoc<'_>, source: u32, limit: usize, out: &mut Vec<u8>) -> Option<()> {
    let s = d.s;
    let mut p = json_ws(s, 0);
    let root = jread(d, s, &mut p);
    let top = match root {
        JVal::Obj(span) => Some(span),
        _ => None,
    };
    match source {
        JSON_PREDATABASE => {
            let [results, next] = jlookup(d, top?, [b"results", b"next_page_id"]);
            let Some(JVal::Arr(rows)) = results else {
                return None;
            };
            match next {
                None | Some(JVal::Null) => {}
                Some(JVal::Str(raw)) => push_field(out, &jdecode(raw))?,
                Some(_) => return None,
            }
            let mut first: Option<i64> = None;
            let mut last: Option<i64> = None;
            let mut body = Vec::with_capacity(rows.len() / 2);
            jelements(d, rows, |row| {
                let JVal::Obj(row) = row else {
                    return Some(true);
                };
                let [name, section, size, files, ctime] = jlookup(
                    d,
                    row,
                    [b"rlsname", b"section", b"size", b"files", b"ctime"],
                );
                let time = match jnumeric(ctime).ok()? {
                    Some(n) => num_int(n).ok()?,
                    None => 0,
                };
                if time > 0 {
                    if last.is_some_and(|l| time > l) {
                        return None;
                    }
                    first.get_or_insert(time);
                    last = Some(time);
                }
                let Some(title) = row_title(name) else {
                    return Some(true);
                };
                begin_record(&mut body, &title, jnonempty_string(section).as_deref())?;
                push_numeric_size(&mut body, size, 1.0)?;
                body.push(FIELD_SEP);
                push_files(&mut body, files)?;
                body.push(FIELD_SEP);
                if time > 0 {
                    push_i64(&mut body, time);
                }
                end_record(&mut body, PRE_NONUKE, None)?;
                Some(true)
            })?;
            out.push(FIELD_SEP);
            if let (Some(f), Some(l)) = (first, last) {
                push_i64(out, f);
                out.push(FIELD_SEP);
                push_i64(out, l);
            } else {
                out.push(FIELD_SEP);
            }
            out.extend_from_slice(&body);
        }
        JSON_XREL | JSON_XREL_P2P => {
            let [pagination, list] = jlookup(d, top?, [b"pagination", b"list"]);
            let Some(JVal::Arr(rows)) = list else {
                return None;
            };
            let total = match jobject(pagination) {
                Some(span) => match jlookup(d, span, [b"total_pages"]) {
                    [None] => -1,
                    [v] => jint_cast(v).ok()?,
                },
                None => -1,
            };
            push_i64(out, total);
            let p2p = source == JSON_XREL_P2P;
            jelements(d, rows, |row| {
                let JVal::Obj(row) = row else {
                    return Some(true);
                };
                let [
                    dirname,
                    time,
                    size,
                    ext_info,
                    flags,
                    pub_time,
                    size_mb,
                    category,
                ] = jlookup(
                    d,
                    row,
                    [
                        b"dirname",
                        b"time",
                        b"size",
                        b"ext_info",
                        b"flags",
                        b"pub_time",
                        b"size_mb",
                        b"category",
                    ],
                );
                let Some(title) = row_title(dirname) else {
                    return Some(true);
                };
                if p2p {
                    let (mut meta, mut sub) = (Vec::new(), Vec::new());
                    if let Some(span) = category.and_then(|c| match c {
                        JVal::Obj(s) => Some(s),
                        _ => None,
                    }) {
                        let [m, s] = jlookup(d, span, [b"meta_cat", b"sub_cat"]);
                        if let Some(JVal::Str(raw)) = m {
                            meta = jdecode(raw).to_ascii_uppercase();
                        }
                        if let Some(JVal::Str(raw)) = s {
                            sub = jdecode(raw).into_owned();
                        }
                    }
                    let label = if meta.is_empty() {
                        None
                    } else if sub.is_empty() {
                        Some(meta)
                    } else {
                        meta.push(b'-');
                        meta.extend_from_slice(&sub);
                        Some(meta)
                    };
                    begin_record(out, &title, label.as_deref())?;
                    push_numeric_size(out, size_mb, 1.0)?;
                    out.push(FIELD_SEP);
                    out.push(FIELD_SEP);
                    push_timestamp(out, pub_time)?;
                    end_record(out, PRE_NONUKE, None)?;
                } else {
                    let kind = jobject(ext_info)
                        .and_then(|span| jnonempty_string(jlookup(d, span, [b"type"])[0]))
                        .map(|t| t.to_ascii_uppercase());
                    begin_record(out, &title, kind.as_deref())?;
                    if let Some(span) = jobject(size) {
                        let [number, unit] = jlookup(d, span, [b"number", b"unit"]);
                        if let Some(n) = jnumeric(number).ok()? {
                            let unit = match unit {
                                None => None,
                                Some(u) => jscalar_string(u).ok()?,
                            };
                            let n = num_float(n);
                            let mb = match unit.as_deref().map(<[u8]>::to_ascii_uppercase) {
                                Some(u) if u == b"KB" => Some(n / 1024.0),
                                Some(u) if u == b"MB" => Some(n),
                                Some(u) if u == b"GB" => Some(n * 1024.0),
                                Some(u) if u == b"TB" => Some(n * 1_048_576.0),
                                _ => None,
                            };
                            if let Some(mb) = mb {
                                push_size(out, mb)?;
                            }
                        }
                    }
                    out.push(FIELD_SEP);
                    out.push(FIELD_SEP);
                    push_timestamp(out, time)?;
                    let nuked = match jobject(flags) {
                        Some(span) => jtruthy(jlookup(d, span, [b"nuke_rls"])[0]).ok()?,
                        None => false,
                    };
                    end_record(out, if nuked { PRE_NUKED } else { PRE_NONUKE }, None)?;
                }
                Some(true)
            })?;
        }
        JSON_SRRDB => {
            let rows = json_rows(d, top, b"results")?;
            jelements(d, rows, |row| {
                let JVal::Obj(row) = row else {
                    return Some(true);
                };
                let [release, date] = jlookup(d, row, [b"release", b"date"]);
                let Some(title) = row_title(release) else {
                    return Some(true);
                };
                begin_record(out, &title, None)?;
                out.push(FIELD_SEP);
                out.push(FIELD_SEP);
                if let Some(JVal::Str(raw)) = date {
                    let decoded = jdecode(raw);
                    let value = php_trim(&decoded);
                    if !value.is_empty() {
                        let canonical = value.len() == 19
                            && value.iter().enumerate().all(|(i, &b)| match i {
                                4 | 7 => b == b'-',
                                10 => b == b' ',
                                13 | 16 => b == b':',
                                _ => b.is_ascii_digit(),
                            });
                        out.push(if canonical { b'!' } else { b'~' });
                        push_field(out, value)?;
                    }
                }
                end_record(out, PRE_NONUKE, None)?;
                Some(true)
            })?;
        }
        JSON_PREDB_OVH | JSON_PREDB_CLUB => {
            if status_is_error(d, top?) {
                return None;
            }
            let [data] = jlookup(d, top?, [b"data"]);
            let rows = json_rows(d, jobject(data), b"rows")?;
            let ovh = source == JSON_PREDB_OVH;
            jelements(d, rows, |row| {
                let JVal::Obj(row) = row else {
                    return Some(true);
                };
                let [name, id, cat, size, files, pre_at, nuke] = jlookup(
                    d,
                    row,
                    [b"name", b"id", b"cat", b"size", b"files", b"preAt", b"nuke"],
                );
                let Some(title) = row_title(name) else {
                    return Some(true);
                };
                let nuke_value = match nuke {
                    Some(v @ (JVal::Obj(_) | JVal::Arr(_))) => Some(v),
                    _ => None,
                };
                let nuke_span = nuke_value.and_then(|v| match v {
                    JVal::Obj(s) => Some(s),
                    _ => None,
                });
                let [type_id, kind, reason_value, pre_id] = match nuke_span {
                    Some(span) => jlookup(d, span, [b"typeId", b"type", b"reason", b"preId"]),
                    None => [None; 4],
                };
                let (nuked, reason) = if ovh {
                    let row_id = ovh_pre_id(id).ok()?;
                    let nuke_pre_id = ovh_pre_id(pre_id).ok()?;
                    let same = match (&row_id, &nuke_pre_id) {
                        (Some(a), Some(b)) => a == b,
                        _ => true,
                    };
                    let type_id = if nuke_value.is_some() && same {
                        jint_cast(type_id).ok()?
                    } else {
                        0
                    };
                    let reason = if same {
                        jnonempty_string(reason_value)
                    } else {
                        None
                    };
                    let nuked = match type_id {
                        0 => PRE_NONUKE,
                        1 => PRE_NUKED,
                        2 | 5 => PRE_UNNUKED,
                        3 => PRE_MODNUKE,
                        _ => PRE_NUKED,
                    };
                    (nuked, if type_id == 0 { None } else { reason })
                } else if nuke_value.is_none() {
                    (PRE_NONUKE, None)
                } else {
                    let kind = match kind {
                        None | Some(JVal::Null) => Some(std::borrow::Cow::Borrowed(&b"nuke"[..])),
                        Some(v) => jscalar_string(v).ok()?,
                    };
                    let nuked = match kind.map(|k| k.to_ascii_lowercase()).as_deref() {
                        Some(b"unnuke" | b"undelpre") => PRE_UNNUKED,
                        Some(b"modnuke") => PRE_MODNUKE,
                        Some(b"renuke") => PRE_RENUKED,
                        Some(b"oldnuke") => PRE_OLDNUKE,
                        _ => PRE_NUKED,
                    };
                    (nuked, jnonempty_string(reason_value))
                };
                begin_record(out, &title, jnonempty_string(cat).as_deref())?;
                push_numeric_size(out, size, if ovh { 1024.0 } else { 1.0 })?;
                out.push(FIELD_SEP);
                push_files(out, files)?;
                out.push(FIELD_SEP);
                push_timestamp(out, pre_at)?;
                end_record(out, nuked, reason.as_deref())?;
                Some(true)
            })?;
        }
        JSON_PREDB_NET => {
            if status_is_error(d, top?) {
                return None;
            }
            let rows = json_rows(d, top, b"data")?;
            jelements(d, rows, |row| {
                let JVal::Obj(row) = row else {
                    return Some(true);
                };
                let [release, section, size, files, pretime, status, reason] = jlookup(
                    d,
                    row,
                    [
                        b"release", b"section", b"size", b"files", b"pretime", b"status", b"reason",
                    ],
                );
                let Some(title) = row_title(release) else {
                    return Some(true);
                };
                let status = jint_cast(status).ok()?;
                let nuked = match status {
                    0 => PRE_NONUKE,
                    2 | 4 => PRE_UNNUKED,
                    _ => PRE_NUKED,
                };
                let reason = if status == 0 {
                    None
                } else {
                    jnonempty_string(reason)
                };
                begin_record(out, &title, jnonempty_string(section).as_deref())?;
                push_numeric_size(out, size, 1.0)?;
                out.push(FIELD_SEP);
                push_files(out, files)?;
                out.push(FIELD_SEP);
                push_timestamp(out, pretime)?;
                end_record(out, nuked, reason.as_deref())?;
                Some(true)
            })?;
        }
        JSON_PREDB_ORG => {
            let rows = json_rows(d, top, b"values")?;
            jelements(d, rows, |row| {
                let JVal::Obj(row) = row else {
                    return Some(true);
                };
                let [rlsname, section, size, files, ctime, status, reason] = jlookup(
                    d,
                    row,
                    [
                        b"rlsname",
                        b"section",
                        b"size",
                        b"files",
                        b"ctime",
                        b"status",
                        b"nukereason",
                    ],
                );
                let Some(title) = row_title(rlsname) else {
                    return Some(true);
                };
                let reason = jnonempty_string(reason);
                // (string) ($status ?? '0') === '1'
                let status_one = match status {
                    None | Some(JVal::Null) | Some(JVal::Bool(false)) => false,
                    Some(JVal::Bool(true)) => true,
                    Some(JVal::Str(raw)) => &jdecode(raw)[..] == b"1",
                    Some(JVal::Num(_)) => match jnumeric(status).ok()?? {
                        Num::I(i) => i == 1,
                        Num::F(f) => f == 1.0,
                    },
                    Some(JVal::Arr(_) | JVal::Obj(_)) => return None,
                };
                let nuked = if status_one || reason.is_some() {
                    PRE_NUKED
                } else {
                    PRE_NONUKE
                };
                begin_record(out, &title, jnonempty_string(section).as_deref())?;
                push_numeric_size(out, size, 1.0)?;
                out.push(FIELD_SEP);
                push_files(out, files)?;
                out.push(FIELD_SEP);
                push_timestamp(out, ctime)?;
                end_record(out, nuked, reason.as_deref())?;
                Some(true)
            })?;
        }
        JSON_APIBAY => {
            let JVal::Arr(rows) = root else {
                return None;
            };
            let mut count = 0usize;
            jelements(d, rows, |row| {
                let JVal::Obj(row) = row else {
                    return Some(true);
                };
                let [name, category, size, num_files, added] = jlookup(
                    d,
                    row,
                    [b"name", b"category", b"size", b"num_files", b"added"],
                );
                let Some(title) = row_title(name) else {
                    return Some(true);
                };
                let label: &[u8] = match jint_cast(category).ok()? {
                    201 => b"MOVIE",
                    207 => b"MOVIE-HD",
                    205 => b"TV",
                    208 => b"TV-HD",
                    _ => return Some(true),
                };
                begin_record(out, &title, Some(label))?;
                push_numeric_size(out, size, 1_048_576.0)?;
                out.push(FIELD_SEP);
                push_files(out, num_files)?;
                out.push(FIELD_SEP);
                push_timestamp(out, added)?;
                end_record(out, PRE_NONUKE, None)?;
                count += 1;
                Some(count < limit)
            })?;
        }
        _ => return None,
    }
    Some(())
}

/// Parses a PreDB JSON feed response body for `source` (1 predataba.se, 2 xREL, 3 xREL P2P,
/// 4 srrDB, 5 predb.ovh, 6 predb.club, 7 predb.net, 8 predb.org, 9 apibay; `limit` caps apibay
/// entries). Output: `K`, the header (predataba.se: next cursor, newest and oldest positive
/// `ctime`; xREL: `(int)` total pages; otherwise empty) and seven fields per entry, every field
/// after the first header field preceded by `\x1f`: title, category, size (`~<float>` asks PHP
/// to format), files, time (Unix seconds, or srrDB's trimmed date prefixed `!` when canonical,
/// `~` otherwise), nuke status and reason. Strings containing `\x1e` or `\x1f` decline. Returns `-1` when PHP must decode (any invalid or out-of-subset input)
/// and `-(needed)` when `out_cap` is too small.
///
/// # Safety
/// `json` must be readable for `json_len` bytes and `out_buf` writable for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_json_feed_parse(
    json: *const u8,
    json_len: usize,
    source: u32,
    limit: u64,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if json.is_null() || json_len == 0 {
        return -1;
    }
    let s = unsafe { slice::from_raw_parts(json, json_len) };
    let Some(doc) = json_document(s) else {
        return -1;
    };
    let mut body = Vec::with_capacity(json_len / 2);
    body.push(b'K');
    let limit = usize::try_from(limit.max(1)).unwrap_or(usize::MAX);
    if json_feed(&doc, source, limit, &mut body).is_none() {
        return -1;
    }
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    out.extend(&body);
    xml_out_finish(out)
}

#[cfg(test)]
mod json_source_tests {
    use super::*;

    fn parse(json: &str, source: u32, limit: u64) -> Option<String> {
        let mut buf = vec![0u8; json.len() + 64];
        let n = unsafe {
            nntmux_predb_json_feed_parse(
                json.as_ptr(),
                json.len(),
                source,
                limit,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(n == -1 || n > 0, "unexpected status {n}");
        (n > 0).then(|| String::from_utf8(buf[..n as usize].to_vec()).unwrap())
    }

    /// The header fields followed by one seven-field record per entry.
    fn records(out: &str, header: usize) -> Vec<Vec<&str>> {
        let fields: Vec<&str> = out[1..].split('\x1f').collect();
        let mut rows = vec![fields[..header].to_vec()];
        rows.extend(fields[header..].chunks(7).map(<[&str]>::to_vec));
        rows
    }

    #[test]
    fn validator_matches_json_decode_acceptance() {
        for ok in [
            "{}",
            "[]",
            " [1, -0, 0.5, 1e3, -1E-2, true, false, null, \"\\u00e9\\ud83d\\ude00\"] ",
            "{\"a\":{\"b\":[{}]}}",
            "\"x\"",
            "123",
        ] {
            assert!(json_document_valid(ok.as_bytes()), "{ok}");
        }
        for bad in [
            "",
            " ",
            "[1,]",
            "{\"a\":1,}",
            "[01]",
            "[1.]",
            "[.5]",
            "[+1]",
            "[1e]",
            "[\"\\x\"]",
            "[\"\\ud800\"]",
            "[\"\\udc00\"]",
            "[\"a\tb\"]",
            "{'a':1}",
            "[1] 2",
            "\u{feff}[]",
            "[tru]",
            "[NaN]",
        ] {
            assert!(!json_document_valid(bad.as_bytes()), "{bad}");
        }
        assert!(!json_document_valid(b"[\"\xc3(\"]"));
        let deep = "[".repeat(MAX_JSON_DEPTH + 1) + &"]".repeat(MAX_JSON_DEPTH + 1);
        assert!(!json_document_valid(deep.as_bytes()));
    }

    #[test]
    fn sizes_match_size_from_megabytes_or_defer() {
        let size = |mb: f64| {
            let mut out = Vec::new();
            push_size(&mut out, mb).map(|()| String::from_utf8(out).unwrap())
        };
        assert_eq!(size(274.0).unwrap(), "274MB");
        assert_eq!(size(1234.5).unwrap(), "1234.5MB");
        assert_eq!(size(0.5).unwrap(), "512KB");
        assert_eq!(size(0.0001).unwrap(), "0.1KB");
        assert_eq!(size(2000.0 / 1024.0).unwrap(), "1.95MB");
        assert_eq!(size(0.0).unwrap(), "");
        assert_eq!(size(-3.0).unwrap(), "");
        assert_eq!(size(1.125).unwrap(), "~1.125");
        assert_eq!(size(2e6).unwrap(), "~2000000.0");
        assert!(size(f64::INFINITY).is_none());
    }

    #[test]
    fn rows_use_last_duplicate_members_and_php_casts() {
        let out = parse(
            r#"{"status":"success","data":{"rows":[
                {"name":"x","name":" A.B-GRP ","cat":"","size":"2048","files":"0","preAt":1.9,"nuke":{"type":"UnNuke","reason":"r"}},
                {"name":"   "},
                {"n\u0061me":"C-GRP","nuke":[1],"size":0.0001,"files":3.7}
            ]}}"#,
            JSON_PREDB_CLUB,
            1,
        )
        .unwrap();
        assert_eq!(
            records(&out, 1),
            vec![
                vec![""],
                vec!["A.B-GRP", "", "2048MB", "", "1", "1", "r"],
                vec!["C-GRP", "", "0.1KB", "3", "", "2", ""],
            ]
        );
    }

    #[test]
    fn envelopes_and_out_of_subset_values_decline() {
        let row = r#"{"name":"A-GRP","rlsname":"A-GRP","release":"A-GRP","dirname":"A-GRP"}"#;
        for source in 1..=9 {
            assert!(parse("{\"0\":1}", source, 1).is_none());
            assert!(parse("[1,", source, 1).is_none());
        }
        assert!(
            parse(
                &format!(r#"{{"status":"error","data":{{"rows":[{row}]}}}}"#),
                JSON_PREDB_OVH,
                1
            )
            .is_none()
        );
        assert!(parse(r#"{"data":{"rows":[{"name":"A","size":"1.5"}]}}"#, 6, 1).is_none());
        assert!(parse(r#"{"data":[{"release":"A","status":" 1"}]}"#, 7, 1).is_none());
        assert!(parse(r#"{"values":[{"rlsname":"A","status":[]}]}"#, 8, 1).is_none());
        assert!(parse(r#"{"results":[{"rlsname":"A","ctime":1e300}]}"#, 1, 1).is_none());
        assert!(parse(r#"{"results":[{"rlsname":"A\u001fB"}]}"#, 1, 1).is_none());
        assert!(parse(r#"{"results":[{"ctime":5},{"ctime":6}]}"#, 1, 1).is_none());
        assert!(parse(r#"{"results":[],"next_page_id":1}"#, 1, 1).is_none());
        assert!(parse(&format!("[{row}]"), 10, 1).is_none());
    }

    #[test]
    fn source_headers_and_limits() {
        let out = parse(
            r#"{"results":[{"rlsname":"A","ctime":9},{"ctime":0},{"rlsname":"B","ctime":"7"}],"next_page_id":"cur"}"#,
            JSON_PREDATABASE,
            1,
        )
        .unwrap();
        let r = records(&out, 3);
        assert_eq!(r[0], vec!["cur", "9", "7"]);
        assert_eq!(r[2], vec!["B", "", "", "", "7", "0", ""]);

        let out = parse(
            r#"{"pagination":{"total_pages":"3"},"list":[{"dirname":"A","size":{"number":2,"unit":"gb"},"ext_info":{"type":"tv"},"flags":{"nuke_rls":"0"}}]}"#,
            JSON_XREL,
            1,
        )
        .unwrap();
        assert_eq!(
            records(&out, 1),
            vec![vec!["3"], vec!["A", "TV", "2048MB", "", "", "0", ""]]
        );

        let rows = r#"[{"name":"A","category":"201"},{"name":"B","category":"300"},{"name":"C","category":208},{"name":"D","category":205}]"#;
        let out = parse(rows, JSON_APIBAY, 2).unwrap();
        let titles: Vec<&str> = records(&out, 1)[1..].iter().map(|r| r[0]).collect();
        assert_eq!(titles, vec!["A", "C"]);

        let out = parse(
            r#"{"results":[{"release":"A","date":" 2026-10-09 22:13:12 "},{"release":"B","date":"2026-1-9 2:3:4"}]}"#,
            JSON_SRRDB,
            1,
        )
        .unwrap();
        assert_eq!(records(&out, 1)[1][4], "!2026-10-09 22:13:12");
        assert_eq!(records(&out, 1)[2][4], "~2026-1-9 2:3:4");
    }

    #[test]
    fn small_buffers_report_the_needed_size() {
        let json = r#"{"values":[{"rlsname":"Some.Release-GRP","size":10}]}"#;
        let mut buf = [0u8; 4];
        let n = unsafe {
            nntmux_predb_json_feed_parse(
                json.as_ptr(),
                json.len(),
                JSON_PREDB_ORG,
                1,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        assert!(n < -1);
        let full = parse(json, JSON_PREDB_ORG, 1).unwrap();
        assert_eq!(-n as usize, full.len());
    }
}

// ---------------------------------------------------------------------------------------------
// NIMA4K release listing HTML (`Nima4kSource::parseHtml`)
// ---------------------------------------------------------------------------------------------
//
// A strict single-pass tokenizer and tree builder for the subset of HTML on which libxml's legacy
// HTML parser and the HTML5 `Dom\HTMLDocument` parser provably build the same element tree with no
// error that `parseHtmlPhp` rejects or must investigate: an explicit html/head/body skeleton,
// lowercase whitelisted elements that are closed in order, no implied end tags, no raw text other
// than a plain head `<title>`, simple comments, and only the five basic named references, valid
// numeric references or a bare `&` before whitespace. Anything else returns -1 so PHP performs the
// full dual-DOM verification and raises the exact diagnostic. Accepted documents must also satisfy
// every release-card rule; cards with non-ASCII or otherwise unusual names decline as well.

use std::ffi::c_char;

const NIMA_MAX_BYTES: usize = 8 << 20;

#[derive(Clone, Copy, PartialEq, Eq)]
enum NimaTag {
    Div,
    Flow,
    Anchor,
    Phrasing,
    Paragraph,
    Heading,
    H2,
    Span,
    List,
    Li,
}

struct NimaFrame {
    name: Vec<u8>,
    tag: NimaTag,
    phrasing: bool,
    cover_box: bool,
    article_right: bool,
}

#[derive(Default)]
struct NimaCard {
    depth: usize,
    subtitles: usize,
    subtitle_depth: Option<usize>,
    text: Vec<u8>,
    covers: usize,
    alt: Vec<u8>,
    cover_boxes: usize,
}

#[inline]
fn nima_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r')
}

fn nima_skip_space(s: &[u8], mut i: usize) -> usize {
    while i < s.len() && nima_space(s[i]) {
        i += 1;
    }
    i
}

/// Valid UTF-8 without `Nima4kSource::FORBIDDEN_CHARACTERS` or a leading byte order mark.
fn nima_prescan(s: &[u8]) -> bool {
    let Ok(text) = std::str::from_utf8(s) else {
        return false;
    };
    if s.starts_with(b"\xEF\xBB\xBF") {
        return false;
    }
    for &b in s {
        if b < 0x20 && !matches!(b, b'\t' | b'\n' | b'\r') || b == 0x7f {
            return false;
        }
    }
    if s.is_ascii() {
        return true;
    }
    text.chars().all(|c| !nima_forbidden(c as u32))
}

#[inline]
fn nima_forbidden(cp: u32) -> bool {
    cp < 0x09
        || cp == 0x0b
        || cp == 0x0c
        || (0x0e..=0x1f).contains(&cp)
        || (0x7f..=0x9f).contains(&cp)
        || (0xfdd0..=0xfdef).contains(&cp)
        || cp & 0xfffe == 0xfffe
}

/// Numeric references that neither parser repairs, replaces or reports.
#[inline]
fn nima_reference_allowed(cp: u32) -> bool {
    matches!(cp, 0x09 | 0x0a)
        || (0x20..=0x7e).contains(&cp)
        || ((0xa0..=0x10ffff).contains(&cp)
            && !(0xd800..=0xdfff).contains(&cp)
            && !nima_forbidden(cp))
}

/// Decodes one reference at `s[i] == b'&'`; returns the index after it, or `None` to decline.
fn nima_reference(s: &[u8], i: usize, out: &mut Vec<u8>) -> Option<usize> {
    let next = *s.get(i + 1)?;
    if nima_space(next) {
        // Both parsers keep a bare ampersand before whitespace as literal text.
        out.push(b'&');
        return Some(i + 1);
    }
    if next == b'#' {
        let (hex, mut p) = match s.get(i + 2) {
            Some(b'x' | b'X') => (true, i + 3),
            _ => (false, i + 2),
        };
        let start = p;
        let mut cp: u32 = 0;
        while p < s.len() && p - start < 8 {
            let digit = match s[p] {
                b'0'..=b'9' => u32::from(s[p] - b'0'),
                b'a'..=b'f' if hex => u32::from(s[p] - b'a' + 10),
                b'A'..=b'F' if hex => u32::from(s[p] - b'A' + 10),
                _ => break,
            };
            cp = cp * if hex { 16 } else { 10 } + digit;
            p += 1;
        }
        if p == start || s.get(p) != Some(&b';') || !nima_reference_allowed(cp) {
            return None;
        }
        let c = char::from_u32(cp)?;
        let mut buf = [0u8; 4];
        out.extend_from_slice(c.encode_utf8(&mut buf).as_bytes());
        return Some(p + 1);
    }
    for (name, value) in [
        (&b"amp;"[..], b'&'),
        (b"lt;", b'<'),
        (b"gt;", b'>'),
        (b"quot;", b'"'),
    ] {
        if s[i + 1..].starts_with(name) {
            out.push(value);
            return Some(i + 1 + name.len());
        }
    }
    None
}

struct NimaAttr<'a> {
    name: &'a [u8],
    value: Vec<u8>,
}

/// A start tag at `s[i] == b'<'`: `(lowercase name, attributes, self-closing, index after '>')`.
#[allow(clippy::type_complexity)]
fn nima_start_tag(s: &[u8], i: usize) -> Option<(&[u8], Vec<NimaAttr<'_>>, bool, usize)> {
    let mut p = i + 1;
    let name_start = p;
    while p < s.len() && (s[p].is_ascii_lowercase() || (p > name_start && s[p].is_ascii_digit())) {
        p += 1;
    }
    if p == name_start {
        return None;
    }
    let name = &s[name_start..p];
    let mut attrs: Vec<NimaAttr<'_>> = Vec::new();
    loop {
        let q = nima_skip_space(s, p);
        match s.get(q)? {
            b'>' => return Some((name, attrs, false, q + 1)),
            b'/' => return (s.get(q + 1) == Some(&b'>')).then_some((name, attrs, true, q + 2)),
            _ => {}
        }
        if q == p {
            return None;
        }
        p = q;
        let attr_start = p;
        while p < s.len()
            && (s[p].is_ascii_lowercase()
                || (p > attr_start && (s[p].is_ascii_digit() || s[p] == b'-' || s[p] == b'_')))
        {
            p += 1;
        }
        if p == attr_start {
            return None;
        }
        let attr = &s[attr_start..p];
        if attrs.iter().any(|a| a.name == attr) {
            return None;
        }
        let mut value = Vec::new();
        if s.get(p) == Some(&b'=') {
            p += 1;
            match *s.get(p)? {
                quote @ (b'"' | b'\'') => {
                    p += 1;
                    loop {
                        match *s.get(p)? {
                            b if b == quote => break,
                            b'<' => return None,
                            b'&' => p = nima_reference(s, p, &mut value)?,
                            b => {
                                value.push(b);
                                p += 1;
                            }
                        }
                    }
                    p += 1;
                }
                _ => {
                    let start = p;
                    while p < s.len() && !nima_space(s[p]) && s[p] != b'>' {
                        if !(s[p].is_ascii_alphanumeric()
                            || matches!(s[p], b'.' | b'_' | b'-' | b':'))
                        {
                            return None;
                        }
                        p += 1;
                    }
                    if p == start {
                        return None;
                    }
                    value.extend_from_slice(&s[start..p]);
                }
            }
        }
        attrs.push(NimaAttr { name: attr, value });
    }
}

/// `</name>` at `s[i] == b'<'` for the expected lowercase `name`.
fn nima_end_tag(s: &[u8], i: usize, name: &[u8]) -> Option<usize> {
    let p = i + 2;
    (s.get(i + 1) == Some(&b'/')
        && s[p..].starts_with(name)
        && s.get(p + name.len()) == Some(&b'>'))
    .then_some(p + name.len() + 1)
}

/// A comment both parsers end at the first `-->`, at `s[i] == b'<'`.
fn nima_comment(s: &[u8], i: usize) -> Option<usize> {
    if !s[i..].starts_with(b"<!--") {
        return None;
    }
    let body = i + 4;
    let end = find(s, body, b"--")?;
    let content = &s[body..end];
    if s.get(end + 2) != Some(&b'>')
        || content.first().is_some_and(|&b| b == b'>' || b == b'-')
        || content.last() == Some(&b'-')
        || content.windows(2).any(|w| w == b"<!")
    {
        return None;
    }
    Some(end + 3)
}

fn nima_class_has(value: &[u8], token: &[u8]) -> bool {
    value.split(|&b| nima_space(b)).any(|t| t == token)
}

fn nima_attr<'a>(attrs: &'a [NimaAttr<'_>], name: &[u8]) -> Option<&'a [u8]> {
    attrs
        .iter()
        .find(|a| a.name == name)
        .map(|a| a.value.as_slice())
}

fn nima_classify(name: &[u8]) -> Option<(NimaTag, bool)> {
    // (kind, phrasing content model)
    Some(match name {
        b"div" => (NimaTag::Div, false),
        b"section" | b"article" | b"nav" | b"header" | b"footer" | b"main" | b"aside"
        | b"figure" | b"figcaption" => (NimaTag::Flow, false),
        b"a" => (NimaTag::Anchor, false),
        b"p" => (NimaTag::Paragraph, true),
        b"h1" | b"h3" | b"h4" | b"h5" | b"h6" => (NimaTag::Heading, true),
        b"h2" => (NimaTag::H2, true),
        b"span" => (NimaTag::Span, true),
        b"strong" | b"em" | b"b" | b"i" | b"u" | b"small" | b"time" | b"abbr" | b"code"
        | b"sup" | b"sub" | b"label" => (NimaTag::Phrasing, true),
        b"ul" | b"ol" => (NimaTag::List, false),
        b"li" => (NimaTag::Li, false),
        _ => return None,
    })
}

/// Head: whitespace, simple comments, `<meta charset="utf-8">`, `<link>` and one plain `<title>`.
fn nima_head(s: &[u8], mut p: usize) -> Option<usize> {
    let mut titled = false;
    loop {
        p = nima_skip_space(s, p);
        if *s.get(p)? != b'<' {
            return None;
        }
        if let Some(end) = nima_end_tag(s, p, b"head") {
            return Some(end);
        }
        if s[p..].starts_with(b"<!--") {
            p = nima_comment(s, p)?;
            continue;
        }
        let (name, attrs, closed, end) = nima_start_tag(s, p)?;
        match name {
            b"meta" => {
                if nima_attr(&attrs, b"http-equiv").is_some() {
                    return None;
                }
                if let Some(charset) = nima_attr(&attrs, b"charset")
                    && !charset.eq_ignore_ascii_case(b"utf-8")
                {
                    return None;
                }
                p = end;
            }
            b"link" => p = end,
            b"title" if !titled && !closed && attrs.is_empty() => {
                titled = true;
                let close = find(s, end, b"</")?;
                if s[end..close].iter().any(|&b| b == b'<' || b == b'&') {
                    return None;
                }
                p = nima_end_tag(s, close, b"title")?;
            }
            _ => return None,
        }
    }
}

fn nima_parse(s: &[u8], page_size: usize, out: &mut Vec<u8>) -> Option<usize> {
    let mut p = nima_skip_space(s, 0);
    if s.len() >= p + 15 && s[p..p + 15].eq_ignore_ascii_case(b"<!DOCTYPE html>") {
        p = nima_skip_space(s, p + 15);
    }
    let (name, _, closed, end) = nima_start_tag(s, p)?;
    if name != b"html" || closed {
        return None;
    }
    p = nima_skip_space(s, end);
    let (name, _, closed, end) = nima_start_tag(s, p)?;
    if name != b"head" || closed {
        return None;
    }
    p = nima_skip_space(s, nima_head(s, end)?);
    let (name, _, closed, end) = nima_start_tag(s, p)?;
    if name != b"body" || closed {
        return None;
    }
    p = end;

    let mut stack: Vec<NimaFrame> = Vec::with_capacity(32);
    let mut card: Option<NimaCard> = None;
    let mut cards = 0usize;
    let mut text = Vec::new();
    loop {
        let b = *s.get(p)?;
        if b != b'<' {
            // Character data up to the next tag; lists hold only whitespace between items.
            if stack.last().is_some_and(|f| f.tag == NimaTag::List) {
                p = nima_skip_space(s, p);
                if s.get(p) != Some(&b'<') {
                    return None;
                }
                continue;
            }
            let capture = card.as_ref().is_some_and(|c| c.subtitle_depth.is_some());
            while p < s.len() && s[p] != b'<' {
                if s[p] == b'&' {
                    text.clear();
                    p = nima_reference(s, p, &mut text)?;
                    if capture && let Some(c) = card.as_mut() {
                        c.text.extend_from_slice(&text);
                    }
                } else {
                    let run = p;
                    while p < s.len() && s[p] != b'<' && s[p] != b'&' {
                        p += 1;
                    }
                    if capture && let Some(c) = card.as_mut() {
                        c.text.extend_from_slice(&s[run..p]);
                    }
                }
            }
            continue;
        }
        let next = *s.get(p + 1)?;
        if next == b'!' {
            if card.as_ref().is_some_and(|c| c.subtitle_depth.is_some()) {
                return None;
            }
            p = nima_comment(s, p)?;
            continue;
        }
        if next == b'/' {
            let Some(frame) = stack.last() else {
                // </body>, whitespace, </html>, whitespace, end of input.
                p = nima_end_tag(s, p, b"body")?;
                p = nima_skip_space(s, p);
                p = nima_skip_space(s, nima_end_tag(s, p, b"html")?);
                return (p == s.len() && cards > 0).then_some(cards);
            };
            p = nima_end_tag(s, p, &frame.name)?;
            let frame = stack.pop()?;
            let depth = stack.len();
            if let Some(c) = card.as_mut() {
                if c.subtitle_depth == Some(depth) {
                    c.subtitle_depth = None;
                }
                if frame.cover_box {
                    c.cover_boxes -= 1;
                }
                if c.depth == depth {
                    let c = card.take()?;
                    let title = php_trim(&c.text);
                    if c.subtitles != 1
                        || c.covers != 1
                        || title.is_empty()
                        || !title.iter().all(|&b| (0x21..=0x7e).contains(&b))
                        || c.alt != title
                    {
                        return None;
                    }
                    if cards < page_size {
                        out.extend_from_slice(title);
                        out.push(0);
                    }
                    cards += 1;
                }
            }
            continue;
        }
        let (name, attrs, self_closing, end) = nima_start_tag(s, p)?;
        p = end;
        let parent = stack.last();
        let parent_phrasing = parent.is_some_and(|f| f.phrasing);
        if let Some(c) = card.as_ref()
            && c.subtitle_depth.is_some()
        {
            return None;
        }
        let class = nima_attr(&attrs, b"class");
        let has_class = |token: &[u8]| class.is_some_and(|v| nima_class_has(v, token));
        if name == b"img" || name == b"br" || name == b"hr" {
            if parent.is_some_and(|f| f.tag == NimaTag::List) || (name == b"hr" && parent_phrasing)
            {
                return None;
            }
            if name == b"img"
                && let Some(c) = card.as_mut()
                && c.cover_boxes > 0
                && let Some(alt) = nima_attr(&attrs, b"alt")
            {
                c.covers += 1;
                if c.covers > 1 {
                    return None;
                }
                c.alt = alt.to_vec();
            }
            continue;
        }
        if self_closing {
            return None;
        }
        let (tag, phrasing_model) = nima_classify(name)?;
        let inside_list = parent.is_some_and(|f| f.tag == NimaTag::List);
        if inside_list != (tag == NimaTag::Li) {
            return None;
        }
        let phrasing_tag = matches!(tag, NimaTag::Anchor | NimaTag::Span | NimaTag::Phrasing);
        if parent_phrasing && !phrasing_tag {
            return None;
        }
        if tag == NimaTag::Anchor && stack.iter().any(|f| f.tag == NimaTag::Anchor) {
            return None;
        }
        let depth = stack.len();
        let mut cover_box = false;
        if tag == NimaTag::Div && has_class(b"article") {
            if card.is_some() {
                return None;
            }
            card = Some(NimaCard {
                depth,
                ..NimaCard::default()
            });
        } else if let Some(c) = card.as_mut() {
            if tag == NimaTag::Span && has_class(b"subtitle") {
                c.subtitles += 1;
                // Only ./div[article-right]/h2/span[subtitle] is accepted; any other one declines.
                if c.subtitles > 1
                    || depth != c.depth + 3
                    || stack[c.depth + 2].tag != NimaTag::H2
                    || !stack[c.depth + 1].article_right
                {
                    return None;
                }
                c.subtitle_depth = Some(depth);
            }
            if tag == NimaTag::Div && has_class(b"cover-box") {
                cover_box = true;
                c.cover_boxes += 1;
            }
        }
        stack.push(NimaFrame {
            name: name.to_vec(),
            tag,
            phrasing: phrasing_model || parent_phrasing,
            cover_box,
            article_right: tag == NimaTag::Div && has_class(b"article-right"),
        });
        if stack.len() > 128 {
            return None;
        }
    }
}

/// Validates a NIMA4K listing and writes up to `page_size` release names, each NUL-terminated.
///
/// Returns the written length, `-1` when PHP must parse the document (any input outside the
/// reproduced subset, including every invalid one), or `-(needed + 16)` when `out_cap` is too small.
///
/// # Safety
/// `html` must be valid for `html_len` bytes and `out_buf` for `out_cap` writable bytes (or null).
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_predb_nima4k_parse_html(
    html: *const c_char,
    html_len: usize,
    page_size: usize,
    max_body_bytes: usize,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    // libxml's default text-node limit is 10 MB; larger bodies stay with the PHP diagnostics.
    if html.is_null()
        || html_len == 0
        || html_len > max_body_bytes
        || html_len > NIMA_MAX_BYTES
        || page_size == 0
    {
        return -1;
    }
    let s = unsafe { slice::from_raw_parts(html.cast::<u8>(), html_len) };
    if !nima_prescan(s) {
        return -1;
    }
    let mut titles = Vec::with_capacity(4096);
    if nima_parse(s, page_size, &mut titles).is_none() {
        return -1;
    }
    if titles.len() > out_cap || out_buf.is_null() {
        return -((titles.len() + 16) as isize);
    }
    unsafe { std::ptr::copy_nonoverlapping(titles.as_ptr(), out_buf.cast::<u8>(), titles.len()) };
    titles.len() as isize
}

#[cfg(test)]
mod nima4k_tests {
    use super::*;

    fn parse(html: &str, page: usize) -> Option<Vec<String>> {
        let mut buf = vec![0 as c_char; html.len() + 16];
        let n = unsafe {
            nntmux_predb_nima4k_parse_html(
                html.as_ptr().cast(),
                html.len(),
                page,
                1 << 20,
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        if n == -1 {
            return None;
        }
        assert!(n > 0);
        let bytes: Vec<u8> = buf[..n as usize].iter().map(|&b| b as u8).collect();
        let mut parts: Vec<String> = bytes
            .split(|&b| b == 0)
            .map(|p| String::from_utf8(p.to_vec()).unwrap())
            .collect();
        assert_eq!(parts.pop().as_deref(), Some(""));
        Some(parts)
    }

    fn card(title: &str) -> String {
        format!(
            "<div class=\"article uk-clearfix\"><div class=\"article-left\"><div class=\"cover-box\">\n<a href=\"/r/1\"><img src=\"/c.jpg\" alt=\"{title}\"></a></div></div><div class=\"article-right\"><h2 class=\"x\"><a href=\"/r/1\">Human & Title</a> <span class=\"subtitle uk-visible-large\">{title}</span></h2><p class=\"meta\">Am <span>11.10.2026</span></p></div></div>\n"
        )
    }

    fn page(body: &str) -> String {
        format!(
            "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>NIMA4K</title></head><body>\n<!-- listing -->\n{body}</body></html>\n"
        )
    }

    #[test]
    fn extracts_clean_cards_in_order_with_limit() {
        let html = page(&(card("A.Release-GRP") + &card("B.Rel&#46;ease-GRP")));
        assert_eq!(
            parse(&html, 10).unwrap(),
            vec!["A.Release-GRP", "B.Rel.ease-GRP"]
        );
        assert_eq!(parse(&html, 1).unwrap(), vec!["A.Release-GRP"]);
    }

    #[test]
    fn declines_ambiguous_or_invalid_documents() {
        let good = page(&card("A.Release-GRP"));
        assert!(parse(&good, 10).is_some());
        for (from, to) in [
            ("<title>NIMA4K</title>", "<title>a&amp;b</title>"),
            ("<title>NIMA4K</title>", "<title/>NIMA4K</title>"),
            ("<title>NIMA4K</title>", "<script>x</script>"),
            ("<!-- listing -->", "<!-- a -- b -->"),
            ("<!-- listing -->", "<?php ?>"),
            ("<!-- listing -->", "<svg></svg>"),
            ("<!-- listing -->", "<template></template>"),
            ("charset=\"UTF-8\"", "charset=\"latin1\""),
            (
                "charset=\"UTF-8\"",
                "http-equiv=\"content-type\" content=\"text/html; charset=utf-8\"",
            ),
            ("Human & Title", "Human &nbsp; Title"),
            ("Human & Title", "Human &apos; Title"),
            ("Human & Title", "Human &#x80; Title"),
            ("Human & Title", "Human &#13; Title"),
            ("Human & Title", "Human &amp Title"),
            ("Human & Title", "1 < 2"),
            ("class=\"x\"", "class=\"x\" class=\"y\""),
            ("</h2>", "</span></h2>"),
            ("<p class=\"meta\">", "<p class=\"meta\"><div></div>"),
            ("alt=\"A.Release-GRP\"", "alt=\"B.Release-GRP\""),
            ("alt=\"A.Release-GRP\"", "title=\"x\""),
            (">A.Release-GRP</span>", "><b>A.Release-GRP</b></span>"),
            (">A.Release-GRP</span>", ">A Release</span>"),
            ("</body></html>", "</body>"),
            (
                "<div class=\"article-left\">",
                "<div class=\"article-left\"><div/>",
            ),
            ("Am <span>", "Am <span class=\"subtitle\">"),
        ] {
            let html = good.replacen(from, to, 1);
            assert_ne!(html, good, "{from}");
            assert!(parse(&html, 10).is_none(), "{to}");
        }
        assert!(parse("<html><head></head><body></body></html>", 10).is_none());
        assert!(parse(&page(&card("Léon.1994-GRP")), 10).is_none());
        assert!(parse(&page(&card("A\u{FDD0}B")), 10).is_none());
    }

    #[test]
    fn small_buffers_report_the_needed_size() {
        let html = page(&card("A.Release-GRP"));
        let mut buf = [0 as c_char; 4];
        let n = unsafe {
            nntmux_predb_nima4k_parse_html(
                html.as_ptr().cast(),
                html.len(),
                10,
                1 << 20,
                buf.as_mut_ptr(),
                4,
            )
        };
        assert_eq!(n, -(("A.Release-GRP".len() + 1 + 16) as isize));
        let n = unsafe {
            nntmux_predb_nima4k_parse_html(
                html.as_ptr().cast(),
                html.len(),
                10,
                10,
                buf.as_mut_ptr(),
                4,
            )
        };
        assert_eq!(n, -1);
    }
}
